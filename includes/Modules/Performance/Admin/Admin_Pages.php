<?php
namespace SFS\HR\Modules\Performance\Admin;

use SFS\HR\Modules\Performance\PerformanceModule;
use SFS\HR\Modules\Performance\Services\Attendance_Metrics;
use SFS\HR\Modules\Performance\Services\Goals_Service;
use SFS\HR\Modules\Performance\Services\Reviews_Service;
use SFS\HR\Modules\Performance\Services\Performance_Calculator;
use SFS\HR\Modules\Performance\Services\Alerts_Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Performance Admin Pages
 *
 * Handles all admin UI for the Performance module.
 *
 * @version 1.0.0
 */
class Admin_Pages {

    /**
     * Register hooks.
     */
    public function hooks(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_menu', [ $this, 'remove_separator_after_performance' ], 999 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_sfs_hr_save_performance_settings', [ $this, 'handle_save_settings' ] );
        add_action( 'admin_post_sfs_hr_save_goal', [ $this, 'handle_save_goal' ] );
        add_action( 'admin_post_sfs_hr_delete_goal', [ $this, 'handle_delete_goal' ] );
        add_action( 'admin_post_sfs_hr_acknowledge_alert', [ $this, 'handle_acknowledge_alert' ] );
        add_action( 'admin_post_sfs_hr_resolve_alert', [ $this, 'handle_resolve_alert' ] );
    }

    /**
     * Register admin menu.
     */
    public function register_menu(): void {
        $view_cap  = 'sfs_hr_performance_view';
        $admin_cap = 'sfs_hr.manage';

        add_menu_page(
            __( 'Performance', 'sfs-hr' ),
            __( 'Performance', 'sfs-hr' ),
            $view_cap,
            'sfs-hr-performance',
            [ $this, 'render_dashboard' ],
            'dashicons-chart-line',
            57
        );

        add_submenu_page(
            'sfs-hr-performance',
            __( 'Dashboard', 'sfs-hr' ),
            __( 'Dashboard', 'sfs-hr' ),
            $view_cap,
            'sfs-hr-performance',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            'sfs-hr-performance',
            __( 'Employees', 'sfs-hr' ),
            __( 'Employees', 'sfs-hr' ),
            $view_cap,
            'sfs-hr-performance-employees',
            [ $this, 'render_employees' ]
        );

        add_submenu_page(
            'sfs-hr-performance',
            __( 'Goals', 'sfs-hr' ),
            __( 'Goals', 'sfs-hr' ),
            $view_cap,
            'sfs-hr-performance-goals',
            [ $this, 'render_goals' ]
        );

        add_submenu_page(
            'sfs-hr-performance',
            __( 'Reviews', 'sfs-hr' ),
            __( 'Reviews', 'sfs-hr' ),
            $view_cap,
            'sfs-hr-performance-reviews',
            [ $this, 'render_reviews' ]
        );

        add_submenu_page(
            'sfs-hr-performance',
            __( 'Alerts', 'sfs-hr' ),
            __( 'Alerts', 'sfs-hr' ),
            $view_cap,
            'sfs-hr-performance-alerts',
            [ $this, 'render_alerts' ]
        );

        add_submenu_page(
            'sfs-hr-performance',
            __( 'Settings', 'sfs-hr' ),
            __( 'Settings', 'sfs-hr' ),
            $admin_cap,
            'sfs-hr-performance-settings',
            [ $this, 'render_settings' ]
        );
    }

    /**
     * Remove WP core separator that creates a gap below Performance.
     */
    public function remove_separator_after_performance(): void {
        global $menu;
        foreach ( $menu as $key => $item ) {
            // Remove any separator sitting between Performance (57) and Appearance (60)
            if ( $key >= 58 && $key <= 59 && isset( $item[4] ) && strpos( $item[4], 'wp-menu-separator' ) !== false ) {
                unset( $menu[ $key ] );
            }
        }
    }

    /**
     * Enqueue assets.
     */
    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'sfs-hr-performance' ) === false ) {
            return;
        }

        wp_enqueue_style( 'sfs-hr-performance', plugins_url( 'assets/css/performance.css', dirname( __DIR__, 3 ) . '/sfs-hr.php' ), [], '1.0.0' );
        wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.0', true );

        // Inline styles for the module
        wp_add_inline_style( 'sfs-hr-performance', $this->get_inline_styles() );
    }

    /**
     * Get inline CSS styles.
     */
    private function get_inline_styles(): string {
        return '
            .sfs-perf-wrap { max-width: 1400px; }
            .sfs-perf-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
            .sfs-perf-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
            .sfs-perf-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; }
            .sfs-perf-card h3 { margin: 0 0 10px; font-size: 14px; color: #666; font-weight: normal; }
            .sfs-perf-card .value { font-size: 32px; font-weight: 600; color: #1e3a5f; }
            .sfs-perf-card .sub { font-size: 12px; color: #888; margin-top: 5px; }
            .sfs-perf-table { background: #fff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
            .sfs-perf-table table { margin: 0; border: none; }
            .sfs-perf-table th { background: #f8f9fa; }
            .sfs-perf-badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; }
            .sfs-perf-badge.excellent { background: #dcfce7; color: #166534; }
            .sfs-perf-badge.good { background: #dbeafe; color: #1e40af; }
            .sfs-perf-badge.fair { background: #fef3c7; color: #92400e; }
            .sfs-perf-badge.poor { background: #fee2e2; color: #991b1b; }
            .sfs-perf-badge.exceptional { background: #dcfce7; color: #166534; }
            .sfs-perf-badge.exceeds { background: #dbeafe; color: #1e40af; }
            .sfs-perf-badge.meets { background: #fef3c7; color: #92400e; }
            .sfs-perf-badge.developing { background: #ffedd5; color: #9a3412; }
            .sfs-perf-badge.needs_improvement { background: #fee2e2; color: #991b1b; }
            .sfs-perf-progress { background: #e5e7eb; border-radius: 4px; height: 8px; overflow: hidden; }
            .sfs-perf-progress-bar { height: 100%; background: #3b82f6; transition: width 0.3s; }
            .sfs-perf-filters { display: flex; gap: 15px; align-items: center; margin-bottom: 20px; flex-wrap: wrap; }
            .sfs-perf-filters label { display: flex; align-items: center; gap: 8px; }
            .sfs-perf-section { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
            .sfs-perf-section h2 { margin: 0 0 15px; font-size: 16px; }
            .sfs-perf-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
            @media (max-width: 1024px) { .sfs-perf-grid { grid-template-columns: 1fr; } }
            .sfs-perf-alert { padding: 15px; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
            .sfs-perf-alert.warning { background: #fef3c7; border: 1px solid #f59e0b; }
            .sfs-perf-alert.critical { background: #fee2e2; border: 1px solid #ef4444; }
            .sfs-perf-alert.info { background: #dbeafe; border: 1px solid #3b82f6; }
            .sfs-perf-chart-container { height: 300px; }

            /* Distribution dots */
            .sfs-dist-item { white-space: nowrap; margin-right: 6px; }
            .sfs-dist-label { display: none; font-size: 11px; }

            /* Mobile responsive styles */
            @media (max-width: 768px) {
                .sfs-perf-wrap { padding: 0 4px; }
                .sfs-perf-header { flex-direction: column; align-items: flex-start; gap: 10px; }
                .sfs-perf-filters { flex-direction: column; align-items: stretch; gap: 10px; width: 100%; }
                .sfs-perf-filters label { flex-direction: column; gap: 4px; }
                .sfs-perf-filters select,
                .sfs-perf-filters input[type="date"] { width: 100%; }
                .sfs-perf-cards { grid-template-columns: 1fr 1fr; gap: 10px; }
                .sfs-perf-card { padding: 14px; }
                .sfs-perf-card .value { font-size: 24px; }

                /* Compact tables on mobile — keep table layout, smaller text */
                .sfs-perf-table { overflow-x: auto; -webkit-overflow-scrolling: touch; }
                .sfs-perf-table table { font-size: 13px; min-width: 0; }
                .sfs-perf-table table th,
                .sfs-perf-table table td { padding: 6px 8px; white-space: nowrap; }

                /* Department table: show distribution labels */
                .sfs-dist-label { display: inline; }
                .sfs-dist-item { display: inline-block; margin-right: 4px; font-size: 12px; }

                /* Ranking table: hide non-essential columns — keep Employee, Grade, Actions */
                .sfs-ranking-table th:nth-child(1),
                .sfs-ranking-table td:nth-child(1),
                .sfs-ranking-table th:nth-child(3),
                .sfs-ranking-table td:nth-child(3),
                .sfs-ranking-table th:nth-child(4),
                .sfs-ranking-table td:nth-child(4),
                .sfs-ranking-table th:nth-child(5),
                .sfs-ranking-table td:nth-child(5),
                .sfs-ranking-table th:nth-child(6),
                .sfs-ranking-table td:nth-child(6) { display: none; }

                /* Commitment table: hide Day, Flags columns */
                .sfs-commitment-table th:nth-child(2),
                .sfs-commitment-table td:nth-child(2),
                .sfs-commitment-table th:nth-child(4),
                .sfs-commitment-table td:nth-child(4) { display: none; }
            }
            @media (max-width: 480px) {
                .sfs-perf-cards { grid-template-columns: 1fr; }
            }
        ';
    }

    /**
     * Render dashboard page.
     */
    public function render_dashboard(): void {
        global $wpdb;

        $att_period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
        $start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : $att_period['start'];
        $end_date = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : $att_period['end'];

        // Get summary data
        $dept_summary = Performance_Calculator::get_departments_summary( $start_date, $end_date );
        $alerts_stats = Alerts_Service::get_statistics();
        $active_alerts = Alerts_Service::get_active_alerts();

        // Get employee count
        $emp_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active'"
        );

        // Calculate averages
        $total_avg = 0;
        $dept_with_scores = 0;
        foreach ( $dept_summary as $dept ) {
            if ( $dept['avg_score'] !== null ) {
                $total_avg += $dept['avg_score'];
                $dept_with_scores++;
            }
        }
        $company_avg = $dept_with_scores > 0 ? round( $total_avg / $dept_with_scores, 1 ) : 0;

        ?>
        <div class="wrap sfs-perf-wrap">
            <div class="sfs-perf-header">
                <h1><?php esc_html_e( 'Performance Dashboard', 'sfs-hr' ); ?></h1>
                <form method="get" class="sfs-perf-filters">
                    <input type="hidden" name="page" value="sfs-hr-performance">
                    <label>
                        <?php esc_html_e( 'From:', 'sfs-hr' ); ?>
                        <input type="date" name="start_date" value="<?php echo esc_attr( $start_date ); ?>">
                    </label>
                    <label>
                        <?php esc_html_e( 'To:', 'sfs-hr' ); ?>
                        <input type="date" name="end_date" value="<?php echo esc_attr( $end_date ); ?>">
                    </label>
                    <button type="submit" class="button"><?php esc_html_e( 'Apply', 'sfs-hr' ); ?></button>
                </form>
            </div>

            <!-- Summary Cards -->
            <div class="sfs-perf-cards">
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Company Average', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo esc_html( $company_avg ); ?>%</div>
                    <div class="sub"><?php esc_html_e( 'Overall Performance', 'sfs-hr' ); ?></div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Active Employees', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo esc_html( $emp_count ); ?></div>
                    <div class="sub"><?php esc_html_e( 'Being tracked', 'sfs-hr' ); ?></div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Active Alerts', 'sfs-hr' ); ?></h3>
                    <div class="value" style="color: <?php echo $alerts_stats['total_active'] > 0 ? '#ef4444' : '#22c55e'; ?>">
                        <?php echo esc_html( $alerts_stats['total_active'] ); ?>
                    </div>
                    <div class="sub">
                        <?php echo esc_html( sprintf(
                            __( '%d critical, %d warning', 'sfs-hr' ),
                            $alerts_stats['by_severity']['critical'],
                            $alerts_stats['by_severity']['warning']
                        ) ); ?>
                    </div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Departments', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo count( $dept_summary ); ?></div>
                    <div class="sub"><?php esc_html_e( 'Active departments', 'sfs-hr' ); ?></div>
                </div>
            </div>

            <div class="sfs-perf-grid">
                <!-- Department Performance -->
                <div class="sfs-perf-section">
                    <h2><?php esc_html_e( 'Department Performance', 'sfs-hr' ); ?></h2>
                    <div class="sfs-perf-table">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Department', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Employees', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Avg Score', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Distribution', 'sfs-hr' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $dept_summary as $dept ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $dept['dept_name'] ); ?></strong></td>
                                    <td><?php echo esc_html( $dept['employee_count'] ); ?></td>
                                    <td>
                                        <?php if ( $dept['avg_score'] !== null ) : ?>
                                            <strong><?php echo esc_html( number_format( $dept['avg_score'], 1 ) ); ?>%</strong>
                                        <?php else : ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="sfs-dist-item" title="<?php esc_attr_e( 'Exceptional', 'sfs-hr' ); ?>" style="color: #22c55e;"><span class="sfs-dist-label"><?php esc_html_e( 'Exc', 'sfs-hr' ); ?> </span>●<?php echo $dept['grade_distribution']['exceptional']; ?></span>
                                        <span class="sfs-dist-item" title="<?php esc_attr_e( 'Exceeds', 'sfs-hr' ); ?>" style="color: #3b82f6;"><span class="sfs-dist-label"><?php esc_html_e( 'Exc+', 'sfs-hr' ); ?> </span>●<?php echo $dept['grade_distribution']['exceeds']; ?></span>
                                        <span class="sfs-dist-item" title="<?php esc_attr_e( 'Meets', 'sfs-hr' ); ?>" style="color: #f59e0b;"><span class="sfs-dist-label"><?php esc_html_e( 'Meet', 'sfs-hr' ); ?> </span>●<?php echo $dept['grade_distribution']['meets']; ?></span>
                                        <span class="sfs-dist-item" title="<?php esc_attr_e( 'Needs Improvement', 'sfs-hr' ); ?>" style="color: #ef4444;"><span class="sfs-dist-label"><?php esc_html_e( 'Low', 'sfs-hr' ); ?> </span>●<?php echo $dept['grade_distribution']['needs_improvement']; ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Alerts -->
                <div class="sfs-perf-section">
                    <h2><?php esc_html_e( 'Recent Alerts', 'sfs-hr' ); ?></h2>
                    <?php if ( empty( $active_alerts ) ) : ?>
                        <p style="color: #22c55e;">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e( 'No active alerts', 'sfs-hr' ); ?>
                        </p>
                    <?php else : ?>
                        <?php foreach ( array_slice( $active_alerts, 0, 5 ) as $alert ) : ?>
                        <div class="sfs-perf-alert <?php echo esc_attr( $alert->severity ); ?>">
                            <div>
                                <strong><?php echo esc_html( $alert->first_name . ' ' . $alert->last_name ); ?></strong><br>
                                <small><?php echo esc_html( $alert->title ); ?></small>
                            </div>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-performance-alerts' ) ); ?>" class="button button-small">
                                <?php esc_html_e( 'View', 'sfs-hr' ); ?>
                            </a>
                        </div>
                        <?php endforeach; ?>
                        <?php if ( count( $active_alerts ) > 5 ) : ?>
                        <p>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-performance-alerts' ) ); ?>">
                                <?php echo esc_html( sprintf( __( 'View all %d alerts', 'sfs-hr' ), count( $active_alerts ) ) ); ?>
                            </a>
                        </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render employees page.
     */
    public function render_employees(): void {
        global $wpdb;

        $att_period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
        $start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : $att_period['start'];
        $end_date = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : $att_period['end'];
        $dept_id = isset( $_GET['dept_id'] ) ? (int) $_GET['dept_id'] : 0;
        $view_employee = isset( $_GET['employee_id'] ) ? (int) $_GET['employee_id'] : 0;

        // Get departments for filter
        $departments = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments WHERE active = 1 ORDER BY name"
        );

        if ( $view_employee ) {
            $this->render_employee_detail( $view_employee, $start_date, $end_date );
            return;
        }

        // Get ranking
        $rankings = Performance_Calculator::get_performance_ranking( $dept_id, $start_date, $end_date );

        ?>
        <div class="wrap sfs-perf-wrap">
            <h1><?php esc_html_e( 'Employee Performance', 'sfs-hr' ); ?></h1>

            <form method="get" class="sfs-perf-filters">
                <input type="hidden" name="page" value="sfs-hr-performance-employees">
                <label>
                    <?php esc_html_e( 'Department:', 'sfs-hr' ); ?>
                    <select name="dept_id">
                        <option value="0"><?php esc_html_e( 'All Departments', 'sfs-hr' ); ?></option>
                        <?php foreach ( $departments as $d ) : ?>
                        <option value="<?php echo (int) $d->id; ?>" <?php selected( $dept_id, $d->id ); ?>>
                            <?php echo esc_html( $d->name ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?php esc_html_e( 'From:', 'sfs-hr' ); ?>
                    <input type="date" name="start_date" value="<?php echo esc_attr( $start_date ); ?>">
                </label>
                <label>
                    <?php esc_html_e( 'To:', 'sfs-hr' ); ?>
                    <input type="date" name="end_date" value="<?php echo esc_attr( $end_date ); ?>">
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'sfs-hr' ); ?></button>
            </form>

            <div class="sfs-perf-table sfs-ranking-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Rank', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Attendance', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Goals', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Reviews', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Overall', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Grade', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rankings ) ) : ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e( 'No performance data available for this period.', 'sfs-hr' ); ?></td>
                        </tr>
                        <?php else : ?>
                        <?php foreach ( $rankings as $emp ) :
                            $grade_display = Performance_Calculator::get_grade_display( $emp['overall_grade'] );
                        ?>
                        <tr>
                            <td class="sfs-rank-cell"><strong>#<?php echo esc_html( $emp['rank'] ); ?></strong></td>
                            <td class="sfs-emp-cell">
                                <?php
                                $detail_url = add_query_arg( [
                                    'page'        => 'sfs-hr-performance-employees',
                                    'employee_id' => $emp['employee_id'],
                                    'start_date'  => $start_date,
                                    'end_date'    => $end_date,
                                ], admin_url( 'admin.php' ) );
                                ?>
                                <a href="<?php echo esc_url( $detail_url ); ?>" style="text-decoration: none; color: inherit;">
                                    <strong><?php echo esc_html( $emp['employee_name'] ); ?></strong>
                                </a><br>
                                <small style="color: #666;"><?php echo esc_html( $emp['employee_code'] ); ?></small>
                            </td>
                            <td data-label="<?php esc_attr_e( 'Attendance', 'sfs-hr' ); ?>"><?php echo $emp['attendance_score'] !== null ? esc_html( number_format( $emp['attendance_score'], 1 ) ) . '%' : '—'; ?></td>
                            <td data-label="<?php esc_attr_e( 'Goals', 'sfs-hr' ); ?>"><?php echo $emp['goals_score'] !== null ? esc_html( number_format( $emp['goals_score'], 1 ) ) . '%' : '—'; ?></td>
                            <td data-label="<?php esc_attr_e( 'Reviews', 'sfs-hr' ); ?>"><?php echo $emp['reviews_score'] !== null ? esc_html( number_format( $emp['reviews_score'], 1 ) ) . '%' : '—'; ?></td>
                            <td data-label="<?php esc_attr_e( 'Overall', 'sfs-hr' ); ?>"><strong><?php echo esc_html( number_format( $emp['overall_score'], 1 ) ); ?>%</strong></td>
                            <td data-label="<?php esc_attr_e( 'Grade', 'sfs-hr' ); ?>">
                                <span class="sfs-perf-badge <?php echo esc_attr( $emp['overall_grade'] ); ?>">
                                    <?php echo esc_html( $grade_display['label'] ); ?>
                                </span>
                            </td>
                            <td class="sfs-actions-cell">
                                <a href="<?php echo esc_url( add_query_arg( [
                                    'page' => 'sfs-hr-performance-employees',
                                    'employee_id' => $emp['employee_id'],
                                    'start_date' => $start_date,
                                    'end_date' => $end_date,
                                ], admin_url( 'admin.php' ) ) ); ?>" class="button button-small">
                                    <?php esc_html_e( 'Details', 'sfs-hr' ); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render individual employee detail.
     */
    private function render_employee_detail( int $employee_id, string $start_date, string $end_date ): void {
        global $wpdb;

        $employee = $wpdb->get_row( $wpdb->prepare(
            "SELECT e.*, d.name as dept_name
             FROM {$wpdb->prefix}sfs_hr_employees e
             LEFT JOIN {$wpdb->prefix}sfs_hr_departments d ON d.id = e.dept_id
             WHERE e.id = %d",
            $employee_id
        ) );

        if ( ! $employee ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Employee not found.', 'sfs-hr' ) . '</p></div>';
            return;
        }

        $score_data = Performance_Calculator::calculate_overall_score( $employee_id, $start_date, $end_date );
        $attendance_metrics = Attendance_Metrics::get_employee_metrics( $employee_id, $start_date, $end_date );
        $goals = Goals_Service::get_employee_goals( $employee_id );
        $alerts = Alerts_Service::get_employee_alerts( $employee_id, 'active' );

        $employee_name = trim( $employee->first_name . ' ' . $employee->last_name );
        $grade_display = $score_data['overall_grade'] ? Performance_Calculator::get_grade_display( $score_data['overall_grade'] ) : null;

        ?>
        <div class="wrap sfs-perf-wrap">
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-performance-employees' ) ); ?>">
                    ← <?php esc_html_e( 'Back to Employees', 'sfs-hr' ); ?>
                </a>
            </p>

            <div class="sfs-perf-header">
                <div>
                    <h1><?php echo esc_html( $employee_name ); ?></h1>
                    <p style="color: #666; margin: 5px 0;">
                        <?php echo esc_html( $employee->employee_code ); ?> •
                        <?php echo esc_html( $employee->dept_name ?? __( 'General', 'sfs-hr' ) ); ?>
                    </p>
                </div>
                <?php if ( $grade_display ) : ?>
                <div>
                    <span class="sfs-perf-badge <?php echo esc_attr( $score_data['overall_grade'] ); ?>" style="font-size: 16px; padding: 8px 16px;">
                        <?php echo esc_html( $grade_display['label'] ); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Summary Cards -->
            <div class="sfs-perf-cards">
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Overall Score', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo $score_data['overall_score'] !== null ? esc_html( number_format( $score_data['overall_score'], 1 ) ) . '%' : '—'; ?></div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Attendance', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo $score_data['components']['attendance']['normalized'] !== null ? esc_html( number_format( $score_data['components']['attendance']['normalized'], 1 ) ) . '%' : '—'; ?></div>
                    <div class="sub">
                        <?php echo esc_html( sprintf(
                            __( 'Late: %d, Early: %d, Absent: %d, Brk Delay: %d', 'sfs-hr' ),
                            $attendance_metrics['late_count'] ?? 0,
                            $attendance_metrics['early_leave_count'] ?? 0,
                            $attendance_metrics['days_absent'] ?? 0,
                            $attendance_metrics['break_delay_count'] ?? 0
                        ) ); ?>
                    </div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Goals Progress', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo $score_data['components']['goals']['normalized'] !== null ? esc_html( number_format( $score_data['components']['goals']['normalized'], 1 ) ) . '%' : '—'; ?></div>
                    <div class="sub">
                        <?php echo esc_html( sprintf(
                            __( '%d active goals', 'sfs-hr' ),
                            $score_data['components']['goals']['details']['active_goals'] ?? 0
                        ) ); ?>
                    </div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Review Score', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo $score_data['components']['reviews']['raw_score'] !== null ? esc_html( number_format( $score_data['components']['reviews']['raw_score'], 1 ) ) . '/5' : '—'; ?></div>
                </div>
            </div>

            <!-- Commitment Detail Breakdown -->
            <div class="sfs-perf-section" style="margin-bottom: 20px;">
                <h2><?php esc_html_e( 'Commitment Details', 'sfs-hr' ); ?></h2>
                <p style="color: #666; margin-bottom: 15px;">
                    <?php echo esc_html( sprintf(
                        __( 'Period: %s to %s | Working Days: %d | Present: %d | Absent: %d | Late: %d | Early Leave: %d | Incomplete: %d | Break Delay: %d | No Break: %d', 'sfs-hr' ),
                        wp_date( 'M j, Y', strtotime( $start_date ) ),
                        wp_date( 'M j, Y', strtotime( $end_date ) ),
                        $attendance_metrics['total_working_days'] ?? 0,
                        $attendance_metrics['days_present'] ?? 0,
                        $attendance_metrics['days_absent'] ?? 0,
                        $attendance_metrics['late_count'] ?? 0,
                        $attendance_metrics['early_leave_count'] ?? 0,
                        $attendance_metrics['incomplete_count'] ?? 0,
                        $attendance_metrics['break_delay_count'] ?? 0,
                        $attendance_metrics['no_break_taken_count'] ?? 0
                    ) ); ?>
                </p>
                <?php
                $daily = $attendance_metrics['daily_breakdown'] ?? [];
                // Filter to show only days with issues (absent, late, early leave, incomplete, break delay, no break)
                $issues = array_filter( $daily, function( $d ) {
                    if ( $d['status'] === 'absent' ) return true;
                    if ( ! empty( $d['flags'] ) && ( in_array( 'late', $d['flags'], true ) || in_array( 'left_early', $d['flags'], true ) || in_array( 'incomplete', $d['flags'], true ) || in_array( 'break_delay', $d['flags'], true ) || in_array( 'no_break_taken', $d['flags'], true ) ) ) return true;
                    return $d['status'] === 'incomplete';
                } );
                ?>
                <?php if ( empty( $issues ) ) : ?>
                    <p style="color: #22c55e;">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'No attendance issues during this period.', 'sfs-hr' ); ?>
                    </p>
                <?php else : ?>
                    <div class="sfs-perf-table sfs-commitment-table">
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Date', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Day', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Flags', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Clock In', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Clock Out', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Worked', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Brk Delay', 'sfs-hr' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $issues as $day ) :
                                    $status_colors = [
                                        'absent' => '#ef4444', 'late' => '#f59e0b', 'left_early' => '#f59e0b',
                                        'incomplete' => '#f97316', 'present' => '#22c55e',
                                    ];
                                    $s_color = $status_colors[ $day['status'] ] ?? '#666';
                                    $flag_labels = [];
                                    foreach ( (array) $day['flags'] as $f ) {
                                        $flag_labels[] = __( ucfirst( str_replace( '_', ' ', $f ) ), 'sfs-hr' );
                                    }
                                    $hours   = floor( $day['minutes'] / 60 );
                                    $mins    = $day['minutes'] % 60;
                                ?>
                                <tr>
                                    <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $day['date'] ) ) ); ?></td>
                                    <td><?php echo esc_html( wp_date( 'l', strtotime( $day['date'] ) ) ); ?></td>
                                    <td style="color: <?php echo esc_attr( $s_color ); ?>; font-weight: 600;">
                                        <?php echo esc_html( __( ucfirst( str_replace( '_', ' ', $day['status'] ) ), 'sfs-hr' ) ); ?>
                                    </td>
                                    <td><?php echo esc_html( implode( ', ', $flag_labels ) ?: '—' ); ?></td>
                                    <td><?php echo $day['in_time'] ? esc_html( wp_date( 'H:i', strtotime( $day['in_time'] ) ) ) : '—'; ?></td>
                                    <td><?php echo $day['out_time'] ? esc_html( wp_date( 'H:i', strtotime( $day['out_time'] ) ) ) : '—'; ?></td>
                                    <td><?php echo $day['minutes'] > 0 ? esc_html( sprintf( __( '%dh %dm', 'sfs-hr' ), $hours, $mins ) ) : '—'; ?></td>
                                    <td style="color: <?php echo ( $day['break_delay_minutes'] ?? 0 ) > 0 ? '#f59e0b' : ( ( $day['no_break_taken'] ?? 0 ) ? '#ef4444' : '#666' ); ?>; font-weight: <?php echo ( ( $day['break_delay_minutes'] ?? 0 ) > 0 || ( $day['no_break_taken'] ?? 0 ) ) ? '600' : 'normal'; ?>;">
                                        <?php
                                        $bd = (int) ( $day['break_delay_minutes'] ?? 0 );
                                        $nb = (int) ( $day['no_break_taken'] ?? 0 );
                                        if ( $bd > 0 ) {
                                            echo esc_html( sprintf( __( '%dm', 'sfs-hr' ), $bd ) );
                                            if ( in_array( 'break_delay', (array) $day['flags'], true ) && ! $nb ) {
                                                // Has break_end punch but was late
                                            }
                                        } elseif ( $nb ) {
                                            esc_html_e( 'No break', 'sfs-hr' );
                                        } else {
                                            echo '—';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p style="color: #666; margin-top: 10px; font-size: 12px;">
                        <?php echo esc_html( sprintf( __( 'Showing %d day(s) with attendance issues.', 'sfs-hr' ), count( $issues ) ) ); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="sfs-perf-grid">
                <!-- Goals -->
                <div class="sfs-perf-section">
                    <h2><?php esc_html_e( 'Goals', 'sfs-hr' ); ?></h2>
                    <?php if ( empty( $goals ) ) : ?>
                        <p style="color: #666;"><?php esc_html_e( 'No goals assigned.', 'sfs-hr' ); ?></p>
                    <?php else : ?>
                        <?php foreach ( $goals as $goal ) : ?>
                        <div style="margin-bottom: 15px; padding: 10px; background: #f9fafb; border-radius: 6px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <strong><?php echo esc_html( $goal->title ); ?></strong>
                                <span class="sfs-perf-badge <?php echo $goal->status === 'completed' ? 'excellent' : ( $goal->status === 'active' ? 'good' : 'fair' ); ?>">
                                    <?php echo esc_html( __( ucfirst( $goal->status ), 'sfs-hr' ) ); ?>
                                </span>
                            </div>
                            <div class="sfs-perf-progress">
                                <div class="sfs-perf-progress-bar" style="width: <?php echo (int) $goal->progress; ?>%;"></div>
                            </div>
                            <small style="color: #666;"><?php echo esc_html( sprintf( __( '%s%% complete', 'sfs-hr' ), $goal->progress ) ); ?></small>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Alerts -->
                <div class="sfs-perf-section">
                    <h2><?php esc_html_e( 'Active Alerts', 'sfs-hr' ); ?></h2>
                    <?php if ( empty( $alerts ) ) : ?>
                        <p style="color: #22c55e;">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php esc_html_e( 'No active alerts', 'sfs-hr' ); ?>
                        </p>
                    <?php else : ?>
                        <?php foreach ( $alerts as $alert ) : ?>
                        <div class="sfs-perf-alert <?php echo esc_attr( $alert->severity ); ?>">
                            <div>
                                <strong><?php echo esc_html( $alert->title ); ?></strong><br>
                                <small><?php echo esc_html( $alert->message ); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render goals page.
     */
    public function render_goals(): void {
        global $wpdb;

        $employee_id = isset( $_GET['employee_id'] ) ? (int) $_GET['employee_id'] : 0;
        $status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';

        // Get employees for filter
        $employees = $wpdb->get_results(
            "SELECT id, employee_code, first_name, last_name
             FROM {$wpdb->prefix}sfs_hr_employees
             WHERE status = 'active'
             ORDER BY first_name, last_name"
        );

        // Get goals
        if ( $employee_id ) {
            $goals = Goals_Service::get_employee_goals( $employee_id, $status );
        } else {
            // Get all goals
            $where = [];
            if ( $status ) {
                $where[] = $wpdb->prepare( "g.status = %s", $status );
            }
            $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

            $goals = $wpdb->get_results(
                "SELECT g.*, e.first_name, e.last_name, e.employee_code
                 FROM {$wpdb->prefix}sfs_hr_performance_goals g
                 JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = g.employee_id
                 {$where_sql}
                 ORDER BY g.status ASC, g.target_date ASC"
            );
        }

        ?>
        <div class="wrap sfs-perf-wrap">
            <div class="sfs-perf-header">
                <h1><?php esc_html_e( 'Goals Management', 'sfs-hr' ); ?></h1>
                <button type="button" class="button button-primary" onclick="document.getElementById('add-goal-form').style.display='block'">
                    <?php esc_html_e( '+ Add Goal', 'sfs-hr' ); ?>
                </button>
            </div>

            <!-- Add Goal Form -->
            <div id="add-goal-form" class="sfs-perf-section" style="display: none;">
                <h2><?php esc_html_e( 'Add New Goal', 'sfs-hr' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="sfs_hr_save_goal">
                    <?php wp_nonce_field( 'sfs_hr_save_goal' ); ?>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                            <td>
                                <select name="employee_id" required>
                                    <option value=""><?php esc_html_e( 'Select Employee', 'sfs-hr' ); ?></option>
                                    <?php foreach ( $employees as $emp ) : ?>
                                    <option value="<?php echo (int) $emp->id; ?>">
                                        <?php echo esc_html( $emp->first_name . ' ' . $emp->last_name . ' (' . $emp->employee_code . ')' ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Goal Title', 'sfs-hr' ); ?></th>
                            <td><input type="text" name="title" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Description', 'sfs-hr' ); ?></th>
                            <td><textarea name="description" rows="3" class="large-text"></textarea></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Target Date', 'sfs-hr' ); ?></th>
                            <td><input type="date" name="target_date"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Weight', 'sfs-hr' ); ?></th>
                            <td><input type="number" name="weight" min="1" max="100" value="100"> %</td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Goal', 'sfs-hr' ); ?></button>
                        <button type="button" class="button" onclick="document.getElementById('add-goal-form').style.display='none'"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></button>
                    </p>
                </form>
            </div>

            <!-- Filters -->
            <form method="get" class="sfs-perf-filters">
                <input type="hidden" name="page" value="sfs-hr-performance-goals">
                <label>
                    <?php esc_html_e( 'Employee:', 'sfs-hr' ); ?>
                    <select name="employee_id">
                        <option value=""><?php esc_html_e( 'All Employees', 'sfs-hr' ); ?></option>
                        <?php foreach ( $employees as $emp ) : ?>
                        <option value="<?php echo (int) $emp->id; ?>" <?php selected( $employee_id, $emp->id ); ?>>
                            <?php echo esc_html( $emp->first_name . ' ' . $emp->last_name ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?php esc_html_e( 'Status:', 'sfs-hr' ); ?>
                    <select name="status">
                        <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
                        <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'sfs-hr' ); ?></option>
                        <option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'sfs-hr' ); ?></option>
                        <option value="on_hold" <?php selected( $status, 'on_hold' ); ?>><?php esc_html_e( 'On Hold', 'sfs-hr' ); ?></option>
                        <option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'sfs-hr' ); ?></option>
                    </select>
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'sfs-hr' ); ?></button>
            </form>

            <!-- Goals Table -->
            <div class="sfs-perf-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Goal', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Progress', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Target Date', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $goals ) ) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'No goals found.', 'sfs-hr' ); ?></td>
                        </tr>
                        <?php else : ?>
                        <?php foreach ( $goals as $goal ) : ?>
                        <tr>
                            <td>
                                <?php if ( isset( $goal->first_name ) ) : ?>
                                    <?php echo esc_html( $goal->first_name . ' ' . $goal->last_name ); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html( $goal->title ); ?></strong>
                                <?php if ( $goal->description ) : ?>
                                    <br><small style="color: #666;"><?php echo esc_html( wp_trim_words( $goal->description, 10 ) ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="min-width: 150px;">
                                <div class="sfs-perf-progress">
                                    <div class="sfs-perf-progress-bar" style="width: <?php echo (int) $goal->progress; ?>%;"></div>
                                </div>
                                <small><?php echo esc_html( $goal->progress ); ?>%</small>
                            </td>
                            <td>
                                <?php echo $goal->target_date ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $goal->target_date ) ) ) : '—'; ?>
                            </td>
                            <td>
                                <span class="sfs-perf-badge <?php echo $goal->status === 'completed' ? 'excellent' : ( $goal->status === 'active' ? 'good' : 'fair' ); ?>">
                                    <?php echo esc_html( __( ucfirst( str_replace( '_', ' ', $goal->status ) ), 'sfs-hr' ) ); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin-post.php?action=sfs_hr_delete_goal&id=' . $goal->id ),
                                    'sfs_hr_delete_goal_' . $goal->id
                                ) ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Delete this goal?', 'sfs-hr' ); ?>');">
                                    <?php esc_html_e( 'Delete', 'sfs-hr' ); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render reviews page.
     */
    public function render_reviews(): void {
        global $wpdb;

        $reviews = $wpdb->get_results(
            "SELECT r.*, e.first_name, e.last_name, e.employee_code,
                    reviewer.display_name as reviewer_name
             FROM {$wpdb->prefix}sfs_hr_performance_reviews r
             JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = r.employee_id
             LEFT JOIN {$wpdb->users} reviewer ON reviewer.ID = r.reviewer_id
             ORDER BY r.created_at DESC
             LIMIT 50"
        );

        ?>
        <div class="wrap sfs-perf-wrap">
            <h1><?php esc_html_e( 'Performance Reviews', 'sfs-hr' ); ?></h1>

            <div class="sfs-perf-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Period', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Reviewer', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Rating', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $reviews ) ) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'No reviews found.', 'sfs-hr' ); ?></td>
                        </tr>
                        <?php else : ?>
                        <?php foreach ( $reviews as $review ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $review->first_name . ' ' . $review->last_name ); ?></strong><br>
                                <small style="color: #666;"><?php echo esc_html( $review->employee_code ); ?></small>
                            </td>
                            <td>
                                <?php echo esc_html( wp_date( 'M Y', strtotime( $review->period_start ) ) ); ?> -
                                <?php echo esc_html( wp_date( 'M Y', strtotime( $review->period_end ) ) ); ?>
                            </td>
                            <td><?php echo esc_html( __( ucfirst( $review->review_type ), 'sfs-hr' ) ); ?></td>
                            <td><?php echo esc_html( $review->reviewer_name ); ?></td>
                            <td>
                                <?php if ( $review->overall_rating ) : ?>
                                    <strong><?php echo esc_html( number_format( $review->overall_rating, 1 ) ); ?>/5</strong>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="sfs-perf-badge <?php echo $review->status === 'acknowledged' ? 'excellent' : ( $review->status === 'submitted' ? 'good' : 'fair' ); ?>">
                                    <?php echo esc_html( __( ucfirst( $review->status ), 'sfs-hr' ) ); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Render alerts page.
     */
    public function render_alerts(): void {
        $active_alerts = Alerts_Service::get_active_alerts();
        $stats = Alerts_Service::get_statistics();

        ?>
        <div class="wrap sfs-perf-wrap">
            <h1><?php esc_html_e( 'Performance Alerts', 'sfs-hr' ); ?></h1>

            <!-- Summary Cards -->
            <div class="sfs-perf-cards">
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Total Active', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo esc_html( $stats['total_active'] ); ?></div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Critical', 'sfs-hr' ); ?></h3>
                    <div class="value" style="color: #ef4444;"><?php echo esc_html( $stats['by_severity']['critical'] ); ?></div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Warning', 'sfs-hr' ); ?></h3>
                    <div class="value" style="color: #f59e0b;"><?php echo esc_html( $stats['by_severity']['warning'] ); ?></div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Info', 'sfs-hr' ); ?></h3>
                    <div class="value" style="color: #3b82f6;"><?php echo esc_html( $stats['by_severity']['info'] ); ?></div>
                </div>
            </div>

            <!-- Alerts List -->
            <div class="sfs-perf-section">
                <?php if ( empty( $active_alerts ) ) : ?>
                    <p style="text-align: center; color: #22c55e; padding: 40px;">
                        <span class="dashicons dashicons-yes-alt" style="font-size: 48px; width: 48px; height: 48px;"></span><br>
                        <?php esc_html_e( 'No active alerts. All employees are performing well!', 'sfs-hr' ); ?>
                    </p>
                <?php else : ?>
                    <?php foreach ( $active_alerts as $alert ) : ?>
                    <div class="sfs-perf-alert <?php echo esc_attr( $alert->severity ); ?>" style="margin-bottom: 15px;">
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <div>
                                    <strong><?php echo esc_html( $alert->title ); ?></strong>
                                    <span class="sfs-perf-badge <?php echo esc_attr( $alert->severity ); ?>" style="margin-left: 10px;">
                                        <?php echo esc_html( __( ucfirst( $alert->severity ), 'sfs-hr' ) ); ?>
                                    </span>
                                </div>
                                <small style="color: #666;">
                                    <?php echo esc_html( human_time_diff( strtotime( $alert->created_at ) ) ); ?> <?php esc_html_e( 'ago', 'sfs-hr' ); ?>
                                </small>
                            </div>
                            <p style="margin: 10px 0 5px;">
                                <strong><?php echo esc_html( $alert->first_name . ' ' . $alert->last_name ); ?></strong>
                                (<?php echo esc_html( $alert->employee_code ); ?>)
                            </p>
                            <p style="margin: 0; color: #666;"><?php echo esc_html( $alert->message ); ?></p>
                        </div>
                        <div style="display: flex; gap: 10px; margin-left: 20px;">
                            <a href="<?php echo esc_url( wp_nonce_url(
                                admin_url( 'admin-post.php?action=sfs_hr_acknowledge_alert&id=' . $alert->id ),
                                'sfs_hr_acknowledge_alert_' . $alert->id
                            ) ); ?>" class="button">
                                <?php esc_html_e( 'Acknowledge', 'sfs-hr' ); ?>
                            </a>
                            <a href="<?php echo esc_url( wp_nonce_url(
                                admin_url( 'admin-post.php?action=sfs_hr_resolve_alert&id=' . $alert->id ),
                                'sfs_hr_resolve_alert_' . $alert->id
                            ) ); ?>" class="button button-primary">
                                <?php esc_html_e( 'Resolve', 'sfs-hr' ); ?>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings page.
     */
    public function render_settings(): void {
        $settings = PerformanceModule::get_settings();

        ?>
        <div class="wrap sfs-perf-wrap">
            <h1><?php esc_html_e( 'Performance Settings', 'sfs-hr' ); ?></h1>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="sfs_hr_save_performance_settings">
                <?php wp_nonce_field( 'sfs_hr_save_performance_settings' ); ?>

                <div class="sfs-perf-section">
                    <h2><?php esc_html_e( 'Score Weights', 'sfs-hr' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Configure how each component contributes to the overall performance score. Total must equal 100%.', 'sfs-hr' ); ?></p>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Attendance Weight', 'sfs-hr' ); ?></th>
                            <td>
                                <input type="number" name="weights[attendance]" value="<?php echo (int) $settings['weights']['attendance']; ?>" min="0" max="100"> %
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Goals Weight', 'sfs-hr' ); ?></th>
                            <td>
                                <input type="number" name="weights[goals]" value="<?php echo (int) $settings['weights']['goals']; ?>" min="0" max="100"> %
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Reviews Weight', 'sfs-hr' ); ?></th>
                            <td>
                                <input type="number" name="weights[reviews]" value="<?php echo (int) $settings['weights']['reviews']; ?>" min="0" max="100"> %
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="sfs-perf-section">
                    <h2><?php esc_html_e( 'Attendance Thresholds', 'sfs-hr' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Set the commitment percentage thresholds for each grade.', 'sfs-hr' ); ?></p>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Excellent (≥)', 'sfs-hr' ); ?></th>
                            <td>
                                <input type="number" name="attendance_thresholds[excellent]" value="<?php echo (int) $settings['attendance_thresholds']['excellent']; ?>" min="0" max="100"> %
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Good (≥)', 'sfs-hr' ); ?></th>
                            <td>
                                <input type="number" name="attendance_thresholds[good]" value="<?php echo (int) $settings['attendance_thresholds']['good']; ?>" min="0" max="100"> %
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Fair (≥)', 'sfs-hr' ); ?></th>
                            <td>
                                <input type="number" name="attendance_thresholds[fair]" value="<?php echo (int) $settings['attendance_thresholds']['fair']; ?>" min="0" max="100"> %
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="sfs-perf-section">
                    <h2><?php esc_html_e( 'Alert Settings', 'sfs-hr' ); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Enable Alerts', 'sfs-hr' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="alerts[enabled]" value="1" <?php checked( $settings['alerts']['enabled'] ); ?>>
                                    <?php esc_html_e( 'Automatically generate alerts for performance issues', 'sfs-hr' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Commitment Alert Threshold', 'sfs-hr' ); ?></th>
                            <td>
                                <input type="number" name="alerts[commitment_threshold]" value="<?php echo (int) $settings['alerts']['commitment_threshold']; ?>" min="0" max="100"> %
                                <p class="description"><?php esc_html_e( 'Alert when attendance commitment falls below this percentage.', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Notify', 'sfs-hr' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="alerts[notify_manager]" value="1" <?php checked( $settings['alerts']['notify_manager'] ); ?>>
                                    <?php esc_html_e( 'Department Manager', 'sfs-hr' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="alerts[notify_hr]" value="1" <?php checked( $settings['alerts']['notify_hr'] ); ?>>
                                    <?php esc_html_e( 'HR Team', 'sfs-hr' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="alerts[notify_employee]" value="1" <?php checked( $settings['alerts']['notify_employee'] ); ?>>
                                    <?php esc_html_e( 'Employee', 'sfs-hr' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'sfs-hr' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Handle save settings.
     */
    public function handle_save_settings(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ) );
        }

        check_admin_referer( 'sfs_hr_save_performance_settings' );

        $settings = PerformanceModule::get_settings();

        // Weights
        if ( isset( $_POST['weights'] ) ) {
            $settings['weights'] = [
                'attendance' => (int) $_POST['weights']['attendance'],
                'goals'      => (int) $_POST['weights']['goals'],
                'reviews'    => (int) $_POST['weights']['reviews'],
            ];
        }

        // Thresholds
        if ( isset( $_POST['attendance_thresholds'] ) ) {
            $settings['attendance_thresholds'] = [
                'excellent' => (int) $_POST['attendance_thresholds']['excellent'],
                'good'      => (int) $_POST['attendance_thresholds']['good'],
                'fair'      => (int) $_POST['attendance_thresholds']['fair'],
                'poor'      => 0,
            ];
        }

        // Alerts
        $settings['alerts']['enabled'] = ! empty( $_POST['alerts']['enabled'] );
        $settings['alerts']['commitment_threshold'] = isset( $_POST['alerts']['commitment_threshold'] )
            ? (int) $_POST['alerts']['commitment_threshold'] : 80;
        $settings['alerts']['notify_manager'] = ! empty( $_POST['alerts']['notify_manager'] );
        $settings['alerts']['notify_hr'] = ! empty( $_POST['alerts']['notify_hr'] );
        $settings['alerts']['notify_employee'] = ! empty( $_POST['alerts']['notify_employee'] );

        PerformanceModule::update_settings( $settings );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-settings&updated=1' ) );
        exit;
    }

    /**
     * Handle save goal.
     */
    public function handle_save_goal(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ) );
        }

        check_admin_referer( 'sfs_hr_save_goal' );

        $result = Goals_Service::save_goal( $_POST );

        if ( is_wp_error( $result ) ) {
            wp_die( $result->get_error_message() );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-goals&saved=1' ) );
        exit;
    }

    /**
     * Handle delete goal.
     */
    public function handle_delete_goal(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ) );
        }

        $goal_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        check_admin_referer( 'sfs_hr_delete_goal_' . $goal_id );

        Goals_Service::delete_goal( $goal_id );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-goals&deleted=1' ) );
        exit;
    }

    /**
     * Handle acknowledge alert.
     */
    public function handle_acknowledge_alert(): void {
        $alert_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        check_admin_referer( 'sfs_hr_acknowledge_alert_' . $alert_id );

        Alerts_Service::acknowledge_alert( $alert_id, get_current_user_id() );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-alerts' ) );
        exit;
    }

    /**
     * Handle resolve alert.
     */
    public function handle_resolve_alert(): void {
        $alert_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        check_admin_referer( 'sfs_hr_resolve_alert_' . $alert_id );

        Alerts_Service::resolve_alert( $alert_id );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-alerts' ) );
        exit;
    }
}
