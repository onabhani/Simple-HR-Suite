<?php
namespace SFS\HR\Modules\Surveys\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Engagement Service
 *
 * Employee engagement features: eNPS scoring, anonymous suggestions,
 * and peer recognition (kudos).
 *
 * @version 1.0.0
 */
class Engagement_Service {

    /** Allowed recognition badges. */
    private const BADGES = [ 'teamwork', 'innovation', 'leadership', 'dedication', 'customer_focus' ];

    /* ─────────────────────── eNPS ─────────────────────── */

    /**
     * Calculate eNPS from a survey with a rating question (1-5 scale).
     *
     * Mapping: 5 = promoter, 4 = passive, 1-3 = detractor.
     *
     * @param int $survey_id
     * @return array{score:float,promoters:int,passives:int,detractors:int,total:int,promoter_pct:float,detractor_pct:float}
     */
    public static function calculate_enps( int $survey_id ): array {
        global $wpdb;

        $result = [
            'score'        => 0.0,
            'promoters'    => 0,
            'passives'     => 0,
            'detractors'   => 0,
            'total'        => 0,
            'promoter_pct' => 0.0,
            'detractor_pct'=> 0.0,
        ];

        // Find the first rating question in this survey.
        $question_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_survey_questions
             WHERE survey_id = %d AND question_type = 'rating'
             ORDER BY sort_order ASC, id ASC LIMIT 1",
            $survey_id
        ) );

        if ( ! $question_id ) {
            return $result;
        }

        // Fetch all rating answers for this question.
        $ratings = $wpdb->get_col( $wpdb->prepare(
            "SELECT a.answer_rating
             FROM {$wpdb->prefix}sfs_hr_survey_answers a
             JOIN {$wpdb->prefix}sfs_hr_survey_responses r ON a.response_id = r.id
             WHERE a.question_id = %d AND a.answer_rating IS NOT NULL",
            $question_id
        ) );

        if ( empty( $ratings ) ) {
            return $result;
        }

        foreach ( $ratings as $rating ) {
            $r = (int) $rating;
            if ( $r === 5 ) {
                $result['promoters']++;
            } elseif ( $r === 4 ) {
                $result['passives']++;
            } elseif ( $r >= 1 && $r <= 3 ) {
                $result['detractors']++;
            }
        }

        $result['total'] = $result['promoters'] + $result['passives'] + $result['detractors'];

        if ( $result['total'] > 0 ) {
            $result['promoter_pct']  = round( ( $result['promoters'] / $result['total'] ) * 100, 1 );
            $result['detractor_pct'] = round( ( $result['detractors'] / $result['total'] ) * 100, 1 );
            $result['score']         = round( $result['promoter_pct'] - $result['detractor_pct'], 1 );
        }

        return $result;
    }

    /**
     * Get eNPS trend over recent months from closed pulse/eNPS surveys.
     *
     * @param int $months Number of months to look back.
     * @return array<int,array{month:string,score:float,responses:int}>
     */
    public static function get_enps_trend( int $months = 6 ): array {
        global $wpdb;

        $since = gmdate( 'Y-m-d', strtotime( "-{$months} months" ) );

        $surveys = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, closed_at
             FROM {$wpdb->prefix}sfs_hr_surveys
             WHERE status = 'closed'
               AND closed_at >= %s
               AND (
                   LOWER(title) LIKE '%%pulse%%'
                   OR LOWER(title) LIKE '%%enps%%'
                   OR is_anonymous = 1
               )
             ORDER BY closed_at ASC",
            $since
        ), ARRAY_A );

        $trend = [];

        foreach ( $surveys as $survey ) {
            $enps  = self::calculate_enps( (int) $survey['id'] );
            if ( $enps['total'] === 0 ) {
                continue;
            }
            $month = substr( $survey['closed_at'], 0, 7 ); // YYYY-MM
            $trend[] = [
                'month'     => $month,
                'score'     => $enps['score'],
                'responses' => $enps['total'],
            ];
        }

        return $trend;
    }

    /* ──────────────── Anonymous Suggestions ──────────────── */

    /**
     * Submit a suggestion (truly anonymous — no employee ID stored).
     *
     * @param string      $message  Suggestion text.
     * @param string|null $category Optional category.
     * @return array{success:bool,id:int}
     */
    public static function submit_suggestion( string $message, ?string $category = null ): array {
        global $wpdb;

        $message = sanitize_textarea_field( $message );
        if ( empty( $message ) ) {
            return [ 'success' => false, 'error' => __( 'Message is required.', 'sfs-hr' ) ];
        }

        $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_employee_suggestions',
            [
                'message'    => $message,
                'category'   => $category ? sanitize_text_field( $category ) : null,
                'status'     => 'new',
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * List suggestions with optional filters.
     *
     * @param array $args {
     *     @type string $status     Filter by status (new|reviewed|actioned|dismissed).
     *     @type string $category   Filter by category.
     *     @type string $date_from  Start date (Y-m-d).
     *     @type string $date_to    End date (Y-m-d).
     *     @type int    $limit      Max rows (default 20).
     *     @type int    $offset     Offset (default 0).
     * }
     * @return array
     */
    public static function list_suggestions( array $args = [] ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_employee_suggestions';
        $where = [];
        $values = [];

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = sanitize_key( $args['status'] );
        }
        if ( ! empty( $args['category'] ) ) {
            $where[]  = 'category = %s';
            $values[] = sanitize_text_field( $args['category'] );
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'created_at >= %s';
            $values[] = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'created_at <= %s';
            $values[] = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $limit     = isset( $args['limit'] ) ? absint( $args['limit'] ) : 20;
        $offset    = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $values[] = $limit;
        $values[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );
    }

    /**
     * Review / action a suggestion.
     *
     * @param int         $id     Suggestion ID.
     * @param string      $status New status (reviewed|actioned|dismissed).
     * @param string|null $notes  Optional admin notes.
     * @return bool
     */
    public static function review_suggestion( int $id, string $status, ?string $notes = null ): bool {
        global $wpdb;

        $valid = [ 'reviewed', 'actioned', 'dismissed' ];
        if ( ! in_array( $status, $valid, true ) ) {
            return false;
        }

        $data = [
            'status'      => $status,
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => current_time( 'mysql' ),
        ];
        if ( $notes !== null ) {
            $data['admin_notes'] = sanitize_textarea_field( $notes );
        }

        return (bool) $wpdb->update(
            $wpdb->prefix . 'sfs_hr_employee_suggestions',
            $data,
            [ 'id' => $id ],
            array_fill( 0, count( $data ), '%s' ),
            [ '%d' ]
        );
    }

    /* ──────────────── Peer Recognition (Kudos) ──────────────── */

    /**
     * Give a recognition / kudos to a peer.
     *
     * @param int         $giver_id     Giver employee ID.
     * @param int         $recipient_id Recipient employee ID.
     * @param string      $message      Recognition message.
     * @param string|null $badge        Badge key (optional).
     * @return array{success:bool,id?:int,error?:string}
     */
    public static function give_recognition( int $giver_id, int $recipient_id, string $message, ?string $badge = null ): array {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Validate both employees exist and are active.
        $giver = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$emp_table} WHERE id = %d", $giver_id
        ) );
        if ( ! $giver || $giver->status !== 'active' ) {
            return [ 'success' => false, 'error' => __( 'Giver employee not found or inactive.', 'sfs-hr' ) ];
        }

        $recipient = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$emp_table} WHERE id = %d", $recipient_id
        ) );
        if ( ! $recipient || $recipient->status !== 'active' ) {
            return [ 'success' => false, 'error' => __( 'Recipient employee not found or inactive.', 'sfs-hr' ) ];
        }

        if ( $giver_id === $recipient_id ) {
            return [ 'success' => false, 'error' => __( 'Cannot give recognition to yourself.', 'sfs-hr' ) ];
        }

        $message = sanitize_textarea_field( $message );
        if ( empty( $message ) ) {
            return [ 'success' => false, 'error' => __( 'Message is required.', 'sfs-hr' ) ];
        }

        // Validate badge if provided.
        if ( $badge !== null && ! in_array( $badge, self::BADGES, true ) ) {
            $badge = null;
        }

        $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_employee_recognition',
            [
                'giver_employee_id'     => $giver_id,
                'recipient_employee_id' => $recipient_id,
                'message'               => $message,
                'badge'                 => $badge,
                'is_public'             => 1,
                'created_at'            => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s' ]
        );

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Get the recognition feed with employee names.
     *
     * @param array $args {
     *     @type int    $recipient_id Filter by recipient.
     *     @type int    $giver_id     Filter by giver.
     *     @type bool   $is_public    Filter public only (default true).
     *     @type string $badge        Filter by badge key.
     *     @type int    $limit        Max rows (default 20).
     *     @type int    $offset       Offset (default 0).
     * }
     * @return array
     */
    public static function get_recognition_feed( array $args = [] ): array {
        global $wpdb;

        $rec  = $wpdb->prefix . 'sfs_hr_employee_recognition';
        $emp  = $wpdb->prefix . 'sfs_hr_employees';

        $where  = [];
        $values = [];

        if ( ! empty( $args['recipient_id'] ) ) {
            $where[]  = 'r.recipient_employee_id = %d';
            $values[] = (int) $args['recipient_id'];
        }
        if ( ! empty( $args['giver_id'] ) ) {
            $where[]  = 'r.giver_employee_id = %d';
            $values[] = (int) $args['giver_id'];
        }
        if ( isset( $args['is_public'] ) ) {
            $where[]  = 'r.is_public = %d';
            $values[] = $args['is_public'] ? 1 : 0;
        }
        if ( ! empty( $args['badge'] ) ) {
            $where[]  = 'r.badge = %s';
            $values[] = sanitize_key( $args['badge'] );
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $limit     = isset( $args['limit'] ) ? absint( $args['limit'] ) : 20;
        $offset    = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        $sql = "SELECT r.id, r.message, r.badge, r.is_public, r.created_at,
                       CONCAT(g.first_name, ' ', g.last_name) AS giver_name,
                       CONCAT(rc.first_name, ' ', rc.last_name) AS recipient_name
                FROM {$rec} r
                JOIN {$emp} g  ON g.id  = r.giver_employee_id
                JOIN {$emp} rc ON rc.id = r.recipient_employee_id
                {$where_sql}
                ORDER BY r.created_at DESC
                LIMIT %d OFFSET %d";

        $values[] = $limit;
        $values[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );
    }

    /**
     * Get recognition stats for a single employee.
     *
     * @param int $employee_id
     * @return array{received:int,given:int,badges:array<string,int>,recent:array}
     */
    public static function get_recognition_stats( int $employee_id ): array {
        global $wpdb;

        $rec = $wpdb->prefix . 'sfs_hr_employee_recognition';
        $emp = $wpdb->prefix . 'sfs_hr_employees';

        $received = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$rec} WHERE recipient_employee_id = %d", $employee_id
        ) );

        $given = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$rec} WHERE giver_employee_id = %d", $employee_id
        ) );

        // Badge breakdown (received).
        $badge_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT badge, COUNT(*) AS cnt FROM {$rec}
             WHERE recipient_employee_id = %d AND badge IS NOT NULL
             GROUP BY badge",
            $employee_id
        ), ARRAY_A );

        $badges = [];
        foreach ( self::BADGES as $b ) {
            $badges[ $b ] = 0;
        }
        foreach ( $badge_rows as $row ) {
            if ( isset( $badges[ $row['badge'] ] ) ) {
                $badges[ $row['badge'] ] = (int) $row['cnt'];
            }
        }

        // Recent recognitions received (last 5).
        $recent = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.message, r.badge, r.created_at,
                    CONCAT(g.first_name, ' ', g.last_name) AS giver_name
             FROM {$rec} r
             JOIN {$emp} g ON g.id = r.giver_employee_id
             WHERE r.recipient_employee_id = %d
             ORDER BY r.created_at DESC LIMIT 5",
            $employee_id
        ), ARRAY_A );

        return [
            'received' => $received,
            'given'    => $given,
            'badges'   => $badges,
            'recent'   => $recent,
        ];
    }

    /* ──────────────── Engagement Dashboard ──────────────── */

    /**
     * Aggregate engagement metrics for the dashboard.
     *
     * @param int|null $dept_id Optional department filter.
     * @return array{response_rate:float,latest_enps:float|null,suggestion_count:int,recognition_count:int}
     */
    public static function get_engagement_summary( ?int $dept_id = null ): array {
        global $wpdb;

        $surveys_t   = $wpdb->prefix . 'sfs_hr_surveys';
        $responses_t = $wpdb->prefix . 'sfs_hr_survey_responses';
        $emp_t       = $wpdb->prefix . 'sfs_hr_employees';
        $suggest_t   = $wpdb->prefix . 'sfs_hr_employee_suggestions';
        $rec_t       = $wpdb->prefix . 'sfs_hr_employee_recognition';

        $thirty_days_ago = wp_date( 'Y-m-d', strtotime( '-30 days' ) ) . ' 00:00:00';

        /* --- Survey response rate from last closed survey --- */
        $response_rate = 0.0;
        $latest_survey = $wpdb->get_row(
            "SELECT id FROM {$surveys_t} WHERE status = 'closed' ORDER BY closed_at DESC LIMIT 1"
        );

        if ( $latest_survey ) {
            $resp_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$responses_t} WHERE survey_id = %d",
                $latest_survey->id
            ) );

            $emp_where = "status = 'active'";
            if ( $dept_id ) {
                $emp_where .= $wpdb->prepare( ' AND dept_id = %d', $dept_id );
            }
            $total_emp = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$emp_t} WHERE {$emp_where}"
            );

            if ( $total_emp > 0 ) {
                $response_rate = round( ( $resp_count / $total_emp ) * 100, 1 );
            }
        }

        /* --- Latest eNPS score --- */
        $latest_enps = null;
        if ( $latest_survey ) {
            $enps = self::calculate_enps( (int) $latest_survey->id );
            if ( $enps['total'] > 0 ) {
                $latest_enps = $enps['score'];
            }
        }

        /* --- Suggestion count (last 30 days) --- */
        $suggestion_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$suggest_t} WHERE created_at >= %s",
            $thirty_days_ago
        ) );

        /* --- Recognition count (last 30 days) --- */
        $rec_where = $wpdb->prepare( "r.created_at >= %s", $thirty_days_ago );
        if ( $dept_id ) {
            $rec_where .= $wpdb->prepare(
                " AND (r.giver_employee_id IN (SELECT id FROM {$emp_t} WHERE dept_id = %d)
                   OR r.recipient_employee_id IN (SELECT id FROM {$emp_t} WHERE dept_id = %d))",
                $dept_id,
                $dept_id
            );
        }

        $recognition_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$rec_t} r WHERE {$rec_where}"
        );

        return [
            'response_rate'    => $response_rate,
            'latest_enps'      => $latest_enps,
            'suggestion_count' => $suggestion_count,
            'recognition_count'=> $recognition_count,
        ];
    }
}
