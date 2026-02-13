<?php
/**
 * Tab Dispatcher — routes frontend tab rendering to the correct Tab class.
 *
 * For existing tabs with dedicated classes (Leave, Loans, Resignation, Settlement),
 * the dispatcher instantiates and calls render(). For tabs rendered inline by the
 * shortcode handler (overview, attendance, documents), the dispatcher returns false
 * so the caller handles them.
 *
 * @package SFS\HR\Frontend
 */

namespace SFS\HR\Frontend;

use SFS\HR\Frontend\Tabs\TabInterface;
use SFS\HR\Frontend\Tabs\LeaveTab;
use SFS\HR\Frontend\Tabs\LoansTab;
use SFS\HR\Frontend\Tabs\ResignationTab;
use SFS\HR\Frontend\Tabs\SettlementTab;
use SFS\HR\Frontend\Tabs\TeamTab;
use SFS\HR\Frontend\Tabs\ApprovalsTab;
use SFS\HR\Frontend\Tabs\TeamAttendanceTab;
use SFS\HR\Frontend\Tabs\DashboardTab;
use SFS\HR\Frontend\Tabs\EmployeesTab;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Tab_Dispatcher {

    /**
     * Map of tab slug → fully-qualified Tab class name.
     *
     * Only tabs with dedicated TabInterface implementations are listed.
     * The overview, attendance, and documents tabs are rendered inline
     * by the shortcode handler and are NOT dispatched here.
     *
     * @var array<string,class-string<TabInterface>>
     */
    private static array $tab_map = [
        // Personal tabs (Phase 1).
        'leave'            => LeaveTab::class,
        'loans'            => LoansTab::class,
        'resignation'      => ResignationTab::class,
        'settlement'       => SettlementTab::class,
        // Team tabs (Phase 3).
        'team'             => TeamTab::class,
        'approvals'        => ApprovalsTab::class,
        'team-attendance'  => TeamAttendanceTab::class,
        // Org tabs (Phase 4).
        'dashboard'        => DashboardTab::class,
        'employees'        => EmployeesTab::class,
    ];

    /**
     * Attempt to render a tab via its dedicated Tab class.
     *
     * @param string $tab     Active tab slug.
     * @param string $role    User's portal role.
     * @param array  $emp     Employee data array.
     * @param int    $emp_id  Employee ID.
     * @param array  $context Contextual flags (is_limited_access, has_settlements, can_self_clock).
     * @return bool True if the tab was rendered, false if the caller should handle it.
     */
    public static function render( string $tab, string $role, array $emp, int $emp_id, array $context = [] ): bool {
        // Tabs handled inline by the shortcode (overview, attendance, documents).
        if ( in_array( $tab, [ 'overview', 'attendance', 'documents' ], true ) ) {
            return false;
        }

        // Access control: leave/loans require non-limited access.
        $is_limited = ! empty( $context['is_limited_access'] );
        if ( in_array( $tab, [ 'leave', 'loans' ], true ) && $is_limited ) {
            return false;
        }

        // Settlement requires existing settlements.
        if ( $tab === 'settlement' && empty( $context['has_settlements'] ) ) {
            return false;
        }

        // Dispatch to the registered Tab class.
        if ( isset( self::$tab_map[ $tab ] ) ) {
            $class = self::$tab_map[ $tab ];
            if ( class_exists( $class ) ) {
                $instance = new $class();
                if ( $instance instanceof TabInterface ) {
                    $instance->render( $emp, $emp_id );
                    return true;
                }
            }
        }

        // Unrecognized tab → caller renders overview as fallback.
        return false;
    }

    /**
     * Register a tab renderer for a slug.
     *
     * Allows modules to register new tab renderers at runtime,
     * enabling additional tabs without modifying this file.
     *
     * @param string $slug  Tab slug.
     * @param string $class Fully-qualified class name implementing TabInterface.
     */
    public static function register( string $slug, string $class ): void {
        self::$tab_map[ $slug ] = $class;
    }
}
