<?php
/**
 * Loans Tab - Frontend loans dashboard and request form
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LoansTab
 *
 * Handles the Loans tab rendering in the employee profile.
 * - Loan request form
 * - Loan history display with payment schedules
 */
class LoansTab implements TabInterface {

    /**
     * Render the loans tab content
     *
     * @param array $emp Employee data array
     * @param int   $emp_id Employee ID
     * @return void
     */
    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own loan information.', 'sfs-hr' ) . '</p>';
            return;
        }

        // Check if loans module is enabled
        $settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();
        if ( ! $settings['show_in_my_profile'] ) {
            echo '<div style="padding:20px;background:#fff;border:1px solid #ddd;border-radius:4px;margin-top:20px;">';
            echo '<p>' . esc_html__( 'Loans module is currently not available.', 'sfs-hr' ) . '</p>';
            echo '</div>';
            return;
        }

        global $wpdb;
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';

        // Get employee's loans
        $loans = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$loans_table}
             WHERE employee_id = %d
             ORDER BY created_at DESC",
            $emp_id
        ) );

        echo '<div class="sfs-hr-loans-tab" style="padding:20px;background:#fff;border:1px solid #ddd;border-radius:4px;margin-top:20px;">';
        echo '<h4 style="margin:0 0 16px;" data-i18n-key="my_loans">' . esc_html__( 'My Loans', 'sfs-hr' ) . '</h4>';

        $this->render_styles();

        // Request new loan section (if enabled)
        if ( $settings['allow_employee_requests'] ) {
            $this->render_request_form( $emp_id, $settings );
        }

        // Loan History section
        echo '<h5 style="margin:24px 0 12px 0;font-size:15px;font-weight:600;color:inherit;" data-i18n-key="loan_history">' . esc_html__( 'Loan history', 'sfs-hr' ) . '</h5>';

        if ( empty( $loans ) ) {
            echo '<p style="color:inherit;" data-i18n-key="you_have_no_loan_records">' . esc_html__( 'You have no loan records.', 'sfs-hr' ) . '</p>';
        } else {
            $loan_data = $this->prepare_loan_data( $loans, $payments_table );
            $this->render_desktop_table( $loan_data );
            $this->render_mobile_cards( $loan_data );
        }

        echo '</div>'; // .sfs-hr-loans-tab
    }

    /**
     * Render tab-specific CSS styles
     */
    private function render_styles(): void {
        echo '<style>
            .sfs-hr-loans-desktop { display: block; }
            .sfs-hr-loans-mobile { display: none; }

            @media (max-width: 782px) {
                .sfs-hr-loans-desktop { display: none; }
                .sfs-hr-loans-mobile { display: block; }
            }

            .sfs-hr-loan-card {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
                margin-bottom: 12px;
                padding: 0;
            }

            .sfs-hr-loan-summary {
                padding: 12px;
                cursor: pointer;
                display: flex;
                justify-content: space-between;
                align-items: center;
                list-style: none;
            }

            .sfs-hr-loan-summary::-webkit-details-marker {
                display: none;
            }

            .sfs-hr-loan-summary-title {
                font-weight: 600;
                color: #1d2327;
            }

            .sfs-hr-loan-body {
                padding: 0 12px 12px;
                border-top: 1px solid #f0f0f0;
            }

            .sfs-hr-loan-field-row {
                display: flex;
                padding: 8px 0;
                border-bottom: 1px solid #f5f5f5;
            }

            .sfs-hr-loan-field-row:last-child {
                border-bottom: none;
            }

            .sfs-hr-loan-field-label {
                font-weight: 600;
                color: #646970;
                min-width: 120px;
            }

            .sfs-hr-loan-field-value {
                color: #1d2327;
                flex: 1;
            }
        </style>';
    }

    /**
     * Render loan request form
     */
    private function render_request_form( int $emp_id, array $settings ): void {
        echo '<div id="sfs-loan-request-form-frontend" class="sfs-hr-loan-request-form" style="background:var(--sfs-surface, #f9f9f9);padding:16px;border:1px solid var(--sfs-border, #ddd);border-radius:8px;margin-bottom:16px;">';
        echo '<h5 style="margin:0 0 12px;font-size:15px;font-weight:600;color:inherit;" data-i18n-key="request_new_loan">' . esc_html__( 'Request new loan', 'sfs-hr' ) . '</h5>';

        // Show messages
        if ( isset( $_GET['loan_request'] ) ) {
            if ( $_GET['loan_request'] === 'success' ) {
                echo '<div style="padding:12px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;margin-bottom:16px;color:#155724;">';
                esc_html_e( 'Loan request submitted successfully!', 'sfs-hr' );
                echo '</div>';
            } elseif ( $_GET['loan_request'] === 'error' ) {
                $error = isset( $_GET['error'] ) ? urldecode( $_GET['error'] ) : __( 'Failed to submit request.', 'sfs-hr' );
                echo '<div style="padding:12px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;margin-bottom:16px;color:#721c24;">';
                echo esc_html( $error );
                echo '</div>';
            }
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'sfs_hr_submit_loan_request_' . $emp_id );
        echo '<input type="hidden" name="action" value="sfs_hr_submit_loan_request" />';
        echo '<input type="hidden" name="employee_id" value="' . (int) $emp_id . '" />';

        echo '<div style="margin-bottom:16px;">';
        echo '<label style="display:block;margin-bottom:4px;font-weight:600;"><span data-i18n-key="loan_amount_sar">' . esc_html__( 'Loan Amount (SAR)', 'sfs-hr' ) . '</span> <span style="color:red;">*</span></label>';
        echo '<input type="number" name="principal_amount" step="0.01" min="1" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;" />';
        if ( $settings['max_loan_amount'] > 0 ) {
            echo '<p style="margin:4px 0 0;font-size:12px;color:#666;">' .
                 sprintf( esc_html__( 'Maximum: %s SAR', 'sfs-hr' ), number_format( $settings['max_loan_amount'], 2 ) ) .
                 '</p>';
        }
        echo '</div>';

        echo '<div style="margin-bottom:16px;">';
        echo '<label style="display:block;margin-bottom:4px;font-weight:600;"><span data-i18n-key="monthly_installment_sar">' . esc_html__( 'Monthly Installment Amount (SAR)', 'sfs-hr' ) . '</span> <span style="color:red;">*</span></label>';
        echo '<input type="number" name="monthly_amount" id="monthly_amount_frontend" step="0.01" min="1" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;" oninput="calculateLoanFrontend()" />';
        echo '<p style="margin:4px 0 0;font-size:12px;color:#666;" data-i18n-key="how_much_you_can_pay">' . esc_html__( 'How much you can pay each month', 'sfs-hr' ) . '</p>';
        echo '<p id="calculated_plan_frontend" style="margin:8px 0 0;font-weight:bold;color:#0073aa;"></p>';
        echo '</div>';

        $this->render_calculator_script();

        echo '<div style="margin-bottom:16px;">';
        echo '<label style="display:block;margin-bottom:4px;font-weight:600;"><span data-i18n-key="reason_for_loan">' . esc_html__( 'Reason for Loan', 'sfs-hr' ) . '</span> <span style="color:red;">*</span></label>';
        echo '<textarea name="reason" rows="3" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;"></textarea>';
        echo '</div>';

        echo '<style>
            @media (max-width: 600px) {
                #sfs-loan-request-form-frontend textarea {
                    min-height: 80px !important;
                    height: auto !important;
                }
                #sfs-loan-request-form-frontend input[type="number"] {
                    font-size: 16px;
                }
            }
        </style>';

        echo '<div style="margin-top:16px;">';
        echo '<button type="submit" class="sfs-hr-lf-submit" style="width:100%;padding:12px 16px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;" data-i18n-key="submit_loan_request">' .
             esc_html__( 'Submit loan request', 'sfs-hr' ) .
             '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    /**
     * Render loan calculator JavaScript
     */
    private function render_calculator_script(): void {
        $i18n_would_require = esc_js( __( 'Would require', 'sfs-hr' ) );
        $i18n_months_max    = esc_js( __( 'months (maximum is 60). Please increase monthly amount.', 'sfs-hr' ) );
        $i18n_final_payment = esc_js( __( 'final payment', 'sfs-hr' ) );
        $i18n_sar_total     = esc_js( __( 'SAR total', 'sfs-hr' ) );
        $i18n_monthly_of    = esc_js( __( 'monthly payments of', 'sfs-hr' ) );
        $i18n_months        = esc_js( __( 'months', 'sfs-hr' ) );
        echo '<script>
var _li18n = {
    would_require: "' . $i18n_would_require . '",
    months_max: "' . $i18n_months_max . '",
    final_payment: "' . $i18n_final_payment . '",
    sar_total: "' . $i18n_sar_total . '",
    monthly_of: "' . $i18n_monthly_of . '",
    months: "' . $i18n_months . '"
};
function calculateLoanFrontend() {
    var principal = parseFloat(document.querySelector(\'input[name="principal_amount"]\').value) || 0;
    var monthly = parseFloat(document.getElementById("monthly_amount_frontend").value) || 0;
    var display = document.getElementById("calculated_plan_frontend");

    if (principal > 0 && monthly > 0) {
        var fullMonths = Math.floor(principal / monthly);
        var lastPayment = principal - (fullMonths * monthly);

        if (lastPayment > 0) {
            var totalMonths = fullMonths + 1;
            var totalPaid = principal;

            if (totalMonths > 60) {
                display.textContent = "⚠️ " + _li18n.would_require + " " + totalMonths + " " + _li18n.months_max;
                display.style.color = "#dc3545";
            } else {
                display.textContent = fullMonths + " × " + monthly.toFixed(2) + " SAR + " + _li18n.final_payment + " " + lastPayment.toFixed(2) + " SAR = " + totalPaid.toFixed(2) + " " + _li18n.sar_total + " (" + totalMonths + " " + _li18n.months + ")";
                display.style.color = "#0073aa";
            }
        } else {
            var months = fullMonths;
            if (months > 60) {
                display.textContent = "⚠️ " + _li18n.would_require + " " + months + " " + _li18n.months_max;
                display.style.color = "#dc3545";
            } else {
                display.textContent = months + " " + _li18n.monthly_of + " " + monthly.toFixed(2) + " SAR = " + principal.toFixed(2) + " " + _li18n.sar_total;
                display.style.color = "#0073aa";
            }
        }
    } else {
        display.textContent = "";
    }
}

document.addEventListener("DOMContentLoaded", function() {
    var principalInput = document.querySelector(\'input[name="principal_amount"]\');
    if (principalInput) {
        principalInput.addEventListener("input", calculateLoanFrontend);
    }
});
</script>';
    }

    /**
     * Prepare loan data with payments and approver info
     */
    private function prepare_loan_data( array $loans, string $payments_table ): array {
        global $wpdb;
        $loan_data = [];

        foreach ( $loans as $loan ) {
            $paid_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$payments_table} WHERE loan_id = %d AND status = 'paid'",
                $loan->id
            ) );

            $payments = [];
            if ( in_array( $loan->status, [ 'active', 'completed' ] ) ) {
                $payments = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$payments_table} WHERE loan_id = %d ORDER BY sequence ASC",
                    $loan->id
                ) );
            }

            $approver_info = '';
            if ( $loan->status === 'rejected' && ! empty( $loan->rejected_by ) ) {
                $rejected_user = get_user_by( 'id', (int) $loan->rejected_by );
                $approver_info = $rejected_user ? $rejected_user->display_name : '';
            } elseif ( $loan->status === 'active' || $loan->status === 'completed' ) {
                if ( ! empty( $loan->approved_finance_by ) ) {
                    $finance_user = get_user_by( 'id', (int) $loan->approved_finance_by );
                    $approver_info = $finance_user ? $finance_user->display_name : '';
                }
            }

            $loan_data[] = [
                'loan' => $loan,
                'paid_count' => $paid_count,
                'payments' => $payments,
                'approver_info' => $approver_info,
            ];
        }

        return $loan_data;
    }

    /**
     * Render desktop loans table
     */
    private function render_desktop_table( array $loan_data ): void {
        echo '<div class="sfs-hr-loans-desktop">';
        echo '<table class="sfs-hr-table" style="width:100%;border-collapse:collapse;margin-top:8px;border:1px solid #ddd;">';
        echo '<thead>';
        echo '<tr style="background:#f5f5f5;">';
        echo '<th style="padding:12px;text-align:left;border:1px solid #ddd;" data-i18n-key="loan_number">' . esc_html__( 'Loan #', 'sfs-hr' ) . '</th>';
        echo '<th style="padding:12px;text-align:left;border:1px solid #ddd;" data-i18n-key="amount">' . esc_html__( 'Amount', 'sfs-hr' ) . '</th>';
        echo '<th style="padding:12px;text-align:left;border:1px solid #ddd;" data-i18n-key="remaining">' . esc_html__( 'Remaining', 'sfs-hr' ) . '</th>';
        echo '<th style="padding:12px;text-align:left;border:1px solid #ddd;" data-i18n-key="installments">' . esc_html__( 'Installments', 'sfs-hr' ) . '</th>';
        echo '<th style="padding:12px;text-align:left;border:1px solid #ddd;" data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '<th style="padding:12px;text-align:left;border:1px solid #ddd;" data-i18n-key="requested">' . esc_html__( 'Requested', 'sfs-hr' ) . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ( $loan_data as $data ) {
            $loan = $data['loan'];
            $paid_count = $data['paid_count'];
            $payments = $data['payments'];

            echo '<tr>';
            echo '<td style="padding:12px;border:1px solid #ddd;"><strong>' . esc_html( $loan->loan_number ) . '</strong></td>';
            echo '<td style="padding:12px;border:1px solid #ddd;">' . number_format( (float) $loan->principal_amount, 2 ) . ' ' . esc_html( $loan->currency ) . '</td>';
            echo '<td style="padding:12px;border:1px solid #ddd;">' . number_format( (float) $loan->remaining_balance, 2 ) . ' ' . esc_html( $loan->currency ) . '</td>';
            echo '<td style="padding:12px;border:1px solid #ddd;">' . (int) $paid_count . ' / ' . (int) $loan->installments_count . '</td>';
            echo '<td style="padding:12px;border:1px solid #ddd;">' . $this->get_loan_status_badge( $loan->status ) . '</td>';
            echo '<td style="padding:12px;border:1px solid #ddd;">' . esc_html( wp_date( 'M j, Y', strtotime( $loan->created_at ) ) ) . '</td>';
            echo '</tr>';

            // Details row
            echo '<tr>';
            echo '<td colspan="6" style="padding:12px;border:1px solid #ddd;background:#f9f9f9;">';
            echo '<p style="margin:0 0 8px;"><strong data-i18n-key="reason">' . esc_html__( 'Reason:', 'sfs-hr' ) . '</strong> ' . esc_html( $loan->reason ) . '</p>';

            if ( $loan->status === 'rejected' ) {
                if ( ! empty( $data['approver_info'] ) ) {
                    echo '<p style="margin:0 0 8px;color:#dc3545;"><strong data-i18n-key="rejected_by">' . esc_html__( 'Rejected by:', 'sfs-hr' ) . '</strong> ' . esc_html( $data['approver_info'] ) . '</p>';
                }
                if ( $loan->rejection_reason ) {
                    echo '<p style="margin:0;color:#dc3545;"><strong data-i18n-key="rejection_reason">' . esc_html__( 'Rejection Reason:', 'sfs-hr' ) . '</strong> ' . esc_html( $loan->rejection_reason ) . '</p>';
                }
            } elseif ( in_array( $loan->status, [ 'active', 'completed' ], true ) && ! empty( $data['approver_info'] ) ) {
                echo '<p style="margin:0 0 8px;color:#28a745;"><strong data-i18n-key="approved_by">' . esc_html__( 'Approved by:', 'sfs-hr' ) . '</strong> ' . esc_html( $data['approver_info'] ) . '</p>';
            }

            if ( ! empty( $payments ) ) {
                $this->render_payment_schedule( $payments );
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    /**
     * Render payment schedule table
     */
    private function render_payment_schedule( array $payments ): void {
        echo '<h5 style="margin:12px 0 8px;" data-i18n-key="payment_schedule">' . esc_html__( 'Payment Schedule', 'sfs-hr' ) . '</h5>';
        echo '<table style="width:100%;border-collapse:collapse;margin-top:8px;">';
        echo '<thead>';
        echo '<tr style="background:#eee;">';
        echo '<th style="padding:8px;text-align:left;border:1px solid #ccc;width:50px;">#</th>';
        echo '<th style="padding:8px;text-align:left;border:1px solid #ccc;" data-i18n-key="due_date">' . esc_html__( 'Due Date', 'sfs-hr' ) . '</th>';
        echo '<th style="padding:8px;text-align:left;border:1px solid #ccc;" data-i18n-key="amount">' . esc_html__( 'Amount', 'sfs-hr' ) . '</th>';
        echo '<th style="padding:8px;text-align:left;border:1px solid #ccc;" data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ( $payments as $payment ) {
            echo '<tr>';
            echo '<td style="padding:6px;border:1px solid #ccc;">' . (int) $payment->sequence . '</td>';
            echo '<td style="padding:6px;border:1px solid #ccc;">' . esc_html( wp_date( 'M Y', strtotime( $payment->due_date ) ) ) . '</td>';
            echo '<td style="padding:6px;border:1px solid #ccc;">' . number_format( (float) $payment->amount_planned, 2 ) . '</td>';
            echo '<td style="padding:6px;border:1px solid #ccc;">' . $this->get_payment_status_badge( $payment->status ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * Render mobile loan cards
     */
    private function render_mobile_cards( array $loan_data ): void {
        echo '<div class="sfs-hr-loans-mobile">';
        foreach ( $loan_data as $data ) {
            $loan = $data['loan'];
            $paid_count = $data['paid_count'];
            $payments = $data['payments'];

            echo '<details class="sfs-hr-loan-card">';
            echo '  <summary class="sfs-hr-loan-summary">';
            echo '      <span class="sfs-hr-loan-summary-title">' . esc_html( $loan->loan_number ) . '</span>';
            echo '      <span class="sfs-hr-loan-summary-status">';
            echo            $this->get_loan_status_badge( $loan->status );
            echo '      </span>';
            echo '  </summary>';

            echo '  <div class="sfs-hr-loan-body">';

            echo '      <div class="sfs-hr-loan-field-row">';
            echo '          <div class="sfs-hr-loan-field-label" data-i18n-key="amount">' . esc_html__( 'Amount', 'sfs-hr' ) . '</div>';
            echo '          <div class="sfs-hr-loan-field-value">' . number_format( (float) $loan->principal_amount, 2 ) . ' ' . esc_html( $loan->currency ) . '</div>';
            echo '      </div>';

            echo '      <div class="sfs-hr-loan-field-row">';
            echo '          <div class="sfs-hr-loan-field-label" data-i18n-key="remaining">' . esc_html__( 'Remaining', 'sfs-hr' ) . '</div>';
            echo '          <div class="sfs-hr-loan-field-value">' . number_format( (float) $loan->remaining_balance, 2 ) . ' ' . esc_html( $loan->currency ) . '</div>';
            echo '      </div>';

            echo '      <div class="sfs-hr-loan-field-row">';
            echo '          <div class="sfs-hr-loan-field-label" data-i18n-key="installments">' . esc_html__( 'Installments', 'sfs-hr' ) . '</div>';
            echo '          <div class="sfs-hr-loan-field-value">' . (int) $paid_count . ' / ' . (int) $loan->installments_count . '</div>';
            echo '      </div>';

            echo '      <div class="sfs-hr-loan-field-row">';
            echo '          <div class="sfs-hr-loan-field-label" data-i18n-key="requested">' . esc_html__( 'Requested', 'sfs-hr' ) . '</div>';
            echo '          <div class="sfs-hr-loan-field-value">' . esc_html( wp_date( 'M j, Y', strtotime( $loan->created_at ) ) ) . '</div>';
            echo '      </div>';

            echo '      <div class="sfs-hr-loan-field-row">';
            echo '          <div class="sfs-hr-loan-field-label" data-i18n-key="reason">' . esc_html__( 'Reason', 'sfs-hr' ) . '</div>';
            echo '          <div class="sfs-hr-loan-field-value">' . esc_html( $loan->reason ) . '</div>';
            echo '      </div>';

            if ( $loan->status === 'rejected' ) {
                if ( ! empty( $data['approver_info'] ) ) {
                    echo '      <div class="sfs-hr-loan-field-row">';
                    echo '          <div class="sfs-hr-loan-field-label" style="color:#dc3545;" data-i18n-key="rejected_by">' . esc_html__( 'Rejected by', 'sfs-hr' ) . '</div>';
                    echo '          <div class="sfs-hr-loan-field-value" style="color:#dc3545;">' . esc_html( $data['approver_info'] ) . '</div>';
                    echo '      </div>';
                }
                if ( $loan->rejection_reason ) {
                    echo '      <div class="sfs-hr-loan-field-row">';
                    echo '          <div class="sfs-hr-loan-field-label" style="color:#dc3545;" data-i18n-key="rejection_reason">' . esc_html__( 'Reason', 'sfs-hr' ) . '</div>';
                    echo '          <div class="sfs-hr-loan-field-value" style="color:#dc3545;">' . esc_html( $loan->rejection_reason ) . '</div>';
                    echo '      </div>';
                }
            } elseif ( in_array( $loan->status, [ 'active', 'completed' ], true ) && ! empty( $data['approver_info'] ) ) {
                echo '      <div class="sfs-hr-loan-field-row">';
                echo '          <div class="sfs-hr-loan-field-label" style="color:#28a745;" data-i18n-key="approved_by">' . esc_html__( 'Approved by', 'sfs-hr' ) . '</div>';
                echo '          <div class="sfs-hr-loan-field-value" style="color:#28a745;">' . esc_html( $data['approver_info'] ) . '</div>';
                echo '      </div>';
            }

            if ( ! empty( $payments ) ) {
                $this->render_mobile_payment_schedule( $payments );
            }

            echo '  </div>';
            echo '</details>';
        }
        echo '</div>';
    }

    /**
     * Render mobile payment schedule
     */
    private function render_mobile_payment_schedule( array $payments ): void {
        echo '      <div style="margin-top:12px;padding-top:12px;border-top:1px solid #ddd;">';
        echo '          <strong data-i18n-key="payment_schedule">' . esc_html__( 'Payment Schedule', 'sfs-hr' ) . '</strong>';
        echo '          <table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:12px;">';
        echo '          <thead>';
        echo '          <tr style="background:#f5f5f5;">';
        echo '          <th style="padding:6px;text-align:left;border:1px solid #ddd;">#</th>';
        echo '          <th style="padding:6px;text-align:left;border:1px solid #ddd;" data-i18n-key="due">' . esc_html__( 'Due', 'sfs-hr' ) . '</th>';
        echo '          <th style="padding:6px;text-align:left;border:1px solid #ddd;" data-i18n-key="amount">' . esc_html__( 'Amount', 'sfs-hr' ) . '</th>';
        echo '          <th style="padding:6px;text-align:left;border:1px solid #ddd;" data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '          </tr>';
        echo '          </thead>';
        echo '          <tbody>';

        foreach ( $payments as $payment ) {
            echo '          <tr>';
            echo '          <td style="padding:4px;border:1px solid #ddd;">' . (int) $payment->sequence . '</td>';
            echo '          <td style="padding:4px;border:1px solid #ddd;">' . esc_html( wp_date( 'M Y', strtotime( $payment->due_date ) ) ) . '</td>';
            echo '          <td style="padding:4px;border:1px solid #ddd;">' . number_format( (float) $payment->amount_planned, 2 ) . '</td>';
            echo '          <td style="padding:4px;border:1px solid #ddd;">' . $this->get_payment_status_badge( $payment->status ) . '</td>';
            echo '          </tr>';
        }

        echo '          </tbody>';
        echo '          </table>';
        echo '      </div>';
    }

    /**
     * Get loan status badge HTML
     */
    private function get_loan_status_badge( string $status ): string {
        $labels = [
            'pending_gm'      => __( 'Pending GM Approval', 'sfs-hr' ),
            'pending_finance' => __( 'Pending Finance Approval', 'sfs-hr' ),
            'active'          => __( 'Active', 'sfs-hr' ),
            'completed'       => __( 'Completed', 'sfs-hr' ),
            'rejected'        => __( 'Rejected', 'sfs-hr' ),
            'cancelled'       => __( 'Cancelled', 'sfs-hr' ),
        ];
        $keys = [
            'pending_gm'      => 'pending_gm_approval',
            'pending_finance' => 'pending_finance_approval',
            'active'          => 'active',
            'completed'       => 'completed',
            'rejected'        => 'rejected',
            'cancelled'       => 'cancelled',
        ];
        $colors = [
            'pending_gm'      => 'background:#ffa500;color:#fff;',
            'pending_finance' => 'background:#ff8c00;color:#fff;',
            'active'          => 'background:#28a745;color:#fff;',
            'completed'       => 'background:#6c757d;color:#fff;',
            'rejected'        => 'background:#dc3545;color:#fff;',
            'cancelled'       => 'background:#6c757d;color:#fff;',
        ];
        $label = $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
        $key = $keys[ $status ] ?? $status;
        $color = $colors[ $status ] ?? 'background:#888;color:#fff;';
        return '<span style="' . esc_attr( $color ) . 'padding:4px 8px;border-radius:3px;font-size:11px;" data-i18n-key="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</span>';
    }

    /**
     * Get payment status badge HTML
     */
    private function get_payment_status_badge( string $status ): string {
        $labels = [
            'planned'  => __( 'Planned', 'sfs-hr' ),
            'paid'     => __( 'Paid', 'sfs-hr' ),
            'skipped'  => __( 'Skipped', 'sfs-hr' ),
            'partial'  => __( 'Partial', 'sfs-hr' ),
        ];
        $colors = [
            'planned'  => 'background:#ffc107;color:#000;',
            'paid'     => 'background:#28a745;color:#fff;',
            'skipped'  => 'background:#6c757d;color:#fff;',
            'partial'  => 'background:#17a2b8;color:#fff;',
        ];
        $label = $labels[ $status ] ?? ucfirst( $status );
        $color = $colors[ $status ] ?? 'background:#888;color:#fff;';
        return '<span style="' . esc_attr( $color ) . 'padding:2px 6px;border-radius:3px;font-size:10px;" data-i18n-key="' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
    }
}
