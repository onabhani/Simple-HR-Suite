<?php
namespace SFS\HR\Core;
if (!defined('ABSPATH')) { exit; }

class Capabilities {
    private static $filter_added = false;

    public static function ensure_roles_caps(): void {
        // --- Base roles (kept minimal) ---
        if (!get_role('sfs_hr_employee')) {
            add_role('sfs_hr_employee', __('HR Employee','sfs-hr'), ['read' => true]);
        }
        if (!get_role('sfs_hr_manager')) {
            add_role('sfs_hr_manager', __('HR Manager','sfs-hr'), ['read' => true]);
        }

        // Static caps for our roles
        $caps_manage = [
            'sfs_hr.view'           => true, // can see HR menu (read-only areas)
            'sfs_hr.manage'         => true, // manage employees, settings
            'sfs_hr.leave.manage'   => true, // leave settings/types
            'sfs_hr.leave.review'   => true, // approve/reject
        ];
        $caps_employee = [
            'sfs_hr.leave.request'  => true, // submit requests
        ];

        if ($mgr = get_role('sfs_hr_manager')) {
            foreach ($caps_manage + $caps_employee as $k=>$v) { $mgr->add_cap($k, $v); }
        }
        if ($emp = get_role('sfs_hr_employee')) {
            foreach ($caps_employee as $k=>$v) { $emp->add_cap($k, $v); }
        }
        if ($adm = get_role('administrator')) {
            foreach ($caps_manage + $caps_employee as $k=>$v) { $adm->add_cap($k, $v); }
        }

        // --- Dynamic grants (no need to create special roles) ---
        if (!self::$filter_added) {
            add_filter('user_has_cap', [__CLASS__, 'dynamic_caps'], 10, 4);
            self::$filter_added = true;
        }
    }

    /**
     * Dynamically grant:
     *  - sfs_hr.leave.request to any user that has an active employee row
     *  - sfs_hr.view + sfs_hr.leave.review to department managers (mapped in sfs_hr_departments)
     *  - All HR capabilities to administrators (manage_options)
     */
    public static function dynamic_caps(array $allcaps, array $caps, array $args, \WP_User $user): array {
        if (empty($user->ID)) return $allcaps;

        global $wpdb;
        $uid = (int)$user->ID;

        // Administrators get all HR capabilities (fallback if static caps weren't properly assigned)
        if ( ! empty( $allcaps['manage_options'] ) ) {
            $allcaps['sfs_hr.view']           = true;
            $allcaps['sfs_hr.manage']         = true;
            $allcaps['sfs_hr.leave.manage']   = true;
            $allcaps['sfs_hr.leave.review']   = true;
            $allcaps['sfs_hr.leave.request']  = true;
            $allcaps['sfs_hr_loans_finance_approve'] = true;
            $allcaps['sfs_hr_loans_gm_approve']      = true;
        }

        // If an active employee row exists -> can request leave
        $emp_tbl = $wpdb->prefix . 'sfs_hr_employees';
        $has_emp = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$emp_tbl` WHERE user_id=%d AND status='active'",
            $uid
        ));
        if ($has_emp > 0) {
            $allcaps['sfs_hr.leave.request'] = true;
        }

        // If mapped as a department manager -> can view HR + review leaves
        $dept_tbl = $wpdb->prefix . 'sfs_hr_departments';
        $is_mgr = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$dept_tbl` WHERE manager_user_id=%d AND active=1",
            $uid
        ));
        if ($is_mgr > 0) {
            $allcaps['sfs_hr.view']         = true; // shows HR menu if your menu uses this cap
            $allcaps['sfs_hr.leave.review'] = true;
            // Deliberately NOT granting sfs_hr.manage here (that's for HR admins).
        }

        return $allcaps;
    }
}
