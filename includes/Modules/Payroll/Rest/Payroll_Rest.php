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
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payslip_view' ) || is_user_logged_in(),
        ] );

        // Get salary components
        register_rest_route( $ns, '/payroll/components', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_components' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_payroll_admin' ) || current_user_can( 'sfs_hr.manage' ),
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
            "SELECT i.*, e.first_name, e.last_name, e.emp_number, e.employee_code
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
}
