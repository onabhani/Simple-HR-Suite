<?php
namespace SFS\HR\Modules\Attendance\Cron;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Attendance\AttendanceModule;

/**
 * Hourly cron that auto-closes stale attendance sessions.
 *
 * When an employee forgets to clock out and the overtime buffer has expired,
 * this job inserts an automatic clock-out punch at the shift-end + buffer
 * deadline so the employee is no longer blocked from clocking in the next day.
 */
class Stale_Session_Closer {

    const CRON_HOOK = 'sfs_hr_close_stale_sessions';

    /**
     * Register hooks.
     */
    public function hooks(): void {
        add_action( 'init', [ $this, 'schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'run' ] );
    }

    /**
     * Schedule the cron job (runs hourly).
     */
    public function schedule(): void {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }
        wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
    }

    /**
     * Find and auto-close all stale open sessions.
     */
    public function run(): void {
        global $wpdb;

        $pT    = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $now   = current_time( 'timestamp', true ); // UTC timestamp
        $today = wp_date( 'Y-m-d' );

        // Find employees whose last punch is an open session (in or break_end)
        // from yesterday or the day before (covers overnight + missed 2-day gaps).
        $lookback = wp_date( 'Y-m-d H:i:s', strtotime( '-2 days' ), new \DateTimeZone( 'UTC' ) );

        $open_sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.employee_id, p.punch_type, p.punch_time
             FROM {$pT} p
             INNER JOIN (
                 SELECT employee_id, MAX(punch_time) AS max_time
                 FROM {$pT}
                 WHERE punch_time >= %s
                 GROUP BY employee_id
             ) latest ON p.employee_id = latest.employee_id AND p.punch_time = latest.max_time
             WHERE p.punch_type IN ('in', 'break_end', 'break_start')",
            $lookback
        ) );

        if ( empty( $open_sessions ) ) {
            return;
        }

        $closed_count = 0;

        foreach ( $open_sessions as $session ) {
            $employee_id = (int) $session->employee_id;
            $last_ts     = strtotime( $session->punch_time . ' UTC' );
            $last_local  = wp_date( 'Y-m-d', $last_ts );

            // Skip if the session is from today (employee may still be working).
            if ( $last_local >= $today ) {
                continue;
            }

            // Resolve the shift for the day the session started.
            $prev_shift = AttendanceModule::resolve_shift_for_date( $employee_id, $last_local );
            $prev_segs  = AttendanceModule::build_segments_from_shift( $prev_shift, $last_local );

            $deadline_ts = 0;

            if ( ! empty( $prev_segs ) ) {
                $lastSeg    = end( $prev_segs );
                $segEndTs   = strtotime( $lastSeg['end_utc'] . ' UTC' );
                $confBufRaw = $prev_shift->overtime_buffer_minutes ?? null;
                $bufferMin  = ( $confBufRaw !== null )
                    ? (int) $confBufRaw
                    : min( (int) round( $lastSeg['minutes'] * 0.5 ), 240 );
                $deadline_ts = $segEndTs + $bufferMin * 60;
            } else {
                // Total-hours shifts (00:00–00:00) produce no segments.
                $confBufRaw = $prev_shift->overtime_buffer_minutes ?? null;
                $bufferMin  = ( $confBufRaw !== null )
                    ? (int) $confBufRaw
                    : 240; // 4-hour default for segment-less shifts
                $deadline_ts = $last_ts + $bufferMin * 60;
            }

            // Only auto-close if the buffer has expired.
            if ( $now <= $deadline_ts ) {
                continue;
            }

            // Insert an automatic clock-out punch at the deadline time.
            $auto_close_utc = gmdate( 'Y-m-d H:i:s', $deadline_ts );
            $now_utc        = gmdate( 'Y-m-d H:i:s', $now );

            // Guard: skip if an auto-close punch already exists (e.g. the
            // status endpoint already handled it inline).
            $already_closed = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$pT}
                 WHERE employee_id = %d AND punch_type = 'out'
                   AND punch_time = %s AND note LIKE '%%[auto-close]%%'",
                $employee_id, $auto_close_utc
            ) );
            if ( $already_closed ) {
                continue;
            }

            // If the last punch is break_start, close the break before clocking out.
            if ( $session->punch_type === 'break_start' ) {
                $wpdb->insert( $pT, [
                    'employee_id' => $employee_id,
                    'punch_type'  => 'break_end',
                    'punch_time'  => $auto_close_utc,
                    'source'      => 'manager_adjust',
                    'note'        => '[auto-close] Break ended automatically — stale session.',
                    'created_at'  => $now_utc,
                    'created_by'  => null,
                ] );
            }

            // Insert the auto clock-out punch.
            $wpdb->insert( $pT, [
                'employee_id' => $employee_id,
                'punch_type'  => 'out',
                'punch_time'  => $auto_close_utc,
                'source'      => 'manager_adjust',
                'note'        => '[auto-close] Shift auto-closed — employee did not clock out.',
                'created_at'  => $now_utc,
                'created_by'  => null,
            ] );

            if ( $wpdb->insert_id ) {
                $closed_count++;

                // Rebuild the session for the affected date.
                AttendanceModule::recalc_session_for( $employee_id, $last_local, $wpdb );

                // Also rebuild today in case the overnight shift spans into today.
                AttendanceModule::recalc_session_for( $employee_id, $today, $wpdb );
            }
        }

        if ( $closed_count > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Stale session closer: auto-closed %d session(s).',
                $closed_count
            ) );
        }
    }
}
