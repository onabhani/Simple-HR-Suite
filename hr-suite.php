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

    // Self-heal Assets tables if missing
    if (!$table_exists($assets_table) || !$table_exists($assign_table)) {
        // Load and instantiate the Assets module to create tables
        if (class_exists('\SFS\HR\Modules\Assets\AssetsModule')) {
            $assets_module = new \SFS\HR\Modules\Assets\AssetsModule();
            $assets_module->maybe_upgrade_db();
        }
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
    (new \SFS\HR\Modules\Leave\LeaveModule())->install();
});
