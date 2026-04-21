<?php
namespace SFS\HR\Modules\Expenses\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Helpers;

/**
 * Advance_Service
 *
 * M10.3 — Cash advance lifecycle and outstanding-balance tracking.
 *
 * Statuses:
 *   pending → approved → paid → settled
 *   Any pending/approved state can transition to `rejected` or `cancelled`.
 *
 * On `paid`, outstanding_amount is initialized to the advance amount.
 * It decreases when:
 *   - An expense claim linked to the advance is paid (offset_from_claim)
 *   - Finance manually records a deduction (record_deduction)
 *   - Payroll deducts remaining balance via the
 *     `sfs_hr_payroll_extra_deductions` filter, which subscribers can
 *     opt into during their payroll run. (See Advance_Service::payroll_filter.)
 *
 * When outstanding_amount reaches zero, status flips to `settled`.
 *
 * @since M10
 */
class Advance_Service {

    const STATUS_PENDING   = 'pending';
    const STATUS_APPROVED  = 'approved';
    const STATUS_PAID      = 'paid';
    const STATUS_SETTLED   = 'settled';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    private const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING   => [ self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED ],
        self::STATUS_APPROVED  => [ self::STATUS_PAID, self::STATUS_CANCELLED ],
        self::STATUS_PAID      => [ self::STATUS_SETTLED ],
        self::STATUS_SETTLED   => [],
        self::STATUS_REJECTED  => [],
        self::STATUS_CANCELLED => [],
    ];

    // ── Create / read ───────────────────────────────────────────────────────

    /**
     * Submit an advance request. Status starts at 'pending'.
     */
    public static function request( array $data ): array {
        global $wpdb;

        $employee_id = (int) ( $data['employee_id'] ?? 0 );
        $amount      = (float) ( $data['amount'] ?? 0 );
        $purpose     = sanitize_text_field( (string) ( $data['purpose'] ?? '' ) );

        if ( $employee_id <= 0 || $amount <= 0 || '' === $purpose ) {
            return [ 'success' => false, 'error' => __( 'employee_id, amount, and purpose are required.', 'sfs-hr' ) ];
        }

        $currency = sanitize_text_field( (string) ( $data['currency'] ?? Expense_Service::get_settings()['currency'] ) );
        $now      = current_time( 'mysql' );
        $ref      = Helpers::generate_reference_number( 'ADV', $wpdb->prefix . 'sfs_hr_expense_advances' );

        $ok = $wpdb->insert( $wpdb->prefix . 'sfs_hr_expense_advances', [
            'request_number'     => $ref,
            'employee_id'        => $employee_id,
            'amount'             => $amount,
            'outstanding_amount' => 0,
            'currency'           => $currency,
            'purpose'            => $purpose,
            'notes'              => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
            'status'             => self::STATUS_PENDING,
            'created_at'         => $now,
            'updated_at'         => $now,
        ], [ '%s', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s' ] );

        if ( ! $ok ) {
            return [ 'success' => false, 'error' => __( 'Failed to save advance request.', 'sfs-hr' ) ];
        }

        $id = (int) $wpdb->insert_id;
        do_action( 'sfs_hr_expense_advance_requested', $id, self::get( $id ) ?? [] );
        return [ 'success' => true, 'id' => $id, 'request_number' => $ref ];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_advances';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return $row ?: null;
    }

    public static function list_for_employee( int $employee_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_advances';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE employee_id = %d ORDER BY id DESC",
            $employee_id
        ), ARRAY_A ) ?: [];
    }

    public static function list_pending(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_advances';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC",
            self::STATUS_PENDING
        ), ARRAY_A ) ?: [];
    }

    /**
     * Sum of outstanding advances for an employee (active = paid status).
     */
    public static function total_outstanding( int $employee_id ): float {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_advances';
        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(outstanding_amount), 0)
             FROM {$table}
             WHERE employee_id = %d AND status = %s",
            $employee_id,
            self::STATUS_PAID
        ) );
        return (float) $total;
    }

    // ── Workflow ────────────────────────────────────────────────────────────

    /**
     * Approve an advance. Enforces:
     *   - Not self-approving (employee ≠ approver).
     *   - Non-admin approver manages the employee's department.
     */
    public static function approve( int $id, int $approver_id, string $note = '' ): array {
        $advance = self::get( $id );
        if ( ! $advance ) {
            return [ 'success' => false, 'error' => __( 'Advance not found.', 'sfs-hr' ) ];
        }

        $emp_uid = Expense_Service::employee_user_id( (int) $advance['employee_id'] );
        if ( $emp_uid && $emp_uid === $approver_id ) {
            return [ 'success' => false, 'error' => __( 'You cannot approve your own advance.', 'sfs-hr' ) ];
        }
        if ( ! user_can( $approver_id, 'sfs_hr.manage' ) && ! Expense_Service::approver_in_scope_for_employee( $approver_id, (int) $advance['employee_id'] ) ) {
            return [ 'success' => false, 'error' => __( 'You can only approve advances for your own department.', 'sfs-hr' ) ];
        }

        return self::set_status( $id, self::STATUS_APPROVED, [
            'approver_id'   => $approver_id,
            'approver_note' => sanitize_textarea_field( $note ),
            'decided_at'    => current_time( 'mysql' ),
        ] );
    }

    public static function reject( int $id, int $approver_id, string $reason = '' ): array {
        $advance = self::get( $id );
        if ( ! $advance ) {
            return [ 'success' => false, 'error' => __( 'Advance not found.', 'sfs-hr' ) ];
        }
        if ( ! user_can( $approver_id, 'sfs_hr.manage' ) && ! Expense_Service::approver_in_scope_for_employee( $approver_id, (int) $advance['employee_id'] ) ) {
            return [ 'success' => false, 'error' => __( 'You can only reject advances for your own department.', 'sfs-hr' ) ];
        }
        return self::set_status( $id, self::STATUS_REJECTED, [
            'approver_id'      => $approver_id,
            'rejection_reason' => sanitize_textarea_field( $reason ),
            'decided_at'       => current_time( 'mysql' ),
        ] );
    }

    public static function cancel( int $id ): array {
        return self::set_status( $id, self::STATUS_CANCELLED, [
            'decided_at' => current_time( 'mysql' ),
        ] );
    }

    /**
     * Finance marks an approved advance as paid — outstanding balance is
     * initialized to the advance amount at this point. Enforces separation
     * of duties: the payer must not be the approver or the employee.
     */
    public static function mark_paid( int $id, int $paid_by, string $reference = '' ): array {
        $advance = self::get( $id );
        if ( ! $advance ) {
            return [ 'success' => false, 'error' => __( 'Advance not found.', 'sfs-hr' ) ];
        }
        $emp_uid = Expense_Service::employee_user_id( (int) $advance['employee_id'] );
        if ( $emp_uid && $emp_uid === $paid_by ) {
            return [ 'success' => false, 'error' => __( 'You cannot pay your own advance.', 'sfs-hr' ) ];
        }
        if ( ! empty( $advance['approver_id'] ) && (int) $advance['approver_id'] === $paid_by ) {
            return [ 'success' => false, 'error' => __( 'Separation of duties: the approver cannot also record the payment.', 'sfs-hr' ) ];
        }
        return self::set_status( $id, self::STATUS_PAID, [
            'paid_at'            => current_time( 'mysql' ),
            'paid_by'            => $paid_by,
            'payment_reference'  => sanitize_text_field( $reference ),
            'outstanding_amount' => (float) $advance['amount'],
        ] );
    }

    /**
     * Offset the outstanding balance when a linked expense claim is paid.
     * Flips the advance to 'settled' once it hits zero.
     */
    public static function offset_from_claim( int $advance_id, float $amount ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_advances';

        $row = self::get( $advance_id );
        if ( ! $row || self::STATUS_PAID !== $row['status'] ) {
            return;
        }

        $new_balance = max( 0.0, (float) $row['outstanding_amount'] - $amount );
        $update      = [
            'outstanding_amount' => $new_balance,
            'updated_at'         => current_time( 'mysql' ),
        ];
        $format      = [ '%f', '%s' ];

        if ( $new_balance <= 0.0 ) {
            $update['status']     = self::STATUS_SETTLED;
            $update['settled_at'] = current_time( 'mysql' );
            $format[] = '%s';
            $format[] = '%s';
        }

        $wpdb->update( $table, $update, [ 'id' => $advance_id ], $format, [ '%d' ] );

        if ( $new_balance <= 0.0 ) {
            do_action( 'sfs_hr_expense_advance_settled', $advance_id );
        }
    }

    /**
     * Manually record a deduction against an advance (e.g., payroll or cash
     * receipt). Same accounting effect as offset_from_claim but typically
     * invoked by finance ops.
     */
    public static function record_deduction( int $advance_id, float $amount ): void {
        self::offset_from_claim( $advance_id, $amount );
    }

    // ── Payroll integration ────────────────────────────────────────────────

    /**
     * Filter callback: produce deduction components for an employee's
     * outstanding advances. Payroll modules that support
     * `sfs_hr_payroll_extra_deductions` may call this to pull in advances.
     *
     * Security: this handler only runs when the payroll engine is actually
     * calculating a run (detected via the `sfs_hr_payroll_run` action being
     * in flight or the `SFS_HR_PAYROLL_RUNNING` constant being set). This
     * prevents unrelated plugins that call the same filter from leaking
     * employee advance balances.
     *
     * @param array $components Existing deductions passed by payroll engine.
     * @param int   $employee_id
     * @return array Components list augmented with advance deduction lines.
     */
    public static function payroll_filter( array $components, int $employee_id ): array {
        // H4 fix: only respond when invoked from a legitimate payroll run.
        $in_payroll = ( defined( 'SFS_HR_PAYROLL_RUNNING' ) && SFS_HR_PAYROLL_RUNNING )
            || doing_action( 'sfs_hr_payroll_run' )
            || doing_action( 'sfs_hr_payroll_run_calculate' )
            || doing_filter( 'sfs_hr_payroll_extra_deductions' ) && current_user_can( 'sfs_hr_payroll_run' );
        if ( ! $in_payroll ) {
            return $components;
        }

        $outstanding = self::total_outstanding( $employee_id );
        if ( $outstanding <= 0 ) {
            return $components;
        }

        $settings = Expense_Service::get_settings();
        // Cap per-payroll deduction if configured; default = full outstanding.
        $cap = isset( $settings['advance_payroll_cap'] ) ? (float) $settings['advance_payroll_cap'] : 0.0;
        $deduction = $cap > 0 ? min( $cap, $outstanding ) : $outstanding;

        $components[] = [
            'code'        => 'EXP_ADV',
            'label'       => __( 'Expense Advance Recovery', 'sfs-hr' ),
            'amount'      => round( $deduction, 2 ),
            'type'        => 'deduction',
            'description' => sprintf(
                /* translators: %s: amount */
                __( 'Outstanding expense advance deduction (%s).', 'sfs-hr' ),
                number_format( $outstanding, 2 )
            ),
        ];

        return $components;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private static function set_status( int $id, string $new_status, array $extra = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_advances';

        $current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );
        if ( ! $current ) {
            return [ 'success' => false, 'error' => __( 'Advance not found.', 'sfs-hr' ) ];
        }

        $allowed = self::ALLOWED_TRANSITIONS[ $current ] ?? [];
        if ( ! in_array( $new_status, $allowed, true ) ) {
            return [ 'success' => false, 'error' => sprintf( __( 'Cannot transition %1$s → %2$s.', 'sfs-hr' ), $current, $new_status ) ];
        }

        $data = array_merge( [
            'status'     => $new_status,
            'updated_at' => current_time( 'mysql' ),
        ], $extra );

        $ok = $wpdb->update( $table, $data, [ 'id' => $id ] );
        if ( false === $ok ) {
            return [ 'success' => false, 'error' => __( 'DB update failed.', 'sfs-hr' ) ];
        }

        do_action( 'sfs_hr_expense_advance_status_changed', $id, (string) $current, $new_status );
        if ( self::STATUS_APPROVED === $new_status ) {
            do_action( 'sfs_hr_expense_advance_approved', $id, self::get( $id ) ?? [] );
        }

        return [ 'success' => true, 'status' => $new_status ];
    }
}
