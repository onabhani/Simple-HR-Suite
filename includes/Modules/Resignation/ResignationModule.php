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

        Helpers::render_admin_nav();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Resignations Management', 'sfs-hr'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=sfs-hr-resignations&tab=resignations"
                   class="nav-tab <?php echo $tab === 'resignations' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Resignations', 'sfs-hr'); ?>
                </a>
                <?php if (current_user_can('sfs_hr.manage')): ?>
                <a href="?page=sfs-hr-resignations&tab=settings"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'sfs-hr'); ?>
                </a>
                <?php endif; ?>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
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
                ?>
            </div>
        </div>
        <?php
    }

    private function render_resignations_tab(): void {
        global $wpdb;

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'pending';
        $page   = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $pp     = 20;
        $offset = ($page - 1) * $pp;

        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $where  = '1=1';
        $params = [];

        if (in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $where   .= " AND r.status = %s";
            $params[] = $status;
        } elseif ($status === 'final_exit') {
            $where   .= " AND r.resignation_type = %s";
            $params[] = 'final_exit';
        }

        // Department manager scoping
        $managed_depts = [];
        if (!current_user_can('sfs_hr.manage')) {
            $managed_depts = $this->manager_dept_ids_for_user(get_current_user_id());
            if (!empty($managed_depts)) {
                $placeholders = implode(',', array_fill(0, count($managed_depts), '%d'));
                $where .= " AND e.dept_id IN ($placeholders)";
                $params = array_merge($params, array_map('intval', $managed_depts));
            }
        }

        $sql_total = "SELECT COUNT(*)
                      FROM $table r
                      JOIN $emp_t e ON e.id = r.employee_id
                      WHERE $where";
        $total = $params ? (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params))
                         : (int)$wpdb->get_var($sql_total);

        $sql = "SELECT r.*, e.employee_code, e.first_name, e.last_name, e.user_id AS emp_user_id, e.dept_id
                FROM $table r
                JOIN $emp_t e ON e.id = r.employee_id
                WHERE $where
                ORDER BY r.id DESC
                LIMIT %d OFFSET %d";

        $params_all = array_merge($params, [$pp, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_all), ARRAY_A);

        ?>
            <style>
                @media (max-width: 782px) {
                    /* Hide less important columns on mobile */
                    .sfs-hr-resignations-table-wrapper table.wp-list-table th.hide-mobile,
                    .sfs-hr-resignations-table-wrapper table.wp-list-table td.hide-mobile {
                        display: none;
                    }

                    /* Adjust remaining columns */
                    .sfs-hr-resignations-table-wrapper table.wp-list-table {
                        font-size: 13px;
                    }

                    .sfs-hr-resignations-table-wrapper table.wp-list-table th,
                    .sfs-hr-resignations-table-wrapper table.wp-list-table td {
                        padding: 10px 8px;
                    }

                    /* Stack action buttons vertically */
                    .sfs-hr-resignations-table-wrapper table.wp-list-table td.actions-col {
                        white-space: normal;
                    }

                    .sfs-hr-resignations-table-wrapper table.wp-list-table .button {
                        display: block;
                        width: 100%;
                        margin: 4px 0;
                        text-align: center;
                        font-size: 12px;
                        padding: 4px 8px;
                    }

                    .sfs-hr-resignations-table-wrapper table.wp-list-table td small {
                        font-size: 11px;
                    }

                    /* Make employee names more readable */
                    .sfs-hr-resignations-table-wrapper table.wp-list-table td.employee-col {
                        min-width: 120px;
                    }
                }
            </style>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-resignations&tab=resignations&status=pending')); ?>"
                    class="<?php echo $status === 'pending' ? 'current' : ''; ?>">
                    <?php esc_html_e('Pending', 'sfs-hr'); ?></a> | </li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-resignations&tab=resignations&status=approved')); ?>"
                    class="<?php echo $status === 'approved' ? 'current' : ''; ?>">
                    <?php esc_html_e('Approved', 'sfs-hr'); ?></a> | </li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-resignations&tab=resignations&status=rejected')); ?>"
                    class="<?php echo $status === 'rejected' ? 'current' : ''; ?>">
                    <?php esc_html_e('Rejected', 'sfs-hr'); ?></a> | </li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-resignations&tab=resignations&status=cancelled')); ?>"
                    class="<?php echo $status === 'cancelled' ? 'current' : ''; ?>">
                    <?php esc_html_e('Cancelled', 'sfs-hr'); ?></a> | </li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-resignations&tab=resignations&status=final_exit')); ?>"
                    class="<?php echo $status === 'final_exit' ? 'current' : ''; ?>">
                    <?php esc_html_e('Final Exit', 'sfs-hr'); ?></a></li>
            </ul>

            <div class="sfs-hr-resignations-table-wrapper">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="hide-mobile"><?php esc_html_e('ID', 'sfs-hr'); ?></th>
                        <th class="employee-col"><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Type', 'sfs-hr'); ?></th>
                        <th class="hide-mobile"><?php esc_html_e('Resignation Date', 'sfs-hr'); ?></th>
                        <th class="hide-mobile"><?php esc_html_e('Last Working Day', 'sfs-hr'); ?></th>
                        <th class="hide-mobile"><?php esc_html_e('Notice Period', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Final Exit', 'sfs-hr'); ?></th>
                        <th class="hide-mobile"><?php esc_html_e('Reason', 'sfs-hr'); ?></th>
                        <th class="actions-col"><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="10"><?php esc_html_e('No resignations found.', 'sfs-hr'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="hide-mobile"><?php echo esc_html($row['id']); ?></td>
                                <td class="employee-col">
                                    <?php echo esc_html($row['first_name'] . ' ' . $row['last_name']); ?><br>
                                    <small><?php echo esc_html($row['employee_code']); ?></small>
                                    <?php
                                    // Display loan badge if loans exist
                                    if (class_exists('\SFS\HR\Modules\Loans\Admin\DashboardWidget')) {
                                        echo \SFS\HR\Modules\Loans\Admin\DashboardWidget::render_employee_loan_badge($row['employee_id']);
                                    }
                                    ?>
                                </td>
                                <td><?php
                                    $type = $row['resignation_type'] ?? 'regular';
                                    if ($type === 'final_exit') {
                                        echo '<span style="background:#673ab7;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">'
                                            . esc_html__('Final Exit', 'sfs-hr') . '</span>';
                                    } else {
                                        echo '<span style="background:#607d8b;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;">'
                                            . esc_html__('Regular', 'sfs-hr') . '</span>';
                                    }
                                ?></td>
                                <td class="hide-mobile"><?php echo esc_html($row['resignation_date']); ?></td>
                                <td class="hide-mobile"><?php echo esc_html($row['last_working_day'] ?: 'N/A'); ?></td>
                                <td class="hide-mobile"><?php echo esc_html($row['notice_period_days']) . ' ' . esc_html__('days', 'sfs-hr'); ?></td>
                                <td><?php echo $this->status_badge($row['status'], intval($row['approval_level'] ?? 1)); ?></td>
                                <td><?php
                                    if ($type === 'final_exit') {
                                        $fe_status = $row['final_exit_status'] ?? 'not_required';
                                        echo $this->final_exit_status_badge($fe_status);
                                        if (!empty($row['final_exit_number'])) {
                                            echo '<br><small>' . esc_html($row['final_exit_number']) . '</small>';
                                        }
                                    } else {
                                        echo '—';
                                    }
                                ?></td>
                                <td class="hide-mobile"><?php echo esc_html(wp_trim_words($row['reason'], 10)); ?></td>
                                <td class="actions-col">
                                    <?php if ($row['status'] === 'pending' && $this->can_approve_resignation($row)): ?>
                                        <a href="#" onclick="return showApproveModal(<?php echo esc_attr($row['id']); ?>);" class="button button-small">
                                            <?php esc_html_e('Approve', 'sfs-hr'); ?>
                                        </a>
                                        <a href="#" onclick="return showRejectModal(<?php echo esc_attr($row['id']); ?>);" class="button button-small">
                                            <?php esc_html_e('Reject', 'sfs-hr'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (in_array($row['status'], ['pending', 'approved']) && $this->can_approve_resignation($row)): ?>
                                        <a href="#" onclick="return showCancelModal(<?php echo esc_attr($row['id']); ?>);" class="button button-small" style="background:#dc3545;border-color:#dc3545;color:#fff;margin-left:4px;">
                                            <?php esc_html_e('Cancel', 'sfs-hr'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($type === 'final_exit'): ?>
                                        <a href="#" onclick="return showFinalExitModal(<?php echo esc_attr($row['id']); ?>);" class="button button-small">
                                            <?php esc_html_e('Final Exit', 'sfs-hr'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <a href="#" onclick="return showDetailsModal(<?php echo esc_attr($row['id']); ?>);" class="button button-small">
                                        <?php esc_html_e('Details', 'sfs-hr'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <?php if ($total > $pp): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        $total_pages = ceil($total / $pp);
                        echo paginate_links([
                            'base'    => add_query_arg('paged', '%#%'),
                            'format'  => '',
                            'current' => $page,
                            'total'   => $total_pages,
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>

        <!-- Approve Modal -->
        <div id="approve-modal" style="display:none;">
            <div style="background:#fff; padding:20px; max-width:500px; margin:50px auto; border:1px solid #ccc;">
                <h2><?php esc_html_e('Approve Resignation', 'sfs-hr'); ?></h2>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_resignation_approve'); ?>
                    <input type="hidden" name="action" value="sfs_hr_resignation_approve">
                    <input type="hidden" name="resignation_id" id="approve-resignation-id">
                    <p>
                        <label><?php esc_html_e('Note (optional):', 'sfs-hr'); ?></label><br>
                        <textarea name="note" rows="4" style="width:100%;"></textarea>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Approve', 'sfs-hr'); ?></button>
                        <button type="button" onclick="hideApproveModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Reject Modal -->
        <div id="reject-modal" style="display:none;">
            <div style="background:#fff; padding:20px; max-width:500px; margin:50px auto; border:1px solid #ccc;">
                <h2><?php esc_html_e('Reject Resignation', 'sfs-hr'); ?></h2>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_resignation_reject'); ?>
                    <input type="hidden" name="action" value="sfs_hr_resignation_reject">
                    <input type="hidden" name="resignation_id" id="reject-resignation-id">
                    <p>
                        <label><?php esc_html_e('Reason for rejection:', 'sfs-hr'); ?></label><br>
                        <textarea name="note" rows="4" style="width:100%;" required></textarea>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>
                        <button type="button" onclick="hideRejectModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Cancel Modal -->
        <div id="cancel-modal" style="display:none;">
            <div style="background:#fff; padding:20px; max-width:500px; margin:50px auto; border:1px solid #ccc;">
                <h2><?php esc_html_e('Cancel Resignation', 'sfs-hr'); ?></h2>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_resignation_cancel'); ?>
                    <input type="hidden" name="action" value="sfs_hr_resignation_cancel">
                    <input type="hidden" name="resignation_id" id="cancel-resignation-id">
                    <p>
                        <?php esc_html_e('Are you sure you want to cancel this resignation? If the resignation was approved, the employee status will be reverted to active.', 'sfs-hr'); ?>
                    </p>
                    <p>
                        <label><?php esc_html_e('Reason for cancellation:', 'sfs-hr'); ?></label><br>
                        <textarea name="note" rows="4" style="width:100%;" required></textarea>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary" style="background:#dc3545;border-color:#dc3545;"><?php esc_html_e('Cancel Resignation', 'sfs-hr'); ?></button>
                        <button type="button" onclick="hideCancelModal();" class="button"><?php esc_html_e('Close', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Details Modal -->
        <div id="details-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
            <div style="background:#fff;padding:30px;max-width:900px;margin:50px auto;border-radius:5px;max-height:90%;overflow-y:auto;">
                <h2><?php esc_html_e('Resignation Details', 'sfs-hr'); ?></h2>
                <div id="details-content">
                    <p><?php esc_html_e('Loading...', 'sfs-hr'); ?></p>
                </div>
                <div style="margin-top:20px;">
                    <button type="button" onclick="hideDetailsModal();" class="button"><?php esc_html_e('Close', 'sfs-hr'); ?></button>
                </div>
            </div>
        </div>

        <!-- Final Exit Modal -->
        <div id="final-exit-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
            <div style="background:#fff;padding:30px;max-width:900px;margin:50px auto;border-radius:5px;max-height:90%;overflow-y:auto;">
                <h2><?php esc_html_e('Final Exit Management', 'sfs-hr'); ?></h2>
                <div id="final-exit-content">
                    <p><?php esc_html_e('Loading...', 'sfs-hr'); ?></p>
                </div>
            </div>
        </div>

        <script>
        function showApproveModal(id) {
            document.getElementById('approve-resignation-id').value = id;
            document.getElementById('approve-modal').style.display = 'block';
            return false;
        }
        function hideApproveModal() {
            document.getElementById('approve-modal').style.display = 'none';
        }
        function showRejectModal(id) {
            document.getElementById('reject-resignation-id').value = id;
            document.getElementById('reject-modal').style.display = 'block';
            return false;
        }
        function hideRejectModal() {
            document.getElementById('reject-modal').style.display = 'none';
        }
        function showCancelModal(id) {
            document.getElementById('cancel-resignation-id').value = id;
            document.getElementById('cancel-modal').style.display = 'block';
            return false;
        }
        function hideCancelModal() {
            document.getElementById('cancel-modal').style.display = 'none';
        }
        function showDetailsModal(id) {
            document.getElementById('details-modal').style.display = 'block';

            // Load resignation data via AJAX
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
                        document.getElementById('details-content').innerHTML = buildDetailsView(response.data);
                    } else {
                        document.getElementById('details-content').innerHTML = '<p>Error loading resignation data.</p>';
                    }
                },
                error: function() {
                    document.getElementById('details-content').innerHTML = '<p>Error loading resignation data.</p>';
                }
            });
            return false;
        }
        function hideDetailsModal() {
            document.getElementById('details-modal').style.display = 'none';
        }
        function buildDetailsView(data) {
            var html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px;">';

            // Employee Information
            html += '<div style="grid-column:1/-1;">';
            html += '<h3><?php esc_html_e('Employee Information', 'sfs-hr'); ?></h3>';
            html += '</div>';

            html += '<div><strong><?php esc_html_e('Name:', 'sfs-hr'); ?></strong><br>' + data.employee_name + '</div>';
            html += '<div><strong><?php esc_html_e('Employee Code:', 'sfs-hr'); ?></strong><br>' + data.employee_code + '</div>';

            // Resignation Details
            html += '<div style="grid-column:1/-1;margin-top:15px;">';
            html += '<h3><?php esc_html_e('Resignation Details', 'sfs-hr'); ?></h3>';
            html += '</div>';

            var typeLabel = (data.resignation_type === 'final_exit') ? '<?php echo esc_js(__('Final Exit', 'sfs-hr')); ?>' : '<?php echo esc_js(__('Regular', 'sfs-hr')); ?>';
            html += '<div><strong><?php esc_html_e('Type:', 'sfs-hr'); ?></strong><br>' + typeLabel + '</div>';
            html += '<div><strong><?php esc_html_e('Resignation Date:', 'sfs-hr'); ?></strong><br>' + data.resignation_date + '</div>';

            // Final Exit information if applicable
            if (data.resignation_type === 'final_exit') {
                html += '<div style="grid-column:1/-1;margin-top:15px;">';
                html += '<h3><?php esc_html_e('Final Exit Information', 'sfs-hr'); ?></h3>';
                html += '</div>';

                var statusLabel = data.final_exit_status ? ucwords(data.final_exit_status.replace(/_/g, ' ')) : 'N/A';
                html += '<div><strong><?php esc_html_e('Status:', 'sfs-hr'); ?></strong><br>' + statusLabel + '</div>';

                if (data.final_exit_number) {
                    html += '<div><strong><?php esc_html_e('Exit Number:', 'sfs-hr'); ?></strong><br>' + data.final_exit_number + '</div>';
                }

                if (data.government_reference) {
                    html += '<div><strong><?php esc_html_e('Government Reference:', 'sfs-hr'); ?></strong><br>' + data.government_reference + '</div>';
                }

                if (data.expected_country_exit_date) {
                    html += '<div><strong><?php esc_html_e('Expected Exit Date:', 'sfs-hr'); ?></strong><br>' + data.expected_country_exit_date + '</div>';
                }

                if (data.actual_exit_date) {
                    html += '<div><strong><?php esc_html_e('Actual Exit Date:', 'sfs-hr'); ?></strong><br>' + data.actual_exit_date + '</div>';
                }

                if (data.final_exit_date) {
                    html += '<div><strong><?php esc_html_e('Exit Issue Date:', 'sfs-hr'); ?></strong><br>' + data.final_exit_date + '</div>';
                }

                if (data.final_exit_submitted_date) {
                    html += '<div><strong><?php esc_html_e('Submission Date:', 'sfs-hr'); ?></strong><br>' + data.final_exit_submitted_date + '</div>';
                }

                html += '<div><strong><?php esc_html_e('Ticket Booked:', 'sfs-hr'); ?></strong><br>' + (data.ticket_booked == 1 ? '<?php echo esc_js(__('Yes', 'sfs-hr')); ?>' : '<?php echo esc_js(__('No', 'sfs-hr')); ?>') + '</div>';
                html += '<div><strong><?php esc_html_e('Exit Stamp Received:', 'sfs-hr'); ?></strong><br>' + (data.exit_stamp_received == 1 ? '<?php echo esc_js(__('Yes', 'sfs-hr')); ?>' : '<?php echo esc_js(__('No', 'sfs-hr')); ?>') + '</div>';
            }

            html += '</div>';
            return html;
        }
        function ucwords(str) {
            return str.toLowerCase().replace(/\b\w/g, function(l) { return l.toUpperCase(); });
        }
        function showFinalExitModal(id) {
            document.getElementById('final-exit-modal').style.display = 'block';

            // Load resignation data via AJAX
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
            document.getElementById('final-exit-modal').style.display = 'none';
        }
        function buildFinalExitForm(data) {
            var html = '<form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">';
            html += '<?php wp_nonce_field('sfs_hr_final_exit_update', '_wpnonce', true, false); ?>';
            html += '<input type="hidden" name="action" value="sfs_hr_final_exit_update">';
            html += '<input type="hidden" name="resignation_id" value="' + data.id + '">';

            html += '<div style="margin-bottom:20px;">';
            html += '<h3><?php esc_html_e('Employee Information', 'sfs-hr'); ?></h3>';
            html += '<p><strong><?php esc_html_e('Name:', 'sfs-hr'); ?></strong> ' + data.employee_name + '</p>';
            html += '<p><strong><?php esc_html_e('Employee Code:', 'sfs-hr'); ?></strong> ' + data.employee_code + '</p>';
            html += '<p><strong><?php esc_html_e('Resignation Date:', 'sfs-hr'); ?></strong> ' + data.resignation_date + '</p>';
            html += '<p><strong><?php esc_html_e('Expected Exit Date:', 'sfs-hr'); ?></strong> ' + (data.expected_country_exit_date || 'N/A') + '</p>';
            html += '</div>';

            html += '<div style="margin-bottom:20px;padding:20px;background:#f0f0f1;border-radius:4px;">';
            html += '<h3><?php esc_html_e('Government Processing', 'sfs-hr'); ?></h3>';

            html += '<p>';
            html += '<label><strong><?php esc_html_e('Final Exit Status:', 'sfs-hr'); ?></strong></label><br>';
            html += '<select name="final_exit_status" style="width:100%;max-width:300px;padding:8px;">';
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
            html += '</select>';
            html += '</p>';

            html += '<p>';
            html += '<label><strong><?php esc_html_e('Government Submission Date:', 'sfs-hr'); ?></strong></label><br>';
            html += '<input type="date" name="final_exit_submitted_date" value="' + (data.final_exit_submitted_date || '') + '" style="width:100%;max-width:300px;padding:8px;">';
            html += '</p>';

            html += '<p>';
            html += '<label><strong><?php esc_html_e('Government Reference Number:', 'sfs-hr'); ?></strong></label><br>';
            html += '<input type="text" name="government_reference" value="' + (data.government_reference || '') + '" style="width:100%;max-width:400px;padding:8px;">';
            html += '</p>';

            html += '<p>';
            html += '<label><strong><?php esc_html_e('Final Exit Issue Date:', 'sfs-hr'); ?></strong></label><br>';
            html += '<input type="date" name="final_exit_date" value="' + (data.final_exit_date || '') + '" style="width:100%;max-width:300px;padding:8px;">';
            html += '</p>';

            html += '<p>';
            html += '<label><strong><?php esc_html_e('Final Exit Number:', 'sfs-hr'); ?></strong></label><br>';
            html += '<input type="text" name="final_exit_number" value="' + (data.final_exit_number || '') + '" style="width:100%;max-width:400px;padding:8px;">';
            html += '</p>';
            html += '</div>';

            html += '<div style="margin-bottom:20px;padding:20px;background:#f9f9f9;border-radius:4px;">';
            html += '<h3><?php esc_html_e('Exit Tracking', 'sfs-hr'); ?></h3>';

            html += '<p>';
            html += '<label>';
            html += '<input type="checkbox" name="ticket_booked" value="1"' + (data.ticket_booked == 1 ? ' checked' : '') + '> ';
            html += '<strong><?php esc_html_e('Ticket Booked', 'sfs-hr'); ?></strong>';
            html += '</label>';
            html += '</p>';

            html += '<p>';
            html += '<label><strong><?php esc_html_e('Actual Exit Date:', 'sfs-hr'); ?></strong></label><br>';
            html += '<input type="date" name="actual_exit_date" value="' + (data.actual_exit_date || '') + '" style="width:100%;max-width:300px;padding:8px;">';
            html += '</p>';

            html += '<p>';
            html += '<label>';
            html += '<input type="checkbox" name="exit_stamp_received" value="1"' + (data.exit_stamp_received == 1 ? ' checked' : '') + '> ';
            html += '<strong><?php esc_html_e('Exit Stamp Received', 'sfs-hr'); ?></strong>';
            html += '</label>';
            html += '</p>';
            html += '</div>';

            html += '<p>';
            html += '<button type="submit" class="button button-primary"><?php esc_html_e('Save Final Exit Data', 'sfs-hr'); ?></button> ';
            html += '<button type="button" onclick="hideFinalExitModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>';
            html += '</p>';
            html += '</form>';

            return html;
        }
        </script>
        <?php
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

        // If final approval, mark as approved
        if ($is_final_approval) {
            $update_data['status'] = 'approved';
            $update_data['decided_at'] = current_time('mysql');

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
            } else {
                $label = __('Pending', 'sfs-hr');
            }
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
            'final_exit_status'          => $resignation['final_exit_status'] ?? 'not_required',
            'final_exit_number'          => $resignation['final_exit_number'] ?? '',
            'final_exit_date'            => $resignation['final_exit_date'] ?? '',
            'final_exit_submitted_date'  => $resignation['final_exit_submitted_date'] ?? '',
            'government_reference'       => $resignation['government_reference'] ?? '',
            'expected_country_exit_date' => $resignation['expected_country_exit_date'] ?? '',
            'actual_exit_date'           => $resignation['actual_exit_date'] ?? '',
            'ticket_booked'              => $resignation['ticket_booked'] ?? 0,
            'exit_stamp_received'        => $resignation['exit_stamp_received'] ?? 0,
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

        $update_data = [
            'final_exit_status'          => sanitize_text_field($_POST['final_exit_status'] ?? 'not_required'),
            'final_exit_number'          => sanitize_text_field($_POST['final_exit_number'] ?? ''),
            'final_exit_date'            => sanitize_text_field($_POST['final_exit_date'] ?? ''),
            'final_exit_submitted_date'  => sanitize_text_field($_POST['final_exit_submitted_date'] ?? ''),
            'government_reference'       => sanitize_text_field($_POST['government_reference'] ?? ''),
            'actual_exit_date'           => sanitize_text_field($_POST['actual_exit_date'] ?? ''),
            'ticket_booked'              => isset($_POST['ticket_booked']) ? 1 : 0,
            'exit_stamp_received'        => isset($_POST['exit_stamp_received']) ? 1 : 0,
            'updated_at'                 => current_time('mysql'),
        ];

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