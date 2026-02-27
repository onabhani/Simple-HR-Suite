<?php
namespace SFS\HR\Modules\Projects\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Projects Service — data access helpers.
 */
class Projects_Service {

    /**
     * Get all projects (optionally filter by active status).
     */
    public static function get_all( bool $active_only = false ): array {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_projects';
        $where = $active_only ? 'WHERE active = 1' : '';
        return $wpdb->get_results( "SELECT * FROM {$t} {$where} ORDER BY name ASC" );
    }

    /**
     * Get a single project by ID.
     */
    public static function get( int $id ): ?\stdClass {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_projects';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
    }

    /**
     * Insert a new project. Returns the new ID or 0 on failure.
     */
    public static function insert( array $data ): int {
        global $wpdb;
        $t   = $wpdb->prefix . 'sfs_hr_projects';
        $now = current_time( 'mysql' );
        $ok  = $wpdb->insert( $t, array_merge( $data, [
            'created_at' => $now,
            'updated_at' => $now,
        ] ) );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Update an existing project.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_projects';
        $data['updated_at'] = current_time( 'mysql' );
        return (bool) $wpdb->update( $t, $data, [ 'id' => $id ] );
    }

    /* ---- Employee assignments ---- */

    /**
     * Get employees assigned to a project (with optional date filter).
     */
    public static function get_project_employees( int $project_id, string $on_date = '' ): array {
        global $wpdb;
        $pe = $wpdb->prefix . 'sfs_hr_project_employees';
        $e  = $wpdb->prefix . 'sfs_hr_employees';

        $sql = "SELECT pe.*, e.first_name, e.last_name, e.employee_code, e.dept_id
                FROM {$pe} pe
                JOIN {$e} e ON e.id = pe.employee_id
                WHERE pe.project_id = %d";
        $args = [ $project_id ];

        if ( $on_date ) {
            $sql .= " AND pe.assigned_from <= %s AND (pe.assigned_to IS NULL OR pe.assigned_to >= %s)";
            $args[] = $on_date;
            $args[] = $on_date;
        }

        $sql .= " ORDER BY e.first_name, e.last_name";
        return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
    }

    /**
     * Assign an employee to a project.
     */
    public static function assign_employee( int $project_id, int $employee_id, string $from, ?string $to = null ): int {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_project_employees';
        $ok = $wpdb->insert( $t, [
            'project_id'    => $project_id,
            'employee_id'   => $employee_id,
            'assigned_from' => $from,
            'assigned_to'   => $to,
            'created_at'    => current_time( 'mysql' ),
            'created_by'    => get_current_user_id(),
        ] );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Remove an employee assignment.
     */
    public static function remove_employee_assignment( int $assignment_id ): bool {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_project_employees';
        return (bool) $wpdb->delete( $t, [ 'id' => $assignment_id ] );
    }

    /* ---- Shift assignments ---- */

    /**
     * Get shifts linked to a project.
     */
    public static function get_project_shifts( int $project_id ): array {
        global $wpdb;
        $ps = $wpdb->prefix . 'sfs_hr_project_shifts';
        $s  = $wpdb->prefix . 'sfs_hr_attendance_shifts';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ps.*, s.name AS shift_name, s.start_time, s.end_time,
                    s.location_label, s.location_lat, s.location_lng, s.location_radius_m
             FROM {$ps} ps
             JOIN {$s} s ON s.id = ps.shift_id
             WHERE ps.project_id = %d
             ORDER BY ps.is_default DESC, s.name ASC",
            $project_id
        ) );
    }

    /**
     * Link a shift to a project.
     */
    public static function add_shift( int $project_id, int $shift_id, bool $is_default = false ): int {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_project_shifts';

        // If setting as default, clear any existing default first
        if ( $is_default ) {
            $wpdb->update( $t, [ 'is_default' => 0 ], [ 'project_id' => $project_id ] );
        }

        $ok = $wpdb->insert( $t, [
            'project_id' => $project_id,
            'shift_id'   => $shift_id,
            'is_default' => (int) $is_default,
            'created_at' => current_time( 'mysql' ),
        ] );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Remove a shift from a project.
     */
    public static function remove_shift( int $link_id ): bool {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_project_shifts';
        return (bool) $wpdb->delete( $t, [ 'id' => $link_id ] );
    }

    /* ---- Resolution helper (used by shift resolution) ---- */

    /**
     * Find the active project for an employee on a given date.
     * Returns the project row with `default_shift_id` if set, or null.
     */
    public static function get_employee_project_on_date( int $employee_id, string $ymd ): ?\stdClass {
        global $wpdb;
        $pe = $wpdb->prefix . 'sfs_hr_project_employees';
        $pr = $wpdb->prefix . 'sfs_hr_projects';
        $ps = $wpdb->prefix . 'sfs_hr_project_shifts';

        // Check table existence first (module may not be installed yet)
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $pe
        ) );
        if ( ! $exists ) {
            return null;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT pr.*, pe.assigned_from, pe.assigned_to
             FROM {$pe} pe
             JOIN {$pr} pr ON pr.id = pe.project_id AND pr.active = 1
             WHERE pe.employee_id = %d
               AND pe.assigned_from <= %s
               AND (pe.assigned_to IS NULL OR pe.assigned_to >= %s)
             ORDER BY pe.id DESC
             LIMIT 1",
            $employee_id,
            $ymd,
            $ymd
        ) );

        if ( ! $row ) {
            return null;
        }

        // Attach the default shift for this project
        $default_shift = $wpdb->get_var( $wpdb->prepare(
            "SELECT shift_id FROM {$ps} WHERE project_id = %d AND is_default = 1 LIMIT 1",
            $row->id
        ) );
        $row->default_shift_id = $default_shift ? (int) $default_shift : null;

        return $row;
    }
}
