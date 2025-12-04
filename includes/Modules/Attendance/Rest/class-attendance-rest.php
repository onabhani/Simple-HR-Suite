<?php
namespace SFS\HR\Modules\Attendance\Rest;

use SFS\HR\Modules\Attendance\AttendanceModule;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Public_REST
 * Version: 0.1.2-punch
 * Author: Omar Alnabhani (hdqah.com)
 *
 * Routes:
 *  - POST /sfs-hr/v1/attendance/punch
 *  - GET  /sfs-hr/v1/attendance/status
 */
class Public_REST {


    public static function register(): void {
     // Register routes immediately (AttendanceModule already wires us under rest_api_init)
    self::routes();
    // Ensure no-cache headers are attached once
    add_filter('rest_post_dispatch', [ __CLASS__, 'nocache_headers' ], 99, 3);
}



public static function routes(): void {
    // POST /sfs-hr/v1/attendance/punch
    register_rest_route('sfs-hr/v1', '/attendance/punch', [
        'methods'  => 'POST',
        'callback' => [ __CLASS__, 'punch' ],
        'permission_callback' => [ __CLASS__, 'can_punch' ],
        'args' => [
            'punch_type' => [
                'type' => 'string', 'required' => true,
                'enum' => [ 'in','out','break_start','break_end' ],
            ],
            'source' => [ 'type'=>'string', 'required'=>false, 'default'=>'self_web' ],
            'employee_scan_token' => [ 'type'=>'string', 'required'=>false ],
            'geo_lat' => [
                'required'=>false,
                'validate_callback'=>fn($v)=>$v===null||$v===''||is_numeric($v),
                'sanitize_callback'=>fn($v)=>($v===''||$v===null)?null:(float)$v,
            ],
            'geo_lng' => [
                'required'=>false,
                'validate_callback'=>fn($v)=>$v===null||$v===''||is_numeric($v),
                'sanitize_callback'=>fn($v)=>($v===''||$v===null)?null:(float)$v,
            ],
            'geo_accuracy_m' => [
                'required'=>false,
                'validate_callback'=>fn($v)=>$v===null||$v===''||is_numeric($v),
                'sanitize_callback'=>fn($v)=>($v===''||$v===null)?null:(int)$v,
            ],
            'selfie_media_id' => [
                'required'=>false,
                'validate_callback'=>fn($v)=>$v===null||$v===''||is_numeric($v),
                'sanitize_callback'=>fn($v)=>($v===''||$v===null)?null:(int)$v,
            ],
            'device' => [ 'type'=>'integer', 'required'=>false ],
            'selfie_data_url' => [ 'type'=>'string', 'required'=>false ],
        ],
    ]);

    // GET /sfs-hr/v1/attendance/status
    register_rest_route('sfs-hr/v1', '/attendance/status', [
        'methods'  => 'GET',
        'callback' => [ __CLASS__, 'status' ],
        // public JSON; we only include self snapshot when logged in
        'permission_callback' => '__return_true',
    ]);

    // GET /sfs-hr/v1/attendance/scan  (mint short-lived scan token for kiosk)
    register_rest_route('sfs-hr/v1', '/attendance/scan', [
        'methods'  => 'GET',
        'callback' => [ __CLASS__, 'scan' ],
        'permission_callback' => function () {
            return is_user_logged_in() && (
                current_user_can('sfs_hr_attendance_clock_kiosk') ||
                current_user_can('sfs_hr_attendance_admin')
            );
        },
        'args' => [
            'emp'    => [ 'type'=>'integer', 'required'=>true ],
            'token'  => [ 'type'=>'string',  'required'=>true ],
            'device' => [ 'type'=>'integer', 'required'=>false ],
        ],
    ]);
}




/** Store a one-time scan token using options (works across nodes). */
private static function put_scan_token(string $token, array $payload, int $ttl = 300): void {
    $key = 'sfs_hr_scan_' . $token;
    // avoid clobbering an existing token
    if ( get_option($key, null) !== null ) return;
    add_option($key, ['data' => $payload, 'exp' => time() + $ttl], '', false);
}



/** Consume & delete the token; returns payload or null if missing/expired. */
private static function pop_scan_token(string $token): ?array {
    $key = 'sfs_hr_scan_' . $token;
    $row = get_option($key, null);
    if ($row === null || !is_array($row)) return null;
    if (empty($row['exp']) || time() > (int)$row['exp']) {
        delete_option($key);
        return null;
    }
    delete_option($key);
    return isset($row['data']) && is_array($row['data']) ? $row['data'] : null;
}

/** Peek (do not consume) a scan token; returns payload or null if missing/expired. */
private static function get_scan_token( string $token ): ?array {
    $key = 'sfs_hr_scan_' . $token;
    $row = get_option( $key, null );
    if ( $row === null || ! is_array( $row ) ) return null;
    if ( empty( $row['exp'] ) || time() > (int) $row['exp'] ) return null;
    return ( isset( $row['data'] ) && is_array( $row['data'] ) ) ? $row['data'] : null;
}


public static function nocache_headers( $result, $server, $request ) {
    // Limit to our namespace/routes
    $route = is_object($request) ? (string) $request->get_route() : '';
    if ($route === '' || strpos($route, '/sfs-hr/v1/attendance/') !== 0) {
        return $result;
    }

    if ( ! headers_sent() ) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    return $result;
}


    /* ---------------- Permissions ---------------- */

    public static function can_view_self(): bool {
        return is_user_logged_in() && current_user_can( 'sfs_hr_attendance_view_self' );
    }
    public static function can_punch_self(): bool {
        return is_user_logged_in() && current_user_can( 'sfs_hr_attendance_clock_self' );
    }
    
    public static function can_punch(): bool {
    if ( ! is_user_logged_in() ) return false;

    // Allow normal self punches
    if ( current_user_can('sfs_hr_attendance_clock_self') ) return true;

    // Allow kiosk operators / attendance admins to hit the endpoint
    if ( current_user_can('sfs_hr_attendance_clock_kiosk') ) return true;
    if ( current_user_can('sfs_hr_attendance_admin') ) return true;

    return false;
}


    /* ---------------- Handlers ---------------- */
public static function scan( \WP_REST_Request $req ) {
    global $wpdb;

    $emp    = (int) $req->get_param('emp');
    $qrTok  = sanitize_text_field( (string) $req->get_param('token') );
    $device = (int) ( $req->get_param('device') ?: 0 );

    // Basic employee existence check
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE id=%d LIMIT 1", $emp
    ) );
    if ( ! $exists ) {
        return new \WP_Error('unknown_employee', 'Unknown employee.', [ 'status' => 404 ]);
    }

    // Mint short-lived server scan token (one-time use)
    $scan_token = substr( wp_hash( $emp . '|' . $qrTok . '|' . microtime(true) ), 0, 24 );

    self::put_scan_token( $scan_token, [
        'employee_id' => $emp,
        'device_id'   => $device ?: null,
        'qr_token'    => $qrTok,
        'ua'          => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
        'ip'          => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
    ], 180 ); // TTL: 3 minutes

    return rest_ensure_response([
        'ok'         => true,
        'scan_token' => $scan_token,
        'employee'   => [ 'id' => $emp ],
        'device_id'  => ($device ?: null),
    ]);
}

public static function status( \WP_REST_Request $req ) {
    global $wpdb;

    // Optional debug switch (?dbg=1) – only echoed in JSON, never as raw output
    $dbg = (int) ($req->get_param('dbg') ?: 0);
    $debug = [];

    try {
        $device_id = (int) ( $req->get_param('device') ?: 0 );

        // ---- Device context (policy only; no employee binding)
        $qr_enabled   = false;
        $device_mode  = 'inherit'; // inherit|never|in_only|in_out|all
        $device_meta  = null;

        if ( $device_id > 0 ) {
            $dT = $wpdb->prefix . 'sfs_hr_attendance_devices';

            $wpdb->suppress_errors( true );
            $dev = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, label, allowed_dept, qr_enabled, selfie_mode,
                        geo_lock_lat, geo_lock_lng, geo_lock_radius_m, active
                 FROM {$dT}
                 WHERE id=%d AND active=1",
                $device_id
            ) );
            $debug['db_error'] = $wpdb->last_error;
            $wpdb->suppress_errors( false );

            if ( $dev ) {
                $qr_enabled  = ((int)$dev->qr_enabled === 1);
                $device_mode = (string)$dev->selfie_mode;
                $device_meta = [
                    'id'       => (int)$dev->id,
                    'label'    => (string)($dev->label ?? ''),
                    'dept'     => (string)($dev->allowed_dept ?? 'any'),
                    'geofence' => ($dev->geo_lock_lat !== null && $dev->geo_lock_lng !== null && $dev->geo_lock_radius_m !== null)
                        ? [ 'lat' => (float)$dev->geo_lock_lat, 'lng' => (float)$dev->geo_lock_lng, 'radius_m' => (int)$dev->geo_lock_radius_m ]
                        : null,
                ];
            }
        }

        // ---- Base policy (defaults) from options
        $opt    = get_option( \SFS\HR\Modules\Attendance\AttendanceModule::OPT_SETTINGS, [] );
        $policy = is_array($opt) ? ($opt['selfie_policy'] ?? []) : [];

        // Device overrides policy when not 'inherit'
        $effective_mode = ( $device_mode !== 'inherit' )
            ? $device_mode
            : ( $policy['default'] ?? 'optional' );

        $requires_selfie_ui = in_array( $effective_mode, [ 'in_only', 'in_out', 'all' ], true );

        // ---- Base response (device/policy only)
        $resp = [
            'ok'              => true,
            'server_time'     => gmdate( 'c', current_time( 'timestamp', true ) ),
            'device'          => $device_meta,
            'qr_enabled'      => (bool) $qr_enabled,
            'selfie_mode'     => $effective_mode,
            'requires_selfie' => (bool) $requires_selfie_ui,
        ];

        // ---- If logged in & mapped, include self snapshot
        $debug['is_logged_in'] = is_user_logged_in();
        $debug['has_capability'] = current_user_can('sfs_hr_attendance_view_self');

        if ( is_user_logged_in() && current_user_can('sfs_hr_attendance_view_self') ) {
            $uid = get_current_user_id();
            $debug['user_id'] = $uid;

            $emp = \SFS\HR\Modules\Attendance\AttendanceModule::employee_id_from_user( (int) $uid );
            $debug['employee_id'] = $emp;

            if ( $emp ) {
                $snap  = self::snapshot_for_today( (int) $emp );
                $today = wp_date( 'Y-m-d' );
                $shift = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( (int) $emp, $today );
$dept  = $shift && ! empty( $shift->dept ) ? (string) $shift->dept : 'office';

$resp = array_merge( $resp, $snap, [
    'dept'     => $dept,
    'employee' => [ 'id' => (int) $emp ],
] );

// Respect shift "Selfie required" in UI as well
$shift_requires = $shift && ! empty( $shift->require_selfie );

$mode_now = \SFS\HR\Modules\Attendance\AttendanceModule::selfie_mode_for(
    (int) $emp,
    $dept,
    [
        'device_id'      => $device_id ?: null,
        'shift_requires' => $shift_requires,
    ]
);

$requires_selfie_now       = in_array( $mode_now, [ 'in_only', 'in_out', 'all' ], true );
$resp['selfie_mode']       = $mode_now;
$resp['requires_selfie']   = (bool) $requires_selfie_now;


            }
        }

        if ( $dbg ) {
            $resp['_debug'] = $debug;
        }

        return rest_ensure_response( $resp );

    } catch ( \Throwable $e ) {
        // Never emit raw output; always return JSON
        $err = [
            'message' => 'Status error',
            'detail'  => $e->getMessage(),
        ];
        if ( $dbg ) {
            $err['trace'] = $e->getTraceAsString();
        }
        return new \WP_Error( 'status_error', wp_json_encode($err), [ 'status' => 500 ] );
    }
}



public static function can_view_self_or_kiosk(): bool {
    if ( ! is_user_logged_in() ) return false;
    return current_user_can('sfs_hr_attendance_view_self')
        || current_user_can('sfs_hr_attendance_clock_kiosk')
        || current_user_can('sfs_hr_attendance_admin');
}

public static function can_punch_self_or_kiosk(): bool {
    if ( ! is_user_logged_in() ) return false;
    return current_user_can('sfs_hr_attendance_clock_self')
        || current_user_can('sfs_hr_attendance_clock_kiosk')
        || current_user_can('sfs_hr_attendance_admin');
}


// File: Rest/class-attendance-rest.php
// Method: Public_REST::punch()

public static function punch( \WP_REST_Request $req ) {
    global $wpdb;

    // ---- Inputs (validated/sanitized by route args)
    $params      = $req->get_params();
    $punch_type  = isset( $params['punch_type'] ) ? sanitize_text_field( (string) $params['punch_type'] ) : '';
    $source      = isset( $params['source'] ) ? sanitize_text_field( (string) $params['source'] ) : 'self_web';
    $device_id   = isset( $params['device'] ) ? (int) $params['device'] : 0;
    $scan_token  = isset( $params['employee_scan_token'] ) ? sanitize_text_field( (string) $params['employee_scan_token'] ) : '';
    $uid = get_current_user_id();
    



    // ---- Kiosk: CONSUME (not peek) the short-lived scan token EARLY
$scanned_emp = null;
if ( $source === 'kiosk' && $scan_token !== '' ) {
    $payload = self::pop_scan_token( $scan_token ); // ← consume immediately
    if ( ! $payload || ! is_array( $payload ) ) {
        error_log( sprintf('[SFS ATT] bad_token: missing/expired token=%s device=%d uid=%d',
            $scan_token, (int) $device_id, (int) get_current_user_id() ) );
        return new \WP_Error( 'bad_token', 'Scan token expired or invalid.', [ 'status' => 403 ] );
    }
    if ( ! empty( $payload['device_id'] ) && $device_id > 0 && (int) $payload['device_id'] !== (int) $device_id ) {
        error_log( sprintf('[SFS ATT] bad_token: device_mismatch token=%s token_device=%d req_device=%d',
            $scan_token, (int) $payload['device_id'], (int) $device_id ) );
        return new \WP_Error( 'bad_token', 'Scan token not valid for this device.', [ 'status' => 403 ] );
    }
    $scanned_emp = (int) $payload['employee_id'];
}

    // ---- Resolve acting employee (kiosk scan overrides WP-user mapping)
    $uid = get_current_user_id();
    $emp = $scanned_emp ?: \SFS\HR\Modules\Attendance\AttendanceModule::employee_id_from_user( (int) $uid );
    if ( ! $emp ) {
        return new \WP_Error( 'not_mapped', 'No employee record linked to this user.', [ 'status' => 403 ] );
    }

    // --- Guard invalid transitions based on current snapshot
    $pre   = self::snapshot_for_today( (int) $emp );
    $allow = isset( $pre['allow'] ) && is_array( $pre['allow'] ) ? $pre['allow'] : [];

    if ( empty( $allow[ $punch_type ] ) ) {
        $msg = 'Invalid action.';
        $st  = $pre['state'] ?? 'idle';
        if      ( $punch_type === 'out'         && $st === 'break' ) { $msg = 'You are on break. End the break before clocking out.'; }
        elseif  ( $punch_type === 'out'         && $st !== 'in'    ) { $msg = 'You are not clocked in.'; }
        elseif  ( $punch_type === 'in'          && $st !== 'idle'  ) { $msg = 'Already clocked in.'; }
        elseif  ( $punch_type === 'break_start' && $st !== 'in'    ) { $msg = 'You can start a break only while clocked in.'; }
        elseif  ( $punch_type === 'break_end'   && $st !== 'break' ) { $msg = 'You have no active break to end.'; }
        return new \WP_Error( 'invalid_transition', $msg, [ 'status' => 409 ] );
    }
    
    // === ADD: 5-minute cooldown guard (kiosk & self_web) ========================
$cooldownSec  = 300;  // default 5 min
$cooldownLite = 30;   // 30 sec for break toggles

$pT   = $wpdb->prefix . 'sfs_hr_attendance_punches';
$last = $wpdb->get_row( $wpdb->prepare(
    "SELECT punch_type, punch_time FROM {$pT}
     WHERE employee_id=%d ORDER BY punch_time DESC LIMIT 1", (int) $emp
) );

$now = current_time('timestamp', true);

if ( $last ) {
    $then = strtotime( $last->punch_time . ' UTC' );
    $diff = $now - $then;

    // Lite cooldown for break transitions, or when source=kiosk (operator throughput)
    $effectiveCooldown = (
        in_array( $punch_type, ['break_start','break_end'], true ) || $source === 'kiosk'
    ) ? $cooldownLite : $cooldownSec;

    if ( $diff < $effectiveCooldown ) {
        // Tell the kiosk what the current state is
        $st   = $pre['state'] ?? 'idle';
        $when = wp_date( 'H:i', $then ); // wp_date handles site TZ
        $msg  = sprintf(
            'You already are %s (last: %s at %s). Please wait %d minutes.',
            ($st === 'break' ? 'on break' : ($st === 'in' ? 'clocked in' : 'clocked out')),
            strtoupper( str_replace('_',' ', (string) $last->punch_type ) ),
            $when,
            ceil( ($effectiveCooldown - $diff) / 60 )
        );
        return new \WP_Error( 'cooldown', $msg, [ 'status' => 429 ] );
    }
}
// ===========================================================================

// Also block if last attempt (success OR failure) was too recent
$last_attempt_key = 'sfs_att_last_attempt_' . (int)$emp;
$last_attempt_ts  = (int) get_transient( $last_attempt_key );
$now_ts = time();

if ( $last_attempt_ts > 0 && ( $now_ts - $last_attempt_ts ) < 10 ) { // 10-sec global cooldown
    return new \WP_Error( 'cooldown', 'Please wait 10 seconds between attempts.', [ 'status' => 429 ] );
}

// Set attempt timestamp (expires in 15 seconds to avoid stale data)
set_transient( $last_attempt_key, $now_ts, 15 );

    // ---- Resolve effective shift for today (Assignments override Automation)
    $dateYmd = wp_date( 'Y-m-d' );
    $assign  = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( (int) $emp, $dateYmd );
    if ( ! $assign ) {
        return new \WP_Error( 'no_shift', 'No shift set (no assignment and no department automation). Ask HR to set Automation or create an assignment.', [ 'status' => 409 ] );
    }
    if ( isset( $assign->is_holiday ) && (int) $assign->is_holiday === 1 ) {
        return new \WP_Error( 'holiday', 'Today is marked as a holiday.', [ 'status' => 409 ] );
    }

    // Block if on approved leave/holiday (external module/option)
    if ( \SFS\HR\Modules\Attendance\AttendanceModule::is_blocked_by_leave_or_holiday( (int) $emp, $dateYmd ) ) {
        return new \WP_Error( 'on_leave', 'You are on approved leave/holiday today.', [ 'status' => 409 ] );
    }

    // ---- Department self-punch policy (web/mobile only; kiosk allowed regardless)
    if ( $source !== 'kiosk' ) {
        $opt         = get_option( \SFS\HR\Modules\Attendance\AttendanceModule::OPT_SETTINGS, [] );
        $web_allowed = $opt['web_allowed_by_dept'] ?? [ 'office' => true, 'showroom' => true, 'warehouse' => false, 'factory' => false ];
        $dept        = (string) ( $assign->dept ?? 'office' );
        if ( empty( $web_allowed[ $dept ] ) ) {
            return new \WP_Error( 'blocked', 'Self punching not allowed for this department. Use kiosk.', [ 'status' => 403 ] );
        }
    }

    // ---- GEO validation (server-side, BEFORE insert)
    $lat = isset( $params['geo_lat'] ) ? (float) $params['geo_lat'] : null;
    $lng = isset( $params['geo_lng'] ) ? (float) $params['geo_lng'] : null;
    $acc = isset( $params['geo_accuracy_m'] ) ? (int) $params['geo_accuracy_m'] : null;

    $valid_geo = 1;
    if ( $assign->location_lat !== null && $assign->location_lng !== null && $assign->location_radius_m !== null ) {
        if ( $lat === null || $lng === null ) {
            $valid_geo = 0;
        } else {
            $dist_m    = self::haversine_m( (float) $assign->location_lat, (float) $assign->location_lng, $lat, $lng );
            $valid_geo = ( $dist_m <= (int) $assign->location_radius_m ) ? 1 : 0;
        }
    }
    // Device-level geofence (if device is provided and active)
if ( $device_id > 0 ) {
    $dT = $wpdb->prefix . 'sfs_hr_attendance_devices';
    $dev = $wpdb->get_row( $wpdb->prepare(
        "SELECT geo_lock_lat, geo_lock_lng, geo_lock_radius_m FROM {$dT} WHERE id=%d AND active=1",
        $device_id
    ) );
    if ( $dev && $dev->geo_lock_lat !== null && $dev->geo_lock_lng !== null && $dev->geo_lock_radius_m !== null ) {
        if ( $lat === null || $lng === null ) {
            $valid_geo = 0;
        } else {
            $dist_m = self::haversine_m( (float)$dev->geo_lock_lat, (float)$dev->geo_lock_lng, $lat, $lng );
            if ( $dist_m > (int)$dev->geo_lock_radius_m ) {
                $valid_geo = 0;
            }
        }
    }
}

$dept = (string) ( $assign->dept ?? 'office' );

// مصدر واحد للحقيقة
$mode = \SFS\HR\Modules\Attendance\AttendanceModule::selfie_mode_for(
    (int) $emp,
    $dept,
    [
        'device_id'      => $device_id ?: null,
        'shift_requires' => ((int) $assign->require_selfie === 1),
        'punch_type'     => $punch_type,
    ]
);

$require_selfie = in_array($mode, ['in_only','in_out','all'], true)
    && in_array($punch_type, ['in','out','break_start','break_end'], true);

// ---- Consolidated selfie handling (accept upload OR existing attachment id)
$valid_selfie    = $require_selfie ? 0 : 1;
$selfie_media_id = 0;

// Accept pre-uploaded attachment id (e.g., native kiosk app)
$incoming_id = isset( $params['selfie_media_id'] ) ? (int) $params['selfie_media_id'] : 0;

// Option 1) New file uploaded in this request
if ( ! empty( $_FILES['selfie']['tmp_name'] ) ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Light validation: image only (don’t rely on EXIF for “freshness”, many cameras strip it)
    $ft = wp_check_filetype_and_ext( $_FILES['selfie']['tmp_name'], $_FILES['selfie']['name'] ?? '' );
    if ( $require_selfie && ( empty( $ft['ext'] ) || strpos( (string) $ft['type'], 'image/' ) !== 0 ) ) {
        return new \WP_Error( 'sfs_att_selfie_not_image', 'Selfie must be an image.', [ 'status' => 400 ] );
    }

    $up = wp_handle_upload( $_FILES['selfie'], [
        'test_form' => false,
        'mimes'     => [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
        ],
    ] );
    if ( isset( $up['error'] ) ) {
        return new \WP_Error( 'upload_error', 'Failed to save selfie: ' . $up['error'], [ 'status' => 400 ] );
    }

    $attachment = [
        'post_mime_type' => $up['type'],
        'post_title'     => sprintf( 'Attendance Selfie — Emp #%d — %s', (int) $emp, wp_date( 'Y-m-d H:i:s' ) ),
        'post_content'   => '',
        'post_status'    => 'private',
    ];
    $attach_id = wp_insert_attachment( $attachment, $up['file'] );
    if ( ! is_wp_error( $attach_id ) && $attach_id ) {
        $meta = wp_generate_attachment_metadata( $attach_id, $up['file'] );
        if ( $meta ) { wp_update_attachment_metadata( $attach_id, $meta ); }
        $selfie_media_id = (int) $attach_id;
        $valid_selfie    = 1;

        update_post_meta( $attach_id, '_sfs_att_employee_id', (int) $emp );
        update_post_meta( $attach_id, '_sfs_att_source', $source );
    }

// Option 2) Reuse existing attachment id
} elseif ( $incoming_id > 0 ) {
    $mime = get_post_mime_type( $incoming_id );
    if ( $mime && strpos( $mime, 'image/' ) === 0 ) {
        $selfie_media_id = (int) $incoming_id;
        $valid_selfie    = 1;
    }
}

// Option 3) Base64 data URL (e.g., mobile/webcam snapshot sent as JSON)
elseif ( ! empty( $params['selfie_data_url'] ) && is_string( $params['selfie_data_url'] ) ) {
    $att_id = self::save_selfie_attachment([
        'data_url' => (string) $params['selfie_data_url'],
        'filename' => 'selfie-'.time().'.jpg',
    ]);
    if ( $att_id > 0 ) {
        $selfie_media_id = (int) $att_id;
        $valid_selfie    = 1;
    }
}

// If a selfie is required but we still have none, block the punch
if ( $require_selfie && ( ! $selfie_media_id || ! $valid_selfie ) ) {
    return new \WP_Error( 'sfs_att_selfie_required', 'Selfie is required for this punch.', [ 'status' => 400 ] );
}



    

    // ---- Insert immutable punch
    $nowUtc = current_time( 'mysql', true );
    $punchT = $wpdb->prefix . 'sfs_hr_attendance_punches';

    $wpdb->insert( $punchT, [
        'employee_id'     => (int) $emp,
        'punch_type'      => $punch_type,
        'punch_time'      => $nowUtc,
        'source'          => $source,
        'device_id'       => ( $device_id ?: null ),
        'ip_addr'         => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : null,
        'geo_lat'         => $lat,
        'geo_lng'         => $lng,
        'geo_accuracy_m'  => $acc,
        'valid_geo'       => (int) $valid_geo,
        'note'            => null,
        'selfie_media_id' => ($selfie_media_id ?: null),
        'valid_selfie'    => (int) $valid_selfie,
        'created_at'      => $nowUtc,
        'created_by'      => $uid,
    ] );

$punch_id = (int) $wpdb->insert_id;

if ( $selfie_media_id ) {
    update_post_meta( $selfie_media_id, '_sfs_att_punch_id', $punch_id );
    update_post_meta( $selfie_media_id, '_sfs_att_employee_id', (int) $emp );
    update_post_meta( $selfie_media_id, '_sfs_att_source', $source );
}


$resp_extra = [];
if ( $selfie_media_id ) {
    $resp_extra['selfie_media_id'] = $selfie_media_id;
    $resp_extra['selfie_url']      = wp_get_attachment_url( $selfie_media_id );
}


    // ---- Audit
    $auditT = $wpdb->prefix . 'sfs_hr_attendance_audit';
    $wpdb->insert( $auditT, [
        'actor_user_id'      => $uid,
        'action_type'        => 'punch.create',
        'target_employee_id' => (int) $emp,
        'target_punch_id'    => (int) $wpdb->insert_id,
        'target_session_id'  => null,
        'before_json'        => null,
        'after_json'         => wp_json_encode( [
            'punch_type'   => $punch_type,
            'source'       => $source,
            'valid_geo'    => $valid_geo,
            'valid_selfie' => $valid_selfie,
        ] ),
        'created_at'         => $nowUtc,
    ] );

    // ---- Recalculate today’s session, return snapshot + selfie requirement hint
        \SFS\HR\Modules\Attendance\AttendanceModule::recalc_session_for( (int) $emp, $dateYmd );

    // Snapshot جديد بعد التسجيل
    $snap  = self::snapshot_for_today( (int) $emp );
    $today = wp_date( 'Y-m-d' );
    $shift = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( (int) $emp, $today );
    $dept  = $shift && ! empty( $shift->dept ) ? (string) $shift->dept : 'office';

    // نحسب المود / إلزامية السيلفي "للنقطة التالية" بنفس منطق /status
    $mode_next = \SFS\HR\Modules\Attendance\AttendanceModule::selfie_mode_for(
        (int) $emp,
        $dept,
        [
            'device_id' => $device_id ?: null,
        ]
    );
    $requires_selfie_next = in_array( $mode_next, [ 'in_only', 'in_out', 'all' ], true );

    $resp = array_merge( $snap, [
        'selfie_mode'     => $mode_next,
        'requires_selfie' => (bool) $requires_selfie_next,
        'dept'            => $dept,
    ] );

    if ( $selfie_media_id ) {
        $resp['selfie_media_id'] = $selfie_media_id;
        $resp['selfie_url']      = wp_get_attachment_url( $selfie_media_id );
    }

    // لوج واضح: ماذا كان مطلوب لهذا البنش، وماذا سيكون المطلوب للبنش التالي
    error_log('[SFS-HR/Attendance] punch selfie | ' . wp_json_encode([
        'required_for_this_punch' => (bool) $require_selfie,       // منطق التحقق اللي فوق
        'selfie_mode_next'        => $mode_next,
        'requires_selfie_next'    => (bool) $requires_selfie_next, // اللي رجعناه للواجهة
        'saved_attachment'        => (int) $selfie_media_id,
        'valid_selfie'            => (int) $valid_selfie,
        'source'                  => $source,
        'device_id'               => (int) $device_id,
        'emp'                     => (int) $emp,
    ]));

    return rest_ensure_response( $resp );


}


    /**
 * Save a selfie (from FILES or data URL) as a WP attachment and return attachment ID or 0.
 * Accepts one of: ['file'=>$_FILES['selfie']] OR ['attachment_id'=>123] OR ['data_url'=>'data:image/...','filename'=>'selfie.jpg']
 */
private static function save_selfie_attachment( array $src ): int {
    if ( ! function_exists('wp_handle_upload') ) {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
    }

    // Already uploaded?
    if ( ! empty($src['attachment_id']) && (int)$src['attachment_id'] > 0 ) {
        return (int) $src['attachment_id'];
    }

    // Multipart file
    if ( ! empty($src['file']) && is_array($src['file']) && empty($src['file']['error']) ) {
        $overrides = [ 'test_form' => false, 'mimes' => [
            'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'
        ]];
        $up = wp_handle_upload( $src['file'], $overrides );
        if ( ! empty($up['file']) && ! empty($up['type']) ) {
            $att_id = wp_insert_attachment([
                'post_mime_type' => $up['type'],
                'post_title'     => sanitize_file_name( wp_basename( $up['file'] ) ),
                'post_content'   => '',
                'post_status'    => 'private',
            ], $up['file'] );
            if ( $att_id ) {
                $meta = wp_generate_attachment_metadata( $att_id, $up['file'] );
                if ( $meta ) wp_update_attachment_metadata( $att_id, $meta );
                return (int) $att_id;
            }
        }
    }

    // Data URL
    if ( ! empty($src['data_url']) && is_string($src['data_url']) && str_starts_with($src['data_url'],'data:image') ) {
        if ( preg_match('#^data:(image/\w+);base64,(.+)$#', $src['data_url'], $m) ) {
            $mime = $m[1]; $bin = base64_decode($m[2]);
            if ( $bin !== false ) {
                $uploads = wp_upload_dir();
                $ext     = explode('/', $mime)[1] ?? 'jpg';
                $name    = sanitize_file_name( $src['filename'] ?? ('selfie-'.time().'.'.$ext) );
                $path    = trailingslashit($uploads['path']).$name;
                if ( file_put_contents($path, $bin) !== false ) {
                    $att_id = wp_insert_attachment([
                        'post_mime_type' => $mime,
                        'post_title'     => pathinfo($name, PATHINFO_FILENAME),
                        'post_content'   => '',
                        'post_status'    => 'private',
                    ], $path );
                    if ( $att_id ) {
                        $meta = wp_generate_attachment_metadata( $att_id, $path );
                        if ( $meta ) wp_update_attachment_metadata( $att_id, $meta );
                        return (int) $att_id;
                    }
                }
            }
        }
    }
    return 0;
}


    /* ---------------- Helpers ---------------- */

    private static function snapshot_for_today( int $employee_id ): array {
    global $wpdb;

    $today = wp_date('Y-m-d');
    $pT    = $wpdb->prefix . 'sfs_hr_attendance_punches';

    // Compute local day bounds, compare in UTC (punch_time is stored UTC)
    $tz           = wp_timezone();
    $start_local  = new \DateTimeImmutable($today.' 00:00:00', $tz);
    $end_local    = $start_local->modify('+1 day');
    $start_utc    = $start_local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $end_utc      = $end_local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    // Pull today's punches in order
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT punch_type, punch_time
         FROM {$pT}
         WHERE employee_id=%d
           AND punch_time >= %s AND punch_time < %s
         ORDER BY punch_time ASC",
        $employee_id, $start_utc, $end_utc
    ) );

    $state = 'idle'; // 'idle' | 'in' | 'break'
    $label = 'Ready';

    if (!empty($rows)) {
        $last     = end($rows);
        $lastType = (string)$last->punch_type;
        $when     = wp_date('H:i', strtotime($last->punch_time)); // shown in site TZ
        $label    = 'Last: ' . strtoupper(str_replace('_',' ', $lastType)) . " at {$when}";

        if ($lastType === 'in' || $lastType === 'break_end') {
            $state = 'in';
        } elseif ($lastType === 'break_start') {
            $state = 'break';
        } elseif ($lastType === 'out') {
            $state = 'idle';
        }
    }

    // Allowed transitions based on current state:
    $allow = [
        'in'          => ($state === 'idle'),
        'out'         => ($state === 'in'),        // keep hidden during break; change to ($state==='in'||$state==='break') if you prefer to show it
        'break_start' => ($state === 'in'),
        'break_end'   => ($state === 'break'),
    ];

    return [
        'label' => $label,
        'state' => $state,
        'allow' => $allow,
    ];
}





    private static function haversine_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
        $R = 6371000.0; // meters
        $dLat = deg2rad($lat2-$lat1);
        $dLng = deg2rad($lng2-$lng1);
        $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)*sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c;
    }
}
