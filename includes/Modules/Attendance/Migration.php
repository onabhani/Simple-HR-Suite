<?php
namespace SFS\HR\Modules\Attendance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Migration
 *
 * Handles all attendance table creation, column migration, FK migration,
 * capability registration, and seed data logic.
 *
 * Extracted from AttendanceModule to reduce god-class size.
 */
class Migration {

    /**
     * Main entry point — runs all table creation, column migrations,
     * FK migrations, cap registration, and seed data.
     */
    public function run(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        // 1) punches (immutable events)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_punches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            punch_type ENUM('in','out','break_start','break_end') NOT NULL,
            punch_time DATETIME NOT NULL,
            source ENUM('self_web','self_mobile','kiosk','manager_adjust','import_sync') NOT NULL,
            device_id BIGINT UNSIGNED NULL,
            ip_addr VARCHAR(45) NULL,
            geo_lat DECIMAL(10,7) NULL,
            geo_lng DECIMAL(10,7) NULL,
            geo_accuracy_m SMALLINT UNSIGNED NULL,
            selfie_media_id BIGINT UNSIGNED NULL,
            valid_geo TINYINT(1) NOT NULL DEFAULT 1,
            valid_selfie TINYINT(1) NOT NULL DEFAULT 1,
            offline_origin TINYINT(1) NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY emp_time (employee_id, punch_time),
            KEY dev_time (device_id, punch_time),
            KEY punch_time (punch_time),
            KEY date_type (punch_time, punch_type),
            KEY source (source),
            KEY emp_type_date (employee_id, punch_type, punch_time)
        ) $charset_collate;");

        // Migration: Add offline_origin column for existing installations
        $punchesT = "{$p}sfs_hr_attendance_punches";
        $this->add_column_if_missing($wpdb, $punchesT, 'offline_origin', "offline_origin TINYINT(1) NOT NULL DEFAULT 0 AFTER valid_selfie");

        // 2) sessions (processed day rows for payroll)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            shift_assign_id BIGINT UNSIGNED NULL,
            work_date DATE NOT NULL,
            in_time DATETIME NULL,
            out_time DATETIME NULL,
            break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            break_delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            no_break_taken TINYINT(1) NOT NULL DEFAULT 0,
            net_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            rounded_net_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('present','late','left_early','absent','incomplete','on_leave','holiday','day_off') NOT NULL DEFAULT 'present',
            overtime_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            flags_json LONGTEXT NULL,
            calc_meta_json LONGTEXT NULL,
            last_recalc_at DATETIME NOT NULL,
            locked TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY emp_date (employee_id, work_date),
            KEY work_date (work_date),
            KEY status (status)
        ) $charset_collate;");

        // 3) shift templates (CRUD in admin; Ramadan, etc. handled via assignments)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_shifts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            location_label VARCHAR(100) NOT NULL,
            location_lat DECIMAL(10,7) NULL,
            location_lng DECIMAL(10,7) NULL,
            location_radius_m SMALLINT UNSIGNED NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            unpaid_break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            break_start_time TIME NULL,
            break_policy ENUM('auto','punch','none') NOT NULL DEFAULT 'auto',
            grace_late_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5,
            grace_early_leave_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5,
            rounding_rule ENUM('none','5','10','15') NOT NULL DEFAULT '5',
            overtime_after_minutes SMALLINT UNSIGNED NULL,
            require_selfie TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            dept_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            weekly_overrides TEXT NULL,
            period_overrides TEXT NULL,
            dept_ids TEXT NULL,
            PRIMARY KEY (id),
            KEY active_dept_id (active, dept_id),
            KEY dept_id (dept_id)
        ) $charset_collate;");

        // Migration: Add dept_id column if missing (for existing installations)
        $shifts_table = "{$p}sfs_hr_attendance_shifts";
        $dept_id_added = !$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$shifts_table} LIKE %s", 'dept_id'));
        $this->add_column_if_missing($wpdb, $shifts_table, 'dept_id', "dept_id BIGINT UNSIGNED NULL AFTER active");
        if ($dept_id_added) {
            $this->add_index_if_missing($wpdb, $shifts_table, 'dept_id', "ALTER TABLE {$shifts_table} ADD KEY dept_id (dept_id)");
        }

        // Migration: Add dept_ids column for multi-department support
        $dept_ids_added = !$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$shifts_table} LIKE %s", 'dept_ids'));
        $this->add_column_if_missing($wpdb, $shifts_table, 'dept_ids', "dept_ids TEXT NULL COMMENT 'JSON array of department IDs'");
        if ($dept_ids_added) {
            // Migrate existing single dept_id to dept_ids JSON
            $wpdb->query("UPDATE {$shifts_table} SET dept_ids = CONCAT('[', dept_id, ']') WHERE dept_id IS NOT NULL AND dept_ids IS NULL");
        }

        // Migration: Add period_overrides column for date-range time overrides (Ramadan, etc.)
        $this->add_column_if_missing($wpdb, $shifts_table, 'period_overrides', "period_overrides TEXT NULL COMMENT 'JSON array of date-range time overrides' AFTER weekly_overrides");

                // 4) daily assignments (rota)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_shift_assign (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            shift_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            is_holiday TINYINT(1) NOT NULL DEFAULT 0,
            override_json LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY emp_date (employee_id, work_date),
            KEY shift_date (shift_id, work_date),
            KEY work_date (work_date)
        ) $charset_collate;");

        // 5) employee default shifts (history)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_emp_shifts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            shift_id BIGINT UNSIGNED NOT NULL,
            schedule_id BIGINT UNSIGNED NULL,
            start_date DATE NOT NULL,
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY emp_date (employee_id, start_date),
            KEY shift_id (shift_id),
            KEY schedule_id (schedule_id)
        ) $charset_collate;");

        // Migration: Add schedule_id column to emp_shifts for existing installations
        $emp_shifts_tbl = "{$p}sfs_hr_attendance_emp_shifts";
        $sched_id_added = !$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$emp_shifts_tbl} LIKE %s", 'schedule_id'));
        $this->add_column_if_missing($wpdb, $emp_shifts_tbl, 'schedule_id', "schedule_id BIGINT UNSIGNED NULL COMMENT 'FK to shift_schedules' AFTER shift_id");
        if ($sched_id_added) {
            $this->add_index_if_missing($wpdb, $emp_shifts_tbl, 'schedule_id', "ALTER TABLE {$emp_shifts_tbl} ADD KEY schedule_id (schedule_id)");
        }

        // 5b) shift schedules (rotation patterns: week A/B, 4-on-4-off, etc.)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_shift_schedules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            cycle_days SMALLINT UNSIGNED NOT NULL,
            anchor_date DATE NOT NULL,
            entries TEXT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY active (active)
        ) $charset_collate;");

        // 6) devices (kiosks & locks)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_devices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            label VARCHAR(100) NOT NULL,
            type ENUM('kiosk','mobile','web') NOT NULL DEFAULT 'kiosk',
            kiosk_enabled TINYINT(1) NOT NULL DEFAULT 0,
            kiosk_pin VARCHAR(255) NULL,
            kiosk_offline TINYINT(1) NOT NULL DEFAULT 0,
            last_sync_at DATETIME NULL,
            geo_lock_lat DECIMAL(10,7) NULL,
            geo_lock_lng DECIMAL(10,7) NULL,
            geo_lock_radius_m SMALLINT UNSIGNED NULL,
            allowed_dept_id BIGINT UNSIGNED NULL,
            fingerprint_hash VARCHAR(64) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            qr_enabled TINYINT(1) NOT NULL DEFAULT 1,
            selfie_mode ENUM('inherit','never','in_only','in_out','all') NOT NULL DEFAULT 'inherit',
            PRIMARY KEY (id),
            KEY active_type (active, type),
            KEY fp (fingerprint_hash),
            KEY kiosk_enabled (kiosk_enabled),
            KEY allowed_dept_id (allowed_dept_id)
        ) $charset_collate;");


        // Harden upgrades: add columns if old table exists without them
        $t = "{$p}sfs_hr_attendance_devices";
        $this->add_column_if_missing($wpdb, $t, 'kiosk_enabled',     "kiosk_enabled TINYINT(1) NOT NULL DEFAULT 0");
        $this->add_column_if_missing($wpdb, $t, 'kiosk_offline',     "kiosk_offline TINYINT(1) NOT NULL DEFAULT 0");
        $this->add_column_if_missing($wpdb, $t, 'geo_lock_lat',      "geo_lock_lat DECIMAL(10,7) NULL");
        $this->add_column_if_missing($wpdb, $t, 'geo_lock_lng',      "geo_lock_lng DECIMAL(10,7) NULL");
        $this->add_column_if_missing($wpdb, $t, 'geo_lock_radius_m', "geo_lock_radius_m SMALLINT UNSIGNED NULL");
        $this->add_column_if_missing($wpdb, $t, 'allowed_dept_id',   "allowed_dept_id BIGINT UNSIGNED NULL");
        $this->add_column_if_missing($wpdb, $t, 'active',            "active TINYINT(1) NOT NULL DEFAULT 1");
        $this->add_column_if_missing($wpdb, $t, 'qr_enabled',        "qr_enabled TINYINT(1) NOT NULL DEFAULT 1");
        $this->add_column_if_missing($wpdb, $t, 'selfie_mode',       "selfie_mode ENUM('inherit','never','in_only','in_out','all') NOT NULL DEFAULT 'inherit'");
        $this->add_column_if_missing($wpdb, $t, 'suggest_in_time',            "suggest_in_time TIME NULL");
        $this->add_column_if_missing($wpdb, $t, 'suggest_break_start_time',   "suggest_break_start_time TIME NULL");
        $this->add_column_if_missing($wpdb, $t, 'suggest_break_end_time',     "suggest_break_end_time TIME NULL");
        $this->add_column_if_missing($wpdb, $t, 'suggest_out_time',           "suggest_out_time TIME NULL");
        $this->add_column_if_missing($wpdb, $t, 'break_enabled',              "break_enabled TINYINT(1) NOT NULL DEFAULT 0");


        // 6) flags (exceptions)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_flags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NULL,
            punch_id BIGINT UNSIGNED NULL,
            flag_code ENUM('late','early_leave','missed_punch','outside_geofence','no_selfie','overtime','manual_edit') NOT NULL,
            flag_status ENUM('open','approved','rejected') NOT NULL DEFAULT 'open',
            manager_comment TEXT NULL,
            created_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            resolved_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY status_only (flag_status),
            KEY emp_created (employee_id, created_at)
        ) $charset_collate;");

        // 7) audit (append-only)
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_user_id BIGINT UNSIGNED NULL,
            action_type VARCHAR(50) NOT NULL,
            target_employee_id BIGINT UNSIGNED NULL,
            target_punch_id BIGINT UNSIGNED NULL,
            target_session_id BIGINT UNSIGNED NULL,
            before_json LONGTEXT NULL,
            after_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY act_time (action_type, created_at),
            KEY emp_time (target_employee_id, created_at)
        ) $charset_collate;");

        // 8) Early Leave Requests - for manager approval workflow
        dbDelta("CREATE TABLE {$p}sfs_hr_early_leave_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NULL,
            request_date DATE NOT NULL,
            scheduled_end_time TIME NULL,
            requested_leave_time TIME NOT NULL,
            actual_leave_time TIME NULL,
            reason_type ENUM('sick','external_task','personal','emergency','other') NOT NULL DEFAULT 'other',
            reason_note TEXT NULL,
            status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            manager_id BIGINT UNSIGNED NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            manager_note TEXT NULL,
            affects_salary TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY emp_date (employee_id, request_date),
            KEY status_date (status, request_date),
            KEY manager_status (manager_id, status),
            KEY session_id (session_id)
        ) $charset_collate;");

        // ── M5.1: UTC Timestamp Normalization ────────────────────────────────
        // Add punch_time_utc and tz_offset to punches table
        $this->add_column_if_missing($wpdb, $punchesT, 'punch_time_utc', "punch_time_utc DATETIME NULL AFTER punch_time");
        $this->add_column_if_missing($wpdb, $punchesT, 'tz_offset', "tz_offset VARCHAR(6) NULL AFTER punch_time_utc");

        // Add UTC columns to sessions table
        $sessions_table = "{$p}sfs_hr_attendance_sessions";
        $this->add_column_if_missing($wpdb, $sessions_table, 'in_time_utc', "in_time_utc DATETIME NULL AFTER out_time");
        $this->add_column_if_missing($wpdb, $sessions_table, 'out_time_utc', "out_time_utc DATETIME NULL AFTER in_time_utc");
        $this->add_column_if_missing($wpdb, $sessions_table, 'tz_offset', "tz_offset VARCHAR(6) NULL AFTER out_time_utc");

        // ── M5.2: Shift Templates (roster patterns) ─────────────────────────
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_shift_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            pattern_type ENUM('fixed','rotating','custom') NOT NULL DEFAULT 'fixed',
            pattern_json LONGTEXT NOT NULL,
            cycle_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
            min_rest_hours TINYINT UNSIGNED NOT NULL DEFAULT 8,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY is_active (is_active)
        ) $charset_collate;");

        // ── M5.2: Shift Bids ────────────────────────────────────────────────
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_shift_bids (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            preferred_shift_id BIGINT UNSIGNED NOT NULL,
            reason TEXT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME NULL,
            rejection_reason TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY emp_date (employee_id, work_date),
            KEY status (status),
            KEY shift_date (preferred_shift_id, work_date)
        ) $charset_collate;");

        // ── M5.4: Biometric Devices ─────────────────────────────────────────
        dbDelta("CREATE TABLE {$p}sfs_hr_attendance_biometric_devices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            device_serial VARCHAR(100) NOT NULL,
            device_type ENUM('zkteco','hikvision','generic') NOT NULL DEFAULT 'generic',
            device_name VARCHAR(100) NULL,
            api_key VARCHAR(64) NOT NULL,
            location_label VARCHAR(100) NULL,
            location_lat DECIMAL(10,7) NULL,
            location_lng DECIMAL(10,7) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_heartbeat_at DATETIME NULL,
            last_punch_at DATETIME NULL,
            total_punches BIGINT UNSIGNED NOT NULL DEFAULT 0,
            config_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY device_serial (device_serial),
            KEY is_active (is_active),
            KEY device_type (device_type)
        ) $charset_collate;");

        // Add early_leave_approved column to sessions if missing
        $this->add_column_if_missing($wpdb, $sessions_table, 'early_leave_approved', "early_leave_approved TINYINT(1) NOT NULL DEFAULT 0");
        $this->add_column_if_missing($wpdb, $sessions_table, 'early_leave_request_id', "early_leave_request_id BIGINT UNSIGNED NULL");

        // Break delay & no-break-taken tracking
        $this->add_column_if_missing($wpdb, $sessions_table, 'break_delay_minutes', "break_delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0");
        $this->add_column_if_missing($wpdb, $sessions_table, 'no_break_taken', "no_break_taken TINYINT(1) NOT NULL DEFAULT 0");

        // Scheduled break start time on shifts
        $this->add_column_if_missing($wpdb, $shifts_table, 'break_start_time', "break_start_time TIME NULL");

        // Add request_number column for early leave requests
        $early_leave_table = "{$p}sfs_hr_early_leave_requests";
        $this->add_column_if_missing($wpdb, $early_leave_table, 'request_number', "request_number VARCHAR(50) NULL");
        $this->add_unique_key_if_missing($wpdb, $early_leave_table, 'request_number');
        Services\Early_Leave_Service::backfill_early_leave_request_numbers($wpdb);

        // Migration: Add foreign key constraints (runs once)
        if ( ! get_option( 'sfs_hr_att_fk_migrated' ) ) {
            $this->migrate_add_foreign_keys( $wpdb, $p );
        }

        // Caps (only on first run or version bump to avoid writing wp_options on every page load)
        // Bump the suffix when register_caps() logic changes (e.g. adding trainee role).
        $caps_tag = \SFS_HR_VER . '-b';
        $caps_ver = get_option( 'sfs_hr_att_caps_ver', '' );
        if ( $caps_ver !== $caps_tag ) {
            $this->register_caps();
            update_option( 'sfs_hr_att_caps_ver', $caps_tag, true );
        }
        $this->maybe_seed_defaults();
        $this->maybe_seed_kiosks();
    }

    private function add_column_if_missing( \wpdb $wpdb, string $table, string $col, string $ddl ): void {
        $exists = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col) );
        if ( ! $exists ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$ddl}" );
        }
    }

    /**
     * Add a non-unique index only if the named key does not already exist.
     *
     * @param string $key_name  Index name to check.
     * @param string $ddl       Full ALTER TABLE … ADD KEY … statement to run when absent.
     */
    private function add_index_if_missing( \wpdb $wpdb, string $table, string $key_name, string $ddl ): void {
        // information_schema acceptable here — migration-only, version-gated; no clean SHOW equivalent for index names
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
            $table,
            $key_name
        ));
        if ($exists === 0) {
            $wpdb->query($ddl);
        }
    }

    /**
     * Add unique key if it doesn't exist
     */
    private function add_unique_key_if_missing( \wpdb $wpdb, string $table, string $key_name ): void {
        // information_schema acceptable here — migration-only, version-gated; no clean SHOW equivalent for index names
        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
            $table, $key_name
        ));
        if ((int)$index_exists === 0) {
            $col_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $key_name));
            if ($col_exists) {
                $wpdb->query("ALTER TABLE `$table` ADD UNIQUE KEY `$key_name` (`$key_name`)");
            }
        }
    }

    /**
     * One-time migration: add FK constraints to attendance tables.
     * Cleans orphaned rows first, then adds RESTRICT/SET NULL/CASCADE as appropriate.
     */
    private function migrate_add_foreign_keys( \wpdb $wpdb, string $p ): void {
        $empT    = "{$p}sfs_hr_employees";
        $punchT  = "{$p}sfs_hr_attendance_punches";
        $sessT   = "{$p}sfs_hr_attendance_sessions";
        $shiftT  = "{$p}sfs_hr_attendance_shifts";
        $assignT = "{$p}sfs_hr_attendance_shift_assign";
        $empShT  = "{$p}sfs_hr_attendance_emp_shifts";
        $flagT   = "{$p}sfs_hr_attendance_flags";
        $auditT  = "{$p}sfs_hr_attendance_audit";
        $elrT    = "{$p}sfs_hr_early_leave_requests";

        // Helper: check if a FK already exists on a table
        // information_schema.TABLE_CONSTRAINTS acceptable here — migration-only, option-gated; no clean SHOW alternative for FK names
        $fk_exists = function( string $table, string $fk_name ) use ( $wpdb ): bool {
            return (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s
                   AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                $table, $fk_name
            ) );
        };

        // Ensure InnoDB on all tables including the parent employees table
        // (required for FK constraints).
        $had_errors = false;
        foreach ( [ $empT, $punchT, $sessT, $shiftT, $assignT, $empShT, $flagT, $auditT, $elrT ] as $t ) {
            $row    = $wpdb->get_row( $wpdb->prepare( "SHOW TABLE STATUS LIKE %s", $t ), ARRAY_A );
            $engine = $row ? $row['Engine'] : null;
            if ( $engine !== null && strcasecmp( $engine, 'InnoDB' ) !== 0 ) {
                if ( $wpdb->query( "ALTER TABLE {$t} ENGINE = InnoDB" ) === false ) {
                    $had_errors = true;
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( "[SFS HR] FK migration: failed to convert {$t} to InnoDB" );
                    }
                }
            }
        }

        if ( $had_errors ) {
            return; // Retry on next activation — don't set the migrated flag
        }

        // Clean orphaned employee references
        $cleanup_queries = [
            "DELETE p FROM {$punchT} p LEFT JOIN {$empT} e ON e.id = p.employee_id WHERE e.id IS NULL",
            "DELETE s FROM {$sessT} s LEFT JOIN {$empT} e ON e.id = s.employee_id WHERE e.id IS NULL",
            "DELETE sa FROM {$assignT} sa LEFT JOIN {$empT} e ON e.id = sa.employee_id WHERE e.id IS NULL",
            "DELETE es FROM {$empShT} es LEFT JOIN {$empT} e ON e.id = es.employee_id WHERE e.id IS NULL",
            "DELETE f FROM {$flagT} f LEFT JOIN {$empT} e ON e.id = f.employee_id WHERE e.id IS NULL",
            "UPDATE {$auditT} a LEFT JOIN {$empT} e ON e.id = a.target_employee_id SET a.target_employee_id = NULL WHERE a.target_employee_id IS NOT NULL AND e.id IS NULL",
            "DELETE el FROM {$elrT} el LEFT JOIN {$empT} e ON e.id = el.employee_id WHERE e.id IS NULL",
            // Clean orphaned shift references
            "DELETE sa FROM {$assignT} sa LEFT JOIN {$shiftT} sh ON sh.id = sa.shift_id WHERE sh.id IS NULL",
            "DELETE es FROM {$empShT} es LEFT JOIN {$shiftT} sh ON sh.id = es.shift_id WHERE sh.id IS NULL",
            "UPDATE {$sessT} s LEFT JOIN {$assignT} sa ON sa.id = s.shift_assign_id SET s.shift_assign_id = NULL WHERE s.shift_assign_id IS NOT NULL AND sa.id IS NULL",
        ];

        foreach ( $cleanup_queries as $sql ) {
            if ( $wpdb->query( $sql ) === false ) {
                $had_errors = true;
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( "[SFS HR] FK migration: cleanup query failed — {$wpdb->last_error}" );
                }
            }
        }

        if ( $had_errors ) {
            return; // Retry on next activation — don't set the migrated flag
        }

        // Add FK constraints (skip if already exists)
        $fks = [
            [ $punchT,  'fk_punches_employee',       'employee_id',      $empT,    'id', 'RESTRICT' ],
            [ $sessT,   'fk_sessions_employee',       'employee_id',      $empT,    'id', 'RESTRICT' ],
            [ $sessT,   'fk_sessions_shift_assign',   'shift_assign_id',  $assignT, 'id', 'SET NULL' ],
            [ $assignT, 'fk_shift_assign_employee',   'employee_id',      $empT,    'id', 'RESTRICT' ],
            [ $assignT, 'fk_shift_assign_shift',      'shift_id',         $shiftT,  'id', 'CASCADE'  ],
            [ $empShT,  'fk_emp_shifts_employee',      'employee_id',      $empT,    'id', 'RESTRICT' ],
            [ $empShT,  'fk_emp_shifts_shift',         'shift_id',         $shiftT,  'id', 'CASCADE'  ],
            [ $flagT,   'fk_flags_employee',           'employee_id',      $empT,    'id', 'RESTRICT' ],
            [ $auditT,  'fk_audit_employee',           'target_employee_id', $empT,  'id', 'SET NULL' ],
            [ $elrT,    'fk_early_leave_employee',     'employee_id',      $empT,    'id', 'RESTRICT' ],
        ];

        foreach ( $fks as [ $table, $name, $col, $ref_table, $ref_col, $on_delete ] ) {
            if ( ! $fk_exists( $table, $name ) ) {
                $result = $wpdb->query(
                    "ALTER TABLE {$table} ADD CONSTRAINT {$name}
                     FOREIGN KEY ({$col}) REFERENCES {$ref_table}({$ref_col})
                     ON DELETE {$on_delete} ON UPDATE CASCADE"
                );
                if ( $result === false ) {
                    $had_errors = true;
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( "[SFS HR] FK migration: failed to add {$name} on {$table}" );
                    }
                }
            }
        }

        if ( ! $had_errors ) {
            update_option( 'sfs_hr_att_fk_migrated', 1 );
        } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[SFS HR] FK migration: completed with errors — will retry on next activation' );
        }
    }

    /** Map capabilities to roles. */
    private function register_caps(): void {
        // Base caps
        $caps_self    = ['sfs_hr_attendance_clock_self','sfs_hr_attendance_view_self'];
        $caps_kiosk   = ['sfs_hr_attendance_clock_kiosk'];
        $caps_manage  = ['sfs_hr_attendance_view_team','sfs_hr_attendance_edit_team','sfs_hr_attendance_admin'];

        // Employee
        if ( $role = get_role('sfs_hr_employee') ) {
            foreach ( array_merge($caps_self, $caps_kiosk) as $c ) { $role->add_cap($c); }
        }

        // Trainee (same attendance caps as employee — they need to clock in/out)
        if ( $role = get_role('sfs_hr_trainee') ) {
            foreach ( array_merge($caps_self, $caps_kiosk) as $c ) { $role->add_cap($c); }
        }

        // Manager
        if ( $role = get_role('sfs_hr_manager') ) {
            foreach ( array_merge($caps_self, $caps_kiosk, $caps_manage) as $c ) { $role->add_cap($c); }
        }

        // Any role that already has the suite's master cap gets full attendance admin + self punch
        foreach ( array_keys( wp_roles()->roles ) as $role_key ) {
            $r = get_role($role_key);
            if ( ! $r ) { continue; }
            if ( $r->has_cap('sfs_hr.manage') ) {
                foreach ( array_merge($caps_manage, $caps_kiosk, $caps_self) as $c ) { $r->add_cap($c); }
            }
        }

        // Site Administrators: include self-punch too
        if ( $admin = get_role('administrator') ) {
            foreach ( array_merge($caps_manage, $caps_kiosk, $caps_self) as $c ) { $admin->add_cap($c); }
        }

        // Make sure device/admin caps exist on key roles
        $caps_devices = ['sfs_hr_attendance_admin', 'sfs_hr_attendance_edit_devices'];

        if ( $admin = get_role('administrator') ) {
            $all_admin_caps = array_merge(
                $caps_devices,
                ['sfs_hr_attendance_view_self', 'sfs_hr_attendance_clock_self', 'sfs_hr_attendance_clock_kiosk']
            );
            foreach ( $all_admin_caps as $c ) {
                $admin->add_cap($c);
            }
        }
        if ( $mgr = get_role('sfs_hr_manager') ) {
            foreach ( $caps_devices as $c ) { $mgr->add_cap($c); }
        }
    }

    /** Seed global defaults (changeable later via Admin UI). */
    private function maybe_seed_defaults(): void {
        $defaults = [
            // Department settings now use dept_id from sfs_hr_departments table
            'web_allowed_by_dept_id'     => [], // dept_id => true/false
            'selfie_required_by_dept_id' => [], // dept_id => true/false
            'selfie_retention_days'      => 30,
            'default_rounding_rule'      => '5',
            'default_grace_late'         => 5,
            'default_grace_early'        => 5,

            // Weekly segments now keyed by dept_id
            'dept_weekly_segments' => [], // dept_id => [ 'sun' => [...], ... ]

            // Selfie policy (optional by default)
            'selfie_policy' => [
                'default'      => 'optional', // modes: never | optional | in_only | in_out | all
                'by_dept_id'   => [],         // dept_id => mode
                'by_employee'  => [],         // employee_id => mode
            ],

        ];

        $existing = get_option( AttendanceModule::OPT_SETTINGS );
        if ( ! is_array( $existing ) ) {
            add_option( AttendanceModule::OPT_SETTINGS, $defaults, '', false );
        } else {
            $merged = array_replace_recursive( $defaults, $existing );
            update_option( AttendanceModule::OPT_SETTINGS, $merged, false );
        }
    }

    /** Seed placeholder kiosks. */
    private function maybe_seed_kiosks(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_devices';
        $existing = $wpdb->get_col( "SELECT label FROM {$table} WHERE type='kiosk' LIMIT 2" );
        if ( is_array( $existing ) && count( $existing ) > 0 ) return;

        $now = current_time( 'mysql', true );
        // Create a sample kiosk with no department restriction (allowed_dept_id = null means all)
        $rows = [
            [
                'label'            => 'Main Kiosk #1',
                'type'             => 'kiosk',
                'kiosk_enabled'    => 1,
                'kiosk_pin'        => null,
                'kiosk_offline'    => 1,
                'last_sync_at'     => null,
                'geo_lock_lat'     => null,
                'geo_lock_lng'     => null,
                'geo_lock_radius_m'=> null,
                'allowed_dept_id'  => null, // null = all departments allowed
                'fingerprint_hash' => null,
                'active'           => 1,
                'meta_json'        => wp_json_encode( ['seeded_at'=>$now] ),
            ],
        ];
        foreach ( $rows as $r ) { $wpdb->insert( $table, $r ); }
    }
}
