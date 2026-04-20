<?php
namespace SFS\HR\Modules\Automation;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/Services/Automation_Rule_Service.php';
require_once __DIR__ . '/Services/Scheduled_Actions_Service.php';
require_once __DIR__ . '/Rest/Automation_Rest.php';
require_once __DIR__ . '/Cron/Automation_Cron.php';

use SFS\HR\Modules\Automation\Rest\Automation_Rest;
use SFS\HR\Modules\Automation\Cron\Automation_Cron;
use SFS\HR\Modules\Automation\Services\Automation_Rule_Service;

class AutomationModule {

    public function hooks(): void {
        add_action( 'admin_init', [ $this, 'maybe_install_tables' ] );

        Automation_Rest::register();
        Automation_Cron::register();

        add_action( 'sfs_hr_employee_created',    [ self::class, 'on_employee_event' ], 10, 2 );
        add_action( 'sfs_hr_employee_updated',    [ self::class, 'on_employee_updated' ], 10, 3 );
        add_action( 'sfs_hr_leave_request_created', [ self::class, 'on_event' ], 10, 2 );
        add_action( 'sfs_hr_leave_request_status_changed', [ self::class, 'on_event' ], 10, 2 );
        add_action( 'sfs_hr_attendance_late',     [ self::class, 'on_event' ], 10, 2 );
        add_action( 'sfs_hr_loan_approved',       [ self::class, 'on_event' ], 10, 2 );
        add_action( 'sfs_hr_resignation_submitted', [ self::class, 'on_event' ], 10, 2 );
        add_action( 'sfs_hr_payroll_run_approved', [ self::class, 'on_event' ], 10, 2 );
    }

    public static function on_event( string $event_name, array $context = [] ): void {
        try {
            Automation_Rule_Service::evaluate_event( $event_name, $context );
        } catch ( \Throwable $e ) {
            error_log( '[SFS HR Automation] Event handler error: ' . $e->getMessage() );
        }
    }

    public static function on_employee_event( string $event_name, array $context = [] ): void {
        self::on_event( $event_name, $context );
    }

    public static function on_employee_updated( string $event_name, array $context = [], array $changes = [] ): void {
        self::on_event( $event_name, $context );

        if ( ! empty( $changes ) && ! empty( $context['employee_id'] ) ) {
            foreach ( $changes as $field => $vals ) {
                $old = $vals['old'] ?? null;
                $new = $vals['new'] ?? null;
                if ( $old !== $new ) {
                    try {
                        Automation_Rule_Service::evaluate_field_change(
                            'employee', $field, $old, $new, (int) $context['employee_id']
                        );
                    } catch ( \Throwable $e ) {
                        error_log( '[SFS HR Automation] Field change error: ' . $e->getMessage() );
                    }
                }
            }
        }
    }

    public function maybe_install_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $rules_table = $wpdb->prefix . 'sfs_hr_automation_rules';
        $logs_table  = $wpdb->prefix . 'sfs_hr_automation_logs';

        $rules_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $rules_table ) );

        if ( ! $rules_exists ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$rules_table}` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(150) NOT NULL,
                `description` TEXT NULL,
                `trigger_type` ENUM('event','schedule','field_change') NOT NULL,
                `trigger_config` JSON NOT NULL,
                `action_type` ENUM('notify','update_field','create_task','escalate') NOT NULL,
                `action_config` JSON NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `priority` SMALLINT UNSIGNED NOT NULL DEFAULT 100,
                `created_by` BIGINT(20) UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_trigger_type` (`trigger_type`),
                KEY `idx_is_active` (`is_active`)
            ) {$charset}" );
        }

        $logs_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $logs_table ) );

        if ( ! $logs_exists ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$logs_table}` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `rule_id` BIGINT(20) UNSIGNED NOT NULL,
                `trigger_type` VARCHAR(30) NOT NULL,
                `trigger_data` JSON NULL,
                `action_type` VARCHAR(30) NOT NULL,
                `action_result` JSON NULL,
                `status` ENUM('success','failed','skipped') NOT NULL DEFAULT 'success',
                `error_message` TEXT NULL,
                `employee_id` BIGINT(20) UNSIGNED NULL,
                `executed_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_rule_id` (`rule_id`),
                KEY `idx_executed_at` (`executed_at`),
                KEY `idx_employee` (`employee_id`)
            ) {$charset}" );
        }
    }
}
