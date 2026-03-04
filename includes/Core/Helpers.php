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
        $clean   = wp_kses_post( $message );
        // Only apply wpautop to plain-text content; skip if already HTML
        $body    = preg_match( '/<(div|table|h[1-6])\b/i', $clean ) ? $clean : wpautop( $clean );

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
            'sfs-hr-lifecycle' => [
                'label'  => __( 'Employee Lifecycle', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-lifecycle', $base ),
                'parent' => 'sfs-hr',
            ],
            'sfs-hr-payroll' => [
                'label'  => __( 'Payroll', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-payroll', $base ),
                'parent' => 'sfs-hr',
            ],
            'sfs-hr-performance' => [
                'label'  => __( 'Performance', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-performance', $base ),
                'parent' => 'sfs-hr',
            ],
            'sfs-hr-performance-employees' => [
                'label'  => __( 'Employees', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-performance-employees', $base ),
                'parent' => 'sfs-hr-performance',
            ],
            'sfs-hr-performance-goals' => [
                'label'  => __( 'Goals', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-performance-goals', $base ),
                'parent' => 'sfs-hr-performance',
            ],
            'sfs-hr-performance-reviews' => [
                'label'  => __( 'Reviews', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-performance-reviews', $base ),
                'parent' => 'sfs-hr-performance',
            ],
            'sfs-hr-performance-alerts' => [
                'label'  => __( 'Alerts', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-performance-alerts', $base ),
                'parent' => 'sfs-hr-performance',
            ],
            'sfs-hr-performance-settings' => [
                'label'  => __( 'Settings', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-performance-settings', $base ),
                'parent' => 'sfs-hr-performance',
            ],
            'sfs-hr-surveys' => [
                'label'  => __( 'Surveys', 'sfs-hr' ),
                'url'    => add_query_arg( 'page', 'sfs-hr-surveys', $base ),
                'parent' => 'sfs-hr-performance',
            ],
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

        $html = '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible sfs-hr-notice"><p>' . esc_html( $msg ) . '</p>';

        // Append per-row skip details stored by the CSV importer.
        $transient_key  = 'sfs_hr_import_skipped_' . get_current_user_id();
        $skipped_detail = get_transient( $transient_key );
        if ( is_array( $skipped_detail ) && $skipped_detail ) {
            delete_transient( $transient_key );
            $html .= '<details style="margin-top:6px;"><summary style="cursor:pointer;font-weight:600;">'
                   . esc_html__( 'Show skipped rows', 'sfs-hr' ) . '</summary><ul style="margin:4px 0 0 18px;">';
            foreach ( $skipped_detail as $line ) {
                $html .= '<li>' . esc_html( $line ) . '</li>';
            }
            $html .= '</ul></details>';
        }

        $html .= '</div>';
        echo $html;
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

    /**
     * Return distinct nationality values currently in use by employees.
     *
     * Only returns nationalities with at least one employee record.
     * Results are sorted alphabetically and cached for the request.
     *
     * @return string[]
     */
    public static function get_nationalities_for_select(): array {
        static $cache = null;

        if ( $cache !== null ) {
            return $cache;
        }

        // 1. Use admin-configured list if set.
        $configured = get_option( 'sfs_hr_nationalities', [] );
        if ( is_array( $configured ) && ! empty( $configured ) ) {
            // Merge with any existing DB values not in the configured list.
            global $wpdb;
            $table   = $wpdb->prefix . 'sfs_hr_employees';
            $db_rows = $wpdb->get_col(
                "SELECT DISTINCT nationality FROM {$table}
                 WHERE nationality IS NOT NULL AND nationality != ''
                 ORDER BY nationality ASC"
            );
            $merged = array_unique( array_merge( $configured, $db_rows ?: [] ) );
            sort( $merged );
            $cache = $merged;
            return $cache;
        }

        // 2. Fallback: auto-seed from existing employee data.
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';

        $rows = $wpdb->get_col(
            "SELECT DISTINCT nationality FROM {$table}
             WHERE nationality IS NOT NULL AND nationality != ''
             ORDER BY nationality ASC"
        );

        $cache = $rows ?: [];
        return $cache;
    }

    /**
     * Render a nationality <select> dropdown.
     *
     * Auto-seeded from existing employee data. Includes an "Add new…" option
     * that reveals a text input for entering a new nationality.
     *
     * @param string $selected Currently selected value.
     * @param string $name     HTML name attribute.
     * @param string $id       HTML id attribute.
     * @param string $class    CSS class(es) for the wrapper.
     */
    public static function render_nationality_select( string $selected = '', string $name = 'nationality', string $id = '', string $class = 'regular-text' ): void {
        $nationalities = self::get_nationalities_for_select();
        $id = $id ?: 'sfs-hr-nationality-' . wp_unique_id();

        // If the current value isn't in the list (e.g. brand new), include it.
        if ( $selected !== '' && ! in_array( $selected, $nationalities, true ) ) {
            $nationalities[] = $selected;
            sort( $nationalities );
        }

        $sel_label = $selected !== '' ? esc_html( $selected ) : esc_html__( '— Select —', 'sfs-hr' );

        echo '<div class="sfs-hr-nationality-wrap" style="display:flex;width:100%;gap:6px;align-items:center;flex-wrap:wrap;">';
        // Hidden input holds the actual value.
        echo '<input type="hidden" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $selected ) . '" />';
        // Custom searchable dropdown trigger.
        echo '<div class="sfs-hr-nat-dropdown" style="position:relative;width:100%;">';
        echo '<button type="button" class="sfs-hr-nat-trigger ' . esc_attr( $class ) . '" aria-haspopup="listbox" aria-expanded="false">';
        echo '<span class="sfs-hr-nat-label">' . $sel_label . '</span>';
        echo '<svg viewBox="0 0 20 20" width="16" height="16" fill="currentColor" style="flex-shrink:0;opacity:.5;"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';
        echo '</button>';
        echo '<div class="sfs-hr-nat-panel" style="display:none;">';
        echo '<input type="text" class="sfs-hr-nat-search" placeholder="' . esc_attr__( 'Search nationality…', 'sfs-hr' ) . '" autocomplete="off" />';
        echo '<ul class="sfs-hr-nat-list" role="listbox">';
        echo '<li class="sfs-hr-nat-opt" data-value="" role="option">' . esc_html__( '— Select —', 'sfs-hr' ) . '</li>';
        foreach ( $nationalities as $nat ) {
            $is_sel = ( $nat === $selected ) ? ' aria-selected="true"' : '';
            echo '<li class="sfs-hr-nat-opt" data-value="' . esc_attr( $nat ) . '" role="option"' . $is_sel . '>' . esc_html( $nat ) . '</li>';
        }
        echo '<li class="sfs-hr-nat-opt sfs-hr-nat-opt--add" data-value="__add_new__" role="option">' . esc_html__( '+ Add new…', 'sfs-hr' ) . '</li>';
        echo '</ul>';
        echo '</div>';
        echo '</div>';
        echo '<input type="text" class="' . esc_attr( $class ) . ' sfs-hr-nationality-new" placeholder="' . esc_attr__( 'New nationality', 'sfs-hr' ) . '" aria-label="' . esc_attr__( 'New nationality', 'sfs-hr' ) . '" style="display:none;" />';
        echo '</div>';

        // Inline CSS + JS (output once per page).
        static $js_done = false;
        if ( ! $js_done ) {
            $js_done = true;
            echo '<style>';
            echo '.sfs-hr-nat-trigger{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%!important;max-width:100%!important;box-sizing:border-box!important;cursor:pointer;background:#fff;border:1px solid #8c8f94;padding:0 8px;min-height:30px;text-align:start;font-size:inherit;line-height:inherit;border-radius:4px;}';
            echo '.sfs-hr-nat-trigger:focus{outline:1px solid #2271b1;border-color:#2271b1;}';
            echo '.sfs-hr-nat-panel{position:absolute;left:0;right:0;top:100%;margin-top:2px;background:#fff;border:1px solid #8c8f94;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);z-index:200;max-height:260px;display:flex;flex-direction:column;}';
            echo '.sfs-hr-nat-search{display:block;width:100%;box-sizing:border-box;border:none;border-bottom:1px solid #ddd;padding:8px 10px;font-size:13px;outline:none;}';
            echo '.sfs-hr-nat-search:focus{box-shadow:none;}';
            echo '.sfs-hr-nat-list{list-style:none;margin:0;padding:4px 0;overflow-y:auto;flex:1;}';
            echo '.sfs-hr-nat-opt{padding:6px 10px;cursor:pointer;font-size:13px;}';
            echo '.sfs-hr-nat-opt:hover,.sfs-hr-nat-opt.sfs-hr-nat-opt--highlighted{background:#f0f0f1;}';
            echo '.sfs-hr-nat-opt[aria-selected="true"]{font-weight:600;background:#e8f0fe;}';
            echo '.sfs-hr-nat-opt--add{border-top:1px solid #ddd;color:#2271b1;font-weight:500;}';
            echo '.sfs-hr-nat-opt--hidden{display:none;}';
            echo '</style>';
            echo '<script>';
            echo '(function(){';
            echo 'document.addEventListener("click",function(e){';
            echo '  var dd=e.target.closest(".sfs-hr-nat-dropdown");';
            echo '  document.querySelectorAll(".sfs-hr-nat-panel").forEach(function(p){';
            echo '    if(!dd||p!==dd.querySelector(".sfs-hr-nat-panel"))p.style.display="none";';
            echo '  });';
            echo '  if(!dd)return;';
            echo '  var btn=dd.querySelector(".sfs-hr-nat-trigger");';
            echo '  var panel=dd.querySelector(".sfs-hr-nat-panel");';
            echo '  if(e.target.closest(".sfs-hr-nat-trigger")){';
            echo '    var open=panel.style.display!=="none";';
            echo '    panel.style.display=open?"none":"flex";';
            echo '    if(!open){var si=panel.querySelector(".sfs-hr-nat-search");si.value="";si.dispatchEvent(new Event("input"));si.focus();}';
            echo '  }';
            echo '});';
            echo 'document.addEventListener("input",function(e){';
            echo '  if(!e.target.classList.contains("sfs-hr-nat-search"))return;';
            echo '  var q=e.target.value.toLowerCase();';
            echo '  e.target.closest(".sfs-hr-nat-panel").querySelectorAll(".sfs-hr-nat-opt").forEach(function(o){';
            echo '    if(o.classList.contains("sfs-hr-nat-opt--add")){o.classList.remove("sfs-hr-nat-opt--hidden");return;}';
            echo '    var match=o.textContent.toLowerCase().indexOf(q)!==-1;';
            echo '    o.classList.toggle("sfs-hr-nat-opt--hidden",!match);';
            echo '  });';
            echo '});';
            echo 'document.addEventListener("click",function(e){';
            echo '  var opt=e.target.closest(".sfs-hr-nat-opt");';
            echo '  if(!opt)return;';
            echo '  var dd=opt.closest(".sfs-hr-nat-dropdown");';
            echo '  var wrap=dd.closest(".sfs-hr-nationality-wrap");';
            echo '  var hidden=wrap.querySelector("input[type=hidden]");';
            echo '  var label=dd.querySelector(".sfs-hr-nat-label");';
            echo '  var panel=dd.querySelector(".sfs-hr-nat-panel");';
            echo '  var newInp=wrap.querySelector(".sfs-hr-nationality-new");';
            echo '  var val=opt.getAttribute("data-value");';
            echo '  dd.querySelectorAll(".sfs-hr-nat-opt").forEach(function(o){o.removeAttribute("aria-selected");});';
            echo '  if(val==="__add_new__"){';
            echo '    panel.style.display="none";';
            echo '    hidden.disabled=true;';
            echo '    newInp.style.display="";newInp.name=hidden.name;newInp.focus();';
            echo '  }else{';
            echo '    opt.setAttribute("aria-selected","true");';
            echo '    hidden.disabled=false;hidden.value=val;';
            echo '    label.textContent=opt.textContent;';
            echo '    panel.style.display="none";';
            echo '    newInp.style.display="none";newInp.value="";newInp.removeAttribute("name");';
            echo '  }';
            echo '});';
            echo '})();';
            echo '</script>';
        }
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

        // Use MAX of the numeric suffix to avoid collisions when rows are
        // deleted.  The old COUNT(*) approach would re-use existing numbers
        // if any request was removed, violating the UNIQUE constraint.
        $max_ref = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(`{$column}`) FROM `{$table}` WHERE `{$column}` LIKE %s",
                $like_pattern
            )
        );

        $next = 1;
        if ( $max_ref ) {
            $parts = explode( '-', $max_ref );
            $last_seq = (int) end( $parts );
            $next = $last_seq + 1;
        }

        $sequence = str_pad( $next, 4, '0', STR_PAD_LEFT );
        return $prefix . '-' . $year . '-' . $sequence;
    }

    // =========================================================================
    // LANGUAGE / LOCALE HELPERS
    // =========================================================================

    /** Available languages (short code => display label). */
    public static function get_available_languages(): array {
        return [
            'en'  => 'English',
            'ar'  => 'العربية (Arabic)',
            'fil' => 'Filipino',
            'ur'  => 'اردو (Urdu)',
        ];
    }

    /**
     * Map our short language code to a full WP locale string.
     * Empty string means "site default" (WP stores '' for that).
     */
    public static function lang_to_wp_locale( string $lang ): string {
        $map = [
            'ar'  => 'ar',
            'fil' => 'fil',
            'ur'  => 'ur',
            'en'  => 'en_US',
        ];
        return $map[ $lang ] ?? '';
    }

    /**
     * Resolve the WP locale for a given employee (by employee_id or user_id).
     *
     * Priority: employee.language → WP user locale → site locale.
     *
     * @param int $employee_id  Employee row ID (0 to skip).
     * @param int $user_id      WP user ID (0 to skip).
     * @return string WP locale string (e.g. 'ar', 'en_US').
     */
    public static function get_employee_locale( int $employee_id = 0, int $user_id = 0 ): string {
        // Try employee.language first
        if ( $employee_id ) {
            $row = self::get_employee_row( $employee_id );
            if ( $row && ! empty( $row['language'] ) ) {
                $locale = self::lang_to_wp_locale( $row['language'] );
                if ( $locale ) {
                    return $locale;
                }
            }
            if ( $row && ! $user_id ) {
                $user_id = (int) ( $row['user_id'] ?? 0 );
            }
        }

        // Fall back to WP user locale
        if ( $user_id ) {
            $user_locale = get_user_meta( $user_id, 'locale', true );
            if ( $user_locale ) {
                return $user_locale;
            }
        }

        return get_locale();
    }

    /**
     * Resolve the WP locale for a recipient identified by email address.
     *
     * Looks up WP user by email → employee by user_id → employee.language.
     */
    public static function get_locale_for_email( string $email ): string {
        if ( ! $email ) {
            return get_locale();
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return get_locale();
        }

        // Find the employee linked to this WP user
        global $wpdb;
        $emp_table   = $wpdb->prefix . 'sfs_hr_employees';
        $employee_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$emp_table} WHERE user_id = %d LIMIT 1", $user->ID )
        );

        return self::get_employee_locale( $employee_id, $user->ID );
    }

    /**
     * Send a localized email — switches WP locale to the recipient's
     * preferred language, invokes the $build callback to generate
     * subject + body, then sends and restores the locale.
     *
     * @param string   $to       Recipient email.
     * @param callable $build    fn() => ['subject' => …, 'message' => …]
     *                           Called inside the switched locale so __() works.
     * @param array    $extra_headers  Optional extra headers.
     */
    public static function send_mail_localized( string $to, callable $build, array $extra_headers = [] ): void {
        $locale = self::get_locale_for_email( $to );
        $switched = false;

        if ( $locale && $locale !== get_locale() && function_exists( 'switch_to_locale' ) ) {
            switch_to_locale( $locale );
            // Re-apply our JSON translation filter for the new locale
            self::reload_json_translations( $locale );
            $switched = true;
        }

        $email_data = $build();

        if ( $switched ) {
            restore_previous_locale();
            // Re-apply JSON translations for the restored locale
            self::reload_json_translations( determine_locale() );
        }

        if ( ! empty( $email_data['subject'] ) && ! empty( $email_data['message'] ) ) {
            self::send_mail( $to, $email_data['subject'], $email_data['message'], $extra_headers );
        }
    }

    /**
     * Reload our JSON-based gettext translations for a given locale.
     * This ensures __('…', 'sfs-hr') returns the correct language
     * after switch_to_locale().
     */
    public static function reload_json_translations( string $locale ): void {
        // Remove existing filter
        remove_all_filters( 'gettext_sfs-hr' );

        $locale_map = [ 'ar' => 'ar', 'fil' => 'fil', 'ur' => 'ur' ];

        $lang = $locale_map[ $locale ] ?? null;
        if ( ! $lang ) {
            $prefix = substr( $locale, 0, 2 );
            $lang   = $locale_map[ $prefix ] ?? null;
        }
        if ( ! $lang ) {
            return; // English — no filter needed
        }

        $en_file   = SFS_HR_DIR . 'languages/en.json';
        $lang_file = SFS_HR_DIR . "languages/{$lang}.json";
        if ( ! file_exists( $en_file ) || ! file_exists( $lang_file ) ) {
            return;
        }

        $en = json_decode( (string) file_get_contents( $en_file ), true );
        $tr = json_decode( (string) file_get_contents( $lang_file ), true );
        if ( ! is_array( $en ) || ! is_array( $tr ) ) {
            return;
        }

        $map = [];
        foreach ( $en as $key => $english ) {
            if ( strpos( $key, '_comment' ) === 0 ) {
                continue;
            }
            if ( isset( $tr[ $key ] ) && $tr[ $key ] !== $english ) {
                $map[ $english ] = $tr[ $key ];
            }
        }

        if ( ! empty( $map ) ) {
            add_filter( 'gettext_sfs-hr', function ( $translation, $text ) use ( $map ) {
                return $map[ $text ] ?? $translation;
            }, 10, 2 );
        }
    }

    /**
     * Normalize a date string from various formats to MySQL DATE (Y-m-d).
     *
     * Handles: Y-m-d, d/m/Y, m/d/Y, d-m-Y, d.m.Y, and common Excel variants.
     * Returns null for empty or unparseable values.
     */
    public static function normalize_date( ?string $value ): ?string {
        if ( $value === null || trim( $value ) === '' ) {
            return null;
        }

        $value = trim( $value );

        // Already in MySQL format (Y-m-d)
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return $value;
        }

        // dd/mm/yyyy or dd-mm-yyyy or dd.mm.yyyy
        if ( preg_match( '#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $value, $m ) ) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $y = (int) $m[3];

            // Unambiguous: if first part > 12 it must be day (dd/mm/yyyy)
            if ( $a > 12 ) {
                $d = $a;
                $mo = $b;
            // Unambiguous: if second part > 12 it must be day (mm/dd/yyyy)
            } elseif ( $b > 12 ) {
                $mo = $a;
                $d  = $b;
            } else {
                // Ambiguous (both <= 12): treat as dd/mm/yyyy per user preference
                $d  = $a;
                $mo = $b;
            }

            if ( checkdate( $mo, $d, $y ) ) {
                return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
            }
        }

        // Fallback: let PHP try to parse it
        $ts = strtotime( $value );
        if ( $ts !== false && $ts > 0 ) {
            return gmdate( 'Y-m-d', $ts );
        }

        return null;
    }

}
