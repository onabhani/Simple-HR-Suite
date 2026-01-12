<?php
namespace SFS\HR\Modules\Settlement\Admin;

use SFS\HR\Modules\Settlement\Admin\Views\Settlement_List;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_Form;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_View;

if (!defined('ABSPATH')) { exit; }

/**
 * Settlement Admin
 * Admin page routing for settlement management
 */
class Settlement_Admin {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_menu', [$this, 'register_menu']);
    }

    /**
     * Register admin menu
     */
    public function register_menu(): void {
        add_submenu_page(
            'sfs-hr',
            __('Settlements', 'sfs-hr'),
            __('Settlements', 'sfs-hr'),
            'sfs_hr.manage',
            'sfs-hr-settlements',
            [$this, 'render_page']
        );
    }

    /**
     * Render admin page - routes to appropriate view
     */
    public function render_page(): void {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        $settlement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        switch ($action) {
            case 'create':
                Settlement_Form::render();
                break;

            case 'edit':
                if ($settlement_id) {
                    // Could add Settlement_Edit_Form::render($settlement_id) for edit
                    Settlement_View::render($settlement_id);
                } else {
                    Settlement_List::render();
                }
                break;

            case 'view':
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
