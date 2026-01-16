<?php
namespace SFS\HR\Modules\ShiftSwap\Admin;

use SFS\HR\Modules\ShiftSwap\Services\ShiftSwap_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Shift Swap Tab
 * Employee self-service tab for shift swaps
 */
class ShiftSwap_Tab {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('sfs_hr_employee_tabs', [$this, 'add_tab'], 25);
        add_action('sfs_hr_employee_tab_content', [$this, 'render'], 10, 2);
    }

    /**
     * Add tab to My Profile
     */
    public function add_tab($employee): void {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        $tab_class = 'nav-tab' . ($active_tab === 'shift_swap' ? ' nav-tab-active' : '');
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

        // Only show on My Profile
        if ($page !== 'sfs-hr-my-profile') {
            return;
        }

        $url = admin_url('admin.php?page=sfs-hr-my-profile&tab=shift_swap');
        echo '<a href="' . esc_url($url) . '" class="' . esc_attr($tab_class) . '">';
        esc_html_e('Shift Swaps', 'sfs-hr');
        echo '</a>';
    }

    /**
     * Render tab content
     */
    public function render($employee, string $active_tab): void {
        if ($active_tab !== 'shift_swap') {
            return;
        }

        $employee_id = (int)$employee->id;
        $current_user_id = get_current_user_id();

        // Verify this is the employee's own profile
        if ((int)$employee->user_id !== $current_user_id) {
            wp_die(esc_html__('You can only access your own shift swaps.', 'sfs-hr'));
        }

        $my_shifts = ShiftSwap_Service::get_employee_shifts($employee_id);
        $colleagues = ShiftSwap_Service::get_colleagues((int)$employee->dept_id, $employee_id);
        $incoming_requests = ShiftSwap_Service::get_incoming_requests($employee_id);
        $outgoing_requests = ShiftSwap_Service::get_outgoing_requests($employee_id);

        $this->show_messages();
        $this->render_styles();

        ?>
        <div class="sfs-hr-shift-swap-wrap">
            <?php if (!empty($incoming_requests)): ?>
                <?php $this->render_incoming_requests($incoming_requests); ?>
            <?php endif; ?>

            <?php $this->render_request_form($employee_id, $my_shifts, $colleagues); ?>

            <?php $this->render_outgoing_requests($outgoing_requests); ?>
        </div>
        <?php
    }

    /**
     * Show success/error messages
     */
    private function show_messages(): void {
        if (isset($_GET['success'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['success']))) . '</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['error']))) . '</p></div>';
        }
    }

    /**
     * Render CSS styles
     */
    private function render_styles(): void {
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
        <?php
    }

    /**
     * Render incoming requests section
     */
    private function render_incoming_requests(array $requests): void {
        ?>
        <div class="sfs-hr-swap-card">
            <h3><?php esc_html_e('Incoming Swap Requests', 'sfs-hr'); ?></h3>

            <?php foreach ($requests as $req): ?>
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
        <?php
    }

    /**
     * Render request form
     */
    private function render_request_form(int $employee_id, array $shifts, array $colleagues): void {
        $today = wp_date('Y-m-d');

        ?>
        <div class="sfs-hr-swap-card">
            <h3><?php esc_html_e('Request a Shift Swap', 'sfs-hr'); ?></h3>

            <?php if (empty($shifts)): ?>
                <p class="description"><?php esc_html_e('You have no upcoming shifts to swap.', 'sfs-hr'); ?></p>
            <?php elseif (empty($colleagues)): ?>
                <p class="description"><?php esc_html_e('No colleagues available for swap.', 'sfs-hr'); ?></p>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="sfs_hr_request_shift_swap" />
                    <?php wp_nonce_field('sfs_hr_request_swap_' . $employee_id); ?>

                    <table class="form-table">
                        <tr>
                            <th><label><?php esc_html_e('My Shift to Swap', 'sfs-hr'); ?></label></th>
                            <td>
                                <select name="my_shift" required style="min-width:250px;">
                                    <option value=""><?php esc_html_e('— Select your shift —', 'sfs-hr'); ?></option>
                                    <?php foreach ($shifts as $shift): ?>
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
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render outgoing requests
     */
    private function render_outgoing_requests(array $requests): void {
        $status_labels = ShiftSwap_Service::get_status_labels();
        $status_colors = ShiftSwap_Service::get_status_colors();

        ?>
        <div class="sfs-hr-swap-card">
            <h3><?php esc_html_e('My Swap Requests', 'sfs-hr'); ?></h3>

            <?php if (empty($requests)): ?>
                <p class="description"><?php esc_html_e('No swap requests yet.', 'sfs-hr'); ?></p>
            <?php else: ?>
                <?php foreach ($requests as $req): ?>
                <div class="sfs-hr-swap-item">
                    <div class="sfs-hr-swap-info">
                        <strong>
                            <?php printf(esc_html__('Swap with %s', 'sfs-hr'), esc_html($req->target_name)); ?>
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
        <?php
    }
}
