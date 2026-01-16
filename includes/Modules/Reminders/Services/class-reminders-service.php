<?php
namespace SFS\HR\Modules\Reminders\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Reminders Service
 * Business logic for birthday and anniversary reminders
 */
class Reminders_Service {

    /**
     * Get settings
     */
    public static function get_settings(): array {
        return [
            'notify_birthdays'        => (bool)get_option('sfs_hr_notify_birthdays', true),
            'birthday_days_before'    => array_map('intval', (array)get_option('sfs_hr_birthday_days', [0, 1, 7])),
            'notify_anniversaries'    => (bool)get_option('sfs_hr_notify_anniversaries', true),
            'anniversary_days_before' => array_map('intval', (array)get_option('sfs_hr_anniversary_days', [0, 1, 7])),
            'reminder_recipients'     => get_option('sfs_hr_reminder_recipients', 'managers'),
        ];
    }

    /**
     * Save settings
     */
    public static function save_settings(array $data): void {
        update_option('sfs_hr_notify_birthdays', isset($data['notify_birthdays']));
        update_option('sfs_hr_notify_anniversaries', isset($data['notify_anniversaries']));
        update_option('sfs_hr_reminder_recipients', sanitize_key($data['reminder_recipients'] ?? 'managers'));

        // Parse days arrays
        $birthday_days = array_filter(array_map('intval', array_map('trim', explode(',', $data['birthday_days'] ?? '0, 1, 7'))));
        $anniversary_days = array_filter(array_map('intval', array_map('trim', explode(',', $data['anniversary_days'] ?? '0, 1, 7'))));

        update_option('sfs_hr_birthday_days', $birthday_days ?: [0, 1, 7]);
        update_option('sfs_hr_anniversary_days', $anniversary_days ?: [0, 1, 7]);
    }

    /**
     * Get upcoming birthdays
     */
    public static function get_upcoming_birthdays(array $days_offsets = [0, 1, 7]): array {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Check if birth_date column exists
        if (!self::has_birth_date_column()) {
            return [];
        }

        $results = [];

        foreach ($days_offsets as $days) {
            $target_date = wp_date('Y-m-d', strtotime("+{$days} days"));
            $month_day = substr($target_date, 5); // MM-DD

            $employees = $wpdb->get_results($wpdb->prepare(
                "SELECT e.*, d.name AS department_name,
                        DATE_FORMAT(e.birth_date, '%%m-%%d') AS birth_month_day,
                        %d AS days_until
                 FROM {$emp_table} e
                 LEFT JOIN {$wpdb->prefix}sfs_hr_departments d ON d.id = e.dept_id
                 WHERE e.status = 'active'
                   AND e.birth_date IS NOT NULL
                   AND DATE_FORMAT(e.birth_date, '%%m-%%d') = %s",
                $days,
                $month_day
            ));

            if ($employees) {
                $results = array_merge($results, $employees);
            }
        }

        return $results;
    }

    /**
     * Get upcoming work anniversaries
     */
    public static function get_upcoming_anniversaries(array $days_offsets = [0, 1, 7]): array {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $results = [];

        foreach ($days_offsets as $days) {
            $target_date = wp_date('Y-m-d', strtotime("+{$days} days"));
            $month_day = substr($target_date, 5); // MM-DD

            $employees = $wpdb->get_results($wpdb->prepare(
                "SELECT e.*, d.name AS department_name,
                        DATE_FORMAT(e.hired_at, '%%m-%%d') AS hire_month_day,
                        %d AS days_until,
                        TIMESTAMPDIFF(YEAR, e.hired_at, %s) + 1 AS years_completing
                 FROM {$emp_table} e
                 LEFT JOIN {$wpdb->prefix}sfs_hr_departments d ON d.id = e.dept_id
                 WHERE e.status = 'active'
                   AND e.hired_at IS NOT NULL
                   AND DATE_FORMAT(e.hired_at, '%%m-%%d') = %s
                   AND TIMESTAMPDIFF(YEAR, e.hired_at, %s) >= 0",
                $days,
                $target_date,
                $month_day,
                $target_date
            ));

            if ($employees) {
                $results = array_merge($results, $employees);
            }
        }

        return $results;
    }

    /**
     * Get birthdays for a specific month
     */
    public static function get_birthdays_for_month(int $month): array {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        if (!self::has_birth_date_column()) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, d.name AS department_name, DAY(e.birth_date) AS day_of_month
             FROM {$emp_table} e
             LEFT JOIN {$dept_table} d ON d.id = e.dept_id
             WHERE e.status = 'active'
               AND e.birth_date IS NOT NULL
               AND MONTH(e.birth_date) = %d
             ORDER BY DAY(e.birth_date)",
            $month
        ));
    }

    /**
     * Get anniversaries for a specific month
     */
    public static function get_anniversaries_for_month(int $month, int $year): array {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, d.name AS department_name, DAY(e.hired_at) AS day_of_month,
                    TIMESTAMPDIFF(YEAR, e.hired_at, CONCAT(%d, '-', LPAD(%d, 2, '0'), '-', LPAD(DAY(e.hired_at), 2, '0'))) + 1 AS years_completing
             FROM {$emp_table} e
             LEFT JOIN {$dept_table} d ON d.id = e.dept_id
             WHERE e.status = 'active'
               AND e.hired_at IS NOT NULL
               AND MONTH(e.hired_at) = %d
             ORDER BY DAY(e.hired_at)",
            $year,
            $month,
            $month
        ));
    }

    /**
     * Get notification recipients
     */
    public static function get_notification_recipients($employee, string $recipient_type): array {
        $emails = [];

        switch ($recipient_type) {
            case 'all_hr':
                $users = get_users(['role' => 'sfs_hr_manager']);
                foreach ($users as $user) {
                    $emails[] = $user->user_email;
                }
                $admins = get_users(['role' => 'administrator']);
                foreach ($admins as $admin) {
                    $emails[] = $admin->user_email;
                }
                break;

            case 'dept_manager':
                if ($employee->dept_id) {
                    $manager_email = self::get_department_manager_email($employee->dept_id);
                    if ($manager_email) {
                        $emails[] = $manager_email;
                    }
                }
                break;

            case 'managers':
            default:
                $users = get_users(['role' => 'sfs_hr_manager']);
                foreach ($users as $user) {
                    $emails[] = $user->user_email;
                }
                if ($employee->dept_id) {
                    $manager_email = self::get_department_manager_email($employee->dept_id);
                    if ($manager_email) {
                        $emails[] = $manager_email;
                    }
                }
                break;
        }

        return array_unique(array_filter($emails));
    }

    /**
     * Get department manager email
     */
    private static function get_department_manager_email(int $dept_id): ?string {
        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $manager_user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT manager_user_id FROM {$dept_table} WHERE id = %d",
            $dept_id
        ));

        if ($manager_user_id) {
            $user = get_userdata($manager_user_id);
            if ($user) {
                return $user->user_email;
            }
        }

        return null;
    }

    /**
     * Get upcoming events count
     */
    public static function get_upcoming_count(int $days = 7): int {
        $birthdays = self::get_upcoming_birthdays(range(0, $days));
        $anniversaries = self::get_upcoming_anniversaries(range(0, $days));

        return count($birthdays) + count($anniversaries);
    }

    /**
     * Check if birth_date column exists
     */
    public static function has_birth_date_column(): bool {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'birth_date'",
            $emp_table
        ));
    }

    /**
     * Get month names array
     */
    public static function get_month_names(): array {
        return [
            1  => __('January', 'sfs-hr'),
            2  => __('February', 'sfs-hr'),
            3  => __('March', 'sfs-hr'),
            4  => __('April', 'sfs-hr'),
            5  => __('May', 'sfs-hr'),
            6  => __('June', 'sfs-hr'),
            7  => __('July', 'sfs-hr'),
            8  => __('August', 'sfs-hr'),
            9  => __('September', 'sfs-hr'),
            10 => __('October', 'sfs-hr'),
            11 => __('November', 'sfs-hr'),
            12 => __('December', 'sfs-hr'),
        ];
    }
}
