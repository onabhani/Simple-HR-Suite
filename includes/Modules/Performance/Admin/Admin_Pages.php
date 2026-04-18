<?php
namespace SFS\HR\Modules\Performance\Admin;

use SFS\HR\Modules\Performance\PerformanceModule;
use SFS\HR\Modules\Performance\Services\Attendance_Metrics;
use SFS\HR\Modules\Performance\Services\Goals_Service;
use SFS\HR\Modules\Performance\Services\Reviews_Service;
use SFS\HR\Modules\Performance\Services\Performance_Calculator;
use SFS\HR\Modules\Performance\Services\Alerts_Service;
use SFS\HR\Modules\Performance\Services\Goal_Hierarchy_Service;
use SFS\HR\Modules\Performance\Services\Review_Cycle_Service;
use SFS\HR\Modules\Performance\Services\Feedback_360_Service;

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
        add_action( 'admin_post_sfs_hr_save_justification', [ $this, 'handle_save_justification' ] );
        add_action( 'admin_post_sfs_hr_save_objective', [ $this, 'handle_save_objective' ] );
        add_action( 'admin_post_sfs_hr_delete_objective', [ $this, 'handle_delete_objective' ] );
        add_action( 'admin_post_sfs_hr_save_review_cycle', [ $this, 'handle_save_review_cycle' ] );
        add_action( 'admin_post_sfs_hr_activate_review_cycle', [ $this, 'handle_activate_review_cycle' ] );
        add_action( 'admin_post_sfs_hr_save_pip', [ $this, 'handle_save_pip' ] );
        add_action( 'admin_post_sfs_hr_complete_pip', [ $this, 'handle_complete_pip' ] );
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
            __( 'Goals & OKRs', 'sfs-hr' ),
            __( 'Goals & OKRs', 'sfs-hr' ),
            $view_cap,
            'sfs-hr-performance-okrs',
            [ $this, 'render_okrs' ]
        );

        add_submenu_page(
            'sfs-hr-performance',
            __( 'Review Cycles', 'sfs-hr' ),
            __( 'Review Cycles', 'sfs-hr' ),
            $view_cap,
            'sfs-hr-performance-cycles',
            [ $this, 'render_cycles' ]
        );

        add_submenu_page(
            'sfs-hr-performance',
            __( '360° Feedback', 'sfs-hr' ),
            __( '360° Feedback', 'sfs-hr' ),
            $view_cap,
            'sfs-hr-performance-feedback',
            [ $this, 'render_feedback' ]
        );

        add_submenu_page(
            'sfs-hr-performance',
            __( 'Improvement Plans', 'sfs-hr' ),
            __( 'Improvement Plans', 'sfs-hr' ),
            $view_cap,
            'sfs-hr-performance-pips',
            [ $this, 'render_pips' ]
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

        wp_enqueue_style( 'sfs-hr-admin' );
        wp_enqueue_script( 'chart-js' );

        // Inline styles for the performance module
        wp_add_inline_style( 'sfs-hr-admin', $this->get_inline_styles() );
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

            /* Period label */
            .sfs-perf-period-label { color: #666; margin: 4px 0 0; font-size: 13px; }

            /* Delta indicators */
            .sfs-perf-delta { display: inline-block; font-size: 12px; font-weight: 600; margin-right: 4px; }
            .sfs-perf-delta.up { color: #22c55e; }
            .sfs-perf-delta.down { color: #ef4444; }

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

                /* Previous period column: hide on mobile */
                .sfs-prev-col { display: none; }

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

        // Period labels
        $current_label = \SFS\HR\Modules\Attendance\AttendanceModule::format_period_label( [ 'start' => $start_date, 'end' => $end_date ] );

        // Previous period for comparison
        $prev_period = \SFS\HR\Modules\Attendance\AttendanceModule::get_previous_period( $start_date );
        $prev_label  = \SFS\HR\Modules\Attendance\AttendanceModule::format_period_label( $prev_period );

        // Get summary data
        $dept_summary = Performance_Calculator::get_departments_summary( $start_date, $end_date );
        $alerts_stats = Alerts_Service::get_statistics();
        $active_alerts = Alerts_Service::get_active_alerts();

        // Previous period summary for comparison
        $prev_dept_summary = Performance_Calculator::get_departments_summary( $prev_period['start'], $prev_period['end'] );

        // Get employee count
        $emp_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active'"
        );

        // Calculate averages — current period
        $total_avg = 0;
        $dept_with_scores = 0;
        foreach ( $dept_summary as $dept ) {
            if ( $dept['avg_score'] !== null ) {
                $total_avg += $dept['avg_score'];
                $dept_with_scores++;
            }
        }
        $company_avg = $dept_with_scores > 0 ? round( $total_avg / $dept_with_scores, 1 ) : 0;

        // Calculate averages — previous period
        $prev_total_avg = 0;
        $prev_dept_with_scores = 0;
        foreach ( $prev_dept_summary as $dept ) {
            if ( $dept['avg_score'] !== null ) {
                $prev_total_avg += $dept['avg_score'];
                $prev_dept_with_scores++;
            }
        }
        $prev_company_avg = $prev_dept_with_scores > 0 ? round( $prev_total_avg / $prev_dept_with_scores, 1 ) : 0;

        // Build previous-period avg-by-dept lookup for comparison column
        $prev_dept_avg = [];
        foreach ( $prev_dept_summary as $pd ) {
            $prev_dept_avg[ $pd['dept_name'] ] = $pd['avg_score'];
        }

        // Compute company-avg delta
        $company_delta = ( $prev_company_avg > 0 ) ? round( $company_avg - $prev_company_avg, 1 ) : null;
        ?>
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
            <h1><?php esc_html_e( 'Performance Dashboard', 'sfs-hr' ); ?></h1>
            <hr class="wp-header-end">

            <div class="sfs-perf-header">
                <p class="sfs-perf-period-label">
                    <?php echo esc_html( $current_label ); ?>
                </p>
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
                    <a href="<?php echo esc_url( add_query_arg( [
                        'page'       => 'sfs-hr-performance',
                        'start_date' => $prev_period['start'],
                        'end_date'   => $prev_period['end'],
                    ], admin_url( 'admin.php' ) ) ); ?>" class="button" title="<?php echo esc_attr( $prev_label ); ?>">
                        &larr; <?php esc_html_e( 'Previous Period', 'sfs-hr' ); ?>
                    </a>
                </form>
            </div>

            <!-- Summary Cards -->
            <div class="sfs-perf-cards">
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Company Average', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo esc_html( $company_avg ); ?>%</div>
                    <div class="sub">
                        <?php if ( $company_delta !== null ) : ?>
                            <span class="sfs-perf-delta <?php echo $company_delta >= 0 ? 'up' : 'down'; ?>">
                                <?php echo $company_delta >= 0 ? '&#9650;' : '&#9660;'; ?>
                                <?php echo esc_html( abs( $company_delta ) ); ?>%
                            </span>
                            <?php echo esc_html( sprintf( __( 'vs %s', 'sfs-hr' ), $prev_label ) ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Overall Performance', 'sfs-hr' ); ?>
                        <?php endif; ?>
                    </div>
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
                                    <th class="sfs-prev-col"><?php esc_html_e( 'Prev Period', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Distribution', 'sfs-hr' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $dept_summary as $dept ) :
                                    $p_avg   = $prev_dept_avg[ $dept['dept_name'] ] ?? null;
                                    $d_delta = ( $dept['avg_score'] !== null && $p_avg !== null ) ? round( $dept['avg_score'] - $p_avg, 1 ) : null;
                                ?>
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
                                    <td class="sfs-prev-col">
                                        <?php if ( $p_avg !== null ) : ?>
                                            <?php echo esc_html( number_format( $p_avg, 1 ) ); ?>%
                                            <?php if ( $d_delta !== null && $d_delta != 0 ) : ?>
                                                <span class="sfs-perf-delta <?php echo $d_delta >= 0 ? 'up' : 'down'; ?>">
                                                    <?php echo $d_delta >= 0 ? '&#9650;' : '&#9660;'; ?>
                                                    <?php echo esc_html( abs( $d_delta ) ); ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="sfs-dist-item" title="<?php esc_attr_e( 'Exceptional', 'sfs-hr' ); ?>" style="color: #22c55e;"><span class="sfs-dist-label"><?php esc_html_e( 'Exc', 'sfs-hr' ); ?> </span>●<?php echo esc_html( $dept['grade_distribution']['exceptional'] ); ?></span>
                                        <span class="sfs-dist-item" title="<?php esc_attr_e( 'Exceeds', 'sfs-hr' ); ?>" style="color: #3b82f6;"><span class="sfs-dist-label"><?php esc_html_e( 'Exc+', 'sfs-hr' ); ?> </span>●<?php echo esc_html( $dept['grade_distribution']['exceeds'] ); ?></span>
                                        <span class="sfs-dist-item" title="<?php esc_attr_e( 'Meets', 'sfs-hr' ); ?>" style="color: #f59e0b;"><span class="sfs-dist-label"><?php esc_html_e( 'Meet', 'sfs-hr' ); ?> </span>●<?php echo esc_html( $dept['grade_distribution']['meets'] ); ?></span>
                                        <span class="sfs-dist-item" title="<?php esc_attr_e( 'Needs Improvement', 'sfs-hr' ); ?>" style="color: #ef4444;"><span class="sfs-dist-label"><?php esc_html_e( 'Low', 'sfs-hr' ); ?> </span>●<?php echo esc_html( $dept['grade_distribution']['needs_improvement'] ); ?></span>
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
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
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
            echo '<div class="wrap sfs-hr-wrap"><p>' . esc_html__( 'Employee not found.', 'sfs-hr' ) . '</p></div>';
            return;
        }

        $score_data = Performance_Calculator::calculate_overall_score( $employee_id, $start_date, $end_date );
        $attendance_metrics = Attendance_Metrics::get_employee_metrics( $employee_id, $start_date, $end_date );
        $goals = Goals_Service::get_employee_goals( $employee_id );

        // Refresh attendance-related alerts so stored data matches live metrics
        Alerts_Service::refresh_employee_attendance_alerts( $employee_id );
        $alerts = Alerts_Service::get_employee_alerts( $employee_id, 'active' );

        $employee_name = trim( $employee->first_name . ' ' . $employee->last_name );
        $grade_display = $score_data['overall_grade'] ? Performance_Calculator::get_grade_display( $score_data['overall_grade'] ) : null;
        $profile_url = admin_url( 'admin.php?page=sfs-hr-employee-profile&employee_id=' . (int) $employee_id );

        // Justification logic
        $settings            = PerformanceModule::get_settings();
        $commit_threshold    = (float) ( $settings['alerts']['commitment_threshold'] ?? 80 );
        $is_below_threshold  = $score_data['overall_score'] !== null && $score_data['overall_score'] < $commit_threshold;

        $justification_record = null;
        $can_write_justification = false;
        $justification_locked    = false;

        if ( $is_below_threshold ) {
            // Load existing justification for this period
            $justification_record = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_justifications
                 WHERE employee_id = %d AND period_start = %s AND period_end = %s",
                $employee_id,
                $start_date,
                $end_date
            ) );

            // Check if current user is the department's HR responsible
            $hr_responsible_user_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT hr_responsible_user_id FROM {$wpdb->prefix}sfs_hr_departments WHERE id = %d",
                $employee->dept_id
            ) );
            $is_hr_responsible = $hr_responsible_user_id && (int) get_current_user_id() === $hr_responsible_user_id;

            // Justification window: opens 5 days before period end, closes at period end.
            $tz       = wp_timezone();
            $today    = new \DateTimeImmutable( 'now', $tz );
            $parsed_end = \DateTime::createFromFormat( 'Y-m-d', $end_date );
            if ( ! $parsed_end || $parsed_end->format( 'Y-m-d' ) !== $end_date ) {
                $within_deadline = false;
            } else {
                $period_end_dt = new \DateTimeImmutable( $end_date, $tz );
                $window_open   = $period_end_dt->modify( '-5 days' );
                $today_str     = $today->format( 'Y-m-d' );
                $within_deadline = $today_str >= $window_open->format( 'Y-m-d' ) && $today_str <= $period_end_dt->format( 'Y-m-d' );
            }

            $can_write_justification = $is_hr_responsible && $within_deadline;
            $justification_locked    = ! $within_deadline;

            // Determine read access: HR responsible, dept manager, or GM/admin
            $manager_user_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT manager_user_id FROM {$wpdb->prefix}sfs_hr_departments WHERE id = %d",
                $employee->dept_id
            ) );
            $gm_user_id = (int) get_option( 'sfs_hr_leave_gm_approver', 0 );
            $current_uid = (int) get_current_user_id();

            $can_read_justification = $is_hr_responsible
                || ( $manager_user_id && $current_uid === $manager_user_id )
                || ( $gm_user_id && $current_uid === $gm_user_id )
                || current_user_can( 'manage_options' );
        } else {
            $can_read_justification = false;
        }

        ?>
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Performance Details', 'sfs-hr' ); ?></h1>
            <hr class="wp-header-end">

            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-performance-employees' ) ); ?>">
                    ← <?php esc_html_e( 'Back to Employees', 'sfs-hr' ); ?>
                </a>
            </p>

            <div class="sfs-perf-header">
                <div>
                    <h2 style="margin:0;font-size:1.5em;">
                        <?php echo esc_html( $employee_name ); ?>
                        <a href="<?php echo esc_url( $profile_url ); ?>" title="<?php esc_attr_e( 'View employee profile', 'sfs-hr' ); ?>" aria-label="<?php echo esc_attr__( 'View employee profile', 'sfs-hr' ); ?>" style="margin-left:4px;color:#0284c7;text-decoration:none;font-size:18px;">
                            <span class="dashicons dashicons-id-alt" style="font-size:18px;width:18px;height:18px;vertical-align:middle;"></span>
                        </a>
                    </h2>
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
                <?php
                // Early Leave Request stats card
                $elr_table = $wpdb->prefix . 'sfs_hr_early_leave_requests';
                $elr_stats = $wpdb->get_row( $wpdb->prepare(
                    "SELECT
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
                     FROM {$elr_table}
                     WHERE employee_id = %d AND request_date BETWEEN %s AND %s",
                    $employee_id,
                    $start_date,
                    $end_date
                ) );
                $elr_total    = (int) ( $elr_stats->total ?? 0 );
                $elr_approved = (int) ( $elr_stats->approved ?? 0 );
                $elr_rejected = (int) ( $elr_stats->rejected ?? 0 );
                $elr_pending  = (int) ( $elr_stats->pending ?? 0 );
                ?>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Early Leave Requests', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo esc_html( $elr_total ); ?></div>
                    <div class="sub">
                        <?php echo esc_html( sprintf(
                            /* translators: %1$d = approved, %2$d = rejected, %3$d = pending */
                            __( 'Approved: %1$d, Rejected: %2$d, Pending: %3$d', 'sfs-hr' ),
                            $elr_approved,
                            $elr_rejected,
                            $elr_pending
                        ) ); ?>
                    </div>
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

            <?php if ( $is_below_threshold && $can_read_justification ) : ?>
            <!-- HR Justification -->
            <div class="sfs-perf-section" style="margin-top: 20px;">
                <h2 style="color: #d63638;">
                    <span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
                    <?php esc_html_e( 'HR Justification', 'sfs-hr' ); ?>
                    <?php if ( $justification_locked ) : ?>
                        <span style="font-size: 12px; font-weight: normal; color: #666; margin-left: 8px;">
                            <span class="dashicons dashicons-lock" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                            <?php esc_html_e( 'Locked', 'sfs-hr' ); ?>
                        </span>
                    <?php endif; ?>
                </h2>
                <p style="color: #666; margin-bottom: 12px; font-size: 13px;">
                    <?php printf(
                        esc_html__( 'This employee\'s overall score (%.1f%%) is below the %s%% threshold. A justification is required from the department HR Responsible.', 'sfs-hr' ),
                        $score_data['overall_score'],
                        number_format( $commit_threshold, 0 )
                    ); ?>
                    <?php if ( ! $justification_locked ) : ?>
                        <br>
                        <?php printf(
                            esc_html__( 'Deadline: %s', 'sfs-hr' ),
                            '<strong>' . esc_html( wp_date( 'M j, Y', strtotime( $end_date ) ) ) . '</strong>'
                        ); ?>
                    <?php endif; ?>
                </p>

                <?php if ( $can_write_justification ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sfs_hr_save_justification" />
                        <?php wp_nonce_field( 'sfs_hr_save_justification_' . $employee_id ); ?>
                        <input type="hidden" name="employee_id" value="<?php echo (int) $employee_id; ?>" />
                        <input type="hidden" name="period_start" value="<?php echo esc_attr( $start_date ); ?>" />
                        <input type="hidden" name="period_end" value="<?php echo esc_attr( $end_date ); ?>" />
                        <textarea name="justification" rows="4" required style="width: 100%; border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px; font-size: 14px;" placeholder="<?php esc_attr_e( 'Provide justification for this employee\'s below-threshold performance...', 'sfs-hr' ); ?>"><?php echo esc_textarea( $justification_record->justification ?? '' ); ?></textarea>
                        <div style="margin-top: 10px;">
                            <button type="submit" class="button button-primary">
                                <?php echo $justification_record ? esc_html__( 'Update Justification', 'sfs-hr' ) : esc_html__( 'Save Justification', 'sfs-hr' ); ?>
                            </button>
                            <?php if ( $justification_record ) : ?>
                                <span style="color: #666; font-size: 12px; margin-left: 10px;">
                                    <?php printf(
                                        esc_html__( 'Last updated: %s by %s', 'sfs-hr' ),
                                        esc_html( wp_date( 'M j, Y g:i A', strtotime( $justification_record->updated_at ) ) ),
                                        esc_html( get_userdata( $justification_record->written_by )->display_name ?? __( 'Unknown', 'sfs-hr' ) )
                                    ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php elseif ( $justification_record ) : ?>
                    <div style="background: #f9fafb; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px;">
                        <p style="margin: 0 0 8px; font-size: 14px; color: #1d2327; white-space: pre-wrap;"><?php echo esc_html( $justification_record->justification ); ?></p>
                        <p style="margin: 0; font-size: 12px; color: #666;">
                            <?php printf(
                                esc_html__( 'Written by %s on %s', 'sfs-hr' ),
                                '<strong>' . esc_html( get_userdata( $justification_record->written_by )->display_name ?? __( 'Unknown', 'sfs-hr' ) ) . '</strong>',
                                esc_html( wp_date( 'M j, Y g:i A', strtotime( $justification_record->updated_at ) ) )
                            ); ?>
                        </p>
                    </div>
                <?php else : ?>
                    <p style="color: #996800; font-style: italic;">
                        <span class="dashicons dashicons-info" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
                        <?php if ( $justification_locked ) : ?>
                            <?php esc_html_e( 'No justification was provided for this period.', 'sfs-hr' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Awaiting justification from the department HR Responsible.', 'sfs-hr' ); ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
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
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
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
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
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
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
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
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
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
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ), '', 403 );
        }

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
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ), '', 403 );
        }

        $alert_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        check_admin_referer( 'sfs_hr_resolve_alert_' . $alert_id );

        Alerts_Service::resolve_alert( $alert_id );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-alerts' ) );
        exit;
    }

    /**
     * Handle saving a performance justification.
     *
     * Only the department's HR Responsible can write, only for below-threshold
     * employees, and only until 1 day after the period end (the 26th).
     */
    public function handle_save_justification(): void {
        global $wpdb;

        $employee_id  = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;
        $start_date   = isset( $_POST['period_start'] ) ? sanitize_text_field( $_POST['period_start'] ) : '';
        $end_date     = isset( $_POST['period_end'] ) ? sanitize_text_field( $_POST['period_end'] ) : '';
        $justification = isset( $_POST['justification'] ) ? sanitize_textarea_field( $_POST['justification'] ) : '';

        check_admin_referer( 'sfs_hr_save_justification_' . $employee_id );

        $redirect_url = add_query_arg( [
            'page'        => 'sfs-hr-performance-employees',
            'employee_id' => $employee_id,
            'start_date'  => $start_date,
            'end_date'    => $end_date,
        ], admin_url( 'admin.php' ) );

        if ( $justification === '' ) {
            wp_die( __( 'Justification text is required.', 'sfs-hr' ), '', 400 );
        }

        // Validate employee exists and get their department
        $employee = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, dept_id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d",
            $employee_id
        ) );

        if ( ! $employee ) {
            wp_safe_redirect( $redirect_url );
            exit;
        }

        // Check: current user must be the department's HR responsible
        $hr_responsible_user_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT hr_responsible_user_id FROM {$wpdb->prefix}sfs_hr_departments WHERE id = %d",
            $employee->dept_id
        ) );

        if ( ! $hr_responsible_user_id || (int) get_current_user_id() !== $hr_responsible_user_id ) {
            wp_die( __( 'Only the department HR Responsible can write justifications.', 'sfs-hr' ), '', 403 );
        }

        // Justification window: opens 5 days before period end, closes at period end.
        $tz       = wp_timezone();
        $today    = new \DateTimeImmutable( 'now', $tz );
        $parsed_end = \DateTime::createFromFormat( 'Y-m-d', $end_date );
        if ( ! $parsed_end || $parsed_end->format( 'Y-m-d' ) !== $end_date ) {
            wp_die( __( 'Invalid period end date.', 'sfs-hr' ), '', 400 );
        }
        $period_end_dt = new \DateTimeImmutable( $end_date, $tz );
        $window_open   = $period_end_dt->modify( '-5 days' );
        $today_str     = $today->format( 'Y-m-d' );

        if ( $today_str < $window_open->format( 'Y-m-d' ) ) {
            wp_die( __( 'The justification window is not open yet.', 'sfs-hr' ), '', 403 );
        }
        if ( $today_str > $period_end_dt->format( 'Y-m-d' ) ) {
            wp_die( __( 'The justification deadline for this period has passed.', 'sfs-hr' ), '', 403 );
        }

        // Check: employee must be below threshold
        $settings  = PerformanceModule::get_settings();
        $threshold = (float) ( $settings['alerts']['commitment_threshold'] ?? 80 );
        $score     = Performance_Calculator::calculate_overall_score( $employee_id, $start_date, $end_date );

        if ( $score['overall_score'] === null || $score['overall_score'] >= $threshold ) {
            wp_die( __( 'Justification is only required for employees below the performance threshold.', 'sfs-hr' ), '', 403 );
        }

        // Upsert justification
        $table = $wpdb->prefix . 'sfs_hr_performance_justifications';
        $now   = current_time( 'mysql' );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE employee_id = %d AND period_start = %s AND period_end = %s",
            $employee_id,
            $start_date,
            $end_date
        ) );

        if ( $existing ) {
            $wpdb->update(
                $table,
                [
                    'justification' => $justification,
                    'written_by'    => get_current_user_id(),
                    'updated_at'    => $now,
                ],
                [ 'id' => $existing ]
            );
        } else {
            $wpdb->insert( $table, [
                'employee_id'   => $employee_id,
                'period_start'  => $start_date,
                'period_end'    => $end_date,
                'justification' => $justification,
                'written_by'    => get_current_user_id(),
                'created_at'    => $now,
                'updated_at'    => $now,
            ] );
        }

        wp_safe_redirect( add_query_arg( 'justification_saved', '1', $redirect_url ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // M4: Goals & OKRs
    // -------------------------------------------------------------------------

    /**
     * Render Goals & OKRs page.
     */
    public function render_okrs(): void {
        global $wpdb;

        $level_filter = isset( $_GET['level'] ) ? sanitize_text_field( $_GET['level'] ) : '';
        $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';

        // Build WHERE clauses
        $where = [];
        $where_vals = [];
        if ( $level_filter ) {
            $where[] = 'o.level = %s';
            $where_vals[] = $level_filter;
        }
        if ( $status_filter ) {
            $where[] = 'o.status = %s';
            $where_vals[] = $status_filter;
        }
        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $sql = "SELECT o.*, u.display_name as creator_name
                FROM {$wpdb->prefix}sfs_hr_performance_objectives o
                LEFT JOIN {$wpdb->users} u ON u.ID = o.created_by
                {$where_sql}
                ORDER BY o.level ASC, o.parent_id ASC, o.id ASC";

        $objectives = $where_vals
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$where_vals ) )
            : $wpdb->get_results( $sql );

        // Fetch departments for owner_type=department display
        $departments = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments WHERE active = 1 ORDER BY name"
        );
        $dept_map = [];
        foreach ( $departments as $d ) {
            $dept_map[ $d->id ] = $d->name;
        }

        // Fetch employees for owner_type=employee display and form select
        $employees = $wpdb->get_results(
            "SELECT id, employee_code, first_name, last_name FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active' ORDER BY first_name, last_name"
        );
        $emp_map = [];
        foreach ( $employees as $e ) {
            $emp_map[ $e->id ] = $e->first_name . ' ' . $e->last_name;
        }

        $level_labels = [
            'company'    => __( 'Company', 'sfs-hr' ),
            'department' => __( 'Department', 'sfs-hr' ),
            'individual' => __( 'Individual', 'sfs-hr' ),
        ];

        $status_colors = [
            'draft'     => 'fair',
            'active'    => 'good',
            'completed' => 'excellent',
            'cancelled' => 'needs_improvement',
        ];

        $show_form = ! empty( $_GET['add'] );
        ?>
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
            <div class="sfs-perf-header">
                <h1><?php esc_html_e( 'Goals & OKRs', 'sfs-hr' ); ?></h1>
                <a href="<?php echo esc_url( add_query_arg( 'add', '1', admin_url( 'admin.php?page=sfs-hr-performance-okrs' ) ) ); ?>" class="button button-primary">
                    <?php esc_html_e( '+ New Objective', 'sfs-hr' ); ?>
                </a>
            </div>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Objective saved.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['deleted'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Objective deleted.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $show_form ) : ?>
            <!-- Add Objective Form -->
            <div class="sfs-perf-section">
                <h2><?php esc_html_e( 'New Objective', 'sfs-hr' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="sfs_hr_save_objective">
                    <?php wp_nonce_field( 'sfs_hr_save_objective' ); ?>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Title', 'sfs-hr' ); ?></th>
                            <td><input type="text" name="title" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Description', 'sfs-hr' ); ?></th>
                            <td><textarea name="description" rows="3" class="large-text"></textarea></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Level', 'sfs-hr' ); ?></th>
                            <td>
                                <select name="level" id="okr-level">
                                    <option value="company"><?php esc_html_e( 'Company', 'sfs-hr' ); ?></option>
                                    <option value="department"><?php esc_html_e( 'Department', 'sfs-hr' ); ?></option>
                                    <option value="individual" selected><?php esc_html_e( 'Individual', 'sfs-hr' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Owner', 'sfs-hr' ); ?></th>
                            <td>
                                <select name="owner_type" id="okr-owner-type">
                                    <option value="company"><?php esc_html_e( 'Company-wide', 'sfs-hr' ); ?></option>
                                    <option value="department"><?php esc_html_e( 'Department', 'sfs-hr' ); ?></option>
                                    <option value="employee" selected><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></option>
                                </select>
                                <select name="owner_dept_id" style="margin-left:8px;">
                                    <option value=""><?php esc_html_e( 'Select Department', 'sfs-hr' ); ?></option>
                                    <?php foreach ( $departments as $d ) : ?>
                                    <option value="<?php echo (int) $d->id; ?>"><?php echo esc_html( $d->name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="owner_emp_id" style="margin-left:8px;">
                                    <option value=""><?php esc_html_e( 'Select Employee', 'sfs-hr' ); ?></option>
                                    <?php foreach ( $employees as $e ) : ?>
                                    <option value="<?php echo (int) $e->id; ?>"><?php echo esc_html( $e->first_name . ' ' . $e->last_name . ' (' . $e->employee_code . ')' ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Parent Objective', 'sfs-hr' ); ?></th>
                            <td>
                                <select name="parent_id">
                                    <option value=""><?php esc_html_e( 'None (top-level)', 'sfs-hr' ); ?></option>
                                    <?php foreach ( $objectives as $obj ) : ?>
                                    <option value="<?php echo (int) $obj->id; ?>">
                                        <?php echo esc_html( '[' . ( $level_labels[ $obj->level ] ?? $obj->level ) . '] ' . $obj->title ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Progress Type', 'sfs-hr' ); ?></th>
                            <td>
                                <select name="progress_type">
                                    <option value="percentage"><?php esc_html_e( 'Percentage', 'sfs-hr' ); ?></option>
                                    <option value="milestone"><?php esc_html_e( 'Milestone', 'sfs-hr' ); ?></option>
                                    <option value="binary"><?php esc_html_e( 'Binary (Done/Not Done)', 'sfs-hr' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Weight', 'sfs-hr' ); ?></th>
                            <td><input type="number" name="weight" min="0.01" max="100" step="0.01" value="1.00" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></th>
                            <td><input type="date" name="start_date"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Due Date', 'sfs-hr' ); ?></th>
                            <td><input type="date" name="due_date"></td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Objective', 'sfs-hr' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-performance-okrs' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></a>
                    </p>
                </form>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <form method="get" class="sfs-perf-filters">
                <input type="hidden" name="page" value="sfs-hr-performance-okrs">
                <label>
                    <?php esc_html_e( 'Level:', 'sfs-hr' ); ?>
                    <select name="level">
                        <option value=""><?php esc_html_e( 'All Levels', 'sfs-hr' ); ?></option>
                        <?php foreach ( $level_labels as $val => $lbl ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $level_filter, $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?php esc_html_e( 'Status:', 'sfs-hr' ); ?>
                    <select name="status">
                        <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
                        <option value="draft" <?php selected( $status_filter, 'draft' ); ?>><?php esc_html_e( 'Draft', 'sfs-hr' ); ?></option>
                        <option value="active" <?php selected( $status_filter, 'active' ); ?>><?php esc_html_e( 'Active', 'sfs-hr' ); ?></option>
                        <option value="completed" <?php selected( $status_filter, 'completed' ); ?>><?php esc_html_e( 'Completed', 'sfs-hr' ); ?></option>
                        <option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'sfs-hr' ); ?></option>
                    </select>
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'sfs-hr' ); ?></button>
            </form>

            <!-- Objectives Table -->
            <div class="sfs-perf-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Objective', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Level', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Owner', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Progress', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Due', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $objectives ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No objectives found.', 'sfs-hr' ); ?></td></tr>
                        <?php else : ?>
                        <?php foreach ( $objectives as $obj ) :
                            $indent = $obj->parent_id ? 'padding-left:24px;' : '';
                            $level_badge_class = $obj->level === 'company' ? 'excellent' : ( $obj->level === 'department' ? 'good' : 'fair' );
                            // Resolve owner display
                            if ( $obj->owner_type === 'company' ) {
                                $owner_display = __( 'Company', 'sfs-hr' );
                            } elseif ( $obj->owner_type === 'department' ) {
                                $owner_display = $dept_map[ $obj->owner_id ] ?? ( '#' . $obj->owner_id );
                            } else {
                                $owner_display = $emp_map[ $obj->owner_id ] ?? ( '#' . $obj->owner_id );
                            }
                            $kr_count = (int) $wpdb->get_var( $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_performance_key_results WHERE objective_id = %d",
                                $obj->id
                            ) );
                        ?>
                        <tr>
                            <td style="<?php echo esc_attr( $indent ); ?>">
                                <?php if ( $obj->parent_id ) : ?>
                                    <span style="color:#999;margin-right:4px;">&#x2514;</span>
                                <?php endif; ?>
                                <strong><?php echo esc_html( $obj->title ); ?></strong>
                                <?php if ( $obj->description ) : ?>
                                    <br><small style="color:#666;"><?php echo esc_html( wp_trim_words( $obj->description, 10 ) ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="sfs-perf-badge <?php echo esc_attr( $level_badge_class ); ?>">
                                    <?php echo esc_html( $level_labels[ $obj->level ] ?? $obj->level ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $owner_display ); ?></td>
                            <td style="min-width:120px;">
                                <div class="sfs-perf-progress">
                                    <div class="sfs-perf-progress-bar" style="width:<?php echo min( 100, (float) $obj->progress ); ?>%;"></div>
                                </div>
                                <small><?php echo esc_html( number_format( (float) $obj->progress, 1 ) ); ?>%
                                    <?php if ( $kr_count ) : ?>
                                        &middot; <?php echo esc_html( sprintf( _n( '%d KR', '%d KRs', $kr_count, 'sfs-hr' ), $kr_count ) ); ?>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <span class="sfs-perf-badge <?php echo esc_attr( $status_colors[ $obj->status ] ?? 'fair' ); ?>">
                                    <?php echo esc_html( ucfirst( $obj->status ) ); ?>
                                </span>
                            </td>
                            <td><?php echo $obj->due_date ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $obj->due_date ) ) ) : '—'; ?></td>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin-post.php?action=sfs_hr_delete_objective&id=' . $obj->id ),
                                    'sfs_hr_delete_objective_' . $obj->id
                                ) ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Delete this objective?', 'sfs-hr' ); ?>');">
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

    // -------------------------------------------------------------------------
    // M4: Review Cycles
    // -------------------------------------------------------------------------

    /**
     * Render Review Cycles page.
     */
    public function render_cycles(): void {
        global $wpdb;

        $cycles = $wpdb->get_results(
            "SELECT rc.*, u.display_name as creator_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_performance_reviews r WHERE r.cycle_id = rc.id) as total_reviews,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_performance_reviews r WHERE r.cycle_id = rc.id AND r.status = 'submitted') as submitted_reviews
             FROM {$wpdb->prefix}sfs_hr_performance_review_cycles rc
             LEFT JOIN {$wpdb->users} u ON u.ID = rc.created_by
             ORDER BY rc.start_date DESC"
        );

        $cycle_type_labels = [
            'annual'      => __( 'Annual', 'sfs-hr' ),
            'semi_annual' => __( 'Semi-Annual', 'sfs-hr' ),
            'quarterly'   => __( 'Quarterly', 'sfs-hr' ),
            'custom'      => __( 'Custom', 'sfs-hr' ),
        ];

        $status_colors = [
            'draft'       => 'fair',
            'active'      => 'good',
            'in_review'   => 'good',
            'calibration' => 'fair',
            'completed'   => 'excellent',
            'cancelled'   => 'needs_improvement',
        ];

        $show_form = ! empty( $_GET['add'] );
        ?>
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
            <div class="sfs-perf-header">
                <h1><?php esc_html_e( 'Review Cycles', 'sfs-hr' ); ?></h1>
                <a href="<?php echo esc_url( add_query_arg( 'add', '1', admin_url( 'admin.php?page=sfs-hr-performance-cycles' ) ) ); ?>" class="button button-primary">
                    <?php esc_html_e( '+ New Cycle', 'sfs-hr' ); ?>
                </a>
            </div>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Review cycle saved.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['activated'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Review cycle activated.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $show_form ) : ?>
            <div class="sfs-perf-section">
                <h2><?php esc_html_e( 'New Review Cycle', 'sfs-hr' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="sfs_hr_save_review_cycle">
                    <?php wp_nonce_field( 'sfs_hr_save_review_cycle' ); ?>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Name', 'sfs-hr' ); ?></th>
                            <td><input type="text" name="name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Description', 'sfs-hr' ); ?></th>
                            <td><textarea name="description" rows="3" class="large-text"></textarea></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Cycle Type', 'sfs-hr' ); ?></th>
                            <td>
                                <select name="cycle_type">
                                    <?php foreach ( $cycle_type_labels as $val => $lbl ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $lbl ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></th>
                            <td><input type="date" name="start_date" required></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'End Date', 'sfs-hr' ); ?></th>
                            <td><input type="date" name="end_date" required></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Submission Deadline', 'sfs-hr' ); ?></th>
                            <td><input type="date" name="deadline"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Review Types', 'sfs-hr' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="review_types[]" value="self"> <?php esc_html_e( 'Self', 'sfs-hr' ); ?></label>&nbsp;
                                <label><input type="checkbox" name="review_types[]" value="manager" checked> <?php esc_html_e( 'Manager', 'sfs-hr' ); ?></label>&nbsp;
                                <label><input type="checkbox" name="review_types[]" value="peer"> <?php esc_html_e( 'Peer', 'sfs-hr' ); ?></label>&nbsp;
                                <label><input type="checkbox" name="review_types[]" value="360"> <?php esc_html_e( '360°', 'sfs-hr' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Rating Scale (max)', 'sfs-hr' ); ?></th>
                            <td><input type="number" name="rating_scale_max" min="3" max="10" value="5" class="small-text"></td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Cycle', 'sfs-hr' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-performance-cycles' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></a>
                    </p>
                </form>
            </div>
            <?php endif; ?>

            <div class="sfs-perf-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Period', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Completion', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $cycles ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No review cycles found.', 'sfs-hr' ); ?></td></tr>
                        <?php else : ?>
                        <?php foreach ( $cycles as $cycle ) :
                            $completion_pct = $cycle->total_reviews > 0
                                ? round( ( $cycle->submitted_reviews / $cycle->total_reviews ) * 100 )
                                : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $cycle->name ); ?></strong>
                                <?php if ( $cycle->description ) : ?>
                                    <br><small style="color:#666;"><?php echo esc_html( wp_trim_words( $cycle->description, 8 ) ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $cycle_type_labels[ $cycle->cycle_type ] ?? $cycle->cycle_type ); ?></td>
                            <td>
                                <?php echo esc_html( wp_date( 'M j, Y', strtotime( $cycle->start_date ) ) ); ?> –
                                <?php echo esc_html( wp_date( 'M j, Y', strtotime( $cycle->end_date ) ) ); ?>
                            </td>
                            <td>
                                <span class="sfs-perf-badge <?php echo esc_attr( $status_colors[ $cycle->status ] ?? 'fair' ); ?>">
                                    <?php echo esc_html( ucfirst( str_replace( '_', ' ', $cycle->status ) ) ); ?>
                                </span>
                            </td>
                            <td style="min-width:120px;">
                                <div class="sfs-perf-progress">
                                    <div class="sfs-perf-progress-bar" style="width:<?php echo (int) $completion_pct; ?>%;"></div>
                                </div>
                                <small><?php echo esc_html( sprintf( __( '%d / %d submitted', 'sfs-hr' ), $cycle->submitted_reviews, $cycle->total_reviews ) ); ?></small>
                            </td>
                            <td>
                                <?php if ( $cycle->status === 'draft' ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin-post.php?action=sfs_hr_activate_review_cycle&id=' . $cycle->id ),
                                    'sfs_hr_activate_review_cycle_' . $cycle->id
                                ) ); ?>" class="button button-primary button-small">
                                    <?php esc_html_e( 'Activate', 'sfs-hr' ); ?>
                                </a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url( add_query_arg( [
                                    'page'     => 'sfs-hr-performance-reviews',
                                    'cycle_id' => $cycle->id,
                                ], admin_url( 'admin.php' ) ) ); ?>" class="button button-small">
                                    <?php esc_html_e( 'View Reviews', 'sfs-hr' ); ?>
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

    // -------------------------------------------------------------------------
    // M4: 360° Feedback
    // -------------------------------------------------------------------------

    /**
     * Render 360° Feedback page.
     */
    public function render_feedback(): void {
        global $wpdb;

        $cycle_id_filter = isset( $_GET['cycle_id'] ) ? (int) $_GET['cycle_id'] : 0;

        // Fetch cycles for filter dropdown
        $cycles = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}sfs_hr_performance_review_cycles ORDER BY start_date DESC"
        );

        // Build feedback query
        $where_sql = $cycle_id_filter
            ? $wpdb->prepare( 'WHERE f.cycle_id = %d', $cycle_id_filter )
            : '';

        $feedback_rows = $wpdb->get_results(
            "SELECT f.*,
                    CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                    e.employee_code,
                    up.display_name AS provider_name,
                    rc.name AS cycle_name
             FROM {$wpdb->prefix}sfs_hr_performance_feedback_360 f
             JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = f.employee_id
             LEFT JOIN {$wpdb->users} up ON up.ID = f.provider_id
             LEFT JOIN {$wpdb->prefix}sfs_hr_performance_review_cycles rc ON rc.id = f.cycle_id
             {$where_sql}
             ORDER BY f.created_at DESC
             LIMIT 100"
        );

        // Per-cycle completion stats
        $cycle_stats = [];
        if ( $cycle_id_filter ) {
            $total    = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_performance_feedback_360 WHERE cycle_id = %d",
                $cycle_id_filter
            ) );
            $submitted = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_performance_feedback_360 WHERE cycle_id = %d AND status = 'submitted'",
                $cycle_id_filter
            ) );
            $cycle_stats = [ 'total' => $total, 'submitted' => $submitted ];
        }

        $provider_type_labels = [
            'self'          => __( 'Self', 'sfs-hr' ),
            'manager'       => __( 'Manager', 'sfs-hr' ),
            'peer'          => __( 'Peer', 'sfs-hr' ),
            'direct_report' => __( 'Direct Report', 'sfs-hr' ),
            'external'      => __( 'External', 'sfs-hr' ),
        ];
        ?>
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
            <h1><?php esc_html_e( '360° Feedback', 'sfs-hr' ); ?></h1>

            <!-- Cycle filter + stats -->
            <form method="get" class="sfs-perf-filters">
                <input type="hidden" name="page" value="sfs-hr-performance-feedback">
                <label>
                    <?php esc_html_e( 'Cycle:', 'sfs-hr' ); ?>
                    <select name="cycle_id">
                        <option value=""><?php esc_html_e( 'All Cycles', 'sfs-hr' ); ?></option>
                        <?php foreach ( $cycles as $c ) : ?>
                        <option value="<?php echo (int) $c->id; ?>" <?php selected( $cycle_id_filter, $c->id ); ?>>
                            <?php echo esc_html( $c->name ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'sfs-hr' ); ?></button>
            </form>

            <?php if ( $cycle_id_filter && $cycle_stats ) : ?>
            <div class="sfs-perf-cards" style="margin-bottom:20px;">
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Total Requests', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo esc_html( $cycle_stats['total'] ); ?></div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Submitted', 'sfs-hr' ); ?></h3>
                    <div class="value" style="color:#22c55e;"><?php echo esc_html( $cycle_stats['submitted'] ); ?></div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Pending', 'sfs-hr' ); ?></h3>
                    <div class="value" style="color:#f59e0b;"><?php echo esc_html( $cycle_stats['total'] - $cycle_stats['submitted'] ); ?></div>
                </div>
                <div class="sfs-perf-card">
                    <h3><?php esc_html_e( 'Completion', 'sfs-hr' ); ?></h3>
                    <div class="value"><?php echo $cycle_stats['total'] > 0 ? esc_html( round( ( $cycle_stats['submitted'] / $cycle_stats['total'] ) * 100 ) ) . '%' : '—'; ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="sfs-perf-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Cycle', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Provider', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Provider Type', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Rating', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Anonymous', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $feedback_rows ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No 360° feedback records found.', 'sfs-hr' ); ?></td></tr>
                        <?php else : ?>
                        <?php foreach ( $feedback_rows as $fb ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $fb->employee_name ); ?></strong><br>
                                <small style="color:#666;"><?php echo esc_html( $fb->employee_code ); ?></small>
                            </td>
                            <td><?php echo esc_html( $fb->cycle_name ); ?></td>
                            <td>
                                <?php if ( $fb->is_anonymous ) : ?>
                                    <em style="color:#999;"><?php esc_html_e( 'Anonymous', 'sfs-hr' ); ?></em>
                                <?php else : ?>
                                    <?php echo esc_html( $fb->provider_name ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $provider_type_labels[ $fb->provider_type ] ?? $fb->provider_type ); ?></td>
                            <td>
                                <?php echo $fb->overall_rating !== null
                                    ? esc_html( number_format( (float) $fb->overall_rating, 1 ) ) . '/5'
                                    : '—'; ?>
                            </td>
                            <td>
                                <span class="sfs-perf-badge <?php echo $fb->status === 'submitted' ? 'excellent' : 'fair'; ?>">
                                    <?php echo esc_html( ucfirst( $fb->status ) ); ?>
                                </span>
                            </td>
                            <td><?php echo $fb->is_anonymous ? esc_html__( 'Yes', 'sfs-hr' ) : esc_html__( 'No', 'sfs-hr' ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // M4: Performance Improvement Plans (PIPs)
    // -------------------------------------------------------------------------

    /**
     * Render PIPs page.
     */
    public function render_pips(): void {
        global $wpdb;

        $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';

        $where_sql = $status_filter
            ? $wpdb->prepare( 'WHERE p.status = %s', $status_filter )
            : '';

        $pips = $wpdb->get_results(
            "SELECT p.*,
                    CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                    e.employee_code,
                    u.display_name AS initiated_by_name
             FROM {$wpdb->prefix}sfs_hr_performance_pips p
             JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = p.employee_id
             LEFT JOIN {$wpdb->users} u ON u.ID = p.initiated_by
             {$where_sql}
             ORDER BY p.created_at DESC"
        );

        $status_colors = [
            'active'     => 'good',
            'extended'   => 'fair',
            'completed'  => 'excellent',
            'terminated' => 'needs_improvement',
        ];

        $outcome_labels = [
            'improved'   => __( 'Improved', 'sfs-hr' ),
            'no_change'  => __( 'No Change', 'sfs-hr' ),
            'terminated' => __( 'Terminated', 'sfs-hr' ),
        ];

        $employees = $wpdb->get_results(
            "SELECT id, employee_code, first_name, last_name FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active' ORDER BY first_name, last_name"
        );

        $show_form = ! empty( $_GET['add'] );
        ?>
        <div class="wrap sfs-hr-wrap sfs-perf-wrap">
            <div class="sfs-perf-header">
                <h1><?php esc_html_e( 'Performance Improvement Plans', 'sfs-hr' ); ?></h1>
                <a href="<?php echo esc_url( add_query_arg( 'add', '1', admin_url( 'admin.php?page=sfs-hr-performance-pips' ) ) ); ?>" class="button button-primary">
                    <?php esc_html_e( '+ New PIP', 'sfs-hr' ); ?>
                </a>
            </div>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'PIP saved.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['completed'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'PIP marked as completed.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $show_form ) : ?>
            <div class="sfs-perf-section">
                <h2><?php esc_html_e( 'New Performance Improvement Plan', 'sfs-hr' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="sfs_hr_save_pip">
                    <?php wp_nonce_field( 'sfs_hr_save_pip' ); ?>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                            <td>
                                <select name="employee_id" required>
                                    <option value=""><?php esc_html_e( 'Select Employee', 'sfs-hr' ); ?></option>
                                    <?php foreach ( $employees as $e ) : ?>
                                    <option value="<?php echo (int) $e->id; ?>">
                                        <?php echo esc_html( $e->first_name . ' ' . $e->last_name . ' (' . $e->employee_code . ')' ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Reason', 'sfs-hr' ); ?></th>
                            <td><textarea name="reason" rows="3" class="large-text" required></textarea></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Goals / Expected Improvements', 'sfs-hr' ); ?></th>
                            <td><textarea name="goals" rows="4" class="large-text"></textarea></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></th>
                            <td><input type="date" name="start_date" required></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'End Date', 'sfs-hr' ); ?></th>
                            <td><input type="date" name="end_date" required></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Review Date', 'sfs-hr' ); ?></th>
                            <td><input type="date" name="review_date"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Notes', 'sfs-hr' ); ?></th>
                            <td><textarea name="notes" rows="3" class="large-text"></textarea></td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save PIP', 'sfs-hr' ); ?></button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-performance-pips' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></a>
                    </p>
                </form>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <form method="get" class="sfs-perf-filters">
                <input type="hidden" name="page" value="sfs-hr-performance-pips">
                <label>
                    <?php esc_html_e( 'Status:', 'sfs-hr' ); ?>
                    <select name="status">
                        <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
                        <option value="active" <?php selected( $status_filter, 'active' ); ?>><?php esc_html_e( 'Active', 'sfs-hr' ); ?></option>
                        <option value="extended" <?php selected( $status_filter, 'extended' ); ?>><?php esc_html_e( 'Extended', 'sfs-hr' ); ?></option>
                        <option value="completed" <?php selected( $status_filter, 'completed' ); ?>><?php esc_html_e( 'Completed', 'sfs-hr' ); ?></option>
                        <option value="terminated" <?php selected( $status_filter, 'terminated' ); ?>><?php esc_html_e( 'Terminated', 'sfs-hr' ); ?></option>
                    </select>
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'sfs-hr' ); ?></button>
            </form>

            <!-- PIPs Table -->
            <div class="sfs-perf-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Reason', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Period', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Review Date', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Outcome', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Initiated By', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $pips ) ) : ?>
                        <tr><td colspan="8"><?php esc_html_e( 'No PIPs found.', 'sfs-hr' ); ?></td></tr>
                        <?php else : ?>
                        <?php foreach ( $pips as $pip ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $pip->employee_name ); ?></strong><br>
                                <small style="color:#666;"><?php echo esc_html( $pip->employee_code ); ?></small>
                            </td>
                            <td><small><?php echo esc_html( wp_trim_words( $pip->reason, 12 ) ); ?></small></td>
                            <td>
                                <?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $pip->start_date ) ) ); ?> –
                                <?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $pip->end_date ) ) ); ?>
                            </td>
                            <td>
                                <?php echo $pip->review_date
                                    ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $pip->review_date ) ) )
                                    : '—'; ?>
                            </td>
                            <td>
                                <span class="sfs-perf-badge <?php echo esc_attr( $status_colors[ $pip->status ] ?? 'fair' ); ?>">
                                    <?php echo esc_html( ucfirst( $pip->status ) ); ?>
                                </span>
                            </td>
                            <td><?php echo $pip->outcome ? esc_html( $outcome_labels[ $pip->outcome ] ?? $pip->outcome ) : '—'; ?></td>
                            <td><?php echo esc_html( $pip->initiated_by_name ); ?></td>
                            <td>
                                <?php if ( $pip->status === 'active' || $pip->status === 'extended' ) : ?>
                                <a href="<?php echo esc_url( add_query_arg( [
                                    'page'   => 'sfs-hr-performance-pips',
                                    'action' => 'complete',
                                    'id'     => $pip->id,
                                ], admin_url( 'admin.php' ) ) ); ?>" class="button button-primary button-small" data-pip-id="<?php echo (int) $pip->id; ?>" onclick="return sfsHrCompletePip(this)">
                                    <?php esc_html_e( 'Complete', 'sfs-hr' ); ?>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Complete PIP inline form (hidden by default, shown via JS) -->
        <div id="sfs-pip-complete-dialog" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:8px;padding:30px;max-width:480px;width:100%;margin:20px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Complete PIP', 'sfs-hr' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="sfs_hr_complete_pip">
                    <input type="hidden" name="pip_id" id="sfs-pip-id-input" value="">
                    <?php wp_nonce_field( 'sfs_hr_complete_pip' ); ?>
                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th><?php esc_html_e( 'Outcome', 'sfs-hr' ); ?></th>
                            <td>
                                <select name="outcome" required>
                                    <option value=""><?php esc_html_e( 'Select outcome', 'sfs-hr' ); ?></option>
                                    <option value="improved"><?php esc_html_e( 'Improved', 'sfs-hr' ); ?></option>
                                    <option value="no_change"><?php esc_html_e( 'No Change', 'sfs-hr' ); ?></option>
                                    <option value="terminated"><?php esc_html_e( 'Terminated', 'sfs-hr' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Notes', 'sfs-hr' ); ?></th>
                            <td><textarea name="completion_notes" rows="3" class="large-text"></textarea></td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'sfs-hr' ); ?></button>
                        <button type="button" class="button" onclick="document.getElementById('sfs-pip-complete-dialog').style.display='none';"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></button>
                    </p>
                </form>
            </div>
        </div>
        <script>
        function sfsHrCompletePip(btn) {
            document.getElementById('sfs-pip-id-input').value = btn.getAttribute('data-pip-id');
            var d = document.getElementById('sfs-pip-complete-dialog');
            d.style.display = 'flex';
            return false;
        }
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // M4: Form Handlers
    // -------------------------------------------------------------------------

    /**
     * Handle save objective (Goals & OKRs).
     */
    public function handle_save_objective(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ), '', 403 );
        }
        check_admin_referer( 'sfs_hr_save_objective' );

        global $wpdb;
        $now = current_time( 'mysql' );

        $owner_type = sanitize_text_field( $_POST['owner_type'] ?? 'employee' );
        if ( $owner_type === 'department' ) {
            $owner_id = (int) ( $_POST['owner_dept_id'] ?? 0 );
        } elseif ( $owner_type === 'employee' ) {
            $owner_id = (int) ( $_POST['owner_emp_id'] ?? 0 );
        } else {
            $owner_id = 0;
        }

        $level_raw        = sanitize_text_field( $_POST['level'] ?? 'individual' );
        $progress_type    = sanitize_text_field( $_POST['progress_type'] ?? 'percentage' );
        $allowed_levels   = [ 'company', 'department', 'individual' ];
        $allowed_pt       = [ 'percentage', 'milestone', 'binary' ];
        $allowed_ot       = [ 'company', 'department', 'employee' ];

        $level         = in_array( $level_raw, $allowed_levels, true ) ? $level_raw : 'individual';
        $progress_type = in_array( $progress_type, $allowed_pt, true ) ? $progress_type : 'percentage';
        $owner_type    = in_array( $owner_type, $allowed_ot, true ) ? $owner_type : 'employee';

        $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_performance_objectives',
            [
                'parent_id'       => ( $_POST['parent_id'] ?? '' ) !== '' ? (int) $_POST['parent_id'] : null,
                'level'           => $level,
                'title'           => sanitize_text_field( $_POST['title'] ?? '' ),
                'description'     => sanitize_textarea_field( $_POST['description'] ?? '' ),
                'owner_type'      => $owner_type,
                'owner_id'        => $owner_id,
                'weight'          => isset( $_POST['weight'] ) ? (float) $_POST['weight'] : 1.00,
                'progress_type'   => $progress_type,
                'start_date'      => ( $_POST['start_date'] ?? '' ) ?: null,
                'due_date'        => ( $_POST['due_date'] ?? '' ) ?: null,
                'created_by'      => get_current_user_id(),
                'created_at'      => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%d', '%s' ]
        );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-okrs&saved=1' ) );
        exit;
    }

    /**
     * Handle delete objective.
     */
    public function handle_delete_objective(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ), '', 403 );
        }

        $obj_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        check_admin_referer( 'sfs_hr_delete_objective_' . $obj_id );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'sfs_hr_performance_objectives', [ 'id' => $obj_id ], [ '%d' ] );
        // Also remove child key results and child objectives
        $wpdb->delete( $wpdb->prefix . 'sfs_hr_performance_key_results', [ 'objective_id' => $obj_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'sfs_hr_performance_objectives', [ 'parent_id' => $obj_id ], [ '%d' ] );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-okrs&deleted=1' ) );
        exit;
    }

    /**
     * Handle save review cycle.
     */
    public function handle_save_review_cycle(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ), '', 403 );
        }
        check_admin_referer( 'sfs_hr_save_review_cycle' );

        global $wpdb;
        $now = current_time( 'mysql' );

        $allowed_types = [ 'annual', 'semi_annual', 'quarterly', 'custom' ];
        $cycle_type    = sanitize_text_field( $_POST['cycle_type'] ?? 'annual' );
        if ( ! in_array( $cycle_type, $allowed_types, true ) ) {
            $cycle_type = 'annual';
        }

        $review_types = isset( $_POST['review_types'] ) && is_array( $_POST['review_types'] )
            ? implode( ',', array_map( 'sanitize_text_field', $_POST['review_types'] ) )
            : '';

        $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_performance_review_cycles',
            [
                'name'             => sanitize_text_field( $_POST['name'] ?? '' ),
                'description'      => sanitize_textarea_field( $_POST['description'] ?? '' ),
                'cycle_type'       => $cycle_type,
                'start_date'       => sanitize_text_field( $_POST['start_date'] ?? '' ),
                'end_date'         => sanitize_text_field( $_POST['end_date'] ?? '' ),
                'deadline'         => ( $_POST['deadline'] ?? '' ) ?: null,
                'review_types'     => $review_types,
                'status'           => 'draft',
                'rating_scale_max' => isset( $_POST['rating_scale_max'] ) ? (int) $_POST['rating_scale_max'] : 5,
                'created_by'       => get_current_user_id(),
                'created_at'       => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ]
        );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-cycles&saved=1' ) );
        exit;
    }

    /**
     * Handle activate review cycle.
     */
    public function handle_activate_review_cycle(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ), '', 403 );
        }

        $cycle_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        check_admin_referer( 'sfs_hr_activate_review_cycle_' . $cycle_id );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'sfs_hr_performance_review_cycles',
            [ 'status' => 'active', 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $cycle_id, 'status' => 'draft' ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-cycles&activated=1' ) );
        exit;
    }

    /**
     * Handle save PIP.
     */
    public function handle_save_pip(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ), '', 403 );
        }
        check_admin_referer( 'sfs_hr_save_pip' );

        global $wpdb;
        $now = current_time( 'mysql' );

        $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_performance_pips',
            [
                'employee_id'  => (int) ( $_POST['employee_id'] ?? 0 ),
                'initiated_by' => get_current_user_id(),
                'reason'       => sanitize_textarea_field( $_POST['reason'] ?? '' ),
                'goals'        => sanitize_textarea_field( $_POST['goals'] ?? '' ),
                'start_date'   => sanitize_text_field( $_POST['start_date'] ?? '' ),
                'end_date'     => sanitize_text_field( $_POST['end_date'] ?? '' ),
                'review_date'  => ( $_POST['review_date'] ?? '' ) ?: null,
                'status'       => 'active',
                'notes'        => sanitize_textarea_field( $_POST['notes'] ?? '' ),
                'created_at'   => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-pips&saved=1' ) );
        exit;
    }

    /**
     * Handle complete PIP.
     */
    public function handle_complete_pip(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ), '', 403 );
        }
        check_admin_referer( 'sfs_hr_complete_pip' );

        $pip_id = isset( $_POST['pip_id'] ) ? (int) $_POST['pip_id'] : 0;
        if ( ! $pip_id ) {
            wp_die( __( 'Invalid PIP ID.', 'sfs-hr' ), '', 400 );
        }

        $allowed_outcomes = [ 'improved', 'no_change', 'terminated' ];
        $outcome = sanitize_text_field( $_POST['outcome'] ?? '' );
        if ( ! in_array( $outcome, $allowed_outcomes, true ) ) {
            wp_die( __( 'Invalid outcome.', 'sfs-hr' ), '', 400 );
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'sfs_hr_performance_pips',
            [
                'status'     => 'completed',
                'outcome'    => $outcome,
                'notes'      => sanitize_textarea_field( $_POST['completion_notes'] ?? '' ),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $pip_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-performance-pips&completed=1' ) );
        exit;
    }
}
