<?php
namespace SFS\HR\Modules\ShiftSwap\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Shift Swap Service
 * Business logic for shift swap operations
 */
class ShiftSwap_Service {

    /**
     * Status labels
     */
    public static function get_status_labels(): array {
        return [
            'pending'         => __('Pending Response', 'sfs-hr'),
            'accepted'        => __('Accepted by Colleague', 'sfs-hr'),
            'declined'        => __('Declined', 'sfs-hr'),
            'cancelled'       => __('Cancelled', 'sfs-hr'),
            'manager_pending' => __('Awaiting Manager Approval', 'sfs-hr'),
            'approved'        => __('Approved', 'sfs-hr'),
            'rejected'        => __('Rejected by Manager', 'sfs-hr'),
        ];
    }

    /**
     * Status colors
     */
    public static function get_status_colors(): array {
        return [
            'pending'         => '#f59e0b',
            'accepted'        => '#3b82f6',
            'declined'        => '#ef4444',
            'cancelled'       => '#6b7280',
            'manager_pending' => '#8b5cf6',
            'approved'        => '#10b981',
            'rejected'        => '#ef4444',
        ];
    }

    /**
     * Get current employee ID from user
     */
    public static function get_current_employee_id(): int {
        if (!is_user_logged_in()) {
            return 0;
        }

        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$emp_table} WHERE user_id = %d AND status = 'active'",
            get_current_user_id()
        ));
    }

    /**
     * Get employee's upcoming shifts
     */
    public static function get_employee_shifts(int $employee_id, int $limit = 30): array {
        global $wpdb;
        $shifts_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assigns';
        $today = wp_date('Y-m-d');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT sa.*, s.name AS shift_name, s.start_time, s.end_time
             FROM {$shifts_table} sa
             LEFT JOIN {$wpdb->prefix}sfs_hr_attendance_shifts s ON s.id = sa.shift_id
             WHERE sa.employee_id = %d AND sa.assign_date >= %s
             ORDER BY sa.assign_date ASC
             LIMIT %d",
            $employee_id,
            $today,
            $limit
        ));
    }

    /**
     * Get colleagues in same department
     */
    public static function get_colleagues(int $dept_id, int $exclude_employee_id): array {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, first_name, last_name, employee_code
             FROM {$emp_table}
             WHERE dept_id = %d AND id != %d AND status = 'active'
             ORDER BY first_name, last_name",
            $dept_id,
            $exclude_employee_id
        ));
    }

    /**
     * Get incoming swap requests for employee
     */
    public static function get_incoming_requests(int $employee_id): array {
        global $wpdb;
        $swaps_table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT sw.*,
                    CONCAT(req.first_name, ' ', req.last_name) AS requester_name,
                    req.employee_code AS requester_code
             FROM {$swaps_table} sw
             JOIN {$emp_table} req ON req.id = sw.requester_id
             WHERE sw.target_id = %d AND sw.status = 'pending'
             ORDER BY sw.created_at DESC",
            $employee_id
        ));
    }

    /**
     * Get outgoing swap requests for employee
     */
    public static function get_outgoing_requests(int $employee_id, int $limit = 20): array {
        global $wpdb;
        $swaps_table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT sw.*,
                    CONCAT(tgt.first_name, ' ', tgt.last_name) AS target_name,
                    tgt.employee_code AS target_code
             FROM {$swaps_table} sw
             JOIN {$emp_table} tgt ON tgt.id = sw.target_id
             WHERE sw.requester_id = %d
             ORDER BY sw.created_at DESC
             LIMIT %d",
            $employee_id,
            $limit
        ));
    }

    /**
     * Get swap by ID
     */
    public static function get_swap(int $swap_id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $swap_id
        ));
    }

    /**
     * Get swap for target employee response
     */
    public static function get_swap_for_target(int $swap_id, int $target_id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND target_id = %d AND status = 'pending'",
            $swap_id,
            $target_id
        ));
    }

    /**
     * Get swap for requester cancellation
     */
    public static function get_swap_for_requester(int $swap_id, int $requester_id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND requester_id = %d AND status = 'pending'",
            $swap_id,
            $requester_id
        ));
    }

    /**
     * Get swap for manager approval
     */
    public static function get_swap_for_manager(int $swap_id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND status = 'manager_pending'",
            $swap_id
        ));
    }

    /**
     * Create swap request
     */
    public static function create_swap_request(array $data): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $now = current_time('mysql');

        // Generate reference number
        $request_number = \SFS\HR\Modules\ShiftSwap\ShiftSwapModule::generate_shift_swap_request_number();

        $wpdb->insert($table, [
            'requester_id'       => $data['requester_id'],
            'requester_shift_id' => $data['requester_shift_id'],
            'requester_date'     => $data['requester_date'],
            'target_id'          => $data['target_id'],
            'target_date'        => $data['target_date'],
            'reason'             => $data['reason'] ?? '',
            'request_number'     => $request_number,
            'status'             => 'pending',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        return $wpdb->insert_id;
    }

    /**
     * Update swap status
     */
    public static function update_swap_status(int $swap_id, string $status, array $extra = []): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';

        $data = array_merge([
            'status'     => $status,
            'updated_at' => current_time('mysql'),
        ], $extra);

        return $wpdb->update($table, $data, ['id' => $swap_id]) !== false;
    }

    /**
     * Execute the actual shift swap
     */
    public static function execute_swap($swap): void {
        global $wpdb;
        $shifts_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assigns';

        // Get requester's shift assignment
        $requester_shift = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$shifts_table} WHERE id = %d",
            $swap->requester_shift_id
        ));

        // Get target's shift assignment for the target date
        $target_shift = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$shifts_table} WHERE employee_id = %d AND assign_date = %s",
            $swap->target_id,
            $swap->target_date
        ));

        if ($requester_shift) {
            $wpdb->update(
                $shifts_table,
                ['employee_id' => $swap->target_id, 'updated_at' => current_time('mysql')],
                ['id' => $requester_shift->id]
            );
        }

        if ($target_shift) {
            $wpdb->update(
                $shifts_table,
                ['employee_id' => $swap->requester_id, 'updated_at' => current_time('mysql')],
                ['id' => $target_shift->id]
            );
        }
    }

    /**
     * Validate shift belongs to employee
     */
    public static function validate_shift_ownership(int $shift_assign_id, int $employee_id): ?object {
        global $wpdb;
        $shifts_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assigns';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$shifts_table} WHERE id = %d AND employee_id = %d",
            $shift_assign_id,
            $employee_id
        ));
    }

    /**
     * Get pending swaps count for managers
     */
    public static function get_pending_count(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';

        return (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'manager_pending'"
        );
    }

    /**
     * Get all pending swaps for managers
     */
    public static function get_pending_for_managers(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        return $wpdb->get_results(
            "SELECT sw.*,
                    CONCAT(req.first_name, ' ', req.last_name) AS requester_name,
                    CONCAT(tgt.first_name, ' ', tgt.last_name) AS target_name
             FROM {$table} sw
             JOIN {$emp_table} req ON req.id = sw.requester_id
             JOIN {$emp_table} tgt ON tgt.id = sw.target_id
             WHERE sw.status = 'manager_pending'
             ORDER BY sw.created_at DESC"
        );
    }

    /**
     * Get employee with email
     */
    public static function get_employee_with_email(int $employee_id): ?object {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, u.user_email
             FROM {$emp_table} e
             LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
             WHERE e.id = %d",
            $employee_id
        ));
    }
}
