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

    const MONTH_RAMADAN = 9;

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
