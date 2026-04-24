<?php
/**
 * Payroll REST API
 *
 * @package SFS\HR\Modules\Payroll\Rest
 */

namespace SFS\HR\Modules\Payroll\Rest;

use SFS\HR\Modules\Payroll\PayrollModule;

defined( 'ABSPATH' ) || exit;

class Payroll_Rest {

    public static function register_routes(): void {
        $ns = 'sfs-hr/v1';

        // Get payroll periods
        register_rest_route( $ns, '/payroll/periods', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_periods' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_view' ) || current_user_can( 'sfs_hr.manage' ),
        ] );

        // Get payroll runs
        register_rest_route( $ns, '/payroll/runs', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_runs' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_view' ) || current_user_can( 'sfs_hr.manage' ),
        ] );

        // Get run details
        register_rest_route( $ns, '/payroll/runs/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_run_detail' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_view' ) || current_user_can( 'sfs_hr.manage' ),
        ] );

        // Calculate preview for employee
        register_rest_route( $ns, '/payroll/calculate-preview', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'calculate_preview' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_run' ) || current_user_can( 'sfs_hr.manage' ),
        ] );

        // Get employee payslips (self-service)
        register_rest_route( $ns, '/payroll/my-payslips', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'my_payslips' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payslip_view' ) || current_user_can( 'sfs_hr.view' ),
        ] );

        // Get salary components
        register_rest_route( $ns, '/payroll/components', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_components' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_admin' ) || current_user_can( 'sfs_hr.manage' ),
        ] );

        // M1.4 — Payslip enhancements
        register_rest_route( $ns, '/payroll/payslips/(?P<id>\d+)/html', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_payslip_html' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_admin' ) || current_user_can( 'sfs_hr.manage' ),
        ] );

        register_rest_route( $ns, '/payroll/payslips/(?P<id>\d+)/send', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'send_payslip_email' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_admin' ) || current_user_can( 'sfs_hr.manage' ),
        ] );

        register_rest_route( $ns, '/payroll/runs/(?P<id>\d+)/send-payslips', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'batch_send_payslips' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_admin' ) || current_user_can( 'sfs_hr.manage' ),
        ] );

        register_rest_route( $ns, '/payroll/employees/(?P<id>\d+)/ytd', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_employee_ytd' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_view' ) || current_user_can( 'sfs_hr.manage' ),
        ] );

        register_rest_route( $ns, '/payroll/employees/(?P<id>\d+)/comparison', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_employee_comparison' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_view' ) || current_user_can( 'sfs_hr.manage' ),
        ] );

        register_rest_route( $ns, '/payroll/my-ytd', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'my_ytd' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payslip_view' ) || current_user_can( 'sfs_hr.view' ),
        ] );

        register_rest_route( $ns, '/payroll/my-comparison', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'my_comparison' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payslip_view' ) || current_user_can( 'sfs_hr.view' ),
        ] );
    }

    /**
     * Get payroll periods
     */
    public static function get_periods( \WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $status = sanitize_key( $req['status'] ?? '' );
        $limit = min( 50, max( 1, (int) ( $req['limit'] ?? 20 ) ) );

        $where = '';
        $args = [];

        if ( $status && in_array( $status, [ 'upcoming', 'open', 'processing', 'closed', 'paid' ], true ) ) {
            $where = 'WHERE status = %s';
            $args[] = $status;
        }

        $args[] = $limit;

        $sql = "SELECT * FROM {$table} {$where} ORDER BY start_date DESC LIMIT %d";
        $periods = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

        return rest_ensure_response( $periods ?: [] );
    }

    /**
     * Get payroll runs
     */
    public static function get_runs( \WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;

        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $period_id = (int) ( $req['period_id'] ?? 0 );
        $limit = min( 50, max( 1, (int) ( $req['limit'] ?? 20 ) ) );

        $where = '';
        $args = [];

        if ( $period_id ) {
            $where = 'WHERE r.period_id = %d';
            $args[] = $period_id;
        }

        $args[] = $limit;

        $sql = "SELECT r.*, p.name as period_name, p.start_date, p.end_date
                FROM {$runs_table} r
                LEFT JOIN {$periods_table} p ON p.id = r.period_id
                {$where}
                ORDER BY r.created_at DESC
                LIMIT %d";

        $runs = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

        return rest_ensure_response( $runs ?: [] );
    }

    /**
     * Get run details with items
     */
    public static function get_run_detail( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $run_id = (int) $req['id'];
        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $run = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, p.name as period_name, p.start_date, p.end_date, p.pay_date
             FROM {$runs_table} r
             LEFT JOIN {$periods_table} p ON p.id = r.period_id
             WHERE r.id = %d",
            $run_id
        ), ARRAY_A );

        if ( ! $run ) {
            return new \WP_Error( 'not_found', __( 'Run not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, e.first_name, e.last_name, e.employee_code
             FROM {$items_table} i
             LEFT JOIN {$emp_table} e ON e.id = i.employee_id
             WHERE i.run_id = %d
             ORDER BY e.first_name ASC",
            $run_id
        ), ARRAY_A );

        $run['items'] = $items ?: [];

        return rest_ensure_response( $run );
    }

    /**
     * Calculate payroll preview for an employee
     */
    public static function calculate_preview( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $employee_id = (int) ( $req['employee_id'] ?? 0 );
        $period_id = (int) ( $req['period_id'] ?? 0 );

        if ( ! $employee_id || ! $period_id ) {
            return new \WP_Error( 'missing_params', __( 'Employee ID and Period ID are required.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        $result = PayrollModule::calculate_employee_payroll( $employee_id, $period_id );

        if ( isset( $result['error'] ) ) {
            return new \WP_Error( 'calculation_error', $result['error'], [ 'status' => 400 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Get current user's payslips
     */
    public static function my_payslips( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $user_id = get_current_user_id();
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $payslips_table = $wpdb->prefix . 'sfs_hr_payslips';
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';

        // Get employee ID for current user
        $employee = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$emp_table} WHERE user_id = %d LIMIT 1",
            $user_id
        ) );

        if ( ! $employee ) {
            return new \WP_Error( 'not_employee', __( 'You are not linked to an employee record.', 'sfs-hr' ), [ 'status' => 403 ] );
        }

        $limit = min( 24, max( 1, (int) ( $req['limit'] ?? 12 ) ) );

        $payslips = $wpdb->get_results( $wpdb->prepare(
            "SELECT ps.*, p.name as period_name, p.start_date, p.end_date, p.pay_date,
                    i.base_salary, i.gross_salary, i.total_deductions, i.net_salary,
                    i.components_json
             FROM {$payslips_table} ps
             LEFT JOIN {$periods_table} p ON p.id = ps.period_id
             LEFT JOIN {$items_table} i ON i.id = ps.payroll_item_id
             WHERE ps.employee_id = %d
             ORDER BY p.start_date DESC
             LIMIT %d",
            $employee->id,
            $limit
        ), ARRAY_A );

        // Decode components JSON
        foreach ( $payslips as &$ps ) {
            if ( ! empty( $ps['components_json'] ) ) {
                $ps['components'] = json_decode( $ps['components_json'], true );
                unset( $ps['components_json'] );
            }
        }

        return rest_ensure_response( $payslips ?: [] );
    }

    /**
     * Get salary components
     */
    public static function get_components( \WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_salary_components';
        $type = sanitize_key( $req['type'] ?? '' );
        $active_only = (bool) ( $req['active_only'] ?? true );

        $where = [];
        $args = [];

        if ( $type && in_array( $type, [ 'earning', 'deduction', 'benefit' ], true ) ) {
            $where[] = 'type = %s';
            $args[] = $type;
        }

        if ( $active_only ) {
            $where[] = 'is_active = 1';
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY type, display_order ASC";

        if ( ! empty( $args ) ) {
            $sql = $wpdb->prepare( $sql, ...$args );
        }

        $components = $wpdb->get_results( $sql, ARRAY_A );

        return rest_ensure_response( $components ?: [] );
    }

    /* ================================================================== */
    /*  M1.4 — Payslip Enhancements                                       */
    /* ================================================================== */

    /**
     * Get rendered HTML for a payslip.
     */
    public static function get_payslip_html( \WP_REST_Request $req ): \WP_REST_Response {
        $html = Services\Payslip_Service::render_html( (int) $req['id'] );

        if ( ! $html ) {
            return new \WP_REST_Response( [ 'message' => 'Payslip not found.' ], 404 );
        }

        return rest_ensure_response( [ 'html' => $html ] );
    }

    /**
     * Send payslip email to a single employee.
     */
    public static function send_payslip_email( \WP_REST_Request $req ): \WP_REST_Response {
        $sent = Services\Payslip_Service::send_email( (int) $req['id'] );

        if ( ! $sent ) {
            return new \WP_REST_Response( [ 'message' => 'Failed to send payslip email.' ], 400 );
        }

        return rest_ensure_response( [ 'sent' => true ] );
    }

    /**
     * Batch send payslip emails for a payroll run.
     */
    public static function batch_send_payslips( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Services\Payslip_Service::batch_send_by_run( (int) $req['id'] );

        if ( ! empty( $result['error'] ) ) {
            return new \WP_REST_Response( $result, 400 );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Get YTD data for an employee (admin).
     */
    public static function get_employee_ytd( \WP_REST_Request $req ): \WP_REST_Response {
        $year = (int) ( $req['year'] ?? 0 ) ?: null;
        $ytd  = Services\Payslip_Service::get_ytd( (int) $req['id'], $year );

        return rest_ensure_response( $ytd );
    }

    /**
     * Get month-over-month comparison for an employee (admin).
     */
    public static function get_employee_comparison( \WP_REST_Request $req ): \WP_REST_Response {
        $limit = min( 24, max( 2, (int) ( $req['limit'] ?? 6 ) ) );
        $data  = Services\Payslip_Service::get_month_comparison( (int) $req['id'], $limit );

        return rest_ensure_response( $data );
    }

    /**
     * Get YTD data for the current user (self-service).
     */
    public static function my_ytd( \WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;

        $emp_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d LIMIT 1",
            get_current_user_id()
        ) );

        if ( ! $emp_id ) {
            return new \WP_REST_Response( [ 'message' => 'Employee not found.' ], 404 );
        }

        $year = (int) ( $req['year'] ?? 0 ) ?: null;
        $ytd  = Services\Payslip_Service::get_ytd( $emp_id, $year );

        return rest_ensure_response( $ytd );
    }

    /**
     * Get month-over-month comparison for the current user (self-service).
     */
    public static function my_comparison( \WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;

        $emp_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d LIMIT 1",
            get_current_user_id()
        ) );

        if ( ! $emp_id ) {
            return new \WP_REST_Response( [ 'message' => 'Employee not found.' ], 404 );
        }

        $limit = min( 24, max( 2, (int) ( $req['limit'] ?? 6 ) ) );
        $data  = Services\Payslip_Service::get_month_comparison( $emp_id, $limit );

        return rest_ensure_response( $data );
    }
}
