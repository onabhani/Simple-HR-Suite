<?php
namespace SFS\HR\Modules\Reminders;

if (!defined('ABSPATH')) { exit; }

/**
 * Reminders Module
 * Automatic birthday and work anniversary notifications
 * Version: 0.1.0
 * Author: Omar Alnabhani (hdqah.com)
 */
class RemindersModule {

    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function hooks(): void {
        // Schedule daily cron job
        add_action('init', [$this, 'schedule_cron']);
        add_action('sfs_hr_daily_reminders', [$this, 'run_daily_reminders']);

        // Add reminders widget to HR Dashboard
        add_action('sfs_hr_dashboard_widgets', [$this, 'render_dashboard_widget'], 15);

        // Settings
        add_filter('sfs_hr_notification_settings_fields', [$this, 'add_settings_fields']);
        add_action('sfs_hr_save_notification_settings', [$this, 'save_settings']);

        // Admin submenu for viewing upcoming events
        add_action('admin_menu', [$this, 'add_admin_menu'], 50);
    }

    /**
     * Schedule cron job
     */
    public function schedule_cron(): void {
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
     * Run daily reminders check
     */
    public function run_daily_reminders(): void {
        $settings = $this->get_settings();

        if ($settings['notify_birthdays']) {
            $this->send_birthday_reminders($settings['birthday_days_before']);
        }

        if ($settings['notify_anniversaries']) {
            $this->send_anniversary_reminders($settings['anniversary_days_before']);
        }
    }

    /**
     * Get settings
     */
    private function get_settings(): array {
        return [
            'notify_birthdays' => (bool)get_option('sfs_hr_notify_birthdays', true),
            'birthday_days_before' => array_map('intval', (array)get_option('sfs_hr_birthday_days', [0, 1, 7])),
            'notify_anniversaries' => (bool)get_option('sfs_hr_notify_anniversaries', true),
            'anniversary_days_before' => array_map('intval', (array)get_option('sfs_hr_anniversary_days', [0, 1, 7])),
            'reminder_recipients' => get_option('sfs_hr_reminder_recipients', 'managers'),
        ];
    }

    /**
     * Send birthday reminders
     */
    private function send_birthday_reminders(array $days_before): void {
        $birthdays = $this->get_upcoming_birthdays($days_before);

        if (empty($birthdays)) {
            return;
        }

        foreach ($birthdays as $employee) {
            $this->send_reminder_notification('birthday', $employee);
        }
    }

    /**
     * Send anniversary reminders
     */
    private function send_anniversary_reminders(array $days_before): void {
        $anniversaries = $this->get_upcoming_anniversaries($days_before);

        if (empty($anniversaries)) {
            return;
        }

        foreach ($anniversaries as $employee) {
            $this->send_reminder_notification('anniversary', $employee);
        }
    }

    /**
     * Get upcoming birthdays
     */
    public function get_upcoming_birthdays(array $days_offsets = [0, 1, 7]): array {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Check if birth_date column exists
        $has_birth_date = (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'birth_date'",
            $emp_table
        ));

        if (!$has_birth_date) {
            return [];
        }

        $today = wp_date('Y-m-d');
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
    public function get_upcoming_anniversaries(array $days_offsets = [0, 1, 7]): array {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $today = wp_date('Y-m-d');
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
     * Send reminder notification
     */
    private function send_reminder_notification(string $type, $employee): void {
        $settings = $this->get_settings();
        $recipients = $this->get_notification_recipients($employee, $settings['reminder_recipients']);

        if (empty($recipients)) {
            return;
        }

        $name = trim($employee->first_name . ' ' . $employee->last_name);

        if ($type === 'birthday') {
            $subject = sprintf(__("Birthday Reminder: %s", 'sfs-hr'), $name);

            if ($employee->days_until == 0) {
                $message = sprintf(
                    __("Today is %s's birthday! Don't forget to wish them a happy birthday.", 'sfs-hr'),
                    $name
                );
            } else {
                $message = sprintf(
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
                $message = sprintf(
                    __("Today marks %s's %d year work anniversary! Congratulations!", 'sfs-hr'),
                    $name,
                    $years
                );
            } else {
                $message = sprintf(
                    __("%s will complete %d year(s) with the company in %d day(s) on %s.", 'sfs-hr'),
                    $name,
                    $years,
                    $employee->days_until,
                    date_i18n(get_option('date_format'), strtotime("+{$employee->days_until} days"))
                );
            }
        }

        $message .= "\n\n" . sprintf(__("Department: %s", 'sfs-hr'), $employee->department_name ?: __('General', 'sfs-hr'));
        $message .= "\n" . sprintf(__("Employee Code: %s", 'sfs-hr'), $employee->employee_code ?: 'N/A');

        foreach ($recipients as $email) {
            if (is_email($email) && class_exists('\SFS\HR\Core\Notifications')) {
                \SFS\HR\Core\Notifications::send_email($email, $subject, $message);
            }
        }
    }

    /**
     * Get notification recipients
     */
    private function get_notification_recipients($employee, string $recipient_type): array {
        $emails = [];

        switch ($recipient_type) {
            case 'all_hr':
                // Get all HR managers
                $users = get_users(['role' => 'sfs_hr_manager']);
                foreach ($users as $user) {
                    $emails[] = $user->user_email;
                }
                // Also add administrators
                $admins = get_users(['role' => 'administrator']);
                foreach ($admins as $admin) {
                    $emails[] = $admin->user_email;
                }
                break;

            case 'dept_manager':
                // Get department manager
                if ($employee->dept_id) {
                    global $wpdb;
                    $dept_table = $wpdb->prefix . 'sfs_hr_departments';
                    $manager_user_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT manager_user_id FROM {$dept_table} WHERE id = %d",
                        $employee->dept_id
                    ));
                    if ($manager_user_id) {
                        $user = get_userdata($manager_user_id);
                        if ($user) {
                            $emails[] = $user->user_email;
                        }
                    }
                }
                break;

            case 'managers':
            default:
                // Get HR managers + department manager
                $users = get_users(['role' => 'sfs_hr_manager']);
                foreach ($users as $user) {
                    $emails[] = $user->user_email;
                }
                // Add department manager
                if ($employee->dept_id) {
                    global $wpdb;
                    $dept_table = $wpdb->prefix . 'sfs_hr_departments';
                    $manager_user_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT manager_user_id FROM {$dept_table} WHERE id = %d",
                        $employee->dept_id
                    ));
                    if ($manager_user_id) {
                        $user = get_userdata($manager_user_id);
                        if ($user) {
                            $emails[] = $user->user_email;
                        }
                    }
                }
                break;
        }

        return array_unique(array_filter($emails));
    }

    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget(): void {
        $birthdays = $this->get_upcoming_birthdays([0, 1, 2, 3, 4, 5, 6, 7]);
        $anniversaries = $this->get_upcoming_anniversaries([0, 1, 2, 3, 4, 5, 6, 7]);

        if (empty($birthdays) && empty($anniversaries)) {
            return;
        }

        ?>
        <div class="sfs-hr-dashboard-widget" style="background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:16px; margin-bottom:20px;">
            <h3 style="margin:0 0 16px; font-size:14px; display:flex; align-items:center; gap:8px;">
                <span style="font-size:18px;">&#127881;</span>
                <?php esc_html_e('Upcoming Celebrations', 'sfs-hr'); ?>
            </h3>

            <?php if (!empty($birthdays)): ?>
            <div style="margin-bottom:16px;">
                <h4 style="margin:0 0 8px; font-size:12px; color:#666; text-transform:uppercase;"><?php esc_html_e('Birthdays', 'sfs-hr'); ?></h4>
                <?php foreach ($birthdays as $emp): ?>
                <div style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f0f0f1;">
                    <div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg, #ec4899 0%, #f472b6 100%); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:12px;">
                        <?php echo esc_html(mb_substr($emp->first_name, 0, 1) . mb_substr($emp->last_name, 0, 1)); ?>
                    </div>
                    <div style="flex:1;">
                        <strong style="display:block; font-size:13px;"><?php echo esc_html($emp->first_name . ' ' . $emp->last_name); ?></strong>
                        <span style="font-size:11px; color:#666;">
                            <?php
                            if ($emp->days_until == 0) {
                                echo '<span style="color:#ec4899; font-weight:600;">' . esc_html__('Today!', 'sfs-hr') . '</span>';
                            } elseif ($emp->days_until == 1) {
                                esc_html_e('Tomorrow', 'sfs-hr');
                            } else {
                                printf(esc_html__('In %d days', 'sfs-hr'), $emp->days_until);
                            }
                            ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($anniversaries)): ?>
            <div>
                <h4 style="margin:0 0 8px; font-size:12px; color:#666; text-transform:uppercase;"><?php esc_html_e('Work Anniversaries', 'sfs-hr'); ?></h4>
                <?php foreach ($anniversaries as $emp): ?>
                <div style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f0f0f1;">
                    <div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg, #2271b1 0%, #135e96 100%); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:12px;">
                        <?php echo esc_html(mb_substr($emp->first_name, 0, 1) . mb_substr($emp->last_name, 0, 1)); ?>
                    </div>
                    <div style="flex:1;">
                        <strong style="display:block; font-size:13px;"><?php echo esc_html($emp->first_name . ' ' . $emp->last_name); ?></strong>
                        <span style="font-size:11px; color:#666;">
                            <?php
                            $years = isset($emp->years_completing) ? (int)$emp->years_completing : 1;
                            if ($emp->days_until == 0) {
                                printf('<span style="color:#2271b1; font-weight:600;">' . esc_html__('%d year(s) today!', 'sfs-hr') . '</span>', $years);
                            } elseif ($emp->days_until == 1) {
                                printf(esc_html__('%d year(s) tomorrow', 'sfs-hr'), $years);
                            } else {
                                printf(esc_html__('%d year(s) in %d days', 'sfs-hr'), $years, $emp->days_until);
                            }
                            ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Add admin submenu
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            null,
            __('Upcoming Celebrations', 'sfs-hr'),
            __('Celebrations', 'sfs-hr'),
            'sfs_hr.view',
            'sfs-hr-celebrations',
            [$this, 'render_celebrations_page']
        );
    }

    /**
     * Render celebrations page
     */
    public function render_celebrations_page(): void {
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        // Check if birth_date column exists
        $has_birth_date = (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'birth_date'",
            $emp_table
        ));

        // Get birthdays for the month
        $birthdays = [];
        if ($has_birth_date) {
            $birthdays = $wpdb->get_results($wpdb->prepare(
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

        // Get anniversaries for the month
        $anniversaries = $wpdb->get_results($wpdb->prepare(
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

        $month_names = [
            1 => __('January', 'sfs-hr'),
            2 => __('February', 'sfs-hr'),
            3 => __('March', 'sfs-hr'),
            4 => __('April', 'sfs-hr'),
            5 => __('May', 'sfs-hr'),
            6 => __('June', 'sfs-hr'),
            7 => __('July', 'sfs-hr'),
            8 => __('August', 'sfs-hr'),
            9 => __('September', 'sfs-hr'),
            10 => __('October', 'sfs-hr'),
            11 => __('November', 'sfs-hr'),
            12 => __('December', 'sfs-hr'),
        ];

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e('Upcoming Celebrations', 'sfs-hr'); ?></h1>

            <?php if (method_exists('\SFS\HR\Core\Helpers', 'render_admin_nav')): ?>
                <?php \SFS\HR\Core\Helpers::render_admin_nav(); ?>
            <?php endif; ?>

            <hr class="wp-header-end" />

            <div style="margin:20px 0;">
                <form method="get" style="display:flex; gap:10px; align-items:center;">
                    <input type="hidden" name="page" value="sfs-hr-celebrations" />
                    <select name="month">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php selected($month, $m); ?>><?php echo esc_html($month_names[$m]); ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="year">
                        <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php selected($year, $y); ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="button"><?php esc_html_e('View', 'sfs-hr'); ?></button>
                </form>
            </div>

            <h2><?php echo esc_html($month_names[$month] . ' ' . $year); ?></h2>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(400px, 1fr)); gap:20px; margin-top:20px;">
                <div style="background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:16px;">
                    <h3 style="margin:0 0 16px; color:#ec4899;">
                        <span style="margin-right:8px;">&#127874;</span>
                        <?php esc_html_e('Birthdays', 'sfs-hr'); ?>
                        <span style="font-weight:normal; font-size:14px; color:#666;">(<?php echo count($birthdays); ?>)</span>
                    </h3>

                    <?php if (empty($birthdays)): ?>
                        <p class="description"><?php esc_html_e('No birthdays this month.', 'sfs-hr'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Day', 'sfs-hr'); ?></th>
                                    <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                                    <th><?php esc_html_e('Department', 'sfs-hr'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($birthdays as $emp): ?>
                                <tr>
                                    <td style="width:50px; text-align:center; font-weight:600;"><?php echo (int)$emp->day_of_month; ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $emp->id)); ?>">
                                            <?php echo esc_html($emp->first_name . ' ' . $emp->last_name); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($emp->department_name ?: '—'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div style="background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:16px;">
                    <h3 style="margin:0 0 16px; color:#2271b1;">
                        <span style="margin-right:8px;">&#127942;</span>
                        <?php esc_html_e('Work Anniversaries', 'sfs-hr'); ?>
                        <span style="font-weight:normal; font-size:14px; color:#666;">(<?php echo count($anniversaries); ?>)</span>
                    </h3>

                    <?php if (empty($anniversaries)): ?>
                        <p class="description"><?php esc_html_e('No work anniversaries this month.', 'sfs-hr'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Day', 'sfs-hr'); ?></th>
                                    <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                                    <th><?php esc_html_e('Years', 'sfs-hr'); ?></th>
                                    <th><?php esc_html_e('Department', 'sfs-hr'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($anniversaries as $emp): ?>
                                <tr>
                                    <td style="width:50px; text-align:center; font-weight:600;"><?php echo (int)$emp->day_of_month; ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $emp->id)); ?>">
                                            <?php echo esc_html($emp->first_name . ' ' . $emp->last_name); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span style="background:#2271b11a; color:#2271b1; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600;">
                                            <?php printf(esc_html__('%d year(s)', 'sfs-hr'), $emp->years_completing); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($emp->department_name ?: '—'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Add settings fields
     */
    public function add_settings_fields(array $fields): array {
        $fields['birthday_anniversary_header'] = [
            'type' => 'header',
            'label' => __('Birthday & Anniversary Reminders', 'sfs-hr'),
        ];

        $fields['notify_birthdays'] = [
            'type' => 'checkbox',
            'label' => __('Send Birthday Reminders', 'sfs-hr'),
            'default' => true,
        ];

        $fields['birthday_days'] = [
            'type' => 'text',
            'label' => __('Birthday Reminder Days', 'sfs-hr'),
            'description' => __('Days before birthday to send reminders (comma-separated, e.g., 0, 1, 7)', 'sfs-hr'),
            'default' => '0, 1, 7',
        ];

        $fields['notify_anniversaries'] = [
            'type' => 'checkbox',
            'label' => __('Send Work Anniversary Reminders', 'sfs-hr'),
            'default' => true,
        ];

        $fields['anniversary_days'] = [
            'type' => 'text',
            'label' => __('Anniversary Reminder Days', 'sfs-hr'),
            'description' => __('Days before anniversary to send reminders (comma-separated)', 'sfs-hr'),
            'default' => '0, 1, 7',
        ];

        $fields['reminder_recipients'] = [
            'type' => 'select',
            'label' => __('Send Reminders To', 'sfs-hr'),
            'options' => [
                'managers' => __('HR Managers + Department Manager', 'sfs-hr'),
                'dept_manager' => __('Department Manager Only', 'sfs-hr'),
                'all_hr' => __('All HR Managers + Administrators', 'sfs-hr'),
            ],
            'default' => 'managers',
        ];

        return $fields;
    }

    /**
     * Save settings
     */
    public function save_settings(array $data): void {
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
     * Get upcoming events count for dashboard badge
     */
    public static function get_upcoming_count(int $days = 7): int {
        $instance = self::instance();
        $birthdays = $instance->get_upcoming_birthdays(range(0, $days));
        $anniversaries = $instance->get_upcoming_anniversaries(range(0, $days));

        return count($birthdays) + count($anniversaries);
    }
}
