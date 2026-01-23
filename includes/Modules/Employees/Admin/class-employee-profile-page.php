<?php
namespace SFS\HR\Modules\Employees\Admin;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Employee_Profile_Page
 * Employee Profile screen (Phase 2)
 * Version: 0.1.6-profile-kpi
 * Author: Omar Alnabhani (hdqah.com)
 */
class Employee_Profile_Page {

    private const RISK_LOOKBACK_DAYS       = 30;
    private const RISK_LATE_MIN_DAYS       = 3;
    private const RISK_LOW_PRES_MIN_DAYS   = 3;
    private const RISK_LEAVE_MIN_DAYS      = 5;

    public function hooks(): void {
        add_action( 'admin_menu', [ $this, 'menu' ], 40 );
    }

    public function menu(): void {
        add_submenu_page(
            null,
            __( 'Employee Profile', 'sfs-hr' ),
            __( 'Employee Profile', 'sfs-hr' ),
            'sfs_hr_attendance_view_team',
            'sfs-hr-employee-profile',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
    Helpers::require_cap( 'sfs_hr_attendance_view_team' );

echo '<div class="wrap sfs-hr-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'Employee Profile', 'sfs-hr' ) . '</h1>';
    
    Helpers::render_admin_nav();
    echo '<hr class="wp-header-end" />';
    
    $employee_id = isset( $_GET['employee_id'] ) ? (int) $_GET['employee_id'] : 0;
    if ( $employee_id <= 0 ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-employees' ) );
        exit;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'sfs_hr_employees';

    $emp = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $employee_id ),
        ARRAY_A
    );

    if ( ! $emp ) {
        wp_die( esc_html__( 'Employee not found.', 'sfs-hr' ) );
    }

        $employee_id = (int) $emp['id'];
    $employee    = (object) $emp; // stdClass-like object for tabs/hooks


    // Month param (for summary + to preserve in edit/view links)
    $ym_param = isset( $_GET['ym'] ) ? sanitize_text_field( wp_unslash( $_GET['ym'] ) ) : '';
    if ( ! preg_match( '/^\d{4}-\d{2}$/', $ym_param ) ) {
        $ym_param = wp_date( 'Y-m' );
    }

        // Active tab (default: overview)
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview';

    // View / edit mode
    $mode = ( isset( $_GET['mode'] ) && $_GET['mode'] === 'edit' ) ? 'edit' : 'view';

    $base_url = admin_url( 'admin.php?page=sfs-hr-employee-profile&employee_id=' . $employee_id );
    if ( $ym_param ) {
        $base_url = add_query_arg( 'ym', $ym_param, $base_url );
    }

    $edit_url   = add_query_arg( 'mode', 'edit', $base_url );
    $view_url   = remove_query_arg( 'mode', $base_url );
    $edit_nonce = wp_create_nonce( 'sfs_hr_save_edit_' . $employee_id );

    // Dept scoping
    $can_manage_all = current_user_can( 'sfs_hr.manage' );
    if ( ! $can_manage_all ) {
        $allowed_depts = $this->manager_dept_ids_for_current_user();
        $emp_dept_id   = (int) ( $emp['dept_id'] ?? 0 );

        if ( empty( $allowed_depts ) || ! in_array( $emp_dept_id, $allowed_depts, true ) ) {
            wp_die( esc_html__( 'You are not allowed to view this employee.', 'sfs-hr' ) );
        }
    }

    // -------- Basic info / fields --------
    $name = trim( ( $emp['first_name'] ?? '' ) . ' ' . ( $emp['last_name'] ?? '' ) );
    if ( $name === '' ) {
        $name = '#' . (int) $emp['id'];
    }

    $code        = $emp['employee_code'] ?? '';
    $status      = $emp['status'] ?? '';
    $dept_id     = (int) ( $emp['dept_id'] ?? 0 );
    $wp_user     = (int) ( $emp['user_id'] ?? 0 );
    $hire_date   = $emp['hired_at'] ?? ( $emp['hire_date'] ?? '' );
    $email       = $emp['email'] ?? '';
    $phone       = $emp['phone'] ?? '';
    $position    = $emp['position'] ?? '';
    $gender      = $emp['gender'] ?? '';
    $base_salary = isset( $emp['base_salary'] ) ? $emp['base_salary'] : null;
    $national_id = $emp['national_id'] ?? '';
    $nid_exp     = $emp['national_id_expiry'] ?? '';
    $passport_no = $emp['passport_no'] ?? '';
    $pass_exp    = $emp['passport_expiry'] ?? '';
    $emg_name    = $emp['emergency_contact_name'] ?? '';
    $emg_phone   = $emp['emergency_contact_phone'] ?? '';
    $photo_id    = isset( $emp['photo_id'] ) ? (int) $emp['photo_id'] : 0;
    $first_name  = $emp['first_name'] ?? '';
    $last_name   = $emp['last_name'] ?? '';

    // Dept name + dept map (for edit dropdown)
    $dept_name  = '';
    $dept_map   = [];
    $dept_table = $wpdb->prefix . 'sfs_hr_departments';
    $dept_exists = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $dept_table
        )
    );
    if ( $dept_exists ) {
        if ( $dept_id > 0 ) {
            $dept_name = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT name FROM {$dept_table} WHERE id=%d",
                    $dept_id
                )
            );
        }
        $dept_rows = $wpdb->get_results( "SELECT id, name FROM {$dept_table} WHERE active=1 ORDER BY name ASC", ARRAY_A );
        foreach ( $dept_rows as $r ) {
            $dept_map[ (int) $r['id'] ] = $r['name'];
        }
    }

    // Hierarchy: Get manager and direct reports
    $manager_info = null;
    $direct_reports = [];
    $is_manager = false;

    if ( $dept_exists && $dept_id > 0 ) {
        // Get this employee's department manager
        $manager_user_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT manager_user_id FROM {$dept_table} WHERE id = %d AND active = 1",
                $dept_id
            )
        );

        // If this employee IS the manager, get direct reports
        if ( $manager_user_id > 0 && $wp_user > 0 && $manager_user_id === $wp_user ) {
            $is_manager = true;
            // Get employees in this department (excluding self)
            $direct_reports = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, first_name, last_name, position, photo_id
                     FROM {$table}
                     WHERE dept_id = %d AND status = 'active' AND id != %d
                     ORDER BY first_name, last_name ASC",
                    $dept_id,
                    $employee_id
                ),
                ARRAY_A
            );
        } else if ( $manager_user_id > 0 ) {
            // Get manager's employee record
            $manager_info = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT e.id, e.first_name, e.last_name, e.position, e.photo_id
                     FROM {$table} e
                     WHERE e.user_id = %d AND e.status = 'active'",
                    $manager_user_id
                ),
                ARRAY_A
            );
        }
    }

    // WP user / username
    $wp_username = '';
    if ( $wp_user ) {
        $u = get_userdata( $wp_user );
        if ( $u ) {
            $wp_username = $u->user_login;
        }
    }

    // Today + risk
    $today          = wp_date( 'Y-m-d' );
    $today_snapshot = $this->get_today_snapshot( $employee_id, $today );
    $risk_flag      = $this->get_risk_flag_for_employee( $employee_id, $today );

    // Month meta + data
    $month_meta = $this->get_month_meta( $ym_param );
    $month_data = $this->get_month_summary_for_employee( $employee_id, $month_meta['start'], $month_meta['end'] );
    $kpi        = $month_data['kpi'];

    // Month choices (current + previous 11)
    $month_choices = [];
    $tz            = wp_timezone();
    $current_dt    = \DateTimeImmutable::createFromFormat( 'Y-m-d', $month_meta['ym'] . '-01', $tz );
    if ( $current_dt ) {
        for ( $i = 0; $i < 12; $i++ ) {
            $m         = $current_dt->modify( "-{$i} months" );
            $ym        = $m->format( 'Y-m' );
            $label_opt = date_i18n( 'F Y', $m->getTimestamp() );
            $month_choices[ $ym ] = $label_opt;
        }
    }

    ?>
    <div class="wrap" id="sfs-hr-employee-profile-wrap">
        <?php $this->output_inline_styles(); ?>

        <h1 class="wp-heading-inline">
            <?php echo esc_html__( 'Employee Profile', 'sfs-hr' ); ?>
        </h1>
        

        <div class="sfs-hr-emp-header-actions">
            <?php if ( current_user_can( 'sfs_hr.manage' ) ) : ?>
                <?php if ( $mode === 'view' ) : ?>
                    <a href="<?php echo esc_url( $edit_url ); ?>" class="page-title-action">
                        <?php esc_html_e( 'Edit', 'sfs-hr' ); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url( $view_url ); ?>" class="page-title-action">
                        <?php esc_html_e( 'Cancel', 'sfs-hr' ); ?>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <hr class="wp-header-end" />

                <h2 class="sfs-hr-emp-name">
            <?php echo esc_html( $name ); ?>
            <?php if ( $code ) : ?>
                <span class="sfs-hr-emp-code-pill"><code><?php echo esc_html( $code ); ?></code></span>
            <?php endif; ?>
            <?php if ( $wp_user ) : ?>
                <span class="sfs-hr-emp-code-pill">
                    <?php echo esc_html__( 'USR', 'sfs-hr' ); ?>-<?php echo (int) $wp_user; ?>
                </span>
            <?php endif; ?>
        </h2>

        <?php
        // Tabs: Overview + extra tabs from modules (Leave, etc.)
        $tabs_base_args = [
            'page'        => 'sfs-hr-employee-profile',
            'employee_id' => (int) $employee_id,
        ];
        if ( $ym_param ) {
            $tabs_base_args['ym'] = $ym_param;
        }
        $tabs_base_url  = add_query_arg( $tabs_base_args, admin_url( 'admin.php' ) );
        $overview_url   = remove_query_arg( 'tab', $tabs_base_url );
        $overview_class = 'nav-tab' . ( $active_tab === 'overview' ? ' nav-tab-active' : '' );
        ?>
        <h2 class="nav-tab-wrapper sfs-hr-employee-tabs">
            <a href="<?php echo esc_url( $overview_url ); ?>" class="<?php echo esc_attr( $overview_class ); ?>">
                <?php esc_html_e( 'Overview', 'sfs-hr' ); ?>
            </a>
            <?php
            /**
             * Extra tabs for Employee Profile (Leave, others).
             */
            do_action( 'sfs_hr_employee_tabs', $employee );
            ?>
        </h2>

        <div class="sfs-hr-employee-profile-inner">
        <?php if ( $active_tab === 'overview' ) : ?>

        <?php if ( $mode === 'edit' ) : ?>

        <form method="post"
              action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              enctype="multipart/form-data"
              class="sfs-hr-emp-edit-form">
            <input type="hidden" name="action" value="sfs_hr_save_edit" />
            <input type="hidden" name="id" value="<?php echo (int) $employee_id; ?>" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $edit_nonce ); ?>" />
            <input type="hidden" name="from_profile" value="1" />
    <input type="hidden" name="ym" value="<?php echo esc_attr( $ym_param ); ?>" />
        <?php endif; ?>

        <div class="sfs-hr-emp-layout">
            <div class="sfs-hr-emp-col sfs-hr-emp-col--left">
                <div class="sfs-hr-emp-card sfs-hr-emp-card--basic">
                    <h3><?php esc_html_e( 'Employee info', 'sfs-hr' ); ?></h3>

                    <div class="sfs-hr-emp-basic-top">
                        <div class="sfs-hr-emp-photo-wrap">
                            <?php
                            if ( $photo_id ) {
                                echo wp_get_attachment_image(
                                    $photo_id,
                                    [ 96, 96 ],
                                    false,
                                    [
                                        'class' => 'sfs-hr-emp-photo',
                                        'style' => 'display:block;'
                                    ]
                                );
                            } else {
                                echo '<div class="sfs-hr-emp-photo sfs-hr-emp-photo--empty">'
                                     . esc_html__( 'No photo', 'sfs-hr' ) .
                                     '</div>';
                            }
                            ?>
                        </div>
                        <?php if ( $mode === 'edit' ) : ?>
                            <p style="margin-top:8px;">
                                <input type="file" name="employee_photo" accept="image/*" />
                                <span class="description">
                                    <?php esc_html_e( 'JPEG/PNG. Optional.', 'sfs-hr' ); ?>
                                </span>
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ( $mode === 'view' ) : ?>

                        <table class="sfs-hr-emp-basic-table">
                            <tbody>

                            <!-- Employment -->
                            <tr class="sfs-hr-emp-section-row">
                                <th colspan="2"><?php esc_html_e( 'Employment', 'sfs-hr' ); ?></th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                                <td><?php echo esc_html( ucfirst( $status ) ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Gender', 'sfs-hr' ); ?></th>
                                <td>
                                    <?php
                                    if ( $gender ) {
                                        echo esc_html( ucwords( str_replace( '_', ' ', $gender ) ) );
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Department', 'sfs-hr' ); ?></th>
                                <td><?php echo $dept_name ? esc_html( $dept_name ) : '—'; ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Position', 'sfs-hr' ); ?></th>
                                <td><?php echo $position ? esc_html( $position ) : '—'; ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Hire date', 'sfs-hr' ); ?></th>
                                <td>
                                    <?php
                                    echo $hire_date
                                        ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $hire_date ) ) )
                                        : '—';
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Employee ID', 'sfs-hr' ); ?></th>
                                <td><?php echo (int) $emp['id']; ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'WP Username', 'sfs-hr' ); ?></th>
                                <td>
                                    <?php
                                    if ( $wp_username ) {
                                        $edit_link = get_edit_user_link( $wp_user );
                                        if ( $edit_link ) {
                                            echo '<a href="' . esc_url( $edit_link ) . '">'
                                                 . esc_html( $wp_username ) . '</a>';
                                        } else {
                                            echo esc_html( $wp_username );
                                        }
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>

                            <!-- Contact -->
                            <tr class="sfs-hr-emp-section-row">
                                <th colspan="2"><?php esc_html_e( 'Contact', 'sfs-hr' ); ?></th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Email', 'sfs-hr' ); ?></th>
                                <td><?php echo $email ? esc_html( $email ) : '—'; ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Phone', 'sfs-hr' ); ?></th>
                                <td><?php echo $phone ? esc_html( $phone ) : '—'; ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Emergency contact', 'sfs-hr' ); ?></th>
                                <td>
                                    <?php
                                    if ( $emg_name || $emg_phone ) {
                                        echo esc_html( trim( $emg_name ) );
                                        if ( $emg_phone ) {
                                            echo $emg_name ? ' – ' : '';
                                            echo esc_html( $emg_phone );
                                        }
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>

                            <!-- Identification -->
                            <tr class="sfs-hr-emp-section-row">
                                <th colspan="2"><?php esc_html_e( 'Identification', 'sfs-hr' ); ?></th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'National ID', 'sfs-hr' ); ?></th>
                                <td>
                                    <?php
                                    echo $national_id ? esc_html( $national_id ) : '—';
                                    if ( $nid_exp ) {
                                        echo ' <span class="description">(' .
                                             esc_html(
                                                 sprintf(
                                                     __( 'expires %s', 'sfs-hr' ),
                                                     date_i18n( get_option( 'date_format' ), strtotime( $nid_exp ) )
                                                 )
                                             ) .
                                             ')</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Passport', 'sfs-hr' ); ?></th>
                                <td>
                                    <?php
                                    echo $passport_no ? esc_html( $passport_no ) : '—';
                                    if ( $pass_exp ) {
                                        echo ' <span class="description">(' .
                                             esc_html(
                                                 sprintf(
                                                     __( 'expires %s', 'sfs-hr' ),
                                                     date_i18n( get_option( 'date_format' ), strtotime( $pass_exp ) )
                                                 )
                                             ) .
                                             ')</span>';
                                    }
                                    ?>
                                </td>
                            </tr>

                            <!-- Payroll -->
                            <tr class="sfs-hr-emp-section-row">
                                <th colspan="2"><?php esc_html_e( 'Payroll', 'sfs-hr' ); ?></th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Base salary', 'sfs-hr' ); ?></th>
                                <td>
                                    <?php
                                    if ( $base_salary !== null && $base_salary !== '' ) {
                                        echo esc_html( number_format_i18n( (float) $base_salary, 2 ) );
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>

                            </tbody>
                        </table>

                    <?php else : // EDIT MODE ?>

                        <table class="sfs-hr-emp-basic-table">
                            <tbody>

                            <!-- Employment -->
                            <tr class="sfs-hr-emp-section-row">
                                <th colspan="2"><?php esc_html_e( 'Employment', 'sfs-hr' ); ?></th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Employee code', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="text" name="employee_code" class="regular-text"
                                           value="<?php echo esc_attr( $code ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'First name', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="text" name="first_name" class="regular-text"
                                           value="<?php echo esc_attr( $first_name ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Last name', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="text" name="last_name" class="regular-text"
                                           value="<?php echo esc_attr( $last_name ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                                <td>
                                    <?php $st = $status ?: 'active'; ?>
                                    <select name="status">
                                        <option value="active" <?php selected( $st, 'active' ); ?>>
                                            <?php esc_html_e( 'Active', 'sfs-hr' ); ?>
                                        </option>
                                        <option value="inactive" <?php selected( $st, 'inactive' ); ?>>
                                            <?php esc_html_e( 'Inactive', 'sfs-hr' ); ?>
                                        </option>
                                        <option value="terminated" <?php selected( $st, 'terminated' ); ?>>
                                            <?php esc_html_e( 'Terminated', 'sfs-hr' ); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Gender', 'sfs-hr' ); ?></th>
                                <td>
                                    <?php $g = strtolower( (string) $gender ); ?>
                                    <select name="gender">
                                        <option value=""><?php esc_html_e( '— Select —', 'sfs-hr' ); ?></option>
                                        <option value="male" <?php selected( $g, 'male' ); ?>>
                                            <?php esc_html_e( 'Male', 'sfs-hr' ); ?>
                                        </option>
                                        <option value="female" <?php selected( $g, 'female' ); ?>>
                                            <?php esc_html_e( 'Female', 'sfs-hr' ); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Date of Birth', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="date" name="birth_date" class="regular-text sfs-hr-date"
                                           value="<?php echo esc_attr( $emp['birth_date'] ?? '' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Used for birthday reminders', 'sfs-hr' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Department', 'sfs-hr' ); ?></th>
                                <td>
                                    <select name="dept_id" class="sfs-hr-select">
                                        <option value=""><?php esc_html_e( 'General (no department)', 'sfs-hr' ); ?></option>
                                        <?php foreach ( $dept_map as $did => $dname ) : ?>
                                            <option value="<?php echo (int) $did; ?>" <?php selected( $dept_id, $did ); ?>>
                                                <?php echo esc_html( $dname ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Position', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="text" name="position" class="regular-text"
                                           value="<?php echo esc_attr( $position ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Hire date', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="date" name="hired_at" class="regular-text sfs-hr-date"
                                           value="<?php echo esc_attr( $hire_date ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Employee ID', 'sfs-hr' ); ?></th>
                                <td><code><?php echo (int) $emp['id']; ?></code></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'WP Username', 'sfs-hr' ); ?></th>
                                <td>
                                    <?php
                                    if ( $wp_username ) {
                                        $edit_link = get_edit_user_link( $wp_user );
                                        if ( $edit_link ) {
                                            echo '<a href="' . esc_url( $edit_link ) . '">'
                                                 . esc_html( $wp_username ) . '</a>';
                                        } else {
                                            echo esc_html( $wp_username );
                                        }
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>

                            <!-- Contact -->
                            <tr class="sfs-hr-emp-section-row">
                                <th colspan="2"><?php esc_html_e( 'Contact', 'sfs-hr' ); ?></th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Email', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="email" name="email" class="regular-text"
                                           value="<?php echo esc_attr( $email ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Phone', 'sfs-hr' ); ?></th>
                                <td>
                                <input type="text" name="phone" class="regular-text"
                                       value="<?php echo esc_attr( $phone ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Emergency contact name', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="text" name="emergency_contact_name" class="regular-text"
                                           value="<?php echo esc_attr( $emg_name ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Emergency contact phone', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="text" name="emergency_contact_phone" class="regular-text"
                                           value="<?php echo esc_attr( $emg_phone ); ?>" />
                                </td>
                            </tr>

                            <!-- Identification -->
                            <tr class="sfs-hr-emp-section-row">
                                <th colspan="2"><?php esc_html_e( 'Identification', 'sfs-hr' ); ?></th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'National ID', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="text" name="national_id" class="regular-text"
                                           value="<?php echo esc_attr( $national_id ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'National ID expiry', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="date" name="national_id_expiry" class="regular-text sfs-hr-date"
                                           value="<?php echo esc_attr( $nid_exp ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Passport No.', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="text" name="passport_no" class="regular-text"
                                           value="<?php echo esc_attr( $passport_no ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Passport expiry', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="date" name="passport_expiry" class="regular-text sfs-hr-date"
                                           value="<?php echo esc_attr( $pass_exp ); ?>" />
                                </td>
                            </tr>

                            <!-- Payroll -->
                            <tr class="sfs-hr-emp-section-row">
                                <th colspan="2"><?php esc_html_e( 'Payroll', 'sfs-hr' ); ?></th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Base salary', 'sfs-hr' ); ?></th>
                                <td>
                                    <input type="text" name="base_salary" class="regular-text"
                                           value="<?php echo esc_attr( $base_salary ); ?>" />
                                </td>
                            </tr>

                            </tbody>
                        </table>

                    <?php endif; // view/edit ?>
                </div>
            </div>

            <div class="sfs-hr-emp-col sfs-hr-emp-col--right">
                <div class="sfs-hr-emp-card">
                    <h3><?php esc_html_e( 'Today snapshot', 'sfs-hr' ); ?></h3>

                    <table class="sfs-hr-emp-today-table">
                        <tbody>
                        <tr>
                            <th><?php esc_html_e( 'Attendance', 'sfs-hr' ); ?></th>
                            <td>
                                <?php echo $this->render_status_badge( $today_snapshot['status_key'], $today_snapshot['status_label'] ); ?>
                                <?php if ( $today_snapshot['since'] ) : ?>
                                    <span class="description">
                                        <?php
                                        printf(
                                            esc_html__( 'since %s', 'sfs-hr' ),
                                            esc_html( $this->format_time( $today_snapshot['since'] ) )
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Leave', 'sfs-hr' ); ?></th>
                            <td><?php echo $this->render_leave_badge( $today_snapshot['leave_label'] ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Last punch', 'sfs-hr' ); ?></th>
                            <td><?php echo esc_html( $this->format_time( $today_snapshot['last_punch'] ) ); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Risk', 'sfs-hr' ); ?></th>
                            <td>
                                <?php
                                echo $risk_flag !== ''
                                    ? esc_html( $risk_flag )
                                    : '<span class="description">' . esc_html__( 'No recent risk flags.', 'sfs-hr' ) . '</span>';
                                ?>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
                <?php $this->render_assets_card( $employee_id ); ?>
                <?php $this->render_documents_card( $employee_id ); ?>
                <?php $this->render_requests_card( $employee_id ); ?>

                <?php if ( $is_manager && ! empty( $direct_reports ) ) : ?>
                <div class="sfs-hr-emp-card">
                    <h3><?php esc_html_e( 'Direct Reports', 'sfs-hr' ); ?></h3>
                    <div class="sfs-hr-hierarchy-list">
                        <?php foreach ( $direct_reports as $report ) :
                            $report_name = trim( $report['first_name'] . ' ' . $report['last_name'] );
                            $report_url = admin_url( 'admin.php?page=sfs-hr-employee-profile&employee_id=' . $report['id'] );
                            $report_avatar = ! empty( $report['photo_id'] ) ? wp_get_attachment_image_url( $report['photo_id'], 'thumbnail' ) : null;
                        ?>
                            <a href="<?php echo esc_url( $report_url ); ?>" class="sfs-hr-hierarchy-item">
                                <span class="sfs-hr-hierarchy-avatar">
                                    <?php if ( $report_avatar ) : ?>
                                        <img src="<?php echo esc_url( $report_avatar ); ?>" alt="">
                                    <?php else : ?>
                                        <?php echo esc_html( strtoupper( substr( $report_name, 0, 1 ) ) ); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="sfs-hr-hierarchy-info">
                                    <span class="sfs-hr-hierarchy-name"><?php echo esc_html( $report_name ); ?></span>
                                    <?php if ( ! empty( $report['position'] ) ) : ?>
                                        <span class="sfs-hr-hierarchy-position"><?php echo esc_html( $report['position'] ); ?></span>
                                    <?php endif; ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php elseif ( $manager_info ) : ?>
                <div class="sfs-hr-emp-card">
                    <h3><?php esc_html_e( 'Reports To', 'sfs-hr' ); ?></h3>
                    <?php
                    $mgr_name = trim( $manager_info['first_name'] . ' ' . $manager_info['last_name'] );
                    $mgr_url = admin_url( 'admin.php?page=sfs-hr-employee-profile&employee_id=' . $manager_info['id'] );
                    $mgr_avatar = ! empty( $manager_info['photo_id'] ) ? wp_get_attachment_image_url( $manager_info['photo_id'], 'thumbnail' ) : null;
                    ?>
                    <a href="<?php echo esc_url( $mgr_url ); ?>" class="sfs-hr-hierarchy-item sfs-hr-hierarchy-single">
                        <span class="sfs-hr-hierarchy-avatar">
                            <?php if ( $mgr_avatar ) : ?>
                                <img src="<?php echo esc_url( $mgr_avatar ); ?>" alt="">
                            <?php else : ?>
                                <?php echo esc_html( strtoupper( substr( $mgr_name, 0, 1 ) ) ); ?>
                            <?php endif; ?>
                        </span>
                        <span class="sfs-hr-hierarchy-info">
                            <span class="sfs-hr-hierarchy-name"><?php echo esc_html( $mgr_name ); ?></span>
                            <?php if ( ! empty( $manager_info['position'] ) ) : ?>
                                <span class="sfs-hr-hierarchy-position"><?php echo esc_html( $manager_info['position'] ); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="sfs-hr-hierarchy-badge"><?php esc_html_e( 'Manager', 'sfs-hr' ); ?></span>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( $mode === 'edit' ) : ?>
            <?php submit_button( __( 'Save Changes', 'sfs-hr' ) ); ?>
        </form>
        <?php else : ?>

        <div class="sfs-hr-emp-month-wrap">
            <div class="sfs-hr-emp-month-header">
                <h2 class="sfs-hr-emp-month-title">
                    <?php echo esc_html( sprintf( __( 'Month summary – %s', 'sfs-hr' ), $month_meta['label'] ) ); ?>
                </h2>

                <div class="sfs-hr-emp-month-controls">
                    <form method="get" class="sfs-hr-emp-month-form">
                        <input type="hidden" name="page" value="sfs-hr-employee-profile" />
                        <input type="hidden" name="employee_id" value="<?php echo (int) $employee_id; ?>" />
                        <label for="sfs-hr-month-select" class="screen-reader-text">
                            <?php esc_html_e( 'Select month', 'sfs-hr' ); ?>
                        </label>
                        <select id="sfs-hr-month-select" name="ym" onchange="this.form.submit()">
                            <?php foreach ( $month_choices as $ym => $label_opt ) : ?>
                                <option value="<?php echo esc_attr( $ym ); ?>" <?php selected( $ym_param, $ym ); ?>>
                                    <?php echo esc_html( $label_opt ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <?php
                    $base_args = [
                        'page'        => 'sfs-hr-employee-profile',
                        'employee_id' => $employee_id,
                    ];
                    $prev_url = add_query_arg( array_merge( $base_args, [ 'ym' => $month_meta['prev_ym'] ] ), admin_url( 'admin.php' ) );
                    $next_url = add_query_arg( array_merge( $base_args, [ 'ym' => $month_meta['next_ym'] ] ), admin_url( 'admin.php' ) );
                    ?>
                    <div class="sfs-hr-emp-month-nav">
                        <a href="<?php echo esc_url( $prev_url ); ?>" class="button button-small">&laquo; <?php esc_html_e( 'Previous', 'sfs-hr' ); ?></a>
                        <a href="<?php echo esc_url( $next_url ); ?>" class="button button-small"><?php esc_html_e( 'Next', 'sfs-hr' ); ?> &raquo;</a>
                    </div>
                </div>
            </div>

            <?php if ( $month_data['total_days'] > 0 ) : ?>
                <div class="sfs-hr-emp-month-kpi-row">
                    <div class="sfs-hr-emp-month-kpi">
                        <span class="sfs-hr-emp-month-kpi-label"><?php esc_html_e( 'Present days', 'sfs-hr' ); ?></span>
                        <span class="sfs-hr-emp-month-kpi-value"><?php echo (int) $kpi['present_days']; ?></span>
                    </div>
                    <div class="sfs-hr-emp-month-kpi">
                        <span class="sfs-hr-emp-month-kpi-label"><?php esc_html_e( 'Not Clocked-IN days', 'sfs-hr' ); ?></span>
                        <span class="sfs-hr-emp-month-kpi-value"><?php echo (int) $kpi['not_in_days']; ?></span>
                    </div>
                    <div class="sfs-hr-emp-month-kpi">
                        <span class="sfs-hr-emp-month-kpi-label"><?php esc_html_e( 'Leave days', 'sfs-hr' ); ?></span>
                        <span class="sfs-hr-emp-month-kpi-value"><?php echo (int) $kpi['leave_days']; ?></span>
                    </div>
                    <div class="sfs-hr-emp-month-kpi">
                        <span class="sfs-hr-emp-month-kpi-label"><?php esc_html_e( 'Late days', 'sfs-hr' ); ?></span>
                        <span class="sfs-hr-emp-month-kpi-value"><?php echo (int) $kpi['late_days']; ?></span>
                    </div>
                    <div class="sfs-hr-emp-month-kpi">
                        <span class="sfs-hr-emp-month-kpi-label"><?php esc_html_e( 'Incomplete days', 'sfs-hr' ); ?></span>
                        <span class="sfs-hr-emp-month-kpi-value"><?php echo (int) $kpi['incomplete_days']; ?></span>
                    </div>
                    <div class="sfs-hr-emp-month-kpi">
                        <span class="sfs-hr-emp-month-kpi-label"><?php esc_html_e( 'Left early days', 'sfs-hr' ); ?></span>
                        <span class="sfs-hr-emp-month-kpi-value"><?php echo (int) $kpi['left_early_days']; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="sfs-hr-emp-month-layout">
                <div class="sfs-hr-emp-col sfs-hr-emp-col--left">
                    <div class="sfs-hr-emp-card">
                        <h3><?php esc_html_e( 'Status counts', 'sfs-hr' ); ?></h3>
                        <?php if ( empty( $month_data['counts'] ) ) : ?>
                            <p class="description"><?php esc_html_e( 'No attendance sessions for this month.', 'sfs-hr' ); ?></p>
                        <?php else : ?>
                            <table class="widefat striped sfs-hr-emp-month-table">
                                <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                                    <th style="width:80px; text-align:right;"><?php esc_html_e( 'Days', 'sfs-hr' ); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $month_data['counts'] as $st => $cnt ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $st ); ?></td>
                                        <td style="text-align:right;"><?php echo (int) $cnt; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                <tr>
                                    <th><?php esc_html_e( 'Total days with records', 'sfs-hr' ); ?></th>
                                    <th style="text-align:right;"><?php echo (int) $month_data['total_days']; ?></th>
                                </tr>
                                </tfoot>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sfs-hr-emp-col sfs-hr-emp-col--right">
                    <div class="sfs-hr-emp-card">
                        <h3><?php esc_html_e( 'Daily history', 'sfs-hr' ); ?></h3>
                        <?php if ( empty( $month_data['rows'] ) ) : ?>
                            <p class="description"><?php esc_html_e( 'No attendance sessions for this month.', 'sfs-hr' ); ?></p>
                        <?php else : ?>
                            <table class="widefat striped sfs-hr-emp-month-table">
                                <thead>
                                <tr>
                                    <th style="width:120px;"><?php esc_html_e( 'Date', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $month_data['rows'] as $row ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row['work_date'] ) ) ); ?></td>
                                        <td><?php echo esc_html( $row['status'] ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                        </div>
        </div>

        <?php endif; // view vs edit ?>

        <?php endif; // overview tab ?>

        <?php
        // Other tabs content (Leave, etc.)
        do_action( 'sfs_hr_employee_tab_content', $employee, $active_tab );
        ?>
        </div> <!-- .sfs-hr-employee-profile-inner -->
    </div>
    <?php
}




    protected function output_inline_styles(): void {
        static $done = false;
        if ( $done ) { return; }
        $done = true;
        ?>
        <style>
        #sfs-hr-employee-profile-wrap .sfs-hr-emp-basic-table .sfs-hr-emp-section-row th{
    padding-top:8px;
    padding-bottom:4px;
    border-top:1px solid #e2e4e7;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.03em;
    color:#555d66;
}
#sfs-hr-employee-profile-wrap .sfs-hr-emp-basic-table .sfs-hr-emp-section-row th:first-child{
    padding-left:0;
}

            #sfs-hr-employee-profile-wrap .sfs-hr-emp-name{
                margin-top:10px;
                margin-bottom:10px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-code-pill{
                display:inline-block;
                margin-left:6px;
                padding:2px 6px;
                border-radius:999px;
                background:#f3f4f5;
                font-size:11px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-layout{
                display:flex;
                flex-wrap:wrap;
                gap:16px;
                margin-top:10px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-layout{
                display:flex;
                flex-wrap:wrap;
                gap:16px;
                margin-top:10px;
                align-items:stretch;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-col{
                box-sizing:border-box;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-col--left{
                flex:1 1 320px;
                max-width:520px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-col--right{
                flex:1 1 260px;
                max-width:420px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-layout .sfs-hr-emp-col{
                display:flex;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-layout .sfs-hr-emp-card{
                flex:1 1 auto;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-card{
                background:#fff;
                border:1px solid #ccd0d4;
                box-shadow:0 1px 1px rgba(0,0,0,.04);
                padding:12px 14px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-card h3{
                margin-top:0;
                margin-bottom:8px;
                font-size:14px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-card--basic{
                min-height:0;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-basic-top{
                display:flex;
                align-items:center;
                margin-bottom:8px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-photo-wrap{
                width:72px;
                margin-right:10px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-photo{
                width:72px;
                height:72px;
                border-radius:50%;
                object-fit:cover;
                background:#f3f4f5;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-photo--empty{
                width:72px;
                height:72px;
                border-radius:50%;
                background:#f3f4f5;
                color:#777;
                display:flex;
                align-items:center;
                justify-content:center;
                font-size:10px;
                text-align:center;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-basic-table{
                width:100%;
                border-collapse:collapse;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-basic-table th{
                text-align:left;
                width:34%;
                padding:3px 0;
                font-weight:600;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-basic-table td{
                padding:3px 0;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-today-table{
                width:100%;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-today-table th{
                text-align:left;
                width:30%;
                padding:4px 0;
                font-weight:600;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-today-table td{
                padding:4px 0;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-wrap{
                margin-top:25px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-header{
                display:flex;
                flex-wrap:wrap;
                align-items:center;
                gap:8px;
                margin-bottom:8px;
                justify-content:flex-start;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-title{
                margin:0;
                font-size:16px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-controls{
                display:flex;
                align-items:center;
                gap:6px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-form select{
                min-width:160px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-kpi-row{
                display:flex;
                flex-wrap:wrap;
                gap:12px;
                margin-bottom:8px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-kpi{
                flex:1 1 120px;
                padding:6px 8px;
                border-radius:4px;
                background:#f8f9fa;
                border:1px solid #e2e4e7;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-kpi-label{
                display:block;
                font-size:11px;
                color:#555d66;
                margin-bottom:2px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-kpi-value{
                font-size:15px;
                font-weight:600;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-table th,
            #sfs-hr-employee-profile-wrap .sfs-hr-emp-month-table td{
                font-size:12px;
                padding:4px 6px;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-pill{
                display:inline-block;
                padding:2px 8px;
                border-radius:999px;
                font-size:11px;
                font-weight:500;
                line-height:1.6;
                border:1px solid rgba(0,0,0,.05);
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-pill--status-in{
                background:#46b4501a;
                color:#008a20;
                border-color:#46b4504d;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-pill--status-break{
                background:#ffb9001a;
                color:#aa7a00;
                border-color:#ffb90066;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-pill--status-out{
                background:#ccd0d41a;
                color:#555d66;
                border-color:#ccd0d4;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-pill--status-notin{
                background:#f1f1f1;
                color:#777;
                border-color:#e2e4e7;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-pill--leave-duty{
                background:#46b4500f;
                color:#008a20;
                border-color:#46b45040;
            }
            #sfs-hr-employee-profile-wrap .sfs-hr-pill--leave-on{
                background:#0073aa14;
                color:#005177;
                border-color:#0073aa40;
            }

            /* Hierarchy styles */
            .sfs-hr-hierarchy-list {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .sfs-hr-hierarchy-item {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 6px 10px;
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                text-decoration: none;
                color: inherit;
                transition: background 0.15s, border-color 0.15s;
            }
            .sfs-hr-hierarchy-item:hover {
                background: #fff;
                border-color: #2271b1;
            }
            .sfs-hr-hierarchy-single {
                background: #fff;
            }
            .sfs-hr-hierarchy-avatar {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                background: #2271b1;
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 13px;
                font-weight: 600;
                flex-shrink: 0;
                overflow: hidden;
            }
            .sfs-hr-hierarchy-avatar img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .sfs-hr-hierarchy-info {
                display: flex;
                flex-direction: column;
                gap: 2px;
                min-width: 0;
            }
            .sfs-hr-hierarchy-name {
                font-size: 13px;
                font-weight: 500;
                color: #1d2327;
            }
            .sfs-hr-hierarchy-position {
                font-size: 11px;
                color: #646970;
            }
            .sfs-hr-hierarchy-badge {
                margin-left: auto;
                background: #2271b1;
                color: #fff;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: 500;
            }
        </style>
        <?php
    }

    /* ====== Snapshot, month, helpers ====== */

    protected function get_today_snapshot( int $employee_id, string $today_ymd ): array {
        $status_key   = 'not_clocked_in';
        $status_label = __( 'Not Clocked-IN', 'sfs-hr' );
        $since        = null;
        $last_punch   = null;

        $last = $this->get_today_last_punch( $employee_id, $today_ymd );
        if ( $last ) {
            $status_key   = $this->compute_status_from_punch( $last['punch_type'] );
            $status_label = $this->status_label_from_key( $status_key );
            $since        = $last['punch_time'];
            $last_punch   = $last['punch_time'];
        }

        $leave_label = $this->get_today_leave_label( $employee_id, $today_ymd );

        return [
            'status_key'   => $status_key,
            'status_label' => $status_label,
            'since'        => $since,
            'leave_label'  => $leave_label,
            'last_punch'   => $last_punch,
        ];
    }

    protected function status_label_from_key( string $key ): string {
        switch ( $key ) {
            case 'clocked_in':
                return __( 'Clocked in', 'sfs-hr' );
            case 'on_break':
                return __( 'On break', 'sfs-hr' );
            case 'clocked_out':
                return __( 'Clocked out', 'sfs-hr' );
            case 'not_clocked_in':
            default:
                return __( 'Not Clocked-IN', 'sfs-hr' );
        }
    }

    protected function get_today_last_punch( int $employee_id, string $today_ymd ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_punches';

        $tz          = wp_timezone();
        $start_local = new \DateTimeImmutable( $today_ymd . ' 00:00:00', $tz );
        $end_local   = $start_local->modify( '+1 day' );
        $start_utc   = $start_local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $end_utc     = $end_local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

        $sql = "SELECT punch_type, punch_time
                FROM {$table}
                WHERE employee_id = %d
                  AND punch_time >= %s AND punch_time < %s
                ORDER BY punch_time ASC";

        $rows = $wpdb->get_results(
            $wpdb->prepare( $sql, $employee_id, $start_utc, $end_utc ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return null;
        }

        return end( $rows );
    }

    protected function compute_status_from_punch( ?string $punch_type ): string {
        switch ( $punch_type ) {
            case 'break_start':
                return 'on_break';
            case 'in':
            case 'break_end':
                return 'clocked_in';
            case 'out':
                return 'clocked_out';
            default:
                return 'not_clocked_in';
        }
    }

    protected function get_today_leave_label( int $employee_id, string $today ): string {
        global $wpdb;
        $req_t  = $wpdb->prefix . 'sfs_hr_leave_requests';
        $type_t = $wpdb->prefix . 'sfs_hr_leave_types';

        $sql = "SELECT t.name AS type_name
                FROM {$req_t} r
                JOIN {$type_t} t ON t.id = r.type_id
                WHERE r.status = 'approved'
                  AND r.employee_id = %d
                  AND r.start_date <= %s
                  AND r.end_date >= %s
                LIMIT 1";

        $row = $wpdb->get_row(
            $wpdb->prepare( $sql, $employee_id, $today, $today ),
            ARRAY_A
        );

        if ( ! $row ) {
            return __( 'On duty', 'sfs-hr' );
        }

        return sprintf( __( 'On leave (%s)', 'sfs-hr' ), $row['type_name'] );
    }

    protected function get_risk_flag_for_employee( int $employee_id, string $today_ymd ): string {
        global $wpdb;
        $sT = $wpdb->prefix . 'sfs_hr_attendance_sessions';

        $end      = $today_ymd;
        $start_ts = strtotime( $today_ymd . ' -' . ( self::RISK_LOOKBACK_DAYS - 1 ) . ' days' );
        $start    = date( 'Y-m-d', $start_ts ?: time() );

        $sql = "SELECT status
                FROM {$sT}
                WHERE employee_id = %d
                  AND work_date BETWEEN %s AND %s";

        $rows = $wpdb->get_results(
            $wpdb->prepare( $sql, $employee_id, $start, $end ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return '';
        }

        $late        = 0;
        $presenceBad = 0;
        $leave       = 0;

        foreach ( $rows as $r ) {
            $status = (string) $r['status'];
            switch ( $status ) {
                case 'late':
                    $late++;
                    break;
                case 'absent':
                case 'incomplete':
                case 'left_early':
                    $presenceBad++;
                    break;
                case 'on_leave':
                    $leave++;
                    break;
                default:
                    break;
            }
        }

        $flags = [];
        if ( $late >= self::RISK_LATE_MIN_DAYS ) {
            $flags[] = __( 'High lateness', 'sfs-hr' );
        }
        if ( $presenceBad >= self::RISK_LOW_PRES_MIN_DAYS ) {
            $flags[] = __( 'Low presence', 'sfs-hr' );
        }
        if ( $leave >= self::RISK_LEAVE_MIN_DAYS ) {
            $flags[] = __( 'Frequent leave', 'sfs-hr' );
        }

        return implode( ', ', $flags );
    }
    
    
    protected function render_assets_card( int $employee_id ): void {
    global $wpdb;

    // Ensure badge CSS is printed once.
    Helpers::output_asset_status_badge_css();

    $assets_table = $wpdb->prefix . 'sfs_hr_assets';
    $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';

    // Make sure assets table exists; if not, bail silently.
    $assets_exists = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) 
             FROM information_schema.tables 
             WHERE table_schema = DATABASE() 
               AND table_name = %s",
            $assets_table
        )
    );
    if ( ! $assets_exists ) {
        return;
    }

    // Also make sure assignments table exists.
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

    $rows = $wpdb->get_results(
        $wpdb->prepare("
            SELECT 
                a.*,
                ast.id         AS asset_id,
                ast.name       AS asset_name,
                ast.asset_code AS asset_code
            FROM {$assign_table} a
            LEFT JOIN {$assets_table} ast ON ast.id = a.asset_id
            WHERE a.employee_id = %d
            ORDER BY a.created_at DESC
            LIMIT 20
        ", $employee_id )
    );

    // ---- Counters ----
    $active           = 0;
    $pending          = 0;
    $return_requested = 0;
    $returned         = 0;
    $rejected         = 0;

    foreach ( $rows as $r ) {
        $st = (string) $r->status;

        if ( in_array( $st, [ 'pending_employee_approval', 'active', 'return_requested' ], true ) ) {
            $active++;
        }

        switch ( $st ) {
            case 'pending_employee_approval':
                $pending++;
                break;
            case 'return_requested':
                $return_requested++;
                break;
            case 'returned':
                $returned++;
                break;
            case 'rejected':
                $rejected++;
                break;
        }
    }

    echo '<div class="sfs-hr-emp-card">';
    echo '<h3>' . esc_html__( 'Assets', 'sfs-hr' ) . '</h3>';

    if ( empty( $rows ) ) {
        echo '<p class="description">' . esc_html__( 'No assets assigned.', 'sfs-hr' ) . '</p>';
        echo '</div>';
        return;
    }

    // Main summary line
    echo '<p>';
    printf(
        esc_html__( 'Currently %1$d active asset(s), %2$d total assignments.', 'sfs-hr' ),
        (int) $active,
        (int) count( $rows )
    );
    echo '</p>';

    // Tiny label: Pending / Return requested / Returned / Rejected
    $bits = [];

    if ( $pending > 0 ) {
        $bits[] = sprintf(
            esc_html__( 'Pending: %d', 'sfs-hr' ),
            (int) $pending
        );
    }
    if ( $return_requested > 0 ) {
        $bits[] = sprintf(
            esc_html__( 'Return requested: %d', 'sfs-hr' ),
            (int) $return_requested
        );
    }
    if ( $returned > 0 ) {
        $bits[] = sprintf(
            esc_html__( 'Returned: %d', 'sfs-hr' ),
            (int) $returned
        );
    }
    if ( $rejected > 0 ) {
        $bits[] = sprintf(
            esc_html__( 'Rejected: %d', 'sfs-hr' ),
            (int) $rejected
        );
    }

    echo '<p class="description" style="margin-top:2px;">';
    if ( ! empty( $bits ) ) {
        echo esc_html( implode( ' · ', $bits ) );
    } else {
        echo esc_html__( 'No pending or historical actions.', 'sfs-hr' );
    }
    echo '</p>';

    // Table
    echo '<table class="widefat striped" style="margin-top:8px;">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__( 'Asset', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Code', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Start', 'sfs-hr' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $rows as $row ) {
        echo '<tr>';

        // Asset name → link to asset edit
        $asset_label = $row->asset_name ?: '';
        if ( $row->asset_id ) {
            $edit_url = add_query_arg(
                [
                    'page' => 'sfs-hr-assets',
                    'tab'  => 'assets',
                    'view' => 'edit',
                    'id'   => (int) $row->asset_id,
                ],
                admin_url( 'admin.php' )
            );
            echo '<td><a href="' . esc_url( $edit_url ) . '">'
                 . esc_html( $asset_label ) . '</a></td>';
        } else {
            echo '<td>' . esc_html( $asset_label ) . '</td>';
        }

        // Asset code
        echo '<td>' . esc_html( $row->asset_code ?? '' ) . '</td>';

        // Status badge (centralized helper)
        echo '<td>' . Helpers::asset_status_badge( (string) $row->status ) . '</td>';

        // Start date
        echo '<td>' . esc_html( $row->start_date ?: '' ) . '</td>';

        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}





    protected function get_month_meta( string $ym ): array {
        $tz  = wp_timezone();
        $dt  = \DateTimeImmutable::createFromFormat( 'Y-m-d', $ym . '-01', $tz );
        if ( ! $dt ) {
            $dt = new \DateTimeImmutable( 'first day of this month', $tz );
        }

        $start = $dt->format( 'Y-m-01' );
        $end   = $dt->format( 'Y-m-t' );
        $label = date_i18n( 'F Y', $dt->getTimestamp() );

        $prev = $dt->modify( '-1 month' );
        $next = $dt->modify( '+1 month' );

        return [
            'ym'      => $dt->format( 'Y-m' ),
            'start'   => $start,
            'end'     => $end,
            'label'   => $label,
            'prev_ym' => $prev->format( 'Y-m' ),
            'next_ym' => $next->format( 'Y-m' ),
        ];
    }

    /**
     * Month summary + KPI for one employee over [start,end].
     */
    protected function get_month_summary_for_employee( int $employee_id, string $start, string $end ): array {
        global $wpdb;
        $sT = $wpdb->prefix . 'sfs_hr_attendance_sessions';

        $sql = "SELECT work_date, status
                FROM {$sT}
                WHERE employee_id = %d
                  AND work_date BETWEEN %s AND %s
                ORDER BY work_date DESC";

        $rows = $wpdb->get_results(
            $wpdb->prepare( $sql, $employee_id, $start, $end ),
            ARRAY_A
        );

        $counts = [];
        $kpi = [
            'present_days'     => 0,
            'not_in_days'      => 0,
            'leave_days'       => 0,
            'late_days'        => 0,
            'incomplete_days'  => 0,
            'left_early_days'  => 0,
        ];

        foreach ( $rows as $r ) {
            $st = (string) $r['status'];
            if ( $st === '' ) {
                $st = '(unknown)';
            }

            // raw counts
            if ( ! isset( $counts[ $st ] ) ) {
                $counts[ $st ] = 0;
            }
            $counts[ $st ]++;

            // KPI mapping
            switch ( $st ) {
                case 'present':
                    $kpi['present_days']++;
                    break;

                case 'late':
                    $kpi['present_days']++;
                    $kpi['late_days']++;
                    break;

                case 'left_early':
                    $kpi['present_days']++;
                    $kpi['left_early_days']++;
                    break;

                case 'incomplete':
                    $kpi['present_days']++;
                    $kpi['incomplete_days']++;
                    break;

                case 'on_leave':
                    $kpi['leave_days']++;
                    break;

                case 'not_clocked_in':
                case 'absent':
                    $kpi['not_in_days']++;
                    break;

                default:
                    // ignore for KPI; still counted in raw table
                    break;
            }
        }

        ksort( $counts );

        return [
            'counts'     => $counts,
            'rows'       => $rows,
            'total_days' => count( $rows ),
            'kpi'        => $kpi,
        ];
    }

    protected function render_status_badge( string $status_key, string $label ): string {
        switch ( $status_key ) {
            case 'clocked_in':
                $class = 'sfs-hr-pill--status-in';
                break;
            case 'on_break':
                $class = 'sfs-hr-pill--status-break';
                break;
            case 'clocked_out':
                $class = 'sfs-hr-pill--status-out';
                break;
            case 'not_clocked_in':
            default:
                $class = 'sfs-hr-pill--status-notin';
                break;
        }

        return '<span class="sfs-hr-pill ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
    }

    protected function render_leave_badge( string $label ): string {
        $is_on_leave = ( stripos( $label, 'On leave' ) === 0 );
        $class       = $is_on_leave ? 'sfs-hr-pill--leave-on' : 'sfs-hr-pill--leave-duty';

        return '<span class="sfs-hr-pill ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
    }

    protected function format_time( ?string $mysql ): string {
        if ( ! $mysql ) {
            return '—';
        }
        $ts = strtotime( $mysql );
        if ( ! $ts ) {
            return '—';
        }
        return date_i18n( get_option( 'time_format' ), $ts );
    }

    protected function manager_dept_ids_for_current_user(): array {
        $uid = get_current_user_id();
        if ( ! $uid ) {
            return [];
        }

        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$dept_table} WHERE manager_user_id=%d AND active=1",
                $uid
            )
        );

        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * Render Documents summary card for employee profile
     */
    protected function render_documents_card( int $employee_id ): void {
        global $wpdb;

        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';

        // Check if documents table exists
        $table_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $doc_table
            )
        );

        if ( ! $table_exists ) {
            return;
        }

        // Get document counts by type
        $documents = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT document_type, COUNT(*) as count, MIN(expiry_date) as earliest_expiry
                 FROM {$doc_table}
                 WHERE employee_id = %d AND status = 'active'
                 GROUP BY document_type
                 ORDER BY count DESC",
                $employee_id
            ),
            ARRAY_A
        );

        $total_docs = 0;
        $expiring_soon = 0;
        $expired = 0;
        $today = wp_date( 'Y-m-d' );
        $thirty_days = wp_date( 'Y-m-d', strtotime( '+30 days' ) );

        // Check for expiring/expired documents
        $expiry_check = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT expiry_date FROM {$doc_table}
                 WHERE employee_id = %d AND status = 'active' AND expiry_date IS NOT NULL",
                $employee_id
            ),
            ARRAY_A
        );

        foreach ( $expiry_check as $row ) {
            if ( $row['expiry_date'] < $today ) {
                $expired++;
            } elseif ( $row['expiry_date'] <= $thirty_days ) {
                $expiring_soon++;
            }
        }

        foreach ( $documents as $doc ) {
            $total_docs += (int) $doc['count'];
        }

        $doc_types = \SFS\HR\Modules\Documents\DocumentsModule::get_document_types();

        $docs_tab_url = add_query_arg(
            [
                'page'        => 'sfs-hr-employee-profile',
                'employee_id' => $employee_id,
                'tab'         => 'documents',
            ],
            admin_url( 'admin.php' )
        );

        echo '<div class="sfs-hr-emp-card">';
        echo '<h3>' . esc_html__( 'Documents', 'sfs-hr' ) . '</h3>';

        if ( empty( $documents ) ) {
            echo '<p class="description">' . esc_html__( 'No documents uploaded.', 'sfs-hr' ) . '</p>';
        } else {
            echo '<p>';
            printf(
                esc_html__( '%d document(s) on file.', 'sfs-hr' ),
                (int) $total_docs
            );
            echo '</p>';

            // Warnings for expiring/expired
            if ( $expired > 0 ) {
                echo '<p style="color:#dc2626; margin:4px 0;">';
                printf(
                    esc_html__( '%d document(s) expired!', 'sfs-hr' ),
                    (int) $expired
                );
                echo '</p>';
            }
            if ( $expiring_soon > 0 ) {
                echo '<p style="color:#d97706; margin:4px 0;">';
                printf(
                    esc_html__( '%d document(s) expiring within 30 days.', 'sfs-hr' ),
                    (int) $expiring_soon
                );
                echo '</p>';
            }

            // Show type breakdown
            echo '<p class="description" style="margin-top:6px;">';
            $bits = [];
            foreach ( $documents as $doc ) {
                $type_label = $doc_types[ $doc['document_type'] ] ?? ucfirst( str_replace( '_', ' ', $doc['document_type'] ) );
                $bits[] = $type_label . ': ' . (int) $doc['count'];
            }
            echo esc_html( implode( ' · ', $bits ) );
            echo '</p>';
        }

        echo '<p style="margin-top:10px;">';
        echo '<a href="' . esc_url( $docs_tab_url ) . '" class="button button-small">';
        esc_html_e( 'View Documents', 'sfs-hr' );
        echo '</a>';
        echo '</p>';

        echo '</div>';
    }

    /**
     * Render Employee Requests card showing all employee requests with their status
     */
    protected function render_requests_card( int $employee_id ): void {
        global $wpdb;

        // Collect all requests
        $requests = [];

        // ---- Leave Requests ----
        $leave_table = $wpdb->prefix . 'sfs_hr_leave_requests';
        $leave_types_table = $wpdb->prefix . 'sfs_hr_leave_types';

        $leave_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $leave_table
            )
        );

        if ( $leave_exists ) {
            $leaves = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT lr.id, lr.start_date, lr.end_date, lr.days, lr.status, lr.created_at, lr.approval_level,
                            lt.name as type_name
                     FROM {$leave_table} lr
                     LEFT JOIN {$leave_types_table} lt ON lt.id = lr.type_id
                     WHERE lr.employee_id = %d
                     ORDER BY lr.created_at DESC
                     LIMIT 10",
                    $employee_id
                ),
                ARRAY_A
            );

            foreach ( $leaves as $leave ) {
                $status_key = $leave['status'];
                if ( $status_key === 'pending' ) {
                    $level = (int) ( $leave['approval_level'] ?? 1 );
                    if ( $level >= 3 ) {
                        $status_key = 'pending_finance';
                    } elseif ( $level >= 2 ) {
                        $status_key = 'pending_hr';
                    } else {
                        $status_key = 'pending_manager';
                    }
                }

                $requests[] = [
                    'type'       => 'leave',
                    'type_label' => __( 'Leave', 'sfs-hr' ),
                    'detail'     => $leave['type_name'] ?? __( 'Leave', 'sfs-hr' ),
                    'dates'      => $leave['start_date'] . ' - ' . $leave['end_date'],
                    'status'     => $status_key,
                    'created_at' => $leave['created_at'],
                    'url'        => admin_url( 'admin.php?page=sfs-hr-leave-requests&action=view&id=' . (int) $leave['id'] ),
                ];
            }
        }

        // ---- Loan Requests ----
        $loan_table = $wpdb->prefix . 'sfs_hr_loans';

        $loan_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $loan_table
            )
        );

        if ( $loan_exists ) {
            $loans = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, loan_number, principal_amount, remaining_balance, status, created_at
                     FROM {$loan_table}
                     WHERE employee_id = %d
                     ORDER BY created_at DESC
                     LIMIT 10",
                    $employee_id
                ),
                ARRAY_A
            );

            foreach ( $loans as $loan ) {
                $requests[] = [
                    'type'       => 'loan',
                    'type_label' => __( 'Loan', 'sfs-hr' ),
                    'detail'     => $loan['loan_number'] . ' - ' . number_format( (float) $loan['principal_amount'], 2 ),
                    'dates'      => '',
                    'status'     => $loan['status'],
                    'created_at' => $loan['created_at'],
                    'url'        => admin_url( 'admin.php?page=sfs-hr-loans&action=view&id=' . (int) $loan['id'] ),
                ];
            }
        }

        // ---- Resignation Requests ----
        $resignation_table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignation_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $resignation_table
            )
        );

        if ( $resignation_exists ) {
            $resignations = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, resignation_type, resignation_date, last_working_day, status, approval_level, created_at
                     FROM {$resignation_table}
                     WHERE employee_id = %d
                     ORDER BY created_at DESC
                     LIMIT 5",
                    $employee_id
                ),
                ARRAY_A
            );

            foreach ( $resignations as $res ) {
                $status_key = $res['status'];
                if ( $status_key === 'pending' ) {
                    $level = (int) ( $res['approval_level'] ?? 1 );
                    if ( $level >= 4 ) {
                        $status_key = 'pending_finance';
                    } elseif ( $level >= 3 ) {
                        $status_key = 'pending_gm';
                    } elseif ( $level >= 2 ) {
                        $status_key = 'pending_hr';
                    } else {
                        $status_key = 'pending_manager';
                    }
                }

                $type_label = $res['resignation_type'] === 'final_exit'
                    ? __( 'Final Exit', 'sfs-hr' )
                    : __( 'Regular', 'sfs-hr' );

                $requests[] = [
                    'type'       => 'resignation',
                    'type_label' => __( 'Resignation', 'sfs-hr' ),
                    'detail'     => $type_label,
                    'dates'      => $res['resignation_date'] . ' - ' . ( $res['last_working_day'] ?: __( 'TBD', 'sfs-hr' ) ),
                    'status'     => $status_key,
                    'created_at' => $res['created_at'],
                    'url'        => admin_url( 'admin.php?page=sfs-hr-resignations&tab=resignations' ),
                ];
            }
        }

        // ---- Settlement Requests ----
        $settlement_table = $wpdb->prefix . 'sfs_hr_settlements';

        $settlement_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $settlement_table
            )
        );

        if ( $settlement_exists ) {
            $settlements = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, total_settlement, last_working_day, status, created_at
                     FROM {$settlement_table}
                     WHERE employee_id = %d
                     ORDER BY created_at DESC
                     LIMIT 5",
                    $employee_id
                ),
                ARRAY_A
            );

            foreach ( $settlements as $set ) {
                $requests[] = [
                    'type'       => 'settlement',
                    'type_label' => __( 'Settlement', 'sfs-hr' ),
                    'detail'     => number_format( (float) $set['total_settlement'], 2 ),
                    'dates'      => $set['last_working_day'] ?: '',
                    'status'     => $set['status'],
                    'created_at' => $set['created_at'],
                    'url'        => admin_url( 'admin.php?page=sfs-hr-settlements&action=view&id=' . (int) $set['id'] ),
                ];
            }
        }

        // Sort all requests by created_at descending
        usort( $requests, function( $a, $b ) {
            return strtotime( $b['created_at'] ) - strtotime( $a['created_at'] );
        } );

        // Take only the most recent 10
        $requests = array_slice( $requests, 0, 10 );

        // Render the card
        echo '<div class="sfs-hr-emp-card">';
        echo '<h3>' . esc_html__( 'Recent Requests', 'sfs-hr' ) . '</h3>';

        if ( empty( $requests ) ) {
            echo '<p class="description">' . esc_html__( 'No requests found.', 'sfs-hr' ) . '</p>';
        } else {
            echo '<table class="widefat striped" style="margin-top:8px;font-size:12px;">';
            echo '<thead><tr>';
            echo '<th style="width:90px;">' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
            echo '<th>' . esc_html__( 'Details', 'sfs-hr' ) . '</th>';
            echo '<th style="width:90px;">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $requests as $req ) {
                $status_badge = $this->get_request_status_badge( $req['status'] );
                $type_badge = $this->get_request_type_badge( $req['type'] );

                echo '<tr>';
                echo '<td>' . $type_badge . '</td>';
                echo '<td>';
                echo '<a href="' . esc_url( $req['url'] ) . '" style="color:#2271b1;text-decoration:none;font-weight:500;">';
                echo esc_html( $req['detail'] );
                echo '</a>';
                if ( $req['dates'] ) {
                    echo '<br><small class="description">' . esc_html( $req['dates'] ) . '</small>';
                }
                echo '</td>';
                echo '<td>' . $status_badge . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    /**
     * Get status badge HTML for requests
     */
    protected function get_request_status_badge( string $status ): string {
        $status_colors = [
            'pending'         => [ 'bg' => '#fff3e0', 'color' => '#e65100' ],
            'pending_manager' => [ 'bg' => '#fff3e0', 'color' => '#e65100' ],
            'pending_hr'      => [ 'bg' => '#fff3e0', 'color' => '#e65100' ],
            'pending_gm'      => [ 'bg' => '#fff3e0', 'color' => '#e65100' ],
            'pending_finance' => [ 'bg' => '#fff3e0', 'color' => '#e65100' ],
            'approved'        => [ 'bg' => '#e8f5e9', 'color' => '#2e7d32' ],
            'active'          => [ 'bg' => '#e8f5e9', 'color' => '#2e7d32' ],
            'completed'       => [ 'bg' => '#e3f2fd', 'color' => '#1565c0' ],
            'paid'            => [ 'bg' => '#e3f2fd', 'color' => '#1565c0' ],
            'rejected'        => [ 'bg' => '#ffebee', 'color' => '#c62828' ],
            'cancelled'       => [ 'bg' => '#fafafa', 'color' => '#757575' ],
        ];

        $status_labels = [
            'pending'         => __( 'Pending', 'sfs-hr' ),
            'pending_manager' => __( 'Pending Mgr', 'sfs-hr' ),
            'pending_hr'      => __( 'Pending HR', 'sfs-hr' ),
            'pending_gm'      => __( 'Pending GM', 'sfs-hr' ),
            'pending_finance' => __( 'Pending Fin', 'sfs-hr' ),
            'approved'        => __( 'Approved', 'sfs-hr' ),
            'active'          => __( 'Active', 'sfs-hr' ),
            'completed'       => __( 'Completed', 'sfs-hr' ),
            'paid'            => __( 'Paid', 'sfs-hr' ),
            'rejected'        => __( 'Rejected', 'sfs-hr' ),
            'cancelled'       => __( 'Cancelled', 'sfs-hr' ),
        ];

        $colors = $status_colors[ $status ] ?? [ 'bg' => '#f5f5f5', 'color' => '#666' ];
        $label = $status_labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );

        return sprintf(
            '<span style="display:inline-block;padding:2px 6px;border-radius:10px;font-size:10px;font-weight:500;background:%s;color:%s;">%s</span>',
            esc_attr( $colors['bg'] ),
            esc_attr( $colors['color'] ),
            esc_html( $label )
        );
    }

    /**
     * Get type badge HTML for requests
     */
    protected function get_request_type_badge( string $type ): string {
        $type_colors = [
            'leave'       => [ 'bg' => '#e3f2fd', 'color' => '#1565c0' ],
            'loan'        => [ 'bg' => '#fce4ec', 'color' => '#c2185b' ],
            'resignation' => [ 'bg' => '#fff3e0', 'color' => '#e65100' ],
            'settlement'  => [ 'bg' => '#e8f5e9', 'color' => '#2e7d32' ],
        ];

        $type_labels = [
            'leave'       => __( 'Leave', 'sfs-hr' ),
            'loan'        => __( 'Loan', 'sfs-hr' ),
            'resignation' => __( 'Resign', 'sfs-hr' ),
            'settlement'  => __( 'Settle', 'sfs-hr' ),
        ];

        $colors = $type_colors[ $type ] ?? [ 'bg' => '#f5f5f5', 'color' => '#666' ];
        $label = $type_labels[ $type ] ?? ucfirst( $type );

        return sprintf(
            '<span style="display:inline-block;padding:2px 6px;border-radius:10px;font-size:10px;font-weight:500;background:%s;color:%s;">%s</span>',
            esc_attr( $colors['bg'] ),
            esc_attr( $colors['color'] ),
            esc_html( $label )
        );
    }
}
