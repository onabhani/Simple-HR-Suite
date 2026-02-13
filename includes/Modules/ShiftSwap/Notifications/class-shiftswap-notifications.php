<?php
namespace SFS\HR\Modules\ShiftSwap\Notifications;

use SFS\HR\Core\Helpers;
use SFS\HR\Core\Notifications as CoreNotifications;
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
            $user_id = isset($target->user_id) ? (int) $target->user_id : 0;
            self::send_notification_localized($user_id, $target->user_email, function () {
                return [
                    'subject' => __('Shift Swap Request', 'sfs-hr'),
                    'message' => sprintf(
                        __('You have received a shift swap request. Please review it in your HR profile: %s', 'sfs-hr'),
                        admin_url('admin.php?page=sfs-hr-my-profile&tab=shift_swap')
                    ),
                ];
            }, 'shift_swap_requested');
        }

        // Also notify HR
        $swap = ShiftSwap_Service::get_swap($swap_id);
        if ($swap) {
            $requester = ShiftSwap_Service::get_employee_with_email($swap->requester_id);
            self::notify_hr_shift_swap_event($swap, $requester, $target, 'requested');
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
        $target = ShiftSwap_Service::get_employee_with_email($swap->target_id);

        if ($requester && $requester->user_email) {
            $notification_type = ($response === 'accept') ? 'shift_swap_accepted' : 'shift_swap_declined';
            $user_id = isset($requester->user_id) ? (int) $requester->user_id : 0;
            self::send_notification_localized($user_id, $requester->user_email, function () use ($response) {
                return [
                    'subject' => ($response === 'accept')
                        ? __('Shift Swap Accepted', 'sfs-hr')
                        : __('Shift Swap Declined', 'sfs-hr'),
                    'message' => ($response === 'accept')
                        ? __('Your shift swap request has been accepted and is now awaiting manager approval.', 'sfs-hr')
                        : __('Your shift swap request has been declined.', 'sfs-hr'),
                ];
            }, $notification_type);
        }

        // Also notify HR
        $event = ($response === 'accept') ? 'accepted' : 'declined';
        self::notify_hr_shift_swap_event($swap, $requester, $target, $event);
    }

    /**
     * Notify HR team about shift swap events
     *
     * @param object      $swap      Swap data
     * @param object|null $requester Requester employee data
     * @param object|null $target    Target employee data
     * @param string      $event     Event type: requested, accepted, declined
     */
    private static function notify_hr_shift_swap_event($swap, $requester, $target, string $event): void {
        // Get HR emails from Core settings
        $core_settings = CoreNotifications::get_settings();
        $hr_emails = $core_settings['hr_emails'] ?? [];

        if (empty($hr_emails) || !($core_settings['hr_notification'] ?? true)) {
            return;
        }

        $requester_name = $requester ? trim(($requester->first_name ?? '') . ' ' . ($requester->last_name ?? '')) : __('Unknown', 'sfs-hr');
        $target_name = $target ? trim(($target->first_name ?? '') . ' ' . ($target->last_name ?? '')) : __('Unknown', 'sfs-hr');

        switch ($event) {
            case 'requested':
                $subject = sprintf(__('[HR Notice] Shift swap requested by %s', 'sfs-hr'), $requester_name);
                $message = sprintf(
                    __("A shift swap has been requested.\n\nRequester: %s\nTarget Employee: %s\nRequested Shift Date: %s\nTarget Shift Date: %s\nReason: %s", 'sfs-hr'),
                    $requester_name,
                    $target_name,
                    $swap->requester_shift_date ?? 'N/A',
                    $swap->target_shift_date ?? 'N/A',
                    $swap->reason ?? 'Not specified'
                );
                break;

            case 'accepted':
                $subject = sprintf(__('[HR Notice] Shift swap accepted by %s', 'sfs-hr'), $target_name);
                $message = sprintf(
                    __("A shift swap has been accepted.\n\nRequester: %s\nAccepted By: %s\nRequested Shift Date: %s\nTarget Shift Date: %s\n\nThis swap is now awaiting manager approval.", 'sfs-hr'),
                    $requester_name,
                    $target_name,
                    $swap->requester_shift_date ?? 'N/A',
                    $swap->target_shift_date ?? 'N/A'
                );
                break;

            case 'declined':
                $subject = sprintf(__('[HR Notice] Shift swap declined by %s', 'sfs-hr'), $target_name);
                $message = sprintf(
                    __("A shift swap has been declined.\n\nRequester: %s\nDeclined By: %s\nRequested Shift Date: %s\nTarget Shift Date: %s", 'sfs-hr'),
                    $requester_name,
                    $target_name,
                    $swap->requester_shift_date ?? 'N/A',
                    $swap->target_shift_date ?? 'N/A'
                );
                break;

            default:
                return;
        }

        // Send to all HR emails
        foreach ($hr_emails as $hr_email) {
            if (!is_email($hr_email)) {
                continue;
            }
            self::send_notification(0, $hr_email, $subject, $message, 'shift_swap_hr_notification');
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
