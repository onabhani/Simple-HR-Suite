<?php
namespace SFS\HR\Modules\ShiftSwap\Notifications;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\ShiftSwap\Services\ShiftSwap_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Shift Swap Notifications
 * Email notifications for shift swap events
 */
class ShiftSwap_Notifications {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('sfs_hr_shift_swap_requested', [$this, 'notify_swap_requested'], 10, 2);
        add_action('sfs_hr_shift_swap_responded', [$this, 'notify_swap_responded'], 10, 2);
    }

    /**
     * Notify target employee of swap request
     */
    public function notify_swap_requested(int $swap_id, int $target_id): void {
        $target = ShiftSwap_Service::get_employee_with_email($target_id);

        if ($target && $target->user_email) {
            $subject = __('Shift Swap Request', 'sfs-hr');
            $message = sprintf(
                __('You have received a shift swap request. Please review it in your HR profile: %s', 'sfs-hr'),
                admin_url('admin.php?page=sfs-hr-my-profile&tab=shift_swap')
            );

            $user_id = isset($target->user_id) ? (int) $target->user_id : 0;
            self::send_notification($user_id, $target->user_email, $subject, $message, 'shift_swap_requested');
        }
    }

    /**
     * Notify requester of swap response
     */
    public function notify_swap_responded(int $swap_id, string $response): void {
        $swap = ShiftSwap_Service::get_swap($swap_id);
        if (!$swap) {
            return;
        }

        $requester = ShiftSwap_Service::get_employee_with_email($swap->requester_id);

        if ($requester && $requester->user_email) {
            $subject = ($response === 'accept')
                ? __('Shift Swap Accepted', 'sfs-hr')
                : __('Shift Swap Declined', 'sfs-hr');

            $message = ($response === 'accept')
                ? __('Your shift swap request has been accepted and is now awaiting manager approval.', 'sfs-hr')
                : __('Your shift swap request has been declined.', 'sfs-hr');

            $notification_type = ($response === 'accept') ? 'shift_swap_accepted' : 'shift_swap_declined';
            $user_id = isset($requester->user_id) ? (int) $requester->user_id : 0;
            self::send_notification($user_id, $requester->user_email, $subject, $message, $notification_type);
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
