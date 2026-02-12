<?php
/**
 * Plugin Name: Simple HR Suite
 * Description: Simple HR Suite – employees, departments, leave, balances, approvals.
 * Version: 0.4.3
 * Author: hdqah.com
 * Author URI: https://hdqah.com
 * Text Domain: sfs-hr
 * GitHub Plugin URI: onabhani/Simple-HR-Suite
 * Primary Branch: main
 */

if (!defined('ABSPATH')) { exit; }

define('SFS_HR_VER', '0.4.3');
define('SFS_HR_DIR', plugin_dir_path(__FILE__));
define('SFS_HR_URL', plugin_dir_url(__FILE__));
define('SFS_HR_PLUGIN_FILE', __FILE__);

/**
 * PSR-4-ish autoloader for SFS\HR\* classes.
 */
spl_autoload_register(function($class){
    if (strpos($class, 'SFS\\HR\\') !== 0) return;
    $path = SFS_HR_DIR . 'includes/' . str_replace(['SFS\\HR\\','\\'], ['','/'], $class) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

/**
 * Activation: ensure roles/caps + DB migrations + module installs.
 */
register_activation_hook(__FILE__, function(){
    global $wpdb;

    // Ensure roles and capabilities
    \SFS\HR\Core\Capabilities::ensure_roles_caps();

    // Run core migrations
    \SFS\HR\Install\Migrations::run();

    // Install Hiring module tables (candidates, trainees)
    \SFS\HR\Modules\Hiring\HiringModule::install();

    // Install Leave module
    (new \SFS\HR\Modules\Leave\LeaveModule())->install();

    // Create Assets tables directly
    $assets_table    = $wpdb->prefix . 'sfs_hr_assets';
    $assign_table    = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $charset_collate = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$assets_table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        asset_code VARCHAR(50) NOT NULL,
        name VARCHAR(255) NOT NULL,
        category VARCHAR(50) NOT NULL,
        department VARCHAR(100) DEFAULT '',
        serial_number VARCHAR(100) DEFAULT '',
        model VARCHAR(150) DEFAULT '',
        purchase_year SMALLINT(4) DEFAULT NULL,
        purchase_price DECIMAL(10,2) DEFAULT NULL,
        warranty_expiry DATE DEFAULT NULL,
        invoice_number VARCHAR(100) DEFAULT '',
        invoice_date DATE DEFAULT NULL,
        invoice_file VARCHAR(255) DEFAULT '',
        qr_code_path VARCHAR(255) DEFAULT '',
        status ENUM('available','assigned','under_approval','returned','archived') DEFAULT 'available',
        `condition` ENUM('new','good','damaged','needs_repair','lost') DEFAULT 'good',
        notes TEXT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY asset_code (asset_code),
        KEY category (category),
        KEY status (status),
        KEY department (department)
    ) {$charset_collate}");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$assign_table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        asset_id BIGINT(20) UNSIGNED NOT NULL,
        employee_id BIGINT(20) UNSIGNED NOT NULL,
        assigned_by BIGINT(20) UNSIGNED NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE DEFAULT NULL,
        status ENUM('pending_employee_approval','active','return_requested','returned','rejected') NOT NULL DEFAULT 'pending_employee_approval',
        return_requested_by BIGINT(20) UNSIGNED DEFAULT NULL,
        return_date DATE DEFAULT NULL,
        selfie_attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
        asset_attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
        return_selfie_attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
        return_asset_attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
        notes TEXT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY asset_id (asset_id),
        KEY employee_id (employee_id),
        KEY status (status)
    ) {$charset_collate}");

    // Update version options
    update_option('sfs_hr_db_ver', SFS_HR_VER);
    update_option('sfs_hr_assets_db_version', SFS_HR_VER);

    flush_rewrite_rules();
});

/**
 * Robust self-healing on every admin load:
 * - Ensures roles/caps
 * - Runs migrations if plugin updated OR required tables/columns missing
 * - Reseeds default leave types if missing
 * - Ensures “Annual” is marked tenure-based when column exists (covers “Annual Leave” too)
 * - Ensures Departments table exists (for approvals routing)
 * - Ensures global fallback approver role option exists
 * - Ensures Assets / Assignments / Loans / Shifts tables exist
 * - Ensures Resignations / Settlements tables exist
 */
add_action('admin_init', function(){
    \SFS\HR\Core\Capabilities::ensure_roles_caps();

    global $wpdb;

    $types_table          = $wpdb->prefix . 'sfs_hr_leave_types';
    $req_table            = $wpdb->prefix . 'sfs_hr_leave_requests';
    $bal_table            = $wpdb->prefix . 'sfs_hr_leave_balances';
    $emp_table            = $wpdb->prefix . 'sfs_hr_employees';
    $dept_table           = $wpdb->prefix . 'sfs_hr_departments';

    // From first version
    $resign_table         = $wpdb->prefix . 'sfs_hr_resignations';
    $settle_table         = $wpdb->prefix . 'sfs_hr_settlements';

    // From second version
    $assets_table         = $wpdb->prefix . 'sfs_hr_assets';
    $assign_table         = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $shifts_table         = $wpdb->prefix . 'sfs_hr_attendance_shifts';
    $sessions_table       = $wpdb->prefix . 'sfs_hr_attendance_sessions';
    $loans_table          = $wpdb->prefix . 'sfs_hr_loans';
    $loan_payments_table  = $wpdb->prefix . 'sfs_hr_loan_payments';
    $loan_history_table   = $wpdb->prefix . 'sfs_hr_loan_history';
    $leave_history_table  = $wpdb->prefix . 'sfs_hr_leave_request_history';

    // Hiring module tables
    $candidates_table     = $wpdb->prefix . 'sfs_hr_candidates';
    $trainees_table       = $wpdb->prefix . 'sfs_hr_trainees';

    // Performance module tables
    $perf_snapshots_table = $wpdb->prefix . 'sfs_hr_performance_snapshots';
    $perf_goals_table     = $wpdb->prefix . 'sfs_hr_performance_goals';
    $perf_reviews_table   = $wpdb->prefix . 'sfs_hr_performance_reviews';
    $perf_alerts_table    = $wpdb->prefix . 'sfs_hr_performance_alerts';

    // Attendance policy tables
    $att_policies_table      = $wpdb->prefix . 'sfs_hr_attendance_policies';
    $att_policy_roles_table  = $wpdb->prefix . 'sfs_hr_attendance_policy_roles';

    $table_exists = function(string $table) use ($wpdb){
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        ));
    };

    $column_exists = function(string $table, string $column) use ($wpdb){
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
            $table, $column
        ));
    };

    $stored          = get_option('sfs_hr_db_ver', '0');
    $needs_migration = version_compare((string) $stored, SFS_HR_VER, '<');

    $needs_tables = (
        !$table_exists($types_table)          ||
        !$table_exists($req_table)            ||
        !$table_exists($bal_table)            ||
        !$table_exists($emp_table)            ||
        !$table_exists($dept_table)           ||

        // From first version
        !$table_exists($resign_table)         ||
        !$table_exists($settle_table)         ||

        // From second version
        !$table_exists($assets_table)         ||
        !$table_exists($assign_table)         ||
        !$table_exists($loans_table)          ||
        !$table_exists($loan_payments_table)  ||
        !$table_exists($loan_history_table)   ||
        !$table_exists($leave_history_table)  ||

        // Hiring module
        !$table_exists($candidates_table)     ||
        !$table_exists($trainees_table)       ||

        // Performance module
        !$table_exists($perf_snapshots_table) ||
        !$table_exists($perf_goals_table)     ||
        !$table_exists($perf_reviews_table)   ||
        !$table_exists($perf_alerts_table)    ||

        // Attendance policies
        !$table_exists($att_policies_table)     ||
        !$table_exists($att_policy_roles_table)
    );

    $needs_columns = false;
    if ($table_exists($types_table) && !$column_exists($types_table, 'is_annual')) {
        $needs_columns = true;
    }
    if ($table_exists($emp_table) && !$column_exists($emp_table, 'hire_date')) {
        $needs_columns = true;
    }
    if ($table_exists($shifts_table) && !$column_exists($shifts_table, 'weekly_overrides')) {
        $needs_columns = true;
    }

    if ($needs_migration || $needs_tables || $needs_columns) {
        \SFS\HR\Install\Migrations::run();
        \SFS\HR\Modules\Hiring\HiringModule::install();
        update_option('sfs_hr_db_ver', SFS_HR_VER);
    }

    // One-time cleanup: remove false early leave requests created by pre-fix code
    \SFS\HR\Install\Migrations::cleanup_false_early_leave_requests();

    // Self-heal Hiring tables if missing
    if (!$table_exists($candidates_table) || !$table_exists($trainees_table)) {
        \SFS\HR\Modules\Hiring\HiringModule::install();
    }

    // Seed/mark data if tables already exist
    if ($table_exists($types_table)) {
        $count_types = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$types_table}");
        if ($count_types === 0) {
            \SFS\HR\Install\Migrations::run();
            update_option('sfs_hr_db_ver', SFS_HR_VER);
        } else {
            // Mark annual names broadly
            if ($column_exists($types_table, 'is_annual')) {
                $wpdb->query("UPDATE {$types_table}
                              SET is_annual = 1
                              WHERE (LOWER(name) = 'annual'
                                  OR LOWER(name) = 'annual leave'
                                  OR LOWER(name) = 'annual vacation'
                                  OR LOWER(name) LIKE 'annual %')
                                AND (is_annual IS NULL OR is_annual = 0)");
            }

            add_option('sfs_hr_annual_lt5', '21');
            add_option('sfs_hr_annual_ge5', '30');
        }
    }

    // Ensure global fallback approver role option exists
    if (get_option('sfs_hr_global_approver_role', '') === '') {
        add_option('sfs_hr_global_approver_role', 'sfs_hr_manager');
    }

    // Migrate attendance sessions ENUM to include 'day_off' (v0.1.8+ fix)
    // This fixes incorrect 'holiday' status for days without shifts
    if ($table_exists($sessions_table)) {
        $col_type = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'status'",
            $sessions_table
        ));
        // Check if 'day_off' is NOT in the ENUM definition
        if ($col_type && strpos($col_type, "'day_off'") === false) {
            $wpdb->query("ALTER TABLE {$sessions_table}
                          MODIFY COLUMN status ENUM('present','late','left_early','absent','incomplete','on_leave','holiday','day_off')
                          NOT NULL DEFAULT 'present'");
            // Update existing incorrect 'holiday' records where no shift was assigned
            // These should be 'day_off' (no scheduled work) not 'holiday' (company holiday)
            $wpdb->query("UPDATE {$sessions_table} SET status = 'day_off'
                          WHERE status = 'holiday' AND shift_assign_id IS NULL");
        }
    }

    // Migrate leave_types table to add gender filtering and attachment options
    $leave_types_table = $wpdb->prefix . 'sfs_hr_leave_types';
    if ($table_exists($leave_types_table)) {
        // Add gender_required column
        $has_gender = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'gender_required'",
            $leave_types_table
        ));
        if (!$has_gender) {
            $wpdb->query("ALTER TABLE {$leave_types_table} ADD COLUMN gender_required ENUM('any','male','female') DEFAULT 'any' AFTER special_code");
            // Auto-set gender for existing maternity/paternity leaves based on special_code
            $wpdb->query("UPDATE {$leave_types_table} SET gender_required = 'female' WHERE special_code = 'MATERNITY'");
            $wpdb->query("UPDATE {$leave_types_table} SET gender_required = 'male' WHERE special_code = 'PATERNITY'");
        }

        // Add requires_attachment column
        $has_attachment = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'requires_attachment'",
            $leave_types_table
        ));
        if (!$has_attachment) {
            $wpdb->query("ALTER TABLE {$leave_types_table} ADD COLUMN requires_attachment TINYINT(1) DEFAULT 0 AFTER gender_required");
            // Auto-enable attachment for sick leave types
            $wpdb->query("UPDATE {$leave_types_table} SET requires_attachment = 1 WHERE special_code IN ('SICK_SHORT', 'SICK_LONG')");
        }

        // Add color column if missing
        $has_color = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'color'",
            $leave_types_table
        ));
        if (!$has_color) {
            $wpdb->query("ALTER TABLE {$leave_types_table} ADD COLUMN color VARCHAR(20) DEFAULT '#2271b1' AFTER requires_attachment");
        }
    }

    // Migrate employees table to add birth_date column for birthday reminders
    if ($table_exists($emp_table)) {
        $has_birth_date = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'birth_date'",
            $emp_table
        ));
        if (!$has_birth_date) {
            $wpdb->query("ALTER TABLE {$emp_table} ADD COLUMN birth_date DATE DEFAULT NULL AFTER gender");
        }
    }

    // Self-heal Assets tables if missing - use direct SQL instead of dbDelta
    if (!$table_exists($assets_table) || !$table_exists($assign_table)) {
        $charset_collate = $wpdb->get_charset_collate();

        // Create Assets table
        if (!$table_exists($assets_table)) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$assets_table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                asset_code VARCHAR(50) NOT NULL,
                name VARCHAR(255) NOT NULL,
                category VARCHAR(50) NOT NULL,
                department VARCHAR(100) DEFAULT '',
                serial_number VARCHAR(100) DEFAULT '',
                model VARCHAR(150) DEFAULT '',
                purchase_year SMALLINT(4) DEFAULT NULL,
                purchase_price DECIMAL(10,2) DEFAULT NULL,
                warranty_expiry DATE DEFAULT NULL,
                invoice_number VARCHAR(100) DEFAULT '',
                invoice_date DATE DEFAULT NULL,
                invoice_file VARCHAR(255) DEFAULT '',
                qr_code_path VARCHAR(255) DEFAULT '',
                status ENUM('available','assigned','under_approval','returned','archived') DEFAULT 'available',
                `condition` ENUM('new','good','damaged','needs_repair','lost') DEFAULT 'good',
                notes TEXT,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY asset_code (asset_code),
                KEY category (category),
                KEY status (status),
                KEY department (department)
            ) {$charset_collate}");
        }

        // Create Asset Assignments table
        if (!$table_exists($assign_table)) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$assign_table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                asset_id BIGINT(20) UNSIGNED NOT NULL,
                employee_id BIGINT(20) UNSIGNED NOT NULL,
                assigned_by BIGINT(20) UNSIGNED NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE DEFAULT NULL,
                status ENUM('pending_employee_approval','active','return_requested','returned','rejected') NOT NULL DEFAULT 'pending_employee_approval',
                return_requested_by BIGINT(20) UNSIGNED DEFAULT NULL,
                return_date DATE DEFAULT NULL,
                selfie_attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                asset_attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                return_selfie_attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                return_asset_attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                notes TEXT,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY asset_id (asset_id),
                KEY employee_id (employee_id),
                KEY status (status)
            ) {$charset_collate}");
        }

        // Set the version option
        update_option('sfs_hr_assets_db_version', SFS_HR_VER);
    }
});

/**
 * Load translations from JSON files for the sfs-hr text domain.
 * Maps WP locale → language JSON, builds English→Translated lookup,
 * then hooks into gettext for backend PHP __() / _e() calls.
 */
add_action( 'init', function () {
    $locale = determine_locale();

    // Map WP locale prefix to our JSON file key
    $locale_map = [
        'ar' => 'ar', 'fil' => 'fil', 'ur' => 'ur',
    ];

    $lang = $locale_map[ $locale ] ?? null;
    if ( ! $lang ) {
        // Try 2-letter prefix (e.g. ar_SA → ar)
        $prefix = substr( $locale, 0, 2 );
        $lang   = $locale_map[ $prefix ] ?? null;
    }
    if ( ! $lang ) {
        return; // English or unsupported — no filter needed
    }

    $en_file   = SFS_HR_DIR . 'languages/en.json';
    $lang_file = SFS_HR_DIR . "languages/{$lang}.json";
    if ( ! file_exists( $en_file ) || ! file_exists( $lang_file ) ) {
        return;
    }

    $en   = json_decode( (string) file_get_contents( $en_file ), true );
    $tr   = json_decode( (string) file_get_contents( $lang_file ), true );
    if ( ! is_array( $en ) || ! is_array( $tr ) ) {
        return;
    }

    $map = [];
    foreach ( $en as $key => $english ) {
        if ( strpos( $key, '_comment' ) === 0 ) {
            continue;
        }
        if ( isset( $tr[ $key ] ) && $tr[ $key ] !== $english ) {
            $map[ $english ] = $tr[ $key ];
        }
    }

    if ( empty( $map ) ) {
        return;
    }

    add_filter( 'gettext_sfs-hr', function ( $translation, $text ) use ( $map ) {
        return $map[ $text ] ?? $translation;
    }, 10, 2 );
}, 1 );

/**
 * Bootstrap modules.
 */
add_action('plugins_loaded', function(){
    \SFS\HR\Core\Capabilities::ensure_roles_caps();

    // Core admin + HR hooks
    (new \SFS\HR\Core\Admin())->hooks();
    (new \SFS\HR\Core\Hooks())->hooks();
    
    // Frontend shortcodes (employee self-service)
    (new \SFS\HR\Frontend\Shortcodes())->hooks();

    // HR modules
    (new \SFS\HR\Modules\Departments\DepartmentsModule())->hooks(); // departments UI + role mapping
    (new \SFS\HR\Modules\Leave\LeaveModule())->hooks();
    (new \SFS\HR\Modules\Attendance\AttendanceModule())->hooks();
    (new \SFS\HR\Modules\Workforce_Status\WorkforceStatusModule())->hooks();
    (new \SFS\HR\Modules\Employees\EmployeesModule())->hooks();
    (new \SFS\HR\Modules\Assets\AssetsModule())->hooks();

    // Employee Exit Module (unified Resignation + Settlement)
    (new \SFS\HR\Modules\EmployeeExit\EmployeeExitModule())->hooks();

    // From second version – Loans (Cash Advances)
    new \SFS\HR\Modules\Loans\LoansModule();

    // Payroll Module
    \SFS\HR\Modules\Payroll\PayrollModule::init();

    // Documents Module (employee document management)
    (new \SFS\HR\Modules\Documents\DocumentsModule())->hooks();

    // PWA Module (Progressive Web App for mobile punch in/out)
    (new \SFS\HR\Modules\PWA\PWAModule())->hooks();

    // Shift Swap Module (employees can request shift swaps)
    (new \SFS\HR\Modules\ShiftSwap\ShiftSwapModule())->hooks();

    // Reminders Module (birthday/anniversary notifications)
    (new \SFS\HR\Modules\Reminders\RemindersModule())->hooks();

    // Hiring Module (candidates and trainee students)
    \SFS\HR\Modules\Hiring\HiringModule::instance()->hooks();

    // Audit Trail (logging system)
    \SFS\HR\Core\AuditTrail::init();

    // Notification System (email/SMS alerts)
    \SFS\HR\Core\Notifications::init();

    // Performance Module (attendance commitment, goals, reviews, alerts)
    (new \SFS\HR\Modules\Performance\PerformanceModule())->hooks();
});

/**
 * Optional: admin notice if tables still missing (edge cases)
 */
add_action('admin_notices', function(){
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;

    $req    = $wpdb->prefix . 'sfs_hr_leave_requests';
    $dept   = $wpdb->prefix . 'sfs_hr_departments';
    $assets = $wpdb->prefix . 'sfs_hr_assets';

    $ok_req = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
        $req
    ));
    $ok_dept = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
        $dept
    ));
    $ok_assets = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
        $assets
    ));

    if (!$ok_req || !$ok_dept || !$ok_assets) {
        echo '<div class="notice notice-error"><p><strong>HR Suite:</strong> database tables not found. Deactivate → activate the plugin, or reload this page to let self-healing run.</p></div>';
    }
});

