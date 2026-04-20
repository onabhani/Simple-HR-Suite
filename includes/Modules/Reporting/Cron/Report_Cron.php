<?php
namespace SFS\HR\Modules\Reporting\Cron;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Reporting\Services\Report_Builder_Service;

class Report_Cron {

    const HOOK = 'sfs_hr_report_daily';

    public static function register(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::HOOK );
        }
        add_action( self::HOOK, [ self::class, 'run' ] );
    }

    public static function run(): void {
        try {
            Report_Builder_Service::process_scheduled_reports();
        } catch ( \Throwable $e ) {
            error_log( '[SFS HR Reporting] Scheduled report processing failed: ' . $e->getMessage() );
        }
    }
}
