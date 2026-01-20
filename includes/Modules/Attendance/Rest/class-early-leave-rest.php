<?php
/**
 * Early Leave Request REST API
 *
 * Handles employee early leave requests and manager approvals.
 *
 * @package SFS\HR\Modules\Attendance\Rest
 */

namespace SFS\HR\Modules\Attendance\Rest;

use SFS\HR\Modules\Attendance\AttendanceModule;

defined( 'ABSPATH' ) || exit;

class Early_Leave_Rest {

    public static function register_routes(): void {
        $ns = 'sfs-hr/v1';

        // Employee endpoints
        register_rest_route( $ns, '/early-leave/request', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'create_request' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_attendance_clock_self' ),
        ] );

        register_rest_route( $ns, '/early-leave/my-requests', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'my_requests' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_attendance_view_self' ),
        ] );

        register_rest_route( $ns, '/early-leave/cancel/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'cancel_request' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_attendance_clock_self' ),
        ] );

        // Manager endpoints
        register_rest_route( $ns, '/early-leave/pending', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'pending_requests' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_attendance_view_team' ) || current_user_can( 'sfs_hr.leave.review' ),
        ] );

        register_rest_route( $ns, '/early-leave/review/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'review_request' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_attendance_edit_team' ) || current_user_can( 'sfs_hr.leave.review' ),
        ] );

        // Admin endpoints
        register_rest_route( $ns, '/early-leave/list', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'list_all' ],
            'permission_callback' => fn() => current_user_can( 'sfs_hr_attendance_admin' ),
        ] );
    }

    /**
     * Employee creates an early leave request
     */
    public static function create_request( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $user_id = get_current_user_id();
        $emp_id  = AttendanceModule::employee_id_from_user( $user_id );

        if ( ! $emp_id ) {
            return new \WP_Error( 'not_employee', __( 'You are not registered as an employee.', 'sfs-hr' ), [ 'status' => 403 ] );
        }

        $request_date         = sanitize_text_field( $req['request_date'] ?? wp_date( 'Y-m-d' ) );
        $requested_leave_time = sanitize_text_field( $req['requested_leave_time'] ?? '' );
        $reason_type          = sanitize_key( $req['reason_type'] ?? 'other' );
        $reason_note          = sanitize_textarea_field( $req['reason_note'] ?? '' );

        // Validate
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $request_date ) ) {
            return new \WP_Error( 'invalid_date', __( 'Invalid date format.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        if ( ! preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $requested_leave_time ) ) {
            return new \WP_Error( 'invalid_time', __( 'Invalid time format.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        $valid_reasons = [ 'sick', 'external_task', 'personal', 'emergency', 'other' ];
        if ( ! in_array( $reason_type, $valid_reasons, true ) ) {
            $reason_type = 'other';
        }

        // Check for existing pending request for same date
        $table = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE employee_id = %d AND request_date = %s AND status = 'pending'",
            $emp_id,
            $request_date
        ) );

        if ( $existing ) {
            return new \WP_Error( 'duplicate', __( 'You already have a pending early leave request for this date.', 'sfs-hr' ), [ 'status' => 409 ] );
        }

        // Get employee's department manager
        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $emp_row    = $wpdb->get_row( $wpdb->prepare( "SELECT dept_id FROM {$emp_table} WHERE id = %d", $emp_id ) );
        $manager_id = null;

        if ( $emp_row && $emp_row->dept_id ) {
            $dept = $wpdb->get_row( $wpdb->prepare( "SELECT manager_user_id FROM {$dept_table} WHERE id = %d", $emp_row->dept_id ) );
            if ( $dept && $dept->manager_user_id ) {
                $manager_id = (int) $dept->manager_user_id;
            }
        }

        // Get scheduled end time from shift
        $shift = AttendanceModule::resolve_shift_for_date( $emp_id, $request_date );
        $scheduled_end_time = $shift && ! empty( $shift->end_time ) ? $shift->end_time : null;

        // Get existing session if any
        $session_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $session       = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$session_table} WHERE employee_id = %d AND work_date = %s",
            $emp_id,
            $request_date
        ) );

        $now = current_time( 'mysql' );

        // Generate reference number
        $request_number = AttendanceModule::generate_early_leave_request_number();

        $data = [
            'employee_id'          => $emp_id,
            'session_id'           => $session ? $session->id : null,
            'request_date'         => $request_date,
            'scheduled_end_time'   => $scheduled_end_time,
            'requested_leave_time' => $requested_leave_time,
            'reason_type'          => $reason_type,
            'reason_note'          => $reason_note,
            'status'               => 'pending',
            'request_number'       => $request_number,
            'manager_id'           => $manager_id,
            'affects_salary'       => 0,
            'created_at'           => $now,
            'updated_at'           => $now,
        ];

        $wpdb->insert( $table, $data );
        $request_id = (int) $wpdb->insert_id;

        if ( ! $request_id ) {
            return new \WP_Error( 'db_error', __( 'Failed to create request.', 'sfs-hr' ), [ 'status' => 500 ] );
        }

        // TODO: Send notification to manager

        return rest_ensure_response( [
            'success'        => true,
            'request_id'     => $request_id,
            'request_number' => $request_number,
            'message'        => __( 'Early leave request submitted successfully.', 'sfs-hr' ),
        ] );
    }

    /**
     * Get employee's own requests
     */
    public static function my_requests( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $user_id = get_current_user_id();
        $emp_id  = AttendanceModule::employee_id_from_user( $user_id );

        if ( ! $emp_id ) {
            return new \WP_Error( 'not_employee', __( 'You are not registered as an employee.', 'sfs-hr' ), [ 'status' => 403 ] );
        }

        $table = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $limit = min( 50, max( 1, (int) ( $req['limit'] ?? 20 ) ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE employee_id = %d ORDER BY request_date DESC, created_at DESC LIMIT %d",
            $emp_id,
            $limit
        ), ARRAY_A );

        return rest_ensure_response( $rows ?: [] );
    }

    /**
     * Employee cancels their own pending request
     */
    public static function cancel_request( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $user_id    = get_current_user_id();
        $emp_id     = AttendanceModule::employee_id_from_user( $user_id );
        $request_id = (int) $req['id'];

        if ( ! $emp_id ) {
            return new \WP_Error( 'not_employee', __( 'You are not registered as an employee.', 'sfs-hr' ), [ 'status' => 403 ] );
        }

        $table   = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $request = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND employee_id = %d",
            $request_id,
            $emp_id
        ) );

        if ( ! $request ) {
            return new \WP_Error( 'not_found', __( 'Request not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }

        if ( $request->status !== 'pending' ) {
            return new \WP_Error( 'invalid_status', __( 'Only pending requests can be cancelled.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        $wpdb->update(
            $table,
            [ 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $request_id ]
        );

        return rest_ensure_response( [
            'success' => true,
            'message' => __( 'Request cancelled successfully.', 'sfs-hr' ),
        ] );
    }

    /**
     * Manager gets pending requests for their team
     */
    public static function pending_requests( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $user_id = get_current_user_id();
        $table   = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $emp_t   = $wpdb->prefix . 'sfs_hr_employees';

        // If admin, show all pending
        if ( current_user_can( 'sfs_hr_attendance_admin' ) ) {
            $sql = "SELECT r.*, e.first_name, e.last_name, e.employee_number
                    FROM {$table} r
                    LEFT JOIN {$emp_t} e ON e.id = r.employee_id
                    WHERE r.status = 'pending'
                    ORDER BY r.request_date DESC, r.created_at DESC";
            $rows = $wpdb->get_results( $sql, ARRAY_A );
        } else {
            // Show requests where current user is the manager
            $sql = $wpdb->prepare(
                "SELECT r.*, e.first_name, e.last_name, e.employee_number
                 FROM {$table} r
                 LEFT JOIN {$emp_t} e ON e.id = r.employee_id
                 WHERE r.status = 'pending' AND r.manager_id = %d
                 ORDER BY r.request_date DESC, r.created_at DESC",
                $user_id
            );
            $rows = $wpdb->get_results( $sql, ARRAY_A );
        }

        return rest_ensure_response( $rows ?: [] );
    }

    /**
     * Manager reviews (approves/rejects) a request
     */
    public static function review_request( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $user_id    = get_current_user_id();
        $request_id = (int) $req['id'];
        // Accept both 'action' (approve/reject) and 'status' (approved/rejected) params
        $action     = sanitize_key( $req['action'] ?? $req['status'] ?? '' );
        $note       = sanitize_textarea_field( $req['manager_note'] ?? '' );
        $affects    = (int) ( $req['affects_salary'] ?? 0 );

        // Normalize action values
        if ( $action === 'approved' ) {
            $action = 'approve';
        } elseif ( $action === 'rejected' ) {
            $action = 'reject';
        }

        if ( ! in_array( $action, [ 'approve', 'reject' ], true ) ) {
            return new \WP_Error( 'invalid_action', __( 'Action must be approve or reject.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        $table   = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $request_id ) );

        if ( ! $request ) {
            return new \WP_Error( 'not_found', __( 'Request not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }

        if ( $request->status !== 'pending' ) {
            return new \WP_Error( 'already_reviewed', __( 'Request has already been reviewed.', 'sfs-hr' ), [ 'status' => 400 ] );
        }

        // Check permission: must be admin or the assigned manager
        if ( ! current_user_can( 'sfs_hr_attendance_admin' ) && (int) $request->manager_id !== $user_id ) {
            return new \WP_Error( 'forbidden', __( 'You are not authorized to review this request.', 'sfs-hr' ), [ 'status' => 403 ] );
        }

        $new_status = $action === 'approve' ? 'approved' : 'rejected';
        $now        = current_time( 'mysql' );

        $update_data = [
            'status'         => $new_status,
            'reviewed_by'    => $user_id,
            'reviewed_at'    => $now,
            'manager_note'   => $note,
            'affects_salary' => $action === 'approve' ? $affects : 1, // rejected = affects salary
            'updated_at'     => $now,
        ];

        $wpdb->update( $table, $update_data, [ 'id' => $request_id ] );

        // If approved, update the attendance session
        if ( $action === 'approve' && $request->session_id ) {
            $session_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
            $wpdb->update(
                $session_table,
                [
                    'early_leave_approved'    => 1,
                    'early_leave_request_id'  => $request_id,
                ],
                [ 'id' => $request->session_id ]
            );

            // Recalculate session to update status
            AttendanceModule::recalc_session_for( (int) $request->employee_id, $request->request_date );
        }

        // TODO: Send notification to employee

        return rest_ensure_response( [
            'success' => true,
            'status'  => $new_status,
            'message' => $action === 'approve'
                ? __( 'Early leave request approved.', 'sfs-hr' )
                : __( 'Early leave request rejected.', 'sfs-hr' ),
        ] );
    }

    /**
     * Admin lists all requests with filters
     */
    public static function list_all( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $table  = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $emp_t  = $wpdb->prefix . 'sfs_hr_employees';
        $status = sanitize_key( $req['status'] ?? '' );
        $from   = sanitize_text_field( $req['from'] ?? '' );
        $to     = sanitize_text_field( $req['to'] ?? '' );
        $limit  = min( 100, max( 1, (int) ( $req['limit'] ?? 50 ) ) );
        $offset = max( 0, (int) ( $req['offset'] ?? 0 ) );

        $where = [];
        $args  = [];

        if ( $status && in_array( $status, [ 'pending', 'approved', 'rejected', 'cancelled' ], true ) ) {
            $where[] = 'r.status = %s';
            $args[]  = $status;
        }

        if ( $from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
            $where[] = 'r.request_date >= %s';
            $args[]  = $from;
        }

        if ( $to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            $where[] = 'r.request_date <= %s';
            $args[]  = $to;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $sql = "SELECT r.*, e.first_name, e.last_name, e.employee_number
                FROM {$table} r
                LEFT JOIN {$emp_t} e ON e.id = r.employee_id
                {$where_sql}
                ORDER BY r.request_date DESC, r.created_at DESC
                LIMIT %d OFFSET %d";

        $args[] = $limit;
        $args[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table} r {$where_sql}";
        $total     = $where ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...array_slice( $args, 0, -2 ) ) ) : (int) $wpdb->get_var( $count_sql );

        return rest_ensure_response( [
            'items' => $rows ?: [],
            'total' => $total,
        ] );
    }

    /**
     * Get pending count for dashboard
     */
    public static function get_pending_count_for_user( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_early_leave_requests';

        if ( current_user_can( 'sfs_hr_attendance_admin' ) ) {
            return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
        }

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND manager_id = %d",
            $user_id
        ) );
    }
}
