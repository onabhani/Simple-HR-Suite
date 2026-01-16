<?php
/**
 * Payroll Module
 *
 * Handles salary structures, payroll periods, attendance integration,
 * deductions, payslip generation, and bank exports.
 *
 * @package SFS\HR\Modules\Payroll
 * @version 1.0.0
 */

namespace SFS\HR\Modules\Payroll;

defined( 'ABSPATH' ) || exit;

class PayrollModule {

    private static bool $initialized = false;

    /**
     * Initialize the module
     */
    public static function init(): void {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        // Create/update tables on init
        add_action( 'init', [ self::class, 'maybe_install_tables' ], 5 );

        // Register REST routes
        add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );

        // Admin pages
        if ( is_admin() ) {
            $admin = new Admin\Admin_Pages();
            $admin->hooks();
        }

        // Register capabilities
        add_filter( 'sfs_hr_capabilities', [ self::class, 'register_capabilities' ] );
    }

    /**
     * Register module capabilities
     */
    public static function register_capabilities( array $caps ): array {
        $caps['sfs_hr_payroll_admin']  = __( 'Manage Payroll Settings', 'sfs-hr' );
        $caps['sfs_hr_payroll_run']    = __( 'Run Payroll', 'sfs-hr' );
        $caps['sfs_hr_payroll_view']   = __( 'View Payroll Reports', 'sfs-hr' );
        $caps['sfs_hr_payslip_view']   = __( 'View Own Payslips', 'sfs-hr' );
        return $caps;
    }

    /**
     * Install database tables
     */
    public static function maybe_install_tables(): void {
        global $wpdb;

        $installed_version = get_option( 'sfs_hr_payroll_db_version', '0' );
        $current_version   = '1.0.0';

        if ( version_compare( $installed_version, $current_version, '>=' ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        // 1) Salary Components - Define allowances, deductions, benefits
        dbDelta( "CREATE TABLE {$p}sfs_hr_salary_components (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            name_ar VARCHAR(255) NULL,
            type ENUM('earning','deduction','benefit') NOT NULL DEFAULT 'earning',
            calculation_type ENUM('fixed','percentage','formula') NOT NULL DEFAULT 'fixed',
            default_amount DECIMAL(12,2) NULL DEFAULT 0,
            percentage_of VARCHAR(50) NULL COMMENT 'base_salary, gross_salary, etc.',
            is_taxable TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            display_order INT NOT NULL DEFAULT 0,
            description TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY type_active (type, is_active)
        ) $charset_collate;" );

        // 2) Employee Salary Components - Per-employee component overrides
        dbDelta( "CREATE TABLE {$p}sfs_hr_employee_components (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            component_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NULL COMMENT 'Override amount, NULL = use default',
            effective_from DATE NULL,
            effective_to DATE NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY employee_component (employee_id, component_id),
            KEY effective_dates (effective_from, effective_to)
        ) $charset_collate;" );

        // 3) Payroll Periods - Define pay periods
        dbDelta( "CREATE TABLE {$p}sfs_hr_payroll_periods (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            period_type ENUM('monthly','bi_weekly','weekly') NOT NULL DEFAULT 'monthly',
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            pay_date DATE NOT NULL,
            status ENUM('upcoming','open','processing','closed','paid') NOT NULL DEFAULT 'upcoming',
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status_dates (status, start_date, end_date),
            UNIQUE KEY period_dates (start_date, end_date)
        ) $charset_collate;" );

        // 4) Payroll Runs - Each payroll execution
        dbDelta( "CREATE TABLE {$p}sfs_hr_payroll_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_id BIGINT UNSIGNED NOT NULL,
            run_number INT NOT NULL DEFAULT 1,
            status ENUM('draft','calculating','review','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
            total_gross DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
            total_net DECIMAL(15,2) NOT NULL DEFAULT 0,
            employee_count INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            calculated_at DATETIME NULL,
            calculated_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            approved_by BIGINT UNSIGNED NULL,
            paid_at DATETIME NULL,
            paid_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY period_status (period_id, status),
            KEY status (status)
        ) $charset_collate;" );

        // 5) Payroll Items - Individual employee payroll records
        dbDelta( "CREATE TABLE {$p}sfs_hr_payroll_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_earnings DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
            net_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            working_days INT NOT NULL DEFAULT 0,
            days_worked INT NOT NULL DEFAULT 0,
            days_absent INT NOT NULL DEFAULT 0,
            days_late INT NOT NULL DEFAULT 0,
            days_leave INT NOT NULL DEFAULT 0,
            overtime_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
            overtime_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            attendance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            loan_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            components_json LONGTEXT NULL COMMENT 'JSON array of component breakdowns',
            bank_name VARCHAR(100) NULL,
            bank_account VARCHAR(50) NULL,
            iban VARCHAR(50) NULL,
            payment_status ENUM('pending','paid','failed','hold') NOT NULL DEFAULT 'pending',
            payment_reference VARCHAR(100) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY run_employee (run_id, employee_id),
            KEY employee_id (employee_id),
            KEY payment_status (payment_status)
        ) $charset_collate;" );

        // 6) Payslip History - For employee self-service
        dbDelta( "CREATE TABLE {$p}sfs_hr_payslips (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payroll_item_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            period_id BIGINT UNSIGNED NOT NULL,
            payslip_number VARCHAR(50) NOT NULL,
            pdf_attachment_id BIGINT UNSIGNED NULL,
            sent_at DATETIME NULL,
            viewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY employee_period (employee_id, period_id),
            UNIQUE KEY payslip_number (payslip_number)
        ) $charset_collate;" );

        // Insert default salary components
        self::insert_default_components();

        update_option( 'sfs_hr_payroll_db_version', $current_version );
    }

    /**
     * Insert default salary components
     */
    private static function insert_default_components(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_salary_components';
        $now   = current_time( 'mysql' );

        // Check if components already exist
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        $defaults = [
            // Earnings
            [ 'BASE', 'Basic Salary', 'earning', 'fixed', 0, null, 1, 1, 1 ],
            [ 'HOUSING', 'Housing Allowance', 'earning', 'percentage', 0, 'base_salary', 1, 1, 2 ],
            [ 'TRANSPORT', 'Transportation Allowance', 'earning', 'fixed', 0, null, 1, 1, 3 ],
            [ 'FOOD', 'Food Allowance', 'earning', 'fixed', 0, null, 1, 1, 4 ],
            [ 'PHONE', 'Phone Allowance', 'earning', 'fixed', 0, null, 1, 1, 5 ],
            [ 'OVERTIME', 'Overtime Pay', 'earning', 'formula', 0, null, 1, 1, 6 ],
            [ 'BONUS', 'Bonus', 'earning', 'fixed', 0, null, 1, 1, 7 ],
            [ 'COMMISSION', 'Commission', 'earning', 'fixed', 0, null, 1, 1, 8 ],

            // Deductions
            [ 'GOSI_EMP', 'GOSI Employee Share', 'deduction', 'percentage', 9.75, 'gross_salary', 0, 1, 10 ],
            [ 'ABSENCE', 'Absence Deduction', 'deduction', 'formula', 0, null, 0, 1, 11 ],
            [ 'LATE', 'Late Arrival Deduction', 'deduction', 'formula', 0, null, 0, 1, 12 ],
            [ 'LOAN', 'Loan Deduction', 'deduction', 'fixed', 0, null, 0, 1, 13 ],
            [ 'ADVANCE', 'Salary Advance', 'deduction', 'fixed', 0, null, 0, 1, 14 ],
            [ 'OTHER_DED', 'Other Deductions', 'deduction', 'fixed', 0, null, 0, 1, 15 ],

            // Benefits (employer contributions, informational)
            [ 'GOSI_COMP', 'GOSI Company Share', 'benefit', 'percentage', 11.75, 'gross_salary', 0, 1, 20 ],
            [ 'MEDICAL', 'Medical Insurance', 'benefit', 'fixed', 0, null, 0, 1, 21 ],
        ];

        foreach ( $defaults as $comp ) {
            $wpdb->insert( $table, [
                'code'             => $comp[0],
                'name'             => $comp[1],
                'type'             => $comp[2],
                'calculation_type' => $comp[3],
                'default_amount'   => $comp[4],
                'percentage_of'    => $comp[5],
                'is_taxable'       => $comp[6],
                'is_active'        => $comp[7],
                'display_order'    => $comp[8],
                'created_at'       => $now,
                'updated_at'       => $now,
            ] );
        }
    }

    /**
     * Register REST routes
     */
    public static function register_rest_routes(): void {
        Rest\Payroll_Rest::register_routes();
    }

    /**
     * Get employee's salary components for a given date
     */
    public static function get_employee_components( int $employee_id, string $date = null ): array {
        global $wpdb;

        $date = $date ?: wp_date( 'Y-m-d' );
        $comp_table = $wpdb->prefix . 'sfs_hr_salary_components';
        $emp_comp_table = $wpdb->prefix . 'sfs_hr_employee_components';

        // Get all active components
        $components = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, ec.amount as employee_amount, ec.is_active as employee_active
             FROM {$comp_table} c
             LEFT JOIN {$emp_comp_table} ec ON ec.component_id = c.id
                 AND ec.employee_id = %d
                 AND ec.is_active = 1
                 AND (ec.effective_from IS NULL OR ec.effective_from <= %s)
                 AND (ec.effective_to IS NULL OR ec.effective_to >= %s)
             WHERE c.is_active = 1
             ORDER BY c.display_order ASC",
            $employee_id,
            $date,
            $date
        ), ARRAY_A );

        return $components ?: [];
    }

    /**
     * Calculate payroll for a single employee
     */
    public static function calculate_employee_payroll( int $employee_id, int $period_id, array $options = [] ): array {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $period_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $sessions_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $leave_table = $wpdb->prefix . 'sfs_hr_leave_requests';
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $loan_payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';

        // Get employee
        $employee = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$emp_table} WHERE id = %d",
            $employee_id
        ) );

        if ( ! $employee ) {
            return [ 'error' => 'Employee not found' ];
        }

        // Get period
        $period = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$period_table} WHERE id = %d",
            $period_id
        ) );

        if ( ! $period ) {
            return [ 'error' => 'Period not found' ];
        }

        $base_salary = (float) ( $employee->base_salary ?? 0 );
        $start_date = $period->start_date;
        $end_date = $period->end_date;

        // Calculate working days in period (exclude Fridays - Saudi work week)
        $working_days = self::count_working_days( $start_date, $end_date );

        // Get attendance data
        $attendance = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as total_sessions,
                SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as days_worked,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as days_absent,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as days_late,
                SUM(CASE WHEN status = 'on_leave' THEN 1 ELSE 0 END) as days_leave,
                SUM(COALESCE(overtime_minutes, 0)) as total_overtime_minutes
             FROM {$sessions_table}
             WHERE employee_id = %d
               AND work_date BETWEEN %s AND %s",
            $employee_id,
            $start_date,
            $end_date
        ) );

        $days_worked = (int) ( $attendance->days_worked ?? 0 );
        $days_absent = (int) ( $attendance->days_absent ?? 0 );
        $days_late = (int) ( $attendance->days_late ?? 0 );
        $days_leave = (int) ( $attendance->days_leave ?? 0 );
        $overtime_minutes = (int) ( $attendance->total_overtime_minutes ?? 0 );
        $overtime_hours = round( $overtime_minutes / 60, 2 );

        // Get salary components
        $components = self::get_employee_components( $employee_id, $end_date );

        // Calculate earnings
        $total_earnings = 0;
        $component_details = [];

        foreach ( $components as $comp ) {
            if ( $comp['type'] !== 'earning' ) {
                continue;
            }

            $amount = 0;
            $use_amount = $comp['employee_amount'] ?? $comp['default_amount'] ?? 0;

            switch ( $comp['calculation_type'] ) {
                case 'fixed':
                    $amount = (float) $use_amount;
                    break;

                case 'percentage':
                    $base = 0;
                    if ( $comp['percentage_of'] === 'base_salary' ) {
                        $base = $base_salary;
                    }
                    $amount = $base * ( (float) $use_amount / 100 );
                    break;

                case 'formula':
                    // Handle special formulas
                    if ( $comp['code'] === 'OVERTIME' ) {
                        // Overtime: 1.5x hourly rate
                        $hourly_rate = $base_salary / ( $working_days * 8 );
                        $amount = $overtime_hours * $hourly_rate * 1.5;
                    }
                    break;
            }

            if ( $amount > 0 || $comp['code'] === 'BASE' ) {
                $component_details[] = [
                    'code'   => $comp['code'],
                    'name'   => $comp['name'],
                    'type'   => $comp['type'],
                    'amount' => round( $amount, 2 ),
                ];
                $total_earnings += $amount;
            }
        }

        // Add base salary if not in components
        $has_base = false;
        foreach ( $component_details as $cd ) {
            if ( $cd['code'] === 'BASE' ) {
                $has_base = true;
                break;
            }
        }
        if ( ! $has_base ) {
            array_unshift( $component_details, [
                'code'   => 'BASE',
                'name'   => 'Basic Salary',
                'type'   => 'earning',
                'amount' => $base_salary,
            ] );
            $total_earnings += $base_salary;
        }

        $gross_salary = $total_earnings;

        // Calculate deductions
        $total_deductions = 0;

        foreach ( $components as $comp ) {
            if ( $comp['type'] !== 'deduction' ) {
                continue;
            }

            $amount = 0;
            $use_amount = $comp['employee_amount'] ?? $comp['default_amount'] ?? 0;

            switch ( $comp['calculation_type'] ) {
                case 'fixed':
                    $amount = (float) $use_amount;
                    break;

                case 'percentage':
                    $base = 0;
                    if ( $comp['percentage_of'] === 'gross_salary' ) {
                        $base = $gross_salary;
                    } elseif ( $comp['percentage_of'] === 'base_salary' ) {
                        $base = $base_salary;
                    }
                    $amount = $base * ( (float) $use_amount / 100 );
                    break;

                case 'formula':
                    // Handle special deduction formulas
                    if ( $comp['code'] === 'ABSENCE' && $days_absent > 0 ) {
                        // Deduct daily rate for each absence
                        $daily_rate = $base_salary / $working_days;
                        $amount = $days_absent * $daily_rate;
                    } elseif ( $comp['code'] === 'LATE' && $days_late > 0 ) {
                        // Deduct portion for late arrivals (configurable)
                        $late_deduction_per_day = ( $base_salary / $working_days ) * 0.25; // 25% of daily rate
                        $amount = $days_late * $late_deduction_per_day;
                    }
                    break;
            }

            if ( $amount > 0 ) {
                $component_details[] = [
                    'code'   => $comp['code'],
                    'name'   => $comp['name'],
                    'type'   => $comp['type'],
                    'amount' => round( $amount, 2 ),
                ];
                $total_deductions += $amount;
            }
        }

        // Check for loan deductions
        $loan_deduction = 0;
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $loans_table ) ) ) {
            // Get active loans with pending installments
            $loans = $wpdb->get_results( $wpdb->prepare(
                "SELECT l.id, l.monthly_installment, l.remaining_balance
                 FROM {$loans_table} l
                 WHERE l.employee_id = %d
                   AND l.status = 'active'
                   AND l.remaining_balance > 0",
                $employee_id
            ) );

            foreach ( $loans as $loan ) {
                $installment = min( (float) $loan->monthly_installment, (float) $loan->remaining_balance );
                $loan_deduction += $installment;
            }

            if ( $loan_deduction > 0 ) {
                $component_details[] = [
                    'code'   => 'LOAN',
                    'name'   => 'Loan Deduction',
                    'type'   => 'deduction',
                    'amount' => round( $loan_deduction, 2 ),
                ];
                $total_deductions += $loan_deduction;
            }
        }

        // Calculate attendance deduction (absence + late)
        $attendance_deduction = 0;
        foreach ( $component_details as $cd ) {
            if ( in_array( $cd['code'], [ 'ABSENCE', 'LATE' ], true ) ) {
                $attendance_deduction += $cd['amount'];
            }
        }

        $net_salary = $gross_salary - $total_deductions;

        // Get bank details
        $bank_name = $employee->bank_name ?? '';
        $bank_account = $employee->bank_account ?? '';
        $iban = $employee->iban ?? '';

        return [
            'employee_id'         => $employee_id,
            'employee_name'       => trim( ( $employee->first_name ?? '' ) . ' ' . ( $employee->last_name ?? '' ) ),
            'emp_number'          => $employee->emp_number ?? $employee->employee_code ?? '',
            'base_salary'         => round( $base_salary, 2 ),
            'gross_salary'        => round( $gross_salary, 2 ),
            'total_earnings'      => round( $total_earnings, 2 ),
            'total_deductions'    => round( $total_deductions, 2 ),
            'net_salary'          => round( $net_salary, 2 ),
            'working_days'        => $working_days,
            'days_worked'         => $days_worked,
            'days_absent'         => $days_absent,
            'days_late'           => $days_late,
            'days_leave'          => $days_leave,
            'overtime_hours'      => $overtime_hours,
            'overtime_amount'     => round( $overtime_hours > 0 ? $component_details[ array_search( 'OVERTIME', array_column( $component_details, 'code' ) ) ]['amount'] ?? 0 : 0, 2 ),
            'attendance_deduction'=> round( $attendance_deduction, 2 ),
            'loan_deduction'      => round( $loan_deduction, 2 ),
            'components'          => $component_details,
            'bank_name'           => $bank_name,
            'bank_account'        => $bank_account,
            'iban'                => $iban,
        ];
    }

    /**
     * Count working days between two dates (excludes Fridays)
     */
    public static function count_working_days( string $start, string $end ): int {
        $start_date = new \DateTime( $start );
        $end_date = new \DateTime( $end );
        $end_date->modify( '+1 day' );

        $interval = new \DateInterval( 'P1D' );
        $period = new \DatePeriod( $start_date, $interval, $end_date );

        $working_days = 0;
        foreach ( $period as $date ) {
            // Skip Fridays (5) - Saudi weekend
            if ( $date->format( 'N' ) != 5 ) {
                $working_days++;
            }
        }

        return $working_days;
    }

    /**
     * Generate payroll period name
     */
    public static function generate_period_name( string $start_date, string $end_date ): string {
        $start = new \DateTime( $start_date );
        $end = new \DateTime( $end_date );

        if ( $start->format( 'Y-m' ) === $end->format( 'Y-m' ) ) {
            return $start->format( 'F Y' );
        }

        return $start->format( 'M j' ) . ' - ' . $end->format( 'M j, Y' );
    }

    /**
     * Generate payslip number
     */
    public static function generate_payslip_number( int $employee_id, int $period_id ): string {
        return sprintf( 'PS-%06d-%06d-%s', $employee_id, $period_id, wp_date( 'Ymd' ) );
    }
}
