<?php
namespace SFS\HR\Modules\Loans;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Loan Notifications Handler
 *
 * Handles email notifications for loan lifecycle events:
 * - New request → GM
 * - GM approval → Finance
 * - Finance approval → Employee
 * - Rejection → Employee
 * - Installment skipped → Employee
 */
class Notifications {

    /**
     * Send notification when new loan request is created
     *
     * @param int $loan_id Loan ID
     */
    public static function notify_new_loan_request( int $loan_id ): void {
        $settings = LoansModule::get_settings();

        if ( ! ( $settings['enable_notifications'] ?? true ) ) {
            return;
        }

        if ( ! ( $settings['notify_gm_new_request'] ?? true ) ) {
            return;
        }

        $loan = self::get_loan_data( $loan_id );
        if ( ! $loan ) {
            return;
        }

        // Get GM users to notify
        $gm_users = LoansModule::get_gm_users();

        // If no specific GMs assigned, notify all managers
        if ( empty( $gm_users ) ) {
            $gm_users = get_users( [ 'role' => 'administrator' ] );
        }

        foreach ( $gm_users as $user ) {
            if ( ! $user->user_email ) {
                continue;
            }

            $subject = sprintf(
                /* translators: %s: Loan number */
                __( '[Loan Request] New loan request %s requires your approval', 'sfs-hr' ),
                $loan->loan_number
            );

            $message = self::get_email_template( 'new_request_to_gm', [
                'gm_name'         => $user->display_name,
                'employee_name'   => $loan->employee_name,
                'employee_code'   => $loan->employee_code,
                'loan_number'     => $loan->loan_number,
                'amount'          => number_format( (float) $loan->principal_amount, 2 ),
                'currency'        => $loan->currency,
                'installments'    => $loan->installments_count,
                'reason'          => $loan->reason,
                'loan_url'        => admin_url( 'admin.php?page=sfs-hr-loans&action=view&id=' . $loan_id ),
                'requested_date'  => wp_date( 'F j, Y', strtotime( $loan->created_at ) ),
            ] );

            wp_mail( $user->user_email, $subject, $message, self::get_email_headers() );
        }
    }

    /**
     * Send notification when GM approves loan
     *
     * @param int $loan_id Loan ID
     */
    public static function notify_gm_approved( int $loan_id ): void {
        $settings = LoansModule::get_settings();

        if ( ! ( $settings['enable_notifications'] ?? true ) ) {
            return;
        }

        if ( ! ( $settings['notify_finance_gm_approved'] ?? true ) ) {
            return;
        }

        $loan = self::get_loan_data( $loan_id );
        if ( ! $loan ) {
            return;
        }

        // Get Finance users to notify
        $finance_users = LoansModule::get_finance_users();

        // If no specific Finance users assigned, notify all managers
        if ( empty( $finance_users ) ) {
            $finance_users = get_users( [ 'role' => 'administrator' ] );
        }

        // Get GM who approved
        $gm_user = get_userdata( $loan->approved_gm_by );
        $gm_name = $gm_user ? $gm_user->display_name : __( 'GM', 'sfs-hr' );

        foreach ( $finance_users as $user ) {
            if ( ! $user->user_email ) {
                continue;
            }

            $subject = sprintf(
                /* translators: %s: Loan number */
                __( '[Loan Request] Loan %s approved by GM - Finance approval required', 'sfs-hr' ),
                $loan->loan_number
            );

            $message = self::get_email_template( 'gm_approved_to_finance', [
                'finance_name'    => $user->display_name,
                'gm_name'         => $gm_name,
                'employee_name'   => $loan->employee_name,
                'employee_code'   => $loan->employee_code,
                'loan_number'     => $loan->loan_number,
                'amount'          => number_format( (float) $loan->principal_amount, 2 ),
                'currency'        => $loan->currency,
                'installments'    => $loan->installments_count,
                'reason'          => $loan->reason,
                'loan_url'        => admin_url( 'admin.php?page=sfs-hr-loans&action=view&id=' . $loan_id ),
                'approved_date'   => wp_date( 'F j, Y', strtotime( $loan->approved_gm_at ) ),
            ] );

            wp_mail( $user->user_email, $subject, $message, self::get_email_headers() );
        }
    }

    /**
     * Send notification when Finance approves loan
     *
     * @param int $loan_id Loan ID
     */
    public static function notify_finance_approved( int $loan_id ): void {
        $settings = LoansModule::get_settings();

        if ( ! ( $settings['enable_notifications'] ?? true ) ) {
            return;
        }

        if ( ! ( $settings['notify_employee_approved'] ?? true ) ) {
            return;
        }

        $loan = self::get_loan_data( $loan_id );
        if ( ! $loan ) {
            return;
        }

        // Get employee user
        $employee_user = $loan->user_id ? get_userdata( $loan->user_id ) : null;
        if ( ! $employee_user || ! $employee_user->user_email ) {
            return;
        }

        $subject = sprintf(
            /* translators: %s: Loan number */
            __( '[Loan Request] Your loan request %s has been approved!', 'sfs-hr' ),
            $loan->loan_number
        );

        $message = self::get_email_template( 'approved_to_employee', [
            'employee_name'   => $loan->employee_name,
            'loan_number'     => $loan->loan_number,
            'amount'          => number_format( (float) $loan->principal_amount, 2 ),
            'currency'        => $loan->currency,
            'installments'    => $loan->installments_count,
            'installment_amount' => number_format( (float) $loan->installment_amount, 2 ),
            'first_due_date'  => $loan->first_due_date ? wp_date( 'F j, Y', strtotime( $loan->first_due_date ) ) : __( 'TBD', 'sfs-hr' ),
            'last_due_date'   => $loan->last_due_date ? wp_date( 'F j, Y', strtotime( $loan->last_due_date ) ) : __( 'TBD', 'sfs-hr' ),
            'approved_date'   => wp_date( 'F j, Y', strtotime( $loan->approved_finance_at ) ),
        ] );

        wp_mail( $employee_user->user_email, $subject, $message, self::get_email_headers() );
    }

    /**
     * Send notification when loan is rejected
     *
     * @param int $loan_id Loan ID
     */
    public static function notify_loan_rejected( int $loan_id ): void {
        $settings = LoansModule::get_settings();

        if ( ! ( $settings['enable_notifications'] ?? true ) ) {
            return;
        }

        if ( ! ( $settings['notify_employee_rejected'] ?? true ) ) {
            return;
        }

        $loan = self::get_loan_data( $loan_id );
        if ( ! $loan ) {
            return;
        }

        // Get employee user
        $employee_user = $loan->user_id ? get_userdata( $loan->user_id ) : null;
        if ( ! $employee_user || ! $employee_user->user_email ) {
            return;
        }

        // Get rejector
        $rejected_by_user = get_userdata( $loan->rejected_by );
        $rejected_by_name = $rejected_by_user ? $rejected_by_user->display_name : __( 'Management', 'sfs-hr' );

        $subject = sprintf(
            /* translators: %s: Loan number */
            __( '[Loan Request] Your loan request %s has been declined', 'sfs-hr' ),
            $loan->loan_number
        );

        $message = self::get_email_template( 'rejected_to_employee', [
            'employee_name'     => $loan->employee_name,
            'loan_number'       => $loan->loan_number,
            'amount'            => number_format( (float) $loan->principal_amount, 2 ),
            'currency'          => $loan->currency,
            'rejection_reason'  => $loan->rejection_reason,
            'rejected_by'       => $rejected_by_name,
            'rejected_date'     => wp_date( 'F j, Y', strtotime( $loan->rejected_at ) ),
        ] );

        wp_mail( $employee_user->user_email, $subject, $message, self::get_email_headers() );
    }

    /**
     * Send notification when installment is skipped
     *
     * @param int $loan_id Loan ID
     * @param int $sequence Installment sequence number
     */
    public static function notify_installment_skipped( int $loan_id, int $sequence ): void {
        $settings = LoansModule::get_settings();

        if ( ! ( $settings['enable_notifications'] ?? true ) ) {
            return;
        }

        if ( ! ( $settings['notify_employee_installment_skipped'] ?? true ) ) {
            return;
        }

        $loan = self::get_loan_data( $loan_id );
        if ( ! $loan ) {
            return;
        }

        // Get employee user
        $employee_user = $loan->user_id ? get_userdata( $loan->user_id ) : null;
        if ( ! $employee_user || ! $employee_user->user_email ) {
            return;
        }

        $subject = sprintf(
            /* translators: 1: Loan number, 2: Installment number */
            __( '[Loan Payment] Installment #%2$d of loan %1$s was skipped', 'sfs-hr' ),
            $loan->loan_number,
            $sequence
        );

        $message = self::get_email_template( 'installment_skipped_to_employee', [
            'employee_name'      => $loan->employee_name,
            'loan_number'        => $loan->loan_number,
            'sequence'           => $sequence,
            'remaining_balance'  => number_format( (float) $loan->remaining_balance, 2 ),
            'currency'           => $loan->currency,
            'installment_amount' => number_format( (float) $loan->installment_amount, 2 ),
        ] );

        wp_mail( $employee_user->user_email, $subject, $message, self::get_email_headers() );
    }

    /**
     * Get loan data with employee information
     *
     * @param int $loan_id Loan ID
     * @return object|null
     */
    private static function get_loan_data( int $loan_id ) {
        global $wpdb;

        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT l.*,
                    COALESCE(
                        NULLIF(TRIM(CONCAT(e.first_name, ' ', e.last_name)), ''),
                        e.employee_code,
                        CONCAT('Employee #', l.employee_id)
                    ) as employee_name,
                    e.employee_code,
                    e.user_id
             FROM {$loans_table} l
             LEFT JOIN {$emp_table} e ON l.employee_id = e.id
             WHERE l.id = %d",
            $loan_id
        ) );
    }

    /**
     * Get email template
     *
     * @param string $template Template name
     * @param array $vars Template variables
     * @return string
     */
    private static function get_email_template( string $template, array $vars ): string {
        $site_name = get_bloginfo( 'name' );

        $templates = [
            'new_request_to_gm' => "
Hello {gm_name},

A new loan request has been submitted and requires your approval.

Loan Details:
--------------
Loan Number: {loan_number}
Employee: {employee_name} ({employee_code})
Amount Requested: {amount} {currency}
Number of Installments: {installments}
Requested Date: {requested_date}

Reason:
{reason}

Please review and approve or reject this loan request:
{loan_url}

---
{site_name}
HR Management System
",

            'gm_approved_to_finance' => "
Hello {finance_name},

A loan request has been approved by {gm_name} and now requires Finance approval.

Loan Details:
--------------
Loan Number: {loan_number}
Employee: {employee_name} ({employee_code})
Amount: {amount} {currency}
Number of Installments: {installments}
GM Approved Date: {approved_date}

Reason:
{reason}

Please review and finalize this loan request:
{loan_url}

---
{site_name}
HR Management System
",

            'approved_to_employee' => "
Hello {employee_name},

Great news! Your loan request has been approved.

Loan Details:
--------------
Loan Number: {loan_number}
Approved Amount: {amount} {currency}
Number of Installments: {installments}
Monthly Installment: {installment_amount} {currency}
First Deduction Date: {first_due_date}
Last Deduction Date: {last_due_date}
Approval Date: {approved_date}

The installment amount will be automatically deducted from your monthly salary starting from the first deduction date.

If you have any questions, please contact the Finance department.

---
{site_name}
HR Management System
",

            'rejected_to_employee' => "
Hello {employee_name},

We regret to inform you that your loan request has been declined.

Loan Details:
--------------
Loan Number: {loan_number}
Requested Amount: {amount} {currency}
Declined By: {rejected_by}
Declined Date: {rejected_date}

Reason for Decline:
{rejection_reason}

If you have any questions or would like to discuss this further, please contact the HR department.

---
{site_name}
HR Management System
",

            'installment_skipped_to_employee' => "
Hello {employee_name},

This is to inform you that installment #{sequence} of your loan {loan_number} was skipped for this month.

Loan Details:
--------------
Loan Number: {loan_number}
Skipped Installment: #{sequence}
Installment Amount: {installment_amount} {currency}
Remaining Balance: {remaining_balance} {currency}

The skipped installment amount will be added to your remaining balance. Please contact the Finance department if you have any questions.

---
{site_name}
HR Management System
",
        ];

        $template_content = $templates[ $template ] ?? '';
        $vars['site_name'] = $site_name;

        // Replace variables
        foreach ( $vars as $key => $value ) {
            $template_content = str_replace( '{' . $key . '}', $value, $template_content );
        }

        return $template_content;
    }

    /**
     * Get email headers for HTML emails
     *
     * @return array
     */
    private static function get_email_headers(): array {
        return [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
        ];
    }
}