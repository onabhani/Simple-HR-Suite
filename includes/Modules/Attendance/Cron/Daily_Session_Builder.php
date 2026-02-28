<?php
namespace SFS\HR\Modules\Attendance\Cron;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Attendance\AttendanceModule;

/**
 * Daily cron that rebuilds attendance sessions for yesterday and today.
 *
 * Ensures sessions exist for employees who punched but whose real-time
 * recalc may have been missed (e.g. offline punches synced late, server
 * restarts, or edge-case timing issues).
 */
class Daily_Session_Builder {

    const CRON_HOOK = 'sfs_hr_daily_session_build';

    /**
     * Register hooks.
     */
    public function hooks(): void {
        add_action( 'init', [ $this, 'schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'run' ] );
    }

    /**
     * Schedule the cron job (runs twice daily).
     */
    public function schedule(): void {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }
        wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
    }

    /**
     * Rebuild sessions for yesterday and today.
     */
    public function run(): void {
        $today     = wp_date( 'Y-m-d' );
        $yesterday = wp_date( 'Y-m-d', strtotime( '-1 day' ) );

        AttendanceModule::rebuild_sessions_for_date_static( $yesterday );
        AttendanceModule::rebuild_sessions_for_date_static( $today );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Daily session builder: rebuilt sessions for %s and %s.',
                $yesterday,
                $today
            ) );
        }
    }
}
