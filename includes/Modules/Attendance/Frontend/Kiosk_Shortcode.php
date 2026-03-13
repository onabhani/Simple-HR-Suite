<?php
namespace SFS\HR\Modules\Attendance\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Kiosk_Shortcode
 *
 * Extracted from AttendanceModule::shortcode_kiosk().
 * Renders the kiosk attendance interface (QR scanning, camera, punch).
 */
class Kiosk_Shortcode {

    /**
     * Render the kiosk shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function render( array $atts = [] ): string {
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
  var apCtrl = new AbortController();
  var apTimer = setTimeout(function(){ apCtrl.abort(); }, selfieBlob ? 30000 : 15000);
  try {
    res  = await fetch(punchUrl, {
      method: 'POST',
      headers: { 'X-WP-Nonce': nonce },
      credentials: 'same-origin',
      body: fd,
      signal: apCtrl.signal
    });
    text = await res.text();
    try { json = JSON.parse(text); } catch(_) {}
  } catch (e) {
    dbg('attemptPunch network error', e && (e.message || e));
    if (e.name === 'AbortError') {
      return { ok:false, status:0, data:{ message: t.request_timed_out || 'Request timed out. Please try again.' }, code:'timeout', raw:'' };
    }

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
  } finally {
    clearTimeout(apTimer);
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
      let qrEpoch = 0; // generation counter to prevent stale async RAF ticks

      // Persistent off-screen canvas for QR scanning (reused across startQr calls)
      const _scanCanvas = document.createElement('canvas');
      const _scanCtx = _scanCanvas.getContext('2d', { willReadFrequently: true });
      // Reusable ImageData buffer for jsQR (avoids per-frame allocation)
      let _scanImageData = null;

      // Shared AudioContext for tones (avoids hitting browser limit)
      let _sharedAudioCtx = null;
      function getAudioCtx() {
        if (_sharedAudioCtx && _sharedAudioCtx.state !== 'closed') return _sharedAudioCtx;
        try { _sharedAudioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(_) { _sharedAudioCtx = null; }
        return _sharedAudioCtx;
      }
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
// Shared AudioContext — reused across punches to avoid resource accumulation
let _sharedAudioCtx = null;
function getAudioCtx(){
  if (!_sharedAudioCtx || _sharedAudioCtx.state === 'closed') {
    _sharedAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
  return _sharedAudioCtx;
}
async function playActionTone(kind){
  const freq = { in: 920, out: 420, break_start: 680, break_end: 560 }[kind] || 750;
  try {
    const ctx = getAudioCtx();
    if (!ctx) return;
    if (ctx.state === 'suspended') {
      await ctx.resume();
    }
    const o = ctx.createOscillator(); const g = ctx.createGain();
    o.type = 'sine'; o.frequency.value = freq;
    o.connect(g); g.connect(ctx.destination);
    g.gain.value = 0.25;
    o.start();
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.22);
    setTimeout(()=>{ try { o.stop(); o.disconnect(); g.disconnect(); } catch(_){} }, 260);
  } catch(e) {
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
    const ctx = getAudioCtx();
    if (!ctx) return;
    if (ctx.state === 'suspended') ctx.resume().catch(()=>{});
    const o = ctx.createOscillator(); const g = ctx.createGain();
    o.type = 'square'; o.frequency.value = 220;
    o.connect(g); g.connect(ctx.destination);
    o.start();
    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.25);
    setTimeout(()=>{ try { o.stop(); o.disconnect(); g.disconnect(); } catch(_){} }, 280);
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

    // --- Reuse persistent off-screen canvas for scanning ---
    const scanCanvas = _scanCanvas;
    const sctx = _scanCtx;
    let lastScanTs = 0;
    const SCAN_INTERVAL_MS = 150; // throttle QR decode to ~7fps to reduce GC pressure

    const ensureCanvasSize = () => {
      const w = qrVid.videoWidth  || 640;
      const h = qrVid.videoHeight || 480;
      if (w === 0 || h === 0) return false;
      if (scanCanvas.width !== w || scanCanvas.height !== h){
        scanCanvas.width = w;
        scanCanvas.height = h;
        _scanImageData = null; // invalidate cached ImageData on resize
        dbg('ensureCanvasSize: set', {w,h});
      }
      return true;
    };

    // Capture epoch at start of this scanner session
    const myEpoch = qrEpoch;

    // ====== TICK LOOP ======
    const tick = async () => {
      // Bail if scanner was stopped or restarted (epoch changed)
      if (!qrRunning || myEpoch !== qrEpoch) return;

      // If a request is in-flight, just loop again
      if (inflight) {
        qrLoop = requestAnimationFrame(tick);
        return;
      }

      try {
        if (!ensureCanvasSize()) {
          setStat('Camera not ready…', 'busy');
          dbg('tick: camera not ready (videoWidth/Height=0)');
          if (qrRunning && myEpoch === qrEpoch) qrLoop = requestAnimationFrame(tick);
          return;
        }

        // Throttle QR decoding to reduce ImageData allocation / GC pressure
        const now = Date.now();
        let payload = null;
        if (now - lastScanTs >= SCAN_INTERVAL_MS) {
          lastScanTs = now;

          const w = scanCanvas.width, h = scanCanvas.height;
          sctx.drawImage(qrVid, 0, 0, w, h);

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

          // (B) jsQR fallback path — reuse ImageData buffer to reduce GC pressure
          if (!payload && typeof window.jsQR === 'function') {
            try {
              if (!_scanImageData || _scanImageData.width !== w || _scanImageData.height !== h) {
                _scanImageData = sctx.getImageData(0, 0, w, h);
              } else {
                const fresh = sctx.getImageData(0, 0, w, h);
                _scanImageData.data.set(fresh.data);
              }
              const code = jsQR(_scanImageData.data, w, h, { inversionAttempts: 'attemptBoth' });
              if (code && code.data) {
                payload = String(code.data);
                dbg('tick: jsQR hit');
              }
            } catch (err) {
              dbg('tick: jsQR error (non-fatal)', err && (err.message || err));
            }
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

      // Re-check epoch after async work — bail if scanner was restarted
      if (qrRunning && myEpoch === qrEpoch) qrLoop = requestAnimationFrame(tick);
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
  qrEpoch++; // invalidate any in-flight async tick callbacks
  if (camBadge) { camBadge.textContent = (window.SFS_ATT_I18N||{}).camera_off||'Camera Off'; camBadge.classList.add('off'); }
  showScannerUI(false);
  if (qrLoop) { cancelAnimationFrame(qrLoop); qrLoop = null; }
  if (selfiePreviewLoop) { cancelAnimationFrame(selfiePreviewLoop); selfiePreviewLoop = null; }
  if (qrStream) { try { qrStream.getTracks().forEach(t=>t.stop()); } catch(_) {} qrStream = null; }
  if (qrVid) qrVid.srcObject = null;

  // Clear inline display styles so CSS [data-view="menu"] rules take effect
  if (camwrap) camwrap.style.display = '';
  if (qrWrap) qrWrap.style.display = '';

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
  if (scanLog.length > 50) scanLog.length = 50;

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

        var kpCtrl = new AbortController();
        var kpTimer = setTimeout(function(){ kpCtrl.abort(); }, requiresSelfie ? 30000 : 15000);
        try{
          const r = await fetch(punchUrl, { method:'POST', headers, credentials:'same-origin', body, signal: kpCtrl.signal });
          const j = await r.json();
          if (!r.ok) throw new Error(j.message || 'Punch failed');
          setStat(j.label || t.done_label||'Done', 'ok');

          lastBlob = null;
          await refresh();
          ROOT.querySelectorAll('button[data-action]').forEach(b=>b.disabled = true);
          setTimeout(()=>ROOT.querySelectorAll('button[data-action]').forEach(b=>b.disabled = !allowed[b.getAttribute('data-type')]), 3000);
        }catch(e){
          var kpMsg = (e.name === 'AbortError')
              ? ((t.error_prefix||'Error:') + ' ' + (t.request_timed_out || 'Request timed out. Please try again.'))
              : ((t.error_prefix||'Error:') + ' ' + e.message);
          setStat(kpMsg, 'error');
        }finally{
          clearTimeout(kpTimer);
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
}
