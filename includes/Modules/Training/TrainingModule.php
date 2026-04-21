<?php
namespace SFS\HR\Modules\Training;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * TrainingModule
 *
 * M11 — Training programs, sessions, enrollments, certification tracking,
 * and role-based compliance reporting.
 *
 * Tables owned (created by Install\Migrations::run()):
 *   - sfs_hr_training_programs
 *   - sfs_hr_training_sessions
 *   - sfs_hr_training_enrollments
 *   - sfs_hr_training_requests
 *   - sfs_hr_training_certifications
 *   - sfs_hr_training_cert_requirements
 *
 * @since M11
 */
class TrainingModule {

    public function hooks(): void {
        // REST endpoints
        Rest\Training_Rest::register();

        // Admin pages
        if ( is_admin() ) {
            require_once __DIR__ . '/Admin/Training_Admin_Page.php';
            ( new Admin\Training_Admin_Page() )->hooks();
        }

        // Daily cron: expire certifications
        add_action( 'sfs_hr_daily_cron', [ $this, 'cron_expire_certifications' ] );

        if ( ! wp_next_scheduled( 'sfs_hr_training_cert_expiry_check' ) ) {
            wp_schedule_event( time(), 'daily', 'sfs_hr_training_cert_expiry_check' );
        }
        add_action( 'sfs_hr_training_cert_expiry_check', [ $this, 'cron_expire_certifications' ] );
    }

    public function cron_expire_certifications(): void {
        Services\Certification_Service::get_expired();
    }
}
