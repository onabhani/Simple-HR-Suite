<?php
namespace SFS\HR\Modules\Documents\Rest;

use SFS\HR\Modules\Documents\Services\Documents_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Documents REST API
 * REST endpoints for document operations
 */
class Documents_Rest {

    /**
     * Register REST routes
     */
    public static function register(): void {
        register_rest_route('sfs-hr/v1', '/documents/(?P<employee_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_documents'],
            'permission_callback' => [self::class, 'check_read_permission'],
            'args'                => [
                'employee_id' => [
                    'required'          => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ],
            ],
        ]);

        register_rest_route('sfs-hr/v1', '/documents/expiring', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_expiring'],
            'permission_callback' => [self::class, 'check_admin_permission'],
            'args'                => [
                'days' => [
                    'default'           => 30,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 365;
                    },
                ],
            ],
        ]);
    }

    /**
     * Check read permission for employee documents
     */
    public static function check_read_permission(\WP_REST_Request $request): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        $employee_id = (int)$request->get_param('employee_id');
        $current_user_id = get_current_user_id();

        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$emp_table} WHERE id = %d",
            $employee_id
        ));

        if (!$employee) {
            return false;
        }

        // Own documents or HR manager
        return (int)$employee->user_id === $current_user_id
            || current_user_can('sfs_hr.manage')
            || current_user_can('sfs_hr_attendance_view_team');
    }

    /**
     * Check admin permission
     */
    public static function check_admin_permission(): bool {
        return current_user_can('sfs_hr.manage');
    }

    /**
     * GET /documents/{employee_id}
     */
    public static function get_documents(\WP_REST_Request $request): \WP_REST_Response {
        $employee_id = (int)$request->get_param('employee_id');
        $documents = Documents_Service::get_employee_documents($employee_id);

        $result = [];
        foreach ($documents as $doc) {
            $expiry = Documents_Service::get_expiry_status($doc->expiry_date);

            $result[] = [
                'id'            => (int)$doc->id,
                'document_type' => $doc->document_type,
                'document_name' => $doc->document_name,
                'description'   => $doc->description,
                'file_name'     => $doc->file_name,
                'file_size'     => (int)$doc->file_size,
                'mime_type'     => $doc->mime_type,
                'expiry_date'   => $doc->expiry_date,
                'expiry_status' => $expiry['status'],
                'file_url'      => wp_get_attachment_url($doc->attachment_id),
                'created_at'    => $doc->created_at,
            ];
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * GET /documents/expiring
     */
    public static function get_expiring(\WP_REST_Request $request): \WP_REST_Response {
        $days = (int)$request->get_param('days');
        $documents = Documents_Service::get_expiring_documents($days);

        $result = [];
        foreach ($documents as $doc) {
            $expiry = Documents_Service::get_expiry_status($doc->expiry_date);

            $result[] = [
                'id'            => (int)$doc->id,
                'employee_id'   => (int)$doc->employee_id,
                'employee_name' => trim($doc->first_name . ' ' . $doc->last_name),
                'employee_code' => $doc->employee_code,
                'document_type' => $doc->document_type,
                'document_name' => $doc->document_name,
                'expiry_date'   => $doc->expiry_date,
                'expiry_status' => $expiry['status'],
                'days_until'    => (int)((strtotime($doc->expiry_date) - strtotime(wp_date('Y-m-d'))) / 86400),
            ];
        }

        return new \WP_REST_Response($result, 200);
    }
}
