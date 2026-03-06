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
     * Option key used as an atomic lock while a build is in progress.
     * Uses the options API (add_option) for atomic set-if-absent semantics.
     */
    const RUNNING_LOCK = 'sfs_hr_session_build_running';

    /**
     * Transient key set after a successful build (throttle key).
     */
    const LAST_SUCCESS_TRANSIENT = 'sfs_hr_session_build_last_success';

    /**
     * Maximum age (seconds) of the running lock before it's considered stale.
     */
    const LOCK_TTL = 600; // 10 minutes

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
     * Attempt to acquire the running lock atomically.
     *
     * Uses add_option() which only succeeds if the key doesn't exist yet.
     * Also cleans up stale locks from crashed/timed-out runs.
     */
    private static function acquire_lock(): bool {
        $now = time();

        // Check for stale lock — if previous run crashed, clean it up.
        $existing = get_option( self::RUNNING_LOCK );
        if ( $existing !== false && ( $now - (int) $existing ) > self::LOCK_TTL ) {
            delete_option( self::RUNNING_LOCK );
        }

        // add_option returns false if the key already exists (atomic set-if-absent).
        return (bool) add_option( self::RUNNING_LOCK, $now, '', 'no' );
    }

    /**
     * Release the running lock.
     */
    private static function release_lock(): void {
        delete_option( self::RUNNING_LOCK );
    }

    /**
     * Rebuild sessions for yesterday and today.
     */
    public function run(): void {
        if ( ! self::acquire_lock() ) {
            return; // Another instance is already running.
        }

        // Use pure UTC arithmetic so the target day is consistent
        // regardless of site timezone or PHP default timezone.
        $now_ts    = time();
        $today     = gmdate( 'Y-m-d', $now_ts );
        $yesterday = gmdate( 'Y-m-d', $now_ts - 86400 );

        try {
            AttendanceModule::rebuild_sessions_for_date_static( $yesterday );
            AttendanceModule::rebuild_sessions_for_date_static( $today );

            // Mark successful completion — throttles fallback for 6 hours.
            set_transient( self::LAST_SUCCESS_TRANSIENT, $now_ts, 6 * HOUR_IN_SECONDS );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[SFS HR] Daily session builder: rebuilt sessions for %s and %s.',
                    $yesterday,
                    $today
                ) );
            }
        } finally {
            self::release_lock();
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

        // Don't start if another instance is already running (cheap pre-filter).
        // Allow through if the lock is stale so acquire_lock() can clean it up.
        $lock_ts = get_option( self::RUNNING_LOCK );
        if ( $lock_ts !== false && ( time() - (int) $lock_ts ) <= self::LOCK_TTL ) {
            return;
        }

        $this->run();
    }
}
