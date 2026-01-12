<?php
namespace SFS\HR\Modules\Settlement\Admin\Views;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Settlement\Services\Settlement_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Settlement View
 * Renders the settlement detail view with approval actions
 */
class Settlement_View {

    /**
     * Render the detail view
     */
    public static function render(int $settlement_id): void {
        $settlement = Settlement_Service::get_settlement($settlement_id);

        if (!$settlement) {
            wp_die(__('Settlement not found.', 'sfs-hr'));
        }

        $loan_status = Settlement_Service::check_loan_clearance($settlement['employee_id']);
        $asset_status = Settlement_Service::check_asset_clearance($settlement['employee_id']);

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e('Settlement Details', 'sfs-hr'); ?> #<?php echo esc_html($settlement['id']); ?></h1>
            <?php Helpers::render_admin_nav(); ?>

            <div style="background:#fff;padding:20px;border:1px solid #ddd;margin-top:20px;">
                <?php self::render_employee_info($settlement); ?>
                <?php self::render_breakdown($settlement); ?>
                <?php self::render_clearance_status($settlement); ?>
                <?php self::render_loan_status($settlement, $loan_status); ?>
                <?php self::render_asset_status($settlement, $asset_status); ?>
                <?php self::render_approval_status($settlement); ?>
                <?php self::render_action_buttons($settlement); ?>
            </div>

            <p style="margin-top:20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements')); ?>" class="button">&larr; <?php esc_html_e('Back to Settlements', 'sfs-hr'); ?></a>
            </p>

            <?php self::render_modals($settlement); ?>
        </div>
        <?php
    }

    /**
     * Render employee information section
     */
    private static function render_employee_info(array $settlement): void {
        ?>
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
        <?php
    }

    /**
     * Render settlement breakdown
     */
    private static function render_breakdown(array $settlement): void {
        ?>
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
        <?php
    }

    /**
     * Render clearance status section
     */
    private static function render_clearance_status(array $settlement): void {
        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e('Clearance Status', 'sfs-hr'); ?></h2>
        <table class="widefat">
            <tr>
                <th style="width:200px;"><?php esc_html_e('Asset Clearance:', 'sfs-hr'); ?></th>
                <td><?php echo Settlement_Service::status_badge($settlement['asset_clearance_status'] ?? 'pending'); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Document Clearance:', 'sfs-hr'); ?></th>
                <td><?php echo Settlement_Service::status_badge($settlement['document_clearance_status'] ?? 'pending'); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Finance Clearance:', 'sfs-hr'); ?></th>
                <td><?php echo Settlement_Service::status_badge($settlement['finance_clearance_status'] ?? 'pending'); ?></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render loan status section
     */
    private static function render_loan_status(array $settlement, array $loan_status): void {
        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e('Loan Status', 'sfs-hr'); ?></h2>
        <?php if (!$loan_status['cleared']): ?>
        <div class="notice notice-error inline" style="margin:0 0 20px 0;">
            <p><strong><?php esc_html_e('⚠️ Outstanding Loan Alert:', 'sfs-hr'); ?></strong></p>
            <p><?php echo sprintf(
                esc_html__('This employee has an outstanding loan balance of %s SAR. Settlement cannot be finalized until all loans are settled with Finance.', 'sfs-hr'),
                '<strong>' . number_format($loan_status['outstanding'], 2) . '</strong>'
            ); ?></p>
            <?php if ($loan_status['summary']): ?>
            <p>
                <strong><?php esc_html_e('Loan Details:', 'sfs-hr'); ?></strong><br>
                <?php esc_html_e('Active Loans:', 'sfs-hr'); ?> <?php echo esc_html($loan_status['summary']['loan_count']); ?><br>
                <?php if (!empty($loan_status['summary']['next_due_date'])): ?>
                    <?php esc_html_e('Next Payment Due:', 'sfs-hr'); ?> <?php echo esc_html($loan_status['summary']['next_due_date']); ?><br>
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
        <?php endif;
    }

    /**
     * Render asset status section
     */
    private static function render_asset_status(array $settlement, array $asset_status): void {
        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e('Asset Return Status', 'sfs-hr'); ?></h2>
        <?php if (!$asset_status['cleared']): ?>
        <div class="notice notice-error inline" style="margin:0 0 20px 0;">
            <p><strong><?php esc_html_e('⚠️ Unreturned Assets Alert:', 'sfs-hr'); ?></strong></p>
            <p><?php echo sprintf(
                esc_html__('This employee has %d unreturned asset(s). Settlement cannot be finalized until all assets are returned.', 'sfs-hr'),
                '<strong>' . $asset_status['unreturned_count'] . '</strong>'
            ); ?></p>
            <?php if (!empty($asset_status['assets'])): ?>
            <p><strong><?php esc_html_e('Unreturned Assets:', 'sfs-hr'); ?></strong></p>
            <ul style="margin-left:20px;">
                <?php foreach ($asset_status['assets'] as $asset): ?>
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
        <?php endif;
    }

    /**
     * Render approval and payment status
     */
    private static function render_approval_status(array $settlement): void {
        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e('Approval & Payment', 'sfs-hr'); ?></h2>
        <table class="widefat">
            <tr>
                <th style="width:200px;"><?php esc_html_e('Status:', 'sfs-hr'); ?></th>
                <td><?php echo Settlement_Service::status_badge($settlement['status']); ?></td>
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
        <?php
    }

    /**
     * Render action buttons based on status
     */
    private static function render_action_buttons(array $settlement): void {
        if ($settlement['status'] === 'pending'): ?>
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
        <?php endif;
    }

    /**
     * Render approval/rejection/payment modals
     */
    private static function render_modals(array $settlement): void {
        ?>
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
        <?php
    }
}
