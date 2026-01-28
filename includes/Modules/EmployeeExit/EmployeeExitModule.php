<?php
namespace SFS\HR\Modules\EmployeeExit;

if (!defined('ABSPATH')) { exit; }

// Load admin
require_once __DIR__ . '/Admin/class-employee-exit-admin.php';

// Load existing submodules (they still contain the actual logic)
require_once dirname(__DIR__) . '/Resignation/ResignationModule.php';
require_once dirname(__DIR__) . '/Settlement/SettlementModule.php';

use SFS\HR\Modules\EmployeeExit\Admin\Employee_Exit_Admin;
use SFS\HR\Modules\Resignation\Handlers\Resignation_Handlers;
use SFS\HR\Modules\Resignation\Cron\Resignation_Cron;
use SFS\HR\Modules\Resignation\Frontend\Resignation_Shortcodes;
use SFS\HR\Modules\Settlement\Handlers\Settlement_Handlers;

/**
 * Employee Exit Module
 * Unified module for employee exit workflow: resignations and settlements
 *
 * This module combines the previously separate Resignation and Settlement
 * modules into a single cohesive workflow with tabbed admin interface.
 *
 * Structure:
 * - Admin/              Unified admin page with tabs
 * - ../Resignation/     Resignation-specific logic (Services, Views, Notifications, Cron)
 * - ../Settlement/      Settlement-specific logic (Services, Views, Calculations)
 *
 * Version: 1.0.0
 */
class EmployeeExitModule {

    /**
     * Register all hooks
     */
    public function hooks(): void {
        // Unified admin page
        (new Employee_Exit_Admin())->hooks();

        // Resignation functionality (handlers, cron, frontend)
        (new Resignation_Handlers())->hooks();
        (new Resignation_Cron())->hooks();
        (new Resignation_Shortcodes())->hooks();

        // Settlement functionality (handlers)
        (new Settlement_Handlers())->hooks();

        // Register roles from resignation module
        add_action('init', [$this, 'register_roles_and_caps']);
    }

    /**
     * Register roles and capabilities for employee exit workflow
     */
    public function register_roles_and_caps(): void {
        // Add capability to Administrator role
        $admin_role = get_role('administrator');
        if ($admin_role) {
            $admin_role->add_cap('sfs_hr_resignation_finance_approve');
        }

        // Create Finance Approver role if it doesn't exist
        if (!get_role('sfs_hr_finance_approver')) {
            add_role(
                'sfs_hr_finance_approver',
                __('HR Finance Approver', 'sfs-hr'),
                [
                    'read'                               => true,
                    'sfs_hr_resignation_finance_approve' => true,
                    'sfs_hr.view'                        => true,
                ]
            );
        } else {
            $finance_role = get_role('sfs_hr_finance_approver');
            if ($finance_role && !$finance_role->has_cap('sfs_hr_resignation_finance_approve')) {
                $finance_role->add_cap('sfs_hr_resignation_finance_approve');
            }
        }
    }

    // =========================================================================
    // History Logging Functions
    // =========================================================================

    /**
     * Log an event for a resignation or settlement
     *
     * @param int         $resignation_id Resignation ID (or 0 if settlement-only)
     * @param int         $settlement_id  Settlement ID (or 0 if resignation-only)
     * @param string      $event_type     Type of event (e.g., 'submitted', 'manager_approved', 'rejected')
     * @param array       $meta           Additional metadata to store
     */
    public static function log_event( int $resignation_id, int $settlement_id, string $event_type, array $meta = [] ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_exit_history';

        $wpdb->insert( $table, [
            'resignation_id' => $resignation_id > 0 ? $resignation_id : null,
            'settlement_id'  => $settlement_id > 0 ? $settlement_id : null,
            'created_at'     => current_time( 'mysql' ),
            'user_id'        => get_current_user_id(),
            'event_type'     => $event_type,
            'meta'           => wp_json_encode( $meta ),
        ] );
    }

    /**
     * Log an event for a resignation
     *
     * @param int    $resignation_id Resignation ID
     * @param string $event_type     Type of event
     * @param array  $meta           Additional metadata
     */
    public static function log_resignation_event( int $resignation_id, string $event_type, array $meta = [] ): void {
        self::log_event( $resignation_id, 0, $event_type, $meta );
    }

    /**
     * Log an event for a settlement
     *
     * @param int    $settlement_id Settlement ID
     * @param string $event_type    Type of event
     * @param array  $meta          Additional metadata
     */
    public static function log_settlement_event( int $settlement_id, string $event_type, array $meta = [] ): void {
        self::log_event( 0, $settlement_id, $event_type, $meta );
    }

    /**
     * Get history for a resignation
     *
     * @param int $resignation_id Resignation ID
     * @return array Array of history records
     */
    public static function get_resignation_history( int $resignation_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_exit_history';
        $users_table = $wpdb->users;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT h.*, u.display_name as user_name
             FROM {$table} h
             LEFT JOIN {$users_table} u ON u.ID = h.user_id
             WHERE h.resignation_id = %d
             ORDER BY h.created_at DESC",
            $resignation_id
        ), ARRAY_A );

        return $results ?: [];
    }

    /**
     * Get history for a settlement
     *
     * @param int $settlement_id Settlement ID
     * @return array Array of history records
     */
    public static function get_settlement_history( int $settlement_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_exit_history';
        $users_table = $wpdb->users;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT h.*, u.display_name as user_name
             FROM {$table} h
             LEFT JOIN {$users_table} u ON u.ID = h.user_id
             WHERE h.settlement_id = %d
             ORDER BY h.created_at DESC",
            $settlement_id
        ), ARRAY_A );

        return $results ?: [];
    }

    /**
     * Get combined history for a resignation and its related settlement
     *
     * @param int $resignation_id Resignation ID
     * @param int $settlement_id  Settlement ID (optional)
     * @return array Array of history records
     */
    public static function get_combined_history( int $resignation_id, int $settlement_id = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_exit_history';
        $users_table = $wpdb->users;

        $where = 'h.resignation_id = %d';
        $params = [ $resignation_id ];

        if ( $settlement_id > 0 ) {
            $where .= ' OR h.settlement_id = %d';
            $params[] = $settlement_id;
        }

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT h.*, u.display_name as user_name
             FROM {$table} h
             LEFT JOIN {$users_table} u ON u.ID = h.user_id
             WHERE {$where}
             ORDER BY h.created_at DESC",
            ...$params
        ), ARRAY_A );

        return $results ?: [];
    }
}
