<?php
/**
 * Role Resolver — detects the effective HR portal role for a user.
 *
 * Evaluation order: admin → gm → finance → hr → manager → employee → trainee → none
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
     * Per-request static cache for resolve() results.
     * Keyed by user ID, reset on each PHP request.
     *
     * @var array<int, string>
     */
    private static $cache = [];

    /**
     * Resolve the effective HR portal role for a user.
     *
     * Evaluation order (first match wins): admin → gm → finance → hr → manager → employee → trainee → none.
     * WordPress administrators are checked first because admin has the highest portal
     * level (60) and broadest access — without this, an admin who is also assigned as
     * a department manager or HR responsible would lose Organization and System sections.
     *
     * Results are cached in a static property for the duration of the current PHP request
     * to avoid repeated DB queries on portal pages that call resolve() multiple times.
     *
     * @param int $user_id WordPress user ID.
     * @return string One of: admin, gm, finance, hr, manager, employee, trainee, none.
     */
    public static function resolve( int $user_id ): string {
        if ( ! $user_id ) {
            return 'none';
        }

        if ( isset( self::$cache[ $user_id ] ) ) {
            return self::$cache[ $user_id ];
        }

        // Admin: WordPress administrator — checked first because admin role (level 60)
        // has the broadest portal access. Without this priority, an admin who is also
        // a department manager would be resolved as 'manager' and lose Organization/System sections.
        if ( user_can( $user_id, 'manage_options' ) ) {
            self::$cache[ $user_id ] = 'admin';
            return 'admin';
        }

        // GM: configured as the General Manager approver.
        $gm_user_id = (int) get_option( 'sfs_hr_leave_gm_approver', 0 );
        if ( $gm_user_id > 0 && $gm_user_id === $user_id ) {
            self::$cache[ $user_id ] = 'gm';
            return 'gm';
        }

        // Fetch user data once — reused by finance and HR checks below.
        $user_obj = get_userdata( $user_id );

        // Finance: designated leave finance approver or has the sfs_hr_finance_approver role.
        $finance_approver_id = (int) get_option( 'sfs_hr_leave_finance_approver', 0 );
        if ( $finance_approver_id > 0 && $finance_approver_id === $user_id ) {
            self::$cache[ $user_id ] = 'finance';
            return 'finance';
        }
        if ( $user_obj && in_array( 'sfs_hr_finance_approver', (array) $user_obj->roles, true ) ) {
            self::$cache[ $user_id ] = 'finance';
            return 'finance';
        }

        // HR: has the sfs_hr_manager role, is in HR approvers list, or is hr_responsible.
        // Note: checking role/assignment — NOT sfs_hr.manage capability (admins inherit that).
        // The checks live in has_hr_assignment() so is_hr() can ask a related question
        // without the manage_options short-circuit above. is_hr() uses the narrower
        // has_hr_approver_assignment(): hr_responsible_user_id is a portal signal, not a
        // leave approval one. All three stay in force here.
        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        if ( self::has_hr_assignment( $user_id, $user_obj ) ) {
            self::$cache[ $user_id ] = 'hr';
            return 'hr';
        }

        // Department Manager: assigned as manager in any active department.
        $is_mgr = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$dept_table} WHERE manager_user_id = %d AND active = 1",
            $user_id
        ) );
        if ( $is_mgr > 0 ) {
            self::$cache[ $user_id ] = 'manager';
            return 'manager';
        }

        // Employee: has an employee record (any status — terminated employees still get employee role).
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $is_emp    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$emp_table} WHERE user_id = %d",
            $user_id
        ) );
        if ( $is_emp > 0 ) {
            self::$cache[ $user_id ] = 'employee';
            return 'employee';
        }

        // Trainee: active trainee record.
        $trainee_table = $wpdb->prefix . 'sfs_hr_trainees';
        $is_trainee    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$trainee_table} WHERE user_id = %d AND status = 'active'",
            $user_id
        ) );
        if ( $is_trainee > 0 ) {
            self::$cache[ $user_id ] = 'trainee';
            return 'trainee';
        }

        self::$cache[ $user_id ] = 'none';
        return 'none';
    }

    /**
     * Whether a user is explicitly assigned as HR.
     *
     * This answers a different question from resolve(): not "which portal role does
     * this user get?" but "has this user deliberately been put in an HR position?".
     * resolve() cannot answer it, because its documented admin-first ordering returns
     * 'admin' for every user holding manage_options before the HR branch is reached,
     * and that ordering has to stay exactly as it is because it drives portal
     * navigation. Both functions are correct for their own question.
     *
     * The signals are the two that actually confer leave approval authority, through
     * the shared has_hr_approver_assignment() helper: the sfs_hr_manager role, or
     * membership of the sfs_hr_leave_hr_approvers option. Neither is a capability test,
     * so being a WordPress administrator is never sufficient on its own: an
     * administrator satisfies this only when somebody assigned them.
     *
     * hr_responsible_user_id is deliberately NOT one of them, even though resolve()'s
     * HR branch counts it. That column is the per-department HR contact for performance
     * justifications, and Capabilities::dynamic_caps() grants the person holding it only
     * sfs_hr.view and sfs_hr_performance_view, never sfs_hr.leave.review. A
     * non-administrator holding it therefore cannot reach a leave approval handler at
     * all. Honouring it here would let an administrator who is the HR contact of one
     * department approve leave for every other department, so the signal could only ever
     * benefit administrators: exactly the asymmetry this predicate exists to prevent.
     *
     * Deliberately does not read or write self::$cache. That cache holds resolve()
     * strings keyed by user ID, and the two functions return different answers for the
     * same user, so sharing it would corrupt whichever ran second.
     *
     * @param int $user_id WordPress user ID.
     * @return bool True when the user holds an explicit HR assignment.
     */
    public static function is_hr( int $user_id ): bool {
        if ( ! $user_id ) {
            return false;
        }

        return self::has_hr_approver_assignment( $user_id, get_userdata( $user_id ) );
    }

    /**
     * The narrow HR test: assignments that actually confer leave approval authority.
     *
     * The sfs_hr_manager role or membership of the sfs_hr_leave_hr_approvers option.
     * Role and assignment only, never the sfs_hr.manage capability, which administrators
     * inherit. Short-circuits on the first match; both checks are in memory.
     *
     * Used directly by is_hr(), and by has_hr_assignment() as its first two signals, so
     * the two cannot drift: a signal added here reaches both callers, while
     * has_hr_assignment() adds the one signal that is portal-only. That containment is
     * why this is a pair of named functions rather than one function with a boolean
     * argument: the call sites cannot silently pick the wrong view, and the wide view is
     * defined as the narrow view plus a stated extra rather than as a parallel copy.
     *
     * @param int                $user_id  WordPress user ID.
     * @param \WP_User|false|null $user_obj Already fetched user object, or a falsy
     *                                     value when the role test should be skipped.
     * @return bool
     */
    private static function has_hr_approver_assignment( int $user_id, $user_obj ): bool {
        if ( $user_obj && in_array( 'sfs_hr_manager', (array) $user_obj->roles, true ) ) {
            return true;
        }

        $hr_approvers = array_map( 'intval', (array) get_option( 'sfs_hr_leave_hr_approvers', [] ) );

        return in_array( $user_id, $hr_approvers, true );
    }

    /**
     * The wide HR test used by resolve()'s HR branch: approver assignment, or being an
     * active department's hr_responsible_user_id.
     *
     * The third signal is right for resolve(), whose job is to pick a portal role and
     * give the per-department HR contact the HR portal view. It is wrong for is_hr(),
     * which answers an authorization question; see that method for why.
     *
     * @param int                $user_id  WordPress user ID.
     * @param \WP_User|false|null $user_obj Already fetched user object, or a falsy
     *                                     value when the role test should be skipped.
     * @return bool
     */
    private static function has_hr_assignment( int $user_id, $user_obj ): bool {
        if ( self::has_hr_approver_assignment( $user_id, $user_obj ) ) {
            return true;
        }

        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$dept_table} WHERE hr_responsible_user_id = %d AND active = 1",
            $user_id
        ) ) > 0;
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
            'finance'  => 35,
            'manager'  => 30,
            'employee' => 20,
            'trainee'  => 10,
            'none'     => 0,
        ];
        return $levels[ $role ] ?? 0;
    }
}
