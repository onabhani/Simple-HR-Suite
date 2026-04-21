<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * UAE_Labor_Law
 *
 * UAE Federal Decree-Law No. 33 of 2021 (Labour Relations).
 *
 * - Gratuity: 21 days basic/year for first 5 years; 30 days basic/year after.
 *   No partial gratuity reduction on resignation under the current law.
 *   Cap: total gratuity ≤ 2 years basic salary (Article 51).
 * - Annual leave: 2 days/month during first year (up to 24 days);
 *   30 calendar days after 1 full year.
 * - Sick leave: 90 days total per year (15 full pay / 30 half pay / 45 unpaid).
 * - Notice: probation 14 days, permanent/contract 30 days (minimum; up to 90 by agreement).
 * - Workweek: Mon–Fri (weekend Sat+Sun since 2022 federal change).
 *
 * @since M8
 */
class UAE_Labor_Law extends Base_Labor_Law {

    /** Gratuity cap: total ≤ 2 years basic salary. */
    const GRATUITY_CAP_YEARS = 2.0;

    public function country_code(): string {
        return 'AE';
    }

    public function country_name(): string {
        return __( 'United Arab Emirates', 'sfs-hr' );
    }

    public function calculate_gratuity_base( float $basic_salary, float $years_of_service ): float {
        if ( $years_of_service <= 0 || $basic_salary <= 0 ) {
            return 0.0;
        }

        $daily_rate = $this->daily_rate( $basic_salary );

        if ( $years_of_service <= 5 ) {
            $amount = $daily_rate * 21 * $years_of_service;
        } else {
            $first_5 = $daily_rate * 21 * 5;
            $after_5 = $daily_rate * 30 * ( $years_of_service - 5 );
            $amount  = $first_5 + $after_5;
        }

        // Cap total gratuity at 2 years of basic salary (Article 51).
        $cap = $basic_salary * 12 * self::GRATUITY_CAP_YEARS;
        if ( $amount > $cap ) {
            $amount = $cap;
        }

        return $this->money( $amount );
    }

    public function calculate_gratuity_with_trigger( float $basic_salary, float $years_of_service, string $trigger_type ): float {
        // Under 2021 law: no gratuity if service < 1 year, full amount otherwise.
        if ( $years_of_service < 1 ) {
            return 0.0;
        }
        return $this->calculate_gratuity_base( $basic_salary, $years_of_service );
    }

    public function annual_leave_days( float $tenure_years ): int {
        if ( $tenure_years < 1 ) {
            // 2 days per full month during first year (rounded down).
            $months = (int) floor( $tenure_years * 12 );
            return min( 24, max( 0, $months * 2 ) );
        }
        return 30;
    }

    public function sick_leave_bands(): array {
        // Article 31: after probation, up to 90 days/year:
        // First 15 full, next 30 half, next 45 unpaid.
        return [
            [ 'days' => 15, 'pay_percentage' => 100.0 ],
            [ 'days' => 30, 'pay_percentage' => 50.0 ],
            [ 'days' => 45, 'pay_percentage' => 0.0 ],
        ];
    }

    public function notice_period_days( string $employment_type ): int {
        switch ( $employment_type ) {
            case 'probation':
                return 14;
            case 'contract':
            case 'permanent':
            default:
                return 30;
        }
    }

    public function probation_period_days(): int {
        return 180; // Up to 6 months under UAE law.
    }

    /** UAE weekend: Saturday + Sunday (federal change effective 2022). */
    public function weekend_days(): array {
        return [ 0, 6 ]; // Sun + Sat
    }

    public function gratuity_formula_description(): string {
        return __( 'UAE: 21 days of basic salary per year for the first 5 years, 30 days per year thereafter, capped at 2 years of basic salary (Federal Decree-Law 33/2021, Art. 51).', 'sfs-hr' );
    }
}
