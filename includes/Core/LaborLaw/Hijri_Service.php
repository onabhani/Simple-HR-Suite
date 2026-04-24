<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Hijri_Service
 *
 * M8.4 — Hijri calendar support.
 *
 * Provides Gregorian ↔ Hijri (Umm al-Qura) conversion, Ramadan detection,
 * and Islamic holiday helpers. Uses PHP's IntlDateFormatter with the
 * 'islamic-umalqura' calendar when the Intl extension is available,
 * and falls back to an algorithmic converter otherwise.
 *
 * Example:
 *   Hijri_Service::to_hijri('2026-04-20')  // ['y'=>1447,'m'=>11,'d'=>2]
 *   Hijri_Service::is_ramadan('2026-02-15') // true/false
 *
 * All methods are static and side-effect free.
 *
 * @since M8
 */
class Hijri_Service {

    /** Islamic month names (transliterated). */
    const HIJRI_MONTHS = [
        1  => 'Muharram',
        2  => 'Safar',
        3  => "Rabi' al-Awwal",
        4  => "Rabi' al-Thani",
        5  => 'Jumada al-Awwal',
        6  => 'Jumada al-Thani',
        7  => 'Rajab',
        8  => "Sha'ban",
        9  => 'Ramadan',
        10 => 'Shawwal',
        11 => "Dhu al-Qi'dah",
        12 => 'Dhu al-Hijjah',
    ];

    const MONTH_RAMADAN     = 9;
    const MONTH_SHAWWAL     = 10;
    const MONTH_DHU_HIJJAH  = 12;
    const MONTH_MUHARRAM    = 1;

    const OPT_HIJRI_DISPLAY = 'sfs_hr_hijri_display';

    /** Whether Intl extension with Islamic calendar is available. */
    private static ?bool $intl_available = null;

    /**
     * Convert a Gregorian date (Y-m-d) to Hijri (Umm al-Qura).
     *
     * @param string $gregorian_date Y-m-d or any strtotime-parseable string.
     * @return array{y:int, m:int, d:int} Hijri year, month (1-12), day (1-30).
     */
    public static function to_hijri( string $gregorian_date ): array {
        $ts = strtotime( $gregorian_date );
        if ( $ts === false ) {
            return [ 'y' => 0, 'm' => 0, 'd' => 0 ];
        }

        if ( self::intl_available() ) {
            $fmt = new \IntlDateFormatter(
                'en_US@calendar=islamic-umalqura',
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                'UTC',
                \IntlDateFormatter::TRADITIONAL,
                'yyyy-MM-dd'
            );
            $formatted = $fmt->format( $ts );
            if ( is_string( $formatted ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $formatted, $m ) ) {
                return [ 'y' => (int) $m[1], 'm' => (int) $m[2], 'd' => (int) $m[3] ];
            }
        }

        // Fallback: algorithmic Gregorian → Hijri conversion.
        return self::gregorian_to_hijri_algo( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
    }

    /**
     * Convert a Hijri date to Gregorian (Y-m-d).
     *
     * @param int $y Hijri year.
     * @param int $m Hijri month (1-12).
     * @param int $d Hijri day (1-30).
     * @return string Y-m-d Gregorian, or empty string on failure.
     */
    public static function to_gregorian( int $y, int $m, int $d ): string {
        if ( self::intl_available() ) {
            $cal = \IntlCalendar::createInstance( 'UTC', 'en_US@calendar=islamic-umalqura' );
            $cal->clear();
            $cal->set( $y, $m - 1, $d );
            $ts = (int) ( $cal->getTime() / 1000 );
            return gmdate( 'Y-m-d', $ts );
        }
        return self::hijri_to_gregorian_algo( $y, $m, $d );
    }

    /**
     * Format a Hijri date using month names (transliterated or Arabic).
     *
     * @param string $gregorian_date Source Gregorian date.
     * @param string $format 'full' (e.g. "2 Ramadan 1447") | 'short' (e.g. "1447-09-02").
     * @return string
     */
    public static function format( string $gregorian_date, string $format = 'full' ): string {
        $h = self::to_hijri( $gregorian_date );
        if ( ! $h['y'] ) {
            return '';
        }

        if ( 'short' === $format ) {
            return sprintf( '%04d-%02d-%02d', $h['y'], $h['m'], $h['d'] );
        }

        $month_name = self::HIJRI_MONTHS[ $h['m'] ] ?? '';
        return sprintf( '%d %s %d AH', $h['d'], $month_name, $h['y'] );
    }

    /**
     * Return true when the given Gregorian date falls in Ramadan.
     */
    public static function is_ramadan( string $gregorian_date ): bool {
        $h = self::to_hijri( $gregorian_date );
        return (int) $h['m'] === self::MONTH_RAMADAN;
    }

    /**
     * Find the Gregorian start/end dates of Ramadan for a given Gregorian year.
     * May return approximate bounds when running on the algorithmic fallback.
     *
     * @param int $gregorian_year
     * @return array{start:string, end:string}
     */
    public static function ramadan_range_for_year( int $gregorian_year ): array {
        // Probe mid-year months: Ramadan typically falls Feb–Oct in modern era.
        $start = '';
        $end   = '';
        $prev_ramadan = false;

        $probe_start = strtotime( sprintf( '%d-01-01', $gregorian_year ) );
        $probe_end   = strtotime( sprintf( '%d-12-31', $gregorian_year ) );
        if ( $probe_start === false || $probe_end === false ) {
            return [ 'start' => '', 'end' => '' ];
        }

        for ( $ts = $probe_start; $ts <= $probe_end; $ts += DAY_IN_SECONDS ) {
            $ymd = gmdate( 'Y-m-d', $ts );
            $is  = self::is_ramadan( $ymd );
            if ( $is && ! $prev_ramadan ) {
                $start = $start ?: $ymd; // first occurrence in year
            }
            if ( ! $is && $prev_ramadan ) {
                $end = gmdate( 'Y-m-d', $ts - DAY_IN_SECONDS ); // day before leaving Ramadan
            }
            $prev_ramadan = $is;
        }

        // If year ended mid-Ramadan, close with year-end.
        if ( $start && ! $end ) {
            $end = gmdate( 'Y-m-d', $probe_end );
        }

        return [ 'start' => $start, 'end' => $end ];
    }

    /**
     * Get today's date in Hijri.
     *
     * @return array{y:int, m:int, d:int}
     */
    public static function today(): array {
        return self::to_hijri( current_time( 'Y-m-d' ) );
    }

    /**
     * Return the admin-configured Hijri display settings with defaults.
     *
     * @return array{enabled:bool, show_alongside:bool, ramadan_short_hours:bool}
     */
    public static function get_settings(): array {
        $raw = get_option( self::OPT_HIJRI_DISPLAY, [] );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }
        return [
            'enabled'             => ! empty( $raw['enabled'] ),
            'show_alongside'      => ! isset( $raw['show_alongside'] ) || ! empty( $raw['show_alongside'] ),
            'ramadan_short_hours' => ! isset( $raw['ramadan_short_hours'] ) || ! empty( $raw['ramadan_short_hours'] ),
        ];
    }

    /**
     * Format a Gregorian date honoring the admin Hijri display settings.
     *
     *   - Hijri disabled:          returns the Gregorian date unchanged
     *   - show_alongside = true:   "2026-04-20 (2 Ramadan 1447)"
     *   - show_alongside = false:  "2 Ramadan 1447" (Hijri only)
     *
     * The 'sfs_hr_format_date_with_hijri' filter is available so themes /
     * extensions can override the combined rendering.
     *
     * @param string $gregorian_date Y-m-d (or any strtotime-parseable string).
     * @param string $gregorian_format date() format used for the Gregorian part.
     * @return string
     */
    public static function format_with_gregorian( string $gregorian_date, string $gregorian_format = 'Y-m-d' ): string {
        $ts = strtotime( $gregorian_date );
        if ( $ts === false ) {
            return '';
        }

        $gregorian = wp_date( $gregorian_format, $ts );

        $settings = self::get_settings();
        if ( ! $settings['enabled'] ) {
            $out = $gregorian;
        } else {
            $hijri = self::format( $gregorian_date, 'full' );
            if ( $hijri === '' ) {
                $out = $gregorian;
            } elseif ( $settings['show_alongside'] ) {
                $out = sprintf( '%s (%s)', $gregorian, $hijri );
            } else {
                $out = $hijri;
            }
        }

        /**
         * Filter the combined Gregorian/Hijri string before display.
         *
         * @param string $out             Final formatted string.
         * @param string $gregorian_date  Source Gregorian date.
         * @param array  $settings        Resolved Hijri display settings.
         */
        return (string) apply_filters( 'sfs_hr_format_date_with_hijri', $out, $gregorian_date, $settings );
    }

    /**
     * Compute the major Islamic holidays for a given Gregorian year.
     *
     *   - Islamic New Year  — 1 Muharram
     *   - Eid al-Fitr       — 1-3 Shawwal (3 days)
     *   - Eid al-Adha       — 10-13 Dhu al-Hijjah (4 days, Hajj tied)
     *
     * Dates are Gregorian ISO strings. Uses the configured calendar (Umm
     * al-Qura via Intl when available; arithmetic fallback otherwise).
     *
     * @param int $gregorian_year
     * @return array<string, array{start:string, end:string, hijri:string}>
     */
    public static function islamic_holidays_for_year( int $gregorian_year ): array {
        // A Gregorian year typically spans ~1-2 Hijri years. Probe both
        // Hijri years that could fall within the year, then keep only the
        // holidays whose Gregorian start lands in the target year.
        $year_start = self::to_hijri( sprintf( '%d-01-01', $gregorian_year ) );
        $year_end   = self::to_hijri( sprintf( '%d-12-31', $gregorian_year ) );
        $hijri_years = array_unique( array_filter( [ $year_start['y'], $year_end['y'] ] ) );

        $holidays = [];

        foreach ( $hijri_years as $hy ) {
            // Islamic New Year (1 Muharram).
            $g = self::to_gregorian( $hy, self::MONTH_MUHARRAM, 1 );
            if ( $g && str_starts_with( $g, (string) $gregorian_year ) ) {
                $holidays['islamic_new_year'] = [
                    'start' => $g,
                    'end'   => $g,
                    'hijri' => sprintf( '1 Muharram %d AH', $hy ),
                ];
            }

            // Eid al-Fitr (1-3 Shawwal).
            $gs = self::to_gregorian( $hy, self::MONTH_SHAWWAL, 1 );
            $ge = self::to_gregorian( $hy, self::MONTH_SHAWWAL, 3 );
            if ( $gs && str_starts_with( $gs, (string) $gregorian_year ) ) {
                $holidays['eid_al_fitr'] = [
                    'start' => $gs,
                    'end'   => $ge ?: $gs,
                    'hijri' => sprintf( '1-3 Shawwal %d AH', $hy ),
                ];
            }

            // Eid al-Adha (10-13 Dhu al-Hijjah).
            $gs = self::to_gregorian( $hy, self::MONTH_DHU_HIJJAH, 10 );
            $ge = self::to_gregorian( $hy, self::MONTH_DHU_HIJJAH, 13 );
            if ( $gs && str_starts_with( $gs, (string) $gregorian_year ) ) {
                $holidays['eid_al_adha'] = [
                    'start' => $gs,
                    'end'   => $ge ?: $gs,
                    'hijri' => sprintf( '10-13 Dhu al-Hijjah %d AH', $hy ),
                ];
            }
        }

        return $holidays;
    }

    /**
     * True if a Gregorian date falls on any Islamic holiday.
     *
     * Multi-day holidays (Eid al-Adha in particular) can span a Gregorian
     * year boundary — start in year N, end in year N+1. islamic_holidays_for_year()
     * lists each holiday under the year that contains its start, so a date
     * like 2027-01-01 might belong to a holiday listed under 2026. Merge
     * ±1 year to cover that.
     */
    public static function is_islamic_holiday( string $gregorian_date ): bool {
        $ts = strtotime( $gregorian_date );
        if ( $ts === false ) {
            return false;
        }
        $year = (int) gmdate( 'Y', $ts );
        $ymd  = gmdate( 'Y-m-d', $ts );

        $holidays = array_merge(
            self::islamic_holidays_for_year( $year - 1 ),
            self::islamic_holidays_for_year( $year ),
            self::islamic_holidays_for_year( $year + 1 )
        );

        foreach ( $holidays as $h ) {
            if ( strcmp( $ymd, $h['start'] ) >= 0 && strcmp( $ymd, $h['end'] ) <= 0 ) {
                return true;
            }
        }
        return false;
    }

    /** Does the running PHP have Intl with Islamic calendar support? */
    public static function intl_available(): bool {
        if ( null !== self::$intl_available ) {
            return self::$intl_available;
        }
        self::$intl_available = class_exists( 'IntlDateFormatter' ) && class_exists( 'IntlCalendar' );
        return self::$intl_available;
    }

    // ── Algorithmic fallback (Umm al-Qura approximation via arithmetic calendar) ─

    /**
     * Astronomical-arithmetic Gregorian → Hijri conversion.
     * Accuracy: ±1 day vs. Umm al-Qura. Used only when Intl is unavailable.
     */
    private static function gregorian_to_hijri_algo( int $y, int $m, int $d ): array {
        $jd  = gregoriantojd( $m, $d, $y );
        // Tabular Islamic (arithmetic) calendar epoch: JD 1948440 = 1 Muharram 1 AH (16 Jul 622 CE).
        $l   = $jd - 1948440 + 10632;
        $n   = (int) ( ( $l - 1 ) / 10631 );
        $l   = $l - 10631 * $n + 354;
        $j   = ( (int) ( ( 10985 - $l ) / 5316 ) ) * ( (int) ( 50 * $l / 17719 ) )
             + ( (int) ( $l / 5670 ) ) * ( (int) ( 43 * $l / 15238 ) );
        $l   = $l - ( (int) ( ( 30 - $j ) / 15 ) ) * ( (int) ( ( 17719 * $j ) / 50 ) )
             - ( (int) ( $j / 16 ) ) * ( (int) ( ( 15238 * $j ) / 43 ) )
             + 29;
        $hm  = (int) ( 24 * $l / 709 );
        $hd  = $l - (int) ( 709 * $hm / 24 );
        $hy  = 30 * $n + $j - 30;
        return [ 'y' => $hy, 'm' => $hm, 'd' => $hd ];
    }

    /**
     * Astronomical-arithmetic Hijri → Gregorian conversion.
     */
    private static function hijri_to_gregorian_algo( int $y, int $m, int $d ): string {
        $jd = (int) (
            ( 11 * $y + 3 ) / 30
            + 354 * $y
            + 30 * $m
            - (int) ( ( $m - 1 ) / 2 )
            + $d
            + 1948440 - 385
        );
        $parts = explode( '/', jdtogregorian( $jd ) );
        if ( count( $parts ) !== 3 ) {
            return '';
        }
        [ $mm, $dd, $yy ] = $parts;
        return sprintf( '%04d-%02d-%02d', (int) $yy, (int) $mm, (int) $dd );
    }
}
