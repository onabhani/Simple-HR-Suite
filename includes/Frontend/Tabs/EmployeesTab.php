<?php
/**
 * Employees Tab — Employee directory for HR/GM/Admin.
 *
 * Full employee list with search and filter capabilities.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Frontend\Role_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EmployeesTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        $user_id = get_current_user_id();
        $role    = Role_Resolver::resolve( $user_id );
        $level   = Role_Resolver::role_level( $role );

        if ( $level < 40 ) { // hr=40
            echo '<p>' . esc_html__( 'You do not have permission to view this page.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        // Filters.
        $search        = isset( $_GET['emp_search'] ) ? sanitize_text_field( $_GET['emp_search'] ) : '';
        $filter_dept   = isset( $_GET['emp_dept'] ) ? (int) $_GET['emp_dept'] : 0;
        $filter_status = isset( $_GET['emp_status'] ) ? sanitize_key( $_GET['emp_status'] ) : 'active';
        $page_num      = max( 1, isset( $_GET['emp_page'] ) ? (int) $_GET['emp_page'] : 1 );
        $per_page      = 50;
        $offset        = ( $page_num - 1 ) * $per_page;

        // Build query.
        $where  = [ '1=1' ];
        $params = [];

        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(e.first_name LIKE %s OR e.last_name LIKE %s OR e.employee_code LIKE %s OR e.email LIKE %s)';
            $params = array_merge( $params, [ $like, $like, $like, $like ] );
        }

        if ( $filter_dept > 0 ) {
            $where[] = 'e.dept_id = %d';
            $params[] = $filter_dept;
        }

        if ( $filter_status && $filter_status !== 'all' ) {
            $where[] = 'e.status = %s';
            $params[] = $filter_status;
        }

        $where_sql = implode( ' AND ', $where );

        // Count.
        $count_query = "SELECT COUNT(*) FROM {$emp_table} e WHERE {$where_sql}";
        if ( ! empty( $params ) ) {
            $total_count = (int) $wpdb->get_var( $wpdb->prepare( $count_query, ...$params ) );
        } else {
            $total_count = (int) $wpdb->get_var( $count_query );
        }

        $total_pages = max( 1, ceil( $total_count / $per_page ) );

        // Fetch.
        $query = "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.status,
                         e.position, e.phone, e.email, e.hire_date, e.nationality,
                         d.name AS dept_name
                  FROM {$emp_table} e
                  LEFT JOIN {$dept_table} d ON d.id = e.dept_id
                  WHERE {$where_sql}
                  ORDER BY e.first_name ASC, e.last_name ASC
                  LIMIT %d OFFSET %d";
        $all_params = array_merge( $params, [ $per_page, $offset ] );
        $employees = $wpdb->get_results( $wpdb->prepare( $query, ...$all_params ), ARRAY_A );

        // Departments for filter.
        $departments = $wpdb->get_results(
            "SELECT id, name FROM {$dept_table} WHERE active = 1 ORDER BY name ASC",
            ARRAY_A
        );

        // Stats.
        $stat_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$emp_table} GROUP BY status",
            ARRAY_A
        );
        $stats = [];
        foreach ( $stat_counts as $s ) {
            $stats[ $s['status'] ] = (int) $s['cnt'];
        }

        // ── Render ──
        $this->render_header( $total_count, $stats );
        $this->render_filters( $departments, $search, $filter_dept, $filter_status );
        $this->render_list( $employees );
        $this->render_pagination( $page_num, $total_pages );
    }

    private function render_header( int $total, array $stats ): void {
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="employee_directory">' . esc_html__( 'Employee Directory', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-section-subtitle">' . esc_html( sprintf( __( '%d employees found', 'sfs-hr' ), $total ) ) . '</p>';
        echo '</div>';

        $active     = $stats['active'] ?? 0;
        $terminated = $stats['terminated'] ?? 0;
        $resigned   = $stats['resigned'] ?? 0;

        echo '<div class="sfs-kpi-grid">';

        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#ecfdf5;"><svg viewBox="0 0 24 24" stroke="#059669" fill="none" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="active">' . esc_html__( 'Active', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $active . '</div></div>';

        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#fef2f2;"><svg viewBox="0 0 24 24" stroke="#dc2626" fill="none" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="terminated">' . esc_html__( 'Terminated', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $terminated . '</div></div>';

        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" stroke="#d97706" fill="none" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="resigned">' . esc_html__( 'Resigned', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $resigned . '</div></div>';

        echo '</div>';
    }

    private function render_filters( array $departments, string $search, int $dept, string $status ): void {
        echo '<div class="sfs-card" style="margin-bottom:16px;">';
        echo '<div class="sfs-card-body" style="padding:12px 16px;">';
        echo '<form method="get" class="sfs-form-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap;">';

        if ( isset( $_GET['sfs_hr_tab'] ) ) {
            echo '<input type="hidden" name="sfs_hr_tab" value="' . esc_attr( sanitize_key( $_GET['sfs_hr_tab'] ) ) . '" />';
        }

        // Search.
        echo '<div class="sfs-form-group" style="flex:2;min-width:180px;margin:0;">';
        echo '<label class="sfs-form-label" style="font-size:12px;margin-bottom:4px;" data-i18n-key="search">' . esc_html__( 'Search', 'sfs-hr' ) . '</label>';
        echo '<input type="text" name="emp_search" class="sfs-input" style="padding:8px 10px;font-size:13px;" placeholder="' . esc_attr__( 'Name, code, or email...', 'sfs-hr' ) . '" value="' . esc_attr( $search ) . '" />';
        echo '</div>';

        // Department.
        echo '<div class="sfs-form-group" style="flex:1;min-width:140px;margin:0;">';
        echo '<label class="sfs-form-label" style="font-size:12px;margin-bottom:4px;" data-i18n-key="department">' . esc_html__( 'Department', 'sfs-hr' ) . '</label>';
        echo '<select name="emp_dept" class="sfs-select" style="padding:8px 10px;font-size:13px;">';
        echo '<option value="0" data-i18n-key="all">' . esc_html__( 'All', 'sfs-hr' ) . '</option>';
        foreach ( $departments as $d ) {
            $sel = ( (int) $d['id'] === $dept ) ? ' selected' : '';
            echo '<option value="' . (int) $d['id'] . '"' . $sel . '>' . esc_html( $d['name'] ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Status.
        echo '<div class="sfs-form-group" style="flex:1;min-width:120px;margin:0;">';
        echo '<label class="sfs-form-label" style="font-size:12px;margin-bottom:4px;" data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</label>';
        echo '<select name="emp_status" class="sfs-select" style="padding:8px 10px;font-size:13px;">';
        $statuses = [
            'active'     => __( 'Active', 'sfs-hr' ),
            'all'        => __( 'All', 'sfs-hr' ),
            'terminated' => __( 'Terminated', 'sfs-hr' ),
            'resigned'   => __( 'Resigned', 'sfs-hr' ),
        ];
        foreach ( $statuses as $val => $label ) {
            $sel = ( $val === $status ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $val ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="sfs-form-group" style="margin:0;">';
        echo '<button type="submit" class="sfs-btn sfs-btn--primary" style="padding:8px 16px;font-size:13px;" data-i18n-key="search">' . esc_html__( 'Search', 'sfs-hr' ) . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div></div>';
    }

    private function render_list( array $employees ): void {
        if ( empty( $employees ) ) {
            echo '<div class="sfs-card"><div class="sfs-empty-state">';
            echo '<div class="sfs-empty-state-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" fill="none" stroke-width="1.5"/><circle cx="9" cy="7" r="4" stroke="currentColor" fill="none" stroke-width="1.5"/></svg></div>';
            echo '<p class="sfs-empty-state-title" data-i18n-key="no_employees_found">' . esc_html__( 'No employees found', 'sfs-hr' ) . '</p>';
            echo '<p class="sfs-empty-state-text" data-i18n-key="try_adjusting_search">' . esc_html__( 'Try adjusting your search criteria.', 'sfs-hr' ) . '</p>';
            echo '</div></div>';
            return;
        }

        // Desktop table.
        echo '<div class="sfs-desktop-only"><table class="sfs-table">';
        echo '<thead><tr>';
        echo '<th data-i18n-key="code">' . esc_html__( 'Code', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="name">' . esc_html__( 'Name', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="department">' . esc_html__( 'Department', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="position">' . esc_html__( 'Position', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="contact">' . esc_html__( 'Contact', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="nationality">' . esc_html__( 'Nationality', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="hire_date">' . esc_html__( 'Hire Date', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $employees as $e ) {
            $name   = esc_html( trim( ( $e['first_name'] ?? '' ) . ' ' . ( $e['last_name'] ?? '' ) ) );
            $code   = esc_html( $e['employee_code'] ?? '' );
            $dept   = esc_html( $e['dept_name'] ?? '—' );
            $pos    = esc_html( $e['position'] ?? '—' );
            $phone  = esc_html( $e['phone'] ?? '' );
            $email  = esc_html( $e['email'] ?? '' );
            $nat    = esc_html( $e['nationality'] ?? '—' );
            $hire   = $e['hire_date'] ? esc_html( wp_date( 'M j, Y', strtotime( $e['hire_date'] ) ) ) : '—';
            $status = $e['status'] ?? 'active';
            $badge_class = $status === 'active' ? 'approved' : ( $status === 'terminated' ? 'rejected' : 'pending' );

            echo '<tr>';
            echo '<td><strong>' . $code . '</strong></td>';
            echo '<td>' . $name . '</td>';
            echo '<td>' . $dept . '</td>';
            echo '<td>' . $pos . '</td>';
            echo '<td>';
            if ( $phone ) echo '<span style="font-size:13px;">' . $phone . '</span>';
            if ( $phone && $email ) echo '<br>';
            if ( $email ) echo '<span style="font-size:12px;color:var(--sfs-text-muted);">' . $email . '</span>';
            if ( ! $phone && ! $email ) echo '—';
            echo '</td>';
            echo '<td>' . $nat . '</td>';
            echo '<td>' . $hire . '</td>';
            $status_key = $status;
            $status_label = $this->translate_status( $status );
            echo '<td><span class="sfs-badge sfs-badge--' . esc_attr( $badge_class ) . '" data-i18n-key="' . esc_attr( $status_key ) . '">' . esc_html( $status_label ) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        // Mobile cards.
        echo '<div class="sfs-mobile-only sfs-emp-dir-list">';
        foreach ( $employees as $e ) {
            $name   = esc_html( trim( ( $e['first_name'] ?? '' ) . ' ' . ( $e['last_name'] ?? '' ) ) );
            $code   = esc_html( $e['employee_code'] ?? '' );
            $dept   = esc_html( $e['dept_name'] ?? '—' );
            $pos    = esc_html( $e['position'] ?? '—' );
            $status = $e['status'] ?? 'active';
            $badge_class = $status === 'active' ? 'approved' : ( $status === 'terminated' ? 'rejected' : 'pending' );
            $status_label = $this->translate_status( $status );

            echo '<div class="sfs-card" style="margin-bottom:8px;">';
            echo '<div class="sfs-card-body" style="padding:12px 16px;">';
            echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;">';
            echo '<div>';
            echo '<strong style="font-size:15px;">' . $name . '</strong>';
            echo '<div style="font-size:13px;color:var(--sfs-text-muted);margin-top:2px;">' . $code . '</div>';
            echo '<div style="font-size:13px;color:var(--sfs-text-muted);">' . $dept;
            if ( $pos !== '—' ) echo ' &middot; ' . $pos;
            echo '</div>';
            echo '</div>';
            echo '<span class="sfs-badge sfs-badge--' . esc_attr( $badge_class ) . '" data-i18n-key="' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span>';
            echo '</div>';
            echo '</div></div>';
        }
        echo '</div>';
    }

    private function translate_status( string $status ): string {
        $map = [
            'active'     => __( 'Active', 'sfs-hr' ),
            'terminated' => __( 'Terminated', 'sfs-hr' ),
            'resigned'   => __( 'Resigned', 'sfs-hr' ),
            'on_leave'   => __( 'On Leave', 'sfs-hr' ),
        ];
        return $map[ $status ] ?? ucfirst( $status );
    }

    private function render_pagination( int $current, int $total ): void {
        if ( $total <= 1 ) return;

        echo '<div style="display:flex;justify-content:center;gap:8px;margin-top:16px;">';

        $base = remove_query_arg( 'emp_page' );

        if ( $current > 1 ) {
            $prev_url = add_query_arg( 'emp_page', $current - 1, $base );
            echo '<a href="' . esc_url( $prev_url ) . '" class="sfs-btn sfs-btn--sm" style="padding:6px 12px;font-size:13px;">&laquo; <span data-i18n-key="prev">' . esc_html__( 'Prev', 'sfs-hr' ) . '</span></a>';
        }

        echo '<span style="padding:6px 12px;font-size:13px;color:var(--sfs-text-muted);">'
            . esc_html( sprintf( __( 'Page %d of %d', 'sfs-hr' ), $current, $total ) )
            . '</span>';

        if ( $current < $total ) {
            $next_url = add_query_arg( 'emp_page', $current + 1, $base );
            echo '<a href="' . esc_url( $next_url ) . '" class="sfs-btn sfs-btn--sm" style="padding:6px 12px;font-size:13px;"><span data-i18n-key="next">' . esc_html__( 'Next', 'sfs-hr' ) . '</span> &raquo;</a>';
        }

        echo '</div>';
    }
}
