<?php
/**
 * Team Attendance Tab — Team attendance summary for managers.
 *
 * Managers see their department's attendance for the current period.
 * HR/GM/Admin see organization-wide with department filtering.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Frontend\Role_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TeamAttendanceTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        $user_id = get_current_user_id();
        $role    = Role_Resolver::resolve( $user_id );
        $level   = Role_Resolver::role_level( $role );

        if ( $level < 30 ) {
            echo '<p>' . esc_html__( 'You do not have permission to view this page.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;

        $sessions_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $emp_table      = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table     = $wpdb->prefix . 'sfs_hr_departments';

        $today      = current_time( 'Y-m-d' );
        $period     = $this->get_current_period();
        $period_start = $period['start'];
        $period_end   = $period['end'];

        // Scope: manager=own depts, HR+=all.
        $is_manager_only = ( $role === 'manager' );
        $dept_ids = [];
        $scope_sql = '';
        $scope_params = [];

        if ( $is_manager_only ) {
            $dept_ids = Role_Resolver::get_manager_dept_ids( $user_id );
            if ( empty( $dept_ids ) ) {
                echo '<div class="sfs-empty-state">';
                echo '<p class="sfs-empty-state-title">' . esc_html__( 'No departments assigned.', 'sfs-hr' ) . '</p>';
                echo '</div>';
                return;
            }
            $placeholders = implode( ',', array_fill( 0, count( $dept_ids ), '%d' ) );
            $scope_sql = "AND e.dept_id IN ({$placeholders})";
            $scope_params = $dept_ids;
        }

        // Optional department filter for HR+.
        $filter_dept = isset( $_GET['att_dept'] ) ? (int) $_GET['att_dept'] : 0;
        if ( $filter_dept > 0 && ! $is_manager_only ) {
            $scope_sql = 'AND e.dept_id = %d';
            $scope_params = [ $filter_dept ];
        }

        // ── Today's Summary ──
        $today_query = "SELECT s.status, COUNT(*) AS cnt
                        FROM {$sessions_table} s
                        JOIN {$emp_table} e ON e.id = s.employee_id
                        WHERE s.work_date = %s {$scope_sql}
                        GROUP BY s.status";
        $today_params = array_merge( [ $today ], $scope_params );
        $today_stats = $wpdb->get_results( $wpdb->prepare( $today_query, ...$today_params ), ARRAY_A );

        $status_counts = [];
        $total_today = 0;
        foreach ( $today_stats as $row ) {
            $status_counts[ $row['status'] ] = (int) $row['cnt'];
            $total_today += (int) $row['cnt'];
        }

        // ── Period Summary ──
        $period_query = "SELECT s.status, COUNT(*) AS cnt
                         FROM {$sessions_table} s
                         JOIN {$emp_table} e ON e.id = s.employee_id
                         WHERE s.work_date BETWEEN %s AND %s {$scope_sql}
                         GROUP BY s.status";
        $period_params = array_merge( [ $period_start, $period_end ], $scope_params );
        $period_stats = $wpdb->get_results( $wpdb->prepare( $period_query, ...$period_params ), ARRAY_A );

        $period_counts = [];
        $period_total = 0;
        foreach ( $period_stats as $row ) {
            $period_counts[ $row['status'] ] = (int) $row['cnt'];
            $period_total += (int) $row['cnt'];
        }

        // ── Employee attendance summary for period ──
        $emp_query = "SELECT e.id, e.first_name, e.last_name, e.employee_code,
                             d.name AS dept_name,
                             SUM(CASE WHEN s.status IN ('present') THEN 1 ELSE 0 END) AS present_days,
                             SUM(CASE WHEN s.status = 'late' THEN 1 ELSE 0 END) AS late_days,
                             SUM(CASE WHEN s.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
                             SUM(CASE WHEN s.status IN ('on_leave','holiday','day_off') THEN 1 ELSE 0 END) AS off_days,
                             ROUND(AVG(s.rounded_net_minutes), 0) AS avg_minutes,
                             COUNT(*) AS total_days
                      FROM {$sessions_table} s
                      JOIN {$emp_table} e ON e.id = s.employee_id
                      LEFT JOIN {$dept_table} d ON d.id = e.dept_id
                      WHERE s.work_date BETWEEN %s AND %s {$scope_sql}
                      GROUP BY e.id, e.first_name, e.last_name, e.employee_code, d.name
                      ORDER BY absent_days DESC, late_days DESC, e.first_name ASC
                      LIMIT 200";
        $emp_params = array_merge( [ $period_start, $period_end ], $scope_params );
        $emp_attendance = $wpdb->get_results( $wpdb->prepare( $emp_query, ...$emp_params ), ARRAY_A );

        // Departments for filter.
        $departments = [];
        if ( ! $is_manager_only ) {
            $departments = $wpdb->get_results(
                "SELECT id, name FROM {$dept_table} WHERE active = 1 ORDER BY name ASC",
                ARRAY_A
            );
        }

        // ── Render ──
        $this->render_header( $period_start, $period_end );
        if ( ! empty( $departments ) ) {
            $this->render_dept_filter( $departments, $filter_dept );
        }
        $this->render_today_kpis( $status_counts );
        $this->render_period_summary( $period_counts, $period_total );
        $this->render_employee_table( $emp_attendance );
    }

    private function render_header( string $start, string $end ): void {
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="team_attendance">' . esc_html__( 'Team Attendance', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-section-subtitle">'
            . esc_html( wp_date( 'M j', strtotime( $start ) ) . ' – ' . wp_date( 'M j, Y', strtotime( $end ) ) )
            . '</p>';
        echo '</div>';
    }

    private function render_dept_filter( array $departments, int $selected ): void {
        echo '<div class="sfs-card" style="margin-bottom:16px;">';
        echo '<div class="sfs-card-body" style="padding:12px 16px;">';
        echo '<form method="get" class="sfs-form-row" style="gap:12px;align-items:flex-end;">';

        // Preserve tab param.
        if ( isset( $_GET['sfs_hr_tab'] ) ) {
            echo '<input type="hidden" name="sfs_hr_tab" value="' . esc_attr( sanitize_key( $_GET['sfs_hr_tab'] ) ) . '" />';
        }

        echo '<div class="sfs-form-group" style="flex:1;min-width:140px;margin:0;">';
        echo '<label class="sfs-form-label" style="font-size:12px;margin-bottom:4px;">' . esc_html__( 'Department', 'sfs-hr' ) . '</label>';
        echo '<select name="att_dept" class="sfs-select" style="padding:8px 10px;font-size:13px;">';
        echo '<option value="0">' . esc_html__( 'All Departments', 'sfs-hr' ) . '</option>';
        foreach ( $departments as $d ) {
            $sel = ( (int) $d['id'] === $selected ) ? ' selected' : '';
            echo '<option value="' . (int) $d['id'] . '"' . $sel . '>' . esc_html( $d['name'] ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="sfs-form-group" style="margin:0;">';
        echo '<button type="submit" class="sfs-btn sfs-btn--primary" style="padding:8px 16px;font-size:13px;">' . esc_html__( 'Filter', 'sfs-hr' ) . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div></div>';
    }

    private function render_today_kpis( array $counts ): void {
        $present  = ( $counts['present'] ?? 0 );
        $late     = ( $counts['late'] ?? 0 );
        $absent   = ( $counts['absent'] ?? 0 );
        $on_leave = ( $counts['on_leave'] ?? 0 ) + ( $counts['holiday'] ?? 0 );

        echo '<div class="sfs-section" style="margin-bottom:8px;">';
        echo '<h3 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:0;" data-i18n-key="today_snap">'
            . esc_html__( "Today's Snapshot", 'sfs-hr' ) . '</h3>';
        echo '</div>';

        echo '<div class="sfs-kpi-grid sfs-kpi-grid--4">';

        echo '<div class="sfs-kpi-card"><div class="sfs-kpi-icon" style="background:#ecfdf5;"><svg viewBox="0 0 24 24" stroke="#059669" fill="none" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>';
        echo '<div class="sfs-kpi-label">' . esc_html__( 'Present', 'sfs-hr' ) . '</div><div class="sfs-kpi-value">' . $present . '</div></div>';

        echo '<div class="sfs-kpi-card"><div class="sfs-kpi-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" stroke="#d97706" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>';
        echo '<div class="sfs-kpi-label">' . esc_html__( 'Late', 'sfs-hr' ) . '</div><div class="sfs-kpi-value">' . $late . '</div></div>';

        echo '<div class="sfs-kpi-card"><div class="sfs-kpi-icon" style="background:#fef2f2;"><svg viewBox="0 0 24 24" stroke="#dc2626" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>';
        echo '<div class="sfs-kpi-label">' . esc_html__( 'Absent', 'sfs-hr' ) . '</div><div class="sfs-kpi-value">' . $absent . '</div></div>';

        echo '<div class="sfs-kpi-card"><div class="sfs-kpi-icon" style="background:#eff6ff;"><svg viewBox="0 0 24 24" stroke="#3b82f6" fill="none" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>';
        echo '<div class="sfs-kpi-label">' . esc_html__( 'On Leave', 'sfs-hr' ) . '</div><div class="sfs-kpi-value">' . $on_leave . '</div></div>';

        echo '</div>';
    }

    private function render_period_summary( array $counts, int $total ): void {
        if ( $total === 0 ) return;

        $present  = ( $counts['present'] ?? 0 ) + ( $counts['late'] ?? 0 ) + ( $counts['left_early'] ?? 0 );
        $absent   = ( $counts['absent'] ?? 0 );
        $on_leave = ( $counts['on_leave'] ?? 0 );
        $att_rate = $total > 0 ? round( ( $present / $total ) * 100 ) : 0;

        echo '<div class="sfs-card" style="margin-bottom:16px;">';
        echo '<div class="sfs-card-body">';
        echo '<h3 class="sfs-dash-widget-title" data-i18n-key="period_summary">' . esc_html__( 'Period Summary', 'sfs-hr' ) . '</h3>';

        echo '<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:12px;">';

        echo '<div style="flex:1;min-width:120px;text-align:center;">';
        echo '<div style="font-size:28px;font-weight:800;color:var(--sfs-primary);">' . $att_rate . '%</div>';
        echo '<div style="font-size:12px;color:var(--sfs-text-muted);margin-top:2px;">' . esc_html__( 'Attendance Rate', 'sfs-hr' ) . '</div>';
        echo '</div>';

        echo '<div style="flex:1;min-width:120px;text-align:center;">';
        echo '<div style="font-size:28px;font-weight:800;color:#059669;">' . $present . '</div>';
        echo '<div style="font-size:12px;color:var(--sfs-text-muted);margin-top:2px;">' . esc_html__( 'Present Days', 'sfs-hr' ) . '</div>';
        echo '</div>';

        echo '<div style="flex:1;min-width:120px;text-align:center;">';
        echo '<div style="font-size:28px;font-weight:800;color:#dc2626;">' . $absent . '</div>';
        echo '<div style="font-size:12px;color:var(--sfs-text-muted);margin-top:2px;">' . esc_html__( 'Absent Days', 'sfs-hr' ) . '</div>';
        echo '</div>';

        echo '<div style="flex:1;min-width:120px;text-align:center;">';
        echo '<div style="font-size:28px;font-weight:800;color:#3b82f6;">' . $on_leave . '</div>';
        echo '<div style="font-size:12px;color:var(--sfs-text-muted);margin-top:2px;">' . esc_html__( 'Leave Days', 'sfs-hr' ) . '</div>';
        echo '</div>';

        echo '</div>';
        echo '</div></div>';
    }

    private function render_employee_table( array $employees ): void {
        if ( empty( $employees ) ) {
            echo '<div class="sfs-card"><div class="sfs-empty-state">';
            echo '<p class="sfs-empty-state-title">' . esc_html__( 'No attendance data for this period.', 'sfs-hr' ) . '</p>';
            echo '</div></div>';
            return;
        }

        echo '<div class="sfs-card">';
        echo '<div class="sfs-card-body">';
        echo '<h3 class="sfs-dash-widget-title" data-i18n-key="employee_breakdown">' . esc_html__( 'Employee Breakdown', 'sfs-hr' ) . '</h3>';

        // Desktop table.
        echo '<div class="sfs-desktop-only"><table class="sfs-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Employee', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Department', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Present', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Late', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Absent', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Off', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Avg Hours', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Rate', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $employees as $e ) {
            $name     = esc_html( trim( ( $e['first_name'] ?? '' ) . ' ' . ( $e['last_name'] ?? '' ) ) );
            $code     = esc_html( $e['employee_code'] ?? '' );
            $dept     = esc_html( $e['dept_name'] ?? '—' );
            $present  = (int) $e['present_days'];
            $late     = (int) $e['late_days'];
            $absent   = (int) $e['absent_days'];
            $off      = (int) $e['off_days'];
            $avg_hrs  = $e['avg_minutes'] ? round( (int) $e['avg_minutes'] / 60, 1 ) : 0;
            $total    = (int) $e['total_days'];
            $worked   = $present + $late;
            $rate     = $total > 0 ? round( ( $worked / $total ) * 100 ) : 0;
            $rate_color = $rate >= 80 ? '#059669' : ( $rate >= 60 ? '#d97706' : '#dc2626' );

            echo '<tr>';
            echo '<td><strong>' . $name . '</strong><br><small style="color:var(--sfs-text-muted);">' . $code . '</small></td>';
            echo '<td>' . $dept . '</td>';
            echo '<td style="color:#059669;font-weight:600;">' . $present . '</td>';
            echo '<td style="color:#d97706;font-weight:600;">' . $late . '</td>';
            echo '<td style="color:#dc2626;font-weight:600;">' . $absent . '</td>';
            echo '<td style="color:var(--sfs-text-muted);">' . $off . '</td>';
            echo '<td>' . $avg_hrs . 'h</td>';
            echo '<td><span style="color:' . esc_attr( $rate_color ) . ';font-weight:700;">' . $rate . '%</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        // Mobile cards.
        echo '<div class="sfs-mobile-only sfs-team-att-list">';
        foreach ( $employees as $e ) {
            $name     = esc_html( trim( ( $e['first_name'] ?? '' ) . ' ' . ( $e['last_name'] ?? '' ) ) );
            $code     = esc_html( $e['employee_code'] ?? '' );
            $present  = (int) $e['present_days'];
            $late     = (int) $e['late_days'];
            $absent   = (int) $e['absent_days'];
            $total    = (int) $e['total_days'];
            $worked   = $present + $late;
            $rate     = $total > 0 ? round( ( $worked / $total ) * 100 ) : 0;
            $rate_color = $rate >= 80 ? '#059669' : ( $rate >= 60 ? '#d97706' : '#dc2626' );

            echo '<div class="sfs-card" style="margin-bottom:8px;">';
            echo '<div class="sfs-card-body" style="padding:12px 16px;">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;">';
            echo '<div><strong>' . $name . '</strong><br><small style="color:var(--sfs-text-muted);">' . $code . '</small></div>';
            echo '<span style="font-size:18px;font-weight:800;color:' . esc_attr( $rate_color ) . ';">' . $rate . '%</span>';
            echo '</div>';
            echo '<div style="display:flex;gap:16px;margin-top:8px;font-size:12px;">';
            echo '<span style="color:#059669;">P: ' . $present . '</span>';
            echo '<span style="color:#d97706;">L: ' . $late . '</span>';
            echo '<span style="color:#dc2626;">A: ' . $absent . '</span>';
            echo '</div>';
            echo '</div></div>';
        }
        echo '</div>';

        echo '</div></div>';
    }

    private function get_current_period(): array {
        if ( class_exists( '\SFS\HR\Modules\Attendance\AttendanceModule' ) &&
             method_exists( '\SFS\HR\Modules\Attendance\AttendanceModule', 'get_current_period' ) ) {
            $period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
            if ( is_array( $period ) && ! empty( $period['start'] ) ) {
                return $period;
            }
        }
        return [
            'start' => date( 'Y-m-01' ),
            'end'   => date( 'Y-m-t' ),
        ];
    }
}
