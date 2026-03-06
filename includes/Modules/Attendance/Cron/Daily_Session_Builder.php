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
     * Transient key used to throttle the fallback check.
     */
    const FALLBACK_TRANSIENT = 'sfs_hr_session_build_last_run';

    /**
     * Register hooks.
     */
    public function hooks(): void {
        add_action( 'init', [ $this, 'schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'run' ] );

        // Fallback: if the WP cron event was missed (common on low-traffic
        // sites or when WP cron is disabled), piggyback on 'shutdown' to
        // ensure sessions are eventually built.  Throttled to once per 6 hours
        // via a transient so it doesn't run on every request.
        add_action( 'shutdown', [ $this, 'maybe_run_fallback' ] );
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

        // Update the fallback transient so the shutdown hook knows we ran.
        set_transient( self::FALLBACK_TRANSIENT, time(), 6 * HOUR_IN_SECONDS );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Daily session builder: rebuilt sessions for %s and %s.',
                $yesterday,
                $today
            ) );
        }
    }

    /**
     * Fallback: run on shutdown if the cron event hasn't fired recently.
     *
     * This catches the common scenario where WP cron is disabled
     * (DISABLE_WP_CRON) or the site has too little traffic to trigger
     * scheduled events.  Without this, absent employees would get no
     * session record and absences would be invisible in reports.
     */
    public function maybe_run_fallback(): void {
        // Skip AJAX / REST / CLI requests to minimise overhead.
        if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        $last_run = get_transient( self::FALLBACK_TRANSIENT );
        if ( $last_run ) {
            return; // ran recently — nothing to do
        }

        // Mark as running immediately to prevent parallel requests from
        // also triggering the rebuild.
        set_transient( self::FALLBACK_TRANSIENT, time(), 6 * HOUR_IN_SECONDS );

        $this->run();
    }
}
