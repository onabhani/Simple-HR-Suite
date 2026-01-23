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
        add_action( 'admin_init', [ $this, 'handle_loan_actions' ] );
        add_action( 'admin_init', [ $this, 'handle_installment_actions' ] );
        add_action( 'admin_init', [ $this, 'export_installments_csv' ] );
    }

    /**
     * Register admin menu
     */
    public function menu(): void {
        $parent_slug = 'sfs-hr';

        // Allow access if user can manage, or is a GM/Finance approver
        $can_access = current_user_can( 'sfs_hr.manage' )
                   || current_user_can( 'sfs_hr_loans_gm_approve' )
                   || current_user_can( 'sfs_hr_loans_finance_approve' );

        if ( ! $can_access ) {
            return;
        }

        add_submenu_page(
            $parent_slug,
            __( 'Loans', 'sfs-hr' ),
            __( 'Loans', 'sfs-hr' ),
            'read', // Use 'read' as base capability, we check permissions internally
            'sfs-hr-loans',
            [ $this, 'loans_page' ]
        );
    }

    /**
     * Main loans page (list view or detail)
     */
    public function loans_page(): void {
        // Verify access
        $can_access = current_user_can( 'sfs_hr.manage' )
                   || current_user_can( 'sfs_hr_loans_gm_approve' )
                   || current_user_can( 'sfs_hr_loans_finance_approve' );

        if ( ! $can_access ) {
            wp_die( __( 'You do not have permission to access this page.', 'sfs-hr' ) );
        }

        $action = $_GET['action'] ?? '';
        $loan_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

        // Show loan detail if action=view
        if ( $action === 'view' && $loan_id > 0 ) {
            $this->render_loan_detail( $loan_id );
            return;
        }

        // Show create form if action=create
        if ( $action === 'create' ) {
            $this->render_create_loan_form();
            return;
        }

        // Otherwise show tabs
        $tab = $_GET['tab'] ?? 'loans';

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e( 'Loans Management', 'sfs-hr' ); ?></h1>
            <?php \SFS\HR\Core\Helpers::render_admin_nav(); ?>

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

        $this->output_loans_styles();

        // Filters
        $current_status = isset( $_GET['filter_status'] ) ? sanitize_key( $_GET['filter_status'] ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        // Get counts for each status
        $status_tabs = [
            ''                => __( 'All', 'sfs-hr' ),
            'pending_gm'      => __( 'Pending GM', 'sfs-hr' ),
            'pending_finance' => __( 'Pending Finance', 'sfs-hr' ),
            'active'          => __( 'Active', 'sfs-hr' ),
            'completed'       => __( 'Completed', 'sfs-hr' ),
            'rejected'        => __( 'Rejected', 'sfs-hr' ),
            'cancelled'       => __( 'Cancelled', 'sfs-hr' ),
        ];

        $counts = [];
        foreach ( array_keys( $status_tabs ) as $st ) {
            if ( $st === '' ) {
                $count_sql = "SELECT COUNT(*) FROM {$table}";
                $counts[ $st ] = (int) $wpdb->get_var( $count_sql );
            } else {
                $count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $st );
                $counts[ $st ] = (int) $wpdb->get_var( $count_sql );
            }
        }

        // Build query
        $where = [ '1=1' ];
        $params = [];

        if ( $current_status ) {
            $where[] = 'l.status = %s';
            $params[] = $current_status;
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
        $total = count( $loans );

        ?>
        <!-- Toolbar -->
        <div class="sfs-hr-loans-toolbar">
            <form method="get">
                <input type="hidden" name="page" value="sfs-hr-loans" />
                <input type="hidden" name="filter_status" value="<?php echo esc_attr( $current_status ); ?>" />
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search employee or loan #...', 'sfs-hr' ); ?>" />
                <button type="submit" class="button"><?php esc_html_e( 'Search', 'sfs-hr' ); ?></button>
                <?php if ( $search !== '' ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-loans' . ( $current_status ? '&filter_status=' . $current_status : '' ) ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'sfs-hr' ); ?></a>
                <?php endif; ?>
                <a href="?page=sfs-hr-loans&action=create" class="button button-primary" style="margin-left:auto;"><?php esc_html_e( 'Add New Loan', 'sfs-hr' ); ?></a>
            </form>
        </div>

        <!-- Status Tabs -->
        <div class="sfs-hr-loans-tabs">
            <?php foreach ( $status_tabs as $key => $label ) : ?>
                <?php
                $url = add_query_arg( [
                    'page'          => 'sfs-hr-loans',
                    'filter_status' => $key !== '' ? $key : null,
                    's'             => $search !== '' ? $search : null,
                ], admin_url( 'admin.php' ) );
                $classes = 'sfs-tab' . ( $key === $current_status ? ' active' : '' );
                ?>
                <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $classes ); ?>">
                    <?php echo esc_html( $label ); ?>
                    <span class="count"><?php echo esc_html( $counts[ $key ] ); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Table Card -->
        <div class="sfs-hr-loans-table-wrap">
            <div class="table-header">
                <h3><?php echo esc_html( $status_tabs[ $current_status ] ?? __( 'All Loans', 'sfs-hr' ) ); ?> (<?php echo esc_html( $total ); ?>)</h3>
            </div>

            <table class="sfs-hr-loans-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                        <th class="hide-mobile"><?php esc_html_e( 'Loan #', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Principal', 'sfs-hr' ); ?></th>
                        <th class="hide-mobile"><?php esc_html_e( 'Remaining', 'sfs-hr' ); ?></th>
                        <th class="hide-mobile"><?php esc_html_e( 'Installments', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                        <th class="hide-mobile"><?php esc_html_e( 'First Due', 'sfs-hr' ); ?></th>
                        <th class="show-mobile" style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $loans ) ) : ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                <p><?php esc_html_e( 'No loans found.', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $loans as $loan ) : ?>
                            <tr data-id="<?php echo (int) $loan->id; ?>"
                                data-number="<?php echo esc_attr( $loan->loan_number ); ?>"
                                data-employee="<?php echo esc_attr( $loan->employee_name ); ?>"
                                data-code="<?php echo esc_attr( $loan->employee_code ); ?>"
                                data-principal="<?php echo esc_attr( number_format( (float) $loan->principal_amount, 2 ) ); ?>"
                                data-remaining="<?php echo esc_attr( number_format( (float) $loan->remaining_balance, 2 ) ); ?>"
                                data-currency="<?php echo esc_attr( $loan->currency ); ?>"
                                data-installments="<?php echo (int) $loan->installments_count; ?>"
                                data-installment-amount="<?php echo esc_attr( number_format( (float) $loan->installment_amount, 2 ) ); ?>"
                                data-status="<?php echo esc_attr( $loan->status ); ?>"
                                data-first-due="<?php echo esc_attr( $loan->first_due_date ?: '' ); ?>"
                                data-created="<?php echo esc_attr( $loan->created_at ); ?>">
                                <td>
                                    <?php $profile_url = admin_url( 'admin.php?page=sfs-hr-employee-profile&employee_id=' . (int) $loan->employee_id ); ?>
                                    <a href="<?php echo esc_url( $profile_url ); ?>" class="emp-name"><?php echo esc_html( $loan->employee_name ); ?></a>
                                    <span class="emp-code"><?php echo esc_html( $loan->employee_code ); ?></span>
                                </td>
                                <td class="hide-mobile">
                                    <a href="?page=sfs-hr-loans&action=view&id=<?php echo (int) $loan->id; ?>" style="color:#2271b1;text-decoration:none;">
                                        <?php echo esc_html( $loan->loan_number ); ?>
                                    </a>
                                </td>
                                <td><?php echo number_format( (float) $loan->principal_amount, 2 ); ?></td>
                                <td class="hide-mobile"><?php echo number_format( (float) $loan->remaining_balance, 2 ); ?></td>
                                <td class="hide-mobile">
                                    <?php echo (int) $loan->installments_count; ?> × <?php echo number_format( (float) $loan->installment_amount, 2 ); ?>
                                </td>
                                <td><?php echo $this->get_status_pill( $loan->status ); ?></td>
                                <td class="hide-mobile"><?php echo $loan->first_due_date ? esc_html( wp_date( 'M j, Y', strtotime( $loan->first_due_date ) ) ) : '—'; ?></td>
                                <td class="hide-mobile">
                                    <a href="?page=sfs-hr-loans&action=view&id=<?php echo (int) $loan->id; ?>" class="sfs-hr-action-btn" title="<?php esc_attr_e( 'View Details', 'sfs-hr' ); ?>">👁</a>
                                </td>
                                <td class="show-mobile">
                                    <button type="button" class="sfs-hr-action-btn sfs-mobile-loan-btn" title="<?php esc_attr_e( 'View Details', 'sfs-hr' ); ?>">›</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Slide-up Modal -->
        <div id="sfs-hr-loan-modal" class="sfs-hr-loan-modal">
            <div class="sfs-hr-loan-modal-overlay" onclick="closeLoanModal();"></div>
            <div class="sfs-hr-loan-modal-content">
                <div class="sfs-hr-loan-modal-header">
                    <h3><?php esc_html_e( 'Loan Details', 'sfs-hr' ); ?></h3>
                    <button type="button" class="sfs-hr-loan-modal-close" onclick="closeLoanModal();">×</button>
                </div>
                <div id="sfs-hr-loan-modal-body">
                    <!-- Content loaded dynamically -->
                </div>
                <div id="sfs-hr-loan-modal-actions" style="margin-top:20px;">
                    <!-- Actions loaded dynamically -->
                </div>
            </div>
        </div>

        <script>
        (function() {
            var modal = document.getElementById('sfs-hr-loan-modal');
            var modalBody = document.getElementById('sfs-hr-loan-modal-body');
            var modalActions = document.getElementById('sfs-hr-loan-modal-actions');

            document.querySelectorAll('.sfs-mobile-loan-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var row = this.closest('tr');
                    openLoanModal(row);
                });
            });

            function openLoanModal(row) {
                var data = row.dataset;
                var statusLabels = {
                    'pending_gm': '<?php echo esc_js( __( 'Pending GM', 'sfs-hr' ) ); ?>',
                    'pending_finance': '<?php echo esc_js( __( 'Pending Finance', 'sfs-hr' ) ); ?>',
                    'active': '<?php echo esc_js( __( 'Active', 'sfs-hr' ) ); ?>',
                    'completed': '<?php echo esc_js( __( 'Completed', 'sfs-hr' ) ); ?>',
                    'rejected': '<?php echo esc_js( __( 'Rejected', 'sfs-hr' ) ); ?>',
                    'cancelled': '<?php echo esc_js( __( 'Cancelled', 'sfs-hr' ) ); ?>'
                };

                var html = '';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Loan #', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + data.number + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Employee', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + data.employee + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Employee Code', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + data.code + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Principal', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + data.principal + ' ' + data.currency + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Remaining', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + data.remaining + ' ' + data.currency + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Installments', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + data.installments + ' × ' + data.installmentAmount + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Status', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + (statusLabels[data.status] || data.status) + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'First Due', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + (data.firstDue || '—') + '</span></div>';

                modalBody.innerHTML = html;
                modalActions.innerHTML = '<a href="?page=sfs-hr-loans&action=view&id=' + data.id + '" class="button button-primary" style="width:100%;text-align:center;"><?php echo esc_js( __( 'View Full Details', 'sfs-hr' ) ); ?></a>';

                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            window.closeLoanModal = function() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            };
        })();
        </script>
        <?php
    }

    /**
     * Output unified CSS styles for loans page
     */
    private function output_loans_styles(): void {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;
        ?>
        <style>
            /* Toolbar */
            .sfs-hr-loans-toolbar {
                background: #fff;
                border: 1px solid #e2e4e7;
                border-radius: 8px;
                padding: 16px;
                margin-bottom: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .sfs-hr-loans-toolbar form {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                align-items: center;
                margin: 0;
            }
            .sfs-hr-loans-toolbar input[type="search"] {
                height: 36px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                padding: 0 12px;
                font-size: 13px;
                min-width: 220px;
            }
            .sfs-hr-loans-toolbar .button {
                height: 36px;
                line-height: 34px;
                padding: 0 16px;
            }

            /* Status Tabs */
            .sfs-hr-loans-tabs {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 20px;
            }
            .sfs-hr-loans-tabs .sfs-tab {
                display: inline-block;
                padding: 8px 16px;
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 20px;
                font-size: 13px;
                font-weight: 500;
                color: #50575e;
                text-decoration: none;
                transition: all 0.15s ease;
            }
            .sfs-hr-loans-tabs .sfs-tab:hover {
                background: #fff;
                border-color: #2271b1;
                color: #2271b1;
            }
            .sfs-hr-loans-tabs .sfs-tab.active {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
            }
            .sfs-hr-loans-tabs .sfs-tab .count {
                display: inline-block;
                background: rgba(0,0,0,0.1);
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 11px;
                margin-left: 6px;
            }
            .sfs-hr-loans-tabs .sfs-tab.active .count {
                background: rgba(255,255,255,0.25);
            }

            /* Table Card */
            .sfs-hr-loans-table-wrap {
                background: #fff;
                border: 1px solid #e2e4e7;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .sfs-hr-loans-table-wrap .table-header {
                padding: 16px 20px;
                border-bottom: 1px solid #f0f0f1;
                background: #f9fafb;
            }
            .sfs-hr-loans-table-wrap .table-header h3 {
                margin: 0;
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
            }
            .sfs-hr-loans-table {
                width: 100%;
                border-collapse: collapse;
                margin: 0;
            }
            .sfs-hr-loans-table th {
                background: #f9fafb;
                padding: 12px 16px;
                text-align: left;
                font-weight: 600;
                font-size: 12px;
                color: #50575e;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                border-bottom: 1px solid #e2e4e7;
            }
            .sfs-hr-loans-table td {
                padding: 14px 16px;
                font-size: 13px;
                border-bottom: 1px solid #f0f0f1;
                vertical-align: middle;
            }
            .sfs-hr-loans-table tbody tr:hover {
                background: #f9fafb;
            }
            .sfs-hr-loans-table tbody tr:last-child td {
                border-bottom: none;
            }
            .sfs-hr-loans-table .emp-name {
                display: block;
                font-weight: 500;
                color: #2271b1;
                text-decoration: none;
            }
            .sfs-hr-loans-table a.emp-name:hover {
                color: #135e96;
                text-decoration: underline;
            }
            .sfs-hr-loans-table .emp-code {
                display: block;
                font-size: 11px;
                color: #787c82;
                margin-top: 2px;
            }
            .sfs-hr-loans-table .empty-state {
                text-align: center;
                padding: 40px 20px;
                color: #787c82;
            }
            .sfs-hr-loans-table .empty-state p {
                margin: 0;
                font-size: 14px;
            }

            /* Status Pills */
            .sfs-hr-pill {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 500;
                line-height: 1.4;
            }
            .sfs-hr-pill--pending-gm {
                background: #fff3e0;
                color: #e65100;
            }
            .sfs-hr-pill--pending-finance {
                background: #fff8e1;
                color: #ff8f00;
            }
            .sfs-hr-pill--active {
                background: #e8f5e9;
                color: #2e7d32;
            }
            .sfs-hr-pill--completed {
                background: #e3f2fd;
                color: #1565c0;
            }
            .sfs-hr-pill--rejected {
                background: #ffebee;
                color: #c62828;
            }
            .sfs-hr-pill--cancelled {
                background: #fafafa;
                color: #757575;
            }

            /* Payment/Installment Status Pills */
            .sfs-hr-pill--planned {
                background: #fff8e1;
                color: #ff8f00;
            }
            .sfs-hr-pill--paid {
                background: #e8f5e9;
                color: #2e7d32;
            }
            .sfs-hr-pill--partial {
                background: #fff3e0;
                color: #e65100;
            }
            .sfs-hr-pill--skipped {
                background: #fafafa;
                color: #757575;
            }

            /* Action Button */
            .sfs-hr-action-btn {
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                padding: 6px 10px;
                cursor: pointer;
                font-size: 16px;
                line-height: 1;
                transition: all 0.15s ease;
                text-decoration: none;
                display: inline-block;
            }
            .sfs-hr-action-btn:hover {
                background: #fff;
                border-color: #2271b1;
            }

            /* Mobile Modal */
            .sfs-hr-loan-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 100000;
            }
            .sfs-hr-loan-modal.active {
                display: block;
            }
            .sfs-hr-loan-modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
            }
            .sfs-hr-loan-modal-content {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                background: #fff;
                border-radius: 16px 16px 0 0;
                padding: 24px;
                max-height: 85vh;
                overflow-y: auto;
                transform: translateY(100%);
                transition: transform 0.3s ease;
            }
            .sfs-hr-loan-modal.active .sfs-hr-loan-modal-content {
                transform: translateY(0);
            }
            .sfs-hr-loan-modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 16px;
                border-bottom: 1px solid #e2e4e7;
            }
            .sfs-hr-loan-modal-header h3 {
                margin: 0;
                font-size: 18px;
                color: #1d2327;
            }
            .sfs-hr-loan-modal-close {
                background: #f6f7f7;
                border: none;
                width: 32px;
                height: 32px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 18px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .sfs-hr-loan-modal-row {
                display: flex;
                justify-content: space-between;
                padding: 12px 0;
                border-bottom: 1px solid #f0f0f1;
            }
            .sfs-hr-loan-modal-row:last-child {
                border-bottom: none;
            }
            .sfs-hr-loan-modal-label {
                color: #50575e;
                font-size: 13px;
            }
            .sfs-hr-loan-modal-value {
                font-weight: 500;
                color: #1d2327;
                font-size: 13px;
                text-align: right;
            }

            /* Mobile Responsive */
            @media screen and (max-width: 782px) {
                .sfs-hr-loans-toolbar form {
                    flex-direction: column;
                    align-items: stretch;
                }
                .sfs-hr-loans-toolbar input[type="search"] {
                    width: 100%;
                    min-width: auto;
                }
                .sfs-hr-loans-toolbar .button {
                    width: 100%;
                    text-align: center;
                }
                .sfs-hr-loans-tabs {
                    overflow-x: auto;
                    flex-wrap: nowrap;
                    padding-bottom: 8px;
                    -webkit-overflow-scrolling: touch;
                }
                .sfs-hr-loans-tabs .sfs-tab {
                    flex-shrink: 0;
                    padding: 6px 12px;
                    font-size: 12px;
                }
                .hide-mobile {
                    display: none !important;
                }
                .sfs-hr-loans-table th,
                .sfs-hr-loans-table td {
                    padding: 12px;
                }
                .show-mobile {
                    display: table-cell !important;
                }
            }
            @media screen and (min-width: 783px) {
                .show-mobile {
                    display: none !important;
                }
            }
        </style>
        <?php
    }

    /**
     * Get status pill HTML (unified design)
     */
    private function get_status_pill( string $status ): string {
        $labels = [
            'pending_gm'      => __( 'Pending GM', 'sfs-hr' ),
            'pending_finance' => __( 'Pending Finance', 'sfs-hr' ),
            'active'          => __( 'Active', 'sfs-hr' ),
            'completed'       => __( 'Completed', 'sfs-hr' ),
            'rejected'        => __( 'Rejected', 'sfs-hr' ),
            'cancelled'       => __( 'Cancelled', 'sfs-hr' ),
        ];

        $label = $labels[ $status ] ?? ucfirst( $status );
        // Convert underscores to hyphens for CSS class (pending_gm -> pending-gm)
        $status_class = str_replace( '_', '-', $status );
        $class = 'sfs-hr-pill sfs-hr-pill--' . esc_attr( $status_class );

        return '<span class="' . $class . '">' . esc_html( $label ) . '</span>';
    }

    /**
     * Render create loan form
     */
    private function render_create_loan_form(): void {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        // Get all active employees
        $employees = $wpdb->get_results(
            "SELECT e.id, e.employee_code, e.first_name, e.last_name,
                    COALESCE(d.name, 'N/A') as department
             FROM {$emp_table} e
             LEFT JOIN {$dept_table} d ON e.dept_id = d.id
             WHERE e.status = 'active'
             ORDER BY e.first_name, e.last_name"
        );

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Create New Loan', 'sfs-hr' ); ?>
                <a href="?page=sfs-hr-loans" class="page-title-action"><?php esc_html_e( '← Back to List', 'sfs-hr' ); ?></a>
            </h1>

            <?php if ( isset( $_GET['error'] ) ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html( urldecode( $_GET['error'] ) ); ?></p>
                </div>
            <?php endif; ?>

            <div style="background:#fff;padding:20px;border:1px solid #ccc;border-radius:4px;margin-top:20px;max-width:800px;">
                <form method="post" action="">
                    <?php wp_nonce_field( 'sfs_hr_loan_create' ); ?>
                    <input type="hidden" name="action" value="create_loan" />

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="employee_id"><?php esc_html_e( 'Employee', 'sfs-hr' ); ?> <span style="color:red;">*</span></label>
                            </th>
                            <td>
                                <select name="employee_id" id="employee_id" required style="min-width:300px;">
                                    <option value=""><?php esc_html_e( '— Select Employee —', 'sfs-hr' ); ?></option>
                                    <?php foreach ( $employees as $emp ) : ?>
                                        <option value="<?php echo (int) $emp->id; ?>">
                                            <?php echo esc_html( $emp->first_name . ' ' . $emp->last_name ); ?>
                                            (<?php echo esc_html( $emp->employee_code ); ?>) -
                                            <?php echo esc_html( $emp->department ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Select the employee requesting the loan.', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="principal_amount"><?php esc_html_e( 'Principal Amount', 'sfs-hr' ); ?> <span style="color:red;">*</span></label>
                            </th>
                            <td>
                                <input type="number" name="principal_amount" id="principal_amount"
                                       step="0.01" min="1" required style="width:200px;" />
                                <span style="margin-left:5px;">SAR</span>
                                <p class="description"><?php esc_html_e( 'The total loan amount requested.', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="installments_count"><?php esc_html_e( 'Number of Installments', 'sfs-hr' ); ?> <span style="color:red;">*</span></label>
                            </th>
                            <td>
                                <input type="number" name="installments_count" id="installments_count"
                                       min="1" max="60" required style="width:100px;" />
                                <p class="description"><?php esc_html_e( 'Number of monthly installments (1-60 months).', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="reason"><?php esc_html_e( 'Reason for Loan', 'sfs-hr' ); ?> <span style="color:red;">*</span></label>
                            </th>
                            <td>
                                <textarea name="reason" id="reason" rows="4" required
                                          style="width:100%;max-width:500px;"></textarea>
                                <p class="description"><?php esc_html_e( 'Employee\'s reason for requesting the loan.', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="internal_notes"><?php esc_html_e( 'Internal Notes', 'sfs-hr' ); ?></label>
                            </th>
                            <td>
                                <textarea name="internal_notes" id="internal_notes" rows="3"
                                          style="width:100%;max-width:500px;"></textarea>
                                <p class="description"><?php esc_html_e( 'Optional internal notes (not visible to employee).', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Create Loan Request', 'sfs-hr' ); ?>
                        </button>
                        <a href="?page=sfs-hr-loans" class="button">
                            <?php esc_html_e( 'Cancel', 'sfs-hr' ); ?>
                        </a>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render monthly installments tab (Finance only)
     */
    private function render_installments_tab(): void {
        global $wpdb;
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Output shared styles (reuse from loans tab)
        $this->output_loans_styles();

        // Get selected month (default to current month)
        $selected_month = isset( $_GET['month'] ) ? sanitize_text_field( $_GET['month'] ) : wp_date( 'Y-m' );

        // Get status filter
        $current_status = isset( $_GET['filter_status'] ) ? sanitize_key( $_GET['filter_status'] ) : '';

        // Calculate month range
        $month_start = $selected_month . '-01';
        $month_end = wp_date( 'Y-m-t', strtotime( $month_start ) );

        // Get counts for status tabs
        $status_tabs = [
            ''        => __( 'All', 'sfs-hr' ),
            'planned' => __( 'Planned', 'sfs-hr' ),
            'paid'    => __( 'Paid', 'sfs-hr' ),
            'partial' => __( 'Partial', 'sfs-hr' ),
            'skipped' => __( 'Skipped', 'sfs-hr' ),
        ];

        $counts = [];
        foreach ( array_keys( $status_tabs ) as $st ) {
            if ( $st === '' ) {
                $count_sql = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$payments_table} p
                     INNER JOIN {$loans_table} l ON p.loan_id = l.id
                     WHERE p.due_date >= %s AND p.due_date <= %s AND l.status = 'active'",
                    $month_start, $month_end
                );
            } else {
                $count_sql = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$payments_table} p
                     INNER JOIN {$loans_table} l ON p.loan_id = l.id
                     WHERE p.due_date >= %s AND p.due_date <= %s AND l.status = 'active' AND p.status = %s",
                    $month_start, $month_end, $st
                );
            }
            $counts[ $st ] = (int) $wpdb->get_var( $count_sql );
        }

        // Query installments for selected month with status filter
        $where_status = $current_status ? $wpdb->prepare( ' AND p.status = %s', $current_status ) : '';
        $installments = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, l.loan_number, l.currency, l.employee_id, l.remaining_balance,
                    CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                    e.employee_code
             FROM {$payments_table} p
             INNER JOIN {$loans_table} l ON p.loan_id = l.id
             LEFT JOIN {$emp_table} e ON l.employee_id = e.id
             WHERE p.due_date >= %s
             AND p.due_date <= %s
             AND l.status = 'active'
             {$where_status}
             ORDER BY p.due_date ASC, e.first_name ASC",
            $month_start,
            $month_end
        ) );

        // Calculate totals
        $total_planned = 0;
        $total_paid = 0;

        foreach ( $installments as $inst ) {
            $total_planned += (float) $inst->amount_planned;
            $total_paid += (float) $inst->amount_paid;
        }

        $total = count( $installments );

        ?>
        <!-- Toolbar -->
        <div class="sfs-hr-loans-toolbar">
            <form method="get" style="margin:0;">
                <input type="hidden" name="page" value="sfs-hr-loans" />
                <input type="hidden" name="tab" value="installments" />
                <input type="hidden" name="filter_status" value="<?php echo esc_attr( $current_status ); ?>" />
                <label for="month-selector" style="margin-right:10px;font-weight:600;">
                    <?php esc_html_e( 'Select Month:', 'sfs-hr' ); ?>
                </label>
                <input type="month" id="month-selector" name="month" value="<?php echo esc_attr( $selected_month ); ?>"
                       onchange="this.form.submit();"
                       style="height:36px;border:1px solid #dcdcde;border-radius:4px;padding:0 12px;" />
                <a href="?page=sfs-hr-loans&tab=installments&action=export_csv&month=<?php echo esc_attr( $selected_month ); ?>"
                   class="button" style="margin-left:auto;">
                    <?php esc_html_e( 'Export CSV', 'sfs-hr' ); ?>
                </a>
            </form>
        </div>

        <!-- Summary Cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;">
            <div style="padding:16px;background:#fff;border:1px solid #e2e4e7;border-radius:8px;border-left:4px solid #0ea5e9;">
                <div style="font-size:11px;color:#50575e;text-transform:uppercase;letter-spacing:0.3px;"><?php esc_html_e( 'Total Planned', 'sfs-hr' ); ?></div>
                <div style="font-size:20px;font-weight:600;margin-top:6px;color:#1d2327;">
                    <?php echo number_format( $total_planned, 2 ); ?> <span style="font-size:12px;color:#50575e;">SAR</span>
                </div>
            </div>
            <div style="padding:16px;background:#fff;border:1px solid #e2e4e7;border-radius:8px;border-left:4px solid #22c55e;">
                <div style="font-size:11px;color:#50575e;text-transform:uppercase;letter-spacing:0.3px;"><?php esc_html_e( 'Total Paid', 'sfs-hr' ); ?></div>
                <div style="font-size:20px;font-weight:600;margin-top:6px;color:#1d2327;">
                    <?php echo number_format( $total_paid, 2 ); ?> <span style="font-size:12px;color:#50575e;">SAR</span>
                </div>
            </div>
            <div style="padding:16px;background:#fff;border:1px solid #e2e4e7;border-radius:8px;border-left:4px solid #eab308;">
                <div style="font-size:11px;color:#50575e;text-transform:uppercase;letter-spacing:0.3px;"><?php esc_html_e( 'Pending', 'sfs-hr' ); ?></div>
                <div style="font-size:20px;font-weight:600;margin-top:6px;color:#1d2327;"><?php echo (int) $counts['planned']; ?></div>
            </div>
            <div style="padding:16px;background:#fff;border:1px solid #e2e4e7;border-radius:8px;border-left:4px solid #22c55e;">
                <div style="font-size:11px;color:#50575e;text-transform:uppercase;letter-spacing:0.3px;"><?php esc_html_e( 'Paid', 'sfs-hr' ); ?></div>
                <div style="font-size:20px;font-weight:600;margin-top:6px;color:#1d2327;"><?php echo (int) $counts['paid']; ?></div>
            </div>
            <div style="padding:16px;background:#fff;border:1px solid #e2e4e7;border-radius:8px;border-left:4px solid #ef4444;">
                <div style="font-size:11px;color:#50575e;text-transform:uppercase;letter-spacing:0.3px;"><?php esc_html_e( 'Partial', 'sfs-hr' ); ?></div>
                <div style="font-size:20px;font-weight:600;margin-top:6px;color:#1d2327;"><?php echo (int) $counts['partial']; ?></div>
            </div>
            <div style="padding:16px;background:#fff;border:1px solid #e2e4e7;border-radius:8px;border-left:4px solid #6b7280;">
                <div style="font-size:11px;color:#50575e;text-transform:uppercase;letter-spacing:0.3px;"><?php esc_html_e( 'Skipped', 'sfs-hr' ); ?></div>
                <div style="font-size:20px;font-weight:600;margin-top:6px;color:#1d2327;"><?php echo (int) $counts['skipped']; ?></div>
            </div>
        </div>

        <!-- Status Tabs -->
        <div class="sfs-hr-loans-tabs">
            <?php foreach ( $status_tabs as $key => $label ) : ?>
                <?php
                $url = add_query_arg( [
                    'page'          => 'sfs-hr-loans',
                    'tab'           => 'installments',
                    'month'         => $selected_month,
                    'filter_status' => $key !== '' ? $key : null,
                ], admin_url( 'admin.php' ) );
                $classes = 'sfs-tab' . ( $key === $current_status ? ' active' : '' );
                ?>
                <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $classes ); ?>">
                    <?php echo esc_html( $label ); ?>
                    <span class="count"><?php echo esc_html( $counts[ $key ] ); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Table Card -->
        <div class="sfs-hr-loans-table-wrap">
            <div class="table-header">
                <h3><?php echo esc_html( $status_tabs[ $current_status ] ?? __( 'All Installments', 'sfs-hr' ) ); ?> (<?php echo esc_html( $total ); ?>)</h3>
            </div>

            <table class="sfs-hr-loans-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                        <th class="hide-mobile"><?php esc_html_e( 'Loan #', 'sfs-hr' ); ?></th>
                        <th class="hide-mobile"><?php esc_html_e( 'Inst. #', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Amount', 'sfs-hr' ); ?></th>
                        <th class="hide-mobile"><?php esc_html_e( 'Due Date', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                        <th class="hide-mobile" style="width:50px;"></th>
                        <th class="show-mobile" style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $installments ) ) : ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                <p><?php esc_html_e( 'No installments found for the selected month.', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $installments as $inst ) : ?>
                            <tr class="sfs-hr-inst-row"
                                data-id="<?php echo (int) $inst->id; ?>"
                                data-employee="<?php echo esc_attr( $inst->employee_name ); ?>"
                                data-employee-id="<?php echo (int) $inst->employee_id; ?>"
                                data-code="<?php echo esc_attr( $inst->employee_code ); ?>"
                                data-loan="<?php echo esc_attr( $inst->loan_number ); ?>"
                                data-loan-id="<?php echo (int) $inst->loan_id; ?>"
                                data-sequence="<?php echo (int) $inst->sequence; ?>"
                                data-due="<?php echo esc_attr( wp_date( 'M j, Y', strtotime( $inst->due_date ) ) ); ?>"
                                data-planned="<?php echo number_format( (float) $inst->amount_planned, 2 ); ?>"
                                data-paid="<?php echo number_format( (float) $inst->amount_paid, 2 ); ?>"
                                data-status="<?php echo esc_attr( $inst->status ); ?>"
                                data-remaining="<?php echo number_format( (float) $inst->remaining_balance, 2 ); ?>"
                                data-currency="<?php echo esc_attr( $inst->currency ); ?>"
                                data-max="<?php echo esc_attr( $inst->amount_planned ); ?>"
                                data-nonce="<?php echo wp_create_nonce( 'sfs_hr_mark_installment_' . $inst->id ); ?>">
                                <td>
                                    <?php $profile_url = admin_url( 'admin.php?page=sfs-hr-employee-profile&employee_id=' . (int) $inst->employee_id ); ?>
                                    <a href="<?php echo esc_url( $profile_url ); ?>" class="emp-name"><?php echo esc_html( $inst->employee_name ); ?></a>
                                    <span class="emp-code"><?php echo esc_html( $inst->employee_code ); ?></span>
                                </td>
                                <td class="hide-mobile">
                                    <a href="?page=sfs-hr-loans&action=view&id=<?php echo (int) $inst->loan_id; ?>" style="color:#2271b1;text-decoration:none;">
                                        <?php echo esc_html( $inst->loan_number ); ?>
                                    </a>
                                </td>
                                <td class="hide-mobile"><?php echo (int) $inst->sequence; ?></td>
                                <td><?php echo number_format( (float) $inst->amount_planned, 2 ); ?></td>
                                <td class="hide-mobile"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $inst->due_date ) ) ); ?></td>
                                <td><?php echo $this->get_payment_status_badge( $inst->status ); ?></td>
                                <td class="hide-mobile">
                                    <button type="button" class="sfs-hr-action-btn sfs-inst-view-btn" title="<?php esc_attr_e( 'View Details', 'sfs-hr' ); ?>">👁</button>
                                </td>
                                <td class="show-mobile">
                                    <button type="button" class="sfs-hr-action-btn sfs-inst-view-btn" title="<?php esc_attr_e( 'View Details', 'sfs-hr' ); ?>">›</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Installment Detail Modal -->
        <div id="sfs-hr-inst-modal" class="sfs-hr-loan-modal">
            <div class="sfs-hr-loan-modal-overlay" onclick="closeInstModal();"></div>
            <div class="sfs-hr-loan-modal-content">
                <div class="sfs-hr-loan-modal-header">
                    <h3><?php esc_html_e( 'Installment Details', 'sfs-hr' ); ?></h3>
                    <button type="button" class="sfs-hr-loan-modal-close" onclick="closeInstModal();">×</button>
                </div>
                <div id="sfs-hr-inst-modal-body">
                    <!-- Content loaded dynamically -->
                </div>
                <div id="sfs-hr-inst-modal-actions" style="margin-top:20px;display:flex;gap:10px;">
                    <!-- Actions loaded dynamically -->
                </div>
                <div id="sfs-hr-inst-modal-partial" style="margin-top:16px;padding:16px;background:#f9fafb;border-radius:8px;display:none;">
                    <form method="post" action="" id="sfs-hr-inst-partial-form">
                        <input type="hidden" name="action" value="mark_installment_partial" />
                        <input type="hidden" name="payment_id" id="sfs-hr-inst-partial-id" value="" />
                        <input type="hidden" name="month" value="<?php echo esc_attr( $selected_month ); ?>" />
                        <input type="hidden" name="_wpnonce" id="sfs-hr-inst-partial-nonce" value="" />
                        <label style="display:block;margin-bottom:8px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'Enter partial payment amount:', 'sfs-hr' ); ?></label>
                        <input type="number" name="partial_amount" id="sfs-hr-inst-partial-amount" step="0.01" min="0.01" required style="width:100%;padding:10px 12px;border:1px solid #dcdcde;border-radius:6px;margin-bottom:12px;" />
                        <div style="display:flex;gap:10px;">
                            <button type="submit" class="button button-primary" style="flex:1;"><?php esc_html_e( 'Submit', 'sfs-hr' ); ?></button>
                            <button type="button" class="button" style="flex:1;" onclick="hideModalPartial();"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var modal = document.getElementById('sfs-hr-inst-modal');
            var modalBody = document.getElementById('sfs-hr-inst-modal-body');
            var modalActions = document.getElementById('sfs-hr-inst-modal-actions');
            var currentInstData = null;

            var statusLabels = {
                'planned': '<?php echo esc_js( __( 'Planned', 'sfs-hr' ) ); ?>',
                'paid': '<?php echo esc_js( __( 'Paid', 'sfs-hr' ) ); ?>',
                'partial': '<?php echo esc_js( __( 'Partial', 'sfs-hr' ) ); ?>',
                'skipped': '<?php echo esc_js( __( 'Skipped', 'sfs-hr' ) ); ?>'
            };

            // Attach click events to view buttons
            document.querySelectorAll('.sfs-inst-view-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var row = this.closest('tr');
                    if (row) openInstModal(row);
                });
            });

            function openInstModal(row) {
                var data = row.dataset;
                currentInstData = {
                    id: data.id,
                    employee: data.employee,
                    employeeId: data.employeeId,
                    code: data.code,
                    loan: data.loan,
                    loanId: data.loanId,
                    sequence: data.sequence,
                    due: data.due,
                    planned: data.planned,
                    paid: data.paid,
                    status: data.status,
                    remaining: data.remaining,
                    currency: data.currency,
                    max: data.max,
                    nonce: data.nonce
                };

                var html = '';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Employee', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + currentInstData.employee + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Employee Code', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + currentInstData.code + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Loan #', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + currentInstData.loan + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Installment', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">#' + currentInstData.sequence + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Due Date', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + currentInstData.due + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Amount Planned', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + currentInstData.planned + ' ' + currentInstData.currency + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Amount Paid', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + currentInstData.paid + ' ' + currentInstData.currency + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Status', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + (statusLabels[currentInstData.status] || currentInstData.status) + '</span></div>';
                html += '<div class="sfs-hr-loan-modal-row"><span class="sfs-hr-loan-modal-label"><?php echo esc_js( __( 'Remaining Loan', 'sfs-hr' ) ); ?></span><span class="sfs-hr-loan-modal-value">' + currentInstData.remaining + ' ' + currentInstData.currency + '</span></div>';

                modalBody.innerHTML = html;

                // Actions based on status
                if (currentInstData.status === 'planned' || currentInstData.status === 'partial') {
                    var actionsHtml = '';
                    actionsHtml += '<form method="post" action="" style="flex:1;"><input type="hidden" name="_wpnonce" value="' + currentInstData.nonce + '" /><input type="hidden" name="action" value="mark_installment_paid" /><input type="hidden" name="payment_id" value="' + currentInstData.id + '" /><input type="hidden" name="month" value="<?php echo esc_attr( $selected_month ); ?>" /><button type="submit" class="button button-primary" style="width:100%;"><?php echo esc_js( __( 'Mark Paid', 'sfs-hr' ) ); ?></button></form>';
                    actionsHtml += '<button type="button" class="button" style="flex:1;" onclick="showModalPartial();"><?php echo esc_js( __( 'Partial', 'sfs-hr' ) ); ?></button>';
                    actionsHtml += '<form method="post" action="" style="flex:1;" onsubmit="return confirm(\'<?php echo esc_js( __( 'Skip this payment?', 'sfs-hr' ) ); ?>\');"><input type="hidden" name="_wpnonce" value="' + currentInstData.nonce + '" /><input type="hidden" name="action" value="mark_installment_skipped" /><input type="hidden" name="payment_id" value="' + currentInstData.id + '" /><input type="hidden" name="month" value="<?php echo esc_attr( $selected_month ); ?>" /><button type="submit" class="button" style="width:100%;"><?php echo esc_js( __( 'Skip', 'sfs-hr' ) ); ?></button></form>';
                    modalActions.innerHTML = actionsHtml;
                    modalActions.style.display = 'flex';
                } else {
                    modalActions.innerHTML = '<a href="?page=sfs-hr-loans&action=view&id=' + currentInstData.loanId + '" class="button button-primary" style="width:100%;text-align:center;"><?php echo esc_js( __( 'View Loan Details', 'sfs-hr' ) ); ?></a>';
                    modalActions.style.display = 'block';
                }

                // Reset partial form
                hideModalPartial();

                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            window.closeInstModal = function() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
                currentInstData = null;
            };

            window.showModalPartial = function() {
                if (!currentInstData) return;
                document.getElementById('sfs-hr-inst-partial-id').value = currentInstData.id;
                document.getElementById('sfs-hr-inst-partial-nonce').value = currentInstData.nonce;
                document.getElementById('sfs-hr-inst-partial-amount').max = currentInstData.max;
                document.getElementById('sfs-hr-inst-modal-partial').style.display = 'block';
            };

            window.hideModalPartial = function() {
                document.getElementById('sfs-hr-inst-modal-partial').style.display = 'none';
                document.getElementById('sfs-hr-inst-partial-amount').value = '';
            };
        })();
        </script>
        <?php
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
                    <th><?php esc_html_e( 'GM Approvers', 'sfs-hr' ); ?></th>
                    <td>
                        <?php
                        // Get all users with GM approval capability
                        $all_users = get_users( [
                            'role__in' => [ 'administrator', 'sfs_hr_gm_approver' ],
                            'orderby'  => 'display_name',
                        ] );

                        // Also get users with sfs_hr.manage capability
                        $manage_users = get_users( [
                            'meta_query' => [
                                [
                                    'key'     => 'wp_capabilities',
                                    'value'   => 'sfs_hr.manage',
                                    'compare' => 'LIKE',
                                ],
                            ],
                        ] );

                        // Get users with GM approval capability (custom role)
                        $gm_users = get_users( [
                            'meta_query' => [
                                [
                                    'key'     => 'wp_capabilities',
                                    'value'   => 'sfs_hr_loans_gm_approve',
                                    'compare' => 'LIKE',
                                ],
                            ],
                        ] );

                        $available_users = array_merge( $all_users, $manage_users, $gm_users );
                        $available_users = array_unique( $available_users, SORT_REGULAR );

                        $gm_user_ids = $settings['gm_user_ids'] ?? [];
                        ?>

                        <select name="gm_user_ids[]" multiple style="min-width:400px;height:150px;">
                            <?php foreach ( $available_users as $user ) : ?>
                                <option value="<?php echo (int) $user->ID; ?>" <?php selected( in_array( $user->ID, $gm_user_ids, true ), true ); ?>>
                                    <?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Select users who can approve loans at the GM stage. Hold Ctrl/Cmd to select multiple. Leave empty to allow any user with sfs_hr.manage capability.', 'sfs-hr' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Finance Approvers', 'sfs-hr' ); ?></th>
                    <td>
                        <?php
                        // Get all users with Finance approval capability
                        $finance_all = get_users( [
                            'role__in' => [ 'administrator', 'sfs_hr_finance_approver' ],
                            'orderby'  => 'display_name',
                        ] );

                        // Get users with finance approval capability (custom capability)
                        $finance_cap_users = get_users( [
                            'meta_query' => [
                                [
                                    'key'     => 'wp_capabilities',
                                    'value'   => 'sfs_hr_loans_finance_approve',
                                    'compare' => 'LIKE',
                                ],
                            ],
                        ] );

                        $available_finance_users = array_merge( $finance_all, $manage_users, $finance_cap_users );
                        $available_finance_users = array_unique( $available_finance_users, SORT_REGULAR );

                        $finance_user_ids = $settings['finance_user_ids'] ?? [];
                        ?>

                        <select name="finance_user_ids[]" multiple style="min-width:400px;height:150px;">
                            <?php foreach ( $available_finance_users as $user ) : ?>
                                <option value="<?php echo (int) $user->ID; ?>" <?php selected( in_array( $user->ID, $finance_user_ids, true ), true ); ?>>
                                    <?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Select users who can approve loans at the Finance stage. Hold Ctrl/Cmd to select multiple. Leave empty to allow any user with sfs_hr.manage capability.', 'sfs-hr' ); ?>
                        </p>
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
                <tr>
                    <th><?php esc_html_e( 'Email Notifications', 'sfs-hr' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_notifications" value="1" <?php checked( $settings['enable_notifications'] ?? true, true ); ?> />
                            <strong><?php esc_html_e( 'Enable email notifications', 'sfs-hr' ); ?></strong>
                        </label>
                        <p class="description"><?php esc_html_e( 'Master switch for all loan-related email notifications. Uncheck to disable all emails.', 'sfs-hr' ); ?></p>

                        <div style="margin-top:15px;margin-left:25px;">
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="notify_gm_new_request" value="1" <?php checked( $settings['notify_gm_new_request'] ?? true, true ); ?> />
                                <?php esc_html_e( 'Notify GM when new loan request is submitted', 'sfs-hr' ); ?>
                            </label>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="notify_finance_gm_approved" value="1" <?php checked( $settings['notify_finance_gm_approved'] ?? true, true ); ?> />
                                <?php esc_html_e( 'Notify Finance when GM approves loan', 'sfs-hr' ); ?>
                            </label>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="notify_employee_approved" value="1" <?php checked( $settings['notify_employee_approved'] ?? true, true ); ?> />
                                <?php esc_html_e( 'Notify employee when loan is approved', 'sfs-hr' ); ?>
                            </label>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="notify_employee_rejected" value="1" <?php checked( $settings['notify_employee_rejected'] ?? true, true ); ?> />
                                <?php esc_html_e( 'Notify employee when loan is rejected', 'sfs-hr' ); ?>
                            </label>
                            <label style="display:block;margin-bottom:8px;">
                                <input type="checkbox" name="notify_employee_installment_skipped" value="1" <?php checked( $settings['notify_employee_installment_skipped'] ?? true, true ); ?> />
                                <?php esc_html_e( 'Notify employee when installment is skipped', 'sfs-hr' ); ?>
                            </label>
                        </div>
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

        // Process GM user IDs
        $gm_user_ids = [];
        if ( isset( $_POST['gm_user_ids'] ) && is_array( $_POST['gm_user_ids'] ) ) {
            $gm_user_ids = array_map( 'intval', $_POST['gm_user_ids'] );
        }

        // Process Finance user IDs
        $finance_user_ids = [];
        if ( isset( $_POST['finance_user_ids'] ) && is_array( $_POST['finance_user_ids'] ) ) {
            $finance_user_ids = array_map( 'intval', $_POST['finance_user_ids'] );
        }

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
            'gm_user_ids'                      => $gm_user_ids,
            'require_finance_approval'         => true, // Always required
            'finance_user_ids'                 => $finance_user_ids,
            'allow_early_repayment'            => isset( $_POST['allow_early_repayment'] ),
            'early_repayment_requires_approval'=> isset( $_POST['early_repayment_requires_approval'] ),
            'show_in_my_profile'               => isset( $_POST['show_in_my_profile'] ),
            'allow_employee_requests'          => isset( $_POST['allow_employee_requests'] ),
            // Email notifications
            'enable_notifications'             => isset( $_POST['enable_notifications'] ),
            'notify_gm_new_request'            => isset( $_POST['notify_gm_new_request'] ),
            'notify_finance_gm_approved'       => isset( $_POST['notify_finance_gm_approved'] ),
            'notify_employee_approved'         => isset( $_POST['notify_employee_approved'] ),
            'notify_employee_rejected'         => isset( $_POST['notify_employee_rejected'] ),
            'notify_employee_installment_skipped' => isset( $_POST['notify_employee_installment_skipped'] ),
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

    /**
     * Get payment status badge HTML
     */
    private function get_payment_status_badge( string $status ): string {
        $labels = [
            'planned'  => __( 'Planned', 'sfs-hr' ),
            'paid'     => __( 'Paid', 'sfs-hr' ),
            'partial'  => __( 'Partial', 'sfs-hr' ),
            'skipped'  => __( 'Skipped', 'sfs-hr' ),
        ];

        $label = $labels[ $status ] ?? ucfirst( $status );
        $class = 'sfs-hr-pill sfs-hr-pill--' . esc_attr( $status );

        return '<span class="' . $class . '">' . esc_html( $label ) . '</span>';
    }

    /**
     * Render loan detail page
     */
    private function render_loan_detail( int $loan_id ): void {
        global $wpdb;
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $history_table = $wpdb->prefix . 'sfs_hr_loan_history';
        $payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';

        $loan = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.*, CONCAT(e.first_name, ' ', e.last_name) as employee_name, e.employee_code,
                    COALESCE(d.name, l.department, 'N/A') as department
             FROM {$loans_table} l
             LEFT JOIN {$emp_table} e ON l.employee_id = e.id
             LEFT JOIN {$dept_table} d ON e.dept_id = d.id
             WHERE l.id = %d",
            $loan_id
        ) );

        if ( ! $loan ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Loan not found', 'sfs-hr' ) . '</h1></div>';
            return;
        }

        // Get history
        $history = $wpdb->get_results( $wpdb->prepare(
            "SELECT h.*, u.display_name as user_name
             FROM {$history_table} h
             LEFT JOIN {$wpdb->users} u ON h.user_id = u.ID
             WHERE h.loan_id = %d
             ORDER BY h.created_at DESC",
            $loan_id
        ) );

        // Get payments schedule
        $payments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$payments_table}
             WHERE loan_id = %d
             ORDER BY sequence ASC",
            $loan_id
        ) );

        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html( $loan->loan_number ); ?>
                <a href="?page=sfs-hr-loans" class="page-title-action"><?php esc_html_e( '← Back to List', 'sfs-hr' ); ?></a>
            </h1>

            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Loan updated successfully.', 'sfs-hr' ); ?></p>
                </div>
            <?php endif; ?>

            <style>
                .sfs-loan-detail-grid {
                    display: flex;
                    gap: 20px;
                    margin-top: 20px;
                }
                .sfs-loan-detail-main {
                    flex: 2;
                    min-width: 0;
                }
                .sfs-loan-detail-sidebar {
                    flex: 1;
                    min-width: 300px;
                }
                @media (max-width: 1200px) {
                    .sfs-loan-detail-grid {
                        flex-direction: column;
                    }
                    .sfs-loan-detail-sidebar {
                        min-width: 100%;
                    }
                }
            </style>

            <div class="sfs-loan-detail-grid">
                <div class="sfs-loan-detail-main">
                    <div style="background:#fff;padding:20px;border:1px solid #ccc;border-radius:4px;">
                        <h2><?php esc_html_e( 'Loan Information', 'sfs-hr' ); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Loan Number', 'sfs-hr' ); ?></th>
                        <td><strong><?php echo esc_html( $loan->loan_number ); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                        <td>
                            <a href="?page=sfs-hr-employee-profile&employee_id=<?php echo (int) $loan->employee_id; ?>" style="text-decoration:none;">
                                <strong><?php echo esc_html( $loan->employee_name ); ?></strong>
                            </a>
                            <br><small><?php echo esc_html( $loan->employee_code ); ?> - <?php echo esc_html( $loan->department ); ?></small>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                        <td><?php echo $this->get_status_badge( $loan->status ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Principal Amount', 'sfs-hr' ); ?></th>
                        <td><strong><?php echo number_format( (float) $loan->principal_amount, 2 ); ?> <?php echo esc_html( $loan->currency ); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Remaining Balance', 'sfs-hr' ); ?></th>
                        <td><strong><?php echo number_format( (float) $loan->remaining_balance, 2 ); ?> <?php echo esc_html( $loan->currency ); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Installments', 'sfs-hr' ); ?></th>
                        <td>
                            <?php echo (int) $loan->installments_count; ?> installments ×
                            <?php echo number_format( (float) $loan->installment_amount, 2 ); ?> <?php echo esc_html( $loan->currency ); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'First Due Date', 'sfs-hr' ); ?></th>
                        <td><?php echo $loan->first_due_date ? esc_html( wp_date( 'F j, Y', strtotime( $loan->first_due_date ) ) ) : '—'; ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Last Due Date', 'sfs-hr' ); ?></th>
                        <td><?php echo $loan->last_due_date ? esc_html( wp_date( 'F j, Y', strtotime( $loan->last_due_date ) ) ) : '—'; ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Reason', 'sfs-hr' ); ?></th>
                        <td><?php echo esc_html( $loan->reason ); ?></td>
                    </tr>
                    <?php if ( $loan->internal_notes ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'Internal Notes', 'sfs-hr' ); ?></th>
                            <td><?php echo nl2br( esc_html( $loan->internal_notes ) ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e( 'Created', 'sfs-hr' ); ?></th>
                        <td><?php echo esc_html( wp_date( 'F j, Y g:i a', strtotime( $loan->created_at ) ) ); ?></td>
                    </tr>
                </table>

                <!-- Approval Actions -->
                <hr style="margin: 30px 0;">

                <?php if ( $loan->status === 'pending_gm' ) : ?>
                    <h3><?php esc_html_e( 'GM Approval', 'sfs-hr' ); ?></h3>

                    <?php if ( \SFS\HR\Modules\Loans\LoansModule::current_user_can_approve_as_gm() ) : ?>
                        <form method="post" action="" style="margin-bottom:20px;">
                            <?php wp_nonce_field( 'sfs_hr_loan_approve_gm_' . $loan_id ); ?>
                            <input type="hidden" name="action" value="approve_gm" />
                            <input type="hidden" name="loan_id" value="<?php echo (int) $loan_id; ?>" />

                            <table class="form-table" style="margin-bottom:15px;">
                                <tr>
                                    <th style="width:180px;"><?php esc_html_e( 'Approved Amount', 'sfs-hr' ); ?></th>
                                    <td>
                                        <input type="number" name="approved_gm_amount" value="<?php echo esc_attr( $loan->principal_amount ); ?>" step="0.01" min="0" style="width:150px;" />
                                        <p class="description"><?php esc_html_e( 'You can approve a different amount than requested.', 'sfs-hr' ); ?></p>
                                        <p class="description"><?php printf( esc_html__( 'Requested amount: %s', 'sfs-hr' ), number_format( $loan->principal_amount, 2 ) ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Approval Note', 'sfs-hr' ); ?></th>
                                    <td>
                                        <textarea name="approved_gm_note" rows="2" style="width:400px;" placeholder="<?php esc_attr_e( 'Optional note for this approval', 'sfs-hr' ); ?>"></textarea>
                                    </td>
                                </tr>
                            </table>

                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Approve (GM)', 'sfs-hr' ); ?></button>
                        </form>

                        <hr style="margin:20px 0;">
                        <form method="post" action="" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to reject this loan?', 'sfs-hr' ); ?>');">
                            <?php wp_nonce_field( 'sfs_hr_loan_reject_' . $loan_id ); ?>
                            <input type="hidden" name="action" value="reject_loan" />
                            <input type="hidden" name="loan_id" value="<?php echo (int) $loan_id; ?>" />
                            <input type="text" name="rejection_reason" placeholder="<?php esc_attr_e( 'Reason (required)', 'sfs-hr' ); ?>" required style="width:300px;" />
                            <button type="submit" class="button"><?php esc_html_e( 'Reject', 'sfs-hr' ); ?></button>
                        </form>
                    <?php else : ?>
                        <div class="notice notice-info inline">
                            <p>
                                <?php
                                $gm_users = \SFS\HR\Modules\Loans\LoansModule::get_gm_users();
                                if ( ! empty( $gm_users ) ) {
                                    $gm_names = array_map( function( $user ) {
                                        return $user->display_name;
                                    }, $gm_users );
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %s: Comma-separated list of GM names */
                                            __( 'This loan requires GM approval. Assigned GM(s): %s', 'sfs-hr' ),
                                            implode( ', ', $gm_names )
                                        )
                                    );
                                } else {
                                    esc_html_e( 'This loan requires GM approval. No GMs are currently assigned in settings.', 'sfs-hr' );
                                }
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>

                <?php elseif ( $loan->status === 'pending_finance' ) : ?>
                    <!-- GM Approval Info -->
                    <?php if ( $loan->approved_gm_by ) :
                        $gm_user = get_user_by( 'id', $loan->approved_gm_by );
                        $gm_name = $gm_user ? $gm_user->display_name : __( 'Unknown', 'sfs-hr' );
                    ?>
                        <div class="notice notice-success inline" style="margin:0 0 20px 0;">
                            <h4 style="margin:0 0 10px 0;"><?php esc_html_e( 'GM Approval', 'sfs-hr' ); ?></h4>
                            <p>
                                <strong><?php esc_html_e( 'Approved by:', 'sfs-hr' ); ?></strong> <?php echo esc_html( $gm_name ); ?><br>
                                <strong><?php esc_html_e( 'Date:', 'sfs-hr' ); ?></strong> <?php echo esc_html( wp_date( 'F j, Y g:i a', strtotime( $loan->approved_gm_at ) ) ); ?>
                                <?php if ( ! empty( $loan->approved_gm_amount ) && abs( (float) $loan->approved_gm_amount - (float) $loan->principal_amount ) > 0.01 ) : ?>
                                    <br><strong><?php esc_html_e( 'GM Approved Amount:', 'sfs-hr' ); ?></strong> <?php echo number_format( (float) $loan->approved_gm_amount, 2 ); ?> <?php echo esc_html( $loan->currency ); ?>
                                <?php endif; ?>
                                <?php if ( ! empty( $loan->approved_gm_note ) ) : ?>
                                    <br><strong><?php esc_html_e( 'GM Note:', 'sfs-hr' ); ?></strong> <?php echo esc_html( $loan->approved_gm_note ); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <h3><?php esc_html_e( 'Finance Approval', 'sfs-hr' ); ?></h3>

                    <?php if ( \SFS\HR\Modules\Loans\LoansModule::current_user_can_approve_as_finance() ) : ?>
                        <form method="post" action="">
                            <?php wp_nonce_field( 'sfs_hr_loan_approve_finance_' . $loan_id ); ?>
                            <input type="hidden" name="action" value="approve_finance" />
                            <input type="hidden" name="loan_id" value="<?php echo (int) $loan_id; ?>" />

                            <table class="form-table">
                                <tr>
                                    <th><?php esc_html_e( 'Principal Amount', 'sfs-hr' ); ?></th>
                                    <td>
                                        <input type="number" name="principal_amount" value="<?php echo esc_attr( $loan->principal_amount ); ?>" step="0.01" required style="width:150px;" />
                                        <p class="description"><?php esc_html_e( 'You can adjust the amount before approval.', 'sfs-hr' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Number of Installments', 'sfs-hr' ); ?></th>
                                    <td>
                                        <input type="number" name="installments_count" value="<?php echo esc_attr( $loan->installments_count ); ?>" min="1" required style="width:100px;" />
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'First Deduction Month', 'sfs-hr' ); ?></th>
                                    <td>
                                        <input type="month" name="first_due_month" required />
                                        <p class="description"><?php esc_html_e( 'Select the month when first deduction should occur.', 'sfs-hr' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Approval Note', 'sfs-hr' ); ?></th>
                                    <td>
                                        <textarea name="approved_finance_note" rows="2" style="width:400px;" placeholder="<?php esc_attr_e( 'Optional note for this approval', 'sfs-hr' ); ?>"></textarea>
                                    </td>
                                </tr>
                            </table>

                            <p>
                                <button type="submit" class="button button-primary"><?php esc_html_e( 'Approve & Activate Loan', 'sfs-hr' ); ?></button>
                                <a href="?page=sfs-hr-loans" class="button"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></a>
                            </p>
                        </form>

                        <hr style="margin:20px 0;">
                        <form method="post" action="" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to reject this loan?', 'sfs-hr' ); ?>');">
                            <?php wp_nonce_field( 'sfs_hr_loan_reject_' . $loan_id ); ?>
                            <input type="hidden" name="action" value="reject_loan" />
                            <input type="hidden" name="loan_id" value="<?php echo (int) $loan_id; ?>" />
                            <input type="text" name="rejection_reason" placeholder="<?php esc_attr_e( 'Reason (required)', 'sfs-hr' ); ?>" required style="width:400px;" />
                            <button type="submit" class="button"><?php esc_html_e( 'Reject', 'sfs-hr' ); ?></button>
                        </form>
                    <?php else : ?>
                        <div class="notice notice-info inline">
                            <p>
                                <?php
                                $finance_users = \SFS\HR\Modules\Loans\LoansModule::get_finance_users();
                                if ( ! empty( $finance_users ) ) {
                                    $finance_names = array_map( function( $user ) {
                                        return $user->display_name;
                                    }, $finance_users );
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %s: Comma-separated list of Finance user names */
                                            __( 'This loan requires Finance approval. Assigned Finance user(s): %s', 'sfs-hr' ),
                                            implode( ', ', $finance_names )
                                        )
                                    );
                                } else {
                                    esc_html_e( 'This loan requires Finance approval. No Finance users are currently assigned in settings.', 'sfs-hr' );
                                }
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Schedule -->
            <?php if ( ! empty( $payments ) ) : ?>
                <div style="background:#fff;padding:20px;border:1px solid #ccc;border-radius:4px;margin-top:20px;">
                    <h2><?php esc_html_e( 'Payment Schedule', 'sfs-hr' ); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( '#', 'sfs-hr' ); ?></th>
                                <th><?php esc_html_e( 'Due Date', 'sfs-hr' ); ?></th>
                                <th><?php esc_html_e( 'Amount Planned', 'sfs-hr' ); ?></th>
                                <th><?php esc_html_e( 'Amount Paid', 'sfs-hr' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                                <th><?php esc_html_e( 'Paid At', 'sfs-hr' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $payments as $payment ) : ?>
                                <tr>
                                    <td><?php echo (int) $payment->sequence; ?></td>
                                    <td><?php echo esc_html( wp_date( 'F Y', strtotime( $payment->due_date ) ) ); ?></td>
                                    <td><?php echo number_format( (float) $payment->amount_planned, 2 ); ?></td>
                                    <td><?php echo number_format( (float) $payment->amount_paid, 2 ); ?></td>
                                    <td>
                                        <span style="padding:2px 6px;border-radius:3px;font-size:11px;background:<?php
                                        echo $payment->status === 'paid' ? '#28a745' : ($payment->status === 'skipped' ? '#6c757d' : '#ffc107');
                                        ?>;color:#fff;">
                                            <?php echo esc_html( ucfirst( $payment->status ) ); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $payment->paid_at ? esc_html( wp_date( 'M j, Y', strtotime( $payment->paid_at ) ) ) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
                </div><!-- /.sfs-loan-detail-main -->

                <!-- Sidebar: History -->
                <div class="sfs-loan-detail-sidebar">
                    <?php if ( ! empty( $history ) ) : ?>
                        <div style="background:#fff;padding:20px;border:1px solid #ccc;border-radius:4px;">
                            <h2><?php esc_html_e( 'Loan History', 'sfs-hr' ); ?></h2>
                            <div style="max-height:600px;overflow-y:auto;">
                                <?php foreach ( $history as $event ) : ?>
                                    <div style="border-bottom:1px solid #eee;padding:10px 0;">
                                        <div style="font-size:11px;color:#666;"><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $event->created_at ) ) ); ?></div>
                                        <div style="font-weight:600;margin:4px 0;"><?php echo esc_html( str_replace( '_', ' ', ucwords( $event->event_type, '_' ) ) ); ?></div>
                                        <div style="font-size:12px;color:#555;"><?php echo esc_html( $event->user_name ?: __( 'System', 'sfs-hr' ) ); ?></div>
                                        <?php
                                        if ( $event->meta ) {
                                            $meta = json_decode( $event->meta, true );
                                            if ( is_array( $meta ) && ! empty( $meta ) ) {
                                                echo '<div style="font-size:11px;margin-top:6px;background:#f9f9f9;padding:6px;border-radius:3px;">';
                                                foreach ( $meta as $key => $value ) {
                                                    $label = ucwords( str_replace( '_', ' ', $key ) );
                                                    if ( is_numeric( $value ) && $key !== 'installments' ) {
                                                        $display_value = number_format( (float) $value, 2 );
                                                    } else {
                                                        $display_value = esc_html( $value );
                                                    }
                                                    echo '<strong>' . esc_html( $label ) . ':</strong> ' . $display_value . '<br>';
                                                }
                                                echo '</div>';
                                            }
                                        }
                                        ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div><!-- /.sfs-loan-detail-sidebar -->
            </div><!-- /.sfs-loan-detail-grid -->
        </div>
        <?php
    }

    /**
     * Handle loan actions (approve, reject, etc.)
     */
    public function handle_loan_actions(): void {
        if ( ! isset( $_POST['action'] ) ) {
            return;
        }

        $action = $_POST['action'];
        $loan_id = isset( $_POST['loan_id'] ) ? (int) $_POST['loan_id'] : 0;

        // Check capability based on action type
        $allowed = false;
        switch ( $action ) {
            case 'approve_gm':
                $allowed = \SFS\HR\Modules\Loans\LoansModule::current_user_can_approve_as_gm();
                break;
            case 'approve_finance':
                $allowed = \SFS\HR\Modules\Loans\LoansModule::current_user_can_approve_as_finance();
                break;
            case 'reject_loan':
                // GM or Finance can reject
                $allowed = \SFS\HR\Modules\Loans\LoansModule::current_user_can_approve_as_gm()
                        || \SFS\HR\Modules\Loans\LoansModule::current_user_can_approve_as_finance();
                break;
            case 'create_loan':
            case 'update_loan':
            case 'record_payment':
                $allowed = current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr_loans_manage' );
                break;
            default:
                return;
        }

        if ( ! $allowed ) {
            return;
        }

        global $wpdb;
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';

        switch ( $action ) {
            case 'approve_gm':
                // Validate loan_id for this action
                if ( ! $loan_id ) {
                    return;
                }
                check_admin_referer( 'sfs_hr_loan_approve_gm_' . $loan_id );

                $approved_gm_amount = isset( $_POST['approved_gm_amount'] ) ? (float) $_POST['approved_gm_amount'] : null;
                $approved_gm_note = isset( $_POST['approved_gm_note'] ) ? sanitize_textarea_field( $_POST['approved_gm_note'] ) : '';

                // Get original loan to compare amounts
                $original_loan = $wpdb->get_row( $wpdb->prepare(
                    "SELECT principal_amount FROM {$loans_table} WHERE id = %d",
                    $loan_id
                ) );

                $update_data = [
                    'status'             => 'pending_finance',
                    'approved_gm_by'     => get_current_user_id(),
                    'approved_gm_at'     => current_time( 'mysql' ),
                    'approved_gm_note'   => $approved_gm_note,
                    'updated_at'         => current_time( 'mysql' ),
                ];

                // If GM approved a different amount, save it and update principal
                if ( $approved_gm_amount !== null && $approved_gm_amount > 0 ) {
                    $update_data['approved_gm_amount'] = $approved_gm_amount;
                    // Update principal amount to the GM approved amount
                    $update_data['principal_amount'] = $approved_gm_amount;
                }

                $wpdb->update( $loans_table, $update_data, [ 'id' => $loan_id ] );

                $log_meta = [
                    'status' => 'pending_gm → pending_finance',
                ];
                if ( $approved_gm_amount !== null && $original_loan && abs( $approved_gm_amount - (float) $original_loan->principal_amount ) > 0.01 ) {
                    $log_meta['original_amount'] = $original_loan->principal_amount;
                    $log_meta['approved_amount'] = $approved_gm_amount;
                }
                if ( $approved_gm_note ) {
                    $log_meta['note'] = $approved_gm_note;
                }

                \SFS\HR\Modules\Loans\LoansModule::log_event( $loan_id, 'gm_approved', $log_meta );

                // Audit Trail: loan status changed
                do_action( 'sfs_hr_loan_status_changed', $loan_id, 'pending_gm', 'pending_finance' );

                // Send notification to Finance
                \SFS\HR\Modules\Loans\Notifications::notify_gm_approved( $loan_id );

                wp_safe_redirect( add_query_arg( [ 'page' => 'sfs-hr-loans', 'action' => 'view', 'id' => $loan_id, 'updated' => '1' ], admin_url( 'admin.php' ) ) );
                exit;

            case 'approve_finance':
                // Validate loan_id for this action
                if ( ! $loan_id ) {
                    return;
                }
                check_admin_referer( 'sfs_hr_loan_approve_finance_' . $loan_id );

                $principal = (float) ( $_POST['principal_amount'] ?? 0 );
                $installments = (int) ( $_POST['installments_count'] ?? 0 );
                $first_month = sanitize_text_field( $_POST['first_due_month'] ?? '' );
                $approved_finance_note = isset( $_POST['approved_finance_note'] ) ? sanitize_textarea_field( $_POST['approved_finance_note'] ) : '';

                if ( $principal <= 0 || $installments <= 0 || ! $first_month ) {
                    wp_die( __( 'Invalid data', 'sfs-hr' ) );
                }

                $installment_amount = round( $principal / $installments, 2 );

                // Update loan
                $wpdb->update( $loans_table, [
                    'principal_amount'       => $principal,
                    'installments_count'     => $installments,
                    'installment_amount'     => $installment_amount,
                    'remaining_balance'      => $principal,
                    'status'                 => 'active',
                    'approved_finance_by'    => get_current_user_id(),
                    'approved_finance_at'    => current_time( 'mysql' ),
                    'approved_finance_note'  => $approved_finance_note,
                    'updated_at'             => current_time( 'mysql' ),
                ], [ 'id' => $loan_id ] );

                // Generate schedule
                $this->generate_payment_schedule( $loan_id, $first_month, $installments, $installment_amount );

                $log_meta = [
                    'status'       => 'pending_finance → active',
                    'principal'    => $principal,
                    'installments' => $installments,
                ];
                if ( $approved_finance_note ) {
                    $log_meta['note'] = $approved_finance_note;
                }

                \SFS\HR\Modules\Loans\LoansModule::log_event( $loan_id, 'finance_approved', $log_meta );

                // Audit Trail: loan status changed
                do_action( 'sfs_hr_loan_status_changed', $loan_id, 'pending_finance', 'active' );

                // Send notification to Employee
                \SFS\HR\Modules\Loans\Notifications::notify_finance_approved( $loan_id );

                wp_safe_redirect( add_query_arg( [ 'page' => 'sfs-hr-loans', 'action' => 'view', 'id' => $loan_id, 'updated' => '1' ], admin_url( 'admin.php' ) ) );
                exit;

            case 'reject_loan':
                // Validate loan_id for this action
                if ( ! $loan_id ) {
                    return;
                }
                check_admin_referer( 'sfs_hr_loan_reject_' . $loan_id );

                $reason = sanitize_textarea_field( $_POST['rejection_reason'] ?? '' );

                if ( ! $reason ) {
                    wp_die( __( 'Rejection reason is required', 'sfs-hr' ) );
                }

                $wpdb->update( $loans_table, [
                    'status'           => 'rejected',
                    'rejected_by'      => get_current_user_id(),
                    'rejected_at'      => current_time( 'mysql' ),
                    'rejection_reason' => $reason,
                    'updated_at'       => current_time( 'mysql' ),
                ], [ 'id' => $loan_id ] );

                \SFS\HR\Modules\Loans\LoansModule::log_event( $loan_id, 'rejected', [
                    'reason' => $reason,
                ] );

                // Audit Trail: loan status changed to rejected
                do_action( 'sfs_hr_loan_status_changed', $loan_id, 'pending', 'rejected' );

                // Send notification to Employee
                \SFS\HR\Modules\Loans\Notifications::notify_loan_rejected( $loan_id );

                wp_safe_redirect( add_query_arg( [ 'page' => 'sfs-hr-loans', 'action' => 'view', 'id' => $loan_id, 'updated' => '1' ], admin_url( 'admin.php' ) ) );
                exit;

            case 'create_loan':
                check_admin_referer( 'sfs_hr_loan_create' );

                // Validate inputs
                $employee_id = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;
                $principal = isset( $_POST['principal_amount'] ) ? (float) $_POST['principal_amount'] : 0;
                $installments = isset( $_POST['installments_count'] ) ? (int) $_POST['installments_count'] : 0;
                $reason = sanitize_textarea_field( $_POST['reason'] ?? '' );
                $internal_notes = sanitize_textarea_field( $_POST['internal_notes'] ?? '' );

                // Validation
                if ( ! $employee_id || ! $principal || ! $installments || ! $reason ) {
                    wp_safe_redirect( add_query_arg( [
                        'page' => 'sfs-hr-loans',
                        'action' => 'create',
                        'error' => urlencode( __( 'All required fields must be filled.', 'sfs-hr' ) ),
                    ], admin_url( 'admin.php' ) ) );
                    exit;
                }

                if ( $principal <= 0 || $installments <= 0 || $installments > 60 ) {
                    wp_safe_redirect( add_query_arg( [
                        'page' => 'sfs-hr-loans',
                        'action' => 'create',
                        'error' => urlencode( __( 'Invalid amount or installments.', 'sfs-hr' ) ),
                    ], admin_url( 'admin.php' ) ) );
                    exit;
                }

                // Get employee details
                $emp_table = $wpdb->prefix . 'sfs_hr_employees';
                $dept_table = $wpdb->prefix . 'sfs_hr_departments';
                $employee = $wpdb->get_row( $wpdb->prepare(
                    "SELECT COALESCE(d.name, 'N/A') as department
                     FROM {$emp_table} e
                     LEFT JOIN {$dept_table} d ON e.dept_id = d.id
                     WHERE e.id = %d AND e.status = 'active'",
                    $employee_id
                ) );

                if ( ! $employee ) {
                    wp_safe_redirect( add_query_arg( [
                        'page' => 'sfs-hr-loans',
                        'action' => 'create',
                        'error' => urlencode( __( 'Employee not found or inactive.', 'sfs-hr' ) ),
                    ], admin_url( 'admin.php' ) ) );
                    exit;
                }

                // Generate loan number
                $loan_number = \SFS\HR\Modules\Loans\LoansModule::generate_loan_number();

                // Calculate installment amount
                $installment_amount = round( $principal / $installments, 2 );

                // Insert loan
                $result = $wpdb->insert( $loans_table, [
                    'loan_number'        => $loan_number,
                    'employee_id'        => $employee_id,
                    'department'         => $employee->department,
                    'principal_amount'   => $principal,
                    'currency'           => 'SAR',
                    'installments_count' => $installments,
                    'installment_amount' => $installment_amount,
                    'remaining_balance'  => 0, // Will be set when approved
                    'status'             => 'pending_gm',
                    'reason'             => $reason,
                    'internal_notes'     => $internal_notes,
                    'request_source'     => 'admin_portal',
                    'created_by'         => get_current_user_id(),
                    'created_at'         => current_time( 'mysql' ),
                    'updated_at'         => current_time( 'mysql' ),
                ] );

                $new_loan_id = $wpdb->insert_id;

                if ( ! $new_loan_id ) {
                    error_log( 'Loan insert failed. Result: ' . var_export( $result, true ) );
                    error_log( 'wpdb->last_error: ' . $wpdb->last_error );
                    error_log( 'wpdb->last_query: ' . $wpdb->last_query );

                    wp_safe_redirect( add_query_arg( [
                        'page' => 'sfs-hr-loans',
                        'action' => 'create',
                        'error' => urlencode( 'Failed to create loan: ' . $wpdb->last_error ),
                    ], admin_url( 'admin.php' ) ) );
                    exit;
                }

                // Log creation
                \SFS\HR\Modules\Loans\LoansModule::log_event( $new_loan_id, 'loan_created', [
                    'created_by'      => 'admin',
                    'principal'       => $principal,
                    'installments'    => $installments,
                    'request_source'  => 'admin_portal',
                ] );

                // Audit Trail: loan created
                do_action( 'sfs_hr_loan_created', $new_loan_id, [
                    'employee_id'   => $employee_id,
                    'amount'        => $principal,
                    'installments'  => $installments,
                    'source'        => 'admin_portal',
                ] );

                // Send notification to GM
                \SFS\HR\Modules\Loans\Notifications::notify_new_loan_request( $new_loan_id );

                // Redirect to loan detail
                wp_safe_redirect( add_query_arg( [
                    'page' => 'sfs-hr-loans',
                    'action' => 'view',
                    'id' => $new_loan_id,
                    'updated' => '1',
                ], admin_url( 'admin.php' ) ) );
                exit;
        }
    }

    /**
     * Generate payment schedule
     */
    private function generate_payment_schedule( int $loan_id, string $first_month, int $installments, float $installment_amount ): void {
        global $wpdb;
        $payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';

        // Clear existing schedule
        $wpdb->delete( $payments_table, [ 'loan_id' => $loan_id ] );

        $first_date = new \DateTime( $first_month . '-01' );
        $last_date = clone $first_date;

        for ( $i = 1; $i <= $installments; $i++ ) {
            $due_date = clone $first_date;
            $due_date->modify( '+' . ($i - 1) . ' months' );

            // Last installment may need adjustment for rounding
            $amount = ( $i === $installments ) ? $installment_amount : $installment_amount;

            $wpdb->insert( $payments_table, [
                'loan_id'        => $loan_id,
                'sequence'       => $i,
                'due_date'       => $due_date->format( 'Y-m-d' ),
                'amount_planned' => $amount,
                'amount_paid'    => 0,
                'status'         => 'planned',
                'created_at'     => current_time( 'mysql' ),
                'updated_at'     => current_time( 'mysql' ),
            ] );

            $last_date = $due_date;
        }

        // Update loan with first/last due dates
        $wpdb->update( $wpdb->prefix . 'sfs_hr_loans', [
            'first_due_date' => $first_date->format( 'Y-m-d' ),
            'last_due_date'  => $last_date->format( 'Y-m-d' ),
        ], [ 'id' => $loan_id ] );

        \SFS\HR\Modules\Loans\LoansModule::log_event( $loan_id, 'schedule_generated', [
            'installments' => $installments,
            'first_due'    => $first_date->format( 'Y-m-d' ),
            'last_due'     => $last_date->format( 'Y-m-d' ),
        ] );
    }

    /**
     * Handle installment payment actions
     */
    public function handle_installment_actions(): void {
        if ( ! isset( $_POST['action'] ) || ! current_user_can( 'sfs_hr.manage' ) ) {
            return;
        }

        $action = $_POST['action'];
        $payment_id = isset( $_POST['payment_id'] ) ? (int) $_POST['payment_id'] : 0;
        $month = isset( $_POST['month'] ) ? sanitize_text_field( $_POST['month'] ) : wp_date( 'Y-m' );

        global $wpdb;
        $payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';

        $redirect_url = add_query_arg( [
            'page'  => 'sfs-hr-loans',
            'tab'   => 'installments',
            'month' => $month,
        ], admin_url( 'admin.php' ) );

        switch ( $action ) {
            case 'mark_installment_paid':
                if ( ! $payment_id ) {
                    return;
                }
                check_admin_referer( 'sfs_hr_mark_installment_' . $payment_id );

                // Get payment details
                $payment = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$payments_table} WHERE id = %d",
                    $payment_id
                ) );

                if ( ! $payment ) {
                    wp_die( __( 'Payment not found', 'sfs-hr' ) );
                }

                // Update payment status
                $wpdb->update( $payments_table, [
                    'amount_paid' => $payment->amount_planned,
                    'status'      => 'paid',
                    'paid_at'     => current_time( 'mysql' ),
                    'updated_at'  => current_time( 'mysql' ),
                ], [ 'id' => $payment_id ] );

                // Update loan remaining balance
                $this->update_loan_balance( $payment->loan_id );

                // Log event
                \SFS\HR\Modules\Loans\LoansModule::log_event( $payment->loan_id, 'payment_marked_paid', [
                    'payment_id' => $payment_id,
                    'sequence'   => $payment->sequence,
                    'amount'     => $payment->amount_planned,
                ] );

                wp_safe_redirect( add_query_arg( 'updated', '1', $redirect_url ) );
                exit;

            case 'mark_installment_partial':
                if ( ! $payment_id ) {
                    return;
                }
                check_admin_referer( 'sfs_hr_mark_installment_' . $payment_id );

                $partial_amount = (float) ( $_POST['partial_amount'] ?? 0 );

                // Get payment details
                $payment = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$payments_table} WHERE id = %d",
                    $payment_id
                ) );

                if ( ! $payment || $partial_amount <= 0 || $partial_amount > (float) $payment->amount_planned ) {
                    wp_die( __( 'Invalid partial amount', 'sfs-hr' ) );
                }

                // Update payment status
                $wpdb->update( $payments_table, [
                    'amount_paid' => $partial_amount,
                    'status'      => 'partial',
                    'paid_at'     => current_time( 'mysql' ),
                    'updated_at'  => current_time( 'mysql' ),
                ], [ 'id' => $payment_id ] );

                // Update loan remaining balance
                $this->update_loan_balance( $payment->loan_id );

                // Log event
                \SFS\HR\Modules\Loans\LoansModule::log_event( $payment->loan_id, 'payment_marked_partial', [
                    'payment_id'     => $payment_id,
                    'sequence'       => $payment->sequence,
                    'amount_planned' => $payment->amount_planned,
                    'amount_paid'    => $partial_amount,
                ] );

                wp_safe_redirect( add_query_arg( 'updated', '1', $redirect_url ) );
                exit;

            case 'mark_installment_skipped':
                if ( ! $payment_id ) {
                    return;
                }
                check_admin_referer( 'sfs_hr_mark_installment_' . $payment_id );

                // Get payment details
                $payment = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$payments_table} WHERE id = %d",
                    $payment_id
                ) );

                if ( ! $payment ) {
                    wp_die( __( 'Payment not found', 'sfs-hr' ) );
                }

                // Update payment status
                $wpdb->update( $payments_table, [
                    'status'     => 'skipped',
                    'updated_at' => current_time( 'mysql' ),
                ], [ 'id' => $payment_id ] );

                // Log event
                \SFS\HR\Modules\Loans\LoansModule::log_event( $payment->loan_id, 'payment_skipped', [
                    'payment_id' => $payment_id,
                    'sequence'   => $payment->sequence,
                ] );

                // Send notification to Employee
                \SFS\HR\Modules\Loans\Notifications::notify_installment_skipped( $payment->loan_id, $payment->sequence );

                wp_safe_redirect( add_query_arg( 'updated', '1', $redirect_url ) );
                exit;

            case 'bulk_update_installments':
                check_admin_referer( 'sfs_hr_bulk_installments' );

                $bulk_action = sanitize_text_field( $_POST['bulk_action'] ?? '' );
                $payment_ids = isset( $_POST['payment_ids'] ) ? array_map( 'intval', $_POST['payment_ids'] ) : [];

                if ( ! $bulk_action || empty( $payment_ids ) ) {
                    wp_safe_redirect( $redirect_url );
                    exit;
                }

                $processed = 0;

                foreach ( $payment_ids as $pid ) {
                    $payment = $wpdb->get_row( $wpdb->prepare(
                        "SELECT * FROM {$payments_table} WHERE id = %d",
                        $pid
                    ) );

                    if ( ! $payment ) {
                        continue;
                    }

                    if ( $bulk_action === 'mark_paid' ) {
                        $wpdb->update( $payments_table, [
                            'amount_paid' => $payment->amount_planned,
                            'status'      => 'paid',
                            'paid_at'     => current_time( 'mysql' ),
                            'updated_at'  => current_time( 'mysql' ),
                        ], [ 'id' => $pid ] );

                        $this->update_loan_balance( $payment->loan_id );

                        \SFS\HR\Modules\Loans\LoansModule::log_event( $payment->loan_id, 'payment_marked_paid', [
                            'payment_id' => $pid,
                            'sequence'   => $payment->sequence,
                            'amount'     => $payment->amount_planned,
                            'bulk'       => true,
                        ] );

                        $processed++;
                    } elseif ( $bulk_action === 'mark_skipped' ) {
                        $wpdb->update( $payments_table, [
                            'status'     => 'skipped',
                            'updated_at' => current_time( 'mysql' ),
                        ], [ 'id' => $pid ] );

                        \SFS\HR\Modules\Loans\LoansModule::log_event( $payment->loan_id, 'payment_skipped', [
                            'payment_id' => $pid,
                            'sequence'   => $payment->sequence,
                            'bulk'       => true,
                        ] );

                        // Send notification to Employee
                        \SFS\HR\Modules\Loans\Notifications::notify_installment_skipped( $payment->loan_id, $payment->sequence );

                        $processed++;
                    }
                }

                wp_safe_redirect( add_query_arg( 'updated', $processed, $redirect_url ) );
                exit;
        }
    }

    /**
     * Update loan remaining balance based on payments
     */
    private function update_loan_balance( int $loan_id ): void {
        global $wpdb;
        $payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';

        // Get loan
        $loan = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$loans_table} WHERE id = %d",
            $loan_id
        ) );

        if ( ! $loan ) {
            return;
        }

        // Calculate total paid
        $total_paid = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(amount_paid) FROM {$payments_table} WHERE loan_id = %d",
            $loan_id
        ) );

        // Calculate remaining balance
        $remaining = (float) $loan->principal_amount - $total_paid;
        $remaining = max( 0, $remaining ); // Ensure non-negative

        // Update loan
        $wpdb->update( $loans_table, [
            'remaining_balance' => $remaining,
            'updated_at'        => current_time( 'mysql' ),
        ], [ 'id' => $loan_id ] );

        // Check if loan is fully paid
        if ( $remaining <= 0.01 && $loan->status === 'active' ) {
            $wpdb->update( $loans_table, [
                'status'     => 'completed',
                'updated_at' => current_time( 'mysql' ),
            ], [ 'id' => $loan_id ] );

            \SFS\HR\Modules\Loans\LoansModule::log_event( $loan_id, 'loan_completed', [
                'principal_amount' => $loan->principal_amount,
                'total_paid'       => $total_paid,
            ] );
        }
    }

    /**
     * Export installments to CSV
     */
    public function export_installments_csv(): void {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sfs-hr-loans' ) {
            return;
        }

        if ( ! isset( $_GET['tab'] ) || $_GET['tab'] !== 'installments' ) {
            return;
        }

        if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'export_csv' ) {
            return;
        }

        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'Permission denied', 'sfs-hr' ) );
        }

        global $wpdb;
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Get selected month
        $selected_month = isset( $_GET['month'] ) ? sanitize_text_field( $_GET['month'] ) : wp_date( 'Y-m' );

        // Calculate month range
        $month_start = $selected_month . '-01';
        $month_end = wp_date( 'Y-m-t', strtotime( $month_start ) );

        // Query installments
        $installments = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, l.loan_number, l.currency, l.employee_id,
                    CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                    e.employee_code
             FROM {$payments_table} p
             INNER JOIN {$loans_table} l ON p.loan_id = l.id
             LEFT JOIN {$emp_table} e ON l.employee_id = e.id
             WHERE p.due_date >= %s
             AND p.due_date <= %s
             AND l.status = 'active'
             ORDER BY e.first_name ASC, p.due_date ASC",
            $month_start,
            $month_end
        ) );

        // Set headers for CSV download
        $filename = 'loan-installments-' . $selected_month . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Open output stream
        $output = fopen( 'php://output', 'w' );

        // Write UTF-8 BOM for Excel compatibility
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );

        // Write CSV headers
        fputcsv( $output, [
            'Employee Code',
            'Employee Name',
            'Loan Number',
            'Installment #',
            'Due Date',
            'Amount Planned',
            'Amount Paid',
            'Status',
            'Currency',
        ] );

        // Write data rows
        foreach ( $installments as $inst ) {
            fputcsv( $output, [
                $inst->employee_code,
                $inst->employee_name,
                $inst->loan_number,
                $inst->sequence,
                wp_date( 'Y-m-d', strtotime( $inst->due_date ) ),
                number_format( (float) $inst->amount_planned, 2, '.', '' ),
                number_format( (float) $inst->amount_paid, 2, '.', '' ),
                ucfirst( $inst->status ),
                $inst->currency,
            ] );
        }

        fclose( $output );
        exit;
    }
}