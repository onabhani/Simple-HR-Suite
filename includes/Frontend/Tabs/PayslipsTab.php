<?php
/**
 * Payslips Tab — Employee self-service payslip viewer.
 *
 * Shows monthly payslip cards with period, gross, deductions, net.
 * Expandable detail view for salary component breakdown.
 * Print-friendly layout per payslip.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PayslipsTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own payslips.', 'sfs-hr' ) . '</p>';
            return;
        }

        $employee_id = isset( $emp['id'] ) ? (int) $emp['id'] : 0;
        if ( $employee_id <= 0 ) {
            echo '<p>' . esc_html__( 'Employee record not found.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;
        $payslips_table = $wpdb->prefix . 'sfs_hr_payslips';
        $items_table    = $wpdb->prefix . 'sfs_hr_payroll_items';
        $periods_table  = $wpdb->prefix . 'sfs_hr_payroll_periods';

        // Check table exists.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$payslips_table}'" ) !== $payslips_table ) {
            $this->render_empty_state();
            return;
        }

        $payslips = $wpdb->get_results( $wpdb->prepare(
            "SELECT ps.*, p.name AS period_name, p.start_date, p.end_date, p.pay_date,
                    i.base_salary, i.gross_salary, i.total_deductions, i.net_salary,
                    i.components_json
             FROM {$payslips_table} ps
             LEFT JOIN {$periods_table} p ON p.id = ps.period_id
             LEFT JOIN {$items_table} i ON i.id = ps.payroll_item_id
             WHERE ps.employee_id = %d
             ORDER BY p.start_date DESC
             LIMIT 24",
            $employee_id
        ), ARRAY_A );

        // Decode components.
        foreach ( $payslips as &$ps ) {
            if ( ! empty( $ps['components_json'] ) ) {
                $ps['components'] = json_decode( $ps['components_json'], true );
                unset( $ps['components_json'] );
            } else {
                $ps['components'] = [];
            }
        }
        unset( $ps );

        // Header.
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="payslips">' . esc_html__( 'My Payslips', 'sfs-hr' ) . '</h2>';
        echo '</div>';

        if ( empty( $payslips ) ) {
            $this->render_empty_state();
            return;
        }

        // KPI summary.
        $this->render_kpi_summary( $payslips );

        // Payslip cards.
        foreach ( $payslips as $idx => $ps ) {
            $this->render_payslip_card( $ps, $idx );
        }

        // Print styles + expand/collapse JS.
        $this->render_scripts();
    }

    private function render_kpi_summary( array $payslips ): void {
        $latest_net     = (float) ( $payslips[0]['net_salary'] ?? 0 );
        $total_count    = count( $payslips );
        $year_gross     = 0.0;
        $year_deductions = 0.0;
        $current_year   = (int) current_time( 'Y' );

        foreach ( $payslips as $ps ) {
            $ps_year = ! empty( $ps['start_date'] ) ? (int) substr( $ps['start_date'], 0, 4 ) : 0;
            if ( $ps_year === $current_year ) {
                $year_gross     += (float) ( $ps['gross_salary'] ?? 0 );
                $year_deductions += (float) ( $ps['total_deductions'] ?? 0 );
            }
        }

        echo '<div class="sfs-kpi-grid" style="margin-bottom:24px;">';

        // Latest Net Pay.
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:var(--sfs-primary-light,#e0f2fe);color:var(--sfs-primary,#0284c7);">';
        echo '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>';
        echo '</div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="latest_net_pay">' . esc_html__( 'Latest Net Pay', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . esc_html( number_format( $latest_net, 2 ) ) . '</div>';
        echo '</div>';

        // Total Payslips.
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#fef3c7;color:#d97706;">';
        echo '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';
        echo '</div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="total_payslips">' . esc_html__( 'Total Payslips', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . esc_html( (string) $total_count ) . '</div>';
        echo '</div>';

        // YTD Gross.
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#dcfce7;color:#16a34a;">';
        echo '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>';
        echo '</div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="ytd_gross">' . esc_html__( 'YTD Gross', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . esc_html( number_format( $year_gross, 2 ) ) . '</div>';
        echo '</div>';

        // YTD Deductions.
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#fee2e2;color:#dc2626;">';
        echo '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';
        echo '</div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="ytd_deductions">' . esc_html__( 'YTD Deductions', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . esc_html( number_format( $year_deductions, 2 ) ) . '</div>';
        echo '</div>';

        echo '</div>';
    }

    private function render_payslip_card( array $ps, int $idx ): void {
        $period    = esc_html( $ps['period_name'] ?? __( 'Unknown Period', 'sfs-hr' ) );
        $base      = (float) ( $ps['base_salary'] ?? 0 );
        $gross     = (float) ( $ps['gross_salary'] ?? 0 );
        $deduct    = (float) ( $ps['total_deductions'] ?? 0 );
        $net       = (float) ( $ps['net_salary'] ?? 0 );
        $start     = $ps['start_date'] ?? '';
        $end       = $ps['end_date'] ?? '';
        $pay_date  = $ps['pay_date'] ?? '';
        $components = $ps['components'] ?? [];
        $card_id   = 'sfs-payslip-' . $idx;

        echo '<div class="sfs-card sfs-payslip-card" style="margin-bottom:16px;" id="' . esc_attr( $card_id ) . '">';

        // Card header.
        echo '<div class="sfs-card-body" style="border-bottom:1px solid var(--sfs-border);cursor:pointer;" onclick="sfsTogglePayslip(\'' . esc_js( $card_id ) . '\')">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">';

        // Period name + dates.
        echo '<div>';
        echo '<h3 style="font-size:16px;font-weight:700;color:var(--sfs-text);margin:0;">' . $period . '</h3>';
        if ( $start && $end ) {
            echo '<span style="font-size:13px;color:var(--sfs-text-muted,#6b7280);">' . esc_html( $start ) . ' — ' . esc_html( $end ) . '</span>';
        }
        echo '</div>';

        // Net salary highlight.
        echo '<div style="text-align:right;">';
        echo '<div style="font-size:20px;font-weight:800;color:var(--sfs-primary,#0284c7);">' . esc_html( number_format( $net, 2 ) ) . '</div>';
        echo '<span class="sfs-badge sfs-badge--success" style="font-size:11px;" data-i18n-key="net_pay">' . esc_html__( 'Net Pay', 'sfs-hr' ) . '</span>';
        echo '</div>';

        echo '</div>';

        // Summary row.
        echo '<div style="display:flex;gap:24px;margin-top:12px;flex-wrap:wrap;">';
        $this->mini_metric( __( 'Base', 'sfs-hr' ), number_format( $base, 2 ) );
        $this->mini_metric( __( 'Gross', 'sfs-hr' ), number_format( $gross, 2 ), 'color:#16a34a;' );
        $this->mini_metric( __( 'Deductions', 'sfs-hr' ), number_format( $deduct, 2 ), 'color:#dc2626;' );
        if ( $pay_date ) {
            $this->mini_metric( __( 'Pay Date', 'sfs-hr' ), $pay_date );
        }
        echo '</div>';

        // Expand arrow.
        echo '<div style="text-align:center;margin-top:8px;">';
        echo '<svg class="sfs-payslip-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="var(--sfs-text-muted,#9ca3af)" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';
        echo '</div>';

        echo '</div>';

        // Expandable detail section (hidden by default).
        echo '<div class="sfs-payslip-detail" style="display:none;">';

        if ( ! empty( $components ) ) {
            // Earnings.
            $earnings   = array_filter( $components, fn( $c ) => ( $c['type'] ?? '' ) === 'earning' );
            $deductions = array_filter( $components, fn( $c ) => ( $c['type'] ?? '' ) === 'deduction' );

            if ( ! empty( $earnings ) ) {
                echo '<div class="sfs-card-body" style="border-bottom:1px solid var(--sfs-border);">';
                echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:0 0 10px;" data-i18n-key="earnings">' . esc_html__( 'Earnings', 'sfs-hr' ) . '</h4>';
                foreach ( $earnings as $c ) {
                    $this->component_row( $c['name'] ?? __( 'Item', 'sfs-hr' ), (float) ( $c['amount'] ?? 0 ), '#16a34a' );
                }
                echo '<div style="display:flex;justify-content:space-between;padding-top:8px;border-top:1px solid var(--sfs-border);font-weight:700;color:var(--sfs-text);">';
                echo '<span>' . esc_html__( 'Total Earnings', 'sfs-hr' ) . '</span>';
                echo '<span style="color:#16a34a;">' . esc_html( number_format( $gross, 2 ) ) . '</span>';
                echo '</div>';
                echo '</div>';
            }

            if ( ! empty( $deductions ) ) {
                echo '<div class="sfs-card-body" style="border-bottom:1px solid var(--sfs-border);">';
                echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:0 0 10px;" data-i18n-key="deductions">' . esc_html__( 'Deductions', 'sfs-hr' ) . '</h4>';
                foreach ( $deductions as $c ) {
                    $this->component_row( $c['name'] ?? __( 'Item', 'sfs-hr' ), (float) ( $c['amount'] ?? 0 ), '#dc2626' );
                }
                echo '<div style="display:flex;justify-content:space-between;padding-top:8px;border-top:1px solid var(--sfs-border);font-weight:700;color:var(--sfs-text);">';
                echo '<span>' . esc_html__( 'Total Deductions', 'sfs-hr' ) . '</span>';
                echo '<span style="color:#dc2626;">-' . esc_html( number_format( $deduct, 2 ) ) . '</span>';
                echo '</div>';
                echo '</div>';
            }
        }

        // Net summary row.
        echo '<div class="sfs-card-body" style="background:var(--sfs-primary-light,#f0f9ff);">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;">';
        echo '<span style="font-size:16px;font-weight:800;color:var(--sfs-text);" data-i18n-key="net_salary">' . esc_html__( 'Net Salary', 'sfs-hr' ) . '</span>';
        echo '<span style="font-size:20px;font-weight:800;color:var(--sfs-primary,#0284c7);">' . esc_html( number_format( $net, 2 ) ) . '</span>';
        echo '</div></div>';

        // Print button.
        echo '<div class="sfs-card-body" style="text-align:center;">';
        echo '<button type="button" class="sfs-btn sfs-btn--primary" onclick="sfsPrintPayslip(\'' . esc_js( $card_id ) . '\')" style="gap:6px;">';
        echo '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>';
        echo esc_html__( 'Print Payslip', 'sfs-hr' );
        echo '</button>';
        echo '</div>';

        echo '</div>'; // .sfs-payslip-detail
        echo '</div>'; // .sfs-card
    }

    private function mini_metric( string $label, string $value, string $style = '' ): void {
        echo '<div style="min-width:80px;">';
        echo '<div style="font-size:12px;color:var(--sfs-text-muted,#6b7280);">' . esc_html( $label ) . '</div>';
        echo '<div style="font-size:14px;font-weight:600;color:var(--sfs-text);' . esc_attr( $style ) . '">' . esc_html( $value ) . '</div>';
        echo '</div>';
    }

    private function component_row( string $name, float $amount, string $color ): void {
        echo '<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:14px;">';
        echo '<span style="color:var(--sfs-text);">' . esc_html( $name ) . '</span>';
        echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600;">' . esc_html( number_format( $amount, 2 ) ) . '</span>';
        echo '</div>';
    }

    private function render_empty_state(): void {
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="payslips">' . esc_html__( 'My Payslips', 'sfs-hr' ) . '</h2>';
        echo '</div>';
        echo '<div class="sfs-card"><div class="sfs-empty-state">';
        echo '<div class="sfs-empty-state-icon">';
        echo '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" fill="none" stroke-width="1.5"/><polyline points="14 2 14 8 20 8" stroke="currentColor" fill="none" stroke-width="1.5"/><line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="1.5"/><line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="1.5"/></svg>';
        echo '</div>';
        echo '<p class="sfs-empty-state-title">' . esc_html__( 'No payslips yet', 'sfs-hr' ) . '</p>';
        echo '<p class="sfs-empty-state-text">' . esc_html__( 'Your payslips will appear here once payroll is processed.', 'sfs-hr' ) . '</p>';
        echo '</div></div>';
    }

    private function render_scripts(): void {
        ?>
        <script>
        function sfsTogglePayslip(cardId) {
            var card = document.getElementById(cardId);
            if (!card) return;
            var detail = card.querySelector('.sfs-payslip-detail');
            var arrow = card.querySelector('.sfs-payslip-arrow');
            if (!detail) return;
            var isHidden = detail.style.display === 'none';
            detail.style.display = isHidden ? 'block' : 'none';
            if (arrow) arrow.style.transform = isHidden ? 'rotate(180deg)' : '';
        }

        function sfsPrintPayslip(cardId) {
            var card = document.getElementById(cardId);
            if (!card) return;
            // Show detail before printing.
            var detail = card.querySelector('.sfs-payslip-detail');
            if (detail) detail.style.display = 'block';

            var printWin = window.open('', '_blank', 'width=800,height=600');
            if (!printWin) { alert('Please allow popups.'); return; }

            var styles = '<style>'
                + 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:24px;color:#1f2937;}'
                + '.sfs-badge,.sfs-payslip-arrow,.sfs-btn{display:none!important;}'
                + '.sfs-card{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;}'
                + '.sfs-card-body{padding:16px;}'
                + '.sfs-payslip-detail{display:block!important;}'
                + '@media print{.no-print{display:none;}}'
                + '</style>';

            printWin.document.write('<!DOCTYPE html><html><head><title>' + document.title + '</title>' + styles + '</head><body>');
            printWin.document.write(card.outerHTML);
            printWin.document.write('</body></html>');
            printWin.document.close();
            printWin.focus();
            setTimeout(function(){ printWin.print(); printWin.close(); }, 300);
        }
        </script>
        <?php
    }
}
