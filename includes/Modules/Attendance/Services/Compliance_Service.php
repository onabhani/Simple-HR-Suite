<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Compliance_Service {

    const MAX_DAILY_MINUTES              = 480;
    const MAX_DAILY_MINUTES_RAMADAN      = 360;
    const MIN_REST_BETWEEN_SHIFTS_HOURS  = 12;
    const MAX_CONSECUTIVE_DAYS           = 6;
    const MAX_WEEKLY_HOURS               = 48;

    public static function compute_snapshot( int $employee_id, string $period_start, string $period_end ): array {
        global $wpdb;

        $sessT   = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $assignT = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';
        $shiftT  = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $snapT   = $wpdb->prefix . 'sfs_hr_attendance_compliance_snapshots';

        $sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, sa.shift_id, sa.is_holiday,
                    sh.start_time, sh.end_time, sh.min_rest_hours,
                    sh.max_daily_minutes, sh.weekly_off_required
             FROM {$sessT} s
             LEFT JOIN {$assignT} sa ON sa.employee_id = s.employee_id AND sa.work_date = s.work_date
             LEFT JOIN {$shiftT} sh  ON sh.id = sa.shift_id
             WHERE s.employee_id = %d
               AND s.work_date BETWEEN %s AND %s
             ORDER BY s.work_date ASC",
            $employee_id, $period_start, $period_end
        ) );

        $actual_minutes   = 0;
        $overtime_minutes  = 0;
        $days_worked       = 0;
        $days_absent       = 0;
        $days_late         = 0;
        $contracted_total  = 0;
        $violations        = [];

        $worked_statuses = [ 'present', 'late', 'left_early', 'incomplete' ];

        foreach ( $sessions as $row ) {
            $actual_minutes  += (int) $row->net_minutes;
            $overtime_minutes += (int) $row->overtime_minutes;

            if ( in_array( $row->status, $worked_statuses, true ) ) {
                $days_worked++;
            }
            if ( $row->status === 'absent' ) {
                $days_absent++;
            }
            if ( $row->status === 'late' ) {
                $days_late++;
            }

            if ( ! in_array( $row->status, [ 'holiday', 'day_off', 'on_leave' ], true ) ) {
                $shift_daily = $row->max_daily_minutes ? (int) $row->max_daily_minutes : self::MAX_DAILY_MINUTES;
                $contracted_total += $shift_daily;
            }

            $shift_max = $row->max_daily_minutes ? (int) $row->max_daily_minutes : self::MAX_DAILY_MINUTES;
            if ( (int) $row->net_minutes > $shift_max ) {
                $violations[] = [
                    'date'  => $row->work_date,
                    'type'  => 'daily_hour_exceeded',
                    'value' => (int) $row->net_minutes,
                    'limit' => $shift_max,
                ];
            }
        }

        $max_consecutive       = self::calc_max_consecutive( $sessions, $worked_statuses );
        $weekly_off_violations = self::calc_weekly_off_violations( $sessions, $worked_statuses, $period_start, $period_end, $violations );
        $daily_hour_violations = count( array_filter( $violations, fn( $v ) => $v['type'] === 'daily_hour_exceeded' ) );
        $rest_violations       = self::calc_rest_violations( $sessions, $worked_statuses, $violations );

        $snapshot = [
            'employee_id'             => $employee_id,
            'period_start'            => $period_start,
            'period_end'              => $period_end,
            'contracted_minutes_total' => $contracted_total,
            'actual_minutes_total'    => $actual_minutes,
            'overtime_minutes_total'  => $overtime_minutes,
            'days_worked'             => $days_worked,
            'days_absent'             => $days_absent,
            'days_late'               => $days_late,
            'max_consecutive_days'    => $max_consecutive,
            'weekly_off_violations'   => $weekly_off_violations,
            'daily_hour_violations'   => $daily_hour_violations,
            'rest_period_violations'  => $rest_violations,
            'violations_json'         => wp_json_encode( $violations ),
            'computed_at'             => current_time( 'mysql' ),
        ];

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$snapT}
                (employee_id, period_start, period_end, contracted_minutes_total,
                 actual_minutes_total, overtime_minutes_total, days_worked, days_absent,
                 days_late, max_consecutive_days, weekly_off_violations,
                 daily_hour_violations, rest_period_violations, violations_json, computed_at)
             VALUES (%d, %s, %s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
                contracted_minutes_total = VALUES(contracted_minutes_total),
                actual_minutes_total     = VALUES(actual_minutes_total),
                overtime_minutes_total   = VALUES(overtime_minutes_total),
                days_worked              = VALUES(days_worked),
                days_absent              = VALUES(days_absent),
                days_late                = VALUES(days_late),
                max_consecutive_days     = VALUES(max_consecutive_days),
                weekly_off_violations    = VALUES(weekly_off_violations),
                daily_hour_violations    = VALUES(daily_hour_violations),
                rest_period_violations   = VALUES(rest_period_violations),
                violations_json          = VALUES(violations_json),
                computed_at              = VALUES(computed_at)",
            $snapshot['employee_id'],
            $snapshot['period_start'],
            $snapshot['period_end'],
            $snapshot['contracted_minutes_total'],
            $snapshot['actual_minutes_total'],
            $snapshot['overtime_minutes_total'],
            $snapshot['days_worked'],
            $snapshot['days_absent'],
            $snapshot['days_late'],
            $snapshot['max_consecutive_days'],
            $snapshot['weekly_off_violations'],
            $snapshot['daily_hour_violations'],
            $snapshot['rest_period_violations'],
            $snapshot['violations_json'],
            $snapshot['computed_at']
        ) );

        $snapshot['violations_json'] = json_decode( $snapshot['violations_json'], true );
        return $snapshot;
    }

    public static function compute_bulk( string $period_start, string $period_end, ?int $dept_id = null ): int {
        global $wpdb;
        $empT = $wpdb->prefix . 'sfs_hr_employees';

        $sql = "SELECT id FROM {$empT} WHERE status = 'active'";
        $params = [];

        if ( $dept_id !== null ) {
            $sql     .= ' AND dept_id = %d';
            $params[] = $dept_id;
        }

        $employee_ids = $params
            ? $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_col( $sql );

        foreach ( $employee_ids as $eid ) {
            self::compute_snapshot( (int) $eid, $period_start, $period_end );
        }

        return count( $employee_ids );
    }

    public static function get_snapshot( int $employee_id, string $period_start, string $period_end ): ?array {
        global $wpdb;
        $snapT = $wpdb->prefix . 'sfs_hr_attendance_compliance_snapshots';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$snapT}
             WHERE employee_id = %d AND period_start = %s AND period_end = %s
             LIMIT 1",
            $employee_id, $period_start, $period_end
        ), ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        $row['violations_json'] = json_decode( $row['violations_json'], true );
        return $row;
    }

    public static function get_department_summary( string $period_start, string $period_end, ?int $dept_id = null ): array {
        global $wpdb;
        $snapT = $wpdb->prefix . 'sfs_hr_attendance_compliance_snapshots';
        $empT  = $wpdb->prefix . 'sfs_hr_employees';

        $where  = 'cs.period_start = %s AND cs.period_end = %s';
        $params = [ $period_start, $period_end ];

        if ( $dept_id !== null ) {
            $where   .= ' AND e.dept_id = %d';
            $params[] = $dept_id;
        }

        $agg = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS total_employees,
                    AVG(cs.actual_minutes_total) AS avg_actual_minutes,
                    SUM(cs.weekly_off_violations) AS total_weekly_off_violations,
                    SUM(cs.daily_hour_violations) AS total_daily_hour_violations,
                    SUM(cs.rest_period_violations) AS total_rest_period_violations,
                    SUM(cs.days_absent) AS total_days_absent,
                    SUM(cs.days_late) AS total_days_late
             FROM {$snapT} cs
             JOIN {$empT} e ON e.id = cs.employee_id
             WHERE {$where}",
            ...$params
        ), ARRAY_A );

        $top_violators = $wpdb->get_results( $wpdb->prepare(
            "SELECT cs.employee_id, e.employee_code,
                    (cs.weekly_off_violations + cs.daily_hour_violations + cs.rest_period_violations) AS total_violations
             FROM {$snapT} cs
             JOIN {$empT} e ON e.id = cs.employee_id
             WHERE {$where}
               AND (cs.weekly_off_violations + cs.daily_hour_violations + cs.rest_period_violations) > 0
             ORDER BY total_violations DESC
             LIMIT 10",
            ...$params
        ), ARRAY_A );

        return [
            'total_employees'              => (int) ( $agg['total_employees'] ?? 0 ),
            'avg_actual_minutes'           => round( (float) ( $agg['avg_actual_minutes'] ?? 0 ), 1 ),
            'total_weekly_off_violations'  => (int) ( $agg['total_weekly_off_violations'] ?? 0 ),
            'total_daily_hour_violations'  => (int) ( $agg['total_daily_hour_violations'] ?? 0 ),
            'total_rest_period_violations' => (int) ( $agg['total_rest_period_violations'] ?? 0 ),
            'total_days_absent'            => (int) ( $agg['total_days_absent'] ?? 0 ),
            'total_days_late'              => (int) ( $agg['total_days_late'] ?? 0 ),
            'top_violators'                => $top_violators,
        ];
    }

    public static function get_fatigue_alerts( int $max_consecutive = 6 ): array {
        global $wpdb;
        $sessT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $empT  = $wpdb->prefix . 'sfs_hr_employees';

        $lookback = wp_date( 'Y-m-d', strtotime( '-14 days' ) );
        $today    = wp_date( 'Y-m-d' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.employee_id, e.employee_code, s.work_date, s.status
             FROM {$sessT} s
             JOIN {$empT} e ON e.id = s.employee_id
             WHERE e.status = 'active'
               AND s.work_date BETWEEN %s AND %s
             ORDER BY s.employee_id ASC, s.work_date ASC",
            $lookback, $today
        ) );

        $grouped = [];
        foreach ( $rows as $r ) {
            $grouped[ (int) $r->employee_id ]['code'] = $r->employee_code;
            $grouped[ (int) $r->employee_id ]['days'][] = $r;
        }

        $rest_statuses = [ 'day_off', 'holiday', 'on_leave' ];
        $alerts        = [];

        foreach ( $grouped as $eid => $data ) {
            $streak    = 0;
            $last_rest = null;

            foreach ( $data['days'] as $day ) {
                if ( in_array( $day->status, $rest_statuses, true ) ) {
                    $streak    = 0;
                    $last_rest = $day->work_date;
                } else {
                    $streak++;
                }
            }

            if ( $streak >= $max_consecutive ) {
                $alerts[] = [
                    'employee_id'      => $eid,
                    'employee_code'    => $data['code'],
                    'consecutive_days' => $streak,
                    'last_rest_date'   => $last_rest,
                ];
            }
        }

        return $alerts;
    }

    public static function get_weekly_off_violators( string $start_date, string $end_date ): array {
        global $wpdb;
        $sessT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $empT  = $wpdb->prefix . 'sfs_hr_employees';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.employee_id, e.employee_code, s.work_date, s.status
             FROM {$sessT} s
             JOIN {$empT} e ON e.id = s.employee_id
             WHERE s.work_date BETWEEN %s AND %s
             ORDER BY s.employee_id ASC, s.work_date ASC",
            $start_date, $end_date
        ) );

        $grouped = [];
        foreach ( $rows as $r ) {
            $grouped[ (int) $r->employee_id ]['code'] = $r->employee_code;
            $grouped[ (int) $r->employee_id ]['days'][] = $r;
        }

        $worked_statuses = [ 'present', 'late', 'left_early', 'incomplete' ];
        $violators       = [];

        foreach ( $grouped as $eid => $data ) {
            $violation_weeks = [];

            foreach ( $data['days'] as $day ) {
                $week_start = wp_date( 'Y-m-d', strtotime( 'monday this week', strtotime( $day->work_date ) ) );

                if ( ! isset( $violation_weeks[ $week_start ] ) ) {
                    $violation_weeks[ $week_start ] = 0;
                }
                if ( in_array( $day->status, $worked_statuses, true ) ) {
                    $violation_weeks[ $week_start ]++;
                }
            }

            $bad_weeks = array_filter( $violation_weeks, fn( $count ) => $count >= 7 );
            if ( ! empty( $bad_weeks ) ) {
                $violators[] = [
                    'employee_id'   => $eid,
                    'employee_code' => $data['code'],
                    'weeks'         => array_keys( $bad_weeks ),
                    'total_weeks'   => count( $bad_weeks ),
                ];
            }
        }

        return $violators;
    }

    // ── Private helpers ────────────────────────────────────────────────

    private static function calc_max_consecutive( array $sessions, array $worked_statuses ): int {
        $max     = 0;
        $current = 0;

        foreach ( $sessions as $row ) {
            if ( in_array( $row->status, $worked_statuses, true ) ) {
                $current++;
                if ( $current > $max ) {
                    $max = $current;
                }
            } else {
                $current = 0;
            }
        }

        return $max;
    }

    private static function calc_weekly_off_violations( array $sessions, array $worked_statuses, string $period_start, string $period_end, array &$violations ): int {
        $weeks = [];

        foreach ( $sessions as $row ) {
            $week_start = wp_date( 'Y-m-d', strtotime( 'monday this week', strtotime( $row->work_date ) ) );
            if ( ! isset( $weeks[ $week_start ] ) ) {
                $weeks[ $week_start ] = 0;
            }
            if ( in_array( $row->status, $worked_statuses, true ) ) {
                $weeks[ $week_start ]++;
            }
        }

        $count = 0;
        foreach ( $weeks as $week => $worked ) {
            if ( $worked >= 7 ) {
                $count++;
                $violations[] = [
                    'date'  => $week,
                    'type'  => 'weekly_off_missing',
                    'value' => $worked,
                    'limit' => self::MAX_CONSECUTIVE_DAYS,
                ];
            }
        }

        return $count;
    }

    private static function calc_rest_violations( array $sessions, array $worked_statuses, array &$violations ): int {
        $count    = 0;
        $prev_out = null;
        $prev_date = null;

        foreach ( $sessions as $row ) {
            if ( ! in_array( $row->status, $worked_statuses, true ) ) {
                $prev_out  = null;
                $prev_date = null;
                continue;
            }

            if ( $prev_out !== null && $row->in_time ) {
                $gap_hours = ( strtotime( $row->in_time ) - strtotime( $prev_out ) ) / 3600;

                $min_rest = $row->min_rest_hours
                    ? (float) $row->min_rest_hours
                    : (float) self::MIN_REST_BETWEEN_SHIFTS_HOURS;

                if ( $gap_hours < $min_rest && $gap_hours >= 0 ) {
                    $count++;
                    $violations[] = [
                        'date'  => $row->work_date,
                        'type'  => 'rest_period_short',
                        'value' => round( $gap_hours, 1 ),
                        'limit' => $min_rest,
                    ];
                }
            }

            $prev_out  = $row->out_time;
            $prev_date = $row->work_date;
        }

        return $count;
    }
}
