<?php
/**
 * Loans Tab - Frontend loans dashboard and request form
 *
 * Redesigned with §10.1 design system — KPI cards, card-based history, improved form.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LoansTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own loan information.', 'sfs-hr' ) . '</p>';
            return;
        }

        $settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();
        if ( ! $settings['show_in_my_profile'] ) {
            echo '<div class="sfs-card"><div class="sfs-empty-state">';
            echo '<div class="sfs-empty-state-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23" stroke="currentColor" stroke-width="1.5"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" fill="none" stroke-width="1.5"/></svg></div>';
            echo '<p class="sfs-empty-state-title">' . esc_html__( 'Loans module is currently not available.', 'sfs-hr' ) . '</p>';
            echo '</div></div>';
            return;
        }

        global $wpdb;
        $loans_table    = $wpdb->prefix . 'sfs_hr_loans';
        $payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';

        $loans = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$loans_table} WHERE employee_id = %d ORDER BY created_at DESC",
            $emp_id
        ) );

        // KPI data
        $active_count     = 0;
        $total_borrowed   = 0;
        $total_remaining  = 0;
        $total_paid_count = 0;
        foreach ( $loans as $loan ) {
            $total_borrowed += (float) $loan->principal_amount;
            $total_remaining += (float) $loan->remaining_balance;
            if ( $loan->status === 'active' ) {
                $active_count++;
            }
            if ( $loan->status === 'completed' ) {
                $total_paid_count++;
            }
        }

        // Header
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="my_loans">' . esc_html__( 'My Loans', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-section-subtitle">' . esc_html( ( $emp['first_name'] ?? '' ) . ' ' . ( $emp['last_name'] ?? '' ) ) . '</p>';
        echo '</div>';

        // Flash messages
        $this->render_flash_messages();

        // KPIs
        $this->render_kpis( $active_count, $total_borrowed, $total_remaining, $total_paid_count );

        // Request form
        if ( $settings['allow_employee_requests'] ) {
            $this->render_request_form( $emp_id, $settings );
        }

        // Loan history
        $this->render_history( $loans, $payments_table );
    }

    private function render_flash_messages(): void {
        if ( ! isset( $_GET['loan_request'] ) ) {
            return;
        }
        if ( $_GET['loan_request'] === 'success' ) {
            echo '<div class="sfs-alert sfs-alert--success">';
            echo '<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" fill="none" stroke-width="2"/><polyline points="22 4 12 14.01 9 11.01" stroke="currentColor" fill="none" stroke-width="2"/></svg>';
            echo '<span>' . esc_html__( 'Loan request submitted successfully!', 'sfs-hr' ) . '</span></div>';
        } elseif ( $_GET['loan_request'] === 'error' ) {
            $error = isset( $_GET['error'] ) ? urldecode( $_GET['error'] ) : __( 'Failed to submit request.', 'sfs-hr' );
            echo '<div class="sfs-alert sfs-alert--error">';
            echo '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" fill="none" stroke-width="2"/><line x1="12" y1="8" x2="12" y2="12" stroke="currentColor" stroke-width="2"/><line x1="12" y1="16" x2="12.01" y2="16" stroke="currentColor" stroke-width="2"/></svg>';
            echo '<span>' . esc_html( $error ) . '</span></div>';
        }
    }

    private function render_kpis( int $active, float $borrowed, float $remaining, int $completed ): void {
        echo '<div class="sfs-kpi-grid">';

        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#dbeafe;"><svg viewBox="0 0 24 24" stroke="#3b82f6"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="total_borrowed">' . esc_html__( 'Total borrowed', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . number_format( $borrowed, 0 ) . '</div>';
        echo '</div>';

        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" stroke="#f59e0b"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="remaining_balance">' . esc_html__( 'Remaining', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . number_format( $remaining, 0 ) . '</div>';
        echo '</div>';

        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#ecfdf5;"><svg viewBox="0 0 24 24" stroke="#10b981"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="active_loans">' . esc_html__( 'Active loans', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $active . '</div>';
        echo '</div>';

        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#f3f4f6;"><svg viewBox="0 0 24 24" stroke="#6b7280"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="completed_loans">' . esc_html__( 'Completed', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $completed . '</div>';
        echo '</div>';

        echo '</div>';
    }

    private function render_request_form( int $emp_id, array $settings ): void {
        echo '<div class="sfs-card" style="margin-bottom:24px;">';
        echo '<div class="sfs-card-body">';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0 0 14px;" data-i18n-key="request_new_loan">' . esc_html__( 'Request New Loan', 'sfs-hr' ) . '</h3>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'sfs_hr_submit_loan_request_' . $emp_id );
        echo '<input type="hidden" name="action" value="sfs_hr_submit_loan_request" />';
        echo '<input type="hidden" name="employee_id" value="' . (int) $emp_id . '" />';

        echo '<div class="sfs-form-fields">';

        // Amount
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label"><span data-i18n-key="loan_amount_sar">' . esc_html__( 'Loan Amount (SAR)', 'sfs-hr' ) . '</span> <span class="sfs-required">*</span></label>';
        echo '<input type="number" name="principal_amount" step="0.01" min="1" required class="sfs-input" />';
        if ( $settings['max_loan_amount'] > 0 ) {
            echo '<span class="sfs-form-hint">' . sprintf( esc_html__( 'Maximum: %s SAR', 'sfs-hr' ), number_format( $settings['max_loan_amount'], 2 ) ) . '</span>';
        }
        echo '</div>';

        // Monthly installment
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label"><span data-i18n-key="monthly_installment_sar">' . esc_html__( 'Monthly Installment (SAR)', 'sfs-hr' ) . '</span> <span class="sfs-required">*</span></label>';
        echo '<input type="number" name="monthly_amount" id="sfs_loan_monthly" step="0.01" min="1" required class="sfs-input" oninput="sfsCalcLoan()" />';
        echo '<span class="sfs-form-hint" data-i18n-key="how_much_you_can_pay">' . esc_html__( 'How much you can pay each month', 'sfs-hr' ) . '</span>';
        echo '<p id="sfs_loan_plan" style="margin:8px 0 0;font-weight:600;font-size:13px;color:var(--sfs-primary);"></p>';
        echo '</div>';

        $this->render_calculator_script();

        // Reason
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label"><span data-i18n-key="reason_for_loan">' . esc_html__( 'Reason for Loan', 'sfs-hr' ) . '</span> <span class="sfs-required">*</span></label>';
        echo '<textarea name="reason" rows="3" required class="sfs-textarea"></textarea>';
        echo '</div>';

        // Submit
        echo '<button type="submit" class="sfs-btn sfs-btn--primary sfs-btn--full" data-i18n-key="submit_loan_request">';
        echo '<svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
        esc_html_e( 'Submit Loan Request', 'sfs-hr' );
        echo '</button>';

        echo '</div>'; // .sfs-form-fields
        echo '</form>';
        echo '</div></div>';
    }

    private function render_calculator_script(): void {
        $i18n = [
            'would_require' => esc_js( __( 'Would require', 'sfs-hr' ) ),
            'months_max'    => esc_js( __( 'months (max 60). Increase monthly amount.', 'sfs-hr' ) ),
            'final_payment' => esc_js( __( 'final payment', 'sfs-hr' ) ),
            'sar_total'     => esc_js( __( 'SAR total', 'sfs-hr' ) ),
            'monthly_of'    => esc_js( __( 'monthly payments of', 'sfs-hr' ) ),
            'months'        => esc_js( __( 'months', 'sfs-hr' ) ),
        ];
        echo '<script>var _li={';
        foreach ( $i18n as $k => $v ) {
            echo $k . ':"' . $v . '",';
        }
        echo '};function sfsCalcLoan(){var p=parseFloat(document.querySelector(\'input[name="principal_amount"]\').value)||0,m=parseFloat(document.getElementById("sfs_loan_monthly").value)||0,d=document.getElementById("sfs_loan_plan");if(p>0&&m>0){var f=Math.floor(p/m),r=p-(f*m),t=r>0?f+1:f;if(t>60){d.textContent="⚠️ "+_li.would_require+" "+t+" "+_li.months_max;d.style.color="var(--sfs-danger)";}else if(r>0){d.textContent=f+" × "+m.toFixed(2)+" SAR + "+_li.final_payment+" "+r.toFixed(2)+" SAR = "+p.toFixed(2)+" "+_li.sar_total+" ("+t+" "+_li.months+")";d.style.color="var(--sfs-primary)";}else{d.textContent=f+" "+_li.monthly_of+" "+m.toFixed(2)+" SAR = "+p.toFixed(2)+" "+_li.sar_total;d.style.color="var(--sfs-primary)";}}else{d.textContent="";}}'
           . 'document.addEventListener("DOMContentLoaded",function(){var i=document.querySelector(\'input[name="principal_amount"]\');if(i)i.addEventListener("input",sfsCalcLoan);});</script>';
    }

    private function render_history( array $loans, string $payments_table ): void {
        echo '<div class="sfs-section" style="margin-top:4px;">';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0 0 14px;" data-i18n-key="loan_history">' . esc_html__( 'Loan History', 'sfs-hr' ) . '</h3>';
        echo '</div>';

        if ( empty( $loans ) ) {
            echo '<div class="sfs-card"><div class="sfs-empty-state">';
            echo '<div class="sfs-empty-state-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23" stroke="currentColor" stroke-width="1.5"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" stroke="currentColor" fill="none" stroke-width="1.5"/></svg></div>';
            echo '<p class="sfs-empty-state-title" data-i18n-key="you_have_no_loan_records">' . esc_html__( 'No loan records yet', 'sfs-hr' ) . '</p>';
            echo '<p class="sfs-empty-state-text">' . esc_html__( 'Your loan requests will appear here.', 'sfs-hr' ) . '</p>';
            echo '</div></div>';
            return;
        }

        global $wpdb;
        $loan_data = [];
        foreach ( $loans as $loan ) {
            $paid_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$payments_table} WHERE loan_id = %d AND status = 'paid'",
                $loan->id
            ) );
            $payments = [];
            if ( in_array( $loan->status, [ 'active', 'completed' ], true ) ) {
                $payments = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$payments_table} WHERE loan_id = %d ORDER BY sequence ASC",
                    $loan->id
                ) );
            }
            $approver_info = '';
            if ( $loan->status === 'rejected' && ! empty( $loan->rejected_by ) ) {
                $u = get_user_by( 'id', (int) $loan->rejected_by );
                $approver_info = $u ? $u->display_name : '';
            } elseif ( in_array( $loan->status, [ 'active', 'completed' ], true ) && ! empty( $loan->approved_finance_by ) ) {
                $u = get_user_by( 'id', (int) $loan->approved_finance_by );
                $approver_info = $u ? $u->display_name : '';
            }
            $loan_data[] = compact( 'loan', 'paid_count', 'payments', 'approver_info' );
        }

        // Desktop table
        echo '<div class="sfs-desktop-only"><table class="sfs-table">';
        echo '<thead><tr>';
        echo '<th data-i18n-key="loan_number">' . esc_html__( 'Loan #', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="amount">' . esc_html__( 'Amount', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="remaining">' . esc_html__( 'Remaining', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="installments">' . esc_html__( 'Installments', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="requested">' . esc_html__( 'Requested', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $loan_data as $d ) {
            $loan = $d['loan'];
            echo '<tr>';
            echo '<td><strong>' . esc_html( $loan->loan_number ) . '</strong></td>';
            echo '<td>' . number_format( (float) $loan->principal_amount, 2 ) . ' ' . esc_html( $loan->currency ) . '</td>';
            echo '<td>' . number_format( (float) $loan->remaining_balance, 2 ) . ' ' . esc_html( $loan->currency ) . '</td>';
            echo '<td>' . (int) $d['paid_count'] . ' / ' . (int) $loan->installments_count . '</td>';
            echo '<td>' . $this->status_badge( $loan->status ) . '</td>';
            echo '<td>' . esc_html( wp_date( 'M j, Y', strtotime( $loan->created_at ) ) ) . '</td>';
            echo '</tr>';

            // Expandable detail row
            echo '<tr><td colspan="6" style="background:var(--sfs-background);font-size:12px;">';
            echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Reason:', 'sfs-hr' ) . '</strong> ' . esc_html( $loan->reason ) . '</p>';
            if ( $loan->status === 'rejected' ) {
                if ( ! empty( $d['approver_info'] ) ) {
                    echo '<p style="margin:0 0 4px;color:var(--sfs-danger);"><strong>' . esc_html__( 'Rejected by:', 'sfs-hr' ) . '</strong> ' . esc_html( $d['approver_info'] ) . '</p>';
                }
                if ( ! empty( $loan->rejection_reason ) ) {
                    echo '<p style="margin:0;color:var(--sfs-danger);"><strong>' . esc_html__( 'Reason:', 'sfs-hr' ) . '</strong> ' . esc_html( $loan->rejection_reason ) . '</p>';
                }
            } elseif ( ! empty( $d['approver_info'] ) ) {
                echo '<p style="margin:0 0 4px;color:var(--sfs-success);"><strong>' . esc_html__( 'Approved by:', 'sfs-hr' ) . '</strong> ' . esc_html( $d['approver_info'] ) . '</p>';
            }
            if ( ! empty( $d['payments'] ) ) {
                $this->render_payment_table( $d['payments'] );
            }
            echo '</td></tr>';
        }

        echo '</tbody></table></div>';

        // Mobile cards
        echo '<div class="sfs-mobile-only sfs-history-list">';
        foreach ( $loan_data as $d ) {
            $loan = $d['loan'];
            echo '<details class="sfs-history-card">';
            echo '<summary>';
            echo '<span class="sfs-history-card-title">' . esc_html( $loan->loan_number ) . '</span>';
            echo $this->status_badge( $loan->status );
            echo '</summary>';
            echo '<div class="sfs-history-card-body">';
            $this->detail_row( __( 'Amount', 'sfs-hr' ), number_format( (float) $loan->principal_amount, 2 ) . ' ' . $loan->currency );
            $this->detail_row( __( 'Remaining', 'sfs-hr' ), number_format( (float) $loan->remaining_balance, 2 ) . ' ' . $loan->currency );
            $this->detail_row( __( 'Installments', 'sfs-hr' ), (int) $d['paid_count'] . ' / ' . (int) $loan->installments_count );
            $this->detail_row( __( 'Requested', 'sfs-hr' ), wp_date( 'M j, Y', strtotime( $loan->created_at ) ) );
            $this->detail_row( __( 'Reason', 'sfs-hr' ), $loan->reason );
            if ( $loan->status === 'rejected' && ! empty( $d['approver_info'] ) ) {
                echo '<div class="sfs-detail-row" style="color:var(--sfs-danger);"><span class="sfs-detail-label">' . esc_html__( 'Rejected by', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $d['approver_info'] ) . '</span></div>';
            } elseif ( ! empty( $d['approver_info'] ) ) {
                echo '<div class="sfs-detail-row" style="color:var(--sfs-success);"><span class="sfs-detail-label">' . esc_html__( 'Approved by', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $d['approver_info'] ) . '</span></div>';
            }
            if ( ! empty( $d['payments'] ) ) {
                $this->render_payment_table( $d['payments'] );
            }
            echo '</div></details>';
        }
        echo '</div>';
    }

    private function render_payment_table( array $payments ): void {
        echo '<div style="margin-top:10px;">';
        echo '<strong style="font-size:12px;" data-i18n-key="payment_schedule">' . esc_html__( 'Payment Schedule', 'sfs-hr' ) . '</strong>';
        echo '<table class="sfs-table" style="margin-top:6px;font-size:12px;">';
        echo '<thead><tr>';
        echo '<th style="padding:6px 10px;">#</th>';
        echo '<th style="padding:6px 10px;" data-i18n-key="due_date">' . esc_html__( 'Due', 'sfs-hr' ) . '</th>';
        echo '<th style="padding:6px 10px;" data-i18n-key="amount">' . esc_html__( 'Amount', 'sfs-hr' ) . '</th>';
        echo '<th style="padding:6px 10px;" data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $payments as $p ) {
            echo '<tr>';
            echo '<td style="padding:4px 10px;">' . (int) $p->sequence . '</td>';
            echo '<td style="padding:4px 10px;">' . esc_html( wp_date( 'M Y', strtotime( $p->due_date ) ) ) . '</td>';
            echo '<td style="padding:4px 10px;">' . number_format( (float) $p->amount_planned, 2 ) . '</td>';
            echo '<td style="padding:4px 10px;">' . $this->payment_badge( $p->status ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private function detail_row( string $label, string $value ): void {
        echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html( $label ) . '</span><span class="sfs-detail-value">' . esc_html( $value ) . '</span></div>';
    }

    private function status_badge( string $status ): string {
        $map = [
            'pending_gm'      => [ 'pending', __( 'Pending GM', 'sfs-hr' ) ],
            'pending_finance'  => [ 'pending', __( 'Pending Finance', 'sfs-hr' ) ],
            'active'           => [ 'active', __( 'Active', 'sfs-hr' ) ],
            'completed'        => [ 'completed', __( 'Completed', 'sfs-hr' ) ],
            'rejected'         => [ 'rejected', __( 'Rejected', 'sfs-hr' ) ],
            'cancelled'        => [ 'cancelled', __( 'Cancelled', 'sfs-hr' ) ],
        ];
        $info = $map[ $status ] ?? [ 'pending', ucfirst( str_replace( '_', ' ', $status ) ) ];
        return '<span class="sfs-badge sfs-badge--' . esc_attr( $info[0] ) . '">' . esc_html( $info[1] ) . '</span>';
    }

    private function payment_badge( string $status ): string {
        $map = [
            'planned' => 'pending',
            'paid'    => 'approved',
            'skipped' => 'cancelled',
            'partial' => 'info',
        ];
        $class = $map[ $status ] ?? 'pending';
        return '<span class="sfs-badge sfs-badge--' . esc_attr( $class ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
    }
}
