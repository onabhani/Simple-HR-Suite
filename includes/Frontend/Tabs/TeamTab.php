<?php
/**
 * Team Tab — My Team employee list for managers/HR/admin.
 *
 * Managers see employees in their departments.
 * HR, GM, Admin see all active employees with department filter.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Frontend\Role_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TeamTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        $user_id = get_current_user_id();
        $role    = Role_Resolver::resolve( $user_id );
        $level   = Role_Resolver::role_level( $role );

        if ( $level < 30 ) { // manager=30
            echo '<p>' . esc_html__( 'You do not have permission to view this page.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        // Determine scope — always filter by managed departments (direct reports only).
        $dept_ids = Role_Resolver::get_manager_dept_ids( $user_id );
        if ( empty( $dept_ids ) ) {
            echo '<div class="sfs-empty-state">';
            echo '<p class="sfs-empty-state-title">' . esc_html__( 'No departments assigned.', 'sfs-hr' ) . '</p>';
            echo '<p class="sfs-empty-state-text">' . esc_html__( 'You are not currently managing any departments.', 'sfs-hr' ) . '</p>';
            echo '</div>';
            return;
        }
        $is_manager_only = true; // Always scope to direct reports

        // Get all departments for filter (HR+).
        $departments = [];
        if ( ! $is_manager_only ) {
            $departments = $wpdb->get_results(
                "SELECT id, name FROM {$dept_table} WHERE active = 1 ORDER BY name ASC",
                ARRAY_A
            );
        } else {
            $in_clause = implode( ',', array_map( 'intval', $dept_ids ) );
            $departments = $wpdb->get_results(
                "SELECT id, name FROM {$dept_table} WHERE id IN ({$in_clause}) ORDER BY name ASC",
                ARRAY_A
            );
        }

        // Filter by selected department.
        $filter_dept = isset( $_GET['dept_filter'] ) ? (int) $_GET['dept_filter'] : 0;
        $filter_status = isset( $_GET['status_filter'] ) ? sanitize_key( $_GET['status_filter'] ) : 'active';

        // Build query.
        $where = [ '1=1' ];
        $params = [];

        if ( $is_manager_only ) {
            $placeholders = implode( ',', array_fill( 0, count( $dept_ids ), '%d' ) );
            $where[] = "e.dept_id IN ({$placeholders})";
            $params = array_merge( $params, $dept_ids );
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

        $query = "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.status,
                         e.position, e.phone, e.email, e.photo_id, e.hire_date,
                         d.name AS dept_name
                  FROM {$emp_table} e
                  LEFT JOIN {$dept_table} d ON d.id = e.dept_id
                  WHERE {$where_sql}
                  ORDER BY e.first_name ASC, e.last_name ASC
                  LIMIT 200";

        if ( ! empty( $params ) ) {
            $employees = $wpdb->get_results( $wpdb->prepare( $query, ...$params ), ARRAY_A );
        } else {
            $employees = $wpdb->get_results( $query, ARRAY_A );
        }

        $total_count = count( $employees );

        // ── Render ──
        $this->render_header( $is_manager_only, $total_count );
        $this->render_filters( $departments, $filter_dept, $filter_status );
        $this->render_team_list( $employees );
    }

    private function render_header( bool $is_manager, int $count ): void {
        $title = $is_manager
            ? __( 'My Team', 'sfs-hr' )
            : __( 'Team Members', 'sfs-hr' );

        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="my_team">' . esc_html( $title ) . '</h2>';
        echo '<p class="sfs-section-subtitle">'
            . esc_html( sprintf( _n( '%d member', '%d members', $count, 'sfs-hr' ), $count ) )
            . '</p>';
        echo '</div>';
    }

    private function render_filters( array $departments, int $selected_dept, string $selected_status ): void {
        $base_url = remove_query_arg( [ 'dept_filter', 'status_filter' ] );

        echo '<div class="sfs-card" style="margin-bottom:16px;">';
        echo '<div class="sfs-card-body" style="padding:12px 16px;">';
        echo '<form method="get" action="" class="sfs-team-filter-form">';

        // Preserve existing query params.
        $preserved = [ 'sfs_hr_tab' ];
        foreach ( $preserved as $key ) {
            if ( isset( $_GET[ $key ] ) ) {
                echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( sanitize_text_field( $_GET[ $key ] ) ) . '" />';
            }
        }

        echo '<div class="sfs-form-row" style="gap:12px;align-items:flex-end;">';

        // Department filter.
        if ( count( $departments ) > 1 ) {
            echo '<div class="sfs-form-group" style="flex:1;min-width:140px;margin:0;">';
            echo '<label class="sfs-form-label" style="font-size:12px;margin-bottom:4px;" data-i18n-key="department">' . esc_html__( 'Department', 'sfs-hr' ) . '</label>';
            echo '<select name="dept_filter" class="sfs-select" style="padding:8px 10px;font-size:13px;">';
            echo '<option value="0" data-i18n-key="all_departments">' . esc_html__( 'All Departments', 'sfs-hr' ) . '</option>';
            foreach ( $departments as $d ) {
                $sel = ( (int) $d['id'] === $selected_dept ) ? ' selected' : '';
                echo '<option value="' . (int) $d['id'] . '"' . $sel . '>' . esc_html( $d['name'] ) . '</option>';
            }
            echo '</select>';
            echo '</div>';
        }

        // Status filter.
        echo '<div class="sfs-form-group" style="flex:1;min-width:120px;margin:0;">';
        echo '<label class="sfs-form-label" style="font-size:12px;margin-bottom:4px;" data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</label>';
        echo '<select name="status_filter" class="sfs-select" style="padding:8px 10px;font-size:13px;">';
        $statuses = [
            'active'     => __( 'Active', 'sfs-hr' ),
            'all'        => __( 'All', 'sfs-hr' ),
            'terminated' => __( 'Terminated', 'sfs-hr' ),
            'resigned'   => __( 'Resigned', 'sfs-hr' ),
        ];
        foreach ( $statuses as $val => $label ) {
            $sel = ( $val === $selected_status ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $val ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="sfs-form-group" style="margin:0;">';
        echo '<button type="submit" class="sfs-btn sfs-btn--primary" style="padding:8px 16px;font-size:13px;" data-i18n-key="filter">' . esc_html__( 'Filter', 'sfs-hr' ) . '</button>';
        echo '</div>';

        echo '</div>'; // .sfs-form-row
        echo '</form>';
        echo '</div></div>';
    }

    /**
     * Translate a status value.
     */
    private function translate_status( string $status ): string {
        $map = [
            'active'     => __( 'Active', 'sfs-hr' ),
            'terminated' => __( 'Terminated', 'sfs-hr' ),
            'resigned'   => __( 'Resigned', 'sfs-hr' ),
            'on_leave'   => __( 'On Leave', 'sfs-hr' ),
        ];
        return $map[ $status ] ?? ucfirst( $status );
    }

    private function render_team_list( array $employees ): void {
        if ( empty( $employees ) ) {
            echo '<div class="sfs-card"><div class="sfs-empty-state">';
            echo '<div class="sfs-empty-state-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="currentColor" fill="none" stroke-width="1.5"/><circle cx="9" cy="7" r="4" stroke="currentColor" fill="none" stroke-width="1.5"/><path d="M23 21v-2a4 4 0 0 0-3-3.87" stroke="currentColor" fill="none" stroke-width="1.5"/><path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="currentColor" fill="none" stroke-width="1.5"/></svg></div>';
            echo '<p class="sfs-empty-state-title" data-i18n-key="no_team_members">' . esc_html__( 'No team members found', 'sfs-hr' ) . '</p>';
            echo '<p class="sfs-empty-state-text" data-i18n-key="try_adjusting_filters">' . esc_html__( 'Try adjusting your filters.', 'sfs-hr' ) . '</p>';
            echo '</div></div>';
            return;
        }

        // Card-based team list (unified for desktop + mobile).
        echo '<div class="sfs-history-list">';
        foreach ( $employees as $e ) {
            $name   = esc_html( trim( ( $e['first_name'] ?? '' ) . ' ' . ( $e['last_name'] ?? '' ) ) );
            $code   = esc_html( $e['employee_code'] ?? '' );
            $dept   = esc_html( $e['dept_name'] ?? '—' );
            $pos    = esc_html( $e['position'] ?? '—' );
            $phone  = esc_html( $e['phone'] ?? '' );
            $email  = esc_html( $e['email'] ?? '' );
            $hire   = $e['hire_date'] ? esc_html( wp_date( 'M j, Y', strtotime( $e['hire_date'] ) ) ) : '—';
            $status = $e['status'] ?? 'active';
            $badge_class = $status === 'active' ? 'approved' : ( $status === 'terminated' ? 'rejected' : 'pending' );
            $status_label = $this->translate_status( $status );

            echo '<details class="sfs-history-card">';
            echo '<summary>';
            echo '<div class="sfs-history-card-info">';
            echo '<span class="sfs-history-card-title">' . $name . '</span>';
            echo '<span class="sfs-history-card-meta">' . $code . ' · ' . $dept;
            if ( $pos !== '—' ) {
                echo ' · ' . $pos;
            }
            echo '</span>';
            echo '</div>';
            echo '<span class="sfs-badge sfs-badge--' . esc_attr( $badge_class ) . '" data-i18n-key="' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span>';
            echo '</summary>';

            echo '<div class="sfs-history-card-body">';
            echo '<div class="sfs-detail-row"><span class="sfs-detail-label" data-i18n-key="department">' . esc_html__( 'Department', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . $dept . '</span></div>';
            echo '<div class="sfs-detail-row"><span class="sfs-detail-label" data-i18n-key="position">' . esc_html__( 'Position', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . $pos . '</span></div>';
            echo '<div class="sfs-detail-row"><span class="sfs-detail-label" data-i18n-key="hire_date">' . esc_html__( 'Hire Date', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . $hire . '</span></div>';
            if ( $phone ) {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label" data-i18n-key="phone">' . esc_html__( 'Phone', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . $phone . '</span></div>';
            }
            if ( $email ) {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label" data-i18n-key="email">' . esc_html__( 'Email', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . $email . '</span></div>';
            }
            echo '</div></details>';
        }
        echo '</div>';
    }
}
