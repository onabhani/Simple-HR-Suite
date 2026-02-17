<?php
namespace SFS\HR\Modules\EmployeeExit\Admin;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_List;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_Settings;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_List;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_Form;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_View;

if (!defined('ABSPATH')) { exit; }

/**
 * Employee Exit Admin
 * Unified admin page for the employee exit workflow:
 * - Resignations
 * - Settlements
 * - Exit Settings
 *
 * Note: Hiring (Candidates, Trainees) is now a separate page under HR → Hiring.
 */
class Employee_Exit_Admin {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_menu', [$this, 'register_menu'], 25);
    }

    /**
     * Register admin menu
     */
    public function register_menu(): void {
        add_submenu_page(
            'sfs-hr',
            __('Employee Exit', 'sfs-hr'),
            __('Employee Exit', 'sfs-hr'),
            'sfs_hr.view',
            'sfs-hr-lifecycle',
            [$this, 'render_hub']
        );
    }

    /**
     * Render the hub page with tabs
     */
    public function render_hub(): void {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'resignations';

        echo '<div class="wrap sfs-hr-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Employee Exit', 'sfs-hr') . '</h1>';
        Helpers::render_admin_nav();
        echo '<hr class="wp-header-end" />';

        // Tab navigation
        $this->render_tabs($tab);

        switch ($tab) {
            case 'resignations':
                $this->render_resignations_content();
                break;

            case 'settlements':
                $this->render_settlements_content();
                break;

            case 'contracts':
                $this->render_expiring_contracts();
                break;

            case 'exit-settings':
                if (current_user_can('sfs_hr.manage')) {
                    Resignation_Settings::render();
                }
                break;

            default:
                $this->render_resignations_content();
                break;
        }

        echo '</div>';
    }

    /**
     * Render tab navigation
     */
    private function render_tabs(string $current_tab): void {
        $tabs = [
            'resignations' => __('Resignations', 'sfs-hr'),
            'settlements' => __('Settlements', 'sfs-hr'),
            'contracts' => __('Expiring Contracts', 'sfs-hr'),
        ];

        // Add settings tab only for managers
        if (current_user_can('sfs_hr.manage')) {
            $tabs['exit-settings'] = __('Exit Settings', 'sfs-hr');
        }

        echo '<h2 class="nav-tab-wrapper">';

        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(['page' => 'sfs-hr-lifecycle', 'tab' => $slug], admin_url('admin.php'));
            $class = ($current_tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }

        echo '</h2>';
        echo '<div style="margin-top: 20px;"></div>';
    }

    /**
     * Render resignations content
     */
    private function render_resignations_content(): void {
        Resignation_List::render();
    }

    /**
     * Render settlements content
     */
    private function render_settlements_content(): void {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        $settlement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        switch ($action) {
            case 'create':
                Settlement_Form::render();
                break;

            case 'view':
            case 'edit':
                if ($settlement_id) {
                    Settlement_View::render($settlement_id);
                } else {
                    Settlement_List::render();
                }
                break;

            default:
                Settlement_List::render();
                break;
        }
    }

    /**
     * Render the Expiring Contracts tab.
     */
    private function render_expiring_contracts(): void {
        global $wpdb;

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $today      = current_time( 'Y-m-d' );

        $notice_period = (int) get_option( 'sfs_hr_resignation_notice_period', 30 );
        $threshold     = $notice_period + 5;

        // Filter: 30, 60, 90, or custom threshold days
        $filter_days = isset( $_GET['days'] ) ? (int) $_GET['days'] : $threshold;
        if ( $filter_days < 1 ) {
            $filter_days = $threshold;
        }
        $end_date = gmdate( 'Y-m-d', strtotime( "+{$filter_days} days" ) );

        $employees = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.employee_code, e.first_name, e.last_name,
                    e.contract_end_date, e.contract_type, e.position,
                    d.name AS dept_name
             FROM {$emp_table} e
             LEFT JOIN {$dept_table} d ON d.id = e.dept_id
             WHERE e.status = 'active'
               AND e.contract_end_date IS NOT NULL
               AND e.contract_end_date BETWEEN %s AND %s
             ORDER BY e.contract_end_date ASC",
            $today,
            $end_date
        ), ARRAY_A );

        // Also show already-expired active employees (contract ended but still active)
        $expired = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.employee_code, e.first_name, e.last_name,
                    e.contract_end_date, e.contract_type, e.position,
                    d.name AS dept_name
             FROM {$emp_table} e
             LEFT JOIN {$dept_table} d ON d.id = e.dept_id
             WHERE e.status = 'active'
               AND e.contract_end_date IS NOT NULL
               AND e.contract_end_date < %s
             ORDER BY e.contract_end_date ASC",
            $today
        ), ARRAY_A );

        echo '<div class="sfs-hr-section">';
        echo '<h2>' . esc_html__( 'Expiring Contracts', 'sfs-hr' ) . '</h2>';
        echo '<p class="description">' . sprintf(
            /* translators: %d: notice period days */
            esc_html__( 'Employees whose contracts expire within the notification threshold (%d days = notice period %d + 5 days buffer).', 'sfs-hr' ),
            $threshold,
            $notice_period
        ) . '</p>';

        // Filter buttons
        $base_url = admin_url( 'admin.php?page=sfs-hr-lifecycle&tab=contracts' );
        echo '<div style="margin:12px 0;">';
        foreach ( [ $threshold => sprintf( __( '%d days (default)', 'sfs-hr' ), $threshold ), 60 => __( '60 days', 'sfs-hr' ), 90 => __( '90 days', 'sfs-hr' ), 180 => __( '180 days', 'sfs-hr' ) ] as $d => $label ) {
            $active = ( $filter_days === $d ) ? ' button-primary' : '';
            echo '<a href="' . esc_url( add_query_arg( 'days', $d, $base_url ) ) . '" class="button' . $active . '" style="margin-right:4px;">' . esc_html( $label ) . '</a>';
        }
        echo '</div>';

        // Expired contracts section
        if ( ! empty( $expired ) ) {
            echo '<h3 style="color:#dc2626;margin-top:20px;">' . esc_html__( 'Expired (still active)', 'sfs-hr' ) . '</h3>';
            $this->render_contracts_table( $expired, $today, true );
        }

        // Upcoming expirations
        if ( ! empty( $employees ) ) {
            echo '<h3 style="margin-top:20px;">' . esc_html__( 'Expiring Soon', 'sfs-hr' ) . '</h3>';
            $this->render_contracts_table( $employees, $today, false );
        }

        if ( empty( $expired ) && empty( $employees ) ) {
            echo '<p>' . esc_html__( 'No contracts expiring within the selected period.', 'sfs-hr' ) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Render a contracts table.
     */
    private function render_contracts_table( array $employees, string $today, bool $is_expired ): void {
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Code', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Employee', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Department', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Position', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Contract Type', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Contract End', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Days Left', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $employees as $emp ) {
            $end_date = $emp['contract_end_date'];
            $days_left = (int) ( ( strtotime( $end_date ) - strtotime( $today ) ) / 86400 );
            $badge_class = 'sfs-hr-badge ';
            if ( $days_left < 0 ) {
                $badge_class .= 'status-terminated';
                $days_text = sprintf( __( '%d days overdue', 'sfs-hr' ), abs( $days_left ) );
            } elseif ( $days_left <= 14 ) {
                $badge_class .= 'status-terminated';
                $days_text = sprintf( _n( '%d day', '%d days', $days_left, 'sfs-hr' ), $days_left );
            } elseif ( $days_left <= 30 ) {
                $badge_class .= 'status-inactive';
                $days_text = sprintf( _n( '%d day', '%d days', $days_left, 'sfs-hr' ), $days_left );
            } else {
                $badge_class .= 'status-active';
                $days_text = sprintf( _n( '%d day', '%d days', $days_left, 'sfs-hr' ), $days_left );
            }

            $view_url = admin_url( 'admin.php?page=sfs-hr-employees&action=view&id=' . (int) $emp['id'] );

            echo '<tr>';
            echo '<td>' . esc_html( $emp['employee_code'] ?? '' ) . '</td>';
            echo '<td><a href="' . esc_url( $view_url ) . '"><strong>' . esc_html( trim( $emp['first_name'] . ' ' . $emp['last_name'] ) ) . '</strong></a></td>';
            echo '<td>' . esc_html( $emp['dept_name'] ?? '—' ) . '</td>';
            echo '<td>' . esc_html( $emp['position'] ?? '—' ) . '</td>';
            echo '<td>' . esc_html( $emp['contract_type'] ?? '—' ) . '</td>';
            echo '<td>' . esc_html( $end_date ) . '</td>';
            echo '<td><span class="' . esc_attr( $badge_class ) . '">' . esc_html( $days_text ) . '</span></td>';
            echo '<td><a href="' . esc_url( $view_url ) . '" class="button button-small">' . esc_html__( 'View', 'sfs-hr' ) . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
