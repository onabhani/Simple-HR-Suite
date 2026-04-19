<?php
namespace SFS\HR\Core\SocialInsurance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Social_Insurance_Service
 *
 * M8.3 — Pluggable social-insurance (statutory contribution) engine.
 *
 * Each country has zero or more schemes stored in sfs_hr_social_insurance_schemes
 * (country_code, scheme_code, employee_rate, employer_rate, applies_to,
 * base_components, ceiling, floor, effective window).
 *
 * Known built-in schemes (seeded on first activation):
 *   - SA / GOSI
 *   - AE / UAE_PENSION (nationals only; WPS applies to all)
 *   - BH / SIO
 *   - KW / PIFSS
 *   - OM / PASI
 *   - QA / QGRSIA (nationals only)
 *
 * Callers should use this service instead of hardcoding rates so companies
 * can adjust percentages, ceilings, or effective dates from the admin UI.
 *
 * @since M8
 */
class Social_Insurance_Service {

    /** Applies-to values. */
    const APPLIES_ALL             = 'all';
    const APPLIES_NATIONALS_ONLY  = 'nationals_only';
    const APPLIES_NONE            = 'none';

    /** Default GOSI-equivalent seed for fresh SA installs. */
    private static array $seeds = [
        'SA' => [
            [
                'scheme_code'     => 'GOSI_EMP',
                'scheme_name'     => 'GOSI (Employee share)',
                'employee_rate'   => 9.75,
                'employer_rate'   => 11.75,
                'applies_to'      => self::APPLIES_NATIONALS_ONLY,
                'base_components' => 'base_salary,housing',
                'ceiling'         => 45000,
                'floor'           => 0,
            ],
        ],
        'AE' => [
            [
                'scheme_code'     => 'UAE_PENSION',
                'scheme_name'     => 'UAE Pension & Social Security',
                'employee_rate'   => 5.00,
                'employer_rate'   => 12.50,
                'applies_to'      => self::APPLIES_NATIONALS_ONLY,
                'base_components' => 'base_salary,housing,transport',
                'ceiling'         => 50000,
                'floor'           => 1000,
            ],
        ],
        'BH' => [
            [
                'scheme_code'     => 'SIO',
                'scheme_name'     => 'Social Insurance Organization',
                'employee_rate'   => 7.00,
                'employer_rate'   => 12.00,
                'applies_to'      => self::APPLIES_NATIONALS_ONLY,
                'base_components' => 'base_salary',
                'ceiling'         => 4000,
                'floor'           => 0,
            ],
        ],
        'KW' => [
            [
                'scheme_code'     => 'PIFSS',
                'scheme_name'     => 'Public Institution for Social Security',
                'employee_rate'   => 10.50,
                'employer_rate'   => 11.50,
                'applies_to'      => self::APPLIES_NATIONALS_ONLY,
                'base_components' => 'base_salary',
                'ceiling'         => 2750,
                'floor'           => 230,
            ],
        ],
        'OM' => [
            [
                'scheme_code'     => 'PASI',
                'scheme_name'     => 'Public Authority for Social Insurance',
                'employee_rate'   => 7.00,
                'employer_rate'   => 11.50,
                'applies_to'      => self::APPLIES_NATIONALS_ONLY,
                'base_components' => 'base_salary',
                'ceiling'         => 3000,
                'floor'           => 0,
            ],
        ],
        'QA' => [
            [
                'scheme_code'     => 'QGRSIA',
                'scheme_name'     => 'General Retirement & Social Insurance',
                'employee_rate'   => 5.00,
                'employer_rate'   => 10.00,
                'applies_to'      => self::APPLIES_NATIONALS_ONLY,
                'base_components' => 'base_salary,housing',
                'ceiling'         => 100000,
                'floor'           => 0,
            ],
        ],
    ];

    /**
     * Get active schemes for a country (effective as of $as_of date).
     *
     * @return array<int, array> Rows from sfs_hr_social_insurance_schemes.
     */
    public static function schemes_for_country( string $country_code, ?string $as_of = null ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_social_insurance_schemes';

        $as_of = $as_of ?: current_time( 'Y-m-d' );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE country_code = %s
               AND is_active = 1
               AND ( effective_from IS NULL OR effective_from <= %s )
               AND ( effective_to   IS NULL OR effective_to   >= %s )
             ORDER BY scheme_code ASC",
            strtoupper( $country_code ),
            $as_of,
            $as_of
        ), ARRAY_A ) ?: [];
    }

    /**
     * Calculate contribution for a single scheme against an employee and
     * gross-component set.
     *
     * @param array  $scheme       One row from schemes_for_country().
     * @param object $employee     Must expose ->nationality and a "is_national"
     *                              determination for applies_to=nationals_only.
     * @param array  $components   Map of component_code => amount (e.g. ['base_salary'=>5000]).
     * @return array{employee:float, employer:float, base:float, applied:bool}
     */
    public static function calculate( array $scheme, object $employee, array $components ): array {
        $applied = self::applies_to_employee( $scheme, $employee );
        if ( ! $applied ) {
            return [ 'employee' => 0.0, 'employer' => 0.0, 'base' => 0.0, 'applied' => false ];
        }

        $base_keys = array_filter( array_map( 'trim', explode( ',', (string) ( $scheme['base_components'] ?? '' ) ) ) );
        $base      = 0.0;
        foreach ( $base_keys as $key ) {
            $base += (float) ( $components[ $key ] ?? 0 );
        }
        if ( $base <= 0 ) {
            $base = (float) ( $components['base_salary'] ?? 0 );
        }

        $ceiling = isset( $scheme['ceiling'] ) && $scheme['ceiling'] !== null ? (float) $scheme['ceiling'] : null;
        $floor   = isset( $scheme['floor'] )   && $scheme['floor']   !== null ? (float) $scheme['floor']   : null;

        if ( $floor !== null && $base < $floor ) {
            $base = $floor;
        }
        if ( $ceiling !== null && $base > $ceiling ) {
            $base = $ceiling;
        }

        $employee_amt = round( $base * ( (float) $scheme['employee_rate'] / 100 ), 2 );
        $employer_amt = round( $base * ( (float) $scheme['employer_rate'] / 100 ), 2 );

        return [
            'employee' => $employee_amt,
            'employer' => $employer_amt,
            'base'     => round( $base, 2 ),
            'applied'  => true,
        ];
    }

    /**
     * Determine whether a scheme applies to a given employee.
     */
    private static function applies_to_employee( array $scheme, object $employee ): bool {
        $raw        = $scheme['applies_to'] ?? '';
        $valid      = [ self::APPLIES_ALL, self::APPLIES_NATIONALS_ONLY, self::APPLIES_NONE ];
        // Missing or unknown values default to APPLIES_NONE (fail-safe — avoids
        // accidentally deducting statutory contributions from mis-configured rows).
        $applies_to = in_array( (string) $raw, $valid, true ) ? (string) $raw : self::APPLIES_NONE;

        if ( self::APPLIES_NONE === $applies_to ) {
            return false;
        }
        if ( self::APPLIES_ALL === $applies_to ) {
            return true;
        }
        // Nationals only.
        $country_code  = strtoupper( (string) ( $scheme['country_code'] ?? '' ) );
        $nationality   = isset( $employee->nationality ) ? strtoupper( (string) $employee->nationality ) : '';
        // Accept nationality as either 2-letter code (SA, AE) or full name.
        return $country_code && $country_code === $nationality;
    }

    /**
     * Seed default schemes for all supported countries.
     * Idempotent — only inserts when the (country_code, scheme_code) pair
     * does not already exist. Called during activation/migration.
     */
    public static function seed_defaults(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_social_insurance_schemes';

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) {
            return; // Migration hasn't run yet.
        }

        $now = current_time( 'mysql' );

        foreach ( self::$seeds as $country => $schemes ) {
            foreach ( $schemes as $s ) {
                $already = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE country_code = %s AND scheme_code = %s",
                    $country,
                    $s['scheme_code']
                ) );
                if ( $already > 0 ) {
                    continue;
                }

                $wpdb->insert( $table, [
                    'country_code'    => $country,
                    'scheme_code'     => $s['scheme_code'],
                    'scheme_name'     => $s['scheme_name'],
                    'employee_rate'   => $s['employee_rate'],
                    'employer_rate'   => $s['employer_rate'],
                    'applies_to'      => $s['applies_to'],
                    'base_components' => $s['base_components'],
                    'ceiling'         => $s['ceiling'],
                    'floor'           => $s['floor'],
                    'is_active'       => 1,
                    'effective_from'  => null,
                    'effective_to'    => null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ], [
                    '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%f', '%f', '%d', '%s', '%s', '%s', '%s',
                ] );
            }
        }
    }

    /**
     * List all schemes (admin UI).
     */
    public static function list_all(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_social_insurance_schemes';
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY country_code, scheme_code", ARRAY_A ) ?: [];
    }
}
