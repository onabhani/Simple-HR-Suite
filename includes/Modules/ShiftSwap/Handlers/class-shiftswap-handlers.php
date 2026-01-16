<?php
namespace SFS\HR\Modules\ShiftSwap\Handlers;

use SFS\HR\Modules\ShiftSwap\Services\ShiftSwap_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Shift Swap Handlers
 * Form handlers for shift swap operations
 */
class ShiftSwap_Handlers {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_post_sfs_hr_request_shift_swap', [$this, 'handle_swap_request']);
        add_action('admin_post_sfs_hr_respond_shift_swap', [$this, 'handle_swap_response']);
        add_action('admin_post_sfs_hr_cancel_shift_swap', [$this, 'handle_swap_cancel']);
        add_action('admin_post_sfs_hr_approve_shift_swap', [$this, 'handle_manager_approval']);
    }

    /**
     * Handle swap request submission
     */
    public function handle_swap_request(): void {
        $employee_id = ShiftSwap_Service::get_current_employee_id();
        if (!$employee_id) {
            wp_die(esc_html__('Not authorized.', 'sfs-hr'));
        }

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_request_swap_' . $employee_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        // Parse my_shift value
        $my_shift_data = isset($_POST['my_shift']) ? sanitize_text_field($_POST['my_shift']) : '';
        if (!$my_shift_data || strpos($my_shift_data, '_') === false) {
            $this->redirect_with_error(__('Please select your shift.', 'sfs-hr'));
            return;
        }

        list($shift_assign_id, $requester_date) = explode('_', $my_shift_data);
        $shift_assign_id = (int)$shift_assign_id;

        $target_id = isset($_POST['colleague_id']) ? (int)$_POST['colleague_id'] : 0;
        $target_date = isset($_POST['target_date']) ? sanitize_text_field($_POST['target_date']) : '';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        if (!$target_id || !$target_date) {
            $this->redirect_with_error(__('Please fill in all required fields.', 'sfs-hr'));
            return;
        }

        // Verify the shift belongs to the requester
        $shift = ShiftSwap_Service::validate_shift_ownership($shift_assign_id, $employee_id);
        if (!$shift) {
            $this->redirect_with_error(__('Invalid shift selected.', 'sfs-hr'));
            return;
        }

        // Create swap request
        $swap_id = ShiftSwap_Service::create_swap_request([
            'requester_id'       => $employee_id,
            'requester_shift_id' => $shift_assign_id,
            'requester_date'     => $requester_date,
            'target_id'          => $target_id,
            'target_date'        => $target_date,
            'reason'             => $reason,
        ]);

        // Trigger notification
        do_action('sfs_hr_shift_swap_requested', $swap_id, $target_id);

        // Audit log
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'shift_swap_requested',
                'shift_swaps',
                $swap_id,
                null,
                ['requester_id' => $employee_id, 'target_id' => $target_id]
            );
        }

        $this->redirect_with_success(__('Shift swap request sent!', 'sfs-hr'));
    }

    /**
     * Handle swap response (accept/decline)
     */
    public function handle_swap_response(): void {
        $swap_id = isset($_POST['swap_id']) ? (int)$_POST['swap_id'] : 0;
        $response = isset($_POST['response']) ? sanitize_key($_POST['response']) : '';

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_respond_swap_' . $swap_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        if (!in_array($response, ['accept', 'decline'], true)) {
            wp_die(esc_html__('Invalid response.', 'sfs-hr'));
        }

        $employee_id = ShiftSwap_Service::get_current_employee_id();
        if (!$employee_id) {
            wp_die(esc_html__('Not authorized.', 'sfs-hr'));
        }

        // Verify swap belongs to this employee as target
        $swap = ShiftSwap_Service::get_swap_for_target($swap_id, $employee_id);
        if (!$swap) {
            $this->redirect_with_error(__('Swap request not found or already processed.', 'sfs-hr'));
            return;
        }

        $new_status = ($response === 'accept') ? 'manager_pending' : 'declined';

        ShiftSwap_Service::update_swap_status($swap_id, $new_status, [
            'target_responded_at' => current_time('mysql'),
        ]);

        // Notify requester
        do_action('sfs_hr_shift_swap_responded', $swap_id, $response);

        // Audit log
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'shift_swap_' . $response . 'ed',
                'shift_swaps',
                $swap_id,
                ['status' => 'pending'],
                ['status' => $new_status]
            );
        }

        if ($response === 'accept') {
            $this->redirect_with_success(__('Swap accepted! Awaiting manager approval.', 'sfs-hr'));
        } else {
            $this->redirect_with_success(__('Swap request declined.', 'sfs-hr'));
        }
    }

    /**
     * Handle swap cancellation
     */
    public function handle_swap_cancel(): void {
        $swap_id = isset($_POST['swap_id']) ? (int)$_POST['swap_id'] : 0;

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_cancel_swap_' . $swap_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        $employee_id = ShiftSwap_Service::get_current_employee_id();
        if (!$employee_id) {
            wp_die(esc_html__('Not authorized.', 'sfs-hr'));
        }

        // Verify swap belongs to this employee as requester
        $swap = ShiftSwap_Service::get_swap_for_requester($swap_id, $employee_id);
        if (!$swap) {
            $this->redirect_with_error(__('Swap request not found or cannot be cancelled.', 'sfs-hr'));
            return;
        }

        ShiftSwap_Service::update_swap_status($swap_id, 'cancelled');

        $this->redirect_with_success(__('Swap request cancelled.', 'sfs-hr'));
    }

    /**
     * Handle manager approval (called from Attendance admin)
     */
    public function handle_manager_approval(): void {
        $swap_id = isset($_POST['swap_id']) ? (int)$_POST['swap_id'] : 0;
        $decision = isset($_POST['decision']) ? sanitize_key($_POST['decision']) : '';

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_approve_swap_' . $swap_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        if (!current_user_can('sfs_hr.manage') && !current_user_can('sfs_hr_attendance_admin')) {
            wp_die(esc_html__('Not authorized.', 'sfs-hr'));
        }

        if (!in_array($decision, ['approve', 'reject'], true)) {
            wp_die(esc_html__('Invalid decision.', 'sfs-hr'));
        }

        $swap = ShiftSwap_Service::get_swap_for_manager($swap_id);
        if (!$swap) {
            wp_safe_redirect(admin_url('admin.php?page=sfs-hr-attendance&tab=shift_swaps&error=' . rawurlencode(__('Swap not found.', 'sfs-hr'))));
            exit;
        }

        $new_status = ($decision === 'approve') ? 'approved' : 'rejected';
        $manager_note = isset($_POST['manager_note']) ? sanitize_textarea_field($_POST['manager_note']) : '';

        ShiftSwap_Service::update_swap_status($swap_id, $new_status, [
            'manager_id'           => get_current_user_id(),
            'manager_responded_at' => current_time('mysql'),
            'manager_note'         => $manager_note,
        ]);

        // If approved, execute the swap
        if ($decision === 'approve') {
            ShiftSwap_Service::execute_swap($swap);
        }

        // Audit log
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'shift_swap_' . $decision . 'd',
                'shift_swaps',
                $swap_id,
                ['status' => 'manager_pending'],
                ['status' => $new_status]
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-attendance&tab=shift_swaps&success=' . rawurlencode(__('Swap ' . $decision . 'd.', 'sfs-hr'))));
        exit;
    }

    /**
     * Redirect with error message
     */
    private function redirect_with_error(string $message): void {
        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-my-profile&tab=shift_swap&error=' . rawurlencode($message)));
        exit;
    }

    /**
     * Redirect with success message
     */
    private function redirect_with_success(string $message): void {
        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-my-profile&tab=shift_swap&success=' . rawurlencode($message)));
        exit;
    }
}
