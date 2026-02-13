<?php
/**
 * Dashboard Tab — Organization attendance dashboard with §10.3 widgets.
 *
 * Displays attendance KPIs, clock-in method breakdown, calendar heatmap,
 * and employee status list. Accessible to HR, GM, and Admin roles.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Frontend\Role_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DashboardTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        $user_id = get_current_user_id();
        $role    = Role_Resolver::resolve( $user_id );
        $level   = Role_Resolver::role_level( $role );

        if ( $level < 40 ) { // hr=40, gm=50, admin=60
            echo '<p>' . esc_html__( 'You do not have permission to view this page.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;

        $sessions_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $punches_table  = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $emp_table      = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table     = $wpdb->prefix . 'sfs_hr_departments';

        $today      = current_time( 'Y-m-d' );
        $period     = $this->get_current_period();
        $period_start = $period['start'];
        $period_end   = $period['end'];

        // Total active employees.
        $total_employees = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$emp_table} WHERE status = 'active'"
        );

        // ── Today's sessions ──
        $today_stats = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS cnt
             FROM {$sessions_table}
             WHERE work_date = %s
             GROUP BY status",
            $today
        ), ARRAY_A );

        $today_map = [];
        $today_total_sessions = 0;
        foreach ( $today_stats as $row ) {
            $today_map[ $row['status'] ] = (int) $row['cnt'];
            $today_total_sessions += (int) $row['cnt'];
        }

        $present    = ( $today_map['present'] ?? 0 );
        $late       = ( $today_map['late'] ?? 0 );
        $absent     = ( $today_map['absent'] ?? 0 );
        $on_leave   = ( $today_map['on_leave'] ?? 0 );
        $left_early = ( $today_map['left_early'] ?? 0 );
        $incomplete = ( $today_map['incomplete'] ?? 0 );
        $holiday    = ( $today_map['holiday'] ?? 0 );
        $day_off    = ( $today_map['day_off'] ?? 0 );
        $clocked_in = $present + $late + $left_early + $incomplete;
        $on_time    = $present;
        $not_clocked = max( 0, $total_employees - $clocked_in - $absent - $on_leave - $holiday - $day_off );

        // ── Clock-in method breakdown (today) ──
        $methods = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                CASE
                    WHEN source = 'kiosk' THEN 'kiosk'
                    WHEN source = 'self_mobile' THEN 'mobile'
                    WHEN source = 'self_web' THEN 'web'
                    WHEN source = 'manager_adjust' THEN 'manual'
                    ELSE 'other'
                END AS method,
                COUNT(DISTINCT employee_id) AS cnt
             FROM {$punches_table}
             WHERE punch_type = 'in'
               AND DATE(punch_time) = %s
             GROUP BY method",
            $today
        ), ARRAY_A );

        $method_map = [ 'kiosk' => 0, 'mobile' => 0, 'web' => 0, 'manual' => 0, 'other' => 0 ];
        foreach ( $methods as $m ) {
            $method_map[ $m['method'] ] = (int) $m['cnt'];
        }

        // ── Calendar heatmap (current period) ──
        $heatmap = $wpdb->get_results( $wpdb->prepare(
            "SELECT work_date,
                    SUM(CASE WHEN status IN ('present') THEN 1 ELSE 0 END) AS present_cnt,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) AS late_cnt,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent_cnt,
                    SUM(CASE WHEN status IN ('on_leave','holiday','day_off') THEN 1 ELSE 0 END) AS off_cnt,
                    COUNT(*) AS total_cnt
             FROM {$sessions_table}
             WHERE work_date BETWEEN %s AND %s
             GROUP BY work_date
             ORDER BY work_date ASC",
            $period_start,
            $period_end
        ), ARRAY_A );

        $heatmap_data = [];
        foreach ( $heatmap as $h ) {
            $total = max( 1, (int) $h['total_cnt'] );
            $present_pct = round( ( (int) $h['present_cnt'] / $total ) * 100 );
            $late_pct    = round( ( (int) $h['late_cnt'] / $total ) * 100 );
            $absent_pct  = round( ( (int) $h['absent_cnt'] / $total ) * 100 );
            $heatmap_data[ $h['work_date'] ] = [
                'present' => (int) $h['present_cnt'],
                'late'    => (int) $h['late_cnt'],
                'absent'  => (int) $h['absent_cnt'],
                'off'     => (int) $h['off_cnt'],
                'total'   => (int) $h['total_cnt'],
                'pct'     => $present_pct + $late_pct, // attendance rate
            ];
        }

        // ── Department stats ──
        $dept_stats = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.name AS dept_name, d.id AS dept_id,
                    SUM(CASE WHEN s.status IN ('present','late','left_early','incomplete') THEN 1 ELSE 0 END) AS attended,
                    SUM(CASE WHEN s.status = 'absent' THEN 1 ELSE 0 END) AS absent_cnt,
                    COUNT(*) AS total_cnt
             FROM {$sessions_table} s
             JOIN {$emp_table} e ON e.id = s.employee_id
             JOIN {$dept_table} d ON d.id = e.dept_id
             WHERE s.work_date = %s AND d.active = 1
             GROUP BY d.id, d.name
             ORDER BY d.name ASC",
            $today
        ), ARRAY_A );

        // ── Today's employee list ──
        $today_list = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.employee_id, s.status, s.in_time, s.out_time, s.rounded_net_minutes,
                    e.first_name, e.last_name, e.employee_code, d.name AS dept_name
             FROM {$sessions_table} s
             JOIN {$emp_table} e ON e.id = s.employee_id
             LEFT JOIN {$dept_table} d ON d.id = e.dept_id
             WHERE s.work_date = %s
             ORDER BY
                CASE s.status
                    WHEN 'absent' THEN 1
                    WHEN 'incomplete' THEN 2
                    WHEN 'late' THEN 3
                    WHEN 'left_early' THEN 4
                    WHEN 'present' THEN 5
                    ELSE 6
                END ASC,
                e.first_name ASC
             LIMIT 100",
            $today
        ), ARRAY_A );

        // ── Render ──
        $this->render_header( $today, $period_start, $period_end, $total_employees );
        $this->render_summary_counters( $clocked_in, $not_clocked, $on_leave + $holiday, $absent, $total_employees );
        $this->render_gauge( $on_time, $late, $clocked_in, $total_employees );
        $this->render_method_breakdown( $method_map );
        $this->render_dept_breakdown( $dept_stats );
        $this->render_calendar_heatmap( $heatmap_data, $period_start, $period_end );
        $this->render_today_list( $today_list );
    }

    /* ──────────────────────────────────────────────────────────
       Header
    ────────────────────────────────────────────────────────── */
    private function render_header( string $today, string $start, string $end, int $total ): void {
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="attendance_dashboard">' . esc_html__( 'Attendance Dashboard', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-section-subtitle">'
            . esc_html( wp_date( 'l, F j, Y', strtotime( $today ) ) )
            . ' &middot; '
            . esc_html( sprintf( __( '%d active employees', 'sfs-hr' ), $total ) )
            . '</p>';
        echo '</div>';
    }

    /* ──────────────────────────────────────────────────────────
       §10.3 — Clocked In / Not Clocked In Counters
    ────────────────────────────────────────────────────────── */
    private function render_summary_counters( int $clocked, int $not_clocked, int $off, int $absent, int $total ): void {
        echo '<div class="sfs-kpi-grid sfs-kpi-grid--4">';

        $this->kpi_card( 'clocked_in', __( 'Clocked In', 'sfs-hr' ), (string) $clocked,
            '#ecfdf5', '#059669',
            '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'
        );

        $this->kpi_card( 'not_clocked', __( 'Not Clocked In', 'sfs-hr' ), (string) $not_clocked,
            '#fef2f2', '#dc2626',
            '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>'
        );

        $this->kpi_card( 'on_leave_holiday', __( 'On Leave / Holiday', 'sfs-hr' ), (string) $off,
            '#eff6ff', '#3b82f6',
            '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'
        );

        $this->kpi_card( 'absent', __( 'Absent', 'sfs-hr' ), (string) $absent,
            '#fef3c7', '#d97706',
            '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>'
        );

        echo '</div>';
    }

    private function kpi_card( string $key, string $label, string $value, string $bg, string $color, string $svg_path ): void {
        echo '<div class="sfs-kpi-card" data-filter="' . esc_attr( $key ) . '">';
        echo '<div class="sfs-kpi-icon" style="background:' . esc_attr( $bg ) . ';"><svg viewBox="0 0 24 24" stroke="' . esc_attr( $color ) . '" fill="none" stroke-width="2">' . $svg_path . '</svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</div>';
        echo '<div class="sfs-kpi-value">' . esc_html( $value ) . '</div>';
        echo '</div>';
    }

    /* ──────────────────────────────────────────────────────────
       §10.3 — Today's Attendance Gauge
    ────────────────────────────────────────────────────────── */
    private function render_gauge( int $on_time, int $late, int $clocked, int $total ): void {
        $attendance_pct = $total > 0 ? round( ( $clocked / $total ) * 100 ) : 0;
        $on_time_pct    = $clocked > 0 ? round( ( $on_time / $clocked ) * 100 ) : 0;

        // SVG gauge arc — semicircle (180°), radius 80, cx/cy 100/100
        $circ       = 251.33; // π × 80
        $fill       = $circ * $attendance_pct / 100;
        $on_time_fill = $circ * ( $total > 0 ? $on_time / $total : 0 );
        $late_fill    = $circ * ( $total > 0 ? $late / $total : 0 );

        echo '<div class="sfs-card sfs-dash-gauge-card">';
        echo '<div class="sfs-card-body">';
        echo '<h3 class="sfs-dash-widget-title" data-i18n-key="todays_attendance">' . esc_html__( "Today's Attendance", 'sfs-hr' ) . '</h3>';

        echo '<div class="sfs-dash-gauge-wrap">';
        echo '<svg class="sfs-dash-gauge" viewBox="0 0 200 120">';
        // Background arc.
        echo '<path class="sfs-dash-gauge-bg" d="M 20,100 A 80,80 0 0,1 180,100" />';
        // Filled arc (on-time = green).
        if ( $on_time > 0 && $total > 0 ) {
            $on_time_dash = $circ * ( $on_time / $total );
            echo '<path class="sfs-dash-gauge-fill sfs-dash-gauge-fill--ontime" d="M 20,100 A 80,80 0 0,1 180,100" stroke-dasharray="' . round( $on_time_dash, 2 ) . ' ' . round( $circ, 2 ) . '" />';
        }
        // Late arc (amber, after on-time).
        if ( $late > 0 && $total > 0 ) {
            $late_dash = $circ * ( $late / $total );
            $late_offset = $circ * ( $on_time / $total );
            echo '<path class="sfs-dash-gauge-fill sfs-dash-gauge-fill--late" d="M 20,100 A 80,80 0 0,1 180,100" stroke-dasharray="' . round( $late_dash, 2 ) . ' ' . round( $circ, 2 ) . '" stroke-dashoffset="-' . round( $late_offset, 2 ) . '" />';
        }
        echo '</svg>';

        // Center label.
        echo '<div class="sfs-dash-gauge-center">';
        echo '<div class="sfs-dash-gauge-pct">' . $attendance_pct . '%</div>';
        echo '<div class="sfs-dash-gauge-sub" data-i18n-key="attendance_rate">' . esc_html__( 'Attendance', 'sfs-hr' ) . '</div>';
        echo '</div>';
        echo '</div>'; // .sfs-dash-gauge-wrap

        // Legend.
        echo '<div class="sfs-dash-gauge-legend">';
        echo '<span class="sfs-dash-legend-item"><span class="sfs-dash-legend-dot" style="background:#059669;"></span> '
            . esc_html__( 'On Time', 'sfs-hr' ) . ' <strong>' . $on_time . '</strong></span>';
        echo '<span class="sfs-dash-legend-item"><span class="sfs-dash-legend-dot" style="background:#d97706;"></span> '
            . esc_html__( 'Late', 'sfs-hr' ) . ' <strong>' . $late . '</strong></span>';
        echo '</div>';

        echo '</div></div>'; // .sfs-card-body, .sfs-card
    }

    /* ──────────────────────────────────────────────────────────
       §10.3 — Clock-In Method Breakdown
    ────────────────────────────────────────────────────────── */
    private function render_method_breakdown( array $method_map ): void {
        echo '<div class="sfs-card sfs-dash-methods-card">';
        echo '<div class="sfs-card-body">';
        echo '<h3 class="sfs-dash-widget-title" data-i18n-key="clock_in_methods">' . esc_html__( 'Clock-In Methods', 'sfs-hr' ) . '</h3>';

        echo '<div class="sfs-dash-methods-grid">';

        $icons = [
            'kiosk'  => [ __( 'Kiosk', 'sfs-hr' ), '#6366f1', '<rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>' ],
            'mobile' => [ __( 'Mobile', 'sfs-hr' ), '#059669', '<rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>' ],
            'web'    => [ __( 'Web', 'sfs-hr' ), '#3b82f6', '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>' ],
            'manual' => [ __( 'Manual', 'sfs-hr' ), '#f59e0b', '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>' ],
        ];

        foreach ( $icons as $key => [ $label, $color, $svg ] ) {
            $count = $method_map[ $key ] ?? 0;
            echo '<div class="sfs-dash-method-item">';
            echo '<div class="sfs-dash-method-icon" style="background:' . esc_attr( $color ) . '20;"><svg viewBox="0 0 24 24" stroke="' . esc_attr( $color ) . '" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $svg . '</svg></div>';
            echo '<div class="sfs-dash-method-count">' . $count . '</div>';
            echo '<div class="sfs-dash-method-label">' . esc_html( $label ) . '</div>';
            echo '</div>';
        }

        echo '</div>'; // .sfs-dash-methods-grid
        echo '</div></div>';
    }

    /* ──────────────────────────────────────────────────────────
       Department Breakdown
    ────────────────────────────────────────────────────────── */
    private function render_dept_breakdown( array $dept_stats ): void {
        if ( empty( $dept_stats ) ) {
            return;
        }

        echo '<div class="sfs-card sfs-dash-dept-card">';
        echo '<div class="sfs-card-body">';
        echo '<h3 class="sfs-dash-widget-title" data-i18n-key="department_attendance">' . esc_html__( 'Department Attendance', 'sfs-hr' ) . '</h3>';

        echo '<div class="sfs-dash-dept-list">';
        foreach ( $dept_stats as $d ) {
            $total    = max( 1, (int) $d['total_cnt'] );
            $attended = (int) $d['attended'];
            $pct      = round( ( $attended / $total ) * 100 );
            $bar_color = $pct >= 80 ? '#059669' : ( $pct >= 60 ? '#d97706' : '#dc2626' );

            echo '<div class="sfs-dash-dept-row">';
            echo '<div class="sfs-dash-dept-name">' . esc_html( $d['dept_name'] ) . '</div>';
            echo '<div class="sfs-dash-dept-bar-wrap">';
            echo '<div class="sfs-dash-dept-bar" style="width:' . $pct . '%;background:' . esc_attr( $bar_color ) . ';"></div>';
            echo '</div>';
            echo '<div class="sfs-dash-dept-pct">' . $pct . '%</div>';
            echo '<div class="sfs-dash-dept-count">' . $attended . '/' . $total . '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '</div></div>';
    }

    /* ──────────────────────────────────────────────────────────
       §10.3 — Calendar Heatmap
    ────────────────────────────────────────────────────────── */
    private function render_calendar_heatmap( array $data, string $period_start, string $period_end ): void {
        echo '<div class="sfs-card sfs-dash-heatmap-card">';
        echo '<div class="sfs-card-body">';
        echo '<h3 class="sfs-dash-widget-title" data-i18n-key="attendance_calendar">' . esc_html__( 'Attendance Calendar', 'sfs-hr' ) . '</h3>';

        // Legend.
        echo '<div class="sfs-dash-heatmap-legend">';
        echo '<span class="sfs-dash-legend-item"><span class="sfs-dash-legend-dot" style="background:#059669;"></span> ' . esc_html__( 'High', 'sfs-hr' ) . '</span>';
        echo '<span class="sfs-dash-legend-item"><span class="sfs-dash-legend-dot" style="background:#fbbf24;"></span> ' . esc_html__( 'Medium', 'sfs-hr' ) . '</span>';
        echo '<span class="sfs-dash-legend-item"><span class="sfs-dash-legend-dot" style="background:#f87171;"></span> ' . esc_html__( 'Low', 'sfs-hr' ) . '</span>';
        echo '<span class="sfs-dash-legend-item"><span class="sfs-dash-legend-dot" style="background:#e5e7eb;"></span> ' . esc_html__( 'No Data', 'sfs-hr' ) . '</span>';
        echo '</div>';

        echo '<div class="sfs-dash-heatmap-grid">';

        // Day-of-week headers.
        $day_names = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
        foreach ( $day_names as $dn ) {
            echo '<div class="sfs-dash-heatmap-header">' . esc_html( $dn ) . '</div>';
        }

        // Calculate start padding (empty cells before period starts).
        $start_ts    = strtotime( $period_start );
        $end_ts      = strtotime( $period_end );
        $start_dow   = (int) date( 'w', $start_ts );
        $today       = current_time( 'Y-m-d' );

        // Empty cells for alignment.
        for ( $i = 0; $i < $start_dow; $i++ ) {
            echo '<div class="sfs-dash-heatmap-cell sfs-dash-heatmap-cell--empty"></div>';
        }

        // Day cells.
        $current = $start_ts;
        while ( $current <= $end_ts ) {
            $date_str = date( 'Y-m-d', $current );
            $day_num  = (int) date( 'j', $current );
            $is_future = $date_str > $today;

            if ( $is_future ) {
                $color_class = 'future';
                $tooltip = '';
            } elseif ( isset( $data[ $date_str ] ) ) {
                $d = $data[ $date_str ];
                $pct = $d['pct'];
                if ( $pct >= 80 ) {
                    $color_class = 'high';
                } elseif ( $pct >= 60 ) {
                    $color_class = 'medium';
                } elseif ( $pct > 0 ) {
                    $color_class = 'low';
                } else {
                    $color_class = 'none';
                }
                $tooltip = sprintf( '%s: %d%% (%dP, %dL, %dA)',
                    wp_date( 'M j', $current ),
                    $pct,
                    $d['present'],
                    $d['late'],
                    $d['absent']
                );
            } else {
                $color_class = 'none';
                $tooltip = '';
            }

            $is_today = ( $date_str === $today );
            $today_class = $is_today ? ' sfs-dash-heatmap-cell--today' : '';

            echo '<div class="sfs-dash-heatmap-cell sfs-dash-heatmap-cell--' . esc_attr( $color_class ) . $today_class . '"'
                . ( $tooltip ? ' title="' . esc_attr( $tooltip ) . '"' : '' )
                . '>' . $day_num . '</div>';

            $current = strtotime( '+1 day', $current );
        }

        echo '</div>'; // .sfs-dash-heatmap-grid
        echo '</div></div>';
    }

    /* ──────────────────────────────────────────────────────────
       §10.3 — Today's Employee List (Drill Down)
    ────────────────────────────────────────────────────────── */
    private function render_today_list( array $list ): void {
        echo '<div class="sfs-card sfs-dash-list-card">';
        echo '<div class="sfs-card-body">';
        echo '<h3 class="sfs-dash-widget-title" data-i18n-key="todays_employee_status">' . esc_html__( "Today's Employee Status", 'sfs-hr' ) . '</h3>';

        if ( empty( $list ) ) {
            echo '<div class="sfs-empty-state">';
            echo '<p class="sfs-empty-state-title">' . esc_html__( 'No attendance records for today yet.', 'sfs-hr' ) . '</p>';
            echo '</div>';
            echo '</div></div>';
            return;
        }

        // Status filter chips.
        echo '<div class="sfs-dash-filter-chips" id="sfs-dash-status-filters">';
        echo '<button type="button" class="sfs-chip sfs-chip--active" data-status="all">' . esc_html__( 'All', 'sfs-hr' ) . '</button>';
        echo '<button type="button" class="sfs-chip" data-status="present">' . esc_html__( 'Present', 'sfs-hr' ) . '</button>';
        echo '<button type="button" class="sfs-chip" data-status="late">' . esc_html__( 'Late', 'sfs-hr' ) . '</button>';
        echo '<button type="button" class="sfs-chip" data-status="absent">' . esc_html__( 'Absent', 'sfs-hr' ) . '</button>';
        echo '<button type="button" class="sfs-chip" data-status="incomplete">' . esc_html__( 'Incomplete', 'sfs-hr' ) . '</button>';
        echo '<button type="button" class="sfs-chip" data-status="on_leave">' . esc_html__( 'On Leave', 'sfs-hr' ) . '</button>';
        echo '</div>';

        // Desktop table.
        echo '<div class="sfs-desktop-only"><table class="sfs-table" id="sfs-dash-emp-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Employee', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Department', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Clock In', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Clock Out', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Hours', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $list as $row ) {
            $name  = esc_html( trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) );
            $code  = esc_html( $row['employee_code'] ?? '' );
            $dept  = esc_html( $row['dept_name'] ?? '—' );
            $in    = $row['in_time'] ? esc_html( wp_date( 'g:i A', strtotime( $row['in_time'] ) ) ) : '—';
            $out   = $row['out_time'] ? esc_html( wp_date( 'g:i A', strtotime( $row['out_time'] ) ) ) : '—';
            $hours = $row['rounded_net_minutes'] ? round( (int) $row['rounded_net_minutes'] / 60, 1 ) . 'h' : '—';
            $badge = $this->status_badge( $row['status'] ?? '' );

            echo '<tr data-status="' . esc_attr( $row['status'] ?? '' ) . '">';
            echo '<td><strong>' . $name . '</strong><br><small style="color:var(--sfs-text-muted);">' . $code . '</small></td>';
            echo '<td>' . $dept . '</td>';
            echo '<td>' . $badge . '</td>';
            echo '<td>' . $in . '</td>';
            echo '<td>' . $out . '</td>';
            echo '<td>' . esc_html( $hours ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        // Mobile cards.
        echo '<div class="sfs-mobile-only sfs-dash-emp-list" id="sfs-dash-emp-mobile">';
        foreach ( $list as $row ) {
            $name  = esc_html( trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ) );
            $code  = esc_html( $row['employee_code'] ?? '' );
            $dept  = esc_html( $row['dept_name'] ?? '—' );
            $in    = $row['in_time'] ? esc_html( wp_date( 'g:i A', strtotime( $row['in_time'] ) ) ) : '—';
            $out   = $row['out_time'] ? esc_html( wp_date( 'g:i A', strtotime( $row['out_time'] ) ) ) : '—';
            $hours = $row['rounded_net_minutes'] ? round( (int) $row['rounded_net_minutes'] / 60, 1 ) . 'h' : '—';
            $badge = $this->status_badge( $row['status'] ?? '' );

            echo '<div class="sfs-dash-emp-item" data-status="' . esc_attr( $row['status'] ?? '' ) . '">';
            echo '<div class="sfs-dash-emp-item-head">';
            echo '<div><strong>' . $name . '</strong><br><small style="color:var(--sfs-text-muted);">' . $code . ' &middot; ' . $dept . '</small></div>';
            echo $badge;
            echo '</div>';
            echo '<div class="sfs-dash-emp-item-meta">';
            echo '<span>' . esc_html__( 'In', 'sfs-hr' ) . ': ' . $in . '</span>';
            echo '<span>' . esc_html__( 'Out', 'sfs-hr' ) . ': ' . $out . '</span>';
            echo '<span>' . $hours . '</span>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        // Filter JS.
        echo '<script>document.addEventListener("DOMContentLoaded",function(){';
        echo 'var fc=document.getElementById("sfs-dash-status-filters");';
        echo 'if(!fc)return;';
        echo 'fc.addEventListener("click",function(e){';
        echo 'var b=e.target.closest("[data-status]");if(!b)return;';
        echo 'fc.querySelectorAll(".sfs-chip").forEach(function(c){c.classList.remove("sfs-chip--active");});';
        echo 'b.classList.add("sfs-chip--active");';
        echo 'var s=b.dataset.status;';
        echo 'document.querySelectorAll("#sfs-dash-emp-table tbody tr,#sfs-dash-emp-mobile .sfs-dash-emp-item").forEach(function(r){';
        echo 'r.style.display=(s==="all"||r.dataset.status===s)?"":"none";';
        echo '});});});</script>';

        echo '</div></div>';
    }

    /* ──────────────────────────────────────────────────────────
       Helpers
    ────────────────────────────────────────────────────────── */
    private function status_badge( string $status ): string {
        $map = [
            'present'    => [ 'approved', __( 'Present', 'sfs-hr' ) ],
            'late'       => [ 'pending',  __( 'Late', 'sfs-hr' ) ],
            'left_early' => [ 'pending',  __( 'Left Early', 'sfs-hr' ) ],
            'absent'     => [ 'rejected', __( 'Absent', 'sfs-hr' ) ],
            'incomplete' => [ 'rejected', __( 'Incomplete', 'sfs-hr' ) ],
            'on_leave'   => [ 'info',     __( 'On Leave', 'sfs-hr' ) ],
            'holiday'    => [ 'info',     __( 'Holiday', 'sfs-hr' ) ],
            'day_off'    => [ 'neutral',  __( 'Day Off', 'sfs-hr' ) ],
        ];

        $badge = $map[ $status ] ?? [ 'neutral', ucfirst( str_replace( '_', ' ', $status ) ) ];
        return '<span class="sfs-badge sfs-badge--' . esc_attr( $badge[0] ) . '">' . esc_html( $badge[1] ) . '</span>';
    }

    private function get_current_period(): array {
        if ( class_exists( '\SFS\HR\Modules\Attendance\AttendanceModule' ) &&
             method_exists( '\SFS\HR\Modules\Attendance\AttendanceModule', 'get_current_period' ) ) {
            $period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
            if ( is_array( $period ) && ! empty( $period['start'] ) ) {
                return $period;
            }
        }
        // Fallback: calendar month.
        return [
            'start' => date( 'Y-m-01' ),
            'end'   => date( 'Y-m-t' ),
        ];
    }
}
