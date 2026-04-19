<?php
/**
 * Exit Interview Service
 *
 * Manages exit interviews and surveys for resigning employees.
 *
 * @package SFS\HR\Modules\Resignation\Services
 * @since   1.9.2
 */

namespace SFS\HR\Modules\Resignation\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Exit_Interview_Service
 *
 * Static service for exit interview question management, interview lifecycle,
 * and departure analytics.
 */
class Exit_Interview_Service {

	/**
	 * Categorized exit reasons with human-readable labels.
	 */
	const EXIT_REASONS = [
		'better_opportunity' => 'Better Opportunity',
		'compensation'       => 'Compensation',
		'management'         => 'Management',
		'relocation'         => 'Relocation',
		'personal'           => 'Personal',
		'work_life_balance'  => 'Work-Life Balance',
		'career_growth'      => 'Career Growth',
		'other'              => 'Other',
	];

	// ─── Question CRUD ──────────────────────────────────────────────────

	/**
	 * Create a new exit interview question.
	 *
	 * @param array $data {
	 *     @type string $question_text  Required.
	 *     @type string $question_type  Optional. text|rating|select|multi_select. Default 'text'.
	 *     @type array  $options        Optional. Choices for select/multi_select.
	 *     @type int    $sort_order     Optional. Default 0.
	 *     @type bool   $is_required    Optional. Default true.
	 * }
	 * @return array { success: bool, data?: array, message?: string }
	 */
	public static function create_question( array $data ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interview_questions';

		if ( empty( $data['question_text'] ) ) {
			return [ 'success' => false, 'message' => __( 'Question text is required.', 'sfs-hr' ) ];
		}

		$question_type = $data['question_type'] ?? 'text';
		$allowed_types = [ 'text', 'rating', 'select', 'multi_select' ];
		if ( ! in_array( $question_type, $allowed_types, true ) ) {
			return [ 'success' => false, 'message' => __( 'Invalid question type.', 'sfs-hr' ) ];
		}

		$options_json = null;
		if ( ! empty( $data['options'] ) && is_array( $data['options'] ) ) {
			$options_json = wp_json_encode( $data['options'] );
		}

		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$table,
			[
				'question_text' => sanitize_textarea_field( $data['question_text'] ),
				'question_type' => $question_type,
				'options_json'  => $options_json,
				'sort_order'    => (int) ( $data['sort_order'] ?? 0 ),
				'is_required'   => isset( $data['is_required'] ) ? (int) (bool) $data['is_required'] : 1,
				'is_active'     => 1,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return [ 'success' => false, 'message' => __( 'Failed to create question.', 'sfs-hr' ) ];
		}

		return [
			'success' => true,
			'data'    => self::get_question_row( (int) $wpdb->insert_id ),
		];
	}

	/**
	 * Update an existing exit interview question.
	 *
	 * @param int   $id   Question ID.
	 * @param array $data Fields to update.
	 * @return array { success: bool, data?: array, message?: string }
	 */
	public static function update_question( int $id, array $data ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interview_questions';

		$existing = self::get_question_row( $id );
		if ( ! $existing ) {
			return [ 'success' => false, 'message' => __( 'Question not found.', 'sfs-hr' ) ];
		}

		$update = [];
		$format = [];

		if ( isset( $data['question_text'] ) ) {
			$update['question_text'] = sanitize_textarea_field( $data['question_text'] );
			$format[] = '%s';
		}

		if ( isset( $data['question_type'] ) ) {
			$allowed_types = [ 'text', 'rating', 'select', 'multi_select' ];
			if ( ! in_array( $data['question_type'], $allowed_types, true ) ) {
				return [ 'success' => false, 'message' => __( 'Invalid question type.', 'sfs-hr' ) ];
			}
			$update['question_type'] = $data['question_type'];
			$format[] = '%s';
		}

		if ( array_key_exists( 'options', $data ) ) {
			$update['options_json'] = is_array( $data['options'] ) ? wp_json_encode( $data['options'] ) : null;
			$format[] = '%s';
		}

		if ( isset( $data['sort_order'] ) ) {
			$update['sort_order'] = (int) $data['sort_order'];
			$format[] = '%d';
		}

		if ( isset( $data['is_required'] ) ) {
			$update['is_required'] = (int) (bool) $data['is_required'];
			$format[] = '%d';
		}

		if ( isset( $data['is_active'] ) ) {
			$update['is_active'] = (int) (bool) $data['is_active'];
			$format[] = '%d';
		}

		if ( empty( $update ) ) {
			return [ 'success' => false, 'message' => __( 'No fields to update.', 'sfs-hr' ) ];
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[] = '%s';

		$wpdb->update( $table, $update, [ 'id' => $id ], $format, [ '%d' ] );

		return [
			'success' => true,
			'data'    => self::get_question_row( $id ),
		];
	}

	/**
	 * Retrieve exit interview questions.
	 *
	 * @param bool $active_only Whether to return only active questions.
	 * @return array { success: bool, data: array }
	 */
	public static function get_questions( bool $active_only = true ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interview_questions';

		$where = $active_only ? 'WHERE is_active = 1' : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id ASC",
			ARRAY_A
		);

		return [ 'success' => true, 'data' => $rows ?: [] ];
	}

	/**
	 * Delete (deactivate) an exit interview question.
	 *
	 * @param int $id Question ID.
	 * @return array { success: bool, message?: string }
	 */
	public static function delete_question( int $id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interview_questions';

		$existing = self::get_question_row( $id );
		if ( ! $existing ) {
			return [ 'success' => false, 'message' => __( 'Question not found.', 'sfs-hr' ) ];
		}

		$wpdb->update(
			$table,
			[ 'is_active' => 0, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		return [ 'success' => true, 'message' => __( 'Question deleted.', 'sfs-hr' ) ];
	}

	/**
	 * Reorder exit interview questions.
	 *
	 * @param array $order Associative array of question_id => sort_order.
	 * @return array { success: bool }
	 */
	public static function reorder_questions( array $order ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interview_questions';
		$now   = current_time( 'mysql' );

		foreach ( $order as $id => $sort_order ) {
			$wpdb->update(
				$table,
				[ 'sort_order' => (int) $sort_order, 'updated_at' => $now ],
				[ 'id' => (int) $id ],
				[ '%d', '%s' ],
				[ '%d' ]
			);
		}

		return [ 'success' => true ];
	}

	// ─── Interview Management ───────────────────────────────────────────

	/**
	 * Create a pending exit interview for a resignation.
	 *
	 * @param int $resignation_id Resignation record ID.
	 * @return array { success: bool, data?: array, message?: string }
	 */
	public static function create_interview( int $resignation_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interviews';

		// Fetch the resignation to get employee_id.
		$resignation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, employee_id FROM {$wpdb->prefix}sfs_hr_resignations WHERE id = %d",
				$resignation_id
			),
			ARRAY_A
		);

		if ( ! $resignation ) {
			return [ 'success' => false, 'message' => __( 'Resignation not found.', 'sfs-hr' ) ];
		}

		// Check if interview already exists.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE resignation_id = %d",
				$resignation_id
			)
		);

		if ( $existing ) {
			return [ 'success' => false, 'message' => __( 'Exit interview already exists for this resignation.', 'sfs-hr' ) ];
		}

		$now = current_time( 'mysql' );

		$wpdb->insert(
			$table,
			[
				'resignation_id' => $resignation_id,
				'employee_id'    => (int) $resignation['employee_id'],
				'status'         => 'pending',
				'created_at'     => $now,
				'updated_at'     => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);

		if ( ! $wpdb->insert_id ) {
			return [ 'success' => false, 'message' => __( 'Failed to create exit interview.', 'sfs-hr' ) ];
		}

		$interview = self::get_interview( (int) $wpdb->insert_id );

		\SFS\HR\Modules\EmployeeExit\EmployeeExitModule::log_resignation_event(
			$resignation_id,
			'exit_interview_created',
			__( 'Exit interview record created.', 'sfs-hr' )
		);

		return [ 'success' => true, 'data' => $interview ];
	}

	/**
	 * Schedule an exit interview.
	 *
	 * @param int    $interview_id   Interview ID.
	 * @param string $date           Interview date (Y-m-d).
	 * @param int    $interviewer_id WP user ID of interviewer.
	 * @return array { success: bool, data?: array, message?: string }
	 */
	public static function schedule_interview( int $interview_id, string $date, int $interviewer_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interviews';

		$interview = self::get_interview( $interview_id );
		if ( ! $interview ) {
			return [ 'success' => false, 'message' => __( 'Interview not found.', 'sfs-hr' ) ];
		}

		if ( 'completed' === $interview['status'] ) {
			return [ 'success' => false, 'message' => __( 'Interview is already completed.', 'sfs-hr' ) ];
		}

		$wpdb->update(
			$table,
			[
				'interview_date' => sanitize_text_field( $date ),
				'interviewer_id' => $interviewer_id,
				'status'         => 'scheduled',
				'updated_at'     => current_time( 'mysql' ),
			],
			[ 'id' => $interview_id ],
			[ '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		\SFS\HR\Modules\EmployeeExit\EmployeeExitModule::log_resignation_event(
			(int) $interview['resignation_id'],
			'exit_interview_scheduled',
			sprintf(
				/* translators: %s: interview date */
				__( 'Exit interview scheduled for %s.', 'sfs-hr' ),
				$date
			)
		);

		return [ 'success' => true, 'data' => self::get_interview( $interview_id ) ];
	}

	/**
	 * Submit responses for an exit interview.
	 *
	 * @param int         $interview_id    Interview ID.
	 * @param array       $responses       Array of [ question_id => int, answer => mixed ].
	 * @param string      $exit_reason     One of EXIT_REASONS keys.
	 * @param bool        $is_anonymous    Whether to anonymize the response.
	 * @param string      $comments        Additional comments.
	 * @param bool|null   $rehire_eligible Whether employee is eligible for rehire.
	 * @param string      $rehire_notes    Notes about rehire eligibility.
	 * @return array { success: bool, data?: array, message?: string }
	 */
	public static function submit_responses(
		int $interview_id,
		array $responses,
		string $exit_reason,
		bool $is_anonymous = false,
		string $comments = '',
		?bool $rehire_eligible = null,
		string $rehire_notes = ''
	): array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interviews';

		$interview = self::get_interview( $interview_id );
		if ( ! $interview ) {
			return [ 'success' => false, 'message' => __( 'Interview not found.', 'sfs-hr' ) ];
		}

		if ( 'completed' === $interview['status'] ) {
			return [ 'success' => false, 'message' => __( 'Interview responses have already been submitted.', 'sfs-hr' ) ];
		}

		if ( ! array_key_exists( $exit_reason, self::EXIT_REASONS ) ) {
			return [ 'success' => false, 'message' => __( 'Invalid exit reason.', 'sfs-hr' ) ];
		}

		$update_data = [
			'responses_json'      => wp_json_encode( $responses ),
			'exit_reason'         => $exit_reason,
			'is_anonymous'        => (int) $is_anonymous,
			'additional_comments' => sanitize_textarea_field( $comments ),
			'status'              => 'completed',
			'updated_at'          => current_time( 'mysql' ),
		];
		$format = [ '%s', '%s', '%d', '%s', '%s', '%s' ];

		if ( null !== $rehire_eligible ) {
			$update_data['rehire_eligible'] = (int) $rehire_eligible;
			$format[] = '%d';
		}

		if ( ! empty( $rehire_notes ) ) {
			$update_data['rehire_notes'] = sanitize_textarea_field( $rehire_notes );
			$format[] = '%s';
		}

		$wpdb->update( $table, $update_data, [ 'id' => $interview_id ], $format, [ '%d' ] );

		\SFS\HR\Modules\EmployeeExit\EmployeeExitModule::log_resignation_event(
			(int) $interview['resignation_id'],
			'exit_interview_completed',
			__( 'Exit interview responses submitted.', 'sfs-hr' )
		);

		return [ 'success' => true, 'data' => self::get_interview( $interview_id ) ];
	}

	/**
	 * Get a single exit interview by ID.
	 *
	 * @param int $interview_id Interview ID.
	 * @return array|null Interview row or null if not found.
	 */
	public static function get_interview( int $interview_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interviews';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $interview_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get an exit interview by resignation ID.
	 *
	 * @param int $resignation_id Resignation ID.
	 * @return array|null Interview row or null if not found.
	 */
	public static function get_interview_by_resignation( int $resignation_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interviews';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE resignation_id = %d", $resignation_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	// ─── Analytics ──────────────────────────────────────────────────────

	/**
	 * Get exit interview analytics for a date range.
	 *
	 * @param string   $start_date Start date (Y-m-d).
	 * @param string   $end_date   End date (Y-m-d).
	 * @param int|null $dept_id    Optional department filter.
	 * @return array {
	 *     @type array  $reason_breakdown  reason => count
	 *     @type float  $avg_tenure_months Average tenure in months
	 *     @type array  $dept_breakdown    dept_id => count
	 *     @type array  $monthly_trend     Y-m => count
	 *     @type array  $rehire_stats      { eligible, not_eligible, not_assessed }
	 *     @type int    $total_exits       Total completed interviews
	 * }
	 */
	public static function get_exit_analytics( string $start_date, string $end_date, ?int $dept_id = null ): array {
		global $wpdb;

		$interviews_table  = $wpdb->prefix . 'sfs_hr_exit_interviews';
		$employees_table   = $wpdb->prefix . 'sfs_hr_employees';

		$dept_join  = '';
		$dept_where = '';

		if ( null !== $dept_id ) {
			$dept_join  = "INNER JOIN {$employees_table} e ON ei.employee_id = e.id";
			$dept_where = $wpdb->prepare( ' AND e.dept_id = %d', $dept_id );
		}

		$base_where = $wpdb->prepare(
			"WHERE ei.status = 'completed' AND ei.updated_at >= %s AND ei.updated_at <= %s",
			$start_date . ' 00:00:00',
			$end_date . ' 23:59:59'
		);

		// Reason breakdown.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reason_rows = $wpdb->get_results(
			"SELECT ei.exit_reason, COUNT(*) as cnt
			 FROM {$interviews_table} ei
			 {$dept_join}
			 {$base_where} {$dept_where}
			 GROUP BY ei.exit_reason",
			ARRAY_A
		);

		$reason_breakdown = [];
		foreach ( $reason_rows as $row ) {
			$reason_breakdown[ $row['exit_reason'] ] = (int) $row['cnt'];
		}

		// Average tenure.
		$join_emp = ( null !== $dept_id ) ? '' : "INNER JOIN {$employees_table} e ON ei.employee_id = e.id";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$avg_tenure = $wpdb->get_var(
			"SELECT AVG(TIMESTAMPDIFF(MONTH, e.hire_date, ei.updated_at))
			 FROM {$interviews_table} ei
			 " . ( null !== $dept_id ? $dept_join : "INNER JOIN {$employees_table} e ON ei.employee_id = e.id" ) . "
			 {$base_where} {$dept_where}"
		);

		// Department breakdown.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dept_rows = $wpdb->get_results(
			"SELECT e.dept_id, COUNT(*) as cnt
			 FROM {$interviews_table} ei
			 INNER JOIN {$employees_table} e ON ei.employee_id = e.id
			 {$base_where} {$dept_where}
			 GROUP BY e.dept_id",
			ARRAY_A
		);

		$dept_breakdown = [];
		foreach ( $dept_rows as $row ) {
			$dept_breakdown[ (int) $row['dept_id'] ] = (int) $row['cnt'];
		}

		// Monthly trend.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$monthly_rows = $wpdb->get_results(
			"SELECT DATE_FORMAT(ei.updated_at, '%Y-%m') as month_key, COUNT(*) as cnt
			 FROM {$interviews_table} ei
			 {$dept_join}
			 {$base_where} {$dept_where}
			 GROUP BY month_key
			 ORDER BY month_key ASC",
			ARRAY_A
		);

		$monthly_trend = [];
		foreach ( $monthly_rows as $row ) {
			$monthly_trend[ $row['month_key'] ] = (int) $row['cnt'];
		}

		// Rehire stats.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rehire_rows = $wpdb->get_results(
			"SELECT ei.rehire_eligible, COUNT(*) as cnt
			 FROM {$interviews_table} ei
			 {$dept_join}
			 {$base_where} {$dept_where}
			 GROUP BY ei.rehire_eligible",
			ARRAY_A
		);

		$rehire_stats = [
			'eligible'      => 0,
			'not_eligible'  => 0,
			'not_assessed'  => 0,
		];

		foreach ( $rehire_rows as $row ) {
			if ( null === $row['rehire_eligible'] ) {
				$rehire_stats['not_assessed'] = (int) $row['cnt'];
			} elseif ( (int) $row['rehire_eligible'] === 1 ) {
				$rehire_stats['eligible'] = (int) $row['cnt'];
			} else {
				$rehire_stats['not_eligible'] = (int) $row['cnt'];
			}
		}

		// Total exits.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_exits = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$interviews_table} ei
			 {$dept_join}
			 {$base_where} {$dept_where}"
		);

		return [
			'reason_breakdown'  => $reason_breakdown,
			'avg_tenure_months' => round( (float) $avg_tenure, 1 ),
			'dept_breakdown'    => $dept_breakdown,
			'monthly_trend'     => $monthly_trend,
			'rehire_stats'      => $rehire_stats,
			'total_exits'       => $total_exits,
		];
	}

	// ─── Seed Defaults ──────────────────────────────────────────────────

	/**
	 * Seed default exit interview questions if none exist.
	 *
	 * @return void
	 */
	public static function seed_default_questions(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interview_questions';

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 0 ) {
			return;
		}

		$reason_options = array_keys( self::EXIT_REASONS );

		$defaults = [
			[
				'question_text' => __( 'What is your primary reason for leaving?', 'sfs-hr' ),
				'question_type' => 'select',
				'options'       => $reason_options,
				'sort_order'    => 1,
				'is_required'   => 1,
			],
			[
				'question_text' => __( 'How would you rate your overall experience?', 'sfs-hr' ),
				'question_type' => 'rating',
				'options'       => [ 1, 2, 3, 4, 5 ],
				'sort_order'    => 2,
				'is_required'   => 1,
			],
			[
				'question_text' => __( 'How would you rate your relationship with your direct manager?', 'sfs-hr' ),
				'question_type' => 'rating',
				'options'       => [ 1, 2, 3, 4, 5 ],
				'sort_order'    => 3,
				'is_required'   => 1,
			],
			[
				'question_text' => __( 'Would you recommend this company to others?', 'sfs-hr' ),
				'question_type' => 'rating',
				'options'       => [ 1, 2, 3, 4, 5 ],
				'sort_order'    => 4,
				'is_required'   => 1,
			],
			[
				'question_text' => __( 'What could the company have done to retain you?', 'sfs-hr' ),
				'question_type' => 'text',
				'options'       => null,
				'sort_order'    => 5,
				'is_required'   => 1,
			],
			[
				'question_text' => __( 'Any additional feedback you\'d like to share?', 'sfs-hr' ),
				'question_type' => 'text',
				'options'       => null,
				'sort_order'    => 6,
				'is_required'   => 0,
			],
		];

		$now = current_time( 'mysql' );

		foreach ( $defaults as $q ) {
			$wpdb->insert(
				$table,
				[
					'question_text' => $q['question_text'],
					'question_type' => $q['question_type'],
					'options_json'  => $q['options'] ? wp_json_encode( $q['options'] ) : null,
					'sort_order'    => $q['sort_order'],
					'is_required'   => $q['is_required'],
					'is_active'     => 1,
					'created_at'    => $now,
					'updated_at'    => $now,
				],
				[ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
			);
		}
	}

	// ─── Private Helpers ────────────────────────────────────────────────

	/**
	 * Get a single question row by ID.
	 *
	 * @param int $id Question ID.
	 * @return array|null
	 */
	private static function get_question_row( int $id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'sfs_hr_exit_interview_questions';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}
}
