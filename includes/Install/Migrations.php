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
        self::add_column_if_missing($emp, 'bank_name',               "VARCHAR(100) NULL");
        self::add_column_if_missing($emp, 'bank_account',            "VARCHAR(50) NULL");
        self::add_column_if_missing($emp, 'iban',                    "VARCHAR(50) NULL");
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
        // HR responsible person per department (for performance justification)
        self::add_column_if_missing($dept, 'hr_responsible_user_id', "BIGINT(20) UNSIGNED NULL AFTER `manager_user_id`");

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
        self::add_column_if_missing($types, 'name_translations', "TEXT NULL"); // JSON: {"ar":"...","ur":"...","fil":"..."}
        // M2: leave type control columns
        self::add_column_if_missing($types, 'code',              "VARCHAR(64) NULL");
        self::add_column_if_missing($types, 'color',             "VARCHAR(7) NOT NULL DEFAULT '#2271b1'");
        self::add_column_if_missing($types, 'gender_required',   "VARCHAR(10) NOT NULL DEFAULT 'any'");
        self::add_column_if_missing($types, 'requires_attachment', "TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'skip_managers_gm',  "TINYINT(1) NOT NULL DEFAULT 0");
        // M2.1: carry-forward
        self::add_column_if_missing($types, 'allow_carry_forward',          "TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'max_carry_forward',            "INT NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'carry_forward_expiry_months',  "INT NOT NULL DEFAULT 0");
        // M2.2: encashment
        self::add_column_if_missing($types, 'allow_encashment',    "TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'max_encashment_days', "INT NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'min_balance_after',   "INT NOT NULL DEFAULT 0");
        // M2.3: compensatory
        self::add_column_if_missing($types, 'is_compensatory',  "TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'comp_expiry_days', "INT NOT NULL DEFAULT 0");
        // M2.4: half-day / hourly
        self::add_column_if_missing($types, 'allow_half_day',   "TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'allow_hourly',     "TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'hours_per_day',    "DECIMAL(3,1) NOT NULL DEFAULT 8.0");
        // M2.5: additional controls
        self::add_column_if_missing($types, 'max_days_per_request',  "INT NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'once_per_employment',   "TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($types, 'min_tenure_months',     "INT NOT NULL DEFAULT 0");

        // M2.4: half-day / hourly on leave requests
        self::add_column_if_missing($req, 'leave_mode',      "VARCHAR(10) NOT NULL DEFAULT 'full_day'");
        self::add_column_if_missing($req, 'half_day_period',  "VARCHAR(2) NULL");
        self::add_column_if_missing($req, 'hours',            "DECIMAL(4,1) NULL");

        self::add_column_if_missing($req, 'pay_breakdown', "TEXT NULL");   // JSON: [{days:30, rate:1}, ...]
        self::add_column_if_missing($req, 'paid_days',     "INT NOT NULL DEFAULT 0");
        self::add_column_if_missing($req, 'unpaid_days',   "INT NOT NULL DEFAULT 0");

        // M2: balance tracking columns
        self::add_column_if_missing($bal, 'carried_expiry_date', "DATE NULL");
        self::add_column_if_missing($bal, 'expired_days',        "INT NOT NULL DEFAULT 0");
        self::add_column_if_missing($bal, 'encashed',            "INT NOT NULL DEFAULT 0");


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
        // Hold / date-update columns for HR actions on approved leaves
        self::add_column_if_missing($req, 'hold_reason',         "TEXT NULL");
        self::add_column_if_missing($req, 'held_by',             "BIGINT(20) UNSIGNED NULL");
        self::add_column_if_missing($req, 'held_at',             "DATETIME NULL");
        self::add_column_if_missing($req, 'original_start_date', "DATE NULL");
        self::add_column_if_missing($req, 'original_end_date',   "DATE NULL");
        self::add_column_if_missing($req, 'original_days',       "INT NULL");
        self::add_column_if_missing($req, 'date_update_reason',  "TEXT NULL");
        self::add_column_if_missing($req, 'date_updated_by',     "BIGINT(20) UNSIGNED NULL");
        self::add_column_if_missing($req, 'date_updated_at',     "DATETIME NULL");

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
        self::add_column_if_missing($settle, 'trigger_type', "VARCHAR(20) NOT NULL DEFAULT 'resignation'");

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
        $cand_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $candidates));
        if ($cand_exists) {
            self::add_column_if_missing($candidates, 'request_number', "VARCHAR(50) NULL");
            self::add_unique_key_if_missing($candidates, 'request_number', 'request_number');
            self::add_column_if_missing($candidates, 'approval_chain', "LONGTEXT NULL");
            self::add_column_if_missing($candidates, 'hr_reviewer_id', "BIGINT(20) UNSIGNED NULL");
            self::add_column_if_missing($candidates, 'hr_reviewed_at', "DATETIME NULL");
            self::add_column_if_missing($candidates, 'hr_notes', "TEXT NULL");

            // Expand ENUM to include hr_reviewed status
            $col_row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM `{$candidates}` LIKE %s", 'status'), ARRAY_A);
            $col_type = $col_row ? $col_row['Type'] : null;
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

        /** EMPLOYEES – language preference (synced to WP user locale) */
        self::add_column_if_missing($emp, 'language', "VARCHAR(10) NULL DEFAULT NULL");

        /** EMPLOYEES – hide from attendance sessions & performance reports */
        self::add_column_if_missing($emp, 'hidden_from_attendance', "TINYINT(1) NOT NULL DEFAULT 0");

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

        /** M2.2: LEAVE ENCASHMENT REQUESTS */
        $leave_encash = $wpdb->prefix . 'sfs_hr_leave_encashment';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$leave_encash` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `type_id` BIGINT(20) UNSIGNED NOT NULL,
            `year` SMALLINT(5) UNSIGNED NOT NULL,
            `days` INT NOT NULL,
            `daily_rate` DECIMAL(18,2) NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `approver_id` BIGINT(20) UNSIGNED NULL,
            `approver_note` TEXT NULL,
            `decided_at` DATETIME NULL,
            `payroll_run_id` BIGINT(20) UNSIGNED NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `emp_type_year` (`employee_id`, `type_id`, `year`),
            KEY `status_idx` (`status`)
        ) $charset");

        /** M2.3: COMPENSATORY LEAVE REQUESTS */
        $leave_comp = $wpdb->prefix . 'sfs_hr_leave_compensatory';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$leave_comp` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `work_date` DATE NOT NULL,
            `session_id` BIGINT(20) UNSIGNED NULL,
            `hours_worked` DECIMAL(5,2) NOT NULL DEFAULT 0,
            `days_earned` INT NOT NULL DEFAULT 1,
            `reason` TEXT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `approver_id` BIGINT(20) UNSIGNED NULL,
            `approver_note` TEXT NULL,
            `decided_at` DATETIME NULL,
            `expiry_date` DATE NULL,
            `credited` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `emp_idx` (`employee_id`),
            KEY `status_idx` (`status`),
            KEY `expiry_idx` (`expiry_date`)
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

        // Performance Justifications (per-period, by HR responsible)
        $perf_justifications = $wpdb->prefix.'sfs_hr_performance_justifications';
        $wpdb->query("CREATE TABLE IF NOT EXISTS `$perf_justifications` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `period_start` DATE NOT NULL,
            `period_end` DATE NOT NULL,
            `justification` TEXT NOT NULL,
            `written_by` BIGINT(20) UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_emp_period` (`employee_id`, `period_start`, `period_end`),
            KEY `idx_employee` (`employee_id`),
            KEY `idx_period` (`period_start`, `period_end`)
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

        /** ATTENDANCE SHIFTS — merge policy fields into shifts (v0.3.8) */
        $att_shifts = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $shift_tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $att_shifts ) );
        if ( $shift_tbl_exists ) {
            self::add_column_if_missing( $att_shifts, 'clock_in_methods',  "VARCHAR(255) NULL DEFAULT NULL" );
            self::add_column_if_missing( $att_shifts, 'clock_out_methods', "VARCHAR(255) NULL DEFAULT NULL" );
            self::add_column_if_missing( $att_shifts, 'break_methods',     "VARCHAR(255) NULL DEFAULT NULL" );
            self::add_column_if_missing( $att_shifts, 'geofence_in',       "VARCHAR(20) NULL DEFAULT NULL" );
            self::add_column_if_missing( $att_shifts, 'geofence_out',      "VARCHAR(20) NULL DEFAULT NULL" );
            self::add_column_if_missing( $att_shifts, 'calculation_mode',  "VARCHAR(20) NULL DEFAULT NULL" );
            self::add_column_if_missing( $att_shifts, 'target_hours',      "DECIMAL(4,2) NULL DEFAULT NULL" );
            self::add_column_if_missing( $att_shifts, 'overtime_buffer_minutes', "SMALLINT UNSIGNED NULL DEFAULT NULL" );
        }

        /** ATTENDANCE POLICIES — add break_methods column if missing */
        $att_policies_tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $att_policies ) );
        if ( $att_policies_tbl_exists ) {
            self::add_column_if_missing( $att_policies, 'break_methods', "VARCHAR(255) NULL DEFAULT NULL" );
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

        // M2: Backfill Saudi-specific leave types on existing installs.
        // Each row is [name, is_paid, requires_approval, annual_quota, allow_negative, is_annual, special_code, gender_required, once_per_employment, max_days_per_request].
        // Only inserts if no type with that special_code already exists.
        $saudi_types = [
            [ 'Hajj Leave',        1, 1, 15, 0, 0, 'HAJJ',        'any',    1, 15 ],
            [ 'Maternity Leave',   1, 1, 70, 0, 0, 'MATERNITY',   'female', 0, 70 ],
            [ 'Paternity Leave',   1, 1,  3, 0, 0, 'PATERNITY',   'male',   0,  3 ],
            [ 'Marriage Leave',    1, 1,  5, 0, 0, 'MARRIAGE',     'any',    1,  5 ],
            [ 'Bereavement Leave', 1, 1,  5, 0, 0, 'BEREAVEMENT', 'any',    0,  5 ],
            [ 'Iddah Leave',       1, 1,130, 0, 0, 'IDDAH',       'female', 0,130 ],
            [ 'Exam Leave',        0, 1, 10, 0, 0, 'EXAM',        'any',    0, 10 ],
        ];
        $now_seed = current_time( 'mysql' );
        foreach ( $saudi_types as $st ) {
            $exists_code = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `$types` WHERE special_code = %s LIMIT 1",
                $st[6]
            ) );
            if ( ! $exists_code ) {
                $wpdb->insert( $types, [
                    'name'                => $st[0],
                    'is_paid'             => $st[1],
                    'requires_approval'   => $st[2],
                    'annual_quota'        => $st[3],
                    'allow_negative'      => $st[4],
                    'is_annual'           => $st[5],
                    'special_code'        => $st[6],
                    'gender_required'     => $st[7],
                    'once_per_employment' => $st[8],
                    'max_days_per_request' => $st[9],
                    'active'              => 1,
                    'created_at'          => $now_seed,
                    'updated_at'          => $now_seed,
                ] );
            }
        }

        /** Policy defaults */
        add_option('sfs_hr_annual_lt5', '21'); // <5y
        add_option('sfs_hr_annual_ge5', '30'); // >=5y
        add_option('sfs_hr_global_approver_role', get_option('sfs_hr_global_approver_role','sfs_hr_manager') ?: 'sfs_hr_manager');

        /** PERFORMANCE INDEXES — idempotent, safe to re-run */
        self::ensure_performance_indexes();

        /** Recalculate balances for current year (non-destructive) */
        self::recalc_all_current_year();
    }

    private static function add_column_if_missing(string $table, string $column, string $definition): void {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));
        if (!$exists) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    private static function make_column_nullable_if_exists(string $table, string $column, string $type): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column), ARRAY_A);
        if ($row && strtoupper((string)$row['Null']) === 'NO') {
            // Relax NOT NULL to NULL, preserving unsigned/length
            $coltype = $row['Type'] ?: $type;
            $wpdb->query("ALTER TABLE `$table` MODIFY `$column` $coltype NULL");
        }
    }

    private static function make_text_if_varchar255(string $table, string $column): void {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column), ARRAY_A);
        if ($row && stripos((string)$row['Type'], 'varchar(255)') === 0) {
            $wpdb->query("ALTER TABLE `$table` MODIFY `$column` TEXT NULL");
        }
    }

    /**
     * Add unique key if it doesn't exist
     */
    private static function add_unique_key_if_missing(string $table, string $column, string $key_name): void {
        global $wpdb;
        // information_schema acceptable here — migration-only, version-gated; no clean SHOW equivalent for index names
        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
            $table, $key_name
        ));
        if ((int)$index_exists === 0) {
            // Check if column exists first
            $col_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));
            if ($col_exists) {
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

    /**
     * Add index if it doesn't already exist.
     */
    private static function add_index_if_missing(string $table, string $index_name, string $columns): void {
        global $wpdb;
        // information_schema acceptable here — migration-only, version-gated; no clean SHOW equivalent for index names
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
            $table, $index_name
        ) );
        if ( $exists === 0 ) {
            $wpdb->query( "ALTER TABLE `$table` ADD INDEX `$index_name` ($columns)" );
        }
    }

    /**
     * Create performance indexes across all major tables.
     */
    private static function ensure_performance_indexes(): void {
        global $wpdb;

        // Employees
        $emp = $wpdb->prefix . 'sfs_hr_employees';
        self::add_index_if_missing( $emp, 'idx_dept_id',    '`dept_id`' );
        self::add_index_if_missing( $emp, 'idx_status',     '`status`' );

        // Attendance punches (most critical for kiosk)
        $punches = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $punches ) );
        if ( $tbl_exists ) {
            self::add_index_if_missing( $punches, 'idx_employee_date',   '`employee_id`, `punch_time`' );
            self::add_index_if_missing( $punches, 'idx_date_type',       '`punch_time`, `punch_type`' );
            self::add_index_if_missing( $punches, 'idx_emp_type_date',   '`employee_id`, `punch_type`, `punch_time`' );
        }

        // Shift assignments
        $assigns = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';
        $tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $assigns ) );
        if ( $tbl_exists ) {
            self::add_index_if_missing( $assigns, 'idx_emp_date',  '`employee_id`, `work_date`' );
            self::add_index_if_missing( $assigns, 'idx_shift_id',  '`shift_id`' );
            self::add_index_if_missing( $assigns, 'idx_work_date', '`work_date`' );
        }

        // Sessions
        $sessions = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sessions ) );
        if ( $tbl_exists ) {
            self::add_index_if_missing( $sessions, 'idx_emp_date',  '`employee_id`, `work_date`' );
            self::add_index_if_missing( $sessions, 'idx_work_date', '`work_date`' );
            self::add_index_if_missing( $sessions, 'idx_status',    '`status`' );
        }

        // Audit trail
        $audit = $wpdb->prefix . 'sfs_hr_audit_trail';
        $tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $audit ) );
        if ( $tbl_exists ) {
            self::add_index_if_missing( $audit, 'idx_entity_type', '`entity_type`' );
            self::add_index_if_missing( $audit, 'idx_action',      '`action`' );
            self::add_index_if_missing( $audit, 'idx_created_at',  '`created_at`' );
            self::add_index_if_missing( $audit, 'idx_user_id',     '`user_id`' );
        }

        // Early leave requests
        $early = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $early ) );
        if ( $tbl_exists ) {
            self::add_index_if_missing( $early, 'idx_status_created', '`status`, `created_at`' );
            self::add_index_if_missing( $early, 'idx_employee_id',    '`employee_id`' );
        }

        // Loans
        $loans = $wpdb->prefix . 'sfs_hr_loans';
        $tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $loans ) );
        if ( $tbl_exists ) {
            self::add_index_if_missing( $loans, 'idx_employee_id', '`employee_id`' );
            self::add_index_if_missing( $loans, 'idx_status',      '`status`' );
        }

        // Loan payments
        $loan_pay = $wpdb->prefix . 'sfs_hr_loan_payments';
        $tbl_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $loan_pay ) );
        if ( $tbl_exists ) {
            self::add_index_if_missing( $loan_pay, 'idx_loan_id',  '`loan_id`' );
            self::add_index_if_missing( $loan_pay, 'idx_due_date', '`due_date`' );
            self::add_index_if_missing( $loan_pay, 'idx_status',   '`status`' );
        }
    }

    /**
     * One-time cleanup: remove false auto-created early leave requests.
     *
     * Bug: The system created bogus early leave requests for:
     *   1) Employees who left ON TIME or AFTER shift end ("left 0 minutes before shift end")
     *   2) Total-hours policy employees where the irrelevant shift end time was used
     *      (e.g., "left 292 minutes before shift end" when their policy is 8-hour duty)
     *
     * This runs once per installation via the option flag below.
     */
    public static function cleanup_false_early_leave_requests(): void {
        $flag = 'sfs_hr_cleaned_false_early_leaves';
        if ( get_option( $flag ) ) {
            return; // already ran
        }

        global $wpdb;
        $el_table  = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $pol_table = $wpdb->prefix . 'sfs_hr_attendance_policies';
        $pr_table  = $wpdb->prefix . 'sfs_hr_attendance_policy_roles';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // 1) Delete "left 0 minutes" — employee was on time or late, not early
        $deleted_zero = (int) $wpdb->query(
            "DELETE FROM `{$el_table}`
             WHERE reason_note LIKE 'Auto-created: employee left 0 minutes%'
               AND status = 'pending'"
        );

        // 2) Delete auto-created requests for total-hours policy employees where
        //    the shift end time was wrongly used. These are identifiable by:
        //    - Auto-created reason note ("Auto-created: employee left%")
        //    - Employee is on a total_hours policy
        //    - Status is still pending (approved ones are left untouched)
        $deleted_th = (int) $wpdb->query(
            "DELETE el FROM `{$el_table}` el
             INNER JOIN `{$emp_table}` e ON e.id = el.employee_id
             INNER JOIN `{$wpdb->usermeta}` um ON um.user_id = e.user_id AND um.meta_key = '{$wpdb->prefix}capabilities'
             INNER JOIN `{$pr_table}` pr ON LOCATE(pr.role_slug, um.meta_value) > 0
             INNER JOIN `{$pol_table}` p ON p.id = pr.policy_id AND p.calculation_mode = 'total_hours' AND p.active = 1
             WHERE el.reason_note LIKE 'Auto-created: employee left%'
               AND el.status = 'pending'"
        );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Early leave cleanup: removed %d zero-minute + %d total-hours false requests.',
                $deleted_zero, $deleted_th
            ) );
        }

        update_option( $flag, '1' );
    }

    /**
     * One-time repair: recalculate attendance sessions for "Total Hours" shifts
     * (start_time == end_time, e.g. 00:00–00:00).
     *
     * Before the fix, these shifts built a bogus 24-hour segment (midnight →
     * midnight+1 day) which incorrectly flagged every employee as "Late",
     * "Left early", and "No break taken".  This migration re-runs the
     * calculation engine for every affected session to store correct flags.
     */
    public static function repair_total_hours_sessions(): void {
        $flag = 'sfs_hr_repaired_total_hours_sessions';
        if ( get_option( $flag ) ) {
            return; // already ran
        }

        global $wpdb;
        $shifts_table   = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $assign_table   = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';
        $sessions_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';

        // Find all sessions linked to shifts where start_time == end_time
        // (the problematic "Total Hours" shifts).
        $rows = $wpdb->get_results(
            "SELECT DISTINCT s.employee_id, s.work_date
             FROM {$sessions_table} s
             INNER JOIN {$assign_table} sa
                 ON sa.employee_id = s.employee_id AND sa.work_date = s.work_date
             INNER JOIN {$shifts_table} sh
                 ON sh.id = sa.shift_id
             WHERE sh.start_time = sh.end_time
               AND ( s.flags_json LIKE '%late%'
                  OR s.flags_json LIKE '%left_early%'
                  OR s.flags_json LIKE '%no_break_taken%' )"
        );

        $repaired = 0;
        if ( ! empty( $rows ) ) {
            foreach ( $rows as $r ) {
                \SFS\HR\Modules\Attendance\AttendanceModule::recalc_session_for(
                    (int) $r->employee_id,
                    $r->work_date,
                    $wpdb
                );
                $repaired++;
            }
        }

        // Also clean up false early-leave requests for total-hours shifts
        // identified by equal start/end times.
        $el_table = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $deleted_el = (int) $wpdb->query(
            "DELETE el FROM `{$el_table}` el
             INNER JOIN `{$assign_table}` sa
                 ON sa.employee_id = el.employee_id AND sa.work_date = el.request_date
             INNER JOIN `{$shifts_table}` sh
                 ON sh.id = sa.shift_id
             WHERE sh.start_time = sh.end_time
               AND el.reason_note LIKE 'Auto-created:%'
               AND el.status = 'pending'"
        );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Total-hours repair: recalculated %d sessions, removed %d false early-leave requests.',
                $repaired, $deleted_el
            ) );
        }

        update_option( $flag, '1' );
    }

    /**
     * One-time cleanup: remove stale manager_adjust OUT punches from overnight
     * shift workarounds.
     *
     * These punches were manually created to close broken overnight sessions
     * before the overnight logic was fixed in code.  They appear as OUT punches
     * with source='manager_adjust' in the early-morning hours (00:00–05:00 local)
     * where no corresponding manager_adjust IN punch exists nearby.
     *
     * After removal, affected sessions are rebuilt from the remaining punches.
     */
    public static function cleanup_stale_overnight_adjust_punches(): void {
        $flag = 'sfs_hr_cleaned_stale_overnight_adjusts';
        if ( get_option( $flag ) ) {
            return; // already ran
        }

        global $wpdb;
        $pT = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $sT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $tz = wp_timezone();

        // Find manager_adjust OUT punches in the early morning (00:00–05:00 local)
        // that lack a paired manager_adjust IN punch on the same day.
        // These are standalone OUT punches injected to close overnight sessions.
        $early_morning_start = '00:00:00';
        $early_morning_end   = '05:00:00';

        // Convert local early-morning boundaries for the last 7 days to UTC
        // to catch recently-created workaround punches.
        $affected_dates = [];
        $deleted        = 0;

        for ( $i = 0; $i < 7; $i++ ) {
            $day = wp_date( 'Y-m-d', strtotime( "-{$i} days" ) );
            $utc_from = ( new \DateTimeImmutable( $day . ' ' . $early_morning_start, $tz ) )
                ->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
            $utc_to = ( new \DateTimeImmutable( $day . ' ' . $early_morning_end, $tz ) )
                ->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

            $del = (int) $wpdb->query( $wpdb->prepare(
                "DELETE p FROM `{$pT}` p
                 WHERE p.punch_type = 'out'
                   AND p.source     = 'manager_adjust'
                   AND p.punch_time >= %s
                   AND p.punch_time <  %s
                   AND NOT EXISTS (
                       SELECT 1 FROM (SELECT id, employee_id, punch_time FROM `{$pT}`
                                      WHERE source = 'manager_adjust'
                                        AND punch_type = 'in'
                                        AND punch_time >= %s
                                        AND punch_time < %s) AS adj_in
                       WHERE adj_in.employee_id = p.employee_id
                   )",
                $utc_from, $utc_to, $utc_from, $utc_to
            ) );

            if ( $del > 0 ) {
                $deleted += $del;
                $affected_dates[] = $day;
            }
        }

        // Rebuild sessions for affected dates
        if ( ! empty( $affected_dates ) ) {
            foreach ( $affected_dates as $day ) {
                \SFS\HR\Modules\Attendance\AttendanceModule::rebuild_sessions_for_date_static( $day );
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Overnight adjust cleanup: removed %d stale manager_adjust OUT punch(es) on %d date(s).',
                $deleted, count( $affected_dates )
            ) );
        }

        update_option( $flag, '1' );
    }

    /**
     * One-time repair: backfill missing early leave requests for sessions that
     * have left_early status but no corresponding early leave request.
     *
     * This can happen when:
     *  - Sessions were calculated before auto-creation was added.
     *  - Cleanup migrations removed false requests but recalculation didn't
     *    re-create them (because the left_early flag already existed).
     */
    public static function backfill_missing_early_leave_requests(): void {
        $flag = 'sfs_hr_backfilled_early_leave_requests';
        if ( get_option( $flag ) ) {
            return; // already ran
        }

        global $wpdb;
        $sT  = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $elT = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $eT  = $wpdb->prefix . 'sfs_hr_employees';
        $dT  = $wpdb->prefix . 'sfs_hr_departments';

        // Find sessions with left_early status that have no corresponding
        // pending or approved early leave request.
        $orphans = $wpdb->get_results(
            "SELECT s.id AS session_id, s.employee_id, s.work_date, s.out_time,
                    s.rounded_net_minutes, s.calc_meta_json
             FROM `{$sT}` s
             WHERE s.status = 'left_early'
               AND NOT EXISTS (
                   SELECT 1 FROM `{$elT}` el
                   WHERE el.employee_id = s.employee_id
                     AND el.request_date = s.work_date
                     AND el.status IN ('pending','approved')
               )
             ORDER BY s.work_date ASC"
        );

        $created = 0;
        $tz = wp_timezone();

        foreach ( (array) $orphans as $s ) {
            // Parse calc_meta for target/scheduled info
            $meta = $s->calc_meta_json ? json_decode( $s->calc_meta_json, true ) : [];
            $is_th = ( $meta['policy_mode'] ?? '' ) === 'total_hours';

            // Calculate minutes early
            $minutes_early = 0;
            if ( $is_th && ! empty( $meta['target_minutes'] ) ) {
                $minutes_early = max( 0, (int) $meta['target_minutes'] - (int) $s->rounded_net_minutes );
            } elseif ( ! empty( $meta['segments'] ) ) {
                foreach ( $meta['segments'] as $seg ) {
                    $minutes_early += (int) ( $seg['early_minutes'] ?? 0 );
                }
            }

            if ( $minutes_early <= 0 ) {
                continue; // on time or no useful data
            }

            // Actual leave time
            $actual_leave_local = null;
            if ( $s->out_time ) {
                try {
                    $utc_out = new \DateTimeImmutable( $s->out_time, new \DateTimeZone( 'UTC' ) );
                    $actual_leave_local = $utc_out->setTimezone( $tz )->format( 'H:i:s' );
                } catch ( \Throwable $e ) { /* skip */ }
            }

            // Find department manager
            $mgr_id = null;
            $emp_dept = $wpdb->get_var( $wpdb->prepare(
                "SELECT dept_id FROM `{$eT}` WHERE id = %d", (int) $s->employee_id
            ) );
            if ( $emp_dept ) {
                $mgr_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT manager_user_id FROM `{$dT}` WHERE id = %d", (int) $emp_dept
                ) );
            }

            // Generate reference number
            $ref = \SFS\HR\Core\Helpers::generate_reference_number(
                'EL', $elT, 'request_number'
            );

            $reason = $is_th
                ? sprintf( 'Auto-created: employee worked %d minutes less than required hours.', $minutes_early )
                : sprintf( 'Auto-created: employee left %d minutes before shift end.', $minutes_early );

            $now = current_time( 'mysql' );
            $wpdb->insert( $elT, [
                'employee_id'          => (int) $s->employee_id,
                'session_id'           => (int) $s->session_id,
                'request_date'         => $s->work_date,
                'scheduled_end_time'   => null,
                'requested_leave_time' => $actual_leave_local,
                'actual_leave_time'    => $actual_leave_local,
                'reason_type'          => 'other',
                'reason_note'          => $reason,
                'status'               => 'pending',
                'request_number'       => $ref,
                'manager_id'           => $mgr_id ? (int) $mgr_id : null,
                'affects_salary'       => 0,
                'created_at'           => $now,
                'updated_at'           => $now,
            ] );
            $created++;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Early leave backfill: created %d missing early leave request(s).',
                $created
            ) );
        }

        update_option( $flag, '1' );
    }
}
