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

        // Handle loan request submission
        add_action( 'admin_post_sfs_hr_submit_loan_request', [ $this, 'handle_loan_request' ] );
    }

    /**
     * Add "Loans" tab to My Profile tabs
     */
    public function add_loans_tab( \stdClass $employee ): void {
        $settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();

        // Only show if enabled in settings
        if ( ! $settings['show_in_my_profile'] ) {
            return;
        }

        $active = ( isset( $_GET['tab'] ) && $_GET['tab'] === 'loans' );
        $class  = 'nav-tab' . ( $active ? ' nav-tab-active' : '' );
        $url    = admin_url( 'admin.php?page=sfs-hr-my-profile&tab=loans' );

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
        echo '<h2 style="margin:0 0 16px;">' . esc_html__( 'My Loans', 'sfs-hr' ) . '</h2>';

        // Request new loan button (if enabled)
        if ( $settings['allow_employee_requests'] ) {
            echo '<div style="margin-bottom:16px;">';
            echo '<button type="button" class="button button-primary" onclick="document.getElementById(\'sfs-loan-request-form\').style.display=\'block\';this.style.display=\'none\';">';
            esc_html_e( 'Request New Loan', 'sfs-hr' );
            echo '</button>';
            echo '</div>';

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
     * Render loan request form
     */
    private function render_loan_request_form( \stdClass $employee ): void {
        $settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();

        echo '<div id="sfs-loan-request-form" style="display:none;background:#fff;padding:20px;border:1px solid #ccc;border-radius:4px;margin-bottom:20px;max-width:600px;">';
        echo '<h3>' . esc_html__( 'Request New Loan', 'sfs-hr' ) . '</h3>';

        // Show error/success messages
        if ( isset( $_GET['loan_request'] ) ) {
            if ( $_GET['loan_request'] === 'success' ) {
                echo '<div class="notice notice-success" style="margin:10px 0;"><p>' .
                     esc_html__( 'Loan request submitted successfully!', 'sfs-hr' ) .
                     '</p></div>';
            } elseif ( $_GET['loan_request'] === 'error' ) {
                $error_msg = isset( $_GET['error'] ) ? urldecode( $_GET['error'] ) : __( 'Failed to submit loan request.', 'sfs-hr' );
                echo '<div class="notice notice-error" style="margin:10px 0;"><p>' .
                     esc_html( $error_msg ) .
                     '</p></div>';
            }
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'sfs_hr_submit_loan_request_' . $employee->id );
        echo '<input type="hidden" name="action" value="sfs_hr_submit_loan_request" />';
        echo '<input type="hidden" name="employee_id" value="' . (int) $employee->id . '" />';

        echo '<table class="form-table">';

        // Principal amount
        echo '<tr>';
        echo '<th scope="row"><label for="principal_amount">' . esc_html__( 'Loan Amount (SAR)', 'sfs-hr' ) . ' <span style="color:red;">*</span></label></th>';
        echo '<td>';
        echo '<input type="number" name="principal_amount" id="principal_amount" step="0.01" min="1" required style="width:200px;" />';
        if ( $settings['max_loan_amount'] > 0 ) {
            echo '<p class="description">' .
                 sprintf( esc_html__( 'Maximum: %s SAR', 'sfs-hr' ), number_format( $settings['max_loan_amount'], 2 ) ) .
                 '</p>';
        }
        echo '</td>';
        echo '</tr>';

        // Installments
        echo '<tr>';
        echo '<th scope="row"><label for="installments_count">' . esc_html__( 'Number of Installments', 'sfs-hr' ) . ' <span style="color:red;">*</span></label></th>';
        echo '<td>';
        echo '<input type="number" name="installments_count" id="installments_count" min="1" max="60" required style="width:100px;" />';
        echo '<p class="description">' . esc_html__( 'Monthly installments (1-60 months)', 'sfs-hr' ) . '</p>';
        echo '</td>';
        echo '</tr>';

        // Reason
        echo '<tr>';
        echo '<th scope="row"><label for="reason">' . esc_html__( 'Reason for Loan', 'sfs-hr' ) . ' <span style="color:red;">*</span></label></th>';
        echo '<td>';
        echo '<textarea name="reason" id="reason" rows="4" required style="width:100%;max-width:500px;"></textarea>';
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
                    echo '<table class="widefat" style="margin-top:8px;">';
                    echo '<thead><tr>';
                    echo '<th style="width:50px;">#</th>';
                    echo '<th>' . esc_html__( 'Due Date', 'sfs-hr' ) . '</th>';
                    echo '<th>' . esc_html__( 'Amount', 'sfs-hr' ) . '</th>';
                    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
                    echo '</tr></thead><tbody>';

                    foreach ( $payments as $payment ) {
                        echo '<tr>';
                        echo '<td>' . (int) $payment->sequence . '</td>';
                        echo '<td>' . esc_html( wp_date( 'M Y', strtotime( $payment->due_date ) ) ) . '</td>';
                        echo '<td>' . number_format( (float) $payment->amount_planned, 2 ) . '</td>';
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
            'pending_gm'      => '<span style="background:#ffa500;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Pending GM</span>',
            'pending_finance' => '<span style="background:#ff8c00;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Pending Finance</span>',
            'active'          => '<span style="background:#28a745;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Active</span>',
            'completed'       => '<span style="background:#6c757d;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Completed</span>',
            'rejected'        => '<span style="background:#dc3545;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Rejected</span>',
            'cancelled'       => '<span style="background:#6c757d;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Cancelled</span>',
        ];

        return $badges[ $status ] ?? esc_html( $status );
    }

    /**
     * Get payment status badge
     */
    private function get_payment_status_badge( string $status ): string {
        $badges = [
            'planned'  => '<span style="background:#ffc107;color:#000;padding:2px 6px;border-radius:3px;font-size:11px;">Planned</span>',
            'paid'     => '<span style="background:#28a745;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">Paid</span>',
            'skipped'  => '<span style="background:#6c757d;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">Skipped</span>',
            'partial'  => '<span style="background:#17a2b8;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">Partial</span>',
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
        check_admin_referer( 'sfs_hr_submit_loan_request_' . $employee_id );

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
            wp_die( esc_html__( 'Invalid employee record.', 'sfs-hr' ) );
        }

        // Get form data
        $principal = isset( $_POST['principal_amount'] ) ? (float) $_POST['principal_amount'] : 0;
        $installments = isset( $_POST['installments_count'] ) ? (int) $_POST['installments_count'] : 0;
        $reason = sanitize_textarea_field( $_POST['reason'] ?? '' );

        // Validate
        if ( $principal <= 0 || $installments <= 0 || $installments > 60 || ! $reason ) {
            wp_safe_redirect( add_query_arg( [
                'page' => 'sfs-hr-my-profile',
                'tab' => 'loans',
                'loan_request' => 'error',
                'error' => urlencode( __( 'Invalid input. Please check all fields.', 'sfs-hr' ) ),
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Check max loan amount
        if ( $settings['max_loan_amount'] > 0 && $principal > $settings['max_loan_amount'] ) {
            wp_safe_redirect( add_query_arg( [
                'page' => 'sfs-hr-my-profile',
                'tab' => 'loans',
                'loan_request' => 'error',
                'error' => urlencode( sprintf( __( 'Maximum loan amount is %s SAR', 'sfs-hr' ), number_format( $settings['max_loan_amount'], 2 ) ) ),
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Generate loan number
        $loan_number = \SFS\HR\Modules\Loans\LoansModule::generate_loan_number();

        // Calculate installment amount
        $installment_amount = round( $principal / $installments, 2 );

        // Insert loan
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $wpdb->insert( $loans_table, [
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

        $loan_id = $wpdb->insert_id;

        if ( ! $loan_id ) {
            wp_safe_redirect( add_query_arg( [
                'page' => 'sfs-hr-my-profile',
                'tab' => 'loans',
                'loan_request' => 'error',
                'error' => urlencode( __( 'Failed to submit request. Please try again.', 'sfs-hr' ) ),
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Log creation
        \SFS\HR\Modules\Loans\LoansModule::log_event( $loan_id, 'loan_created', [
            'created_by'      => 'employee',
            'principal'       => $principal,
            'installments'    => $installments,
            'request_source'  => 'employee_portal',
        ] );

        // Send notification to GM
        \SFS\HR\Modules\Loans\Notifications::notify_new_loan_request( $loan_id );

        // Redirect with success message
        wp_safe_redirect( add_query_arg( [
            'page' => 'sfs-hr-my-profile',
            'tab' => 'loans',
            'loan_request' => 'success',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }
}