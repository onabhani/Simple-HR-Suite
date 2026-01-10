<?php
/**
 * Payroll Admin Pages
 *
 * @package SFS\HR\Modules\Payroll\Admin
 */

namespace SFS\HR\Modules\Payroll\Admin;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Payroll\PayrollModule;

defined( 'ABSPATH' ) || exit;

class Admin_Pages {

    public function hooks(): void {
        add_action( 'admin_menu', [ $this, 'menu' ], 25 );
        add_action( 'admin_post_sfs_hr_payroll_create_period', [ $this, 'handle_create_period' ] );
        add_action( 'admin_post_sfs_hr_payroll_run_payroll', [ $this, 'handle_run_payroll' ] );
        add_action( 'admin_post_sfs_hr_payroll_approve_run', [ $this, 'handle_approve_run' ] );
        add_action( 'admin_post_sfs_hr_payroll_save_component', [ $this, 'handle_save_component' ] );
        add_action( 'admin_post_sfs_hr_payroll_export_bank', [ $this, 'handle_export_bank' ] );
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __( 'Payroll', 'sfs-hr' ),
            __( 'Payroll', 'sfs-hr' ),
            'sfs_hr.view',
            'sfs-hr-payroll',
            [ $this, 'render_hub' ]
        );
    }

    public function render_hub(): void {
        if ( ! current_user_can( 'sfs_hr.view' ) ) {
            wp_die( esc_html__( 'Access denied.', 'sfs-hr' ) );
        }

        echo '<div class="wrap sfs-hr-wrap">';
        echo '<h1>' . esc_html__( 'Payroll Management', 'sfs-hr' ) . '</h1>';
        Helpers::render_admin_nav();
        echo '<hr class="wp-header-end" />';

        // Tabs
        $tabs = [
            'overview'   => __( 'Overview', 'sfs-hr' ),
            'periods'    => __( 'Payroll Periods', 'sfs-hr' ),
            'runs'       => __( 'Payroll Runs', 'sfs-hr' ),
            'components' => __( 'Salary Components', 'sfs-hr' ),
            'payslips'   => __( 'Payslips', 'sfs-hr' ),
        ];

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
        if ( ! isset( $tabs[ $active_tab ] ) ) {
            $active_tab = 'overview';
        }

        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $slug => $label ) {
            $url   = add_query_arg( 'tab', $slug, admin_url( 'admin.php?page=sfs-hr-payroll' ) );
            $class = $active_tab === $slug ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</h2>';

        echo '<div class="sfs-hr-payroll-content" style="margin-top:20px;">';

        switch ( $active_tab ) {
            case 'overview':
                $this->render_overview();
                break;
            case 'periods':
                $this->render_periods();
                break;
            case 'runs':
                $this->render_runs();
                break;
            case 'components':
                $this->render_components();
                break;
            case 'payslips':
                $this->render_payslips();
                break;
        }

        echo '</div>';
        echo '</div>';
    }

    private function render_overview(): void {
        global $wpdb;

        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Get current/latest period
        $current_period = $wpdb->get_row(
            "SELECT * FROM {$periods_table} WHERE status IN ('open','processing') ORDER BY start_date DESC LIMIT 1"
        );

        if ( ! $current_period ) {
            $current_period = $wpdb->get_row(
                "SELECT * FROM {$periods_table} ORDER BY start_date DESC LIMIT 1"
            );
        }

        // Get stats
        $total_employees = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$emp_table} WHERE status = 'active'" );
        $total_periods = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$periods_table}" );

        // Get last completed run totals
        $last_run = $wpdb->get_row(
            "SELECT * FROM {$runs_table} WHERE status IN ('approved','paid') ORDER BY approved_at DESC LIMIT 1"
        );

        ?>
        <div class="sfs-hr-payroll-overview">
            <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:30px;">
                <div class="sfs-hr-stat-card" style="flex:1; min-width:200px; background:#fff; padding:20px; border:1px solid #dcdcde; border-radius:8px;">
                    <h3 style="margin:0 0 8px; color:#1d2327; font-size:14px;"><?php esc_html_e( 'Active Employees', 'sfs-hr' ); ?></h3>
                    <div style="font-size:32px; font-weight:700; color:#2271b1;"><?php echo esc_html( number_format_i18n( $total_employees ) ); ?></div>
                </div>

                <div class="sfs-hr-stat-card" style="flex:1; min-width:200px; background:#fff; padding:20px; border:1px solid #dcdcde; border-radius:8px;">
                    <h3 style="margin:0 0 8px; color:#1d2327; font-size:14px;"><?php esc_html_e( 'Payroll Periods', 'sfs-hr' ); ?></h3>
                    <div style="font-size:32px; font-weight:700; color:#2271b1;"><?php echo esc_html( number_format_i18n( $total_periods ) ); ?></div>
                </div>

                <?php if ( $last_run ): ?>
                <div class="sfs-hr-stat-card" style="flex:1; min-width:200px; background:#fff; padding:20px; border:1px solid #dcdcde; border-radius:8px;">
                    <h3 style="margin:0 0 8px; color:#1d2327; font-size:14px;"><?php esc_html_e( 'Last Payroll Net', 'sfs-hr' ); ?></h3>
                    <div style="font-size:24px; font-weight:700; color:#00a32a;"><?php echo esc_html( number_format( (float) $last_run->total_net, 2 ) ); ?> SAR</div>
                    <div style="font-size:12px; color:#646970;"><?php echo esc_html( $last_run->employee_count ); ?> <?php esc_html_e( 'employees', 'sfs-hr' ); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( $current_period ): ?>
            <div style="background:#fff; padding:20px; border:1px solid #dcdcde; border-radius:8px; margin-bottom:20px;">
                <h2 style="margin:0 0 15px;"><?php esc_html_e( 'Current Period', 'sfs-hr' ); ?>: <?php echo esc_html( $current_period->name ); ?></h2>
                <p>
                    <strong><?php esc_html_e( 'Period:', 'sfs-hr' ); ?></strong>
                    <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $current_period->start_date ) ) ); ?>
                    -
                    <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $current_period->end_date ) ) ); ?>
                </p>
                <p>
                    <strong><?php esc_html_e( 'Pay Date:', 'sfs-hr' ); ?></strong>
                    <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $current_period->pay_date ) ) ); ?>
                </p>
                <p>
                    <strong><?php esc_html_e( 'Status:', 'sfs-hr' ); ?></strong>
                    <?php echo esc_html( ucfirst( $current_period->status ) ); ?>
                </p>

                <?php if ( $current_period->status === 'open' && current_user_can( 'sfs_hr_payroll_run' ) ): ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:15px;">
                    <?php wp_nonce_field( 'sfs_hr_payroll_run_payroll' ); ?>
                    <input type="hidden" name="action" value="sfs_hr_payroll_run_payroll" />
                    <input type="hidden" name="period_id" value="<?php echo intval( $current_period->id ); ?>" />
                    <button type="submit" class="button button-primary button-hero">
                        <?php esc_html_e( 'Run Payroll', 'sfs-hr' ); ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="notice notice-warning" style="margin:0;">
                <p><?php esc_html_e( 'No payroll periods found. Create a period to get started.', 'sfs-hr' ); ?></p>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&tab=periods' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Create Period', 'sfs-hr' ); ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div style="background:#f0f6fc; padding:20px; border-radius:8px;">
                <h3 style="margin:0 0 15px;"><?php esc_html_e( 'Quick Actions', 'sfs-hr' ); ?></h3>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&tab=periods&action=new' ) ); ?>" class="button">
                        <?php esc_html_e( 'Create New Period', 'sfs-hr' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&tab=components' ) ); ?>" class="button">
                        <?php esc_html_e( 'Manage Components', 'sfs-hr' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&tab=runs' ) ); ?>" class="button">
                        <?php esc_html_e( 'View Payroll History', 'sfs-hr' ); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_periods(): void {
        global $wpdb;

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        $table = $wpdb->prefix . 'sfs_hr_payroll_periods';

        if ( $action === 'new' ) {
            $this->render_period_form();
            return;
        }

        // List periods
        $periods = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY start_date DESC LIMIT 50" );

        ?>
        <div class="sfs-hr-payroll-periods">
            <div style="margin-bottom:15px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&tab=periods&action=new' ) ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Create New Period', 'sfs-hr' ); ?>
                </a>
            </div>

            <?php if ( empty( $periods ) ): ?>
            <div class="notice notice-info">
                <p><?php esc_html_e( 'No payroll periods found.', 'sfs-hr' ); ?></p>
            </div>
            <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Period Name', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'End Date', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Pay Date', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $periods as $period ): ?>
                    <tr>
                        <td><strong><?php echo esc_html( $period->name ); ?></strong></td>
                        <td><?php echo esc_html( ucfirst( str_replace( '_', '-', $period->period_type ) ) ); ?></td>
                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $period->start_date ) ) ); ?></td>
                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $period->end_date ) ) ); ?></td>
                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $period->pay_date ) ) ); ?></td>
                        <td>
                            <?php
                            $status_colors = [
                                'upcoming'   => '#f0ad4e',
                                'open'       => '#5bc0de',
                                'processing' => '#0073aa',
                                'closed'     => '#777',
                                'paid'       => '#5cb85c',
                            ];
                            $color = $status_colors[ $period->status ] ?? '#777';
                            ?>
                            <span style="color:<?php echo esc_attr( $color ); ?>; font-weight:600;">
                                <?php echo esc_html( ucfirst( $period->status ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( $period->status === 'open' ): ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                <?php wp_nonce_field( 'sfs_hr_payroll_run_payroll' ); ?>
                                <input type="hidden" name="action" value="sfs_hr_payroll_run_payroll" />
                                <input type="hidden" name="period_id" value="<?php echo intval( $period->id ); ?>" />
                                <button type="submit" class="button button-small"><?php esc_html_e( 'Run', 'sfs-hr' ); ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_period_form(): void {
        $now = new \DateTime();
        $start_of_month = $now->format( 'Y-m-01' );
        $end_of_month = $now->format( 'Y-m-t' );
        $default_pay_date = ( new \DateTime( $end_of_month ) )->modify( '+5 days' )->format( 'Y-m-d' );

        ?>
        <div class="sfs-hr-period-form" style="max-width:600px;">
            <h2><?php esc_html_e( 'Create Payroll Period', 'sfs-hr' ); ?></h2>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sfs_hr_payroll_create_period' ); ?>
                <input type="hidden" name="action" value="sfs_hr_payroll_create_period" />

                <table class="form-table">
                    <tr>
                        <th><label for="period_type"><?php esc_html_e( 'Period Type', 'sfs-hr' ); ?></label></th>
                        <td>
                            <select name="period_type" id="period_type" class="regular-text">
                                <option value="monthly"><?php esc_html_e( 'Monthly', 'sfs-hr' ); ?></option>
                                <option value="bi_weekly"><?php esc_html_e( 'Bi-Weekly', 'sfs-hr' ); ?></option>
                                <option value="weekly"><?php esc_html_e( 'Weekly', 'sfs-hr' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="start_date"><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="date" name="start_date" id="start_date" value="<?php echo esc_attr( $start_of_month ); ?>" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="end_date"><?php esc_html_e( 'End Date', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="date" name="end_date" id="end_date" value="<?php echo esc_attr( $end_of_month ); ?>" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="pay_date"><?php esc_html_e( 'Pay Date', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="date" name="pay_date" id="pay_date" value="<?php echo esc_attr( $default_pay_date ); ?>" class="regular-text" required />
                            <p class="description"><?php esc_html_e( 'The date when salaries will be paid.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status"><?php esc_html_e( 'Status', 'sfs-hr' ); ?></label></th>
                        <td>
                            <select name="status" id="status" class="regular-text">
                                <option value="upcoming"><?php esc_html_e( 'Upcoming', 'sfs-hr' ); ?></option>
                                <option value="open" selected><?php esc_html_e( 'Open (Ready for Payroll)', 'sfs-hr' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="notes"><?php esc_html_e( 'Notes', 'sfs-hr' ); ?></label></th>
                        <td>
                            <textarea name="notes" id="notes" rows="3" class="large-text"></textarea>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Create Period', 'sfs-hr' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&tab=periods' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_runs(): void {
        global $wpdb;

        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';

        $view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
        $run_id = isset( $_GET['run_id'] ) ? intval( $_GET['run_id'] ) : 0;

        if ( $view === 'detail' && $run_id ) {
            $this->render_run_detail( $run_id );
            return;
        }

        // List runs
        $runs = $wpdb->get_results(
            "SELECT r.*, p.name as period_name, p.start_date, p.end_date
             FROM {$runs_table} r
             LEFT JOIN {$periods_table} p ON p.id = r.period_id
             ORDER BY r.created_at DESC
             LIMIT 50"
        );

        ?>
        <div class="sfs-hr-payroll-runs">
            <h2><?php esc_html_e( 'Payroll Runs', 'sfs-hr' ); ?></h2>

            <?php if ( empty( $runs ) ): ?>
            <div class="notice notice-info">
                <p><?php esc_html_e( 'No payroll runs found. Run payroll from a period to create a run.', 'sfs-hr' ); ?></p>
            </div>
            <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php esc_html_e( 'ID', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Period', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Employees', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Total Gross', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Total Net', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $runs as $run ): ?>
                    <tr>
                        <td><?php echo intval( $run->id ); ?></td>
                        <td>
                            <strong><?php echo esc_html( $run->period_name ); ?></strong>
                            <?php if ( $run->run_number > 1 ): ?>
                            <small>(Run #<?php echo intval( $run->run_number ); ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $status_colors = [
                                'draft'      => '#f0ad4e',
                                'calculating'=> '#5bc0de',
                                'review'     => '#0073aa',
                                'approved'   => '#5cb85c',
                                'paid'       => '#00a32a',
                                'cancelled'  => '#d9534f',
                            ];
                            $color = $status_colors[ $run->status ] ?? '#777';
                            ?>
                            <span style="color:<?php echo esc_attr( $color ); ?>; font-weight:600;">
                                <?php echo esc_html( ucfirst( $run->status ) ); ?>
                            </span>
                        </td>
                        <td><?php echo intval( $run->employee_count ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $run->total_gross, 2 ) ); ?></td>
                        <td><strong><?php echo esc_html( number_format( (float) $run->total_net, 2 ) ); ?></strong></td>
                        <td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $run->created_at ) ) ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&tab=runs&view=detail&run_id=' . $run->id ) ); ?>" class="button button-small">
                                <?php esc_html_e( 'View', 'sfs-hr' ); ?>
                            </a>

                            <?php if ( $run->status === 'review' && current_user_can( 'sfs_hr_payroll_admin' ) ): ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                <?php wp_nonce_field( 'sfs_hr_payroll_approve_run' ); ?>
                                <input type="hidden" name="action" value="sfs_hr_payroll_approve_run" />
                                <input type="hidden" name="run_id" value="<?php echo intval( $run->id ); ?>" />
                                <button type="submit" class="button button-small button-primary"><?php esc_html_e( 'Approve', 'sfs-hr' ); ?></button>
                            </form>
                            <?php endif; ?>

                            <?php if ( in_array( $run->status, [ 'approved', 'paid' ], true ) ): ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                <?php wp_nonce_field( 'sfs_hr_payroll_export_bank' ); ?>
                                <input type="hidden" name="action" value="sfs_hr_payroll_export_bank" />
                                <input type="hidden" name="run_id" value="<?php echo intval( $run->id ); ?>" />
                                <button type="submit" class="button button-small"><?php esc_html_e( 'Export', 'sfs-hr' ); ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_run_detail( int $run_id ): void {
        global $wpdb;

        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $run = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, p.name as period_name, p.start_date, p.end_date, p.pay_date
             FROM {$runs_table} r
             LEFT JOIN {$periods_table} p ON p.id = r.period_id
             WHERE r.id = %d",
            $run_id
        ) );

        if ( ! $run ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Run not found.', 'sfs-hr' ) . '</p></div>';
            return;
        }

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, e.first_name, e.last_name, e.emp_number, e.employee_code
             FROM {$items_table} i
             LEFT JOIN {$emp_table} e ON e.id = i.employee_id
             WHERE i.run_id = %d
             ORDER BY e.first_name ASC",
            $run_id
        ) );

        ?>
        <div class="sfs-hr-run-detail">
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&tab=runs' ) ); ?>">&larr; <?php esc_html_e( 'Back to Runs', 'sfs-hr' ); ?></a>
            </p>

            <h2><?php echo esc_html( $run->period_name ); ?> - <?php esc_html_e( 'Payroll Run', 'sfs-hr' ); ?> #<?php echo intval( $run->id ); ?></h2>

            <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">
                <div style="background:#f0f6fc; padding:15px; border-radius:6px; flex:1; min-width:150px;">
                    <div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Status', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px; font-weight:600;"><?php echo esc_html( ucfirst( $run->status ) ); ?></div>
                </div>
                <div style="background:#f0f6fc; padding:15px; border-radius:6px; flex:1; min-width:150px;">
                    <div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Employees', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px; font-weight:600;"><?php echo intval( $run->employee_count ); ?></div>
                </div>
                <div style="background:#f0f6fc; padding:15px; border-radius:6px; flex:1; min-width:150px;">
                    <div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Total Gross', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px; font-weight:600;"><?php echo esc_html( number_format( (float) $run->total_gross, 2 ) ); ?></div>
                </div>
                <div style="background:#e7f5ea; padding:15px; border-radius:6px; flex:1; min-width:150px;">
                    <div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Total Net', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px; font-weight:600; color:#00a32a;"><?php echo esc_html( number_format( (float) $run->total_net, 2 ) ); ?></div>
                </div>
            </div>

            <?php if ( $run->status === 'review' && current_user_can( 'sfs_hr_payroll_admin' ) ): ?>
            <div style="margin-bottom:20px;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                    <?php wp_nonce_field( 'sfs_hr_payroll_approve_run' ); ?>
                    <input type="hidden" name="action" value="sfs_hr_payroll_approve_run" />
                    <input type="hidden" name="run_id" value="<?php echo intval( $run->id ); ?>" />
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Approve Payroll', 'sfs-hr' ); ?></button>
                </form>
            </div>
            <?php endif; ?>

            <h3><?php esc_html_e( 'Employee Payroll Details', 'sfs-hr' ); ?></h3>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Base Salary', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Gross', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Deductions', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Net', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Days', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Absent', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'OT Hours', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $items as $item ):
                        $emp_name = trim( ( $item->first_name ?? '' ) . ' ' . ( $item->last_name ?? '' ) );
                        $emp_code = $item->emp_number ?: $item->employee_code;
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $emp_name ?: '#' . $item->employee_id ); ?></strong>
                            <?php if ( $emp_code ): ?>
                            <br><small class="description"><?php echo esc_html( $emp_code ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( number_format( (float) $item->base_salary, 2 ) ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $item->gross_salary, 2 ) ); ?></td>
                        <td style="color:#d9534f;"><?php echo esc_html( number_format( (float) $item->total_deductions, 2 ) ); ?></td>
                        <td style="font-weight:600; color:#00a32a;"><?php echo esc_html( number_format( (float) $item->net_salary, 2 ) ); ?></td>
                        <td><?php echo intval( $item->days_worked ); ?>/<?php echo intval( $item->working_days ); ?></td>
                        <td><?php echo intval( $item->days_absent ); ?></td>
                        <td><?php echo esc_html( $item->overtime_hours ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th><?php esc_html_e( 'Totals', 'sfs-hr' ); ?></th>
                        <th></th>
                        <th><?php echo esc_html( number_format( (float) $run->total_gross, 2 ) ); ?></th>
                        <th style="color:#d9534f;"><?php echo esc_html( number_format( (float) $run->total_deductions, 2 ) ); ?></th>
                        <th style="font-weight:600; color:#00a32a;"><?php echo esc_html( number_format( (float) $run->total_net, 2 ) ); ?></th>
                        <th colspan="3"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }

    private function render_components(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_salary_components';
        $components = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY type, display_order ASC" );

        $type_labels = [
            'earning'   => __( 'Earnings', 'sfs-hr' ),
            'deduction' => __( 'Deductions', 'sfs-hr' ),
            'benefit'   => __( 'Benefits', 'sfs-hr' ),
        ];

        ?>
        <div class="sfs-hr-salary-components">
            <h2><?php esc_html_e( 'Salary Components', 'sfs-hr' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Define salary components like allowances, deductions, and benefits.', 'sfs-hr' ); ?></p>

            <?php foreach ( $type_labels as $type => $label ):
                $type_components = array_filter( $components, fn( $c ) => $c->type === $type );
            ?>
            <h3 style="margin-top:20px;"><?php echo esc_html( $label ); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:80px;"><?php esc_html_e( 'Code', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Calculation', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Default Value', 'sfs-hr' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Active', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $type_components as $comp ): ?>
                    <tr>
                        <td><code><?php echo esc_html( $comp->code ); ?></code></td>
                        <td><strong><?php echo esc_html( $comp->name ); ?></strong></td>
                        <td>
                            <?php
                            switch ( $comp->calculation_type ) {
                                case 'fixed':
                                    esc_html_e( 'Fixed Amount', 'sfs-hr' );
                                    break;
                                case 'percentage':
                                    printf(
                                        esc_html__( '%s%% of %s', 'sfs-hr' ),
                                        esc_html( $comp->default_amount ),
                                        esc_html( str_replace( '_', ' ', $comp->percentage_of ?? '' ) )
                                    );
                                    break;
                                case 'formula':
                                    esc_html_e( 'Formula', 'sfs-hr' );
                                    break;
                            }
                            ?>
                        </td>
                        <td>
                            <?php if ( $comp->calculation_type === 'fixed' ): ?>
                            <?php echo esc_html( number_format( (float) $comp->default_amount, 2 ) ); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $comp->is_active ): ?>
                            <span style="color:#00a32a;">&#10003;</span>
                            <?php else: ?>
                            <span style="color:#d9534f;">&#10007;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_payslips(): void {
        global $wpdb;

        $payslips_table = $wpdb->prefix . 'sfs_hr_payslips';
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // For admin: show all payslips
        // For employees: show only their payslips
        $user_id = get_current_user_id();
        $is_admin = current_user_can( 'sfs_hr_payroll_admin' );

        if ( $is_admin ) {
            $payslips = $wpdb->get_results(
                "SELECT ps.*, p.name as period_name, e.first_name, e.last_name, e.emp_number,
                        i.net_salary
                 FROM {$payslips_table} ps
                 LEFT JOIN {$periods_table} p ON p.id = ps.period_id
                 LEFT JOIN {$emp_table} e ON e.id = ps.employee_id
                 LEFT JOIN {$items_table} i ON i.id = ps.payroll_item_id
                 ORDER BY ps.created_at DESC
                 LIMIT 100"
            );
        } else {
            // Get employee ID for current user
            $employee = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$emp_table} WHERE user_id = %d LIMIT 1",
                $user_id
            ) );

            if ( ! $employee ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'You are not linked to an employee record.', 'sfs-hr' ) . '</p></div>';
                return;
            }

            $payslips = $wpdb->get_results( $wpdb->prepare(
                "SELECT ps.*, p.name as period_name, e.first_name, e.last_name, e.emp_number,
                        i.net_salary
                 FROM {$payslips_table} ps
                 LEFT JOIN {$periods_table} p ON p.id = ps.period_id
                 LEFT JOIN {$emp_table} e ON e.id = ps.employee_id
                 LEFT JOIN {$items_table} i ON i.id = ps.payroll_item_id
                 WHERE ps.employee_id = %d
                 ORDER BY ps.created_at DESC
                 LIMIT 50",
                $employee->id
            ) );
        }

        ?>
        <div class="sfs-hr-payslips">
            <h2><?php esc_html_e( 'Payslips', 'sfs-hr' ); ?></h2>

            <?php if ( empty( $payslips ) ): ?>
            <div class="notice notice-info">
                <p><?php esc_html_e( 'No payslips found.', 'sfs-hr' ); ?></p>
            </div>
            <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Payslip #', 'sfs-hr' ); ?></th>
                        <?php if ( $is_admin ): ?>
                        <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e( 'Period', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Net Salary', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $payslips as $ps ):
                        $emp_name = trim( ( $ps->first_name ?? '' ) . ' ' . ( $ps->last_name ?? '' ) );
                    ?>
                    <tr>
                        <td><code><?php echo esc_html( $ps->payslip_number ); ?></code></td>
                        <?php if ( $is_admin ): ?>
                        <td><?php echo esc_html( $emp_name ); ?></td>
                        <?php endif; ?>
                        <td><?php echo esc_html( $ps->period_name ); ?></td>
                        <td style="font-weight:600;"><?php echo esc_html( number_format( (float) $ps->net_salary, 2 ) ); ?></td>
                        <td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $ps->created_at ) ) ); ?></td>
                        <td>
                            <button type="button" class="button button-small"><?php esc_html_e( 'View', 'sfs-hr' ); ?></button>
                            <?php if ( $ps->pdf_attachment_id ): ?>
                            <a href="<?php echo esc_url( wp_get_attachment_url( $ps->pdf_attachment_id ) ); ?>" class="button button-small" target="_blank">
                                <?php esc_html_e( 'Download PDF', 'sfs-hr' ); ?>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // Handler methods

    public function handle_create_period(): void {
        if ( ! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( 'Access denied' );
        }
        check_admin_referer( 'sfs_hr_payroll_create_period' );

        global $wpdb;

        $period_type = sanitize_key( $_POST['period_type'] ?? 'monthly' );
        $start_date = sanitize_text_field( $_POST['start_date'] ?? '' );
        $end_date = sanitize_text_field( $_POST['end_date'] ?? '' );
        $pay_date = sanitize_text_field( $_POST['pay_date'] ?? '' );
        $status = sanitize_key( $_POST['status'] ?? 'open' );
        $notes = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( ! $start_date || ! $end_date || ! $pay_date ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&tab=periods&error=missing_dates' ) );
            exit;
        }

        $name = PayrollModule::generate_period_name( $start_date, $end_date );
        $now = current_time( 'mysql' );

        $wpdb->insert( $wpdb->prefix . 'sfs_hr_payroll_periods', [
            'name'        => $name,
            'period_type' => $period_type,
            'start_date'  => $start_date,
            'end_date'    => $end_date,
            'pay_date'    => $pay_date,
            'status'      => $status,
            'notes'       => $notes,
            'created_by'  => get_current_user_id(),
            'created_at'  => $now,
            'updated_at'  => $now,
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&tab=periods&created=1' ) );
        exit;
    }

    public function handle_run_payroll(): void {
        if ( ! current_user_can( 'sfs_hr_payroll_run' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( 'Access denied' );
        }
        check_admin_referer( 'sfs_hr_payroll_run_payroll' );

        global $wpdb;

        $period_id = intval( $_POST['period_id'] ?? 0 );
        if ( ! $period_id ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&error=no_period' ) );
            exit;
        }

        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $period = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$periods_table} WHERE id = %d",
            $period_id
        ) );

        if ( ! $period || ! in_array( $period->status, [ 'open', 'processing' ], true ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&error=invalid_period' ) );
            exit;
        }

        // Get run number
        $run_number = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(run_number), 0) + 1 FROM {$runs_table} WHERE period_id = %d",
            $period_id
        ) );

        $now = current_time( 'mysql' );
        $user_id = get_current_user_id();

        // Create payroll run
        $wpdb->insert( $runs_table, [
            'period_id'     => $period_id,
            'run_number'    => $run_number,
            'status'        => 'calculating',
            'created_at'    => $now,
            'updated_at'    => $now,
        ] );

        $run_id = (int) $wpdb->insert_id;

        // Get all active employees
        $employees = $wpdb->get_col(
            "SELECT id FROM {$emp_table} WHERE status = 'active'"
        );

        $total_gross = 0;
        $total_deductions = 0;
        $total_net = 0;
        $employee_count = 0;

        foreach ( $employees as $emp_id ) {
            $calc = PayrollModule::calculate_employee_payroll( (int) $emp_id, $period_id );

            if ( isset( $calc['error'] ) ) {
                continue;
            }

            $wpdb->insert( $items_table, [
                'run_id'              => $run_id,
                'employee_id'         => $emp_id,
                'base_salary'         => $calc['base_salary'],
                'gross_salary'        => $calc['gross_salary'],
                'total_earnings'      => $calc['total_earnings'],
                'total_deductions'    => $calc['total_deductions'],
                'net_salary'          => $calc['net_salary'],
                'working_days'        => $calc['working_days'],
                'days_worked'         => $calc['days_worked'],
                'days_absent'         => $calc['days_absent'],
                'days_late'           => $calc['days_late'],
                'days_leave'          => $calc['days_leave'],
                'overtime_hours'      => $calc['overtime_hours'],
                'overtime_amount'     => $calc['overtime_amount'],
                'attendance_deduction'=> $calc['attendance_deduction'],
                'loan_deduction'      => $calc['loan_deduction'],
                'components_json'     => wp_json_encode( $calc['components'] ),
                'bank_name'           => $calc['bank_name'],
                'bank_account'        => $calc['bank_account'],
                'iban'                => $calc['iban'],
                'payment_status'      => 'pending',
                'created_at'          => $now,
                'updated_at'          => $now,
            ] );

            $total_gross += $calc['gross_salary'];
            $total_deductions += $calc['total_deductions'];
            $total_net += $calc['net_salary'];
            $employee_count++;
        }

        // Update run totals
        $wpdb->update( $runs_table, [
            'status'          => 'review',
            'total_gross'     => $total_gross,
            'total_deductions'=> $total_deductions,
            'total_net'       => $total_net,
            'employee_count'  => $employee_count,
            'calculated_at'   => $now,
            'calculated_by'   => $user_id,
            'updated_at'      => $now,
        ], [ 'id' => $run_id ] );

        // Audit Trail: payroll run created
        do_action( 'sfs_hr_payroll_run_created', $run_id, [
            'period_id'      => $period_id,
            'employee_count' => $employee_count,
            'total_net'      => $total_net,
        ] );

        // Update period status
        $wpdb->update( $periods_table, [
            'status'     => 'processing',
            'updated_at' => $now,
        ], [ 'id' => $period_id ] );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&tab=runs&view=detail&run_id=' . $run_id ) );
        exit;
    }

    public function handle_approve_run(): void {
        if ( ! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( 'Access denied' );
        }
        check_admin_referer( 'sfs_hr_payroll_approve_run' );

        global $wpdb;

        $run_id = intval( $_POST['run_id'] ?? 0 );
        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $payslips_table = $wpdb->prefix . 'sfs_hr_payslips';
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';

        $run = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$runs_table} WHERE id = %d",
            $run_id
        ) );

        if ( ! $run || $run->status !== 'review' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&tab=runs&error=invalid_run' ) );
            exit;
        }

        $now = current_time( 'mysql' );
        $user_id = get_current_user_id();

        // Approve run
        $wpdb->update( $runs_table, [
            'status'      => 'approved',
            'approved_at' => $now,
            'approved_by' => $user_id,
            'updated_at'  => $now,
        ], [ 'id' => $run_id ] );

        // Audit Trail: payroll run approved
        do_action( 'sfs_hr_payroll_run_approved', $run_id, [
            'total_net' => $run->total_net ?? 0,
        ] );

        // Generate payslips
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, employee_id FROM {$items_table} WHERE run_id = %d",
            $run_id
        ) );

        foreach ( $items as $item ) {
            $payslip_number = PayrollModule::generate_payslip_number( (int) $item->employee_id, (int) $run->period_id );

            $wpdb->insert( $payslips_table, [
                'payroll_item_id' => $item->id,
                'employee_id'     => $item->employee_id,
                'period_id'       => $run->period_id,
                'payslip_number'  => $payslip_number,
                'created_at'      => $now,
            ] );
        }

        // Update period status
        $wpdb->update( $periods_table, [
            'status'     => 'closed',
            'updated_at' => $now,
        ], [ 'id' => $run->period_id ] );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&tab=runs&view=detail&run_id=' . $run_id . '&approved=1' ) );
        exit;
    }

    public function handle_save_component(): void {
        // TODO: Implement component editing
    }

    public function handle_export_bank(): void {
        if ( ! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( 'Access denied' );
        }
        check_admin_referer( 'sfs_hr_payroll_export_bank' );

        global $wpdb;

        $run_id = intval( $_POST['run_id'] ?? 0 );
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $run = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, p.name as period_name FROM {$runs_table} r
             LEFT JOIN {$periods_table} p ON p.id = r.period_id
             WHERE r.id = %d",
            $run_id
        ) );

        if ( ! $run || ! in_array( $run->status, [ 'approved', 'paid' ], true ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&tab=runs&error=invalid_run' ) );
            exit;
        }

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, e.first_name, e.last_name, e.emp_number, e.employee_code
             FROM {$items_table} i
             LEFT JOIN {$emp_table} e ON e.id = i.employee_id
             WHERE i.run_id = %d
             ORDER BY e.first_name",
            $run_id
        ) );

        // Generate CSV for bank transfer
        $filename = sanitize_file_name( 'payroll-export-' . $run->period_name . '-' . wp_date( 'Y-m-d' ) . '.csv' );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // Header row
        fputcsv( $output, [
            'Employee ID',
            'Employee Name',
            'IBAN',
            'Bank Name',
            'Account Number',
            'Net Salary',
            'Currency',
            'Reference',
        ] );

        foreach ( $items as $item ) {
            $emp_name = trim( ( $item->first_name ?? '' ) . ' ' . ( $item->last_name ?? '' ) );
            $emp_code = $item->emp_number ?: $item->employee_code;

            fputcsv( $output, [
                $emp_code ?: $item->employee_id,
                $emp_name,
                $item->iban,
                $item->bank_name,
                $item->bank_account,
                number_format( (float) $item->net_salary, 2, '.', '' ),
                'SAR',
                'SALARY-' . $run->period_name . '-' . $item->employee_id,
            ] );
        }

        fclose( $output );
        exit;
    }
}
