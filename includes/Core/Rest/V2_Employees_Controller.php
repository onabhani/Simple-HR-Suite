<?php
namespace SFS\HR\Core\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * V2_Employees_Controller (M9.2)
 *
 * Reference implementation for v2 endpoints. Demonstrates the conventions
 * that future v2 controllers should follow:
 *   - Uses Rest_Response::paginated / success / error
 *   - Declares arg schemas so OpenAPI_Generator can introspect them
 *   - Gates permission through Rate_Limiter::gate(...)
 *   - Returns WP_Error for not-found / forbidden cases
 *   - All SQL prepared; returns a stable shape
 *
 * @since M9.2
 */
class V2_Employees_Controller {

	const ROUTE_LIST   = '/employees';
	const ROUTE_DETAIL = '/employees/(?P<id>\d+)';

	public static function register(): void {
		$ns = V2_Bootstrap::NAMESPACE_V2;

		register_rest_route( $ns, self::ROUTE_LIST, [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'list_employees' ],
			'permission_callback' => Rate_Limiter::gate(
				fn() => self::can_read(),
				[ 'limit' => 60, 'window' => 60 ]
			),
			'summary'             => 'List employees with pagination and optional filters',
			'schema'              => self::response_schema_list(),
			'args'                => [
				'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1,  'description' => 'Page number (1-indexed)' ],
				'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => 'Page size (max 100)' ],
				'status'   => [ 'type' => 'string', 'enum' => [ 'active', 'inactive', 'terminated' ], 'description' => 'Filter by status' ],
				'dept_id'  => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Filter by department ID' ],
				'search'   => [ 'type' => 'string', 'description' => 'Search in name / email / employee_code' ],
			],
		] );

		register_rest_route( $ns, self::ROUTE_DETAIL, [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'get_employee' ],
			'permission_callback' => Rate_Limiter::gate(
				fn() => self::can_read(),
				[ 'limit' => 120, 'window' => 60 ]
			),
			'summary'             => 'Get a single employee by ID',
			'schema'              => self::response_schema_single(),
			'args'                => [
				'id' => [ 'type' => 'integer', 'minimum' => 1, 'required' => true, 'description' => 'Employee ID' ],
			],
		] );
	}

	/* ───────── Permission ───────── */

	private static function can_read(): bool {
		return current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr.view' );
	}

	/* ───────── Handlers ───────── */

	public static function list_employees( \WP_REST_Request $req ) {
		global $wpdb;

		$table   = $wpdb->prefix . 'sfs_hr_employees';
		$paging  = Rest_Response::parse_pagination( $req, 20, 100 );

		$where   = [ '1=1' ];
		$args    = [];

		$status = $req->get_param( 'status' );
		if ( in_array( $status, [ 'active', 'inactive', 'terminated' ], true ) ) {
			$where[]  = 'status = %s';
			$args[]   = $status;
		}

		$dept_id = (int) $req->get_param( 'dept_id' );
		if ( $dept_id > 0 ) {
			$where[]  = 'dept_id = %d';
			$args[]   = $dept_id;
		}

		$search = trim( (string) $req->get_param( 'search' ) );
		if ( $search !== '' ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR employee_code LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		// Count.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( empty( $args )
			? $wpdb->get_var( $count_sql )
			: $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) );

		// Rows.
		$rows_args = array_merge( $args, [ $paging['per_page'], $paging['offset'] ] );
		$rows_sql  = "SELECT id, employee_code, first_name, last_name, email, phone, dept_id, position, status, hired_at
		              FROM {$table}
		              WHERE {$where_sql}
		              ORDER BY id DESC
		              LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$rows_args ), ARRAY_A );

		$data = array_map( [ self::class, 'format_row' ], $rows ?: [] );

		return Rest_Response::paginated( $data, $total, $paging['page'], $paging['per_page'] );
	}

	public static function get_employee( \WP_REST_Request $req ) {
		global $wpdb;

		$id    = (int) $req['id'];
		$table = $wpdb->prefix . 'sfs_hr_employees';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, employee_code, first_name, last_name, email, phone, dept_id, position, status, hired_at, created_at, updated_at
			 FROM {$table}
			 WHERE id = %d",
			$id
		), ARRAY_A );

		if ( ! $row ) {
			return Rest_Response::error( 'not_found', __( 'Employee not found.', 'sfs-hr' ), 404 );
		}

		return Rest_Response::success( self::format_row( $row ) );
	}

	/* ───────── Formatting ───────── */

	private static function format_row( array $row ): array {
		return [
			'id'            => (int) $row['id'],
			'employee_code' => (string) $row['employee_code'],
			'first_name'    => (string) ( $row['first_name'] ?? '' ),
			'last_name'     => (string) ( $row['last_name'] ?? '' ),
			'email'         => (string) ( $row['email'] ?? '' ),
			'phone'         => (string) ( $row['phone'] ?? '' ),
			'dept_id'       => isset( $row['dept_id'] ) && $row['dept_id'] !== null ? (int) $row['dept_id'] : null,
			'position'      => (string) ( $row['position'] ?? '' ),
			'status'        => (string) $row['status'],
			'hired_at'      => $row['hired_at'] ?? null,
			'created_at'    => $row['created_at'] ?? null,
			'updated_at'    => $row['updated_at'] ?? null,
		];
	}

	/* ───────── Schemas (for OpenAPI) ───────── */

	private static function employee_schema(): array {
		return [
			'type'     => 'object',
			'required' => [ 'id', 'employee_code', 'status' ],
			'properties' => [
				'id'            => [ 'type' => 'integer' ],
				'employee_code' => [ 'type' => 'string' ],
				'first_name'    => [ 'type' => 'string' ],
				'last_name'     => [ 'type' => 'string' ],
				'email'         => [ 'type' => 'string', 'format' => 'email' ],
				'phone'         => [ 'type' => 'string' ],
				'dept_id'       => [ 'type' => [ 'integer', 'null' ] ],
				'position'      => [ 'type' => 'string' ],
				'status'        => [ 'type' => 'string', 'enum' => [ 'active', 'inactive', 'terminated' ] ],
				'hired_at'      => [ 'type' => [ 'string', 'null' ], 'format' => 'date' ],
				'created_at'    => [ 'type' => [ 'string', 'null' ], 'format' => 'date-time' ],
				'updated_at'    => [ 'type' => [ 'string', 'null' ], 'format' => 'date-time' ],
			],
		];
	}

	private static function response_schema_list(): array {
		return [
			'type'     => 'object',
			'required' => [ 'data', 'meta' ],
			'properties' => [
				'data' => [
					'type'  => 'array',
					'items' => self::employee_schema(),
				],
				'meta' => [ '$ref' => '#/components/schemas/Meta' ],
			],
		];
	}

	private static function response_schema_single(): array {
		return [
			'type'     => 'object',
			'required' => [ 'data', 'meta' ],
			'properties' => [
				'data' => self::employee_schema(),
				'meta' => [
					'type'       => 'object',
					'properties' => [ 'timestamp' => [ 'type' => 'string', 'format' => 'date-time' ] ],
				],
			],
		];
	}
}
