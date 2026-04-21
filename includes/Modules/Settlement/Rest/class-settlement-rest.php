<?php
namespace SFS\HR\Modules\Settlement\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Rest\Rest_Response;
use SFS\HR\Modules\Settlement\Services\Settlement_Service;

/**
 * Settlement_Rest
 *
 * M9.1 — REST endpoints for End-of-Service Settlement operations.
 *
 * Base: /sfs-hr/v1/settlements
 *   GET    /settlements                  — list with pagination + status filter
 *   POST   /settlements                  — create new settlement
 *   GET    /settlements/{id}             — detail
 *   POST   /settlements/{id}/approve     — approve (manager/HR)
 *   POST   /settlements/{id}/reject      — reject
 *   POST   /settlements/{id}/payment     — mark as paid
 *   POST   /settlements/calculate        — preview calculation (no persistence)
 *
 * All routes require sfs_hr.manage except read-only list/get which accept
 * sfs_hr.view.
 *
 * @since M9
 */
class Settlement_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        register_rest_route( $ns, '/settlements', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_settlements' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
                'args' => [
                    'page'     => [ 'type' => 'integer', 'default' => 1 ],
                    'per_page' => [ 'type' => 'integer', 'default' => 20 ],
                    'status'   => [ 'type' => 'string',  'required' => false ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_settlement' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/settlements/calculate', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'calculate_preview' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );

        register_rest_route( $ns, '/settlements/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_settlement' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );

        register_rest_route( $ns, '/settlements/(?P<id>\d+)/approve', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'approve_settlement' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/settlements/(?P<id>\d+)/reject', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'reject_settlement' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/settlements/(?P<id>\d+)/payment', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'mark_paid' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );
    }

    // ── Permissions ─────────────────────────────────────────────────────────

    public static function can_view(): bool {
        return current_user_can( 'sfs_hr.view' );
    }

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    // ── Handlers ────────────────────────────────────────────────────────────

    public static function list_settlements( \WP_REST_Request $req ): \WP_REST_Response {
        $pg       = Rest_Response::parse_pagination( $req );
        $status   = sanitize_text_field( (string) ( $req->get_param( 'status' ) ?? 'pending' ) );
        $valid    = [ 'pending', 'approved', 'rejected', 'paid' ];
        if ( ! in_array( $status, $valid, true ) ) {
            $status = 'pending';
        }

        $result = Settlement_Service::get_settlements( $status, $pg['page'], $pg['per_page'] );
        return Rest_Response::paginated(
            $result['rows'] ?? [],
            (int) ( $result['total'] ?? 0 ),
            $pg['page'],
            $pg['per_page']
        );
    }

    public static function get_settlement( \WP_REST_Request $req ) {
        $id  = (int) $req['id'];
        $row = Settlement_Service::get_settlement( $id );
        if ( ! $row ) {
            return Rest_Response::error( 'not_found', __( 'Settlement not found.', 'sfs-hr' ), 404 );
        }
        return Rest_Response::success( $row );
    }

    public static function calculate_preview( \WP_REST_Request $req ) {
        $basic    = (float) ( $req['basic_salary']    ?? 0 );
        $years    = (float) ( $req['years_of_service'] ?? 0 );
        $unused   = (int)   ( $req['unused_leave_days'] ?? 0 );
        $trigger  = sanitize_text_field( (string) ( $req['trigger_type'] ?? 'termination' ) );

        if ( $basic <= 0 || $years < 0 ) {
            return Rest_Response::error( 'invalid', __( 'basic_salary and years_of_service must be positive.', 'sfs-hr' ), 400 );
        }
        if ( ! in_array( $trigger, [ 'resignation', 'termination', 'contract_end' ], true ) ) {
            $trigger = 'termination';
        }

        $gratuity   = Settlement_Service::calculate_gratuity_with_trigger( $basic, $years, $trigger );
        $encashment = Settlement_Service::calculate_leave_encashment( $basic, $unused );

        return Rest_Response::success( [
            'basic_salary'      => $basic,
            'years_of_service'  => $years,
            'trigger_type'      => $trigger,
            'unused_leave_days' => $unused,
            'gratuity'          => $gratuity,
            'leave_encashment'  => $encashment,
        ] );
    }

    public static function create_settlement( \WP_REST_Request $req ) {
        $required = [ 'employee_id', 'resignation_id', 'last_working_day', 'years_of_service', 'basic_salary' ];
        $missing  = [];
        foreach ( $required as $k ) {
            if ( ! $req->has_param( $k ) ) {
                $missing[ $k ] = 'required';
                continue;
            }
            $v = $req[ $k ];
            // Reject null, empty string, and empty array.
            // Allow literal 0 / '0' for numeric fields (e.g., years_of_service = 0).
            if ( $v === null || $v === '' || ( is_array( $v ) && empty( $v ) ) ) {
                $missing[ $k ] = 'required';
            }
        }
        if ( ! empty( $missing ) ) {
            return Rest_Response::error( 'missing_fields', __( 'Required fields missing.', 'sfs-hr' ), 400, $missing );
        }

        $basic    = (float) $req['basic_salary'];
        $years    = (float) $req['years_of_service'];
        $unused   = (int) ( $req['unused_leave_days'] ?? 0 );
        $trigger  = sanitize_text_field( (string) ( $req['trigger_type'] ?? 'termination' ) );
        if ( ! in_array( $trigger, [ 'resignation', 'termination', 'contract_end' ], true ) ) {
            $trigger = 'termination';
        }

        // Server-side recalculation — don't trust client numbers.
        $gratuity    = Settlement_Service::calculate_gratuity_with_trigger( $basic, $years, $trigger );
        $encashment  = Settlement_Service::calculate_leave_encashment( $basic, $unused );
        $final_sal   = (float) ( $req['final_salary']     ?? 0 );
        $other       = (float) ( $req['other_allowances'] ?? 0 );
        $deductions  = (float) ( $req['deductions']       ?? 0 );
        $total       = Settlement_Service::calculate_total( [
            'gratuity'         => $gratuity,
            'leave_encashment' => $encashment,
            'final_salary'     => $final_sal,
            'other_allowances' => $other,
            'deductions'       => $deductions,
        ] );

        $new_id = Settlement_Service::create_settlement( [
            'employee_id'       => (int) $req['employee_id'],
            'resignation_id'    => (int) $req['resignation_id'],
            'last_working_day'  => sanitize_text_field( (string) $req['last_working_day'] ),
            'years_of_service'  => $years,
            'basic_salary'      => $basic,
            'gratuity_amount'   => $gratuity,
            'leave_encashment'  => $encashment,
            'unused_leave_days' => $unused,
            'final_salary'      => $final_sal,
            'other_allowances'  => $other,
            'deductions'        => $deductions,
            'deduction_notes'   => sanitize_textarea_field( (string) ( $req['deduction_notes'] ?? '' ) ),
            'total_settlement'  => $total,
            'trigger_type'      => $trigger,
        ] );

        if ( ! $new_id ) {
            return Rest_Response::error( 'db_error', __( 'Failed to create settlement.', 'sfs-hr' ), 500 );
        }

        return new \WP_REST_Response( [
            'data' => Settlement_Service::get_settlement( (int) $new_id ),
            'meta' => [ 'timestamp' => gmdate( 'c' ) ],
        ], 201 );
    }

    public static function approve_settlement( \WP_REST_Request $req ) {
        $id = (int) $req['id'];
        if ( ! Settlement_Service::get_settlement( $id ) ) {
            return Rest_Response::error( 'not_found', __( 'Settlement not found.', 'sfs-hr' ), 404 );
        }

        // Clearance checks mirror the admin_post flow.
        $s = Settlement_Service::get_settlement( $id );
        $emp_id = (int) $s['employee_id'];
        $loan   = Settlement_Service::check_loan_clearance( $emp_id );
        $asset  = Settlement_Service::check_asset_clearance( $emp_id );
        if ( ! $loan['cleared'] || ! $asset['cleared'] ) {
            return Rest_Response::error( 'clearance_required', __( 'Clearance required before approval.', 'sfs-hr' ), 409, [
                'loan_cleared'  => $loan['cleared'],
                'asset_cleared' => $asset['cleared'],
            ] );
        }

        $ok = Settlement_Service::update_status( $id, 'approved', [
            'approver_id'   => get_current_user_id(),
            'approver_note' => sanitize_textarea_field( (string) ( $req['note'] ?? '' ) ),
            'decided_at'    => current_time( 'mysql' ),
        ] );
        if ( ! $ok ) {
            return Rest_Response::error( 'invalid_transition', __( 'Cannot approve from the current status.', 'sfs-hr' ), 409 );
        }
        return Rest_Response::success( Settlement_Service::get_settlement( $id ) );
    }

    public static function reject_settlement( \WP_REST_Request $req ) {
        $id = (int) $req['id'];
        if ( ! Settlement_Service::get_settlement( $id ) ) {
            return Rest_Response::error( 'not_found', __( 'Settlement not found.', 'sfs-hr' ), 404 );
        }
        $ok = Settlement_Service::update_status( $id, 'rejected', [
            'approver_id'   => get_current_user_id(),
            'approver_note' => sanitize_textarea_field( (string) ( $req['reason'] ?? '' ) ),
            'decided_at'    => current_time( 'mysql' ),
        ] );
        if ( ! $ok ) {
            return Rest_Response::error( 'invalid_transition', __( 'Cannot reject from the current status.', 'sfs-hr' ), 409 );
        }
        return Rest_Response::success( Settlement_Service::get_settlement( $id ) );
    }

    public static function mark_paid( \WP_REST_Request $req ) {
        $id = (int) $req['id'];
        if ( ! Settlement_Service::get_settlement( $id ) ) {
            return Rest_Response::error( 'not_found', __( 'Settlement not found.', 'sfs-hr' ), 404 );
        }

        $payment_date      = sanitize_text_field( (string) ( $req['payment_date'] ?? current_time( 'Y-m-d' ) ) );
        $payment_reference = sanitize_text_field( (string) ( $req['payment_reference'] ?? '' ) );

        $ok = Settlement_Service::update_status( $id, 'paid', [
            'payment_date'      => $payment_date,
            'payment_reference' => $payment_reference,
        ] );
        if ( ! $ok ) {
            return Rest_Response::error( 'invalid_transition', __( 'Settlement must be approved before marking paid.', 'sfs-hr' ), 409 );
        }
        return Rest_Response::success( Settlement_Service::get_settlement( $id ) );
    }
}
