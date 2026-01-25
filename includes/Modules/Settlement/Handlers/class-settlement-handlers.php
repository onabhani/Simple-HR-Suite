<?php
namespace SFS\HR\Modules\Settlement\Handlers;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Settlement\Services\Settlement_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Settlement Handlers
 * Form submission handlers for settlement operations
 */
class Settlement_Handlers {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_post_sfs_hr_settlement_create', [$this, 'handle_create']);
        add_action('admin_post_sfs_hr_settlement_update', [$this, 'handle_update']);
        add_action('admin_post_sfs_hr_settlement_approve', [$this, 'handle_approve']);
        add_action('admin_post_sfs_hr_settlement_reject', [$this, 'handle_reject']);
        add_action('admin_post_sfs_hr_settlement_payment', [$this, 'handle_payment']);
    }

    /**
     * Handle settlement creation
     */
    public function handle_create(): void {
        check_admin_referer('sfs_hr_settlement_create');
        Helpers::require_cap('sfs_hr.manage');

        $data = [
            'employee_id'       => intval($_POST['employee_id'] ?? 0),
            'resignation_id'    => intval($_POST['resignation_id'] ?? 0),
            'last_working_day'  => sanitize_text_field($_POST['last_working_day'] ?? ''),
            'years_of_service'  => floatval($_POST['years_of_service'] ?? 0),
            'basic_salary'      => floatval($_POST['basic_salary'] ?? 0),
            'gratuity_amount'   => floatval($_POST['gratuity_amount'] ?? 0),
            'unused_leave_days' => intval($_POST['unused_leave_days'] ?? 0),
            'leave_encashment'  => floatval($_POST['leave_encashment'] ?? 0),
            'final_salary'      => floatval($_POST['final_salary'] ?? 0),
            'other_allowances'  => floatval($_POST['other_allowances'] ?? 0),
            'deductions'        => floatval($_POST['deductions'] ?? 0),
            'deduction_notes'   => sanitize_textarea_field($_POST['deduction_notes'] ?? ''),
            'total_settlement'  => floatval($_POST['total_settlement'] ?? 0),
        ];

        Settlement_Service::create_settlement($data);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements'),
            'success',
            __('Settlement created successfully.', 'sfs-hr')
        );
    }

    /**
     * Handle settlement update
     */
    public function handle_update(): void {
        check_admin_referer('sfs_hr_settlement_update');
        Helpers::require_cap('sfs_hr.manage');

        $settlement_id = intval($_POST['settlement_id'] ?? 0);

        // Only allow updates to pending settlements
        $settlement = Settlement_Service::get_settlement($settlement_id);
        if (!$settlement || $settlement['status'] !== 'pending') {
            wp_die(__('Settlement not found or cannot be updated.', 'sfs-hr'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';

        $wpdb->update($table, [
            'basic_salary'      => floatval($_POST['basic_salary'] ?? 0),
            'gratuity_amount'   => floatval($_POST['gratuity_amount'] ?? 0),
            'unused_leave_days' => intval($_POST['unused_leave_days'] ?? 0),
            'leave_encashment'  => floatval($_POST['leave_encashment'] ?? 0),
            'final_salary'      => floatval($_POST['final_salary'] ?? 0),
            'other_allowances'  => floatval($_POST['other_allowances'] ?? 0),
            'deductions'        => floatval($_POST['deductions'] ?? 0),
            'deduction_notes'   => sanitize_textarea_field($_POST['deduction_notes'] ?? ''),
            'total_settlement'  => floatval($_POST['total_settlement'] ?? 0),
            'updated_at'        => current_time('mysql'),
        ], ['id' => $settlement_id]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&action=view&id=' . $settlement_id),
            'success',
            __('Settlement updated successfully.', 'sfs-hr')
        );
    }

    /**
     * Handle settlement approval
     */
    public function handle_approve(): void {
        check_admin_referer('sfs_hr_settlement_approve');
        Helpers::require_cap('sfs_hr.manage');

        $settlement_id = intval($_POST['settlement_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        $settlement = Settlement_Service::get_settlement($settlement_id);
        if (!$settlement) {
            wp_die(__('Settlement not found.', 'sfs-hr'));
        }

        // Check for outstanding loans
        $loan_status = Settlement_Service::check_loan_clearance($settlement['employee_id']);
        if (!$loan_status['cleared']) {
            wp_die(
                sprintf(
                    '<h1>%s</h1><p>%s</p><p><a href="%s" class="button">%s</a> <a href="%s" class="button button-primary">%s</a></p>',
                    esc_html__('Settlement Approval Blocked', 'sfs-hr'),
                    sprintf(
                        esc_html__('Cannot approve settlement. Employee has outstanding loan balance of %s SAR. Please settle all loans with Finance department first.', 'sfs-hr'),
                        '<strong>' . number_format($loan_status['outstanding'], 2) . '</strong>'
                    ),
                    esc_url(admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&action=view&id=' . $settlement_id)),
                    esc_html__('Back to Settlement', 'sfs-hr'),
                    esc_url(admin_url('admin.php?page=sfs-hr-loans&employee_id=' . $settlement['employee_id'])),
                    esc_html__('View Employee Loans', 'sfs-hr')
                )
            );
        }

        // Check for unreturned assets
        $asset_status = Settlement_Service::check_asset_clearance($settlement['employee_id']);
        if (!$asset_status['cleared']) {
            wp_die(
                sprintf(
                    '<h1>%s</h1><p>%s</p><p><a href="%s" class="button">%s</a> <a href="%s" class="button button-primary">%s</a></p>',
                    esc_html__('Settlement Approval Blocked', 'sfs-hr'),
                    sprintf(
                        esc_html__('Cannot approve settlement. Employee has %d unreturned asset(s). All assets must be returned before settlement approval.', 'sfs-hr'),
                        '<strong>' . $asset_status['unreturned_count'] . '</strong>'
                    ),
                    esc_url(admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&action=view&id=' . $settlement_id)),
                    esc_html__('Back to Settlement', 'sfs-hr'),
                    esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&id=' . $settlement['employee_id'] . '&tab=assets')),
                    esc_html__('View Employee Assets', 'sfs-hr')
                )
            );
        }

        Settlement_Service::update_status($settlement_id, 'approved', [
            'approver_id'   => get_current_user_id(),
            'approver_note' => $note,
            'decided_at'    => current_time('mysql'),
        ]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&action=view&id=' . $settlement_id),
            'success',
            __('Settlement approved successfully.', 'sfs-hr')
        );
    }

    /**
     * Handle settlement rejection
     */
    public function handle_reject(): void {
        check_admin_referer('sfs_hr_settlement_reject');
        Helpers::require_cap('sfs_hr.manage');

        $settlement_id = intval($_POST['settlement_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        Settlement_Service::update_status($settlement_id, 'rejected', [
            'approver_id'   => get_current_user_id(),
            'approver_note' => $note,
            'decided_at'    => current_time('mysql'),
        ]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements'),
            'success',
            __('Settlement rejected.', 'sfs-hr')
        );
    }

    /**
     * Handle payment recording
     */
    public function handle_payment(): void {
        check_admin_referer('sfs_hr_settlement_payment');
        Helpers::require_cap('sfs_hr.manage');

        $settlement_id = intval($_POST['settlement_id'] ?? 0);
        $payment_date = sanitize_text_field($_POST['payment_date'] ?? '');
        $payment_reference = sanitize_text_field($_POST['payment_reference'] ?? '');

        $settlement = Settlement_Service::get_settlement($settlement_id);
        if (!$settlement) {
            wp_die(__('Settlement not found.', 'sfs-hr'));
        }

        // CRITICAL: Check for outstanding loans before final payment
        $loan_status = Settlement_Service::check_loan_clearance($settlement['employee_id']);
        if (!$loan_status['cleared']) {
            wp_die(
                sprintf(
                    '<h1>%s</h1><p>%s</p><p><strong>%s</strong></p><p><a href="%s" class="button">%s</a> <a href="%s" class="button button-primary">%s</a></p>',
                    esc_html__('Settlement Payment Blocked', 'sfs-hr'),
                    sprintf(
                        esc_html__('Cannot complete final settlement payment. Employee has outstanding loan balance of %s SAR.', 'sfs-hr'),
                        '<strong style="color:red;">' . number_format($loan_status['outstanding'], 2) . '</strong>'
                    ),
                    esc_html__('All loans must be fully settled before releasing final exit settlement payment.', 'sfs-hr'),
                    esc_url(admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&action=view&id=' . $settlement_id)),
                    esc_html__('Back to Settlement', 'sfs-hr'),
                    esc_url(admin_url('admin.php?page=sfs-hr-loans&employee_id=' . $settlement['employee_id'])),
                    esc_html__('View Employee Loans', 'sfs-hr')
                )
            );
        }

        // CRITICAL: Check for unreturned assets before final payment
        $asset_status = Settlement_Service::check_asset_clearance($settlement['employee_id']);
        if (!$asset_status['cleared']) {
            wp_die(
                sprintf(
                    '<h1>%s</h1><p>%s</p><p><strong>%s</strong></p><p><a href="%s" class="button">%s</a> <a href="%s" class="button button-primary">%s</a></p>',
                    esc_html__('Settlement Payment Blocked', 'sfs-hr'),
                    sprintf(
                        esc_html__('Cannot complete final settlement payment. Employee has %d unreturned asset(s).', 'sfs-hr'),
                        '<strong style="color:red;">' . $asset_status['unreturned_count'] . '</strong>'
                    ),
                    esc_html__('All assets must be returned before releasing final exit settlement payment.', 'sfs-hr'),
                    esc_url(admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&action=view&id=' . $settlement_id)),
                    esc_html__('Back to Settlement', 'sfs-hr'),
                    esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&id=' . $settlement['employee_id'] . '&tab=assets')),
                    esc_html__('View Employee Assets', 'sfs-hr')
                )
            );
        }

        Settlement_Service::update_status($settlement_id, 'paid', [
            'payment_date'      => $payment_date,
            'payment_reference' => $payment_reference,
        ]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-lifecycle&tab=settlements&action=view&id=' . $settlement_id),
            'success',
            __('Payment recorded successfully.', 'sfs-hr')
        );
    }
}
