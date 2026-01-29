<?php
namespace SFS\HR\Modules\Workforce_Status;

use SFS\HR\Modules\Workforce_Status\Cron\Absent_Cron;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WorkforceStatusModule
 * Version: 0.2.0-workforce-v2
 * Author: Omar Alnabhani (hdqah.com)
 *
 * Notes:
 * - Read-only aggregation on top of Attendance + Leave
 * - Absent employee notifications to department managers
 */
class WorkforceStatusModule {

    public function hooks(): void {

        // Keep admin pages bootstrapped on init (same pattern as Attendance)
        add_action( 'init', function () {
            if ( is_admin() ) {
                // Autoloader will resolve this to:
                // includes/Modules/Workforce_Status/Admin/Admin_Pages.php
                ( new \SFS\HR\Modules\Workforce_Status\Admin\Admin_Pages() )->hooks();
            }
        } );

        // Register absent notification cron
        ( new Absent_Cron() )->hooks();
    }
}
