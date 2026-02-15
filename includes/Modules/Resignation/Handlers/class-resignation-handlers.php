<?php
namespace SFS\HR\Modules\Resignation\Handlers;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Resignation\Services\Resignation_Service;
use SFS\HR\Modules\Resignation\Notifications\Resignation_Notifications;
use SFS\HR\Modules\EmployeeExit\EmployeeExitModule;

if (!defined('ABSPATH')) { exit; }

/**
 * Resignation Handlers
 * Form submission handlers for resignation operations
 */
class Resignation_Handlers {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_post_sfs_hr_resignation_submit', [$this, 'handle_submit']);
        add_action('admin_post_sfs_hr_resignation_approve', [$this, 'handle_approve']);
        add_action('admin_post_sfs_hr_resignation_reject', [$this, 'handle_reject']);
        add_action('admin_post_sfs_hr_resignation_cancel', [$this, 'handle_cancel']);
        add_action('admin_post_sfs_hr_final_exit_update', [$this, 'handle_final_exit_update']);
        add_action('admin_post_sfs_hr_company_termination', [$this, 'handle_company_termination']);
        add_action('admin_post_sfs_hr_resignation_settings', [$this, 'handle_settings']);

        // AJAX handlers
        add_action('wp_ajax_sfs_hr_get_resignation', [$this, 'ajax_get_resignation']);
    }

    /**
     * Handle resignation submission
     */
    public function handle_submit(): void {
        check_admin_referer('sfs_hr_resignation_submit');

        $employee_id = Helpers::current_employee_id();
        if (!$employee_id) {
            wp_die(__('No employee record found.', 'sfs-hr'));
        }

        $resignation_date = sanitize_text_field($_POST['resignation_date'] ?? '');
        $resignation_type = sanitize_key($_POST['resignation_type'] ?? 'regular');
        $notice_period_days = (int)get_option('sfs_hr_resignation_notice_period', 30);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (empty($resignation_date) || empty($reason)) {
            wp_die(__('Please fill all required fields.', 'sfs-hr'));
        }

        // Calculate last working day
        $last_working_day = date('Y-m-d', strtotime($resignation_date . ' + ' . $notice_period_days . ' days'));

        $resignation_id = Resignation_Service::create_resignation([
            'employee_id'        => $employee_id,
            'resignation_date'   => $resignation_date,
            'resignation_type'   => $resignation_type,
            'notice_period_days' => $notice_period_days,
            'last_working_day'   => $last_working_day,
            'reason'             => $reason,
        ]);

        // Log the submission event
        EmployeeExitModule::log_resignation_event( $resignation_id, 'submitted', [
            'resignation_type'   => $resignation_type,
            'resignation_date'   => $resignation_date,
            'last_working_day'   => $last_working_day,
            'notice_period_days' => $notice_period_days,
        ] );

        // Send notification to manager
        Resignation_Notifications::notify_new_submission($resignation_id);

        // Redirect back
        $redirect_url = isset($_POST['_wp_http_referer']) ? $_POST['_wp_http_referer'] : admin_url('admin.php?page=sfs-hr-my-profile');
        Helpers::redirect_with_notice(
            $redirect_url,
            'success',
            __('Resignation submitted successfully.', 'sfs-hr')
        );
    }

    /**
     * Handle resignation approval
     */
    public function handle_approve(): void {
        check_admin_referer('sfs_hr_resignation_approve');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$resignation_id) {
            wp_die(__('Invalid resignation ID.', 'sfs-hr'));
        }

        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation || !Resignation_Service::can_approve_resignation($resignation)) {
            wp_die(__('You cannot approve this resignation.', 'sfs-hr'));
        }

        $current_level = intval($resignation['approval_level']);
        $has_finance_approval = (bool)get_option('sfs_hr_resignation_finance_approver', 0);

        // Append to approval chain
        $chain = json_decode($resignation['approval_chain'] ?? '[]', true) ?: [];
        $chain[] = [
            'by'     => get_current_user_id(),
            'role'   => $current_level === 1 ? 'manager' : ($current_level === 2 ? 'hr' : 'finance'),
            'action' => 'approved',
            'note'   => $note,
            'at'     => current_time('mysql'),
        ];

        $update_data = [
            'approval_chain' => json_encode($chain),
            'approver_id'    => get_current_user_id(),
            'approver_note'  => $note,
            'updated_at'     => current_time('mysql'),
        ];

        $is_final_approval = false;

        // Determine next approval level
        if ($current_level === 1) {
            $update_data['approval_level'] = 2;
        } elseif ($current_level === 2) {
            if ($has_finance_approval) {
                $update_data['approval_level'] = 3;
            } else {
                $is_final_approval = true;
            }
        } elseif ($current_level === 3) {
            $is_final_approval = true;
        }

        if ($is_final_approval) {
            $resignation_type = $resignation['resignation_type'] ?? 'regular';

            if ($resignation_type === 'final_exit') {
                $update_data['approval_level'] = 4;
                $update_data['status'] = 'pending';
            } else {
                $update_data['status'] = 'approved';
                $update_data['decided_at'] = current_time('mysql');
            }
        }

        $wpdb->update($table, $update_data, ['id' => $resignation_id]);

        // Log the approval event
        $role_name = $current_level === 1 ? 'manager' : ($current_level === 2 ? 'hr' : 'finance');
        EmployeeExitModule::log_resignation_event( $resignation_id, $role_name . '_approved', [
            'approval_level' => $current_level,
            'note'           => $note ?: null,
            'next_level'     => $update_data['approval_level'] ?? null,
            'final'          => $is_final_approval,
        ] );

        // Fire hook for AuditTrail (log approval steps and final approval)
        $new_status = $update_data['status'] ?? 'pending';
        do_action( 'sfs_hr_resignation_status_changed', $resignation_id, $resignation['status'], $new_status );

        // Send notification
        Resignation_Notifications::notify_approval($resignation_id);

        // Build success message
        $success_message = sprintf(
            __('Resignation approved successfully. Employee will remain active until their last working day (%s).', 'sfs-hr'),
            $resignation['last_working_day'] ?? 'N/A'
        );

        // Check for loan/asset warnings
        $loan_status = Resignation_Service::check_loan_clearance($resignation['employee_id']);
        if (!$loan_status['cleared']) {
            $success_message .= ' ' . sprintf(
                __('Note: Employee has outstanding loan balance of %s SAR.', 'sfs-hr'),
                number_format($loan_status['outstanding'], 2)
            );
        }

        $asset_status = Resignation_Service::check_asset_clearance($resignation['employee_id']);
        if (!$asset_status['cleared']) {
            $success_message .= ' ' . sprintf(
                __('Note: Employee has %d unreturned asset(s).', 'sfs-hr'),
                $asset_status['unreturned_count']
            );
        }

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-lifecycle&tab=resignations'),
            'success',
            $success_message
        );
    }

    /**
     * Handle resignation rejection
     */
    public function handle_reject(): void {
        check_admin_referer('sfs_hr_resignation_reject');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$resignation_id || empty($note)) {
            wp_die(__('Resignation ID and rejection reason are required.', 'sfs-hr'));
        }

        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation || !Resignation_Service::can_approve_resignation($resignation)) {
            wp_die(__('You cannot reject this resignation.', 'sfs-hr'));
        }

        $current_level = intval($resignation['approval_level']);

        $chain = json_decode($resignation['approval_chain'] ?? '[]', true) ?: [];
        $chain[] = [
            'by'     => get_current_user_id(),
            'role'   => $current_level === 1 ? 'manager' : 'hr',
            'action' => 'rejected',
            'note'   => $note,
            'at'     => current_time('mysql'),
        ];

        $wpdb->update($table, [
            'status'         => 'rejected',
            'approval_chain' => json_encode($chain),
            'approver_note'  => $note,
            'approver_id'    => get_current_user_id(),
            'decided_at'     => current_time('mysql'),
            'updated_at'     => current_time('mysql'),
        ], ['id' => $resignation_id]);

        // Log the rejection event
        $role_name = $current_level === 1 ? 'manager' : 'hr';
        EmployeeExitModule::log_resignation_event( $resignation_id, 'rejected', [
            'rejected_by'    => $role_name,
            'approval_level' => $current_level,
            'reason'         => $note,
        ] );

        Resignation_Notifications::notify_rejection($resignation_id);

        // Fire hook for AuditTrail
        do_action( 'sfs_hr_resignation_status_changed', $resignation_id, $resignation['status'], 'rejected' );

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-lifecycle&tab=resignations'),
            'success',
            __('Resignation rejected.', 'sfs-hr')
        );
    }

    /**
     * Handle resignation cancellation
     */
    public function handle_cancel(): void {
        check_admin_referer('sfs_hr_resignation_cancel');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$resignation_id || empty($note)) {
            wp_die(__('Resignation ID and cancellation reason are required.', 'sfs-hr'));
        }

        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation || !Resignation_Service::can_approve_resignation($resignation)) {
            wp_die(__('You cannot cancel this resignation.', 'sfs-hr'));
        }

        $chain = json_decode($resignation['approval_chain'] ?? '[]', true) ?: [];
        $chain[] = [
            'by'     => get_current_user_id(),
            'role'   => 'manager',
            'action' => 'cancelled',
            'note'   => $note,
            'at'     => current_time('mysql'),
        ];

        $wpdb->update($table, [
            'status'         => 'cancelled',
            'approval_chain' => json_encode($chain),
            'approver_note'  => $note,
            'approver_id'    => get_current_user_id(),
            'decided_at'     => current_time('mysql'),
            'updated_at'     => current_time('mysql'),
        ], ['id' => $resignation_id]);

        // Log the cancellation event
        EmployeeExitModule::log_resignation_event( $resignation_id, 'cancelled', [
            'reason' => $note,
        ] );

        // Check if employee was terminated
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM {$emp_table} WHERE id = %d",
            $resignation['employee_id']
        ), ARRAY_A);

        $status_message = '';
        if ($employee && $employee['status'] === 'terminated') {
            $wpdb->update($emp_table, [
                'status' => 'active',
                'updated_at' => current_time('mysql'),
            ], ['id' => $resignation['employee_id']]);
            $status_message = ' ' . __('Employee status has been reverted to active.', 'sfs-hr');

            // Log employee reactivation
            EmployeeExitModule::log_resignation_event( $resignation_id, 'employee_reactivated', [
                'note' => __('Employee status reverted to active after resignation cancellation.', 'sfs-hr'),
            ] );
        }

        // Fire hook for AuditTrail
        do_action( 'sfs_hr_resignation_status_changed', $resignation_id, $resignation['status'], 'cancelled' );

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-lifecycle&tab=resignations'),
            'success',
            __('Resignation cancelled successfully.', 'sfs-hr') . $status_message
        );
    }

    /**
     * Handle final exit update
     */
    public function handle_final_exit_update(): void {
        check_admin_referer('sfs_hr_final_exit_update');

        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Access denied', 'sfs-hr'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        if (!$resignation_id) {
            wp_die(__('Invalid resignation ID', 'sfs-hr'));
        }

        $final_exit_status = sanitize_text_field($_POST['final_exit_status'] ?? 'not_required');

        $update_data = [
            'final_exit_status'          => $final_exit_status,
            'final_exit_number'          => sanitize_text_field($_POST['final_exit_number'] ?? ''),
            'final_exit_date'            => sanitize_text_field($_POST['final_exit_date'] ?? '') ?: null,
            'final_exit_submitted_date'  => sanitize_text_field($_POST['final_exit_submitted_date'] ?? '') ?: null,
            'government_reference'       => sanitize_text_field($_POST['government_reference'] ?? ''),
            'actual_exit_date'           => sanitize_text_field($_POST['actual_exit_date'] ?? '') ?: null,
            'ticket_booked'              => isset($_POST['ticket_booked']) ? 1 : 0,
            'exit_stamp_received'        => isset($_POST['exit_stamp_received']) ? 1 : 0,
            'updated_at'                 => current_time('mysql'),
        ];

        if ($final_exit_status === 'completed') {
            $update_data['status'] = 'completed';
            $update_data['decided_at'] = current_time('mysql');
        }

        $wpdb->update($table, $update_data, ['id' => $resignation_id]);

        // Log the final exit update event
        $log_meta = [
            'final_exit_status' => $final_exit_status,
        ];
        if ( ! empty( $update_data['final_exit_number'] ) ) {
            $log_meta['final_exit_number'] = $update_data['final_exit_number'];
        }
        if ( ! empty( $update_data['actual_exit_date'] ) ) {
            $log_meta['actual_exit_date'] = $update_data['actual_exit_date'];
        }
        if ( $final_exit_status === 'completed' ) {
            $log_meta['completed'] = true;
        }
        EmployeeExitModule::log_resignation_event( $resignation_id, 'final_exit_updated', $log_meta );

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-lifecycle&tab=resignations&status=final_exit'),
            'success',
            __('Final Exit data updated successfully.', 'sfs-hr')
        );
    }

    /**
     * Handle company-initiated termination (HR/admin only)
     */
    public function handle_company_termination(): void {
        check_admin_referer('sfs_hr_company_termination');

        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Access denied', 'sfs-hr'));
        }

        $employee_id = intval($_POST['employee_id'] ?? 0);
        $termination_date = sanitize_text_field($_POST['termination_date'] ?? '');
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (!$employee_id || empty($termination_date) || empty($reason)) {
            wp_die(__('Please fill all required fields.', 'sfs-hr'));
        }

        // Create a resignation record of type company_termination
        $resignation_id = Resignation_Service::create_resignation([
            'employee_id'        => $employee_id,
            'resignation_date'   => $termination_date,
            'resignation_type'   => 'company_termination',
            'notice_period_days' => 0,
            'last_working_day'   => $termination_date,
            'reason'             => $reason,
        ]);

        if ($resignation_id) {
            // Auto-approve since it's company-initiated
            Resignation_Service::update_status($resignation_id, 'approved', [
                'approver_id'    => get_current_user_id(),
                'decided_at'     => current_time('mysql'),
                'approval_level' => 2,
            ]);

            // Log the event
            EmployeeExitModule::log_resignation_event($resignation_id, 'company_termination', [
                'termination_date' => $termination_date,
                'initiated_by'     => get_current_user_id(),
                'reason'           => $reason,
            ]);

            // Update employee status to terminated
            global $wpdb;
            $emp_table = $wpdb->prefix . 'sfs_hr_employees';
            $wpdb->update($emp_table, [
                'status'     => 'terminated',
                'updated_at' => current_time('mysql'),
            ], ['id' => $employee_id]);
        }

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-lifecycle&tab=resignations&status=approved'),
            'success',
            __('Employee terminated successfully.', 'sfs-hr')
        );
    }

    /**
     * Handle settings save
     */
    public function handle_settings(): void {
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Access denied', 'sfs-hr'));
        }

        check_admin_referer('sfs_hr_resignation_settings');

        $notice_period_days = isset($_POST['notice_period_days']) ? max(0, min(365, (int)$_POST['notice_period_days'])) : 30;
        update_option('sfs_hr_resignation_notice_period', (string)$notice_period_days);

        $hr_approver = isset($_POST['hr_approver']) ? (int)$_POST['hr_approver'] : 0;
        update_option('sfs_hr_resignation_hr_approver', (string)$hr_approver);

        $finance_approver = isset($_POST['finance_approver']) ? (int)$_POST['finance_approver'] : 0;
        update_option('sfs_hr_resignation_finance_approver', (string)$finance_approver);

        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-lifecycle&tab=resignations&tab=settings&ok=1'));
        exit;
    }

    /**
     * AJAX: Get resignation details
     */
    public function ajax_get_resignation(): void {
        check_ajax_referer('sfs_hr_resignation_ajax', 'nonce');

        if (!current_user_can('sfs_hr.manage') && !current_user_can('sfs_hr.view')) {
            wp_send_json_error(['message' => __('Access denied', 'sfs-hr')]);
        }

        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        if (!$resignation_id) {
            wp_send_json_error(['message' => __('Invalid resignation ID', 'sfs-hr')]);
        }

        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation) {
            wp_send_json_error(['message' => __('Resignation not found', 'sfs-hr')]);
        }

        // Get history for this resignation
        $history = EmployeeExitModule::get_resignation_history($resignation_id);
        $formatted_history = [];
        foreach ($history as $event) {
            $meta = $event['meta'] ? json_decode($event['meta'], true) : [];
            $formatted_history[] = [
                'date'       => wp_date('M j, Y g:i a', strtotime($event['created_at'])),
                'event_type' => str_replace('_', ' ', ucwords($event['event_type'], '_')),
                'user_name'  => $event['user_name'] ?: __('System', 'sfs-hr'),
                'meta'       => $meta,
            ];
        }

        $data = [
            'id'                         => $resignation['id'],
            'employee_name'              => trim($resignation['first_name'] . ' ' . $resignation['last_name']),
            'employee_code'              => $resignation['employee_code'],
            'resignation_date'           => $resignation['resignation_date'],
            'resignation_type'           => $resignation['resignation_type'] ?? 'regular',
            'status'                     => $resignation['status'],
            'approval_level'             => intval($resignation['approval_level'] ?? 1),
            'final_exit_status'          => $resignation['final_exit_status'] ?? 'not_required',
            'final_exit_number'          => $resignation['final_exit_number'] ?? '',
            'final_exit_date'            => $resignation['final_exit_date'] ?? '',
            'final_exit_submitted_date'  => $resignation['final_exit_submitted_date'] ?? '',
            'government_reference'       => $resignation['government_reference'] ?? '',
            'expected_country_exit_date' => $resignation['expected_country_exit_date'] ?? '',
            'actual_exit_date'           => $resignation['actual_exit_date'] ?? '',
            'ticket_booked'              => $resignation['ticket_booked'] ?? 0,
            'exit_stamp_received'        => $resignation['exit_stamp_received'] ?? 0,
            'can_approve'                => Resignation_Service::can_approve_resignation($resignation),
            'can_manage'                 => current_user_can('sfs_hr.manage'),
            'history'                    => $formatted_history,
        ];

        wp_send_json_success($data);
    }
}
