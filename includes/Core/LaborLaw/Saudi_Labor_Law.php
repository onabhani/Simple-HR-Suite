<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Saudi_Labor_Law
 *
 * Saudi labor law implementation (Royal Decree No. M/51, Articles 84, 87, 109, 117).
 *
 * - Gratuity: 15 days/year for first 5 years; 30 days/year after (basic salary).
 * - Resignation trigger:
 *     < 2 years  = 0
 *     2–5 years  = 1/3 of full
 *     5–10 years = 2/3 of full
 *     10+ years  = full
 * - Annual leave: 21 days <5 yrs tenure; 30 days ≥5 yrs tenure.
 * - Sick leave: 30 full / 60 three-quarter / 30 unpaid (Article 117).
 * - Notice: probation 0, permanent 30, contract 60.
 *
 * This class preserves the exact calculation behavior previously
 * hardcoded in Settlement_Service::calculate_gratuity() to guarantee
 * backward compatibility for existing Saudi installations.
 *
 * @since M8
 */
class Saudi_Labor_Law extends Base_Labor_Law {

    public function country_code(): string {
        return 'SA';
    }

    public function country_name(): string {
        return __( 'Saudi Arabia', 'sfs-hr' );
    }

    public function calculate_gratuity_base( float $basic_salary, float $years_of_service ): float {
        if ( $years_of_service <= 0 || $basic_salary <= 0 ) {
            return 0.0;
        }

        $daily_rate = $this->daily_rate( $basic_salary );

        if ( $years_of_service <= 5 ) {
            return $this->money( $daily_rate * 15 * $years_of_service );
        }

        $first_5       = $daily_rate * 15 * 5;
        $after_5_years = $daily_rate * 30 * ( $years_of_service - 5 );

        return $this->money( $first_5 + $after_5_years );
    }

    public function calculate_gratuity_with_trigger( float $basic_salary, float $years_of_service, string $trigger_type ): float {
        $full = $this->calculate_gratuity_base( $basic_salary, $years_of_service );

        if ( 'resignation' === $trigger_type ) {
            if ( $years_of_service < 2 ) {
                return 0.0;
            }
            if ( $years_of_service < 5 ) {
                return $this->money( $full / 3 );
            }
            if ( $years_of_service < 10 ) {
                return $this->money( $full * 2 / 3 );
            }
            return $full;
        }

        // termination, contract_end = full
        return $full;
    }

    public function annual_leave_days( float $tenure_years ): int {
        $lt5 = (int) get_option( 'sfs_hr_annual_lt5', 21 );
        $ge5 = (int) get_option( 'sfs_hr_annual_ge5', 30 );
        return $tenure_years >= 5 ? $ge5 : $lt5;
    }

    public function sick_leave_bands(): array {
        // Saudi Article 117: first 30 full pay, next 60 at 75%, next 30 unpaid.
        return [
            [ 'days' => 30, 'pay_percentage' => 100.0 ],
            [ 'days' => 60, 'pay_percentage' => 75.0 ],
            [ 'days' => 30, 'pay_percentage' => 0.0 ],
        ];
    }

    public function notice_period_days( string $employment_type ): int {
        switch ( $employment_type ) {
            case 'probation':
                return 0;
            case 'contract':
                return 60;
            case 'permanent':
            default:
                return 30;
        }
    }

    public function probation_period_days(): int {
        return 90; // SA allows up to 180 by agreement; 90 is the standard default.
    }

    public function gratuity_formula_description(): string {
        return __( 'Saudi Arabia: 15 days of basic salary per year for the first 5 years, then 30 days per year thereafter. Resignation tier reductions apply under Article 85.', 'sfs-hr' );
    }
}
