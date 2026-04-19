<?php
namespace SFS\HR\Modules\Payroll\Services;

use SFS\HR\Core\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * AdjustmentService
 *
 * Manages post-approval payroll adjustments against individual payroll items.
 *
 * Design:
 *   - Adjustments are ADDITIVE: the original payroll_item is never modified
 *     until an adjustment is approved, at which point totals are updated in place.
 *   - Approve/reject use an atomic UPDATE … WHERE status = 'pending' to prevent
 *     double-processing races without needing a separate lock.
 *   - All status transitions fire do_action hooks so external modules (audit,
 *     notifications) can react without coupling.
 *
 * Table: sfs_hr_payroll_adjustments
 *   (created by PayrollModule::install_tables via dbDelta)
 */
class AdjustmentService {

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Create a payroll adjustment for an employee in a completed payroll run.
     *
     * @param int    $payroll_item_id  ID of the original sfs_hr_payroll_items row.
     * @param array  $adjustments      Array of line items:
     *                                 [['component_code'=>string,'amount'=>float,'reason'=>string], …]
     * @param string $reason           Overall reason text for the adjustment.
     * @param int    $created_by       WP user ID creating this record.
     * @return array{success:bool,id?:int,error?:string}
     */
    public static function create(
        int $payroll_item_id,
        array $adjustments,
        string $reason,
        int $created_by
    ): array {
        global $wpdb;

        // ---- Validate item exists ----
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, run_id, employee_id, total_earnings, total_deductions, net_salary
                 FROM {$wpdb->prefix}sfs_hr_payroll_items
                 WHERE id = %d",
                $payroll_item_id
            )
        );

        if ( ! $item ) {
            return [ 'success' => false, 'error' => 'Payroll item not found.' ];
        }

        // ---- Validate adjustment lines ----
        if ( empty( $adjustments ) ) {
            return [ 'success' => false, 'error' => 'At least one adjustment line is required.' ];
        }

        $total_amount = 0.0;
        $sanitized_lines = [];

        foreach ( $adjustments as $index => $line ) {
            if ( empty( $line['component_code'] ) || ! is_string( $line['component_code'] ) ) {
                return [ 'success' => false, 'error' => "Line {$index}: component_code is required." ];
            }
            if ( ! isset( $line['amount'] ) || ! is_numeric( $line['amount'] ) ) {
                return [ 'success' => false, 'error' => "Line {$index}: amount must be numeric." ];
            }

            $amount = (float) $line['amount'];
            $total_amount += $amount;

            $sanitized_lines[] = [
                'component_code' => sanitize_text_field( $line['component_code'] ),
                'amount'         => $amount,
                'reason'         => sanitize_textarea_field( $line['reason'] ?? '' ),
            ];
        }

        // ---- Derive adjustment_type from net total ----
        $adjustment_type = self::derive_type( $total_amount, $reason );

        $now = Helpers::now_mysql();

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}sfs_hr_payroll_adjustments",
            [
                'payroll_item_id' => $payroll_item_id,
                'run_id'          => (int) $item->run_id,
                'employee_id'     => (int) $item->employee_id,
                'adjustment_type' => $adjustment_type,
                'total_amount'    => round( $total_amount, 2 ),
                'reason'          => sanitize_textarea_field( $reason ),
                'status'          => 'pending',
                'lines_json'      => wp_json_encode( $sanitized_lines ),
                'created_by'      => $created_by,
                'created_at'      => $now,
            ],
            [ '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s' ]
        );

        if ( ! $inserted ) {
            return [
                'success' => false,
                'error'   => 'Database insert failed: ' . $wpdb->last_error,
            ];
        }

        $adjustment_id = (int) $wpdb->insert_id;

        /**
         * Fires after a payroll adjustment record is created (status = pending).
         *
         * @param int   $adjustment_id
         * @param array $data  Snapshot of the inserted row fields.
         */
        do_action( 'sfs_hr_payroll_adjustment_created', $adjustment_id, [
            'payroll_item_id' => $payroll_item_id,
            'run_id'          => (int) $item->run_id,
            'employee_id'     => (int) $item->employee_id,
            'adjustment_type' => $adjustment_type,
            'total_amount'    => round( $total_amount, 2 ),
            'reason'          => $reason,
            'lines'           => $sanitized_lines,
            'created_by'      => $created_by,
        ] );

        return [ 'success' => true, 'id' => $adjustment_id ];
    }

    /**
     * Approve a pending adjustment.
     *
     * Uses an atomic WHERE status = 'pending' guard to prevent double-approve.
     * On success, propagates the adjustment totals into the linked payroll item.
     *
     * @param int $adjustment_id
     * @param int $approved_by   WP user ID performing approval.
     * @return array{success:bool,error?:string}
     */
    public static function approve( int $adjustment_id, int $approved_by ): array {
        global $wpdb;

        $now = Helpers::now_mysql();

        // Atomic status transition: only succeeds when still pending.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}sfs_hr_payroll_adjustments
                 SET status = 'approved', approved_by = %d, approved_at = %s
                 WHERE id = %d AND status = 'pending'",
                $approved_by,
                $now,
                $adjustment_id
            )
        );

        if ( $updated === false ) {
            return [
                'success' => false,
                'error'   => 'Database error: ' . $wpdb->last_error,
            ];
        }

        if ( $updated === 0 ) {
            // Either not found or already transitioned — retrieve current status for error detail.
            $current = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}sfs_hr_payroll_adjustments WHERE id = %d",
                    $adjustment_id
                )
            );

            if ( ! $current ) {
                return [ 'success' => false, 'error' => 'Adjustment not found.' ];
            }

            return [
                'success' => false,
                'error'   => "Adjustment cannot be approved; current status is '{$current}'.",
            ];
        }

        // Fetch full record to update payroll item and fire hook.
        $adj = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_payroll_adjustments WHERE id = %d",
                $adjustment_id
            ),
            ARRAY_A
        );

        $lines = json_decode( $adj['lines_json'] ?? '[]', true );
        if ( ! is_array( $lines ) ) {
            $lines = [];
        }

        // Apply adjustment amounts to the payroll item.
        self::apply_to_item( (int) $adj['payroll_item_id'], $lines );

        /**
         * Fires after a payroll adjustment is approved and item totals updated.
         *
         * @param int   $adjustment_id
         * @param array $data  Full adjustment row (ARRAY_A).
         */
        do_action( 'sfs_hr_payroll_adjustment_approved', $adjustment_id, $adj );

        return [ 'success' => true ];
    }

    /**
     * Reject a pending adjustment.
     *
     * Uses an atomic WHERE status = 'pending' guard identical to approve().
     *
     * @param int    $adjustment_id
     * @param int    $rejected_by       WP user ID performing rejection.
     * @param string $rejection_reason  Optional reason text stored on the record.
     * @return array{success:bool,error?:string}
     */
    public static function reject(
        int $adjustment_id,
        int $rejected_by,
        string $rejection_reason = ''
    ): array {
        global $wpdb;

        $now = Helpers::now_mysql();

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}sfs_hr_payroll_adjustments
                 SET status = 'rejected', rejected_by = %d, rejected_at = %s,
                     rejection_reason = %s
                 WHERE id = %d AND status = 'pending'",
                $rejected_by,
                $now,
                sanitize_textarea_field( $rejection_reason ),
                $adjustment_id
            )
        );

        if ( $updated === false ) {
            return [
                'success' => false,
                'error'   => 'Database error: ' . $wpdb->last_error,
            ];
        }

        if ( $updated === 0 ) {
            $current = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}sfs_hr_payroll_adjustments WHERE id = %d",
                    $adjustment_id
                )
            );

            if ( ! $current ) {
                return [ 'success' => false, 'error' => 'Adjustment not found.' ];
            }

            return [
                'success' => false,
                'error'   => "Adjustment cannot be rejected; current status is '{$current}'.",
            ];
        }

        $adj = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_payroll_adjustments WHERE id = %d",
                $adjustment_id
            ),
            ARRAY_A
        );

        /**
         * Fires after a payroll adjustment is rejected.
         *
         * @param int   $adjustment_id
         * @param array $data  Full adjustment row (ARRAY_A).
         */
        do_action( 'sfs_hr_payroll_adjustment_rejected', $adjustment_id, $adj );

        return [ 'success' => true ];
    }

    /**
     * Retrieve adjustments, optionally filtered.
     *
     * Supported filter keys:
     *   - run_id         int
     *   - payroll_item_id int
     *   - employee_id    int
     *   - status         string  'pending'|'approved'|'rejected'
     *   - adjustment_type string
     *   - limit          int    (default 100, max 500)
     *   - offset         int    (default 0)
     *   - order          string 'ASC'|'DESC' (default 'DESC')
     *
     * @param array $filters
     * @return array  Array of adjustment rows as associative arrays with lines_json decoded.
     */
    public static function get_adjustments( array $filters = [] ): array {
        global $wpdb;

        $table  = "{$wpdb->prefix}sfs_hr_payroll_adjustments";
        $where  = [];
        $params = [];

        $allowed_statuses = [ 'pending', 'approved', 'rejected' ];
        $allowed_types    = [ 'correction', 'reversal', 'bonus', 'deduction' ];

        if ( ! empty( $filters['run_id'] ) ) {
            $where[]  = 'run_id = %d';
            $params[] = (int) $filters['run_id'];
        }

        if ( ! empty( $filters['payroll_item_id'] ) ) {
            $where[]  = 'payroll_item_id = %d';
            $params[] = (int) $filters['payroll_item_id'];
        }

        if ( ! empty( $filters['employee_id'] ) ) {
            $where[]  = 'employee_id = %d';
            $params[] = (int) $filters['employee_id'];
        }

        if ( ! empty( $filters['status'] ) && in_array( $filters['status'], $allowed_statuses, true ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        if ( ! empty( $filters['adjustment_type'] ) && in_array( $filters['adjustment_type'], $allowed_types, true ) ) {
            $where[]  = 'adjustment_type = %s';
            $params[] = $filters['adjustment_type'];
        }

        $limit  = min( (int) ( $filters['limit'] ?? 100 ), 500 );
        $offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );
        $order  = strtoupper( $filters['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM {$table}";

        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }

        $sql .= " ORDER BY created_at {$order} LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dynamic but safe: only literals/placeholders above.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        // Decode lines_json on each row so callers get a usable array.
        foreach ( $rows as &$row ) {
            $decoded = json_decode( $row['lines_json'] ?? '[]', true );
            $row['lines'] = is_array( $decoded ) ? $decoded : [];
        }
        unset( $row );

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Apply approved adjustment line amounts to the linked payroll item totals.
     *
     * Positive amounts on earnings components increase total_earnings and net.
     * Negative amounts (or deduction components) decrease them accordingly.
     * The method recalculates net_salary = total_earnings - total_deductions
     * after applying all deltas.
     *
     * Component codes ending with '_ded' or matching common deduction codes are
     * treated as deduction lines; everything else is an earnings line.
     *
     * @param int   $payroll_item_id
     * @param array $lines  Decoded adjustment lines [['component_code'=>…,'amount'=>float,…],…]
     */
    private static function apply_to_item( int $payroll_item_id, array $lines ): void {
        global $wpdb;

        if ( empty( $lines ) ) {
            return;
        }

        $earnings_delta   = 0.0;
        $deductions_delta = 0.0;

        foreach ( $lines as $line ) {
            $amount = (float) ( $line['amount'] ?? 0 );
            $code   = strtolower( trim( $line['component_code'] ?? '' ) );

            if ( self::is_deduction_component( $code ) ) {
                // Deduction lines: positive amount = more deducted, negative = reversal.
                $deductions_delta += $amount;
            } else {
                // Earnings lines: positive = more earned, negative = reversal/correction.
                $earnings_delta += $amount;
            }
        }

        // Read current item totals so we can recompute net safely.
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT total_earnings, total_deductions, net_salary
                 FROM {$wpdb->prefix}sfs_hr_payroll_items
                 WHERE id = %d",
                $payroll_item_id
            )
        );

        if ( ! $item ) {
            return;
        }

        $new_earnings   = round( (float) $item->total_earnings + $earnings_delta, 2 );
        $new_deductions = round( (float) $item->total_deductions + $deductions_delta, 2 );
        $new_net        = round( $new_earnings - $new_deductions, 2 );

        $wpdb->update(
            "{$wpdb->prefix}sfs_hr_payroll_items",
            [
                'total_earnings'   => $new_earnings,
                'total_deductions' => $new_deductions,
                'net_salary'       => $new_net,
                'updated_at'       => Helpers::now_mysql(),
            ],
            [ 'id' => $payroll_item_id ],
            [ '%f', '%f', '%f', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Derive a human-readable adjustment_type enum value from total amount and reason.
     *
     * Rules (in priority order):
     *   1. 'reversal' if reason text contains "reversal" or "reverse".
     *   2. 'bonus'     if total_amount > 0 and reason contains "bonus" or "incentive".
     *   3. 'deduction' if total_amount < 0.
     *   4. 'correction' (default).
     *
     * @param float  $total_amount
     * @param string $reason
     * @return string
     */
    private static function derive_type( float $total_amount, string $reason ): string {
        $reason_lower = strtolower( $reason );

        if ( str_contains( $reason_lower, 'reversal' ) || str_contains( $reason_lower, 'reverse' ) ) {
            return 'reversal';
        }

        if ( $total_amount > 0 && ( str_contains( $reason_lower, 'bonus' ) || str_contains( $reason_lower, 'incentive' ) ) ) {
            return 'bonus';
        }

        if ( $total_amount < 0 ) {
            return 'deduction';
        }

        return 'correction';
    }

    /**
     * Determine whether a component code represents a deduction.
     *
     * Convention: codes ending in '_ded', '_deduction', or matching known
     * deduction short-codes (loan, gosi, tax, absence, late, penalty) are
     * treated as deductions.
     *
     * @param string $code  Lowercased component code.
     * @return bool
     */
    private static function is_deduction_component( string $code ): bool {
        if ( str_ends_with( $code, '_ded' ) || str_ends_with( $code, '_deduction' ) ) {
            return true;
        }

        $deduction_keywords = [ 'loan', 'gosi', 'tax', 'absence', 'late', 'penalty', 'deduct', 'fine' ];

        foreach ( $deduction_keywords as $keyword ) {
            if ( str_contains( $code, $keyword ) ) {
                return true;
            }
        }

        return false;
    }
}
