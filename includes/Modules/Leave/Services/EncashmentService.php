<?php
namespace SFS\HR\Modules\Leave\Services;

defined('ABSPATH') || exit;

/**
 * Leave Encashment Service
 * Handles leave encashment requests, approvals, and balance updates.
 */
class EncashmentService {

    /**
     * Get employee daily rate (base_salary / 30).
     *
     * @param int $employee_id
     * @return float
     */
    public static function daily_rate( int $employee_id ): float {
        global $wpdb;

        $base_salary = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT base_salary FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d LIMIT 1",
                $employee_id
            )
        );

        if ( $base_salary === null ) {
            return 0.0;
        }

        return (float) $base_salary / 30;
    }

    /**
     * Calculate the maximum days that can be encashed for a given employee/type/year.
     *
     * @param int $employee_id
     * @param int $type_id
     * @param int $year
     * @return int
     */
    public static function get_encashable( int $employee_id, int $type_id, int $year ): int {
        global $wpdb;

        $type = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT allow_encashment, max_encashment_days, min_balance_after
                 FROM {$wpdb->prefix}sfs_hr_leave_types
                 WHERE id = %d LIMIT 1",
                $type_id
            )
        );

        if ( ! $type || ! (int) $type->allow_encashment ) {
            return 0;
        }

        $balance = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT opening, accrued, carried_over, used, encashed, expired_days
                 FROM {$wpdb->prefix}sfs_hr_leave_balances
                 WHERE employee_id = %d AND type_id = %d AND year = %d LIMIT 1",
                $employee_id,
                $type_id,
                $year
            )
        );

        if ( ! $balance ) {
            return 0;
        }

        $available = (float) $balance->opening
            + (float) $balance->accrued
            + (float) $balance->carried_over
            - (float) $balance->used
            - (float) $balance->encashed
            - (float) $balance->expired_days;

        $min_after       = (int) $type->min_balance_after;
        $max_encash_days = (int) $type->max_encashment_days;

        $encashable = $available - $min_after;

        if ( $max_encash_days > 0 ) {
            $encashable = min( $encashable, $max_encash_days );
        }

        return max( 0, (int) floor( $encashable ) );
    }

    /**
     * Create a new encashment request.
     *
     * @param int $employee_id
     * @param int $type_id
     * @param int $year
     * @param int $days
     * @return array{success: bool, id?: int, amount?: float, error?: string}
     */
    public static function create_request( int $employee_id, int $type_id, int $year, int $days ): array {
        global $wpdb;

        if ( $days <= 0 ) {
            return [
                'success' => false,
                'error'   => __( 'Days must be greater than zero.', 'sfs-hr' ),
            ];
        }

        $type = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT allow_encashment FROM {$wpdb->prefix}sfs_hr_leave_types WHERE id = %d LIMIT 1",
                $type_id
            )
        );

        if ( ! $type || ! (int) $type->allow_encashment ) {
            return [
                'success' => false,
                'error'   => __( 'This leave type does not allow encashment.', 'sfs-hr' ),
            ];
        }

        $encashable = self::get_encashable( $employee_id, $type_id, $year );

        if ( $days > $encashable ) {
            return [
                'success' => false,
                /* translators: %d: maximum encashable days */
                'error'   => sprintf( __( 'Requested days exceed the encashable balance. Maximum allowed: %d.', 'sfs-hr' ), $encashable ),
            ];
        }

        $daily_rate = self::daily_rate( $employee_id );
        $amount     = $days * $daily_rate;
        $now        = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_leave_encashment',
            [
                'employee_id' => $employee_id,
                'type_id'     => $type_id,
                'year'        => $year,
                'days'        => $days,
                'daily_rate'  => $daily_rate,
                'amount'      => $amount,
                'status'      => 'pending',
                'created_at'  => $now,
            ],
            [ '%d', '%d', '%d', '%d', '%f', '%f', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [
                'success' => false,
                'error'   => __( 'Failed to create encashment request.', 'sfs-hr' ),
            ];
        }

        return [
            'success' => true,
            'id'      => (int) $wpdb->insert_id,
            'amount'  => $amount,
        ];
    }

    /**
     * Approve an encashment request and update the leave balance.
     *
     * @param int    $encashment_id
     * @param int    $approver_id
     * @param string $note
     * @return array{success: bool, error?: string}
     */
    public static function approve( int $encashment_id, int $approver_id, string $note = '' ): array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_leave_encashment WHERE id = %d LIMIT 1",
                $encashment_id
            )
        );

        if ( ! $row ) {
            return [
                'success' => false,
                'error'   => __( 'Encashment request not found.', 'sfs-hr' ),
            ];
        }

        if ( $row->status !== 'pending' ) {
            return [
                'success' => false,
                'error'   => __( 'Only pending requests can be approved.', 'sfs-hr' ),
            ];
        }

        // Re-check encashable balance and clamp days to prevent TOCTOU issues
        $encashable   = self::get_encashable( (int) $row->employee_id, (int) $row->type_id, (int) $row->year );
        $allowed_days = min( (int) $row->days, $encashable );

        if ( $allowed_days <= 0 ) {
            return [
                'success' => false,
                'error'   => __( 'Insufficient encashable balance. The balance may have changed since the request was created.', 'sfs-hr' ),
            ];
        }

        $now = current_time( 'mysql', true );

        // Atomic conditional update: only transition if still pending
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}sfs_hr_leave_encashment
                 SET status = 'approved', days = %d, amount = %f,
                     approver_id = %d, approver_note = %s,
                     decided_at = %s, updated_at = %s
                 WHERE id = %d AND status = 'pending'",
                $allowed_days,
                $allowed_days * (float) $row->daily_rate,
                $approver_id,
                $note,
                $now,
                $now,
                $encashment_id
            )
        );

        if ( ! $affected ) {
            return [
                'success' => false,
                'error'   => __( 'Failed to approve — request may have already been processed.', 'sfs-hr' ),
            ];
        }

        // Atomic balance update: increment encashed and clamp closing in a single query
        $bal_table = $wpdb->prefix . 'sfs_hr_leave_balances';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$bal_table}
                 SET encashed = encashed + %d,
                     closing  = GREATEST( opening + accrued + carried_over - used - (encashed + %d) - expired_days, 0 ),
                     updated_at = %s
                 WHERE employee_id = %d AND type_id = %d AND year = %d",
                $allowed_days,
                $allowed_days,
                $now,
                (int) $row->employee_id,
                (int) $row->type_id,
                (int) $row->year
            )
        );

        return [ 'success' => true ];
    }

    /**
     * Reject an encashment request.
     *
     * @param int    $encashment_id
     * @param int    $approver_id
     * @param string $note
     * @return array{success: bool, error?: string}
     */
    public static function reject( int $encashment_id, int $approver_id, string $note = '' ): array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status FROM {$wpdb->prefix}sfs_hr_leave_encashment WHERE id = %d LIMIT 1",
                $encashment_id
            )
        );

        if ( ! $row ) {
            return [
                'success' => false,
                'error'   => __( 'Encashment request not found.', 'sfs-hr' ),
            ];
        }

        if ( $row->status !== 'pending' ) {
            return [
                'success' => false,
                'error'   => __( 'Only pending requests can be rejected.', 'sfs-hr' ),
            ];
        }

        $now     = current_time( 'mysql', true );
        $updated = $wpdb->update(
            $wpdb->prefix . 'sfs_hr_leave_encashment',
            [
                'status'        => 'rejected',
                'approver_id'   => $approver_id,
                'approver_note' => $note,
                'decided_at'    => $now,
                'updated_at'    => $now,
            ],
            [ 'id' => $encashment_id ],
            [ '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return [
                'success' => false,
                'error'   => __( 'Failed to reject encashment request.', 'sfs-hr' ),
            ];
        }

        return [ 'success' => true ];
    }

    /**
     * Retrieve encashment requests with optional filters.
     *
     * Supported filters: employee_id, type_id, year, status.
     *
     * @param array $filters
     * @return array
     */
    public static function get_requests( array $filters = [] ): array {
        global $wpdb;

        $enc  = $wpdb->prefix . 'sfs_hr_leave_encashment';
        $emp  = $wpdb->prefix . 'sfs_hr_employees';
        $lt   = $wpdb->prefix . 'sfs_hr_leave_types';

        $where  = [];
        $params = [];

        if ( ! empty( $filters['employee_id'] ) ) {
            $where[]  = 'e.id = %d';
            $params[] = (int) $filters['employee_id'];
        }

        if ( ! empty( $filters['type_id'] ) ) {
            $where[]  = 'enc.type_id = %d';
            $params[] = (int) $filters['type_id'];
        }

        if ( ! empty( $filters['year'] ) ) {
            $where[]  = 'enc.year = %d';
            $params[] = (int) $filters['year'];
        }

        if ( isset( $filters['status'] ) && $filters['status'] !== '' ) {
            $where[]  = 'enc.status = %s';
            $params[] = sanitize_text_field( $filters['status'] );
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $limit  = isset( $filters['limit'] )  ? max( 1, (int) $filters['limit'] )  : 50;
        $offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

        $sql = "SELECT enc.*,
                       e.first_name, e.last_name, e.employee_code,
                       lt.name AS leave_type_name
                FROM {$enc} AS enc
                INNER JOIN {$emp} AS e  ON e.id  = enc.employee_id
                INNER JOIN {$lt}  AS lt ON lt.id = enc.type_id
                {$where_sql}
                ORDER BY enc.created_at DESC
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare( $sql, ...$params );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }
}
