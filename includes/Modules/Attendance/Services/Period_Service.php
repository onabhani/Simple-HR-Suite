<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Period_Service
 *
 * Attendance period calculations (full month vs. custom start day).
 * Extracted from AttendanceModule — the original static methods on
 * AttendanceModule now delegate here for backwards compatibility.
 */
class Period_Service {

    const OPT_SETTINGS = 'sfs_hr_attendance_settings';

    /**
     * Get the current attendance period boundaries based on configured settings.
     *
     * @param string $reference_date Optional Y-m-d date to calculate around (defaults to today).
     * @return array{start: string, end: string} Y-m-d formatted start and end dates.
     */
    public static function get_current_period( string $reference_date = '' ): array {
        $opt        = get_option( self::OPT_SETTINGS, [] );
        $type       = $opt['period_type'] ?? 'full_month';
        $start_day  = isset( $opt['period_start_day'] ) ? (int) $opt['period_start_day'] : 1;

        if ( empty( $reference_date ) ) {
            $reference_date = current_time( 'Y-m-d' );
        }

        $ref_ts = strtotime( $reference_date );
        $year   = (int) date( 'Y', $ref_ts );
        $month  = (int) date( 'n', $ref_ts );
        $day    = (int) date( 'j', $ref_ts );

        if ( $type === 'custom' && $start_day > 1 ) {
            if ( $day >= $start_day ) {
                // Period starts this month
                $start = sprintf( '%04d-%02d-%02d', $year, $month, $start_day );
                // Ends on (start_day - 1) of next month
                $next  = mktime( 0, 0, 0, $month + 1, $start_day - 1, $year );
                $end   = date( 'Y-m-d', $next );
            } else {
                // Period started last month
                $prev  = mktime( 0, 0, 0, $month - 1, $start_day, $year );
                $start = date( 'Y-m-d', $prev );
                // Ends on (start_day - 1) of this month
                $end   = sprintf( '%04d-%02d-%02d', $year, $month, $start_day - 1 );
            }
        } else {
            // Full calendar month
            $start = sprintf( '%04d-%02d-01', $year, $month );
            $end   = date( 'Y-m-t', $ref_ts );
        }

        return [ 'start' => $start, 'end' => $end ];
    }

    /**
     * Get the previous attendance period (the one just before the current one).
     *
     * @param string $reference_date  Optional Y-m-d to calculate around (defaults to today).
     * @return array{start: string, end: string}
     */
    public static function get_previous_period( string $reference_date = '' ): array {
        $current = self::get_current_period( $reference_date );
        // Go to one day before the current period start
        $day_before = date( 'Y-m-d', strtotime( $current['start'] . ' -1 day' ) );
        return self::get_current_period( $day_before );
    }

    /**
     * Format a period as a human-readable label.
     *
     * @param array{start: string, end: string} $period
     * @return string e.g. "Jan 25 – Feb 24, 2026" or "February 2026"
     */
    public static function format_period_label( array $period ): string {
        $start_ts = strtotime( $period['start'] );
        $end_ts   = strtotime( $period['end'] );

        $start_day = (int) date( 'j', $start_ts );
        $end_day   = (int) date( 'j', $end_ts );
        $same_month = date( 'Y-m', $start_ts ) === date( 'Y-m', $end_ts );

        // If it's a full calendar month (1st to last day), show "Month YYYY"
        if ( $start_day === 1 && $same_month && $end_day === (int) date( 't', $start_ts ) ) {
            return date_i18n( 'F Y', $start_ts );
        }

        // Custom period: show "Mon D – Mon D, YYYY"
        $same_year = date( 'Y', $start_ts ) === date( 'Y', $end_ts );
        if ( $same_year ) {
            return date_i18n( 'M j', $start_ts ) . ' – ' . date_i18n( 'M j, Y', $end_ts );
        }
        return date_i18n( 'M j, Y', $start_ts ) . ' – ' . date_i18n( 'M j, Y', $end_ts );
    }
}
