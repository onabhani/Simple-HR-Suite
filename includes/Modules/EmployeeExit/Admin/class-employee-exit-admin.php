<?php
namespace SFS\HR\Modules\EmployeeExit\Admin;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_List;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_Settings;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_List;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_Form;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_View;
use SFS\HR\Modules\Payroll\Admin\Admin_Pages as Payroll_Admin;

if (!defined('ABSPATH')) { exit; }

/**
 * Employee Exit Admin
 * Unified admin page for resignation, settlement, and payroll management
 */
class Employee_Exit_Admin {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_menu', [$this, 'register_menu'], 25);
    }

    /**
     * Register admin menu - single unified page for finance and exit
     */
    public function register_menu(): void {
        // Main Finance & Exit page
        add_submenu_page(
            'sfs-hr',
            __('Finance & Exit', 'sfs-hr'),
            __('Finance & Exit', 'sfs-hr'),
            'sfs_hr.view',
            'sfs-hr-finance-exit',
            [$this, 'render_hub']
        );
    }

    /**
     * Render the unified hub page with tabs
     */
    public function render_hub(): void {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'payroll';

        echo '<div class="wrap sfs-hr-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Finance & Exit Management', 'sfs-hr') . '</h1>';
        Helpers::render_admin_nav();
        echo '<hr class="wp-header-end" />';

        // Tab navigation
        $this->render_tabs($tab);

        switch ($tab) {
            case 'payroll':
                $this->render_payroll_content();
                break;

            case 'resignations':
                $this->render_resignations_content();
                break;

            case 'settlements':
                $this->render_settlements_content();
                break;

            case 'resignation-settings':
                if (current_user_can('sfs_hr.manage')) {
                    Resignation_Settings::render();
                }
                break;

            default:
                $this->render_payroll_content();
                break;
        }

        echo '</div>';
    }

    /**
     * Render tab navigation
     */
    private function render_tabs(string $current_tab): void {
        $tabs = [
            'payroll' => __('Payroll', 'sfs-hr'),
            'resignations' => __('Resignations', 'sfs-hr'),
            'settlements' => __('Settlements', 'sfs-hr'),
        ];

        // Add settings tab only for managers
        if (current_user_can('sfs_hr.manage')) {
            $tabs['resignation-settings'] = __('Exit Settings', 'sfs-hr');
        }

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(['page' => 'sfs-hr-finance-exit', 'tab' => $slug], admin_url('admin.php'));
            $class = ($current_tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';
        echo '<div style="margin-top: 20px;"></div>';
    }

    /**
     * Render payroll content (delegated to payroll module)
     */
    private function render_payroll_content(): void {
        // Get payroll sub-tab
        $payroll_tab = isset($_GET['payroll_tab']) ? sanitize_key($_GET['payroll_tab']) : 'overview';

        // Payroll sub-tabs
        $payroll_tabs = [
            'overview'   => __('Overview', 'sfs-hr'),
            'periods'    => __('Payroll Periods', 'sfs-hr'),
            'runs'       => __('Payroll Runs', 'sfs-hr'),
            'components' => __('Salary Components', 'sfs-hr'),
            'payslips'   => __('Payslips', 'sfs-hr'),
            'export'     => __('Export', 'sfs-hr'),
        ];

        if (!isset($payroll_tabs[$payroll_tab])) {
            $payroll_tab = 'overview';
        }

        // Render payroll sub-tabs
        echo '<div class="sfs-hr-subtabs" style="margin-bottom: 15px;">';
        foreach ($payroll_tabs as $slug => $label) {
            $url = add_query_arg(['page' => 'sfs-hr-finance-exit', 'tab' => 'payroll', 'payroll_tab' => $slug], admin_url('admin.php'));
            $class = ($payroll_tab === $slug) ? 'button button-primary' : 'button';
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '" style="margin-right: 5px;">' . esc_html($label) . '</a>';
        }
        echo '</div>';

        // Use the existing Payroll_Admin renderer
        $payroll_admin = new Payroll_Admin();
        $payroll_admin->render_tab_content($payroll_tab);
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
