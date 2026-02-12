<?php
namespace SFS\HR\Frontend;
use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Leave\Leave_UI;
use SFS\HR\Modules\Attendance\Rest\Public_REST as Attendance_Public_REST;
use SFS\HR\Frontend\Tabs\LeaveTab;
use SFS\HR\Frontend\Tabs\LoansTab;
use SFS\HR\Frontend\Tabs\ResignationTab;
use SFS\HR\Frontend\Tabs\SettlementTab;

if ( ! defined('ABSPATH') ) { exit; }

class Shortcodes {
    public function hooks(): void {
        add_shortcode('sfs_hr_my_profile', [$this, 'my_profile']);
        add_shortcode('sfs_hr_my_loans', [$this, 'my_loans']);
        add_shortcode('sfs_hr_leave_request', [$this, 'leave_request']);
        add_shortcode('sfs_hr_my_leaves', [$this, 'my_leaves']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    /**
     * Enqueue frontend CSS and JS assets for PWA profile
     */
    public function enqueue_frontend_assets(): void {
        // Only enqueue on pages that might use our shortcodes
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }

        // Check if our shortcode is present in the content
        if ( has_shortcode( $post->post_content, 'sfs_hr_my_profile' ) ) {
            wp_enqueue_style(
                'sfs-hr-pwa-styles',
                \SFS_HR_URL . 'assets/frontend/pwa-styles.css',
                [],
                \SFS_HR_VER
            );
        }
    }
    
    
    public function my_profile( $atts = [], $content = '' ): string {
    if ( ! is_user_logged_in() ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__( 'Please log in to view your profile.', 'sfs-hr' ) . '</div>';
    }

    global $wpdb;
    $current_user_id = get_current_user_id();

    // Check if current user is a trainee
    $trainees_table = $wpdb->prefix . 'sfs_hr_trainees';
    $trainee = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$trainees_table} WHERE user_id = %d AND status = 'active'",
        $current_user_id
    ), ARRAY_A );

    // If user is a trainee, render trainee profile instead
    if ( $trainee ) {
        return $this->render_trainee_profile( $trainee );
    }

    // Use any-status version to allow terminated employees to access their profile
    $emp_id = Helpers::current_employee_id_any_status();
    if ( ! $emp_id ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__( 'Your HR profile is not linked. Please contact HR.', 'sfs-hr' ) . '</div>';
    }

    $emp = Helpers::get_employee_row( $emp_id );
    if ( ! $emp || ! is_array( $emp ) ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__( 'Profile not found.', 'sfs-hr' ) . '</div>';
    }

    // Check if employee is terminated (limited access)
    $is_terminated = ( $emp['status'] ?? '' ) === 'terminated';

    // Check if employee has approved resignation (also limited access during notice period)
    $has_approved_resignation = false;
    if ( ! $is_terminated ) {
        $resignation_table = $wpdb->prefix . 'sfs_hr_resignations';
        $approved_resignation = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$resignation_table}
             WHERE employee_id = %d AND status = 'approved' LIMIT 1",
            $emp_id
        ) );
        $has_approved_resignation = ( $approved_resignation > 0 );
    }

    // Limited access for both terminated and employees with approved resignation
    $is_limited_access = $is_terminated || $has_approved_resignation;

    // Department name.
    $dept_name = '';
    if ( ! empty( $emp['dept_id'] ) ) {
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $dept_name  = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT name FROM {$dept_table} WHERE id = %d",
                (int) $emp['dept_id']
            )
        );
    }

    // Core fields.
    $first_name = (string) ( $emp['first_name'] ?? '' );
    $last_name  = (string) ( $emp['last_name']  ?? '' );
    $full_name  = trim( $first_name . ' ' . $last_name );
    if ( $full_name === '' ) {
        $full_name = '#' . (int) $emp_id;
    }

    // Arabic name (for RTL display)
    $first_name_ar = (string) ( $emp['first_name_ar'] ?? '' );
    $last_name_ar  = (string) ( $emp['last_name_ar']  ?? '' );
    $full_name_ar  = trim( $first_name_ar . ' ' . $last_name_ar );

    $code        = (string) ( $emp['employee_code']         ?? '' );
    $status_raw  = (string) ( $emp['status']                ?? '' );
    $position    = (string) ( $emp['position']              ?? '' );
    $email       = (string) ( $emp['email']                 ?? '' );
    $phone       = (string) ( $emp['phone']                 ?? '' );
    $hire_date   = (string) ( $emp['hired_at']              ?? '' );
    $gender_raw  = (string) ( $emp['gender']                ?? '' );

    // Translate DB enum values for display.
    $status_map = [
        'active'     => __( 'Active', 'sfs-hr' ),
        'inactive'   => __( 'Inactive', 'sfs-hr' ),
        'terminated' => __( 'Terminated', 'sfs-hr' ),
    ];
    $gender_map = [
        'male'   => __( 'Male', 'sfs-hr' ),
        'female' => __( 'Female', 'sfs-hr' ),
    ];
    $status = $status_map[ $status_raw ] ?? ucfirst( $status_raw );
    $gender = $gender_map[ $gender_raw ] ?? ucfirst( $gender_raw );
    $base_salary = isset( $emp['base_salary'] ) && $emp['base_salary'] !== null
        ? number_format_i18n( (float) $emp['base_salary'], 2 )
        : '';

    $national_id = (string) ( $emp['national_id']           ?? '' );
    $nid_exp     = (string) ( $emp['national_id_expiry']    ?? '' );
    $passport_no = (string) ( $emp['passport_no']           ?? '' );
    $pass_exp    = (string) ( $emp['passport_expiry']       ?? '' );
    $emg_name    = (string) ( $emp['emergency_contact_name']  ?? '' );
    $emg_phone   = (string) ( $emp['emergency_contact_phone'] ?? '' );
    $photo_id    = isset( $emp['photo_id'] ) ? (int) $emp['photo_id'] : 0;

    // WP username (if linked).
    $wp_username = '';
    if ( ! empty( $emp['user_id'] ) ) {
        $u = get_userdata( (int) $emp['user_id'] );
        if ( $u && $u->user_login ) {
            $wp_username = (string) $u->user_login;
        }
    }
    
        // WP username (if linked).
    $wp_username = '';
    if ( ! empty( $emp['user_id'] ) ) {
        $u = get_userdata( (int) $emp['user_id'] );
        if ( $u && $u->user_login ) {
            $wp_username = (string) $u->user_login;
        }
    }


        // Can this user use self-web attendance?
    $can_self_clock = class_exists( Attendance_Public_REST::class )
        && Attendance_Public_REST::can_punch_self();

    // Active tab from query (?sfs_hr_tab=leave / attendance).
    $active_tab = isset( $_GET['sfs_hr_tab'] )
        ? sanitize_key( (string) $_GET['sfs_hr_tab'] )
        : 'overview';

    // Tab URLs (keep current query string but override sfs_hr_tab).
    $base_url        = remove_query_arg( 'sfs_hr_tab' );
    // Tab URLs (keep current query string but override sfs_hr_tab).
    $base_url        = remove_query_arg( 'sfs_hr_tab' );
    $overview_url    = add_query_arg( 'sfs_hr_tab', 'overview',    $base_url );
    $leave_url       = add_query_arg( 'sfs_hr_tab', 'leave',       $base_url );
    $loans_url       = add_query_arg( 'sfs_hr_tab', 'loans',       $base_url );
    $resignation_url = add_query_arg( 'sfs_hr_tab', 'resignation', $base_url );
    $settlement_url  = add_query_arg( 'sfs_hr_tab', 'settlement',  $base_url );
    $attendance_url  = add_query_arg( 'sfs_hr_tab', 'attendance',  $base_url );
    $documents_url   = add_query_arg( 'sfs_hr_tab', 'documents',   $base_url );

    // Check if employee has settlements (to show Settlement tab)
    $settle_table = $wpdb->prefix . 'sfs_hr_settlements';
    $has_settlements = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$settle_table} WHERE employee_id = %d",
        $emp_id
    ) ) > 0;

    // Check for missing required documents
    $missing_docs = [];
    $missing_docs_count = 0;
    if ( class_exists( '\SFS\HR\Modules\Documents\Services\Documents_Service' ) ) {
        $missing_docs = \SFS\HR\Modules\Documents\Services\Documents_Service::get_missing_required_documents( (int) $emp_id );
        $missing_docs_count = count( $missing_docs );
    }

    // Calculate profile completion percentage
    $profile_fields = [
        'photo'       => $photo_id > 0,
        'name'        => $first_name !== '' && $last_name !== '',
        'email'       => $email !== '',
        'phone'       => $phone !== '',
        'gender'      => $gender !== '',
        'position'    => $position !== '',
        'department'  => $dept_name !== '',
        'hire_date'   => $hire_date !== '',
        'national_id' => $national_id !== '',
        'emergency'   => $emg_name !== '' && $emg_phone !== '',
        'documents'   => $missing_docs_count === 0,
    ];
    $profile_completed = array_filter($profile_fields);
    $profile_completion_pct = round((count($profile_completed) / count($profile_fields)) * 100);
    $profile_missing = array_keys(array_filter($profile_fields, fn($v) => !$v));

    // Preload assets once – we'll show them in both desktop table + mobile cards.
    $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $asset_table  = $wpdb->prefix . 'sfs_hr_assets';
    $asset_rows   = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT 
                a.*,
                ast.id         AS asset_id,
                ast.name       AS asset_name,
                ast.asset_code AS asset_code,
                ast.category   AS category
            FROM {$assign_table} a
            LEFT JOIN {$asset_table} ast ON ast.id = a.asset_id
            WHERE a.employee_id = %d
            ORDER BY a.created_at DESC
            LIMIT 200
            ",
            (int) $emp_id
        ),
        ARRAY_A
    );

    // Helper: status badge.
    $asset_status_badge_fn = static function ( string $status_key ): string {
        $status_key = trim( $status_key );
        if ( $status_key === '' ) {
            return '';
        }
        if ( method_exists( Helpers::class, 'asset_status_badge' ) ) {
            return Helpers::asset_status_badge( $status_key );
        }
        $label = ucfirst( str_replace( '_', ' ', $status_key ) );
        return '<span class="sfs-hr-asset-status-pill">' . esc_html( $label ) . '</span>';
    };

    // Helper for field rows in overview.
    // Map English labels to translation keys for client-side language switching
    $label_to_key = static function ( string $label ): string {
        $map = [
            'Status' => 'status',
            'Gender' => 'gender',
            'Department' => 'department',
            'Position' => 'position',
            'Hire Date' => 'hire_date',
            'Hire date' => 'hire_date',
            'Employee ID' => 'employee_id',
            'WP Username' => 'wp_username',
            'Email' => 'email',
            'Phone' => 'phone',
            'Emergency contact' => 'emergency_contact',
            'Emergency Contact' => 'emergency_contact',
            'National ID' => 'national_id',
            'National ID Expiry' => 'national_id_expiry',
            'Passport No.' => 'passport_no',
            'Passport Expiry' => 'passport_expiry',
            'Base salary' => 'base_salary',
            'Base Salary' => 'base_salary',
            // Arabic labels (for when WP renders in Arabic)
            'الحالة' => 'status',
            'الجنس' => 'gender',
            'القسم' => 'department',
            'المنصب' => 'position',
            'تاريخ التعيين' => 'hire_date',
            'رقم الموظف' => 'employee_id',
            'اسم المستخدم' => 'wp_username',
            'البريد الإلكتروني' => 'email',
            'الهاتف' => 'phone',
            'جهة اتصال الطوارئ' => 'emergency_contact',
            'رقم الهوية' => 'national_id',
            'انتهاء الهوية' => 'national_id_expiry',
            'رقم الجواز' => 'passport_no',
            'انتهاء الجواز' => 'passport_expiry',
            'الراتب الأساسي' => 'base_salary',
        ];
        return $map[$label] ?? '';
    };
    $print_field = static function ( string $label, string $value ) use ( $label_to_key ): void {
        if ( $value === '' ) {
            return;
        }
        $key = $label_to_key( $label );
        $key_attr = $key ? ' data-i18n-key="' . esc_attr( $key ) . '"' : '';
        echo '<div class="sfs-hr-field-row">';
        echo '<div class="sfs-hr-field-label"' . $key_attr . '>' . esc_html( $label ) . '</div>';
        echo '<div class="sfs-hr-field-value">' . esc_html( $value ) . '</div>';
        echo '</div>';
    };

    // Helper for asset field rows (cards).
    $asset_field_fn = static function ( string $label, ?string $value ): void {
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return;
        }
        echo '<div class="sfs-hr-asset-field-row">';
        echo '<div class="sfs-hr-asset-field-label">' . esc_html( $label ) . '</div>';
        echo '<div class="sfs-hr-asset-field-value">' . esc_html( $value ) . '</div>';
        echo '</div>';
    };

    ob_start();

    // PWA App Shell for My HR Profile
    $pwa_instance = 'pwa-' . substr(wp_hash((string)get_current_user_id() . microtime(true)), 0, 6);
    ?>
    <!-- PWA App Wrapper -->
    <div class="sfs-hr-pwa-app" id="<?php echo esc_attr($pwa_instance); ?>">

    <!-- Offline Indicator -->
    <div class="sfs-hr-offline-banner" id="sfs-hr-offline-<?php echo esc_attr($pwa_instance); ?>">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px; margin-right: 6px;">
            <line x1="1" y1="1" x2="23" y2="23"></line>
            <path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"></path>
            <path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"></path>
            <path d="M10.71 5.05A16 16 0 0 1 22.58 9"></path>
            <path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"></path>
            <path d="M8.53 16.11a6 6 0 0 1 6.95 0"></path>
            <line x1="12" y1="20" x2="12.01" y2="20"></line>
        </svg>
        <?php esc_html_e('You are offline. Some features may be limited.', 'sfs-hr'); ?>
    </div>

    <!-- PWA Install Banner -->
    <div class="sfs-hr-pwa-install-banner" id="sfs-hr-pwa-banner-<?php echo esc_attr($pwa_instance); ?>">
        <div class="sfs-hr-pwa-install-content">
            <div class="sfs-hr-pwa-install-icon">
                <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="sfs-hr-pwa-install-text">
                <strong><?php esc_html_e('Install HR Suite', 'sfs-hr'); ?></strong>
                <span><?php esc_html_e('Add to home screen for quick access', 'sfs-hr'); ?></span>
            </div>
        </div>
        <div class="sfs-hr-pwa-install-buttons">
            <button type="button" class="sfs-hr-pwa-btn sfs-hr-pwa-btn--primary" id="sfs-hr-pwa-install-<?php echo esc_attr($pwa_instance); ?>">
                <?php esc_html_e('Install App', 'sfs-hr'); ?>
            </button>
            <button type="button" class="sfs-hr-pwa-btn sfs-hr-pwa-btn--secondary" id="sfs-hr-pwa-dismiss-<?php echo esc_attr($pwa_instance); ?>">
                <?php esc_html_e('Not Now', 'sfs-hr'); ?>
            </button>
        </div>
    </div>

    <!-- App Header with Title, Language and Dark Mode Toggle -->
    <header class="sfs-hr-app-header">
        <h1 class="sfs-hr-app-header-title" data-i18n="my_hr_profile"><?php echo esc_html__( 'My HR Profile', 'sfs-hr' ); ?></h1>
        <div class="sfs-hr-app-header-actions">
            <!-- Language Toggle -->
            <div class="sfs-hr-lang-toggle" id="sfs-hr-lang-toggle-<?php echo esc_attr($pwa_instance); ?>">
                <button type="button" class="sfs-hr-lang-btn" title="<?php esc_attr_e('Change language', 'sfs-hr'); ?>">
                    <span class="sfs-hr-lang-current">EN</span>
                    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
                <div class="sfs-hr-lang-dropdown">
                    <button type="button" class="sfs-hr-lang-option active" data-lang="en">
                        <span class="sfs-hr-lang-option-code">EN</span>
                        <span>English</span>
                    </button>
                    <button type="button" class="sfs-hr-lang-option" data-lang="ar">
                        <span class="sfs-hr-lang-option-code">AR</span>
                        <span>العربية</span>
                    </button>
                    <button type="button" class="sfs-hr-lang-option" data-lang="ur">
                        <span class="sfs-hr-lang-option-code">UR</span>
                        <span>اردو</span>
                    </button>
                    <button type="button" class="sfs-hr-lang-option" data-lang="fil">
                        <span class="sfs-hr-lang-option-code">FIL</span>
                        <span>Filipino</span>
                    </button>
                </div>
            </div>
            <!-- Dark Mode Toggle -->
            <button type="button" class="sfs-hr-theme-toggle" id="sfs-hr-theme-toggle-<?php echo esc_attr($pwa_instance); ?>" title="<?php esc_attr_e('Toggle dark mode', 'sfs-hr'); ?>">
                <svg class="sfs-hr-icon-moon" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
                <svg class="sfs-hr-icon-sun" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="5"></circle>
                    <line x1="12" y1="1" x2="12" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="23"></line>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                    <line x1="1" y1="12" x2="3" y2="12"></line>
                    <line x1="21" y1="12" x2="23" y2="12"></line>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
            </button>
        </div>
    </header>

    <div class="sfs-hr sfs-hr-profile sfs-hr-profile--frontend">

        <?php if ( $is_terminated ) : ?>
            <div class="sfs-hr-alert" style="background:#fff3cd;color:#856404;padding:15px;border-radius:4px;margin-bottom:20px;">
                <strong><?php esc_html_e( 'Notice:', 'sfs-hr' ); ?></strong>
                <?php esc_html_e( 'Your employment has been terminated. You have limited access to view your profile, resignation, and settlement information only.', 'sfs-hr' ); ?>
            </div>
        <?php elseif ( $has_approved_resignation ) : ?>
            <div class="sfs-hr-alert" style="background:#d1ecf1;color:#0c5460;padding:15px;border-radius:4px;margin-bottom:20px;">
                <strong><?php esc_html_e( 'Notice:', 'sfs-hr' ); ?></strong>
                <?php esc_html_e( 'Your resignation has been approved. During your notice period, you have limited access. You cannot request leave or loans, but can view your profile and resignation information.', 'sfs-hr' ); ?>
            </div>
        <?php endif; ?>

        <div class="sfs-hr-profile-tabs">
            <a href="<?php echo esc_url( $overview_url ); ?>"
               class="sfs-hr-tab <?php echo ( $active_tab === 'overview' ) ? 'sfs-hr-tab-active' : ''; ?>">
                <svg class="sfs-hr-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <span><?php esc_html_e( 'Overview', 'sfs-hr' ); ?></span>
            </a>
            <?php if ( ! $is_limited_access ) : ?>
                <a href="<?php echo esc_url( $leave_url ); ?>"
                   class="sfs-hr-tab <?php echo ( $active_tab === 'leave' ) ? 'sfs-hr-tab-active' : ''; ?>">
                    <svg class="sfs-hr-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    <span><?php esc_html_e( 'Leave', 'sfs-hr' ); ?></span>
                </a>
                <a href="<?php echo esc_url( $loans_url ); ?>"
                   class="sfs-hr-tab <?php echo ( $active_tab === 'loans' ) ? 'sfs-hr-tab-active' : ''; ?>">
                    <svg class="sfs-hr-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    <span><?php esc_html_e( 'Loans', 'sfs-hr' ); ?></span>
                </a>
            <?php endif; ?>
            <a href="<?php echo esc_url( $resignation_url ); ?>"
               class="sfs-hr-tab <?php echo ( $active_tab === 'resignation' ) ? 'sfs-hr-tab-active' : ''; ?>">
                <svg class="sfs-hr-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                <span><?php esc_html_e( 'Resignation', 'sfs-hr' ); ?></span>
            </a>

            <?php if ( $has_settlements ) : ?>
                <a href="<?php echo esc_url( $settlement_url ); ?>"
                   class="sfs-hr-tab <?php echo ( $active_tab === 'settlement' ) ? 'sfs-hr-tab-active' : ''; ?>">
                    <svg class="sfs-hr-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                    <span><?php esc_html_e( 'Settlement', 'sfs-hr' ); ?></span>
                </a>
            <?php endif; ?>

            <?php if ( $can_self_clock && ! $is_limited_access ) : ?>
                <a href="<?php echo esc_url( $attendance_url ); ?>"
                   class="sfs-hr-tab <?php echo ( $active_tab === 'attendance' ) ? 'sfs-hr-tab-active' : ''; ?>">
                    <svg class="sfs-hr-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    <span><?php esc_html_e( 'Attendance', 'sfs-hr' ); ?></span>
                </a>
            <?php endif; ?>
        </div>

        <?php if ( $active_tab === 'leave' && ! $is_limited_access ) : ?>

            <?php ( new LeaveTab() )->render( $emp, $emp_id ); ?>

        <?php elseif ( $active_tab === 'loans' && ! $is_limited_access ) : ?>

        <?php ( new LoansTab() )->render( $emp, $emp_id ); ?>

    <?php elseif ( $active_tab === 'resignation' ) : ?>

        <?php ( new ResignationTab() )->render( $emp, $emp_id ); ?>

    <?php elseif ( $active_tab === 'settlement' && $has_settlements ) : ?>

        <?php ( new SettlementTab() )->render( $emp, $emp_id ); ?>

    <?php elseif ( $active_tab === 'attendance' && $can_self_clock ) : ?>

        <div class="sfs-hr-profile-attendance-tab" style="margin-top:24px;">
            <?php
            // Full self-web widget, non-immersive so it stays inline.
            echo do_shortcode( '[sfs_hr_attendance_widget immersive="0"]' );
            ?>
        </div>

    <?php elseif ( $active_tab === 'documents' && ! $is_limited_access ) : ?>

        <?php $this->render_frontend_documents_tab( $emp_id ); ?>

    <?php else : ?>

        <div class="sfs-hr-profile-header">

    <div class="sfs-hr-profile-photo">
        <?php
        if ( $photo_id ) {
            echo wp_get_attachment_image(
                $photo_id,
                [ 96, 96 ],
                false,
                [
                    'class' => 'sfs-hr-emp-photo',
                    'style' => 'border-radius:50%;display:block;object-fit:cover;width:96px;height:96px;',
                ]
            );
        } else {
            echo '<div class="sfs-hr-emp-photo sfs-hr-emp-photo--empty">'
                 . esc_html__( 'No photo', 'sfs-hr' ) .
                 '</div>';
        }
        ?>
    </div>

    <div class="sfs-hr-profile-header-main">
        <h4 class="sfs-hr-profile-name" data-name-en="<?php echo esc_attr( $full_name ); ?>" data-name-ar="<?php echo esc_attr( $full_name_ar ?: $full_name ); ?>">
            <?php echo esc_html( $full_name ); ?>
        </h4>

        <div class="sfs-hr-profile-chips">
            <?php if ( $code !== '' ) : ?>
                <span class="sfs-hr-chip sfs-hr-chip--code">
                    <?php echo esc_html__( 'Code', 'sfs-hr' ); ?>: <?php echo esc_html( $code ); ?>
                </span>
            <?php endif; ?>

            <?php if ( $status !== '' ) : ?>
                <span class="sfs-hr-chip sfs-hr-chip--status">
                    <?php echo esc_html( $status ); ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="sfs-hr-profile-meta-line">
            <?php
            $meta_parts = [];
            if ( $position !== '' ) {
                $meta_parts[] = $position;
            }
            if ( $dept_name !== '' ) {
                $meta_parts[] = $dept_name;
            }
            echo esc_html( implode( ' · ', $meta_parts ) );
            ?>
        </div>

                <?php if ( ! empty( $can_self_clock ) ) : ?>
    <div class="sfs-hr-profile-actions">
        <a href="<?php echo esc_url( $attendance_url ); ?>"
           class="sfs-hr-att-btn"
           data-sfs-att-btn="1">
            <?php esc_html_e( 'Attendance', 'sfs-hr' ); ?>
        </a>
    </div>
<?php endif; ?>
    </div>
</div>

<?php if ( $profile_completion_pct < 100 ) : ?>
<div class="sfs-hr-profile-completion">
    <div class="sfs-hr-completion-header">
        <span class="sfs-hr-completion-title" data-i18n-key="profile_completion"><?php esc_html_e( 'Profile Completion', 'sfs-hr' ); ?></span>
        <span class="sfs-hr-completion-pct"><?php echo esc_html( $profile_completion_pct ); ?>%</span>
    </div>
    <div class="sfs-hr-completion-bar">
        <div class="sfs-hr-completion-fill" style="width:<?php echo esc_attr( $profile_completion_pct ); ?>%"></div>
    </div>
    <?php if ( ! empty( $profile_missing ) ) : ?>
    <div class="sfs-hr-completion-hint">
        <span data-i18n-key="missing"><?php esc_html_e( 'Missing', 'sfs-hr' ); ?>:</span>
        <?php
        // Store missing field keys for JS translation
        $missing_key_map = [
            'photo' => 'photo',
            'name' => 'full_name',
            'email' => 'email',
            'phone' => 'phone',
            'gender' => 'gender',
            'position' => 'position',
            'department' => 'department',
            'hire_date' => 'hire_date',
            'national_id' => 'national_id',
            'emergency' => 'emergency_contact',
        ];
        $missing_labels = [
            'photo' => __('Photo', 'sfs-hr'),
            'name' => __('Full name', 'sfs-hr'),
            'email' => __('Email', 'sfs-hr'),
            'phone' => __('Phone', 'sfs-hr'),
            'gender' => __('Gender', 'sfs-hr'),
            'position' => __('Position', 'sfs-hr'),
            'department' => __('Department', 'sfs-hr'),
            'hire_date' => __('Hire date', 'sfs-hr'),
            'national_id' => __('National ID', 'sfs-hr'),
            'emergency' => __('Emergency contact', 'sfs-hr'),
        ];
        foreach ( $profile_missing as $field ) :
            $key = $missing_key_map[$field] ?? $field;
            $label = $missing_labels[$field] ?? $field;
        ?>
            <span class="sfs-hr-missing-field" data-i18n-key="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></span><?php echo ( $field !== end($profile_missing) ) ? ', ' : ''; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

            <div class="sfs-hr-profile-grid">
                <div class="sfs-hr-profile-col">
                    <div class="sfs-hr-profile-group">
                        <div class="sfs-hr-profile-group-title" data-i18n-key="employment"><?php esc_html_e( 'Employment', 'sfs-hr' ); ?></div>
                        <div class="sfs-hr-profile-group-body">
                            <?php
                            $print_field( __( 'Status', 'sfs-hr' ),      $status );
                            $print_field( __( 'Gender', 'sfs-hr' ),      $gender );
                            $print_field( __( 'Department', 'sfs-hr' ),  $dept_name );
                            $print_field( __( 'Position', 'sfs-hr' ),    $position );
                            $print_field( __( 'Hire Date', 'sfs-hr' ),   $hire_date );
                            $print_field( __( 'Employee ID', 'sfs-hr' ), (string) $emp_id );
                            if ( $wp_username !== '' ) {
                                $print_field( __( 'WP Username', 'sfs-hr' ), $wp_username );
                            }
                            ?>
                        </div>
                    </div>

                    <div class="sfs-hr-profile-group">
                        <div class="sfs-hr-profile-group-title" data-i18n-key="contact"><?php esc_html_e( 'Contact', 'sfs-hr' ); ?></div>
                        <div class="sfs-hr-profile-group-body">
                            <?php
                            $print_field( __( 'Email', 'sfs-hr' ), $email );
                            $print_field( __( 'Phone', 'sfs-hr' ), $phone );
                            $print_field(
                                __( 'Emergency contact', 'sfs-hr' ),
                                trim( $emg_name . ( $emg_phone ? ' / ' . $emg_phone : '' ) )
                            );
                            ?>
                        </div>
                    </div>
                </div>

                <div class="sfs-hr-profile-col">
                    <div class="sfs-hr-profile-group">
                        <div class="sfs-hr-profile-group-title" data-i18n-key="identification"><?php esc_html_e( 'Identification', 'sfs-hr' ); ?></div>
                        <div class="sfs-hr-profile-group-body">
                            <?php
                            $print_field( __( 'National ID', 'sfs-hr' ),        $national_id );
                            $print_field( __( 'National ID Expiry', 'sfs-hr' ), $nid_exp );
                            $print_field( __( 'Passport No.', 'sfs-hr' ),       $passport_no );
                            $print_field( __( 'Passport Expiry', 'sfs-hr' ),    $pass_exp );
                            ?>
                        </div>
                    </div>

                    <div class="sfs-hr-profile-group">
                        <div class="sfs-hr-profile-group-title" data-i18n-key="payroll"><?php esc_html_e( 'Payroll', 'sfs-hr' ); ?></div>
                        <div class="sfs-hr-profile-group-body">
                            <?php
                            $print_field( __( 'Base salary', 'sfs-hr' ), $base_salary );
                            ?>
                        </div>
                    </div>

                    <?php if ( ! $is_limited_access ) : ?>
                    <div class="sfs-hr-profile-group sfs-hr-quick-links">
                        <div class="sfs-hr-profile-group-title" data-i18n-key="quick_links"><?php esc_html_e( 'Quick Links', 'sfs-hr' ); ?></div>
                        <div class="sfs-hr-profile-group-body">
                            <a href="<?php echo esc_url( $documents_url ); ?>" class="sfs-hr-quick-link">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                                <span data-i18n-key="my_documents"><?php esc_html_e( 'My Documents', 'sfs-hr' ); ?></span>
                                <?php if ( $missing_docs_count > 0 ) : ?>
                                    <span class="sfs-hr-notification-dot" title="<?php echo esc_attr( sprintf( _n( '%d missing document', '%d missing documents', $missing_docs_count, 'sfs-hr' ), $missing_docs_count ) ); ?>"></span>
                                <?php endif; ?>
                                <svg class="sfs-hr-quick-link-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( ! empty( $asset_rows ) ) : ?>
                <div class="sfs-hr-my-assets-frontend">
                    <h4 data-i18n-key="my_assets"><?php echo esc_html__( 'My Assets', 'sfs-hr' ); ?></h4>

                    <!-- Desktop: table view -->
                    <div class="sfs-hr-assets-desktop">
                        <table class="sfs-hr-table sfs-hr-assets-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__( 'Asset', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'Code', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'Category', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'Start', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'End', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'Status', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'Actions', 'sfs-hr' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $asset_rows as $row ) : ?>
                                <?php
                                $assignment_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
                                $row_status    = (string) ( $row['status'] ?? '' );
                                $asset_id      = isset( $row['asset_id'] ) ? (int) $row['asset_id'] : 0;
                                $asset_name    = (string) ( $row['asset_name'] ?? '' );
                                $asset_code    = (string) ( $row['asset_code'] ?? '' );
                                $category      = (string) ( $row['category']   ?? '' );
                                $start_date    = (string) ( $row['start_date'] ?? '' );
                                $end_date      = (string) ( $row['end_date']   ?? '' );

                                $title = $asset_name !== '' ? $asset_name : $asset_code;
                                if ( $title === '' ) {
                                    $title = sprintf( __( 'Asset #%d', 'sfs-hr' ), $assignment_id );
                                }

                                $title_html = esc_html( $title );
                                if ( $asset_id && ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr_assets_admin' ) ) ) {
                                    $edit_url   = add_query_arg(
                                        [
                                            'page' => 'sfs-hr-assets',
                                            'tab'  => 'assets',
                                            'view' => 'edit',
                                            'id'   => $asset_id,
                                        ],
                                        admin_url( 'admin.php' )
                                    );
                                    $title_html = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a>';
                                }

                                $status_html = $asset_status_badge_fn( $row_status );
                                ?>
                                <tr data-assignment-id="<?php echo (int) $assignment_id; ?>">
                                    <td><?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                    <td><?php echo esc_html( $asset_code ); ?></td>
                                    <td><?php echo esc_html( $category ); ?></td>
                                    <td><?php echo esc_html( $start_date ); ?></td>
                                    <td><?php echo esc_html( $end_date !== '' ? $end_date : '—' ); ?></td>
                                    <td><?php echo $status_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                    <td class="sfs-hr-asset-actions">
                                        <?php if ( $assignment_id && $row_status === 'pending_employee_approval' ) : ?>
                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form"
                                                  data-requires-photos="1">
                                                <?php wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="approve" />
                                                <button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">
                                                    <?php esc_html_e( 'Approve', 'sfs-hr' ); ?>
                                                </button>
                                            </form>

                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form">
                                                <?php wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="reject" />
                                                <button type="submit" class="sfs-hr-asset-btn sfs-hr-asset-btn--reject">
                                                    <?php esc_html_e( 'Reject', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ( $assignment_id && $row_status === 'return_requested' ) : ?>
                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form"
                                                  data-requires-photos="1">
                                                <?php wp_nonce_field( 'sfs_hr_assets_return_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_return_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="approve" />
                                                <button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">
                                                    <?php esc_html_e( 'Confirm Return', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile: collapsible cards -->
                    <div class="sfs-hr-assets-mobile">
                        <?php
                        foreach ( $asset_rows as $row ) :
                            $assignment_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
                            $row_status    = (string) ( $row['status'] ?? '' );
                            $asset_id      = isset( $row['asset_id'] ) ? (int) $row['asset_id'] : 0;
                            $asset_name    = (string) ( $row['asset_name'] ?? '' );
                            $asset_code    = (string) ( $row['asset_code'] ?? '' );
                            $category      = (string) ( $row['category']   ?? '' );
                            $start_date    = (string) ( $row['start_date'] ?? '' );
                            $end_date      = (string) ( $row['end_date']   ?? '' );

                            $title = $asset_name !== '' ? $asset_name : $asset_code;
                            if ( $title === '' ) {
                                $title = sprintf( __( 'Asset #%d', 'sfs-hr' ), $assignment_id );
                            }

                            $title_html = esc_html( $title );
                            if ( $asset_id && ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr_assets_admin' ) ) ) {
                                $edit_url   = add_query_arg(
                                    [
                                        'page' => 'sfs-hr-assets',
                                        'tab'  => 'assets',
                                        'view' => 'edit',
                                        'id'   => $asset_id,
                                    ],
                                    admin_url( 'admin.php' )
                                );
                                $title_html = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a>';
                            }

                            $status_html = $asset_status_badge_fn( $row_status );
                            ?>
                            <details class="sfs-hr-asset-card">
                                <summary class="sfs-hr-asset-summary">
                                    <span class="sfs-hr-asset-summary-title">
                                        <?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                    <span class="sfs-hr-asset-summary-status">
                                        <?php echo $status_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                </summary>

                                <div class="sfs-hr-asset-body">
                                    <div class="sfs-hr-asset-fields">
                                        <?php
                                        $asset_field_fn( __( 'Code', 'sfs-hr' ), $asset_code );
                                        $asset_field_fn( __( 'Category', 'sfs-hr' ), $category );
                                        $asset_field_fn( __( 'Start', 'sfs-hr' ), $start_date );
                                        $asset_field_fn( __( 'End', 'sfs-hr' ), $end_date !== '' ? $end_date : '—' );
                                        ?>
                                    </div>

                                    <div class="sfs-hr-asset-actions">
                                        <?php if ( $assignment_id && $row_status === 'pending_employee_approval' ) : ?>
                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form"
                                                  data-requires-photos="1">
                                                <?php wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="approve" />
                                                <button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">
                                                    <?php esc_html_e( 'Approve', 'sfs-hr' ); ?>
                                                </button>
                                            </form>

                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form">
                                                <?php wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="reject" />
                                                <button type="submit" class="sfs-hr-asset-btn sfs-hr-asset-btn--reject">
                                                    <?php esc_html_e( 'Reject', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ( $assignment_id && $row_status === 'return_requested' ) : ?>
                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form"
                                                  data-requires-photos="1">
                                                <?php wp_nonce_field( 'sfs_hr_assets_return_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_return_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="approve" />
                                                <button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">
                                                    <?php esc_html_e( 'Confirm Return', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </details>
                            <?php
                        endforeach;
                        ?>
                    </div>

                </div>
            <?php endif; // assets ?>

            <?php
            // Attendance block (unchanged).
            if ( method_exists( $this, 'render_my_attendance_frontend' ) ) {
                $this->render_my_attendance_frontend( (int) $emp_id );
            }
            ?>

            <!-- Modal for selfie + asset capture -->
            <div id="sfs-hr-asset-photo-modal" class="sfs-hr-modal" style="display:none;">
                <div class="sfs-hr-modal-backdrop"></div>
                <div class="sfs-hr-modal-dialog">
                    <div class="sfs-hr-modal-header">
                        <h4 class="sfs-hr-modal-title"><?php esc_html_e( 'Verify Asset Handover', 'sfs-hr' ); ?></h4>
                        <button type="button" class="sfs-hr-modal-close" aria-label="<?php esc_attr_e( 'Close', 'sfs-hr' ); ?>">×</button>
                    </div>
                    <div class="sfs-hr-modal-body">
                        <p class="sfs-hr-modal-step-title">
                            <?php esc_html_e( 'Step 1 of 2: Take a selfie', 'sfs-hr' ); ?>
                        </p>
                        <div class="sfs-hr-modal-camera">
                            <video autoplay playsinline class="sfs-hr-modal-video"></video>
                            <canvas class="sfs-hr-modal-canvas" style="display:none;"></canvas>
                        </div>
                        <div class="sfs-hr-modal-previews">
                            <div class="sfs-hr-modal-preview-block">
                                <span class="sfs-hr-modal-preview-label"><?php esc_html_e( 'Selfie', 'sfs-hr' ); ?></span>
                                <img class="sfs-hr-preview-selfie" alt="" style="display:none;"/>
                            </div>
                            <div class="sfs-hr-modal-preview-block">
                                <span class="sfs-hr-modal-preview-label"><?php esc_html_e( 'Asset', 'sfs-hr' ); ?></span>
                                <img class="sfs-hr-preview-asset" alt="" style="display:none;"/>
                            </div>
                        </div>
                    </div>
                    <div class="sfs-hr-modal-footer">
                        <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-cancel-btn">
                            <?php esc_html_e( 'Cancel', 'sfs-hr' ); ?>
                        </button>
                        <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-back-btn" style="display:none;">
                            <?php esc_html_e( 'Back', 'sfs-hr' ); ?>
                        </button>
                        <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-capture-btn">
                            <?php esc_html_e( 'Capture', 'sfs-hr' ); ?>
                        </button>
                        <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-next-btn" style="display:none;" disabled>
                            <?php esc_html_e( 'Next', 'sfs-hr' ); ?>
                        </button>
                        <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-done-btn" style="display:none;" disabled>
                            <?php esc_html_e( 'Done & Submit', 'sfs-hr' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <script>
            (function() {
                document.addEventListener('DOMContentLoaded', function() {
                    var modal = document.getElementById('sfs-hr-asset-photo-modal');
                    if (!modal) { return; }

                    // On desktop, we keep cards hidden anyway; this just ensures they're open if revealed.
                    if (window.matchMedia && window.matchMedia('(min-width: 768px)').matches) {
                        document.querySelectorAll('.sfs-hr-asset-card').forEach(function(d) {
                            d.setAttribute('open', 'open');
                        });
                    }

                    var backdrop     = modal.querySelector('.sfs-hr-modal-backdrop');
                    var closeBtn     = modal.querySelector('.sfs-hr-modal-close');
                    var cancelBtn    = modal.querySelector('.sfs-hr-modal-cancel-btn');
                    var backBtn      = modal.querySelector('.sfs-hr-modal-back-btn');
                    var captureBtn   = modal.querySelector('.sfs-hr-modal-capture-btn');
                    var nextBtn      = modal.querySelector('.sfs-hr-modal-next-btn');
                    var doneBtn      = modal.querySelector('.sfs-hr-modal-done-btn');
                    var stepTitle    = modal.querySelector('.sfs-hr-modal-step-title');
                    var video        = modal.querySelector('.sfs-hr-modal-video');
                    var canvas       = modal.querySelector('.sfs-hr-modal-canvas');
                    var previewSelf  = modal.querySelector('.sfs-hr-preview-selfie');
                    var previewAsset = modal.querySelector('.sfs-hr-preview-asset');

                    var currentForm  = null;
                    var currentStep  = 1; // 1 = selfie, 2 = asset
                    var stream       = null;
                    var selfieData   = '';
                    var assetData    = '';

                    function updateStepUI() {
                        if (currentStep === 1) {
                            stepTitle.textContent = <?php echo json_encode( __( 'Step 1 of 2: Take a selfie', 'sfs-hr' ) ); ?>;
                            if (backBtn)  backBtn.style.display = 'none';
                            if (captureBtn) captureBtn.style.display = 'inline-block';
                            if (nextBtn) {
                                nextBtn.style.display = 'inline-block';
                                nextBtn.disabled = !selfieData;
                            }
                            if (doneBtn) {
                                doneBtn.style.display = 'none';
                                doneBtn.disabled = true;
                            }
                        } else {
                            stepTitle.textContent = <?php echo json_encode( __( 'Step 2 of 2: Take photo of asset', 'sfs-hr' ) ); ?>;
                            if (backBtn)  backBtn.style.display = 'inline-block';
                            if (captureBtn) captureBtn.style.display = 'inline-block';
                            if (nextBtn)   nextBtn.style.display = 'none';
                            if (doneBtn) {
                                doneBtn.style.display = 'inline-block';
                                doneBtn.disabled = !assetData;
                            }
                        }
                    }

                    function stopStream() {
                        if (stream) {
                            try { stream.getTracks().forEach(function(t){ t.stop(); }); } catch(e) {}
                        }
                        stream = null;
                        if (video) {
                            video.srcObject = null;
                        }
                    }

                    function startStreamForStep(step) {
                        stopStream();

                        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !video) {
                            alert('<?php echo esc_js( __( 'Camera API not supported in this browser.', 'sfs-hr' ) ); ?>');
                            return;
                        }

                        var primaryConstraints;
                        if (step === 1) {
                            primaryConstraints = { video: { facingMode: 'user' } };
                        } else {
                            primaryConstraints = { video: { facingMode: { ideal: 'environment' } } };
                        }

                        function startStreamSuccess(s) {
                            stream = s;
                            try {
                                video.srcObject = s;
                                var playPromise = video.play();
                                if (playPromise && playPromise.catch) {
                                    playPromise.catch(function() {});
                                }
                            } catch (e) {}
                        }

                        navigator.mediaDevices.getUserMedia(primaryConstraints)
                            .then(startStreamSuccess)
                            .catch(function() {
                                navigator.mediaDevices.getUserMedia({ video: true })
                                    .then(startStreamSuccess)
                                    .catch(function() {
                                        alert('<?php echo esc_js( __( 'Unable to access the camera. Please allow camera permissions.', 'sfs-hr' ) ); ?>');
                                    });
                            });
                    }

                    function openModal(form) {
                        currentForm = form;
                        currentStep = 1;
                        selfieData  = '';
                        assetData   = '';

                        if (previewSelf) {
                            previewSelf.style.display = 'none';
                            previewSelf.src = '';
                        }
                        if (previewAsset) {
                            previewAsset.style.display = 'none';
                            previewAsset.src = '';
                        }

                        modal.style.display = 'block';
                        updateStepUI();
                        startStreamForStep(1);
                    }

                    function closeModal() {
                        stopStream();
                        modal.style.display = 'none';
                        currentForm = null;
                        currentStep = 1;
                        selfieData  = '';
                        assetData   = '';
                    }

                    function captureCurrent() {
                        if (!video || !canvas) { return; }
                        var w = video.videoWidth || 640;
                        var h = video.videoHeight || 480;
                        if (!w || !h) { return; }
                        canvas.width  = w;
                        canvas.height = h;
                        var ctx = canvas.getContext('2d');
                        ctx.drawImage(video, 0, 0, w, h);
                        var dataUrl = canvas.toDataURL('image/jpeg', 0.8);

                        if (currentStep === 1) {
                            selfieData = dataUrl;
                            if (previewSelf) {
                                previewSelf.src = dataUrl;
                                previewSelf.style.display = 'block';
                            }
                        } else {
                            assetData = dataUrl;
                            if (previewAsset) {
                                previewAsset.src = dataUrl;
                                previewAsset.style.display = 'block';
                            }
                        }
                        updateStepUI();
                    }

                    function submitWithPhotos() {
                        if (!currentForm) {
                            return;
                        }

                        var selfieInput = currentForm.querySelector('input[name="selfie_data"]');
                        if (!selfieInput) {
                            selfieInput = document.createElement('input');
                            selfieInput.type = 'hidden';
                            selfieInput.name = 'selfie_data';
                            currentForm.appendChild(selfieInput);
                        }
                        selfieInput.value = selfieData || '';

                        var assetInput = currentForm.querySelector('input[name="asset_data"]');
                        if (!assetInput) {
                            assetInput = document.createElement('input');
                            assetInput.type = 'hidden';
                            assetInput.name = 'asset_data';
                            currentForm.appendChild(assetInput);
                        }
                        assetInput.value = assetData || '';

                        currentForm.submit();
                        closeModal();
                    }

                    if (captureBtn) {
                        captureBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            captureCurrent();
                        });
                    }

                    if (nextBtn) {
                        nextBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            if (!selfieData) { return; }
                            currentStep = 2;
                            updateStepUI();
                            startStreamForStep(2);
                        });
                    }

                    if (backBtn) {
                        backBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            currentStep = 1;
                            updateStepUI();
                            startStreamForStep(1);
                        });
                    }

                    if (doneBtn) {
                        doneBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            submitWithPhotos();
                        });
                    }

                    if (cancelBtn) {
                        cancelBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            closeModal();
                        });
                    }

                    if (closeBtn) {
                        closeBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            closeModal();
                        });
                    }

                    if (backdrop) {
                        backdrop.addEventListener('click', function(e) {
                            e.preventDefault();
                            closeModal();
                        });
                    }

                    // Attach to Approve / Confirm Return buttons that require photos.
                    var container = document.querySelector('.sfs-hr-my-assets-frontend');
                    if (!container) { return; }

                    container.addEventListener('click', function(e) {
                        var target = e.target;
                        if (!target || !target.classList || !target.classList.contains('sfs-hr-asset-btn--approve')) {
                            return;
                        }
                        var form = target.closest('form');
                        if (!form) { return; }
                        if (form.getAttribute('data-requires-photos') !== '1') {
                            return;
                        }
                        e.preventDefault();
                        openModal(form);
                    });
                });
            })();
            </script>
               <script>
(function () {
    'use strict';
    
    function updateAttendanceButton() {
        var btn = document.querySelector('.sfs-hr-att-btn[data-sfs-att-btn="1"]');
        if (!btn) {
            console.warn('Attendance button not found');
            return;
        }

        var statusUrl = '<?php echo esc_js( rest_url( 'sfs-hr/v1/attendance/status' ) ); ?>';
        var nonce = '<?php echo wp_create_nonce( 'wp_rest' ); ?>';

        // Add a loading indicator
        btn.textContent = '<?php echo esc_js( __( 'Loading...', 'sfs-hr' ) ); ?>';

        fetch(statusUrl, {
            credentials: 'same-origin',
            headers: {
                'Cache-Control': 'no-cache',
                'X-WP-Nonce': nonce
            }
        })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (data) {
                if (!data) {
                    btn.textContent = '<?php echo esc_js( __( 'Attendance', 'sfs-hr' ) ); ?>';
                    return;
                }

                // Support both { allow: {...} } and flat { in: true, ... }
                var allow = data.allow || data;
                var label = '';

                // Check what actions are allowed - priority order matters
                var action = '';
                if (allow.in) {
                    label = '<?php echo esc_js( __( 'Clock In', 'sfs-hr' ) ); ?>';
                    action = 'in';
                } else if (allow.break_start) {
                    label = '<?php echo esc_js( __( 'Start Break', 'sfs-hr' ) ); ?>';
                    action = 'break_start';
                } else if (allow.break_end) {
                    label = '<?php echo esc_js( __( 'End Break', 'sfs-hr' ) ); ?>';
                    action = 'break_end';
                } else if (allow.out) {
                    label = '<?php echo esc_js( __( 'Clock Out', 'sfs-hr' ) ); ?>';
                    action = 'out';
                }

                // Update button text and action attribute
                if (label) {
                    btn.textContent = label;
                    btn.setAttribute('data-action', action);
                } else {
                    btn.textContent = '<?php echo esc_js( __( 'Attendance', 'sfs-hr' ) ); ?>';
                    btn.removeAttribute('data-action');
                }
            })
            .catch(function (err) {
                console.error('Attendance status fetch failed:', err);
                // Restore default text on error
                btn.textContent = '<?php echo esc_js( __( 'Attendance', 'sfs-hr' ) ); ?>';
            });
    }

    // Run after DOM is fully loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateAttendanceButton);
    } else {
        updateAttendanceButton();
    }
})();
</script>




        <?php endif; // overview/leave ?>

    </div>

    <style>
    .sfs-hr-profile-header {
        display:flex;
        align-items:center;
        gap:16px;
        margin:0 0 16px;
    }
    .sfs-hr-profile-photo {
        flex-shrink: 0;
    }
    .sfs-hr-profile-photo img,
    .sfs-hr-emp-photo {
        width:96px;
        height:96px;
        border-radius:50%;
        object-fit:cover;
        display: block;
    }
    .sfs-hr-emp-photo--empty {
        width:96px;
        height:96px;
        border-radius:50%;
        background:#f3f4f5;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:12px;
        color:#666;
    }
    .sfs-hr-profile-name {
        margin:0 0 4px;
        font-size:20px;
    }
    .sfs-hr-profile-chips {
        margin:0 0 6px;
    }
    .sfs-hr-chip {
        display:inline-block;
        border-radius:999px;
        padding:2px 10px;
        font-size:11px;
        margin-right:6px;
    }
    .sfs-hr-chip--code {
        background:#f1f1f1;
    }
    .sfs-hr-chip--status {
        background:#e5f5ff;
    }
    .sfs-hr-profile-meta-line {
        font-size:13px;
        color:#555;
    }

    .sfs-hr-profile-actions {
        margin-top:8px;
    }

    .sfs-hr-att-btn {
        display:inline-flex;
        align-items:center;
        padding:6px 14px;
        border-radius:999px;
        border:1px solid #2563eb;
        background:#2563eb;
        color:#ffffff !important;
        font-size:13px;
        font-weight:500;
        text-decoration:none;
        cursor:pointer;
        transition:
            background .15s ease,
            border-color .15s ease,
            box-shadow .15s ease,
            transform .05s ease;
    }

    .sfs-hr-att-btn:hover,
    .sfs-hr-att-btn:visited,
    .sfs-hr-att-btn:active,
    .sfs-hr-att-btn:focus {
        background:#1d4ed8;
        border-color:#1d4ed8;
        box-shadow:0 4px 10px rgba(37,99,235,0.25);
        color:#ffffff !important;
        text-decoration:none;
        transform:translateY(-1px);
    }

    .sfs-hr-att-btn:active {
        transform:translateY(0);
        box-shadow:none;
    }

    /* Tabs */
    .sfs-hr-profile-tabs {
        margin:8px 0 16px;
        border-bottom:1px solid #eee;
    }
    .sfs-hr-tab {
        display:inline-block;
        padding:6px 12px;
        margin-right:4px;
        text-decoration:none;
        border-radius:4px 4px 0 0;
        border:1px solid transparent;
        border-bottom:none;
        background:#f3f4f6;
        color:#6b7280;
    }
    .sfs-hr-tab-icon {
        display:none; /* Hide icons on desktop */
    }
    .sfs-hr-tab:hover {
        background:#e5e7eb;
        color:#111827;
    }
    .sfs-hr-tab-active {
        background:#ffffff;
        color:#111827;
        border-color:#2563eb #2563eb #fff;
        font-weight:600;
    }

    /* Profile Completion Meter */
    .sfs-hr-profile-completion {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border: 1px solid #bae6fd;
        border-radius: 10px;
        padding: 14px 16px;
        margin: 16px 0;
    }
    .sfs-hr-completion-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }
    .sfs-hr-completion-title {
        font-size: 13px;
        font-weight: 600;
        color: #0369a1;
    }
    .sfs-hr-completion-pct {
        font-size: 14px;
        font-weight: 700;
        color: #0284c7;
    }
    .sfs-hr-completion-bar {
        height: 8px;
        background: #e0f2fe;
        border-radius: 4px;
        overflow: hidden;
    }
    .sfs-hr-completion-fill {
        height: 100%;
        background: linear-gradient(90deg, #0ea5e9 0%, #0284c7 100%);
        border-radius: 4px;
        transition: width 0.5s ease;
    }
    .sfs-hr-completion-hint {
        margin-top: 8px;
        font-size: 11px;
        color: #0369a1;
    }

    /* Overview grid layout */
    .sfs-hr-profile-grid {
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));
        gap:16px;
        margin-bottom:24px;
    }
    .sfs-hr-profile-group {
        background:#fff;
        border:1px solid #e5e7eb;
        border-radius:8px;
        padding:12px 14px;
        margin-bottom:12px;
    }
    .sfs-hr-profile-col .sfs-hr-profile-group:last-child {
        margin-bottom:0;
    }
    .sfs-hr-profile-group-title {
        font-size:13px;
        font-weight:600;
        margin-bottom:8px;
        text-transform:uppercase;
        letter-spacing:.03em;
        color:#4b5563;
    }
    .sfs-hr-field-row {
        display:flex;
        justify-content:space-between;
        gap:8px;
        padding:4px 0;
        font-size:13px;
        border-bottom:1px dashed #f1f1f1;
    }
    .sfs-hr-field-row:last-child {
        border-bottom:none;
    }
    .sfs-hr-field-label {
        color:#6b7280;
        flex:0 0 45%;
    }
    .sfs-hr-field-value {
        text-align:right;
        flex:1;
        color:#111827;
    }

    .sfs-hr-table {
        width:100%;
        border-collapse:collapse;
    }
    .sfs-hr-table th,
    .sfs-hr-table td {
        padding:8px 10px;
        border-bottom:1px solid #eee;
        text-align:start;
        font-size:13px;
    }

    .sfs-hr-alert {
        padding:12px;
        background:#fef3c7;
        border:1px solid:#fde68a;
        border-radius:8px;
        margin-bottom:10px;
    }

    /* My Assets wrapper */
    .sfs-hr-my-assets-frontend {
        border:1px solid #e5e7eb;
        border-radius:8px;
        padding:16px 18px 18px;
        margin-top:24px;
        margin-bottom:24px;
        background:#ffffff;
    }
    .sfs-hr-my-assets-frontend h4 {
        margin:0 0 12px;
    }

    .sfs-hr-assets-desktop { display:block; }
    .sfs-hr-assets-mobile  { display:none; }

    .sfs-hr-assets-desktop .sfs-hr-asset-actions {
        white-space:nowrap;
    }

    /* Collapsible cards (mobile block) */
    .sfs-hr-assets-mobile .sfs-hr-asset-card {
        border-top:1px solid #f3f4f6;
        padding:6px 0;
    }
    .sfs-hr-assets-mobile .sfs-hr-asset-card:first-of-type {
        border-top:none;
    }
    .sfs-hr-asset-summary {
        display:flex;
        align-items:center;
        gap:8px;
        cursor:pointer;
        padding:6px 0;
        list-style:none;
    }
    .sfs-hr-asset-summary::-webkit-details-marker { display:none; }
    .sfs-hr-asset-summary-title {
        flex:1;
        min-width:0;
    }
    .sfs-hr-asset-summary-status {
        margin-left:auto;
    }
    .sfs-hr-asset-summary::after {
        content:"›";
        font-size:14px;
        transform:rotate(90deg);
        opacity:0.4;
        margin-left:4px;
    }
    .sfs-hr-asset-card[open] .sfs-hr-asset-summary::after {
        transform:rotate(-90deg);
    }
    .sfs-hr-asset-summary-title a {
        text-decoration:none;
    }
    .sfs-hr-asset-body {
        padding:4px 0 8px;
        border-top:1px dashed #e5e7eb;
        margin-top:4px;
    }
    .sfs-hr-asset-fields {
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));
        gap:4px 16px;
        font-size:12px;
    }
    .sfs-hr-asset-field-row {
        display:flex;
        justify-content:space-between;
        gap:6px;
    }
    .sfs-hr-asset-field-label {
        color:#6b7280;
    }
    .sfs-hr-asset-field-value {
        text-align:right;
        color:#111827;
        font-weight:500;
    }
    .sfs-hr-asset-status-pill {
        display:inline-block;
        padding:1px 8px;
        border-radius:999px;
        border:1px solid #e5e7eb;
        background:#f3f4f6;
        color:#374151;
        font-size:11px;
        white-space:nowrap;
    }

    /* Asset actions buttons */
    .sfs-hr-asset-actions {
        margin-top:8px;
        display:flex;
        flex-wrap:wrap;
        gap:6px;
    }
    .sfs-hr-asset-action-form { margin:0; }

    .sfs-hr-asset-btn {
        appearance:none;
        border-radius:999px;
        border:1px solid #e5e7eb;
        background:#ffffff;
        padding:4px 14px;
        min-width:92px;
        font-size:11px;
        line-height:1.4;
        cursor:pointer;
        color:#111827;
        text-align:center;
        transition:background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .sfs-hr-asset-btn--approve {
        border-color:#16a34a;
        background:#ecfdf3;
        color:#166534;
    }
    .sfs-hr-asset-btn--approve:hover {
        background:#bbf7d0;
        border-color:#16a34a;
        box-shadow:0 0 0 1px rgba(22,163,74,0.15);
    }
    .sfs-hr-asset-btn--reject {
        border-color:#e5e7eb;
        background:#fef2f2;
        color:#b91c1c;
    }
    .sfs-hr-asset-btn--reject:hover {
        background:#fee2e2;
        border-color:#fecaca;
        box-shadow:0 0 0 1px rgba(248,113,113,0.25);
    }

    @media (max-width:640px) {
        .sfs-hr-assets-desktop { display:none; }
        .sfs-hr-assets-mobile  { display:block; }
        .sfs-hr-asset-fields   { grid-template-columns:1fr; }
        .sfs-hr-asset-summary  { align-items:flex-start; }
        .sfs-hr-asset-btn {
            min-width:86px;
            padding:3px 10px;
            font-size:10px;
        }
    }

    /* Attendance card */
    .sfs-hr-my-attendance-frontend {
        border:1px solid #e5e7eb;
        border-radius:8px;
        padding:16px 18px 18px;
        margin-top:24px;
        margin-bottom:24px;
        background:#ffffff;
    }
    .sfs-hr-my-attendance-frontend h4 {
        margin:0 0 12px;
    }

    /* Modal */
    .sfs-hr-modal {
        position:fixed;
        inset:0;
        z-index:9999;
    }
    .sfs-hr-modal-backdrop {
        position:absolute;
        inset:0;
        background:rgba(15,23,42,0.45);
    }
    .sfs-hr-modal-dialog {
        position:relative;
        max-width:480px;
        margin:40px auto;
        background:#ffffff;
        border-radius:12px;
        box-shadow:0 20px 40px rgba(15,23,42,0.4);
        display:flex;
        flex-direction:column;
        overflow:hidden;
    }
    .sfs-hr-modal-header {
        padding:10px 14px;
        border-bottom:1px solid #e5e7eb;
        display:flex;
        align-items:center;
        justify-content:space-between;
    }
    .sfs-hr-modal-title {
        margin:0;
        font-size:16px;
        font-weight:600;
    }
    .sfs-hr-modal-close {
        border:none;
        background:transparent;
        font-size:18px;
        line-height:1;
        cursor:pointer;
    }
    .sfs-hr-modal-body {
        padding:12px 14px 10px;
    }
    .sfs-hr-modal-step-title {
        margin:0 0 8px;
        font-size:13px;
        color:#4b5563;
    }
    .sfs-hr-modal-camera {
        background:#000;
        border-radius:8px;
        overflow:hidden;
        max-height:260px;
        display:flex;
        align-items:center;
        justify-content:center;
        margin-bottom:10px;
    }
    .sfs-hr-modal-video {
        width:100%;
        max-height:260px;
        object-fit:cover;
    }
    .sfs-hr-modal-previews {
        display:flex;
        gap:8px;
        margin-top:4px;
    }
    .sfs-hr-modal-preview-block {
        flex:1;
        border:1px dashed #e5e7eb;
        border-radius:8px;
        padding:4px;
        text-align:center;
    }
    .sfs-hr-modal-preview-label {
        display:block;
        font-size:11px;
        color:#6b7280;
        margin-bottom:4px;
    }
    .sfs-hr-modal-preview-block img {
        max-width:100%;
        max-height:80px;
        border-radius:6px;
    }
    .sfs-hr-modal-footer {
        padding:10px 14px;
        border-top:1px solid #e5e7eb;
        text-align:right;
    }
    .sfs-hr-modal-btn {
        display:inline-block;
        margin-left:6px;
        padding:4px 10px;
        font-size:12px;
        border-radius:999px;
        border:1px solid #e5e7eb;
        background:#f9fafb;
        cursor:pointer;
        color:#111827;
        transition:background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .sfs-hr-modal-btn:hover:not(:disabled) {
        background:#e5e7eb;
    }
    .sfs-hr-modal-done-btn,
    .sfs-hr-modal-next-btn {
        background:#2563eb;
        border-color:#2563eb;
        color:#ffffff;
    }
    .sfs-hr-modal-done-btn:hover:not(:disabled),
    .sfs-hr-modal-next-btn:hover:not(:disabled) {
        background:#1d4ed8;
        border-color:#1d4ed8;
    }
    .sfs-hr-modal-cancel-btn {
        background:#ffffff;
    }
    .sfs-hr-modal-btn:disabled,
    .sfs-hr-modal-done-btn:disabled,
    .sfs-hr-modal-next-btn:disabled {
        background:#f3f4f6;
        border-color:#e5e7eb;
        color:#9ca3af;
        opacity:1;
        cursor:not-allowed;
        box-shadow:none;
    }
    
    
    /* Leave tab block */
.sfs-hr-my-profile-leave {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px 18px 18px;
    margin-top: 24px;
    margin-bottom: 24px;
    background: #ffffff;
}
.sfs-hr-my-profile-leave h4 {
    margin: 0 0 12px;
}
.sfs-hr-my-profile-leave .sfs-hr-leave-self-form p {
    margin-bottom: 10px;
}
.sfs-hr-my-profile-leave table.sfs-hr-leave-table th,
.sfs-hr-my-profile-leave table.sfs-hr-leave-table td {
    font-size: 12px;
}

/* Leave history: desktop vs mobile */
.sfs-hr-leaves-desktop {
    display: block;
}
.sfs-hr-leaves-mobile {
    display: none;
}

/* Mobile leave cards */
.sfs-hr-leave-card {
    border-top: 1px solid #f3f4f6;
    padding: 8px 12px;
}
.sfs-hr-leave-card:first-of-type {
    border-top: none;
}
.sfs-hr-leave-summary {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 6px 0;
    list-style: none;
}
.sfs-hr-leave-summary::-webkit-details-marker {
    display: none;
}
.sfs-hr-leave-summary-title {
    flex: 1;
    min-width: 0;
    font-weight: 500;
}
.sfs-hr-leave-summary-status {
    margin-left: auto;
}
.sfs-hr-leave-summary::after {
    content: "›";
    font-size: 14px;
    transform: rotate(90deg);
    opacity: 0.4;
    margin-left: 4px;
}
.sfs-hr-leave-card[open] .sfs-hr-leave-summary::after {
    transform: rotate(-90deg);
}

.sfs-hr-leave-body {
    padding: 4px 0 8px;
    border-top: 1px dashed #e5e7eb;
    margin-top: 4px;
}
.sfs-hr-leave-field-row {
    display: flex;
    justify-content: space-between;
    gap: 6px;
    font-size: 12px;
    margin: 2px 0;
}
.sfs-hr-leave-field-label {
    color: #6b7280;
}
.sfs-hr-leave-field-value {
    text-align: right;
    color: #111827;
    font-weight: 500;
}

/* Mobile breakpoint */
@media (max-width: 640px) {
    .sfs-hr-leaves-desktop {
        display: none;
    }
    .sfs-hr-leaves-mobile {
        display: block;
    }
}

/* Notification dot for missing documents */
.sfs-hr-notification-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    background-color: #dc2626;
    border-radius: 50%;
    margin-left: 6px;
    flex-shrink: 0;
    animation: sfs-hr-pulse 2s infinite;
}
@keyframes sfs-hr-pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

/* Quick link with notification */
.sfs-hr-quick-link {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
}


    </style>

    </div><!-- /.sfs-hr-pwa-app -->

    <!-- PWA JavaScript -->
    <script>
    (function() {
        var inst = '<?php echo esc_js($pwa_instance); ?>';
        var sfsHrLangUrl = '<?php echo esc_js(SFS_HR_URL . "languages/"); ?>';
        var offlineBanner = document.getElementById('sfs-hr-offline-' + inst);
        var pwaBanner = document.getElementById('sfs-hr-pwa-banner-' + inst);
        var installBtn = document.getElementById('sfs-hr-pwa-install-' + inst);
        var dismissBtn = document.getElementById('sfs-hr-pwa-dismiss-' + inst);
        var deferredPrompt = null;

        // Offline detection
        function updateOfflineStatus() {
            if (offlineBanner) {
                offlineBanner.classList.toggle('visible', !navigator.onLine);
            }
        }
        window.addEventListener('online', updateOfflineStatus);
        window.addEventListener('offline', updateOfflineStatus);
        updateOfflineStatus();

        // Detect iOS Safari
        var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
        var isIOSSafari = isIOS && isSafari;
        var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

        // PWA Install prompt for Chrome/Android
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            deferredPrompt = e;

            // Check if already dismissed or in standalone mode
            if (localStorage.getItem('sfs_hr_pwa_dismissed') || isStandalone) {
                return;
            }

            // Show install banner after 2 seconds
            setTimeout(function() {
                if (pwaBanner) {
                    pwaBanner.classList.add('visible');
                }
            }, 2000);
        });

        // For iOS Safari - show banner since beforeinstallprompt doesn't fire
        if (isIOS && !isStandalone && !localStorage.getItem('sfs_hr_pwa_dismissed')) {
            setTimeout(function() {
                if (pwaBanner) {
                    pwaBanner.classList.add('visible');
                    // Update button text for iOS
                    if (installBtn) {
                        installBtn.textContent = '<?php echo esc_js(__('How to Install', 'sfs-hr')); ?>';
                    }
                }
            }, 3000);
        }

        // Install button click
        if (installBtn) {
            installBtn.addEventListener('click', function() {
                if (!deferredPrompt) {
                    // Show iOS instructions
                    if (isIOS) {
                        alert('<?php echo esc_js(__('To install: tap the Share button at the bottom of Safari, then tap "Add to Home Screen"', 'sfs-hr')); ?>');
                    }
                    return;
                }

                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function(choice) {
                    deferredPrompt = null;
                    if (pwaBanner) {
                        pwaBanner.classList.remove('visible');
                    }
                });
            });
        }

        // Dismiss button click
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function() {
                localStorage.setItem('sfs_hr_pwa_dismissed', '1');
                if (pwaBanner) {
                    pwaBanner.classList.remove('visible');
                }
            });
        }

        // Service worker is registered by PWAModule (pwa-app.js)
        // Do not register here to avoid conflicts

        // Viewport meta for standalone mode
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
            document.documentElement.classList.add('sfs-hr-standalone');
        }

        // Scroll detection for header positioning
        var pwaAppEl = document.getElementById(inst);
        if (pwaAppEl && document.body.classList.contains('admin-bar')) {
            var scrollThreshold = 10;
            var lastScrollY = 0;

            function handleScroll() {
                var currentScrollY = window.scrollY || window.pageYOffset;
                if (currentScrollY > scrollThreshold) {
                    pwaAppEl.classList.add('sfs-hr-scrolled');
                } else {
                    pwaAppEl.classList.remove('sfs-hr-scrolled');
                }
                lastScrollY = currentScrollY;
            }

            window.addEventListener('scroll', handleScroll, { passive: true });
            handleScroll(); // Check initial state
        }

        // Dark mode toggle
        var themeToggle = document.getElementById('sfs-hr-theme-toggle-' + inst);
        var pwaApp = document.getElementById(inst);

        if (themeToggle && pwaApp) {
            // Check for saved preference
            var savedTheme = localStorage.getItem('sfs_hr_theme');
            if (savedTheme === 'dark') {
                pwaApp.classList.add('sfs-hr-dark-mode');
                pwaApp.classList.remove('sfs-hr-light-mode');
            } else if (savedTheme === 'light') {
                pwaApp.classList.add('sfs-hr-light-mode');
                pwaApp.classList.remove('sfs-hr-dark-mode');
            }

            themeToggle.addEventListener('click', function() {
                var isDark = pwaApp.classList.contains('sfs-hr-dark-mode');
                var isSystemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (isDark) {
                    // Currently dark (manual) -> switch to light
                    pwaApp.classList.remove('sfs-hr-dark-mode');
                    pwaApp.classList.add('sfs-hr-light-mode');
                    localStorage.setItem('sfs_hr_theme', 'light');
                } else if (pwaApp.classList.contains('sfs-hr-light-mode')) {
                    // Currently light (manual) -> switch to dark
                    pwaApp.classList.remove('sfs-hr-light-mode');
                    pwaApp.classList.add('sfs-hr-dark-mode');
                    localStorage.setItem('sfs_hr_theme', 'dark');
                } else if (isSystemDark) {
                    // System is dark, no manual preference -> switch to light
                    pwaApp.classList.add('sfs-hr-light-mode');
                    localStorage.setItem('sfs_hr_theme', 'light');
                } else {
                    // System is light, no manual preference -> switch to dark
                    pwaApp.classList.add('sfs-hr-dark-mode');
                    localStorage.setItem('sfs_hr_theme', 'dark');
                }
            });
        }

        // Language toggle functionality
        var langToggle = document.getElementById('sfs-hr-lang-toggle-' + inst);
        if (langToggle) {
            var langBtn = langToggle.querySelector('.sfs-hr-lang-btn');
            var langDropdown = langToggle.querySelector('.sfs-hr-lang-dropdown');
            var langOptions = langToggle.querySelectorAll('.sfs-hr-lang-option');
            var langCurrent = langToggle.querySelector('.sfs-hr-lang-current');

            // Translation cache - loaded from JSON files
            var translations = {};
            var translationsLoaded = false;

            // Inline fallback translations (essential keys only)
            var fallbackTranslations = {
                en: { overview: 'Overview', leave: 'Leave', loans: 'Loans', resignation: 'Resignation', attendance: 'Attendance' },
                ar: { overview: 'نظرة عامة', leave: 'الإجازات', loans: 'القروض', resignation: 'الاستقالة', attendance: 'الحضور' },
                ur: { overview: 'جائزہ', leave: 'چھٹی', loans: 'قرضے', resignation: 'استعفیٰ', attendance: 'حاضری' },
                fil: { overview: 'Pangkalahatang-tanaw', leave: 'Leave', loans: 'Utang', resignation: 'Pagbibitiw', attendance: 'Attendance' }
            };

            // Load translations from JSON file
            function loadTranslations(lang) {
                return new Promise(function(resolve) {
                    if (translations[lang]) {
                        resolve(translations[lang]);
                        return;
                    }
                    fetch(sfsHrLangUrl + lang + '.json')
                        .then(function(response) {
                            if (!response.ok) throw new Error('Not found');
                            return response.json();
                        })
                        .then(function(data) {
                            translations[lang] = data;
                            translationsLoaded = true;
                            resolve(data);
                        })
                        .catch(function() {
                            // Use fallback if fetch fails
                            translations[lang] = fallbackTranslations[lang] || fallbackTranslations.en;
                            resolve(translations[lang]);
                        });
                });
            }

            // Load saved language and apply
            var savedLang = localStorage.getItem('sfs_hr_lang') || 'en';
            // Preload English as fallback, then apply saved language
            try {
                loadTranslations('en').then(function() {
                    loadTranslations(savedLang).then(function() {
                        applyLanguage(savedLang);
                    }).catch(function() { applyLanguage(savedLang); });
                }).catch(function() { applyLanguage(savedLang); });
            } catch(e) {
                // If promises fail, still apply language with fallback
                translations = fallbackTranslations;
                applyLanguage(savedLang);
            }

            // Toggle dropdown
            langBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                langDropdown.classList.toggle('active');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function() {
                langDropdown.classList.remove('active');
            });

            // Language option click
            langOptions.forEach(function(option) {
                option.addEventListener('click', function() {
                    var lang = this.dataset.lang;
                    loadTranslations(lang).then(function() {
                        applyLanguage(lang);
                        localStorage.setItem('sfs_hr_lang', lang);
                        langDropdown.classList.remove('active');
                    });
                });
            });

            function applyLanguage(lang) {
                // Update current language display
                if (langCurrent) langCurrent.textContent = lang.toUpperCase();

                // Update active state
                langOptions.forEach(function(opt) {
                    opt.classList.remove('active');
                    if (opt.dataset.lang === lang) {
                        opt.classList.add('active');
                    }
                });

                // Apply RTL for Arabic and Urdu
                if (lang === 'ar' || lang === 'ur') {
                    pwaApp.setAttribute('dir', 'rtl');
                    pwaApp.style.textAlign = 'right';
                } else {
                    pwaApp.setAttribute('dir', 'ltr');
                    pwaApp.style.textAlign = 'left';
                }

                // Switch profile name based on language
                var profileName = pwaApp.querySelector('.sfs-hr-profile-name');
                if (profileName && profileName.dataset.nameAr) {
                    if (lang === 'ar' && profileName.dataset.nameAr !== profileName.dataset.nameEn) {
                        profileName.textContent = profileName.dataset.nameAr;
                    } else {
                        profileName.textContent = profileName.dataset.nameEn;
                    }
                }

                // Translate elements with data-i18n attribute
                var langStrings = translations[lang] || translations['en'] || fallbackTranslations[lang] || fallbackTranslations.en || {};
                pwaApp.querySelectorAll('[data-i18n]').forEach(function(el) {
                    var key = el.dataset.i18n;
                    if (langStrings[key]) {
                        el.textContent = langStrings[key];
                    }
                });

                // Translate tab labels
                var tabMap = {
                    'Overview': langStrings.overview,
                    'Leave': langStrings.leave,
                    'Loans': langStrings.loans,
                    'Resignation': langStrings.resignation,
                    'Attendance': langStrings.attendance
                };
                pwaApp.querySelectorAll('.sfs-hr-tab span').forEach(function(span) {
                    var original = span.dataset.original || span.textContent.trim();
                    span.dataset.original = original;
                    if (tabMap[original]) {
                        span.textContent = tabMap[original];
                    }
                });

                // Translate form labels and buttons
                translateFormElements(pwaApp, langStrings);
            }

            function translateFormElements(container, strings) {
                // Map of English text to translation keys
                var textMap = {
                    // Leave form
                    'Request new leave': 'request_new_leave',
                    'Leave type': 'leave_type',
                    'Select type': 'select_type',
                    'Start date': 'start_date',
                    'End date': 'end_date',
                    'Reason / note': 'reason_note',
                    'Supporting document': 'supporting_document',
                    'Submit leave request': 'submit_leave_request',
                    'NEXT APPROVED LEAVE': 'next_approved_leave',
                    'No upcoming leave.': 'no_upcoming_leave',
                    'Leave History': 'leave_history',
                    'Leave history': 'leave_history',
                    // Loan form
                    'Request new loan': 'request_new_loan',
                    'Loan Amount': 'loan_amount',
                    'Monthly Installment Amount': 'monthly_installment',
                    'Reason for Loan': 'reason_for_loan',
                    'Submit loan request': 'submit_loan_request',
                    'Loan history': 'loan_history',
                    // Profile sections
                    'Profile Completion': 'profile_completion',
                    'Contact Information': 'contact_information',
                    'Identification': 'identification',
                    'My Assets': 'my_assets',
                    'Employment': 'employment',
                    'Contact': 'contact',
                    'Payroll': 'payroll',
                    'Quick Links': 'quick_links',
                    // Profile fields
                    'Status': 'status',
                    'Gender': 'gender',
                    'Department': 'department',
                    'Position': 'position',
                    'Employee ID': 'employee_id',
                    'WP Username': 'wp_username',
                    'Hire date': 'hire_date',
                    'Hire Date': 'hire_date',
                    'Base salary': 'base_salary',
                    'Base Salary': 'base_salary',
                    'Email': 'email',
                    'Phone': 'phone',
                    'Emergency contact': 'emergency_contact',
                    'Emergency Contact': 'emergency_contact',
                    'National ID': 'national_id',
                    'National ID Expiry': 'national_id_expiry',
                    'Passport No.': 'passport_no',
                    'Passport Expiry': 'passport_expiry',
                    'Expiry': 'expiry',
                    'Passport': 'passport',
                    'My Documents': 'my_documents',
                    // Common
                    'Missing': 'missing',
                    'Clock In': 'clock_in',
                    'Clock Out': 'clock_out',
                    'Submit': 'submit',
                    'Cancel': 'cancel'
                };

                // Find and translate labels, headings, buttons, and specific elements
                // Store original key in data-i18n-key attribute for reliable translation switching
                container.querySelectorAll('label, h3, h4, h5, button[type="submit"], .sfs-hr-profile-group-title, .sfs-hr-field-label, .sfs-hr-kpi-label, .sfs-hr-lw-kpi-label, .sfs-hr-clock-btn, .sfs-hr-lf-label, .sfs-hr-lf-hint, .sfs-hr-resign-label, .sfs-hr-resign-hint, .sfs-hr-loan-field-label').forEach(function(el) {
                    // Get the translation key from stored attribute or lookup from textMap
                    var key = el.dataset.i18nKey;
                    if (!key) {
                        var text = el.childNodes[0]?.textContent?.trim() || el.textContent.trim();
                        key = textMap[text];
                        if (key) {
                            el.dataset.i18nKey = key; // Store for future translations
                        }
                    }
                    if (key && strings[key]) {
                        if (el.childNodes[0]?.nodeType === 3) {
                            el.childNodes[0].textContent = strings[key] + ' ';
                        } else if (!el.querySelector('*')) {
                            el.textContent = strings[key];
                        }
                    }
                });

                // Translate profile group titles and field labels directly
                container.querySelectorAll('.sfs-hr-profile-group-title, .sfs-hr-field-label').forEach(function(el) {
                    // Get the translation key from stored attribute or lookup from textMap
                    var key = el.dataset.i18nKey;
                    if (!key) {
                        var text = el.textContent.trim();
                        key = textMap[text];
                        if (key) {
                            el.dataset.i18nKey = key; // Store for future translations
                        }
                    }
                    if (key && strings[key]) {
                        el.textContent = strings[key];
                    }
                });

                // Translate ALL elements with data-i18n-key attribute (added via PHP)
                container.querySelectorAll('[data-i18n-key]').forEach(function(el) {
                    var key = el.dataset.i18nKey;
                    if (key && strings[key]) {
                        // Handle elements with child nodes carefully
                        if (!el.querySelector('*') || el.classList.contains('sfs-hr-missing-field')) {
                            el.textContent = strings[key];
                        }
                    }
                });
            }
        }
    })();
    </script>

    <?php

    return (string) ob_get_clean();
}




/**
 * Frontend "My Assets" block – read-only, with clickable asset names (to admin).
 * If the employee has no assets, nothing is rendered.
 */
private function render_my_assets_frontend( int $employee_id ): void {
    global $wpdb;

    $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $asset_table  = $wpdb->prefix . 'sfs_hr_assets';

    // Load assignments; if tables don't exist or there are no rows, this will be empty.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT 
                a.*,
                ast.id         AS asset_id,
                ast.name       AS asset_name,
                ast.asset_code AS asset_code,
                ast.category   AS asset_category
            FROM {$assign_table} a
            LEFT JOIN {$asset_table} ast ON ast.id = a.asset_id
            WHERE a.employee_id = %d
            ORDER BY a.created_at DESC
            LIMIT 50
            ",
            $employee_id
        ),
        ARRAY_A
    );

    // No assets → hide block completely.
    if ( empty( $rows ) ) {
        return;
    }

    echo '<div class="sfs-hr-my-assets-frontend" style="margin-top:24px;">';
    echo '<h4>' . esc_html__( 'My Assets', 'sfs-hr' ) . '</h4>';

    echo '<table class="sfs-hr-table sfs-hr-assets-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__( 'Asset', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Code', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Start', 'sfs-hr' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $rows as $row ) {
        $asset_label = trim( (string) ( $row['asset_name'] ?? '' ) );
        if ( ! empty( $row['asset_code'] ) ) {
            $asset_code  = (string) $row['asset_code'];
            $asset_label = $asset_label !== ''
                ? $asset_label . ' (' . $asset_code . ')'
                : $asset_code;
        }

        // Make asset name clickable → admin asset edit screen.
        $asset_cell = esc_html( $asset_label );
        if ( ! empty( $row['asset_id'] ) ) {
            $edit_url = add_query_arg(
                [
                    'page' => 'sfs-hr-assets',
                    'tab'  => 'assets',
                    'view' => 'edit',
                    'id'   => (int) $row['asset_id'],
                ],
                admin_url( 'admin.php' )
            );
            $asset_cell = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $asset_label ) . '</a>';
        }

        echo '<tr>';
        echo '<td>' . $asset_cell . '</td>';
        echo '<td>' . esc_html( $row['asset_code'] ?? '' ) . '</td>';
        echo '<td>' . (
                method_exists( \SFS\HR\Core\Helpers::class, 'asset_status_badge' )
                    ? \SFS\HR\Core\Helpers::asset_status_badge( (string) ( $row['status'] ?? '' ) )
                    : esc_html( (string) ( $row['status'] ?? '' ) )
            ) . '</td>';
        echo '<td>' . esc_html( $row['start_date'] ?: '' ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}



/**
 * Frontend "My Attendance" block – current month summary + daily history.
 */
private function render_my_attendance_frontend( int $employee_id ): void {
    global $wpdb;

    $sess_table  = $wpdb->prefix . 'sfs_hr_attendance_sessions';
    $punch_table = $wpdb->prefix . 'sfs_hr_attendance_punches';

    // Require login + valid employee id.
    if ( ! is_user_logged_in() || $employee_id <= 0 ) {
        echo '<div id="sfs-hr-my-attendance" class="sfs-hr-my-attendance-frontend" style="margin-top:24px;">';
        echo '<h4>' . esc_html__( 'My Attendance', 'sfs-hr' ) . '</h4>';
        echo '<p class="description">' . esc_html__( 'You must be logged in to see your attendance.', 'sfs-hr' ) . '</p>';
        echo '</div>';
        return;
    }

    // ---------- Helpers ----------

    $status_label_fn = static function ( string $st ): string {
        $st = trim( $st );
        if ( $st === '' ) {
            return __( 'No record', 'sfs-hr' );
        }
        switch ( $st ) {
            case 'present':
                return __( 'Present', 'sfs-hr' );
            case 'late':
                return __( 'Late', 'sfs-hr' );
            case 'left_early':
                return __( 'Left early', 'sfs-hr' );
            case 'incomplete':
                return __( 'Incomplete', 'sfs-hr' );
            case 'on_leave':
                return __( 'On leave', 'sfs-hr' );
            case 'not_clocked_in':
                return __( 'Not Clocked-IN', 'sfs-hr' );
            case 'absent':
                return __( 'Absent', 'sfs-hr' );
            default:
                return ucfirst( str_replace( '_', ' ', $st ) );
        }
    };

    // Chip with color per status.
    $status_chip_fn = static function ( string $st ) use ( $status_label_fn ): string {
        $label = $status_label_fn( $st );
        if ( $label === '' ) {
            return '';
        }

        $classes = 'sfs-hr-status-chip';

        switch ( $st ) {
            case 'present':
                $classes .= ' sfs-hr-status-chip--present';
                break;
            case 'late':
                $classes .= ' sfs-hr-status-chip--late';
                break;
            case 'absent':
            case 'not_clocked_in':
                $classes .= ' sfs-hr-status-chip--absent';
                break;
            case 'incomplete':
                $classes .= ' sfs-hr-status-chip--incomplete';
                break;
            case 'on_leave':
                $classes .= ' sfs-hr-status-chip--on-leave';
                break;
            default:
                $classes .= ' sfs-hr-status-chip--neutral';
                break;
        }

        return '<span class="' . esc_attr( $classes ) . '" data-i18n-key="' . esc_attr( $st ) . '">' . esc_html( $label ) . '</span>';
    };

    // MySQL datetime (stored UTC) -> local time "6:08 am"
    $format_time_local = static function ( ?string $mysql ): string {
        $mysql = (string) $mysql;
        if ( $mysql === '' || $mysql === '0000-00-00 00:00:00' ) {
            return '';
        }
        $ts_utc = strtotime( $mysql . ' UTC' );
        if ( ! $ts_utc ) {
            return '';
        }
        return wp_date( 'g:i a', $ts_utc );
    };

    // ---------- Today line (with optional break info) ----------

    $today = wp_date( 'Y-m-d' );

    $today_row = $wpdb->get_row(
        $wpdb->prepare(
            "
            SELECT status, in_time, out_time
            FROM {$sess_table}
            WHERE employee_id = %d
              AND work_date   = %s
            ORDER BY id DESC
            LIMIT 1
            ",
            $employee_id,
            $today
        ),
        ARRAY_A
    );

    $today_status_key   = isset( $today_row['status'] ) ? (string) $today_row['status'] : '';
    $today_status_label = $status_label_fn( $today_status_key );

    $today_in_label  = ! empty( $today_row['in_time'] )
        ? $format_time_local( (string) $today_row['in_time'] )
        : '';
    $today_out_label = ! empty( $today_row['out_time'] )
        ? $format_time_local( (string) $today_row['out_time'] )
        : '';

    // Try to derive break start / end from punches table (if the table exists and structure matches).
    $break_start_label = '';
    $break_end_label   = '';

    $table_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $punch_table )
    );

    if ( $table_exists === $punch_table ) {
        $break_rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT punch_type, punch_time
                FROM {$punch_table}
                WHERE employee_id = %d
                  AND DATE(punch_time) = %s
                  AND punch_type IN ('break_start','break_end')
                ORDER BY punch_time ASC
                ",
                $employee_id,
                $today
            ),
            ARRAY_A
        );

        if ( ! empty( $break_rows ) ) {
            foreach ( $break_rows as $br ) {
                $type = (string) ( $br['punch_type'] ?? '' );
                $time = (string) ( $br['punch_time'] ?? '' );
                if ( $type === 'break_start' ) {
                    $break_start_label = $format_time_local( $time );
                } elseif ( $type === 'break_end' ) {
                    $break_end_label = $format_time_local( $time );
                }
            }
        }
    }

    // Build the human line.
    $today_parts = [];

    if ( $today_in_label !== '' ) {
        $today_parts[] = sprintf(
            /* translators: 1: time */
            __( 'Clocked in at %s', 'sfs-hr' ),
            $today_in_label
        );
    }
    if ( $today_out_label !== '' ) {
        $today_parts[] = sprintf(
            /* translators: 1: time */
            __( 'Clocked out at %s', 'sfs-hr' ),
            $today_out_label
        );
    }
    if ( $break_start_label !== '' ) {
        $today_parts[] = sprintf(
            /* translators: 1: time */
            __( 'Break start: %s', 'sfs-hr' ),
            $break_start_label
        );
    }
    if ( $break_end_label !== '' ) {
        $today_parts[] = sprintf(
            /* translators: 1: time */
            __( 'Break end: %s', 'sfs-hr' ),
            $break_end_label
        );
    }

    $today_line = '';
    if ( ! empty( $today_parts ) ) {
        $today_line = implode( ' · ', $today_parts );
        if ( $today_status_key !== '' ) {
            $today_line .= ' (' . $today_status_label . ')';
        }
    } elseif ( $today_status_key !== '' ) {
        $today_line = $today_status_label;
    } else {
        $today_line = __( 'No record', 'sfs-hr' );
    }

    // ---------- Current month history ----------

    $att_period  = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
    $month_start = $att_period['start'];
    $month_end   = $att_period['end'];

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT work_date, status, in_time, out_time
            FROM {$sess_table}
            WHERE employee_id = %d
              AND work_date BETWEEN %s AND %s
            ORDER BY work_date DESC
            ",
            $employee_id,
            $month_start,
            $month_end
        ),
        ARRAY_A
    );

    // Status counts (current month).
    $status_counts = [];
    foreach ( $rows as $row ) {
        $st = trim( (string) ( $row['status'] ?? '' ) );
        if ( $st === '' ) {
            continue;
        }
        if ( ! isset( $status_counts[ $st ] ) ) {
            $status_counts[ $st ] = 0;
        }
        $status_counts[ $st ]++;
    }
    $total_days = array_sum( $status_counts );

    // ---------- Output ----------

    echo '<div id="sfs-hr-my-attendance" class="sfs-hr-my-attendance-frontend" style="margin-top:24px;">';
    echo '<h4 data-i18n-key="my_attendance">' . esc_html__( 'My Attendance', 'sfs-hr' ) . '</h4>';

    echo '<p><strong data-i18n-key="today">' . esc_html__( 'Today', 'sfs-hr' ) . ':</strong> <span data-i18n-key="no_record">' . esc_html( $today_line ) . '</span></p>';

    if ( empty( $rows ) ) {
        echo '<p class="description" data-i18n-key="no_attendance_records">' . esc_html__( 'No attendance records for the last days.', 'sfs-hr' ) . '</p>';
        echo '</div>';
        return;
    }

    echo '<div class="sfs-hr-att-grid">';

    // ---- Status counts card ----
    echo '<div class="sfs-hr-att-card">';
    echo '<h5 data-i18n-key="status_counts">' . esc_html__( 'Status counts', 'sfs-hr' ) . '</h5>';

    echo '<table class="sfs-hr-table sfs-hr-att-status-table">';
    echo '<thead><tr>';
    echo '<th data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '<th data-i18n-key="days">' . esc_html__( 'Days', 'sfs-hr' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $status_counts as $st_key => $count ) {
        echo '<tr>';
        echo '<td>' . $status_chip_fn( (string) $st_key ) . '</td>';
        echo '<td>' . (int) $count . '</td>';
        echo '</tr>';
    }

    echo '<tr class="sfs-hr-att-total-row">';
    echo '<td><strong data-i18n-key="total_days_with_records">' . esc_html__( 'Total days with records', 'sfs-hr' ) . '</strong></td>';
    echo '<td><strong>' . (int) $total_days . '</strong></td>';
    echo '</tr>';

    echo '</tbody></table>';
    echo '</div>'; // card 1

    // ---- Daily history card ----
    echo '<div class="sfs-hr-att-card">';
    echo '<h5 data-i18n-key="daily_history">' . esc_html__( 'Daily history', 'sfs-hr' ) . '</h5>';

    echo '<table class="sfs-hr-table sfs-hr-attendance-table">';
    echo '<thead><tr>';
    echo '<th data-i18n-key="date">' . esc_html__( 'Date', 'sfs-hr' ) . '</th>';
    echo '<th data-i18n-key="time_in">' . esc_html__( 'Time in', 'sfs-hr' ) . '</th>';
    echo '<th data-i18n-key="time_out">' . esc_html__( 'Time out', 'sfs-hr' ) . '</th>';
    echo '<th data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '</tr></thead><tbody>';

    $max_visible = 5;
    $has_more    = count( $rows ) > $max_visible;

    foreach ( $rows as $idx => $row ) {
        $date     = $row['work_date'] ?? '';
        $st       = (string) ( $row['status'] ?? '' );
        $time_in  = $format_time_local( $row['in_time']  ?? '' );
        $time_out = $format_time_local( $row['out_time'] ?? '' );
        if ( $time_out === '' ) {
            $time_out = '–';
        }

        $extra_attr = ( $has_more && $idx >= $max_visible )
            ? ' class="sfs-hr-att-extra" style="display:none;"'
            : '';

        echo "<tr{$extra_attr}>";
        echo '<td>' . esc_html( $date ) . '</td>';
        echo '<td>' . esc_html( $time_in ) . '</td>';
        echo '<td>' . esc_html( $time_out ) . '</td>';
        echo '<td>' . $status_chip_fn( $st ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    if ( $has_more ) {
        echo '<p class="sfs-hr-att-more-wrap">';
        echo '<button type="button" class="sfs-hr-show-more-days" data-i18n-key="show_more_days">';
        echo esc_html__( 'Show more days', 'sfs-hr' );
        echo '</button>';
        echo '</p>';
    }

    echo '</div>'; // card 2

    echo '</div>'; // grid
    echo '</div>'; // wrapper

    // ---------- CSS (cards + chips + button) ----------
    ?>
    <style>
    .sfs-hr-att-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 16px;
        margin-top: 12px;
    }
    .sfs-hr-att-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 12px 14px 14px;
    }
    .sfs-hr-att-card h5 {
        margin: 0 0 8px;
        font-size: 13px;
        font-weight: 600;
    }
    .sfs-hr-att-status-table th,
    .sfs-hr-att-status-table td,
    .sfs-hr-attendance-table th,
    .sfs-hr-attendance-table td {
        font-size: 12px;
    }
    .sfs-hr-att-total-row td {
        font-weight: 600;
    }

    /* Status chips */
    .sfs-hr-status-chip {
        display: inline-block;
        padding: 1px 8px;
        font-size: 11px;
        line-height: 1.4;
        border-radius: 999px;
        border: 1px solid #e5e7eb;
        background: #f3f4f6;
        color: #374151;
        white-space: nowrap;
    }
    .sfs-hr-status-chip--present {
        background: #ecfdf3;
        border-color: #bbf7d0;
        color: #166534;
    }
    .sfs-hr-status-chip--late,
    .sfs-hr-status-chip--absent {
        background: #fee2e2;
        border-color: #fecaca;
        color: #b91c1c;
    }
    .sfs-hr-status-chip--incomplete {
        background: #fef9c3;
        border-color: #fef08a;
        color: #92400e;
    }
    .sfs-hr-status-chip--on-leave {
        background: #e0f2fe;
        border-color: #bae6fd;
        color: #075985;
    }

    /* "Show more days" pill button */
    .sfs-hr-show-more-days {
        margin-top: 8px;
        padding: 6px 14px;
        font-size: 12px;
        border-radius: 999px;
        border: 1px solid #c4b5fd;
        background: #ede9fe;
        color: #4c1d95;
        cursor: pointer;
    }
    .sfs-hr-show-more-days:hover {
        background: #ddd6fe;
    }

    .sfs-hr-att-more-wrap {
        text-align: right;
    }
    </style>
    <script>
    (function () {
        if (window.sfsHrAttMoreInit) return;
        window.sfsHrAttMoreInit = true;

        document.addEventListener('click', function (e) {
            var btn = e.target;
            if (!btn.classList || !btn.classList.contains('sfs-hr-show-more-days')) return;

            e.preventDefault();
            var card  = btn.closest('.sfs-hr-att-card');
            if (!card) return;
            var rows  = card.querySelectorAll('.sfs-hr-att-extra');
            if (!rows.length) return;

            rows.forEach(function (tr) { tr.style.display = 'table-row'; });
            btn.style.display = 'none';
        });
    })();
    </script>
    <?php
}





    public function my_loans($atts = [], $content = ''): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="sfs-hr sfs-hr-alert">' . esc_html__('Please log in to view your loans.','sfs-hr') . '</div>';
        }
        $emp_id = Helpers::current_employee_id();
        if ( ! $emp_id ) {
            return '<div class="sfs-hr sfs-hr-alert">' . esc_html__('Your HR profile is not linked. Please contact HR.','sfs-hr') . '</div>';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_loans';
        $rows = $wpdb->get_results( $wpdb->prepare("SELECT id,status,principal_amount,outstanding_principal,installment_amount,start_period FROM {$table} WHERE employee_id=%d ORDER BY id DESC", $emp_id), ARRAY_A );
        ob_start(); ?>
        <div class="sfs-hr sfs-hr-loans">
          <h3><?php echo esc_html__('My Loans', 'sfs-hr'); ?></h3>
          <?php if (empty($rows)): ?>
            <p><?php echo esc_html__('No loans found.','sfs-hr'); ?></p>
          <?php else: ?>
          <table class="sfs-hr-table">
            <thead>
              <tr>
                <th><?php echo esc_html__('ID','sfs-hr'); ?></th>
                <th><?php echo esc_html__('Status','sfs-hr'); ?></th>
                <th><?php echo esc_html__('Principal','sfs-hr'); ?></th>
                <th><?php echo esc_html__('Outstanding','sfs-hr'); ?></th>
                <th><?php echo esc_html__('Monthly Deduction','sfs-hr'); ?></th>
                <th><?php echo esc_html__('Start Period','sfs-hr'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo esc_html( (string)$r['id'] ); ?></td>
                  <td><?php echo esc_html( $r['status'] ); ?></td>
                  <td><?php echo esc_html( number_format_i18n( (float)$r['principal_amount'], 2 ) ); ?></td>
                  <td><?php echo esc_html( number_format_i18n( (float)$r['outstanding_principal'], 2 ) ); ?></td>
                  <td><?php echo esc_html( number_format_i18n( (float)$r['installment_amount'], 2 ) ); ?></td>
                  <td><?php echo esc_html( (string)$r['start_period'] ); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
        <style>
        .sfs-hr-table { width:100%; border-collapse: collapse; }
        .sfs-hr-table th, .sfs-hr-table td { padding:8px 10px; border-bottom:1px solid #eee; text-align:start; }
        .sfs-hr-alert { padding:12px; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; }
        </style>
        <?php
        return (string) ob_get_clean();
    }
    public function leave_request(): string {
    if ( ! is_user_logged_in() ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__('Please log in to request leave.','sfs-hr') . '</div>';
    }

    // If you have a dedicated My Profile page, replace get_permalink()
    // with that page's URL or ID.
    $profile_url = get_permalink(); // <- adjust if needed

    $html  = '<div class="sfs-hr sfs-hr-alert">';
    $html .= esc_html__( 'Leave requests are now handled from your HR profile page.', 'sfs-hr' );
    $html .= '</div>';

    return $html;
}

    public function my_leaves(): string {
    if ( ! is_user_logged_in() ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__('Please log in to view your leaves.','sfs-hr') . '</div>';
    }

    $html  = '<div class="sfs-hr sfs-hr-alert">';
    $html .= esc_html__( 'Your leave history is available in your HR profile under the "Leave" tab.', 'sfs-hr' );
    $html .= '</div>';

    return $html;
}

    
    /**
 * Frontend "My Leave" tab:
 * - List all leave requests for the current employee (latest first).
 */
private function render_my_leave_frontend( int $employee_id ): void {
    global $wpdb;

    $req_table  = $wpdb->prefix . 'sfs_hr_leave_requests';
    $type_table = $wpdb->prefix . 'sfs_hr_leave_types';

    // Fetch leave requests for this employee
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT r.*, t.name AS type_name
            FROM {$req_table} r
            LEFT JOIN {$type_table} t ON t.id = r.type_id
            WHERE r.employee_id = %d
            ORDER BY r.start_date DESC
            LIMIT 100
            ",
            $employee_id
        )
    );

    echo '<div class="sfs-hr sfs-hr-my-leave-frontend" style="margin-top:16px;">';
    echo '<h3>' . esc_html__( 'My Leave', 'sfs-hr' ) . '</h3>';

    if ( empty( $rows ) ) {
        echo '<p>' . esc_html__( 'You have no leave requests yet.', 'sfs-hr' ) . '</p>';
        echo '</div>';
        return;
    }

    echo '<table class="sfs-hr-table sfs-hr-leave-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Period', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Requested at', 'sfs-hr' ) . '</th>';
    echo '</tr></thead><tbody>';

    // Normalize rows once so we can reuse for desktop + mobile
$display_rows = [];

foreach ( $rows as $row ) {
    $type_name = $row->type_name ?: __( 'N/A', 'sfs-hr' );

    $start  = $row->start_date ?: '';
    $end    = $row->end_date   ?: '';
    $period = $start;
    if ( $start && $end && $end !== $start ) {
        $period = $start . ' → ' . $end;
    }

    // Days: never show 0 for a valid period
    $days = isset( $row->days ) ? (int) $row->days : 0;
    if ( $days <= 0 && $start !== '' ) {
        if ( $end === '' || $end === $start ) {
            $days = 1;
        } else {
            $start_ts = strtotime( $start );
            $end_ts   = strtotime( $end );
            if ( $start_ts && $end_ts && $end_ts >= $start_ts ) {
                $days = (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;
            }
        }
    }

    // Status string: keep "pending_manager"/"pending_hr" for consistency
    $status_string = (string) $row->status;
    if ( $status_string === 'pending' ) {
        $level         = isset( $row->approval_level ) ? (int) $row->approval_level : 1;
        $status_string = ( $level <= 1 ) ? 'pending_manager' : 'pending_hr';
    }

    // Status badge: prefer Leave_UI::leave_status_chip( $row ), fallback to string
    $status_html = '';
    if ( method_exists( \SFS\HR\Modules\Leave\Leave_UI::class, 'leave_status_chip' ) ) {
        try {
            $status_html = \SFS\HR\Modules\Leave\Leave_UI::leave_status_chip( $row );
        } catch ( \Throwable $e ) {
            $status_html = \SFS\HR\Modules\Leave\Leave_UI::leave_status_chip( $status_string );
        }
    } else {
        $status_label = ucfirst( str_replace( '_', ' ', $status_string ) );
        $status_html  = '<span class="sfs-hr-badge sfs-hr-leave-status sfs-hr-leave-status-'
                      . esc_attr( $status_string ) . '">'
                      . esc_html( $status_label )
                      . '</span>';
    }

    // Sick-leave document link
    $doc_html = '';
    $doc_id   = isset( $row->doc_attachment_id ) ? (int) $row->doc_attachment_id : 0;
    if ( $doc_id > 0 && stripos( $type_name, 'sick' ) !== false ) {
        $doc_url = wp_get_attachment_url( $doc_id );
        if ( $doc_url ) {
            $doc_html = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url( $doc_url ),
                esc_html__( 'View document', 'sfs-hr' )
            );
        }
    }

    $created_at = $row->created_at ?? '';

    $display_rows[] = [
        'type_name'   => $type_name,
        'period'      => $period,
        'days'        => $days,
        'status_key'  => $status_string,
        'status_html' => $status_html,
        'created_at'  => $created_at,
        'doc_html'    => $doc_html,
    ];
}

// ===== Desktop table =====
echo '<div class="sfs-hr-leaves-desktop">';
echo '<table class="sfs-hr-table sfs-hr-leave-table" style="margin-top:8px;">';
echo '<thead><tr>';
echo '<th>' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Period', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Days', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Document', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Requested at', 'sfs-hr' ) . '</th>';
echo '</tr></thead><tbody>';

foreach ( $display_rows as $r ) {
    echo '<tr>';
    echo '<td>' . esc_html( $r['type_name'] ) . '</td>';
    echo '<td>' . esc_html( $r['period'] ) . '</td>';
    echo '<td>' . esc_html( (string) $r['days'] ) . '</td>';
    echo '<td>' . $r['status_html'] . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

    echo '<td>';
    if ( ! empty( $r['doc_html'] ) ) {
        echo $r['doc_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } else {
        echo '&mdash;';
    }
    echo '</td>';

    echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>'; // .sfs-hr-leaves-desktop

// ===== Mobile cards =====
echo '<div class="sfs-hr-leaves-mobile">';
foreach ( $display_rows as $r ) {
    echo '<details class="sfs-hr-leave-card">';
    echo '  <summary class="sfs-hr-leave-summary">';
    echo '      <span class="sfs-hr-leave-summary-title">' . esc_html( $r['type_name'] ) . '</span>';
    echo '      <span class="sfs-hr-leave-summary-status">';
    echo            $r['status_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '      </span>';
    echo '  </summary>';

    echo '  <div class="sfs-hr-leave-body">';

    echo '      <div class="sfs-hr-leave-field-row">';
    echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Period', 'sfs-hr' ) . '</div>';
    echo '          <div class="sfs-hr-leave-field-value">' . esc_html( $r['period'] ) . '</div>';
    echo '      </div>';

    echo '      <div class="sfs-hr-leave-field-row">';
    echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Days', 'sfs-hr' ) . '</div>';
    echo '          <div class="sfs-hr-leave-field-value">' . esc_html( (string) $r['days'] ) . '</div>';
    echo '      </div>';

    if ( ! empty( $r['doc_html'] ) ) {
        echo '      <div class="sfs-hr-leave-field-row">';
        echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Document', 'sfs-hr' ) . '</div>';
        echo '          <div class="sfs-hr-leave-field-value">';
        echo                $r['doc_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '          </div>';
        echo '      </div>';
    }

    echo '      <div class="sfs-hr-leave-field-row">';
    echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Requested at', 'sfs-hr' ) . '</div>';
    echo '          <div class="sfs-hr-leave-field-value">' . esc_html( $r['created_at'] ) . '</div>';
    echo '      </div>';

    echo '  </div>';
    echo '</details>';
}
echo '</div>'; // .sfs-hr-leaves-mobile

}

/**
 * Render Documents tab for frontend My HR Profile
 */
private function render_frontend_documents_tab( int $emp_id ): void {
    // Check if Documents module is available
    if ( ! class_exists( '\SFS\HR\Modules\Documents\Services\Documents_Service' ) ) {
        echo '<div class="sfs-hr-alert" style="margin-top:20px;">';
        echo esc_html__( 'Documents module is not available.', 'sfs-hr' );
        echo '</div>';
        return;
    }

    $documents_service = '\SFS\HR\Modules\Documents\Services\Documents_Service';

    // Get grouped documents
    $grouped = $documents_service::get_documents_grouped( $emp_id );
    $document_types = $documents_service::get_document_types();
    $uploadable_types = $documents_service::get_uploadable_document_types_for_employee( $emp_id );

    // Check for missing required documents
    $missing_docs = $documents_service::get_missing_required_documents( $emp_id );

    ?>
    <div class="sfs-hr-documents-tab" style="margin-top:20px;">

        <?php if ( ! empty( $missing_docs ) ) : ?>
            <!-- Missing Documents Alert -->
            <div class="sfs-hr-missing-docs-alert" style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:15px;margin-bottom:20px;">
                <div style="display:flex;align-items:flex-start;gap:12px;">
                    <span style="color:#dc2626;font-size:20px;">⚠️</span>
                    <div style="flex:1;">
                        <strong style="color:#991b1b;display:block;margin-bottom:8px;"><?php esc_html_e( 'Missing Required Documents', 'sfs-hr' ); ?></strong>
                        <p style="color:#7f1d1d;margin:0 0 10px;font-size:14px;">
                            <?php esc_html_e( 'Please upload the following required documents:', 'sfs-hr' ); ?>
                        </p>
                        <ul style="margin:0;padding-left:20px;color:#991b1b;">
                            <?php foreach ( $missing_docs as $type_key => $type_label ) : ?>
                                <li><?php echo esc_html( $type_label ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Upload Form (for uploadable types only) -->
        <?php if ( ! empty( $uploadable_types ) ) : ?>
            <div class="sfs-hr-profile-group" style="margin-bottom:20px;">
                <div class="sfs-hr-profile-group-title"><?php esc_html_e( 'Upload Document', 'sfs-hr' ); ?></div>
                <div class="sfs-hr-profile-group-body">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="sfs_hr_upload_document" />
                        <input type="hidden" name="employee_id" value="<?php echo (int)$emp_id; ?>" />
                        <input type="hidden" name="redirect_page" value="sfs-hr-my-profile" />
                        <?php wp_nonce_field( 'sfs_hr_upload_document_' . $emp_id, '_wpnonce' ); ?>

                        <div class="sfs-hr-field-row" style="padding:12px 0;">
                            <div class="sfs-hr-field-label"><?php esc_html_e( 'Document Type', 'sfs-hr' ); ?></div>
                            <div class="sfs-hr-field-value">
                                <select name="document_type" required style="width:100%;max-width:300px;padding:8px;border:1px solid var(--sfs-border);border-radius:6px;background:var(--sfs-surface);color:var(--sfs-text);">
                                    <option value=""><?php esc_html_e( '— Select Type —', 'sfs-hr' ); ?></option>
                                    <?php foreach ( $uploadable_types as $key => $info ) : ?>
                                        <?php
                                        $label = $info['label'];
                                        $hint = '';
                                        if ( $info['reason'] === 'expired' ) {
                                            $hint = ' (' . __( 'expired - update required', 'sfs-hr' ) . ')';
                                        } elseif ( $info['reason'] === 'update_requested' ) {
                                            $hint = ' (' . __( 'update requested by HR', 'sfs-hr' ) . ')';
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label . $hint ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="font-size:12px;color:var(--sfs-text-muted);margin-top:4px;"><?php esc_html_e( 'Only document types that need to be added or updated are shown.', 'sfs-hr' ); ?></div>
                            </div>
                        </div>

                        <div class="sfs-hr-field-row" style="padding:12px 0;">
                            <div class="sfs-hr-field-label"><?php esc_html_e( 'Document Name', 'sfs-hr' ); ?></div>
                            <div class="sfs-hr-field-value">
                                <input type="text" name="document_name" required style="width:100%;max-width:300px;padding:8px;border:1px solid var(--sfs-border);border-radius:6px;background:var(--sfs-surface);color:var(--sfs-text);" placeholder="<?php esc_attr_e( 'e.g., National ID Copy', 'sfs-hr' ); ?>" />
                            </div>
                        </div>

                        <div class="sfs-hr-field-row" style="padding:12px 0;">
                            <div class="sfs-hr-field-label"><?php esc_html_e( 'File', 'sfs-hr' ); ?></div>
                            <div class="sfs-hr-field-value">
                                <input type="file" name="document_file" required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx" style="width:100%;max-width:300px;" />
                                <div style="font-size:12px;color:var(--sfs-text-muted);margin-top:4px;"><?php esc_html_e( 'Accepted: PDF, Images, Word, Excel (max 10MB)', 'sfs-hr' ); ?></div>
                            </div>
                        </div>

                        <div class="sfs-hr-field-row" style="padding:12px 0;">
                            <div class="sfs-hr-field-label"><?php esc_html_e( 'Expiry Date', 'sfs-hr' ); ?></div>
                            <div class="sfs-hr-field-value">
                                <input type="date" name="expiry_date" style="width:100%;max-width:200px;padding:8px;border:1px solid var(--sfs-border);border-radius:6px;background:var(--sfs-surface);color:var(--sfs-text);" />
                                <div style="font-size:12px;color:var(--sfs-text-muted);margin-top:4px;"><?php esc_html_e( 'Optional - for IDs, passports, licenses, etc.', 'sfs-hr' ); ?></div>
                            </div>
                        </div>

                        <div class="sfs-hr-field-row" style="padding:12px 0;">
                            <div class="sfs-hr-field-label"><?php esc_html_e( 'Notes', 'sfs-hr' ); ?></div>
                            <div class="sfs-hr-field-value">
                                <textarea name="description" rows="2" style="width:100%;max-width:300px;padding:8px;border:1px solid var(--sfs-border);border-radius:6px;background:var(--sfs-surface);color:var(--sfs-text);" placeholder="<?php esc_attr_e( 'Optional notes...', 'sfs-hr' ); ?>"></textarea>
                            </div>
                        </div>

                        <div style="padding:12px 0;">
                            <button type="submit" class="sfs-hr-att-btn" style="padding:10px 20px;border-radius:8px;border:none;cursor:pointer;"><?php esc_html_e( 'Upload Document', 'sfs-hr' ); ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else : ?>
            <div class="sfs-hr-profile-group" style="margin-bottom:20px;">
                <div class="sfs-hr-profile-group-body" style="text-align:center;padding:20px;">
                    <div style="font-size:14px;color:var(--sfs-text-muted);">
                        <?php esc_html_e( 'All documents are up to date. If you need to update a document, please contact HR.', 'sfs-hr' ); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Documents List -->
        <div class="sfs-hr-profile-group">
            <div class="sfs-hr-profile-group-title"><?php esc_html_e( 'My Documents', 'sfs-hr' ); ?></div>
            <div class="sfs-hr-profile-group-body">
                <?php if ( empty( $grouped ) ) : ?>
                    <div style="padding:20px;text-align:center;color:var(--sfs-text-muted);">
                        <?php esc_html_e( 'No documents uploaded yet.', 'sfs-hr' ); ?>
                    </div>
                <?php else : ?>
                    <?php foreach ( $document_types as $type_key => $type_label ) : ?>
                        <?php if ( ! empty( $grouped[ $type_key ] ) ) : ?>
                            <div style="margin-bottom:16px;">
                                <div style="font-weight:600;font-size:14px;color:var(--sfs-text);margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--sfs-border);">
                                    <?php echo esc_html( $type_label ); ?> (<?php echo count( $grouped[ $type_key ] ); ?>)
                                </div>

                                <?php foreach ( $grouped[ $type_key ] as $doc ) : ?>
                                    <?php
                                    $file_url = wp_get_attachment_url( $doc->attachment_id );
                                    $file_size = size_format( $doc->file_size, 1 );
                                    $expiry = $documents_service::get_expiry_status( $doc->expiry_date );
                                    $has_update_request = ! empty( $doc->update_requested_at );
                                    ?>
                                    <div style="display:flex;align-items:flex-start;gap:12px;padding:12px;background:var(--sfs-background);border-radius:8px;margin-bottom:8px;">
                                        <div style="flex:1;">
                                            <div style="font-weight:500;color:var(--sfs-text);margin-bottom:4px;">
                                                <?php echo esc_html( $doc->document_name ); ?>
                                                <?php if ( $expiry['label'] ) : ?>
                                                    <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:8px;background:<?php echo $expiry['class'] === 'expired' ? '#fee2e2' : ( $expiry['class'] === 'expiring-soon' ? '#fef3c7' : '#d1fae5' ); ?>;color:<?php echo $expiry['class'] === 'expired' ? '#dc2626' : ( $expiry['class'] === 'expiring-soon' ? '#d97706' : '#059669' ); ?>;">
                                                        <?php echo esc_html( $expiry['label'] ); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ( $has_update_request ) : ?>
                                                    <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:4px;background:#dbeafe;color:#1d4ed8;" title="<?php echo esc_attr( $doc->update_request_reason ?: __( 'Update requested by HR', 'sfs-hr' ) ); ?>">
                                                        <?php esc_html_e( 'Update Requested', 'sfs-hr' ); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size:12px;color:var(--sfs-text-muted);">
                                                <?php echo esc_html( $doc->file_name ); ?> · <?php echo esc_html( $file_size ); ?> · <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $doc->created_at ) ) ); ?>
                                            </div>
                                            <?php if ( $doc->description ) : ?>
                                                <div style="font-size:12px;color:var(--sfs-text-muted);margin-top:4px;font-style:italic;">
                                                    <?php echo esc_html( wp_trim_words( $doc->description, 15 ) ); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ( $has_update_request && $doc->update_request_reason ) : ?>
                                                <div style="font-size:12px;color:var(--sfs-text-muted);margin-top:4px;">
                                                    <strong><?php esc_html_e( 'Reason:', 'sfs-hr' ); ?></strong> <?php echo esc_html( $doc->update_request_reason ); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div style="flex-shrink:0;">
                                            <?php if ( $file_url ) : ?>
                                                <a href="<?php echo esc_url( $file_url ); ?>" download style="display:inline-block;padding:6px 12px;background:var(--sfs-primary);color:#fff;border-radius:6px;font-size:12px;text-decoration:none;">
                                                    <?php esc_html_e( 'Download', 'sfs-hr' ); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Render trainee profile with limited access (no leave, loans, resignation)
 */
private function render_trainee_profile( array $trainee ): string {
    global $wpdb;

    $dept_table = $wpdb->prefix . 'sfs_hr_departments';
    $dept_name = '';
    if ( ! empty( $trainee['dept_id'] ) ) {
        $dept_name = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$dept_table} WHERE id = %d",
            (int) $trainee['dept_id']
        ) );
    }

    // Supervisor name
    $supervisor_name = '';
    if ( ! empty( $trainee['supervisor_id'] ) ) {
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $supervisor = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$emp_table} WHERE id = %d",
            (int) $trainee['supervisor_id']
        ) );
        if ( $supervisor ) {
            $supervisor_name = trim( $supervisor->first_name . ' ' . $supervisor->last_name );
        }
    }

    // Core fields
    $first_name = (string) ( $trainee['first_name'] ?? '' );
    $last_name  = (string) ( $trainee['last_name']  ?? '' );
    $full_name  = trim( $first_name . ' ' . $last_name );
    $code       = (string) ( $trainee['trainee_code'] ?? '' );
    $email      = (string) ( $trainee['email'] ?? '' );
    $phone      = (string) ( $trainee['phone'] ?? '' );
    $position   = (string) ( $trainee['position'] ?? '' );
    $gender     = (string) ( $trainee['gender'] ?? '' );
    $training_start = (string) ( $trainee['training_start'] ?? '' );
    $training_end   = (string) ( $trainee['training_end'] ?? '' );
    $university = (string) ( $trainee['university'] ?? '' );
    $major      = (string) ( $trainee['major'] ?? '' );
    $status     = (string) ( $trainee['status'] ?? '' );

    // Active tab - only overview and attendance available for trainees
    $active_tab = isset( $_GET['sfs_hr_tab'] ) ? sanitize_key( $_GET['sfs_hr_tab'] ) : 'overview';
    if ( ! in_array( $active_tab, [ 'overview', 'attendance' ], true ) ) {
        $active_tab = 'overview';
    }

    $base_url       = remove_query_arg( 'sfs_hr_tab' );
    $overview_url   = add_query_arg( 'sfs_hr_tab', 'overview', $base_url );
    $attendance_url = add_query_arg( 'sfs_hr_tab', 'attendance', $base_url );

    // Check if self-clock is enabled
    $shift_assigns_table = $wpdb->prefix . 'sfs_hr_shift_assignments';
    $can_self_clock = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT allow_self_clock FROM {$shift_assigns_table}
         WHERE user_id = %d AND status = 'active' LIMIT 1",
        (int) $trainee['user_id']
    ) );

    ob_start();
    ?>
    <div class="sfs-hr sfs-hr-my-profile sfs-hr-pwa-app" id="sfs-hr-pwa-app">
        <style>
            :root {
                --sfs-primary: #0f4c5c;
                --sfs-primary-light: #1a6b7f;
                --sfs-bg: #f8fafc;
                --sfs-card-bg: #ffffff;
                --sfs-text: #1e293b;
                --sfs-text-muted: #64748b;
                --sfs-border: #e2e8f0;
            }
            @media (prefers-color-scheme: dark) {
                :root:not([data-theme="light"]) {
                    --sfs-bg: #0f172a;
                    --sfs-card-bg: #1e293b;
                    --sfs-text: #f1f5f9;
                    --sfs-text-muted: #94a3b8;
                    --sfs-border: #334155;
                }
            }
            [data-theme="dark"] {
                --sfs-bg: #0f172a;
                --sfs-card-bg: #1e293b;
                --sfs-text: #f1f5f9;
                --sfs-text-muted: #94a3b8;
                --sfs-border: #334155;
            }
            .sfs-hr-my-profile { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
            .sfs-hr-profile-container { max-width: 800px; margin: 0 auto; background: var(--sfs-bg); padding: 24px; border-radius: 16px; }
            .sfs-hr-profile-header { display: flex; align-items: center; gap: 20px; margin-bottom: 24px; }
            .sfs-hr-profile-photo { width: 80px; height: 80px; background: linear-gradient(135deg, var(--sfs-primary), var(--sfs-primary-light)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 32px; font-weight: 600; }
            .sfs-hr-profile-info h2 { margin: 0 0 4px; color: var(--sfs-text); font-size: 22px; }
            .sfs-hr-profile-info p { margin: 0; color: var(--sfs-text-muted); font-size: 14px; }
            .sfs-hr-profile-tabs { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
            .sfs-hr-tab { display: flex; align-items: center; gap: 6px; padding: 10px 16px; background: var(--sfs-card-bg); border: 1px solid var(--sfs-border); border-radius: 10px; text-decoration: none; color: var(--sfs-text-muted); font-size: 14px; font-weight: 500; transition: all 0.2s; }
            .sfs-hr-tab:hover { border-color: var(--sfs-primary); color: var(--sfs-primary); }
            .sfs-hr-tab-active { background: var(--sfs-primary); border-color: var(--sfs-primary); color: #fff !important; }
            .sfs-hr-tab-icon { width: 18px; height: 18px; }
            .sfs-hr-profile-card { background: var(--sfs-card-bg); border: 1px solid var(--sfs-border); border-radius: 12px; padding: 20px; margin-bottom: 16px; }
            .sfs-hr-profile-card h3 { margin: 0 0 16px; color: var(--sfs-text); font-size: 16px; font-weight: 600; }
            .sfs-hr-profile-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--sfs-border); }
            .sfs-hr-profile-row:last-child { border-bottom: none; }
            .sfs-hr-profile-row label { color: var(--sfs-text-muted); font-size: 14px; }
            .sfs-hr-profile-row span { color: var(--sfs-text); font-size: 14px; font-weight: 500; }
            .sfs-hr-trainee-badge { display: inline-block; padding: 4px 12px; background: #fef3c7; color: #92400e; border-radius: 20px; font-size: 12px; font-weight: 600; margin-left: 8px; }
            .sfs-hr-trainee-notice { background: #fef3c7; color: #92400e; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
            @media (max-width: 600px) {
                .sfs-hr-profile-container { padding: 16px; }
                .sfs-hr-profile-header { flex-direction: column; text-align: center; }
                .sfs-hr-profile-row { flex-direction: column; gap: 4px; }
                .sfs-hr-profile-row label { font-size: 12px; }
            }
        </style>

        <div class="sfs-hr-profile-container">
            <div class="sfs-hr-trainee-notice">
                <strong><?php esc_html_e( 'Trainee Account', 'sfs-hr' ); ?>:</strong>
                <?php esc_html_e( 'You are logged in as a trainee student. Some features are limited.', 'sfs-hr' ); ?>
            </div>

            <div class="sfs-hr-profile-tabs">
                <a href="<?php echo esc_url( $overview_url ); ?>"
                   class="sfs-hr-tab <?php echo ( $active_tab === 'overview' ) ? 'sfs-hr-tab-active' : ''; ?>">
                    <svg class="sfs-hr-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span><?php esc_html_e( 'Overview', 'sfs-hr' ); ?></span>
                </a>
                <?php if ( $can_self_clock ) : ?>
                    <a href="<?php echo esc_url( $attendance_url ); ?>"
                       class="sfs-hr-tab <?php echo ( $active_tab === 'attendance' ) ? 'sfs-hr-tab-active' : ''; ?>">
                        <svg class="sfs-hr-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span><?php esc_html_e( 'Attendance', 'sfs-hr' ); ?></span>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ( $active_tab === 'attendance' && $can_self_clock ) : ?>
                <div class="sfs-hr-profile-attendance-tab">
                    <?php echo do_shortcode( '[sfs_hr_attendance_widget immersive="0"]' ); ?>
                </div>
            <?php else : ?>
                <div class="sfs-hr-profile-header">
                    <div class="sfs-hr-profile-photo">
                        <?php echo esc_html( strtoupper( substr( $first_name, 0, 1 ) . substr( $last_name, 0, 1 ) ) ); ?>
                    </div>
                    <div class="sfs-hr-profile-info">
                        <h2><?php echo esc_html( $full_name ); ?> <span class="sfs-hr-trainee-badge"><?php esc_html_e( 'Trainee', 'sfs-hr' ); ?></span></h2>
                        <p><?php echo esc_html( $code ); ?><?php if ( $position ) : ?> • <?php echo esc_html( $position ); ?><?php endif; ?></p>
                    </div>
                </div>

                <div class="sfs-hr-profile-card">
                    <h3><?php esc_html_e( 'Personal Information', 'sfs-hr' ); ?></h3>
                    <div class="sfs-hr-profile-row">
                        <label><?php esc_html_e( 'Full Name', 'sfs-hr' ); ?></label>
                        <span><?php echo esc_html( $full_name ); ?></span>
                    </div>
                    <?php if ( $email ) : ?>
                        <div class="sfs-hr-profile-row">
                            <label><?php esc_html_e( 'Email', 'sfs-hr' ); ?></label>
                            <span><?php echo esc_html( $email ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( $phone ) : ?>
                        <div class="sfs-hr-profile-row">
                            <label><?php esc_html_e( 'Phone', 'sfs-hr' ); ?></label>
                            <span><?php echo esc_html( $phone ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( $gender ) : ?>
                        <div class="sfs-hr-profile-row">
                            <label><?php esc_html_e( 'Gender', 'sfs-hr' ); ?></label>
                            <span><?php echo esc_html( ucfirst( $gender ) ); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="sfs-hr-profile-card">
                    <h3><?php esc_html_e( 'Training Information', 'sfs-hr' ); ?></h3>
                    <?php if ( $dept_name ) : ?>
                        <div class="sfs-hr-profile-row">
                            <label><?php esc_html_e( 'Department', 'sfs-hr' ); ?></label>
                            <span><?php echo esc_html( $dept_name ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( $position ) : ?>
                        <div class="sfs-hr-profile-row">
                            <label><?php esc_html_e( 'Position', 'sfs-hr' ); ?></label>
                            <span><?php echo esc_html( $position ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( $supervisor_name ) : ?>
                        <div class="sfs-hr-profile-row">
                            <label><?php esc_html_e( 'Supervisor', 'sfs-hr' ); ?></label>
                            <span><?php echo esc_html( $supervisor_name ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( $training_start ) : ?>
                        <div class="sfs-hr-profile-row">
                            <label><?php esc_html_e( 'Training Start', 'sfs-hr' ); ?></label>
                            <span><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $training_start ) ) ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( $training_end ) : ?>
                        <div class="sfs-hr-profile-row">
                            <label><?php esc_html_e( 'Training End', 'sfs-hr' ); ?></label>
                            <span><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $training_end ) ) ); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( $university || $major ) : ?>
                    <div class="sfs-hr-profile-card">
                        <h3><?php esc_html_e( 'Education', 'sfs-hr' ); ?></h3>
                        <?php if ( $university ) : ?>
                            <div class="sfs-hr-profile-row">
                                <label><?php esc_html_e( 'University', 'sfs-hr' ); ?></label>
                                <span><?php echo esc_html( $university ); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ( $major ) : ?>
                            <div class="sfs-hr-profile-row">
                                <label><?php esc_html_e( 'Major', 'sfs-hr' ); ?></label>
                                <span><?php echo esc_html( $major ); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}


}
