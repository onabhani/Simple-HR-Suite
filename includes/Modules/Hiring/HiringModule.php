<?php
namespace SFS\HR\Modules\Hiring;

use SFS\HR\Core\Notifications as CoreNotifications;
use SFS\HR\Core\Helpers;

if (!defined('ABSPATH')) { exit; }

/**
 * Hiring Module
 * Manages candidates and trainee students
 *
 * Workflow:
 * - Candidates: Applied/Manual → Dept Manager Approval → GM Approval → Hired/Rejected
 * - Trainees: Training Period (3-6 months) → Convert to Candidate OR Archive
 */
class HiringModule {

    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function hooks(): void {
        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menus'], 25);

        // Admin pages
        require_once __DIR__ . '/Admin/class-admin-pages.php';
        Admin\AdminPages::instance()->hooks();
    }

    /**
     * Add admin menus
     */
    public function add_admin_menus(): void {
        add_submenu_page(
            'sfs-hr',
            __('Hiring', 'sfs-hr'),
            __('Hiring', 'sfs-hr'),
            'sfs_hr.view',
            'sfs-hr-hiring',
            [Admin\AdminPages::instance(), 'render_page']
        );
    }

    /**
     * Install database tables
     */
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        // Candidates table
        $candidates = "CREATE TABLE {$wpdb->prefix}sfs_hr_candidates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            -- Basic Info
            first_name VARCHAR(191) NOT NULL,
            last_name VARCHAR(191) NULL,
            email VARCHAR(191) NOT NULL,
            phone VARCHAR(64) NULL,
            gender VARCHAR(16) NULL,
            date_of_birth DATE NULL,
            nationality VARCHAR(64) NULL,

            -- Address
            address TEXT NULL,
            city VARCHAR(100) NULL,
            country VARCHAR(100) NULL,

            -- Application Info
            position_applied VARCHAR(191) NULL,
            dept_id BIGINT UNSIGNED NULL,
            application_source ENUM('applied','manual') NOT NULL DEFAULT 'applied',
            expected_salary DECIMAL(12,2) NULL,
            available_from DATE NULL,

            -- Documents
            resume_url VARCHAR(500) NULL,
            cover_letter TEXT NULL,
            notes TEXT NULL,

            -- Status & Workflow
            status ENUM('applied','screening','dept_pending','dept_approved','gm_pending','gm_approved','hired','rejected') NOT NULL DEFAULT 'applied',
            rejection_reason TEXT NULL,

            -- Approval Tracking
            dept_manager_id BIGINT UNSIGNED NULL,
            dept_approved_at DATETIME NULL,
            dept_notes TEXT NULL,
            gm_id BIGINT UNSIGNED NULL,
            gm_approved_at DATETIME NULL,
            gm_notes TEXT NULL,

            -- If hired
            employee_id BIGINT UNSIGNED NULL,
            hired_at DATE NULL,
            offered_salary DECIMAL(12,2) NULL,
            offered_position VARCHAR(191) NULL,

            -- Converted from trainee
            converted_from_trainee_id BIGINT UNSIGNED NULL,

            -- Timestamps
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_dept (dept_id),
            KEY idx_email (email)
        ) $charset;";
        dbDelta($candidates);

        // Trainees table
        $trainees = "CREATE TABLE {$wpdb->prefix}sfs_hr_trainees (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL UNIQUE,
            trainee_code VARCHAR(64) NOT NULL UNIQUE,

            -- Basic Info
            first_name VARCHAR(191) NOT NULL,
            last_name VARCHAR(191) NULL,
            email VARCHAR(191) NOT NULL,
            phone VARCHAR(64) NULL,
            gender VARCHAR(16) NULL,
            date_of_birth DATE NULL,
            nationality VARCHAR(64) NULL,

            -- Address
            address TEXT NULL,
            emergency_contact_name VARCHAR(191) NULL,
            emergency_contact_phone VARCHAR(64) NULL,

            -- Education Info
            university VARCHAR(191) NULL,
            major VARCHAR(191) NULL,
            education_level VARCHAR(64) NULL,
            gpa VARCHAR(16) NULL,

            -- Training Info
            dept_id BIGINT UNSIGNED NULL,
            supervisor_id BIGINT UNSIGNED NULL,
            position VARCHAR(191) NULL,
            training_start DATE NOT NULL,
            training_end DATE NOT NULL,
            training_extended_to DATE NULL,

            -- Status
            status ENUM('active','completed','converted','archived') NOT NULL DEFAULT 'active',

            -- If converted to candidate
            candidate_id BIGINT UNSIGNED NULL,
            converted_at DATETIME NULL,

            -- If archived
            archive_reason TEXT NULL,
            archived_at DATETIME NULL,

            -- Documents
            documents TEXT NULL,
            notes TEXT NULL,

            -- Timestamps
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_dept (dept_id),
            KEY idx_supervisor (supervisor_id),
            KEY idx_user (user_id)
        ) $charset;";
        dbDelta($trainees);
    }

    /**
     * Get candidate statuses with labels
     */
    public static function get_candidate_statuses(): array {
        return [
            'applied' => __('Applied', 'sfs-hr'),
            'screening' => __('Screening', 'sfs-hr'),
            'dept_pending' => __('Pending Dept. Manager', 'sfs-hr'),
            'dept_approved' => __('Dept. Manager Approved', 'sfs-hr'),
            'gm_pending' => __('Pending GM', 'sfs-hr'),
            'gm_approved' => __('GM Approved', 'sfs-hr'),
            'hired' => __('Hired', 'sfs-hr'),
            'rejected' => __('Rejected', 'sfs-hr'),
        ];
    }

    /**
     * Get trainee statuses with labels
     */
    public static function get_trainee_statuses(): array {
        return [
            'active' => __('Active', 'sfs-hr'),
            'completed' => __('Completed', 'sfs-hr'),
            'converted' => __('Converted to Candidate', 'sfs-hr'),
            'archived' => __('Archived', 'sfs-hr'),
        ];
    }

    /**
     * Generate unique trainee code
     */
    public static function generate_trainee_code(): string {
        global $wpdb;
        $year = date('Y');
        $prefix = "TRN-{$year}-";

        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT trainee_code FROM {$wpdb->prefix}sfs_hr_trainees
             WHERE trainee_code LIKE %s
             ORDER BY id DESC LIMIT 1",
            $prefix . '%'
        ));

        if ($last) {
            $num = (int) substr($last, -4) + 1;
        } else {
            $num = 1;
        }

        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Convert trainee to candidate
     */
    public static function convert_trainee_to_candidate(int $trainee_id): ?int {
        global $wpdb;

        $trainee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_trainees WHERE id = %d",
            $trainee_id
        ));

        if (!$trainee || $trainee->status !== 'completed') {
            return null;
        }

        $now = current_time('mysql');

        // Create candidate from trainee data
        $wpdb->insert("{$wpdb->prefix}sfs_hr_candidates", [
            'first_name' => $trainee->first_name,
            'last_name' => $trainee->last_name,
            'email' => $trainee->email,
            'phone' => $trainee->phone,
            'gender' => $trainee->gender,
            'date_of_birth' => $trainee->date_of_birth,
            'nationality' => $trainee->nationality,
            'address' => $trainee->address,
            'position_applied' => $trainee->position,
            'dept_id' => $trainee->dept_id,
            'application_source' => 'manual',
            'status' => 'dept_pending',
            'converted_from_trainee_id' => $trainee_id,
            'notes' => sprintf(__('Converted from trainee %s', 'sfs-hr'), $trainee->trainee_code),
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $candidate_id = $wpdb->insert_id;

        if ($candidate_id) {
            // Update trainee status
            $wpdb->update(
                "{$wpdb->prefix}sfs_hr_trainees",
                [
                    'status' => 'converted',
                    'candidate_id' => $candidate_id,
                    'converted_at' => $now,
                    'updated_at' => $now,
                ],
                ['id' => $trainee_id]
            );
        }

        return $candidate_id;
    }

    /**
     * Convert trainee directly to employee (bypassing candidate workflow)
     *
     * @param int   $trainee_id Trainee ID
     * @param array $extra_data Extra data (hired_date, offered_position, offered_salary, probation_months)
     * @return int|null Employee ID or null on failure
     */
    public static function convert_trainee_to_employee(int $trainee_id, array $extra_data = []): ?int {
        global $wpdb;

        $trainee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_trainees WHERE id = %d",
            $trainee_id
        ));

        if (!$trainee || !in_array($trainee->status, ['active', 'completed'])) {
            return null;
        }

        $now = current_time('mysql');
        $today = current_time('Y-m-d');

        // Generate employee code
        $prefix = "USR-";
        $last = $wpdb->get_var(
            "SELECT employee_code FROM {$wpdb->prefix}sfs_hr_employees
             WHERE employee_code LIKE '{$prefix}%'
             ORDER BY id DESC LIMIT 1"
        );
        $num = $last ? ((int) substr($last, -4) + 1) : 1;
        $employee_code = $prefix . $num;

        // Check if trainee already has a WordPress user account
        $user_id = $trainee->user_id;

        if (!$user_id) {
            // Create WordPress user with username format: firstname.(first letter of lastname)
            $last_initial = $trainee->last_name ? strtolower(substr($trainee->last_name, 0, 1)) : '';
            $username = sanitize_user(strtolower($trainee->first_name) . ($last_initial ? '.' . $last_initial : ''));
            $username = substr($username, 0, 50);

            // Ensure unique username
            $base_username = $username;
            $counter = 1;
            while (username_exists($username)) {
                $username = $base_username . $counter;
                $counter++;
            }

            // Check if email already used
            if (email_exists($trainee->email)) {
                return null;
            }

            $random_password = wp_generate_password(12, true, true);
            $user_id = wp_create_user($username, $random_password, $trainee->email);

            if (is_wp_error($user_id)) {
                return null;
            }

            // Update user meta
            wp_update_user([
                'ID' => $user_id,
                'first_name' => $trainee->first_name,
                'last_name' => $trainee->last_name ?? '',
                'display_name' => trim($trainee->first_name . ' ' . ($trainee->last_name ?? '')),
            ]);

            // Send welcome email with credentials
            self::send_welcome_email($user_id, $username, $random_password);
        } else {
            // Remove trainee meta since they're now an employee
            delete_user_meta($user_id, 'sfs_hr_is_trainee');
        }

        // Determine probation end date (3 months default)
        $probation_months = isset($extra_data['probation_months']) ? (int) $extra_data['probation_months'] : 3;
        $hired_date = $extra_data['hired_date'] ?? $today;
        $probation_end = date('Y-m-d', strtotime("+{$probation_months} months", strtotime($hired_date)));

        // Determine position and salary
        $position = !empty($extra_data['offered_position']) ? $extra_data['offered_position'] : $trainee->position;
        $salary = !empty($extra_data['offered_salary']) ? $extra_data['offered_salary'] : null;

        // Create employee record
        $wpdb->insert("{$wpdb->prefix}sfs_hr_employees", [
            'user_id' => $user_id,
            'employee_code' => $employee_code,
            'first_name' => $trainee->first_name,
            'last_name' => $trainee->last_name,
            'email' => $trainee->email,
            'phone' => $trainee->phone,
            'dept_id' => $trainee->dept_id,
            'position' => $position,
            'gender' => $trainee->gender,
            'status' => 'active',
            'hired_at' => $hired_date,
            'base_salary' => $salary,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $employee_id = $wpdb->insert_id;

        if ($employee_id) {
            // Update trainee status to converted and store employee_id
            // Note: We store the employee_id in the notes field as JSON since there's no dedicated column
            $existing_notes = $trainee->notes ?? '';
            $hire_info = json_encode(['direct_hire_employee_id' => $employee_id, 'hired_date' => $hired_date]);
            $updated_notes = $existing_notes ? $existing_notes . "\n\n[Direct Hire Info: " . $hire_info . "]" : "[Direct Hire Info: " . $hire_info . "]";

            $wpdb->update(
                "{$wpdb->prefix}sfs_hr_trainees",
                [
                    'status' => 'converted',
                    'converted_at' => $now,
                    'notes' => $updated_notes,
                    'updated_at' => $now,
                ],
                ['id' => $trainee_id]
            );

            // Store probation info in user meta
            update_user_meta($user_id, 'sfs_hr_probation_end', $probation_end);
            update_user_meta($user_id, 'sfs_hr_probation_status', 'ongoing');

            // Notify HR about new hire from trainee
            self::notify_hr_trainee_hired($trainee, $employee_code, $hired_date, $position);
        }

        return $employee_id;
    }

    /**
     * Notify HR team about trainee hired directly
     */
    private static function notify_hr_trainee_hired(object $trainee, string $employee_code, string $hired_date, ?string $position): void {
        // Get HR emails from Core settings
        $core_settings = CoreNotifications::get_settings();
        $hr_emails = $core_settings['hr_emails'] ?? [];

        if (empty($hr_emails) || !($core_settings['hr_notification'] ?? true)) {
            return;
        }

        $employee_name = trim($trainee->first_name . ' ' . ($trainee->last_name ?? ''));
        $position_text = $position ?? __('Not specified', 'sfs-hr');

        $subject = sprintf(__('[HR Notice] Trainee hired as employee: %s', 'sfs-hr'), $employee_name);
        $message = sprintf(
            __("A trainee has been directly hired as an employee.\n\nTrainee Code: %s\nEmployee Name: %s\nEmployee Code: %s\nPosition: %s\nDepartment ID: %d\nHired Date: %s\nEmail: %s\nPhone: %s\n\nThe employee has been sent their login credentials (if new account created).", 'sfs-hr'),
            $trainee->trainee_code,
            $employee_name,
            $employee_code,
            $position_text,
            (int) $trainee->dept_id,
            $hired_date,
            $trainee->email ?? 'N/A',
            $trainee->phone ?? 'N/A'
        );

        // Send to all HR emails
        foreach ($hr_emails as $hr_email) {
            if (!is_email($hr_email)) {
                continue;
            }
            Helpers::send_mail($hr_email, $subject, $message);
        }
    }

    /**
     * Convert candidate to employee
     */
    public static function convert_candidate_to_employee(int $candidate_id, array $extra_data = []): ?int {
        global $wpdb;

        $candidate = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_candidates WHERE id = %d",
            $candidate_id
        ));

        if (!$candidate || $candidate->status !== 'gm_approved') {
            return null;
        }

        $now = current_time('mysql');
        $today = current_time('Y-m-d');

        // Generate employee code
        $year = date('Y');
        $prefix = "USR-";
        $last = $wpdb->get_var(
            "SELECT employee_code FROM {$wpdb->prefix}sfs_hr_employees
             WHERE employee_code LIKE '{$prefix}%'
             ORDER BY id DESC LIMIT 1"
        );
        $num = $last ? ((int) substr($last, -4) + 1) : 1;
        $employee_code = $prefix . $num;

        // Create WordPress user with username format: firstname.(first letter of lastname)
        $last_initial = $candidate->last_name ? strtolower(substr($candidate->last_name, 0, 1)) : '';
        $username = sanitize_user(strtolower($candidate->first_name) . ($last_initial ? '.' . $last_initial : ''));
        $username = substr($username, 0, 50);

        // Ensure unique username
        $base_username = $username;
        $counter = 1;
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }

        $random_password = wp_generate_password(12, true, true);
        $user_id = wp_create_user($username, $random_password, $candidate->email);

        if (is_wp_error($user_id)) {
            return null;
        }

        // Update user meta
        wp_update_user([
            'ID' => $user_id,
            'first_name' => $candidate->first_name,
            'last_name' => $candidate->last_name ?? '',
            'display_name' => trim($candidate->first_name . ' ' . ($candidate->last_name ?? '')),
        ]);

        // Determine probation end date (3 months default)
        $probation_months = isset($extra_data['probation_months']) ? (int) $extra_data['probation_months'] : 3;
        $hired_date = $extra_data['hired_date'] ?? $today;
        $probation_end = date('Y-m-d', strtotime("+{$probation_months} months", strtotime($hired_date)));

        // Create employee record
        $wpdb->insert("{$wpdb->prefix}sfs_hr_employees", [
            'user_id' => $user_id,
            'employee_code' => $employee_code,
            'first_name' => $candidate->first_name,
            'last_name' => $candidate->last_name,
            'email' => $candidate->email,
            'phone' => $candidate->phone,
            'dept_id' => $candidate->dept_id,
            'position' => $candidate->offered_position ?? $candidate->position_applied,
            'gender' => $candidate->gender,
            'status' => 'active',
            'hired_at' => $hired_date,
            'base_salary' => $candidate->offered_salary ?? $candidate->expected_salary,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $employee_id = $wpdb->insert_id;

        if ($employee_id) {
            // Update candidate status
            $wpdb->update(
                "{$wpdb->prefix}sfs_hr_candidates",
                [
                    'status' => 'hired',
                    'employee_id' => $employee_id,
                    'hired_at' => $hired_date,
                    'updated_at' => $now,
                ],
                ['id' => $candidate_id]
            );

            // Store probation info in employee meta or custom field
            update_user_meta($user_id, 'sfs_hr_probation_end', $probation_end);
            update_user_meta($user_id, 'sfs_hr_probation_status', 'ongoing');

            // Send welcome email with credentials
            self::send_welcome_email($user_id, $username, $random_password);

            // Notify HR about new hire
            self::notify_hr_new_hire($candidate, $employee_code, $hired_date);
        }

        return $employee_id;
    }

    /**
     * Notify HR team about new hire
     */
    private static function notify_hr_new_hire(object $candidate, string $employee_code, string $hired_date): void {
        // Get HR emails from Core settings
        $core_settings = CoreNotifications::get_settings();
        $hr_emails = $core_settings['hr_emails'] ?? [];

        if (empty($hr_emails) || !($core_settings['hr_notification'] ?? true)) {
            return;
        }

        $employee_name = trim($candidate->first_name . ' ' . ($candidate->last_name ?? ''));
        $position = $candidate->offered_position ?? $candidate->position_applied ?? __('Not specified', 'sfs-hr');

        $subject = sprintf(__('[HR Notice] New employee hired: %s', 'sfs-hr'), $employee_name);
        $message = sprintf(
            __("A new employee has been hired from the recruitment pipeline.\n\nEmployee Name: %s\nEmployee Code: %s\nPosition: %s\nDepartment ID: %d\nHired Date: %s\nEmail: %s\nPhone: %s\n\nThe employee has been sent their login credentials.", 'sfs-hr'),
            $employee_name,
            $employee_code,
            $position,
            (int) $candidate->dept_id,
            $hired_date,
            $candidate->email ?? 'N/A',
            $candidate->phone ?? 'N/A'
        );

        // Send to all HR emails
        foreach ($hr_emails as $hr_email) {
            if (!is_email($hr_email)) {
                continue;
            }
            Helpers::send_mail($hr_email, $subject, $message);
        }
    }

    /**
     * Send welcome email to new employee
     */
    private static function send_welcome_email(int $user_id, string $username, string $password): void {
        $user = get_userdata($user_id);
        if (!$user) return;

        $site_name = get_bloginfo('name');
        $login_url = wp_login_url();

        $subject = sprintf(__('[%s] Your Employee Account', 'sfs-hr'), $site_name);

        $message = sprintf(__('Welcome to %s!', 'sfs-hr'), $site_name) . "\n\n";
        $message .= __('Your employee account has been created. Here are your login credentials:', 'sfs-hr') . "\n\n";
        $message .= sprintf(__('Username: %s', 'sfs-hr'), $username) . "\n";
        $message .= sprintf(__('Password: %s', 'sfs-hr'), $password) . "\n\n";
        $message .= sprintf(__('Login URL: %s', 'sfs-hr'), $login_url) . "\n\n";
        $message .= __('Please change your password after your first login.', 'sfs-hr') . "\n";

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Check if user is a trainee
     */
    public static function is_trainee(int $user_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_trainees WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
    }

    /**
     * Get trainee by user ID
     */
    public static function get_trainee_by_user(int $user_id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_trainees WHERE user_id = %d",
            $user_id
        ));
    }
}
