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
 * Unified admin page for the employee exit workflow:
 * - Resignations
 * - Settlements
 * - Exit Settings
 *
 * Note: Hiring (Candidates, Trainees) is now a separate page under HR → Hiring.
 */
class Employee_Exit_Admin {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_menu', [$this, 'register_menu'], 25);
    }

    /**
     * Register admin menu
     */
    public function register_menu(): void {
        add_submenu_page(
            'sfs-hr',
            __('Employee Exit', 'sfs-hr'),
            __('Employee Exit', 'sfs-hr'),
            'sfs_hr.view',
            'sfs-hr-lifecycle',
            [$this, 'render_hub']
        );
    }

    /**
     * Render the hub page with tabs
     */
    public function render_hub(): void {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'resignations';

        echo '<div class="wrap sfs-hr-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Employee Exit', 'sfs-hr') . '</h1>';
        Helpers::render_admin_nav();
        echo '<hr class="wp-header-end" />';

        // Tab navigation
        $this->render_tabs($tab);

        switch ($tab) {
            case 'resignations':
                $this->render_resignations_content();
                break;

            case 'settlements':
                $this->render_settlements_content();
                break;

            case 'exit-settings':
                if (current_user_can('sfs_hr.manage')) {
                    Resignation_Settings::render();
                }
                break;

            default:
                $this->render_resignations_content();
                break;
        }

        echo '</div>';
    }

    /**
     * Render tab navigation
     */
    private function render_tabs(string $current_tab): void {
        $tabs = [
            'resignations' => __('Resignations', 'sfs-hr'),
            'settlements' => __('Settlements', 'sfs-hr'),
        ];

        // Add settings tab only for managers
        if (current_user_can('sfs_hr.manage')) {
            $tabs['exit-settings'] = __('Exit Settings', 'sfs-hr');
        }

        echo '<h2 class="nav-tab-wrapper">';

        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(['page' => 'sfs-hr-lifecycle', 'tab' => $slug], admin_url('admin.php'));
            $class = ($current_tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }

        echo '</h2>';
        echo '<div style="margin-top: 20px;"></div>';
    }

    /**
     * Render resignations content
     */
    private function render_resignations_content(): void {
        Resignation_List::render();
    }

    /**
     * Render settlements content
     */
    private function render_settlements_content(): void {
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
}
