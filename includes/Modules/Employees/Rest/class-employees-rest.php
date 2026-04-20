<?php
namespace SFS\HR\Modules\Employees\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Rest\Rest_Response;

/**
 * Employees_Rest
 *
 * M9.1 — REST endpoints for Employees CRUD.
 *
 * Base: /sfs-hr/v1/employees
 *   GET    /employees                — list with pagination + filters
 *   POST   /employees                — create
 *   GET    /employees/{id}           — detail
 *   PATCH  /employees/{id}           — update (partial)
 *   DELETE /employees/{id}           — mark terminated (soft)
 *
 * Sensitive fields (national_id, passport_no, bank_account, iban) are
 * redacted for non-admin requesters.
 *
 * @since M9
 */
class Employees_Rest {

    /** Fields safe to expose in list/detail views (non-admin). */
    private const PUBLIC_FIELDS = [
        'id', 'employee_code', 'user_id', 'status',
        'first_name', 'last_name', 'first_name_ar', 'last_name_ar',
        'email', 'phone', 'dept_id', 'position',
        'hire_date', 'hired_at', 'created_at', 'updated_at',
    ];

    /** Additional fields only admins see. */
    private const ADMIN_FIELDS = [
        'base_salary',
        'national_id', 'national_id_expiry',
        'passport_no', 'passport_expiry',
        'emergency_contact_name', 'emergency_contact_phone',
        'bank_name', 'bank_account', 'iban',
    ];

    /** Fields writable via create/update. Everything else is ignored. */
    private const WRITABLE_FIELDS = [
        'employee_code', 'user_id', 'status',
        'first_name', 'last_name', 'first_name_ar', 'last_name_ar',
        'email', 'phone', 'dept_id', 'position',
        'hire_date', 'hired_at', 'base_salary',
        'national_id', 'national_id_expiry',
        'passport_no', 'passport_expiry',
        'emergency_contact_name', 'emergency_contact_phone',
        'bank_name', 'bank_account', 'iban',
    ];

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        register_rest_route( $ns, '/employees', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_employees' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
                'args' => [
                    'page'     => [ 'type' => 'integer', 'default' => 1 ],
                    'per_page' => [ 'type' => 'integer', 'default' => 20 ],
                    'status'   => [ 'type' => 'string',  'required' => false ],
                    'dept_id'  => [ 'type' => 'integer', 'required' => false ],
                    'search'   => [ 'type' => 'string',  'required' => false ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_employee' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/employees/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_employee' ],
                'permission_callback' => [ __CLASS__, 'can_view_single' ],
            ],
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [ __CLASS__, 'update_employee' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'terminate_employee' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
    }

    // ── Permission callbacks ────────────────────────────────────────────────

    public static function can_view(): bool {
        return current_user_can( 'sfs_hr.view' );
    }

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    /** Allow self-view (employee reading their own record) in addition to admins. */
    public static function can_view_single( \WP_REST_Request $req ): bool {
        if ( current_user_can( 'sfs_hr.view' ) ) {
            return true;
        }
        global $wpdb;
        $id      = (int) $req['id'];
        $row_uid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d",
            $id
        ) );
        return $row_uid > 0 && $row_uid === get_current_user_id();
    }

    // ── List ────────────────────────────────────────────────────────────────

    public static function list_employees( \WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';

        $pg       = Rest_Response::parse_pagination( $req );
        $status   = sanitize_text_field( (string) ( $req->get_param( 'status' ) ?? '' ) );
        $dept_id  = (int) ( $req->get_param( 'dept_id' ) ?? 0 );
        $search   = trim( (string) ( $req->get_param( 'search' ) ?? '' ) );

        $where  = [ '1=1' ];
        $params = [];

        if ( $status !== '' ) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }
        if ( $dept_id > 0 ) {
            $where[]  = 'dept_id = %d';
            $params[] = $dept_id;
        }
        if ( $search !== '' ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR employee_code LIKE %s)';
            array_push( $params, $like, $like, $like, $like );
        }

        $where_sql = implode( ' AND ', $where );

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ) );

        $rows_sql     = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows_params  = array_merge( $params, [ $pg['per_page'], $pg['offset'] ] );
        $rows         = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$rows_params ), ARRAY_A ) ?: [];

        $is_admin = current_user_can( 'sfs_hr.manage' );
        $rows     = array_map( fn( $r ) => self::filter_fields( $r, $is_admin ), $rows );

        return Rest_Response::paginated( $rows, $total, $pg['page'], $pg['per_page'] );
    }

    // ── Get single ──────────────────────────────────────────────────────────

    public static function get_employee( \WP_REST_Request $req ) {
        global $wpdb;
        $id  = (int) $req['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $row ) {
            return Rest_Response::error( 'not_found', __( 'Employee not found.', 'sfs-hr' ), 404 );
        }

        $is_admin = current_user_can( 'sfs_hr.manage' );
        // Non-admins viewing their own record still get admin fields for themselves.
        $self     = (int) $row['user_id'] === get_current_user_id();
        return Rest_Response::success( self::filter_fields( $row, $is_admin || $self ) );
    }

    // ── Create ──────────────────────────────────────────────────────────────

    public static function create_employee( \WP_REST_Request $req ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';

        $data = self::extract_writable_fields( $req );

        // Required: employee_code
        if ( empty( $data['employee_code'] ) ) {
            return Rest_Response::error( 'missing_field', __( 'employee_code is required.', 'sfs-hr' ), 400, [ 'employee_code' => 'required' ] );
        }

        // Uniqueness check
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE employee_code = %s",
            $data['employee_code']
        ) );
        if ( $exists > 0 ) {
            return Rest_Response::error( 'duplicate', __( 'An employee with this code already exists.', 'sfs-hr' ), 409 );
        }

        $now = current_time( 'mysql' );
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        if ( empty( $data['status'] ) ) {
            $data['status'] = 'active';
        }

        $formats = array_fill( 0, count( $data ), '%s' );
        $ok = $wpdb->insert( $table, $data, $formats );
        if ( ! $ok ) {
            return Rest_Response::error( 'db_error', __( 'Failed to create employee.', 'sfs-hr' ), 500 );
        }

        $new_id  = (int) $wpdb->insert_id;
        $new_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $new_id
        ), ARRAY_A );

        /** Fire action for hooks + webhook dispatch */
        do_action( 'sfs_hr_employee_created', $new_id, $new_row );

        return new \WP_REST_Response( [
            'data' => self::filter_fields( $new_row, true ),
            'meta' => [ 'timestamp' => gmdate( 'c' ) ],
        ], 201 );
    }

    // ── Update ──────────────────────────────────────────────────────────────

    public static function update_employee( \WP_REST_Request $req ) {
        global $wpdb;
        $id    = (int) $req['id'];
        $table = $wpdb->prefix . 'sfs_hr_employees';

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A );
        if ( ! $existing ) {
            return Rest_Response::error( 'not_found', __( 'Employee not found.', 'sfs-hr' ), 404 );
        }

        $data = self::extract_writable_fields( $req );
        if ( empty( $data ) ) {
            return Rest_Response::error( 'no_fields', __( 'No valid fields to update.', 'sfs-hr' ), 400 );
        }

        // Guard: if changing employee_code, enforce uniqueness
        if ( isset( $data['employee_code'] ) && $data['employee_code'] !== $existing['employee_code'] ) {
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE employee_code = %s AND id != %d",
                $data['employee_code'], $id
            ) );
            if ( $exists > 0 ) {
                return Rest_Response::error( 'duplicate', __( 'Another employee already uses this code.', 'sfs-hr' ), 409 );
            }
        }

        $data['updated_at'] = current_time( 'mysql' );
        $formats = array_fill( 0, count( $data ), '%s' );

        $ok = $wpdb->update( $table, $data, [ 'id' => $id ], $formats, [ '%d' ] );
        if ( false === $ok ) {
            return Rest_Response::error( 'db_error', __( 'Failed to update employee.', 'sfs-hr' ), 500 );
        }

        $new_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A );

        do_action( 'sfs_hr_employee_updated', $id, $new_row, $existing );

        return Rest_Response::success( self::filter_fields( $new_row, true ) );
    }

    // ── Terminate (soft delete) ─────────────────────────────────────────────

    public static function terminate_employee( \WP_REST_Request $req ) {
        global $wpdb;
        $id    = (int) $req['id'];
        $table = $wpdb->prefix . 'sfs_hr_employees';

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A );
        if ( ! $existing ) {
            return Rest_Response::error( 'not_found', __( 'Employee not found.', 'sfs-hr' ), 404 );
        }

        $ok = $wpdb->update(
            $table,
            [ 'status' => 'terminated', 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        if ( false === $ok ) {
            return Rest_Response::error( 'db_error', __( 'Failed to terminate employee.', 'sfs-hr' ), 500 );
        }

        do_action( 'sfs_hr_employee_deleted', $id, $existing );

        return Rest_Response::success( [ 'terminated' => true, 'id' => $id ] );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Filter row fields: redact admin-only columns for non-admin callers.
     */
    private static function filter_fields( array $row, bool $is_admin ): array {
        if ( $is_admin ) {
            return $row;
        }
        $out = [];
        foreach ( self::PUBLIC_FIELDS as $f ) {
            if ( array_key_exists( $f, $row ) ) {
                $out[ $f ] = $row[ $f ];
            }
        }
        return $out;
    }

    /**
     * Extract & sanitize writable fields from the request. Ignores anything
     * not in WRITABLE_FIELDS.
     */
    private static function extract_writable_fields( \WP_REST_Request $req ): array {
        $out = [];
        foreach ( self::WRITABLE_FIELDS as $field ) {
            if ( ! $req->has_param( $field ) ) {
                continue;
            }
            $val = $req->get_param( $field );
            if ( null === $val ) {
                continue;
            }
            switch ( $field ) {
                case 'user_id':
                case 'dept_id':
                    $out[ $field ] = (int) $val;
                    break;
                case 'base_salary':
                    $out[ $field ] = (float) $val;
                    break;
                case 'email':
                    $out[ $field ] = sanitize_email( (string) $val );
                    break;
                case 'hire_date':
                case 'hired_at':
                case 'national_id_expiry':
                case 'passport_expiry':
                    // Accept Y-m-d only
                    if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $val ) ) {
                        $out[ $field ] = (string) $val;
                    }
                    break;
                default:
                    $out[ $field ] = sanitize_text_field( (string) $val );
            }
        }
        return $out;
    }
}
