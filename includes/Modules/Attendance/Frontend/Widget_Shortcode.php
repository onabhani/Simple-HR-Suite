<?php
namespace SFS\HR\Modules\Attendance\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Widget_Shortcode
 *
 * Extracted from AttendanceModule::shortcode_widget().
 * Renders the employee attendance widget (punch in/out, map, selfie).
 */
class Widget_Shortcode {

    /**
     * Render the attendance widget shortcode.
     *
     * @return string HTML output.
     */
    public static function render( array $atts = [] ): string {
    if ( ! is_user_logged_in() ) { return '<div>' . esc_html__( 'Please sign in.', 'sfs-hr' ) . '</div>'; }
    if ( ! current_user_can( 'sfs_hr_attendance_clock_self' ) ) {
        return '<div>' . esc_html__( 'You do not have permission to clock in/out.', 'sfs-hr' ) . '</div>';
    }


  // NEW: immersive flag (like kiosk)
    $atts = shortcode_atts([
        'immersive' => '1', // default ON
    ], $atts, 'sfs_hr_attendance_widget');

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

    $employee_id = \SFS\HR\Modules\Attendance\AttendanceModule::employee_id_from_user( get_current_user_id() );
    if ( $employee_id ) {
        // Local date (site timezone), not UTC
        $today_ymd = wp_date( 'Y-m-d' );

        // Always load shift coordinates first (for logging even when not enforcing)
        $shift = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( $employee_id, $today_ymd );

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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous" defer></script>
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
                  class="sfs-att-btn sfs-att-btn--in"
                  style="display:none" data-i18n-key="clock_in"><?php
                    esc_html_e( 'Clock In', 'sfs-hr' );
          ?></button>
          <button type="button" data-type="out"
                  class="sfs-att-btn sfs-att-btn--out"
                  style="display:none" data-i18n-key="clock_out"><?php
                    esc_html_e( 'Clock Out', 'sfs-hr' );
          ?></button>
          <button type="button" data-type="break_start"
                  class="sfs-att-btn sfs-att-btn--break"
                  style="display:none" data-i18n-key="start_break"><?php
                    esc_html_e( 'Start Break', 'sfs-hr' );
          ?></button>
          <button type="button" data-type="break_end"
                  class="sfs-att-btn sfs-att-btn--breakend"
                  style="display:none" data-i18n-key="end_break"><?php
                    esc_html_e( 'End Break', 'sfs-hr' );
          ?></button>
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
            <button type="button" id="sfs-att-selfie-capture"
                    class="sfs-att-selfie-btn sfs-att-selfie-btn--primary"
                    data-i18n-key="capture_submit">
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
    stale_session_contact_hr: '<?php echo esc_js( __( 'Your previous shift was not closed. Please contact HR.', 'sfs-hr' ) ); ?>',
    stale_session_note: '<?php echo esc_js( __( 'Note: a previous shift was not closed and is marked incomplete.', 'sfs-hr' ) ); ?>',
    // Punch validation messages
    invalid_action: '<?php echo esc_js( __( 'Invalid action.', 'sfs-hr' ) ); ?>',
    end_break_first: '<?php echo esc_js( __( 'You are on break. End the break before clocking out.', 'sfs-hr' ) ); ?>',
    not_clocked_in_yet: '<?php echo esc_js( __( 'You are not clocked in.', 'sfs-hr' ) ); ?>',
    already_clocked_in: '<?php echo esc_js( __( 'Already clocked in.', 'sfs-hr' ) ); ?>',
    break_only_while_in: '<?php echo esc_js( __( 'You can start a break only while clocked in.', 'sfs-hr' ) ); ?>',
    no_active_break: '<?php echo esc_js( __( 'You have no active break to end.', 'sfs-hr' ) ); ?>'
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
                'day_off', 'stale_session_contact_hr', 'stale_session_note', 'target_word'];

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
        const flashEl = document.getElementById('sfs-att-flash-<?php echo esc_js( $inst ); ?>');

        function flash(kind) {
            if (!flashEl) return;
            flashEl.className = 'sfs-flash sfs-flash--' + (kind || 'in');
            void flashEl.offsetWidth; // reflow to restart animation
            flashEl.classList.add('show');
            setTimeout(() => flashEl.classList.remove('show'), 400);
        }
        let _audioCtx = null;
        function getAudioCtx() {
            if (!_audioCtx || _audioCtx.state === 'closed') {
                _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            return _audioCtx;
        }
        window.addEventListener('beforeunload', function() {
            if (_audioCtx && _audioCtx.state !== 'closed') {
                _audioCtx.close();
                _audioCtx = null;
            }
        });
        async function playActionTone(kind) {
            const freq = { in: 920, out: 420, break_start: 680, break_end: 560 }[kind] || 750;
            try {
                const ctx = getAudioCtx();
                if (ctx.state === 'suspended') await ctx.resume();
                const o = ctx.createOscillator(), g = ctx.createGain();
                o.type = 'sine'; o.frequency.value = freq;
                o.connect(g); g.connect(ctx.destination);
                g.gain.value = 0.25;
                o.start();
                g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.22);
                setTimeout(() => { o.stop(); }, 260);
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


const clockEl = document.getElementById('sfs-att-clock-<?php echo esc_js( $inst ); ?>');
const dateEl  = document.getElementById('sfs-att-date-<?php echo esc_js( $inst ); ?>');

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
        var progressBar = document.getElementById('sfs-att-progress-bar-<?php echo esc_js( $inst ); ?>');
        var workedEl    = document.getElementById('sfs-att-worked-<?php echo esc_js( $inst ); ?>');
        var targetEl    = document.getElementById('sfs-att-target-<?php echo esc_js( $inst ); ?>');
        var progressWrap = document.getElementById('sfs-att-progress-<?php echo esc_js( $inst ); ?>');
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
        var punchListEl = document.getElementById('sfs-att-punch-list-<?php echo esc_js( $inst ); ?>');
        var punchIcons = {
            'in':          '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
            'out':         '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 21 3 21 3 15"/><line x1="14" y1="10" x2="3" y2="21"/></svg>',
            'break_start': '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>',
            'break_end':   '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>'
        };

        function escapeHtml(s) {
            if (typeof s !== 'string') return '';
            return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }

        var validPunchTypes = ['in','out','break_start','break_end'];

        function updatePunchHistory(punches) {
            if (!punchListEl) return;
            if (!punches || punches.length === 0) {
                punchListEl.textContent = '';
                var emptyDiv = document.createElement('div');
                emptyDiv.style.cssText = 'text-align:center;color:#9ca3af;font-size:13px;padding:12px 0;';
                emptyDiv.textContent = i18n.no_activity_yet || 'No activity yet';
                punchListEl.appendChild(emptyDiv);
                return;
            }
            punchListEl.textContent = '';
            for (var i = 0; i < punches.length; i++) {
                var p = punches[i];
                var safeType = validPunchTypes.indexOf(p.type) !== -1 ? p.type : 'in';
                var label = punchTypeLabel(p.type);
                var item = document.createElement('div');
                item.className = 'sfs-att-punch-item';

                var badge = document.createElement('div');
                badge.className = 'sfs-att-punch-badge sfs-att-punch-badge--' + safeType;
                if (punchIcons[safeType]) badge.innerHTML = punchIcons[safeType];
                item.appendChild(badge);

                var labelEl = document.createElement('span');
                labelEl.className = 'sfs-att-punch-label';
                labelEl.textContent = label;
                item.appendChild(labelEl);

                var timeEl = document.createElement('span');
                timeEl.className = 'sfs-att-punch-time';
                timeEl.textContent = p.time || '';
                item.appendChild(timeEl);

                punchListEl.appendChild(item);
            }
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
                    setStat(i18n.stale_session_note || j.stale_session_msg, 'warning');
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
                if (selfieStatus) selfieStatus.textContent = label + ' — ' + (i18n.ready_capture_submit || 'Ready');
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

            // Timeout: 30s for selfie uploads (large payload), 15s otherwise
            var punchTimeout = selfieBlob ? 30000 : 15000;
            var punchCtrl = new AbortController();
            var punchTimer = setTimeout(function(){ punchCtrl.abort(); }, punchTimeout);

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
                        body: fd,
                        signal: punchCtrl.signal
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
                        body: fd,
                        signal: punchCtrl.signal
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
                        body: JSON.stringify(payload),
                        signal: punchCtrl.signal
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
                var errMsg = (e.name === 'AbortError')
                    ? (i18n.error_prefix + ' ' + (i18n.request_timed_out || 'Request timed out. Please try again.'))
                    : (i18n.error_prefix + ' ' + e.message);
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
            } finally {
                clearTimeout(punchTimer);
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
                let msg = i18n.invalid_action || 'Invalid action.';
                if (type==='out' && state==='break')        msg = i18n.end_break_first || 'You are on break. End the break before clocking out.';
                else if (type==='out' && state!=='in')      msg = i18n.not_clocked_in_yet || 'You are not clocked in.';
                else if (type==='in'  && state!=='idle')    msg = i18n.already_clocked_in || 'Already clocked in.';
                else if (type==='break_start' && state!=='in')  msg = i18n.break_only_while_in || 'You can start a break only while clocked in.';
                else if (type==='break_end'   && state!=='break')msg = i18n.no_active_break || 'You have no active break to end.';
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
        var _mapRetries = 0;

        function initMap() {
            if (typeof L === 'undefined') {
                if (++_mapRetries < 25) setTimeout(initMap, 200);
                else console.warn('[SFS HR] Leaflet failed to load after 5 s');
                return;
            }

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

        var _geoWatchId = null;
        function updateUserPos() {
            if (!navigator.geolocation) return;
            _geoWatchId = navigator.geolocation.watchPosition(function(pos) {
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
        window.addEventListener('beforeunload', function() {
            if (_geoWatchId !== null) {
                navigator.geolocation.clearWatch(_geoWatchId);
                _geoWatchId = null;
            }
        });

        // Handle container resize
        var _resizeTimer;
        window.addEventListener('resize', function(){ clearTimeout(_resizeTimer); _resizeTimer = setTimeout(function(){ if (map) map.invalidateSize(); }, 150); });

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
}
