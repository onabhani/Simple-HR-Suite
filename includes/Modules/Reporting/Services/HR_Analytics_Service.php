<?php
namespace SFS\HR\Modules\Reporting\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * HR Analytics Service
 *
 * Provides cross-module analytics and dashboard data for headcount,
 * attrition, leave utilization, payroll, attendance, and workforce composition.
 *
 * @version 1.0.0
 */
class HR_Analytics_Service {

    /**
     * Get headcount summary with breakdowns.
     *
     * @param int|null $dept_id Filter by department (null = all).
     * @return array
     */
    public static function get_headcount_summary( ?int $dept_id = null ): array {
        global $wpdb;

        $emp   = $wpdb->prefix . 'sfs_hr_employees';
        $dept  = $wpdb->prefix . 'sfs_hr_departments';
        $where = "e.status = 'active'";

        if ( $dept_id !== null ) {
            $where .= $wpdb->prepare( ' AND e.dept_id = %d', $dept_id );
        }

        // Total active
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$emp} e WHERE {$where}"
        );

        // By department
        $by_department = $wpdb->get_results(
            "SELECT d.id AS dept_id, d.name AS dept_name, COUNT(e.id) AS count
             FROM {$emp} e
             LEFT JOIN {$dept} d ON d.id = e.dept_id
             WHERE {$where}
             GROUP BY d.id, d.name
             ORDER BY count DESC",
            ARRAY_A
        );

        // By employment type
        $by_type = $wpdb->get_results(
            "SELECT employment_type, COUNT(*) AS count
             FROM {$emp} e
             WHERE {$where}
             GROUP BY employment_type
             ORDER BY count DESC",
            ARRAY_A
        );

        // By gender
        $by_gender = $wpdb->get_results(
            "SELECT gender, COUNT(*) AS count
             FROM {$emp} e
             WHERE {$where}
             GROUP BY gender
             ORDER BY count DESC",
            ARRAY_A
        );

        $thirty_ago = wp_date( 'Y-m-d', strtotime( '-30 days' ) );

        // New hires last 30 days
        $dept_clause_new = ( $dept_id !== null )
            ? $wpdb->prepare( ' AND dept_id = %d', $dept_id )
            : '';

        $new_hires = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$emp}
             WHERE status = 'active' AND hire_date >= %s{$dept_clause_new}",
            $thirty_ago
        ) );

        // Terminated last 30 days
        $dept_clause_term = ( $dept_id !== null )
            ? $wpdb->prepare( ' AND dept_id = %d', $dept_id )
            : '';

        $terminated = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$emp}
             WHERE status = 'terminated' AND updated_at >= %s{$dept_clause_term}",
            $thirty_ago
        ) );

        return [
            'total'           => $total,
            'by_department'   => $by_department,
            'by_type'         => $by_type,
            'by_gender'       => $by_gender,
            'new_hires_30d'   => $new_hires,
            'terminated_30d'  => $terminated,
        ];
    }

    /**
     * Get monthly attrition report.
     *
     * @param int      $months  Number of months to look back.
     * @param int|null $dept_id Filter by department.
     * @return array
     */
    public static function get_attrition_report( int $months = 12, ?int $dept_id = null ): array {
        global $wpdb;

        $emp         = $wpdb->prefix . 'sfs_hr_employees';
        $start_date  = wp_date( 'Y-m-01', strtotime( "-{$months} months" ) );
        $dept_clause = ( $dept_id !== null )
            ? $wpdb->prepare( ' AND dept_id = %d', $dept_id )
            : '';

        // Terminated per month
        $terminated_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(updated_at, '%%Y-%%m') AS month, COUNT(*) AS terminated
             FROM {$emp}
             WHERE status = 'terminated'
               AND updated_at >= %s{$dept_clause}
             GROUP BY month
             ORDER BY month ASC",
            $start_date
        ), ARRAY_A );

        $terminated_map = [];
        foreach ( $terminated_rows as $row ) {
            $terminated_map[ $row['month'] ] = (int) $row['terminated'];
        }

        // Build month list
        $report = [];
        $period_start = new \DateTime( $start_date );
        $now          = new \DateTime( wp_date( 'Y-m-01' ) );

        while ( $period_start <= $now ) {
            $month_key = $period_start->format( 'Y-m' );
            $m_start   = $period_start->format( 'Y-m-01' );
            $m_end     = $period_start->format( 'Y-m-t' );

            // Average headcount: (count at start + count at end) / 2
            $count_start = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$emp}
                 WHERE hire_date <= %s
                   AND (status = 'active' OR (status = 'terminated' AND updated_at > %s)){$dept_clause}",
                $m_start,
                $m_start
            ) );

            $count_end = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$emp}
                 WHERE hire_date <= %s
                   AND (status = 'active' OR (status = 'terminated' AND updated_at > %s)){$dept_clause}",
                $m_end,
                $m_end
            ) );

            $avg_headcount = ( $count_start + $count_end ) / 2;
            $term_count    = $terminated_map[ $month_key ] ?? 0;
            $rate          = $avg_headcount > 0
                ? round( ( $term_count / $avg_headcount ) * 100, 2 )
                : 0.0;

            $report[] = [
                'month'          => $month_key,
                'terminated'     => $term_count,
                'avg_headcount'  => (int) round( $avg_headcount ),
                'rate'           => $rate,
            ];

            $period_start->modify( '+1 month' );
        }

        return $report;
    }

    /**
     * Get leave utilization across leave types.
     *
     * @param int|null $dept_id Filter by department.
     * @return array
     */
    public static function get_leave_utilization( ?int $dept_id = null ): array {
        global $wpdb;

        $bal  = $wpdb->prefix . 'sfs_hr_leave_balances';
        $emp  = $wpdb->prefix . 'sfs_hr_employees';
        $join = "INNER JOIN {$emp} e ON e.id = lb.employee_id AND e.status = 'active'";

        $dept_clause = ( $dept_id !== null )
            ? $wpdb->prepare( ' AND e.dept_id = %d', $dept_id )
            : '';

        $rows = $wpdb->get_results(
            "SELECT lb.leave_type_id,
                    SUM(lb.total_days) AS total_allocated,
                    SUM(lb.used_days) AS total_used
             FROM {$bal} lb
             {$join}
             WHERE 1=1{$dept_clause}
             GROUP BY lb.leave_type_id
             ORDER BY lb.leave_type_id",
            ARRAY_A
        );

        $types              = [];
        $grand_allocated    = 0.0;
        $grand_used         = 0.0;

        foreach ( $rows as $row ) {
            $allocated = (float) $row['total_allocated'];
            $used      = (float) $row['total_used'];
            $pct       = $allocated > 0
                ? round( ( $used / $allocated ) * 100, 2 )
                : 0.0;

            $types[] = [
                'leave_type_id'    => (int) $row['leave_type_id'],
                'total_allocated'  => $allocated,
                'total_used'       => $used,
                'utilization_pct'  => $pct,
            ];

            $grand_allocated += $allocated;
            $grand_used      += $used;
        }

        $overall = $grand_allocated > 0
            ? round( ( $grand_used / $grand_allocated ) * 100, 2 )
            : 0.0;

        return [
            'types'               => $types,
            'overall_utilization' => $overall,
        ];
    }

    /**
     * Get monthly payroll summary from approved payroll runs.
     *
     * @param int $months Number of months to look back.
     * @return array
     */
    public static function get_payroll_summary( int $months = 6 ): array {
        global $wpdb;

        $runs       = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $start_date = wp_date( 'Y-m-01', strtotime( "-{$months} months" ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT CONCAT(year, '-', LPAD(month, 2, '0')) AS month,
                    SUM(total_gross) AS total_gross,
                    SUM(total_net) AS total_net,
                    COUNT(*) AS employee_count
             FROM {$runs}
             WHERE status = 'approved'
               AND STR_TO_DATE(CONCAT(year, '-', LPAD(month, 2, '0'), '-01'), '%%Y-%%m-%%d') >= %s
             GROUP BY year, month
             ORDER BY year ASC, month ASC",
            $start_date
        ), ARRAY_A );

        $result = [];
        foreach ( $rows as $row ) {
            $result[] = [
                'month'          => $row['month'],
                'total_gross'    => round( (float) $row['total_gross'], 2 ),
                'total_net'      => round( (float) $row['total_net'], 2 ),
                'employee_count' => (int) $row['employee_count'],
            ];
        }

        return $result;
    }

    /**
     * Get monthly attendance patterns.
     *
     * @param int      $months  Number of months to look back.
     * @param int|null $dept_id Filter by department.
     * @return array
     */
    public static function get_attendance_patterns( int $months = 3, ?int $dept_id = null ): array {
        global $wpdb;

        $sess        = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $emp         = $wpdb->prefix . 'sfs_hr_employees';
        $start_date  = wp_date( 'Y-m-01', strtotime( "-{$months} months" ) );
        $dept_clause = ( $dept_id !== null )
            ? $wpdb->prepare( ' AND e.dept_id = %d', $dept_id )
            : '';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(s.session_date, '%%Y-%%m') AS month,
                    AVG(s.late_minutes) AS avg_late_minutes,
                    SUM(CASE WHEN s.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                    SUM(s.overtime_minutes) AS total_overtime_minutes
             FROM {$sess} s
             INNER JOIN {$emp} e ON e.id = s.employee_id
             WHERE s.session_date >= %s{$dept_clause}
             GROUP BY month
             ORDER BY month ASC",
            $start_date
        ), ARRAY_A );

        $result = [];
        foreach ( $rows as $row ) {
            $result[] = [
                'month'            => $row['month'],
                'avg_late_minutes' => round( (float) $row['avg_late_minutes'], 1 ),
                'absent_count'     => (int) $row['absent_count'],
                'overtime_hours'   => round( (float) $row['total_overtime_minutes'] / 60, 1 ),
            ];
        }

        return $result;
    }

    /**
     * Get workforce composition by nationality and tenure.
     *
     * @param int|null $dept_id Filter by department.
     * @return array
     */
    public static function get_workforce_composition( ?int $dept_id = null ): array {
        global $wpdb;

        $emp         = $wpdb->prefix . 'sfs_hr_employees';
        $where       = "status = 'active'";
        $dept_clause = '';

        if ( $dept_id !== null ) {
            $dept_clause = $wpdb->prepare( ' AND dept_id = %d', $dept_id );
        }

        // By nationality
        $by_nationality = $wpdb->get_results(
            "SELECT nationality, COUNT(*) AS count
             FROM {$emp}
             WHERE {$where}{$dept_clause}
             GROUP BY nationality
             ORDER BY count DESC",
            ARRAY_A
        );

        // By tenure band using TIMESTAMPDIFF
        $tenure_bands = $wpdb->get_results(
            "SELECT
                CASE
                    WHEN TIMESTAMPDIFF(YEAR, hire_date, CURDATE()) < 1  THEN '0-1yr'
                    WHEN TIMESTAMPDIFF(YEAR, hire_date, CURDATE()) < 3  THEN '1-3yr'
                    WHEN TIMESTAMPDIFF(YEAR, hire_date, CURDATE()) < 5  THEN '3-5yr'
                    WHEN TIMESTAMPDIFF(YEAR, hire_date, CURDATE()) < 10 THEN '5-10yr'
                    ELSE '10+yr'
                END AS tenure_band,
                COUNT(*) AS count
             FROM {$emp}
             WHERE {$where}{$dept_clause}
             GROUP BY tenure_band
             ORDER BY FIELD(tenure_band, '0-1yr', '1-3yr', '3-5yr', '5-10yr', '10+yr')",
            ARRAY_A
        );

        // Average tenure in years
        $avg_tenure = (float) $wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(YEAR, hire_date, CURDATE()))
             FROM {$emp}
             WHERE {$where}{$dept_clause}
               AND hire_date IS NOT NULL
               AND hire_date != '0000-00-00'"
        );

        return [
            'by_nationality'   => $by_nationality,
            'by_tenure'        => $tenure_bands,
            'avg_tenure_years' => round( $avg_tenure, 1 ),
        ];
    }

    /**
     * Get aggregate dashboard KPIs.
     *
     * @return array
     */
    public static function get_dashboard_kpis(): array {
        global $wpdb;

        $emp  = $wpdb->prefix . 'sfs_hr_employees';
        $runs = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $sess = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $bal  = $wpdb->prefix . 'sfs_hr_leave_balances';
        $cand = $wpdb->prefix . 'sfs_hr_hiring_candidates';

        $year_start = wp_date( 'Y' ) . '-01-01';

        // Total active employees
        $total_employees = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$emp} WHERE status = 'active'"
        );

        // YTD attrition rate
        $terminated_ytd = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$emp}
             WHERE status = 'terminated' AND updated_at >= %s",
            $year_start
        ) );

        $avg_headcount_ytd = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$emp}
             WHERE hire_date <= %s
               AND (status = 'active' OR (status = 'terminated' AND updated_at >= %s))",
            wp_date( 'Y-m-d' ),
            $year_start
        ) );

        $attrition_rate_ytd = $avg_headcount_ytd > 0
            ? round( ( $terminated_ytd / $avg_headcount_ytd ) * 100, 2 )
            : 0.0;

        // Average leave utilization
        $leave_data = $wpdb->get_row(
            "SELECT SUM(total_days) AS allocated, SUM(used_days) AS used
             FROM {$bal} lb
             INNER JOIN {$emp} e ON e.id = lb.employee_id AND e.status = 'active'"
        );

        $avg_leave_utilization = ( $leave_data && (float) $leave_data->allocated > 0 )
            ? round( ( (float) $leave_data->used / (float) $leave_data->allocated ) * 100, 2 )
            : 0.0;

        // YTD total payroll (approved runs)
        $current_year    = (int) wp_date( 'Y' );
        $total_payroll   = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(total_net), 0) FROM {$runs}
             WHERE status = 'approved' AND year = %d",
            $current_year
        ) );

        // Average attendance percentage (last 30 days)
        $thirty_ago = wp_date( 'Y-m-d', strtotime( '-30 days' ) );

        $att_data = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS total_sessions,
                    SUM(CASE WHEN status IN ('present','late','left_early') THEN 1 ELSE 0 END) AS present_sessions
             FROM {$sess}
             WHERE session_date >= %s",
            $thirty_ago
        ) );

        $avg_attendance_pct = ( $att_data && (int) $att_data->total_sessions > 0 )
            ? round( ( (int) $att_data->present_sessions / (int) $att_data->total_sessions ) * 100, 2 )
            : 0.0;

        // Open positions (candidates with status = 'new' or 'screening')
        $open_positions = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT id) FROM {$cand}
             WHERE status IN ('new', 'screening')"
        );

        return [
            'total_employees'       => $total_employees,
            'attrition_rate_ytd'    => $attrition_rate_ytd,
            'avg_leave_utilization' => $avg_leave_utilization,
            'total_payroll_ytd'     => round( $total_payroll, 2 ),
            'avg_attendance_pct'    => $avg_attendance_pct,
            'open_positions'        => $open_positions,
        ];
    }
}
