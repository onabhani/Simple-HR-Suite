<?php
namespace SFS\HR\Modules\Projects;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/Admin/class-admin-pages.php';
require_once __DIR__ . '/Services/class-projects-service.php';
require_once __DIR__ . '/Rest/Projects_Rest.php';

use SFS\HR\Modules\Projects\Admin\Admin_Pages;
use SFS\HR\Modules\Projects\Rest\Projects_Rest;

class ProjectsModule {

    private static $instance = null;

    private function __construct() {}
    private function __clone() {}
    public function __wakeup() { throw new \RuntimeException( 'Cannot unserialize singleton.' ); }

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function hooks(): void {
        add_action( 'admin_init', [ $this, 'maybe_install_tables' ] );
        add_action( 'admin_menu', [ $this, 'register_menu' ], 14 );

        Admin_Pages::instance()->hooks();
        Projects_Rest::register();
    }

    public function register_menu(): void {
        add_submenu_page(
            'sfs-hr',
            __( 'Projects', 'sfs-hr' ),
            __( 'Projects', 'sfs-hr' ),
            'sfs_hr.manage',
            'sfs-hr-projects',
            [ Admin_Pages::instance(), 'render_page' ]
        );
    }

    /**
     * Create tables if they don't exist.
     */
    public function maybe_install_tables(): void {
        if ( get_option( 'sfs_hr_projects_db_ver' ) === '1.0' ) {
            return;
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        // Projects table
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}sfs_hr_projects` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `location_label` VARCHAR(191) NULL,
            `location_lat` DECIMAL(10,7) NULL,
            `location_lng` DECIMAL(10,7) NULL,
            `location_radius_m` INT UNSIGNED NULL DEFAULT 200,
            `start_date` DATE NULL,
            `end_date` DATE NULL,
            `manager_user_id` BIGINT(20) UNSIGNED NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `notes` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `active` (`active`),
            KEY `manager_user_id` (`manager_user_id`)
        ) {$charset}" );

        // Project-Employee assignments
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}sfs_hr_project_employees` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `project_id` BIGINT(20) UNSIGNED NOT NULL,
            `employee_id` BIGINT(20) UNSIGNED NOT NULL,
            `assigned_from` DATE NOT NULL,
            `assigned_to` DATE NULL,
            `created_at` DATETIME NOT NULL,
            `created_by` BIGINT(20) UNSIGNED NULL,
            PRIMARY KEY (`id`),
            KEY `project_id` (`project_id`),
            KEY `employee_id` (`employee_id`),
            KEY `date_range` (`assigned_from`, `assigned_to`)
        ) {$charset}" );

        // Project-Shift assignments
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$p}sfs_hr_project_shifts` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `project_id` BIGINT(20) UNSIGNED NOT NULL,
            `shift_id` BIGINT(20) UNSIGNED NOT NULL,
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `project_shift` (`project_id`, `shift_id`),
            KEY `project_id` (`project_id`)
        ) {$charset}" );

        update_option( 'sfs_hr_projects_db_ver', '1.0', false );
    }
}
