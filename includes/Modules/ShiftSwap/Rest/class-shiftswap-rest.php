<?php
namespace SFS\HR\Modules\ShiftSwap\Rest;

use SFS\HR\Modules\ShiftSwap\Services\ShiftSwap_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Shift Swap REST API
 * REST endpoints for shift swap operations
 */
class ShiftSwap_Rest {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route('sfs-hr/v1', '/shift-swaps', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_swaps'],
            'permission_callback' => [$this, 'check_manager_permission'],
        ]);

        register_rest_route('sfs-hr/v1', '/shift-swaps/pending-count', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_pending_count'],
            'permission_callback' => [$this, 'check_manager_permission'],
        ]);
    }

    /**
     * Check manager permission
     */
    public function check_manager_permission(): bool {
        return current_user_can('sfs_hr.manage') || current_user_can('sfs_hr_attendance_admin');
    }

    /**
     * Get pending swap requests for managers
     */
    public function get_swaps(\WP_REST_Request $request): \WP_REST_Response {
        $swaps = ShiftSwap_Service::get_pending_for_managers();
        return new \WP_REST_Response($swaps, 200);
    }

    /**
     * Get pending swap count
     */
    public function get_pending_count(\WP_REST_Request $request): \WP_REST_Response {
        $count = ShiftSwap_Service::get_pending_count();
        return new \WP_REST_Response(['count' => $count], 200);
    }
}
