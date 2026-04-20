<?php
namespace SFS\HR\Modules\Resignation\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SFS\HR\Modules\EmployeeExit\EmployeeExitModule;

/**
 * Offboarding Service
 *
 * Manages the offboarding workflow when an employee resigns:
 * template CRUD, task generation, progress tracking, and escalation.
 *
 * @since 1.10.0
 */
class Offboarding_Service {

	/* ─────────────────────────────────────────────
	 * Template CRUD
	 * ───────────────────────────────────────────── */

	/**
	 * Create an offboarding template.
	 *
	 * @param array $data {
	 *     @type string      $template_name  Required.
	 *     @type int|null    $dept_id        NULL = applies to all departments.
	 *     @type array       $tasks_json     Array of task definition objects.
	 *     @type bool        $is_active      Default true.
	 * }
	 * @return array { success: bool, id?: int, error?: string }
	 */
	public static function create_template( array $data ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_templates';

		$template_name = sanitize_text_field( $data['template_name'] ?? '' );
		if ( empty( $template_name ) ) {
			return [ 'success' => false, 'error' => __( 'Template name is required.', 'sfs-hr' ) ];
		}

		$tasks_json = $data['tasks_json'] ?? [];
		if ( ! is_array( $tasks_json ) ) {
			$tasks_json = json_decode( $tasks_json, true ) ?: [];
		}

		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert( $table, [
			'template_name' => $template_name,
			'dept_id'       => ! empty( $data['dept_id'] ) ? absint( $data['dept_id'] ) : null,
			'tasks_json'    => wp_json_encode( $tasks_json ),
			'is_active'     => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
			'created_at'    => $now,
			'updated_at'    => $now,
		], [ '%s', '%d', '%s', '%d', '%s', '%s' ] );

		if ( ! $inserted ) {
			return [ 'success' => false, 'error' => __( 'Failed to create template.', 'sfs-hr' ) ];
		}

		return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
	}

	/**
	 * Update an existing offboarding template.
	 *
	 * @param int   $id   Template ID.
	 * @param array $data Fields to update (template_name, dept_id, tasks_json, is_active).
	 * @return array { success: bool, error?: string }
	 */
	public static function update_template( int $id, array $data ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_templates';

		$update = [];
		$format = [];

		if ( isset( $data['template_name'] ) ) {
			$update['template_name'] = sanitize_text_field( $data['template_name'] );
			$format[] = '%s';
		}
		if ( array_key_exists( 'dept_id', $data ) ) {
			$update['dept_id'] = ! empty( $data['dept_id'] ) ? absint( $data['dept_id'] ) : null;
			$format[] = '%d';
		}
		if ( isset( $data['tasks_json'] ) ) {
			$tasks = is_array( $data['tasks_json'] ) ? $data['tasks_json'] : ( json_decode( $data['tasks_json'], true ) ?: [] );
			$update['tasks_json'] = wp_json_encode( $tasks );
			$format[] = '%s';
		}
		if ( isset( $data['is_active'] ) ) {
			$update['is_active'] = (int) (bool) $data['is_active'];
			$format[] = '%d';
		}

		if ( empty( $update ) ) {
			return [ 'success' => false, 'error' => __( 'No fields to update.', 'sfs-hr' ) ];
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[] = '%s';

		$result = $wpdb->update( $table, $update, [ 'id' => $id ], $format, [ '%d' ] );

		if ( false === $result ) {
			return [ 'success' => false, 'error' => __( 'Failed to update template.', 'sfs-hr' ) ];
		}

		return [ 'success' => true ];
	}

	/**
	 * Get a single offboarding template by ID.
	 *
	 * @param int $id Template ID.
	 * @return array|null Template row with tasks_json decoded, or null.
	 */
	public static function get_template( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_templates';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d",
			$id
		), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$row['tasks_json'] = json_decode( $row['tasks_json'], true ) ?: [];
		return $row;
	}

	/**
	 * List offboarding templates.
	 *
	 * @param bool $active_only If true, only return active templates.
	 * @return array List of template rows.
	 */
	public static function list_templates( bool $active_only = true ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_templates';

		$sql = "SELECT * FROM {$table}";
		if ( $active_only ) {
			$sql .= " WHERE is_active = 1";
		}
		$sql .= " ORDER BY template_name ASC";

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		foreach ( $rows as &$row ) {
			$row['tasks_json'] = json_decode( $row['tasks_json'], true ) ?: [];
		}

		return $rows;
	}

	/**
	 * Delete an offboarding template.
	 *
	 * @param int $id Template ID.
	 * @return array { success: bool, error?: string }
	 */
	public static function delete_template( int $id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_templates';

		$deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		if ( ! $deleted ) {
			return [ 'success' => false, 'error' => __( 'Template not found or already deleted.', 'sfs-hr' ) ];
		}

		return [ 'success' => true ];
	}

	/* ─────────────────────────────────────────────
	 * Task Generation
	 * ───────────────────────────────────────────── */

	/**
	 * Generate offboarding tasks from a template for a given resignation.
	 *
	 * If no template_id is provided, auto-detects by employee's dept_id.
	 * Creates individual task rows from the template's tasks_json.
	 * Calculates due_date from resignation's last_working_day minus due_days_before_exit.
	 *
	 * @param int      $resignation_id Resignation record ID.
	 * @param int|null $template_id    Optional template to use.
	 * @return array { success: bool, tasks_created?: int, error?: string }
	 */
	public static function generate_tasks( int $resignation_id, ?int $template_id = null ): array {
		global $wpdb;

		$res_table = $wpdb->prefix . 'sfs_hr_resignations';
		$emp_table = $wpdb->prefix . 'sfs_hr_employees';

		// Fetch resignation + employee info.
		$resignation = $wpdb->get_row( $wpdb->prepare(
			"SELECT r.*, e.dept_id, e.user_id AS emp_user_id
			 FROM {$res_table} r
			 LEFT JOIN {$emp_table} e ON e.id = r.employee_id
			 WHERE r.id = %d",
			$resignation_id
		), ARRAY_A );

		if ( ! $resignation ) {
			return [ 'success' => false, 'error' => __( 'Resignation not found.', 'sfs-hr' ) ];
		}

		$employee_id = (int) $resignation['employee_id'];
		$dept_id     = (int) ( $resignation['dept_id'] ?? 0 );
		$last_day    = $resignation['last_working_day'] ?? null;

		// Resolve template.
		if ( $template_id ) {
			$template = self::get_template( $template_id );
		} else {
			$template = self::resolve_template_for_dept( $dept_id );
		}

		if ( ! $template || empty( $template['tasks_json'] ) ) {
			return [ 'success' => false, 'error' => __( 'No applicable offboarding template found.', 'sfs-hr' ) ];
		}

		// Resolve role assignments.
		$role_map = self::build_role_map( $dept_id, (int) ( $resignation['emp_user_id'] ?? 0 ) );

		$tasks_table = $wpdb->prefix . 'sfs_hr_offboarding_tasks';
		$now         = current_time( 'mysql' );
		$created     = 0;

		foreach ( $template['tasks_json'] as $task_def ) {
			$task_title = sanitize_text_field( $task_def['title'] ?? '' );
			$task_type  = sanitize_text_field( $task_def['task_type'] ?? 'custom' );

			// Idempotency: skip if a matching task already exists for this resignation + template.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tasks_table}
				 WHERE resignation_id = %d AND template_id = %d AND task_title = %s AND task_type = %s
				 LIMIT 1",
				$resignation_id,
				(int) $template['id'],
				$task_title,
				$task_type
			) );

			if ( $exists ) {
				continue;
			}

			$due_date = null;
			if ( $last_day && isset( $task_def['due_days_before_exit'] ) ) {
				$due_date = gmdate( 'Y-m-d', strtotime( $last_day ) - ( (int) $task_def['due_days_before_exit'] * DAY_IN_SECONDS ) );
			}

			$assigned_to = self::resolve_assignee( $task_def['assign_to_role'] ?? '', $role_map );

			$wpdb->insert( $tasks_table, [
				'resignation_id' => $resignation_id,
				'employee_id'    => $employee_id,
				'template_id'    => (int) $template['id'],
				'task_title'     => $task_title,
				'task_type'      => $task_type,
				'description'    => sanitize_textarea_field( $task_def['description'] ?? '' ),
				'assigned_to'    => $assigned_to ?: null,
				'due_date'       => $due_date,
				'status'         => 'pending',
				'completed_at'   => null,
				'completed_by'   => null,
				'notes'          => null,
				'created_at'     => $now,
				'updated_at'     => $now,
			], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ] );

			if ( $wpdb->insert_id ) {
				$created++;
			}
		}

		EmployeeExitModule::log_resignation_event( $resignation_id, 'offboarding_tasks_generated', [
			'template_id'   => (int) $template['id'],
			'tasks_created' => $created,
		] );

		return [ 'success' => true, 'tasks_created' => $created ];
	}

	/* ─────────────────────────────────────────────
	 * Task Management
	 * ───────────────────────────────────────────── */

	/**
	 * Get all offboarding tasks for a resignation.
	 *
	 * @param int $resignation_id Resignation ID.
	 * @return array List of task rows.
	 */
	public static function get_tasks( int $resignation_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_tasks';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE resignation_id = %d ORDER BY due_date ASC, id ASC",
			$resignation_id
		), ARRAY_A ) ?: [];
	}

	/**
	 * Update an offboarding task.
	 *
	 * @param int   $task_id    Task ID.
	 * @param array $data       Fields to update (status, assigned_to, due_date, notes).
	 * @param int   $updated_by WP user ID performing the update.
	 * @return array { success: bool, error?: string }
	 */
	public static function update_task( int $task_id, array $data, int $updated_by ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_tasks';

		$allowed = [ 'status', 'assigned_to', 'due_date', 'notes', 'task_title', 'description' ];
		$update  = [];
		$format  = [];

		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$update[ $field ] = $data[ $field ];
			$format[]         = in_array( $field, [ 'assigned_to' ], true ) ? '%d' : '%s';
		}

		if ( empty( $update ) ) {
			return [ 'success' => false, 'error' => __( 'No valid fields to update.', 'sfs-hr' ) ];
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

		$result = $wpdb->update( $table, $update, [ 'id' => $task_id ], $format, [ '%d' ] );

		if ( false === $result ) {
			return [ 'success' => false, 'error' => __( 'Failed to update task.', 'sfs-hr' ) ];
		}

		return [ 'success' => true ];
	}

	/**
	 * Mark a task as completed.
	 *
	 * @param int    $task_id      Task ID.
	 * @param int    $completed_by WP user ID who completed the task.
	 * @param string $notes        Optional completion notes.
	 * @return array { success: bool, error?: string }
	 */
	public static function complete_task( int $task_id, int $completed_by, string $notes = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_tasks';

		// Pre-check existence.
		$task = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, resignation_id FROM {$table} WHERE id = %d",
			$task_id
		), ARRAY_A );

		if ( ! $task ) {
			return [ 'success' => false, 'error' => __( 'Task not found.', 'sfs-hr' ) ];
		}

		$result = $wpdb->update( $table, [
			'status'       => 'completed',
			'completed_at' => current_time( 'mysql' ),
			'completed_by' => $completed_by,
			'notes'        => $notes ?: null,
			'updated_at'   => current_time( 'mysql' ),
		], [ 'id' => $task_id ], [ '%s', '%s', '%d', '%s', '%s' ], [ '%d' ] );

		if ( false === $result ) {
			return [ 'success' => false, 'error' => __( 'Failed to complete task.', 'sfs-hr' ) ];
		}

		EmployeeExitModule::log_resignation_event( (int) $task['resignation_id'], 'offboarding_task_completed', [
			'task_id'      => $task_id,
			'completed_by' => $completed_by,
		] );

		return [ 'success' => true ];
	}

	/**
	 * Skip a task with a reason.
	 *
	 * @param int    $task_id    Task ID.
	 * @param int    $skipped_by WP user ID who skipped the task.
	 * @param string $reason     Reason for skipping.
	 * @return array { success: bool, error?: string }
	 */
	public static function skip_task( int $task_id, int $skipped_by, string $reason ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_tasks';

		// Pre-check existence.
		$task = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, resignation_id FROM {$table} WHERE id = %d",
			$task_id
		), ARRAY_A );

		if ( ! $task ) {
			return [ 'success' => false, 'error' => __( 'Task not found.', 'sfs-hr' ) ];
		}

		$result = $wpdb->update( $table, [
			'status'       => 'skipped',
			'completed_at' => current_time( 'mysql' ),
			'completed_by' => $skipped_by,
			'notes'        => $reason,
			'updated_at'   => current_time( 'mysql' ),
		], [ 'id' => $task_id ], [ '%s', '%s', '%d', '%s', '%s' ], [ '%d' ] );

		if ( false === $result ) {
			return [ 'success' => false, 'error' => __( 'Failed to skip task.', 'sfs-hr' ) ];
		}

		EmployeeExitModule::log_resignation_event( (int) $task['resignation_id'], 'offboarding_task_skipped', [
			'task_id'    => $task_id,
			'skipped_by' => $skipped_by,
			'reason'     => $reason,
		] );

		return [ 'success' => true ];
	}

	/* ─────────────────────────────────────────────
	 * Progress Tracking
	 * ───────────────────────────────────────────── */

	/**
	 * Get offboarding progress summary for a resignation.
	 *
	 * @param int $resignation_id Resignation ID.
	 * @return array { total, completed, pending, in_progress, skipped, pct_complete, all_done }
	 */
	public static function get_progress( int $resignation_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_tasks';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS cnt FROM {$table} WHERE resignation_id = %d GROUP BY status",
			$resignation_id
		), ARRAY_A );

		$counts = [
			'pending'     => 0,
			'in_progress' => 0,
			'completed'   => 0,
			'skipped'     => 0,
		];

		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['cnt'];
			}
		}

		$total        = array_sum( $counts );
		$done         = $counts['completed'] + $counts['skipped'];
		$pct_complete = $total > 0 ? round( ( $done / $total ) * 100, 1 ) : 0.0;

		return [
			'total'        => $total,
			'completed'    => $counts['completed'],
			'pending'      => $counts['pending'],
			'in_progress'  => $counts['in_progress'],
			'skipped'      => $counts['skipped'],
			'pct_complete' => $pct_complete,
			'all_done'     => ( $total > 0 && $done === $total ),
		];
	}

	/* ─────────────────────────────────────────────
	 * Escalation
	 * ───────────────────────────────────────────── */

	/**
	 * Get overdue offboarding tasks.
	 *
	 * Returns tasks where due_date < today (minus days_overdue buffer)
	 * and status is still pending or in_progress. Used by cron for escalation.
	 *
	 * @param int $days_overdue Number of days past due_date to consider overdue (0 = today).
	 * @return array List of overdue task rows.
	 */
	public static function get_overdue_tasks( int $days_overdue = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_tasks';

		$cutoff = gmdate( 'Y-m-d', strtotime( '-' . max( 0, $days_overdue ) . ' days' ) );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT t.*, r.employee_id, e.first_name, e.last_name
			 FROM {$table} t
			 LEFT JOIN {$wpdb->prefix}sfs_hr_resignations r ON r.id = t.resignation_id
			 LEFT JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = r.employee_id
			 WHERE t.due_date IS NOT NULL
			   AND t.due_date < %s
			   AND t.status IN ('pending', 'in_progress')
			 ORDER BY t.due_date ASC",
			$cutoff
		), ARRAY_A ) ?: [];
	}

	/* ─────────────────────────────────────────────
	 * Equipment / Asset Clearance
	 * ───────────────────────────────────────────── */

	/**
	 * Check asset clearance status for an employee.
	 *
	 * Queries the assets table for items still assigned to the employee.
	 * Returns clearance status and list of pending items.
	 *
	 * @param int $employee_id Employee ID.
	 * @return array { cleared: bool, pending_items: array }
	 */
	public static function check_asset_clearance( int $employee_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_assets';

		// Check if assets table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$table
		) );

		if ( ! $table_exists ) {
			// Assets module not installed — assume cleared.
			return [ 'cleared' => true, 'pending_items' => [] ];
		}

		// Query assets currently assigned to the employee that have not been returned.
		$pending = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, asset_name, asset_tag, assigned_at
			 FROM {$table}
			 WHERE employee_id = %d
			   AND (returned_at IS NULL OR returned_at = '')
			   AND status != 'returned'",
			$employee_id
		), ARRAY_A ) ?: [];

		return [
			'cleared'       => empty( $pending ),
			'pending_items' => $pending,
		];
	}

	/* ─────────────────────────────────────────────
	 * Private Helpers
	 * ───────────────────────────────────────────── */

	/**
	 * Resolve the best matching template for a department.
	 *
	 * Tries dept-specific first, then falls back to global (dept_id IS NULL).
	 *
	 * @param int $dept_id Department ID.
	 * @return array|null Template row or null.
	 */
	private static function resolve_template_for_dept( int $dept_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_offboarding_templates';

		// Try department-specific template first.
		if ( $dept_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE dept_id = %d AND is_active = 1 LIMIT 1",
				$dept_id
			), ARRAY_A );

			if ( $row ) {
				$row['tasks_json'] = json_decode( $row['tasks_json'], true ) ?: [];
				return $row;
			}
		}

		// Fall back to global template.
		$row = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE dept_id IS NULL AND is_active = 1 ORDER BY id ASC LIMIT 1",
			ARRAY_A
		);

		if ( $row ) {
			$row['tasks_json'] = json_decode( $row['tasks_json'], true ) ?: [];
			return $row;
		}

		return null;
	}

	/**
	 * Build a role-to-user-ID map for task assignment resolution.
	 *
	 * @param int $dept_id      Department ID.
	 * @param int $emp_user_id  The resigning employee's WP user ID.
	 * @return array Associative map: role_key => WP user ID.
	 */
	private static function build_role_map( int $dept_id, int $emp_user_id ): array {
		global $wpdb;

		$map = [
			'employee' => $emp_user_id,
			'it'       => (int) get_option( 'sfs_hr_it_admin_user_id', 0 ),
			'manager'  => 0,
			'hr'       => 0,
		];

		if ( $dept_id > 0 ) {
			$dept_table = $wpdb->prefix . 'sfs_hr_departments';
			$dept       = $wpdb->get_row( $wpdb->prepare(
				"SELECT manager_user_id, hr_responsible_user_id FROM {$dept_table} WHERE id = %d",
				$dept_id
			), ARRAY_A );

			if ( $dept ) {
				$map['manager'] = (int) ( $dept['manager_user_id'] ?? 0 );
				$map['hr']      = (int) ( $dept['hr_responsible_user_id'] ?? 0 );
			}
		}

		return $map;
	}

	/**
	 * Resolve a task's assigned WP user ID from a role key.
	 *
	 * @param string $role_key Role key from template (it, hr, manager, employee, custom).
	 * @param array  $role_map Role-to-user-ID map.
	 * @return int|null WP user ID or null if unresolvable.
	 */
	private static function resolve_assignee( string $role_key, array $role_map ): ?int {
		$role_key = strtolower( trim( $role_key ) );

		if ( isset( $role_map[ $role_key ] ) && $role_map[ $role_key ] > 0 ) {
			return $role_map[ $role_key ];
		}

		// 'custom' or unknown role — leave unassigned for manual assignment.
		return null;
	}
}
