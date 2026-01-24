<?php
namespace SFS\HR\Modules\Resignation\Admin;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_List;
use SFS\HR\Modules\Resignation\Admin\Views\Resignation_Settings;

if (!defined('ABSPATH')) { exit; }

/**
 * Resignation Admin
 * @deprecated Use \SFS\HR\Modules\EmployeeExit\Admin\Employee_Exit_Admin instead
 * Kept for backwards compatibility - menu registration moved to EmployeeExitModule
 */
class Resignation_Admin {

    /**
     * Register hooks
     * @deprecated Menu registration is now handled by EmployeeExitModule
     */
    public function hooks(): void {
        // Menu registration moved to EmployeeExit module
        // Roles registration moved to EmployeeExit module
    }
}
