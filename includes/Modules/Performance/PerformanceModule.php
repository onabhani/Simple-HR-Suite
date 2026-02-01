<?php
namespace SFS\HR\Modules\Performance;

use SFS\HR\Modules\Performance\Cron\Performance_Cron;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * PerformanceModule
 *
 * Comprehensive employee performance management including:
 * - Attendance commitment metrics
 * - Goals and OKRs tracking
 * - Performance reviews
 * - Weighted performance scoring
 * - Threshold alerts
 *
 * @version 1.0.0
 * @author hdqah.com
 */
class PerformanceModule {

    /**
     * Option key for module settings.
     */
    const OPT_SETTINGS = 'sfs_hr_performance_settings';

    /**
     * Register all hooks.
     */
    public function hooks(): void {
        // Admin pages
        add_action( 'init', function () {
            if ( is_admin() ) {
                ( new Admin\Admin_Pages() )->hooks();
            }
        } );

        // REST API
        add_action( 'rest_api_init', function () {
            ( new Rest\Performance_Rest() )->register_routes();
        } );

        // Cron jobs for alerts and snapshots
        ( new Performance_Cron() )->hooks();

        // AJAX handlers
        add_action( 'wp_ajax_sfs_hr_get_employee_metrics', [ $this, 'ajax_get_employee_metrics' ] );
        add_action( 'wp_ajax_sfs_hr_get_department_metrics', [ $this, 'ajax_get_department_metrics' ] );
        add_action( 'wp_ajax_sfs_hr_save_goal', [ $this, 'ajax_save_goal' ] );
        add_action( 'wp_ajax_sfs_hr_update_goal_progress', [ $this, 'ajax_update_goal_progress' ] );
        add_action( 'wp_ajax_sfs_hr_save_review', [ $this, 'ajax_save_review' ] );
    }

    /**
     * Get module settings with defaults.
     *
     * @return array
     */
    public static function get_settings(): array {
        $defaults = [
            'enabled' => true,
            // Weights for overall performance score (must sum to 100)
            'weights' => [
                'attendance'  => 40,
                'goals'       => 35,
                'reviews'     => 25,
            ],
            // Attendance commitment thresholds
            'attendance_thresholds' => [
                'excellent' => 95,  // >= 95%
                'good'      => 85,  // >= 85%
                'fair'      => 70,  // >= 70%
                'poor'      => 0,   // < 70%
            ],
            // Alert settings
            'alerts' => [
                'enabled'              => true,
                'commitment_threshold' => 80, // Alert when below 80%
                'notify_manager'       => true,
                'notify_hr'            => true,
                'notify_employee'      => false,
            ],
            // Review settings
            'reviews' => [
                'cycle'           => 'quarterly', // quarterly, semi-annual, annual
                'self_review'     => true,
                'manager_review'  => true,
                'peer_review'     => false,
                'reminder_days'   => 7, // Days before due date
            ],
            // Snapshot settings
            'snapshots' => [
                'auto_generate'  => true,
                'frequency'      => 'monthly', // weekly, monthly
                'retention_days' => 365,
            ],
        ];

        $saved = get_option( self::OPT_SETTINGS, [] );
        return wp_parse_args( $saved, $defaults );
    }

    /**
     * Update module settings.
     *
     * @param array $settings
     */
    public static function update_settings( array $settings ): void {
        update_option( self::OPT_SETTINGS, $settings );
    }

    /**
     * AJAX: Get employee metrics.
     */
    public function ajax_get_employee_metrics(): void {
        check_ajax_referer( 'sfs_hr_performance', 'nonce' );

        if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'sfs_hr.attendance.view' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'sfs-hr' ) ] );
        }

        $employee_id = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;
        $start_date  = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : '';
        $end_date    = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : '';

        if ( ! $employee_id ) {
            wp_send_json_error( [ 'message' => __( 'Employee ID required', 'sfs-hr' ) ] );
        }

        $metrics = Services\Attendance_Metrics::get_employee_metrics( $employee_id, $start_date, $end_date );
        wp_send_json_success( $metrics );
    }

    /**
     * AJAX: Get department metrics.
     */
    public function ajax_get_department_metrics(): void {
        check_ajax_referer( 'sfs_hr_performance', 'nonce' );

        if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'sfs_hr.attendance.view' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'sfs-hr' ) ] );
        }

        $dept_id    = isset( $_POST['dept_id'] ) ? (int) $_POST['dept_id'] : 0;
        $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : '';
        $end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : '';

        $metrics = Services\Attendance_Metrics::get_department_metrics( $dept_id, $start_date, $end_date );
        wp_send_json_success( $metrics );
    }

    /**
     * AJAX: Save goal.
     */
    public function ajax_save_goal(): void {
        check_ajax_referer( 'sfs_hr_performance', 'nonce' );

        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'sfs-hr' ) ] );
        }

        $data = [
            'id'          => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
            'employee_id' => isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0,
            'title'       => isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '',
            'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '',
            'target_date' => isset( $_POST['target_date'] ) ? sanitize_text_field( $_POST['target_date'] ) : '',
            'weight'      => isset( $_POST['weight'] ) ? (int) $_POST['weight'] : 100,
            'status'      => isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : 'active',
        ];

        $result = Services\Goals_Service::save_goal( $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'goal_id' => $result ] );
    }

    /**
     * AJAX: Update goal progress.
     */
    public function ajax_update_goal_progress(): void {
        check_ajax_referer( 'sfs_hr_performance', 'nonce' );

        $goal_id  = isset( $_POST['goal_id'] ) ? (int) $_POST['goal_id'] : 0;
        $progress = isset( $_POST['progress'] ) ? (int) $_POST['progress'] : 0;

        if ( ! $goal_id ) {
            wp_send_json_error( [ 'message' => __( 'Goal ID required', 'sfs-hr' ) ] );
        }

        $result = Services\Goals_Service::update_progress( $goal_id, $progress );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'updated' => true ] );
    }

    /**
     * AJAX: Save review.
     */
    public function ajax_save_review(): void {
        check_ajax_referer( 'sfs_hr_performance', 'nonce' );

        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'sfs-hr' ) ] );
        }

        $data = [
            'id'           => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
            'employee_id'  => isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0,
            'reviewer_id'  => get_current_user_id(),
            'review_type'  => isset( $_POST['review_type'] ) ? sanitize_text_field( $_POST['review_type'] ) : 'manager',
            'period_start' => isset( $_POST['period_start'] ) ? sanitize_text_field( $_POST['period_start'] ) : '',
            'period_end'   => isset( $_POST['period_end'] ) ? sanitize_text_field( $_POST['period_end'] ) : '',
            'ratings'      => isset( $_POST['ratings'] ) ? array_map( 'intval', (array) $_POST['ratings'] ) : [],
            'comments'     => isset( $_POST['comments'] ) ? sanitize_textarea_field( $_POST['comments'] ) : '',
            'status'       => isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : 'draft',
        ];

        $result = Services\Reviews_Service::save_review( $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'review_id' => $result ] );
    }
}
