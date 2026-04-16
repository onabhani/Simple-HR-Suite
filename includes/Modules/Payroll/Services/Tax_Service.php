<?php
namespace SFS\HR\Modules\Payroll\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Tax_Service
 *
 * Computes statutory income-tax and GOSI deductions for a single payslip.
 *
 * Design goals:
 *   - All rules are CONFIGURABLE through the admin UI (tax brackets table
 *     + sfs_hr_tax_settings / sfs_hr_statutory_settings options). Nothing
 *     below hardcodes country law.
 *   - SAFE by default. When tax is disabled or brackets are missing, every
 *     computation returns 0 rather than throwing — payroll must not fail.
 *   - DETERMINISTIC. Given the same inputs (employee row, gross, exemptions,
 *     brackets), the output is reproducible. No external I/O beyond DB reads.
 *   - CHEAP. Bracket sets are cached per (country, year) per request via a
 *     static memoization cache.
 *
 * Bracket algebra (pure progressive with optional per-bracket surcharge):
 *   For a taxable amount `t` and an ordered bracket list, compute:
 *     progressive_tax = Σ (min(t, bracket_to ?? ∞) - bracket_from) * rate%
 *       (over every bracket whose `bracket_from` < t)
 *     surcharge       = flat_base of the bracket that actually contains `t`
 *     total_tax       = progressive_tax + surcharge
 *
 *   For a pure progressive schedule set `flat_base = 0` on every row. The
 *   optional surcharge supports policies that impose a fixed minimum within
 *   a bracket in addition to the marginal rate (e.g. "top bracket: 20% on
 *   excess above 300k PLUS a 5k flat levy").
 */
class Tax_Service {

    /**
     * @var array<string,array<int,array{bracket_from:float,bracket_to:?float,rate:float,flat_base:float}>>
     *      Key = "{country}|{year}".
     */
    private static array $brackets_cache = [];

    /**
     * Compute GOSI deduction for a single payslip.
     *
     * @param object $employee Employee row (nationality, status, ...).
     * @param float  $base_salary     Monthly base salary.
     * @param float  $housing_amount  Monthly housing allowance (commonly part of GOSI base).
     * @param array<string,mixed> $settings Optional override; defaults to sfs_hr_statutory_settings.
     *
     * @return float Monthly GOSI deduction (employee share), 0 if not applicable.
     */
    public static function calculate_gosi(
        object $employee,
        float $base_salary,
        float $housing_amount = 0.0,
        ?array $settings = null
    ): float {
        $settings = $settings ?? self::get_statutory_settings();

        if ( empty( $settings['gosi_enabled'] ) ) {
            return 0.0;
        }

        $applies_to = (string) ( $settings['gosi_applies_to'] ?? 'saudi_only' );
        $is_saudi   = self::employee_is_saudi( $employee );

        $rate = 0.0;
        switch ( $applies_to ) {
            case 'none':
                return 0.0;
            case 'all':
                $rate = $is_saudi
                    ? (float) ( $settings['gosi_employee_rate'] ?? 9.75 )
                    : (float) ( $settings['gosi_foreign_rate']  ?? 2.00 );
                break;
            case 'saudi_only':
            default:
                if ( ! $is_saudi ) {
                    return 0.0;
                }
                $rate = (float) ( $settings['gosi_employee_rate'] ?? 9.75 );
                break;
        }

        $gosi_base = self::compute_gosi_base( $base_salary, $housing_amount, $settings );
        $ceiling   = (float) ( $settings['gosi_ceiling'] ?? 0 );
        if ( $ceiling > 0 && $gosi_base > $ceiling ) {
            $gosi_base = $ceiling;
        }

        return round( $gosi_base * ( $rate / 100.0 ), 2 );
    }

    /**
     * Compute income tax deduction for a single payslip.
     *
     * Annualizes the period's taxable income, subtracts annual exemptions,
     * applies the bracket schedule, then returns the per-period share. This
     * mirrors how most jurisdictions withhold monthly tax.
     *
     * @param object $employee Employee row.
     * @param float  $gross_salary_period Taxable gross for this payroll period.
     * @param string $period_end_date YYYY-MM-DD — selects bracket year.
     * @param array<string,mixed> $settings Optional; defaults to sfs_hr_tax_settings.
     *
     * @return float Per-period tax deduction, 0 if tax is disabled / no brackets.
     */
    public static function calculate_income_tax(
        object $employee,
        float $gross_salary_period,
        string $period_end_date,
        ?array $settings = null
    ): float {
        $settings = $settings ?? self::get_tax_settings();

        if ( empty( $settings['tax_enabled'] ) ) {
            return 0.0;
        }

        if ( ! self::employee_is_taxable( $employee, $settings ) ) {
            return 0.0;
        }

        $periods_per_year = max( 1, (int) ( $settings['period_annualize'] ?? 12 ) );
        $country          = (string) ( $settings['tax_country'] ?? 'SA' );
        $year             = self::resolve_year( $period_end_date, $settings );

        $brackets = self::load_brackets( $country, $year );
        if ( empty( $brackets ) ) {
            return 0.0;
        }

        $annual_gross      = $gross_salary_period * $periods_per_year;
        $annual_exemptions = self::sum_exemptions( (int) ( $employee->id ?? 0 ), $period_end_date );
        $annual_taxable    = max( 0.0, $annual_gross - $annual_exemptions );

        $annual_tax = self::apply_brackets( $annual_taxable, $brackets );
        return round( $annual_tax / $periods_per_year, 2 );
    }

    /**
     * Apply a bracket schedule to an annual taxable amount.
     *
     * @param array<int,array{bracket_from:float,bracket_to:?float,rate:float,flat_base:float}> $brackets
     */
    public static function apply_brackets( float $annual_taxable, array $brackets ): float {
        if ( $annual_taxable <= 0 || empty( $brackets ) ) {
            return 0.0;
        }

        $tax  = 0.0;
        $flat = 0.0;

        foreach ( $brackets as $b ) {
            $from = (float) $b['bracket_from'];
            $to   = $b['bracket_to'] === null ? null : (float) $b['bracket_to'];

            // Use strict less-than: when $annual_taxable == $from the income
            // is inside THIS bracket (ranges are [from, to) — from inclusive,
            // to exclusive), so we must still run the containment check below
            // to capture the bracket's flat_base surcharge.
            if ( $annual_taxable < $from ) {
                break;
            }

            $top = $to === null ? $annual_taxable : min( $annual_taxable, $to );
            if ( $top > $from ) {
                $tax += ( $top - $from ) * ( (float) $b['rate'] / 100.0 );
            }

            // The flat_base of the bracket that actually contains the income.
            if ( $annual_taxable >= $from && ( $to === null || $annual_taxable < $to ) ) {
                $flat = (float) $b['flat_base'];
            }
        }

        return max( 0.0, $tax + $flat );
    }

    /**
     * Sum active annual exemptions for an employee as of a given date.
     */
    public static function sum_exemptions( int $employee_id, string $as_of_date ): float {
        global $wpdb;

        if ( $employee_id <= 0 ) {
            return 0.0;
        }

        $table = $wpdb->prefix . 'sfs_hr_tax_exemptions';
        if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) ) {
            return 0.0;
        }

        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(annual_amount), 0)
             FROM {$table}
             WHERE employee_id = %d
               AND effective_from <= %s
               AND ( effective_to IS NULL OR effective_to >= %s )",
            $employee_id,
            $as_of_date,
            $as_of_date
        ) );

        return (float) $total;
    }

    /**
     * Load bracket rows for a (country, year) tuple, ordered by bracket_from ASC.
     * Memoized per request.
     *
     * @return array<int,array{bracket_from:float,bracket_to:?float,rate:float,flat_base:float}>
     */
    public static function load_brackets( string $country, int $year ): array {
        $key = strtoupper( $country ) . '|' . $year;
        if ( isset( self::$brackets_cache[ $key ] ) ) {
            return self::$brackets_cache[ $key ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_tax_brackets';
        if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) ) {
            return self::$brackets_cache[ $key ] = [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT bracket_from, bracket_to, rate_percent, flat_base
             FROM {$table}
             WHERE country_code = %s AND tax_year = %d AND is_active = 1
             ORDER BY bracket_from ASC",
            $country,
            $year
        ), ARRAY_A );

        $normalized = [];
        foreach ( (array) $rows as $row ) {
            $normalized[] = [
                'bracket_from' => (float) $row['bracket_from'],
                'bracket_to'   => $row['bracket_to'] === null ? null : (float) $row['bracket_to'],
                'rate'         => (float) $row['rate_percent'],
                'flat_base'    => (float) $row['flat_base'],
            ];
        }

        return self::$brackets_cache[ $key ] = $normalized;
    }

    /**
     * Clear the in-memory brackets cache. Call after writes to the table
     * (e.g. from the admin-save handler) so subsequent reads see fresh data.
     */
    public static function flush_cache(): void {
        self::$brackets_cache = [];
    }

    /* ---------- Internal helpers ---------- */

    private static function get_statutory_settings(): array {
        $opt = get_option( 'sfs_hr_statutory_settings', [] );
        return is_array( $opt ) ? $opt : [];
    }

    private static function get_tax_settings(): array {
        $opt = get_option( 'sfs_hr_tax_settings', [] );
        return is_array( $opt ) ? $opt : [];
    }

    /**
     * Is the employee a Saudi national? Accepts any common spelling.
     */
    private static function employee_is_saudi( object $employee ): bool {
        $n = strtolower( (string) ( $employee->nationality ?? '' ) );
        if ( $n === '' ) {
            return false;
        }
        return in_array( $n, [ 'sa', 'saudi', 'saudi arabian', 'ksa', 'سعودي', 'saudi_arabia' ], true );
    }

    /**
     * Does the tax regime apply to this employee?
     */
    private static function employee_is_taxable( object $employee, array $settings ): bool {
        $scope    = (string) ( $settings['tax_applies_to'] ?? 'all' );
        $is_saudi = self::employee_is_saudi( $employee );

        switch ( $scope ) {
            case 'saudi_only':
                return $is_saudi;
            case 'foreign_only':
                return ! $is_saudi;
            case 'all':
            default:
                return true;
        }
    }

    /**
     * Aggregate the configured components that form the GOSI base.
     *
     * @param string|array<string,mixed> $settings
     */
    private static function compute_gosi_base( float $base_salary, float $housing_amount, array $settings ): float {
        $base_includes = (string) ( $settings['gosi_base_includes'] ?? 'base_salary,housing' );
        $parts = array_filter( array_map( 'trim', explode( ',', strtolower( $base_includes ) ) ) );

        $total = 0.0;
        if ( in_array( 'base_salary', $parts, true ) || in_array( 'base', $parts, true ) ) {
            $total += $base_salary;
        }
        if ( in_array( 'housing', $parts, true ) ) {
            $total += $housing_amount;
        }
        // If neither keyword matched (unusual config) fall back to base salary
        // so GOSI never accidentally computes against zero.
        if ( $total === 0.0 ) {
            $total = $base_salary;
        }
        return $total;
    }

    /**
     * Resolve the tax year for a period end date. Honours the configured
     * override in settings; falls back to the calendar year of the date.
     */
    private static function resolve_year( string $period_end_date, array $settings ): int {
        $configured = (int) ( $settings['tax_year'] ?? 0 );
        if ( $configured > 0 ) {
            return $configured;
        }
        $ts = strtotime( $period_end_date );
        return $ts ? (int) wp_date( 'Y', $ts ) : (int) wp_date( 'Y' );
    }
}
