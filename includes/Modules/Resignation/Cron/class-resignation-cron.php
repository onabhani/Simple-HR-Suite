<?php
namespace SFS\HR\Modules\Resignation\Cron;

if (!defined('ABSPATH')) { exit; }

use SFS\HR\Core\Hooks;

/**
 * Resignation Cron
 * Daily job to terminate employees after last working day
 */
class Resignation_Cron {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('sfs_hr_daily_resignation_check', [$this, 'process_expired_resignations']);

        // Schedule the cron event if not already scheduled
        if (!wp_next_scheduled('sfs_hr_daily_resignation_check')) {
            wp_schedule_event(time(), 'daily', 'sfs_hr_daily_resignation_check');
        }
    }

    /**
     * Process expired resignations and terminate employees
     */
    public function process_expired_resignations(): void {
        global $wpdb;
        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $today = current_time('Y-m-d');

        // Find approved resignations where last_working_day has passed and employee is still active
        $expired_resignations = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.employee_id, r.last_working_day, e.status as employee_status, e.user_id
             FROM {$resign_table} r
             JOIN {$emp_table} e ON e.id = r.employee_id
             WHERE r.status = 'approved'
             AND r.last_working_day < %s
             AND e.status = 'active'",
            $today
        ), ARRAY_A);

        if (empty($expired_resignations)) {
            return;
        }

        // Terminate each employee
        foreach ($expired_resignations as $resignation) {
            $wpdb->update($emp_table, [
                'status'     => 'terminated',
                'updated_at' => current_time('mysql'),
            ], ['id' => $resignation['employee_id']]);

            // Demote WordPress user to terminated role (blocks login)
            if (!empty($resignation['user_id'])) {
                Hooks::demote_to_terminated_role((int) $resignation['user_id']);
            }

            // Log the termination
            if (class_exists('\SFS\HR\Core\AuditTrail')) {
                \SFS\HR\Core\AuditTrail::log(
                    'employee_auto_terminated',
                    'employees',
                    $resignation['employee_id'],
                    ['status' => 'active'],
                    ['status' => 'terminated', 'resignation_id' => $resignation['id']]
                );
            }
        }
    }
}
