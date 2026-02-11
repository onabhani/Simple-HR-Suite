<?php
namespace SFS\HR\Modules\Performance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Attendance Metrics Service
 *
 * Calculates attendance commitment metrics including:
 * - Commitment percentage
 * - Flag counts (late, early leave, absent, incomplete)
 * - Working days analysis
 *
 * @version 1.0.0
 */
class Attendance_Metrics {

    /**
     * Get attendance metrics for a single employee.
     *
     * @param int    $employee_id
     * @param string $start_date Y-m-d format (defaults to start of current month)
     * @param string $end_date   Y-m-d format (defaults to today)
     * @return array
     */
    public static function get_employee_metrics( int $employee_id, string $start_date = '', string $end_date = '' ): array {
        global $wpdb;

        // Default date range: current configured attendance period
        if ( empty( $start_date ) || empty( $end_date ) ) {
            $period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
            if ( empty( $start_date ) ) {
                $start_date = $period['start'];
            }
            if ( empty( $end_date ) ) {
                $end_date = $period['end'];
            }
        }

        $sessions_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $employees_table = $wpdb->prefix . 'sfs_hr_employees';

        // Get employee info
        $employee = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, employee_code, first_name, last_name, dept_id
             FROM {$employees_table}
             WHERE id = %d",
            $employee_id
        ) );

        if ( ! $employee ) {
            return [
                'error'   => true,
                'message' => __( 'Employee not found', 'sfs-hr' ),
            ];
        }

        // Get attendance sessions for the period
        $sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT work_date, status, flags_json, rounded_net_minutes, in_time, out_time,
                    break_delay_minutes, no_break_taken
             FROM {$sessions_table}
             WHERE employee_id = %d
               AND work_date >= %s
               AND work_date <= %s
             ORDER BY work_date ASC",
            $employee_id,
            $start_date,
            $end_date
        ) );

        // Initialize counters
        $metrics = [
            'employee_id'       => $employee_id,
            'employee_code'     => $employee->employee_code,
            'employee_name'     => trim( $employee->first_name . ' ' . $employee->last_name ),
            'dept_id'           => $employee->dept_id,
            'period_start'      => $start_date,
            'period_end'        => $end_date,
            'total_working_days'=> 0,
            'days_present'      => 0,
            'days_absent'       => 0,
            'days_on_leave'     => 0,
            'days_holiday'      => 0,
            'days_day_off'      => 0,
            'late_count'        => 0,
            'early_leave_count' => 0,
            'incomplete_count'  => 0,
            'break_delay_count' => 0,
            'no_break_taken_count' => 0,
            'total_break_delay_minutes' => 0,
            'total_worked_minutes' => 0,
            'commitment_pct'    => 100.00,
            'attendance_grade'  => 'excellent',
            'flag_details'      => [],
            'daily_breakdown'   => [],
        ];

        // Process each session
        foreach ( $sessions as $session ) {
            $flags = json_decode( $session->flags_json, true ) ?: [];
            $status = $session->status;

            // Track daily breakdown
            $day_data = [
                'date'    => $session->work_date,
                'status'  => $status,
                'flags'   => $flags,
                'minutes' => (int) $session->rounded_net_minutes,
                'in_time' => $session->in_time,
                'out_time'=> $session->out_time,
                'break_delay_minutes' => (int) ( $session->break_delay_minutes ?? 0 ),
                'no_break_taken'      => (int) ( $session->no_break_taken ?? 0 ),
            ];
            $metrics['daily_breakdown'][] = $day_data;

            // Categorize the day
            switch ( $status ) {
                case 'present':
                case 'late':
                case 'left_early':
                    $metrics['total_working_days']++;
                    $metrics['days_present']++;
                    $metrics['total_worked_minutes'] += (int) $session->rounded_net_minutes;
                    break;

                case 'absent':
                    $metrics['total_working_days']++;
                    $metrics['days_absent']++;
                    break;

                case 'incomplete':
                    $metrics['total_working_days']++;
                    $metrics['days_present']++; // Partial day
                    $metrics['incomplete_count']++;
                    $metrics['total_worked_minutes'] += (int) $session->rounded_net_minutes;
                    break;

                case 'on_leave':
                    $metrics['days_on_leave']++;
                    break;

                case 'holiday':
                    $metrics['days_holiday']++;
                    break;

                case 'day_off':
                    $metrics['days_day_off']++;
                    break;
            }

            // Count flags
            if ( in_array( 'late', $flags, true ) ) {
                $metrics['late_count']++;
                $metrics['flag_details'][] = [
                    'date' => $session->work_date,
                    'flag' => 'late',
                ];
            }
            if ( in_array( 'left_early', $flags, true ) ) {
                $metrics['early_leave_count']++;
                $metrics['flag_details'][] = [
                    'date' => $session->work_date,
                    'flag' => 'left_early',
                ];
            }
            if ( in_array( 'incomplete', $flags, true ) || $status === 'incomplete' ) {
                if ( $status !== 'incomplete' ) { // Avoid double counting
                    $metrics['incomplete_count']++;
                }
                $metrics['flag_details'][] = [
                    'date' => $session->work_date,
                    'flag' => 'incomplete',
                ];
            }
            // Break delay tracking
            $session_break_delay = (int) ( $session->break_delay_minutes ?? 0 );
            if ( $session_break_delay > 0 ) {
                $metrics['break_delay_count']++;
                $metrics['total_break_delay_minutes'] += $session_break_delay;
                $metrics['flag_details'][] = [
                    'date'    => $session->work_date,
                    'flag'    => 'break_delay',
                    'minutes' => $session_break_delay,
                ];
            }
            if ( (int) ( $session->no_break_taken ?? 0 ) === 1 ) {
                $metrics['no_break_taken_count']++;
                $metrics['flag_details'][] = [
                    'date' => $session->work_date,
                    'flag' => 'no_break_taken',
                ];
            }
        }

        // Calculate commitment percentage
        // Formula: (Working days - Issue days) / Working days * 100
        // Issue days = absent + (late + early_leave + incomplete) weighted
        if ( $metrics['total_working_days'] > 0 ) {
            // Each flag counts as a partial deduction
            $issue_score = $metrics['days_absent']; // Full day deduction
            $issue_score += $metrics['late_count'] * 0.25; // 25% deduction per late
            $issue_score += $metrics['early_leave_count'] * 0.25; // 25% deduction per early leave
            $issue_score += $metrics['incomplete_count'] * 0.5; // 50% deduction per incomplete
            $issue_score += $metrics['break_delay_count'] * 0.25; // 25% deduction per break delay
            $issue_score += $metrics['no_break_taken_count'] * 0.15; // 15% deduction per no break taken

            $effective_present = $metrics['total_working_days'] - $issue_score;
            $effective_present = max( 0, $effective_present );

            $metrics['commitment_pct'] = round(
                ( $effective_present / $metrics['total_working_days'] ) * 100,
                2
            );
        }

        // Determine grade
        $metrics['attendance_grade'] = self::get_grade( $metrics['commitment_pct'] );

        return $metrics;
    }

    /**
     * Get attendance metrics for all employees in a department.
     *
     * @param int    $dept_id    Department ID (0 for all)
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function get_department_metrics( int $dept_id = 0, string $start_date = '', string $end_date = '' ): array {
        global $wpdb;

        $employees_table = $wpdb->prefix . 'sfs_hr_employees';

        // Default date range: current configured attendance period
        if ( empty( $start_date ) || empty( $end_date ) ) {
            $period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
            if ( empty( $start_date ) ) {
                $start_date = $period['start'];
            }
            if ( empty( $end_date ) ) {
                $end_date = $period['end'];
            }
        }

        // Get employees
        $where = "WHERE status = 'active'";
        if ( $dept_id > 0 ) {
            $where .= $wpdb->prepare( " AND dept_id = %d", $dept_id );
        }

        $employees = $wpdb->get_results(
            "SELECT id, employee_code, first_name, last_name, dept_id
             FROM {$employees_table}
             {$where}
             ORDER BY first_name, last_name"
        );

        $results = [
            'dept_id'      => $dept_id,
            'period_start' => $start_date,
            'period_end'   => $end_date,
            'employee_count' => count( $employees ),
            'employees'    => [],
            'summary'      => [
                'avg_commitment_pct'    => 0,
                'total_late_count'      => 0,
                'total_early_leave_count' => 0,
                'total_absent_days'     => 0,
                'total_incomplete_count'=> 0,
                'total_break_delay_count' => 0,
                'total_no_break_taken_count' => 0,
                'grade_distribution'    => [
                    'excellent' => 0,
                    'good'      => 0,
                    'fair'      => 0,
                    'poor'      => 0,
                ],
            ],
        ];

        $total_commitment = 0;

        foreach ( $employees as $emp ) {
            $metrics = self::get_employee_metrics( $emp->id, $start_date, $end_date );

            // Remove daily breakdown for summary view
            unset( $metrics['daily_breakdown'] );
            unset( $metrics['flag_details'] );

            $results['employees'][] = $metrics;

            // Aggregate summary
            $total_commitment += $metrics['commitment_pct'];
            $results['summary']['total_late_count'] += $metrics['late_count'];
            $results['summary']['total_early_leave_count'] += $metrics['early_leave_count'];
            $results['summary']['total_absent_days'] += $metrics['days_absent'];
            $results['summary']['total_incomplete_count'] += $metrics['incomplete_count'];
            $results['summary']['total_break_delay_count'] += $metrics['break_delay_count'];
            $results['summary']['total_no_break_taken_count'] += $metrics['no_break_taken_count'];
            $results['summary']['grade_distribution'][ $metrics['attendance_grade'] ]++;
        }

        // Calculate average
        if ( count( $employees ) > 0 ) {
            $results['summary']['avg_commitment_pct'] = round(
                $total_commitment / count( $employees ),
                2
            );
        }

        return $results;
    }

    /**
     * Get all departments with their metrics summary.
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function get_all_departments_summary( string $start_date = '', string $end_date = '' ): array {
        global $wpdb;

        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        // Default date range: current configured attendance period
        if ( empty( $start_date ) || empty( $end_date ) ) {
            $period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
            if ( empty( $start_date ) ) {
                $start_date = $period['start'];
            }
            if ( empty( $end_date ) ) {
                $end_date = $period['end'];
            }
        }

        $departments = $wpdb->get_results(
            "SELECT id, name FROM {$dept_table} WHERE active = 1 ORDER BY name"
        );

        $results = [
            'period_start' => $start_date,
            'period_end'   => $end_date,
            'departments'  => [],
        ];

        foreach ( $departments as $dept ) {
            $metrics = self::get_department_metrics( $dept->id, $start_date, $end_date );
            $results['departments'][] = [
                'dept_id'               => $dept->id,
                'dept_name'             => $dept->name,
                'employee_count'        => $metrics['employee_count'],
                'avg_commitment_pct'    => $metrics['summary']['avg_commitment_pct'],
                'total_late_count'      => $metrics['summary']['total_late_count'],
                'total_early_leave_count' => $metrics['summary']['total_early_leave_count'],
                'total_absent_days'     => $metrics['summary']['total_absent_days'],
                'grade_distribution'    => $metrics['summary']['grade_distribution'],
            ];
        }

        return $results;
    }

    /**
     * Get grade based on commitment percentage.
     *
     * @param float $commitment_pct
     * @return string
     */
    public static function get_grade( float $commitment_pct ): string {
        $settings = \SFS\HR\Modules\Performance\PerformanceModule::get_settings();
        $thresholds = $settings['attendance_thresholds'];

        if ( $commitment_pct >= $thresholds['excellent'] ) {
            return 'excellent';
        } elseif ( $commitment_pct >= $thresholds['good'] ) {
            return 'good';
        } elseif ( $commitment_pct >= $thresholds['fair'] ) {
            return 'fair';
        }
        return 'poor';
    }

    /**
     * Get grade label with color.
     *
     * @param string $grade
     * @return array
     */
    public static function get_grade_display( string $grade ): array {
        $grades = [
            'excellent' => [
                'label' => __( 'Excellent', 'sfs-hr' ),
                'color' => '#22c55e',
                'bg'    => '#dcfce7',
            ],
            'good' => [
                'label' => __( 'Good', 'sfs-hr' ),
                'color' => '#3b82f6',
                'bg'    => '#dbeafe',
            ],
            'fair' => [
                'label' => __( 'Fair', 'sfs-hr' ),
                'color' => '#f59e0b',
                'bg'    => '#fef3c7',
            ],
            'poor' => [
                'label' => __( 'Poor', 'sfs-hr' ),
                'color' => '#ef4444',
                'bg'    => '#fee2e2',
            ],
        ];

        return $grades[ $grade ] ?? $grades['poor'];
    }

    /**
     * Save a performance snapshot for an employee.
     *
     * @param int    $employee_id
     * @param string $period_start
     * @param string $period_end
     * @return int|false Snapshot ID or false on failure
     */
    public static function save_snapshot( int $employee_id, string $period_start, string $period_end ) {
        global $wpdb;

        $metrics = self::get_employee_metrics( $employee_id, $period_start, $period_end );

        if ( isset( $metrics['error'] ) ) {
            return false;
        }

        $table = $wpdb->prefix . 'sfs_hr_performance_snapshots';

        // Check if snapshot already exists for this period
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE employee_id = %d AND period_start = %s AND period_end = %s",
            $employee_id,
            $period_start,
            $period_end
        ) );

        $data = [
            'employee_id'            => $employee_id,
            'period_start'           => $period_start,
            'period_end'             => $period_end,
            'total_working_days'     => $metrics['total_working_days'],
            'days_present'           => $metrics['days_present'],
            'days_absent'            => $metrics['days_absent'],
            'late_count'             => $metrics['late_count'],
            'early_leave_count'      => $metrics['early_leave_count'],
            'incomplete_count'       => $metrics['incomplete_count'],
            'break_delay_count'      => $metrics['break_delay_count'],
            'no_break_taken_count'   => $metrics['no_break_taken_count'],
            'total_break_delay_minutes' => $metrics['total_break_delay_minutes'],
            'attendance_commitment_pct' => $metrics['commitment_pct'],
            'meta_json'              => wp_json_encode( [
                'days_on_leave'  => $metrics['days_on_leave'],
                'days_holiday'   => $metrics['days_holiday'],
                'days_day_off'   => $metrics['days_day_off'],
                'total_worked_minutes' => $metrics['total_worked_minutes'],
            ] ),
            'created_at'             => current_time( 'mysql' ),
        ];

        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'id' => $existing ] );
            return (int) $existing;
        } else {
            $wpdb->insert( $table, $data );
            return (int) $wpdb->insert_id;
        }
    }

    /**
     * Get historical snapshots for an employee.
     *
     * @param int $employee_id
     * @param int $limit
     * @return array
     */
    public static function get_employee_history( int $employee_id, int $limit = 12 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_snapshots';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE employee_id = %d
             ORDER BY period_end DESC
             LIMIT %d",
            $employee_id,
            $limit
        ), ARRAY_A );
    }
}
