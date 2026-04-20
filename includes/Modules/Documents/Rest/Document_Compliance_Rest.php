<?php
namespace SFS\HR\Modules\Documents\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Documents\Services\Document_Compliance_Service;
use SFS\HR\Modules\Documents\Services\Letter_Template_Service;

class Document_Compliance_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        // Compliance
        register_rest_route( $ns, '/documents/compliance/overview', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'compliance_overview' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/documents/compliance/expiring-report', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'expiring_report' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/documents/compliance/missing-report', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'missing_report' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/documents/compliance/employee/(?P<employee_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'employee_compliance' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // Letter Templates
        register_rest_route( $ns, '/documents/templates', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_templates' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'save_template' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/documents/templates/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_template' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/documents/templates/generate', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'generate_letter' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/documents/templates/fields', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'list_fields' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // Document versioning
        register_rest_route( $ns, '/documents/(?P<id>\d+)/versions', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'document_versions' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
    }

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    /* ──────── Compliance ──────── */

    public static function compliance_overview( \WP_REST_Request $req ): \WP_REST_Response {
        $dept_id = absint( $req['dept_id'] ?? 0 ) ?: null;
        return new \WP_REST_Response( Document_Compliance_Service::get_compliance_overview( $dept_id ) );
    }

    public static function expiring_report( \WP_REST_Request $req ): \WP_REST_Response {
        $days    = absint( $req['days'] ?? 30 ) ?: 30;
        $dept_id = absint( $req['dept_id'] ?? 0 ) ?: null;
        return new \WP_REST_Response( Document_Compliance_Service::get_expiring_report( $days, $dept_id ) );
    }

    public static function missing_report( \WP_REST_Request $req ): \WP_REST_Response {
        $dept_id = absint( $req['dept_id'] ?? 0 ) ?: null;
        return new \WP_REST_Response( Document_Compliance_Service::get_missing_report( $dept_id ) );
    }

    public static function employee_compliance( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Document_Compliance_Service::get_employee_compliance( (int) $req['employee_id'] );
        return new \WP_REST_Response( $result );
    }

    /* ──────── Letter Templates ──────── */

    public static function list_templates(): \WP_REST_Response {
        return new \WP_REST_Response( Letter_Template_Service::list_templates() );
    }

    public static function get_template( \WP_REST_Request $req ): \WP_REST_Response {
        $tpl = Letter_Template_Service::get_template( (int) $req['id'] );
        if ( ! $tpl ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'Template not found.', 'sfs-hr' ) ],
                404
            );
        }
        return new \WP_REST_Response( $tpl );
    }

    public static function save_template( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Letter_Template_Service::upsert_template( [
            'id'        => absint( $req['id'] ?? 0 ) ?: null,
            'code'      => $req['code'] ?? '',
            'name'      => $req['name'] ?? '',
            'name_ar'   => $req['name_ar'] ?? '',
            'body_en'   => $req['body_en'] ?? '',
            'body_ar'   => $req['body_ar'] ?? '',
            'is_active' => isset( $req['is_active'] ) ? (int) $req['is_active'] : 1,
        ] );

        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function generate_letter( \WP_REST_Request $req ): \WP_REST_Response {
        $code        = sanitize_text_field( $req['template_code'] ?? '' );
        $employee_id = absint( $req['employee_id'] ?? 0 );
        $lang        = sanitize_text_field( $req['lang'] ?? 'en' );

        if ( ! $code || ! $employee_id ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'Template code and employee ID are required.', 'sfs-hr' ) ],
                400
            );
        }

        $result = Letter_Template_Service::generate_letter( $code, $employee_id, $lang );
        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function list_fields(): \WP_REST_Response {
        return new \WP_REST_Response( Letter_Template_Service::get_available_fields() );
    }

    /* ──────── Document Versions ──────── */

    public static function document_versions( \WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';

        $doc = $wpdb->get_row( $wpdb->prepare(
            "SELECT employee_id, document_type FROM {$table} WHERE id = %d LIMIT 1",
            (int) $req['id']
        ) );

        if ( ! $doc ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'Document not found.', 'sfs-hr' ) ],
                404
            );
        }

        $versions = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, document_name, file_name, status, expiry_date, created_at
             FROM {$table}
             WHERE employee_id = %d AND document_type = %s
             ORDER BY created_at DESC",
            (int) $doc->employee_id,
            $doc->document_type
        ), ARRAY_A );

        return new \WP_REST_Response( $versions );
    }
}
