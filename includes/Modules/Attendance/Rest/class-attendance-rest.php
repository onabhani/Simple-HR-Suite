<?php
namespace SFS\HR\Modules\Attendance\Rest;

use SFS\HR\Modules\Attendance\AttendanceModule;
use SFS\HR\Modules\Attendance\Services\Policy_Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Public_REST
 * Version: 0.1.2-punch
 * Author: hdqah.com
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
            // Offline kiosk punch fields
            'offline_origin' => [ 'type'=>'boolean', 'required'=>false, 'default'=>false ],
            'offline_employee_id' => [
                'required'=>false,
                'validate_callback'=>fn($v)=>$v===null||$v===''||is_numeric($v),
                'sanitize_callback'=>fn($v)=>($v===''||$v===null)?null:(int)$v,
            ],
            'client_punch_time' => [ 'type'=>'string', 'required'=>false ],
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

    // GET /sfs-hr/v1/attendance/kiosk-roster  (employee list for offline kiosk validation)
    register_rest_route('sfs-hr/v1', '/attendance/kiosk-roster', [
        'methods'  => 'GET',
        'callback' => [ __CLASS__, 'kiosk_roster' ],
        'permission_callback' => function () {
            return is_user_logged_in() && (
                current_user_can('sfs_hr_attendance_clock_kiosk') ||
                current_user_can('sfs_hr_attendance_admin')
            );
        },
        'args' => [
            'device' => [ 'type'=>'integer', 'required'=>false ],
        ],
    ]);

    // POST /sfs-hr/v1/attendance/verify-pin  (verify manager PIN for kiosk)
    register_rest_route('sfs-hr/v1', '/attendance/verify-pin', [
        'methods'  => 'POST',
        'callback' => [ __CLASS__, 'verify_pin' ],
        'permission_callback' => '__return_true', // Public endpoint, PIN itself provides auth
        'args' => [
            'device_id' => [ 'type'=>'integer', 'required'=>true ],
            'pin'       => [ 'type'=>'string',  'required'=>true ],
        ],
    ]);
}




/**
 * Store a one-time scan token using transients (auto-expires via TTL).
 * Transients are the WordPress-recommended mechanism for temporary data and
 * are automatically cleaned up, preventing stale token buildup in wp_options.
 */
private static function put_scan_token(string $token, array $payload, int $ttl = 300): void {
    $key = 'sfs_hr_scan_' . $token;
    // Avoid clobbering an existing token
    if ( get_transient( $key ) !== false ) return;
    set_transient( $key, $payload, $ttl );
}

/** Peek (do not consume) a scan token; returns payload or null if missing/expired. */
private static function get_scan_token( string $token ): ?array {
    $key = 'sfs_hr_scan_' . $token;
    $row = get_transient( $key );
    if ( $row === false || ! is_array( $row ) ) return null;
    return $row;
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

    // Get employee data (code and name)
    $emp_data = $wpdb->get_row( $wpdb->prepare(
        "SELECT e.id, e.employee_code, e.first_name, e.last_name, u.display_name
         FROM {$wpdb->prefix}sfs_hr_employees e
         LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
         WHERE e.id=%d LIMIT 1", $emp
    ), ARRAY_A );

    if ( ! $emp_data ) {
        return new \WP_Error('unknown_employee', 'Unknown employee.', [ 'status' => 404 ]);
    }

    // Build employee display name
    $emp_name = '';
    if ( !empty($emp_data['first_name']) || !empty($emp_data['last_name']) ) {
        $emp_name = trim( ($emp_data['first_name'] ?? '') . ' ' . ($emp_data['last_name'] ?? '') );
    } elseif ( !empty($emp_data['display_name']) ) {
        $emp_name = $emp_data['display_name'];
    }

    // Fallback to employee code or ID
    if ( empty($emp_name) ) {
        $emp_name = !empty($emp_data['employee_code'])
            ? "Employee #{$emp_data['employee_code']}"
            : "Employee #{$emp}";
    }

    // Mint short-lived server scan token (one-time use)
    $scan_token = substr( wp_hash( $emp . '|' . $qrTok . '|' . microtime(true) ), 0, 24 );

    self::put_scan_token( $scan_token, [
        'employee_id' => $emp,
        'device_id'   => $device ?: null,
        'qr_token'    => $qrTok,
        'ua'          => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ) : '',
        'ip'          => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '',
    ], 600 ); // TTL: 10 minutes (allows full punch sequence: In → Break Start → Break End → Out)

    return rest_ensure_response([
        'ok'            => true,
        'scan_token'    => $scan_token,
        'employee'      => [
            'id' => $emp,
            'code' => $emp_data['employee_code'] ?? '',
        ],
        'employee_name' => $emp_name,
        'device_id'     => ($device ?: null),
    ]);
}


/**
 * GET /sfs-hr/v1/attendance/kiosk-roster
 *
 * Returns active, QR-enabled employees with SHA-256 hashed tokens
 * for offline kiosk validation. Only includes employees visible to
 * the device's allowed department (if set).
 */
public static function kiosk_roster( \WP_REST_Request $req ) {
    global $wpdb;

    $device_id = (int) ( $req->get_param('device') ?: 0 );
    $empT = $wpdb->prefix . 'sfs_hr_employees';
    $devT = $wpdb->prefix . 'sfs_hr_attendance_devices';

    // Verify device exists, is active, and has offline enabled
    $allowed_dept_id = null;
    if ( $device_id > 0 ) {
        $dev = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, kiosk_offline, allowed_dept_id FROM {$devT} WHERE id = %d AND active = 1",
            $device_id
        ) );
        if ( ! $dev ) {
            return new \WP_Error( 'device_not_found', 'Device not found or inactive.', [ 'status' => 404 ] );
        }
        if ( ! (int) $dev->kiosk_offline ) {
            return new \WP_Error( 'offline_disabled', 'Offline mode is not enabled for this device.', [ 'status' => 403 ] );
        }
        if ( $dev->allowed_dept_id ) {
            $allowed_dept_id = (int) $dev->allowed_dept_id;
        }
    }

    // Build query: active employees with QR enabled and a valid token
    $where = "e.status = %s AND e.qr_enabled = 1 AND e.qr_token IS NOT NULL AND e.qr_token != ''";
    $params = [ 'active' ];

    if ( $allowed_dept_id ) {
        $where .= ' AND e.dept_id = %d';
        $params[] = $allowed_dept_id;
    }

    $sql = "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.qr_token, u.display_name
            FROM {$empT} e
            LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
            WHERE {$where}
            ORDER BY e.id ASC";

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

    $employees = [];
    foreach ( (array) $rows as $r ) {
        $name = trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
        if ( empty( $name ) ) {
            $name = $r['display_name'] ?? '';
        }
        if ( empty( $name ) ) {
            $name = 'Employee #' . ( $r['employee_code'] ?: $r['id'] );
        }

        $employees[] = [
            'id'         => (int) $r['id'],
            'code'       => (string) ( $r['employee_code'] ?? '' ),
            'name'       => $name,
            // SHA-256 hash — client computes same hash on scanned token to verify
            'token_hash' => hash( 'sha256', (string) $r['qr_token'] ),
        ];
    }

    $ttl = 1800; // 30 minutes

    return rest_ensure_response( [
        'ok'           => true,
        'employees'    => $employees,
        'generated_at' => time(),
        'ttl'          => $ttl,
    ] );
}


public static function status( \WP_REST_Request $req ) {
    global $wpdb;

    // Debug switch — only available to privileged users
    $dbg = (int) ($req->get_param('dbg') ?: 0);
    if ( $dbg && ! current_user_can( 'sfs_hr_attendance_admin' ) ) {
        $dbg = 0;
    }
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
                "SELECT id, label, allowed_dept_id, qr_enabled, selfie_mode,
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
                // Only expose full device config to authenticated users
                if ( is_user_logged_in() ) {
                    $device_meta = [
                        'id'       => (int)$dev->id,
                        'label'    => (string)($dev->label ?? ''),
                        'dept'     => (string)($dev->allowed_dept_id ?? 'any'),
                        'geofence' => ($dev->geo_lock_lat !== null && $dev->geo_lock_lng !== null && $dev->geo_lock_radius_m !== null)
                            ? [ 'lat' => (float)$dev->geo_lock_lat, 'lng' => (float)$dev->geo_lock_lng, 'radius_m' => (int)$dev->geo_lock_radius_m ]
                            : null,
                    ];
                } else {
                    // Unauthenticated: only expose minimal info needed for kiosk UI init
                    $device_meta = [
                        'id' => (int)$dev->id,
                    ];
                }
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
        if ( is_user_logged_in() && current_user_can('sfs_hr_attendance_view_self') ) {
            $uid = get_current_user_id();
            $emp = \SFS\HR\Modules\Attendance\AttendanceModule::employee_id_from_user( (int) $uid );

            if ( $emp ) {
                $snap  = self::snapshot_for_today( (int) $emp );
                $today = wp_date( 'Y-m-d' );
                $shift = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( (int) $emp, $today );
$dept_id = $shift && ! empty( $shift->dept_id ) ? (int) $shift->dept_id : 0;

$resp = array_merge( $resp, $snap, [
    'dept_id'  => $dept_id,
    'employee' => [ 'id' => (int) $emp ],
] );

// Respect shift "Selfie required" in UI as well
$shift_requires = $shift && ! empty( $shift->require_selfie );

$mode_now = \SFS\HR\Modules\Attendance\AttendanceModule::selfie_mode_for(
    (int) $emp,
    $dept_id,
    [
        'device_id'      => $device_id ?: null,
        'shift_requires' => $shift_requires,
    ]
);

$requires_selfie_now       = in_array( $mode_now, [ 'in_only', 'in_out', 'all' ], true );
$resp['selfie_mode']       = $mode_now;
$resp['requires_selfie']   = (bool) $requires_selfie_now;

// ---- Method validation per punch type (so client can block BEFORE camera/geo)
// Check which methods the policy allows, and flag blocked punch types with reason
$method_blocked = [];
foreach ( [ 'in', 'out', 'break_start', 'break_end' ] as $pt ) {
    $check = Policy_Service::validate_method( (int) $emp, $pt, 'self_web', $shift );
    if ( is_wp_error( $check ) ) {
        $method_blocked[ $pt ] = $check->get_error_message();
    }
}
if ( ! empty( $method_blocked ) ) {
    $resp['method_blocked'] = $method_blocked;
}

// ---- Cooldown info (so client can warn before opening camera)
$punchT_cd = $wpdb->prefix . 'sfs_hr_attendance_punches';
$last_punch = $wpdb->get_row( $wpdb->prepare(
    "SELECT punch_type, punch_time FROM {$punchT_cd}
     WHERE employee_id = %d ORDER BY punch_time DESC LIMIT 1",
    (int) $emp
) );
if ( $last_punch ) {
    $cd_then    = strtotime( $last_punch->punch_time . ' UTC' );
    $cd_elapsed = time() - $cd_then;
    $cd_same    = 30;  // same-type cooldown
    $cd_cross   = 15;  // cross-type cooldown
    $resp['cooldown_type'] = (string) $last_punch->punch_type;
    if ( $cd_elapsed < $cd_same ) {
        $resp['cooldown_seconds']       = $cd_same - $cd_elapsed;
    }
    if ( $cd_elapsed < $cd_cross ) {
        $resp['cooldown_cross_seconds'] = $cd_cross - $cd_elapsed;
    }
}


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
        ];
        if ( $dbg && current_user_can( 'sfs_hr_attendance_admin' ) ) {
            $err['detail'] = $e->getMessage();
        }
        // Log server-side only; never expose file paths or traces in API responses.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[SFS ATT] Status error in snapshot' );
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

    // ---- IP-based rate limiting (prevents automated flooding / DoS) ----
    // Allow max 30 punch requests per minute per IP address.
    $client_ip    = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    $rate_key     = 'sfs_hr_punch_rate_' . md5( $client_ip );
    $rate_count   = (int) get_transient( $rate_key );
    $rate_limit   = 30; // requests per 60-second window
    if ( $rate_count >= $rate_limit ) {
        return new \WP_Error(
            'rate_limited',
            __( 'Too many requests. Please try again in a minute.', 'sfs-hr' ),
            [ 'status' => 429 ]
        );
    }
    set_transient( $rate_key, $rate_count + 1, 60 );

    // ---- Inputs (validated/sanitized by route args)
    $params      = $req->get_params();
    $punch_type  = isset( $params['punch_type'] ) ? sanitize_text_field( (string) $params['punch_type'] ) : '';
    $source      = isset( $params['source'] ) ? sanitize_text_field( (string) $params['source'] ) : 'self_web';
    $device_id   = isset( $params['device'] ) ? (int) $params['device'] : 0;
    $scan_token  = isset( $params['employee_scan_token'] ) ? sanitize_text_field( (string) $params['employee_scan_token'] ) : '';
    $uid = get_current_user_id();

    // ---- Offline-origin kiosk punch handling
    $is_offline_origin    = ! empty( $params['offline_origin'] );
    $offline_employee_id  = isset( $params['offline_employee_id'] ) ? (int) $params['offline_employee_id'] : 0;
    $client_punch_time    = isset( $params['client_punch_time'] ) ? sanitize_text_field( (string) $params['client_punch_time'] ) : '';

    if ( $is_offline_origin ) {
        // Validate: must be kiosk source with a device that has offline enabled
        if ( $source !== 'kiosk' || $device_id <= 0 ) {
            return new \WP_Error( 'offline_invalid', 'Offline punches require kiosk source and a device ID.', [ 'status' => 400 ] );
        }
        $devT = $wpdb->prefix . 'sfs_hr_attendance_devices';
        $dev_check = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, kiosk_offline FROM {$devT} WHERE id = %d AND active = 1",
            $device_id
        ) );
        if ( ! $dev_check || ! (int) $dev_check->kiosk_offline ) {
            return new \WP_Error( 'offline_not_allowed', 'Offline mode is not enabled for this device.', [ 'status' => 403 ] );
        }
        if ( $offline_employee_id <= 0 ) {
            return new \WP_Error( 'offline_no_employee', 'Offline punch requires an employee ID.', [ 'status' => 400 ] );
        }
        // Verify employee exists and is active
        $empT = $wpdb->prefix . 'sfs_hr_employees';
        $emp_row_offline = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, dept_id FROM {$empT} WHERE id = %d AND status = 'active'",
            $offline_employee_id
        ) );
        if ( ! $emp_row_offline ) {
            return new \WP_Error( 'offline_unknown_employee', 'Employee not found or inactive.', [ 'status' => 404 ] );
        }
        // Enforce department restriction: device's allowed_dept_id must match employee's dept
        $dev_dept = $wpdb->get_var( $wpdb->prepare(
            "SELECT allowed_dept_id FROM {$devT} WHERE id = %d",
            $device_id
        ) );
        if ( $dev_dept && (int) $dev_dept > 0 && (int) $emp_row_offline->dept_id !== (int) $dev_dept ) {
            return new \WP_Error( 'offline_dept_mismatch', 'Employee does not belong to the department assigned to this device.', [ 'status' => 403 ] );
        }
        // Validate client_punch_time: must be a parseable datetime, not more than 24h old
        if ( $client_punch_time !== '' ) {
            $client_ts = strtotime( $client_punch_time . ' UTC' );
            if ( ! $client_ts ) {
                return new \WP_Error( 'offline_bad_time', 'Invalid client_punch_time format.', [ 'status' => 400 ] );
            }
            $age = time() - $client_ts;
            if ( $age > 86400 ) { // older than 24 hours
                return new \WP_Error( 'offline_stale', 'Offline punch is older than 24 hours.', [ 'status' => 409 ] );
            }
            if ( $age < -300 ) { // more than 5 min in the future
                return new \WP_Error( 'offline_future', 'Offline punch time is in the future.', [ 'status' => 409 ] );
            }

            // Validate punch falls within the employee's shift window ± 2h buffer (C8/S4 fix).
            // Prevents time fraud on offline kiosks where punches could be fabricated
            // for any time within the 24h staleness window.
            $offline_emp_id = $offline_employee_id;
            $offline_date   = wp_date( 'Y-m-d', $client_ts );
            $offline_shift  = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( $offline_emp_id, $offline_date );
            if ( $offline_shift ) {
                $offline_segs = \SFS\HR\Modules\Attendance\AttendanceModule::build_segments_from_shift( $offline_shift, $offline_date );
                if ( ! empty( $offline_segs ) ) {
                    $first_seg    = reset( $offline_segs );
                    $last_seg     = end( $offline_segs );
                    $seg_start_ts = strtotime( $first_seg['start_utc'] . ' UTC' );
                    $seg_end_ts   = strtotime( $last_seg['end_utc'] . ' UTC' );

                    // Use overtime buffer to extend allowed window past shift end
                    $ot_buf_raw = $offline_shift->overtime_buffer_minutes ?? null;
                    $ot_buf     = ( $ot_buf_raw !== null )
                        ? (int) $ot_buf_raw
                        : min( (int) round( $last_seg['minutes'] * 0.5 ), 240 );

                    $window_start = $seg_start_ts - 7200; // 2h before shift
                    $window_end   = $seg_end_ts + $ot_buf * 60;

                    if ( $client_ts < $window_start || $client_ts > $window_end ) {
                        return new \WP_Error(
                            'offline_outside_shift',
                            'Offline punch time falls outside your shift window.',
                            [ 'status' => 409 ]
                        );
                    }
                }
            }
        }
    }

    // ---- Kiosk: PEEK (not consume) the short-lived scan token to allow multiple punch types
    // Token will expire naturally after TTL (10 minutes), allowing Break/Out after In
$scanned_emp = null;
if ( $source === 'kiosk' && $scan_token !== '' && ! $is_offline_origin ) {
    $payload = self::get_scan_token( $scan_token ); // ← peek, don't consume (allows multiple punches)
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
} elseif ( $is_offline_origin && $offline_employee_id > 0 ) {
    // Offline punch: employee ID comes directly from the cached roster
    $scanned_emp = $offline_employee_id;
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

    // When a stale open session exists (buffer expired, snapshot shows idle),
    // only permit closing the session — no new clock-in or break actions.
    $has_stale_session = ! empty( $pre['stale_session_msg'] );
    if ( $has_stale_session ) {
        $allow['out']         = true;
        $allow['in']          = false;
        $allow['break_start'] = false;
        $allow['break_end']   = false;
    }

    if ( empty( $allow[ $punch_type ] ) ) {
        $msg = 'Invalid action.';
        $st  = $pre['state'] ?? 'idle';
        if      ( $punch_type === 'out'         && $st === 'break' ) { $msg = 'You are on break. End the break before clocking out.'; }
        elseif  ( $punch_type === 'out'         && $st !== 'in'    ) { $msg = 'You are not clocked in.'; }
        elseif  ( $punch_type === 'in'          && $st !== 'idle'  ) { $msg = 'Already clocked in.'; }
        elseif  ( $punch_type === 'break_start' && $st !== 'in'    ) { $msg = 'You can start a break only while clocked in.'; }
        elseif  ( $punch_type === 'break_end'   && $st !== 'break' ) { $msg = 'You have no active break to end.'; }
        // Log for debugging
        error_log( sprintf( '[SFS ATT] Invalid transition: emp=%d, requested=%s, state=%s, label=%s',
            $emp, $punch_type, $st, $pre['label'] ?? 'unknown' ) );
        return new \WP_Error( 'invalid_transition', $msg, [ 'status' => 409 ] );
    }
    
    // === Punch cooldown ==========================================================
    $pT   = $wpdb->prefix . 'sfs_hr_attendance_punches';
    $last = $wpdb->get_row( $wpdb->prepare(
        "SELECT punch_type, punch_time FROM {$pT}
         WHERE employee_id=%d ORDER BY punch_time DESC LIMIT 1", (int) $emp
    ) );

    if ( $last ) {
        $now_utc   = current_time( 'timestamp', true );
        $then      = strtotime( $last->punch_time . ' UTC' );
        $diff      = $now_utc - $then;
        $last_type = (string) $last->punch_type;

        // 1) Same action within 30 seconds → duplicate tap
        if ( $last_type === $punch_type && $diff < 30 ) {
            $when = wp_date( 'H:i', $then );
            return new \WP_Error(
                'cooldown',
                sprintf(
                    'You already recorded %s at %s. Please wait %d seconds before trying again.',
                    strtoupper( str_replace( '_', ' ', $punch_type ) ),
                    $when,
                    30 - $diff
                ),
                [ 'status' => 429 ]
            );
        }

        // 2) Any different action within 15 seconds → too fast (prevents break cycling)
        if ( $last_type !== $punch_type && $diff < 15 ) {
            return new \WP_Error(
                'cooldown',
                sprintf(
                    'Please wait %d seconds before your next action.',
                    15 - $diff
                ),
                [ 'status' => 429 ]
            );
        }
    }
    // =========================================================================

    // ---- Resolve effective shift for today (Assignments override Automation)
    // For overnight shifts: if the snapshot detected an open session from
    // yesterday, use yesterday's date so clock-out/break punches are attributed
    // to the correct work date and shift (not today's off-day/null shift).
    $dateYmd          = wp_date( 'Y-m-d' );
    $overnight_active = ! empty( $pre['overnight_ymd'] ) && in_array( $punch_type, [ 'out', 'break_start', 'break_end' ], true );
    if ( $overnight_active ) {
        $dateYmd = $pre['overnight_ymd'];
    }
    $assign  = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( (int) $emp, $dateYmd );

    // --- Allow clock-out / break actions on off-days when an open session exists ---
    // When today is an off-day (no shift) and the snapshot shows the employee is
    // still clocked in (state=in/break), look for an open session from the
    // previous day and use that day's shift for validation.  This covers two cases:
    //   1. Overnight session detected by snapshot but shift resolves to null for
    //      today (off-day) — the snapshot's overnight_ymd should have handled this
    //      but may not if the employee navigated away and the snapshot is stale.
    //   2. Buffer-expired overnight session where the employee still needs to close
    //      out — we allow clock-out regardless of buffer to prevent stuck sessions.
    if ( ! $assign && in_array( $punch_type, [ 'out', 'break_start', 'break_end' ], true ) ) {
        $pT_check = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $last_open = $wpdb->get_row( $wpdb->prepare(
            "SELECT punch_type, punch_time FROM {$pT_check}
             WHERE employee_id = %d ORDER BY punch_time DESC LIMIT 1",
            (int) $emp
        ) );
        if ( $last_open && in_array( (string) $last_open->punch_type, [ 'in', 'break_end', 'break_start' ], true ) ) {
            $open_date = wp_date( 'Y-m-d', strtotime( $last_open->punch_time . ' UTC' ) );
            if ( $open_date < $dateYmd ) {
                $prev_assign = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( (int) $emp, $open_date );
                if ( $prev_assign ) {
                    $assign  = $prev_assign;
                    $dateYmd = $open_date;
                }
            }
        }
    }

    if ( ! $assign ) {
        return new \WP_Error( 'no_shift', 'No shift set (no assignment and no department automation). Ask HR to set Automation or create an assignment.', [ 'status' => 409 ] );
    }
    if ( isset( $assign->is_holiday ) && (int) $assign->is_holiday === 1 ) {
        return new \WP_Error( 'holiday', 'Today is marked as a holiday.', [ 'status' => 409 ] );
    }

    // ---- Attendance policy: validate punch method (shift-level → role-based fallback)
    $policy_method_check = Policy_Service::validate_method( (int) $emp, $punch_type, $source, $assign );
    if ( is_wp_error( $policy_method_check ) ) {
        return $policy_method_check;
    }

    // Block if on approved leave (holidays allow punches — recalc handles overtime)
    if ( \SFS\HR\Modules\Attendance\AttendanceModule::is_on_approved_leave( (int) $emp, $dateYmd ) ) {
        return new \WP_Error( 'on_leave', 'You are on approved leave today.', [ 'status' => 409 ] );
    }

    // ---- Department self-punch policy (web/mobile only; kiosk allowed regardless)
    // Policy may override department block (e.g. field teams allowed self-web)
    if ( $source !== 'kiosk' && ! Policy_Service::should_bypass_dept_web_block( (int) $emp, $punch_type, $source, $assign ) ) {
        $opt = get_option( \SFS\HR\Modules\Attendance\AttendanceModule::OPT_SETTINGS, [] );

        // Get employee's department ID
        $empT    = $wpdb->prefix . 'sfs_hr_employees';
        $emp_row = $wpdb->get_row( $wpdb->prepare( "SELECT dept_id FROM {$empT} WHERE id = %d", $emp ) );
        $dept_id = $emp_row && ! empty( $emp_row->dept_id ) ? (int) $emp_row->dept_id : 0;

        // Check by department ID (new system)
        // If no settings configured yet, allow all by default
        $web_allowed_by_id = $opt['web_allowed_by_dept_id'] ?? [];
        if ( ! empty( $web_allowed_by_id ) && $dept_id > 0 && empty( $web_allowed_by_id[ $dept_id ] ) ) {
            return new \WP_Error( 'blocked', __( 'Self punching not allowed for this department. Use kiosk.', 'sfs-hr' ), [ 'status' => 403 ] );
        }
    }

    // ---- GEO validation (server-side, BEFORE insert)
    // Policy may exempt geofence for specific punch types (shift-level → role-based fallback)
    $enforce_geo = Policy_Service::should_enforce_geofence( (int) $emp, $punch_type, $assign );

    $lat = isset( $params['geo_lat'] ) ? (float) $params['geo_lat'] : null;
    $lng = isset( $params['geo_lng'] ) ? (float) $params['geo_lng'] : null;
    $acc = isset( $params['geo_accuracy_m'] ) ? (int) $params['geo_accuracy_m'] : null;

    $valid_geo = 1;

    // Kiosk punches: the employee's physical presence at the kiosk IS the geo
    // validation — kiosk devices don't send GPS coordinates, so skip the
    // assignment-level and device-level geofence checks entirely.
    if ( $source !== 'kiosk' ) {
        if ( $enforce_geo && $assign->location_lat !== null && $assign->location_lng !== null && $assign->location_radius_m !== null ) {
            if ( $lat === null || $lng === null ) {
                $valid_geo = 0;
            } else {
                $dist_m    = self::haversine_m( (float) $assign->location_lat, (float) $assign->location_lng, $lat, $lng );
                $valid_geo = ( $dist_m <= (int) $assign->location_radius_m ) ? 1 : 0;
            }
        }
        // Device-level geofence (if device is provided and active)
        if ( $enforce_geo && $device_id > 0 ) {
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
    }

$dept_id = (int) ( $assign->dept_id ?? 0 );

// مصدر واحد للحقيقة
$mode = \SFS\HR\Modules\Attendance\AttendanceModule::selfie_mode_for(
    (int) $emp,
    $dept_id,
    [
        'device_id'      => $device_id ?: null,
        'shift_requires' => ((int) ($assign->require_selfie ?? 0) === 1),
        'punch_type'     => $punch_type,
    ]
);

// Determine if selfie is required for THIS specific punch type + mode
$require_selfie = false;
if ( $mode === 'in_only' ) {
    $require_selfie = ( $punch_type === 'in' );
} elseif ( $mode === 'in_out' ) {
    $require_selfie = in_array( $punch_type, [ 'in', 'out' ], true );
} elseif ( $mode === 'all' ) {
    $require_selfie = in_array( $punch_type, [ 'in', 'out', 'break_start', 'break_end' ], true );
}

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
        // Skip wp_generate_attachment_metadata() for attendance selfies.
        // Thumbnail generation (GD/Imagick) takes 2-5s and selfies are only
        // stored as evidence — they are never displayed as thumbnails.
        // Store minimal metadata so wp_get_attachment_url() still works.
        $img_size = @getimagesize( $up['file'] );
        $meta = [
            'width'  => $img_size ? (int) $img_size[0] : 480,
            'height' => $img_size ? (int) $img_size[1] : 480,
            'file'   => _wp_relative_upload_path( $up['file'] ),
        ];
        wp_update_attachment_metadata( $attach_id, $meta );
        $selfie_media_id = (int) $attach_id;
        $valid_selfie    = 1;
        // post_meta written after punch insert (lines below) where punch_id is known
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

    // ---- Define variables needed for duplicate check and insert
    $nowUtc = current_time( 'mysql', true );
    // For offline punches, use client-reported time (already validated above)
    $punchTimeUtc = $nowUtc;
    if ( $is_offline_origin && $client_punch_time !== '' ) {
        $punchTimeUtc = gmdate( 'Y-m-d H:i:s', strtotime( $client_punch_time . ' UTC' ) );
    }
    $punchT = $wpdb->prefix . 'sfs_hr_attendance_punches';

    // ---- ACQUIRE DATABASE LOCK to prevent race conditions
    // This ensures only one request per employee can insert a punch at a time
    $lock_name = 'sfs_hr_punch_' . (int) $emp;
    $lock_acquired = $wpdb->get_var( $wpdb->prepare(
        "SELECT GET_LOCK(%s, 5)",
        $lock_name
    ) );

    if ( ! $lock_acquired ) {
        return new \WP_Error(
            'lock_timeout',
            'System is busy. Please try again.',
            [ 'status' => 503 ]
        );
    }

    try {
        // ---- RE-VALIDATE STATE INSIDE LOCK (H2 TOCTOU fix) ----
        // The initial snapshot_for_today + allow check happened BEFORE the lock.
        // A concurrent request could have changed the state between then and now.
        // Re-check the last punch to confirm the transition is still valid.
        $last_after_lock = $wpdb->get_var( $wpdb->prepare(
            "SELECT punch_type FROM {$punchT}
             WHERE employee_id = %d ORDER BY punch_time DESC LIMIT 1",
            (int) $emp
        ) );
        $state_after_lock = match ( $last_after_lock ) {
            'in', 'break_end' => 'in',
            'break_start'     => 'break',
            'out', null       => 'idle',
            default           => 'idle',
        };
        $allow_after_lock = [
            'in'          => ( $state_after_lock === 'idle' ),
            'out'         => ( $state_after_lock === 'in' || $has_stale_session ),
            'break_start' => ( $state_after_lock === 'in' ),
            'break_end'   => ( $state_after_lock === 'break' ),
        ];
        if ( empty( $allow_after_lock[ $punch_type ] ) ) {
            $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
            return new \WP_Error(
                'invalid_transition',
                'Another punch was processed simultaneously. Please try again.',
                [ 'status' => 409 ]
            );
        }

        // ---- FINAL DUPLICATE CHECK: Prevent exact duplicate within 30 seconds of punch time
        // For offline punches, check around the client-reported time to catch duplicates
        $dup_ref_time = $punchTimeUtc;
        $duplicate_check = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, punch_time FROM {$punchT}
             WHERE employee_id = %d
               AND punch_type = %s
               AND punch_time >= DATE_SUB(%s, INTERVAL 30 SECOND)
               AND punch_time <= DATE_ADD(%s, INTERVAL 30 SECOND)
             ORDER BY punch_time DESC
             LIMIT 1",
            (int) $emp,
            $punch_type,
            $dup_ref_time,
            $dup_ref_time
        ) );

        if ( $duplicate_check ) {
            $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
            $dup_time = wp_date( 'H:i:s', strtotime( $duplicate_check->punch_time . ' UTC' ) );
            return new \WP_Error(
                'duplicate',
                sprintf( 'This punch was already recorded at %s. Please wait before trying again.', $dup_time ),
                [ 'status' => 409 ]
            );
        }

        // ---- Insert immutable punch (inside lock)
        $punch_note = null;
        if ( $is_offline_origin ) {
            $punch_note = '[offline] Queued at device while offline; synced at ' . wp_date( 'Y-m-d H:i:s' );
        }

        $wpdb->insert( $punchT, [
        'employee_id'     => (int) $emp,
        'punch_type'      => $punch_type,
        'punch_time'      => $punchTimeUtc,
        'source'          => $source,
        'device_id'       => ( $device_id ?: null ),
        'ip_addr'         => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : null,
        'geo_lat'         => $lat,
        'geo_lng'         => $lng,
        'geo_accuracy_m'  => $acc,
        'valid_geo'       => (int) $valid_geo,
        'note'            => $punch_note,
        'selfie_media_id' => ($selfie_media_id ?: null),
        'valid_selfie'    => (int) $valid_selfie,
        'offline_origin'  => $is_offline_origin ? 1 : 0,
        'created_at'      => $nowUtc,
        'created_by'      => $uid,
    ] );

$punch_id = (int) $wpdb->insert_id;

// Check if insert failed
if ( $punch_id === 0 ) {
    $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
    error_log( sprintf( '[SFS ATT] Punch insert failed for employee %d, type %s. DB error: %s', $emp, $punch_type, $wpdb->last_error ) );
    return new \WP_Error(
        'insert_failed',
        'Failed to record punch. Please try again.',
        [ 'status' => 500 ]
    );
}

if ( $selfie_media_id ) {
    update_post_meta( $selfie_media_id, '_sfs_att_punch_id', $punch_id );
    update_post_meta( $selfie_media_id, '_sfs_att_employee_id', (int) $emp );
    update_post_meta( $selfie_media_id, '_sfs_att_source', $source );
}


    // ---- Audit
    $auditT = $wpdb->prefix . 'sfs_hr_attendance_audit';
    $wpdb->insert( $auditT, [
        'actor_user_id'      => $uid,
        'action_type'        => 'punch.create',
        'target_employee_id' => (int) $emp,
        'target_punch_id'    => $punch_id,
        'target_session_id'  => null,
        'before_json'        => null,
        'after_json'         => wp_json_encode( array_filter( [
            'punch_type'      => $punch_type,
            'source'          => $source,
            'valid_geo'       => $valid_geo,
            'valid_selfie'    => $valid_selfie,
            'offline_origin'  => $is_offline_origin ? true : null,
            'client_punch_time' => $is_offline_origin ? $client_punch_time : null,
        ], fn( $v ) => $v !== null ) ),
        'created_at'         => $nowUtc,
    ] );

    // ---- Recalculate the session for the work date (may be yesterday for overnight shifts)
        \SFS\HR\Modules\Attendance\AttendanceModule::recalc_session_for( (int) $emp, $dateYmd, null, true );

    // Build post-punch snapshot from pre-punch state (avoids duplicate DB query).
    // The new punch deterministically changes state/allow/label/history.
    $snap  = $pre;
    $today = wp_date( 'Y-m-d' );

    // Update state based on the punch we just inserted
    $post_state = match ( $punch_type ) {
        'in', 'break_end' => 'in',
        'break_start'     => 'break',
        'out'             => 'idle',
        default           => $pre['state'] ?? 'idle',
    };
    $snap['state'] = $post_state;
    $snap['allow'] = [
        'in'          => ( $post_state === 'idle' ),
        'out'         => ( $post_state === 'in' ),
        'break_start' => ( $post_state === 'in' ),
        'break_end'   => ( $post_state === 'break' ),
    ];

    // Update label
    $type_labels_post = [
        'in'          => __( 'Clock In', 'sfs-hr' ),
        'out'         => __( 'Clock Out', 'sfs-hr' ),
        'break_start' => __( 'Break Start', 'sfs-hr' ),
        'break_end'   => __( 'Break End', 'sfs-hr' ),
    ];
    $punch_ts   = strtotime( $punchTimeUtc . ' UTC' );
    $when_label = wp_date( 'H:i', $punch_ts );
    $snap['label'] = sprintf(
        __( 'Last: %1$s at %2$s', 'sfs-hr' ),
        $type_labels_post[ $punch_type ] ?? strtoupper( str_replace( '_', ' ', $punch_type ) ),
        $when_label
    );

    // Append to punch history
    $snap['punch_history'][] = [
        'type' => $punch_type,
        'time' => $when_label,
    ];

    // Update clock_in_time if this was the first clock-in
    if ( $punch_type === 'in' && empty( $snap['clock_in_time'] ) ) {
        $snap['clock_in_time'] = $when_label;
    }

    // Clear stale session message after any successful punch
    $snap['stale_session_msg'] = '';

    // Selfie mode & dept for the NEXT punch response.
    // Reuse already-resolved $assign and $mode when the punch is for today
    // (common path ~95% of punches) — saves 4–9 DB queries.
    // Only re-resolve for overnight shifts where today differs from $dateYmd.
    if ( $dateYmd === $today ) {
        $mode_next    = $mode;
        $dept_id_resp = $dept_id;
    } else {
        $shift_today         = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( (int) $emp, $today );
        $dept_id_resp        = $shift_today && ! empty( $shift_today->dept_id ) ? (int) $shift_today->dept_id : 0;
        $shift_requires_next = $shift_today && ! empty( $shift_today->require_selfie );
        $mode_next = \SFS\HR\Modules\Attendance\AttendanceModule::selfie_mode_for(
            (int) $emp,
            $dept_id_resp,
            [
                'device_id'      => $device_id ?: null,
                'shift_requires' => $shift_requires_next,
            ]
        );
    }
    $requires_selfie_next = in_array( $mode_next, [ 'in_only', 'in_out', 'all' ], true );

    $resp = array_merge( $snap, [
        'selfie_mode'     => $mode_next,
        'requires_selfie' => (bool) $requires_selfie_next,
        'dept_id'         => $dept_id_resp,
    ] );

    if ( $selfie_media_id ) {
        $resp['selfie_media_id'] = $selfie_media_id;
        $resp['selfie_url']      = wp_get_attachment_url( $selfie_media_id );
    }

    // Release the database lock before returning
    $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );

    return rest_ensure_response( $resp );

    } catch ( \Throwable $e ) {
        // Ensure lock is released even on error
        $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
        throw $e;
    }

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
                // Skip thumbnail generation — selfies are evidence, not displayed
                $img_size = @getimagesize( $up['file'] );
                wp_update_attachment_metadata( $att_id, [
                    'width'  => $img_size ? (int) $img_size[0] : 480,
                    'height' => $img_size ? (int) $img_size[1] : 480,
                    'file'   => _wp_relative_upload_path( $up['file'] ),
                ] );
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
                        // Skip thumbnail generation — selfies are evidence, not displayed
                        $img_size = @getimagesize( $path );
                        wp_update_attachment_metadata( $att_id, [
                            'width'  => $img_size ? (int) $img_size[0] : 480,
                            'height' => $img_size ? (int) $img_size[1] : 480,
                            'file'   => _wp_relative_upload_path( $path ),
                        ] );
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

    // --- Overnight shift detection ---
    // If the employee's last punch (globally) is an open 'in' or 'break_end'
    // from yesterday, they have an unfinished session and should be able to
    // clock out today — but only within the shift's overtime buffer.
    //
    // Previous approach compared current time to shift end_utc which failed
    // when the employee tried to clock out even 1 second after shift end.
    // We now use the same overtime buffer the session builder uses so the
    // employee can clock out during a reasonable window after shift end,
    // but stale sessions (e.g. forgot to clock out) auto-expire.
    $overnight_ymd      = '';
    $stale_open_session = '';

    // Get employee's absolute last punch (no date filter)
    $last_global = $wpdb->get_row( $wpdb->prepare(
        "SELECT punch_type, punch_time FROM {$pT}
         WHERE employee_id = %d ORDER BY punch_time DESC LIMIT 1",
        $employee_id
    ) );

    if ( $last_global ) {
        $last_type_g  = (string) $last_global->punch_type;
        $last_ts_g    = strtotime( $last_global->punch_time . ' UTC' );
        $last_local   = wp_date( 'Y-m-d', $last_ts_g );

        // If the last punch is an open session (in or break_end, not out)
        // and it belongs to a previous day, check whether we're still within
        // the shift's allowed window before extending.
        // Check any previous day (not just yesterday) to handle sessions stuck
        // for 2+ days — e.g. employee forgot to clock out before a weekend (C3 fix).
        if ( in_array( $last_type_g, [ 'in', 'break_end' ], true ) && $last_local < $today ) {
            // Resolve the shift for the day the session started and check
            // whether current time is still within shift_end + buffer.
            $work_date  = $last_local;
            $prev_shift = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( $employee_id, $work_date );
            $prev_segs  = \SFS\HR\Modules\Attendance\AttendanceModule::build_segments_from_shift( $prev_shift, $work_date );

            $still_within_buffer = false;
            $nowTs = current_time( 'timestamp', true );

            if ( ! empty( $prev_segs ) ) {
                $lastSeg    = end( $prev_segs );
                $segEndTs   = strtotime( $lastSeg['end_utc'] . ' UTC' );
                $confBufRaw = $prev_shift->overtime_buffer_minutes ?? null;
                $bufferMin  = ( $confBufRaw !== null )
                    ? (int) $confBufRaw
                    : min( (int) round( $lastSeg['minutes'] * 0.5 ), 240 );
                $deadlineTs = $segEndTs + $bufferMin * 60;
                $still_within_buffer = ( $nowTs <= $deadlineTs );
            } else {
                // Total-hours shifts (00:00–00:00) produce no segments.
                // Fall back to a time cap from the last IN punch so the
                // employee can still close the overnight session.
                $confBufRaw = ( $prev_shift->overtime_buffer_minutes ?? null );
                $bufferMin  = ( $confBufRaw !== null )
                    ? (int) $confBufRaw
                    : 1440; // 24-hour default for segment-less shifts
                $deadlineTs = $last_ts_g + $bufferMin * 60;
                $still_within_buffer = ( $nowTs <= $deadlineTs );
            }

            if ( $still_within_buffer ) {
                $work_local    = new \DateTimeImmutable( $work_date . ' 00:00:00', $tz );
                $start_utc     = $work_local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
                $overnight_ymd = $work_date;
            } else {
                // Buffer expired — stale open session (forgot to clock out).
                // Surface this to the UI so the employee knows why they can't act.
                $stale_open_session = $work_date;
            }
        }
    }

    // Pull punches in order (may include yesterday for overnight shifts)
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT punch_type, punch_time
         FROM {$pT}
         WHERE employee_id=%d
           AND punch_time >= %s AND punch_time < %s
         ORDER BY punch_time ASC",
        $employee_id, $start_utc, $end_utc
    ) );

    $state = 'idle'; // 'idle' | 'in' | 'break'
    $label = __( 'Ready', 'sfs-hr' );

    $type_labels = [
        'in'          => __( 'Clock In', 'sfs-hr' ),
        'out'         => __( 'Clock Out', 'sfs-hr' ),
        'break_start' => __( 'Break Start', 'sfs-hr' ),
        'break_end'   => __( 'Break End', 'sfs-hr' ),
    ];

    // Track clock-in time and compute working duration (excluding breaks).
    $clock_in_time   = '';
    $working_seconds = 0;
    $work_start      = null; // timestamp when current work segment started
    $break_start_ts  = null; // timestamp when current break started

    foreach ( $rows as $r ) {
        $ts = strtotime( $r->punch_time . ' UTC' );
        switch ( $r->punch_type ) {
            case 'in':
                if ( ! $clock_in_time ) {
                    $clock_in_time = wp_date( 'H:i', $ts );
                }
                $work_start = $ts;
                break;
            case 'break_start':
                if ( $work_start ) {
                    $working_seconds += $ts - $work_start;
                    $work_start = null;
                }
                $break_start_ts = $ts;
                break;
            case 'break_end':
                $work_start     = $ts;
                $break_start_ts = null;
                break;
            case 'out':
                if ( $work_start ) {
                    $working_seconds += $ts - $work_start;
                    $work_start = null;
                }
                break;
        }
    }

    // If still clocked in, add elapsed time up to now.
    if ( $work_start ) {
        $working_seconds += current_time( 'timestamp', true ) - $work_start;
    }

    if (!empty($rows)) {
        $last     = end($rows);
        $lastType = (string)$last->punch_type;
        $when     = wp_date('H:i', strtotime($last->punch_time . ' UTC')); // shown in site TZ
        $typeLabel = $type_labels[ $lastType ] ?? strtoupper(str_replace('_',' ', $lastType));
        /* translators: %1$s = punch type label (e.g. Clock In), %2$s = time (e.g. 14:30) */
        $label    = sprintf( __( 'Last: %1$s at %2$s', 'sfs-hr' ), $typeLabel, $when );

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

    // --- Off-day detection ---
    // Check if today has a valid shift. When the shift resolves to null (weekly
    // override, period override off_days, or schedule rotation day-off), block
    // new clock-ins so the button doesn't appear on rest days.
    // An active overnight session (clocked in yesterday) still allows clock-out.
    $is_off_day = false;
    if ( $state === 'idle' && empty( $overnight_ymd ) ) {
        $today_shift = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( $employee_id, $today );

        // Diagnostic: log which shift resolved and its weekly_overrides so we
        // can verify the correct shift is being used for off-day detection.
        $tz_diag      = wp_timezone();
        $diag_date    = new \DateTimeImmutable( $today . ' 00:00:00', $tz_diag );
        $diag_dow     = strtolower( $diag_date->format( 'l' ) );
        $diag_shift_id = $today_shift ? ( $today_shift->id ?? 'no-id' ) : 'null';
        $diag_wo       = $today_shift ? ( $today_shift->weekly_overrides ?? '(empty)' ) : 'N/A';
        $diag_virtual  = $today_shift ? ( $today_shift->__virtual ?? '?' ) : 'N/A';
        $diag_path     = 'unknown';
        if ( $today_shift ) {
            if ( isset( $today_shift->__virtual ) && (int) $today_shift->__virtual === 0 ) {
                $diag_path = 'assignment_or_emp_shift';
            } else {
                $diag_path = 'dept_automation_or_fallback';
            }
        }
        error_log( sprintf(
            '[SFS ATT OFF-DAY DIAG] emp=%d date=%s dow=%s | resolved_shift_id=%s path=%s virtual=%s | weekly_overrides=%s',
            $employee_id, $today, $diag_dow, $diag_shift_id, $diag_path, $diag_virtual, $diag_wo
        ) );

        if ( ! $today_shift ) {
            $is_off_day  = true;
            $allow['in'] = false;
            $label       = __( 'Day Off', 'sfs-hr' );
        }
    }

    // --- Stale open session ---
    // When an overnight session exists but its buffer has expired, inform the
    // employee.  The UI will display a message instead of showing stale buttons.
    $stale_session_msg = '';
    if ( $stale_open_session !== '' && $state === 'idle' ) {
        $stale_session_msg = __( 'Your previous shift was not closed. Please contact HR.', 'sfs-hr' );
        $label             = $stale_session_msg;
    }

    // Build punch history for UI display.
    $punch_history = [];
    foreach ( $rows as $r ) {
        $punch_history[] = [
            'type' => $r->punch_type,
            'time' => wp_date( 'H:i', strtotime( $r->punch_time . ' UTC' ) ),
        ];
    }

    // Get target hours for progress display.
    $target_seconds = 0;
    if ( $employee_id ) {
        $today_ymd = wp_date( 'Y-m-d' );
        $shift = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( $employee_id, $today_ymd );
        $target_hours = \SFS\HR\Modules\Attendance\Services\Policy_Service::get_target_hours( $employee_id, $shift );
        $target_seconds = (int) ( $target_hours * 3600 );
    }

    return [
        'label'              => $label,
        'state'              => $state,
        'allow'              => $allow,
        'clock_in_time'      => $clock_in_time,
        'working_seconds'    => max( 0, (int) $working_seconds ),
        'target_seconds'     => $target_seconds,
        'punch_history'      => $punch_history,
        'overnight_ymd'      => $overnight_ymd,
        'is_off_day'         => $is_off_day,
        'stale_session_msg'  => $stale_session_msg,
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

    /**
     * Get client IP address.
     *
     * Only trusts X-Forwarded-For / X-Real-IP when the immediate peer
     * (REMOTE_ADDR) is in the trusted-proxy list.  Without this gate,
     * any client can spoof its IP and bypass per-IP rate limiting.
     *
     * Trusted proxies can be configured via the
     * `sfs_hr_attendance_trusted_proxies` filter (returns string[]).
     */
    private static function get_client_ip(): string {
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '0.0.0.0';

        /**
         * Filter the list of trusted reverse-proxy IPs.
         *
         * @param string[] $proxies Default: loopback addresses.
         */
        $trusted = apply_filters( 'sfs_hr_attendance_trusted_proxies', [ '127.0.0.1', '::1' ] );

        if ( in_array( $remote_addr, $trusted, true ) ) {
            // Peer is a known proxy — check forwarded headers
            foreach ( [ 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ] as $key ) {
                $val = isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
                if ( $val !== '' ) {
                    $ip = trim( explode( ',', $val )[0] );
                    if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                        return $ip;
                    }
                }
            }
        }

        // Untrusted peer or no valid forwarded header — use REMOTE_ADDR
        return filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '0.0.0.0';
    }

    /**
     * Verify Manager PIN for kiosk device
     * POST /sfs-hr/v1/attendance/verify-pin
     */
    public static function verify_pin( \WP_REST_Request $req ) {
        $device_id = (int) $req->get_param( 'device_id' );
        $pin = (string) $req->get_param( 'pin' );

        if ( ! $device_id || ! $pin ) {
            return new \WP_Error( 'invalid_params', 'Device ID and PIN are required', [ 'status' => 400 ] );
        }

        // ---- Rate limiting: check BEFORE any DB lookup or PIN verification ----
        // Per-device rate limit
        $device_fail_key = 'sfs_hr_pin_fail_' . $device_id;
        $device_failures = (int) get_transient( $device_fail_key );
        if ( $device_failures >= 5 ) {
            return new \WP_Error( 'too_many_attempts', 'Too many failed attempts. Please wait 5 minutes.', [ 'status' => 429 ] );
        }

        // Per-IP rate limit (prevents distributed brute-force across devices)
        $client_ip   = self::get_client_ip();
        $ip_fail_key = 'sfs_hr_pin_fail_ip_' . md5( $client_ip );
        $ip_failures = (int) get_transient( $ip_fail_key );
        if ( $ip_failures >= 10 ) {
            return new \WP_Error( 'too_many_attempts', 'Too many failed attempts from this location. Please wait 5 minutes.', [ 'status' => 429 ] );
        }

        global $wpdb;
        $devices_table = $wpdb->prefix . 'sfs_hr_attendance_devices';

        // Get device with PIN
        $device = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, label, kiosk_pin FROM {$devices_table} WHERE id = %d AND active = 1",
            $device_id
        ) );

        if ( ! $device ) {
            return new \WP_Error( 'device_not_found', 'Device not found or inactive', [ 'status' => 404 ] );
        }

        if ( ! $device->kiosk_pin ) {
            return new \WP_Error( 'no_pin_set', 'No PIN configured for this device', [ 'status' => 400 ] );
        }

        // Verify PIN using WordPress password functions
        if ( ! wp_check_password( $pin, $device->kiosk_pin ) ) {
            // Increment per-device failure count
            set_transient( $device_fail_key, $device_failures + 1, 300 ); // 5 min window
            // Increment per-IP failure count
            set_transient( $ip_fail_key, $ip_failures + 1, 300 ); // 5 min window

            return new \WP_Error( 'invalid_pin', 'Invalid PIN', [ 'status' => 401 ] );
        }

        // Success - clear failure counters
        delete_transient( $device_fail_key );
        // Note: IP counter is NOT cleared on success — prevents alternating valid/invalid to reset

        // Generate a short-lived session token for manager actions
        $token = wp_generate_password( 32, false );
        $session_key = 'sfs_hr_mgr_session_' . $device_id;
        set_transient( $session_key, [
            'token' => $token,
            'device_id' => $device_id,
            'verified_at' => time(),
        ], 3600 ); // 1 hour session

        return rest_ensure_response( [
            'success' => true,
            'token' => $token,
            'device_label' => $device->label,
            'expires_in' => 3600,
        ] );
    }
}