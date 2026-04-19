<?php
namespace SFS\HR\Modules\Resignation\Services;

use SFS\HR\Modules\EmployeeExit\EmployeeExitModule;

if (!defined('ABSPATH')) { exit; }

/**
 * Notice Service
 *
 * Manages notice period enforcement for employee resignations:
 * - Notice period configuration per employment type
 * - Auto-calculation of last working day (accounting for weekends/holidays)
 * - Notice period buyout calculations
 * - Garden leave tracking
 * - Notice period adjustments
 */
class Notice_Service {

    /** @var string Option key for notice period configuration */
    private const CONFIG_OPTION = 'sfs_hr_notice_period_config';

    /** @var string Option key for holidays */
    private const HOLIDAYS_OPTION = 'sfs_hr_holidays';

    // ─── Notice Period Configuration ────────────────────────────────────

    /**
     * Get notice period configuration.
     *
     * @return array Array of employment type => notice days mappings.
     */
    public static function get_notice_config(): array {
        $config = get_option(self::CONFIG_OPTION, '');

        if (empty($config)) {
            return self::get_default_config();
        }

        $decoded = is_string($config) ? json_decode($config, true) : $config;

        return is_array($decoded) ? $decoded : self::get_default_config();
    }

    /**
     * Save notice period configuration.
     *
     * @param array $config Array of objects with employment_type and notice_days.
     * @return array { success: bool, error?: string }
     */
    public static function save_notice_config(array $config): array {
        if (empty($config)) {
            return ['success' => false, 'error' => __('Configuration cannot be empty.', 'sfs-hr')];
        }

        foreach ($config as $entry) {
            if (!isset($entry['employment_type']) || !isset($entry['notice_days'])) {
                return ['success' => false, 'error' => __('Each entry must have employment_type and notice_days.', 'sfs-hr')];
            }
            if (!is_numeric($entry['notice_days']) || (int) $entry['notice_days'] < 0) {
                return ['success' => false, 'error' => __('Notice days must be a non-negative integer.', 'sfs-hr')];
            }
        }

        $saved = update_option(self::CONFIG_OPTION, wp_json_encode($config));

        return ['success' => true];
    }

    /**
     * Get notice days for a specific employee based on their contract type.
     *
     * @param int $employee_id Employee ID.
     * @return int Notice days for the employee's employment type.
     */
    public static function get_notice_days_for_employee(int $employee_id): int {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_employees';
        $contract_type = $wpdb->get_var($wpdb->prepare(
            "SELECT contract_type FROM {$table} WHERE id = %d",
            $employee_id
        ));

        if (!$contract_type) {
            // Default to permanent if no contract type set
            $contract_type = 'permanent';
        }

        $config = self::get_notice_config();

        foreach ($config as $entry) {
            if ($entry['employment_type'] === $contract_type) {
                return (int) $entry['notice_days'];
            }
        }

        // Default 30 days if no matching config found
        return 30;
    }

    // ─── Last Working Day Calculation ───────────────────────────────────

    /**
     * Calculate last working day accounting for weekends and holidays.
     *
     * Counts only business days (excludes Fridays, Saturdays, and holidays)
     * starting from the day after the resignation date.
     *
     * @param string $resignation_date Date of resignation (Y-m-d).
     * @param int    $notice_days      Number of notice days to serve.
     * @return string Last working day (Y-m-d).
     */
    public static function calculate_last_working_day(string $resignation_date, int $notice_days): string {
        if ($notice_days <= 0) {
            return $resignation_date;
        }

        $holidays = self::get_holidays();
        $current  = new \DateTime($resignation_date);
        $counted  = 0;

        while ($counted < $notice_days) {
            $current->modify('+1 day');

            $day_of_week = (int) $current->format('N'); // 5 = Friday, 6 = Saturday
            $date_str    = $current->format('Y-m-d');

            // Skip weekends (Friday & Saturday for Saudi work week)
            if ($day_of_week === 5 || $day_of_week === 6) {
                continue;
            }

            // Skip holidays
            if (in_array($date_str, $holidays, true)) {
                continue;
            }

            $counted++;
        }

        return $current->format('Y-m-d');
    }

    // ─── Notice Period Buyout ───────────────────────────────────────────

    /**
     * Calculate buyout amount for remaining notice period days.
     *
     * Daily rate = basic_salary / 30.
     *
     * @param int $employee_id    Employee ID.
     * @param int $remaining_days Number of remaining notice days.
     * @return array { amount: float, daily_rate: float, remaining_days: int } or { success: false, error: string }
     */
    public static function calculate_buyout_amount(int $employee_id, int $remaining_days): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_employees';
        $basic_salary = $wpdb->get_var($wpdb->prepare(
            "SELECT basic_salary FROM {$table} WHERE id = %d",
            $employee_id
        ));

        if ($basic_salary === null) {
            return ['success' => false, 'error' => __('Employee not found or salary not set.', 'sfs-hr')];
        }

        $basic_salary = (float) $basic_salary;
        $daily_rate   = $basic_salary / 30;
        $amount       = $daily_rate * $remaining_days;

        return [
            'amount'         => round($amount, 2),
            'daily_rate'     => round($daily_rate, 2),
            'remaining_days' => $remaining_days,
        ];
    }

    /**
     * Process notice period buyout.
     *
     * Updates the resignation's last_working_day to today and logs the event.
     *
     * @param int $resignation_id Resignation record ID.
     * @param int $processed_by   User ID of the person processing the buyout.
     * @return array { success: bool, last_working_day?: string, buyout?: array, error?: string }
     */
    public static function process_buyout(int $resignation_id, int $processed_by): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$resignation) {
            return ['success' => false, 'error' => __('Resignation not found.', 'sfs-hr')];
        }

        $today = current_time('Y-m-d');

        // Calculate remaining days
        $last_working_day = $resignation['last_working_day'];
        if (empty($last_working_day) || $last_working_day <= $today) {
            return ['success' => false, 'error' => __('No remaining notice period to buy out.', 'sfs-hr')];
        }

        $remaining_days = self::count_business_days($today, $last_working_day);

        // Calculate buyout amount
        $buyout = self::calculate_buyout_amount((int) $resignation['employee_id'], $remaining_days);
        if (isset($buyout['success']) && $buyout['success'] === false) {
            return $buyout;
        }

        // Update last_working_day to today
        $updated = $wpdb->update(
            $table,
            ['last_working_day' => $today],
            ['id' => $resignation_id],
            ['%s'],
            ['%d']
        );

        if ($updated === false) {
            return ['success' => false, 'error' => __('Failed to update resignation record.', 'sfs-hr')];
        }

        // Log the buyout event
        EmployeeExitModule::log_resignation_event($resignation_id, 'notice_buyout', [
            'processed_by'       => $processed_by,
            'original_lwd'       => $last_working_day,
            'new_lwd'            => $today,
            'remaining_days'     => $remaining_days,
            'buyout_amount'      => $buyout['amount'],
            'daily_rate'         => $buyout['daily_rate'],
        ]);

        return [
            'success'          => true,
            'last_working_day' => $today,
            'buyout'           => $buyout,
        ];
    }

    // ─── Garden Leave ───────────────────────────────────────────────────

    /**
     * Set garden leave period for a resignation.
     *
     * @param int    $resignation_id Resignation record ID.
     * @param string $start          Garden leave start date (Y-m-d).
     * @param string $end            Garden leave end date (Y-m-d).
     * @param int    $set_by         User ID of the person setting garden leave.
     * @return array { success: bool, error?: string }
     */
    public static function set_garden_leave(int $resignation_id, string $start, string $end, int $set_by): array {
        global $wpdb;

        if ($start > $end) {
            return ['success' => false, 'error' => __('Garden leave start date must be before end date.', 'sfs-hr')];
        }

        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d",
            $resignation_id
        ));

        if (!$exists) {
            return ['success' => false, 'error' => __('Resignation not found.', 'sfs-hr')];
        }

        $updated = $wpdb->update(
            $table,
            [
                'garden_leave_start' => $start,
                'garden_leave_end'   => $end,
            ],
            ['id' => $resignation_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return ['success' => false, 'error' => __('Failed to update garden leave dates.', 'sfs-hr')];
        }

        EmployeeExitModule::log_resignation_event($resignation_id, 'garden_leave_set', [
            'start'  => $start,
            'end'    => $end,
            'set_by' => $set_by,
        ]);

        return ['success' => true];
    }

    /**
     * Get garden leave details for a resignation.
     *
     * @param int $resignation_id Resignation record ID.
     * @return array|null { start: string, end: string } or null if not set.
     */
    public static function get_garden_leave(int $resignation_id): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT garden_leave_start, garden_leave_end FROM {$table} WHERE id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$row || empty($row['garden_leave_start']) || empty($row['garden_leave_end'])) {
            return null;
        }

        return [
            'start' => $row['garden_leave_start'],
            'end'   => $row['garden_leave_end'],
        ];
    }

    /**
     * Check if an employee is currently on garden leave.
     *
     * @param int $employee_id Employee ID.
     * @return bool True if employee is on garden leave today.
     */
    public static function is_on_garden_leave(int $employee_id): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $today = current_time('Y-m-d');

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE employee_id = %d
               AND garden_leave_start IS NOT NULL
               AND garden_leave_end IS NOT NULL
               AND %s BETWEEN garden_leave_start AND garden_leave_end",
            $employee_id,
            $today
        ));

        return (int) $result > 0;
    }

    // ─── Notice Period Adjustment ───────────────────────────────────────

    /**
     * Adjust the notice period for a resignation.
     *
     * Recalculates last_working_day based on the new notice days and logs to exit history.
     *
     * @param int    $resignation_id Resignation record ID.
     * @param int    $new_notice_days New notice period in days.
     * @param string $reason          Reason for the adjustment.
     * @param int    $adjusted_by     User ID of the person making the adjustment.
     * @return array { success: bool, last_working_day?: string, error?: string }
     */
    public static function adjust_notice_period(int $resignation_id, int $new_notice_days, string $reason, int $adjusted_by): array {
        global $wpdb;

        if ($new_notice_days < 0) {
            return ['success' => false, 'error' => __('Notice days cannot be negative.', 'sfs-hr')];
        }

        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$resignation) {
            return ['success' => false, 'error' => __('Resignation not found.', 'sfs-hr')];
        }

        $old_notice_days = (int) $resignation['notice_period_days'];
        $old_lwd         = $resignation['last_working_day'];

        // Recalculate last working day
        $new_lwd = self::calculate_last_working_day($resignation['resignation_date'], $new_notice_days);

        $updated = $wpdb->update(
            $table,
            [
                'notice_period_days' => $new_notice_days,
                'last_working_day'   => $new_lwd,
            ],
            ['id' => $resignation_id],
            ['%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return ['success' => false, 'error' => __('Failed to update notice period.', 'sfs-hr')];
        }

        EmployeeExitModule::log_resignation_event($resignation_id, 'notice_period_adjusted', [
            'old_notice_days' => $old_notice_days,
            'new_notice_days' => $new_notice_days,
            'old_lwd'         => $old_lwd,
            'new_lwd'         => $new_lwd,
            'reason'          => $reason,
            'adjusted_by'     => $adjusted_by,
        ]);

        return [
            'success'          => true,
            'last_working_day' => $new_lwd,
        ];
    }

    // ─── Private Helpers ────────────────────────────────────────────────

    /**
     * Get default notice period configuration.
     *
     * @return array Default config entries.
     */
    private static function get_default_config(): array {
        return [
            ['employment_type' => 'probation', 'notice_days' => 0],
            ['employment_type' => 'permanent', 'notice_days' => 30],
            ['employment_type' => 'contract',  'notice_days' => 60],
        ];
    }

    /**
     * Get holidays from settings.
     *
     * @return array Array of holiday dates in Y-m-d format.
     */
    private static function get_holidays(): array {
        $holidays = get_option(self::HOLIDAYS_OPTION, '');

        if (empty($holidays)) {
            return [];
        }

        $decoded = is_string($holidays) ? json_decode($holidays, true) : $holidays;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Count business days between two dates (exclusive of start, inclusive of end).
     *
     * @param string $start Start date (Y-m-d).
     * @param string $end   End date (Y-m-d).
     * @return int Number of business days.
     */
    private static function count_business_days(string $start, string $end): int {
        $holidays = self::get_holidays();
        $current  = new \DateTime($start);
        $end_dt   = new \DateTime($end);
        $count    = 0;

        while ($current < $end_dt) {
            $current->modify('+1 day');

            $day_of_week = (int) $current->format('N');
            $date_str    = $current->format('Y-m-d');

            if ($day_of_week === 5 || $day_of_week === 6) {
                continue;
            }

            if (in_array($date_str, $holidays, true)) {
                continue;
            }

            $count++;
        }

        return $count;
    }
}
