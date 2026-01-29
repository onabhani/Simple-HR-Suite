<?php
namespace SFS\HR\Modules\Performance\Rest;

use SFS\HR\Modules\Performance\Services\Attendance_Metrics;
use SFS\HR\Modules\Performance\Services\Goals_Service;
use SFS\HR\Modules\Performance\Services\Reviews_Service;
use SFS\HR\Modules\Performance\Services\Performance_Calculator;
use SFS\HR\Modules\Performance\Services\Alerts_Service;
use SFS\HR\Modules\Performance\PerformanceModule;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Performance REST API
 *
 * Provides REST endpoints for:
 * - Attendance metrics
 * - Goals management
 * - Reviews management
 * - Performance calculations
 * - Alerts
 *
 * @version 1.0.0
 */
class Performance_Rest {

    const NAMESPACE = 'sfs-hr/v1';

    /**
     * Register routes.
     */
    public function register_routes(): void {
        // Attendance Metrics
        register_rest_route( self::NAMESPACE, '/performance/attendance/employee/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_employee_attendance' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
            'args'                => [
                'id'         => [ 'required' => true, 'type' => 'integer' ],
                'start_date' => [ 'type' => 'string', 'format' => 'date' ],
                'end_date'   => [ 'type' => 'string', 'format' => 'date' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/attendance/department/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_department_attendance' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
            'args'                => [
                'id'         => [ 'required' => true, 'type' => 'integer' ],
                'start_date' => [ 'type' => 'string', 'format' => 'date' ],
                'end_date'   => [ 'type' => 'string', 'format' => 'date' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/attendance/summary', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_attendance_summary' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
            'args'                => [
                'start_date' => [ 'type' => 'string', 'format' => 'date' ],
                'end_date'   => [ 'type' => 'string', 'format' => 'date' ],
            ],
        ] );

        // Goals
        register_rest_route( self::NAMESPACE, '/performance/goals', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_goals' ],
                'permission_callback' => [ $this, 'check_read_permission' ],
                'args'                => [
                    'employee_id' => [ 'type' => 'integer' ],
                    'status'      => [ 'type' => 'string' ],
                    'start_date'  => [ 'type' => 'string', 'format' => 'date' ],
                    'end_date'    => [ 'type' => 'string', 'format' => 'date' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_goal' ],
                'permission_callback' => [ $this, 'check_write_permission' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/goals/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_goal' ],
                'permission_callback' => [ $this, 'check_read_permission' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_goal' ],
                'permission_callback' => [ $this, 'check_write_permission' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_goal' ],
                'permission_callback' => [ $this, 'check_write_permission' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/goals/(?P<id>\d+)/progress', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'update_goal_progress' ],
            'permission_callback' => [ $this, 'check_write_permission' ],
            'args'                => [
                'progress' => [ 'required' => true, 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ],
                'note'     => [ 'type' => 'string' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/goals/(?P<id>\d+)/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_goal_history' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
        ] );

        // Reviews
        register_rest_route( self::NAMESPACE, '/performance/reviews', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_reviews' ],
                'permission_callback' => [ $this, 'check_read_permission' ],
                'args'                => [
                    'employee_id' => [ 'type' => 'integer' ],
                    'reviewer_id' => [ 'type' => 'integer' ],
                    'status'      => [ 'type' => 'string' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_review' ],
                'permission_callback' => [ $this, 'check_write_permission' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/reviews/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_review' ],
                'permission_callback' => [ $this, 'check_read_permission' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_review' ],
                'permission_callback' => [ $this, 'check_write_permission' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/reviews/(?P<id>\d+)/submit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'submit_review' ],
            'permission_callback' => [ $this, 'check_write_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/reviews/(?P<id>\d+)/acknowledge', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'acknowledge_review' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
            'args'                => [
                'comments' => [ 'type' => 'string' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/reviews/criteria', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_criteria' ],
                'permission_callback' => [ $this, 'check_read_permission' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_criterion' ],
                'permission_callback' => [ $this, 'check_admin_permission' ],
            ],
        ] );

        // Overall Performance Score
        register_rest_route( self::NAMESPACE, '/performance/score/employee/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_employee_score' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
            'args'                => [
                'start_date' => [ 'type' => 'string', 'format' => 'date' ],
                'end_date'   => [ 'type' => 'string', 'format' => 'date' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/rankings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_rankings' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
            'args'                => [
                'dept_id'    => [ 'type' => 'integer' ],
                'limit'      => [ 'type' => 'integer', 'default' => 10 ],
                'start_date' => [ 'type' => 'string', 'format' => 'date' ],
                'end_date'   => [ 'type' => 'string', 'format' => 'date' ],
            ],
        ] );

        // Alerts
        register_rest_route( self::NAMESPACE, '/performance/alerts', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_alerts' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
            'args'                => [
                'employee_id' => [ 'type' => 'integer' ],
                'status'      => [ 'type' => 'string' ],
                'severity'    => [ 'type' => 'string' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/alerts/(?P<id>\d+)/acknowledge', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'acknowledge_alert' ],
            'permission_callback' => [ $this, 'check_write_permission' ],
            'args'                => [
                'notes' => [ 'type' => 'string' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/alerts/(?P<id>\d+)/resolve', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'resolve_alert' ],
            'permission_callback' => [ $this, 'check_write_permission' ],
            'args'                => [
                'resolution' => [ 'type' => 'string' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/alerts/statistics', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_alert_statistics' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
        ] );

        // Snapshots
        register_rest_route( self::NAMESPACE, '/performance/snapshots/generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_snapshots' ],
            'permission_callback' => [ $this, 'check_admin_permission' ],
            'args'                => [
                'start_date' => [ 'type' => 'string', 'format' => 'date' ],
                'end_date'   => [ 'type' => 'string', 'format' => 'date' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/performance/snapshots/employee/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_employee_snapshots' ],
            'permission_callback' => [ $this, 'check_read_permission' ],
            'args'                => [
                'limit' => [ 'type' => 'integer', 'default' => 12 ],
            ],
        ] );

        // Settings
        register_rest_route( self::NAMESPACE, '/performance/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_settings' ],
                'permission_callback' => [ $this, 'check_admin_permission' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_settings' ],
                'permission_callback' => [ $this, 'check_admin_permission' ],
            ],
        ] );
    }

    // =========================================================================
    // Permission Callbacks
    // =========================================================================

    public function check_read_permission(): bool {
        return current_user_can( 'read' );
    }

    public function check_write_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    public function check_admin_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    // =========================================================================
    // Attendance Endpoints
    // =========================================================================

    public function get_employee_attendance( \WP_REST_Request $request ): \WP_REST_Response {
        $employee_id = (int) $request->get_param( 'id' );
        $start_date  = $request->get_param( 'start_date' ) ?: '';
        $end_date    = $request->get_param( 'end_date' ) ?: '';

        $metrics = Attendance_Metrics::get_employee_metrics( $employee_id, $start_date, $end_date );

        if ( isset( $metrics['error'] ) ) {
            return new \WP_REST_Response( $metrics, 404 );
        }

        return new \WP_REST_Response( $metrics, 200 );
    }

    public function get_department_attendance( \WP_REST_Request $request ): \WP_REST_Response {
        $dept_id    = (int) $request->get_param( 'id' );
        $start_date = $request->get_param( 'start_date' ) ?: '';
        $end_date   = $request->get_param( 'end_date' ) ?: '';

        $metrics = Attendance_Metrics::get_department_metrics( $dept_id, $start_date, $end_date );

        return new \WP_REST_Response( $metrics, 200 );
    }

    public function get_attendance_summary( \WP_REST_Request $request ): \WP_REST_Response {
        $start_date = $request->get_param( 'start_date' ) ?: '';
        $end_date   = $request->get_param( 'end_date' ) ?: '';

        $summary = Attendance_Metrics::get_all_departments_summary( $start_date, $end_date );

        return new \WP_REST_Response( $summary, 200 );
    }

    // =========================================================================
    // Goals Endpoints
    // =========================================================================

    public function get_goals( \WP_REST_Request $request ): \WP_REST_Response {
        $employee_id = $request->get_param( 'employee_id' );
        $status      = $request->get_param( 'status' ) ?: '';
        $start_date  = $request->get_param( 'start_date' ) ?: '';
        $end_date    = $request->get_param( 'end_date' ) ?: '';

        if ( ! $employee_id ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => __( 'Employee ID is required', 'sfs-hr' ),
            ], 400 );
        }

        $goals = Goals_Service::get_employee_goals( (int) $employee_id, $status, $start_date, $end_date );

        return new \WP_REST_Response( $goals, 200 );
    }

    public function get_goal( \WP_REST_Request $request ): \WP_REST_Response {
        $goal_id = (int) $request->get_param( 'id' );
        $goal    = Goals_Service::get_goal( $goal_id );

        if ( ! $goal ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => __( 'Goal not found', 'sfs-hr' ),
            ], 404 );
        }

        return new \WP_REST_Response( $goal, 200 );
    }

    public function create_goal( \WP_REST_Request $request ): \WP_REST_Response {
        $data   = $request->get_json_params();
        $result = Goals_Service::save_goal( $data );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        $goal = Goals_Service::get_goal( $result );

        return new \WP_REST_Response( $goal, 201 );
    }

    public function update_goal( \WP_REST_Request $request ): \WP_REST_Response {
        $goal_id = (int) $request->get_param( 'id' );
        $data    = $request->get_json_params();
        $data['id'] = $goal_id;

        $result = Goals_Service::save_goal( $data );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        $goal = Goals_Service::get_goal( $result );

        return new \WP_REST_Response( $goal, 200 );
    }

    public function delete_goal( \WP_REST_Request $request ): \WP_REST_Response {
        $goal_id = (int) $request->get_param( 'id' );
        $deleted = Goals_Service::delete_goal( $goal_id );

        if ( ! $deleted ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => __( 'Failed to delete goal', 'sfs-hr' ),
            ], 500 );
        }

        return new \WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    public function update_goal_progress( \WP_REST_Request $request ): \WP_REST_Response {
        $goal_id  = (int) $request->get_param( 'id' );
        $progress = (int) $request->get_param( 'progress' );
        $note     = $request->get_param( 'note' ) ?: '';

        $result = Goals_Service::update_progress( $goal_id, $progress, $note );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        $goal = Goals_Service::get_goal( $goal_id );

        return new \WP_REST_Response( $goal, 200 );
    }

    public function get_goal_history( \WP_REST_Request $request ): \WP_REST_Response {
        $goal_id = (int) $request->get_param( 'id' );
        $history = Goals_Service::get_goal_history( $goal_id );

        return new \WP_REST_Response( $history, 200 );
    }

    // =========================================================================
    // Reviews Endpoints
    // =========================================================================

    public function get_reviews( \WP_REST_Request $request ): \WP_REST_Response {
        $employee_id = $request->get_param( 'employee_id' );
        $reviewer_id = $request->get_param( 'reviewer_id' );
        $status      = $request->get_param( 'status' ) ?: '';

        if ( $employee_id ) {
            $reviews = Reviews_Service::get_employee_reviews( (int) $employee_id, $status );
        } elseif ( $reviewer_id ) {
            $reviews = Reviews_Service::get_pending_reviews( (int) $reviewer_id );
        } else {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => __( 'Employee ID or Reviewer ID is required', 'sfs-hr' ),
            ], 400 );
        }

        return new \WP_REST_Response( $reviews, 200 );
    }

    public function get_review( \WP_REST_Request $request ): \WP_REST_Response {
        $review_id = (int) $request->get_param( 'id' );
        $review    = Reviews_Service::get_review( $review_id );

        if ( ! $review ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => __( 'Review not found', 'sfs-hr' ),
            ], 404 );
        }

        return new \WP_REST_Response( $review, 200 );
    }

    public function create_review( \WP_REST_Request $request ): \WP_REST_Response {
        $data   = $request->get_json_params();
        $result = Reviews_Service::save_review( $data );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        $review = Reviews_Service::get_review( $result );

        return new \WP_REST_Response( $review, 201 );
    }

    public function update_review( \WP_REST_Request $request ): \WP_REST_Response {
        $review_id = (int) $request->get_param( 'id' );
        $data      = $request->get_json_params();
        $data['id'] = $review_id;

        $result = Reviews_Service::save_review( $data );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        $review = Reviews_Service::get_review( $result );

        return new \WP_REST_Response( $review, 200 );
    }

    public function submit_review( \WP_REST_Request $request ): \WP_REST_Response {
        $review_id = (int) $request->get_param( 'id' );
        $result    = Reviews_Service::submit_review( $review_id );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        $review = Reviews_Service::get_review( $review_id );

        return new \WP_REST_Response( $review, 200 );
    }

    public function acknowledge_review( \WP_REST_Request $request ): \WP_REST_Response {
        $review_id = (int) $request->get_param( 'id' );
        $comments  = $request->get_param( 'comments' ) ?: '';

        // Get employee ID from current user
        global $wpdb;
        $employee_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d",
            get_current_user_id()
        ) );

        if ( ! $employee_id ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => __( 'Employee record not found', 'sfs-hr' ),
            ], 403 );
        }

        $result = Reviews_Service::acknowledge_review( $review_id, $employee_id, $comments );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        $review = Reviews_Service::get_review( $review_id );

        return new \WP_REST_Response( $review, 200 );
    }

    public function get_criteria( \WP_REST_Request $request ): \WP_REST_Response {
        $criteria = Reviews_Service::get_criteria( false );

        return new \WP_REST_Response( $criteria, 200 );
    }

    public function save_criterion( \WP_REST_Request $request ): \WP_REST_Response {
        $data   = $request->get_json_params();
        $result = Reviews_Service::save_criterion( $data );

        return new \WP_REST_Response( [ 'id' => $result ], 200 );
    }

    // =========================================================================
    // Performance Score Endpoints
    // =========================================================================

    public function get_employee_score( \WP_REST_Request $request ): \WP_REST_Response {
        $employee_id = (int) $request->get_param( 'id' );
        $start_date  = $request->get_param( 'start_date' ) ?: '';
        $end_date    = $request->get_param( 'end_date' ) ?: '';

        $score = Performance_Calculator::calculate_overall_score( $employee_id, $start_date, $end_date );

        return new \WP_REST_Response( $score, 200 );
    }

    public function get_rankings( \WP_REST_Request $request ): \WP_REST_Response {
        $dept_id    = (int) $request->get_param( 'dept_id' );
        $limit      = (int) $request->get_param( 'limit' ) ?: 10;
        $start_date = $request->get_param( 'start_date' ) ?: '';
        $end_date   = $request->get_param( 'end_date' ) ?: '';

        $rankings = Performance_Calculator::get_rankings( $dept_id, $limit, $start_date, $end_date );

        return new \WP_REST_Response( $rankings, 200 );
    }

    // =========================================================================
    // Alerts Endpoints
    // =========================================================================

    public function get_alerts( \WP_REST_Request $request ): \WP_REST_Response {
        $employee_id = (int) $request->get_param( 'employee_id' );
        $status      = $request->get_param( 'status' ) ?: '';
        $severity    = $request->get_param( 'severity' ) ?: '';

        $alerts = Alerts_Service::get_alerts( $employee_id, $status, $severity );

        return new \WP_REST_Response( $alerts, 200 );
    }

    public function acknowledge_alert( \WP_REST_Request $request ): \WP_REST_Response {
        $alert_id = (int) $request->get_param( 'id' );
        $notes    = $request->get_param( 'notes' ) ?: '';

        $result = Alerts_Service::acknowledge_alert( $alert_id, $notes );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return new \WP_REST_Response( [ 'acknowledged' => true ], 200 );
    }

    public function resolve_alert( \WP_REST_Request $request ): \WP_REST_Response {
        $alert_id   = (int) $request->get_param( 'id' );
        $resolution = $request->get_param( 'resolution' ) ?: '';

        $result = Alerts_Service::resolve_alert( $alert_id, $resolution );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( [
                'error'   => true,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return new \WP_REST_Response( [ 'resolved' => true ], 200 );
    }

    public function get_alert_statistics( \WP_REST_Request $request ): \WP_REST_Response {
        $stats = Alerts_Service::get_statistics();

        return new \WP_REST_Response( $stats, 200 );
    }

    // =========================================================================
    // Snapshots Endpoints
    // =========================================================================

    public function generate_snapshots( \WP_REST_Request $request ): \WP_REST_Response {
        $start_date = $request->get_param( 'start_date' ) ?: '';
        $end_date   = $request->get_param( 'end_date' ) ?: '';

        $count = \SFS\HR\Modules\Performance\Cron\Performance_Cron::trigger_snapshots( $start_date, $end_date );

        return new \WP_REST_Response( [
            'generated' => $count,
            'period'    => [
                'start' => $start_date ?: date( 'Y-m-01', strtotime( 'first day of last month' ) ),
                'end'   => $end_date ?: date( 'Y-m-t', strtotime( 'last day of last month' ) ),
            ],
        ], 200 );
    }

    public function get_employee_snapshots( \WP_REST_Request $request ): \WP_REST_Response {
        $employee_id = (int) $request->get_param( 'id' );
        $limit       = (int) $request->get_param( 'limit' ) ?: 12;

        $snapshots = Attendance_Metrics::get_employee_history( $employee_id, $limit );

        return new \WP_REST_Response( $snapshots, 200 );
    }

    // =========================================================================
    // Settings Endpoints
    // =========================================================================

    public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $settings = PerformanceModule::get_settings();

        return new \WP_REST_Response( $settings, 200 );
    }

    public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $data = $request->get_json_params();
        PerformanceModule::save_settings( $data );
        $settings = PerformanceModule::get_settings();

        return new \WP_REST_Response( $settings, 200 );
    }
}
