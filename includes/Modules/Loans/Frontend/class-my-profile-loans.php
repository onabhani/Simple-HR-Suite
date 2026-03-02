<?php
namespace SFS\HR\Modules\Loans\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * My Profile Loans Tab
 *
 * Displays employee's loans in My Profile page
 */
class MyProfileLoans {

    public function __construct() {
        // Add "Loans" tab to My Profile
        add_action( 'sfs_hr_employee_tabs', [ $this, 'add_loans_tab' ], 10, 1 );

        // Render loans tab content
        add_action( 'sfs_hr_employee_tab_content', [ $this, 'render_loans_content' ], 10, 2 );

        // Handle loan request submission (traditional POST fallback)
        add_action( 'admin_post_sfs_hr_submit_loan_request', [ $this, 'handle_loan_request' ] );

        // AJAX loan request submission
        add_action( 'wp_ajax_sfs_hr_submit_loan_ajax', [ $this, 'handle_loan_request_ajax' ] );
    }

    /**
     * Add "Loans" tab to My Profile / Employee Profile tabs
     */
    public function add_loans_tab( \stdClass $employee ): void {
        $settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();
        $page     = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';

        // On self-service: respect the setting
        if ( $page !== 'sfs-hr-employee-profile' && ! $settings['show_in_my_profile'] ) {
            return;
        }

        $active = ( isset( $_GET['tab'] ) && $_GET['tab'] === 'loans' );
        $class  = 'nav-tab' . ( $active ? ' nav-tab-active' : '' );

        if ( $page === 'sfs-hr-employee-profile' ) {
            $employee_id = isset( $_GET['employee_id'] ) ? (int) $_GET['employee_id'] : (int) $employee->id;
            $url = admin_url( 'admin.php?page=sfs-hr-employee-profile&employee_id=' . $employee_id . '&tab=loans' );
        } else {
            $url = admin_url( 'admin.php?page=sfs-hr-my-profile&tab=loans' );
        }

        echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">';
        esc_html_e( 'Loans', 'sfs-hr' );
        echo '</a>';
    }

    /**
     * Render loans tab content
     */
    public function render_loans_content( \stdClass $employee, string $active_tab ): void {
        if ( $active_tab !== 'loans' ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';

        // Admin employee profile — render admin-oriented view
        if ( $page === 'sfs-hr-employee-profile' ) {
            $this->render_admin_loans_view( $employee );
            return;
        }

        $settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();

        if ( ! $settings['show_in_my_profile'] ) {
            echo '<p>' . esc_html__( 'Loans module is currently disabled.', 'sfs-hr' ) . '</p>';
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
            $employee->id
        ) );

        echo '<div class="sfs-hr-my-profile-loans">';

        // Header with action button at top
        echo '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin:0 0 16px;">';
        echo '<h2 style="margin:0;">' . esc_html__( 'My Loans', 'sfs-hr' ) . '</h2>';
        if ( $settings['allow_employee_requests'] ) {
            echo '<button type="button" class="button button-primary" onclick="document.getElementById(\'sfs-loan-request-form\').style.display=\'block\';this.style.display=\'none\';" style="white-space:nowrap;">';
            echo '+ ';
            esc_html_e( 'Request New Loan', 'sfs-hr' );
            echo '</button>';
        }
        echo '</div>';

        // Request new loan form (if enabled)
        if ( $settings['allow_employee_requests'] ) {
            // Show error/success messages
            $loan_request_status = isset( $_GET['loan_request'] ) ? sanitize_key( $_GET['loan_request'] ) : '';
            if ( $loan_request_status === 'success' ) {
                echo '<div class="notice notice-success" style="margin:10px 0 16px;padding:12px;"><p><strong>' .
                     esc_html__( '✓ Loan request submitted successfully!', 'sfs-hr' ) .
                     '</strong></p></div>';
            } elseif ( $loan_request_status === 'error' ) {
                $error_msg = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : __( 'Failed to submit loan request.', 'sfs-hr' );
                echo '<div class="notice notice-error" style="margin:10px 0 16px;padding:12px;background:#f8d7da;border-left:4px solid #dc3545;"><p><strong>✗ ' .
                     esc_html( $error_msg ) .
                     '</strong></p></div>';
            }

            $this->render_loan_request_form( $employee );
        }

        // Display loans
        if ( empty( $loans ) ) {
            echo '<p>' . esc_html__( 'You have no loan records.', 'sfs-hr' ) . '</p>';
        } else {
            $this->render_loans_list( $loans, $payments_table );
        }

        echo '</div>'; // .sfs-hr-my-profile-loans
    }

    /**
     * Render admin-oriented loans view for an employee profile
     */
    private function render_admin_loans_view( \stdClass $employee ): void {
        global $wpdb;
        $loans_table    = $wpdb->prefix . 'sfs_hr_loans';
        $payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';

        $loans = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$loans_table} WHERE employee_id = %d ORDER BY created_at DESC",
            $employee->id
        ) );

        echo '<div class="sfs-hr-admin-loans-view">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">';
        echo '<h2 style="margin:0;">' . esc_html( sprintf( __( 'Loans — %s', 'sfs-hr' ), $employee->first_name . ' ' . $employee->last_name ) ) . '</h2>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-loans' ) ) . '" class="button">' . esc_html__( 'All Loans', 'sfs-hr' ) . ' &rarr;</a>';
        echo '</div>';

        if ( empty( $loans ) ) {
            echo '<p style="color:#666;">' . esc_html__( 'This employee has no loan records.', 'sfs-hr' ) . '</p>';
            echo '</div>';
            return;
        }

        // Summary cards
        $total_principal = 0;
        $total_remaining = 0;
        $active_count    = 0;
        foreach ( $loans as $loan ) {
            if ( in_array( $loan->status, [ 'active', 'completed' ], true ) ) {
                $total_principal += (float) $loan->principal_amount;
                $total_remaining += (float) $loan->remaining_balance;
            }
            if ( $loan->status === 'active' ) {
                $active_count++;
            }
        }

        echo '<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">';
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 20px;min-width:140px;">';
        echo '<div style="font-size:11px;color:#666;text-transform:uppercase;">' . esc_html__( 'Total Loans', 'sfs-hr' ) . '</div>';
        echo '<div style="font-size:22px;font-weight:600;color:#1d2327;">' . count( $loans ) . '</div>';
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 20px;min-width:140px;">';
        echo '<div style="font-size:11px;color:#666;text-transform:uppercase;">' . esc_html__( 'Active', 'sfs-hr' ) . '</div>';
        echo '<div style="font-size:22px;font-weight:600;color:#28a745;">' . $active_count . '</div>';
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 20px;min-width:140px;">';
        echo '<div style="font-size:11px;color:#666;text-transform:uppercase;">' . esc_html__( 'Total Borrowed', 'sfs-hr' ) . '</div>';
        echo '<div style="font-size:22px;font-weight:600;color:#1d2327;">' . number_format( $total_principal, 2 ) . ' <small style="font-size:12px;">SAR</small></div>';
        echo '</div>';
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 20px;min-width:140px;">';
        echo '<div style="font-size:11px;color:#666;text-transform:uppercase;">' . esc_html__( 'Remaining', 'sfs-hr' ) . '</div>';
        echo '<div style="font-size:22px;font-weight:600;color:' . ( $total_remaining > 0 ? '#dc3545' : '#28a745' ) . ';">' . number_format( $total_remaining, 2 ) . ' <small style="font-size:12px;">SAR</small></div>';
        echo '</div>';
        echo '</div>';

        // Loans table
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Loan #', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Amount', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Remaining', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Installments', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Date', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'sfs-hr' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $loans as $loan ) {
            $paid_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$payments_table} WHERE loan_id = %d AND status = 'paid'",
                $loan->id
            ) );
            $view_url = admin_url( 'admin.php?page=sfs-hr-loans&action=view&id=' . (int) $loan->id );

            echo '<tr>';
            echo '<td><a href="' . esc_url( $view_url ) . '" style="font-weight:600;color:#2271b1;text-decoration:none;">' . esc_html( $loan->loan_number ) . '</a></td>';
            echo '<td>' . number_format( (float) $loan->principal_amount, 2 ) . ' ' . esc_html( $loan->currency ) . '</td>';
            echo '<td>' . number_format( (float) $loan->remaining_balance, 2 ) . ' ' . esc_html( $loan->currency ) . '</td>';
            echo '<td>' . (int) $paid_count . ' / ' . (int) $loan->installments_count . '</td>';
            echo '<td>' . $this->get_status_badge( $loan->status ) . '</td>';
            echo '<td>' . esc_html( wp_date( 'M j, Y', strtotime( $loan->created_at ) ) ) . '</td>';
            echo '<td><a href="' . esc_url( $view_url ) . '" class="button button-small">' . esc_html__( 'View', 'sfs-hr' ) . '</a></td>';
            echo '</tr>';

            // Show reason if present
            if ( ! empty( $loan->reason ) ) {
                echo '<tr><td colspan="7" style="padding:4px 10px 10px;background:#f9f9f9;border-top:0;font-size:12px;color:#666;">';
                echo '<strong>' . esc_html__( 'Reason:', 'sfs-hr' ) . '</strong> ' . esc_html( $loan->reason );
                echo '</td></tr>';
            }
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Render loan request form
     */
    private function render_loan_request_form( \stdClass $employee ): void {
        $settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();

        // Pre-calculate limits for client-side hints
        $base_salary = (float) ( $employee->base_salary ?? 0 );
        $limits = [
            'max_amount'          => (float) $settings['max_loan_amount'],
            'max_months'          => (int) ( $settings['max_installment_months'] ?? 60 ),
            'max_by_salary'       => 0,
            'salary_multiplier'   => (float) ( $settings['max_loan_multiplier'] ?? 0 ),
            'max_installment'     => 0,
            'max_installment_pct' => (int) ( $settings['max_installment_percent'] ?? 0 ),
        ];
        if ( $limits['salary_multiplier'] > 0 && $base_salary > 0 ) {
            $limits['max_by_salary'] = round( $base_salary * $limits['salary_multiplier'], 2 );
        }
        if ( $limits['max_installment_pct'] > 0 && $base_salary > 0 ) {
            $limits['max_installment'] = round( $base_salary * $limits['max_installment_pct'] / 100, 2 );
        }

        echo '<div id="sfs-loan-request-form" style="display:none;background:#fff;padding:20px;border:1px solid #ccc;border-radius:4px;margin-bottom:20px;max-width:600px;">';
        echo '<h3>' . esc_html__( 'Request New Loan', 'sfs-hr' ) . '</h3>';

        // In-form message area
        echo '<div id="sfs-legacy-loan-msg" style="display:none;padding:10px 14px;border-radius:4px;margin-bottom:12px;font-size:13px;"></div>';

        echo '<form id="sfs-legacy-loan-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'sfs_hr_submit_loan_request_' . $employee->id );
        echo '<input type="hidden" name="action" value="sfs_hr_submit_loan_request" />';
        echo '<input type="hidden" name="employee_id" value="' . (int) $employee->id . '" />';

        echo '<table class="form-table">';

        // Principal amount
        echo '<tr>';
        echo '<th scope="row"><label for="principal_amount">' . esc_html__( 'Loan Amount (SAR)', 'sfs-hr' ) . ' <span style="color:red;">*</span></label></th>';
        echo '<td>';
        echo '<input type="number" name="principal_amount" id="principal_amount" step="0.01" min="1" required style="width:200px;" />';
        $hints = [];
        if ( $settings['max_loan_amount'] > 0 ) {
            $hints[] = sprintf( esc_html__( 'Max: %s SAR', 'sfs-hr' ), number_format( $settings['max_loan_amount'], 2 ) );
        }
        if ( $limits['max_by_salary'] > 0 ) {
            $hints[] = sprintf( esc_html__( 'Salary limit: %s SAR (%s×)', 'sfs-hr' ), number_format( $limits['max_by_salary'], 2 ), $limits['salary_multiplier'] );
        }
        if ( ! empty( $hints ) ) {
            echo '<p class="description">' . implode( ' · ', $hints ) . '</p>';
        }
        echo '<p id="legacy_loan_amount_warn" style="display:none;margin:4px 0 0;font-size:12px;color:#dc3545;font-weight:bold;"></p>';
        echo '</td>';
        echo '</tr>';

        // Monthly installment amount
        echo '<tr>';
        echo '<th scope="row"><label for="monthly_amount">' . esc_html__( 'Monthly Installment Amount (SAR)', 'sfs-hr' ) . ' <span style="color:red;">*</span></label></th>';
        echo '<td>';
        echo '<input type="number" name="monthly_amount" id="monthly_amount" step="0.01" min="1" required style="width:200px;" />';
        $inst_hint = esc_html__( 'How much you can pay each month', 'sfs-hr' );
        if ( $limits['max_installment'] > 0 ) {
            $inst_hint .= ' · ' . sprintf( esc_html__( 'Max: %s SAR (%d%% of salary)', 'sfs-hr' ), number_format( $limits['max_installment'], 2 ), $limits['max_installment_pct'] );
        }
        echo '<p class="description">' . $inst_hint . '</p>';
        echo '<p id="calculated_months" style="margin-top:8px;font-weight:bold;color:#0073aa;"></p>';
        echo '</td>';
        echo '</tr>';

        // JavaScript calculator with client-side validation
        $ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
        $max_months = $limits['max_months'] ?: 60;
        echo '<script>';
        echo 'var _legacyLimits=' . wp_json_encode( $limits ) . ';';
        echo 'function calculateMonths(){';
        echo 'var p=parseFloat(document.getElementById("principal_amount").value)||0,';
        echo 'm=parseFloat(document.getElementById("monthly_amount").value)||0,';
        echo 'd=document.getElementById("calculated_months"),';
        echo 'w=document.getElementById("legacy_loan_amount_warn"),warns=[];';
        echo 'if(p>0){if(_legacyLimits.max_amount>0&&p>_legacyLimits.max_amount)warns.push("Exceeds max ("+_legacyLimits.max_amount.toLocaleString()+" SAR)");';
        echo 'if(_legacyLimits.max_by_salary>0&&p>_legacyLimits.max_by_salary)warns.push("Exceeds salary limit ("+_legacyLimits.max_by_salary.toLocaleString()+" SAR)");}';
        echo 'if(m>0&&_legacyLimits.max_installment>0&&m>_legacyLimits.max_installment)warns.push("Installment exceeds salary limit ("+_legacyLimits.max_installment.toLocaleString()+" SAR)");';
        echo 'if(w){if(warns.length){w.textContent=warns.join(". ");w.style.display="block";}else{w.textContent="";w.style.display="none";}}';
        echo 'if(p>0&&m>0){var months=Math.ceil(p/m);';
        echo 'if(months>' . $max_months . '){d.textContent="⚠️ Would require "+months+" months (max ' . $max_months . '). Increase monthly amount.";d.style.color="#dc3545";}';
        echo 'else{d.textContent=months+" monthly payments of "+m.toFixed(2)+" SAR = "+(m*months).toFixed(2)+" SAR total";d.style.color="#0073aa";}}';
        echo 'else{d.textContent="";}}';
        echo 'document.getElementById("principal_amount").addEventListener("input",calculateMonths);';
        echo 'document.getElementById("monthly_amount").addEventListener("input",calculateMonths);';
        // AJAX submit for legacy form
        echo '(function(){var f=document.getElementById("sfs-legacy-loan-form");if(!f)return;';
        echo 'f.addEventListener("submit",function(e){e.preventDefault();';
        echo 'var btn=f.querySelector("button[type=submit]"),msg=document.getElementById("sfs-legacy-loan-msg"),origText=btn.textContent;';
        echo 'btn.disabled=true;btn.textContent="' . esc_js( __( 'Submitting...', 'sfs-hr' ) ) . '";msg.style.display="none";';
        echo 'var fd=new FormData(f);fd.set("action","sfs_hr_submit_loan_ajax");';
        echo 'fetch("' . $ajax_url . '",{method:"POST",credentials:"same-origin",body:fd})';
        echo '.then(function(r){return r.json();})';
        echo '.then(function(r){btn.disabled=false;btn.textContent=origText;';
        echo 'msg.style.display="block";';
        echo 'if(r.success){msg.style.background="#d1e7dd";msg.style.color="#0f5132";msg.style.border="1px solid #badbcc";msg.textContent=r.data.message;f.reset();document.getElementById("calculated_months").textContent="";var aw=document.getElementById("legacy_loan_amount_warn");if(aw)aw.style.display="none";setTimeout(function(){location.reload();},1500);}';
        echo 'else{msg.style.background="#f8d7da";msg.style.color="#842029";msg.style.border="1px solid #f5c2c7";msg.textContent=r.data&&r.data.message?r.data.message:"' . esc_js( __( 'An error occurred. Please try again.', 'sfs-hr' ) ) . '";msg.scrollIntoView({behavior:"smooth",block:"nearest"});}';
        echo '}).catch(function(){btn.disabled=false;btn.textContent=origText;msg.style.display="block";msg.style.background="#f8d7da";msg.style.color="#842029";msg.textContent="' . esc_js( __( 'An error occurred. Please try again.', 'sfs-hr' ) ) . '";});});})();';
        echo '</script>';

        // Reason
        echo '<tr>';
        echo '<th scope="row"><label for="reason">' . esc_html__( 'Reason for Loan', 'sfs-hr' ) . ' <span style="color:red;">*</span></label></th>';
        echo '<td>';
        echo '<textarea name="reason" id="reason" rows="3" required style="width:100%;max-width:500px;"></textarea>';
        echo '</td>';
        echo '</tr>';

        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">' . esc_html__( 'Submit Request', 'sfs-hr' ) . '</button>';
        echo ' <button type="button" class="button" onclick="document.getElementById(\'sfs-loan-request-form\').style.display=\'none\';document.querySelector(\'.button-primary\').style.display=\'inline-block\';">' .
             esc_html__( 'Cancel', 'sfs-hr' ) .
             '</button>';
        echo '</p>';

        echo '</form>';
        echo '</div>'; // #sfs-loan-request-form
    }

    /**
     * Render loans list
     */
    private function render_loans_list( array $loans, string $payments_table ): void {
        global $wpdb;

        echo '<table class="widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__( 'Loan #', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Amount', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Remaining', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Installments', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Requested', 'sfs-hr' ) . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ( $loans as $loan ) {
            // Get payment schedule summary
            $paid_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$payments_table} WHERE loan_id = %d AND status = 'paid'",
                $loan->id
            ) );

            echo '<tr>';
            echo '<td><strong>' . esc_html( $loan->loan_number ) . '</strong></td>';
            echo '<td>' . number_format( (float) $loan->principal_amount, 2 ) . ' ' . esc_html( $loan->currency ) . '</td>';
            echo '<td>' . number_format( (float) $loan->remaining_balance, 2 ) . ' ' . esc_html( $loan->currency ) . '</td>';
            echo '<td>' . (int) $paid_count . ' / ' . (int) $loan->installments_count . '</td>';
            echo '<td>' . $this->get_status_badge( $loan->status ) . '</td>';
            echo '<td>' . esc_html( wp_date( 'M j, Y', strtotime( $loan->created_at ) ) ) . '</td>';
            echo '</tr>';

            // Expandable details row
            echo '<tr class="sfs-loan-details-row" id="loan-details-' . (int) $loan->id . '">';
            echo '<td colspan="6" style="padding:0;">';
            echo '<div style="padding:12px;background:#f9f9f9;border-top:1px solid #ddd;">';

            echo '<p><strong>' . esc_html__( 'Reason:', 'sfs-hr' ) . '</strong> ' . esc_html( $loan->reason ) . '</p>';

            if ( $loan->status === 'rejected' && $loan->rejection_reason ) {
                echo '<p style="color:#dc3545;"><strong>' . esc_html__( 'Rejection Reason:', 'sfs-hr' ) . '</strong> ' . esc_html( $loan->rejection_reason ) . '</p>';
            }

            // Show payment schedule for active/completed loans
            if ( in_array( $loan->status, [ 'active', 'completed' ] ) ) {
                $payments = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$payments_table} WHERE loan_id = %d ORDER BY sequence ASC",
                    $loan->id
                ) );

                if ( ! empty( $payments ) ) {
                    echo '<h4 style="margin:12px 0 8px;">' . esc_html__( 'Payment Schedule', 'sfs-hr' ) . '</h4>';
                    echo '<style>
                        .sfs-payment-schedule { width:100%; border-collapse:collapse; font-size:13px; }
                        .sfs-payment-schedule th { text-align:start; padding:8px; background:#f6f7f7; font-weight:600; border-bottom:1px solid #dcdcde; }
                        .sfs-payment-schedule td { padding:8px; border-bottom:1px solid #f0f0f1; }
                        .sfs-payment-schedule .col-num { width:30px; text-align:center; }
                        .sfs-payment-schedule .col-amt { text-align:right; }
                        @media (max-width: 480px) {
                            .sfs-payment-schedule { font-size:11px; }
                            .sfs-payment-schedule th, .sfs-payment-schedule td { padding:6px 4px; }
                        }
                    </style>';
                    echo '<table class="sfs-payment-schedule">';
                    echo '<thead><tr>';
                    echo '<th class="col-num">#</th>';
                    echo '<th>' . esc_html__( 'Due', 'sfs-hr' ) . '</th>';
                    echo '<th class="col-amt">' . esc_html__( 'Amt', 'sfs-hr' ) . '</th>';
                    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
                    echo '</tr></thead><tbody>';

                    foreach ( $payments as $payment ) {
                        echo '<tr>';
                        echo '<td class="col-num">' . (int) $payment->sequence . '</td>';
                        echo '<td>' . esc_html( wp_date( 'M Y', strtotime( $payment->due_date ) ) ) . '</td>';
                        echo '<td class="col-amt">' . number_format( (float) $payment->amount_planned, 2 ) . '</td>';
                        echo '<td>' . $this->get_payment_status_badge( $payment->status ) . '</td>';
                        echo '</tr>';
                    }

                    echo '</tbody></table>';
                }
            }

            echo '</div>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * Get status badge HTML
     */
    private function get_status_badge( string $status ): string {
        $badges = [
            'pending_gm'      => '<span style="background:#ffa500;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">' . esc_html__( 'Pending GM', 'sfs-hr' ) . '</span>',
            'pending_finance' => '<span style="background:#ff8c00;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">' . esc_html__( 'Pending Finance', 'sfs-hr' ) . '</span>',
            'active'          => '<span style="background:#28a745;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">' . esc_html__( 'Active', 'sfs-hr' ) . '</span>',
            'completed'       => '<span style="background:#6c757d;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">' . esc_html__( 'Completed', 'sfs-hr' ) . '</span>',
            'rejected'        => '<span style="background:#dc3545;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">' . esc_html__( 'Rejected', 'sfs-hr' ) . '</span>',
            'cancelled'       => '<span style="background:#6c757d;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">' . esc_html__( 'Cancelled', 'sfs-hr' ) . '</span>',
        ];

        return $badges[ $status ] ?? esc_html( $status );
    }

    /**
     * Get payment status badge
     */
    private function get_payment_status_badge( string $status ): string {
        $badges = [
            'planned'  => '<span style="background:#ffc107;color:#000;padding:2px 6px;border-radius:3px;font-size:11px;">' . esc_html__( 'Planned', 'sfs-hr' ) . '</span>',
            'paid'     => '<span style="background:#28a745;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">' . esc_html__( 'Paid', 'sfs-hr' ) . '</span>',
            'skipped'  => '<span style="background:#6c757d;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">' . esc_html__( 'Skipped', 'sfs-hr' ) . '</span>',
            'partial'  => '<span style="background:#17a2b8;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">' . esc_html__( 'Partial', 'sfs-hr' ) . '</span>',
        ];

        return $badges[ $status ] ?? esc_html( ucfirst( $status ) );
    }

    /**
     * Handle loan request submission
     */
    public function handle_loan_request(): void {
        // Check if user is logged in
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'You must be logged in.', 'sfs-hr' ) );
        }

        $employee_id = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;

        // Verify nonce
        try {
            check_admin_referer( 'sfs_hr_submit_loan_request_' . $employee_id );
        } catch ( \Exception $e ) {
            error_log( 'SFS HR Loans: Nonce verification failed: ' . $e->getMessage() );
            wp_die( esc_html__( 'Security check failed.', 'sfs-hr' ) );
        }

        // Check settings
        $settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();

        if ( ! $settings['enabled'] || ! $settings['allow_employee_requests'] ) {
            wp_die( esc_html__( 'Loan requests are currently disabled.', 'sfs-hr' ) );
        }

        // Verify employee ownership
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $employee = $wpdb->get_row( $wpdb->prepare(
            "SELECT e.*, d.name as department_name
             FROM {$emp_table} e
             LEFT JOIN {$dept_table} d ON e.dept_id = d.id
             WHERE e.id = %d AND e.user_id = %d AND e.status = 'active'",
            $employee_id,
            get_current_user_id()
        ) );

        if ( ! $employee ) {
            error_log( 'SFS HR Loans: Invalid employee record for ID ' . $employee_id . ', User ID ' . get_current_user_id() );
            wp_die( esc_html__( 'Invalid employee record.', 'sfs-hr' ) );
        }

        // Get form data - using monthly amount, not installment count
        $principal = isset( $_POST['principal_amount'] ) ? (float) $_POST['principal_amount'] : 0;
        $monthly_amount = isset( $_POST['monthly_amount'] ) ? (float) $_POST['monthly_amount'] : 0;
        $reason = sanitize_textarea_field( $_POST['reason'] ?? '' );

        // Calculate installments from monthly amount
        // Use floor to get full payments, then add 1 if there's a remainder
        $full_months = $monthly_amount > 0 ? (int) floor( $principal / $monthly_amount ) : 0;
        $last_payment = $principal - ( $full_months * $monthly_amount );
        $installments = $last_payment > 0 ? $full_months + 1 : $full_months;

        // Get redirect URL (stay on frontend)
        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            $redirect_url = home_url();
        }

        // Validate
        if ( $principal <= 0 || $monthly_amount <= 0 || $installments <= 0 || $installments > 60 || ! $reason ) {
            wp_safe_redirect( add_query_arg( [
                'loan_request' => 'error',
                'error' => urlencode( __( 'Invalid input. Please check all fields.', 'sfs-hr' ) ),
            ], $redirect_url ) );
            exit;
        }

        // Check max loan amount
        if ( $settings['max_loan_amount'] > 0 && $principal > $settings['max_loan_amount'] ) {
            error_log( 'SFS HR Loans: Principal exceeds maximum: ' . $principal . ' > ' . $settings['max_loan_amount'] );
            wp_safe_redirect( add_query_arg( [
                'loan_request' => 'error',
                'error' => urlencode( sprintf( __( 'Maximum loan amount is %s SAR', 'sfs-hr' ), number_format( $settings['max_loan_amount'], 2 ) ) ),
            ], $redirect_url ) );
            exit;
        }

        // Check salary multiplier limit
        $multiplier = (float) ( $settings['max_loan_multiplier'] ?? 0 );
        if ( $multiplier > 0 ) {
            $base_salary = (float) ( $employee->base_salary ?? 0 );
            if ( $base_salary <= 0 ) {
                wp_safe_redirect( add_query_arg( [
                    'loan_request' => 'error',
                    'error' => urlencode( __( 'Your base salary is not set. Cannot apply salary multiplier limit. Please contact HR.', 'sfs-hr' ) ),
                ], $redirect_url ) );
                exit;
            }
            $max_by_salary = $base_salary * $multiplier;
            if ( $principal > $max_by_salary ) {
                wp_safe_redirect( add_query_arg( [
                    'loan_request' => 'error',
                    'error' => urlencode( sprintf( __( 'Maximum loan amount is %s SAR (%s× your salary).', 'sfs-hr' ), number_format( $max_by_salary, 2 ), $multiplier ) ),
                ], $redirect_url ) );
                exit;
            }
        }

        // Generate loan number
        $loan_number = \SFS\HR\Modules\Loans\LoansModule::generate_loan_number();

        // Installment amount is the user's monthly payment (last payment may be different)
        $installment_amount = round( $monthly_amount, 2 );

        // Insert loan
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $result = $wpdb->insert( $loans_table, [
            'loan_number'        => $loan_number,
            'employee_id'        => $employee_id,
            'department'         => $employee->department_name ?: 'N/A',
            'principal_amount'   => $principal,
            'currency'           => 'SAR',
            'installments_count' => $installments,
            'installment_amount' => $installment_amount,
            'remaining_balance'  => 0,
            'status'             => 'pending_gm',
            'reason'             => $reason,
            'request_source'     => 'employee_portal',
            'created_by'         => $employee_id, // Employee themselves
            'created_at'         => current_time( 'mysql' ),
            'updated_at'         => current_time( 'mysql' ),
        ] );

        if ( $result === false ) {
            // Log the actual database error
            error_log( 'SFS HR Loans: Failed to insert loan request. Error: ' . $wpdb->last_error );

            wp_safe_redirect( add_query_arg( [
                'loan_request' => 'error',
                'error' => urlencode( __( 'Failed to submit request. Please try again.', 'sfs-hr' ) ),
            ], $redirect_url ) );
            exit;
        }

        $loan_id = $wpdb->insert_id;

        // Log creation
        \SFS\HR\Modules\Loans\LoansModule::log_event( $loan_id, 'loan_created', [
            'created_by'      => 'employee',
            'principal'       => $principal,
            'installments'    => $installments,
            'request_source'  => 'employee_portal',
        ] );

        // Audit Trail: loan created
        do_action( 'sfs_hr_loan_created', $loan_id, [
            'employee_id'   => $employee_id,
            'amount'        => $principal,
            'installments'  => $installments,
            'source'        => 'employee_portal',
        ] );

        // Send notification to GM
        \SFS\HR\Modules\Loans\Notifications::notify_new_loan_request( $loan_id );

        // Redirect with success message (stay on frontend)
        wp_safe_redirect( add_query_arg( [
            'loan_request' => 'success',
        ], $redirect_url ) );
        exit;
    }

    /**
     * AJAX handler for loan request submission with all policy checks.
     */
    public function handle_loan_request_ajax(): void {
        // Verify nonce
        $employee_id = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'sfs_hr_submit_loan_request_' . $employee_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed. Please refresh and try again.', 'sfs-hr' ) ] );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'sfs-hr' ) ] );
        }

        $settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();

        if ( ! $settings['enabled'] || ! $settings['allow_employee_requests'] ) {
            wp_send_json_error( [ 'message' => __( 'Loan requests are currently disabled.', 'sfs-hr' ) ] );
        }

        // Verify employee ownership
        global $wpdb;
        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $employee = $wpdb->get_row( $wpdb->prepare(
            "SELECT e.*, d.name as department_name
             FROM {$emp_table} e
             LEFT JOIN {$dept_table} d ON e.dept_id = d.id
             WHERE e.id = %d AND e.user_id = %d AND e.status = 'active'",
            $employee_id,
            get_current_user_id()
        ) );

        if ( ! $employee ) {
            wp_send_json_error( [ 'message' => __( 'Invalid employee record.', 'sfs-hr' ) ] );
        }

        // Extract form data
        $principal      = isset( $_POST['principal_amount'] ) ? (float) $_POST['principal_amount'] : 0;
        $monthly_amount = isset( $_POST['monthly_amount'] ) ? (float) $_POST['monthly_amount'] : 0;
        $reason         = sanitize_textarea_field( $_POST['reason'] ?? '' );

        // Calculate installments
        $full_months  = $monthly_amount > 0 ? (int) floor( $principal / $monthly_amount ) : 0;
        $last_payment = $principal - ( $full_months * $monthly_amount );
        $installments = $last_payment > 0 ? $full_months + 1 : $full_months;

        // ── Basic validation ──
        if ( $principal <= 0 || $monthly_amount <= 0 || $installments <= 0 || ! $reason ) {
            wp_send_json_error( [ 'message' => __( 'Invalid input. Please check all fields.', 'sfs-hr' ) ] );
        }

        // ── Max installment months ──
        $max_months = (int) ( $settings['max_installment_months'] ?? 60 );
        if ( $max_months > 0 && $installments > $max_months ) {
            wp_send_json_error( [ 'message' => sprintf(
                __( 'Repayment period (%d months) exceeds maximum of %d months. Increase your monthly installment.', 'sfs-hr' ),
                $installments, $max_months
            ) ] );
        }

        // ── Max loan amount ──
        if ( $settings['max_loan_amount'] > 0 && $principal > $settings['max_loan_amount'] ) {
            wp_send_json_error( [ 'message' => sprintf(
                __( 'Maximum loan amount is %s SAR.', 'sfs-hr' ),
                number_format( $settings['max_loan_amount'], 2 )
            ) ] );
        }

        // ── Salary multiplier limit ──
        $multiplier = (float) ( $settings['max_loan_multiplier'] ?? 0 );
        if ( $multiplier > 0 ) {
            $base_salary = (float) ( $employee->base_salary ?? 0 );
            if ( $base_salary <= 0 ) {
                wp_send_json_error( [ 'message' => __( 'Your base salary is not set. Cannot apply salary multiplier limit. Please contact HR.', 'sfs-hr' ) ] );
            }
            $max_by_salary = $base_salary * $multiplier;
            if ( $principal > $max_by_salary ) {
                wp_send_json_error( [ 'message' => sprintf(
                    __( 'Maximum loan amount is %s SAR (%s× your salary).', 'sfs-hr' ),
                    number_format( $max_by_salary, 2 ), $multiplier
                ) ] );
            }
        }

        // ── Max installment percentage of salary ──
        $max_inst_pct = (int) ( $settings['max_installment_percent'] ?? 0 );
        if ( $max_inst_pct > 0 ) {
            $base_salary = (float) ( $employee->base_salary ?? 0 );
            if ( $base_salary <= 0 ) {
                wp_send_json_error( [ 'message' => __( 'Your base salary is not set. Cannot apply installment percentage limit. Please contact HR.', 'sfs-hr' ) ] );
            }
            $max_allowed = round( $base_salary * $max_inst_pct / 100, 2 );
            if ( $monthly_amount > $max_allowed ) {
                wp_send_json_error( [ 'message' => sprintf(
                    __( 'Monthly installment (%s SAR) exceeds maximum (%s SAR = %d%% of salary).', 'sfs-hr' ),
                    number_format( $monthly_amount, 2 ), number_format( $max_allowed, 2 ), $max_inst_pct
                ) ] );
            }
        }

        // ── Minimum service period ──
        $min_service = (int) ( $settings['min_service_months'] ?? 0 );
        if ( $min_service > 0 ) {
            $hired_at = $employee->hired_at ?? $employee->hire_date ?? null;
            if ( ! $hired_at ) {
                wp_send_json_error( [ 'message' => __( 'Your hire date is not set. Please contact HR.', 'sfs-hr' ) ] );
            }
            $hire_dt    = new \DateTime( $hired_at );
            $now_dt     = new \DateTime( current_time( 'mysql' ) );
            $diff       = $hire_dt->diff( $now_dt );
            $months_svc = $diff->y * 12 + $diff->m;
            if ( $months_svc < $min_service ) {
                wp_send_json_error( [ 'message' => sprintf(
                    __( 'You must have at least %d months of service. Current: %d months.', 'sfs-hr' ),
                    $min_service, $months_svc
                ) ] );
            }
        }

        // ── One loan per fiscal year ──
        if ( ! empty( $settings['one_loan_per_fiscal_year'] ) ) {
            $loans_table = $wpdb->prefix . 'sfs_hr_loans';
            $fy_type     = $settings['fiscal_year_type'] ?? 'calendar';
            $now         = current_time( 'mysql' );
            $year        = (int) date( 'Y', strtotime( $now ) );
            $month       = (int) date( 'n', strtotime( $now ) );

            if ( $fy_type === 'custom' ) {
                $fy_start_month = (int) ( $settings['fiscal_year_start_month'] ?? 1 );
                $fy_year = $month >= $fy_start_month ? $year : $year - 1;
                $fy_start = sprintf( '%04d-%02d-01', $fy_year, $fy_start_month );
                $fy_end_year = $fy_year + 1;
                $fy_end = sprintf( '%04d-%02d-01', $fy_end_year, $fy_start_month );
            } else {
                $fy_start = sprintf( '%04d-01-01', $year );
                $fy_end   = sprintf( '%04d-01-01', $year + 1 );
            }

            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$loans_table}
                 WHERE employee_id = %d
                   AND status NOT IN ('rejected','cancelled')
                   AND created_at >= %s AND created_at < %s",
                $employee_id, $fy_start, $fy_end
            ) );

            if ( $existing > 0 ) {
                wp_send_json_error( [ 'message' => sprintf(
                    __( 'You already have a loan in the current fiscal year (%s to %s).', 'sfs-hr' ),
                    wp_date( 'M Y', strtotime( $fy_start ) ),
                    wp_date( 'M Y', strtotime( $fy_end . ' -1 day' ) )
                ) ] );
            }
        }

        // ── Max active loans ──
        if ( empty( $settings['allow_multiple_active_loans'] ) ) {
            $loans_table = $wpdb->prefix . 'sfs_hr_loans';
            $max_active  = (int) ( $settings['max_active_loans_per_employee'] ?? 1 );
            $active_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$loans_table}
                 WHERE employee_id = %d AND status IN ('active','pending_gm','pending_finance')",
                $employee_id
            ) );
            if ( $active_count >= $max_active ) {
                wp_send_json_error( [ 'message' => sprintf(
                    __( 'You already have %d active/pending loan(s). Maximum allowed: %d.', 'sfs-hr' ),
                    $active_count, $max_active
                ) ] );
            }
        }

        // ── All checks passed — insert loan ──
        $loan_number      = \SFS\HR\Modules\Loans\LoansModule::generate_loan_number();
        $installment_amt  = round( $monthly_amount, 2 );
        $loans_table      = $wpdb->prefix . 'sfs_hr_loans';

        $result = $wpdb->insert( $loans_table, [
            'loan_number'        => $loan_number,
            'employee_id'        => $employee_id,
            'department'         => $employee->department_name ?: 'N/A',
            'principal_amount'   => $principal,
            'currency'           => 'SAR',
            'installments_count' => $installments,
            'installment_amount' => $installment_amt,
            'remaining_balance'  => 0,
            'status'             => 'pending_gm',
            'reason'             => $reason,
            'request_source'     => 'employee_portal',
            'created_by'         => $employee_id,
            'created_at'         => current_time( 'mysql' ),
            'updated_at'         => current_time( 'mysql' ),
        ] );

        if ( $result === false ) {
            error_log( 'SFS HR Loans: Failed to insert loan request. Error: ' . $wpdb->last_error );
            wp_send_json_error( [ 'message' => __( 'Failed to submit request. Please try again.', 'sfs-hr' ) ] );
        }

        $loan_id = $wpdb->insert_id;

        \SFS\HR\Modules\Loans\LoansModule::log_event( $loan_id, 'loan_created', [
            'created_by'     => 'employee',
            'principal'      => $principal,
            'installments'   => $installments,
            'request_source' => 'employee_portal',
        ] );

        do_action( 'sfs_hr_loan_created', $loan_id, [
            'employee_id'  => $employee_id,
            'amount'       => $principal,
            'installments' => $installments,
            'source'       => 'employee_portal',
        ] );

        \SFS\HR\Modules\Loans\Notifications::notify_new_loan_request( $loan_id );

        wp_send_json_success( [
            'message'     => __( 'Loan request submitted successfully!', 'sfs-hr' ),
            'loan_number' => $loan_number,
        ] );
    }
}