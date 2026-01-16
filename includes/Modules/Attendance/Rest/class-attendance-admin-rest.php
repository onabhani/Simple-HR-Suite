<?php
namespace SFS\HR\Modules\Attendance\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin_REST
 * Version: 0.1.1-rest-admin
 * Author: Omar Alnabhani (hdqah.com)
 *
 * REST for admin CRUD (shifts, assignments, devices, sessions rebuild)
 * Base: /sfs-hr/v1/attendance/...
 */
class Admin_REST {

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        // Shifts
        register_rest_route( $ns, '/attendance/shifts', [
            [
                'methods'  => 'GET',
                'callback' => [ __CLASS__, 'shifts_list' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'dept' => ['type'=>'string','required'=>false],
                    'active' => ['type'=>'boolean','required'=>false],
                ],
            ],
            [
                'methods'  => 'POST',
                'callback' => [ __CLASS__, 'shift_save' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/shifts/(?P<id>\d+)', [
            [
                'methods'  => 'DELETE',
                'callback' => [ __CLASS__, 'shift_delete' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        // Assignments
        register_rest_route( $ns, '/attendance/assign', [
            [
                'methods'  => 'GET',
                'callback' => [ __CLASS__, 'assign_list' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'date' => ['type'=>'string','required'=>true],
                    'dept' => ['type'=>'string','required'=>false],
                ],
            ],
            [
                'methods'  => 'POST',
                'callback' => [ __CLASS__, 'assign_bulk' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        // Devices
        register_rest_route( $ns, '/attendance/devices', [
            [
                'methods'  => 'GET',
                'callback' => [ __CLASS__, 'devices_list' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
            [
                'methods'  => 'POST',
                'callback' => [ __CLASS__, 'device_save' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/devices/(?P<id>\d+)', [
            [
                'methods'  => 'DELETE',
                'callback' => [ __CLASS__, 'device_delete' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        /* ---------------- Sessions (rebuild) ---------------- */
        // POST /sfs-hr/v1/attendance/sessions/rebuild
        register_rest_route( $ns, '/attendance/sessions/rebuild', [
            [
                'methods'  => 'POST',
                'callback' => [ __CLASS__, 'rebuild_sessions_day' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'date' => ['type'=>'string','required'=>false],         // default: today
                    'employee_id' => ['type'=>'integer','required'=>false], // default: 0 (all)
                    'device' => ['type'=>'integer','required'=>false],      // default: 0 (all)
                ],
            ],
        ] );
    }

    public static function can_admin(): bool {
        return current_user_can( 'sfs_hr_attendance_admin' );
    }

    /* ---------------- Shifts ---------------- */

    public static function shifts_list( \WP_REST_Request $req ) {
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $where = []; $args = [];
        $dept_id = $req->get_param('dept_id');
        $act     = $req->get_param('active');

        if ( $dept_id && is_numeric( $dept_id ) ) {
            $where[] = "dept_id=%d"; $args[] = (int) $dept_id;
        }
        if ( isset($act) ) {
            $where[] = "active=%d"; $args[] = $act ? 1 : 0;
        }

        $sql = "SELECT * FROM {$t}";
        if ( $where ) { $sql .= " WHERE " . implode(' AND ', array_map(fn($w)=>$w, $where) ); }
        $sql .= " ORDER BY active DESC, dept_id, name";

        $pre = $where ? $wpdb->prepare( $sql, ...$args ) : $sql;
        $rows = $wpdb->get_results( $pre );
        return rest_ensure_response( $rows );
    }

    public static function shift_save( \WP_REST_Request $req ) {
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $id = (int)$req->get_param('id');

        $name    = sanitize_text_field( (string)$req['name'] );
        $dept_id = isset( $req['dept_id'] ) ? (int) $req['dept_id'] : 0;

        $loc_label = sanitize_text_field( (string)$req['location_label'] );
        $lat = is_numeric($req['location_lat']) ? (float)$req['location_lat'] : null;
        $lng = is_numeric($req['location_lng']) ? (float)$req['location_lng'] : null;
        $rad = is_numeric($req['location_radius_m']) ? max(10,(int)$req['location_radius_m']) : null;

        $start = preg_match('/^\d{2}:\d{2}$/', (string)$req['start_time']) ? (string)$req['start_time'] : '';
        $end   = preg_match('/^\d{2}:\d{2}$/', (string)$req['end_time'])   ? (string)$req['end_time']   : '';

        $break_policy = in_array( (string)$req['break_policy'], ['auto','punch','none'], true ) ? (string)$req['break_policy'] : 'auto';
        $unpaid_break = max(0, (int)($req['unpaid_break_minutes'] ?? 0));
        $grace_l = max(0, (int)($req['grace_late_minutes'] ?? 5));
        $grace_e = max(0, (int)($req['grace_early_leave_minutes'] ?? 5));
        $round   = in_array( (string)$req['rounding_rule'], ['none','5','10','15'], true ) ? (string)$req['rounding_rule'] : '5';
        $ot_thr  = max(0, (int)($req['overtime_after_minutes'] ?? 0));
        $selfie  = (int)!empty($req['require_selfie']);
        $active  = (int)!empty($req['active']);
        $notes   = wp_kses_post( (string)($req['notes'] ?? '') );

        if ( ! $name || ! $loc_label || $lat === null || $lng === null || ! $start || ! $end ) {
            return new \WP_Error( 'invalid', 'Missing required fields.', [ 'status'=>400 ] );
        }

        $data = [
            'name'                      => $name,
            'dept_id'                   => $dept_id > 0 ? $dept_id : null,
            'location_label'            => $loc_label,
            'location_lat'              => $lat,
            'location_lng'              => $lng,
            'location_radius_m'         => $rad,
            'start_time'                => $start,
            'end_time'                  => $end,
            'break_policy'              => $break_policy,
            'unpaid_break_minutes'      => $unpaid_break,
            'grace_late_minutes'        => $grace_l,
            'grace_early_leave_minutes' => $grace_e,
            'rounding_rule'             => $round,
            'overtime_after_minutes'    => $ot_thr,
            'require_selfie'            => $selfie,
            'active'                    => $active,
            'notes'                     => $notes,
        ];

        if ( $id ) {
            $wpdb->update( $t, $data, ['id'=>$id] );
        } else {
            $wpdb->insert( $t, $data );
            $id = (int)$wpdb->insert_id;
        }
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $id) );
        return rest_ensure_response( $row );
    }

    public static function shift_delete( \WP_REST_Request $req ) {
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $id = (int)$req['id'];
        if ( ! $id ) { return new \WP_Error('invalid','Bad id',[ 'status'=>400 ]); }
        $wpdb->delete( $t, ['id'=>$id] );
        return rest_ensure_response( ['deleted'=>true] );
    }

    /* ---------------- Assignments ---------------- */

    public static function assign_list( \WP_REST_Request $req ) {
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';
        $date = (string)$req['date'];
        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ) {
            return new \WP_Error('invalid','Bad date',[ 'status'=>400 ]);
        }
        $dept_id = $req->get_param('dept_id');

        $sql = "SELECT sa.*, sh.name AS shift_name, sh.dept_id
                FROM {$t} sa
                JOIN {$wpdb->prefix}sfs_hr_attendance_shifts sh ON sh.id=sa.shift_id
                WHERE sa.work_date=%s";
        $args = [ $date ];
        if ( $dept_id && is_numeric( $dept_id ) ) {
            $sql .= " AND sh.dept_id=%d"; $args[] = (int) $dept_id;
        }
        $rows = $wpdb->get_results( $wpdb->prepare($sql, ...$args) );
        return rest_ensure_response( $rows );
    }

    public static function assign_bulk( \WP_REST_Request $req ) {
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';
        $shift_id = (int)$req['shift_id'];
        $sd = (string)$req['start_date']; $ed = (string)$req['end_date'];
        $emps = array_map('intval', (array)$req['employee_id']);
        $overwrite = (bool)$req['overwrite'];

        if ( ! $shift_id || ! preg_match('/^\d{4}-\d{2}-\d{2}$/',$sd) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/',$ed) || empty($emps) ) {
            return new \WP_Error('invalid','Invalid input',[ 'status'=>400 ]);
        }
        if ( $ed < $sd ) {
            return new \WP_Error('invalid','End date before start date',[ 'status'=>400 ]);
        }

        $start = new \DateTimeImmutable($sd); $end = new \DateTimeImmutable($ed);
        $days  = (int)$start->diff($end)->format('%a');

        for ( $i=0; $i<= $days; $i++ ) {
            $d = $start->modify("+{$i} day")->format('Y-m-d');
            foreach ( $emps as $eid ) {
                if ( $overwrite ) {
                    $wpdb->delete( $t, ['employee_id'=>$eid, 'work_date'=>$d] );
                }
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE employee_id=%d AND work_date=%s LIMIT 1", $eid, $d ) );
                if ( ! $exists ) {
                    $wpdb->insert( $t, [
                        'employee_id' => $eid,
                        'shift_id'    => $shift_id,
                        'work_date'   => $d,
                        'is_holiday'  => 0,
                        'override_json'=> null,
                    ] );
                }
            }
        }
        return rest_ensure_response( ['ok'=>true] );
    }

    /* ---------------- Devices ---------------- */

    public static function devices_list( \WP_REST_Request $req ) {
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_devices';
        $rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY active DESC, allowed_dept_id, label" );
        return rest_ensure_response( $rows );
    }

    public static function device_save( \WP_REST_Request $req ) {
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_devices';
        $id    = (int)$req->get_param('id');
        $label = sanitize_text_field( (string)$req['label'] );
        $type  = in_array( (string)$req['type'], ['kiosk','mobile','web'], true ) ? (string)$req['type'] : 'kiosk';
        $kiosk_enabled = (int)!empty($req['kiosk_enabled']);
        $kiosk_offline = (int)!empty($req['kiosk_offline']);
        $pin_raw = (string)($req['kiosk_pin'] ?? '');

        $lat = is_numeric($req['geo_lock_lat']) ? (float)$req['geo_lock_lat'] : null;
        $lng = is_numeric($req['geo_lock_lng']) ? (float)$req['geo_lock_lng'] : null;
        $rad = is_numeric($req['geo_lock_radius_m']) ? max(10,(int)$req['geo_lock_radius_m']) : null;
        $allowed_dept_id = isset( $req['allowed_dept_id'] ) && is_numeric( $req['allowed_dept_id'] ) ? (int) $req['allowed_dept_id'] : null;
        $active = (int)!empty($req['active']);

// read raw booleans/strings from request
$qr_enabled  = !empty($req['qr_enabled']);
$selfie_mode = in_array((string)($req['selfie_mode'] ?? 'optional'), ['never','optional','required'], true)
               ? (string)$req['selfie_mode'] : 'optional';

// merge existing meta_json
$meta = [];
if (!empty($id)) {
    $existing = $wpdb->get_var($wpdb->prepare("SELECT meta_json FROM {$t} WHERE id=%d", $id));
    if ($existing && ($j = json_decode($existing, true)) && is_array($j)) $meta = $j;
}
$meta['qr_enabled']  = $qr_enabled;
$meta['selfie_mode'] = $selfie_mode;

$data['meta_json'] = wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


        if ( ! $label ) {
            return new \WP_Error('invalid','Label required',[ 'status'=>400 ]);
        }

        $data = [
            'label'             => $label,
            'type'              => $type,
            'kiosk_enabled'     => $kiosk_enabled,
            'kiosk_offline'     => $kiosk_offline,
            'geo_lock_lat'      => $lat,
            'geo_lock_lng'      => $lng,
            'geo_lock_radius_m' => $rad,
            'allowed_dept_id'   => $allowed_dept_id,
            'active'            => $active,
        ];
        if ( $pin_raw !== '' ) { $data['kiosk_pin'] = wp_hash_password( $pin_raw ); }

        if ( $id ) { $wpdb->update( $t, $data, ['id'=>$id] ); }
        else       { $wpdb->insert( $t, $data ); $id = (int)$wpdb->insert_id; }

        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $id) );
        return rest_ensure_response( $row );
    }

    public static function device_delete( \WP_REST_Request $req ) {
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_devices';
        $id = (int)$req['id'];
        if ( ! $id ) { return new \WP_Error('invalid','Bad id',[ 'status'=>400 ]); }
        $wpdb->delete( $t, ['id'=>$id] );
        return rest_ensure_response( ['deleted'=>true] );
    }

    /* ---------------- Sessions (rebuild) ---------------- */

    /**
     * Rebuild sessions for a given date (optionally scoped by employee and/or device).
     * POST fields: date (Y-m-d, optional => today), employee_id (int, optional => 0/all), device (int, optional => 0/all)
     */
    public static function rebuild_sessions_day( \WP_REST_Request $req ) {
        // Read params first
        $params = $req->get_params();

        $date        = isset($params['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$params['date'])
            ? (string)$params['date']
            : wp_date('Y-m-d');

        $employee_id = isset($params['employee_id']) ? (int)$params['employee_id'] : 0;

        // ← HERE is your requested line:
        $device_id   = isset($params['device']) ? (int)$params['device'] : 0;

        // If you have a service that actually performs the rebuild, call it safely:
        try {
            if ( class_exists('\\SFS\\HR\\Modules\\Attendance\\AttendanceService') &&
                 method_exists('\\SFS\\HR\\Modules\\Attendance\\AttendanceService', 'rebuild_day') ) {

                // Signature suggestion: rebuild_day(string $date, int $employee_id = 0, int $device_id = 0): array|bool
                $result = \SFS\HR\Modules\Attendance\AttendanceService::rebuild_day($date, $employee_id, $device_id);

                return rest_ensure_response([
                    'ok'          => true,
                    'date'        => $date,
                    'employee_id' => $employee_id,
                    'device_id'   => $device_id,
                    'result'      => $result,
                ]);
            }

            // Fallback: just echo back what would be processed
            return rest_ensure_response([
                'ok'          => true,
                'date'        => $date,
                'employee_id' => $employee_id,
                'device_id'   => $device_id,
                'result'      => 'Service not wired; echo-only.',
            ]);
        } catch ( \Throwable $e ) {
            return new \WP_Error('rebuild_failed', $e->getMessage(), [ 'status' => 500 ]);
        }
    }
}
