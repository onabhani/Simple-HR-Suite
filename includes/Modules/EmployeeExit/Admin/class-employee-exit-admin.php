<?php
namespace SFS\HR\Modules\EmployeeExit\Admin;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_List;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_Settings;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_List;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_Form;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_View;

if (!defined('ABSPATH')) { exit; }

/**
 * Employee Exit Admin
 * Unified admin page for resignation and settlement management
 */
class Employee_Exit_Admin {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_menu', [$this, 'register_menu']);
    }

    /**
     * Register admin menu - single unified page for employee exit
     */
    public function register_menu(): void {
        // Main Employee Exit page
        add_submenu_page(
            'sfs-hr',
            __('Resignations', 'sfs-hr'),
            __('Resignations', 'sfs-hr'),
            'sfs_hr.view',
            'sfs-hr-resignations',
            [$this, 'render_resignations_page']
        );

        // Settlements as separate menu item but part of exit workflow
        add_submenu_page(
            'sfs-hr',
            __('Settlements', 'sfs-hr'),
            __('Settlements', 'sfs-hr'),
            'sfs_hr.manage',
            'sfs-hr-settlements',
            [$this, 'render_settlements_page']
        );
    }

    /**
     * Render resignations page
     */
    public function render_resignations_page(): void {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'resignations';

        echo '<div class="wrap sfs-hr-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Resignations', 'sfs-hr') . '</h1>';
        Helpers::render_admin_nav();
        echo '<hr class="wp-header-end" />';

        // Tab navigation
        if (current_user_can('sfs_hr.manage')) {
            $this->render_resignation_tabs($tab);
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
     * Render settlements page
     */
    public function render_settlements_page(): void {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        $settlement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        switch ($action) {
            case 'create':
                Settlement_Form::render();
                break;

            case 'view':
            case 'edit':
                if ($settlement_id) {
                    Settlement_View::render($settlement_id);
                } else {
                    Settlement_List::render();
                }
                break;

            default:
                Settlement_List::render();
                break;
        }
    }

    /**
     * Render resignation tab navigation
     */
    private function render_resignation_tabs(string $current_tab): void {
        ?>
        <style>
            .sfs-hr-exit-tabs { display: flex; gap: 8px; margin-bottom: 20px; }
            .sfs-hr-exit-tabs .sfs-exit-tab {
                padding: 10px 20px; background: #f6f7f7; border: 1px solid #dcdcde;
                border-radius: 4px; text-decoration: none; color: #50575e; font-weight: 500;
            }
            .sfs-hr-exit-tabs .sfs-exit-tab:hover { background: #fff; border-color: #2271b1; color: #2271b1; }
            .sfs-hr-exit-tabs .sfs-exit-tab.active { background: #2271b1; border-color: #2271b1; color: #fff; }
        </style>
        <div class="sfs-hr-exit-tabs">
            <a href="?page=sfs-hr-resignations&tab=resignations" class="sfs-exit-tab<?php echo $current_tab === 'resignations' ? ' active' : ''; ?>">
                <?php esc_html_e('Resignations', 'sfs-hr'); ?>
            </a>
            <a href="?page=sfs-hr-resignations&tab=settings" class="sfs-exit-tab<?php echo $current_tab === 'settings' ? ' active' : ''; ?>">
                <?php esc_html_e('Settings', 'sfs-hr'); ?>
            </a>
        </div>
        <?php
    }
}
