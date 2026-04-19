<?php
namespace SFS\HR\Modules\Performance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Feedback 360 Service
 *
 * Manages 360-degree feedback requests, submissions, and aggregated reporting.
 * Enforces anonymity: individual provider identities are never exposed for
 * anonymous feedback in any aggregated output.
 *
 * Table: {prefix}sfs_hr_performance_feedback_360
 *
 * @version 1.0.0
 */
class Feedback_360_Service {

    /** Valid provider types. */
    private const PROVIDER_TYPES = [ 'self', 'manager', 'peer', 'direct_report', 'external' ];

    /** Minimum submitted responses required before including a provider_type group
     *  in aggregated output (to prevent de-anonymisation via single-respondent groups). */
    private const MIN_ANONYMITY_THRESHOLD = 2;

    // -------------------------------------------------------------------------
    // Request management
    // -------------------------------------------------------------------------

    /**
     * Create a single 360 feedback request.
     *
     * @param int    $cycle_id      Review cycle ID.
     * @param int    $employee_id   Employee being evaluated.
     * @param int    $provider_id   User providing feedback.
     * @param string $provider_type One of self|manager|peer|direct_report|external.
     * @param bool   $anonymous     Whether feedback is anonymous. Default true.
     * @return array{success: bool, feedback_id?: int, error?: string}
     */
    public static function create_feedback_request(
        int $cycle_id,
        int $employee_id,
        int $provider_id,
        string $provider_type,
        bool $anonymous = true
    ): array {
        global $wpdb;

        if ( ! in_array( $provider_type, self::PROVIDER_TYPES, true ) ) {
            return [
                'success' => false,
                'error'   => sprintf(
                    /* translators: %s: invalid provider type supplied */
                    __( 'Invalid provider type: %s', 'sfs-hr' ),
                    $provider_type
                ),
            ];
        }

        if ( $cycle_id <= 0 || $employee_id <= 0 || $provider_id <= 0 ) {
            return [
                'success' => false,
                'error'   => __( 'cycle_id, employee_id, and provider_id must be positive integers.', 'sfs-hr' ),
            ];
        }

        $table = $wpdb->prefix . 'sfs_hr_performance_feedback_360';
        $now   = current_time( 'mysql' );

        // Prevent duplicate requests for the same cycle/employee/provider combination.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE cycle_id = %d AND employee_id = %d AND provider_id = %d LIMIT 1",
                $cycle_id,
                $employee_id,
                $provider_id
            )
        );

        if ( $existing ) {
            return [
                'success'     => false,
                'feedback_id' => (int) $existing,
                'error'       => __( 'A feedback request already exists for this provider in this cycle.', 'sfs-hr' ),
            ];
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'cycle_id'                => $cycle_id,
                'employee_id'             => $employee_id,
                'provider_id'             => $provider_id,
                'provider_type'           => $provider_type,
                'is_anonymous'            => $anonymous ? 1 : 0,
                'overall_rating'          => null,
                'competency_ratings_json' => null,
                'strengths'               => null,
                'improvements'            => null,
                'comments'                => null,
                'status'                  => 'pending',
                'submitted_at'            => null,
                'created_at'              => $now,
                'updated_at'              => $now,
            ],
            [ '%d', '%d', '%d', '%s', '%d', null, null, null, null, null, '%s', null, '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return [
                'success' => false,
                'error'   => __( 'Database error while creating feedback request.', 'sfs-hr' ),
            ];
        }

        return [
            'success'     => true,
            'feedback_id' => (int) $wpdb->insert_id,
        ];
    }

    /**
     * Bulk-create feedback requests for multiple providers in one call.
     *
     * @param int   $cycle_id    Review cycle ID.
     * @param int   $employee_id Employee being evaluated.
     * @param array $providers   Array of ['provider_id' => int, 'provider_type' => string, 'anonymous' => bool (optional)].
     * @return array{created: int[], skipped: int[], errors: string[]}
     */
    public static function bulk_create_requests( int $cycle_id, int $employee_id, array $providers ): array {
        $created = [];
        $skipped = [];
        $errors  = [];

        foreach ( $providers as $provider ) {
            $provider_id   = isset( $provider['provider_id'] ) ? (int) $provider['provider_id'] : 0;
            $provider_type = $provider['provider_type'] ?? '';
            $anonymous     = isset( $provider['anonymous'] ) ? (bool) $provider['anonymous'] : true;

            if ( $provider_id <= 0 ) {
                $errors[] = sprintf(
                    /* translators: %s: invalid provider data */
                    __( 'Invalid provider entry: %s', 'sfs-hr' ),
                    wp_json_encode( $provider )
                );
                continue;
            }

            $result = self::create_feedback_request( $cycle_id, $employee_id, $provider_id, $provider_type, $anonymous );

            if ( $result['success'] ) {
                $created[] = $result['feedback_id'];
            } elseif ( isset( $result['feedback_id'] ) ) {
                // Duplicate — already existed.
                $skipped[] = $result['feedback_id'];
            } else {
                $errors[] = $result['error'] ?? __( 'Unknown error.', 'sfs-hr' );
            }
        }

        return compact( 'created', 'skipped', 'errors' );
    }

    // -------------------------------------------------------------------------
    // Submission
    // -------------------------------------------------------------------------

    /**
     * Submit feedback for a request.
     *
     * Accepted keys in $data:
     *   overall_rating          float|null  (0.0–5.0)
     *   competency_ratings_json array|null  [{competency_id, rating, comment}, ...]
     *   strengths               string|null
     *   improvements            string|null
     *   comments                string|null
     *
     * @param int   $feedback_id Feedback request ID.
     * @param array $data        Feedback payload.
     * @return array{success: bool, error?: string}
     */
    public static function submit_feedback( int $feedback_id, array $data ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_feedback_360';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $feedback_id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return [ 'success' => false, 'error' => __( 'Feedback request not found.', 'sfs-hr' ) ];
        }

        if ( 'submitted' === $row['status'] ) {
            return [ 'success' => false, 'error' => __( 'Feedback has already been submitted.', 'sfs-hr' ) ];
        }

        // Validate overall_rating when provided.
        $overall_rating = null;
        if ( isset( $data['overall_rating'] ) && '' !== $data['overall_rating'] ) {
            $overall_rating = round( (float) $data['overall_rating'], 1 );
            if ( $overall_rating < 0.0 || $overall_rating > 5.0 ) {
                return [ 'success' => false, 'error' => __( 'overall_rating must be between 0.0 and 5.0.', 'sfs-hr' ) ];
            }
        }

        // Validate and encode competency_ratings.
        $competency_json = null;
        if ( ! empty( $data['competency_ratings_json'] ) ) {
            $ratings = $data['competency_ratings_json'];
            if ( is_string( $ratings ) ) {
                $ratings = json_decode( $ratings, true );
            }
            if ( ! is_array( $ratings ) ) {
                return [ 'success' => false, 'error' => __( 'competency_ratings_json must be an array.', 'sfs-hr' ) ];
            }
            $sanitised = [];
            foreach ( $ratings as $entry ) {
                if ( empty( $entry['competency_id'] ) ) {
                    continue;
                }
                $rating = isset( $entry['rating'] ) ? round( (float) $entry['rating'], 1 ) : null;
                if ( null !== $rating && ( $rating < 0.0 || $rating > 5.0 ) ) {
                    return [
                        'success' => false,
                        'error'   => sprintf(
                            /* translators: %d: competency ID */
                            __( 'Rating for competency %d is out of range (0.0–5.0).', 'sfs-hr' ),
                            (int) $entry['competency_id']
                        ),
                    ];
                }
                $sanitised[] = [
                    'competency_id' => (int) $entry['competency_id'],
                    'rating'        => $rating,
                    'comment'       => isset( $entry['comment'] ) ? sanitize_textarea_field( $entry['comment'] ) : '',
                ];
            }
            $competency_json = wp_json_encode( $sanitised );
        }

        $now     = current_time( 'mysql' );
        $updated = $wpdb->update(
            $table,
            [
                'overall_rating'          => $overall_rating,
                'competency_ratings_json' => $competency_json,
                'strengths'               => ! empty( $data['strengths'] ) ? sanitize_textarea_field( $data['strengths'] ) : null,
                'improvements'            => ! empty( $data['improvements'] ) ? sanitize_textarea_field( $data['improvements'] ) : null,
                'comments'                => ! empty( $data['comments'] ) ? sanitize_textarea_field( $data['comments'] ) : null,
                'status'                  => 'submitted',
                'submitted_at'            => $now,
                'updated_at'              => $now,
            ],
            [ 'id' => $feedback_id ],
            [ '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return [ 'success' => false, 'error' => __( 'Database error while submitting feedback.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    /**
     * Get a single feedback record by ID.
     *
     * @param int $id Feedback record ID.
     * @return array|null Row as associative array, or null if not found.
     */
    public static function get_feedback( int $id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_feedback_360';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        return self::decode_row( $row );
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * Get all pending feedback requests assigned to a provider.
     *
     * @param int $provider_id WP user ID of the feedback provider.
     * @return array[] Array of feedback request rows (anonymised fields omitted).
     */
    public static function get_pending_for_provider( int $provider_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_feedback_360';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, cycle_id, employee_id, provider_type, is_anonymous, status, created_at
                 FROM {$table}
                 WHERE provider_id = %d AND status = 'pending'
                 ORDER BY created_at ASC",
                $provider_id
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Get all feedback records for an employee in a specific cycle.
     *
     * Note: This returns raw rows including provider identity — intended for
     * internal use (HR admin). REST endpoints must apply anonymisation before
     * returning data to non-admin callers.
     *
     * @param int $employee_id Employee being evaluated.
     * @param int $cycle_id    Review cycle ID.
     * @return array[] Array of decoded feedback rows.
     */
    public static function get_feedback_for_employee( int $employee_id, int $cycle_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_feedback_360';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE employee_id = %d AND cycle_id = %d
                 ORDER BY provider_type ASC, created_at ASC",
                $employee_id,
                $cycle_id
            ),
            ARRAY_A
        );

        return array_map( [ __CLASS__, 'decode_row' ], $rows ?: [] );
    }

    // -------------------------------------------------------------------------
    // Aggregation (anonymised)
    // -------------------------------------------------------------------------

    /**
     * Get anonymised aggregated feedback for an employee in a cycle.
     *
     * Rules:
     * - Only submitted records are included.
     * - For is_anonymous=1 groups: provider_id is suppressed; groups with fewer
     *   than MIN_ANONYMITY_THRESHOLD responses are excluded entirely to prevent
     *   de-anonymisation.
     * - For is_anonymous=0 groups: provider identity is preserved.
     * - Text fields (strengths, improvements, comments) are concatenated without
     *   per-provider attribution when is_anonymous=1.
     *
     * @param int $employee_id Employee being evaluated.
     * @param int $cycle_id    Review cycle ID.
     * @return array Keyed by provider_type, each containing avg_rating, count, strengths[], improvements[], comments[].
     */
    public static function get_aggregated_feedback( int $employee_id, int $cycle_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_feedback_360';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, provider_id, provider_type, is_anonymous,
                        overall_rating, strengths, improvements, comments
                 FROM {$table}
                 WHERE employee_id = %d AND cycle_id = %d AND status = 'submitted'",
                $employee_id,
                $cycle_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        // Bucket rows by provider_type.
        $buckets = [];
        foreach ( $rows as $row ) {
            $type = $row['provider_type'];
            if ( ! isset( $buckets[ $type ] ) ) {
                $buckets[ $type ] = [];
            }
            $buckets[ $type ][] = $row;
        }

        $aggregated = [];

        foreach ( $buckets as $type => $type_rows ) {
            // Determine if this group is treated as anonymous.
            // A group is anonymous if ANY record in it has is_anonymous=1.
            $has_anon = false;
            foreach ( $type_rows as $r ) {
                if ( '1' === (string) $r['is_anonymous'] ) {
                    $has_anon = true;
                    break;
                }
            }

            // Enforce minimum threshold for anonymous groups.
            if ( $has_anon && count( $type_rows ) < self::MIN_ANONYMITY_THRESHOLD ) {
                continue; // Skip — too few responses to safely anonymise.
            }

            $ratings     = [];
            $strengths   = [];
            $improvements = [];
            $comments    = [];
            $providers   = []; // Only populated for non-anonymous groups.

            foreach ( $type_rows as $r ) {
                if ( null !== $r['overall_rating'] && '' !== $r['overall_rating'] ) {
                    $ratings[] = (float) $r['overall_rating'];
                }
                if ( ! empty( $r['strengths'] ) ) {
                    $strengths[] = $r['strengths'];
                }
                if ( ! empty( $r['improvements'] ) ) {
                    $improvements[] = $r['improvements'];
                }
                if ( ! empty( $r['comments'] ) ) {
                    $comments[] = $r['comments'];
                }
                if ( ! $has_anon ) {
                    $providers[] = (int) $r['provider_id'];
                }
            }

            $avg_rating = count( $ratings ) > 0
                ? round( array_sum( $ratings ) / count( $ratings ), 2 )
                : null;

            $entry = [
                'provider_type' => $type,
                'count'         => count( $type_rows ),
                'avg_rating'    => $avg_rating,
                'strengths'     => $strengths,
                'improvements'  => $improvements,
                'comments'      => $comments,
                'is_anonymous'  => $has_anon,
            ];

            if ( ! $has_anon ) {
                $entry['provider_ids'] = $providers;
            }

            $aggregated[ $type ] = $entry;
        }

        return $aggregated;
    }

    /**
     * Get per-competency average ratings across all submitted feedback for an employee/cycle.
     *
     * Anonymity is enforced at the competency level: ratings from anonymous providers
     * are included in the average but the contributing provider_ids are never exposed.
     *
     * @param int $employee_id Employee being evaluated.
     * @param int $cycle_id    Review cycle ID.
     * @return array[] Array of ['competency_id' => int, 'avg_rating' => float, 'response_count' => int].
     */
    public static function get_competency_averages( int $employee_id, int $cycle_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_feedback_360';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT competency_ratings_json
                 FROM {$table}
                 WHERE employee_id = %d AND cycle_id = %d
                   AND status = 'submitted'
                   AND competency_ratings_json IS NOT NULL",
                $employee_id,
                $cycle_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        // Aggregate ratings per competency_id.
        $competency_data = [];

        foreach ( $rows as $row ) {
            $entries = json_decode( $row['competency_ratings_json'], true );
            if ( ! is_array( $entries ) ) {
                continue;
            }
            foreach ( $entries as $entry ) {
                if ( empty( $entry['competency_id'] ) || null === $entry['rating'] ) {
                    continue;
                }
                $cid = (int) $entry['competency_id'];
                if ( ! isset( $competency_data[ $cid ] ) ) {
                    $competency_data[ $cid ] = [ 'ratings' => [], 'comments' => [] ];
                }
                $competency_data[ $cid ]['ratings'][] = (float) $entry['rating'];
                if ( ! empty( $entry['comment'] ) ) {
                    $competency_data[ $cid ]['comments'][] = $entry['comment'];
                }
            }
        }

        $result = [];
        foreach ( $competency_data as $cid => $data ) {
            $count = count( $data['ratings'] );
            $result[] = [
                'competency_id'  => $cid,
                'avg_rating'     => $count > 0 ? round( array_sum( $data['ratings'] ) / $count, 2 ) : null,
                'response_count' => $count,
                'comments'       => $data['comments'], // Returned without provider attribution.
            ];
        }

        // Sort by competency_id for deterministic output.
        usort( $result, static fn( $a, $b ) => $a['competency_id'] <=> $b['competency_id'] );

        return $result;
    }

    // -------------------------------------------------------------------------
    // Report
    // -------------------------------------------------------------------------

    /**
     * Generate a structured 360 feedback report for an employee in a cycle.
     *
     * Report structure:
     * {
     *   employee_id:         int,
     *   cycle_id:            int,
     *   overall_score:       float|null,      // weighted average of all provider type avg ratings
     *   response_summary:    {total, submitted, pending, completion_pct},
     *   by_provider_type:    { <type>: {avg_rating, count, strengths[], improvements[], comments[]} },
     *   competency_breakdown: [{competency_id, avg_rating, response_count, comments[]}],
     *   strengths_themes:    string[],         // de-duplicated pool of all strengths text
     *   improvement_themes:  string[],         // de-duplicated pool of all improvements text
     * }
     *
     * @param int $employee_id Employee being evaluated.
     * @param int $cycle_id    Review cycle ID.
     * @return array Structured report.
     */
    public static function generate_feedback_report( int $employee_id, int $cycle_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_feedback_360';

        // Completion counts.
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM(status = 'submitted') AS submitted,
                    SUM(status = 'pending')   AS pending
                 FROM {$table}
                 WHERE employee_id = %d AND cycle_id = %d",
                $employee_id,
                $cycle_id
            ),
            ARRAY_A
        );

        $total     = (int) ( $stats['total'] ?? 0 );
        $submitted = (int) ( $stats['submitted'] ?? 0 );
        $pending   = (int) ( $stats['pending'] ?? 0 );

        $response_summary = [
            'total'          => $total,
            'submitted'      => $submitted,
            'pending'        => $pending,
            'completion_pct' => $total > 0 ? round( ( $submitted / $total ) * 100, 1 ) : 0.0,
        ];

        // Anonymised by-provider-type aggregation.
        $by_provider_type = self::get_aggregated_feedback( $employee_id, $cycle_id );

        // Competency breakdown.
        $competency_breakdown = self::get_competency_averages( $employee_id, $cycle_id );

        // Overall score: simple average of per-type averages (equal weight per type).
        $type_averages = array_filter(
            array_column( array_values( $by_provider_type ), 'avg_rating' ),
            static fn( $v ) => null !== $v
        );

        $overall_score = count( $type_averages ) > 0
            ? round( array_sum( $type_averages ) / count( $type_averages ), 2 )
            : null;

        // Collect all strengths and improvements text across visible groups.
        $all_strengths   = [];
        $all_improvements = [];

        foreach ( $by_provider_type as $type_data ) {
            foreach ( $type_data['strengths'] as $s ) {
                if ( ! empty( trim( $s ) ) ) {
                    $all_strengths[] = $s;
                }
            }
            foreach ( $type_data['improvements'] as $i ) {
                if ( ! empty( trim( $i ) ) ) {
                    $all_improvements[] = $i;
                }
            }
        }

        // Remove exact duplicates for theme lists.
        $strengths_themes   = array_values( array_unique( $all_strengths ) );
        $improvement_themes = array_values( array_unique( $all_improvements ) );

        return [
            'employee_id'          => $employee_id,
            'cycle_id'             => $cycle_id,
            'overall_score'        => $overall_score,
            'response_summary'     => $response_summary,
            'by_provider_type'     => $by_provider_type,
            'competency_breakdown' => $competency_breakdown,
            'strengths_themes'     => $strengths_themes,
            'improvement_themes'   => $improvement_themes,
        ];
    }

    // -------------------------------------------------------------------------
    // Stats
    // -------------------------------------------------------------------------

    /**
     * Get completion statistics for all employees in a review cycle.
     *
     * @param int $cycle_id Review cycle ID.
     * @return array[] Array of per-employee stats:
     *                 [employee_id, total_requests, submitted, pending, completion_pct].
     */
    public static function get_completion_stats( int $cycle_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_feedback_360';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    employee_id,
                    COUNT(*)                      AS total_requests,
                    SUM(status = 'submitted')     AS submitted,
                    SUM(status = 'pending')       AS pending
                 FROM {$table}
                 WHERE cycle_id = %d
                 GROUP BY employee_id
                 ORDER BY employee_id ASC",
                $cycle_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [];
        }

        return array_map( static function ( $row ) {
            $total = (int) $row['total_requests'];
            return [
                'employee_id'    => (int) $row['employee_id'],
                'total_requests' => $total,
                'submitted'      => (int) $row['submitted'],
                'pending'        => (int) $row['pending'],
                'completion_pct' => $total > 0 ? round( ( (int) $row['submitted'] / $total ) * 100, 1 ) : 0.0,
            ];
        }, $rows );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Decode JSON columns in a raw DB row.
     *
     * @param array $row Raw DB row.
     * @return array Row with competency_ratings_json decoded to an array (or null).
     */
    private static function decode_row( array $row ): array {
        if ( isset( $row['competency_ratings_json'] ) && null !== $row['competency_ratings_json'] ) {
            $decoded = json_decode( $row['competency_ratings_json'], true );
            $row['competency_ratings_json'] = is_array( $decoded ) ? $decoded : null;
        }

        // Cast numeric fields.
        foreach ( [ 'id', 'cycle_id', 'employee_id', 'provider_id', 'is_anonymous' ] as $int_col ) {
            if ( isset( $row[ $int_col ] ) ) {
                $row[ $int_col ] = (int) $row[ $int_col ];
            }
        }
        if ( isset( $row['overall_rating'] ) && null !== $row['overall_rating'] ) {
            $row['overall_rating'] = (float) $row['overall_rating'];
        }

        return $row;
    }
}
