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
require_once __DIR__ . '/Admin/class-admin-pages.php';
require_once __DIR__ . '/Rest/class-attendance-admin-rest.php';
require_once __DIR__ . '/Rest/class-attendance-rest.php';
require_once __DIR__ . '/Rest/class-early-leave-rest.php';

class AttendanceModule {

    const OPT_SETTINGS = 'sfs_hr_attendance_settings';

    /**
     * Get the current attendance period boundaries based on configured settings.
     *
     * @param string $reference_date Optional Y-m-d date to calculate around (defaults to today).
     * @return array{start: string, end: string} Y-m-d formatted start and end dates.
     */
    public static function get_current_period( string $reference_date = '' ): array {
        $opt        = get_option( self::OPT_SETTINGS, [] );
        $type       = $opt['period_type'] ?? 'full_month';
        $start_day  = isset( $opt['period_start_day'] ) ? (int) $opt['period_start_day'] : 1;

        if ( empty( $reference_date ) ) {
            $reference_date = current_time( 'Y-m-d' );
        }

        $ref_ts = strtotime( $reference_date );
        $year   = (int) date( 'Y', $ref_ts );
        $month  = (int) date( 'n', $ref_ts );
        $day    = (int) date( 'j', $ref_ts );

        if ( $type === 'custom' && $start_day > 1 ) {
            if ( $day >= $start_day ) {
                // Period starts this month
                $start = sprintf( '%04d-%02d-%02d', $year, $month, $start_day );
                // Ends on (start_day - 1) of next month
                $next  = mktime( 0, 0, 0, $month + 1, $start_day - 1, $year );
                $end   = date( 'Y-m-d', $next );
            } else {
                // Period started last month
                $prev  = mktime( 0, 0, 0, $month - 1, $start_day, $year );
                $start = date( 'Y-m-d', $prev );
                // Ends on (start_day - 1) of this month
                $end   = sprintf( '%04d-%02d-%02d', $year, $month, $start_day - 1 );
            }
        } else {
            // Full calendar month
            $start = sprintf( '%04d-%02d-01', $year, $month );
            $end   = date( 'Y-m-t', $ref_ts );
        }

        return [ 'start' => $start, 'end' => $end ];
    }

    public function hooks(): void {
        add_action('admin_init', [ $this, 'maybe_install' ]);

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
    
    
    
    
    
    ob_start(); ?>
    <?php if ( $geo_lat && $geo_lng ) : ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
    <?php endif; ?>
    <?php if ( $immersive ): ?>
      <script>
        document.documentElement.classList.add('sfs-att-immersive');
        document.body.classList.add('sfs-att-immersive');
      </script>
      <div class="sfs-att-veil" role="application" aria-label="<?php esc_attr_e( 'Self Attendance', 'sfs-hr' ); ?>">
    <?php endif; ?>

    <?php
    // --- Geo for self attendance: always collect location, respect policy for enforcement ---
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


?>

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


      <div class="sfs-att-shell">

        <aside class="sfs-att-left">
  <div>
    <div class="sfs-brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
    <div id="sfs-att-date-<?php echo esc_attr( $inst ); ?>"  class="sfs-att-date">—</div>
    <div id="sfs-att-clock-<?php echo esc_attr( $inst ); ?>" class="sfs-att-clock">--:--</div>
  </div>
  
</aside>



        <main class="sfs-att-right">
          <div class="sfs-att-card">

            <div class="sfs-att-header">
              <div>
                <div class="sfs-att-greet" id="sfs-att-greet">
                  <span data-i18n-key="hello"><?php esc_html_e( 'Hello', 'sfs-hr' ); ?></span>, <?php echo esc_html( $user_name ); ?>
                </div>
                <div class="sfs-att-sub" data-i18n-key="self_attendance"><?php esc_html_e( 'Self attendance', 'sfs-hr' ); ?></div>
              </div>
              <div class="sfs-att-state">
                <span class="sfs-att-state-label" data-i18n-key="current"><?php esc_html_e( 'Current', 'sfs-hr' ); ?></span>
                <span id="sfs-att-state-chip"
                      class="sfs-att-chip sfs-att-chip--idle" data-i18n-key="checking"><?php esc_html_e( 'Checking…', 'sfs-hr' ); ?></span>
              </div>
            </div>

            <div id="sfs-att-status" class="sfs-att-statusline" data-i18n-key="loading"><?php esc_html_e( 'Loading...', 'sfs-hr' ); ?></div>

            <div class="sfs-att-actions" id="sfs-att-actions">
              <button type="button" data-type="in"
                      class="button button-primary" style="display:none" data-i18n-key="clock_in"><?php esc_html_e( 'Clock In', 'sfs-hr' ); ?></button>
              <button type="button" data-type="out"
                      class="button" style="display:none" data-i18n-key="clock_out"><?php esc_html_e( 'Clock Out', 'sfs-hr' ); ?></button>
              <button type="button" data-type="break_start"
                      class="button" style="display:none" data-i18n-key="start_break"><?php esc_html_e( 'Start Break', 'sfs-hr' ); ?></button>
              <button type="button" data-type="break_end"
                      class="button" style="display:none" data-i18n-key="end_break"><?php esc_html_e( 'End Break', 'sfs-hr' ); ?></button>
            </div>


            <?php if ( $geo_lat && $geo_lng ) : ?>
            <!-- Mini-map: geofence + live position -->
            <div id="sfs-att-map-<?php echo $inst; ?>" style="height:180px;border-radius:10px;margin-top:10px;z-index:1;"></div>
            <?php endif; ?>

            <!-- Success flash overlay -->
            <div class="sfs-flash" id="sfs-att-flash-<?php echo $inst; ?>"></div>

            <!-- Selfie overlay (fullscreen on mobile for better UX) -->
            <div id="sfs-att-selfie-panel" class="sfs-att-selfie-overlay">
              <div class="sfs-att-selfie-overlay__inner">
                <div class="sfs-att-selfie-overlay__status" id="sfs-att-selfie-status"></div>
                <div class="sfs-att-selfie-overlay__viewport">
                  <video id="sfs-att-selfie-video" autoplay playsinline muted></video>
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
            <div id="sfs-att-selfie-wrap" style="display:none;margin-top:10px;">
              <input type="file" id="sfs-att-selfie"
                     accept="image/*" capture="user"
                     style="display:block"/>
              <small style="display:block;color:#646970;margin-top:4px;font-size:11px;" data-i18n-key="camera_fallback_hint">
                <?php esc_html_e( 'Your device does not support live camera preview. Capture a selfie, then the system will submit it.', 'sfs-hr' ); ?>
              </small>
            </div>

            <small id="sfs-att-hint" class="sfs-att-hint" data-i18n-key="selfie_required_hint">
              <?php esc_html_e( 'Selfie required for this shift. Location may also be required.', 'sfs-hr' ); ?>
            </small>

          </div><!-- .sfs-att-card -->

          <?php
          // Find the HR profile page URL
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
              // Fallback: try common slugs
              $page = get_page_by_path( 'my-profile' );
              if ( ! $page ) {
                  $page = get_page_by_path( 'hr-profile' );
              }
              if ( $page ) {
                  $profile_url = get_permalink( $page->ID );
              }
          }
          if ( ! empty( $profile_url ) ) : ?>
          <a href="<?php echo esc_url( $profile_url ); ?>" class="sfs-att-back-link" data-i18n-key="back_to_profile">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
            <?php esc_html_e( 'Back to My HR Profile', 'sfs-hr' ); ?>
          </a>
          <?php endif; ?>

        </main>

      </div><!-- .sfs-att-shell -->
    </div><!-- .sfs-att-app -->

    <?php if ( $immersive ): ?>
      </div><!-- .sfs-att-veil -->
    <?php endif; ?>

    <style>
      /* Root layout */
      #<?php echo esc_attr( $root_id ); ?>.sfs-att-app{
        --sfs-teal:#0f4c5c;
        --sfs-surface:#ffffff;
        --sfs-border:#e5e7eb;
        min-height:100dvh;
        width:100%;
        margin:0;
        background:linear-gradient(90deg,var(--sfs-teal) 0 36%, var(--sfs-surface) 36% 100%);
        display:block;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-shell{
        display:grid;
        grid-template-columns:minmax(260px,36vw) 1fr;
        min-height:100dvh;
      }
      /* Left header – match kiosk look */
#<?php echo $root_id; ?> .sfs-att-left{
  color:#fff;
  padding:32px 28px;
  display:flex;
  flex-direction:column;
  justify-content:space-between;
}

/* same brand style as kiosk */
#<?php echo $root_id; ?> .sfs-brand{
  font-weight:600;
  letter-spacing:.08em;
  font-size:13px;
  text-transform:uppercase;
  margin-bottom:18px;
}

#<?php echo $root_id; ?> .sfs-att-date{
  opacity:.95;
  font-size:clamp(14px,2.2vw,18px);
  margin-bottom:8px;
}

#<?php echo $root_id; ?> .sfs-att-clock{
  font-weight:800;
  letter-spacing:-.02em;
  font-size:clamp(42px,8vw,88px); /* same as kiosk */
  line-height:1;
}

#<?php echo $root_id; ?> .sfs-device{
  opacity:.9;
  font-size:clamp(12px,1.4vw,14px);
  margin-top:10px;
}

/* Right side */
#<?php echo $root_id; ?> .sfs-att-right{
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:flex-start;
  padding:24px 16px 32px;
}

#<?php echo $root_id; ?> .sfs-att-main{
  width:100%;
  max-width:520px;
}




      /* Card + content */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-card{
        max-width:520px;
        width:100%;
        padding:16px;
        border:1px solid #c3c4c7;
        border-radius:12px;
        background:#fff;
        box-shadow:0 4px 14px rgba(15,23,42,0.06);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-header{
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        margin-bottom:10px;
        gap:8px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-greet{
        font-weight:600;
        font-size:15px;
        color:#111827;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-sub{
        font-size:12px;
        color:#6b7280;
        margin-top:2px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-state{
        text-align:right;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-state-label{
        display:block;
        font-size:11px;
        text-transform:uppercase;
        letter-spacing:.06em;
        color:#9ca3af;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-chip{
        display:inline-block;
        margin-top:2px;
        padding:4px 10px;
        border-radius:999px;
        font-size:12px;
        line-height:1.2;
        border:1px solid #e5e7eb;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-chip--idle{
        background:#f3f4f6;
        color:#111827;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-chip--in{
        background:#e8f7ee;
        color:#166534;
        border-color:#b7dfc8;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-chip--out{
        background:#feecec;
        color:#b91c1c;
        border-color:#fecaca;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-chip--break{
        background:#fff4d6;
        color:#92400e;
        border-color:#fde68a;
      }

      #<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline{
  margin:0 0 10px;
  font-size:13px;
}

/* normal / idle */
#<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="idle"],
#<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline:not([data-mode]){
  color:#374151;
  background:transparent;
  border:none;
  padding:0;
}

/* busy */
#<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="busy"]{
  color:#1d4ed8;
  background:#eff6ff;
  border:1px solid #bfdbfe;
  border-radius:8px;
  padding:8px 10px;
}

/* error (outside area, etc.) */
#<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="error"]{
  color:#b91c1c;
  background:#fee2e2;
  border:1px solid #fecaca;
  border-radius:8px;
  padding:8px 10px;
  font-weight:500;
}

/* success status — color coded per action */
#<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="in"]{
  color:#166534; background:#dcfce7; border:1px solid #bbf7d0;
  border-radius:8px; padding:8px 10px; font-weight:600;
}
#<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="out"]{
  color:#b91c1c; background:#fee2e2; border:1px solid #fecaca;
  border-radius:8px; padding:8px 10px; font-weight:600;
}
#<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="break_start"]{
  color:#92400e; background:#fef3c7; border:1px solid #fde68a;
  border-radius:8px; padding:8px 10px; font-weight:600;
}
#<?php echo esc_attr( $root_id ); ?> .sfs-att-statusline[data-mode="break_end"]{
  color:#1e40af; background:#dbeafe; border:1px solid #bfdbfe;
  border-radius:8px; padding:8px 10px; font-weight:600;
}

      #<?php echo esc_attr( $root_id ); ?> .sfs-att-actions{
        display:flex;
        flex-direction:column;
        gap:8px;
        margin-top:8px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-actions .button{
        width:100%;
        justify-content:center;
        padding:10px 14px;
        font-size:14px;
        border-radius:10px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-actions .button[data-type="in"]{
        background:#e8f7ee;
        border-color:#b7dfc8;
        color:#111 !important;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-actions .button[data-type="out"]{
        background:#feecec;
        border-color:#fecaca;
        color:#111 !important;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-actions .button[data-type="break_start"]{
        background:#fff4d6;
        border-color:#fde68a;
        color:#111 !important;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-actions .button[data-type="break_end"]{
        background:#eef4ff;
        border-color:#bfdbfe;
        color:#111 !important;
      }

      /* ---- Selfie overlay (fullscreen on mobile) ---- */
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay{
        display:none;
        position:fixed;
        inset:0;
        z-index:999999;
        background:rgba(0,0,0,0.92);
        padding:0;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__inner{
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        height:100%;
        width:100%;
        max-width:480px;
        margin:0 auto;
        padding:16px;
        box-sizing:border-box;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__status{
        color:#fff;
        font-size:15px;
        font-weight:600;
        text-align:center;
        min-height:24px;
        margin-bottom:12px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__viewport{
        position:relative;
        width:100%;
        max-width:340px;
        aspect-ratio:1/1;
        border-radius:16px;
        overflow:hidden;
        border:3px solid rgba(255,255,255,0.25);
        background:#111;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__viewport video{
        width:100%;
        height:100%;
        object-fit:cover;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__hint{
        display:block;
        color:rgba(255,255,255,0.6);
        font-size:12px;
        text-align:center;
        margin-top:10px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-overlay__actions{
        display:flex;
        gap:12px;
        margin-top:16px;
        width:100%;
        max-width:340px;
      }
      .sfs-att-selfie-btn{
        flex:1;
        padding:14px 12px;
        font-size:15px;
        font-weight:600;
        border:none;
        border-radius:12px;
        cursor:pointer;
        transition:opacity 0.15s;
      }
      .sfs-att-selfie-btn:disabled{
        opacity:0.5;
        cursor:not-allowed;
      }
      .sfs-att-selfie-btn--primary{
        background:#22c55e;
        color:#fff;
      }
      .sfs-att-selfie-btn--primary:active{
        background:#16a34a;
      }
      .sfs-att-selfie-btn--cancel{
        background:rgba(255,255,255,0.15);
        color:#fff;
      }
      .sfs-att-selfie-btn--cancel:active{
        background:rgba(255,255,255,0.25);
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-hint{
        display:block;
        margin-top:10px;
        font-size:11px;
        color:#6b7280;
      }

      #<?php echo esc_attr( $root_id ); ?> .sfs-att-back-link{
        display:inline-flex;
        align-items:center;
        gap:4px;
        margin-top:16px;
        padding:8px 14px;
        font-size:13px;
        color:#0f4c5c;
        text-decoration:none;
        background:#f0fdfa;
        border:1px solid #99f6e4;
        border-radius:8px;
        transition:background 0.15s, border-color 0.15s;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-back-link:hover{
        background:#ccfbf1;
        border-color:#5eead4;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-back-link .dashicons{
        font-size:16px;
        width:16px;
        height:16px;
      }

      /* Immersive veil (same idea as kiosk) */
      .sfs-att-veil{
        position:fixed;
        inset:0;
        background:#ffffff;
        z-index:2147483000;
        overflow:auto;
      }

      html.sfs-att-immersive,
      body.sfs-att-immersive{
        overflow:hidden;
      }

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
      body.sfs-att-immersive .elementor-location-footer{
        display:none !important;
      }
      body.sfs-att-immersive #wpadminbar{
        display:none !important;
      }

      /* Mobile stacking */
      @media (max-width:960px){
        #<?php echo esc_attr( $root_id ); ?> .sfs-att-shell{
          grid-template-columns:1fr;
        }
        #<?php echo esc_attr( $root_id ); ?>.sfs-att-app{
          background:linear-gradient(180deg,var(--sfs-teal) 0 200px, var(--sfs-surface) 200px 100%);
        }
        #<?php echo esc_attr( $root_id ); ?> .sfs-att-left{
          min-height:180px;
          padding:20px 16px 40px;
        }
        #<?php echo esc_attr( $root_id ); ?> .sfs-att-right{
          padding:0 16px 24px;
          margin-top:-32px;
        }
        #<?php echo esc_attr( $root_id ); ?> .sfs-att-card{
          box-shadow:0 6px 20px rgba(15,23,42,0.10);
        }
      }

      /* Success flash overlay */
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
    request_timed_out: '<?php echo esc_js( __( 'Request timed out', 'sfs-hr' ) ); ?>'
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
                'please_wait', 'seconds_short'];

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
        dateEl.textContent = d.toLocaleDateString(undefined, {
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

                async function getGeo(punchType, useCache = false){
            // Return cached geo if available and fresh (within 30s)
            if (useCache && cachedGeo && (Date.now() - cachedGeo.ts < 30000)) {
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
                setStat(j.label || i18n.ready, 'idle');

                // Show/hide buttons based on allowed transitions
                if (actionsWrap) {
                    actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                        const t = btn.getAttribute('data-type');
                        const ok = !!allowed[t];
                        btn.style.display = ok ? '' : 'none';
                        btn.disabled = !ok;
                    });
                }

                // Selfie hint
                if (requiresSelfie) {
                    hint && (hint.textContent = i18n.selfie_required_hint);
                } else {
                    hint && (hint.textContent = i18n.location_hint);
                }

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
                }
                return;
            }

            if (!selfiePanel || !selfieVideo) {
                setStat(i18n.camera_ui_not_available, 'error');
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
                        btn.disabled = !allowed[t];
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
                        btn.disabled = !allowed[t];
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

            // Refresh status only if stale (>10s) to avoid unnecessary round-trip
            if (Date.now() - lastRefreshAt > 10000) {
                setStat(i18n.checking_status, 'busy');
                await refresh();
            }

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
                        btn.disabled = !allowed[t];
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
                        btn.disabled = !allowed[t];
                    });
                }
                return;
            }

            // ---- Pre-flight: cooldown check (before geo/camera) ----
            const isSameType = (cooldownType === type);
            const cdRemaining = isSameType ? cooldownSec : cooldownCrossSec;
            if (cdRemaining > 0) {
                setStat(i18n.error_prefix + ' ' + (i18n.please_wait || 'Please wait') + ' ' + cdRemaining + (i18n.seconds_short || 's'), 'error');
                punchInProgress = false;
                if (actionsWrap) {
                    actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                        const t = btn.getAttribute('data-type');
                        btn.disabled = !allowed[t];
                    });
                }
                return;
            }

            if (needsSelfieForType(type)) {
                // Start geo validation and camera open in PARALLEL for speed.
                // If cached geo is available, validation is instant; otherwise GPS
                // runs while the camera warms up (user still sees the viewfinder).
                setStat(i18n.validating, 'busy');
                const geoPromise = getGeo(type).catch(e => e);
                const cameraPromise = startSelfie(type);
                const geoResult = await geoPromise;

                if (geoResult instanceof Error) {
                    // geo blocked → close camera and abort
                    stopSelfiePreview();
                    punchInProgress = false;
                    if (actionsWrap) {
                        actionsWrap.querySelectorAll('button[data-type]').forEach(btn=>{
                            const t = btn.getAttribute('data-type');
                            btn.disabled = !allowed[t];
                        });
                    }
                    return;
                }

                await cameraPromise;
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
                            btn.disabled = !allowed[t];
                        });
                    }
                    return;
                }
                await doPunch(capturedType, blob);
                stopSelfiePreview();
                if (selfieCapture) selfieCapture.disabled = false;
            }, 'image/jpeg', 0.9);
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
                    btn.disabled = !allowed[t];
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
        });

        // Initial load
        refresh();
    })();
    </script>
    <?php if ( $geo_lat && $geo_lng ) : ?>
    <script>
    (function(){
        var mapEl = document.getElementById('sfs-att-map-<?php echo esc_js( $inst ); ?>');
        if (!mapEl) return;
        var geoLat = <?php echo (float) $geo_lat; ?>;
        var geoLng = <?php echo (float) $geo_lng; ?>;
        var geoRad = <?php echo (int) ( $geo_radius ?: 150 ); ?>;
        var map, circle, userMarker;

        function initMap() {
            if (typeof L === 'undefined') { setTimeout(initMap, 200); return; }
            map = L.map(mapEl, { zoomControl: false, attributionControl: false }).setView([geoLat, geoLng], 16);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            circle = L.circle([geoLat, geoLng], { radius: geoRad, color: '#0f4c5c', fillColor: '#0f4c5c', fillOpacity: 0.12, weight: 2 }).addTo(map);
            L.marker([geoLat, geoLng]).addTo(map).bindPopup('<?php echo esc_js( __( 'Workplace', 'sfs-hr' ) ); ?>');
            map.fitBounds(circle.getBounds().pad(0.15));
            updateUserPos();
        }

        function updateUserPos() {
            if (!navigator.geolocation) return;
            navigator.geolocation.watchPosition(function(pos) {
                var ll = [pos.coords.latitude, pos.coords.longitude];
                if (!userMarker) {
                    userMarker = L.circleMarker(ll, { radius: 7, color: '#fff', fillColor: '#2563eb', fillOpacity: 1, weight: 2 }).addTo(map);
                } else {
                    userMarker.setLatLng(ll);
                }
            }, function(){}, { enableHighAccuracy: true, maximumAge: 10000 });
        }

        initMap();
    })();
    </script>
    <?php endif; ?>
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

  <div class="sfs-kiosk-shell">

    <aside class="sfs-kiosk-left">
      <div>
          <div class="sfs-brand"><?php echo esc_html( get_bloginfo('name') ); ?></div>
        <div id="sfs-kiosk-date-<?php echo $inst; ?>" class="sfs-date">—</div>
        <div id="sfs-kiosk-clock-<?php echo $inst; ?>" class="sfs-clock">--:--</div>
      </div>
      <div class="sfs-device"><?php echo esc_html($device['label'] ?? 'Kiosk'); ?></div>
    </aside>

    <main class="sfs-kiosk-right">
      <h2 class="sfs-title"><?php esc_html_e( 'Attendance Kiosk', 'sfs-hr' ); ?></h2>
      <h1 id="sfs-greet-<?php echo $inst; ?>" class="sfs-greet"><?php esc_html_e( 'Good day!', 'sfs-hr' ); ?></h1>


      <!-- hero -->
      <h2 class="sfs-title sr-only"><?php esc_html_e( 'Attendance Kiosk', 'sfs-hr' ); ?></h2>
<div class="sfs-statusbar">
  <span id="sfs-status-dot-<?php echo $inst; ?>" class="sfs-dot sfs-dot--idle"></span>
  <span id="sfs-status-text-<?php echo $inst; ?>"><?php esc_html_e( 'Ready', 'sfs-hr' ); ?></span>
</div>


      <!-- lane -->
<div id="sfs-kiosk-lane-<?php echo $inst; ?>" style="gap:8px;align-items:center;margin:10px 0;">
        <strong id="sfs-kiosk-lane-label-<?php echo $inst; ?>" style="min-width:110px;"><?php esc_html_e( 'Current:', 'sfs-hr' ); ?></strong>
        <span id="sfs-kiosk-lane-chip-<?php echo $inst; ?>" class="sfs-chip sfs-chip--in"><?php esc_html_e( 'Clock In', 'sfs-hr' ); ?></span>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-left:10px">
          <button type="button" data-action="in"          class="button sfs-lane-btn button-primary"><?php esc_html_e( 'Clock In', 'sfs-hr' ); ?></button>
          <button type="button" data-action="out"         class="button sfs-lane-btn"><?php esc_html_e( 'Clock Out', 'sfs-hr' ); ?></button>
        </div>
      </div>




<!-- QR/camera panels -->
<div id="sfs-kiosk-camwrap-<?php echo $inst; ?>" class="sfs-camwrap" style="margin:10px 0;">
  <video id="sfs-kiosk-qr-video-<?php echo $inst; ?>"
         autoplay playsinline webkit-playsinline muted
         style="width:100%;border-radius:8px;background:#000"></video>

  <!-- Hidden square canvas only for snapshot encoding -->
  <canvas id="sfs-kiosk-selfie-<?php echo $inst; ?>" width="480" height="480" hidden></canvas>

  <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
    <button id="sfs-kiosk-qr-exit-<?php echo $inst; ?>" type="button" class="button button-secondary"><?php esc_html_e( 'Exit', 'sfs-hr' ); ?></button>
    <button id="sfs-kiosk-qr-stop-<?php echo $inst; ?>" type="button" class="button" hidden><?php esc_html_e( 'Stop Camera', 'sfs-hr' ); ?></button>
    <span id="sfs-kiosk-qr-status-<?php echo $inst; ?>" style="font-size:12px;color:#646970;"></span>
  </div>
</div>

      <!-- Flashlight overlay for success feedback -->
      <div id="sfs-kiosk-flash-<?php echo $inst; ?>" class="sfs-flash"></div>


    </main>
    <?php if ( $immersive ): ?>
  </div> <!-- .sfs-kiosk-veil -->
<?php endif; ?>

  </div>
</div>


<style>
/* ==== Scope to this kiosk instance ==== */
#<?php echo $root_id; ?>.sfs-kiosk-app{
  --sfs-teal:#0f4c5c;
  --sfs-surface:#ffffff;
  --sfs-border:#e5e7eb;
  position:relative;
  min-height:100dvh; width:100%; margin:0;
  background:linear-gradient(90deg,var(--sfs-teal) 0 36%, var(--sfs-surface) 36% 100%);
}
#<?php echo $root_id; ?> .sfs-kiosk-shell{
  display:grid; grid-template-columns:minmax(260px,36vw) 1fr; min-height:100dvh;
}
#<?php echo $root_id; ?> .sfs-kiosk-left{
  color:#fff; padding:32px 28px; display:flex; flex-direction:column; justify-content:space-between; background:transparent;
}
#<?php echo $root_id; ?> .sfs-date{ opacity:.95; font-size:clamp(14px,2.2vw,18px); margin-bottom:8px; }
#<?php echo $root_id; ?> .sfs-clock{ font-weight:800; letter-spacing:-.02em; font-size:clamp(42px,8vw,88px); line-height:1; }
#<?php echo $root_id; ?> .sfs-device{ opacity:.9; font-size:clamp(12px,1.4vw,14px); }
#<?php echo $root_id; ?> .sfs-kiosk-right{
  background:var(--sfs-surface);
  padding:clamp(16px,3vw,32px);
  display:flex; flex-direction:column; align-items:center;
}
#<?php echo $root_id; ?> .sfs-title{ margin:0 0 10px; font-size:clamp(18px,2.4vw,28px); }

/* Greeting headline */
#<?php echo $root_id; ?> .sfs-greet{
  margin: clamp(8px, 2vw, 24px) 0 clamp(14px, 4vw, 40px);
  font-weight: 800;
  font-size: clamp(28px, 6vw, 72px);
  line-height: 1.1;
  text-align: center;
}

/* Lane container + statusbar blocks */
#<?php echo $root_id; ?> #sfs-kiosk-lane-<?php echo $inst; ?>{
  width:100%;
  max-width:1024px;
  margin:6px auto 12px;
  display:flex;
  gap:8px;
  align-items:center;
}


/* Statusbar visual presets (kept) */
#<?php echo $root_id; ?> .sfs-statusbar{
  display:flex; align-items:center; gap:10px;
  width:100%; max-width:1024px; margin:8px auto 12px;
  padding:10px 12px; border:1px solid var(--sfs-border);
  border-radius:10px; background:#fff;
}
#<?php echo $root_id; ?> .sfs-dot{ width:10px; height:10px; border-radius:999px; display:inline-block; }
#<?php echo $root_id; ?> .sfs-dot--idle{ background:#d1d5db; }
#<?php echo $root_id; ?> .sfs-dot--in{ background:#16a34a; }
#<?php echo $root_id; ?> .sfs-dot--out{ background:#ef4444; }
#<?php echo $root_id; ?> .sfs-dot--break_start{ background:#f59e0b; }
#<?php echo $root_id; ?> .sfs-dot--break_end{ background:#3b82f6; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="idle"]        { border-color:#e5e7eb; background:#fff; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="ok"]          { border-color:#b7dfc8; background:#f6fff9; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="busy"]        { border-color:#bfdbfe; background:#eff6ff; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="error"]       { border-color:#fecaca; background:#fff7f7; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="in"]          { border-color:#b7dfc8; background:#f6fff9; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="out"]         { border-color:#fecaca; background:#fff7f7; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="break_start"] { border-color:#fde68a; background:#fffbeb; }
#<?php echo $root_id; ?> .sfs-statusbar[data-mode="break_end"]   { border-color:#bfdbfe; background:#eff6ff; }

/* Lane chip (Current:) */
#<?php echo $root_id; ?> .sfs-chip{
  display:inline-block; padding:6px 10px; border-radius:999px;
  font-size:13px; line-height:1; background:#f3f4f6; border:1px solid #e5e7eb; color:#111827;
}
#<?php echo $root_id; ?> .sfs-chip--idle{ background:#f3f4f6; border-color:#e5e7eb; }
#<?php echo $root_id; ?> .sfs-chip--in{ background:#e8f7ee; border-color:#b7dfc8; }
#<?php echo $root_id; ?> .sfs-chip--out{ background:#feecec; border-color:#fecaca; }
#<?php echo $root_id; ?> .sfs-chip--break_start{ background:#fff4d6; border-color:#fde68a; }
#<?php echo $root_id; ?> .sfs-chip--break_end{ background:#eef4ff; border-color:#bfdbfe; }


/* Hide label + chip in menu: menu shows only 4 big buttons */
#<?php echo $root_id; ?>[data-view="menu"] #sfs-kiosk-lane-label-<?php echo $inst; ?>,
#<?php echo $root_id; ?>[data-view="menu"] #sfs-kiosk-lane-chip-<?php echo $inst; ?>{
  display:none;
}

/* Buttons — base (also overrides WP .button) */
#<?php echo $root_id; ?> [data-action]{
  padding:clamp(10px,1.4vw,14px) clamp(14px,2.4vw,22px);
  font-size:clamp(14px,1.8vw,18px);
  border-radius:10px;
  color:#111 !important;              /* <<< black text */
  text-shadow:none !important;
}

/* Menu vs Scan visibility */
#<?php echo $root_id; ?>[data-view="menu"]  #sfs-kiosk-lane-<?php echo $inst; ?>{
  display:flex;
}
#<?php echo $root_id; ?>[data-view="scan"]  #sfs-kiosk-lane-<?php echo $inst; ?>{
  display:none !important; /* override any inline display */
}

#<?php echo $root_id; ?>[data-view="menu"]  .sfs-camwrap{ display:none; }
#<?php echo $root_id; ?>[data-view="scan"]  .sfs-camwrap{ display:grid; }

#<?php echo $root_id; ?>[data-view="menu"]  .sfs-statusbar{ display:none; }
#<?php echo $root_id; ?>[data-view="scan"]  .sfs-statusbar{ display:flex; }


/* Big, centered action buttons in menu */
#<?php echo $root_id; ?>[data-view="menu"] .sfs-kiosk-right{ align-items:center; }
#<?php echo $root_id; ?>[data-view="menu"] #sfs-kiosk-lane-<?php echo $inst; ?>{
  flex-direction:column; align-items:center;
  gap:clamp(12px,2.4vw,22px); margin-top:clamp(6px,4vw,40px);
}
#<?php echo $root_id; ?>[data-view="menu"] #sfs-kiosk-lane-<?php echo $inst; ?> .sfs-lane-btn{
  width:min(560px,92%);
  padding:clamp(16px,2.2vw,22px) clamp(20px,3vw,28px);
  font-size:clamp(20px,3.2vw,36px);
  border-radius:16px; border:1px solid #e5e7eb; box-shadow:0 2px 0 rgba(0,0,0,.05);
  color:#111 !important;               /* ensure black text on the big buttons */
}
/* Soft color cues per action (menu only) */
#<?php echo $root_id; ?>[data-view="menu"] .sfs-lane-btn[data-action="in"]{          background:#e8f7ee; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-lane-btn[data-action="out"]{         background:#feecec; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-lane-btn[data-action="break_start"]{ background:#fff4d6; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-lane-btn[data-action="break_end"]{   background:#eef4ff; }

/* Time-based suggestion highlighting (±30 min from configured time) */
#<?php echo $root_id; ?>[data-view="menu"] .sfs-lane-btn.button-suggested {
  border: 3px solid #2563eb !important;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2), 0 2px 0 rgba(0,0,0,.05) !important;
  font-weight: 600 !important;
}
#<?php echo $root_id; ?>[data-view="menu"] .sfs-lane-btn.button-suggested[data-action="in"]{ background:#d1fae5; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-lane-btn.button-suggested[data-action="out"]{ background:#fecaca; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-lane-btn.button-suggested[data-action="break_start"]{ background:#fde68a; }
#<?php echo $root_id; ?>[data-view="menu"] .sfs-lane-btn.button-suggested[data-action="break_end"]{ background:#bfdbfe; }

/* Camera / canvas sizing */
#<?php echo $root_id; ?> #sfs-kiosk-camwrap-<?php echo $inst; ?>{ width:100%; max-width:1024px; margin:10px auto; }
#<?php echo $root_id; ?> video{ width:100%; height:auto; border-radius:8px; background:#000; }
#<?php echo $root_id; ?> #sfs-kiosk-canvas-<?php echo $inst; ?>{
  width:100%; height:auto; max-height:56vh; border:1px dashed var(--sfs-border); border-radius:8px; background:#f6f7f7;
}

/* Never show Stop Camera in production */
#<?php echo $root_id; ?> #sfs-kiosk-qr-stop-<?php echo $inst; ?>{ display:none !important; }

/* A11y helper */
#<?php echo $root_id; ?> .sr-only{
  position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap;
}

/* Immersive veil */
.sfs-kiosk-veil{ position:fixed; inset:0; background:#ffffff; z-index:2147483000; overflow:auto; }
html.sfs-kiosk-immersive, body.sfs-kiosk-immersive{ overflow:hidden; }
body.sfs-kiosk-immersive .site-header,
body.sfs-kiosk-immersive header,
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

/* Mobile stacking */
@media (max-width:960px){
  #<?php echo $root_id; ?> .sfs-kiosk-shell{ grid-template-columns:1fr; }
  #<?php echo $root_id; ?>.sfs-kiosk-app{
    background:linear-gradient(180deg,var(--sfs-teal) 0 220px, var(--sfs-surface) 220px 100%);
  }
  #<?php echo $root_id; ?> .sfs-kiosk-left{ min-height:220px; padding:20px 16px; }
}


/* Quick "halo" flash on successful / queued punch */
#<?php echo $root_id; ?>.sfs-kiosk-flash-ok {
  animation: sfs-kiosk-punch-ok 0.28s ease-out;
}

#<?php echo $root_id; ?> .sfs-flash {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.35s ease-out;
  z-index: 9998;
}

#<?php echo $root_id; ?> .sfs-flash.show {
  opacity: 1;
}

#<?php echo $root_id; ?> .sfs-flash--in {
  background: rgba(34, 197, 94, 0.5);
}

#<?php echo $root_id; ?> .sfs-flash--out {
  background: rgba(239, 68, 68, 0.5);
}

#<?php echo $root_id; ?> .sfs-flash--break_start {
  background: rgba(245, 158, 11, 0.5);
}

#<?php echo $root_id; ?> .sfs-flash--break_end {
  background: rgba(59, 130, 246, 0.5);
}

@keyframes sfs-kiosk-punch-ok {
  0%   { box-shadow: 0 0 0 0 rgba(34,197,94,0.85); }
  100% { box-shadow: 0 0 0 32px rgba(34,197,94,0); }
}


</style>





</div>
</div>

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

    // Offline queueing: store punch locally if enabled
    if (OFFLINE_ENABLED && window.sfsHrPwa && window.sfsHrPwa.db) {
      try {
        await window.sfsHrPwa.db.storePunch({
          url: punchUrl,
          nonce: nonce,
          data: { punch_type: type, source: 'kiosk', device: String(DEVICE_ID), employee_scan_token: scanToken }
        });
        dbg('punch queued offline');
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

      // QR elems
      const qrWrap  = document.getElementById('sfs-kiosk-camwrap-<?php echo $inst; ?>');
      const qrVid   = document.getElementById('sfs-kiosk-qr-video-<?php echo $inst; ?>');
      
      const qrStop  = document.getElementById('sfs-kiosk-qr-stop-<?php echo $inst; ?>');
      
const qrExit = document.getElementById('sfs-kiosk-qr-exit-<?php echo $inst; ?>');
qrExit && qrExit.addEventListener('click', () => {
  // show exiting while we stop camera
  setStat(t.exiting||'Exiting…', 'busy');

  // hard stop camera + QR loop
  stopQr();

  // go back to menu view (CSS hides camera + statusbar)
  ROOT.dataset.view = 'menu';

  // reset main status text so it’s clean next time
  const tag = requiresSelfie ? ' — ' + ((window.SFS_ATT_I18N||{}).selfie_required||'selfie required') : '';
  setStat(((window.SFS_ATT_I18N||{}).ready||'Ready') + ' — ' + ((window.SFS_ATT_I18N||{}).action||'action') + ': ' + labelFor(currentAction) + tag, 'idle');
});






const qrStat  = document.getElementById('sfs-kiosk-qr-status-<?php echo $inst; ?>');
      
      let requiresSelfie = false; // <- will be set after status()
      let allowed = {};
      let state   = 'idle';
      let stream = null;
      let lastBlob = null;
      let scannedEmpId = null;
      let employeeScanToken = null;

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
  flash(currentAction);       // full-screen color flash

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

function setMode(next) {
  view = next === 'scan' ? 'scan' : 'menu';
  ROOT.dataset.view = view;

  if (view === 'menu') {
    stopQr();                              // hard stop camera
    clearIdle();
    setStat(t.ready_choose_action||'Ready — choose an action', 'idle');
  } else {
    // scan
    kickScanner();                         // open camera (no auto-return per scan)
    touchActivity();                       // arm idle countdown
    setStat(t.scanning||'Scanning…', 'scanning');
  }
}

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

function setMode(view){
  const v = (view === 'scan') ? 'scan' : 'menu';
  ROOT.dataset.view = v;

  if (v === 'scan') {
    // hide menu, start camera (idempotent)
    startQr();
  } else {
    // back to menu — stop camera and reset UI
    stopQr();
    setStat(t.ready_pick_action||'Ready — pick an action', 'idle');
  }
}

// === VIEW STATE ===
// 'menu'  -> big buttons, camera hidden
// 'scan'  -> camera shown, small toolbar/status shown
function setMode(mode){
  const root = ROOT; // #<?php echo $root_id; ?>
  if (!root) return;
  const m = (mode === 'scan') ? 'scan' : 'menu';
  root.dataset.view = m;
  // No inline show/hide; CSS controls visibility by [data-view]
}



document.addEventListener('keydown', (e)=>{
  if (e.key === 'Escape' && ROOT.dataset.view === 'scan') {
    setMode('menu');   // stop camera and show the lane again
  }
});

// --- Batch Action (lane) mode ---
const ACTIONS = ['in','out'];
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

  // NO listener binding here.
  laneChip.textContent = labelFor(currentAction);
  laneChip.className   = chipClassFor(currentAction);

  setStat(
    ((window.SFS_ATT_I18N||{}).ready||'Ready') + ' — ' + ((window.SFS_ATT_I18N||{}).action||'action') + ': ' + labelFor(currentAction) + (requiresSelfie ? ' — ' + ((window.SFS_ATT_I18N||{}).selfie_required||'selfie required') : ''),
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

      // 2) flip view to scan
      ROOT.dataset.view = 'scan';

      // 3) force camera wrapper visible (in case refresh()/stopQr() hid it)
      if (qrWrap)  qrWrap.style.display  = '';
      if (camwrap) camwrap.style.display = 'grid';

      // 4) actually start the scanner
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
function flash(kind){
  if (!flashEl) return;
  flashEl.className = 'sfs-flash sfs-flash--' + (kind || 'in');
  // trigger reflow to restart animation
  void flashEl.offsetWidth;
  flashEl.classList.add('show');
  setTimeout(()=> flashEl.classList.remove('show'), 400);
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

    // --- Hit /attendance/scan to mint/use a short-lived scan_token
    let resp, text, data;
    try {
      resp = await fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-WP-Nonce': nonce },
      });
      text = await resp.text();
    } catch (e) {
      setStat(OFFLINE_ENABLED ? (t.offline_no_connection||'No connection — offline mode active') : (t.network_error||'Network error'), 'error');
      dbg('scan network error', e && e.message);
      // mild backoff to avoid hammering same frame
      lastQrValue = raw;
      lastQrTs    = Date.now();
      throw e;
    }

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
    const scanToken = data.scan_token;
    if (!scanToken) {
      setStat(t.scan_failed_no_token||'Scan failed: no token', 'error');
      dbg('scan ok but no scan_token', data);
      lastQrValue = raw; lastQrTs = Date.now();
      throw new Error('no_scan_token');
    }

    const empName = (data && (data.employee_name || data.name))
  || `Employee #${data.employee_id || emp}`;

setStat(`✓ ${empName} — ${t.validating_ellipsis||'Validating…'}`, 'ok');
dbg('scan ok', data);

if (empEl) {
  empEl.textContent = empName;
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
    const r = await attemptPunch(currentAction, scanToken, selfieBlob, geox);


    if (r.ok) {
  playActionTone(currentAction);
  flashPunchSuccess('ok');   // <- use halo on the whole kiosk
  flash(currentAction);      // full-screen color flash

  setStat((r.data?.label || t.done_label||'Done') + ' — ' + (t.next_label||'Next'), 'ok');

  lastQrValue = raw;
  lastQrTs    = Date.now() + BACKOFF_MS_OK;

  await refresh();

  setTimeout(() => {
    if (uiMode !== 'error') setStat(t.scanning||'Scanning…', 'scanning');
  }, 400);

  return true;
}
 else {
      const code = (r.data && (r.data.code || r.data?.data?.code)) || '';
      const msg  = r.data?.message || (t.punch_failed_prefix||'Punch failed:') + ` (HTTP ${r.status})`;

      if (r.status === 409) {
        if (code === 'no_shift') {
          setStat(t.no_shift_contact_hr||'No shift configured. Contact your Manager or HR.', 'error');
        } else if (code === 'invalid_transition') {
          setStat(t.invalid_action_try_different||'Invalid action now. Try a different punch type.', 'error');
        } else {
          setStat(t.no_shift_contact_hr||'No shift, contact your Manager or HR.', 'error');
        }

        // Back off so the same frame doesn't hammer the API
        lastQrValue = raw;
        lastQrTs    = Date.now() + (BACKOFF_MS_409 - QR_COOLDOWN_MS);

        // Keep the camera running; DO NOT throw here.
        return false;
      }

      // Non-409 errors keep prior handling
      setStat(msg, 'error');
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
    setStat((t.punch_failed_prefix||'Punch failed:') + ` ${punchErr && punchErr.message ? punchErr.message : (t.unknown_error||'Unknown error')}`, 'error');
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



    // Toggle UI based on device + view:
    // - QR disabled  → always hidden
    // - QR enabled   → only visible in scan mode
    const inScan = (ROOT && ROOT.dataset.view === 'scan');

    if (qrWrap) {
      qrWrap.style.display = (qrOn && inScan) ? '' : 'none';
    }
    if (camwrap) {
      camwrap.style.display = (qrOn && inScan) ? 'grid' : 'none';
    }
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


function showScannerUI(on){ if (qrWrap) qrWrap.style.display = on ? '' : 'none'; }

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
  // Localized long date (Sun–Sat, 01 Month 2025)
  dateEl.textContent = d.toLocaleDateString(undefined, {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
  });
}

tickDate();

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
public static function generate_early_leave_request_number(): string {
    global $wpdb;
    return \SFS\HR\Core\Helpers::generate_reference_number( 'EL', $wpdb->prefix . 'sfs_hr_early_leave_requests' );
}

/**
 * Backfill reference numbers for existing early leave requests
 */
private static function backfill_early_leave_request_numbers( \wpdb $wpdb ): void {
    $table = $wpdb->prefix . 'sfs_hr_early_leave_requests';
    $missing = $wpdb->get_results(
        "SELECT id, created_at FROM `$table` WHERE request_number IS NULL OR request_number = '' ORDER BY id ASC"
    );
    foreach ($missing as $row) {
        $year = $row->created_at ? date('Y', strtotime($row->created_at)) : wp_date('Y');
        $count = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `$table` WHERE request_number LIKE %s",
                'EL-' . $year . '-%'
            )
        );
        $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        $number = 'EL-' . $year . '-' . $sequence;
        $wpdb->update($table, ['request_number' => $number], ['id' => $row->id]);
    }
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
            KEY work_date (work_date)
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
            break_start_time TIME NULL COMMENT 'Scheduled break start for delay calculation',
            break_policy ENUM('auto','punch','none') NOT NULL DEFAULT 'auto',
            grace_late_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5,
            grace_early_leave_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5,
            rounding_rule ENUM('none','5','10','15') NOT NULL DEFAULT '5',
            overtime_after_minutes SMALLINT UNSIGNED NULL,
            require_selfie TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            dept_id BIGINT UNSIGNED NULL COMMENT 'References sfs_hr_departments.id',
            notes TEXT NULL,
            weekly_overrides TEXT NULL,
            dept_ids TEXT NULL COMMENT 'JSON array of department IDs for multi-department shifts',
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
            KEY shift_date (shift_id, work_date)
        ) $charset_collate;");

        // 5) employee default shifts (history)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_emp_shifts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            shift_id BIGINT UNSIGNED NOT NULL,
            start_date DATE NOT NULL,
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY emp_date (employee_id, start_date),
            KEY shift_id (shift_id)
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
            allowed_dept_id BIGINT UNSIGNED NULL COMMENT 'References sfs_hr_departments.id, NULL means all departments',
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
            session_id BIGINT UNSIGNED NULL COMMENT 'Links to attendance session if exists',
            request_date DATE NOT NULL,
            scheduled_end_time TIME NULL COMMENT 'Original shift end time',
            requested_leave_time TIME NOT NULL COMMENT 'Time employee wants to leave',
            actual_leave_time TIME NULL COMMENT 'Actual punch out time',
            reason_type ENUM('sick','external_task','personal','emergency','other') NOT NULL DEFAULT 'other',
            reason_note TEXT NULL COMMENT 'Employee explanation',
            status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            manager_id BIGINT UNSIGNED NULL COMMENT 'Department manager to approve',
            reviewed_by BIGINT UNSIGNED NULL COMMENT 'Who actually reviewed',
            reviewed_at DATETIME NULL,
            manager_note TEXT NULL COMMENT 'Manager response/comment',
            affects_salary TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=no deduction, 1=normal deduction',
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
        self::add_column_if_missing($wpdb, $shifts_table, 'break_start_time', "break_start_time TIME NULL COMMENT 'Scheduled break start for delay calculation'");

        // Add request_number column for early leave requests
        $early_leave_table = "{$p}sfs_hr_early_leave_requests";
        self::add_column_if_missing($wpdb, $early_leave_table, 'request_number', "request_number VARCHAR(50) NULL");
        self::add_unique_key_if_missing($wpdb, $early_leave_table, 'request_number');
        self::backfill_early_leave_request_numbers($wpdb);

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

        // Holidays
        $ranges = get_option( 'sfs_hr_holidays' );
        if ( is_array( $ranges ) ) {
            foreach ( $ranges as $range ) {
                $s = isset($range['start_date']) ? $range['start_date'] : null;
                $e = isset($range['end_date'])   ? $range['end_date']   : null;
                if ( $s && $e && $dateYmd >= $s && $dateYmd <= $e ) { $blocked = true; break; }
            }
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

    /**
     * Check if a date is a company holiday (not employee-specific leave)
     *
     * @param string $dateYmd Date in Y-m-d format
     * @return bool
     */
    public static function is_company_holiday( string $dateYmd ): bool {
        $ranges = get_option( 'sfs_hr_holidays' );
        if ( ! is_array( $ranges ) ) {
            return false;
        }

        foreach ( $ranges as $range ) {
            $s = isset( $range['start_date'] ) ? $range['start_date'] : null;
            $e = isset( $range['end_date'] )   ? $range['end_date']   : null;
            if ( $s && $e && $dateYmd >= $s && $dateYmd <= $e ) {
                return true;
            }
        }

        return false;
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

    /**
     * Recalculate a day session after every punch.
     * Applies: grace (late/early), rounding (nearest N), unpaid break, OT threshold.
     */
    public static function recalc_session_for( int $employee_id, string $ymd, \wpdb $wpdb = null ): void {
    $wpdb = $wpdb ?: $GLOBALS['wpdb'];
    $pT   = $wpdb->prefix . 'sfs_hr_attendance_punches';
    $sT   = $wpdb->prefix . 'sfs_hr_attendance_sessions';

    // Leave/Holiday global guard
    if ( self::is_blocked_by_leave_or_holiday($employee_id, $ymd) ) {
        $data = [
            'employee_id'         => $employee_id,
            'work_date'           => $ymd,
            'in_time'             => null,
            'out_time'            => null,
            'break_minutes'       => 0,
            'break_delay_minutes' => 0,
            'no_break_taken'      => 0,
            'net_minutes'         => 0,
            'rounded_net_minutes' => 0,
            'overtime_minutes'    => 0,
            'status'              => 'on_leave',
            'flags_json'          => wp_json_encode([]),
            'calc_meta_json'      => wp_json_encode(['reason'=>'blocked_by_leave_or_holiday']),
            'last_recalc_at'      => current_time('mysql', true),
        ];
        $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$sT} WHERE employee_id=%d AND work_date=%s LIMIT 1", $employee_id, $ymd) );
        if ($exists) $wpdb->update($sT,$data,['id'=>$exists]); else $wpdb->insert($sT,$data);
        return;
    }

    // Resolve shift using the proper cascade: assignment → employee shift → dept automation → fallback
    $shift = self::resolve_shift_for_date($employee_id, $ymd, [], $wpdb);

    // Build segments from resolved shift
    $segments = self::build_segments_from_shift($shift, $ymd);

    // Get dept for calc_meta
    $dept = self::get_employee_dept_for_attendance($employee_id, $wpdb);

// Local-day → UTC window
$tz        = wp_timezone();
$dayLocal  = new \DateTimeImmutable($ymd . ' 00:00:00', $tz);
$nextLocal = $dayLocal->modify('+1 day');
$startUtc  = $dayLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$endUtc    = $nextLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

// Pull all punches for that window
$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT punch_type, punch_time, valid_geo, valid_selfie, source
     FROM {$pT}
     WHERE employee_id = %d
       AND punch_time >= %s AND punch_time < %s
     ORDER BY punch_time ASC",
    $employee_id, $startUtc, $endUtc
) );

$firstIn = null; $lastOut = null;
foreach ($rows as $r) {
    if ($r->punch_type === 'in'  && $firstIn === null) $firstIn = $r->punch_time;
    if ($r->punch_type === 'out') $lastOut = $r->punch_time;
}


    // Grace & rounding from settings
    $settings = get_option(self::OPT_SETTINGS) ?: [];
    $grLate   = (int)($settings['default_grace_late']  ?? 5);
    $grEarly  = (int)($settings['default_grace_early'] ?? 5);
    $round    = (string)($settings['default_rounding_rule'] ?? '5');
    $roundN   = ($round === 'none') ? 0 : (int)$round;

    // Evaluate
    $ev = self::evaluate_segments($segments, $rows, $grLate, $grEarly);

    // ---- Break deduction logic ----
    // Determine shift break config
    $shift_break_minutes = $shift ? (int) ( $shift->unpaid_break_minutes ?? 0 ) : 0;
    $shift_break_policy  = $shift ? ( $shift->break_policy ?? 'none' ) : 'none';
    $shift_break_start   = $shift ? ( $shift->break_start_time ?? null ) : null;
    $has_mandatory_break = ( $shift_break_policy !== 'none' && $shift_break_minutes > 0 );

    // Detect if all in/out punches came from kiosk (kiosk = auto break, no punch needed)
    $is_kiosk_day = false;
    if ( ! empty( $rows ) ) {
        $is_kiosk_day = true;
        foreach ( $rows as $r ) {
            if ( in_array( $r->punch_type, [ 'in', 'out' ], true ) && ( $r->source ?? '' ) !== 'kiosk' ) {
                $is_kiosk_day = false;
                break;
            }
        }
    }

    // Check if employee actually punched break_start/break_end
    $has_break_punches = false;
    foreach ( $rows as $r ) {
        if ( in_array( $r->punch_type, [ 'break_start', 'break_end' ], true ) ) {
            $has_break_punches = true;
            break;
        }
    }

    $break_delay_minutes = 0;
    $no_break_taken      = 0;
    $break_deduction     = 0; // total minutes to deduct for break (configured + any delay)

    if ( $has_mandatory_break && count( $rows ) > 0 ) {
        if ( $is_kiosk_day || ! $has_break_punches ) {
            // Kiosk day OR employee didn't punch break at all:
            // Always deduct configured break minutes (mandatory).
            $break_deduction = $shift_break_minutes;

            if ( ! $is_kiosk_day && ! $has_break_punches ) {
                // Self-web with no break punches = no break taken (flag it)
                $no_break_taken = 1;
            }
        } else {
            // Employee punched break_start/break_end — use actual break time
            $actual_break = (int) $ev['break_total']; // minutes from break_start..break_end pairs

            if ( $actual_break > $shift_break_minutes ) {
                // Returned late from break
                $break_delay_minutes = $actual_break - $shift_break_minutes;
            }
            // Total deduction = configured break + any delay beyond it
            $break_deduction = $shift_break_minutes + $break_delay_minutes;
        }
    } else {
        // No mandatory break or no punches at all — deduct actual break punches only
        $break_deduction = (int) $ev['break_total'];
    }

    // Net worked time = total worked minus break deduction
    $net = (int) $ev['worked_total'] - $break_deduction;
    $net = max( 0, $net );

    // ---- Total-hours mode (shift-level → role-based policy fallback) ----
    $is_total_hours = \SFS\HR\Modules\Attendance\Services\Policy_Service::is_total_hours_mode( $employee_id, $shift );
    $policy_break   = \SFS\HR\Modules\Attendance\Services\Policy_Service::get_break_settings( $employee_id, $shift );

    if ( $is_total_hours && $policy_break['enabled'] && $policy_break['duration_minutes'] > 0 && ! $has_mandatory_break ) {
        // Only apply policy-level break if shift doesn't already have a mandatory break
        $net = max( 0, $net - $policy_break['duration_minutes'] );
    }

    if ($roundN > 0) $net = (int)round($net / $roundN) * $roundN;

    $scheduled = (int)$ev['scheduled_total'];
    $ot = max(0, $net - $scheduled);

    // Status rollup
    $status = 'present';
    if ( $is_total_hours ) {
        // Total-hours mode FIRST: segments may be empty (no fixed start/end times)
        // so we must check this before the empty-segments → day_off fallback.
        // Total-hours mode: compare worked hours against target, ignore shift times
        $target_hours   = \SFS\HR\Modules\Attendance\Services\Policy_Service::get_target_hours( $employee_id, $shift );
        $target_minutes = (int) ( $target_hours * 60 );

        if ( count( $rows ) === 0 ) {
            $is_company_holiday = self::is_company_holiday( $ymd );
            $status = $is_company_holiday ? 'holiday' : 'absent';
        } elseif ( in_array( 'incomplete', $ev['flags'], true ) ) {
            $status = 'incomplete';
        } elseif ( $net < $target_minutes ) {
            // Worked but didn't reach target — only flag as left_early if the employee
            // actually clocked out before the shift end time. Otherwise it's insufficient
            // hours (e.g., late arrival) but NOT an early departure.
            $actually_left_early = false;
            if ( $lastOut && $shift && ! empty( $shift->end_time ) ) {
                $tz_th        = wp_timezone();
                $shift_end_th = new \DateTimeImmutable( $ymd . ' ' . $shift->end_time, $tz_th );
                $last_out_th  = ( new \DateTimeImmutable( $lastOut, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz_th );
                $actually_left_early = ( $last_out_th < $shift_end_th );
            }
            if ( $actually_left_early ) {
                $status = 'left_early';
                $ev['flags'][] = 'left_early';
            } else {
                // Insufficient hours but stayed until/past shift end — mark present, not left_early
                $status = 'present';
            }
        } else {
            $status = 'present';
        }

        // In total-hours mode, overtime is hours beyond target
        $ot = max( 0, $net - $target_minutes );
    } elseif (!$segments || count($segments)===0) {
        // No shift segments (no fixed start/end times and NOT total_hours) → day off or holiday
        $is_company_holiday = self::is_company_holiday( $ymd );
        $status = $is_company_holiday ? 'holiday' : 'day_off';
    } elseif (in_array('incomplete', $ev['flags'], true)) {
        $status = 'incomplete';
    } elseif (in_array('missed_segment', $ev['flags'], true) && $net === 0) {
        // If the employee actually punched in/out (has punch records and worked time),
        // they attended but their net is 0 due to break deduction or shift-window mismatch.
        // Mark as incomplete (not absent) — they were physically present.
        $status = (count($rows) > 0 && (int)$ev['worked_total'] > 0) ? 'incomplete' : 'absent';
    } elseif (in_array('missed_segment', $ev['flags'], true)) {
        $status = 'present';
    }
    if ( ! $is_total_hours ) {
        if (in_array('left_early',$ev['flags'],true)) $status = ($status==='present' ? 'left_early' : $status);
        if (in_array('late',$ev['flags'],true))       $status = ($status==='present' ? 'late'       : $status);
    }

    // Geo/selfie counters (for completeness)
    $outside_geo = 0; $no_selfie = 0;
    foreach ($rows as $r) {
        if ((int)$r->valid_geo === 0)    $outside_geo++;
        if ((int)$r->valid_selfie === 0) $no_selfie++;
    }

    $flags = array_values(array_unique($ev['flags']));

    // In total-hours mode, strip segment-based left_early/late flags — shift fixed times
    // are irrelevant; only total worked hours matter. The status block above already
    // determined whether to flag left_early based on actual hours + clock-out time.
    if ( $is_total_hours ) {
        $flags = array_values( array_diff( $flags, [ 'left_early', 'late' ] ) );
        // Re-add left_early only if the total-hours status logic above set it
        if ( $status === 'left_early' && ! in_array( 'left_early', $flags, true ) ) {
            $flags[] = 'left_early';
        }
    }

    if ($outside_geo > 0) $flags[] = 'outside_geofence';
    if ($no_selfie > 0)   $flags[] = 'no_selfie';
    if ($no_break_taken)          $flags[] = 'no_break_taken';
    if ($break_delay_minutes > 0) $flags[] = 'break_delay';

    $calcMeta = [
        'dept'            => $dept,
        'segments'        => $ev['segments'],
        'scheduled_total' => $scheduled,
        'rounded_rule'    => $round,
        'grace'           => ['late'=>$grLate,'early'=>$grEarly],
        'counters'        => ['outside_geo'=>$outside_geo,'no_selfie'=>$no_selfie],
    ];

    // Break diagnostics in calc_meta
    if ( $has_mandatory_break ) {
        $calcMeta['break'] = [
            'policy'             => $shift_break_policy,
            'configured_minutes' => $shift_break_minutes,
            'break_start_time'   => $shift_break_start,
            'actual_break'       => (int) $ev['break_total'],
            'break_deduction'    => $break_deduction,
            'break_delay'        => $break_delay_minutes,
            'no_break_taken'     => $no_break_taken,
            'is_kiosk_day'       => $is_kiosk_day,
        ];
    }

    // Add total-hours policy info to calc_meta for diagnostics
    if ( $is_total_hours ) {
        $calcMeta['policy_mode']           = 'total_hours';
        $calcMeta['target_hours']          = \SFS\HR\Modules\Attendance\Services\Policy_Service::get_target_hours( $employee_id, $shift );
        $calcMeta['target_minutes']        = (int) ( $calcMeta['target_hours'] * 60 );
        $calcMeta['policy_break_deducted'] = ( $policy_break['enabled'] && ! $has_mandatory_break ) ? $policy_break['duration_minutes'] : 0;
    }

    $data = [
        'employee_id'         => $employee_id,
        'work_date'           => $ymd,
        'in_time'             => $firstIn,
        'out_time'            => $lastOut,
        'break_minutes'       => $break_deduction,
        'break_delay_minutes' => $break_delay_minutes,
        'no_break_taken'      => $no_break_taken,
        'net_minutes'         => (int)$ev['worked_total'],
        'rounded_net_minutes' => $net,
        'overtime_minutes'    => $ot,
        'status'              => $status,
        'flags_json'          => wp_json_encode($flags),
        'calc_meta_json'      => wp_json_encode($calcMeta),
        'last_recalc_at'      => current_time('mysql', true),
    ];

    // Check if this is a new late/early detection (to avoid duplicate notifications)
    $existing_flags = [];
    $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$sT} WHERE employee_id=%d AND work_date=%s LIMIT 1", $employee_id, $ymd) );
    if ($exists) {
        $existing_flags_json = $wpdb->get_var( $wpdb->prepare("SELECT flags_json FROM {$sT} WHERE id=%d", (int)$exists) );
        $existing_flags = $existing_flags_json ? (json_decode($existing_flags_json, true) ?: []) : [];
        $wpdb->update($sT, $data, ['id'=>(int)$exists]);
    } else {
        $wpdb->insert($sT, $data);
    }

    // Capture session ID for linking (used by auto-created early leave requests)
    $session_id = $exists ? (int) $exists : (int) $wpdb->insert_id;

    // Fire notification hooks for late arrival and early leave (only once per session)
    $was_late = in_array('late', $existing_flags, true);
    $was_early = in_array('left_early', $existing_flags, true);
    $is_late = in_array('late', $flags, true);
    $is_early = in_array('left_early', $flags, true);

    // Fire late arrival notification (only if newly detected)
    if ($is_late && !$was_late) {
        // Calculate minutes late from segments
        $minutes_late = 0;
        foreach ($ev['segments'] as $seg) {
            if (!empty($seg['late_minutes'])) {
                $minutes_late += (int) $seg['late_minutes'];
            }
        }
        do_action('sfs_hr_attendance_late', $employee_id, [
            'minutes_late' => $minutes_late,
            'work_date'    => $ymd,
            'type'         => 'attendance_flag',
        ]);
    }

    // Fire early leave notification (only if newly detected)
    if ($is_early && !$was_early) {
        $minutes_early = 0;

        if ( $is_total_hours ) {
            // Total-hours mode: "early" means hours shortfall, not shift-time based.
            // Calculate how many minutes short of the target the employee worked.
            $th_target = (int) ( \SFS\HR\Modules\Attendance\Services\Policy_Service::get_target_hours( $employee_id, $shift ) * 60 );
            $minutes_early = max( 0, $th_target - $net );
        } else {
            // Segment-based mode: calculate from segment early_minutes
            foreach ($ev['segments'] as $seg) {
                if (!empty($seg['early_minutes'])) {
                    $minutes_early += (int) $seg['early_minutes'];
                }
            }
            // Fallback: calculate from shift end time and last clock-out
            if ( $minutes_early === 0 && $shift && ! empty( $shift->end_time ) && $lastOut ) {
                $tz_fb       = wp_timezone();
                $shift_end_dt = new \DateTimeImmutable( $ymd . ' ' . $shift->end_time, $tz_fb );
                $last_out_dt  = ( new \DateTimeImmutable( $lastOut, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz_fb );
                $diff_secs    = $shift_end_dt->getTimestamp() - $last_out_dt->getTimestamp();
                if ( $diff_secs > 0 ) {
                    $minutes_early = (int) round( $diff_secs / 60 );
                }
            }
        }
        do_action('sfs_hr_attendance_early_leave', $employee_id, [
            'minutes_early' => $minutes_early,
            'work_date'     => $ymd,
            'type'          => 'attendance_flag',
        ]);

        // Auto-create early leave request so manager sees it in the Early Leave tab.
        // Skip if the employee did not actually leave early (0 minutes = on time or after shift end).
        $el_table = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $el_exists = ( $minutes_early > 0 ) ? $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$el_table} WHERE employee_id = %d AND request_date = %s AND status IN ('pending','approved')",
            $employee_id,
            $ymd
        ) ) : true; // treat as "already exists" to skip creation

        if ( ! $el_exists ) {
            // Scheduled end time from shift (not meaningful in total-hours mode)
            $scheduled_end = ( ! $is_total_hours && $shift && ! empty( $shift->end_time ) ) ? $shift->end_time : null;

            // Actual leave time (last clock-out) converted from UTC to local
            $actual_leave_local = null;
            if ( $lastOut ) {
                $tz_el   = wp_timezone();
                $utc_out = new \DateTimeImmutable( $lastOut, new \DateTimeZone( 'UTC' ) );
                $actual_leave_local = $utc_out->setTimezone( $tz_el )->format( 'H:i:s' );
            }

            // Find department manager
            $emp_tbl  = $wpdb->prefix . 'sfs_hr_employees';
            $dept_tbl = $wpdb->prefix . 'sfs_hr_departments';
            $emp_row  = $wpdb->get_row( $wpdb->prepare(
                "SELECT dept_id FROM {$emp_tbl} WHERE id = %d", $employee_id
            ) );
            $mgr_id = null;
            if ( $emp_row && $emp_row->dept_id ) {
                $dept_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT manager_user_id FROM {$dept_tbl} WHERE id = %d", $emp_row->dept_id
                ) );
                if ( $dept_row && $dept_row->manager_user_id ) {
                    $mgr_id = (int) $dept_row->manager_user_id;
                }
            }

            $now_el = current_time( 'mysql' );
            $el_ref = self::generate_early_leave_request_number();

            // Build reason note based on mode
            $el_reason_note = $is_total_hours
                ? sprintf(
                    /* translators: %d = number of minutes short of required hours */
                    __( 'Auto-created: employee worked %d minutes less than required hours.', 'sfs-hr' ),
                    $minutes_early
                )
                : sprintf(
                    /* translators: %d = number of minutes the employee left early */
                    __( 'Auto-created: employee left %d minutes before shift end.', 'sfs-hr' ),
                    $minutes_early
                );

            $inserted = $wpdb->insert( $el_table, [
                'employee_id'          => $employee_id,
                'session_id'           => $session_id,
                'request_date'         => $ymd,
                'scheduled_end_time'   => $scheduled_end,
                'requested_leave_time' => $actual_leave_local,
                'actual_leave_time'    => $actual_leave_local,
                'reason_type'          => 'other',
                'reason_note'          => $el_reason_note,
                'status'               => 'pending',
                'request_number'       => $el_ref,
                'manager_id'           => $mgr_id,
                'affects_salary'       => 0,
                'created_at'           => $now_el,
                'updated_at'           => $now_el,
            ] );

            if ( $inserted === false && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[SFS HR] Failed to auto-create early leave request for employee %d on %s: %s',
                    $employee_id, $ymd, $wpdb->last_error
                ) );
            }
        }
    }

    // Fire no-break-taken notification (only if newly detected)
    $was_no_break = in_array( 'no_break_taken', $existing_flags, true );
    if ( $no_break_taken && ! $was_no_break ) {
        do_action( 'sfs_hr_attendance_no_break_taken', $employee_id, [
            'work_date'           => $ymd,
            'configured_break'    => $shift_break_minutes,
            'type'                => 'attendance_flag',
        ] );
    }

    // Fire break-delay notification (only if newly detected)
    $was_break_delay = in_array( 'break_delay', $existing_flags, true );
    if ( $break_delay_minutes > 0 && ! $was_break_delay ) {
        do_action( 'sfs_hr_attendance_break_delay', $employee_id, [
            'work_date'           => $ymd,
            'delay_minutes'       => $break_delay_minutes,
            'configured_break'    => $shift_break_minutes,
            'actual_break'        => (int) $ev['break_total'],
            'type'                => 'attendance_flag',
        ] );
    }
}


    

    /** Load the Department → Shift automation map from options, resilient to different keys/shapes. */
/** Load Department → Shift automation map and normalize to: [deptKey => configArray]. */
private static function load_automation_map(): array {
    // Try all known locations
    $candidates = [
        'sfs_hr_attendance_automation',
        'sfs_hr_attendance_auto',
        'sfs_hr_attendance_dept_map',
    ];
    $raw = [];
    foreach ($candidates as $k) {
        $v = get_option($k);
        if (is_array($v) && !empty($v)) { $raw = $v; break; }
    }
    if (empty($raw)) {
        $settings = get_option(self::OPT_SETTINGS);
        if (is_array($settings)) {
            foreach (['automation','dept_automation','dept_map','attendance_automation'] as $sub) {
                if (!empty($settings[$sub]) && is_array($settings[$sub])) { $raw = $settings[$sub]; break; }
            }
            if (empty($raw) && !empty($settings['attendance']) && is_array($settings['attendance'])) {
                foreach (['automation','dept_automation','dept_map'] as $sub) {
                    if (!empty($settings['attendance'][$sub]) && is_array($settings['attendance'][$sub])) {
                        $raw = $settings['attendance'][$sub]; break;
                    }
                }
            }
        }
    }

    // Normalize: if it’s the wrapped shape { department_key_type, map }, unwrap to just the map.
    if (isset($raw['map']) && is_array($raw['map'])) {
        $raw = $raw['map'];
    }

    // Ensure keys are strings (so "2" and 2 both match)
    $norm = [];
    foreach ($raw as $k => $v) {
        $norm[(string)$k] = is_array($v) ? $v : [];
    }
    return $norm;
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
     * Examples:
     *   - "Head Office" / "Sales" / "HR" / "IT" → "office"
     * Returns sanitized slug from work location string.
     * Now simply sanitizes the input - no longer guesses department types.
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
     *   'slug' => string,     // normalized slug: office/showroom/warehouse/factory or sanitized name
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

        // Slug:
        // 1) Prefer explicit work_location mapping (office/showroom/warehouse/factory)
        // 2) Fallback: sanitize_title of dept_name
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
     * Example result: sanitized slug from department name, or empty string.
     */
    public static function get_employee_dept_for_attendance( int $employee_id ): string {
        $info = self::employee_department_info( $employee_id );
        return $info['slug'];
    }

    
    /** Load Department → Shift automation, normalized to [deptKey(string) => configArray]. */
private static function load_automation_map_and_keytype(): array {
    // 1) Dedicated options (legacy & current)
    foreach (['sfs_hr_attendance_automation','sfs_hr_attendance_auto','sfs_hr_attendance_dept_map'] as $optKey) {
        $opt = get_option($optKey);
        if (is_array($opt) && !empty($opt)) {
            // wrapped shape: { department_key_type:'id'|'slug'|'name', map:{...} }
            if (isset($opt['map']) && is_array($opt['map'])) {
                $keytype = in_array(($opt['department_key_type'] ?? 'id'), ['id','slug','name'], true) ? $opt['department_key_type'] : 'id';
                $map = [];
                foreach ($opt['map'] as $k => $v) { $map[(string)$k] = is_array($v) ? $v : []; }
                return ['keytype'=>$keytype, 'map'=>$map, 'source'=>$optKey];
            }
            // direct map
            $map = [];
            foreach ($opt as $k => $v) { $map[(string)$k] = is_array($v) ? $v : []; }
            return ['keytype'=>'id', 'map'=>$map, 'source'=>$optKey];
        }
    }

    // 2) Nested under main settings
    $settings = get_option(self::OPT_SETTINGS);
    if (is_array($settings)) {
        // (a) Older nested maps
        foreach (['automation','dept_automation','dept_map','attendance_automation'] as $sub) {
            if (!empty($settings[$sub]) && is_array($settings[$sub])) {
                $map = [];
                foreach ($settings[$sub] as $k => $v) { $map[(string)$k] = is_array($v) ? $v : []; }
                return ['keytype'=>'id', 'map'=>$map, 'source'=>self::OPT_SETTINGS.'/'.$sub];
            }
        }
        if (!empty($settings['attendance']) && is_array($settings['attendance'])) {
            foreach (['automation','dept_automation','dept_map'] as $sub) {
                if (!empty($settings['attendance'][$sub]) && is_array($settings['attendance'][$sub])) {
                    $map = [];
                    foreach ($settings['attendance'][$sub] as $k => $v) { $map[(string)$k] = is_array($v) ? $v : []; }
                    return ['keytype'=>'id', 'map'=>$map, 'source'=>self::OPT_SETTINGS.'/attendance/'.$sub];
                }
            }
        }

        // (b) **Current UI storage**: dept_defaults + dept_period_overrides
        $defaults  = isset($settings['dept_defaults']) && is_array($settings['dept_defaults']) ? $settings['dept_defaults'] : [];
        $overrides = isset($settings['dept_period_overrides']) && is_array($settings['dept_period_overrides']) ? $settings['dept_period_overrides'] : [];

        if (!empty($defaults) || !empty($overrides)) {
            $map = [];
            foreach ($defaults as $dept_id => $shift_id) {
                $dept_id  = (string)(int)$dept_id;
                $shift_id = (int)$shift_id;
                if ($shift_id > 0) {
                    $map[$dept_id]['default_shift_id'] = $shift_id;
                }
            }
            foreach ($overrides as $dept_id => $list) {
                $dept_id = (string)(int)$dept_id;
                if (!isset($map[$dept_id])) $map[$dept_id] = [];
                foreach ((array)$list as $ov) {
                    $s   = $ov['start'] ?? ($ov['start_date'] ?? null);
                    $e   = $ov['end']   ?? ($ov['end_date']   ?? null);
                    $sid = isset($ov['shift_id']) ? (int)$ov['shift_id'] : 0;
                    if ($s && $e && $sid > 0) {
                        $map[$dept_id]['overrides'][] = [
                            'start_date' => $s,
                            'end_date'   => $e,
                            'shift_id'   => $sid,
                        ];
                    }
                }
            }
            return ['keytype'=>'id', 'map'=>$map, 'source'=>self::OPT_SETTINGS.'/dept_defaults+overrides'];
        }
    }

    return ['keytype'=>'id', 'map'=>[], 'source'=>'(none)'];
}


    /**
     * Employee-specific default shift (history table).
     *
     * Returns the shift row for the employee that is in effect on $ymd,
     * using the last record with start_date <= $ymd.
     */
    private static function lookup_emp_shift_for_date( int $employee_id, string $ymd ): ?\stdClass {
        global $wpdb;

        $p         = $wpdb->prefix;
        $shifts_t  = "{$p}sfs_hr_attendance_shifts";
        $emp_map_t = "{$p}sfs_hr_attendance_emp_shifts";

        if ( $employee_id <= 0 || $ymd === '' ) {
            return null;
        }

        // Bail quickly if mapping table is not installed yet.
        $table_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name   = %s",
                $emp_map_t
            )
        );

        if ( ! $table_exists ) {
            return null;
        }

        // Latest mapping whose start_date <= target date.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT sh.*
                 FROM {$emp_map_t} es
                 INNER JOIN {$shifts_t} sh ON sh.id = es.shift_id
                 WHERE es.employee_id = %d
                   AND es.start_date  <= %s
                   AND sh.active      = 1
                 ORDER BY es.start_date DESC, es.id DESC
                 LIMIT 1",
                $employee_id,
                $ymd
            )
        );

        if ( $row instanceof \stdClass ) {
            // Keep semantics consistent with normal shifts.
            if ( ! isset( $row->__virtual ) ) {
                $row->__virtual = 0;
            }
            return $row;
        }

        return null;
    }


/**
 * Resolve effective shift for Y-m-d:
 * 1) Explicit date-specific assignment
 * 2) Employee-specific default shift (from emp_shifts table)
 * 3) Department automation (supports id/slug/name keying, and UI settings)
 * 4) (Optional) Fallback by dept slug
 */
public static function resolve_shift_for_date(
    int $employee_id,
    string $ymd,
    array $settings = [],
    \wpdb $wpdb_in = null
): ?\stdClass {
    $wpdb   = $wpdb_in ?: $GLOBALS['wpdb'];
    $p      = $wpdb->prefix;
    $assignT= "{$p}sfs_hr_attendance_shift_assign";
    $shiftT = "{$p}sfs_hr_attendance_shifts";
    $empT   = "{$p}sfs_hr_employees";

    // --- 1) Assignment
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT sh.*, sa.is_holiday
         FROM {$assignT} sa
         JOIN {$shiftT}  sh ON sh.id = sa.shift_id
         WHERE sa.employee_id=%d AND sa.work_date=%s
         LIMIT 1",
        $employee_id,
        $ymd
    ));
    if ($row) {
        $row->__virtual  = 0;
        // If shift doesn't have dept_id set, derive from employee
        if ( ! isset( $row->dept_id ) || $row->dept_id === null ) {
            $emp = $wpdb->get_row($wpdb->prepare("SELECT dept_id FROM {$empT} WHERE id=%d", $employee_id));
            $row->dept_id = $emp && ! empty( $emp->dept_id ) ? (int) $emp->dept_id : null;
        }
        return self::apply_weekly_override( $row, $ymd, $wpdb );
    }

    // --- 1.5) Employee-specific shift (from emp_shifts mapping)
    $emp_shift = self::lookup_emp_shift_for_date( $employee_id, $ymd );
    if ( $emp_shift ) {
        // Get employee dept_id for context
        $emp = $wpdb->get_row($wpdb->prepare("SELECT dept_id FROM {$empT} WHERE id=%d", $employee_id));

        // Set dept_id and other required fields
        $emp_shift->dept_id    = $emp && ! empty( $emp->dept_id ) ? (int) $emp->dept_id : null;
        $emp_shift->__virtual  = 0;
        $emp_shift->is_holiday = 0;

        return self::apply_weekly_override( $emp_shift, $ymd, $wpdb );
    }

    // --- 2) Dept identity (id, slug, name) for automation
    $emp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$empT} WHERE id=%d", $employee_id));
    if (!$emp) {
        error_log('[SFS ATT] no employee row for id '.$employee_id);
        return null;
    }

    $dept_id = null; $dept_name = null; $dept_slug = null;
    foreach (['dept_id','department_id'] as $c) {
        if (isset($emp->$c) && is_numeric($emp->$c)) {
            $dept_id = (int)$emp->$c;
            break;
        }
    }
    foreach (['dept','department','dept_label'] as $c) {
        if (!empty($emp->$c)) {
            $dept_name = (string)$emp->$c;
            break;
        }
    }
    if ($dept_name) {
        $dept_slug = sanitize_title($dept_name);
    }

    // --- 3) Department Automation
    $auto    = self::load_automation_map_and_keytype(); // ['keytype','map','source']
    $map     = $auto['map'];
    $keytype = $auto['keytype'];

    // Build candidate keys in the order that matches keytype first
    $candidates = [];
    if ($keytype === 'id') {
        if ($dept_id !== null) { $candidates[] = (string)$dept_id; }
        if ($dept_slug)        { $candidates[] = $dept_slug; }
        if ($dept_name)        { $candidates[] = $dept_name; }
    } elseif ($keytype === 'slug') {
        if ($dept_slug)        { $candidates[] = $dept_slug; }
        if ($dept_name)        { $candidates[] = $dept_name; }
        if ($dept_id !== null) { $candidates[] = (string)$dept_id; }
    } else { // 'name'
        if ($dept_name)        { $candidates[] = $dept_name; }
        if ($dept_slug)        { $candidates[] = $dept_slug; }
        if ($dept_id !== null) { $candidates[] = (string)$dept_id; }
    }

    // Try to find config
    $conf = null;
    foreach ($candidates as $k) {
        if (isset($map[$k]) && is_array($map[$k])) {
            $conf = $map[$k];
            break;
        }
    }

    if ($conf) {
        // default shift id
        $shift_id = null;
        foreach (['default_shift_id','default','shift','shift_id'] as $k) {
            if (isset($conf[$k]) && is_numeric($conf[$k])) {
                $shift_id = (int)$conf[$k];
                break;
            }
        }

        // effective override
        if (!empty($conf['override']) && is_array($conf['override'])) {
            $ov  = $conf['override'];
            $s   = $ov['start'] ?? ($ov['start_date'] ?? null);
            $e   = $ov['end']   ?? ($ov['end_date']   ?? null);
            $sid = isset($ov['shift_id']) ? (int)$ov['shift_id'] : 0;
            if ($s && $e && $sid > 0 && $ymd >= $s && $ymd <= $e) {
                $shift_id = $sid;
            }
        } elseif (!empty($conf['overrides']) && is_array($conf['overrides'])) {
            foreach ($conf['overrides'] as $ov) {
                if (!is_array($ov)) {
                    continue;
                }
                $s   = $ov['start'] ?? ($ov['start_date'] ?? null);
                $e   = $ov['end']   ?? ($ov['end_date']   ?? null);
                $sid = isset($ov['shift_id']) ? (int)$ov['shift_id'] : 0;
                if ($s && $e && $sid > 0 && $ymd >= $s && $ymd <= $e) {
                    $shift_id = $sid;
                    break;
                }
            }
        }

        if ($shift_id) {
            $sh = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$shiftT} WHERE id=%d AND active=1 LIMIT 1",
                    $shift_id
                )
            );
            if ($sh) {
                $sh->__virtual  = 1;
                $sh->is_holiday = 0;
                $sh->dept_id    = $dept_id;
                return self::apply_weekly_override( $sh, $ymd, $wpdb );
            }
        }
    }

    // --- 4) Optional fallback by dept_id to keep system usable
    // Check both old dept_id column and new dept_ids JSON array
    if ($dept_id) {
        // First try: match dept_ids JSON array (multi-department support)
        $fb = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$shiftT}
                 WHERE active=1
                   AND (dept_ids IS NOT NULL AND JSON_CONTAINS(dept_ids, %s))
                 ORDER BY id ASC LIMIT 1",
                (string) $dept_id
            )
        );

        // Fallback: match legacy single dept_id column
        if ( ! $fb ) {
            $fb = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$shiftT} WHERE active=1 AND dept_id=%d ORDER BY id ASC LIMIT 1",
                    $dept_id
                )
            );
        }

        if ($fb) {
            $fb->__virtual  = 1;
            $fb->is_holiday = 0;
            return self::apply_weekly_override( $fb, $ymd, $wpdb );
        }
    }

    return null;
}

/**
 * Apply weekly overrides to a shift for a given date.
 *
 * Supports two formats in the weekly_overrides JSON:
 *  - Legacy (integer): loads a different shift by ID.
 *  - New per-day schedule:
 *      {"friday": {"start":"08:00:00","end":"14:00:00"}} — override times
 *      {"saturday": null}                                 — day off
 *    When a day key is missing the shift's default times apply.
 */
private static function apply_weekly_override( ?\stdClass $shift, string $ymd, \wpdb $wpdb = null ): ?\stdClass {
    if ( ! $shift ) {
        return $shift;
    }

    if ( empty( $shift->weekly_overrides ) ) {
        return $shift;
    }

    $wpdb = $wpdb ?: $GLOBALS['wpdb'];

    $overrides = json_decode( $shift->weekly_overrides, true );
    if ( ! is_array( $overrides ) || empty( $overrides ) ) {
        return $shift;
    }

    $tz = wp_timezone();
    $date = new \DateTimeImmutable( $ymd . ' 00:00:00', $tz );
    $day_of_week = strtolower( $date->format( 'l' ) );

    if ( ! array_key_exists( $day_of_week, $overrides ) ) {
        return $shift;
    }

    $override_value = $overrides[ $day_of_week ];

    // --- New format: null = day off ---
    if ( $override_value === null ) {
        return null;
    }

    // --- New format: object with start/end times ---
    if ( is_array( $override_value ) && isset( $override_value['start'], $override_value['end'] ) ) {
        $cloned = clone $shift;
        $cloned->start_time = $override_value['start'];
        $cloned->end_time   = $override_value['end'];
        return $cloned;
    }

    // --- Legacy format: integer shift ID ---
    $override_shift_id = (int) $override_value;
    if ( $override_shift_id <= 0 ) {
        return $shift;
    }

    $shiftT = $wpdb->prefix . 'sfs_hr_attendance_shifts';
    $override_shift = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$shiftT} WHERE id=%d AND active=1 LIMIT 1",
            $override_shift_id
        )
    );

    if ( ! $override_shift ) {
        return $shift;
    }

    $override_shift->__virtual  = $shift->__virtual ?? 0;
    $override_shift->is_holiday = $shift->is_holiday ?? 0;
    $override_shift->dept_id    = $shift->dept_id ?? null;

    return $override_shift;
}


/**
 * Build segments from a resolved shift object.
 * Returns an array of segment arrays with start/end times in UTC and local.
 */
private static function build_segments_from_shift( ?\stdClass $shift, string $ymd ): array {
    if ( ! $shift || empty( $shift->start_time ) || empty( $shift->end_time ) ) {
        return [];
    }

    $tz = wp_timezone();

    // Format start_time and end_time (TIME columns like '09:00:00')
    $start_time = $shift->start_time;
    $end_time   = $shift->end_time;

    // Build local datetime from date + shift times
    $stLocal = new \DateTimeImmutable( $ymd . ' ' . $start_time, $tz );
    $enLocal = new \DateTimeImmutable( $ymd . ' ' . $end_time, $tz );

    // Handle overnight shifts (end_time < start_time means next day)
    if ( $enLocal <= $stLocal ) {
        $enLocal = $enLocal->modify( '+1 day' );
    }

    // Convert to UTC
    $stUTC = $stLocal->setTimezone( new \DateTimeZone( 'UTC' ) );
    $enUTC = $enLocal->setTimezone( new \DateTimeZone( 'UTC' ) );

    return [
        [
            'start_utc' => $stUTC->format( 'Y-m-d H:i:s' ),
            'end_utc'   => $enUTC->format( 'Y-m-d H:i:s' ),
            'start_l'   => $stLocal->format( 'Y-m-d H:i:s' ),
            'end_l'     => $enLocal->format( 'Y-m-d H:i:s' ),
            'minutes'   => (int) round( ( $enUTC->getTimestamp() - $stUTC->getTimestamp() ) / 60 ),
        ],
    ];
}

/** Build split segments for Y-m-d from dept_id + settings (legacy, kept for backwards compatibility). */
private static function build_segments_for_date_from_dept( $dept_id_or_slug, string $ymd ): array {
    $settings = get_option(self::OPT_SETTINGS) ?: [];
    // Support both dept_id (int) and legacy dept slug (string)
    $key = is_numeric( $dept_id_or_slug ) ? (int) $dept_id_or_slug : (string) $dept_id_or_slug;
    $map = $settings['dept_weekly_segments'][ $key ] ?? null;
    if ( ! $map ) { $map = []; }

    // day-of-week as 'sun'..'sat' using site timezone
    $tz = wp_timezone();
    $d  = new \DateTimeImmutable($ymd.' 00:00:00', $tz);
    $dow = strtolower($d->format('D')); // sun, mon, ...

    $segments = [];
    foreach ((array)($map[$dow] ?? []) as $pair) {
        if (!is_array($pair) || count($pair) < 2) { continue; }
        [$start,$end] = [$pair[0], $pair[1]];
        $stLocal = new \DateTimeImmutable($ymd.' '.$start, $tz);
        $enLocal = new \DateTimeImmutable($ymd.' '.$end,   $tz);
        if ($enLocal <= $stLocal) { $enLocal = $enLocal->modify('+1 day'); }
        $stUTC = $stLocal->setTimezone(new \DateTimeZone('UTC'));
        $enUTC = $enLocal->setTimezone(new \DateTimeZone('UTC'));
        $segments[] = [
            'start_utc' => $stUTC->format('Y-m-d H:i:s'),
            'end_utc'   => $enUTC->format('Y-m-d H:i:s'),
            'start_l'   => $stLocal->format('Y-m-d H:i:s'),
            'end_l'     => $enLocal->format('Y-m-d H:i:s'),
            'minutes'   => (int)round(($enUTC->getTimestamp() - $stUTC->getTimestamp())/60),
        ];
    }
    return $segments;
}

/** Return selfie mode resolved by precedence; true/false for “require this punch now?” decision is made in REST. */
// In class \SFS\HR\Modules\Attendance\AttendanceModule

public static function selfie_mode_for( int $employee_id, $dept_id, array $ctx = [] ): string {
    // Global options
    $opt    = get_option( self::OPT_SETTINGS, [] );
    $policy = is_array( $opt ) ? ( $opt['selfie_policy'] ?? [] ) : [];

    $default_mode = $policy['default'] ?? 'optional'; // optional | never | in_only | in_out | all
    $dept_modes   = $policy['by_dept_id'] ?? [];
    $emp_modes    = $policy['by_employee'] ?? [];

    // 1) Base: default
    $mode = $default_mode;

    // 2) Department override by ID
    $dept_id = (int) $dept_id;
    if ( $dept_id > 0 && ! empty( $dept_modes[ $dept_id ] ) ) {
        $mode = (string) $dept_modes[ $dept_id ];
    }

    // 3) Per-employee override (if you ever use it)
    if ( ! empty( $emp_modes[ $employee_id ] ) ) {
        $mode = (string) $emp_modes[ $employee_id ];
    }

    // 4) Device override
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

    // 5) Shift-level requirement — overrides everything to 'all'
    $shift_requires = ! empty( $ctx['shift_requires'] );

    if ( $shift_requires && $mode !== 'all' ) {
        $mode = 'all';
    }

    // Safety net
    if ( ! in_array( $mode, [ 'never', 'optional', 'in_only', 'in_out', 'all' ], true ) ) {
        $mode = 'optional';
    }

    return $mode;
}


/** Evaluate a day against split segments. Stores detail for calc. */
private static function evaluate_segments(array $segments, array $punchesUTC, int $graceLateMin, int $graceEarlyMin): array {
    // Build intervals from IN..OUT, ignore break types
    $intervals = [];
    $open = null;
    foreach ($punchesUTC as $r) {
        $t = strtotime($r->punch_time.' UTC'); // ensure UTC timestamps
        if ($r->punch_type === 'in') {
            if ($open===null) $open = $t;
        } elseif ($r->punch_type === 'out') {
            if ($open!==null && $t > $open) {
                $intervals[] = [$open, $t];
                $open = null;
            }
        }
    }
    // Close unmatched IN? leave it open → incomplete
    $has_unmatched = ($open !== null);

    // Calculate break time from break_start..break_end pairs
    $break_total = 0;
    $break_open = null;
    foreach ($punchesUTC as $r) {
        $t = strtotime($r->punch_time.' UTC');
        if ($r->punch_type === 'break_start') {
            if ($break_open === null) $break_open = $t;
        } elseif ($r->punch_type === 'break_end') {
            if ($break_open !== null && $t > $break_open) {
                $break_total += (int)round(($t - $break_open) / 60);
                $break_open = null;
            }
        }
    }

    $flags = [];
    $worked_total = 0;
    $scheduled_total = 0;
    $seg_details = [];

    // Calculate ACTUAL worked time from all IN/OUT intervals (regardless of segments)
    $actual_worked_minutes = 0;
    foreach ($intervals as [$start, $end]) {
        $actual_worked_minutes += (int)round(($end - $start) / 60);
    }

    foreach ($segments as $seg) {
        $S = strtotime($seg['start_utc'].' UTC');
        $E = strtotime($seg['end_utc'].' UTC');
        $scheduled_total += $seg['minutes'];

        // overlap minutes with any intervals
        $ovMin = 0; $firstIn = null; $lastOut = null;
        foreach ($intervals as [$a,$b]) {
            // overlap
            $start = max($a,$S);
            $end   = min($b,$E);
            if ($end > $start) {
                $ovMin += (int)round(($end - $start)/60);
                if ($firstIn === null || $a < $firstIn) $firstIn = $a;
                if ($lastOut === null || $b > $lastOut) $lastOut = $b;
            }
        }
        $segFlags = [];
        $late_min  = 0;
        $early_min = 0;

        if ($ovMin === 0) {
            $segFlags[] = 'missed_segment';
        } else {
            if ($firstIn !== null && ($firstIn - $S) > ($graceLateMin*60)) {
                $segFlags[] = 'late';
                $late_min = (int) round( ($firstIn - $S) / 60 );
            }
            if ($lastOut !== null && ($E - $lastOut) > ($graceEarlyMin*60)) {
                $early_min = (int) round( ($E - $lastOut) / 60 );
                // Only flag if at least 1 minute early (avoid 0-minute false positives from rounding)
                if ( $early_min > 0 ) {
                    $segFlags[] = 'left_early';
                }
            }
        }
        $flags = array_values(array_unique(array_merge($flags, $segFlags)));
        $seg_details[] = [
            'start' => $seg['start_l'],
            'end'   => $seg['end_l'],
            'scheduled_min' => $seg['minutes'],
            'worked_min'    => $ovMin,
            'flags' => $segFlags,
            'late_minutes'  => $late_min,
            'early_minutes' => $early_min,
        ];
    }

    // Use actual worked time if there are intervals, even if no segment overlap
    $worked_total = $actual_worked_minutes;

    if ($has_unmatched) $flags[] = 'incomplete';
    $flags = array_values(array_unique($flags));

    return [
        'worked_total'    => $worked_total,
        'scheduled_total' => $scheduled_total,
        'break_total'     => $break_total,
        'flags'           => $flags,
        'segments'        => $seg_details,
    ];
}


    /** Convenience: department label only. */
    private static function employee_department_label( int $employee_id, \wpdb $wpdb ): ?string {
        $info = self::employee_department_info( $employee_id, $wpdb );
        return $info ? ($info['name'] ?: $info['slug']) : null;
    }


}