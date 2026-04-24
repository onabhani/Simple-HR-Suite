<?php
/**
 * Payslip Service
 *
 * Provides payslip-related business logic: HTML rendering for email,
 * YTD calculations, batch email distribution, and month-over-month
 * comparison data.
 *
 * @package SFS\HR\Modules\Payroll\Services
 * @since   2.3.0
 */

namespace SFS\HR\Modules\Payroll\Services;

use SFS\HR\Core\Company_Profile;
use SFS\HR\Modules\Payroll\PayrollModule;

defined( 'ABSPATH' ) || exit;

class Payslip_Service {

    /* ─────────────────────────────────────────────
     * YTD Calculations
     * ───────────────────────────────────────────── */

    /**
     * Get year-to-date totals for an employee.
     *
     * Aggregates all payroll items in the given calendar year (or current year
     * if none specified), excluding reversed runs.
     *
     * @param int      $employee_id Employee ID.
     * @param int|null $year        Calendar year (defaults to current).
     * @return array{gross: float, deductions: float, net: float, months: int}
     */
    public static function get_ytd( int $employee_id, ?int $year = null ): array {
        global $wpdb;

        $year         = $year ?: (int) current_time( 'Y' );
        $items_table  = $wpdb->prefix . 'sfs_hr_payroll_items';
        $runs_table   = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.gross_salary, i.total_deductions, i.net_salary
             FROM {$items_table} i
             INNER JOIN {$runs_table} r ON r.id = i.run_id
             INNER JOIN {$periods_table} p ON p.id = r.period_id
             WHERE i.employee_id = %d
               AND YEAR(p.start_date) = %d
               AND r.status NOT IN ('reversed','draft')
             ORDER BY p.start_date ASC",
            $employee_id,
            $year
        ), ARRAY_A );

        $ytd = [ 'gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'months' => 0 ];

        foreach ( $rows as $row ) {
            $ytd['gross']      += (float) $row['gross_salary'];
            $ytd['deductions'] += (float) $row['total_deductions'];
            $ytd['net']        += (float) $row['net_salary'];
            $ytd['months']++;
        }

        return $ytd;
    }

    /**
     * Get YTD component breakdown for an employee.
     *
     * Returns aggregated amounts per component code across all payroll items
     * in the given year.
     *
     * @param int      $employee_id Employee ID.
     * @param int|null $year        Calendar year.
     * @return array<string, array{name: string, type: string, total: float}>
     */
    public static function get_ytd_components( int $employee_id, ?int $year = null ): array {
        global $wpdb;

        $year          = $year ?: (int) current_time( 'Y' );
        $items_table   = $wpdb->prefix . 'sfs_hr_payroll_items';
        $runs_table    = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.components_json
             FROM {$items_table} i
             INNER JOIN {$runs_table} r ON r.id = i.run_id
             INNER JOIN {$periods_table} p ON p.id = r.period_id
             WHERE i.employee_id = %d
               AND YEAR(p.start_date) = %d
               AND r.status NOT IN ('reversed','draft')
             ORDER BY p.start_date ASC",
            $employee_id,
            $year
        ), ARRAY_A );

        $components = [];

        foreach ( $rows as $row ) {
            $json = json_decode( $row['components_json'] ?? '', true );
            if ( ! is_array( $json ) ) {
                continue;
            }
            foreach ( $json as $c ) {
                $code = $c['code'] ?? 'unknown';
                if ( ! isset( $components[ $code ] ) ) {
                    $components[ $code ] = [
                        'name'  => $c['name'] ?? $code,
                        'type'  => $c['type'] ?? 'earning',
                        'total' => 0.0,
                    ];
                }
                $components[ $code ]['total'] += (float) ( $c['amount'] ?? 0 );
            }
        }

        return $components;
    }

    /* ─────────────────────────────────────────────
     * Month-over-Month Comparison
     * ───────────────────────────────────────────── */

    /**
     * Get month-over-month payroll comparison for an employee.
     *
     * Returns up to $limit recent payroll entries with per-month totals and
     * deltas from previous month.
     *
     * @param int $employee_id Employee ID.
     * @param int $limit       Number of months to compare (max 24).
     * @return array List of month records with deltas.
     */
    public static function get_month_comparison( int $employee_id, int $limit = 6 ): array {
        global $wpdb;

        $limit         = min( max( $limit, 2 ), 24 );
        $items_table   = $wpdb->prefix . 'sfs_hr_payroll_items';
        $runs_table    = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.name AS period_name, p.start_date, p.end_date,
                    i.base_salary, i.gross_salary, i.total_deductions, i.net_salary,
                    i.components_json
             FROM {$items_table} i
             INNER JOIN {$runs_table} r ON r.id = i.run_id
             INNER JOIN {$periods_table} p ON p.id = r.period_id
             WHERE i.employee_id = %d
               AND r.status NOT IN ('reversed','draft')
             ORDER BY p.start_date DESC
             LIMIT %d",
            $employee_id,
            $limit
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return [];
        }

        // Reverse to chronological order for delta calculation.
        $rows = array_reverse( $rows );

        $result = [];
        $prev   = null;

        foreach ( $rows as $row ) {
            $entry = [
                'period_name'      => $row['period_name'],
                'start_date'       => $row['start_date'],
                'end_date'         => $row['end_date'],
                'base_salary'      => round( (float) $row['base_salary'], 2 ),
                'gross_salary'     => round( (float) $row['gross_salary'], 2 ),
                'total_deductions' => round( (float) $row['total_deductions'], 2 ),
                'net_salary'       => round( (float) $row['net_salary'], 2 ),
                'delta_gross'      => null,
                'delta_deductions' => null,
                'delta_net'        => null,
            ];

            if ( $prev ) {
                $entry['delta_gross']      = round( $entry['gross_salary'] - $prev['gross_salary'], 2 );
                $entry['delta_deductions'] = round( $entry['total_deductions'] - $prev['total_deductions'], 2 );
                $entry['delta_net']        = round( $entry['net_salary'] - $prev['net_salary'], 2 );
            }

            $result[] = $entry;
            $prev     = $entry;
        }

        return $result;
    }

    /* ─────────────────────────────────────────────
     * Payslip HTML Rendering
     * ───────────────────────────────────────────── */

    /**
     * Render a payslip as a standalone HTML document suitable for email or print.
     *
     * @param int  $payslip_id The payslip ID.
     * @param bool $include_ytd Whether to include YTD summary section.
     * @return string|false HTML string or false if payslip not found.
     */
    public static function render_html( int $payslip_id, bool $include_ytd = true ) {
        global $wpdb;

        $payslips_table = $wpdb->prefix . 'sfs_hr_payslips';
        $items_table    = $wpdb->prefix . 'sfs_hr_payroll_items';
        $periods_table  = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $emp_table      = $wpdb->prefix . 'sfs_hr_employees';

        $ps = $wpdb->get_row( $wpdb->prepare(
            "SELECT ps.*, p.name AS period_name, p.start_date, p.end_date, p.pay_date,
                    e.first_name, e.last_name, e.employee_code, e.department_id,
                    e.job_title, e.hire_date,
                    COALESCE(i.bank_name, e.bank_name) AS bank_name,
                    COALESCE(i.bank_account, e.bank_account) AS bank_account,
                    COALESCE(i.iban, e.iban) AS iban,
                    i.base_salary, i.gross_salary, i.total_deductions, i.net_salary,
                    i.working_days, i.days_worked, i.days_absent, i.days_late, i.days_leave,
                    i.overtime_hours, i.components_json
             FROM {$payslips_table} ps
             LEFT JOIN {$periods_table} p ON p.id = ps.period_id
             LEFT JOIN {$emp_table} e ON e.id = ps.employee_id
             LEFT JOIN {$items_table} i ON i.id = ps.payroll_item_id
             WHERE ps.id = %d",
            $payslip_id
        ) );

        if ( ! $ps ) {
            return false;
        }

        $company    = Company_Profile::get();
        $components = ! empty( $ps->components_json ) ? json_decode( $ps->components_json, true ) : [];
        $earnings   = is_array( $components ) ? array_filter( $components, fn( $c ) => ( $c['type'] ?? '' ) === 'earning' ) : [];
        $deductions = is_array( $components ) ? array_filter( $components, fn( $c ) => ( $c['type'] ?? '' ) === 'deduction' ) : [];
        $emp_name   = trim( ( $ps->first_name ?? '' ) . ' ' . ( $ps->last_name ?? '' ) );
        $currency   = $company['currency'] ?: 'SAR';

        // Optional YTD data.
        $ytd = null;
        if ( $include_ytd && $ps->start_date ) {
            $year = (int) substr( $ps->start_date, 0, 4 );
            $ytd  = self::get_ytd( (int) $ps->employee_id, $year );
        }

        // Department name lookup.
        $dept_name = '';
        if ( ! empty( $ps->department_id ) ) {
            $dept_table = $wpdb->prefix . 'sfs_hr_departments';
            $dept_name  = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM {$dept_table} WHERE id = %d",
                $ps->department_id
            ) );
        }

        ob_start();
        self::render_html_template( $ps, $company, $emp_name, $dept_name, $currency, $earnings, $deductions, $ytd );
        return ob_get_clean();
    }

    /**
     * Internal: output the payslip HTML template.
     */
    private static function render_html_template(
        object $ps,
        array $company,
        string $emp_name,
        string $dept_name,
        string $currency,
        array $earnings,
        array $deductions,
        ?array $ytd
    ): void {
        $logo_url = ! empty( $company['logo_id'] ) ? wp_get_attachment_image_url( (int) $company['logo_id'], 'medium' ) : '';
        $fmt      = fn( float $v ): string => number_format( $v, 2 );
        ?>
<!DOCTYPE html>
<html dir="ltr">
<head>
<meta charset="UTF-8">
<style>
body{margin:0;padding:20px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:14px;color:#1f2937;background:#fff;}
.ps-container{max-width:700px;margin:0 auto;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
.ps-header{background:#1e40af;color:#fff;padding:20px 24px;display:flex;justify-content:space-between;align-items:center;}
.ps-header h1{margin:0;font-size:18px;font-weight:700;}
.ps-header .ps-logo img{max-height:50px;}
.ps-header .ps-company{font-size:12px;opacity:0.9;margin-top:4px;}
.ps-body{padding:24px;}
.ps-meta{display:flex;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #e5e7eb;}
.ps-meta-block{min-width:140px;}
.ps-meta-label{font-size:11px;text-transform:uppercase;color:#6b7280;margin-bottom:2px;}
.ps-meta-value{font-size:14px;font-weight:600;color:#1f2937;}
table.ps-table{width:100%;border-collapse:collapse;margin-bottom:16px;}
table.ps-table th{background:#f9fafb;text-align:left;padding:8px 12px;font-size:12px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;}
table.ps-table td{padding:8px 12px;border-bottom:1px solid #f3f4f6;}
table.ps-table td.amount{text-align:right;font-variant-numeric:tabular-nums;}
table.ps-table tr.total-row td{font-weight:700;border-top:2px solid #e5e7eb;background:#f9fafb;}
.ps-net-box{background:#1e40af;color:#fff;padding:16px 24px;display:flex;justify-content:space-between;align-items:center;border-radius:6px;margin:16px 0;}
.ps-net-box .ps-net-label{font-size:16px;font-weight:600;}
.ps-net-box .ps-net-value{font-size:24px;font-weight:800;}
.ps-attendance{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;}
.ps-att-item{background:#f9fafb;padding:8px 14px;border-radius:6px;font-size:13px;}
.ps-att-item strong{display:block;font-size:16px;color:#1f2937;}
.ps-ytd{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:16px;margin-top:16px;}
.ps-ytd h3{margin:0 0 10px;font-size:14px;color:#92400e;}
.ps-ytd-grid{display:flex;gap:20px;flex-wrap:wrap;}
.ps-ytd-item{min-width:100px;}
.ps-ytd-item .label{font-size:11px;text-transform:uppercase;color:#92400e;}
.ps-ytd-item .value{font-size:16px;font-weight:700;color:#78350f;}
.ps-footer{padding:16px 24px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af;text-align:center;}
@media print{body{padding:0;}.ps-container{border:none;}}
</style>
</head>
<body>
<div class="ps-container">
    <!-- Header -->
    <div class="ps-header">
        <div>
            <h1><?php echo esc_html( $company['company_name'] ?: __( 'Company', 'sfs-hr' ) ); ?></h1>
            <?php if ( ! empty( $company['address'] ) || ! empty( $company['city'] ) ): ?>
            <div class="ps-company"><?php echo esc_html( trim( $company['address'] . ', ' . $company['city'], ', ' ) ); ?></div>
            <?php endif; ?>
            <?php if ( ! empty( $company['cr_number'] ) ): ?>
            <div class="ps-company"><?php echo esc_html( __( 'CR', 'sfs-hr' ) . ': ' . $company['cr_number'] ); ?></div>
            <?php endif; ?>
        </div>
        <?php if ( $logo_url ): ?>
        <div class="ps-logo"><img src="<?php echo esc_url( $logo_url ); ?>" alt="Logo"></div>
        <?php endif; ?>
    </div>

    <div class="ps-body">
        <!-- Payslip Meta -->
        <div class="ps-meta">
            <div class="ps-meta-block">
                <div class="ps-meta-label"><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></div>
                <div class="ps-meta-value"><?php echo esc_html( $emp_name ); ?></div>
                <?php if ( ! empty( $ps->employee_code ) ): ?>
                <div style="font-size:12px;color:#6b7280;">#<?php echo esc_html( $ps->employee_code ); ?></div>
                <?php endif; ?>
            </div>
            <?php if ( $dept_name ): ?>
            <div class="ps-meta-block">
                <div class="ps-meta-label"><?php esc_html_e( 'Department', 'sfs-hr' ); ?></div>
                <div class="ps-meta-value"><?php echo esc_html( $dept_name ); ?></div>
            </div>
            <?php endif; ?>
            <?php if ( ! empty( $ps->job_title ) ): ?>
            <div class="ps-meta-block">
                <div class="ps-meta-label"><?php esc_html_e( 'Job Title', 'sfs-hr' ); ?></div>
                <div class="ps-meta-value"><?php echo esc_html( $ps->job_title ); ?></div>
            </div>
            <?php endif; ?>
            <div class="ps-meta-block">
                <div class="ps-meta-label"><?php esc_html_e( 'Payslip Number', 'sfs-hr' ); ?></div>
                <div class="ps-meta-value"><?php echo esc_html( $ps->payslip_number ); ?></div>
            </div>
            <div class="ps-meta-block">
                <div class="ps-meta-label"><?php esc_html_e( 'Period', 'sfs-hr' ); ?></div>
                <div class="ps-meta-value"><?php echo esc_html( $ps->period_name ?? '' ); ?></div>
                <?php if ( ! empty( $ps->start_date ) && ! empty( $ps->end_date ) ): ?>
                <div style="font-size:12px;color:#6b7280;"><?php echo esc_html( $ps->start_date . ' — ' . $ps->end_date ); ?></div>
                <?php endif; ?>
            </div>
            <?php if ( ! empty( $ps->pay_date ) ): ?>
            <div class="ps-meta-block">
                <div class="ps-meta-label"><?php esc_html_e( 'Pay Date', 'sfs-hr' ); ?></div>
                <div class="ps-meta-value"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $ps->pay_date ) ) ); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Attendance Summary -->
        <?php if ( ! empty( $ps->working_days ) ): ?>
        <div class="ps-attendance">
            <div class="ps-att-item"><strong><?php echo esc_html( $ps->working_days ); ?></strong><?php esc_html_e( 'Working Days', 'sfs-hr' ); ?></div>
            <div class="ps-att-item"><strong><?php echo esc_html( $ps->days_worked ?? '0' ); ?></strong><?php esc_html_e( 'Days Worked', 'sfs-hr' ); ?></div>
            <div class="ps-att-item"><strong><?php echo esc_html( $ps->days_absent ?? '0' ); ?></strong><?php esc_html_e( 'Absent', 'sfs-hr' ); ?></div>
            <div class="ps-att-item"><strong><?php echo esc_html( $ps->days_leave ?? '0' ); ?></strong><?php esc_html_e( 'Leave', 'sfs-hr' ); ?></div>
            <?php if ( (float) ( $ps->overtime_hours ?? 0 ) > 0 ): ?>
            <div class="ps-att-item"><strong><?php echo esc_html( $ps->overtime_hours ); ?></strong><?php esc_html_e( 'OT Hours', 'sfs-hr' ); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Earnings -->
        <?php if ( ! empty( $earnings ) ): ?>
        <table class="ps-table">
            <thead><tr><th><?php esc_html_e( 'Earnings', 'sfs-hr' ); ?></th><th style="text-align:right;"><?php echo esc_html( $currency ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $earnings as $c ): ?>
                <tr><td><?php echo esc_html( $c['name'] ?? $c['code'] ?? '—' ); ?></td><td class="amount"><?php echo esc_html( $fmt( (float) ( $c['amount'] ?? 0 ) ) ); ?></td></tr>
            <?php endforeach; ?>
                <tr class="total-row"><td><?php esc_html_e( 'Gross Salary', 'sfs-hr' ); ?></td><td class="amount"><?php echo esc_html( $fmt( (float) $ps->gross_salary ) ); ?></td></tr>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Deductions -->
        <?php if ( ! empty( $deductions ) ): ?>
        <table class="ps-table">
            <thead><tr><th><?php esc_html_e( 'Deductions', 'sfs-hr' ); ?></th><th style="text-align:right;"><?php echo esc_html( $currency ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $deductions as $c ): ?>
                <tr><td><?php echo esc_html( $c['name'] ?? $c['code'] ?? '—' ); ?></td><td class="amount"><?php echo esc_html( $fmt( (float) ( $c['amount'] ?? 0 ) ) ); ?></td></tr>
            <?php endforeach; ?>
                <tr class="total-row"><td><?php esc_html_e( 'Total Deductions', 'sfs-hr' ); ?></td><td class="amount"><?php echo esc_html( $fmt( (float) $ps->total_deductions ) ); ?></td></tr>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Net Salary -->
        <div class="ps-net-box">
            <span class="ps-net-label"><?php esc_html_e( 'Net Salary', 'sfs-hr' ); ?></span>
            <span class="ps-net-value"><?php echo esc_html( $currency . ' ' . $fmt( (float) $ps->net_salary ) ); ?></span>
        </div>

        <!-- Bank Details -->
        <?php if ( ! empty( $ps->bank_name ) || ! empty( $ps->iban ) ): ?>
        <table class="ps-table" style="margin-top:16px;">
            <thead><tr><th colspan="2"><?php esc_html_e( 'Bank Details', 'sfs-hr' ); ?></th></tr></thead>
            <tbody>
            <?php if ( ! empty( $ps->bank_name ) ): ?>
                <tr><td><?php esc_html_e( 'Bank', 'sfs-hr' ); ?></td><td class="amount"><?php echo esc_html( $ps->bank_name ); ?></td></tr>
            <?php endif; ?>
            <?php if ( ! empty( $ps->bank_account ) ): ?>
                <tr><td><?php esc_html_e( 'Account', 'sfs-hr' ); ?></td><td class="amount"><?php echo esc_html( $ps->bank_account ); ?></td></tr>
            <?php endif; ?>
            <?php if ( ! empty( $ps->iban ) ): ?>
                <tr><td><?php esc_html_e( 'IBAN', 'sfs-hr' ); ?></td><td class="amount"><?php echo esc_html( $ps->iban ); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- YTD Summary -->
        <?php if ( $ytd && $ytd['months'] > 0 ): ?>
        <div class="ps-ytd">
            <h3><?php echo esc_html( sprintf( __( 'Year-to-Date (%d)', 'sfs-hr' ), (int) substr( $ps->start_date, 0, 4 ) ) ); ?></h3>
            <div class="ps-ytd-grid">
                <div class="ps-ytd-item"><div class="label"><?php esc_html_e( 'Gross', 'sfs-hr' ); ?></div><div class="value"><?php echo esc_html( $fmt( $ytd['gross'] ) ); ?></div></div>
                <div class="ps-ytd-item"><div class="label"><?php esc_html_e( 'Deductions', 'sfs-hr' ); ?></div><div class="value"><?php echo esc_html( $fmt( $ytd['deductions'] ) ); ?></div></div>
                <div class="ps-ytd-item"><div class="label"><?php esc_html_e( 'Net', 'sfs-hr' ); ?></div><div class="value"><?php echo esc_html( $fmt( $ytd['net'] ) ); ?></div></div>
                <div class="ps-ytd-item"><div class="label"><?php esc_html_e( 'Months', 'sfs-hr' ); ?></div><div class="value"><?php echo esc_html( (string) $ytd['months'] ); ?></div></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="ps-footer">
        <?php echo esc_html( sprintf(
            __( 'Generated on %s — %s', 'sfs-hr' ),
            wp_date( 'M j, Y' ),
            $company['company_name'] ?: get_bloginfo( 'name' )
        ) ); ?>
        <?php if ( ! empty( $company['email'] ) ): ?>
         &middot; <?php echo esc_html( $company['email'] ); ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
        <?php
    }

    /* ─────────────────────────────────────────────
     * Batch Email Distribution
     * ───────────────────────────────────────────── */

    /**
     * Send payslip email to a single employee.
     *
     * @param int $payslip_id Payslip ID.
     * @return bool Whether the email was sent successfully.
     */
    public static function send_email( int $payslip_id ): bool {
        global $wpdb;

        $payslips_table = $wpdb->prefix . 'sfs_hr_payslips';
        $emp_table      = $wpdb->prefix . 'sfs_hr_employees';
        $periods_table  = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';

        $ps = $wpdb->get_row( $wpdb->prepare(
            "SELECT ps.employee_id, ps.period_id, ps.sent_at, ps.payroll_item_id,
                    e.email, e.first_name, e.last_name, e.user_id,
                    p.name AS period_name,
                    i.net_salary
             FROM {$payslips_table} ps
             LEFT JOIN {$emp_table} e ON e.id = ps.employee_id
             LEFT JOIN {$periods_table} p ON p.id = ps.period_id
             LEFT JOIN {$items_table} i ON i.id = ps.payroll_item_id
             WHERE ps.id = %d",
            $payslip_id
        ) );

        if ( ! $ps ) {
            return false;
        }

        // Resolve email: employee table first, then WP user.
        $to = $ps->email;
        if ( empty( $to ) && ! empty( $ps->user_id ) ) {
            $user = get_userdata( (int) $ps->user_id );
            $to   = $user ? $user->user_email : '';
        }

        if ( empty( $to ) || ! is_email( $to ) ) {
            return false;
        }

        $html = self::render_html( $payslip_id );
        if ( ! $html ) {
            return false;
        }

        $company = Company_Profile::get();
        $subject = sprintf(
            /* translators: 1: company name, 2: period name */
            __( '[%1$s] Your payslip for %2$s is ready', 'sfs-hr' ),
            $company['company_name'] ?: get_bloginfo( 'name' ),
            $ps->period_name ?: __( 'this period', 'sfs-hr' )
        );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        if ( ! empty( $company['email'] ) && is_email( $company['email'] ) ) {
            $from_name = $company['company_name'] ?: get_bloginfo( 'name' );
            $headers[] = 'Reply-To: ' . $from_name . ' <' . $company['email'] . '>';
        }

        $sent = wp_mail( $to, $subject, $html, $headers );

        if ( $sent ) {
            $wpdb->update( $payslips_table, [
                'sent_at' => current_time( 'mysql' ),
            ], [ 'id' => $payslip_id ] );

            do_action( 'sfs_hr_payslip_ready', (int) $ps->employee_id, [
                'payslip_id'  => $payslip_id,
                'period_name' => $ps->period_name ?? '',
                'net_salary'  => isset( $ps->net_salary ) ? (float) $ps->net_salary : null,
            ] );
        }

        return $sent;
    }

    /**
     * Send payslip emails for all employees in a payroll run.
     *
     * Uses a transient lock to prevent concurrent batch sends.
     *
     * @param int $run_id Payroll run ID.
     * @return array{sent: int, failed: int, skipped: int}
     */
    public static function batch_send_by_run( int $run_id ): array {
        global $wpdb;

        // Extend execution time for large batches.
        @set_time_limit( 300 );

        $lock_key = 'sfs_hr_payslip_batch_' . $run_id;
        if ( get_transient( $lock_key ) ) {
            return [ 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 'batch_in_progress' ];
        }
        set_transient( $lock_key, true, 300 ); // 5-min lock.

        $payslips_table = $wpdb->prefix . 'sfs_hr_payslips';
        $runs_table     = $wpdb->prefix . 'sfs_hr_payroll_runs';

        // Verify run is approved or paid.
        $run_status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$runs_table} WHERE id = %d",
            $run_id
        ) );

        if ( ! in_array( $run_status, [ 'approved', 'paid' ], true ) ) {
            delete_transient( $lock_key );
            return [ 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 'run_not_approved' ];
        }

        // Get all payslips for this run's period.
        $period_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT period_id FROM {$runs_table} WHERE id = %d",
            $run_id
        ) );

        $payslip_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$payslips_table} WHERE period_id = %d",
            $period_id
        ) );

        $result = [ 'sent' => 0, 'failed' => 0, 'skipped' => 0 ];

        foreach ( $payslip_ids as $pid ) {
            // Check if already sent.
            $already_sent = $wpdb->get_var( $wpdb->prepare(
                "SELECT sent_at FROM {$payslips_table} WHERE id = %d",
                $pid
            ) );

            if ( $already_sent ) {
                $result['skipped']++;
                continue;
            }

            if ( self::send_email( (int) $pid ) ) {
                $result['sent']++;
            } else {
                $result['failed']++;
            }
        }

        delete_transient( $lock_key );

        do_action( 'sfs_hr_payslip_batch_sent', $run_id, $result );

        return $result;
    }
}
