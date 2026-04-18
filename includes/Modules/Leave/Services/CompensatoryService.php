<?php
namespace SFS\HR\Modules\Leave\Services;

defined('ABSPATH') || exit;

/**
 * Compensatory Leave Service
 * Handles compensatory leave requests, approvals, rejections, balance crediting, and expiry.
 */
class CompensatoryService {

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Submit a new compensatory leave request for a day worked outside normal schedule.
     *
     * @param int         $employee_id
     * @param string      $work_date    Y-m-d format
     * @param float       $hours_worked
     * @param string      $reason
     * @param int|null    $session_id   Optional link to sfs_hr_attendance_sessions row
     * @return array{success: bool, id?: int, error?: string}
     */
    public static function create_request(
        int $employee_id,
        string $work_date,
        float $hours_worked = 0.0,
        string $reason = '',
        ?int $session_id = null
    ): array {
        global $wpdb;

        // Validate work_date
        $ts = strtotime( $work_date );
        if ( ! $ts || gmdate( 'Y-m-d', $ts ) !== $work_date ) {
            return [ 'success' => false, 'error' => __( 'Invalid work date.', 'sfs-hr' ) ];
        }

        // Validate employee exists
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d LIMIT 1",
                $employee_id
            )
        );
        if ( ! $exists ) {
            return [ 'success' => false, 'error' => __( 'Employee not found.', 'sfs-hr' ) ];
        }

        // Duplicate check: same employee + work_date with a non-rejected request
        $duplicate = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sfs_hr_leave_compensatory
                 WHERE employee_id = %d AND work_date = %s AND status != 'rejected'
                 LIMIT 1",
                $employee_id,
                $work_date
            )
        );
        if ( $duplicate ) {
            return [ 'success' => false, 'error' => __( 'A compensatory request already exists for this date.', 'sfs-hr' ) ];
        }

        // Find active compensatory leave type
        $comp_type = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_leave_types
             WHERE is_compensatory = 1 AND active = 1
             LIMIT 1",
            ARRAY_A
        );
        if ( ! $comp_type ) {
            return [ 'success' => false, 'error' => __( 'No active compensatory leave type is configured.', 'sfs-hr' ) ];
        }

        // Calculate expiry_date
        $expiry_date = null;
        $expiry_days = (int) ( $comp_type['comp_expiry_days'] ?? 0 );
        if ( $expiry_days > 0 ) {
            $expiry_date = gmdate( 'Y-m-d', strtotime( "+{$expiry_days} days", $ts ) );
        }

        $now = current_time( 'mysql' );

        $inserted = $wpdb->insert(
            "{$wpdb->prefix}sfs_hr_leave_compensatory",
            [
                'employee_id'   => $employee_id,
                'work_date'     => $work_date,
                'session_id'    => $session_id,
                'hours_worked'  => $hours_worked,
                'days_earned'   => 1,
                'reason'        => $reason,
                'status'        => 'pending',
                'approver_id'   => null,
                'approver_note' => null,
                'decided_at'    => null,
                'expiry_date'   => $expiry_date,
                'credited'      => 0,
                'created_at'    => $now,
                'updated_at'    => null,
            ],
            [ '%d', '%s', '%d', '%f', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'success' => false, 'error' => __( 'Failed to save compensatory request.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Approve a pending compensatory request and credit the employee's leave balance.
     *
     * @param int    $comp_id
     * @param int    $approver_id
     * @param string $note
     * @return array{success: bool, error?: string}
     */
    public static function approve( int $comp_id, int $approver_id, string $note = '' ): array {
        global $wpdb;

        $row = self::get_row( $comp_id );
        if ( ! $row ) {
            return [ 'success' => false, 'error' => __( 'Compensatory request not found.', 'sfs-hr' ) ];
        }
        if ( $row['status'] !== 'pending' ) {
            return [ 'success' => false, 'error' => __( 'Only pending requests can be approved.', 'sfs-hr' ) ];
        }

        // Find active compensatory leave type
        $comp_type = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_leave_types
             WHERE is_compensatory = 1 AND active = 1
             LIMIT 1",
            ARRAY_A
        );
        if ( ! $comp_type ) {
            return [ 'success' => false, 'error' => __( 'No active compensatory leave type is configured.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );

        // Update the request row
        $updated = $wpdb->update(
            "{$wpdb->prefix}sfs_hr_leave_compensatory",
            [
                'status'        => 'approved',
                'approver_id'   => $approver_id,
                'approver_note' => $note,
                'decided_at'    => $now,
                'credited'      => 1,
                'updated_at'    => $now,
            ],
            [ 'id' => $comp_id ],
            [ '%s', '%d', '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return [ 'success' => false, 'error' => __( 'Failed to approve compensatory request.', 'sfs-hr' ) ];
        }

        // Credit the leave balance
        $credit_result = self::credit_balance(
            (int) $row['employee_id'],
            (int) $comp_type['id'],
            (int) $row['days_earned']
        );

        if ( ! $credit_result['success'] ) {
            // Roll back the status update to avoid crediting without a balance record
            $wpdb->update(
                "{$wpdb->prefix}sfs_hr_leave_compensatory",
                [
                    'status'        => 'pending',
                    'approver_id'   => null,
                    'approver_note' => null,
                    'decided_at'    => null,
                    'credited'      => 0,
                    'updated_at'    => $now,
                ],
                [ 'id' => $comp_id ],
                [ '%s', '%d', '%s', '%s', '%d', '%s' ],
                [ '%d' ]
            );
            return $credit_result;
        }

        return [ 'success' => true ];
    }

    /**
     * Reject a pending compensatory request.
     *
     * @param int    $comp_id
     * @param int    $approver_id
     * @param string $note
     * @return array{success: bool, error?: string}
     */
    public static function reject( int $comp_id, int $approver_id, string $note = '' ): array {
        global $wpdb;

        $row = self::get_row( $comp_id );
        if ( ! $row ) {
            return [ 'success' => false, 'error' => __( 'Compensatory request not found.', 'sfs-hr' ) ];
        }
        if ( $row['status'] !== 'pending' ) {
            return [ 'success' => false, 'error' => __( 'Only pending requests can be rejected.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );

        $updated = $wpdb->update(
            "{$wpdb->prefix}sfs_hr_leave_compensatory",
            [
                'status'        => 'rejected',
                'approver_id'   => $approver_id,
                'approver_note' => $note,
                'decided_at'    => $now,
                'updated_at'    => $now,
            ],
            [ 'id' => $comp_id ],
            [ '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return [ 'success' => false, 'error' => __( 'Failed to reject compensatory request.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    /**
     * Retrieve compensatory requests with optional filters.
     *
     * @param array{employee_id?: int, status?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function get_requests( array $filters = [] ): array {
        global $wpdb;

        $where  = [];
        $params = [];

        if ( ! empty( $filters['employee_id'] ) ) {
            $where[]  = 'c.employee_id = %d';
            $params[] = (int) $filters['employee_id'];
        }

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'c.status = %s';
            $params[] = $filters['status'];
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $sql = "SELECT c.*,
                       CONCAT(e.first_name, ' ', COALESCE(e.last_name, '')) AS employee_name,
                       e.employee_code
                FROM {$wpdb->prefix}sfs_hr_leave_compensatory c
                LEFT JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = c.employee_id
                {$where_sql}
                ORDER BY c.created_at DESC
                LIMIT 50";

        if ( $params ) {
            $sql = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $rows ?: [];
    }

    /**
     * Cron handler: expire approved compensatory records whose expiry_date has passed.
     * Debits the days_earned from the associated leave balance and marks the row 'expired'.
     *
     * @return void
     */
    public static function process_expiry(): void {
        global $wpdb;

        $today = gmdate( 'Y-m-d' );

        // Find active compensatory leave type
        $comp_type = $wpdb->get_row(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_leave_types
             WHERE is_compensatory = 1 AND active = 1
             LIMIT 1",
            ARRAY_A
        );
        if ( ! $comp_type ) {
            return;
        }

        $type_id = (int) $comp_type['id'];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, employee_id, days_earned, expiry_date, work_date
                 FROM {$wpdb->prefix}sfs_hr_leave_compensatory
                 WHERE status = 'approved'
                   AND credited = 1
                   AND expiry_date IS NOT NULL
                   AND expiry_date < %s",
                $today
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $employee_id = (int) $row['employee_id'];
            $days_earned = (int) $row['days_earned'];

            // Derive the year the credit was applied: use work_date year
            // (the credit is for the year the employee worked the extra day).
            $credited_year = (int) gmdate( 'Y', strtotime( $row['work_date'] ) );

            // Debit the balance (floor at 0)
            self::debit_balance( $employee_id, $type_id, $days_earned, $credited_year );

            // Mark row as expired
            $wpdb->update(
                "{$wpdb->prefix}sfs_hr_leave_compensatory",
                [
                    'status'     => 'expired',
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'id' => (int) $row['id'] ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load a single compensatory row by ID.
     *
     * @param int $comp_id
     * @return array<string, mixed>|null
     */
    private static function get_row( int $comp_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_leave_compensatory WHERE id = %d LIMIT 1",
                $comp_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Find or create a leave balance row for the given employee/type/year and
     * increment `accrued` by $days, then recalculate `closing`.
     *
     * closing = max(0, opening + accrued + carried_over - used - encashed - expired_days)
     *
     * @param int $employee_id
     * @param int $type_id
     * @param int $days
     * @return array{success: bool, error?: string}
     */
    private static function credit_balance( int $employee_id, int $type_id, int $days ): array {
        global $wpdb;

        $year = (int) gmdate( 'Y' );

        $balance = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_leave_balances
                 WHERE employee_id = %d AND type_id = %d AND year = %d
                 LIMIT 1",
                $employee_id,
                $type_id,
                $year
            ),
            ARRAY_A
        );

        if ( $balance ) {
            $new_accrued  = (int) $balance['accrued'] + $days;
            $opening      = (int) ( $balance['opening'] ?? 0 );
            $carried_over = (int) ( $balance['carried_over'] ?? 0 );
            $used         = (int) ( $balance['used'] ?? 0 );
            $encashed     = (int) ( $balance['encashed'] ?? 0 );
            $expired_days = (int) ( $balance['expired_days'] ?? 0 );
            $closing      = max( 0, $opening + $new_accrued + $carried_over - $used - $encashed - $expired_days );

            $updated = $wpdb->update(
                "{$wpdb->prefix}sfs_hr_leave_balances",
                [
                    'accrued' => $new_accrued,
                    'closing' => $closing,
                ],
                [
                    'employee_id' => $employee_id,
                    'type_id'     => $type_id,
                    'year'        => $year,
                ],
                [ '%d', '%d' ],
                [ '%d', '%d', '%d' ]
            );

            if ( $updated === false ) {
                return [ 'success' => false, 'error' => __( 'Failed to update leave balance.', 'sfs-hr' ) ];
            }
        } else {
            // Create a new balance row with opening=0, used=0
            $inserted = $wpdb->insert(
                "{$wpdb->prefix}sfs_hr_leave_balances",
                [
                    'employee_id' => $employee_id,
                    'type_id'     => $type_id,
                    'year'        => $year,
                    'opening'     => 0,
                    'accrued'     => $days,
                    'used'        => 0,
                    'closing'     => $days,
                ],
                [ '%d', '%d', '%d', '%d', '%d', '%d', '%d' ]
            );

            if ( ! $inserted ) {
                return [ 'success' => false, 'error' => __( 'Failed to create leave balance.', 'sfs-hr' ) ];
            }
        }

        return [ 'success' => true ];
    }

    /**
     * Debit `days` from the accrued balance (floor at 0) and recalculate closing.
     *
     * @param int $employee_id
     * @param int $type_id
     * @param int $days
     * @param int $year
     * @return void
     */
    private static function debit_balance( int $employee_id, int $type_id, int $days, int $year ): void {
        global $wpdb;

        $balance = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_leave_balances
                 WHERE employee_id = %d AND type_id = %d AND year = %d
                 LIMIT 1",
                $employee_id,
                $type_id,
                $year
            ),
            ARRAY_A
        );

        if ( ! $balance ) {
            return; // Nothing to debit
        }

        $new_accrued  = max( 0, (int) $balance['accrued'] - $days );
        $opening      = (int) ( $balance['opening'] ?? 0 );
        $carried_over = (int) ( $balance['carried_over'] ?? 0 );
        $used         = (int) ( $balance['used'] ?? 0 );
        $encashed     = (int) ( $balance['encashed'] ?? 0 );
        $expired_days = (int) ( $balance['expired_days'] ?? 0 );
        $closing      = max( 0, $opening + $new_accrued + $carried_over - $used - $encashed - $expired_days );

        $wpdb->update(
            "{$wpdb->prefix}sfs_hr_leave_balances",
            [
                'accrued' => $new_accrued,
                'closing' => $closing,
            ],
            [
                'employee_id'   => $employee_id,
                'type_id' => $type_id,
                'year'          => $year,
            ],
            [ '%d', '%d' ],
            [ '%d', '%d', '%d' ]
        );
    }
}
