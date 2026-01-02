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
     * Main loans page (list view or detail)
     */
    public function loans_page(): void {
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
        <div style="margin-bottom: 15px;">
            <a href="?page=sfs-hr-loans&action=create" class="button button-primary">
                <?php esc_html_e( 'Add New Loan', 'sfs-hr' ); ?>
            </a>
        </div>

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
                            <td>
                                <a href="?page=sfs-hr-loans&action=view&id=<?php echo (int) $loan->id; ?>" style="text-decoration:none;">
                                    <strong><?php echo esc_html( $loan->loan_number ); ?></strong>
                                </a>
                            </td>
                            <td>
                                <a href="?page=sfs-hr-employee-profile&employee_id=<?php echo (int) $loan->employee_id; ?>" style="text-decoration:none;">
                                    <?php echo esc_html( $loan->employee_name ); ?>
                                </a>
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

        // Get selected month (default to current month)
        $selected_month = isset( $_GET['month'] ) ? sanitize_text_field( $_GET['month'] ) : wp_date( 'Y-m' );

        // Calculate month range
        $month_start = $selected_month . '-01';
        $month_end = wp_date( 'Y-m-t', strtotime( $month_start ) );

        // Query installments for selected month
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
             ORDER BY p.due_date ASC, e.first_name ASC",
            $month_start,
            $month_end
        ) );

        // Calculate totals
        $total_planned = 0;
        $total_paid = 0;
        $count_paid = 0;
        $count_pending = 0;
        $count_skipped = 0;
        $count_partial = 0;

        foreach ( $installments as $inst ) {
            $total_planned += (float) $inst->amount_planned;
            $total_paid += (float) $inst->amount_paid;

            switch ( $inst->status ) {
                case 'paid':
                    $count_paid++;
                    break;
                case 'planned':
                    $count_pending++;
                    break;
                case 'skipped':
                    $count_skipped++;
                    break;
                case 'partial':
                    $count_partial++;
                    break;
            }
        }

        ?>
        <div class="notice notice-info" style="display:flex;justify-content:space-between;align-items:center;">
            <p style="margin:0;">
                <?php esc_html_e( 'This page shows all loan installments due for the selected month. Mark installments as paid/partial/skipped to update loan balances.', 'sfs-hr' ); ?>
            </p>
        </div>

        <div style="background:#fff;padding:15px;border:1px solid #ccc;border-radius:4px;margin-top:15px;margin-bottom:15px;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <label for="month-selector" style="margin-right:10px;font-weight:600;">
                        <?php esc_html_e( 'Select Month:', 'sfs-hr' ); ?>
                    </label>
                    <input type="month" id="month-selector" value="<?php echo esc_attr( $selected_month ); ?>"
                           onchange="location.href='?page=sfs-hr-loans&tab=installments&month='+this.value"
                           style="padding:5px;" />
                </div>
                <div>
                    <a href="?page=sfs-hr-loans&tab=installments&action=export_csv&month=<?php echo esc_attr( $selected_month ); ?>"
                       class="button button-secondary">
                        <?php esc_html_e( '📥 Export to CSV', 'sfs-hr' ); ?>
                    </a>
                </div>
            </div>

            <div style="margin-top:15px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">
                <div style="padding:10px;background:#f0f9ff;border-left:3px solid #0ea5e9;border-radius:4px;">
                    <div style="font-size:11px;color:#666;text-transform:uppercase;"><?php esc_html_e( 'Total Planned', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px;font-weight:600;margin-top:5px;">
                        <?php echo number_format( $total_planned, 2 ); ?> <span style="font-size:12px;">SAR</span>
                    </div>
                </div>
                <div style="padding:10px;background:#f0fdf4;border-left:3px solid #22c55e;border-radius:4px;">
                    <div style="font-size:11px;color:#666;text-transform:uppercase;"><?php esc_html_e( 'Total Paid', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px;font-weight:600;margin-top:5px;">
                        <?php echo number_format( $total_paid, 2 ); ?> <span style="font-size:12px;">SAR</span>
                    </div>
                </div>
                <div style="padding:10px;background:#fefce8;border-left:3px solid #eab308;border-radius:4px;">
                    <div style="font-size:11px;color:#666;text-transform:uppercase;"><?php esc_html_e( 'Pending', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px;font-weight:600;margin-top:5px;"><?php echo $count_pending; ?></div>
                </div>
                <div style="padding:10px;background:#f0fdf4;border-left:3px solid #22c55e;border-radius:4px;">
                    <div style="font-size:11px;color:#666;text-transform:uppercase;"><?php esc_html_e( 'Paid', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px;font-weight:600;margin-top:5px;"><?php echo $count_paid; ?></div>
                </div>
                <div style="padding:10px;background:#fef2f2;border-left:3px solid #ef4444;border-radius:4px;">
                    <div style="font-size:11px;color:#666;text-transform:uppercase;"><?php esc_html_e( 'Partial', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px;font-weight:600;margin-top:5px;"><?php echo $count_partial; ?></div>
                </div>
                <div style="padding:10px;background:#f9fafb;border-left:3px solid #6b7280;border-radius:4px;">
                    <div style="font-size:11px;color:#666;text-transform:uppercase;"><?php esc_html_e( 'Skipped', 'sfs-hr' ); ?></div>
                    <div style="font-size:18px;font-weight:600;margin-top:5px;"><?php echo $count_skipped; ?></div>
                </div>
            </div>
        </div>

        <?php if ( empty( $installments ) ) : ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e( 'No installments found for the selected month.', 'sfs-hr' ); ?></p>
            </div>
        <?php else : ?>
            <form method="post" action="" id="bulk-installments-form">
                <?php wp_nonce_field( 'sfs_hr_bulk_installments' ); ?>
                <input type="hidden" name="action" value="bulk_update_installments" />
                <input type="hidden" name="month" value="<?php echo esc_attr( $selected_month ); ?>" />

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action" id="bulk-action-selector-top">
                            <option value=""><?php esc_html_e( 'Bulk Actions', 'sfs-hr' ); ?></option>
                            <option value="mark_paid"><?php esc_html_e( 'Mark as Paid', 'sfs-hr' ); ?></option>
                            <option value="mark_skipped"><?php esc_html_e( 'Mark as Skipped', 'sfs-hr' ); ?></option>
                        </select>
                        <button type="submit" class="button action"><?php esc_html_e( 'Apply', 'sfs-hr' ); ?></button>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="cb-select-all" onclick="
                                    var checkboxes = document.getElementsByName('payment_ids[]');
                                    for (var i = 0; i < checkboxes.length; i++) {
                                        checkboxes[i].checked = this.checked;
                                    }
                                " />
                            </td>
                            <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Loan #', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Installment #', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Due Date', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Amount Planned', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Amount Paid', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Remaining Loan', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $installments as $inst ) : ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="payment_ids[]" value="<?php echo (int) $inst->id; ?>" />
                                </th>
                                <td>
                                    <a href="?page=sfs-hr-employee-profile&employee_id=<?php echo (int) $inst->employee_id; ?>" style="text-decoration:none;">
                                        <strong><?php echo esc_html( $inst->employee_name ); ?></strong>
                                    </a>
                                    <br><small><?php echo esc_html( $inst->employee_code ); ?></small>
                                </td>
                                <td>
                                    <a href="?page=sfs-hr-loans&action=view&id=<?php echo (int) $inst->loan_id; ?>" style="text-decoration:none;">
                                        <strong><?php echo esc_html( $inst->loan_number ); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo (int) $inst->sequence; ?></td>
                                <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $inst->due_date ) ) ); ?></td>
                                <td><?php echo number_format( (float) $inst->amount_planned, 2 ); ?></td>
                                <td><?php echo number_format( (float) $inst->amount_paid, 2 ); ?></td>
                                <td><?php echo $this->get_payment_status_badge( $inst->status ); ?></td>
                                <td><?php echo number_format( (float) $inst->remaining_balance, 2 ); ?> <?php echo esc_html( $inst->currency ); ?></td>
                                <td>
                                    <?php if ( $inst->status === 'planned' || $inst->status === 'partial' ) : ?>
                                        <div class="row-actions" style="display:flex;gap:5px;">
                                            <form method="post" action="" style="display:inline-block;">
                                                <?php wp_nonce_field( 'sfs_hr_mark_installment_' . $inst->id ); ?>
                                                <input type="hidden" name="action" value="mark_installment_paid" />
                                                <input type="hidden" name="payment_id" value="<?php echo (int) $inst->id; ?>" />
                                                <input type="hidden" name="month" value="<?php echo esc_attr( $selected_month ); ?>" />
                                                <button type="submit" class="button button-small button-primary" title="<?php esc_attr_e( 'Mark as Paid', 'sfs-hr' ); ?>">
                                                    <?php esc_html_e( 'Paid', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                            <button type="button" class="button button-small" onclick="showPartialForm(<?php echo (int) $inst->id; ?>)" title="<?php esc_attr_e( 'Mark as Partial', 'sfs-hr' ); ?>">
                                                <?php esc_html_e( 'Partial', 'sfs-hr' ); ?>
                                            </button>
                                            <form method="post" action="" style="display:inline-block;">
                                                <?php wp_nonce_field( 'sfs_hr_mark_installment_' . $inst->id ); ?>
                                                <input type="hidden" name="action" value="mark_installment_skipped" />
                                                <input type="hidden" name="payment_id" value="<?php echo (int) $inst->id; ?>" />
                                                <input type="hidden" name="month" value="<?php echo esc_attr( $selected_month ); ?>" />
                                                <button type="submit" class="button button-small" title="<?php esc_attr_e( 'Skip Payment', 'sfs-hr' ); ?>"
                                                        onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to skip this payment?', 'sfs-hr' ); ?>');">
                                                    <?php esc_html_e( 'Skip', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                        </div>

                                        <!-- Partial Payment Form (Hidden by default) -->
                                        <div id="partial-form-<?php echo (int) $inst->id; ?>" style="display:none;margin-top:10px;padding:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;">
                                            <form method="post" action="">
                                                <?php wp_nonce_field( 'sfs_hr_mark_installment_' . $inst->id ); ?>
                                                <input type="hidden" name="action" value="mark_installment_partial" />
                                                <input type="hidden" name="payment_id" value="<?php echo (int) $inst->id; ?>" />
                                                <input type="hidden" name="month" value="<?php echo esc_attr( $selected_month ); ?>" />
                                                <label style="display:block;margin-bottom:5px;font-weight:600;">
                                                    <?php esc_html_e( 'Partial Amount:', 'sfs-hr' ); ?>
                                                </label>
                                                <input type="number" name="partial_amount" step="0.01" min="0.01"
                                                       max="<?php echo esc_attr( $inst->amount_planned ); ?>"
                                                       required style="width:120px;margin-right:5px;" />
                                                <button type="submit" class="button button-small button-primary">
                                                    <?php esc_html_e( 'Submit', 'sfs-hr' ); ?>
                                                </button>
                                                <button type="button" class="button button-small" onclick="hidePartialForm(<?php echo (int) $inst->id; ?>)">
                                                    <?php esc_html_e( 'Cancel', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                        </div>
                                    <?php else : ?>
                                        <span style="color:#999;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <script>
                function showPartialForm(id) {
                    document.getElementById('partial-form-' + id).style.display = 'block';
                }
                function hidePartialForm(id) {
                    document.getElementById('partial-form-' + id).style.display = 'none';
                }
            </script>
        <?php endif; ?>
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
        $badges = [
            'planned'  => '<span style="background:#ffc107;color:#000;padding:3px 8px;border-radius:3px;font-size:11px;">Planned</span>',
            'paid'     => '<span style="background:#28a745;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Paid</span>',
            'partial'  => '<span style="background:#ff8c00;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Partial</span>',
            'skipped'  => '<span style="background:#6c757d;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">Skipped</span>',
        ];

        return $badges[ $status ] ?? esc_html( $status );
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

            <div style="background:#fff;padding:20px;border:1px solid #ccc;border-radius:4px;margin-top:20px;">
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
                        <form method="post" action="" style="display:inline-block;margin-right:10px;">
                            <?php wp_nonce_field( 'sfs_hr_loan_approve_gm_' . $loan_id ); ?>
                            <input type="hidden" name="action" value="approve_gm" />
                            <input type="hidden" name="loan_id" value="<?php echo (int) $loan_id; ?>" />
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Approve (GM)', 'sfs-hr' ); ?></button>
                        </form>
                        <form method="post" action="" style="display:inline-block;" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to reject this loan?', 'sfs-hr' ); ?>');">
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

            <!-- History -->
            <?php if ( ! empty( $history ) ) : ?>
                <div style="background:#fff;padding:20px;border:1px solid #ccc;border-radius:4px;margin-top:20px;">
                    <h2><?php esc_html_e( 'Loan History', 'sfs-hr' ); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Date', 'sfs-hr' ); ?></th>
                                <th><?php esc_html_e( 'User', 'sfs-hr' ); ?></th>
                                <th><?php esc_html_e( 'Event', 'sfs-hr' ); ?></th>
                                <th><?php esc_html_e( 'Details', 'sfs-hr' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $history as $event ) : ?>
                                <tr>
                                    <td><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $event->created_at ) ) ); ?></td>
                                    <td><?php echo esc_html( $event->user_name ?: __( 'System', 'sfs-hr' ) ); ?></td>
                                    <td><strong><?php echo esc_html( str_replace( '_', ' ', ucwords( $event->event_type, '_' ) ) ); ?></strong></td>
                                    <td>
                                        <?php
                                        if ( $event->meta ) {
                                            $meta = json_decode( $event->meta, true );
                                            if ( is_array( $meta ) && ! empty( $meta ) ) {
                                                echo '<div style="font-size:12px;">';
                                                foreach ( $meta as $key => $value ) {
                                                    // Format key (convert snake_case to Title Case)
                                                    $label = ucwords( str_replace( '_', ' ', $key ) );
                                                    // Format value
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

    /**
     * Handle loan actions (approve, reject, etc.)
     */
    public function handle_loan_actions(): void {
        if ( ! isset( $_POST['action'] ) || ! current_user_can( 'sfs_hr.manage' ) ) {
            return;
        }

        $action = $_POST['action'];
        $loan_id = isset( $_POST['loan_id'] ) ? (int) $_POST['loan_id'] : 0;

        global $wpdb;
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';

        switch ( $action ) {
            case 'approve_gm':
                // Validate loan_id for this action
                if ( ! $loan_id ) {
                    return;
                }
                check_admin_referer( 'sfs_hr_loan_approve_gm_' . $loan_id );

                $wpdb->update( $loans_table, [
                    'status'          => 'pending_finance',
                    'approved_gm_by'  => get_current_user_id(),
                    'approved_gm_at'  => current_time( 'mysql' ),
                    'updated_at'      => current_time( 'mysql' ),
                ], [ 'id' => $loan_id ] );

                \SFS\HR\Modules\Loans\LoansModule::log_event( $loan_id, 'gm_approved', [
                    'status' => 'pending_gm → pending_finance',
                ] );

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
                    'updated_at'             => current_time( 'mysql' ),
                ], [ 'id' => $loan_id ] );

                // Generate schedule
                $this->generate_payment_schedule( $loan_id, $first_month, $installments, $installment_amount );

                \SFS\HR\Modules\Loans\LoansModule::log_event( $loan_id, 'finance_approved', [
                    'status'       => 'pending_finance → active',
                    'principal'    => $principal,
                    'installments' => $installments,
                ] );

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