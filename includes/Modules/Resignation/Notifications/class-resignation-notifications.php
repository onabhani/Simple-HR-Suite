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

        self::send_notification((int) $dept['manager_user_id'], $approver->user_email, $subject, $message, 'resignation_submitted');
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

        self::send_notification((int) $resignation['emp_user_id'], $employee->user_email, $subject, $message, 'resignation_approved');
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

        self::send_notification((int) $resignation['emp_user_id'], $employee->user_email, $subject, $message, 'resignation_rejected');
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

        self::send_notification($approver_id, $approver->user_email, $subject, $message, 'resignation_pending_approval');
    }

    /**
     * Send notification with preference checks
     *
     * @param int    $user_id           User ID
     * @param string $email             Email address
     * @param string $subject           Email subject
     * @param string $message           Email message
     * @param string $notification_type Notification type
     */
    private static function send_notification(int $user_id, string $email, string $subject, string $message, string $notification_type): void {
        // Check if user wants this type of email notification
        if (!apply_filters('dofs_user_wants_email_notification', true, $user_id, $notification_type)) {
            return;
        }

        // Check if notification should be sent now or queued for digest
        if (apply_filters('dofs_should_send_notification_now', true, $user_id, $notification_type)) {
            // Send email immediately
            Helpers::send_mail($email, $subject, $message);
        } else {
            // Queue for digest
            self::queue_for_digest($user_id, $email, $subject, $message, $notification_type);
        }
    }

    /**
     * Queue notification for digest delivery
     *
     * @param int    $user_id           User ID
     * @param string $email             Email address
     * @param string $subject           Email subject
     * @param string $message           Email message
     * @param string $notification_type Notification type
     */
    private static function queue_for_digest(int $user_id, string $email, string $subject, string $message, string $notification_type): void {
        $queue = get_option('sfs_hr_notification_digest_queue', []);

        $queue[] = [
            'user_id'           => $user_id,
            'email'             => $email,
            'subject'           => $subject,
            'message'           => $message,
            'notification_type' => $notification_type,
            'queued_at'         => current_time('mysql'),
        ];

        update_option('sfs_hr_notification_digest_queue', $queue);

        // Fire action for external digest handlers
        do_action('sfs_hr_notification_queued_for_digest', $user_id, $email, $subject, $message, $notification_type);
    }
}
