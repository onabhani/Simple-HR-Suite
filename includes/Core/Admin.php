<?php
namespace SFS\HR\Core;
if ( ! defined('ABSPATH') ) { exit; }

// Load Admin services
require_once __DIR__ . '/Admin/Services/ReportsService.php';

use SFS\HR\Core\Admin\Services\ReportsService;

class Admin {
    public function hooks(): void {
    add_action( 'admin_menu',  [ $this, 'menu' ] );
    add_action( 'admin_init',  [ $this, 'maybe_add_employee_photo_column' ] );
    add_action( 'admin_init',  [ $this, 'maybe_install_qr_cols' ] );
    add_action( 'admin_init',  [ $this, 'maybe_install_employee_extra_cols' ] );
    add_action( 'admin_init',  [ $this, 'handle_recreate_page' ] );
    add_action( 'admin_head',  [ $this, 'remove_menu_separator_css' ] );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );

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
    add_action( 'admin_post_sfs_hr_save_notification_settings', [ $this, 'handle_save_notification_settings' ] );
    add_action( 'admin_post_sfs_hr_document_settings_save', [ $this, 'handle_document_settings_save' ] );
    add_action( 'admin_post_sfs_hr_frontend_settings', [ $this, 'handle_frontend_settings' ] );
    add_action( 'admin_post_sfs_hr_save_employees_settings', [ $this, 'handle_save_employees_settings' ] );

    // Role→Department sync
    add_action( 'admin_post_sfs_hr_sync_dept_members',  [ $this, 'handle_sync_dept_members' ] );
}


    /**
     * Enqueue admin styles on HR Suite pages.
     */
    public function enqueue_admin_styles( string $hook ): void {
        // Only load on our plugin's admin pages.
        $screen = get_current_screen();
        $is_hr_page = $screen && ( strpos( $screen->id, 'sfs-hr' ) !== false || strpos( $screen->id, 'sfs_hr' ) !== false );
        $is_hr_hook = strpos( $hook, 'sfs-hr' ) !== false || strpos( $hook, 'sfs_hr' ) !== false;
        if ( $is_hr_page || $is_hr_hook ) {
            $admin_css = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG )
                ? 'assets/admin/admin-styles.css'
                : 'assets/admin/admin-styles.min.css';
            wp_enqueue_style(
                'sfs-hr-admin',
                SFS_HR_URL . $admin_css,
                [],
                SFS_HR_VER
            );

            // Chart.js for dashboard and performance pages (loaded once, deferred)
            if ( $is_hr_hook && strpos( $hook, 'sfs-hr-employees' ) === false && strpos( $hook, 'sfs_hr_employees' ) === false ) {
                wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true );
            }
        }
    }

    /**
     * Handle the "Create Now" button from the Pages settings tab.
     */
    public function handle_recreate_page(): void {
        if ( empty( $_POST['sfs_hr_recreate_page'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'sfs_hr_recreate_page', '_sfs_hr_nonce' );

        $option_key  = sanitize_key( $_POST['sfs_hr_recreate_page'] );
        $definitions = \SFS\HR\Install\Pages::get_definitions();

        if ( isset( $definitions[ $option_key ] ) ) {
            delete_option( $option_key );
            \SFS\HR\Install\Pages::create();
        }

        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-settings&tab=pages' ) );
        exit;
    }

    /**
     * Idempotent column-add helper: adds a column only if it does not already exist.
     *
     * @param string $table  Full (prefixed) table name.
     * @param string $column Column name to check/add.
     * @param string $sql    Full ALTER TABLE statement to run when column is absent.
     */
    private function add_column_safe(string $table, string $column, string $sql): void {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));
        if (!$exists) {
            $wpdb->query($sql);
        }
    }

    public function maybe_add_employee_photo_column(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';
        $this->add_column_safe(
            $table,
            'photo_id',
            "ALTER TABLE `{$table}` ADD COLUMN photo_id BIGINT UNSIGNED NULL AFTER user_id"
        );
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

        // Reports submenu
        add_submenu_page(
            'sfs-hr',
            __('Reports','sfs-hr'),
            __('Reports','sfs-hr'),
            'sfs_hr.manage',
            'sfs-hr-reports',
            [$this, 'render_reports']
        );

        // Add Settings submenu (last item)
        add_submenu_page(
            'sfs-hr',
            __('Settings','sfs-hr'),
            __('Settings','sfs-hr'),
            'manage_options',
            'sfs-hr-settings',
            [$this, 'render_settings']
        );

    }

    public function remove_menu_separator_css(): void {
        echo '<style>
            /* Remove separator after HR menu */
            #adminmenu li.toplevel_page_sfs-hr + li.wp-menu-separator {
                display: none !important;
            }

            /* Responsive table wrapper for mobile */
            .sfs-hr-table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .sfs-hr-table-responsive table {
                min-width: 600px;
            }
            .sfs-hr-table-responsive th {
                white-space: nowrap;
            }
            @media (max-width: 782px) {
                .sfs-hr-table-responsive {
                    margin: 0 -12px;
                    padding: 0 12px;
                }
            }
        </style>';
    }

    public function maybe_install_qr_cols(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_employees';

        $table_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $t
        ));
        if (!$table_exists) return;

        $this->add_column_safe($t, 'qr_token',      "ALTER TABLE `{$t}` ADD COLUMN qr_token VARCHAR(64) NULL");
        $this->add_column_safe($t, 'qr_enabled',    "ALTER TABLE `{$t}` ADD COLUMN qr_enabled TINYINT(1) NOT NULL DEFAULT 1");
        $this->add_column_safe($t, 'qr_updated_at', "ALTER TABLE `{$t}` ADD COLUMN qr_updated_at DATETIME NULL");
    }
    
    public function maybe_install_employee_extra_cols(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_employees';

        $table_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $t
        ));
        if (!$table_exists) {
            return;
        }

        $cols = [
            'nationality'            => "ALTER TABLE `{$t}` ADD COLUMN nationality VARCHAR(100) NULL AFTER gender",
            'marital_status'         => "ALTER TABLE `{$t}` ADD COLUMN marital_status VARCHAR(50) NULL AFTER nationality",
            'date_of_birth'          => "ALTER TABLE `{$t}` ADD COLUMN date_of_birth DATE NULL AFTER marital_status",

            'work_location'          => "ALTER TABLE `{$t}` ADD COLUMN work_location VARCHAR(191) NULL AFTER position",
            'contract_type'          => "ALTER TABLE `{$t}` ADD COLUMN contract_type VARCHAR(50) NULL AFTER work_location",
            'contract_start_date'    => "ALTER TABLE `{$t}` ADD COLUMN contract_start_date DATE NULL AFTER contract_type",
            'contract_end_date'      => "ALTER TABLE `{$t}` ADD COLUMN contract_end_date DATE NULL AFTER contract_start_date",
            'probation_end_date'     => "ALTER TABLE `{$t}` ADD COLUMN probation_end_date DATE NULL AFTER contract_end_date",

            'entry_date_ksa'         => "ALTER TABLE `{$t}` ADD COLUMN entry_date_ksa DATE NULL AFTER hired_at",
            'residence_profession'   => "ALTER TABLE `{$t}` ADD COLUMN residence_profession VARCHAR(191) NULL AFTER entry_date_ksa",

            'sponsor_name'           => "ALTER TABLE `{$t}` ADD COLUMN sponsor_name VARCHAR(191) NULL AFTER residence_profession",
            'sponsor_id'             => "ALTER TABLE `{$t}` ADD COLUMN sponsor_id VARCHAR(50) NULL AFTER sponsor_name",

            'visa_number'            => "ALTER TABLE `{$t}` ADD COLUMN visa_number VARCHAR(50) NULL AFTER passport_no",
            'visa_expiry'            => "ALTER TABLE `{$t}` ADD COLUMN visa_expiry DATE NULL AFTER visa_number",

            'driving_license_has'    => "ALTER TABLE `{$t}` ADD COLUMN driving_license_has TINYINT(1) NOT NULL DEFAULT 0 AFTER visa_expiry",
            'driving_license_number' => "ALTER TABLE `{$t}` ADD COLUMN driving_license_number VARCHAR(50) NULL AFTER driving_license_has",
            'driving_license_expiry' => "ALTER TABLE `{$t}` ADD COLUMN driving_license_expiry DATE NULL AFTER driving_license_number",

            'gosi_salary'            => "ALTER TABLE `{$t}` ADD COLUMN gosi_salary DECIMAL(10,2) NULL AFTER base_salary",
        ];

        foreach ($cols as $col => $sql) {
            $this->add_column_safe($t, $col, $sql);
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

    // --- Contracts expiring within notice period ---
    $notice_period       = (int) get_option( 'sfs_hr_resignation_notice_period', 30 );
    $contract_threshold  = $notice_period + 5;
    $contract_cutoff     = date( 'Y-m-d', strtotime( "+{$contract_threshold} days", strtotime( $today ) ) );
    $expiring_contracts  = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$emp_t}
         WHERE status = 'active'
           AND contract_end_date IS NOT NULL
           AND contract_end_date BETWEEN %s AND %s",
        $today,
        $contract_cutoff
    ) );
    $expired_contracts   = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$emp_t}
         WHERE status = 'active'
           AND contract_end_date IS NOT NULL
           AND contract_end_date < %s",
        $today
    ) );
    $total_contract_alerts = $expiring_contracts + $expired_contracts;

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
    echo '<h1>' . esc_html__( 'HR Dashboard', 'sfs-hr' ) . '</h1>';

    echo '<hr class="wp-header-end" />';

    // Hide generic WP notices here, keep our own .sfs-hr-notice
    echo '<style>
        #wpbody-content .notice:not(.sfs-hr-notice) { display: none; }
    </style>';

    // Dashboard layout styles
    echo '<style>
        .sfs-hr-wrap {
            padding-right: 20px;
        }
        .sfs-hr-wrap .sfs-hr-dashboard-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 16px;
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
        /* Quick Access styles */
        .sfs-hr-wrap .sfs-hr-quick-access-section {
            margin-bottom: 24px;
        }
        .sfs-hr-wrap .sfs-hr-quick-access-section h2 {
            font-size: 14px;
            font-weight: 600;
            color: #1d2327;
            margin: 0 0 12px 0;
            padding: 0;
        }
        .sfs-hr-wrap .sfs-hr-quick-access-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 10px;
        }
        .sfs-hr-wrap .sfs-hr-nav-card {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            padding: 12px 14px;
            text-decoration: none;
            color: #1d2327;
            transition: all 0.15s ease;
        }
        .sfs-hr-wrap .sfs-hr-nav-card:hover {
            border-color: #2271b1;
            box-shadow: 0 2px 8px rgba(34, 113, 177, 0.15);
            transform: translateY(-1px);
        }
        .sfs-hr-wrap .sfs-hr-nav-card .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
            color: #2271b1;
        }
        .sfs-hr-wrap .sfs-hr-nav-card span:not(.dashicons) {
            font-size: 13px;
            font-weight: 500;
        }
        @media (max-width: 782px) {
            .sfs-hr-wrap .sfs-hr-quick-access-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>';

    // === GOVERNMENT SUPPORT REMINDER BANNER ===
    $this->render_gov_support_dashboard_banner( $emp_t );

    // === QUICK ACCESS NAVIGATION SECTION (at the top) ===
    echo '<div class="sfs-hr-quick-access-section">';
    echo '<h2>' . esc_html__( 'Quick Access', 'sfs-hr' ) . '</h2>';
    echo '<div class="sfs-hr-quick-access-grid">';

    $nav_items = [
        [
            'page'  => 'sfs-hr-employees',
            'icon'  => 'dashicons-id-alt',
            'label' => __( 'Employees', 'sfs-hr' ),
            'cap'   => 'sfs_hr.manage',
        ],
        [
            'page'  => 'sfs-hr-departments',
            'icon'  => 'dashicons-networking',
            'label' => __( 'Departments', 'sfs-hr' ),
            'cap'   => 'sfs_hr.view',
        ],
        [
            'page'  => 'sfs-hr-leave-requests',
            'icon'  => 'dashicons-calendar-alt',
            'label' => __( 'Leave', 'sfs-hr' ),
            'cap'   => 'sfs_hr.leave.review',
        ],
        [
            'page'  => 'sfs_hr_attendance',
            'icon'  => 'dashicons-clock',
            'label' => __( 'Attendance', 'sfs-hr' ),
            'cap'   => 'sfs_hr.view',
        ],
        [
            'page'  => 'sfs-hr-workforce-status',
            'icon'  => 'dashicons-chart-area',
            'label' => __( 'Workforce Status', 'sfs-hr' ),
            'cap'   => 'sfs_hr.view',
        ],
        [
            'page'  => 'sfs-hr-loans',
            'icon'  => 'dashicons-money-alt',
            'label' => __( 'Loans', 'sfs-hr' ),
            'cap'   => 'sfs_hr.view',
        ],
        [
            'page'  => 'sfs-hr-assets',
            'icon'  => 'dashicons-laptop',
            'label' => __( 'Assets', 'sfs-hr' ),
            'cap'   => 'sfs_hr.view',
        ],
        [
            'page'  => 'sfs-hr-lifecycle',
            'icon'  => 'dashicons-businessperson',
            'label' => __( 'Employee Lifecycle', 'sfs-hr' ),
            'cap'   => 'sfs_hr.view',
        ],
        [
            'page'  => 'sfs-hr-payroll',
            'icon'  => 'dashicons-money-alt',
            'label' => __( 'Payroll', 'sfs-hr' ),
            'cap'   => 'sfs_hr.view',
        ],
        [
            'page'  => 'sfs-hr-my-profile',
            'icon'  => 'dashicons-admin-users',
            'label' => __( 'My Profile', 'sfs-hr' ),
            'cap'   => 'sfs_hr.view',
        ],
        [
            'page'  => 'sfs-hr-my-team',
            'icon'  => 'dashicons-groups',
            'label' => __( 'My Team', 'sfs-hr' ),
            'cap'   => 'sfs_hr.leave.review',
        ],
    ];

    foreach ( $nav_items as $item ) {
        if ( ! current_user_can( $item['cap'] ) ) {
            continue;
        }
        $url = admin_url( 'admin.php?page=' . $item['page'] );
        echo '<a class="sfs-hr-nav-card" href="' . esc_url( $url ) . '">';
        echo '<span class="dashicons ' . esc_attr( $item['icon'] ) . '"></span>';
        echo '<span>' . esc_html( $item['label'] ) . '</span>';
        echo '</a>';
    }

    echo '</div>'; // .sfs-hr-quick-access-grid
    echo '</div>'; // .sfs-hr-quick-access-section

    // === APPROVAL CARDS SECTION ===
    $has_approval_cards = false;
    ob_start(); // Buffer approval cards to check if any exist

    // LOANS: Pending approvals (only for assigned GM/Finance approvers, excluding own requests)
    $loans_t = $wpdb->prefix . 'sfs_hr_loans';
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $loans_t ) ) ) {
        // Use LoansModule methods to check if user is actually assigned as an approver
        $can_approve_gm = \SFS\HR\Modules\Loans\LoansModule::current_user_can_approve_as_gm();
        $can_approve_finance = \SFS\HR\Modules\Loans\LoansModule::current_user_can_approve_as_finance();

        // Get current user's employee_id to exclude own requests
        $current_user_id = get_current_user_id();
        $current_employee_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$emp_t} WHERE user_id = %d AND status = 'active'",
            $current_user_id
        ) );

        $status_conditions = [];
        if ( $can_approve_gm ) {
            $status_conditions[] = "'pending_gm'";
        }
        if ( $can_approve_finance ) {
            $status_conditions[] = "'pending_finance'";
        }

        if ( ! empty( $status_conditions ) ) {
            $status_list = implode( ',', $status_conditions );
            // Exclude own loan requests
            $exclude_clause = $current_employee_id > 0 ? $wpdb->prepare( " AND employee_id != %d", $current_employee_id ) : "";
            $pending_loans = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$loans_t} WHERE status IN ({$status_list})" . $exclude_clause
            );

            if ( $pending_loans > 0 ) {
                $has_approval_cards = true;
                echo '<a class="sfs-hr-card sfs-hr-approval-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-loans&tab=loans' ) ) . '">';
                echo '<h2>' . esc_html__( 'Pending Loans', 'sfs-hr' ) . '</h2>';
                echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $pending_loans ) ) . '</div>';
                echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Awaiting your approval', 'sfs-hr' ) . '</div>';
                echo '</a>';
            }
        }
    }

    // LEAVES: Pending approvals (only for assigned approvers, excluding own requests)
    // Position-based: only show if user is assigned as dept manager, GM, HR, or Finance
    $user_id = get_current_user_id();
    $pending_leaves_count = 0;

    // Get departments managed by current user (position-based)
    $managed_dept_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$dept_t} WHERE manager_user_id = %d",
        $user_id
    ) );
    $is_dept_manager = ! empty( $managed_dept_ids );

    // GM: Check if user is the assigned GM (position-based)
    $gm_user_id = (int) get_option( 'sfs_hr_leave_gm_approver', 0 );
    if ( ! $gm_user_id ) {
        // Fallback to Loans setting for backward compatibility
        $loan_settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();
        $gm_user_ids = $loan_settings['gm_user_ids'] ?? [];
        $gm_user_id = ! empty( $gm_user_ids ) ? (int) $gm_user_ids[0] : 0;
    }
    $is_gm = ( $gm_user_id > 0 && $user_id === $gm_user_id );

    // HR: Check if user is in the assigned HR approvers list (position-based)
    $hr_user_ids = (array) get_option( 'sfs_hr_leave_hr_approvers', [] );
    $is_hr = ! empty( $hr_user_ids ) && in_array( $user_id, $hr_user_ids, true );

    // Finance: position-based
    $finance_approver_id = (int) get_option('sfs_hr_leave_finance_approver', 0);
    $is_finance = $finance_approver_id > 0 && $finance_approver_id === $user_id;

    // Only proceed if user has any position-based role
    if ( $is_dept_manager || $is_gm || $is_hr || $is_finance ) {

        // Department managers: Count level 1 requests in their departments (for non-manager employees)
        // Exclude own requests (can't approve self)
        if ( $is_dept_manager ) {
            $placeholders = implode( ',', array_fill( 0, count( $managed_dept_ids ), '%d' ) );
            $pending_leaves_count += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$req_t} r
                 INNER JOIN {$emp_t} e ON e.id = r.employee_id
                 INNER JOIN {$dept_t} d ON d.id = e.dept_id
                 WHERE r.status = 'pending'
                 AND (r.approval_level IS NULL OR r.approval_level <= 1)
                 AND e.dept_id IN ({$placeholders})
                 AND (e.user_id IS NULL OR e.user_id != d.manager_user_id)
                 AND (e.user_id IS NULL OR e.user_id != %d)",
                ...array_merge( $managed_dept_ids, [ $user_id ] )
            ) );
        }

        // GM: Count level 1 requests where the employee IS a department manager
        // Exclude own requests
        if ( $is_gm ) {
            $pending_leaves_count += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$req_t} r
                 INNER JOIN {$emp_t} e ON e.id = r.employee_id
                 INNER JOIN {$dept_t} d ON d.id = e.dept_id
                 WHERE r.status = 'pending'
                 AND (r.approval_level IS NULL OR r.approval_level <= 1)
                 AND e.user_id = d.manager_user_id
                 AND e.user_id != %d",
                $user_id
            ) );
        }

        // HR: Count level 2 requests (after GM approved for dept manager leaves)
        // Exclude own requests
        if ( $is_hr ) {
            $pending_leaves_count += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$req_t} r
                 INNER JOIN {$emp_t} e ON e.id = r.employee_id
                 WHERE r.status = 'pending' AND r.approval_level = 2
                 AND (e.user_id IS NULL OR e.user_id != %d)",
                $user_id
            ) );

            // Also count level 1 requests from departments without a manager (fallback to HR)
            // Exclude own requests
            $pending_leaves_count += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$req_t} r
                 INNER JOIN {$emp_t} e ON e.id = r.employee_id
                 INNER JOIN {$dept_t} d ON d.id = e.dept_id
                 WHERE r.status = 'pending'
                 AND (r.approval_level IS NULL OR r.approval_level <= 1)
                 AND (d.manager_user_id IS NULL OR d.manager_user_id = 0)
                 AND (e.user_id IS NULL OR e.user_id != %d)",
                $user_id
            ) );
        }

        // Finance: Count level 3+ requests
        // Exclude own requests
        if ( $is_finance ) {
            $pending_leaves_count += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$req_t} r
                 INNER JOIN {$emp_t} e ON e.id = r.employee_id
                 WHERE r.status = 'pending' AND r.approval_level >= 3
                 AND (e.user_id IS NULL OR e.user_id != %d)",
                $user_id
            ) );
        }

        if ( $pending_leaves_count > 0 ) {
            $has_approval_cards = true;
            echo '<a class="sfs-hr-card sfs-hr-approval-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-leave-requests&status=pending' ) ) . '">';
            echo '<h2>' . esc_html__( 'Pending Leave Requests', 'sfs-hr' ) . '</h2>';
            echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $pending_leaves_count ) ) . '</div>';
            echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Awaiting your approval', 'sfs-hr' ) . '</div>';
            echo '</a>';
        }
    }

    // RESIGNATIONS: Pending approvals (only for assigned approvers based on approval_level, excluding own)
    if ( current_user_can('sfs_hr.view') ) {
        $resignations_t = $wpdb->prefix . 'sfs_hr_resignations';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $resignations_t ) ) ) {
            $user_id = get_current_user_id();
            $pending_resignations = 0;

            // Get current user's employee_id to exclude own requests
            $current_employee_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$emp_t} WHERE user_id = %d AND status = 'active'",
                $user_id
            ) );

            // Get departments managed by current user
            $managed_dept_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$dept_t} WHERE manager_user_id = %d",
                $user_id
            ) );

            // Count resignations at level 1 (Dept Manager) in managed departments (exclude own)
            if ( ! empty( $managed_dept_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $managed_dept_ids ), '%d' ) );
                $exclude_own = $current_employee_id > 0 ? $wpdb->prepare( " AND r.employee_id != %d", $current_employee_id ) : "";
                $pending_resignations += (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$resignations_t} r
                     INNER JOIN {$emp_t} e ON e.id = r.employee_id
                     WHERE r.status = 'pending' AND r.approval_level = 1 AND e.dept_id IN ({$placeholders})" . $exclude_own,
                    ...$managed_dept_ids
                ) );
            }

            // Count resignations at level 2 (HR) if user is the assigned HR approver (exclude own)
            $hr_approver_id = (int) get_option( 'sfs_hr_resignation_hr_approver', 0 );
            if ( $hr_approver_id > 0 && $hr_approver_id === $user_id ) {
                $exclude_own = $current_employee_id > 0 ? $wpdb->prepare( " AND employee_id != %d", $current_employee_id ) : "";
                $pending_resignations += (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$resignations_t} WHERE status = 'pending' AND approval_level = 2" . $exclude_own
                );
            }

            // Count resignations at level 3 (Finance) if user is the assigned Finance approver (exclude own)
            $finance_approver_id = (int) get_option( 'sfs_hr_resignation_finance_approver', 0 );
            if ( $finance_approver_id > 0 && $finance_approver_id === $user_id ) {
                $exclude_own = $current_employee_id > 0 ? $wpdb->prepare( " AND employee_id != %d", $current_employee_id ) : "";
                $pending_resignations += (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$resignations_t} WHERE status = 'pending' AND approval_level = 3" . $exclude_own
                );
            }

            if ( $pending_resignations > 0 ) {
                $has_approval_cards = true;
                echo '<a class="sfs-hr-card sfs-hr-approval-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-lifecycle&tab=resignations&status=pending' ) ) . '">';
                echo '<h2>' . esc_html__( 'Pending Resignations', 'sfs-hr' ) . '</h2>';
                echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $pending_resignations ) ) . '</div>';
                echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Awaiting approval', 'sfs-hr' ) . '</div>';
                echo '</a>';
            }
        }
    }

    // EARLY LEAVE: Pending approvals (Admin/GM see all, dept manager sees their department)
    if ( current_user_can('sfs_hr_attendance_view_team') || current_user_can('sfs_hr_attendance_admin') || current_user_can('sfs_hr_loans_gm_approve') ) {
        $early_leave_t = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $early_leave_t ) ) ) {
            $user_id = get_current_user_id();
            $is_admin_or_gm_el = current_user_can('sfs_hr_attendance_admin') || current_user_can('sfs_hr_loans_gm_approve');

            // Get current user's employee_id to exclude own requests
            $own_emp_id_el = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$emp_t} WHERE user_id = %d AND status = 'active'",
                $user_id
            ) );
            $exclude_own_el = $own_emp_id_el > 0 ? $wpdb->prepare( " AND el.employee_id != %d", $own_emp_id_el ) : "";

            $pending_early_leaves = 0;

            if ( $is_admin_or_gm_el ) {
                // Admin/GM: count ALL pending requests (exclude own)
                $pending_early_leaves = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$early_leave_t} el WHERE el.status = 'pending'" . $exclude_own_el
                );
            } else {
                // Department manager: count pending requests from their department employees
                $managed_dept_ids_el = $wpdb->get_col( $wpdb->prepare(
                    "SELECT id FROM {$dept_t} WHERE manager_user_id = %d AND active = 1",
                    $user_id
                ) );
                if ( ! empty( $managed_dept_ids_el ) ) {
                    $ph_el = implode( ',', array_fill( 0, count( $managed_dept_ids_el ), '%d' ) );
                    $pending_early_leaves = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$early_leave_t} el
                         INNER JOIN {$emp_t} e ON e.id = el.employee_id
                         WHERE el.status = 'pending' AND e.dept_id IN ({$ph_el})" . $exclude_own_el,
                        ...$managed_dept_ids_el
                    ) );
                }
            }

            if ( $pending_early_leaves > 0 ) {
                $has_approval_cards = true;
                echo '<a class="sfs-hr-card sfs-hr-approval-card" href="' . esc_url( admin_url( 'admin.php?page=sfs_hr_attendance&tab=early_leave&status=pending' ) ) . '">';
                echo '<h2>' . esc_html__( 'Early Leave Requests', 'sfs-hr' ) . '</h2>';
                echo '<div class="sfs-hr-card-count">' . esc_html( number_format_i18n( $pending_early_leaves ) ) . '</div>';
                echo '<div class="sfs-hr-card-meta">' . esc_html__( 'Awaiting your approval', 'sfs-hr' ) . '</div>';
                echo '</a>';
            }
        }
    }

    $approval_cards_html = ob_get_clean();

    // Only show approval section if there are pending requests
    if ( $has_approval_cards ) {
        echo '<style>
            .sfs-hr-wrap .sfs-hr-approval-section {
                margin-bottom: 24px;
            }
            .sfs-hr-wrap .sfs-hr-approval-section h2 {
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
                margin: 0 0 12px 0;
                padding: 0;
            }
            .sfs-hr-wrap .sfs-hr-approval-grid {
                display: flex;
                flex-wrap: wrap;
                gap: 16px;
            }
            .sfs-hr-wrap .sfs-hr-approval-card {
                border-left: 4px solid #d63638 !important;
                background: linear-gradient(135deg, #fff 0%, #fef8f8 100%);
            }
            .sfs-hr-wrap .sfs-hr-approval-card .sfs-hr-card-count {
                color: #d63638;
            }
        </style>';

        echo '<div class="sfs-hr-approval-section">';
        echo '<h2>' . esc_html__( 'Requests Awaiting Your Approval', 'sfs-hr' ) . '</h2>';
        echo '<div class="sfs-hr-approval-grid">';
        echo $approval_cards_html;
        echo '</div>';
        echo '</div>';
    }

    // === REGULAR DATA CARDS SECTION ===
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
    echo '<a class="sfs-hr-card" href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-leave-requests' ) ) . '">';
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

    // Contracts Expiring
    if ( $total_contract_alerts > 0 ) {
        $contract_card_style = $expired_contracts > 0
            ? ' style="border-left:4px solid #dc2626;background:linear-gradient(135deg,#fff 0%,#fef2f2 100%);"'
            : ( $expiring_contracts > 0
                ? ' style="border-left:4px solid #d97706;background:linear-gradient(135deg,#fff 0%,#fffbeb 100%);"'
                : '' );
        $exit_url = admin_url( 'admin.php?page=sfs-hr-lifecycle&tab=contracts' );
        echo '<a class="sfs-hr-card" href="' . esc_url( $exit_url ) . '"' . $contract_card_style . '>';
        echo '<h2>' . esc_html__( 'Contracts Expiring', 'sfs-hr' ) . '</h2>';
        echo '<div class="sfs-hr-card-count"' . ( $expired_contracts > 0 ? ' style="color:#dc2626;"' : ' style="color:#d97706;"' ) . '>' . esc_html( number_format_i18n( $total_contract_alerts ) ) . '</div>';
        if ( $expired_contracts > 0 ) {
            echo '<div class="sfs-hr-card-meta" style="color:#dc2626;font-weight:600;">' . sprintf(
                esc_html__( '%s already expired', 'sfs-hr' ),
                number_format_i18n( $expired_contracts )
            ) . '</div>';
        }
        if ( $expiring_contracts > 0 ) {
            echo '<div class="sfs-hr-card-meta">' . sprintf(
                /* translators: %1$s = count, %2$s = days */
                esc_html__( '%1$s expiring within %2$s days', 'sfs-hr' ),
                number_format_i18n( $expiring_contracts ),
                number_format_i18n( $contract_threshold )
            ) . '</div>';
        }
        echo '</a>';
    }

    echo '</div>'; // .sfs-hr-dashboard-grid

    // === CONTRACT & DOCUMENT EXPIRY ALERTS SECTION ===
    $this->render_expiry_alerts_section( $wpdb, $emp_t, $today );

    // === OVERTIME ALERTS SECTION ===
    $this->render_overtime_alerts_section( $wpdb, $emp_t, $today );

    // === ANALYTICS CHARTS SECTION ===
    $this->render_analytics_section( $wpdb, $emp_t, $dept_t, $sessions_t, $req_t );

    echo '</div>'; // .wrap
}

/**
 * Render contract and document expiry alerts section
 */
private function render_expiry_alerts_section( $wpdb, string $emp_t, string $today ): void {
    $alerts_30  = $this->get_expiring_items( $wpdb, $emp_t, $today, 30 );
    $alerts_60  = $this->get_expiring_items( $wpdb, $emp_t, $today, 60, 31 );
    $alerts_90  = $this->get_expiring_items( $wpdb, $emp_t, $today, 90, 61 );

    $total_urgent   = count( $alerts_30 );
    $total_soon     = count( $alerts_60 );
    $total_upcoming = count( $alerts_90 );

    if ( $total_urgent + $total_soon + $total_upcoming === 0 ) {
        return; // No alerts to show
    }

    echo '<style>
        .sfs-hr-expiry-section {
            margin-top: 24px;
            padding: 20px;
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .sfs-hr-expiry-section h2 {
            margin: 0 0 16px 0;
            font-size: 16px;
            font-weight: 600;
            color: #1d2327;
        }
        .sfs-hr-expiry-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            border-bottom: 1px solid #dcdcde;
            padding-bottom: 12px;
        }
        .sfs-hr-expiry-tab {
            padding: 8px 16px;
            border: none;
            background: #f6f7f7;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #50575e;
        }
        .sfs-hr-expiry-tab:hover {
            background: #e2e4e7;
        }
        .sfs-hr-expiry-tab.active {
            background: #2271b1;
            color: #fff;
        }
        .sfs-hr-expiry-tab .count {
            background: rgba(0,0,0,.1);
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 4px;
        }
        .sfs-hr-expiry-tab.active .count {
            background: rgba(255,255,255,.2);
        }
        .sfs-hr-expiry-tab.urgent {
            background: #fee2e2;
            color: #b91c1c;
        }
        .sfs-hr-expiry-tab.urgent.active {
            background: #dc2626;
            color: #fff;
        }
        .sfs-hr-expiry-list {
            display: none;
        }
        .sfs-hr-expiry-list.active {
            display: block;
        }
        .sfs-hr-expiry-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .sfs-hr-expiry-table th {
            text-align: start;
            padding: 10px 12px;
            background: #f6f7f7;
            font-weight: 600;
            color: #50575e;
            border-bottom: 1px solid #dcdcde;
        }
        .sfs-hr-expiry-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f1;
        }
        .sfs-hr-expiry-table tr:hover td {
            background: #f9fafb;
        }
        .sfs-hr-expiry-type {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        .sfs-hr-expiry-type.contract { background: #dbeafe; color: #1d4ed8; }
        .sfs-hr-expiry-type.national-id { background: #fef3c7; color: #b45309; }
        .sfs-hr-expiry-type.passport { background: #ede9fe; color: #7c3aed; }
        .sfs-hr-expiry-type.probation { background: #dcfce7; color: #16a34a; }
        .sfs-hr-expiry-days {
            font-weight: 600;
        }
        .sfs-hr-expiry-days.urgent { color: #dc2626; }
        .sfs-hr-expiry-days.soon { color: #d97706; }
        .sfs-hr-expiry-days.normal { color: #2563eb; }
        .sfs-hr-expiry-empty {
            text-align: center;
            padding: 20px;
            color: #787c82;
        }
    </style>';

    echo '<div class="sfs-hr-expiry-section">';
    echo '<h2><span class="dashicons dashicons-warning" style="color:#d97706;margin-right:8px;"></span>' . esc_html__( 'Contract & Document Expiry Alerts', 'sfs-hr' ) . '</h2>';

    echo '<div class="sfs-hr-expiry-tabs">';

    if ( $total_urgent > 0 ) {
        echo '<button type="button" class="sfs-hr-expiry-tab urgent active" data-target="expiry-30">';
        echo esc_html__( 'Within 30 days', 'sfs-hr' );
        echo '<span class="count">' . esc_html( $total_urgent ) . '</span>';
        echo '</button>';
    }

    if ( $total_soon > 0 ) {
        $active = ( $total_urgent === 0 ) ? ' active' : '';
        echo '<button type="button" class="sfs-hr-expiry-tab' . $active . '" data-target="expiry-60">';
        echo esc_html__( '31-60 days', 'sfs-hr' );
        echo '<span class="count">' . esc_html( $total_soon ) . '</span>';
        echo '</button>';
    }

    if ( $total_upcoming > 0 ) {
        $active = ( $total_urgent === 0 && $total_soon === 0 ) ? ' active' : '';
        echo '<button type="button" class="sfs-hr-expiry-tab' . $active . '" data-target="expiry-90">';
        echo esc_html__( '61-90 days', 'sfs-hr' );
        echo '<span class="count">' . esc_html( $total_upcoming ) . '</span>';
        echo '</button>';
    }

    echo '</div>';

    // Render lists
    if ( $total_urgent > 0 ) {
        $this->render_expiry_list( 'expiry-30', $alerts_30, true );
    }
    if ( $total_soon > 0 ) {
        $this->render_expiry_list( 'expiry-60', $alerts_60, $total_urgent === 0 );
    }
    if ( $total_upcoming > 0 ) {
        $this->render_expiry_list( 'expiry-90', $alerts_90, $total_urgent === 0 && $total_soon === 0 );
    }

    echo '</div>';

    // Tab switching JS
    echo '<script>
    (function(){
        var tabs = document.querySelectorAll(".sfs-hr-expiry-tab");
        var lists = document.querySelectorAll(".sfs-hr-expiry-list");
        tabs.forEach(function(tab) {
            tab.addEventListener("click", function() {
                tabs.forEach(function(t) { t.classList.remove("active"); });
                lists.forEach(function(l) { l.classList.remove("active"); });
                this.classList.add("active");
                var target = document.getElementById(this.dataset.target);
                if (target) target.classList.add("active");
            });
        });
    })();
    </script>';
}

/**
 * Get expiring items (contracts, IDs, passports, probation)
 */
private function get_expiring_items( $wpdb, string $emp_t, string $today, int $days_ahead, int $days_from = 0 ): array {
    $alerts = [];

    $date_from = date( 'Y-m-d', strtotime( "+{$days_from} days", strtotime( $today ) ) );
    $date_to   = date( 'Y-m-d', strtotime( "+{$days_ahead} days", strtotime( $today ) ) );

    // Contract expiry
    $contracts = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, employee_code, first_name, last_name, contract_end_date
         FROM {$emp_t}
         WHERE status = 'active'
           AND contract_end_date IS NOT NULL
           AND contract_end_date BETWEEN %s AND %s
         ORDER BY contract_end_date ASC",
        $date_from,
        $date_to
    ), ARRAY_A );

    foreach ( $contracts as $c ) {
        $alerts[] = [
            'employee_id'   => $c['id'],
            'employee_code' => $c['employee_code'],
            'name'          => trim( $c['first_name'] . ' ' . $c['last_name'] ),
            'type'          => 'contract',
            'expiry_date'   => $c['contract_end_date'],
            'days_left'     => $this->days_until( $today, $c['contract_end_date'] ),
        ];
    }

    // National ID expiry
    $nat_ids = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, employee_code, first_name, last_name, national_id_expiry
         FROM {$emp_t}
         WHERE status = 'active'
           AND national_id_expiry IS NOT NULL
           AND national_id_expiry BETWEEN %s AND %s
         ORDER BY national_id_expiry ASC",
        $date_from,
        $date_to
    ), ARRAY_A );

    foreach ( $nat_ids as $n ) {
        $alerts[] = [
            'employee_id'   => $n['id'],
            'employee_code' => $n['employee_code'],
            'name'          => trim( $n['first_name'] . ' ' . $n['last_name'] ),
            'type'          => 'national-id',
            'expiry_date'   => $n['national_id_expiry'],
            'days_left'     => $this->days_until( $today, $n['national_id_expiry'] ),
        ];
    }

    // Passport expiry
    $passports = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, employee_code, first_name, last_name, passport_expiry
         FROM {$emp_t}
         WHERE status = 'active'
           AND passport_expiry IS NOT NULL
           AND passport_expiry BETWEEN %s AND %s
         ORDER BY passport_expiry ASC",
        $date_from,
        $date_to
    ), ARRAY_A );

    foreach ( $passports as $p ) {
        $alerts[] = [
            'employee_id'   => $p['id'],
            'employee_code' => $p['employee_code'],
            'name'          => trim( $p['first_name'] . ' ' . $p['last_name'] ),
            'type'          => 'passport',
            'expiry_date'   => $p['passport_expiry'],
            'days_left'     => $this->days_until( $today, $p['passport_expiry'] ),
        ];
    }

    // Probation end
    $probations = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, employee_code, first_name, last_name, probation_end_date
         FROM {$emp_t}
         WHERE status = 'active'
           AND probation_end_date IS NOT NULL
           AND probation_end_date BETWEEN %s AND %s
         ORDER BY probation_end_date ASC",
        $date_from,
        $date_to
    ), ARRAY_A );

    foreach ( $probations as $pr ) {
        $alerts[] = [
            'employee_id'   => $pr['id'],
            'employee_code' => $pr['employee_code'],
            'name'          => trim( $pr['first_name'] . ' ' . $pr['last_name'] ),
            'type'          => 'probation',
            'expiry_date'   => $pr['probation_end_date'],
            'days_left'     => $this->days_until( $today, $pr['probation_end_date'] ),
        ];
    }

    // Sort by days_left ascending
    usort( $alerts, fn( $a, $b ) => $a['days_left'] <=> $b['days_left'] );

    return $alerts;
}

/**
 * Calculate days until a date
 */
private function days_until( string $today, string $target_date ): int {
    $today_ts  = strtotime( $today );
    $target_ts = strtotime( $target_date );
    return (int) floor( ( $target_ts - $today_ts ) / 86400 );
}

/**
 * Render expiry list table
 */
private function render_expiry_list( string $id, array $alerts, bool $active ): void {
    $class = $active ? 'sfs-hr-expiry-list active' : 'sfs-hr-expiry-list';
    echo '<div id="' . esc_attr( $id ) . '" class="' . esc_attr( $class ) . '">';

    if ( empty( $alerts ) ) {
        echo '<div class="sfs-hr-expiry-empty">' . esc_html__( 'No expiring items in this period.', 'sfs-hr' ) . '</div>';
    } else {
        echo '<table class="sfs-hr-expiry-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Employee', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Expiry Date', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Days Left', 'sfs-hr' ) . '</th>';
        echo '<th></th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $type_labels = [
            'contract'    => __( 'Contract', 'sfs-hr' ),
            'national-id' => __( 'National ID', 'sfs-hr' ),
            'passport'    => __( 'Passport', 'sfs-hr' ),
            'probation'   => __( 'Probation', 'sfs-hr' ),
        ];

        foreach ( $alerts as $alert ) {
            $days_class = 'normal';
            if ( $alert['days_left'] <= 14 ) {
                $days_class = 'urgent';
            } elseif ( $alert['days_left'] <= 30 ) {
                $days_class = 'soon';
            }

            $edit_url = wp_nonce_url( admin_url( 'admin.php?page=sfs-hr-employees&action=edit&id=' . intval( $alert['employee_id'] ) ), 'sfs_hr_edit_' . intval( $alert['employee_id'] ) );

            echo '<tr>';
            echo '<td>';
            echo '<strong>' . esc_html( $alert['name'] ) . '</strong>';
            echo '<br><small class="description">' . esc_html( $alert['employee_code'] ) . '</small>';
            echo '</td>';
            echo '<td><span class="sfs-hr-expiry-type ' . esc_attr( $alert['type'] ) . '">' . esc_html( $type_labels[ $alert['type'] ] ?? $alert['type'] ) . '</span></td>';
            echo '<td>' . esc_html( date_i18n( 'M j, Y', strtotime( $alert['expiry_date'] ) ) ) . '</td>';
            echo '<td><span class="sfs-hr-expiry-days ' . esc_attr( $days_class ) . '">' . esc_html( $alert['days_left'] ) . ' ' . esc_html__( 'days', 'sfs-hr' ) . '</span></td>';
            echo '<td><a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'View', 'sfs-hr' ) . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    echo '</div>';
}

/**
 * Render overtime alerts section
 */
private function render_overtime_alerts_section( $wpdb, string $emp_t, string $today ): void {
    $sessions_t = $wpdb->prefix . 'sfs_hr_attendance_sessions';

    // Check if sessions table exists
    if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sessions_t ) ) ) {
        return;
    }

    // Get overtime threshold from settings (default: 40 hours/month = 2400 minutes)
    $att_settings = get_option( 'sfs_hr_attendance_settings', [] );
    $monthly_ot_threshold = (int) ( $att_settings['monthly_ot_threshold'] ?? 2400 ); // minutes

    // Warning threshold is 80% of limit
    $warning_threshold = (int) ( $monthly_ot_threshold * 0.8 );

    // Get current attendance period date range
    $att_period  = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
    $month_start = $att_period['start'];
    $month_end   = $att_period['end'];

    // Query employees with high overtime this month
    $high_ot_employees = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            e.id, e.employee_code, e.first_name, e.last_name, e.dept_id,
            SUM(s.overtime_minutes) as total_ot_minutes,
            COUNT(DISTINCT s.work_date) as days_worked
         FROM {$emp_t} e
         INNER JOIN {$sessions_t} s ON s.employee_id = e.id
         WHERE e.status = 'active'
           AND s.work_date BETWEEN %s AND %s
           AND s.overtime_minutes > 0
         GROUP BY e.id
         HAVING total_ot_minutes >= %d
         ORDER BY total_ot_minutes DESC
         LIMIT 20",
        $month_start,
        $month_end,
        $warning_threshold
    ), ARRAY_A );

    if ( empty( $high_ot_employees ) ) {
        return; // No overtime alerts to show
    }

    // Get department names
    $dept_t = $wpdb->prefix . 'sfs_hr_departments';
    $depts = [];
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $dept_t ) ) ) {
        $dept_rows = $wpdb->get_results( "SELECT id, name FROM {$dept_t}", ARRAY_A );
        foreach ( $dept_rows as $d ) {
            $depts[ (int) $d['id'] ] = $d['name'];
        }
    }

    echo '<style>
        .sfs-hr-ot-section {
            margin-top: 24px;
            padding: 20px;
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .sfs-hr-ot-section h2 {
            margin: 0 0 16px 0;
            font-size: 16px;
            font-weight: 600;
            color: #1d2327;
        }
        .sfs-hr-ot-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .sfs-hr-ot-table th {
            text-align: start;
            padding: 8px;
            background: #f6f7f7;
            font-weight: 600;
            color: #50575e;
            border-bottom: 1px solid #dcdcde;
        }
        .sfs-hr-ot-table td {
            padding: 8px;
            border-bottom: 1px solid #f0f0f1;
        }
        .sfs-hr-ot-table tr:hover td {
            background: #f9fafb;
        }
        .sfs-hr-ot-bar {
            width: 100%;
            max-width: 80px;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
        }
        .sfs-hr-ot-bar-fill {
            height: 100%;
            border-radius: 3px;
        }
        .sfs-hr-ot-bar-fill.warning { background: #f59e0b; }
        .sfs-hr-ot-bar-fill.danger { background: #dc2626; }
        .sfs-hr-ot-status {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 500;
        }
        .sfs-hr-ot-status.warning { background: #fef3c7; color: #b45309; }
        .sfs-hr-ot-status.danger { background: #fee2e2; color: #b91c1c; }
        .sfs-hr-ot-hours {
            font-weight: 600;
            font-size: 13px;
        }
        @media (max-width: 782px) {
            .sfs-hr-ot-table { font-size: 11px; }
            .sfs-hr-ot-table th, .sfs-hr-ot-table td { padding: 6px 4px; }
            .sfs-hr-ot-table .hide-mobile { display: none; }
        }
    </style>';

    echo '<div class="sfs-hr-ot-section">';
    echo '<h2><span class="dashicons dashicons-clock" style="color:#f59e0b;margin-right:8px;"></span>' . esc_html__( 'Overtime Alerts', 'sfs-hr' );
    echo ' <small style="font-weight:normal;font-size:12px;color:#787c82;">(' . esc_html( date_i18n( 'F Y' ) ) . ')</small></h2>';

    echo '<p style="margin-bottom:16px;color:#50575e;font-size:13px;">';
    printf(
        esc_html__( 'Employees approaching or exceeding %s hours of overtime this month:', 'sfs-hr' ),
        '<strong>' . number_format( $monthly_ot_threshold / 60, 1 ) . '</strong>'
    );
    echo '</p>';

    echo '<table class="sfs-hr-ot-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__( 'Employee', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Department', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Days Worked', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Overtime Hours', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Progress', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ( $high_ot_employees as $emp ) {
        $ot_minutes = (int) $emp['total_ot_minutes'];
        $ot_hours = $ot_minutes / 60;
        $percentage = min( 100, ( $ot_minutes / $monthly_ot_threshold ) * 100 );
        $is_over = $ot_minutes >= $monthly_ot_threshold;
        $status_class = $is_over ? 'danger' : 'warning';
        $status_label = $is_over ? __( 'Over Limit', 'sfs-hr' ) : __( 'Near Limit', 'sfs-hr' );

        $dept_name = isset( $depts[ (int) $emp['dept_id'] ] ) ? $depts[ (int) $emp['dept_id'] ] : '—';
        $profile_url = admin_url( 'admin.php?page=sfs-hr-employee-profile&id=' . intval( $emp['id'] ) );

        echo '<tr>';
        echo '<td>';
        echo '<a href="' . esc_url( $profile_url ) . '"><strong>' . esc_html( trim( $emp['first_name'] . ' ' . $emp['last_name'] ) ) . '</strong></a>';
        echo '<br><small class="description">' . esc_html( $emp['employee_code'] ) . '</small>';
        echo '</td>';
        echo '<td>' . esc_html( $dept_name ) . '</td>';
        echo '<td>' . esc_html( $emp['days_worked'] ) . '</td>';
        echo '<td><span class="sfs-hr-ot-hours">' . esc_html( number_format( $ot_hours, 1 ) ) . ' ' . esc_html__( 'hrs', 'sfs-hr' ) . '</span></td>';
        echo '<td>';
        echo '<div class="sfs-hr-ot-bar">';
        echo '<div class="sfs-hr-ot-bar-fill ' . esc_attr( $status_class ) . '" style="width:' . esc_attr( $percentage ) . '%;"></div>';
        echo '</div>';
        echo '<small style="color:#787c82;">' . esc_html( round( $percentage ) ) . '%</small>';
        echo '</td>';
        echo '<td><span class="sfs-hr-ot-status ' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span></td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
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
        $rows_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY first_name ASC, last_name ASC LIMIT %d OFFSET %d";
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

    private function resolve_dept_by_name(string $name): ?int {
        $name = trim($name);
        if ($name === '') return null;
        global $wpdb;
        $table = $wpdb->prefix.'sfs_hr_departments';
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name=%s AND active=1 LIMIT 1", $name));
        return $id ? (int)$id : null;
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
    public static function attendance_shifts_grouped(): array {
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
    public static function get_emp_default_shift( int $employee_id ): ?array {
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
    public static function get_emp_shift_history( int $employee_id, int $limit = 5 ): array {
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
    public static function ensure_qr_token(int $emp_id): string {
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
    public static function qr_payload_url(int $emp_id, string $token): string {
        $base = home_url('/wp-json/sfs-hr/v1/attendance/scan');
        return add_query_arg(['emp' => $emp_id, 'token' => $token], $base);
    }

/**
 * Render analytics charts section on dashboard
 */
private function render_analytics_section( $wpdb, string $emp_t, string $dept_t, string $sessions_t, string $req_t ): void {
    // Check if HR Manager or has view permission
    if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'sfs_hr.view' ) ) {
        return;
    }

    // --- Data: Department Headcount ---
    $dept_headcount = [];
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $dept_t ) ) ) {
        $dept_headcount = $wpdb->get_results(
            "SELECT d.name AS dept_name, COUNT(e.id) AS count
             FROM {$dept_t} d
             LEFT JOIN {$emp_t} e ON e.dept_id = d.id AND e.status = 'active'
             GROUP BY d.id, d.name
             ORDER BY count DESC
             LIMIT 10",
            ARRAY_A
        );
    }

    // --- Data: Attendance Trend (Last 7 days) ---
    $attendance_trend = [];
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sessions_t ) ) ) {
        $attendance_trend = $wpdb->get_results(
            "SELECT
                work_date,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) AS late,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent
             FROM {$sessions_t}
             WHERE work_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY work_date
             ORDER BY work_date ASC",
            ARRAY_A
        );
    }

    // --- Data: Leave Trend (Last 30 days by week) ---
    $leave_trend = [];
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $req_t ) ) ) {
        $leave_trend = $wpdb->get_results(
            "SELECT
                YEARWEEK(start_date, 1) AS week_num,
                MIN(start_date) AS week_start,
                COUNT(*) AS total_requests,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending
             FROM {$req_t}
             WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY YEARWEEK(start_date, 1)
             ORDER BY week_num ASC",
            ARRAY_A
        );
    }

    // Skip if no data available
    if ( empty( $dept_headcount ) && empty( $attendance_trend ) && empty( $leave_trend ) ) {
        return;
    }

    // Prepare chart data for JavaScript
    $dept_labels = wp_json_encode( array_column( $dept_headcount, 'dept_name' ) );
    $dept_data   = wp_json_encode( array_map( 'intval', array_column( $dept_headcount, 'count' ) ) );

    $att_labels  = wp_json_encode( array_map( function( $r ) {
        return wp_date( 'D j', strtotime( $r['work_date'] ) );
    }, $attendance_trend ) );
    $att_present = wp_json_encode( array_map( 'intval', array_column( $attendance_trend, 'present' ) ) );
    $att_late    = wp_json_encode( array_map( 'intval', array_column( $attendance_trend, 'late' ) ) );
    $att_absent  = wp_json_encode( array_map( 'intval', array_column( $attendance_trend, 'absent' ) ) );

    $leave_labels   = wp_json_encode( array_map( function( $r ) {
        return wp_date( 'M j', strtotime( $r['week_start'] ) );
    }, $leave_trend ) );
    $leave_approved = wp_json_encode( array_map( 'intval', array_column( $leave_trend, 'approved' ) ) );
    $leave_rejected = wp_json_encode( array_map( 'intval', array_column( $leave_trend, 'rejected' ) ) );
    $leave_pending  = wp_json_encode( array_map( 'intval', array_column( $leave_trend, 'pending' ) ) );

    ?>
    <style>
        .sfs-hr-analytics-section {
            margin-top: 24px;
        }
        .sfs-hr-analytics-section h2 {
            font-size: 16px;
            font-weight: 600;
            color: #1d2327;
            margin: 0 0 16px 0;
        }
        .sfs-hr-analytics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        .sfs-hr-chart-card {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .sfs-hr-chart-card h3 {
            margin: 0 0 16px 0;
            font-size: 14px;
            font-weight: 600;
            color: #1d2327;
        }
        .sfs-hr-chart-container {
            position: relative;
            height: 250px;
        }
        @media (max-width: 1200px) {
            .sfs-hr-analytics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 782px) {
            .sfs-hr-analytics-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="sfs-hr-analytics-section">
        <h2><?php esc_html_e( 'Analytics Overview', 'sfs-hr' ); ?></h2>
        <div class="sfs-hr-analytics-grid">
            <?php if ( ! empty( $dept_headcount ) ) : ?>
            <div class="sfs-hr-chart-card">
                <h3><?php esc_html_e( 'Headcount by Department', 'sfs-hr' ); ?></h3>
                <div class="sfs-hr-chart-container">
                    <canvas id="sfs-hr-dept-chart"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $attendance_trend ) ) : ?>
            <div class="sfs-hr-chart-card">
                <h3><?php esc_html_e( 'Attendance Trend (Last 7 Days)', 'sfs-hr' ); ?></h3>
                <div class="sfs-hr-chart-container">
                    <canvas id="sfs-hr-attendance-chart"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $leave_trend ) ) : ?>
            <div class="sfs-hr-chart-card">
                <h3><?php esc_html_e( 'Leave Requests (Last 30 Days)', 'sfs-hr' ); ?></h3>
                <div class="sfs-hr-chart-container">
                    <canvas id="sfs-hr-leave-chart"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ( ! empty( $dept_headcount ) ) : ?>
        // Department Headcount Chart
        new Chart(document.getElementById('sfs-hr-dept-chart'), {
            type: 'bar',
            data: {
                labels: <?php echo $dept_labels; ?>,
                datasets: [{
                    label: '<?php echo esc_js( __( 'Employees', 'sfs-hr' ) ); ?>',
                    data: <?php echo $dept_data; ?>,
                    backgroundColor: 'rgba(34, 113, 177, 0.8)',
                    borderColor: 'rgba(34, 113, 177, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
        <?php endif; ?>

        <?php if ( ! empty( $attendance_trend ) ) : ?>
        // Attendance Trend Chart
        new Chart(document.getElementById('sfs-hr-attendance-chart'), {
            type: 'line',
            data: {
                labels: <?php echo $att_labels; ?>,
                datasets: [
                    {
                        label: '<?php echo esc_js( __( 'Present', 'sfs-hr' ) ); ?>',
                        data: <?php echo $att_present; ?>,
                        borderColor: 'rgba(34, 197, 94, 1)',
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: '<?php echo esc_js( __( 'Late', 'sfs-hr' ) ); ?>',
                        data: <?php echo $att_late; ?>,
                        borderColor: 'rgba(251, 191, 36, 1)',
                        backgroundColor: 'rgba(251, 191, 36, 0.1)',
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: '<?php echo esc_js( __( 'Absent', 'sfs-hr' ) ); ?>',
                        data: <?php echo $att_absent; ?>,
                        borderColor: 'rgba(239, 68, 68, 1)',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.3,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 15 }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
        <?php endif; ?>

        <?php if ( ! empty( $leave_trend ) ) : ?>
        // Leave Requests Chart
        new Chart(document.getElementById('sfs-hr-leave-chart'), {
            type: 'bar',
            data: {
                labels: <?php echo $leave_labels; ?>,
                datasets: [
                    {
                        label: '<?php echo esc_js( __( 'Approved', 'sfs-hr' ) ); ?>',
                        data: <?php echo $leave_approved; ?>,
                        backgroundColor: 'rgba(34, 197, 94, 0.8)',
                        borderRadius: 4
                    },
                    {
                        label: '<?php echo esc_js( __( 'Pending', 'sfs-hr' ) ); ?>',
                        data: <?php echo $leave_pending; ?>,
                        backgroundColor: 'rgba(251, 191, 36, 0.8)',
                        borderRadius: 4
                    },
                    {
                        label: '<?php echo esc_js( __( 'Rejected', 'sfs-hr' ) ); ?>',
                        data: <?php echo $leave_rejected; ?>,
                        backgroundColor: 'rgba(239, 68, 68, 0.8)',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 15 }
                    }
                },
                scales: {
                    x: { stacked: true },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
        <?php endif; ?>
    });
    </script>
    <?php
}

    public function render_employees(): void {
        Helpers::require_cap('sfs_hr.manage');
        echo '<div class="wrap sfs-hr-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'Employees', 'sfs-hr' ) . '</h1>';
    Helpers::render_admin_nav();
    echo '<hr class="wp-header-end" />';

        // Tab navigation
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'list';
        $base_url = admin_url('admin.php?page=sfs-hr-employees');
        ?>
        <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 20px;">
            <a href="<?php echo esc_url($base_url . '&tab=list'); ?>"
               class="nav-tab <?php echo $active_tab === 'list' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Employee List', 'sfs-hr'); ?>
            </a>
            <a href="<?php echo esc_url($base_url . '&tab=organization'); ?>"
               class="nav-tab <?php echo $active_tab === 'organization' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Organization', 'sfs-hr'); ?>
            </a>
        </nav>
        <?php

        // Route to the appropriate tab content
        if ($active_tab === 'organization') {
            $this->render_organization_structure();
            echo '</div>'; // Close wrap
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ($action === 'edit') { $this->render_edit_form(); return; }

        $q        = isset($_GET['s']) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
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
          <style>
  /* General Styles */
  .sfs-hr-badge { display:inline-block; padding:3px 10px; border-radius:12px; background:#f0f0f1; font-size:12px; font-weight:500; }
  .sfs-hr-badge.status-active { background:#dcfce7; color:#166534; }
  .sfs-hr-badge.status-inactive { background:#fef3c7; color:#92400e; }
  .sfs-hr-badge.status-terminated { background:#fee2e2; color:#991b1b; }
  .sfs-hr-select { min-width: 240px; }

  /* Toolbar section */
  .sfs-hr-toolbar {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 6px;
    padding: 16px;
    margin: 16px 0;
  }
  .sfs-hr-toolbar-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
  }
  .sfs-hr-toolbar-row:last-child {
    margin-bottom: 0;
  }
  .sfs-hr-toolbar-row.search-row {
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e5e5;
  }
  .sfs-hr-toolbar input[type="search"] {
    min-width: 250px;
    height: 36px;
    padding: 0 12px;
    border-radius: 4px;
  }
  .sfs-hr-toolbar select {
    height: 36px;
    line-height: 36px;
    border-radius: 4px;
    min-width: 100px;
    padding: 0 24px 0 10px;
    font-size: 14px;
    color: #1e1e1e;
    background-color: #fff;
    border: 1px solid #8c8f94;
    vertical-align: middle;
    box-sizing: border-box;
  }
  .sfs-hr-toolbar .button {
    height: 36px;
    line-height: 34px;
  }
  .sfs-hr-toolbar-group {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .sfs-hr-toolbar-divider {
    width: 1px;
    height: 24px;
    background: #dcdcde;
    margin: 0 4px;
  }
  .sfs-hr-toolbar label {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    cursor: pointer;
  }

  /* Advanced toggle button */
  .sfs-hr-advanced-toggle {
    display: inline-flex;
    align-items: center;
    background: #f6f7f7;
    border-color: #dcdcde;
  }
  .sfs-hr-advanced-toggle.active {
    background: #2271b1;
    color: #fff;
    border-color: #2271b1;
  }
  .sfs-hr-advanced-toggle.active .dashicons {
    color: #fff;
  }
  .sfs-hr-advanced-section {
    background: #f9f9f9;
    border-radius: 4px;
    padding: 12px 16px !important;
    margin-top: 8px;
  }

  /* Employee list table styles */
  .sfs-hr-emp-table {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 6px;
    margin-top: 16px;
    overflow: visible;
  }
  .sfs-hr-emp-table .widefat {
    border: none;
    border-radius: 6px;
    margin: 0;
  }
  .sfs-hr-emp-table .widefat th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #50575e;
    padding: 12px 16px;
  }
  .sfs-hr-emp-table .widefat td {
    padding: 12px 16px;
    vertical-align: middle;
  }
  .sfs-hr-emp-table .widefat tbody tr:hover {
    background: #f8f9fa;
  }
  .sfs-hr-emp-table .emp-name {
    font-weight: 500;
    color: #1d2327;
  }
  .sfs-hr-emp-table .emp-code {
    font-family: monospace;
    font-size: 12px;
    background: #f0f0f1;
    padding: 2px 6px;
    border-radius: 3px;
    color: #50575e;
  }

  /* Kebab (3-dot) action button & dropdown */
  .sfs-hr-kebab-wrap {
    position: relative;
    display: inline-block;
  }
  .sfs-hr-kebab-btn {
    background: none;
    border: 1px solid #dcdcde;
    border-radius: 6px;
    cursor: pointer;
    padding: 6px 8px;
    color: #50575e;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
  }
  .sfs-hr-kebab-btn:hover {
    background: #f0f0f1;
    border-color: #2271b1;
    color: #2271b1;
  }
  .sfs-hr-kebab-menu {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    margin-top: 4px;
    min-width: 160px;
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    z-index: 100;
    padding: 4px 0;
  }
  .sfs-hr-kebab-menu.open {
    display: block;
  }
  .sfs-hr-kebab-menu.open-up {
    top: auto;
    bottom: 100%;
    margin-top: 0;
    margin-bottom: 4px;
  }
  .sfs-hr-kebab-menu a {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    font-size: 13px;
    color: #1d2327;
    text-decoration: none;
    white-space: nowrap;
    transition: background 0.15s;
  }
  .sfs-hr-kebab-menu a:hover {
    background: #f0f0f1;
  }
  .sfs-hr-kebab-menu a.danger {
    color: #d63638;
  }
  .sfs-hr-kebab-menu a.danger:hover {
    background: #fee2e2;
  }


  /* Pagination */
  .sfs-hr-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 12px 16px;
    background: #fff;
    border: 1px solid #dcdcde;
    border-top: none;
    border-radius: 0 0 6px 6px;
    flex-wrap: wrap;
  }
  .sfs-hr-pagination a,
  .sfs-hr-pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    padding: 0 8px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 13px;
  }
  .sfs-hr-pagination a {
    background: #f0f0f1;
    color: #50575e;
  }
  .sfs-hr-pagination a:hover {
    background: #dcdcde;
  }
  .sfs-hr-pagination .current-page {
    background: #2271b1;
    color: #fff;
    font-weight: 600;
  }

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

  /* Collapsible Add Employee Section */
  .sfs-hr-add-employee-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    font-size: 14px;
    font-weight: 600;
    background: linear-gradient(135deg, #2271b1 0%, #135e96 100%);
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(34, 113, 177, 0.3);
    transition: all 0.2s ease;
    margin: 24px 0 16px;
  }
  .sfs-hr-add-employee-toggle:hover {
    background: linear-gradient(135deg, #135e96 0%, #0a4b7a 100%);
    box-shadow: 0 4px 8px rgba(34, 113, 177, 0.4);
    transform: translateY(-1px);
  }
  .sfs-hr-add-employee-toggle:active {
    transform: translateY(0);
    box-shadow: 0 2px 4px rgba(34, 113, 177, 0.3);
  }
  .sfs-hr-add-employee-toggle .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
    transition: transform 0.3s ease;
  }
  .sfs-hr-add-employee-toggle.active .dashicons-plus-alt2 {
    transform: rotate(45deg);
  }
  .sfs-hr-collapsible-section {
    display: none;
    animation: sfsHrSlideDown 0.3s ease-out;
  }
  .sfs-hr-collapsible-section.open {
    display: block;
  }
  @keyframes sfsHrSlideDown {
    from {
      opacity: 0;
      transform: translateY(-10px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  /* Mobile responsive styles */
  @media (max-width: 782px) {
    /* Toolbar mobile */
    .sfs-hr-toolbar {
      padding: 12px;
    }
    .sfs-hr-toolbar-row {
      flex-direction: column;
      align-items: stretch;
    }
    .sfs-hr-toolbar-row.search-row {
      gap: 8px;
    }
    .sfs-hr-toolbar input[type="search"] {
      width: 100%;
      min-width: auto;
    }
    .sfs-hr-toolbar select {
      width: 100%;
    }
    .sfs-hr-toolbar .button {
      width: 100%;
      text-align: center;
    }
    .sfs-hr-toolbar-group {
      flex-direction: column;
      align-items: stretch;
    }
    .sfs-hr-toolbar-divider {
      display: none;
    }
    .sfs-hr-toolbar label {
      padding: 8px 0;
    }

    /* Hide columns on mobile - only show Name, Status, Actions */
    .sfs-hr-emp-table .widefat thead th.hide-mobile,
    .sfs-hr-emp-table .widefat tbody td.hide-mobile {
      display: none !important;
    }

    /* Table mobile */
    .sfs-hr-emp-table .widefat th,
    .sfs-hr-emp-table .widefat td {
      padding: 10px 8px;
    }
    .sfs-hr-emp-table .widefat th:first-child,
    .sfs-hr-emp-table .widefat td:first-child {
      padding-left: 12px;
    }
    .sfs-hr-emp-table .widefat th:last-child,
    .sfs-hr-emp-table .widefat td:last-child {
      padding-right: 12px;
    }

    /* Kebab menu stays the same on mobile */

    /* Pagination mobile */
    .sfs-hr-pagination {
      justify-content: center;
    }
  }
</style>

<script>
function sfsHrToggleKebab(btn) {
  var menu = btn.nextElementSibling;
  var isOpen = menu.classList.contains('open');
  // Close all open menus first.
  document.querySelectorAll('.sfs-hr-kebab-menu.open').forEach(function(el) {
    el.classList.remove('open');
    el.classList.remove('open-up');
    var prev = el.previousElementSibling;
    if (prev && prev.classList.contains('sfs-hr-kebab-btn')) prev.setAttribute('aria-expanded','false');
  });
  if (!isOpen) {
    menu.classList.add('open');
    btn.setAttribute('aria-expanded','true');
    // Flip upward if menu would overflow the viewport bottom.
    var rect = menu.getBoundingClientRect();
    if (rect.bottom > window.innerHeight) {
      menu.classList.add('open-up');
    }
  } else {
    btn.setAttribute('aria-expanded','false');
  }
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.sfs-hr-kebab-btn') && !e.target.closest('.sfs-hr-kebab-menu')) {
    document.querySelectorAll('.sfs-hr-kebab-menu.open').forEach(function(el) {
      el.classList.remove('open');
      el.classList.remove('open-up');
      var prev = el.previousElementSibling;
      if (prev && prev.classList.contains('sfs-hr-kebab-btn')) prev.setAttribute('aria-expanded','false');
    });
  }
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.sfs-hr-kebab-menu.open').forEach(function(el) {
      el.classList.remove('open');
      el.classList.remove('open-up');
      var prev = el.previousElementSibling;
      if (prev && prev.classList.contains('sfs-hr-kebab-btn')) prev.setAttribute('aria-expanded','false');
    });
  }
});
</script>


          <div class="sfs-hr-toolbar">
            <!-- Search Row -->
            <form method="get" class="sfs-hr-toolbar-row search-row">
              <input type="hidden" name="page" value="sfs-hr-employees" />
              <input type="search" name="s" value="<?php echo esc_attr($q); ?>" placeholder="<?php echo esc_attr__('Search name/email/code','sfs-hr'); ?>"/>
              <select name="per_page">
                <?php foreach ([10,20,50,100] as $pp): ?>
                  <option value="<?php echo (int)$pp; ?>" <?php selected($per_page,$pp); ?>><?php printf(esc_html__('%d/page', 'sfs-hr'), (int)$pp); ?></option>
                <?php endforeach; ?>
              </select>
              <?php submit_button(__('Search','sfs-hr'),'primary','',false); ?>
              <button type="button" class="button sfs-hr-advanced-toggle" onclick="sfsHrToggleAdvanced()">
                <span class="dashicons dashicons-admin-tools" style="margin-right:4px;line-height:inherit;"></span>
                <?php esc_html_e('Advanced','sfs-hr'); ?>
              </button>
            </form>

            <!-- Advanced Actions (collapsible) -->
            <div class="sfs-hr-toolbar-row sfs-hr-advanced-section" id="sfs-hr-advanced-section" style="display:none;">
              <div class="sfs-hr-toolbar-group">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex;">
                  <input type="hidden" name="action" value="sfs_hr_export_employees" />
                  <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_export); ?>" />
                  <?php submit_button(__('Export CSV','sfs-hr'),'secondary','',false); ?>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="display:inline-flex; align-items:center; gap:8px;">
                  <input type="hidden" name="action" value="sfs_hr_import_employees" />
                  <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_import); ?>" />
                  <input type="file" name="csv" accept=".csv" required style="max-width:200px;" />
                  <?php submit_button(__('Import','sfs-hr'),'secondary','',false); ?>
                </form>
              </div>

              <div class="sfs-hr-toolbar-divider"></div>

              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sfs-hr-toolbar-group">
                <input type="hidden" name="action" value="sfs_hr_sync_users" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_sync); ?>" />
                <label><input type="checkbox" name="role_filter[]" value="subscriber" checked /> <?php echo esc_html__('Subscribers','sfs-hr'); ?></label>
                <label><input type="checkbox" name="role_filter[]" value="administrator" /> <?php echo esc_html__('Administrators','sfs-hr'); ?></label>
                <?php submit_button(__('Sync Users','sfs-hr'),'secondary','',false); ?>
              </form>
            </div>
          </div>

          <script>
          function sfsHrToggleAdvanced() {
            var section = document.getElementById('sfs-hr-advanced-section');
            var btn = document.querySelector('.sfs-hr-advanced-toggle');
            if (section.style.display === 'none') {
              section.style.display = 'flex';
              btn.classList.add('active');
            } else {
              section.style.display = 'none';
              btn.classList.remove('active');
            }
          }

          document.addEventListener('DOMContentLoaded', function() {
            var toggleBtn = document.getElementById('sfs-hr-add-employee-toggle');
            var section = document.getElementById('sfs-hr-add-employee-section');
            if (toggleBtn && section) {
              toggleBtn.addEventListener('click', function() {
                var isOpen = section.classList.contains('open');
                if (isOpen) {
                  section.classList.remove('open');
                  toggleBtn.classList.remove('active');
                  toggleBtn.setAttribute('aria-expanded', 'false');
                } else {
                  section.classList.add('open');
                  toggleBtn.classList.add('active');
                  toggleBtn.setAttribute('aria-expanded', 'true');
                }
              });
            }
          });
          </script>

          <button type="button" class="sfs-hr-add-employee-toggle" id="sfs-hr-add-employee-toggle" aria-expanded="false" aria-controls="sfs-hr-add-employee-section">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e('Add New Employee','sfs-hr'); ?>
          </button>

          <div id="sfs-hr-add-employee-section" class="sfs-hr-collapsible-section">
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
                    <?php Helpers::render_nationality_select( '', 'nationality', 'sfs-hr-nationality' ); ?>
                  </div>

                  <div class="sfs-hr-field">
                    <label for="sfs-hr-marital-status"><?php esc_html_e( 'Marital Status', 'sfs-hr' ); ?></label>
                    <select id="sfs-hr-marital-status" name="marital_status">
                      <option value=""><?php esc_html_e( '— Select —', 'sfs-hr' ); ?></option>
                      <option value="single"><?php esc_html_e( 'Single', 'sfs-hr' ); ?></option>
                      <option value="married"><?php esc_html_e( 'Married', 'sfs-hr' ); ?></option>
                      <option value="divorced"><?php esc_html_e( 'Divorced', 'sfs-hr' ); ?></option>
                      <option value="widowed"><?php esc_html_e( 'Widowed', 'sfs-hr' ); ?></option>
                    </select>
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
                    <label for="sfs-hr-contract-type"><?php esc_html_e( 'Contract Duration', 'sfs-hr' ); ?></label>
                    <select id="sfs-hr-contract-type" name="contract_type" class="regular-text">
                      <option value=""><?php esc_html_e( '— Select —', 'sfs-hr' ); ?></option>
                      <option value="1 year"><?php esc_html_e( '1 year', 'sfs-hr' ); ?></option>
                      <option value="2 years"><?php esc_html_e( '2 years', 'sfs-hr' ); ?></option>
                    </select>
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
          </div><!-- .sfs-hr-collapsible-section -->

          <h2><?php echo esc_html__('Employees List','sfs-hr'); ?> <span style="font-weight:normal; font-size:14px; color:#50575e;">(<?php echo (int)$total; ?> <?php esc_html_e('total','sfs-hr'); ?>)</span></h2>

          <div class="sfs-hr-emp-table">
            <table class="widefat striped">
              <thead><tr>
                <th class="hide-mobile"><?php esc_html_e('ID','sfs-hr'); ?></th>
                <th class="hide-mobile"><?php esc_html_e('Code','sfs-hr'); ?></th>
                <th><?php esc_html_e('Name','sfs-hr'); ?></th>
                <th class="hide-mobile"><?php esc_html_e('Email','sfs-hr'); ?></th>
                <th class="hide-mobile"><?php esc_html_e('Department','sfs-hr'); ?></th>
                <th class="hide-mobile"><?php esc_html_e('Position','sfs-hr'); ?></th>
                <th><?php esc_html_e('Status','sfs-hr'); ?></th>
                <th><?php esc_html_e('Actions','sfs-hr'); ?></th>
              </tr></thead>
              <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="8"><?php esc_html_e('No employees found.','sfs-hr'); ?></td></tr>
              <?php else:
                foreach ($rows as $r):
                  $name     = trim(($r['first_name']??'').' '.($r['last_name']??''));
                  $status   = $r['status'];
                  $view_url = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id='.(int)$r['id']);
                  $edit_url = wp_nonce_url( admin_url('admin.php?page=sfs-hr-employees&action=edit&id='.(int)$r['id']), 'sfs_hr_edit_'.(int)$r['id'] );
                  $del_url  = wp_nonce_url( admin_url('admin-post.php?action=sfs_hr_delete_employee&id='.(int)$r['id']), 'sfs_hr_del_'.(int)$r['id'] );
                  $dept_name = empty($r['dept_id']) ? __('General','sfs-hr') : ($dept_map[(int)$r['dept_id']] ?? '#'.(int)$r['dept_id']);
              ?>
                <tr>
                  <td class="hide-mobile"><?php echo (int)$r['id']; ?></td>
                  <td class="hide-mobile"><span class="emp-code"><?php echo esc_html($r['employee_code']); ?></span></td>
                  <td><span class="emp-name"><?php echo esc_html($name ?: $r['employee_code']); ?></span></td>
                  <td class="hide-mobile"><?php echo esc_html($r['email']); ?></td>
                  <td class="hide-mobile"><?php echo esc_html($dept_name); ?></td>
                  <td class="hide-mobile"><?php echo esc_html($r['position']); ?></td>
                  <td><span class="sfs-hr-badge status-<?php echo esc_attr($status); ?>"><?php echo esc_html(__(ucfirst($status), 'sfs-hr')); ?></span></td>
                  <td>
                    <div class="sfs-hr-kebab-wrap">
                      <button type="button" class="sfs-hr-kebab-btn" onclick="sfsHrToggleKebab(this)" aria-label="<?php esc_attr_e('Actions','sfs-hr'); ?>" aria-expanded="false">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                      </button>
                      <div class="sfs-hr-kebab-menu">
                        <a href="<?php echo esc_url($view_url); ?>"><span class="dashicons dashicons-visibility" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span> <?php esc_html_e('View','sfs-hr'); ?></a>
                        <a href="<?php echo esc_url($edit_url); ?>"><span class="dashicons dashicons-edit" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span> <?php esc_html_e('Edit','sfs-hr'); ?></a>
                        <a href="<?php echo esc_url($del_url); ?>" class="danger" onclick="return confirm('<?php echo esc_js(__('Delete permanently? This cannot be undone.', 'sfs-hr')); ?>');"><span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span> <?php esc_html_e('Delete','sfs-hr'); ?></a>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($pages > 1): ?>
          <div class="sfs-hr-pagination">
            <?php for($i=1;$i<=$pages;$i++): ?>
              <?php if ($i === $page): ?>
                <span class="current-page"><?php echo (int)$i; ?></span>
              <?php else: ?>
                <a href="<?php echo esc_url( add_query_arg(['paged'=>$i,'per_page'=>$per_page,'s'=>$q], admin_url('admin.php?page=sfs-hr-employees')) ); ?>"><?php echo (int)$i; ?></a>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
          <?php endif; ?>



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
// Prevent the "__add_new__" sentinel value from being persisted (JS-disabled fallback).
if ( $nationality === '__add_new__' ) {
    $nationality = '';
}
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

        // Audit log: employee created
        $employee_data = [
            'employee_code' => $code,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'dept_id' => $dept,
            'position' => $pos,
            'gender' => $gender,
            'hired_at' => $hired,
            'base_salary' => $base,
        ];
        do_action( 'sfs_hr_employee_created', $employee_id, $employee_data );

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
        $dept_t = $wpdb->prefix.'sfs_hr_departments';
        $rows = $wpdb->get_results(
            "SELECT e.*, d.name AS department_name
             FROM {$table} e
             LEFT JOIN {$dept_t} d ON d.id = e.dept_id
             ORDER BY e.id ASC",
            ARRAY_A
        );

        $filename = 'employees-export-' . date('Ymd-His') . '.csv';

        // Clean any active output buffers to prevent corrupted encoding
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename='.$filename);

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel correctly renders Arabic characters
        fwrite($out, "\xEF\xBB\xBF");
        $headers = [
    'id','employee_code','first_name','last_name','email','phone','department','position','gender','status',
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

        $date_columns = [
            'hired_at','national_id_expiry','passport_expiry','visa_expiry',
            'date_of_birth','contract_start_date','contract_end_date',
            'probation_end_date','entry_date_ksa','driving_license_expiry',
        ];

        fputcsv($out, $headers);
        foreach ($rows as $r) {
            $row = [];
            foreach ($headers as $h) {
                if ($h === 'department') {
                    $row[] = $r['department_name'] ?? '';
                } elseif ( in_array($h, $date_columns, true) && !empty($r[$h]) ) {
                    // Format DATE columns as dd/mm/yyyy for user-friendly export
                    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $r[$h])
                       ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $r[$h]);
                    $row[] = $dt ? $dt->format('d/m/Y') : $r[$h];
                } else {
                    $row[] = isset($r[$h]) ? $r[$h] : '';
                }
            }
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    public function handle_import(): void {
    Helpers::require_cap('sfs_hr.manage');
    check_admin_referer('sfs_hr_import_employees');

    $redir_base = ['page' => 'sfs-hr-employees'];

    if ( empty($_FILES['csv']['tmp_name']) ) {
        wp_safe_redirect( add_query_arg( array_merge($redir_base, [
            'sfs_hr_notice' => 'error',
            'sfs_hr_msg'    => __('No CSV file selected.', 'sfs-hr'),
        ]), admin_url('admin.php') ) );
        exit;
    }

    $fh = fopen($_FILES['csv']['tmp_name'], 'r');
    if ( ! $fh ) {
        wp_safe_redirect( add_query_arg( array_merge($redir_base, [
            'sfs_hr_notice' => 'error',
            'sfs_hr_msg'    => __('Failed to open the uploaded file.', 'sfs-hr'),
        ]), admin_url('admin.php') ) );
        exit;
    }

    // Strip UTF-8 BOM if present — the export adds one (\xEF\xBB\xBF) for
    // Excel Arabic support, but it corrupts the first header cell on re-import.
    $bom = fread( $fh, 3 );
    if ( $bom !== "\xEF\xBB\xBF" ) {
        rewind( $fh );
    }

    $header = fgetcsv($fh);
    if ( ! $header ) {
        fclose($fh);
        wp_safe_redirect( add_query_arg( array_merge($redir_base, [
            'sfs_hr_notice' => 'error',
            'sfs_hr_msg'    => __('Invalid or empty CSV header row.', 'sfs-hr'),
        ]), admin_url('admin.php') ) );
        exit;
    }
    $header = array_map('sanitize_key', $header);

    global $wpdb;
    $table   = $wpdb->prefix.'sfs_hr_employees';
    $updated      = 0;
    $created      = 0;
    $skipped      = 0;
    $skipped_rows = []; // collect "row N (code): reason"
    $row_num      = 1;  // header is row 1

    while ( ($row = fgetcsv($fh)) !== false ) {
        $row_num++;

        // Handle column count mismatch (trailing commas, Excel quirks)
        $hc = count( $header );
        $rc = count( $row );
        if ( $rc < $hc ) {
            $row = array_pad( $row, $hc, '' );
        } elseif ( $rc > $hc ) {
            $row = array_slice( $row, 0, $hc );
        }

        $data = array_combine($header, $row);
        if ( ! is_array($data) ) {
            $skipped++;
            $skipped_rows[] = sprintf( __( 'Row %d: malformed columns', 'sfs-hr' ), $row_num );
            continue;
        }

        $code = isset($data['employee_code']) ? trim( sanitize_text_field($data['employee_code']) ) : '';
        if ( $code === '' ) {
            $skipped++;
            $skipped_rows[] = sprintf( __( 'Row %d: missing employee_code', 'sfs-hr' ), $row_num );
            continue;
        }

        // Primary match: employee_code (the unique business key)
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE employee_code=%s LIMIT 1", $code)
        );

        // Secondary match: if employee_code didn't match but the CSV contains
        // the DB row id, try matching by id.  This handles Excel round-trips
        // that strip leading zeros or alter the code (e.g. "0042" → "42").
        if ( ! $exists && isset( $data['id'] ) && $data['id'] !== '' ) {
            $csv_id = (int) $data['id'];
            if ( $csv_id > 0 ) {
                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare( "SELECT id FROM {$table} WHERE id=%d LIMIT 1", $csv_id )
                );
            }
        }

        $status_in = isset($data['status']) ? sanitize_text_field($data['status']) : 'active';
        $status    = in_array($status_in, ['active','inactive','terminated'], true) ? $status_in : 'active';

        $dept_id = null;
        if ( isset($data['dept_id']) && $data['dept_id'] !== '' ) {
            $dept_id = $this->validate_dept_id((int) $data['dept_id']);
        } elseif ( isset($data['department']) && $data['department'] !== '' ) {
            $dept_id = $this->resolve_dept_by_name( trim($data['department']) );
        }

        $g      = isset($data['gender']) ? strtolower(trim($data['gender'])) : '';
        $gender = in_array($g, ['male','female'], true) ? $g : null;

        $payload = [
            'first_name'              => sanitize_text_field($data['first_name']              ?? ''),
            'last_name'               => sanitize_text_field($data['last_name']               ?? ''),
            'first_name_ar'           => sanitize_text_field($data['first_name_ar']           ?? ''),
            'last_name_ar'            => sanitize_text_field($data['last_name_ar']            ?? ''),
            'email'                   => sanitize_email($data['email']                        ?? ''),
            'phone'                   => sanitize_text_field($data['phone']                   ?? ''),
            'dept_id'                 => $dept_id,
            'position'                => sanitize_text_field($data['position']                ?? ''),
            'status'                  => $status,
            'gender'                  => $gender,
            'hired_at'                => Helpers::normalize_date($data['hired_at'] ?? ''),
            'base_salary'             => ($data['base_salary']             ?? '') !== '' ? floatval( str_replace( ',', '', $data['base_salary'] ) ) : null,
            'national_id'             => sanitize_text_field($data['national_id']             ?? ''),
            'national_id_expiry'      => Helpers::normalize_date($data['national_id_expiry'] ?? ''),
            'passport_no'             => sanitize_text_field($data['passport_no']             ?? ''),
            'passport_expiry'         => Helpers::normalize_date($data['passport_expiry'] ?? ''),
            'emergency_contact_name'  => sanitize_text_field($data['emergency_contact_name']  ?? ''),
            'emergency_contact_phone' => sanitize_text_field($data['emergency_contact_phone'] ?? ''),
        ];

        // NEW FIELDS
        $payload['gosi_salary']            = ($data['gosi_salary']            ?? '') !== '' ? floatval( str_replace( ',', '', $data['gosi_salary'] ) ) : null;

        $payload['visa_number']            = sanitize_text_field($data['visa_number']            ?? '');
        $payload['visa_expiry']            = Helpers::normalize_date($data['visa_expiry'] ?? '');

        $payload['nationality']            = sanitize_text_field($data['nationality']            ?? '');
        $payload['marital_status']         = sanitize_text_field($data['marital_status']         ?? '');
        $payload['date_of_birth']          = Helpers::normalize_date($data['date_of_birth'] ?? '');

        $payload['work_location']          = sanitize_text_field($data['work_location']          ?? '');
        $payload['contract_type']          = sanitize_text_field($data['contract_type']          ?? '');
        $payload['contract_start_date']    = Helpers::normalize_date($data['contract_start_date'] ?? '');
        $payload['contract_end_date']      = Helpers::normalize_date($data['contract_end_date'] ?? '');
        $payload['probation_end_date']     = Helpers::normalize_date($data['probation_end_date'] ?? '');
        $payload['entry_date_ksa']         = Helpers::normalize_date($data['entry_date_ksa'] ?? '');

        $payload['residence_profession']   = sanitize_text_field($data['residence_profession']   ?? '');
        $payload['sponsor_name']           = sanitize_text_field($data['sponsor_name']           ?? '');
        $payload['sponsor_id']             = sanitize_text_field($data['sponsor_id']             ?? '');

        $payload['driving_license_has']    = ! empty($data['driving_license_has']) ? 1 : 0;
        $payload['driving_license_number'] = sanitize_text_field($data['driving_license_number'] ?? '');
        $payload['driving_license_expiry'] = Helpers::normalize_date($data['driving_license_expiry'] ?? '');

        $payload['updated_at']             = Helpers::now_mysql();

        if ( $exists ) {
            // Prevent setting status to 'terminated' if employee has future last_working_day
            if ( $status === 'terminated' && !Helpers::can_terminate_employee($exists) ) {
                // Keep existing status instead of terminating early
                unset($payload['status']);
            }
            // Also update employee_code in case it was matched by id and the
            // code in the CSV is the corrected/canonical value.
            $payload['employee_code'] = $code;
            $res = $wpdb->update($table, $payload, ['id'=>$exists]);
            if ( $res === false ) {
                $skipped++;
                $skipped_rows[] = sprintf( __( 'Row %d (%s): DB error on update', 'sfs-hr' ), $row_num, $code );
                continue;
            } elseif ( $res === 0 ) {
                $skipped++;
                $skipped_rows[] = sprintf( __( 'Row %d (%s): no change', 'sfs-hr' ), $row_num, $code );
                continue;
            } else {
                $employee_id = $exists;
                $updated++;
            }
        } else {
            $payload = array_merge($payload, [
                'employee_code' => $code,
                'created_at'    => Helpers::now_mysql(),
            ]);
            $result = $wpdb->insert($table, $payload);
            if ( $result === false ) {
                // Insert failed — likely UNIQUE constraint on employee_code.
                // Try to find and update the existing row instead.
                $fallback_id = (int) $wpdb->get_var(
                    $wpdb->prepare( "SELECT id FROM {$table} WHERE employee_code=%s LIMIT 1", $code )
                );
                if ( $fallback_id ) {
                    unset( $payload['employee_code'], $payload['created_at'] );
                    if ( ($payload['status'] ?? '') === 'terminated' && !Helpers::can_terminate_employee($fallback_id) ) {
                        unset( $payload['status'] );
                    }
                    $res = $wpdb->update( $table, $payload, [ 'id' => $fallback_id ] );
                    if ( $res === false ) {
                        $skipped++;
                        $skipped_rows[] = sprintf( __( 'Row %d (%s): DB error on fallback update', 'sfs-hr' ), $row_num, $code );
                        continue;
                    } elseif ( $res === 0 ) {
                        $skipped++;
                        $skipped_rows[] = sprintf( __( 'Row %d (%s): no change', 'sfs-hr' ), $row_num, $code );
                        continue;
                    } else {
                        $employee_id = $fallback_id;
                        $updated++;
                    }
                } else {
                    $skipped++;
                    $skipped_rows[] = sprintf( __( 'Row %d (%s): insert failed, no matching record found', 'sfs-hr' ), $row_num, $code );
                    continue; // Truly failed — skip this row
                }
            } else {
                $employee_id = (int) $wpdb->insert_id;
                $created++;
            }
        }

        if ( ! empty($payload['email']) ) {
            $user = get_user_by('email', $payload['email']);
            if ( $user ) {
                $wpdb->update($table, ['user_id' => (int) $user->ID], ['id' => $employee_id]);
            }
        }
    }

    fclose($fh);

    $parts = [];
    if ( $created ) {
        /* translators: %d = number of employees created */
        $parts[] = sprintf( _n( '%d created', '%d created', $created, 'sfs-hr' ), $created );
    }
    if ( $updated ) {
        /* translators: %d = number of employees updated */
        $parts[] = sprintf( _n( '%d updated', '%d updated', $updated, 'sfs-hr' ), $updated );
    }
    if ( $skipped ) {
        /* translators: %d = number of rows skipped */
        $parts[] = sprintf( _n( '%d skipped', '%d skipped', $skipped, 'sfs-hr' ), $skipped );
    }

    if ( $created === 0 && $updated === 0 ) {
        $notice = 'warning';
        $msg    = __( 'Import finished but no records were created or updated.', 'sfs-hr' );
        if ( $skipped ) {
            /* translators: %d = number of rows skipped */
            $msg .= ' ' . sprintf( _n( '(%d row skipped)', '(%d rows skipped)', $skipped, 'sfs-hr' ), $skipped );
        }
    } else {
        $notice = $skipped ? 'warning' : 'success';
        /* translators: %s = comma-separated counts, e.g. "3 created, 2 updated" */
        $msg = sprintf( __( 'Import complete: %s.', 'sfs-hr' ), implode( ', ', $parts ) );
    }

    if ( $skipped_rows ) {
        $max_stored = 50;
        $total_skipped_rows = count( $skipped_rows );
        $stored_rows = array_slice( $skipped_rows, 0, $max_stored );
        if ( $total_skipped_rows > $max_stored ) {
            $stored_rows[] = sprintf(
                /* translators: %d = number of additional skipped rows not shown */
                __( '… and %d more skipped rows.', 'sfs-hr' ),
                $total_skipped_rows - $max_stored
            );
        }
        set_transient( 'sfs_hr_import_skipped_' . get_current_user_id(), $stored_rows, 120 );
    }

    wp_safe_redirect( add_query_arg( array_merge($redir_base, [
        'sfs_hr_notice' => $notice,
        'sfs_hr_msg'    => $msg,
    ]), admin_url('admin.php') ) );
    exit;
}


    public function handle_terminate(): void {
        Helpers::require_cap('sfs_hr.manage');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        check_admin_referer('sfs_hr_term_'.$id);
        if ($id<=0) { wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=id') ); exit; }

        // Check if employee can be terminated (last_working_day must have passed)
        if (!Helpers::can_terminate_employee($id)) {
            Helpers::redirect_with_notice(
                admin_url('admin.php?page=sfs-hr-employees'),
                'error',
                __('Cannot terminate: employee has an approved resignation with a future last working day.', 'sfs-hr')
            );
        }

        global $wpdb; $table = $wpdb->prefix.'sfs_hr_employees';
        $wpdb->update($table, ['status'=>'terminated','updated_at'=>Helpers::now_mysql()], ['id'=>$id]);
        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&ok=terminated') ); exit;
    }

    public function handle_delete(): void {
        Helpers::require_cap('sfs_hr.manage');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        check_admin_referer('sfs_hr_del_'.$id);
        if ($id<=0) { wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=id') ); exit; }

        global $wpdb;
        $loan_table = $wpdb->prefix.'sfs_hr_loans';
        $has = (int)$wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$loan_table} WHERE employee_id=%d", $id) );
        if ($has>0){
            wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=hasloans') ); exit;
        }

        // Get employee data before deletion for audit log
        $emp_table = $wpdb->prefix.'sfs_hr_employees';
        $employee = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$emp_table} WHERE id = %d", $id ), ARRAY_A );

        $wpdb->delete($emp_table, ['id'=>$id]);

        // Audit log: employee deleted
        if ( $employee ) {
            do_action( 'sfs_hr_employee_deleted', $id, $employee );
        }

        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&ok=deleted') ); exit;
    }

    private function render_edit_form(): void {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ( $id <= 0 ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-employees&err=id' ) );
        exit;
    }

    check_admin_referer( 'sfs_hr_edit_' . $id );

    // Redirect to unified Employee Profile page in edit mode.
    wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-employee-profile&employee_id=' . $id . '&mode=edit' ) );
    exit;
}

    /**
     * Adjust color brightness
     * @param string $hex Hex color code
     * @param int $steps Steps to adjust (-255 to 255)
     * @return string Adjusted hex color
     */
    private static function adjust_color_brightness( string $hex, int $steps ): string {
        $hex = ltrim( $hex, '#' );

        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );

        $r = max( 0, min( 255, $r + $steps ) );
        $g = max( 0, min( 255, $g + $steps ) );
        $b = max( 0, min( 255, $b + $steps ) );

        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    private function render_organization_structure(): void {
        global $wpdb;
        $dept_t = $wpdb->prefix . 'sfs_hr_departments';
        $emp_t  = $wpdb->prefix . 'sfs_hr_employees';

        // Get all active departments with their managers (excluding "General" department)
        $departments = $wpdb->get_results(
            "SELECT d.id, d.name, d.manager_user_id, d.color,
                    (SELECT COUNT(*) FROM {$emp_t} e WHERE e.dept_id = d.id AND e.status = 'active') as employee_count
             FROM {$dept_t} d
             WHERE d.active = 1 AND LOWER(d.name) != 'general'
             ORDER BY d.name ASC",
            ARRAY_A
        );

        // Get employees without department
        $unassigned_employees = $wpdb->get_results(
            "SELECT id, employee_code, first_name, last_name, position, photo_id, user_id
             FROM {$emp_t}
             WHERE (dept_id IS NULL OR dept_id = 0) AND status = 'active'
             ORDER BY first_name, last_name ASC",
            ARRAY_A
        );

        // Get GM info for org chart
        $gm_user_id = (int) get_option( 'sfs_hr_leave_gm_approver', 0 );
        if ( ! $gm_user_id ) {
            $loan_settings = \SFS\HR\Modules\Loans\LoansModule::get_settings();
            $gm_user_ids_arr = $loan_settings['gm_user_ids'] ?? [];
            $gm_user_id = ! empty( $gm_user_ids_arr ) ? (int) $gm_user_ids_arr[0] : 0;
        }
        $gm_info = null;
        $gm_employee = null;
        if ( $gm_user_id ) {
            $gm_user = get_user_by( 'id', $gm_user_id );
            if ( $gm_user ) {
                $gm_info = $gm_user;
                $gm_employee = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, photo_id, position FROM {$emp_t} WHERE user_id = %d",
                    $gm_user_id
                ), ARRAY_A );
            }
        }

        // Current view mode
        $org_view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'chart';
        $base_org_url = admin_url('admin.php?page=sfs-hr-employees&tab=organization');

        ?>
        <style>
            .sfs-hr-org-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
                gap: 24px;
                margin-top: 20px;
            }
            .sfs-hr-org-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                overflow: hidden;
            }
            .sfs-hr-org-card-header {
                padding: 18px 20px;
            }
            .sfs-hr-org-card-header h3 {
                margin: 0 0 6px 0;
                font-size: 17px;
                font-weight: 600;
                color: #fff;
                text-shadow: 0 1px 2px rgba(0,0,0,0.2);
            }
            .sfs-hr-org-card-header .dept-count {
                font-size: 13px;
                color: #fff;
                opacity: 0.95;
                font-weight: 500;
            }
            .sfs-hr-org-manager {
                padding: 16px 20px;
                background: #f6f7f7;
                border-bottom: 1px solid #dcdcde;
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .sfs-hr-org-manager-avatar {
                width: 48px;
                height: 48px;
                border-radius: 50%;
                background: #2271b1;
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                font-weight: 600;
                flex-shrink: 0;
                overflow: hidden;
            }
            .sfs-hr-org-manager-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .sfs-hr-org-manager-info h4 {
                margin: 0 0 2px 0;
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
            }
            .sfs-hr-org-manager-info span {
                font-size: 12px;
                color: #646970;
            }
            .sfs-hr-org-manager-badge {
                margin-left: auto;
                background: #2271b1;
                color: #fff;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 500;
            }
            .sfs-hr-org-employees {
                padding: 12px 20px;
                max-height: 300px;
                overflow-y: auto;
            }
            .sfs-hr-org-employee {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f1;
            }
            .sfs-hr-org-employee:last-child {
                border-bottom: none;
            }
            .sfs-hr-org-employee-avatar {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: #dcdcde;
                color: #50575e;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 14px;
                font-weight: 500;
                flex-shrink: 0;
                overflow: hidden;
            }
            .sfs-hr-org-employee-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .sfs-hr-org-employee-info {
                flex: 1;
                min-width: 0;
            }
            .sfs-hr-org-employee-info .name {
                font-size: 13px;
                font-weight: 500;
                color: #1d2327;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .sfs-hr-org-employee-info .position {
                font-size: 12px;
                color: #646970;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .sfs-hr-org-employee-code {
                font-size: 11px;
                color: #787c82;
                background: #f0f0f1;
                padding: 2px 8px;
                border-radius: 10px;
            }
            .sfs-hr-org-empty {
                padding: 20px;
                text-align: center;
                color: #646970;
                font-style: italic;
            }
            .sfs-hr-org-no-manager {
                padding: 16px 20px;
                background: #fff8e5;
                border-bottom: 1px solid #dcdcde;
                color: #996800;
                font-size: 13px;
            }
            .sfs-hr-org-unassigned {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 2px solid #dcdcde;
            }
            .sfs-hr-org-unassigned h3 {
                margin: 0 0 16px 0;
                color: #646970;
                font-size: 14px;
                font-weight: 600;
            }
            .sfs-hr-org-unassigned-list {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
            }
            .sfs-hr-org-unassigned-item {
                display: flex;
                align-items: center;
                gap: 10px;
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                padding: 10px 14px;
            }
            @media (max-width: 782px) {
                .sfs-hr-org-grid {
                    grid-template-columns: 1fr;
                }
            }

            /* View Toggle */
            .sfs-hr-org-view-toggle {
                display: flex;
                gap: 8px;
                margin-bottom: 20px;
            }
            .sfs-hr-org-view-btn {
                padding: 8px 16px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                background: #fff;
                color: #50575e;
                text-decoration: none;
                font-size: 13px;
                font-weight: 500;
                transition: all 0.15s;
            }
            .sfs-hr-org-view-btn:hover {
                border-color: #2271b1;
                color: #2271b1;
            }
            .sfs-hr-org-view-btn.active {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
            }

            /* Org Chart Styles */
            .sfs-hr-org-chart {
                padding: 40px 20px;
                background: #f6f7f7;
                border-radius: 8px;
                margin-top: 20px;
                overflow-x: auto;
            }
            .sfs-hr-org-chart-inner {
                display: flex;
                flex-direction: column;
                align-items: center;
                min-width: fit-content;
            }
            .sfs-hr-chart-node {
                background: #fff;
                border: 2px solid #dcdcde;
                border-radius: 8px;
                padding: 16px 20px;
                text-align: center;
                width: 200px;
                box-sizing: border-box;
                position: relative;
            }
            .sfs-hr-chart-node.gm {
                border-color: #d63638;
                background: linear-gradient(135deg, #fff 0%, #fee2e2 100%);
            }
            .sfs-hr-chart-node.manager {
                background: #fff;
            }
            .sfs-hr-chart-avatar {
                width: 56px;
                height: 56px;
                border-radius: 50%;
                margin: 0 auto 10px;
                overflow: hidden;
                border: 3px solid #fff;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            .sfs-hr-chart-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .sfs-hr-chart-avatar-placeholder {
                width: 100%;
                height: 100%;
                background: #2271b1;
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 22px;
                font-weight: 600;
            }
            .sfs-hr-chart-node.gm .sfs-hr-chart-avatar-placeholder {
                background: #d63638;
            }
            .sfs-hr-chart-name {
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
                margin-bottom: 4px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .sfs-hr-chart-name a {
                color: inherit;
                text-decoration: none;
            }
            .sfs-hr-chart-name a:hover {
                color: #2271b1;
            }
            .sfs-hr-chart-role {
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                padding: 3px 10px;
                border-radius: 10px;
                display: inline-block;
                margin-bottom: 6px;
                color: #fff;
            }
            .sfs-hr-chart-node.gm .sfs-hr-chart-role {
                background: #d63638;
            }
            .sfs-hr-chart-position {
                font-size: 12px;
                color: #646970;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .sfs-hr-chart-dept {
                font-size: 11px;
                color: #787c82;
                margin-top: 4px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .sfs-hr-chart-connector {
                width: 2px;
                height: 30px;
                background: #c3c4c7;
                margin: 0 auto;
            }
            /* Level wrapper - inline-flex to fit content width */
            .sfs-hr-chart-level-wrap {
                position: relative;
                display: inline-flex;
                flex-direction: column;
                align-items: center;
                margin-top: 0;
            }
            /* Horizontal line spanning content width only */
            .sfs-hr-chart-level-wrap::before {
                content: '';
                position: absolute;
                top: 0;
                left: 100px;
                right: 100px;
                height: 2px;
                background: #c3c4c7;
            }
            /* Manager level - single row, no wrap */
            .sfs-hr-chart-level {
                display: flex;
                flex-wrap: nowrap;
                justify-content: center;
                gap: 30px;
                padding-top: 30px;
            }
            .sfs-hr-chart-branch {
                display: flex;
                flex-direction: column;
                align-items: center;
                position: relative;
                flex-shrink: 0;
            }
            /* Vertical connector from horizontal line to each branch */
            .sfs-hr-chart-branch::before {
                content: '';
                position: absolute;
                top: -30px;
                left: 50%;
                transform: translateX(-50%);
                width: 2px;
                height: 30px;
                background: #c3c4c7;
            }
            .sfs-hr-chart-employees {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                justify-content: center;
                margin-top: 12px;
                max-width: 200px;
            }
            .sfs-hr-chart-emp-chip {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 4px 10px 4px 4px;
                background: #f0f0f1;
                border-radius: 20px;
                font-size: 12px;
                color: #1d2327;
                text-decoration: none;
            }
            .sfs-hr-chart-emp-chip:hover {
                background: #e0e0e0;
            }
            .sfs-hr-chart-emp-chip .mini-avatar {
                width: 22px;
                height: 22px;
                border-radius: 50%;
                background: #787c82;
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 10px;
                font-weight: 600;
                overflow: hidden;
            }
            .sfs-hr-chart-emp-chip .mini-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .sfs-hr-chart-no-gm {
                text-align: center;
                padding: 20px;
                color: #646970;
                font-style: italic;
            }

            /* ── Employee visibility toggle ── */
            .sfs-hr-org-container:not(.sfs-hr-show-employees) .sfs-hr-chart-employees,
            .sfs-hr-org-container:not(.sfs-hr-show-employees) .sfs-hr-org-employees .sfs-hr-org-employee {
                display: none;
            }
            .sfs-hr-org-container:not(.sfs-hr-show-employees) .sfs-hr-org-employees {
                padding: 16px 20px;
                text-align: center;
            }
            .sfs-hr-org-emp-hidden-hint {
                font-size: 13px;
                color: #787c82;
                font-style: italic;
            }
            .sfs-hr-org-container.sfs-hr-show-employees .sfs-hr-org-emp-hidden-hint {
                display: none;
            }
        </style>
        <script>
        function sfsHrToggleOrgNames(btn) {
            var container = document.querySelector('.sfs-hr-org-container');
            if (!container) return;
            var isShown = container.classList.toggle('sfs-hr-show-employees');
            btn.setAttribute('aria-pressed', isShown ? 'true' : 'false');
            var label = btn.querySelector('.sfs-hr-toggle-label');
            var icon = btn.querySelector('.dashicons');
            if (isShown) {
                label.textContent = '<?php echo esc_js(__('Hide Employees', 'sfs-hr')); ?>';
                icon.className = 'dashicons dashicons-hidden';
                icon.style.verticalAlign = 'middle';
                icon.style.marginRight = '4px';
            } else {
                label.textContent = '<?php echo esc_js(__('Show Employees', 'sfs-hr')); ?>';
                icon.className = 'dashicons dashicons-visibility';
                icon.style.verticalAlign = 'middle';
                icon.style.marginRight = '4px';
            }
        }
        </script>

        <!-- View Toggle & Controls -->
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
            <div class="sfs-hr-org-view-toggle" style="margin-bottom:0;">
                <a href="<?php echo esc_url(add_query_arg('view', 'chart', $base_org_url)); ?>"
                   class="sfs-hr-org-view-btn <?php echo $org_view === 'chart' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-networking" style="vertical-align: middle; margin-right: 4px;"></span>
                    <?php esc_html_e('Org Chart', 'sfs-hr'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('view', 'cards', $base_org_url)); ?>"
                   class="sfs-hr-org-view-btn <?php echo $org_view === 'cards' ? 'active' : ''; ?>">
                    <span class="dashicons dashicons-grid-view" style="vertical-align: middle; margin-right: 4px;"></span>
                    <?php esc_html_e('Cards', 'sfs-hr'); ?>
                </a>
            </div>
            <button type="button" id="sfs-hr-org-toggle-names" class="sfs-hr-org-view-btn" aria-pressed="false" onclick="sfsHrToggleOrgNames(this)">
                <span class="dashicons dashicons-visibility" style="vertical-align: middle; margin-right: 4px;"></span>
                <span class="sfs-hr-toggle-label"><?php esc_html_e('Show Employees', 'sfs-hr'); ?></span>
            </button>
        </div>

        <div class="sfs-hr-org-container">
            <?php if (empty($departments) && empty($unassigned_employees)): ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e('No departments or employees found. Please add departments and employees first.', 'sfs-hr'); ?></p>
                </div>
            <?php elseif ($org_view === 'chart'): ?>
                <!-- Org Chart View -->
                <div class="sfs-hr-org-chart">
                    <div class="sfs-hr-org-chart-inner">
                        <?php if ($gm_info): ?>
                            <!-- GM Node -->
                            <div class="sfs-hr-chart-node gm">
                                <span class="sfs-hr-chart-role"><?php esc_html_e('General Manager', 'sfs-hr'); ?></span>
                                <div class="sfs-hr-chart-avatar">
                                    <?php
                                    $gm_avatar = $gm_employee && !empty($gm_employee['photo_id']) ? wp_get_attachment_image_url($gm_employee['photo_id'], 'thumbnail') : null;
                                    if ($gm_avatar): ?>
                                        <img src="<?php echo esc_url($gm_avatar); ?>" alt="">
                                    <?php else: ?>
                                        <div class="sfs-hr-chart-avatar-placeholder"><?php echo esc_html(strtoupper(substr($gm_info->display_name, 0, 1))); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="sfs-hr-chart-name">
                                    <?php if ($gm_employee): ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $gm_employee['id'])); ?>">
                                            <?php echo esc_html($gm_info->display_name); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo esc_html($gm_info->display_name); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($gm_employee && !empty($gm_employee['position'])): ?>
                                    <div class="sfs-hr-chart-position"><?php echo esc_html($gm_employee['position']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="sfs-hr-chart-connector"></div>
                        <?php else: ?>
                            <div class="sfs-hr-chart-no-gm">
                                <?php esc_html_e('No General Manager assigned. Set one in Leave Settings.', 'sfs-hr'); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Department Managers Level -->
                        <?php if (!empty($departments)): ?>
                            <div class="sfs-hr-chart-level-wrap">
                            <div class="sfs-hr-chart-level">
                                <?php foreach ($departments as $dept):
                                    $manager = null;
                                    $manager_employee = null;
                                    if (!empty($dept['manager_user_id'])) {
                                        $manager = get_user_by('id', $dept['manager_user_id']);
                                        $manager_employee = $wpdb->get_row($wpdb->prepare(
                                            "SELECT id, photo_id, position FROM {$emp_t} WHERE user_id = %d",
                                            $dept['manager_user_id']
                                        ), ARRAY_A);
                                    }

                                    // Get employees in this department (excluding manager)
                                    $dept_employees = $wpdb->get_results($wpdb->prepare(
                                        "SELECT id, first_name, last_name, photo_id
                                         FROM {$emp_t}
                                         WHERE dept_id = %d AND status = 'active'
                                         " . (!empty($dept['manager_user_id']) ? "AND (user_id IS NULL OR user_id != %d)" : "") . "
                                         ORDER BY first_name, last_name ASC
                                         LIMIT 10",
                                        ...(!empty($dept['manager_user_id']) ? [$dept['id'], $dept['manager_user_id']] : [$dept['id']])
                                    ), ARRAY_A);
                                    $remaining_count = max(0, (int)$dept['employee_count'] - count($dept_employees) - ($manager ? 1 : 0));
                                    $dept_color = ! empty( $dept['color'] ) ? $dept['color'] : '#2271b1';
                                ?>
                                    <div class="sfs-hr-chart-branch">
                                        <div class="sfs-hr-chart-node manager" style="border-color: <?php echo esc_attr($dept_color); ?>;">
                                            <span class="sfs-hr-chart-role" style="background: <?php echo esc_attr($dept_color); ?>;"><?php esc_html_e('Manager', 'sfs-hr'); ?></span>
                                            <div class="sfs-hr-chart-avatar">
                                                <?php
                                                $mgr_avatar = $manager_employee && !empty($manager_employee['photo_id']) ? wp_get_attachment_image_url($manager_employee['photo_id'], 'thumbnail') : null;
                                                if ($manager && $mgr_avatar): ?>
                                                    <img src="<?php echo esc_url($mgr_avatar); ?>" alt="">
                                                <?php elseif ($manager): ?>
                                                    <div class="sfs-hr-chart-avatar-placeholder" style="background: <?php echo esc_attr($dept_color); ?>;"><?php echo esc_html(strtoupper(substr($manager->display_name, 0, 1))); ?></div>
                                                <?php else: ?>
                                                    <div class="sfs-hr-chart-avatar-placeholder" style="background: <?php echo esc_attr($dept_color); ?>;">?</div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="sfs-hr-chart-name">
                                                <?php if ($manager && $manager_employee): ?>
                                                    <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $manager_employee['id'])); ?>">
                                                        <?php echo esc_html($manager->display_name); ?>
                                                    </a>
                                                <?php elseif ($manager): ?>
                                                    <?php echo esc_html($manager->display_name); ?>
                                                <?php else: ?>
                                                    <?php esc_html_e('(No manager)', 'sfs-hr'); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="sfs-hr-chart-dept"><?php echo esc_html($dept['name']); ?></div>

                                            <?php if (!empty($dept_employees)): ?>
                                                <div class="sfs-hr-chart-employees">
                                                    <?php foreach ($dept_employees as $de):
                                                        $de_name = trim($de['first_name'] . ' ' . $de['last_name']);
                                                        $de_avatar = !empty($de['photo_id']) ? wp_get_attachment_image_url($de['photo_id'], 'thumbnail') : null;
                                                    ?>
                                                        <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $de['id'])); ?>" class="sfs-hr-chart-emp-chip">
                                                            <span class="mini-avatar">
                                                                <?php if ($de_avatar): ?>
                                                                    <img src="<?php echo esc_url($de_avatar); ?>" alt="">
                                                                <?php else: ?>
                                                                    <?php echo esc_html(strtoupper(substr($de_name, 0, 1))); ?>
                                                                <?php endif; ?>
                                                            </span>
                                                            <?php echo esc_html($de_name); ?>
                                                        </a>
                                                    <?php endforeach; ?>
                                                    <?php if ($remaining_count > 0): ?>
                                                        <a href="<?php echo esc_url(add_query_arg('view', 'cards', $base_org_url)); ?>" class="sfs-hr-chart-emp-chip" style="background:#2271b1; color:#fff;" title="<?php esc_attr_e('View all in Cards', 'sfs-hr'); ?>">+<?php echo $remaining_count; ?></a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            </div><!-- .sfs-hr-chart-level-wrap -->
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- Cards View -->
                <div class="sfs-hr-org-grid">
                    <?php foreach ($departments as $dept):
                        // Get manager info
                        $manager = null;
                        $manager_employee = null;
                        if (!empty($dept['manager_user_id'])) {
                            $manager = get_user_by('id', $dept['manager_user_id']);
                            // Try to get employee record for manager
                            $manager_employee = $wpdb->get_row($wpdb->prepare(
                                "SELECT id, photo_id, position, employee_code FROM {$emp_t} WHERE user_id = %d",
                                $dept['manager_user_id']
                            ), ARRAY_A);
                        }

                        // Get employees in this department (excluding manager)
                        $employees = $wpdb->get_results($wpdb->prepare(
                            "SELECT id, employee_code, first_name, last_name, position, photo_id, user_id
                             FROM {$emp_t}
                             WHERE dept_id = %d AND status = 'active'
                             " . (!empty($dept['manager_user_id']) ? "AND (user_id IS NULL OR user_id != %d)" : "") . "
                             ORDER BY first_name, last_name ASC",
                            ...(!empty($dept['manager_user_id']) ? [$dept['id'], $dept['manager_user_id']] : [$dept['id']])
                        ), ARRAY_A);
                    ?>
                        <?php
                        $dept_color = ! empty( $dept['color'] ) ? $dept['color'] : '#1e3a5f';
                        // Create a slightly lighter shade for gradient
                        $dept_color_light = self::adjust_color_brightness( $dept_color, 30 );
                        ?>
                        <div class="sfs-hr-org-card">
                            <div class="sfs-hr-org-card-header" style="background: linear-gradient(135deg, <?php echo esc_attr( $dept_color ); ?> 0%, <?php echo esc_attr( $dept_color_light ); ?> 100%);">
                                <h3><?php echo esc_html($dept['name']); ?></h3>
                                <span class="dept-count">
                                    <?php echo sprintf(
                                        _n('%d employee', '%d employees', $dept['employee_count'], 'sfs-hr'),
                                        $dept['employee_count']
                                    ); ?>
                                </span>
                            </div>

                            <?php if ($manager): ?>
                                <div class="sfs-hr-org-manager">
                                    <div class="sfs-hr-org-manager-avatar">
                                        <?php
                                        $avatar_url = null;
                                        if ($manager_employee && !empty($manager_employee['photo_id'])) {
                                            $avatar_url = wp_get_attachment_image_url($manager_employee['photo_id'], 'thumbnail');
                                        }
                                        if ($avatar_url): ?>
                                            <img src="<?php echo esc_url($avatar_url); ?>" alt="">
                                        <?php else:
                                            echo esc_html(strtoupper(substr($manager->display_name, 0, 1)));
                                        endif; ?>
                                    </div>
                                    <div class="sfs-hr-org-manager-info">
                                        <h4>
                                            <?php if ($manager_employee): ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $manager_employee['id'])); ?>">
                                                    <?php echo esc_html($manager->display_name); ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo esc_html($manager->display_name); ?>
                                            <?php endif; ?>
                                        </h4>
                                        <span><?php echo esc_html($manager_employee['position'] ?? __('Department Manager', 'sfs-hr')); ?></span>
                                    </div>
                                    <span class="sfs-hr-org-manager-badge"><?php esc_html_e('Manager', 'sfs-hr'); ?></span>
                                </div>
                            <?php else: ?>
                                <div class="sfs-hr-org-no-manager">
                                    <?php esc_html_e('No manager assigned', 'sfs-hr'); ?>
                                </div>
                            <?php endif; ?>

                            <div class="sfs-hr-org-employees">
                                <div class="sfs-hr-org-emp-hidden-hint">
                                    <?php
                                        $hidden_count = max( 0, (int) $dept['employee_count'] - ( $manager_employee ? 1 : 0 ) );
                                        echo sprintf(
                                        esc_html(_n('%d employee', '%d employees', $hidden_count, 'sfs-hr')),
                                        $hidden_count
                                    ); ?>
                                    — <?php esc_html_e('click "Show Employees" to reveal', 'sfs-hr'); ?>
                                </div>
                                <?php if (empty($employees)): ?>
                                    <div class="sfs-hr-org-empty">
                                        <?php esc_html_e('No other employees in this department', 'sfs-hr'); ?>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($employees as $emp):
                                        $emp_avatar_url = !empty($emp['photo_id']) ? wp_get_attachment_image_url($emp['photo_id'], 'thumbnail') : null;
                                        $emp_name = trim($emp['first_name'] . ' ' . $emp['last_name']);
                                        if (empty($emp_name)) {
                                            $emp_name = __('(No name)', 'sfs-hr');
                                        }
                                    ?>
                                        <div class="sfs-hr-org-employee">
                                            <div class="sfs-hr-org-employee-avatar">
                                                <?php if ($emp_avatar_url): ?>
                                                    <img src="<?php echo esc_url($emp_avatar_url); ?>" alt="">
                                                <?php else: ?>
                                                    <?php echo esc_html(strtoupper(substr($emp_name, 0, 1))); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="sfs-hr-org-employee-info">
                                                <div class="name">
                                                    <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $emp['id'])); ?>">
                                                        <?php echo esc_html($emp_name); ?>
                                                    </a>
                                                </div>
                                                <?php if (!empty($emp['position'])): ?>
                                                    <div class="position"><?php echo esc_html($emp['position']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <span class="sfs-hr-org-employee-code"><?php echo esc_html($emp['employee_code']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($unassigned_employees)): ?>
                    <div class="sfs-hr-org-unassigned">
                        <h3><?php esc_html_e('Employees Without Department', 'sfs-hr'); ?></h3>
                        <div class="sfs-hr-org-unassigned-list">
                            <?php foreach ($unassigned_employees as $emp):
                                $emp_avatar_url = !empty($emp['photo_id']) ? wp_get_attachment_image_url($emp['photo_id'], 'thumbnail') : null;
                                $emp_name = trim($emp['first_name'] . ' ' . $emp['last_name']);
                                if (empty($emp_name)) {
                                    $emp_name = __('(No name)', 'sfs-hr');
                                }
                            ?>
                                <div class="sfs-hr-org-unassigned-item">
                                    <div class="sfs-hr-org-employee-avatar">
                                        <?php if ($emp_avatar_url): ?>
                                            <img src="<?php echo esc_url($emp_avatar_url); ?>" alt="">
                                        <?php else: ?>
                                            <?php echo esc_html(strtoupper(substr($emp_name, 0, 1))); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sfs-hr-org-employee-info">
                                        <div class="name">
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $emp['id'])); ?>">
                                                <?php echo esc_html($emp_name); ?>
                                            </a>
                                        </div>
                                        <?php if (!empty($emp['position'])): ?>
                                            <div class="position"><?php echo esc_html($emp['position']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
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
        'employee_code','first_name','last_name','first_name_ar','last_name_ar',
        'email','phone','position','status',
        'hired_at','base_salary','national_id','national_id_expiry','passport_no','passport_expiry',
        'emergency_contact_name','emergency_contact_phone',

        'visa_number','visa_expiry',
        'nationality','marital_status','birth_date',
        'work_location','contract_type','contract_start_date','contract_end_date','probation_end_date','entry_date_ksa',
        'residence_profession','sponsor_name','sponsor_id',
        'driving_license_number','driving_license_expiry',
        'gosi_salary','language',
    ];

    $allowed_status = ['active','inactive','terminated'];

    $date_fields = [
        'hired_at', 'birth_date', 'national_id_expiry', 'passport_expiry',
        'visa_expiry', 'contract_start_date', 'contract_end_date',
        'probation_end_date', 'entry_date_ksa', 'driving_license_expiry',
    ];

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

        // Normalize date fields from dd/mm/yyyy to Y-m-d.
        if ( in_array( $f, $date_fields, true ) && $val !== '' ) {
            $val = Helpers::normalize_date( $val );
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
    $gender_lower = $gender_in !== '' ? strtolower($gender_in) : '';
    $payload['gender'] = in_array($gender_lower, ['male','female'], true) ? $gender_lower : null;

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

    // Get old data for audit log before update
    $old_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

    // Prevent setting status to 'terminated' if employee has future last_working_day
    if ( isset($payload['status']) && $payload['status'] === 'terminated' ) {
        if ( $old_data && ($old_data['status'] ?? '') !== 'terminated' && !Helpers::can_terminate_employee($id) ) {
            Helpers::redirect_with_notice(
                admin_url('admin.php?page=sfs-hr-employees&action=edit&id=' . $id),
                'error',
                __('Cannot terminate: employee has an approved resignation with a future last working day.', 'sfs-hr')
            );
        }
    }

    // Validate language against known options
    if ( isset( $payload['language'] ) && $payload['language'] !== null ) {
        $valid_langs = array_keys( Helpers::get_available_languages() );
        if ( ! in_array( $payload['language'], $valid_langs, true ) ) {
            $payload['language'] = null;
        }
    }

    $wpdb->update($table, $payload, ['id'=>$id]);

    // Sync language preference to WP user locale
    $wp_user_id = (int) ( $old_data['user_id'] ?? 0 );
    if ( $wp_user_id && isset( $payload['language'] ) ) {
        $wp_locale = Helpers::lang_to_wp_locale( $payload['language'] ?? '' );
        update_user_meta( $wp_user_id, 'locale', $wp_locale );
    }

    // Audit log: employee updated
    if ( $old_data ) {
        do_action( 'sfs_hr_employee_updated', $id, $old_data, $payload );
    }
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

            // Only skip if the LATEST assignment for this date is already the same shift.
            // This allows re-assigning a shift that was previously overridden.
            $latest_for_date = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, shift_id FROM {$map_table}
                     WHERE employee_id=%d AND start_date=%s
                     ORDER BY id DESC LIMIT 1",
                    $id,
                    $start_raw
                )
            );

            if ( ! $latest_for_date || (int) $latest_for_date->shift_id !== $shift_id ) {
                $wpdb->insert(
                    $map_table,
                    [
                        'employee_id' => $id,
                        'shift_id'    => $shift_id,
                        'start_date'  => $start_raw,
                    ]
                );
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
        if ($id<=0) { wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=id') ); exit; }
        check_admin_referer('sfs_hr_regen_qr_'.$id, '_sfsqr_regen');

        global $wpdb; $t = $wpdb->prefix.'sfs_hr_employees';
        $tok = bin2hex(random_bytes(24));
        $wpdb->update($t, [
            'qr_token'      => $tok,
            'qr_updated_at' => Helpers::now_mysql(),
        ], ['id'=>$id]);

        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employee-profile&employee_id='.$id.'&mode=edit&ok=qrregen') ); exit;
    }

    public function handle_toggle_qr(): void {
        Helpers::require_cap('sfs_hr.manage');
        $id  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $new = isset($_POST['new']) ? (int)$_POST['new'] : 0;
        if ($id<=0) { wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employees&err=id') ); exit; }
        check_admin_referer('sfs_hr_toggle_qr_'.$id, '_sfsqr_toggle');

        global $wpdb; $t = $wpdb->prefix.'sfs_hr_employees';
        $wpdb->update($t, [
            'qr_enabled'    => $new ? 1 : 0,
            'qr_updated_at' => Helpers::now_mysql(),
        ], ['id'=>$id]);

        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-employee-profile&employee_id='.$id.'&mode=edit&ok=qrtoggle') ); exit;
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
        // Check capability BEFORE reading any POST data
        if ( ! current_user_can('sfs_hr.manage') && ! current_user_can('sfs_hr.view') ) {
            wp_die( esc_html__( 'Permission denied', 'sfs-hr' ) );
        }

        $dept_id = isset($_POST['dept_id']) ? (int)$_POST['dept_id'] : 0;
        if ( $dept_id <= 0 ) {
            wp_die( esc_html__( 'Invalid department', 'sfs-hr' ) );
        }

        // Department managers need additional scope check
        if ( ! current_user_can('sfs_hr.manage') ) {
            if ( ! $this->current_user_is_dept_manager( $dept_id ) ) {
                wp_die( esc_html__( 'Permission denied', 'sfs-hr' ) );
            }
        }

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

        $where  = "dept_id IN ($in) AND status != 'terminated'";
        $params = [];

        if ($q !== '') {
            $like   = '%' . $wpdb->esc_like($q) . '%';
            $where .= " AND (employee_code LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)";
            $params = [$like,$like,$like,$like];
        }

        $total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = (int)($params ? $wpdb->get_var($wpdb->prepare($total_sql, ...$params)) : $wpdb->get_var($total_sql));

        $offset = max(0, ($page-1)*$per_page);
        $rows_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY first_name ASC, last_name ASC LIMIT %d OFFSET %d";
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
            echo '<h2>'.esc_html__('My Team','sfs-hr').'</h2>';
            echo '<p>'.esc_html__('No managed departments found for your account.','sfs-hr').'</p></div>';
            return;
        }

        $q        = isset($_GET['s']) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $page     = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = isset($_GET['per_page']) ? max(5, intval($_GET['per_page'])) : 20;

        list($rows,$total) = $this->query_team_employees($dept_ids, $q, $page, $per_page);
        $pages = max(1, (int)ceil($total / $per_page));

        $dept_map = $this->departments_map();

        ?>
        <style>
          /* My Team Styles */
          .sfs-hr-team-toolbar {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            padding: 16px;
            margin: 16px 0;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
          }
          .sfs-hr-team-toolbar input[type="search"] {
            min-width: 250px;
            height: 36px;
            padding: 0 12px;
            border-radius: 4px;
          }
          .sfs-hr-team-toolbar select {
            height: 36px;
            border-radius: 4px;
          }
          .sfs-hr-team-table {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            margin-top: 16px;
          }
          .sfs-hr-team-table .widefat {
            border: none;
            border-radius: 6px;
            margin: 0;
          }
          .sfs-hr-team-table .widefat th {
            background: #f8f9fa;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #50575e;
            padding: 12px 16px;
          }
          .sfs-hr-team-table .widefat td {
            padding: 12px 16px;
            vertical-align: middle;
          }
          .sfs-hr-team-table .widefat tbody tr:hover {
            background: #f8f9fa;
          }
          .sfs-hr-team-table .emp-name {
            font-weight: 500;
            color: #1d2327;
          }
          .sfs-hr-team-table .emp-code {
            font-family: monospace;
            font-size: 12px;
            background: #f0f0f1;
            padding: 2px 6px;
            border-radius: 3px;
            color: #50575e;
          }

          /* Mobile details button (vertical dots) */
          .sfs-hr-details-btn {
            display: inline-flex;
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid #d1d5db;
            cursor: pointer;
            padding: 0;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: all 0.15s ease;
          }
          .sfs-hr-details-btn::before,
          .sfs-hr-details-btn::after,
          .sfs-hr-details-btn span {
            content: '';
            display: block;
            width: 5px;
            height: 5px;
            background: #2563eb;
            border-radius: 50%;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
          }
          .sfs-hr-details-btn::before {
            top: 11px;
          }
          .sfs-hr-details-btn span {
            top: 50%;
            transform: translate(-50%, -50%);
          }
          .sfs-hr-details-btn::after {
            bottom: 11px;
          }
          .sfs-hr-details-btn:hover {
            background: #f9fafb;
            border-color: #9ca3af;
          }
          .sfs-hr-details-btn:active {
            transform: scale(0.95);
          }

          /* Details Modal */
          .sfs-hr-details-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 100000;
            background: rgba(0,0,0,0.5);
            align-items: flex-end;
            justify-content: center;
          }
          .sfs-hr-details-modal.active {
            display: flex;
          }
          .sfs-hr-details-modal-content {
            background: #fff;
            width: 100%;
            max-width: 400px;
            border-radius: 16px 16px 0 0;
            padding: 20px;
            animation: sfsSlideUp 0.2s ease-out;
            max-height: 80vh;
            overflow-y: auto;
          }
          @keyframes sfsSlideUp {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
          }
          .sfs-hr-details-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e5e5e5;
          }
          .sfs-hr-details-modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #1d2327;
            margin: 0;
          }
          .sfs-hr-details-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #50575e;
            padding: 0;
            line-height: 1;
          }
          .sfs-hr-details-list {
            list-style: none;
            margin: 0;
            padding: 0;
          }
          .sfs-hr-details-list li {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f1;
          }
          .sfs-hr-details-list li:last-child {
            border-bottom: none;
          }
          .sfs-hr-details-label {
            font-weight: 500;
            color: #50575e;
            font-size: 13px;
          }
          .sfs-hr-details-value {
            color: #1d2327;
            font-size: 13px;
            text-align: right;
          }

          /* Pagination */
          .sfs-hr-team-pagination {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 16px;
            background: #fff;
            border: 1px solid #dcdcde;
            border-top: none;
            border-radius: 0 0 6px 6px;
            flex-wrap: wrap;
          }
          .sfs-hr-team-pagination a,
          .sfs-hr-team-pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 10px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
          }
          .sfs-hr-team-pagination a {
            background: #f0f0f1;
            color: #50575e;
          }
          .sfs-hr-team-pagination a:hover {
            background: #dcdcde;
          }
          .sfs-hr-team-pagination .current-page {
            background: #2271b1;
            color: #fff;
            font-weight: 600;
          }

          /* Mobile responsive */
          @media (max-width: 782px) {
            .sfs-hr-team-toolbar {
              flex-direction: column;
              align-items: stretch;
              padding: 12px;
            }
            .sfs-hr-team-toolbar input[type="search"] {
              width: 100%;
              min-width: auto;
            }
            .sfs-hr-team-toolbar select {
              width: 100%;
            }
            .sfs-hr-team-toolbar .button {
              width: 100%;
              text-align: center;
            }

            /* Hide columns on mobile - only show Name and Details button */
            .sfs-hr-team-table .widefat thead th.hide-mobile,
            .sfs-hr-team-table .widefat tbody td.hide-mobile {
              display: none !important;
            }

            .sfs-hr-team-table .widefat th,
            .sfs-hr-team-table .widefat td {
              padding: 10px 12px;
            }

            /* Show details button on mobile */
            .sfs-hr-details-btn {
              display: inline-flex;
            }

            .sfs-hr-team-pagination {
              justify-content: center;
            }
          }
        </style>

        <!-- Details Modal -->
        <div class="sfs-hr-details-modal" id="sfs-hr-details-modal">
          <div class="sfs-hr-details-modal-content">
            <div class="sfs-hr-details-modal-header">
              <h3 class="sfs-hr-details-modal-title" id="sfs-hr-details-name"><?php esc_html_e( 'Employee Details', 'sfs-hr' ); ?></h3>
              <button type="button" class="sfs-hr-details-modal-close" onclick="sfsHrCloseDetailsModal()">&times;</button>
            </div>
            <ul class="sfs-hr-details-list">
              <li><span class="sfs-hr-details-label"><?php esc_html_e( 'Code', 'sfs-hr' ); ?></span><span class="sfs-hr-details-value" id="sfs-hr-details-code"></span></li>
              <li><span class="sfs-hr-details-label"><?php esc_html_e( 'Email', 'sfs-hr' ); ?></span><span class="sfs-hr-details-value" id="sfs-hr-details-email"></span></li>
              <li><span class="sfs-hr-details-label"><?php esc_html_e( 'Department', 'sfs-hr' ); ?></span><span class="sfs-hr-details-value" id="sfs-hr-details-dept"></span></li>
              <li><span class="sfs-hr-details-label"><?php esc_html_e( 'Position', 'sfs-hr' ); ?></span><span class="sfs-hr-details-value" id="sfs-hr-details-position"></span></li>
              <li><span class="sfs-hr-details-label"><?php esc_html_e( 'Status', 'sfs-hr' ); ?></span><span class="sfs-hr-details-value" id="sfs-hr-details-status"></span></li>
            </ul>
          </div>
        </div>

        <script>
        function sfsHrOpenDetailsModal(name, code, email, dept, position, status) {
          document.getElementById('sfs-hr-details-name').textContent = name || <?php echo wp_json_encode( __( 'Employee Details', 'sfs-hr' ) ); ?>;
          document.getElementById('sfs-hr-details-code').textContent = code;
          document.getElementById('sfs-hr-details-email').textContent = email || '-';
          document.getElementById('sfs-hr-details-dept').textContent = dept;
          document.getElementById('sfs-hr-details-position').textContent = position || '-';
          document.getElementById('sfs-hr-details-status').textContent = status;
          document.getElementById('sfs-hr-details-modal').classList.add('active');
          document.body.style.overflow = 'hidden';
        }
        function sfsHrCloseDetailsModal() {
          document.getElementById('sfs-hr-details-modal').classList.remove('active');
          document.body.style.overflow = '';
        }
        document.getElementById('sfs-hr-details-modal').addEventListener('click', function(e) {
          if (e.target === this) sfsHrCloseDetailsModal();
        });
        </script>

          <h2><?php echo esc_html__('My Team','sfs-hr'); ?> <span style="font-weight:normal; font-size:14px; color:#50575e;">(<?php echo (int)$total; ?> <?php esc_html_e('members','sfs-hr'); ?>)</span></h2>

          <form method="get" class="sfs-hr-team-toolbar">
            <input type="hidden" name="page" value="sfs-hr-my-team" />
            <input type="search" name="s" value="<?php echo esc_attr($q); ?>" placeholder="<?php echo esc_attr__('Search name/email/code','sfs-hr'); ?>"/>
            <select name="per_page">
              <?php foreach ([10,20,50,100] as $pp): ?>
                <option value="<?php echo (int)$pp; ?>" <?php selected($per_page,$pp); ?>><?php printf(esc_html__('%d/page', 'sfs-hr'), (int)$pp); ?></option>
              <?php endforeach; ?>
            </select>
            <?php submit_button(__('Search','sfs-hr'),'primary','',false); ?>
          </form>

          <div class="sfs-hr-team-table">
            <table class="widefat striped">
              <thead><tr>
                <th class="hide-mobile"><?php esc_html_e('ID','sfs-hr'); ?></th>
                <th class="hide-mobile"><?php esc_html_e('Code','sfs-hr'); ?></th>
                <th><?php esc_html_e('Name','sfs-hr'); ?></th>
                <th class="hide-mobile"><?php esc_html_e('Email','sfs-hr'); ?></th>
                <th class="hide-mobile"><?php esc_html_e('Department','sfs-hr'); ?></th>
                <th class="hide-mobile"><?php esc_html_e('Position','sfs-hr'); ?></th>
                <th class="hide-mobile"><?php esc_html_e('Status','sfs-hr'); ?></th>
                <th style="width:50px;"></th>
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
                  <td class="hide-mobile"><?php echo (int)$r['id']; ?></td>
                  <td class="hide-mobile"><span class="emp-code"><?php echo esc_html($r['employee_code']); ?></span></td>
                  <td><span class="emp-name"><?php echo esc_html($name ?: $r['employee_code']); ?></span></td>
                  <td class="hide-mobile"><?php echo esc_html($r['email']); ?></td>
                  <td class="hide-mobile"><?php echo esc_html($dept_name); ?></td>
                  <td class="hide-mobile"><?php echo esc_html($r['position']); ?></td>
                  <td class="hide-mobile"><span class="sfs-hr-badge status-<?php echo esc_attr($status); ?>"><?php echo esc_html(__(ucfirst($status), 'sfs-hr')); ?></span></td>
                  <td>
                    <button type="button" class="sfs-hr-details-btn" onclick="sfsHrOpenDetailsModal('<?php echo esc_js($name ?: $r['employee_code']); ?>', '<?php echo esc_js($r['employee_code']); ?>', '<?php echo esc_js($r['email']); ?>', '<?php echo esc_js($dept_name); ?>', '<?php echo esc_js($r['position']); ?>', '<?php echo esc_js(__(ucfirst($status), 'sfs-hr')); ?>')">
                      <span></span>
                    </button>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <?php if ($pages > 1): ?>
          <div class="sfs-hr-team-pagination">
            <?php for($i=1;$i<=$pages;$i++): ?>
              <?php if ($i === $page): ?>
                <span class="current-page"><?php echo (int)$i; ?></span>
              <?php else: ?>
                <a href="<?php echo esc_url( add_query_arg(['paged'=>$i,'per_page'=>$per_page,'s'=>$q], admin_url('admin.php?page=sfs-hr-my-team')) ); ?>"><?php echo (int)$i; ?></a>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php
    }
    /**
     * Render Reports page - Custom Report Builder
     */
    public function render_reports(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied', 'sfs-hr' ) );
        }

        global $wpdb;

        // Get departments for filter
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $departments = $wpdb->get_results( "SELECT id, name FROM {$dept_table} WHERE active = 1 ORDER BY name ASC" );

        // Handle report generation
        $report_data = [];
        $report_type = '';
        $generated = false;

        if ( isset( $_GET['generate'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'sfs_hr_generate_report' ) ) {
            $report_type = sanitize_key( $_GET['report_type'] ?? '' );
            $date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
            $date_to = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );
            $dept_filter = intval( $_GET['dept'] ?? 0 );
            $export_csv = ! empty( $_GET['export'] );

            $report_data = $this->generate_report( $report_type, $date_from, $date_to, $dept_filter );
            $generated = true;

            if ( $export_csv && ! empty( $report_data['rows'] ) ) {
                $this->export_report_csv( $report_type, $report_data );
                return;
            }
        }

        ?>
        <div class="wrap sfs-hr-wrap">
            <?php Helpers::render_admin_nav(); ?>

            <h1><?php esc_html_e( 'Report Builder', 'sfs-hr' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Generate custom reports with filters and export to CSV.', 'sfs-hr' ); ?></p>

            <div style="background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:20px; margin:20px 0; max-width:800px;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="sfs-hr-reports" />
                    <input type="hidden" name="generate" value="1" />
                    <?php wp_nonce_field( 'sfs_hr_generate_report', '_wpnonce', false ); ?>

                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th scope="row"><label for="report_type"><?php esc_html_e( 'Report Type', 'sfs-hr' ); ?></label></th>
                            <td>
                                <select name="report_type" id="report_type" required style="min-width:250px;">
                                    <option value=""><?php esc_html_e( '— Select Report —', 'sfs-hr' ); ?></option>
                                    <option value="attendance_summary" <?php selected( $report_type, 'attendance_summary' ); ?>><?php esc_html_e( 'Attendance Summary', 'sfs-hr' ); ?></option>
                                    <option value="leave_report" <?php selected( $report_type, 'leave_report' ); ?>><?php esc_html_e( 'Leave Report', 'sfs-hr' ); ?></option>
                                    <option value="employee_directory" <?php selected( $report_type, 'employee_directory' ); ?>><?php esc_html_e( 'Employee Directory', 'sfs-hr' ); ?></option>
                                    <option value="headcount_by_dept" <?php selected( $report_type, 'headcount_by_dept' ); ?>><?php esc_html_e( 'Headcount by Department', 'sfs-hr' ); ?></option>
                                    <option value="contract_expiry" <?php selected( $report_type, 'contract_expiry' ); ?>><?php esc_html_e( 'Contract Expiry Report', 'sfs-hr' ); ?></option>
                                    <option value="tenure_report" <?php selected( $report_type, 'tenure_report' ); ?>><?php esc_html_e( 'Employee Tenure Report', 'sfs-hr' ); ?></option>
                                    <option value="document_expiry" <?php selected( $report_type, 'document_expiry' ); ?>><?php esc_html_e( 'Document Expiry Report', 'sfs-hr' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label><?php esc_html_e( 'Date Range', 'sfs-hr' ); ?></label></th>
                            <td>
                                <?php $rpt_period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period(); ?>
                                <input type="date" name="date_from" value="<?php echo esc_attr( isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : $rpt_period['start'] ); ?>" style="width:150px;" />
                                <span style="margin:0 8px;"><?php esc_html_e( 'to', 'sfs-hr' ); ?></span>
                                <input type="date" name="date_to" value="<?php echo esc_attr( isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : $rpt_period['end'] ); ?>" style="width:150px;" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dept"><?php esc_html_e( 'Department', 'sfs-hr' ); ?></label></th>
                            <td>
                                <select name="dept" id="dept" style="min-width:250px;">
                                    <option value="0"><?php esc_html_e( 'All Departments', 'sfs-hr' ); ?></option>
                                    <?php foreach ( $departments as $d ): ?>
                                        <option value="<?php echo intval( $d->id ); ?>" <?php selected( isset( $_GET['dept'] ) ? intval( $_GET['dept'] ) : 0, $d->id ); ?>><?php echo esc_html( $d->name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top:20px; display:flex; gap:10px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Generate Report', 'sfs-hr' ); ?></button>
                        <button type="submit" name="export" value="csv" class="button"><?php esc_html_e( 'Export CSV', 'sfs-hr' ); ?></button>
                    </div>
                </form>
            </div>

            <?php if ( $generated && ! empty( $report_data ) ): ?>
            <div style="background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:20px; margin:20px 0;">
                <h2 style="margin-top:0;"><?php echo esc_html( $report_data['title'] ?? __( 'Report Results', 'sfs-hr' ) ); ?></h2>
                <p class="description"><?php echo esc_html( $report_data['description'] ?? '' ); ?></p>

                <?php if ( empty( $report_data['rows'] ) ): ?>
                    <div class="notice notice-info" style="margin:15px 0;"><p><?php esc_html_e( 'No data found for the selected criteria.', 'sfs-hr' ); ?></p></div>
                <?php else: ?>
                    <p><strong><?php printf( esc_html__( 'Total Records: %d', 'sfs-hr' ), count( $report_data['rows'] ) ); ?></strong></p>

                    <div style="overflow-x:auto;">
                        <table class="wp-list-table widefat striped" style="margin-top:15px;">
                            <thead>
                                <tr>
                                    <?php foreach ( $report_data['columns'] as $col ): ?>
                                        <th><?php echo esc_html( $col ); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $report_data['rows'] as $row ): ?>
                                    <tr>
                                        <?php foreach ( $row as $val ): ?>
                                            <td><?php echo esc_html( $val ); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Generate report data based on type and filters
     * @deprecated Use ReportsService::generate() directly
     */
    private function generate_report( string $type, string $date_from, string $date_to, int $dept_id ): array {
        return ReportsService::generate( $type, $date_from, $date_to, $dept_id );
    }

    /**
     * Export report to CSV
     * @deprecated Use ReportsService::export_csv() directly
     */
    private function export_report_csv( string $type, array $report_data ): void {
        ReportsService::export_csv( $type, $report_data );
    }

    /**
     * Render Settings page
     */
    public function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied', 'sfs-hr' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'notifications';
        $settings = Notifications::get_settings();

        ?>
        <div class="wrap sfs-hr-wrap">
            <?php Helpers::render_admin_nav(); ?>

            <h1><?php esc_html_e( 'HR Settings', 'sfs-hr' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-settings&tab=notifications' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'notifications' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Notifications', 'sfs-hr' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-settings&tab=employees' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'employees' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Employees', 'sfs-hr' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-settings&tab=documents' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'documents' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Documents', 'sfs-hr' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-settings&tab=shortcodes' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'shortcodes' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Shortcodes', 'sfs-hr' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-settings&tab=pages' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'pages' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Pages', 'sfs-hr' ); ?>
                </a>
            </nav>

            <div class="sfs-hr-settings-content" style="margin-top: 20px;">
                <?php
                if ( $tab === 'notifications' ) {
                    $this->render_notification_settings( $settings );
                } elseif ( $tab === 'employees' ) {
                    $this->render_employees_settings();
                } elseif ( $tab === 'documents' ) {
                    $this->render_document_settings();
                } elseif ( $tab === 'shortcodes' ) {
                    $this->render_shortcodes_reference();
                } elseif ( $tab === 'pages' ) {
                    $this->render_pages_settings();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render government support reminder banner on the admin dashboard.
     *
     * Queries employees matching the configured nationality and threshold,
     * then displays a blue info banner for each qualifying employee.
     */
    private function render_gov_support_dashboard_banner( string $emp_t ): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = get_option( 'sfs_hr_gov_support_settings', [] );
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        $nationality      = $settings['nationality'] ?? 'Saudi';
        $threshold_months = max( 1, min( 120, (int) ( $settings['threshold_months'] ?? 3 ) ) );

        global $wpdb;

        $cutoff_date = wp_date( 'Y-m-d', strtotime( "-{$threshold_months} months" ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $qualifying = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, employee_code, nationality, hired_at
             FROM {$emp_t}
             WHERE status = 'active'
               AND LOWER(nationality) = LOWER(%s)
               AND hired_at IS NOT NULL
               AND hired_at <= %s
             ORDER BY hired_at ASC",
            $nationality,
            $cutoff_date
        ), ARRAY_A );

        if ( empty( $qualifying ) ) {
            return;
        }

        // Filter out dismissed employees.
        $dismissed = get_option( 'sfs_hr_gov_support_dismissed', [] );
        if ( ! is_array( $dismissed ) ) {
            $dismissed = [];
        }

        $to_show    = [];
        $email_ids  = [];
        $email_data = [];
        foreach ( $qualifying as $emp ) {
            $emp_id = (int) $emp['id'];
            if ( ! in_array( $emp_id, $dismissed, true ) ) {
                $to_show[]              = $emp;
                $email_ids[]            = $emp_id;
                $email_data[ $emp_id ]  = $emp;
            }
        }

        // Schedule email sending in the background instead of during render.
        if ( ! empty( $email_ids ) && ! wp_next_scheduled( 'sfs_hr_queue_gov_support_emails' ) ) {
            wp_schedule_single_event( time(), 'sfs_hr_queue_gov_support_emails', [ $email_ids, $email_data ] );
        }

        if ( empty( $to_show ) ) {
            return;
        }

        $nonce    = wp_create_nonce( 'sfs_hr_dismiss_gov_support' );
        $ajax_url = admin_url( 'admin-ajax.php' );

        foreach ( $to_show as $emp ) {
            $emp_id    = (int) $emp['id'];
            $name      = trim( ( $emp['first_name'] ?? '' ) . ' ' . ( $emp['last_name'] ?? '' ) );
            $banner_id = 'sfs-gov-support-' . $emp_id;
            $msg       = sprintf(
                /* translators: 1: employee name, 2: nationality, 3: number of months */
                esc_html__( '%1$s is a %2$s national and has been employed for more than %3$d months. Please check if government support registration is required.', 'sfs-hr' ),
                '<strong>' . esc_html( $name ) . '</strong>',
                esc_html( $nationality ),
                $threshold_months
            );

            echo '<div id="' . esc_attr( $banner_id ) . '" class="notice notice-info" style="display:flex;align-items:flex-start;gap:12px;padding:12px 16px;border-left-color:#2271b1;margin:5px 0 15px;">';
            echo '<div style="flex:1;">';
            echo '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Government Support Reminder', 'sfs-hr' ) . '</strong></p>';
            echo '<p style="margin:0 0 8px;">' . $msg . '</p>';
            echo '<button type="button" class="button button-small sfs-dismiss-gov-support" data-emp-id="' . esc_attr( $emp_id ) . '">' . esc_html__( 'Dismiss', 'sfs-hr' ) . '</button>';
            echo '</div>';
            echo '</div>';
        }

        // Inline dismiss JS (once).
        ?>
        <script>
        document.querySelectorAll('.sfs-dismiss-gov-support').forEach(function(btn){
            btn.addEventListener('click', function(){
                var empId = this.getAttribute('data-emp-id');
                var banner = document.getElementById('sfs-gov-support-' + empId);
                if(banner) banner.style.display = 'none';
                var fd = new FormData();
                fd.append('action', 'sfs_hr_dismiss_gov_support');
                fd.append('_wpnonce', <?php echo wp_json_encode( $nonce ); ?>);
                fd.append('emp_id', empId);
                fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {method:'POST', credentials:'same-origin', body:fd});
            });
        });
        </script>
        <?php
    }

    /**
     * Render employees settings tab (Nationalities + Government Support Reminder).
     */
    private function render_employees_settings(): void {
        $nationalities = get_option( 'sfs_hr_nationalities', [] );
        if ( ! is_array( $nationalities ) ) {
            $nationalities = [];
        }
        if ( empty( $nationalities ) ) {
            $nationalities = Helpers::get_nationalities_for_select();
        }
        $nationalities_str = implode( "\n", $nationalities );

        $gov_settings    = get_option( 'sfs_hr_gov_support_settings', [] );
        $gov_enabled     = ! empty( $gov_settings['enabled'] );
        $gov_months      = max( 1, min( 120, (int) ( $gov_settings['threshold_months'] ?? 3 ) ) );
        $gov_nationality = $gov_settings['nationality'] ?? 'Saudi';

        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'sfs_hr_employees_settings', '_sfs_emp_nonce' ); ?>
            <input type="hidden" name="action" value="sfs_hr_save_employees_settings" />

            <div class="postbox" style="margin-bottom:20px;">
                <div class="postbox-header"><h2 style="padding:8px 12px;"><?php esc_html_e( 'Nationality Settings', 'sfs-hr' ); ?></h2></div>
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="emp-nationalities"><?php esc_html_e( 'Allowed Nationalities', 'sfs-hr' ); ?></label></th>
                            <td>
                                <textarea id="emp-nationalities" name="emp[nationalities]" rows="8" class="large-text" placeholder="<?php esc_attr_e( 'One nationality per line', 'sfs-hr' ); ?>"><?php echo esc_textarea( $nationalities_str ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'One nationality per line. These appear in the nationality dropdown when adding/editing employees.', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="postbox" style="margin-bottom:20px;">
                <div class="postbox-header"><h2 style="padding:8px 12px;"><?php esc_html_e( 'Government Support Reminder', 'sfs-hr' ); ?></h2></div>
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enable', 'sfs-hr' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="emp[gov_enabled]" value="1" <?php checked( $gov_enabled ); ?> />
                                    <?php esc_html_e( 'Enable government support reminder', 'sfs-hr' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gov-nationality"><?php esc_html_e( 'Nationality to Monitor', 'sfs-hr' ); ?></label></th>
                            <td>
                                <input type="text" id="gov-nationality" name="emp[gov_nationality]" value="<?php echo esc_attr( $gov_nationality ); ?>" class="regular-text" />
                                <p class="description"><?php esc_html_e( 'Employees with this nationality will trigger the reminder. Must match the nationality value exactly.', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="gov-months"><?php esc_html_e( 'Threshold (Months)', 'sfs-hr' ); ?></label></th>
                            <td>
                                <input type="number" id="gov-months" name="emp[gov_months]" value="<?php echo esc_attr( $gov_months ); ?>" min="1" max="120" class="small-text" />
                                <p class="description"><?php esc_html_e( 'Alert HR when an employee of the monitored nationality has been employed for this many months (based on hire date).', 'sfs-hr' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php submit_button( __( 'Save Employees Settings', 'sfs-hr' ) ); ?>
        </form>
        <?php
    }

    /**
     * Render shortcodes reference tab
     */
    private function render_shortcodes_reference(): void {
        $shortcodes = [
            [
                'tag'         => 'sfs_hr_my_profile',
                'description' => __( 'Employee self-service profile with tabs for overview, leave, attendance, loans, resignation, settlement, and documents. Includes dark mode, multi-language, and PWA install prompt.', 'sfs-hr' ),
                'params'      => [],
                'capability'  => __( 'Logged-in employee', 'sfs-hr' ),
            ],
            [
                'tag'         => 'sfs_hr_attendance_widget',
                'description' => __( 'Self-service clock in/out widget with live clock, selfie capture, geofence validation, and break management.', 'sfs-hr' ),
                'params'      => [
                    'immersive' => __( '1 (default) = hides theme chrome for fullscreen feel, 0 = inline', 'sfs-hr' ),
                ],
                'capability'  => 'sfs_hr_attendance_clock_self',
            ],
            [
                'tag'         => 'sfs_hr_kiosk',
                'description' => __( 'Fullscreen attendance kiosk with QR code scanning, selfie capture, and multi-employee support. Designed for shared devices at workplace entrances.', 'sfs-hr' ),
                'params'      => [
                    'device'    => __( 'Kiosk device ID (default: auto-selects first active kiosk)', 'sfs-hr' ),
                    'immersive' => __( '1 (default) = fullscreen mode, 0 = inline', 'sfs-hr' ),
                ],
                'capability'  => 'sfs_hr_attendance_clock_kiosk',
            ],
            [
                'tag'         => 'sfs_hr_leave_widget',
                'description' => __( 'Leave dashboard showing KPIs (requests count, available days, used days, annual balance) and recent leave requests.', 'sfs-hr' ),
                'params'      => [],
                'capability'  => __( 'Logged-in employee', 'sfs-hr' ),
            ],
            [
                'tag'         => 'sfs_hr_resignation_submit',
                'description' => __( 'Resignation submission form with date picker, type selection (regular/final exit), and notice period display.', 'sfs-hr' ),
                'params'      => [],
                'capability'  => __( 'Logged-in employee', 'sfs-hr' ),
            ],
            [
                'tag'         => 'sfs_hr_my_resignations',
                'description' => __( 'Lists all submitted resignations for the employee with status badges and approval tracking.', 'sfs-hr' ),
                'params'      => [],
                'capability'  => __( 'Logged-in employee', 'sfs-hr' ),
            ],
            [
                'tag'         => 'sfs_hr_my_loans',
                'description' => __( 'Employee loan listing showing loan ID, status, principal, outstanding balance, monthly installment, and period.', 'sfs-hr' ),
                'params'      => [],
                'capability'  => __( 'Logged-in employee', 'sfs-hr' ),
            ],
            [
                'tag'         => 'sfs_hr_leave_request',
                'description' => __( 'Deprecated — redirects to the leave tab in sfs_hr_my_profile.', 'sfs-hr' ),
                'params'      => [],
                'capability'  => __( 'Logged-in employee', 'sfs-hr' ),
                'deprecated'  => true,
            ],
            [
                'tag'         => 'sfs_hr_my_leaves',
                'description' => __( 'Deprecated — redirects to the leave tab in sfs_hr_my_profile.', 'sfs-hr' ),
                'params'      => [],
                'capability'  => __( 'Logged-in employee', 'sfs-hr' ),
                'deprecated'  => true,
            ],
        ];
        ?>
        <style>
            .sfs-hr-shortcodes { max-width: 900px; }
            .sfs-hr-sc-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 6px;
                padding: 16px 20px;
                margin-bottom: 12px;
            }
            .sfs-hr-sc-card.deprecated { opacity: 0.6; }
            .sfs-hr-sc-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
            }
            .sfs-hr-sc-tag {
                font-family: monospace;
                font-size: 14px;
                font-weight: 600;
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 4px 10px;
                color: #1d2327;
                cursor: pointer;
                user-select: all;
            }
            .sfs-hr-sc-tag:hover { background: #e0e0e0; }
            .sfs-hr-sc-copy {
                background: none;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 4px 10px;
                cursor: pointer;
                font-size: 12px;
                color: #2271b1;
                white-space: nowrap;
            }
            .sfs-hr-sc-copy:hover { background: #f6f7f7; }
            .sfs-hr-sc-copy.copied {
                background: #00a32a;
                color: #fff;
                border-color: #00a32a;
            }
            .sfs-hr-sc-badge {
                font-size: 11px;
                padding: 2px 8px;
                border-radius: 10px;
                font-weight: 500;
            }
            .sfs-hr-sc-badge--dep {
                background: #fcf0f1;
                color: #8b1a1a;
            }
            .sfs-hr-sc-desc {
                color: #50575e;
                font-size: 13px;
                line-height: 1.5;
                margin-bottom: 8px;
            }
            .sfs-hr-sc-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 16px;
                font-size: 12px;
                color: #646970;
            }
            .sfs-hr-sc-meta strong { color: #1d2327; }
            .sfs-hr-sc-params {
                margin-top: 6px;
                padding: 8px 12px;
                background: #f6f7f7;
                border-radius: 4px;
                font-size: 12px;
            }
            .sfs-hr-sc-params dt {
                font-family: monospace;
                font-weight: 600;
                color: #1d2327;
                margin-top: 4px;
            }
            .sfs-hr-sc-params dd {
                margin: 0 0 0 16px;
                color: #646970;
            }
        </style>

        <div class="sfs-hr-shortcodes">
            <p><?php esc_html_e( 'Copy any shortcode below and paste it into a WordPress page or post to display it on the frontend.', 'sfs-hr' ); ?></p>

            <?php foreach ( $shortcodes as $sc ) :
                $tag_str   = '[' . $sc['tag'] . ']';
                $is_dep    = ! empty( $sc['deprecated'] );
            ?>
            <div class="sfs-hr-sc-card <?php echo $is_dep ? 'deprecated' : ''; ?>">
                <div class="sfs-hr-sc-header">
                    <code class="sfs-hr-sc-tag"><?php echo esc_html( $tag_str ); ?></code>
                    <button type="button" class="sfs-hr-sc-copy" data-copy="<?php echo esc_attr( $tag_str ); ?>">
                        <?php esc_html_e( 'Copy', 'sfs-hr' ); ?>
                    </button>
                    <?php if ( $is_dep ) : ?>
                        <span class="sfs-hr-sc-badge sfs-hr-sc-badge--dep"><?php esc_html_e( 'Deprecated', 'sfs-hr' ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="sfs-hr-sc-desc"><?php echo esc_html( $sc['description'] ); ?></div>
                <?php if ( ! empty( $sc['params'] ) ) : ?>
                    <div class="sfs-hr-sc-params">
                        <strong><?php esc_html_e( 'Parameters:', 'sfs-hr' ); ?></strong>
                        <dl>
                            <?php foreach ( $sc['params'] as $pname => $pdesc ) : ?>
                                <dt><?php echo esc_html( $pname ); ?></dt>
                                <dd><?php echo esc_html( $pdesc ); ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </div>
                <?php endif; ?>
                <div class="sfs-hr-sc-meta">
                    <span><strong><?php esc_html_e( 'Capability:', 'sfs-hr' ); ?></strong> <?php echo esc_html( $sc['capability'] ); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
        document.querySelectorAll('.sfs-hr-sc-copy').forEach(function(btn){
            btn.addEventListener('click', function(){
                var text = btn.getAttribute('data-copy');
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function(){
                        btn.textContent = '<?php echo esc_js( __( 'Copied!', 'sfs-hr' ) ); ?>';
                        btn.classList.add('copied');
                        setTimeout(function(){
                            btn.textContent = '<?php echo esc_js( __( 'Copy', 'sfs-hr' ) ); ?>';
                            btn.classList.remove('copied');
                        }, 2000);
                    });
                } else {
                    // Fallback for older browsers
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    btn.textContent = '<?php echo esc_js( __( 'Copied!', 'sfs-hr' ) ); ?>';
                    btn.classList.add('copied');
                    setTimeout(function(){
                        btn.textContent = '<?php echo esc_js( __( 'Copy', 'sfs-hr' ) ); ?>';
                        btn.classList.remove('copied');
                    }, 2000);
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render the Pages settings tab — shows auto-created pages and their status.
     */
    private function render_pages_settings(): void {
        $definitions = \SFS\HR\Install\Pages::get_definitions();
        ?>
        <style>
            .sfs-hr-pages { max-width: 900px; }
            .sfs-hr-page-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 6px;
                padding: 16px 20px;
                margin-bottom: 12px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
            }
            .sfs-hr-page-info { flex: 1; }
            .sfs-hr-page-title {
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
                margin: 0 0 4px;
            }
            .sfs-hr-page-shortcode {
                font-family: monospace;
                font-size: 13px;
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 2px 8px;
                color: #1d2327;
            }
            .sfs-hr-page-status {
                font-size: 12px;
                padding: 3px 10px;
                border-radius: 10px;
                font-weight: 500;
                white-space: nowrap;
            }
            .sfs-hr-page-status--publish {
                background: #edfaef;
                color: #00a32a;
            }
            .sfs-hr-page-status--draft {
                background: #f0f0f1;
                color: #646970;
            }
            .sfs-hr-page-status--trash {
                background: #fcf0f1;
                color: #8b1a1a;
            }
            .sfs-hr-page-status--missing {
                background: #fcf0f1;
                color: #8b1a1a;
            }
            .sfs-hr-page-actions {
                display: flex;
                gap: 8px;
                align-items: center;
            }
        </style>
        <div class="sfs-hr-pages">
            <p><?php esc_html_e( 'These pages are automatically created when the plugin is activated. Each page contains a shortcode that powers the frontend experience for employees.', 'sfs-hr' ); ?></p>

            <?php foreach ( $definitions as $option_key => $def ) :
                $page_id = get_option( $option_key );
                $status  = $page_id ? get_post_status( $page_id ) : false;
            ?>
            <div class="sfs-hr-page-card">
                <div class="sfs-hr-page-info">
                    <p class="sfs-hr-page-title"><?php echo esc_html( $def['title'] ); ?></p>
                    <code class="sfs-hr-page-shortcode"><?php echo esc_html( $def['content'] ); ?></code>
                </div>

                <?php if ( $status === 'publish' ) : ?>
                    <span class="sfs-hr-page-status sfs-hr-page-status--publish"><?php esc_html_e( 'Published', 'sfs-hr' ); ?></span>
                    <div class="sfs-hr-page-actions">
                        <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" class="button button-small" target="_blank"><?php esc_html_e( 'View', 'sfs-hr' ); ?></a>
                        <a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'sfs-hr' ); ?></a>
                    </div>
                <?php elseif ( $status === 'draft' ) : ?>
                    <span class="sfs-hr-page-status sfs-hr-page-status--draft"><?php esc_html_e( 'Draft', 'sfs-hr' ); ?></span>
                    <div class="sfs-hr-page-actions">
                        <a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'sfs-hr' ); ?></a>
                    </div>
                <?php elseif ( $status === 'trash' ) : ?>
                    <span class="sfs-hr-page-status sfs-hr-page-status--trash"><?php esc_html_e( 'Trashed', 'sfs-hr' ); ?></span>
                <?php else : ?>
                    <span class="sfs-hr-page-status sfs-hr-page-status--missing"><?php esc_html_e( 'Not Created', 'sfs-hr' ); ?></span>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field( 'sfs_hr_recreate_page', '_sfs_hr_nonce' ); ?>
                        <input type="hidden" name="sfs_hr_recreate_page" value="<?php echo esc_attr( $option_key ); ?>">
                        <button type="submit" class="button button-small button-primary"><?php esc_html_e( 'Create Now', 'sfs-hr' ); ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render notification settings form
     *
     * @param array $settings Current settings
     */
    private function render_notification_settings( array $settings ): void {
        ?>
        <style>
            .sfs-hr-settings-form { max-width: 800px; }
            .sfs-hr-settings-section {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .sfs-hr-settings-section h2 {
                margin: 0 0 15px 0;
                padding: 0 0 10px 0;
                border-bottom: 1px solid #eee;
                font-size: 16px;
            }
            .sfs-hr-settings-section table.form-table {
                margin: 0;
            }
            .sfs-hr-settings-section table.form-table th {
                padding: 10px 10px 10px 0;
                width: 200px;
            }
            .sfs-hr-settings-section table.form-table td {
                padding: 10px 0;
            }
            .sfs-hr-settings-section .description {
                color: #666;
                font-size: 12px;
                margin-top: 4px;
            }
            .sfs-hr-toggle-row {
                display: flex;
                align-items: center;
                gap: 20px;
                flex-wrap: wrap;
            }
            .sfs-hr-toggle-row label {
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .sfs-hr-sms-provider-settings {
                margin-top: 15px;
                padding: 15px;
                background: #f9f9f9;
                border-radius: 4px;
                display: none;
            }
            .sfs-hr-sms-provider-settings.active {
                display: block;
            }
            .sfs-hr-hr-emails-list {
                margin-top: 10px;
            }
            .sfs-hr-hr-emails-list input {
                width: 300px;
                margin-bottom: 5px;
            }
        </style>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sfs-hr-settings-form">
            <?php wp_nonce_field( 'sfs_hr_notification_settings', '_wpnonce' ); ?>
            <input type="hidden" name="action" value="sfs_hr_save_notification_settings">

            <!-- Global Settings -->
            <div class="sfs-hr-settings-section">
                <h2><?php esc_html_e( 'Global Settings', 'sfs-hr' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable Notifications', 'sfs-hr' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
                                <?php esc_html_e( 'Enable the notification system', 'sfs-hr' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Channels', 'sfs-hr' ); ?></th>
                        <td>
                            <div class="sfs-hr-toggle-row">
                                <label>
                                    <input type="checkbox" name="email_enabled" value="1" <?php checked( $settings['email_enabled'] ); ?>>
                                    <?php esc_html_e( 'Email', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="sms_enabled" value="1" <?php checked( $settings['sms_enabled'] ); ?> id="sms_enabled_toggle">
                                    <?php esc_html_e( 'SMS', 'sfs-hr' ); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Recipient Groups', 'sfs-hr' ); ?></th>
                        <td>
                            <div class="sfs-hr-toggle-row">
                                <label>
                                    <input type="checkbox" name="employee_notification" value="1" <?php checked( $settings['employee_notification'] ); ?>>
                                    <?php esc_html_e( 'Employees', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="manager_notification" value="1" <?php checked( $settings['manager_notification'] ); ?>>
                                    <?php esc_html_e( 'Managers', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="hr_notification" value="1" <?php checked( $settings['hr_notification'] ); ?>>
                                    <?php esc_html_e( 'HR Team', 'sfs-hr' ); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'HR Email Addresses', 'sfs-hr' ); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e( 'Enter HR team email addresses (one per line)', 'sfs-hr' ); ?></p>
                            <textarea name="hr_emails" rows="4" style="width: 350px;"><?php
                                echo esc_textarea( implode( "\n", (array) $settings['hr_emails'] ) );
                            ?></textarea>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- SMS Provider Settings -->
            <div class="sfs-hr-settings-section" id="sms-settings-section" style="<?php echo $settings['sms_enabled'] ? '' : 'display:none;'; ?>">
                <h2><?php esc_html_e( 'SMS Provider Settings', 'sfs-hr' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'SMS Provider', 'sfs-hr' ); ?></th>
                        <td>
                            <select name="sms_provider" id="sms_provider_select">
                                <option value="none" <?php selected( $settings['sms_provider'], 'none' ); ?>><?php esc_html_e( 'None', 'sfs-hr' ); ?></option>
                                <option value="twilio" <?php selected( $settings['sms_provider'], 'twilio' ); ?>><?php esc_html_e( 'Twilio', 'sfs-hr' ); ?></option>
                                <option value="nexmo" <?php selected( $settings['sms_provider'], 'nexmo' ); ?>><?php esc_html_e( 'Nexmo / Vonage', 'sfs-hr' ); ?></option>
                                <option value="custom" <?php selected( $settings['sms_provider'], 'custom' ); ?>><?php esc_html_e( 'Custom API', 'sfs-hr' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <!-- Twilio Settings -->
                <div class="sfs-hr-sms-provider-settings" id="twilio-settings" <?php echo $settings['sms_provider'] === 'twilio' ? 'class="active"' : ''; ?>>
                    <h4><?php esc_html_e( 'Twilio Configuration', 'sfs-hr' ); ?></h4>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Account SID', 'sfs-hr' ); ?></th>
                            <td><input type="text" name="twilio_sid" value="<?php echo esc_attr( $settings['twilio_sid'] ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Auth Token', 'sfs-hr' ); ?></th>
                            <td><input type="password" name="twilio_token" value="<?php echo esc_attr( $settings['twilio_token'] ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'From Number', 'sfs-hr' ); ?></th>
                            <td><input type="text" name="twilio_from" value="<?php echo esc_attr( $settings['twilio_from'] ); ?>" class="regular-text" placeholder="+1234567890"></td>
                        </tr>
                    </table>
                </div>

                <!-- Nexmo Settings -->
                <div class="sfs-hr-sms-provider-settings" id="nexmo-settings" <?php echo $settings['sms_provider'] === 'nexmo' ? 'class="active"' : ''; ?>>
                    <h4><?php esc_html_e( 'Nexmo / Vonage Configuration', 'sfs-hr' ); ?></h4>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'API Key', 'sfs-hr' ); ?></th>
                            <td><input type="text" name="nexmo_api_key" value="<?php echo esc_attr( $settings['nexmo_api_key'] ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'API Secret', 'sfs-hr' ); ?></th>
                            <td><input type="password" name="nexmo_api_secret" value="<?php echo esc_attr( $settings['nexmo_api_secret'] ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'From Name/Number', 'sfs-hr' ); ?></th>
                            <td><input type="text" name="nexmo_from" value="<?php echo esc_attr( $settings['nexmo_from'] ); ?>" class="regular-text" placeholder="CompanyName"></td>
                        </tr>
                    </table>
                </div>

                <!-- Custom API Settings -->
                <div class="sfs-hr-sms-provider-settings" id="custom-settings" <?php echo $settings['sms_provider'] === 'custom' ? 'class="active"' : ''; ?>>
                    <h4><?php esc_html_e( 'Custom API Configuration', 'sfs-hr' ); ?></h4>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'API Endpoint', 'sfs-hr' ); ?></th>
                            <td><input type="url" name="custom_sms_endpoint" value="<?php echo esc_attr( $settings['custom_sms_endpoint'] ); ?>" class="regular-text" placeholder="https://api.example.com/sms"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'API Key', 'sfs-hr' ); ?></th>
                            <td><input type="password" name="custom_sms_api_key" value="<?php echo esc_attr( $settings['custom_sms_api_key'] ); ?>" class="regular-text"></td>
                        </tr>
                    </table>
                    <p class="description"><?php esc_html_e( 'The API will receive POST requests with "phone" and "message" fields.', 'sfs-hr' ); ?></p>
                </div>
            </div>

            <!-- Leave Notifications -->
            <div class="sfs-hr-settings-section">
                <h2><?php esc_html_e( 'Leave Notifications', 'sfs-hr' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Leave Events', 'sfs-hr' ); ?></th>
                        <td>
                            <div class="sfs-hr-toggle-row">
                                <label>
                                    <input type="checkbox" name="notify_leave_created" value="1" <?php checked( $settings['notify_leave_created'] ); ?>>
                                    <?php esc_html_e( 'New Request', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="notify_leave_approved" value="1" <?php checked( $settings['notify_leave_approved'] ); ?>>
                                    <?php esc_html_e( 'Approved', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="notify_leave_rejected" value="1" <?php checked( $settings['notify_leave_rejected'] ); ?>>
                                    <?php esc_html_e( 'Rejected', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="notify_leave_cancelled" value="1" <?php checked( $settings['notify_leave_cancelled'] ); ?>>
                                    <?php esc_html_e( 'Cancelled', 'sfs-hr' ); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Attendance Notifications -->
            <div class="sfs-hr-settings-section">
                <h2><?php esc_html_e( 'Attendance Notifications', 'sfs-hr' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Attendance Events', 'sfs-hr' ); ?></th>
                        <td>
                            <div class="sfs-hr-toggle-row">
                                <label>
                                    <input type="checkbox" name="notify_late_arrival" value="1" <?php checked( $settings['notify_late_arrival'] ); ?>>
                                    <?php esc_html_e( 'Late Arrival', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="notify_early_leave" value="1" <?php checked( $settings['notify_early_leave'] ); ?>>
                                    <?php esc_html_e( 'Early Leave', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="notify_missed_punch" value="1" <?php checked( $settings['notify_missed_punch'] ); ?>>
                                    <?php esc_html_e( 'Missed Punch', 'sfs-hr' ); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Late Threshold', 'sfs-hr' ); ?></th>
                        <td>
                            <input type="number" name="late_arrival_threshold" value="<?php echo esc_attr( $settings['late_arrival_threshold'] ); ?>" min="1" max="120" style="width: 80px;">
                            <span class="description"><?php esc_html_e( 'minutes (notify only if late by more than this)', 'sfs-hr' ); ?></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Employee Milestone Notifications -->
            <div class="sfs-hr-settings-section">
                <h2><?php esc_html_e( 'Employee Milestones', 'sfs-hr' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Events', 'sfs-hr' ); ?></th>
                        <td>
                            <div class="sfs-hr-toggle-row">
                                <label>
                                    <input type="checkbox" name="notify_new_employee" value="1" <?php checked( $settings['notify_new_employee'] ); ?>>
                                    <?php esc_html_e( 'New Employee', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="notify_birthday" value="1" <?php checked( $settings['notify_birthday'] ); ?>>
                                    <?php esc_html_e( 'Birthday', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="notify_anniversary" value="1" <?php checked( $settings['notify_anniversary'] ); ?>>
                                    <?php esc_html_e( 'Work Anniversary', 'sfs-hr' ); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Birthday Reminder', 'sfs-hr' ); ?></th>
                        <td>
                            <input type="number" name="birthday_days_before" value="<?php echo esc_attr( $settings['birthday_days_before'] ); ?>" min="0" max="30" style="width: 80px;">
                            <span class="description"><?php esc_html_e( 'days before (0 = on the day)', 'sfs-hr' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Anniversary Reminder', 'sfs-hr' ); ?></th>
                        <td>
                            <input type="number" name="anniversary_days_before" value="<?php echo esc_attr( $settings['anniversary_days_before'] ); ?>" min="0" max="30" style="width: 80px;">
                            <span class="description"><?php esc_html_e( 'days before (0 = on the day)', 'sfs-hr' ); ?></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Contract & Document Alerts -->
            <div class="sfs-hr-settings-section">
                <h2><?php esc_html_e( 'Contract & Document Alerts', 'sfs-hr' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Events', 'sfs-hr' ); ?></th>
                        <td>
                            <div class="sfs-hr-toggle-row">
                                <label>
                                    <input type="checkbox" name="notify_contract_expiry" value="1" <?php checked( $settings['notify_contract_expiry'] ); ?>>
                                    <?php esc_html_e( 'Contract Expiry', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="notify_probation_end" value="1" <?php checked( $settings['notify_probation_end'] ); ?>>
                                    <?php esc_html_e( 'Probation End', 'sfs-hr' ); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Contract Expiry Recipients', 'sfs-hr' ); ?></th>
                        <td>
                            <div class="sfs-hr-toggle-row">
                                <label>
                                    <input type="checkbox" name="contract_expiry_notify_gm" value="1" <?php checked( $settings['contract_expiry_notify_gm'] ?? false ); ?>>
                                    <?php esc_html_e( 'GM', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="contract_expiry_notify_manager" value="1" <?php checked( $settings['contract_expiry_notify_manager'] ?? true ); ?>>
                                    <?php esc_html_e( 'Department Manager', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="contract_expiry_notify_hr" value="1" <?php checked( $settings['contract_expiry_notify_hr'] ?? true ); ?>>
                                    <?php esc_html_e( 'HR', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="contract_expiry_notify_employee" value="1" <?php checked( $settings['contract_expiry_notify_employee'] ?? false ); ?>>
                                    <?php esc_html_e( 'Employee', 'sfs-hr' ); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Contract Expiry Days', 'sfs-hr' ); ?></th>
                        <td>
                            <input type="text" name="contract_expiry_days" value="<?php echo esc_attr( implode( ', ', (array) $settings['contract_expiry_days'] ) ); ?>" class="regular-text" placeholder="30, 14, 7">
                            <p class="description"><?php esc_html_e( 'Send notifications when contracts expire in X days (comma-separated)', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Probation Review', 'sfs-hr' ); ?></th>
                        <td>
                            <input type="number" name="probation_days_before" value="<?php echo esc_attr( $settings['probation_days_before'] ); ?>" min="1" max="30" style="width: 80px;">
                            <span class="description"><?php esc_html_e( 'days before probation ends', 'sfs-hr' ); ?></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Payroll Notifications -->
            <div class="sfs-hr-settings-section">
                <h2><?php esc_html_e( 'Payroll Notifications', 'sfs-hr' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Events', 'sfs-hr' ); ?></th>
                        <td>
                            <div class="sfs-hr-toggle-row">
                                <label>
                                    <input type="checkbox" name="notify_payslip_ready" value="1" <?php checked( $settings['notify_payslip_ready'] ); ?>>
                                    <?php esc_html_e( 'Payslip Ready', 'sfs-hr' ); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="notify_payroll_processed" value="1" <?php checked( $settings['notify_payroll_processed'] ); ?>>
                                    <?php esc_html_e( 'Payroll Processed', 'sfs-hr' ); ?>
                                </label>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'sfs-hr' ); ?></button>
            </p>
        </form>

        <script>
        jQuery(function($) {
            // Toggle SMS settings section
            $('#sms_enabled_toggle').on('change', function() {
                $('#sms-settings-section').toggle(this.checked);
            });

            // Toggle SMS provider settings
            $('#sms_provider_select').on('change', function() {
                $('.sfs-hr-sms-provider-settings').removeClass('active');
                var provider = $(this).val();
                if (provider !== 'none') {
                    $('#' + provider + '-settings').addClass('active');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Handle saving notification settings
     */
    public function handle_save_notification_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied', 'sfs-hr' ) );
        }

        check_admin_referer( 'sfs_hr_notification_settings' );

        // Parse HR emails from textarea
        $hr_emails_raw = isset( $_POST['hr_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['hr_emails'] ) ) : '';
        $hr_emails = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( "\n", $hr_emails_raw ) ) ) );

        // Parse contract expiry days
        $contract_days_raw = isset( $_POST['contract_expiry_days'] ) ? sanitize_text_field( wp_unslash( $_POST['contract_expiry_days'] ) ) : '30, 14, 7';
        $contract_expiry_days = array_filter( array_map( 'intval', array_map( 'trim', explode( ',', $contract_days_raw ) ) ) );
        if ( empty( $contract_expiry_days ) ) {
            $contract_expiry_days = [ 30, 14, 7 ];
        }

        $settings = [
            // Global
            'enabled'              => isset( $_POST['enabled'] ),
            'email_enabled'        => isset( $_POST['email_enabled'] ),
            'sms_enabled'          => isset( $_POST['sms_enabled'] ),
            'sms_provider'         => isset( $_POST['sms_provider'] ) ? sanitize_key( $_POST['sms_provider'] ) : 'none',

            // SMS Providers
            'twilio_sid'           => isset( $_POST['twilio_sid'] ) ? sanitize_text_field( wp_unslash( $_POST['twilio_sid'] ) ) : '',
            'twilio_token'         => isset( $_POST['twilio_token'] ) ? sanitize_text_field( wp_unslash( $_POST['twilio_token'] ) ) : '',
            'twilio_from'          => isset( $_POST['twilio_from'] ) ? sanitize_text_field( wp_unslash( $_POST['twilio_from'] ) ) : '',
            'nexmo_api_key'        => isset( $_POST['nexmo_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['nexmo_api_key'] ) ) : '',
            'nexmo_api_secret'     => isset( $_POST['nexmo_api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['nexmo_api_secret'] ) ) : '',
            'nexmo_from'           => isset( $_POST['nexmo_from'] ) ? sanitize_text_field( wp_unslash( $_POST['nexmo_from'] ) ) : '',
            'custom_sms_endpoint'  => isset( $_POST['custom_sms_endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['custom_sms_endpoint'] ) ) : '',
            'custom_sms_api_key'   => isset( $_POST['custom_sms_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_sms_api_key'] ) ) : '',

            // Recipients
            'hr_emails'            => $hr_emails,
            'manager_notification' => isset( $_POST['manager_notification'] ),
            'employee_notification' => isset( $_POST['employee_notification'] ),
            'hr_notification'      => isset( $_POST['hr_notification'] ),

            // Leave
            'notify_leave_created'   => isset( $_POST['notify_leave_created'] ),
            'notify_leave_approved'  => isset( $_POST['notify_leave_approved'] ),
            'notify_leave_rejected'  => isset( $_POST['notify_leave_rejected'] ),
            'notify_leave_cancelled' => isset( $_POST['notify_leave_cancelled'] ),

            // Attendance
            'notify_late_arrival'      => isset( $_POST['notify_late_arrival'] ),
            'late_arrival_threshold'   => isset( $_POST['late_arrival_threshold'] ) ? absint( $_POST['late_arrival_threshold'] ) : 15,
            'notify_early_leave'       => isset( $_POST['notify_early_leave'] ),
            'notify_missed_punch'      => isset( $_POST['notify_missed_punch'] ),

            // Milestones
            'notify_new_employee'      => isset( $_POST['notify_new_employee'] ),
            'notify_birthday'          => isset( $_POST['notify_birthday'] ),
            'birthday_days_before'     => isset( $_POST['birthday_days_before'] ) ? absint( $_POST['birthday_days_before'] ) : 1,
            'notify_anniversary'       => isset( $_POST['notify_anniversary'] ),
            'anniversary_days_before'  => isset( $_POST['anniversary_days_before'] ) ? absint( $_POST['anniversary_days_before'] ) : 1,

            // Contracts
            'notify_contract_expiry'          => isset( $_POST['notify_contract_expiry'] ),
            'contract_expiry_days'            => $contract_expiry_days,
            'contract_expiry_notify_gm'       => isset( $_POST['contract_expiry_notify_gm'] ),
            'contract_expiry_notify_manager'  => isset( $_POST['contract_expiry_notify_manager'] ),
            'contract_expiry_notify_hr'       => isset( $_POST['contract_expiry_notify_hr'] ),
            'contract_expiry_notify_employee' => isset( $_POST['contract_expiry_notify_employee'] ),
            'notify_probation_end'            => isset( $_POST['notify_probation_end'] ),
            'probation_days_before'           => isset( $_POST['probation_days_before'] ) ? absint( $_POST['probation_days_before'] ) : 7,

            // Payroll
            'notify_payslip_ready'     => isset( $_POST['notify_payslip_ready'] ),
            'notify_payroll_processed' => isset( $_POST['notify_payroll_processed'] ),
        ];

        Notifications::save_settings( $settings );

        Helpers::redirect_with_notice(
            admin_url( 'admin.php?page=sfs-hr-settings&tab=notifications' ),
            'success',
            __( 'Notification settings saved successfully.', 'sfs-hr' )
        );
    }

    /**
     * Render document settings tab
     */
    private function render_document_settings(): void {
        $all_types = \SFS\HR\Modules\Documents\Services\Documents_Service::get_all_document_types();
        $settings = \SFS\HR\Modules\Documents\Services\Documents_Service::get_document_type_settings();
        ?>
        <style>
            .sfs-hr-settings-form { max-width: 900px; }
            .sfs-hr-settings-section {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .sfs-hr-settings-section h2 {
                margin: 0 0 15px 0;
                padding: 0 0 10px 0;
                border-bottom: 1px solid #eee;
                font-size: 16px;
            }
            .sfs-doc-types-table {
                width: 100%;
                border-collapse: collapse;
            }
            .sfs-doc-types-table th,
            .sfs-doc-types-table td {
                padding: 12px 15px;
                text-align: start;
                border-bottom: 1px solid #eee;
            }
            .sfs-doc-types-table th {
                background: #f9f9f9;
                font-weight: 600;
            }
            .sfs-doc-types-table tr:hover {
                background: #f9f9f9;
            }
            .sfs-doc-required-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                margin-left: 8px;
            }
            .sfs-doc-required-badge--required {
                background: #fef2f2;
                color: #991b1b;
            }
            .sfs-doc-required-badge--optional {
                background: #f0fdf4;
                color: #166534;
            }
        </style>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sfs-hr-settings-form">
            <input type="hidden" name="action" value="sfs_hr_document_settings_save" />
            <?php wp_nonce_field( 'sfs_hr_document_settings' ); ?>

            <div class="sfs-hr-settings-section">
                <h2><?php esc_html_e( 'Required Document Types', 'sfs-hr' ); ?></h2>
                <p class="description" style="margin-bottom:15px;">
                    <?php esc_html_e( 'Configure which document types are enabled and which are required for employees. Required documents will show notifications if missing.', 'sfs-hr' ); ?>
                </p>

                <table class="sfs-doc-types-table">
                    <thead>
                        <tr>
                            <th style="width:40%;"><?php esc_html_e( 'Document Type', 'sfs-hr' ); ?></th>
                            <th style="width:20%;"><?php esc_html_e( 'Enabled', 'sfs-hr' ); ?></th>
                            <th style="width:20%;"><?php esc_html_e( 'Required', 'sfs-hr' ); ?></th>
                            <th style="width:20%;"><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $all_types as $type_key => $type_label ) :
                            $is_enabled = ! empty( $settings[ $type_key ]['enabled'] );
                            $is_required = ! empty( $settings[ $type_key ]['required'] );
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $type_label ); ?></strong>
                                    <code style="margin-left:8px;font-size:11px;color:#666;"><?php echo esc_html( $type_key ); ?></code>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="doc_types[<?php echo esc_attr( $type_key ); ?>][enabled]" value="1" <?php checked( $is_enabled ); ?> />
                                        <?php esc_html_e( 'Enabled', 'sfs-hr' ); ?>
                                    </label>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="doc_types[<?php echo esc_attr( $type_key ); ?>][required]" value="1" <?php checked( $is_required ); ?> />
                                        <?php esc_html_e( 'Required', 'sfs-hr' ); ?>
                                    </label>
                                </td>
                                <td>
                                    <?php if ( $is_enabled && $is_required ) : ?>
                                        <span class="sfs-doc-required-badge sfs-doc-required-badge--required"><?php esc_html_e( 'Required', 'sfs-hr' ); ?></span>
                                    <?php elseif ( $is_enabled ) : ?>
                                        <span class="sfs-doc-required-badge sfs-doc-required-badge--optional"><?php esc_html_e( 'Optional', 'sfs-hr' ); ?></span>
                                    <?php else : ?>
                                        <span style="color:#999;"><?php esc_html_e( 'Disabled', 'sfs-hr' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Document Settings', 'sfs-hr' ); ?></button>
            </p>
        </form>

        <script>
        jQuery(function($) {
            // Auto-uncheck required if enabled is unchecked
            $('input[name$="[enabled]"]').on('change', function() {
                if (!this.checked) {
                    $(this).closest('tr').find('input[name$="[required]"]').prop('checked', false);
                }
            });
            // Auto-check enabled if required is checked
            $('input[name$="[required]"]').on('change', function() {
                if (this.checked) {
                    $(this).closest('tr').find('input[name$="[enabled]"]').prop('checked', true);
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Handle document settings save
     */
    public function handle_document_settings_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied', 'sfs-hr' ) );
        }

        check_admin_referer( 'sfs_hr_document_settings' );

        $doc_types = isset( $_POST['doc_types'] ) && is_array( $_POST['doc_types'] ) ? $_POST['doc_types'] : [];
        $settings = [];

        foreach ( $doc_types as $type_key => $type_settings ) {
            $type_key = sanitize_key( $type_key );
            $settings[ $type_key ] = [
                'enabled'  => ! empty( $type_settings['enabled'] ),
                'required' => ! empty( $type_settings['required'] ),
            ];
        }

        \SFS\HR\Modules\Documents\Services\Documents_Service::save_document_type_settings( $settings );

        Helpers::redirect_with_notice(
            admin_url( 'admin.php?page=sfs-hr-settings&tab=documents' ),
            'success',
            __( 'Document settings saved successfully.', 'sfs-hr' )
        );
    }

    /**
     * Shared helper: save employee nationality list and gov support settings.
     *
     * @param array $emp_input The emp[] POST data array.
     */
    private function save_employee_settings( array $emp_input ): void {
        // Nationalities: one per line → array.
        $raw_nat = isset( $emp_input['nationalities'] ) ? sanitize_textarea_field( wp_unslash( $emp_input['nationalities'] ) ) : '';
        $lines   = array_filter( array_map( 'trim', explode( "\n", $raw_nat ) ), 'strlen' );
        $lines   = array_values( array_unique( $lines ) );
        sort( $lines );
        update_option( 'sfs_hr_nationalities', $lines, false );

        // Government support reminder settings.
        $gov = get_option( 'sfs_hr_gov_support_settings', [] );
        if ( ! is_array( $gov ) ) {
            $gov = [];
        }
        $gov['enabled']          = ! empty( $emp_input['gov_enabled'] );
        $raw_gov_nat             = sanitize_text_field( wp_unslash( $emp_input['gov_nationality'] ?? 'Saudi' ) );
        $valid_nats              = Helpers::get_nationalities_for_select();
        $gov['nationality']      = in_array( $raw_gov_nat, $valid_nats, true ) ? $raw_gov_nat : ( $valid_nats[0] ?? 'Saudi' );
        $gov['threshold_months'] = max( 1, min( 120, (int) ( $emp_input['gov_months'] ?? 3 ) ) );
        update_option( 'sfs_hr_gov_support_settings', $gov, false );
    }

    /**
     * Handle save for the admin Employees settings tab.
     */
    public function handle_save_employees_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_employees_settings', '_sfs_emp_nonce' );

        $emp_input = isset( $_POST['emp'] ) && is_array( $_POST['emp'] ) ? $_POST['emp'] : [];
        $this->save_employee_settings( $emp_input );

        Helpers::redirect_with_notice(
            admin_url( 'admin.php?page=sfs-hr-settings&tab=employees' ),
            'success',
            __( 'Employees settings saved successfully.', 'sfs-hr' )
        );
    }

    /**
     * Handle frontend settings form (Settings Tab in frontend portal).
     */
    public function handle_frontend_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_frontend_settings', '_sfs_settings_nonce' );

        // ── Attendance settings ───────────────────────────────────
        $att_input = isset( $_POST['att'] ) && is_array( $_POST['att'] ) ? $_POST['att'] : [];
        if ( ! empty( $att_input ) ) {
            $att_saved = get_option( 'sfs_hr_attendance_settings', [] );
            $att_saved['period_type']           = sanitize_key( $att_input['period_type'] ?? 'full_month' );
            $att_saved['period_start_day']      = max( 1, min( 28, (int) ( $att_input['period_start_day'] ?? 1 ) ) );
            $att_saved['default_grace_late']    = max( 0, min( 120, (int) ( $att_input['default_grace_late'] ?? 5 ) ) );
            $att_saved['default_grace_early']   = max( 0, min( 120, (int) ( $att_input['default_grace_early'] ?? 5 ) ) );
            $att_saved['default_rounding_rule'] = sanitize_key( $att_input['default_rounding_rule'] ?? '5' );
            $att_saved['selfie_retention_days'] = max( 1, min( 365, (int) ( $att_input['selfie_retention_days'] ?? 30 ) ) );
            $att_saved['monthly_ot_threshold']  = max( 0, (int) ( $att_input['monthly_ot_threshold'] ?? 2400 ) );
            update_option( 'sfs_hr_attendance_settings', $att_saved );

            // ELR settings (nested under att[] in the form).
            $elr_saved = get_option( 'sfs_hr_elr_settings', [] );
            $elr_saved['enabled']                = ! empty( $att_input['elr_enabled'] );
            $elr_saved['auto_create']            = ! empty( $att_input['elr_auto_create'] );
            $elr_saved['require_note']           = ! empty( $att_input['elr_require_note'] );
            $elr_saved['affects_salary_default']  = in_array( ( $att_input['elr_affects_salary_default'] ?? 'no' ), [ 'no', 'yes', 'manager' ], true )
                ? $att_input['elr_affects_salary_default'] : 'no';
            $elr_saved['max_per_month']           = max( 0, min( 31, (int) ( $att_input['elr_max_per_month'] ?? 0 ) ) );
            $elr_saved['auto_reject_days']        = max( 1, min( 30, (int) ( $att_input['elr_auto_reject_days'] ?? 3 ) ) );
            update_option( 'sfs_hr_elr_settings', $elr_saved );
        }

        // ── Leave settings ────────────────────────────────────────
        $lv = isset( $_POST['leave'] ) && is_array( $_POST['leave'] ) ? $_POST['leave'] : [];
        if ( ! empty( $lv ) ) {
            update_option( 'sfs_hr_leave_email', ! empty( $lv['email'] ) ? '1' : '0' );
            update_option( 'sfs_hr_annual_lt5', max( 0, (int) ( $lv['annual_lt5'] ?? 21 ) ) );
            update_option( 'sfs_hr_annual_ge5', max( 0, (int) ( $lv['annual_ge5'] ?? 30 ) ) );
            update_option( 'sfs_hr_leave_gm_approver', (int) ( $lv['gm_approver'] ?? 0 ) );
            update_option( 'sfs_hr_leave_finance_approver', (int) ( $lv['finance_approver'] ?? 0 ) );
            update_option( 'sfs_hr_holiday_notify_on_add', ! empty( $lv['holiday_notify'] ) ? '1' : '0' );
            update_option( 'sfs_hr_holiday_reminder_enabled', ! empty( $lv['holiday_remind'] ) ? '1' : '0' );
            update_option( 'sfs_hr_holiday_reminder_days', max( 1, min( 30, (int) ( $lv['holiday_reminder_days'] ?? 1 ) ) ) );
        }

        // ── Loans settings ────────────────────────────────────────
        $loans_input = isset( $_POST['loans'] ) && is_array( $_POST['loans'] ) ? $_POST['loans'] : [];
        if ( ! empty( $loans_input ) && class_exists( '\SFS\HR\Modules\Loans\LoansModule' ) ) {
            $loans_saved = \SFS\HR\Modules\Loans\LoansModule::get_settings();
            $loans_saved['enabled']                  = ! empty( $loans_input['enabled'] );
            $loans_saved['show_in_my_profile']       = ! empty( $loans_input['show_in_my_profile'] );
            $loans_saved['allow_employee_requests']  = ! empty( $loans_input['allow_employee_requests'] );
            $loans_saved['max_loan_amount']          = max( 0, (int) ( $loans_input['max_loan_amount'] ?? 0 ) );
            if ( isset( $loans_input['gm_user_id'] ) ) {
                $gm_id = (int) $loans_input['gm_user_id'];
                $loans_saved['gm_user_ids'] = $gm_id > 0 ? [ $gm_id ] : [];
            }
            if ( isset( $loans_input['finance_user_id'] ) ) {
                $loans_saved['finance_user_id'] = (int) $loans_input['finance_user_id'];
            }
            update_option( 'sfs_hr_loans_settings', $loans_saved );
        }

        // ── Employees settings ────────────────────────────────────
        $emp_input = isset( $_POST['emp'] ) && is_array( $_POST['emp'] ) ? $_POST['emp'] : [];
        if ( ! empty( $emp_input ) ) {
            $this->save_employee_settings( $emp_input );
        }

        // ── Notification settings ─────────────────────────────────
        $nf = isset( $_POST['notif'] ) && is_array( $_POST['notif'] ) ? $_POST['notif'] : [];
        if ( ! empty( $nf ) ) {
            $ns = class_exists( Notifications::class ) ? Notifications::get_settings() : [];
            $bool_keys = [
                'enabled', 'email_enabled', 'manager_notification', 'employee_notification',
                'hr_notification', 'notify_leave_created', 'notify_leave_approved',
                'notify_leave_rejected', 'notify_leave_cancelled', 'notify_late_arrival',
                'notify_early_leave', 'notify_missed_punch',
                'notify_elr_created', 'notify_elr_approved', 'notify_elr_rejected',
                'notify_new_employee',
                'notify_birthday', 'notify_anniversary', 'notify_contract_expiry',
                'notify_probation_end', 'notify_payslip_ready', 'notify_payroll_processed',
            ];
            foreach ( $bool_keys as $k ) {
                $ns[ $k ] = ! empty( $nf[ $k ] );
            }
            $ns['late_arrival_threshold'] = max( 1, min( 120, (int) ( $nf['late_arrival_threshold'] ?? 15 ) ) );
            Notifications::save_settings( $ns );
        }

        // Redirect back to the frontend settings tab.
        $page_url = get_option( 'sfs_hr_portal_page_url', '' );
        if ( ! $page_url ) {
            // Try to find the portal page.
            $portal_page_id = $this->find_portal_page_id();
            if ( $portal_page_id ) {
                $page_url = get_permalink( $portal_page_id );
            }
        }
        if ( $page_url ) {
            wp_safe_redirect( add_query_arg( [ 'sfs_hr_tab' => 'settings', 'settings_saved' => '1' ], $page_url ) );
        } else {
            wp_safe_redirect( wp_get_referer() ?: admin_url() );
        }
        exit;
    }

    /**
     * Find the WordPress page containing the HR portal shortcode.
     */
    private function find_portal_page_id(): int {
        global $wpdb;
        $page_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_content LIKE %s LIMIT 1",
            'page',
            'publish',
            '%[sfs_hr_my_profile%'
        ) );
        return $page_id;
    }
}
