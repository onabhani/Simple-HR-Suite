<?php
namespace SFS\HR\Modules\Resignation;

use SFS\HR\Core\Helpers;

if (!defined('ABSPATH')) { exit; }

class ResignationModule {

    public function hooks(): void {
        add_action('admin_menu', [$this, 'menu']);

        // Register roles and capabilities
        add_action('init', [$this, 'register_roles_and_caps']);

        // Form handlers
        add_action('admin_post_sfs_hr_resignation_submit',  [$this, 'handle_submit']);
        add_action('admin_post_sfs_hr_resignation_approve', [$this, 'handle_approve']);
        add_action('admin_post_sfs_hr_resignation_reject',  [$this, 'handle_reject']);
        add_action('admin_post_sfs_hr_resignation_cancel',  [$this, 'handle_cancel']);
        add_action('admin_post_sfs_hr_final_exit_update',   [$this, 'handle_final_exit_update']);
        add_action('admin_post_sfs_hr_resignation_settings', [$this, 'handle_settings']);

        // AJAX handlers
        add_action('wp_ajax_sfs_hr_get_resignation', [$this, 'ajax_get_resignation']);

        // Daily cron to terminate employees after last working day
        add_action('sfs_hr_daily_resignation_check', [$this, 'process_expired_resignations']);
        if (!wp_next_scheduled('sfs_hr_daily_resignation_check')) {
            wp_schedule_event(time(), 'daily', 'sfs_hr_daily_resignation_check');
        }

        // Shortcodes for employee self-service
        add_shortcode('sfs_hr_resignation_submit', [$this, 'shortcode_submit_form']);
        add_shortcode('sfs_hr_my_resignations', [$this, 'shortcode_my_resignations']);
    }

    public function register_roles_and_caps(): void {
        // Add capability to Administrator role
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('sfs_hr_resignation_finance_approve');
        }

        // Create Finance Approver role if it doesn't exist
        if (!get_role('sfs_hr_finance_approver')) {
            add_role(
                'sfs_hr_finance_approver',
                __('HR Finance Approver', 'sfs-hr'),
                [
                    'read'                                  => true,  // Basic WordPress access
                    'sfs_hr_resignation_finance_approve'    => true,  // Can approve resignations at finance stage
                    'sfs_hr.view'                           => true,  // Can view HR data
                ]
            );
        } else {
            // Add resignation finance approve capability to existing role
            $finance_role = get_role('sfs_hr_finance_approver');
            if ($finance_role && !$finance_role->has_cap('sfs_hr_resignation_finance_approve')) {
                $finance_role->add_cap('sfs_hr_resignation_finance_approve');
            }
        }
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __('Resignations', 'sfs-hr'),
            __('Resignations', 'sfs-hr'),
            'sfs_hr.view',
            'sfs-hr-resignations',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        $tab = $_GET['tab'] ?? 'resignations';

        echo '<div class="wrap sfs-hr-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Resignations', 'sfs-hr') . '</h1>';
        Helpers::render_admin_nav();
        echo '<hr class="wp-header-end" />';

        // Tab navigation (only show if user can manage settings)
        if (current_user_can('sfs_hr.manage')) {
            $this->output_resignation_styles();
            ?>
            <div class="sfs-hr-resignation-main-tabs">
                <a href="?page=sfs-hr-resignations&tab=resignations" class="sfs-main-tab<?php echo $tab === 'resignations' ? ' active' : ''; ?>">
                    <?php esc_html_e('Resignations', 'sfs-hr'); ?>
                </a>
                <a href="?page=sfs-hr-resignations&tab=settings" class="sfs-main-tab<?php echo $tab === 'settings' ? ' active' : ''; ?>">
                    <?php esc_html_e('Settings', 'sfs-hr'); ?>
                </a>
            </div>
            <?php
        }

        switch ($tab) {
            case 'settings':
                if (current_user_can('sfs_hr.manage')) {
                    $this->render_settings_tab();
                }
                break;
            case 'resignations':
            default:
                $this->render_resignations_tab();
                break;
        }

        echo '</div>';
    }

    private function output_resignation_styles(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
        <style>
            /* Main Tabs */
            .sfs-hr-resignation-main-tabs {
                display: flex;
                gap: 8px;
                margin-bottom: 20px;
            }
            .sfs-hr-resignation-main-tabs .sfs-main-tab {
                padding: 10px 20px;
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                text-decoration: none;
                color: #50575e;
                font-weight: 500;
            }
            .sfs-hr-resignation-main-tabs .sfs-main-tab:hover {
                background: #fff;
                border-color: #2271b1;
                color: #2271b1;
            }
            .sfs-hr-resignation-main-tabs .sfs-main-tab.active {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
            }

            /* Toolbar */
            .sfs-hr-resignation-toolbar {
                background: #fff;
                border: 1px solid #e2e4e7;
                border-radius: 8px;
                padding: 16px;
                margin-bottom: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .sfs-hr-resignation-toolbar form {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                align-items: center;
                margin: 0;
            }
            .sfs-hr-resignation-toolbar input[type="search"] {
                height: 36px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                padding: 0 12px;
                font-size: 13px;
                min-width: 200px;
            }
            .sfs-hr-resignation-toolbar .button {
                height: 36px;
                line-height: 34px;
                padding: 0 16px;
            }

            /* Status Tabs */
            .sfs-hr-resignation-tabs {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 20px;
            }
            .sfs-hr-resignation-tabs .sfs-tab {
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
            .sfs-hr-resignation-tabs .sfs-tab:hover {
                background: #fff;
                border-color: #2271b1;
                color: #2271b1;
            }
            .sfs-hr-resignation-tabs .sfs-tab.active {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
            }
            .sfs-hr-resignation-tabs .sfs-tab .count {
                display: inline-block;
                background: rgba(0,0,0,0.1);
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 11px;
                margin-left: 6px;
            }
            .sfs-hr-resignation-tabs .sfs-tab.active .count {
                background: rgba(255,255,255,0.25);
            }

            /* Table Card */
            .sfs-hr-resignation-table-wrap {
                background: #fff;
                border: 1px solid #e2e4e7;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .sfs-hr-resignation-table-wrap .table-header {
                padding: 16px 20px;
                border-bottom: 1px solid #f0f0f1;
                background: #f9fafb;
            }
            .sfs-hr-resignation-table-wrap .table-header h3 {
                margin: 0;
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
            }
            .sfs-hr-resignation-table {
                width: 100%;
                border-collapse: collapse;
                margin: 0;
            }
            .sfs-hr-resignation-table th {
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
            .sfs-hr-resignation-table td {
                padding: 14px 16px;
                font-size: 13px;
                border-bottom: 1px solid #f0f0f1;
                vertical-align: middle;
            }
            .sfs-hr-resignation-table tbody tr:hover {
                background: #f9fafb;
            }
            .sfs-hr-resignation-table tbody tr:last-child td {
                border-bottom: none;
            }
            .sfs-hr-resignation-table .emp-name {
                display: block;
                font-weight: 500;
                color: #1d2327;
            }
            .sfs-hr-resignation-table .emp-code {
                display: block;
                font-size: 11px;
                color: #787c82;
                margin-top: 2px;
            }
            .sfs-hr-resignation-table .empty-state {
                text-align: center;
                padding: 40px 20px;
                color: #787c82;
            }
            .sfs-hr-resignation-table .empty-state p {
                margin: 0;
                font-size: 14px;
            }

            /* Type and Status Pills */
            .sfs-hr-pill {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 500;
                line-height: 1.4;
            }
            .sfs-hr-pill--final-exit {
                background: #e8def8;
                color: #5e35b1;
            }
            .sfs-hr-pill--regular {
                background: #eceff1;
                color: #546e7a;
            }
            .sfs-hr-pill--pending {
                background: #fff3e0;
                color: #e65100;
            }
            .sfs-hr-pill--approved {
                background: #e8f5e9;
                color: #2e7d32;
            }
            .sfs-hr-pill--rejected {
                background: #ffebee;
                color: #c62828;
            }
            .sfs-hr-pill--cancelled {
                background: #fafafa;
                color: #757575;
            }
            .sfs-hr-pill--completed {
                background: #e3f2fd;
                color: #1565c0;
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
            }
            .sfs-hr-action-btn:hover {
                background: #fff;
                border-color: #2271b1;
            }

            /* Pagination */
            .sfs-hr-resignation-pagination {
                padding: 16px 20px;
                border-top: 1px solid #e2e4e7;
                background: #f9fafb;
                text-align: center;
            }
            .sfs-hr-resignation-pagination .page-numbers {
                display: inline-block;
                padding: 6px 12px;
                margin: 0 2px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                text-decoration: none;
                color: #2271b1;
                font-size: 13px;
            }
            .sfs-hr-resignation-pagination .page-numbers.current {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
            }

            /* Mobile Modal */
            .sfs-hr-resignation-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 100000;
            }
            .sfs-hr-resignation-modal.active {
                display: block;
            }
            .sfs-hr-resignation-modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
            }
            .sfs-hr-resignation-modal-content {
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
            .sfs-hr-resignation-modal.active .sfs-hr-resignation-modal-content {
                transform: translateY(0);
            }
            .sfs-hr-resignation-modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 16px;
                border-bottom: 1px solid #e2e4e7;
            }
            .sfs-hr-resignation-modal-header h3 {
                margin: 0;
                font-size: 18px;
                color: #1d2327;
            }
            .sfs-hr-resignation-modal-close {
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
            .sfs-hr-resignation-modal-row {
                display: flex;
                justify-content: space-between;
                padding: 12px 0;
                border-bottom: 1px solid #f0f0f1;
            }
            .sfs-hr-resignation-modal-row:last-child {
                border-bottom: none;
            }
            .sfs-hr-resignation-modal-label {
                color: #50575e;
                font-size: 13px;
            }
            .sfs-hr-resignation-modal-value {
                font-weight: 500;
                color: #1d2327;
                font-size: 13px;
                text-align: right;
            }

            /* Mobile Responsive */
            @media screen and (max-width: 782px) {
                .sfs-hr-resignation-toolbar form {
                    flex-direction: column;
                    align-items: stretch;
                }
                .sfs-hr-resignation-toolbar input[type="search"] {
                    width: 100%;
                    min-width: auto;
                }
                .sfs-hr-resignation-tabs {
                    overflow-x: auto;
                    flex-wrap: nowrap;
                    padding-bottom: 8px;
                    -webkit-overflow-scrolling: touch;
                }
                .sfs-hr-resignation-tabs .sfs-tab {
                    flex-shrink: 0;
                    padding: 6px 12px;
                    font-size: 12px;
                }
                .sfs-hr-resignation-main-tabs {
                    flex-wrap: wrap;
                }
                .hide-mobile {
                    display: none !important;
                }
                .sfs-hr-resignation-table th,
                .sfs-hr-resignation-table td {
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

    private function render_resignations_tab(): void {
        global $wpdb;

        $this->output_resignation_styles();

        $current_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'pending';
        $page   = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $pp     = 20;
        $offset = ($page - 1) * $pp;

        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        // Department manager scoping
        $dept_where = '';
        $dept_params = [];
        if (!current_user_can('sfs_hr.manage')) {
            $managed_depts = $this->manager_dept_ids_for_user(get_current_user_id());
            if (!empty($managed_depts)) {
                $placeholders = implode(',', array_fill(0, count($managed_depts), '%d'));
                $dept_where = " AND e.dept_id IN ($placeholders)";
                $dept_params = array_map('intval', $managed_depts);
            }
        }

        // Search condition
        $search_where = '';
        $search_params = [];
        if ($search !== '') {
            $search_where = " AND (e.first_name LIKE %s OR e.last_name LIKE %s OR e.employee_code LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $search_params = [$like, $like, $like];
        }

        // Get counts for each status tab
        $status_tabs = [
            'pending'    => __('Pending', 'sfs-hr'),
            'approved'   => __('Approved', 'sfs-hr'),
            'rejected'   => __('Rejected', 'sfs-hr'),
            'cancelled'  => __('Cancelled', 'sfs-hr'),
            'completed'  => __('Completed', 'sfs-hr'),
            'final_exit' => __('Final Exit', 'sfs-hr'),
        ];

        $counts = [];
        foreach (array_keys($status_tabs) as $st) {
            if ($st === 'final_exit') {
                $count_sql = "SELECT COUNT(*) FROM $table r JOIN $emp_t e ON e.id = r.employee_id WHERE r.resignation_type = 'final_exit' $dept_where $search_where";
            } else {
                $count_sql = "SELECT COUNT(*) FROM $table r JOIN $emp_t e ON e.id = r.employee_id WHERE r.status = %s $dept_where $search_where";
            }
            $count_params = ($st === 'final_exit') ? array_merge($dept_params, $search_params) : array_merge([$st], $dept_params, $search_params);
            $counts[$st] = empty($count_params) ? (int)$wpdb->get_var($count_sql) : (int)$wpdb->get_var($wpdb->prepare($count_sql, ...$count_params));
        }

        // Build query for current tab
        $where  = '1=1';
        $params = [];

        if (in_array($current_status, ['pending', 'approved', 'rejected', 'cancelled', 'completed'], true)) {
            $where   .= " AND r.status = %s";
            $params[] = $current_status;
        } elseif ($current_status === 'final_exit') {
            $where   .= " AND r.resignation_type = %s";
            $params[] = 'final_exit';
        }

        $where .= $dept_where;
        $params = array_merge($params, $dept_params);

        $where .= $search_where;
        $params = array_merge($params, $search_params);

        $sql_total = "SELECT COUNT(*) FROM $table r JOIN $emp_t e ON e.id = r.employee_id WHERE $where";
        $total = empty($params) ? (int)$wpdb->get_var($sql_total) : (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params));

        $sql = "SELECT r.*, e.employee_code, e.first_name, e.last_name, e.user_id AS emp_user_id, e.dept_id
                FROM $table r
                JOIN $emp_t e ON e.id = r.employee_id
                WHERE $where
                ORDER BY r.id DESC
                LIMIT %d OFFSET %d";

        $params_all = array_merge($params, [$pp, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_all), ARRAY_A);

        $total_pages = max(1, (int)ceil($total / $pp));
        ?>

        <!-- Search Toolbar -->
        <div class="sfs-hr-resignation-toolbar">
            <form method="get">
                <input type="hidden" name="page" value="sfs-hr-resignations" />
                <input type="hidden" name="tab" value="resignations" />
                <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>" />
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search employee name or code...', 'sfs-hr'); ?>" />
                <button type="submit" class="button"><?php esc_html_e('Search', 'sfs-hr'); ?></button>
                <?php if ($search !== ''): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-resignations&tab=resignations&status=' . $current_status)); ?>" class="button"><?php esc_html_e('Clear', 'sfs-hr'); ?></a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Status Tabs -->
        <div class="sfs-hr-resignation-tabs">
            <?php foreach ($status_tabs as $key => $label): ?>
                <?php
                $url = add_query_arg([
                    'page'   => 'sfs-hr-resignations',
                    'tab'    => 'resignations',
                    'status' => $key,
                    's'      => $search !== '' ? $search : null,
                ], admin_url('admin.php'));
                $url = remove_query_arg('paged', $url);
                $classes = 'sfs-tab' . ($key === $current_status ? ' active' : '');
                ?>
                <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($classes); ?>">
                    <?php echo esc_html($label); ?>
                    <span class="count"><?php echo esc_html($counts[$key]); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Table Card -->
        <div class="sfs-hr-resignation-table-wrap">
            <div class="table-header">
                <h3><?php echo esc_html($status_tabs[$current_status] ?? __('Resignations', 'sfs-hr')); ?> (<?php echo esc_html($total); ?>)</h3>
            </div>

            <table class="sfs-hr-resignation-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Type', 'sfs-hr'); ?></th>
                        <th class="hide-mobile"><?php esc_html_e('Resignation Date', 'sfs-hr'); ?></th>
                        <th class="hide-mobile"><?php esc_html_e('Last Working Day', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th class="hide-mobile"><?php esc_html_e('Reason', 'sfs-hr'); ?></th>
                        <th class="show-mobile" style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                <p><?php esc_html_e('No resignations found.', 'sfs-hr'); ?></p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $idx => $row): ?>
                            <tr data-id="<?php echo esc_attr($row['id']); ?>"
                                data-employee="<?php echo esc_attr($row['first_name'] . ' ' . $row['last_name']); ?>"
                                data-code="<?php echo esc_attr($row['employee_code']); ?>"
                                data-type="<?php echo esc_attr($row['resignation_type'] ?? 'regular'); ?>"
                                data-date="<?php echo esc_attr($row['resignation_date']); ?>"
                                data-lwd="<?php echo esc_attr($row['last_working_day'] ?: 'N/A'); ?>"
                                data-notice="<?php echo esc_attr($row['notice_period_days']); ?>"
                                data-status="<?php echo esc_attr($row['status']); ?>"
                                data-level="<?php echo esc_attr($row['approval_level'] ?? 1); ?>"
                                data-reason="<?php echo esc_attr($row['reason']); ?>">
                                <td>
                                    <span class="emp-name"><?php echo esc_html($row['first_name'] . ' ' . $row['last_name']); ?></span>
                                    <span class="emp-code"><?php echo esc_html($row['employee_code']); ?></span>
                                    <?php
                                    if (class_exists('\SFS\HR\Modules\Loans\Admin\DashboardWidget')) {
                                        echo \SFS\HR\Modules\Loans\Admin\DashboardWidget::render_employee_loan_badge($row['employee_id']);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $type = $row['resignation_type'] ?? 'regular';
                                    if ($type === 'final_exit') {
                                        echo '<span class="sfs-hr-pill sfs-hr-pill--final-exit">' . esc_html__('Final Exit', 'sfs-hr') . '</span>';
                                    } else {
                                        echo '<span class="sfs-hr-pill sfs-hr-pill--regular">' . esc_html__('Regular', 'sfs-hr') . '</span>';
                                    }
                                    ?>
                                </td>
                                <td class="hide-mobile"><?php echo esc_html($row['resignation_date'] ?: 'N/A'); ?></td>
                                <td class="hide-mobile"><?php echo esc_html($row['last_working_day'] ?: 'N/A'); ?></td>
                                <td><?php echo $this->render_status_pill($row['status'], intval($row['approval_level'] ?? 1)); ?></td>
                                <td class="hide-mobile"><?php echo esc_html(wp_trim_words($row['reason'], 8, '...')); ?></td>
                                <td class="hide-mobile">
                                    <button type="button" class="sfs-hr-action-btn" onclick="showResignationDetails(<?php echo esc_attr($row['id']); ?>);" title="<?php esc_attr_e('View Details', 'sfs-hr'); ?>">👁</button>
                                </td>
                                <td class="show-mobile">
                                    <button type="button" class="sfs-hr-action-btn sfs-mobile-detail-btn" title="<?php esc_attr_e('View Details', 'sfs-hr'); ?>">›</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="sfs-hr-resignation-pagination">
                    <?php
                    echo paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $total_pages,
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Mobile Slide-up Modal -->
        <div id="sfs-hr-resignation-modal" class="sfs-hr-resignation-modal">
            <div class="sfs-hr-resignation-modal-overlay" onclick="closeResignationModal();"></div>
            <div class="sfs-hr-resignation-modal-content">
                <div class="sfs-hr-resignation-modal-header">
                    <h3><?php esc_html_e('Resignation Details', 'sfs-hr'); ?></h3>
                    <button type="button" class="sfs-hr-resignation-modal-close" onclick="closeResignationModal();">×</button>
                </div>
                <div id="sfs-hr-resignation-modal-body">
                    <!-- Content loaded dynamically -->
                </div>
                <div id="sfs-hr-resignation-modal-actions" style="margin-top:20px;display:flex;flex-wrap:wrap;gap:8px;">
                    <!-- Actions loaded dynamically -->
                </div>
            </div>
        </div>

        <!-- Form Modals -->
        <div id="approve-modal" class="sfs-hr-resignation-modal">
            <div class="sfs-hr-resignation-modal-overlay" onclick="hideApproveModal();"></div>
            <div class="sfs-hr-resignation-modal-content">
                <div class="sfs-hr-resignation-modal-header">
                    <h3><?php esc_html_e('Approve Resignation', 'sfs-hr'); ?></h3>
                    <button type="button" class="sfs-hr-resignation-modal-close" onclick="hideApproveModal();">×</button>
                </div>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_resignation_approve'); ?>
                    <input type="hidden" name="action" value="sfs_hr_resignation_approve">
                    <input type="hidden" name="resignation_id" id="approve-resignation-id">
                    <p>
                        <label><?php esc_html_e('Note (optional):', 'sfs-hr'); ?></label><br>
                        <textarea name="note" rows="4" style="width:100%;border:1px solid #dcdcde;border-radius:4px;padding:8px;"></textarea>
                    </p>
                    <p style="display:flex;gap:8px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Approve', 'sfs-hr'); ?></button>
                        <button type="button" onclick="hideApproveModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <div id="reject-modal" class="sfs-hr-resignation-modal">
            <div class="sfs-hr-resignation-modal-overlay" onclick="hideRejectModal();"></div>
            <div class="sfs-hr-resignation-modal-content">
                <div class="sfs-hr-resignation-modal-header">
                    <h3><?php esc_html_e('Reject Resignation', 'sfs-hr'); ?></h3>
                    <button type="button" class="sfs-hr-resignation-modal-close" onclick="hideRejectModal();">×</button>
                </div>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_resignation_reject'); ?>
                    <input type="hidden" name="action" value="sfs_hr_resignation_reject">
                    <input type="hidden" name="resignation_id" id="reject-resignation-id">
                    <p>
                        <label><?php esc_html_e('Reason for rejection:', 'sfs-hr'); ?></label><br>
                        <textarea name="note" rows="4" style="width:100%;border:1px solid #dcdcde;border-radius:4px;padding:8px;" required></textarea>
                    </p>
                    <p style="display:flex;gap:8px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>
                        <button type="button" onclick="hideRejectModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <div id="cancel-modal" class="sfs-hr-resignation-modal">
            <div class="sfs-hr-resignation-modal-overlay" onclick="hideCancelModal();"></div>
            <div class="sfs-hr-resignation-modal-content">
                <div class="sfs-hr-resignation-modal-header">
                    <h3><?php esc_html_e('Cancel Resignation', 'sfs-hr'); ?></h3>
                    <button type="button" class="sfs-hr-resignation-modal-close" onclick="hideCancelModal();">×</button>
                </div>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_resignation_cancel'); ?>
                    <input type="hidden" name="action" value="sfs_hr_resignation_cancel">
                    <input type="hidden" name="resignation_id" id="cancel-resignation-id">
                    <p style="color:#dc3545;font-weight:500;">
                        <?php esc_html_e('Are you sure you want to cancel this resignation?', 'sfs-hr'); ?>
                    </p>
                    <p>
                        <label><?php esc_html_e('Reason for cancellation:', 'sfs-hr'); ?></label><br>
                        <textarea name="note" rows="4" style="width:100%;border:1px solid #dcdcde;border-radius:4px;padding:8px;" required></textarea>
                    </p>
                    <p style="display:flex;gap:8px;">
                        <button type="submit" class="button" style="background:#dc3545;border-color:#dc3545;color:#fff;"><?php esc_html_e('Cancel Resignation', 'sfs-hr'); ?></button>
                        <button type="button" onclick="hideCancelModal();" class="button"><?php esc_html_e('Close', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <div id="final-exit-modal" class="sfs-hr-resignation-modal">
            <div class="sfs-hr-resignation-modal-overlay" onclick="hideFinalExitModal();"></div>
            <div class="sfs-hr-resignation-modal-content" style="max-height:90vh;">
                <div class="sfs-hr-resignation-modal-header">
                    <h3><?php esc_html_e('Final Exit Management', 'sfs-hr'); ?></h3>
                    <button type="button" class="sfs-hr-resignation-modal-close" onclick="hideFinalExitModal();">×</button>
                </div>
                <div id="final-exit-content">
                    <p><?php esc_html_e('Loading...', 'sfs-hr'); ?></p>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var modal = document.getElementById('sfs-hr-resignation-modal');
            var modalBody = document.getElementById('sfs-hr-resignation-modal-body');
            var modalActions = document.getElementById('sfs-hr-resignation-modal-actions');

            // Mobile detail buttons
            document.querySelectorAll('.sfs-mobile-detail-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var row = this.closest('tr');
                    openMobileModal(row);
                });
            });

            function openMobileModal(row) {
                var data = row.dataset;
                var typeLabel = data.type === 'final_exit' ? '<?php echo esc_js(__('Final Exit', 'sfs-hr')); ?>' : '<?php echo esc_js(__('Regular', 'sfs-hr')); ?>';
                var typePill = data.type === 'final_exit'
                    ? '<span class="sfs-hr-pill sfs-hr-pill--final-exit">' + typeLabel + '</span>'
                    : '<span class="sfs-hr-pill sfs-hr-pill--regular">' + typeLabel + '</span>';

                var html = '';
                html += '<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php echo esc_js(__('Employee', 'sfs-hr')); ?></span><span class="sfs-hr-resignation-modal-value">' + data.employee + '</span></div>';
                html += '<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php echo esc_js(__('Employee Code', 'sfs-hr')); ?></span><span class="sfs-hr-resignation-modal-value">' + data.code + '</span></div>';
                html += '<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php echo esc_js(__('Type', 'sfs-hr')); ?></span><span class="sfs-hr-resignation-modal-value">' + typePill + '</span></div>';
                html += '<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php echo esc_js(__('Resignation Date', 'sfs-hr')); ?></span><span class="sfs-hr-resignation-modal-value">' + (data.date || 'N/A') + '</span></div>';
                html += '<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php echo esc_js(__('Last Working Day', 'sfs-hr')); ?></span><span class="sfs-hr-resignation-modal-value">' + data.lwd + '</span></div>';
                html += '<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php echo esc_js(__('Notice Period', 'sfs-hr')); ?></span><span class="sfs-hr-resignation-modal-value">' + data.notice + ' <?php echo esc_js(__('days', 'sfs-hr')); ?></span></div>';
                html += '<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php echo esc_js(__('Reason', 'sfs-hr')); ?></span><span class="sfs-hr-resignation-modal-value" style="max-width:200px;word-wrap:break-word;">' + (data.reason || '-') + '</span></div>';

                modalBody.innerHTML = html;

                // Load full details via AJAX to get action buttons
                loadResignationActions(data.id);

                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            function loadResignationActions(id) {
                modalActions.innerHTML = '<span style="color:#787c82;"><?php echo esc_js(__('Loading...', 'sfs-hr')); ?></span>';
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sfs_hr_get_resignation',
                        resignation_id: id,
                        nonce: '<?php echo wp_create_nonce('sfs_hr_resignation_ajax'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            modalActions.innerHTML = buildActionButtons(response.data);
                        } else {
                            modalActions.innerHTML = '';
                        }
                    },
                    error: function() {
                        modalActions.innerHTML = '';
                    }
                });
            }

            function buildActionButtons(data) {
                var html = '';
                if (data.status === 'pending' && data.can_approve) {
                    html += '<button type="button" class="button button-primary" onclick="closeResignationModal(); showApproveModal(' + data.id + ');"><?php echo esc_js(__('Approve', 'sfs-hr')); ?></button>';
                    html += '<button type="button" class="button" onclick="closeResignationModal(); showRejectModal(' + data.id + ');"><?php echo esc_js(__('Reject', 'sfs-hr')); ?></button>';
                }
                if ((data.status === 'pending' || data.status === 'approved') && data.can_approve) {
                    html += '<button type="button" class="button" style="background:#dc3545;border-color:#dc3545;color:#fff;" onclick="closeResignationModal(); showCancelModal(' + data.id + ');"><?php echo esc_js(__('Cancel', 'sfs-hr')); ?></button>';
                }
                if (data.resignation_type === 'final_exit' && data.approval_level == 4 && data.can_manage) {
                    html += '<button type="button" class="button button-primary" onclick="closeResignationModal(); showFinalExitModal(' + data.id + ');"><?php echo esc_js(__('Process Final Exit', 'sfs-hr')); ?></button>';
                }
                return html;
            }

            window.closeResignationModal = function() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            };

            window.showResignationDetails = function(id) {
                var row = document.querySelector('tr[data-id="' + id + '"]');
                if (row) {
                    openMobileModal(row);
                }
            };
        })();

        function showApproveModal(id) {
            document.getElementById('approve-resignation-id').value = id;
            document.getElementById('approve-modal').classList.add('active');
            document.body.style.overflow = 'hidden';
            return false;
        }
        function hideApproveModal() {
            document.getElementById('approve-modal').classList.remove('active');
            document.body.style.overflow = '';
        }
        function showRejectModal(id) {
            document.getElementById('reject-resignation-id').value = id;
            document.getElementById('reject-modal').classList.add('active');
            document.body.style.overflow = 'hidden';
            return false;
        }
        function hideRejectModal() {
            document.getElementById('reject-modal').classList.remove('active');
            document.body.style.overflow = '';
        }
        function showCancelModal(id) {
            document.getElementById('cancel-resignation-id').value = id;
            document.getElementById('cancel-modal').classList.add('active');
            document.body.style.overflow = 'hidden';
            return false;
        }
        function hideCancelModal() {
            document.getElementById('cancel-modal').classList.remove('active');
            document.body.style.overflow = '';
        }
        function showFinalExitModal(id) {
            document.getElementById('final-exit-modal').classList.add('active');
            document.body.style.overflow = 'hidden';

            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sfs_hr_get_resignation',
                    resignation_id: id,
                    nonce: '<?php echo wp_create_nonce('sfs_hr_resignation_ajax'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        document.getElementById('final-exit-content').innerHTML = buildFinalExitForm(response.data);
                    } else {
                        document.getElementById('final-exit-content').innerHTML = '<p>Error loading resignation data.</p>';
                    }
                },
                error: function() {
                    document.getElementById('final-exit-content').innerHTML = '<p>Error loading resignation data.</p>';
                }
            });
            return false;
        }
        function hideFinalExitModal() {
            document.getElementById('final-exit-modal').classList.remove('active');
            document.body.style.overflow = '';
        }
        function buildFinalExitForm(data) {
            var html = '<form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">';
            html += '<?php wp_nonce_field('sfs_hr_final_exit_update', '_wpnonce', true, false); ?>';
            html += '<input type="hidden" name="action" value="sfs_hr_final_exit_update">';
            html += '<input type="hidden" name="resignation_id" value="' + data.id + '">';

            html += '<div style="margin-bottom:16px;padding:16px;background:#f9fafb;border-radius:8px;">';
            html += '<p style="margin:0 0 8px;"><strong><?php esc_html_e('Employee:', 'sfs-hr'); ?></strong> ' + data.employee_name + ' (' + data.employee_code + ')</p>';
            html += '<p style="margin:0;"><strong><?php esc_html_e('Expected Exit:', 'sfs-hr'); ?></strong> ' + (data.expected_country_exit_date || 'N/A') + '</p>';
            html += '</div>';

            html += '<p><label><strong><?php esc_html_e('Final Exit Status:', 'sfs-hr'); ?></strong></label><br>';
            html += '<select name="final_exit_status" style="width:100%;padding:8px;border:1px solid #dcdcde;border-radius:4px;">';
            var statuses = ['not_required', 'pending_submission', 'submitted', 'approved', 'issued', 'completed'];
            var statusLabels = {
                'not_required': '<?php echo esc_js(__('Not Required', 'sfs-hr')); ?>',
                'pending_submission': '<?php echo esc_js(__('Pending Submission', 'sfs-hr')); ?>',
                'submitted': '<?php echo esc_js(__('Submitted to Government', 'sfs-hr')); ?>',
                'approved': '<?php echo esc_js(__('Approved by Government', 'sfs-hr')); ?>',
                'issued': '<?php echo esc_js(__('Final Exit Visa Issued', 'sfs-hr')); ?>',
                'completed': '<?php echo esc_js(__('Completed', 'sfs-hr')); ?>'
            };
            statuses.forEach(function(status) {
                var selected = (data.final_exit_status === status) ? ' selected' : '';
                html += '<option value="' + status + '"' + selected + '>' + statusLabels[status] + '</option>';
            });
            html += '</select></p>';

            html += '<p><label><strong><?php esc_html_e('Government Submission Date:', 'sfs-hr'); ?></strong></label><br>';
            html += '<input type="date" name="final_exit_submitted_date" value="' + (data.final_exit_submitted_date || '') + '" style="width:100%;padding:8px;border:1px solid #dcdcde;border-radius:4px;"></p>';

            html += '<p><label><strong><?php esc_html_e('Government Reference Number:', 'sfs-hr'); ?></strong></label><br>';
            html += '<input type="text" name="government_reference" value="' + (data.government_reference || '') + '" style="width:100%;padding:8px;border:1px solid #dcdcde;border-radius:4px;"></p>';

            html += '<p><label><strong><?php esc_html_e('Final Exit Issue Date:', 'sfs-hr'); ?></strong></label><br>';
            html += '<input type="date" name="final_exit_date" value="' + (data.final_exit_date || '') + '" style="width:100%;padding:8px;border:1px solid #dcdcde;border-radius:4px;"></p>';

            html += '<p><label><strong><?php esc_html_e('Final Exit Number:', 'sfs-hr'); ?></strong></label><br>';
            html += '<input type="text" name="final_exit_number" value="' + (data.final_exit_number || '') + '" style="width:100%;padding:8px;border:1px solid #dcdcde;border-radius:4px;"></p>';

            html += '<p><label><input type="checkbox" name="ticket_booked" value="1"' + (data.ticket_booked == 1 ? ' checked' : '') + '> <strong><?php esc_html_e('Ticket Booked', 'sfs-hr'); ?></strong></label></p>';

            html += '<p><label><strong><?php esc_html_e('Actual Exit Date:', 'sfs-hr'); ?></strong></label><br>';
            html += '<input type="date" name="actual_exit_date" value="' + (data.actual_exit_date || '') + '" style="width:100%;padding:8px;border:1px solid #dcdcde;border-radius:4px;"></p>';

            html += '<p><label><input type="checkbox" name="exit_stamp_received" value="1"' + (data.exit_stamp_received == 1 ? ' checked' : '') + '> <strong><?php esc_html_e('Exit Stamp Received', 'sfs-hr'); ?></strong></label></p>';

            html += '<p style="display:flex;gap:8px;margin-top:20px;">';
            html += '<button type="submit" class="button button-primary"><?php esc_html_e('Save', 'sfs-hr'); ?></button>';
            html += '<button type="button" onclick="hideFinalExitModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>';
            html += '</p></form>';

            return html;
        }
        </script>
        <?php
    }

    private function render_status_pill(string $status, int $level): string {
        $label = '';
        $class = '';

        switch ($status) {
            case 'pending':
                if ($level === 1) {
                    $label = __('Pending Manager', 'sfs-hr');
                } elseif ($level === 2) {
                    $label = __('Pending HR', 'sfs-hr');
                } elseif ($level === 3) {
                    $label = __('Pending Finance', 'sfs-hr');
                } elseif ($level === 4) {
                    $label = __('Pending Final Exit', 'sfs-hr');
                } else {
                    $label = __('Pending', 'sfs-hr');
                }
                $class = 'sfs-hr-pill--pending';
                break;
            case 'approved':
                $label = __('Approved', 'sfs-hr');
                $class = 'sfs-hr-pill--approved';
                break;
            case 'rejected':
                $label = __('Rejected', 'sfs-hr');
                $class = 'sfs-hr-pill--rejected';
                break;
            case 'cancelled':
                $label = __('Cancelled', 'sfs-hr');
                $class = 'sfs-hr-pill--cancelled';
                break;
            case 'completed':
                $label = __('Completed', 'sfs-hr');
                $class = 'sfs-hr-pill--completed';
                break;
            default:
                $label = ucfirst($status);
                $class = 'sfs-hr-pill--pending';
        }

        return '<span class="sfs-hr-pill ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    /* ---------------------------------- Form Handlers ---------------------------------- */

    public function handle_submit(): void {
        check_admin_referer('sfs_hr_resignation_submit');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $employee_id = Helpers::current_employee_id();
        if (!$employee_id) {
            wp_die(__('No employee record found for current user.', 'sfs-hr'));
        }

        $resignation_date = sanitize_text_field($_POST['resignation_date'] ?? '');
        // Get notice period from settings
        $notice_period = (int)get_option('sfs_hr_resignation_notice_period', 30);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        $resignation_type = sanitize_text_field($_POST['resignation_type'] ?? 'regular');
        $expected_country_exit_date = sanitize_text_field($_POST['expected_country_exit_date'] ?? '');

        // Validation
        if ($resignation_type !== 'final_exit' && empty($resignation_date)) {
            wp_die(__('Resignation date is required.', 'sfs-hr'));
        }

        if ($resignation_type === 'final_exit' && empty($expected_country_exit_date)) {
            wp_die(__('Expected country exit date is required for Final Exit resignations.', 'sfs-hr'));
        }

        // Calculate last working day (only for regular resignations with a resignation date)
        $last_working_day = null;
        if (!empty($resignation_date)) {
            $last_working_day = date('Y-m-d', strtotime($resignation_date . " +{$notice_period} days"));
        }

        // Get department manager for approval
        $emp = Helpers::get_employee_row($employee_id);
        $dept_id = $emp['dept_id'] ?? null;
        $approver_id = null;

        if ($dept_id) {
            $dept_table = $wpdb->prefix . 'sfs_hr_departments';
            $dept = $wpdb->get_row($wpdb->prepare(
                "SELECT manager_user_id FROM $dept_table WHERE id = %d",
                $dept_id
            ), ARRAY_A);
            $approver_id = $dept['manager_user_id'] ?? null;
        }

        $now = current_time('mysql');
        $insert_data = [
            'employee_id'        => $employee_id,
            'resignation_date'   => $resignation_date,
            'last_working_day'   => $last_working_day,
            'notice_period_days' => $notice_period,
            'reason'             => $reason,
            'status'             => 'pending',
            'approval_level'     => 1,
            'approver_id'        => $approver_id,
            'created_at'         => $now,
            'updated_at'         => $now,
            'resignation_type'   => $resignation_type,
        ];

        if ($resignation_type === 'final_exit') {
            $insert_data['final_exit_status'] = 'pending_submission';
            if (!empty($expected_country_exit_date)) {
                $insert_data['expected_country_exit_date'] = $expected_country_exit_date;
            }
        }

        $wpdb->insert($table, $insert_data);

        $resignation_id = $wpdb->insert_id;

        // Send notification email to manager
        if ($approver_id) {
            $this->send_notification_to_approver($resignation_id, $approver_id);
        }

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-resignations'),
            'success',
            __('Resignation submitted successfully.', 'sfs-hr')
        );
    }

    public function handle_approve(): void {
        check_admin_referer('sfs_hr_resignation_approve');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$resignation_id) {
            wp_die(__('Invalid resignation ID.', 'sfs-hr'));
        }

        $resignation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$resignation || !$this->can_approve_resignation($resignation)) {
            wp_die(__('You cannot approve this resignation.', 'sfs-hr'));
        }

        $current_level = intval($resignation['approval_level']);
        $new_level = $current_level + 1;

        // Check if Finance approver is configured
        $finance_approver_id = (int)get_option('sfs_hr_resignation_finance_approver', 0);
        $has_finance_approval = $finance_approver_id > 0;

        // Determine current role based on approval level
        $role = 'manager';
        if ($current_level === 2) {
            $role = 'hr';
        } elseif ($current_level === 3) {
            $role = 'finance';
        }

        // Append to approval chain
        $chain = json_decode($resignation['approval_chain'] ?? '[]', true) ?: [];
        $chain[] = [
            'by'     => get_current_user_id(),
            'role'   => $role,
            'action' => 'approved',
            'note'   => $note,
            'at'     => current_time('mysql'),
        ];

        $update_data = [
            'approval_chain' => json_encode($chain),
            'approver_note'  => $note,
            'approver_id'    => get_current_user_id(),
            'updated_at'     => current_time('mysql'),
        ];

        // Determine if this is final approval
        // Level 1: Manager -> HR (level 2)
        // Level 2: HR -> Finance (level 3) if configured, else approved
        // Level 3: Finance -> approved
        $is_final_approval = false;

        if ($current_level === 1) {
            // Manager approved, move to HR (level 2)
            $update_data['approval_level'] = 2;
        } elseif ($current_level === 2) {
            // HR approved
            if ($has_finance_approval) {
                // Move to Finance (level 3)
                $update_data['approval_level'] = 3;
            } else {
                // No Finance approval needed, mark as approved
                $is_final_approval = true;
            }
        } elseif ($current_level === 3) {
            // Finance approved (final)
            $is_final_approval = true;
        }

        // If final approval, determine next step based on resignation type
        if ($is_final_approval) {
            $resignation_type = $resignation['resignation_type'] ?? 'regular';

            if ($resignation_type === 'final_exit') {
                // For Final Exit types, move to Final Exit processing stage (level 4)
                $update_data['approval_level'] = 4;
                $update_data['status'] = 'pending'; // Remains pending until Final Exit is completed
            } else {
                // For Regular resignations, mark as approved
                $update_data['status'] = 'approved';
                $update_data['decided_at'] = current_time('mysql');
            }

            // NOTE: Employee status remains 'active' during notice period
            // They should only be terminated after their last working day
            // This allows them to work, attend, and access their profile during the notice period
        }

        $wpdb->update($table, $update_data, ['id' => $resignation_id]);

        // Send notification
        $this->send_approval_notification($resignation_id);

        // Prepare success message
        $last_working_day = $resignation['last_working_day'] ?? 'N/A';
        $success_message = sprintf(
            __('Resignation approved successfully. Employee will remain active until their last working day (%s), after which they will be automatically terminated.', 'sfs-hr'),
            $last_working_day
        );

        // Check for outstanding loans and add to notice
        if (class_exists('\SFS\HR\Modules\Loans\LoansModule')) {
            $has_loans = \SFS\HR\Modules\Loans\LoansModule::has_active_loans($resignation['employee_id']);
            $outstanding = \SFS\HR\Modules\Loans\LoansModule::get_outstanding_balance($resignation['employee_id']);

            if ($has_loans && $outstanding > 0) {
                $success_message .= ' ' . sprintf(
                    __('Note: Employee has outstanding loan balance of %s SAR that must be settled before final exit.', 'sfs-hr'),
                    number_format($outstanding, 2)
                );
            }
        }

        // Check for unreturned assets and add to notice
        if (class_exists('\SFS\HR\Modules\Assets\AssetsModule')) {
            $has_unreturned = \SFS\HR\Modules\Assets\AssetsModule::has_unreturned_assets($resignation['employee_id']);
            $unreturned_count = \SFS\HR\Modules\Assets\AssetsModule::get_unreturned_assets_count($resignation['employee_id']);

            if ($has_unreturned) {
                $success_message .= ' ' . sprintf(
                    __('Note: Employee has %d unreturned asset(s) that must be returned before final exit.', 'sfs-hr'),
                    $unreturned_count
                );
            }
        }

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-resignations'),
            'success',
            $success_message
        );
    }

    public function handle_reject(): void {
        check_admin_referer('sfs_hr_resignation_reject');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$resignation_id || empty($note)) {
            wp_die(__('Resignation ID and rejection reason are required.', 'sfs-hr'));
        }

        $resignation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$resignation || !$this->can_approve_resignation($resignation)) {
            wp_die(__('You cannot reject this resignation.', 'sfs-hr'));
        }

        $current_level = intval($resignation['approval_level']);

        // Append to approval chain
        $chain = json_decode($resignation['approval_chain'] ?? '[]', true) ?: [];
        $chain[] = [
            'by'     => get_current_user_id(),
            'role'   => $current_level === 1 ? 'manager' : 'hr',
            'action' => 'rejected',
            'note'   => $note,
            'at'     => current_time('mysql'),
        ];

        $wpdb->update($table, [
            'status'         => 'rejected',
            'approval_chain' => json_encode($chain),
            'approver_note'  => $note,
            'approver_id'    => get_current_user_id(),
            'decided_at'     => current_time('mysql'),
            'updated_at'     => current_time('mysql'),
        ], ['id' => $resignation_id]);

        // Send rejection notification
        $this->send_rejection_notification($resignation_id);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-resignations'),
            'success',
            __('Resignation rejected.', 'sfs-hr')
        );
    }

    public function handle_cancel(): void {
        check_admin_referer('sfs_hr_resignation_cancel');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$resignation_id || empty($note)) {
            wp_die(__('Resignation ID and cancellation reason are required.', 'sfs-hr'));
        }

        $resignation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$resignation || !$this->can_approve_resignation($resignation)) {
            wp_die(__('You cannot cancel this resignation.', 'sfs-hr'));
        }

        $was_approved = $resignation['status'] === 'approved';

        // Append to approval chain
        $chain = json_decode($resignation['approval_chain'] ?? '[]', true) ?: [];
        $chain[] = [
            'by'     => get_current_user_id(),
            'role'   => 'manager',
            'action' => 'cancelled',
            'note'   => $note,
            'at'     => current_time('mysql'),
        ];

        $wpdb->update($table, [
            'status'         => 'cancelled',
            'approval_chain' => json_encode($chain),
            'approver_note'  => $note,
            'approver_id'    => get_current_user_id(),
            'decided_at'     => current_time('mysql'),
            'updated_at'     => current_time('mysql'),
        ], ['id' => $resignation_id]);

        // Check if employee was already terminated (past last working day)
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM {$emp_table} WHERE id = %d",
            $resignation['employee_id']
        ), ARRAY_A);

        $status_message = '';
        if ($employee && $employee['status'] === 'terminated') {
            // Revert to active
            $wpdb->update($emp_table, [
                'status' => 'active',
                'updated_at' => current_time('mysql'),
            ], ['id' => $resignation['employee_id']]);
            $status_message = ' ' . __('Employee status has been reverted to active.', 'sfs-hr');
        }

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-resignations'),
            'success',
            __('Resignation cancelled successfully.', 'sfs-hr') . $status_message
        );
    }

    /* ---------------------------------- Helper Methods ---------------------------------- */

    private function can_approve_resignation(array $resignation): bool {
        $current_user_id = get_current_user_id();
        $approval_level = intval($resignation['approval_level'] ?? 1);

        // HR managers can always approve
        if (current_user_can('sfs_hr.manage')) {
            return true;
        }

        // Check if user is department manager (for level 1)
        if ($approval_level === 1) {
            $managed_depts = $this->manager_dept_ids_for_user($current_user_id);
            return in_array($resignation['dept_id'], $managed_depts, true);
        }

        // Check if user is configured HR approver (for level 2)
        if ($approval_level === 2) {
            $hr_approver_id = (int)get_option('sfs_hr_resignation_hr_approver', 0);
            if ($hr_approver_id > 0 && $hr_approver_id === $current_user_id) {
                return true;
            }
        }

        // Check if user is configured Finance approver (for level 3)
        if ($approval_level === 3) {
            $finance_approver_id = (int)get_option('sfs_hr_resignation_finance_approver', 0);
            if ($finance_approver_id > 0 && $finance_approver_id === $current_user_id) {
                return true;
            }
        }

        return false;
    }

    private function manager_dept_ids_for_user(int $user_id): array {
        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $dept_table WHERE manager_user_id = %d AND active = 1",
            $user_id
        ), ARRAY_A);

        return array_column($rows, 'id');
    }

    private function status_badge(string $status, int $approval_level = 1): string {
        $colors = [
            'pending'   => '#f0ad4e',
            'approved'  => '#5cb85c',
            'completed' => '#28a745',
            'rejected'  => '#d9534f',
            'cancelled' => '#6c757d',
        ];

        $color = $colors[$status] ?? '#777';

        // Make status clearer for pending resignations
        $label = ucfirst($status);
        if ($status === 'pending') {
            if ($approval_level === 1) {
                $label = __('Pending - Manager', 'sfs-hr');
            } elseif ($approval_level === 2) {
                $label = __('Pending - HR', 'sfs-hr');
            } elseif ($approval_level === 3) {
                $label = __('Pending - Finance', 'sfs-hr');
            } elseif ($approval_level === 4) {
                $label = __('Pending - Final Exit', 'sfs-hr');
                $color = '#17a2b8'; // Different color for Final Exit stage
            } else {
                $label = __('Pending', 'sfs-hr');
            }
        } elseif ($status === 'completed') {
            $label = __('Completed', 'sfs-hr');
        }

        return sprintf(
            '<span style="background:%s;color:#fff;padding:4px 8px;border-radius:3px;font-size:11px;">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }

    private function final_exit_status_badge(string $status): string {
        $colors = [
            'not_required'       => '#999',
            'pending_submission' => '#f0ad4e',
            'submitted'          => '#17a2b8',
            'approved'           => '#5cb85c',
            'issued'             => '#007bff',
            'completed'          => '#28a745',
        ];

        $labels = [
            'not_required'       => __('Not Required', 'sfs-hr'),
            'pending_submission' => __('Pending', 'sfs-hr'),
            'submitted'          => __('Submitted', 'sfs-hr'),
            'approved'           => __('Approved', 'sfs-hr'),
            'issued'             => __('Issued', 'sfs-hr'),
            'completed'          => __('Completed', 'sfs-hr'),
        ];

        $color = $colors[$status] ?? '#777';
        $label = $labels[$status] ?? ucfirst($status);

        return sprintf(
            '<span style="background:%s;color:#fff;padding:4px 8px;border-radius:3px;font-size:11px;">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }

    /* ---------------------------------- Notifications ---------------------------------- */

    private function send_notification_to_approver(int $resignation_id, int $approver_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $resignation = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, e.first_name, e.last_name FROM $table r
             JOIN $emp_t e ON e.id = r.employee_id
             WHERE r.id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$resignation) return;

        $approver = get_userdata($approver_id);
        if (!$approver) return;

        $subject = __('New Resignation Submitted', 'sfs-hr');
        $message = sprintf(
            __('A resignation has been submitted by %s %s and requires your approval.', 'sfs-hr'),
            $resignation['first_name'],
            $resignation['last_name']
        );
        $message .= "\n\n" . __('Resignation Date:', 'sfs-hr') . ' ' . $resignation['resignation_date'];
        $message .= "\n" . __('Last Working Day:', 'sfs-hr') . ' ' . $resignation['last_working_day'];
        $message .= "\n\n" . __('Please log in to review and approve.', 'sfs-hr');

        Helpers::send_mail($approver->user_email, $subject, $message);
    }

    private function send_approval_notification(int $resignation_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $resignation = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, e.first_name, e.last_name, e.user_id FROM $table r
             JOIN $emp_t e ON e.id = r.employee_id
             WHERE r.id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$resignation || !$resignation['user_id']) return;

        $employee = get_userdata($resignation['user_id']);
        if (!$employee) return;

        $subject = __('Your Resignation Has Been Approved', 'sfs-hr');
        $message = sprintf(
            __('Dear %s,', 'sfs-hr'),
            $resignation['first_name']
        );
        $message .= "\n\n" . __('Your resignation has been approved.', 'sfs-hr');
        $message .= "\n" . __('Last Working Day:', 'sfs-hr') . ' ' . $resignation['last_working_day'];

        Helpers::send_mail($employee->user_email, $subject, $message);
    }

    private function send_rejection_notification(int $resignation_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $resignation = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, e.first_name, e.last_name, e.user_id FROM $table r
             JOIN $emp_t e ON e.id = r.employee_id
             WHERE r.id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$resignation || !$resignation['user_id']) return;

        $employee = get_userdata($resignation['user_id']);
        if (!$employee) return;

        $subject = __('Your Resignation Has Been Rejected', 'sfs-hr');
        $message = sprintf(
            __('Dear %s,', 'sfs-hr'),
            $resignation['first_name']
        );
        $message .= "\n\n" . __('Your resignation has been rejected.', 'sfs-hr');
        $message .= "\n" . __('Reason:', 'sfs-hr') . ' ' . $resignation['approver_note'];

        Helpers::send_mail($employee->user_email, $subject, $message);
    }

    /* ---------------------------------- Cron Jobs ---------------------------------- */

    /**
     * Daily cron job to terminate employees whose last working day has passed
     */
    public function process_expired_resignations(): void {
        global $wpdb;
        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Get today's date
        $today = current_time('Y-m-d');

        // Find all approved resignations where last_working_day has passed
        $expired_resignations = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.employee_id, r.last_working_day, e.status as employee_status
             FROM {$resign_table} r
             JOIN {$emp_table} e ON e.id = r.employee_id
             WHERE r.status = 'approved'
             AND r.last_working_day < %s
             AND e.status = 'active'",
            $today
        ), ARRAY_A);

        if (empty($expired_resignations)) {
            return;
        }

        // Terminate each employee
        foreach ($expired_resignations as $resignation) {
            $wpdb->update($emp_table, [
                'status' => 'terminated',
                'updated_at' => current_time('mysql'),
            ], ['id' => $resignation['employee_id']]);
        }
    }

    /* ---------------------------------- AJAX Handlers ---------------------------------- */

    public function ajax_get_resignation(): void {
        check_ajax_referer('sfs_hr_resignation_ajax', 'nonce');

        if (!current_user_can('sfs_hr.manage') && !current_user_can('sfs_hr.view')) {
            wp_send_json_error(['message' => __('Access denied', 'sfs-hr')]);
        }

        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        if (!$resignation_id) {
            wp_send_json_error(['message' => __('Invalid resignation ID', 'sfs-hr')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $resignation = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, e.first_name, e.last_name, e.employee_code
             FROM {$table} r
             LEFT JOIN {$emp_t} e ON e.id = r.employee_id
             WHERE r.id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$resignation) {
            wp_send_json_error(['message' => __('Resignation not found', 'sfs-hr')]);
        }

        // Prepare response data
        $data = [
            'id'                         => $resignation['id'],
            'employee_name'              => trim($resignation['first_name'] . ' ' . $resignation['last_name']),
            'employee_code'              => $resignation['employee_code'],
            'resignation_date'           => $resignation['resignation_date'],
            'resignation_type'           => $resignation['resignation_type'] ?? 'regular',
            'status'                     => $resignation['status'],
            'approval_level'             => intval($resignation['approval_level'] ?? 1),
            'final_exit_status'          => $resignation['final_exit_status'] ?? 'not_required',
            'final_exit_number'          => $resignation['final_exit_number'] ?? '',
            'final_exit_date'            => $resignation['final_exit_date'] ?? '',
            'final_exit_submitted_date'  => $resignation['final_exit_submitted_date'] ?? '',
            'government_reference'       => $resignation['government_reference'] ?? '',
            'expected_country_exit_date' => $resignation['expected_country_exit_date'] ?? '',
            'actual_exit_date'           => $resignation['actual_exit_date'] ?? '',
            'ticket_booked'              => $resignation['ticket_booked'] ?? 0,
            'exit_stamp_received'        => $resignation['exit_stamp_received'] ?? 0,
            'can_approve'                => $this->can_approve_resignation($resignation),
            'can_manage'                 => current_user_can('sfs_hr.manage'),
        ];

        wp_send_json_success($data);
    }

    public function handle_final_exit_update(): void {
        check_admin_referer('sfs_hr_final_exit_update');

        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Access denied', 'sfs-hr'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        if (!$resignation_id) {
            wp_die(__('Invalid resignation ID', 'sfs-hr'));
        }

        $resignation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $resignation_id
        ), ARRAY_A);

        if (!$resignation) {
            wp_die(__('Resignation not found', 'sfs-hr'));
        }

        $final_exit_status = sanitize_text_field($_POST['final_exit_status'] ?? 'not_required');

        $update_data = [
            'final_exit_status'          => $final_exit_status,
            'final_exit_number'          => sanitize_text_field($_POST['final_exit_number'] ?? ''),
            'final_exit_date'            => sanitize_text_field($_POST['final_exit_date'] ?? ''),
            'final_exit_submitted_date'  => sanitize_text_field($_POST['final_exit_submitted_date'] ?? ''),
            'government_reference'       => sanitize_text_field($_POST['government_reference'] ?? ''),
            'actual_exit_date'           => sanitize_text_field($_POST['actual_exit_date'] ?? ''),
            'ticket_booked'              => isset($_POST['ticket_booked']) ? 1 : 0,
            'exit_stamp_received'        => isset($_POST['exit_stamp_received']) ? 1 : 0,
            'updated_at'                 => current_time('mysql'),
        ];

        // If Final Exit is completed, mark the entire resignation as completed
        if ($final_exit_status === 'completed') {
            $update_data['status'] = 'completed';
            $update_data['decided_at'] = current_time('mysql');
        }

        // Remove empty dates
        foreach (['final_exit_date', 'final_exit_submitted_date', 'actual_exit_date'] as $date_field) {
            if (empty($update_data[$date_field])) {
                $update_data[$date_field] = null;
            }
        }

        $wpdb->update($table, $update_data, ['id' => $resignation_id]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-resignations&status=final_exit'),
            'success',
            __('Final Exit data updated successfully.', 'sfs-hr')
        );
    }

    /* ---------------------------------- Shortcodes ---------------------------------- */

    public function shortcode_submit_form($atts): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to submit a resignation.', 'sfs-hr') . '</p>';
        }

        $employee_id = Helpers::current_employee_id();
        if (!$employee_id) {
            return '<p>' . esc_html__('No employee record found.', 'sfs-hr') . '</p>';
        }

        ob_start();
        ?>
        <div class="sfs-hr-resignation-form">
            <h2><?php esc_html_e('Submit Resignation', 'sfs-hr'); ?></h2>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sfs_hr_resignation_submit'); ?>
                <input type="hidden" name="action" value="sfs_hr_resignation_submit">

                <p>
                    <label for="resignation_date"><?php esc_html_e('Resignation Date:', 'sfs-hr'); ?> <span style="color:red;">*</span></label><br>
                    <input type="date" name="resignation_date" id="resignation_date" required style="width:100%;max-width:300px;">
                </p>

                <p>
                    <label><?php esc_html_e('Resignation Type:', 'sfs-hr'); ?> <span style="color:red;">*</span></label><br>
                    <label style="display:inline-block;margin-right:20px;">
                        <input type="radio" name="resignation_type" value="regular" checked required>
                        <?php esc_html_e('Regular Resignation', 'sfs-hr'); ?>
                    </label>
                    <label style="display:inline-block;">
                        <input type="radio" name="resignation_type" value="final_exit" required>
                        <?php esc_html_e('Final Exit (Foreign Employees)', 'sfs-hr'); ?>
                    </label>
                </p>

                <p>
                    <label for="notice_period_days"><?php esc_html_e('Notice Period (days):', 'sfs-hr'); ?> <span style="color:red;">*</span></label><br>
                    <input type="number" name="notice_period_days" id="notice_period_days" value="<?php echo esc_attr(get_option('sfs_hr_resignation_notice_period', '30')); ?>" min="0" readonly required style="width:100%;max-width:300px;background:#f5f5f5;cursor:not-allowed;">
                    <br><small style="color:#666;"><?php esc_html_e('Set by HR based on company policy.', 'sfs-hr'); ?></small>
                </p>

                <p>
                    <label for="reason"><?php esc_html_e('Reason for Resignation:', 'sfs-hr'); ?> <span style="color:red;">*</span></label><br>
                    <textarea name="reason" id="reason" rows="5" required style="width:100%;max-width:600px;"></textarea>
                </p>

                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Submit Resignation', 'sfs-hr'); ?></button>
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function shortcode_my_resignations($atts): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to view your resignations.', 'sfs-hr') . '</p>';
        }

        $employee_id = Helpers::current_employee_id();
        if (!$employee_id) {
            return '<p>' . esc_html__('No employee record found.', 'sfs-hr') . '</p>';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE employee_id = %d ORDER BY id DESC",
            $employee_id
        ), ARRAY_A);

        ob_start();
        ?>
        <div class="sfs-hr-my-resignations">
            <h2><?php esc_html_e('My Resignations', 'sfs-hr'); ?></h2>
            <?php if (empty($resignations)): ?>
                <p><?php esc_html_e('You have not submitted any resignations.', 'sfs-hr'); ?></p>
            <?php else: ?>
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="border:1px solid #ddd;padding:8px;"><?php esc_html_e('Resignation Date', 'sfs-hr'); ?></th>
                            <th style="border:1px solid #ddd;padding:8px;"><?php esc_html_e('Last Working Day', 'sfs-hr'); ?></th>
                            <th style="border:1px solid #ddd;padding:8px;"><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                            <th style="border:1px solid #ddd;padding:8px;"><?php esc_html_e('Submitted', 'sfs-hr'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resignations as $r): ?>
                            <tr>
                                <td style="border:1px solid #ddd;padding:8px;"><?php echo esc_html($r['resignation_date']); ?></td>
                                <td style="border:1px solid #ddd;padding:8px;"><?php echo esc_html($r['last_working_day']); ?></td>
                                <td style="border:1px solid #ddd;padding:8px;"><?php echo $this->status_badge($r['status'], intval($r['approval_level'] ?? 1)); ?></td>
                                <td style="border:1px solid #ddd;padding:8px;"><?php echo esc_html($r['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------------------------- Settings ---------------------------------- */

    private function render_settings_tab(): void {
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Access denied', 'sfs-hr'));
        }

        $notice_period_days = (int)get_option('sfs_hr_resignation_notice_period', 30);
        $hr_approver_id = (int)get_option('sfs_hr_resignation_hr_approver', 0);
        $finance_approver_id = (int)get_option('sfs_hr_resignation_finance_approver', 0);

        // Get list of users with HR management capabilities
        $hr_users = get_users([
            'capability' => 'sfs_hr.manage',
            'orderby' => 'display_name',
        ]);

        ?>
            <?php if (!empty($_GET['ok'])): ?>
                <div class="notice notice-success"><p><?php esc_html_e('Settings saved successfully.', 'sfs-hr'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sfs_hr_resignation_settings'); ?>
                <input type="hidden" name="action" value="sfs_hr_resignation_settings">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="notice_period_days">
                                <?php esc_html_e('Default Notice Period (days)', 'sfs-hr'); ?>
                            </label>
                        </th>
                        <td>
                            <input
                                type="number"
                                name="notice_period_days"
                                id="notice_period_days"
                                value="<?php echo esc_attr($notice_period_days); ?>"
                                min="0"
                                max="365"
                                style="width:100px;"
                                required>
                            <p class="description">
                                <?php esc_html_e('The default notice period in days for employee resignations. This will be applied automatically and shown as read-only to employees when they submit their resignation.', 'sfs-hr'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="hr_approver">
                                <?php esc_html_e('HR Approver', 'sfs-hr'); ?>
                            </label>
                        </th>
                        <td>
                            <select name="hr_approver" id="hr_approver" style="width:300px;">
                                <option value="0"><?php esc_html_e('-- No specific HR approver --', 'sfs-hr'); ?></option>
                                <?php foreach ($hr_users as $user): ?>
                                    <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($hr_approver_id, $user->ID); ?>>
                                        <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Select a specific user for HR approval. If not set, any user with HR management capability can approve at HR level.', 'sfs-hr'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="finance_approver">
                                <?php esc_html_e('Finance Approver', 'sfs-hr'); ?>
                            </label>
                        </th>
                        <td>
                            <select name="finance_approver" id="finance_approver" style="width:300px;">
                                <option value="0"><?php esc_html_e('-- No Finance approval required --', 'sfs-hr'); ?></option>
                                <?php foreach ($hr_users as $user): ?>
                                    <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($finance_approver_id, $user->ID); ?>>
                                        <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Select a specific user for Finance approval. If not set, resignations will only require Manager and HR approval. Finance approval happens after HR approval.', 'sfs-hr'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'sfs-hr')); ?>
            </form>
        <?php
    }

    public function handle_settings(): void {
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Access denied', 'sfs-hr'));
        }

        check_admin_referer('sfs_hr_resignation_settings');

        $notice_period_days = isset($_POST['notice_period_days']) ? max(0, min(365, (int)$_POST['notice_period_days'])) : 30;
        update_option('sfs_hr_resignation_notice_period', (string)$notice_period_days);

        $hr_approver = isset($_POST['hr_approver']) ? (int)$_POST['hr_approver'] : 0;
        update_option('sfs_hr_resignation_hr_approver', (string)$hr_approver);

        $finance_approver = isset($_POST['finance_approver']) ? (int)$_POST['finance_approver'] : 0;
        update_option('sfs_hr_resignation_finance_approver', (string)$finance_approver);

        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-resignations&tab=settings&ok=1'));
        exit;
    }
}