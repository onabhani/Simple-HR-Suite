<?php
namespace SFS\HR\Modules\Resignation;

if (!defined('ABSPATH')) { exit; }

// Load submodules
require_once __DIR__ . '/Services/class-resignation-service.php';
require_once __DIR__ . '/Admin/class-resignation-admin.php';
require_once __DIR__ . '/Admin/Views/class-resignation-list.php';
require_once __DIR__ . '/Admin/Views/class-resignation-settings.php';
require_once __DIR__ . '/Handlers/class-resignation-handlers.php';
require_once __DIR__ . '/Notifications/class-resignation-notifications.php';
require_once __DIR__ . '/Cron/class-resignation-cron.php';
require_once __DIR__ . '/Frontend/class-resignation-shortcodes.php';

use SFS\HR\Modules\Resignation\Services\Resignation_Service;
use SFS\HR\Modules\Resignation\Admin\Resignation_Admin;
use SFS\HR\Modules\Resignation\Handlers\Resignation_Handlers;
use SFS\HR\Modules\Resignation\Cron\Resignation_Cron;
use SFS\HR\Modules\Resignation\Frontend\Resignation_Shortcodes;

/**
 * Resignation Module
 * Employee resignation management with multi-level approval
 *
 * Structure:
 * - Services/           Business logic and status helpers
 * - Admin/              Admin page routing
 * - Admin/Views/        View rendering (list, settings)
 * - Handlers/           Form submission and AJAX handlers
 * - Notifications/      Email notifications
 * - Cron/               Daily termination processing
 * - Frontend/           Employee self-service shortcodes
 *
 * Version: 0.2.0
 * Author: Simple HR Suite
 */
class ResignationModule {

    /**
     * Register all hooks
     */
    public function hooks(): void {
        // Initialize submodules
        (new Resignation_Admin())->hooks();
        (new Resignation_Handlers())->hooks();
        (new Resignation_Cron())->hooks();
        (new Resignation_Shortcodes())->hooks();
    }

    // =========================================================================
    // Backwards compatibility - delegate to Services class
    // =========================================================================

    /**
     * @deprecated Use Resignation_Service::get_status_tabs() instead
     */
    public static function get_status_tabs(): array {
        return Resignation_Service::get_status_tabs();
    }

    /**
     * @deprecated Use Resignation_Service::get_resignation() instead
     */
    public static function get_resignation(int $id): ?array {
        return Resignation_Service::get_resignation($id);
    }

    /**
     * @deprecated Use Resignation_Service::can_approve_resignation() instead
     */
    public static function can_approve_resignation(array $resignation): bool {
        return Resignation_Service::can_approve_resignation($resignation);
    }

    /**
     * @deprecated Use Resignation_Service::status_badge() instead
     */
    public static function status_badge(string $status, int $level = 1): string {
        return Resignation_Service::status_badge($status, $level);
    }

    /**
     * @deprecated Use Resignation_Service::render_status_pill() instead
     */
    public static function render_status_pill(string $status, int $level = 1): string {
        return Resignation_Service::render_status_pill($status, $level);
    }

    /**
     * @deprecated Use Resignation_Service::get_manager_dept_ids() instead
     */
    public static function manager_dept_ids_for_user(int $user_id): array {
        return Resignation_Service::get_manager_dept_ids($user_id);
    }
}
