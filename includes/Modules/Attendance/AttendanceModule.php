<?php
namespace SFS\HR\Modules\Attendance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AttendanceModule
 * Version: 0.1.2-admin-crud
 * Author: hdqah.com
 *
 * Notes:
 * - Employee mapping: {prefix}sfs_hr_employees.id and .user_id (to wp_users.ID)
 * - Leaves: {prefix}sfs_hr_leave_requests (status='approved', start_date, end_date)
 * - Holidays: option 'sfs_hr_holidays' (array of date ranges)
 */

// Load submodules at file scope (NOT inside the class!)
require_once __DIR__ . '/Services/Period_Service.php';
require_once __DIR__ . '/Services/Shift_Service.php';
require_once __DIR__ . '/Services/Early_Leave_Service.php';
require_once __DIR__ . '/Services/Session_Service.php';
require_once __DIR__ . '/Admin/class-admin-pages.php';
require_once __DIR__ . '/Rest/class-attendance-admin-rest.php';
require_once __DIR__ . '/Rest/class-attendance-rest.php';
require_once __DIR__ . '/Rest/class-early-leave-rest.php';
require_once __DIR__ . '/Cron/Daily_Session_Builder.php';
require_once __DIR__ . '/Frontend/Widget_Shortcode.php';
require_once __DIR__ . '/Frontend/Kiosk_Shortcode.php';

class AttendanceModule {

    const OPT_SETTINGS = 'sfs_hr_attendance_settings';

    /**
     * @deprecated Delegate to Period_Service. Kept for backwards compatibility.
     */
    public static function get_current_period( string $reference_date = '' ): array {
        return Services\Period_Service::get_current_period( $reference_date );
    }

    /** @deprecated Delegate to Period_Service. */
    public static function get_previous_period( string $reference_date = '' ): array {
        return Services\Period_Service::get_previous_period( $reference_date );
    }

    /** @deprecated Delegate to Period_Service. */
    public static function format_period_label( array $period ): string {
        return Services\Period_Service::format_period_label( $period );
    }

    public function hooks(): void {
        add_action('admin_init', [ $this, 'maybe_install' ]);

        // Deferred recalc hook — fires when a recalc was skipped due to lock contention.
        // Uses a wrapper because recalc_session_for's 3rd param is $wpdb, not $force.
        add_action( 'sfs_hr_deferred_recalc', [ self::class, 'run_deferred_recalc' ], 10, 3 );

        // Safe call to private method
        add_action('admin_init', function () { $this->register_caps(); });
        add_shortcode('sfs_hr_kiosk', [ $this, 'shortcode_kiosk' ]);

add_action('wp_ajax_sfs_hr_att_dbg', [ $this, 'ajax_dbg' ]);
add_action('wp_ajax_nopriv_sfs_hr_att_dbg', [ $this, 'ajax_dbg' ]);

        // Keep Admin pages on init
add_action('init', function () {
    ( new \SFS\HR\Modules\Attendance\Admin\Admin_Pages() )->hooks();
});

// Register REST routes (Admin + Public) in the right hook
add_action('rest_api_init', function () {
    // Admin REST
    if (method_exists(\SFS\HR\Modules\Attendance\Rest\Admin_REST::class, 'routes')) {
        \SFS\HR\Modules\Attendance\Rest\Admin_REST::routes();
    } elseif (method_exists(\SFS\HR\Modules\Attendance\Rest\Admin_REST::class, 'register')) {
        // fallback if Admin_REST only has register()
        \SFS\HR\Modules\Attendance\Rest\Admin_REST::register();
    }

    // Public REST — call register() so nocache headers are attached
    \SFS\HR\Modules\Attendance\Rest\Public_REST::register();

    // Early Leave Requests REST
    \SFS\HR\Modules\Attendance\Rest\Early_Leave_Rest::register_routes();
}, 10);



        add_shortcode('sfs_hr_attendance_widget', [ $this, 'shortcode_widget' ]);

        // Auto-reject early leave requests after 72 hours of no action
        ( new \SFS\HR\Modules\Attendance\Cron\Early_Leave_Auto_Reject() )->hooks();

        // Daily session builder — ensures sessions exist for yesterday/today
        ( new \SFS\HR\Modules\Attendance\Cron\Daily_Session_Builder() )->hooks();

        // Selfie cleanup — deletes attachments older than selfie_retention_days
        ( new \SFS\HR\Modules\Attendance\Cron\Selfie_Cleanup() )->hooks();
    }

    /**
     * Minimal employee widget (shortcode) with nonce + REST calls.
     * Place on a page restricted to logged-in employees.
     */
    public function shortcode_widget(): string {
        return Frontend\Widget_Shortcode::render();
    }




/**
 * Kiosk Widget
 */
public function shortcode_kiosk( $atts = [] ): string {
    return Frontend\Kiosk_Shortcode::render( $atts );
}


private static function add_column_if_missing( \wpdb $wpdb, string $table, string $col, string $ddl ): void {
    $exists = $wpdb->get_var( $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col) );
    if ( ! $exists ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$ddl}" );
    }
}

/**
 * One-time migration: add FK constraints to attendance tables.
 * Cleans orphaned rows first, then adds RESTRICT/SET NULL/CASCADE as appropriate.
 */
private static function migrate_add_foreign_keys( \wpdb $wpdb, string $p ): void {
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
        $engine = $wpdb->get_var( $wpdb->prepare(
            "SELECT ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $t
        ) );
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

/**
 * Add unique key if it doesn't exist
 */
private static function add_unique_key_if_missing( \wpdb $wpdb, string $table, string $key_name ): void {
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
 * Generate reference number for early leave requests
 */
/** @deprecated Delegate to Early_Leave_Service. */
public static function generate_early_leave_request_number(): string {
    return Services\Early_Leave_Service::generate_early_leave_request_number();
}

/** @deprecated Delegate to Early_Leave_Service. */
private static function maybe_create_early_leave_request(
    int $employee_id,
    string $ymd,
    int $session_id,
    ?string $lastOutUtc,
    int $minutes_early,
    bool $is_total_hours,
    $shift,
    \wpdb $wpdb
): void {
    Services\Early_Leave_Service::maybe_create_early_leave_request(
        $employee_id, $ymd, $session_id, $lastOutUtc,
        $minutes_early, $is_total_hours, $shift, $wpdb
    );
}

/** @deprecated Delegate to Early_Leave_Service. */
private static function backfill_early_leave_request_numbers( \wpdb $wpdb ): void {
    Services\Early_Leave_Service::backfill_early_leave_request_numbers( $wpdb );
}


    /**
     * Create / upgrade tables and initialize caps & defaults.
     */
    public function maybe_install(): void {
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
        self::add_column_if_missing($wpdb, $punchesT, 'offline_origin', "offline_origin TINYINT(1) NOT NULL DEFAULT 0 AFTER valid_selfie");

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
        $col_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'dept_id'",
            $shifts_table
        ) );
        if ( ! $col_exists ) {
            $wpdb->query( "ALTER TABLE {$shifts_table} ADD COLUMN dept_id BIGINT UNSIGNED NULL AFTER active" );
            $wpdb->query( "ALTER TABLE {$shifts_table} ADD KEY dept_id (dept_id)" );
        }

        // Migration: Add dept_ids column for multi-department support
        $dept_ids_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'dept_ids'",
            $shifts_table
        ) );
        if ( ! $dept_ids_exists ) {
            $wpdb->query( "ALTER TABLE {$shifts_table} ADD COLUMN dept_ids TEXT NULL COMMENT 'JSON array of department IDs'" );
            // Migrate existing single dept_id to dept_ids JSON
            $wpdb->query( "UPDATE {$shifts_table} SET dept_ids = CONCAT('[', dept_id, ']') WHERE dept_id IS NOT NULL AND dept_ids IS NULL" );
        }

        // Migration: Add period_overrides column for date-range time overrides (Ramadan, etc.)
        $period_ov_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'period_overrides'",
            $shifts_table
        ) );
        if ( ! $period_ov_exists ) {
            $wpdb->query( "ALTER TABLE {$shifts_table} ADD COLUMN period_overrides TEXT NULL COMMENT 'JSON array of date-range time overrides' AFTER weekly_overrides" );
        }

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
        $sched_col_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'schedule_id'",
            $emp_shifts_tbl
        ) );
        if ( ! $sched_col_exists ) {
            $wpdb->query( "ALTER TABLE {$emp_shifts_tbl} ADD COLUMN schedule_id BIGINT UNSIGNED NULL COMMENT 'FK to shift_schedules' AFTER shift_id" );
            $wpdb->query( "ALTER TABLE {$emp_shifts_tbl} ADD KEY schedule_id (schedule_id)" );
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
self::add_column_if_missing($wpdb, $t, 'kiosk_enabled',     "kiosk_enabled TINYINT(1) NOT NULL DEFAULT 0");
self::add_column_if_missing($wpdb, $t, 'kiosk_offline',     "kiosk_offline TINYINT(1) NOT NULL DEFAULT 0");
self::add_column_if_missing($wpdb, $t, 'geo_lock_lat',      "geo_lock_lat DECIMAL(10,7) NULL");
self::add_column_if_missing($wpdb, $t, 'geo_lock_lng',      "geo_lock_lng DECIMAL(10,7) NULL");
self::add_column_if_missing($wpdb, $t, 'geo_lock_radius_m', "geo_lock_radius_m SMALLINT UNSIGNED NULL");
self::add_column_if_missing($wpdb, $t, 'allowed_dept_id',   "allowed_dept_id BIGINT UNSIGNED NULL");
self::add_column_if_missing($wpdb, $t, 'active',            "active TINYINT(1) NOT NULL DEFAULT 1");
self::add_column_if_missing($wpdb, $t, 'qr_enabled',        "qr_enabled TINYINT(1) NOT NULL DEFAULT 1");
self::add_column_if_missing($wpdb, $t, 'selfie_mode',       "selfie_mode ENUM('inherit','never','in_only','in_out','all') NOT NULL DEFAULT 'inherit'");
self::add_column_if_missing($wpdb, $t, 'suggest_in_time',         "suggest_in_time TIME NULL");
self::add_column_if_missing($wpdb, $t, 'suggest_break_start_time',"suggest_break_start_time TIME NULL");
self::add_column_if_missing($wpdb, $t, 'suggest_break_end_time',  "suggest_break_end_time TIME NULL");
self::add_column_if_missing($wpdb, $t, 'suggest_out_time',        "suggest_out_time TIME NULL");
self::add_column_if_missing($wpdb, $t, 'break_enabled',           "break_enabled TINYINT(1) NOT NULL DEFAULT 0");


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

        // Add early_leave_approved column to sessions if missing
        $sessions_table = "{$p}sfs_hr_attendance_sessions";
        self::add_column_if_missing($wpdb, $sessions_table, 'early_leave_approved', "early_leave_approved TINYINT(1) NOT NULL DEFAULT 0");
        self::add_column_if_missing($wpdb, $sessions_table, 'early_leave_request_id', "early_leave_request_id BIGINT UNSIGNED NULL");

        // Break delay & no-break-taken tracking
        self::add_column_if_missing($wpdb, $sessions_table, 'break_delay_minutes', "break_delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0");
        self::add_column_if_missing($wpdb, $sessions_table, 'no_break_taken', "no_break_taken TINYINT(1) NOT NULL DEFAULT 0");

        // Scheduled break start time on shifts
        self::add_column_if_missing($wpdb, $shifts_table, 'break_start_time', "break_start_time TIME NULL");

        // Add request_number column for early leave requests
        $early_leave_table = "{$p}sfs_hr_early_leave_requests";
        self::add_column_if_missing($wpdb, $early_leave_table, 'request_number', "request_number VARCHAR(50) NULL");
        self::add_unique_key_if_missing($wpdb, $early_leave_table, 'request_number');
        self::backfill_early_leave_request_numbers($wpdb);

        // Migration: Add foreign key constraints (runs once)
        if ( ! get_option( 'sfs_hr_att_fk_migrated' ) ) {
            self::migrate_add_foreign_keys( $wpdb, $p );
        }

        // Caps + defaults + seed kiosks
        $this->register_caps();
        $this->maybe_seed_defaults();
        $this->maybe_seed_kiosks();
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

        // Manager
        if ( $role = get_role('sfs_hr_manager') ) {
            foreach ( array_merge($caps_self, $caps_kiosk, $caps_manage) as $c ) { $role->add_cap($c); }
        }

        // Any role that already has the suite’s master cap gets full attendance admin + self punch
        foreach ( wp_roles()->roles as $role_key => $def ) {
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
$caps_devices = ['sfs_hr_attendance_admin','sfs_hr_attendance_edit_devices'];

if ($admin = get_role('administrator')) {
    foreach (array_merge($caps_devices, ['sfs_hr_attendance_view_self','sfs_hr_attendance_clock_self','sfs_hr_attendance_clock_kiosk']) as $c) {
        $admin->add_cap($c);
    }
}
if ($mgr = get_role('sfs_hr_manager')) {
    foreach ($caps_devices as $c) { $mgr->add_cap($c); }
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

        $existing = get_option( self::OPT_SETTINGS );
        if ( ! is_array( $existing ) ) {
            add_option( self::OPT_SETTINGS, $defaults, '', false );
        } else {
            $merged = array_replace_recursive( $defaults, $existing );
            update_option( self::OPT_SETTINGS, $merged, false );
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

public function ajax_dbg(): void {
    // Minimal, safe logger
    $msg = isset($_POST['m']) ? wp_unslash($_POST['m']) : '';
    $ctx = isset($_POST['c']) ? wp_unslash($_POST['c']) : '';
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $line = '[SFS ATT DBG] ' . gmdate('c') . " ip={$ip} | " . $msg;
    if ($ctx !== '') { $line .= ' | ' . $ctx; }
    $line .= ' | UA=' . substr($ua, 0, 120);
    error_log($line);
    wp_send_json_success();
}

    /* ---------------- Core helpers ---------------- */

    /** Resolve employee_id from WP user_id via {prefix}sfs_hr_employees.user_id */
    public static function employee_id_from_user( int $user_id ): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d LIMIT 1",
            $user_id
        ) );
        return $id ? (int) $id : null;
    }

    /** Holidays/Leave guard. */
    public static function is_blocked_by_leave_or_holiday( int $employee_id, string $dateYmd ): bool {
        $blocked = false;

        // Holidays (uses holidays_in_range which handles yearly repeat expansion)
        $holiday_dates = \SFS\HR\Modules\Leave\Services\LeaveCalculationService::holidays_in_range( $dateYmd, $dateYmd );
        if ( ! empty( $holiday_dates ) ) {
            $blocked = true;
        }

        // Leaves
        if ( ! $blocked ) {
            global $wpdb;
            $table = $wpdb->prefix . 'sfs_hr_leave_requests';
            $has = $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$table}
                 WHERE employee_id = %d
                   AND status = 'approved'
                   AND %s BETWEEN start_date AND end_date
                 LIMIT 1",
                $employee_id, $dateYmd
            ) );
            $blocked = (bool) $has;
        }

        return (bool) apply_filters( 'sfs_hr_attendance_is_leave_or_holiday', $blocked, $employee_id, $dateYmd );
    }

    /** Check if employee is on approved leave (excludes company holidays). */
    public static function is_on_approved_leave( int $employee_id, string $dateYmd ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_leave_requests';
        $has = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$table}
             WHERE employee_id = %d
               AND status = 'approved'
               AND %s BETWEEN start_date AND end_date
             LIMIT 1",
            $employee_id, $dateYmd
        ) );
        return (bool) $has;
    }

    /**
     * Check if a date is a company holiday (not employee-specific leave)
     *
     * @param string $dateYmd Date in Y-m-d format
     * @return bool
     */
    public static function is_company_holiday( string $dateYmd ): bool {
        $holiday_dates = \SFS\HR\Modules\Leave\Services\LeaveCalculationService::holidays_in_range( $dateYmd, $dateYmd );
        return ! empty( $holiday_dates );
    }


/** Local Y-m-d → [start_utc, end_utc) */
public static function local_day_window_to_utc(string $ymd): array {
    $tz  = wp_timezone();
    $stL = new \DateTimeImmutable($ymd.' 00:00:00', $tz);
    $enL = $stL->modify('+1 day');
    $stU = $stL->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $enU = $enL->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    return [$stU, $enU];
}

/** Format a UTC MySQL datetime into site-local using WP formats. */
public static function fmt_local(?string $utc_mysql): string {
    if (!$utc_mysql) return '';
    $ts = strtotime($utc_mysql.' UTC');
    return wp_date(get_option('date_format').' '.get_option('time_format'), $ts);
}


/** Find the config array for a department by trying id → slug → name. */
private static function pick_dept_conf(array $autoMap, array $deptInfo): ?array {
    $candidates = [];
    if (!empty($deptInfo['id']))   $candidates[] = (string)$deptInfo['id'];
    if (!empty($deptInfo['slug'])) $candidates[] = (string)$deptInfo['slug'];
    if (!empty($deptInfo['name'])) $candidates[] = (string)$deptInfo['name'];

    foreach ($candidates as $key) {
        if (isset($autoMap[$key]) && is_array($autoMap[$key])) {
            return $autoMap[$key];
        }
    }
    return null;
}

    /** @deprecated Delegate to Session_Service. */
    public static function run_deferred_recalc( int $employee_id, string $ymd, bool $force = false ): void {
        Services\Session_Service::run_deferred_recalc( $employee_id, $ymd, $force );
    }

    /** @deprecated Delegate to Session_Service. */
    public static function recalc_session_for( int $employee_id, string $ymd, \wpdb $wpdb = null, bool $force = false ): void {
        Services\Session_Service::recalc_session_for( $employee_id, $ymd, $wpdb, $force );
    }





    /** @deprecated Delegate to Shift_Service. */
    public static function resolve_shift_for_date(
        int $employee_id,
        string $ymd,
        array $settings = [],
        \wpdb $wpdb_in = null
    ): ?\stdClass {
        return Services\Shift_Service::resolve_shift_for_date( $employee_id, $ymd, $settings, $wpdb_in );
    }

    /** @deprecated Delegate to Shift_Service. */
    public static function build_segments_from_shift( ?\stdClass $shift, string $ymd ): array {
        return Services\Shift_Service::build_segments_from_shift( $shift, $ymd );
    }

    /** @deprecated Delegate to Session_Service. */
    private static function evaluate_segments(array $segments, array $punchesUTC, int $graceLateMin, int $graceEarlyMin, int $dayEndUtcTs = 0): array {
        return Services\Session_Service::evaluate_segments( $segments, $punchesUTC, $graceLateMin, $graceEarlyMin, $dayEndUtcTs );
    }

    /** @deprecated Delegate to Session_Service. */
    public static function rebuild_sessions_for_date_static( string $date ): void {
        Services\Session_Service::rebuild_sessions_for_date_static( $date );
    }

    /** Return selfie mode resolved by precedence. */
    public static function selfie_mode_for( int $employee_id, $dept_id, array $ctx = [] ): string {
        // Global options
        $opt    = get_option( self::OPT_SETTINGS, [] );
        $policy = is_array( $opt ) ? ( $opt['selfie_policy'] ?? [] ) : [];

        $default_mode = $policy['default'] ?? 'optional';
        $dept_modes   = $policy['by_dept_id'] ?? [];
        $emp_modes    = $policy['by_employee'] ?? [];

        $mode = $default_mode;

        $dept_id = (int) $dept_id;
        if ( $dept_id > 0 && ! empty( $dept_modes[ $dept_id ] ) ) {
            $mode = (string) $dept_modes[ $dept_id ];
        }

        if ( ! empty( $emp_modes[ $employee_id ] ) ) {
            $mode = (string) $emp_modes[ $employee_id ];
        }

        if ( ! empty( $ctx['device_id'] ) ) {
            global $wpdb;
            $dT  = $wpdb->prefix . 'sfs_hr_attendance_devices';
            $dev = $wpdb->get_row( $wpdb->prepare(
                "SELECT selfie_mode FROM {$dT} WHERE id=%d AND active=1",
                (int) $ctx['device_id']
            ) );
            if ( $dev && ! empty( $dev->selfie_mode ) && $dev->selfie_mode !== 'inherit' ) {
                $mode = (string) $dev->selfie_mode;
            }
        }

        $shift_requires = ! empty( $ctx['shift_requires'] );
        if ( $shift_requires && $mode !== 'all' ) {
            $mode = 'all';
        }

        if ( ! in_array( $mode, [ 'never', 'optional', 'in_only', 'in_out', 'all' ], true ) ) {
            $mode = 'optional';
        }

        return $mode;
    }

        /* ---------- Dept helpers (safe, backend-only) ---------- */

    /**
     * Internal: cache of employee table columns so we don't hammer SHOW COLUMNS.
     *
     * @return string[] column names
     */
    private static function employee_table_columns(): array {
        static $cols = null;

        if ( $cols !== null ) {
            return $cols;
        }

        global $wpdb;
        $table     = $wpdb->prefix . 'sfs_hr_employees';
        $table_sql = esc_sql( $table );

        // SHOW COLUMNS is safe here; table name is local, not user input.
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_sql}`", 0 );
        if ( ! is_array( $cols ) ) {
            $cols = [];
        }

        return $cols;
    }

    /**
     * Normalize a string "work location" / dept name to a simple slug.
     * Returns sanitized slug from work location string.
     */
    private static function normalize_work_location( string $raw ): string {
        $s = trim( $raw );
        if ( $s === '' ) {
            return '';
        }
        return sanitize_title( $s );
    }

    /**
     * Fetch department info for an employee in a safe/defensive way.
     *
     * Returns:
     * [
     *   'id'   => int|null,   // dept id if available
     *   'name' => string,     // raw dept name/label
     *   'slug' => string,     // normalized slug
     * ]
     */
    public static function employee_department_info( int $employee_id ): array {
        global $wpdb;

        $table     = $wpdb->prefix . 'sfs_hr_employees';
        $table_sql = esc_sql( $table );
        $cols      = self::employee_table_columns();

        // Build a SELECT that only uses existing columns to avoid "Unknown column" errors.
        $select_cols = [ 'id' ];

        if ( in_array( 'dept_id', $cols, true ) ) {
            $select_cols[] = 'dept_id';
        } elseif ( in_array( 'department_id', $cols, true ) ) {
            $select_cols[] = 'department_id';
        }

        if ( in_array( 'dept', $cols, true ) ) {
            $select_cols[] = 'dept';
        } elseif ( in_array( 'department', $cols, true ) ) {
            $select_cols[] = 'department';
        } elseif ( in_array( 'dept_label', $cols, true ) ) {
            $select_cols[] = 'dept_label';
        }

        if ( in_array( 'work_location', $cols, true ) ) {
            $select_cols[] = 'work_location';
        }

        $select_sql = implode( ', ', array_map( 'esc_sql', $select_cols ) );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$select_sql} FROM `{$table_sql}` WHERE id=%d LIMIT 1",
                $employee_id
            )
        );

        if ( ! $row ) {
            return [
                'id'   => null,
                'name' => '',
                'slug' => '',
            ];
        }

        // Dept id
        $dept_id = null;
        if ( isset( $row->dept_id ) && is_numeric( $row->dept_id ) ) {
            $dept_id = (int) $row->dept_id;
        } elseif ( isset( $row->department_id ) && is_numeric( $row->department_id ) ) {
            $dept_id = (int) $row->department_id;
        }

        // Dept name / label
        $dept_name = '';
        if ( isset( $row->dept ) && $row->dept !== '' ) {
            $dept_name = (string) $row->dept;
        } elseif ( isset( $row->department ) && $row->department !== '' ) {
            $dept_name = (string) $row->department;
        } elseif ( isset( $row->dept_label ) && $row->dept_label !== '' ) {
            $dept_name = (string) $row->dept_label;
        }

        // Slug
        $slug = '';
        if ( isset( $row->work_location ) && $row->work_location !== '' ) {
            $slug = self::normalize_work_location( (string) $row->work_location );
        } elseif ( $dept_name !== '' ) {
            $slug = sanitize_title( $dept_name );
        }

        return [
            'id'   => $dept_id,
            'name' => $dept_name,
            'slug' => $slug,
        ];
    }

    /**
     * Simple helper: best-effort dept slug for attendance logic.
     */
    public static function get_employee_dept_for_attendance( int $employee_id ): string {
        $info = self::employee_department_info( $employee_id );
        return $info['slug'];
    }

    /** Convenience: department label only. */
    private static function employee_department_label( int $employee_id, \wpdb $wpdb ): ?string {
        $info = self::employee_department_info( $employee_id, $wpdb );
        return $info ? ($info['name'] ?: $info['slug']) : null;
    }
}