<?php
namespace SFS\HR\Modules\Hiring\Services;

if (!defined('ABSPATH')) { exit; }

use SFS\HR\Core\Helpers;

/**
 * Offer Service
 * Business logic for offer letter management with approval workflow
 */
class OfferService {

    /**
     * Create a new offer
     *
     * @param array $data Offer data.
     * @return int|null Insert ID or null on failure.
     */
    public static function create(array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_offers';

        $request_number = Helpers::generate_reference_number('OFR', $table, 'request_number');
        $now = current_time('mysql');

        $result = $wpdb->insert($table, [
            'request_number'      => $request_number,
            'candidate_id'        => intval($data['candidate_id'] ?? 0),
            'posting_id'          => intval($data['posting_id'] ?? 0),
            'position'            => sanitize_text_field($data['position'] ?? ''),
            'dept_id'             => intval($data['dept_id'] ?? 0),
            'salary'              => floatval($data['salary'] ?? 0),
            'housing_allowance'   => floatval($data['housing_allowance'] ?? 0),
            'transport_allowance' => floatval($data['transport_allowance'] ?? 0),
            'other_allowances'    => floatval($data['other_allowances'] ?? 0),
            'start_date'          => sanitize_text_field($data['start_date'] ?? ''),
            'probation_months'    => intval($data['probation_months'] ?? 3),
            'benefits'            => wp_kses_post($data['benefits'] ?? ''),
            'template_id'         => intval($data['template_id'] ?? 0),
            'letter_html'         => wp_kses_post($data['letter_html'] ?? ''),
            'status'              => 'draft',
            'expires_at'          => sanitize_text_field($data['expires_at'] ?? ''),
            'hr_drafter_id'       => get_current_user_id(),
            'approval_chain'      => wp_json_encode([]),
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        return $result ? (int) $wpdb->insert_id : null;
    }

    /**
     * Update editable fields on a draft offer
     *
     * @param int   $id   Offer ID.
     * @param array $data Fields to update.
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_offers';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'draft') {
            return false;
        }

        $allowed = [
            'position', 'dept_id', 'salary', 'housing_allowance', 'transport_allowance',
            'other_allowances', 'start_date', 'probation_months', 'benefits',
            'template_id', 'letter_html', 'expires_at',
        ];
        $update = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if ($field === 'dept_id' || $field === 'template_id' || $field === 'probation_months') {
                $update[$field] = intval($data[$field]);
            } elseif (in_array($field, ['salary', 'housing_allowance', 'transport_allowance', 'other_allowances'], true)) {
                $update[$field] = floatval($data[$field]);
            } elseif (in_array($field, ['benefits', 'letter_html'], true)) {
                $update[$field] = wp_kses_post($data[$field]);
            } else {
                $update[$field] = sanitize_text_field($data[$field]);
            }
        }

        if (empty($update)) {
            return false;
        }

        $update['updated_at'] = current_time('mysql');

        $result = $wpdb->update($table, $update, ['id' => $id]);

        return $result !== false;
    }

    /**
     * Submit a draft offer for approval
     *
     * @param int $id Offer ID.
     * @return bool
     */
    public static function submit_for_approval(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_offers';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'draft') {
            return false;
        }

        $result = $wpdb->update($table, [
            'status'     => 'pending_approval',
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        if ($result !== false) {
            self::append_chain($id, 'hr', 'submit', '');
        }

        return $result !== false;
    }

    /**
     * Hiring manager reviews an offer
     *
     * @param int    $id     Offer ID.
     * @param string $action 'approve' or 'reject'.
     * @param string $notes  Optional notes.
     * @return bool
     */
    public static function manager_review(int $id, string $action, string $notes = ''): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_offers';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'pending_approval') {
            return false;
        }

        if (!in_array($action, ['approve', 'reject'], true)) {
            return false;
        }

        $new_status = $action === 'approve' ? 'pending_approval' : 'draft';

        $result = $wpdb->update($table, [
            'status'              => $new_status,
            'manager_reviewer_id' => get_current_user_id(),
            'manager_reviewed_at' => current_time('mysql'),
            'manager_notes'       => sanitize_textarea_field($notes),
            'updated_at'          => current_time('mysql'),
        ], ['id' => $id]);

        if ($result !== false) {
            self::append_chain($id, 'manager', $action, $notes);
        }

        return $result !== false;
    }

    /**
     * Finance approves or rejects an offer's salary
     *
     * @param int    $id     Offer ID.
     * @param string $action 'approve' or 'reject'.
     * @param string $notes  Optional notes.
     * @return bool
     */
    public static function finance_approve(int $id, string $action, string $notes = ''): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_offers';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'pending_approval') {
            return false;
        }

        // Manager must have reviewed first
        if (empty($existing->manager_reviewed_at)) {
            return false;
        }

        if (!in_array($action, ['approve', 'reject'], true)) {
            return false;
        }

        $new_status = $action === 'approve' ? 'pending_approval' : 'draft';

        $result = $wpdb->update($table, [
            'status'              => $new_status,
            'finance_approver_id' => get_current_user_id(),
            'finance_approved_at' => current_time('mysql'),
            'finance_notes'       => sanitize_textarea_field($notes),
            'updated_at'          => current_time('mysql'),
        ], ['id' => $id]);

        if ($result !== false) {
            self::append_chain($id, 'finance', $action, $notes);
        }

        return $result !== false;
    }

    /**
     * Mark an offer as sent to the candidate
     *
     * @param int $id Offer ID.
     * @return bool
     */
    public static function send_offer(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_offers';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'pending_approval') {
            return false;
        }

        // Finance must have approved
        if (empty($existing->finance_approved_at)) {
            return false;
        }

        $result = $wpdb->update($table, [
            'status'     => 'sent',
            'sent_at'    => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        if ($result !== false) {
            self::append_chain($id, 'hr', 'send', '');
        }

        return $result !== false;
    }

    /**
     * Record candidate response to an offer
     *
     * @param int    $id       Offer ID.
     * @param string $response 'accepted' or 'rejected'.
     * @return bool
     */
    public static function record_response(int $id, string $response): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_offers';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'sent') {
            return false;
        }

        if (!in_array($response, ['accepted', 'rejected'], true)) {
            return false;
        }

        $result = $wpdb->update($table, [
            'status'       => $response,
            'responded_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        ], ['id' => $id]);

        // If accepted, update candidate status to gm_approved (ready for hire)
        if ($result !== false && $response === 'accepted' && !empty($existing->candidate_id)) {
            $candidates_table = $wpdb->prefix . 'sfs_hr_candidates';
            $wpdb->update($candidates_table, [
                'status' => 'gm_approved',
            ], ['id' => (int) $existing->candidate_id]);
        }

        if ($result !== false) {
            self::append_chain($id, 'candidate', $response, '');
        }

        return $result !== false;
    }

    /**
     * Cron handler: expire sent offers past their expiry date
     *
     * @return int Number of offers expired.
     */
    public static function expire_offers(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_offers';
        $today = current_time('Y-m-d');

        $count = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'expired', updated_at = %s WHERE status = 'sent' AND expires_at < %s",
            current_time('mysql'),
            $today
        ));

        return $count;
    }

    /**
     * Generate the offer letter HTML by merging template with offer/candidate data
     *
     * @param int $id Offer ID.
     * @return string Rendered HTML or empty string on failure.
     */
    public static function generate_letter(int $id): string {
        global $wpdb;
        $table           = $wpdb->prefix . 'sfs_hr_offers';
        $templates_table = $wpdb->prefix . 'sfs_hr_offer_templates';
        $candidates_table = $wpdb->prefix . 'sfs_hr_candidates';

        $offer = self::get($id);
        if (!$offer || empty($offer->template_id)) {
            return '';
        }

        // Load template
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$templates_table} WHERE id = %d",
            (int) $offer->template_id
        ));

        if (!$template || empty($template->body_html)) {
            return '';
        }

        // Load candidate name
        $candidate = $wpdb->get_row($wpdb->prepare(
            "SELECT first_name, last_name FROM {$candidates_table} WHERE id = %d",
            (int) $offer->candidate_id
        ));

        $candidate_name = $candidate
            ? trim($candidate->first_name . ' ' . $candidate->last_name)
            : '';

        // Company name from profile
        $company_profile = get_option('sfs_hr_company_profile', []);
        $company_name    = $company_profile['name'] ?? $company_profile['company_name'] ?? '';

        // Merge fields
        $merge = [
            '{{candidate_name}}'      => $candidate_name,
            '{{position}}'            => $offer->position ?? '',
            '{{salary}}'              => number_format((float) ($offer->salary ?? 0), 2),
            '{{start_date}}'          => $offer->start_date ?? '',
            '{{benefits}}'            => $offer->benefits ?? '',
            '{{company_name}}'        => $company_name,
            '{{housing_allowance}}'   => number_format((float) ($offer->housing_allowance ?? 0), 2),
            '{{transport_allowance}}' => number_format((float) ($offer->transport_allowance ?? 0), 2),
        ];

        $html = str_replace(array_keys($merge), array_values($merge), $template->body_html);

        return $html;
    }

    /**
     * Get a single offer by ID with candidate name joined
     *
     * @param int $id Offer ID.
     * @return object|null
     */
    public static function get(int $id): ?object {
        global $wpdb;
        $table      = $wpdb->prefix . 'sfs_hr_offers';
        $cand_table = $wpdb->prefix . 'sfs_hr_candidates';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, CONCAT(c.first_name, ' ', c.last_name) AS candidate_name
             FROM {$table} o
             LEFT JOIN {$cand_table} c ON c.id = o.candidate_id
             WHERE o.id = %d",
            $id
        ));

        return $row ?: null;
    }

    /**
     * Get list of offers with optional filters
     *
     * @param array $filters Optional: status, candidate_id.
     * @return array
     */
    public static function get_list(array $filters = []): array {
        global $wpdb;
        $table      = $wpdb->prefix . 'sfs_hr_offers';
        $cand_table = $wpdb->prefix . 'sfs_hr_candidates';

        $where  = '1=1';
        $params = [];

        if (!empty($filters['status'])) {
            $where   .= ' AND o.status = %s';
            $params[] = sanitize_text_field($filters['status']);
        }

        if (!empty($filters['candidate_id'])) {
            $where   .= ' AND o.candidate_id = %d';
            $params[] = intval($filters['candidate_id']);
        }

        $sql = "SELECT o.*, CONCAT(c.first_name, ' ', c.last_name) AS candidate_name
                FROM {$table} o
                LEFT JOIN {$cand_table} c ON c.id = o.candidate_id
                WHERE {$where}
                ORDER BY o.created_at DESC";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, ...$params));
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Get active offer templates
     *
     * @return array
     */
    public static function get_templates(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_offer_templates';

        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE active = 1 ORDER BY name ASC"
        );
    }

    /**
     * Append an entry to the approval_chain JSON column
     *
     * @param int    $id     Offer ID.
     * @param string $role   Role performing the action (e.g. 'hr', 'manager', 'finance', 'candidate').
     * @param string $action Action taken.
     * @param string $notes  Optional notes.
     */
    private static function append_chain(int $id, string $role, string $action, string $notes): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_offers';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT approval_chain FROM {$table} WHERE id = %d",
            $id
        ));

        if (!$existing) {
            return;
        }

        $chain = json_decode($existing->approval_chain ?: '[]', true);
        if (!is_array($chain)) {
            $chain = [];
        }

        $chain[] = [
            'by'     => get_current_user_id(),
            'role'   => $role,
            'action' => $action,
            'note'   => $notes,
            'at'     => current_time('mysql'),
        ];

        $wpdb->update($table, [
            'approval_chain' => wp_json_encode($chain),
        ], ['id' => $id]);
    }
}
