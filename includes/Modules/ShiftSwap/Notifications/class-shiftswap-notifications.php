<?php
namespace SFS\HR\Modules\ShiftSwap\Notifications;

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

        if ($target && $target->user_email && class_exists('\SFS\HR\Core\Notifications')) {
            \SFS\HR\Core\Notifications::send_email(
                $target->user_email,
                __('Shift Swap Request', 'sfs-hr'),
                sprintf(
                    __('You have received a shift swap request. Please review it in your HR profile: %s', 'sfs-hr'),
                    admin_url('admin.php?page=sfs-hr-my-profile&tab=shift_swap')
                )
            );
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

        if ($requester && $requester->user_email && class_exists('\SFS\HR\Core\Notifications')) {
            $subject = ($response === 'accept')
                ? __('Shift Swap Accepted', 'sfs-hr')
                : __('Shift Swap Declined', 'sfs-hr');

            $message = ($response === 'accept')
                ? __('Your shift swap request has been accepted and is now awaiting manager approval.', 'sfs-hr')
                : __('Your shift swap request has been declined.', 'sfs-hr');

            \SFS\HR\Core\Notifications::send_email($requester->user_email, $subject, $message);
        }
    }
}
