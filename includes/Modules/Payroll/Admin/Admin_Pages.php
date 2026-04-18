<?php
/**
 * Payroll Admin Pages
 *
 * @package SFS\HR\Modules\Payroll\Admin
 */

namespace SFS\HR\Modules\Payroll\Admin;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Payroll\PayrollModule;
use SFS\HR\Modules\Payroll\Services;

defined( 'ABSPATH' ) || exit;

class Admin_Pages {

    public function hooks(): void {
        // Register standalone Payroll menu
        add_action( 'admin_menu', [ $this, 'register_menu' ], 25 );
        add_action( 'admin_post_sfs_hr_payroll_create_period', [ $this, 'handle_create_period' ] );
        add_action( 'admin_post_sfs_hr_payroll_run_payroll', [ $this, 'handle_run_payroll' ] );
        add_action( 'admin_post_sfs_hr_payroll_approve_run', [ $this, 'handle_approve_run' ] );
        add_action( 'admin_post_sfs_hr_payroll_save_component', [ $this, 'handle_save_component' ] );
        add_action( 'admin_post_sfs_hr_payroll_toggle_component', [ $this, 'handle_toggle_component' ] );
        add_action( 'admin_post_sfs_hr_payroll_save_tax_settings', [ $this, 'handle_save_tax_settings' ] );
        add_action( 'admin_post_sfs_hr_payroll_save_tax_bracket', [ $this, 'handle_save_tax_bracket' ] );
        add_action( 'admin_post_sfs_hr_payroll_delete_tax_bracket', [ $this, 'handle_delete_tax_bracket' ] );
        add_action( 'admin_post_sfs_hr_payroll_export_bank', [ $this, 'handle_export_bank' ] );
        add_action( 'admin_post_sfs_hr_payroll_export_attendance', [ $this, 'handle_export_attendance' ] );
        add_action( 'admin_post_sfs_hr_payroll_export_wps', [ $this, 'handle_export_wps' ] );
        add_action( 'admin_post_sfs_hr_payroll_export_detailed', [ $this, 'handle_export_detailed' ] );
        add_action( 'admin_post_sfs_hr_payroll_create_adjustment', [ $this, 'handle_create_adjustment' ] );
        add_action( 'admin_post_sfs_hr_payroll_approve_adjustment', [ $this, 'handle_approve_adjustment' ] );
        add_action( 'admin_post_sfs_hr_payroll_reject_adjustment', [ $this, 'handle_reject_adjustment' ] );
        add_action( 'admin_post_sfs_hr_payroll_lock_period', [ $this, 'handle_lock_period' ] );
        add_action( 'admin_post_sfs_hr_payroll_reopen_period', [ $this, 'handle_reopen_period' ] );
        add_action( 'admin_post_sfs_hr_payroll_submit_review', [ $this, 'handle_submit_review' ] );
        add_action( 'admin_post_sfs_hr_payroll_mark_paid', [ $this, 'handle_mark_paid' ] );
        add_action( 'admin_post_sfs_hr_payroll_reverse_run', [ $this, 'handle_reverse_run' ] );
    }

    /**
     * Render tab content for unified Finance & Exit page
     */
    public function render_tab_content( string $tab ): void {
        switch ( $tab ) {
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
            case 'export':
                $this->render_export();
                break;
            case 'tax':
                $this->render_tax();
                break;
            case 'adjustments':
                $this->render_adjustments();
                break;
            case 'audit':
                $this->render_audit();
                break;
            default:
                $this->render_overview();
                break;
        }
    }

    /**
     * Register standalone Payroll menu
     */
    public function register_menu(): void {
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
            'tax'         => __( 'Tax & Statutory', 'sfs-hr' ),
            'export'      => __( 'Export', 'sfs-hr' ),
            'adjustments' => __( 'Adjustments', 'sfs-hr' ),
            'audit'       => __( 'Audit Trail', 'sfs-hr' ),
        ];

        $active_tab = isset( $_GET['payroll_tab'] ) ? sanitize_key( $_GET['payroll_tab'] ) : 'overview';
        if ( ! isset( $tabs[ $active_tab ] ) ) {
            $active_tab = 'overview';
        }

        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $slug => $label ) {
            $url   = add_query_arg( 'payroll_tab', $slug, admin_url( 'admin.php?page=sfs-hr-payroll' ) );
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
            case 'tax':
                $this->render_tax();
                break;
            case 'export':
                $this->render_export();
                break;
            case 'adjustments':
                $this->render_adjustments();
                break;
            case 'audit':
                $this->render_audit();
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
                    <?php echo esc_html( __( ucfirst( $current_period->status ), 'sfs-hr' ) ); ?>
                </p>

                <?php if ( $current_period->status === 'open' && current_user_can( 'sfs_hr_payroll_run' ) ): ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:15px;">
                    <?php wp_nonce_field( 'sfs_hr_payroll_run_payroll' ); ?>
                    <input type="hidden" name="action" value="sfs_hr_payroll_run_payroll" />
                    <input type="hidden" name="period_id" value="<?php echo intval( $current_period->id ); ?>" />
                    <p>
                        <label>
                            <input type="checkbox" name="include_overtime" value="1" checked="checked" />
                            <?php esc_html_e( 'Include overtime in payroll', 'sfs-hr' ); ?>
                        </label>
                    </p>
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
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=periods' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Create Period', 'sfs-hr' ); ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div style="background:#f0f6fc; padding:20px; border-radius:8px;">
                <h3 style="margin:0 0 15px;"><?php esc_html_e( 'Quick Actions', 'sfs-hr' ); ?></h3>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=periods&action=new' ) ); ?>" class="button">
                        <?php esc_html_e( 'Create New Period', 'sfs-hr' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=components' ) ); ?>" class="button">
                        <?php esc_html_e( 'Manage Components', 'sfs-hr' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=runs' ) ); ?>" class="button">
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
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=periods&action=new' ) ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Create New Period', 'sfs-hr' ); ?>
                </a>
            </div>

            <?php if ( empty( $periods ) ): ?>
            <div class="notice notice-info">
                <p><?php esc_html_e( 'No payroll periods found.', 'sfs-hr' ); ?></p>
            </div>
            <?php else: ?>
            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
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
                        <td><?php echo esc_html( __( ucfirst( str_replace( '_', '-', $period->period_type ) ), 'sfs-hr' ) ); ?></td>
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
                                <?php echo esc_html( __( ucfirst( $period->status ), 'sfs-hr' ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( $period->status === 'open' ): ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                <?php wp_nonce_field( 'sfs_hr_payroll_run_payroll' ); ?>
                                <input type="hidden" name="action" value="sfs_hr_payroll_run_payroll" />
                                <input type="hidden" name="period_id" value="<?php echo intval( $period->id ); ?>" />
                                <label style="margin-right:6px;">
                                    <input type="checkbox" name="include_overtime" value="1" checked="checked" />
                                    <?php esc_html_e( 'OT', 'sfs-hr' ); ?>
                                </label>
                                <button type="submit" class="button button-small"><?php esc_html_e( 'Run', 'sfs-hr' ); ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
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
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=periods' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></a>
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
            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
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
                            <strong><?php echo esc_html( PayrollModule::generate_period_name( $run->start_date, $run->end_date ) ); ?></strong>
                            <?php if ( $run->run_number > 1 ): ?>
                            <small>(<?php printf( esc_html__( 'Run #%d', 'sfs-hr' ), intval( $run->run_number ) ); ?>)</small>
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
                                <?php echo esc_html( __( ucfirst( $run->status ), 'sfs-hr' ) ); ?>
                            </span>
                        </td>
                        <td><?php echo intval( $run->employee_count ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $run->total_gross, 2 ) ); ?></td>
                        <td><strong><?php echo esc_html( number_format( (float) $run->total_net, 2 ) ); ?></strong></td>
                        <td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $run->created_at ) ) ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=runs&view=detail&run_id=' . $run->id ) ); ?>" class="button button-small">
                                <?php esc_html_e( 'View', 'sfs-hr' ); ?>
                            </a>

                            <?php if ( $run->status === 'review' && ( current_user_can( 'sfs_hr_payroll_admin' ) || current_user_can( 'sfs_hr.manage' ) || current_user_can( 'manage_options' ) ) ): ?>
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
            </div><!-- .sfs-hr-table-responsive -->
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
            "SELECT i.*, e.first_name, e.last_name, e.employee_code
             FROM {$items_table} i
             LEFT JOIN {$emp_table} e ON e.id = i.employee_id
             WHERE i.run_id = %d
             ORDER BY e.first_name ASC",
            $run_id
        ) );

        ?>
        <div class="sfs-hr-run-detail">
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=runs' ) ); ?>">&larr; <?php esc_html_e( 'Back to Runs', 'sfs-hr' ); ?></a>
            </p>

            <h2><?php echo esc_html( PayrollModule::generate_period_name( $run->start_date, $run->end_date ) ); ?> - <?php esc_html_e( 'Payroll Run', 'sfs-hr' ); ?> #<?php echo intval( $run->id ); ?></h2>

            <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">
                <div style="background:#f0f6fc; padding:15px; border-radius:6px; flex:1; min-width:150px;">
                    <div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Status', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px; font-weight:600;"><?php echo esc_html( __( ucfirst( $run->status ), 'sfs-hr' ) ); ?></div>
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

            <?php if ( ! empty( $run->notes ) ): ?>
            <div class="notice notice-info" style="margin:0 0 20px;">
                <p><?php echo nl2br( esc_html( $run->notes ) ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( $run->status === 'review' && ( current_user_can( 'sfs_hr_payroll_admin' ) || current_user_can( 'sfs_hr.manage' ) || current_user_can( 'manage_options' ) ) ): ?>
            <div style="margin-bottom:20px;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                    <?php wp_nonce_field( 'sfs_hr_payroll_approve_run' ); ?>
                    <input type="hidden" name="action" value="sfs_hr_payroll_approve_run" />
                    <input type="hidden" name="run_id" value="<?php echo intval( $run->id ); ?>" />
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Approve Payroll', 'sfs-hr' ); ?></button>
                </form>
            </div>
            <?php endif; ?>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h3 style="margin:0;"><?php esc_html_e( 'Employee Payroll Details', 'sfs-hr' ); ?></h3>
                <button type="button" class="button button-small" id="sfs-toggle-all-details"><?php esc_html_e( 'Expand All', 'sfs-hr' ); ?></button>
            </div>

            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped" id="sfs-payroll-detail-table">
                <thead>
                    <tr>
                        <th style="width:40px;"></th>
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
                    <?php foreach ( $items as $idx => $item ):
                        $emp_name = trim( ( $item->first_name ?? '' ) . ' ' . ( $item->last_name ?? '' ) );
                        $emp_code = $item->employee_code ?? '';
                        $components = ! empty( $item->components_json ) ? json_decode( $item->components_json, true ) : [];
                        $earnings   = is_array( $components ) ? array_filter( $components, fn( $c ) => ( $c['type'] ?? '' ) === 'earning' ) : [];
                        $deductions = is_array( $components ) ? array_filter( $components, fn( $c ) => ( $c['type'] ?? '' ) === 'deduction' ) : [];
                        $benefits   = is_array( $components ) ? array_filter( $components, fn( $c ) => ( $c['type'] ?? '' ) === 'benefit' ) : [];
                    ?>
                    <tr class="sfs-row-toggle" data-detail="sfs-detail-<?php echo intval( $idx ); ?>" style="cursor:pointer;">
                        <td style="text-align:center;">
                            <span class="dashicons dashicons-arrow-right-alt2 sfs-detail-arrow" style="transition:transform .2s;"></span>
                        </td>
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
                    <tr id="sfs-detail-<?php echo intval( $idx ); ?>" class="sfs-detail-row" style="display:none;">
                        <td colspan="9" style="padding:0;">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0; border-top:2px solid #2271b1;">

                                <!-- Earnings -->
                                <div style="padding:15px; border-right:1px solid #dcdcde;">
                                    <h4 style="margin:0 0 10px; color:#00a32a; font-size:13px; text-transform:uppercase; letter-spacing:.5px;">
                                        <?php esc_html_e( 'Earnings', 'sfs-hr' ); ?>
                                    </h4>
                                    <table style="width:100%; font-size:13px; border-collapse:collapse;">
                                        <?php foreach ( $earnings as $c ):
                                            $c_name = $c['name'] ?? $c['code'] ?? __( 'Item', 'sfs-hr' );
                                            $c_amt  = (float) ( $c['amount'] ?? 0 );
                                        ?>
                                        <tr>
                                            <td style="padding:3px 0;"><?php echo esc_html( $c_name ); ?></td>
                                            <td style="padding:3px 0; text-align:right; font-weight:500; color:#00a32a;"><?php echo esc_html( number_format( $c_amt, 2 ) ); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if ( (float) ( $item->overtime_amount ?? 0 ) > 0 && empty( array_filter( $earnings, fn( $c ) => ( $c['code'] ?? '' ) === 'OVERTIME' ) ) ): ?>
                                        <tr>
                                            <td style="padding:3px 0;"><?php esc_html_e( 'Overtime', 'sfs-hr' ); ?></td>
                                            <td style="padding:3px 0; text-align:right; font-weight:500; color:#00a32a;"><?php echo esc_html( number_format( (float) $item->overtime_amount, 2 ) ); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr style="border-top:1px solid #dcdcde; font-weight:700;">
                                            <td style="padding:6px 0;"><?php esc_html_e( 'Total Gross', 'sfs-hr' ); ?></td>
                                            <td style="padding:6px 0; text-align:right; color:#00a32a;"><?php echo esc_html( number_format( (float) $item->gross_salary, 2 ) ); ?></td>
                                        </tr>
                                    </table>

                                    <?php if ( ! empty( $benefits ) ): ?>
                                    <h4 style="margin:12px 0 8px; color:#0073aa; font-size:13px; text-transform:uppercase; letter-spacing:.5px;">
                                        <?php esc_html_e( 'Benefits (Company)', 'sfs-hr' ); ?>
                                    </h4>
                                    <table style="width:100%; font-size:13px; border-collapse:collapse;">
                                        <?php foreach ( $benefits as $c ): ?>
                                        <tr>
                                            <td style="padding:3px 0;"><?php echo esc_html( $c['name'] ?? $c['code'] ?? '' ); ?></td>
                                            <td style="padding:3px 0; text-align:right; color:#0073aa;"><?php echo esc_html( number_format( (float) ( $c['amount'] ?? 0 ), 2 ) ); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </table>
                                    <?php endif; ?>
                                </div>

                                <!-- Deductions -->
                                <div style="padding:15px;">
                                    <h4 style="margin:0 0 10px; color:#d9534f; font-size:13px; text-transform:uppercase; letter-spacing:.5px;">
                                        <?php esc_html_e( 'Deductions', 'sfs-hr' ); ?>
                                    </h4>
                                    <table style="width:100%; font-size:13px; border-collapse:collapse;">
                                        <?php foreach ( $deductions as $c ):
                                            $c_name = $c['name'] ?? $c['code'] ?? __( 'Item', 'sfs-hr' );
                                            $c_amt  = (float) ( $c['amount'] ?? 0 );
                                        ?>
                                        <tr>
                                            <td style="padding:3px 0;"><?php echo esc_html( $c_name ); ?></td>
                                            <td style="padding:3px 0; text-align:right; font-weight:500; color:#d9534f;"><?php echo esc_html( number_format( $c_amt, 2 ) ); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if ( (float) ( $item->attendance_deduction ?? 0 ) > 0 && empty( array_filter( $deductions, fn( $c ) => in_array( $c['code'] ?? '', [ 'ABSENCE', 'LATE' ], true ) ) ) ): ?>
                                        <tr>
                                            <td style="padding:3px 0;"><?php esc_html_e( 'Attendance Deduction', 'sfs-hr' ); ?></td>
                                            <td style="padding:3px 0; text-align:right; font-weight:500; color:#d9534f;"><?php echo esc_html( number_format( (float) $item->attendance_deduction, 2 ) ); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ( (float) ( $item->loan_deduction ?? 0 ) > 0 && empty( array_filter( $deductions, fn( $c ) => ( $c['code'] ?? '' ) === 'LOAN' ) ) ): ?>
                                        <tr>
                                            <td style="padding:3px 0;"><?php esc_html_e( 'Loan Installment', 'sfs-hr' ); ?></td>
                                            <td style="padding:3px 0; text-align:right; font-weight:500; color:#d9534f;"><?php echo esc_html( number_format( (float) $item->loan_deduction, 2 ) ); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr style="border-top:1px solid #dcdcde; font-weight:700;">
                                            <td style="padding:6px 0;"><?php esc_html_e( 'Total Deductions', 'sfs-hr' ); ?></td>
                                            <td style="padding:6px 0; text-align:right; color:#d9534f;"><?php echo esc_html( number_format( (float) $item->total_deductions, 2 ) ); ?></td>
                                        </tr>
                                    </table>

                                    <!-- Attendance Summary -->
                                    <h4 style="margin:12px 0 8px; color:#646970; font-size:13px; text-transform:uppercase; letter-spacing:.5px;">
                                        <?php esc_html_e( 'Attendance', 'sfs-hr' ); ?>
                                    </h4>
                                    <table style="width:100%; font-size:13px; border-collapse:collapse;">
                                        <tr>
                                            <td style="padding:3px 0;"><?php esc_html_e( 'Working Days', 'sfs-hr' ); ?></td>
                                            <td style="padding:3px 0; text-align:right;"><?php echo intval( $item->days_worked ); ?>/<?php echo intval( $item->working_days ); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="padding:3px 0;"><?php esc_html_e( 'Days Absent', 'sfs-hr' ); ?></td>
                                            <td style="padding:3px 0; text-align:right;"><?php echo intval( $item->days_absent ); ?></td>
                                        </tr>
                                        <?php if ( (int) ( $item->days_late ?? 0 ) > 0 ): ?>
                                        <tr>
                                            <td style="padding:3px 0;"><?php esc_html_e( 'Days Late', 'sfs-hr' ); ?></td>
                                            <td style="padding:3px 0; text-align:right;"><?php echo intval( $item->days_late ); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ( (int) ( $item->days_leave ?? 0 ) > 0 ): ?>
                                        <tr>
                                            <td style="padding:3px 0;"><?php esc_html_e( 'Days on Leave', 'sfs-hr' ); ?></td>
                                            <td style="padding:3px 0; text-align:right;"><?php echo intval( $item->days_leave ); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ( (float) ( $item->overtime_hours ?? 0 ) > 0 ): ?>
                                        <tr>
                                            <td style="padding:3px 0;"><?php esc_html_e( 'Overtime Hours', 'sfs-hr' ); ?></td>
                                            <td style="padding:3px 0; text-align:right;"><?php echo esc_html( $item->overtime_hours ); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>

                            </div>
                            <!-- Net pay bar -->
                            <div style="padding:10px 15px; background:#f0f6fc; border-top:1px solid #dcdcde; display:flex; justify-content:space-between; align-items:center; font-weight:700;">
                                <span><?php esc_html_e( 'Net Salary', 'sfs-hr' ); ?></span>
                                <span style="font-size:16px; color:#00a32a;"><?php echo esc_html( number_format( (float) $item->net_salary, 2 ) ); ?></span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th></th>
                        <th><?php esc_html_e( 'Totals', 'sfs-hr' ); ?></th>
                        <th></th>
                        <th><?php echo esc_html( number_format( (float) $run->total_gross, 2 ) ); ?></th>
                        <th style="color:#d9534f;"><?php echo esc_html( number_format( (float) $run->total_deductions, 2 ) ); ?></th>
                        <th style="font-weight:600; color:#00a32a;"><?php echo esc_html( number_format( (float) $run->total_net, 2 ) ); ?></th>
                        <th colspan="3"></th>
                    </tr>
                </tfoot>
            </table>
            </div><!-- .sfs-hr-table-responsive -->
        </div>

        <script>
        (function(){
            // Toggle individual detail row
            document.querySelectorAll('.sfs-row-toggle').forEach(function(row){
                row.addEventListener('click', function(){
                    var detailId = this.getAttribute('data-detail');
                    var detail = document.getElementById(detailId);
                    var arrow = this.querySelector('.sfs-detail-arrow');
                    if(!detail) return;
                    var visible = detail.style.display !== 'none';
                    detail.style.display = visible ? 'none' : 'table-row';
                    if(arrow) arrow.style.transform = visible ? '' : 'rotate(90deg)';
                });
            });

            // Expand/Collapse all
            var allBtn = document.getElementById('sfs-toggle-all-details');
            if(allBtn){
                var expanded = false;
                allBtn.addEventListener('click', function(){
                    expanded = !expanded;
                    document.querySelectorAll('.sfs-detail-row').forEach(function(r){ r.style.display = expanded ? 'table-row' : 'none'; });
                    document.querySelectorAll('.sfs-detail-arrow').forEach(function(a){ a.style.transform = expanded ? 'rotate(90deg)' : ''; });
                    allBtn.textContent = expanded ? '<?php echo esc_js( __( 'Collapse All', 'sfs-hr' ) ); ?>' : '<?php echo esc_js( __( 'Expand All', 'sfs-hr' ) ); ?>';
                });
            }
        })();
        </script>
        <?php
    }

    private function render_components(): void {
        global $wpdb;

        $action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        $comp_id = isset( $_GET['comp_id'] ) ? intval( $_GET['comp_id'] ) : 0;

        if ( $action === 'new' || ( $action === 'edit' && $comp_id ) ) {
            $this->render_component_form( $comp_id );
            return;
        }

        $table = $wpdb->prefix . 'sfs_hr_salary_components';
        $components = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY type, display_order ASC" );

        $type_labels = [
            'earning'   => __( 'Earnings', 'sfs-hr' ),
            'deduction' => __( 'Deductions', 'sfs-hr' ),
            'benefit'   => __( 'Benefits', 'sfs-hr' ),
        ];

        ?>
        <div class="sfs-hr-salary-components">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <div>
                    <h2 style="margin:0;"><?php esc_html_e( 'Salary Components', 'sfs-hr' ); ?></h2>
                    <p class="description" style="margin-top:5px;"><?php esc_html_e( 'Define salary components like allowances, deductions, and benefits.', 'sfs-hr' ); ?></p>
                </div>
                <?php if ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'manage_options' ) ): ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=components&action=new' ) ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Add New Component', 'sfs-hr' ); ?>
                </a>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $_GET['saved'] ) ): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Component saved.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['toggled'] ) ): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Component status updated.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['comp_error'] ) ): ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( urldecode( $_GET['comp_error'] ) ); ?></p></div>
            <?php endif; ?>

            <?php foreach ( $type_labels as $type => $label ):
                $type_components = array_filter( $components, fn( $c ) => $c->type === $type );
            ?>
            <h3 style="margin-top:20px;"><?php echo esc_html( $label ); ?></h3>
            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:80px;"><?php esc_html_e( 'Code', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Calculation', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Default Value', 'sfs-hr' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Active', 'sfs-hr' ); ?></th>
                        <?php if ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'manage_options' ) ): ?>
                        <th style="width:140px;"><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $type_components ) ): ?>
                    <tr><td colspan="<?php echo ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'manage_options' ) ) ? 6 : 5; ?>" style="text-align:center; color:#646970;"><?php esc_html_e( 'No components.', 'sfs-hr' ); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ( $type_components as $comp ): ?>
                    <tr<?php echo ! $comp->is_active ? ' style="opacity:.6;"' : ''; ?>>
                        <td><code><?php echo esc_html( $comp->code ); ?></code></td>
                        <td>
                            <strong><?php echo esc_html( __( $comp->name, 'sfs-hr' ) ); ?></strong>
                            <?php if ( ! empty( $comp->description ) ): ?>
                            <br><small class="description"><?php echo esc_html( $comp->description ); ?></small>
                            <?php endif; ?>
                        </td>
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
                                        esc_html( ucwords( str_replace( '_', ' ', $comp->percentage_of ?? '' ) ) )
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
                        <?php if ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'manage_options' ) ): ?>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=components&action=edit&comp_id=' . intval( $comp->id ) ) ); ?>" class="button button-small">
                                <?php esc_html_e( 'Edit', 'sfs-hr' ); ?>
                            </a>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                <?php wp_nonce_field( 'sfs_hr_payroll_toggle_component' ); ?>
                                <input type="hidden" name="action" value="sfs_hr_payroll_toggle_component" />
                                <input type="hidden" name="comp_id" value="<?php echo intval( $comp->id ); ?>" />
                                <button type="submit" class="button button-small" title="<?php echo $comp->is_active ? esc_attr__( 'Deactivate', 'sfs-hr' ) : esc_attr__( 'Activate', 'sfs-hr' ); ?>">
                                    <?php echo $comp->is_active ? esc_html__( 'Deactivate', 'sfs-hr' ) : esc_html__( 'Activate', 'sfs-hr' ); ?>
                                </button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- .sfs-hr-table-responsive -->
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_component_form( int $comp_id = 0 ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_salary_components';
        $comp  = null;

        if ( $comp_id ) {
            $comp = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $comp_id ) );
            if ( ! $comp ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Component not found.', 'sfs-hr' ) . '</p></div>';
                return;
            }
        }

        $is_edit = (bool) $comp;
        ?>
        <div class="sfs-hr-component-form" style="max-width:700px;">
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=components' ) ); ?>">&larr; <?php esc_html_e( 'Back to Components', 'sfs-hr' ); ?></a>
            </p>
            <h2><?php echo $is_edit ? esc_html__( 'Edit Component', 'sfs-hr' ) : esc_html__( 'Add New Component', 'sfs-hr' ); ?></h2>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sfs_hr_payroll_save_component' ); ?>
                <input type="hidden" name="action" value="sfs_hr_payroll_save_component" />
                <?php if ( $is_edit ): ?>
                <input type="hidden" name="comp_id" value="<?php echo intval( $comp->id ); ?>" />
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><label for="comp_code"><?php esc_html_e( 'Code', 'sfs-hr' ); ?> <span style="color:red;">*</span></label></th>
                        <td>
                            <input type="text" name="code" id="comp_code" value="<?php echo esc_attr( $comp->code ?? '' ); ?>"
                                   class="regular-text" required pattern="[A-Z0-9_]+" style="text-transform:uppercase;"
                                   <?php echo $is_edit ? 'readonly' : ''; ?> />
                            <p class="description"><?php esc_html_e( 'Unique code (uppercase letters, numbers, underscores). Cannot be changed after creation.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="comp_name"><?php esc_html_e( 'Name (English)', 'sfs-hr' ); ?> <span style="color:red;">*</span></label></th>
                        <td>
                            <input type="text" name="name" id="comp_name" value="<?php echo esc_attr( $comp->name ?? '' ); ?>" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="comp_name_ar"><?php esc_html_e( 'Name (Arabic)', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="text" name="name_ar" id="comp_name_ar" value="<?php echo esc_attr( $comp->name_ar ?? '' ); ?>" class="regular-text" dir="rtl" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="comp_type"><?php esc_html_e( 'Type', 'sfs-hr' ); ?></label></th>
                        <td>
                            <select name="type" id="comp_type" class="regular-text">
                                <option value="earning" <?php selected( $comp->type ?? '', 'earning' ); ?>><?php esc_html_e( 'Earning', 'sfs-hr' ); ?></option>
                                <option value="deduction" <?php selected( $comp->type ?? '', 'deduction' ); ?>><?php esc_html_e( 'Deduction', 'sfs-hr' ); ?></option>
                                <option value="benefit" <?php selected( $comp->type ?? '', 'benefit' ); ?>><?php esc_html_e( 'Benefit', 'sfs-hr' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="comp_calc"><?php esc_html_e( 'Calculation Type', 'sfs-hr' ); ?></label></th>
                        <td>
                            <select name="calculation_type" id="comp_calc" class="regular-text">
                                <option value="fixed" <?php selected( $comp->calculation_type ?? '', 'fixed' ); ?>><?php esc_html_e( 'Fixed Amount', 'sfs-hr' ); ?></option>
                                <option value="percentage" <?php selected( $comp->calculation_type ?? '', 'percentage' ); ?>><?php esc_html_e( 'Percentage', 'sfs-hr' ); ?></option>
                                <option value="formula" <?php selected( $comp->calculation_type ?? '', 'formula' ); ?>><?php esc_html_e( 'Formula', 'sfs-hr' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="comp_amount"><?php esc_html_e( 'Default Amount / Percentage', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" name="default_amount" id="comp_amount" value="<?php echo esc_attr( $comp->default_amount ?? '0' ); ?>" step="0.01" min="0" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'For fixed: the default amount. For percentage: the percentage value (e.g. 25 for 25%).', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr id="percentage_of_row" style="<?php echo ( $comp->calculation_type ?? '' ) !== 'percentage' ? 'display:none;' : ''; ?>">
                        <th><label for="comp_pct_of"><?php esc_html_e( 'Percentage Of', 'sfs-hr' ); ?></label></th>
                        <td>
                            <select name="percentage_of" id="comp_pct_of" class="regular-text">
                                <option value="base_salary" <?php selected( $comp->percentage_of ?? '', 'base_salary' ); ?>><?php esc_html_e( 'Base Salary', 'sfs-hr' ); ?></option>
                                <option value="gross_salary" <?php selected( $comp->percentage_of ?? '', 'gross_salary' ); ?>><?php esc_html_e( 'Gross Salary', 'sfs-hr' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr id="formula_expression_row" style="<?php echo ( $comp->calculation_type ?? '' ) !== 'formula' ? 'display:none;' : ''; ?>">
                        <th><label for="comp_formula_expr"><?php esc_html_e( 'Formula Expression', 'sfs-hr' ); ?></label></th>
                        <td>
                            <textarea name="formula_expression" id="comp_formula_expr" rows="3" class="large-text" style="font-family:monospace;"><?php echo esc_textarea( $comp->formula_expression ?? '' ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Safe arithmetic expression. Leave empty to use the built-in behaviour for OVERTIME / ABSENCE / LATE components.', 'sfs-hr' ); ?><br>
                                <strong><?php esc_html_e( 'Examples:', 'sfs-hr' ); ?></strong>
                                <code>base_salary * 0.25</code>,
                                <code>overtime_hours * (base_salary / (working_days_full * 8)) * 1.5</code>,
                                <code>if(tenure_years &gt;= 5, 500, 250)</code>
                            </p>
                            <p class="description">
                                <strong><?php esc_html_e( 'Available variables:', 'sfs-hr' ); ?></strong>
                                <code>base_salary</code>, <code>gross_salary</code>, <code>pro_rata</code>,
                                <code>working_days</code>, <code>working_days_full</code>,
                                <code>days_worked</code>, <code>days_absent</code>, <code>days_late</code>, <code>days_leave</code>,
                                <code>overtime_hours</code>, <code>overtime_minutes</code>,
                                <code>tenure_years</code>, <code>department_id</code>, <code>position_id</code>,
                                <code>default_amount</code>, <code>employee_amount</code>.
                            </p>
                            <p class="description">
                                <strong><?php esc_html_e( 'Supported operators & functions:', 'sfs-hr' ); ?></strong>
                                <code>+ - * / %</code>, <code>^</code> (power),
                                <code>= != &lt; &gt; &lt;= &gt;=</code>, <code>and</code>, <code>or</code>, <code>not</code>,
                                <code>min(a,b)</code>, <code>max(a,b)</code>, <code>round(x,dp)</code>,
                                <code>abs(x)</code>, <code>floor(x)</code>, <code>ceil(x)</code>, <code>if(cond, a, b)</code>.
                            </p>
                        </td>
                    </tr>
                    <tr id="formula_condition_row" style="<?php echo ( $comp->calculation_type ?? '' ) !== 'formula' ? 'display:none;' : ''; ?>">
                        <th><label for="comp_formula_cond"><?php esc_html_e( 'Formula Condition', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="text" name="formula_condition" id="comp_formula_cond" value="<?php echo esc_attr( $comp->formula_condition ?? '' ); ?>" class="large-text" style="font-family:monospace;" />
                            <p class="description">
                                <?php esc_html_e( 'Optional gate — the formula runs only when this expression is truthy (non-zero). Leave empty to always apply.', 'sfs-hr' ); ?>
                                <?php esc_html_e( 'Example:', 'sfs-hr' ); ?>
                                <code>tenure_years &gt;= 1</code>,
                                <code>department_id = 3</code>.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="comp_taxable"><?php esc_html_e( 'Taxable', 'sfs-hr' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_taxable" id="comp_taxable" value="1" <?php checked( $comp->is_taxable ?? 0, 1 ); ?> />
                                <?php esc_html_e( 'This component is subject to tax', 'sfs-hr' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="comp_active"><?php esc_html_e( 'Active', 'sfs-hr' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_active" id="comp_active" value="1" <?php checked( $is_edit ? ( $comp->is_active ?? 1 ) : 1, 1 ); ?> />
                                <?php esc_html_e( 'Include in payroll calculations', 'sfs-hr' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="comp_order"><?php esc_html_e( 'Display Order', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" name="display_order" id="comp_order" value="<?php echo intval( $comp->display_order ?? 10 ); ?>" min="0" step="1" style="width:80px;" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="comp_desc"><?php esc_html_e( 'Description', 'sfs-hr' ); ?></label></th>
                        <td>
                            <textarea name="description" id="comp_desc" rows="3" class="large-text"><?php echo esc_textarea( $comp->description ?? '' ); ?></textarea>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__( 'Update Component', 'sfs-hr' ) : esc_html__( 'Create Component', 'sfs-hr' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=components' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></a>
                </p>
            </form>
        </div>

        <script>
        document.getElementById('comp_calc').addEventListener('change', function(){
            document.getElementById('percentage_of_row').style.display = this.value === 'percentage' ? '' : 'none';
            var isFormula = this.value === 'formula';
            document.getElementById('formula_expression_row').style.display = isFormula ? '' : 'none';
            document.getElementById('formula_condition_row').style.display = isFormula ? '' : 'none';
        });
        document.getElementById('comp_code').addEventListener('input', function(){
            this.value = this.value.toUpperCase().replace(/[^A-Z0-9_]/g, '');
        });
        </script>
        <?php
    }

    private function render_payslips(): void {
        global $wpdb;

        $payslips_table = $wpdb->prefix . 'sfs_hr_payslips';
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Detail view routing (same pattern as render_runs)
        $view       = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
        $payslip_id = isset( $_GET['payslip_id'] ) ? intval( $_GET['payslip_id'] ) : 0;

        if ( $view === 'detail' && $payslip_id ) {
            $this->render_payslip_detail( $payslip_id );
            return;
        }

        // For admin: show all payslips
        // For employees: show only their payslips
        $user_id = get_current_user_id();
        $is_admin = current_user_can( 'sfs_hr_payroll_admin' )
                 || current_user_can( 'sfs_hr.manage' )
                 || current_user_can( 'manage_options' );

        if ( $is_admin ) {
            $payslips = $wpdb->get_results(
                "SELECT ps.*, p.name as period_name, e.first_name, e.last_name, e.employee_code,
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
                "SELECT ps.*, p.name as period_name, e.first_name, e.last_name, e.employee_code,
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
                <p><?php esc_html_e( 'No payslips found. Payslips are automatically generated when a payroll run is approved.', 'sfs-hr' ); ?></p>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=runs' ) ); ?>" class="button button-small">
                        <?php esc_html_e( 'View Payroll Runs', 'sfs-hr' ); ?>
                    </a>
                </p>
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
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=payslips&view=detail&payslip_id=' . intval( $ps->id ) ) ); ?>" class="button button-small"><?php esc_html_e( 'View', 'sfs-hr' ); ?></a>
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

    private function render_payslip_detail( int $payslip_id ): void {
        global $wpdb;

        $payslips_table = $wpdb->prefix . 'sfs_hr_payslips';
        $items_table    = $wpdb->prefix . 'sfs_hr_payroll_items';
        $periods_table  = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $emp_table      = $wpdb->prefix . 'sfs_hr_employees';

        $is_admin = current_user_can( 'sfs_hr_payroll_admin' )
                 || current_user_can( 'sfs_hr.manage' )
                 || current_user_can( 'manage_options' );

        // Fetch payslip with joined data
        $ps = $wpdb->get_row( $wpdb->prepare(
            "SELECT ps.*, p.name AS period_name, p.start_date, p.end_date, p.pay_date,
                    e.first_name, e.last_name, e.employee_code,
                    COALESCE(i.bank_name, e.bank_name) AS bank_name,
                    COALESCE(i.bank_account, e.bank_account) AS bank_account,
                    COALESCE(i.iban, e.iban) AS iban,
                    i.base_salary, i.gross_salary, i.total_deductions, i.net_salary,
                    i.working_days, i.days_worked, i.days_absent, i.days_late, i.days_leave,
                    i.overtime_hours, i.components_json
             FROM {$payslips_table} ps
             LEFT JOIN {$periods_table} p ON p.id = ps.period_id
             LEFT JOIN {$emp_table} e ON e.id = ps.employee_id
             LEFT JOIN {$items_table} i ON i.id = ps.payroll_item_id
             WHERE ps.id = %d",
            $payslip_id
        ) );

        if ( ! $ps ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Payslip not found.', 'sfs-hr' ) . '</p></div>';
            return;
        }

        // Non-admin: only allow viewing own payslips
        if ( ! $is_admin ) {
            $user_emp = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$emp_table} WHERE user_id = %d LIMIT 1",
                get_current_user_id()
            ) );
            if ( (int) $ps->employee_id !== (int) $user_emp ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Access denied.', 'sfs-hr' ) . '</p></div>';
                return;
            }
        }

        $emp_name   = trim( ( $ps->first_name ?? '' ) . ' ' . ( $ps->last_name ?? '' ) );
        $components = ! empty( $ps->components_json ) ? json_decode( $ps->components_json, true ) : [];
        $earnings   = is_array( $components ) ? array_filter( $components, fn( $c ) => ( $c['type'] ?? '' ) === 'earning' ) : [];
        $deductions = is_array( $components ) ? array_filter( $components, fn( $c ) => ( $c['type'] ?? '' ) === 'deduction' ) : [];

        ?>
        <div class="sfs-hr-payslip-detail">
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=payslips' ) ); ?>">&larr; <?php esc_html_e( 'Back to Payslips', 'sfs-hr' ); ?></a>
            </p>

            <h2><?php esc_html_e( 'Payslip', 'sfs-hr' ); ?> #<?php echo esc_html( $ps->payslip_number ); ?></h2>

            <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">
                <div style="background:#f0f6fc; padding:15px; border-radius:6px; flex:1; min-width:150px;">
                    <div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></div>
                    <div style="font-size:16px; font-weight:600;"><?php echo esc_html( $emp_name ); ?></div>
                    <?php if ( ! empty( $ps->employee_code ) ): ?>
                    <div style="font-size:12px; color:#646970;">#<?php echo esc_html( $ps->employee_code ); ?></div>
                    <?php endif; ?>
                </div>
                <div style="background:#f0f6fc; padding:15px; border-radius:6px; flex:1; min-width:150px;">
                    <div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Period', 'sfs-hr' ); ?></div>
                    <div style="font-size:16px; font-weight:600;"><?php echo esc_html( $ps->period_name ); ?></div>
                    <?php if ( ! empty( $ps->pay_date ) ): ?>
                    <div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Pay Date:', 'sfs-hr' ); ?> <?php echo esc_html( date_i18n( 'M j, Y', strtotime( $ps->pay_date ) ) ); ?></div>
                    <?php endif; ?>
                </div>
                <div style="background:#f0f6fc; padding:15px; border-radius:6px; flex:1; min-width:150px;">
                    <div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Base Salary', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px; font-weight:600;"><?php echo esc_html( number_format( (float) ( $ps->base_salary ?? 0 ), 2 ) ); ?></div>
                </div>
                <div style="background:#e7f5ea; padding:15px; border-radius:6px; flex:1; min-width:150px;">
                    <div style="font-size:12px; color:#646970;"><?php esc_html_e( 'Net Salary', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px; font-weight:600; color:#00a32a;"><?php echo esc_html( number_format( (float) ( $ps->net_salary ?? 0 ), 2 ) ); ?></div>
                </div>
            </div>

            <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">
                <div style="background:#fff; border:1px solid #ddd; padding:15px; border-radius:6px; flex:1; min-width:250px;">
                    <h3 style="margin:0 0 10px;"><?php esc_html_e( 'Attendance', 'sfs-hr' ); ?></h3>
                    <table class="widefat" style="border:0;">
                        <tr><td><?php esc_html_e( 'Working Days', 'sfs-hr' ); ?></td><td style="text-align:right; font-weight:600;"><?php echo esc_html( $ps->working_days ?? '—' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'Days Worked', 'sfs-hr' ); ?></td><td style="text-align:right; font-weight:600;"><?php echo esc_html( $ps->days_worked ?? '—' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'Days Absent', 'sfs-hr' ); ?></td><td style="text-align:right; font-weight:600;"><?php echo esc_html( $ps->days_absent ?? '0' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'Days Late', 'sfs-hr' ); ?></td><td style="text-align:right; font-weight:600;"><?php echo esc_html( $ps->days_late ?? '0' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'Days Leave', 'sfs-hr' ); ?></td><td style="text-align:right; font-weight:600;"><?php echo esc_html( $ps->days_leave ?? '0' ); ?></td></tr>
                        <tr><td><?php esc_html_e( 'Overtime Hours', 'sfs-hr' ); ?></td><td style="text-align:right; font-weight:600;"><?php echo esc_html( $ps->overtime_hours ?? '0' ); ?></td></tr>
                    </table>
                </div>

                <?php if ( ! empty( $ps->bank_name ) || ! empty( $ps->iban ) ): ?>
                <div style="background:#fff; border:1px solid #ddd; padding:15px; border-radius:6px; flex:1; min-width:250px;">
                    <h3 style="margin:0 0 10px;"><?php esc_html_e( 'Bank Details', 'sfs-hr' ); ?></h3>
                    <table class="widefat" style="border:0;">
                        <?php if ( ! empty( $ps->bank_name ) ): ?>
                        <tr><td><?php esc_html_e( 'Bank', 'sfs-hr' ); ?></td><td style="text-align:right; font-weight:600;"><?php echo esc_html( $ps->bank_name ); ?></td></tr>
                        <?php endif; ?>
                        <?php if ( ! empty( $ps->bank_account ) ): ?>
                        <tr><td><?php esc_html_e( 'Account', 'sfs-hr' ); ?></td><td style="text-align:right; font-weight:600;"><?php echo esc_html( $ps->bank_account ); ?></td></tr>
                        <?php endif; ?>
                        <?php if ( ! empty( $ps->iban ) ): ?>
                        <tr><td><?php esc_html_e( 'IBAN', 'sfs-hr' ); ?></td><td style="text-align:right; font-weight:600;"><?php echo esc_html( $ps->iban ); ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $earnings ) ): ?>
            <h3><?php esc_html_e( 'Earnings', 'sfs-hr' ); ?></h3>
            <table class="wp-list-table widefat striped" style="margin-bottom:20px;">
                <thead><tr><th><?php esc_html_e( 'Component', 'sfs-hr' ); ?></th><th style="text-align:right;"><?php esc_html_e( 'Amount', 'sfs-hr' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( $earnings as $c ): ?>
                    <tr><td><?php echo esc_html( $c['name'] ?? $c['code'] ?? '—' ); ?></td><td style="text-align:right;"><?php echo esc_html( number_format( (float) ( $c['amount'] ?? 0 ), 2 ) ); ?></td></tr>
                <?php endforeach; ?>
                    <tr style="font-weight:700;"><td><?php esc_html_e( 'Total Gross', 'sfs-hr' ); ?></td><td style="text-align:right;"><?php echo esc_html( number_format( (float) ( $ps->gross_salary ?? 0 ), 2 ) ); ?></td></tr>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ( ! empty( $deductions ) ): ?>
            <h3><?php esc_html_e( 'Deductions', 'sfs-hr' ); ?></h3>
            <table class="wp-list-table widefat striped" style="margin-bottom:20px;">
                <thead><tr><th><?php esc_html_e( 'Component', 'sfs-hr' ); ?></th><th style="text-align:right;"><?php esc_html_e( 'Amount', 'sfs-hr' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( $deductions as $c ): ?>
                    <tr><td><?php echo esc_html( $c['name'] ?? $c['code'] ?? '—' ); ?></td><td style="text-align:right;"><?php echo esc_html( number_format( (float) ( $c['amount'] ?? 0 ), 2 ) ); ?></td></tr>
                <?php endforeach; ?>
                    <tr style="font-weight:700;"><td><?php esc_html_e( 'Total Deductions', 'sfs-hr' ); ?></td><td style="text-align:right;"><?php echo esc_html( number_format( (float) ( $ps->total_deductions ?? 0 ), 2 ) ); ?></td></tr>
                </tbody>
            </table>
            <?php endif; ?>

            <div style="text-align:right; padding:15px; background:#e7f5ea; border-radius:6px; margin-bottom:20px;">
                <span style="font-size:14px; color:#646970;"><?php esc_html_e( 'Net Salary:', 'sfs-hr' ); ?></span>
                <span style="font-size:22px; font-weight:700; color:#00a32a; margin-inline-start:10px;"><?php echo esc_html( number_format( (float) ( $ps->net_salary ?? 0 ), 2 ) ); ?></span>
            </div>

            <?php if ( $ps->pdf_attachment_id ): ?>
            <p>
                <a href="<?php echo esc_url( wp_get_attachment_url( $ps->pdf_attachment_id ) ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'Download PDF', 'sfs-hr' ); ?>
                </a>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_create_period(): void {
        if ( ! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
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
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=periods&error=missing_dates' ) );
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

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=periods&created=1' ) );
        exit;
    }

    public function handle_run_payroll(): void {
        if ( ! current_user_can( 'sfs_hr_payroll_run' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_payroll_run_payroll' );

        global $wpdb;

        $period_id = intval( $_POST['period_id'] ?? 0 );
        $include_overtime = ! empty( $_POST['include_overtime'] );
        if ( ! $period_id ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&error=no_period' ) );
            exit;
        }

        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Prevent concurrent payroll runs using a transient lock
        $lock_key = 'sfs_hr_payroll_lock_' . $period_id;
        if ( get_transient( $lock_key ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&error=payroll_in_progress' ) );
            exit;
        }
        set_transient( $lock_key, get_current_user_id(), 600 ); // 10 minute lock

        $period = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$periods_table} WHERE id = %d",
            $period_id
        ) );

        if ( ! $period || ! in_array( $period->status, [ 'open', 'processing' ], true ) ) {
            delete_transient( $lock_key );
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&error=invalid_period' ) );
            exit;
        }

        // Wrap entire payroll run in a transaction
        $wpdb->query( 'START TRANSACTION' );

        try {
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

            if ( ! $run_id ) {
                throw new \RuntimeException( 'Failed to create payroll run record' );
            }

            // Get all active employees (excludes terminated/inactive)
            $employees = $wpdb->get_col(
                "SELECT id FROM {$emp_table} WHERE status = 'active'"
            );

            $total_gross = 0;
            $total_deductions = 0;
            $total_net = 0;
            $employee_count = 0;
            $errors = [];

            foreach ( $employees as $emp_id ) {
                $calc = PayrollModule::calculate_employee_payroll( (int) $emp_id, $period_id, [
                    'include_overtime' => $include_overtime,
                ] );

                if ( isset( $calc['error'] ) ) {
                    $errors[] = sprintf( 'Employee #%d: %s', $emp_id, $calc['error'] );
                    continue;
                }

                $inserted = $wpdb->insert( $items_table, [
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

                if ( false === $inserted ) {
                    $errors[] = sprintf( 'Employee #%d: failed to insert payroll item', $emp_id );
                    continue;
                }

                $total_gross += $calc['gross_salary'];
                $total_deductions += $calc['total_deductions'];
                $total_net += $calc['net_salary'];
                $employee_count++;
            }

            // Update run totals
            $wpdb->update( $runs_table, [
                'status'          => 'review',
                'total_gross'     => round( $total_gross, 2 ),
                'total_deductions'=> round( $total_deductions, 2 ),
                'total_net'       => round( $total_net, 2 ),
                'employee_count'  => $employee_count,
                'calculated_at'   => $now,
                'calculated_by'   => $user_id,
                'notes'           => implode( "\n", array_filter( [
                    $include_overtime ? null : __( 'Overtime excluded from this run.', 'sfs-hr' ),
                    $errors ? implode( "\n", $errors ) : null,
                ] ) ) ?: null,
                'updated_at'      => $now,
            ], [ 'id' => $run_id ] );

            // Update period status
            $wpdb->update( $periods_table, [
                'status'     => 'processing',
                'updated_at' => $now,
            ], [ 'id' => $period_id ] );

            $wpdb->query( 'COMMIT' );

            // Audit Trail: payroll run created
            do_action( 'sfs_hr_payroll_run_created', $run_id, [
                'period_id'      => $period_id,
                'employee_count' => $employee_count,
                'total_net'      => round( $total_net, 2 ),
            ] );

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            delete_transient( $lock_key );
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&error=run_failed' ) );
            exit;
        }

        delete_transient( $lock_key );

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=runs&view=detail&run_id=' . $run_id ) );
        exit;
    }

    public function handle_approve_run(): void {
        if ( ! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
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
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=runs&error=invalid_run' ) );
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

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=runs&view=detail&run_id=' . $run_id . '&approved=1' ) );
        exit;
    }

    public function handle_save_component(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_payroll_save_component' );

        global $wpdb;
        $table   = $wpdb->prefix . 'sfs_hr_salary_components';
        $comp_id = intval( $_POST['comp_id'] ?? 0 );
        $redirect = admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=components' );

        $code             = strtoupper( preg_replace( '/[^A-Z0-9_]/', '', sanitize_text_field( $_POST['code'] ?? '' ) ) );
        $name             = sanitize_text_field( $_POST['name'] ?? '' );
        $name_ar          = sanitize_text_field( $_POST['name_ar'] ?? '' );
        $type             = sanitize_key( $_POST['type'] ?? 'earning' );
        $calculation_type = sanitize_key( $_POST['calculation_type'] ?? 'fixed' );
        $default_amount   = max( 0.0, floatval( $_POST['default_amount'] ?? 0 ) );
        $percentage_of    = $calculation_type === 'percentage' ? sanitize_key( $_POST['percentage_of'] ?? 'base_salary' ) : null;
        if ( $percentage_of !== null && ! in_array( $percentage_of, [ 'base_salary', 'gross_salary' ], true ) ) {
            $percentage_of = 'base_salary';
        }
        $is_taxable       = ! empty( $_POST['is_taxable'] ) ? 1 : 0;
        $is_active        = ! empty( $_POST['is_active'] ) ? 1 : 0;
        $display_order    = max( 0, min( 999, intval( $_POST['display_order'] ?? 10 ) ) );
        $description      = sanitize_textarea_field( $_POST['description'] ?? '' );

        // Formula fields only apply when calculation_type = formula.
        // wp_unslash restores quotes/operators that WordPress auto-escapes on POST.
        $formula_expression = null;
        $formula_condition  = null;
        if ( $calculation_type === 'formula' ) {
            $raw_expr = isset( $_POST['formula_expression'] ) ? (string) wp_unslash( $_POST['formula_expression'] ) : '';
            $raw_cond = isset( $_POST['formula_condition'] ) ? (string) wp_unslash( $_POST['formula_condition'] ) : '';
            // Keep expressions safe: strip HTML, strip control chars, normalize whitespace.
            $formula_expression = trim( wp_strip_all_tags( $raw_expr ) );
            $formula_condition  = trim( wp_strip_all_tags( $raw_cond ) );
            if ( $formula_expression === '' ) {
                $formula_expression = null;
            }
            if ( $formula_condition === '' ) {
                $formula_condition = null;
            }

            // Parse-only validation so the admin gets early feedback.
            if ( $formula_expression !== null ) {
                $check = \SFS\HR\Modules\Payroll\Services\Formula_Evaluator::validate( $formula_expression );
                if ( empty( $check['valid'] ) ) {
                    wp_safe_redirect( add_query_arg( 'comp_error', rawurlencode( sprintf( __( 'Invalid formula expression: %s', 'sfs-hr' ), (string) ( $check['error'] ?? '' ) ) ), $redirect ) );
                    exit;
                }
            }
            if ( $formula_condition !== null ) {
                $check = \SFS\HR\Modules\Payroll\Services\Formula_Evaluator::validate( $formula_condition );
                if ( empty( $check['valid'] ) ) {
                    wp_safe_redirect( add_query_arg( 'comp_error', rawurlencode( sprintf( __( 'Invalid formula condition: %s', 'sfs-hr' ), (string) ( $check['error'] ?? '' ) ) ), $redirect ) );
                    exit;
                }
            }
        }

        if ( ! $code || ! $name ) {
            wp_safe_redirect( add_query_arg( 'comp_error', rawurlencode( __( 'Code and Name are required.', 'sfs-hr' ) ), $redirect ) );
            exit;
        }

        if ( ! in_array( $type, [ 'earning', 'deduction', 'benefit' ], true ) ) {
            $type = 'earning';
        }
        if ( ! in_array( $calculation_type, [ 'fixed', 'percentage', 'formula' ], true ) ) {
            $calculation_type = 'fixed';
        }

        $data = [
            'name'               => $name,
            'name_ar'            => $name_ar,
            'type'               => $type,
            'calculation_type'   => $calculation_type,
            'default_amount'     => $default_amount,
            'percentage_of'      => $percentage_of,
            'is_taxable'         => $is_taxable,
            'is_active'          => $is_active,
            'display_order'      => $display_order,
            'description'        => $description,
            'formula_expression' => $formula_expression,
            'formula_condition'  => $formula_condition,
        ];

        if ( $comp_id ) {
            // Update existing
            $result = $wpdb->update( $table, $data, [ 'id' => $comp_id ] );
        } else {
            // Check code uniqueness
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s", $code ) );
            if ( $existing ) {
                wp_safe_redirect( add_query_arg( 'comp_error', rawurlencode( sprintf( __( 'Code "%s" already exists.', 'sfs-hr' ), $code ) ), $redirect ) );
                exit;
            }
            $data['code'] = $code;
            $result = $wpdb->insert( $table, $data );
        }

        if ( false === $result ) {
            wp_safe_redirect( add_query_arg( 'comp_error', rawurlencode( __( 'Failed to save component.', 'sfs-hr' ) . ' ' . $wpdb->last_error ), $redirect ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( 'saved', '1', $redirect ) );
        exit;
    }

    public function handle_toggle_component(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_payroll_toggle_component' );

        global $wpdb;
        $table   = $wpdb->prefix . 'sfs_hr_salary_components';
        $comp_id = intval( $_POST['comp_id'] ?? 0 );

        if ( $comp_id ) {
            $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM {$table} WHERE id = %d", $comp_id ) );
            $wpdb->update( $table, [ 'is_active' => $current ? 0 : 1 ], [ 'id' => $comp_id ] );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=components&toggled=1' ) );
        exit;
    }

    /* ================================================================== *
     *                   Tax & Statutory admin (M1.2)                      *
     * ================================================================== */

    /**
     * Render the Tax & Statutory tab: GOSI/statutory settings + tax settings
     * + progressive bracket CRUD. Purely an admin view — all calc happens in
     * Tax_Service at payroll time.
     */
    private function render_tax(): void {
        global $wpdb;

        $statutory = get_option( 'sfs_hr_statutory_settings', [] );
        $tax       = get_option( 'sfs_hr_tax_settings', [] );
        if ( ! is_array( $statutory ) ) { $statutory = []; }
        if ( ! is_array( $tax ) ) { $tax = []; }

        $country = isset( $_GET['bracket_country'] ) ? strtoupper( sanitize_text_field( (string) $_GET['bracket_country'] ) ) : strtoupper( (string) ( $tax['tax_country'] ?? 'SA' ) );
        $year    = isset( $_GET['bracket_year'] ) ? (int) $_GET['bracket_year'] : (int) ( $tax['tax_year'] ?? wp_date( 'Y' ) );

        $brackets_table = $wpdb->prefix . 'sfs_hr_tax_brackets';
        $brackets = [];
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $brackets_table ) ) ) {
            $brackets = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$brackets_table} WHERE country_code = %s AND tax_year = %d ORDER BY bracket_from ASC",
                $country,
                $year
            ) );
        }

        $notice = isset( $_GET['saved'] ) ? __( 'Settings saved.', 'sfs-hr' ) : '';
        if ( isset( $_GET['bracket_saved'] ) )  { $notice = __( 'Bracket saved.', 'sfs-hr' ); }
        if ( isset( $_GET['bracket_deleted'] ) ) { $notice = __( 'Bracket deleted.', 'sfs-hr' ); }
        $error = isset( $_GET['error'] ) ? sanitize_text_field( (string) $_GET['error'] ) : '';

        ?>
        <div class="sfs-hr-tax-admin" style="max-width:1100px;">
            <?php if ( $notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>
            <?php if ( $error ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Statutory Deductions (GOSI)', 'sfs-hr' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Configure Saudi GOSI rates and coverage. Changes apply to payroll runs calculated after saving.', 'sfs-hr' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sfs_hr_payroll_save_tax_settings' ); ?>
                <input type="hidden" name="action" value="sfs_hr_payroll_save_tax_settings" />

                <table class="form-table">
                    <tr>
                        <th><label for="gosi_enabled"><?php esc_html_e( 'GOSI Enabled', 'sfs-hr' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="gosi_enabled" id="gosi_enabled" value="1" <?php checked( ! empty( $statutory['gosi_enabled'] ) ); ?> />
                                <?php esc_html_e( 'Deduct GOSI during payroll calculation', 'sfs-hr' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'When disabled, no GOSI deduction is applied regardless of component activation.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gosi_applies_to"><?php esc_html_e( 'Applies To', 'sfs-hr' ); ?></label></th>
                        <td>
                            <select name="gosi_applies_to" id="gosi_applies_to">
                                <option value="saudi_only" <?php selected( (string) ( $statutory['gosi_applies_to'] ?? 'saudi_only' ), 'saudi_only' ); ?>><?php esc_html_e( 'Saudi nationals only', 'sfs-hr' ); ?></option>
                                <option value="all" <?php selected( (string) ( $statutory['gosi_applies_to'] ?? '' ), 'all' ); ?>><?php esc_html_e( 'All employees (reduced rate for foreigners)', 'sfs-hr' ); ?></option>
                                <option value="none" <?php selected( (string) ( $statutory['gosi_applies_to'] ?? '' ), 'none' ); ?>><?php esc_html_e( 'Do not apply', 'sfs-hr' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gosi_employee_rate"><?php esc_html_e( 'Employee Rate (Saudi)', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" name="gosi_employee_rate" id="gosi_employee_rate" step="0.001" min="0" max="100" value="<?php echo esc_attr( number_format( (float) ( $statutory['gosi_employee_rate'] ?? 9.75 ), 3, '.', '' ) ); ?>" /> %
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gosi_company_rate"><?php esc_html_e( 'Company Rate (Saudi)', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" name="gosi_company_rate" id="gosi_company_rate" step="0.001" min="0" max="100" value="<?php echo esc_attr( number_format( (float) ( $statutory['gosi_company_rate'] ?? 11.75 ), 3, '.', '' ) ); ?>" /> %
                            <p class="description"><?php esc_html_e( 'Informational only — recorded as a benefit component, not deducted from net.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gosi_foreign_rate"><?php esc_html_e( 'Foreign Employee Rate', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" name="gosi_foreign_rate" id="gosi_foreign_rate" step="0.001" min="0" max="100" value="<?php echo esc_attr( number_format( (float) ( $statutory['gosi_foreign_rate'] ?? 2.00 ), 3, '.', '' ) ); ?>" /> %
                            <p class="description"><?php esc_html_e( 'Only used when "Applies To" = All employees.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gosi_ceiling"><?php esc_html_e( 'Monthly Base Ceiling', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" name="gosi_ceiling" id="gosi_ceiling" step="0.01" min="0" value="<?php echo esc_attr( number_format( (float) ( $statutory['gosi_ceiling'] ?? 45000 ), 2, '.', '' ) ); ?>" />
                            <p class="description"><?php esc_html_e( 'Maximum monthly base subject to GOSI. Set 0 to disable the ceiling.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gosi_base_includes"><?php esc_html_e( 'Base Includes', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="text" name="gosi_base_includes" id="gosi_base_includes" class="regular-text" value="<?php echo esc_attr( (string) ( $statutory['gosi_base_includes'] ?? 'base_salary,housing' ) ); ?>" />
                            <p class="description"><?php esc_html_e( 'Comma-separated keywords: base_salary, housing. The sum of these forms the GOSI base before applying the ceiling.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e( 'Income Tax', 'sfs-hr' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Saudi Arabia has no personal income tax. These settings are provided for Gulf organisations that need to implement tax brackets for specific employee groups.', 'sfs-hr' ); ?>
                </p>

                <table class="form-table">
                    <tr>
                        <th><label for="tax_enabled"><?php esc_html_e( 'Income Tax Enabled', 'sfs-hr' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="tax_enabled" id="tax_enabled" value="1" <?php checked( ! empty( $tax['tax_enabled'] ) ); ?> />
                                <?php esc_html_e( 'Apply income tax using the bracket schedule below', 'sfs-hr' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tax_country"><?php esc_html_e( 'Country Code', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="text" name="tax_country" id="tax_country" maxlength="3" class="small-text" value="<?php echo esc_attr( strtoupper( (string) ( $tax['tax_country'] ?? 'SA' ) ) ); ?>" style="text-transform:uppercase;" />
                            <p class="description"><?php esc_html_e( 'Two-letter ISO country code — selects which bracket set is active.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tax_year"><?php esc_html_e( 'Tax Year', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" name="tax_year" id="tax_year" min="2000" max="2100" value="<?php echo esc_attr( (int) ( $tax['tax_year'] ?? wp_date( 'Y' ) ) ); ?>" />
                            <p class="description"><?php esc_html_e( 'Leave 0 to auto-select based on the payroll period end date.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tax_applies_to"><?php esc_html_e( 'Applies To', 'sfs-hr' ); ?></label></th>
                        <td>
                            <select name="tax_applies_to" id="tax_applies_to">
                                <option value="all" <?php selected( (string) ( $tax['tax_applies_to'] ?? 'all' ), 'all' ); ?>><?php esc_html_e( 'All employees', 'sfs-hr' ); ?></option>
                                <option value="foreign_only" <?php selected( (string) ( $tax['tax_applies_to'] ?? '' ), 'foreign_only' ); ?>><?php esc_html_e( 'Foreign employees only', 'sfs-hr' ); ?></option>
                                <option value="saudi_only" <?php selected( (string) ( $tax['tax_applies_to'] ?? '' ), 'saudi_only' ); ?>><?php esc_html_e( 'Saudi nationals only', 'sfs-hr' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="period_annualize"><?php esc_html_e( 'Periods Per Year', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" name="period_annualize" id="period_annualize" min="1" max="52" value="<?php echo esc_attr( (int) ( $tax['period_annualize'] ?? 12 ) ); ?>" />
                            <p class="description"><?php esc_html_e( '12 for monthly payroll, 24 for semi-monthly, 26 for bi-weekly, 52 for weekly.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Statutory Settings', 'sfs-hr' ); ?></button>
                </p>
            </form>

            <hr style="margin:30px 0;" />

            <h2><?php esc_html_e( 'Tax Brackets', 'sfs-hr' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Progressive tax slabs. Calculation: for each bracket where income > from, add (min(income, to) - from) × rate%. A per-bracket flat surcharge can be added to the bracket that contains the final income.', 'sfs-hr' ); ?>
            </p>

            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:10px 0 20px;">
                <input type="hidden" name="page" value="sfs-hr-payroll" />
                <input type="hidden" name="payroll_tab" value="tax" />
                <label><?php esc_html_e( 'Country:', 'sfs-hr' ); ?>
                    <input type="text" name="bracket_country" maxlength="3" class="small-text" value="<?php echo esc_attr( $country ); ?>" style="text-transform:uppercase;" />
                </label>
                <label style="margin-left:10px;"><?php esc_html_e( 'Year:', 'sfs-hr' ); ?>
                    <input type="number" name="bracket_year" min="2000" max="2100" value="<?php echo esc_attr( (string) $year ); ?>" />
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Load Brackets', 'sfs-hr' ); ?></button>
            </form>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'From', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'To', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Rate %', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Flat Surcharge', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Active', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $brackets ) ) : ?>
                    <tr><td colspan="7" style="text-align:center;color:#888;padding:20px;">
                        <?php esc_html_e( 'No brackets defined for this country/year. Use the form below to add one.', 'sfs-hr' ); ?>
                    </td></tr>
                <?php else : ?>
                    <?php foreach ( $brackets as $b ) : ?>
                        <tr>
                            <td><?php echo esc_html( number_format( (float) $b->bracket_from, 2, '.', ',' ) ); ?></td>
                            <td><?php echo $b->bracket_to === null ? '<em>' . esc_html__( 'and above', 'sfs-hr' ) . '</em>' : esc_html( number_format( (float) $b->bracket_to, 2, '.', ',' ) ); ?></td>
                            <td><?php echo esc_html( number_format( (float) $b->rate_percent, 3, '.', '' ) ); ?> %</td>
                            <td><?php echo esc_html( number_format( (float) $b->flat_base, 2, '.', ',' ) ); ?></td>
                            <td><?php echo esc_html( (string) $b->description ); ?></td>
                            <td><?php echo ( (int) $b->is_active === 1 ) ? '✓' : '—'; ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this bracket?', 'sfs-hr' ) ); ?>');">
                                    <?php wp_nonce_field( 'sfs_hr_payroll_delete_tax_bracket' ); ?>
                                    <input type="hidden" name="action" value="sfs_hr_payroll_delete_tax_bracket" />
                                    <input type="hidden" name="bracket_id" value="<?php echo intval( $b->id ); ?>" />
                                    <input type="hidden" name="bracket_country" value="<?php echo esc_attr( $country ); ?>" />
                                    <input type="hidden" name="bracket_year" value="<?php echo esc_attr( (string) $year ); ?>" />
                                    <button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'sfs-hr' ); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <h3 style="margin-top:25px;"><?php esc_html_e( 'Add Bracket', 'sfs-hr' ); ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sfs_hr_payroll_save_tax_bracket' ); ?>
                <input type="hidden" name="action" value="sfs_hr_payroll_save_tax_bracket" />
                <input type="hidden" name="country_code" value="<?php echo esc_attr( $country ); ?>" />
                <input type="hidden" name="tax_year" value="<?php echo esc_attr( (string) $year ); ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="bracket_from_new"><?php esc_html_e( 'From (annual, inclusive)', 'sfs-hr' ); ?></label></th>
                        <td><input type="number" name="bracket_from" id="bracket_from_new" step="0.01" min="0" required /></td>
                    </tr>
                    <tr>
                        <th><label for="bracket_to_new"><?php esc_html_e( 'To (annual, exclusive)', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" name="bracket_to" id="bracket_to_new" step="0.01" min="0" />
                            <p class="description"><?php esc_html_e( 'Leave blank for the top bracket (applies to everything above From).', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="rate_percent_new"><?php esc_html_e( 'Rate %', 'sfs-hr' ); ?></label></th>
                        <td><input type="number" name="rate_percent" id="rate_percent_new" step="0.001" min="0" max="100" required /></td>
                    </tr>
                    <tr>
                        <th><label for="flat_base_new"><?php esc_html_e( 'Flat Surcharge', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" name="flat_base" id="flat_base_new" step="0.01" min="0" value="0" />
                            <p class="description"><?php esc_html_e( 'Fixed amount added when the final taxable income lands in this bracket. Leave 0 for pure progressive.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="description_new"><?php esc_html_e( 'Description', 'sfs-hr' ); ?></label></th>
                        <td><input type="text" name="description" id="description_new" class="regular-text" /></td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Bracket', 'sfs-hr' ); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Persist statutory + income-tax settings from the Tax admin tab.
     */
    public function handle_save_tax_settings(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_payroll_save_tax_settings' );

        $statutory = [
            'gosi_enabled'       => ! empty( $_POST['gosi_enabled'] ) ? 1 : 0,
            'gosi_applies_to'    => in_array( (string) ( $_POST['gosi_applies_to'] ?? '' ), [ 'saudi_only', 'all', 'none' ], true ) ? (string) $_POST['gosi_applies_to'] : 'saudi_only',
            'gosi_employee_rate' => max( 0.0, min( 100.0, (float) ( $_POST['gosi_employee_rate'] ?? 9.75 ) ) ),
            'gosi_company_rate'  => max( 0.0, min( 100.0, (float) ( $_POST['gosi_company_rate'] ?? 11.75 ) ) ),
            'gosi_foreign_rate'  => max( 0.0, min( 100.0, (float) ( $_POST['gosi_foreign_rate'] ?? 2.00 ) ) ),
            'gosi_ceiling'       => max( 0.0, (float) ( $_POST['gosi_ceiling'] ?? 45000 ) ),
            'gosi_base_includes' => sanitize_text_field( (string) ( $_POST['gosi_base_includes'] ?? 'base_salary,housing' ) ),
        ];

        $tax = [
            'tax_enabled'      => ! empty( $_POST['tax_enabled'] ) ? 1 : 0,
            'tax_country'      => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $_POST['tax_country'] ?? 'SA' ) ), 0, 3 ) ),
            'tax_year'         => max( 0, min( 2100, (int) ( $_POST['tax_year'] ?? 0 ) ) ),
            'tax_applies_to'   => in_array( (string) ( $_POST['tax_applies_to'] ?? '' ), [ 'all', 'foreign_only', 'saudi_only' ], true ) ? (string) $_POST['tax_applies_to'] : 'all',
            'period_annualize' => max( 1, min( 52, (int) ( $_POST['period_annualize'] ?? 12 ) ) ),
        ];
        if ( $tax['tax_country'] === '' ) {
            $tax['tax_country'] = 'SA';
        }

        update_option( 'sfs_hr_statutory_settings', $statutory );
        update_option( 'sfs_hr_tax_settings', $tax );

        // Brackets cache is keyed by (country, year) — flush after any write
        // to these settings so the next payroll run reads fresh data.
        \SFS\HR\Modules\Payroll\Services\Tax_Service::flush_cache();

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=tax&saved=1' ) );
        exit;
    }

    /**
     * Add a tax bracket row for a given (country, year).
     */
    public function handle_save_tax_bracket(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_payroll_save_tax_bracket' );

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_tax_brackets';

        $country = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $_POST['country_code'] ?? 'SA' ) ), 0, 3 ) );
        $year    = max( 2000, min( 2100, (int) ( $_POST['tax_year'] ?? wp_date( 'Y' ) ) ) );
        $from    = max( 0.0, (float) ( $_POST['bracket_from'] ?? 0 ) );
        $to_raw  = $_POST['bracket_to'] ?? '';
        $to      = ( is_string( $to_raw ) && trim( $to_raw ) === '' ) ? null : max( 0.0, (float) $to_raw );
        $rate    = max( 0.0, min( 100.0, (float) ( $_POST['rate_percent'] ?? 0 ) ) );
        $flat    = max( 0.0, (float) ( $_POST['flat_base'] ?? 0 ) );
        $desc    = sanitize_text_field( (string) ( $_POST['description'] ?? '' ) );

        $redirect = admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=tax&bracket_country=' . rawurlencode( $country ) . '&bracket_year=' . $year );

        if ( $to !== null && $to <= $from ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( __( '"To" must be greater than "From".', 'sfs-hr' ) ), $redirect ) );
            exit;
        }

        $now = current_time( 'mysql' );
        $wpdb->insert( $table, [
            'country_code' => $country,
            'tax_year'     => $year,
            'bracket_from' => $from,
            'bracket_to'   => $to,
            'rate_percent' => $rate,
            'flat_base'    => $flat,
            'description'  => $desc,
            'is_active'    => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ] );

        \SFS\HR\Modules\Payroll\Services\Tax_Service::flush_cache();

        wp_safe_redirect( add_query_arg( 'bracket_saved', '1', $redirect ) );
        exit;
    }

    /**
     * Delete a tax bracket row.
     */
    public function handle_delete_tax_bracket(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_payroll_delete_tax_bracket' );

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_tax_brackets';
        $id    = (int) ( $_POST['bracket_id'] ?? 0 );

        $country = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $_POST['bracket_country'] ?? 'SA' ) ), 0, 3 ) );
        $year    = max( 2000, min( 2100, (int) ( $_POST['bracket_year'] ?? wp_date( 'Y' ) ) ) );

        if ( $id > 0 ) {
            $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
            \SFS\HR\Modules\Payroll\Services\Tax_Service::flush_cache();
        }

        $redirect = admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=tax&bracket_country=' . rawurlencode( $country ) . '&bracket_year=' . $year . '&bracket_deleted=1' );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Render Export tab with multiple export options
     */
    private function render_export(): void {
        global $wpdb;

        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';

        // Get available periods
        $periods = $wpdb->get_results(
            "SELECT * FROM {$periods_table} ORDER BY start_date DESC LIMIT 24"
        );

        // Get approved/paid runs
        $runs = $wpdb->get_results(
            "SELECT r.*, p.name as period_name FROM {$runs_table} r
             LEFT JOIN {$periods_table} p ON p.id = r.period_id
             WHERE r.status IN ('approved', 'paid')
             ORDER BY r.approved_at DESC LIMIT 24"
        );

        ?>
        <div class="sfs-hr-export-hub">
            <h2><?php esc_html_e( 'Export Center', 'sfs-hr' ); ?></h2>
            <p style="color: #666; margin-bottom: 25px;">
                <?php esc_html_e( 'Export payroll and attendance data in various formats for external systems.', 'sfs-hr' ); ?>
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px;">

                <!-- Attendance Summary Export -->
                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px;">
                    <h3 style="margin: 0 0 10px; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-clock" style="color: #2271b1;"></span>
                        <?php esc_html_e( 'Attendance Summary', 'sfs-hr' ); ?>
                    </h3>
                    <p style="color: #666; font-size: 13px; margin-bottom: 15px;">
                        <?php esc_html_e( 'Export attendance data including work hours, overtime, late arrivals, and absences for a date range.', 'sfs-hr' ); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sfs_hr_payroll_export_attendance" />
                        <?php wp_nonce_field( 'sfs_hr_payroll_export_attendance' ); ?>

                        <table class="form-table" style="margin: 0;">
                            <tr>
                                <th scope="row" style="padding: 8px 0; width: 100px;"><?php esc_html_e( 'Period', 'sfs-hr' ); ?></th>
                                <td style="padding: 8px 0;">
                                    <select name="period_id" style="width: 100%;">
                                        <option value="custom"><?php esc_html_e( '— Custom Date Range —', 'sfs-hr' ); ?></option>
                                        <?php foreach ( $periods as $p ) : ?>
                                            <option value="<?php echo esc_attr( $p->id ); ?>">
                                                <?php echo esc_html( $p->name ); ?>
                                                (<?php echo esc_html( date_i18n( 'M j', strtotime( $p->start_date ) ) ); ?> -
                                                <?php echo esc_html( date_i18n( 'M j, Y', strtotime( $p->end_date ) ) ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr class="custom-dates" style="display: none;">
                                <th scope="row" style="padding: 8px 0;"><?php esc_html_e( 'From', 'sfs-hr' ); ?></th>
                                <td style="padding: 8px 0;">
                                    <input type="date" name="start_date" value="<?php echo esc_attr( date( 'Y-m-01' ) ); ?>" style="width: 100%;" />
                                </td>
                            </tr>
                            <tr class="custom-dates" style="display: none;">
                                <th scope="row" style="padding: 8px 0;"><?php esc_html_e( 'To', 'sfs-hr' ); ?></th>
                                <td style="padding: 8px 0;">
                                    <input type="date" name="end_date" value="<?php echo esc_attr( date( 'Y-m-t' ) ); ?>" style="width: 100%;" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" style="padding: 8px 0;"><?php esc_html_e( 'Format', 'sfs-hr' ); ?></th>
                                <td style="padding: 8px 0;">
                                    <select name="format" style="width: 100%;">
                                        <option value="csv"><?php esc_html_e( 'CSV (Excel Compatible)', 'sfs-hr' ); ?></option>
                                        <option value="xlsx"><?php esc_html_e( 'Excel (XLSX)', 'sfs-hr' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <button type="submit" class="button button-primary" style="margin-top: 15px;">
                            <span class="dashicons dashicons-download" style="margin-top: 4px;"></span>
                            <?php esc_html_e( 'Export Attendance', 'sfs-hr' ); ?>
                        </button>
                    </form>
                </div>

                <!-- Bank Transfer / WPS Export -->
                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px;">
                    <h3 style="margin: 0 0 10px; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-bank" style="color: #00a32a;"></span>
                        <?php esc_html_e( 'Bank Transfer (WPS)', 'sfs-hr' ); ?>
                    </h3>
                    <p style="color: #666; font-size: 13px; margin-bottom: 15px;">
                        <?php esc_html_e( 'Export salary data for bank transfers in Wage Protection System (WPS) format used in Saudi Arabia and UAE.', 'sfs-hr' ); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sfs_hr_payroll_export_wps" />
                        <?php wp_nonce_field( 'sfs_hr_payroll_export_wps' ); ?>

                        <table class="form-table" style="margin: 0;">
                            <tr>
                                <th scope="row" style="padding: 8px 0; width: 100px;"><?php esc_html_e( 'Payroll Run', 'sfs-hr' ); ?></th>
                                <td style="padding: 8px 0;">
                                    <select name="run_id" style="width: 100%;" required>
                                        <option value=""><?php esc_html_e( '— Select Run —', 'sfs-hr' ); ?></option>
                                        <?php foreach ( $runs as $r ) : ?>
                                            <option value="<?php echo esc_attr( $r->id ); ?>">
                                                <?php echo esc_html( $r->period_name ); ?>
                                                (<?php echo esc_html( number_format( (float) $r->total_net, 2 ) ); ?> SAR,
                                                <?php echo esc_html( $r->employee_count ); ?> <?php esc_html_e( 'employees', 'sfs-hr' ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" style="padding: 8px 0;"><?php esc_html_e( 'Format', 'sfs-hr' ); ?></th>
                                <td style="padding: 8px 0;">
                                    <select name="format" style="width: 100%;">
                                        <option value="wps_sif"><?php esc_html_e( 'WPS SIF (Standard)', 'sfs-hr' ); ?></option>
                                        <option value="csv"><?php esc_html_e( 'CSV (Bank Upload)', 'sfs-hr' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <button type="submit" class="button button-primary" style="margin-top: 15px;">
                            <span class="dashicons dashicons-download" style="margin-top: 4px;"></span>
                            <?php esc_html_e( 'Export WPS File', 'sfs-hr' ); ?>
                        </button>
                    </form>
                </div>

                <!-- Detailed Payroll Export -->
                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px;">
                    <h3 style="margin: 0 0 10px; display: flex; align-items: center; gap: 8px;">
                        <span class="dashicons dashicons-media-spreadsheet" style="color: #dba617;"></span>
                        <?php esc_html_e( 'Detailed Payroll Report', 'sfs-hr' ); ?>
                    </h3>
                    <p style="color: #666; font-size: 13px; margin-bottom: 15px;">
                        <?php esc_html_e( 'Export comprehensive payroll data including all earnings, deductions, and net pay breakdown.', 'sfs-hr' ); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sfs_hr_payroll_export_detailed" />
                        <?php wp_nonce_field( 'sfs_hr_payroll_export_detailed' ); ?>

                        <table class="form-table" style="margin: 0;">
                            <tr>
                                <th scope="row" style="padding: 8px 0; width: 100px;"><?php esc_html_e( 'Payroll Run', 'sfs-hr' ); ?></th>
                                <td style="padding: 8px 0;">
                                    <select name="run_id" style="width: 100%;" required>
                                        <option value=""><?php esc_html_e( '— Select Run —', 'sfs-hr' ); ?></option>
                                        <?php foreach ( $runs as $r ) : ?>
                                            <option value="<?php echo esc_attr( $r->id ); ?>">
                                                <?php echo esc_html( $r->period_name ); ?>
                                                (<?php echo esc_html( number_format( (float) $r->total_net, 2 ) ); ?> SAR)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" style="padding: 8px 0;"><?php esc_html_e( 'Format', 'sfs-hr' ); ?></th>
                                <td style="padding: 8px 0;">
                                    <select name="format" style="width: 100%;">
                                        <option value="csv"><?php esc_html_e( 'CSV (Excel Compatible)', 'sfs-hr' ); ?></option>
                                        <option value="xlsx"><?php esc_html_e( 'Excel (XLSX)', 'sfs-hr' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" style="padding: 8px 0;"><?php esc_html_e( 'Include', 'sfs-hr' ); ?></th>
                                <td style="padding: 8px 0;">
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox" name="include_attendance" value="1" checked />
                                        <?php esc_html_e( 'Attendance data', 'sfs-hr' ); ?>
                                    </label>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox" name="include_deductions" value="1" checked />
                                        <?php esc_html_e( 'Deductions breakdown', 'sfs-hr' ); ?>
                                    </label>
                                    <label style="display: block;">
                                        <input type="checkbox" name="include_loans" value="1" checked />
                                        <?php esc_html_e( 'Loan deductions', 'sfs-hr' ); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        <button type="submit" class="button button-primary" style="margin-top: 15px;">
                            <span class="dashicons dashicons-download" style="margin-top: 4px;"></span>
                            <?php esc_html_e( 'Export Detailed Report', 'sfs-hr' ); ?>
                        </button>
                    </form>
                </div>

            </div>

            <?php if ( empty( $runs ) ) : ?>
            <div style="margin-top: 20px; padding: 15px; background: #fff8e5; border-left: 4px solid #dba617; color: #654b00;">
                <strong><?php esc_html_e( 'Note:', 'sfs-hr' ); ?></strong>
                <?php esc_html_e( 'No approved payroll runs found. Run and approve a payroll first to enable bank transfer and detailed exports.', 'sfs-hr' ); ?>
            </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($) {
            $('select[name="period_id"]').on('change', function() {
                if ($(this).val() === 'custom') {
                    $(this).closest('form').find('.custom-dates').show();
                } else {
                    $(this).closest('form').find('.custom-dates').hide();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Handle Attendance Summary Export
     */
    public function handle_export_attendance(): void {
        if ( ! current_user_can( 'sfs_hr.view' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_payroll_export_attendance' );

        global $wpdb;

        $period_id = sanitize_text_field( $_POST['period_id'] ?? '' );
        $format = sanitize_key( $_POST['format'] ?? 'csv' );

        $sessions_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        // Determine date range
        if ( $period_id === 'custom' ) {
            $start_date = sanitize_text_field( $_POST['start_date'] ?? date( 'Y-m-01' ) );
            $end_date = sanitize_text_field( $_POST['end_date'] ?? date( 'Y-m-t' ) );
            $period_name = 'Custom';
        } else {
            $period = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$periods_table} WHERE id = %d",
                (int) $period_id
            ) );
            if ( ! $period ) {
                wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=export&error=invalid_period' ) );
                exit;
            }
            $start_date = $period->start_date;
            $end_date = $period->end_date;
            $period_name = $period->name;
        }

        // Get attendance summary per employee
        $data = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                e.id AS employee_id,
                e.employee_code,
                CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                d.name AS department,
                e.position AS job_title,
                COUNT(s.id) AS total_days,
                SUM(CASE WHEN s.status = 'present' THEN 1 ELSE 0 END) AS present_days,
                SUM(CASE WHEN s.status = 'late' THEN 1 ELSE 0 END) AS late_days,
                SUM(CASE WHEN s.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
                SUM(CASE WHEN s.status = 'on_leave' THEN 1 ELSE 0 END) AS leave_days,
                SUM(CASE WHEN s.status = 'left_early' THEN 1 ELSE 0 END) AS early_leave_days,
                SUM(s.net_minutes) AS total_work_minutes,
                SUM(s.overtime_minutes) AS total_overtime_minutes,
                SUM(s.break_minutes) AS total_break_minutes
            FROM {$emp_table} e
            LEFT JOIN {$sessions_table} s ON s.employee_id = e.id
                AND s.work_date BETWEEN %s AND %s
            LEFT JOIN {$dept_table} d ON e.dept_id = d.id
            WHERE e.status = 'active'
            GROUP BY e.id
            ORDER BY e.first_name, e.last_name",
            $start_date,
            $end_date
        ) );

        $filename = sanitize_file_name( 'attendance-summary-' . sanitize_title( $period_name ) . '-' . wp_date( 'Y-m-d' ) );

        if ( $format === 'xlsx' ) {
            $this->export_xlsx( $filename, $this->format_attendance_data( $data ) );
        } else {
            $this->export_csv( $filename, $this->format_attendance_data( $data ) );
        }
    }

    /**
     * Format attendance data for export
     */
    private function format_attendance_data( array $data ): array {
        $headers = [
            __( 'Employee ID', 'sfs-hr' ),
            __( 'Employee Name', 'sfs-hr' ),
            __( 'Department', 'sfs-hr' ),
            __( 'Job Title', 'sfs-hr' ),
            __( 'Total Days', 'sfs-hr' ),
            __( 'Present', 'sfs-hr' ),
            __( 'Late', 'sfs-hr' ),
            __( 'Absent', 'sfs-hr' ),
            __( 'On Leave', 'sfs-hr' ),
            __( 'Early Leave', 'sfs-hr' ),
            __( 'Work Hours', 'sfs-hr' ),
            __( 'Overtime Hours', 'sfs-hr' ),
            __( 'Break Hours', 'sfs-hr' ),
        ];

        $rows = [ $headers ];

        foreach ( $data as $row ) {
            $rows[] = [
                $row->employee_code ?: $row->employee_id,
                $row->employee_name,
                $row->department ?: '-',
                $row->job_title ?: '-',
                (int) $row->total_days,
                (int) $row->present_days,
                (int) $row->late_days,
                (int) $row->absent_days,
                (int) $row->leave_days,
                (int) $row->early_leave_days,
                number_format( ( (int) $row->total_work_minutes ) / 60, 2 ),
                number_format( ( (int) $row->total_overtime_minutes ) / 60, 2 ),
                number_format( ( (int) $row->total_break_minutes ) / 60, 2 ),
            ];
        }

        return $rows;
    }

    /**
     * Handle WPS (Wage Protection System) Export
     */
    public function handle_export_wps(): void {
        if ( ! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_payroll_export_wps' );

        global $wpdb;

        $run_id = intval( $_POST['run_id'] ?? 0 );
        $format = sanitize_key( $_POST['format'] ?? 'wps_sif' );

        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $run = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, p.name as period_name, p.start_date, p.end_date
             FROM {$runs_table} r
             LEFT JOIN {$periods_table} p ON p.id = r.period_id
             WHERE r.id = %d",
            $run_id
        ) );

        if ( ! $run || ! in_array( $run->status, [ 'approved', 'paid' ], true ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=export&error=invalid_run' ) );
            exit;
        }

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, i.components_json,
                    e.first_name, e.last_name, e.employee_code,
                    e.national_id, e.passport_no,
                    e.base_salary
             FROM {$items_table} i
             LEFT JOIN {$emp_table} e ON e.id = i.employee_id
             WHERE i.run_id = %d
             ORDER BY e.first_name",
            $run_id
        ) );

        $filename = sanitize_file_name( 'wps-' . sanitize_title( $run->period_name ) . '-' . wp_date( 'Y-m-d' ) );

        if ( $format === 'wps_sif' ) {
            $this->export_wps_sif( $filename, $run, $items );
        } else {
            $this->export_csv( $filename . '.csv', $this->format_wps_csv_data( $run, $items ) );
        }
    }

    /**
     * Export WPS SIF (Salary Information File) format
     */
    private function export_wps_sif( string $filename, object $run, array $items ): void {
        $employer_code = get_option( 'sfs_hr_employer_code', '0000000000' ); // MOL registration
        $bank_code = get_option( 'sfs_hr_bank_code', '00' );

        // Calculate actual days in the period
        $period_days = ( strtotime( $run->end_date ) - strtotime( $run->start_date ) ) / 86400 + 1;

        // SIF file header
        $year_month = date( 'Ym', strtotime( $run->start_date ) );
        $record_count = count( $items );
        $total_salaries = 0;
        foreach ( $items as $item ) {
            $total_salaries += (float) $item->net_salary;
        }

        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename . '.sif' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Header Record (EDH)
        echo 'EDH';
        echo str_pad( $employer_code, 13 );
        echo str_pad( $bank_code, 4 );
        echo $year_month;
        echo str_pad( (string) $record_count, 6, '0', STR_PAD_LEFT );
        echo str_pad( number_format( $total_salaries, 2, '', '' ), 15, '0', STR_PAD_LEFT );
        echo 'SAR';
        echo "\n";

        // Employee Records (EDR)
        foreach ( $items as $item ) {
            $emp_id = $item->national_id ?: $item->employee_code ?: '';

            // Extract housing and other allowances from components_json
            $housing_allowance = 0;
            $other_allowances = 0;
            $components = ! empty( $item->components_json ) ? json_decode( $item->components_json, true ) : [];
            if ( is_array( $components ) ) {
                foreach ( $components as $comp ) {
                    if ( ( $comp['code'] ?? '' ) === 'HOUSING' ) {
                        $housing_allowance += (float) ( $comp['amount'] ?? 0 );
                    } elseif ( ( $comp['type'] ?? '' ) === 'earning' && ! in_array( $comp['code'] ?? '', [ 'BASE', 'HOUSING', 'OVERTIME' ], true ) ) {
                        $other_allowances += (float) ( $comp['amount'] ?? 0 );
                    }
                }
            }

            echo 'EDR';
            echo str_pad( $emp_id ?? '', 15 );
            echo str_pad( $item->iban ?? '', 24 );
            echo str_pad( date( 'Ymd', strtotime( $run->start_date ) ), 8 );
            echo str_pad( date( 'Ymd', strtotime( $run->end_date ) ), 8 );
            echo str_pad( (string) (int) $period_days, 4 );
            echo str_pad( number_format( (float) $item->net_salary, 2, '', '' ), 15, '0', STR_PAD_LEFT );
            echo str_pad( number_format( (float) $item->base_salary, 2, '', '' ), 15, '0', STR_PAD_LEFT );
            echo str_pad( number_format( $housing_allowance, 2, '', '' ), 15, '0', STR_PAD_LEFT );
            echo str_pad( number_format( $other_allowances, 2, '', '' ), 15, '0', STR_PAD_LEFT );
            echo str_pad( number_format( (float) ( $item->total_deductions ?? 0 ), 2, '', '' ), 15, '0', STR_PAD_LEFT );
            echo "\n";
        }

        exit;
    }

    /**
     * Format WPS data for CSV export
     */
    private function format_wps_csv_data( object $run, array $items ): array {
        $period_days = ( strtotime( $run->end_date ) - strtotime( $run->start_date ) ) / 86400 + 1;

        $headers = [
            'Employee ID',
            'Employee Name',
            'National ID / Iqama',
            'IBAN',
            'Bank Name',
            'Days in Period',
            'Basic Salary',
            'Housing Allowance',
            'Other Allowances',
            'Total Deductions',
            'Net Salary',
            'Currency',
        ];

        $rows = [ $headers ];

        foreach ( $items as $item ) {
            $emp_name = trim( ( $item->first_name ?? '' ) . ' ' . ( $item->last_name ?? '' ) );
            $emp_id = $item->national_id ?: $item->employee_code ?: '';

            // Extract housing and other allowances from components_json
            $housing_allowance = 0;
            $other_allowances = 0;
            $components = ! empty( $item->components_json ) ? json_decode( $item->components_json, true ) : [];
            if ( is_array( $components ) ) {
                foreach ( $components as $comp ) {
                    if ( ( $comp['code'] ?? '' ) === 'HOUSING' ) {
                        $housing_allowance += (float) ( $comp['amount'] ?? 0 );
                    } elseif ( ( $comp['type'] ?? '' ) === 'earning' && ! in_array( $comp['code'] ?? '', [ 'BASE', 'HOUSING', 'OVERTIME' ], true ) ) {
                        $other_allowances += (float) ( $comp['amount'] ?? 0 );
                    }
                }
            }

            $rows[] = [
                $item->employee_code ?: $item->employee_id,
                $emp_name,
                $emp_id,
                $item->iban ?? '',
                $item->bank_name ?? '',
                (int) $period_days,
                number_format( (float) $item->base_salary, 2, '.', '' ),
                number_format( $housing_allowance, 2, '.', '' ),
                number_format( $other_allowances, 2, '.', '' ),
                number_format( (float) ( $item->total_deductions ?? 0 ), 2, '.', '' ),
                number_format( (float) $item->net_salary, 2, '.', '' ),
                'SAR',
            ];
        }

        return $rows;
    }

    /**
     * Handle Detailed Payroll Export
     */
    public function handle_export_detailed(): void {
        if ( ! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_payroll_export_detailed' );

        global $wpdb;

        $run_id = intval( $_POST['run_id'] ?? 0 );
        $format = sanitize_key( $_POST['format'] ?? 'csv' );
        $include_attendance = isset( $_POST['include_attendance'] );
        $include_deductions = isset( $_POST['include_deductions'] );
        $include_loans = isset( $_POST['include_loans'] );

        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $runs_table = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods_table = $wpdb->prefix . 'sfs_hr_payroll_periods';
        $sessions_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $run = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, p.name as period_name, p.start_date, p.end_date
             FROM {$runs_table} r
             LEFT JOIN {$periods_table} p ON p.id = r.period_id
             WHERE r.id = %d",
            $run_id
        ) );

        if ( ! $run || ! in_array( $run->status, [ 'approved', 'paid' ], true ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=export&error=invalid_run' ) );
            exit;
        }

        // Get payroll items with employee details
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, e.first_name, e.last_name, e.employee_code,
                    e.position, d.name as department
             FROM {$items_table} i
             LEFT JOIN {$emp_table} e ON e.id = i.employee_id
             LEFT JOIN {$dept_table} d ON e.dept_id = d.id
             WHERE i.run_id = %d
             ORDER BY d.name, e.first_name",
            $run_id
        ) );

        // Build headers
        $headers = [
            __( 'Employee ID', 'sfs-hr' ),
            __( 'Employee Name', 'sfs-hr' ),
            __( 'Department', 'sfs-hr' ),
            __( 'Job Title', 'sfs-hr' ),
            __( 'Basic Salary', 'sfs-hr' ),
            __( 'Housing Allowance', 'sfs-hr' ),
            __( 'Transport Allowance', 'sfs-hr' ),
            __( 'Other Allowances', 'sfs-hr' ),
            __( 'Gross Salary', 'sfs-hr' ),
        ];

        if ( $include_attendance ) {
            $headers = array_merge( $headers, [
                __( 'Work Days', 'sfs-hr' ),
                __( 'Work Hours', 'sfs-hr' ),
                __( 'Overtime Hours', 'sfs-hr' ),
                __( 'Late Days', 'sfs-hr' ),
                __( 'Absent Days', 'sfs-hr' ),
            ] );
        }

        if ( $include_deductions ) {
            $headers = array_merge( $headers, [
                __( 'GOSI Deduction', 'sfs-hr' ),
                __( 'Absence Deduction', 'sfs-hr' ),
                __( 'Late Deduction', 'sfs-hr' ),
                __( 'Other Deductions', 'sfs-hr' ),
            ] );
        }

        if ( $include_loans ) {
            $headers[] = __( 'Loan Deduction', 'sfs-hr' );
        }

        $headers = array_merge( $headers, [
            __( 'Total Deductions', 'sfs-hr' ),
            __( 'Net Salary', 'sfs-hr' ),
        ] );

        $rows = [ $headers ];

        foreach ( $items as $item ) {
            $emp_name = trim( ( $item->first_name ?? '' ) . ' ' . ( $item->last_name ?? '' ) );

            // Extract allowances from components_json
            $housing_allowance = 0;
            $transport_allowance = 0;
            $other_allowances = 0;
            $components = ! empty( $item->components_json ) ? json_decode( $item->components_json, true ) : [];
            if ( is_array( $components ) ) {
                foreach ( $components as $comp ) {
                    $code = $comp['code'] ?? '';
                    if ( $code === 'HOUSING' ) {
                        $housing_allowance += (float) ( $comp['amount'] ?? 0 );
                    } elseif ( $code === 'TRANSPORT' ) {
                        $transport_allowance += (float) ( $comp['amount'] ?? 0 );
                    } elseif ( ( $comp['type'] ?? '' ) === 'earning' && ! in_array( $code, [ 'BASE', 'HOUSING', 'TRANSPORT', 'OVERTIME' ], true ) ) {
                        $other_allowances += (float) ( $comp['amount'] ?? 0 );
                    }
                }
            }

            $row = [
                $item->employee_code ?: $item->employee_id,
                $emp_name,
                $item->department ?: '-',
                $item->position ?: '-',
                number_format( (float) $item->base_salary, 2, '.', '' ),
                number_format( $housing_allowance, 2, '.', '' ),
                number_format( $transport_allowance, 2, '.', '' ),
                number_format( $other_allowances, 2, '.', '' ),
                number_format( (float) $item->gross_salary, 2, '.', '' ),
            ];

            if ( $include_attendance ) {
                // Get attendance summary for this employee in this period
                $att = $wpdb->get_row( $wpdb->prepare(
                    "SELECT
                        COUNT(*) as total_days,
                        SUM(net_minutes) as work_minutes,
                        SUM(overtime_minutes) as ot_minutes,
                        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days
                     FROM {$sessions_table}
                     WHERE employee_id = %d AND work_date BETWEEN %s AND %s",
                    $item->employee_id,
                    $run->start_date,
                    $run->end_date
                ) );

                $row = array_merge( $row, [
                    (int) ( $att->total_days ?? 0 ),
                    number_format( ( (int) ( $att->work_minutes ?? 0 ) ) / 60, 2 ),
                    number_format( ( (int) ( $att->ot_minutes ?? 0 ) ) / 60, 2 ),
                    (int) ( $att->late_days ?? 0 ),
                    (int) ( $att->absent_days ?? 0 ),
                ] );
            }

            if ( $include_deductions ) {
                // Extract deduction breakdown from components_json
                $gosi_deduction = 0;
                $absence_deduction_amt = (float) ( $item->attendance_deduction ?? 0 );
                $late_deduction_amt = 0;
                $other_deductions_amt = 0;
                if ( is_array( $components ) ) {
                    foreach ( $components as $comp ) {
                        $code = $comp['code'] ?? '';
                        if ( $code === 'GOSI_EMP' ) {
                            $gosi_deduction += (float) ( $comp['amount'] ?? 0 );
                        } elseif ( $code === 'ABSENCE' ) {
                            $absence_deduction_amt = (float) ( $comp['amount'] ?? 0 );
                        } elseif ( $code === 'LATE' ) {
                            $late_deduction_amt = (float) ( $comp['amount'] ?? 0 );
                        } elseif ( ( $comp['type'] ?? '' ) === 'deduction' && ! in_array( $code, [ 'GOSI_EMP', 'ABSENCE', 'LATE', 'LOAN' ], true ) ) {
                            $other_deductions_amt += (float) ( $comp['amount'] ?? 0 );
                        }
                    }
                }
                $row = array_merge( $row, [
                    number_format( $gosi_deduction, 2, '.', '' ),
                    number_format( $absence_deduction_amt, 2, '.', '' ),
                    number_format( $late_deduction_amt, 2, '.', '' ),
                    number_format( $other_deductions_amt, 2, '.', '' ),
                ] );
            }

            if ( $include_loans ) {
                $row[] = number_format( (float) ( $item->loan_deduction ?? 0 ), 2, '.', '' );
            }

            $row = array_merge( $row, [
                number_format( (float) $item->total_deductions, 2, '.', '' ),
                number_format( (float) $item->net_salary, 2, '.', '' ),
            ] );

            $rows[] = $row;
        }

        $filename = sanitize_file_name( 'payroll-detailed-' . sanitize_title( $run->period_name ) . '-' . wp_date( 'Y-m-d' ) );

        if ( $format === 'xlsx' ) {
            $this->export_xlsx( $filename, $rows );
        } else {
            $this->export_csv( $filename, $rows );
        }
    }

    /**
     * Export data as CSV
     */
    private function export_csv( string $filename, array $rows ): void {
        if ( ! str_ends_with( $filename, '.csv' ) ) {
            $filename .= '.csv';
        }

        while ( ob_get_level() ) { ob_end_clean(); }
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // BOM for Excel UTF-8 compatibility
        fwrite( $output, "\xEF\xBB\xBF" );

        foreach ( $rows as $row ) {
            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }

    /**
     * Export data as XLSX (simple implementation using CSV-like format that Excel can open)
     * For full XLSX support, consider using PhpSpreadsheet library
     */
    private function export_xlsx( string $filename, array $rows ): void {
        // For now, export as tab-separated values with .xlsx extension
        // Excel will open it correctly
        if ( ! str_ends_with( $filename, '.xlsx' ) ) {
            $filename .= '.xlsx';
        }

        while ( ob_get_level() ) { ob_end_clean(); }
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // BOM for Excel UTF-8 compatibility
        fwrite( $output, "\xEF\xBB\xBF" );

        foreach ( $rows as $row ) {
            fputcsv( $output, $row, "\t" ); // Tab-separated for Excel
        }

        fclose( $output );
        exit;
    }

    public function handle_export_bank(): void {
        if ( ! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
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
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&payroll_tab=runs&error=invalid_run' ) );
            exit;
        }

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, e.first_name, e.last_name, e.employee_code
             FROM {$items_table} i
             LEFT JOIN {$emp_table} e ON e.id = i.employee_id
             WHERE i.run_id = %d
             ORDER BY e.first_name",
            $run_id
        ) );

        // Generate CSV for bank transfer
        $filename = sanitize_file_name( 'payroll-export-' . $run->period_name . '-' . wp_date( 'Y-m-d' ) . '.csv' );

        while ( ob_get_level() ) { ob_end_clean(); }
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fwrite( $output, "\xEF\xBB\xBF" );

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
            $emp_code = $item->employee_code ?? '';

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

    // --- M1.3 Handlers ---

    public function handle_create_adjustment(): void {
        Helpers::require_cap('sfs_hr_payroll_admin');
        check_admin_referer('sfs_hr_payroll_create_adjustment');

        $item_id = (int) ($_POST['payroll_item_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        $lines = [];

        if (!empty($_POST['adj_code']) && is_array($_POST['adj_code'])) {
            foreach ($_POST['adj_code'] as $i => $code) {
                $code = sanitize_text_field($code);
                $amount = (float) ($_POST['adj_amount'][$i] ?? 0);
                $line_reason = sanitize_text_field($_POST['adj_reason'][$i] ?? '');
                if ($code && $amount != 0) {
                    $lines[] = ['component_code' => $code, 'amount' => $amount, 'reason' => $line_reason];
                }
            }
        }

        $redirect = admin_url('admin.php?page=sfs-hr-payroll&payroll_tab=adjustments');
        $result = Services\AdjustmentService::create($item_id, $lines, $reason, get_current_user_id());

        if (!empty($result['success'])) {
            wp_safe_redirect(add_query_arg('adj_ok', '1', $redirect));
        } else {
            wp_safe_redirect(add_query_arg('adj_err', rawurlencode($result['error'] ?? __('Failed', 'sfs-hr')), $redirect));
        }
        exit;
    }

    public function handle_approve_adjustment(): void {
        Helpers::require_cap('sfs_hr_payroll_admin');
        check_admin_referer('sfs_hr_payroll_approve_adjustment');
        $id = (int) ($_POST['adjustment_id'] ?? 0);
        $redirect = admin_url('admin.php?page=sfs-hr-payroll&payroll_tab=adjustments');
        $result = Services\AdjustmentService::approve($id, get_current_user_id());
        if (!empty($result['success'])) {
            wp_safe_redirect(add_query_arg('adj_ok', '1', $redirect));
        } else {
            wp_safe_redirect(add_query_arg('adj_err', rawurlencode($result['error'] ?? __('Failed', 'sfs-hr')), $redirect));
        }
        exit;
    }

    public function handle_reject_adjustment(): void {
        Helpers::require_cap('sfs_hr_payroll_admin');
        check_admin_referer('sfs_hr_payroll_reject_adjustment');
        $id = (int) ($_POST['adjustment_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['rejection_reason'] ?? '');
        $redirect = admin_url('admin.php?page=sfs-hr-payroll&payroll_tab=adjustments');
        $result = Services\AdjustmentService::reject($id, get_current_user_id(), $reason);
        if (!empty($result['success'])) {
            wp_safe_redirect(add_query_arg('adj_ok', '1', $redirect));
        } else {
            wp_safe_redirect(add_query_arg('adj_err', rawurlencode($result['error'] ?? __('Failed', 'sfs-hr')), $redirect));
        }
        exit;
    }

    public function handle_lock_period(): void {
        Helpers::require_cap('sfs_hr_payroll_admin');
        check_admin_referer('sfs_hr_payroll_lock_period');
        $id = (int) ($_POST['period_id'] ?? 0);
        $redirect = admin_url('admin.php?page=sfs-hr-payroll&payroll_tab=periods');
        $result = Services\PeriodLockService::lock($id, get_current_user_id());
        if (!empty($result['success'])) {
            wp_safe_redirect(add_query_arg('period_ok', '1', $redirect));
        } else {
            wp_safe_redirect(add_query_arg('period_err', rawurlencode($result['error'] ?? __('Failed', 'sfs-hr')), $redirect));
        }
        exit;
    }

    public function handle_reopen_period(): void {
        Helpers::require_cap('sfs_hr.manage');
        check_admin_referer('sfs_hr_payroll_reopen_period');
        $id = (int) ($_POST['period_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        $redirect = admin_url('admin.php?page=sfs-hr-payroll&payroll_tab=periods');
        $result = Services\PeriodLockService::reopen($id, get_current_user_id(), $reason);
        if (!empty($result['success'])) {
            wp_safe_redirect(add_query_arg('period_ok', '1', $redirect));
        } else {
            wp_safe_redirect(add_query_arg('period_err', rawurlencode($result['error'] ?? __('Failed', 'sfs-hr')), $redirect));
        }
        exit;
    }

    public function handle_submit_review(): void {
        Helpers::require_cap('sfs_hr_payroll_run');
        check_admin_referer('sfs_hr_payroll_submit_review');
        $id = (int) ($_POST['run_id'] ?? 0);
        $redirect = admin_url('admin.php?page=sfs-hr-payroll&payroll_tab=runs');
        $result = Services\PeriodLockService::submit_for_review($id, get_current_user_id());
        if (!empty($result['success'])) {
            wp_safe_redirect(add_query_arg('run_ok', '1', $redirect));
        } else {
            wp_safe_redirect(add_query_arg('run_err', rawurlencode($result['error'] ?? __('Failed', 'sfs-hr')), $redirect));
        }
        exit;
    }

    public function handle_mark_paid(): void {
        Helpers::require_cap('sfs_hr_payroll_admin');
        check_admin_referer('sfs_hr_payroll_mark_paid');
        $id = (int) ($_POST['run_id'] ?? 0);
        $ref = sanitize_text_field($_POST['payment_reference'] ?? '');
        $redirect = admin_url('admin.php?page=sfs-hr-payroll&payroll_tab=runs');
        $result = Services\PeriodLockService::mark_paid($id, get_current_user_id(), $ref ?: null);
        if (!empty($result['success'])) {
            wp_safe_redirect(add_query_arg('run_ok', '1', $redirect));
        } else {
            wp_safe_redirect(add_query_arg('run_err', rawurlencode($result['error'] ?? __('Failed', 'sfs-hr')), $redirect));
        }
        exit;
    }

    public function handle_reverse_run(): void {
        Helpers::require_cap('sfs_hr.manage');
        check_admin_referer('sfs_hr_payroll_reverse_run');
        $id = (int) ($_POST['run_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        $redirect = admin_url('admin.php?page=sfs-hr-payroll&payroll_tab=runs');
        $result = Services\PeriodLockService::reverse_run($id, get_current_user_id(), $reason);
        if (!empty($result['success'])) {
            wp_safe_redirect(add_query_arg('run_ok', '1', $redirect));
        } else {
            wp_safe_redirect(add_query_arg('run_err', rawurlencode($result['error'] ?? __('Failed', 'sfs-hr')), $redirect));
        }
        exit;
    }

    private function render_adjustments(): void {
        global $wpdb;
        $filters = ['limit' => 50, 'offset' => 0];
        if (!empty($_GET['status'])) $filters['status'] = sanitize_key($_GET['status']);
        if (!empty($_GET['run_id'])) $filters['run_id'] = (int) $_GET['run_id'];

        $adjustments = Services\AdjustmentService::get_adjustments($filters);
        ?>
        <h2><?php esc_html_e('Payroll Adjustments', 'sfs-hr'); ?></h2>

        <?php if (!empty($_GET['adj_ok'])): ?>
        <div class="notice notice-success"><p><?php esc_html_e('Adjustment processed successfully.', 'sfs-hr'); ?></p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['adj_err'])): ?>
        <div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['adj_err']))); ?></p></div>
        <?php endif; ?>

        <?php if (empty($adjustments)): ?>
        <div class="notice notice-info"><p><?php esc_html_e('No adjustments found.', 'sfs-hr'); ?></p></div>
        <?php else: ?>
        <div class="sfs-hr-table-responsive">
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Run', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Type', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Amount', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Reason', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Date', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($adjustments as $adj): ?>
                <tr>
                    <td><?php echo (int) $adj['id']; ?></td>
                    <td><?php echo esc_html(trim(($adj['first_name'] ?? '') . ' ' . ($adj['last_name'] ?? ''))); ?></td>
                    <td>#<?php echo (int) $adj['run_id']; ?></td>
                    <td><?php echo esc_html(ucfirst($adj['adjustment_type'])); ?></td>
                    <td><?php echo esc_html(number_format((float) $adj['total_amount'], 2)); ?> SAR</td>
                    <td><?php echo esc_html(mb_strimwidth($adj['reason'] ?? '', 0, 60, '...')); ?></td>
                    <td>
                        <span class="sfs-hr-badge sfs-hr-badge--<?php echo esc_attr($adj['status']); ?>">
                            <?php echo esc_html(ucfirst($adj['status'])); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($adj['created_at']))); ?></td>
                    <td>
                        <?php if ($adj['status'] === 'pending' && current_user_can('sfs_hr_payroll_admin')): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <?php wp_nonce_field('sfs_hr_payroll_approve_adjustment'); ?>
                            <input type="hidden" name="action" value="sfs_hr_payroll_approve_adjustment" />
                            <input type="hidden" name="adjustment_id" value="<?php echo (int) $adj['id']; ?>" />
                            <button type="submit" class="button button-small button-primary" onclick="return confirm('<?php esc_attr_e('Approve this adjustment?', 'sfs-hr'); ?>');"><?php esc_html_e('Approve', 'sfs-hr'); ?></button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <?php wp_nonce_field('sfs_hr_payroll_reject_adjustment'); ?>
                            <input type="hidden" name="action" value="sfs_hr_payroll_reject_adjustment" />
                            <input type="hidden" name="adjustment_id" value="<?php echo (int) $adj['id']; ?>" />
                            <input type="hidden" name="rejection_reason" value="" />
                            <button type="submit" class="button button-small" onclick="var r=prompt('<?php esc_attr_e('Rejection reason:', 'sfs-hr'); ?>'); if(!r) return false; this.form.rejection_reason.value=r;"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif;
    }

    private function render_audit(): void {
        $recent = Services\AuditService::get_recent(50);
        ?>
        <h2><?php esc_html_e('Payroll Audit Trail', 'sfs-hr'); ?></h2>

        <?php if (empty($recent)): ?>
        <div class="notice notice-info"><p><?php esc_html_e('No audit entries yet.', 'sfs-hr'); ?></p></div>
        <?php else: ?>
        <div class="sfs-hr-table-responsive">
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('User', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Entity', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Action', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Details', 'sfs-hr'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $entry): ?>
                <tr>
                    <td><?php echo esc_html(date_i18n('Y-m-d H:i', strtotime($entry['created_at']))); ?></td>
                    <td><?php echo esc_html($entry['actor_name'] ?? __('System', 'sfs-hr')); ?></td>
                    <td><?php echo esc_html(ucfirst($entry['entity_type']) . ' #' . $entry['entity_id']); ?></td>
                    <td>
                        <span class="sfs-hr-badge"><?php echo esc_html(ucfirst(str_replace('_', ' ', $entry['action']))); ?></span>
                    </td>
                    <td>
                        <?php
                        $details = is_array($entry['details'] ?? null) ? $entry['details'] : [];
                        if (!empty($details['reason'])) {
                            echo esc_html(mb_strimwidth($details['reason'], 0, 80, '...'));
                        } elseif (!empty($details['total_amount'])) {
                            echo esc_html(number_format((float) $details['total_amount'], 2) . ' SAR');
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif;
    }
}
