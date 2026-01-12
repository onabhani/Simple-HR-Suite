<?php
namespace SFS\HR\Modules\Resignation\Admin;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_List;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_Settings;

if (!defined('ABSPATH')) { exit; }

/**
 * Resignation Admin
 * Admin page routing for resignation management
 */
class Resignation_Admin {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('init', [$this, 'register_roles_and_caps']);
    }

    /**
     * Register admin menu
     */
    public function register_menu(): void {
        add_submenu_page(
            'sfs-hr',
            __('Resignations', 'sfs-hr'),
            __('Resignations', 'sfs-hr'),
            'sfs_hr.view',
            'sfs-hr-resignations',
            [$this, 'render_page']
        );
    }

    /**
     * Register roles and capabilities
     */
    public function register_roles_and_caps(): void {
        // Add capability to Administrator role
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('sfs_hr_resignation_finance_approve');
        }

        // Create Finance Approver role if it doesn't exist
        if (!get_role('sfs_hr_finance_approver')) {
            add_role(
                'sfs_hr_finance_approver',
                __('HR Finance Approver', 'sfs-hr'),
                [
                    'read'                               => true,
                    'sfs_hr_resignation_finance_approve' => true,
                    'sfs_hr.view'                        => true,
                ]
            );
        } else {
            $finance_role = get_role('sfs_hr_finance_approver');
            if ($finance_role && !$finance_role->has_cap('sfs_hr_resignation_finance_approve')) {
                $finance_role->add_cap('sfs_hr_resignation_finance_approve');
            }
        }
    }

    /**
     * Render admin page
     */
    public function render_page(): void {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'resignations';

        echo '<div class="wrap sfs-hr-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Resignations', 'sfs-hr') . '</h1>';
        Helpers::render_admin_nav();
        echo '<hr class="wp-header-end" />';

        // Tab navigation (only show if user can manage settings)
        if (current_user_can('sfs_hr.manage')) {
            $this->render_tab_navigation($tab);
        }

        switch ($tab) {
            case 'settings':
                if (current_user_can('sfs_hr.manage')) {
                    Resignation_Settings::render();
                }
                break;

            case 'resignations':
            default:
                Resignation_List::render();
                break;
        }

        echo '</div>';
    }

    /**
     * Render tab navigation
     */
    private function render_tab_navigation(string $current_tab): void {
        ?>
        <style>
            .sfs-hr-resignation-main-tabs { display: flex; gap: 8px; margin-bottom: 20px; }
            .sfs-hr-resignation-main-tabs .sfs-main-tab {
                padding: 10px 20px; background: #f6f7f7; border: 1px solid #dcdcde;
                border-radius: 4px; text-decoration: none; color: #50575e; font-weight: 500;
            }
            .sfs-hr-resignation-main-tabs .sfs-main-tab:hover { background: #fff; border-color: #2271b1; color: #2271b1; }
            .sfs-hr-resignation-main-tabs .sfs-main-tab.active { background: #2271b1; border-color: #2271b1; color: #fff; }
        </style>
        <div class="sfs-hr-resignation-main-tabs">
            <a href="?page=sfs-hr-resignations&tab=resignations" class="sfs-main-tab<?php echo $current_tab === 'resignations' ? ' active' : ''; ?>">
                <?php esc_html_e('Resignations', 'sfs-hr'); ?>
            </a>
            <a href="?page=sfs-hr-resignations&tab=settings" class="sfs-main-tab<?php echo $current_tab === 'settings' ? ' active' : ''; ?>">
                <?php esc_html_e('Settings', 'sfs-hr'); ?>
            </a>
        </div>
        <?php
    }
}
