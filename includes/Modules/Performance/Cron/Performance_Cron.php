<?php
namespace SFS\HR\Modules\Performance\Cron;

use SFS\HR\Modules\Performance\Services\Alerts_Service;
use SFS\HR\Modules\Performance\Services\Performance_Calculator;
use SFS\HR\Modules\Performance\PerformanceModule;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Performance Cron
 *
 * Handles scheduled tasks:
 * - Daily alert checks
 * - Monthly snapshot generation
 *
 * @version 1.0.0
 */
class Performance_Cron {

    const CRON_ALERTS = 'sfs_hr_performance_alerts_check';
    const CRON_SNAPSHOTS = 'sfs_hr_performance_snapshots';

    /**
     * Register hooks.
     */
    public function hooks(): void {
        add_action( 'init', [ $this, 'schedule' ] );
        add_action( self::CRON_ALERTS, [ $this, 'run_alert_checks' ] );
        add_action( self::CRON_SNAPSHOTS, [ $this, 'run_snapshot_generation' ] );
    }

    /**
     * Schedule cron jobs.
     */
    public function schedule(): void {
        // Daily alert check at 9 AM
        if ( ! wp_next_scheduled( self::CRON_ALERTS ) ) {
            $timestamp = $this->get_next_run_timestamp( '09:00' );
            wp_schedule_event( $timestamp, 'daily', self::CRON_ALERTS );
        }

        // Monthly snapshot on the 1st at midnight
        if ( ! wp_next_scheduled( self::CRON_SNAPSHOTS ) ) {
            $timestamp = $this->get_first_of_month_timestamp();
            wp_schedule_event( $timestamp, 'monthly', self::CRON_SNAPSHOTS );
        }

        // Register monthly interval if not exists
        add_filter( 'cron_schedules', function( $schedules ) {
            if ( ! isset( $schedules['monthly'] ) ) {
                $schedules['monthly'] = [
                    'interval' => 30 * DAY_IN_SECONDS,
                    'display'  => __( 'Monthly', 'sfs-hr' ),
                ];
            }
            return $schedules;
        } );
    }

    /**
     * Get next run timestamp for a given time.
     *
     * @param string $time H:i format
     * @return int
     */
    private function get_next_run_timestamp( string $time ): int {
        $tz = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );
        $target = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $time, $tz );

        if ( ! $target || $target <= $now ) {
            $target = $target ? $target->modify( '+1 day' ) : $now->modify( '+1 day' )->setTime( 9, 0 );
        }

        return $target->getTimestamp();
    }

    /**
     * Get timestamp for the 1st of next month.
     *
     * @return int
     */
    private function get_first_of_month_timestamp(): int {
        $tz = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );
        $first_of_next = $now->modify( 'first day of next month' )->setTime( 0, 0 );
        return $first_of_next->getTimestamp();
    }

    /**
     * Run alert checks.
     */
    public function run_alert_checks(): void {
        $settings = PerformanceModule::get_settings();

        if ( ! $settings['enabled'] || ! $settings['alerts']['enabled'] ) {
            return;
        }

        $created = 0;

        // Check attendance commitment
        $created += Alerts_Service::check_commitment_alerts();

        // Check overdue goals
        $created += Alerts_Service::check_goal_alerts();

        // Check due reviews
        $created += Alerts_Service::check_review_alerts();

        // Log
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR Performance] Alert check completed: %d alerts created/updated',
                $created
            ) );
        }

        do_action( 'sfs_hr_performance_alerts_checked', $created );
    }

    /**
     * Run monthly snapshot generation.
     */
    public function run_snapshot_generation(): void {
        global $wpdb;

        $settings = PerformanceModule::get_settings();

        if ( ! $settings['enabled'] || ! $settings['snapshots']['auto_generate'] ) {
            return;
        }

        $employees_table = $wpdb->prefix . 'sfs_hr_employees';

        // Get active employees
        $employees = $wpdb->get_col(
            "SELECT id FROM {$employees_table} WHERE status = 'active'"
        );

        // Calculate period (previous month)
        $period_start = date( 'Y-m-01', strtotime( 'first day of last month' ) );
        $period_end = date( 'Y-m-t', strtotime( 'last day of last month' ) );

        $generated = 0;

        foreach ( $employees as $employee_id ) {
            $result = Performance_Calculator::generate_snapshot( (int) $employee_id, $period_start, $period_end );
            if ( $result ) {
                $generated++;
            }
        }

        // Cleanup old snapshots
        $retention_days = $settings['snapshots']['retention_days'];
        if ( $retention_days > 0 ) {
            $cutoff_date = date( 'Y-m-d', strtotime( "-{$retention_days} days" ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}sfs_hr_performance_snapshots
                 WHERE created_at < %s",
                $cutoff_date
            ) );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR Performance] Monthly snapshots: %d generated for period %s to %s',
                $generated,
                $period_start,
                $period_end
            ) );
        }

        do_action( 'sfs_hr_performance_snapshots_generated', $generated, $period_start, $period_end );
    }

    /**
     * Manually trigger alert checks.
     *
     * @return int Number of alerts created
     */
    public static function trigger_alerts(): int {
        $instance = new self();
        ob_start();
        $instance->run_alert_checks();
        ob_end_clean();

        return Alerts_Service::get_statistics()['total_active'];
    }

    /**
     * Manually trigger snapshot generation.
     *
     * @param string $period_start
     * @param string $period_end
     * @return int Number of snapshots generated
     */
    public static function trigger_snapshots( string $period_start = '', string $period_end = '' ): int {
        global $wpdb;

        if ( empty( $period_start ) ) {
            $period_start = date( 'Y-m-01', strtotime( 'first day of last month' ) );
        }
        if ( empty( $period_end ) ) {
            $period_end = date( 'Y-m-t', strtotime( 'last day of last month' ) );
        }

        $employees_table = $wpdb->prefix . 'sfs_hr_employees';
        $employees = $wpdb->get_col(
            "SELECT id FROM {$employees_table} WHERE status = 'active'"
        );

        $generated = 0;

        foreach ( $employees as $employee_id ) {
            $result = Performance_Calculator::generate_snapshot( (int) $employee_id, $period_start, $period_end );
            if ( $result ) {
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * Get cron status.
     *
     * @return array
     */
    public static function get_status(): array {
        $alerts_next = wp_next_scheduled( self::CRON_ALERTS );
        $snapshots_next = wp_next_scheduled( self::CRON_SNAPSHOTS );

        return [
            'alerts' => [
                'scheduled' => (bool) $alerts_next,
                'next_run'  => $alerts_next ? wp_date( 'Y-m-d H:i:s', $alerts_next ) : null,
            ],
            'snapshots' => [
                'scheduled' => (bool) $snapshots_next,
                'next_run'  => $snapshots_next ? wp_date( 'Y-m-d H:i:s', $snapshots_next ) : null,
            ],
        ];
    }
}
