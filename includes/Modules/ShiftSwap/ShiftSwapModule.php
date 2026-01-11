<?php
namespace SFS\HR\Modules\ShiftSwap;

if (!defined('ABSPATH')) { exit; }

/**
 * Shift Swap Module
 * Allows employees to request shift swaps with colleagues
 * Version: 0.1.0
 * Author: Omar Alnabhani (hdqah.com)
 */
class ShiftSwapModule {

    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function hooks(): void {
        // Install tables
        add_action('admin_init', [$this, 'maybe_install_tables']);

        // Add Shift Swap tab to My Profile
        add_action('sfs_hr_employee_tabs', [$this, 'add_shift_swap_tab'], 25);
        add_action('sfs_hr_employee_tab_content', [$this, 'render_shift_swap_tab'], 10, 2);

        // Admin-post handlers
        add_action('admin_post_sfs_hr_request_shift_swap', [$this, 'handle_swap_request']);
        add_action('admin_post_sfs_hr_respond_shift_swap', [$this, 'handle_swap_response']);
        add_action('admin_post_sfs_hr_cancel_shift_swap', [$this, 'handle_swap_cancel']);
        add_action('admin_post_sfs_hr_approve_shift_swap', [$this, 'handle_manager_approval']);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Send notifications
        add_action('sfs_hr_shift_swap_requested', [$this, 'notify_swap_requested'], 10, 2);
        add_action('sfs_hr_shift_swap_responded', [$this, 'notify_swap_responded'], 10, 2);
    }

    /**
     * Install shift_swaps table
     */
    public function maybe_install_tables(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $charset_collate = $wpdb->get_charset_collate();

        $table_exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        ));

        if (!$table_exists) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                requester_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Employee requesting the swap',
                requester_shift_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Shift assignment of requester',
                requester_date DATE NOT NULL,
                target_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Employee being asked to swap',
                target_shift_id BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Shift assignment of target (optional)',
                target_date DATE NOT NULL,
                reason TEXT,
                status ENUM('pending','accepted','declined','cancelled','manager_pending','approved','rejected') DEFAULT 'pending',
                target_responded_at DATETIME DEFAULT NULL,
                manager_id BIGINT(20) UNSIGNED DEFAULT NULL,
                manager_responded_at DATETIME DEFAULT NULL,
                manager_note TEXT,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY requester_id (requester_id),
                KEY target_id (target_id),
                KEY status (status),
                KEY requester_date (requester_date),
                KEY target_date (target_date)
            ) {$charset_collate}");
        }
    }

    /**
     * Add Shift Swap tab to My Profile
     */
    public function add_shift_swap_tab($employee): void {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        $tab_class = 'nav-tab' . ($active_tab === 'shift_swap' ? ' nav-tab-active' : '');

        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ($page === 'sfs-hr-my-profile') {
            $url = admin_url('admin.php?page=sfs-hr-my-profile&tab=shift_swap');
        } else {
            return; // Only show on My Profile
        }

        echo '<a href="' . esc_url($url) . '" class="' . esc_attr($tab_class) . '">';
        esc_html_e('Shift Swaps', 'sfs-hr');
        echo '</a>';
    }

    /**
     * Render Shift Swap tab content
     */
    public function render_shift_swap_tab($employee, string $active_tab): void {
        if ($active_tab !== 'shift_swap') {
            return;
        }

        global $wpdb;
        $employee_id = (int)$employee->id;
        $current_user_id = get_current_user_id();

        // Verify this is the employee's own profile
        if ((int)$employee->user_id !== $current_user_id) {
            wp_die(esc_html__('You can only access your own shift swaps.', 'sfs-hr'));
        }

        $swaps_table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $shifts_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assigns';

        // Get my upcoming shifts
        $today = wp_date('Y-m-d');
        $my_shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT sa.*, s.name AS shift_name, s.start_time, s.end_time
             FROM {$shifts_table} sa
             LEFT JOIN {$wpdb->prefix}sfs_hr_attendance_shifts s ON s.id = sa.shift_id
             WHERE sa.employee_id = %d AND sa.assign_date >= %s
             ORDER BY sa.assign_date ASC
             LIMIT 30",
            $employee_id,
            $today
        ));

        // Get colleagues (same department) for swap targets
        $colleagues = $wpdb->get_results($wpdb->prepare(
            "SELECT id, first_name, last_name, employee_code
             FROM {$emp_table}
             WHERE dept_id = %d AND id != %d AND status = 'active'
             ORDER BY first_name, last_name",
            (int)$employee->dept_id,
            $employee_id
        ));

        // Get pending requests where I'm the target
        $incoming_requests = $wpdb->get_results($wpdb->prepare(
            "SELECT sw.*,
                    CONCAT(req.first_name, ' ', req.last_name) AS requester_name,
                    req.employee_code AS requester_code
             FROM {$swaps_table} sw
             JOIN {$emp_table} req ON req.id = sw.requester_id
             WHERE sw.target_id = %d AND sw.status = 'pending'
             ORDER BY sw.created_at DESC",
            $employee_id
        ));

        // Get my outgoing requests
        $outgoing_requests = $wpdb->get_results($wpdb->prepare(
            "SELECT sw.*,
                    CONCAT(tgt.first_name, ' ', tgt.last_name) AS target_name,
                    tgt.employee_code AS target_code
             FROM {$swaps_table} sw
             JOIN {$emp_table} tgt ON tgt.id = sw.target_id
             WHERE sw.requester_id = %d
             ORDER BY sw.created_at DESC
             LIMIT 20",
            $employee_id
        ));

        // Show success/error messages
        if (isset($_GET['success'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['success']))) . '</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['error']))) . '</p></div>';
        }

        $status_labels = [
            'pending' => __('Pending Response', 'sfs-hr'),
            'accepted' => __('Accepted by Colleague', 'sfs-hr'),
            'declined' => __('Declined', 'sfs-hr'),
            'cancelled' => __('Cancelled', 'sfs-hr'),
            'manager_pending' => __('Awaiting Manager Approval', 'sfs-hr'),
            'approved' => __('Approved', 'sfs-hr'),
            'rejected' => __('Rejected by Manager', 'sfs-hr'),
        ];

        $status_colors = [
            'pending' => '#f59e0b',
            'accepted' => '#3b82f6',
            'declined' => '#ef4444',
            'cancelled' => '#6b7280',
            'manager_pending' => '#8b5cf6',
            'approved' => '#10b981',
            'rejected' => '#ef4444',
        ];

        ?>
        <style>
            .sfs-hr-shift-swap-wrap { max-width: 900px; }
            .sfs-hr-swap-card {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 16px;
                margin-bottom: 20px;
            }
            .sfs-hr-swap-card h3 {
                margin: 0 0 16px;
                font-size: 15px;
                border-bottom: 1px solid #e5e7eb;
                padding-bottom: 10px;
            }
            .sfs-hr-swap-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 12px;
                background: #f9fafb;
                border-radius: 6px;
                margin-bottom: 10px;
            }
            .sfs-hr-swap-item:last-child { margin-bottom: 0; }
            .sfs-hr-swap-info { flex: 1; }
            .sfs-hr-swap-info strong { display: block; margin-bottom: 4px; }
            .sfs-hr-swap-info .meta { font-size: 12px; color: #666; }
            .sfs-hr-swap-actions { display: flex; gap: 8px; }
            .sfs-hr-swap-status {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
            }
            @media screen and (max-width: 782px) {
                .sfs-hr-swap-item {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 12px;
                }
                .sfs-hr-swap-actions { width: 100%; justify-content: flex-end; }
            }
        </style>

        <div class="sfs-hr-shift-swap-wrap">

            <?php if (!empty($incoming_requests)): ?>
            <div class="sfs-hr-swap-card">
                <h3><?php esc_html_e('Incoming Swap Requests', 'sfs-hr'); ?></h3>

                <?php foreach ($incoming_requests as $req): ?>
                <div class="sfs-hr-swap-item" style="background:#fef3c7; border:1px solid #fcd34d;">
                    <div class="sfs-hr-swap-info">
                        <strong><?php echo esc_html($req->requester_name); ?></strong>
                        <div class="meta">
                            <?php
                            printf(
                                esc_html__('Wants to swap their shift on %s with your shift on %s', 'sfs-hr'),
                                '<strong>' . esc_html(date_i18n(get_option('date_format'), strtotime($req->requester_date))) . '</strong>',
                                '<strong>' . esc_html(date_i18n(get_option('date_format'), strtotime($req->target_date))) . '</strong>'
                            );
                            ?>
                            <?php if ($req->reason): ?>
                                <br><em><?php echo esc_html($req->reason); ?></em>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="sfs-hr-swap-actions">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="sfs_hr_respond_shift_swap" />
                            <input type="hidden" name="swap_id" value="<?php echo (int)$req->id; ?>" />
                            <input type="hidden" name="response" value="accept" />
                            <?php wp_nonce_field('sfs_hr_respond_swap_' . $req->id); ?>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Accept', 'sfs-hr'); ?></button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                            <input type="hidden" name="action" value="sfs_hr_respond_shift_swap" />
                            <input type="hidden" name="swap_id" value="<?php echo (int)$req->id; ?>" />
                            <input type="hidden" name="response" value="decline" />
                            <?php wp_nonce_field('sfs_hr_respond_swap_' . $req->id); ?>
                            <button type="submit" class="button"><?php esc_html_e('Decline', 'sfs-hr'); ?></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($my_shifts) && !empty($colleagues)): ?>
            <div class="sfs-hr-swap-card">
                <h3><?php esc_html_e('Request a Shift Swap', 'sfs-hr'); ?></h3>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="sfs_hr_request_shift_swap" />
                    <?php wp_nonce_field('sfs_hr_request_swap_' . $employee_id); ?>

                    <table class="form-table">
                        <tr>
                            <th><label><?php esc_html_e('My Shift to Swap', 'sfs-hr'); ?></label></th>
                            <td>
                                <select name="my_shift" required style="min-width:250px;">
                                    <option value=""><?php esc_html_e('— Select your shift —', 'sfs-hr'); ?></option>
                                    <?php foreach ($my_shifts as $shift): ?>
                                        <option value="<?php echo (int)$shift->id; ?>_<?php echo esc_attr($shift->assign_date); ?>">
                                            <?php
                                            echo esc_html(date_i18n(get_option('date_format'), strtotime($shift->assign_date)));
                                            if ($shift->shift_name) {
                                                echo ' - ' . esc_html($shift->shift_name);
                                                if ($shift->start_time) {
                                                    echo ' (' . esc_html(date_i18n('H:i', strtotime($shift->start_time))) . ')';
                                                }
                                            }
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e('Swap With', 'sfs-hr'); ?></label></th>
                            <td>
                                <select name="colleague_id" required style="min-width:250px;">
                                    <option value=""><?php esc_html_e('— Select colleague —', 'sfs-hr'); ?></option>
                                    <?php foreach ($colleagues as $col): ?>
                                        <option value="<?php echo (int)$col->id; ?>">
                                            <?php echo esc_html($col->first_name . ' ' . $col->last_name . ' (' . $col->employee_code . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e('Their Shift Date', 'sfs-hr'); ?></label></th>
                            <td>
                                <input type="date" name="target_date" required min="<?php echo esc_attr($today); ?>" style="width:180px;" />
                                <p class="description"><?php esc_html_e('The date of the shift you want from them', 'sfs-hr'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e('Reason', 'sfs-hr'); ?></label></th>
                            <td>
                                <textarea name="reason" rows="2" class="large-text" placeholder="<?php esc_attr_e('Optional: explain why you need this swap...', 'sfs-hr'); ?>"></textarea>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Send Swap Request', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
            <?php elseif (empty($my_shifts)): ?>
            <div class="sfs-hr-swap-card">
                <h3><?php esc_html_e('Request a Shift Swap', 'sfs-hr'); ?></h3>
                <p class="description"><?php esc_html_e('You have no upcoming shifts to swap.', 'sfs-hr'); ?></p>
            </div>
            <?php endif; ?>

            <div class="sfs-hr-swap-card">
                <h3><?php esc_html_e('My Swap Requests', 'sfs-hr'); ?></h3>

                <?php if (empty($outgoing_requests)): ?>
                    <p class="description"><?php esc_html_e('No swap requests yet.', 'sfs-hr'); ?></p>
                <?php else: ?>
                    <?php foreach ($outgoing_requests as $req): ?>
                    <div class="sfs-hr-swap-item">
                        <div class="sfs-hr-swap-info">
                            <strong>
                                <?php
                                printf(
                                    esc_html__('Swap with %s', 'sfs-hr'),
                                    esc_html($req->target_name)
                                );
                                ?>
                            </strong>
                            <div class="meta">
                                <?php
                                printf(
                                    esc_html__('My shift: %s | Their shift: %s', 'sfs-hr'),
                                    esc_html(date_i18n(get_option('date_format'), strtotime($req->requester_date))),
                                    esc_html(date_i18n(get_option('date_format'), strtotime($req->target_date)))
                                );
                                ?>
                            </div>
                        </div>
                        <div class="sfs-hr-swap-actions">
                            <span class="sfs-hr-swap-status" style="background:<?php echo esc_attr($status_colors[$req->status] ?? '#6b7280'); ?>1a; color:<?php echo esc_attr($status_colors[$req->status] ?? '#6b7280'); ?>;">
                                <?php echo esc_html($status_labels[$req->status] ?? $req->status); ?>
                            </span>
                            <?php if ($req->status === 'pending'): ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <input type="hidden" name="action" value="sfs_hr_cancel_shift_swap" />
                                <input type="hidden" name="swap_id" value="<?php echo (int)$req->id; ?>" />
                                <?php wp_nonce_field('sfs_hr_cancel_swap_' . $req->id); ?>
                                <button type="submit" class="button button-small"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
        <?php
    }

    /**
     * Handle swap request submission
     */
    public function handle_swap_request(): void {
        $employee_id = $this->get_current_employee_id();
        if (!$employee_id) {
            wp_die(esc_html__('Not authorized.', 'sfs-hr'));
        }

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_request_swap_' . $employee_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        global $wpdb;

        // Parse my_shift value
        $my_shift_data = isset($_POST['my_shift']) ? sanitize_text_field($_POST['my_shift']) : '';
        if (!$my_shift_data || strpos($my_shift_data, '_') === false) {
            $this->redirect_with_error(__('Please select your shift.', 'sfs-hr'));
            return;
        }

        list($shift_assign_id, $requester_date) = explode('_', $my_shift_data);
        $shift_assign_id = (int)$shift_assign_id;

        $target_id = isset($_POST['colleague_id']) ? (int)$_POST['colleague_id'] : 0;
        $target_date = isset($_POST['target_date']) ? sanitize_text_field($_POST['target_date']) : '';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        if (!$target_id || !$target_date) {
            $this->redirect_with_error(__('Please fill in all required fields.', 'sfs-hr'));
            return;
        }

        // Verify the shift belongs to the requester
        $shifts_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assigns';
        $shift = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$shifts_table} WHERE id = %d AND employee_id = %d",
            $shift_assign_id,
            $employee_id
        ));

        if (!$shift) {
            $this->redirect_with_error(__('Invalid shift selected.', 'sfs-hr'));
            return;
        }

        // Insert swap request
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $now = current_time('mysql');

        $wpdb->insert($table, [
            'requester_id' => $employee_id,
            'requester_shift_id' => $shift_assign_id,
            'requester_date' => $requester_date,
            'target_id' => $target_id,
            'target_date' => $target_date,
            'reason' => $reason,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $swap_id = $wpdb->insert_id;

        // Trigger notification
        do_action('sfs_hr_shift_swap_requested', $swap_id, $target_id);

        // Audit log
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'shift_swap_requested',
                'shift_swaps',
                $swap_id,
                null,
                ['requester_id' => $employee_id, 'target_id' => $target_id]
            );
        }

        $this->redirect_with_success(__('Shift swap request sent!', 'sfs-hr'));
    }

    /**
     * Handle swap response (accept/decline)
     */
    public function handle_swap_response(): void {
        $swap_id = isset($_POST['swap_id']) ? (int)$_POST['swap_id'] : 0;
        $response = isset($_POST['response']) ? sanitize_key($_POST['response']) : '';

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_respond_swap_' . $swap_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        if (!in_array($response, ['accept', 'decline'], true)) {
            wp_die(esc_html__('Invalid response.', 'sfs-hr'));
        }

        $employee_id = $this->get_current_employee_id();
        if (!$employee_id) {
            wp_die(esc_html__('Not authorized.', 'sfs-hr'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';

        // Verify swap belongs to this employee as target
        $swap = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND target_id = %d AND status = 'pending'",
            $swap_id,
            $employee_id
        ));

        if (!$swap) {
            $this->redirect_with_error(__('Swap request not found or already processed.', 'sfs-hr'));
            return;
        }

        $new_status = ($response === 'accept') ? 'manager_pending' : 'declined';

        $wpdb->update(
            $table,
            [
                'status' => $new_status,
                'target_responded_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $swap_id]
        );

        // Notify requester
        do_action('sfs_hr_shift_swap_responded', $swap_id, $response);

        // Audit log
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'shift_swap_' . $response . 'ed',
                'shift_swaps',
                $swap_id,
                ['status' => 'pending'],
                ['status' => $new_status]
            );
        }

        if ($response === 'accept') {
            $this->redirect_with_success(__('Swap accepted! Awaiting manager approval.', 'sfs-hr'));
        } else {
            $this->redirect_with_success(__('Swap request declined.', 'sfs-hr'));
        }
    }

    /**
     * Handle swap cancellation
     */
    public function handle_swap_cancel(): void {
        $swap_id = isset($_POST['swap_id']) ? (int)$_POST['swap_id'] : 0;

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_cancel_swap_' . $swap_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        $employee_id = $this->get_current_employee_id();
        if (!$employee_id) {
            wp_die(esc_html__('Not authorized.', 'sfs-hr'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';

        // Verify swap belongs to this employee as requester
        $swap = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND requester_id = %d AND status = 'pending'",
            $swap_id,
            $employee_id
        ));

        if (!$swap) {
            $this->redirect_with_error(__('Swap request not found or cannot be cancelled.', 'sfs-hr'));
            return;
        }

        $wpdb->update(
            $table,
            [
                'status' => 'cancelled',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $swap_id]
        );

        $this->redirect_with_success(__('Swap request cancelled.', 'sfs-hr'));
    }

    /**
     * Handle manager approval (called from Attendance admin)
     */
    public function handle_manager_approval(): void {
        $swap_id = isset($_POST['swap_id']) ? (int)$_POST['swap_id'] : 0;
        $decision = isset($_POST['decision']) ? sanitize_key($_POST['decision']) : '';

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_approve_swap_' . $swap_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        if (!current_user_can('sfs_hr.manage') && !current_user_can('sfs_hr_attendance_admin')) {
            wp_die(esc_html__('Not authorized.', 'sfs-hr'));
        }

        if (!in_array($decision, ['approve', 'reject'], true)) {
            wp_die(esc_html__('Invalid decision.', 'sfs-hr'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';

        $swap = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND status = 'manager_pending'",
            $swap_id
        ));

        if (!$swap) {
            wp_safe_redirect(admin_url('admin.php?page=sfs-hr-attendance&tab=shift_swaps&error=' . rawurlencode(__('Swap not found.', 'sfs-hr'))));
            exit;
        }

        $new_status = ($decision === 'approve') ? 'approved' : 'rejected';
        $manager_note = isset($_POST['manager_note']) ? sanitize_textarea_field($_POST['manager_note']) : '';

        $wpdb->update(
            $table,
            [
                'status' => $new_status,
                'manager_id' => get_current_user_id(),
                'manager_responded_at' => current_time('mysql'),
                'manager_note' => $manager_note,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $swap_id]
        );

        // If approved, actually swap the shifts
        if ($decision === 'approve') {
            $this->execute_shift_swap($swap);
        }

        // Audit log
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'shift_swap_' . $decision . 'd',
                'shift_swaps',
                $swap_id,
                ['status' => 'manager_pending'],
                ['status' => $new_status]
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-attendance&tab=shift_swaps&success=' . rawurlencode(__('Swap ' . $decision . 'd.', 'sfs-hr'))));
        exit;
    }

    /**
     * Execute the actual shift swap in the database
     */
    private function execute_shift_swap($swap): void {
        global $wpdb;
        $shifts_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assigns';

        // Get requester's shift assignment
        $requester_shift = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$shifts_table} WHERE id = %d",
            $swap->requester_shift_id
        ));

        // Get target's shift assignment for the target date
        $target_shift = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$shifts_table} WHERE employee_id = %d AND assign_date = %s",
            $swap->target_id,
            $swap->target_date
        ));

        if ($requester_shift) {
            // Swap requester's shift to target
            $wpdb->update(
                $shifts_table,
                ['employee_id' => $swap->target_id, 'updated_at' => current_time('mysql')],
                ['id' => $requester_shift->id]
            );
        }

        if ($target_shift) {
            // Swap target's shift to requester
            $wpdb->update(
                $shifts_table,
                ['employee_id' => $swap->requester_id, 'updated_at' => current_time('mysql')],
                ['id' => $target_shift->id]
            );
        }
    }

    /**
     * Get current employee ID
     */
    private function get_current_employee_id(): int {
        if (!is_user_logged_in()) {
            return 0;
        }

        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$emp_table} WHERE user_id = %d AND status = 'active'",
            get_current_user_id()
        ));
    }

    /**
     * Redirect helpers
     */
    private function redirect_with_error(string $message): void {
        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-my-profile&tab=shift_swap&error=' . rawurlencode($message)));
        exit;
    }

    private function redirect_with_success(string $message): void {
        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-my-profile&tab=shift_swap&success=' . rawurlencode($message)));
        exit;
    }

    /**
     * Notify target employee of swap request
     */
    public function notify_swap_requested(int $swap_id, int $target_id): void {
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $target = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, u.user_email
             FROM {$emp_table} e
             LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
             WHERE e.id = %d",
            $target_id
        ));

        if ($target && $target->user_email && class_exists('\SFS\HR\Core\Notifications')) {
            \SFS\HR\Core\Notifications::send_email(
                $target->user_email,
                __('Shift Swap Request', 'sfs-hr'),
                sprintf(
                    __('You have received a shift swap request. Please review it in your HR profile: %s', 'sfs-hr'),
                    admin_url('admin.php?page=sfs-hr-my-profile&tab=shift_swap')
                )
            );
        }
    }

    /**
     * Notify requester of swap response
     */
    public function notify_swap_responded(int $swap_id, string $response): void {
        global $wpdb;
        $swap_table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $swap = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$swap_table} WHERE id = %d",
            $swap_id
        ));

        if (!$swap) return;

        $requester = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, u.user_email
             FROM {$emp_table} e
             LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
             WHERE e.id = %d",
            $swap->requester_id
        ));

        if ($requester && $requester->user_email && class_exists('\SFS\HR\Core\Notifications')) {
            $subject = ($response === 'accept')
                ? __('Shift Swap Accepted', 'sfs-hr')
                : __('Shift Swap Declined', 'sfs-hr');

            $message = ($response === 'accept')
                ? __('Your shift swap request has been accepted and is now awaiting manager approval.', 'sfs-hr')
                : __('Your shift swap request has been declined.', 'sfs-hr');

            \SFS\HR\Core\Notifications::send_email($requester->user_email, $subject, $message);
        }
    }

    /**
     * Register REST routes
     */
    public function register_rest_routes(): void {
        register_rest_route('sfs-hr/v1', '/shift-swaps', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_swaps'],
            'permission_callback' => function() {
                return current_user_can('sfs_hr.manage') || current_user_can('sfs_hr_attendance_admin');
            },
        ]);
    }

    /**
     * REST: Get pending swap requests for managers
     */
    public function rest_get_swaps(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $swaps = $wpdb->get_results(
            "SELECT sw.*,
                    CONCAT(req.first_name, ' ', req.last_name) AS requester_name,
                    CONCAT(tgt.first_name, ' ', tgt.last_name) AS target_name
             FROM {$table} sw
             JOIN {$emp_table} req ON req.id = sw.requester_id
             JOIN {$emp_table} tgt ON tgt.id = sw.target_id
             WHERE sw.status = 'manager_pending'
             ORDER BY sw.created_at DESC"
        );

        return new \WP_REST_Response($swaps, 200);
    }

    /**
     * Get pending swaps count (for admin badge)
     */
    public static function get_pending_count(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';

        return (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'manager_pending'"
        );
    }
}
