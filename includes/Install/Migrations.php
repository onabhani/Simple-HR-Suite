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
