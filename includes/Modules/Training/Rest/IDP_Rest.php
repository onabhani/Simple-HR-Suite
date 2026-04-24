<?php
namespace SFS\HR\Modules\Training\Rest;

use SFS\HR\Core\Rest\Rest_Response;
use SFS\HR\Modules\Training\Services\IDP_Service;
use SFS\HR\Modules\Training\Services\Skill_Service;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IDP_Rest (M11.3)
 *
 * REST surface for skills, role requirements, employee skill ratings,
 * Individual Development Plans, IDP items, and skill-gap analysis.
 *
 * @since M11.3
 */
class IDP_Rest {

	const NS = 'sfs-hr/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$ns = self::NS;

		/* ─── Skill catalog ─── */

		register_rest_route( $ns, '/skills', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_skills' ],
				'permission_callback' => [ self::class, 'can_view' ],
				'args'                => [
					'category' => [ 'type' => 'string' ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'create_skill' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'name'        => [ 'type' => 'string', 'required' => true ],
					'category'    => [ 'type' => 'string' ],
					'description' => [ 'type' => 'string' ],
				],
			],
		] );

		register_rest_route( $ns, '/skills/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_skill' ],
				'permission_callback' => [ self::class, 'can_view' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ self::class, 'update_skill' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'delete_skill' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			],
		] );

		/* ─── Role requirements ─── */

		register_rest_route( $ns, '/skills/role/(?P<role>[a-z0-9_\-]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_role_skills' ],
				'permission_callback' => [ self::class, 'can_view' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'set_role_skill' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'skill_id'       => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
					'required_level' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 3 ],
					'mandatory'      => [ 'type' => 'boolean', 'default' => true ],
				],
			],
		] );

		register_rest_route( $ns, '/skills/role/requirements/(?P<id>\d+)', [
			'methods'             => 'DELETE',
			'callback'            => [ self::class, 'remove_role_skill' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );

		/* ─── Employee skills ─── */

		register_rest_route( $ns, '/skills/employee/(?P<employee_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'list_employee_skills' ],
			'permission_callback' => [ self::class, 'can_view_employee' ],
		] );

		register_rest_route( $ns, '/skills/employee/(?P<employee_id>\d+)/rate', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'rate_employee_skill' ],
			'permission_callback' => [ self::class, 'can_rate_employee' ],
			'args'                => [
				'skill_id'       => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
				'self_rating'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ],
				'manager_rating' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ],
				'notes'          => [ 'type' => 'string' ],
			],
		] );

		/* ─── IDPs ─── */

		register_rest_route( $ns, '/idps', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_idps' ],
				'permission_callback' => [ self::class, 'can_view' ],
				'args'                => [
					'employee_id' => [ 'type' => 'integer' ],
					'status'      => [ 'type' => 'string', 'enum' => IDP_Service::STATUSES ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'create_idp' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'employee_id'  => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
					'title'        => [ 'type' => 'string',  'required' => true ],
					'objective'    => [ 'type' => 'string' ],
					'period_start' => [ 'type' => 'string' ],
					'period_end'   => [ 'type' => 'string' ],
					'status'       => [ 'type' => 'string', 'enum' => IDP_Service::STATUSES ],
				],
			],
		] );

		register_rest_route( $ns, '/idps/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_idp' ],
				'permission_callback' => [ self::class, 'can_view_idp' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ self::class, 'update_idp' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'delete_idp' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			],
		] );

		register_rest_route( $ns, '/idps/(?P<id>\d+)/approve', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'approve_idp' ],
			'permission_callback' => [ self::class, 'can_manage' ],
		] );

		/* ─── IDP items ─── */

		register_rest_route( $ns, '/idps/(?P<id>\d+)/items', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_items' ],
				'permission_callback' => [ self::class, 'can_view_idp' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'add_item' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'description'         => [ 'type' => 'string', 'required' => true ],
					'skill_id'            => [ 'type' => 'integer' ],
					'target_level'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ],
					'training_request_id' => [ 'type' => 'integer' ],
					'target_date'         => [ 'type' => 'string' ],
					'status'              => [ 'type' => 'string', 'enum' => IDP_Service::ITEM_STATUSES ],
					'notes'               => [ 'type' => 'string' ],
				],
			],
		] );

		register_rest_route( $ns, '/idps/items/(?P<item_id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ self::class, 'update_item' ],
				'permission_callback' => [ self::class, 'can_update_item' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'delete_item' ],
				'permission_callback' => [ self::class, 'can_manage' ],
			],
		] );

		/* ─── Skill-gap analysis ─── */

		register_rest_route( $ns, '/idps/skill-gaps/(?P<employee_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'skill_gaps' ],
			'permission_callback' => [ self::class, 'can_view_employee' ],
			'args'                => [
				'role' => [ 'type' => 'string' ],
			],
		] );
	}

	/* ───────── Permission callbacks ───────── */

	public static function can_view(): bool {
		return is_user_logged_in() && (
			current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr.view' )
		);
	}

	public static function can_manage(): bool {
		return current_user_can( 'sfs_hr.manage' );
	}

	/**
	 * Reading skills/IDP for an employee: admin OR the employee themself.
	 */
	public static function can_view_employee( \WP_REST_Request $req ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'sfs_hr.manage' ) ) {
			return true;
		}
		$emp_id = (int) $req['employee_id'];
		return self::is_owner( $emp_id );
	}

	/**
	 * Updating an item: admin always. Employees only when the item belongs
	 * to one of their own IDPs (so they can mark progress on their plan).
	 */
	public static function can_update_item( \WP_REST_Request $req ): bool {
		if ( current_user_can( 'sfs_hr.manage' ) ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$item = IDP_Service::get_item( (int) $req['item_id'] );
		if ( ! $item ) {
			return false;
		}
		$idp = IDP_Service::get_idp( (int) $item['idp_id'] );
		return $idp && self::is_owner( (int) $idp['employee_id'] );
	}

	public static function can_view_idp( \WP_REST_Request $req ): bool {
		if ( current_user_can( 'sfs_hr.manage' ) ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$idp = IDP_Service::get_idp( (int) $req['id'] );
		return $idp && self::is_owner( (int) $idp['employee_id'] );
	}

	/**
	 * Rating an employee skill: admin can manager-rate; employee can self-rate
	 * their own skills only.
	 */
	public static function can_rate_employee( \WP_REST_Request $req ): bool {
		if ( current_user_can( 'sfs_hr.manage' ) ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		// Self-rating only — block manager_rating param for non-admins
		// (handler scrubs it; permission gate just verifies ownership).
		return self::is_owner( (int) $req['employee_id'] );
	}

	/* ───────── Skill catalog ───────── */

	public static function list_skills( \WP_REST_Request $req ) {
		$category = $req->get_param( 'category' );
		$category = $category !== null && $category !== '' ? sanitize_key( (string) $category ) : null;
		return Rest_Response::success( Skill_Service::list_skills( $category ) );
	}

	public static function get_skill( \WP_REST_Request $req ) {
		$skill = Skill_Service::get_skill( (int) $req['id'] );
		if ( ! $skill ) {
			return Rest_Response::error( 'not_found', __( 'Skill not found.', 'sfs-hr' ), 404 );
		}
		return Rest_Response::success( $skill );
	}

	public static function create_skill( \WP_REST_Request $req ) {
		$id = Skill_Service::create_skill( [
			'name'        => $req->get_param( 'name' ),
			'category'    => $req->get_param( 'category' ),
			'description' => $req->get_param( 'description' ),
		] );
		if ( ! $id ) {
			return Rest_Response::error( 'invalid_input', __( 'Failed to create skill — name may be missing or duplicate.', 'sfs-hr' ), 400 );
		}
		return Rest_Response::success( Skill_Service::get_skill( $id ), 201 );
	}

	public static function update_skill( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! Skill_Service::get_skill( $id ) ) {
			return Rest_Response::error( 'not_found', __( 'Skill not found.', 'sfs-hr' ), 404 );
		}
		$ok = Skill_Service::update_skill( $id, $req->get_params() );
		if ( ! $ok ) {
			return Rest_Response::error( 'update_failed', __( 'Could not update skill.', 'sfs-hr' ), 400 );
		}
		return Rest_Response::success( Skill_Service::get_skill( $id ) );
	}

	public static function delete_skill( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! Skill_Service::get_skill( $id ) ) {
			return Rest_Response::error( 'not_found', __( 'Skill not found.', 'sfs-hr' ), 404 );
		}
		$ok = Skill_Service::delete_skill( $id );
		if ( ! $ok ) {
			return Rest_Response::error( 'delete_failed', __( 'Could not delete skill.', 'sfs-hr' ), 500 );
		}
		return Rest_Response::success( [ 'deleted' => true, 'id' => $id ] );
	}

	/* ───────── Role requirements ───────── */

	public static function list_role_skills( \WP_REST_Request $req ) {
		$role = sanitize_key( (string) $req['role'] );
		return Rest_Response::success( Skill_Service::list_for_role( $role ) );
	}

	public static function set_role_skill( \WP_REST_Request $req ) {
		$role     = sanitize_key( (string) $req['role'] );
		$skill_id = (int) $req->get_param( 'skill_id' );
		$level    = (int) ( $req->get_param( 'required_level' ) ?? 3 );
		$mand     = $req->get_param( 'mandatory' );
		$mand     = $mand === null ? true : (bool) $mand;

		if ( ! Skill_Service::get_skill( $skill_id ) ) {
			return Rest_Response::error( 'invalid_skill', __( 'Skill not found.', 'sfs-hr' ), 400 );
		}

		$id = Skill_Service::set_role_skill( $role, $skill_id, $level, $mand );
		if ( ! $id ) {
			return Rest_Response::error( 'insert_failed', __( 'Failed to set role skill.', 'sfs-hr' ), 500 );
		}
		return Rest_Response::success( [ 'id' => $id, 'role' => $role, 'skill_id' => $skill_id, 'required_level' => $level, 'mandatory' => $mand ], 201 );
	}

	public static function remove_role_skill( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$ok = Skill_Service::remove_role_skill( $id );
		if ( ! $ok ) {
			return Rest_Response::error( 'not_found', __( 'Role skill not found.', 'sfs-hr' ), 404 );
		}
		return Rest_Response::success( [ 'deleted' => true, 'id' => $id ] );
	}

	/* ───────── Employee skills ───────── */

	public static function list_employee_skills( \WP_REST_Request $req ) {
		$emp_id = (int) $req['employee_id'];
		return Rest_Response::success( Skill_Service::list_for_employee( $emp_id ) );
	}

	public static function rate_employee_skill( \WP_REST_Request $req ) {
		$emp_id   = (int) $req['employee_id'];
		$skill_id = (int) $req->get_param( 'skill_id' );
		$self     = $req->get_param( 'self_rating' );
		$manager  = $req->get_param( 'manager_rating' );
		$notes    = (string) $req->get_param( 'notes' );

		if ( ! Skill_Service::get_skill( $skill_id ) ) {
			return Rest_Response::error( 'invalid_skill', __( 'Skill not found.', 'sfs-hr' ), 400 );
		}

		// Non-admins can only set their own self_rating.
		if ( ! current_user_can( 'sfs_hr.manage' ) ) {
			$manager = null;
		}

		$id = Skill_Service::set_employee_skill(
			$emp_id,
			$skill_id,
			$self !== null ? (int) $self : null,
			$manager !== null ? (int) $manager : null,
			$notes
		);
		if ( ! $id ) {
			return Rest_Response::error( 'update_failed', __( 'Could not set rating.', 'sfs-hr' ), 500 );
		}
		return Rest_Response::success( [ 'id' => $id ] );
	}

	/* ───────── IDPs ───────── */

	public static function list_idps( \WP_REST_Request $req ) {
		$filters = [];
		$emp     = $req->get_param( 'employee_id' );
		if ( $emp !== null && $emp !== '' ) {
			$filters['employee_id'] = (int) $emp;
		}
		$status = $req->get_param( 'status' );
		if ( $status ) {
			$filters['status'] = (string) $status;
		}

		// Non-admins are scoped to their own IDPs.
		if ( ! current_user_can( 'sfs_hr.manage' ) ) {
			$own = self::current_employee_id();
			if ( ! $own ) {
				return Rest_Response::success( [] );
			}
			$filters['employee_id'] = $own;
		}

		return Rest_Response::success( IDP_Service::list_idps( $filters ) );
	}

	public static function get_idp( \WP_REST_Request $req ) {
		$idp = IDP_Service::get_idp( (int) $req['id'], true );
		if ( ! $idp ) {
			return Rest_Response::error( 'not_found', __( 'IDP not found.', 'sfs-hr' ), 404 );
		}
		return Rest_Response::success( $idp );
	}

	public static function create_idp( \WP_REST_Request $req ) {
		$id = IDP_Service::create_idp( [
			'employee_id'  => (int) $req->get_param( 'employee_id' ),
			'title'        => $req->get_param( 'title' ),
			'objective'    => $req->get_param( 'objective' ),
			'period_start' => $req->get_param( 'period_start' ),
			'period_end'   => $req->get_param( 'period_end' ),
			'status'       => $req->get_param( 'status' ),
		] );
		if ( ! $id ) {
			return Rest_Response::error( 'invalid_input', __( 'Failed to create IDP — employee_id and title are required.', 'sfs-hr' ), 400 );
		}
		return Rest_Response::success( IDP_Service::get_idp( $id, true ), 201 );
	}

	public static function update_idp( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! IDP_Service::get_idp( $id ) ) {
			return Rest_Response::error( 'not_found', __( 'IDP not found.', 'sfs-hr' ), 404 );
		}
		$ok = IDP_Service::update_idp( $id, $req->get_params() );
		if ( ! $ok ) {
			return Rest_Response::error( 'update_failed', __( 'Could not update IDP.', 'sfs-hr' ), 400 );
		}
		return Rest_Response::success( IDP_Service::get_idp( $id, true ) );
	}

	public static function approve_idp( \WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$idp = IDP_Service::get_idp( $id );
		if ( ! $idp ) {
			return Rest_Response::error( 'not_found', __( 'IDP not found.', 'sfs-hr' ), 404 );
		}
		if ( $idp['status'] !== 'draft' ) {
			return Rest_Response::error( 'invalid_state', __( 'Only draft IDPs can be approved.', 'sfs-hr' ), 409 );
		}
		IDP_Service::approve_idp( $id );
		return Rest_Response::success( IDP_Service::get_idp( $id, true ) );
	}

	public static function delete_idp( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! IDP_Service::get_idp( $id ) ) {
			return Rest_Response::error( 'not_found', __( 'IDP not found.', 'sfs-hr' ), 404 );
		}
		$ok = IDP_Service::delete_idp( $id );
		if ( ! $ok ) {
			return Rest_Response::error( 'delete_failed', __( 'Could not delete IDP.', 'sfs-hr' ), 500 );
		}
		return Rest_Response::success( [ 'deleted' => true, 'id' => $id ] );
	}

	/* ───────── Items ───────── */

	public static function list_items( \WP_REST_Request $req ) {
		$idp_id = (int) $req['id'];
		if ( ! IDP_Service::get_idp( $idp_id ) ) {
			return Rest_Response::error( 'not_found', __( 'IDP not found.', 'sfs-hr' ), 404 );
		}
		return Rest_Response::success( IDP_Service::list_items( $idp_id ) );
	}

	public static function add_item( \WP_REST_Request $req ) {
		$idp_id = (int) $req['id'];
		if ( ! IDP_Service::get_idp( $idp_id ) ) {
			return Rest_Response::error( 'not_found', __( 'IDP not found.', 'sfs-hr' ), 404 );
		}
		$id = IDP_Service::add_item( $idp_id, $req->get_params() );
		if ( ! $id ) {
			return Rest_Response::error( 'invalid_input', __( 'Failed to add item — description is required.', 'sfs-hr' ), 400 );
		}
		return Rest_Response::success( IDP_Service::get_item( $id ), 201 );
	}

	public static function update_item( \WP_REST_Request $req ) {
		$item_id = (int) $req['item_id'];
		$item    = IDP_Service::get_item( $item_id );
		if ( ! $item ) {
			return Rest_Response::error( 'not_found', __( 'Item not found.', 'sfs-hr' ), 404 );
		}

		$params = $req->get_params();

		// Non-admins (item owner via IDP) can only update progress/status/notes.
		if ( ! current_user_can( 'sfs_hr.manage' ) ) {
			$allowed = array_intersect_key( $params, array_flip( [ 'status', 'progress_pct', 'notes' ] ) );
			$params  = $allowed;
		}

		$ok = IDP_Service::update_item( $item_id, $params );
		if ( ! $ok ) {
			return Rest_Response::error( 'update_failed', __( 'Could not update item.', 'sfs-hr' ), 400 );
		}
		return Rest_Response::success( IDP_Service::get_item( $item_id ) );
	}

	public static function delete_item( \WP_REST_Request $req ) {
		$item_id = (int) $req['item_id'];
		if ( ! IDP_Service::get_item( $item_id ) ) {
			return Rest_Response::error( 'not_found', __( 'Item not found.', 'sfs-hr' ), 404 );
		}
		$ok = IDP_Service::delete_item( $item_id );
		if ( ! $ok ) {
			return Rest_Response::error( 'delete_failed', __( 'Could not delete item.', 'sfs-hr' ), 500 );
		}
		return Rest_Response::success( [ 'deleted' => true, 'id' => $item_id ] );
	}

	/* ───────── Skill-gap analysis ───────── */

	public static function skill_gaps( \WP_REST_Request $req ) {
		$emp_id = (int) $req['employee_id'];
		$role   = $req->get_param( 'role' );
		$role   = $role !== null && $role !== '' ? sanitize_key( (string) $role ) : null;

		$gaps = IDP_Service::get_employee_skill_gaps( $emp_id, $role );
		return Rest_Response::success( [
			'employee_id' => $emp_id,
			'role'        => $role,
			'gaps'        => $gaps,
		] );
	}

	/* ───────── Helpers ───────── */

	private static function current_employee_id(): ?int {
		global $wpdb;
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return null;
		}
		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d LIMIT 1",
			$uid
		) );
		return $id ? (int) $id : null;
	}

	private static function is_owner( int $employee_id ): bool {
		$own = self::current_employee_id();
		return $own !== null && $own === $employee_id;
	}
}
