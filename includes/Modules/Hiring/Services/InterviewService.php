<?php
namespace SFS\HR\Modules\Hiring\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Interview Service
 * Business logic for interview scheduling, scorecards, communication logs, and reference checks.
 */
class InterviewService {

    /* ─────────────────────────────────────────────
     * Interview Management
     * ───────────────────────────────────────────── */

    /**
     * Schedule a new interview.
     *
     * @param array $data Interview data.
     * @return int|null Insert ID or null on failure.
     */
    public static function schedule(array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_interviews';
        $now   = current_time('mysql');

        $result = $wpdb->insert($table, [
            'candidate_id'     => intval($data['candidate_id'] ?? 0),
            'posting_id'       => intval($data['posting_id'] ?? 0),
            'stage'            => sanitize_text_field($data['stage'] ?? 'screening'),
            'scheduled_at'     => sanitize_text_field($data['scheduled_at'] ?? ''),
            'duration_minutes' => intval($data['duration_minutes'] ?? 60),
            'location'         => sanitize_text_field($data['location'] ?? ''),
            'meeting_link'     => esc_url_raw($data['meeting_link'] ?? ''),
            'interviewer_id'   => intval($data['interviewer_id'] ?? 0),
            'panel_ids'        => sanitize_text_field($data['panel_ids'] ?? ''),
            'status'           => 'scheduled',
            'notes'            => wp_kses_post($data['notes'] ?? ''),
            'created_by'       => get_current_user_id(),
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        return $result ? (int) $wpdb->insert_id : null;
    }

    /**
     * Update a scheduled interview.
     *
     * @param int   $id   Interview ID.
     * @param array $data Fields to update.
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_interviews';

        $allowed = [
            'scheduled_at', 'duration_minutes', 'location',
            'meeting_link', 'interviewer_id', 'panel_ids', 'stage',
        ];

        $update = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $update[$field] = match ($field) {
                'duration_minutes', 'interviewer_id' => intval($data[$field]),
                'meeting_link'                       => esc_url_raw($data[$field]),
                default                              => sanitize_text_field($data[$field]),
            };
        }

        if (empty($update)) {
            return false;
        }

        $update['updated_at'] = current_time('mysql');

        return (bool) $wpdb->update($table, $update, ['id' => $id]);
    }

    /**
     * Mark an interview as completed.
     *
     * @param int    $id    Interview ID.
     * @param string $notes Optional notes.
     * @return bool
     */
    public static function complete(int $id, string $notes = ''): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_interviews';

        return (bool) $wpdb->update($table, [
            'status'     => 'completed',
            'notes'      => wp_kses_post($notes),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    /**
     * Cancel an interview.
     *
     * @param int $id Interview ID.
     * @return bool
     */
    public static function cancel(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_interviews';

        return (bool) $wpdb->update($table, [
            'status'     => 'cancelled',
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    /**
     * Get a single interview with candidate name joined.
     *
     * @param int $id Interview ID.
     * @return object|null
     */
    public static function get(int $id): ?object {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_interviews';
        $c = $wpdb->prefix . 'sfs_hr_candidates';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, c.first_name AS candidate_first_name, c.last_name AS candidate_last_name
             FROM {$t} i
             LEFT JOIN {$c} c ON c.id = i.candidate_id
             WHERE i.id = %d",
            $id
        ));

        return $row ?: null;
    }

    /**
     * Get all interviews for a candidate.
     *
     * @param int $candidate_id Candidate ID.
     * @return array
     */
    public static function get_for_candidate(int $candidate_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_interviews';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE candidate_id = %d ORDER BY scheduled_at DESC",
            $candidate_id
        ));
    }

    /**
     * Get upcoming scheduled interviews with candidate name.
     *
     * @param int $limit Max rows.
     * @return array
     */
    public static function get_upcoming(int $limit = 20): array {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_interviews';
        $c = $wpdb->prefix . 'sfs_hr_candidates';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, c.first_name AS candidate_first_name, c.last_name AS candidate_last_name
             FROM {$t} i
             LEFT JOIN {$c} c ON c.id = i.candidate_id
             WHERE i.status = 'scheduled' AND i.scheduled_at >= NOW()
             ORDER BY i.scheduled_at ASC
             LIMIT %d",
            $limit
        ));
    }

    /* ─────────────────────────────────────────────
     * Scorecards
     * ───────────────────────────────────────────── */

    /**
     * Submit a scorecard for an interview.
     *
     * criteria should be a JSON string of [{name, score, weight}, …].
     * total_score is calculated as weighted average.
     *
     * @param array $data Scorecard data.
     * @return int|null Insert ID or null on failure.
     */
    public static function submit_scorecard(array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_interview_scorecards';
        $now   = current_time('mysql');

        $criteria_raw = $data['criteria'] ?? '[]';
        $criteria      = is_string($criteria_raw) ? json_decode($criteria_raw, true) : $criteria_raw;

        if (!is_array($criteria)) {
            $criteria = [];
        }

        $total_score = self::calculate_weighted_average($criteria);

        $result = $wpdb->insert($table, [
            'interview_id'   => intval($data['interview_id'] ?? 0),
            'interviewer_id' => get_current_user_id(),
            'criteria'       => wp_json_encode($criteria),
            'total_score'    => $total_score,
            'recommendation' => sanitize_text_field($data['recommendation'] ?? ''),
            'comments'       => wp_kses_post($data['comments'] ?? ''),
            'submitted_at'   => $now,
            'created_at'     => $now,
        ]);

        return $result ? (int) $wpdb->insert_id : null;
    }

    /**
     * Get all scorecards for an interview.
     *
     * @param int $interview_id Interview ID.
     * @return array
     */
    public static function get_scorecards(int $interview_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_interview_scorecards';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE interview_id = %d ORDER BY submitted_at ASC",
            $interview_id
        ));
    }

    /**
     * Get all scorecards across all interviews for a candidate, joined with interview stage.
     *
     * @param int $candidate_id Candidate ID.
     * @return array
     */
    public static function get_candidate_scores(int $candidate_id): array {
        global $wpdb;
        $sc = $wpdb->prefix . 'sfs_hr_interview_scorecards';
        $iv = $wpdb->prefix . 'sfs_hr_interviews';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT sc.*, i.stage AS interview_stage
             FROM {$sc} sc
             INNER JOIN {$iv} i ON i.id = sc.interview_id
             WHERE i.candidate_id = %d
             ORDER BY i.scheduled_at ASC, sc.submitted_at ASC",
            $candidate_id
        ));
    }

    /* ─────────────────────────────────────────────
     * Communication Log
     * ───────────────────────────────────────────── */

    /**
     * Add a communication log entry for a candidate.
     *
     * @param array $data Communication data.
     * @return int|null Insert ID or null on failure.
     */
    public static function log_communication(array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_candidate_comm_log';

        $result = $wpdb->insert($table, [
            'candidate_id' => intval($data['candidate_id'] ?? 0),
            'channel'      => sanitize_text_field($data['channel'] ?? 'note'),
            'direction'    => sanitize_text_field($data['direction'] ?? 'outbound'),
            'subject'      => sanitize_text_field($data['subject'] ?? ''),
            'body'         => wp_kses_post($data['body'] ?? ''),
            'logged_by'    => get_current_user_id(),
            'created_at'   => current_time('mysql'),
        ]);

        return $result ? (int) $wpdb->insert_id : null;
    }

    /**
     * Get all communication log entries for a candidate.
     *
     * @param int $candidate_id Candidate ID.
     * @return array
     */
    public static function get_communications(int $candidate_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_candidate_comm_log';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE candidate_id = %d ORDER BY created_at DESC",
            $candidate_id
        ));
    }

    /* ─────────────────────────────────────────────
     * Reference Checks
     * ───────────────────────────────────────────── */

    /**
     * Add a reference check record.
     *
     * @param array $data Reference data.
     * @return int|null Insert ID or null on failure.
     */
    public static function add_reference(array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_reference_checks';

        $result = $wpdb->insert($table, [
            'candidate_id'   => intval($data['candidate_id'] ?? 0),
            'referee_name'   => sanitize_text_field($data['referee_name'] ?? ''),
            'referee_title'  => sanitize_text_field($data['referee_title'] ?? ''),
            'referee_company'=> sanitize_text_field($data['referee_company'] ?? ''),
            'referee_phone'  => sanitize_text_field($data['referee_phone'] ?? ''),
            'referee_email'  => sanitize_email($data['referee_email'] ?? ''),
            'relationship'   => sanitize_text_field($data['relationship'] ?? ''),
            'status'         => 'pending',
            'notes'          => wp_kses_post($data['notes'] ?? ''),
            'created_at'     => current_time('mysql'),
        ]);

        return $result ? (int) $wpdb->insert_id : null;
    }

    /**
     * Update reference check fields.
     *
     * @param int   $id   Reference check ID.
     * @param array $data Fields to update.
     * @return bool
     */
    public static function update_reference(int $id, array $data): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_reference_checks';

        $allowed = [
            'referee_name', 'referee_title', 'referee_company',
            'referee_phone', 'referee_email', 'relationship',
            'status', 'notes',
        ];

        $update = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $update[$field] = $field === 'referee_email'
                ? sanitize_email($data[$field])
                : sanitize_text_field($data[$field]);
        }

        if (empty($update)) {
            return false;
        }

        return (bool) $wpdb->update($table, $update, ['id' => $id]);
    }

    /**
     * Mark a reference check as completed.
     *
     * @param int    $id    Reference check ID.
     * @param string $notes Completion notes.
     * @return bool
     */
    public static function complete_reference(int $id, string $notes): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_reference_checks';

        return (bool) $wpdb->update($table, [
            'status'     => 'completed',
            'notes'      => wp_kses_post($notes),
            'checked_by' => get_current_user_id(),
            'checked_at' => current_time('mysql'),
        ], ['id' => $id]);
    }

    /**
     * Get all reference checks for a candidate.
     *
     * @param int $candidate_id Candidate ID.
     * @return array
     */
    public static function get_references(int $candidate_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_reference_checks';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE candidate_id = %d ORDER BY created_at DESC",
            $candidate_id
        ));
    }

    /* ─────────────────────────────────────────────
     * Stage & Recommendation Helpers
     * ───────────────────────────────────────────── */

    /**
     * Get interview stage options with translated labels.
     *
     * @return array Associative array of stage_key => label.
     */
    public static function get_stages(): array {
        return [
            'screening' => __('Screening', 'sfs-hr'),
            'technical' => __('Technical', 'sfs-hr'),
            'hr'        => __('HR', 'sfs-hr'),
            'final'     => __('Final', 'sfs-hr'),
            'other'     => __('Other', 'sfs-hr'),
        ];
    }

    /**
     * Get scorecard recommendation options with translated labels.
     *
     * @return array Associative array of recommendation_key => label.
     */
    public static function get_recommendations(): array {
        return [
            'strong_hire'    => __('Strong Hire', 'sfs-hr'),
            'hire'           => __('Hire', 'sfs-hr'),
            'no_hire'        => __('No Hire', 'sfs-hr'),
            'strong_no_hire' => __('Strong No Hire', 'sfs-hr'),
        ];
    }

    /* ─────────────────────────────────────────────
     * Internal Helpers
     * ───────────────────────────────────────────── */

    /**
     * Calculate weighted average score from criteria array.
     *
     * @param array $criteria Array of [{name, score, weight}, …].
     * @return float
     */
    private static function calculate_weighted_average(array $criteria): float {
        $total_weight = 0.0;
        $weighted_sum = 0.0;

        foreach ($criteria as $item) {
            $score  = floatval($item['score'] ?? 0);
            $weight = floatval($item['weight'] ?? 1);
            $weighted_sum += $score * $weight;
            $total_weight += $weight;
        }

        if ($total_weight <= 0) {
            return 0.0;
        }

        return round($weighted_sum / $total_weight, 2);
    }
}
