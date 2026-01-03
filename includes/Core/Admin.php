<?php
namespace SFS\HR\Core;
if ( ! defined('ABSPATH') ) { exit; }

class Admin {
    public function hooks(): void {
    add_action( 'admin_menu',  [ $this, 'menu' ] );
    add_action( 'admin_init',  [ $this, 'maybe_add_employee_photo_column' ] );
    add_action( 'admin_init',  [ $this, 'maybe_install_qr_cols' ] );
    add_action( 'admin_init',  [ $this, 'maybe_install_employee_extra_cols' ] );
    add_action( 'admin_head',  [ $this, 'remove_menu_separator_css' ] );

    add_action( 'admin_post_sfs_hr_add_employee',       [ $this, 'handle_add_employee' ] );
    add_action( 'admin_post_sfs_hr_link_user',          [ $this, 'handle_link_user' ] );
    add_action( 'admin_post_sfs_hr_sync_users',         [ $this, 'handle_sync_users' ] );
    add_action( 'admin_post_sfs_hr_export_employees',   [ $this, 'handle_export' ] );
    add_action( 'admin_post_sfs_hr_import_employees',   [ $this, 'handle_import' ] );
    add_action( 'admin_post_sfs_hr_terminate_employee', [ $this, 'handle_terminate' ] );
    add_action( 'admin_post_sfs_hr_delete_employee',    [ $this, 'handle_delete' ] );
    add_action( 'admin_post_sfs_hr_save_edit',          [ $this, 'handle_save_edit' ] );
    add_action( 'admin_post_sfs_hr_regen_qr',           [ $this, 'handle_regen_qr' ] );
    add_action( 'admin_post_sfs_hr_toggle_qr',          [ $this, 'handle_toggle_qr' ] );
    add_action( 'admin_post_sfs_hr_download_qr_card',   [ $this, 'handle_download_qr_card' ] );


    // Role→Department sync
    add_action( 'admin_post_sfs_hr_sync_dept_members',  [ $this, 'handle_sync_dept_members' ] );
}


    public function maybe_add_employee_photo_column(): void {
        global $wpdb;
        $table = $wpdb->prefix.'sfs_hr_employees';
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name='photo_id'",
            $table
        ));
        if (!$exists) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN photo_id BIGINT UNSIGNED NULL AFTER user_id");
        }
    }

    public function menu(): void {
        add_menu_page(
            __('HR','sfs-hr'),
            __('HR','sfs-hr'),
            'sfs_hr.view',
            'sfs-hr',
            [$this, 'render_dashboard'],
            'dashicons-groups',
            56
        );

        // Override the auto-generated duplicate submenu with "Dashboard"
        add_submenu_page(
            'sfs-hr',
            __('Dashboard','sfs-hr'),
            __('Dashboard','sfs-hr'),
            'sfs_hr.view',
            'sfs-hr',
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            'sfs-hr',
            __('Employees','sfs-hr'),
            __('Employees','sfs-hr'),
            'sfs_hr.manage',
            'sfs-hr-employees',
            [$this, 'render_employees']
        );

        add_submenu_page(
            'sfs-hr',
            __('My Team','sfs-hr'),
            __('My Team','sfs-hr'),
            'sfs_hr.leave.review',
            'sfs-hr-my-team',
            [$this, 'render_my_team']
        );
    }

    public function remove_menu_separator_css(): void {
        echo '<style>
            /* Remove separator after HR menu */
            #adminmenu li.toplevel_page_sfs-hr + li.wp-menu-separator {
                display: none !important;
            }
        </style>';
    }

    public function maybe_install_qr_cols(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_employees';

        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $t
        ));
        if (!$exists) return;

        $need_qr_token = ! (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'qr_token'", $t));
        $need_qr_enabled = ! (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'qr_enabled'", $t));
        $need_qr_updated = ! (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'qr_updated_at'", $t));

        if ($need_qr_token)   { $wpdb->query("ALTER TABLE {$t} ADD COLUMN qr_token VARCHAR(64) NULL"); }
        if ($need_qr_enabled) { $wpdb->query("ALTER TABLE {$t} ADD COLUMN qr_enabled TINYINT(1) NOT NULL DEFAULT 1"); }
        if ($need_qr_updated) { $wpdb->query("ALTER TABLE {$t} ADD COLUMN qr_updated_at DATETIME NULL"); }
    }
    
    public function maybe_install_employee_extra_cols(): void {
    global $wpdb;
    $t = $wpdb->prefix . 'sfs_hr_employees';

    $exists = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables 
         WHERE table_schema = DATABASE() AND table_name = %s",
        $t
    ) );
    if ( ! $exists ) {
        return;
    }

    $cols = [
        'nationality'            => "ALTER TABLE {$t} ADD COLUMN nationality VARCHAR(100) NULL AFTER gender",
        'marital_status'         => "ALTER TABLE {$t} ADD COLUMN marital_status VARCHAR(50) NULL AFTER nationality",
        'date_of_birth'          => "ALTER TABLE {$t} ADD COLUMN date_of_birth DATE NULL AFTER marital_status",

        'work_location'          => "ALTER TABLE {$t} ADD COLUMN work_location VARCHAR(191) NULL AFTER position",
        'contract_type'          => "ALTER TABLE {$t} ADD COLUMN contract_type VARCHAR(50) NULL AFTER work_location",
        'contract_start_date'    => "ALTER TABLE {$t} ADD COLUMN contract_start_date DATE NULL AFTER contract_type",
        'contract_end_date'      => "ALTER TABLE {$t} ADD COLUMN contract_end_date DATE NULL AFTER contract_start_date",
        'probation_end_date'     => "ALTER TABLE {$t} ADD COLUMN probation_end_date DATE NULL AFTER contract_end_date",

        'entry_date_ksa'         => "ALTER TABLE {$t} ADD COLUMN entry_date_ksa DATE NULL AFTER hired_at",
        'residence_profession'   => "ALTER TABLE {$t} ADD COLUMN residence_profession VARCHAR(191) NULL AFTER entry_date_ksa",

        'sponsor_name'           => "ALTER TABLE {$t} ADD COLUMN sponsor_name VARCHAR(191) NULL AFTER residence_profession",
        'sponsor_id'             => "ALTER TABLE {$t} ADD COLUMN sponsor_id VARCHAR(50) NULL AFTER sponsor_name",

        'visa_number'            => "ALTER TABLE {$t} ADD COLUMN visa_number VARCHAR(50) NULL AFTER passport_no",
        'visa_expiry'            => "ALTER TABLE {$t} ADD COLUMN visa_expiry DATE NULL AFTER visa_number",

        'driving_license_has'    => "ALTER TABLE {$t} ADD COLUMN driving_license_has TINYINT(1) NOT NULL DEFAULT 0 AFTER visa_expiry",
        'driving_license_number' => "ALTER TABLE {$t} ADD COLUMN driving_license_number VARCHAR(50) NULL AFTER driving_license_has",
        'driving_license_expiry' => "ALTER TABLE {$t} ADD COLUMN driving_license_expiry DATE NULL AFTER driving_license_number",

        'gosi_salary'            => "ALTER TABLE {$t} ADD COLUMN gosi_salary DECIMAL(10,2) NULL AFTER base_salary",
    ];

    foreach ( $cols as $col => $sql ) {
        $has_col = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
            $t,
            $col
        ) );
        if ( ! $has_col ) {
            $wpdb->query( $sql );
        }
    }
}


    public function render_dashboard(): void {
    Helpers::require_cap( 'sfs_hr.view' );

    global $wpdb;

    $emp_t      = $wpdb->prefix . 'sfs_hr_employees';
    $req_t      = $wpdb->prefix . 'sfs_hr_leave_requests';
    $dept_t     = $wpdb->prefix . 'sfs_hr_departments';
    $shift_t    = $wpdb->prefix . 'sfs_hr_attendance_shifts';
    $sessions_t = $wpdb->prefix . 'sfs_hr_attendance_sessions';

    $today = wp_date( 'Y-m-d' );

    // --- Employees ---
    $total_employees  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$emp_t}" );
    $active_employees = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$emp_t} WHERE status = %s",
            'active'
        )
    );

    // --- Leave: pending + currently on leave (from leave requests) ---
    $pending_leaves = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$req_t} WHERE status = 'pending'"
    );

    $on_leave_employees = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(DISTINCT employee_id)
             FROM {$req_t}
             WHERE status = 'approved'
               AND %s BETWEEN start_date AND end_date",
            $today
        )
    );

    // --- Departments count (guard table exists) ---
    $departments_count = 0;
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $dept_t ) ) ) {
        $departments_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dept_t}" );
    }

    // --- Attendance: active shifts ---
    $active_shifts = 0;
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $shift_t ) ) ) {
        $active_shifts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$shift_t} WHERE active = 1"
        );
    }

    // --- Workforce / sessions: today stats ---
    $today_sessions      = 0;
    $clocked_in_now      = 0;
    $absent_today        = 0;
    $late_today          = 0;
    $incomplete_sessions = 0;
    $holiday_today       = 0;

    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sessions_t ) ) ) {
        $today_sessions = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sessions_t} WHERE work_date = %s",
                $today
            )
        );

        $clocked_in_now = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sessions_t}
                 WHERE work_date = %s
                   AND in_time IS NOT NULL
                   AND out_time IS NULL",
                $today
            )
        );

        $absent_today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sessions_t}
                 WHERE work_date = %s
                   AND status = 'absent'",
                $today
            )
        );

        $late_today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sessions_t}
                 WHERE work_date = %s
                   AND status = 'late'",
                $today
            )
        );

        $incomplete_sessions = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sessions_t}
                 WHERE work_date = %s
                   AND status = 'incomplete'",
                $today
            )
        );

        $holiday_today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$sessions_t}
                 WHERE work_date = %s
                   AND status = 'holiday'",
                $today
            )
        );
    }

    echo '<div class="wrap sfs-hr-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'HR Dashboard', 'sfs-hr' ) . '</h1>';

    // HR nav + breadcrumb
    Helpers::render_admin_nav();

    echo '<hr class="wp-header-end" />';

    // Hide generic WP notices here, keep our own .sfs-hr-notice
    echo '<style>
        #wpbody-content .notice:not(.sfs-hr-notice) { display: none; }
    </style>';

    // Dashboard layout styles
    echo '<style>
        .sfs-hr-wrap .sfs-hr-dashboard-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 16px;
            margin-right: 16px; /* avoid touching right edge */
        }
        .sfs-hr-wrap .sfs-hr-card {
            flex: 1 1 220px;
            background: #fff;
            border-radius: 8px;
            border: 1px solid #dcdcde;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
            padding: 16px;
            text-decoration: none;
            color: #1d2327;
        }
        .sfs-hr-wrap .sfs-hr-card:hover {
            box-shadow: 0 2px 6px rgba(0,0,0,.08);
        }
        .sfs-hr-wrap .sfs-hr-card h2 {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 600;
        }
        .sfs-hr-wrap .sfs-hr-card .sfs-hr-card-count {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .sfs-hr-wrap .sfs-hr-card .sfs-hr-card-meta {
            font-size: 12px;
            color: #646970;
        }
    </style>';

    echo '<div class="sfs-hr-dashboard-grid">';

    // Employees
    echo '<a class="sfs-hr-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-employees' ) ) . '">';
    echo '<h2>' . esc_html__( 'Employees', 'sfs-hr' ) . '</h2>';
    echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $active_employees ) ) . '</div>';
    echo '<div class="sfs-hr-card-meta">' . sprintf(
        esc_html__( '%s total employees', 'sfs-hr' ),
        number_format_i18n( $total_employees )
    ) . '</div>';
    echo '</a>';

    // Leave (pending + on leave now)
    echo '<a class="sfs-hr-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-leave' ) ) . '">';
    echo '<h2>' . esc_html__( 'Leave', 'sfs-hr' ) . '</h2>';
    echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $pending_leaves ) ) . '</div>';
    echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Pending leave requests', 'sfs-hr' ) . '</div>';
    echo '<div class="sfs-hr-card-meta">' . sprintf(
        esc_html__( '%s employees currently on leave', 'sfs-hr' ),
        number_format_i18n( $on_leave_employees )
    ) . '</div>';
    echo '</a>';

    // Working now (clocked in)
    echo '<a class="sfs-hr-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-workforce-status' ) ) . '">';
    echo '<h2>' . esc_html__( 'Working now', 'sfs-hr' ) . '</h2>';
    echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $clocked_in_now ) ) . '</div>';
    echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Employees clocked in right now', 'sfs-hr' ) . '</div>';
    echo '</a>';

    // Absent today
    echo '<a class="sfs-hr-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-workforce-status' ) ) . '">';
    echo '<h2>' . esc_html__( 'Absent today', 'sfs-hr' ) . '</h2>';
    echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $absent_today ) ) . '</div>';
    echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Marked as absent in today\'s sessions', 'sfs-hr' ) . '</div>';
    echo '</a>';

    // Late today
    echo '<a class="sfs-hr-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-workforce-status' ) ) . '">';
    echo '<h2>' . esc_html__( 'Late today', 'sfs-hr' ) . '</h2>';
    echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $late_today ) ) . '</div>';
    echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Sessions with status “late”', 'sfs-hr' ) . '</div>';
    echo '</a>';

    // Incomplete sessions – go directly to Attendance > Sessions tab
$incomplete_url = add_query_arg(
    [
        'page' => 'sfs_hr_attendance',
        'tab'  => 'sessions',
    ],
    admin_url( 'admin.php' )
);

echo '<a class="sfs-hr-card" href="' . esc_url( $incomplete_url ) . '">';
echo '<h2>' . esc_html__( 'Incomplete sessions', 'sfs-hr' ) . '</h2>';
echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $incomplete_sessions ) ) . '</div>';
echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Missing punch-out or similar issues', 'sfs-hr' ) . '</div>';
echo '</a>';



    // On holiday today
    echo '<a class="sfs-hr-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-workforce-status' ) ) . '">';
    echo '<h2>' . esc_html__( 'On holiday today', 'sfs-hr' ) . '</h2>';
    echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $holiday_today ) ) . '</div>';
    echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Sessions marked as holiday', 'sfs-hr' ) . '</div>';
    echo '</a>';

    // Attendance (active shifts)
    echo '<a class="sfs-hr-card" href="' . esc_url( admin_url( 'admin.php?page=sfs_hr_attendance' ) ) . '">';
    echo '<h2>' . esc_html__( 'Attendance', 'sfs-hr' ) . '</h2>';
    echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $active_shifts ) ) . '</div>';
    echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Active shifts configured', 'sfs-hr' ) . '</div>';
    echo '</a>';

    // Workforce Status (today sessions)
    echo '<a class="sfs-hr-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-workforce-status' ) ) . '">';
    echo '<h2>' . esc_html__( 'Workforce Status', 'sfs-hr' ) . '</h2>';
    echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $today_sessions ) ) . '</div>';
    echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Attendance rows for today', 'sfs-hr' ) . '</div>';
    echo '</a>';

    // Departments
    echo '<a class="sfs-hr-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-departments' ) ) . '">';
    echo '<h2>' . esc_html__( 'Departments', 'sfs-hr' ) . '</h2>';
    echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $departments_count ) ) . '</div>';
    echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Structure & approver routing', 'sfs-hr' ) . '</div>';
    echo '</a>';

    echo '</div>'; // .sfs-hr-dashboard-grid
    echo '</div>'; // .wrap
}





    private function query_employees(string $q, int $page, int $per_page): array {
        global $wpdb; $table = $wpdb->prefix.'sfs_hr_employees';
        $where  = '1=1';
        $params = [];

        if ($q !== '') {
            $like   = '%' . $wpdb->esc_like($q) . '%';
            $where .= " AND (employee_code LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)";
            $params = [$like,$like,$like,$like];
        }

        $total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = (int)($params ? $wpdb->get_var($wpdb->prepare($total_sql, ...$params)) : $wpdb->get_var($total_sql));

        $offset = max(0, ($page-1)*$per_page);
        $rows_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($rows_sql, ...array_merge($params, [$per_page, $offset])), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($rows_sql, $per_page, $offset), ARRAY_A);

        return [$rows, $total];
    }

    /** Active departments map [id => name] */
    private function departments_map(): array {
        global $wpdb;
        $table = $wpdb->prefix.'sfs_hr_departments';
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        ));
        if (!$exists) return [];
        $rows = $wpdb->get_results("SELECT id, name FROM {$table} WHERE active=1 ORDER BY name ASC", ARRAY_A);
        $map  = [];
        foreach ($rows as $r) { $map[(int)$r['id']] = $r['name']; }
        return $map;
    }

    /** Validate dept id; return int id or null if invalid */
    private function validate_dept_id($dept_id) {
        $dept_id = (int)$dept_id;
        if ($dept_id <= 0) return null;
        global $wpdb;
        $table = $wpdb->prefix.'sfs_hr_departments';
        $ok = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d AND active=1", $dept_id));
        return $ok ? $dept_id : null;
    }
    
    
        /**
     * Grouped list of active attendance shifts for selects.
     *
     * Returns:
     * [
     *   'office'   => [ shift_id => 'Name | 09:00–18:00', ... ],
     *   'showroom' => [ ... ],
     *   'other'    => [ ... ],
     * ]
     */
    private function attendance_shifts_grouped(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_attendance_shifts';

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name   = %s",
                $table
            )
        );
        if ( ! $exists ) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT id, name, dept, start_time, end_time, active
             FROM {$table}
             ORDER BY active DESC, dept ASC, name ASC",
            ARRAY_A
        );
        if ( ! $rows ) {
            return [];
        }

        $out = [];

        foreach ( $rows as $row ) {
            $id = isset( $row['id'] ) ? (int) $row['id'] : 0;
            if ( $id <= 0 ) {
                continue;
            }

            $dept = (string) ( $row['dept'] ?? '' );
            if ( $dept === '' ) {
                $dept = 'other';
            }

            $label_parts = [];

            $label_name = trim( (string) ( $row['name'] ?? '' ) );
            if ( $label_name !== '' ) {
                $label_parts[] = $label_name;
            }

            $start = (string) ( $row['start_time'] ?? '' );
            $end   = (string) ( $row['end_time'] ?? '' );
            if ( $start !== '' && $end !== '' ) {
                $label_parts[] = sprintf(
                    '%s–%s',
                    substr( $start, 0, 5 ),
                    substr( $end, 0, 5 )
                );
            }

            $label = implode( ' | ', $label_parts );
            if ( $label === '' ) {
                $label = sprintf( __( 'Shift #%d', 'sfs-hr' ), $id );
            }

            if ( empty( $out[ $dept ] ) ) {
                $out[ $dept ] = [];
            }

            $out[ $dept ][ $id ] = $label;
        }

        return $out;
    }

    /** Validate attendance shift id; return int id or null if invalid/inactive. */
    private function validate_shift_id( $shift_id ) {
        $shift_id = (int) $shift_id;
        if ( $shift_id <= 0 ) {
            return null;
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE id=%d AND active=1",
                $shift_id
            )
        );

        return $exists ? $shift_id : null;
    }

    /**
     * Current default shift for employee (based on today) from emp_shifts table.
     * Returns an array with shift fields + start_date, or null.
     */
    private function get_emp_default_shift( int $employee_id ): ?array {
        global $wpdb;

        if ( $employee_id <= 0 ) {
            return null;
        }

        $p        = $wpdb->prefix;
        $shifts_t = "{$p}sfs_hr_attendance_shifts";
        $map_t    = "{$p}sfs_hr_attendance_emp_shifts";

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name   = %s",
                $map_t
            )
        );
        if ( ! $exists ) {
            return null;
        }

        $today = wp_date( 'Y-m-d' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT es.id AS map_id, es.start_date, sh.*
                 FROM {$map_t} es
                 INNER JOIN {$shifts_t} sh ON sh.id = es.shift_id
                 WHERE es.employee_id = %d
                   AND es.start_date  <= %s
                   AND sh.active      = 1
                 ORDER BY es.start_date DESC, es.id DESC
                 LIMIT 1",
                $employee_id,
                $today
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Basic shift history for an employee (newest first).
     */
    private function get_emp_shift_history( int $employee_id, int $limit = 5 ): array {
        global $wpdb;

        if ( $employee_id <= 0 ) {
            return [];
        }

        $p        = $wpdb->prefix;
        $shifts_t = "{$p}sfs_hr_attendance_shifts";
        $map_t    = "{$p}sfs_hr_attendance_emp_shifts";

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name   = %s",
                $map_t
            )
        );
        if ( ! $exists ) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT es.id AS map_id,
                        es.start_date,
                        es.shift_id,
                        sh.name,
                        sh.dept,
                        sh.start_time,
                        sh.end_time
                 FROM {$map_t} es
                 LEFT JOIN {$shifts_t} sh ON sh.id = es.shift_id
                 WHERE es.employee_id = %d
                 ORDER BY es.start_date DESC, es.id DESC
                 LIMIT %d",
                $employee_id,
                $limit
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }


    /** Ensure a random token exists for employee; return token string. */
    private function ensure_qr_token(int $emp_id): string {
        global $wpdb; $t = $wpdb->prefix.'sfs_hr_employees';
        $row = $wpdb->get_row($wpdb->prepare("SELECT qr_token FROM {$t} WHERE id=%d", $emp_id), ARRAY_A);
        $tok = isset($row['qr_token']) ? (string)$row['qr_token'] : '';
        if ($tok === '' || strlen($tok) < 24) {
            $tok = bin2hex(random_bytes(24)); // 48 hex chars
            $wpdb->update($t, [
                'qr_token'      => $tok,
                'qr_updated_at' => Helpers::now_mysql(),
            ], ['id'=>$emp_id]);
        }
        return $tok;
    }

    /** Build the URL encoded inside the QR. */
    private function qr_payload_url(int $emp_id, string $token): string {
        $base = home_url('/wp-json/sfs-hr/v1/attendance/scan');
        return add_query_arg(['emp' => $emp_id, 'token' => $token], $base);
    }

    public function render_employees(): void {
        Helpers::require_cap('sfs_hr.manage');
        echo '<div class="wrap sfs-hr-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'Employees', 'sfs-hr' ) . '</h1>';
    Helpers::render_admin_nav();
    echo '<hr class="wp-header-end" />';

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action === 'edit') { $this->render_edit_form(); return; }

        $q        = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $page     = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = isset($_GET['per_page']) ? max(5, intval($_GET['per_page'])) : 20;

        list($rows,$total) = $this->query_employees($q, $page, $per_page);
        $pages = max(1, (int)ceil($total / $per_page));

        $nonce_export = wp_create_nonce('sfs_hr_export_employees');
        $nonce_import = wp_create_nonce('sfs_hr_import_employees');
        $nonce_sync   = wp_create_nonce('sfs_hr_sync_users');
        $nonce_add    = wp_create_nonce('sfs_hr_add_employee');
        $nonce_link   = wp_create_nonce('sfs_hr_link_user');

        $dept_map = $this->departments_map();
        $shift_groups = $this->attendance_shifts_grouped();

        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('Employees','sfs-hr'); ?></h1>

          <style>
  .sfs-hr-actions .button { margin-right:6px; }
  .sfs-hr-actions .button-danger { background:#d63638; border-color:#d63638; color:#fff; }
  .sfs-hr-actions .button-danger:hover { background:#b32d2e; border-color:#b32d2e; color:#fff; }
  .sfs-hr-badge { display:inline-block; padding:2px 8px; border-radius:12px; background:#f0f0f1; font-size:11px; }
  .sfs-hr-badge.status-active { background:#ecfccb; }
  .sfs-hr-badge.status-inactive { background:#fee2e2; }
  .sfs-hr-badge.status-terminated { background:#ffe4e6; }
  .sfs-hr-select { min-width: 240px; }

  /* Add Employee layout */
  .sfs-hr-emp-add-wrap {
    margin-top: 16px;
    padding: 16px 20px 20px;
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 6px;
    max-width: 1160px;
  }
  .sfs-hr-emp-add-sections {
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
  }
  .sfs-hr-emp-add-section {
    flex: 1 1 320px;
    min-width: 280px;
  }
  .sfs-hr-emp-add-section h3 {
    margin: 0 0 10px;
    font-size: 14px;
    font-weight: 600;
    border-bottom: 1px solid #e5e5e5;
    padding-bottom: 4px;
  }
  .sfs-hr-field {
    margin-bottom: 10px;
  }
  .sfs-hr-field label {
    display: block;
    font-weight: 500;
    margin-bottom: 2px;
  }
  .sfs-hr-field input,
  .sfs-hr-field select {
    width: 100%;
    max-width: 100%;
  }
  .sfs-hr-field-note {
    font-size: 11px;
    color: #666;
    margin-top: 2px;
  }
  .sfs-hr-emp-add-footer {
    margin-top: 16px;
  }
</style>


          <form method="get" style="margin:10px 0;">
            <input type="hidden" name="page" value="sfs-hr-employees" />
            <input type="search" name="s" value="<?php echo esc_attr($q); ?>" placeholder="<?php echo esc_attr__('Search name/email/code','sfs-hr'); ?>"/>
            <select name="per_page">
              <?php foreach ([10,20,50,100] as $pp): ?>
                <option value="<?php echo (int)$pp; ?>" <?php selected($per_page,$pp); ?>><?php echo (int)$pp; ?>/page</option>
              <?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter','sfs-hr'),'secondary','',false); ?>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:10px 0;">
            <input type="hidden" name="action" value="sfs_hr_export_employees" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_export); ?>" />
            <?php submit_button(__('Export CSV','sfs-hr'),'secondary','',false); ?>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin:10px 0;">
            <input type="hidden" name="action" value="sfs_hr_import_employees" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_import); ?>" />
            <input type="file" name="csv" accept=".csv" required />
            <?php submit_button(__('Import CSV','sfs-hr'),'secondary','',false); ?>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:10px 0;">
            <input type="hidden" name="action" value="sfs_hr_sync_users" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_sync); ?>" />
            <label><input type="checkbox" name="role_filter[]" value="subscriber" checked /> <?php echo esc_html__('Include Subscribers','sfs-hr'); ?></label>
            <label style="margin-left:10px;"><input type="checkbox" name="role_filter[]" value="administrator" /> <?php echo esc_html__('Include Administrators','sfs-hr'); ?></label>
            <?php submit_button(__('Run Sync','sfs-hr'),'secondary','',false); ?>
          </form>

          <h2><?php echo esc_html__('Employees List','sfs-hr'); ?></h2>
          <table class="widefat striped">
            <thead><tr>
              <th><?php esc_html_e('ID','sfs-hr'); ?></th>
              <th><?php esc_html_e('Code','sfs-hr'); ?></th>
              <th><?php esc_html_e('Name','sfs-hr'); ?></th>
              <th><?php esc_html_e('Email','sfs-hr'); ?></th>
              <th><?php esc_html_e('Department','sfs-hr'); ?></th>
              <th><?php esc_html_e('Position','sfs-hr'); ?></th>
              <th><?php esc_html_e('Status','sfs-hr'); ?></th>
              <th><?php esc_html_e('WP User','sfs-hr'); ?></th>
              <th><?php esc_html_e('Actions','sfs-hr'); ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="9"><?php esc_html_e('No employees found.','sfs-hr'); ?></td></tr>
            <?php else:
              foreach ($rows as $r):
                $name     = trim(($r['first_name']??'').' '.($r['last_name']??''));
                $status   = $r['status'];
                $edit_url = wp_nonce_url( admin_url('admin.php?page=sfs-hr-employees&action=edit&id='.(int)$r['id']), 'sfs_hr_edit_'.(int)$r['id'] );
                $term_url = wp_nonce_url( admin_url('admin-post.php?action=sfs_hr_terminate_employee&id='.(int)$r['id']), 'sfs_hr_term_'.(int)$r['id'] );
                $del_url  = wp_nonce_url( admin_url('admin-post.php?action=sfs_hr_delete_employee&id='.(int)$r['id']), 'sfs_hr_del_'.(int)$r['id'] );
                $dept_name = empty($r['dept_id']) ? __('General','sfs-hr') : ($dept_map[(int)$r['dept_id']] ?? '#'.(int)$r['dept_id']);
            ?>
              <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><code><?php echo esc_html($r['employee_code']); ?></code></td>
                <td><?php echo esc_html($name); ?></td>
                <td><?php echo esc_html($r['email']); ?></td>
                <td><?php echo esc_html($dept_name); ?></td>
                <td><?php echo esc_html($r['position']); ?></td>
                <td><span class="sfs-hr-badge status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                <td><?php echo $r['user_id'] ? '<code>'.(int)$r['user_id'].'</code>' : '&ndash;'; ?></td>
                <td>
                  <div class="sfs-hr-actions">
                    <a class="button button-small" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit','sfs-hr'); ?></a>
                    <a class="button button-small" href="<?php echo esc_url($term_url); ?>" onclick="return confirm('Terminate this employee?');"><?php esc_html_e('Terminate','sfs-hr'); ?></a>
                    <a class="button button-small button-danger" href="<?php echo esc_url($del_url); ?>" onclick="return confirm('Delete permanently? This cannot be undone.');"><?php esc_html_e('Delete','sfs-hr'); ?></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>

          <div style="margin:10px 0;">
            <?php for($i=1;$i<=$pages;$i++): ?>
              <?php if ($i === $page): ?>
                <span class="tablenav-pages-navspan" style="margin-right:6px;"><?php echo (int)$i; ?></span>
              <?php else: ?>
                <a href="<?php echo esc_url( add_query_arg(['paged'=>$i,'per_page'=>$per_page,'s'=>$q], admin_url('admin.php?page=sfs-hr-employees')) ); ?>" style="margin-right:6px;"><?php echo (int)$i; ?></a>
              <?php endif; ?>
            <?php endfor; ?>
          </div>

                    <hr/>
          <h2><?php esc_html_e('Add Employee','sfs-hr'); ?></h2>

          <div class="sfs-hr-emp-add-wrap">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
              <input type="hidden" name="action" value="sfs_hr_add_employee" />
              <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_add ); ?>" />

              <div class="sfs-hr-emp-add-sections">

                <!-- Identity & contact -->
                <div class="sfs-hr-emp-add-section">
                  <h3><?php esc_html_e( 'Identity & contact', 'sfs-hr' ); ?></h3>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-emp-code"><?php esc_html_e( 'Employee Code', 'sfs-hr' ); ?> *</label>
                    <input id="sfs-hr-emp-code" name="employee_code" class="regular-text" required />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-first-name"><?php esc_html_e( 'First Name', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-first-name" name="first_name" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-last-name"><?php esc_html_e( 'Last Name', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-last-name" name="last_name" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-email"><?php esc_html_e( 'Email', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-email" name="email" type="email" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-phone"><?php esc_html_e( 'Phone', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-phone" name="phone" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-gender"><?php esc_html_e( 'Gender', 'sfs-hr' ); ?></label>
                    <select id="sfs-hr-gender" name="gender">
                      <option value=""><?php esc_html_e( '— Select —', 'sfs-hr' ); ?></option>
                      <option value="male"><?php esc_html_e( 'Male', 'sfs-hr' ); ?></option>
                      <option value="female"><?php esc_html_e( 'Female', 'sfs-hr' ); ?></option>
                    </select>
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-nationality"><?php esc_html_e( 'Nationality', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-nationality" name="nationality" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-marital-status"><?php esc_html_e( 'Marital Status', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-marital-status" name="marital_status" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-dob"><?php esc_html_e( 'Date of Birth', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-dob" name="date_of_birth" type="date" class="regular-text sfs-hr-date" />
                  </div>
                </div>

                <!-- Employment & location -->
                <div class="sfs-hr-emp-add-section">
                  <h3><?php esc_html_e( 'Employment & location', 'sfs-hr' ); ?></h3>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-dept"><?php esc_html_e( 'Department', 'sfs-hr' ); ?></label>
                    <select id="sfs-hr-dept" name="dept_id" class="sfs-hr-select">
                      <option value=""><?php esc_html_e( 'General (no department)', 'sfs-hr' ); ?></option>
                      <?php foreach ( $dept_map as $dept_id_key => $dept_name ): ?>
                        <option value="<?php echo (int) $dept_id_key; ?>">
                          <?php echo esc_html( $dept_name ); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-position"><?php esc_html_e( 'Position', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-position" name="position" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-work-location"><?php esc_html_e( 'Work Location', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-work-location" name="work_location" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-shift">
                        <?php esc_html_e( 'Default Attendance Shift', 'sfs-hr' ); ?> *
                    </label>

                    <?php if ( $shift_groups ) : ?>
                        <select id="sfs-hr-shift"
                                name="attendance_shift_id"
                                class="sfs-hr-select"
                                required>
                            <option value="">
                                <?php esc_html_e( '— Select shift —', 'sfs-hr' ); ?>
                            </option>
                            <?php foreach ( $shift_groups as $dept_slug => $group_shifts ) : ?>
                                <optgroup label="<?php echo esc_attr( ucfirst( $dept_slug ) ); ?>">
                                    <?php foreach ( $group_shifts as $sid => $label ) : ?>
                                        <option value="<?php echo (int) $sid; ?>">
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <div class="sfs-hr-field-note">
                            <?php esc_html_e(
                                'This is the employee’s default working shift. You can still override specific days in Attendance > Assignments.',
                                'sfs-hr'
                            ); ?>
                        </div>
                    <?php else : ?>
                        <p class="description">
                            <?php esc_html_e(
                                'No active attendance shifts found. Configure shifts first under Attendance > Shifts.',
                                'sfs-hr'
                            ); ?>
                        </p>
                    <?php endif; ?>
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-contract-type"><?php esc_html_e( 'Contract Type', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-contract-type" name="contract_type" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-contract-start"><?php esc_html_e( 'Contract Start Date', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-contract-start" name="contract_start_date" type="date" class="regular-text sfs-hr-date" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-contract-end"><?php esc_html_e( 'Contract End Date', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-contract-end" name="contract_end_date" type="date" class="regular-text sfs-hr-date" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-probation-end"><?php esc_html_e( 'Probation End Date', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-probation-end" name="probation_end_date" type="date" class="regular-text sfs-hr-date" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-hired-at"><?php esc_html_e( 'Hire Date', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-hired-at" name="hired_at" type="date" class="regular-text sfs-hr-date" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-entry-ksa"><?php esc_html_e( 'Entry Date (KSA)', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-entry-ksa" name="entry_date_ksa" type="date" class="regular-text sfs-hr-date" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-res-prof"><?php esc_html_e( 'Residence Profession', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-res-prof" name="residence_profession" class="regular-text" />
                  </div>
                </div>

                <!-- Documents & visas -->
                <div class="sfs-hr-emp-add-section">
                  <h3><?php esc_html_e( 'Documents & visas', 'sfs-hr' ); ?></h3>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-nid"><?php esc_html_e( 'National ID', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-nid" name="national_id" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-nid-exp"><?php esc_html_e( 'National ID Expiry', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-nid-exp" name="national_id_expiry" type="date" class="regular-text sfs-hr-date" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-passport"><?php esc_html_e( 'Passport No.', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-passport" name="passport_no" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-passport-exp"><?php esc_html_e( 'Passport Expiry', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-passport-exp" name="passport_expiry" type="date" class="regular-text sfs-hr-date" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-visa-number"><?php esc_html_e( 'Visa Number', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-visa-number" name="visa_number" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-visa-exp"><?php esc_html_e( 'Visa Expiry', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-visa-exp" name="visa_expiry" type="date" class="regular-text sfs-hr-date" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-sponsor-name"><?php esc_html_e( 'Sponsor Name', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-sponsor-name" name="sponsor_name" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-sponsor-id"><?php esc_html_e( 'Sponsor ID', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-sponsor-id" name="sponsor_id" class="regular-text" />
                  </div>
                </div>

                <!-- Salary & emergency -->
                <div class="sfs-hr-emp-add-section">
                  <h3><?php esc_html_e( 'Salary & emergency', 'sfs-hr' ); ?></h3>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-base-salary"><?php esc_html_e( 'Base Salary', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-base-salary" name="base_salary" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-gosi-salary"><?php esc_html_e( 'GOSI Salary', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-gosi-salary" name="gosi_salary" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-ecn"><?php esc_html_e( 'Emergency Contact Name', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-ecn" name="emergency_contact_name" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-ecp"><?php esc_html_e( 'Emergency Contact Phone', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-ecp" name="emergency_contact_phone" class="regular-text" />
                  </div>
                </div>

                <!-- Driving license & account -->
                <div class="sfs-hr-emp-add-section">
                  <h3><?php esc_html_e( 'Driving license & account', 'sfs-hr' ); ?></h3>

                  <div class="sfs-hr-field">
                    <label>
                      <input type="checkbox" name="driving_license_has" value="1" />
                      <?php esc_html_e( 'Has driving license', 'sfs-hr' ); ?>
                    </label>
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-dl-number"><?php esc_html_e( 'Driving License Number', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-dl-number" name="driving_license_number" class="regular-text" />
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-dl-exp"><?php esc_html_e( 'Driving License Expiry', 'sfs-hr' ); ?></label>
                    <input id="sfs-hr-dl-exp" name="driving_license_expiry" type="date" class="regular-text sfs-hr-date" />
                  </div>

                  <div class="sfs-hr-field">
                    <label>
                      <input type="checkbox" name="create_user" value="1" checked />
                      <?php esc_html_e( 'Create WordPress User', 'sfs-hr' ); ?>
                    </label>
                    <div class="sfs-hr-field-note">
                      <?php esc_html_e( 'If email is set, a new HR employee user will be created or linked automatically.', 'sfs-hr' ); ?>
                    </div>
                  </div>
                </div>

              </div><!-- .sfs-hr-emp-add-sections -->

              <div class="sfs-hr-emp-add-footer">
                <?php submit_button( __( 'Save Employee', 'sfs-hr' ) ); ?>
              </div>
            </form>
          </div>


          <hr/>
          <h2><?php esc_html_e('Link Existing WordPress User','sfs-hr'); ?></h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sfs_hr_link_user" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_link); ?>" />
            <table class="form-table">
              <tr><th><?php esc_html_e('Employee Code','sfs-hr'); ?></th><td><input name="employee_code" class="regular-text" required /></td></tr>
              <tr><th><?php esc_html_e('WP User (username or email)','sfs-hr'); ?></th><td><input name="user_identifier" class="regular-text" required /></td></tr>
            </table>
            <?php submit_button(__('Link User','sfs-hr')); ?>
          </form>
        </div>
        <?php
    }

    private function sanitize_field($k, $type='text'){
        if (!isset($_POST[$k])) return '';
        $v = $_POST[$k];
        return $type==='email' ? sanitize_email($v) : sanitize_text_field($v);
    }

    public function handle_add_employee(): void {
        Helpers::require_cap('sfs_hr.manage');
        check_admin_referer('sfs_hr_add_employee');

        $code  = $this->sanitize_field('employee_code');
        if (empty($code)) { wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','err'=>'code'], admin_url('admin.php')) ); exit; }

        $first  = $this->sanitize_field('first_name');
        $last   = $this->sanitize_field('last_name');
        $email  = $this->sanitize_field('email','email');
        $phone  = $this->sanitize_field('phone');
        $dept   = isset($_POST['dept_id']) ? $this->validate_dept_id($_POST['dept_id']) : null;
        $pos    = $this->sanitize_field('position');
                $shift_id_in = isset( $_POST['attendance_shift_id'] ) ? (int) $_POST['attendance_shift_id'] : 0;
        $shift_id    = $this->validate_shift_id( $shift_id_in );

        // Enforce "no employee without shift" if shifts are configured.
        if ( ! $shift_id ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page' => 'sfs-hr-employees',
                        'err'  => 'shift',
                    ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }


        $hired  = $this->sanitize_field('hired_at');
        $nid_ex = $this->sanitize_field('national_id_expiry');
        $pass_ex= $this->sanitize_field('passport_expiry');

        $base   = $this->sanitize_field('base_salary');
        $nid    = $this->sanitize_field('national_id');
        $pass   = $this->sanitize_field('passport_no');
        $ecn    = $this->sanitize_field('emergency_contact_name');
        $ecp    = $this->sanitize_field('emergency_contact_phone');
        $visa_number    = $this->sanitize_field('visa_number');
$visa_expiry    = $this->sanitize_field('visa_expiry');

$nationality    = $this->sanitize_field('nationality');
$marital_status = $this->sanitize_field('marital_status');
$dob            = $this->sanitize_field('date_of_birth');

$work_location  = $this->sanitize_field('work_location');
$contract_type  = $this->sanitize_field('contract_type');
$contract_start = $this->sanitize_field('contract_start_date');
$contract_end   = $this->sanitize_field('contract_end_date');
$probation_end  = $this->sanitize_field('probation_end_date');
$entry_ksa      = $this->sanitize_field('entry_date_ksa');

$res_prof       = $this->sanitize_field('residence_profession');
$sponsor_name   = $this->sanitize_field('sponsor_name');
$sponsor_id     = $this->sanitize_field('sponsor_id');

$dl_has         = ! empty( $_POST['driving_license_has'] ) ? 1 : 0;
$dl_number      = $this->sanitize_field('driving_license_number');
$dl_expiry      = $this->sanitize_field('driving_license_expiry');

$gosi_salary    = $this->sanitize_field('gosi_salary');


        $g = isset($_POST['gender']) ? strtolower(trim(sanitize_text_field($_POST['gender']))) : '';
        $gender = in_array($g, ['male','female'], true) ? $g : null;

        $create_user = !empty($_POST['create_user']);

        global $wpdb; $table = $wpdb->prefix.'sfs_hr_employees';
        $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$table} WHERE employee_code=%s LIMIT 1", $code) );
        if ($exists) { wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','err'=>'duplicate'], admin_url('admin.php')) ); exit; }

        $wpdb->insert($table, [
            'employee_code'           => $code,
            'first_name'              => $first,
            'last_name'               => $last,
            'email'                   => $email,
            'phone'                   => $phone,
            'dept_id'                 => $dept,
            'position'                => $pos,
            'gender'                  => $gender,
            'status'                  => 'active',
            'hired_at'                => $hired ?: null,
            'base_salary'             => $base !== '' ? $base : null,
            'national_id'             => $nid,
            'national_id_expiry'      => $nid_ex ?: null,
            'passport_no'             => $pass,
            'passport_expiry'         => $pass_ex ?: null,
            'emergency_contact_name'  => $ecn,
            'emergency_contact_phone' => $ecp,
            'created_at'              => Helpers::now_mysql(),
            'updated_at'              => Helpers::now_mysql(),
            'visa_number'            => $visa_number,
'visa_expiry'            => $visa_expiry ?: null,

'nationality'            => $nationality,
'marital_status'         => $marital_status,
'date_of_birth'          => $dob ?: null,

'work_location'          => $work_location,
'contract_type'          => $contract_type,
'contract_start_date'    => $contract_start ?: null,
'contract_end_date'      => $contract_end ?: null,
'probation_end_date'     => $probation_end ?: null,
'entry_date_ksa'         => $entry_ksa ?: null,

'residence_profession'   => $res_prof,
'sponsor_name'           => $sponsor_name,
'sponsor_id'             => $sponsor_id,

'driving_license_has'    => $dl_has,
'driving_license_number' => $dl_number,
'driving_license_expiry' => $dl_expiry ?: null,

'gosi_salary'            => $gosi_salary !== '' ? $gosi_salary : null,

        ]);

                $employee_id = (int) $wpdb->insert_id;

                // Base attendance shift mapping
        $map_table = $wpdb->prefix . 'sfs_hr_attendance_emp_shifts';
        $map_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name   = %s",
                $map_table
            )
        );

        if ( $map_exists && $shift_id ) {
            // Use hire date if set; otherwise today.
            $start_ymd = $hired ?: wp_date( 'Y-m-d' );

            // Avoid exact duplicate for same employee/shift/date.
            $already = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$map_table}
                     WHERE employee_id=%d AND shift_id=%d AND start_date=%s
                     LIMIT 1",
                    $employee_id,
                    $shift_id,
                    $start_ymd
                )
            );

            if ( ! $already ) {
                $wpdb->insert(
                    $map_table,
                    [
                        'employee_id' => $employee_id,
                        'shift_id'    => $shift_id,
                        'start_date'  => $start_ymd,
                    ]
                );

                // Optional debug if something goes wrong:
                // if ( $wpdb->last_error ) error_log('[SFS HR] emp_shift insert error (add): ' . $wpdb->last_error);
            }
        }


        // Link/Create WP user
        $user_id = 0;

        if ($email) { $u = get_user_by('email', $email); if ($u) $user_id = (int)$u->ID; }
        if ($create_user) {
            if (!$user_id && $email) {
                try {
                    $user_id = self::create_user_and_link([
                        'id'            => $employee_id,
                        'email'         => $email,
                        'first_name'    => $first,
                        'last_name'     => $last,
                        'employee_code' => $code,
                    ]);
                    add_user_meta($user_id, '_sfs_hr_employee_code', $code, true);
                } catch (\Throwable $e) {
                    error_log('[SFS HR] user creation failed: '.$e->getMessage());
                }
            } elseif ($user_id) {
                $this->link_user_record($employee_id, $user_id);
            }
        } else {
            if ($user_id) $this->link_user_record($employee_id, $user_id);
        }

        wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','ok'=>'1'], admin_url('admin.php')) ); exit;
    }

    public function handle_link_user(): void {
        Helpers::require_cap('sfs_hr.manage');
        check_admin_referer('sfs_hr_link_user');

        $code  = isset($_POST['employee_code']) ? sanitize_text_field($_POST['employee_code']) : '';
        $ident = isset($_POST['user_identifier']) ? sanitize_text_field($_POST['user_identifier']) : '';
        if (!$code || !$ident) { wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','err'=>'link'], admin_url('admin.php')) ); exit; }

        global $wpdb;
        $table  = $wpdb->prefix . 'sfs_hr_employees';
        $emp_id = (int)$wpdb->get_var( $wpdb->prepare("SELECT id FROM {$table} WHERE employee_code=%s LIMIT 1", $code) );
        if ( ! $emp_id ) { wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','err'=>'empnotfound'], admin_url('admin.php')) ); exit; }

        $user = get_user_by('login', $ident);
        if ( ! $user ) $user = get_user_by('email', $ident);
        if ( ! $user ) { wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','err'=>'usernotfound'], admin_url('admin.php')) ); exit; }

        $this->link_user_record($emp_id, (int)$user->ID);
        wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','ok'=>'linked'], admin_url('admin.php')) ); exit;
    }

    private function link_user_record(int $employee_id, int $user_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';
        $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d AND id<>%d LIMIT 1", $user_id, $employee_id) );
        if ($exists) return;
        $wpdb->update($table, ['user_id' => $user_id, 'updated_at' => Helpers::now_mysql()], ['id' => $employee_id]);
        do_action('sfs_hr/employee_linked', $employee_id, $user_id);
    }

    public static function create_user_and_link(array $emp): int {
        $email = sanitize_email($emp['email'] ?? '');
        $first = sanitize_text_field($emp['first_name'] ?? '');
        $last  = sanitize_text_field($emp['last_name'] ?? '');
        $code  = sanitize_text_field($emp['employee_code'] ?? '');
        if ( empty($email) || ! is_email($email) ) throw new \RuntimeException(__('Valid email is required','sfs-hr'));

        $base = sanitize_user( strstr($email, '@', true), true );
        if ( $base === '' ) $base = 'emp';

        $login = $base; $i = 1; while ( username_exists($login) ) { $login = $base . $i++; }

        $user_id = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $email,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first.' '.$last),
            'user_pass'    => wp_generate_password(24, true, true),
            'role'         => 'sfs_hr_employee',
        ]);
        if ( is_wp_error($user_id) ) throw new \RuntimeException($user_id->get_error_message());

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';
        $wpdb->update($table, ['user_id' => (int)$user_id, 'updated_at'=>Helpers::now_mysql()], ['id' => (int)$emp['id']]);

        if ( function_exists('wp_send_new_user_notifications') ) {
            wp_send_new_user_notifications($user_id, 'user');
        }

        do_action('sfs_hr/employee_user_created', (int)$emp['id'], (int)$user_id);
        return (int)$user_id;
    }

    public function handle_sync_users(): void {
        Helpers::require_cap('sfs_hr.manage');
        check_admin_referer('sfs_hr_sync_users');

        $roles = isset($_POST['role_filter']) && is_array($_POST['role_filter'])
            ? array_map('sanitize_text_field', $_POST['role_filter'])
            : [];

        $args = ['fields' => ['ID','user_email','user_login','display_name']];
        if ($roles) $args['role__in'] = $roles;

        $users = get_users($args);

        global $wpdb;
        $table   = $wpdb->prefix . 'sfs_hr_employees';
        $created = 0; $linked = 0; $skipped = 0;

        foreach ($users as $u) {
            $user_id    = (int)$u->ID;
            $exists_emp = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id=%d LIMIT 1", $user_id));
            if ($exists_emp) { $skipped++; continue; }

            $email = sanitize_email($u->user_email);
            $emp_by_email = 0;
            if ($email) {
                $emp_by_email = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE email=%s AND (user_id IS NULL OR user_id=0) LIMIT 1", $email));
            }
            if ($emp_by_email) {
                $wpdb->update($table, ['user_id' => $user_id, 'updated_at'=>Helpers::now_mysql()], ['id' => $emp_by_email]);
                $linked++; continue;
            }

            $first = ''; $last = '';
            $dn = trim($u->display_name);
            if (strpos($dn, ' ') !== false) { [$first,$last] = explode(' ', $dn, 2); } else { $first = $dn ?: $u->user_login; }
            $code = 'USR-' . $user_id;

            $wpdb->insert($table, [
                'user_id'       => $user_id,
                'employee_code' => sanitize_text_field($code),
                'first_name'    => sanitize_text_field($first),
                'last_name'     => sanitize_text_field($last),
                'email'         => $email,
                'status'        => 'active',
                'created_at'    => Helpers::now_mysql(),
                'updated_at'    => Helpers::now_mysql(),
            ]);
            $created++;
        }

        wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','ok'=>"sync:$created:$linked:$skipped"], admin_url('admin.php')) ); exit;
    }

    public function handle_export(): void {
        Helpers::require_cap('sfs_hr.manage');
        check_admin_referer('sfs_hr_export_employees');

        global $wpdb; $table = $wpdb->prefix.'sfs_hr_employees';
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);

        $filename = 'employees-export-' . date('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename='.$filename);

        $out = fopen('php://output', 'w');
        $headers = [
    'id','employee_code','first_name','last_name','email','phone','dept_id','position','gender','status',
    'hired_at','base_salary','gosi_salary',
    'national_id','national_id_expiry','passport_no','passport_expiry',
    'visa_number','visa_expiry',
    'nationality','marital_status','date_of_birth',
    'work_location','contract_type','contract_start_date','contract_end_date','probation_end_date','entry_date_ksa',
    'residence_profession','sponsor_name','sponsor_id',
    'driving_license_has','driving_license_number','driving_license_expiry',
    'emergency_contact_name','emergency_contact_phone',
    'user_id','created_at','updated_at'
];

        fputcsv($out, $headers);
        foreach ($rows as $r) {
            $row = [];
            foreach ($headers as $h) { $row[] = isset($r[$h]) ? $r[$h] : ''; }
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    public function handle_import(): void {
    Helpers::require_cap('sfs_hr.manage');
    check_admin_referer('sfs_hr_import_employees');

    if ( empty($_FILES['csv']['tmp_name']) ) {
        wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','err'=>'nocsv'], admin_url('admin.php')) );
        exit;
    }

    $fh = fopen($_FILES['csv']['tmp_name'], 'r');
    if ( ! $fh ) {
        wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','err'=>'upload'], admin_url('admin.php')) );
        exit;
    }

    $header = fgetcsv($fh);
    if ( ! $header ) {
        fclose($fh);
        wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','err'=>'header'], admin_url('admin.php')) );
        exit;
    }
    $header = array_map('sanitize_key', $header);

    global $wpdb;
    $table   = $wpdb->prefix.'sfs_hr_employees';
    $updated = 0;
    $created = 0;

    while ( ($row = fgetcsv($fh)) !== false ) {
        $data = array_combine($header, $row);
        if ( ! is_array($data) ) { 
            continue;
        }

        $code = isset($data['employee_code']) ? sanitize_text_field($data['employee_code']) : '';
        if ( $code === '' ) { 
            continue;
        }

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE employee_code=%s LIMIT 1", $code)
        );

        $status_in = isset($data['status']) ? sanitize_text_field($data['status']) : 'active';
        $status    = in_array($status_in, ['active','inactive','terminated'], true) ? $status_in : 'active';

        $dept_id_in = ( isset($data['dept_id']) && $data['dept_id'] !== '' ) ? (int) $data['dept_id'] : null;
        $dept_id    = $dept_id_in ? $this->validate_dept_id($dept_id_in) : null;

        $g      = isset($data['gender']) ? strtolower(trim($data['gender'])) : '';
        $gender = in_array($g, ['male','female'], true) ? $g : null;

        $payload = [
            'first_name'              => sanitize_text_field($data['first_name']              ?? ''),
            'last_name'               => sanitize_text_field($data['last_name']               ?? ''),
            'email'                   => sanitize_email($data['email']                        ?? ''),
            'phone'                   => sanitize_text_field($data['phone']                   ?? ''),
            'dept_id'                 => $dept_id,
            'position'                => sanitize_text_field($data['position']                ?? ''),
            'status'                  => $status,
            'gender'                  => $gender,
            'hired_at'                => ($data['hired_at']                ?? '') ?: null,
            'base_salary'             => ($data['base_salary']             ?? '') !== '' ? $data['base_salary'] : null,
            'national_id'             => sanitize_text_field($data['national_id']             ?? ''),
            'national_id_expiry'      => ($data['national_id_expiry']      ?? '') ?: null,
            'passport_no'             => sanitize_text_field($data['passport_no']             ?? ''),
            'passport_expiry'         => ($data['passport_expiry']         ?? '') ?: null,
            'emergency_contact_name'  => sanitize_text_field($data['emergency_contact_name']  ?? ''),
            'emergency_contact_phone' => sanitize_text_field($data['emergency_contact_phone'] ?? ''),
        ];

        // NEW FIELDS
        $payload['gosi_salary']            = ($data['gosi_salary']            ?? '') !== '' ? $data['gosi_salary'] : null;

        $payload['visa_number']            = sanitize_text_field($data['visa_number']            ?? '');
        $payload['visa_expiry']            = ($data['visa_expiry']            ?? '') ?: null;

        $payload['nationality']            = sanitize_text_field($data['nationality']            ?? '');
        $payload['marital_status']         = sanitize_text_field($data['marital_status']         ?? '');
        $payload['date_of_birth']          = ($data['date_of_birth']          ?? '') ?: null;

        $payload['work_location']          = sanitize_text_field($data['work_location']          ?? '');
        $payload['contract_type']          = sanitize_text_field($data['contract_type']          ?? '');
        $payload['contract_start_date']    = ($data['contract_start_date']    ?? '') ?: null;
        $payload['contract_end_date']      = ($data['contract_end_date']      ?? '') ?: null;
        $payload['probation_end_date']     = ($data['probation_end_date']     ?? '') ?: null;
        $payload['entry_date_ksa']         = ($data['entry_date_ksa']         ?? '') ?: null;

        $payload['residence_profession']   = sanitize_text_field($data['residence_profession']   ?? '');
        $payload['sponsor_name']           = sanitize_text_field($data['sponsor_name']           ?? '');
        $payload['sponsor_id']             = sanitize_text_field($data['sponsor_id']             ?? '');

        $payload['driving_license_has']    = ! empty($data['driving_license_has']) ? 1 : 0;
        $payload['driving_license_number'] = sanitize_text_field($data['driving_license_number'] ?? '');
        $payload['driving_license_expiry'] = ($data['driving_license_expiry'] ?? '') ?: null;

        $payload['updated_at']             = Helpers::now_mysql();

        if ( $exists ) {
            $wpdb->update($table, $payload, ['id'=>$exists]);
            $employee_id = $exists;
            $updated++;
        } else {
            $payload = array_merge($payload, [
                'employee_code' => $code,
                'created_at'    => Helpers::now_mysql(),
            ]);
            $wpdb->insert($table, $payload);
            $employee_id = (int) $wpdb->insert_id;
            $created++;
        }

        if ( ! empty($payload['email']) ) {
            $user = get_user_by('email', $payload['email']);
            if ( $user ) {
                $wpdb->update($table, ['user_id' => (int) $user->ID], ['id' => $employee_id]);
            }
        }
    }

    fclose($fh);

    wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','ok'=>"import:$created:$updated"], admin_url('admin.php')) );
    exit;
}


    public function handle_terminate(): void {
        Helpers::require_cap('sfs_hr.manage');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        check_admin_referer('sfs_hr_term_'.$id);
        if ($id<=0) wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=id') );

        global $wpdb; $table = $wpdb->prefix.'sfs_hr_employees';
        $wpdb->update($table, ['status'=>'terminated','updated_at'=>Helpers::now_mysql()], ['id'=>$id]);
        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&ok=terminated') ); exit;
    }

    public function handle_delete(): void {
        Helpers::require_cap('sfs_hr.manage');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        check_admin_referer('sfs_hr_del_'.$id);
        if ($id<=0) wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=id') );

        global $wpdb;
        $loan_table = $wpdb->prefix.'sfs_hr_loans';
        $has = (int)$wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$loan_table} WHERE employee_id=%d", $id) );
        if ($has>0){
            wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=hasloans') ); exit;
        }
        $emp_table = $wpdb->prefix.'sfs_hr_employees';
        $wpdb->delete($emp_table, ['id'=>$id]);
        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&ok=deleted') ); exit;
    }

    private function render_edit_form(): void {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ( $id <= 0 ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-employees&err=id' ) );
        exit;
    }
    Helpers::require_cap( 'sfs_hr.manage' );

    $nonce = wp_create_nonce( 'sfs_hr_save_edit_' . $id );

    global $wpdb;
    $table = $wpdb->prefix . 'sfs_hr_employees';
    $emp   = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ),
        ARRAY_A
    );

    if ( ! $emp ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-employees&err=notfound' ) );
        exit;
    }

    $qr_token   = $this->ensure_qr_token( (int) $emp['id'] );
    $qr_enabled = (int) ( $emp['qr_enabled'] ?? 1 );

    // Build raw URL (not escaped) for QR service; escape only when outputting into HTML.
    $qr_url_raw = $this->qr_payload_url( (int) $emp['id'], $qr_token );
    $qr_img     = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode( $qr_url_raw );

    // Fields that should be rendered as <input type="date">
    $date_fields = [
        'hired_at',
        'national_id_expiry',
        'passport_expiry',
        'visa_expiry',
        'date_of_birth',
        'entry_date_ksa',
        'contract_start_date',
        'contract_end_date',
        'probation_end_date',
        'driving_license_expiry',
    ];

    $dept_map     = $this->departments_map();
    $current_dept = (int) ( $emp['dept_id'] ?? 0 );
    $status_val   = $emp['status'] ?? 'active';
    $shift_groups  = $this->attendance_shifts_grouped();
    $current_shift = $this->get_emp_default_shift( (int) $emp['id'] );
    $shift_history = $this->get_emp_shift_history( (int) $emp['id'], 5 );

    // Small helper to render a simple <input> row.
    $render_input_row = function ( string $field, string $label = '' ) use ( $emp, $date_fields ) {
        if ( $label === '' ) {
            $label = ucwords( str_replace( '_', ' ', $field ) );
        }
        $val  = $emp[ $field ] ?? '';
        $type = in_array( $field, $date_fields, true )
            ? 'date'
            : ( $field === 'email' ? 'email' : 'text' );

        $cls = in_array( $field, $date_fields, true )
            ? 'regular-text sfs-hr-date'
            : 'regular-text';
        ?>
        <tr>
            <th>
                <label for="sfs-hr-<?php echo esc_attr( $field ); ?>">
                    <?php echo esc_html( $label ); ?>
                </label>
            </th>
            <td>
                <input
                    id="sfs-hr-<?php echo esc_attr( $field ); ?>"
                    name="<?php echo esc_attr( $field ); ?>"
                    type="<?php echo esc_attr( $type ); ?>"
                    class="<?php echo esc_attr( $cls ); ?>"
                    value="<?php echo esc_attr( $val ); ?>"
                />
            </td>
        </tr>
        <?php
    };
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Edit Employee', 'sfs-hr' ); ?></h1>

        <style>
            .sfs-hr-emp-edit-layout {
                margin-top: 20px;
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
            }
            .sfs-hr-emp-card {
                flex: 1 1 360px;
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 16px 20px;
                box-shadow: 0 1px 1px rgba(0,0,0,0.02);
            }
            .sfs-hr-emp-card h2 {
                margin: 0 0 10px;
                font-size: 16px;
            }
            .sfs-hr-emp-card .description {
                margin-top: 4px;
            }
            .sfs-hr-emp-card .form-table {
                margin-top: 5px;
            }
            .sfs-hr-emp-card .form-table th {
                width: 180px;
            }
            .sfs-hr-emp-dual {
                display: grid;
                grid-template-columns: repeat(auto-fit,minmax(260px,1fr));
                gap: 20px;
            }
        </style>

        <!-- MAIN FORM (no nested forms inside) -->
        <form method="post"
              action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              enctype="multipart/form-data">
            <input type="hidden" name="action" value="sfs_hr_save_edit" />
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />

            <div class="sfs-hr-emp-edit-layout">

                <!-- Personal & contact -->
                <div class="sfs-hr-emp-card">
                    <h2><?php esc_html_e( 'Personal & Contact', 'sfs-hr' ); ?></h2>
                    <table class="form-table">
                        <?php
                        $render_input_row( 'employee_code', __( 'Employee Code', 'sfs-hr' ) );
                        $render_input_row( 'first_name', __( 'First Name', 'sfs-hr' ) );
                        $render_input_row( 'last_name', __( 'Last Name', 'sfs-hr' ) );
                        $render_input_row( 'email', __( 'Email', 'sfs-hr' ) );
                        $render_input_row( 'phone', __( 'Phone', 'sfs-hr' ) );
                        ?>
                        <tr>
                            <th><?php esc_html_e( 'Gender', 'sfs-hr' ); ?></th>
                            <td>
                                <?php $g = strtolower( (string) ( $emp['gender'] ?? '' ) ); ?>
                                <select name="gender">
                                    <option value=""><?php esc_html_e( '— Select —', 'sfs-hr' ); ?></option>
                                    <option value="male"   <?php selected( $g === 'male' ); ?>><?php esc_html_e( 'Male', 'sfs-hr' ); ?></option>
                                    <option value="female" <?php selected( $g === 'female' ); ?>><?php esc_html_e( 'Female', 'sfs-hr' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <?php
                        $render_input_row( 'nationality', __( 'Nationality', 'sfs-hr' ) );
                        $render_input_row( 'marital_status', __( 'Marital Status', 'sfs-hr' ) );
                        $render_input_row( 'date_of_birth', __( 'Date of Birth', 'sfs-hr' ) );
                        $render_input_row( 'work_location', __( 'Work Location', 'sfs-hr' ) );
                        ?>
                    </table>
                </div>

                <!-- Job, department & contract -->
                <div class="sfs-hr-emp-card">
                    <h2><?php esc_html_e( 'Job & Contract', 'sfs-hr' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Department', 'sfs-hr' ); ?></th>
                            <td>
                                <select name="dept_id" class="sfs-hr-select">
                                    <option value=""><?php esc_html_e( 'General (no department)', 'sfs-hr' ); ?></option>
                                    <?php foreach ( $dept_map as $dept_id_key => $dept_name ) : ?>
                                        <option value="<?php echo (int) $dept_id_key; ?>"
                                            <?php selected( $current_dept, $dept_id_key ); ?>>
                                            <?php echo esc_html( $dept_name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php
                        $render_input_row( 'position', __( 'Position', 'sfs-hr' ) );
                        ?>
                        <tr>
                            <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                            <td>
                                <select name="status">
                                    <option value="active"     <?php selected( $status_val, 'active' ); ?>><?php esc_html_e( 'Active', 'sfs-hr' ); ?></option>
                                    <option value="inactive"   <?php selected( $status_val, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'sfs-hr' ); ?></option>
                                    <option value="terminated" <?php selected( $status_val, 'terminated' ); ?>><?php esc_html_e( 'Terminated', 'sfs-hr' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <?php
                                                $render_input_row( 'hired_at', __( 'Hire Date', 'sfs-hr' ) );
                        $render_input_row( 'entry_date_ksa', __( 'Entry Date (KSA)', 'sfs-hr' ) );
                        $render_input_row( 'contract_type', __( 'Contract Type', 'sfs-hr' ) );
                        $render_input_row( 'contract_start_date', __( 'Contract Start Date', 'sfs-hr' ) );
                        $render_input_row( 'contract_end_date', __( 'Contract End Date', 'sfs-hr' ) );
                        $render_input_row( 'probation_end_date', __( 'Probation End Date', 'sfs-hr' ) );
                        $render_input_row( 'base_salary', __( 'Base Salary', 'sfs-hr' ) );
                        $render_input_row( 'gosi_salary', __( 'GOSI Salary', 'sfs-hr' ) );
                        ?>
                        <tr>
                            <th><?php esc_html_e( 'Current default shift', 'sfs-hr' ); ?></th>
                            <td>
                                <?php
                                if ( $current_shift ) {
                                    $cs_label = $current_shift['name'] ?? '';
                                    $cs_dept  = $current_shift['dept'] ?? '';
                                    $cs_time  = '';
                                    if ( ! empty( $current_shift['start_time'] ) && ! empty( $current_shift['end_time'] ) ) {
                                        $cs_time = sprintf(
                                            '%s–%s',
                                            substr( (string) $current_shift['start_time'], 0, 5 ),
                                            substr( (string) $current_shift['end_time'], 0, 5 )
                                        );
                                    }
                                    $parts = array_filter( [ $cs_label, $cs_time, $cs_dept ] );
                                    echo esc_html( implode( ' | ', $parts ) );
                                    echo '<br />';
                                    echo '<span class="description">'
                                        . esc_html__( 'Effective today based on shift history.', 'sfs-hr' )
                                        . '</span>';
                                } else {
                                    echo '<em>' . esc_html__( 'No default shift configured yet.', 'sfs-hr' ) . '</em>';
                                }
                                ?>
                            </td>
                        </tr>

                        <tr>
                            <th><?php esc_html_e( 'Change default shift', 'sfs-hr' ); ?></th>
                            <td>
                                <?php if ( $shift_groups ) : ?>
                                    <select name="attendance_shift_id" class="sfs-hr-select">
                                        <option value="">
                                            <?php esc_html_e( '— Keep current —', 'sfs-hr' ); ?>
                                        </option>
                                        <?php foreach ( $shift_groups as $dept_slug => $group_shifts ) : ?>
                                            <optgroup label="<?php echo esc_attr( ucfirst( $dept_slug ) ); ?>">
                                                <?php foreach ( $group_shifts as $sid => $label ) : ?>
                                                    <option value="<?php echo (int) $sid; ?>">
                                                        <?php echo esc_html( $label ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <br />
                                    <input
                                        type="date"
                                        name="attendance_shift_start"
                                        class="regular-text sfs-hr-date"
                                        value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"
                                    />
                                    <p class="description">
                                        <?php esc_html_e(
                                            'Selecting a shift here adds a new history row starting from the given date. Previous rows are kept for past attendance.',
                                            'sfs-hr'
                                        ); ?>
                                    </p>
                                <?php else : ?>
                                    <p class="description">
                                        <?php esc_html_e(
                                            'No active attendance shifts found. Configure shifts first under Attendance > Shifts.',
                                            'sfs-hr'
                                        ); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <?php if ( $shift_history ) : ?>
                            <tr>
                                <th><?php esc_html_e( 'Shift history (last 5)', 'sfs-hr' ); ?></th>
                                <td>
                                    <ul style="margin:0;padding-left:18px;">
                                        <?php foreach ( $shift_history as $h ) : ?>
                                            <?php
                                            $h_label = $h['name'] ?? '';
                                            $h_dept  = $h['dept'] ?? '';
                                            $h_time  = '';
                                            if ( ! empty( $h['start_time'] ) && ! empty( $h['end_time'] ) ) {
                                                $h_time = sprintf(
                                                    '%s–%s',
                                                    substr( (string) $h['start_time'], 0, 5 ),
                                                    substr( (string) $h['end_time'], 0, 5 )
                                                );
                                            }
                                            $parts = array_filter( [ $h_label, $h_time, $h_dept ] );
                                            ?>
                                            <li>
                                                <strong><?php echo esc_html( $h['start_date'] ?? '' ); ?></strong>
                                                — <?php echo esc_html( implode( ' | ', $parts ) ); ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>


                <!-- IDs, visa, sponsor -->
                <div class="sfs-hr-emp-card">
                    <h2><?php esc_html_e( 'Documents & Residency', 'sfs-hr' ); ?></h2>
                    <table class="form-table">
                        <?php
                        $render_input_row( 'national_id', __( 'National ID', 'sfs-hr' ) );
                        $render_input_row( 'national_id_expiry', __( 'National ID Expiry', 'sfs-hr' ) );
                        $render_input_row( 'passport_no', __( 'Passport No.', 'sfs-hr' ) );
                        $render_input_row( 'passport_expiry', __( 'Passport Expiry', 'sfs-hr' ) );
                        $render_input_row( 'visa_number', __( 'Visa Number', 'sfs-hr' ) );
                        $render_input_row( 'visa_expiry', __( 'Visa Expiry', 'sfs-hr' ) );
                        $render_input_row( 'residence_profession', __( 'Residence Profession', 'sfs-hr' ) );
                        $render_input_row( 'sponsor_name', __( 'Sponsor Name', 'sfs-hr' ) );
                        $render_input_row( 'sponsor_id', __( 'Sponsor ID', 'sfs-hr' ) );
                        ?>
                    </table>
                </div>

                <!-- Driving license & emergency -->
                <div class="sfs-hr-emp-card">
                    <h2><?php esc_html_e( 'Driving License & Emergency', 'sfs-hr' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Driving License', 'sfs-hr' ); ?></th>
                            <td>
                                <?php $dl_has = ! empty( $emp['driving_license_has'] ); ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox" name="driving_license_has" value="1" <?php checked( $dl_has ); ?> />
                                    <?php esc_html_e( 'Has driving license', 'sfs-hr' ); ?>
                                </label>
                                <input
                                    name="driving_license_number"
                                    class="regular-text"
                                    value="<?php echo esc_attr( $emp['driving_license_number'] ?? '' ); ?>"
                                    placeholder="<?php esc_attr_e( 'License number', 'sfs-hr' ); ?>"
                                /><br/>
                                <input
                                    name="driving_license_expiry"
                                    type="date"
                                    class="regular-text sfs-hr-date"
                                    value="<?php echo esc_attr( $emp['driving_license_expiry'] ?? '' ); ?>"
                                    placeholder="<?php esc_attr_e( 'Expiry date', 'sfs-hr' ); ?>"
                                />
                            </td>
                        </tr>

                        <?php
                        $render_input_row( 'emergency_contact_name', __( 'Emergency Contact Name', 'sfs-hr' ) );
                        $render_input_row( 'emergency_contact_phone', __( 'Emergency Contact Phone', 'sfs-hr' ) );
                        ?>
                    </table>
                </div>

                <!-- QR & photo -->
                <div class="sfs-hr-emp-card">
                    <h2><?php esc_html_e( 'QR & Photo', 'sfs-hr' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Employee QR', 'sfs-hr' ); ?></th>
                            <td>
                                <?php if ( $qr_enabled ) : ?>
                                    <img src="<?php echo esc_url( $qr_img ); ?>"
                                        alt="QR" width="220" height="220"
                                        referrerpolicy="no-referrer"
                                        style="border:1px solid #c3c4c7;border-radius:6px;background:#fff;"/>
                                    <p style="margin-top:8px;">
                                        <code style="user-select:all;"><?php echo esc_html( $qr_url_raw ); ?></code>
                                    </p>
                                    <!-- New: Download QR Card button -->
    <p style="margin-top:8px;">
        <a class="button button-secondary" href="<?php
            echo esc_url(
                wp_nonce_url(
                    add_query_arg(
                        [
                            'action' => 'sfs_hr_download_qr_card',
                            'id'     => (int) $emp['id'],
                        ],
                        admin_url( 'admin-post.php' )
                    ),
                    'sfs_hr_download_qr_card_' . (int) $emp['id'],
                    '_sfsqr_download'
                )
            );
        ?>">
            <?php esc_html_e( 'Download QR Card (86×54mm)', 'sfs-hr' ); ?>
        </a>
    </p>
                                <?php else : ?>
                                    <em><?php esc_html_e( 'QR is disabled for this employee.', 'sfs-hr' ); ?></em>
                                <?php endif; ?>
                                <p class="description">
                                    <?php esc_html_e( 'Scanning the code opens a secure URL with a token for this employee.', 'sfs-hr' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th><?php esc_html_e( 'Employee Photo', 'sfs-hr' ); ?></th>
                            <td>
                                <?php
                                $photo_id = isset( $emp['photo_id'] ) ? (int) $emp['photo_id'] : 0;
                                if ( $photo_id ) {
                                    echo wp_get_attachment_image(
                                        $photo_id,
                                        [ 96, 96 ],
                                        false,
                                        [ 'style' => 'border-radius:6px;display:block;margin-bottom:8px' ]
                                    );
                                }
                                ?>
                                <input type="file" name="employee_photo" accept="image/*" />
                                <p class="description">
                                    <?php esc_html_e( 'JPEG/PNG. Optional. Shown on kiosk/web confirmation.', 'sfs-hr' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

            </div><!-- /.sfs-hr-emp-edit-layout -->

            <?php submit_button( __( 'Save Changes', 'sfs-hr' ) ); ?>
        </form>
        <!-- END MAIN FORM -->

        <!-- Standalone QR action forms (AFTER the main form) -->
        <div style="margin-top:16px;display:flex;gap:12px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="sfs_hr_regen_qr" />
                <input type="hidden" name="id" value="<?php echo (int) $emp['id']; ?>" />
                <?php wp_nonce_field( 'sfs_hr_regen_qr_' . (int) $emp['id'], '_sfsqr_regen' ); ?>
                <?php submit_button(
                    __( 'Regenerate QR Token', 'sfs-hr' ),
                    'secondary',
                    '',
                    false,
                    [ 'onclick' => "return confirm('Regenerate token? Old QR codes will stop working.');" ]
                ); ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="sfs_hr_toggle_qr" />
                <input type="hidden" name="id" value="<?php echo (int) $emp['id']; ?>" />
                <input type="hidden" name="new" value="<?php echo $qr_enabled ? '0' : '1'; ?>" />
                <?php wp_nonce_field( 'sfs_hr_toggle_qr_' . (int) $emp['id'], '_sfsqr_toggle' ); ?>
                <?php submit_button(
                    $qr_enabled ? __( 'Disable QR', 'sfs-hr' ) : __( 'Enable QR', 'sfs-hr' ),
                    $qr_enabled ? 'delete' : 'primary',
                    '',
                    false
                ); ?>
            </form>
        </div>
    </div>
    <?php
}



    public function handle_save_edit(): void {
    Helpers::require_cap('sfs_hr.manage');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    check_admin_referer('sfs_hr_save_edit_'.$id);
    if ( $id <= 0 ) {
        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=id') );
        exit;
    }

    $payload = [];

    $fields = [
        'employee_code','first_name','last_name','email','phone','position','status',
        'hired_at','base_salary','national_id','national_id_expiry','passport_no','passport_expiry',
        'emergency_contact_name','emergency_contact_phone',

        'visa_number','visa_expiry',
        'nationality','marital_status','date_of_birth',
        'work_location','contract_type','contract_start_date','contract_end_date','probation_end_date','entry_date_ksa',
        'residence_profession','sponsor_name','sponsor_id',
        'driving_license_number','driving_license_expiry',
        'gosi_salary',
    ];

    $allowed_status = ['active','inactive','terminated'];

    foreach ( $fields as $f ) {
        $val = isset($_POST[$f]) ? $_POST[$f] : '';

        if ( $f === 'email' ) {
            $val = sanitize_email( $val );
        } else {
            $val = sanitize_text_field( $val );
        }

        if ( $f === 'status' && ! in_array( $val, $allowed_status, true ) ) {
            $val = 'active';
        }

        if ( $val === '' ) {
            $val = null;
        }

        $payload[ $f ] = $val;
    }

    // Driving license checkbox (separate from loop)
    $payload['driving_license_has'] = ! empty( $_POST['driving_license_has'] ) ? 1 : 0;

    // Department
    $dept_in = isset($_POST['dept_id']) ? $_POST['dept_id'] : '';
    $payload['dept_id'] = $this->validate_dept_id($dept_in);

    // Gender
    $gender_in = isset($_POST['gender']) ? sanitize_text_field($_POST['gender']) : '';
    $payload['gender'] = in_array(strtolower($gender_in), ['male','female'], true) ? strtolower($gender_in) : null;

    // Handle photo upload if present
    if ( ! empty($_FILES['employee_photo']['name']) ) {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $attach_id = media_handle_upload('employee_photo', 0);
        if ( ! is_wp_error($attach_id) ) {
            $payload['photo_id'] = (int) $attach_id;
        }
    }

    $payload['updated_at'] = Helpers::now_mysql();

    global $wpdb;
    $table = $wpdb->prefix.'sfs_hr_employees';
    $wpdb->update($table, $payload, ['id'=>$id]);
           // Attendance default shift mapping (optional change from edit screen)
    $shift_id_in = isset( $_POST['attendance_shift_id'] ) ? (int) $_POST['attendance_shift_id'] : 0;
    $shift_id    = $this->validate_shift_id( $shift_id_in );

    if ( $shift_id ) {
        $map_table = $wpdb->prefix . 'sfs_hr_attendance_emp_shifts';

        $map_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name   = %s",
                $map_table
            )
        );

        if ( $map_exists ) {
            $start_raw = isset( $_POST['attendance_shift_start'] )
                ? sanitize_text_field( wp_unslash( $_POST['attendance_shift_start'] ) )
                : '';

            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_raw ) ) {
                $start_raw = wp_date( 'Y-m-d' );
            }

            // Avoid exact duplicate for same date+shift.
            $already = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$map_table}
                     WHERE employee_id=%d AND shift_id=%d AND start_date=%s
                     LIMIT 1",
                    $id,
                    $shift_id,
                    $start_raw
                )
            );

            if ( ! $already ) {
                $wpdb->insert(
                    $map_table,
                    [
                        'employee_id' => $id,
                        'shift_id'    => $shift_id,
                        'start_date'  => $start_raw,
                    ]
                );

                // Optional debug:
                // if ( $wpdb->last_error ) error_log('[SFS HR] emp_shift insert error (edit): ' . $wpdb->last_error);
            }
        }
    }



    // Redirect
    $from_profile = ! empty( $_POST['from_profile'] );

    if ( $from_profile ) {
        $ym = isset( $_POST['ym'] ) ? sanitize_text_field( $_POST['ym'] ) : '';
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $ym ) ) {
            $ym = '';
        }

        $args = [
            'page'        => 'sfs-hr-employee-profile',
            'employee_id' => $id,
            'mode'        => 'view',
            'ok'          => 'updated',
        ];
        if ( $ym ) {
            $args['ym'] = $ym;
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    // Default: back to classic Employees edit screen
    wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-employees&action=edit&id=' . $id . '&ok=updated' ) );
    exit;
}


    public function handle_regen_qr(): void {
        Helpers::require_cap('sfs_hr.manage');
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id<=0) wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=id') );
        check_admin_referer('sfs_hr_regen_qr_'.$id, '_sfsqr_regen');

        global $wpdb; $t = $wpdb->prefix.'sfs_hr_employees';
        $tok = bin2hex(random_bytes(24));
        $wpdb->update($t, [
            'qr_token'      => $tok,
            'qr_updated_at' => Helpers::now_mysql(),
        ], ['id'=>$id]);

        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&action=edit&id='.$id.'&ok=qrregen') ); exit;
    }

    public function handle_toggle_qr(): void {
        Helpers::require_cap('sfs_hr.manage');
        $id  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $new = isset($_POST['new']) ? (int)$_POST['new'] : 0;
        if ($id<=0) wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=id') );
        check_admin_referer('sfs_hr_toggle_qr_'.$id, '_sfsqr_toggle');

        global $wpdb; $t = $wpdb->prefix.'sfs_hr_employees';
        $wpdb->update($t, [
            'qr_enabled'    => $new ? 1 : 0,
            'qr_updated_at' => Helpers::now_mysql(),
        ], ['id'=>$id]);

        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&action=edit&id='.$id.'&ok=qrtoggle') ); exit;
    }
    
    public function handle_download_qr_card(): void {
    // Only HR managers can download cards
    Helpers::require_cap( 'sfs_hr.manage' );

    $id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
    if ( $id <= 0 ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-employees&err=id' ) );
        exit;
    }

    // Nonce check
    check_admin_referer( 'sfs_hr_download_qr_card_' . $id, '_sfsqr_download' );

    global $wpdb;
    $table = $wpdb->prefix . 'sfs_hr_employees';

    // Fetch minimal data needed
    $emp = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, first_name, last_name, employee_code, qr_enabled FROM {$table} WHERE id=%d",
            $id
        ),
        ARRAY_A
    );

    if ( ! $emp ) {
        wp_die( esc_html__( 'Employee not found.', 'sfs-hr' ) );
    }

    if ( isset( $emp['qr_enabled'] ) && (int) $emp['qr_enabled'] !== 1 ) {
        wp_die( esc_html__( 'QR is disabled for this employee.', 'sfs-hr' ) );
    }

    // Ensure token and build QR URL (same logic used in edit form)
    $qr_token   = $this->ensure_qr_token( (int) $emp['id'] );
    $qr_url_raw = $this->qr_payload_url( (int) $emp['id'], $qr_token );

    // QR service URL (PNG)
    $qr_png_url = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=' . rawurlencode( $qr_url_raw );

    // Fetch PNG and embed as base64
    $qr_data_uri = '';
    $response    = wp_remote_get( $qr_png_url, [ 'timeout' => 10 ] );

    if ( ! is_wp_error( $response ) ) {
        $body = wp_remote_retrieve_body( $response );
        if ( ! empty( $body ) ) {
            $qr_base64   = base64_encode( $body );
            $qr_data_uri = 'data:image/png;base64,' . $qr_base64;
        }
    }

    // Fallback: if we somehow failed to get PNG, at least keep external URL
    if ( $qr_data_uri === '' ) {
        $qr_data_uri = esc_url( $qr_png_url );
    }

    // Employee name & code
    $first = trim( (string) ( $emp['first_name'] ?? '' ) );
    $last  = trim( (string) ( $emp['last_name'] ?? '' ) );
    $name  = trim( $first . ' ' . $last );
    $code  = isset( $emp['employee_code'] ) ? (string) $emp['employee_code'] : '';

    // ---- Name wrapping (max 2 lines) ----
    $max_chars_per_line = 18; // tweak if needed
    $name_lines         = [];

    if ( $name !== '' ) {
        $wrapped    = wordwrap( $name, $max_chars_per_line, "\n", true );
        $name_lines = explode( "\n", $wrapped );
        $name_lines = array_slice( $name_lines, 0, 2 ); // max 2 lines
    }

    $name_line1      = $name_lines[0] ?? '';
    $name_line2      = $name_lines[1] ?? '';
    $has_second_line = ( $name_line2 !== '' );
    $has_name        = ( $name_line1 !== '' );

    $name_line1_svg = esc_html( $name_line1 );
    $name_line2_svg = esc_html( $name_line2 );

    // Positions
    $name_y  = 12;                          // first line Y
    $code_y  = $has_second_line ? 24 : 20;  // move code down if 2 lines
    $qr_y    = $code !== '' ? $code_y + 6 : ( $has_second_line ? 24 : 18 ) + 6;

    // Sanitize for SVG output
    $code_svg   = esc_html( $code );
    $code_label = esc_html__( 'Code:', 'sfs-hr' );

    // Kill any previous buffered output to avoid junk before SVG
    if ( function_exists( 'ob_get_level' ) ) {
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
    }

    // Send as downloadable SVG (54mm x 86mm, portrait)
    nocache_headers();
    header( 'Content-Type: image/svg+xml; charset=UTF-8' );
    header(
        'Content-Disposition: attachment; filename="employee-qr-card-' . (int) $emp['id'] . '.svg"'
    );

    // SVG with xlink namespace
    echo '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="54mm" height="86mm" viewBox="0 0 54 86">' . "\n";

    // Border + white background (1px-ish border; SVG units)
    echo '  <rect x="0.5" y="0.5" width="53" height="85" fill="#ffffff" stroke="#000000" stroke-width="1" />' . "\n";

    // Name (one or two lines, centered)
    if ( $has_name ) {
        echo '  <text x="27" y="' . $name_y . '" text-anchor="middle" font-family="sans-serif" font-size="4.5" font-weight="bold">' . "\n";
        echo '    <tspan x="27" dy="0">' . $name_line1_svg . '</tspan>' . "\n";
        if ( $has_second_line ) {
            echo '    <tspan x="27" dy="5">' . $name_line2_svg . '</tspan>' . "\n";
        }
        echo '  </text>' . "\n";
    }

    // Optional employee code centered below name (if exists)
    if ( $code !== '' ) {
        echo '  <text x="27" y="' . $code_y . '" text-anchor="middle" font-family="sans-serif" font-size="4">' .
            $code_label . ' ' . $code_svg . '</text>' . "\n";
    }

    // QR code centered below text
    // Use xlink:href; if base64 embedding succeeded, this is a data URI.
    echo '  <image xlink:href="' . $qr_data_uri . '" x="7" y="' . $qr_y . '" width="40" height="40" />' . "\n";

    echo '</svg>';
    exit;
}





    /** Role→Department membership sync */
    public function handle_sync_dept_members(): void {
        if ( ! current_user_can('sfs_hr.manage') ) {
            $dept_id = isset($_POST['dept_id']) ? (int)$_POST['dept_id'] : 0;
            if ($dept_id<=0) wp_die(esc_html__('Invalid department','sfs-hr'));
            if ( ! $this->current_user_is_dept_manager($dept_id) ) wp_die(esc_html__('Permission denied','sfs-hr'));
        }
        $dept_id = isset($_POST['dept_id']) ? (int)$_POST['dept_id'] : 0;
        check_admin_referer('sfs_hr_sync_dept_'.$dept_id);

        global $wpdb;
        $dept_table = $wpdb->prefix.'sfs_hr_departments';
        $emp_table  = $wpdb->prefix.'sfs_hr_employees';

        $dept = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$dept_table} WHERE id=%d AND active=1", $dept_id), ARRAY_A );
        if ( ! $dept ) wp_die(esc_html__('Department not found','sfs-hr'));

        $role = sanitize_text_field($dept['auto_role'] ?? '');
        if ($role === '') wp_die(esc_html__('No auto role mapped for this department','sfs-hr'));

        $users = get_users(['role__in'=>[$role], 'fields'=>['ID','user_email','display_name','user_login']]);
        $assigned = 0; $created = 0;

        foreach ($users as $u) {
            $uid = (int)$u->ID;

            $emp_id = (int)$wpdb->get_var( $wpdb->prepare("SELECT id FROM {$emp_table} WHERE user_id=%d LIMIT 1", $uid) );
            if ( ! $emp_id ) {
                $name = trim($u->display_name); $first=''; $last='';
                if (strpos($name,' ')!==false) { [$first,$last] = explode(' ',$name,2); } else { $first=$name ?: $u->user_login; }
                $wpdb->insert($emp_table, [
                    'user_id'       => $uid,
                    'employee_code' => 'USR-'.$uid,
                    'first_name'    => sanitize_text_field($first),
                    'last_name'     => sanitize_text_field($last),
                    'email'         => sanitize_email($u->user_email),
                    'status'        => 'active',
                    'dept_id'       => $dept_id,
                    'created_at'    => Helpers::now_mysql(),
                    'updated_at'    => Helpers::now_mysql(),
                ]);
                $emp_id = (int)$wpdb->insert_id;
                if ($emp_id) $created++;
            } else {
                $wpdb->update($emp_table, ['dept_id'=>$dept_id, 'updated_at'=>Helpers::now_mysql()], ['id'=>$emp_id]);
            }
            $assigned++;
        }

        wp_safe_redirect( add_query_arg(['page'=>'sfs-hr-employees','ok'=>"deptsync:$assigned:$created"], admin_url('admin.php')) ); exit;
    }

    private function current_user_is_dept_manager(int $dept_id): bool {
        $uid = get_current_user_id();
        if (!$uid) return false;
        global $wpdb;
        $dept_table = $wpdb->prefix.'sfs_hr_departments';
        $id = (int)$wpdb->get_var( $wpdb->prepare("SELECT id FROM {$dept_table} WHERE id=%d AND manager_user_id=%d", $dept_id, $uid) );
        return $id>0;
    }

    private function manager_dept_ids(): array {
        $uid = get_current_user_id();
        if (!$uid) return [];
        global $wpdb;
        $dept_table = $wpdb->prefix.'sfs_hr_departments';
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $dept_table
        ));
        if (!$exists) return [];
        $ids = $wpdb->get_col( $wpdb->prepare("SELECT id FROM {$dept_table} WHERE manager_user_id=%d AND active=1", $uid) );
        return array_map('intval', $ids ?: []);
    }

    private function query_team_employees(array $dept_ids, string $q, int $page, int $per_page): array {
        if (!$dept_ids) return [[], 0];
        global $wpdb; $table = $wpdb->prefix.'sfs_hr_employees';

        $dept_ids = array_values(array_unique(array_map('intval', $dept_ids)));
        $in = implode(',', $dept_ids);

        $where  = "dept_id IN ($in)";
        $params = [];

        if ($q !== '') {
            $like   = '%' . $wpdb->esc_like($q) . '%';
            $where .= " AND (employee_code LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)";
            $params = [$like,$like,$ike,$like]; // typo fix below
        }

        // fix minor typo from the line above:
        if (!empty($params) && count($params)===3) { $params = [$params[0],$params[1],$params[1],$params[2]]; }

        $total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = (int)($params ? $wpdb->get_var($wpdb->prepare($total_sql, ...$params)) : $wpdb->get_var($total_sql));

        $offset = max(0, ($page-1)*$per_page);
        $rows_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($rows_sql, ...array_merge($params, [$per_page, $offset])), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($rows_sql, $per_page, $offset), ARRAY_A);

        return [$rows, $total];
    }

    public function render_my_team(): void {
        Helpers::require_cap('sfs_hr.leave.review');
echo '<div class="wrap sfs-hr-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'Employees', 'sfs-hr' ) . '</h1>';
    Helpers::render_admin_nav();
    echo '<hr class="wp-header-end" />';
    
        $dept_ids = $this->manager_dept_ids();
        if (!$dept_ids) {
            echo '<div class="wrap"><h1>'.esc_html__('My Team','sfs-hr').'</h1>';
            echo '<p>'.esc_html__('No managed departments found for your account.','sfs-hr').'</p></div>';
            return;
        }

        $q        = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $page     = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = isset($_GET['per_page']) ? max(5, intval($_GET['per_page'])) : 20;

        list($rows,$total) = $this->query_team_employees($dept_ids, $q, $page, $per_page);
        $pages = max(1, (int)ceil($total / $per_page));

        $dept_map = $this->departments_map();

        ?>
        <div class="wrap">
          <h1><?php echo esc_html__('My Team','sfs-hr'); ?></h1>
          <form method="get" style="margin:10px 0;">
            <input type="hidden" name="page" value="sfs-hr-my-team" />
            <input type="search" name="s" value="<?php echo esc_attr($q); ?>" placeholder="<?php echo esc_attr__('Search name/email/code','sfs-hr'); ?>"/>
            <select name="per_page">
              <?php foreach ([10,20,50,100] as $pp): ?>
                <option value="<?php echo (int)$pp; ?>" <?php selected($per_page,$pp); ?>><?php echo (int)$pp; ?>/page</option>
              <?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter','sfs-hr'),'secondary','',false); ?>
          </form>

          <table class="widefat striped">
            <thead><tr>
              <th><?php esc_html_e('ID','sfs-hr'); ?></th>
              <th><?php esc_html_e('Code','sfs-hr'); ?></th>
              <th><?php esc_html_e('Name','sfs-hr'); ?></th>
              <th><?php esc_html_e('Email','sfs-hr'); ?></th>
              <th><?php esc_html_e('Department','sfs-hr'); ?></th>
              <th><?php esc_html_e('Position','sfs-hr'); ?></th>
              <th><?php esc_html_e('Status','sfs-hr'); ?></th>
              <th><?php esc_html_e('WP User','sfs-hr'); ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="8"><?php esc_html_e('No employees in your departments.','sfs-hr'); ?></td></tr>
            <?php else:
              foreach ($rows as $r):
                $name     = trim(($r['first_name']??'').' '.($r['last_name']??''));
                $status   = $r['status'];
                $dept_name = empty($r['dept_id'])
                    ? __('General','sfs-hr')
                    : ($dept_map[(int)$r['dept_id']] ?? ('#'.(int)$r['dept_id']));
            ?>
              <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><code><?php echo esc_html($r['employee_code']); ?></code></td>
                <td><?php echo esc_html($name); ?></td>
                <td><?php echo esc_html($r['email']); ?></td>
                <td><?php echo esc_html($dept_name); ?></td>
                <td><?php echo esc_html($r['position']); ?></td>
                <td><span class="sfs-hr-badge status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
                <td><?php echo $r['user_id'] ? '<code>'.(int)$r['user_id'].'</code>' : '&ndash;'; ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>

          <div style="margin:10px 0;">
            <?php for($i=1;$i<=$pages;$i++): ?>
              <?php if ($i === $page): ?>
                <span class="tablenav-pages-navspan" style="margin-right:6px;"><?php echo (int)$i; ?></span>
              <?php else: ?>
                <a href="<?php echo esc_url( add_query_arg(['paged'=>$i,'per_page'=>$per_page,'s'=>$q], admin_url('admin.php?page=sfs-hr-my-team')) ); ?>" style="margin-right:6px;"><?php echo (int)$i; ?></a>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
        </div>
        <?php
    }
}
