<?php
namespace SFS\HR\Modules\Hiring\Services;

if (!defined('ABSPATH')) { exit; }

use SFS\HR\Core\Helpers;

/**
 * Requisition Service
 * Business logic for job requisition CRUD and approval workflow
 */
class RequisitionService {

    /**
     * Create a new requisition
     *
     * @param array $data Requisition data.
     * @return int|null Insert ID or null on failure.
     */
    public static function create(array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $request_number = Helpers::generate_reference_number('REQ', $table, 'request_number');
        $now = current_time('mysql');

        $result = $wpdb->insert($table, [
            'request_number' => $request_number,
            'title'          => sanitize_text_field($data['title'] ?? ''),
            'dept_id'        => intval($data['dept_id'] ?? 0),
            'grade'          => sanitize_text_field($data['grade'] ?? ''),
            'salary_min'     => floatval($data['salary_min'] ?? 0),
            'salary_mid'     => floatval($data['salary_mid'] ?? 0),
            'salary_max'     => floatval($data['salary_max'] ?? 0),
            'headcount'      => intval($data['headcount'] ?? 1),
            'filled'         => 0,
            'requirements'   => wp_kses_post($data['requirements'] ?? ''),
            'justification'  => wp_kses_post($data['justification'] ?? ''),
            'status'         => 'draft',
            'requested_by'   => get_current_user_id(),
            'approval_chain' => wp_json_encode([]),
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return $result ? (int) $wpdb->insert_id : null;
    }

    /**
     * Update editable fields on a draft requisition
     *
     * @param int   $id   Requisition ID.
     * @param array $data Fields to update.
     * @return bool
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'draft') {
            return false;
        }

        $allowed = ['title', 'dept_id', 'grade', 'salary_min', 'salary_mid', 'salary_max', 'headcount', 'requirements', 'justification'];
        $update  = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if (in_array($field, ['dept_id', 'headcount'], true)) {
                $update[$field] = intval($data[$field]);
            } elseif (in_array($field, ['salary_min', 'salary_mid', 'salary_max'], true)) {
                $update[$field] = floatval($data[$field]);
            } elseif (in_array($field, ['requirements', 'justification'], true)) {
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
     * Submit a draft requisition for approval
     *
     * @param int $id Requisition ID.
     * @return bool
     */
    public static function submit_for_approval(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'draft') {
            return false;
        }

        $result = $wpdb->update($table, [
            'status'       => 'pending_approval',
            'requested_by' => get_current_user_id(),
            'updated_at'   => current_time('mysql'),
        ], ['id' => $id]);

        return $result !== false;
    }

    /**
     * HR reviews a requisition
     *
     * @param int    $id     Requisition ID.
     * @param string $action 'approve' or 'reject'.
     * @param string $notes  Optional notes.
     * @return bool
     */
    public static function hr_review(int $id, string $action, string $notes = ''): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'pending_approval') {
            return false;
        }

        if (!in_array($action, ['approve', 'reject'], true)) {
            return false;
        }

        $new_status = $action === 'approve' ? 'approved' : 'cancelled';

        $result = $wpdb->update($table, [
            'status'         => $new_status,
            'hr_reviewer_id' => get_current_user_id(),
            'hr_reviewed_at' => current_time('mysql'),
            'hr_notes'       => sanitize_textarea_field($notes),
            'updated_at'     => current_time('mysql'),
        ], ['id' => $id]);

        if ($result !== false) {
            self::append_chain($id, 'hr', $action, $notes);
        }

        return $result !== false;
    }

    /**
     * GM approves or rejects a requisition
     *
     * @param int    $id     Requisition ID.
     * @param string $action 'approve' or 'reject'.
     * @param string $notes  Optional notes.
     * @return bool
     */
    public static function gm_approve(int $id, string $action, string $notes = ''): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'approved') {
            return false;
        }

        if (!in_array($action, ['approve', 'reject'], true)) {
            return false;
        }

        $new_status = $action === 'approve' ? 'open' : 'cancelled';

        $result = $wpdb->update($table, [
            'status'         => $new_status,
            'gm_approver_id' => get_current_user_id(),
            'gm_approved_at' => current_time('mysql'),
            'gm_notes'       => sanitize_textarea_field($notes),
            'updated_at'     => current_time('mysql'),
        ], ['id' => $id]);

        if ($result !== false) {
            self::append_chain($id, 'gm', $action, $notes);
        }

        return $result !== false;
    }

    /**
     * Mark a requisition as filled
     *
     * @param int $id Requisition ID.
     * @return bool
     */
    public static function mark_filled(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'open') {
            return false;
        }

        if ((int) $existing->filled < (int) $existing->headcount) {
            return false;
        }

        $result = $wpdb->update($table, [
            'status'     => 'filled',
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        return $result !== false;
    }

    /**
     * Increment the filled counter by 1; auto-fill if headcount reached
     *
     * @param int $id Requisition ID.
     * @return bool
     */
    public static function increment_filled(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $existing = self::get($id);
        if (!$existing || $existing->status !== 'open') {
            return false;
        }

        $new_filled = (int) $existing->filled + 1;
        $headcount  = (int) $existing->headcount;
        $new_status = $new_filled >= $headcount ? 'filled' : 'open';

        $result = $wpdb->update($table, [
            'filled'     => $new_filled,
            'status'     => $new_status,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        return $result !== false;
    }

    /**
     * Cancel a requisition (any status except 'filled')
     *
     * @param int $id Requisition ID.
     * @return bool
     */
    public static function cancel(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $existing = self::get($id);
        if (!$existing || $existing->status === 'filled') {
            return false;
        }

        $result = $wpdb->update($table, [
            'status'     => 'cancelled',
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        return $result !== false;
    }

    /**
     * Get a single requisition by ID
     *
     * @param int $id Requisition ID.
     * @return object|null
     */
    public static function get(int $id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));

        return $row ?: null;
    }

    /**
     * Get list of requisitions with optional filters
     *
     * @param array $filters Optional: status, dept_id, requested_by.
     * @return array
     */
    public static function get_list(array $filters = []): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $where  = '1=1';
        $params = [];

        if (!empty($filters['status'])) {
            $where   .= ' AND status = %s';
            $params[] = sanitize_text_field($filters['status']);
        }

        if (!empty($filters['dept_id'])) {
            $where   .= ' AND dept_id = %d';
            $params[] = intval($filters['dept_id']);
        }

        if (!empty($filters['requested_by'])) {
            $where   .= ' AND requested_by = %d';
            $params[] = intval($filters['requested_by']);
        }

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, ...$params));
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Get associative array of statuses with translated labels
     *
     * @return array
     */
    public static function get_statuses(): array {
        return [
            'draft'            => __('Draft', 'sfs-hr'),
            'pending_approval' => __('Pending Approval', 'sfs-hr'),
            'approved'         => __('Approved', 'sfs-hr'),
            'open'             => __('Open', 'sfs-hr'),
            'filled'           => __('Filled', 'sfs-hr'),
            'cancelled'        => __('Cancelled', 'sfs-hr'),
        ];
    }

    /**
     * Append an entry to the approval_chain JSON column
     *
     * @param int    $id     Requisition ID.
     * @param string $role   Role performing the action (e.g. 'hr', 'gm').
     * @param string $action Action taken ('approve' or 'reject').
     * @param string $notes  Optional notes.
     */
    private static function append_chain(int $id, string $role, string $action, string $notes): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_requisitions';

        $existing = self::get($id);
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
