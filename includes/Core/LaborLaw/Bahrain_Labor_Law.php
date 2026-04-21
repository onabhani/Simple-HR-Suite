<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bahrain_Labor_Law
 *
 * Bahrain Labour Law for Private Sector (Law No. 36/2012).
 *
 * - Gratuity: 15 days/year for first 3 years; 1 month/year thereafter.
 *   No statutory resignation reduction — gratuity is payable regardless of cause
 *   once minimum service threshold is met.
 * - Annual leave: 30 calendar days after 1 year of continuous service
 *   (pro-rated for partial year).
 * - Sick leave: 55 days/year — 15 full, 20 half, 20 unpaid.
 * - Notice: probation 1 day, permanent 30 days, contract 30 days.
 *
 * @since M8
 */
class Bahrain_Labor_Law extends Base_Labor_Law {

    public function country_code(): string {
        return 'BH';
    }

    public function country_name(): string {
        return __( 'Bahrain', 'sfs-hr' );
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
        $after_3 = $basic_salary * ( $years_of_service - 3 ); // 1 month/year = basic × years
        return $this->money( $first_3 + $after_3 );
    }

    public function calculate_gratuity_with_trigger( float $basic_salary, float $years_of_service, string $trigger_type ): float {
        // Bahrain: no statutory reduction for resignation; full gratuity applies.
        return $this->calculate_gratuity_base( $basic_salary, $years_of_service );
    }

    public function annual_leave_days( float $tenure_years ): int {
        if ( $tenure_years < 1 ) {
            return (int) floor( $tenure_years * 30 );
        }
        return 30;
    }

    public function sick_leave_bands(): array {
        return [
            [ 'days' => 15, 'pay_percentage' => 100.0 ],
            [ 'days' => 20, 'pay_percentage' => 50.0 ],
            [ 'days' => 20, 'pay_percentage' => 0.0 ],
        ];
    }

    public function notice_period_days( string $employment_type ): int {
        switch ( $employment_type ) {
            case 'probation':
                return 1;
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
        return __( 'Bahrain: 15 days of basic salary per year for the first 3 years, 1 month per year thereafter (Law 36/2012).', 'sfs-hr' );
    }
}
