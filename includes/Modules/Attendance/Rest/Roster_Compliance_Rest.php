<?php
namespace SFS\HR\Modules\Attendance\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Attendance\Services\Roster_Service;
use SFS\HR\Modules\Attendance\Services\Compliance_Service;

class Roster_Compliance_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        // Roster
        register_rest_route( $ns, '/attendance/roster/generate', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'roster_generate' ],
            'permission_callback' => [ __CLASS__, 'can_admin' ],
        ] );

        register_rest_route( $ns, '/attendance/roster/runs', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'roster_list_runs' ],
            'permission_callback' => [ __CLASS__, 'can_admin' ],
        ] );

        register_rest_route( $ns, '/attendance/roster/runs/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'roster_get_run' ],
            'permission_callback' => [ __CLASS__, 'can_admin' ],
        ] );

        register_rest_route( $ns, '/attendance/roster/runs/(?P<id>\d+)/publish', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'roster_publish' ],
            'permission_callback' => [ __CLASS__, 'can_admin' ],
        ] );

        register_rest_route( $ns, '/attendance/roster/runs/(?P<id>\d+)/revert', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'roster_revert' ],
            'permission_callback' => [ __CLASS__, 'can_admin' ],
        ] );

        // Compliance
        register_rest_route( $ns, '/attendance/compliance/compute', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'compliance_compute' ],
            'permission_callback' => [ __CLASS__, 'can_admin' ],
        ] );

        register_rest_route( $ns, '/attendance/compliance/snapshot', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'compliance_snapshot' ],
            'permission_callback' => [ __CLASS__, 'can_admin' ],
            'args'                => [
                'employee_id'  => [ 'type' => 'integer', 'required' => true ],
                'period_start' => [ 'type' => 'string',  'required' => true ],
                'period_end'   => [ 'type' => 'string',  'required' => true ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/compliance/summary', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'compliance_summary' ],
            'permission_callback' => [ __CLASS__, 'can_admin' ],
            'args'                => [
                'period_start' => [ 'type' => 'string',  'required' => true ],
                'period_end'   => [ 'type' => 'string',  'required' => true ],
                'dept_id'      => [ 'type' => 'integer', 'required' => false ],
            ],
        ] );

        register_rest_route( $ns, '/attendance/compliance/fatigue', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'compliance_fatigue' ],
            'permission_callback' => [ __CLASS__, 'can_admin' ],
        ] );

        register_rest_route( $ns, '/attendance/compliance/weekly-off-violators', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'compliance_weekly_off' ],
            'permission_callback' => [ __CLASS__, 'can_admin' ],
            'args'                => [
                'start_date' => [ 'type' => 'string', 'required' => true ],
                'end_date'   => [ 'type' => 'string', 'required' => true ],
            ],
        ] );
    }

    public static function can_admin(): bool {
        return current_user_can( 'sfs_hr_attendance_admin' );
    }

    /* ──────── Roster ──────── */

    public static function roster_generate( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Roster_Service::generate( [
            'start_date'  => sanitize_text_field( $req['start_date'] ?? '' ),
            'end_date'    => sanitize_text_field( $req['end_date'] ?? '' ),
            'dept_id'     => absint( $req['dept_id'] ?? 0 ) ?: null,
            'shift_id'    => absint( $req['shift_id'] ?? 0 ) ?: null,
            'schedule_id' => absint( $req['schedule_id'] ?? 0 ) ?: null,
        ] );

        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function roster_list_runs( \WP_REST_Request $req ): \WP_REST_Response {
        $runs = Roster_Service::list_runs( [
            'dept_id' => absint( $req['dept_id'] ?? 0 ) ?: null,
            'status'  => sanitize_text_field( $req['status'] ?? '' ) ?: null,
            'limit'   => absint( $req['limit'] ?? 20 ),
            'offset'  => absint( $req['offset'] ?? 0 ),
        ] );
        return new \WP_REST_Response( $runs );
    }

    public static function roster_get_run( \WP_REST_Request $req ): \WP_REST_Response {
        $run = Roster_Service::get_run( (int) $req['id'] );
        if ( ! $run ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'Roster run not found.', 'sfs-hr' ) ],
                404
            );
        }
        return new \WP_REST_Response( $run );
    }

    public static function roster_publish( \WP_REST_Request $req ): \WP_REST_Response {
        $ok = Roster_Service::publish( (int) $req['id'] );
        return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 400 );
    }

    public static function roster_revert( \WP_REST_Request $req ): \WP_REST_Response {
        $ok = Roster_Service::revert( (int) $req['id'] );
        return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 400 );
    }

    /* ──────── Compliance ──────── */

    public static function compliance_compute( \WP_REST_Request $req ): \WP_REST_Response {
        $period_start = sanitize_text_field( $req['period_start'] ?? '' );
        $period_end   = sanitize_text_field( $req['period_end'] ?? '' );
        $dept_id      = absint( $req['dept_id'] ?? 0 ) ?: null;

        if ( ! $period_start || ! $period_end ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'Period start and end are required.', 'sfs-hr' ) ],
                400
            );
        }

        $employee_id = absint( $req['employee_id'] ?? 0 );
        if ( $employee_id ) {
            $snapshot = Compliance_Service::compute_snapshot( $employee_id, $period_start, $period_end );
            return new \WP_REST_Response( [ 'success' => true, 'snapshot' => $snapshot ] );
        }

        $count = Compliance_Service::compute_bulk( $period_start, $period_end, $dept_id );
        return new \WP_REST_Response( [ 'success' => true, 'processed' => $count ] );
    }

    public static function compliance_snapshot( \WP_REST_Request $req ): \WP_REST_Response {
        $snap = Compliance_Service::get_snapshot(
            (int) $req['employee_id'],
            sanitize_text_field( $req['period_start'] ),
            sanitize_text_field( $req['period_end'] )
        );
        if ( ! $snap ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'Snapshot not found. Run compute first.', 'sfs-hr' ) ],
                404
            );
        }
        return new \WP_REST_Response( $snap );
    }

    public static function compliance_summary( \WP_REST_Request $req ): \WP_REST_Response {
        $summary = Compliance_Service::get_department_summary(
            sanitize_text_field( $req['period_start'] ),
            sanitize_text_field( $req['period_end'] ),
            absint( $req['dept_id'] ?? 0 ) ?: null
        );
        return new \WP_REST_Response( $summary );
    }

    public static function compliance_fatigue( \WP_REST_Request $req ): \WP_REST_Response {
        $threshold = absint( $req['threshold'] ?? 0 ) ?: Compliance_Service::MAX_CONSECUTIVE_DAYS;
        $alerts    = Compliance_Service::get_fatigue_alerts( $threshold );
        return new \WP_REST_Response( $alerts );
    }

    public static function compliance_weekly_off( \WP_REST_Request $req ): \WP_REST_Response {
        $violators = Compliance_Service::get_weekly_off_violators(
            sanitize_text_field( $req['start_date'] ),
            sanitize_text_field( $req['end_date'] )
        );
        return new \WP_REST_Response( $violators );
    }
}
