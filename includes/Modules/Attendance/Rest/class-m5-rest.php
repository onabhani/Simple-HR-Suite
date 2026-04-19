<?php
namespace SFS\HR\Modules\Attendance\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * M5_REST
 *
 * REST endpoints for Milestone 5 features:
 *   - Roster / Shift Templates (M5.2)
 *   - Compliance Reports (M5.3)
 *   - Biometric Devices (M5.4)
 *   - UTC Backfill (M5.1)
 *
 * Base: /sfs-hr/v1/attendance/...
 */
class M5_REST {

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        // ── Roster Templates ────────────────────────────────────────────────
        register_rest_route( $ns, '/attendance/roster/templates', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'templates_list' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'active_only' => [ 'type' => 'boolean', 'default' => false ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'template_create' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/roster/templates/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'template_get' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'template_update' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'template_delete' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        // ── Roster Generation ───────────────────────────────────────────────
        register_rest_route( $ns, '/attendance/roster/generate', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'roster_generate' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/roster', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'roster_get' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'start_date' => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'end_date'   => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'dept_id'    => [ 'type' => 'integer', 'required' => false ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/roster/calendar', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'roster_calendar' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'start_date' => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'end_date'   => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'dept_id'    => [ 'type' => 'integer', 'required' => false ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/roster/validate', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'roster_validate' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/roster/clear', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'roster_clear' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/roster/copy', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'roster_copy' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        // ── Shift Bids ──────────────────────────────────────────────────────
        register_rest_route( $ns, '/attendance/roster/bids', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'bids_list' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'start_date' => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'end_date'   => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'dept_id'    => [ 'type' => 'integer', 'required' => false ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'bid_create' ],
                'permission_callback' => [ __CLASS__, 'can_employee' ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/roster/bids/(?P<id>\d+)/approve', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'bid_approve' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/roster/bids/(?P<id>\d+)/reject', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'bid_reject' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        // ── Compliance Reports ──────────────────────────────────────────────
        register_rest_route( $ns, '/attendance/compliance/hours', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'compliance_hours' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'start_date' => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'end_date'   => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'dept_id'    => [ 'type' => 'integer', 'required' => false ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/compliance/daily-violations', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'compliance_daily_violations' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'start_date' => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'end_date'   => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'is_ramadan' => [ 'type' => 'boolean', 'default' => false ],
                    'dept_id'    => [ 'type' => 'integer', 'required' => false ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/compliance/weekly-off', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'compliance_weekly_off' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'start_date' => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'end_date'   => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'dept_id'    => [ 'type' => 'integer', 'required' => false ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/compliance/consecutive', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'compliance_consecutive' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'start_date' => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'end_date'   => [ 'type' => 'string', 'required' => true, 'validate_callback' => [ __CLASS__, 'validate_date' ] ],
                    'dept_id'    => [ 'type' => 'integer', 'required' => false ],
                ],
            ],
        ] );

        // ── Biometric Devices ───────────────────────────────────────────────
        register_rest_route( $ns, '/attendance/biometric/devices', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'biometric_devices_list' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'active_only' => [ 'type' => 'boolean', 'default' => false ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'biometric_device_register' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/biometric/devices/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'biometric_device_get' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'biometric_device_update' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );

        // Biometric webhook — public endpoint authenticated via API key (no WP session)
        register_rest_route( $ns, '/attendance/biometric/webhook', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'biometric_webhook' ],
                'permission_callback' => '__return_true',
            ],
        ] );

        // ── UTC Backfill ────────────────────────────────────────────────────
        register_rest_route( $ns, '/attendance/utc/backfill', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'utc_backfill' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
                'args' => [
                    'batch_size' => [ 'type' => 'integer', 'default' => 500 ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/utc/stats', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'utc_stats' ],
                'permission_callback' => [ __CLASS__, 'can_admin' ],
            ],
        ] );
    }

    // ── Validation helpers ────────────────────────────────────────────────

    /**
     * Validate Y-m-d date format.
     */
    private static function validate_date( $value ): bool {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value );
    }

    /**
     * Strip sensitive fields from a device record before returning to client.
     */
    private static function sanitize_device_response( $device ) {
        if ( is_array( $device ) ) {
            unset( $device['api_key'] );
        } elseif ( is_object( $device ) ) {
            unset( $device->api_key );
        }
        return $device;
    }

    // ── Permission callbacks ────────────────────────────────────────────────

    public static function can_admin(): bool {
        return current_user_can( 'sfs_hr_attendance_admin' );
    }

    public static function can_employee(): bool {
        return current_user_can( 'sfs_hr_attendance_clock_self' );
    }

    // ── Roster Template endpoints ───────────────────────────────────────────

    public static function templates_list( \WP_REST_Request $req ) {
        $active_only = (bool) $req->get_param( 'active_only' );
        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::list_templates( $active_only );
        return rest_ensure_response( $result );
    }

    public static function template_get( \WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $template = \SFS\HR\Modules\Attendance\Services\Roster_Service::get_template( $id );
        if ( ! $template ) {
            return new \WP_Error( 'not_found', __( 'Template not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( $template );
    }

    public static function template_create( \WP_REST_Request $req ) {
        $data = [
            'name'           => sanitize_text_field( (string) ( $req['name'] ?? '' ) ),
            'pattern_type'   => sanitize_text_field( (string) ( $req['pattern_type'] ?? 'fixed' ) ),
            'pattern_json'   => $req['pattern_json'] ?? [],
            'cycle_days'     => (int) ( $req['cycle_days'] ?? 7 ),
            'min_rest_hours' => (int) ( $req['min_rest_hours'] ?? 8 ),
            'description'    => sanitize_textarea_field( (string) ( $req['description'] ?? '' ) ),
            'is_active'      => (int) ( $req['is_active'] ?? 1 ),
        ];

        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::create_template( $data );
        if ( ! empty( $result['error'] ) ) {
            return new \WP_Error( 'invalid', $result['error'], [ 'status' => 400 ] );
        }
        return rest_ensure_response( $result );
    }

    public static function template_update( \WP_REST_Request $req ) {
        $id   = (int) $req['id'];
        $data = array_filter( [
            'name'           => $req->has_param( 'name' ) ? sanitize_text_field( (string) $req['name'] ) : null,
            'pattern_type'   => $req->has_param( 'pattern_type' ) ? sanitize_text_field( (string) $req['pattern_type'] ) : null,
            'pattern_json'   => $req->has_param( 'pattern_json' ) ? $req['pattern_json'] : null,
            'cycle_days'     => $req->has_param( 'cycle_days' ) ? (int) $req['cycle_days'] : null,
            'min_rest_hours' => $req->has_param( 'min_rest_hours' ) ? (int) $req['min_rest_hours'] : null,
            'description'    => $req->has_param( 'description' ) ? sanitize_textarea_field( (string) $req['description'] ) : null,
            'is_active'      => $req->has_param( 'is_active' ) ? (int) $req['is_active'] : null,
        ], fn( $v ) => $v !== null );

        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::update_template( $id, $data );
        if ( ! empty( $result['error'] ) ) {
            $status = str_contains( $result['error'], 'not found' ) ? 404 : 400;
            return new \WP_Error( 'invalid', $result['error'], [ 'status' => $status ] );
        }
        return rest_ensure_response( $result );
    }

    public static function template_delete( \WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::delete_template( $id );
        if ( ! empty( $result['error'] ) ) {
            return new \WP_Error( 'not_found', $result['error'], [ 'status' => 404 ] );
        }
        return rest_ensure_response( $result );
    }

    // ── Roster Generation endpoints ─────────────────────────────────────────

    public static function roster_generate( \WP_REST_Request $req ) {
        $employee_ids = $req['employee_ids'] ?? [];
        $template_id  = (int) ( $req['template_id'] ?? 0 );
        $start_date   = sanitize_text_field( (string) ( $req['start_date'] ?? '' ) );
        $end_date     = sanitize_text_field( (string) ( $req['end_date'] ?? '' ) );
        $options      = $req['options'] ?? [];

        if ( empty( $employee_ids ) || ! $template_id || ! $start_date || ! $end_date ) {
            return new \WP_Error( 'invalid', __( 'employee_ids, template_id, start_date, and end_date are required.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::generate_roster(
            array_map( 'intval', (array) $employee_ids ),
            $template_id,
            $start_date,
            $end_date,
            (array) $options
        );

        if ( ! empty( $result['error'] ) ) {
            return new \WP_Error( 'generation_failed', $result['error'], [ 'status' => 400 ] );
        }
        return rest_ensure_response( $result );
    }

    public static function roster_get( \WP_REST_Request $req ) {
        $start   = sanitize_text_field( (string) $req['start_date'] );
        $end     = sanitize_text_field( (string) $req['end_date'] );
        $dept_id = $req->has_param( 'dept_id' ) ? (int) $req['dept_id'] : null;

        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::get_roster( $start, $end, $dept_id );
        return rest_ensure_response( $result );
    }

    public static function roster_calendar( \WP_REST_Request $req ) {
        $start   = sanitize_text_field( (string) $req['start_date'] );
        $end     = sanitize_text_field( (string) $req['end_date'] );
        $dept_id = $req->has_param( 'dept_id' ) ? (int) $req['dept_id'] : null;

        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::get_calendar_data( $start, $end, $dept_id );
        return rest_ensure_response( $result );
    }

    public static function roster_validate( \WP_REST_Request $req ) {
        $employee_ids = array_map( 'intval', (array) ( $req['employee_ids'] ?? [] ) );
        $template_id  = (int) ( $req['template_id'] ?? 0 );
        $start_date   = sanitize_text_field( (string) ( $req['start_date'] ?? '' ) );
        $end_date     = sanitize_text_field( (string) ( $req['end_date'] ?? '' ) );

        if ( empty( $employee_ids ) || ! $template_id || ! $start_date || ! $end_date ) {
            return new \WP_Error( 'invalid', __( 'employee_ids, template_id, start_date, and end_date are required.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::validate_roster( $employee_ids, $template_id, $start_date, $end_date );
        return rest_ensure_response( $result );
    }

    public static function roster_clear( \WP_REST_Request $req ) {
        $employee_ids = array_map( 'intval', (array) ( $req['employee_ids'] ?? [] ) );
        $start_date   = sanitize_text_field( (string) ( $req['start_date'] ?? '' ) );
        $end_date     = sanitize_text_field( (string) ( $req['end_date'] ?? '' ) );

        if ( empty( $employee_ids ) || ! $start_date || ! $end_date ) {
            return new \WP_Error( 'invalid', __( 'employee_ids, start_date, and end_date are required.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        $deleted = \SFS\HR\Modules\Attendance\Services\Roster_Service::clear_roster( $employee_ids, $start_date, $end_date );
        return rest_ensure_response( [ 'deleted' => $deleted ] );
    }

    public static function roster_copy( \WP_REST_Request $req ) {
        $source_start = sanitize_text_field( (string) ( $req['source_start'] ?? '' ) );
        $source_end   = sanitize_text_field( (string) ( $req['source_end'] ?? '' ) );
        $target_start = sanitize_text_field( (string) ( $req['target_start'] ?? '' ) );
        $employee_ids = isset( $req['employee_ids'] ) ? array_map( 'intval', (array) $req['employee_ids'] ) : null;

        if ( ! $source_start || ! $source_end || ! $target_start ) {
            return new \WP_Error( 'invalid', __( 'source_start, source_end, and target_start are required.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::copy_roster( $source_start, $source_end, $target_start, $employee_ids );
        if ( ! empty( $result['error'] ) ) {
            return new \WP_Error( 'copy_failed', $result['error'], [ 'status' => 400 ] );
        }
        return rest_ensure_response( $result );
    }

    // ── Shift Bids endpoints ────────────────────────────────────────────────

    public static function bids_list( \WP_REST_Request $req ) {
        $start   = sanitize_text_field( (string) $req['start_date'] );
        $end     = sanitize_text_field( (string) $req['end_date'] );
        $dept_id = $req->has_param( 'dept_id' ) ? (int) $req['dept_id'] : null;

        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::get_bids( $start, $end, $dept_id );
        return rest_ensure_response( $result );
    }

    public static function bid_create( \WP_REST_Request $req ) {
        $user_id     = get_current_user_id();
        $employee_id = \SFS\HR\Modules\Attendance\AttendanceModule::employee_id_from_user( $user_id );
        if ( ! $employee_id ) {
            return new \WP_Error( 'not_employee', __( 'No employee record found.', 'sfs-hr' ), [ 'status' => 403 ] );
        }

        $date     = sanitize_text_field( (string) ( $req['date'] ?? '' ) );
        $shift_id = (int) ( $req['preferred_shift_id'] ?? 0 );
        $reason   = sanitize_textarea_field( (string) ( $req['reason'] ?? '' ) );

        if ( ! $date || ! $shift_id ) {
            return new \WP_Error( 'invalid', __( 'date and preferred_shift_id are required.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::create_bid( $employee_id, $date, $shift_id, $reason );
        if ( ! empty( $result['error'] ) ) {
            return new \WP_Error( 'bid_failed', $result['error'], [ 'status' => 400 ] );
        }
        return rest_ensure_response( $result );
    }

    public static function bid_approve( \WP_REST_Request $req ) {
        $bid_id = (int) $req['id'];
        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::approve_bid( $bid_id, get_current_user_id() );
        if ( ! empty( $result['error'] ) ) {
            return new \WP_Error( 'approve_failed', $result['error'], [ 'status' => 400 ] );
        }
        return rest_ensure_response( $result );
    }

    public static function bid_reject( \WP_REST_Request $req ) {
        $bid_id = (int) $req['id'];
        $reason = sanitize_textarea_field( (string) ( $req['reason'] ?? '' ) );
        $result = \SFS\HR\Modules\Attendance\Services\Roster_Service::reject_bid( $bid_id, get_current_user_id(), $reason );
        if ( ! empty( $result['error'] ) ) {
            return new \WP_Error( 'reject_failed', $result['error'], [ 'status' => 400 ] );
        }
        return rest_ensure_response( $result );
    }

    // ── Compliance endpoints ────────────────────────────────────────────────

    public static function compliance_hours( \WP_REST_Request $req ) {
        $start   = sanitize_text_field( (string) $req['start_date'] );
        $end     = sanitize_text_field( (string) $req['end_date'] );
        $dept_id = $req->has_param( 'dept_id' ) ? (int) $req['dept_id'] : null;

        $result = \SFS\HR\Modules\Attendance\Services\Compliance_Service::hours_compliance_report( $start, $end, $dept_id );
        return rest_ensure_response( $result );
    }

    public static function compliance_daily_violations( \WP_REST_Request $req ) {
        $start      = sanitize_text_field( (string) $req['start_date'] );
        $end        = sanitize_text_field( (string) $req['end_date'] );
        $is_ramadan = (bool) $req->get_param( 'is_ramadan' );
        $dept_id    = $req->has_param( 'dept_id' ) ? (int) $req['dept_id'] : null;

        $result = \SFS\HR\Modules\Attendance\Services\Compliance_Service::daily_hours_violations( $start, $end, $is_ramadan, $dept_id );
        return rest_ensure_response( $result );
    }

    public static function compliance_weekly_off( \WP_REST_Request $req ) {
        $start   = sanitize_text_field( (string) $req['start_date'] );
        $end     = sanitize_text_field( (string) $req['end_date'] );
        $dept_id = $req->has_param( 'dept_id' ) ? (int) $req['dept_id'] : null;

        $result = \SFS\HR\Modules\Attendance\Services\Compliance_Service::weekly_off_violations( $start, $end, $dept_id );
        return rest_ensure_response( $result );
    }

    public static function compliance_consecutive( \WP_REST_Request $req ) {
        $start   = sanitize_text_field( (string) $req['start_date'] );
        $end     = sanitize_text_field( (string) $req['end_date'] );
        $dept_id = $req->has_param( 'dept_id' ) ? (int) $req['dept_id'] : null;

        $result = \SFS\HR\Modules\Attendance\Services\Compliance_Service::fatigue_report( $start, $end, \SFS\HR\Modules\Attendance\Services\Compliance_Service::MAX_CONSECUTIVE_WORK_DAYS, $dept_id );
        return rest_ensure_response( $result );
    }

    // ── Biometric Device endpoints ──────────────────────────────────────────

    public static function biometric_devices_list( \WP_REST_Request $req ) {
        $active_only = (bool) $req->get_param( 'active_only' );
        $result = \SFS\HR\Modules\Attendance\Services\Biometric_Service::list_devices( $active_only );
        // Strip API keys from response — only shown once at registration
        $result = array_map( [ __CLASS__, 'sanitize_device_response' ], $result );
        return rest_ensure_response( $result );
    }

    public static function biometric_device_get( \WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $device = \SFS\HR\Modules\Attendance\Services\Biometric_Service::get_device( $id );
        if ( ! $device ) {
            return new \WP_Error( 'not_found', __( 'Device not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( self::sanitize_device_response( $device ) );
    }

    public static function biometric_device_register( \WP_REST_Request $req ) {
        $data = [
            'device_serial'  => sanitize_text_field( (string) ( $req['device_serial'] ?? '' ) ),
            'device_type'    => sanitize_text_field( (string) ( $req['device_type'] ?? 'generic' ) ),
            'device_name'    => sanitize_text_field( (string) ( $req['device_name'] ?? '' ) ),
            'location_label' => sanitize_text_field( (string) ( $req['location_label'] ?? '' ) ),
            'location_lat'   => isset( $req['location_lat'] ) ? (float) $req['location_lat'] : null,
            'location_lng'   => isset( $req['location_lng'] ) ? (float) $req['location_lng'] : null,
            'config'         => $req['config'] ?? null,
        ];

        $result = \SFS\HR\Modules\Attendance\Services\Biometric_Service::register_device( $data );
        if ( ! ( $result['ok'] ?? false ) ) {
            return new \WP_Error( 'register_failed', $result['error'] ?? __( 'Registration failed.', 'sfs-hr' ), [ 'status' => 400 ] );
        }
        return rest_ensure_response( $result );
    }

    public static function biometric_device_update( \WP_REST_Request $req ) {
        $id   = (int) $req['id'];
        $data = array_filter( [
            'device_name'    => $req->has_param( 'device_name' ) ? sanitize_text_field( (string) $req['device_name'] ) : null,
            'location_label' => $req->has_param( 'location_label' ) ? sanitize_text_field( (string) $req['location_label'] ) : null,
            'location_lat'   => $req->has_param( 'location_lat' ) ? (float) $req['location_lat'] : null,
            'location_lng'   => $req->has_param( 'location_lng' ) ? (float) $req['location_lng'] : null,
            'is_active'      => $req->has_param( 'is_active' ) ? (int) $req['is_active'] : null,
            'device_type'    => $req->has_param( 'device_type' ) ? sanitize_text_field( (string) $req['device_type'] ) : null,
            'config'         => $req->has_param( 'config' ) ? $req['config'] : null,
        ], fn( $v ) => $v !== null );

        $result = \SFS\HR\Modules\Attendance\Services\Biometric_Service::update_device( $id, $data );
        if ( ! ( $result['ok'] ?? false ) ) {
            return new \WP_Error( 'update_failed', $result['error'] ?? __( 'Update failed.', 'sfs-hr' ), [ 'status' => 400 ] );
        }
        return rest_ensure_response( $result );
    }

    /**
     * Public webhook endpoint for biometric device punch data.
     * Authentication is via X-API-Key header matched against device records.
     */
    public static function biometric_webhook( \WP_REST_Request $req ) {
        $api_key = $req->get_header( 'X-API-Key' );
        if ( empty( $api_key ) ) {
            return new \WP_Error( 'unauthorized', __( 'Missing X-API-Key header.', 'sfs-hr' ), [ 'status' => 401 ] );
        }

        // Validate content type
        $params = $req->get_json_params();
        if ( empty( $params ) || ! is_array( $params ) ) {
            return new \WP_Error( 'bad_request', __( 'Request body must be valid JSON.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        // Authenticate the device
        if ( ! method_exists( \SFS\HR\Modules\Attendance\Services\Biometric_Service::class, 'authenticate_device' ) ) {
            return new \WP_Error( 'not_implemented', __( 'Webhook authentication not yet available.', 'sfs-hr' ), [ 'status' => 501 ] );
        }

        $device = \SFS\HR\Modules\Attendance\Services\Biometric_Service::authenticate_device( $api_key );
        if ( ! $device ) {
            return new \WP_Error( 'unauthorized', __( 'Invalid API key.', 'sfs-hr' ), [ 'status' => 401 ] );
        }

        // Process punch data — use process_punch or process_batch if available
        if ( method_exists( \SFS\HR\Modules\Attendance\Services\Biometric_Service::class, 'process_punch' ) ) {
            $result = \SFS\HR\Modules\Attendance\Services\Biometric_Service::process_punch( (int) $device->id, $params );
        } elseif ( method_exists( \SFS\HR\Modules\Attendance\Services\Biometric_Service::class, 'process_batch' ) ) {
            $result = \SFS\HR\Modules\Attendance\Services\Biometric_Service::process_batch( (int) $device->id, $params );
        } else {
            return new \WP_Error( 'not_implemented', __( 'Punch processing not yet available.', 'sfs-hr' ), [ 'status' => 501 ] );
        }

        if ( ! ( $result['ok'] ?? false ) ) {
            return new \WP_Error( 'ingest_failed', $result['error'] ?? __( 'Punch ingestion failed.', 'sfs-hr' ), [ 'status' => 400 ] );
        }
        return rest_ensure_response( $result );
    }

    // ── UTC Backfill endpoints ──────────────────────────────────────────────

    public static function utc_backfill( \WP_REST_Request $req ) {
        $batch_size = max( 1, min( 2000, (int) ( $req['batch_size'] ?? 500 ) ) );
        $updated    = \SFS\HR\Modules\Attendance\Services\UTC_Service::backfill_utc( $batch_size );
        $stats      = \SFS\HR\Modules\Attendance\Services\UTC_Service::get_backfill_stats();

        return rest_ensure_response( [
            'updated' => $updated,
            'stats'   => $stats,
        ] );
    }

    public static function utc_stats( \WP_REST_Request $req ) {
        $stats = \SFS\HR\Modules\Attendance\Services\UTC_Service::get_backfill_stats();
        return rest_ensure_response( $stats );
    }
}
