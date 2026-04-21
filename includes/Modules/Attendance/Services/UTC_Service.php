<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * UTC_Service
 *
 * Handles UTC timestamp normalization for attendance punches.
 *
 * Stores a UTC copy (punch_time_utc) and the originating timezone offset
 * (tz_offset) alongside each punch so that cross-timezone duration
 * calculations and DST-safe reporting are possible.
 *
 * New columns required on sfs_hr_attendance_punches (added via Migration):
 *   - punch_time_utc  DATETIME NULL
 *   - tz_offset       VARCHAR(6) NULL   e.g. '+03:00'
 *
 * New columns required on sfs_hr_attendance_sessions (added via Migration):
 *   - in_time_utc     DATETIME NULL
 *   - out_time_utc    DATETIME NULL
 *
 * @since 2.3.0
 */
class UTC_Service {

    // ── Constants ────────────────────────────────────────────────────────────

    /** Default offset used as last-resort fallback (UTC+3 = AST/Riyadh). */
    const DEFAULT_OFFSET = '+03:00';

    /** Option key for company profile. */
    const COMPANY_PROFILE_KEY = 'sfs_hr_company_profile';

    /** User meta key for per-employee timezone offset. */
    const EMPLOYEE_TZ_META_KEY = 'tz_offset';

    // ── Conversion helpers ────────────────────────────────────────────────────

    /**
     * Convert a local datetime + timezone offset to UTC.
     *
     * @param string $local_datetime  'Y-m-d H:i:s'
     * @param string $tz_offset       '+03:00', '-05:30', etc.
     * @return string UTC datetime in 'Y-m-d H:i:s', or '0000-00-00 00:00:00' on failure.
     */
    public static function to_utc( string $local_datetime, string $tz_offset ): string {
        if ( ! self::is_valid_offset( $tz_offset ) ) {
            $tz_offset = self::DEFAULT_OFFSET;
        }

        try {
            $tz  = new \DateTimeZone( $tz_offset );
            $dt  = new \DateTime( $local_datetime, $tz );
            $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( \Exception $e ) {
            return '0000-00-00 00:00:00';
        }
    }

    /**
     * Convert a UTC datetime back to local time using the given offset.
     *
     * @param string $utc_datetime  'Y-m-d H:i:s' (UTC)
     * @param string $tz_offset     '+03:00', '-05:30', etc.
     * @return string Local datetime in 'Y-m-d H:i:s', or '0000-00-00 00:00:00' on failure.
     */
    public static function to_local( string $utc_datetime, string $tz_offset ): string {
        if ( ! self::is_valid_offset( $tz_offset ) ) {
            $tz_offset = self::DEFAULT_OFFSET;
        }

        try {
            $dt = new \DateTime( $utc_datetime, new \DateTimeZone( 'UTC' ) );
            $dt->setTimezone( new \DateTimeZone( $tz_offset ) );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( \Exception $e ) {
            return '0000-00-00 00:00:00';
        }
    }

    // ── Punch storage ─────────────────────────────────────────────────────────

    /**
     * Persist UTC fields on an existing punch row.
     *
     * Called after a punch is inserted so the punch_id is already known.
     * Derives punch_time_utc from the punch's existing punch_time and the
     * supplied offset.
     *
     * @param int    $punch_id   Row ID in sfs_hr_attendance_punches.
     * @param string $local_time Local datetime 'Y-m-d H:i:s'.
     * @param string $tz_offset  e.g. '+03:00'.
     * @return bool True on success, false on DB error or invalid args.
     */
    public static function store_punch_utc( int $punch_id, string $local_time, string $tz_offset ): bool {
        global $wpdb;

        if ( $punch_id <= 0 ) {
            return false;
        }

        if ( ! self::is_valid_offset( $tz_offset ) ) {
            $tz_offset = self::get_server_tz_offset();
        }

        $utc_time = self::to_utc( $local_time, $tz_offset );
        if ( '0000-00-00 00:00:00' === $utc_time ) {
            return false;
        }

        $table = $wpdb->prefix . 'sfs_hr_attendance_punches';

        $result = $wpdb->update(
            $table,
            [
                'punch_time_utc' => $utc_time,
                'tz_offset'      => $tz_offset,
            ],
            [ 'id' => $punch_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return false !== $result;
    }

    // ── Employee timezone resolution ──────────────────────────────────────────

    /**
     * Resolve an employee's timezone offset string.
     *
     * Priority order:
     *   1. Employee user meta key 'tz_offset'
     *   2. Company profile 'timezone' field (treated as a PHP TZ identifier
     *      or a numeric offset string)
     *   3. WordPress timezone_string option
     *   4. WordPress gmt_offset option
     *   5. Hard-coded fallback '+03:00' (AST / Riyadh)
     *
     * @param int $employee_id  The sfs_hr_employees.id value (also used as WP user lookup via join).
     * @return string Validated offset string, e.g. '+03:00'.
     */
    public static function get_employee_tz_offset( int $employee_id ): string {
        global $wpdb;

        // 1) Employee user meta
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $user_id   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$emp_table} WHERE id = %d LIMIT 1",
            $employee_id
        ) );

        if ( $user_id > 0 ) {
            $meta_offset = get_user_meta( $user_id, self::EMPLOYEE_TZ_META_KEY, true );
            if ( $meta_offset && self::is_valid_offset( (string) $meta_offset ) ) {
                return (string) $meta_offset;
            }
        }

        // 2) Company profile timezone
        $profile = get_option( self::COMPANY_PROFILE_KEY, [] );
        if ( ! empty( $profile['timezone'] ) ) {
            $offset = self::tz_name_to_offset( (string) $profile['timezone'] );
            if ( $offset ) {
                return $offset;
            }
        }

        // 3) WordPress timezone_string
        $wp_tz_string = get_option( 'timezone_string', '' );
        if ( $wp_tz_string ) {
            $offset = self::tz_name_to_offset( $wp_tz_string );
            if ( $offset ) {
                return $offset;
            }
        }

        // 4) WordPress gmt_offset (float, e.g. 3 or -5.5)
        $gmt_offset = get_option( 'gmt_offset', null );
        if ( null !== $gmt_offset && '' !== $gmt_offset ) {
            $offset = self::float_offset_to_string( (float) $gmt_offset );
            if ( $offset ) {
                return $offset;
            }
        }

        // 5) Fallback
        return self::DEFAULT_OFFSET;
    }

    // ── Backfill ──────────────────────────────────────────────────────────────

    /**
     * Backfill punch_time_utc and tz_offset for punches that have NULL punch_time_utc.
     *
     * Uses the current server/WordPress timezone as the assumed local timezone
     * for existing punch records (since those were stored in server local time).
     *
     * Processes in batches to avoid memory exhaustion on large datasets.
     *
     * @param int $batch_size Number of rows per batch (default 500).
     * @return int Total number of rows updated.
     */
    public static function backfill_utc( int $batch_size = 500 ): int {
        global $wpdb;

        $table      = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $server_tz  = self::get_server_tz_offset();
        $total      = 0;

        $max_iterations = 1000; // Safety limit to prevent infinite loops.
        $iteration      = 0;

        while ( $iteration < $max_iterations ) {
            $iteration++;

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, punch_time FROM {$table}
                 WHERE punch_time_utc IS NULL
                 LIMIT %d",
                $batch_size
            ) );

            if ( empty( $rows ) ) {
                break;
            }

            $batch_updated = 0;
            foreach ( $rows as $row ) {
                $utc_time = self::to_utc( $row->punch_time, $server_tz );
                if ( '0000-00-00 00:00:00' === $utc_time ) {
                    // Mark unfixable rows with a sentinel so they don't block progress.
                    $wpdb->update(
                        $table,
                        [
                            'punch_time_utc' => '1970-01-01 00:00:00',
                            'tz_offset'      => $server_tz,
                        ],
                        [ 'id' => (int) $row->id ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                    continue;
                }

                $updated = $wpdb->update(
                    $table,
                    [
                        'punch_time_utc' => $utc_time,
                        'tz_offset'      => $server_tz,
                    ],
                    [ 'id' => (int) $row->id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );

                if ( false !== $updated ) {
                    $total++;
                    $batch_updated++;
                }
            }

            // If no rows were updated in this batch, stop to avoid infinite loop.
            if ( $batch_updated === 0 ) {
                break;
            }

            // If we got fewer rows than the batch size, no more work remains.
            if ( count( $rows ) < $batch_size ) {
                break;
            }
        }

        return $total;
    }

    /**
     * Return backfill progress statistics.
     *
     * @return array {
     *     @type int $total       Total punch rows.
     *     @type int $backfilled  Rows with punch_time_utc populated.
     *     @type int $pending     Rows still needing backfill.
     *     @type float $pct       Completion percentage (0–100, 2 decimal places).
     * }
     */
    public static function get_backfill_stats(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_attendance_punches';

        $total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $backfilled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE punch_time_utc IS NOT NULL" );
        $pending    = $total - $backfilled;
        $pct        = $total > 0 ? round( ( $backfilled / $total ) * 100, 2 ) : 100.0;

        return compact( 'total', 'backfilled', 'pending', 'pct' );
    }

    // ── Duration calculation ──────────────────────────────────────────────────

    /**
     * Calculate session duration in minutes using UTC times when available.
     *
     * Falls back to local in_time / out_time if UTC columns are not populated.
     * Returns null if the session cannot be found or has no out time.
     *
     * @param int $session_id  Row ID in sfs_hr_attendance_sessions.
     * @return int|null Duration in minutes, or null on failure.
     */
    public static function calculate_duration_utc( int $session_id ): ?int {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_attendance_sessions';

        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT in_time, out_time, in_time_utc, out_time_utc
             FROM {$table}
             WHERE id = %d
             LIMIT 1",
            $session_id
        ) );

        if ( ! $session ) {
            return null;
        }

        // Prefer UTC columns (accurate across DST transitions).
        $in_str  = ! empty( $session->in_time_utc )  ? $session->in_time_utc  : $session->in_time;
        $out_str = ! empty( $session->out_time_utc ) ? $session->out_time_utc : $session->out_time;

        if ( empty( $in_str ) || empty( $out_str ) ) {
            return null;
        }

        try {
            $in  = new \DateTime( $in_str,  new \DateTimeZone( 'UTC' ) );
            $out = new \DateTime( $out_str, new \DateTimeZone( 'UTC' ) );

            $diff_seconds = $out->getTimestamp() - $in->getTimestamp();
            if ( $diff_seconds <= 0 ) {
                return null;
            }

            return (int) floor( $diff_seconds / 60 );
        } catch ( \Exception $e ) {
            return null;
        }
    }

    // ── Display formatting ────────────────────────────────────────────────────

    /**
     * Format a UTC datetime for display in the employee's local timezone.
     *
     * @param string $utc_time   UTC datetime 'Y-m-d H:i:s'.
     * @param string $tz_offset  Target offset e.g. '+03:00'.
     * @param string $format     PHP date format string (default 'Y-m-d H:i:s').
     * @return string Formatted local time, or empty string on failure.
     */
    public static function format_for_display( string $utc_time, string $tz_offset, string $format = 'Y-m-d H:i:s' ): string {
        if ( ! self::is_valid_offset( $tz_offset ) ) {
            $tz_offset = self::DEFAULT_OFFSET;
        }

        try {
            $dt = new \DateTime( $utc_time, new \DateTimeZone( 'UTC' ) );
            $dt->setTimezone( new \DateTimeZone( $tz_offset ) );
            return $dt->format( $format );
        } catch ( \Exception $e ) {
            return '';
        }
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Validate a timezone offset string.
     *
     * Accepts formats: +03:00, -05:30, +00:00, -00:00, Z (treated as +00:00).
     *
     * @param string $offset
     * @return bool
     */
    public static function is_valid_offset( string $offset ): bool {
        if ( 'Z' === $offset ) {
            return true;
        }
        // Pattern: [+-]HH:MM where HH 00-14, MM 00|15|30|45
        return (bool) preg_match(
            '/^[+\-](?:0\d|1[0-4]):[0-5]\d$/',
            $offset
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Derive the current server/WordPress UTC offset as a '+HH:MM' string.
     *
     * Uses wp_timezone() when available (WP 5.3+), falling back to
     * timezone_string and gmt_offset options, and finally the DEFAULT_OFFSET.
     *
     * @return string e.g. '+03:00'
     */
    public static function get_server_tz_offset(): string {
        // wp_timezone() available since WP 5.3
        if ( function_exists( 'wp_timezone' ) ) {
            try {
                $tz     = wp_timezone();
                $now    = new \DateTime( 'now', $tz );
                $offset = self::seconds_to_offset_string( $now->getOffset() );
                if ( $offset ) {
                    return $offset;
                }
            } catch ( \Exception $e ) {
                // fall through
            }
        }

        // Fallback: timezone_string option
        $tz_string = get_option( 'timezone_string', '' );
        if ( $tz_string ) {
            $offset = self::tz_name_to_offset( $tz_string );
            if ( $offset ) {
                return $offset;
            }
        }

        // Fallback: gmt_offset option (float hours)
        $gmt_offset = get_option( 'gmt_offset', null );
        if ( null !== $gmt_offset && '' !== $gmt_offset ) {
            $offset = self::float_offset_to_string( (float) $gmt_offset );
            if ( $offset ) {
                return $offset;
            }
        }

        return self::DEFAULT_OFFSET;
    }

    /**
     * Convert a PHP timezone name (e.g. 'Asia/Riyadh') or a numeric offset
     * string (e.g. '+03:00') to a validated offset string.
     *
     * @param string $tz_name
     * @return string|null Offset string, or null on failure.
     */
    private static function tz_name_to_offset( string $tz_name ): ?string {
        // Already an offset string?
        if ( self::is_valid_offset( $tz_name ) ) {
            return $tz_name;
        }

        try {
            $tz     = new \DateTimeZone( $tz_name );
            $now    = new \DateTime( 'now', $tz );
            $offset = self::seconds_to_offset_string( $now->getOffset() );
            return $offset ?: null;
        } catch ( \Exception $e ) {
            return null;
        }
    }

    /**
     * Convert an integer seconds-from-UTC offset to '+HH:MM' format.
     *
     * @param int $seconds  e.g. 10800 for UTC+3.
     * @return string|null '+03:00', or null on overflow/invalid.
     */
    private static function seconds_to_offset_string( int $seconds ): ?string {
        $sign     = $seconds < 0 ? '-' : '+';
        $abs      = abs( $seconds );
        $hours    = (int) floor( $abs / 3600 );
        $minutes  = (int) ( ( $abs % 3600 ) / 60 );

        if ( $hours > 14 ) {
            return null;
        }

        return sprintf( '%s%02d:%02d', $sign, $hours, $minutes );
    }

    /**
     * Convert a floating-point hours offset (as stored in WP gmt_offset
     * option, e.g. 3 or -5.5) to a '+HH:MM' string.
     *
     * @param float $hours  e.g. 3.0, -5.5, 5.75 (for IST +05:45).
     * @return string|null Offset string, or null on invalid input.
     */
    private static function float_offset_to_string( float $hours ): ?string {
        $seconds = (int) round( $hours * 3600 );
        return self::seconds_to_offset_string( $seconds );
    }
}
