<?php
namespace SFS\HR\Modules\Attendance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AttendanceModule
 * Version: 0.1.2-admin-crud
 * Author: Omar Alnabhani (hdqah.com)
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

class AttendanceModule {

    const OPT_SETTINGS = 'sfs_hr_attendance_settings';

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
}, 10);



        add_shortcode('sfs_hr_attendance_widget', [ $this, 'shortcode_widget' ]);
    }

    /**
     * Minimal employee widget (shortcode) with nonce + REST calls.
     * Place on a page restricted to logged-in employees.
     */
    public function shortcode_widget(): string {
    if ( ! is_user_logged_in() ) { return '<div>Please sign in.</div>'; }
    if ( ! current_user_can( 'sfs_hr_attendance_clock_self' ) ) {
        return '<div>You do not have permission to clock in/out.</div>';
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
    <?php if ( $immersive ): ?>
      <script>
        document.documentElement.classList.add('sfs-att-immersive');
        document.body.classList.add('sfs-att-immersive');
      </script>
      <div class="sfs-att-veil" role="application" aria-label="Self Attendance">
    <?php endif; ?>

    <?php
    // --- Geo for self attendance: read from today's shift ---
    $geo_lat    = '';
    $geo_lng    = '';
    $geo_radius = '';

    $employee_id = self::employee_id_from_user( get_current_user_id() );
    if ( $employee_id ) {
        // Local date (site timezone), not UTC
        $today_ymd = wp_date( 'Y-m-d' );

        // Uses the helper already defined at bottom of this class
        $shift = self::resolve_shift_for_date( $employee_id, $today_ymd );

        if ( $shift ) {
            // shift row is an object from sfs_hr_attendance_shifts
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
                  Hello, <?php echo esc_html( $user_name ); ?>
                </div>
                <div class="sfs-att-sub">Self attendance</div>
              </div>
              <div class="sfs-att-state">
                <span class="sfs-att-state-label">Current</span>
                <span id="sfs-att-state-chip"
                      class="sfs-att-chip sfs-att-chip--idle">Checking…</span>
              </div>
            </div>

            <div id="sfs-att-status" class="sfs-att-statusline">Loading...</div>

            <div class="sfs-att-actions" id="sfs-att-actions">
              <button type="button" data-type="in"
                      class="button button-primary" style="display:none">Clock In</button>
              <button type="button" data-type="out"
                      class="button" style="display:none">Clock Out</button>
              <button type="button" data-type="break_start"
                      class="button" style="display:none">Start Break</button>
              <button type="button" data-type="break_end"
                      class="button" style="display:none">End Break</button>
            </div>

            <!-- Selfie panel (manual capture like kiosk) -->
            <div id="sfs-att-selfie-panel" class="sfs-att-selfie-panel">
              <video id="sfs-att-selfie-video" autoplay playsinline muted></video>
              <canvas id="sfs-att-selfie-canvas" width="640" height="640" hidden></canvas>
              <div class="sfs-att-selfie-actions">
                <button type="button" id="sfs-att-selfie-capture" class="button button-primary">
                  Capture &amp; Submit
                </button>
                <button type="button" id="sfs-att-selfie-cancel" class="button">
                  Cancel
                </button>
              </div>
              <small style="display:block;margin-top:4px;color:#6b7280;font-size:11px;">
                Center your face, then tap “Capture &amp; Submit”.
              </small>
            </div>

            <!-- Fallback file input (if camera API not available) -->
            <div id="sfs-att-selfie-wrap" style="display:none;margin-top:10px;">
              <input type="file" id="sfs-att-selfie"
                     accept="image/*" capture="user"
                     style="display:block"/>
              <small style="display:block;color:#646970;margin-top:4px;font-size:11px;">
                Your device does not support live camera preview. Capture a selfie, then the system will submit it.
              </small>
            </div>

            <small id="sfs-att-hint" class="sfs-att-hint">
              Your location may be required. Allow the browser location prompt if asked.
            </small>

          </div><!-- .sfs-att-card -->
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

      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-panel{
        margin-top:12px;
        display:none;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-panel video{
        width:100%;
        max-width:360px;
        border-radius:10px;
        background:#000;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-selfie-actions{
        margin-top:8px;
        display:flex;
        flex-wrap:wrap;
        gap:8px;
      }
      #<?php echo esc_attr( $root_id ); ?> .sfs-att-hint{
        display:block;
        margin-top:10px;
        font-size:11px;
        color:#6b7280;
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
          background:linear-gradient(180deg,var(--sfs-teal) 0 220px, var(--sfs-surface) 220px 100%);
        }
        #<?php echo esc_attr( $root_id ); ?> .sfs-att-left{
          min-height:220px;
          padding:20px 16px;
        }
      }
    </style>

<script>
(function(){
    if (window.sfsGeo) return; // avoid redefining if other shortcode printed it

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

    function getGeoConfig(root) {
        if (!root) return { enabled:false, required:false, lat:null, lng:null, radius:null };

        const geoLat     = root.dataset.geoLat;
        const geoLng     = root.dataset.geoLng;
        const geoRadius  = root.dataset.geoRadius;
        const required   = root.dataset.geoRequired === '1';

        const lat    = parseFloat(geoLat);
        const lng    = parseFloat(geoLng);
        const radius = parseFloat(geoRadius);

        if (!lat || !lng || !radius) {
            return { enabled:false, required, lat:null, lng:null, radius:null };
        }
        return { enabled:true, required, lat, lng, radius };
    }

    /**
     * requireInside(root, onAllow, onReject)
     * - If no geofence configured → calls onAllow(null) without asking for location.
     * - If geofence set → asks for location, checks radius:
     *   - inside  → onAllow({ latitude, longitude, distance })
     *   - outside / permission error → onReject(message, code)
     */
    function requireInside(root, onAllow, onReject) {
        const cfg = getGeoConfig(root);

        // No geofence on this widget/device → allow, no coords
        if (!cfg.enabled) {
            onAllow && onAllow(null);
            return;
        }

        if (!navigator.geolocation) {
            onReject && onReject(
                'Location is required but this browser does not support it.',
                'NO_GEO'
            );
            return;
        }

        navigator.geolocation.getCurrentPosition(
            pos => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                const dist = haversineMeters(cfg.lat, cfg.lng, lat, lng);

                if (dist > cfg.radius) {
    onReject && onReject(
        'You are outside the allowed area. Please move closer to the workplace and try again.',
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
                let msg;
                if (err.code === err.PERMISSION_DENIED) {
                    msg = 'Location permission was denied. Enable it to use attendance.';
                } else if (err.code === err.POSITION_UNAVAILABLE) {
                    msg = 'Location is unavailable. Check GPS or network.';
                } else if (err.code === err.TIMEOUT) {
                    msg = 'Timed out while getting location. Try again.';
                } else {
                    msg = 'Could not get your location. Try again.';
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

        // Selfie elements (live camera)
        const selfiePanel   = document.getElementById('sfs-att-selfie-panel');
        const selfieVideo   = document.getElementById('sfs-att-selfie-video');
        const selfieCanvas  = document.getElementById('sfs-att-selfie-canvas');
        const selfieCapture = document.getElementById('sfs-att-selfie-capture');
        const selfieCancel  = document.getElementById('sfs-att-selfie-cancel');

        // Fallback file input
        const selfieWrap  = document.getElementById('sfs-att-selfie-wrap');
        const selfieInput = document.getElementById('sfs-att-selfie');

        let selfieStream   = null;
        let pendingType    = null;

        let allowed        = {};
        let state          = 'idle';
        let requiresSelfie = false;
        let refreshing     = false;
        let queued         = false;

        const STATUS_URL = '<?php echo esc_js( $status_url ); ?>';
        const PUNCH_URL  = '<?php echo esc_js( $punch_url ); ?>';
        const NONCE      = '<?php echo esc_js( $nonce ); ?>';

        function setStat(text, mode){
    if (!statusBox) return;

    statusBox.textContent = text || '';
    if (mode) {
        statusBox.dataset.mode = mode;
    } else {
        statusBox.removeAttribute('data-mode');
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
            let label = 'Not clocked in';
            let cls   = 'sfs-att-chip sfs-att-chip--idle';

            if (state === 'in') {
                label = 'Clocked in';
                cls   = 'sfs-att-chip sfs-att-chip--in';
            } else if (state === 'break') {
                label = 'On break';
                cls   = 'sfs-att-chip sfs-att-chip--break';
            } else if (state === 'out') {
                label = 'Clocked out';
                cls   = 'sfs-att-chip sfs-att-chip--out';
            }

            chipEl.textContent = label;
            chipEl.className   = cls;
        }

                async function getGeo(){
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

            // Normal path: enforce radius. If rejected → we abort punch.
            return new Promise((resolve, reject)=>{
                window.sfsGeo.requireInside(
                    root,
                    function onAllow(coords){
                        if (!coords) {
                            // geofence not configured → no coords, but allowed
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
                        setStat(msg || 'Location check failed.', 'error');
                        // Hard block by rejecting; caller will bail
                        reject(new Error('geo_blocked'));
                    }
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
                requiresSelfie = !!j.requires_selfie;
        if (!requiresSelfie) {
            // Make sure selfie UI is hidden if policy changed
            if (selfiePanel) selfiePanel.style.display = 'none';
            if (selfieWrap)  selfieWrap.style.display  = 'none';
        }

                updateChip();
                setStat(j.label || 'Ready', 'idle');

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
                    hint && (hint.textContent = 'Selfie required for this shift. Location may also be required.');
                } else {
                    hint && (hint.textContent = 'Your location may be required. Allow the browser location prompt if asked.');
                }

            } catch(e) {
                setStat('Error: ' + (e.name === 'AbortError' ? 'Request timed out' : e.message), 'error');
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
        }

        async function startSelfie(type){
            pendingType = type;

            // If no mediaDevices API → fall back to file input
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                if (selfieWrap && selfieInput) {
                    selfieWrap.style.display = 'block';
                    setStat('Your device doesn\'t support live camera preview. Capture a selfie, then it will be submitted.', 'error');
                    selfieInput.click();
                } else {
                    setStat('Camera not available on this device.', 'error');
                }
                return;
            }

            if (!selfiePanel || !selfieVideo) {
                setStat('Camera UI not available.', 'error');
                return;
            }

            selfiePanel.style.display = 'block';
            setStat('Starting camera…', 'busy');

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
                setStat('Ready — tap “Capture & Submit”.', 'idle');
            } catch(e) {
                setStat('Camera error: ' + (e.message || e), 'error');
                stopSelfiePreview();
                pendingType = null;
            }
        }

        async function doPunch(type, selfieBlob){
            setStat('Working…', 'busy');

            let geo = null;
            try {
                geo = await getGeo();
            } catch(e){
                // geo_blocked → do not hit the REST API
                return;
            }

            try {
                let resp, text = '', j = null;

                if (requiresSelfie) {
                    const fd = new FormData();
                    fd.append('punch_type', type);
                    fd.append('source', 'self_web');

                    if (geo && typeof geo.lat==='number' && typeof geo.lng==='number') {
                        fd.append('geo_lat', String(geo.lat));
                        fd.append('geo_lng', String(geo.lng));
                        if (typeof geo.acc==='number') fd.append('geo_accuracy_m', String(Math.round(geo.acc)));
                    }

                    if (selfieBlob) {
                        const timestamp = Date.now();
                        fd.append('selfie', selfieBlob, `selfie-${timestamp}.jpg`);
                    } else if (selfieInput && selfieInput.files && selfieInput.files[0]) {
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
                hint.textContent = 'Selfie required for this shift. Location may also be required.';
            }

            // Start the camera UI for the same action (in/out/...)
            await startSelfie(type);
            return; // don't fall through to generic error
        }

        if (!resp.ok) {
            throw new Error((j && j.message) || 'Punch failed');
        }


                await refresh();

            } catch (e) {
                setStat('Error: ' + e.message, 'error');
            }
        }

        async function punch(type){
            if (!allowed[type]) {
                let msg = 'Invalid action.';
                if (type==='out' && state==='break')        msg = 'You are on break. End the break before clocking out.';
                else if (type==='out' && state!=='in')      msg = 'You are not clocked in.';
                else if (type==='in'  && state!=='idle')    msg = 'Already clocked in.';
                else if (type==='break_start' && state!=='in')  msg = 'You can start a break only while clocked in.';
                else if (type==='break_end'   && state!=='break')msg = 'You have no active break to end.';
                setStat('Error: ' + msg, 'error');
                return;
            }

            if (requiresSelfie) {
                // Step 1: choose action (button)
                // Step 2: open camera frame and wait for manual capture
                await startSelfie(type);
            } else {
                await doPunch(type, null);
            }
        }

        // Selfie capture button → grab frame from video → doPunch
        async function captureAndSubmit(){
            if (!pendingType) return;
            if (!selfieVideo || !selfieCanvas) return;

            const vw = selfieVideo.videoWidth;
            const vh = selfieVideo.videoHeight;
            if (!vw || !vh) {
                setStat('Camera not ready yet. Please wait a second.', 'error');
                return;
            }

            const size = Math.min(vw, vh);
            const sx = (vw - size) / 2;
            const sy = (vh - size) / 2;

            const ctx = selfieCanvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(selfieVideo, sx, sy, size, size, 0, 0, selfieCanvas.width, selfieCanvas.height);

            selfieCanvas.toBlob(async function(blob){
                if (!blob) {
                    setStat('Could not capture selfie. Try again.', 'error');
                    return;
                }
                await doPunch(pendingType, blob);
                pendingType = null;
                stopSelfiePreview();
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
            stopSelfiePreview();
            setStat('Cancelled.', 'idle');
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

        // Initial load
        refresh();
    })();
    </script>
    <?php
    return ob_get_clean();
}



/**
 * Kiosk Widget
 */
public function shortcode_kiosk( $atts = [] ): string {
    if ( ! is_user_logged_in() ) { return '<div>Please sign in.</div>'; }
    if ( ! current_user_can( 'sfs_hr_attendance_clock_kiosk' ) && ! current_user_can('sfs_hr_attendance_admin') ) {
        return '<div>Access denied (kiosk only).</div>';
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
    if (!$device) { return '<div>No kiosk device configured.</div>'; }

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
      <h2 class="sfs-title">Attendance Kiosk</h2>
      <h1 id="sfs-greet-<?php echo $inst; ?>" class="sfs-greet">Good day!</h1>


      <!-- hero -->
      <h2 class="sfs-title sr-only">Attendance Kiosk</h2>
<div class="sfs-statusbar">
  <span id="sfs-status-dot-<?php echo $inst; ?>" class="sfs-dot sfs-dot--idle"></span>
  <span id="sfs-status-text-<?php echo $inst; ?>">Ready</span>
</div>


      <!-- lane -->
<div id="sfs-kiosk-lane-<?php echo $inst; ?>" style="gap:8px;align-items:center;margin:10px 0;">
        <strong id="sfs-kiosk-lane-label-<?php echo $inst; ?>" style="min-width:110px;">Current:</strong>
        <span id="sfs-kiosk-lane-chip-<?php echo $inst; ?>" class="sfs-chip sfs-chip--in">Clock In</span>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-left:10px">
          <button type="button" data-action="in"          class="button sfs-lane-btn button-primary">Clock In</button>
          <button type="button" data-action="out"         class="button sfs-lane-btn">Clock Out</button>
          <button type="button" data-action="break_start" class="button sfs-lane-btn">Break Start</button>
          <button type="button" data-action="break_end"   class="button sfs-lane-btn">Break End</button>
        </div>
      </div>




<!-- QR/camera panels -->
<div id="sfs-kiosk-camwrap-<?php echo $inst; ?>" class="sfs-camwrap" style="margin:10px 0;">
  <video id="sfs-kiosk-qr-video-<?php echo $inst; ?>"
         autoplay playsinline webkit-playsinline muted
         style="width:100%;border-radius:8px;background:#000"></video>

  <!-- Hidden square canvas only for snapshot encoding -->
  <canvas id="sfs-kiosk-selfie-<?php echo $inst; ?>" width="640" height="640" hidden></canvas>

  <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
    <button id="sfs-kiosk-qr-exit-<?php echo $inst; ?>" type="button" class="button button-secondary">Exit</button>
    <button id="sfs-kiosk-qr-stop-<?php echo $inst; ?>" type="button" class="button" hidden>Stop Camera</button>
    <span id="sfs-kiosk-qr-status-<?php echo $inst; ?>" style="font-size:12px;color:#646970;"></span>
  </div>
</div>

      

      
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


/* Quick “halo” flash on successful / queued punch */
#<?php echo $root_id; ?>.sfs-kiosk-flash-ok {
  animation: sfs-kiosk-punch-ok 0.28s ease-out;
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
</script>

    <script>
    (function(){
        

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
  if (selfieBlob) fd.append('selfie', selfieBlob, 'selfie.jpg');

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
    return { ok:false, status:0, data:{ message:'Network error' }, code:null, raw:text };
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
    setStat('Punch: ' + type + '…', 'busy');
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
  setStat('Exiting…', 'busy');

  // hard stop camera + QR loop
  stopQr();

  // go back to menu view (CSS hides camera + statusbar)
  ROOT.dataset.view = 'menu';

  // reset main status text so it’s clean next time
  const tag = requiresSelfie ? ' — selfie required' : '';
  setStat('Ready — action: ' + labelFor(currentAction) + tag, 'idle');
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
        setStat('No pending scan. Scan QR again.', 'error');
        return;
      }
      const blob = await captureSelfieFromQrVideo();
      if (!blob) {
        setStat('No camera frame yet. Keep face in frame and try again.', 'error');
        return;
      }
      setStat('Attempting punch…', 'busy');
      const r = await attemptPunch(currentAction, pendingPunch.scanToken, blob, pendingPunch.geox);
      if (r.ok) {
  playActionTone(currentAction);
  flashPunchSuccess('ok');    // halo flash here too
  // flash(currentAction);     // optional: remove

  setStat((r.data?.label || 'Done') + ' — Next', 'ok');
  touchActivity();
  pendingPunch = null;
  manualSelfieMode = false;
  if (capture) capture.style.display = 'none';
  await refresh();
  setTimeout(() => { if (uiMode !== 'error') setStat('Scanning…', 'scanning'); }, 800);
} else {
        playErrorTone();
        setStat(r.data?.message || `Punch failed (HTTP ${r.status})`, 'error');
      }
    } catch (e) {
      playErrorTone();
      setStat('Error: ' + (e.message || e), 'error');
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
    setStat('Ready — choose an action', 'idle');
  } else {
    // scan
    kickScanner();                         // open camera (no auto-return per scan)
    touchActivity();                       // arm idle countdown
    setStat('Scanning…', 'scanning');
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
      setStat('Idle timeout — returning to menu', 'busy');
      returnToMenu();
    }, IDLE_MS);
  }
}

function exitToMenu(){
  stopQr();          // stops tracks + preview
  setMode('menu');   // shows big buttons again
  setStat('Ready — action: ' + labelFor(currentAction) + (requiresSelfie?' — selfie required':''), 'idle');
}

// iOS inline playback hardening
try {
  qrVid.setAttribute('playsinline', '');
  qrVid.setAttribute('webkit-playsinline', '');
  qrVid.muted = true;
} catch (_) {}


function dbg() {
  if (!DBG) return;
  // stringify args safely
  const parts = [];
  for (let i = 0; i < arguments.length; i++) {
    const a = arguments[i];
    try { parts.push(typeof a === 'object' ? JSON.stringify(a) : String(a)); }
    catch (_) { parts.push(String(a)); }
  }
  const msg = parts.join(' ');
  // console
  try { console.log('[ATT]', msg); } catch (_) {}
  // send to server
  try {
    fetch(dbgUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      credentials: 'same-origin',
      body: 'm=' + encodeURIComponent(msg)
    });
  } catch (_) {}
}

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
    setStat('Ready — pick an action', 'idle');
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
const ACTIONS = ['in','out','break_start','break_end'];
let currentAction = 'in';
const laneRoot  = document.getElementById('sfs-kiosk-lane-<?php echo $inst; ?>');
const laneChip  = document.getElementById('sfs-kiosk-lane-chip-<?php echo $inst; ?>');



function labelFor(a){
  switch(a){
    case 'in': return 'Clock In';
    case 'out': return 'Clock Out';
    case 'break_start': return 'Break Start';
    case 'break_end': return 'Break End';
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
    'Ready — action: ' + labelFor(currentAction) + (requiresSelfie ? ' — selfie required' : ''),
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


// Initial lane
setAction('in');

// Prevent re-entrancy while we mint token + punch
let inflight = false;



// Short beep on success (no <audio> tag needed)
function playActionTone(kind){
  const freq = { in: 920, out: 420, break_start: 680, break_end: 560 }[kind] || 750;
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const o = ctx.createOscillator(); const g = ctx.createGain();
    o.type = 'sine'; o.frequency.value = freq;
    o.connect(g); g.connect(ctx.destination);
    g.gain.value = 0.25;
    o.start();
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.22);
    setTimeout(()=>{ o.stop(); ctx.close(); }, 260);
  } catch(_) {}
}
function flash(kind){
  if (!flashEl) return;
  flashEl.className = 'sfs-flash sfs-flash--' + (kind || 'in');
  // trigger reflow to restart animation
  void flashEl.offsetWidth;
  flashEl.classList.add('show');
  setTimeout(()=> flashEl.classList.remove('show'), 220);
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



            async function getGeo(){
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

        return new Promise((resolve, reject)=>{
          window.sfsGeo.requireInside(
            root,
            function onAllow(coords){
              if (!coords) { resolve(null); return; } // no geofence configured
              resolve({
                lat: coords.latitude,
                lng: coords.longitude,
                acc: Math.round(coords.distance || 0)
              });
            },
            function onReject(msg, code){
              setStat(msg || 'Location check failed.', 'error');
              reject(new Error('geo_blocked'));
            }
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
      setStat('Invalid QR', 'error');
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
      setStat('Network error', 'error');
      dbg('scan network error', e && e.message);
      // mild backoff to avoid hammering same frame
      lastQrValue = raw;
      lastQrTs    = Date.now();
      throw e;
    }

    // Try parse JSON; WP may return HTML on error
    try { data = JSON.parse(text); } catch {
      dbg('scan parse error; raw=', (text || '').slice(0, 200));
      setStat('Scan failed: invalid server reply', 'error');
      lastQrValue = raw; lastQrTs = Date.now();
      throw new Error('invalid_json_from_scan');
    }

    // Normalize REST errors
    if (!resp.ok || !data || data.ok === false) {
      const msg  = (data && (data.message || data.msg)) || `HTTP ${resp.status}`;
      const code = (data && (data.code || data.data?.status)) || resp.status;
      setStat(`Scan failed: ${msg}`, 'error');
      dbg('scan failed', { status: resp.status, code, data });
      lastQrValue = raw; lastQrTs = Date.now();
      throw new Error(`scan_failed:${code}:${msg}`);
    }

    // Expect { ok:true, scan_token: "...", employee_id, device_id, ttl }
    const scanToken = data.scan_token;
    if (!scanToken) {
      setStat('Scan failed: no token', 'error');
      dbg('scan ok but no scan_token', data);
      lastQrValue = raw; lastQrTs = Date.now();
      throw new Error('no_scan_token');
    }

    const empName = (data && (data.employee_name || data.name))
  || `Employee #${data.employee_id || emp}`;

setStat(`${empName} ready`, 'ok');
dbg('scan ok', data);

if (empEl) {
  empEl.textContent = empName;
}


    // --- Prepare geo + selfie (if required)
        if (qrStat) qrStat.textContent = 'QR OK — preparing punch…';

    // 1) Enforce geofence
    let geox = null;
    try {
        geox = await getGeo();  // uses sfsGeo + ROOT just like manual punch
    } catch(e){
        // geo_blocked → abort this scan and cool down this frame
        lastQrValue = raw;
        lastQrTs    = Date.now() + (BACKOFF_MS_ERR - QR_COOLDOWN_MS);
        return false; // keep scanner running, but no punch
    }

    // 2) Capture selfie frame (if needed)
    const selfieBlob = await captureSelfieFromQrVideo();

    if (requiresSelfie && !selfieBlob) {
      manualSelfieMode = true;
      pendingPunch = { scanToken, geox };
      if (capture) capture.style.display = '';
      setStat('Keep face in frame and press “Capture Selfie”.', 'error');
      lastQrValue = raw;
      lastQrTs    = Date.now() + (BACKOFF_MS_SLF - QR_COOLDOWN_MS);
      return false;
    }

    if (qrStat) qrStat.textContent = 'QR OK — attempting punch…';
    const r = await attemptPunch(currentAction, scanToken, selfieBlob, geox);


    if (r.ok) {
  playActionTone(currentAction);
  flashPunchSuccess('ok');   // <- use halo on the whole kiosk
  // flash(currentAction);    // optional: remove or keep, it’s a no-op now

  setStat((r.data?.label || 'Done') + ' — Next', 'ok');

  lastQrValue = raw;
  lastQrTs    = Date.now() + BACKOFF_MS_OK;

  await refresh();

  setTimeout(() => {
    if (uiMode !== 'error') setStat('Scanning…', 'scanning');
  }, 800);

  return true;
}
 else {
      const code = (r.data && (r.data.code || r.data?.data?.code)) || '';
      const msg  = r.data?.message || `Punch failed (HTTP ${r.status})`;

      if (r.status === 409) {
        if (code === 'no_shift') {
          setStat('No shift configured. Contact your Manager or HR.', 'error');
        } else if (code === 'invalid_transition') {
          setStat('Invalid action now. Try a different punch type.', 'error');
        } else {
          setStat('No shift, contact your Manager or HR.', 'error');
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
    setStat(`Punch failed: ${punchErr && punchErr.message ? punchErr.message : 'Unknown error'}`, 'error');
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
  const tag = requiresSelfie ? ' — selfie required' : '';
  setStat('Scanning — action: ' + labelFor(currentAction) + tag, 'scanning');

  showScannerUI(true);
  
  try {
  if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
    const devs = await navigator.mediaDevices.enumerateDevices();
    const cams = devs.filter(d => d.kind === 'videoinput');
    if (!cams.length) {
      setStat('No camera found on this device.', 'error');
      return;
    }
  }
} catch (_) { /* ignore – fallback to getUserMedia errors */ }

  dbg('startQr: begin, requiresSelfie=', requiresSelfie, 'BarcodeDetector in window?', 'BarcodeDetector' in window);

  if (!('jsQR' in window)) dbg('startQr: jsQR not yet loaded, will poll');

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
    setStat('No camera API available.', 'error');
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

  if (!useBarcodeDetector && typeof window.jsQR !== 'function'){
    setStat('Loading QR engine…', 'busy');
    const t0 = Date.now();
    while (typeof window.jsQR !== 'function' && (Date.now() - t0) < 3000) {
      await new Promise(r=>setTimeout(r,100));
    }
    dbg('startQr: jsQR loaded?', typeof window.jsQR === 'function');
    if (typeof window.jsQR !== 'function'){
  setStat('QR engine not loaded. Please wait and press Start again.', 'error');
  
  if (qrStop)  qrStop.disabled  = true;
  return;
}
  }

  const constraints = {
    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
    audio: false
  };

  try {
    dbg('startQr: getUserMedia requesting…', constraints.video);
    qrStream = await navigator.mediaDevices.getUserMedia(constraints);
    qrVid.srcObject = qrStream;
    await new Promise(r => qrVid.onloadedmetadata = r);
    dbg('startQr: stream ready', { w: qrVid.videoWidth, h: qrVid.videoHeight });

    try { await qrVid.play(); } catch(e) { dbg('startQr: play() err', e && e.message); }
    
// after await qrVid.play();
if (document.getElementById('sfs-kiosk-canvas-<?php echo $inst; ?>')) {
  startSelfiePreview();
}

    
    if (qrStop)  qrStop.disabled  = false;
    if (qrStat)  qrStat.textContent = 'Scanning…';
    startSelfiePreview();


    // Live selfie preview when required
    if (requiresSelfie) dbg('startQr: selfie preview active');

    qrRunning = true;
    touchActivity();  
    if (camwrap) camwrap.style.display = 'grid';


    // --- Offscreen canvas for scanning
    const scanCanvas = document.createElement('canvas');
    const sctx = scanCanvas.getContext('2d', { willReadFrequently: true });

    const ensureCanvasSize = () => {
      const w = qrVid.videoWidth  || 640;
      const h = qrVid.videoHeight || 480;
      if (w === 0 || h === 0) return false;
      if (scanCanvas.width !== w || scanCanvas.height !== h){
        scanCanvas.width = w; scanCanvas.height = h;
        dbg('ensureCanvasSize: set', {w,h});
      }
      return true;
    };

// ====== TICK LOOP ======
const tick = async () => {
  if (!qrRunning) return;

  // If a request is in-flight, just loop again
  if (inflight) { qrLoop = requestAnimationFrame(tick); return; }

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
    if (qrStat.textContent === '' || qrStat.textContent === 'Scanning…') {
      qrStat.textContent = 'Scanning…';
      lastUIBeat = Date.now();
    }
  }

  qrLoop = requestAnimationFrame(tick);
};



    qrLoop = requestAnimationFrame(tick);
    dbg('startQr: tick loop started');

  } catch (e) {
    setStat('Camera error: ' + (e && e.message ? e.message : e), 'error');
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

    const tag = requiresSelfie ? ' — selfie required' : '';
setStat('Ready — action: ' + labelFor(currentAction) + tag, currentAction);


// Device-level features/policy
requiresSelfie = !!j.requires_selfie;
const qrOn = (typeof j.qr_enabled === 'boolean') ? j.qr_enabled : true;




// Single, unified status line

setStat('Ready — action: ' + labelFor(currentAction) + tag, 'idle');
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
    setStat('Error: ' + (e.message || 'Status fetch failed'), 'error');
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
    canvas.toBlob(blob => resolve(blob), 'image/jpeg', 0.9);
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
    h < 12 ? 'Good morning!' :
    h < 18 ? 'Good afternoon!' :
             'Good evening!';
})();




      async function punch(type){
        if (!allowed[type]) {
          let msg = 'Invalid action.';
          if (type==='out' && state==='break')             msg = 'Employee is on break. End the break before clocking out.';
          else if (type==='out' && state!=='in')           msg = 'Employee is not clocked in.';
          else if (type==='in'  && state!=='idle')         msg = 'Already clocked in.';
          else if (type==='break_start' && state!=='in')   msg = 'Break can start only while clocked in.';
          else if (type==='break_end'   && state!=='break')msg = 'No active break to end.';
          setStat('Error: ' + msg, 'error');
          return;
        }


                setStat('Working…', 'busy');

        let geo = null;
        try {
            geo = await getGeo();
        } catch(e){
            // geo_blocked → stop here
            return;
        }

        let headers = {'X-WP-Nonce':nonce};

        let body;

        if (requiresSelfie) {
          if (!lastBlob) { setStat('Error: please capture a selfie first.', 'error'); return; }
          const fd = new FormData();
          fd.append('punch_type', String(type));
          fd.append('source', 'kiosk');
          fd.append('device', String(DEVICE_ID));
          if (geo && typeof geo.lat==='number' && typeof geo.lng==='number') {
            fd.append('geo_lat', String(geo.lat));
            fd.append('geo_lng', String(geo.lng));
            
            if (typeof geo.acc==='number') fd.append('geo_accuracy_m', String(Math.round(geo.acc)));
          }
          fd.append('selfie', lastBlob, 'kiosk-selfie.jpg');
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
          setStat(j.label || 'Done', 'ok');

          lastBlob = null;
          await refresh();
          ROOT.querySelectorAll('button[data-action]').forEach(b=>b.disabled = true);
          setTimeout(()=>ROOT.querySelectorAll('button[data-action]').forEach(b=>b.disabled = !allowed[b.getAttribute('data-type')]), 3000);
        }catch(e){
          setStat('Error: ' + e.message, 'error');
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
            KEY punch_time (punch_time)
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
            net_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            rounded_net_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('present','late','left_early','absent','incomplete','on_leave','holiday') NOT NULL DEFAULT 'present',
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
            break_policy ENUM('auto','punch','none') NOT NULL DEFAULT 'auto',
            grace_late_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5,
            grace_early_leave_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5,
            rounding_rule ENUM('none','5','10','15') NOT NULL DEFAULT '5',
            overtime_after_minutes SMALLINT UNSIGNED NULL,
            require_selfie TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            dept ENUM('office','showroom','warehouse','factory') NOT NULL,
            notes TEXT NULL,
            weekly_overrides TEXT NULL,
            PRIMARY KEY (id),
            KEY active_dept (active, dept),
            KEY dept (dept)
        ) $charset_collate;");

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
            allowed_dept ENUM('office','showroom','warehouse','factory','any') NOT NULL DEFAULT 'any',
            fingerprint_hash VARCHAR(64) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            qr_enabled TINYINT(1) NOT NULL DEFAULT 1,
            selfie_mode ENUM('inherit','never','in_only','in_out','all') NOT NULL DEFAULT 'inherit',
            PRIMARY KEY (id),
            KEY active_type (active, type),
            KEY fp (fingerprint_hash)
        ) $charset_collate;");


// Harden upgrades: add columns if old table exists without them
$t = "{$p}sfs_hr_attendance_devices";
self::add_column_if_missing($wpdb, $t, 'kiosk_enabled',     "kiosk_enabled TINYINT(1) NOT NULL DEFAULT 0");
self::add_column_if_missing($wpdb, $t, 'kiosk_offline',     "kiosk_offline TINYINT(1) NOT NULL DEFAULT 0");
self::add_column_if_missing($wpdb, $t, 'geo_lock_lat',      "geo_lock_lat DECIMAL(10,7) NULL");
self::add_column_if_missing($wpdb, $t, 'geo_lock_lng',      "geo_lock_lng DECIMAL(10,7) NULL");
self::add_column_if_missing($wpdb, $t, 'geo_lock_radius_m', "geo_lock_radius_m SMALLINT UNSIGNED NULL");
self::add_column_if_missing($wpdb, $t, 'allowed_dept',      "allowed_dept ENUM('office','showroom','warehouse','factory','any') NOT NULL DEFAULT 'any'");
self::add_column_if_missing($wpdb, $t, 'active',            "active TINYINT(1) NOT NULL DEFAULT 1");
self::add_column_if_missing($wpdb, $t, 'qr_enabled',        "qr_enabled TINYINT(1) NOT NULL DEFAULT 1");
self::add_column_if_missing($wpdb, $t, 'selfie_mode',       "selfie_mode ENUM('inherit','never','in_only','in_out','all') NOT NULL DEFAULT 'inherit'");


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
            'web_allowed_by_dept' => [
                'office'    => true,
                'showroom'  => true,
                'warehouse' => false,
                'factory'   => false,
            ],
            'selfie_retention_days' => 30,
            'default_rounding_rule' => '5',
            'default_grace_late'    => 5,
            'default_grace_early'   => 5,
            
            // Add below 'default_grace_early'
'dept_weekly_segments' => [
  // All times are local (site timezone); engine converts to UTC.
  'showroom' => [
    'sun' => [ ['09:00','12:00'], ['16:00','22:00'] ],
    'mon' => [ ['09:00','12:00'], ['16:00','22:00'] ],
    'tue' => [ ['09:00','12:00'], ['16:00','22:00'] ],
    'wed' => [ ['09:00','12:00'], ['16:00','22:00'] ],
    'thu' => [ ['09:00','12:00'], ['16:00','20:00'] ], // short PM
    'fri' => [ /* off */ ],
    'sat' => [ ['09:00','12:00'], ['16:00','22:00'] ],
  ],
  'factory' => [
    'sun' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'mon' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'tue' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'wed' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'thu' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'fri' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'sat' => [ ['07:00','12:00'], ['13:00','16:00'] ],
  ],
  'warehouse' => [
    'sun' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'mon' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'tue' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'wed' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'thu' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'fri' => [ ['07:00','12:00'], ['13:00','16:00'] ],
    'sat' => [ ['07:00','12:00'], ['13:00','16:00'] ],
  ],
  'office' => [
    'sun' => [ ['09:00','17:00'] ],
    'mon' => [ ['09:00','17:00'] ],
    'tue' => [ ['09:00','17:00'] ],
    'wed' => [ ['09:00','17:00'] ],
    'thu' => [ ['09:00','17:00'] ],
    'fri' => [ ['09:00','17:00'] ],
    'sat' => [ ['09:00','17:00'] ],
  ],
],
// Selfie policy (optional by default)
'selfie_policy' => [
  // modes: never | in_only | in_out | all | outside_geofence
  'dept' => [
    'showroom'  => 'never',
    'factory'   => 'in_only',
    'warehouse' => 'in_only',
    'office'    => 'never',
  ],
  'employee_overrides' => [ /* emp_id => mode */ ],
  'kiosk_overrides'    => [ /* device_id => mode */ ],
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
        $rows = [
            [
                'label'            => 'Warehouse Kiosk #1',
                'type'             => 'kiosk',
                'kiosk_enabled'    => 1,
                'kiosk_pin'        => null,
                'kiosk_offline'    => 1,
                'last_sync_at'     => null,
                'geo_lock_lat'     => null,
                'geo_lock_lng'     => null,
                'geo_lock_radius_m'=> null,
                'allowed_dept'     => 'warehouse',
                'fingerprint_hash' => null,
                'active'           => 1,
                'meta_json'        => wp_json_encode( ['seeded_at'=>$now] ),
            ],
            [
                'label'            => 'Factory Kiosk #1',
                'type'             => 'kiosk',
                'kiosk_enabled'    => 1,
                'kiosk_pin'        => null,
                'kiosk_offline'    => 1,
                'last_sync_at'     => null,
                'geo_lock_lat'     => null,
                'geo_lock_lng'     => null,
                'geo_lock_radius_m'=> null,
                'allowed_dept'     => 'factory',
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

    // Dept slug
$dept = self::get_employee_dept_for_attendance($employee_id, $wpdb) ?: 'office';

// Segments for this date
$segments = self::build_segments_for_date_from_dept($dept, $ymd);

// Local-day → UTC window
$tz        = wp_timezone();
$dayLocal  = new \DateTimeImmutable($ymd . ' 00:00:00', $tz);
$nextLocal = $dayLocal->modify('+1 day');
$startUtc  = $dayLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$endUtc    = $nextLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

// Pull all punches for that window
$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT punch_type, punch_time, valid_geo, valid_selfie
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
    $net = (int)$ev['worked_total'];
    if ($roundN > 0) $net = (int)round($net / $roundN) * $roundN;

    $scheduled = (int)$ev['scheduled_total'];
    $ot = max(0, $net - $scheduled);

    // Status rollup
    $status = 'present';
    if (!$segments || count($segments)===0) {
        $status = 'holiday'; // showroom Friday off → scheduled 0
    } elseif (in_array('incomplete', $ev['flags'], true)) {
        $status = 'incomplete';
    } elseif (in_array('missed_segment', $ev['flags'], true) && $net === 0) {
        $status = 'absent';
    } elseif (in_array('missed_segment', $ev['flags'], true)) {
        $status = 'present';
    }
    if (in_array('left_early',$ev['flags'],true)) $status = ($status==='present' ? 'left_early' : $status);
    if (in_array('late',$ev['flags'],true))       $status = ($status==='present' ? 'late'       : $status);

    // Geo/selfie counters (for completeness)
    $outside_geo = 0; $no_selfie = 0;
    foreach ($rows as $r) {
        if ((int)$r->valid_geo === 0)    $outside_geo++;
        if ((int)$r->valid_selfie === 0) $no_selfie++;
    }

    $flags = array_values(array_unique($ev['flags']));
    if ($outside_geo > 0) $flags[] = 'outside_geofence';
    if ($no_selfie > 0)   $flags[] = 'no_selfie';

    $calcMeta = [
        'dept'            => $dept,
        'segments'        => $ev['segments'],
        'scheduled_total' => $scheduled,
        'rounded_rule'    => $round,
        'grace'           => ['late'=>$grLate,'early'=>$grEarly],
        'counters'        => ['outside_geo'=>$outside_geo,'no_selfie'=>$no_selfie],
    ];

    $data = [
        'employee_id'         => $employee_id,
        'work_date'           => $ymd,
        'in_time'             => $firstIn,
        'out_time'            => $lastOut,
        'break_minutes'       => 0,
        'net_minutes'         => (int)$ev['worked_total'],
        'rounded_net_minutes' => $net,
        'overtime_minutes'    => $ot,
        'status'              => $status,
        'flags_json'          => wp_json_encode($flags),
        'calc_meta_json'      => wp_json_encode($calcMeta),
        'last_recalc_at'      => current_time('mysql', true),
    ];

    $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$sT} WHERE employee_id=%d AND work_date=%s LIMIT 1", $employee_id, $ymd) );
    if ($exists) { $wpdb->update($sT, $data, ['id'=>(int)$exists]); }
    else         { $wpdb->insert($sT, $data); }
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
     *   - "Showroom A" / "Branch" / "Store"   → "showroom"
     *   - "Warehouse" / "Storehouse"          → "warehouse"
     *   - "Factory" / "Production"            → "factory"
     */
    private static function normalize_work_location( string $raw ): string {
        $s = strtolower( trim( $raw ) );
        if ( $s === '' ) {
            return '';
        }

        // Showroom
        if ( str_contains( $s, 'showroom' ) || str_contains( $s, 'branch' ) || str_contains( $s, 'shop' ) ) {
            return 'showroom';
        }

        // Warehouse / stock
        if (
            str_contains( $s, 'warehouse' ) ||
            str_contains( $s, 'storehouse' ) ||
            str_contains( $s, 'inventory' ) ||
            str_contains( $s, 'stock' )
        ) {
            return 'warehouse';
        }

        // Factory / production / install
        if (
            str_contains( $s, 'factory' ) ||
            str_contains( $s, 'production' ) ||
            str_contains( $s, 'plant' ) ||
            str_contains( $s, 'install' )
        ) {
            return 'factory';
        }

        // Everything else treated as office by default
        return 'office';
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
     * Example result: 'office', 'showroom', 'warehouse', 'factory', or ''.
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
        if (!isset($row->dept) || $row->dept === null) {
            // derive a readable label for downstream checks
            $emp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$empT} WHERE id=%d", $employee_id));
            $label = $emp && isset($emp->department) && $emp->department !== '' ? (string)$emp->department : 'office';
            $row->dept = sanitize_title($label);
        }
        return self::apply_weekly_override( $row, $ymd, $wpdb );
    }

    // --- 1.5) Employee-specific shift (from emp_shifts mapping)
    $emp_shift = self::lookup_emp_shift_for_date( $employee_id, $ymd );
    if ( $emp_shift ) {
        // Get employee dept for context
        $emp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$empT} WHERE id=%d", $employee_id));
        $dept_label = $emp && isset($emp->department) && $emp->department !== '' ? (string)$emp->department : 'office';

        // Set dept and other required fields
        $emp_shift->dept       = sanitize_title($dept_label);
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

    // DEBUG (safe; remove later)
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log(
            '[SFS ATT] auto.source='.$auto['source'].
            ' keytype='.$keytype.
            ' candidates='.implode('|',$candidates).
            ' hit='.( $conf ? 'yes':'no' )
        );
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
                $sh->dept       = $dept_slug ?: ($dept_name ?: 'office');
                return self::apply_weekly_override( $sh, $ymd, $wpdb );
            }
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[SFS ATT] automation shift_id '.$shift_id.' not found/active');
            }
        }
    }

    // --- 4) Optional fallback by dept slug to keep system usable
    if ($dept_slug) {
        $fb = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$shiftT} WHERE active=1 AND dept=%s ORDER BY id ASC LIMIT 1",
                $dept_slug
            )
        );
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
 * If the shift has weekly_overrides configured for the day-of-week,
 * load and return the override shift instead.
 */
private static function apply_weekly_override( ?\stdClass $shift, string $ymd, \wpdb $wpdb = null ): ?\stdClass {
    if ( ! $shift ) {
        return $shift;
    }

    // Check if shift has weekly_overrides
    if ( empty( $shift->weekly_overrides ) ) {
        return $shift;
    }

    $wpdb = $wpdb ?: $GLOBALS['wpdb'];

    // Decode weekly overrides JSON
    $overrides = json_decode( $shift->weekly_overrides, true );
    if ( ! is_array( $overrides ) || empty( $overrides ) ) {
        return $shift;
    }

    // Get day of week from date (monday, tuesday, etc.)
    $tz = wp_timezone();
    $date = new \DateTimeImmutable( $ymd . ' 00:00:00', $tz );
    $day_of_week = strtolower( $date->format( 'l' ) ); // monday, tuesday, etc.

    // Check if there's an override for this day
    if ( ! isset( $overrides[ $day_of_week ] ) || (int) $overrides[ $day_of_week ] <= 0 ) {
        return $shift;
    }

    $override_shift_id = (int) $overrides[ $day_of_week ];

    // Load the override shift
    $shiftT = $wpdb->prefix . 'sfs_hr_attendance_shifts';
    $override_shift = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$shiftT} WHERE id=%d AND active=1 LIMIT 1",
            $override_shift_id
        )
    );

    if ( ! $override_shift ) {
        // Override shift not found or inactive, return original
        return $shift;
    }

    // Preserve key properties from original shift
    $override_shift->__virtual = $shift->__virtual ?? 0;
    $override_shift->is_holiday = $shift->is_holiday ?? 0;
    $override_shift->dept = $shift->dept ?? 'office';

    return $override_shift;
}


/** Build split segments for Y-m-d from dept + settings. */
private static function build_segments_for_date_from_dept(string $dept, string $ymd): array {
    $settings = get_option(self::OPT_SETTINGS) ?: [];
    $map = $settings['dept_weekly_segments'][$dept] ?? null;
    if (!$map) { $map = $settings['dept_weekly_segments']['office'] ?? []; }

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

public static function selfie_mode_for( int $employee_id, string $dept, array $ctx = [] ): string {
    // Global options
    $opt    = get_option( self::OPT_SETTINGS, [] );
    $policy = is_array( $opt ) ? ( $opt['selfie_policy'] ?? [] ) : [];

    $default_mode = $policy['default'] ?? 'optional'; // optional | never | in_only | in_out | all
    $dept_modes   = $policy['by_dept'] ?? [];
    $emp_modes    = $policy['by_employee'] ?? [];

    // 1) Base: default
    $mode = $default_mode;

    // 2) Department override
    if ( $dept !== '' && ! empty( $dept_modes[ $dept ] ) ) {
        $mode = (string) $dept_modes[ $dept ];
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

    // 5) Shift-level minimum requirement
    $shift_requires = ! empty( $ctx['shift_requires'] );

    if ( $shift_requires ) {
        // Shift says "selfie required" → we must NOT end up with "never" or "optional"
        if ( $mode === 'never' ) {
            $mode = 'in_only';
        } elseif ( $mode === 'optional' ) {
            $mode = 'in_out';
        }
        // If device/policy already say in_only / in_out / all we don't downgrade it
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

    $flags = [];
    $worked_total = 0;
    $scheduled_total = 0;
    $seg_details = [];

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

        if ($ovMin === 0) {
            $segFlags[] = 'missed_segment';
        } else {
            if ($firstIn !== null && ($firstIn - $S) > ($graceLateMin*60))  $segFlags[] = 'late';
            if ($lastOut  !== null && ($E - $lastOut) > ($graceEarlyMin*60)) $segFlags[] = 'left_early';
        }
        $worked_total += $ovMin;
        $flags = array_values(array_unique(array_merge($flags, $segFlags)));
        $seg_details[] = [
            'start' => $seg['start_l'],
            'end'   => $seg['end_l'],
            'scheduled_min' => $seg['minutes'],
            'worked_min'    => $ovMin,
            'flags' => $segFlags,
        ];
    }

    if ($has_unmatched) $flags[] = 'incomplete';
    $flags = array_values(array_unique($flags));

    return [
        'worked_total'    => $worked_total,
        'scheduled_total' => $scheduled_total,
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
