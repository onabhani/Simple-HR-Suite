<?php
namespace SFS\HR\Modules\Expenses\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Expenses\Services\Expense_Service;
use SFS\HR\Modules\Expenses\Services\Expense_Category_Service;
use SFS\HR\Modules\Expenses\Services\Advance_Service;
use SFS\HR\Modules\Expenses\Services\Expense_Report_Service;

/**
 * Expense_Admin_Page (M10)
 *
 * Admin submenu with four tabs:
 *   1. Pending — claims + advances awaiting approval
 *   2. Categories — manage expense categories
 *   3. Settings — approval threshold, auto-approve, currency
 *   4. Reports — by-department, by-employee, policy violations + CSV export
 *
 * @since M10
 */
class Expense_Admin_Page {

    const MENU_SLUG = 'sfs-hr-expenses';

    public function hooks(): void {
        add_action( 'admin_menu', [ $this, 'menu' ], 25 );
        add_action( 'admin_post_sfs_hr_expense_settings_save', [ $this, 'handle_settings_save' ] );
        add_action( 'admin_post_sfs_hr_expense_category_save', [ $this, 'handle_category_save' ] );
        add_action( 'admin_post_sfs_hr_expense_csv_export',    [ $this, 'handle_csv_export' ] );
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __( 'Expenses', 'sfs-hr' ),
            __( 'Expenses', 'sfs-hr' ),
            'sfs_hr.view',
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'sfs_hr.view' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'pending';
        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e( 'Expense Management', 'sfs-hr' ); ?></h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( [
                    'pending'    => __( 'Pending', 'sfs-hr' ),
                    'categories' => __( 'Categories', 'sfs-hr' ),
                    'settings'   => __( 'Settings', 'sfs-hr' ),
                    'reports'    => __( 'Reports', 'sfs-hr' ),
                ] as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>"
                       class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ( ! empty( $_GET['ok'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>

            <div style="margin-top:20px;">
                <?php
                switch ( $tab ) {
                    case 'categories': $this->render_categories();  break;
                    case 'settings':   $this->render_settings();    break;
                    case 'reports':    $this->render_reports();     break;
                    case 'pending':
                    default:           $this->render_pending();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /* ───────── Pending tab ───────── */

    private function render_pending(): void {
        $manager_pending = Expense_Service::list_pending_for_approver( get_current_user_id(), 'pending_manager' );
        $finance_pending = Expense_Service::list_pending_for_approver( get_current_user_id(), 'pending_finance' );
        $advances_pending = Advance_Service::list_pending();
        $currency = Expense_Service::get_settings()['currency'];
        ?>
        <h2><?php esc_html_e( 'Claims — Pending Manager', 'sfs-hr' ); ?></h2>
        <?php $this->render_claims_table( $manager_pending, $currency ); ?>

        <h2 style="margin-top:30px;"><?php esc_html_e( 'Claims — Pending Finance', 'sfs-hr' ); ?></h2>
        <?php $this->render_claims_table( $finance_pending, $currency ); ?>

        <h2 style="margin-top:30px;"><?php esc_html_e( 'Cash Advances — Pending', 'sfs-hr' ); ?></h2>
        <?php if ( empty( $advances_pending ) ) : ?>
            <p><?php esc_html_e( 'No pending advances.', 'sfs-hr' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Ref', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Amount', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Purpose', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Requested', 'sfs-hr' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $advances_pending as $a ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $a['request_number'] ?? '—' ); ?></code></td>
                            <td>#<?php echo (int) $a['employee_id']; ?></td>
                            <td><?php echo esc_html( number_format( (float) $a['amount'], 2 ) ); ?> <?php echo esc_html( $a['currency'] ); ?></td>
                            <td><?php echo esc_html( $a['purpose'] ); ?></td>
                            <td><?php echo esc_html( $a['created_at'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e( 'Approve or reject via REST: POST /sfs-hr/v1/expenses/advances/{id}/approve (or /reject).', 'sfs-hr' ); ?></p>
        <?php endif; ?>
        <?php
    }

    private function render_claims_table( array $claims, string $currency ): void {
        if ( empty( $claims ) ) {
            echo '<p>' . esc_html__( 'No claims.', 'sfs-hr' ) . '</p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Ref', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Title', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Total', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Submitted', 'sfs-hr' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $claims as $c ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $c['request_number'] ?? '—' ); ?></code></td>
                        <td><?php echo esc_html( trim( ( $c['first_name'] ?? '' ) . ' ' . ( $c['last_name'] ?? '' ) ) ?: '#' . (int) $c['employee_id'] ); ?></td>
                        <td><?php echo esc_html( $c['title'] ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $c['total_amount'], 2 ) ); ?> <?php echo esc_html( $c['currency'] ?: $currency ); ?></td>
                        <td><?php echo esc_html( $c['submitted_at'] ?? '—' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ───────── Categories tab ───────── */

    private function render_categories(): void {
        $cats = Expense_Category_Service::list_all();
        ?>
        <h2><?php esc_html_e( 'Expense Categories', 'sfs-hr' ); ?></h2>
        <p><?php esc_html_e( 'Create or edit expense categories. Deactivated categories remain visible in historical data but cannot be used on new claims.', 'sfs-hr' ); ?></p>

        <h3><?php esc_html_e( 'Add / Edit Category', 'sfs-hr' ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:800px;">
            <input type="hidden" name="action" value="sfs_hr_expense_category_save" />
            <?php wp_nonce_field( 'sfs_hr_expense_category_save', '_sfs_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="code"><?php esc_html_e( 'Code', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" id="code" name="code" class="regular-text" required pattern="[a-z0-9_-]+" /></td>
                </tr>
                <tr>
                    <th><label for="name"><?php esc_html_e( 'Name', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" id="name" name="name" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="name_ar"><?php esc_html_e( 'Name (Arabic)', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" id="name_ar" name="name_ar" class="regular-text" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Limits', 'sfs-hr' ); ?></th>
                    <td>
                        <label><?php esc_html_e( 'Per-claim', 'sfs-hr' ); ?>:
                            <input type="number" step="0.01" min="0" name="per_claim_limit" style="width:140px;" />
                        </label>
                        &nbsp;&nbsp;
                        <label><?php esc_html_e( 'Monthly', 'sfs-hr' ); ?>:
                            <input type="number" step="0.01" min="0" name="monthly_limit" style="width:140px;" />
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Receipt required', 'sfs-hr' ); ?></th>
                    <td><label><input type="checkbox" name="receipt_required" value="1" checked /> <?php esc_html_e( 'Yes', 'sfs-hr' ); ?></label></td>
                </tr>
                <tr>
                    <th><label for="sort_order"><?php esc_html_e( 'Sort Order', 'sfs-hr' ); ?></label></th>
                    <td><input type="number" id="sort_order" name="sort_order" value="50" style="width:80px;" /></td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Category', 'sfs-hr' ) ); ?>
        </form>

        <h3><?php esc_html_e( 'Existing Categories', 'sfs-hr' ); ?></h3>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Code', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Name', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Per-claim', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Monthly', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Receipt?', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $cats as $c ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $c['code'] ); ?></code></td>
                        <td><?php echo esc_html( $c['name'] ); ?></td>
                        <td><?php echo $c['per_claim_limit'] !== null ? esc_html( number_format( (float) $c['per_claim_limit'], 2 ) ) : '—'; ?></td>
                        <td><?php echo $c['monthly_limit']   !== null ? esc_html( number_format( (float) $c['monthly_limit'], 2 ) )   : '—'; ?></td>
                        <td><?php echo (int) $c['receipt_required'] ? '✓' : '—'; ?></td>
                        <td><?php echo (int) $c['is_active'] ? esc_html__( 'Active', 'sfs-hr' ) : esc_html__( 'Disabled', 'sfs-hr' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ───────── Settings tab ───────── */

    private function render_settings(): void {
        $s = Expense_Service::get_settings();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
            <input type="hidden" name="action" value="sfs_hr_expense_settings_save" />
            <?php wp_nonce_field( 'sfs_hr_expense_settings_save', '_sfs_nonce' ); ?>
            <h2><?php esc_html_e( 'Approval & Payroll Settings', 'sfs-hr' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="currency"><?php esc_html_e( 'Default currency', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" id="currency" name="currency" value="<?php echo esc_attr( $s['currency'] ); ?>" maxlength="3" style="width:80px;" /></td>
                </tr>
                <tr>
                    <th><label for="manager_threshold"><?php esc_html_e( 'Manager approval threshold', 'sfs-hr' ); ?></label></th>
                    <td>
                        <input type="number" step="0.01" min="0" id="manager_threshold" name="manager_threshold" value="<?php echo esc_attr( $s['manager_threshold'] ); ?>" style="width:160px;" />
                        <p class="description"><?php esc_html_e( 'Claims above this amount also require finance approval after manager sign-off.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="auto_approve_below"><?php esc_html_e( 'Auto-approve below', 'sfs-hr' ); ?></label></th>
                    <td>
                        <input type="number" step="0.01" min="0" id="auto_approve_below" name="auto_approve_below" value="<?php echo esc_attr( $s['auto_approve_below'] ); ?>" style="width:160px;" />
                        <p class="description"><?php esc_html_e( 'Claims with total under this amount are auto-approved on submission. Set to 0 to disable.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="advance_payroll_cap"><?php esc_html_e( 'Advance deduction cap (per payroll run)', 'sfs-hr' ); ?></label></th>
                    <td>
                        <input type="number" step="0.01" min="0" id="advance_payroll_cap" name="advance_payroll_cap" value="<?php echo esc_attr( $s['advance_payroll_cap'] ?? 0 ); ?>" style="width:160px;" />
                        <p class="description"><?php esc_html_e( 'Maximum amount to deduct per employee per payroll run. 0 = recover entire outstanding balance.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Settings', 'sfs-hr' ) ); ?>
        </form>
        <?php
    }

    /* ───────── Reports tab ───────── */

    private function render_reports(): void {
        $start = isset( $_GET['start_date'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['start_date'] ) ) : gmdate( 'Y-m-01' );
        $end   = isset( $_GET['end_date'] )   ? sanitize_text_field( (string) wp_unslash( $_GET['end_date'] ) )   : gmdate( 'Y-m-d' );
        $dept  = isset( $_GET['dept_id'] )    ? (int) $_GET['dept_id'] : 0;

        $valid = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end );

        ?>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
            <input type="hidden" name="tab"  value="reports" />
            <label><?php esc_html_e( 'From', 'sfs-hr' ); ?>: <input type="date" name="start_date" value="<?php echo esc_attr( $start ); ?>" /></label>
            <label><?php esc_html_e( 'To', 'sfs-hr' ); ?>: <input type="date" name="end_date" value="<?php echo esc_attr( $end ); ?>" /></label>
            <label><?php esc_html_e( 'Dept', 'sfs-hr' ); ?>: <input type="number" min="0" name="dept_id" value="<?php echo esc_attr( $dept ); ?>" style="width:90px;" /></label>
            <?php submit_button( __( 'Apply', 'sfs-hr' ), 'secondary', '', false ); ?>
        </form>

        <?php if ( ! $valid ) : ?>
            <p><?php esc_html_e( 'Select a valid date range.', 'sfs-hr' ); ?></p>
            <?php return;
        endif;

        $dept_id = $dept > 0 ? $dept : null;
        $by_emp  = Expense_Report_Service::by_employee( $start, $end, $dept_id );
        $by_cat  = Expense_Report_Service::by_department( $start, $end, $dept_id );
        $viol    = Expense_Report_Service::policy_violations( $start, $end );
        ?>

        <h2><?php esc_html_e( 'By Employee', 'sfs-hr' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:10px 0;">
            <input type="hidden" name="action" value="sfs_hr_expense_csv_export" />
            <input type="hidden" name="start_date" value="<?php echo esc_attr( $start ); ?>" />
            <input type="hidden" name="end_date"   value="<?php echo esc_attr( $end ); ?>" />
            <input type="hidden" name="dept_id"    value="<?php echo esc_attr( $dept ); ?>" />
            <?php wp_nonce_field( 'sfs_hr_expense_csv_export', '_sfs_nonce' ); ?>
            <button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'sfs-hr' ); ?></button>
        </form>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Claims', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Approved', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Pending', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Rejected', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Total', 'sfs-hr' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $by_emp as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( $r['employee_code'] . ' — ' . trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) ) ); ?></td>
                        <td><?php echo (int) $r['claim_count']; ?></td>
                        <td><?php echo esc_html( number_format( (float) $r['approved_amount'], 2 ) ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $r['pending_amount'], 2 ) ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $r['rejected_amount'], 2 ) ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $r['total_amount'], 2 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $by_emp ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No data for selected range.', 'sfs-hr' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 style="margin-top:30px;"><?php esc_html_e( 'By Category (per dept)', 'sfs-hr' ); ?></h2>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Dept', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Category', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Claims', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Submitted', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Approved', 'sfs-hr' ); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ( $by_cat as $r ) : ?>
                    <tr>
                        <td>#<?php echo (int) $r['dept_id']; ?></td>
                        <td><?php echo esc_html( $r['category_name'] ); ?></td>
                        <td><?php echo (int) $r['claim_count']; ?></td>
                        <td><?php echo esc_html( number_format( (float) $r['total_submitted'], 2 ) ); ?></td>
                        <td><?php echo esc_html( number_format( (float) $r['total_approved'], 2 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $by_cat ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No data.', 'sfs-hr' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 style="margin-top:30px;"><?php esc_html_e( 'Policy Violations', 'sfs-hr' ); ?></h2>
        <?php if ( empty( $viol ) ) : ?>
            <p><?php esc_html_e( 'No violations detected for this period.', 'sfs-hr' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e( 'Type', 'sfs-hr' ); ?></th><th><?php esc_html_e( 'Details', 'sfs-hr' ); ?></th></tr></thead>
                <tbody>
                    <?php foreach ( $viol as $v ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $v['violation'] ); ?></code></td>
                            <td><pre style="margin:0;white-space:pre-wrap;font-size:11px;"><?php echo esc_html( wp_json_encode( $v, JSON_PRETTY_PRINT ) ); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /* ───────── Handlers ───────── */

    public function handle_settings_save(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) { wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) ); }
        check_admin_referer( 'sfs_hr_expense_settings_save', '_sfs_nonce' );

        $settings = [
            'currency'             => strtoupper( substr( sanitize_text_field( (string) ( $_POST['currency'] ?? 'SAR' ) ), 0, 3 ) ),
            'manager_threshold'    => max( 0.0, (float) ( $_POST['manager_threshold'] ?? 0 ) ),
            'auto_approve_below'   => max( 0.0, (float) ( $_POST['auto_approve_below'] ?? 0 ) ),
            'advance_payroll_cap'  => max( 0.0, (float) ( $_POST['advance_payroll_cap'] ?? 0 ) ),
        ];
        update_option( Expense_Service::SETTINGS_OPTION, $settings );

        wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'settings', 'ok' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_category_save(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) { wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) ); }
        check_admin_referer( 'sfs_hr_expense_category_save', '_sfs_nonce' );

        Expense_Category_Service::upsert( [
            'code'             => sanitize_key( (string) ( $_POST['code'] ?? '' ) ),
            'name'             => sanitize_text_field( (string) ( $_POST['name'] ?? '' ) ),
            'name_ar'          => sanitize_text_field( (string) ( $_POST['name_ar'] ?? '' ) ),
            'receipt_required' => ! empty( $_POST['receipt_required'] ),
            'per_claim_limit'  => (string) ( $_POST['per_claim_limit'] ?? '' ),
            'monthly_limit'    => (string) ( $_POST['monthly_limit'] ?? '' ),
            'sort_order'       => (int) ( $_POST['sort_order'] ?? 0 ),
            'is_active'        => 1,
        ] );

        wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'categories', 'ok' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_csv_export(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) { wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) ); }
        check_admin_referer( 'sfs_hr_expense_csv_export', '_sfs_nonce' );

        $start = sanitize_text_field( (string) ( $_POST['start_date'] ?? '' ) );
        $end   = sanitize_text_field( (string) ( $_POST['end_date']   ?? '' ) );
        $dept  = (int) ( $_POST['dept_id'] ?? 0 );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
            wp_die( esc_html__( 'Invalid date range.', 'sfs-hr' ) );
        }

        $csv = Expense_Report_Service::export_by_employee_csv( $start, $end, $dept > 0 ? $dept : null );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="expenses-' . $start . '-to-' . $end . '.csv"' );
        echo $csv;
        exit;
    }
}
