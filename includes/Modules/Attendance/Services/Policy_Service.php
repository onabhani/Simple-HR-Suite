<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Attendance Policy Service
 *
 * Looks up role-based attendance policies and provides validation helpers
 * for the punch flow. When no policy is found for an employee's role,
 * the system falls through to existing default behaviour — no disruption.
 *
 * @version 1.0.0
 */
class Policy_Service {

    /**
     * Runtime cache: employee_id → policy row (or null).
     *
     * @var array<int, object|null|false>  false = not yet looked up
     */
    private static array $cache = [];

    // =========================================================================
    // Lookup
    // =========================================================================

    /**
     * Get the active attendance policy for an employee (by WP role).
     *
     * Resolution:
     *  1. Find employee's WP user_id → WP roles.
     *  2. Check sfs_hr_attendance_policy_roles for a matching role_slug.
     *  3. Return the joined policy row, or null when none matches.
     *
     * @param int $employee_id  HR employee ID (not WP user ID).
     * @return object|null  Policy row with decoded JSON fields, or null.
     */
    public static function get_policy_for_employee( int $employee_id ): ?object {
        if ( isset( self::$cache[ $employee_id ] ) ) {
            $v = self::$cache[ $employee_id ];
            return $v === false ? null : $v;
        }

        global $wpdb;

        // 1. Resolve WP user_id from HR employee record
        $user_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d",
            $employee_id
        ) );

        if ( ! $user_id ) {
            self::$cache[ $employee_id ] = null;
            return null;
        }

        // 2. Get WP roles for this user
        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->roles ) ) {
            self::$cache[ $employee_id ] = null;
            return null;
        }

        $roles = $user->roles; // e.g. ['installing_team']

        // 3. Find a matching active policy
        $placeholders = implode( ',', array_fill( 0, count( $roles ), '%s' ) );
        $sql = $wpdb->prepare(
            "SELECT p.*
             FROM {$wpdb->prefix}sfs_hr_attendance_policies p
             INNER JOIN {$wpdb->prefix}sfs_hr_attendance_policy_roles pr
                 ON pr.policy_id = p.id
             WHERE pr.role_slug IN ({$placeholders})
               AND p.active = 1
             ORDER BY p.id ASC
             LIMIT 1",
            ...$roles
        );

        $row = $wpdb->get_row( $sql );

        if ( $row ) {
            $row->clock_in_methods  = json_decode( $row->clock_in_methods, true ) ?: [];
            $row->clock_out_methods = json_decode( $row->clock_out_methods, true ) ?: [];
        }

        self::$cache[ $employee_id ] = $row ?: null;

        return self::$cache[ $employee_id ];
    }

    // =========================================================================
    // Validation helpers (called from punch handler)
    // =========================================================================

    /**
     * Check whether a punch method is allowed for an employee.
     *
     * @param int    $employee_id
     * @param string $punch_type   'in', 'out', 'break_start', 'break_end'
     * @param string $source       'kiosk', 'self_web', etc.
     * @return true|\WP_Error  True if allowed, WP_Error if blocked.
     */
    public static function validate_method( int $employee_id, string $punch_type, string $source ): mixed {
        $policy = self::get_policy_for_employee( $employee_id );

        // No policy → fall through to default behaviour
        if ( ! $policy ) {
            return true;
        }

        if ( $punch_type === 'in' ) {
            $allowed = $policy->clock_in_methods;
        } elseif ( $punch_type === 'out' ) {
            $allowed = $policy->clock_out_methods;
        } else {
            // break_start / break_end follow clock-in method rules
            $allowed = $policy->clock_in_methods;
        }

        if ( ! in_array( $source, $allowed, true ) ) {
            $method_label = $source === 'kiosk' ? __( 'Kiosk', 'sfs-hr' ) : __( 'Self Web', 'sfs-hr' );
            $action_label = $punch_type === 'in' ? __( 'Clock-in', 'sfs-hr' ) : __( 'Clock-out', 'sfs-hr' );

            return new \WP_Error(
                'method_not_allowed',
                sprintf(
                    /* translators: 1: action (Clock-in/Clock-out), 2: method (Kiosk/Self Web) */
                    __( '%1$s via %2$s is not allowed by your attendance policy.', 'sfs-hr' ),
                    $action_label,
                    $method_label
                ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    /**
     * Should geofence be enforced for this punch?
     *
     * @param int    $employee_id
     * @param string $punch_type  'in' or 'out'
     * @return bool  True = enforce geofence (default). False = skip geofence.
     */
    public static function should_enforce_geofence( int $employee_id, string $punch_type ): bool {
        $policy = self::get_policy_for_employee( $employee_id );

        // No policy → enforce (default behaviour)
        if ( ! $policy ) {
            return true;
        }

        if ( $punch_type === 'in' || $punch_type === 'break_start' || $punch_type === 'break_end' ) {
            return $policy->clock_in_geofence === 'enforced';
        }

        // out
        return $policy->clock_out_geofence === 'enforced';
    }

    /**
     * Should department web-punch restriction be bypassed?
     *
     * When a role-based policy explicitly allows self_web, department-level
     * web-punch blocking should not apply.
     *
     * @param int    $employee_id
     * @param string $punch_type  'in', 'out', etc.
     * @param string $source      'self_web', 'kiosk', etc.
     * @return bool  True = bypass department restriction.
     */
    public static function should_bypass_dept_web_block( int $employee_id, string $punch_type, string $source ): bool {
        if ( $source !== 'self_web' ) {
            return false;
        }

        $policy = self::get_policy_for_employee( $employee_id );

        if ( ! $policy ) {
            return false;
        }

        $methods = ( $punch_type === 'out' ) ? $policy->clock_out_methods : $policy->clock_in_methods;

        return in_array( 'self_web', $methods, true );
    }

    /**
     * Is this employee under a total-hours calculation policy?
     *
     * @param int $employee_id
     * @return bool
     */
    public static function is_total_hours_mode( int $employee_id ): bool {
        $policy = self::get_policy_for_employee( $employee_id );

        return $policy && $policy->calculation_mode === 'total_hours';
    }

    /**
     * Get the target hours for total-hours mode.
     *
     * @param int $employee_id
     * @return float  Target hours, defaults to 8.0
     */
    public static function get_target_hours( int $employee_id ): float {
        $policy = self::get_policy_for_employee( $employee_id );

        if ( $policy && $policy->target_hours ) {
            return (float) $policy->target_hours;
        }

        return 8.0;
    }

    /**
     * Get break settings for an employee's policy.
     *
     * @param int $employee_id
     * @return array{enabled: bool, duration_minutes: int}
     */
    public static function get_break_settings( int $employee_id ): array {
        $policy = self::get_policy_for_employee( $employee_id );

        if ( ! $policy ) {
            return [ 'enabled' => false, 'duration_minutes' => 0 ];
        }

        return [
            'enabled'          => (bool) $policy->breaks_enabled,
            'duration_minutes' => (int) ( $policy->break_duration_minutes ?: 0 ),
        ];
    }

    // =========================================================================
    // Admin helpers
    // =========================================================================

    /**
     * Get all policies with their assigned roles.
     *
     * @return array
     */
    public static function get_all_policies(): array {
        global $wpdb;

        $policies = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_attendance_policies ORDER BY name ASC"
        );

        foreach ( $policies as &$p ) {
            $p->clock_in_methods  = json_decode( $p->clock_in_methods, true ) ?: [];
            $p->clock_out_methods = json_decode( $p->clock_out_methods, true ) ?: [];
            $p->roles = $wpdb->get_col( $wpdb->prepare(
                "SELECT role_slug FROM {$wpdb->prefix}sfs_hr_attendance_policy_roles WHERE policy_id = %d",
                $p->id
            ) );
        }

        return $policies;
    }

    /**
     * Get a single policy by ID.
     *
     * @param int $policy_id
     * @return object|null
     */
    public static function get_policy( int $policy_id ): ?object {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_attendance_policies WHERE id = %d",
            $policy_id
        ) );

        if ( ! $row ) {
            return null;
        }

        $row->clock_in_methods  = json_decode( $row->clock_in_methods, true ) ?: [];
        $row->clock_out_methods = json_decode( $row->clock_out_methods, true ) ?: [];
        $row->roles = $wpdb->get_col( $wpdb->prepare(
            "SELECT role_slug FROM {$wpdb->prefix}sfs_hr_attendance_policy_roles WHERE policy_id = %d",
            $row->id
        ) );

        return $row;
    }

    /**
     * Save (create or update) a policy.
     *
     * @param array $data
     * @return int|false  Policy ID on success, false on failure.
     */
    public static function save_policy( array $data ) {
        global $wpdb;

        $table       = $wpdb->prefix . 'sfs_hr_attendance_policies';
        $roles_table = $wpdb->prefix . 'sfs_hr_attendance_policy_roles';
        $now         = current_time( 'mysql' );

        $fields = [
            'name'                   => sanitize_text_field( $data['name'] ?? '' ),
            'clock_in_methods'       => wp_json_encode( $data['clock_in_methods'] ?? [ 'kiosk', 'self_web' ] ),
            'clock_out_methods'      => wp_json_encode( $data['clock_out_methods'] ?? [ 'kiosk', 'self_web' ] ),
            'clock_in_geofence'      => in_array( $data['clock_in_geofence'] ?? '', [ 'enforced', 'none' ] ) ? $data['clock_in_geofence'] : 'enforced',
            'clock_out_geofence'     => in_array( $data['clock_out_geofence'] ?? '', [ 'enforced', 'none' ] ) ? $data['clock_out_geofence'] : 'enforced',
            'calculation_mode'       => in_array( $data['calculation_mode'] ?? '', [ 'shift_times', 'total_hours' ] ) ? $data['calculation_mode'] : 'shift_times',
            'target_hours'           => isset( $data['target_hours'] ) && $data['target_hours'] !== '' ? (float) $data['target_hours'] : null,
            'breaks_enabled'         => ! empty( $data['breaks_enabled'] ) ? 1 : 0,
            'break_duration_minutes' => isset( $data['break_duration_minutes'] ) && $data['break_duration_minutes'] !== '' ? (int) $data['break_duration_minutes'] : null,
            'active'                 => isset( $data['active'] ) ? (int) $data['active'] : 1,
            'updated_at'             => $now,
        ];

        $id = isset( $data['id'] ) ? (int) $data['id'] : 0;

        if ( $id > 0 ) {
            $wpdb->update( $table, $fields, [ 'id' => $id ] );
        } else {
            $fields['created_at'] = $now;
            $wpdb->insert( $table, $fields );
            $id = (int) $wpdb->insert_id;
        }

        if ( ! $id ) {
            return false;
        }

        // Sync roles
        $wpdb->delete( $roles_table, [ 'policy_id' => $id ] );

        $roles = $data['roles'] ?? [];
        if ( is_array( $roles ) ) {
            foreach ( $roles as $role_slug ) {
                $slug = sanitize_key( $role_slug );
                if ( $slug ) {
                    $wpdb->insert( $roles_table, [
                        'policy_id' => $id,
                        'role_slug' => $slug,
                    ] );
                }
            }
        }

        // Clear runtime cache
        self::$cache = [];

        return $id;
    }

    /**
     * Delete a policy and its role mappings.
     *
     * @param int $policy_id
     * @return bool
     */
    public static function delete_policy( int $policy_id ): bool {
        global $wpdb;

        $wpdb->delete( $wpdb->prefix . 'sfs_hr_attendance_policy_roles', [ 'policy_id' => $policy_id ] );
        $deleted = $wpdb->delete( $wpdb->prefix . 'sfs_hr_attendance_policies', [ 'id' => $policy_id ] );

        self::$cache = [];

        return (bool) $deleted;
    }
}
