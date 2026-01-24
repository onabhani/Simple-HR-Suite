<?php
namespace SFS\HR\Modules\Settlement\Admin;

use SFS\HR\Modules\Settlement\Admin\Views\Settlement_List;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_Form;
use SFS\HR\Modules\Settlement\Admin\Views\Settlement_View;

if (!defined('ABSPATH')) { exit; }

/**
 * Settlement Admin
 * @deprecated Use \SFS\HR\Modules\EmployeeExit\Admin\Employee_Exit_Admin instead
 * Kept for backwards compatibility - menu registration moved to EmployeeExitModule
 */
class Settlement_Admin {

    /**
     * Register hooks
     * @deprecated Menu registration is now handled by EmployeeExitModule
     */
    public function hooks(): void {
        // Menu registration moved to EmployeeExit module
    }
}
