<?php
namespace SFS\HR\Modules\Resignation\Frontend;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Resignation\Services\Resignation_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Resignation Shortcodes
 * Frontend shortcodes for employee self-service
 */
class Resignation_Shortcodes {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_shortcode('sfs_hr_resignation_submit', [$this, 'render_submit_form']);
        add_shortcode('sfs_hr_my_resignations', [$this, 'render_my_resignations']);
    }

    /**
     * Render resignation submission form
     */
    public function render_submit_form($atts): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to submit a resignation.', 'sfs-hr') . '</p>';
        }

        $employee_id = Helpers::current_employee_id();
        if (!$employee_id) {
            return '<p>' . esc_html__('No employee record found.', 'sfs-hr') . '</p>';
        }

        $notice_period = (int)get_option('sfs_hr_resignation_notice_period', 30);

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
                    <input type="number" name="notice_period_days" id="notice_period_days"
                           value="<?php echo esc_attr($notice_period); ?>" min="0" readonly required
                           style="width:100%;max-width:300px;background:#f5f5f5;cursor:not-allowed;">
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

    /**
     * Render employee's resignations list
     */
    public function render_my_resignations($atts): string {
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
                                <td style="border:1px solid #ddd;padding:8px;">
                                    <?php echo Resignation_Service::status_badge($r['status'], intval($r['approval_level'] ?? 1)); ?>
                                </td>
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
