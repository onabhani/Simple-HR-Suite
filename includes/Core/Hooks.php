<?php
namespace SFS\HR\Core;
if ( ! defined('ABSPATH') ) { exit; }

class Hooks {
    public function hooks(): void {
        add_action('user_register', [$this, 'ensure_employee_for_user']);
        add_action('wp_login', [$this, 'ensure_employee_on_login'], 10, 2);
        add_action('profile_update', [$this, 'sync_employee_email_on_profile_update'], 10, 2);
        add_action('admin_notices', [Helpers::class, 'render_admin_notice_bar']);
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
                'employees',
                $employee_id,
                'update',
                [
                    'email' => [
                        'old' => $old_user_data->user_email,
                        'new' => $user->user_email,
                    ],
                ],
                $user_id
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
