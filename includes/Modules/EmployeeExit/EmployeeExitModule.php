<?php
namespace SFS\HR\Modules\EmployeeExit;

if (!defined('ABSPATH')) { exit; }

// Load admin
require_once __DIR__ . '/Admin/class-employee-exit-admin.php';

// Load existing submodules (they still contain the actual logic)
require_once dirname(__DIR__) . '/Resignation/ResignationModule.php';
require_once dirname(__DIR__) . '/Settlement/SettlementModule.php';

use SFS\HR\Modules\EmployeeExit\Admin\Employee_Exit_Admin;
use SFS\HR\Modules\Resignation\Handlers\Resignation_Handlers;
use SFS\HR\Modules\Resignation\Cron\Resignation_Cron;
use SFS\HR\Modules\Resignation\Frontend\Resignation_Shortcodes;
use SFS\HR\Modules\Settlement\Handlers\Settlement_Handlers;

/**
 * Employee Exit Module
 * Unified module for employee exit workflow: resignations and settlements
 *
 * This module combines the previously separate Resignation and Settlement
 * modules into a single cohesive workflow with tabbed admin interface.
 *
 * Structure:
 * - Admin/              Unified admin page with tabs
 * - ../Resignation/     Resignation-specific logic (Services, Views, Notifications, Cron)
 * - ../Settlement/      Settlement-specific logic (Services, Views, Calculations)
 *
 * Version: 1.0.0
 */
class EmployeeExitModule {

    /**
     * Register all hooks
     */
    public function hooks(): void {
        // Unified admin page
        (new Employee_Exit_Admin())->hooks();

        // Resignation functionality (handlers, cron, frontend)
        (new Resignation_Handlers())->hooks();
        (new Resignation_Cron())->hooks();
        (new Resignation_Shortcodes())->hooks();

        // Settlement functionality (handlers)
        (new Settlement_Handlers())->hooks();

        // Register roles from resignation module
        add_action('init', [$this, 'register_roles_and_caps']);
    }

    /**
     * Register roles and capabilities for employee exit workflow
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
}
