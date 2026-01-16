<?php
namespace SFS\HR\Modules\Settlement;

if (!defined('ABSPATH')) { exit; }

// Load submodules
require_once __DIR__ . '/Services/class-settlement-service.php';
require_once __DIR__ . '/Admin/class-settlement-admin.php';
require_once __DIR__ . '/Admin/Views/class-settlement-list.php';
require_once __DIR__ . '/Admin/Views/class-settlement-form.php';
require_once __DIR__ . '/Admin/Views/class-settlement-view.php';
require_once __DIR__ . '/Handlers/class-settlement-handlers.php';

use SFS\HR\Modules\Settlement\Services\Settlement_Service;
use SFS\HR\Modules\Settlement\Admin\Settlement_Admin;
use SFS\HR\Modules\Settlement\Handlers\Settlement_Handlers;

/**
 * Settlement Module
 * End of service settlement management
 *
 * Structure:
 * - Services/           Business logic and calculations
 * - Admin/              Admin page routing
 * - Admin/Views/        View rendering (list, form, detail)
 * - Handlers/           Form submission handlers
 *
 * Version: 0.2.0
 * Author: Simple HR Suite
 */
class SettlementModule {

    /**
     * Register all hooks
     */
    public function hooks(): void {
        // Initialize submodules
        (new Settlement_Admin())->hooks();
        (new Settlement_Handlers())->hooks();
    }

    // =========================================================================
    // Backwards compatibility - delegate to Services class
    // =========================================================================

    /**
     * @deprecated Use Settlement_Service::get_status_labels() instead
     */
    public static function get_status_labels(): array {
        return Settlement_Service::get_status_labels();
    }

    /**
     * @deprecated Use Settlement_Service::get_status_colors() instead
     */
    public static function get_status_colors(): array {
        return Settlement_Service::get_status_colors();
    }

    /**
     * @deprecated Use Settlement_Service::get_settlement() instead
     */
    public static function get_settlement(int $id): ?array {
        return Settlement_Service::get_settlement($id);
    }

    /**
     * @deprecated Use Settlement_Service::calculate_gratuity() instead
     */
    public static function calculate_gratuity(float $salary, float $years): float {
        return Settlement_Service::calculate_gratuity($salary, $years);
    }

    /**
     * @deprecated Use Settlement_Service::check_loan_clearance() instead
     */
    public static function check_loan_clearance(int $employee_id): array {
        return Settlement_Service::check_loan_clearance($employee_id);
    }

    /**
     * @deprecated Use Settlement_Service::check_asset_clearance() instead
     */
    public static function check_asset_clearance(int $employee_id): array {
        return Settlement_Service::check_asset_clearance($employee_id);
    }
}
