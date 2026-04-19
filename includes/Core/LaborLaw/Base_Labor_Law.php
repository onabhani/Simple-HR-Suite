<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Base_Labor_Law
 *
 * Abstract base providing common helpers (daily-rate calculation,
 * rounding, override merging). Country classes extend this.
 *
 * @since M8
 */
abstract class Base_Labor_Law implements Labor_Law_Strategy {

    /**
     * Standard calendar days per month used to derive daily rate.
     * Gulf labor laws uniformly use 30 for gratuity/leave encashment.
     */
    const DAYS_PER_MONTH = 30;

    /**
     * Compute daily rate from monthly basic salary.
     */
    protected function daily_rate( float $basic_salary ): float {
        if ( $basic_salary <= 0 ) {
            return 0.0;
        }
        return $basic_salary / self::DAYS_PER_MONTH;
    }

    /**
     * Round to 2 decimal places (local currency subunit).
     */
    protected function money( float $amount ): float {
        return round( $amount, 2 );
    }

    /**
     * Default weekend: Friday + Saturday (Gulf standard since 2013).
     * Countries that differ (e.g. historical Saudi Thursday–Friday)
     * should override.
     *
     * 0=Sun, 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat.
     */
    public function weekend_days(): array {
        return [ 5, 6 ];
    }

    /**
     * Default probation: 90 days.
     */
    public function probation_period_days(): int {
        return 90;
    }

    /**
     * Fetch per-country rule overrides stored in the country_rules table,
     * if any, and merge them with the strategy's built-in defaults.
     *
     * @param string $rule_type e.g. 'gratuity', 'annual_leave', 'notice'.
     * @return array Decoded config_json, or empty array when no override.
     */
    protected function load_override( string $rule_type ): array {
        global $wpdb;
        static $cache = [];

        $key = $this->country_code() . ':' . $rule_type;
        if ( isset( $cache[ $key ] ) ) {
            return $cache[ $key ];
        }

        $table = $wpdb->prefix . 'sfs_hr_country_rules';

        // Table may not exist yet on fresh installs (version-gated migration).
        // Use SHOW TABLES to avoid warnings.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) {
            return $cache[ $key ] = [];
        }

        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_json FROM {$table}
             WHERE country_code = %s AND rule_type = %s AND is_active = 1
             LIMIT 1",
            $this->country_code(),
            $rule_type
        ) );

        if ( ! $row ) {
            return $cache[ $key ] = [];
        }

        $decoded = json_decode( (string) $row, true );
        return $cache[ $key ] = is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Default sick leave bands — can be overridden per country.
     * Saudi Arabia default: Article 117 — 30 full / 60 three-quarter / 30 unpaid.
     */
    public function sick_leave_bands(): array {
        return [
            [ 'days' => 30, 'pay_percentage' => 100.0 ],
            [ 'days' => 60, 'pay_percentage' => 75.0 ],
            [ 'days' => 30, 'pay_percentage' => 0.0 ],
        ];
    }

    /**
     * Default gratuity formula description.
     */
    public function gratuity_formula_description(): string {
        return __( 'Country-specific gratuity formula per local labor law.', 'sfs-hr' );
    }
}
