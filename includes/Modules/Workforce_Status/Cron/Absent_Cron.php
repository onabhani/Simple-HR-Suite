<?php
namespace SFS\HR\Modules\Workforce_Status\Cron;

use SFS\HR\Modules\Workforce_Status\Notifications\Absent_Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Absent Cron
 * Handles scheduled absent employee notifications to department managers.
 *
 * @version 1.0.0
 */
class Absent_Cron {

    /**
     * Cron hook name.
     */
    const CRON_HOOK = 'sfs_hr_absent_notifications';

    /**
     * Register hooks.
     */
    public function hooks(): void {
        add_action( 'init', [ $this, 'schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'run' ] );

        // Reschedule if settings change
        add_action( 'update_option_sfs_hr_absent_notification_settings', [ $this, 'reschedule' ] );
    }

    /**
     * Schedule cron job.
     */
    public function schedule(): void {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        $settings = Absent_Notifications::get_settings();

        if ( ! $settings['enabled'] ) {
            return;
        }

        $timestamp = $this->get_next_run_timestamp( $settings['send_time'] );
        wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
    }

    /**
     * Reschedule cron when settings change.
     */
    public function reschedule(): void {
        $this->unschedule();

        $settings = Absent_Notifications::get_settings();

        if ( $settings['enabled'] ) {
            $timestamp = $this->get_next_run_timestamp( $settings['send_time'] );
            wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Unschedule cron job.
     */
    public function unschedule(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Get the next run timestamp based on send time.
     *
     * @param string $send_time Time in H:i format (e.g., "20:00").
     * @return int Unix timestamp.
     */
    private function get_next_run_timestamp( string $send_time ): int {
        $tz = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );
        $target = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $send_time, $tz );

        if ( ! $target || $target <= $now ) {
            // Schedule for tomorrow if time has passed
            $target = $target ? $target->modify( '+1 day' ) : $now->modify( '+1 day' )->setTime( 20, 0 );
        }

        return $target->getTimestamp();
    }

    /**
     * Run absent notifications.
     */
    public function run(): void {
        $settings = Absent_Notifications::get_settings();

        if ( ! $settings['enabled'] ) {
            return;
        }

        // Send notifications for today
        $today = current_time( 'Y-m-d' );
        $sent_count = Absent_Notifications::send_absent_notifications( $today );

        // Log the run
        do_action( 'sfs_hr_absent_cron_completed', [
            'date'       => $today,
            'sent_count' => $sent_count,
            'run_time'   => current_time( 'mysql' ),
        ] );

        // Optionally log to error log for debugging
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Absent notifications sent: %d notifications for date %s',
                $sent_count,
                $today
            ) );
        }
    }

    /**
     * Manually trigger notifications (for testing or admin action).
     *
     * @param string|null $date Date in Y-m-d format. Defaults to today.
     * @return int Number of notifications sent.
     */
    public static function trigger_manual( ?string $date = null ): int {
        if ( ! $date ) {
            $date = current_time( 'Y-m-d' );
        }

        return Absent_Notifications::send_absent_notifications( $date );
    }

    /**
     * Get cron status information.
     *
     * @return array Status information.
     */
    public static function get_status(): array {
        $next_run = wp_next_scheduled( self::CRON_HOOK );
        $settings = Absent_Notifications::get_settings();

        return [
            'enabled'      => $settings['enabled'],
            'send_time'    => $settings['send_time'],
            'scheduled'    => (bool) $next_run,
            'next_run'     => $next_run ? wp_date( 'Y-m-d H:i:s', $next_run ) : null,
            'next_run_utc' => $next_run ? gmdate( 'Y-m-d H:i:s', $next_run ) : null,
        ];
    }
}
