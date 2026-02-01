<?php
namespace SFS\HR\Install;

if (!defined('ABSPATH')) { exit; }

class Migrations {

    public static function run(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $emp   = $wpdb->prefix.'sfs_hr_employees';
        $dept  = $wpdb->prefix.'sfs_hr_departments';
        $types = $wpdb->prefix.'sfs_hr_leave_types';
        $req   = $wpdb->prefix.'sfs_hr_leave_requests';
        $bal   = $wpdb->prefix.'sfs_hr_leave_balances';
        $resign = $wpdb->prefix.'sfs_hr_resignations';
        $settle = $wpdb->prefix.'sfs_hr_settlements';

        /** EMPLOYEES (create minimal shell if missing, then add columns idempotently) */
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$emp` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_code` VARCHAR(191) NOT NULL,
            `user_id` BIGINT(20) UNSIGNED NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_emp_code` (`employee_code`),
            KEY `idx_emp_user` (`user_id`)
        ) $charset");

        // Ensure full schema used by UI/workflows
        self::add_column_if_missing($emp, 'first_name',               "VARCHAR(191) NULL");
        self::add_column_if_missing($emp, 'last_name',                "VARCHAR(191) NULL");
        self::add_column_if_missing($emp, 'first_name_ar',            "VARCHAR(191) NULL");
        self::add_column_if_missing($emp, 'last_name_ar',             "VARCHAR(191) NULL");
        self::add_column_if_missing($emp, 'email',                    "VARCHAR(191) NULL");
        self::add_column_if_missing($emp, 'phone',                    "VARCHAR(191) NULL");
        self::add_column_if_missing($emp, 'dept_id',                  "BIGINT(20) UNSIGNED NULL");
        self::add_column_if_missing($emp, 'position',                 "VARCHAR(191) NULL");
        self::add_column_if_missing($emp, 'hire_date',                "DATE NULL");
        self::add_column_if_missing($emp, 'hired_at',                 "DATE NULL");
        self::add_column_if_missing($emp, 'base_salary',              "DECIMAL(18,2) NULL");
        self::add_column_if_missing($emp, 'national_id',              "VARCHAR(191) NULL");
        self::add_column_if_missing($emp, 'national_id_expiry',       "DATE NULL");
        self::add_column_if_missing($emp, 'passport_no',              "VARCHAR(191) NULL");
        self::add_column_if_missing($emp, 'passport_expiry',          "DATE NULL");
        self::add_column_if_missing($emp, 'emergency_contact_name',   "VARCHAR(191) NULL");
        self::add_column_if_missing($emp, 'emergency_contact_phone',  "VARCHAR(191) NULL");
        // If some older install created user_id as NOT NULL, make it NULL-able
        self::make_column_nullable_if_exists($emp, 'user_id', "BIGINT(20) UNSIGNED");

        /** DEPARTMENTS (NEW) */
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$dept` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `manager_user_id` BIGINT(20) UNSIGNED NULL,
            `auto_role` VARCHAR(191) NULL,
            `approver_role` VARCHAR(191) NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_dept_name` (`name`),
            KEY `idx_dept_manager` (`manager_user_id`)
        ) $charset");
        // Add color column for department cards/charts
        self::add_column_if_missing($dept, 'color', "VARCHAR(7) NULL");

        /** LEAVE TYPES */
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$types` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `is_paid` TINYINT(1) NOT NULL DEFAULT 1,
            `requires_approval` TINYINT(1) NOT NULL DEFAULT 1,
            `annual_quota` INT NOT NULL DEFAULT 30,
            `allow_negative` TINYINT(1) NOT NULL DEFAULT 0,
            `is_annual` TINYINT(1) NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_name` (`name`)
        ) $charset");
        self::add_column_if_missing($types, 'annual_quota',  "INT NOT NULL DEFAULT 30");
        self::add_column_if_missing($types, 'allow_negative',"TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'is_annual',     "TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'special_code', "VARCHAR(50) NULL"); // e.g. SICK_SHORT, SICK_LONG, HAJJ, MATERNITY
        self::add_column_if_missing($req, 'pay_breakdown', "TEXT NULL");   // JSON: [{days:30, rate:1}, ...]
        self::add_column_if_missing($req, 'paid_days',     "INT NOT NULL DEFAULT 0");
        self::add_column_if_missing($req, 'unpaid_days',   "INT NOT NULL DEFAULT 0");


        /** LEAVE REQUESTS */
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$req` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `type_id` BIGINT(20) UNSIGNED NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `days` INT NOT NULL DEFAULT 1,
            `reason` TEXT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `approver_id` BIGINT(20) UNSIGNED NULL,
            `approver_note` TEXT NULL,
            `approval_level` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
            `approval_chain` LONGTEXT NULL,
            `decided_at` DATETIME NULL,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `emp_idx` (`employee_id`),
            KEY `type_idx` (`type_id`),
            KEY `status_idx` (`status`),
            KEY `dates_idx` (`start_date`,`end_date`)
        ) $charset");
        self::add_column_if_missing($req, 'approval_level', "TINYINT(3) UNSIGNED NOT NULL DEFAULT 1");
        self::add_column_if_missing($req, 'approval_chain', "LONGTEXT NULL");
        self::add_column_if_missing($req, 'decided_at',     "DATETIME NULL");
        self::make_text_if_varchar255($req, 'approver_note'); // widen if earlier was VARCHAR(255)

        /** LEAVE BALANCES */
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$bal` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `type_id` BIGINT(20) UNSIGNED NOT NULL,
            `year` SMALLINT(5) UNSIGNED NOT NULL,
            `opening` INT NOT NULL DEFAULT 0,
            `accrued` INT NOT NULL DEFAULT 0,
            `used` INT NOT NULL DEFAULT 0,
            `carried_over` INT NOT NULL DEFAULT 0,
            `closing` INT NOT NULL DEFAULT 0,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_emp_type_year` (`employee_id`,`type_id`,`year`),
            KEY `emp_idx` (`employee_id`),
            KEY `type_idx` (`type_id`)
        ) $charset");

        /** RESIGNATIONS */
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$resign` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `resignation_date` DATE NOT NULL,
            `last_working_day` DATE NULL,
            `notice_period_days` INT NOT NULL DEFAULT 30,
            `reason` TEXT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `approver_id` BIGINT(20) UNSIGNED NULL,
            `approver_note` TEXT NULL,
            `approval_level` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
            `approval_chain` LONGTEXT NULL,
            `decided_at` DATETIME NULL,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            `handover_notes` TEXT NULL,
            `clearance_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            PRIMARY KEY (`id`),
            KEY `emp_idx` (`employee_id`),
            KEY `status_idx` (`status`),
            KEY `resignation_date_idx` (`resignation_date`)
        ) $charset");
        self::add_column_if_missing($resign, 'approval_level', "TINYINT(3) UNSIGNED NOT NULL DEFAULT 1");
        self::add_column_if_missing($resign, 'approval_chain', "LONGTEXT NULL");
        self::add_column_if_missing($resign, 'decided_at', "DATETIME NULL");
        self::add_column_if_missing($resign, 'handover_notes', "TEXT NULL");
        self::add_column_if_missing($resign, 'clearance_status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");

        // Final Exit columns for foreign employees
        self::add_column_if_missing($resign, 'resignation_type', "VARCHAR(20) NOT NULL DEFAULT 'regular'");
        self::add_column_if_missing($resign, 'final_exit_status', "VARCHAR(20) NOT NULL DEFAULT 'not_required'");
        self::add_column_if_missing($resign, 'final_exit_number', "VARCHAR(100) NULL");
        self::add_column_if_missing($resign, 'final_exit_date', "DATE NULL");
        self::add_column_if_missing($resign, 'final_exit_submitted_date', "DATE NULL");
        self::add_column_if_missing($resign, 'government_reference', "VARCHAR(100) NULL");
        self::add_column_if_missing($resign, 'expected_country_exit_date', "DATE NULL");
        self::add_column_if_missing($resign, 'actual_exit_date', "DATE NULL");
        self::add_column_if_missing($resign, 'ticket_booked', "TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($resign, 'exit_stamp_received', "TINYINT(1) NOT NULL DEFAULT 0");

        /** END OF SERVICE SETTLEMENTS */
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$settle` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `resignation_id` BIGINT(20) UNSIGNED NULL,
            `settlement_date` DATE NOT NULL,
            `last_working_day` DATE NOT NULL,
            `years_of_service` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `basic_salary` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `gratuity_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `leave_encashment` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `unused_leave_days` INT NOT NULL DEFAULT 0,
            `final_salary` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `other_allowances` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `deductions` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `deduction_notes` TEXT NULL,
            `total_settlement` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `clearance_checklist` LONGTEXT NULL,
            `asset_clearance_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `document_clearance_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `finance_clearance_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `approver_id` BIGINT(20) UNSIGNED NULL,
            `approver_note` TEXT NULL,
            `approval_level` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
            `approval_chain` LONGTEXT NULL,
            `decided_at` DATETIME NULL,
            `payment_date` DATE NULL,
            `payment_reference` VARCHAR(191) NULL,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `emp_idx` (`employee_id`),
            KEY `resignation_idx` (`resignation_id`),
            KEY `status_idx` (`status`),
            KEY `settlement_date_idx` (`settlement_date`)
        ) $charset");
        self::add_column_if_missing($settle, 'approval_level', "TINYINT(3) UNSIGNED NOT NULL DEFAULT 1");
        self::add_column_if_missing($settle, 'approval_chain', "LONGTEXT NULL");
        self::add_column_if_missing($settle, 'decided_at', "DATETIME NULL");
        self::add_column_if_missing($settle, 'payment_date', "DATE NULL");
        self::add_column_if_missing($settle, 'payment_reference', "VARCHAR(191) NULL");
        self::add_column_if_missing($settle, 'clearance_checklist', "LONGTEXT NULL");
        self::add_column_if_missing($settle, 'asset_clearance_status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");
        self::add_column_if_missing($settle, 'document_clearance_status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");
        self::add_column_if_missing($settle, 'finance_clearance_status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");

        /** ADD REFERENCE NUMBER COLUMNS TO ALL REQUEST TABLES */
        // Leave requests reference number
        self::add_column_if_missing($req, 'request_number', "VARCHAR(50) NULL");
        self::add_unique_key_if_missing($req, 'request_number', 'request_number');

        // Resignations reference number
        self::add_column_if_missing($resign, 'request_number', "VARCHAR(50) NULL");
        self::add_unique_key_if_missing($resign, 'request_number', 'request_number');

        // Settlements reference number
        self::add_column_if_missing($settle, 'request_number', "VARCHAR(50) NULL");
        self::add_unique_key_if_missing($settle, 'request_number', 'request_number');

        // Generate reference numbers for existing records that don't have them
        self::backfill_request_numbers();

        /** CANDIDATES – add columns for enhanced workflow */
        $candidates = $wpdb->prefix.'sfs_hr_candidates';
        $cand_exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s",
            $candidates
        ));
        if ($cand_exists) {
            self::add_column_if_missing($candidates, 'request_number', "VARCHAR(50) NULL");
            self::add_unique_key_if_missing($candidates, 'request_number', 'request_number');
            self::add_column_if_missing($candidates, 'approval_chain', "LONGTEXT NULL");
            self::add_column_if_missing($candidates, 'hr_reviewer_id', "BIGINT(20) UNSIGNED NULL");
            self::add_column_if_missing($candidates, 'hr_reviewed_at', "DATETIME NULL");
            self::add_column_if_missing($candidates, 'hr_notes', "TEXT NULL");

            // Expand ENUM to include hr_reviewed status
            $col_type = $wpdb->get_var($wpdb->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'status'",
                $candidates
            ));
            if ($col_type && strpos($col_type, 'hr_reviewed') === false) {
                $wpdb->query("ALTER TABLE `$candidates` MODIFY `status` ENUM('applied','screening','hr_reviewed','dept_pending','dept_approved','gm_pending','gm_approved','hired','rejected') NOT NULL DEFAULT 'applied'");
            }

            // Backfill reference numbers for existing candidates
            $missing_cnd = $wpdb->get_results(
                "SELECT id, created_at FROM `$candidates` WHERE request_number IS NULL OR request_number = '' ORDER BY id ASC"
            );
            foreach ($missing_cnd as $row) {
                $number = self::generate_request_number('CND', $candidates, $row->created_at);
                $wpdb->update($candidates, ['request_number' => $number], ['id' => $row->id]);
            }
        }

        /** EMPLOYEES – add probation tracking columns */
        self::add_column_if_missing($emp, 'probation_end_date', "DATE NULL");
        self::add_column_if_missing($emp, 'probation_status', "VARCHAR(20) NULL DEFAULT NULL");

        /** LEAVE REQUEST HISTORY (audit trail) */
        $leave_history = $wpdb->prefix.'sfs_hr_leave_request_history';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$leave_history` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `leave_request_id` BIGINT(20) UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL,
            `user_id` BIGINT(20) UNSIGNED NULL,
            `event_type` VARCHAR(50) NOT NULL,
            `meta` LONGTEXT NULL,
            PRIMARY KEY (`id`),
            KEY `leave_request_id` (`leave_request_id`),
            KEY `created_at` (`created_at`),
            KEY `event_type` (`event_type`)
        ) $charset");

        /** LEAVE CANCELLATIONS (post-approval cancellation workflow) */
        $leave_cancel = $wpdb->prefix.'sfs_hr_leave_cancellations';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$leave_cancel` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `leave_request_id` BIGINT(20) UNSIGNED NOT NULL,
            `request_number` VARCHAR(50) NULL,
            `reason` TEXT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `approval_level` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
            `approval_chain` LONGTEXT NULL,
            `initiated_by` BIGINT(20) UNSIGNED NOT NULL,
            `approver_id` BIGINT(20) UNSIGNED NULL,
            `approver_note` TEXT NULL,
            `decided_at` DATETIME NULL,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `leave_req_idx` (`leave_request_id`),
            KEY `status_idx` (`status`),
            UNIQUE KEY `request_number` (`request_number`)
        ) $charset");

        /** EXIT HISTORY (audit trail for resignations and settlements) */
        $exit_history = $wpdb->prefix.'sfs_hr_exit_history';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$exit_history` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `resignation_id` BIGINT(20) UNSIGNED NULL,
            `settlement_id` BIGINT(20) UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `user_id` BIGINT(20) UNSIGNED NULL,
            `event_type` VARCHAR(50) NOT NULL,
            `meta` LONGTEXT NULL,
            PRIMARY KEY (`id`),
            KEY `resignation_id` (`resignation_id`),
            KEY `settlement_id` (`settlement_id`),
            KEY `created_at` (`created_at`),
            KEY `event_type` (`event_type`)
        ) $charset");

        /** PERFORMANCE MODULE TABLES */

        // Performance Snapshots - historical attendance commitment data
        $perf_snapshots = $wpdb->prefix.'sfs_hr_performance_snapshots';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$perf_snapshots` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `period_start` DATE NOT NULL,
            `period_end` DATE NOT NULL,
            `total_working_days` INT UNSIGNED NOT NULL DEFAULT 0,
            `days_present` INT UNSIGNED NOT NULL DEFAULT 0,
            `days_absent` INT UNSIGNED NOT NULL DEFAULT 0,
            `late_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `early_leave_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `incomplete_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `break_delay_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `no_break_taken_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `total_break_delay_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
            `attendance_commitment_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `goals_completion_pct` DECIMAL(5,2) NULL,
            `review_score` DECIMAL(5,2) NULL,
            `overall_score` DECIMAL(5,2) NULL,
            `meta_json` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`),
            KEY `period_start` (`period_start`),
            KEY `period_end` (`period_end`),
            UNIQUE KEY `uq_emp_period` (`employee_id`, `period_start`, `period_end`)
        ) $charset");

        // Migration: add break delay columns to snapshots for existing installations
        self::add_column_if_missing($perf_snapshots, 'break_delay_count', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `incomplete_count`");
        self::add_column_if_missing($perf_snapshots, 'no_break_taken_count', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `break_delay_count`");
        self::add_column_if_missing($perf_snapshots, 'total_break_delay_minutes', "INT UNSIGNED NOT NULL DEFAULT 0 AFTER `no_break_taken_count`");

        // Goals / OKRs
        $perf_goals = $wpdb->prefix.'sfs_hr_performance_goals';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$perf_goals` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `target_date` DATE NULL,
            `weight` TINYINT UNSIGNED NOT NULL DEFAULT 100,
            `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `status` ENUM('active','completed','cancelled','on_hold') NOT NULL DEFAULT 'active',
            `category` VARCHAR(50) NULL,
            `parent_id` BIGINT(20) UNSIGNED NULL,
            `created_by` BIGINT(20) UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`),
            KEY `status` (`status`),
            KEY `target_date` (`target_date`),
            KEY `parent_id` (`parent_id`)
        ) $charset");

        // Goal Progress History
        $perf_goal_history = $wpdb->prefix.'sfs_hr_performance_goal_history';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$perf_goal_history` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `goal_id` BIGINT(20) UNSIGNED NOT NULL,
            `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `note` TEXT NULL,
            `updated_by` BIGINT(20) UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `goal_id` (`goal_id`),
            KEY `created_at` (`created_at`)
        ) $charset");

        // Performance Reviews
        $perf_reviews = $wpdb->prefix.'sfs_hr_performance_reviews';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$perf_reviews` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `reviewer_id` BIGINT(20) UNSIGNED NOT NULL,
            `review_type` ENUM('self','manager','peer','360') NOT NULL DEFAULT 'manager',
            `review_cycle` VARCHAR(50) NULL,
            `period_start` DATE NOT NULL,
            `period_end` DATE NOT NULL,
            `status` ENUM('draft','pending','submitted','acknowledged') NOT NULL DEFAULT 'draft',
            `overall_rating` DECIMAL(3,2) NULL,
            `ratings_json` LONGTEXT NULL,
            `strengths` TEXT NULL,
            `improvements` TEXT NULL,
            `comments` TEXT NULL,
            `employee_comments` TEXT NULL,
            `acknowledged_at` DATETIME NULL,
            `due_date` DATE NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`),
            KEY `reviewer_id` (`reviewer_id`),
            KEY `status` (`status`),
            KEY `period_end` (`period_end`)
        ) $charset");

        // Review Templates / Criteria
        $perf_review_criteria = $wpdb->prefix.'sfs_hr_performance_review_criteria';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$perf_review_criteria` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `description` TEXT NULL,
            `category` VARCHAR(50) NULL,
            `weight` TINYINT UNSIGNED NOT NULL DEFAULT 100,
            `sort_order` INT NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `category` (`category`),
            KEY `active` (`active`)
        ) $charset");

        // Performance Alerts
        $perf_alerts = $wpdb->prefix.'sfs_hr_performance_alerts';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$perf_alerts` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `alert_type` VARCHAR(50) NOT NULL,
            `severity` ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT NULL,
            `metric_value` DECIMAL(10,2) NULL,
            `threshold_value` DECIMAL(10,2) NULL,
            `status` ENUM('active','acknowledged','resolved') NOT NULL DEFAULT 'active',
            `acknowledged_by` BIGINT(20) UNSIGNED NULL,
            `acknowledged_at` DATETIME NULL,
            `resolved_at` DATETIME NULL,
            `meta_json` LONGTEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`),
            KEY `alert_type` (`alert_type`),
            KEY `status` (`status`),
            KEY `severity` (`severity`),
            KEY `created_at` (`created_at`)
        ) $charset");

        /** ATTENDANCE POLICY TABLES */

        // Attendance Policies - role-based clock-in/out rules
        $att_policies = $wpdb->prefix.'sfs_hr_attendance_policies';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$att_policies` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL,
            `clock_in_methods` VARCHAR(255) NOT NULL DEFAULT '[\"kiosk\",\"self_web\"]',
            `clock_out_methods` VARCHAR(255) NOT NULL DEFAULT '[\"kiosk\",\"self_web\"]',
            `clock_in_geofence` ENUM('enforced','none') NOT NULL DEFAULT 'enforced',
            `clock_out_geofence` ENUM('enforced','none') NOT NULL DEFAULT 'enforced',
            `calculation_mode` ENUM('shift_times','total_hours') NOT NULL DEFAULT 'shift_times',
            `target_hours` DECIMAL(4,2) NULL DEFAULT NULL,
            `breaks_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `break_duration_minutes` INT UNSIGNED NULL DEFAULT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `active` (`active`)
        ) $charset");

        // Attendance Policy ↔ Role mapping
        $att_policy_roles = $wpdb->prefix.'sfs_hr_attendance_policy_roles';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$att_policy_roles` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `policy_id` BIGINT(20) UNSIGNED NOT NULL,
            `role_slug` VARCHAR(100) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_policy_role` (`policy_id`, `role_slug`),
            KEY `role_slug` (`role_slug`)
        ) $charset");

        // Seed default review criteria if empty
        $has_criteria = (int)$wpdb->get_var("SELECT COUNT(*) FROM `$perf_review_criteria`");
        if ($has_criteria === 0) {
            $now = current_time('mysql');
            $default_criteria = [
                ['Job Knowledge', 'Understanding of job duties and responsibilities', 'competency', 20, 1],
                ['Quality of Work', 'Accuracy, thoroughness, and reliability of work', 'competency', 20, 2],
                ['Productivity', 'Volume of work and efficiency', 'competency', 15, 3],
                ['Communication', 'Clarity and effectiveness in communication', 'soft_skills', 15, 4],
                ['Teamwork', 'Collaboration and support of colleagues', 'soft_skills', 15, 5],
                ['Attendance & Punctuality', 'Reliability in attendance and timeliness', 'attendance', 15, 6],
            ];
            foreach ($default_criteria as $c) {
                $wpdb->insert($perf_review_criteria, [
                    'name'        => $c[0],
                    'description' => $c[1],
                    'category'    => $c[2],
                    'weight'      => $c[3],
                    'sort_order'  => $c[4],
                    'active'      => 1,
                    'created_at'  => $now,
                ]);
            }
        }

        /** Seed Departments + assign */
        $has_dept = (int)$wpdb->get_var("SELECT COUNT(*) FROM `$dept`");
        if ($has_dept === 0) {
            $now = current_time('mysql');
            $wpdb->insert($dept, [
                'name' => 'General',
                'manager_user_id' => null,
                'auto_role' => null,
                'approver_role' => null,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $general_id = (int)$wpdb->insert_id;
            if ($general_id > 0) {
                $wpdb->query( $wpdb->prepare("UPDATE `$emp` SET dept_id=%d WHERE dept_id IS NULL OR dept_id=0", $general_id) );
            }
        }

        /** Seed leave types if empty */
        $count_types = (int)$wpdb->get_var("SELECT COUNT(*) FROM `$types`");
        if ($count_types === 0) {
            $now = current_time('mysql');
            $seed = [
                ['Annual', 1, 1, 30, 0, 1],
                ['Sick',   1, 1, 10, 0, 0],
                ['Unpaid', 0, 1,  0, 1, 0],
            ];
            foreach ($seed as $r) {
                $wpdb->insert($types, [
                    'name' => $r[0],
                    'is_paid' => $r[1],
                    'requires_approval' => $r[2],
                    'annual_quota' => $r[3],
                    'allow_negative' => $r[4],
                    'is_annual' => $r[5],
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } else {
            $wpdb->query("UPDATE `$types` SET is_annual=1 WHERE (LOWER(name)='annual' OR LOWER(name)='annual leave' OR LOWER(name)='annual vacation' OR LOWER(name) LIKE 'annual %') AND is_annual=0");
        }

        /** Policy defaults */
        add_option('sfs_hr_annual_lt5', '21'); // <5y
        add_option('sfs_hr_annual_ge5', '30'); // >=5y
        add_option('sfs_hr_global_approver_role', get_option('sfs_hr_global_approver_role','sfs_hr_manager') ?: 'sfs_hr_manager');

        /** Recalculate balances for current year (non-destructive) */
        self::recalc_all_current_year();
    }

    private static function add_column_if_missing(string $table, string $column, string $definition): void {
        global $wpdb;
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
            $table, $column
        ));
        if ($exists === 0) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    private static function make_column_nullable_if_exists(string $table, string $column, string $type): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT IS_NULLABLE, DATA_TYPE, COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
            $table, $column
        ), ARRAY_A);
        if ($row && strtoupper((string)$row['IS_NULLABLE']) === 'NO') {
            // Relax NOT NULL to NULL, preserving unsigned/length
            $coltype = $row['COLUMN_TYPE'] ?: $type;
            $wpdb->query("ALTER TABLE `$table` MODIFY `$column` $coltype NULL");
        }
    }

    private static function make_text_if_varchar255(string $table, string $column): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
             FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
            $table, $column
        ), ARRAY_A);
        if ($row && strtolower((string)$row['DATA_TYPE']) === 'varchar' && (int)$row['CHARACTER_MAXIMUM_LENGTH'] === 255) {
            $wpdb->query("ALTER TABLE `$table` MODIFY `$column` TEXT NULL");
        }
    }

    /**
     * Add unique key if it doesn't exist
     */
    private static function add_unique_key_if_missing(string $table, string $column, string $key_name): void {
        global $wpdb;
        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
            $table, $key_name
        ));
        if ((int)$index_exists === 0) {
            // Check if column exists first
            $col_exists = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
                $table, $column
            ));
            if ($col_exists > 0) {
                $wpdb->query("ALTER TABLE `$table` ADD UNIQUE KEY `$key_name` (`$column`)");
            }
        }
    }

    /**
     * Backfill reference numbers for existing records
     */
    private static function backfill_request_numbers(): void {
        global $wpdb;

        // Leave requests
        $req_table = $wpdb->prefix . 'sfs_hr_leave_requests';
        $missing_leave = $wpdb->get_results(
            "SELECT id, created_at FROM `$req_table` WHERE request_number IS NULL OR request_number = '' ORDER BY id ASC"
        );
        foreach ($missing_leave as $row) {
            $number = self::generate_request_number('LV', $req_table, $row->created_at);
            $wpdb->update($req_table, ['request_number' => $number], ['id' => $row->id]);
        }

        // Resignations
        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';
        $missing_resign = $wpdb->get_results(
            "SELECT id, created_at FROM `$resign_table` WHERE request_number IS NULL OR request_number = '' ORDER BY id ASC"
        );
        foreach ($missing_resign as $row) {
            $number = self::generate_request_number('RS', $resign_table, $row->created_at);
            $wpdb->update($resign_table, ['request_number' => $number], ['id' => $row->id]);
        }

        // Settlements
        $settle_table = $wpdb->prefix . 'sfs_hr_settlements';
        $missing_settle = $wpdb->get_results(
            "SELECT id, created_at FROM `$settle_table` WHERE request_number IS NULL OR request_number = '' ORDER BY id ASC"
        );
        foreach ($missing_settle as $row) {
            $number = self::generate_request_number('ST', $settle_table, $row->created_at);
            $wpdb->update($settle_table, ['request_number' => $number], ['id' => $row->id]);
        }
    }

    /**
     * Generate a reference number for a request
     * Format: PREFIX-YYYY-NNNN (e.g., LV-2026-0001)
     * @deprecated Use \SFS\HR\Core\Helpers::generate_reference_number() instead
     */
    public static function generate_request_number(string $prefix, string $table, ?string $created_at = null): string {
        return \SFS\HR\Core\Helpers::generate_reference_number( $prefix, $table, 'request_number', $created_at );
    }

    /**
     * Recalculate balances for current year from approved requests (non-destructive).
     * Uses hire_date if available, otherwise falls back to hired_at.
     */
    private static function recalc_all_current_year(): void {
        global $wpdb;
        $year  = (int)current_time('Y');
        $types = $wpdb->prefix.'sfs_hr_leave_types';
        $emp   = $wpdb->prefix.'sfs_hr_employees';
        $req   = $wpdb->prefix.'sfs_hr_leave_requests';
        $bal   = $wpdb->prefix.'sfs_hr_leave_balances';

        $employees = $wpdb->get_col("SELECT id FROM `$emp` WHERE status='active'");
        $type_rows = $wpdb->get_results("SELECT id, annual_quota, is_annual FROM `$types` WHERE active=1", ARRAY_A);
        if (!$employees || !$type_rows) return;

        $lt5 = (int)get_option('sfs_hr_annual_lt5','21');
        $ge5 = (int)get_option('sfs_hr_annual_ge5','30');

        foreach ($employees as $eid) {
            // tenure from hire_date OR hired_at
            $row = $wpdb->get_row($wpdb->prepare("SELECT hire_date, hired_at FROM `$emp` WHERE id=%d", $eid), ARRAY_A);
            $hire = null;
            if ($row) {
                $hire = $row['hire_date'] ?: ($row['hired_at'] ?? null);
            }

            $years = 0;
            if ($hire) {
                $as_of = strtotime($year.'-01-01 00:00:00');
                $hd    = strtotime($hire.' 00:00:00');
                if ($hd) {
                    $years = (int) floor( ($as_of - $hd) / (365.2425 * DAY_IN_SECONDS) );
                    if ($years < 0) $years = 0;
                }
            }

            foreach ($type_rows as $tr) {
                $tid   = (int)$tr['id'];
                $quota = (int)$tr['annual_quota'];
                if (!empty($tr['is_annual'])) {
                    $quota = ($years >= 5) ? $ge5 : $lt5;
                }

                $used = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(days),0) FROM `$req`
                     WHERE employee_id=%d AND type_id=%d AND status='approved' AND YEAR(start_date)=%d",
                    $eid, $tid, $year
                ));

                $row_id  = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `$bal` WHERE employee_id=%d AND type_id=%d AND year=%d",
                    $eid, $tid, $year
                ));
                $opening = 0; $carried = 0; $accrued = $quota;
                $closing = $opening + $accrued + $carried - $used;

                if ($row_id) {
                    $wpdb->update($bal, [
                        'opening'=>$opening, 'accrued'=>$accrued, 'used'=>$used, 'carried_over'=>$carried, 'closing'=>$closing,
                        'updated_at'=> current_time('mysql')
                    ], ['id'=>$row_id]);
                } else {
                    $wpdb->insert($bal, [
                        'employee_id'=>$eid,'type_id'=>$tid,'year'=>$year,
                        'opening'=>$opening,'accrued'=>$accrued,'used'=>$used,'carried_over'=>$carried,'closing'=>$closing,
                        'updated_at'=> current_time('mysql')
                    ]);
                }
            }
        }
    }
}
