<?php
namespace SFS\HR\Modules\Resignation;

use SFS\HR\Core\Helpers;

if (!defined('ABSPATH')) { exit; }

class ResignationModule {

    public function hooks(): void {
        add_action('admin_menu', [$this, 'menu']);

        // Form handlers
        add_action('admin_post_sfs_hr_resignation_submit',  [$this, 'handle_submit']);
        add_action('admin_post_sfs_hr_resignation_approve', [$this, 'handle_approve']);
        add_action('admin_post_sfs_hr_resignation_reject',  [$this, 'handle_reject']);

        // Shortcodes for employee self-service
        add_shortcode('sfs_hr_resignation_submit', [$this, 'shortcode_submit_form']);
        add_shortcode('sfs_hr_my_resignations', [$this, 'shortcode_my_resignations']);
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
        global $wpdb;

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'pending';
        $page   = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $pp     = 20;
        $offset = ($page - 1) * $pp;

        $table = $wpdb->prefix . 'sfs_hr_resignations';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $where  = '1=1';
        $params = [];

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $where   .= " AND r.status = %s";
            $params[] = $status;
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

        Helpers::render_admin_nav();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Resignations', 'sfs-hr'); ?></h1>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-resignations&status=pending')); ?>"
                    class="<?php echo $status === 'pending' ? 'current' : ''; ?>">
                    <?php esc_html_e('Pending', 'sfs-hr'); ?></a> | </li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-resignations&status=approved')); ?>"
                    class="<?php echo $status === 'approved' ? 'current' : ''; ?>">
                    <?php esc_html_e('Approved', 'sfs-hr'); ?></a> | </li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-resignations&status=rejected')); ?>"
                    class="<?php echo $status === 'rejected' ? 'current' : ''; ?>">
                    <?php esc_html_e('Rejected', 'sfs-hr'); ?></a></li>
            </ul>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Resignation Date', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Last Working Day', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Notice Period', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Reason', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e('No resignations found.', 'sfs-hr'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['id']); ?></td>
                                <td>
                                    <?php echo esc_html($row['first_name'] . ' ' . $row['last_name']); ?><br>
                                    <small><?php echo esc_html($row['employee_code']); ?></small>
                                    <?php
                                    // Display loan badge if loans exist
                                    if (class_exists('\SFS\HR\Modules\Loans\Admin\DashboardWidget')) {
                                        echo \SFS\HR\Modules\Loans\Admin\DashboardWidget::render_employee_loan_badge($row['employee_id']);
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($row['resignation_date']); ?></td>
                                <td><?php echo esc_html($row['last_working_day'] ?: 'N/A'); ?></td>
                                <td><?php echo esc_html($row['notice_period_days']) . ' ' . esc_html__('days', 'sfs-hr'); ?></td>
                                <td><?php echo $this->status_badge($row['status']); ?></td>
                                <td><?php echo esc_html(wp_trim_words($row['reason'], 10)); ?></td>
                                <td>
                                    <?php if ($row['status'] === 'pending' && $this->can_approve_resignation($row)): ?>
                                        <a href="#" onclick="return showApproveModal(<?php echo esc_attr($row['id']); ?>);" class="button button-small">
                                            <?php esc_html_e('Approve', 'sfs-hr'); ?>
                                        </a>
                                        <a href="#" onclick="return showRejectModal(<?php echo esc_attr($row['id']); ?>);" class="button button-small">
                                            <?php esc_html_e('Reject', 'sfs-hr'); ?>
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
        </div>

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
        function showDetailsModal(id) {
            // TODO: Implement details modal
            alert('Details for resignation #' + id);
            return false;
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
        $notice_period = intval($_POST['notice_period_days'] ?? 30);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');

        if (empty($resignation_date)) {
            wp_die(__('Resignation date is required.', 'sfs-hr'));
        }

        // Calculate last working day
        $last_working_day = date('Y-m-d', strtotime($resignation_date . " +{$notice_period} days"));

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
        $wpdb->insert($table, [
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
        ]);

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

        // Append to approval chain
        $chain = json_decode($resignation['approval_chain'] ?? '[]', true) ?: [];
        $chain[] = [
            'by'     => get_current_user_id(),
            'role'   => $current_level === 1 ? 'manager' : 'hr',
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

        // Check if this is final approval (HR level)
        if ($new_level > 2 || current_user_can('sfs_hr.manage')) {
            $update_data['status'] = 'approved';
            $update_data['decided_at'] = current_time('mysql');

            // Update employee status to terminated
            $emp_table = $wpdb->prefix . 'sfs_hr_employees';
            $wpdb->update($emp_table, [
                'status' => 'terminated',
                'updated_at' => current_time('mysql'),
            ], ['id' => $resignation['employee_id']]);
        } else {
            $update_data['approval_level'] = $new_level;
        }

        $wpdb->update($table, $update_data, ['id' => $resignation_id]);

        // Send notification
        $this->send_approval_notification($resignation_id);

        // Prepare success message
        $success_message = __('Resignation approved successfully.', 'sfs-hr');

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

    /* ---------------------------------- Helper Methods ---------------------------------- */

    private function can_approve_resignation(array $resignation): bool {
        if (current_user_can('sfs_hr.manage')) {
            return true;
        }

        $managed_depts = $this->manager_dept_ids_for_user(get_current_user_id());
        return in_array($resignation['dept_id'], $managed_depts, true);
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

    private function status_badge(string $status): string {
        $colors = [
            'pending'  => '#f0ad4e',
            'approved' => '#5cb85c',
            'rejected' => '#d9534f',
        ];

        $color = $colors[$status] ?? '#777';
        return sprintf(
            '<span style="background:%s;color:#fff;padding:4px 8px;border-radius:3px;font-size:11px;">%s</span>',
            esc_attr($color),
            esc_html(ucfirst($status))
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
                    <label for="notice_period_days"><?php esc_html_e('Notice Period (days):', 'sfs-hr'); ?> <span style="color:red;">*</span></label><br>
                    <input type="number" name="notice_period_days" id="notice_period_days" value="30" min="0" required style="width:100%;max-width:300px;">
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
                                <td style="border:1px solid #ddd;padding:8px;"><?php echo $this->status_badge($r['status']); ?></td>
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
}
