<?php
namespace SFS\HR\Modules\Reminders\Cron;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Reminders\Services\Reminders_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Reminders Cron
 * Handles scheduled reminder notifications
 */
class Reminders_Cron {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('init', [$this, 'schedule']);
        add_action('sfs_hr_daily_reminders', [$this, 'run']);
    }

    /**
     * Schedule cron job
     */
    public function schedule(): void {
        if (!wp_next_scheduled('sfs_hr_daily_reminders')) {
            // Schedule for 8 AM local time
            $timestamp = strtotime('today 08:00:00', current_time('timestamp'));
            if ($timestamp < current_time('timestamp')) {
                $timestamp = strtotime('tomorrow 08:00:00', current_time('timestamp'));
            }
            wp_schedule_event($timestamp, 'daily', 'sfs_hr_daily_reminders');
        }
    }

    /**
     * Run daily reminders
     */
    public function run(): void {
        $settings = Reminders_Service::get_settings();

        if ($settings['notify_birthdays']) {
            $this->send_birthday_reminders($settings['birthday_days_before']);
        }

        if ($settings['notify_anniversaries']) {
            $this->send_anniversary_reminders($settings['anniversary_days_before']);
        }
    }

    /**
     * Send birthday reminders
     */
    private function send_birthday_reminders(array $days_before): void {
        $birthdays = Reminders_Service::get_upcoming_birthdays($days_before);

        foreach ($birthdays as $employee) {
            $this->send_reminder_notification('birthday', $employee);
        }
    }

    /**
     * Send anniversary reminders
     */
    private function send_anniversary_reminders(array $days_before): void {
        $anniversaries = Reminders_Service::get_upcoming_anniversaries($days_before);

        foreach ($anniversaries as $employee) {
            $this->send_reminder_notification('anniversary', $employee);
        }
    }

    /**
     * Send a reminder notification
     */
    private function send_reminder_notification(string $type, $employee): void {
        $settings = Reminders_Service::get_settings();
        $recipients = Reminders_Service::get_notification_recipients($employee, $settings['reminder_recipients']);

        if (empty($recipients)) {
            return;
        }

        $name = trim($employee->first_name . ' ' . $employee->last_name);
        $message_parts = $this->build_message($type, $employee, $name);

        $message = $message_parts['body'];
        $message .= "\n\n" . sprintf(__("Department: %s", 'sfs-hr'), $employee->department_name ?: __('General', 'sfs-hr'));
        $message .= "\n" . sprintf(__("Employee Code: %s", 'sfs-hr'), $employee->employee_code ?: 'N/A');

        $notification_type = ($type === 'birthday') ? 'reminder_birthday' : 'reminder_anniversary';

        foreach ($recipients as $email) {
            if (is_email($email)) {
                // Get user_id from email if possible
                $user = get_user_by('email', $email);
                $user_id = $user ? $user->ID : 0;

                $this->send_notification_with_preferences($user_id, $email, $message_parts['subject'], $message, $notification_type);
            }
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
    private function send_notification_with_preferences(int $user_id, string $email, string $subject, string $message, string $notification_type): void {
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
            $this->queue_for_digest($user_id, $email, $subject, $message, $notification_type);
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
    private function queue_for_digest(int $user_id, string $email, string $subject, string $message, string $notification_type): void {
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

    /**
     * Build notification message
     */
    private function build_message(string $type, $employee, string $name): array {
        if ($type === 'birthday') {
            $subject = sprintf(__("Birthday Reminder: %s", 'sfs-hr'), $name);

            if ($employee->days_until == 0) {
                $body = sprintf(
                    __("Today is %s's birthday! Don't forget to wish them a happy birthday.", 'sfs-hr'),
                    $name
                );
            } else {
                $body = sprintf(
                    __("%s's birthday is coming up in %d day(s) on %s.", 'sfs-hr'),
                    $name,
                    $employee->days_until,
                    date_i18n(get_option('date_format'), strtotime("+{$employee->days_until} days"))
                );
            }
        } else {
            $years = isset($employee->years_completing) ? (int)$employee->years_completing : 1;
            $subject = sprintf(__("Work Anniversary: %s - %d Year(s)", 'sfs-hr'), $name, $years);

            if ($employee->days_until == 0) {
                $body = sprintf(
                    __("Today marks %s's %d year work anniversary! Congratulations!", 'sfs-hr'),
                    $name,
                    $years
                );
            } else {
                $body = sprintf(
                    __("%s will complete %d year(s) with the company in %d day(s) on %s.", 'sfs-hr'),
                    $name,
                    $years,
                    $employee->days_until,
                    date_i18n(get_option('date_format'), strtotime("+{$employee->days_until} days"))
                );
            }
        }

        return ['subject' => $subject, 'body' => $body];
    }
}
