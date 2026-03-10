<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Early_Leave_Service
 *
 * Early leave request number generation, auto-creation, and backfill logic.
 * Extracted from AttendanceModule — the original static methods on
 * AttendanceModule now delegate here for backwards compatibility.
 */
class Early_Leave_Service {

    /**
     * Generate a unique reference number for early leave requests (e.g. EL-2026-0042).
     */
    public static function generate_early_leave_request_number(): string {
        global $wpdb;
        return \SFS\HR\Core\Helpers::generate_reference_number( 'EL', $wpdb->prefix . 'sfs_hr_early_leave_requests' );
    }

    /**
     * Create an early leave request if one doesn't already exist for the given
     * employee + date.  Called from both the normal recalc path and the
     * retro-close path so the logic is shared.
     */
    public static function maybe_create_early_leave_request(
        int $employee_id,
        string $ymd,
        int $session_id,
        ?string $lastOutUtc,
        int $minutes_early,
        bool $is_total_hours,
        $shift,
        \wpdb $wpdb
    ): void {
        $el_table = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $el_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$el_table} WHERE employee_id = %d AND request_date = %s",
            $employee_id,
            $ymd
        ) );

        if ( $el_exists ) {
            return;
        }

        $scheduled_end = ( ! $is_total_hours && $shift && ! empty( $shift->end_time ) ) ? $shift->end_time : null;

        $actual_leave_local = null;
        if ( $lastOutUtc ) {
            $tz_el   = wp_timezone();
            $utc_out = new \DateTimeImmutable( $lastOutUtc, new \DateTimeZone( 'UTC' ) );
            $actual_leave_local = $utc_out->setTimezone( $tz_el )->format( 'H:i:s' );
        }

        // Fallback: if no OUT time available, use the shift end time so the
        // NOT NULL DB constraint on requested_leave_time is satisfied.
        if ( $actual_leave_local === null && $scheduled_end ) {
            $actual_leave_local = $scheduled_end;
        }
        if ( $actual_leave_local === null ) {
            $actual_leave_local = '00:00:00';
        }

        $emp_tbl  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_tbl = $wpdb->prefix . 'sfs_hr_departments';
        $emp_row  = $wpdb->get_row( $wpdb->prepare(
            "SELECT dept_id FROM {$emp_tbl} WHERE id = %d", $employee_id
        ) );
        $mgr_id = null;
        if ( $emp_row && $emp_row->dept_id ) {
            $dept_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT manager_user_id FROM {$dept_tbl} WHERE id = %d", $emp_row->dept_id
            ) );
            if ( $dept_row && $dept_row->manager_user_id ) {
                $mgr_id = (int) $dept_row->manager_user_id;
            }
        }

        $now_el = current_time( 'mysql', true );
        $el_ref = self::generate_early_leave_request_number();

        $el_reason_note = $is_total_hours
            ? sprintf(
                /* translators: %d = number of minutes short of required hours */
                __( 'Auto-created: employee worked %d minutes less than required hours.', 'sfs-hr' ),
                $minutes_early
            )
            : sprintf(
                /* translators: %d = number of minutes the employee left early */
                __( 'Auto-created: employee left %d minutes before shift end.', 'sfs-hr' ),
                $minutes_early
            );

        $inserted = $wpdb->insert( $el_table, [
            'employee_id'          => $employee_id,
            'session_id'           => $session_id,
            'request_date'         => $ymd,
            'scheduled_end_time'   => $scheduled_end,
            'requested_leave_time' => $actual_leave_local,
            'actual_leave_time'    => $actual_leave_local,
            'reason_type'          => 'other',
            'reason_note'          => $el_reason_note,
            'status'               => 'pending',
            'request_number'       => $el_ref,
            'manager_id'           => $mgr_id,
            'affects_salary'       => 0,
            'created_at'           => $now_el,
            'updated_at'           => $now_el,
        ] );

        if ( $inserted === false ) {
            error_log( sprintf(
                '[SFS HR] Failed to auto-create early leave request: db_error=%s',
                $wpdb->last_error
            ) );
        } else {
            $new_el_id = (int) $wpdb->insert_id;
            do_action( 'sfs_hr_early_leave_requested', $new_el_id, $employee_id, $mgr_id );
        }
    }

    /**
     * Backfill reference numbers for existing early leave requests
     */
    public static function backfill_early_leave_request_numbers( \wpdb $wpdb ): void {
        $table = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $missing = $wpdb->get_results(
            "SELECT id, created_at FROM `$table` WHERE request_number IS NULL OR request_number = '' ORDER BY id ASC"
        );
        foreach ($missing as $row) {
            $year = $row->created_at ? date('Y', strtotime($row->created_at)) : wp_date('Y');
            $count = (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `$table` WHERE request_number LIKE %s",
                    'EL-' . $year . '-%'
                )
            );
            $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
            $number = 'EL-' . $year . '-' . $sequence;
            $wpdb->update($table, ['request_number' => $number], ['id' => $row->id]);
        }
    }
}
