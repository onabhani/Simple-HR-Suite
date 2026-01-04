<?php
namespace SFS\HR\Modules\Employees\Admin;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * My_Profile_Page
 * Employee self-service profile (tabs: overview, assets, leave, ...)
 * Version: 0.1.0-my-profile-v1
 * Author: Omar Alnabhani (hdqah.com)
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

$this->render_assets_block( $employee );
$this->render_attendance_block( $employee );

        echo '</div>'; // .sfs-hr-my-profile-overview
        
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

    echo '</div>';
}


}