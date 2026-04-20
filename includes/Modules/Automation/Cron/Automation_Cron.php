<?php
namespace SFS\HR\Modules\Automation\Cron;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Automation\Services\Automation_Rule_Service;
use SFS\HR\Modules\Automation\Services\Scheduled_Actions_Service;

class Automation_Cron {

    const HOOK = 'sfs_hr_automation_daily';

    public static function register(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::HOOK );
        }
        add_action( self::HOOK, [ self::class, 'run' ] );
    }

    public static function run(): void {
        try {
            Automation_Rule_Service::evaluate_scheduled();
        } catch ( \Throwable $e ) {
            error_log( '[SFS HR Automation] Rule evaluation failed: ' . $e->getMessage() );
        }

        try {
            ( new Scheduled_Actions_Service() )->run_daily();
        } catch ( \Throwable $e ) {
            error_log( '[SFS HR Automation] Scheduled actions failed: ' . $e->getMessage() );
        }
    }
}
