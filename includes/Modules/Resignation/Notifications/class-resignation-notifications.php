<?php
namespace SFS\HR\Modules\Resignation\Notifications;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Resignation\Services\Resignation_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Resignation Notifications
 * Email notifications for resignation events
 */
class Resignation_Notifications {

    /**
     * Notify approver of new submission
     */
    public static function notify_new_submission(int $resignation_id): void {
        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation) return;

        // Get department manager
        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $dept = $wpdb->get_row($wpdb->prepare(
            "SELECT manager_user_id FROM $dept_table WHERE id = %d AND active = 1",
            $resignation['dept_id']
        ), ARRAY_A);

        if (!$dept || !$dept['manager_user_id']) return;

        $approver = get_userdata($dept['manager_user_id']);
        if (!$approver) return;

        $subject = __('New Resignation Submitted', 'sfs-hr');
        $message = sprintf(
            __('A resignation has been submitted by %s %s and requires your approval.', 'sfs-hr'),
            $resignation['first_name'],
            $resignation['last_name']
        );
        $message .= "\n\n" . __('Resignation Date:', 'sfs-hr') . ' ' . $resignation['resignation_date'];
        $message .= "\n" . __('Last Working Day:', 'sfs-hr') . ' ' . $resignation['last_working_day'];
        $message .= "\n\n" . __('Please log in to review and approve.', 'sfs-hr');

        Helpers::send_mail($approver->user_email, $subject, $message);
    }

    /**
     * Notify employee of approval
     */
    public static function notify_approval(int $resignation_id): void {
        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation || !$resignation['emp_user_id']) return;

        $employee = get_userdata($resignation['emp_user_id']);
        if (!$employee) return;

        $subject = __('Your Resignation Has Been Approved', 'sfs-hr');
        $message = sprintf(
            __('Dear %s,', 'sfs-hr'),
            $resignation['first_name']
        );
        $message .= "\n\n" . __('Your resignation has been approved.', 'sfs-hr');
        $message .= "\n" . __('Last Working Day:', 'sfs-hr') . ' ' . $resignation['last_working_day'];

        Helpers::send_mail($employee->user_email, $subject, $message);
    }

    /**
     * Notify employee of rejection
     */
    public static function notify_rejection(int $resignation_id): void {
        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation || !$resignation['emp_user_id']) return;

        $employee = get_userdata($resignation['emp_user_id']);
        if (!$employee) return;

        $subject = __('Your Resignation Has Been Rejected', 'sfs-hr');
        $message = sprintf(
            __('Dear %s,', 'sfs-hr'),
            $resignation['first_name']
        );
        $message .= "\n\n" . __('Your resignation has been rejected.', 'sfs-hr');
        if (!empty($resignation['approver_note'])) {
            $message .= "\n" . __('Reason:', 'sfs-hr') . ' ' . $resignation['approver_note'];
        }

        Helpers::send_mail($employee->user_email, $subject, $message);
    }

    /**
     * Notify next approver in chain
     */
    public static function notify_next_approver(int $resignation_id, int $approver_id): void {
        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation) return;

        $approver = get_userdata($approver_id);
        if (!$approver) return;

        $subject = __('Resignation Pending Your Approval', 'sfs-hr');
        $message = sprintf(
            __('A resignation from %s %s is pending your approval.', 'sfs-hr'),
            $resignation['first_name'],
            $resignation['last_name']
        );
        $message .= "\n\n" . __('Resignation Date:', 'sfs-hr') . ' ' . $resignation['resignation_date'];
        $message .= "\n" . __('Last Working Day:', 'sfs-hr') . ' ' . $resignation['last_working_day'];
        $message .= "\n\n" . __('Please log in to review and approve.', 'sfs-hr');

        Helpers::send_mail($approver->user_email, $subject, $message);
    }
}
