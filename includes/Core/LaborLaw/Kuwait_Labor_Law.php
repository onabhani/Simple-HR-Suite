<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Kuwait_Labor_Law
 *
 * Kuwait Labour Law (Law No. 6/2010) for the private sector.
 *
 * - Gratuity:
 *     15 days per year for the first 5 years
 *     1 month per year thereafter
 *     Total cap: 1.5 years of salary
 * - Resignation reductions (Art. 51):
 *     < 3 years  = 0
 *     3–5 years  = 1/2 of full
 *     5–10 years = 2/3 of full
 *     10+ years  = full
 * - Annual leave: 30 days after 9 months of continuous service.
 * - Sick leave: 75 days/year — 15 full, 10 three-quarter, 10 half,
 *   10 quarter, 30 unpaid.
 * - Notice: probation 0, permanent 90 days (monthly-paid), 30 days others.
 *
 * @since M8
 */
class Kuwait_Labor_Law extends Base_Labor_Law {

    /** Total gratuity cap: 1.5 years salary. */
    const GRATUITY_CAP_YEARS = 1.5;

    public function country_code(): string {
        return 'KW';
    }

    public function country_name(): string {
        return __( 'Kuwait', 'sfs-hr' );
    }

    public function calculate_gratuity_base( float $basic_salary, float $years_of_service ): float {
        if ( $years_of_service <= 0 || $basic_salary <= 0 ) {
            return 0.0;
        }

        $daily_rate = $this->daily_rate( $basic_salary );

        if ( $years_of_service <= 5 ) {
            $amount = $daily_rate * 15 * $years_of_service;
        } else {
            $first_5 = $daily_rate * 15 * 5;
            $after_5 = $basic_salary * ( $years_of_service - 5 );
            $amount  = $first_5 + $after_5;
        }

        // Cap: ≤ 1.5 years basic salary.
        $cap = $basic_salary * 12 * self::GRATUITY_CAP_YEARS;
        if ( $amount > $cap ) {
            $amount = $cap;
        }

        return $this->money( $amount );
    }

    public function calculate_gratuity_with_trigger( float $basic_salary, float $years_of_service, string $trigger_type ): float {
        $full = $this->calculate_gratuity_base( $basic_salary, $years_of_service );

        if ( 'resignation' === $trigger_type ) {
            if ( $years_of_service < 3 ) {
                return 0.0;
            }
            if ( $years_of_service < 5 ) {
                return $this->money( $full / 2 );
            }
            if ( $years_of_service < 10 ) {
                return $this->money( $full * 2 / 3 );
            }
            return $full;
        }

        return $full;
    }

    public function annual_leave_days( float $tenure_years ): int {
        // Eligible after 9 months (0.75 years).
        if ( $tenure_years < 0.75 ) {
            return 0;
        }
        return 30;
    }

    public function sick_leave_bands(): array {
        // Kuwait Art. 69: 15 full, 10 three-quarter, 10 half, 10 quarter, 30 unpaid.
        return [
            [ 'days' => 15, 'pay_percentage' => 100.0 ],
            [ 'days' => 10, 'pay_percentage' => 75.0 ],
            [ 'days' => 10, 'pay_percentage' => 50.0 ],
            [ 'days' => 10, 'pay_percentage' => 25.0 ],
            [ 'days' => 30, 'pay_percentage' => 0.0 ],
        ];
    }

    public function notice_period_days( string $employment_type ): int {
        switch ( $employment_type ) {
            case 'probation':
                return 0;
            case 'permanent':
                return 90; // Monthly-paid employees — 3 months.
            case 'contract':
            default:
                return 30;
        }
    }

    public function probation_period_days(): int {
        return 100; // Up to 100 days under Kuwait law.
    }

    public function gratuity_formula_description(): string {
        return __( 'Kuwait: 15 days per year for first 5 years, 1 month per year thereafter, capped at 1.5 years salary. Resignation reductions apply under Art. 51.', 'sfs-hr' );
    }
}
