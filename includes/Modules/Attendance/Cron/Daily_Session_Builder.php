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
     * Transient key set while a build is in progress (short TTL guard).
     */
    const RUNNING_TRANSIENT = 'sfs_hr_session_build_running';

    /**
     * Transient key set after a successful build (throttle key).
     */
    const LAST_SUCCESS_TRANSIENT = 'sfs_hr_session_build_last_success';

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
        // Atomic guard against concurrent execution.
        // add_transient only succeeds if the key doesn't already exist.
        if ( ! add_transient( self::RUNNING_TRANSIENT, time(), 10 * MINUTE_IN_SECONDS ) ) {
            return; // Another instance is already running.
        }

        // Use UTC dates so the target day is consistent regardless of site timezone.
        $today     = gmdate( 'Y-m-d' );
        $yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

        try {
            AttendanceModule::rebuild_sessions_for_date_static( $yesterday );
            AttendanceModule::rebuild_sessions_for_date_static( $today );

            // Mark successful completion — throttles fallback for 6 hours.
            set_transient( self::LAST_SUCCESS_TRANSIENT, time(), 6 * HOUR_IN_SECONDS );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[SFS HR] Daily session builder: rebuilt sessions for %s and %s.',
                    $yesterday,
                    $today
                ) );
            }
        } finally {
            delete_transient( self::RUNNING_TRANSIENT );
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
        // Skip AJAX / REST / CLI / WP-CLI requests to minimise overhead.
        if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }

        // Don't retry if a previous run succeeded recently.
        if ( get_transient( self::LAST_SUCCESS_TRANSIENT ) ) {
            return;
        }

        // Don't start if another instance is already running.
        if ( get_transient( self::RUNNING_TRANSIENT ) ) {
            return;
        }

        $this->run();
    }
}
