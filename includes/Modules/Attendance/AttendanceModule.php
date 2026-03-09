<?php
namespace SFS\HR\Modules\Attendance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AttendanceModule
 * Version: 0.1.2-admin-crud
 * Author: hdqah.com
 *
 * Notes:
 * - Employee mapping: {prefix}sfs_hr_employees.id and .user_id (to wp_users.ID)
 * - Leaves: {prefix}sfs_hr_leave_requests (status='approved', start_date, end_date)
 * - Holidays: option 'sfs_hr_holidays' (array of date ranges)
 */

// Load submodules at file scope (NOT inside the class!)
require_once __DIR__ . '/Services/Period_Service.php';
require_once __DIR__ . '/Services/Shift_Service.php';
require_once __DIR__ . '/Services/Early_Leave_Service.php';
require_once __DIR__ . '/Services/Session_Service.php';
require_once __DIR__ . '/Admin/class-admin-pages.php';
require_once __DIR__ . '/Rest/class-attendance-admin-rest.php';
require_once __DIR__ . '/Rest/class-attendance-rest.php';
require_once __DIR__ . '/Rest/class-early-leave-rest.php';
require_once __DIR__ . '/Cron/Daily_Session_Builder.php';

class AttendanceModule {

    const OPT_SETTINGS = 'sfs_hr_attendance_settings';

    /**
     * @deprecated Delegate to Period_Service. Kept for backwards compatibility.
     */
    public static function get_current_period( string $reference_date = '' ): array {
        return Services\Period_Service::get_current_period( $reference_date );
    }

    /** @deprecated Delegate to Period_Service. */
    public static function get_previous_period( string $reference_date = '' ): array {
        return Services\Period_Service::get_previous_period( $reference_date );
    }

    /** @deprecated Delegate to Period_Service. */
    public static function format_period_label( array $period ): string {
        return Services\Period_Service::format_period_label( $period );
    }

    public function hooks(): void {
        add_action('admin_init', [ $this, 'maybe_install' ]);

        // Deferred recalc hook — fires when a recalc was skipped due to lock contention.
        // Uses a wrapper because recalc_session_for's 3rd param is $wpdb, not $force.
        add_action( 'sfs_hr_deferred_recalc', [ self::class, 'run_deferred_recalc' ], 10, 3 );

        // Safe call to private method
        add_action('admin_init', function () { $this->register_caps(); });
        add_shortcode('sfs_hr_kiosk', [ $this, 'shortcode_kiosk' ]);

add_action('wp_ajax_sfs_hr_att_dbg', [ $this, 'ajax_dbg' ]);
add_action('wp_ajax_nopriv_sfs_hr_att_dbg', [ $this, 'ajax_dbg' ]);

        // Keep Admin pages on init
add_action('init', function () {
    ( new \SFS\HR\Modules\Attendance\Admin\Admin_Pages() )->hooks();
});

// Register REST routes (Admin + Public) in the right hook
add_action('rest_api_init', function () {
    // Admin REST
    if (method_exists(\SFS\HR\Modules\Attendance\Rest\Admin_REST::class, 'routes')) {
        \SFS\HR\Modules\Attendance\Rest\Admin_REST::routes();
    } elseif (method_exists(\SFS\HR\Modules\Attendance\Rest\Admin_REST::class, 'register')) {
        // fallback if Admin_REST only has register()
        \SFS\HR\Modules\Attendance\Rest\Admin_REST::register();
    }

    // Public REST — call register() so nocache headers are attached
    \SFS\HR\Modules\Attendance\Rest\Public_REST::register();

    // Early Leave Requests REST
    \SFS\HR\Modules\Attendance\Rest\Early_Leave_Rest::register_routes();
}, 10);



        add_shortcode('sfs_hr_attendance_widget', [ $this, 'shortcode_widget' ]);

        // Auto-reject early leave requests after 72 hours of no action
        ( new \SFS\HR\Modules\Attendance\Cron\Early_Leave_Auto_Reject() )->hooks();

        // Daily session builder — ensures sessions exist for yesterday/today
        ( new \SFS\HR\Modules\Attendance\Cron\Daily_Session_Builder() )->hooks();

        // Selfie cleanup — deletes attachments older than selfie_retention_days
        ( new \SFS\HR\Modules\Attendance\Cron\Selfie_Cleanup() )->hooks();
    }

    /**
     * Minimal employee widget (shortcode) with nonce + REST calls.
     * Place on a page restricted to logged-in employees.
     */
    public function shortcode_widget(): string {
    if ( ! is_user_logged_in() ) { return '<div>' . esc_html__( 'Please sign in.', 'sfs-hr' ) . '</div>'; }
    if ( ! current_user_can( 'sfs_hr_attendance_clock_self' ) ) {
        return '<div>' . esc_html__( 'You do not have permission to clock in/out.', 'sfs-hr' ) . '</div>';
    }


  // NEW: immersive flag (like kiosk)
    $atts = shortcode_atts([
        'immersive' => '1', // default ON
    ], [], 'sfs_hr_attendance_widget');

    $immersive = $atts['immersive'] === '1' || $atts['immersive'] === 1 || $atts['immersive'] === true;


    // REST nonce + endpoints
    $nonce      = wp_create_nonce( 'wp_rest' );
    $status_url = esc_url_raw( rest_url( 'sfs-hr/v1/attendance/status' ) );
    $punch_url  = esc_url_raw( rest_url( 'sfs-hr/v1/attendance/punch' ) );

    // Simple name for greeting
    $user      = wp_get_current_user();
    $user_name = $user ? ( $user->display_name ?: $user->user_login ) : '';

 // NEW: instance id for date/time
    $inst    = 'w'.substr( wp_hash((string)get_current_user_id().':'.microtime(true)), 0, 6 );
    $root_id = 'sfs-att-app-'.$inst;

    // --- Geo for self attendance: always collect location, respect policy for enforcement ---
    // IMPORTANT: Must be initialized BEFORE ob_start() so Leaflet assets can be conditionally loaded.
    $geo_lat     = '';
    $geo_lng     = '';
    $geo_radius  = '';
    $geo_enforce_in  = '1'; // default: enforce geofence
    $geo_enforce_out = '1';

    $employee_id = self::employee_id_from_user( get_current_user_id() );
    if ( $employee_id ) {
        // Local date (site timezone), not UTC
        $today_ymd = wp_date( 'Y-m-d' );

        // Always load shift coordinates first (for logging even when not enforcing)
        $shift = self::resolve_shift_for_date( $employee_id, $today_ymd );

        // Check if the policy allows geofence bypass per direction (shift-level → role-based fallback)
        $geo_enforce_in  = \SFS\HR\Modules\Attendance\Services\Policy_Service::should_enforce_geofence( $employee_id, 'in',  $shift ) ? '1' : '0';
        $geo_enforce_out = \SFS\HR\Modules\Attendance\Services\Policy_Service::should_enforce_geofence( $employee_id, 'out', $shift ) ? '1' : '0';

        if ( $shift ) {
            if ( isset( $shift->location_lat ) && $shift->location_lat !== null && $shift->location_lat !== '' ) {
                $geo_lat = trim( (string) $shift->location_lat );
            }
            if ( isset( $shift->location_lng ) && $shift->location_lng !== null && $shift->location_lng !== '' ) {
                $geo_lng = trim( (string) $shift->location_lng );
            }
            if ( isset( $shift->location_radius_m ) && $shift->location_radius_m !== null && $shift->location_radius_m !== '' ) {
                $geo_radius = trim( (string) $shift->location_radius_m ); // meters
            }
        }
    }

    ob_start(); ?>
    <!-- Always load Leaflet so the map can show the user's current location -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
    <?php if ( $immersive ): ?>
      <script>
        document.documentElement.classList.add('sfs-att-immersive');
        document.body.classList.add('sfs-att-immersive');
      </script>
      <div class="sfs-att-veil" role="application" aria-label="<?php esc_attr_e( 'Self Attendance', 'sfs-hr' ); ?>">
    <?php endif; ?>

<div
  id="<?php echo esc_attr( $root_id ); ?>"
  class="sfs-att-app"
  data-geo-lat="<?php echo esc_attr( $geo_lat ); ?>"
  data-geo-lng="<?php echo esc_attr( $geo_lng ); ?>"
  data-geo-radius="<?php echo esc_attr( $geo_radius ); ?>"
  data-geo-required="<?php echo ( $geo_lat && $geo_lng && $geo_radius ) ? '1' : '0'; ?>"
  data-geo-enforce-in="<?php echo esc_attr( $geo_enforce_in ); ?>"
  data-geo-enforce-out="<?php echo esc_attr( $geo_enforce_out ); ?>"
>

      <!-- ===== Hero map ===== -->
      <div class="sfs-att-map-hero">
        <div class="sfs-att-map-brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
        <?php
        // Back arrow button (overlaid on map)
        $profile_url = '';
        $profile_pages = get_posts( array(
            'post_type'      => 'page',
            'posts_per_page' => 1,
            's'              => '[sfs_hr_my_profile',
            'post_status'    => 'publish',
        ) );
        if ( ! empty( $profile_pages ) ) {
            $profile_url = get_permalink( $profile_pages[0]->ID );
        }
        if ( empty( $profile_url ) ) {
            $page = get_page_by_path( 'my-profile' );
            if ( ! $page ) { $page = get_page_by_path( 'hr-profile' ); }
            if ( $page ) { $profile_url = get_permalink( $page->ID ); }
        }
        if ( ! empty( $profile_url ) ) : ?>
        <a href="<?php echo esc_url( $profile_url ); ?>" class="sfs-att-back-arrow" aria-label="<?php esc_attr_e( 'Back', 'sfs-hr' ); ?>">
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M12.5 15L7.5 10l5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
        <?php endif; ?>
        <div id="sfs-att-map-<?php echo esc_attr( $inst ); ?>" class="sfs-att-map-canvas"></div>
        <button type="button" id="sfs-att-locate-<?php echo esc_attr( $inst ); ?>" class="sfs-att-locate-btn" aria-label="<?php esc_attr_e( 'Locate me', 'sfs-hr' ); ?>" title="<?php esc_attr_e( 'Locate me', 'sfs-hr' ); ?>">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
        </button>
      </div>

      <!-- ===== Bottom panel ===== -->
      <div class="sfs-att-panel">

        <!-- Time + status row -->
        <div class="sfs-att-time-row">
          <div>
            <div id="sfs-att-clock-<?php echo esc_attr( $inst ); ?>" class="sfs-att-clock">--:--</div>
            <div id="sfs-att-date-<?php echo esc_attr( $inst ); ?>" class="sfs-att-date">—</div>
          </div>
          <span id="sfs-att-state-chip"
                class="sfs-att-chip sfs-att-chip--idle" data-i18n-key="checking"><?php esc_html_e( 'Checking...', 'sfs-hr' ); ?></span>
        </div>

        <!-- Circular progress timer -->
        <div class="sfs-att-progress-wrap" id="sfs-att-progress-<?php echo esc_attr( $inst ); ?>">
          <svg class="sfs-att-progress-ring" viewBox="0 0 120 120">
            <circle class="sfs-att-progress-bg" cx="60" cy="60" r="52" />
            <circle class="sfs-att-progress-bar" id="sfs-att-progress-bar-<?php echo esc_attr( $inst ); ?>" cx="60" cy="60" r="52" />
          </svg>
          <div class="sfs-att-progress-inner">
            <div class="sfs-att-progress-hours" id="sfs-att-worked-<?php echo esc_attr( $inst ); ?>">0:00</div>
            <div class="sfs-att-progress-label" data-i18n-key="hours_worked"><?php esc_html_e( 'hours worked', 'sfs-hr' ); ?></div>
            <div class="sfs-att-progress-target" id="sfs-att-target-<?php echo esc_attr( $inst ); ?>"></div>
          </div>
        </div>

        <!-- Status line -->
        <div id="sfs-att-status" class="sfs-att-statusline" data-i18n-key="loading"><?php esc_html_e( 'Loading...', 'sfs-hr' ); ?></div>

        <!-- Action buttons -->
        <div class="sfs-att-actions" id="sfs-att-actions">
          <button type="button" data-type="in"
                  class="sfs-att-btn sfs-att-btn--in" style="display:none" data-i18n-key="clock_in"><?php esc_html_e( 'Clock In', 'sfs-hr' ); ?></button>
          <button type="button" data-type="out"
                  class="sfs-att-btn sfs-att-btn--out" style="display:none" data-i18n-key="clock_out"><?php esc_html_e( 'Clock Out', 'sfs-hr' ); ?></button>
          <button type="button" data-type="break_start"
                  class="sfs-att-btn sfs-att-btn--break" style="display:none" data-i18n-key="start_break"><?php esc_html_e( 'Start Break', 'sfs-hr' ); ?></button>
          <button type="button" data-type="break_end"
                  class="sfs-att-btn sfs-att-btn--breakend" style="display:none" data-i18n-key="end_break"><?php esc_html_e( 'End Break', 'sfs-hr' ); ?></button>
        </div>

        <!-- Today's Punch History -->
        <div class="sfs-att-punch-history" id="sfs-att-punches-<?php echo esc_attr( $inst ); ?>">
          <h4 class="sfs-att-punch-history-title" data-i18n-key="todays_activity"><?php esc_html_e( "Today's Activity", 'sfs-hr' ); ?></h4>
          <div class="sfs-att-punch-list" id="sfs-att-punch-list-<?php echo esc_attr( $inst ); ?>">
            <!-- Populated by JS -->
          </div>
        </div>

      </div><!-- .sfs-att-panel -->

      <!-- Success flash overlay -->
      <div class="sfs-flash" id="sfs-att-flash-<?php echo $inst; ?>"></div>

      <!-- Selfie overlay (fullscreen on mobile for better UX) -->
      <div id="sfs-att-selfie-panel" class="sfs-att-selfie-overlay">
        <div class="sfs-att-selfie-overlay__inner">
          <div class="sfs-att-selfie-overlay__status" id="sfs-att-selfie-status"></div>
          <div class="sfs-att-selfie-overlay__viewport">
            <video id="sfs-att-selfie-video" autoplay playsinline muted disablepictureinpicture></video>
          </div>
          <canvas id="sfs-att-selfie-canvas" width="480" height="480" hidden></canvas>
          <small class="sfs-att-selfie-overlay__hint" data-i18n-key="selfie_instruction">
            <?php esc_html_e( 'Center your face, then tap "Capture & Submit".', 'sfs-hr' ); ?>
          </small>
          <div class="sfs-att-selfie-overlay__actions">
            <button type="button" id="sfs-att-selfie-capture" class="sfs-att-selfie-btn sfs-att-selfie-btn--primary" data-i18n-key="capture_submit">
              <?php esc_html_e( 'Capture & Submit', 'sfs-hr' ); ?>
            </button>
            <button type="button" id="sfs-att-selfie-cancel" class="sfs-att-selfie-btn sfs-att-selfie-btn--cancel" data-i18n-key="cancel">
              <?php esc_html_e( 'Cancel', 'sfs-hr' ); ?>
            </button>
          </div>
        </div>
      </div>

      <!-- Fallback file input (if camera API not available) -->
      <div id="sfs-att-selfie-wrap" style="display:none;padding:0 20px;">
        <input type="file" id="sfs-att-selfie"
               accept="image/*" capture="user"
               style="display:block"/>
        <small style="display:block;color:#646970;margin-top:4px;font-size:11px;" data-i18n-key="camera_fallback_hint">
          <?php esc_html_e( 'Your device does not support live camera preview. Capture a selfie, then the system will submit it.', 'sfs-hr' ); ?>
        </small>
      </div>

      <!-- Hidden hint element (used by JS) -->
      <small id="sfs-att-hint" style="display:none"></small>

    </div><!-- .sfs-att-app -->

    <?php if ( $immersive ): ?>
      </div><!-- .sfs-att-veil -->
    <?php endif; ?>

    <style>
      /* ===== Root layout — map-first design ===== */
      #<?php echo esc_attr( $root_id ); ?>.sfs-att-app{
        --sfs-teal:#0f4c5c;
        --sfs-surface:#ffffff;
        --sfs-radius:16px;
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
        min-height:100dvh;
        width:100%;
        margin:0;
        display:flex;
        flex-direction:column;
        background:#f0f2f5;
      }

      /* ===== Hero map area ===== */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-map-hero{
        position:relative;
        flex:1 1 55dvh;
        min-height:280px;
        background:#e5e7eb;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-map-canvas{
        position:absolute;
        inset:0;
        width:100%;
        height:100%;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-map-brand{
        position:absolute;
        top:16px;
        left:16px;
        z-index:500;
        font-weight:700;
        font-size:14px;
        letter-spacing:.04em;
        text-transform:uppercase;
        color:#fff;
        background:var(--sfs-teal);
        padding:8px 14px;
        border-radius:10px;
        box-shadow:0 2px 8px rgba(0,0,0,0.25);
      }
      [dir="rtl"] #<?php echo esc_attr( $root_id ); ?> .sfs-att-map-brand{
        left:auto;
        right:16px;
      }
      /* Back arrow button on map */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-back-arrow{
        position:absolute;
        top:16px;
        right:16px;
        z-index:500;
        display:flex;
        align-items:center;
        justify-content:center;
        width:40px;
        height:40px;
        border-radius:12px;
        background:rgba(255,255,255,0.92);
        color:#111827;
        text-decoration:none;
        box-shadow:0 2px 8px rgba(0,0,0,0.15);
        backdrop-filter:blur(4px);
        -webkit-backdrop-filter:blur(4px);
        transition:background .15s;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-back-arrow:hover{
        background:#fff;
      }
      [dir="rtl"] #<?php echo esc_attr( $root_id ); ?> .sfs-att-back-arrow{
        right:auto;
        left:16px;
      }
      [dir="rtl"] #<?php echo esc_attr( $root_id ); ?> .sfs-att-back-arrow svg{
        transform:scaleX(-1);
      }

      /* Locate-me button on map */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-locate-btn{
        position:absolute;
        bottom:calc(var(--sfs-radius) + 16px);
        right:16px;
        z-index:500;
        display:flex;
        align-items:center;
        justify-content:center;
        width:40px;
        height:40px;
        border-radius:12px;
        background:rgba(255,255,255,0.92);
        color:#111827;
        border:none;
        cursor:pointer;
        box-shadow:0 2px 8px rgba(0,0,0,0.15);
        backdrop-filter:blur(4px);
        -webkit-backdrop-filter:blur(4px);
        transition:background .15s;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-locate-btn:hover{
        background:#fff;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-locate-btn:active{
        transform:scale(0.95);
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-locate-btn.locating{
        color:#2563eb;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-locate-btn svg{
        display:block;
        width:20px;
        height:20px;
        stroke:currentColor;
        fill:none;
        pointer-events:none;
        flex-shrink:0;
      }
      [dir="rtl"] #<?php echo esc_attr( $root_id ); ?> .sfs-att-locate-btn{
        right:auto;
        left:16px;
      }

      /* ===== Bottom panel ===== */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-panel{
        position:relative;
        z-index:400;
        background:var(--sfs-surface);
        border-radius:var(--sfs-radius) var(--sfs-radius) 0 0;
        margin-top:calc(-1 * var(--sfs-radius));
        padding:20px 20px 24px;
        box-shadow:0 -4px 20px rgba(0,0,0,0.08);
        display:flex;
        flex-direction:column;
        gap:14px;
      }

      /* Time + chip row */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-time-row{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-clock{
        font-weight:800;
        font-size:32px;
        line-height:1;
        letter-spacing:-.02em;
        color:#111827;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-date{
        font-size:13px;
        color:#6b7280;
        margin-top:2px;
      }

      /* Status chip */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-chip{
        display:inline-block;
        padding:6px 14px;
        border-radius:999px;
        font-size:13px;
        font-weight:600;
        line-height:1.2;
        white-space:nowrap;
        border:1px solid #e5e7eb;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-chip--idle{
        background:#f3f4f6; color:#374151;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-chip--in{
        background:#dcfce7; color:#166534; border-color:#bbf7d0;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-chip--out{
        background:#fee2e2; color:#b91c1c; border-color:#fecaca;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-chip--break{
        background:#fef3c7; color:#92400e; border-color:#fde68a;
      }

      /* Status line */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline{
        font-size:13px;
        margin:0;
        transition:all .2s;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="idle"],
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline:not([data-mode]){
        color:#6b7280; background:transparent; border:none; padding:0;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="busy"]{
        color:#1d4ed8; background:#eff6ff; border:1px solid #bfdbfe;
        border-radius:10px; padding:10px 14px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="error"]{
        color:#b91c1c; background:#fee2e2; border:1px solid #fecaca;
        border-radius:10px; padding:10px 14px; font-weight:500;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="in"]{
        color:#166534; background:#dcfce7; border:1px solid #bbf7d0;
        border-radius:10px; padding:10px 14px; font-weight:600;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="out"]{
        color:#b91c1c; background:#fee2e2; border:1px solid #fecaca;
        border-radius:10px; padding:10px 14px; font-weight:600;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="break_start"]{
        color:#92400e; background:#fef3c7; border:1px solid #fde68a;
        border-radius:10px; padding:10px 14px; font-weight:600;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="break_end"]{
        color:#1e40af; background:#dbeafe; border:1px solid #bfdbfe;
        border-radius:10px; padding:10px 14px; font-weight:600;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="warning"]{
        color:#92400e; background:#fef3c7; border:1px solid #fde68a;
        border-radius:10px; padding:10px 14px; font-weight:500;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="off_day"]{
        color:#4338ca; background:#eef2ff; border:1px solid #c7d2fe;
        border-radius:10px; padding:12px 18px; font-weight:600; font-size:15px;
        text-align:center;
      }

      /* ===== Action buttons ===== */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-actions{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-btn{
        flex:1 1 calc(50% - 5px);
        min-width:120px;
        padding:14px 12px;
        font-size:15px;
        font-weight:600;
        border:none;
        border-radius:12px;
        cursor:pointer;
        text-align:center;
        transition:transform .1s, box-shadow .15s;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-btn:active:not(:disabled){
        transform:scale(0.97);
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-btn:disabled{
        opacity:0.45;
        cursor:not-allowed;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-btn--in{
        background:#22c55e; color:#fff;
        box-shadow:0 2px 8px rgba(34,197,94,0.3);
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-btn--out{
        background:#ef4444; color:#fff;
        box-shadow:0 2px 8px rgba(239,68,68,0.3);
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-btn--break{
        background:#f59e0b; color:#fff;
        box-shadow:0 2px 8px rgba(245,158,11,0.3);
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-btn--breakend{
        background:#3b82f6; color:#fff;
        box-shadow:0 2px 8px rgba(59,130,246,0.3);
      }


      /* ===== Circular Progress Timer ===== */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-progress-wrap{
        display:flex; align-items:center; justify-content:center;
        position:relative; width:140px; height:140px; margin:8px auto 12px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-progress-ring{
        width:140px; height:140px; transform:rotate(-90deg);
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-progress-bg{
        fill:none; stroke:#e5e7eb; stroke-width:8;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-progress-bar{
        fill:none; stroke:#22c55e; stroke-width:8;
        stroke-linecap:round;
        stroke-dasharray:326.7; /* 2*PI*52 */
        stroke-dashoffset:326.7;
        transition:stroke-dashoffset 0.8s ease, stroke 0.3s;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-progress-inner{
        position:absolute; text-align:center;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-progress-hours{
        font-size:24px; font-weight:800; color:var(--sfs-teal,#0f4c5c);
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-progress-label{
        font-size:11px; color:#6b7280; margin-top:2px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-progress-target{
        font-size:11px; color:#9ca3af; margin-top:2px;
      }

      /* ===== Punch History ===== */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-history{
        margin-top:16px; padding-top:16px;
        border-top:1px solid #e5e7eb;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-history-title{
        font-size:14px; font-weight:700; color:#374151;
        margin:0 0 10px; padding:0;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-list{
        display:flex; flex-direction:column; gap:6px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-item{
        display:flex; align-items:center; gap:10px;
        padding:8px 12px; border-radius:10px; background:#f9fafb;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-badge{
        display:inline-flex; align-items:center; justify-content:center;
        width:32px; height:32px; border-radius:50%; flex-shrink:0;
        font-size:12px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-badge--in{
        background:#dcfce7; color:#16a34a;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-badge--out{
        background:#fee2e2; color:#dc2626;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-badge--break_start{
        background:#fef3c7; color:#d97706;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-badge--break_end{
        background:#dbeafe; color:#2563eb;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-label{
        flex:1; font-size:14px; font-weight:600; color:#374151;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-punch-time{
        font-size:13px; color:#6b7280; font-weight:500;
      }

      /* ===== Selfie overlay ===== */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay{
        display:none;
        position:fixed;
        inset:0;
        z-index:999999;
        background:rgba(0,0,0,0.92);
        padding:0;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__inner{
        display:flex; flex-direction:column; align-items:center; justify-content:center;
        height:100%; width:100%; max-width:480px; margin:0 auto; padding:16px; box-sizing:border-box;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__status{
        color:#fff; font-size:15px; font-weight:600; text-align:center; min-height:24px; margin-bottom:12px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__viewport{
        position:relative; width:100%; max-width:340px; aspect-ratio:1/1;
        border-radius:16px; overflow:hidden; border:3px solid rgba(255,255,255,0.25); background:#111;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__viewport video{
        width:100%; height:100%; object-fit:cover; pointer-events:none;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__viewport video::-webkit-media-controls{ display:none !important; }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__viewport video::-webkit-media-controls-start-playback-button{ display:none !important; }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__viewport video::-webkit-media-controls-play-button{ display:none !important; }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__hint{
        display:block; color:rgba(255,255,255,0.6); font-size:12px; text-align:center; margin-top:10px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__actions{
        display:flex; gap:12px; margin-top:16px; width:100%; max-width:340px;
      }
      .sfs-att-selfie-btn{
        flex:1; padding:14px 12px; font-size:15px; font-weight:600;
        border:none; border-radius:12px; cursor:pointer; transition:opacity 0.15s;
      }
      .sfs-att-selfie-btn:disabled{ opacity:0.5; cursor:not-allowed; }
      .sfs-att-selfie-btn--primary{ background:#22c55e; color:#fff; }
      .sfs-att-selfie-btn--primary:active{ background:#16a34a; }
      .sfs-att-selfie-btn--cancel{ background:rgba(255,255,255,0.15); color:#fff; }
      .sfs-att-selfie-btn--cancel:active{ background:rgba(255,255,255,0.25); }

      /* ===== Immersive mode ===== */
      .sfs-att-veil{
        position:fixed; inset:0; background:#f0f2f5;
        z-index:2147483000; overflow:auto;
      }
      html.sfs-att-immersive,
      body.sfs-att-immersive{ overflow:hidden; }
      body.sfs-att-immersive .site-header,
      body.sfs-att-immersive header,
      body.sfs-att-immersive .site-footer,
      body.sfs-att-immersive footer,
      body.sfs-att-immersive .entry-header,
      body.sfs-att-immersive .entry-footer,
      body.sfs-att-immersive .sidebar,
      body.sfs-att-immersive #secondary,
      body.sfs-att-immersive .page-title,
      body.sfs-att-immersive .elementor-location-header,
      body.sfs-att-immersive .elementor-location-footer,
      body.sfs-att-immersive #wpadminbar{
        display:none !important;
      }

      /* ===== Desktop: side-by-side ===== */
      @media (min-width:961px){
        #<?php echo esc_attr( $root_id ); ?>.sfs-att-app{
          flex-direction:row;
        }
        #<?php echo esc_attr( $root_id ); ?> .sfs-att-map-hero{
          flex:1 1 60%;
          min-height:100dvh;
        }
        #<?php echo esc_attr( $root_id ); ?> .sfs-att-panel{
          flex:0 0 380px;
          max-width:420px;
          border-radius:0;
          margin-top:0;
          box-shadow:-4px 0 20px rgba(0,0,0,0.06);
          justify-content:center;
        }
      }

      /* ===== Flash overlay ===== */
      #<?php echo esc_attr( $root_id ); ?> .sfs-flash {
        position:fixed; top:0; left:0; right:0; bottom:0;
        pointer-events:none; opacity:0;
        transition:opacity 0.35s ease-out; z-index:9998;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-flash.show { opacity:1; }
      #<?php echo esc_attr( $root_id ); ?> .sfs-flash--in { background:rgba(34,197,94,0.35); }
      #<?php echo esc_attr( $root_id ); ?> .sfs-flash--out { background:rgba(239,68,68,0.35); }
      #<?php echo esc_attr( $root_id ); ?> .sfs-flash--break_start { background:rgba(245,158,11,0.35); }
      #<?php echo esc_attr( $root_id ); ?> .sfs-flash--break_end { background:rgba(59,130,246,0.35); }
    </style>

<!-- Global translations for attendance widget -->
<script>
window.sfsAttI18n = window.sfsAttI18n || {
    // Status labels
    not_clocked_in: '<?php echo esc_js( __( 'Not clocked in', 'sfs-hr' ) ); ?>',
    clocked_in: '<?php echo esc_js( __( 'Clocked in', 'sfs-hr' ) ); ?>',
    on_break: '<?php echo esc_js( __( 'On break', 'sfs-hr' ) ); ?>',
    clocked_out: '<?php echo esc_js( __( 'Clocked out', 'sfs-hr' ) ); ?>',
    ready: '<?php echo esc_js( __( 'Ready', 'sfs-hr' ) ); ?>',
    working: '<?php echo esc_js( __( 'Working…', 'sfs-hr' ) ); ?>',
    checking_status: '<?php echo esc_js( __( 'Checking status…', 'sfs-hr' ) ); ?>',
    validating: '<?php echo esc_js( __( 'Validating…', 'sfs-hr' ) ); ?>',
    success: '<?php echo esc_js( __( 'Success!', 'sfs-hr' ) ); ?>',
    cancelled: '<?php echo esc_js( __( 'Cancelled.', 'sfs-hr' ) ); ?>',
    please_wait_processing: '<?php echo esc_js( __( 'Please wait, processing...', 'sfs-hr' ) ); ?>',
    // Camera messages
    starting_camera: '<?php echo esc_js( __( 'Starting camera…', 'sfs-hr' ) ); ?>',
    camera_error: '<?php echo esc_js( __( 'Camera error:', 'sfs-hr' ) ); ?>',
    camera_not_available: '<?php echo esc_js( __( 'Camera not available on this device.', 'sfs-hr' ) ); ?>',
    camera_ui_not_available: '<?php echo esc_js( __( 'Camera UI not available.', 'sfs-hr' ) ); ?>',
    device_no_camera_preview: '<?php echo esc_js( __( "Your device doesn't support live camera preview. Capture a selfie, then it will be submitted.", 'sfs-hr' ) ); ?>',
    ready_capture_submit: '<?php echo esc_js( __( 'Ready — tap "Capture & Submit".', 'sfs-hr' ) ); ?>',
    camera_not_ready: '<?php echo esc_js( __( 'Camera not ready yet. Please wait a second.', 'sfs-hr' ) ); ?>',
    could_not_capture_selfie: '<?php echo esc_js( __( 'Could not capture selfie. Try again.', 'sfs-hr' ) ); ?>',
    // Location/geo messages
    location_check_failed: '<?php echo esc_js( __( 'Location check failed.', 'sfs-hr' ) ); ?>',
    location_required_not_supported: '<?php echo esc_js( __( 'Location is required but this browser does not support it.', 'sfs-hr' ) ); ?>',
    outside_allowed_area: '<?php echo esc_js( __( 'You are outside the allowed area. Please move closer to the workplace and try again.', 'sfs-hr' ) ); ?>',
    location_permission_denied: '<?php echo esc_js( __( 'Location permission was denied. Enable it to use attendance.', 'sfs-hr' ) ); ?>',
    location_unavailable: '<?php echo esc_js( __( 'Location is unavailable. Check GPS or network.', 'sfs-hr' ) ); ?>',
    location_timeout: '<?php echo esc_js( __( 'Timed out while getting location. Try again.', 'sfs-hr' ) ); ?>',
    location_error_generic: '<?php echo esc_js( __( 'Could not get your location. Try again.', 'sfs-hr' ) ); ?>',
    // Hints
    selfie_required_hint: '<?php echo esc_js( __( 'Selfie required for this shift. Location may also be required.', 'sfs-hr' ) ); ?>',
    location_hint: '<?php echo esc_js( __( 'Your location may be required. Allow the browser location prompt if asked.', 'sfs-hr' ) ); ?>',
    selfie_required_capture: '<?php echo esc_js( __( 'Selfie is required for this shift. Please capture a photo.', 'sfs-hr' ) ); ?>',
    // Punch type labels
    clock_in: '<?php echo esc_js( __( 'Clock In', 'sfs-hr' ) ); ?>',
    clock_out: '<?php echo esc_js( __( 'Clock Out', 'sfs-hr' ) ); ?>',
    start_break: '<?php echo esc_js( __( 'Start Break', 'sfs-hr' ) ); ?>',
    end_break: '<?php echo esc_js( __( 'End Break', 'sfs-hr' ) ); ?>',
    break_start: '<?php echo esc_js( __( 'Break Start', 'sfs-hr' ) ); ?>',
    break_end: '<?php echo esc_js( __( 'Break End', 'sfs-hr' ) ); ?>',
    // Cooldown
    please_wait: '<?php echo esc_js( __( 'Please wait', 'sfs-hr' ) ); ?>',
    seconds_short: '<?php echo esc_js( __( 's', 'sfs-hr' ) ); ?>',
    // Error
    error_prefix: '<?php echo esc_js( __( 'Error:', 'sfs-hr' ) ); ?>',
    request_timed_out: '<?php echo esc_js( __( 'Request timed out', 'sfs-hr' ) ); ?>',
    locate_me: '<?php echo esc_js( __( 'Locate me', 'sfs-hr' ) ); ?>',
    target_word: '<?php echo esc_js( __( 'target', 'sfs-hr' ) ); ?>',
    // Progress & History
    no_activity_yet: '<?php echo esc_js( __( 'No activity recorded yet.', 'sfs-hr' ) ); ?>',
    hours_worked: '<?php echo esc_js( __( 'hours worked', 'sfs-hr' ) ); ?>',
    todays_activity: '<?php echo esc_js( __( "Today\'s Activity", 'sfs-hr' ) ); ?>',
    // Off-day / stale session
    day_off: '<?php echo esc_js( __( 'Day Off', 'sfs-hr' ) ); ?>',
    stale_session_contact_hr: '<?php echo esc_js( __( 'Your previous shift was not closed. Please contact HR.', 'sfs-hr' ) ); ?>'
};

// Language switching support for attendance widget
(function() {
    var langUrl = '<?php echo esc_js( \SFS_HR_URL . 'languages/' ); ?>';
    var translations = {};
    var currentLang = localStorage.getItem('sfs_hr_lang') || '<?php echo esc_js( substr( get_locale(), 0, 2 ) ); ?>' || 'en';

    function loadTranslations(lang) {
        return new Promise(function(resolve) {
            if (translations[lang]) {
                resolve(translations[lang]);
                return;
            }
            fetch(langUrl + lang + '.json')
                .then(function(response) {
                    if (!response.ok) throw new Error('Not found');
                    return response.json();
                })
                .then(function(data) {
                    translations[lang] = data;
                    resolve(data);
                })
                .catch(function() {
                    resolve({});
                });
        });
    }

    function applyTranslations(lang) {
        loadTranslations(lang).then(function(strings) {
            // Update global i18n object
            var keys = ['not_clocked_in', 'clocked_in', 'on_break', 'clocked_out', 'ready', 'working',
                'checking_status', 'validating', 'success', 'cancelled', 'please_wait_processing',
                'starting_camera', 'camera_error', 'camera_not_available', 'camera_ui_not_available',
                'device_no_camera_preview', 'ready_capture_submit', 'camera_not_ready', 'could_not_capture_selfie',
                'location_check_failed', 'location_required_not_supported', 'outside_allowed_area',
                'location_permission_denied', 'location_unavailable', 'location_timeout', 'location_error_generic',
                'selfie_required_hint', 'location_hint', 'selfie_required_capture', 'error_prefix', 'request_timed_out',
                'clock_in', 'clock_out', 'start_break', 'end_break', 'break_start', 'break_end',
                'please_wait', 'seconds_short',
                'no_activity_yet', 'hours_worked', 'todays_activity', 'locate_me',
                'day_off', 'stale_session_contact_hr'];

            keys.forEach(function(key) {
                if (strings[key]) {
                    window.sfsAttI18n[key] = strings[key];
                }
            });

            // Update DOM elements with data-i18n-key
            var container = document.querySelector('.sfs-att-app');
            if (container) {
                container.querySelectorAll('[data-i18n-key]').forEach(function(el) {
                    var key = el.dataset.i18nKey;
                    if (key && strings[key]) {
                        el.textContent = strings[key];
                    }
                });

                // Apply RTL for Arabic and Urdu
                if (lang === 'ar' || lang === 'ur') {
                    container.setAttribute('dir', 'rtl');
                } else {
                    container.setAttribute('dir', 'ltr');
                }
            }

            // Notify other scripts to re-render dynamic elements
            window.dispatchEvent(new CustomEvent('sfs_hr_i18n_updated'));
        });
    }

    // Apply translations on load
    applyTranslations(currentLang);

    // Listen for language changes from localStorage
    window.addEventListener('storage', function(e) {
        if (e.key === 'sfs_hr_lang' && e.newValue) {
            applyTranslations(e.newValue);
        }
    });

    // Also listen for custom event from the main app
    window.addEventListener('sfs_hr_lang_change', function(e) {
        if (e.detail && e.detail.lang) {
            applyTranslations(e.detail.lang);
        }
    });
})();
</script>

<script>
(function(){
    if (window.sfsGeo) return; // avoid redefining if other shortcode printed it
    var i18n = window.sfsAttI18n || {};

    function haversineMeters(lat1, lon1, lat2, lon2) {
        const R = 6371000;
        const toRad = d => d * Math.PI / 180;
        const φ1 = toRad(lat1), φ2 = toRad(lat2);
        const dφ = toRad(lat2 - lat1);
        const dλ = toRad(lon2 - lon1);
        const a =
            Math.sin(dφ / 2) * Math.sin(dφ / 2) +
            Math.cos(φ1) * Math.cos(φ2) *
            Math.sin(dλ / 2) * Math.sin(dλ / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    function getGeoConfig(root, punchType) {
        if (!root) return { enabled:false, enforce:false, lat:null, lng:null, radius:null };

        const geoLat     = root.dataset.geoLat;
        const geoLng     = root.dataset.geoLng;
        const geoRadius  = root.dataset.geoRadius;

        // Direction-specific enforcement
        const enforce = (punchType === 'out')
            ? root.dataset.geoEnforceOut === '1'
            : root.dataset.geoEnforceIn  === '1'; // 'in', 'break_start', 'break_end' all use clock-in rule

        const lat    = parseFloat(geoLat);
        const lng    = parseFloat(geoLng);
        const radius = parseFloat(geoRadius);

        if (!lat || !lng || !radius) {
            return { enabled:false, enforce, lat:null, lng:null, radius:null };
        }
        return { enabled:true, enforce, lat, lng, radius };
    }

    /**
     * requireInside(root, onAllow, onReject, punchType)
     * - If no geofence configured → still tries to get GPS for logging, then calls onAllow.
     * - If geofence set + enforce=true → asks for location, checks radius:
     *   - inside  → onAllow({ latitude, longitude, distance })
     *   - outside / permission error → onReject(message, code)
     * - If geofence set + enforce=false → asks for location for logging, always allows.
     * @param {string} punchType - 'in', 'out', 'break_start', 'break_end'
     */
    function requireInside(root, onAllow, onReject, punchType) {
        const cfg = getGeoConfig(root, punchType);

        // Always try to collect GPS for logging, even if geofence is not configured/enforced
        if (!navigator.geolocation) {
            if (cfg.enabled && cfg.enforce) {
                onReject && onReject(
                    i18n.location_required_not_supported || 'Location is required but this browser does not support it.',
                    'NO_GEO'
                );
            } else {
                // No geolocation support but not enforcing → allow without coords
                onAllow && onAllow(null);
            }
            return;
        }

        // No geofence configured at all → still try to log GPS silently
        // Use lower accuracy + cached position for better reliability
        if (!cfg.enabled) {
            navigator.geolocation.getCurrentPosition(
                pos => {
                    onAllow && onAllow({
                        latitude: pos.coords.latitude,
                        longitude: pos.coords.longitude,
                        distance: null
                    });
                },
                () => {
                    // GPS failed but not enforcing → allow without coords
                    onAllow && onAllow(null);
                },
                { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 }
            );
            return;
        }

        navigator.geolocation.getCurrentPosition(
            pos => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                const dist = haversineMeters(cfg.lat, cfg.lng, lat, lng);

                // Only block when enforcement is on and user is outside radius
                if (cfg.enforce && dist > cfg.radius) {
                    onReject && onReject(
                        i18n.outside_allowed_area || 'You are outside the allowed area. Please move closer to the workplace and try again.',
                        'OUTSIDE_RADIUS'
                    );
                    return;
                }

                onAllow && onAllow({
                    latitude: lat,
                    longitude: lng,
                    distance: dist
                });
            },
            err => {
                // When enforcement is off, GPS failure should not block the punch
                if (!cfg.enforce) {
                    onAllow && onAllow(null);
                    return;
                }

                let msg;
                if (err.code === err.PERMISSION_DENIED) {
                    msg = i18n.location_permission_denied || 'Location permission was denied. Enable it to use attendance.';
                } else if (err.code === err.POSITION_UNAVAILABLE) {
                    msg = i18n.location_unavailable || 'Location is unavailable. Check GPS or network.';
                } else if (err.code === err.TIMEOUT) {
                    msg = i18n.location_timeout || 'Timed out while getting location. Try again.';
                } else {
                    msg = i18n.location_error_generic || 'Could not get your location. Try again.';
                }
                onReject && onReject(msg, 'GEO_ERROR_' + err.code);
            },
            { enableHighAccuracy:true, timeout:10000, maximumAge:0 }
        );
    }

    window.sfsGeo = { requireInside };
})();
</script>


    <script data-cfasync="false">
    (function(){
        const statusBox   = document.getElementById('sfs-att-status');
        const chipEl      = document.getElementById('sfs-att-state-chip');
        const actionsWrap = document.getElementById('sfs-att-actions');
        const hint        = document.getElementById('sfs-att-hint');

        // Selfie elements (live camera overlay)
        const selfiePanel   = document.getElementById('sfs-att-selfie-panel');
        const selfieVideo   = document.getElementById('sfs-att-selfie-video');
        const selfieCanvas  = document.getElementById('sfs-att-selfie-canvas');
        const selfieCapture = document.getElementById('sfs-att-selfie-capture');
        const selfieCancel  = document.getElementById('sfs-att-selfie-cancel');
        const selfieStatus  = document.getElementById('sfs-att-selfie-status');

        // Fallback file input
        const selfieWrap  = document.getElementById('sfs-att-selfie-wrap');
        const selfieInput = document.getElementById('sfs-att-selfie');

        let selfieStream   = null;
        let pendingType    = null;

        let allowed        = {};
        let state          = 'idle';
        let requiresSelfie = false;
        let selfieMode     = 'optional'; // 'never','optional','in_only','in_out','all'
        let methodBlocked  = {};         // { in: 'msg', out: 'msg', ... } from policy
        let cooldownType     = null;       // punch_type currently in cooldown
        let cooldownSec      = 0;          // seconds remaining (same-type)
        let cooldownCrossSec = 0;          // seconds remaining (cross-type)
        let refreshing     = false;
        let queued         = false;
        let punchInProgress = false; // Prevent duplicate submissions
        let lastRefreshAt  = 0;      // timestamp of last successful refresh
        let cachedGeo      = null;   // { lat, lng, acc, ts }

        // Flash + tone feedback
        const flashEl = document.getElementById('sfs-att-flash-<?php echo $inst; ?>');

        function flash(kind) {
            if (!flashEl) return;
            flashEl.className = 'sfs-flash sfs-flash--' + (kind || 'in');
            void flashEl.offsetWidth; // reflow to restart animation
            flashEl.classList.add('show');
            setTimeout(() => flashEl.classList.remove('show'), 400);
        }
        async function playActionTone(kind) {
            const freq = { in: 920, out: 420, break_start: 680, break_end: 560 }[kind] || 750;
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                if (ctx.state === 'suspended') await ctx.resume();
                const o = ctx.createOscillator(), g = ctx.createGain();
                o.type = 'sine'; o.frequency.value = freq;
                o.connect(g); g.connect(ctx.destination);
                g.gain.value = 0.25;
                o.start();
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.22);
                setTimeout(() => { o.stop(); ctx.close(); }, 260);
            } catch(_) {}
        }

        const STATUS_URL = '<?php echo esc_js( $status_url ); ?>';
        const PUNCH_URL  = '<?php echo esc_js( $punch_url ); ?>';
        const NONCE      = '<?php echo esc_js( $nonce ); ?>';

        // Use global translated strings
        const i18n = window.sfsAttI18n || {};

        function setStat(text, mode){
    if (!statusBox) return;

    statusBox.textContent = text || '';
    if (mode) {
        statusBox.dataset.mode = mode;
    } else {
        statusBox.removeAttribute('data-mode');
    }

    // On error, scroll status into view so user sees the message (important on mobile
    // where camera panel may have pushed the status off-screen)
    if (mode === 'error') {
        try { statusBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch(_) {}
    }
}


const clockEl = document.getElementById('sfs-att-clock-<?php echo $inst; ?>');
const dateEl  = document.getElementById('sfs-att-date-<?php echo $inst; ?>');

function tickClock(){
    if (!clockEl) return;
    try {
        const d = new Date();
        clockEl.textContent = d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    } catch(_) {}
}
function tickDate(){
    if (!dateEl) return;
    try {
        const d = new Date();
        const dateLang = localStorage.getItem('sfs_hr_lang') || 'en';
        const dateLocale = dateLang === 'ar' ? 'ar-SA' : (dateLang === 'ur' ? 'ur-PK' : (dateLang === 'fil' ? 'fil-PH' : 'en-US'));
        dateEl.textContent = d.toLocaleDateString(dateLocale, {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    } catch(_) {}
}

tickClock();
tickDate();
setInterval(tickClock, 1000);
// date changes once per day; no need for interval

        function updateChip() {
            if (!chipEl) return;
            let label = i18n.not_clocked_in;
            let cls   = 'sfs-att-chip sfs-att-chip--idle';

            if (state === 'in') {
                label = i18n.clocked_in;
                cls   = 'sfs-att-chip sfs-att-chip--in';
            } else if (state === 'break') {
                label = i18n.on_break;
                cls   = 'sfs-att-chip sfs-att-chip--break';
            } else if (state === 'out') {
                label = i18n.clocked_out;
                cls   = 'sfs-att-chip sfs-att-chip--out';
            }

            chipEl.textContent = label;
            chipEl.className   = cls;
        }

        // ===== Progress Timer =====
        var progressWorkingSec = 0;
        var progressTargetSec = 0;
        var progressTimerInterval = null;
        var progressBar = document.getElementById('sfs-att-progress-bar-<?php echo $inst; ?>');
        var workedEl    = document.getElementById('sfs-att-worked-<?php echo $inst; ?>');
        var targetEl    = document.getElementById('sfs-att-target-<?php echo $inst; ?>');
        var progressWrap = document.getElementById('sfs-att-progress-<?php echo $inst; ?>');
        var CIRCUMFERENCE = 2 * Math.PI * 52; // ~326.7

        function formatHM(seconds) {
            var h = Math.floor(seconds / 3600);
            var m = Math.floor((seconds % 3600) / 60);
            return h + ':' + (m < 10 ? '0' : '') + m;
        }

        function renderProgress() {
            if (!progressBar || !workedEl) return;
            workedEl.textContent = formatHM(progressWorkingSec);
            var pct = progressTargetSec > 0 ? Math.min(progressWorkingSec / progressTargetSec, 1.5) : 0;
            var offset = CIRCUMFERENCE - (Math.min(pct, 1) * CIRCUMFERENCE);
            progressBar.style.strokeDashoffset = offset;
            // Color based on progress.
            if (pct >= 1) {
                progressBar.style.stroke = '#22c55e'; // green — target met
            } else if (pct >= 0.75) {
                progressBar.style.stroke = '#3b82f6'; // blue — almost there
            } else if (pct >= 0.5) {
                progressBar.style.stroke = '#f59e0b'; // amber — half way
            } else {
                progressBar.style.stroke = '#ef4444'; // red — early
            }
            if (targetEl) {
                targetEl.textContent = progressTargetSec > 0
                    ? ('/ ' + formatHM(progressTargetSec) + ' ' + (i18n.target_word || 'target'))
                    : '';
            }
        }

        function updateProgressTimer(workedSec, targetSec) {
            progressWorkingSec = workedSec;
            progressTargetSec = targetSec;
            renderProgress();
            // Live tick while clocked in.
            if (progressTimerInterval) clearInterval(progressTimerInterval);
            if (state === 'in') {
                progressTimerInterval = setInterval(function() {
                    progressWorkingSec++;
                    renderProgress();
                }, 1000);
            }
        }

        // ===== Punch History =====
        var punchListEl = document.getElementById('sfs-att-punch-list-<?php echo $inst; ?>');
        var punchIcons = {
            'in':          '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
            'out':         '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 21 3 21 3 15"/><line x1="14" y1="10" x2="3" y2="21"/></svg>',
            'break_start': '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>',
            'break_end':   '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>'
        };

        function updatePunchHistory(punches) {
            if (!punchListEl) return;
            if (!punches || punches.length === 0) {
                punchListEl.innerHTML = '<div style="text-align:center;color:#9ca3af;font-size:13px;padding:12px 0;">'
                    + (i18n.no_activity_yet || 'No activity yet') + '</div>';
                return;
            }
            var html = '';
            for (var i = 0; i < punches.length; i++) {
                var p = punches[i];
                var label = punchTypeLabel(p.type);
                html += '<div class="sfs-att-punch-item">'
                    + '<div class="sfs-att-punch-badge sfs-att-punch-badge--' + p.type + '">'
                    + (punchIcons[p.type] || '')
                    + '</div>'
                    + '<span class="sfs-att-punch-label">' + label + '</span>'
                    + '<span class="sfs-att-punch-time">' + p.time + '</span>'
                    + '</div>';
            }
            punchListEl.innerHTML = html;
        }

        // Check if an action is truly available (state + policy)
        function isActionAvailable(t) {
            return !!allowed[t] && !methodBlocked[t];
        }

        // Sync all action buttons to current state + policy
        function syncButtons() {
            if (!actionsWrap) return;
            actionsWrap.querySelectorAll('button[data-type]').forEach(btn => {
                const t = btn.getAttribute('data-type');
                const avail = isActionAvailable(t);
                btn.style.display = allowed[t] ? '' : 'none';
                btn.disabled = !avail;
                // Visually dim policy-blocked buttons
                if (allowed[t] && methodBlocked[t]) {
                    btn.style.display = '';
                    btn.disabled = true;
                    btn.title = methodBlocked[t];
                } else {
                    btn.title = '';
                }
            });
        }

                async function getGeo(punchType, useCache = false){
            // Return cached geo if available and fresh (within 45s).
            // Only used by doPunch() which always runs right after a FRESH pre-flight
            // geofence check, so the cache here is just seconds old from that check.
            // 45s window covers the time a user takes to position face + capture selfie.
            if (useCache && cachedGeo && (Date.now() - cachedGeo.ts < 45000)) {
                return { lat: cachedGeo.lat, lng: cachedGeo.lng, acc: cachedGeo.acc };
            }

            const root = document.getElementById('<?php echo esc_js( $root_id ); ?>');

            // If helper missing for any reason → fallback to old behavior
            if (!window.sfsGeo || !root) {
                return new Promise(resolve=>{
                    if(!navigator.geolocation){ return resolve(null); }
                    navigator.geolocation.getCurrentPosition(
                        pos=>resolve({
                            lat: pos.coords.latitude,
                            lng: pos.coords.longitude,
                            acc: Math.round(pos.coords.accuracy || 0)
                        }),
                        ()=>resolve(null),
                        {enableHighAccuracy:true,timeout:8000,maximumAge:0}
                    );
                });
            }

            // Normal path: check geofence per punch direction. If rejected → we abort punch.
            const result = await new Promise((resolve, reject)=>{
                window.sfsGeo.requireInside(
                    root,
                    function onAllow(coords){
                        if (!coords) {
                            // geofence not configured or GPS failed while not enforcing
                            resolve(null);
                            return;
                        }
                        resolve({
                            lat: coords.latitude,
                            lng: coords.longitude,
                            acc: Math.round(coords.distance || 0)
                        });
                    },
                    function onReject(msg, code){
                        setStat(msg || i18n.location_check_failed, 'error');
                        // Hard block by rejecting; caller will bail
                        reject(new Error('geo_blocked'));
                    },
                    punchType
                );
            });

            if (result) {
                cachedGeo = { ...result, ts: Date.now() };
                return result;
            }

            // Fallback: requireInside returned null (GPS failed while not enforcing).
            // Try once more with lower-accuracy (WiFi/cell) and accept cached position.
            if (!navigator.geolocation) return null;
            return new Promise(resolve=>{
                navigator.geolocation.getCurrentPosition(
                    pos=>resolve({
                        lat: pos.coords.latitude,
                        lng: pos.coords.longitude,
                        acc: Math.round(pos.coords.accuracy || 0)
                    }),
                    ()=>resolve(null),
                    {enableHighAccuracy:false, timeout:5000, maximumAge:60000}
                );
            });
        }


        async function refresh(){
            if (refreshing) { queued = true; return; }
            refreshing = true;

            const ctrl = new AbortController();
            const t = setTimeout(()=>ctrl.abort(), 6000);

            try {
                const url = STATUS_URL
                  + (<?php echo wp_json_encode(strpos($status_url,'?')!==false); ?> ? '&' : '?')
                  + '_=' + Date.now();

                const r = await fetch(url, {
                    headers: {
                        'X-WP-Nonce': NONCE,
                        'Cache-Control': 'no-cache'
                    },
                    credentials:'same-origin',
                    cache:'no-store',
                    signal: ctrl.signal
                });

                const j = await r.json();
                if (!r.ok) throw new Error(j.message || 'Status error');

                state          = j.state || 'idle';
                allowed        = j.allow || {};
                selfieMode     = j.selfie_mode || 'optional';
                requiresSelfie = !!j.requires_selfie;
                methodBlocked  = j.method_blocked || {};
                cooldownType       = j.cooldown_type || null;
                cooldownSec        = j.cooldown_seconds || 0;
                cooldownCrossSec   = j.cooldown_cross_seconds || 0;
        if (!requiresSelfie) {
            // Make sure selfie UI is hidden if policy changed
            if (selfiePanel) { selfiePanel.style.display = 'none'; document.body.style.overflow = ''; }
            if (selfieWrap)  selfieWrap.style.display  = 'none';
        }

                lastRefreshAt = Date.now();
                updateChip();

                // Show/hide buttons based on allowed transitions + method policy
                syncButtons();

                // --- Off-day / stale session messaging ---
                if (j.is_off_day) {
                    setStat(i18n.day_off || 'Day Off', 'off_day');
                    hint && (hint.textContent = '');
                    if (progressWrap) progressWrap.style.display = 'none';
                } else if (j.stale_session_msg) {
                    // Stale session: the backend already returns the correct
                    // allow flags (in=true, out=false) so we just show the
                    // informational message without overriding button state.
                    setStat(j.stale_session_msg, 'warning');
                    hint && (hint.textContent = '');
                    if (progressWrap) progressWrap.style.display = '';
                } else {
                    // If ALL state-allowed actions are method-blocked, show why
                    var blockedMsg = null;
                    var allAllowedBlocked = true;
                    for (var pt in allowed) {
                        if (allowed[pt]) {
                            if (methodBlocked[pt]) {
                                blockedMsg = methodBlocked[pt];
                            } else {
                                allAllowedBlocked = false;
                                break;
                            }
                        }
                    }
                    if (allAllowedBlocked && blockedMsg) {
                        setStat(blockedMsg, 'error');
                    } else {
                        setStat(i18n.ready, 'idle');
                    }

                    // Selfie hint
                    if (requiresSelfie) {
                        hint && (hint.textContent = i18n.selfie_required_hint);
                    } else {
                        hint && (hint.textContent = i18n.location_hint);
                    }
                    if (progressWrap) progressWrap.style.display = '';
                }

                // Update progress timer.
                updateProgressTimer(j.working_seconds || 0, j.target_seconds || 0);

                // Update punch history.
                updatePunchHistory(j.punch_history || []);

            } catch(e) {
                setStat(i18n.error_prefix + ' ' + (e.name === 'AbortError' ? i18n.request_timed_out : e.message), 'error');
            } finally {
                clearTimeout(t);
                refreshing = false;
                if (queued) { queued = false; refresh(); }
            }
        }

        function stopSelfiePreview(){
            if (selfieStream) {
                try { selfieStream.getTracks().forEach(t=>t.stop()); } catch(_) {}
                selfieStream = null;
            }
            if (selfieVideo) selfieVideo.srcObject = null;
            if (selfiePanel) selfiePanel.style.display = 'none';
            if (selfieStatus) selfieStatus.textContent = '';
            document.body.style.overflow = '';
        }

        function punchTypeLabel(type){
            const map = {
                'in':          i18n.clock_in    || 'Clock In',
                'out':         i18n.clock_out   || 'Clock Out',
                'break_start': i18n.start_break || 'Start Break',
                'break_end':   i18n.end_break   || 'End Break'
            };
            return map[type] || type;
        }

        async function startSelfie(type){
            pendingType = type;

            // If no mediaDevices API → fall back to file input
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                if (selfieWrap && selfieInput) {
                    selfieWrap.style.display = 'block';
                    setStat(i18n.device_no_camera_preview, 'error');
                    selfieInput.click();
                } else {
                    setStat(i18n.camera_not_available, 'error');
                    punchInProgress = false;
                    syncButtons();
                }
                return;
            }

            if (!selfiePanel || !selfieVideo) {
                setStat(i18n.camera_ui_not_available, 'error');
                punchInProgress = false;
                syncButtons();
                return;
            }

            // Show overlay and lock body scroll
            selfiePanel.style.display = 'block';
            document.body.style.overflow = 'hidden';

            // Show action label in overlay status
            const label = punchTypeLabel(type);
            if (selfieStatus) selfieStatus.textContent = label + ' — ' + (i18n.starting_camera || 'Starting camera…');

            try {
                selfieStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'user' },
                        width:  { ideal: 1280 },
                        height: { ideal: 720 }
                    },
                    audio: false
                });
                selfieVideo.srcObject = selfieStream;
                await new Promise(r => {
                    selfieVideo.onloadedmetadata = () => {
                        try { selfieVideo.play().then(r).catch(()=>r()); } catch(_) { r(); }
                    };
                });
                if (selfieStatus) selfieStatus.textContent = label + ' — ' + (i18n.ready_capture || 'Ready');
            } catch(e) {
                setStat(i18n.camera_error + ' ' + (e.message || e), 'error');
                stopSelfiePreview();
                pendingType = null;
                punchInProgress = false;
                syncButtons();
            }
        }

        function needsSelfieForType(punchType) {
            if (selfieMode === 'in_only')  return punchType === 'in';
            if (selfieMode === 'in_out')   return punchType === 'in' || punchType === 'out';
            if (selfieMode === 'all')      return ['in','out','break_start','break_end'].includes(punchType);
            return false; // 'never', 'optional'
        }

        async function doPunch(type, selfieBlob){
            setStat(i18n.working, 'busy');
            // Mirror status into overlay if it's open
            if (selfieStatus && selfiePanel && selfiePanel.style.display !== 'none') {
                selfieStatus.textContent = punchTypeLabel(type) + ' — ' + (i18n.working || 'Working…');
            }

            let geo = null;
            try {
                geo = await getGeo(type, true); // use cached geo from pre-flight
            } catch(e){
                // geo_blocked → do not hit the REST API
                punchInProgress = false;
                if (actionsWrap) {
                    actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                        const t = btn.getAttribute('data-type');
                        btn.disabled = !isActionAvailable(t);
                    });
                }
                return;
            }

            try {
                let resp, text = '', j = null;

                const selfieNeeded = needsSelfieForType(type);
                if (selfieNeeded && selfieBlob) {
                    const fd = new FormData();
                    fd.append('punch_type', type);
                    fd.append('source', 'self_web');

                    if (geo && typeof geo.lat==='number' && typeof geo.lng==='number') {
                        fd.append('geo_lat', String(geo.lat));
                        fd.append('geo_lng', String(geo.lng));
                        if (typeof geo.acc==='number') fd.append('geo_accuracy_m', String(Math.round(geo.acc)));
                    }

                    const timestamp = Date.now();
                    fd.append('selfie', selfieBlob, `selfie-${timestamp}.jpg`);

                    resp = await fetch(PUNCH_URL, {
                        method: 'POST',
                        headers: { 'X-WP-Nonce': NONCE },
                        credentials: 'same-origin',
                        body: fd
                    });
                } else if (selfieNeeded) {
                    // Selfie needed but no blob — try file input, or error
                    const fd = new FormData();
                    fd.append('punch_type', type);
                    fd.append('source', 'self_web');

                    if (geo && typeof geo.lat==='number' && typeof geo.lng==='number') {
                        fd.append('geo_lat', String(geo.lat));
                        fd.append('geo_lng', String(geo.lng));
                        if (typeof geo.acc==='number') fd.append('geo_accuracy_m', String(Math.round(geo.acc)));
                    }

                    if (selfieInput && selfieInput.files && selfieInput.files[0]) {
                        fd.append('selfie', selfieInput.files[0]);
                    } else {
                        throw new Error('Selfie is required for this shift. Please capture a photo.');
                    }

                    resp = await fetch(PUNCH_URL, {
                        method: 'POST',
                        headers: { 'X-WP-Nonce': NONCE },
                        credentials: 'same-origin',
                        body: fd
                    });
                } else {
                    const payload = { punch_type: type, source: 'self_web' };
                    if (geo && typeof geo.lat==='number' && typeof geo.lng==='number') {
                        payload.geo_lat = geo.lat;
                        payload.geo_lng = geo.lng;
                        if (typeof geo.acc==='number') payload.geo_accuracy_m = Math.round(geo.acc);
                    }

                    resp = await fetch(PUNCH_URL, {
                        method: 'POST',
                        headers: {
                            'X-WP-Nonce': NONCE,
                            'Content-Type': 'application/json'
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(payload)
                    });
                }

                        text = await resp.text();
        try { j = JSON.parse(text); } catch(_) {}

        const errCode =
            j && (j.code || (j.data && j.data.code)) || null;

        // If server says "selfie required", switch to selfie flow
        if (!resp.ok && errCode === 'sfs_att_selfie_required') {
            requiresSelfie = true;

            // Update hint text
            if (hint) {
                hint.textContent = i18n.selfie_required_hint;
            }

            // Start the camera UI for the same action (in/out/...)
            await startSelfie(type);
            // Don't clear punchInProgress yet - selfie capture will handle it
            return; // don't fall through to generic error
        }

        if (!resp.ok) {
            throw new Error((j && j.message) || 'Punch failed');
        }

                // Success feedback — immediate flash + tone
                flash(type);
                playActionTone(type);
                stopSelfiePreview();

                // Show success status immediately, then refresh in background
                const successLabel = (j && j.data && j.data.label) || punchTypeLabel(type);
                setStat(successLabel, type);
                punchInProgress = false;

                // Non-blocking refresh to update buttons/state
                refresh();

            } catch (e) {
                const errMsg = i18n.error_prefix + ' ' + e.message;
                setStat(errMsg, 'error');
                // Close the overlay so user sees the main status message
                stopSelfiePreview();
                punchInProgress = false;
                if (actionsWrap) {
                    actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                        const t = btn.getAttribute('data-type');
                        btn.disabled = !isActionAvailable(t);
                    });
                }
            }
        }

        async function punch(type){
            // Prevent duplicate submissions
            if (punchInProgress) {
                setStat(i18n.please_wait_processing, 'busy');
                return;
            }
            punchInProgress = true;

            // Disable all action buttons
            if (actionsWrap) {
                actionsWrap.querySelectorAll('button[data-type]').forEach(btn => btn.disabled = true);
            }

            // Skip pre-punch status refresh — the server validates all transitions,
            // cooldowns, and policies on its own. Removing this round-trip saves 1-3s.
            // The UI state from the last refresh is recent enough for button visibility.

            if (!allowed[type]) {
                let msg = 'Invalid action.';
                if (type==='out' && state==='break')        msg = 'You are on break. End the break before clocking out.';
                else if (type==='out' && state!=='in')      msg = 'You are not clocked in.';
                else if (type==='in'  && state!=='idle')    msg = 'Already clocked in.';
                else if (type==='break_start' && state!=='in')  msg = 'You can start a break only while clocked in.';
                else if (type==='break_end'   && state!=='break')msg = 'You have no active break to end.';
                setStat(i18n.error_prefix + ' ' + msg, 'error');
                punchInProgress = false;
                // Re-enable allowed buttons
                if (actionsWrap) {
                    actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                        const t = btn.getAttribute('data-type');
                        btn.disabled = !isActionAvailable(t);
                    });
                }
                return;
            }

            // ---- Pre-flight: method policy check (before geo/camera) ----
            if (methodBlocked[type]) {
                setStat(i18n.error_prefix + ' ' + methodBlocked[type], 'error');
                punchInProgress = false;
                if (actionsWrap) {
                    actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                        const t = btn.getAttribute('data-type');
                        btn.disabled = !isActionAvailable(t);
                    });
                }
                return;
            }

            // ---- Pre-flight: cooldown check (before geo/camera) ----
            const isSameType = (cooldownType === type);
            const elapsed = lastRefreshAt ? Math.floor((Date.now() - lastRefreshAt) / 1000) : 0;
            const cdRaw = isSameType ? cooldownSec : cooldownCrossSec;
            const cdRemaining = Math.max(0, cdRaw - elapsed);
            if (cdRemaining > 0) {
                setStat(i18n.error_prefix + ' ' + (i18n.please_wait || 'Please wait') + ' ' + cdRemaining + (i18n.seconds_short || 's'), 'error');
                punchInProgress = false;
                if (actionsWrap) {
                    actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                        const t = btn.getAttribute('data-type');
                        btn.disabled = !isActionAvailable(t);
                    });
                }
                // Auto-refresh after cooldown expires so stale values are replaced
                setTimeout(() => refresh(), cdRemaining * 1000 + 500);
                return;
            }

            // ---- Pre-flight: geofence check (before camera/selfie) ----
            // MUST use fresh GPS here (not cache) because the server only records
            // valid_geo as a flag — it does not reject the punch. This client-side
            // check is the actual geofence enforcement.
            setStat(i18n.validating, 'busy');
            try {
                await getGeo(type);
            } catch(e) {
                // Geo blocked (outside area / permission denied) — do NOT open camera
                punchInProgress = false;
                syncButtons();
                return;
            }

            if (needsSelfieForType(type)) {
                await startSelfie(type);
                return;
            } else {
                await doPunch(type, null);
            }
        }

        // Selfie capture button → grab frame from video → doPunch
        async function captureAndSubmit(){
            if (!pendingType) return;
            if (!selfieVideo || !selfieCanvas) return;

            // Grab the type and clear pendingType SYNCHRONOUSLY to prevent
            // double-clicks from firing multiple toBlob → doPunch calls.
            const capturedType = pendingType;
            pendingType = null;

            // Disable the capture button immediately
            if (selfieCapture) selfieCapture.disabled = true;

            const vw = selfieVideo.videoWidth;
            const vh = selfieVideo.videoHeight;
            if (!vw || !vh) {
                setStat(i18n.camera_not_ready, 'error');
                pendingType = capturedType; // restore so user can retry
                if (selfieCapture) selfieCapture.disabled = false;
                return;
            }

            const size = Math.min(vw, vh);
            const sx = (vw - size) / 2;
            const sy = (vh - size) / 2;

            const ctx = selfieCanvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(selfieVideo, sx, sy, size, size, 0, 0, selfieCanvas.width, selfieCanvas.height);

            // Show working status in the overlay so user sees it immediately
            if (selfieStatus) selfieStatus.textContent = punchTypeLabel(capturedType) + ' — ' + (i18n.working || 'Working…');

            selfieCanvas.toBlob(async function(blob){
                if (!blob) {
                    setStat(i18n.could_not_capture_selfie, 'error');
                    if (selfieStatus) selfieStatus.textContent = i18n.could_not_capture_selfie || 'Could not capture selfie';
                    pendingType = capturedType; // restore so user can retry
                    if (selfieCapture) selfieCapture.disabled = false;
                    punchInProgress = false;
                    if (actionsWrap) {
                        actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                            const t = btn.getAttribute('data-type');
                            btn.disabled = !isActionAvailable(t);
                        });
                    }
                    return;
                }
                await doPunch(capturedType, blob);
                stopSelfiePreview();
                if (selfieCapture) selfieCapture.disabled = false;
            }, 'image/jpeg', 0.75);
        }

        // Wire buttons
        if (actionsWrap) {
            actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                btn.addEventListener('click', ()=> punch(btn.getAttribute('data-type')));
            });
        }

        selfieCapture && selfieCapture.addEventListener('click', captureAndSubmit);
        selfieCancel  && selfieCancel.addEventListener('click', ()=>{
            pendingType = null;
            punchInProgress = false;
            stopSelfiePreview(); // Also unlocks body scroll and clears overlay status
            setStat(i18n.cancelled, 'idle');
            // Re-enable buttons
            if (actionsWrap) {
                actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                    const t = btn.getAttribute('data-type');
                    btn.disabled = !isActionAvailable(t);
                });
            }
        });

        // Fallback: if user selects file manually (no live camera), submit
        selfieInput && selfieInput.addEventListener('change', async ()=>{
            if (!pendingType && requiresSelfie) {
                // If no pending action, do nothing – user should press a button first
                return;
            }
            if (selfieInput.files && selfieInput.files[0] && pendingType) {
                await doPunch(pendingType, selfieInput.files[0]);
                pendingType = null;
                selfieWrap.style.display = 'none';
            }
        });

        // Auto-refresh on visibility
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refresh();
        });

        // Pre-request GPS on page load to warm up coordinates cache
        (async function preloadGeo() {
            try {
                const g = await getGeo('in');
                // Cache result for faster first punch
                if (g && !cachedGeo) {
                    cachedGeo = { ...g, ts: Date.now() };
                }
            } catch(e) {
                // Location denied or unavailable - user will see error when they try to punch
            }
        })();

        // Re-render dynamic elements when translations arrive (fixes race with refresh)
        window.addEventListener('sfs_hr_i18n_updated', function() {
            updateChip();
            if (requiresSelfie) {
                hint && (hint.textContent = i18n.selfie_required_hint);
            } else {
                hint && (hint.textContent = i18n.location_hint);
            }
            // Re-apply button labels
            if (actionsWrap) {
                actionsWrap.querySelectorAll('button[data-i18n-key]').forEach(function(btn) {
                    var key = btn.dataset.i18nKey;
                    if (key && i18n[key]) btn.textContent = i18n[key];
                });
            }
            // Re-render date in new locale
            if (typeof tickDate === 'function') tickDate();
        });

        // Initial load
        refresh();
    })();
    </script>
    <script>
    (function(){
        var mapEl = document.getElementById('sfs-att-map-<?php echo esc_js( $inst ); ?>');
        if (!mapEl) return;
        var geoLat = <?php echo (float) $geo_lat; ?> || 0;
        var geoLng = <?php echo (float) $geo_lng; ?> || 0;
        var geoRad = <?php echo (int) ( $geo_radius ?: 150 ); ?>;
        var hasGeofence = (geoLat !== 0 && geoLng !== 0);
        var map, circle, userMarker;

        function initMap() {
            if (typeof L === 'undefined') { setTimeout(initMap, 200); return; }

            if (hasGeofence) {
                map = L.map(mapEl, { zoomControl: false, attributionControl: false }).setView([geoLat, geoLng], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
                circle = L.circle([geoLat, geoLng], { radius: geoRad, color: '#0f4c5c', fillColor: '#0f4c5c', fillOpacity: 0.15, weight: 2 }).addTo(map);
                L.marker([geoLat, geoLng]).addTo(map).bindPopup('<?php echo esc_js( __( 'Workplace', 'sfs-hr' ) ); ?>');
                map.fitBounds(circle.getBounds().pad(0.2));
            } else {
                map = L.map(mapEl, { zoomControl: false, attributionControl: false }).setView([24.7136, 46.6753], 10);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            }

            // Ensure tiles fill the flex-sized container
            setTimeout(function(){ map.invalidateSize(); }, 300);
            updateUserPos();
        }

        function updateUserPos() {
            if (!navigator.geolocation) return;
            navigator.geolocation.watchPosition(function(pos) {
                var ll = [pos.coords.latitude, pos.coords.longitude];
                if (!userMarker) {
                    var pulseIcon = L.divIcon({
                        className: 'sfs-att-user-dot',
                        iconSize: [18, 18],
                        iconAnchor: [9, 9],
                        html: '<span></span>'
                    });
                    userMarker = L.marker(ll, { icon: pulseIcon }).addTo(map);
                    if (!hasGeofence) map.setView(ll, 15);
                } else {
                    userMarker.setLatLng(ll);
                }
            }, function(){}, { enableHighAccuracy: true, maximumAge: 10000 });
        }

        // Handle container resize
        window.addEventListener('resize', function(){ if (map) map.invalidateSize(); });

        // Locate-me button
        var locateBtn = document.getElementById('sfs-att-locate-<?php echo esc_js( $inst ); ?>');
        if (locateBtn) {
            locateBtn.addEventListener('click', function(){
                if (!navigator.geolocation || !map) return;
                locateBtn.classList.add('locating');
                navigator.geolocation.getCurrentPosition(function(pos){
                    var ll = [pos.coords.latitude, pos.coords.longitude];
                    map.setView(ll, 17, { animate: true });
                    if (!userMarker) {
                        var pulseIcon = L.divIcon({
                            className: 'sfs-att-user-dot',
                            iconSize: [18, 18],
                            iconAnchor: [9, 9],
                            html: '<span></span>'
                        });
                        userMarker = L.marker(ll, { icon: pulseIcon }).addTo(map);
                    } else {
                        userMarker.setLatLng(ll);
                    }
                    locateBtn.classList.remove('locating');
                }, function(){
                    locateBtn.classList.remove('locating');
                }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 });
            });
        }

        initMap();
    })();
    </script>
    <style>
    .sfs-att-user-dot{background:none!important;border:none!important;}
    .sfs-att-user-dot span{
      display:block;width:14px;height:14px;border-radius:50%;
      background:#2563eb;border:2.5px solid #fff;
      box-shadow:0 0 0 4px rgba(37,99,235,0.25);
      animation:sfsAttPulse 2s infinite;
    }
    @keyframes sfsAttPulse{
      0%{box-shadow:0 0 0 0 rgba(37,99,235,0.4)}
      70%{box-shadow:0 0 0 10px rgba(37,99,235,0)}
      100%{box-shadow:0 0 0 0 rgba(37,99,235,0)}
    }
    </style>
    <?php
    return ob_get_clean();
}



/**
 * Kiosk Widget
 */
public function shortcode_kiosk( $atts = [] ): string {
    if ( ! is_user_logged_in() ) { return '<div>' . esc_html__( 'Please sign in.', 'sfs-hr' ) . '</div>'; }
    if ( ! current_user_can( 'sfs_hr_attendance_clock_kiosk' ) && ! current_user_can('sfs_hr_attendance_admin') ) {
        return '<div>' . esc_html__( 'Access denied (kiosk only).', 'sfs-hr' ) . '</div>';
    }

    $atts = shortcode_atts(['device' => 0], $atts, 'sfs_hr_kiosk');
    $atts = shortcode_atts([
  'device'    => 0,
  'immersive' => '1', // default ON
], $atts, 'sfs_hr_kiosk');

$immersive = $atts['immersive'] === '1' || $atts['immersive'] === 1 || $atts['immersive'] === true;


    global $wpdb;
    $devT = $wpdb->prefix.'sfs_hr_attendance_devices';

    // Resolve device id (shortcode > first active kiosk)
    $device_id = (int)$atts['device'];
    if ($device_id <= 0) {
        $device_id = (int)$wpdb->get_var("SELECT id FROM {$devT} WHERE active=1 AND type='kiosk' ORDER BY id ASC LIMIT 1");
    }
    $device = $device_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$devT} WHERE id=%d", $device_id), ARRAY_A) : null;
    if (!$device) { return '<div>' . esc_html__( 'No kiosk device configured.', 'sfs-hr' ) . '</div>'; }

    // Device meta
    $meta = [];
    if (!empty($device['meta_json'])) {
        $m = json_decode((string)$device['meta_json'], true);
        if (is_array($m)) { $meta = $m; }
    }
    

    $nonce      = wp_create_nonce('wp_rest');
    $status_url = esc_url_raw( rest_url('sfs-hr/v1/attendance/status') );
    $punch_url  = esc_url_raw( rest_url('sfs-hr/v1/attendance/punch') );

// unique instance token for DOM ids (per-shortcode)
$inst = 'k'.substr(wp_hash( (string)$device_id . ':' . (string)wp_rand() ), 0, 6);
$root_id = 'sfs-kiosk-'.$inst;


    ob_start(); ?>
<?php if ( $immersive ): ?>
  <script>
    // Hide theme chrome + lock scroll while kiosk is open
    document.documentElement.classList.add('sfs-kiosk-immersive');
    document.body.classList.add('sfs-kiosk-immersive');
  </script>
  <div class="sfs-kiosk-veil" role="application" aria-label="Attendance Kiosk">
<?php endif; ?>

<?php
$geo_lat    = isset( $device['geo_lock_lat'] )      ? trim( (string) $device['geo_lock_lat'] )      : '';
$geo_lng    = isset( $device['geo_lock_lng'] )      ? trim( (string) $device['geo_lock_lng'] )      : '';
$geo_radius = isset( $device['geo_lock_radius_m'] ) ? trim( (string) $device['geo_lock_radius_m'] ) : '';
?>

<div
  id="<?php echo esc_attr($root_id); ?>"
  class="sfs-att-kiosk sfs-kiosk-app"
  data-view="menu"
  data-geo-lat="<?php echo esc_attr( $geo_lat ); ?>"
  data-geo-lng="<?php echo esc_attr( $geo_lng ); ?>"
  data-geo-radius="<?php echo esc_attr( $geo_radius ); ?>"
  data-geo-required="<?php echo ( $geo_lat && $geo_lng && $geo_radius ) ? '1' : '0'; ?>"
  data-geo-enforce-in="1"
  data-geo-enforce-out="1"
>

  <!-- ── MENU HEADER (top bar — shown in menu view) ── -->
  <header class="sfs-kh">
    <span class="sfs-kh-brand"><?php echo esc_html( get_bloginfo('name') ); ?></span>
    <span id="sfs-kiosk-clock-<?php echo $inst; ?>" class="sfs-kh-clock">--:--</span>
    <div class="sfs-kh-meta">
      <span id="sfs-kiosk-date-<?php echo $inst; ?>" class="sfs-kh-date">—</span>
      <span class="sfs-kh-device"><?php echo esc_html($device['label'] ?? 'Kiosk'); ?></span>
    </div>
  </header>

  <!-- ── SCAN HEADER (shown in scan view) ── -->
  <header class="sfs-sh">
    <button id="sfs-kiosk-stop-<?php echo $inst; ?>" type="button" class="sfs-sh-stop"><?php esc_html_e( 'STOP', 'sfs-hr' ); ?></button>
    <div class="sfs-sh-mode">
      <span id="sfs-kiosk-lane-label-<?php echo $inst; ?>" class="sfs-sh-action"><?php esc_html_e( 'Clock In', 'sfs-hr' ); ?></span>
      <span class="sfs-sh-sub"><?php esc_html_e( 'Continuous Scan Mode', 'sfs-hr' ); ?></span>
    </div>
    <div class="sfs-sh-counter">
      <span id="sfs-kiosk-count-<?php echo $inst; ?>" class="sfs-sh-num">0</span>
      <span class="sfs-sh-lbl"><?php esc_html_e( 'scanned', 'sfs-hr' ); ?></span>
    </div>
    <span id="sfs-kiosk-session-time-<?php echo $inst; ?>" class="sfs-sh-time">--:--</span>
  </header>

  <!-- ── SCAN INFO BAR (mobile: system name + time + date below scan header) ── -->
  <div class="sfs-scan-info">
    <span class="sfs-scan-info-brand"><?php echo esc_html( get_bloginfo('name') ); ?></span>
    <span id="sfs-scan-info-time-<?php echo $inst; ?>" class="sfs-scan-info-time">--:--</span>
    <span id="sfs-scan-info-date-<?php echo $inst; ?>" class="sfs-scan-info-date">&mdash;</span>
  </div>

  <!-- ── CONTENT AREA ── -->
  <main class="sfs-kc">
    <h2 class="sfs-title sr-only"><?php esc_html_e( 'Attendance Kiosk', 'sfs-hr' ); ?></h2>
    <h1 id="sfs-greet-<?php echo $inst; ?>" class="sfs-greet"><?php esc_html_e( 'Good day!', 'sfs-hr' ); ?></h1>

    <div class="sfs-statusbar">
      <span id="sfs-status-dot-<?php echo $inst; ?>" class="sfs-dot sfs-dot--idle"></span>
      <span id="sfs-status-text-<?php echo $inst; ?>"><?php esc_html_e( 'Ready', 'sfs-hr' ); ?></span>
    </div>

    <!-- lane chip (internal state only — hidden from all views) -->
    <span id="sfs-kiosk-lane-chip-<?php echo $inst; ?>" class="sfs-chip sfs-chip--in" hidden aria-hidden="true"><?php esc_html_e( 'Clock In', 'sfs-hr' ); ?></span>

    <!-- ── Instruction text (menu view only) ── -->
    <p class="sfs-kiosk-instruction"><?php esc_html_e( 'Select an action, then scan employee QR codes.', 'sfs-hr' ); ?></p>

    <!-- ── ACTION BUTTONS (menu view — card style per mockup) ── -->
    <div id="sfs-kiosk-lane-<?php echo $inst; ?>" class="sfs-al">
      <button type="button" data-action="in" class="sfs-ab sfs-ab--in sfs-lane-btn">
        <span class="sfs-ab-dot"></span><?php esc_html_e( 'Clock In', 'sfs-hr' ); ?><span class="sfs-ab-arr">&#8250;</span>
      </button>
      <button type="button" data-action="out" class="sfs-ab sfs-ab--out sfs-lane-btn">
        <span class="sfs-ab-dot"></span><?php esc_html_e( 'Clock Out', 'sfs-hr' ); ?><span class="sfs-ab-arr">&#8250;</span>
      </button>
      <?php if ( ! empty( $device['break_enabled'] ) ) : ?>
      <button type="button" data-action="break_start" class="sfs-ab sfs-ab--brk sfs-lane-btn">
        <span class="sfs-ab-dot"></span><?php esc_html_e( 'Start Break', 'sfs-hr' ); ?><span class="sfs-ab-arr">&#8250;</span>
      </button>
      <button type="button" data-action="break_end" class="sfs-ab sfs-ab--bend sfs-lane-btn">
        <span class="sfs-ab-dot"></span><?php esc_html_e( 'End Break', 'sfs-hr' ); ?><span class="sfs-ab-arr">&#8250;</span>
      </button>
      <?php endif; ?>
    </div>


    <!-- ── CAMERA (scan view — within viewport) ── -->
    <div id="sfs-kiosk-camwrap-<?php echo $inst; ?>" class="sfs-camwrap">
      <!-- Camera + activity panel row (side-by-side on tablet, stacked on mobile) -->
      <div class="sfs-scan-row">
        <div class="sfs-cam-card">
          <div class="sfs-cam-body">
            <div class="sfs-cam-feed">
              <video id="sfs-kiosk-qr-video-<?php echo $inst; ?>"
                     autoplay playsinline webkit-playsinline muted></video>
              <div class="sfs-qr-guide"><div class="sfs-qr-c sfs-qr-tl"></div><div class="sfs-qr-c sfs-qr-tr"></div><div class="sfs-qr-c sfs-qr-bl"></div><div class="sfs-qr-c sfs-qr-br"></div></div>
              <div class="sfs-scan-hint"><span class="sfs-pulse"></span> <?php esc_html_e( 'Next employee — show QR code', 'sfs-hr' ); ?></div>
              <!-- Inline camera status badge -->
              <span id="sfs-kiosk-cam-badge-<?php echo $inst; ?>" class="sfs-cam-badge"><?php esc_html_e( 'Camera On', 'sfs-hr' ); ?></span>
            </div>
          </div>
        </div>
        <!-- Recent activity panel (always visible on both mobile & tablet) -->
        <div id="sfs-kiosk-log-<?php echo $inst; ?>" class="sfs-scan-log">
          <div class="sfs-log-header">
            <h4 class="sfs-log-title"><?php esc_html_e( 'Recent Scans', 'sfs-hr' ); ?></h4>
            <span class="sfs-log-live"><?php esc_html_e( 'Live', 'sfs-hr' ); ?></span>
          </div>
          <ul id="sfs-kiosk-log-list-<?php echo $inst; ?>" class="sfs-log-list"></ul>
        </div>
      </div>
      <!-- Quick tips panel (visible on mobile below camera, hidden on tablet where log shows inline) -->
      <div class="sfs-scan-tips">
        <div class="sfs-scan-tips-row">
          <span class="sfs-scan-tips-icon">&#9432;</span>
          <span><?php esc_html_e( 'Hold QR code steady in the frame', 'sfs-hr' ); ?></span>
        </div>
        <div class="sfs-scan-tips-row">
          <span class="sfs-scan-tips-icon">&#8635;</span>
          <span><?php esc_html_e( 'Scans automatically — no need to tap', 'sfs-hr' ); ?></span>
        </div>
        <div class="sfs-scan-tips-row">
          <span class="sfs-scan-tips-icon">&#9650;</span>
          <span><?php esc_html_e( 'Swipe up to view recent scans', 'sfs-hr' ); ?></span>
        </div>
      </div>
      <!-- Hidden elements for QR processing -->
      <canvas id="sfs-kiosk-selfie-<?php echo $inst; ?>" width="480" height="480" hidden></canvas>
      <span id="sfs-kiosk-qr-status-<?php echo $inst; ?>" class="sfs-qr-status"></span>
    </div>

    <!-- Large flash overlay with employee name -->
    <div id="sfs-kiosk-flash-<?php echo $inst; ?>" class="sfs-flash">
      <div class="sfs-flash-inner">
        <div id="sfs-kiosk-flash-name-<?php echo $inst; ?>" class="sfs-flash-name"></div>
        <div id="sfs-kiosk-flash-action-<?php echo $inst; ?>" class="sfs-flash-action"></div>
        <div id="sfs-kiosk-flash-time-<?php echo $inst; ?>" class="sfs-flash-time"></div>
      </div>
    </div>

    <!-- Session summary overlay -->
    <div id="sfs-kiosk-summary-<?php echo $inst; ?>" class="sfs-session-summary" style="display:none;">
      <div class="sfs-summary-card">
        <h2><?php esc_html_e( 'Session Complete', 'sfs-hr' ); ?></h2>
        <div class="sfs-summary-stat">
          <span class="sfs-summary-number" id="sfs-summary-count-<?php echo $inst; ?>">0</span>
          <span class="sfs-summary-label"><?php esc_html_e( 'employees scanned', 'sfs-hr' ); ?></span>
        </div>
        <div class="sfs-summary-detail" id="sfs-summary-duration-<?php echo $inst; ?>"></div>
        <div style="display:flex;gap:12px;margin-top:24px;">
          <button type="button" id="sfs-summary-done-<?php echo $inst; ?>" style="flex:1;padding:13px 24px;font-size:15px;font-weight:600;border:none;border-radius:12px;background:#0f4c5c;color:#fff;cursor:pointer;"><?php esc_html_e( 'Done', 'sfs-hr' ); ?></button>
        </div>
      </div>
    </div>

  </main>

  <!-- ── Wrong-punch error notice (overlay) ── -->
  <div id="sfs-kiosk-error-notice-<?php echo $inst; ?>" class="sfs-punch-error-notice">
    <div class="sfs-error-icon">&times;</div>
    <div id="sfs-kiosk-error-msg-<?php echo $inst; ?>" class="sfs-error-msg"></div>
    <div id="sfs-kiosk-error-detail-<?php echo $inst; ?>" class="sfs-error-detail"></div>
  </div>

  <!-- ── Mobile: slide-up modal trigger (bottom bar) ── -->
  <div id="sfs-log-modal-trigger-<?php echo $inst; ?>" class="sfs-log-modal-trigger">
    <span id="sfs-log-modal-count-<?php echo $inst; ?>" class="sfs-log-modal-count">0</span>
    <span id="sfs-log-modal-last-<?php echo $inst; ?>" class="sfs-log-modal-last"><?php esc_html_e( 'Waiting for scans…', 'sfs-hr' ); ?></span>
    <span class="sfs-log-modal-arrow">&#8593;</span>
  </div>

  <!-- ── Mobile: slide-up modal backdrop ── -->
  <div id="sfs-log-modal-backdrop-<?php echo $inst; ?>" class="sfs-log-modal-backdrop"></div>

  <!-- ── Mobile: slide-up modal panel ── -->
  <div id="sfs-log-modal-<?php echo $inst; ?>" class="sfs-log-modal">
    <div class="sfs-log-modal-handle"></div>
    <div class="sfs-log-header">
      <h4 class="sfs-log-title"><?php esc_html_e( 'Recent Scans', 'sfs-hr' ); ?></h4>
      <span class="sfs-log-live"><?php esc_html_e( 'Live', 'sfs-hr' ); ?></span>
    </div>
    <ul id="sfs-log-modal-list-<?php echo $inst; ?>" class="sfs-log-list"></ul>
  </div>

</div> <!-- .sfs-kiosk-app -->
<?php if ( $immersive ): ?>
  </div> <!-- .sfs-kiosk-veil -->
<?php endif; ?>


<style>
/* ==== Scope to this kiosk instance — matches self-web design ==== */
#<?php echo $root_id; ?>.sfs-kiosk-app{
  --sfs-teal:#0f4c5c;
  --sfs-surface:#ffffff;
  --sfs-bg:#f0f2f5;
  --sfs-white:#ffffff;
  --sfs-border:#e5e7eb;
  --sfs-text:#111827;
  --sfs-text-muted:#6b7280;
  --sfs-green:#22c55e;
  --sfs-green-dark:#16a34a;
  --sfs-red:#ef4444;
  --sfs-amber:#f59e0b;
  --sfs-blue:#3b82f6;
  --sfs-radius:16px;
  position:relative;
  min-height:100dvh; width:100%; margin:0;
  display:flex; flex-direction:column;
  background:var(--sfs-bg);
  font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  -webkit-font-smoothing:antialiased;
  color:var(--sfs-text);
}

/* ── MENU HEADER (top bar — teal like self-web brand badge) ── */
#<?php echo $root_id; ?> .sfs-kh{
  background:var(--sfs-teal); color:#fff;
  padding:14px 24px;
  display:flex; align-items:center; justify-content:space-between;
  flex-shrink:0;
}
#<?php echo $root_id; ?> .sfs-kh-brand{
  font-weight:700; font-size:14px; letter-spacing:0.04em; text-transform:uppercase;
}
#<?php echo $root_id; ?> .sfs-kh-clock{ font-weight:800; font-size:32px; letter-spacing:-0.02em; line-height:1; }
#<?php echo $root_id; ?> .sfs-kh-meta{ text-align:right; font-size:13px; opacity:0.9; line-height:1.4; display:flex; flex-direction:column; }
#<?php echo $root_id; ?> .sfs-kh-device{ font-size:12px; opacity:0.8; }

/* ── SCAN HEADER (compact bar during scanning) ── */
#<?php echo $root_id; ?> .sfs-sh{
  background:var(--sfs-teal); color:#fff;
  padding:10px 16px;
  display:flex; align-items:center; gap:12px;
  flex-shrink:0;
}
#<?php echo $root_id; ?> .sfs-sh-stop{
  background:var(--sfs-red); border:none; color:#fff;
  padding:7px 16px; border-radius:12px; font-size:13px; font-weight:700;
  letter-spacing:0.02em; cursor:pointer;
  transition:transform 0.1s, background 0.15s;
}
#<?php echo $root_id; ?> .sfs-sh-stop:hover{ background:#dc2626; }
#<?php echo $root_id; ?> .sfs-sh-stop:active{ transform:scale(0.97); }
#<?php echo $root_id; ?> .sfs-sh-mode{ flex:1; text-align:center; }
#<?php echo $root_id; ?> .sfs-sh-action{ font-weight:700; font-size:17px; display:block; }
#<?php echo $root_id; ?> .sfs-sh-sub{ font-size:11px; opacity:0.75; }
#<?php echo $root_id; ?> .sfs-sh-counter{
  background:rgba(255,255,255,0.15); border-radius:12px;
  padding:5px 12px; text-align:center; min-width:60px;
}
#<?php echo $root_id; ?> .sfs-sh-num{ font-weight:800; font-size:20px; display:block; line-height:1.1; }
#<?php echo $root_id; ?> .sfs-sh-lbl{ font-size:9px; text-transform:uppercase; opacity:0.7; letter-spacing:0.04em; }
#<?php echo $root_id; ?> .sfs-sh-time{ font-size:14px; opacity:0.9; }

/* ── CONTENT AREA (white panel with rounded top — like self-web .sfs-att-panel) ── */
#<?php echo $root_id; ?> .sfs-kc{
  flex:1; display:flex; flex-direction:column; align-items:center;
  padding:20px 20px 24px;
  background:var(--sfs-surface);
  border-radius:var(--sfs-radius) var(--sfs-radius) 0 0;
  box-shadow:0 -4px 20px rgba(0,0,0,0.08);
  overflow:hidden;
}

/* Greeting */
#<?php echo $root_id; ?> .sfs-greet{
  font-weight:800; font-size:clamp(24px,5vw,36px);
  color:var(--sfs-text); margin:clamp(8px,2vw,20px) 0; text-align:center;
}

/* ── Statusbar (matches self-web .sfs-att-statusline) ── */
#<?php echo $root_id; ?> .sfs-statusbar{
  display:flex; align-items:center; gap:10px;
  width:100%; max-width:500px; margin:8px auto 12px;
  padding:10px 14px;
  border-radius:10px; font-size:13px; transition:all 0.2s;
}
#<?php echo $root_id; ?> .sfs-dot{ width:10px; height:10px; border-radius:999px; display:inline-block; }
#<?php echo $root_id; ?> .sfs-dot--idle{ background:#d1d5db; }
#<?php echo $root_id; ?> .sfs-dot--in{ background:var(--sfs-green); }
#<?php echo $root_id; ?> .sfs-dot--out{ background:var(--sfs-red); }
#<?php echo $root_id; ?> .sfs-dot--break_start{ background:var(--sfs-amber); }
#<?php echo $root_id; ?> .sfs-dot--break_end{ background:var(--sfs-blue); }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="idle"]        { color:#6b7280; background:transparent; border:none; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="ok"]          { color:#166534; background:#dcfce7; border:1px solid #bbf7d0; font-weight:600; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="busy"]        { color:#1d4ed8; background:#eff6ff; border:1px solid #bfdbfe; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="error"]       { color:#b91c1c; background:#fee2e2; border:1px solid #fecaca; font-weight:500; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="in"]          { color:#166534; background:#dcfce7; border:1px solid #bbf7d0; font-weight:600; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="out"]         { color:#b91c1c; background:#fee2e2; border:1px solid #fecaca; font-weight:600; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="break_start"] { color:#92400e; background:#fef3c7; border:1px solid #fde68a; font-weight:600; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="break_end"]   { color:#1e40af; background:#dbeafe; border:1px solid #bfdbfe; font-weight:600; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="scanning"]    { color:#1d4ed8; background:#eff6ff; border:1px solid #bfdbfe; }

/* Lane chip — internal state element, always hidden */
#<?php echo $root_id; ?> .sfs-chip{
  display:none !important;
}
/* Instruction text on menu view */
#<?php echo $root_id; ?> .sfs-kiosk-instruction{
  font-size:14px; color:var(--sfs-text-muted); margin:0 0 4px; text-align:center;
}
#<?php echo $root_id; ?>[data-view="scan"] .sfs-kiosk-instruction{ display:none; }
#<?php echo $root_id; ?> .sfs-chip--idle{ background:#f3f4f6; color:#374151; }
#<?php echo $root_id; ?> .sfs-chip--in{ background:#dcfce7; color:#166534; border-color:#bbf7d0; }
#<?php echo $root_id; ?> .sfs-chip--out{ background:#fee2e2; color:#b91c1c; border-color:#fecaca; }
#<?php echo $root_id; ?> .sfs-chip--break_start{ background:#fef3c7; color:#92400e; border-color:#fde68a; }
#<?php echo $root_id; ?> .sfs-chip--break_end{ background:#dbeafe; color:#1e40af; border-color:#bfdbfe; }

/* ── ACTION BUTTONS (solid colored — matches self-web .sfs-att-btn) ── */
#<?php echo $root_id; ?> .sfs-al{
  width:100%; max-width:500px;
  display:flex; flex-wrap:wrap; gap:10px;
  margin-top:clamp(8px,3vw,24px);
}
#<?php echo $root_id; ?> .sfs-ab{
  flex:1 1 calc(50% - 5px); min-width:120px;
  display:flex; align-items:center; justify-content:center; gap:10px;
  padding:14px 12px; border-radius:12px;
  border:none;
  font-size:15px; font-weight:600; color:#fff;
  cursor:pointer; text-align:center;
  transition:transform 0.1s, box-shadow 0.15s;
}
#<?php echo $root_id; ?> .sfs-ab:active:not(:disabled){ transform:scale(0.97); }
#<?php echo $root_id; ?> .sfs-ab:disabled{ opacity:0.45; cursor:not-allowed; }
#<?php echo $root_id; ?> .sfs-ab-dot{ display:none; }
#<?php echo $root_id; ?> .sfs-ab-arr{ display:none; }
#<?php echo $root_id; ?> .sfs-ab--in  { background:var(--sfs-green); box-shadow:0 2px 8px rgba(34,197,94,0.3); }
#<?php echo $root_id; ?> .sfs-ab--out { background:var(--sfs-red); box-shadow:0 2px 8px rgba(239,68,68,0.3); }
#<?php echo $root_id; ?> .sfs-ab--brk { background:var(--sfs-amber); box-shadow:0 2px 8px rgba(245,158,11,0.3); }
#<?php echo $root_id; ?> .sfs-ab--bend{ background:var(--sfs-blue); box-shadow:0 2px 8px rgba(59,130,246,0.3); }
#<?php echo $root_id; ?> .sfs-ab.button-suggested{
  box-shadow:0 0 0 3px rgba(255,255,255,0.4), 0 2px 12px rgba(0,0,0,0.15);
  transform:scale(1.02);
}

/* ── CAMERA AREA (within viewport) ── */
#<?php echo $root_id; ?> .sfs-camwrap{ width:100%; flex:1; display:flex; flex-direction:column; min-height:0; padding:12px; gap:12px; }
#<?php echo $root_id; ?> .sfs-scan-row{ flex:1; display:flex; gap:12px; min-height:0; }
#<?php echo $root_id; ?> .sfs-cam-card{ flex:1; display:flex; flex-direction:column; min-height:0; }
#<?php echo $root_id; ?> .sfs-cam-body{
  flex:1; display:flex; position:relative; background:#0a0a14; overflow:hidden;
  border-radius:var(--sfs-radius); min-height:0;
}
#<?php echo $root_id; ?> .sfs-cam-feed{
  flex:1; display:flex; align-items:center; justify-content:center; position:relative;
}
#<?php echo $root_id; ?> .sfs-cam-feed video{
  width:100%; height:100%; object-fit:cover; display:block;
}
/* Camera status badge (inline on camera) */
#<?php echo $root_id; ?> .sfs-cam-badge{
  position:absolute; top:12px; right:12px;
  background:rgba(0,0,0,0.5); color:#fff; padding:4px 10px;
  border-radius:10px; font-size:11px; font-weight:600;
  display:flex; align-items:center; gap:6px;
  backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
}
#<?php echo $root_id; ?> .sfs-cam-badge::before{
  content:''; width:6px; height:6px; border-radius:50%;
  background:var(--sfs-green); box-shadow:0 0 4px var(--sfs-green);
}
#<?php echo $root_id; ?> .sfs-cam-badge.off{ opacity:0.7; }
#<?php echo $root_id; ?> .sfs-cam-badge.off::before{ background:var(--sfs-red); box-shadow:0 0 4px var(--sfs-red); }
/* QR guide corners */
#<?php echo $root_id; ?> .sfs-qr-guide{
  position:absolute; width:140px; height:140px;
  top:50%; left:50%; transform:translate(-50%,-50%); pointer-events:none;
}
#<?php echo $root_id; ?> .sfs-qr-c{
  position:absolute; width:28px; height:28px;
  border-color:rgba(255,255,255,0.7); border-style:solid; border-width:0;
}
#<?php echo $root_id; ?> .sfs-qr-tl{ top:0;left:0; border-top-width:3px; border-left-width:3px; border-top-left-radius:6px; }
#<?php echo $root_id; ?> .sfs-qr-tr{ top:0;right:0; border-top-width:3px; border-right-width:3px; border-top-right-radius:6px; }
#<?php echo $root_id; ?> .sfs-qr-bl{ bottom:0;left:0; border-bottom-width:3px; border-left-width:3px; border-bottom-left-radius:6px; }
#<?php echo $root_id; ?> .sfs-qr-br{ bottom:0;right:0; border-bottom-width:3px; border-right-width:3px; border-bottom-right-radius:6px; }
#<?php echo $root_id; ?> .sfs-scan-hint{
  position:absolute; bottom:16px; left:50%; transform:translateX(-50%);
  background:rgba(0,0,0,0.6); color:#fff; padding:8px 18px; border-radius:20px;
  font-size:13px; display:flex; align-items:center; gap:8px; white-space:nowrap;
}
#<?php echo $root_id; ?> .sfs-pulse{
  width:8px; height:8px; border-radius:50%; background:var(--sfs-green);
  box-shadow:0 0 0 3px rgba(22,163,74,0.3);
  animation:sfs-pulse-anim 2s infinite;
}
@keyframes sfs-pulse-anim{
  0%{box-shadow:0 0 0 0 rgba(22,163,74,0.4)}
  70%{box-shadow:0 0 0 8px rgba(22,163,74,0)}
  100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}
}
#<?php echo $root_id; ?> .sfs-qr-status{ display:none; }

/* ── Recent activity panel (card-based, always visible) ── */
#<?php echo $root_id; ?> .sfs-scan-log{
  width:260px; background:var(--sfs-white); color:var(--sfs-text);
  display:flex; flex-direction:column; flex-shrink:0;
  border-radius:var(--sfs-radius); border:1px solid var(--sfs-border);
  overflow:hidden;
}
#<?php echo $root_id; ?> .sfs-log-header{
  display:flex; align-items:center; justify-content:space-between;
  padding:14px 16px 10px; border-bottom:1px solid var(--sfs-border);
}
#<?php echo $root_id; ?> .sfs-log-title{
  font-size:14px; font-weight:700; margin:0; color:var(--sfs-text);
}
#<?php echo $root_id; ?> .sfs-log-live{
  font-size:10px; font-weight:600; text-transform:uppercase;
  color:var(--sfs-green); letter-spacing:0.04em;
}
#<?php echo $root_id; ?> .sfs-log-list{ list-style:none; margin:0; padding:0; flex:1; overflow-y:auto; }
#<?php echo $root_id; ?> .sfs-log-list:empty::after{
  content:'Waiting for scans\2026'; display:block; text-align:center;
  padding:32px 16px; font-size:13px; color:var(--sfs-text-muted);
}
#<?php echo $root_id; ?> .sfs-log-list li{
  display:flex; align-items:center; gap:10px;
  padding:8px 12px; margin:3px 8px; border-radius:10px;
  background:#f9fafb; font-size:13px;
  border-bottom:none;
}
#<?php echo $root_id; ?> .sfs-log-list li:last-child{ border-bottom:none; }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-initials{
  width:32px; height:32px; border-radius:50%;
  display:flex; align-items:center; justify-content:center;
  font-size:11px; font-weight:700; flex-shrink:0;
  background:rgba(15,76,92,0.1); color:var(--sfs-teal);
}
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-initials.ok{ background:rgba(22,163,74,0.1); color:var(--sfs-green); }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-initials.err{ background:rgba(239,68,68,0.1); color:var(--sfs-red); }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-info{ flex:1; min-width:0; }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-name{
  font-weight:600; font-size:14px; color:#374151; display:block;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-meta{
  font-size:11px; color:var(--sfs-text-muted); display:flex; align-items:center; gap:4px;
}
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-meta .sfs-log-dot{
  width:6px; height:6px; border-radius:50%; flex-shrink:0;
}
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-meta .sfs-log-dot.ok{ background:var(--sfs-green); }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-meta .sfs-log-dot.err{ background:var(--sfs-red); }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-time{
  font-size:13px; color:#6b7280; font-weight:500; flex-shrink:0; white-space:nowrap;
}

/* Flash overlay — large centered with employee name */
#<?php echo $root_id; ?> .sfs-flash-inner{
  position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
  text-align:center; color:#fff; text-shadow:0 2px 8px rgba(0,0,0,0.3);
  pointer-events:none;
}
#<?php echo $root_id; ?> .sfs-flash-name{ font-size:clamp(24px,4vw,42px); font-weight:800; line-height:1.2; }
#<?php echo $root_id; ?> .sfs-flash-action{ font-size:clamp(16px,2.4vw,24px); margin-top:6px; opacity:.9; }
#<?php echo $root_id; ?> .sfs-flash-time{ font-size:clamp(14px,1.8vw,18px); margin-top:4px; opacity:.8; }

/* Session summary overlay */
#<?php echo $root_id; ?> .sfs-session-summary{
  position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999;
  display:flex; align-items:center; justify-content:center;
}
#<?php echo $root_id; ?> .sfs-summary-card{
  background:#fff; border-radius:16px; padding:clamp(24px,4vw,48px);
  text-align:center; max-width:420px; width:90%;
  box-shadow:0 20px 60px rgba(0,0,0,0.2);
}
#<?php echo $root_id; ?> .sfs-summary-card h2{ margin:0 0 24px; font-size:clamp(22px,3vw,32px); color:#111827; }
#<?php echo $root_id; ?> .sfs-summary-stat{ margin:16px 0; }
#<?php echo $root_id; ?> .sfs-summary-number{ font-size:clamp(48px,8vw,72px); font-weight:800; color:var(--sfs-teal); line-height:1; }
#<?php echo $root_id; ?> .sfs-summary-label{ display:block; font-size:16px; color:#6b7280; margin-top:4px; }
#<?php echo $root_id; ?> .sfs-summary-detail{ font-size:14px; color:#6b7280; }

/* Scan tips — hidden by default; shown on mobile via media query */
#<?php echo $root_id; ?> .sfs-scan-tips{ display:none; }

/* ── VIEW TOGGLE: menu vs scan ── */
#<?php echo $root_id; ?>[data-view="menu"] .sfs-kh{ display:flex; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-sh{ display:none; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-greet{ display:block; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-al{ display:flex; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-camwrap{ display:none; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-statusbar{ display:none; }

#<?php echo $root_id; ?>[data-view="scan"] .sfs-kh{ display:none; }
#<?php echo $root_id; ?>[data-view="scan"] .sfs-sh{ display:flex; }
#<?php echo $root_id; ?>[data-view="scan"] .sfs-greet{ display:none; }
#<?php echo $root_id; ?>[data-view="scan"] .sfs-al{ display:none !important; }
#<?php echo $root_id; ?>[data-view="scan"] .sfs-camwrap{ display:flex; }
#<?php echo $root_id; ?>[data-view="scan"] .sfs-statusbar{ display:flex; max-width:100%; margin:4px 8px; }
#<?php echo $root_id; ?>[data-view="scan"] .sfs-kc{ padding:0; align-items:stretch; overflow:visible; }
#<?php echo $root_id; ?>[data-view="scan"] .sfs-chip{ display:none; }

/* A11y helper */
#<?php echo $root_id; ?> .sr-only{
  position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap;
}

/* Immersive veil */
.sfs-kiosk-veil{ position:fixed; inset:0; background:#f0f2f5; z-index:2147483000; overflow:auto; display:flex; flex-direction:column; }
html.sfs-kiosk-immersive, body.sfs-kiosk-immersive{ overflow:hidden; }
body.sfs-kiosk-immersive .site-header,
body.sfs-kiosk-immersive header:not(.sfs-kh):not(.sfs-sh),
body.sfs-kiosk-immersive .site-footer,
body.sfs-kiosk-immersive footer,
body.sfs-kiosk-immersive .entry-header,
body.sfs-kiosk-immersive .entry-footer,
body.sfs-kiosk-immersive .sidebar,
body.sfs-kiosk-immersive #secondary,
body.sfs-kiosk-immersive .page-title,
body.sfs-kiosk-immersive .elementor-location-header,
body.sfs-kiosk-immersive .elementor-location-footer{ display:none !important; }
body.sfs-kiosk-immersive #wpadminbar{ display:none !important; }

/* ── MOBILE (< 640px) ── */
@media (max-width:640px){
  #<?php echo $root_id; ?> .sfs-kh{
    flex-direction:column; align-items:flex-start; padding:14px 18px; gap:2px;
  }
  #<?php echo $root_id; ?> .sfs-kh-clock{ font-size:32px; }
  #<?php echo $root_id; ?> .sfs-kh-meta{ text-align:left; font-size:12px; opacity:0.85; flex-direction:row; gap:6px; }
  #<?php echo $root_id; ?> .sfs-kc{ padding:20px 16px; }
  #<?php echo $root_id; ?> .sfs-greet{ font-size:24px; }
  /* Camera square on mobile — fits viewport nicely */
  #<?php echo $root_id; ?> .sfs-scan-row{ flex-direction:column; flex:0 0 auto; }
  #<?php echo $root_id; ?> .sfs-cam-card{ flex:0 0 auto; }
  #<?php echo $root_id; ?> .sfs-cam-body{
    border-radius:var(--sfs-radius);
    aspect-ratio:1/1; width:100%; max-width:min(100vw - 16px, 400px); margin:0 auto;
    flex:none;
  }
  #<?php echo $root_id; ?> .sfs-cam-feed video{
    object-fit:cover; width:100%; height:100%;
  }
  /* Hide inline scan log on mobile — replaced by slide-up modal */
  #<?php echo $root_id; ?> .sfs-scan-log{ display:none !important; }
  /* Show tips panel on mobile scan view to fill empty space below camera */
  #<?php echo $root_id; ?>[data-view="scan"] .sfs-scan-tips{
    display:flex; flex-direction:column; gap:10px;
    padding:16px 20px; margin:0 8px;
    background:#f8fafc; border-radius:12px; border:1px solid var(--sfs-border);
  }
  #<?php echo $root_id; ?> .sfs-scan-tips-row{
    display:flex; align-items:center; gap:10px;
    font-size:13px; color:var(--sfs-text-muted);
  }
  #<?php echo $root_id; ?> .sfs-scan-tips-icon{
    font-size:16px; opacity:0.6; flex-shrink:0; width:20px; text-align:center;
  }
  #<?php echo $root_id; ?> .sfs-camwrap{ padding:16px 8px 8px; gap:16px; }
  #<?php echo $root_id; ?> .sfs-sh .sfs-sh-sub{ display:none; }
  #<?php echo $root_id; ?> .sfs-sh-stop{ padding:7px 10px; }
  /* Hide status bar on mobile scan — saves vertical space */
  #<?php echo $root_id; ?>[data-view="scan"] .sfs-statusbar{ display:none; }

  /* ── Scan info bar (system name + time + date) under scan header on mobile ── */
  #<?php echo $root_id; ?> .sfs-scan-info{
    display:flex; align-items:center; justify-content:space-between;
    background:var(--sfs-teal); color:rgba(255,255,255,0.9);
    padding:6px 16px; font-size:12px; border-top:1px solid rgba(255,255,255,0.1);
  }
  #<?php echo $root_id; ?> .sfs-scan-info-brand{ font-weight:700; font-size:13px; }
  #<?php echo $root_id; ?> .sfs-scan-info-time{ font-weight:700; font-size:16px; }
  #<?php echo $root_id; ?> .sfs-scan-info-date{ font-size:11px; opacity:0.8; }
  #<?php echo $root_id; ?>[data-view="menu"] .sfs-scan-info{ display:none; }

  /* ── Slide-up modal for recent scans on mobile ── */
  #<?php echo $root_id; ?> .sfs-log-modal-trigger{
    display:flex; align-items:center; gap:8px;
    position:fixed; bottom:0; left:0; right:0;
    background:var(--sfs-white); color:var(--sfs-text);
    padding:12px 16px; padding-bottom:calc(12px + env(safe-area-inset-bottom, 0px));
    border-top:1px solid var(--sfs-border);
    box-shadow:0 -4px 12px rgba(0,0,0,0.08);
    z-index:9990; cursor:pointer;
    font-size:14px; font-weight:600;
  }
  #<?php echo $root_id; ?> .sfs-log-modal-trigger .sfs-log-modal-count{
    background:var(--sfs-teal); color:#fff; border-radius:999px;
    min-width:24px; height:24px; display:flex; align-items:center; justify-content:center;
    font-size:12px; font-weight:700; padding:0 6px;
  }
  #<?php echo $root_id; ?> .sfs-log-modal-trigger .sfs-log-modal-last{
    flex:1; min-width:0; display:flex; align-items:center; gap:6px;
    font-size:13px; font-weight:500; color:var(--sfs-text-muted);
  }
  #<?php echo $root_id; ?> .sfs-log-modal-trigger .sfs-log-modal-last .sfs-log-dot{
    width:6px; height:6px; border-radius:50%; flex-shrink:0;
  }
  #<?php echo $root_id; ?> .sfs-log-modal-trigger .sfs-log-modal-arrow{
    font-size:18px; color:var(--sfs-text-muted); margin-left:auto;
  }
  #<?php echo $root_id; ?>[data-view="menu"] .sfs-log-modal-trigger{ display:none; }

  /* Slide-up modal panel */
  #<?php echo $root_id; ?> .sfs-log-modal{
    position:fixed; bottom:0; left:0; right:0;
    background:var(--sfs-white); border-radius:16px 16px 0 0;
    box-shadow:0 -8px 32px rgba(0,0,0,0.15);
    z-index:9995; transform:translateY(100%);
    transition:transform 0.3s ease;
    max-height:60vh; display:flex; flex-direction:column;
    padding-bottom:env(safe-area-inset-bottom, 0px);
  }
  #<?php echo $root_id; ?> .sfs-log-modal.open{ transform:translateY(0); }
  #<?php echo $root_id; ?> .sfs-log-modal-handle{
    width:36px; height:4px; border-radius:2px;
    background:#d1d5db; margin:10px auto 6px; flex-shrink:0;
  }
  #<?php echo $root_id; ?> .sfs-log-modal .sfs-log-header{
    display:flex; align-items:center; justify-content:space-between;
    padding:8px 16px 10px; border-bottom:1px solid var(--sfs-border);
  }
  #<?php echo $root_id; ?> .sfs-log-modal .sfs-log-title{
    font-size:15px; font-weight:700; margin:0; color:var(--sfs-text);
  }
  #<?php echo $root_id; ?> .sfs-log-modal .sfs-log-live{
    font-size:10px; font-weight:600; text-transform:uppercase;
    color:var(--sfs-green); letter-spacing:0.04em;
  }
  #<?php echo $root_id; ?> .sfs-log-modal .sfs-log-list{
    list-style:none; margin:0; padding:0; flex:1; overflow-y:auto;
  }
  #<?php echo $root_id; ?> .sfs-log-modal .sfs-log-list li{
    display:flex; align-items:center; gap:10px;
    padding:10px 16px; border-bottom:1px solid var(--sfs-border); font-size:13px;
  }
  #<?php echo $root_id; ?>[data-view="menu"] .sfs-log-modal{ display:none; }

  /* Backdrop */
  #<?php echo $root_id; ?> .sfs-log-modal-backdrop{
    position:fixed; inset:0; background:rgba(0,0,0,0.3);
    z-index:9994; opacity:0; pointer-events:none; transition:opacity 0.3s;
  }
  #<?php echo $root_id; ?> .sfs-log-modal-backdrop.show{ opacity:1; pointer-events:auto; }
  #<?php echo $root_id; ?>[data-view="menu"] .sfs-log-modal-backdrop{ display:none; }
}

/* Desktop/tablet: hide mobile-only elements */
@media (min-width:641px){
  #<?php echo $root_id; ?> .sfs-scan-info{ display:none; }
  #<?php echo $root_id; ?> .sfs-log-modal-trigger{ display:none !important; }
  #<?php echo $root_id; ?> .sfs-log-modal{ display:none !important; }
  #<?php echo $root_id; ?> .sfs-log-modal-backdrop{ display:none !important; }
}

/* ── Wrong-punch error notice ── */
#<?php echo $root_id; ?> .sfs-punch-error-notice{
  position:fixed; top:50%; left:50%; transform:translate(-50%,-50%) scale(0.9);
  background:var(--sfs-red); color:#fff; border-radius:16px;
  padding:24px 32px; text-align:center; z-index:9999;
  opacity:0; pointer-events:none; transition:all 0.3s ease;
  box-shadow:0 20px 60px rgba(0,0,0,0.3);
  max-width:90vw; min-width:260px;
}
#<?php echo $root_id; ?> .sfs-punch-error-notice.show{
  opacity:1; transform:translate(-50%,-50%) scale(1); pointer-events:auto;
}
#<?php echo $root_id; ?> .sfs-punch-error-notice .sfs-error-icon{
  width:48px; height:48px; border-radius:50%;
  border:3px solid rgba(255,255,255,0.5);
  display:flex; align-items:center; justify-content:center;
  font-size:24px; font-weight:700; margin:0 auto 12px;
}
#<?php echo $root_id; ?> .sfs-punch-error-notice .sfs-error-msg{
  font-size:16px; font-weight:700; margin-bottom:4px;
}
#<?php echo $root_id; ?> .sfs-punch-error-notice .sfs-error-detail{
  font-size:13px; opacity:0.85;
}

/* ── Scan log dot & initials colors by action (matches self-web .sfs-att-punch-badge) ── */
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-dot.action-in{ background:var(--sfs-green); }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-dot.action-out{ background:var(--sfs-red); }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-dot.action-break_start{ background:var(--sfs-amber); }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-dot.action-break_end{ background:var(--sfs-blue); }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-initials.action-in{ background:#dcfce7; color:#16a34a; }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-initials.action-out{ background:#fee2e2; color:#dc2626; }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-initials.action-break_start{ background:#fef3c7; color:#d97706; }
#<?php echo $root_id; ?> .sfs-log-list .sfs-log-initials.action-break_end{ background:#dbeafe; color:#2563eb; }

/* Quick "halo" flash on successful / queued punch */
#<?php echo $root_id; ?>.sfs-kiosk-flash-ok {
  animation: sfs-kiosk-punch-ok 0.28s ease-out;
}

#<?php echo $root_id; ?> .sfs-flash {
  position: fixed; top: 0; left: 0; right: 0; bottom: 0;
  pointer-events: none; opacity: 0;
  transition: opacity 0.4s ease-out; z-index: 9998;
}
#<?php echo $root_id; ?> .sfs-flash.show { opacity: 1; }
#<?php echo $root_id; ?> .sfs-flash--in { background: rgba(34, 197, 94, 0.35); }
#<?php echo $root_id; ?> .sfs-flash--out { background: rgba(239, 68, 68, 0.35); }
#<?php echo $root_id; ?> .sfs-flash--break_start { background: rgba(245, 158, 11, 0.35); }
#<?php echo $root_id; ?> .sfs-flash--break_end { background: rgba(59, 130, 246, 0.35); }

@keyframes sfs-kiosk-punch-ok {
  0%   { box-shadow: 0 0 0 0 rgba(34,197,94,0.85); }
  100% { box-shadow: 0 0 0 32px rgba(34,197,94,0); }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js" defer></script>
<?php
// Make sure WP exposes a nonce helper on the front-end
wp_enqueue_script('wp-api');
?>
<script>
  // A guaranteed nonce you can use in console/tests and as a fallback in code
  window.SFS_ATT_NONCE = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
  window.SFS_ATT_I18N = {
    clock_in:      <?php echo wp_json_encode( __( 'Clock In', 'sfs-hr' ) ); ?>,
    clock_out:     <?php echo wp_json_encode( __( 'Clock Out', 'sfs-hr' ) ); ?>,
    break_start:   <?php echo wp_json_encode( __( 'Break Start', 'sfs-hr' ) ); ?>,
    break_end:     <?php echo wp_json_encode( __( 'Break End', 'sfs-hr' ) ); ?>,
    ready:         <?php echo wp_json_encode( __( 'Ready', 'sfs-hr' ) ); ?>,
    scanning:      <?php echo wp_json_encode( __( 'Scanning', 'sfs-hr' ) ); ?>,
    action:        <?php echo wp_json_encode( __( 'action', 'sfs-hr' ) ); ?>,
    selfie_required: <?php echo wp_json_encode( __( 'selfie required', 'sfs-hr' ) ); ?>,
    good_morning:  <?php echo wp_json_encode( __( 'Good morning!', 'sfs-hr' ) ); ?>,
    good_afternoon: <?php echo wp_json_encode( __( 'Good afternoon!', 'sfs-hr' ) ); ?>,
    good_evening:  <?php echo wp_json_encode( __( 'Good evening!', 'sfs-hr' ) ); ?>,
    no_pending_scan: <?php echo wp_json_encode( __( 'No pending scan. Scan QR again.', 'sfs-hr' ) ); ?>,
    no_camera_frame: <?php echo wp_json_encode( __( 'No camera frame yet. Keep face in frame and try again.', 'sfs-hr' ) ); ?>,
    attempting_punch: <?php echo wp_json_encode( __( 'Attempting punch…', 'sfs-hr' ) ); ?>,
    done_label:    <?php echo wp_json_encode( __( 'Done', 'sfs-hr' ) ); ?>,
    next_label:    <?php echo wp_json_encode( __( 'Next', 'sfs-hr' ) ); ?>,
    exiting:       <?php echo wp_json_encode( __( 'Exiting…', 'sfs-hr' ) ); ?>,
    ready_choose_action: <?php echo wp_json_encode( __( 'Ready — choose an action', 'sfs-hr' ) ); ?>,
    idle_timeout_menu: <?php echo wp_json_encode( __( 'Idle timeout — returning to menu', 'sfs-hr' ) ); ?>,
    ready_pick_action: <?php echo wp_json_encode( __( 'Ready — pick an action', 'sfs-hr' ) ); ?>,
    invalid_qr:    <?php echo wp_json_encode( __( 'Invalid QR', 'sfs-hr' ) ); ?>,
    network_error: <?php echo wp_json_encode( __( 'Network error', 'sfs-hr' ) ); ?>,
    scan_failed_invalid_reply: <?php echo wp_json_encode( __( 'Scan failed: invalid server reply', 'sfs-hr' ) ); ?>,
    scan_failed_no_token: <?php echo wp_json_encode( __( 'Scan failed: no token', 'sfs-hr' ) ); ?>,
    keep_face_capture_selfie: <?php echo wp_json_encode( __( 'Keep face in frame and press "Capture Selfie".', 'sfs-hr' ) ); ?>,
    no_shift_contact_hr: <?php echo wp_json_encode( __( 'No shift configured. Contact your Manager or HR.', 'sfs-hr' ) ); ?>,
    invalid_action_try_different: <?php echo wp_json_encode( __( 'Invalid action now. Try a different punch type.', 'sfs-hr' ) ); ?>,
    punch_failed_prefix: <?php echo wp_json_encode( __( 'Punch failed:', 'sfs-hr' ) ); ?>,
    unknown_error: <?php echo wp_json_encode( __( 'Unknown error', 'sfs-hr' ) ); ?>,
    no_camera_found: <?php echo wp_json_encode( __( 'No camera found on this device.', 'sfs-hr' ) ); ?>,
    no_camera_api: <?php echo wp_json_encode( __( 'No camera API available.', 'sfs-hr' ) ); ?>,
    loading_qr_engine: <?php echo wp_json_encode( __( 'Loading QR engine…', 'sfs-hr' ) ); ?>,
    qr_engine_not_loaded: <?php echo wp_json_encode( __( 'QR engine not loaded. Please wait and press Start again.', 'sfs-hr' ) ); ?>,
    capture_selfie_first: <?php echo wp_json_encode( __( 'Please capture a selfie first.', 'sfs-hr' ) ); ?>,
    employee_on_break: <?php echo wp_json_encode( __( 'Employee is on break. End the break before clocking out.', 'sfs-hr' ) ); ?>,
    employee_not_clocked_in: <?php echo wp_json_encode( __( 'Employee is not clocked in.', 'sfs-hr' ) ); ?>,
    already_clocked_in: <?php echo wp_json_encode( __( 'Already clocked in.', 'sfs-hr' ) ); ?>,
    break_only_while_clocked_in: <?php echo wp_json_encode( __( 'Break can start only while clocked in.', 'sfs-hr' ) ); ?>,
    no_active_break_to_end: <?php echo wp_json_encode( __( 'No active break to end.', 'sfs-hr' ) ); ?>,
    invalid_action: <?php echo wp_json_encode( __( 'Invalid action.', 'sfs-hr' ) ); ?>,
    checking_location: <?php echo wp_json_encode( __( 'Checking location…', 'sfs-hr' ) ); ?>,
    capturing_photo: <?php echo wp_json_encode( __( 'Capturing photo…', 'sfs-hr' ) ); ?>,
    recording_punch: <?php echo wp_json_encode( __( 'Recording punch…', 'sfs-hr' ) ); ?>,
    uploading_recording: <?php echo wp_json_encode( __( 'Uploading & recording…', 'sfs-hr' ) ); ?>,
    punch_colon:   <?php echo wp_json_encode( __( 'Punch:', 'sfs-hr' ) ); ?>,
    error_prefix:  <?php echo wp_json_encode( __( 'Error:', 'sfs-hr' ) ); ?>,
    working_ellipsis: <?php echo wp_json_encode( __( 'Working…', 'sfs-hr' ) ); ?>,
    camera_error:  <?php echo wp_json_encode( __( 'Camera error:', 'sfs-hr' ) ); ?>
  };
</script>

    <script>
    (function(){
        const t = window.SFS_ATT_I18N || {};
 // Safe debug logger for kiosk – won't break if debug is off
        const dbg = (...args) => {
            if (window.SFS_ATT_DEBUG) {
                console.log('[SFS-ATT]', ...args);
            }
        };
        
async function attemptPunch(type, scanToken, selfieBlob, geox) {
  const fd = new FormData();
  fd.append('punch_type', type);
  fd.append('lane_action', type);
  fd.append('source', 'kiosk');
  fd.append('device', String(DEVICE_ID));
  fd.append('employee_scan_token', scanToken);

  if (geox) {
    const lat = (typeof geox.geo_lat === 'number') ? geox.geo_lat : geox.lat;
    const lng = (typeof geox.geo_lng === 'number') ? geox.geo_lng : geox.lng;
    const acc = (typeof geox.geo_accuracy_m === 'number') ? geox.geo_accuracy_m : geox.acc;
    
    if (typeof lat === 'number' && typeof lng === 'number') {
      fd.append('geo_lat', lat);
      fd.append('geo_lng', lng);
      if (typeof acc === 'number') fd.append('geo_accuracy_m', acc);
    }
  }
  if (selfieBlob) {
    // Use unique filename with timestamp to prevent overwrites across employees
    const timestamp = Date.now();
    fd.append('selfie', selfieBlob, `selfie-${timestamp}.jpg`);
  }

  let res, text = '', json = null;
  try {
    res  = await fetch(punchUrl, {
      method: 'POST',
      headers: { 'X-WP-Nonce': nonce },
      credentials: 'same-origin',
      body: fd
    });
    text = await res.text();
    try { json = JSON.parse(text); } catch(_) {}
  } catch (e) {
    dbg('attemptPunch network error', e && (e.message || e));

    // Offline queueing: store punch locally with offline_origin fields for later sync
    if (OFFLINE_ENABLED && window.sfsHrPwa && window.sfsHrPwa.db) {
      try {
        const empId = scannedEmpId || parseInt(String(scanToken || '').split('_')[0], 10) || 0;
        await window.sfsHrPwa.db.storePunch({
          url: punchUrl,
          nonce: nonce,
          offline_origin: true,
          offline_employee_id: empId,
          client_punch_time: new Date().toISOString(),
          data: { punch_type: type, source: 'kiosk', device: String(DEVICE_ID) }
        });
        dbg('punch queued offline (origin) for emp', empId);
        return { ok:true, status:0, data:{ message: t.offline_queued || 'Offline — will sync when connection is restored', label: t.offline_queued_short || 'Queued offline' }, code:'offline_queued', raw:'' };
      } catch(qe) {
        dbg('offline queue failed', qe);
      }
    }

    return { ok:false, status:0, data:{ message: t.network_error || 'Network error' }, code:null, raw:text };
  }

  // Safe debug head
  try {
    dbg('attemptPunch status=' + res.status + ' body.head=' + String(text).slice(0,180));
    if (!res.ok) dbg('attemptPunch JSON=', json || text);
  } catch(_) {}

  const code = (json && (json.code || json?.data?.code)) || null;
  return { ok: res.ok, status: res.status, data: json || {}, code, raw: text };
}







async function autoPunchWithFallback(scanToken, selfieBlob, geox) {
  let last = null;
  for (const type of PUNCH_ORDER) {
    setStat((t.punch_colon||'Punch:') + ' ' + type + '…', 'busy');
    const r = await attemptPunch(type, scanToken, selfieBlob, geox);
    if (r.ok) return r;
    last = r;
    if (r.status !== 409) return r;
  }
  return last || { ok:false, status:409, data:{ message:'No valid action available.' } };
}


      // Device flags
      // NEW (server is source of truth via /status)
    const DEVICE_ID = <?php echo (int)$device_id; ?>;
    const OFFLINE_ENABLED = <?php echo !empty($device['kiosk_offline']) ? 'true' : 'false'; ?>;
    const BREAK_ENABLED = <?php echo !empty($device['break_enabled']) ? 'true' : 'false'; ?>;

// Time-based action suggestions (±30 minutes window)
const SUGGEST_TIMES = {
  in: <?php echo $device && !empty($device['suggest_in_time']) ? wp_json_encode($device['suggest_in_time']) : 'null'; ?>,
  break_start: <?php echo $device && !empty($device['suggest_break_start_time']) ? wp_json_encode($device['suggest_break_start_time']) : 'null'; ?>,
  break_end: <?php echo $device && !empty($device['suggest_break_end_time']) ? wp_json_encode($device['suggest_break_end_time']) : 'null'; ?>,
  out: <?php echo $device && !empty($device['suggest_out_time']) ? wp_json_encode($device['suggest_out_time']) : 'null'; ?>
};

      // Elements
      const ROOT      = document.getElementById('<?php echo esc_js($root_id); ?>');
      const statusBox = document.getElementById('sfs-kiosk-status-<?php echo $inst; ?>');
      const video     = document.getElementById('sfs-kiosk-qr-video-<?php echo $inst; ?>');
     
      const capture   = document.getElementById('sfs-kiosk-capture-<?php echo $inst; ?>');
      const camwrap   = document.getElementById('sfs-kiosk-camwrap-<?php echo $inst; ?>');
      const clockEl  = document.getElementById('sfs-kiosk-clock-<?php echo $inst; ?>');
      const dateEl  = document.getElementById('sfs-kiosk-date-<?php echo $inst; ?>');
      const empEl    = document.getElementById('sfs-kiosk-emp-<?php echo $inst; ?>');
      const flashEl  = document.getElementById('sfs-kiosk-flash-<?php echo $inst; ?>');
      const camBadge = document.getElementById('sfs-kiosk-cam-badge-<?php echo $inst; ?>');

      // QR elems
      const qrWrap  = document.getElementById('sfs-kiosk-camwrap-<?php echo $inst; ?>');
      const qrVid   = document.getElementById('sfs-kiosk-qr-video-<?php echo $inst; ?>');
      
      // STOP button is now sfs-kiosk-stop (in scan header), handled via stopBtn below
      const qrStop = null; // removed: old hidden "Stop Camera" button no longer exists






const qrStat  = document.getElementById('sfs-kiosk-qr-status-<?php echo $inst; ?>');
      
      let requiresSelfie = false; // <- will be set after status()
      let allowed = {};
      let state   = 'idle';
      let stream = null;
      let lastBlob = null;
      let scannedEmpId = null;
      let employeeScanToken = null;
      let pendingPunch = null;
      let manualSelfieMode = false;
      const PUNCH_ORDER = ['in','out','break_start','break_end'];

      let lastQrValue = '';
      let lastQrTs = 0;
      let qrRunning = false;
      let selfiePreviewLoop = null;
      const QR_COOLDOWN_MS = 1500; // debounce between detections
      const punchUrl  = '<?php echo esc_js($punch_url); ?>';
      const statusUrl = '<?php echo esc_js($status_url); ?>' + '?device=' + String(DEVICE_ID);
      const nonce     = '<?php echo esc_js($nonce); ?>';
const BACKOFF_MS_409 = 2000;      // invalid transition -> pause 2s
const BACKOFF_MS_429 = 3000;      // server cooldown -> pause 3s
const BACKOFF_MS_SLF = 1800;      // selfie not ready -> pause ~1.8s
const BACKOFF_MS_OK  = 1200;   // after success
const BACKOFF_MS_ERR = 1500;   // generic non-429/non-409 error

// Debug helpers removed in Simple HR production build.



if (capture) {
  capture.addEventListener('click', async () => {
    try {
      if (!pendingPunch || !pendingPunch.scanToken) {
        setStat(t.no_pending_scan||'No pending scan. Scan QR again.', 'error');
        return;
      }
      const blob = await captureSelfieFromQrVideo();
      if (!blob) {
        setStat(t.no_camera_frame||'No camera frame yet. Keep face in frame and try again.', 'error');
        return;
      }
      setStat(t.attempting_punch||'Attempting punch…', 'busy');
      const r = await attemptPunch(currentAction, pendingPunch.scanToken, blob, pendingPunch.geox);
      if (r.ok) {
  playActionTone(currentAction);
  flashPunchSuccess('ok');    // halo flash here too
  flash(currentAction, '');   // full-screen color flash

  if (!sessionStartTs) startSession();
  addScanLog('', currentAction, true);

  setStat((r.data?.label || t.done_label||'Done') + ' — ' + (t.next_label||'Next'), 'ok');
  touchActivity();
  pendingPunch = null;
  manualSelfieMode = false;
  if (capture) capture.style.display = 'none';
  await refresh();
  setTimeout(() => { if (uiMode !== 'error') setStat(t.scanning||'Scanning…', 'scanning'); }, 400);
} else {
        playErrorTone();
        setStat(r.data?.message || (t.punch_failed_prefix||'Punch failed:') + ` (HTTP ${r.status})`, 'error');
      }
    } catch (e) {
      playErrorTone();
      setStat((t.error_prefix||'Error:') + ' ' + (e.message || e), 'error');
    }
  });
}


// ----- View state + idle timer -----
let view = 'menu';                         // 'menu' | 'scan'
let idleTimer = null;
const IDLE_MS = 5 * 60 * 1000;            // 5 minutes

function returnToMenu() { setMode('menu'); }

function clearIdle() {
  if (idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
}

function touchActivity() {
  clearIdle();
  if (view === 'scan') {
    idleTimer = setTimeout(() => {
      setStat(t.idle_timeout_menu||'Idle timeout — returning to menu', 'busy');
      returnToMenu();
    }, IDLE_MS);
  }
}

function exitToMenu(){
  stopQr();          // stops tracks + preview
  setMode('menu');   // shows big buttons again
  setStat(((window.SFS_ATT_I18N||{}).ready||'Ready') + ' — ' + ((window.SFS_ATT_I18N||{}).action||'action') + ': ' + labelFor(currentAction) + (requiresSelfie?' — ' + ((window.SFS_ATT_I18N||{}).selfie_required||'selfie required'):''), 'idle');
}

// iOS inline playback hardening
try {
  qrVid.setAttribute('playsinline', '');
  qrVid.setAttribute('webkit-playsinline', '');
  qrVid.muted = true;
} catch (_) {}

// Debug flag for console logging (set to true to enable debug logs)
const DBG = false;



// Status bar elements (must exist before setStat)
const sBar  = document.querySelector('#<?php echo $root_id; ?> .sfs-statusbar');
const dot   = document.getElementById('sfs-status-dot-<?php echo $inst; ?>');
const sText = document.getElementById('sfs-status-text-<?php echo $inst; ?>');

// --- engine flags shared at top-level ---
let qrStream = null, qrLoop = null;
let useBarcodeDetector = ('BarcodeDetector' in window);
useBarcodeDetector = false; // force jsQR path for now

let detector = null;
let lastUIBeat = 0;       // heartbeat for "Scanning…"
let uiMode = 'idle';      // 'idle' | 'scanning' | 'busy' | 'ok' | 'error'

function setStat(text, mode) {
  if (qrStat) qrStat.textContent = text;
  if (sText)  sText.textContent  = text;

  if (mode && sBar) sBar.dataset.mode = mode;

  if (dot && mode) {
    let cls = 'sfs-dot--idle';
    if (ACTIONS.includes(mode)) cls = 'sfs-dot--' + mode;        // action preview
    else if (mode === 'ok')     cls = 'sfs-dot--in';
    else if (mode === 'busy')   cls = 'sfs-dot--break_start';
    else if (mode === 'error')  cls = 'sfs-dot--out';
    dot.className = 'sfs-dot ' + cls;
  }

  if (mode) uiMode = mode;
  lastUIBeat = Date.now();
}

// === VIEW STATE ===
// 'menu'  -> big buttons, camera hidden
// 'scan'  -> camera shown, small toolbar/status shown
function setMode(mode){
  const root = ROOT;
  if (!root) return;
  const m = (mode === 'scan') ? 'scan' : 'menu';
  root.dataset.view = m;

  if (m === 'scan') {
    kickScanner();
    touchActivity();
    setStat(t.scanning||'Scanning…', 'scanning');
  } else {
    stopQr();
    clearIdle();
    setStat(t.ready_choose_action||'Ready — choose an action', 'idle');
  }
}



document.addEventListener('keydown', (e)=>{
  if (e.key === 'Escape' && ROOT.dataset.view === 'scan') {
    setMode('menu');   // stop camera and show the lane again
  }
});

// --- Batch Action (lane) mode ---
const ACTIONS = BREAK_ENABLED ? ['in','out','break_start','break_end'] : ['in','out'];
let currentAction = 'in';
const laneRoot  = document.getElementById('sfs-kiosk-lane-<?php echo $inst; ?>');
const laneChip  = document.getElementById('sfs-kiosk-lane-chip-<?php echo $inst; ?>');



function labelFor(a){
  const t = window.SFS_ATT_I18N || {};
  switch(a){
    case 'in': return t.clock_in || 'Clock In';
    case 'out': return t.clock_out || 'Clock Out';
    case 'break_start': return t.break_start || 'Break Start';
    case 'break_end': return t.break_end || 'Break End';
    default: return a;
  }
}

function chipClassFor(a){
  return 'sfs-chip sfs-chip--' + (ACTIONS.includes(a) ? a : 'idle');
}



function setAction(a){
  if (!ACTIONS.includes(a)) return;
  currentAction = a;

  if (laneChip) {
    laneChip.textContent = labelFor(currentAction);
    laneChip.className   = chipClassFor(currentAction);
  }

  // Update scan header label
  const scanLabel = document.getElementById('sfs-kiosk-lane-label-<?php echo $inst; ?>');
  if (scanLabel) scanLabel.textContent = labelFor(currentAction);

  setStat(
    ((window.SFS_ATT_I18N||{}).ready||'Ready') + ' \u2014 ' + ((window.SFS_ATT_I18N||{}).action||'action') + ': ' + labelFor(currentAction) + (requiresSelfie ? ' \u2014 ' + ((window.SFS_ATT_I18N||{}).selfie_required||'selfie required') : ''),
    currentAction
  );
}

// Wire the lane buttons exactly once
if (laneRoot) {
  laneRoot.querySelectorAll('button[data-action]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (qrRunning) return; // ignore while already scanning

      // 1) set current action (updates chip + status text)
      setAction(btn.getAttribute('data-action'));

      // 2) start session timer if not already active
      if (!sessionStartTs) startSession();

      // 3) flip view to scan
      ROOT.dataset.view = 'scan';

      // 4) force camera wrapper visible (in case refresh()/stopQr() hid it)
      if (qrWrap)  qrWrap.style.display  = '';
      if (camwrap) camwrap.style.display = 'grid';

      // 5) actually start the scanner
      kickScanner();
    });
  });
}





// Arrow-key navigation across actions
laneRoot && laneRoot.addEventListener('keydown', (e)=>{
  if (!['ArrowLeft','ArrowRight'].includes(e.key)) return;
  e.preventDefault();
  const idx = ACTIONS.indexOf(currentAction);
  const next = e.key === 'ArrowRight'
    ? ACTIONS[(idx+1) % ACTIONS.length]
    : ACTIONS[(idx-1 + ACTIONS.length) % ACTIONS.length];
  setAction(next);
});

// Time-based action highlighting
function updateTimeSuggestions() {
  if (!laneRoot || !SUGGEST_TIMES) return;

  const now = new Date();
  const currentMinutes = now.getHours() * 60 + now.getMinutes();

  laneRoot.querySelectorAll('button[data-action]').forEach(btn => {
    const action = btn.getAttribute('data-action');
    const suggestTime = SUGGEST_TIMES[action];

    if (!suggestTime) {
      btn.classList.remove('button-suggested');
      return;
    }

    // Parse suggest time (HH:MM:SS format)
    const [hours, minutes] = suggestTime.split(':').map(Number);
    const suggestMinutes = hours * 60 + minutes;

    // Check if within ±30 minutes window
    const diff = Math.abs(currentMinutes - suggestMinutes);
    const withinWindow = diff <= 30 || diff >= (24 * 60 - 30); // Handle midnight wrap

    if (withinWindow) {
      btn.classList.add('button-suggested');
    } else {
      btn.classList.remove('button-suggested');
    }
  });
}

// Update suggestions on load and every minute
updateTimeSuggestions();
setInterval(updateTimeSuggestions, 60000);

// Initial lane
setAction('in');

// Prevent re-entrancy while we mint token + punch
let inflight = false;



// Short beep on success (no <audio> tag needed)
async function playActionTone(kind){
  const freq = { in: 920, out: 420, break_start: 680, break_end: 560 }[kind] || 750;
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    // Resume context if suspended (browser autoplay policy)
    if (ctx.state === 'suspended') {
      await ctx.resume();
    }
    const o = ctx.createOscillator(); const g = ctx.createGain();
    o.type = 'sine'; o.frequency.value = freq;
    o.connect(g); g.connect(ctx.destination);
    g.gain.value = 0.25;
    o.start();
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.22);
    setTimeout(()=>{ o.stop(); ctx.close(); }, 260);
  } catch(e) {
    // Log audio errors to console for debugging
    dbg('Audio tone error:', e);
  }
}
function flash(kind, empName){
  if (!flashEl) return;
  flashEl.className = 'sfs-flash sfs-flash--' + (kind || 'in');

  // Populate name/action/time inside flash
  if (flashNameEl)   flashNameEl.textContent   = empName || '';
  if (flashActionEl) flashActionEl.textContent = labelFor(kind);
  if (flashTimeEl)   flashTimeEl.textContent   = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});

  // trigger reflow to restart animation
  void flashEl.offsetWidth;
  flashEl.classList.add('show');
  setTimeout(()=> flashEl.classList.remove('show'), 1500);
}


function playErrorTone() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const o = ctx.createOscillator(); const g = ctx.createGain();
    o.type = 'square'; o.frequency.value = 220;
    o.connect(g); g.connect(ctx.destination);
    o.start();
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.25);
    setTimeout(()=>{ o.stop(); ctx.close(); }, 280);
  } catch(_) {}
}



            async function getGeo(punchType){
        const root = ROOT; // already defined at top for this kiosk instance

        if (!window.sfsGeo || !root) {
          // Fallback if helper not loaded
          return new Promise(resolve=>{
            if(!navigator.geolocation){ return resolve(null); }
            navigator.geolocation.getCurrentPosition(
              pos=>resolve({
                lat: pos.coords.latitude,
                lng: pos.coords.longitude,
                acc: Math.round(pos.coords.accuracy || 0)
              }),
              ()=>resolve(null),
              {enableHighAccuracy:true,timeout:8000,maximumAge:0}
            );
          });
        }

        const result = await new Promise((resolve, reject)=>{
          window.sfsGeo.requireInside(
            root,
            function onAllow(coords){
              if (!coords) { resolve(null); return; }
              resolve({
                lat: coords.latitude,
                lng: coords.longitude,
                acc: Math.round(coords.distance || 0)
              });
            },
            function onReject(msg, code){
              setStat(msg || (t.location_check_failed||'Location check failed.'), 'error');
              reject(new Error('geo_blocked'));
            },
            punchType
          );
        });

        if (result) return result;

        // Fallback: try lower-accuracy GPS (WiFi/cell) with cached position
        if (!navigator.geolocation) return null;
        return new Promise(resolve=>{
          navigator.geolocation.getCurrentPosition(
            pos=>resolve({
              lat: pos.coords.latitude,
              lng: pos.coords.longitude,
              acc: Math.round(pos.coords.accuracy || 0)
            }),
            ()=>resolve(null),
            {enableHighAccuracy:false, timeout:5000, maximumAge:60000}
          );
        });
      }




async function handleQrFound(raw) {
  dbg('handleQrFound: start', { raw });

  // ← ADD try block here
  try {
    // --- Parse payload: allow full URL OR "emp=...&token=..."
    let emp = null, token = null, url = null;
    const deviceIdSafe = Number(DEVICE_ID) || 0;

    try {
      // Try full URL first
      const u = new URL(String(raw).trim());
      emp   = u.searchParams.get('emp');
      token = u.searchParams.get('token');

      // Enforce same-origin endpoint (ignore host in QR)
      const base = `${window.location.origin}/wp-json/sfs-hr/v1/attendance/scan`;
      url = new URL(base);
      if (emp)   url.searchParams.set('emp',   emp);
      if (token) url.searchParams.set('token', token);
      if (deviceIdSafe) url.searchParams.set('device', String(deviceIdSafe));
    } catch {
      // Fallback: parse as querystring-ish text
      const qs = new URLSearchParams(String(raw).trim().replace(/^[?#]/,''));
      emp   = qs.get('emp');
      token = qs.get('token');
      if (emp && token) {
        const base = `${window.location.origin}/wp-json/sfs-hr/v1/attendance/scan`;
        url = new URL(base);
        url.searchParams.set('emp', emp);
        url.searchParams.set('token', token);
        if (deviceIdSafe) url.searchParams.set('device', String(deviceIdSafe));
      }
    }

    if (!emp || !token || !url) {
      setStat(t.invalid_qr||'Invalid QR', 'error');
      throw new Error('invalid_qr_payload');
    }

    // Track employee ID for offline queueing in attemptPunch()
    scannedEmpId = parseInt(emp, 10) || null;

    // --- Hit /attendance/scan to mint/use a short-lived scan_token
    // If the server is unreachable and offline mode is enabled, fall back
    // to validating the QR against the cached employee roster.
    let resp, text, data;
    let offlineFallback = false;
    let offlineEmpRecord = null;

    try {
      resp = await fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-WP-Nonce': nonce },
      });
      text = await resp.text();
    } catch (e) {
      dbg('scan network error', e && e.message);

      // --- Offline fallback: validate QR against cached roster ---
      if (OFFLINE_ENABLED && window.sfsHrPwa && window.sfsHrPwa.db && window.sfsHrPwa.sha256) {
        try {
          const empId = parseInt(emp, 10);
          offlineEmpRecord = await window.sfsHrPwa.db.getEmployee(empId);
          if (offlineEmpRecord && offlineEmpRecord.token_hash) {
            const scannedHash = await window.sfsHrPwa.sha256(token);
            if (scannedHash === offlineEmpRecord.token_hash) {
              offlineFallback = true;
              dbg('offline fallback: QR verified for employee', empId);
            } else {
              dbg('offline fallback: token hash mismatch');
              setStat(t.invalid_qr||'Invalid QR', 'error');
              lastQrValue = raw; lastQrTs = Date.now();
              throw new Error('offline_token_mismatch');
            }
          } else {
            dbg('offline fallback: employee not in cached roster', empId);
            setStat(t.offline_employee_not_cached||'Offline — employee not recognized. Cache may be stale.', 'error');
            lastQrValue = raw; lastQrTs = Date.now();
            throw new Error('offline_employee_not_found');
          }
        } catch (offErr) {
          if (offErr.message === 'offline_token_mismatch' || offErr.message === 'offline_employee_not_found') throw offErr;
          dbg('offline fallback failed', offErr);
          setStat(t.network_error||'Network error', 'error');
          lastQrValue = raw; lastQrTs = Date.now();
          throw e;
        }
      } else {
        setStat(t.network_error||'Network error', 'error');
        lastQrValue = raw; lastQrTs = Date.now();
        throw e;
      }
    }

    let scanToken = null;
    let empName = '';

    if (offlineFallback) {
      // Offline mode: use cached employee data, no server scan token
      empName = (offlineEmpRecord && offlineEmpRecord.name) || `Employee #${emp}`;
      scanToken = null; // no server token in offline mode

      setStat(`⚡ ${empName} — ${t.offline_mode||'Offline mode'}`, 'ok');
      dbg('offline scan accepted', { emp, name: empName });

      if (empEl) { empEl.textContent = empName; }
    } else {
      // Online mode: parse server response
      // Try parse JSON; WP may return HTML on error
      try { data = JSON.parse(text); } catch {
        dbg('scan parse error; raw=', (text || '').slice(0, 200));
        setStat(t.scan_failed_invalid_reply||'Scan failed: invalid server reply', 'error');
        lastQrValue = raw; lastQrTs = Date.now();
        throw new Error('invalid_json_from_scan');
      }

      // Normalize REST errors
      if (!resp.ok || !data || data.ok === false) {
        const msg  = (data && (data.message || data.msg)) || `HTTP ${resp.status}`;
        const code = (data && (data.code || data.data?.status)) || resp.status;
        setStat((t.scan_failed_invalid_reply||'Scan failed:') + ` ${msg}`, 'error');
        dbg('scan failed', { status: resp.status, code, data });
        lastQrValue = raw; lastQrTs = Date.now();
        throw new Error(`scan_failed:${code}:${msg}`);
      }

      // Expect { ok:true, scan_token: "...", employee_id, device_id, ttl }
      scanToken = data.scan_token;
      if (!scanToken) {
        setStat(t.scan_failed_no_token||'Scan failed: no token', 'error');
        dbg('scan ok but no scan_token', data);
        lastQrValue = raw; lastQrTs = Date.now();
        throw new Error('no_scan_token');
      }

      empName = (data && (data.employee_name || data.name))
        || `Employee #${data.employee_id || emp}`;

      setStat(`✓ ${empName} — ${t.validating_ellipsis||'Validating…'}`, 'ok');
      dbg('scan ok', data);

      if (empEl) { empEl.textContent = empName; }
    }


    // --- Prepare geo + selfie (if required)
    // For kiosks (fixed location), skip browser GPS - use device's configured location
    // For web/mobile, get browser GPS for verification
    let geox = null;

    if (!deviceIdSafe) {
        // Web/mobile source: get GPS from browser
        if (qrStat) qrStat.textContent = '1/3 ' + (t.checking_location||'Checking location…');
        try {
            geox = await getGeo();
            if (qrStat) qrStat.textContent = requiresSelfie ? '2/3 ' + (t.capturing_photo||'Capturing photo…') : '2/2 ' + (t.recording_punch||'Recording punch…');
        } catch(e){
            // geo_blocked → abort this scan and cool down this frame
            lastQrValue = raw;
            lastQrTs    = Date.now() + (BACKOFF_MS_ERR - QR_COOLDOWN_MS);
            return false; // keep scanner running, but no punch
        }
    } else {
        // Kiosk source: skip GPS check (device has fixed configured location)
        // Server will validate against device's geo_lock settings
        if (qrStat) qrStat.textContent = requiresSelfie ? '1/2 ' + (t.capturing_photo||'Capturing photo…') : '1/1 ' + (t.recording_punch||'Recording punch…');
    }

    // 2) Capture selfie frame (if needed)
    const selfieBlob = await captureSelfieFromQrVideo();

    if (requiresSelfie && !selfieBlob) {
      manualSelfieMode = true;
      pendingPunch = { scanToken, geox };
      if (capture) capture.style.display = '';
      setStat(t.keep_face_capture_selfie||'Keep face in frame and press "Capture Selfie".', 'error');
      lastQrValue = raw;
      lastQrTs    = Date.now() + (BACKOFF_MS_SLF - QR_COOLDOWN_MS);
      return false;
    }

    if (qrStat) qrStat.textContent = requiresSelfie ? '3/3 ' + (t.uploading_recording||'Uploading & recording…') : '2/2 ' + (t.recording_punch||'Recording punch…');

    let r;
    if (offlineFallback) {
      // Offline mode: queue punch directly to IndexedDB (no server call)
      if (window.sfsHrPwa && window.sfsHrPwa.db) {
        try {
          await window.sfsHrPwa.db.storePunch({
            url: punchUrl,
            nonce: nonce,
            offline_origin: true,
            offline_employee_id: parseInt(emp, 10),
            client_punch_time: new Date().toISOString(),
            data: {
              punch_type: currentAction,
              source: 'kiosk',
              device: String(DEVICE_ID)
            }
          });
          dbg('offline punch queued for', emp, currentAction);
          r = {
            ok: true, status: 0, code: 'offline_queued',
            data: {
              message: t.offline_queued||'Offline — will sync when connection is restored',
              label: `⚡ ${empName} — ${t.offline_queued_short||'Queued offline'}`
            }
          };
        } catch (qe) {
          dbg('offline queue failed', qe);
          r = { ok: false, status: 0, data: { message: t.offline_queue_error||'Failed to save offline punch' }, code: null, raw: '' };
        }
      } else {
        r = { ok: false, status: 0, data: { message: t.offline_not_supported||'Offline storage not available' }, code: null, raw: '' };
      }
    } else {
      // Online mode: normal server punch
      r = await attemptPunch(currentAction, scanToken, selfieBlob, geox);
    }


    if (r.ok) {
  playActionTone(currentAction);
  flashPunchSuccess('ok');   // <- use halo on the whole kiosk
  flash(currentAction, empName);      // full-screen color flash with name

  // Auto-start session timer on first successful scan
  if (!sessionStartTs) startSession();

  // Log this scan
  addScanLog(empName, currentAction, true);

  setStat((r.data?.label || t.done_label||'Done') + ' — ' + (t.next_label||'Next'), 'ok');

  lastQrValue = raw;
  lastQrTs    = Date.now() + BACKOFF_MS_OK;

  if (!offlineFallback) { await refresh(); }

  setTimeout(() => {
    if (uiMode !== 'error') setStat(t.scanning||'Scanning…', 'scanning');
  }, 400);

  return true;
}
 else {
      const code = (r.data && (r.data.code || r.data?.data?.code)) || '';
      const msg  = r.data?.message || (t.punch_failed_prefix||'Punch failed:') + ` (HTTP ${r.status})`;

      if (r.status === 409) {
        let errMsg = msg;
        let errDetail = empName || '';
        if (code === 'no_shift') {
          errMsg = t.no_shift_contact_hr||'No shift configured. Contact your Manager or HR.';
        } else if (code === 'invalid_transition') {
          errMsg = t.invalid_action_try_different||'Invalid action now. Try a different punch type.';
        } else {
          errMsg = t.no_shift_contact_hr||'No shift, contact your Manager or HR.';
        }
        setStat(errMsg, 'error');

        // Show prominent error notice overlay so operator sees it clearly
        showPunchError(errMsg, errDetail ? errDetail + ' — ' + labelFor(currentAction) : '');

        // Flash red overlay
        flash('out', empName || '');

        // Back off so the same frame doesn't hammer the API
        lastQrValue = raw;
        lastQrTs    = Date.now() + (BACKOFF_MS_409 - QR_COOLDOWN_MS);

        // Keep the camera running; DO NOT throw here.
        return false;
      }

      // Non-409 errors: show error notice too
      setStat(msg, 'error');
      showPunchError(msg, empName ? empName + ' — ' + labelFor(currentAction) : '');
      if (r.status === 429) {
        lastQrValue = raw;
        lastQrTs    = Date.now() + (BACKOFF_MS_429 - QR_COOLDOWN_MS);
      } else {
        lastQrValue = raw;
        lastQrTs    = Date.now() + BACKOFF_MS_ERR;
      }
      throw new Error(msg);
    }

  } catch (punchErr) {
    // ← ENHANCED error handling
    const punchErrMsg = (t.punch_failed_prefix||'Punch failed:') + ` ${punchErr && punchErr.message ? punchErr.message : (t.unknown_error||'Unknown error')}`;
    setStat(punchErrMsg, 'error');
    showPunchError(punchErrMsg, empName || '');
    dbg('punch failed', punchErr);
    
    // Always arm cooldown to prevent infinite loops
    lastQrValue = raw;
    lastQrTs    = Date.now() + BACKOFF_MS_ERR;
    
    // Don't re-throw; let the scanner continue
    return false;
    
  } finally {
    // ← ADD finally block to ALWAYS reset inflight flag
    inflight = false;
  }
}

async function startQr(){
  inflight = false;
  lastQrValue = '';
  lastQrTs = 0;
  stopQr();
  lastUIBeat = 0;
  const tag = requiresSelfie ? ' — ' + ((window.SFS_ATT_I18N||{}).selfie_required||'selfie required') : '';
  setStat(((window.SFS_ATT_I18N||{}).scanning||'Scanning') + ' — ' + ((window.SFS_ATT_I18N||{}).action||'action') + ': ' + labelFor(currentAction) + tag, 'scanning');

  showScannerUI(true);

  // Pre-check that we actually have at least one camera
  try {
    if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
      const devs = await navigator.mediaDevices.enumerateDevices();
      const cams = devs.filter(d => d.kind === 'videoinput');
      if (!cams.length) {
        setStat(t.no_camera_found||'No camera found on this device.', 'error');
        if (camBadge) { camBadge.textContent = t.no_camera||'No Camera'; camBadge.classList.add('off'); }
        return;
      }
    }
  } catch (_) {
    // ignore – fallback to getUserMedia errors
  }

  dbg('startQr: begin, requiresSelfie=', requiresSelfie, 'BarcodeDetector in window?', 'BarcodeDetector' in window);

  if (!('jsQR' in window)) dbg('startQr: jsQR not yet loaded, will poll');

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
    setStat(t.no_camera_api||'No camera API available.', 'error');
    dbg('startQr: no getUserMedia');
    return;
  }

  // Prefer BarcodeDetector if supported; otherwise jsQR
  if (useBarcodeDetector) {
    try {
      detector = new BarcodeDetector({ formats: ['qr_code'] });
      await detector.detect(document.createElement('canvas'));
      dbg('startQr: BarcodeDetector OK');
    } catch {
      useBarcodeDetector = false;
      dbg('startQr: BarcodeDetector unavailable, fallback to jsQR');
    }
  }

  // Lazy-load jsQR if needed
  if (!useBarcodeDetector && typeof window.jsQR !== 'function'){
    setStat(t.loading_qr_engine||'Loading QR engine…', 'busy');
    const t0 = Date.now();
    while (typeof window.jsQR !== 'function' && (Date.now() - t0) < 3000) {
      await new Promise(r => setTimeout(r,100));
    }
    dbg('startQr: jsQR loaded?', typeof window.jsQR === 'function');
    if (typeof window.jsQR !== 'function'){
      setStat(t.qr_engine_not_loaded||'QR engine not loaded. Please wait and press Start again.', 'error');
      if (qrStop)  qrStop.disabled  = true;
      return;
    }
  }

  const constraints = {
    video: {
      facingMode: { ideal: 'environment' },
      width:  { ideal: 1280 },
      height: { ideal: 720 }
    },
    audio: false
  };

  try {
    dbg('startQr: getUserMedia requesting…', constraints.video);
    qrStream = await navigator.mediaDevices.getUserMedia(constraints);
    qrVid.srcObject = qrStream;
    await new Promise(r => qrVid.onloadedmetadata = r);
    dbg('startQr: stream ready', { w: qrVid.videoWidth, h: qrVid.videoHeight });

    try {
      await qrVid.play();
    } catch(e) {
      dbg('startQr: play() err', e && e.message);
    }

    // === IMPORTANT: mark running BEFORE starting selfie preview ===
    qrRunning = true;
    if (camBadge) { camBadge.textContent = t.camera_on||'Camera On'; camBadge.classList.remove('off'); }
    touchActivity();
    if (camwrap) camwrap.style.display = 'grid';

    if (qrStop) qrStop.disabled = false;
    if (qrStat) qrStat.textContent = t.scanning||'Scanning…';

    // Start live selfie preview only if the selfie canvas exists
    if (document.getElementById('sfs-kiosk-canvas-<?php echo $inst; ?>')) {
      startSelfiePreview();
      if (requiresSelfie) dbg('startQr: selfie preview active');
    }

    // --- Offscreen canvas for scanning
    const scanCanvas = document.createElement('canvas');
    const sctx = scanCanvas.getContext('2d', { willReadFrequently: true });

    const ensureCanvasSize = () => {
      const w = qrVid.videoWidth  || 640;
      const h = qrVid.videoHeight || 480;
      if (w === 0 || h === 0) return false;
      if (scanCanvas.width !== w || scanCanvas.height !== h){
        scanCanvas.width = w;
        scanCanvas.height = h;
        dbg('ensureCanvasSize: set', {w,h});
      }
      return true;
    };

    // ====== TICK LOOP ======
    const tick = async () => {
      if (!qrRunning) return;

      // If a request is in-flight, just loop again
      if (inflight) {
        qrLoop = requestAnimationFrame(tick);
        return;
      }

      try {
        if (!ensureCanvasSize()) {
          setStat('Camera not ready…', 'busy');
          dbg('tick: camera not ready (videoWidth/Height=0)');
          qrLoop = requestAnimationFrame(tick);
          return;
        }

        const w = scanCanvas.width, h = scanCanvas.height;
        sctx.drawImage(qrVid, 0, 0, w, h);

        let payload = null;

        // (A) BarcodeDetector path
        if (useBarcodeDetector && detector) {
          try {
            const det = await detector.detect(scanCanvas);
            if (det && det.length) {
              payload = String(det[0].rawValue ?? '');
              if (payload) dbg('tick: detector hit');
            }
          } catch (e) {
            dbg('tick: detector error', e && (e.message || e));
          }
        }

        // (B) jsQR fallback path
        if (!payload && typeof window.jsQR === 'function') {
          try {
            const img  = sctx.getImageData(0, 0, w, h);
            const code = jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
            if (code && code.data) {
              payload = String(code.data);
              dbg('tick: jsQR hit');
            }
          } catch (err) {
            // Non-fatal decode hiccup; keep UI in "Scanning…" and just log
            dbg('tick: jsQR error (non-fatal)', err && (err.message || err));
          }
        }

        if (payload) {
          const now    = Date.now();
          const cooled = (now - lastQrTs) > QR_COOLDOWN_MS;
          const isNew  = payload !== lastQrValue;

          if (isNew || cooled) {
            inflight = true;
            setStat('Reading QR…', 'busy');
            dbg('tick: payload detected', payload.slice(0, 128));

            try {
              const ok = await handleQrFound(payload);
              touchActivity();
              if (ok) {
                // Success → arm cooldown so the same frame doesn’t immediately re-trigger
                lastQrValue = payload;
                lastQrTs    = Date.now();
                setStat('QR OK', 'ok');
              } else {
                // handleQrFound already set a meaningful status and cooldown.
                // DO NOT overwrite it with “QR OK”.
              }
            } catch (err) {
              // Failure → allow immediate retry (light backoff handled inside handleQrFound)
              setStat('Scan failed', 'error');
              dbg('tick: handleQrFound error', err && (err.message || err));
              lastQrValue = null;
              lastQrTs    = 0;
            } finally {
              inflight = false;
            }
          } else {
            dbg('tick: payload ignored (cooldown)');
          }
        }
      } catch (e) {
        // Only surface fatal camera errors; otherwise keep scanning
        const msg = e && (e.message || e);
        if (/NotAllowedError|NotReadableError|OverconstrainedError|NotFoundError|no camera/i.test(String(msg))) {
          setStat('Camera error: ' + msg, 'error');
        } else {
          dbg('tick: non-fatal error', msg);
        }
      }

      // Heartbeat: only while scanning and not in-flight
      if (uiMode === 'scanning' && !inflight && qrStat && (Date.now() - lastUIBeat) > 1000) {
        if (qrStat.textContent === '' || qrStat.textContent === (t.scanning||'Scanning…')) {
          qrStat.textContent = t.scanning||'Scanning…';
          lastUIBeat = Date.now();
        }
      }

      qrLoop = requestAnimationFrame(tick);
    };

    qrLoop = requestAnimationFrame(tick);
    dbg('startQr: tick loop started');

  } catch (e) {
    setStat((t.camera_error||'Camera error:') + ' ' + (e && e.message ? e.message : e), 'error');
    dbg('startQr: getUserMedia error', e && e.message);
  }
}




function stopQr(){
  inflight = false;
  qrRunning = false;
  if (camBadge) { camBadge.textContent = (window.SFS_ATT_I18N||{}).camera_off||'Camera Off'; camBadge.classList.add('off'); }
  showScannerUI(false);
  if (qrLoop) { cancelAnimationFrame(qrLoop); qrLoop = null; }
  if (selfiePreviewLoop) { cancelAnimationFrame(selfiePreviewLoop); selfiePreviewLoop = null; }
  if (qrStream) { try { qrStream.getTracks().forEach(t=>t.stop()); } catch(_) {} qrStream = null; }
  if (qrVid) qrVid.srcObject = null;
  
  if (qrStop)  qrStop.disabled  = true;
  if (qrStat)  qrStat.textContent = '';
  lastUIBeat = 0;
  uiMode = 'idle';
  stopSelfiePreview();
}


async function refresh(){
  try {
    const r = await fetch(statusUrl, {
      headers: { 'X-WP-Nonce': nonce, 'Cache-Control': 'no-cache' },
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const j = await r.json();
    if (!r.ok) throw new Error(j.message || 'Status error');

    const tag = requiresSelfie ? ' — ' + ((window.SFS_ATT_I18N||{}).selfie_required||'selfie required') : '';
setStat(((window.SFS_ATT_I18N||{}).ready||'Ready') + ' — ' + ((window.SFS_ATT_I18N||{}).action||'action') + ': ' + labelFor(currentAction) + tag, currentAction);


// Device-level features/policy
requiresSelfie = !!j.requires_selfie;
const qrOn = (typeof j.qr_enabled === 'boolean') ? j.qr_enabled : true;

// Single, unified status line
setStat(((window.SFS_ATT_I18N||{}).ready||'Ready') + ' — ' + ((window.SFS_ATT_I18N||{}).action||'action') + ': ' + labelFor(currentAction) + tag, 'idle');
if (laneChip) {
  laneChip.textContent = labelFor(currentAction);
  laneChip.className   = chipClassFor(currentAction);
}



    // CSS view toggle handles visibility; only update capture button
    const inScan = (ROOT && ROOT.dataset.view === 'scan');
    if (capture) {
      capture.style.display = (qrOn && requiresSelfie && manualSelfieMode && inScan) ? '' : 'none';
    }



  } catch (e) {
    setStat((t.error_prefix||'Error:') + ' ' + (e.message || e), 'error');
  }
}


     
     
      qrStop  && qrStop.addEventListener('click',  stopQr);

      // helper: read ?emp= & ?token= from either a URL or raw text/JSON
function _qrNormalize(s){
  // remove LTR/RTL & embedding marks; collapse whitespace
  return String(s)
    .replace(/[\u200E\u200F\u202A-\u202E]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

function flashPunchSuccess(kind) {
  const root = document.getElementById('<?php echo $root_id; ?>');
  if (!root) return;

  const cls = (kind === 'queued')
    ? 'sfs-kiosk-flash-queued'
    : 'sfs-kiosk-flash-ok';

  root.classList.add(cls);
  setTimeout(() => {
    root.classList.remove(cls);
  }, 260);
}


function showScannerUI(on){ /* CSS view toggle handles visibility */ }

function kickScanner(){
  try {
    // Only start if not already running and device allows QR
    if (!qrRunning) {
      showScannerUI(true);
      startQr();                  // ← opens camera
    }
  } catch(e){
    setStat('Camera error: ' + (e.message || e), 'error');
  }
}

// === Session tracking: counter + log + timer ===
let sessionCount = 0;
let sessionStartTs = null;
let sessionTimer = null;
const scanLog = [];
const countEl  = document.getElementById('sfs-kiosk-count-<?php echo $inst; ?>');
const logList  = document.getElementById('sfs-kiosk-log-list-<?php echo $inst; ?>');
const sessionTimeEl = document.getElementById('sfs-kiosk-session-time-<?php echo $inst; ?>');
const flashNameEl   = document.getElementById('sfs-kiosk-flash-name-<?php echo $inst; ?>');
const flashActionEl = document.getElementById('sfs-kiosk-flash-action-<?php echo $inst; ?>');
const flashTimeEl   = document.getElementById('sfs-kiosk-flash-time-<?php echo $inst; ?>');
const summaryEl     = document.getElementById('sfs-kiosk-summary-<?php echo $inst; ?>');
const summaryCountEl = document.getElementById('sfs-summary-count-<?php echo $inst; ?>');
const summaryDurEl  = document.getElementById('sfs-summary-duration-<?php echo $inst; ?>');
const stopBtn       = document.getElementById('sfs-kiosk-stop-<?php echo $inst; ?>');
const summaryDoneBtn = document.getElementById('sfs-summary-done-<?php echo $inst; ?>');

function startSession(){
  sessionCount = 0;
  scanLog.length = 0;
  sessionStartTs = Date.now();
  if (countEl) countEl.textContent = '0';
  if (logList) logList.innerHTML = '';
  if (sessionTimer) clearInterval(sessionTimer);
  sessionTimer = setInterval(tickSessionTime, 1000);
  tickSessionTime();
}

function tickSessionTime(){
  if (!sessionStartTs || !sessionTimeEl) return;
  const s = Math.floor((Date.now() - sessionStartTs) / 1000);
  const m = Math.floor(s / 60);
  const h = Math.floor(m / 60);
  sessionTimeEl.textContent = h > 0
    ? h + 'h ' + (m % 60) + 'm'
    : m + 'm ' + (s % 60) + 's';
}

function getInitials(name){
  if (!name) return '?';
  const parts = name.trim().split(/\s+/);
  if (parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
  return name.slice(0,2).toUpperCase();
}

// TODO: Future — headcount counter: clock_in increments, clock_out decrements.
// Display the difference (employees currently inside) and optionally surface
// names of those who clocked in but haven't clocked out yet.
function addScanLog(name, action, ok){
  if (ok) {
    sessionCount++;
    if (countEl) countEl.textContent = String(sessionCount);
  }

  const time = new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
  scanLog.unshift({ name, action, time, ok });

  const safeName = (name||'\u2014').replace(/</g,'&lt;');
  const cls = ok ? 'ok' : 'err';
  // Action-specific color class for dots and initials
  const actionCls = 'action-' + (action || 'in');

  // Build log item HTML with action-specific colors
  function buildLogHtml(){
    return '<span class="sfs-log-initials ' + cls + ' ' + actionCls + '">' + getInitials(name) + '</span>'
      + '<div class="sfs-log-info"><span class="sfs-log-name">' + safeName + '</span>'
      + '<span class="sfs-log-meta"><span class="sfs-log-dot ' + cls + ' ' + actionCls + '"></span> '
      + labelFor(action) + ' \u2022 ' + time + '</span></div>'
      + '<span class="sfs-log-time">' + time + '</span>';
  }

  // Update desktop/tablet activity log
  if (logList) {
    const li = document.createElement('li');
    li.innerHTML = buildLogHtml();
    logList.prepend(li);
    while (logList.children.length > 50) logList.removeChild(logList.lastChild);
  }

  // Update mobile slide-up modal list
  const modalList = document.getElementById('sfs-log-modal-list-<?php echo $inst; ?>');
  if (modalList) {
    const li = document.createElement('li');
    li.innerHTML = buildLogHtml();
    modalList.prepend(li);
    while (modalList.children.length > 50) modalList.removeChild(modalList.lastChild);
  }

  // Update mobile bottom trigger bar
  const triggerCount = document.getElementById('sfs-log-modal-count-<?php echo $inst; ?>');
  const triggerLast  = document.getElementById('sfs-log-modal-last-<?php echo $inst; ?>');
  if (triggerCount) triggerCount.textContent = String(sessionCount);
  if (triggerLast) {
    triggerLast.innerHTML = '<span class="sfs-log-dot ' + actionCls + '"></span> '
      + safeName + ' — ' + labelFor(action);
  }
}

function showSessionSummary(){
  stopQr();
  ROOT.dataset.view = 'menu';
  if (sessionTimer) { clearInterval(sessionTimer); sessionTimer = null; }

  if (summaryCountEl) summaryCountEl.textContent = String(sessionCount);
  if (summaryDurEl && sessionStartTs) {
    const s = Math.floor((Date.now() - sessionStartTs) / 1000);
    const m = Math.floor(s / 60);
    const h = Math.floor(m / 60);
    summaryDurEl.textContent = (h > 0 ? h + 'h ' : '') + (m % 60) + 'm ' + (s % 60) + 's';
  }
  if (summaryEl) summaryEl.style.display = '';
}

// STOP button
if (stopBtn) {
  stopBtn.addEventListener('click', showSessionSummary);
}

// Session summary: single "Done" button — resets session and returns to menu
if (summaryDoneBtn) {
  summaryDoneBtn.addEventListener('click', () => {
    if (summaryEl) summaryEl.style.display = 'none';
    startSession();
    ROOT.dataset.view = 'menu';
    setStat(t.ready_choose_action||'Ready — choose an action', 'idle');
  });
}



function getGeoFast({timeout=2000, maxAge=120000} = {}) {
  return new Promise(resolve => {
    if (!navigator.geolocation) return resolve(null);
    const opts = { enableHighAccuracy:false, timeout, maximumAge:maxAge };
    let done = v => resolve(v||null);
    const timer = setTimeout(() => done(null), timeout + 50);
    navigator.geolocation.getCurrentPosition(
      pos => { clearTimeout(timer); done({
        lat: pos.coords.latitude, lng: pos.coords.longitude,
        acc: Math.round(pos.coords.accuracy || 0)
      }); },
      _ => { clearTimeout(timer); done(null); },
      opts
    );
  });
}




function startSelfiePreview(){
  const cnv = document.getElementById('sfs-kiosk-canvas-<?php echo $inst; ?>');
  const vid = document.getElementById('sfs-kiosk-qr-video-<?php echo $inst; ?>');
  if (!cnv || !vid) return; // preview is optional

  const ctx = cnv.getContext('2d', { willReadFrequently: true });

  function ensureSize(){
    if (cnv.width !== 640 || cnv.height !== 480) { cnv.width = 640; cnv.height = 480; }
    return (vid.videoWidth > 0 && vid.videoHeight > 0);
  }

  function tick(){
    if (!qrRunning) return;
    if (!ensureSize()) { selfiePreviewLoop = requestAnimationFrame(tick); return; }

    const vw = vid.videoWidth, vh = vid.videoHeight;
    const cw = cnv.width, ch = cnv.height;
    const s  = Math.min(cw/vw, ch/vh);
    const dw = vw*s, dh = vh*s, dx = (cw-dw)/2, dy = (ch-dh)/2;

    ctx.clearRect(0,0,cw,ch);
    ctx.drawImage(vid, 0, 0, vw, vh, dx, dy, dw, dh);

    selfiePreviewLoop = requestAnimationFrame(tick);
  }

  if (selfiePreviewLoop) cancelAnimationFrame(selfiePreviewLoop);
  selfiePreviewLoop = requestAnimationFrame(tick);
}



function stopSelfiePreview() {
  if (selfiePreviewLoop) { cancelAnimationFrame(selfiePreviewLoop); selfiePreviewLoop = null; }
}





function captureSelfieFromQrVideo() {
  const qrVid  = document.getElementById('sfs-kiosk-qr-video-<?php echo $inst; ?>');
  const canvas = document.getElementById('sfs-kiosk-selfie-<?php echo $inst; ?>');
  const ctx = canvas.getContext('2d', { willReadFrequently: true });

  // Ensure camera is ready
  if (!qrVid || !qrVid.srcObject || qrVid.readyState < 2 || !qrVid.videoWidth || !qrVid.videoHeight) {
    return Promise.resolve(null);
  }

  // Square crop from center
  const vw = qrVid.videoWidth, vh = qrVid.videoHeight;
  const size = Math.min(vw, vh);
  const sx = (vw - size) / 2;
  const sy = (vh - size) / 2;

  ctx.drawImage(qrVid, sx, sy, size, size, 0, 0, canvas.width, canvas.height);

  return new Promise(resolve => {
    canvas.toBlob(blob => resolve(blob), 'image/jpeg', 0.75);
  });
}



function parseEmployeeQR(payload){
  // Normalize entities (&amp;, &#038;, &#x26;) → &
  const norm = (function(s){
    if (typeof s !== 'string') s = String(s || '');
    s = s.replace(/&amp;|&#0*38;|&#x0*26;/gi, '&');
    const el = document.createElement('textarea');
    el.innerHTML = s;
    return el.value.trim();
  })(payload);

  // URL form
  try {
    const u = new URL(norm, window.location.origin);
    const emp   = u.searchParams.get('emp') || u.searchParams.get('employee') || u.searchParams.get('id');
    const token = u.searchParams.get('token') || u.searchParams.get('t');
    if (emp && token) return { emp: parseInt(emp,10), token };
  } catch(_) {}

  // raw querystring
  const m = norm.match(/(?:^|[?&])emp=(\d+).*?(?:^|[?&])token=([A-Za-z0-9_\.\-]+)/i);
  if (m) return { emp: parseInt(m[1],10), token: m[2] };

  // JSON
  try {
    const o = JSON.parse(norm);
    const emp   = o.emp || o.employee_id || o.id;
    const token = o.token || o.qr_token;
    if (emp && token) return { emp: parseInt(emp,10), token };
  } catch(_) {}

  return null;
}


      function tickClock(){
        try {
          const d = new Date();
          clockEl && (clockEl.textContent = d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}));
        } catch(_) {}
      }
      tickClock(); setInterval(tickClock, 1000);


function tickDate(){
  if (!dateEl) return;
  const d = new Date();
  const dateLang = localStorage.getItem('sfs_hr_lang') || 'en';
  const dateLocale = dateLang === 'ar' ? 'ar-SA' : (dateLang === 'ur' ? 'ur-PK' : (dateLang === 'fil' ? 'fil-PH' : 'en-US'));
  dateEl.textContent = d.toLocaleDateString(dateLocale, {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
  });
}

tickDate();

// ── Mobile scan info bar: sync time + date ──
(function(){
  const scanTimeEl = document.getElementById('sfs-scan-info-time-<?php echo $inst; ?>');
  const scanDateEl = document.getElementById('sfs-scan-info-date-<?php echo $inst; ?>');
  function tickScanInfo(){
    const d = new Date();
    if (scanTimeEl) scanTimeEl.textContent = d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    if (scanDateEl) {
      const dateLang = localStorage.getItem('sfs_hr_lang') || 'en';
      const dateLocale = dateLang === 'ar' ? 'ar-SA' : (dateLang === 'ur' ? 'ur-PK' : (dateLang === 'fil' ? 'fil-PH' : 'en-US'));
      scanDateEl.textContent = d.toLocaleDateString(dateLocale, {
        weekday: 'short', year: 'numeric', month: 'short', day: 'numeric'
      });
    }
  }
  tickScanInfo();
  setInterval(tickScanInfo, 1000);
})();

// ── Slide-up modal for Recent Scans (mobile) ──
(function(){
  const trigger  = document.getElementById('sfs-log-modal-trigger-<?php echo $inst; ?>');
  const modal    = document.getElementById('sfs-log-modal-<?php echo $inst; ?>');
  const backdrop = document.getElementById('sfs-log-modal-backdrop-<?php echo $inst; ?>');
  if (!trigger || !modal || !backdrop) return;

  function openModal(){
    modal.classList.add('open');
    backdrop.classList.add('show');
  }
  function closeModal(){
    modal.classList.remove('open');
    backdrop.classList.remove('show');
  }
  trigger.addEventListener('click', openModal);
  backdrop.addEventListener('click', closeModal);
  // Swipe-down to close
  let startY = 0;
  modal.addEventListener('touchstart', function(e){ startY = e.touches[0].clientY; }, {passive:true});
  modal.addEventListener('touchmove', function(e){
    const dy = e.touches[0].clientY - startY;
    if (dy > 60) closeModal();
  }, {passive:true});
})();

// ── Wrong-punch error notice (visible overlay) ──
const errorNoticeEl  = document.getElementById('sfs-kiosk-error-notice-<?php echo $inst; ?>');
const errorMsgEl     = document.getElementById('sfs-kiosk-error-msg-<?php echo $inst; ?>');
const errorDetailEl  = document.getElementById('sfs-kiosk-error-detail-<?php echo $inst; ?>');
let errorNoticeTimer = null;

function showPunchError(msg, detail){
  if (!errorNoticeEl) return;
  if (errorMsgEl)    errorMsgEl.textContent = msg || '';
  if (errorDetailEl) errorDetailEl.textContent = detail || '';
  errorNoticeEl.classList.add('show');
  playErrorTone();
  if (errorNoticeTimer) clearTimeout(errorNoticeTimer);
  errorNoticeTimer = setTimeout(function(){
    errorNoticeEl.classList.remove('show');
  }, 2500);
}

(function(){
  const greet = document.getElementById('sfs-greet-<?php echo $inst; ?>');
  if (!greet) return;
  const h = new Date().getHours();
  greet.textContent =
    h < 12 ? (t.good_morning||'Good morning!') :
    h < 18 ? (t.good_afternoon||'Good afternoon!') :
             (t.good_evening||'Good evening!');
})();




      async function punch(type){
        if (!allowed[type]) {
          let msg = t.invalid_action||'Invalid action.';
          if (type==='out' && state==='break')             msg = t.employee_on_break||'Employee is on break. End the break before clocking out.';
          else if (type==='out' && state!=='in')           msg = t.employee_not_clocked_in||'Employee is not clocked in.';
          else if (type==='in'  && state!=='idle')         msg = t.already_clocked_in||'Already clocked in.';
          else if (type==='break_start' && state!=='in')   msg = t.break_only_while_clocked_in||'Break can start only while clocked in.';
          else if (type==='break_end'   && state!=='break')msg = t.no_active_break_to_end||'No active break to end.';
          setStat((t.error_prefix||'Error:') + ' ' + msg, 'error');
          return;
        }


                setStat(t.working_ellipsis||'Working…', 'busy');

        let geo = null;
        try {
            geo = await getGeo(type);
        } catch(e){
            // geo_blocked → stop here
            return;
        }

        let headers = {'X-WP-Nonce':nonce};

        let body;

        if (requiresSelfie) {
          if (!lastBlob) { setStat((t.error_prefix||'Error:') + ' ' + (t.capture_selfie_first||'Please capture a selfie first.'), 'error'); return; }
          const fd = new FormData();
          fd.append('punch_type', String(type));
          fd.append('source', 'kiosk');
          fd.append('device', String(DEVICE_ID));
          if (geo && typeof geo.lat==='number' && typeof geo.lng==='number') {
            fd.append('geo_lat', String(geo.lat));
            fd.append('geo_lng', String(geo.lng));
            
            if (typeof geo.acc==='number') fd.append('geo_accuracy_m', String(Math.round(geo.acc)));
          }
          // Use unique filename with timestamp and random component to prevent overwrites
          const timestamp = Date.now();
          const random = Math.random().toString(36).substring(7);
          fd.append('selfie', lastBlob, `kiosk-selfie-${timestamp}-${random}.jpg`);
          fd.append('employee_scan_token', employeeScanToken || '');
          body = fd;
        } else {
          headers['Content-Type'] = 'application/json';
          const payload = {
  punch_type: String(type),
  source: 'kiosk',
  device: DEVICE_ID,
  employee_scan_token: (employeeScanToken || '')
};

          if (geo && typeof geo.lat==='number' && typeof geo.lng==='number') {
            payload.geo_lat = geo.lat;
            payload.geo_lng = geo.lng;
            if (typeof geo.acc==='number') payload.geo_accuracy_m = Math.round(geo.acc);
          }
          body = JSON.stringify(payload);
        }

        try{
          const r = await fetch(punchUrl, { method:'POST', headers, credentials:'same-origin', body });
          const j = await r.json();
          if (!r.ok) throw new Error(j.message || 'Punch failed');
          setStat(j.label || t.done_label||'Done', 'ok');

          lastBlob = null;
          await refresh();
          ROOT.querySelectorAll('button[data-action]').forEach(b=>b.disabled = true);
          setTimeout(()=>ROOT.querySelectorAll('button[data-action]').forEach(b=>b.disabled = !allowed[b.getAttribute('data-type')]), 3000);
        }catch(e){
          setStat((t.error_prefix||'Error:') + ' ' + e.message, 'error');
        }
      }



ROOT.dataset.view = 'menu';
setMode('menu');
      refresh();

      // --- Offline roster: preload employee cache on init + periodic refresh ---
      if (OFFLINE_ENABLED && window.sfsHrPwa && window.sfsHrPwa.refreshRoster) {
        // Initial roster cache (non-blocking)
        window.sfsHrPwa.refreshRoster(DEVICE_ID, nonce).then(ok => {
          dbg('roster initial cache:', ok ? 'success' : 'skipped/failed');
        });
        // Periodic refresh every 15 minutes (while page is open)
        setInterval(() => {
          if (navigator.onLine) {
            window.sfsHrPwa.refreshRoster(DEVICE_ID, nonce).then(ok => {
              dbg('roster periodic refresh:', ok ? 'success' : 'skipped/failed');
            });
          }
        }, 15 * 60 * 1000);
      }
    })();
    </script>
    <?php
    return ob_get_clean();
}


private static function add_column_if_missing( \wpdb $wpdb, string $table, string $col, string $ddl ): void {
    $exists = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col) );
    if ( ! $exists ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$ddl}" );
    }
}

/**
 * One-time migration: add FK constraints to attendance tables.
 * Cleans orphaned rows first, then adds RESTRICT/SET NULL/CASCADE as appropriate.
 */
private static function migrate_add_foreign_keys( \wpdb $wpdb, string $p ): void {
    $empT    = "{$p}sfs_hr_employees";
    $punchT  = "{$p}sfs_hr_attendance_punches";
    $sessT   = "{$p}sfs_hr_attendance_sessions";
    $shiftT  = "{$p}sfs_hr_attendance_shifts";
    $assignT = "{$p}sfs_hr_attendance_shift_assign";
    $empShT  = "{$p}sfs_hr_attendance_emp_shifts";
    $flagT   = "{$p}sfs_hr_attendance_flags";
    $auditT  = "{$p}sfs_hr_attendance_audit";
    $elrT    = "{$p}sfs_hr_early_leave_requests";

    // Helper: check if a FK already exists on a table
    $fk_exists = function( string $table, string $fk_name ) use ( $wpdb ): bool {
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
               AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            $table, $fk_name
        ) );
    };

    // Ensure InnoDB on all tables including the parent employees table
    // (required for FK constraints).
    $had_errors = false;
    foreach ( [ $empT, $punchT, $sessT, $shiftT, $assignT, $empShT, $flagT, $auditT, $elrT ] as $t ) {
        $engine = $wpdb->get_var( $wpdb->prepare(
            "SELECT ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $t
        ) );
        if ( $engine !== null && strcasecmp( $engine, 'InnoDB' ) !== 0 ) {
            if ( $wpdb->query( "ALTER TABLE {$t} ENGINE = InnoDB" ) === false ) {
                $had_errors = true;
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( "[SFS HR] FK migration: failed to convert {$t} to InnoDB" );
                }
            }
        }
    }

    if ( $had_errors ) {
        return; // Retry on next activation — don't set the migrated flag
    }

    // Clean orphaned employee references
    $cleanup_queries = [
        "DELETE p FROM {$punchT} p LEFT JOIN {$empT} e ON e.id = p.employee_id WHERE e.id IS NULL",
        "DELETE s FROM {$sessT} s LEFT JOIN {$empT} e ON e.id = s.employee_id WHERE e.id IS NULL",
        "DELETE sa FROM {$assignT} sa LEFT JOIN {$empT} e ON e.id = sa.employee_id WHERE e.id IS NULL",
        "DELETE es FROM {$empShT} es LEFT JOIN {$empT} e ON e.id = es.employee_id WHERE e.id IS NULL",
        "DELETE f FROM {$flagT} f LEFT JOIN {$empT} e ON e.id = f.employee_id WHERE e.id IS NULL",
        "UPDATE {$auditT} a LEFT JOIN {$empT} e ON e.id = a.target_employee_id SET a.target_employee_id = NULL WHERE a.target_employee_id IS NOT NULL AND e.id IS NULL",
        "DELETE el FROM {$elrT} el LEFT JOIN {$empT} e ON e.id = el.employee_id WHERE e.id IS NULL",
        // Clean orphaned shift references
        "DELETE sa FROM {$assignT} sa LEFT JOIN {$shiftT} sh ON sh.id = sa.shift_id WHERE sh.id IS NULL",
        "DELETE es FROM {$empShT} es LEFT JOIN {$shiftT} sh ON sh.id = es.shift_id WHERE sh.id IS NULL",
        "UPDATE {$sessT} s LEFT JOIN {$assignT} sa ON sa.id = s.shift_assign_id SET s.shift_assign_id = NULL WHERE s.shift_assign_id IS NOT NULL AND sa.id IS NULL",
    ];

    foreach ( $cleanup_queries as $sql ) {
        if ( $wpdb->query( $sql ) === false ) {
            $had_errors = true;
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "[SFS HR] FK migration: cleanup query failed — {$wpdb->last_error}" );
            }
        }
    }

    if ( $had_errors ) {
        return; // Retry on next activation — don't set the migrated flag
    }

    // Add FK constraints (skip if already exists)
    $fks = [
        [ $punchT,  'fk_punches_employee',       'employee_id',      $empT,    'id', 'RESTRICT' ],
        [ $sessT,   'fk_sessions_employee',       'employee_id',      $empT,    'id', 'RESTRICT' ],
        [ $sessT,   'fk_sessions_shift_assign',   'shift_assign_id',  $assignT, 'id', 'SET NULL' ],
        [ $assignT, 'fk_shift_assign_employee',   'employee_id',      $empT,    'id', 'RESTRICT' ],
        [ $assignT, 'fk_shift_assign_shift',      'shift_id',         $shiftT,  'id', 'CASCADE'  ],
        [ $empShT,  'fk_emp_shifts_employee',      'employee_id',      $empT,    'id', 'RESTRICT' ],
        [ $empShT,  'fk_emp_shifts_shift',         'shift_id',         $shiftT,  'id', 'CASCADE'  ],
        [ $flagT,   'fk_flags_employee',           'employee_id',      $empT,    'id', 'RESTRICT' ],
        [ $auditT,  'fk_audit_employee',           'target_employee_id', $empT,  'id', 'SET NULL' ],
        [ $elrT,    'fk_early_leave_employee',     'employee_id',      $empT,    'id', 'RESTRICT' ],
    ];

    foreach ( $fks as [ $table, $name, $col, $ref_table, $ref_col, $on_delete ] ) {
        if ( ! $fk_exists( $table, $name ) ) {
            $result = $wpdb->query(
                "ALTER TABLE {$table} ADD CONSTRAINT {$name}
                 FOREIGN KEY ({$col}) REFERENCES {$ref_table}({$ref_col})
                 ON DELETE {$on_delete} ON UPDATE CASCADE"
            );
            if ( $result === false ) {
                $had_errors = true;
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( "[SFS HR] FK migration: failed to add {$name} on {$table}" );
                }
            }
        }
    }

    if ( ! $had_errors ) {
        update_option( 'sfs_hr_att_fk_migrated', 1 );
    } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[SFS HR] FK migration: completed with errors — will retry on next activation' );
    }
}

/**
 * Add unique key if it doesn't exist
 */
private static function add_unique_key_if_missing( \wpdb $wpdb, string $table, string $key_name ): void {
    $index_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
        $table, $key_name
    ));
    if ((int)$index_exists === 0) {
        $col_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $key_name));
        if ($col_exists) {
            $wpdb->query("ALTER TABLE `$table` ADD UNIQUE KEY `$key_name` (`$key_name`)");
        }
    }
}

/**
 * Generate reference number for early leave requests
 */
/** @deprecated Delegate to Early_Leave_Service. */
public static function generate_early_leave_request_number(): string {
    return Services\Early_Leave_Service::generate_early_leave_request_number();
}

/** @deprecated Delegate to Early_Leave_Service. */
private static function maybe_create_early_leave_request(
    int $employee_id,
    string $ymd,
    int $session_id,
    ?string $lastOutUtc,
    int $minutes_early,
    bool $is_total_hours,
    $shift,
    \wpdb $wpdb
): void {
    Services\Early_Leave_Service::maybe_create_early_leave_request(
        $employee_id, $ymd, $session_id, $lastOutUtc,
        $minutes_early, $is_total_hours, $shift, $wpdb
    );
}

/** @deprecated Delegate to Early_Leave_Service. */
private static function backfill_early_leave_request_numbers( \wpdb $wpdb ): void {
    Services\Early_Leave_Service::backfill_early_leave_request_numbers( $wpdb );
}


    /**
     * Create / upgrade tables and initialize caps & defaults.
     */
    public function maybe_install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        // 1) punches (immutable events)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_punches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            punch_type ENUM('in','out','break_start','break_end') NOT NULL,
            punch_time DATETIME NOT NULL,
            source ENUM('self_web','self_mobile','kiosk','manager_adjust','import_sync') NOT NULL,
            device_id BIGINT UNSIGNED NULL,
            ip_addr VARCHAR(45) NULL,
            geo_lat DECIMAL(10,7) NULL,
            geo_lng DECIMAL(10,7) NULL,
            geo_accuracy_m SMALLINT UNSIGNED NULL,
            selfie_media_id BIGINT UNSIGNED NULL,
            valid_geo TINYINT(1) NOT NULL DEFAULT 1,
            valid_selfie TINYINT(1) NOT NULL DEFAULT 1,
            offline_origin TINYINT(1) NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY emp_time (employee_id, punch_time),
            KEY dev_time (device_id, punch_time),
            KEY punch_time (punch_time),
            KEY date_type (punch_time, punch_type),
            KEY source (source),
            KEY emp_type_date (employee_id, punch_type, punch_time)
        ) $charset_collate;");

        // Migration: Add offline_origin column for existing installations
        $punchesT = "{$p}sfs_hr_attendance_punches";
        self::add_column_if_missing($wpdb, $punchesT, 'offline_origin', "offline_origin TINYINT(1) NOT NULL DEFAULT 0 AFTER valid_selfie");

        // 2) sessions (processed day rows for payroll)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            shift_assign_id BIGINT UNSIGNED NULL,
            work_date DATE NOT NULL,
            in_time DATETIME NULL,
            out_time DATETIME NULL,
            break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            break_delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            no_break_taken TINYINT(1) NOT NULL DEFAULT 0,
            net_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            rounded_net_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('present','late','left_early','absent','incomplete','on_leave','holiday','day_off') NOT NULL DEFAULT 'present',
            overtime_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            flags_json LONGTEXT NULL,
            calc_meta_json LONGTEXT NULL,
            last_recalc_at DATETIME NOT NULL,
            locked TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY emp_date (employee_id, work_date),
            KEY work_date (work_date),
            KEY status (status)
        ) $charset_collate;");

        // 3) shift templates (CRUD in admin; Ramadan, etc. handled via assignments)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_shifts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            location_label VARCHAR(100) NOT NULL,
            location_lat DECIMAL(10,7) NULL,
            location_lng DECIMAL(10,7) NULL,
            location_radius_m SMALLINT UNSIGNED NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            unpaid_break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            break_start_time TIME NULL,
            break_policy ENUM('auto','punch','none') NOT NULL DEFAULT 'auto',
            grace_late_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5,
            grace_early_leave_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5,
            rounding_rule ENUM('none','5','10','15') NOT NULL DEFAULT '5',
            overtime_after_minutes SMALLINT UNSIGNED NULL,
            require_selfie TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            dept_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            weekly_overrides TEXT NULL,
            period_overrides TEXT NULL,
            dept_ids TEXT NULL,
            PRIMARY KEY (id),
            KEY active_dept_id (active, dept_id),
            KEY dept_id (dept_id)
        ) $charset_collate;");

        // Migration: Add dept_id column if missing (for existing installations)
        $shifts_table = "{$p}sfs_hr_attendance_shifts";
        $col_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'dept_id'",
            $shifts_table
        ) );
        if ( ! $col_exists ) {
            $wpdb->query( "ALTER TABLE {$shifts_table} ADD COLUMN dept_id BIGINT UNSIGNED NULL AFTER active" );
            $wpdb->query( "ALTER TABLE {$shifts_table} ADD KEY dept_id (dept_id)" );
        }

        // Migration: Add dept_ids column for multi-department support
        $dept_ids_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'dept_ids'",
            $shifts_table
        ) );
        if ( ! $dept_ids_exists ) {
            $wpdb->query( "ALTER TABLE {$shifts_table} ADD COLUMN dept_ids TEXT NULL COMMENT 'JSON array of department IDs'" );
            // Migrate existing single dept_id to dept_ids JSON
            $wpdb->query( "UPDATE {$shifts_table} SET dept_ids = CONCAT('[', dept_id, ']') WHERE dept_id IS NOT NULL AND dept_ids IS NULL" );
        }

        // Migration: Add period_overrides column for date-range time overrides (Ramadan, etc.)
        $period_ov_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'period_overrides'",
            $shifts_table
        ) );
        if ( ! $period_ov_exists ) {
            $wpdb->query( "ALTER TABLE {$shifts_table} ADD COLUMN period_overrides TEXT NULL COMMENT 'JSON array of date-range time overrides' AFTER weekly_overrides" );
        }

                // 4) daily assignments (rota)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_shift_assign (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            shift_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            is_holiday TINYINT(1) NOT NULL DEFAULT 0,
            override_json LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY emp_date (employee_id, work_date),
            KEY shift_date (shift_id, work_date),
            KEY work_date (work_date)
        ) $charset_collate;");

        // 5) employee default shifts (history)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_emp_shifts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            shift_id BIGINT UNSIGNED NOT NULL,
            schedule_id BIGINT UNSIGNED NULL,
            start_date DATE NOT NULL,
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY emp_date (employee_id, start_date),
            KEY shift_id (shift_id),
            KEY schedule_id (schedule_id)
        ) $charset_collate;");

        // Migration: Add schedule_id column to emp_shifts for existing installations
        $emp_shifts_tbl = "{$p}sfs_hr_attendance_emp_shifts";
        $sched_col_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'schedule_id'",
            $emp_shifts_tbl
        ) );
        if ( ! $sched_col_exists ) {
            $wpdb->query( "ALTER TABLE {$emp_shifts_tbl} ADD COLUMN schedule_id BIGINT UNSIGNED NULL COMMENT 'FK to shift_schedules' AFTER shift_id" );
            $wpdb->query( "ALTER TABLE {$emp_shifts_tbl} ADD KEY schedule_id (schedule_id)" );
        }

        // 5b) shift schedules (rotation patterns: week A/B, 4-on-4-off, etc.)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_shift_schedules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            cycle_days SMALLINT UNSIGNED NOT NULL,
            anchor_date DATE NOT NULL,
            entries TEXT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY active (active)
        ) $charset_collate;");

        // 6) devices (kiosks & locks)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_devices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(100) NOT NULL,
            type ENUM('kiosk','mobile','web') NOT NULL DEFAULT 'kiosk',
            kiosk_enabled TINYINT(1) NOT NULL DEFAULT 0,
            kiosk_pin VARCHAR(255) NULL,
            kiosk_offline TINYINT(1) NOT NULL DEFAULT 0,
            last_sync_at DATETIME NULL,
            geo_lock_lat DECIMAL(10,7) NULL,
            geo_lock_lng DECIMAL(10,7) NULL,
            geo_lock_radius_m SMALLINT UNSIGNED NULL,
            allowed_dept_id BIGINT UNSIGNED NULL,
            fingerprint_hash VARCHAR(64) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            qr_enabled TINYINT(1) NOT NULL DEFAULT 1,
            selfie_mode ENUM('inherit','never','in_only','in_out','all') NOT NULL DEFAULT 'inherit',
            PRIMARY KEY (id),
            KEY active_type (active, type),
            KEY fp (fingerprint_hash),
            KEY kiosk_enabled (kiosk_enabled),
            KEY allowed_dept_id (allowed_dept_id)
        ) $charset_collate;");


// Harden upgrades: add columns if old table exists without them
$t = "{$p}sfs_hr_attendance_devices";
self::add_column_if_missing($wpdb, $t, 'kiosk_enabled',     "kiosk_enabled TINYINT(1) NOT NULL DEFAULT 0");
self::add_column_if_missing($wpdb, $t, 'kiosk_offline',     "kiosk_offline TINYINT(1) NOT NULL DEFAULT 0");
self::add_column_if_missing($wpdb, $t, 'geo_lock_lat',      "geo_lock_lat DECIMAL(10,7) NULL");
self::add_column_if_missing($wpdb, $t, 'geo_lock_lng',      "geo_lock_lng DECIMAL(10,7) NULL");
self::add_column_if_missing($wpdb, $t, 'geo_lock_radius_m', "geo_lock_radius_m SMALLINT UNSIGNED NULL");
self::add_column_if_missing($wpdb, $t, 'allowed_dept_id',   "allowed_dept_id BIGINT UNSIGNED NULL");
self::add_column_if_missing($wpdb, $t, 'active',            "active TINYINT(1) NOT NULL DEFAULT 1");
self::add_column_if_missing($wpdb, $t, 'qr_enabled',        "qr_enabled TINYINT(1) NOT NULL DEFAULT 1");
self::add_column_if_missing($wpdb, $t, 'selfie_mode',       "selfie_mode ENUM('inherit','never','in_only','in_out','all') NOT NULL DEFAULT 'inherit'");
self::add_column_if_missing($wpdb, $t, 'suggest_in_time',         "suggest_in_time TIME NULL");
self::add_column_if_missing($wpdb, $t, 'suggest_break_start_time',"suggest_break_start_time TIME NULL");
self::add_column_if_missing($wpdb, $t, 'suggest_break_end_time',  "suggest_break_end_time TIME NULL");
self::add_column_if_missing($wpdb, $t, 'suggest_out_time',        "suggest_out_time TIME NULL");
self::add_column_if_missing($wpdb, $t, 'break_enabled',           "break_enabled TINYINT(1) NOT NULL DEFAULT 0");


        // 6) flags (exceptions)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_flags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NULL,
            punch_id BIGINT UNSIGNED NULL,
            flag_code ENUM('late','early_leave','missed_punch','outside_geofence','no_selfie','overtime','manual_edit') NOT NULL,
            flag_status ENUM('open','approved','rejected') NOT NULL DEFAULT 'open',
            manager_comment TEXT NULL,
            created_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            resolved_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY status_only (flag_status),
            KEY emp_created (employee_id, created_at)
        ) $charset_collate;");

        // 7) audit (append-only)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id BIGINT UNSIGNED NULL,
            action_type VARCHAR(50) NOT NULL,
            target_employee_id BIGINT UNSIGNED NULL,
            target_punch_id BIGINT UNSIGNED NULL,
            target_session_id BIGINT UNSIGNED NULL,
            before_json LONGTEXT NULL,
            after_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY act_time (action_type, created_at),
            KEY emp_time (target_employee_id, created_at)
        ) $charset_collate;");

        // 8) Early Leave Requests - for manager approval workflow
        dbDelta("CREATE TABLE {$p}sfs_hr_early_leave_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NULL,
            request_date DATE NOT NULL,
            scheduled_end_time TIME NULL,
            requested_leave_time TIME NOT NULL,
            actual_leave_time TIME NULL,
            reason_type ENUM('sick','external_task','personal','emergency','other') NOT NULL DEFAULT 'other',
            reason_note TEXT NULL,
            status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            manager_id BIGINT UNSIGNED NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            manager_note TEXT NULL,
            affects_salary TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY emp_date (employee_id, request_date),
            KEY status_date (status, request_date),
            KEY manager_status (manager_id, status),
            KEY session_id (session_id)
        ) $charset_collate;");

        // Add early_leave_approved column to sessions if missing
        $sessions_table = "{$p}sfs_hr_attendance_sessions";
        self::add_column_if_missing($wpdb, $sessions_table, 'early_leave_approved', "early_leave_approved TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($wpdb, $sessions_table, 'early_leave_request_id', "early_leave_request_id BIGINT UNSIGNED NULL");

        // Break delay & no-break-taken tracking
        self::add_column_if_missing($wpdb, $sessions_table, 'break_delay_minutes', "break_delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0");
        self::add_column_if_missing($wpdb, $sessions_table, 'no_break_taken', "no_break_taken TINYINT(1) NOT NULL DEFAULT 0");

        // Scheduled break start time on shifts
        self::add_column_if_missing($wpdb, $shifts_table, 'break_start_time', "break_start_time TIME NULL");

        // Add request_number column for early leave requests
        $early_leave_table = "{$p}sfs_hr_early_leave_requests";
        self::add_column_if_missing($wpdb, $early_leave_table, 'request_number', "request_number VARCHAR(50) NULL");
        self::add_unique_key_if_missing($wpdb, $early_leave_table, 'request_number');
        self::backfill_early_leave_request_numbers($wpdb);

        // Migration: Add foreign key constraints (runs once)
        if ( ! get_option( 'sfs_hr_att_fk_migrated' ) ) {
            self::migrate_add_foreign_keys( $wpdb, $p );
        }

        // Caps + defaults + seed kiosks
        $this->register_caps();
        $this->maybe_seed_defaults();
        $this->maybe_seed_kiosks();
    }

    /** Map capabilities to roles. */
    private function register_caps(): void {
        // Base caps
        $caps_self    = ['sfs_hr_attendance_clock_self','sfs_hr_attendance_view_self'];
        $caps_kiosk   = ['sfs_hr_attendance_clock_kiosk'];
        $caps_manage  = ['sfs_hr_attendance_view_team','sfs_hr_attendance_edit_team','sfs_hr_attendance_admin'];

        // Employee
        if ( $role = get_role('sfs_hr_employee') ) {
            foreach ( array_merge($caps_self, $caps_kiosk) as $c ) { $role->add_cap($c); }
        }

        // Manager
        if ( $role = get_role('sfs_hr_manager') ) {
            foreach ( array_merge($caps_self, $caps_kiosk, $caps_manage) as $c ) { $role->add_cap($c); }
        }

        // Any role that already has the suite’s master cap gets full attendance admin + self punch
        foreach ( wp_roles()->roles as $role_key => $def ) {
            $r = get_role($role_key);
            if ( ! $r ) { continue; }
            if ( $r->has_cap('sfs_hr.manage') ) {
                foreach ( array_merge($caps_manage, $caps_kiosk, $caps_self) as $c ) { $r->add_cap($c); }
            }
        }

        // Site Administrators: include self-punch too
        if ( $admin = get_role('administrator') ) {
            foreach ( array_merge($caps_manage, $caps_kiosk, $caps_self) as $c ) { $admin->add_cap($c); }
        }
        
        // Make sure device/admin caps exist on key roles
$caps_devices = ['sfs_hr_attendance_admin','sfs_hr_attendance_edit_devices'];

if ($admin = get_role('administrator')) {
    foreach (array_merge($caps_devices, ['sfs_hr_attendance_view_self','sfs_hr_attendance_clock_self','sfs_hr_attendance_clock_kiosk']) as $c) {
        $admin->add_cap($c);
    }
}
if ($mgr = get_role('sfs_hr_manager')) {
    foreach ($caps_devices as $c) { $mgr->add_cap($c); }
}

        
        
    }

    /** Seed global defaults (changeable later via Admin UI). */
    private function maybe_seed_defaults(): void {
        $defaults = [
            // Department settings now use dept_id from sfs_hr_departments table
            'web_allowed_by_dept_id'     => [], // dept_id => true/false
            'selfie_required_by_dept_id' => [], // dept_id => true/false
            'selfie_retention_days'      => 30,
            'default_rounding_rule'      => '5',
            'default_grace_late'         => 5,
            'default_grace_early'        => 5,

            // Weekly segments now keyed by dept_id
            'dept_weekly_segments' => [], // dept_id => [ 'sun' => [...], ... ]

            // Selfie policy (optional by default)
            'selfie_policy' => [
                'default'      => 'optional', // modes: never | optional | in_only | in_out | all
                'by_dept_id'   => [],         // dept_id => mode
                'by_employee'  => [],         // employee_id => mode
            ],

        ];

        $existing = get_option( self::OPT_SETTINGS );
        if ( ! is_array( $existing ) ) {
            add_option( self::OPT_SETTINGS, $defaults, '', false );
        } else {
            $merged = array_replace_recursive( $defaults, $existing );
            update_option( self::OPT_SETTINGS, $merged, false );
        }
    }

    /** Seed placeholder kiosks. */
    private function maybe_seed_kiosks(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_devices';
        $existing = $wpdb->get_col( "SELECT label FROM {$table} WHERE type='kiosk' LIMIT 2" );
        if ( is_array( $existing ) && count( $existing ) > 0 ) return;

        $now = current_time( 'mysql', true );
        // Create a sample kiosk with no department restriction (allowed_dept_id = null means all)
        $rows = [
            [
                'label'            => 'Main Kiosk #1',
                'type'             => 'kiosk',
                'kiosk_enabled'    => 1,
                'kiosk_pin'        => null,
                'kiosk_offline'    => 1,
                'last_sync_at'     => null,
                'geo_lock_lat'     => null,
                'geo_lock_lng'     => null,
                'geo_lock_radius_m'=> null,
                'allowed_dept_id'  => null, // null = all departments allowed
                'fingerprint_hash' => null,
                'active'           => 1,
                'meta_json'        => wp_json_encode( ['seeded_at'=>$now] ),
            ],
        ];
        foreach ( $rows as $r ) { $wpdb->insert( $table, $r ); }
    }

public function ajax_dbg(): void {
    // Minimal, safe logger
    $msg = isset($_POST['m']) ? wp_unslash($_POST['m']) : '';
    $ctx = isset($_POST['c']) ? wp_unslash($_POST['c']) : '';
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $line = '[SFS ATT DBG] ' . gmdate('c') . " ip={$ip} | " . $msg;
    if ($ctx !== '') { $line .= ' | ' . $ctx; }
    $line .= ' | UA=' . substr($ua, 0, 120);
    error_log($line);
    wp_send_json_success();
}

    /* ---------------- Core helpers ---------------- */

    /** Resolve employee_id from WP user_id via {prefix}sfs_hr_employees.user_id */
    public static function employee_id_from_user( int $user_id ): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d LIMIT 1",
            $user_id
        ) );
        return $id ? (int) $id : null;
    }

    /** Holidays/Leave guard. */
    public static function is_blocked_by_leave_or_holiday( int $employee_id, string $dateYmd ): bool {
        $blocked = false;

        // Holidays (uses holidays_in_range which handles yearly repeat expansion)
        $holiday_dates = \SFS\HR\Modules\Leave\Services\LeaveCalculationService::holidays_in_range( $dateYmd, $dateYmd );
        if ( ! empty( $holiday_dates ) ) {
            $blocked = true;
        }

        // Leaves
        if ( ! $blocked ) {
            global $wpdb;
            $table = $wpdb->prefix . 'sfs_hr_leave_requests';
            $has = $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$table}
                 WHERE employee_id = %d
                   AND status = 'approved'
                   AND %s BETWEEN start_date AND end_date
                 LIMIT 1",
                $employee_id, $dateYmd
            ) );
            $blocked = (bool) $has;
        }

        return (bool) apply_filters( 'sfs_hr_attendance_is_leave_or_holiday', $blocked, $employee_id, $dateYmd );
    }

    /** Check if employee is on approved leave (excludes company holidays). */
    public static function is_on_approved_leave( int $employee_id, string $dateYmd ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_leave_requests';
        $has = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$table}
             WHERE employee_id = %d
               AND status = 'approved'
               AND %s BETWEEN start_date AND end_date
             LIMIT 1",
            $employee_id, $dateYmd
        ) );
        return (bool) $has;
    }

    /**
     * Check if a date is a company holiday (not employee-specific leave)
     *
     * @param string $dateYmd Date in Y-m-d format
     * @return bool
     */
    public static function is_company_holiday( string $dateYmd ): bool {
        $holiday_dates = \SFS\HR\Modules\Leave\Services\LeaveCalculationService::holidays_in_range( $dateYmd, $dateYmd );
        return ! empty( $holiday_dates );
    }


/** Local Y-m-d → [start_utc, end_utc) */
public static function local_day_window_to_utc(string $ymd): array {
    $tz  = wp_timezone();
    $stL = new \DateTimeImmutable($ymd.' 00:00:00', $tz);
    $enL = $stL->modify('+1 day');
    $stU = $stL->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $enU = $enL->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    return [$stU, $enU];
}

/** Format a UTC MySQL datetime into site-local using WP formats. */
public static function fmt_local(?string $utc_mysql): string {
    if (!$utc_mysql) return '';
    $ts = strtotime($utc_mysql.' UTC');
    return wp_date(get_option('date_format').' '.get_option('time_format'), $ts);
}


/** Find the config array for a department by trying id → slug → name. */
private static function pick_dept_conf(array $autoMap, array $deptInfo): ?array {
    $candidates = [];
    if (!empty($deptInfo['id']))   $candidates[] = (string)$deptInfo['id'];
    if (!empty($deptInfo['slug'])) $candidates[] = (string)$deptInfo['slug'];
    if (!empty($deptInfo['name'])) $candidates[] = (string)$deptInfo['name'];

    foreach ($candidates as $key) {
        if (isset($autoMap[$key]) && is_array($autoMap[$key])) {
            return $autoMap[$key];
        }
    }
    return null;
}

    /** @deprecated Delegate to Session_Service. */
    public static function run_deferred_recalc( int $employee_id, string $ymd, bool $force = false ): void {
        Services\Session_Service::run_deferred_recalc( $employee_id, $ymd, $force );
    }

    /** @deprecated Delegate to Session_Service. */
    public static function recalc_session_for( int $employee_id, string $ymd, \wpdb $wpdb = null, bool $force = false ): void {
        Services\Session_Service::recalc_session_for( $employee_id, $ymd, $wpdb, $force );
    }





    /** @deprecated Delegate to Shift_Service. */
    public static function resolve_shift_for_date(
        int $employee_id,
        string $ymd,
        array $settings = [],
        \wpdb $wpdb_in = null
    ): ?\stdClass {
        return Services\Shift_Service::resolve_shift_for_date( $employee_id, $ymd, $settings, $wpdb_in );
    }

    /** @deprecated Delegate to Shift_Service. */
    public static function build_segments_from_shift( ?\stdClass $shift, string $ymd ): array {
        return Services\Shift_Service::build_segments_from_shift( $shift, $ymd );
    }

    /** @deprecated Delegate to Session_Service. */
    private static function evaluate_segments(array $segments, array $punchesUTC, int $graceLateMin, int $graceEarlyMin, int $dayEndUtcTs = 0): array {
        return Services\Session_Service::evaluate_segments( $segments, $punchesUTC, $graceLateMin, $graceEarlyMin, $dayEndUtcTs );
    }

    /** @deprecated Delegate to Session_Service. */
    public static function rebuild_sessions_for_date_static( string $date ): void {
        Services\Session_Service::rebuild_sessions_for_date_static( $date );
    }

    /** Return selfie mode resolved by precedence. */
    public static function selfie_mode_for( int $employee_id, $dept_id, array $ctx = [] ): string {
        // Global options
        $opt    = get_option( self::OPT_SETTINGS, [] );
        $policy = is_array( $opt ) ? ( $opt['selfie_policy'] ?? [] ) : [];

        $default_mode = $policy['default'] ?? 'optional';
        $dept_modes   = $policy['by_dept_id'] ?? [];
        $emp_modes    = $policy['by_employee'] ?? [];

        $mode = $default_mode;

        $dept_id = (int) $dept_id;
        if ( $dept_id > 0 && ! empty( $dept_modes[ $dept_id ] ) ) {
            $mode = (string) $dept_modes[ $dept_id ];
        }

        if ( ! empty( $emp_modes[ $employee_id ] ) ) {
            $mode = (string) $emp_modes[ $employee_id ];
        }

        if ( ! empty( $ctx['device_id'] ) ) {
            global $wpdb;
            $dT  = $wpdb->prefix . 'sfs_hr_attendance_devices';
            $dev = $wpdb->get_row( $wpdb->prepare(
                "SELECT selfie_mode FROM {$dT} WHERE id=%d AND active=1",
                (int) $ctx['device_id']
            ) );
            if ( $dev && ! empty( $dev->selfie_mode ) && $dev->selfie_mode !== 'inherit' ) {
                $mode = (string) $dev->selfie_mode;
            }
        }

        $shift_requires = ! empty( $ctx['shift_requires'] );
        if ( $shift_requires && $mode !== 'all' ) {
            $mode = 'all';
        }

        if ( ! in_array( $mode, [ 'never', 'optional', 'in_only', 'in_out', 'all' ], true ) ) {
            $mode = 'optional';
        }

        return $mode;
    }

        /* ---------- Dept helpers (safe, backend-only) ---------- */

    /**
     * Internal: cache of employee table columns so we don't hammer SHOW COLUMNS.
     *
     * @return string[] column names
     */
    private static function employee_table_columns(): array {
        static $cols = null;

        if ( $cols !== null ) {
            return $cols;
        }

        global $wpdb;
        $table     = $wpdb->prefix . 'sfs_hr_employees';
        $table_sql = esc_sql( $table );

        // SHOW COLUMNS is safe here; table name is local, not user input.
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_sql}`", 0 );
        if ( ! is_array( $cols ) ) {
            $cols = [];
        }

        return $cols;
    }

    /**
     * Normalize a string "work location" / dept name to a simple slug.
     * Returns sanitized slug from work location string.
     */
    private static function normalize_work_location( string $raw ): string {
        $s = trim( $raw );
        if ( $s === '' ) {
            return '';
        }
        return sanitize_title( $s );
    }

    /**
     * Fetch department info for an employee in a safe/defensive way.
     *
     * Returns:
     * [
     *   'id'   => int|null,   // dept id if available
     *   'name' => string,     // raw dept name/label
     *   'slug' => string,     // normalized slug
     * ]
     */
    public static function employee_department_info( int $employee_id ): array {
        global $wpdb;

        $table     = $wpdb->prefix . 'sfs_hr_employees';
        $table_sql = esc_sql( $table );
        $cols      = self::employee_table_columns();

        // Build a SELECT that only uses existing columns to avoid "Unknown column" errors.
        $select_cols = [ 'id' ];

        if ( in_array( 'dept_id', $cols, true ) ) {
            $select_cols[] = 'dept_id';
        } elseif ( in_array( 'department_id', $cols, true ) ) {
            $select_cols[] = 'department_id';
        }

        if ( in_array( 'dept', $cols, true ) ) {
            $select_cols[] = 'dept';
        } elseif ( in_array( 'department', $cols, true ) ) {
            $select_cols[] = 'department';
        } elseif ( in_array( 'dept_label', $cols, true ) ) {
            $select_cols[] = 'dept_label';
        }

        if ( in_array( 'work_location', $cols, true ) ) {
            $select_cols[] = 'work_location';
        }

        $select_sql = implode( ', ', array_map( 'esc_sql', $select_cols ) );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$select_sql} FROM `{$table_sql}` WHERE id=%d LIMIT 1",
                $employee_id
            )
        );

        if ( ! $row ) {
            return [
                'id'   => null,
                'name' => '',
                'slug' => '',
            ];
        }

        // Dept id
        $dept_id = null;
        if ( isset( $row->dept_id ) && is_numeric( $row->dept_id ) ) {
            $dept_id = (int) $row->dept_id;
        } elseif ( isset( $row->department_id ) && is_numeric( $row->department_id ) ) {
            $dept_id = (int) $row->department_id;
        }

        // Dept name / label
        $dept_name = '';
        if ( isset( $row->dept ) && $row->dept !== '' ) {
            $dept_name = (string) $row->dept;
        } elseif ( isset( $row->department ) && $row->department !== '' ) {
            $dept_name = (string) $row->department;
        } elseif ( isset( $row->dept_label ) && $row->dept_label !== '' ) {
            $dept_name = (string) $row->dept_label;
        }

        // Slug
        $slug = '';
        if ( isset( $row->work_location ) && $row->work_location !== '' ) {
            $slug = self::normalize_work_location( (string) $row->work_location );
        } elseif ( $dept_name !== '' ) {
            $slug = sanitize_title( $dept_name );
        }

        return [
            'id'   => $dept_id,
            'name' => $dept_name,
            'slug' => $slug,
        ];
    }

    /**
     * Simple helper: best-effort dept slug for attendance logic.
     */
    public static function get_employee_dept_for_attendance( int $employee_id ): string {
        $info = self::employee_department_info( $employee_id );
        return $info['slug'];
    }

    /** Convenience: department label only. */
    private static function employee_department_label( int $employee_id, \wpdb $wpdb ): ?string {
        $info = self::employee_department_info( $employee_id, $wpdb );
        return $info ? ($info['name'] ?: $info['slug']) : null;
    }
}