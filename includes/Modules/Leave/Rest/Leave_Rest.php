<?php
namespace SFS\HR\Modules\Leave\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Leave\Services\CarryForwardService;
use SFS\HR\Modules\Leave\Services\EncashmentService;
use SFS\HR\Modules\Leave\Services\CompensatoryService;
use SFS\HR\Modules\Leave\Services\LeaveCalculationService;

/**
 * Thin REST wrapper for the Leave module enhancement services (M2).
 * Writes require sfs_hr.manage. Reads allow sfs_hr.view for self-service.
 */
class Leave_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        /* ── Carry-Forward ── */
        register_rest_route( $ns, '/leave/carry-forward/preview', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'cf_preview' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/leave/carry-forward/process', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'cf_process' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        /* ── Encashment ── */
        register_rest_route( $ns, '/leave/encashment', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'enc_list' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'enc_create' ],
                'permission_callback' => [ self::class, 'can_self_service' ],
            ],
        ] );
        register_rest_route( $ns, '/leave/encashment/encashable', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'enc_encashable' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );
        register_rest_route( $ns, '/leave/encashment/(?P<id>\d+)/approve', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'enc_approve' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/leave/encashment/(?P<id>\d+)/reject', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'enc_reject' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        /* ── Compensatory ── */
        register_rest_route( $ns, '/leave/compensatory', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'comp_list' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'comp_create' ],
                'permission_callback' => [ self::class, 'can_self_service' ],
            ],
        ] );
        register_rest_route( $ns, '/leave/compensatory/(?P<id>\d+)/approve', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'comp_approve' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/leave/compensatory/(?P<id>\d+)/reject', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'comp_reject' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        /* ── Calculations ── */
        register_rest_route( $ns, '/leave/balance/(?P<employee_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'bal_available' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );
        register_rest_route( $ns, '/leave/business-days', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'calc_business_days' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );
        register_rest_route( $ns, '/leave/holidays', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'calc_holidays' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );
    }

    /* ── Permission callbacks ── */

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    public static function can_view(): bool {
        return current_user_can( 'sfs_hr.view' ) || current_user_can( 'sfs_hr.manage' );
    }

    /**
     * Self-service write gate: require an authenticated WP user in addition
     * to sfs_hr.view. Prevents granting the view cap from enabling writes.
     */
    public static function can_self_service(): bool {
        return is_user_logged_in() && ( current_user_can( 'sfs_hr.view' ) || current_user_can( 'sfs_hr.manage' ) );
    }

    /* ── Resolve employee_id for the current user (self-service) ── */

    private static function current_employee_id(): ?int {
        global $wpdb;
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return null;
        }
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d LIMIT 1",
            $user_id
        ) );
        return $id ? (int) $id : null;
    }

    /**
     * Verify that the requested employee_id matches the current user,
     * or that the user has manage capability.
     */
    private static function authorize_employee( int $employee_id ): bool {
        if ( current_user_can( 'sfs_hr.manage' ) ) {
            return true;
        }
        return $employee_id === self::current_employee_id();
    }

    /* ── Carry-Forward handlers ── */

    public static function cf_preview( \WP_REST_Request $req ): \WP_REST_Response {
        $year = absint( $req['from_year'] ?? 0 ) ?: ( (int) wp_date( 'Y' ) - 1 );
        return new \WP_REST_Response( CarryForwardService::get_carry_forward_preview( $year ) );
    }

    public static function cf_process( \WP_REST_Request $req ): \WP_REST_Response {
        $body = (array) $req->get_json_params();

        // Year-end processing mutates balances for every active employee —
        // require an explicit confirm flag to prevent accidental double-runs
        // from replayed requests or compromised tokens.
        if ( empty( $body['confirm'] ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'error'   => __( 'Confirmation required: pass "confirm": true to process year-end carry-forward.', 'sfs-hr' ),
            ], 400 );
        }

        $year = absint( $body['from_year'] ?? 0 ) ?: ( (int) wp_date( 'Y' ) - 1 );
        return new \WP_REST_Response( CarryForwardService::process_year_end( $year ) );
    }

    /* ── Encashment handlers ── */

    public static function enc_list( \WP_REST_Request $req ): \WP_REST_Response {
        $filters = [
            'status'      => sanitize_text_field( $req['status'] ?? '' ),
            'employee_id' => absint( $req['employee_id'] ?? 0 ),
            'year'        => absint( $req['year'] ?? 0 ),
        ];

        // Employees without manage cap can only list their own requests.
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            $own = self::current_employee_id();
            if ( ! $own ) {
                return new \WP_REST_Response( [], 200 );
            }
            $filters['employee_id'] = $own;
        }

        return new \WP_REST_Response( EncashmentService::get_requests( array_filter( $filters ) ) );
    }

    public static function enc_encashable( \WP_REST_Request $req ): \WP_REST_Response {
        $employee_id = absint( $req['employee_id'] ?? 0 );
        $type_id     = absint( $req['type_id'] ?? 0 );
        $year        = absint( $req['year'] ?? 0 ) ?: (int) wp_date( 'Y' );

        if ( ! $employee_id || ! $type_id ) {
            return new \WP_REST_Response( [ 'error' => __( 'employee_id and type_id are required.', 'sfs-hr' ) ], 400 );
        }
        if ( ! self::authorize_employee( $employee_id ) ) {
            return new \WP_REST_Response( [ 'error' => __( 'Forbidden.', 'sfs-hr' ) ], 403 );
        }

        return new \WP_REST_Response( [
            'employee_id' => $employee_id,
            'type_id'     => $type_id,
            'year'        => $year,
            'encashable'  => EncashmentService::get_encashable( $employee_id, $type_id, $year ),
            'daily_rate'  => EncashmentService::daily_rate( $employee_id ),
        ] );
    }

    public static function enc_create( \WP_REST_Request $req ): \WP_REST_Response {
        $body        = (array) $req->get_json_params();
        $employee_id = absint( $body['employee_id'] ?? 0 );
        $type_id     = absint( $body['type_id'] ?? 0 );
        $year        = absint( $body['year'] ?? 0 ) ?: (int) wp_date( 'Y' );
        $days        = absint( $body['days'] ?? 0 );

        // Employees can only create requests for themselves.
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            $own = self::current_employee_id();
            if ( ! $own ) {
                return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Forbidden.', 'sfs-hr' ) ], 403 );
            }
            $employee_id = $own;
        }

        if ( ! $employee_id || ! $type_id || ! $days ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Missing required fields.', 'sfs-hr' ) ], 400 );
        }

        $result = EncashmentService::create_request( $employee_id, $type_id, $year, $days );
        $status = ! empty( $result['success'] ) ? 201 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function enc_approve( \WP_REST_Request $req ): \WP_REST_Response {
        $note   = sanitize_textarea_field( $req['note'] ?? '' );
        $result = EncashmentService::approve( (int) $req['id'], get_current_user_id(), $note );
        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function enc_reject( \WP_REST_Request $req ): \WP_REST_Response {
        $note   = sanitize_textarea_field( $req['note'] ?? '' );
        $result = EncashmentService::reject( (int) $req['id'], get_current_user_id(), $note );
        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    /* ── Compensatory handlers ── */

    public static function comp_list( \WP_REST_Request $req ): \WP_REST_Response {
        $filters = [
            'status'      => sanitize_text_field( $req['status'] ?? '' ),
            'employee_id' => absint( $req['employee_id'] ?? 0 ),
        ];

        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            $own = self::current_employee_id();
            if ( ! $own ) {
                return new \WP_REST_Response( [], 200 );
            }
            $filters['employee_id'] = $own;
        }

        return new \WP_REST_Response( CompensatoryService::get_requests( array_filter( $filters ) ) );
    }

    public static function comp_create( \WP_REST_Request $req ): \WP_REST_Response {
        $body        = (array) $req->get_json_params();
        $employee_id = absint( $body['employee_id'] ?? 0 );
        $work_date   = sanitize_text_field( $body['work_date'] ?? '' );
        $hours       = isset( $body['hours_worked'] ) ? (float) $body['hours_worked'] : 0.0;
        $reason      = sanitize_textarea_field( $body['reason'] ?? '' );
        $session_id  = ! empty( $body['session_id'] ) ? absint( $body['session_id'] ) : null;
        $type_id     = ! empty( $body['type_id'] ) ? absint( $body['type_id'] ) : null;

        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            $own = self::current_employee_id();
            if ( ! $own ) {
                return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Forbidden.', 'sfs-hr' ) ], 403 );
            }
            $employee_id = $own;
        }

        if ( ! $employee_id || ! $work_date ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Missing required fields.', 'sfs-hr' ) ], 400 );
        }

        $result = CompensatoryService::create_request( $employee_id, $work_date, $hours, $reason, $session_id, $type_id );
        $status = ! empty( $result['success'] ) ? 201 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function comp_approve( \WP_REST_Request $req ): \WP_REST_Response {
        $note   = sanitize_textarea_field( $req['note'] ?? '' );
        $result = CompensatoryService::approve( (int) $req['id'], get_current_user_id(), $note );
        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function comp_reject( \WP_REST_Request $req ): \WP_REST_Response {
        $note   = sanitize_textarea_field( $req['note'] ?? '' );
        $result = CompensatoryService::reject( (int) $req['id'], get_current_user_id(), $note );
        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    /* ── Calculations handlers ── */

    public static function bal_available( \WP_REST_Request $req ): \WP_REST_Response {
        $employee_id = (int) $req['employee_id'];
        $type_id     = absint( $req['type_id'] ?? 0 );
        $year        = absint( $req['year'] ?? 0 ) ?: (int) wp_date( 'Y' );
        $annual      = absint( $req['annual_quota'] ?? 0 );

        if ( ! $type_id ) {
            return new \WP_REST_Response( [ 'error' => __( 'type_id is required.', 'sfs-hr' ) ], 400 );
        }
        if ( ! self::authorize_employee( $employee_id ) ) {
            return new \WP_REST_Response( [ 'error' => __( 'Forbidden.', 'sfs-hr' ) ], 403 );
        }

        return new \WP_REST_Response( [
            'employee_id'    => $employee_id,
            'type_id'        => $type_id,
            'year'           => $year,
            'annual_quota'   => $annual,
            'available_days' => LeaveCalculationService::available_days( $employee_id, $type_id, $year, $annual ),
        ] );
    }

    public static function calc_business_days( \WP_REST_Request $req ): \WP_REST_Response {
        $start = sanitize_text_field( $req['start'] ?? '' );
        $end   = sanitize_text_field( $req['end'] ?? '' );
        if ( ! $start || ! $end ) {
            return new \WP_REST_Response( [ 'error' => __( 'start and end are required.', 'sfs-hr' ) ], 400 );
        }
        $err = LeaveCalculationService::validate_dates( $start, $end );
        if ( $err ) {
            return new \WP_REST_Response( [ 'error' => $err ], 400 );
        }
        return new \WP_REST_Response( [
            'start'         => $start,
            'end'           => $end,
            'business_days' => LeaveCalculationService::business_days( $start, $end ),
            'holidays'      => LeaveCalculationService::holidays_in_range( $start, $end ),
        ] );
    }

    public static function calc_holidays(): \WP_REST_Response {
        return new \WP_REST_Response( LeaveCalculationService::get_holidays() );
    }
}
