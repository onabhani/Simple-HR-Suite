<?php
namespace SFS\HR\Modules\Reminders;

if (!defined('ABSPATH')) { exit; }

// Load submodules
require_once __DIR__ . '/Services/class-reminders-service.php';
require_once __DIR__ . '/Admin/class-dashboard-widget.php';
require_once __DIR__ . '/Admin/class-celebrations-page.php';
require_once __DIR__ . '/Cron/class-reminders-cron.php';

use SFS\HR\Modules\Reminders\Services\Reminders_Service;
use SFS\HR\Modules\Reminders\Admin\Dashboard_Widget;
use SFS\HR\Modules\Reminders\Admin\Celebrations_Page;
use SFS\HR\Modules\Reminders\Cron\Reminders_Cron;

/**
 * Reminders Module
 * Automatic birthday and work anniversary notifications
 *
 * Structure:
 * - Services/   Business logic and data access
 * - Admin/      Dashboard widget, celebrations page
 * - Cron/       Scheduled notification jobs
 *
 * Version: 0.2.0
 * Author: hdqah.com
 */
class RemindersModule {

    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register all hooks
     */
    public function hooks(): void {
        // Initialize submodules
        (new Dashboard_Widget())->hooks();
        (new Celebrations_Page())->hooks();
        (new Reminders_Cron())->hooks();

        // Settings integration
        add_filter('sfs_hr_notification_settings_fields', [$this, 'add_settings_fields']);
        add_action('sfs_hr_save_notification_settings', [Reminders_Service::class, 'save_settings']);
    }

    /**
     * Add settings fields to notifications settings
     */
    public function add_settings_fields(array $fields): array {
        $fields['birthday_anniversary_header'] = [
            'type'  => 'header',
            'label' => __('Birthday & Anniversary Reminders', 'sfs-hr'),
        ];

        $fields['notify_birthdays'] = [
            'type'    => 'checkbox',
            'label'   => __('Send Birthday Reminders', 'sfs-hr'),
            'default' => true,
        ];

        $fields['birthday_days'] = [
            'type'        => 'text',
            'label'       => __('Birthday Reminder Days', 'sfs-hr'),
            'description' => __('Days before birthday to send reminders (comma-separated, e.g., 0, 1, 7)', 'sfs-hr'),
            'default'     => '0, 1, 7',
        ];

        $fields['notify_anniversaries'] = [
            'type'    => 'checkbox',
            'label'   => __('Send Work Anniversary Reminders', 'sfs-hr'),
            'default' => true,
        ];

        $fields['anniversary_days'] = [
            'type'        => 'text',
            'label'       => __('Anniversary Reminder Days', 'sfs-hr'),
            'description' => __('Days before anniversary to send reminders (comma-separated)', 'sfs-hr'),
            'default'     => '0, 1, 7',
        ];

        $fields['reminder_recipients'] = [
            'type'    => 'select',
            'label'   => __('Send Reminders To', 'sfs-hr'),
            'options' => [
                'managers'     => __('HR Managers + Department Manager', 'sfs-hr'),
                'dept_manager' => __('Department Manager Only', 'sfs-hr'),
                'all_hr'       => __('All HR Managers + Administrators', 'sfs-hr'),
            ],
            'default' => 'managers',
        ];

        return $fields;
    }

    // =========================================================================
    // Backwards compatibility - delegate to Services class
    // =========================================================================

    /**
     * @deprecated Use Reminders_Service::get_upcoming_birthdays() instead
     */
    public function get_upcoming_birthdays(array $days_offsets = [0, 1, 7]): array {
        return Reminders_Service::get_upcoming_birthdays($days_offsets);
    }

    /**
     * @deprecated Use Reminders_Service::get_upcoming_anniversaries() instead
     */
    public function get_upcoming_anniversaries(array $days_offsets = [0, 1, 7]): array {
        return Reminders_Service::get_upcoming_anniversaries($days_offsets);
    }

    /**
     * @deprecated Use Reminders_Service::get_upcoming_count() instead
     */
    public static function get_upcoming_count(int $days = 7): int {
        return Reminders_Service::get_upcoming_count($days);
    }
}
