<?php
namespace SFS\HR\Modules\Reminders\Cron;

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
            $this->send_notification('birthday', $employee);
        }
    }

    /**
     * Send anniversary reminders
     */
    private function send_anniversary_reminders(array $days_before): void {
        $anniversaries = Reminders_Service::get_upcoming_anniversaries($days_before);

        foreach ($anniversaries as $employee) {
            $this->send_notification('anniversary', $employee);
        }
    }

    /**
     * Send a reminder notification
     */
    private function send_notification(string $type, $employee): void {
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

        foreach ($recipients as $email) {
            if (is_email($email) && class_exists('\SFS\HR\Core\Notifications')) {
                \SFS\HR\Core\Notifications::send_email($email, $message_parts['subject'], $message);
            }
        }
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
