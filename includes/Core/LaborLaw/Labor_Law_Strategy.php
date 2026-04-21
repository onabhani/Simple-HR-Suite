<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Labor_Law_Strategy
 *
 * Contract for per-country labor law implementations. Each Gulf country
 * (and Saudi Arabia) implements this interface with jurisdiction-specific
 * rules for gratuity, leave, notice periods, and workweek.
 *
 * Concrete implementations live alongside this file:
 *   - Saudi_Labor_Law
 *   - UAE_Labor_Law
 *   - Bahrain_Labor_Law
 *   - Kuwait_Labor_Law
 *   - Oman_Labor_Law
 *   - Qatar_Labor_Law
 *
 * Dispatch is handled by Labor_Law_Service::for_country().
 *
 * @since M8
 */
interface Labor_Law_Strategy {

    /** Two-letter ISO country code this strategy implements (e.g. 'SA', 'AE'). */
    public function country_code(): string;

    /** Human-readable country name, already translated where applicable. */
    public function country_name(): string;

    /**
     * Calculate gratuity (end-of-service indemnity) base amount — before any
     * trigger-type reduction.
     *
     * @param float $basic_salary   Monthly basic salary (local currency).
     * @param float $years_of_service Tenure in years (may be fractional).
     * @return float Gratuity amount in local currency.
     */
    public function calculate_gratuity_base( float $basic_salary, float $years_of_service ): float;

    /**
     * Apply trigger-type multiplier to gratuity.
     *
     * Trigger types: 'resignation', 'termination', 'contract_end'.
     *
     * @param float  $basic_salary
     * @param float  $years_of_service
     * @param string $trigger_type
     * @return float Final gratuity amount.
     */
    public function calculate_gratuity_with_trigger( float $basic_salary, float $years_of_service, string $trigger_type ): float;

    /**
     * Annual leave entitlement (calendar days) based on tenure.
     *
     * @param float $tenure_years Years of service at the point of accrual.
     * @return int  Entitled annual leave days for the year.
     */
    public function annual_leave_days( float $tenure_years ): int;

    /**
     * Sick leave allocation — returns an array describing paid bands.
     *
     * @return array[] Each band: [ 'days' => int, 'pay_percentage' => float ].
     *                 Bands are applied in order until total is consumed.
     */
    public function sick_leave_bands(): array;

    /**
     * Notice period in days for the given employment type.
     *
     * @param string $employment_type 'probation' | 'permanent' | 'contract'.
     * @return int Notice days (0 or positive).
     */
    public function notice_period_days( string $employment_type ): int;

    /**
     * Probation period in days (default per country law).
     */
    public function probation_period_days(): int;

    /**
     * Weekend day numbers (0 = Sunday .. 6 = Saturday) used for
     * business-day calculations when no shift schedule is provided.
     */
    public function weekend_days(): array;

    /**
     * Short free-form description of the gratuity formula shown in admin UI.
     * Translated string — use __() inside implementations.
     */
    public function gratuity_formula_description(): string;
}
