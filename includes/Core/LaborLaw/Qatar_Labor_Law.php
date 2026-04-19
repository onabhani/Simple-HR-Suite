<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Qatar_Labor_Law
 *
 * Qatar Labour Law (Law No. 14/2004, as amended by Law 18/2020).
 *
 * - Gratuity: not less than 3 weeks (21 days) basic per year for first 5 years;
 *             1 month basic per year thereafter.
 *   Payable upon completion of at least 1 year of continuous service.
 *   No statutory reduction on resignation — full amount payable.
 * - Annual leave: 3 weeks (21 days) for 1–5 years; 4 weeks (28 days) after 5 years.
 * - Sick leave: 12 weeks — 2 weeks full, 4 weeks three-quarter, 6 weeks unpaid
 *   (after minimum 3 months service).
 * - Notice: probation 3 days, ≤ 5 yrs permanent 30 days, > 5 yrs 60 days.
 *
 * @since M8
 */
class Qatar_Labor_Law extends Base_Labor_Law {

    public function country_code(): string {
        return 'QA';
    }

    public function country_name(): string {
        return __( 'Qatar', 'sfs-hr' );
    }

    public function calculate_gratuity_base( float $basic_salary, float $years_of_service ): float {
        if ( $years_of_service <= 0 || $basic_salary <= 0 ) {
            return 0.0;
        }

        $daily_rate = $this->daily_rate( $basic_salary );

        if ( $years_of_service <= 5 ) {
            return $this->money( $daily_rate * 21 * $years_of_service );
        }

        $first_5 = $daily_rate * 21 * 5;
        $after_5 = $basic_salary * ( $years_of_service - 5 );
        return $this->money( $first_5 + $after_5 );
    }

    public function calculate_gratuity_with_trigger( float $basic_salary, float $years_of_service, string $trigger_type ): float {
        // Payable only after 1 year of service; otherwise zero.
        if ( $years_of_service < 1 ) {
            return 0.0;
        }
        return $this->calculate_gratuity_base( $basic_salary, $years_of_service );
    }

    public function annual_leave_days( float $tenure_years ): int {
        if ( $tenure_years < 1 ) {
            return (int) floor( $tenure_years * 21 );
        }
        return $tenure_years >= 5 ? 28 : 21;
    }

    public function sick_leave_bands(): array {
        // Qatar: 2 weeks full, 4 weeks 50%, 6 weeks unpaid.
        return [
            [ 'days' => 14, 'pay_percentage' => 100.0 ],
            [ 'days' => 28, 'pay_percentage' => 50.0 ],
            [ 'days' => 42, 'pay_percentage' => 0.0 ],
        ];
    }

    public function notice_period_days( string $employment_type ): int {
        switch ( $employment_type ) {
            case 'probation':
                return 3;
            case 'contract':
                return 30;
            case 'permanent':
            default:
                return 30; // Short-tenure default; long-tenure handled by Notice_Service tenure check.
        }
    }

    public function probation_period_days(): int {
        return 180; // Up to 6 months.
    }

    public function gratuity_formula_description(): string {
        return __( 'Qatar: 3 weeks per year for first 5 years, 1 month per year thereafter (Law 14/2004, Art. 54).', 'sfs-hr' );
    }
}
