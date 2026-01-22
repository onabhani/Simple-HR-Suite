<?php
/**
 * Frontend Settlement Tab
 *
 * Read-only view of employee's settlement information.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SettlementTab
 *
 * Handles the settlement tab in the employee frontend profile.
 */
class SettlementTab implements TabInterface {

    /**
     * Render the settlement tab content
     *
     * @param array $emp Employee data array
     * @param int   $emp_id Employee ID
     * @return void
     */
    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own settlement information.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;

        $employee_id = isset( $emp['id'] ) ? (int) $emp['id'] : 0;
        if ( $employee_id <= 0 ) {
            echo '<p>' . esc_html__( 'Employee record not found.', 'sfs-hr' ) . '</p>';
            return;
        }

        $settle_table = $wpdb->prefix . 'sfs_hr_settlements';

        // Fetch settlements for this employee
        $settlements = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$settle_table} WHERE employee_id = %d ORDER BY id DESC",
                $employee_id
            ),
            ARRAY_A
        );

        echo '<div class="sfs-hr-settlement-tab" style="margin-top:24px;">';

        if ( empty( $settlements ) ) {
            echo '<div class="sfs-hr-alert" style="background:#f9f9f9;padding:20px;border-radius:4px;">';
            echo '<p>' . esc_html__( 'You do not have any settlement records yet.', 'sfs-hr' ) . '</p>';
            echo '</div>';
        } else {
            foreach ( $settlements as $settlement ) {
                $this->render_settlement_card( $settlement );
            }
        }

        echo '</div>'; // .sfs-hr-settlement-tab
    }

    /**
     * Render a single settlement card
     *
     * @param array $settlement Settlement record
     * @return void
     */
    private function render_settlement_card( array $settlement ): void {
        $status_badge = $this->settlement_status_badge( $settlement['status'] );
        $request_number = $settlement['request_number'] ?? '';

        echo '<div class="sfs-hr-settlement-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:20px;margin-bottom:20px;">';

        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">';
        echo '<h3 style="margin:0;">' . esc_html__( 'Settlement', 'sfs-hr' ) . ' ';
        if ( ! empty( $request_number ) ) {
            echo esc_html( $request_number );
        } else {
            echo '#' . esc_html( $settlement['id'] );
        }
        echo '</h3>';
        echo $status_badge;
        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:15px;margin-bottom:15px;">';

        echo '<div>';
        echo '<div style="font-weight:600;margin-bottom:5px;">' . esc_html__( 'Last Working Day:', 'sfs-hr' ) . '</div>';
        echo '<div>' . esc_html( $settlement['last_working_day'] ) . '</div>';
        echo '</div>';

        echo '<div>';
        echo '<div style="font-weight:600;margin-bottom:5px;">' . esc_html__( 'Years of Service:', 'sfs-hr' ) . '</div>';
        echo '<div>' . esc_html( number_format( $settlement['years_of_service'], 2 ) ) . ' ' . esc_html__( 'years', 'sfs-hr' ) . '</div>';
        echo '</div>';

        echo '<div>';
        echo '<div style="font-weight:600;margin-bottom:5px;">' . esc_html__( 'Settlement Date:', 'sfs-hr' ) . '</div>';
        echo '<div>' . esc_html( $settlement['settlement_date'] ) . '</div>';
        echo '</div>';

        echo '</div>'; // grid

        // Settlement breakdown
        $this->render_settlement_breakdown( $settlement );

        // Clearance status
        if ( $settlement['status'] !== 'pending' ) {
            $this->render_clearance_status( $settlement );
        }

        // Payment information
        if ( $settlement['status'] === 'paid' && ! empty( $settlement['payment_date'] ) ) {
            $this->render_payment_info( $settlement );
        }

        // Approver note
        if ( ! empty( $settlement['approver_note'] ) ) {
            echo '<div style="margin-top:15px;padding:15px;background:#fff3cd;border-radius:4px;">';
            echo '<div style="font-weight:600;margin-bottom:5px;">' . esc_html__( 'Note from HR:', 'sfs-hr' ) . '</div>';
            echo '<div>' . nl2br( esc_html( $settlement['approver_note'] ) ) . '</div>';
            echo '</div>';
        }

        echo '</div>'; // settlement-card
    }

    /**
     * Render settlement breakdown section
     *
     * @param array $settlement Settlement record
     * @return void
     */
    private function render_settlement_breakdown( array $settlement ): void {
        echo '<div style="background:#f9f9f9;padding:15px;border-radius:4px;margin-top:15px;">';
        echo '<h4 style="margin-top:0;">' . esc_html__( 'Settlement Breakdown', 'sfs-hr' ) . '</h4>';

        echo '<div style="display:grid;gap:10px;">';

        $this->render_settlement_row( __( 'Basic Salary:', 'sfs-hr' ), number_format( $settlement['basic_salary'], 2 ) );
        $this->render_settlement_row( __( 'Gratuity Amount:', 'sfs-hr' ), number_format( $settlement['gratuity_amount'], 2 ) );
        $this->render_settlement_row(
            __( 'Leave Encashment:', 'sfs-hr' ),
            number_format( $settlement['leave_encashment'], 2 ) . ' (' . $settlement['unused_leave_days'] . ' ' . __( 'days', 'sfs-hr' ) . ')'
        );
        $this->render_settlement_row( __( 'Final Salary:', 'sfs-hr' ), number_format( $settlement['final_salary'], 2 ) );
        $this->render_settlement_row( __( 'Other Allowances:', 'sfs-hr' ), number_format( $settlement['other_allowances'], 2 ) );

        if ( $settlement['deductions'] > 0 ) {
            $deduction_text = number_format( $settlement['deductions'], 2 );
            if ( ! empty( $settlement['deduction_notes'] ) ) {
                $deduction_text .= '<br><small style="color:#666;">' . esc_html( $settlement['deduction_notes'] ) . '</small>';
            }
            $this->render_settlement_row( __( 'Deductions:', 'sfs-hr' ), $deduction_text, true );
        }

        echo '<div style="border-top:2px solid #ddd;padding-top:10px;margin-top:10px;">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;">';
        echo '<strong style="font-size:16px;">' . esc_html__( 'Total Settlement:', 'sfs-hr' ) . '</strong>';
        echo '<strong style="font-size:18px;color:#0073aa;">' . esc_html( number_format( $settlement['total_settlement'], 2 ) ) . '</strong>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // grid
        echo '</div>'; // breakdown
    }

    /**
     * Render clearance status section
     *
     * @param array $settlement Settlement record
     * @return void
     */
    private function render_clearance_status( array $settlement ): void {
        echo '<div style="margin-top:15px;">';
        echo '<h4>' . esc_html__( 'Clearance Status', 'sfs-hr' ) . '</h4>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));gap:10px;">';

        echo '<div>';
        echo '<div style="font-weight:600;margin-bottom:5px;">' . esc_html__( 'Assets:', 'sfs-hr' ) . '</div>';
        echo $this->settlement_status_badge( $settlement['asset_clearance_status'] );
        echo '</div>';

        echo '<div>';
        echo '<div style="font-weight:600;margin-bottom:5px;">' . esc_html__( 'Documents:', 'sfs-hr' ) . '</div>';
        echo $this->settlement_status_badge( $settlement['document_clearance_status'] );
        echo '</div>';

        echo '<div>';
        echo '<div style="font-weight:600;margin-bottom:5px;">' . esc_html__( 'Finance:', 'sfs-hr' ) . '</div>';
        echo $this->settlement_status_badge( $settlement['finance_clearance_status'] );
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    /**
     * Render payment information section
     *
     * @param array $settlement Settlement record
     * @return void
     */
    private function render_payment_info( array $settlement ): void {
        echo '<div style="background:#d4edda;color:#155724;padding:15px;border-radius:4px;margin-top:15px;">';
        echo '<div style="font-weight:600;margin-bottom:5px;">' . esc_html__( 'Payment Information', 'sfs-hr' ) . '</div>';
        echo '<div>' . esc_html__( 'Payment Date:', 'sfs-hr' ) . ' ' . esc_html( $settlement['payment_date'] ) . '</div>';
        if ( ! empty( $settlement['payment_reference'] ) ) {
            echo '<div>' . esc_html__( 'Reference:', 'sfs-hr' ) . ' ' . esc_html( $settlement['payment_reference'] ) . '</div>';
        }
        echo '</div>';
    }

    /**
     * Render settlement status badge
     *
     * @param string $status Settlement status
     * @return string HTML for the status badge
     */
    private function settlement_status_badge( string $status ): string {
        $colors = [
            'pending'  => '#f0ad4e',
            'approved' => '#5cb85c',
            'rejected' => '#d9534f',
            'paid'     => '#0073aa',
        ];

        $color = $colors[ $status ] ?? '#777';
        return sprintf(
            '<span style="background:%s;color:#fff;padding:6px 12px;border-radius:3px;font-size:12px;font-weight:500;">%s</span>',
            esc_attr( $color ),
            esc_html( ucfirst( $status ) )
        );
    }

    /**
     * Render a settlement row
     *
     * @param string $label      Row label
     * @param string $value      Row value
     * @param bool   $allow_html Whether to allow HTML in value
     * @return void
     */
    private function render_settlement_row( string $label, string $value, bool $allow_html = false ): void {
        echo '<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #ddd;">';
        echo '<div style="font-weight:500;">' . esc_html( $label ) . '</div>';
        if ( $allow_html ) {
            echo '<div>' . $value . '</div>';
        } else {
            echo '<div>' . esc_html( $value ) . '</div>';
        }
        echo '</div>';
    }
}
