<?php
namespace SFS\HR\Modules\Training\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IDP_Service (M11.3)
 *
 * Individual Development Plans + skill-gap analysis.
 *
 * An IDP is a periodic plan (typically annual) tying an employee's
 * development goals to concrete items — skill targets, training
 * requests, milestones with target dates. Items can optionally
 * reference a skill_id (linking to the skill catalog) and a
 * training_request_id (linking to an existing training request).
 *
 * Skill-gap analysis compares the role's required skills (with target
 * proficiency levels) against the employee's current effective
 * proficiency (manager rating preferred over self rating). The output
 * powers IDP item suggestions.
 *
 * @since M11.3
 */
class IDP_Service {

	const STATUSES      = [ 'draft', 'active', 'completed', 'cancelled' ];
	const ITEM_STATUSES = [ 'pending', 'in_progress', 'completed', 'skipped' ];

	/* ── IDP CRUD ── */

	public static function list_idps( array $filters = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_idps';

		$where = [ '1=1' ];
		$args  = [];

		if ( ! empty( $filters['employee_id'] ) ) {
			$where[] = 'employee_id = %d';
			$args[]  = (int) $filters['employee_id'];
		}

		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC";
		$rows      = empty( $args )
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return $rows ?: [];
	}

	public static function get_idp( int $id, bool $with_items = false ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sfs_hr_idps WHERE id = %d",
			$id
		), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		if ( $with_items ) {
			$row['items'] = self::list_items( $id );
		}
		return $row;
	}

	public static function create_idp( array $data ): int {
		global $wpdb;

		$employee_id = (int) ( $data['employee_id'] ?? 0 );
		$title       = trim( (string) ( $data['title'] ?? '' ) );
		if ( $employee_id <= 0 || $title === '' ) {
			return 0;
		}

		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( $wpdb->prefix . 'sfs_hr_idps', [
			'employee_id'  => $employee_id,
			'title'        => sanitize_text_field( $title ),
			'objective'    => isset( $data['objective'] ) ? sanitize_textarea_field( (string) $data['objective'] ) : null,
			'period_start' => self::sanitize_date( $data['period_start'] ?? null ),
			'period_end'   => self::sanitize_date( $data['period_end'] ?? null ),
			'status'       => self::sanitize_status( $data['status'] ?? 'draft' ),
			'created_by'   => get_current_user_id() ?: null,
			'created_at'   => $now,
			'updated_at'   => $now,
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_idp( int $id, array $data ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_idps';

		$set = [];
		if ( isset( $data['title'] ) ) {
			$title = trim( (string) $data['title'] );
			if ( $title === '' ) {
				return false;
			}
			$set['title'] = sanitize_text_field( $title );
		}
		if ( array_key_exists( 'objective', $data ) ) {
			$set['objective'] = $data['objective'] !== null ? sanitize_textarea_field( (string) $data['objective'] ) : null;
		}
		if ( array_key_exists( 'period_start', $data ) ) {
			$set['period_start'] = self::sanitize_date( $data['period_start'] );
		}
		if ( array_key_exists( 'period_end', $data ) ) {
			$set['period_end'] = self::sanitize_date( $data['period_end'] );
		}
		if ( isset( $data['status'] ) ) {
			$set['status'] = self::sanitize_status( $data['status'] );
		}

		if ( empty( $set ) ) {
			return false;
		}
		$set['updated_at'] = current_time( 'mysql' );

		return $wpdb->update( $table, $set, [ 'id' => $id ] ) !== false;
	}

	public static function approve_idp( int $id ): bool {
		global $wpdb;
		$now = current_time( 'mysql' );
		return $wpdb->update(
			$wpdb->prefix . 'sfs_hr_idps',
			[
				'status'      => 'active',
				'approved_by' => get_current_user_id() ?: null,
				'approved_at' => $now,
				'updated_at'  => $now,
			],
			[ 'id' => $id ]
		) !== false;
	}

	public static function delete_idp( int $id ): bool {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->delete( $wpdb->prefix . 'sfs_hr_idp_items', [ 'idp_id' => $id ] );
		$result = $wpdb->delete( $wpdb->prefix . 'sfs_hr_idps', [ 'id' => $id ] );
		if ( $result === false || $result < 1 ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}

	/* ── IDP items ── */

	public static function list_items( int $idp_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT i.*, s.name AS skill_name, s.category AS skill_category
			 FROM {$wpdb->prefix}sfs_hr_idp_items i
			 LEFT JOIN {$wpdb->prefix}sfs_hr_skills s ON s.id = i.skill_id
			 WHERE i.idp_id = %d
			 ORDER BY i.target_date IS NULL ASC, i.target_date ASC, i.id ASC",
			$idp_id
		), ARRAY_A ) ?: [];
	}

	public static function add_item( int $idp_id, array $data ): int {
		global $wpdb;

		$description = trim( (string) ( $data['description'] ?? '' ) );
		if ( $description === '' ) {
			return 0;
		}

		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( $wpdb->prefix . 'sfs_hr_idp_items', [
			'idp_id'              => $idp_id,
			'skill_id'            => isset( $data['skill_id'] ) && $data['skill_id'] ? (int) $data['skill_id'] : null,
			'description'         => sanitize_textarea_field( $description ),
			'target_level'        => isset( $data['target_level'] ) && $data['target_level'] !== null
				? max( 1, min( 5, (int) $data['target_level'] ) )
				: null,
			'training_request_id' => isset( $data['training_request_id'] ) && $data['training_request_id'] ? (int) $data['training_request_id'] : null,
			'target_date'         => self::sanitize_date( $data['target_date'] ?? null ),
			'status'              => self::sanitize_item_status( $data['status'] ?? 'pending' ),
			'progress_pct'        => isset( $data['progress_pct'] ) ? max( 0, min( 100, (int) $data['progress_pct'] ) ) : 0,
			'notes'               => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
			'created_at'          => $now,
			'updated_at'          => $now,
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_item( int $item_id, array $data ): bool {
		global $wpdb;

		$set = [];
		if ( array_key_exists( 'description', $data ) ) {
			$d = trim( (string) $data['description'] );
			if ( $d === '' ) {
				return false;
			}
			$set['description'] = sanitize_textarea_field( $d );
		}
		if ( array_key_exists( 'skill_id', $data ) ) {
			$set['skill_id'] = $data['skill_id'] ? (int) $data['skill_id'] : null;
		}
		if ( array_key_exists( 'target_level', $data ) ) {
			$set['target_level'] = $data['target_level'] !== null ? max( 1, min( 5, (int) $data['target_level'] ) ) : null;
		}
		if ( array_key_exists( 'training_request_id', $data ) ) {
			$set['training_request_id'] = $data['training_request_id'] ? (int) $data['training_request_id'] : null;
		}
		if ( array_key_exists( 'target_date', $data ) ) {
			$set['target_date'] = self::sanitize_date( $data['target_date'] );
		}
		if ( isset( $data['status'] ) ) {
			$set['status'] = self::sanitize_item_status( $data['status'] );
			if ( $set['status'] === 'completed' ) {
				$set['completed_at'] = current_time( 'mysql' );
				$set['progress_pct'] = 100;
			}
		}
		if ( array_key_exists( 'progress_pct', $data ) ) {
			$set['progress_pct'] = max( 0, min( 100, (int) $data['progress_pct'] ) );
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$set['notes'] = $data['notes'] !== null ? sanitize_textarea_field( (string) $data['notes'] ) : null;
		}

		if ( empty( $set ) ) {
			return false;
		}
		$set['updated_at'] = current_time( 'mysql' );

		return $wpdb->update( $wpdb->prefix . 'sfs_hr_idp_items', $set, [ 'id' => $item_id ] ) !== false;
	}

	public static function delete_item( int $item_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $wpdb->prefix . 'sfs_hr_idp_items', [ 'id' => $item_id ] );
	}

	public static function get_item( int $item_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sfs_hr_idp_items WHERE id = %d",
			$item_id
		), ARRAY_A );
		return $row ?: null;
	}

	/* ── Skill-gap analysis ── */

	/**
	 * Compute the gap between a role's required skills and an employee's
	 * effective proficiency.
	 *
	 *   - employee skills with no rating → effective = 0 (full gap)
	 *   - effective = max(manager_rating, self_rating) when both exist;
	 *     manager preferred when only one is present (Skill_Service decides)
	 *
	 * Returns one row per required skill, sorted by gap descending so the
	 * largest gaps surface first. A non-zero gap means development needed.
	 *
	 * @return array<int, array{
	 *     skill_id: int,
	 *     skill_name: string,
	 *     skill_category: ?string,
	 *     required_level: int,
	 *     mandatory: bool,
	 *     current_level: ?int,
	 *     gap: int,
	 * }>
	 */
	public static function get_employee_skill_gaps( int $employee_id, ?string $role = null ): array {
		// Resolve role from employee record if not provided.
		if ( $role === null ) {
			$role = self::resolve_employee_role( $employee_id );
		}
		if ( ! $role ) {
			return [];
		}

		$role_skills = Skill_Service::list_for_role( $role );
		if ( ! $role_skills ) {
			return [];
		}

		$emp_rows = Skill_Service::list_for_employee( $employee_id );
		$by_skill = [];
		foreach ( $emp_rows as $r ) {
			$by_skill[ (int) $r['skill_id'] ] = $r;
		}

		$gaps = [];
		foreach ( $role_skills as $rs ) {
			$skill_id     = (int) $rs['skill_id'];
			$required     = (int) $rs['required_level'];
			$current_row  = $by_skill[ $skill_id ] ?? null;
			$current      = Skill_Service::effective_rating( $current_row );
			$current_safe = $current ?? 0;
			$gap          = max( 0, $required - $current_safe );

			$gaps[] = [
				'skill_id'       => $skill_id,
				'skill_name'     => (string) $rs['skill_name'],
				'skill_category' => $rs['skill_category'] ?? null,
				'required_level' => $required,
				'mandatory'      => (bool) (int) $rs['mandatory'],
				'current_level'  => $current,
				'gap'            => $gap,
			];
		}

		usort( $gaps, fn( $a, $b ) => $b['gap'] <=> $a['gap'] );
		return $gaps;
	}

	/* ── Helpers ── */

	private static function resolve_employee_role( int $employee_id ): ?string {
		global $wpdb;
		$user_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d",
			$employee_id
		) );
		if ( $user_id <= 0 ) {
			return null;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return null;
		}
		// Pick the most-specific (last) role.
		return (string) end( $user->roles );
	}

	private static function sanitize_date( $value ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : null;
	}

	private static function sanitize_status( $value ): string {
		$v = (string) $value;
		return in_array( $v, self::STATUSES, true ) ? $v : 'draft';
	}

	private static function sanitize_item_status( $value ): string {
		$v = (string) $value;
		return in_array( $v, self::ITEM_STATUSES, true ) ? $v : 'pending';
	}
}
