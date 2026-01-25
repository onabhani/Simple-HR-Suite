<?php
namespace SFS\HR\Modules\EmployeeExit\Admin;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_List;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_Settings;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_List;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_Form;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_View;
use SFS\HR\Modules\Hiring\Admin\AdminPages as Hiring_Admin;

if (!defined('ABSPATH')) { exit; }

/**
 * Employee Lifecycle Admin
 * Unified admin page for the complete employee lifecycle:
 * - Entry: Candidates, Trainees (hiring process)
 * - Exit: Resignations, Settlements (departure process)
 */
class Employee_Exit_Admin {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_menu', [$this, 'register_menu'], 25);
    }

    /**
     * Register admin menu - single unified page for employee lifecycle
     */
    public function register_menu(): void {
        // Main Employee Lifecycle page
        add_submenu_page(
            'sfs-hr',
            __('Employee Lifecycle', 'sfs-hr'),
            __('Employee Lifecycle', 'sfs-hr'),
            'sfs_hr.view',
            'sfs-hr-lifecycle',
            [$this, 'render_hub']
        );
    }

    /**
     * Render the unified hub page with tabs
     */
    public function render_hub(): void {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'candidates';

        echo '<div class="wrap sfs-hr-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Employee Lifecycle', 'sfs-hr') . '</h1>';
        Helpers::render_admin_nav();
        echo '<hr class="wp-header-end" />';

        // Tab navigation
        $this->render_tabs($tab);

        switch ($tab) {
            case 'candidates':
                $this->render_candidates_content();
                break;

            case 'trainees':
                $this->render_trainees_content();
                break;

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
                $this->render_candidates_content();
                break;
        }

        echo '</div>';
    }

    /**
     * Render tab navigation
     */
    private function render_tabs(string $current_tab): void {
        $tabs = [
            // Entry phase
            'candidates' => __('Candidates', 'sfs-hr'),
            'trainees' => __('Trainees', 'sfs-hr'),
            // Exit phase
            'resignations' => __('Resignations', 'sfs-hr'),
            'settlements' => __('Settlements', 'sfs-hr'),
        ];

        // Add settings tab only for managers
        if (current_user_can('sfs_hr.manage')) {
            $tabs['exit-settings'] = __('Exit Settings', 'sfs-hr');
        }

        echo '<h2 class="nav-tab-wrapper">';

        // Entry group label
        echo '<span style="padding: 10px 0 0 10px; color: #666; font-size: 11px; text-transform: uppercase;">' . esc_html__('Entry', 'sfs-hr') . '</span>';

        foreach (['candidates', 'trainees'] as $slug) {
            $url = add_query_arg(['page' => 'sfs-hr-lifecycle', 'tab' => $slug], admin_url('admin.php'));
            $class = ($current_tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($tabs[$slug]) . '</a>';
        }

        // Separator
        echo '<span style="padding: 10px 5px 0 20px; color: #666; font-size: 11px; text-transform: uppercase;">' . esc_html__('Exit', 'sfs-hr') . '</span>';

        foreach (['resignations', 'settlements'] as $slug) {
            $url = add_query_arg(['page' => 'sfs-hr-lifecycle', 'tab' => $slug], admin_url('admin.php'));
            $class = ($current_tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($tabs[$slug]) . '</a>';
        }

        // Settings tab
        if (current_user_can('sfs_hr.manage')) {
            $url = add_query_arg(['page' => 'sfs-hr-lifecycle', 'tab' => 'exit-settings'], admin_url('admin.php'));
            $class = ($current_tab === 'exit-settings') ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($tabs['exit-settings']) . '</a>';
        }

        echo '</h2>';
        echo '<div style="margin-top: 20px;"></div>';
    }

    /**
     * Render candidates content (delegated to hiring module)
     */
    private function render_candidates_content(): void {
        $this->render_hiring_styles();
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        Hiring_Admin::instance()->render_candidates_tab_content($action);
    }

    /**
     * Render trainees content (delegated to hiring module)
     */
    private function render_trainees_content(): void {
        $this->render_hiring_styles();
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        Hiring_Admin::instance()->render_trainees_tab_content($action);
    }

    /**
     * Render hiring module styles
     */
    private function render_hiring_styles(): void {
        ?>
        <style>
            .sfs-hr-card { background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:20px; margin-bottom:20px; }
            .sfs-hr-card h3 { margin-top:0; padding-bottom:10px; border-bottom:1px solid #eee; }
            .sfs-hr-form-row { margin-bottom:15px; }
            .sfs-hr-form-row label { display:block; margin-bottom:5px; font-weight:600; }
            .sfs-hr-form-row input[type="text"],
            .sfs-hr-form-row input[type="email"],
            .sfs-hr-form-row input[type="tel"],
            .sfs-hr-form-row input[type="date"],
            .sfs-hr-form-row input[type="number"],
            .sfs-hr-form-row select,
            .sfs-hr-form-row textarea { width:100%; max-width:400px; }
            .sfs-hr-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
            @media (max-width:782px) { .sfs-hr-form-grid { grid-template-columns:1fr; } }
            .sfs-hr-status-badge { display:inline-block; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500; }
            .sfs-hr-status-applied { background:#e3f2fd; color:#1565c0; }
            .sfs-hr-status-screening { background:#fff3e0; color:#ef6c00; }
            .sfs-hr-status-dept_pending { background:#fce4ec; color:#c2185b; }
            .sfs-hr-status-dept_approved { background:#e8f5e9; color:#2e7d32; }
            .sfs-hr-status-gm_pending { background:#f3e5f5; color:#7b1fa2; }
            .sfs-hr-status-gm_approved { background:#e0f2f1; color:#00695c; }
            .sfs-hr-status-hired { background:#c8e6c9; color:#1b5e20; }
            .sfs-hr-status-rejected { background:#ffebee; color:#c62828; }
            .sfs-hr-status-active { background:#e8f5e9; color:#2e7d32; }
            .sfs-hr-status-completed { background:#e3f2fd; color:#1565c0; }
            .sfs-hr-status-converted { background:#fff3e0; color:#ef6c00; }
            .sfs-hr-status-archived { background:#f5f5f5; color:#616161; }
        </style>
        <?php
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
