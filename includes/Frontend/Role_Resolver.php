<?php
/**
 * Role Resolver — detects the effective HR portal role for a user.
 *
 * Evaluation order: admin → gm → hr → manager → employee → trainee → none
 * The first match wins. WordPress administrators are always resolved as 'admin'
 * (highest portal level) so they never lose Organization/System sidebar sections.
 *
 * @package SFS\HR\Frontend
 */

namespace SFS\HR\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Role_Resolver {

    /**
     * Resolve the effective HR portal role for a user.
     *
     * Evaluation order (first match wins): admin → gm → hr → manager → employee → trainee → none.
     * WordPress administrators are checked first because admin has the highest portal
     * level (60) and broadest access — without this, an admin who is also assigned as
     * a department manager or HR responsible would lose Organization and System sections.
     *
     * @param int $user_id WordPress user ID.
     * @return string One of: admin, gm, hr, manager, employee, trainee, none.
     */
    public static function resolve( int $user_id ): string {
        if ( ! $user_id ) {
            return 'none';
        }

        // Admin: WordPress administrator — checked first because admin role (level 60)
        // has the broadest portal access. Without this priority, an admin who is also
        // a department manager would be resolved as 'manager' and lose Organization/System sections.
        if ( user_can( $user_id, 'manage_options' ) ) {
            return 'admin';
        }

        // GM: configured as the General Manager approver.
        $gm_user_id = (int) get_option( 'sfs_hr_leave_gm_approver', 0 );
        if ( $gm_user_id > 0 && $gm_user_id === $user_id ) {
            return 'gm';
        }

        // HR: has the sfs_hr_manager role, is in HR approvers list, or is hr_responsible.
        // Note: checking role/assignment — NOT sfs_hr.manage capability (admins inherit that).
        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $user_obj    = get_userdata( $user_id );
        $has_hr_role = $user_obj && in_array( 'sfs_hr_manager', (array) $user_obj->roles, true );

        $hr_approvers   = array_map( 'intval', (array) get_option( 'sfs_hr_leave_hr_approvers', [] ) );
        $is_hr_approver = in_array( $user_id, $hr_approvers, true );

        $is_hr_responsible = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$dept_table} WHERE hr_responsible_user_id = %d AND active = 1",
            $user_id
        ) ) > 0;

        if ( $has_hr_role || $is_hr_approver || $is_hr_responsible ) {
            return 'hr';
        }

        // Department Manager: assigned as manager in any active department.
        $is_mgr = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$dept_table} WHERE manager_user_id = %d AND active = 1",
            $user_id
        ) );
        if ( $is_mgr > 0 ) {
            return 'manager';
        }

        // Employee: has an employee record (any status — terminated employees still get employee role).
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $is_emp    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$emp_table} WHERE user_id = %d",
            $user_id
        ) );
        if ( $is_emp > 0 ) {
            return 'employee';
        }

        // Trainee: active trainee record.
        $trainee_table = $wpdb->prefix . 'sfs_hr_trainees';
        $is_trainee    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$trainee_table} WHERE user_id = %d AND status = 'active'",
            $user_id
        ) );
        if ( $is_trainee > 0 ) {
            return 'trainee';
        }

        return 'none';
    }

    /**
     * Get department IDs managed by a user.
     *
     * @param int $user_id WordPress user ID.
     * @return int[] Array of department IDs.
     */
    public static function get_manager_dept_ids( int $user_id ): array {
        if ( ! $user_id ) {
            return [];
        }

        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $ids        = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$dept_table} WHERE manager_user_id = %d AND active = 1",
            $user_id
        ) );

        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * Role hierarchy level (higher = more access).
     *
     * @param string $role Role name.
     * @return int Hierarchy level.
     */
    public static function role_level( string $role ): int {
        $levels = [
            'admin'    => 60,
            'gm'       => 50,
            'hr'       => 40,
            'manager'  => 30,
            'employee' => 20,
            'trainee'  => 10,
            'none'     => 0,
        ];
        return $levels[ $role ] ?? 0;
    }
}
