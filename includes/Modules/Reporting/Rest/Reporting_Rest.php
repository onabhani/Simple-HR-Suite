<?php
namespace SFS\HR\Modules\Reporting\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Reporting\Services\HR_Analytics_Service;
use SFS\HR\Modules\Reporting\Services\Report_Builder_Service;

class Reporting_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        // Analytics dashboard
        register_rest_route( $ns, '/analytics/dashboard', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'dashboard_kpis' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/analytics/headcount', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'headcount' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/analytics/attrition', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'attrition' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/analytics/leave-utilization', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'leave_utilization' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/analytics/payroll', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'payroll_summary' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/analytics/attendance', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'attendance_patterns' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/analytics/workforce', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'workforce_composition' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // Report builder
        register_rest_route( $ns, '/reports/types', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'report_types' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/reports/run', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'run_report' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/reports/export', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'export_report' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // Saved reports
        register_rest_route( $ns, '/reports/saved', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_saved' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'save_report' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/reports/saved/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_saved' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'delete_saved' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/reports/saved/(?P<id>\d+)/run', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'run_saved' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
    }

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    /* ── Analytics ── */

    public static function dashboard_kpis(): \WP_REST_Response {
        return new \WP_REST_Response( HR_Analytics_Service::get_dashboard_kpis() );
    }

    public static function headcount( \WP_REST_Request $req ): \WP_REST_Response {
        $dept_id = absint( $req['dept_id'] ?? 0 ) ?: null;
        return new \WP_REST_Response( HR_Analytics_Service::get_headcount_summary( $dept_id ) );
    }

    public static function attrition( \WP_REST_Request $req ): \WP_REST_Response {
        $months  = absint( $req['months'] ?? 12 ) ?: 12;
        $dept_id = absint( $req['dept_id'] ?? 0 ) ?: null;
        return new \WP_REST_Response( HR_Analytics_Service::get_attrition_report( $months, $dept_id ) );
    }

    public static function leave_utilization( \WP_REST_Request $req ): \WP_REST_Response {
        $dept_id = absint( $req['dept_id'] ?? 0 ) ?: null;
        return new \WP_REST_Response( HR_Analytics_Service::get_leave_utilization( $dept_id ) );
    }

    public static function payroll_summary( \WP_REST_Request $req ): \WP_REST_Response {
        $months = absint( $req['months'] ?? 6 ) ?: 6;
        return new \WP_REST_Response( HR_Analytics_Service::get_payroll_summary( $months ) );
    }

    public static function attendance_patterns( \WP_REST_Request $req ): \WP_REST_Response {
        $months  = absint( $req['months'] ?? 3 ) ?: 3;
        $dept_id = absint( $req['dept_id'] ?? 0 ) ?: null;
        return new \WP_REST_Response( HR_Analytics_Service::get_attendance_patterns( $months, $dept_id ) );
    }

    public static function workforce_composition( \WP_REST_Request $req ): \WP_REST_Response {
        $dept_id = absint( $req['dept_id'] ?? 0 ) ?: null;
        return new \WP_REST_Response( HR_Analytics_Service::get_workforce_composition( $dept_id ) );
    }

    /* ── Report Builder ── */

    public static function report_types(): \WP_REST_Response {
        return new \WP_REST_Response( Report_Builder_Service::get_predefined_reports() );
    }

    public static function run_report( \WP_REST_Request $req ): \WP_REST_Response {
        $type    = sanitize_text_field( $req['type'] ?? '' );
        $filters = $req['filters'] ?? [];
        if ( is_string( $filters ) ) {
            $filters = json_decode( $filters, true ) ?: [];
        }
        $result = Report_Builder_Service::run_report( $type, $filters );
        return new \WP_REST_Response( $result );
    }

    public static function export_report( \WP_REST_Request $req ): \WP_REST_Response {
        $type    = sanitize_text_field( $req['type'] ?? '' );
        $filters = $req['filters'] ?? [];
        if ( is_string( $filters ) ) {
            $filters = json_decode( $filters, true ) ?: [];
        }
        $csv = Report_Builder_Service::export_report( $type, $filters );
        $response = new \WP_REST_Response( $csv );
        $response->header( 'Content-Type', 'text/csv; charset=UTF-8' );
        $response->header( 'Content-Disposition', 'attachment; filename="report-' . $type . '.csv"' );
        return $response;
    }

    /* ── Saved Reports ── */

    public static function list_saved(): \WP_REST_Response {
        return new \WP_REST_Response( Report_Builder_Service::list_saved_reports() );
    }

    public static function save_report( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Report_Builder_Service::save_report( [
            'name'           => $req['name'] ?? '',
            'description'    => $req['description'] ?? '',
            'report_type'    => $req['report_type'] ?? '',
            'config'         => $req['config'] ?? [],
            'schedule_type'  => $req['schedule_type'] ?? 'none',
            'schedule_email' => $req['schedule_email'] ?? '',
        ] );
        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function get_saved( \WP_REST_Request $req ): \WP_REST_Response {
        $report = Report_Builder_Service::get_saved_report( (int) $req['id'] );
        if ( ! $report ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'Report not found.', 'sfs-hr' ) ],
                404
            );
        }
        return new \WP_REST_Response( $report );
    }

    public static function delete_saved( \WP_REST_Request $req ): \WP_REST_Response {
        $ok = Report_Builder_Service::delete_saved_report( (int) $req['id'] );
        return new \WP_REST_Response( [ 'success' => $ok ] );
    }

    public static function run_saved( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Report_Builder_Service::run_saved_report( (int) $req['id'] );
        if ( isset( $result['success'] ) && ! $result['success'] ) {
            return new \WP_REST_Response( $result, 404 );
        }
        return new \WP_REST_Response( $result );
    }
}
