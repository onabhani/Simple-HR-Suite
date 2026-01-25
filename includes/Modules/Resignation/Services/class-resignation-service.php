<?php
namespace SFS\HR\Modules\Resignation\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Resignation Service
 * Business logic for resignation management
 */
class Resignation_Service {

    /**
     * Get status tabs for admin view
     */
    public static function get_status_tabs(): array {
        return [
            'pending'    => __('Pending', 'sfs-hr'),
            'approved'   => __('Approved', 'sfs-hr'),
            'rejected'   => __('Rejected', 'sfs-hr'),
            'cancelled'  => __('Cancelled', 'sfs-hr'),
            'completed'  => __('Completed', 'sfs-hr'),
            'final_exit' => __('Final Exit', 'sfs-hr'),
        ];
    }

    /**
     * Get resignation by ID
     */
    public static function get_resignation(int $resignation_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, e.first_name, e.last_name, e.employee_code, e.dept_id, e.user_id AS emp_user_id
             FROM {$table} r
             LEFT JOIN {$emp_t} e ON e.id = r.employee_id
             WHERE r.id = %d",
            $resignation_id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Get resignations list with filtering and pagination
     */
    public static function get_resignations(string $status, int $page, int $per_page, string $search = '', array $dept_ids = []): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';
        $offset = ($page - 1) * $per_page;

        // Build where clause
        $where = '1=1';
        $params = [];

        // Status filter
        if (in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'completed'], true)) {
            $where .= " AND r.status = %s";
            $params[] = $status;
        } elseif ($status === 'final_exit') {
            $where .= " AND r.resignation_type = %s";
            $params[] = 'final_exit';
        }

        // Department filter
        if (!empty($dept_ids)) {
            $placeholders = implode(',', array_fill(0, count($dept_ids), '%d'));
            $where .= " AND e.dept_id IN ($placeholders)";
            $params = array_merge($params, array_map('intval', $dept_ids));
        }

        // Search filter
        if ($search !== '') {
            $where .= " AND (e.first_name LIKE %s OR e.last_name LIKE %s OR e.employee_code LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }

        // Get total
        $sql_total = "SELECT COUNT(*) FROM $table r JOIN $emp_t e ON e.id = r.employee_id WHERE $where";
        $total = empty($params) ? (int)$wpdb->get_var($sql_total) : (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params));

        // Get rows
        $sql = "SELECT r.*, e.employee_code, e.first_name, e.last_name, e.user_id AS emp_user_id, e.dept_id
                FROM $table r
                JOIN $emp_t e ON e.id = r.employee_id
                WHERE $where
                ORDER BY r.id DESC
                LIMIT %d OFFSET %d";

        $params_all = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_all), ARRAY_A);

        return [
            'rows'        => $rows,
            'total'       => $total,
            'total_pages' => max(1, (int)ceil($total / $per_page)),
            'page'        => $page,
        ];
    }

    /**
     * Get counts for each status tab
     */
    public static function get_status_counts(string $search = '', array $dept_ids = []): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $dept_where = '';
        $dept_params = [];
        if (!empty($dept_ids)) {
            $placeholders = implode(',', array_fill(0, count($dept_ids), '%d'));
            $dept_where = " AND e.dept_id IN ($placeholders)";
            $dept_params = array_map('intval', $dept_ids);
        }

        $search_where = '';
        $search_params = [];
        if ($search !== '') {
            $search_where = " AND (e.first_name LIKE %s OR e.last_name LIKE %s OR e.employee_code LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $search_params = [$like, $like, $like];
        }

        $counts = [];
        foreach (array_keys(self::get_status_tabs()) as $st) {
            if ($st === 'final_exit') {
                $count_sql = "SELECT COUNT(*) FROM $table r JOIN $emp_t e ON e.id = r.employee_id WHERE r.resignation_type = 'final_exit' $dept_where $search_where";
                $count_params = array_merge($dept_params, $search_params);
            } else {
                $count_sql = "SELECT COUNT(*) FROM $table r JOIN $emp_t e ON e.id = r.employee_id WHERE r.status = %s $dept_where $search_where";
                $count_params = array_merge([$st], $dept_params, $search_params);
            }
            $counts[$st] = empty($count_params) ? (int)$wpdb->get_var($count_sql) : (int)$wpdb->get_var($wpdb->prepare($count_sql, ...$count_params));
        }

        return $counts;
    }

    /**
     * Create resignation
     */
    public static function create_resignation(array $data): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $now = current_time('mysql');

        // Generate reference number
        $request_number = self::generate_resignation_request_number();

        $wpdb->insert($table, [
            'employee_id'        => $data['employee_id'],
            'resignation_date'   => $data['resignation_date'],
            'resignation_type'   => $data['resignation_type'],
            'notice_period_days' => $data['notice_period_days'],
            'last_working_day'   => $data['last_working_day'],
            'reason'             => $data['reason'],
            'status'             => 'pending',
            'request_number'     => $request_number,
            'approval_level'     => 1,
            'approval_chain'     => '[]',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        return $wpdb->insert_id;
    }

    /**
     * Generate reference number for resignation requests
     * Format: RS-YYYY-NNNN (e.g., RS-2026-0001)
     */
    public static function generate_resignation_request_number(): string {
        global $wpdb;
        return \SFS\HR\Core\Helpers::generate_reference_number( 'RS', $wpdb->prefix . 'sfs_hr_resignations' );
    }

    /**
     * Update resignation status
     */
    public static function update_status(int $resignation_id, string $status, array $extra = []): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $data = array_merge([
            'status'     => $status,
            'updated_at' => current_time('mysql'),
        ], $extra);

        return $wpdb->update($table, $data, ['id' => $resignation_id]) !== false;
    }

    /**
     * Check if user can approve resignation
     */
    public static function can_approve_resignation(array $resignation): bool {
        $current_user_id = get_current_user_id();
        $approval_level = intval($resignation['approval_level'] ?? 1);

        // HR managers can always approve
        if (current_user_can('sfs_hr.manage')) {
            return true;
        }

        // Check department manager (for level 1)
        if ($approval_level === 1) {
            $managed_depts = self::get_manager_dept_ids($current_user_id);
            return in_array($resignation['dept_id'], $managed_depts, true);
        }

        // Check HR approver (for level 2)
        if ($approval_level === 2) {
            $hr_approver_id = (int)get_option('sfs_hr_resignation_hr_approver', 0);
            if ($hr_approver_id > 0 && $hr_approver_id === $current_user_id) {
                return true;
            }
        }

        // Check Finance approver (for level 3)
        if ($approval_level === 3) {
            $finance_approver_id = (int)get_option('sfs_hr_resignation_finance_approver', 0);
            if ($finance_approver_id > 0 && $finance_approver_id === $current_user_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get department IDs managed by user
     */
    public static function get_manager_dept_ids(int $user_id): array {
        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $dept_table WHERE manager_user_id = %d AND active = 1",
            $user_id
        ), ARRAY_A);

        return array_column($rows, 'id');
    }

    /**
     * Render status badge HTML
     */
    public static function status_badge(string $status, int $approval_level = 1): string {
        $colors = [
            'pending'   => '#f0ad4e',
            'approved'  => '#5cb85c',
            'completed' => '#28a745',
            'rejected'  => '#d9534f',
            'cancelled' => '#6c757d',
        ];

        $color = $colors[$status] ?? '#777';

        $label = ucfirst($status);
        if ($status === 'pending') {
            if ($approval_level === 1) {
                $label = __('Pending - Manager', 'sfs-hr');
            } elseif ($approval_level === 2) {
                $label = __('Pending - HR', 'sfs-hr');
            } elseif ($approval_level === 3) {
                $label = __('Pending - Finance', 'sfs-hr');
            } elseif ($approval_level === 4) {
                $label = __('Pending - Final Exit', 'sfs-hr');
                $color = '#17a2b8';
            }
        } elseif ($status === 'completed') {
            $label = __('Completed', 'sfs-hr');
        }

        return sprintf(
            '<span style="background:%s;color:#fff;padding:4px 8px;border-radius:3px;font-size:11px;">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }

    /**
     * Render status pill (CSS class based)
     */
    public static function render_status_pill(string $status, int $approval_level = 1): string {
        $label = ucfirst($status);
        $class = 'sfs-hr-pill--' . $status;

        if ($status === 'pending') {
            if ($approval_level === 1) {
                $label = __('Pending - Manager', 'sfs-hr');
            } elseif ($approval_level === 2) {
                $label = __('Pending - HR', 'sfs-hr');
            } elseif ($approval_level === 3) {
                $label = __('Pending - Finance', 'sfs-hr');
            } elseif ($approval_level === 4) {
                $label = __('Final Exit', 'sfs-hr');
            }
        }

        return sprintf(
            '<span class="sfs-hr-pill %s">%s</span>',
            esc_attr($class),
            esc_html($label)
        );
    }

    /**
     * Final exit status badge
     */
    public static function final_exit_status_badge(string $status): string {
        $colors = [
            'not_required'       => '#999',
            'pending_submission' => '#f0ad4e',
            'submitted'          => '#17a2b8',
            'approved'           => '#5cb85c',
            'issued'             => '#007bff',
            'completed'          => '#28a745',
        ];

        $labels = [
            'not_required'       => __('Not Required', 'sfs-hr'),
            'pending_submission' => __('Pending', 'sfs-hr'),
            'submitted'          => __('Submitted', 'sfs-hr'),
            'approved'           => __('Approved', 'sfs-hr'),
            'issued'             => __('Issued', 'sfs-hr'),
            'completed'          => __('Completed', 'sfs-hr'),
        ];

        $color = $colors[$status] ?? '#777';
        $label = $labels[$status] ?? ucfirst($status);

        return sprintf(
            '<span style="background:%s;color:#fff;padding:4px 8px;border-radius:3px;font-size:11px;">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }

    /**
     * Check for outstanding loans
     */
    public static function check_loan_clearance(int $employee_id): array {
        $has_loans = false;
        $outstanding = 0;

        if (class_exists('\SFS\HR\Modules\Loans\LoansModule')) {
            $has_loans = \SFS\HR\Modules\Loans\LoansModule::has_active_loans($employee_id);
            $outstanding = \SFS\HR\Modules\Loans\LoansModule::get_outstanding_balance($employee_id);
        }

        return [
            'has_loans'   => $has_loans,
            'outstanding' => $outstanding,
            'cleared'     => !$has_loans || $outstanding <= 0,
        ];
    }

    /**
     * Check for unreturned assets
     */
    public static function check_asset_clearance(int $employee_id): array {
        $has_unreturned = false;
        $count = 0;

        if (class_exists('\SFS\HR\Modules\Assets\AssetsModule')) {
            $has_unreturned = \SFS\HR\Modules\Assets\AssetsModule::has_unreturned_assets($employee_id);
            $count = \SFS\HR\Modules\Assets\AssetsModule::get_unreturned_assets_count($employee_id);
        }

        return [
            'has_unreturned'   => $has_unreturned,
            'unreturned_count' => $count,
            'cleared'          => !$has_unreturned,
        ];
    }
}
