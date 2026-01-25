<?php
namespace SFS\HR\Modules\Settlement\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Settlement Service
 * Business logic for end of service settlements
 */
class Settlement_Service {

    /**
     * Get status labels
     */
    public static function get_status_labels(): array {
        return [
            'pending'  => __('Pending', 'sfs-hr'),
            'approved' => __('Approved', 'sfs-hr'),
            'rejected' => __('Rejected', 'sfs-hr'),
            'paid'     => __('Paid', 'sfs-hr'),
        ];
    }

    /**
     * Get status colors for badges
     */
    public static function get_status_colors(): array {
        return [
            'pending'  => '#f0ad4e',
            'approved' => '#5cb85c',
            'rejected' => '#d9534f',
            'paid'     => '#0073aa',
            'cleared'  => '#5cb85c',
        ];
    }

    /**
     * Get settlement by ID
     */
    public static function get_settlement(int $settlement_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, e.employee_code, e.first_name, e.last_name, e.position, e.dept_id
             FROM $table s
             JOIN $emp_t e ON e.id = s.employee_id
             WHERE s.id = %d",
            $settlement_id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Get settlements list with pagination
     */
    public static function get_settlements(string $status = 'pending', int $page = 1, int $per_page = 20): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $offset = ($page - 1) * $per_page;
        $where = '1=1';
        $params = [];

        if (in_array($status, ['pending', 'approved', 'rejected', 'paid'], true)) {
            $where .= " AND s.status = %s";
            $params[] = $status;
        }

        // Get total count
        $sql_total = "SELECT COUNT(*) FROM $table s JOIN $emp_t e ON e.id = s.employee_id WHERE $where";
        $total = $params ? (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params))
                        : (int)$wpdb->get_var($sql_total);

        // Get rows
        $sql = "SELECT s.*, e.employee_code, e.first_name, e.last_name
                FROM $table s
                JOIN $emp_t e ON e.id = s.employee_id
                WHERE $where
                ORDER BY s.id DESC
                LIMIT %d OFFSET %d";

        $params_all = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_all), ARRAY_A);

        return [
            'rows'        => $rows,
            'total'       => $total,
            'total_pages' => ceil($total / $per_page),
            'page'        => $page,
            'per_page'    => $per_page,
        ];
    }

    /**
     * Get approved resignations pending settlement
     */
    public static function get_pending_resignations(): array {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';
        $settle_table = $wpdb->prefix . 'sfs_hr_settlements';

        $sql = "SELECT r.*, e.employee_code, e.first_name, e.last_name, e.base_salary, e.hire_date, e.hired_at
                FROM $resign_table r
                JOIN $emp_table e ON e.id = r.employee_id
                WHERE r.status = 'approved'
                AND r.id NOT IN (SELECT resignation_id FROM $settle_table WHERE resignation_id IS NOT NULL)
                ORDER BY r.last_working_day DESC";

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Create settlement
     */
    public static function create_settlement(array $data): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';
        $now = current_time('mysql');

        // Generate reference number
        $request_number = self::generate_settlement_request_number();

        $wpdb->insert($table, [
            'employee_id'       => $data['employee_id'],
            'resignation_id'    => $data['resignation_id'],
            'settlement_date'   => current_time('Y-m-d'),
            'last_working_day'  => $data['last_working_day'],
            'years_of_service'  => $data['years_of_service'],
            'basic_salary'      => $data['basic_salary'],
            'gratuity_amount'   => $data['gratuity_amount'],
            'leave_encashment'  => $data['leave_encashment'],
            'unused_leave_days' => $data['unused_leave_days'],
            'final_salary'      => $data['final_salary'],
            'other_allowances'  => $data['other_allowances'],
            'deductions'        => $data['deductions'],
            'deduction_notes'   => $data['deduction_notes'],
            'total_settlement'  => $data['total_settlement'],
            'request_number'    => $request_number,
            'status'            => 'pending',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return $wpdb->insert_id;
    }

    /**
     * Generate reference number for settlement requests
     * Format: ST-YYYY-NNNN (e.g., ST-2026-0001)
     */
    public static function generate_settlement_request_number(): string {
        global $wpdb;
        return \SFS\HR\Core\Helpers::generate_reference_number( 'ST', $wpdb->prefix . 'sfs_hr_settlements' );
    }

    /**
     * Update settlement status
     */
    public static function update_status(int $settlement_id, string $status, array $extra = []): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';

        // Get old status for audit trail
        $old_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $settlement_id ) );

        $data = array_merge([
            'status'     => $status,
            'updated_at' => current_time('mysql'),
        ], $extra);

        $result = $wpdb->update($table, $data, ['id' => $settlement_id]) !== false;

        // Fire hook for AuditTrail
        if ( $result && $old_status !== $status ) {
            do_action( 'sfs_hr_settlement_status_changed', $settlement_id, $old_status ?: 'pending', $status );
        }

        return $result;
    }

    /**
     * Check if employee has outstanding loans
     */
    public static function check_loan_clearance(int $employee_id): array {
        $has_loans = false;
        $outstanding = 0;
        $loan_summary = null;

        if (class_exists('\SFS\HR\Modules\Loans\LoansModule')) {
            $has_loans = \SFS\HR\Modules\Loans\LoansModule::has_active_loans($employee_id);
            $outstanding = \SFS\HR\Modules\Loans\LoansModule::get_outstanding_balance($employee_id);

            if (class_exists('\SFS\HR\Modules\Loans\Admin\DashboardWidget')) {
                $loan_summary = \SFS\HR\Modules\Loans\Admin\DashboardWidget::get_employee_loan_summary($employee_id);
            }
        }

        return [
            'has_loans'   => $has_loans,
            'outstanding' => $outstanding,
            'summary'     => $loan_summary,
            'cleared'     => !$has_loans || $outstanding <= 0,
        ];
    }

    /**
     * Check if employee has unreturned assets
     */
    public static function check_asset_clearance(int $employee_id): array {
        $has_unreturned = false;
        $unreturned_count = 0;
        $unreturned_assets = [];

        if (class_exists('\SFS\HR\Modules\Assets\AssetsModule')) {
            $has_unreturned = \SFS\HR\Modules\Assets\AssetsModule::has_unreturned_assets($employee_id);
            $unreturned_count = \SFS\HR\Modules\Assets\AssetsModule::get_unreturned_assets_count($employee_id);
            $unreturned_assets = \SFS\HR\Modules\Assets\AssetsModule::get_unreturned_assets($employee_id);
        }

        return [
            'has_unreturned'   => $has_unreturned,
            'unreturned_count' => $unreturned_count,
            'assets'           => $unreturned_assets,
            'cleared'          => !$has_unreturned,
        ];
    }

    /**
     * Calculate gratuity amount
     * Based on Saudi Labor Law:
     * - 21 days salary per year for first 5 years
     * - 30 days salary per year beyond 5 years
     */
    public static function calculate_gratuity(float $basic_salary, float $years_of_service): float {
        if ($years_of_service <= 0 || $basic_salary <= 0) {
            return 0;
        }

        $daily_rate = $basic_salary / 30;

        if ($years_of_service <= 5) {
            return $daily_rate * 21 * $years_of_service;
        }

        $first_5_years = $daily_rate * 21 * 5;
        $remaining_years = $years_of_service - 5;
        $after_5_years = $daily_rate * 30 * $remaining_years;

        return $first_5_years + $after_5_years;
    }

    /**
     * Calculate leave encashment
     */
    public static function calculate_leave_encashment(float $basic_salary, int $unused_days): float {
        if ($unused_days <= 0 || $basic_salary <= 0) {
            return 0;
        }

        $daily_rate = $basic_salary / 30;
        return $daily_rate * $unused_days;
    }

    /**
     * Calculate total settlement
     */
    public static function calculate_total(array $components): float {
        $gratuity = floatval($components['gratuity'] ?? 0);
        $leave_encashment = floatval($components['leave_encashment'] ?? 0);
        $final_salary = floatval($components['final_salary'] ?? 0);
        $other_allowances = floatval($components['other_allowances'] ?? 0);
        $deductions = floatval($components['deductions'] ?? 0);

        return $gratuity + $leave_encashment + $final_salary + $other_allowances - $deductions;
    }

    /**
     * Render status badge HTML
     */
    public static function status_badge(string $status): string {
        $colors = self::get_status_colors();
        $color = $colors[$status] ?? '#777';

        return sprintf(
            '<span style="background:%s;color:#fff;padding:4px 8px;border-radius:3px;font-size:11px;">%s</span>',
            esc_attr($color),
            esc_html(ucfirst($status))
        );
    }
}
