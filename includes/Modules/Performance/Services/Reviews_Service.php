<?php
namespace SFS\HR\Modules\Performance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Reviews Service
 *
 * Manages performance reviews including:
 * - Review creation and management
 * - Review criteria/templates
 * - Rating calculations
 *
 * @version 1.0.0
 */
class Reviews_Service {

    /**
     * Save a review (create or update).
     *
     * @param array $data Review data
     * @return int|\WP_Error Review ID or error
     */
    public static function save_review( array $data ) {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_reviews';

        // Validate required fields
        if ( empty( $data['employee_id'] ) ) {
            return new \WP_Error( 'missing_employee', __( 'Employee ID is required', 'sfs-hr' ) );
        }
        if ( empty( $data['period_start'] ) || empty( $data['period_end'] ) ) {
            return new \WP_Error( 'missing_period', __( 'Review period is required', 'sfs-hr' ) );
        }

        $now = current_time( 'mysql' );

        // Calculate overall rating from individual ratings
        $ratings = isset( $data['ratings'] ) ? (array) $data['ratings'] : [];
        $overall_rating = self::calculate_overall_rating( $ratings );

        $review_data = [
            'employee_id'       => (int) $data['employee_id'],
            'reviewer_id'       => (int) ( $data['reviewer_id'] ?? get_current_user_id() ),
            'review_type'       => in_array( $data['review_type'] ?? '', [ 'self', 'manager', 'peer', '360' ], true )
                ? $data['review_type'] : 'manager',
            'review_cycle'      => ! empty( $data['review_cycle'] ) ? sanitize_text_field( $data['review_cycle'] ) : null,
            'period_start'      => sanitize_text_field( $data['period_start'] ),
            'period_end'        => sanitize_text_field( $data['period_end'] ),
            'status'            => in_array( $data['status'] ?? '', [ 'draft', 'pending', 'submitted', 'acknowledged' ], true )
                ? $data['status'] : 'draft',
            'overall_rating'    => $overall_rating,
            'ratings_json'      => wp_json_encode( $ratings ),
            'strengths'         => sanitize_textarea_field( $data['strengths'] ?? '' ),
            'improvements'      => sanitize_textarea_field( $data['improvements'] ?? '' ),
            'comments'          => sanitize_textarea_field( $data['comments'] ?? '' ),
            'employee_comments' => sanitize_textarea_field( $data['employee_comments'] ?? '' ),
            'due_date'          => ! empty( $data['due_date'] ) ? sanitize_text_field( $data['due_date'] ) : null,
            'updated_at'        => $now,
        ];

        $review_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $review_id > 0 ) {
            // Update existing review
            $wpdb->update( $table, $review_data, [ 'id' => $review_id ] );
        } else {
            // Create new review
            $review_data['created_at'] = $now;
            $wpdb->insert( $table, $review_data );
            $review_id = (int) $wpdb->insert_id;
        }

        return $review_id;
    }

    /**
     * Calculate overall rating from individual criterion ratings.
     *
     * @param array $ratings Array of [criterion_id => rating]
     * @return float|null
     */
    public static function calculate_overall_rating( array $ratings ): ?float {
        if ( empty( $ratings ) ) {
            return null;
        }

        global $wpdb;
        $criteria_table = $wpdb->prefix . 'sfs_hr_performance_review_criteria';

        // Get criteria with weights
        $criteria = $wpdb->get_results(
            "SELECT id, weight FROM {$criteria_table} WHERE active = 1",
            OBJECT_K
        );

        $total_weight = 0;
        $weighted_sum = 0;

        foreach ( $ratings as $criterion_id => $rating ) {
            $weight = isset( $criteria[ $criterion_id ] ) ? (int) $criteria[ $criterion_id ]->weight : 100;
            $rating = max( 1, min( 5, (float) $rating ) ); // Clamp to 1-5 scale

            $weighted_sum += $rating * $weight;
            $total_weight += $weight;
        }

        if ( $total_weight === 0 ) {
            return null;
        }

        return round( $weighted_sum / $total_weight, 2 );
    }

    /**
     * Get a single review.
     *
     * @param int $review_id
     * @return object|null
     */
    public static function get_review( int $review_id ): ?object {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_reviews';

        $review = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*,
                    e.first_name as employee_first_name,
                    e.last_name as employee_last_name,
                    e.employee_code,
                    reviewer.display_name as reviewer_name
             FROM {$table} r
             LEFT JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = r.employee_id
             LEFT JOIN {$wpdb->users} reviewer ON reviewer.ID = r.reviewer_id
             WHERE r.id = %d",
            $review_id
        ) );

        if ( $review && $review->ratings_json ) {
            $review->ratings = json_decode( $review->ratings_json, true );
        }

        return $review;
    }

    /**
     * Get reviews for an employee.
     *
     * @param int    $employee_id
     * @param string $status
     * @return array
     */
    public static function get_employee_reviews( int $employee_id, string $status = '' ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_reviews';

        $where = [ $wpdb->prepare( "r.employee_id = %d", $employee_id ) ];

        if ( ! empty( $status ) ) {
            $where[] = $wpdb->prepare( "r.status = %s", $status );
        }

        $where_sql = implode( ' AND ', $where );

        $reviews = $wpdb->get_results(
            "SELECT r.*,
                    reviewer.display_name as reviewer_name
             FROM {$table} r
             LEFT JOIN {$wpdb->users} reviewer ON reviewer.ID = r.reviewer_id
             WHERE {$where_sql}
             ORDER BY r.period_end DESC, r.created_at DESC"
        );

        foreach ( $reviews as $review ) {
            if ( $review->ratings_json ) {
                $review->ratings = json_decode( $review->ratings_json, true );
            }
        }

        return $reviews;
    }

    /**
     * Get pending reviews for a reviewer.
     *
     * @param int $reviewer_id
     * @return array
     */
    public static function get_pending_reviews( int $reviewer_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_reviews';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*,
                    e.first_name, e.last_name, e.employee_code
             FROM {$table} r
             JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = r.employee_id
             WHERE r.reviewer_id = %d
               AND r.status IN ('draft', 'pending')
             ORDER BY r.due_date ASC, r.period_end ASC",
            $reviewer_id
        ) );
    }

    /**
     * Acknowledge a review (employee acknowledges they've read it).
     *
     * @param int $review_id
     * @param int $employee_id
     * @param string $comments Employee's comments
     * @return true|\WP_Error
     */
    public static function acknowledge_review( int $review_id, int $employee_id, string $comments = '' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_reviews';

        // Verify review exists and belongs to employee
        $review = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, employee_id, status FROM {$table} WHERE id = %d",
            $review_id
        ) );

        if ( ! $review ) {
            return new \WP_Error( 'not_found', __( 'Review not found', 'sfs-hr' ) );
        }

        if ( (int) $review->employee_id !== $employee_id ) {
            return new \WP_Error( 'forbidden', __( 'You cannot acknowledge this review', 'sfs-hr' ) );
        }

        if ( $review->status !== 'submitted' ) {
            return new \WP_Error( 'invalid_status', __( 'Review is not ready for acknowledgment', 'sfs-hr' ) );
        }

        $wpdb->update(
            $table,
            [
                'status'            => 'acknowledged',
                'employee_comments' => sanitize_textarea_field( $comments ),
                'acknowledged_at'   => current_time( 'mysql' ),
                'updated_at'        => current_time( 'mysql' ),
            ],
            [ 'id' => $review_id ]
        );

        return true;
    }

    /**
     * Submit a review.
     *
     * @param int $review_id
     * @return true|\WP_Error
     */
    public static function submit_review( int $review_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_reviews';

        $review = self::get_review( $review_id );

        if ( ! $review ) {
            return new \WP_Error( 'not_found', __( 'Review not found', 'sfs-hr' ) );
        }

        if ( $review->status !== 'draft' && $review->status !== 'pending' ) {
            return new \WP_Error( 'invalid_status', __( 'Review cannot be submitted', 'sfs-hr' ) );
        }

        $wpdb->update(
            $table,
            [
                'status'     => 'submitted',
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $review_id ]
        );

        // Notify employee
        do_action( 'sfs_hr_review_submitted', $review_id, $review );

        return true;
    }

    /**
     * Get review criteria.
     *
     * @param bool $active_only
     * @return array
     */
    public static function get_criteria( bool $active_only = true ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_review_criteria';

        $where = $active_only ? "WHERE active = 1" : "";

        return $wpdb->get_results(
            "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id ASC"
        );
    }

    /**
     * Save a criterion.
     *
     * @param array $data
     * @return int Criterion ID
     */
    public static function save_criterion( array $data ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_review_criteria';

        $criterion_data = [
            'name'        => sanitize_text_field( $data['name'] ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'category'    => sanitize_text_field( $data['category'] ?? '' ),
            'weight'      => min( 100, max( 0, (int) ( $data['weight'] ?? 100 ) ) ),
            'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
            'active'      => isset( $data['active'] ) ? (int) $data['active'] : 1,
        ];

        $criterion_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $criterion_id > 0 ) {
            $wpdb->update( $table, $criterion_data, [ 'id' => $criterion_id ] );
        } else {
            $criterion_data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $criterion_data );
            $criterion_id = (int) $wpdb->insert_id;
        }

        return $criterion_id;
    }

    /**
     * Calculate review metrics for an employee.
     *
     * @param int    $employee_id
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function calculate_review_metrics( int $employee_id, string $start_date = '', string $end_date = '' ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_reviews';

        // Default to last year
        if ( empty( $start_date ) ) {
            $start_date = date( 'Y-m-d', strtotime( '-1 year' ) );
        }
        if ( empty( $end_date ) ) {
            $end_date = date( 'Y-m-d' );
        }

        $reviews = $wpdb->get_results( $wpdb->prepare(
            "SELECT overall_rating, review_type, status
             FROM {$table}
             WHERE employee_id = %d
               AND period_end >= %s
               AND period_end <= %s
               AND status IN ('submitted', 'acknowledged')
               AND overall_rating IS NOT NULL",
            $employee_id,
            $start_date,
            $end_date
        ) );

        $metrics = [
            'total_reviews'     => count( $reviews ),
            'avg_rating'        => null,
            'latest_rating'     => null,
            'review_breakdown'  => [
                'self'    => 0,
                'manager' => 0,
                'peer'    => 0,
                '360'     => 0,
            ],
        ];

        if ( empty( $reviews ) ) {
            return $metrics;
        }

        $total_rating = 0;
        foreach ( $reviews as $review ) {
            $total_rating += (float) $review->overall_rating;
            $metrics['review_breakdown'][ $review->review_type ]++;
        }

        $metrics['avg_rating'] = round( $total_rating / count( $reviews ), 2 );

        // Get latest rating
        $latest = $wpdb->get_var( $wpdb->prepare(
            "SELECT overall_rating
             FROM {$table}
             WHERE employee_id = %d
               AND status IN ('submitted', 'acknowledged')
               AND overall_rating IS NOT NULL
             ORDER BY period_end DESC
             LIMIT 1",
            $employee_id
        ) );

        $metrics['latest_rating'] = $latest !== null ? (float) $latest : null;

        return $metrics;
    }

    /**
     * Get due reviews (reviews that need to be completed).
     *
     * @param int $days_ahead Number of days to look ahead
     * @return array
     */
    public static function get_due_reviews( int $days_ahead = 7 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_reviews';
        $future_date = date( 'Y-m-d', strtotime( "+{$days_ahead} days" ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*,
                    e.first_name, e.last_name, e.employee_code,
                    reviewer.display_name as reviewer_name
             FROM {$table} r
             JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = r.employee_id
             LEFT JOIN {$wpdb->users} reviewer ON reviewer.ID = r.reviewer_id
             WHERE r.status IN ('draft', 'pending')
               AND r.due_date IS NOT NULL
               AND r.due_date <= %s
             ORDER BY r.due_date ASC",
            $future_date
        ) );
    }

    /**
     * Get rating label.
     *
     * @param float $rating
     * @return array
     */
    public static function get_rating_display( float $rating ): array {
        $ratings = [
            5 => [ 'label' => __( 'Exceptional', 'sfs-hr' ), 'color' => '#22c55e' ],
            4 => [ 'label' => __( 'Exceeds Expectations', 'sfs-hr' ), 'color' => '#3b82f6' ],
            3 => [ 'label' => __( 'Meets Expectations', 'sfs-hr' ), 'color' => '#f59e0b' ],
            2 => [ 'label' => __( 'Needs Improvement', 'sfs-hr' ), 'color' => '#f97316' ],
            1 => [ 'label' => __( 'Unsatisfactory', 'sfs-hr' ), 'color' => '#ef4444' ],
        ];

        $rounded = max( 1, min( 5, round( $rating ) ) );
        return $ratings[ $rounded ];
    }
}
