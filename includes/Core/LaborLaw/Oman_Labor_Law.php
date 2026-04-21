<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Oman_Labor_Law
 *
 * Oman Labour Law (Royal Decree 35/2003 as amended, and 53/2023).
 *
 * - Gratuity: 15 days basic salary/year for first 3 years;
 *             1 month basic salary/year thereafter.
 *   No statutory reduction on resignation — amount is payable in full
 *   once minimum service threshold is met.
 * - Annual leave: 30 days after 6 months of continuous service.
 *   Pro-rated during first 6 months.
 * - Sick leave: 10 weeks (70 days) — first 2 weeks full pay,
 *   next 2 weeks three-quarter pay, next 2 weeks half pay, last 4 weeks unpaid.
 * - Notice: probation 7 days, permanent 30 days, contract as per contract (≥ 30 days).
 *
 * @since M8
 */
class Oman_Labor_Law extends Base_Labor_Law {

    public function country_code(): string {
        return 'OM';
    }

    public function country_name(): string {
        return __( 'Oman', 'sfs-hr' );
    }

    public function calculate_gratuity_base( float $basic_salary, float $years_of_service ): float {
        if ( $years_of_service <= 0 || $basic_salary <= 0 ) {
            return 0.0;
        }

        $daily_rate = $this->daily_rate( $basic_salary );

        if ( $years_of_service <= 3 ) {
            return $this->money( $daily_rate * 15 * $years_of_service );
        }

        $first_3 = $daily_rate * 15 * 3;
        $after_3 = $basic_salary * ( $years_of_service - 3 );
        return $this->money( $first_3 + $after_3 );
    }

    public function calculate_gratuity_with_trigger( float $basic_salary, float $years_of_service, string $trigger_type ): float {
        // Oman: no statutory reduction on resignation.
        return $this->calculate_gratuity_base( $basic_salary, $years_of_service );
    }

    public function annual_leave_days( float $tenure_years ): int {
        if ( $tenure_years < 0.5 ) {
            return (int) floor( $tenure_years * 60 ); // pro-rata: 30/0.5 = 60 factor → days per year
        }
        return 30;
    }

    public function sick_leave_bands(): array {
        // Oman: 2 weeks full, 2 weeks 75%, 2 weeks 50%, 4 weeks unpaid.
        return [
            [ 'days' => 14, 'pay_percentage' => 100.0 ],
            [ 'days' => 14, 'pay_percentage' => 75.0 ],
            [ 'days' => 14, 'pay_percentage' => 50.0 ],
            [ 'days' => 28, 'pay_percentage' => 0.0 ],
        ];
    }

    public function notice_period_days( string $employment_type ): int {
        switch ( $employment_type ) {
            case 'probation':
                return 7;
            case 'contract':
            case 'permanent':
            default:
                return 30;
        }
    }

    public function probation_period_days(): int {
        return 90;
    }

    public function gratuity_formula_description(): string {
        return __( 'Oman: 15 days per year for first 3 years, 1 month per year thereafter (Royal Decree 35/2003, 53/2023).', 'sfs-hr' );
    }
}
