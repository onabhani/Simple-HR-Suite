<?php
/**
 * Plugin Name: Simple HR Suite
 * Description: Simple HR Suite – employees, departments, leave, balances, approvals.
 * Version: 0.1.8 BETA
 * Author: Omar Alnabhani
 * Author URI: https://hdqah.com
 * Text Domain: sfs-hr
 */

if (!defined('ABSPATH')) { exit; }

define('SFS_HR_VER', '0.1.8');
define('SFS_HR_DIR', plugin_dir_path(__FILE__));
define('SFS_HR_URL', plugin_dir_url(__FILE__));

spl_autoload_register(function($class){
    if (strpos($class, 'SFS\\HR\\') !== 0) return;
    $path = SFS_HR_DIR . 'includes/' . str_replace(['SFS\\HR\\','\\'], ['','/'], $class) . '.php';
    if (file_exists($path)) require_once $path;
});

register_activation_hook(__FILE__, function(){
    \SFS\HR\Core\Capabilities::ensure_roles_caps();
    \SFS\HR\Install\Migrations::run();
    update_option('sfs_hr_db_ver', SFS_HR_VER);
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
 */
add_action('admin_init', function(){
    \SFS\HR\Core\Capabilities::ensure_roles_caps();

    global $wpdb;
    $types_table  = $wpdb->prefix . 'sfs_hr_leave_types';
    $req_table    = $wpdb->prefix . 'sfs_hr_leave_requests';
    $bal_table    = $wpdb->prefix . 'sfs_hr_leave_balances';
    $emp_table    = $wpdb->prefix . 'sfs_hr_employees';
    $dept_table   = $wpdb->prefix . 'sfs_hr_departments';
    $assets_table = $wpdb->prefix . 'sfs_hr_assets';
    $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $shifts_table = $wpdb->prefix . 'sfs_hr_attendance_shifts';

    $table_exists = function(string $table) use ($wpdb){
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        ));
    };
    $column_exists = function(string $table, string $column) use ($wpdb){
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
            $table, $column
        ));
    };

    $stored = get_option('sfs_hr_db_ver', '0');
    $needs_migration = version_compare((string)$stored, SFS_HR_VER, '<');

    $needs_tables = (
        !$table_exists($types_table)  ||
        !$table_exists($req_table)    ||
        !$table_exists($bal_table)    ||
        !$table_exists($emp_table)    ||
        !$table_exists($dept_table)   ||
        !$table_exists($assets_table) ||
        !$table_exists($assign_table)
    );

    $needs_columns = false;
    if ($table_exists($types_table) && !$column_exists($types_table, 'is_annual')) $needs_columns = true;
    if ($table_exists($emp_table)   && !$column_exists($emp_table,   'hire_date')) $needs_columns = true;
    if ($table_exists($shifts_table) && !$column_exists($shifts_table, 'weekly_overrides')) $needs_columns = true;

    if ($needs_migration || $needs_tables || $needs_columns) {
        \SFS\HR\Install\Migrations::run();
        update_option('sfs_hr_db_ver', SFS_HR_VER);
    }

    // Seed/mark data if tables already exist
    if ($table_exists($types_table)) {
        $count_types = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$types_table}");
        if ($count_types === 0) {
            \SFS\HR\Install\Migrations::run();
            update_option('sfs_hr_db_ver', SFS_HR_VER);
        } else {
            // Mark annual names broadly
            if ($column_exists($types_table, 'is_annual')) {
                $wpdb->query("UPDATE {$types_table}
                              SET is_annual=1
                              WHERE (LOWER(name)='annual'
                                  OR LOWER(name)='annual leave'
                                  OR LOWER(name)='annual vacation'
                                  OR LOWER(name) LIKE 'annual %')
                                AND (is_annual IS NULL OR is_annual=0)");
            }
            add_option('sfs_hr_annual_lt5', '21');
            add_option('sfs_hr_annual_ge5','30');
        }
    }

    // Ensure global fallback approver role option exists
    if (get_option('sfs_hr_global_approver_role', '') === '') {
        add_option('sfs_hr_global_approver_role', 'sfs_hr_manager');
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
        update_option('sfs_hr_assets_db_version', '0.1.9-assets-mvp');
    }
});

/** Bootstrap modules */
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

});


/** Optional: admin notice if tables still missing (edge cases) */
add_action('admin_notices', function(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $req    = $wpdb->prefix.'sfs_hr_leave_requests';
    $dept   = $wpdb->prefix.'sfs_hr_departments';
    $assets = $wpdb->prefix.'sfs_hr_assets';
    $ok_req    = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
        $req
    ));
    $ok_dept   = $wpdb->get_var($wpdb->prepare(
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



register_activation_hook(__FILE__, function () {
    global $wpdb;

    (new \SFS\HR\Modules\Leave\LeaveModule())->install();

    // Ensure Assets tables are created on activation - use direct SQL
    $assets_table = $wpdb->prefix . 'sfs_hr_assets';
    $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $charset_collate = $wpdb->get_charset_collate();

    // Create Assets table
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

    // Create Asset Assignments table
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

    // Set the version option
    update_option('sfs_hr_assets_db_version', '0.1.9-assets-mvp');
});
