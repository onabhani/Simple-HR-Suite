<?php
namespace SFS\HR\Modules\Employees\Admin;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * My_Profile_Page
 * Employee self-service profile (tabs: overview, assets, leave, ...)
 * Version: 0.1.0-my-profile-v1
 * Author: hdqah.com
 */
class My_Profile_Page {

    public function hooks(): void {
        // Hidden page; you can later add a visible menu item if you want.
        add_action( 'admin_menu', [ $this, 'menu' ], 45 );
    }

    public function menu(): void {
        add_submenu_page(
            null,
            __( 'My Profile', 'sfs-hr' ),
            __( 'My Profile', 'sfs-hr' ),
            'read',                         // any logged-in user
            'sfs-hr-my-profile',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'You must be logged in to view this page.', 'sfs-hr' ) );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_die( esc_html__( 'Invalid user.', 'sfs-hr' ) );
        }

        // Output CSS for asset and leave status badges once
        Helpers::output_asset_status_badge_css();

        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        // Link WP user -> employee row
        $employee = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$emp_table} WHERE user_id = %d LIMIT 1",
                $user_id
            )
        );

        if ( ! $employee ) {
            wp_die( esc_html__( 'You are not linked to an employee record.', 'sfs-hr' ) );
        }

        // Active tab (default: overview)
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';

        echo '<div class="wrap sfs-hr-wrap sfs-hr-my-profile-wrap">';
echo '<h1 class="wp-heading-inline">' . esc_html__( 'My Profile', 'sfs-hr' ) . '</h1>';

// Add mobile-specific CSS for all forms in My Profile tabs
echo '<style>
    @media screen and (max-width: 782px) {
        /* Leave form */
        .sfs-hr-leave-self-form textarea.large-text,
        .sfs-hr-leave-self-form textarea {
            min-height: 80px !important;
            height: auto !important;
        }
        .sfs-hr-leave-self-form .form-table th,
        .sfs-hr-leave-self-form .form-table td {
            padding: 10px 0;
        }
        .sfs-hr-leave-self-form input[type="date"],
        .sfs-hr-leave-self-form select,
        .sfs-hr-leave-self-form textarea {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }

        /* Loan form */
        #sfs-loan-request-form {
            padding: 15px !important;
        }
        #sfs-loan-request-form textarea {
            min-height: 80px !important;
            height: auto !important;
        }
        #sfs-loan-request-form .form-table th,
        #sfs-loan-request-form .form-table td {
            padding: 10px 0;
        }
        #sfs-loan-request-form input[type="number"],
        #sfs-loan-request-form input[type="date"],
        #sfs-loan-request-form select,
        #sfs-loan-request-form textarea {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }

        /* General form-table improvements for mobile */
        .sfs-hr-my-profile-wrap .form-table th,
        .sfs-hr-my-profile-wrap .form-table td {
            display: block;
            width: 100%;
            padding: 8px 0;
        }
        .sfs-hr-my-profile-wrap .form-table th {
            padding-bottom: 4px;
        }
        .sfs-hr-my-profile-wrap .form-table input[type="text"],
        .sfs-hr-my-profile-wrap .form-table input[type="email"],
        .sfs-hr-my-profile-wrap .form-table input[type="number"],
        .sfs-hr-my-profile-wrap .form-table input[type="date"],
        .sfs-hr-my-profile-wrap .form-table select,
        .sfs-hr-my-profile-wrap .form-table textarea {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
    }
</style>';

// IMPORTANT:
// Do NOT render the full HR admin nav here for normal employees.
// It usually enforces HR caps and can trigger auth_redirect() loops.
// If you want HR to see the nav on this page too, wrap it in a cap check:
//
// if ( current_user_can( 'sfs_hr.manage' ) && method_exists( Helpers::class, 'render_admin_nav' ) ) {
//     Helpers::render_admin_nav();
// }

echo '<hr class="wp-header-end" />';

        // ----- Tabs -----
        $base_url = admin_url( 'admin.php?page=sfs-hr-my-profile' );

        echo '<h2 class="nav-tab-wrapper">';

        // Overview tab (built here)
        $overview_url   = remove_query_arg( 'tab', $base_url );
        $overview_class = 'nav-tab' . ( $active_tab === 'overview' ? ' nav-tab-active' : '' );

        echo '<a href="' . esc_url( $overview_url ) . '" class="' . esc_attr( $overview_class ) . '">';
        esc_html_e( 'Overview', 'sfs-hr' );
        echo '</a>';

        /**
         * Allow modules (Assets, Leave, Loans, ...) to add extra tabs.
         *
         * The Assets module already hooks here:
         *  - sfs_hr_employee_tabs        -> adds "Assets" tab
         */
        do_action( 'sfs_hr_employee_tabs', $employee );

        echo '</h2>';

        // ----- Tab content -----
        echo '<div class="sfs-hr-my-profile-inner">';

        if ( $active_tab === 'overview' ) {
            $this->render_overview_tab( $employee );
        }

        /**
         * Allow modules to render tab content for the current employee.
         *
         * Assets module already hooks here and renders:
         *  - assigned assets
         *  - approve/reject new assignments
         *  - confirm returns
         */
        do_action( 'sfs_hr_employee_tab_content', $employee, $active_tab );

        echo '</div>'; // .sfs-hr-my-profile-inner
        echo '</div>'; // .wrap
    }

        /**
     * Overview tab: basic info + photo + key details.
     *
     * @param \stdClass $employee Employee row from sfs_hr_employees.
     */
    public function render_overview_tab( \stdClass $employee ): void {
        global $wpdb;

        $dept_name = '';
        if ( ! empty( $employee->dept_id ) ) {
            $dept_table = $wpdb->prefix . 'sfs_hr_departments';
            $dept_name  = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT name FROM {$dept_table} WHERE id = %d",
                    (int) $employee->dept_id
                )
            );
        }

        $full_name = trim(
            (string) ( $employee->first_name ?? '' ) . ' ' .
            (string) ( $employee->last_name  ?? '' )
        );
        if ( $full_name === '' ) {
            $full_name = '#' . (int) $employee->id;
        }

        $code        = $employee->employee_code           ?? '';
        $status      = $employee->status                  ?? '';
        $position    = $employee->position                ?? '';
        $email       = $employee->email                   ?? '';
        $phone       = $employee->phone                   ?? '';
        $hire_date   = $employee->hired_at
            ?? ( $employee->hire_date                    ?? '' );
        $gender      = $employee->gender                  ?? '';
        $national_id = $employee->national_id             ?? '';
        $nid_exp     = $employee->national_id_expiry      ?? '';
        $passport_no = $employee->passport_no             ?? '';
        $pass_exp    = $employee->passport_expiry         ?? '';
        $base_salary = $employee->base_salary             ?? '';
        $emg_name    = $employee->emergency_contact_name  ?? '';
        $emg_phone   = $employee->emergency_contact_phone ?? '';
        $photo_id    = isset( $employee->photo_id ) ? (int) $employee->photo_id : 0;

        echo '<div class="sfs-hr-my-profile-overview">';

        // Top header with photo + core data
        echo '<div class="sfs-hr-my-profile-header" style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">';

        // Photo
        echo '<div class="sfs-hr-my-profile-photo">';
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
            echo '<div class="sfs-hr-emp-photo sfs-hr-emp-photo--empty" style="width:96px;height:96px;border-radius:50%;background:#f3f4f5;display:flex;align-items:center;justify-content:center;font-size:12px;color:#666;">'
                 . esc_html__( 'No photo', 'sfs-hr' ) .
                 '</div>';
        }
        echo '</div>';

        // Main header info
        echo '<div class="sfs-hr-my-profile-header-main">';
        echo '<h2 style="margin:0 0 4px;font-size:20px;">' . esc_html( $full_name ) . '</h2>';

        if ( $code !== '' || $status !== '' ) {
            echo '<div style="margin-bottom:6px;">';

            if ( $code !== '' ) {
                echo '<span style="display:inline-block;background:#f1f1f1;border-radius:999px;padding:2px 10px;font-size:11px;margin-right:6px;">'
                     . esc_html__( 'Code', 'sfs-hr' ) . ': ' . esc_html( $code ) .
                     '</span>';
            }

            if ( $status !== '' ) {
                echo '<span style="display:inline-block;background:#e5f5ff;border-radius:999px;padding:2px 10px;font-size:11px;">'
                     . esc_html( ucfirst( (string) $status ) ) .
                     '</span>';
            }

            echo '</div>';
        }

        if ( $position || $dept_name ) {
            echo '<div style="font-size:13px;color:#555;">';
            if ( $position ) {
                echo esc_html( $position );
            }
            if ( $position && $dept_name ) {
                echo ' · ';
            }
            if ( $dept_name ) {
                echo esc_html( $dept_name );
            }
            echo '</div>';
        }

        echo '</div>'; // .sfs-hr-my-profile-header-main
        echo '</div>'; // .sfs-hr-my-profile-header

        // Detail table
        echo '<table class="form-table sfs-hr-my-profile-table">';
        echo '<tbody>';

       // helper closure – internal use only
$print_row = static function ( string $label, $value ): void {
    // Normalize everything to string, handle null safely
    $value = (string) ( $value ?? '' );

    if ( $value === '' ) {
        return;
    }

    echo '<tr>';
    echo '<th scope="row">' . esc_html( $label ) . '</th>';
    echo '<td>' . esc_html( $value ) . '</td>';
    echo '</tr>';
};


        $print_row( __( 'Email', 'sfs-hr' ),       $email );
$print_row( __( 'Phone', 'sfs-hr' ),       $phone );
$print_row( __( 'Gender', 'sfs-hr' ),      $gender );
$print_row( __( 'Hire Date', 'sfs-hr' ),   $hire_date );
$print_row( __( 'Status', 'sfs-hr' ),      $status !== '' ? ucfirst( $status ) : '' );
$print_row( __( 'Department', 'sfs-hr' ),  $dept_name );
$print_row( __( 'Base Salary', 'sfs-hr' ), $base_salary );
$print_row( __( 'National ID', 'sfs-hr' ), $national_id );
$print_row( __( 'National ID Expiry', 'sfs-hr' ), $nid_exp );
$print_row( __( 'Passport No.', 'sfs-hr' ), $passport_no );
$print_row( __( 'Passport Expiry', 'sfs-hr' ), $pass_exp );
$print_row(
    __( 'Emergency Contact', 'sfs-hr' ),
    trim( $emg_name . ( $emg_phone ? ' / ' . $emg_phone : '' ) )
);


        echo '</tbody>';
        echo '</table>';

$this->render_quick_punch_block( $employee );
$this->render_assets_block( $employee );
$this->render_attendance_block( $employee );

        echo '</div>'; // .sfs-hr-my-profile-overview

    }

    /**
     * Render Quick Punch block for mobile-optimized clock in/out
     */
    private function render_quick_punch_block( \stdClass $employee ): void {
        global $wpdb;

        $punches_table = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $today = wp_date('Y-m-d');

        // Get last punch to determine current state
        $last_punch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$punches_table}
             WHERE employee_id = %d AND DATE(punch_time) = %s
             ORDER BY punch_time DESC LIMIT 1",
            (int)$employee->id,
            $today
        ));

        $current_status = 'not_clocked_in';
        $can_punch_in = true;
        $can_punch_out = false;
        $can_start_break = false;
        $can_end_break = false;

        if ($last_punch) {
            switch ($last_punch->punch_type) {
                case 'in':
                case 'break_end':
                    $current_status = 'clocked_in';
                    $can_punch_in = false;
                    $can_punch_out = true;
                    $can_start_break = true;
                    break;
                case 'break_start':
                    $current_status = 'on_break';
                    $can_punch_in = false;
                    $can_punch_out = false;
                    $can_end_break = true;
                    break;
                case 'out':
                    $current_status = 'clocked_out';
                    $can_punch_in = true;
                    $can_punch_out = false;
                    break;
            }
        }

        $status_labels = [
            'not_clocked_in' => __('Not Clocked In', 'sfs-hr'),
            'clocked_in' => __('Clocked In', 'sfs-hr'),
            'on_break' => __('On Break', 'sfs-hr'),
            'clocked_out' => __('Clocked Out', 'sfs-hr'),
        ];

        $status_colors = [
            'not_clocked_in' => '#666',
            'clocked_in' => '#059669',
            'on_break' => '#d97706',
            'clocked_out' => '#dc2626',
        ];

        ?>
        <div class="sfs-hr-quick-punch" style="margin-top:24px; padding:20px; background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius:12px; border:1px solid #e2e8f0;">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
                <div>
                    <h3 style="margin:0 0 8px; font-size:16px;"><?php esc_html_e('Quick Punch', 'sfs-hr'); ?></h3>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <span id="quick-punch-time" style="font-size:32px; font-weight:300; font-variant-numeric:tabular-nums;"><?php echo esc_html(wp_date('H:i:s')); ?></span>
                        <span style="display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; background:<?php echo esc_attr($status_colors[$current_status]); ?>1a; color:<?php echo esc_attr($status_colors[$current_status]); ?>;" id="quick-punch-status">
                            <?php echo esc_html($status_labels[$current_status]); ?>
                        </span>
                    </div>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;" id="quick-punch-buttons">
                    <button type="button" class="button button-primary sfs-quick-punch-btn" data-action="in" <?php disabled(!$can_punch_in); ?> style="<?php echo $can_punch_in ? 'background:#059669; border-color:#047857;' : ''; ?>">
                        <?php esc_html_e('Punch In', 'sfs-hr'); ?>
                    </button>
                    <button type="button" class="button sfs-quick-punch-btn" data-action="break_start" <?php disabled(!$can_start_break); ?> style="<?php echo $can_start_break ? 'background:#f59e0b; border-color:#d97706; color:#fff;' : ''; ?>">
                        <?php esc_html_e('Start Break', 'sfs-hr'); ?>
                    </button>
                    <button type="button" class="button sfs-quick-punch-btn" data-action="break_end" <?php disabled(!$can_end_break); ?> style="<?php echo $can_end_break ? 'background:#2271b1; border-color:#135e96; color:#fff;' : ''; ?>">
                        <?php esc_html_e('End Break', 'sfs-hr'); ?>
                    </button>
                    <button type="button" class="button sfs-quick-punch-btn" data-action="out" <?php disabled(!$can_punch_out); ?> style="<?php echo $can_punch_out ? 'background:#dc2626; border-color:#b91c1c; color:#fff;' : ''; ?>">
                        <?php esc_html_e('Punch Out', 'sfs-hr'); ?>
                    </button>
                </div>
            </div>
            <div id="quick-punch-message" style="margin-top:12px; display:none;"></div>
        </div>

        <script>
        (function() {
            // Update clock
            setInterval(function() {
                var el = document.getElementById('quick-punch-time');
                if (el) {
                    var now = new Date();
                    el.textContent = now.toLocaleTimeString('en-GB');
                }
            }, 1000);

            // Handle punch buttons
            document.querySelectorAll('.sfs-quick-punch-btn').forEach(function(btn) {
                btn.addEventListener('click', async function() {
                    if (this.disabled) return;

                    var action = this.dataset.action;
                    var message = document.getElementById('quick-punch-message');
                    var originalText = this.textContent;

                    this.disabled = true;
                    this.textContent = '<?php echo esc_js(__('Processing...', 'sfs-hr')); ?>';

                    try {
                        var response = await fetch('<?php echo esc_url(rest_url('sfs-hr/v1/attendance/punch')); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                            },
                            body: JSON.stringify({ punch_type: action })
                        });

                        var data = await response.json();

                        if (data.success) {
                            message.style.display = 'block';
                            message.style.padding = '10px';
                            message.style.borderRadius = '6px';
                            message.style.background = '#d1fae5';
                            message.style.color = '#059669';
                            message.textContent = data.message || '<?php echo esc_js(__('Punch recorded!', 'sfs-hr')); ?>';

                            setTimeout(function() { location.reload(); }, 1200);
                        } else {
                            message.style.display = 'block';
                            message.style.padding = '10px';
                            message.style.borderRadius = '6px';
                            message.style.background = '#fee2e2';
                            message.style.color = '#dc2626';
                            message.textContent = data.message || '<?php echo esc_js(__('An error occurred.', 'sfs-hr')); ?>';
                            this.disabled = false;
                            this.textContent = originalText;
                        }
                    } catch (error) {
                        message.style.display = 'block';
                        message.style.padding = '10px';
                        message.style.borderRadius = '6px';
                        message.style.background = '#fee2e2';
                        message.style.color = '#dc2626';
                        message.textContent = '<?php echo esc_js(__('Connection error. Please try again.', 'sfs-hr')); ?>';
                        this.disabled = false;
                        this.textContent = originalText;
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render "My Assets" block under the Overview tab for the logged-in employee.
     * - Section 1: Pending actions (approve / reject / confirm return)
     * - Section 2: All assignments (read-only history)
     */
    private function render_assets_block( \stdClass $employee ): void {
        global $wpdb;

        $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
        $asset_table  = $wpdb->prefix . 'sfs_hr_assets';

        // Check if Assets tables exist; if not, bail silently
        $assets_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = %s",
                $asset_table
            )
        );
        if ( ! $assets_exists ) {
            return;
        }

        $assign_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = %s",
                $assign_table
            )
        );
        if ( ! $assign_exists ) {
            return;
        }

        // Fetch all assignments for this employee (limit to something sane)
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT a.*, ast.name AS asset_name, ast.asset_code, ast.category
                FROM {$assign_table} a
                LEFT JOIN {$asset_table} ast ON ast.id = a.asset_id
                WHERE a.employee_id = %d
                ORDER BY a.created_at DESC
                LIMIT 200
                ",
                (int) $employee->id
            )
        );

        echo '<div class="sfs-hr-my-profile-assets" style="margin-top:24px;">';
        echo '<h2 style="margin:0 0 8px;">' . esc_html__( 'My Assets', 'sfs-hr' ) . '</h2>';

        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'You have no asset assignments.', 'sfs-hr' ) . '</p>';
            echo '</div>';
            return;
        }

        // Split rows → pending actions vs all
        $pending = [];
        foreach ( $rows as $row ) {
            if ( in_array( (string) $row->status, [ 'pending_employee_approval', 'return_requested' ], true ) ) {
                $pending[] = $row;
            }
        }

        // ====== Section 1: Pending actions ======
        echo '<div class="sfs-hr-my-assets-pending" style="margin-bottom:16px;">';
        echo '<h3 style="margin:12px 0 6px;">' . esc_html__( 'Pending actions', 'sfs-hr' ) . '</h3>';

        if ( empty( $pending ) ) {
            echo '<p>' . esc_html__( 'No pending actions.', 'sfs-hr' ) . '</p>';
        } else {
            echo '<table class="widefat fixed striped" style="margin-top:6px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'Asset', 'sfs-hr' ) . '</th>';
            echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
            echo '<th>' . esc_html__( 'Date', 'sfs-hr' ) . '</th>';
            echo '<th>' . esc_html__( 'Actions', 'sfs-hr' ) . '</th>';
            echo '</tr></thead><tbody>';

            $current_user_id = get_current_user_id();

            foreach ( $pending as $row ) {
                $asset_label = trim( (string) ( $row->asset_name ?? '' ) );
                if ( ! empty( $row->asset_code ) ) {
                    $asset_code  = (string) $row->asset_code;
                    $asset_label = $asset_label !== ''
                        ? $asset_label . ' (' . $asset_code . ')'
                        : $asset_code;
                }

                echo '<tr>';
                echo '<td>' . esc_html( $asset_label ) . '</td>';
                echo '<td>' . Helpers::asset_status_badge( (string) $row->status ) . '</td>';

                $date = $row->start_date ?: $row->created_at;
                echo '<td>' . esc_html( $date ?: '' ) . '</td>';

                echo '<td>';

                // Self-service: only the employee himself can act here.
                if ( $current_user_id && (int) $employee->user_id === (int) $current_user_id ) {

                    // 1) New assignment approval
                    if ( $row->status === 'pending_employee_approval' ) {
                        // Approve
                        echo '<form method="post" style="display:inline-block;margin-right:4px;" action="' .
                             esc_url( admin_url( 'admin-post.php' ) ) . '">';
                        wp_nonce_field( 'sfs_hr_assets_assign_decision_' . (int) $row->id );
                        echo '<input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />';
                        echo '<input type="hidden" name="assignment_id" value="' . (int) $row->id . '" />';
                        echo '<input type="hidden" name="decision" value="approve" />';
                        echo '<button type="submit" class="button button-primary">';
                        esc_html_e( 'Approve', 'sfs-hr' );
                        echo '</button>';
                        echo '</form>';

                        // Reject
                        echo '<form method="post" style="display:inline-block;" action="' .
                             esc_url( admin_url( 'admin-post.php' ) ) . '">';
                        wp_nonce_field( 'sfs_hr_assets_assign_decision_' . (int) $row->id );
                        echo '<input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />';
                        echo '<input type="hidden" name="assignment_id" value="' . (int) $row->id . '" />';
                        echo '<input type="hidden" name="decision" value="reject" />';
                        echo '<button type="submit" class="button">';
                        esc_html_e( 'Reject', 'sfs-hr' );
                        echo '</button>';
                        echo '</form>';
                    }

                    // 2) Confirm return
                    if ( $row->status === 'return_requested' ) {
                        echo '<form method="post" style="display:inline-block;" action="' .
                             esc_url( admin_url( 'admin-post.php' ) ) . '">';
                        wp_nonce_field( 'sfs_hr_assets_return_decision_' . (int) $row->id );
                        echo '<input type="hidden" name="action" value="sfs_hr_assets_return_decision" />';
                        echo '<input type="hidden" name="assignment_id" value="' . (int) $row->id . '" />';
                        echo '<input type="hidden" name="decision" value="approve" />';
                        echo '<button type="submit" class="button button-primary">';
                        esc_html_e( 'Confirm Return', 'sfs-hr' );
                        echo '</button>';
                        echo '</form>';
                    }
                }

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>'; // .sfs-hr-my-assets-pending

        // ====== Section 2: All assignments ======
        echo '<div class="sfs-hr-my-assets-all">';
        echo '<h3 style="margin:12px 0 6px;">' . esc_html__( 'All asset assignments', 'sfs-hr' ) . '</h3>';

        echo '<table class="widefat fixed striped" style="margin-top:6px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Asset', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Assigned at', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Returned / closed at', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $asset_label = trim( (string) ( $row->asset_name ?? '' ) );
            if ( ! empty( $row->asset_code ) ) {
                $asset_code  = (string) $row->asset_code;
                $asset_label = $asset_label !== ''
                    ? $asset_label . ' (' . $asset_code . ')'
                    : $asset_code;
            }

            echo '<tr>';
            echo '<td>' . esc_html( $asset_label ) . '</td>';
            echo '<td>' . Helpers::asset_status_badge( (string) $row->status ) . '</td>';

            $assigned_at = $row->start_date ?: $row->created_at;
            $closed_at   = $row->end_date ?: '';

            echo '<td>' . esc_html( $assigned_at ?: '' ) . '</td>';
            echo '<td>' . esc_html( $closed_at ?: '—' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // .sfs-hr-my-assets-all

        echo '</div>'; // .sfs-hr-my-profile-assets
    }
    
    
    /**
 * Admin "My Attendance" block under Overview tab:
 * - Today status
 * - Last 30 days status counts
 * - Last 10 days daily history
 */
private function render_attendance_block( \stdClass $employee ): void {
    global $wpdb;

    $sess_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';

    $today = wp_date( 'Y-m-d' );

    // Today row
    $today_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT status FROM {$sess_table} WHERE employee_id = %d AND work_date = %s LIMIT 1",
            (int) $employee->id,
            $today
        ),
        ARRAY_A
    );

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

    $today_status_key   = isset( $today_row['status'] ) ? (string) $today_row['status'] : '';
    $today_status_label = $status_label_fn( $today_status_key );

    // Last 30 days for summary + last 10 for history
    $start_30 = date( 'Y-m-d', strtotime( $today . ' -29 days' ) );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT work_date, status
            FROM {$sess_table}
            WHERE employee_id = %d
              AND work_date BETWEEN %s AND %s
            ORDER BY work_date DESC
            ",
            (int) $employee->id,
            $start_30,
            $today
        ),
        ARRAY_A
    );

    $counts = [];
    foreach ( $rows as $r ) {
        $st = (string) ( $r['status'] ?? '' );
        if ( $st === '' ) {
            $st = '(unknown)';
        }
        if ( ! isset( $counts[ $st ] ) ) {
            $counts[ $st ] = 0;
        }
        $counts[ $st ]++;
    }
    ksort( $counts );

    $history_rows = array_slice( $rows, 0, 10 );

    echo '<div class="sfs-hr-my-profile-attendance" style="margin-top:24px;">';
    echo '<h2 style="margin:0 0 8px;">' . esc_html__( 'My Attendance', 'sfs-hr' ) . '</h2>';

    echo '<p>';
    echo '<strong>' . esc_html__( 'Today:', 'sfs-hr' ) . '</strong> ';
    echo esc_html( $today_status_label );
    echo '</p>';

    // Summary
    echo '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:8px 0;">';
    if ( ! empty( $counts ) ) {
        foreach ( $counts as $st => $cnt ) {
            echo '<div style="padding:6px 8px;border-radius:4px;background:#f8f9fa;border:1px solid #e2e4e7;min-width:120px;">';
            echo '<div style="font-size:11px;color:#555d66;margin-bottom:2px;">' .
                 esc_html( $status_label_fn( $st ) ) . '</div>';
            echo '<div style="font-size:15px;font-weight:600;">' . (int) $cnt . '</div>';
            echo '</div>';
        }
    } else {
        echo '<p class="description" style="margin:0;">' .
             esc_html__( 'No attendance records for the last 30 days.', 'sfs-hr' ) .
             '</p>';
    }
    echo '</div>';

    // History table (last 10 days)
    if ( ! empty( $history_rows ) ) {
        echo '<h3 style="margin:12px 0 6px;">' . esc_html__( 'Recent days', 'sfs-hr' ) . '</h3>';
        echo '<table class="widefat fixed striped" style="margin-top:4px;">';
        echo '<thead><tr>';
        echo '<th style="width:120px;">' . esc_html__( 'Date', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $history_rows as $row ) {
            $date  = $row['work_date'] ?? '';
            $st    = (string) ( $row['status'] ?? '' );
            $label = $status_label_fn( $st );

            echo '<tr>';
            echo '<td>' . esc_html( $date ) . '</td>';
            echo '<td>' . esc_html( $label ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // Early Leave Requests Section
    $this->render_early_leave_section( $employee );

    echo '</div>';
}

/**
 * Render Early Leave Requests section in My Profile
 * Allows employees to request early leave and see their request history
 */
private function render_early_leave_section( \stdClass $employee ): void {
    global $wpdb;

    $table = $wpdb->prefix . 'sfs_hr_early_leave_requests';

    // Check if table exists
    $table_exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
        $table
    ) );

    if ( ! $table_exists ) {
        return;
    }

    $today = wp_date( 'Y-m-d' );
    $now_time = wp_date( 'H:i' );

    // Check for existing pending request today
    $existing_today = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE employee_id = %d AND request_date = %s AND status IN ('pending', 'approved')",
        (int) $employee->id,
        $today
    ) );

    // Get recent requests (last 30 days)
    $recent_requests = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE employee_id = %d ORDER BY request_date DESC, created_at DESC LIMIT 10",
        (int) $employee->id
    ) );

    $reason_labels = [
        'sick' => __( 'Sickness', 'sfs-hr' ),
        'external_task' => __( 'External Task', 'sfs-hr' ),
        'personal' => __( 'Personal', 'sfs-hr' ),
        'emergency' => __( 'Emergency', 'sfs-hr' ),
        'other' => __( 'Other', 'sfs-hr' ),
    ];

    $status_labels = [
        'pending' => __( 'Pending', 'sfs-hr' ),
        'approved' => __( 'Approved', 'sfs-hr' ),
        'rejected' => __( 'Rejected', 'sfs-hr' ),
        'cancelled' => __( 'Cancelled', 'sfs-hr' ),
    ];

    $status_colors = [
        'pending' => '#f0ad4e',
        'approved' => '#5cb85c',
        'rejected' => '#d9534f',
        'cancelled' => '#777',
    ];

    echo '<div class="sfs-hr-early-leave-section" style="margin-top:24px; padding-top:16px; border-top:1px solid #e2e4e7;">';

    // Header with "Add Request" button at the top
    echo '<div style="display:flex; align-items:center; justify-content:space-between; margin:0 0 12px;">';
    echo '<h3 style="margin:0;">' . esc_html__( 'Early Leave Requests', 'sfs-hr' ) . '</h3>';

    // Show request form if no pending/approved request for today
    if ( ! $existing_today ) {
        echo '<button type="button" class="button button-primary" id="sfs-early-leave-toggle-btn" style="font-size:13px; padding:4px 14px; border-radius:6px;">';
        echo '+ ' . esc_html__( 'Add Request', 'sfs-hr' );
        echo '</button>';
    }
    echo '</div>';

    if ( ! $existing_today ) {
        ?>
        <div id="sfs-early-leave-form-panel" class="sfs-hr-early-leave-form-wrap" style="display:none; background:#f9f9f9; padding:15px; border:1px solid #e5e5e5; margin-bottom:16px; border-radius:4px;">
            <p class="description" style="margin-top:0;">
                <?php esc_html_e( 'Need to leave early today? Submit a request for manager approval.', 'sfs-hr' ); ?>
            </p>

            <form id="sfs-early-leave-request-form" class="sfs-hr-early-leave-form">
                <input type="hidden" name="request_date" value="<?php echo esc_attr( $today ); ?>" />

                <table class="form-table" style="margin:0;">
                    <tr>
                        <th scope="row"><label for="early-leave-time"><?php esc_html_e( 'Leave Time', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="time" id="early-leave-time" name="requested_leave_time" value="<?php echo esc_attr( $now_time ); ?>" required style="width:150px;" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="early-leave-reason"><?php esc_html_e( 'Reason', 'sfs-hr' ); ?></label></th>
                        <td>
                            <select id="early-leave-reason" name="reason_type" required style="width:200px;">
                                <option value=""><?php esc_html_e( '— Select —', 'sfs-hr' ); ?></option>
                                <?php foreach ( $reason_labels as $key => $label ): ?>
                                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="early-leave-note"><?php esc_html_e( 'Note', 'sfs-hr' ); ?></label></th>
                        <td>
                            <textarea id="early-leave-note" name="reason_note" rows="2" style="width:100%; max-width:400px;" placeholder="<?php esc_attr_e( 'Optional details...', 'sfs-hr' ); ?>"></textarea>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-bottom:0; padding-bottom:0;">
                    <button type="submit" class="button button-primary" id="early-leave-submit-btn">
                        <?php esc_html_e( 'Submit Request', 'sfs-hr' ); ?>
                    </button>
                    <button type="button" class="button" id="sfs-early-leave-cancel-form" style="margin-left:8px;">
                        <?php esc_html_e( 'Cancel', 'sfs-hr' ); ?>
                    </button>
                    <span id="early-leave-message" style="margin-left:10px;"></span>
                </p>
            </form>
        </div>
        <script>
        jQuery(function($){
            $('#sfs-early-leave-toggle-btn').on('click', function(){
                $(this).hide();
                $('#sfs-early-leave-form-panel').slideDown(200);
            });
            $('#sfs-early-leave-cancel-form').on('click', function(){
                $('#sfs-early-leave-form-panel').slideUp(200, function(){
                    $('#sfs-early-leave-toggle-btn').show();
                });
            });
        });
        </script>

        <script>
        jQuery(function($) {
            $('#sfs-early-leave-request-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $btn = $('#early-leave-submit-btn');
                var $msg = $('#early-leave-message');

                var data = {
                    request_date: $form.find('[name="request_date"]').val(),
                    requested_leave_time: $form.find('[name="requested_leave_time"]').val(),
                    reason_type: $form.find('[name="reason_type"]').val(),
                    reason_note: $form.find('[name="reason_note"]').val()
                };

                if (!data.requested_leave_time || !data.reason_type) {
                    $msg.html('<span style="color:#d9534f;"><?php echo esc_js( __( 'Please fill in all required fields.', 'sfs-hr' ) ); ?></span>');
                    return;
                }

                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Submitting...', 'sfs-hr' ) ); ?>');
                $msg.text('');

                $.ajax({
                    url: '<?php echo esc_url( rest_url( 'sfs-hr/v1/early-leave/request' ) ); ?>',
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce( 'wp_rest' ); ?>');
                    },
                    data: data,
                    success: function(response) {
                        if (response.success) {
                            $msg.html('<span style="color:#5cb85c;"><?php echo esc_js( __( 'Request submitted! Awaiting manager approval.', 'sfs-hr' ) ); ?></span>');
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            $msg.html('<span style="color:#d9534f;">' + (response.message || '<?php echo esc_js( __( 'An error occurred.', 'sfs-hr' ) ); ?>') + '</span>');
                            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Submit Request', 'sfs-hr' ) ); ?>');
                        }
                    },
                    error: function(xhr) {
                        var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : '<?php echo esc_js( __( 'An error occurred.', 'sfs-hr' ) ); ?>';
                        $msg.html('<span style="color:#d9534f;">' + msg + '</span>');
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Submit Request', 'sfs-hr' ) ); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    } else {
        // Show existing request for today
        $status_color = $status_colors[ $existing_today->status ] ?? '#333';
        echo '<div class="notice notice-info" style="margin:0 0 16px;">';
        echo '<p>';
        printf(
            esc_html__( 'You have an early leave request for today (%s) - Status: %s', 'sfs-hr' ),
            esc_html( date_i18n( 'H:i', strtotime( $existing_today->requested_leave_time ) ) ),
            '<strong style="color:' . esc_attr( $status_color ) . ';">' . esc_html( $status_labels[ $existing_today->status ] ?? $existing_today->status ) . '</strong>'
        );

        // Cancel button for pending requests
        if ( $existing_today->status === 'pending' ) {
            ?>
            <button type="button" class="button button-small" id="cancel-early-leave-btn" data-id="<?php echo intval( $existing_today->id ); ?>" style="margin-left:10px;">
                <?php esc_html_e( 'Cancel Request', 'sfs-hr' ); ?>
            </button>
            <script>
            jQuery(function($) {
                $('#cancel-early-leave-btn').on('click', function() {
                    if (!confirm('<?php echo esc_js( __( 'Are you sure you want to cancel this request?', 'sfs-hr' ) ); ?>')) {
                        return;
                    }

                    var $btn = $(this);
                    var id = $btn.data('id');
                    $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Cancelling...', 'sfs-hr' ) ); ?>');

                    $.ajax({
                        url: '<?php echo esc_url( rest_url( 'sfs-hr/v1/early-leave/cancel/' ) ); ?>' + id,
                        method: 'POST',
                        beforeSend: function(xhr) {
                            xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce( 'wp_rest' ); ?>');
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert(response.message || '<?php echo esc_js( __( 'Failed to cancel.', 'sfs-hr' ) ); ?>');
                                $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Cancel Request', 'sfs-hr' ) ); ?>');
                            }
                        },
                        error: function(xhr) {
                            var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : '<?php echo esc_js( __( 'An error occurred.', 'sfs-hr' ) ); ?>';
                            alert(msg);
                            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Cancel Request', 'sfs-hr' ) ); ?>');
                        }
                    });
                });
            });
            </script>
            <?php
        }

        echo '</p>';
        echo '</div>';
    }

    // Recent requests history
    if ( ! empty( $recent_requests ) ) {
        echo '<h4 style="margin:16px 0 8px;">' . esc_html__( 'Recent Requests', 'sfs-hr' ) . '</h4>';
        echo '<table class="widefat fixed striped" style="margin-top:4px;">';
        echo '<thead><tr>';
        echo '<th style="width:100px;">' . esc_html__( 'Date', 'sfs-hr' ) . '</th>';
        echo '<th style="width:80px;">' . esc_html__( 'Time', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Reason', 'sfs-hr' ) . '</th>';
        echo '<th style="width:100px;">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $recent_requests as $req ) {
            $status_color = $status_colors[ $req->status ] ?? '#333';
            echo '<tr>';
            echo '<td>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $req->request_date ) ) ) . '</td>';
            echo '<td>' . esc_html( date_i18n( 'H:i', strtotime( $req->requested_leave_time ) ) ) . '</td>';
            echo '<td>';
            echo esc_html( $reason_labels[ $req->reason_type ] ?? $req->reason_type );
            if ( $req->reason_note ) {
                echo '<br><small class="description">' . esc_html( wp_trim_words( $req->reason_note, 10 ) ) . '</small>';
            }
            echo '</td>';
            echo '<td style="color:' . esc_attr( $status_color ) . '; font-weight:600;">' . esc_html( $status_labels[ $req->status ] ?? $req->status ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div>'; // .sfs-hr-early-leave-section
}


}