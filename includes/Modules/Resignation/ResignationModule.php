<?php
namespace SFS\HR\Modules\Resignation;

if (!defined('ABSPATH')) { exit; }

// Load submodules
require_once __DIR__ . '/Services/class-resignation-service.php';
require_once __DIR__ . '/Services/class-notice-service.php';
require_once __DIR__ . '/Services/class-offboarding-service.php';
require_once __DIR__ . '/Services/class-exit-interview-service.php';
require_once __DIR__ . '/Admin/class-resignation-admin.php';
require_once __DIR__ . '/Admin/Views/class-resignation-list.php';
require_once __DIR__ . '/Admin/Views/class-resignation-settings.php';
require_once __DIR__ . '/Handlers/class-resignation-handlers.php';
require_once __DIR__ . '/Notifications/class-resignation-notifications.php';
require_once __DIR__ . '/Cron/class-resignation-cron.php';
require_once __DIR__ . '/Frontend/class-resignation-shortcodes.php';
require_once __DIR__ . '/Rest/class-resignation-rest.php';

use SFS\HR\Modules\Resignation\Services\Resignation_Service;
use SFS\HR\Modules\Resignation\Services\Exit_Interview_Service;
use SFS\HR\Modules\Resignation\Admin\Resignation_Admin;
use SFS\HR\Modules\Resignation\Handlers\Resignation_Handlers;
use SFS\HR\Modules\Resignation\Cron\Resignation_Cron;
use SFS\HR\Modules\Resignation\Frontend\Resignation_Shortcodes;
use SFS\HR\Modules\Resignation\Rest\Resignation_REST;

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
 * Version: 1.0.0
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

        // M7: REST API
        Resignation_REST::register();

        // M7: Seed default exit interview questions on first run
        add_action( 'admin_init', [ __CLASS__, 'maybe_seed_exit_questions' ] );

        // M7: Daily cron for offboarding task escalation
        add_action( 'sfs_hr_daily_offboarding_check', [ __CLASS__, 'run_offboarding_check' ] );
        if ( ! wp_next_scheduled( 'sfs_hr_daily_offboarding_check' ) ) {
            wp_schedule_event( time(), 'daily', 'sfs_hr_daily_offboarding_check' );
        }
    }

    /**
     * Seed default exit interview questions if none exist.
     */
    public static function maybe_seed_exit_questions(): void {
        if ( get_option( 'sfs_hr_exit_questions_seeded' ) ) {
            return;
        }
        Exit_Interview_Service::seed_default_questions();
        update_option( 'sfs_hr_exit_questions_seeded', 1 );
    }

    /**
     * Daily cron: check overdue offboarding tasks and send reminders.
     */
    public static function run_offboarding_check(): void {
        $overdue = \SFS\HR\Modules\Resignation\Services\Offboarding_Service::get_overdue_tasks();
        if ( empty( $overdue ) ) {
            return;
        }

        // Group by assigned_to for notification batching
        $by_user = [];
        foreach ( $overdue as $task ) {
            $uid = (int) ( $task['assigned_to'] ?? 0 );
            if ( $uid ) {
                $by_user[ $uid ][] = $task;
            }
        }

        foreach ( $by_user as $user_id => $tasks ) {
            $user = get_userdata( $user_id );
            if ( ! $user || empty( $user->user_email ) ) {
                continue;
            }
            $count   = count( $tasks );
            $subject = sprintf( __( '[HR] You have %d overdue offboarding task(s)', 'sfs-hr' ), $count );
            $body    = sprintf(
                __( "Hello %s,\n\nYou have %d overdue offboarding task(s) that require attention.\nPlease log in to the HR system to review and complete them.\n\nThank you.", 'sfs-hr' ),
                $user->display_name,
                $count
            );
            wp_mail( $user->user_email, $subject, $body );
        }
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
