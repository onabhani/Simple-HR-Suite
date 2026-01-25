<?php
namespace SFS\HR\Core;
if ( ! defined('ABSPATH') ) { exit; }

class Hooks {
    /** Role slug for terminated employees */
    const TERMINATED_ROLE = 'sfs_hr_terminated';

    public function hooks(): void {
        add_action('user_register', [$this, 'ensure_employee_for_user']);
        add_action('wp_login', [$this, 'ensure_employee_on_login'], 10, 2);
        add_action('profile_update', [$this, 'sync_employee_email_on_profile_update'], 10, 2);
        add_action('admin_notices', [Helpers::class, 'render_admin_notice_bar']);

        // Register terminated employee role
        add_action('init', [$this, 'register_terminated_role']);

        // Block login for terminated employees
        add_filter('authenticate', [$this, 'block_terminated_employee_login'], 30, 3);

        // Show notice on login page for blocked users
        add_filter('login_message', [$this, 'terminated_login_message']);
    }

    /**
     * Register the "Terminated Employee" role with zero capabilities
     */
    public function register_terminated_role(): void {
        if (!get_role(self::TERMINATED_ROLE)) {
            add_role(
                self::TERMINATED_ROLE,
                __('Terminated Employee', 'sfs-hr'),
                [] // Zero capabilities
            );
        }
    }

    /**
     * Block login for terminated employees
     *
     * Only blocks login if the employee's last working day has passed.
     * Employees with pending resignations can still access until their final exit date.
     *
     * @param \WP_User|\WP_Error|null $user
     * @param string $username
     * @param string $password
     * @return \WP_User|\WP_Error|null
     */
    public function block_terminated_employee_login($user, $username, $password) {
        // Only check if we have a valid user
        if (!($user instanceof \WP_User)) {
            return $user;
        }

        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';
        $today = current_time('Y-m-d');

        // Get employee info
        $employee = $wpdb->get_row(
            $wpdb->prepare("SELECT id, status FROM {$emp_table} WHERE user_id = %d LIMIT 1", $user->ID),
            ARRAY_A
        );

        if (!$employee) {
            return $user; // No employee record, allow login
        }

        // Check if user has terminated role OR terminated status
        $is_terminated = in_array(self::TERMINATED_ROLE, (array) $user->roles, true)
            || $employee['status'] === 'terminated';

        if (!$is_terminated) {
            return $user; // Not terminated, allow login
        }

        // Check if there's an approved resignation with last_working_day still in the future or today
        $last_working_day = $wpdb->get_var($wpdb->prepare(
            "SELECT last_working_day FROM {$resign_table}
             WHERE employee_id = %d AND status = 'approved'
             ORDER BY last_working_day DESC LIMIT 1",
            $employee['id']
        ));

        // Allow access if last_working_day hasn't passed yet (today or future)
        if ($last_working_day && $last_working_day >= $today) {
            return $user;
        }

        // Last working day has passed (or no resignation record) - block login
        // Ensure user has terminated role for consistency
        if (!in_array(self::TERMINATED_ROLE, (array) $user->roles, true)) {
            self::demote_to_terminated_role($user->ID);
        }

        return new \WP_Error(
            'terminated_employee',
            __('Your employment has been terminated. Please contact HR for assistance.', 'sfs-hr')
        );
    }

    /**
     * Show message on login page if blocked
     *
     * @param string $message
     * @return string
     */
    public function terminated_login_message($message): string {
        if (isset($_GET['terminated'])) {
            $message .= '<div id="login_error">' . esc_html__('Your employment has been terminated. Please contact HR for assistance.', 'sfs-hr') . '</div>';
        }
        return $message;
    }

    /**
     * Demote a user to terminated role (removes all other roles)
     *
     * Only demotes if the employee's last working day has passed.
     *
     * @param int $user_id WordPress user ID
     * @param bool $force Force demotion even if last working day hasn't passed
     * @return bool True if successful
     */
    public static function demote_to_terminated_role(int $user_id, bool $force = false): bool {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        // Don't demote administrators
        if (in_array('administrator', (array) $user->roles, true)) {
            return false;
        }

        // Check if last working day has passed (unless forced)
        if (!$force) {
            global $wpdb;
            $emp_table = $wpdb->prefix . 'sfs_hr_employees';
            $resign_table = $wpdb->prefix . 'sfs_hr_resignations';
            $today = current_time('Y-m-d');

            $employee_id = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$emp_table} WHERE user_id = %d LIMIT 1", $user_id)
            );

            if ($employee_id) {
                $last_working_day = $wpdb->get_var($wpdb->prepare(
                    "SELECT last_working_day FROM {$resign_table}
                     WHERE employee_id = %d AND status = 'approved'
                     ORDER BY last_working_day DESC LIMIT 1",
                    $employee_id
                ));

                // Don't demote if last working day hasn't passed yet
                if ($last_working_day && $last_working_day >= $today) {
                    return false;
                }
            }
        }

        // Remove all existing roles and set to terminated
        $user->set_role(self::TERMINATED_ROLE);

        // Log the role change
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            AuditTrail::log(
                'role_change',
                'user',
                $user_id,
                null,
                null,
                [
                    'action' => 'demoted_to_terminated',
                    'new_role' => self::TERMINATED_ROLE,
                ],
                'User demoted to terminated role'
            );
        }

        return true;
    }

    /**
     * Restore a user's role (e.g., if termination was reversed)
     *
     * @param int $user_id WordPress user ID
     * @param string $new_role Role to restore to (default: subscriber)
     * @return bool True if successful
     */
    public static function restore_user_role(int $user_id, string $new_role = 'subscriber'): bool {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        // Only restore if currently terminated
        if (!in_array(self::TERMINATED_ROLE, (array) $user->roles, true)) {
            return false;
        }

        $user->set_role($new_role);

        // Log the role change
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            AuditTrail::log(
                'role_change',
                'user',
                $user_id,
                null,
                ['role' => self::TERMINATED_ROLE],
                ['role' => $new_role],
                'User role restored from terminated to ' . $new_role
            );
        }

        return true;
    }

    public function ensure_employee_for_user(int $user_id): void { $this->ensure($user_id); }
    public function ensure_employee_on_login(string $user_login, \WP_User $user): void { $this->ensure((int)$user->ID); }

    /**
     * Sync employee email when WordPress user profile is updated.
     *
     * @param int      $user_id       The user ID.
     * @param \WP_User $old_user_data The old user data before update.
     */
    public function sync_employee_email_on_profile_update(int $user_id, \WP_User $old_user_data): void {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        // Check if email actually changed
        if ($user->user_email === $old_user_data->user_email) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';

        // Find the linked employee record
        $employee_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id)
        );

        if ($employee_id <= 0) {
            return;
        }

        // Update the employee email
        $wpdb->update(
            $table,
            [
                'email'      => sanitize_email($user->user_email),
                'updated_at' => Helpers::now_mysql(),
            ],
            ['id' => $employee_id],
            ['%s', '%s'],
            ['%d']
        );

        // Log the change via audit trail if available
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            AuditTrail::log(
                'update',
                'employee',
                $employee_id,
                null,
                ['email' => $old_user_data->user_email],
                ['email' => $user->user_email],
                'Employee email synced from WordPress profile'
            );
        }
    }

    private function ensure(int $user_id): void {
        if ($user_id <= 0) return;
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d LIMIT 1", $user_id));
        if ($exists) return;
        $u = get_userdata($user_id);
        if ( ! $u ) return;
        $first = get_user_meta($user_id, 'first_name', true);
        $last  = get_user_meta($user_id, 'last_name', true);
        if (!$first && !$last) {
            $dn = trim($u->display_name);
            if (strpos($dn, ' ') !== false) { [$first, $last] = explode(' ', $dn, 2); }
            else { $first = $dn ?: $u->user_login; }
        }
        $code = 'USR-' . $user_id;
        $wpdb->insert($table, [
            'user_id'       => $user_id,
            'employee_code' => sanitize_text_field($code),
            'first_name'    => sanitize_text_field($first),
            'last_name'     => sanitize_text_field($last),
            'email'         => sanitize_email($u->user_email),
            'status'        => 'active',
            'created_at'    => Helpers::now_mysql(),
            'updated_at'    => Helpers::now_mysql(),
        ], ['%d','%s','%s','%s','%s','%s','%s']);
    }
}
