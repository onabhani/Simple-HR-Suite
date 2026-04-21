<?php
namespace SFS\HR\Modules\Expenses\Handlers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Expenses\Services\Expense_Service;
use SFS\HR\Modules\Expenses\Services\Advance_Service;

/**
 * Expense_Handlers
 *
 * admin_post handlers for the employee frontend portal (shortcode forms).
 * REST endpoints cover programmatic access; these are the WP-native
 * form-POST paths used by the shortcode.
 *
 * @since M10
 */
class Expense_Handlers {

    public function hooks(): void {
        add_action( 'admin_post_sfs_hr_expense_submit_claim',    [ $this, 'submit_claim' ] );
        add_action( 'admin_post_sfs_hr_expense_request_advance', [ $this, 'request_advance' ] );
    }

    public function submit_claim(): void {
        $this->require_auth();
        check_admin_referer( 'sfs_hr_expense_submit_claim', '_sfs_nonce' );

        $emp_id = $this->current_employee_id();
        if ( ! $emp_id ) {
            wp_die( esc_html__( 'No HR profile linked to your account.', 'sfs-hr' ) );
        }

        $title    = sanitize_text_field( wp_unslash( (string) ( $_POST['title'] ?? '' ) ) );
        $items    = [];
        $advance  = isset( $_POST['advance_id'] ) ? (int) $_POST['advance_id'] : null;

        // Support multi-line items from parallel-indexed arrays
        $categories = isset( $_POST['item_category'] ) && is_array( $_POST['item_category'] ) ? (array) $_POST['item_category'] : [];
        $dates      = isset( $_POST['item_date'] ) && is_array( $_POST['item_date'] ) ? (array) $_POST['item_date'] : [];
        $amounts    = isset( $_POST['item_amount'] ) && is_array( $_POST['item_amount'] ) ? (array) $_POST['item_amount'] : [];
        $descs      = isset( $_POST['item_description'] ) && is_array( $_POST['item_description'] ) ? (array) $_POST['item_description'] : [];
        $receipts   = isset( $_POST['item_receipt_media_id'] ) && is_array( $_POST['item_receipt_media_id'] ) ? (array) $_POST['item_receipt_media_id'] : [];

        $count = count( $categories );
        for ( $i = 0; $i < $count; $i++ ) {
            $amount = (float) ( $amounts[ $i ] ?? 0 );
            if ( $amount <= 0 ) continue;
            $items[] = [
                'category_id'      => (int) ( $categories[ $i ] ?? 0 ),
                'item_date'        => sanitize_text_field( (string) ( $dates[ $i ] ?? '' ) ),
                'amount'           => $amount,
                'description'      => sanitize_textarea_field( (string) ( $descs[ $i ] ?? '' ) ),
                'receipt_media_id' => isset( $receipts[ $i ] ) ? (int) $receipts[ $i ] : 0,
            ];
        }

        $result = Expense_Service::create_draft( [
            'employee_id' => $emp_id,
            'advance_id'  => $advance,
            'title'       => $title,
            'description' => sanitize_textarea_field( wp_unslash( (string) ( $_POST['description'] ?? '' ) ) ),
            'items'       => $items,
        ] );
        if ( ! ( $result['success'] ?? false ) ) {
            wp_die( esc_html( $result['error'] ?? __( 'Submission failed.', 'sfs-hr' ) ) );
        }

        // Immediately submit for approval.
        if ( ! empty( $_POST['submit_for_approval'] ) ) {
            Expense_Service::submit( (int) $result['id'] );
        }

        $redirect = wp_get_referer() ?: home_url( '/' );
        wp_safe_redirect( add_query_arg( [ 'expense' => 'ok', 'ref' => rawurlencode( $result['request_number'] ?? '' ) ], $redirect ) );
        exit;
    }

    public function request_advance(): void {
        $this->require_auth();
        check_admin_referer( 'sfs_hr_expense_request_advance', '_sfs_nonce' );

        $emp_id = $this->current_employee_id();
        if ( ! $emp_id ) {
            wp_die( esc_html__( 'No HR profile linked to your account.', 'sfs-hr' ) );
        }

        $result = Advance_Service::request( [
            'employee_id' => $emp_id,
            'amount'      => (float) ( $_POST['amount'] ?? 0 ),
            'purpose'     => sanitize_text_field( wp_unslash( (string) ( $_POST['purpose'] ?? '' ) ) ),
            'notes'       => sanitize_textarea_field( wp_unslash( (string) ( $_POST['notes'] ?? '' ) ) ),
        ] );

        $redirect = wp_get_referer() ?: home_url( '/' );
        $args     = ( $result['success'] ?? false )
            ? [ 'advance' => 'ok', 'ref' => rawurlencode( $result['request_number'] ?? '' ) ]
            : [ 'advance' => 'err', 'msg' => rawurlencode( $result['error'] ?? 'failed' ) ];
        wp_safe_redirect( add_query_arg( $args, $redirect ) );
        exit;
    }

    private function require_auth(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'You must be logged in.', 'sfs-hr' ) );
        }
    }

    private function current_employee_id(): ?int {
        global $wpdb;
        $uid = get_current_user_id();
        $id  = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d LIMIT 1",
            $uid
        ) );
        return $id ? (int) $id : null;
    }
}
