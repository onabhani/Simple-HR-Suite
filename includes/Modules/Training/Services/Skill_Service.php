<?php
namespace SFS\HR\Modules\Training\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Skill_Service (M11.3)
 *
 * Manages the skill catalog, role-skill requirements, and per-employee
 * skill ratings. Used by IDP_Service for gap analysis.
 *
 * Proficiency scale: 1 (novice) → 5 (expert).
 *
 * Tables:
 *   - sfs_hr_skills           — catalog
 *   - sfs_hr_role_skills      — required skills per role with target level
 *   - sfs_hr_employee_skills  — employee proficiency (self + manager rated)
 *
 * @since M11.3
 */
class Skill_Service {

	/* ── Catalog ── */

	public static function list_skills( ?string $category = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_skills';

		if ( $category ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE category = %s ORDER BY name ASC", $category ),
				ARRAY_A
			) ?: [];
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY category, name ASC", ARRAY_A ) ?: [];
	}

	public static function get_skill( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sfs_hr_skills WHERE id = %d",
			$id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function create_skill( array $data ): int {
		global $wpdb;
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( $name === '' ) {
			return 0;
		}
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( $wpdb->prefix . 'sfs_hr_skills', [
			'name'        => sanitize_text_field( $name ),
			'category'    => isset( $data['category'] ) ? sanitize_key( (string) $data['category'] ) : null,
			'description' => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : null,
			'created_at'  => $now,
			'updated_at'  => $now,
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_skill( int $id, array $data ): bool {
		global $wpdb;
		$set = [];
		if ( isset( $data['name'] ) ) {
			$name = trim( (string) $data['name'] );
			if ( $name === '' ) {
				return false;
			}
			$set['name'] = sanitize_text_field( $name );
		}
		if ( array_key_exists( 'category', $data ) ) {
			$set['category'] = $data['category'] !== null ? sanitize_key( (string) $data['category'] ) : null;
		}
		if ( array_key_exists( 'description', $data ) ) {
			$set['description'] = $data['description'] !== null ? sanitize_textarea_field( (string) $data['description'] ) : null;
		}
		if ( empty( $set ) ) {
			return false;
		}
		$set['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( $wpdb->prefix . 'sfs_hr_skills', $set, [ 'id' => $id ] ) !== false;
	}

	public static function delete_skill( int $id ): bool {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->delete( $wpdb->prefix . 'sfs_hr_role_skills', [ 'skill_id' => $id ] );
		$wpdb->delete( $wpdb->prefix . 'sfs_hr_employee_skills', [ 'skill_id' => $id ] );
		$result = $wpdb->delete( $wpdb->prefix . 'sfs_hr_skills', [ 'id' => $id ] );
		if ( $result === false || $result < 1 ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}

	/* ── Role requirements ── */

	public static function list_for_role( string $role ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT rs.*, s.name AS skill_name, s.category AS skill_category, s.description AS skill_description
			 FROM {$wpdb->prefix}sfs_hr_role_skills rs
			 INNER JOIN {$wpdb->prefix}sfs_hr_skills s ON s.id = rs.skill_id
			 WHERE rs.role = %s
			 ORDER BY rs.required_level DESC, s.name ASC",
			$role
		), ARRAY_A ) ?: [];
	}

	public static function set_role_skill( string $role, int $skill_id, int $required_level = 3, bool $mandatory = true ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_role_skills';
		$now   = current_time( 'mysql' );

		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE role = %s AND skill_id = %d",
			$role,
			$skill_id
		) );

		$level = max( 1, min( 5, $required_level ) );

		if ( $existing ) {
			$wpdb->update( $table, [
				'required_level' => $level,
				'mandatory'      => $mandatory ? 1 : 0,
				'updated_at'     => $now,
			], [ 'id' => $existing ] );
			return $existing;
		}

		$ok = $wpdb->insert( $table, [
			'role'           => sanitize_key( $role ),
			'skill_id'       => $skill_id,
			'required_level' => $level,
			'mandatory'      => $mandatory ? 1 : 0,
			'created_at'     => $now,
			'updated_at'     => $now,
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function remove_role_skill( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $wpdb->prefix . 'sfs_hr_role_skills', [ 'id' => $id ] );
	}

	/* ── Employee skills ── */

	public static function list_for_employee( int $employee_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT es.*, s.name AS skill_name, s.category AS skill_category
			 FROM {$wpdb->prefix}sfs_hr_employee_skills es
			 INNER JOIN {$wpdb->prefix}sfs_hr_skills s ON s.id = es.skill_id
			 WHERE es.employee_id = %d
			 ORDER BY s.category, s.name ASC",
			$employee_id
		), ARRAY_A ) ?: [];
	}

	/**
	 * Set or update the proficiency rating for an employee+skill pair.
	 *
	 * @param int      $employee_id
	 * @param int      $skill_id
	 * @param int|null $self_rating    1-5, or null to leave unchanged
	 * @param int|null $manager_rating 1-5, or null to leave unchanged
	 * @param string   $notes
	 * @return int Row ID (existing or newly inserted), 0 on failure.
	 */
	public static function set_employee_skill( int $employee_id, int $skill_id, ?int $self_rating = null, ?int $manager_rating = null, string $notes = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_employee_skills';
		$now   = current_time( 'mysql' );

		$clamp = function ( $v ) {
			if ( $v === null ) return null;
			$v = (int) $v;
			return max( 1, min( 5, $v ) );
		};

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, self_rating, manager_rating FROM {$table} WHERE employee_id = %d AND skill_id = %d",
			$employee_id,
			$skill_id
		) );

		$self_val    = $self_rating !== null ? $clamp( $self_rating ) : ( $existing ? $existing->self_rating : null );
		$manager_val = $manager_rating !== null ? $clamp( $manager_rating ) : ( $existing ? $existing->manager_rating : null );

		if ( $existing ) {
			$wpdb->update( $table, [
				'self_rating'    => $self_val,
				'manager_rating' => $manager_val,
				'notes'          => $notes !== '' ? sanitize_textarea_field( $notes ) : ( $existing->notes ?? null ),
				'last_assessed'  => current_time( 'Y-m-d' ),
				'updated_at'     => $now,
			], [ 'id' => $existing->id ] );
			return (int) $existing->id;
		}

		$ok = $wpdb->insert( $table, [
			'employee_id'    => $employee_id,
			'skill_id'       => $skill_id,
			'self_rating'    => $self_val,
			'manager_rating' => $manager_val,
			'last_assessed'  => current_time( 'Y-m-d' ),
			'notes'          => $notes !== '' ? sanitize_textarea_field( $notes ) : null,
			'created_at'     => $now,
			'updated_at'     => $now,
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Resolve the effective proficiency for an employee skill row.
	 * Manager rating takes precedence; falls back to self; null if unrated.
	 */
	public static function effective_rating( ?array $row ): ?int {
		if ( ! $row ) {
			return null;
		}
		if ( isset( $row['manager_rating'] ) && $row['manager_rating'] !== null ) {
			return (int) $row['manager_rating'];
		}
		if ( isset( $row['self_rating'] ) && $row['self_rating'] !== null ) {
			return (int) $row['self_rating'];
		}
		return null;
	}
}
