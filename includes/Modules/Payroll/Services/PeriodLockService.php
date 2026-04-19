<?php
/**
 * PeriodLockService
 *
 * Manages payroll period locking, reopening, and the run approval workflow.
 * All state transitions use atomic conditional UPDATEs to prevent race conditions.
 *
 * Hooks fired on each transition (consumed by AuditTrail / notification listeners):
 *   do_action( 'sfs_hr_payroll_audit', string $entity_type, int $entity_id,
 *              string $action, int $actor_id, array $details )
 *
 * Required migration (M1.3): add_column_if_missing() for
 *   sfs_hr_payroll_runs.payment_reference VARCHAR(191) NULL
 *
 * @package SFS\HR\Modules\Payroll\Services
 * @since   2.3.0
 */

namespace SFS\HR\Modules\Payroll\Services;

use SFS\HR\Core\Helpers;

defined( 'ABSPATH' ) || exit;

class PeriodLockService {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /** Period statuses that are considered locked (no further mutations allowed). */
    private const LOCKED_STATUSES = [ 'closed', 'paid' ];

    /** Run statuses that block a period from being locked. */
    private const BLOCKING_RUN_STATUSES = [ 'draft', 'calculating', 'review' ];

    /** Capability required to reopen a closed period. */
    private const REOPEN_CAP = 'sfs_hr.manage';

    // -------------------------------------------------------------------------
    // Period lifecycle
    // -------------------------------------------------------------------------

    /**
     * Lock a period (transition to 'closed').
     *
     * Preconditions:
     *  - Period must exist and be in 'open' or 'processing' status.
     *  - No runs may be in draft / calculating / review state.
     *
     * @param int $period_id  Period to lock.
     * @param int $locked_by  WP user ID performing the action.
     * @return array { success: bool, message: string, [period]: object }
     */
    public static function lock( int $period_id, int $locked_by ): array {
        global $wpdb;

        $periods = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $runs    = $wpdb->prefix . 'sfs_hr_payroll_runs';

        // 1. Load the period.
        $period = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$periods} WHERE id = %d", $period_id )
        );

        if ( ! $period ) {
            return self::err( __( 'Period not found.', 'sfs-hr' ) );
        }

        if ( in_array( $period->status, self::LOCKED_STATUSES, true ) ) {
            return self::err( __( 'Period is already locked.', 'sfs-hr' ) );
        }

        if ( ! in_array( $period->status, [ 'open', 'processing' ], true ) ) {
            return self::err(
                sprintf(
                    /* translators: %s: current period status */
                    __( 'Period cannot be locked from status "%s". It must be open or processing.', 'sfs-hr' ),
                    $period->status
                )
            );
        }

        // 2. Check for blocking runs.
        $blocking_placeholders = implode( ', ', array_fill( 0, count( self::BLOCKING_RUN_STATUSES ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $blocking_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$runs} WHERE period_id = %d AND status IN ({$blocking_placeholders})",
                array_merge( [ $period_id ], self::BLOCKING_RUN_STATUSES )
            )
        );

        if ( $blocking_count > 0 ) {
            return self::err(
                sprintf(
                    /* translators: %d: number of unfinished runs */
                    _n(
                        'Cannot lock period: %d run is still in draft, calculating, or review status.',
                        'Cannot lock period: %d runs are still in draft, calculating, or review status.',
                        $blocking_count,
                        'sfs-hr'
                    ),
                    $blocking_count
                )
            );
        }

        // 3. Atomic status transition: only succeeds if status hasn't changed
        //    between our read and this write (concurrent-safe).
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$periods}
                    SET status = 'closed', updated_at = %s
                  WHERE id = %d AND status = %s",
                Helpers::now_mysql(),
                $period_id,
                $period->status
            )
        );

        if ( ! $updated ) {
            return self::err( __( 'Period lock failed — status changed concurrently or no rows matched.', 'sfs-hr' ) );
        }

        $period->status = 'closed';

        /**
         * Fires after a payroll period is locked.
         *
         * @param string $entity_type 'payroll_period'
         * @param int    $entity_id   Period ID.
         * @param string $action      'lock'
         * @param int    $actor_id    WP user ID.
         * @param array  $details     Contextual data.
         */
        do_action( 'sfs_hr_payroll_audit', 'payroll_period', $period_id, 'lock', $locked_by, [
            'period_name' => $period->name,
            'old_status'  => $period->status === 'closed' ? 'open_or_processing' : $period->status,
            'new_status'  => 'closed',
        ] );

        return [
            'success' => true,
            'message' => __( 'Period locked successfully.', 'sfs-hr' ),
            'period'  => $period,
        ];
    }

    /**
     * Reopen a locked period (closed → open).
     *
     * Requires the `sfs_hr.manage` capability. Logs an audit trail entry with
     * the mandatory reason string.
     *
     * @param int    $period_id    Period to reopen.
     * @param int    $reopened_by  WP user ID performing the action.
     * @param string $reason       Required reason for the audit log.
     * @return array { success: bool, message: string, [period]: object }
     */
    public static function reopen( int $period_id, int $reopened_by, string $reason ): array {
        global $wpdb;

        // Elevated permission check.
        if ( ! user_can( $reopened_by, self::REOPEN_CAP ) ) {
            return self::err( __( 'You do not have permission to reopen a locked period.', 'sfs-hr' ) );
        }

        $reason = trim( $reason );
        if ( '' === $reason ) {
            return self::err( __( 'A reason is required to reopen a locked period.', 'sfs-hr' ) );
        }

        $periods = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $period = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$periods} WHERE id = %d", $period_id )
        );

        if ( ! $period ) {
            return self::err( __( 'Period not found.', 'sfs-hr' ) );
        }

        if ( 'paid' === $period->status ) {
            return self::err( __( 'A fully paid period cannot be reopened.', 'sfs-hr' ) );
        }

        if ( 'closed' !== $period->status ) {
            return self::err(
                sprintf(
                    /* translators: %s: current period status */
                    __( 'Period is not locked (current status: "%s").', 'sfs-hr' ),
                    $period->status
                )
            );
        }

        // Atomic transition: closed → open.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$periods}
                    SET status = 'open', updated_at = %s
                  WHERE id = %d AND status = 'closed'",
                Helpers::now_mysql(),
                $period_id
            )
        );

        if ( ! $updated ) {
            return self::err( __( 'Reopen failed — status changed concurrently or no rows matched.', 'sfs-hr' ) );
        }

        $period->status = 'open';

        do_action( 'sfs_hr_payroll_audit', 'payroll_period', $period_id, 'reopen', $reopened_by, [
            'period_name' => $period->name,
            'old_status'  => 'closed',
            'new_status'  => 'open',
            'reason'      => $reason,
        ] );

        return [
            'success' => true,
            'message' => __( 'Period reopened successfully.', 'sfs-hr' ),
            'period'  => $period,
        ];
    }

    /**
     * Check whether a period is locked (status is 'closed' or 'paid').
     *
     * @param int $period_id Period to check.
     * @return bool True if locked.
     */
    public static function is_locked( int $period_id ): bool {
        global $wpdb;

        $periods = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $status = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM {$periods} WHERE id = %d", $period_id )
        );

        return $status !== null && in_array( $status, self::LOCKED_STATUSES, true );
    }

    /**
     * Guard: return an error array if the period is locked.
     *
     * Call this at the top of any service method that mutates runs or items
     * belonging to a period, before performing any writes.
     *
     * Usage:
     *   $guard = PeriodLockService::assert_unlocked( $period_id );
     *   if ( ! $guard['success'] ) { return $guard; }
     *
     * @param int $period_id Period to check.
     * @return array { success: bool, message: string }
     */
    public static function assert_unlocked( int $period_id ): array {
        global $wpdb;

        $periods = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $status = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM {$periods} WHERE id = %d", $period_id )
        );

        if ( null === $status ) {
            return self::err( __( 'Period not found.', 'sfs-hr' ) );
        }

        if ( in_array( $status, self::LOCKED_STATUSES, true ) ) {
            return self::err(
                sprintf(
                    /* translators: %s: locked status (closed or paid) */
                    __( 'This operation is not allowed — the payroll period is %s.', 'sfs-hr' ),
                    $status
                )
            );
        }

        return [ 'success' => true, 'message' => '' ];
    }

    /**
     * Get full lock/status details for a period.
     *
     * @param int $period_id Period to inspect.
     * @return array {
     *   success:     bool,
     *   message:     string,        // populated on error only
     *   period_id:   int,
     *   status:      string,
     *   is_locked:   bool,
     *   run_summary: array {        // counts by run status
     *     draft:       int,
     *     calculating: int,
     *     review:      int,
     *     approved:    int,
     *     paid:        int,
     *     cancelled:   int,
     *     total:       int,
     *   },
     *   can_lock:    bool,          // true when no blocking runs exist
     *   can_reopen:  bool,
     * }
     */
    public static function get_status( int $period_id ): array {
        global $wpdb;

        $periods = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $runs    = $wpdb->prefix . 'sfs_hr_payroll_runs';

        $period = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$periods} WHERE id = %d", $period_id )
        );

        if ( ! $period ) {
            return self::err( __( 'Period not found.', 'sfs-hr' ) );
        }

        // Aggregate run statuses in a single query.
        $run_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS cnt FROM {$runs} WHERE period_id = %d GROUP BY status",
                $period_id
            ),
            ARRAY_A
        );

        $run_summary = [
            'draft'       => 0,
            'calculating' => 0,
            'review'      => 0,
            'approved'    => 0,
            'paid'        => 0,
            'cancelled'   => 0,
            'total'       => 0,
        ];

        foreach ( $run_rows as $row ) {
            $key = $row['status'];
            if ( isset( $run_summary[ $key ] ) ) {
                $run_summary[ $key ] = (int) $row['cnt'];
            }
            $run_summary['total'] += (int) $row['cnt'];
        }

        $is_locked = in_array( $period->status, self::LOCKED_STATUSES, true );

        $blocking = $run_summary['draft'] + $run_summary['calculating'] + $run_summary['review'];

        return [
            'success'     => true,
            'message'     => '',
            'period_id'   => $period_id,
            'status'      => $period->status,
            'is_locked'   => $is_locked,
            'run_summary' => $run_summary,
            'can_lock'    => ! $is_locked
                             && in_array( $period->status, [ 'open', 'processing' ], true )
                             && 0 === $blocking,
            'can_reopen'  => 'closed' === $period->status,
        ];
    }

    /**
     * Bulk lock all periods whose end_date is strictly before $before_date.
     *
     * Skips periods that have blocking runs. Returns the count of successfully
     * locked periods.
     *
     * @param string $before_date ISO date string (Y-m-d), exclusive upper bound.
     * @param int    $actor_id    WP user ID performing the bulk action.
     * @return int Number of periods locked.
     */
    public static function auto_lock_old_periods( string $before_date, int $actor_id ): int {
        global $wpdb;

        $periods = $wpdb->prefix . 'sfs_hr_payroll_periods';

        // Fetch candidate periods (open or processing, end_date < $before_date).
        $candidates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$periods}
                  WHERE status IN ('open','processing')
                    AND end_date < %s
                  ORDER BY end_date ASC",
                $before_date
            ),
            ARRAY_A
        );

        $locked = 0;

        foreach ( $candidates as $row ) {
            $result = self::lock( (int) $row['id'], $actor_id );
            if ( ! empty( $result['success'] ) ) {
                $locked++;
            }
        }

        return $locked;
    }

    // -------------------------------------------------------------------------
    // Approval workflow
    // -------------------------------------------------------------------------

    /**
     * Submit a run for review (draft → review).
     *
     * @param int $run_id        Payroll run ID.
     * @param int $submitted_by  WP user ID.
     * @return array { success: bool, message: string, [run]: object }
     */
    public static function submit_for_review( int $run_id, int $submitted_by ): array {
        global $wpdb;

        $runs = $wpdb->prefix . 'sfs_hr_payroll_runs';

        $run = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$runs} WHERE id = %d", $run_id )
        );

        if ( ! $run ) {
            return self::err( __( 'Payroll run not found.', 'sfs-hr' ) );
        }

        // Guard: period must not be locked.
        $guard = self::assert_unlocked( (int) $run->period_id );
        if ( ! $guard['success'] ) {
            return $guard;
        }

        if ( 'draft' !== $run->status ) {
            return self::err(
                sprintf(
                    /* translators: %s: current run status */
                    __( 'Run cannot be submitted for review from status "%s". It must be in draft.', 'sfs-hr' ),
                    $run->status
                )
            );
        }

        // Atomic: draft → review.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$runs}
                    SET status = 'review', updated_at = %s
                  WHERE id = %d AND status = 'draft'",
                Helpers::now_mysql(),
                $run_id
            )
        );

        if ( ! $updated ) {
            return self::err( __( 'Submit for review failed — status changed concurrently.', 'sfs-hr' ) );
        }

        $run->status = 'review';

        do_action( 'sfs_hr_payroll_audit', 'payroll_run', $run_id, 'submit_for_review', $submitted_by, [
            'period_id'  => (int) $run->period_id,
            'run_number' => (int) $run->run_number,
            'old_status' => 'draft',
            'new_status' => 'review',
        ] );

        return [
            'success' => true,
            'message' => __( 'Run submitted for review.', 'sfs-hr' ),
            'run'     => $run,
        ];
    }

    /**
     * Approve a run (review → approved).
     *
     * Uses an atomic UPDATE WHERE status='review' to prevent double-approvals.
     *
     * @param int $run_id      Payroll run ID.
     * @param int $approved_by WP user ID.
     * @return array { success: bool, message: string, [run]: object }
     */
    public static function approve_run( int $run_id, int $approved_by ): array {
        global $wpdb;

        $runs = $wpdb->prefix . 'sfs_hr_payroll_runs';

        $run = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$runs} WHERE id = %d", $run_id )
        );

        if ( ! $run ) {
            return self::err( __( 'Payroll run not found.', 'sfs-hr' ) );
        }

        // Guard: period must not be locked.
        $guard = self::assert_unlocked( (int) $run->period_id );
        if ( ! $guard['success'] ) {
            return $guard;
        }

        if ( 'review' !== $run->status ) {
            return self::err(
                sprintf(
                    /* translators: %s: current run status */
                    __( 'Run cannot be approved from status "%s". It must be in review.', 'sfs-hr' ),
                    $run->status
                )
            );
        }

        $now = Helpers::now_mysql();

        // Atomic: review → approved (double-approve safe).
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$runs}
                    SET status      = 'approved',
                        approved_at = %s,
                        approved_by = %d,
                        updated_at  = %s
                  WHERE id = %d AND status = 'review'",
                $now,
                $approved_by,
                $now,
                $run_id
            )
        );

        if ( ! $updated ) {
            return self::err( __( 'Approval failed — run is no longer in review status (possibly already approved).', 'sfs-hr' ) );
        }

        $run->status      = 'approved';
        $run->approved_at = $now;
        $run->approved_by = $approved_by;

        do_action( 'sfs_hr_payroll_audit', 'payroll_run', $run_id, 'approve', $approved_by, [
            'period_id'   => (int) $run->period_id,
            'run_number'  => (int) $run->run_number,
            'old_status'  => 'review',
            'new_status'  => 'approved',
            'approved_at' => $now,
        ] );

        // Fire the legacy hook consumed by AuditTrail::log_payroll_approved().
        do_action( 'sfs_hr_payroll_run_approved', $run_id, (array) $run );

        return [
            'success' => true,
            'message' => __( 'Run approved successfully.', 'sfs-hr' ),
            'run'     => $run,
        ];
    }

    /**
     * Mark a run as paid (approved → paid).
     *
     * If every run in the period is now paid, the period itself is transitioned
     * to 'paid' status atomically.
     *
     * Note: `payment_reference` is stored in the `payment_reference` column
     * added by the M1.3 migration (add_column_if_missing on payroll_runs).
     *
     * @param int         $run_id            Payroll run ID.
     * @param int         $paid_by           WP user ID.
     * @param string|null $payment_reference Optional payment reference / transfer ID.
     * @return array { success: bool, message: string, [run]: object, period_paid: bool }
     */
    public static function mark_paid( int $run_id, int $paid_by, ?string $payment_reference = null ): array {
        global $wpdb;

        $runs    = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $run = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$runs} WHERE id = %d", $run_id )
        );

        if ( ! $run ) {
            return self::err( __( 'Payroll run not found.', 'sfs-hr' ) );
        }

        // A paid-period guard is intentionally skipped here: marking runs paid
        // is the mechanism that drives a period to 'paid'. We only block if the
        // period is already 'closed' (locked before payment) — which shouldn't
        // normally happen but is a valid guard.
        if ( 'closed' === self::_get_period_status( (int) $run->period_id ) ) {
            return self::err( __( 'Cannot mark run as paid — the period is locked (closed). Reopen the period first.', 'sfs-hr' ) );
        }

        if ( 'approved' !== $run->status ) {
            return self::err(
                sprintf(
                    /* translators: %s: current run status */
                    __( 'Run cannot be marked as paid from status "%s". It must be approved first.', 'sfs-hr' ),
                    $run->status
                )
            );
        }

        $now = Helpers::now_mysql();

        // Atomic: approved → paid.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$runs}
                    SET status             = 'paid',
                        paid_at            = %s,
                        paid_by            = %d,
                        payment_reference  = %s,
                        updated_at         = %s
                  WHERE id = %d AND status = 'approved'",
                $now,
                $paid_by,
                $payment_reference,
                $now,
                $run_id
            )
        );

        if ( ! $updated ) {
            return self::err( __( 'Mark paid failed — run is no longer approved (possibly already paid).', 'sfs-hr' ) );
        }

        $run->status            = 'paid';
        $run->paid_at           = $now;
        $run->paid_by           = $paid_by;
        $run->payment_reference = $payment_reference;

        do_action( 'sfs_hr_payroll_audit', 'payroll_run', $run_id, 'mark_paid', $paid_by, [
            'period_id'         => (int) $run->period_id,
            'run_number'        => (int) $run->run_number,
            'old_status'        => 'approved',
            'new_status'        => 'paid',
            'paid_at'           => $now,
            'payment_reference' => $payment_reference,
        ] );

        // Attempt to transition the period to 'paid' if all non-cancelled runs
        // for this period are now in 'paid' status.
        $period_paid = false;
        $period_id   = (int) $run->period_id;

        $unpaid_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$runs}
                  WHERE period_id = %d
                    AND status NOT IN ('paid','cancelled')",
                $period_id
            )
        );

        $total_active = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$runs}
                  WHERE period_id = %d AND status != 'cancelled'",
                $period_id
            )
        );

        if ( 0 === $unpaid_count && $total_active > 0 ) {
            $period_updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$periods}
                        SET status = 'paid', updated_at = %s
                      WHERE id = %d AND status NOT IN ('paid')",
                    $now,
                    $period_id
                )
            );

            if ( $period_updated ) {
                $period_paid = true;

                do_action( 'sfs_hr_payroll_audit', 'payroll_period', $period_id, 'auto_paid', $paid_by, [
                    'triggered_by_run' => $run_id,
                    'old_status'       => 'closed_or_open',
                    'new_status'       => 'paid',
                ] );
            }
        }

        return [
            'success'     => true,
            'message'     => __( 'Run marked as paid.', 'sfs-hr' )
                . ( $period_paid ? ' ' . __( 'Period has been transitioned to paid.', 'sfs-hr' ) : '' ),
            'run'         => $run,
            'period_paid' => $period_paid,
        ];
    }

    /**
     * Reverse a completed (approved or paid) run.
     *
     * - Sets the original run's status to 'cancelled'.
     * - Creates a new reversal run on the same period with:
     *     run_number  = max(run_number for period) + 1
     *     total_gross = -original.total_gross
     *     total_deductions = -original.total_deductions
     *     total_net   = -original.total_net
     *     employee_count = original.employee_count
     *     notes = "Reversal of run #N: <reason>"
     *     status = 'draft'
     *
     * The caller is responsible for copying and negating the individual payroll
     * items into the new run (this service only creates the run header).
     *
     * @param int    $run_id      Run to reverse.
     * @param int    $reversed_by WP user ID.
     * @param string $reason      Mandatory reason for the reversal.
     * @return array { success: bool, message: string, [original_run]: object, [reversal_run_id]: int }
     */
    public static function reverse_run( int $run_id, int $reversed_by, string $reason ): array {
        global $wpdb;

        $reason = trim( $reason );
        if ( '' === $reason ) {
            return self::err( __( 'A reason is required to reverse a payroll run.', 'sfs-hr' ) );
        }

        $runs = $wpdb->prefix . 'sfs_hr_payroll_runs';

        $run = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$runs} WHERE id = %d", $run_id )
        );

        if ( ! $run ) {
            return self::err( __( 'Payroll run not found.', 'sfs-hr' ) );
        }

        if ( ! in_array( $run->status, [ 'approved', 'paid' ], true ) ) {
            return self::err(
                sprintf(
                    /* translators: %s: current run status */
                    __( 'Only approved or paid runs can be reversed (current status: "%s").', 'sfs-hr' ),
                    $run->status
                )
            );
        }

        $period_id = (int) $run->period_id;

        // The period must not be in 'paid' status — reopening must happen first.
        $period_status = self::_get_period_status( $period_id );
        if ( 'paid' === $period_status ) {
            return self::err( __( 'Cannot reverse a run in a fully paid period. Reopen the period first.', 'sfs-hr' ) );
        }

        $now = Helpers::now_mysql();

        // 1. Atomic: mark original run as cancelled.
        $cancelled = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$runs}
                    SET status = 'cancelled', updated_at = %s
                  WHERE id = %d AND status = %s",
                $now,
                $run_id,
                $run->status
            )
        );

        if ( ! $cancelled ) {
            return self::err( __( 'Reversal failed — run status changed concurrently.', 'sfs-hr' ) );
        }

        // 2. Determine next run_number for this period.
        $next_run_number = 1 + (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(MAX(run_number), 0) FROM {$runs} WHERE period_id = %d",
                $period_id
            )
        );

        // 3. Insert the reversal run with negated financials.
        $inserted = $wpdb->insert(
            $runs,
            [
                'period_id'        => $period_id,
                'run_number'       => $next_run_number,
                'status'           => 'draft',
                'total_gross'      => (float) $run->total_gross * -1,
                'total_deductions' => (float) $run->total_deductions * -1,
                'total_net'        => (float) $run->total_net * -1,
                'employee_count'   => (int) $run->employee_count,
                'notes'            => sprintf(
                    /* translators: 1: original run number, 2: reversal reason */
                    __( 'Reversal of run #%1$d: %2$s', 'sfs-hr' ),
                    (int) $run->run_number,
                    $reason
                ),
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            [ '%d', '%d', '%s', '%f', '%f', '%f', '%d', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            // Attempt to roll back the cancellation so data stays consistent.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$runs}
                        SET status = %s, updated_at = %s
                      WHERE id = %d AND status = 'cancelled'",
                    $run->status,
                    $now,
                    $run_id
                )
            );

            return self::err( __( 'Reversal run creation failed. The original run has been restored.', 'sfs-hr' ) );
        }

        $reversal_run_id = (int) $wpdb->insert_id;
        $run->status     = 'cancelled';

        do_action( 'sfs_hr_payroll_audit', 'payroll_run', $run_id, 'reverse', $reversed_by, [
            'period_id'       => $period_id,
            'original_run'    => $run_id,
            'reversal_run_id' => $reversal_run_id,
            'run_number'      => (int) $run->run_number,
            'reason'          => $reason,
            'old_status'      => in_array( $run->status, [ 'approved', 'paid' ], true ) ? $run->status : 'approved_or_paid',
            'new_status'      => 'cancelled',
        ] );

        do_action( 'sfs_hr_payroll_audit', 'payroll_run', $reversal_run_id, 'reversal_created', $reversed_by, [
            'period_id'         => $period_id,
            'reverses_run_id'   => $run_id,
            'run_number'        => $next_run_number,
            'reason'            => $reason,
        ] );

        return [
            'success'         => true,
            'message'         => __( 'Run reversed successfully. A new draft reversal run has been created.', 'sfs-hr' ),
            'original_run'    => $run,
            'reversal_run_id' => $reversal_run_id,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch only the status column for a period (avoids a full object load).
     *
     * @param int $period_id Period ID.
     * @return string|null Status string or null if not found.
     */
    private static function _get_period_status( int $period_id ): ?string {
        global $wpdb;

        $periods = $wpdb->prefix . 'sfs_hr_payroll_periods';

        return $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM {$periods} WHERE id = %d", $period_id )
        );
    }

    /**
     * Build a standard error response array.
     *
     * @param string $message Human-readable error message (already translated).
     * @return array { success: false, message: string }
     */
    private static function err( string $message ): array {
        return [ 'success' => false, 'message' => $message ];
    }
}
