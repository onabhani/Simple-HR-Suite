<?php
namespace SFS\HR\Modules\Reporting;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/Services/HR_Analytics_Service.php';
require_once __DIR__ . '/Services/Report_Builder_Service.php';
require_once __DIR__ . '/Rest/Reporting_Rest.php';
require_once __DIR__ . '/Cron/Report_Cron.php';

use SFS\HR\Modules\Reporting\Rest\Reporting_Rest;
use SFS\HR\Modules\Reporting\Cron\Report_Cron;

class ReportingModule {

    public function hooks(): void {
        add_action( 'admin_init', [ $this, 'maybe_install_tables' ] );

        Reporting_Rest::register();
        Report_Cron::register();
    }

    public function maybe_install_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'sfs_hr_saved_reports';

        $exists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );

        if ( ! $exists ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(150) NOT NULL,
                `description` TEXT NULL,
                `report_type` VARCHAR(50) NOT NULL,
                `config` JSON NOT NULL,
                `schedule_type` ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none',
                `schedule_email` TEXT NULL,
                `last_run_at` DATETIME NULL,
                `created_by` BIGINT(20) UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_report_type` (`report_type`),
                KEY `idx_created_by` (`created_by`)
            ) {$charset}" );
        }
    }
}
