<?php
namespace SFS\HR\Modules\Employees;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * EmployeesModule
 * Version: 0.1.0-profile-routing
 * Only responsible for employee-related extra screens (e.g. Employee Profile).
 */
use SFS\HR\Modules\Employees\Admin\Employee_Profile_Page;
use SFS\HR\Modules\Employees\Admin\My_Profile_Page;

class EmployeesModule {
    public function hooks(): void {
        if ( is_admin() ) {
            require_once __DIR__ . '/Admin/class-employee-profile-page.php';
            require_once __DIR__ . '/Admin/class-my-profile-page.php';

            ( new Employee_Profile_Page() )->hooks();
            ( new My_Profile_Page() )->hooks();
        }
    }
}