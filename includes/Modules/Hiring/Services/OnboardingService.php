<?php
namespace SFS\HR\Modules\Hiring\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Onboarding Service
 * Business logic for onboarding templates, instances, and task management
 */
class OnboardingService {

    /* =========================================================================
     * Template Management
     * ====================================================================== */

    /**
     * Create an onboarding template
     *
     * @param array $data Template data (name, dept_id, position_match, active).
     * @return int|null Insert ID on success, null on failure.
     */
    public static function create_template(array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_templates';
        $now   = current_time('mysql');

        $inserted = $wpdb->insert($table, [
            'name'           => sanitize_text_field($data['name'] ?? ''),
            'dept_id'        => isset($data['dept_id']) ? (int) $data['dept_id'] : null,
            'position_match' => sanitize_text_field($data['position_match'] ?? ''),
            'active'         => isset($data['active']) ? (int) $data['active'] : 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ], ['%s', '%d', '%s', '%d', '%s', '%s']);

        return $inserted ? (int) $wpdb->insert_id : null;
    }

    /**
     * Update an onboarding template
     *
     * @param int   $id   Template ID.
     * @param array $data Fields to update (name, dept_id, position_match, active).
     * @return bool
     */
    public static function update_template(int $id, array $data): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_templates';

        $update = [];
        $format = [];

        if (isset($data['name'])) {
            $update['name'] = sanitize_text_field($data['name']);
            $format[]       = '%s';
        }
        if (array_key_exists('dept_id', $data)) {
            $update['dept_id'] = $data['dept_id'] !== null ? (int) $data['dept_id'] : null;
            $format[]          = '%d';
        }
        if (isset($data['position_match'])) {
            $update['position_match'] = sanitize_text_field($data['position_match']);
            $format[]                 = '%s';
        }
        if (isset($data['active'])) {
            $update['active'] = (int) $data['active'];
            $format[]         = '%d';
        }

        if (empty($update)) {
            return false;
        }

        $update['updated_at'] = current_time('mysql');
        $format[]             = '%s';

        $result = $wpdb->update($table, $update, ['id' => $id], $format, ['%d']);

        return $result !== false;
    }

    /**
     * Get a single onboarding template
     *
     * @param int $id Template ID.
     * @return object|null
     */
    public static function get_template(int $id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_templates';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));

        return $row ?: null;
    }

    /**
     * List onboarding templates with optional filters
     *
     * @param array $filters Optional filters: dept_id, active.
     * @return array
     */
    public static function get_templates(array $filters = []): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_templates';

        $where  = '1=1';
        $params = [];

        if (isset($filters['dept_id'])) {
            $where   .= ' AND dept_id = %d';
            $params[] = (int) $filters['dept_id'];
        }

        if (isset($filters['active'])) {
            $where   .= ' AND active = %d';
            $params[] = (int) $filters['active'];
        }

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY active DESC, name ASC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Add an item to an onboarding template
     *
     * @param int   $template_id Template ID.
     * @param array $data        Item data (title, description, task_type, assigned_role, due_days, sort_order).
     * @return int|null Insert ID on success, null on failure.
     */
    public static function add_template_item(int $template_id, array $data): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_template_items';

        $valid_types = array_keys(self::get_task_types());
        $task_type   = in_array($data['task_type'] ?? '', $valid_types, true)
            ? $data['task_type']
            : 'general';

        $inserted = $wpdb->insert($table, [
            'template_id'  => $template_id,
            'title'        => sanitize_text_field($data['title'] ?? ''),
            'description'  => sanitize_textarea_field($data['description'] ?? ''),
            'task_type'    => $task_type,
            'assigned_role'=> sanitize_text_field($data['assigned_role'] ?? ''),
            'due_days'     => isset($data['due_days']) ? (int) $data['due_days'] : 0,
            'sort_order'   => isset($data['sort_order']) ? (int) $data['sort_order'] : 0,
            'created_at'   => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s']);

        return $inserted ? (int) $wpdb->insert_id : null;
    }

    /**
     * Update a template item
     *
     * @param int   $item_id Item ID.
     * @param array $data    Fields to update.
     * @return bool
     */
    public static function update_template_item(int $item_id, array $data): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_template_items';

        $update = [];
        $format = [];

        if (isset($data['title'])) {
            $update['title'] = sanitize_text_field($data['title']);
            $format[]        = '%s';
        }
        if (isset($data['description'])) {
            $update['description'] = sanitize_textarea_field($data['description']);
            $format[]              = '%s';
        }
        if (isset($data['task_type'])) {
            $valid_types = array_keys(self::get_task_types());
            if (in_array($data['task_type'], $valid_types, true)) {
                $update['task_type'] = $data['task_type'];
                $format[]            = '%s';
            }
        }
        if (isset($data['assigned_role'])) {
            $update['assigned_role'] = sanitize_text_field($data['assigned_role']);
            $format[]                = '%s';
        }
        if (isset($data['due_days'])) {
            $update['due_days'] = (int) $data['due_days'];
            $format[]           = '%d';
        }
        if (isset($data['sort_order'])) {
            $update['sort_order'] = (int) $data['sort_order'];
            $format[]             = '%d';
        }

        if (empty($update)) {
            return false;
        }

        $result = $wpdb->update($table, $update, ['id' => $item_id], $format, ['%d']);

        return $result !== false;
    }

    /**
     * Delete a template item
     *
     * @param int $item_id Item ID.
     * @return bool
     */
    public static function delete_template_item(int $item_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_template_items';

        $result = $wpdb->delete($table, ['id' => $item_id], ['%d']);

        return $result !== false;
    }

    /**
     * Get all items for a template ordered by sort_order
     *
     * @param int $template_id Template ID.
     * @return array
     */
    public static function get_template_items(int $template_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_template_items';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE template_id = %d ORDER BY sort_order ASC",
            $template_id
        ));
    }

    /* =========================================================================
     * Instance Management
     * ====================================================================== */

    /**
     * Start onboarding for an employee
     *
     * If template_id is null, auto-detect by matching the employee's dept_id
     * and position against active templates.
     *
     * @param int      $employee_id Employee ID.
     * @param int|null $template_id Optional template ID; auto-detected if null.
     * @return int|null Onboarding instance ID on success, null on failure.
     */
    public static function start_onboarding(int $employee_id, ?int $template_id = null): ?int {
        global $wpdb;

        $onboarding_table = $wpdb->prefix . 'sfs_hr_onboarding';
        $tasks_table      = $wpdb->prefix . 'sfs_hr_onboarding_tasks';
        $templates_table  = $wpdb->prefix . 'sfs_hr_onboarding_templates';
        $items_table      = $wpdb->prefix . 'sfs_hr_onboarding_template_items';
        $employees_table  = $wpdb->prefix . 'sfs_hr_employees';

        // Look up employee details
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT dept_id, position FROM {$employees_table} WHERE id = %d",
            $employee_id
        ));

        if (!$employee) {
            return null;
        }

        // Auto-detect template if not provided
        if ($template_id === null) {
            // Try matching by dept_id first
            if ($employee->dept_id) {
                $template_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$templates_table}
                     WHERE active = 1 AND dept_id = %d
                     ORDER BY id DESC LIMIT 1",
                    $employee->dept_id
                ));
            }

            // Fall back to position_match LIKE
            if (!$template_id && $employee->position) {
                $template_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$templates_table}
                     WHERE active = 1 AND position_match LIKE %s
                     ORDER BY id DESC LIMIT 1",
                    '%' . $wpdb->esc_like($employee->position) . '%'
                ));
            }

            // No matching template found
            if (!$template_id) {
                return null;
            }
        }

        $now = current_time('mysql');

        // Create onboarding instance
        $inserted = $wpdb->insert($onboarding_table, [
            'employee_id' => $employee_id,
            'template_id' => $template_id,
            'status'      => 'active',
            'started_at'  => $now,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s']);

        if (!$inserted) {
            return null;
        }

        $onboarding_id = (int) $wpdb->insert_id;

        // Copy template items into tasks
        $items = self::get_template_items($template_id);

        foreach ($items as $item) {
            $due_date    = wp_date('Y-m-d', strtotime($now . " +{$item->due_days} days"));
            $assigned_to = self::resolve_assigned_user($item->assigned_role);

            $wpdb->insert($tasks_table, [
                'onboarding_id'    => $onboarding_id,
                'template_item_id' => $item->id,
                'title'            => $item->title,
                'description'      => $item->description,
                'task_type'        => $item->task_type,
                'assigned_to'      => $assigned_to,
                'due_date'         => $due_date,
                'status'           => 'pending',
                'created_at'       => $now,
                'updated_at'       => $now,
            ], ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']);
        }

        return $onboarding_id;
    }

    /**
     * Mark a task as completed
     *
     * Sets completed_by, completed_at, and notes. If all tasks in the
     * onboarding are now completed, auto-completes the onboarding instance.
     *
     * @param int    $task_id Task ID.
     * @param string $notes   Optional notes.
     * @return bool
     */
    public static function complete_task(int $task_id, string $notes = ''): bool {
        global $wpdb;
        $tasks_table      = $wpdb->prefix . 'sfs_hr_onboarding_tasks';
        $onboarding_table = $wpdb->prefix . 'sfs_hr_onboarding';

        $now = current_time('mysql');

        $updated = $wpdb->update(
            $tasks_table,
            [
                'status'       => 'completed',
                'completed_by' => get_current_user_id(),
                'completed_at' => $now,
                'notes'        => sanitize_textarea_field($notes),
                'updated_at'   => $now,
            ],
            ['id' => $task_id],
            ['%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return false;
        }

        // Check if all tasks for this onboarding are completed
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT onboarding_id FROM {$tasks_table} WHERE id = %d",
            $task_id
        ));

        if ($task) {
            $pending = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tasks_table}
                 WHERE onboarding_id = %d AND status != 'completed'",
                $task->onboarding_id
            ));

            if ($pending === 0) {
                $wpdb->update(
                    $onboarding_table,
                    [
                        'status'       => 'completed',
                        'completed_at' => $now,
                        'updated_at'   => $now,
                    ],
                    ['id' => $task->onboarding_id],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
            }
        }

        return true;
    }

    /**
     * Update a task's status
     *
     * @param int    $task_id Task ID.
     * @param string $status  One of: pending, in_progress, completed.
     * @return bool
     */
    public static function update_task_status(int $task_id, string $status): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_tasks';

        $valid = ['pending', 'in_progress', 'completed'];
        if (!in_array($status, $valid, true)) {
            return false;
        }

        $update = [
            'status'     => $status,
            'updated_at' => current_time('mysql'),
        ];
        $format = ['%s', '%s'];

        if ($status === 'completed') {
            $update['completed_by'] = get_current_user_id();
            $update['completed_at'] = current_time('mysql');
            $format[]               = '%d';
            $format[]               = '%s';
        }

        $result = $wpdb->update($table, $update, ['id' => $task_id], $format, ['%d']);

        return $result !== false;
    }

    /**
     * Get a single onboarding instance
     *
     * @param int $id Onboarding ID.
     * @return object|null
     */
    public static function get_onboarding(int $id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));

        return $row ?: null;
    }

    /**
     * Get active onboarding for an employee
     *
     * @param int $employee_id Employee ID.
     * @return object|null
     */
    public static function get_employee_onboarding(int $employee_id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE employee_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
            $employee_id
        ));

        return $row ?: null;
    }

    /**
     * Get all tasks for an onboarding instance
     *
     * @param int $onboarding_id Onboarding instance ID.
     * @return array
     */
    public static function get_tasks(int $onboarding_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_tasks';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE onboarding_id = %d ORDER BY due_date ASC",
            $onboarding_id
        ));
    }

    /**
     * Get all pending/in-progress tasks assigned to a user
     *
     * Joins with onboarding and employee tables for dashboard context.
     *
     * @param int $user_id WordPress user ID.
     * @return array
     */
    public static function get_my_tasks(int $user_id): array {
        global $wpdb;
        $tasks_table      = $wpdb->prefix . 'sfs_hr_onboarding_tasks';
        $onboarding_table = $wpdb->prefix . 'sfs_hr_onboarding';
        $employees_table  = $wpdb->prefix . 'sfs_hr_employees';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, o.employee_id, o.status AS onboarding_status,
                    e.first_name, e.last_name, e.employee_code
             FROM {$tasks_table} t
             JOIN {$onboarding_table} o ON o.id = t.onboarding_id
             JOIN {$employees_table} e ON e.id = o.employee_id
             WHERE t.assigned_to = %d
               AND t.status IN ('pending', 'in_progress')
               AND o.status = 'active'
             ORDER BY t.due_date ASC",
            $user_id
        ));
    }

    /**
     * Get onboarding progress summary
     *
     * @param int $onboarding_id Onboarding instance ID.
     * @return array{total: int, completed: int, percent: float}
     */
    public static function get_progress(int $onboarding_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_onboarding_tasks';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE onboarding_id = %d",
            $onboarding_id
        ));

        $completed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE onboarding_id = %d AND status = 'completed'",
            $onboarding_id
        ));

        $percent = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

        return [
            'total'     => $total,
            'completed' => $completed,
            'percent'   => $percent,
        ];
    }

    /**
     * Get task type labels
     *
     * @return array<string, string> task_type => translated label
     */
    public static function get_task_types(): array {
        return [
            'general'             => __('General', 'sfs-hr'),
            'document_submission' => __('Document Submission', 'sfs-hr'),
            'it_setup'            => __('IT Setup', 'sfs-hr'),
            'training'            => __('Training', 'sfs-hr'),
            'orientation'         => __('Orientation', 'sfs-hr'),
            'equipment'           => __('Equipment', 'sfs-hr'),
        ];
    }

    /* =========================================================================
     * Internal Helpers
     * ====================================================================== */

    /**
     * Resolve a WP user ID from a role name
     *
     * Returns the first user with the given role, or 0 if none found.
     *
     * @param string $role WordPress role slug.
     * @return int User ID or 0.
     */
    private static function resolve_assigned_user(string $role): int {
        if (empty($role)) {
            return 0;
        }

        $users = get_users([
            'role'   => $role,
            'number' => 1,
            'fields' => 'ID',
        ]);

        return !empty($users) ? (int) $users[0] : 0;
    }
}
