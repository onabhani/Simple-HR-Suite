<?php
namespace SFS\HR\Modules\Attendance\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Biometric_Rest {

    private static string $ns = 'sfs-hr/v1';

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
    }

    public static function routes(): void {
        register_rest_route( self::$ns, '/attendance/biometric/punch', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'handle_punch' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::$ns, '/attendance/biometric/batch', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'handle_batch' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::$ns, '/attendance/biometric/webhook', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::$ns, '/attendance/biometric/devices', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'list_devices' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( self::$ns, '/attendance/biometric/devices', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'create_device' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( self::$ns, '/attendance/biometric/devices/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ self::class, 'deactivate_device' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
    }

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    // ── Device-authenticated endpoints ──────────────────────────────────

    public static function handle_punch( \WP_REST_Request $request ): \WP_REST_Response {
        $device = self::authenticate_bearer( $request );
        if ( is_wp_error( $device ) ) {
            return new \WP_REST_Response(
                [ 'code' => 'unauthorized', 'message' => $device->get_error_message() ],
                401
            );
        }

        do_action( 'sfs_hr_biometric_before_punch', $device );

        $result = self::process_punch( $request->get_json_params(), $device, $request );
        if ( is_wp_error( $result ) ) {
            $status = $result->get_error_data()['status'] ?? 400;
            return new \WP_REST_Response(
                [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ],
                $status
            );
        }

        $status = $result['duplicate'] ? 200 : 201;
        return new \WP_REST_Response( $result, $status );
    }

    public static function handle_batch( \WP_REST_Request $request ): \WP_REST_Response {
        $device = self::authenticate_bearer( $request );
        if ( is_wp_error( $device ) ) {
            return new \WP_REST_Response(
                [ 'code' => 'unauthorized', 'message' => $device->get_error_message() ],
                401
            );
        }

        $body    = $request->get_json_params();
        $punches = $body['punches'] ?? [];

        if ( ! is_array( $punches ) || empty( $punches ) ) {
            return new \WP_REST_Response(
                [ 'code' => 'invalid_body', 'message' => __( 'punches array is required.', 'sfs-hr' ) ],
                400
            );
        }

        if ( count( $punches ) > 500 ) {
            return new \WP_REST_Response(
                [ 'code' => 'batch_too_large', 'message' => __( 'Maximum 500 punches per batch.', 'sfs-hr' ) ],
                400
            );
        }

        $created    = 0;
        $duplicates = 0;
        $errors     = [];

        foreach ( $punches as $i => $punch_data ) {
            $result = self::process_punch( $punch_data, $device, $request );
            if ( is_wp_error( $result ) ) {
                $errors[] = [
                    'index'   => $i,
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ];
                continue;
            }
            if ( $result['duplicate'] ) {
                $duplicates++;
            } else {
                $created++;
            }
        }

        return new \WP_REST_Response( [
            'processed'  => count( $punches ),
            'created'    => $created,
            'duplicates' => $duplicates,
            'errors'     => $errors,
        ], 200 );
    }

    public static function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        $device = self::authenticate_webhook( $request );
        if ( is_wp_error( $device ) ) {
            return new \WP_REST_Response(
                [ 'code' => 'unauthorized', 'message' => $device->get_error_message() ],
                401
            );
        }

        do_action( 'sfs_hr_biometric_before_punch', $device );

        $result = self::process_punch( $request->get_json_params(), $device, $request );
        if ( is_wp_error( $result ) ) {
            $status = $result->get_error_data()['status'] ?? 400;
            return new \WP_REST_Response(
                [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ],
                $status
            );
        }

        $status = $result['duplicate'] ? 200 : 201;
        return new \WP_REST_Response( $result, $status );
    }

    // ── Admin endpoints ─────────────────────────────────────────────────

    public static function list_devices( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_devices';

        $devices = $wpdb->get_results(
            "SELECT id, label, type, active, employee_id_field, meta_json, created_at FROM {$table} WHERE active = 1 ORDER BY id DESC"
        );

        $out = [];
        foreach ( $devices as $d ) {
            $out[] = [
                'id'                => (int) $d->id,
                'label'             => $d->label,
                'type'              => $d->type,
                'active'            => (bool) $d->active,
                'employee_id_field' => $d->employee_id_field,
                'meta_json'         => json_decode( $d->meta_json, true ),
            ];
        }

        return new \WP_REST_Response( $out, 200 );
    }

    public static function create_device( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_devices';

        $label            = sanitize_text_field( $request->get_param( 'label' ) ?? '' );
        $employee_id_field = sanitize_text_field( $request->get_param( 'employee_id_field' ) ?? 'employee_code' );

        if ( empty( $label ) ) {
            return new \WP_REST_Response(
                [ 'code' => 'missing_label', 'message' => __( 'Device label is required.', 'sfs-hr' ) ],
                400
            );
        }

        $allowed_fields = [ 'employee_code', 'national_id', 'id' ];
        if ( ! in_array( $employee_id_field, $allowed_fields, true ) ) {
            return new \WP_REST_Response(
                [ 'code' => 'invalid_field', 'message' => __( 'employee_id_field must be one of: employee_code, national_id, id.', 'sfs-hr' ) ],
                400
            );
        }

        $api_token      = wp_generate_password( 48, false );
        $api_token_hash  = hash( 'sha256', $api_token );
        $webhook_secret  = wp_generate_password( 32, false );

        $inserted = $wpdb->insert( $table, [
            'label'             => $label,
            'type'              => 'biometric',
            'api_token_hash'    => $api_token_hash,
            'webhook_secret'    => $webhook_secret,
            'employee_id_field' => $employee_id_field,
            'active'            => 1,
            'meta_json'         => '{}',
        ], [ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ] );

        if ( ! $inserted ) {
            return new \WP_REST_Response(
                [ 'code' => 'db_error', 'message' => __( 'Failed to create device.', 'sfs-hr' ) ],
                500
            );
        }

        return new \WP_REST_Response( [
            'id'                => $wpdb->insert_id,
            'label'             => $label,
            'employee_id_field' => $employee_id_field,
            'api_token'         => $api_token,
            'webhook_secret'    => $webhook_secret,
        ], 201 );
    }

    public static function deactivate_device( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_devices';
        $id    = absint( $request->get_param( 'id' ) );

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d",
            $id
        ) );

        if ( ! $exists ) {
            return new \WP_REST_Response(
                [ 'code' => 'not_found', 'message' => __( 'Device not found.', 'sfs-hr' ) ],
                404
            );
        }

        $wpdb->update( $table, [ 'active' => 0 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );

        return new \WP_REST_Response( [ 'deactivated' => true ], 200 );
    }

    // ── Authentication helpers ──────────────────────────────────────────

    private static function authenticate_bearer( \WP_REST_Request $request ) {
        $header = $request->get_header( 'Authorization' );
        if ( ! $header || stripos( $header, 'Bearer ' ) !== 0 ) {
            return new \WP_Error( 'missing_token', __( 'Bearer token required.', 'sfs-hr' ) );
        }

        $token = substr( $header, 7 );
        $hash  = hash( 'sha256', $token );

        global $wpdb;
        $table  = $wpdb->prefix . 'sfs_hr_attendance_devices';
        $device = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE api_token_hash = %s AND active = 1 LIMIT 1",
            $hash
        ) );

        if ( ! $device ) {
            return new \WP_Error( 'invalid_token', __( 'Invalid or inactive device token.', 'sfs-hr' ) );
        }

        return $device;
    }

    private static function authenticate_webhook( \WP_REST_Request $request ) {
        global $wpdb;

        $device_id = sanitize_text_field( $request->get_header( 'X-Device-ID' ) ?? '' );
        $signature = sanitize_text_field( $request->get_header( 'X-Webhook-Signature' ) ?? '' );

        if ( empty( $device_id ) || empty( $signature ) ) {
            return new \WP_Error( 'missing_headers', __( 'X-Device-ID and X-Webhook-Signature headers required.', 'sfs-hr' ) );
        }

        $table  = $wpdb->prefix . 'sfs_hr_attendance_devices';
        $device = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND active = 1 LIMIT 1",
            absint( $device_id )
        ) );

        if ( ! $device ) {
            return new \WP_Error( 'invalid_device', __( 'Device not found or inactive.', 'sfs-hr' ) );
        }

        if ( empty( $device->webhook_secret ) ) {
            return new \WP_Error( 'no_secret', __( 'Webhook secret not configured for this device.', 'sfs-hr' ) );
        }

        $raw_body = $request->get_body();
        $expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $device->webhook_secret );

        if ( ! hash_equals( $expected, $signature ) ) {
            return new \WP_Error( 'invalid_signature', __( 'Webhook signature verification failed.', 'sfs-hr' ) );
        }

        return $device;
    }

    // ── Punch processing ────────────────────────────────────────────────

    /**
     * @return array|\WP_Error
     */
    private static function process_punch( array $data, object $device, \WP_REST_Request $request ) {
        global $wpdb;

        $employee_ref = sanitize_text_field( $data['employee_ref'] ?? '' );
        $punch_type   = sanitize_text_field( $data['punch_type'] ?? '' );
        $punch_time   = sanitize_text_field( $data['punch_time'] ?? '' );
        $tz_offset    = sanitize_text_field( $data['tz_offset'] ?? '' );
        $external_ref = sanitize_text_field( $data['external_ref'] ?? '' );

        if ( empty( $employee_ref ) || empty( $punch_type ) || empty( $punch_time ) ) {
            return new \WP_Error( 'missing_fields', __( 'employee_ref, punch_type, and punch_time are required.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        if ( ! in_array( $punch_type, [ 'in', 'out' ], true ) ) {
            return new \WP_Error( 'invalid_punch_type', __( 'punch_type must be "in" or "out".', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $punch_time ) ) {
            return new \WP_Error( 'invalid_punch_time', __( 'punch_time must be in Y-m-d H:i:s format.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $id_field  = $device->employee_id_field ?: 'employee_code';

        $allowed_columns = [ 'employee_code', 'national_id', 'id' ];
        if ( ! in_array( $id_field, $allowed_columns, true ) ) {
            return new \WP_Error( 'config_error', __( 'Invalid employee_id_field on device.', 'sfs-hr' ), [ 'status' => 500 ] );
        }

        $employee = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, employee_code, user_id, status FROM {$emp_table} WHERE {$id_field} = %s LIMIT 1",
            $employee_ref
        ) );

        if ( ! $employee ) {
            return new \WP_Error( 'employee_not_found', __( 'Employee not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }

        $punch_table = $wpdb->prefix . 'sfs_hr_attendance_punches';

        if ( ! empty( $external_ref ) ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, punch_type, punch_time FROM {$punch_table} WHERE device_id = %d AND external_ref = %s LIMIT 1",
                $device->id,
                $external_ref
            ) );

            if ( $existing ) {
                return [
                    'punch_id'   => (int) $existing->id,
                    'duplicate'  => true,
                    'punch_type' => $existing->punch_type,
                    'punch_time' => $existing->punch_time,
                ];
            }
        }

        $punch_time_utc  = self::compute_utc( $punch_time, $tz_offset );
        $resolved_offset = ! empty( $tz_offset ) ? self::normalize_offset( $tz_offset ) : self::wp_tz_offset();
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

        $emp_id    = (int) $employee->id;
        $lock_name = 'sfs_hr_punch_' . $emp_id;
        $got_lock  = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 5)", $lock_name ) );
        if ( ! $got_lock ) {
            return new \WP_Error( 'lock_timeout', __( 'Concurrent punch in progress.', 'sfs-hr' ), [ 'status' => 409 ] );
        }

        $inserted = $wpdb->insert( $punch_table, [
            'employee_id'    => $emp_id,
            'punch_type'     => $punch_type,
            'punch_time'     => $punch_time,
            'punch_time_utc' => $punch_time_utc,
            'tz_offset'      => $resolved_offset,
            'source'         => 'biometric',
            'device_id'      => (int) $device->id,
            'external_ref'   => $external_ref ?: null,
            'ip_addr'        => $ip,
            'created_at'     => current_time( 'mysql', true ),
            'created_by'     => 0,
        ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d' ] );

        if ( ! $inserted ) {
            $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
            return new \WP_Error( 'db_error', __( 'Failed to record punch.', 'sfs-hr' ), [ 'status' => 500 ] );
        }

        $punch_id = $wpdb->insert_id;

        $audit_table = $wpdb->prefix . 'sfs_hr_attendance_audit';
        $wpdb->insert( $audit_table, [
            'actor_user_id'      => 0,
            'action_type'        => 'punch.create',
            'target_employee_id' => $emp_id,
            'target_punch_id'    => $punch_id,
            'before_json'        => null,
            'after_json'         => wp_json_encode( [ 'source' => 'biometric', 'device_id' => (int) $device->id, 'external_ref' => $external_ref ] ),
            'created_at'         => current_time( 'mysql', true ),
        ], [ '%d', '%s', '%d', '%d', '%s', '%s', '%s' ] );

        $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );

        $work_date = wp_date( 'Y-m-d', strtotime( $punch_time ) );
        \SFS\HR\Modules\Attendance\AttendanceModule::recalc_session_for( $emp_id, $work_date, null, true );

        do_action( 'sfs_hr_attendance_punch_recorded', $punch_id, $emp_id );

        return [
            'punch_id'   => $punch_id,
            'duplicate'  => false,
            'punch_type' => $punch_type,
            'punch_time' => $punch_time,
        ];
    }

    // ── Time helpers ────────────────────────────────────────────────────

    private static function compute_utc( string $local_time, string $tz_offset ): string {
        if ( empty( $tz_offset ) ) {
            $tz_offset = self::wp_tz_offset();
        }

        try {
            $tz  = new \DateTimeZone( $tz_offset );
            $dt  = new \DateTime( $local_time, $tz );
            $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( \Exception $e ) {
            // If offset parsing fails, treat as numeric hours offset
            $sign    = ( $tz_offset[0] === '-' ) ? 1 : -1;
            $parts   = explode( ':', ltrim( $tz_offset, '+-' ) );
            $hours   = (int) ( $parts[0] ?? 0 );
            $minutes = (int) ( $parts[1] ?? 0 );
            $total   = $sign * ( $hours * 3600 + $minutes * 60 );

            $ts = strtotime( $local_time );
            return gmdate( 'Y-m-d H:i:s', $ts + $total );
        }
    }

    private static function wp_tz_offset(): string {
        $tz_string = get_option( 'timezone_string' );
        if ( $tz_string ) {
            try {
                $tz     = new \DateTimeZone( $tz_string );
                $offset = $tz->getOffset( new \DateTime( 'now', $tz ) );
                $sign   = $offset >= 0 ? '+' : '-';
                $offset = abs( $offset );
                return sprintf( '%s%02d:%02d', $sign, intdiv( $offset, 3600 ), ( $offset % 3600 ) / 60 );
            } catch ( \Exception $e ) {
                // fall through
            }
        }

        $gmt_offset = (float) get_option( 'gmt_offset', 0 );
        $sign       = $gmt_offset >= 0 ? '+' : '-';
        $abs        = abs( $gmt_offset );
        $hours      = (int) $abs;
        $minutes    = (int) ( ( $abs - $hours ) * 60 );
        return sprintf( '%s%02d:%02d', $sign, $hours, $minutes );
    }

    private static function normalize_offset( string $input ): string {
        if ( preg_match( '/^[+-]\d{2}:\d{2}$/', $input ) ) {
            return $input;
        }
        try {
            $tz     = new \DateTimeZone( $input );
            $offset = $tz->getOffset( new \DateTime( 'now', $tz ) );
            $sign   = $offset >= 0 ? '+' : '-';
            $offset = abs( $offset );
            return sprintf( '%s%02d:%02d', $sign, intdiv( $offset, 3600 ), ( $offset % 3600 ) / 60 );
        } catch ( \Exception $e ) {
            return $input;
        }
    }
}
