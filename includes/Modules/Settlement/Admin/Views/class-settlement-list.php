<?php
namespace SFS\HR\Modules\Settlement\Admin\Views;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Settlement\Services\Settlement_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Settlement List View
 * Renders the settlements list with filtering and pagination
 */
class Settlement_List {

    /**
     * Render the list view
     */
    public static function render(): void {
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'pending';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

        $result = Settlement_Service::get_settlements($status, $page, 20);

        self::render_styles();
        self::render_modal();
        self::render_modal_script();
        ?>

        <div class="wrap sfs-hr-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('End of Service Settlements', 'sfs-hr'); ?></h1>
            <?php Helpers::render_admin_nav(); ?>

            <div class="sfs-hr-settlement-toolbar">
                <ul class="sfs-hr-settlement-tabs">
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&status=pending')); ?>"
                        class="<?php echo $status === 'pending' ? 'current' : ''; ?>">
                        <?php esc_html_e('Pending', 'sfs-hr'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&status=approved')); ?>"
                        class="<?php echo $status === 'approved' ? 'current' : ''; ?>">
                        <?php esc_html_e('Approved', 'sfs-hr'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&status=paid')); ?>"
                        class="<?php echo $status === 'paid' ? 'current' : ''; ?>">
                        <?php esc_html_e('Paid', 'sfs-hr'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&status=rejected')); ?>"
                        class="<?php echo $status === 'rejected' ? 'current' : ''; ?>">
                        <?php esc_html_e('Rejected', 'sfs-hr'); ?></a></li>
                </ul>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&action=create')); ?>" class="button button-primary">
                    <?php esc_html_e('Create Settlement', 'sfs-hr'); ?>
                </a>
            </div>

            <h2><?php echo esc_html(ucfirst($status)); ?> <?php esc_html_e('Settlements', 'sfs-hr'); ?> <span style="font-weight:normal; font-size:14px; color:#50575e;">(<?php echo (int)$result['total']; ?>)</span></h2>

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
                        <?php if (empty($result['rows'])): ?>
                            <tr>
                                <td colspan="7"><?php esc_html_e('No settlements found.', 'sfs-hr'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($result['rows'] as $row):
                                $emp_name = $row['first_name'] . ' ' . $row['last_name'];
                                $view_url = admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&action=view&id=' . $row['id']);
                                $edit_url = admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&action=edit&id=' . $row['id']);
                                $can_edit = $row['status'] === 'pending' ? '1' : '0';
                            ?>
                                <tr>
                                    <td class="hide-mobile"><?php echo esc_html($row['id']); ?></td>
                                    <td>
                                        <?php $profile_url = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . (int) $row['employee_id']); ?>
                                        <a href="<?php echo esc_url($profile_url); ?>" class="emp-name"><?php echo esc_html($emp_name); ?></a>
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

            <?php if ($result['total_pages'] > 1): ?>
            <div class="sfs-hr-settlement-pagination">
                <?php for($i=1; $i<=$result['total_pages']; $i++): ?>
                    <?php if ($i === $result['page']): ?>
                        <span class="current-page"><?php echo (int)$i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo esc_url(add_query_arg(['paged' => $i, 'status' => $status], admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements'))); ?>"><?php echo (int)$i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render CSS styles
     */
    private static function render_styles(): void {
        ?>
        <style>
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
          .sfs-hr-settlement-tabs li { margin: 0; }
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
          .sfs-hr-settlement-tabs a:hover { background: #dcdcde; }
          .sfs-hr-settlement-tabs a.current { background: #2271b1; color: #fff; }
          .sfs-hr-settlement-table {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            margin-top: 16px;
          }
          .sfs-hr-settlement-table .widefat { border: none; border-radius: 6px; margin: 0; }
          .sfs-hr-settlement-table .widefat th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #50575e;
            padding: 12px 16px;
          }
          .sfs-hr-settlement-table .widefat td { padding: 12px 16px; vertical-align: middle; }
          .sfs-hr-settlement-table .widefat tbody tr:hover { background: #f8f9fa; }
          .sfs-hr-settlement-table .emp-name { font-weight: 500; color: #2271b1; text-decoration: none; }
          .sfs-hr-settlement-table a.emp-name:hover { color: #135e96; text-decoration: underline; }
          .sfs-hr-settlement-table .emp-code { font-family: monospace; font-size: 11px; color: #50575e; display: block; margin-top: 2px; }
          .sfs-hr-settlement-table .amount { font-weight: 600; color: #1d2327; }
          .sfs-hr-settlement-actions { display: flex; gap: 6px; }
          .sfs-hr-settlement-actions .button { font-size: 12px; padding: 4px 10px; height: auto; line-height: 1.4; border-radius: 4px; }
          .sfs-hr-settlement-mobile-btn {
            display: none;
            width: 36px; height: 36px;
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
          .sfs-hr-settlement-mobile-btn:hover { background: #135e96; }
          .sfs-hr-status-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
          .sfs-hr-status-badge.status-pending { background: #fef3c7; color: #92400e; }
          .sfs-hr-status-badge.status-approved { background: #d1fae5; color: #065f46; }
          .sfs-hr-status-badge.status-paid { background: #dbeafe; color: #1e40af; }
          .sfs-hr-status-badge.status-rejected { background: #fee2e2; color: #991b1b; }
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
            min-width: 32px; height: 32px;
            padding: 0 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
          }
          .sfs-hr-settlement-pagination a { background: #f0f0f1; color: #50575e; }
          .sfs-hr-settlement-pagination a:hover { background: #dcdcde; }
          .sfs-hr-settlement-pagination .current-page { background: #2271b1; color: #fff; font-weight: 600; }

          @media (max-width: 782px) {
            .sfs-hr-settlement-toolbar { flex-direction: column; align-items: stretch; padding: 12px; }
            .sfs-hr-settlement-tabs { width: 100%; justify-content: center; }
            .sfs-hr-settlement-tabs a { flex: 1; text-align: center; padding: 10px 8px; font-size: 12px; }
            .sfs-hr-settlement-toolbar .page-title-action { width: 100%; text-align: center; margin: 0; }
            .sfs-hr-settlement-table .widefat thead th.hide-mobile,
            .sfs-hr-settlement-table .widefat tbody td.hide-mobile { display: none !important; }
            .sfs-hr-settlement-table .widefat th,
            .sfs-hr-settlement-table .widefat td { padding: 10px 12px; }
            .sfs-hr-settlement-actions { display: none !important; }
            .sfs-hr-settlement-mobile-btn { display: inline-flex; }
            .sfs-hr-settlement-pagination { justify-content: center; }
          }
        </style>
        <?php
    }

    /**
     * Render mobile modal HTML
     */
    private static function render_modal(): void {
        ?>
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
        <style>
          .sfs-hr-settlement-modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 100000;
            background: rgba(0,0,0,0.5);
            align-items: flex-end;
            justify-content: center;
          }
          .sfs-hr-settlement-modal.active { display: flex; }
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
          .sfs-hr-settlement-modal-title { font-size: 18px; font-weight: 600; color: #1d2327; margin: 0; }
          .sfs-hr-settlement-modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #50575e; padding: 0; line-height: 1; }
          .sfs-hr-settlement-details-list { list-style: none; margin: 0 0 16px; padding: 0; }
          .sfs-hr-settlement-details-list li { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f1; }
          .sfs-hr-settlement-details-list li:last-child { border-bottom: none; }
          .sfs-hr-settlement-label { font-weight: 500; color: #50575e; font-size: 13px; }
          .sfs-hr-settlement-value { color: #1d2327; font-size: 13px; text-align: right; }
          .sfs-hr-settlement-modal-buttons { display: flex; flex-direction: column; gap: 10px; }
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
        </style>
        <?php
    }

    /**
     * Render modal JavaScript
     */
    private static function render_modal_script(): void {
        ?>
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
        <?php
    }
}
