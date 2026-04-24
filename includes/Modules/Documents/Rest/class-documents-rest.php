<?php
namespace SFS\HR\Modules\Documents\Rest;

use SFS\HR\Core\Rest\Rest_Response;
use SFS\HR\Modules\Documents\Services\Documents_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Documents REST API
 *
 * M9.1: full CRUD coverage. Upload, update, delete were missing — only
 * list-by-employee and list-expiring existed. Upload accepts a WordPress
 * attachment_id (client uploads file via WP media endpoint first) rather
 * than multipart to keep the surface simple and align with how the admin
 * UI already creates documents.
 */
class Documents_Rest {

    /**
     * Register REST routes
     */
    public static function register(): void {
        $ns = 'sfs-hr/v1';

        // Existing (backward-compat): list docs for one employee.
        register_rest_route($ns, '/documents/(?P<employee_id>\d+)', [
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

        // Existing: documents approaching expiry.
        register_rest_route($ns, '/documents/expiring', [
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

        // M9.1 — List all documents (admin), with filters.
        register_rest_route($ns, '/documents', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'list_all'],
                'permission_callback' => [self::class, 'check_admin_permission'],
                'args'                => [
                    'employee_id'   => ['type' => 'integer', 'minimum' => 1],
                    'document_type' => ['type' => 'string'],
                    'status'        => ['type' => 'string', 'enum' => ['active', 'archived', 'expired']],
                    'page'          => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    'per_page'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'upload_document'],
                'permission_callback' => [self::class, 'check_upload_permission'],
                'args'                => [
                    'employee_id'   => ['type' => 'integer', 'required' => true, 'minimum' => 1],
                    'document_type' => ['type' => 'string',  'required' => true],
                    'document_name' => ['type' => 'string',  'required' => true],
                    'attachment_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1,
                                         'description' => 'WordPress attachment ID (upload the file via /wp/v2/media first).'],
                    'description'   => ['type' => 'string'],
                    'expiry_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ],
            ],
        ]);

        // M9.1 — single doc detail.
        register_rest_route($ns, '/documents/(?P<id>\d+)/detail', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_detail'],
            'permission_callback' => [self::class, 'check_detail_permission'],
        ]);

        // M9.1 — update metadata (PUT and DELETE share the /documents/{id}
        // pattern — they don't collide with the existing GET handler that
        // uses (?P<employee_id>\d+) because WP REST dispatches by method).
        register_rest_route($ns, '/documents/(?P<id>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [self::class, 'update_document'],
                'permission_callback' => [self::class, 'check_admin_permission'],
                'args'                => [
                    'document_name' => ['type' => 'string'],
                    'description'   => ['type' => 'string'],
                    'expiry_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD or null to clear.'],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'delete_document'],
                'permission_callback' => [self::class, 'check_admin_permission'],
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

    /* ───────────────────────────────────────────────────────────── */
    /*  M9.1 — Admin CRUD                                             */
    /* ───────────────────────────────────────────────────────────── */

    /**
     * Upload requires either admin rights or ownership of the employee
     * record (employees can upload their own documents — this matches the
     * frontend profile tab behavior).
     */
    public static function check_upload_permission(\WP_REST_Request $req): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        if (current_user_can('sfs_hr.manage')) {
            return true;
        }

        $employee_id = (int) $req->get_param('employee_id');
        if ($employee_id <= 0) {
            return false;
        }

        global $wpdb;
        $owner = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d",
            $employee_id
        ));
        return $owner > 0 && $owner === get_current_user_id();
    }

    /**
     * Detail endpoint is visible to admins and to the document's owning
     * employee.
     */
    public static function check_detail_permission(\WP_REST_Request $req): bool {
        if (!is_user_logged_in()) {
            return false;
        }
        if (current_user_can('sfs_hr.manage')) {
            return true;
        }

        $id  = (int) $req['id'];
        $doc = Documents_Service::get_document($id);
        if (!$doc) {
            return false;
        }

        global $wpdb;
        $owner = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d",
            (int) $doc->employee_id
        ));
        return $owner > 0 && $owner === get_current_user_id();
    }

    /**
     * GET /documents — admin list across all employees.
     */
    public static function list_all(\WP_REST_Request $req) {
        global $wpdb;
        $table  = $wpdb->prefix . 'sfs_hr_employee_documents';
        $paging = Rest_Response::parse_pagination($req, 20, 100);

        $where = ['1=1'];
        $args  = [];

        $employee_id = (int) $req->get_param('employee_id');
        if ($employee_id > 0) {
            $where[] = 'd.employee_id = %d';
            $args[]  = $employee_id;
        }

        $type = (string) $req->get_param('document_type');
        if ($type !== '') {
            $where[] = 'd.document_type = %s';
            $args[]  = sanitize_key($type);
        }

        $status = (string) $req->get_param('status');
        if (in_array($status, ['active', 'archived', 'expired'], true)) {
            $where[] = 'd.status = %s';
            $args[]  = $status;
        } else {
            $where[] = "d.status != 'archived'";
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} d WHERE {$where_sql}";
        $total     = (int) (empty($args)
            ? $wpdb->get_var($count_sql)
            : $wpdb->get_var($wpdb->prepare($count_sql, ...$args)));

        $rows_args = array_merge($args, [$paging['per_page'], $paging['offset']]);
        $rows_sql  = "SELECT d.*, e.first_name, e.last_name, e.employee_code
                      FROM {$table} d
                      LEFT JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = d.employee_id
                      WHERE {$where_sql}
                      ORDER BY d.id DESC
                      LIMIT %d OFFSET %d";
        $rows      = $wpdb->get_results($wpdb->prepare($rows_sql, ...$rows_args), ARRAY_A);

        $data = array_map([self::class, 'format_document'], $rows ?: []);
        return Rest_Response::paginated($data, $total, $paging['page'], $paging['per_page']);
    }

    /**
     * POST /documents — create a document record for an existing WordPress
     * attachment. The client is expected to upload the file first via
     * `/wp/v2/media` and then pass the returned attachment_id here. That
     * keeps this endpoint JSON-only and matches the admin UI flow.
     */
    public static function upload_document(\WP_REST_Request $req) {
        $employee_id   = (int) $req->get_param('employee_id');
        $document_type = sanitize_key((string) $req->get_param('document_type'));
        $document_name = trim((string) $req->get_param('document_name'));
        $attachment_id = (int) $req->get_param('attachment_id');
        $description   = sanitize_textarea_field((string) $req->get_param('description'));
        $expiry_date   = trim((string) $req->get_param('expiry_date'));

        if ($employee_id <= 0 || $document_type === '' || $document_name === '' || $attachment_id <= 0) {
            return Rest_Response::error('invalid_input', __('employee_id, document_type, document_name, and attachment_id are required.', 'sfs-hr'), 400);
        }

        if ($expiry_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
            return Rest_Response::error('invalid_expiry_date', __('expiry_date must be YYYY-MM-DD.', 'sfs-hr'), 400);
        }

        // Verify attachment exists and mime is allowed.
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return Rest_Response::error('invalid_attachment', __('Attachment not found.', 'sfs-hr'), 400);
        }

        $mime = (string) get_post_mime_type($attachment_id);
        if (!in_array($mime, Documents_Service::get_allowed_mime_types(), true)) {
            return Rest_Response::error('mime_not_allowed', __('Attachment mime type is not allowed for documents.', 'sfs-hr'), 400);
        }

        $file_path = get_attached_file($attachment_id);
        $file_size = $file_path && file_exists($file_path) ? (int) filesize($file_path) : 0;
        if ($file_size > Documents_Service::get_max_file_size()) {
            return Rest_Response::error('file_too_large', __('Attachment exceeds the configured maximum size.', 'sfs-hr'), 400);
        }

        // Enforce the same type gate used by the admin UI (e.g. required
        // types, one-active-per-type rules).
        $gate = Documents_Service::can_employee_upload_document_type($employee_id, $document_type);
        if (!($gate['can_upload'] ?? false)) {
            return Rest_Response::error('upload_blocked', $gate['reason'] ?? __('Upload is not allowed.', 'sfs-hr'), 409);
        }

        $document_id = Documents_Service::create_document([
            'employee_id'   => $employee_id,
            'document_type' => $document_type,
            'document_name' => sanitize_text_field($document_name),
            'description'   => $description,
            'attachment_id' => $attachment_id,
            'file_name'     => basename($file_path ?: (string) $attachment->post_title),
            'file_size'     => $file_size,
            'mime_type'     => $mime,
            'expiry_date'   => $expiry_date,
            'uploaded_by'   => get_current_user_id(),
        ]);

        if (!$document_id) {
            return Rest_Response::error('insert_failed', __('Failed to create document.', 'sfs-hr'), 500);
        }

        do_action('sfs_hr_document_uploaded', (int) $document_id, $employee_id, $document_type);

        return Rest_Response::success(self::format_document((array) Documents_Service::get_document((int) $document_id)), 201);
    }

    /**
     * GET /documents/{id}/detail — single-document view.
     */
    public static function get_detail(\WP_REST_Request $req) {
        $doc = Documents_Service::get_document((int) $req['id']);
        if (!$doc) {
            return Rest_Response::error('not_found', __('Document not found.', 'sfs-hr'), 404);
        }
        return Rest_Response::success(self::format_document((array) $doc));
    }

    /**
     * PUT /documents/{id} — update editable metadata. The attachment itself
     * is not swapped here; use the version service (admin flow) for that.
     */
    public static function update_document(\WP_REST_Request $req) {
        global $wpdb;
        $id    = (int) $req['id'];
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';

        $doc = Documents_Service::get_document($id);
        if (!$doc) {
            return Rest_Response::error('not_found', __('Document not found.', 'sfs-hr'), 404);
        }

        $set = [];

        $name = $req->get_param('document_name');
        if ($name !== null) {
            $name = trim((string) $name);
            if ($name === '') {
                return Rest_Response::error('invalid_input', __('document_name cannot be empty.', 'sfs-hr'), 400);
            }
            $set['document_name'] = sanitize_text_field($name);
        }

        $description = $req->get_param('description');
        if ($description !== null) {
            $set['description'] = sanitize_textarea_field((string) $description);
        }

        if ($req->has_param('expiry_date')) {
            $expiry = $req->get_param('expiry_date');
            if ($expiry === null || $expiry === '') {
                $set['expiry_date'] = null;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $expiry)) {
                $set['expiry_date'] = (string) $expiry;
            } else {
                return Rest_Response::error('invalid_expiry_date', __('expiry_date must be YYYY-MM-DD or null.', 'sfs-hr'), 400);
            }
        }

        if (empty($set)) {
            return Rest_Response::error('invalid_input', __('No fields to update.', 'sfs-hr'), 400);
        }

        $set['updated_at'] = current_time('mysql');
        $wpdb->update($table, $set, ['id' => $id]);

        return Rest_Response::success(self::format_document((array) Documents_Service::get_document($id)));
    }

    /**
     * DELETE /documents/{id} — archive (soft delete). Preserves the row so
     * version history and audit trails stay intact.
     */
    public static function delete_document(\WP_REST_Request $req) {
        $id  = (int) $req['id'];
        $doc = Documents_Service::get_document($id);
        if (!$doc) {
            return Rest_Response::error('not_found', __('Document not found.', 'sfs-hr'), 404);
        }
        if ($doc->status === 'archived') {
            return Rest_Response::error('invalid_state', __('Document is already archived.', 'sfs-hr'), 409);
        }

        $ok = Documents_Service::archive_document($id);
        if (!$ok) {
            return Rest_Response::error('delete_failed', __('Could not archive document.', 'sfs-hr'), 500);
        }

        do_action('sfs_hr_document_archived', $id);

        return Rest_Response::success(['archived' => true, 'id' => $id]);
    }

    /* ── Formatting ── */

    private static function format_document(array $row): array {
        $expiry = Documents_Service::get_expiry_status($row['expiry_date'] ?? null);
        $out = [
            'id'            => (int) $row['id'],
            'employee_id'   => (int) $row['employee_id'],
            'document_type' => (string) $row['document_type'],
            'document_name' => (string) $row['document_name'],
            'description'   => $row['description'] ?? '',
            'file_name'     => (string) ($row['file_name'] ?? ''),
            'file_size'     => (int) ($row['file_size'] ?? 0),
            'mime_type'     => (string) ($row['mime_type'] ?? ''),
            'expiry_date'   => $row['expiry_date'] ?? null,
            'expiry_status' => $expiry['status'],
            'status'        => (string) ($row['status'] ?? 'active'),
            'uploaded_by'   => isset($row['uploaded_by']) ? (int) $row['uploaded_by'] : null,
            'attachment_id' => isset($row['attachment_id']) ? (int) $row['attachment_id'] : null,
            'file_url'      => isset($row['attachment_id']) ? wp_get_attachment_url((int) $row['attachment_id']) : null,
            'created_at'    => $row['created_at'] ?? null,
            'updated_at'    => $row['updated_at'] ?? null,
        ];

        if (isset($row['first_name']) || isset($row['last_name'])) {
            $out['employee_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        }
        if (isset($row['employee_code'])) {
            $out['employee_code'] = (string) $row['employee_code'];
        }

        return $out;
    }
}
