<?php
namespace SFS\HR\Modules\Reminders\Admin;

use SFS\HR\Modules\Reminders\Services\Reminders_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Celebrations Page
 * Admin page showing birthdays and anniversaries by month
 */
class Celebrations_Page {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_menu', [$this, 'add_menu'], 50);
    }

    /**
     * Add admin submenu
     */
    public function add_menu(): void {
        add_submenu_page(
            null,
            __('Upcoming Celebrations', 'sfs-hr'),
            __('Celebrations', 'sfs-hr'),
            'sfs_hr.view',
            'sfs-hr-celebrations',
            [$this, 'render']
        );
    }

    /**
     * Render the page
     */
    public function render(): void {
        $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

        $birthdays = Reminders_Service::get_birthdays_for_month($month);
        $anniversaries = Reminders_Service::get_anniversaries_for_month($month, $year);
        $month_names = Reminders_Service::get_month_names();

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e('Upcoming Celebrations', 'sfs-hr'); ?></h1>

            <?php if (method_exists('\SFS\HR\Core\Helpers', 'render_admin_nav')): ?>
                <?php \SFS\HR\Core\Helpers::render_admin_nav(); ?>
            <?php endif; ?>

            <hr class="wp-header-end" />

            <?php $this->render_month_selector($month, $year, $month_names); ?>

            <h2><?php echo esc_html($month_names[$month] . ' ' . $year); ?></h2>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(400px, 1fr)); gap:20px; margin-top:20px;">
                <?php $this->render_birthdays_card($birthdays); ?>
                <?php $this->render_anniversaries_card($anniversaries); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render month selector form
     */
    private function render_month_selector(int $month, int $year, array $month_names): void {
        ?>
        <div style="margin:20px 0;">
            <form method="get" style="display:flex; gap:10px; align-items:center;">
                <input type="hidden" name="page" value="sfs-hr-celebrations" />
                <select name="month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php selected($month, $m); ?>>
                            <?php echo esc_html($month_names[$m]); ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <select name="year">
                    <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php selected($year, $y); ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('View', 'sfs-hr'); ?></button>
            </form>
        </div>
        <?php
    }

    /**
     * Render birthdays card
     */
    private function render_birthdays_card(array $birthdays): void {
        ?>
        <div style="background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:16px;">
            <h3 style="margin:0 0 16px; color:#ec4899;">
                <span style="margin-right:8px;">&#127874;</span>
                <?php esc_html_e('Birthdays', 'sfs-hr'); ?>
                <span style="font-weight:normal; font-size:14px; color:#666;">(<?php echo count($birthdays); ?>)</span>
            </h3>

            <?php if (empty($birthdays)): ?>
                <p class="description"><?php esc_html_e('No birthdays this month.', 'sfs-hr'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Day', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('Department', 'sfs-hr'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($birthdays as $emp): ?>
                        <tr>
                            <td style="width:50px; text-align:center; font-weight:600;">
                                <?php echo (int)$emp->day_of_month; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $emp->id)); ?>">
                                    <?php echo esc_html($emp->first_name . ' ' . $emp->last_name); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($emp->department_name ?: '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render anniversaries card
     */
    private function render_anniversaries_card(array $anniversaries): void {
        ?>
        <div style="background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:16px;">
            <h3 style="margin:0 0 16px; color:#2271b1;">
                <span style="margin-right:8px;">&#127942;</span>
                <?php esc_html_e('Work Anniversaries', 'sfs-hr'); ?>
                <span style="font-weight:normal; font-size:14px; color:#666;">(<?php echo count($anniversaries); ?>)</span>
            </h3>

            <?php if (empty($anniversaries)): ?>
                <p class="description"><?php esc_html_e('No work anniversaries this month.', 'sfs-hr'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Day', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('Years', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('Department', 'sfs-hr'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($anniversaries as $emp): ?>
                        <tr>
                            <td style="width:50px; text-align:center; font-weight:600;">
                                <?php echo (int)$emp->day_of_month; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $emp->id)); ?>">
                                    <?php echo esc_html($emp->first_name . ' ' . $emp->last_name); ?>
                                </a>
                            </td>
                            <td>
                                <span style="background:#2271b11a; color:#2271b1; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600;">
                                    <?php printf(esc_html__('%d year(s)', 'sfs-hr'), $emp->years_completing); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($emp->department_name ?: '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
