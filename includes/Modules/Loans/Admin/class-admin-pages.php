<?php
namespace SFS\HR\Modules\Loans\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Loans Admin Pages
 */
class AdminPages {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'menu' ], 20 );
        add_action( 'admin_init', [ $this, 'save_settings' ] );
    }

    /**
     * Register admin menu
     */
    public function menu(): void {
        $parent_slug = 'sfs-hr';

        add_submenu_page(
            $parent_slug,
            __( 'Loans', 'sfs-hr' ),
            __( 'Loans', 'sfs-hr' ),
            'sfs_hr.manage',
            'sfs-hr-loans',
            [ $this, 'loans_page' ]
        );
    }

    /**
     * Main loans page (list view)
     */
    public function loans_page(): void {
        $tab = $_GET['tab'] ?? 'loans';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Loans Management', 'sfs-hr' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=sfs-hr-loans&tab=loans"
                   class="nav-tab <?php echo $tab === 'loans' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'All Loans', 'sfs-hr' ); ?>
                </a>
                <a href="?page=sfs-hr-loans&tab=installments"
                   class="nav-tab <?php echo $tab === 'installments' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Monthly Installments', 'sfs-hr' ); ?>
                </a>
                <a href="?page=sfs-hr-loans&tab=settings"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'sfs-hr' ); ?>
                </a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                switch ( $tab ) {
                    case 'installments':
                        $this->render_installments_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'loans':
                    default:
                        $this->render_loans_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render loans list tab
     */
    private function render_loans_tab(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_loans';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Filters
        $status = $_GET['filter_status'] ?? '';
        $search = $_GET['s'] ?? '';

        $where = [ '1=1' ];
        $params = [];

        if ( $status ) {
            $where[] = 'l.status = %s';
            $params[] = $status;
        }

        if ( $search ) {
            $where[] = '(e.first_name LIKE %s OR e.last_name LIKE %s OR l.loan_number LIKE %s)';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = '%' . $wpdb->esc_like( $search ) . '%';
        }

        $where_sql = implode( ' AND ', $where );

        $query = "SELECT l.*,
                         CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                         e.employee_code
                  FROM {$table} l
                  LEFT JOIN {$emp_table} e ON l.employee_id = e.id
                  WHERE {$where_sql}
                  ORDER BY l.created_at DESC";

        if ( ! empty( $params ) ) {
            $query = $wpdb->prepare( $query, ...$params );
        }

        $loans = $wpdb->get_results( $query );

        ?>
        <div class="tablenav top">
            <div class="alignleft actions">
                <select name="filter_status" onchange="location.href='?page=sfs-hr-loans&filter_status='+this.value">
                    <option value=""><?php esc_html_e( 'All Statuses', 'sfs-hr' ); ?></option>
                    <option value="pending_gm" <?php selected( $status, 'pending_gm' ); ?>><?php esc_html_e( 'Pending GM', 'sfs-hr' ); ?></option>
                    <option value="pending_finance" <?php selected( $status, 'pending_finance' ); ?>><?php esc_html_e( 'Pending Finance', 'sfs-hr' ); ?></option>
                    <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'sfs-hr' ); ?></option>
                    <option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'sfs-hr' ); ?></option>
                    <option value="rejected" <?php selected( $status, 'rejected' ); ?>><?php esc_html_e( 'Rejected', 'sfs-hr' ); ?></option>
                    <option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'sfs-hr' ); ?></option>
                </select>
            </div>
            <div class="alignleft actions">
                <form method="get" style="display: inline-block;">
                    <input type="hidden" name="page" value="sfs-hr-loans" />
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'sfs-hr' ); ?>" />
                    <button type="submit" class="button"><?php esc_html_e( 'Search', 'sfs-hr' ); ?></button>
                </form>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Loan #', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Principal', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Remaining', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Installments', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'First Due', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $loans ) ) : ?>
                    <tr>
                        <td colspan="9"><?php esc_html_e( 'No loans found.', 'sfs-hr' ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $loans as $loan ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $loan->loan_number ); ?></strong></td>
                            <td>
                                <?php echo esc_html( $loan->employee_name ); ?>
                                <br><small><?php echo esc_html( $loan->employee_code ); ?></small>
                            </td>
                            <td><?php echo number_format( (float) $loan->principal_amount, 2 ); ?> <?php echo esc_html( $loan->currency ); ?></td>
                            <td><?php echo number_format( (float) $loan->remaining_balance, 2 ); ?> <?php echo esc_html( $loan->currency ); ?></td>
                            <td>
                                <?php echo (int) $loan->installments_count; ?> ×
                                <?php echo number_format( (float) $loan->installment_amount, 2 ); ?>
                            </td>
                            <td><?php echo $this->get_status_badge( $loan->status ); ?></td>
                            <td><?php echo $loan->first_due_date ? esc_html( wp_date( 'Y-m-d', strtotime( $loan->first_due_date ) ) ) : '—'; ?></td>
                            <td><?php echo esc_html( wp_date( 'Y-m-d', strtotime( $loan->created_at ) ) ); ?></td>
                            <td>
                                <a href="?page=sfs-hr-loans&action=view&id=<?php echo (int) $loan->id; ?>" class="button button-small">
                                    <?php esc_html_e( 'View', 'sfs-hr' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render monthly installments tab (Finance only)
     */
    private function render_installments_tab(): void {
        echo '<div class="notice notice-info"><p>' . esc_html__( 'Monthly installments management - Coming in Phase 3', 'sfs-hr' ) . '</p></div>';
    }

    /**
     * Render settings tab
     */
    private function render_settings_tab(): void {
        $settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();

        ?>
        <form method="post" action="">
            <?php wp_nonce_field( 'sfs_hr_loans_settings' ); ?>
            <input type="hidden" name="action" value="save_loans_settings" />

            <h2><?php esc_html_e( 'Loan Settings', 'sfs-hr' ); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Enable Loans Module', 'sfs-hr' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'], true ); ?> />
                            <?php esc_html_e( 'Enable cash advance requests', 'sfs-hr' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Maximum Loan Amount', 'sfs-hr' ); ?></th>
                    <td>
                        <input type="number" name="max_loan_amount" value="<?php echo esc_attr( $settings['max_loan_amount'] ); ?>" step="0.01" />
                        <p class="description"><?php esc_html_e( 'Set to 0 for no limit. This is a soft limit (can be overridden).', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Max Loan as Salary Multiplier', 'sfs-hr' ); ?></th>
                    <td>
                        <input type="number" name="max_loan_multiplier" value="<?php echo esc_attr( $settings['max_loan_multiplier'] ); ?>" step="0.1" />
                        <p class="description"><?php esc_html_e( 'e.g., 2.0 = Maximum 2× basic salary. Set to 0 to disable.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Maximum Installment Amount', 'sfs-hr' ); ?></th>
                    <td>
                        <input type="number" name="max_installment_amount" value="<?php echo esc_attr( $settings['max_installment_amount'] ); ?>" step="0.01" />
                        <p class="description"><?php esc_html_e( 'Maximum monthly installment. Set to 0 for no limit.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Max Installment (% of Salary)', 'sfs-hr' ); ?></th>
                    <td>
                        <input type="number" name="max_installment_percent" value="<?php echo esc_attr( $settings['max_installment_percent'] ); ?>" min="0" max="100" />
                        <p class="description"><?php esc_html_e( 'e.g., 30 = Maximum 30% of basic salary. Set to 0 to disable.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Deduction Start Offset', 'sfs-hr' ); ?></th>
                    <td>
                        <input type="number" name="loan_start_offset_months" value="<?php echo esc_attr( $settings['loan_start_offset_months'] ); ?>" min="1" />
                        <p class="description"><?php esc_html_e( 'Number of months after request before first deduction (e.g., 2 = request in Dec, first deduction in Feb).', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Multiple Active Loans', 'sfs-hr' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="allow_multiple_active_loans" value="1" <?php checked( $settings['allow_multiple_active_loans'], true ); ?> />
                            <?php esc_html_e( 'Allow employees to have multiple active loans', 'sfs-hr' ); ?>
                        </label>
                        <br>
                        <label style="margin-top: 8px; display: inline-block;">
                            <?php esc_html_e( 'Max active loans per employee:', 'sfs-hr' ); ?>
                            <input type="number" name="max_active_loans_per_employee" value="<?php echo esc_attr( $settings['max_active_loans_per_employee'] ); ?>" min="1" style="width: 60px;" />
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Approval Requirements', 'sfs-hr' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="require_gm_approval" value="1" <?php checked( $settings['require_gm_approval'], true ); ?> />
                            <?php esc_html_e( 'Require GM approval', 'sfs-hr' ); ?>
                        </label>
                        <br>
                        <label style="margin-top: 8px; display: inline-block;">
                            <input type="checkbox" name="require_finance_approval" value="1" <?php checked( $settings['require_finance_approval'], true ); ?> disabled />
                            <?php esc_html_e( 'Require Finance approval', 'sfs-hr' ); ?>
                            <span class="description">(<?php esc_html_e( 'Always required', 'sfs-hr' ); ?>)</span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Early Repayment', 'sfs-hr' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="allow_early_repayment" value="1" <?php checked( $settings['allow_early_repayment'], true ); ?> />
                            <?php esc_html_e( 'Allow employees to request early repayment', 'sfs-hr' ); ?>
                        </label>
                        <br>
                        <label style="margin-top: 8px; display: inline-block;">
                            <input type="checkbox" name="early_repayment_requires_approval" value="1" <?php checked( $settings['early_repayment_requires_approval'], true ); ?> />
                            <?php esc_html_e( 'Early repayment requires Finance approval', 'sfs-hr' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Employee Portal', 'sfs-hr' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_in_my_profile" value="1" <?php checked( $settings['show_in_my_profile'], true ); ?> />
                            <?php esc_html_e( 'Show Loans tab in My Profile', 'sfs-hr' ); ?>
                        </label>
                        <br>
                        <label style="margin-top: 8px; display: inline-block;">
                            <input type="checkbox" name="allow_employee_requests" value="1" <?php checked( $settings['allow_employee_requests'], true ); ?> />
                            <?php esc_html_e( 'Allow employees to submit loan requests', 'sfs-hr' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Settings', 'sfs-hr' ) ); ?>
        </form>
        <?php
    }

    /**
     * Save settings
     */
    public function save_settings(): void {
        if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'save_loans_settings' ) {
            return;
        }

        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            return;
        }

        check_admin_referer( 'sfs_hr_loans_settings' );

        $settings = [
            'enabled'                          => isset( $_POST['enabled'] ),
            'max_loan_amount'                  => (float) ( $_POST['max_loan_amount'] ?? 0 ),
            'max_loan_multiplier'              => (float) ( $_POST['max_loan_multiplier'] ?? 0 ),
            'max_installment_amount'           => (float) ( $_POST['max_installment_amount'] ?? 0 ),
            'max_installment_percent'          => (int) ( $_POST['max_installment_percent'] ?? 0 ),
            'loan_start_offset_months'         => (int) ( $_POST['loan_start_offset_months'] ?? 2 ),
            'allow_multiple_active_loans'      => isset( $_POST['allow_multiple_active_loans'] ),
            'max_active_loans_per_employee'    => (int) ( $_POST['max_active_loans_per_employee'] ?? 1 ),
            'require_gm_approval'              => isset( $_POST['require_gm_approval'] ),
            'require_finance_approval'         => true, // Always required
            'allow_early_repayment'            => isset( $_POST['allow_early_repayment'] ),
            'early_repayment_requires_approval'=> isset( $_POST['early_repayment_requires_approval'] ),
            'show_in_my_profile'               => isset( $_POST['show_in_my_profile'] ),
            'allow_employee_requests'          => isset( $_POST['allow_employee_requests'] ),
        ];

        update_option( \SFS\HR\Modules\Loans\LoansModule::OPT_SETTINGS, $settings );

        wp_safe_redirect( add_query_arg( [ 'page' => 'sfs-hr-loans', 'tab' => 'settings', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Get status badge HTML
     */
    private function get_status_badge( string $status ): string {
        $badges = [
            'pending_gm'      => '<span style="background:#ffa500;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Pending GM</span>',
            'pending_finance' => '<span style="background:#ff8c00;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Pending Finance</span>',
            'active'          => '<span style="background:#28a745;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Active</span>',
            'completed'       => '<span style="background:#6c757d;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Completed</span>',
            'rejected'        => '<span style="background:#dc3545;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Rejected</span>',
            'cancelled'       => '<span style="background:#6c757d;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Cancelled</span>',
        ];

        return $badges[ $status ] ?? esc_html( $status );
    }
}
