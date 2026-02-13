<?php
/**
 * Frontend Settlement Tab
 *
 * Read-only view of employee's settlement information.
 * Redesigned with §10.1 design system — card-based layout.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SettlementTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own settlement information.', 'sfs-hr' ) . '</p>';
            return;
        }

        $employee_id = isset( $emp['id'] ) ? (int) $emp['id'] : 0;
        if ( $employee_id <= 0 ) {
            echo '<p>' . esc_html__( 'Employee record not found.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;
        $settle_table = $wpdb->prefix . 'sfs_hr_settlements';

        $settlements = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$settle_table} WHERE employee_id = %d ORDER BY id DESC",
                $employee_id
            ),
            ARRAY_A
        );

        // Header
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="my_settlements">' . esc_html__( 'My Settlements', 'sfs-hr' ) . '</h2>';
        echo '</div>';

        if ( empty( $settlements ) ) {
            echo '<div class="sfs-card"><div class="sfs-empty-state">';
            echo '<div class="sfs-empty-state-icon"><svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2" stroke="currentColor" fill="none" stroke-width="1.5"/><line x1="2" y1="10" x2="22" y2="10" stroke="currentColor" stroke-width="1.5"/></svg></div>';
            echo '<p class="sfs-empty-state-title">' . esc_html__( 'No settlement records', 'sfs-hr' ) . '</p>';
            echo '<p class="sfs-empty-state-text">' . esc_html__( 'Settlement information will appear here when applicable.', 'sfs-hr' ) . '</p>';
            echo '</div></div>';
            return;
        }

        foreach ( $settlements as $s ) {
            $this->render_settlement_card( $s );
        }
    }

    private function render_settlement_card( array $s ): void {
        $ref    = $s['request_number'] ?? '';
        $badge  = $this->status_badge( $s['status'] );
        $total  = (float) $s['total_settlement'];

        echo '<div class="sfs-card" style="margin-bottom:20px;">';

        // Card header
        echo '<div class="sfs-card-body" style="border-bottom:1px solid var(--sfs-border);">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;">';
        echo '<div>';
        echo '<h3 style="font-size:16px;font-weight:700;color:var(--sfs-text);margin:0;">';
        echo esc_html__( 'Settlement', 'sfs-hr' ) . ' ';
        echo esc_html( $ref ?: '#' . $s['id'] );
        echo '</h3>';
        echo '</div>';
        echo $badge;
        echo '</div></div>';

        // Quick info grid
        echo '<div class="sfs-card-body" style="border-bottom:1px solid var(--sfs-border);">';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;">';

        $this->info_tile( __( 'Last Working Day', 'sfs-hr' ), esc_html( $s['last_working_day'] ) );
        $this->info_tile( __( 'Years of Service', 'sfs-hr' ), number_format( (float) $s['years_of_service'], 2 ) . ' ' . esc_html__( 'years', 'sfs-hr' ) );
        $this->info_tile( __( 'Settlement Date', 'sfs-hr' ), esc_html( $s['settlement_date'] ) );

        echo '</div></div>';

        // Breakdown
        echo '<div class="sfs-card-body">';
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:0 0 12px;">' . esc_html__( 'Breakdown', 'sfs-hr' ) . '</h4>';

        $this->amount_row( __( 'Basic Salary', 'sfs-hr' ), $s['basic_salary'] );
        $this->amount_row( __( 'Gratuity', 'sfs-hr' ), $s['gratuity_amount'] );
        $this->amount_row(
            __( 'Leave Encashment', 'sfs-hr' ),
            $s['leave_encashment'],
            '(' . (int) $s['unused_leave_days'] . ' ' . __( 'days', 'sfs-hr' ) . ')'
        );
        $this->amount_row( __( 'Final Salary', 'sfs-hr' ), $s['final_salary'] );
        $this->amount_row( __( 'Other Allowances', 'sfs-hr' ), $s['other_allowances'] );

        if ( (float) $s['deductions'] > 0 ) {
            $note = ! empty( $s['deduction_notes'] ) ? $s['deduction_notes'] : '';
            $this->amount_row( __( 'Deductions', 'sfs-hr' ), $s['deductions'], $note, true );
        }

        // Total
        echo '<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0 4px;margin-top:8px;border-top:2px solid var(--sfs-border);">';
        echo '<strong style="font-size:15px;color:var(--sfs-text);">' . esc_html__( 'Total Settlement', 'sfs-hr' ) . '</strong>';
        echo '<strong style="font-size:18px;color:var(--sfs-primary);">' . esc_html( number_format( $total, 2 ) ) . '</strong>';
        echo '</div>';
        echo '</div>';

        // Clearance
        if ( $s['status'] !== 'pending' ) {
            echo '<div class="sfs-card-body" style="border-top:1px solid var(--sfs-border);">';
            echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:0 0 12px;">' . esc_html__( 'Clearance Status', 'sfs-hr' ) . '</h4>';
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;">';
            $this->clearance_item( __( 'Assets', 'sfs-hr' ), $s['asset_clearance_status'] );
            $this->clearance_item( __( 'Documents', 'sfs-hr' ), $s['document_clearance_status'] );
            $this->clearance_item( __( 'Finance', 'sfs-hr' ), $s['finance_clearance_status'] );
            echo '</div></div>';
        }

        // Payment info
        if ( $s['status'] === 'paid' && ! empty( $s['payment_date'] ) ) {
            echo '<div class="sfs-card-body" style="border-top:1px solid var(--sfs-border);">';
            echo '<div class="sfs-alert sfs-alert--success" style="margin-bottom:0;">';
            echo '<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" fill="none" stroke-width="2"/><polyline points="22 4 12 14.01 9 11.01" stroke="currentColor" fill="none" stroke-width="2"/></svg>';
            echo '<div><strong>' . esc_html__( 'Payment Completed', 'sfs-hr' ) . '</strong><br>';
            echo esc_html__( 'Date:', 'sfs-hr' ) . ' ' . esc_html( $s['payment_date'] );
            if ( ! empty( $s['payment_reference'] ) ) {
                echo ' · ' . esc_html__( 'Ref:', 'sfs-hr' ) . ' ' . esc_html( $s['payment_reference'] );
            }
            echo '</div></div></div>';
        }

        // HR note
        if ( ! empty( $s['approver_note'] ) ) {
            echo '<div class="sfs-card-body" style="border-top:1px solid var(--sfs-border);">';
            echo '<div class="sfs-alert sfs-alert--warning" style="margin-bottom:0;">';
            echo '<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" stroke="currentColor" fill="none" stroke-width="2"/></svg>';
            echo '<div><strong>' . esc_html__( 'Note from HR', 'sfs-hr' ) . '</strong><br>' . nl2br( esc_html( $s['approver_note'] ) ) . '</div>';
            echo '</div></div>';
        }

        echo '</div>'; // .sfs-card
    }

    private function info_tile( string $label, string $value ): void {
        echo '<div>';
        echo '<div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.03em;color:var(--sfs-text-muted);margin-bottom:4px;">' . esc_html( $label ) . '</div>';
        echo '<div style="font-size:15px;font-weight:600;color:var(--sfs-text);">' . $value . '</div>';
        echo '</div>';
    }

    private function amount_row( string $label, $amount, string $note = '', bool $is_deduction = false ): void {
        $color = $is_deduction ? 'var(--sfs-danger)' : 'var(--sfs-text)';
        echo '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--sfs-border);font-size:13px;">';
        echo '<span style="color:var(--sfs-text-muted);">' . esc_html( $label ) . '</span>';
        echo '<span style="font-weight:600;color:' . $color . ';">';
        echo ( $is_deduction ? '- ' : '' ) . esc_html( number_format( (float) $amount, 2 ) );
        if ( $note ) {
            echo ' <small style="font-weight:400;color:var(--sfs-text-muted);">' . esc_html( $note ) . '</small>';
        }
        echo '</span></div>';
    }

    private function clearance_item( string $label, string $status ): void {
        echo '<div style="text-align:center;">';
        echo '<div style="font-size:11px;font-weight:600;color:var(--sfs-text-muted);margin-bottom:6px;">' . esc_html( $label ) . '</div>';
        echo $this->status_badge( $status );
        echo '</div>';
    }

    private function status_badge( string $status ): string {
        $map = [
            'pending'  => 'pending',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'paid'     => 'active',
            'cleared'  => 'approved',
        ];
        $class = $map[ $status ] ?? 'pending';
        return '<span class="sfs-badge sfs-badge--' . esc_attr( $class ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
    }
}
