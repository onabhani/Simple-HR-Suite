<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compliance_Service
 *
 * M5.3 Attendance Compliance — Saudi labor law violation detection,
 * exception management, and compliance dashboard utilities.
 *
 * All queries use $wpdb->prepare() for dynamic values. No raw interpolation.
 */
class Compliance_Service {

    // -------------------------------------------------------------------------
    // Saudi Labor Law Constants
    // -------------------------------------------------------------------------

    /** Maximum daily working hours (normal). */
    const MAX_DAILY_HOURS = 8;

    /** Maximum daily working hours during Ramadan. */
    const MAX_DAILY_HOURS_RAMADAN = 6;

    /** Maximum weekly working hours. */
    const MAX_WEEKLY_HOURS = 48;

    /** Minimum rest period between consecutive shifts (hours). */
    const MIN_REST_BETWEEN_SHIFTS = 8;

    /** Minimum weekly off-days required. */
    const MIN_WEEKLY_OFF_DAYS = 1;

    /** Maximum consecutive working days before a rest day is mandatory. */
    const MAX_CONSECUTIVE_WORK_DAYS = 6;

    // -------------------------------------------------------------------------
    // Hours Compliance Report
    // -------------------------------------------------------------------------

    /**
     * Generate hours compliance report for a date range.
     *
     * Returns per-employee aggregates: total_hours_worked, contracted_hours,
     * overtime_hours, days_over_limit, weekly_hours_violations.
     *
     * @param string   $start_date  Y-m-d
     * @param string   $end_date    Y-m-d
     * @param int|null $dept_id     Optional department filter.
     * @return array[]  Keyed by employee_id.
     */
    public static function hours_compliance_report( string $start_date, string $end_date, ?int $dept_id = null ): array {
        global $wpdb;

        $sessT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $empT  = $wpdb->prefix . 'sfs_hr_employees';

        $dept_join  = '';
        $dept_where = '';
        $args       = [ $start_date, $end_date ];

        if ( $dept_id !== null ) {
            $dept_where = ' AND e.dept_id = %d';
            $args[]     = $dept_id;
        }

        // Per-day totals and per-employee aggregates in one pass.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    s.employee_id,
                    e.first_name,
                    e.last_name,
                    e.employee_code,
                    e.dept_id,
                    SUM(s.net_minutes) AS total_net_minutes,
                    SUM(s.overtime_minutes) AS total_overtime_minutes,
                    COUNT(CASE WHEN s.status NOT IN ('absent','on_leave','holiday','day_off') THEN 1 END) AS days_worked,
                    COUNT(CASE WHEN s.net_minutes > %d THEN 1 END) AS days_over_daily_limit,
                    FLOOR(DATEDIFF(%s, %s) / 7) + 1 AS total_weeks
                FROM {$sessT} s
                INNER JOIN {$empT} e ON e.id = s.employee_id
                WHERE s.work_date BETWEEN %s AND %s
                  AND e.status != 'terminated'" . $dept_where . "
                GROUP BY s.employee_id",
                self::MAX_DAILY_HOURS * 60, // days_over_daily_limit threshold
                $end_date,
                $start_date,
                $start_date,
                $end_date,
                ...$args
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        // Calculate contracted hours: MAX_DAILY_HOURS × days_worked (approximation).
        // Then assess weekly violation count using a separate query.
        $employee_ids = array_unique( array_column( $rows, 'employee_id' ) );

        $weekly_violations = self::_count_weekly_hour_violations( $employee_ids, $start_date, $end_date );

        $report = [];
        foreach ( $rows as $row ) {
            $emp_id               = (int) $row['employee_id'];
            $total_minutes        = (int) $row['total_net_minutes'];
            $overtime_minutes     = (int) $row['total_overtime_minutes'];
            $days_worked          = (int) $row['days_worked'];
            $contracted_minutes   = $days_worked * self::MAX_DAILY_HOURS * 60;

            $report[ $emp_id ] = [
                'employee_id'             => $emp_id,
                'employee_code'           => $row['employee_code'],
                'first_name'              => $row['first_name'],
                'last_name'               => $row['last_name'],
                'dept_id'                 => (int) $row['dept_id'],
                'total_hours_worked'      => round( $total_minutes / 60, 2 ),
                'contracted_hours'        => round( $contracted_minutes / 60, 2 ),
                'overtime_hours'          => round( $overtime_minutes / 60, 2 ),
                'days_over_daily_limit'   => (int) $row['days_over_daily_limit'],
                'weekly_hours_violations' => $weekly_violations[ $emp_id ] ?? 0,
            ];
        }

        return $report;
    }

    /**
     * Count weeks where an employee exceeded MAX_WEEKLY_HOURS.
     *
     * @param int[]  $employee_ids
     * @param string $start_date
     * @param string $end_date
     * @return array<int, int>  employee_id => violation_count
     */
    private static function _count_weekly_hour_violations( array $employee_ids, string $start_date, string $end_date ): array {
        global $wpdb;

        if ( empty( $employee_ids ) ) {
            return [];
        }

        $sessT = $wpdb->prefix . 'sfs_hr_attendance_sessions';

        $placeholders = implode( ',', array_fill( 0, count( $employee_ids ), '%d' ) );

        // Group by ISO year-week so we get week-level totals.
        $query_args = array_merge( [ $start_date, $end_date ], $employee_ids, [ self::MAX_WEEKLY_HOURS * 60 ] );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    employee_id,
                    YEARWEEK(work_date, 3) AS iso_week,
                    SUM(net_minutes) AS week_minutes
                FROM {$sessT}
                WHERE work_date BETWEEN %s AND %s
                  AND employee_id IN ({$placeholders})
                GROUP BY employee_id, iso_week
                HAVING week_minutes > %d",
                ...$query_args
            ),
            ARRAY_A
        );

        $violations = [];
        foreach ( $rows as $row ) {
            $emp_id = (int) $row['employee_id'];
            $violations[ $emp_id ] = ( $violations[ $emp_id ] ?? 0 ) + 1;
        }

        return $violations;
    }

    // -------------------------------------------------------------------------
    // Daily Hours Violations
    // -------------------------------------------------------------------------

    /**
     * Return sessions where an employee worked more than the allowed daily hours.
     *
     * Applies normal limit (8 h) or Ramadan limit (6 h) depending on $is_ramadan.
     *
     * @param string   $start_date
     * @param string   $end_date
     * @param bool     $is_ramadan  Use Ramadan 6-hour limit when true.
     * @param int|null $dept_id
     * @return array[]
     */
    public static function daily_hours_violations( string $start_date, string $end_date, bool $is_ramadan = false, ?int $dept_id = null ): array {
        global $wpdb;

        $sessT      = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $empT       = $wpdb->prefix . 'sfs_hr_employees';
        $limit_mins = ( $is_ramadan ? self::MAX_DAILY_HOURS_RAMADAN : self::MAX_DAILY_HOURS ) * 60;

        $dept_where = '';
        $args       = [ $start_date, $end_date, $limit_mins ];

        if ( $dept_id !== null ) {
            $dept_where = ' AND e.dept_id = %d';
            $args[]     = $dept_id;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    s.id AS session_id,
                    s.employee_id,
                    e.first_name,
                    e.last_name,
                    e.employee_code,
                    e.dept_id,
                    s.work_date,
                    s.net_minutes,
                    s.in_time,
                    s.out_time,
                    s.overtime_minutes
                FROM {$sessT} s
                INNER JOIN {$empT} e ON e.id = s.employee_id
                WHERE s.work_date BETWEEN %s AND %s
                  AND s.net_minutes > %d
                  AND s.status NOT IN ('absent','on_leave','holiday','day_off')
                  AND e.status != 'terminated'" . $dept_where . "
                ORDER BY s.work_date, e.last_name, e.first_name",
                ...$args
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        foreach ( $rows as &$row ) {
            $row['session_id']       = (int) $row['session_id'];
            $row['employee_id']      = (int) $row['employee_id'];
            $row['dept_id']          = (int) $row['dept_id'];
            $row['net_minutes']      = (int) $row['net_minutes'];
            $row['overtime_minutes'] = (int) $row['overtime_minutes'];
            $row['hours_worked']     = round( $row['net_minutes'] / 60, 2 );
            $row['limit_hours']      = $is_ramadan ? self::MAX_DAILY_HOURS_RAMADAN : self::MAX_DAILY_HOURS;
            $row['excess_minutes']   = $row['net_minutes'] - $limit_mins;
            $row['is_ramadan']       = $is_ramadan;
        }
        unset( $row );

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Weekly Off Violations
    // -------------------------------------------------------------------------

    /**
     * Find employees who worked 7+ consecutive calendar days without a day off.
     *
     * Uses a PHP-side sliding window on per-employee work dates because MySQL
     * gap-and-islands requires window functions not guaranteed on older servers.
     *
     * @param string   $start_date
     * @param string   $end_date
     * @param int|null $dept_id
     * @return array[]  Each entry: employee data + violation_periods array.
     */
    public static function weekly_off_violations( string $start_date, string $end_date, ?int $dept_id = null ): array {
        global $wpdb;

        $sessT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $empT  = $wpdb->prefix . 'sfs_hr_employees';

        $dept_where = '';
        $args       = [ $start_date, $end_date ];

        if ( $dept_id !== null ) {
            $dept_where = ' AND e.dept_id = %d';
            $args[]     = $dept_id;
        }

        // Fetch all working-day dates per employee (excluding off/leave/holiday).
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    s.employee_id,
                    e.first_name,
                    e.last_name,
                    e.employee_code,
                    e.dept_id,
                    s.work_date
                FROM {$sessT} s
                INNER JOIN {$empT} e ON e.id = s.employee_id
                WHERE s.work_date BETWEEN %s AND %s
                  AND s.status NOT IN ('absent','on_leave','holiday','day_off')
                  AND e.status != 'terminated'" . $dept_where . "
                ORDER BY s.employee_id, s.work_date",
                ...$args
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        // Group dates by employee.
        $by_employee = [];
        foreach ( $rows as $row ) {
            $emp_id = (int) $row['employee_id'];
            if ( ! isset( $by_employee[ $emp_id ] ) ) {
                $by_employee[ $emp_id ] = [
                    'employee_id'   => $emp_id,
                    'first_name'    => $row['first_name'],
                    'last_name'     => $row['last_name'],
                    'employee_code' => $row['employee_code'],
                    'dept_id'       => (int) $row['dept_id'],
                    'dates'         => [],
                ];
            }
            $by_employee[ $emp_id ]['dates'][] = $row['work_date'];
        }

        $violations = [];

        foreach ( $by_employee as $emp_id => $emp ) {
            $periods = self::_find_consecutive_periods( $emp['dates'], self::MAX_CONSECUTIVE_WORK_DAYS + 1 );

            if ( ! empty( $periods ) ) {
                $violations[] = [
                    'employee_id'       => $emp['employee_id'],
                    'employee_code'     => $emp['employee_code'],
                    'first_name'        => $emp['first_name'],
                    'last_name'         => $emp['last_name'],
                    'dept_id'           => $emp['dept_id'],
                    'violation_periods' => $periods,
                    'max_consecutive'   => max( array_column( $periods, 'consecutive_days' ) ),
                ];
            }
        }

        return $violations;
    }

    // -------------------------------------------------------------------------
    // Rest Period Violations
    // -------------------------------------------------------------------------

    /**
     * Find sessions where an employee had less than MIN_REST_BETWEEN_SHIFTS hours
     * between the out_time of one day and the in_time of the next working day.
     *
     * @param string   $start_date
     * @param string   $end_date
     * @param int|null $dept_id
     * @return array[]
     */
    public static function rest_period_violations( string $start_date, string $end_date, ?int $dept_id = null ): array {
        global $wpdb;

        $sessT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $empT  = $wpdb->prefix . 'sfs_hr_employees';

        $dept_where = '';
        $args       = [ $start_date, $end_date ];

        if ( $dept_id !== null ) {
            $dept_where = ' AND e.dept_id = %d';
            $args[]     = $dept_id;
        }

        // Retrieve consecutive session pairs using a self-join on next calendar date.
        // We look slightly outside the range for the "previous day" reference.
        $extended_start = gmdate( 'Y-m-d', strtotime( $start_date . ' -1 day' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    s1.employee_id,
                    e.first_name,
                    e.last_name,
                    e.employee_code,
                    e.dept_id,
                    s1.work_date        AS day1_date,
                    s1.out_time         AS day1_out,
                    s2.work_date        AS day2_date,
                    s2.in_time          AS day2_in,
                    TIMESTAMPDIFF(MINUTE, s1.out_time, s2.in_time) AS rest_minutes
                FROM {$sessT} s1
                INNER JOIN {$sessT} s2
                    ON s2.employee_id = s1.employee_id
                    AND s2.work_date > s1.work_date
                    AND s2.work_date <= DATE_ADD(s1.work_date, INTERVAL 2 DAY)
                INNER JOIN {$empT} e ON e.id = s1.employee_id
                WHERE s1.work_date BETWEEN %s AND %s
                  AND s1.out_time IS NOT NULL
                  AND s2.in_time  IS NOT NULL
                  AND s1.status NOT IN ('absent','on_leave','holiday','day_off')
                  AND s2.status NOT IN ('absent','on_leave','holiday','day_off')
                  AND e.status != 'terminated'" . $dept_where . "
                HAVING rest_minutes IS NOT NULL
                   AND rest_minutes < %d
                ORDER BY s1.employee_id, s1.work_date",
                ...array_merge( $args, [ self::MIN_REST_BETWEEN_SHIFTS * 60 ] )
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        foreach ( $rows as &$row ) {
            $row['employee_id']  = (int) $row['employee_id'];
            $row['dept_id']      = (int) $row['dept_id'];
            $row['rest_minutes'] = (int) $row['rest_minutes'];
            $row['rest_hours']   = round( $row['rest_minutes'] / 60, 2 );
            $row['required_rest_hours'] = self::MIN_REST_BETWEEN_SHIFTS;
            $row['deficit_minutes']     = ( self::MIN_REST_BETWEEN_SHIFTS * 60 ) - $row['rest_minutes'];
        }
        unset( $row );

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Fatigue Report
    // -------------------------------------------------------------------------

    /**
     * Return employees who worked $threshold_days or more consecutive days.
     *
     * Similar to weekly_off_violations but with a configurable threshold.
     *
     * @param string   $start_date
     * @param string   $end_date
     * @param int      $threshold_days  Default: MAX_CONSECUTIVE_WORK_DAYS (6).
     * @param int|null $dept_id
     * @return array[]
     */
    public static function fatigue_report( string $start_date, string $end_date, int $threshold_days = self::MAX_CONSECUTIVE_WORK_DAYS, ?int $dept_id = null ): array {
        global $wpdb;

        $sessT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $empT  = $wpdb->prefix . 'sfs_hr_employees';

        $dept_where = '';
        $args       = [ $start_date, $end_date ];

        if ( $dept_id !== null ) {
            $dept_where = ' AND e.dept_id = %d';
            $args[]     = $dept_id;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    s.employee_id,
                    e.first_name,
                    e.last_name,
                    e.employee_code,
                    e.dept_id,
                    s.work_date
                FROM {$sessT} s
                INNER JOIN {$empT} e ON e.id = s.employee_id
                WHERE s.work_date BETWEEN %s AND %s
                  AND s.status NOT IN ('absent','on_leave','holiday','day_off')
                  AND e.status != 'terminated'" . $dept_where . "
                ORDER BY s.employee_id, s.work_date",
                ...$args
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        $by_employee = [];
        foreach ( $rows as $row ) {
            $emp_id = (int) $row['employee_id'];
            if ( ! isset( $by_employee[ $emp_id ] ) ) {
                $by_employee[ $emp_id ] = [
                    'employee_id'   => $emp_id,
                    'first_name'    => $row['first_name'],
                    'last_name'     => $row['last_name'],
                    'employee_code' => $row['employee_code'],
                    'dept_id'       => (int) $row['dept_id'],
                    'dates'         => [],
                ];
            }
            $by_employee[ $emp_id ]['dates'][] = $row['work_date'];
        }

        $report = [];

        foreach ( $by_employee as $emp_id => $emp ) {
            $periods = self::_find_consecutive_periods( $emp['dates'], $threshold_days );

            if ( ! empty( $periods ) ) {
                $report[] = [
                    'employee_id'       => $emp['employee_id'],
                    'employee_code'     => $emp['employee_code'],
                    'first_name'        => $emp['first_name'],
                    'last_name'         => $emp['last_name'],
                    'dept_id'           => $emp['dept_id'],
                    'threshold_days'    => $threshold_days,
                    'fatigue_periods'   => $periods,
                    'max_consecutive'   => max( array_column( $periods, 'consecutive_days' ) ),
                    'total_fatigue_days'=> array_sum( array_column( $periods, 'consecutive_days' ) ),
                ];
            }
        }

        return $report;
    }

    // -------------------------------------------------------------------------
    // Overtime Compliance
    // -------------------------------------------------------------------------

    /**
     * Return employees who exceeded the weekly overtime cap.
     *
     * Saudi Labor Law Art. 107: overtime is permitted but capped at what is
     * "necessary". This report flags employees whose overtime caused their
     * weekly total to exceed MAX_WEEKLY_HOURS.
     *
     * @param string   $start_date
     * @param string   $end_date
     * @param int|null $dept_id
     * @return array[]
     */
    public static function overtime_compliance( string $start_date, string $end_date, ?int $dept_id = null ): array {
        global $wpdb;

        $sessT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $empT  = $wpdb->prefix . 'sfs_hr_employees';

        $dept_where = '';
        $args       = [ $start_date, $end_date, self::MAX_WEEKLY_HOURS * 60 ];

        if ( $dept_id !== null ) {
            $dept_where = ' AND e.dept_id = %d';
            $args[]     = $dept_id;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    s.employee_id,
                    e.first_name,
                    e.last_name,
                    e.employee_code,
                    e.dept_id,
                    YEARWEEK(s.work_date, 3)     AS iso_week,
                    MIN(s.work_date)              AS week_start,
                    MAX(s.work_date)              AS week_end,
                    SUM(s.net_minutes)            AS total_net_minutes,
                    SUM(s.overtime_minutes)       AS total_overtime_minutes
                FROM {$sessT} s
                INNER JOIN {$empT} e ON e.id = s.employee_id
                WHERE s.work_date BETWEEN %s AND %s
                  AND s.status NOT IN ('absent','on_leave','holiday','day_off')
                  AND e.status != 'terminated'" . $dept_where . "
                GROUP BY s.employee_id, YEARWEEK(s.work_date, 3)
                HAVING total_net_minutes > %d
                ORDER BY s.employee_id, iso_week",
                ...$args
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        foreach ( $rows as &$row ) {
            $row['employee_id']           = (int) $row['employee_id'];
            $row['dept_id']               = (int) $row['dept_id'];
            $row['total_net_minutes']     = (int) $row['total_net_minutes'];
            $row['total_overtime_minutes']= (int) $row['total_overtime_minutes'];
            $row['total_hours']           = round( $row['total_net_minutes'] / 60, 2 );
            $row['overtime_hours']        = round( $row['total_overtime_minutes'] / 60, 2 );
            $row['weekly_limit_hours']    = self::MAX_WEEKLY_HOURS;
            $row['excess_hours']          = round( ( $row['total_net_minutes'] - self::MAX_WEEKLY_HOURS * 60 ) / 60, 2 );
        }
        unset( $row );

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Exception Management
    // -------------------------------------------------------------------------

    /**
     * Override a flag record: set flag_status to 'approved', record audit trail.
     *
     * Optionally also updates the parent session status if $new_session_status is given.
     *
     * @param int         $flag_id            ID in sfs_hr_attendance_flags.
     * @param int         $overridden_by       WP user ID performing the override.
     * @param string      $reason              Plain-text reason for the override.
     * @param string|null $new_session_status  Optional new status for the parent session.
     * @return array  { success: bool, message: string, flag_id: int }
     */
    public static function override_flag( int $flag_id, int $overridden_by, string $reason, ?string $new_session_status = null ): array {
        global $wpdb;

        $flagT  = $wpdb->prefix . 'sfs_hr_attendance_flags';
        $sessT  = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $auditT = $wpdb->prefix . 'sfs_hr_attendance_audit';

        $flag = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$flagT} WHERE id = %d LIMIT 1", $flag_id )
        );

        if ( ! $flag ) {
            return [ 'success' => false, 'message' => 'Flag not found.', 'flag_id' => $flag_id ];
        }

        $before = clone $flag;

        $updated = $wpdb->update(
            $flagT,
            [
                'flag_status'    => 'approved',
                'manager_comment'=> $reason,
                'resolved_at'    => current_time( 'mysql', true ),
                'resolved_by'    => $overridden_by,
            ],
            [ 'id' => $flag_id ],
            [ '%s', '%s', '%s', '%d' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return [ 'success' => false, 'message' => $wpdb->last_error ?: 'DB update failed.', 'flag_id' => $flag_id ];
        }

        // Optionally update the parent session status.
        $session_before = null;
        if ( $new_session_status !== null && ! empty( $flag->session_id ) ) {
            $session_before = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$sessT} WHERE id = %d LIMIT 1", (int) $flag->session_id )
            );
            $wpdb->update(
                $sessT,
                [ 'status' => $new_session_status ],
                [ 'id'     => (int) $flag->session_id ],
                [ '%s' ],
                [ '%d' ]
            );
        }

        // Audit log.
        $wpdb->insert(
            $auditT,
            [
                'actor_user_id'      => $overridden_by,
                'action_type'        => 'flag_override',
                'target_employee_id' => (int) $flag->employee_id,
                'target_session_id'  => ! empty( $flag->session_id ) ? (int) $flag->session_id : null,
                'before_json'        => wp_json_encode( [
                    'flag'    => (array) $before,
                    'session' => $session_before ? (array) $session_before : null,
                ] ),
                'after_json'         => wp_json_encode( [
                    'flag_status'        => 'approved',
                    'manager_comment'    => $reason,
                    'new_session_status' => $new_session_status,
                ] ),
                'created_at'         => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );

        return [ 'success' => true, 'message' => 'Flag overridden.', 'flag_id' => $flag_id ];
    }

    /**
     * Override a session status directly and write an audit record.
     *
     * @param int    $session_id      ID in sfs_hr_attendance_sessions.
     * @param string $new_status      Target status value.
     * @param int    $overridden_by   WP user ID performing the override.
     * @param string $reason          Plain-text reason.
     * @return array  { success: bool, message: string, session_id: int }
     */
    public static function override_session( int $session_id, string $new_status, int $overridden_by, string $reason ): array {
        global $wpdb;

        $sessT  = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $auditT = $wpdb->prefix . 'sfs_hr_attendance_audit';

        $allowed_statuses = [ 'present', 'late', 'left_early', 'absent', 'incomplete', 'on_leave', 'holiday', 'day_off' ];
        if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
            return [ 'success' => false, 'message' => 'Invalid status value.', 'session_id' => $session_id ];
        }

        $session = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$sessT} WHERE id = %d LIMIT 1", $session_id )
        );

        if ( ! $session ) {
            return [ 'success' => false, 'message' => 'Session not found.', 'session_id' => $session_id ];
        }

        if ( (int) $session->locked === 1 ) {
            return [ 'success' => false, 'message' => 'Session is locked and cannot be overridden.', 'session_id' => $session_id ];
        }

        $before = clone $session;

        $updated = $wpdb->update(
            $sessT,
            [ 'status' => $new_status ],
            [ 'id'     => $session_id ],
            [ '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return [ 'success' => false, 'message' => $wpdb->last_error ?: 'DB update failed.', 'session_id' => $session_id ];
        }

        // Audit log.
        $wpdb->insert(
            $auditT,
            [
                'actor_user_id'      => $overridden_by,
                'action_type'        => 'session_override',
                'target_employee_id' => (int) $session->employee_id,
                'target_session_id'  => $session_id,
                'before_json'        => wp_json_encode( [ 'status' => $before->status ] ),
                'after_json'         => wp_json_encode( [ 'status' => $new_status, 'reason' => $reason ] ),
                'created_at'         => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );

        return [ 'success' => true, 'message' => 'Session status updated.', 'session_id' => $session_id ];
    }

    /**
     * Retrieve exception (flag override + session override) history for an employee.
     *
     * @param int         $employee_id
     * @param string|null $start_date  Optional Y-m-d lower bound.
     * @param string|null $end_date    Optional Y-m-d upper bound.
     * @return array[]
     */
    public static function get_exceptions( int $employee_id, ?string $start_date = null, ?string $end_date = null ): array {
        global $wpdb;

        $auditT = $wpdb->prefix . 'sfs_hr_attendance_audit';

        $date_where = '';
        $args       = [
            [ 'flag_override', 'session_override' ],
            $employee_id,
        ];

        // Build date filter if provided.
        if ( $start_date !== null && $end_date !== null ) {
            $date_where = ' AND DATE(a.created_at) BETWEEN %s AND %s';
        } elseif ( $start_date !== null ) {
            $date_where = ' AND DATE(a.created_at) >= %s';
        } elseif ( $end_date !== null ) {
            $date_where = ' AND DATE(a.created_at) <= %s';
        }

        // Build action_type IN clause manually (two static values, no user input).
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    a.id,
                    a.action_type,
                    a.actor_user_id,
                    a.target_session_id,
                    a.before_json,
                    a.after_json,
                    a.created_at
                FROM {$auditT} a
                WHERE a.action_type IN ('flag_override','session_override')
                  AND a.target_employee_id = %d" . $date_where . "
                ORDER BY a.created_at DESC",
                $employee_id,
                ...( $start_date !== null && $end_date !== null ? [ $start_date, $end_date ] :
                     ( $start_date !== null ? [ $start_date ] :
                     ( $end_date !== null   ? [ $end_date ]   : [] ) ) )
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        foreach ( $rows as &$row ) {
            $row['id']                 = (int) $row['id'];
            $row['actor_user_id']      = $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null;
            $row['target_session_id']  = $row['target_session_id'] !== null ? (int) $row['target_session_id'] : null;
            $row['before']             = json_decode( $row['before_json'], true );
            $row['after']              = json_decode( $row['after_json'],  true );
            unset( $row['before_json'], $row['after_json'] );

            // Enrich with actor display name if available.
            if ( $row['actor_user_id'] ) {
                $user = get_userdata( $row['actor_user_id'] );
                $row['actor_display_name'] = $user ? $user->display_name : null;
            } else {
                $row['actor_display_name'] = null;
            }
        }
        unset( $row );

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Dashboard / Summary
    // -------------------------------------------------------------------------

    /**
     * Aggregate all violation types into a compliance dashboard summary.
     *
     * @param string   $start_date
     * @param string   $end_date
     * @param int|null $dept_id
     * @return array {
     *     total_violations: int,
     *     violation_breakdown: array,
     *     top_violators: array,
     *     trends: array,
     * }
     */
    public static function get_compliance_summary( string $start_date, string $end_date, ?int $dept_id = null ): array {
        $daily_violations   = self::daily_hours_violations( $start_date, $end_date, false, $dept_id );
        $weekly_off         = self::weekly_off_violations( $start_date, $end_date, $dept_id );
        $rest_violations    = self::rest_period_violations( $start_date, $end_date, $dept_id );
        $overtime_violations= self::overtime_compliance( $start_date, $end_date, $dept_id );

        $breakdown = [
            'daily_hours'     => count( $daily_violations ),
            'weekly_off'      => count( $weekly_off ),
            'rest_period'     => count( $rest_violations ),
            'overtime_weekly' => count( $overtime_violations ),
        ];

        $total_violations = array_sum( $breakdown );

        // Build per-employee violation counts to identify top violators.
        $violation_counts = [];

        foreach ( $daily_violations as $v ) {
            $emp_id = (int) $v['employee_id'];
            self::_increment_violator( $violation_counts, $emp_id, $v, 'daily_hours' );
        }
        foreach ( $weekly_off as $v ) {
            $emp_id = (int) $v['employee_id'];
            self::_increment_violator( $violation_counts, $emp_id, $v, 'weekly_off', count( $v['violation_periods'] ) );
        }
        foreach ( $rest_violations as $v ) {
            $emp_id = (int) $v['employee_id'];
            self::_increment_violator( $violation_counts, $emp_id, $v, 'rest_period' );
        }
        foreach ( $overtime_violations as $v ) {
            $emp_id = (int) $v['employee_id'];
            self::_increment_violator( $violation_counts, $emp_id, $v, 'overtime_weekly' );
        }

        // Sort by total violations descending.
        uasort( $violation_counts, fn( $a, $b ) => $b['total'] <=> $a['total'] );
        $top_violators = array_values( array_slice( $violation_counts, 0, 10, true ) );

        // Weekly trend: group daily violations by ISO week.
        $trends = self::_build_weekly_trend( $daily_violations, $rest_violations, $start_date, $end_date );

        return [
            'start_date'          => $start_date,
            'end_date'            => $end_date,
            'dept_id'             => $dept_id,
            'total_violations'    => $total_violations,
            'violation_breakdown' => $breakdown,
            'top_violators'       => $top_violators,
            'trends'              => $trends,
        ];
    }

    /**
     * Calculate a compliance score (0–100) for a single employee over a period.
     *
     * Score = ( compliant_days / total_working_days ) × 100.
     * A "compliant day" has no daily-hours, rest-period, or flag violation.
     *
     * @param int    $employee_id
     * @param string $start_date
     * @param string $end_date
     * @return float  Score between 0.0 and 100.0.
     */
    public static function get_employee_compliance_score( int $employee_id, string $start_date, string $end_date ): float {
        global $wpdb;

        $sessT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $flagT = $wpdb->prefix . 'sfs_hr_attendance_flags';

        // Count total working days (non-off days) in the period.
        $total_days = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sessT}
                 WHERE employee_id = %d
                   AND work_date BETWEEN %s AND %s
                   AND status NOT IN ('holiday','day_off')",
                $employee_id,
                $start_date,
                $end_date
            )
        );

        if ( $total_days === 0 ) {
            return 100.0;
        }

        // Days with open (unresolved) flags — each flagged session counts as a violation day.
        $flagged_session_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT session_id FROM {$flagT}
                 WHERE employee_id = %d
                   AND flag_status = 'open'
                   AND session_id IS NOT NULL",
                $employee_id
            )
        );

        // Daily hours violations (net > 8h).
        $daily_over_limit = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sessT}
                 WHERE employee_id = %d
                   AND work_date BETWEEN %s AND %s
                   AND net_minutes > %d
                   AND status NOT IN ('absent','on_leave','holiday','day_off')",
                $employee_id,
                $start_date,
                $end_date,
                self::MAX_DAILY_HOURS * 60
            )
        );

        // Rest-period violations for this employee (derived from pairs).
        $rest_viols = self::rest_period_violations( $start_date, $end_date );
        $rest_violation_days = 0;
        foreach ( $rest_viols as $v ) {
            if ( (int) $v['employee_id'] === $employee_id ) {
                $rest_violation_days++;
            }
        }

        // Absent days (unexcused).
        $absent_days = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sessT}
                 WHERE employee_id = %d
                   AND work_date BETWEEN %s AND %s
                   AND status = 'absent'",
                $employee_id,
                $start_date,
                $end_date
            )
        );

        // Collect all unique violation session IDs or day-counts.
        // To avoid double-counting, track by date.
        $violation_days = $daily_over_limit
                        + $rest_violation_days
                        + $absent_days
                        + count( $flagged_session_ids );

        // Clamp to [0, total_days] to prevent negative scores.
        $violation_days = min( $violation_days, $total_days );
        $compliant_days = $total_days - $violation_days;

        return round( ( $compliant_days / $total_days ) * 100, 2 );
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Sliding-window algorithm: find all consecutive-date runs of $min_length
     * or more from a sorted list of Y-m-d strings.
     *
     * @param string[] $dates       Sorted ascending Y-m-d strings.
     * @param int      $min_length  Minimum consecutive-day count to flag.
     * @return array[]  Each entry: { start_date, end_date, consecutive_days }
     */
    private static function _find_consecutive_periods( array $dates, int $min_length ): array {
        if ( empty( $dates ) ) {
            return [];
        }

        $periods    = [];
        $run_start  = $dates[0];
        $run_prev   = $dates[0];
        $run_length = 1;

        for ( $i = 1, $n = count( $dates ); $i < $n; $i++ ) {
            $expected_next = gmdate( 'Y-m-d', strtotime( $run_prev . ' +1 day' ) );

            if ( $dates[ $i ] === $expected_next ) {
                $run_length++;
                $run_prev = $dates[ $i ];
            } else {
                // Gap found — close current run.
                if ( $run_length >= $min_length ) {
                    $periods[] = [
                        'start_date'       => $run_start,
                        'end_date'         => $run_prev,
                        'consecutive_days' => $run_length,
                    ];
                }
                $run_start  = $dates[ $i ];
                $run_prev   = $dates[ $i ];
                $run_length = 1;
            }
        }

        // Close the final run.
        if ( $run_length >= $min_length ) {
            $periods[] = [
                'start_date'       => $run_start,
                'end_date'         => $run_prev,
                'consecutive_days' => $run_length,
            ];
        }

        return $periods;
    }

    /**
     * Increment per-employee violation counter used by get_compliance_summary().
     *
     * @param array  &$counts     Reference to the running tally array.
     * @param int     $emp_id
     * @param array   $row        Source violation row (must contain first_name, last_name, employee_code, dept_id).
     * @param string  $type       Violation type label.
     * @param int     $increment  How many violations to add (default 1).
     */
    private static function _increment_violator( array &$counts, int $emp_id, array $row, string $type, int $increment = 1 ): void {
        if ( ! isset( $counts[ $emp_id ] ) ) {
            $counts[ $emp_id ] = [
                'employee_id'   => $emp_id,
                'employee_code' => $row['employee_code'] ?? '',
                'first_name'    => $row['first_name']    ?? '',
                'last_name'     => $row['last_name']     ?? '',
                'dept_id'       => isset( $row['dept_id'] ) ? (int) $row['dept_id'] : null,
                'total'         => 0,
                'by_type'       => [],
            ];
        }

        $counts[ $emp_id ]['total']               += $increment;
        $counts[ $emp_id ]['by_type'][ $type ]     = ( $counts[ $emp_id ]['by_type'][ $type ] ?? 0 ) + $increment;
    }

    /**
     * Build a per-ISO-week violation trend from daily-hours and rest-period results.
     *
     * @param array[] $daily_violations
     * @param array[] $rest_violations
     * @param string  $start_date
     * @param string  $end_date
     * @return array[]  Sorted by iso_week ascending.
     */
    private static function _build_weekly_trend( array $daily_violations, array $rest_violations, string $start_date, string $end_date ): array {
        $weeks = [];

        foreach ( $daily_violations as $v ) {
            $week = gmdate( 'o-W', strtotime( $v['work_date'] ) ); // ISO year-week
            $weeks[ $week ]['iso_week']             = $week;
            $weeks[ $week ]['daily_hours']          = ( $weeks[ $week ]['daily_hours'] ?? 0 ) + 1;
            $weeks[ $week ]['rest_period']          = $weeks[ $week ]['rest_period'] ?? 0;
        }

        foreach ( $rest_violations as $v ) {
            $week = gmdate( 'o-W', strtotime( $v['day2_date'] ) );
            $weeks[ $week ]['iso_week']             = $week;
            $weeks[ $week ]['rest_period']          = ( $weeks[ $week ]['rest_period'] ?? 0 ) + 1;
            $weeks[ $week ]['daily_hours']          = $weeks[ $week ]['daily_hours'] ?? 0;
        }

        // Fill any weeks with zero violations that fall within the range.
        $cursor = strtotime( $start_date );
        $end_ts = strtotime( $end_date );
        while ( $cursor <= $end_ts ) {
            $week = gmdate( 'o-W', $cursor );
            if ( ! isset( $weeks[ $week ] ) ) {
                $weeks[ $week ] = [
                    'iso_week'    => $week,
                    'daily_hours' => 0,
                    'rest_period' => 0,
                ];
            }
            $cursor = strtotime( '+7 days', $cursor );
        }

        ksort( $weeks );

        return array_values( $weeks );
    }
}
