<?php
namespace SFS\HR\Modules\Resignation\Admin\Views;

if (!defined('ABSPATH')) { exit; }

/**
 * Resignation Settings View
 * Admin settings form for resignation module
 */
class Resignation_Settings {

    /**
     * Render the settings form
     */
    public static function render(): void {
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
}
