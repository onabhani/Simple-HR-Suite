<?php
namespace SFS\HR\Modules\Performance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Goals Service
 *
 * Manages employee goals and OKRs including:
 * - Goal creation and management
 * - Progress tracking
 * - Goal completion calculations
 *
 * @version 1.0.0
 */
class Goals_Service {

    /**
     * Save a goal (create or update).
     *
     * @param array $data Goal data
     * @return int|\WP_Error Goal ID or error
     */
    public static function save_goal( array $data ) {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_goals';

        // Validate required fields
        if ( empty( $data['employee_id'] ) ) {
            return new \WP_Error( 'missing_employee', __( 'Employee ID is required', 'sfs-hr' ) );
        }
        if ( empty( $data['title'] ) ) {
            return new \WP_Error( 'missing_title', __( 'Goal title is required', 'sfs-hr' ) );
        }

        $now = current_time( 'mysql' );

        $goal_data = [
            'employee_id' => (int) $data['employee_id'],
            'title'       => sanitize_text_field( $data['title'] ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'target_date' => ! empty( $data['target_date'] ) ? sanitize_text_field( $data['target_date'] ) : null,
            'weight'      => isset( $data['weight'] ) ? min( 100, max( 0, (int) $data['weight'] ) ) : 100,
            'progress'    => isset( $data['progress'] ) ? min( 100, max( 0, (int) $data['progress'] ) ) : 0,
            'status'      => in_array( $data['status'] ?? '', [ 'active', 'completed', 'cancelled', 'on_hold' ], true )
                ? $data['status'] : 'active',
            'category'    => ! empty( $data['category'] ) ? sanitize_text_field( $data['category'] ) : null,
            'parent_id'   => ! empty( $data['parent_id'] ) ? (int) $data['parent_id'] : null,
            'updated_at'  => $now,
        ];

        $goal_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $goal_id > 0 ) {
            // Update existing goal
            $wpdb->update( $table, $goal_data, [ 'id' => $goal_id ] );
        } else {
            // Create new goal
            $goal_data['created_by'] = get_current_user_id();
            $goal_data['created_at'] = $now;
            $wpdb->insert( $table, $goal_data );
            $goal_id = (int) $wpdb->insert_id;
        }

        return $goal_id;
    }

    /**
     * Update goal progress.
     *
     * @param int    $goal_id
     * @param int    $progress (0-100)
     * @param string $note     Optional note
     * @return true|\WP_Error
     */
    public static function update_progress( int $goal_id, int $progress, string $note = '' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_goals';
        $history_table = $wpdb->prefix . 'sfs_hr_performance_goal_history';

        // Validate goal exists
        $goal = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE id = %d",
            $goal_id
        ) );

        if ( ! $goal ) {
            return new \WP_Error( 'not_found', __( 'Goal not found', 'sfs-hr' ) );
        }

        $progress = min( 100, max( 0, $progress ) );
        $now = current_time( 'mysql' );

        // Update goal progress
        $update_data = [
            'progress'   => $progress,
            'updated_at' => $now,
        ];

        // Auto-complete goal when progress reaches 100%
        if ( $progress === 100 && $goal->status === 'active' ) {
            $update_data['status'] = 'completed';
        }

        $wpdb->update( $table, $update_data, [ 'id' => $goal_id ] );

        // Log progress history
        $wpdb->insert( $history_table, [
            'goal_id'    => $goal_id,
            'progress'   => $progress,
            'note'       => sanitize_textarea_field( $note ),
            'updated_by' => get_current_user_id(),
            'created_at' => $now,
        ] );

        return true;
    }

    /**
     * Get a single goal.
     *
     * @param int $goal_id
     * @return object|null
     */
    public static function get_goal( int $goal_id ): ?object {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_goals';

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $goal_id
        ) );
    }

    /**
     * Get goals for an employee.
     *
     * @param int    $employee_id
     * @param string $status      Filter by status (empty for all)
     * @param string $start_date  Filter goals with target_date >= this
     * @param string $end_date    Filter goals with target_date <= this
     * @return array
     */
    public static function get_employee_goals(
        int $employee_id,
        string $status = '',
        string $start_date = '',
        string $end_date = ''
    ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_goals';

        $where = [ $wpdb->prepare( "employee_id = %d", $employee_id ) ];

        if ( ! empty( $status ) ) {
            $where[] = $wpdb->prepare( "status = %s", $status );
        }

        if ( ! empty( $start_date ) ) {
            $where[] = $wpdb->prepare( "(target_date IS NULL OR target_date >= %s)", $start_date );
        }

        if ( ! empty( $end_date ) ) {
            $where[] = $wpdb->prepare( "(target_date IS NULL OR target_date <= %s)", $end_date );
        }

        $where_sql = implode( ' AND ', $where );

        return $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE {$where_sql}
             ORDER BY
                CASE status
                    WHEN 'active' THEN 1
                    WHEN 'on_hold' THEN 2
                    WHEN 'completed' THEN 3
                    WHEN 'cancelled' THEN 4
                END,
                target_date ASC,
                created_at DESC"
        );
    }

    /**
     * Get goal progress history.
     *
     * @param int $goal_id
     * @return array
     */
    public static function get_goal_history( int $goal_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_goal_history';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT h.*, u.display_name as updated_by_name
             FROM {$table} h
             LEFT JOIN {$wpdb->users} u ON u.ID = h.updated_by
             WHERE h.goal_id = %d
             ORDER BY h.created_at DESC",
            $goal_id
        ), ARRAY_A );
    }

    /**
     * Calculate goals completion percentage for an employee.
     *
     * @param int    $employee_id
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function calculate_goals_metrics( int $employee_id, string $start_date = '', string $end_date = '' ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_goals';

        // Default to current year if no dates provided
        if ( empty( $start_date ) ) {
            $start_date = date( 'Y-01-01' );
        }
        if ( empty( $end_date ) ) {
            $end_date = date( 'Y-12-31' );
        }

        // Get goals for the period (include goals without target date or within range)
        $goals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, weight, progress, status, target_date
             FROM {$table}
             WHERE employee_id = %d
               AND (
                   target_date IS NULL
                   OR (target_date >= %s AND target_date <= %s)
                   OR created_at BETWEEN %s AND %s
               )
               AND status != 'cancelled'",
            $employee_id,
            $start_date,
            $end_date,
            $start_date,
            $end_date . ' 23:59:59'
        ) );

        $metrics = [
            'total_goals'       => count( $goals ),
            'completed_goals'   => 0,
            'active_goals'      => 0,
            'on_hold_goals'     => 0,
            'avg_progress'      => 0,
            'weighted_completion_pct' => 0,
            'goals'             => [],
        ];

        if ( empty( $goals ) ) {
            return $metrics;
        }

        $total_weight = 0;
        $weighted_progress = 0;
        $total_progress = 0;

        foreach ( $goals as $goal ) {
            $weight = max( 1, (int) $goal->weight );
            $progress = (int) $goal->progress;

            $metrics['goals'][] = [
                'id'          => $goal->id,
                'title'       => $goal->title,
                'weight'      => $weight,
                'progress'    => $progress,
                'status'      => $goal->status,
                'target_date' => $goal->target_date,
            ];

            switch ( $goal->status ) {
                case 'completed':
                    $metrics['completed_goals']++;
                    break;
                case 'active':
                    $metrics['active_goals']++;
                    break;
                case 'on_hold':
                    $metrics['on_hold_goals']++;
                    break;
            }

            $total_progress += $progress;
            $total_weight += $weight;
            $weighted_progress += ( $progress * $weight );
        }

        // Calculate averages
        if ( count( $goals ) > 0 ) {
            $metrics['avg_progress'] = round( $total_progress / count( $goals ), 2 );
        }

        if ( $total_weight > 0 ) {
            $metrics['weighted_completion_pct'] = round( $weighted_progress / $total_weight, 2 );
        }

        return $metrics;
    }

    /**
     * Delete a goal.
     *
     * @param int $goal_id
     * @return bool
     */
    public static function delete_goal( int $goal_id ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_goals';
        $history_table = $wpdb->prefix . 'sfs_hr_performance_goal_history';

        // Delete history first
        $wpdb->delete( $history_table, [ 'goal_id' => $goal_id ] );

        // Delete goal
        return (bool) $wpdb->delete( $table, [ 'id' => $goal_id ] );
    }

    /**
     * Get goal categories used in the system.
     *
     * @return array
     */
    public static function get_categories(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_goals';

        $categories = $wpdb->get_col(
            "SELECT DISTINCT category FROM {$table}
             WHERE category IS NOT NULL AND category != ''
             ORDER BY category"
        );

        // Add default categories if empty
        if ( empty( $categories ) ) {
            $categories = [
                'professional',
                'technical',
                'personal',
                'team',
            ];
        }

        return $categories;
    }

    /**
     * Get overdue goals for an employee.
     *
     * @param int $employee_id
     * @return array
     */
    public static function get_overdue_goals( int $employee_id = 0 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_goals';
        $today = date( 'Y-m-d' );

        $where = [
            "status = 'active'",
            $wpdb->prepare( "target_date < %s", $today ),
            "target_date IS NOT NULL",
        ];

        if ( $employee_id > 0 ) {
            $where[] = $wpdb->prepare( "employee_id = %d", $employee_id );
        }

        $where_sql = implode( ' AND ', $where );

        return $wpdb->get_results(
            "SELECT g.*, e.first_name, e.last_name, e.employee_code
             FROM {$table} g
             JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = g.employee_id
             WHERE {$where_sql}
             ORDER BY target_date ASC"
        );
    }
}
