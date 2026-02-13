<?php
namespace SFS\HR\Modules\Resignation\Notifications;

use SFS\HR\Core\Helpers;
use SFS\HR\Core\Notifications as CoreNotifications;
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

        self::send_notification_localized((int) $dept['manager_user_id'], $approver->user_email, function () use ($resignation) {
            $message = sprintf(
                __('A resignation has been submitted by %s %s and requires your approval.', 'sfs-hr'),
                $resignation['first_name'],
                $resignation['last_name']
            );
            $message .= "\n\n" . __('Resignation Date:', 'sfs-hr') . ' ' . $resignation['resignation_date'];
            $message .= "\n" . __('Last Working Day:', 'sfs-hr') . ' ' . $resignation['last_working_day'];
            $message .= "\n\n" . __('Please log in to review and approve.', 'sfs-hr');
            return [ 'subject' => __('New Resignation Submitted', 'sfs-hr'), 'message' => $message ];
        }, 'resignation_submitted');

        // Also notify HR
        self::notify_hr_resignation_event($resignation, 'submitted', $resignation_id);
    }

    /**
     * Notify employee of approval
     */
    public static function notify_approval(int $resignation_id): void {
        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation || !$resignation['emp_user_id']) return;

        $employee = get_userdata($resignation['emp_user_id']);
        if (!$employee) return;

        self::send_notification_localized((int) $resignation['emp_user_id'], $employee->user_email, function () use ($resignation) {
            $message = sprintf( __('Dear %s,', 'sfs-hr'), $resignation['first_name'] );
            $message .= "\n\n" . __('Your resignation has been approved.', 'sfs-hr');
            $message .= "\n" . __('Last Working Day:', 'sfs-hr') . ' ' . $resignation['last_working_day'];
            return [ 'subject' => __('Your Resignation Has Been Approved', 'sfs-hr'), 'message' => $message ];
        }, 'resignation_approved');

        // Also notify HR
        self::notify_hr_resignation_event($resignation, 'approved', $resignation_id);
    }

    /**
     * Notify employee of rejection
     */
    public static function notify_rejection(int $resignation_id): void {
        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation || !$resignation['emp_user_id']) return;

        $employee = get_userdata($resignation['emp_user_id']);
        if (!$employee) return;

        self::send_notification_localized((int) $resignation['emp_user_id'], $employee->user_email, function () use ($resignation) {
            $message = sprintf( __('Dear %s,', 'sfs-hr'), $resignation['first_name'] );
            $message .= "\n\n" . __('Your resignation has been rejected.', 'sfs-hr');
            if (!empty($resignation['approver_note'])) {
                $message .= "\n" . __('Reason:', 'sfs-hr') . ' ' . $resignation['approver_note'];
            }
            return [ 'subject' => __('Your Resignation Has Been Rejected', 'sfs-hr'), 'message' => $message ];
        }, 'resignation_rejected');

        // Also notify HR
        self::notify_hr_resignation_event($resignation, 'rejected', $resignation_id);
    }

    /**
     * Notify next approver in chain
     */
    public static function notify_next_approver(int $resignation_id, int $approver_id): void {
        $resignation = Resignation_Service::get_resignation($resignation_id);
        if (!$resignation) return;

        $approver = get_userdata($approver_id);
        if (!$approver) return;

        self::send_notification_localized($approver_id, $approver->user_email, function () use ($resignation) {
            $message = sprintf(
                __('A resignation from %s %s is pending your approval.', 'sfs-hr'),
                $resignation['first_name'],
                $resignation['last_name']
            );
            $message .= "\n\n" . __('Resignation Date:', 'sfs-hr') . ' ' . $resignation['resignation_date'];
            $message .= "\n" . __('Last Working Day:', 'sfs-hr') . ' ' . $resignation['last_working_day'];
            $message .= "\n\n" . __('Please log in to review and approve.', 'sfs-hr');
            return [ 'subject' => __('Resignation Pending Your Approval', 'sfs-hr'), 'message' => $message ];
        }, 'resignation_pending_approval');
    }

    /**
     * Notify HR team about resignation events
     *
     * @param array  $resignation Resignation data
     * @param string $event       Event type: submitted, approved, rejected
     * @param int    $resignation_id Resignation ID
     */
    private static function notify_hr_resignation_event(array $resignation, string $event, int $resignation_id): void {
        // Get HR emails from Core settings
        $core_settings = CoreNotifications::get_settings();
        $hr_emails = $core_settings['hr_emails'] ?? [];

        if (empty($hr_emails) || !($core_settings['hr_notification'] ?? true)) {
            return;
        }

        // Send to all HR emails (content built inside callback for locale support)
        foreach ($hr_emails as $hr_email) {
            if (!is_email($hr_email)) {
                continue;
            }
            self::send_notification_localized(0, $hr_email, function () use ($resignation, $event, $resignation_id) {
                $employee_name = trim($resignation['first_name'] . ' ' . $resignation['last_name']);
                $admin_url = admin_url('admin.php?page=sfs-hr-lifecycle&tab=resignations&action=view&id=' . $resignation_id);

                switch ($event) {
                    case 'submitted':
                        return [
                            'subject' => sprintf(__('[HR Notice] New resignation submitted by %s', 'sfs-hr'), $employee_name),
                            'message' => sprintf(
                                __("A new resignation has been submitted.\n\nEmployee: %s\nEmployee Code: %s\nResignation Date: %s\nLast Working Day: %s\nType: %s\n\nReason:\n%s\n\nView details: %s", 'sfs-hr'),
                                $employee_name, $resignation['employee_code'] ?? 'N/A', $resignation['resignation_date'],
                                $resignation['last_working_day'], $resignation['resignation_type'] ?? 'regular',
                                $resignation['reason'] ?? 'Not specified', $admin_url
                            ),
                        ];
                    case 'approved':
                        return [
                            'subject' => sprintf(__('[HR Notice] Resignation approved for %s', 'sfs-hr'), $employee_name),
                            'message' => sprintf(
                                __("A resignation has been approved.\n\nEmployee: %s\nEmployee Code: %s\nLast Working Day: %s\n\nView details: %s", 'sfs-hr'),
                                $employee_name, $resignation['employee_code'] ?? 'N/A', $resignation['last_working_day'], $admin_url
                            ),
                        ];
                    case 'rejected':
                        return [
                            'subject' => sprintf(__('[HR Notice] Resignation rejected for %s', 'sfs-hr'), $employee_name),
                            'message' => sprintf(
                                __("A resignation has been rejected.\n\nEmployee: %s\nEmployee Code: %s\nRejection Reason: %s\n\nView details: %s", 'sfs-hr'),
                                $employee_name, $resignation['employee_code'] ?? 'N/A',
                                $resignation['approver_note'] ?? 'Not specified', $admin_url
                            ),
                        ];
                    default:
                        return [ 'subject' => '', 'message' => '' ];
                }
            }, 'resignation_hr_notification');
        }
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
     * Build email content in the recipient's preferred locale, then send.
     */
    private static function send_notification_localized(int $user_id, string $email, callable $build, string $notification_type): void {
        $locale   = Helpers::get_locale_for_email( $email );
        $switched = false;
        if ( $locale && $locale !== get_locale() && function_exists( 'switch_to_locale' ) ) {
            switch_to_locale( $locale );
            Helpers::reload_json_translations( $locale );
            $switched = true;
        }
        $data = $build();
        if ( $switched ) {
            restore_previous_locale();
            Helpers::reload_json_translations( determine_locale() );
        }
        if ( ! empty( $data['subject'] ) && ! empty( $data['message'] ) ) {
            self::send_notification( $user_id, $email, $data['subject'], $data['message'], $notification_type );
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
