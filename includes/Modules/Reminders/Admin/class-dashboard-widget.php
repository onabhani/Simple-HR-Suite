<?php
namespace SFS\HR\Modules\Reminders\Admin;

use SFS\HR\Modules\Reminders\Services\Reminders_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Reminders Dashboard Widget
 * Shows upcoming birthdays and anniversaries on HR Dashboard
 */
class Dashboard_Widget {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('sfs_hr_dashboard_widgets', [$this, 'render'], 15);
    }

    /**
     * Render the widget
     */
    public function render(): void {
        $birthdays = Reminders_Service::get_upcoming_birthdays([0, 1, 2, 3, 4, 5, 6, 7]);
        $anniversaries = Reminders_Service::get_upcoming_anniversaries([0, 1, 2, 3, 4, 5, 6, 7]);

        if (empty($birthdays) && empty($anniversaries)) {
            return;
        }

        ?>
        <div class="sfs-hr-dashboard-widget" style="background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:16px; margin-bottom:20px;">
            <h3 style="margin:0 0 16px; font-size:14px; display:flex; align-items:center; gap:8px;">
                <span style="font-size:18px;">&#127881;</span>
                <?php esc_html_e('Upcoming Celebrations', 'sfs-hr'); ?>
            </h3>

            <?php if (!empty($birthdays)): ?>
                <?php $this->render_birthdays_section($birthdays); ?>
            <?php endif; ?>

            <?php if (!empty($anniversaries)): ?>
                <?php $this->render_anniversaries_section($anniversaries); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render birthdays section
     */
    private function render_birthdays_section(array $birthdays): void {
        ?>
        <div style="margin-bottom:16px;">
            <h4 style="margin:0 0 8px; font-size:12px; color:#666; text-transform:uppercase;">
                <?php esc_html_e('Birthdays', 'sfs-hr'); ?>
            </h4>
            <?php foreach ($birthdays as $emp): ?>
                <?php $this->render_employee_item($emp, 'birthday'); ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render anniversaries section
     */
    private function render_anniversaries_section(array $anniversaries): void {
        ?>
        <div>
            <h4 style="margin:0 0 8px; font-size:12px; color:#666; text-transform:uppercase;">
                <?php esc_html_e('Work Anniversaries', 'sfs-hr'); ?>
            </h4>
            <?php foreach ($anniversaries as $emp): ?>
                <?php $this->render_employee_item($emp, 'anniversary'); ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render single employee item
     */
    private function render_employee_item($emp, string $type): void {
        $bg_color = $type === 'birthday'
            ? 'linear-gradient(135deg, #ec4899 0%, #f472b6 100%)'
            : 'linear-gradient(135deg, #2271b1 0%, #135e96 100%)';
        $highlight_color = $type === 'birthday' ? '#ec4899' : '#2271b1';

        ?>
        <div style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f0f0f1;">
            <div style="width:36px; height:36px; border-radius:50%; background:<?php echo $bg_color; ?>; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:12px;">
                <?php echo esc_html(mb_substr($emp->first_name, 0, 1) . mb_substr($emp->last_name, 0, 1)); ?>
            </div>
            <div style="flex:1;">
                <strong style="display:block; font-size:13px;">
                    <?php echo esc_html($emp->first_name . ' ' . $emp->last_name); ?>
                </strong>
                <span style="font-size:11px; color:#666;">
                    <?php
                    if ($type === 'birthday') {
                        if ($emp->days_until == 0) {
                            echo '<span style="color:' . $highlight_color . '; font-weight:600;">' . esc_html__('Today!', 'sfs-hr') . '</span>';
                        } elseif ($emp->days_until == 1) {
                            esc_html_e('Tomorrow', 'sfs-hr');
                        } else {
                            printf(esc_html__('In %d days', 'sfs-hr'), $emp->days_until);
                        }
                    } else {
                        $years = isset($emp->years_completing) ? (int)$emp->years_completing : 1;
                        if ($emp->days_until == 0) {
                            printf('<span style="color:' . $highlight_color . '; font-weight:600;">' . esc_html__('%d year(s) today!', 'sfs-hr') . '</span>', $years);
                        } elseif ($emp->days_until == 1) {
                            printf(esc_html__('%d year(s) tomorrow', 'sfs-hr'), $years);
                        } else {
                            printf(esc_html__('%d year(s) in %d days', 'sfs-hr'), $years, $emp->days_until);
                        }
                    }
                    ?>
                </span>
            </div>
        </div>
        <?php
    }
}
