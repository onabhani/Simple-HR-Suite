<?php
namespace SFS\HR\Modules\Performance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Review Cycle Service
 *
 * Manages performance review cycles, reviews, templates, competencies,
 * PIPs (Performance Improvement Plans), calibration, and analytics.
 *
 * Tables managed:
 *  - sfs_hr_performance_review_cycles
 *  - sfs_hr_performance_reviews
 *  - sfs_hr_performance_review_templates
 *  - sfs_hr_performance_competencies
 *  - sfs_hr_performance_pips
 *
 * @version 1.0.0
 */
class Review_Cycle_Service {

    // -------------------------------------------------------------------------
    // CYCLES
    // -------------------------------------------------------------------------

    /**
     * Create a new review cycle.
     *
     * @param array $data {
     *   name, description, cycle_type, start_date, end_date, deadline,
     *   review_types (array), rating_scale_max, created_by
     * }
     * @return array{ success: bool, id?: int, error?: string }
     */
    public static function create_cycle( array $data ): array {
        global $wpdb;

        if ( empty( $data['name'] ) ) {
            return [ 'success' => false, 'error' => __( 'Cycle name is required.', 'sfs-hr' ) ];
        }
        if ( empty( $data['start_date'] ) || empty( $data['end_date'] ) ) {
            return [ 'success' => false, 'error' => __( 'Start date and end date are required.', 'sfs-hr' ) ];
        }
        if ( strtotime( $data['end_date'] ) <= strtotime( $data['start_date'] ) ) {
            return [ 'success' => false, 'error' => __( 'End date must be after start date.', 'sfs-hr' ) ];
        }

        $valid_types = [ 'annual', 'semi_annual', 'quarterly', 'custom' ];
        $cycle_type  = $data['cycle_type'] ?? 'annual';
        if ( ! in_array( $cycle_type, $valid_types, true ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid cycle type.', 'sfs-hr' ) ];
        }

        $review_types = $data['review_types'] ?? [ 'self', 'manager' ];
        if ( ! is_array( $review_types ) ) {
            $review_types = [ 'self', 'manager' ];
        }

        $now   = current_time( 'mysql' );
        $table = $wpdb->prefix . 'sfs_hr_performance_review_cycles';

        $inserted = $wpdb->insert(
            $table,
            [
                'name'             => sanitize_text_field( $data['name'] ),
                'description'      => sanitize_textarea_field( $data['description'] ?? '' ),
                'cycle_type'       => $cycle_type,
                'start_date'       => $data['start_date'],
                'end_date'         => $data['end_date'],
                'deadline'         => $data['deadline'] ?? $data['end_date'],
                'review_types'     => wp_json_encode( array_values( $review_types ) ),
                'status'           => 'draft',
                'rating_scale_max' => isset( $data['rating_scale_max'] ) ? (int) $data['rating_scale_max'] : 5,
                'created_by'       => isset( $data['created_by'] ) ? (int) $data['created_by'] : get_current_user_id(),
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'success' => false, 'error' => __( 'Failed to create review cycle.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Update an existing review cycle.
     *
     * @param int   $id   Cycle ID.
     * @param array $data Fields to update.
     * @return array{ success: bool, error?: string }
     */
    public static function update_cycle( int $id, array $data ): array {
        global $wpdb;

        $cycle = self::get_cycle( $id );
        if ( ! $cycle ) {
            return [ 'success' => false, 'error' => __( 'Review cycle not found.', 'sfs-hr' ) ];
        }
        if ( in_array( $cycle['status'], [ 'completed', 'cancelled' ], true ) ) {
            return [ 'success' => false, 'error' => __( 'Cannot modify a completed or cancelled cycle.', 'sfs-hr' ) ];
        }

        $fields  = [];
        $formats = [];

        if ( isset( $data['name'] ) ) {
            $fields['name'] = sanitize_text_field( $data['name'] );
            $formats[]      = '%s';
        }
        if ( isset( $data['description'] ) ) {
            $fields['description'] = sanitize_textarea_field( $data['description'] );
            $formats[]             = '%s';
        }
        if ( isset( $data['cycle_type'] ) ) {
            $valid = [ 'annual', 'semi_annual', 'quarterly', 'custom' ];
            if ( ! in_array( $data['cycle_type'], $valid, true ) ) {
                return [ 'success' => false, 'error' => __( 'Invalid cycle type.', 'sfs-hr' ) ];
            }
            $fields['cycle_type'] = $data['cycle_type'];
            $formats[]            = '%s';
        }
        if ( isset( $data['start_date'] ) ) {
            $fields['start_date'] = $data['start_date'];
            $formats[]            = '%s';
        }
        if ( isset( $data['end_date'] ) ) {
            $fields['end_date'] = $data['end_date'];
            $formats[]          = '%s';
        }
        if ( isset( $data['deadline'] ) ) {
            $fields['deadline'] = $data['deadline'];
            $formats[]          = '%s';
        }
        if ( isset( $data['review_types'] ) && is_array( $data['review_types'] ) ) {
            $fields['review_types'] = wp_json_encode( array_values( $data['review_types'] ) );
            $formats[]              = '%s';
        }
        if ( isset( $data['rating_scale_max'] ) ) {
            $fields['rating_scale_max'] = (int) $data['rating_scale_max'];
            $formats[]                  = '%d';
        }
        if ( isset( $data['status'] ) ) {
            $valid_statuses = [ 'draft', 'active', 'in_review', 'calibration', 'completed', 'cancelled' ];
            if ( ! in_array( $data['status'], $valid_statuses, true ) ) {
                return [ 'success' => false, 'error' => __( 'Invalid status.', 'sfs-hr' ) ];
            }
            $fields['status'] = $data['status'];
            $formats[]        = '%s';
        }

        if ( empty( $fields ) ) {
            return [ 'success' => true ];
        }

        $fields['updated_at'] = current_time( 'mysql' );
        $formats[]            = '%s';

        $result = $wpdb->update(
            $wpdb->prefix . 'sfs_hr_performance_review_cycles',
            $fields,
            [ 'id' => $id ],
            $formats,
            [ '%d' ]
        );

        if ( false === $result ) {
            return [ 'success' => false, 'error' => __( 'Failed to update review cycle.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    /**
     * Fetch a single review cycle by ID.
     *
     * @param int $id Cycle ID.
     * @return array|null
     */
    public static function get_cycle( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_review_cycles WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        $row['review_types'] = json_decode( $row['review_types'] ?? '[]', true ) ?: [];
        return $row;
    }

    /**
     * List review cycles with optional filters.
     *
     * @param array $filters {
     *   status?: string,
     *   cycle_type?: string,
     *   year?: int,
     *   limit?: int,
     *   offset?: int,
     * }
     * @return array
     */
    public static function list_cycles( array $filters = [] ): array {
        global $wpdb;

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }
        if ( ! empty( $filters['cycle_type'] ) ) {
            $where[]  = 'cycle_type = %s';
            $params[] = $filters['cycle_type'];
        }
        if ( ! empty( $filters['year'] ) ) {
            $where[]  = 'YEAR(start_date) = %d';
            $params[] = (int) $filters['year'];
        }

        $limit  = isset( $filters['limit'] ) ? (int) $filters['limit'] : 50;
        $offset = isset( $filters['offset'] ) ? (int) $filters['offset'] : 0;

        $sql = "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_review_cycles
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        if ( ! $rows ) {
            return [];
        }

        foreach ( $rows as &$row ) {
            $row['review_types'] = json_decode( $row['review_types'] ?? '[]', true ) ?: [];
        }
        unset( $row );

        return $rows;
    }

    /**
     * Activate a draft cycle (status → active).
     *
     * @param int $id Cycle ID.
     * @return array{ success: bool, error?: string }
     */
    public static function activate_cycle( int $id ): array {
        $cycle = self::get_cycle( $id );
        if ( ! $cycle ) {
            return [ 'success' => false, 'error' => __( 'Review cycle not found.', 'sfs-hr' ) ];
        }
        if ( $cycle['status'] !== 'draft' ) {
            return [ 'success' => false, 'error' => __( 'Only draft cycles can be activated.', 'sfs-hr' ) ];
        }

        return self::update_cycle( $id, [ 'status' => 'active' ] );
    }

    /**
     * Mark a cycle as completed (status → completed).
     * All reviews must be submitted or acknowledged first.
     *
     * @param int $id Cycle ID.
     * @return array{ success: bool, error?: string }
     */
    public static function complete_cycle( int $id ): array {
        global $wpdb;

        $cycle = self::get_cycle( $id );
        if ( ! $cycle ) {
            return [ 'success' => false, 'error' => __( 'Review cycle not found.', 'sfs-hr' ) ];
        }
        if ( ! in_array( $cycle['status'], [ 'active', 'in_review', 'calibration' ], true ) ) {
            return [ 'success' => false, 'error' => __( 'Cycle cannot be completed from its current status.', 'sfs-hr' ) ];
        }

        // Check for still-pending reviews.
        $pending_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_performance_reviews
                 WHERE cycle_id = %d AND status IN ('pending','in_progress')",
                $id
            )
        );

        if ( $pending_count > 0 ) {
            return [
                'success' => false,
                /* translators: %d: count of pending reviews */
                'error'   => sprintf( __( '%d review(s) are still pending. Complete or cancel them before closing the cycle.', 'sfs-hr' ), $pending_count ),
            ];
        }

        return self::update_cycle( $id, [ 'status' => 'completed' ] );
    }

    // -------------------------------------------------------------------------
    // REVIEWS
    // -------------------------------------------------------------------------

    /**
     * Create a single review record.
     *
     * @param array $data {
     *   cycle_id, employee_id, reviewer_id, review_type,
     *   is_anonymous?, overall_rating?, strengths?, improvements?, comments?
     * }
     * @return array{ success: bool, id?: int, error?: string }
     */
    public static function create_review( array $data ): array {
        global $wpdb;

        foreach ( [ 'cycle_id', 'employee_id', 'reviewer_id', 'review_type' ] as $required ) {
            if ( empty( $data[ $required ] ) ) {
                /* translators: %s: field name */
                return [ 'success' => false, 'error' => sprintf( __( '%s is required.', 'sfs-hr' ), $required ) ];
            }
        }

        $valid_review_types = [ 'self', 'manager', 'peer', '360', 'external' ];
        if ( ! in_array( $data['review_type'], $valid_review_types, true ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid review type.', 'sfs-hr' ) ];
        }

        // Prevent duplicate reviews for same cycle/employee/reviewer/type.
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sfs_hr_performance_reviews
                 WHERE cycle_id = %d AND employee_id = %d AND reviewer_id = %d AND review_type = %s
                 LIMIT 1",
                (int) $data['cycle_id'],
                (int) $data['employee_id'],
                (int) $data['reviewer_id'],
                $data['review_type']
            )
        );
        if ( $exists ) {
            return [ 'success' => false, 'error' => __( 'A review of this type already exists for this employee/reviewer/cycle combination.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_performance_reviews',
            [
                'cycle_id'       => (int) $data['cycle_id'],
                'employee_id'    => (int) $data['employee_id'],
                'reviewer_id'    => (int) $data['reviewer_id'],
                'review_type'    => $data['review_type'],
                'overall_rating' => isset( $data['overall_rating'] ) ? (float) $data['overall_rating'] : null,
                'strengths'      => isset( $data['strengths'] ) ? sanitize_textarea_field( $data['strengths'] ) : null,
                'improvements'   => isset( $data['improvements'] ) ? sanitize_textarea_field( $data['improvements'] ) : null,
                'comments'       => isset( $data['comments'] ) ? sanitize_textarea_field( $data['comments'] ) : null,
                'responses_json' => null,
                'status'         => 'pending',
                'is_anonymous'   => isset( $data['is_anonymous'] ) ? (int) (bool) $data['is_anonymous'] : 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'success' => false, 'error' => __( 'Failed to create review.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Submit a review with structured responses and optional overall rating.
     *
     * @param int   $id        Review ID.
     * @param array $responses Keyed question responses or flat data array.
     *                         Recognised top-level keys: overall_rating, strengths,
     *                         improvements, comments, responses (array).
     * @return array{ success: bool, error?: string }
     */
    public static function submit_review( int $id, array $responses ): array {
        global $wpdb;

        $review = self::get_review( $id );
        if ( ! $review ) {
            return [ 'success' => false, 'error' => __( 'Review not found.', 'sfs-hr' ) ];
        }
        if ( ! in_array( $review['status'], [ 'pending', 'in_progress' ], true ) ) {
            return [ 'success' => false, 'error' => __( 'Only pending or in-progress reviews can be submitted.', 'sfs-hr' ) ];
        }

        $now    = current_time( 'mysql' );
        $fields = [
            'status'       => 'submitted',
            'submitted_at' => $now,
            'updated_at'   => $now,
        ];
        $formats = [ '%s', '%s', '%s' ];

        if ( array_key_exists( 'overall_rating', $responses ) && $responses['overall_rating'] !== null ) {
            $rating = (float) $responses['overall_rating'];
            $cycle  = self::get_cycle( (int) $review['cycle_id'] );
            $max    = $cycle ? (int) $cycle['rating_scale_max'] : 5;
            if ( $rating < 0 || $rating > $max ) {
                return [
                    'success' => false,
                    /* translators: %s: max rating */
                    'error'   => sprintf( __( 'Rating must be between 0 and %s.', 'sfs-hr' ), $max ),
                ];
            }
            $fields['overall_rating'] = $rating;
            $formats[]                = '%f';
        }
        if ( ! empty( $responses['strengths'] ) ) {
            $fields['strengths'] = sanitize_textarea_field( $responses['strengths'] );
            $formats[]           = '%s';
        }
        if ( ! empty( $responses['improvements'] ) ) {
            $fields['improvements'] = sanitize_textarea_field( $responses['improvements'] );
            $formats[]              = '%s';
        }
        if ( ! empty( $responses['comments'] ) ) {
            $fields['comments'] = sanitize_textarea_field( $responses['comments'] );
            $formats[]          = '%s';
        }
        if ( ! empty( $responses['responses'] ) && is_array( $responses['responses'] ) ) {
            $fields['responses_json'] = wp_json_encode( $responses['responses'] );
            $formats[]                = '%s';
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'sfs_hr_performance_reviews',
            $fields,
            [ 'id' => $id ],
            $formats,
            [ '%d' ]
        );

        if ( false === $result ) {
            return [ 'success' => false, 'error' => __( 'Failed to submit review.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    /**
     * Acknowledge a submitted review (employee confirms they have read it).
     *
     * @param int $review_id   Review ID.
     * @param int $employee_id The employee acknowledging (must match review's employee_id).
     * @return array{ success: bool, error?: string }
     */
    public static function acknowledge_review( int $review_id, int $employee_id ): array {
        global $wpdb;

        $review = self::get_review( $review_id );
        if ( ! $review ) {
            return [ 'success' => false, 'error' => __( 'Review not found.', 'sfs-hr' ) ];
        }
        if ( (int) $review['employee_id'] !== $employee_id ) {
            return [ 'success' => false, 'error' => __( 'You can only acknowledge your own review.', 'sfs-hr' ) ];
        }
        if ( $review['status'] !== 'submitted' ) {
            return [ 'success' => false, 'error' => __( 'Only submitted reviews can be acknowledged.', 'sfs-hr' ) ];
        }

        $now    = current_time( 'mysql' );
        $result = $wpdb->update(
            $wpdb->prefix . 'sfs_hr_performance_reviews',
            [
                'status'          => 'acknowledged',
                'acknowledged_at' => $now,
                'updated_at'      => $now,
            ],
            [ 'id' => $review_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $result ) {
            return [ 'success' => false, 'error' => __( 'Failed to acknowledge review.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    /**
     * Fetch a single review by ID.
     *
     * @param int $id Review ID.
     * @return array|null
     */
    public static function get_review( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_reviews WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        $row['responses_json'] = $row['responses_json']
            ? ( json_decode( $row['responses_json'], true ) ?: [] )
            : [];

        return $row;
    }

    /**
     * Get all reviews for an employee, optionally filtered by cycle.
     *
     * @param int      $employee_id Employee ID.
     * @param int|null $cycle_id    Optional cycle filter.
     * @return array
     */
    public static function get_employee_reviews( int $employee_id, ?int $cycle_id = null ): array {
        global $wpdb;

        $where  = [ 'r.employee_id = %d' ];
        $params = [ $employee_id ];

        if ( $cycle_id !== null ) {
            $where[]  = 'r.cycle_id = %d';
            $params[] = $cycle_id;
        }

        $sql = "SELECT r.*,
                       c.name AS cycle_name,
                       e.display_name AS reviewer_name
                FROM {$wpdb->prefix}sfs_hr_performance_reviews r
                LEFT JOIN {$wpdb->prefix}sfs_hr_performance_review_cycles c ON c.id = r.cycle_id
                LEFT JOIN {$wpdb->users} e ON e.ID = r.reviewer_id
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY r.created_at DESC";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        if ( ! $rows ) {
            return [];
        }

        foreach ( $rows as &$row ) {
            $row['responses_json'] = $row['responses_json']
                ? ( json_decode( $row['responses_json'], true ) ?: [] )
                : [];
        }
        unset( $row );

        return $rows;
    }

    /**
     * Get all pending or in-progress reviews assigned to a reviewer.
     *
     * @param int $reviewer_id Reviewer user ID.
     * @return array
     */
    public static function get_pending_reviews( int $reviewer_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*,
                        c.name AS cycle_name,
                        c.deadline,
                        e.display_name AS employee_name
                 FROM {$wpdb->prefix}sfs_hr_performance_reviews r
                 LEFT JOIN {$wpdb->prefix}sfs_hr_performance_review_cycles c ON c.id = r.cycle_id
                 LEFT JOIN {$wpdb->users} e ON e.ID = r.employee_id
                 WHERE r.reviewer_id = %d
                   AND r.status IN ('pending','in_progress')
                 ORDER BY c.deadline ASC, r.created_at ASC",
                $reviewer_id
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    // -------------------------------------------------------------------------
    // BULK OPERATIONS
    // -------------------------------------------------------------------------

    /**
     * Generate review records for every active employee in the cycle.
     *
     * For each active employee:
     *  - Creates a 'self' review (reviewer = employee's own WP user ID).
     *  - Creates a 'manager' review (reviewer = department manager_user_id).
     *  - If cycle review_types includes 'peer', creates a placeholder peer review
     *    with reviewer_id = 0 (to be assigned later).
     *
     * @param int   $cycle_id Cycle ID.
     * @param array $options {
     *   skip_existing?: bool  (default true — don't overwrite existing reviews),
     *   include_types?: array (override cycle's review_types)
     * }
     * @return array{ success: bool, created: int, skipped: int, errors: array, error?: string }
     */
    public static function generate_reviews_for_cycle( int $cycle_id, array $options = [] ): array {
        global $wpdb;

        $cycle = self::get_cycle( $cycle_id );
        if ( ! $cycle ) {
            return [ 'success' => false, 'created' => 0, 'skipped' => 0, 'errors' => [], 'error' => __( 'Review cycle not found.', 'sfs-hr' ) ];
        }
        if ( ! in_array( $cycle['status'], [ 'draft', 'active' ], true ) ) {
            return [ 'success' => false, 'created' => 0, 'skipped' => 0, 'errors' => [], 'error' => __( 'Reviews can only be generated for draft or active cycles.', 'sfs-hr' ) ];
        }

        $skip_existing  = $options['skip_existing'] ?? true;
        $types_to_use   = isset( $options['include_types'] ) && is_array( $options['include_types'] )
            ? $options['include_types']
            : $cycle['review_types'];

        $tbl_emp  = $wpdb->prefix . 'sfs_hr_employees';
        $tbl_dept = $wpdb->prefix . 'sfs_hr_departments';

        // Fetch all active employees with their department manager.
        $employees = $wpdb->get_results(
            "SELECT e.id AS employee_id,
                    e.user_id,
                    e.department_id,
                    d.manager_user_id
             FROM {$tbl_emp} e
             LEFT JOIN {$tbl_dept} d ON d.id = e.department_id
             WHERE e.status = 'active'",
            ARRAY_A
        );

        if ( ! $employees ) {
            return [ 'success' => true, 'created' => 0, 'skipped' => 0, 'errors' => [], 'message' => __( 'No active employees found.', 'sfs-hr' ) ];
        }

        $created = 0;
        $skipped = 0;
        $errors  = [];

        foreach ( $employees as $emp ) {
            $employee_id       = (int) $emp['employee_id'];
            $employee_user_id  = (int) $emp['user_id'];
            $manager_user_id   = (int) $emp['manager_user_id'];

            // Build list of (reviewer_id, review_type) pairs to create.
            $to_create = [];

            if ( in_array( 'self', $types_to_use, true ) && $employee_user_id ) {
                $to_create[] = [ 'reviewer_id' => $employee_user_id, 'review_type' => 'self' ];
            }

            if ( in_array( 'manager', $types_to_use, true ) && $manager_user_id ) {
                $to_create[] = [ 'reviewer_id' => $manager_user_id, 'review_type' => 'manager' ];
            }

            if ( in_array( 'peer', $types_to_use, true ) ) {
                // Placeholder peer review — reviewer_id 0 indicates "to be assigned".
                $to_create[] = [ 'reviewer_id' => 0, 'review_type' => 'peer' ];
            }

            if ( in_array( '360', $types_to_use, true ) ) {
                // 360 review: manager if available, else placeholder.
                $reviewer = $manager_user_id ?: 0;
                $to_create[] = [ 'reviewer_id' => $reviewer, 'review_type' => '360' ];
            }

            foreach ( $to_create as $spec ) {
                if ( $skip_existing ) {
                    $exists = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}sfs_hr_performance_reviews
                             WHERE cycle_id = %d AND employee_id = %d AND reviewer_id = %d AND review_type = %s
                             LIMIT 1",
                            $cycle_id,
                            $employee_id,
                            $spec['reviewer_id'],
                            $spec['review_type']
                        )
                    );
                    if ( $exists ) {
                        $skipped++;
                        continue;
                    }
                }

                $result = self::create_review( [
                    'cycle_id'    => $cycle_id,
                    'employee_id' => $employee_id,
                    'reviewer_id' => $spec['reviewer_id'],
                    'review_type' => $spec['review_type'],
                ] );

                if ( $result['success'] ) {
                    $created++;
                } else {
                    $errors[] = [
                        'employee_id' => $employee_id,
                        'review_type' => $spec['review_type'],
                        'error'       => $result['error'],
                    ];
                }
            }
        }

        return [
            'success' => true,
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    /**
     * Get completion statistics for a cycle.
     *
     * @param int $cycle_id Cycle ID.
     * @return array{
     *   total: int,
     *   pending: int,
     *   in_progress: int,
     *   submitted: int,
     *   acknowledged: int,
     *   completion_pct: float
     * }
     */
    public static function get_cycle_completion_stats( int $cycle_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS cnt
                 FROM {$wpdb->prefix}sfs_hr_performance_reviews
                 WHERE cycle_id = %d
                 GROUP BY status",
                $cycle_id
            ),
            ARRAY_A
        );

        $counts = [
            'pending'      => 0,
            'in_progress'  => 0,
            'submitted'    => 0,
            'acknowledged' => 0,
        ];
        foreach ( $rows as $row ) {
            if ( array_key_exists( $row['status'], $counts ) ) {
                $counts[ $row['status'] ] = (int) $row['cnt'];
            }
        }

        $total     = array_sum( $counts );
        $completed = $counts['submitted'] + $counts['acknowledged'];
        $pct       = $total > 0 ? round( ( $completed / $total ) * 100, 1 ) : 0.0;

        return array_merge( $counts, [ 'total' => $total, 'completion_pct' => $pct ] );
    }

    // -------------------------------------------------------------------------
    // TEMPLATES
    // -------------------------------------------------------------------------

    /**
     * Create a review template.
     *
     * @param array $data {
     *   name, description, questions_json (array), is_default?
     * }
     * @return array{ success: bool, id?: int, error?: string }
     */
    public static function create_template( array $data ): array {
        global $wpdb;

        if ( empty( $data['name'] ) ) {
            return [ 'success' => false, 'error' => __( 'Template name is required.', 'sfs-hr' ) ];
        }

        $questions = $data['questions_json'] ?? [];
        if ( ! is_array( $questions ) ) {
            return [ 'success' => false, 'error' => __( 'questions_json must be an array.', 'sfs-hr' ) ];
        }

        $is_default = ! empty( $data['is_default'] ) ? 1 : 0;

        // If setting as default, unset previous default.
        if ( $is_default ) {
            $wpdb->update(
                $wpdb->prefix . 'sfs_hr_performance_review_templates',
                [ 'is_default' => 0 ],
                [ 'is_default' => 1 ],
                [ '%d' ],
                [ '%d' ]
            );
        }

        $now      = current_time( 'mysql' );
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_performance_review_templates',
            [
                'name'           => sanitize_text_field( $data['name'] ),
                'description'    => sanitize_textarea_field( $data['description'] ?? '' ),
                'questions_json' => wp_json_encode( $questions ),
                'is_default'     => $is_default,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'success' => false, 'error' => __( 'Failed to create template.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Fetch a single review template.
     *
     * @param int $id Template ID.
     * @return array|null
     */
    public static function get_template( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_review_templates WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        $row['questions_json'] = json_decode( $row['questions_json'] ?? '[]', true ) ?: [];
        return $row;
    }

    /**
     * List all review templates.
     *
     * @return array
     */
    public static function list_templates(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_review_templates ORDER BY is_default DESC, name ASC",
            ARRAY_A
        );
        if ( ! $rows ) {
            return [];
        }

        foreach ( $rows as &$row ) {
            $row['questions_json'] = json_decode( $row['questions_json'] ?? '[]', true ) ?: [];
        }
        unset( $row );

        return $rows;
    }

    // -------------------------------------------------------------------------
    // COMPETENCIES
    // -------------------------------------------------------------------------

    /**
     * Create a competency.
     *
     * @param array $data { name, name_ar?, description?, category?, is_active? }
     * @return array{ success: bool, id?: int, error?: string }
     */
    public static function create_competency( array $data ): array {
        global $wpdb;

        if ( empty( $data['name'] ) ) {
            return [ 'success' => false, 'error' => __( 'Competency name is required.', 'sfs-hr' ) ];
        }

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_performance_competencies',
            [
                'name'        => sanitize_text_field( $data['name'] ),
                'name_ar'     => isset( $data['name_ar'] ) ? sanitize_text_field( $data['name_ar'] ) : null,
                'description' => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
                'category'    => isset( $data['category'] ) ? sanitize_text_field( $data['category'] ) : '',
                'is_active'   => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'success' => false, 'error' => __( 'Failed to create competency.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * List competencies with optional filters.
     *
     * @param array $filters { category?: string, is_active?: bool }
     * @return array
     */
    public static function list_competencies( array $filters = [] ): array {
        global $wpdb;

        $where  = [ '1=1' ];
        $params = [];

        if ( isset( $filters['is_active'] ) ) {
            $where[]  = 'is_active = %d';
            $params[] = (int) (bool) $filters['is_active'];
        }
        if ( ! empty( $filters['category'] ) ) {
            $where[]  = 'category = %s';
            $params[] = $filters['category'];
        }

        $sql = "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_competencies
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY category ASC, name ASC";

        if ( $params ) {
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( $sql, ARRAY_A );
        }

        return $rows ?: [];
    }

    // -------------------------------------------------------------------------
    // PIPs
    // -------------------------------------------------------------------------

    /**
     * Create a Performance Improvement Plan.
     *
     * @param array $data {
     *   employee_id, initiated_by, reason, goals,
     *   start_date, end_date, review_date?, notes?
     * }
     * @return array{ success: bool, id?: int, error?: string }
     */
    public static function create_pip( array $data ): array {
        global $wpdb;

        foreach ( [ 'employee_id', 'initiated_by', 'reason', 'goals', 'start_date', 'end_date' ] as $required ) {
            if ( empty( $data[ $required ] ) ) {
                /* translators: %s: field name */
                return [ 'success' => false, 'error' => sprintf( __( '%s is required.', 'sfs-hr' ), $required ) ];
            }
        }

        if ( strtotime( $data['end_date'] ) <= strtotime( $data['start_date'] ) ) {
            return [ 'success' => false, 'error' => __( 'End date must be after start date.', 'sfs-hr' ) ];
        }

        $now      = current_time( 'mysql' );
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_performance_pips',
            [
                'employee_id'  => (int) $data['employee_id'],
                'initiated_by' => (int) $data['initiated_by'],
                'reason'       => sanitize_textarea_field( $data['reason'] ),
                'goals'        => sanitize_textarea_field( $data['goals'] ),
                'start_date'   => $data['start_date'],
                'end_date'     => $data['end_date'],
                'review_date'  => $data['review_date'] ?? null,
                'status'       => 'active',
                'outcome'      => null,
                'notes'        => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'success' => false, 'error' => __( 'Failed to create PIP.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Update an existing PIP.
     *
     * @param int   $id   PIP ID.
     * @param array $data Fields to update.
     * @return array{ success: bool, error?: string }
     */
    public static function update_pip( int $id, array $data ): array {
        global $wpdb;

        $pip = self::get_pip( $id );
        if ( ! $pip ) {
            return [ 'success' => false, 'error' => __( 'PIP not found.', 'sfs-hr' ) ];
        }

        $fields  = [];
        $formats = [];

        $text_fields = [ 'reason', 'goals', 'notes' ];
        foreach ( $text_fields as $f ) {
            if ( isset( $data[ $f ] ) ) {
                $fields[ $f ] = sanitize_textarea_field( $data[ $f ] );
                $formats[]    = '%s';
            }
        }

        $date_fields = [ 'start_date', 'end_date', 'review_date' ];
        foreach ( $date_fields as $f ) {
            if ( isset( $data[ $f ] ) ) {
                $fields[ $f ] = $data[ $f ];
                $formats[]    = '%s';
            }
        }

        if ( isset( $data['status'] ) ) {
            $valid_statuses = [ 'active', 'extended', 'completed', 'terminated' ];
            if ( ! in_array( $data['status'], $valid_statuses, true ) ) {
                return [ 'success' => false, 'error' => __( 'Invalid PIP status.', 'sfs-hr' ) ];
            }
            $fields['status'] = $data['status'];
            $formats[]        = '%s';
        }

        if ( isset( $data['outcome'] ) ) {
            $valid_outcomes = [ 'improved', 'no_change', 'terminated' ];
            if ( ! in_array( $data['outcome'], $valid_outcomes, true ) ) {
                return [ 'success' => false, 'error' => __( 'Invalid PIP outcome.', 'sfs-hr' ) ];
            }
            $fields['outcome'] = $data['outcome'];
            $formats[]         = '%s';
        }

        if ( empty( $fields ) ) {
            return [ 'success' => true ];
        }

        $fields['updated_at'] = current_time( 'mysql' );
        $formats[]            = '%s';

        $result = $wpdb->update(
            $wpdb->prefix . 'sfs_hr_performance_pips',
            $fields,
            [ 'id' => $id ],
            $formats,
            [ '%d' ]
        );

        if ( false === $result ) {
            return [ 'success' => false, 'error' => __( 'Failed to update PIP.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    /**
     * Fetch a single PIP by ID.
     *
     * @param int $id PIP ID.
     * @return array|null
     */
    public static function get_pip( int $id ): ?array {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_pips WHERE id = %d LIMIT 1",
                $id
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Get all PIPs for an employee.
     *
     * @param int $employee_id Employee ID.
     * @return array
     */
    public static function get_employee_pips( int $employee_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, u.display_name AS initiated_by_name
                 FROM {$wpdb->prefix}sfs_hr_performance_pips p
                 LEFT JOIN {$wpdb->users} u ON u.ID = p.initiated_by
                 WHERE p.employee_id = %d
                 ORDER BY p.created_at DESC",
                $employee_id
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Complete a PIP and record its outcome.
     *
     * @param int    $id      PIP ID.
     * @param string $outcome One of: 'improved', 'no_change', 'terminated'.
     * @return array{ success: bool, error?: string }
     */
    public static function complete_pip( int $id, string $outcome ): array {
        $valid = [ 'improved', 'no_change', 'terminated' ];
        if ( ! in_array( $outcome, $valid, true ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid outcome. Must be: improved, no_change, or terminated.', 'sfs-hr' ) ];
        }

        $pip = self::get_pip( $id );
        if ( ! $pip ) {
            return [ 'success' => false, 'error' => __( 'PIP not found.', 'sfs-hr' ) ];
        }
        if ( $pip['status'] === 'completed' ) {
            return [ 'success' => false, 'error' => __( 'PIP is already completed.', 'sfs-hr' ) ];
        }

        return self::update_pip( $id, [ 'status' => 'completed', 'outcome' => $outcome ] );
    }

    // -------------------------------------------------------------------------
    // CALIBRATION
    // -------------------------------------------------------------------------

    /**
     * Get calibration data for a cycle — submitted/acknowledged reviews with
     * overall ratings, grouped by department.
     *
     * @param int      $cycle_id Cycle ID.
     * @param int|null $dept_id  Optional department filter.
     * @return array{
     *   reviews: array,
     *   distribution: array,
     *   avg_rating: float,
     *   dept_averages: array
     * }
     */
    public static function get_calibration_data( int $cycle_id, ?int $dept_id = null ): array {
        global $wpdb;

        $tbl_emp  = $wpdb->prefix . 'sfs_hr_employees';
        $tbl_dept = $wpdb->prefix . 'sfs_hr_departments';
        $tbl_rev  = $wpdb->prefix . 'sfs_hr_performance_reviews';

        $where  = [
            'r.cycle_id = %d',
            "r.status IN ('submitted','acknowledged')",
            'r.overall_rating IS NOT NULL',
        ];
        $params = [ $cycle_id ];

        if ( $dept_id !== null ) {
            $where[]  = 'e.department_id = %d';
            $params[] = $dept_id;
        }

        $sql = "SELECT r.id,
                       r.employee_id,
                       r.reviewer_id,
                       r.review_type,
                       r.overall_rating,
                       r.strengths,
                       r.improvements,
                       r.status,
                       r.is_anonymous,
                       e.department_id,
                       d.name AS department_name,
                       eu.display_name AS employee_name,
                       ru.display_name AS reviewer_name
                FROM {$tbl_rev} r
                LEFT JOIN {$tbl_emp} e ON e.id = r.employee_id
                LEFT JOIN {$tbl_dept} d ON d.id = e.department_id
                LEFT JOIN {$wpdb->users} eu ON eu.ID = r.employee_id
                LEFT JOIN {$wpdb->users} ru ON ru.ID = r.reviewer_id
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY d.name ASC, e.id ASC, r.review_type ASC";

        $reviews = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: [];

        // Rating distribution buckets (by integer floor).
        $distribution = [];
        $total_rating  = 0.0;
        $dept_sums     = [];
        $dept_counts   = [];

        foreach ( $reviews as $rev ) {
            $rating = (float) $rev['overall_rating'];
            $bucket = (string) (int) floor( $rating );
            $distribution[ $bucket ] = ( $distribution[ $bucket ] ?? 0 ) + 1;
            $total_rating += $rating;

            $dept_key = $rev['department_id'] ?? 'unknown';
            $dept_sums[ $dept_key ]   = ( $dept_sums[ $dept_key ] ?? 0.0 ) + $rating;
            $dept_counts[ $dept_key ] = ( $dept_counts[ $dept_key ] ?? 0 ) + 1;

            // Mask reviewer name if anonymous.
            if ( (int) $rev['is_anonymous'] === 1 ) {
                $reviews[ array_search( $rev, $reviews, true ) ]['reviewer_name'] = __( 'Anonymous', 'sfs-hr' );
            }
        }

        $avg_rating = count( $reviews ) > 0
            ? round( $total_rating / count( $reviews ), 2 )
            : 0.0;

        $dept_averages = [];
        foreach ( $dept_sums as $dept_key => $sum ) {
            $dept_averages[ $dept_key ] = round( $sum / $dept_counts[ $dept_key ], 2 );
        }

        ksort( $distribution );

        return [
            'reviews'       => $reviews,
            'distribution'  => $distribution,
            'avg_rating'    => $avg_rating,
            'dept_averages' => $dept_averages,
        ];
    }

    /**
     * Adjust a review's overall rating during calibration.
     * Stores justification in the review's comments (appended).
     *
     * @param int    $review_id     Review ID.
     * @param float  $new_rating    New rating value.
     * @param string $justification Reason for adjustment.
     * @param int    $adjusted_by   User ID making the adjustment.
     * @return array{ success: bool, error?: string }
     */
    public static function adjust_rating( int $review_id, float $new_rating, string $justification, int $adjusted_by ): array {
        global $wpdb;

        $review = self::get_review( $review_id );
        if ( ! $review ) {
            return [ 'success' => false, 'error' => __( 'Review not found.', 'sfs-hr' ) ];
        }

        $cycle = self::get_cycle( (int) $review['cycle_id'] );
        if ( $cycle ) {
            $max = (int) $cycle['rating_scale_max'];
            if ( $new_rating < 0 || $new_rating > $max ) {
                return [
                    'success' => false,
                    /* translators: %s: max rating */
                    'error'   => sprintf( __( 'Rating must be between 0 and %s.', 'sfs-hr' ), $max ),
                ];
            }
        }

        if ( empty( trim( $justification ) ) ) {
            return [ 'success' => false, 'error' => __( 'Justification is required for rating adjustments.', 'sfs-hr' ) ];
        }

        $adjuster      = get_userdata( $adjusted_by );
        $adjuster_name = $adjuster ? $adjuster->display_name : "User #{$adjusted_by}";
        $timestamp     = current_time( 'mysql' );

        $calibration_note = sprintf(
            /* translators: 1: old rating, 2: new rating, 3: adjuster name, 4: date, 5: justification */
            __( '[Calibration] Rating adjusted from %1$s to %2$s by %3$s on %4$s. Reason: %5$s', 'sfs-hr' ),
            $review['overall_rating'] ?? 'N/A',
            $new_rating,
            $adjuster_name,
            $timestamp,
            sanitize_textarea_field( $justification )
        );

        $existing_comments = $review['comments'] ?? '';
        $new_comments      = trim( $existing_comments . "\n\n" . $calibration_note );

        $result = $wpdb->update(
            $wpdb->prefix . 'sfs_hr_performance_reviews',
            [
                'overall_rating' => $new_rating,
                'comments'       => $new_comments,
                'updated_at'     => $timestamp,
            ],
            [ 'id' => $review_id ],
            [ '%f', '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $result ) {
            return [ 'success' => false, 'error' => __( 'Failed to adjust rating.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    // -------------------------------------------------------------------------
    // ANALYTICS
    // -------------------------------------------------------------------------

    /**
     * Get comprehensive analytics for a review cycle.
     *
     * @param int $cycle_id Cycle ID.
     * @return array{
     *   cycle: array|null,
     *   completion: array,
     *   rating_distribution: array,
     *   avg_by_review_type: array,
     *   top_performers: array,
     *   low_performers: array,
     *   dept_breakdown: array
     * }
     */
    public static function get_cycle_analytics( int $cycle_id ): array {
        global $wpdb;

        $cycle      = self::get_cycle( $cycle_id );
        $completion = self::get_cycle_completion_stats( $cycle_id );

        $tbl_rev  = $wpdb->prefix . 'sfs_hr_performance_reviews';
        $tbl_emp  = $wpdb->prefix . 'sfs_hr_employees';
        $tbl_dept = $wpdb->prefix . 'sfs_hr_departments';

        // Average rating by review type.
        $avg_by_type = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT review_type,
                        ROUND(AVG(overall_rating), 2) AS avg_rating,
                        COUNT(*) AS count
                 FROM {$tbl_rev}
                 WHERE cycle_id = %d
                   AND overall_rating IS NOT NULL
                   AND status IN ('submitted','acknowledged')
                 GROUP BY review_type",
                $cycle_id
            ),
            ARRAY_A
        ) ?: [];

        // Top 10 performers (highest avg rating across their reviews in the cycle).
        $top_performers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.employee_id,
                        u.display_name AS employee_name,
                        d.name AS department_name,
                        ROUND(AVG(r.overall_rating), 2) AS avg_rating,
                        COUNT(*) AS review_count
                 FROM {$tbl_rev} r
                 LEFT JOIN {$tbl_emp} e ON e.id = r.employee_id
                 LEFT JOIN {$tbl_dept} d ON d.id = e.department_id
                 LEFT JOIN {$wpdb->users} u ON u.ID = r.employee_id
                 WHERE r.cycle_id = %d
                   AND r.overall_rating IS NOT NULL
                   AND r.status IN ('submitted','acknowledged')
                 GROUP BY r.employee_id
                 ORDER BY avg_rating DESC
                 LIMIT 10",
                $cycle_id
            ),
            ARRAY_A
        ) ?: [];

        // Bottom 10 performers.
        $low_performers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.employee_id,
                        u.display_name AS employee_name,
                        d.name AS department_name,
                        ROUND(AVG(r.overall_rating), 2) AS avg_rating,
                        COUNT(*) AS review_count
                 FROM {$tbl_rev} r
                 LEFT JOIN {$tbl_emp} e ON e.id = r.employee_id
                 LEFT JOIN {$tbl_dept} d ON d.id = e.department_id
                 LEFT JOIN {$wpdb->users} u ON u.ID = r.employee_id
                 WHERE r.cycle_id = %d
                   AND r.overall_rating IS NOT NULL
                   AND r.status IN ('submitted','acknowledged')
                 GROUP BY r.employee_id
                 ORDER BY avg_rating ASC
                 LIMIT 10",
                $cycle_id
            ),
            ARRAY_A
        ) ?: [];

        // Department breakdown.
        $dept_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.department_id,
                        d.name AS department_name,
                        ROUND(AVG(r.overall_rating), 2) AS avg_rating,
                        COUNT(DISTINCT r.employee_id) AS employee_count,
                        COUNT(*) AS review_count,
                        SUM(CASE WHEN r.status IN ('submitted','acknowledged') THEN 1 ELSE 0 END) AS completed_count
                 FROM {$tbl_rev} r
                 LEFT JOIN {$tbl_emp} e ON e.id = r.employee_id
                 LEFT JOIN {$tbl_dept} d ON d.id = e.department_id
                 WHERE r.cycle_id = %d
                 GROUP BY e.department_id, d.name
                 ORDER BY d.name ASC",
                $cycle_id
            ),
            ARRAY_A
        ) ?: [];

        // Rating distribution (all submitted/acknowledged reviews).
        $distribution_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT FLOOR(overall_rating) AS rating_bucket, COUNT(*) AS count
                 FROM {$tbl_rev}
                 WHERE cycle_id = %d
                   AND overall_rating IS NOT NULL
                   AND status IN ('submitted','acknowledged')
                 GROUP BY FLOOR(overall_rating)
                 ORDER BY rating_bucket ASC",
                $cycle_id
            ),
            ARRAY_A
        ) ?: [];

        $distribution = [];
        foreach ( $distribution_rows as $d ) {
            $distribution[ (string) $d['rating_bucket'] ] = (int) $d['count'];
        }

        return [
            'cycle'               => $cycle,
            'completion'          => $completion,
            'rating_distribution' => $distribution,
            'avg_by_review_type'  => $avg_by_type,
            'top_performers'      => $top_performers,
            'low_performers'      => $low_performers,
            'dept_breakdown'      => $dept_breakdown,
        ];
    }

    /**
     * Get historical performance trend for an employee (last N cycles).
     *
     * @param int $employee_id Employee ID.
     * @param int $limit       Number of past cycles to include.
     * @return array  Ordered oldest → newest, each entry has cycle metadata + avg_rating.
     */
    public static function get_employee_performance_trend( int $employee_id, int $limit = 5 ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id AS cycle_id,
                        c.name AS cycle_name,
                        c.start_date,
                        c.end_date,
                        c.cycle_type,
                        ROUND(AVG(r.overall_rating), 2) AS avg_rating,
                        COUNT(*) AS review_count
                 FROM {$wpdb->prefix}sfs_hr_performance_reviews r
                 JOIN {$wpdb->prefix}sfs_hr_performance_review_cycles c ON c.id = r.cycle_id
                 WHERE r.employee_id = %d
                   AND r.overall_rating IS NOT NULL
                   AND r.status IN ('submitted','acknowledged')
                   AND c.status IN ('completed','calibration','in_review')
                 GROUP BY c.id, c.name, c.start_date, c.end_date, c.cycle_type
                 ORDER BY c.end_date DESC
                 LIMIT %d",
                $employee_id,
                max( 1, $limit )
            ),
            ARRAY_A
        ) ?: [];

        // Return oldest → newest for charting.
        return array_reverse( $rows );
    }

    /**
     * Get performance analytics aggregated for a department.
     *
     * @param int      $dept_id  Department ID.
     * @param int|null $cycle_id Optional; if null, aggregates across all completed cycles.
     * @return array{
     *   dept_id: int,
     *   cycle_id: int|null,
     *   avg_rating: float,
     *   employee_count: int,
     *   review_count: int,
     *   rating_distribution: array,
     *   top_performers: array,
     *   review_type_breakdown: array
     * }
     */
    public static function get_department_analytics( int $dept_id, ?int $cycle_id = null ): array {
        global $wpdb;

        $tbl_rev  = $wpdb->prefix . 'sfs_hr_performance_reviews';
        $tbl_emp  = $wpdb->prefix . 'sfs_hr_employees';
        $tbl_cyc  = $wpdb->prefix . 'sfs_hr_performance_review_cycles';

        $where  = [
            'e.department_id = %d',
            "r.overall_rating IS NOT NULL",
            "r.status IN ('submitted','acknowledged')",
        ];
        $params = [ $dept_id ];

        if ( $cycle_id !== null ) {
            $where[]  = 'r.cycle_id = %d';
            $params[] = $cycle_id;
        } else {
            // Only completed cycles when no specific cycle is given.
            $where[]  = "c.status = 'completed'";
        }

        $base_join = "FROM {$tbl_rev} r
                      LEFT JOIN {$tbl_emp} e ON e.id = r.employee_id
                      LEFT JOIN {$tbl_cyc} c ON c.id = r.cycle_id";
        $base_where = 'WHERE ' . implode( ' AND ', $where );

        // Summary stats.
        $summary = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ROUND(AVG(r.overall_rating), 2) AS avg_rating,
                        COUNT(DISTINCT r.employee_id) AS employee_count,
                        COUNT(*) AS review_count
                 {$base_join}
                 {$base_where}",
                $params
            ),
            ARRAY_A
        ) ?: [ 'avg_rating' => 0.0, 'employee_count' => 0, 'review_count' => 0 ];

        // Rating distribution.
        $dist_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT FLOOR(r.overall_rating) AS bucket, COUNT(*) AS count
                 {$base_join}
                 {$base_where}
                 GROUP BY FLOOR(r.overall_rating)
                 ORDER BY bucket ASC",
                $params
            ),
            ARRAY_A
        ) ?: [];

        $distribution = [];
        foreach ( $dist_rows as $d ) {
            $distribution[ (string) $d['bucket'] ] = (int) $d['count'];
        }

        // Top 5 performers in dept.
        $top_performers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.employee_id,
                        u.display_name AS employee_name,
                        ROUND(AVG(r.overall_rating), 2) AS avg_rating,
                        COUNT(*) AS review_count
                 {$base_join}
                 LEFT JOIN {$wpdb->users} u ON u.ID = r.employee_id
                 {$base_where}
                 GROUP BY r.employee_id
                 ORDER BY avg_rating DESC
                 LIMIT 5",
                $params
            ),
            ARRAY_A
        ) ?: [];

        // Review type breakdown.
        $type_breakdown = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.review_type,
                        ROUND(AVG(r.overall_rating), 2) AS avg_rating,
                        COUNT(*) AS count
                 {$base_join}
                 {$base_where}
                 GROUP BY r.review_type",
                $params
            ),
            ARRAY_A
        ) ?: [];

        return [
            'dept_id'              => $dept_id,
            'cycle_id'             => $cycle_id,
            'avg_rating'           => (float) ( $summary['avg_rating'] ?? 0.0 ),
            'employee_count'       => (int) ( $summary['employee_count'] ?? 0 ),
            'review_count'         => (int) ( $summary['review_count'] ?? 0 ),
            'rating_distribution'  => $distribution,
            'top_performers'       => $top_performers,
            'review_type_breakdown' => $type_breakdown,
        ];
    }
}
