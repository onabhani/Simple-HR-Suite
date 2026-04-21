<?php
namespace SFS\HR\Modules\Expenses\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Rest\Rest_Response;
use SFS\HR\Modules\Expenses\Services\Expense_Service;
use SFS\HR\Modules\Expenses\Services\Expense_Category_Service;
use SFS\HR\Modules\Expenses\Services\Advance_Service;
use SFS\HR\Modules\Expenses\Services\Expense_Report_Service;

/**
 * Expenses_Rest
 *
 * M10 — REST endpoints for expense claims, categories, advances, reports.
 *
 * Base: /sfs-hr/v1/expenses
 *
 * Categories:
 *   GET    /categories                      — list active categories
 *   GET    /categories/all                  — list all (admin)
 *   POST   /categories                      — upsert (admin)
 *   POST   /categories/{id}/toggle          — set active (admin)
 *
 * Claims:
 *   GET    /claims                          — my claims (employee) OR all (admin with status filter)
 *   POST   /claims                          — create draft
 *   GET    /claims/{id}                     — detail with line items
 *   POST   /claims/{id}/submit              — employee submits for approval
 *   POST   /claims/{id}/cancel              — cancel before approval
 *   POST   /claims/{id}/decide-manager      — manager approve/reject
 *   POST   /claims/{id}/decide-finance      — finance approve/reject
 *   POST   /claims/{id}/pay                 — mark paid (finance)
 *   GET    /claims/pending                  — pending claims for the current approver
 *
 * Advances:
 *   GET    /advances                        — my advances OR pending list (admin)
 *   POST   /advances                        — request
 *   GET    /advances/{id}
 *   POST   /advances/{id}/approve
 *   POST   /advances/{id}/reject
 *   POST   /advances/{id}/cancel
 *   POST   /advances/{id}/pay               — finance marks paid (initializes outstanding)
 *
 * Reports:
 *   GET    /reports/by-department
 *   GET    /reports/by-employee
 *   GET    /reports/violations
 *   GET    /reports/by-employee.csv         — CSV download (streamed via REST)
 *
 * @since M10
 */
class Expenses_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        $ns  = 'sfs-hr/v1';
        $base = '/expenses';

        // Categories
        register_rest_route( $ns, $base . '/categories', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_categories_active' ], 'permission_callback' => [ __CLASS__, 'can_view' ] ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'upsert_category' ],         'permission_callback' => [ __CLASS__, 'can_manage' ] ],
        ] );
        register_rest_route( $ns, $base . '/categories/all', [
            'methods' => 'GET', 'callback' => [ __CLASS__, 'list_categories_all' ], 'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );
        register_rest_route( $ns, $base . '/categories/(?P<id>\d+)/toggle', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'toggle_category' ], 'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        // Claims
        register_rest_route( $ns, $base . '/claims', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_claims' ],  'permission_callback' => [ __CLASS__, 'can_view_own_or_team' ] ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_claim' ], 'permission_callback' => [ __CLASS__, 'can_submit' ] ],
        ] );
        register_rest_route( $ns, $base . '/claims/pending', [
            'methods' => 'GET', 'callback' => [ __CLASS__, 'list_pending_claims' ], 'permission_callback' => [ __CLASS__, 'can_review' ],
        ] );
        register_rest_route( $ns, $base . '/claims/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [ __CLASS__, 'get_claim' ], 'permission_callback' => [ __CLASS__, 'can_view_claim' ],
        ] );
        register_rest_route( $ns, $base . '/claims/(?P<id>\d+)/submit', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'submit_claim' ], 'permission_callback' => [ __CLASS__, 'can_modify_own_claim' ],
        ] );
        register_rest_route( $ns, $base . '/claims/(?P<id>\d+)/cancel', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'cancel_claim' ], 'permission_callback' => [ __CLASS__, 'can_modify_own_claim' ],
        ] );
        register_rest_route( $ns, $base . '/claims/(?P<id>\d+)/decide-manager', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'manager_decide' ], 'permission_callback' => [ __CLASS__, 'can_review' ],
        ] );
        register_rest_route( $ns, $base . '/claims/(?P<id>\d+)/decide-finance', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'finance_decide' ], 'permission_callback' => [ __CLASS__, 'can_finance' ],
        ] );
        register_rest_route( $ns, $base . '/claims/(?P<id>\d+)/pay', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'pay_claim' ], 'permission_callback' => [ __CLASS__, 'can_finance' ],
        ] );

        // Advances
        register_rest_route( $ns, $base . '/advances', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_advances' ],   'permission_callback' => [ __CLASS__, 'can_view_own_or_team' ] ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'request_advance' ], 'permission_callback' => [ __CLASS__, 'can_submit' ] ],
        ] );
        register_rest_route( $ns, $base . '/advances/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [ __CLASS__, 'get_advance' ], 'permission_callback' => [ __CLASS__, 'can_view_advance' ],
        ] );
        register_rest_route( $ns, $base . '/advances/(?P<id>\d+)/approve', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'approve_advance' ], 'permission_callback' => [ __CLASS__, 'can_review' ],
        ] );
        register_rest_route( $ns, $base . '/advances/(?P<id>\d+)/reject', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'reject_advance' ], 'permission_callback' => [ __CLASS__, 'can_review' ],
        ] );
        register_rest_route( $ns, $base . '/advances/(?P<id>\d+)/cancel', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'cancel_advance' ], 'permission_callback' => [ __CLASS__, 'can_modify_own_advance' ],
        ] );
        register_rest_route( $ns, $base . '/advances/(?P<id>\d+)/pay', [
            'methods' => 'POST', 'callback' => [ __CLASS__, 'pay_advance' ], 'permission_callback' => [ __CLASS__, 'can_finance' ],
        ] );

        // Reports
        foreach ( [ 'by-department', 'by-employee', 'violations' ] as $endpoint ) {
            register_rest_route( $ns, $base . '/reports/' . $endpoint, [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'report_' . str_replace( '-', '_', $endpoint ) ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
                'args' => [
                    'start_date' => [ 'required' => true, 'type' => 'string', 'validate_callback' => [ __CLASS__, 'is_date' ] ],
                    'end_date'   => [ 'required' => true, 'type' => 'string', 'validate_callback' => [ __CLASS__, 'is_date' ] ],
                    'dept_id'    => [ 'required' => false, 'type' => 'integer' ],
                ],
            ] );
        }
        register_rest_route( $ns, $base . '/reports/by-employee.csv', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'report_by_employee_csv' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
            'args' => [
                'start_date' => [ 'required' => true, 'type' => 'string', 'validate_callback' => [ __CLASS__, 'is_date' ] ],
                'end_date'   => [ 'required' => true, 'type' => 'string', 'validate_callback' => [ __CLASS__, 'is_date' ] ],
                'dept_id'    => [ 'required' => false, 'type' => 'integer' ],
            ],
        ] );
    }

    // ── Permission callbacks ────────────────────────────────────────────────

    public static function can_view(): bool        { return is_user_logged_in(); }
    public static function can_submit(): bool      { return is_user_logged_in(); }
    public static function can_manage(): bool      { return current_user_can( 'sfs_hr.manage' ); }
    public static function can_review(): bool      {
        return current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr.leave.review' );
    }
    public static function can_finance(): bool {
        return current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr_resignation_finance_approve' );
    }

    public static function can_view_own_or_team(): bool {
        return is_user_logged_in();
    }

    public static function can_view_claim( \WP_REST_Request $req ): bool {
        if ( self::can_manage() || self::can_review() ) {
            return true;
        }
        $claim = Expense_Service::get( (int) $req['id'] );
        if ( ! $claim ) return false;
        $emp_uid = self::employee_user_id( (int) $claim['employee_id'] );
        return $emp_uid && $emp_uid === get_current_user_id();
    }

    public static function can_view_advance( \WP_REST_Request $req ): bool {
        if ( self::can_manage() || self::can_review() ) {
            return true;
        }
        $adv = Advance_Service::get( (int) $req['id'] );
        if ( ! $adv ) return false;
        $emp_uid = self::employee_user_id( (int) $adv['employee_id'] );
        return $emp_uid && $emp_uid === get_current_user_id();
    }

    public static function can_modify_own_claim( \WP_REST_Request $req ): bool {
        if ( self::can_manage() ) return true;
        $claim = Expense_Service::get( (int) $req['id'] );
        if ( ! $claim ) return false;
        $emp_uid = self::employee_user_id( (int) $claim['employee_id'] );
        return $emp_uid && $emp_uid === get_current_user_id();
    }

    public static function can_modify_own_advance( \WP_REST_Request $req ): bool {
        if ( self::can_manage() ) return true;
        $adv = Advance_Service::get( (int) $req['id'] );
        if ( ! $adv ) return false;
        $emp_uid = self::employee_user_id( (int) $adv['employee_id'] );
        return $emp_uid && $emp_uid === get_current_user_id();
    }

    public static function is_date( $value ): bool {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value );
    }

    // ── Handlers: categories ────────────────────────────────────────────────

    public static function list_categories_active(): \WP_REST_Response {
        return Rest_Response::success( Expense_Category_Service::list_active() );
    }

    public static function list_categories_all(): \WP_REST_Response {
        return Rest_Response::success( Expense_Category_Service::list_all() );
    }

    public static function upsert_category( \WP_REST_Request $req ) {
        $result = Expense_Category_Service::upsert( [
            'code'             => sanitize_key( (string) ( $req['code'] ?? '' ) ),
            'name'             => sanitize_text_field( (string) ( $req['name'] ?? '' ) ),
            'name_ar'          => sanitize_text_field( (string) ( $req['name_ar'] ?? '' ) ),
            'description'      => sanitize_textarea_field( (string) ( $req['description'] ?? '' ) ),
            'receipt_required' => ! empty( $req['receipt_required'] ),
            'monthly_limit'    => isset( $req['monthly_limit'] )   ? $req['monthly_limit']   : '',
            'per_claim_limit'  => isset( $req['per_claim_limit'] ) ? $req['per_claim_limit'] : '',
            'is_active'        => ! isset( $req['is_active'] ) || (int) $req['is_active'] === 1,
            'sort_order'       => isset( $req['sort_order'] ) ? (int) $req['sort_order'] : 0,
        ] );
        if ( ! ( $result['success'] ?? false ) ) {
            return Rest_Response::error( 'invalid', $result['error'] ?? 'invalid', 400 );
        }
        return Rest_Response::success( $result );
    }

    public static function toggle_category( \WP_REST_Request $req ) {
        $id     = (int) $req['id'];
        $active = (bool) ( $req['is_active'] ?? true );
        if ( ! Expense_Category_Service::set_active( $id, $active ) ) {
            return Rest_Response::error( 'toggle_failed', __( 'Failed to toggle.', 'sfs-hr' ), 500 );
        }
        return Rest_Response::success( [ 'id' => $id, 'is_active' => $active ] );
    }

    // ── Handlers: claims ────────────────────────────────────────────────────

    public static function list_claims( \WP_REST_Request $req ): \WP_REST_Response {
        // Admins can filter by status across the org; employees see their own.
        if ( self::can_manage() || self::can_review() ) {
            $status = sanitize_text_field( (string) ( $req->get_param( 'status' ) ?? 'pending_manager' ) );
            return Rest_Response::success( Expense_Service::list_pending_for_approver( get_current_user_id(), $status ) );
        }
        $emp_id = self::current_employee_id();
        if ( ! $emp_id ) {
            return Rest_Response::success( [] );
        }
        return Rest_Response::success( Expense_Service::list_for_employee( $emp_id, 100 ) );
    }

    public static function list_pending_claims( \WP_REST_Request $req ): \WP_REST_Response {
        $status = sanitize_text_field( (string) ( $req->get_param( 'status' ) ?? 'pending_manager' ) );
        return Rest_Response::success( Expense_Service::list_pending_for_approver( get_current_user_id(), $status ) );
    }

    public static function get_claim( \WP_REST_Request $req ) {
        $id    = (int) $req['id'];
        $claim = Expense_Service::get( $id );
        if ( ! $claim ) {
            return Rest_Response::error( 'not_found', __( 'Claim not found.', 'sfs-hr' ), 404 );
        }
        $claim['items'] = Expense_Service::get_items( $id );
        return Rest_Response::success( $claim );
    }

    public static function create_claim( \WP_REST_Request $req ) {
        $emp_id = self::current_employee_id();
        if ( ! $emp_id ) {
            return Rest_Response::error( 'no_employee', __( 'No HR profile linked to your account.', 'sfs-hr' ), 403 );
        }

        $result = Expense_Service::create_draft( [
            'employee_id' => $emp_id,
            'advance_id'  => isset( $req['advance_id'] ) ? (int) $req['advance_id'] : null,
            'title'       => (string) ( $req['title'] ?? '' ),
            'description' => (string) ( $req['description'] ?? '' ),
            'currency'    => (string) ( $req['currency'] ?? '' ),
            'items'       => (array)  ( $req['items'] ?? [] ),
        ] );
        if ( ! ( $result['success'] ?? false ) ) {
            return Rest_Response::error( 'invalid', $result['error'] ?? 'invalid', 400 );
        }

        // Optionally auto-submit.
        if ( ! empty( $req['submit'] ) ) {
            Expense_Service::submit( (int) $result['id'] );
        }

        return new \WP_REST_Response( [ 'data' => $result, 'meta' => [ 'timestamp' => gmdate( 'c' ) ] ], 201 );
    }

    public static function submit_claim( \WP_REST_Request $req ) {
        $result = Expense_Service::submit( (int) $req['id'] );
        return self::result_to_response( $result );
    }

    public static function cancel_claim( \WP_REST_Request $req ) {
        $result = Expense_Service::cancel( (int) $req['id'] );
        return self::result_to_response( $result );
    }

    public static function manager_decide( \WP_REST_Request $req ) {
        $decision = (string) ( $req['decision'] ?? 'approved' );
        $amount   = isset( $req['approved_amount'] ) ? (float) $req['approved_amount'] : null;
        $note     = (string) ( $req['note'] ?? '' );
        $result   = Expense_Service::manager_decide( (int) $req['id'], $decision, get_current_user_id(), $note, $amount );
        return self::result_to_response( $result );
    }

    public static function finance_decide( \WP_REST_Request $req ) {
        $decision = (string) ( $req['decision'] ?? 'approved' );
        $amount   = isset( $req['approved_amount'] ) ? (float) $req['approved_amount'] : null;
        $note     = (string) ( $req['note'] ?? '' );
        $result   = Expense_Service::finance_decide( (int) $req['id'], $decision, get_current_user_id(), $note, $amount );
        return self::result_to_response( $result );
    }

    public static function pay_claim( \WP_REST_Request $req ) {
        $ref    = (string) ( $req['payment_reference'] ?? '' );
        $result = Expense_Service::mark_paid( (int) $req['id'], $ref, get_current_user_id() );
        return self::result_to_response( $result );
    }

    // ── Handlers: advances ──────────────────────────────────────────────────

    public static function list_advances( \WP_REST_Request $req ): \WP_REST_Response {
        if ( self::can_manage() || self::can_review() ) {
            return Rest_Response::success( Advance_Service::list_pending() );
        }
        $emp_id = self::current_employee_id();
        return Rest_Response::success( $emp_id ? Advance_Service::list_for_employee( $emp_id ) : [] );
    }

    public static function request_advance( \WP_REST_Request $req ) {
        $emp_id = self::current_employee_id();
        if ( ! $emp_id ) {
            return Rest_Response::error( 'no_employee', __( 'No HR profile linked.', 'sfs-hr' ), 403 );
        }
        $result = Advance_Service::request( [
            'employee_id' => $emp_id,
            'amount'      => (float) ( $req['amount'] ?? 0 ),
            'purpose'     => (string) ( $req['purpose'] ?? '' ),
            'notes'       => (string) ( $req['notes'] ?? '' ),
            'currency'    => (string) ( $req['currency'] ?? '' ),
        ] );
        if ( ! ( $result['success'] ?? false ) ) {
            return Rest_Response::error( 'invalid', $result['error'] ?? 'invalid', 400 );
        }
        return new \WP_REST_Response( [ 'data' => $result, 'meta' => [ 'timestamp' => gmdate( 'c' ) ] ], 201 );
    }

    public static function get_advance( \WP_REST_Request $req ) {
        $adv = Advance_Service::get( (int) $req['id'] );
        if ( ! $adv ) {
            return Rest_Response::error( 'not_found', __( 'Advance not found.', 'sfs-hr' ), 404 );
        }
        return Rest_Response::success( $adv );
    }

    public static function approve_advance( \WP_REST_Request $req ) {
        $result = Advance_Service::approve( (int) $req['id'], get_current_user_id(), (string) ( $req['note'] ?? '' ) );
        return self::result_to_response( $result );
    }

    public static function reject_advance( \WP_REST_Request $req ) {
        $result = Advance_Service::reject( (int) $req['id'], get_current_user_id(), (string) ( $req['reason'] ?? '' ) );
        return self::result_to_response( $result );
    }

    public static function cancel_advance( \WP_REST_Request $req ) {
        $result = Advance_Service::cancel( (int) $req['id'] );
        return self::result_to_response( $result );
    }

    public static function pay_advance( \WP_REST_Request $req ) {
        $result = Advance_Service::mark_paid( (int) $req['id'], get_current_user_id(), (string) ( $req['payment_reference'] ?? '' ) );
        return self::result_to_response( $result );
    }

    // ── Handlers: reports ───────────────────────────────────────────────────

    public static function report_by_department( \WP_REST_Request $req ): \WP_REST_Response {
        return Rest_Response::success( Expense_Report_Service::by_department(
            (string) $req['start_date'],
            (string) $req['end_date'],
            $req->has_param( 'dept_id' ) ? (int) $req['dept_id'] : null
        ) );
    }

    public static function report_by_employee( \WP_REST_Request $req ): \WP_REST_Response {
        return Rest_Response::success( Expense_Report_Service::by_employee(
            (string) $req['start_date'],
            (string) $req['end_date'],
            $req->has_param( 'dept_id' ) ? (int) $req['dept_id'] : null
        ) );
    }

    public static function report_violations( \WP_REST_Request $req ): \WP_REST_Response {
        return Rest_Response::success( Expense_Report_Service::policy_violations(
            (string) $req['start_date'],
            (string) $req['end_date']
        ) );
    }

    /**
     * CSV streaming endpoint — returns raw text/csv, not a JSON-encoded envelope.
     *
     * WP_REST_Response bodies are json_encode()'d by the REST server regardless
     * of Content-Type headers. To serve binary/text payloads we short-circuit
     * the serialization via the rest_pre_serve_request filter.
     */
    public static function report_by_employee_csv( \WP_REST_Request $req ) {
        $csv = Expense_Report_Service::export_by_employee_csv(
            (string) $req['start_date'],
            (string) $req['end_date'],
            $req->has_param( 'dept_id' ) ? (int) $req['dept_id'] : null
        );
        $filename = sprintf( 'expenses-by-employee-%s-to-%s.csv', (string) $req['start_date'], (string) $req['end_date'] );

        add_filter(
            'rest_pre_serve_request',
            static function ( $served ) use ( $csv, $filename ) {
                if ( headers_sent() ) {
                    return $served;
                }
                header( 'Content-Type: text/csv; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
                header( 'X-Content-Type-Options: nosniff' );
                echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return true;
            },
            10,
            1
        );

        return new \WP_REST_Response( null, 200 );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private static function current_employee_id(): ?int {
        global $wpdb;
        $uid = get_current_user_id();
        if ( ! $uid ) return null;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d LIMIT 1",
            $uid
        ) );
        return $id ? (int) $id : null;
    }

    private static function employee_user_id( int $employee_id ): ?int {
        global $wpdb;
        $uid = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d LIMIT 1",
            $employee_id
        ) );
        return $uid ? (int) $uid : null;
    }

    private static function result_to_response( array $result ) {
        if ( ! ( $result['success'] ?? false ) ) {
            return Rest_Response::error( 'failed', (string) ( $result['error'] ?? 'failed' ), 400 );
        }
        return Rest_Response::success( $result );
    }
}
