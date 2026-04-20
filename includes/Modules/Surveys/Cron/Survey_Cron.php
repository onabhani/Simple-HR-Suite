<?php
namespace SFS\HR\Modules\Surveys\Cron;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Surveys\Services\Survey_Enhancement_Service;

class Survey_Cron {

    const HOOK = 'sfs_hr_survey_daily';

    public static function register(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::HOOK );
        }
        add_action( self::HOOK, [ self::class, 'run' ] );
    }

    public static function run(): void {
        try {
            Survey_Enhancement_Service::process_scheduled();
        } catch ( \Throwable $e ) {
            error_log( '[SFS HR Surveys] Schedule processing failed: ' . $e->getMessage() );
        }

        try {
            self::auto_remind_published_surveys();
        } catch ( \Throwable $e ) {
            error_log( '[SFS HR Surveys] Auto-reminder failed: ' . $e->getMessage() );
        }
    }

    private static function auto_remind_published_surveys(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_surveys';

        $surveys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE status = %s",
                'published'
            )
        );

        foreach ( $surveys as $survey_id ) {
            Survey_Enhancement_Service::send_reminders( (int) $survey_id, 3 );
        }
    }
}
