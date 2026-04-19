<?php
namespace SFS\HR\Modules\Performance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Goal Hierarchy Service
 *
 * Manages hierarchical OKR objectives and key results including:
 * - Company / department / individual objective CRUD
 * - Full tree cascade (top-down) and alignment chain (bottom-up)
 * - Key result (KR) management per objective
 * - Weight-based progress roll-up through the hierarchy
 *
 * Tables:
 *   {prefix}sfs_hr_performance_objectives
 *   {prefix}sfs_hr_performance_key_results
 *
 * @package SFS\HR\Modules\Performance\Services
 * @since   2.3.0
 */
class Goal_Hierarchy_Service {

    // -------------------------------------------------------------------------
    // Objectives CRUD
    // -------------------------------------------------------------------------

    /**
     * Create a new objective.
     *
     * @param array $data {
     *   @type string      $title          Required. Objective title.
     *   @type string      $description    Optional.
     *   @type int|null    $parent_id      Optional. Parent objective ID.
     *   @type string      $level          'company'|'department'|'individual'. Default 'individual'.
     *   @type string      $owner_type     'company'|'department'|'employee'. Default 'employee'.
     *   @type int         $owner_id       Owner entity ID (dept ID, employee ID, or 0 for company).
     *   @type float       $weight         Relative weight among siblings. Default 1.00.
     *   @type string      $progress_type  'percentage'|'milestone'|'binary'. Default 'percentage'.
     *   @type float       $progress       Initial progress. Default 0.
     *   @type string      $status         'draft'|'active'|'completed'|'cancelled'. Default 'draft'.
     *   @type string      $start_date     DATE string or empty.
     *   @type string      $due_date       DATE string or empty.
     *   @type int|null    $review_cycle_id Review cycle FK or null.
     * }
     * @return array{ok: bool, id?: int, error?: string}
     */
    public static function create_objective( array $data ): array {
        global $wpdb;

        if ( empty( $data['title'] ) ) {
            return [ 'ok' => false, 'error' => __( 'Objective title is required.', 'sfs-hr' ) ];
        }

        $allowed_levels        = [ 'company', 'department', 'individual' ];
        $allowed_owner_types   = [ 'company', 'department', 'employee' ];
        $allowed_progress_types = [ 'percentage', 'milestone', 'binary' ];
        $allowed_statuses      = [ 'draft', 'active', 'completed', 'cancelled' ];

        $parent_id = ! empty( $data['parent_id'] ) ? (int) $data['parent_id'] : null;

        // Validate parent exists when provided.
        if ( $parent_id !== null ) {
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sfs_hr_performance_objectives WHERE id = %d",
                $parent_id
            ) );
            if ( ! $exists ) {
                return [ 'ok' => false, 'error' => __( 'Parent objective not found.', 'sfs-hr' ) ];
            }
        }

        $now = current_time( 'mysql' );

        $row = [
            'parent_id'       => $parent_id,
            'level'           => in_array( $data['level'] ?? '', $allowed_levels, true )
                                    ? $data['level'] : 'individual',
            'title'           => sanitize_text_field( $data['title'] ),
            'description'     => sanitize_textarea_field( $data['description'] ?? '' ),
            'owner_type'      => in_array( $data['owner_type'] ?? '', $allowed_owner_types, true )
                                    ? $data['owner_type'] : 'employee',
            'owner_id'        => isset( $data['owner_id'] ) ? (int) $data['owner_id'] : 0,
            'weight'          => isset( $data['weight'] ) ? (float) $data['weight'] : 1.00,
            'progress_type'   => in_array( $data['progress_type'] ?? '', $allowed_progress_types, true )
                                    ? $data['progress_type'] : 'percentage',
            'progress'        => isset( $data['progress'] ) ? min( 100, max( 0, (float) $data['progress'] ) ) : 0.00,
            'status'          => in_array( $data['status'] ?? '', $allowed_statuses, true )
                                    ? $data['status'] : 'draft',
            'start_date'      => ! empty( $data['start_date'] ) ? sanitize_text_field( $data['start_date'] ) : null,
            'due_date'        => ! empty( $data['due_date'] ) ? sanitize_text_field( $data['due_date'] ) : null,
            'review_cycle_id' => ! empty( $data['review_cycle_id'] ) ? (int) $data['review_cycle_id'] : null,
            'created_by'      => get_current_user_id(),
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        $inserted = $wpdb->insert( "{$wpdb->prefix}sfs_hr_performance_objectives", $row );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'error' => __( 'Database error while creating objective.', 'sfs-hr' ) ];
        }

        return [ 'ok' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Update an existing objective.
     *
     * Only the keys present in $data are updated; omitted keys are left unchanged.
     *
     * @param int   $id   Objective ID.
     * @param array $data Fields to update (same keys as create_objective $data).
     * @return array{ok: bool, error?: string}
     */
    public static function update_objective( int $id, array $data ): array {
        global $wpdb;

        $table = "{$wpdb->prefix}sfs_hr_performance_objectives";

        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
        if ( ! $existing ) {
            return [ 'ok' => false, 'error' => __( 'Objective not found.', 'sfs-hr' ) ];
        }

        $allowed_levels         = [ 'company', 'department', 'individual' ];
        $allowed_owner_types    = [ 'company', 'department', 'employee' ];
        $allowed_progress_types = [ 'percentage', 'milestone', 'binary' ];
        $allowed_statuses       = [ 'draft', 'active', 'completed', 'cancelled' ];

        $update = [];

        if ( array_key_exists( 'title', $data ) ) {
            $update['title'] = sanitize_text_field( $data['title'] );
        }
        if ( array_key_exists( 'description', $data ) ) {
            $update['description'] = sanitize_textarea_field( $data['description'] );
        }
        if ( array_key_exists( 'parent_id', $data ) ) {
            if ( ! empty( $data['parent_id'] ) ) {
                $parent_id = (int) $data['parent_id'];
                if ( $parent_id === $id ) {
                    return [ 'ok' => false, 'error' => __( 'An objective cannot be its own parent.', 'sfs-hr' ) ];
                }
                // Guard against circular references.
                if ( self::is_descendant( $id, $parent_id ) ) {
                    return [ 'ok' => false, 'error' => __( 'Circular hierarchy detected.', 'sfs-hr' ) ];
                }
                $update['parent_id'] = $parent_id;
            } else {
                $update['parent_id'] = null;
            }
        }
        if ( array_key_exists( 'level', $data ) && in_array( $data['level'], $allowed_levels, true ) ) {
            $update['level'] = $data['level'];
        }
        if ( array_key_exists( 'owner_type', $data ) && in_array( $data['owner_type'], $allowed_owner_types, true ) ) {
            $update['owner_type'] = $data['owner_type'];
        }
        if ( array_key_exists( 'owner_id', $data ) ) {
            $update['owner_id'] = (int) $data['owner_id'];
        }
        if ( array_key_exists( 'weight', $data ) ) {
            $update['weight'] = (float) $data['weight'];
        }
        if ( array_key_exists( 'progress_type', $data ) && in_array( $data['progress_type'], $allowed_progress_types, true ) ) {
            $update['progress_type'] = $data['progress_type'];
        }
        if ( array_key_exists( 'progress', $data ) ) {
            $update['progress'] = min( 100, max( 0, (float) $data['progress'] ) );
        }
        if ( array_key_exists( 'status', $data ) && in_array( $data['status'], $allowed_statuses, true ) ) {
            $update['status'] = $data['status'];
        }
        if ( array_key_exists( 'start_date', $data ) ) {
            $update['start_date'] = ! empty( $data['start_date'] ) ? sanitize_text_field( $data['start_date'] ) : null;
        }
        if ( array_key_exists( 'due_date', $data ) ) {
            $update['due_date'] = ! empty( $data['due_date'] ) ? sanitize_text_field( $data['due_date'] ) : null;
        }
        if ( array_key_exists( 'review_cycle_id', $data ) ) {
            $update['review_cycle_id'] = ! empty( $data['review_cycle_id'] ) ? (int) $data['review_cycle_id'] : null;
        }

        if ( empty( $update ) ) {
            return [ 'ok' => true ]; // nothing to do
        }

        $update['updated_at'] = current_time( 'mysql' );

        $wpdb->update( $table, $update, [ 'id' => $id ] );

        return [ 'ok' => true ];
    }

    /**
     * Delete an objective and all its descendants and key results.
     *
     * @param int $id Objective ID.
     * @return array{ok: bool, deleted: int, error?: string}
     */
    public static function delete_objective( int $id ): array {
        global $wpdb;

        $table    = "{$wpdb->prefix}sfs_hr_performance_objectives";
        $kr_table = "{$wpdb->prefix}sfs_hr_performance_key_results";

        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
        if ( ! $existing ) {
            return [ 'ok' => false, 'deleted' => 0, 'error' => __( 'Objective not found.', 'sfs-hr' ) ];
        }

        // Collect the full subtree (inclusive of the root).
        $all_ids = self::collect_subtree_ids( $id );

        if ( empty( $all_ids ) ) {
            return [ 'ok' => false, 'deleted' => 0, 'error' => __( 'Failed to collect subtree.', 'sfs-hr' ) ];
        }

        // Build IN placeholder list.
        $in_placeholders = implode( ', ', array_fill( 0, count( $all_ids ), '%d' ) );

        // Delete key results for all objectives in the subtree.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$kr_table} WHERE objective_id IN ({$in_placeholders})",
                ...$all_ids
            )
        );

        // Delete all objectives in the subtree.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE id IN ({$in_placeholders})",
                ...$all_ids
            )
        );

        return [ 'ok' => true, 'deleted' => (int) $deleted ];
    }

    /**
     * Retrieve a single objective by ID.
     *
     * @param int $id Objective ID.
     * @return array|null Associative array or null if not found.
     */
    public static function get_objective( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_objectives WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    // -------------------------------------------------------------------------
    // Hierarchy retrieval
    // -------------------------------------------------------------------------

    /**
     * Get all company-level objectives.
     *
     * @param array $filters {
     *   @type string   $status          Filter by status.
     *   @type int|null $review_cycle_id Filter by review cycle.
     * }
     * @return array List of objectives as associative arrays.
     */
    public static function get_company_goals( array $filters = [] ): array {
        global $wpdb;

        $where   = [ "level = 'company'" ];
        $params  = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        if ( ! empty( $filters['review_cycle_id'] ) ) {
            $where[]  = 'review_cycle_id = %d';
            $params[] = (int) $filters['review_cycle_id'];
        }

        $where_sql = implode( ' AND ', $where );
        $sql       = "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_objectives WHERE {$where_sql} ORDER BY weight DESC, created_at ASC";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, ...$params );
        }

        return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Get all objectives owned by a department.
     *
     * @param int   $dept_id Department ID.
     * @param array $filters {
     *   @type string   $status
     *   @type int|null $review_cycle_id
     * }
     * @return array
     */
    public static function get_department_goals( int $dept_id, array $filters = [] ): array {
        global $wpdb;

        $where   = [ "owner_type = 'department'", 'owner_id = %d' ];
        $params  = [ $dept_id ];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        if ( ! empty( $filters['review_cycle_id'] ) ) {
            $where[]  = 'review_cycle_id = %d';
            $params[] = (int) $filters['review_cycle_id'];
        }

        $where_sql = implode( ' AND ', $where );
        $sql       = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_objectives WHERE {$where_sql} ORDER BY weight DESC, created_at ASC",
            ...$params
        );

        return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Get all objectives owned by an employee.
     *
     * @param int   $employee_id Employee ID (sfs_hr_employees.id).
     * @param array $filters {
     *   @type string   $status
     *   @type int|null $review_cycle_id
     * }
     * @return array
     */
    public static function get_employee_goals( int $employee_id, array $filters = [] ): array {
        global $wpdb;

        $where   = [ "owner_type = 'employee'", 'owner_id = %d' ];
        $params  = [ $employee_id ];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        if ( ! empty( $filters['review_cycle_id'] ) ) {
            $where[]  = 'review_cycle_id = %d';
            $params[] = (int) $filters['review_cycle_id'];
        }

        $where_sql = implode( ' AND ', $where );
        $sql       = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_objectives WHERE {$where_sql} ORDER BY weight DESC, created_at ASC",
            ...$params
        );

        return $wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Get immediate children of an objective.
     *
     * @param int $parent_id Parent objective ID.
     * @return array
     */
    public static function get_children( int $parent_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_objectives WHERE parent_id = %d ORDER BY weight DESC, created_at ASC",
                $parent_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get the full cascade tree downward from an objective (recursive).
     *
     * Returns a nested structure: each node has a 'children' key.
     *
     * @param int $objective_id Root of the sub-tree.
     * @return array Nested tree array.
     */
    public static function get_cascade( int $objective_id ): array {
        $root = self::get_objective( $objective_id );
        if ( $root === null ) {
            return [];
        }

        $root['children'] = self::build_cascade_tree( $objective_id );

        return $root;
    }

    /**
     * Get the alignment chain from an objective up to the company root.
     *
     * Returns an ordered list starting from the objective itself up to the topmost ancestor.
     *
     * @param int $objective_id
     * @return array Ordered chain from current objective to company root (inclusive).
     */
    public static function get_alignment_chain( int $objective_id ): array {
        $chain     = [];
        $current   = self::get_objective( $objective_id );
        $visited   = []; // cycle guard

        while ( $current !== null ) {
            if ( isset( $visited[ $current['id'] ] ) ) {
                break; // circular reference guard
            }
            $visited[ $current['id'] ] = true;
            $chain[]                   = $current;

            if ( empty( $current['parent_id'] ) ) {
                break;
            }

            $current = self::get_objective( (int) $current['parent_id'] );
        }

        return $chain;
    }

    // -------------------------------------------------------------------------
    // Key Results CRUD
    // -------------------------------------------------------------------------

    /**
     * Add a key result to an objective.
     *
     * @param int   $objective_id Objective ID.
     * @param array $data {
     *   @type string $title        Required.
     *   @type string $description  Optional.
     *   @type string $metric_type  'number'|'percentage'|'currency'|'boolean'. Default 'number'.
     *   @type float  $start_value  Default 0.
     *   @type float  $target_value Required.
     *   @type float  $current_value Default 0.
     *   @type float  $weight       Default 1.00.
     *   @type string $status       'on_track'|'at_risk'|'behind'|'completed'. Default 'on_track'.
     * }
     * @return array{ok: bool, id?: int, error?: string}
     */
    public static function add_key_result( int $objective_id, array $data ): array {
        global $wpdb;

        // Validate objective exists.
        $obj_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_performance_objectives WHERE id = %d",
            $objective_id
        ) );

        if ( ! $obj_exists ) {
            return [ 'ok' => false, 'error' => __( 'Objective not found.', 'sfs-hr' ) ];
        }

        if ( empty( $data['title'] ) ) {
            return [ 'ok' => false, 'error' => __( 'Key result title is required.', 'sfs-hr' ) ];
        }

        if ( ! isset( $data['target_value'] ) || $data['target_value'] === '' ) {
            return [ 'ok' => false, 'error' => __( 'Key result target value is required.', 'sfs-hr' ) ];
        }

        $allowed_metric_types = [ 'number', 'percentage', 'currency', 'boolean' ];
        $allowed_statuses     = [ 'on_track', 'at_risk', 'behind', 'completed' ];

        $now = current_time( 'mysql' );

        $row = [
            'objective_id'  => $objective_id,
            'title'         => sanitize_text_field( $data['title'] ),
            'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
            'metric_type'   => in_array( $data['metric_type'] ?? '', $allowed_metric_types, true )
                                  ? $data['metric_type'] : 'number',
            'start_value'   => isset( $data['start_value'] ) ? (float) $data['start_value'] : 0.00,
            'target_value'  => (float) $data['target_value'],
            'current_value' => isset( $data['current_value'] ) ? (float) $data['current_value'] : 0.00,
            'weight'        => isset( $data['weight'] ) ? (float) $data['weight'] : 1.00,
            'status'        => in_array( $data['status'] ?? '', $allowed_statuses, true )
                                  ? $data['status'] : 'on_track',
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        $inserted = $wpdb->insert( "{$wpdb->prefix}sfs_hr_performance_key_results", $row );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'error' => __( 'Database error while adding key result.', 'sfs-hr' ) ];
        }

        $kr_id = (int) $wpdb->insert_id;

        // Recalculate the parent objective progress.
        self::recalc_objective_progress( $objective_id );

        return [ 'ok' => true, 'id' => $kr_id ];
    }

    /**
     * Update an existing key result.
     *
     * @param int   $kr_id Key result ID.
     * @param array $data  Fields to update.
     * @return array{ok: bool, error?: string}
     */
    public static function update_key_result( int $kr_id, array $data ): array {
        global $wpdb;

        $kr_table = "{$wpdb->prefix}sfs_hr_performance_key_results";

        $kr = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$kr_table} WHERE id = %d", $kr_id ), ARRAY_A );
        if ( ! $kr ) {
            return [ 'ok' => false, 'error' => __( 'Key result not found.', 'sfs-hr' ) ];
        }

        $allowed_metric_types = [ 'number', 'percentage', 'currency', 'boolean' ];
        $allowed_statuses     = [ 'on_track', 'at_risk', 'behind', 'completed' ];

        $update = [];

        if ( array_key_exists( 'title', $data ) ) {
            $update['title'] = sanitize_text_field( $data['title'] );
        }
        if ( array_key_exists( 'description', $data ) ) {
            $update['description'] = sanitize_textarea_field( $data['description'] );
        }
        if ( array_key_exists( 'metric_type', $data ) && in_array( $data['metric_type'], $allowed_metric_types, true ) ) {
            $update['metric_type'] = $data['metric_type'];
        }
        if ( array_key_exists( 'start_value', $data ) ) {
            $update['start_value'] = (float) $data['start_value'];
        }
        if ( array_key_exists( 'target_value', $data ) ) {
            $update['target_value'] = (float) $data['target_value'];
        }
        if ( array_key_exists( 'current_value', $data ) ) {
            $update['current_value'] = (float) $data['current_value'];
        }
        if ( array_key_exists( 'weight', $data ) ) {
            $update['weight'] = (float) $data['weight'];
        }
        if ( array_key_exists( 'status', $data ) && in_array( $data['status'], $allowed_statuses, true ) ) {
            $update['status'] = $data['status'];
        }

        if ( empty( $update ) ) {
            return [ 'ok' => true ];
        }

        $update['updated_at'] = current_time( 'mysql' );

        $wpdb->update( $kr_table, $update, [ 'id' => $kr_id ] );

        // Recalculate parent objective progress.
        self::recalc_objective_progress( (int) $kr['objective_id'] );

        return [ 'ok' => true ];
    }

    /**
     * Delete a key result.
     *
     * @param int $kr_id Key result ID.
     * @return array{ok: bool, error?: string}
     */
    public static function delete_key_result( int $kr_id ): array {
        global $wpdb;

        $kr_table = "{$wpdb->prefix}sfs_hr_performance_key_results";

        $kr = $wpdb->get_row( $wpdb->prepare( "SELECT objective_id FROM {$kr_table} WHERE id = %d", $kr_id ) );
        if ( ! $kr ) {
            return [ 'ok' => false, 'error' => __( 'Key result not found.', 'sfs-hr' ) ];
        }

        $wpdb->delete( $kr_table, [ 'id' => $kr_id ] );

        // Recalculate parent objective progress after deletion.
        self::recalc_objective_progress( (int) $kr->objective_id );

        return [ 'ok' => true ];
    }

    /**
     * Get all key results for an objective.
     *
     * @param int $objective_id Objective ID.
     * @return array List of key result rows as associative arrays.
     */
    public static function get_key_results( int $objective_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_key_results WHERE objective_id = %d ORDER BY weight DESC, created_at ASC",
                $objective_id
            ),
            ARRAY_A
        ) ?: [];
    }

    // -------------------------------------------------------------------------
    // Progress
    // -------------------------------------------------------------------------

    /**
     * Directly set the progress on an objective and propagate upward.
     *
     * Use this when the objective has no key results (manual tracking).
     *
     * @param int   $objective_id Objective ID.
     * @param float $progress     Value between 0 and 100.
     * @return array{ok: bool, progress: float, error?: string}
     */
    public static function update_progress( int $objective_id, float $progress ): array {
        global $wpdb;

        $table = "{$wpdb->prefix}sfs_hr_performance_objectives";

        $obj = $wpdb->get_row( $wpdb->prepare( "SELECT id, parent_id FROM {$table} WHERE id = %d", $objective_id ), ARRAY_A );
        if ( ! $obj ) {
            return [ 'ok' => false, 'progress' => 0.0, 'error' => __( 'Objective not found.', 'sfs-hr' ) ];
        }

        $progress = (float) min( 100, max( 0, $progress ) );

        $wpdb->update(
            $table,
            [
                'progress'   => $progress,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $objective_id ]
        );

        // Propagate progress change up the hierarchy.
        if ( ! empty( $obj['parent_id'] ) ) {
            self::recalc_parent_progress( (int) $obj['parent_id'] );
        }

        return [ 'ok' => true, 'progress' => $progress ];
    }

    /**
     * Recalculate an objective's progress from its key results (weighted average).
     *
     * Each KR contributes: (current - start) / (target - start) * 100, capped 0-100.
     * The weighted average of all KR contributions is stored on the objective.
     * After updating, propagates to parent if one exists.
     *
     * @param int $objective_id Objective ID.
     * @return float Calculated progress (0-100), or 0 if no KRs.
     */
    public static function recalc_objective_progress( int $objective_id ): float {
        global $wpdb;

        $table    = "{$wpdb->prefix}sfs_hr_performance_objectives";
        $kr_table = "{$wpdb->prefix}sfs_hr_performance_key_results";

        $obj = $wpdb->get_row( $wpdb->prepare( "SELECT id, parent_id, progress_type FROM {$table} WHERE id = %d", $objective_id ), ARRAY_A );
        if ( ! $obj ) {
            return 0.0;
        }

        $krs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT start_value, target_value, current_value, weight, metric_type FROM {$kr_table} WHERE objective_id = %d",
                $objective_id
            ),
            ARRAY_A
        );

        if ( empty( $krs ) ) {
            // No KRs; leave existing manual progress unchanged.
            $existing = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT progress FROM {$table} WHERE id = %d",
                $objective_id
            ) );
            return $existing;
        }

        $total_weight       = 0.0;
        $weighted_progress  = 0.0;

        foreach ( $krs as $kr ) {
            $weight       = max( 0.0001, (float) $kr['weight'] ); // avoid division by zero
            $start        = (float) $kr['start_value'];
            $target       = (float) $kr['target_value'];
            $current      = (float) $kr['current_value'];
            $metric_type  = $kr['metric_type'];

            // Calculate individual KR completion percentage.
            if ( $metric_type === 'boolean' ) {
                $kr_pct = $current >= 1.0 ? 100.0 : 0.0;
            } else {
                $range = $target - $start;
                if ( abs( $range ) < 0.0001 ) {
                    $kr_pct = $current >= $target ? 100.0 : 0.0;
                } else {
                    $kr_pct = ( ( $current - $start ) / $range ) * 100.0;
                    $kr_pct = min( 100.0, max( 0.0, $kr_pct ) );
                }
            }

            $weighted_progress += $kr_pct * $weight;
            $total_weight      += $weight;
        }

        $progress = $total_weight > 0.0 ? round( $weighted_progress / $total_weight, 2 ) : 0.0;

        $wpdb->update(
            $table,
            [
                'progress'   => $progress,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $objective_id ]
        );

        // Propagate upward.
        if ( ! empty( $obj['parent_id'] ) ) {
            self::recalc_parent_progress( (int) $obj['parent_id'] );
        }

        return $progress;
    }

    /**
     * Recalculate a parent objective's progress as the weighted average of its children's progress.
     *
     * After updating the parent, continues propagating further up the tree.
     *
     * @param int $parent_id Parent objective ID.
     * @return float Calculated progress (0-100), or 0 if no children.
     */
    public static function recalc_parent_progress( int $parent_id ): float {
        global $wpdb;

        $table = "{$wpdb->prefix}sfs_hr_performance_objectives";

        $parent = $wpdb->get_row( $wpdb->prepare( "SELECT id, parent_id FROM {$table} WHERE id = %d", $parent_id ), ARRAY_A );
        if ( ! $parent ) {
            return 0.0;
        }

        $children = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT progress, weight FROM {$table} WHERE parent_id = %d",
                $parent_id
            ),
            ARRAY_A
        );

        if ( empty( $children ) ) {
            return 0.0;
        }

        $total_weight      = 0.0;
        $weighted_progress = 0.0;

        foreach ( $children as $child ) {
            $weight            = max( 0.0001, (float) $child['weight'] );
            $progress          = min( 100.0, max( 0.0, (float) $child['progress'] ) );
            $weighted_progress += $progress * $weight;
            $total_weight      += $weight;
        }

        $progress = $total_weight > 0.0 ? round( $weighted_progress / $total_weight, 2 ) : 0.0;

        $wpdb->update(
            $table,
            [
                'progress'   => $progress,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $parent_id ]
        );

        // Continue propagating upward.
        if ( ! empty( $parent['parent_id'] ) ) {
            self::recalc_parent_progress( (int) $parent['parent_id'] );
        }

        return $progress;
    }

    // -------------------------------------------------------------------------
    // Weighting / scoring
    // -------------------------------------------------------------------------

    /**
     * Calculate the weighted OKR score for an employee within an optional review cycle.
     *
     * Score = weighted average of progress across all non-cancelled objectives
     *         owned by the employee within the given cycle (or all active objectives
     *         if no cycle is specified).
     *
     * @param int      $employee_id Employee ID (sfs_hr_employees.id).
     * @param int|null $cycle_id    Review cycle ID, or null for all.
     * @return float Score 0-100.
     */
    public static function get_weighted_score( int $employee_id, ?int $cycle_id = null ): float {
        global $wpdb;

        $table  = "{$wpdb->prefix}sfs_hr_performance_objectives";
        $where  = [ "owner_type = 'employee'", 'owner_id = %d', "status != 'cancelled'" ];
        $params = [ $employee_id ];

        if ( $cycle_id !== null ) {
            $where[]  = 'review_cycle_id = %d';
            $params[] = $cycle_id;
        }

        $where_sql = implode( ' AND ', $where );
        $sql       = $wpdb->prepare(
            "SELECT progress, weight FROM {$table} WHERE {$where_sql}",
            ...$params
        );

        $objectives = $wpdb->get_results( $sql, ARRAY_A );

        if ( empty( $objectives ) ) {
            return 0.0;
        }

        $total_weight      = 0.0;
        $weighted_progress = 0.0;

        foreach ( $objectives as $obj ) {
            $weight            = max( 0.0001, (float) $obj['weight'] );
            $progress          = min( 100.0, max( 0.0, (float) $obj['progress'] ) );
            $weighted_progress += $progress * $weight;
            $total_weight      += $weight;
        }

        return $total_weight > 0.0 ? round( $weighted_progress / $total_weight, 2 ) : 0.0;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively build a cascade tree for an objective.
     *
     * @param int $parent_id
     * @return array Nested children array.
     */
    private static function build_cascade_tree( int $parent_id ): array {
        global $wpdb;

        $children = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_performance_objectives WHERE parent_id = %d ORDER BY weight DESC, created_at ASC",
                $parent_id
            ),
            ARRAY_A
        );

        if ( empty( $children ) ) {
            return [];
        }

        foreach ( $children as &$child ) {
            $child['children'] = self::build_cascade_tree( (int) $child['id'] );
        }
        unset( $child );

        return $children;
    }

    /**
     * Collect all descendant IDs (inclusive of the root) for deletion.
     *
     * Uses an iterative BFS to avoid deep call-stack issues with very large trees.
     *
     * @param int $root_id Root objective ID.
     * @return int[] List of all IDs in the subtree.
     */
    private static function collect_subtree_ids( int $root_id ): array {
        global $wpdb;

        $table    = "{$wpdb->prefix}sfs_hr_performance_objectives";
        $all_ids  = [];
        $queue    = [ $root_id ];

        while ( ! empty( $queue ) ) {
            $current_id = array_shift( $queue );
            $all_ids[]  = $current_id;

            $child_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE parent_id = %d",
                $current_id
            ) );

            foreach ( $child_ids as $child_id ) {
                $queue[] = (int) $child_id;
            }
        }

        return $all_ids;
    }

    /**
     * Check whether $candidate_id is a descendant of $ancestor_id.
     *
     * Used to guard against circular references when re-parenting objectives.
     *
     * @param int $ancestor_id  The suspected ancestor.
     * @param int $candidate_id The node to test.
     * @return bool True if $candidate_id is a descendant of $ancestor_id.
     */
    private static function is_descendant( int $ancestor_id, int $candidate_id ): bool {
        $descendant_ids = self::collect_subtree_ids( $ancestor_id );
        // Remove the root itself; we only care about strict descendants.
        $descendant_ids = array_filter( $descendant_ids, static fn( $id ) => $id !== $ancestor_id );
        return in_array( $candidate_id, $descendant_ids, true );
    }
}
