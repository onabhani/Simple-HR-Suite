<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Roster_Service {

    private static function tables(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return [
            'runs'      => "{$p}sfs_hr_attendance_roster_runs",
            'assign'    => "{$p}sfs_hr_attendance_shift_assign",
            'emp_shifts'=> "{$p}sfs_hr_attendance_emp_shifts",
            'schedules' => "{$p}sfs_hr_attendance_shift_schedules",
            'shifts'    => "{$p}sfs_hr_attendance_shifts",
            'employees' => "{$p}sfs_hr_employees",
        ];
    }

    public static function generate( array $args ): array {
        global $wpdb;
        $t = self::tables();

        $start_date  = $args['start_date']  ?? '';
        $end_date    = $args['end_date']    ?? '';
        $dept_id     = ! empty( $args['dept_id'] )     ? (int) $args['dept_id']     : null;
        $shift_id    = ! empty( $args['shift_id'] )    ? (int) $args['shift_id']    : null;
        $schedule_id = ! empty( $args['schedule_id'] ) ? (int) $args['schedule_id'] : null;

        // Validate dates
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid date format. Use YYYY-MM-DD.', 'sfs-hr' ) ];
        }
        if ( $end_date < $start_date ) {
            return [ 'success' => false, 'error' => __( 'End date must be on or after start date.', 'sfs-hr' ) ];
        }
        $diff = ( new \DateTimeImmutable( $end_date ) )->diff( new \DateTimeImmutable( $start_date ) )->days;
        if ( $diff > 90 ) {
            return [ 'success' => false, 'error' => __( 'Date range cannot exceed 90 days.', 'sfs-hr' ) ];
        }

        // Find employees
        $where = "status = 'active'";
        $params = [];
        if ( $dept_id ) {
            $where .= " AND dept_id = %d";
            $params[] = $dept_id;
        }
        $sql = "SELECT id FROM {$t['employees']} WHERE {$where} ORDER BY id ASC";
        $employees = $params
            ? $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_col( $sql );

        if ( empty( $employees ) ) {
            return [ 'success' => false, 'error' => __( 'No active employees found.', 'sfs-hr' ) ];
        }

        // Load schedule if specified
        $schedule = null;
        if ( $schedule_id ) {
            $schedule = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$t['schedules']} WHERE id = %d AND active = 1 LIMIT 1",
                $schedule_id
            ) );
        }

        $created    = 0;
        $skipped    = 0;
        $violations = [];

        // Create roster run first to get the ID
        $now     = current_time( 'mysql' );
        $user_id = get_current_user_id();

        $run_data   = [
            'start_date'          => $start_date,
            'end_date'            => $end_date,
            'assignments_created' => 0,
            'assignments_skipped' => 0,
            'violations_json'     => '[]',
            'status'              => 'draft',
            'created_at'          => $now,
            'created_by'          => $user_id,
        ];
        $run_format = [ '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d' ];

        if ( $dept_id )     { $run_data['dept_id']     = $dept_id;     $run_format[] = '%d'; }
        if ( $shift_id )    { $run_data['shift_id']    = $shift_id;    $run_format[] = '%d'; }
        if ( $schedule_id ) { $run_data['schedule_id'] = $schedule_id; $run_format[] = '%d'; }

        $wpdb->insert( $t['runs'], $run_data, $run_format );

        $run_id = (int) $wpdb->insert_id;
        if ( ! $run_id ) {
            return [ 'success' => false, 'error' => __( 'Failed to create roster run.', 'sfs-hr' ) ];
        }

        $current = new \DateTimeImmutable( $start_date );
        $last    = new \DateTimeImmutable( $end_date );

        while ( $current <= $last ) {
            $ymd = $current->format( 'Y-m-d' );
            $dow = strtolower( $current->format( 'l' ) );

            foreach ( $employees as $emp_id ) {
                $emp_id = (int) $emp_id;

                // Skip if assignment already exists for this employee + date
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$t['assign']} WHERE employee_id = %d AND work_date = %s LIMIT 1",
                    $emp_id,
                    $ymd
                ) );
                if ( $exists ) {
                    $skipped++;
                    continue;
                }

                // Resolve which shift to assign
                $resolved_shift_id = $shift_id;
                $is_holiday        = 0;

                if ( ! $resolved_shift_id ) {
                    $emp_mapping = $wpdb->get_row( $wpdb->prepare(
                        "SELECT shift_id, schedule_id FROM {$t['emp_shifts']}
                         WHERE employee_id = %d AND start_date <= %s
                         ORDER BY start_date DESC, id DESC LIMIT 1",
                        $emp_id,
                        $ymd
                    ) );
                    if ( $emp_mapping ) {
                        $resolved_shift_id = (int) $emp_mapping->shift_id;
                        // Use employee-level schedule if no global schedule override
                        if ( ! $schedule && ! empty( $emp_mapping->schedule_id ) ) {
                            $emp_schedule = $wpdb->get_row( $wpdb->prepare(
                                "SELECT * FROM {$t['schedules']} WHERE id = %d AND active = 1 LIMIT 1",
                                (int) $emp_mapping->schedule_id
                            ) );
                            if ( $emp_schedule ) {
                                $resolved_shift_id = self::resolve_shift_from_schedule( $emp_schedule, $ymd );
                            }
                        }
                    }
                }

                // Resolve shift from schedule rotation
                if ( $schedule && $resolved_shift_id ) {
                    $sched_shift = self::resolve_shift_from_schedule( $schedule, $ymd );
                    if ( $sched_shift ) {
                        $resolved_shift_id = $sched_shift;
                    }
                }

                if ( ! $resolved_shift_id ) {
                    $skipped++;
                    continue;
                }

                // Load shift details for holiday/rest checks
                $shift_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$t['shifts']} WHERE id = %d LIMIT 1",
                    $resolved_shift_id
                ) );

                if ( ! $shift_row ) {
                    $skipped++;
                    continue;
                }

                // Check weekly_overrides for holiday/day-off
                if ( ! empty( $shift_row->weekly_overrides ) ) {
                    $overrides = json_decode( $shift_row->weekly_overrides, true );
                    if ( is_array( $overrides ) && array_key_exists( $dow, $overrides ) && $overrides[ $dow ] === null ) {
                        $is_holiday = 1;
                    }
                }

                // Check minimum rest violation
                $violation = self::check_rest_violation( $emp_id, $ymd, (array) $shift_row );
                if ( $violation ) {
                    $violations[] = [
                        'employee_id' => $emp_id,
                        'date'        => $ymd,
                        'message'     => $violation,
                    ];
                }

                $wpdb->insert( $t['assign'], [
                    'employee_id'   => $emp_id,
                    'shift_id'      => $resolved_shift_id,
                    'work_date'     => $ymd,
                    'is_holiday'    => $is_holiday,
                    'roster_run_id' => $run_id,
                ], [ '%d', '%d', '%s', '%d', '%d' ] );

                $created++;
            }

            $current = $current->modify( '+1 day' );
        }

        // Update run record with final counts
        $wpdb->update(
            $t['runs'],
            [
                'assignments_created' => $created,
                'assignments_skipped' => $skipped,
                'violations_json'     => wp_json_encode( $violations ),
            ],
            [ 'id' => $run_id ],
            [ '%d', '%d', '%s' ],
            [ '%d' ]
        );

        return [
            'success'       => true,
            'roster_run_id' => $run_id,
            'created'       => $created,
            'skipped'       => $skipped,
            'violations'    => $violations,
        ];
    }

    public static function publish( int $run_id ): bool {
        global $wpdb;
        $t = self::tables();

        $run = self::get_run( $run_id );
        if ( ! $run || $run['status'] !== 'draft' ) {
            return false;
        }

        return (bool) $wpdb->update(
            $t['runs'],
            [
                'status'       => 'published',
                'published_at' => current_time( 'mysql' ),
                'published_by' => get_current_user_id(),
            ],
            [ 'id' => $run_id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );
    }

    public static function revert( int $run_id ): bool {
        global $wpdb;
        $t = self::tables();

        $run = self::get_run( $run_id );
        if ( ! $run || $run['status'] !== 'draft' ) {
            return false;
        }

        $wpdb->delete( $t['assign'], [ 'roster_run_id' => $run_id ], [ '%d' ] );

        return (bool) $wpdb->update(
            $t['runs'],
            [ 'status' => 'reverted' ],
            [ 'id' => $run_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    public static function get_run( int $id ): ?array {
        global $wpdb;
        $t = self::tables();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['runs']} WHERE id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        $row['violations_json'] = json_decode( $row['violations_json'] ?? '[]', true );
        return $row;
    }

    public static function list_runs( array $args = [] ): array {
        global $wpdb;
        $t = self::tables();

        $where  = '1=1';
        $params = [];

        if ( ! empty( $args['dept_id'] ) ) {
            $where   .= ' AND dept_id = %d';
            $params[] = (int) $args['dept_id'];
        }
        if ( ! empty( $args['status'] ) ) {
            $where   .= ' AND status = %s';
            $params[] = sanitize_text_field( $args['status'] );
        }

        $limit  = isset( $args['limit'] )  ? absint( $args['limit'] )  : 20;
        $offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        $sql = "SELECT * FROM {$t['runs']} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        foreach ( $rows as &$row ) {
            $row['violations_json'] = json_decode( $row['violations_json'] ?? '[]', true );
        }
        unset( $row );

        return $rows;
    }

    public static function check_rest_violation( int $employee_id, string $date, array $shift ): ?string {
        global $wpdb;
        $t = self::tables();

        $min_rest = ! empty( $shift['min_rest_hours'] ) ? (float) $shift['min_rest_hours'] : 0;
        if ( $min_rest <= 0 ) {
            return null;
        }

        $prev_date = ( new \DateTimeImmutable( $date ) )->modify( '-1 day' )->format( 'Y-m-d' );

        $prev_assign = $wpdb->get_row( $wpdb->prepare(
            "SELECT sa.shift_id, sh.end_time
             FROM {$t['assign']} sa
             JOIN {$t['shifts']} sh ON sh.id = sa.shift_id
             WHERE sa.employee_id = %d AND sa.work_date = %s
             LIMIT 1",
            $employee_id,
            $prev_date
        ) );

        if ( ! $prev_assign || empty( $prev_assign->end_time ) ) {
            return null;
        }

        $new_start = $shift['start_time'] ?? '';
        if ( ! $new_start ) {
            return null;
        }

        // Calculate hours between previous shift end and new shift start
        $prev_end_dt = new \DateTimeImmutable( $prev_date . ' ' . $prev_assign->end_time );
        $new_start_dt = new \DateTimeImmutable( $date . ' ' . $new_start );

        // Handle overnight previous shift (end_time is early morning = next day)
        if ( $prev_assign->end_time < '12:00:00' && $prev_assign->end_time < ( $shift['start_time'] ?? '23:59' ) ) {
            $prev_end_dt = $prev_end_dt->modify( '+1 day' );
        }

        $gap_hours = ( $new_start_dt->getTimestamp() - $prev_end_dt->getTimestamp() ) / 3600;

        if ( $gap_hours < $min_rest ) {
            return sprintf(
                /* translators: 1: rest gap hours, 2: required minimum hours */
                __( 'Rest violation: only %.1f hours between shifts (minimum %.1f required).', 'sfs-hr' ),
                $gap_hours,
                $min_rest
            );
        }

        return null;
    }

    // Resolve which shift_id a schedule dictates for a given date
    private static function resolve_shift_from_schedule( object $schedule, string $ymd ): ?int {
        if ( empty( $schedule->entries ) || (int) $schedule->cycle_days <= 0 ) {
            return null;
        }

        $entries = json_decode( $schedule->entries, true );
        if ( ! is_array( $entries ) || empty( $entries ) ) {
            return null;
        }

        $anchor     = new \DateTimeImmutable( $schedule->anchor_date );
        $target     = new \DateTimeImmutable( $ymd );
        $diff_days  = (int) $anchor->diff( $target )->format( '%r%a' );
        $cycle_days = (int) $schedule->cycle_days;
        $cycle_day  = ( ( $diff_days % $cycle_days ) + $cycle_days ) % $cycle_days;
        $cycle_day_1 = $cycle_day + 1;

        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            if ( (int) ( $entry['day'] ?? 0 ) === $cycle_day_1 ) {
                if ( ! empty( $entry['day_off'] ) ) {
                    return null;
                }
                $sid = (int) ( $entry['shift_id'] ?? 0 );
                return $sid > 0 ? $sid : null;
            }
        }

        return null;
    }
}
