<?php
namespace SFS\HR\Modules\Settlement;

use SFS\HR\Core\Helpers;

if (!defined('ABSPATH')) { exit; }

class SettlementModule {

    public function hooks(): void {
        add_action('admin_menu', [$this, 'menu']);

        // Form handlers
        add_action('admin_post_sfs_hr_settlement_create',  [$this, 'handle_create']);
        add_action('admin_post_sfs_hr_settlement_update',  [$this, 'handle_update']);
        add_action('admin_post_sfs_hr_settlement_approve', [$this, 'handle_approve']);
        add_action('admin_post_sfs_hr_settlement_reject',  [$this, 'handle_reject']);
        add_action('admin_post_sfs_hr_settlement_payment', [$this, 'handle_payment']);
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __('Settlements', 'sfs-hr'),
            __('Settlements', 'sfs-hr'),
            'sfs_hr.manage',
            'sfs-hr-settlements',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        global $wpdb;

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        $settlement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($action === 'create') {
            $this->render_create_form();
        } elseif ($action === 'edit' && $settlement_id) {
            $this->render_edit_form($settlement_id);
        } elseif ($action === 'view' && $settlement_id) {
            $this->render_view($settlement_id);
        } else {
            $this->render_list();
        }
    }

    /* ---------------------------------- List View ---------------------------------- */

    private function render_list(): void {
        global $wpdb;

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'pending';
        $page   = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $pp     = 20;
        $offset = ($page - 1) * $pp;

        $table = $wpdb->prefix . 'sfs_hr_settlements';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $where  = '1=1';
        $params = [];

        if (in_array($status, ['pending', 'approved', 'rejected', 'paid'], true)) {
            $where   .= " AND s.status = %s";
            $params[] = $status;
        }

        $sql_total = "SELECT COUNT(*)
                      FROM $table s
                      JOIN $emp_t e ON e.id = s.employee_id
                      WHERE $where";
        $total = $params ? (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params))
                         : (int)$wpdb->get_var($sql_total);

        $total_pages = ceil($total / $pp);

        $sql = "SELECT s.*, e.employee_code, e.first_name, e.last_name
                FROM $table s
                JOIN $emp_t e ON e.id = s.employee_id
                WHERE $where
                ORDER BY s.id DESC
                LIMIT %d OFFSET %d";

        $params_all = array_merge($params, [$pp, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_all), ARRAY_A);

        ?>
        <style>
          /* Settlement Page Styles */
          .sfs-hr-settlement-toolbar {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            padding: 16px;
            margin: 16px 0;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
          }
          .sfs-hr-settlement-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            list-style: none;
            margin: 0;
            padding: 0;
          }
          .sfs-hr-settlement-tabs li {
            margin: 0;
          }
          .sfs-hr-settlement-tabs a {
            display: inline-block;
            padding: 8px 16px;
            background: #f0f0f1;
            border-radius: 4px;
            text-decoration: none;
            color: #50575e;
            font-size: 13px;
            font-weight: 500;
          }
          .sfs-hr-settlement-tabs a:hover {
            background: #dcdcde;
          }
          .sfs-hr-settlement-tabs a.current {
            background: #2271b1;
            color: #fff;
          }
          .sfs-hr-settlement-table {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            margin-top: 16px;
          }
          .sfs-hr-settlement-table .widefat {
            border: none;
            border-radius: 6px;
            margin: 0;
          }
          .sfs-hr-settlement-table .widefat th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #50575e;
            padding: 12px 16px;
          }
          .sfs-hr-settlement-table .widefat td {
            padding: 12px 16px;
            vertical-align: middle;
          }
          .sfs-hr-settlement-table .widefat tbody tr:hover {
            background: #f8f9fa;
          }
          .sfs-hr-settlement-table .emp-name {
            font-weight: 500;
            color: #1d2327;
          }
          .sfs-hr-settlement-table .emp-code {
            font-family: monospace;
            font-size: 11px;
            color: #50575e;
            display: block;
            margin-top: 2px;
          }
          .sfs-hr-settlement-table .amount {
            font-weight: 600;
            color: #1d2327;
          }
          .sfs-hr-settlement-actions {
            display: flex;
            gap: 6px;
          }
          .sfs-hr-settlement-actions .button {
            font-size: 12px;
            padding: 4px 10px;
            height: auto;
            line-height: 1.4;
            border-radius: 4px;
          }

          /* Mobile details button */
          .sfs-hr-settlement-mobile-btn {
            display: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #2271b1;
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 16px;
            padding: 0;
            align-items: center;
            justify-content: center;
          }
          .sfs-hr-settlement-mobile-btn:hover {
            background: #135e96;
          }

          /* Settlement Modal */
          .sfs-hr-settlement-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 100000;
            background: rgba(0,0,0,0.5);
            align-items: flex-end;
            justify-content: center;
          }
          .sfs-hr-settlement-modal.active {
            display: flex;
          }
          .sfs-hr-settlement-modal-content {
            background: #fff;
            width: 100%;
            max-width: 400px;
            border-radius: 16px 16px 0 0;
            padding: 20px;
            animation: sfsSettlementSlideUp 0.2s ease-out;
            max-height: 80vh;
            overflow-y: auto;
          }
          @keyframes sfsSettlementSlideUp {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
          }
          .sfs-hr-settlement-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e5e5e5;
          }
          .sfs-hr-settlement-modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #1d2327;
            margin: 0;
          }
          .sfs-hr-settlement-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #50575e;
            padding: 0;
            line-height: 1;
          }
          .sfs-hr-settlement-details-list {
            list-style: none;
            margin: 0 0 16px;
            padding: 0;
          }
          .sfs-hr-settlement-details-list li {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f1;
          }
          .sfs-hr-settlement-details-list li:last-child {
            border-bottom: none;
          }
          .sfs-hr-settlement-label {
            font-weight: 500;
            color: #50575e;
            font-size: 13px;
          }
          .sfs-hr-settlement-value {
            color: #1d2327;
            font-size: 13px;
            text-align: right;
          }
          .sfs-hr-settlement-modal-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
          }
          .sfs-hr-settlement-modal-buttons .button {
            width: 100%;
            padding: 12px 20px;
            font-size: 14px;
            border-radius: 8px;
            text-align: center;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
          }

          /* Status badges */
          .sfs-hr-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
          }
          .sfs-hr-status-badge.status-pending { background: #fef3c7; color: #92400e; }
          .sfs-hr-status-badge.status-approved { background: #d1fae5; color: #065f46; }
          .sfs-hr-status-badge.status-paid { background: #dbeafe; color: #1e40af; }
          .sfs-hr-status-badge.status-rejected { background: #fee2e2; color: #991b1b; }

          /* Pagination */
          .sfs-hr-settlement-pagination {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 16px;
            background: #fff;
            border: 1px solid #dcdcde;
            border-top: none;
            border-radius: 0 0 6px 6px;
            flex-wrap: wrap;
          }
          .sfs-hr-settlement-pagination a,
          .sfs-hr-settlement-pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
          }
          .sfs-hr-settlement-pagination a {
            background: #f0f0f1;
            color: #50575e;
          }
          .sfs-hr-settlement-pagination a:hover {
            background: #dcdcde;
          }
          .sfs-hr-settlement-pagination .current-page {
            background: #2271b1;
            color: #fff;
            font-weight: 600;
          }

          /* Mobile responsive */
          @media (max-width: 782px) {
            .sfs-hr-settlement-toolbar {
              flex-direction: column;
              align-items: stretch;
              padding: 12px;
            }
            .sfs-hr-settlement-tabs {
              width: 100%;
              justify-content: center;
            }
            .sfs-hr-settlement-tabs a {
              flex: 1;
              text-align: center;
              padding: 10px 8px;
              font-size: 12px;
            }
            .sfs-hr-settlement-toolbar .page-title-action {
              width: 100%;
              text-align: center;
              margin: 0;
            }

            /* Hide columns on mobile - only show Employee, Amount, Status, Actions */
            .sfs-hr-settlement-table .widefat thead th.hide-mobile,
            .sfs-hr-settlement-table .widefat tbody td.hide-mobile {
              display: none !important;
            }

            .sfs-hr-settlement-table .widefat th,
            .sfs-hr-settlement-table .widefat td {
              padding: 10px 12px;
            }

            /* Hide desktop actions, show mobile button */
            .sfs-hr-settlement-actions {
              display: none !important;
            }
            .sfs-hr-settlement-mobile-btn {
              display: inline-flex;
            }

            .sfs-hr-settlement-pagination {
              justify-content: center;
            }
          }
        </style>

        <!-- Settlement Modal -->
        <div class="sfs-hr-settlement-modal" id="sfs-hr-settlement-modal">
          <div class="sfs-hr-settlement-modal-content">
            <div class="sfs-hr-settlement-modal-header">
              <h3 class="sfs-hr-settlement-modal-title" id="sfs-hr-settlement-modal-name">Settlement Details</h3>
              <button type="button" class="sfs-hr-settlement-modal-close" onclick="sfsHrCloseSettlementModal()">&times;</button>
            </div>
            <ul class="sfs-hr-settlement-details-list">
              <li><span class="sfs-hr-settlement-label">Employee Code</span><span class="sfs-hr-settlement-value" id="sfs-hr-settlement-code"></span></li>
              <li><span class="sfs-hr-settlement-label">Last Working Day</span><span class="sfs-hr-settlement-value" id="sfs-hr-settlement-lwd"></span></li>
              <li><span class="sfs-hr-settlement-label">Service Years</span><span class="sfs-hr-settlement-value" id="sfs-hr-settlement-years"></span></li>
              <li><span class="sfs-hr-settlement-label">Total Amount</span><span class="sfs-hr-settlement-value" id="sfs-hr-settlement-amount" style="font-weight:600;"></span></li>
              <li><span class="sfs-hr-settlement-label">Status</span><span class="sfs-hr-settlement-value" id="sfs-hr-settlement-status"></span></li>
            </ul>
            <div class="sfs-hr-settlement-modal-buttons">
              <a href="#" class="button button-primary" id="sfs-hr-settlement-view-btn">
                <span class="dashicons dashicons-visibility"></span> View Details
              </a>
              <a href="#" class="button button-secondary" id="sfs-hr-settlement-edit-btn" style="display:none;">
                <span class="dashicons dashicons-edit"></span> Edit Settlement
              </a>
            </div>
          </div>
        </div>

        <script>
        function sfsHrOpenSettlementModal(name, code, lwd, years, amount, status, viewUrl, editUrl, canEdit) {
          document.getElementById('sfs-hr-settlement-modal-name').textContent = name || 'Settlement Details';
          document.getElementById('sfs-hr-settlement-code').textContent = code;
          document.getElementById('sfs-hr-settlement-lwd').textContent = lwd;
          document.getElementById('sfs-hr-settlement-years').textContent = years;
          document.getElementById('sfs-hr-settlement-amount').textContent = amount;
          document.getElementById('sfs-hr-settlement-status').textContent = status;
          document.getElementById('sfs-hr-settlement-view-btn').href = viewUrl;

          var editBtn = document.getElementById('sfs-hr-settlement-edit-btn');
          if (canEdit === '1') {
            editBtn.href = editUrl;
            editBtn.style.display = 'flex';
          } else {
            editBtn.style.display = 'none';
          }

          document.getElementById('sfs-hr-settlement-modal').classList.add('active');
          document.body.style.overflow = 'hidden';
        }
        function sfsHrCloseSettlementModal() {
          document.getElementById('sfs-hr-settlement-modal').classList.remove('active');
          document.body.style.overflow = '';
        }
        document.getElementById('sfs-hr-settlement-modal').addEventListener('click', function(e) {
          if (e.target === this) sfsHrCloseSettlementModal();
        });
        </script>

        <div class="wrap sfs-hr-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('End of Service Settlements', 'sfs-hr'); ?></h1>
            <?php Helpers::render_admin_nav(); ?>

            <div class="sfs-hr-settlement-toolbar">
                <ul class="sfs-hr-settlement-tabs">
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&status=pending')); ?>"
                        class="<?php echo $status === 'pending' ? 'current' : ''; ?>">
                        <?php esc_html_e('Pending', 'sfs-hr'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&status=approved')); ?>"
                        class="<?php echo $status === 'approved' ? 'current' : ''; ?>">
                        <?php esc_html_e('Approved', 'sfs-hr'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&status=paid')); ?>"
                        class="<?php echo $status === 'paid' ? 'current' : ''; ?>">
                        <?php esc_html_e('Paid', 'sfs-hr'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&status=rejected')); ?>"
                        class="<?php echo $status === 'rejected' ? 'current' : ''; ?>">
                        <?php esc_html_e('Rejected', 'sfs-hr'); ?></a></li>
                </ul>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&action=create')); ?>" class="button button-primary">
                    <?php esc_html_e('Create Settlement', 'sfs-hr'); ?>
                </a>
            </div>

            <h2><?php echo esc_html(ucfirst($status)); ?> <?php esc_html_e('Settlements', 'sfs-hr'); ?> <span style="font-weight:normal; font-size:14px; color:#50575e;">(<?php echo (int)$total; ?>)</span></h2>

            <div class="sfs-hr-settlement-table">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th class="hide-mobile"><?php esc_html_e('ID', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                            <th class="hide-mobile"><?php esc_html_e('Last Working Day', 'sfs-hr'); ?></th>
                            <th class="hide-mobile"><?php esc_html_e('Service Years', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('Amount', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                            <th style="width:120px;"><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="7"><?php esc_html_e('No settlements found.', 'sfs-hr'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row):
                                $emp_name = $row['first_name'] . ' ' . $row['last_name'];
                                $view_url = admin_url('admin.php?page=sfs-hr-settlements&action=view&id=' . $row['id']);
                                $edit_url = admin_url('admin.php?page=sfs-hr-settlements&action=edit&id=' . $row['id']);
                                $can_edit = $row['status'] === 'pending' ? '1' : '0';
                            ?>
                                <tr>
                                    <td class="hide-mobile"><?php echo esc_html($row['id']); ?></td>
                                    <td>
                                        <span class="emp-name"><?php echo esc_html($emp_name); ?></span>
                                        <span class="emp-code"><?php echo esc_html($row['employee_code']); ?></span>
                                    </td>
                                    <td class="hide-mobile"><?php echo esc_html($row['last_working_day']); ?></td>
                                    <td class="hide-mobile"><?php echo esc_html(number_format($row['years_of_service'], 2)); ?> <?php esc_html_e('yrs', 'sfs-hr'); ?></td>
                                    <td><span class="amount"><?php echo esc_html(number_format($row['total_settlement'], 2)); ?></span></td>
                                    <td><span class="sfs-hr-status-badge status-<?php echo esc_attr($row['status']); ?>"><?php echo esc_html(ucfirst($row['status'])); ?></span></td>
                                    <td>
                                        <!-- Desktop actions -->
                                        <div class="sfs-hr-settlement-actions">
                                            <a href="<?php echo esc_url($view_url); ?>" class="button button-small">
                                                <?php esc_html_e('View', 'sfs-hr'); ?>
                                            </a>
                                            <?php if ($row['status'] === 'pending'): ?>
                                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">
                                                    <?php esc_html_e('Edit', 'sfs-hr'); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Mobile button -->
                                        <button type="button" class="sfs-hr-settlement-mobile-btn" onclick="sfsHrOpenSettlementModal('<?php echo esc_js($emp_name); ?>', '<?php echo esc_js($row['employee_code']); ?>', '<?php echo esc_js($row['last_working_day']); ?>', '<?php echo esc_js(number_format($row['years_of_service'], 2) . ' years'); ?>', '<?php echo esc_js(number_format($row['total_settlement'], 2)); ?>', '<?php echo esc_js(ucfirst($row['status'])); ?>', '<?php echo esc_js($view_url); ?>', '<?php echo esc_js($edit_url); ?>', '<?php echo $can_edit; ?>')">
                                            <span class="dashicons dashicons-ellipsis"></span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="sfs-hr-settlement-pagination">
                <?php for($i=1; $i<=$total_pages; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current-page"><?php echo (int)$i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo esc_url(add_query_arg(['paged' => $i, 'status' => $status], admin_url('admin.php?page=sfs-hr-settlements'))); ?>"><?php echo (int)$i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------- Create Form ---------------------------------- */

    private function render_create_form(): void {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';

        // Get approved resignations without settlements
        $sql = "SELECT r.*, e.employee_code, e.first_name, e.last_name, e.base_salary, e.hire_date, e.hired_at
                FROM $resign_table r
                JOIN $emp_table e ON e.id = r.employee_id
                WHERE r.status = 'approved'
                AND r.id NOT IN (SELECT resignation_id FROM {$wpdb->prefix}sfs_hr_settlements WHERE resignation_id IS NOT NULL)
                ORDER BY r.last_working_day DESC";

        $pending_resignations = $wpdb->get_results($sql, ARRAY_A);

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e('Create Settlement', 'sfs-hr'); ?></h1>
            <?php Helpers::render_admin_nav(); ?>

            <?php if (empty($pending_resignations)): ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e('No approved resignations pending settlement. All approved resignations have been processed.', 'sfs-hr'); ?></p>
                </div>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements')); ?>" class="button">&larr; <?php esc_html_e('Back to Settlements', 'sfs-hr'); ?></a></p>
            <?php else: ?>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="settlement-form">
                <?php wp_nonce_field('sfs_hr_settlement_create'); ?>
                <input type="hidden" name="action" value="sfs_hr_settlement_create">

                <table class="form-table">
                    <tr>
                        <th><label for="resignation_id"><?php esc_html_e('Select Resignation:', 'sfs-hr'); ?> <span style="color:red;">*</span></label></th>
                        <td>
                            <select name="resignation_id" id="resignation_id" required style="width:100%;max-width:500px;" onchange="loadResignationDetails(this.value)">
                                <option value=""><?php esc_html_e('-- Select Resignation --', 'sfs-hr'); ?></option>
                                <?php foreach ($pending_resignations as $r): ?>
                                    <option value="<?php echo esc_attr($r['id']); ?>"
                                            data-employee-id="<?php echo esc_attr($r['employee_id']); ?>"
                                            data-last-working-day="<?php echo esc_attr($r['last_working_day']); ?>"
                                            data-employee-name="<?php echo esc_attr($r['first_name'] . ' ' . $r['last_name']); ?>"
                                            data-base-salary="<?php echo esc_attr($r['base_salary'] ?? 0); ?>"
                                            data-hire-date="<?php echo esc_attr($r['hire_date'] ?? $r['hired_at'] ?? ''); ?>">
                                        <?php echo esc_html($r['first_name'] . ' ' . $r['last_name'] . ' - ' . $r['employee_code']); ?>
                                        (<?php esc_html_e('LWD:', 'sfs-hr'); ?> <?php echo esc_html($r['last_working_day']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Select an approved resignation to create a settlement.', 'sfs-hr'); ?></p>
                        </td>
                    </tr>
                </table>

                <div id="settlement-details" style="display:none;margin-top:20px;">
                    <h2><?php esc_html_e('Settlement Calculation', 'sfs-hr'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Employee Name:', 'sfs-hr'); ?></th>
                            <td><span id="display-employee-name"></span></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Last Working Day:', 'sfs-hr'); ?></th>
                            <td><span id="display-last-working-day"></span></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Years of Service:', 'sfs-hr'); ?></th>
                            <td><span id="display-years-of-service"></span></td>
                        </tr>
                        <tr>
                            <th><label for="basic_salary"><?php esc_html_e('Basic Salary:', 'sfs-hr'); ?> <span style="color:red;">*</span></label></th>
                            <td><input type="number" name="basic_salary" id="basic_salary" step="0.01" required style="width:200px;" onchange="calculateSettlement()"></td>
                        </tr>
                        <tr>
                            <th><label for="gratuity_amount"><?php esc_html_e('Gratuity Amount:', 'sfs-hr'); ?></label></th>
                            <td>
                                <input type="number" name="gratuity_amount" id="gratuity_amount" step="0.01" readonly style="width:200px;background:#f5f5f5;">
                                <button type="button" onclick="calculateGratuity()" class="button"><?php esc_html_e('Calculate', 'sfs-hr'); ?></button>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="unused_leave_days"><?php esc_html_e('Unused Leave Days:', 'sfs-hr'); ?></label></th>
                            <td><input type="number" name="unused_leave_days" id="unused_leave_days" value="0" min="0" style="width:200px;" onchange="calculateSettlement()"></td>
                        </tr>
                        <tr>
                            <th><label for="leave_encashment"><?php esc_html_e('Leave Encashment:', 'sfs-hr'); ?></label></th>
                            <td><input type="number" name="leave_encashment" id="leave_encashment" step="0.01" readonly style="width:200px;background:#f5f5f5;"></td>
                        </tr>
                        <tr>
                            <th><label for="final_salary"><?php esc_html_e('Final Salary:', 'sfs-hr'); ?></label></th>
                            <td><input type="number" name="final_salary" id="final_salary" step="0.01" value="0" style="width:200px;" onchange="calculateSettlement()"></td>
                        </tr>
                        <tr>
                            <th><label for="other_allowances"><?php esc_html_e('Other Allowances:', 'sfs-hr'); ?></label></th>
                            <td><input type="number" name="other_allowances" id="other_allowances" step="0.01" value="0" style="width:200px;" onchange="calculateSettlement()"></td>
                        </tr>
                        <tr>
                            <th><label for="deductions"><?php esc_html_e('Deductions:', 'sfs-hr'); ?></label></th>
                            <td><input type="number" name="deductions" id="deductions" step="0.01" value="0" style="width:200px;" onchange="calculateSettlement()"></td>
                        </tr>
                        <tr>
                            <th><label for="deduction_notes"><?php esc_html_e('Deduction Notes:', 'sfs-hr'); ?></label></th>
                            <td><textarea name="deduction_notes" id="deduction_notes" rows="3" style="width:100%;max-width:500px;"></textarea></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Total Settlement:', 'sfs-hr'); ?></th>
                            <td><strong style="font-size:18px;color:#0073aa;" id="display-total-settlement">0.00</strong></td>
                        </tr>
                    </table>

                    <input type="hidden" name="employee_id" id="employee_id">
                    <input type="hidden" name="last_working_day" id="last_working_day">
                    <input type="hidden" name="years_of_service" id="years_of_service">
                    <input type="hidden" name="total_settlement" id="total_settlement">

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Create Settlement', 'sfs-hr'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements')); ?>" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></a>
                    </p>
                </div>
            </form>

            <script>
            function loadResignationDetails(resignationId) {
                if (!resignationId) {
                    document.getElementById('settlement-details').style.display = 'none';
                    return;
                }

                const select = document.getElementById('resignation_id');
                const option = select.options[select.selectedIndex];

                const employeeId = option.getAttribute('data-employee-id');
                const lastWorkingDay = option.getAttribute('data-last-working-day');
                const employeeName = option.getAttribute('data-employee-name');
                const baseSalary = parseFloat(option.getAttribute('data-base-salary')) || 0;
                const hireDate = option.getAttribute('data-hire-date');

                // Calculate years of service
                const yearsOfService = calculateYearsOfService(hireDate, lastWorkingDay);

                // Populate fields
                document.getElementById('employee_id').value = employeeId;
                document.getElementById('last_working_day').value = lastWorkingDay;
                document.getElementById('years_of_service').value = yearsOfService;
                document.getElementById('basic_salary').value = baseSalary.toFixed(2);

                document.getElementById('display-employee-name').textContent = employeeName;
                document.getElementById('display-last-working-day').textContent = lastWorkingDay;
                document.getElementById('display-years-of-service').textContent = yearsOfService.toFixed(2) + ' years';

                document.getElementById('settlement-details').style.display = 'block';

                // Auto-calculate gratuity
                calculateGratuity();
            }

            function calculateYearsOfService(hireDate, lastWorkingDay) {
                if (!hireDate || !lastWorkingDay) return 0;

                const hire = new Date(hireDate);
                const lwd = new Date(lastWorkingDay);
                const diffTime = Math.abs(lwd - hire);
                const diffDays = diffTime / (1000 * 60 * 60 * 24);
                return diffDays / 365.25;
            }

            function calculateGratuity() {
                const yearsOfService = parseFloat(document.getElementById('years_of_service').value) || 0;
                const baseSalary = parseFloat(document.getElementById('basic_salary').value) || 0;

                // Gratuity calculation: 21 days salary for each year of service (for first 5 years)
                // 30 days salary for each year beyond 5 years
                let gratuity = 0;

                if (yearsOfService <= 5) {
                    gratuity = (baseSalary / 30) * 21 * yearsOfService;
                } else {
                    const first5Years = (baseSalary / 30) * 21 * 5;
                    const remainingYears = yearsOfService - 5;
                    const afterYears = (baseSalary / 30) * 30 * remainingYears;
                    gratuity = first5Years + afterYears;
                }

                document.getElementById('gratuity_amount').value = gratuity.toFixed(2);
                calculateSettlement();
            }

            function calculateSettlement() {
                const gratuity = parseFloat(document.getElementById('gratuity_amount').value) || 0;
                const unusedLeaveDays = parseFloat(document.getElementById('unused_leave_days').value) || 0;
                const baseSalary = parseFloat(document.getElementById('basic_salary').value) || 0;
                const finalSalary = parseFloat(document.getElementById('final_salary').value) || 0;
                const otherAllowances = parseFloat(document.getElementById('other_allowances').value) || 0;
                const deductions = parseFloat(document.getElementById('deductions').value) || 0;

                // Calculate leave encashment (daily rate * unused days)
                const dailyRate = baseSalary / 30;
                const leaveEncashment = dailyRate * unusedLeaveDays;
                document.getElementById('leave_encashment').value = leaveEncashment.toFixed(2);

                // Calculate total
                const total = gratuity + leaveEncashment + finalSalary + otherAllowances - deductions;

                document.getElementById('total_settlement').value = total.toFixed(2);
                document.getElementById('display-total-settlement').textContent = total.toFixed(2);
            }
            </script>

            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------- View Settlement ---------------------------------- */

    private function render_view(int $settlement_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $settlement = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, e.employee_code, e.first_name, e.last_name, e.position, e.dept_id
             FROM $table s
             JOIN $emp_t e ON e.id = s.employee_id
             WHERE s.id = %d",
            $settlement_id
        ), ARRAY_A);

        if (!$settlement) {
            wp_die(__('Settlement not found.', 'sfs-hr'));
        }

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e('Settlement Details', 'sfs-hr'); ?> #<?php echo esc_html($settlement['id']); ?></h1>
            <?php Helpers::render_admin_nav(); ?>

            <div style="background:#fff;padding:20px;border:1px solid #ddd;margin-top:20px;">
                <h2><?php esc_html_e('Employee Information', 'sfs-hr'); ?></h2>
                <table class="widefat">
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('Employee:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['first_name'] . ' ' . $settlement['last_name']); ?> (<?php echo esc_html($settlement['employee_code']); ?>)</td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Position:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['position'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Last Working Day:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['last_working_day']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Years of Service:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['years_of_service'], 2)); ?> <?php esc_html_e('years', 'sfs-hr'); ?></td>
                    </tr>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e('Settlement Breakdown', 'sfs-hr'); ?></h2>
                <table class="widefat">
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('Basic Salary:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['basic_salary'], 2)); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Gratuity Amount:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['gratuity_amount'], 2)); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Leave Encashment:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['leave_encashment'], 2)); ?>
                            (<?php echo esc_html($settlement['unused_leave_days']); ?> <?php esc_html_e('days', 'sfs-hr'); ?>)</td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Final Salary:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['final_salary'], 2)); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Other Allowances:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['other_allowances'], 2)); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Deductions:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['deductions'], 2)); ?>
                            <?php if (!empty($settlement['deduction_notes'])): ?>
                                <br><small><?php echo esc_html($settlement['deduction_notes']); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr style="background:#f5f5f5;font-weight:bold;font-size:16px;">
                        <th><?php esc_html_e('Total Settlement:', 'sfs-hr'); ?></th>
                        <td style="color:#0073aa;"><?php echo esc_html(number_format($settlement['total_settlement'], 2)); ?></td>
                    </tr>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e('Clearance Status', 'sfs-hr'); ?></h2>
                <table class="widefat">
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('Asset Clearance:', 'sfs-hr'); ?></th>
                        <td><?php echo $this->status_badge($settlement['asset_clearance_status']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Document Clearance:', 'sfs-hr'); ?></th>
                        <td><?php echo $this->status_badge($settlement['document_clearance_status']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Finance Clearance:', 'sfs-hr'); ?></th>
                        <td><?php echo $this->status_badge($settlement['finance_clearance_status']); ?></td>
                    </tr>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e('Loan Status', 'sfs-hr'); ?></h2>
                <?php
                // Check for active loans
                $has_loans = false;
                $outstanding_balance = 0;
                $loan_summary = null;

                if (class_exists('\SFS\HR\Modules\Loans\LoansModule')) {
                    $has_loans = \SFS\HR\Modules\Loans\LoansModule::has_active_loans($settlement['employee_id']);
                    $outstanding_balance = \SFS\HR\Modules\Loans\LoansModule::get_outstanding_balance($settlement['employee_id']);

                    if (class_exists('\SFS\HR\Modules\Loans\Admin\DashboardWidget')) {
                        $loan_summary = \SFS\HR\Modules\Loans\Admin\DashboardWidget::get_employee_loan_summary($settlement['employee_id']);
                    }
                }
                ?>
                <?php if ($has_loans && $outstanding_balance > 0): ?>
                <div class="notice notice-error inline" style="margin:0 0 20px 0;">
                    <p><strong><?php esc_html_e('⚠️ Outstanding Loan Alert:', 'sfs-hr'); ?></strong></p>
                    <p><?php echo sprintf(
                        esc_html__('This employee has an outstanding loan balance of %s SAR. Settlement cannot be finalized until all loans are settled with Finance.', 'sfs-hr'),
                        '<strong>' . number_format($outstanding_balance, 2) . '</strong>'
                    ); ?></p>
                    <?php if ($loan_summary): ?>
                    <p>
                        <strong><?php esc_html_e('Loan Details:', 'sfs-hr'); ?></strong><br>
                        <?php esc_html_e('Active Loans:', 'sfs-hr'); ?> <?php echo esc_html($loan_summary['loan_count']); ?><br>
                        <?php if (!empty($loan_summary['next_due_date'])): ?>
                            <?php esc_html_e('Next Payment Due:', 'sfs-hr'); ?> <?php echo esc_html($loan_summary['next_due_date']); ?><br>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-loans&employee_id=' . $settlement['employee_id'])); ?>" class="button button-small">
                            <?php esc_html_e('View Loans', 'sfs-hr'); ?>
                        </a>
                    </p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="notice notice-success inline" style="margin:0 0 20px 0;">
                    <p><strong><?php esc_html_e('✓ Loan Clearance:', 'sfs-hr'); ?></strong> <?php esc_html_e('No outstanding loans. Employee cleared for settlement.', 'sfs-hr'); ?></p>
                </div>
                <?php endif; ?>

                <h2 style="margin-top:30px;"><?php esc_html_e('Asset Return Status', 'sfs-hr'); ?></h2>
                <?php
                // Check for unreturned assets
                $has_unreturned_assets = false;
                $unreturned_assets = [];
                $unreturned_count = 0;

                if (class_exists('\SFS\HR\Modules\Assets\AssetsModule')) {
                    $has_unreturned_assets = \SFS\HR\Modules\Assets\AssetsModule::has_unreturned_assets($settlement['employee_id']);
                    $unreturned_count = \SFS\HR\Modules\Assets\AssetsModule::get_unreturned_assets_count($settlement['employee_id']);
                    $unreturned_assets = \SFS\HR\Modules\Assets\AssetsModule::get_unreturned_assets($settlement['employee_id']);
                }
                ?>
                <?php if ($has_unreturned_assets): ?>
                <div class="notice notice-error inline" style="margin:0 0 20px 0;">
                    <p><strong><?php esc_html_e('⚠️ Unreturned Assets Alert:', 'sfs-hr'); ?></strong></p>
                    <p><?php echo sprintf(
                        esc_html__('This employee has %d unreturned asset(s). Settlement cannot be finalized until all assets are returned.', 'sfs-hr'),
                        '<strong>' . $unreturned_count . '</strong>'
                    ); ?></p>
                    <?php if (!empty($unreturned_assets)): ?>
                    <p><strong><?php esc_html_e('Unreturned Assets:', 'sfs-hr'); ?></strong></p>
                    <ul style="margin-left:20px;">
                        <?php foreach ($unreturned_assets as $asset): ?>
                        <li>
                            <strong><?php echo esc_html($asset['asset_name']); ?></strong>
                            (<?php echo esc_html($asset['asset_code']); ?>)
                            - <?php echo esc_html($asset['category']); ?>
                            - <em><?php echo esc_html(ucfirst(str_replace('_', ' ', $asset['assignment_status']))); ?></em>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&id=' . $settlement['employee_id'] . '&tab=assets')); ?>" class="button button-small">
                            <?php esc_html_e('View Employee Assets', 'sfs-hr'); ?>
                        </a>
                    </p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="notice notice-success inline" style="margin:0 0 20px 0;">
                    <p><strong><?php esc_html_e('✓ Asset Clearance:', 'sfs-hr'); ?></strong> <?php esc_html_e('All assets returned. Employee cleared for settlement.', 'sfs-hr'); ?></p>
                </div>
                <?php endif; ?>

                <h2 style="margin-top:30px;"><?php esc_html_e('Approval & Payment', 'sfs-hr'); ?></h2>
                <table class="widefat">
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('Status:', 'sfs-hr'); ?></th>
                        <td><?php echo $this->status_badge($settlement['status']); ?></td>
                    </tr>
                    <?php if (!empty($settlement['approver_note'])): ?>
                    <tr>
                        <th><?php esc_html_e('Approver Note:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['approver_note']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($settlement['payment_date'])): ?>
                    <tr>
                        <th><?php esc_html_e('Payment Date:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['payment_date']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($settlement['payment_reference'])): ?>
                    <tr>
                        <th><?php esc_html_e('Payment Reference:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['payment_reference']); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php if ($settlement['status'] === 'pending'): ?>
                <div style="margin-top:30px;">
                    <a href="#" onclick="return showApproveModal();" class="button button-primary">
                        <?php esc_html_e('Approve Settlement', 'sfs-hr'); ?>
                    </a>
                    <a href="#" onclick="return showRejectModal();" class="button">
                        <?php esc_html_e('Reject', 'sfs-hr'); ?>
                    </a>
                </div>
                <?php elseif ($settlement['status'] === 'approved'): ?>
                <div style="margin-top:30px;">
                    <a href="#" onclick="return showPaymentModal();" class="button button-primary">
                        <?php esc_html_e('Mark as Paid', 'sfs-hr'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <p style="margin-top:20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements')); ?>" class="button">&larr; <?php esc_html_e('Back to Settlements', 'sfs-hr'); ?></a>
            </p>

            <!-- Approve Modal -->
            <div id="approve-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
                <div style="background:#fff; padding:20px; max-width:500px; margin:100px auto; border:1px solid #ccc;">
                    <h2><?php esc_html_e('Approve Settlement', 'sfs-hr'); ?></h2>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sfs_hr_settlement_approve'); ?>
                        <input type="hidden" name="action" value="sfs_hr_settlement_approve">
                        <input type="hidden" name="settlement_id" value="<?php echo esc_attr($settlement['id']); ?>">
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
            <div id="reject-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
                <div style="background:#fff; padding:20px; max-width:500px; margin:100px auto; border:1px solid #ccc;">
                    <h2><?php esc_html_e('Reject Settlement', 'sfs-hr'); ?></h2>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sfs_hr_settlement_reject'); ?>
                        <input type="hidden" name="action" value="sfs_hr_settlement_reject">
                        <input type="hidden" name="settlement_id" value="<?php echo esc_attr($settlement['id']); ?>">
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

            <!-- Payment Modal -->
            <div id="payment-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
                <div style="background:#fff; padding:20px; max-width:500px; margin:100px auto; border:1px solid #ccc;">
                    <h2><?php esc_html_e('Record Payment', 'sfs-hr'); ?></h2>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sfs_hr_settlement_payment'); ?>
                        <input type="hidden" name="action" value="sfs_hr_settlement_payment">
                        <input type="hidden" name="settlement_id" value="<?php echo esc_attr($settlement['id']); ?>">
                        <p>
                            <label><?php esc_html_e('Payment Date:', 'sfs-hr'); ?></label><br>
                            <input type="date" name="payment_date" required style="width:100%;">
                        </p>
                        <p>
                            <label><?php esc_html_e('Payment Reference:', 'sfs-hr'); ?></label><br>
                            <input type="text" name="payment_reference" style="width:100%;">
                        </p>
                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Mark as Paid', 'sfs-hr'); ?></button>
                            <button type="button" onclick="hidePaymentModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                        </p>
                    </form>
                </div>
            </div>

            <script>
            function showApproveModal() {
                document.getElementById('approve-modal').style.display = 'block';
                return false;
            }
            function hideApproveModal() {
                document.getElementById('approve-modal').style.display = 'none';
            }
            function showRejectModal() {
                document.getElementById('reject-modal').style.display = 'block';
                return false;
            }
            function hideRejectModal() {
                document.getElementById('reject-modal').style.display = 'none';
            }
            function showPaymentModal() {
                document.getElementById('payment-modal').style.display = 'block';
                return false;
            }
            function hidePaymentModal() {
                document.getElementById('payment-modal').style.display = 'none';
            }
            </script>
        </div>
        <?php
    }

    /* ---------------------------------- Form Handlers ---------------------------------- */

    public function handle_create(): void {
        check_admin_referer('sfs_hr_settlement_create');
        Helpers::require_cap('sfs_hr.manage');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';

        $employee_id = intval($_POST['employee_id'] ?? 0);
        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        $last_working_day = sanitize_text_field($_POST['last_working_day'] ?? '');
        $years_of_service = floatval($_POST['years_of_service'] ?? 0);
        $basic_salary = floatval($_POST['basic_salary'] ?? 0);
        $gratuity_amount = floatval($_POST['gratuity_amount'] ?? 0);
        $unused_leave_days = intval($_POST['unused_leave_days'] ?? 0);
        $leave_encashment = floatval($_POST['leave_encashment'] ?? 0);
        $final_salary = floatval($_POST['final_salary'] ?? 0);
        $other_allowances = floatval($_POST['other_allowances'] ?? 0);
        $deductions = floatval($_POST['deductions'] ?? 0);
        $deduction_notes = sanitize_textarea_field($_POST['deduction_notes'] ?? '');
        $total_settlement = floatval($_POST['total_settlement'] ?? 0);

        $now = current_time('mysql');

        $wpdb->insert($table, [
            'employee_id' => $employee_id,
            'resignation_id' => $resignation_id,
            'settlement_date' => current_time('Y-m-d'),
            'last_working_day' => $last_working_day,
            'years_of_service' => $years_of_service,
            'basic_salary' => $basic_salary,
            'gratuity_amount' => $gratuity_amount,
            'leave_encashment' => $leave_encashment,
            'unused_leave_days' => $unused_leave_days,
            'final_salary' => $final_salary,
            'other_allowances' => $other_allowances,
            'deductions' => $deductions,
            'deduction_notes' => $deduction_notes,
            'total_settlement' => $total_settlement,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-settlements'),
            'success',
            __('Settlement created successfully.', 'sfs-hr')
        );
    }

    public function handle_approve(): void {
        check_admin_referer('sfs_hr_settlement_approve');
        Helpers::require_cap('sfs_hr.manage');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';

        $settlement_id = intval($_POST['settlement_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        // Get settlement details
        $settlement = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $settlement_id
        ), ARRAY_A);

        if (!$settlement) {
            wp_die(__('Settlement not found.', 'sfs-hr'));
        }

        // Check for outstanding loans
        if (class_exists('\SFS\HR\Modules\Loans\LoansModule')) {
            $has_loans = \SFS\HR\Modules\Loans\LoansModule::has_active_loans($settlement['employee_id']);
            $outstanding = \SFS\HR\Modules\Loans\LoansModule::get_outstanding_balance($settlement['employee_id']);

            if ($has_loans && $outstanding > 0) {
                wp_die(
                    sprintf(
                        '<h1>%s</h1><p>%s</p><p><a href="%s" class="button">%s</a> <a href="%s" class="button button-primary">%s</a></p>',
                        esc_html__('Settlement Approval Blocked', 'sfs-hr'),
                        sprintf(
                            esc_html__('Cannot approve settlement. Employee has outstanding loan balance of %s SAR. Please settle all loans with Finance department first.', 'sfs-hr'),
                            '<strong>' . number_format($outstanding, 2) . '</strong>'
                        ),
                        esc_url(admin_url('admin.php?page=sfs-hr-settlements&action=view&id=' . $settlement_id)),
                        esc_html__('Back to Settlement', 'sfs-hr'),
                        esc_url(admin_url('admin.php?page=sfs-hr-loans&employee_id=' . $settlement['employee_id'])),
                        esc_html__('View Employee Loans', 'sfs-hr')
                    )
                );
            }
        }

        // Check for unreturned assets
        if (class_exists('\SFS\HR\Modules\Assets\AssetsModule')) {
            $has_unreturned = \SFS\HR\Modules\Assets\AssetsModule::has_unreturned_assets($settlement['employee_id']);
            $unreturned_count = \SFS\HR\Modules\Assets\AssetsModule::get_unreturned_assets_count($settlement['employee_id']);

            if ($has_unreturned) {
                wp_die(
                    sprintf(
                        '<h1>%s</h1><p>%s</p><p><a href="%s" class="button">%s</a> <a href="%s" class="button button-primary">%s</a></p>',
                        esc_html__('Settlement Approval Blocked', 'sfs-hr'),
                        sprintf(
                            esc_html__('Cannot approve settlement. Employee has %d unreturned asset(s). All assets must be returned before settlement approval.', 'sfs-hr'),
                            '<strong>' . $unreturned_count . '</strong>'
                        ),
                        esc_url(admin_url('admin.php?page=sfs-hr-settlements&action=view&id=' . $settlement_id)),
                        esc_html__('Back to Settlement', 'sfs-hr'),
                        esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&id=' . $settlement['employee_id'] . '&tab=assets')),
                        esc_html__('View Employee Assets', 'sfs-hr')
                    )
                );
            }
        }

        $wpdb->update($table, [
            'status' => 'approved',
            'approver_id' => get_current_user_id(),
            'approver_note' => $note,
            'decided_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $settlement_id]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-settlements&action=view&id=' . $settlement_id),
            'success',
            __('Settlement approved successfully.', 'sfs-hr')
        );
    }

    public function handle_reject(): void {
        check_admin_referer('sfs_hr_settlement_reject');
        Helpers::require_cap('sfs_hr.manage');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';

        $settlement_id = intval($_POST['settlement_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        $wpdb->update($table, [
            'status' => 'rejected',
            'approver_id' => get_current_user_id(),
            'approver_note' => $note,
            'decided_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $settlement_id]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-settlements'),
            'success',
            __('Settlement rejected.', 'sfs-hr')
        );
    }

    public function handle_payment(): void {
        check_admin_referer('sfs_hr_settlement_payment');
        Helpers::require_cap('sfs_hr.manage');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';

        $settlement_id = intval($_POST['settlement_id'] ?? 0);
        $payment_date = sanitize_text_field($_POST['payment_date'] ?? '');
        $payment_reference = sanitize_text_field($_POST['payment_reference'] ?? '');

        // Get settlement details
        $settlement = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $settlement_id
        ), ARRAY_A);

        if (!$settlement) {
            wp_die(__('Settlement not found.', 'sfs-hr'));
        }

        // CRITICAL: Check for outstanding loans before final payment
        if (class_exists('\SFS\HR\Modules\Loans\LoansModule')) {
            $has_loans = \SFS\HR\Modules\Loans\LoansModule::has_active_loans($settlement['employee_id']);
            $outstanding = \SFS\HR\Modules\Loans\LoansModule::get_outstanding_balance($settlement['employee_id']);

            if ($has_loans && $outstanding > 0) {
                wp_die(
                    sprintf(
                        '<h1>%s</h1><p>%s</p><p><strong>%s</strong></p><p><a href="%s" class="button">%s</a> <a href="%s" class="button button-primary">%s</a></p>',
                        esc_html__('Settlement Payment Blocked', 'sfs-hr'),
                        sprintf(
                            esc_html__('Cannot complete final settlement payment. Employee has outstanding loan balance of %s SAR.', 'sfs-hr'),
                            '<strong style="color:red;">' . number_format($outstanding, 2) . '</strong>'
                        ),
                        esc_html__('All loans must be fully settled before releasing final exit settlement payment.', 'sfs-hr'),
                        esc_url(admin_url('admin.php?page=sfs-hr-settlements&action=view&id=' . $settlement_id)),
                        esc_html__('Back to Settlement', 'sfs-hr'),
                        esc_url(admin_url('admin.php?page=sfs-hr-loans&employee_id=' . $settlement['employee_id'])),
                        esc_html__('View Employee Loans', 'sfs-hr')
                    )
                );
            }
        }

        // CRITICAL: Check for unreturned assets before final payment
        if (class_exists('\SFS\HR\Modules\Assets\AssetsModule')) {
            $has_unreturned = \SFS\HR\Modules\Assets\AssetsModule::has_unreturned_assets($settlement['employee_id']);
            $unreturned_count = \SFS\HR\Modules\Assets\AssetsModule::get_unreturned_assets_count($settlement['employee_id']);

            if ($has_unreturned) {
                wp_die(
                    sprintf(
                        '<h1>%s</h1><p>%s</p><p><strong>%s</strong></p><p><a href="%s" class="button">%s</a> <a href="%s" class="button button-primary">%s</a></p>',
                        esc_html__('Settlement Payment Blocked', 'sfs-hr'),
                        sprintf(
                            esc_html__('Cannot complete final settlement payment. Employee has %d unreturned asset(s).', 'sfs-hr'),
                            '<strong style="color:red;">' . $unreturned_count . '</strong>'
                        ),
                        esc_html__('All assets must be returned before releasing final exit settlement payment.', 'sfs-hr'),
                        esc_url(admin_url('admin.php?page=sfs-hr-settlements&action=view&id=' . $settlement_id)),
                        esc_html__('Back to Settlement', 'sfs-hr'),
                        esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&id=' . $settlement['employee_id'] . '&tab=assets')),
                        esc_html__('View Employee Assets', 'sfs-hr')
                    )
                );
            }
        }

        $wpdb->update($table, [
            'status' => 'paid',
            'payment_date' => $payment_date,
            'payment_reference' => $payment_reference,
            'updated_at' => current_time('mysql'),
        ], ['id' => $settlement_id]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-settlements&action=view&id=' . $settlement_id),
            'success',
            __('Payment recorded successfully.', 'sfs-hr')
        );
    }

    /* ---------------------------------- Helper Methods ---------------------------------- */

    private function status_badge(string $status): string {
        $colors = [
            'pending'  => '#f0ad4e',
            'approved' => '#5cb85c',
            'rejected' => '#d9534f',
            'paid'     => '#0073aa',
            'cleared'  => '#5cb85c',
        ];

        $color = $colors[$status] ?? '#777';
        return sprintf(
            '<span style="background:%s;color:#fff;padding:4px 8px;border-radius:3px;font-size:11px;">%s</span>',
            esc_attr($color),
            esc_html(ucfirst($status))
        );
    }
}