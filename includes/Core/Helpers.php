<?php
namespace SFS\HR\Core;
if ( ! defined('ABSPATH') ) { exit; }

class Helpers {

    /** Current WP-local time in MySQL DATETIME. */
    public static function now_mysql(): string {
        return current_time('mysql');
    }

    /** Capability gate with admin-friendly error. */
    public static function require_cap(string $cap) : void {
        if ( ! current_user_can($cap) ) {
            wp_die( esc_html__('Permission denied','sfs-hr') );
        }
    }

    /** Get the current logged-in user's employee id (active only), cached per request. */
    public static function current_employee_id(): ?int {
        if ( ! is_user_logged_in() ) return null;
        static $cache = null;
        if ($cache !== null) return $cache ?: null;

        global $wpdb;
        $table   = $wpdb->prefix . 'sfs_hr_employees';
        $user_id = get_current_user_id();
        $id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE user_id = %d AND status = 'active' LIMIT 1", $user_id)
        );
        $cache = $id ? (int)$id : 0;
        return $cache ?: null;
    }

    /** Get the current logged-in user's employee id (any status), cached per request. */
    public static function current_employee_id_any_status(): ?int {
        if ( ! is_user_logged_in() ) return null;
        static $cache = null;
        if ($cache !== null) return $cache ?: null;

        global $wpdb;
        $table   = $wpdb->prefix . 'sfs_hr_employees';
        $user_id = get_current_user_id();
        $id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id)
        );
        $cache = $id ? (int)$id : 0;
        return $cache ?: null;
    }

    /** Fetch an employee row by id (assoc array) or null. */
    public static function get_employee_row(?int $employee_id): ?array {
        if (!$employee_id) return null;
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", $employee_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Check if an employee can be terminated.
     * Returns true if termination is allowed, false if they have an approved resignation
     * with a last_working_day that hasn't passed yet.
     *
     * @param int $employee_id Employee ID
     * @return bool True if can be terminated, false if must wait for last_working_day
     */
    public static function can_terminate_employee(int $employee_id): bool {
        global $wpdb;
        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';
        $today = current_time('Y-m-d');

        $last_working_day = $wpdb->get_var($wpdb->prepare(
            "SELECT last_working_day FROM {$resign_table}
             WHERE employee_id = %d AND status = 'approved'
             ORDER BY last_working_day DESC LIMIT 1",
            $employee_id
        ));

        // Can terminate if no approved resignation or last_working_day has passed
        return !$last_working_day || $last_working_day < $today;
    }

    public static function esc_html_if($v){ return $v !== null ? esc_html($v) : ''; }
    public static function esc_attr_if($v){ return $v !== null ? esc_attr($v) : ''; }

    /**
     * Send HTML email(s) with UTF-8 headers.
     * - $to may be string or array; invalid/empty addresses are skipped.
     * - $message is sanitized via wp_kses_post() and wpautop().
     */
    public static function send_mail($to, string $subject, string $message, array $extra_headers = []) : void {
        if (empty($to)) return;

        // Normalize recipients
        $recipients = is_array($to) ? $to : [$to];
        $recipients = array_unique(array_filter(array_map('sanitize_email', $recipients)));
        if (!$recipients) return;

        // Build headers (ensure HTML)
        $headers = array_merge(
            ['Content-Type: text/html; charset=UTF-8'],
            array_map('sanitize_text_field', $extra_headers)
        );

        // Sanitize subject/body
        $subject = wp_specialchars_decode( wp_strip_all_tags($subject), ENT_QUOTES );
        $body    = wpautop( wp_kses_post($message) );

        foreach ($recipients as $email) {
            if ($email) {
                // Ignore boolean result; leave delivery responsibility to site mailer config
                wp_mail($email, $subject, $body, $headers);
            }
        }
    }
    
        /**
     * Central redirect helper with HR notice.
     *
     * @param string $url   Base URL (e.g. admin_url('admin.php?page=sfs-hr-employees')).
     * @param string $type  success|error|warning|info
     * @param string $msg   Optional human-readable message.
     */
    public static function redirect_with_notice( string $url, string $type = 'success', string $msg = '' ): void {
        $type = in_array( $type, [ 'success', 'error', 'warning', 'info' ], true ) ? $type : 'success';

        $args = [ 'sfs_hr_notice' => $type ];
        if ( $msg !== '' ) {
            $args['sfs_hr_msg'] = $msg;
        }

        wp_safe_redirect( add_query_arg( $args, $url ) );
        exit;
    }

    /**
     * Define HR admin pages for nav + breadcrumbs.
     */
    private static function admin_nav_items(): array {
        $base = admin_url( 'admin.php' );

        return [
            'sfs-hr' => [
                'label'  => __( 'HR Dashboard', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr', $base ),
                'parent' => null,
            ],
            'sfs-hr-employees' => [
                'label'  => __( 'Employees', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-employees', $base ),
                'parent' => 'sfs-hr',
            ],
            'sfs-hr-employee-profile' => [
                'label'  => __( 'Employee Profile', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-employee-profile', $base ),
                'parent' => 'sfs-hr-employees',
            ],
            'sfs-hr-my-team' => [
                'label'  => __( 'My Team', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-my-team', $base ),
                'parent' => 'sfs-hr',
            ],
            'sfs-hr-leave' => [
                'label'  => __( 'Leave', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-leave', $base ),
                'parent' => 'sfs-hr',
            ],
            'sfs_hr_attendance' => [
                'label'  => __( 'Attendance', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs_hr_attendance', $base ),
                'parent' => 'sfs-hr',
            ],
            'sfs-hr-workforce-status' => [
                'label'  => __( 'Workforce Status', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-workforce-status', $base ),
                'parent' => 'sfs_hr_attendance',
            ],
            'sfs-hr-departments' => [
                'label'  => __( 'Departments', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-departments', $base ),
                'parent' => 'sfs-hr',
            ],
            'sfs-hr-finance-exit' => [
                'label'  => __( 'Finance & Exit', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-finance-exit', $base ),
                'parent' => 'sfs-hr',
            ],
            // Add more here if you have extra HR pages.
        ];
    }

    /**
     * Top HR nav + breadcrumb line.
     * Call this at the top of each HR admin page.
     */
    public static function render_admin_nav(): void {
        if ( ! is_admin() ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $page === '' ) {
            return;
        }

        $items = self::admin_nav_items();

        // Only show on pages that belong to HR Suite.
        if ( ! isset( $items[ $page ] ) && strpos( $page, 'sfs-hr' ) !== 0 && strpos( $page, 'sfs_hr_attendance' ) !== 0 ) {
            return;
        }

        // Build breadcrumb trail.
        $trail = [];
        $slug  = isset( $items[ $page ] ) ? $page : 'sfs-hr'; // fallback to root
        while ( $slug && isset( $items[ $slug ] ) ) {
            array_unshift( $trail, $items[ $slug ] );
            $slug = $items[ $slug ]['parent'] ?? null;
        }

        static $css_printed = false;
        if ( ! $css_printed ) {
            $css_printed = true;
            echo '<style>
                .sfs-hr-wrap .sfs-hr-nav {margin:12px 0 8px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;}
                .sfs-hr-nav-dashboard {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    text-decoration: none;
                    padding: 6px 12px;
                    border-radius: 4px;
                    background: #2271b1;
                    color: #fff;
                    font-size: 13px;
                    font-weight: 500;
                    transition: background 0.15s ease;
                }
                .sfs-hr-nav-dashboard:hover {
                    background: #135e96;
                    color: #fff;
                }
                .sfs-hr-nav-dashboard .dashicons {
                    font-size: 16px;
                    width: 16px;
                    height: 16px;
                }
                .sfs-hr-nav-breadcrumb {font-size:12px; color:#50575e;}
                .sfs-hr-nav-breadcrumb span + span:before {content:" / "; color:#b0b4b8;}
                .sfs-hr-nav-breadcrumb a {color:#2271b1; text-decoration:none;}
                .sfs-hr-nav-breadcrumb a:hover {text-decoration:underline;}
            </style>';
        }

        echo '<div class="sfs-hr-nav" aria-label="HR navigation">';

        // Dashboard button
        echo '<a class="sfs-hr-nav-dashboard" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr' ) ) . '">';
        echo '<span class="dashicons dashicons-dashboard"></span>';
        echo esc_html__( 'Dashboard', 'sfs-hr' );
        echo '</a>';

        // Breadcrumb trail (skip "HR Dashboard" since we have the button)
        if ( ! empty( $trail ) && count( $trail ) > 1 ) {
            echo '<div class="sfs-hr-nav-breadcrumb">';
            $segments = [];
            foreach ( $trail as $i => $item ) {
                // Skip the first item (HR Dashboard) since we have the button
                if ( $i === 0 ) {
                    continue;
                }
                if ( $i === count( $trail ) - 1 ) {
                    $segments[] = '<span>' . esc_html( $item['label'] ) . '</span>';
                } else {
                    $segments[] = '<span><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a></span>';
                }
            }
            echo implode( '', $segments );
            echo '</div>';
        }

        echo '</div>'; // .sfs-hr-nav
    }

    /**
     * Global notice bar, driven by ?sfs_hr_notice=success|error etc.
     */
    public static function render_admin_notice_bar(): void {
        if ( ! is_admin() ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( $page === '' ) {
            return;
        }

        if ( strpos( $page, 'sfs-hr' ) !== 0 && strpos( $page, 'sfs_hr_attendance' ) !== 0 ) {
            return;
        }

        if ( empty( $_GET['sfs_hr_notice'] ) ) {
            return;
        }

        $type = sanitize_key( wp_unslash( $_GET['sfs_hr_notice'] ) );
        $type = in_array( $type, [ 'success', 'error', 'warning', 'info' ], true ) ? $type : 'success';

        $raw_msg = isset( $_GET['sfs_hr_msg'] ) ? wp_unslash( $_GET['sfs_hr_msg'] ) : '';
        $msg     = $raw_msg !== '' ? sanitize_text_field( $raw_msg ) : (
            $type === 'success'
                ? __( 'Action completed successfully.', 'sfs-hr' )
                : __( 'Action failed. Please try again.', 'sfs-hr' )
        );

        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible sfs-hr-notice"><p>' . esc_html( $msg ) . '</p></div>';
    }

    /**
     * Return departments for selects, keyed by slug.
     *
     * [
     *   'office' => [ 'id' => 1, 'name' => 'Office', 'active' => 1 ],
     *   ...
     * ]
     */
    public static function get_departments_for_select( bool $only_active = true ): array {
        static $cache = [];

        $key = $only_active ? '1' : '0';
        if ( isset( $cache[ $key ] ) ) {
            return $cache[ $key ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_departments';

        $where = $only_active ? 'WHERE active = 1' : '';
        $rows  = $wpdb->get_results(
            "SELECT id, name, active FROM {$table} {$where} ORDER BY id ASC",
            ARRAY_A
        );

        if ( ! $rows ) {
            $cache[ $key ] = [];
            return $cache[ $key ];
        }

        $out = [];
        foreach ( $rows as $r ) {
            $name = isset( $r['name'] ) ? (string) $r['name'] : '';
            if ( $name === '' ) {
                continue;
            }

            $slug = sanitize_title( $name ); // e.g. "Office" => "office"
            $out[ $slug ] = [
                'id'     => (int) $r['id'],
                'name'   => $name,
                'active' => (int) $r['active'],
            ];
        }

        $cache[ $key ] = $out;
        return $out;
    }

public static function asset_status_label( string $status ): string {
    $key = sanitize_key( $status );

    switch ( $key ) {
        case 'pending_employee_approval':
            return __( 'Pending', 'sfs-hr' );
        case 'active':
            return __( 'Active', 'sfs-hr' );
        case 'return_requested':
            return __( 'Return Requested', 'sfs-hr' );
        case 'returned':
            return __( 'Returned', 'sfs-hr' );
        case 'rejected':
            return __( 'Rejected', 'sfs-hr' );
        default:
            return ucfirst( str_replace( '_', ' ', $key ?: 'unknown' ) );
    }
}

/**
 * Output CSS for asset + leave status badges once.
 */
public static function output_asset_status_badge_css(): void {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;

    echo '<style>
        /* ========== Asset status badges ========== */
        .sfs-hr-asset-status {
            display:inline-block;
            padding:2px 8px;
            border-radius:999px;
            font-size:11px;
            line-height:1.6;
            border:1px solid rgba(0,0,0,.05);
        }
        .sfs-hr-asset-status--pending_employee_approval {
            background:#ffb9001a;
            color:#7a5600;
            border-color:#ffb90066;
        }
        .sfs-hr-asset-status--active {
            background:#46b4501a;
            color:#006b1f;
            border-color:#46b45066;
        }
        .sfs-hr-asset-status--return_requested {
            background:#0073aa14;
            color:#004767;
            border-color:#0073aa55;
        }
        .sfs-hr-asset-status--returned {
            background:#ccd0d41a;
            color:#444c55;
            border-color:#ccd0d4;
        }
        .sfs-hr-asset-status--rejected {
            background:#dc32321a;
            color:#8a1414;
            border-color:#dc323266;
        }

        /* ========== Generic status chips (leave status) ========== */
        .sfs-hr-status-chip,
        .sfs-hr-leave-chip {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:2px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:600;
            line-height:1.4;
            white-space:nowrap;
            border:1px solid transparent;
            box-sizing:border-box;
        }

        .sfs-hr-status-chip--gray {
            background-color:#f3f4f6;
            color:#374151;
            border-color:#e5e7eb;
        }

        .sfs-hr-status-chip--green {
            background-color:#ecfdf3;
            color:#166534;
            border-color:#bbf7d0;
        }

        .sfs-hr-status-chip--blue {
            background-color:#eff6ff;
            color:#1d4ed8;
            border-color:#bfdbfe;
        }

        .sfs-hr-status-chip--red {
            background-color:#fef2f2;
            color:#b91c1c;
            border-color:#fecaca;
        }

        .sfs-hr-status-chip--purple {
            background-color:#f5f3ff;
            color:#5b21b6;
            border-color:#ddd6fe;
        }

        .sfs-hr-status-chip--yellow {
            background-color:#fef9c3;
            color:#854d0e;
            border-color:#facc15;
        }

        .sfs-hr-status-chip--orange {
            background-color:#ffedd5;
            color:#9a3412;
            border-color:#fdba74;
        }

        .sfs-hr-status-chip--teal {
            background-color:#ccfbf1;
            color:#0f766e;
            border-color:#5eead4;
        }

        /* ========== Approval-state chips (Pending – HR / Manager etc.) ========== */
        .sfs-hr-leave-chip-pending-gm {
            background-color:#ffedd5;
            color:#9a3412;
            border-color:#fdba74;
        }

        .sfs-hr-leave-chip-pending-manager {
            background-color:#fef9c3;
            color:#854d0e;
            border-color:#facc15;
        }

        .sfs-hr-leave-chip-pending-hr {
            background-color:#eff6ff;
            color:#1d4ed8;
            border-color:#bfdbfe;
        }

        .sfs-hr-leave-chip-approved-manager,
        .sfs-hr-leave-chip-approved-hr {
            background-color:#ecfdf3;
            color:#166534;
            border-color:#bbf7d0;
        }

        .sfs-hr-leave-chip-rejected,
        .sfs-hr-leave-chip-cancelled {
            background-color:#fef2f2;
            color:#b91c1c;
            border-color:#fecaca;
        }
    </style>';
}



/**
 * Return HTML for a colored asset status badge.
 */
public static function asset_status_badge( string $status ): string {
    self::output_asset_status_badge_css();

    $key   = sanitize_key( $status ?: 'unknown' );
    $label = self::asset_status_label( $key );
    $class = 'sfs-hr-asset-status sfs-hr-asset-status--' . $key;

    return '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
}

    /**
     * Generate a reference number for HR requests.
     * Format: PREFIX-YYYY-NNNN (e.g., LV-2026-0001)
     *
     * @param string      $prefix     The prefix (e.g., 'LV', 'LN', 'RS', 'ST', 'SS', 'EL')
     * @param string      $table      The database table name (with wpdb prefix)
     * @param string      $column     The column name storing the reference number (default: 'request_number')
     * @param string|null $created_at Optional date to determine the year (for backfilling historical data)
     * @return string The generated reference number
     */
    public static function generate_reference_number(
        string $prefix,
        string $table,
        string $column = 'request_number',
        ?string $created_at = null
    ): string {
        global $wpdb;

        $year = $created_at ? date( 'Y', strtotime( $created_at ) ) : wp_date( 'Y' );
        $like_pattern = $prefix . '-' . $year . '-%';

        // Get count for this year with this prefix
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` LIKE %s",
                $like_pattern
            )
        );

        $sequence = str_pad( $count + 1, 4, '0', STR_PAD_LEFT );
        return $prefix . '-' . $year . '-' . $sequence;
    }

}
