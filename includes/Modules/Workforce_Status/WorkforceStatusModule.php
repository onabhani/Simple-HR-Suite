<?php
namespace SFS\HR\Modules\Workforce_Status;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WorkforceStatusModule
 * Version: 0.1.0-workforce-v1
 * Author: Omar Alnabhani (hdqah.com)
 *
 * Notes:
 * - Read-only aggregation on top of Attendance + Leave
 * - No schema changes, no writes
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

        // No REST, no cron, no front-end hooks in v1.
    }
}
