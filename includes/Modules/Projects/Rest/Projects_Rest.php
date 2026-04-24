<?php
namespace SFS\HR\Modules\Projects\Rest;

use SFS\HR\Core\Rest\Rest_Response;
use SFS\HR\Modules\Projects\Services\Projects_Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Projects REST API (M9.1)
 *
 * Fills the final M9.1 gap: the Projects module had admin pages and a
 * full service layer but no REST layer. All CRUD goes through the
 * existing Projects_Service, so REST and admin stay in lockstep — any
 * future change to insert/update/delete business rules (e.g. the
 * cascading delete of assignments + shifts inside a transaction) is
 * honored automatically.
 *
 * @since M9.1
 */
class Projects_Rest {

	const NS = 'sfs-hr/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		// Projects CRUD
		register_rest_route( self::NS, '/projects', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_projects' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'args'                => [
					'active_only' => [ 'type' => 'boolean', 'default' => false ],
					'page'        => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
					'per_page'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'create_project' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'name'              => [ 'type' => 'string',  'required' => true ],
					'location_label'    => [ 'type' => 'string' ],
					'location_lat'      => [ 'type' => 'number' ],
					'location_lng'      => [ 'type' => 'number' ],
					'location_radius_m' => [ 'type' => 'integer', 'minimum' => 0 ],
					'start_date'        => [ 'type' => 'string', 'description' => 'YYYY-MM-DD' ],
					'end_date'          => [ 'type' => 'string', 'description' => 'YYYY-MM-DD' ],
					'manager_user_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
					'active'            => [ 'type' => 'boolean', 'default' => true ],
					'notes'             => [ 'type' => 'string' ],
				],
			],
		] );

		register_rest_route( self::NS, '/projects/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_project' ],
				'permission_callback' => [ self::class, 'can_read' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ self::class, 'update_project' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'delete_project' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			],
		] );

		// Project-Employee assignments
		register_rest_route( self::NS, '/projects/(?P<id>\d+)/employees', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_assignments' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'args'                => [
					'on_date' => [ 'type' => 'string', 'description' => 'Filter to assignments active on YYYY-MM-DD' ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'assign_employee' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'employee_id'   => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
					'assigned_from' => [ 'type' => 'string',  'required' => true, 'description' => 'YYYY-MM-DD' ],
					'assigned_to'   => [ 'type' => 'string', 'description' => 'YYYY-MM-DD (null for open-ended)' ],
				],
			],
		] );

		register_rest_route( self::NS, '/projects/assignments/(?P<assignment_id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ self::class, 'remove_assignment' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );

		// Project-Shift links
		register_rest_route( self::NS, '/projects/(?P<id>\d+)/shifts', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_shifts' ],
				'permission_callback' => [ self::class, 'can_read' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'link_shift' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'shift_id'   => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
					'is_default' => [ 'type' => 'boolean', 'default' => false ],
				],
			],
		] );

		register_rest_route( self::NS, '/projects/shifts/(?P<link_id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ self::class, 'unlink_shift' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );
	}

	/* ───────── Permissions ───────── */

	public static function can_read(): bool {
		return is_user_logged_in() && (
			current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr.view' )
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'sfs_hr.manage' );
	}

	/* ───────── Project handlers ───────── */

	public static function list_projects( \WP_REST_Request $req ) {
		$active_only = (bool) $req->get_param( 'active_only' );
		$paging      = Rest_Response::parse_pagination( $req, 20, 100 );

		$all   = Projects_Service::get_all( $active_only );
		$total = count( $all );
		$page  = array_slice( $all, $paging['offset'], $paging['per_page'] );

		// Enrich with employee counts.
		$ids = array_map( fn( $p ) => (int) $p->id, $page );
		$counts = $ids ? Projects_Service::get_employee_counts( $ids ) : [];

		$data = array_map(
			function ( $p ) use ( $counts ) {
				$row = self::format_project( $p );
				$row['employee_count'] = (int) ( $counts[ $row['id'] ] ?? 0 );
				return $row;
			},
			$page
		);

		return Rest_Response::paginated( $data, $total, $paging['page'], $paging['per_page'] );
	}

	public static function get_project( \WP_REST_Request $req ) {
		$id      = (int) $req['id'];
		$project = Projects_Service::get( $id );
		if ( ! $project ) {
			return Rest_Response::error( 'not_found', __( 'Project not found.', 'sfs-hr' ), 404 );
		}
		return Rest_Response::success( self::format_project( $project ) );
	}

	public static function create_project( \WP_REST_Request $req ) {
		$name = trim( (string) $req->get_param( 'name' ) );
		if ( $name === '' ) {
			return Rest_Response::error( 'invalid_input', __( 'name is required.', 'sfs-hr' ), 400 );
		}

		$data = self::sanitize_project_data( $req, true );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}

		$id = Projects_Service::insert( $data );
		if ( ! $id ) {
			return Rest_Response::error( 'insert_failed', __( 'Failed to create project.', 'sfs-hr' ), 500 );
		}

		return Rest_Response::success( self::format_project( Projects_Service::get( $id ) ), 201 );
	}

	public static function update_project( \WP_REST_Request $req ) {
		$id      = (int) $req['id'];
		$project = Projects_Service::get( $id );
		if ( ! $project ) {
			return Rest_Response::error( 'not_found', __( 'Project not found.', 'sfs-hr' ), 404 );
		}

		$data = self::sanitize_project_data( $req, false );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		if ( empty( $data ) ) {
			return Rest_Response::error( 'invalid_input', __( 'No fields to update.', 'sfs-hr' ), 400 );
		}

		$ok = Projects_Service::update( $id, $data );
		if ( ! $ok ) {
			return Rest_Response::error( 'update_failed', __( 'Could not update project.', 'sfs-hr' ), 500 );
		}

		return Rest_Response::success( self::format_project( Projects_Service::get( $id ) ) );
	}

	public static function delete_project( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! Projects_Service::get( $id ) ) {
			return Rest_Response::error( 'not_found', __( 'Project not found.', 'sfs-hr' ), 404 );
		}

		$ok = Projects_Service::delete( $id );
		if ( ! $ok ) {
			return Rest_Response::error( 'delete_failed', __( 'Could not delete project.', 'sfs-hr' ), 500 );
		}

		return Rest_Response::success( [ 'deleted' => true, 'id' => $id ] );
	}

	/* ───────── Assignment handlers ───────── */

	public static function list_assignments( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! Projects_Service::get( $id ) ) {
			return Rest_Response::error( 'not_found', __( 'Project not found.', 'sfs-hr' ), 404 );
		}

		$on_date = trim( (string) $req->get_param( 'on_date' ) );
		if ( $on_date !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $on_date ) ) {
			return Rest_Response::error( 'invalid_on_date', __( 'on_date must be YYYY-MM-DD.', 'sfs-hr' ), 400 );
		}

		$rows = Projects_Service::get_project_employees( $id, $on_date );
		$data = array_map( [ self::class, 'format_assignment' ], $rows ?: [] );
		return Rest_Response::success( $data );
	}

	public static function assign_employee( \WP_REST_Request $req ) {
		$project_id  = (int) $req['id'];
		$employee_id = (int) $req->get_param( 'employee_id' );
		$from        = (string) $req->get_param( 'assigned_from' );
		$to          = $req->get_param( 'assigned_to' );
		$to          = $to !== null && $to !== '' ? (string) $to : null;

		if ( ! Projects_Service::get( $project_id ) ) {
			return Rest_Response::error( 'not_found', __( 'Project not found.', 'sfs-hr' ), 404 );
		}
		if ( $employee_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			return Rest_Response::error( 'invalid_input', __( 'employee_id and assigned_from (YYYY-MM-DD) are required.', 'sfs-hr' ), 400 );
		}
		if ( $to !== null && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			return Rest_Response::error( 'invalid_input', __( 'assigned_to must be YYYY-MM-DD or null.', 'sfs-hr' ), 400 );
		}
		if ( $to !== null && strcmp( $to, $from ) < 0 ) {
			return Rest_Response::error( 'invalid_input', __( 'assigned_to cannot be before assigned_from.', 'sfs-hr' ), 400 );
		}

		// Ensure the employee exists.
		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d",
			$employee_id
		) );
		if ( ! $exists ) {
			return Rest_Response::error( 'invalid_employee', __( 'Employee not found.', 'sfs-hr' ), 400 );
		}

		$assignment_id = Projects_Service::assign_employee( $project_id, $employee_id, $from, $to );
		if ( ! $assignment_id ) {
			return Rest_Response::error( 'insert_failed', __( 'Failed to assign employee.', 'sfs-hr' ), 500 );
		}

		$assignment = Projects_Service::get_assignment( $assignment_id );
		return Rest_Response::success( self::format_assignment( $assignment ), 201 );
	}

	public static function remove_assignment( \WP_REST_Request $req ) {
		$assignment_id = (int) $req['assignment_id'];
		if ( ! Projects_Service::get_assignment( $assignment_id ) ) {
			return Rest_Response::error( 'not_found', __( 'Assignment not found.', 'sfs-hr' ), 404 );
		}

		$ok = Projects_Service::remove_employee_assignment( $assignment_id );
		if ( ! $ok ) {
			return Rest_Response::error( 'delete_failed', __( 'Could not remove assignment.', 'sfs-hr' ), 500 );
		}

		return Rest_Response::success( [ 'deleted' => true, 'id' => $assignment_id ] );
	}

	/* ───────── Shift link handlers ───────── */

	public static function list_shifts( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! Projects_Service::get( $id ) ) {
			return Rest_Response::error( 'not_found', __( 'Project not found.', 'sfs-hr' ), 404 );
		}

		$rows = Projects_Service::get_project_shifts( $id );
		$data = array_map( [ self::class, 'format_shift_link' ], $rows ?: [] );
		return Rest_Response::success( $data );
	}

	public static function link_shift( \WP_REST_Request $req ) {
		$project_id = (int) $req['id'];
		$shift_id   = (int) $req->get_param( 'shift_id' );
		$is_default = (bool) $req->get_param( 'is_default' );

		if ( ! Projects_Service::get( $project_id ) ) {
			return Rest_Response::error( 'not_found', __( 'Project not found.', 'sfs-hr' ), 404 );
		}
		if ( $shift_id <= 0 ) {
			return Rest_Response::error( 'invalid_input', __( 'shift_id is required.', 'sfs-hr' ), 400 );
		}

		// Ensure the shift exists.
		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_attendance_shifts WHERE id = %d",
			$shift_id
		) );
		if ( ! $exists ) {
			return Rest_Response::error( 'invalid_shift', __( 'Shift not found.', 'sfs-hr' ), 400 );
		}

		$link_id = Projects_Service::add_shift( $project_id, $shift_id, $is_default );
		if ( ! $link_id ) {
			return Rest_Response::error( 'insert_failed', __( 'Failed to link shift. It may already be linked.', 'sfs-hr' ), 409 );
		}

		$link = Projects_Service::get_shift_link( $link_id );
		return Rest_Response::success( self::format_shift_link( $link ), 201 );
	}

	public static function unlink_shift( \WP_REST_Request $req ) {
		$link_id = (int) $req['link_id'];
		if ( ! Projects_Service::get_shift_link( $link_id ) ) {
			return Rest_Response::error( 'not_found', __( 'Shift link not found.', 'sfs-hr' ), 404 );
		}

		$ok = Projects_Service::remove_shift( $link_id );
		if ( ! $ok ) {
			return Rest_Response::error( 'delete_failed', __( 'Could not unlink shift.', 'sfs-hr' ), 500 );
		}

		return Rest_Response::success( [ 'deleted' => true, 'id' => $link_id ] );
	}

	/* ───────── Helpers ───────── */

	/**
	 * Shared validation + sanitization for create/update. On create, $require
	 * ensures name is present. Returns WP_Error on validation failure or the
	 * sanitized data array to pass to the service.
	 *
	 * @return array|\WP_Error
	 */
	private static function sanitize_project_data( \WP_REST_Request $req, bool $require ) {
		$data = [];

		if ( $require || $req->has_param( 'name' ) ) {
			$name = trim( (string) $req->get_param( 'name' ) );
			if ( $require && $name === '' ) {
				return Rest_Response::error( 'invalid_input', __( 'name cannot be empty.', 'sfs-hr' ), 400 );
			}
			if ( $name !== '' ) {
				$data['name'] = sanitize_text_field( $name );
			}
		}

		if ( $req->has_param( 'location_label' ) ) {
			$data['location_label'] = sanitize_text_field( (string) $req->get_param( 'location_label' ) );
		}

		foreach ( [ 'location_lat', 'location_lng' ] as $f ) {
			if ( $req->has_param( $f ) ) {
				$v         = $req->get_param( $f );
				$data[ $f ] = ( $v === null || $v === '' ) ? null : (float) $v;
			}
		}

		if ( $req->has_param( 'location_radius_m' ) ) {
			$data['location_radius_m'] = max( 0, (int) $req->get_param( 'location_radius_m' ) );
		}

		foreach ( [ 'start_date', 'end_date' ] as $f ) {
			if ( $req->has_param( $f ) ) {
				$v = (string) $req->get_param( $f );
				if ( $v !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
					return Rest_Response::error( 'invalid_input', sprintf( __( '%s must be YYYY-MM-DD.', 'sfs-hr' ), $f ), 400 );
				}
				$data[ $f ] = $v === '' ? null : $v;
			}
		}

		if ( isset( $data['start_date'], $data['end_date'] )
			&& $data['start_date'] !== null
			&& $data['end_date'] !== null
			&& strcmp( $data['end_date'], $data['start_date'] ) < 0 ) {
			return Rest_Response::error( 'invalid_input', __( 'end_date cannot be before start_date.', 'sfs-hr' ), 400 );
		}

		if ( $req->has_param( 'manager_user_id' ) ) {
			$v                          = (int) $req->get_param( 'manager_user_id' );
			$data['manager_user_id']    = $v > 0 ? $v : null;
		}

		if ( $req->has_param( 'active' ) ) {
			$data['active'] = (bool) $req->get_param( 'active' ) ? 1 : 0;
		}

		if ( $req->has_param( 'notes' ) ) {
			$data['notes'] = sanitize_textarea_field( (string) $req->get_param( 'notes' ) );
		}

		return $data;
	}

	private static function format_project( \stdClass $p ): array {
		return [
			'id'                => (int) $p->id,
			'name'              => (string) $p->name,
			'location_label'    => $p->location_label,
			'location_lat'      => $p->location_lat !== null ? (float) $p->location_lat : null,
			'location_lng'      => $p->location_lng !== null ? (float) $p->location_lng : null,
			'location_radius_m' => $p->location_radius_m !== null ? (int) $p->location_radius_m : null,
			'start_date'        => $p->start_date,
			'end_date'          => $p->end_date,
			'manager_user_id'   => $p->manager_user_id !== null ? (int) $p->manager_user_id : null,
			'active'            => (bool) (int) $p->active,
			'notes'             => $p->notes,
			'created_at'        => $p->created_at,
			'updated_at'        => $p->updated_at,
		];
	}

	private static function format_assignment( $row ): array {
		$row = (array) $row;
		return [
			'id'            => (int) $row['id'],
			'project_id'    => (int) $row['project_id'],
			'employee_id'   => (int) $row['employee_id'],
			'employee_name' => trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) ),
			'employee_code' => $row['employee_code'] ?? null,
			'dept_id'       => isset( $row['dept_id'] ) && $row['dept_id'] !== null ? (int) $row['dept_id'] : null,
			'assigned_from' => $row['assigned_from'] ?? null,
			'assigned_to'   => $row['assigned_to'] ?? null,
			'created_at'    => $row['created_at'] ?? null,
			'created_by'    => isset( $row['created_by'] ) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
		];
	}

	private static function format_shift_link( $row ): array {
		$row = (array) $row;
		return [
			'id'                => (int) $row['id'],
			'project_id'        => (int) $row['project_id'],
			'shift_id'          => (int) $row['shift_id'],
			'shift_name'        => $row['shift_name'] ?? null,
			'start_time'        => $row['start_time'] ?? null,
			'end_time'          => $row['end_time'] ?? null,
			'location_label'    => $row['location_label'] ?? null,
			'location_lat'      => isset( $row['location_lat'] ) && $row['location_lat'] !== null ? (float) $row['location_lat'] : null,
			'location_lng'      => isset( $row['location_lng'] ) && $row['location_lng'] !== null ? (float) $row['location_lng'] : null,
			'location_radius_m' => isset( $row['location_radius_m'] ) && $row['location_radius_m'] !== null ? (int) $row['location_radius_m'] : null,
			'is_default'        => (bool) (int) ( $row['is_default'] ?? 0 ),
			'created_at'        => $row['created_at'] ?? null,
		];
	}
}
