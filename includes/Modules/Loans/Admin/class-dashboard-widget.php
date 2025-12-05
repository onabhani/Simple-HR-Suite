<?php
namespace SFS\HR\Modules\Loans\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Loans Dashboard Widget
 *
 * Displays loan statistics on WordPress dashboard and HR overview pages
 */
class DashboardWidget {

    public function __construct() {
        // Add WordPress dashboard widget
        add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );

        // Add hook for HR dashboard integration
        add_action( 'sfs_hr_dashboard_widgets', [ $this, 'render_hr_widget' ] );
    }

    /**
     * Register WordPress dashboard widget
     */
    public function add_dashboard_widget(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'sfs_hr_loans_overview',
            __( 'Loans Overview', 'sfs-hr' ),
            [ $this, 'render_dashboard_widget' ]
        );
    }

    /**
     * Render WordPress dashboard widget
     */
    public function render_dashboard_widget(): void {
        $stats = $this->get_loan_statistics();
        $this->render_widget_content( $stats );
    }

    /**
     * Render HR dashboard widget (hook for HR dashboard pages)
     */
    public function render_hr_widget(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            return;
        }

        $stats = $this->get_loan_statistics();

        echo '<div class="sfs-hr-dashboard-widget sfs-hr-loans-widget">';
        echo '<h3>' . esc_html__( 'Loans Overview', 'sfs-hr' ) . '</h3>';
        $this->render_widget_content( $stats );
        echo '</div>';
    }

    /**
     * Get comprehensive loan statistics
     */
    private function get_loan_statistics(): array {
        global $wpdb;
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';

        // Total active loans
        $active_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$loans_table} WHERE status = 'active'"
        );

        // Total outstanding amount
        $outstanding = (float) $wpdb->get_var(
            "SELECT SUM(remaining_balance) FROM {$loans_table} WHERE status = 'active'"
        );

        // Number of employees with active loans
        $employees_with_loans = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT employee_id) FROM {$loans_table} WHERE status = 'active'"
        );

        // Pending approvals
        $pending_gm = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$loans_table} WHERE status = 'pending_gm'"
        );

        $pending_finance = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$loans_table} WHERE status = 'pending_finance'"
        );

        // Total loans this month
        $this_month_start = wp_date( 'Y-m-01' );
        $this_month_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$loans_table} WHERE DATE(created_at) >= %s",
            $this_month_start
        ) );

        // Completed loans
        $completed_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$loans_table} WHERE status = 'completed'"
        );

        // Total amount disbursed (all-time)
        $total_disbursed = (float) $wpdb->get_var(
            "SELECT SUM(principal_amount) FROM {$loans_table} WHERE status IN ('active', 'completed')"
        );

        return [
            'active_count'          => $active_count,
            'outstanding_amount'    => $outstanding,
            'employees_with_loans'  => $employees_with_loans,
            'pending_gm'            => $pending_gm,
            'pending_finance'       => $pending_finance,
            'this_month_count'      => $this_month_count,
            'completed_count'       => $completed_count,
            'total_disbursed'       => $total_disbursed,
        ];
    }

    /**
     * Render widget content
     */
    private function render_widget_content( array $stats ): void {
        ?>
        <div class="sfs-hr-loans-stats">
            <style>
                .sfs-hr-loans-stats { font-size: 13px; }
                .sfs-hr-loans-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px; }
                .sfs-hr-loans-stat-box { padding: 10px; background: #f8f9fa; border-left: 3px solid #2271b1; border-radius: 3px; }
                .sfs-hr-loans-stat-box.warning { border-left-color: #dba617; background: #fcf9e8; }
                .sfs-hr-loans-stat-box.success { border-left-color: #2ea44f; background: #f0fdf4; }
                .sfs-hr-loans-stat-label { font-size: 11px; color: #666; text-transform: uppercase; margin-bottom: 4px; }
                .sfs-hr-loans-stat-value { font-size: 20px; font-weight: 600; color: #1d2327; }
                .sfs-hr-loans-stat-value small { font-size: 12px; font-weight: normal; color: #666; margin-left: 4px; }
                .sfs-hr-loans-actions { margin-top: 12px; padding-top: 12px; border-top: 1px solid #ddd; }
                .sfs-hr-loans-actions a { text-decoration: none; margin-right: 12px; }
            </style>

            <div class="sfs-hr-loans-stat-grid">
                <div class="sfs-hr-loans-stat-box">
                    <div class="sfs-hr-loans-stat-label"><?php esc_html_e( 'Active Loans', 'sfs-hr' ); ?></div>
                    <div class="sfs-hr-loans-stat-value">
                        <?php echo number_format( $stats['active_count'] ); ?>
                    </div>
                </div>

                <div class="sfs-hr-loans-stat-box">
                    <div class="sfs-hr-loans-stat-label"><?php esc_html_e( 'Outstanding Amount', 'sfs-hr' ); ?></div>
                    <div class="sfs-hr-loans-stat-value">
                        <?php echo number_format( $stats['outstanding_amount'], 0 ); ?>
                        <small>SAR</small>
                    </div>
                </div>

                <div class="sfs-hr-loans-stat-box">
                    <div class="sfs-hr-loans-stat-label"><?php esc_html_e( 'Employees with Loans', 'sfs-hr' ); ?></div>
                    <div class="sfs-hr-loans-stat-value">
                        <?php echo number_format( $stats['employees_with_loans'] ); ?>
                    </div>
                </div>

                <div class="sfs-hr-loans-stat-box success">
                    <div class="sfs-hr-loans-stat-label"><?php esc_html_e( 'Completed', 'sfs-hr' ); ?></div>
                    <div class="sfs-hr-loans-stat-value">
                        <?php echo number_format( $stats['completed_count'] ); ?>
                    </div>
                </div>
            </div>

            <?php if ( $stats['pending_gm'] > 0 || $stats['pending_finance'] > 0 ) : ?>
                <div class="sfs-hr-loans-stat-grid">
                    <?php if ( $stats['pending_gm'] > 0 ) : ?>
                        <div class="sfs-hr-loans-stat-box warning">
                            <div class="sfs-hr-loans-stat-label"><?php esc_html_e( 'Pending GM Approval', 'sfs-hr' ); ?></div>
                            <div class="sfs-hr-loans-stat-value">
                                <?php echo number_format( $stats['pending_gm'] ); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $stats['pending_finance'] > 0 ) : ?>
                        <div class="sfs-hr-loans-stat-box warning">
                            <div class="sfs-hr-loans-stat-label"><?php esc_html_e( 'Pending Finance Approval', 'sfs-hr' ); ?></div>
                            <div class="sfs-hr-loans-stat-value">
                                <?php echo number_format( $stats['pending_finance'] ); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="sfs-hr-loans-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-loans&tab=loans' ) ); ?>" class="button button-small">
                    <?php esc_html_e( 'View All Loans', 'sfs-hr' ); ?>
                </a>
                <?php if ( $stats['pending_gm'] > 0 || $stats['pending_finance'] > 0 ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-loans&tab=loans&filter_status=pending_gm' ) ); ?>" class="button button-small">
                        <?php esc_html_e( 'Pending Approvals', 'sfs-hr' ); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-loans&tab=installments' ) ); ?>" class="button button-small">
                    <?php esc_html_e( 'Monthly Installments', 'sfs-hr' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Get loan summary for specific employee (helper for other modules)
     *
     * @param int $employee_id Employee ID
     * @return array|null Loan summary or null if no loans
     */
    public static function get_employee_loan_summary( int $employee_id ): ?array {
        $has_loans = \SFS\HR\Modules\Loans\LoansModule::has_active_loans( $employee_id );

        if ( ! $has_loans ) {
            return null;
        }

        global $wpdb;
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';

        $summary = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as loan_count,
                SUM(remaining_balance) as total_outstanding,
                MIN(first_due_date) as next_due_date
             FROM {$loans_table}
             WHERE employee_id = %d AND status = 'active'",
            $employee_id
        ), ARRAY_A );

        return [
            'has_loans'         => true,
            'loan_count'        => (int) $summary['loan_count'],
            'total_outstanding' => (float) $summary['total_outstanding'],
            'next_due_date'     => $summary['next_due_date'],
        ];
    }

    /**
     * Render employee loan badge (for use in employee lists)
     *
     * @param int $employee_id Employee ID
     * @return string HTML badge
     */
    public static function render_employee_loan_badge( int $employee_id ): string {
        $summary = self::get_employee_loan_summary( $employee_id );

        if ( ! $summary ) {
            return '<span style="color:#999;font-size:11px;">—</span>';
        }

        $badge_color = $summary['total_outstanding'] > 10000 ? '#dc3545' : '#f59e0b';

        return sprintf(
            '<span style="background:%s;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;white-space:nowrap;" title="%s">
                💰 %d loan%s (%s SAR)
            </span>',
            $badge_color,
            esc_attr( sprintf(
                __( 'Total outstanding: %s SAR', 'sfs-hr' ),
                number_format( $summary['total_outstanding'], 2 )
            ) ),
            $summary['loan_count'],
            $summary['loan_count'] > 1 ? 's' : '',
            number_format( $summary['total_outstanding'], 0 )
        );
    }
}
