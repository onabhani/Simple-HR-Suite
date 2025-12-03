<?php
namespace SFS\HR\Core;
if ( ! defined('ABSPATH') ) { exit; }

class Hooks {
    public function hooks(): void {
        add_action('user_register', [$this, 'ensure_employee_for_user']);
        add_action('wp_login', [$this, 'ensure_employee_on_login'], 10, 2);
        add_action('admin_notices', [Helpers::class, 'render_admin_notice_bar']);
    }
    public function ensure_employee_for_user(int $user_id): void { $this->ensure($user_id); }
    public function ensure_employee_on_login(string $user_login, \WP_User $user): void { $this->ensure((int)$user->ID); }
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
