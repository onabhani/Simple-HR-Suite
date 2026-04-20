<?php
namespace SFS\HR\Modules\Expenses;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ExpensesModule
 *
 * M10 — Employee expense claims, cash advances, approval workflow,
 * and reporting.
 *
 * Tables owned (created by Install\Migrations::run()):
 *   - sfs_hr_expense_categories
 *   - sfs_hr_expense_claims
 *   - sfs_hr_expense_items
 *   - sfs_hr_expense_advances
 *   - sfs_hr_expense_approvals
 *
 * Payroll integration:
 *   Outstanding advances can be auto-deducted from payroll runs by
 *   subscribing `Advance_Service::payroll_filter` to the payroll engine's
 *   `sfs_hr_payroll_extra_deductions` filter.
 *
 * @since M10
 */
class ExpensesModule {

    public function hooks(): void {
        // REST endpoints
        \SFS\HR\Modules\Expenses\Rest\Expenses_Rest::register();

        // Frontend shortcode — employee expense portal
        require_once __DIR__ . '/Frontend/Expense_Shortcode.php';
        add_shortcode( 'sfs_hr_expenses', [ \SFS\HR\Modules\Expenses\Frontend\Expense_Shortcode::class, 'render' ] );

        // Admin pages
        if ( is_admin() ) {
            require_once __DIR__ . '/Admin/Expense_Admin_Page.php';
            ( new Admin\Expense_Admin_Page() )->hooks();
        }

        // Admin-post form handlers (create claim from frontend)
        require_once __DIR__ . '/Handlers/Expense_Handlers.php';
        ( new Handlers\Expense_Handlers() )->hooks();

        // Payroll integration — subscribes to a documented filter.
        add_filter( 'sfs_hr_payroll_extra_deductions', [ \SFS\HR\Modules\Expenses\Services\Advance_Service::class, 'payroll_filter' ], 10, 2 );
    }

    /**
     * Called on plugin activation + admin_init self-heal.
     */
    public static function install(): void {
        \SFS\HR\Modules\Expenses\Services\Expense_Category_Service::seed_defaults();
    }
}
