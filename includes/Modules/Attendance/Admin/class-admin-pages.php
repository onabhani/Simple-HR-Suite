<?php
namespace SFS\HR\Modules\Attendance\Admin;

use SFS\HR\Modules\Attendance\AttendanceModule;
use SFS\HR\Core\Helpers;


if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin_Pages
 * Version: 0.1.1-admin-crud
 * Author: Omar Alnabhani (hdqah.com)
 *
 * Menus:
 * - HR Attendance (root)
 *   - Settings
 *   - Shifts
 *   - Assignments (bulk; handles Ramadan)
 *   - Devices (Kiosk)
 *
 * Security:
 * - All actions require 'sfs_hr_attendance_admin'
 * - Nonce on every form
 * - Sanitize/validate every field
 */
class Admin_Pages {

    public function hooks(): void {
    // Run after the core HR menu (which uses the same parent slug).
    add_action( 'admin_menu', [ $this, 'menu' ], 20 );

    // Handlers ...
    add_action( 'admin_post_sfs_hr_att_save_settings', [ $this, 'handle_save_settings' ] );
    add_action( 'admin_post_sfs_hr_att_shift_save',    [ $this, 'handle_shift_save' ] );
    add_action( 'admin_post_sfs_hr_att_shift_delete',  [ $this, 'handle_shift_delete' ] );
    add_action( 'admin_post_sfs_hr_att_assign_bulk',   [ $this, 'handle_assign_bulk' ] );
    add_action( 'admin_post_sfs_hr_att_device_save',   [ $this, 'handle_device_save' ] );
    add_action( 'admin_post_sfs_hr_att_device_delete', [ $this, 'handle_device_delete' ] );
    add_action( 'admin_post_sfs_hr_att_save_automation', [ $this, 'handle_save_automation' ] );
    add_action( 'admin_post_sfs_hr_att_export_csv', [ $this, 'handle_export_csv' ] );
    add_action( 'admin_post_sfs_hr_att_rebuild_sessions_day', [ $this, 'handle_rebuild_sessions_day' ] );
    add_action( 'admin_post_sfs_hr_att_save_auto_rules', [ $this, 'handle_save_auto_rules' ] );
    add_action( 'admin_post_sfs_hr_att_run_auto_rules', [ $this, 'handle_run_auto_rules' ] );
    add_action( 'admin_post_sfs_hr_att_delete_auto_rule', [ $this, 'handle_delete_auto_rule' ] );
    add_action( 'admin_post_sfs_hr_att_save_template', [ $this, 'handle_save_template' ] );
    add_action( 'admin_post_sfs_hr_att_delete_template', [ $this, 'handle_delete_template' ] );
    add_action( 'admin_post_sfs_hr_att_apply_template', [ $this, 'handle_apply_template' ] );

    // Auto-assign on new employee
    add_action( 'sfs_hr_employee_created', [ $this, 'auto_assign_on_employee_created' ], 10, 2 );
}

    
    /**
 * Submenu callback for: admin.php?page=sfs_hr_attendance_devices
 * Reuses the main Attendance hub UI but forces the "Devices" tab.
 */



public function menu(): void {
    // only show these menus to users who have the attendance admin cap
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { return; }

    $parent = 'sfs-hr'; // your HR top-level menu slug

    // Hub (tabs: Settings, Automation, Shifts, Assignments, Exceptions, Devices, Punches, Sessions)
    add_submenu_page(
        $parent,
        __('Attendance','sfs-hr'),
        __('Attendance','sfs-hr'),
        'sfs_hr_attendance_admin',
        'sfs_hr_attendance',
        [ $this, 'render_attendance_hub' ]
    );

    
}


// Detect employee columns across schema variants.
private function detect_employee_columns( \wpdb $wpdb ): array {
    $t = $wpdb->prefix . 'sfs_hr_employees';
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}", 0 ) ?: [];
    $has  = static fn($k) => in_array($k, $cols, true);

    $id        = 'id';
    $name      = $has('full_name') ? 'full_name' : ( $has('name') ? 'name' : null );
    $dept_slug = $has('dept') ? 'dept' : ( $has('department') ? 'department' : null );
    $dept_id   = $has('department_id') ? 'department_id' : null;

    return compact('id','name','dept_slug','dept_id');
}

// Resolve a readable department label for an employee row.
private function dept_label_from_employee( \wpdb $wpdb, $row, array $map ): string {
    if ( $map['dept_slug'] && isset($row->{$map['dept_slug']}) && $row->{$map['dept_slug']} !== '' ) {
        return (string) $row->{$map['dept_slug']};
    }
    if ( $map['dept_id'] && isset($row->{$map['dept_id']}) ) {
        $deptT = $wpdb->prefix . 'sfs_hr_departments';
        $name  = $wpdb->get_var( $wpdb->prepare("SELECT name FROM {$deptT} WHERE id=%d", (int)$row->{$map['dept_id']}) );
        if ( $name ) { return (string)$name; }
    }
    return 'unknown';
}

// Fetch departments (id, name) for the filter.
private function get_departments( \wpdb $wpdb ): array {
    $deptT = $wpdb->prefix . 'sfs_hr_departments';
    $rows  = $wpdb->get_results( "SHOW TABLES LIKE '{$deptT}'" );
    if ( empty($rows) ) { return []; }
    $list  = $wpdb->get_results( "SELECT id, name FROM {$deptT} ORDER BY name", ARRAY_A ) ?: [];
    return $list;
}

private function get_active_shifts_indexed( $wpdb ): array {
    $t = $wpdb->prefix . 'sfs_hr_attendance_shifts';
    $rows = $wpdb->get_results( "SELECT id, name, dept_id, location_label, start_time, end_time FROM {$t} WHERE active=1 ORDER BY dept_id, name", ARRAY_A ) ?: [];
    $out = [];
    foreach ( $rows as $r ) {
        $dept_id = (int) ( $r['dept_id'] ?? 0 );
        if ( ! isset( $out[ $dept_id ] ) ) {
            $out[ $dept_id ] = [];
        }
        $out[ $dept_id ][] = $r;
    }
    return $out;
}


private function get_table_columns( $table ): array {
    global $wpdb;
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ) ?: [];
    return array_map('strval', $cols);
}




    /* ========================= SETTINGS ========================= */

    public function render_settings(): void {
        if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
        global $wpdb;

        $opt = get_option( AttendanceModule::OPT_SETTINGS, [] );
        $departments = $this->get_departments( $wpdb );

        // Settings stored by department ID
        $web_allowed = $opt['web_allowed_by_dept_id'] ?? [];
        $selfie_req  = $opt['selfie_required_by_dept_id'] ?? [];
        $ret_days    = isset($opt['selfie_retention_days']) ? (int)$opt['selfie_retention_days'] : 30;
        $def_round   = $opt['default_rounding_rule'] ?? '5';
        $def_gl      = isset($opt['default_grace_late']) ? (int)$opt['default_grace_late'] : 5;
        $def_ge      = isset($opt['default_grace_early']) ? (int)$opt['default_grace_early'] : 5;
        $monthly_ot  = isset($opt['monthly_ot_threshold']) ? (int)$opt['monthly_ot_threshold'] : 2400; // minutes (40 hours default)

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Attendance Settings', 'sfs-hr' ); ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'sfs_hr_att_save_settings' ); ?>
                <input type="hidden" name="action" value="sfs_hr_att_save_settings"/>

                <?php if ( ! empty( $departments ) ) : ?>
                <h2><?php esc_html_e( 'Web/Mobile Punch Allowed by Department', 'sfs-hr' ); ?></h2>
                <table class="form-table">
                    <?php foreach ( $departments as $dept ) : ?>
                    <tr>
                        <th><?php echo esc_html( $dept['name'] ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="web_allowed_by_dept_id[<?php echo esc_attr( $dept['id'] ); ?>]" value="1" <?php checked( ! empty( $web_allowed[ $dept['id'] ] ) ); ?>/>
                                <?php esc_html_e( 'Allow web/mobile punches', 'sfs-hr' ); ?>
                            </label>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <h2><?php esc_html_e( 'Selfie Required by Department', 'sfs-hr' ); ?></h2>
                <table class="form-table">
                    <?php foreach ( $departments as $dept ) : ?>
                    <tr>
                        <th><?php echo esc_html( $dept['name'] ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="selfie_required_by_dept_id[<?php echo esc_attr( $dept['id'] ); ?>]" value="1" <?php checked( ! empty( $selfie_req[ $dept['id'] ] ) ); ?>/>
                                <?php esc_html_e( 'Require selfie on punch', 'sfs-hr' ); ?>
                            </label>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php else : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'No departments found. Please create departments first in HR → Departments.', 'sfs-hr' ); ?></p>
                </div>
                <?php endif; ?>

                <h2><?php esc_html_e( 'Retention & Defaults', 'sfs-hr' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Selfie retention (days)', 'sfs-hr' ); ?></th>
                        <td><input type="number" name="selfie_retention_days" min="1" step="1" value="<?php echo esc_attr($ret_days); ?>"/></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Default rounding (nearest minutes)', 'sfs-hr' ); ?></th>
                        <td>
                            <select name="default_rounding_rule">
                                <?php foreach (['none','5','10','15'] as $r): ?>
                                    <option value="<?php echo esc_attr($r); ?>" <?php selected($def_round,$r); ?>><?php echo esc_html($r); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Rounding = snap worked minutes to nearest N for payroll.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Default grace (late / early-leave)', 'sfs-hr' ); ?></th>
                        <td>
                            <input type="number" min="0" step="1" name="default_grace_late"  value="<?php echo esc_attr($def_gl); ?>" style="width:80px"/> <?php esc_html_e( 'min', 'sfs-hr' ); ?>
                            /
                            <input type="number" min="0" step="1" name="default_grace_early" value="<?php echo esc_attr($def_ge); ?>" style="width:80px"/> <?php esc_html_e( 'min', 'sfs-hr' ); ?>
                            <p class="description"><?php esc_html_e( 'Grace = tolerance before flagging late/early.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Monthly overtime threshold', 'sfs-hr' ); ?></th>
                        <td>
                            <input type="number" min="0" step="30" name="monthly_ot_threshold" value="<?php echo esc_attr($monthly_ot); ?>" style="width:100px"/> <?php esc_html_e( 'minutes', 'sfs-hr' ); ?>
                            <span style="color:#666;margin-left:10px;">(<?php echo esc_html( number_format($monthly_ot / 60, 1) ); ?> <?php esc_html_e( 'hours', 'sfs-hr' ); ?>)</span>
                            <p class="description"><?php esc_html_e( 'Overtime alert threshold per month. Dashboard will highlight employees approaching or exceeding this limit.', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Settings', 'sfs-hr' ) ); ?>
            </form>
        </div>
        <?php
    }
    
    
    /**
 * One-page tabbed UI to reduce sidebar clutter.
 */
public function render_attendance_hub(): void {
    // Allow attendance_admin OR attendance_view_team for certain tabs
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
    $view_team_tabs = ['early_leave', 'punches', 'sessions'];

    if ( in_array($tab, $view_team_tabs, true) ) {
        if ( ! current_user_can( 'sfs_hr_attendance_view_team' ) && ! current_user_can( 'sfs_hr_attendance_admin' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'sfs-hr' ) );
        }
    } else {
        if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'sfs-hr' ) );
        }
    }

      echo '<div class="wrap sfs-hr-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'Attendance', 'sfs-hr' ) . '</h1>';
    Helpers::render_admin_nav();
    echo '<hr class="wp-header-end" />';

    // $tab already set at top of function for capability check
    $tabs = [
        'settings'    => __( 'Settings', 'sfs-hr' ),
        'automation'  => __( 'Automation', 'sfs-hr' ),
        'shifts'      => __( 'Shifts', 'sfs-hr' ),
        'assign'      => __( 'Assignments', 'sfs-hr' ),
        'early_leave' => __( 'Early Leave Requests', 'sfs-hr' ),
        'exceptions'  => __( 'Exceptions', 'sfs-hr' ),
        'devices'     => __( 'Devices (Kiosk)', 'sfs-hr' ),
        'punches'     => __( 'Punches', 'sfs-hr' ),
        'sessions'    => __( 'Sessions', 'sfs-hr' ),
    ];
    if ( ! isset($tabs[$tab]) ) { $tab = 'settings'; }

    $base = admin_url('admin.php?page=sfs_hr_attendance');

    echo '<h2 class="nav-tab-wrapper">';
    foreach ( $tabs as $k => $label ) {
        $url = esc_url( add_query_arg( 'tab', $k, $base ) );
        $class = 'nav-tab' . ( $tab === $k ? ' nav-tab-active' : '' );
        echo '<a class="'.esc_attr($class).'" href="'.$url.'">'.esc_html($label).'</a>';
    }
    echo '</h2>';

    // Render selected tab using your existing renderers
    switch ( $tab ) {
        case 'settings':
            $this->render_settings();
            break;
            case 'automation':
    $this->render_automation();
    break;

        case 'shifts':
            $this->render_shifts();
            break;
        case 'assign':
            $this->render_assignments();
            break;
            case 'exceptions':
    $this->render_exceptions();
    break;
        case 'devices':
            $this->render_devices();
            break;
            case 'punches':  $this->render_punches();  break;
            case 'sessions': $this->render_sessions(); break;
            case 'early_leave': $this->render_early_leave(); break;
    }
    echo '</div>';
}


    public function handle_save_settings(): void {
        if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
        check_admin_referer( 'sfs_hr_att_save_settings' );

        // Process department settings dynamically by ID
        $web_allowed_by_dept_id = [];
        $selfie_required_by_dept_id = [];

        if ( ! empty( $_POST['web_allowed_by_dept_id'] ) && is_array( $_POST['web_allowed_by_dept_id'] ) ) {
            foreach ( $_POST['web_allowed_by_dept_id'] as $dept_id => $val ) {
                $web_allowed_by_dept_id[ (int) $dept_id ] = ! empty( $val );
            }
        }

        if ( ! empty( $_POST['selfie_required_by_dept_id'] ) && is_array( $_POST['selfie_required_by_dept_id'] ) ) {
            foreach ( $_POST['selfie_required_by_dept_id'] as $dept_id => $val ) {
                $selfie_required_by_dept_id[ (int) $dept_id ] = ! empty( $val );
            }
        }

        $input = [
            'web_allowed_by_dept_id'     => $web_allowed_by_dept_id,
            'selfie_required_by_dept_id' => $selfie_required_by_dept_id,
            'selfie_retention_days'      => max( 1, (int) ( $_POST['selfie_retention_days'] ?? 30 ) ),
            'default_rounding_rule'      => in_array( ( $_POST['default_rounding_rule'] ?? '5' ), [ 'none', '5', '10', '15' ], true ) ? $_POST['default_rounding_rule'] : '5',
            'default_grace_late'         => max( 0, (int) ( $_POST['default_grace_late'] ?? 5 ) ),
            'default_grace_early'        => max( 0, (int) ( $_POST['default_grace_early'] ?? 5 ) ),
            'monthly_ot_threshold'       => max( 0, (int) ( $_POST['monthly_ot_threshold'] ?? 2400 ) ),
        ];

        $existing = get_option( AttendanceModule::OPT_SETTINGS, [] );
        $merged   = array_replace_recursive( $existing, $input );
        update_option( AttendanceModule::OPT_SETTINGS, $merged, false );

        wp_safe_redirect( admin_url('admin.php?page=sfs_hr_attendance&tab=settings&saved=1') );

        exit;
    }

    /* ========================= AUTOMATION ====================*/

public function render_automation(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
    global $wpdb;

    $opt       = get_option( \SFS\HR\Modules\Attendance\AttendanceModule::OPT_SETTINGS, [] );
    $dept_def  = $opt['dept_defaults']          ?? []; // dept_id => shift_id
    $dept_ovr  = $opt['dept_period_overrides']  ?? []; // dept_id => [ ['start'=>Y-m-d,'end'=>Y-m-d,'shift_id'=>int,'label'=>string] ]

    $departments = $this->get_departments( $wpdb );

    // نجيب الشفتات ونفردها في قائمة واحدة بدون Optgroup ولا Dept headers
    $shifts_by  = $this->get_active_shifts_indexed( $wpdb );
    $all_shifts = [];
    foreach ( $shifts_by as $rows ) {
        foreach ( $rows as $s ) {
            $all_shifts[] = $s;
        }
    }

    ?>
    <div class="wrap sfs-hr-automation-wrap">
        <h1>Automation</h1>
        <p>Map each Department to a <strong>Default Shift</strong>. Optional date-range override (e.g., Ramadan). If no explicit Assignment exists, punches use this mapping.</p>

        <style>
        .sfs-hr-automation-wrap { max-width: 1180px; }
        .sfs-hr-automation-table { table-layout: fixed; width: 100%; }
        .sfs-hr-automation-table th,
        .sfs-hr-automation-table td { vertical-align: top; }
        .sfs-hr-automation-table th:nth-child(1),
        .sfs-hr-automation-table td:nth-child(1) { width: 18%; }
        .sfs-hr-automation-table th:nth-child(2),
        .sfs-hr-automation-table td:nth-child(2) { width: 30%; }
        .sfs-hr-automation-table th:nth-child(3),
        .sfs-hr-automation-table td:nth-child(3) { width: 52%; }
        .sfs-hr-automation-table td { padding-top: 8px; padding-bottom: 8px; }

        .sfs-hr-automation-table select {
            max-width: 100%;
            width: 100%;
            box-sizing: border-box;
        }
        </style>

        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field( 'sfs_hr_att_save_automation' ); ?>
            <input type="hidden" name="action" value="sfs_hr_att_save_automation"/>

            <table class="widefat striped sfs-hr-automation-table">
                <thead>
                <tr>
                    <th>Department</th>
                    <th>Default Shift</th>
                    <th>Override (optional)</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ( $departments as $d ):
                    $did             = (int) $d['id'];
                    $current_default = isset( $dept_def[ $did ] ) ? (int) $dept_def[ $did ] : 0;
                    $ovr             = $dept_ovr[ $did ][0] ?? [ 'label' => 'Ramadan', 'start' => '', 'end' => '', 'shift_id' => 0 ];
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $d['name'] ); ?></strong></td>

                        <!-- Default shift -->
                        <td>
                            <select name="dept_default_shift[<?php echo $did; ?>]">
                                <option value="0">— None —</option>
                                <?php foreach ( $all_shifts as $s ) : ?>
                                    <?php
                                    $label = "{$s['name']} — {$s['location_label']} ({$s['start_time']}→{$s['end_time']})";
                                    ?>
                                    <option value="<?php echo (int) $s['id']; ?>" <?php selected( $current_default, (int) $s['id'] ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>

                        <!-- Date-range override -->
                        <td>
                            <input type="text"
                                   name="dept_override_label[<?php echo $did; ?>]"
                                   value="<?php echo esc_attr( $ovr['label'] ); ?>"
                                   placeholder="Label (e.g., Ramadan)"
                                   style="width:160px"/>
                            <input type="date"
                                   name="dept_override_start[<?php echo $did; ?>]"
                                   value="<?php echo esc_attr( $ovr['start'] ); ?>"/>
                            →
                            <input type="date"
                                   name="dept_override_end[<?php echo $did; ?>]"
                                   value="<?php echo esc_attr( $ovr['end'] ); ?>"/>
                            <br/>
                            <select name="dept_override_shift[<?php echo $did; ?>]"
                                    style="margin-top:6px">
                                <option value="0">— No override —</option>
                                <?php foreach ( $all_shifts as $s ) : ?>
                                    <?php
                                    $label = "{$s['name']} — {$s['location_label']} ({$s['start_time']}→{$s['end_time']})";
                                    ?>
                                    <option value="<?php echo (int) $s['id']; ?>" <?php selected( (int) $ovr['shift_id'], (int) $s['id'] ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button( 'Save Automation' ); ?>
        </form>

        <?php $this->render_shift_templates( $all_shifts, $departments ); ?>

        <?php $this->render_auto_assignment_rules( $all_shifts, $departments ); ?>
    </div>
    <?php
}

/**
 * Render Shift Templates section
 *
 * @param array $all_shifts   Available shifts
 * @param array $departments  Available departments
 */
private function render_shift_templates( array $all_shifts, array $departments ): void {
    $opt       = get_option( AttendanceModule::OPT_SETTINGS, [] );
    $templates = $opt['shift_templates'] ?? [];

    ?>
    <hr style="margin: 30px 0;">

    <h2><?php esc_html_e( 'Shift Templates', 'sfs-hr' ); ?></h2>
    <p><?php esc_html_e( 'Create reusable shift patterns (e.g., Ramadan Hours, Summer Schedule) that can be quickly applied to multiple departments.', 'sfs-hr' ); ?></p>

    <style>
        .sfs-hr-templates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .sfs-hr-template-card {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 15px;
        }
        .sfs-hr-template-card h4 {
            margin: 0 0 10px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sfs-hr-template-card .template-meta {
            color: #666;
            font-size: 13px;
            margin-bottom: 10px;
        }
        .sfs-hr-template-card .template-dates {
            background: #f0f6fc;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 12px;
            margin-bottom: 10px;
        }
        .sfs-hr-template-card .template-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        .sfs-hr-template-form {
            background: #fff;
            border: 1px solid #c3c4c7;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .sfs-hr-template-form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }
        .sfs-hr-template-form-field {
            flex: 1;
            min-width: 180px;
        }
        .sfs-hr-template-form-field label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .sfs-hr-template-form-field select,
        .sfs-hr-template-form-field input {
            width: 100%;
        }
        .sfs-hr-apply-template-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 100000;
            align-items: center;
            justify-content: center;
        }
        .sfs-hr-apply-template-modal.active {
            display: flex;
        }
        .sfs-hr-apply-template-content {
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .sfs-hr-apply-template-content h3 {
            margin-top: 0;
        }
        .sfs-hr-dept-checklist {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            margin: 10px 0;
        }
        .sfs-hr-dept-checklist label {
            display: block;
            padding: 5px 0;
        }
    </style>

    <!-- Add New Template Form -->
    <div class="sfs-hr-template-form">
        <h3 style="margin-top:0;"><?php esc_html_e( 'Create New Template', 'sfs-hr' ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'sfs_hr_att_save_template' ); ?>
            <input type="hidden" name="action" value="sfs_hr_att_save_template">

            <div class="sfs-hr-template-form-row">
                <div class="sfs-hr-template-form-field" style="flex: 2;">
                    <label><?php esc_html_e( 'Template Name', 'sfs-hr' ); ?></label>
                    <input type="text" name="template_name" placeholder="<?php esc_attr_e( 'e.g., Ramadan Hours 2026', 'sfs-hr' ); ?>" required>
                </div>
                <div class="sfs-hr-template-form-field">
                    <label><?php esc_html_e( 'Shift', 'sfs-hr' ); ?></label>
                    <select name="template_shift_id" required>
                        <option value=""><?php esc_html_e( '— Select Shift —', 'sfs-hr' ); ?></option>
                        <?php foreach ( $all_shifts as $s ) : ?>
                            <option value="<?php echo (int) $s['id']; ?>">
                                <?php echo esc_html( "{$s['name']} ({$s['start_time']}→{$s['end_time']})" ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="sfs-hr-template-form-row">
                <div class="sfs-hr-template-form-field">
                    <label><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></label>
                    <input type="date" name="template_start_date" required>
                </div>
                <div class="sfs-hr-template-form-field">
                    <label><?php esc_html_e( 'End Date', 'sfs-hr' ); ?></label>
                    <input type="date" name="template_end_date" required>
                </div>
                <div class="sfs-hr-template-form-field">
                    <label><?php esc_html_e( 'Color Label', 'sfs-hr' ); ?></label>
                    <select name="template_color">
                        <option value="blue"><?php esc_html_e( 'Blue', 'sfs-hr' ); ?></option>
                        <option value="green"><?php esc_html_e( 'Green', 'sfs-hr' ); ?></option>
                        <option value="orange"><?php esc_html_e( 'Orange', 'sfs-hr' ); ?></option>
                        <option value="purple"><?php esc_html_e( 'Purple', 'sfs-hr' ); ?></option>
                        <option value="red"><?php esc_html_e( 'Red', 'sfs-hr' ); ?></option>
                    </select>
                </div>
            </div>

            <div class="sfs-hr-template-form-row">
                <div class="sfs-hr-template-form-field">
                    <label><?php esc_html_e( 'Description (optional)', 'sfs-hr' ); ?></label>
                    <input type="text" name="template_description" placeholder="<?php esc_attr_e( 'Short description of this template', 'sfs-hr' ); ?>">
                </div>
            </div>

            <button type="submit" class="button button-primary"><?php esc_html_e( 'Create Template', 'sfs-hr' ); ?></button>
        </form>
    </div>

    <!-- Existing Templates -->
    <?php if ( empty( $templates ) ) : ?>
        <div style="padding: 20px; text-align: center; color: #666; background: #f9f9f9;">
            <p><?php esc_html_e( 'No shift templates created yet.', 'sfs-hr' ); ?></p>
        </div>
    <?php else : ?>
        <div class="sfs-hr-templates-grid">
            <?php
            $colors = [
                'blue'   => '#2271b1',
                'green'  => '#00a32a',
                'orange' => '#d63638',
                'purple' => '#7e3bd0',
                'red'    => '#dc3232',
            ];

            foreach ( $templates as $idx => $tpl ) :
                // Find shift name
                $shift_name = '—';
                $shift_times = '';
                foreach ( $all_shifts as $s ) {
                    if ( (int) $s['id'] === (int) $tpl['shift_id'] ) {
                        $shift_name = $s['name'];
                        $shift_times = "{$s['start_time']}→{$s['end_time']}";
                        break;
                    }
                }

                $color_code = $colors[ $tpl['color'] ?? 'blue' ] ?? '#2271b1';
                ?>
                <div class="sfs-hr-template-card">
                    <h4>
                        <span style="color: <?php echo esc_attr( $color_code ); ?>;">
                            <?php echo esc_html( $tpl['name'] ); ?>
                        </span>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0;">
                            <?php wp_nonce_field( 'sfs_hr_att_delete_template' ); ?>
                            <input type="hidden" name="action" value="sfs_hr_att_delete_template">
                            <input type="hidden" name="template_index" value="<?php echo (int) $idx; ?>">
                            <button type="submit" class="button-link" style="color: #b32d2e;" onclick="return confirm('<?php esc_attr_e( 'Delete this template?', 'sfs-hr' ); ?>');">
                                <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                            </button>
                        </form>
                    </h4>
                    <div class="template-meta">
                        <strong><?php echo esc_html( $shift_name ); ?></strong>
                        <?php if ( $shift_times ) : ?>
                            <span style="color: #999;">(<?php echo esc_html( $shift_times ); ?>)</span>
                        <?php endif; ?>
                    </div>
                    <div class="template-dates">
                        <span class="dashicons dashicons-calendar-alt" style="vertical-align: middle; font-size: 14px;"></span>
                        <?php
                        echo esc_html(
                            wp_date( 'M j, Y', strtotime( $tpl['start_date'] ) ) .
                            ' → ' .
                            wp_date( 'M j, Y', strtotime( $tpl['end_date'] ) )
                        );
                        ?>
                    </div>
                    <?php if ( ! empty( $tpl['description'] ) ) : ?>
                        <p style="margin: 0 0 10px; font-size: 13px; color: #666;">
                            <?php echo esc_html( $tpl['description'] ); ?>
                        </p>
                    <?php endif; ?>
                    <div class="template-actions">
                        <button type="button" class="button button-secondary apply-template-btn"
                                data-template-idx="<?php echo (int) $idx; ?>"
                                data-template-name="<?php echo esc_attr( $tpl['name'] ); ?>">
                            <?php esc_html_e( 'Apply to Departments', 'sfs-hr' ); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Apply Template Modal -->
    <div id="apply-template-modal" class="sfs-hr-apply-template-modal">
        <div class="sfs-hr-apply-template-content">
            <h3><?php esc_html_e( 'Apply Template', 'sfs-hr' ); ?>: <span id="apply-template-name"></span></h3>
            <p><?php esc_html_e( 'Select departments to apply this template to:', 'sfs-hr' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'sfs_hr_att_apply_template' ); ?>
                <input type="hidden" name="action" value="sfs_hr_att_apply_template">
                <input type="hidden" name="template_index" id="apply-template-index" value="">

                <div class="sfs-hr-dept-checklist">
                    <label>
                        <input type="checkbox" id="select-all-depts">
                        <strong><?php esc_html_e( 'Select All', 'sfs-hr' ); ?></strong>
                    </label>
                    <hr style="margin: 5px 0;">
                    <?php foreach ( $departments as $d ) : ?>
                        <label>
                            <input type="checkbox" name="apply_departments[]" value="<?php echo (int) $d['id']; ?>">
                            <?php echo esc_html( $d['name'] ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <p style="margin-top: 15px;">
                    <label>
                        <input type="checkbox" name="overwrite_existing" value="1">
                        <?php esc_html_e( 'Overwrite existing period overrides', 'sfs-hr' ); ?>
                    </label>
                </p>

                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Template', 'sfs-hr' ); ?></button>
                    <button type="button" class="button" onclick="document.getElementById('apply-template-modal').classList.remove('active');">
                        <?php esc_html_e( 'Cancel', 'sfs-hr' ); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    jQuery(function($) {
        // Open apply modal
        $('.apply-template-btn').on('click', function() {
            var idx = $(this).data('template-idx');
            var name = $(this).data('template-name');
            $('#apply-template-index').val(idx);
            $('#apply-template-name').text(name);
            $('#apply-template-modal').addClass('active');
        });

        // Close modal on backdrop click
        $('#apply-template-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).removeClass('active');
            }
        });

        // Select all departments
        $('#select-all-depts').on('change', function() {
            $('input[name="apply_departments[]"]').prop('checked', this.checked);
        });
    });
    </script>
    <?php
}

/**
 * Render Auto-Assignment Rules section
 *
 * @param array $all_shifts Available shifts
 * @param array $departments Available departments
 */
private function render_auto_assignment_rules( array $all_shifts, array $departments ): void {
    $opt   = get_option( AttendanceModule::OPT_SETTINGS, [] );
    $rules = $opt['auto_assignment_rules'] ?? [];

    // Get unique job titles for dropdown
    global $wpdb;
    $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
    $job_titles = $wpdb->get_col( "SELECT DISTINCT position FROM {$emp_table} WHERE position IS NOT NULL AND position != '' ORDER BY position" );

    // Get unique work locations
    $work_locations = $wpdb->get_col( "SELECT DISTINCT work_location FROM {$emp_table} WHERE work_location IS NOT NULL AND work_location != '' ORDER BY work_location" );

    // Get contract types
    $contract_types = [ 'full-time', 'part-time', 'contract', 'temporary', 'intern' ];

    ?>
    <hr style="margin: 30px 0;">

    <h2><?php esc_html_e( 'Auto-Assignment Rules', 'sfs-hr' ); ?></h2>
    <p><?php esc_html_e( 'Define rules to automatically assign shifts to employees based on their attributes. Rules are evaluated in priority order (lowest number first).', 'sfs-hr' ); ?></p>

    <style>
        .sfs-hr-rules-table { width: 100%; border-collapse: collapse; }
        .sfs-hr-rules-table th,
        .sfs-hr-rules-table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        .sfs-hr-rules-table th { background: #f9f9f9; }
        .sfs-hr-rule-conditions { display: flex; flex-wrap: wrap; gap: 10px; }
        .sfs-hr-rule-condition {
            background: #f0f6fc;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        .sfs-hr-rule-form { background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin-bottom: 20px; border-radius: 4px; }
        .sfs-hr-rule-form-row { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
        .sfs-hr-rule-form-field { flex: 1; min-width: 200px; }
        .sfs-hr-rule-form-field label { display: block; margin-bottom: 5px; font-weight: 600; }
        .sfs-hr-rule-form-field select,
        .sfs-hr-rule-form-field input { width: 100%; }
        .sfs-hr-no-rules { padding: 20px; text-align: center; color: #666; background: #f9f9f9; }
        .sfs-hr-rule-actions { display: flex; gap: 10px; }
    </style>

    <!-- Add New Rule Form -->
    <div class="sfs-hr-rule-form">
        <h3 style="margin-top:0;"><?php esc_html_e( 'Add New Rule', 'sfs-hr' ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'sfs_hr_att_save_auto_rules' ); ?>
            <input type="hidden" name="action" value="sfs_hr_att_save_auto_rules">

            <div class="sfs-hr-rule-form-row">
                <div class="sfs-hr-rule-form-field">
                    <label><?php esc_html_e( 'Rule Name', 'sfs-hr' ); ?></label>
                    <input type="text" name="rule_name" placeholder="<?php esc_attr_e( 'e.g., Office Staff Morning Shift', 'sfs-hr' ); ?>" required>
                </div>
                <div class="sfs-hr-rule-form-field" style="flex: 0 0 100px;">
                    <label><?php esc_html_e( 'Priority', 'sfs-hr' ); ?></label>
                    <input type="number" name="rule_priority" value="10" min="1" max="100" style="width: 80px;">
                </div>
                <div class="sfs-hr-rule-form-field">
                    <label><?php esc_html_e( 'Assign Shift', 'sfs-hr' ); ?></label>
                    <select name="rule_shift_id" required>
                        <option value=""><?php esc_html_e( '— Select Shift —', 'sfs-hr' ); ?></option>
                        <?php foreach ( $all_shifts as $s ) : ?>
                            <option value="<?php echo (int) $s['id']; ?>">
                                <?php echo esc_html( "{$s['name']} ({$s['start_time']}→{$s['end_time']})" ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="sfs-hr-rule-form-row">
                <div class="sfs-hr-rule-form-field">
                    <label><?php esc_html_e( 'Department (optional)', 'sfs-hr' ); ?></label>
                    <select name="rule_department">
                        <option value=""><?php esc_html_e( '— Any Department —', 'sfs-hr' ); ?></option>
                        <?php foreach ( $departments as $d ) : ?>
                            <option value="<?php echo (int) $d['id']; ?>">
                                <?php echo esc_html( $d['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sfs-hr-rule-form-field">
                    <label><?php esc_html_e( 'Job Title (optional)', 'sfs-hr' ); ?></label>
                    <select name="rule_job_title">
                        <option value=""><?php esc_html_e( '— Any Job Title —', 'sfs-hr' ); ?></option>
                        <?php foreach ( $job_titles as $jt ) : ?>
                            <option value="<?php echo esc_attr( $jt ); ?>">
                                <?php echo esc_html( $jt ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sfs-hr-rule-form-field">
                    <label><?php esc_html_e( 'Work Location (optional)', 'sfs-hr' ); ?></label>
                    <select name="rule_work_location">
                        <option value=""><?php esc_html_e( '— Any Location —', 'sfs-hr' ); ?></option>
                        <?php foreach ( $work_locations as $loc ) : ?>
                            <option value="<?php echo esc_attr( $loc ); ?>">
                                <?php echo esc_html( $loc ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="sfs-hr-rule-form-row">
                <div class="sfs-hr-rule-form-field">
                    <label><?php esc_html_e( 'Contract Type (optional)', 'sfs-hr' ); ?></label>
                    <select name="rule_contract_type">
                        <option value=""><?php esc_html_e( '— Any Contract —', 'sfs-hr' ); ?></option>
                        <?php foreach ( $contract_types as $ct ) : ?>
                            <option value="<?php echo esc_attr( $ct ); ?>">
                                <?php echo esc_html( ucfirst( $ct ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sfs-hr-rule-form-field">
                    <label><?php esc_html_e( 'Hired After (optional)', 'sfs-hr' ); ?></label>
                    <input type="date" name="rule_hired_after">
                </div>
                <div class="sfs-hr-rule-form-field">
                    <label><?php esc_html_e( 'Hired Before (optional)', 'sfs-hr' ); ?></label>
                    <input type="date" name="rule_hired_before">
                </div>
            </div>

            <div class="sfs-hr-rule-form-row">
                <div class="sfs-hr-rule-form-field">
                    <label>
                        <input type="checkbox" name="rule_apply_on_create" value="1" checked>
                        <?php esc_html_e( 'Apply automatically when new employee is created', 'sfs-hr' ); ?>
                    </label>
                </div>
            </div>

            <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Rule', 'sfs-hr' ); ?></button>
        </form>
    </div>

    <!-- Existing Rules -->
    <?php if ( empty( $rules ) ) : ?>
        <div class="sfs-hr-no-rules">
            <p><?php esc_html_e( 'No auto-assignment rules defined yet.', 'sfs-hr' ); ?></p>
        </div>
    <?php else : ?>
        <table class="widefat sfs-hr-rules-table">
            <thead>
                <tr>
                    <th style="width: 60px;"><?php esc_html_e( 'Priority', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Rule Name', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Conditions', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Shift', 'sfs-hr' ); ?></th>
                    <th style="width: 100px;"><?php esc_html_e( 'Auto', 'sfs-hr' ); ?></th>
                    <th style="width: 80px;"><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Sort by priority
                usort( $rules, fn( $a, $b ) => ( $a['priority'] ?? 10 ) <=> ( $b['priority'] ?? 10 ) );

                foreach ( $rules as $idx => $rule ) :
                    // Find shift name
                    $shift_name = '—';
                    foreach ( $all_shifts as $s ) {
                        if ( (int) $s['id'] === (int) $rule['shift_id'] ) {
                            $shift_name = $s['name'] . " ({$s['start_time']}→{$s['end_time']})";
                            break;
                        }
                    }

                    // Build conditions display
                    $conditions = [];
                    if ( ! empty( $rule['department'] ) ) {
                        foreach ( $departments as $d ) {
                            if ( (int) $d['id'] === (int) $rule['department'] ) {
                                $conditions[] = __( 'Dept:', 'sfs-hr' ) . ' ' . $d['name'];
                                break;
                            }
                        }
                    }
                    if ( ! empty( $rule['job_title'] ) ) {
                        $conditions[] = __( 'Title:', 'sfs-hr' ) . ' ' . $rule['job_title'];
                    }
                    if ( ! empty( $rule['work_location'] ) ) {
                        $conditions[] = __( 'Location:', 'sfs-hr' ) . ' ' . $rule['work_location'];
                    }
                    if ( ! empty( $rule['contract_type'] ) ) {
                        $conditions[] = __( 'Contract:', 'sfs-hr' ) . ' ' . ucfirst( $rule['contract_type'] );
                    }
                    if ( ! empty( $rule['hired_after'] ) ) {
                        $conditions[] = __( 'Hired after:', 'sfs-hr' ) . ' ' . $rule['hired_after'];
                    }
                    if ( ! empty( $rule['hired_before'] ) ) {
                        $conditions[] = __( 'Hired before:', 'sfs-hr' ) . ' ' . $rule['hired_before'];
                    }
                    if ( empty( $conditions ) ) {
                        $conditions[] = __( 'All employees', 'sfs-hr' );
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo (int) ( $rule['priority'] ?? 10 ); ?></strong></td>
                        <td><?php echo esc_html( $rule['name'] ?? __( 'Unnamed Rule', 'sfs-hr' ) ); ?></td>
                        <td>
                            <div class="sfs-hr-rule-conditions">
                                <?php foreach ( $conditions as $cond ) : ?>
                                    <span class="sfs-hr-rule-condition"><?php echo esc_html( $cond ); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td><?php echo esc_html( $shift_name ); ?></td>
                        <td>
                            <?php if ( ! empty( $rule['apply_on_create'] ) ) : ?>
                                <span style="color: green;">✓</span>
                            <?php else : ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
                                <?php wp_nonce_field( 'sfs_hr_att_delete_auto_rule' ); ?>
                                <input type="hidden" name="action" value="sfs_hr_att_delete_auto_rule">
                                <input type="hidden" name="rule_index" value="<?php echo (int) $idx; ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Delete this rule?', 'sfs-hr' ); ?>');">
                                    <?php esc_html_e( 'Delete', 'sfs-hr' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Run Rules Button -->
    <div style="margin-top: 20px;">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
            <?php wp_nonce_field( 'sfs_hr_att_run_auto_rules' ); ?>
            <input type="hidden" name="action" value="sfs_hr_att_run_auto_rules">
            <button type="submit" class="button button-secondary" onclick="return confirm('<?php esc_attr_e( 'This will apply rules to all employees who do not have a default shift assigned. Continue?', 'sfs-hr' ); ?>');">
                <?php esc_html_e( 'Run Rules on Existing Employees', 'sfs-hr' ); ?>
            </button>
            <span class="description" style="margin-left: 10px;">
                <?php esc_html_e( 'Apply rules to employees who do not have a default shift assigned yet.', 'sfs-hr' ); ?>
            </span>
        </form>
    </div>
    <?php
}


public function handle_save_automation(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die( 'Access denied' ); }
    check_admin_referer( 'sfs_hr_att_save_automation' );

    $def   = (array) ( $_POST['dept_default_shift']  ?? [] );
    $olbl  = (array) ( $_POST['dept_override_label'] ?? [] );
    $os    = (array) ( $_POST['dept_override_start'] ?? [] );
    $oe    = (array) ( $_POST['dept_override_end']   ?? [] );
    $osh   = (array) ( $_POST['dept_override_shift'] ?? [] );

    // -------- Defaults --------
    $dept_defaults = [];
    foreach ( $def as $dept_id => $shift_id ) {
        $dept_defaults[ (int) $dept_id ] = max( 0, (int) $shift_id );
    }

    // -------- Date-range overrides --------
    $dept_period_overrides = [];
    foreach ( $osh as $dept_id => $shift_id ) {
        $did   = (int) $dept_id;
        $sid   = max( 0, (int) $shift_id );
        $start = sanitize_text_field( $os[ $dept_id ] ?? '' );
        $end   = sanitize_text_field( $oe[ $dept_id ] ?? '' );
        $label = sanitize_text_field( $olbl[ $dept_id ] ?? 'Override' );

        if (
            $sid > 0
            && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start )
            && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end )
            && $end >= $start
        ) {
            $dept_period_overrides[ $did ] = [
                [
                    'label'    => $label,
                    'start'    => $start,
                    'end'      => $end,
                    'shift_id' => $sid,
                ],
            ];
        }
    }

    // -------- Save into main option --------
    $opt = get_option( \SFS\HR\Modules\Attendance\AttendanceModule::OPT_SETTINGS, [] );
    if ( ! is_array( $opt ) ) {
        $opt = [];
    }

    $opt['dept_defaults']          = $dept_defaults;
    $opt['dept_period_overrides']  = $dept_period_overrides;

    update_option( \SFS\HR\Modules\Attendance\AttendanceModule::OPT_SETTINGS, $opt, false );

    wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&saved=1' ) );
    exit;
}

/**
 * Handle saving new auto-assignment rule
 */
public function handle_save_auto_rules(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) {
        wp_die( 'Access denied' );
    }
    check_admin_referer( 'sfs_hr_att_save_auto_rules' );

    $rule_name        = sanitize_text_field( wp_unslash( $_POST['rule_name'] ?? '' ) );
    $rule_priority    = absint( $_POST['rule_priority'] ?? 10 );
    $rule_shift_id    = absint( $_POST['rule_shift_id'] ?? 0 );
    $rule_department  = absint( $_POST['rule_department'] ?? 0 );
    $rule_job_title   = sanitize_text_field( wp_unslash( $_POST['rule_job_title'] ?? '' ) );
    $rule_work_loc    = sanitize_text_field( wp_unslash( $_POST['rule_work_location'] ?? '' ) );
    $rule_contract    = sanitize_text_field( wp_unslash( $_POST['rule_contract_type'] ?? '' ) );
    $rule_hired_after = sanitize_text_field( wp_unslash( $_POST['rule_hired_after'] ?? '' ) );
    $rule_hired_before = sanitize_text_field( wp_unslash( $_POST['rule_hired_before'] ?? '' ) );
    $rule_apply_on_create = ! empty( $_POST['rule_apply_on_create'] );

    if ( empty( $rule_name ) || empty( $rule_shift_id ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&error=missing_fields' ) );
        exit;
    }

    // Build rule array
    $new_rule = [
        'name'            => $rule_name,
        'priority'        => $rule_priority,
        'shift_id'        => $rule_shift_id,
        'department'      => $rule_department ?: '',
        'job_title'       => $rule_job_title,
        'work_location'   => $rule_work_loc,
        'contract_type'   => $rule_contract,
        'hired_after'     => $rule_hired_after,
        'hired_before'    => $rule_hired_before,
        'apply_on_create' => $rule_apply_on_create,
        'created_at'      => current_time( 'mysql' ),
    ];

    // Load existing rules
    $opt   = get_option( AttendanceModule::OPT_SETTINGS, [] );
    $rules = $opt['auto_assignment_rules'] ?? [];

    // Add new rule
    $rules[] = $new_rule;

    // Save back
    $opt['auto_assignment_rules'] = $rules;
    update_option( AttendanceModule::OPT_SETTINGS, $opt, false );

    wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&rule_saved=1' ) );
    exit;
}

/**
 * Handle deleting an auto-assignment rule
 */
public function handle_delete_auto_rule(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) {
        wp_die( 'Access denied' );
    }
    check_admin_referer( 'sfs_hr_att_delete_auto_rule' );

    $rule_index = absint( $_POST['rule_index'] ?? -1 );

    $opt   = get_option( AttendanceModule::OPT_SETTINGS, [] );
    $rules = $opt['auto_assignment_rules'] ?? [];

    if ( isset( $rules[ $rule_index ] ) ) {
        array_splice( $rules, $rule_index, 1 );
        $opt['auto_assignment_rules'] = array_values( $rules );
        update_option( AttendanceModule::OPT_SETTINGS, $opt, false );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&rule_deleted=1' ) );
    exit;
}

/**
 * Handle running auto-assignment rules on existing employees
 */
public function handle_run_auto_rules(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) {
        wp_die( 'Access denied' );
    }
    check_admin_referer( 'sfs_hr_att_run_auto_rules' );

    global $wpdb;

    $opt   = get_option( AttendanceModule::OPT_SETTINGS, [] );
    $rules = $opt['auto_assignment_rules'] ?? [];

    if ( empty( $rules ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&no_rules=1' ) );
        exit;
    }

    // Sort rules by priority
    usort( $rules, fn( $a, $b ) => ( $a['priority'] ?? 10 ) <=> ( $b['priority'] ?? 10 ) );

    // Get all active employees
    $emp_table = $wpdb->prefix . 'sfs_hr_employees';
    $emp_shifts_table = $wpdb->prefix . 'sfs_hr_attendance_emp_shifts';
    $dept_table = $wpdb->prefix . 'sfs_hr_departments';

    // Get employees who don't have a default shift assigned
    $employees = $wpdb->get_results(
        "SELECT e.*, d.id as dept_id
         FROM {$emp_table} e
         LEFT JOIN {$dept_table} d ON e.department = d.name
         WHERE e.status = 'active'
         AND NOT EXISTS (
             SELECT 1 FROM {$emp_shifts_table} es
             WHERE es.employee_id = e.id
         )"
    );

    $assigned_count = 0;

    foreach ( $employees as $emp ) {
        $matched_shift = $this->find_matching_rule_for_employee( $emp, $rules );

        if ( $matched_shift ) {
            // Assign the shift
            $wpdb->insert(
                $emp_shifts_table,
                [
                    'employee_id' => $emp->id,
                    'shift_id'    => $matched_shift,
                    'start_date'  => current_time( 'Y-m-d' ),
                    'created_at'  => current_time( 'mysql' ),
                    'created_by'  => get_current_user_id(),
                ],
                [ '%d', '%d', '%s', '%s', '%d' ]
            );
            $assigned_count++;
        }
    }

    wp_safe_redirect( admin_url( "admin.php?page=sfs_hr_attendance&tab=automation&rules_run=1&assigned={$assigned_count}" ) );
    exit;
}

/**
 * Find matching rule for an employee
 *
 * @param object $employee Employee data
 * @param array  $rules    Rules sorted by priority
 * @return int|null Shift ID if matched, null otherwise
 */
private function find_matching_rule_for_employee( $employee, array $rules ): ?int {
    foreach ( $rules as $rule ) {
        $matches = true;

        // Check department
        if ( ! empty( $rule['department'] ) ) {
            if ( (int) ( $employee->dept_id ?? 0 ) !== (int) $rule['department'] ) {
                $matches = false;
            }
        }

        // Check job title
        if ( $matches && ! empty( $rule['job_title'] ) ) {
            if ( strtolower( $employee->position ?? '' ) !== strtolower( $rule['job_title'] ) ) {
                $matches = false;
            }
        }

        // Check work location
        if ( $matches && ! empty( $rule['work_location'] ) ) {
            if ( strtolower( $employee->work_location ?? '' ) !== strtolower( $rule['work_location'] ) ) {
                $matches = false;
            }
        }

        // Check contract type
        if ( $matches && ! empty( $rule['contract_type'] ) ) {
            if ( strtolower( $employee->contract_type ?? '' ) !== strtolower( $rule['contract_type'] ) ) {
                $matches = false;
            }
        }

        // Check hired after
        if ( $matches && ! empty( $rule['hired_after'] ) ) {
            $hire_date = $employee->hire_date ?? $employee->hired_at ?? '';
            if ( empty( $hire_date ) || $hire_date < $rule['hired_after'] ) {
                $matches = false;
            }
        }

        // Check hired before
        if ( $matches && ! empty( $rule['hired_before'] ) ) {
            $hire_date = $employee->hire_date ?? $employee->hired_at ?? '';
            if ( empty( $hire_date ) || $hire_date > $rule['hired_before'] ) {
                $matches = false;
            }
        }

        if ( $matches ) {
            return (int) $rule['shift_id'];
        }
    }

    return null;
}

/**
 * Auto-assign shift when a new employee is created
 *
 * @param int   $employee_id Employee ID
 * @param array $data        Employee data
 */
public function auto_assign_on_employee_created( int $employee_id, array $data ): void {
    global $wpdb;

    $opt   = get_option( AttendanceModule::OPT_SETTINGS, [] );
    $rules = $opt['auto_assignment_rules'] ?? [];

    // Filter only rules with apply_on_create enabled
    $rules = array_filter( $rules, fn( $r ) => ! empty( $r['apply_on_create'] ) );

    if ( empty( $rules ) ) {
        return;
    }

    // Sort rules by priority
    usort( $rules, fn( $a, $b ) => ( $a['priority'] ?? 10 ) <=> ( $b['priority'] ?? 10 ) );

    // Get employee with department info
    $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
    $dept_table = $wpdb->prefix . 'sfs_hr_departments';

    $employee = $wpdb->get_row( $wpdb->prepare(
        "SELECT e.*, d.id as dept_id
         FROM {$emp_table} e
         LEFT JOIN {$dept_table} d ON e.department = d.name
         WHERE e.id = %d",
        $employee_id
    ) );

    if ( ! $employee ) {
        return;
    }

    $matched_shift = $this->find_matching_rule_for_employee( $employee, $rules );

    if ( $matched_shift ) {
        $emp_shifts_table = $wpdb->prefix . 'sfs_hr_attendance_emp_shifts';

        // Check if employee already has a shift assigned
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$emp_shifts_table} WHERE employee_id = %d LIMIT 1",
            $employee_id
        ) );

        if ( ! $existing ) {
            $wpdb->insert(
                $emp_shifts_table,
                [
                    'employee_id' => $employee_id,
                    'shift_id'    => $matched_shift,
                    'start_date'  => current_time( 'Y-m-d' ),
                    'created_at'  => current_time( 'mysql' ),
                    'created_by'  => get_current_user_id(),
                ],
                [ '%d', '%d', '%s', '%s', '%d' ]
            );
        }
    }
}

/**
 * Handle saving a new shift template
 */
public function handle_save_template(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) {
        wp_die( 'Access denied' );
    }
    check_admin_referer( 'sfs_hr_att_save_template' );

    $name        = sanitize_text_field( wp_unslash( $_POST['template_name'] ?? '' ) );
    $shift_id    = absint( $_POST['template_shift_id'] ?? 0 );
    $start_date  = sanitize_text_field( wp_unslash( $_POST['template_start_date'] ?? '' ) );
    $end_date    = sanitize_text_field( wp_unslash( $_POST['template_end_date'] ?? '' ) );
    $color       = sanitize_key( $_POST['template_color'] ?? 'blue' );
    $description = sanitize_text_field( wp_unslash( $_POST['template_description'] ?? '' ) );

    if ( empty( $name ) || empty( $shift_id ) || empty( $start_date ) || empty( $end_date ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&error=missing_fields' ) );
        exit;
    }

    if ( $end_date < $start_date ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&error=invalid_dates' ) );
        exit;
    }

    $new_template = [
        'name'        => $name,
        'shift_id'    => $shift_id,
        'start_date'  => $start_date,
        'end_date'    => $end_date,
        'color'       => $color,
        'description' => $description,
        'created_at'  => current_time( 'mysql' ),
        'created_by'  => get_current_user_id(),
    ];

    $opt       = get_option( AttendanceModule::OPT_SETTINGS, [] );
    $templates = $opt['shift_templates'] ?? [];
    $templates[] = $new_template;

    $opt['shift_templates'] = $templates;
    update_option( AttendanceModule::OPT_SETTINGS, $opt, false );

    wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&template_saved=1' ) );
    exit;
}

/**
 * Handle deleting a shift template
 */
public function handle_delete_template(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) {
        wp_die( 'Access denied' );
    }
    check_admin_referer( 'sfs_hr_att_delete_template' );

    $template_index = absint( $_POST['template_index'] ?? -1 );

    $opt       = get_option( AttendanceModule::OPT_SETTINGS, [] );
    $templates = $opt['shift_templates'] ?? [];

    if ( isset( $templates[ $template_index ] ) ) {
        array_splice( $templates, $template_index, 1 );
        $opt['shift_templates'] = array_values( $templates );
        update_option( AttendanceModule::OPT_SETTINGS, $opt, false );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&template_deleted=1' ) );
    exit;
}

/**
 * Handle applying a shift template to departments
 */
public function handle_apply_template(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) {
        wp_die( 'Access denied' );
    }
    check_admin_referer( 'sfs_hr_att_apply_template' );

    $template_index   = absint( $_POST['template_index'] ?? -1 );
    $apply_depts      = isset( $_POST['apply_departments'] ) && is_array( $_POST['apply_departments'] )
                        ? array_map( 'absint', $_POST['apply_departments'] )
                        : [];
    $overwrite        = ! empty( $_POST['overwrite_existing'] );

    if ( empty( $apply_depts ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&error=no_departments' ) );
        exit;
    }

    $opt       = get_option( AttendanceModule::OPT_SETTINGS, [] );
    $templates = $opt['shift_templates'] ?? [];

    if ( ! isset( $templates[ $template_index ] ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=automation&error=template_not_found' ) );
        exit;
    }

    $template = $templates[ $template_index ];
    $dept_ovr = $opt['dept_period_overrides'] ?? [];

    $applied_count = 0;

    foreach ( $apply_depts as $dept_id ) {
        // Check if department already has an override
        $has_existing = ! empty( $dept_ovr[ $dept_id ] );

        if ( $has_existing && ! $overwrite ) {
            continue;
        }

        // Apply template as period override
        $dept_ovr[ $dept_id ] = [
            [
                'label'    => $template['name'],
                'start'    => $template['start_date'],
                'end'      => $template['end_date'],
                'shift_id' => (int) $template['shift_id'],
            ],
        ];

        $applied_count++;
    }

    $opt['dept_period_overrides'] = $dept_ovr;
    update_option( AttendanceModule::OPT_SETTINGS, $opt, false );

    wp_safe_redirect( admin_url( "admin.php?page=sfs_hr_attendance&tab=automation&template_applied=1&count={$applied_count}" ) );
    exit;
}


/* ========================= SHIFTS ========================= */
public function render_shifts(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
    global $wpdb;
    $table = $wpdb->prefix . 'sfs_hr_attendance_shifts';

    // ما نحتاج dept في الـ ORDER BY عشان العمود اختفى
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY active DESC, name ASC");

    $editing = null;
    if ( isset($_GET['edit']) ) {
        $editing = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", (int)$_GET['edit']) );
    }

    // meta_json (لو استُخدم لاحقاً)
    $meta = [];
    if ( $editing && property_exists($editing, 'meta_json') && !empty($editing->meta_json) ) {
        $decoded = json_decode($editing->meta_json, true);
        if ( is_array($decoded) ) { $meta = $decoded; }
    }

    $qr_enabled  = !empty($meta['qr_enabled']);
    $selfie_mode = $meta['selfie_mode']
        ?? ( $editing ? ( ((int)($editing->require_selfie ?? 0)) ? 'required' : 'optional' ) : 'optional' );

    // Departments للفورم فقط
    $dept_list = Helpers::get_departments_for_select( true );

    ?>
    <div class="wrap">
        <h1>Shifts</h1>

        <h2><?php echo $editing ? 'Edit Shift' : 'Add Shift'; ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field( 'sfs_hr_att_shift_save' ); ?>
            <input type="hidden" name="action" value="sfs_hr_att_shift_save"/>
            <?php if ( $editing ): ?>
                <input type="hidden" name="id" value="<?php echo (int)$editing->id; ?>"/>
            <?php endif; ?>

            <table class="form-table">
                <tr>
                    <th>Name</th>
                    <td>
                        <input required type="text" name="name"
                               value="<?php echo esc_attr($editing->name ?? ''); ?>" class="regular-text"/>
                    </td>
                </tr>

                <tr>
                    <th><?php esc_html_e( 'Departments', 'sfs-hr' ); ?></th>
                    <td>
                        <?php
                        // Get current dept_ids (JSON array) or fall back to single dept_id
                        $current_dept_ids = [];
                        if ( ! empty( $editing->dept_ids ) ) {
                            $decoded = json_decode( $editing->dept_ids, true );
                            if ( is_array( $decoded ) ) {
                                $current_dept_ids = array_map( 'intval', $decoded );
                            }
                        } elseif ( ! empty( $editing->dept_id ) ) {
                            $current_dept_ids = [ (int) $editing->dept_id ];
                        }

                        if ( empty( $dept_list ) ) : ?>
                            <p style="color: #d63638;">
                                <?php esc_html_e( 'No departments defined. Please create departments first.', 'sfs-hr' ); ?>
                            </p>
                        <?php else : ?>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px 20px; max-height: 200px; overflow-y: auto; padding: 10px; border: 1px solid #dcdcde; border-radius: 4px; background: #f9f9f9;">
                                <?php foreach ( $dept_list as $dept ) : ?>
                                    <label style="display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                                        <input type="checkbox" name="dept_ids[]" value="<?php echo esc_attr( $dept['id'] ); ?>"
                                            <?php checked( in_array( (int) $dept['id'], $current_dept_ids, true ) ); ?> />
                                        <?php echo esc_html( $dept['name'] ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description" style="margin-top: 8px;">
                                <?php esc_html_e( 'Select one or more departments this shift applies to. Employees in any selected department can use this shift.', 'sfs-hr' ); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th>Location Label</th>
                    <td>
                        <input required type="text" name="location_label"
                               value="<?php echo esc_attr($editing->location_label ?? ''); ?>" class="regular-text"/>
                    </td>
                </tr>

                <tr>
                    <th>Location (lat,lng,radius m)</th>
                    <td>
                        <input required type="text" name="location_lat" style="width:120px"
                               value="<?php echo esc_attr($editing->location_lat ?? ''); ?>" placeholder="24.7136"/>
                        <input required type="text" name="location_lng" style="width:120px"
                               value="<?php echo esc_attr($editing->location_lng ?? ''); ?>" placeholder="46.6753"/>
                        <input required type="number" name="location_radius_m" min="10" step="1" style="width:120px"
                               value="<?php echo esc_attr($editing->location_radius_m ?? ''); ?>" placeholder="150"/>
                        <p class="description">Geofence is mandatory for Office/Showrooms/Warehouse/Factory.</p>
                    </td>
                </tr>

                <tr>
                    <th>Start / End (local)</th>
                    <td>
                        <?php
                        $start_val = isset($editing->start_time) ? substr((string)$editing->start_time, 0, 5) : '';
                        $end_val   = isset($editing->end_time)   ? substr((string)$editing->end_time,   0, 5) : '';
                        ?>
                        <input required type="time" name="start_time" step="60"
                               value="<?php echo esc_attr($start_val); ?>"/> →
                        <input required type="time" name="end_time" step="60"
                               value="<?php echo esc_attr($end_val); ?>"/>
                        <p class="description">Overnight allowed (end earlier than start).</p>
                    </td>
                </tr>

                <tr>
                    <th>Break policy</th>
                    <td>
                        <select name="break_policy">
                            <?php foreach (['auto','punch','none'] as $bp): ?>
                                <option value="<?php echo esc_attr($bp); ?>"
                                    <?php selected(($editing->break_policy ?? 'auto'), $bp); ?>>
                                    <?php echo esc_html($bp); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        &nbsp;Unpaid break minutes:
                        <input type="number" name="unpaid_break_minutes" min="0" step="1" style="width:120px"
                               value="<?php echo esc_attr($editing->unpaid_break_minutes ?? 0); ?>"/>
                    </td>
                </tr>

                <tr>
                    <th>Grace (late / early)</th>
                    <td>
                        <input type="number" name="grace_late_minutes" min="0" step="1" style="width:120px"
                               value="<?php echo esc_attr($editing->grace_late_minutes ?? 5); ?>"/> /
                        <input type="number" name="grace_early_leave_minutes" min="0" step="1" style="width:120px"
                               value="<?php echo esc_attr($editing->grace_early_leave_minutes ?? 5); ?>"/>
                    </td>
                </tr>

                <tr>
                    <th>Rounding (nearest minutes)</th>
                    <td>
                        <select name="rounding_rule">
                            <?php foreach (['none','5','10','15'] as $r): ?>
                                <option value="<?php echo esc_attr($r); ?>"
                                    <?php selected(($editing->rounding_rule ?? '5'), $r); ?>>
                                    <?php echo esc_html($r); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th>Overtime threshold (minutes)</th>
                    <td>
                        <input type="number" name="overtime_after_minutes" min="0" step="1" style="width:120px"
                               value="<?php echo esc_attr($editing->overtime_after_minutes ?? 0); ?>"/>
                        <p class="description">Set 0 to compute OT as any minutes above scheduled duration.</p>
                    </td>
                </tr>

                <tr>
                    <th>Selfie required</th>
                    <td>
                        <label>
                            <input type="checkbox" name="require_selfie" value="1"
                                <?php checked(!empty($editing->require_selfie)); ?>/>
                            Require selfie
                        </label>
                    </td>
                </tr>

                <tr>
                    <th>Active</th>
                    <td>
                        <label>
                            <input type="checkbox" name="active" value="1"
                                <?php checked(!isset($editing->active) || (int)$editing->active===1); ?>/>
                            Active
                        </label>
                    </td>
                </tr>

                <tr>
                    <th>Weekly Overrides</th>
                    <td>
                        <p class="description" style="margin:0 0 10px;">
                            Override this shift with a different shift on specific days of the week.
                        </p>
                        <?php
                        // Parse existing weekly overrides from notes or dedicated field
                        $weekly_overrides = [];
                        if ( isset( $editing->weekly_overrides ) && ! empty( $editing->weekly_overrides ) ) {
                            $decoded = json_decode( $editing->weekly_overrides, true );
                            if ( is_array( $decoded ) ) {
                                $weekly_overrides = $decoded;
                            }
                        }

                        $days = [
                            'monday'    => 'Monday',
                            'tuesday'   => 'Tuesday',
                            'wednesday' => 'Wednesday',
                            'thursday'  => 'Thursday',
                            'friday'    => 'Friday',
                            'saturday'  => 'Saturday',
                            'sunday'    => 'Sunday',
                        ];

                        foreach ( $days as $day_key => $day_label ) :
                            $selected_shift = $weekly_overrides[ $day_key ] ?? 0;
                            ?>
                            <div style="margin-bottom:8px;">
                                <label style="display:inline-block;width:120px;font-weight:500;">
                                    <?php echo esc_html( $day_label ); ?>:
                                </label>
                                <select name="weekly_override[<?php echo esc_attr( $day_key ); ?>]">
                                    <option value="0">— No override —</option>
                                    <?php foreach ( $rows as $s ) : ?>
                                        <option value="<?php echo (int) $s->id; ?>"
                                            <?php selected( $selected_shift, (int) $s->id ); ?>>
                                            <?php echo esc_html( $s->name . ' (' . $s->start_time . '→' . $s->end_time . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>

                <tr>
                    <th>Notes</th>
                    <td>
                        <textarea name="notes" rows="3" class="large-text"><?php
                            echo esc_textarea($editing->notes ?? '');
                        ?></textarea>
                    </td>
                </tr>
            </table>

            <?php submit_button( $editing ? 'Update Shift' : 'Add Shift' ); ?>
        </form>

        <hr/>
        <h2>Existing Shifts</h2>
        <table class="widefat striped">
            <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Departments</th>
                <th>Start→End</th>
                <th>Geo (m)</th>
                <th>Break</th>
                <th>Grace</th>
                <th>Round</th>
                <th>Active</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $r ): ?>
                <tr>
                    <td><?php echo (int)$r->id; ?></td>
                    <td><?php echo esc_html($r->name); ?></td>
                    <td><?php
                        $dept_names = [];
                        if ( ! empty( $r->dept_ids ) ) {
                            $ids = json_decode( $r->dept_ids, true );
                            if ( is_array( $ids ) ) {
                                foreach ( $ids as $did ) {
                                    if ( isset( $depts[ $did ] ) ) {
                                        $dept_names[] = $depts[ $did ];
                                    }
                                }
                            }
                        } elseif ( ! empty( $r->dept_id ) && isset( $depts[ $r->dept_id ] ) ) {
                            $dept_names[] = $depts[ $r->dept_id ];
                        }
                        echo ! empty( $dept_names )
                            ? esc_html( implode( ', ', $dept_names ) )
                            : '<em style="color:#999;">All Departments</em>';
                    ?></td>
                    <td><?php echo esc_html($r->start_time . ' → ' . $r->end_time); ?></td>
                    <td><?php echo esc_html($r->location_label . ' (' . (float)$r->location_radius_m . 'm)'); ?></td>
                    <td><?php echo esc_html($r->break_policy . ' / ' . (int)$r->unpaid_break_minutes . 'm'); ?></td>
                    <td><?php echo (int)$r->grace_late_minutes . '/' . (int)$r->grace_early_leave_minutes; ?></td>
                    <td><?php echo esc_html($r->rounding_rule); ?></td>
                    <td><?php echo $r->active ? 'Yes' : 'No'; ?></td>
                    <td>
                        <a class="button button-small"
                           href="<?php echo esc_url(
                               admin_url('admin.php?page=sfs_hr_attendance&tab=shifts&edit='.(int)$r->id)
                           ); ?>">Edit</a>

                        <form style="display:inline" method="post"
                              action="<?php echo esc_url( admin_url('admin-post.php') ); ?>"
                              onsubmit="return confirm('Delete this shift?');">
                            <?php wp_nonce_field( 'sfs_hr_att_shift_delete' ); ?>
                            <input type="hidden" name="action" value="sfs_hr_att_shift_delete"/>
                            <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>"/>
                            <button class="button button-small" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}


    public function handle_shift_save(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
    check_admin_referer( 'sfs_hr_att_shift_save' );

    global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_shifts';

    $id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name    = sanitize_text_field( $_POST['name'] ?? '' );

    // Handle multiple department selection
    $dept_ids_raw = isset( $_POST['dept_ids'] ) && is_array( $_POST['dept_ids'] ) ? $_POST['dept_ids'] : [];
    $dept_ids = array_filter( array_map( 'intval', $dept_ids_raw ) );
    $dept_ids_json = ! empty( $dept_ids ) ? wp_json_encode( array_values( $dept_ids ) ) : null;
    // Keep first dept_id for backward compatibility
    $dept_id = ! empty( $dept_ids ) ? $dept_ids[0] : 0;

    $loc_label = sanitize_text_field( $_POST['location_label'] ?? '' );
    $lat = is_numeric($_POST['location_lat'] ?? null) ? (float)$_POST['location_lat'] : null;
    $lng = is_numeric($_POST['location_lng'] ?? null) ? (float)$_POST['location_lng'] : null;
    $rad = max(10, (int)($_POST['location_radius_m'] ?? 0));

    // Accept "HH:MM" or "HH:MM:SS" from the form and normalize to "HH:MM:SS".
$norm_time = static function ($v): string {
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') { return ''; }
    if (preg_match('/^\d{2}:\d{2}$/', $v)) {
        return $v . ':00';
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $v)) {
        return $v;
    }
    return ''; // invalid format → will trigger "Missing required fields"
};

$start = $norm_time($_POST['start_time'] ?? '');
$end   = $norm_time($_POST['end_time']   ?? '');


    $break_policy = in_array( $_POST['break_policy'] ?? 'auto', ['auto','punch','none'], true ) ? $_POST['break_policy'] : 'auto';
    $unpaid_break = max(0, (int)($_POST['unpaid_break_minutes'] ?? 0));

    $grace_l = max(0, (int)($_POST['grace_late_minutes'] ?? 5));
    $grace_e = max(0, (int)($_POST['grace_early_leave_minutes'] ?? 5));
    $round   = in_array( $_POST['rounding_rule'] ?? '5', ['none','5','10','15'], true ) ? $_POST['rounding_rule'] : '5';
    $ot_thr  = max(0, (int)($_POST['overtime_after_minutes'] ?? 0));
    $selfie  = !empty($_POST['require_selfie']) ? 1 : 0;
    $active  = !empty($_POST['active']) ? 1 : 0;
    $notes   = wp_kses_post( $_POST['notes'] ?? '' );

    // Weekly overrides: store as JSON
    $weekly_override_raw = $_POST['weekly_override'] ?? [];
    $weekly_override = [];
    if ( is_array( $weekly_override_raw ) ) {
        foreach ( $weekly_override_raw as $day => $shift_id ) {
            $shift_id = (int) $shift_id;
            if ( $shift_id > 0 ) {
                $weekly_override[ sanitize_key( $day ) ] = $shift_id;
            }
        }
    }
    $weekly_override_json = ! empty( $weekly_override ) ? wp_json_encode( $weekly_override ) : '';

    // Enforce required fields ONLY when saving an ACTIVE shift.
    if ( $active === 1 ) {
        $missing = [];
        if ($name === '')      $missing[] = 'name';
        if ($loc_label === '') $missing[] = 'location_label';
        if ($lat === null)     $missing[] = 'location_lat';
        if ($lng === null)     $missing[] = 'location_lng';
        if ($start === '')     $missing[] = 'start_time';
        if ($end === '')       $missing[] = 'end_time';
        if ($missing) {
            wp_die('Missing required fields: ' . esc_html(implode(', ', $missing)) . '.');
        }
    }

    $data = [
        'name'                     => $name,
        'location_label'           => $loc_label,
        'location_lat'             => $lat,
        'location_lng'             => $lng,
        'location_radius_m'        => $rad,
        'start_time'               => $start,
        'end_time'                 => $end,
        'unpaid_break_minutes'     => $unpaid_break,
        'break_policy'             => $break_policy,
        'grace_late_minutes'       => $grace_l,
        'grace_early_leave_minutes'=> $grace_e,
        'rounding_rule'            => $round,
        'overtime_after_minutes'   => $ot_thr,
        'require_selfie'           => $selfie,
        'active'                   => $active,
        'dept_id'                  => $dept_id > 0 ? $dept_id : null,
        'dept_ids'                 => $dept_ids_json,
        'notes'                    => $notes,
        'weekly_overrides'         => $weekly_override_json,
    ];

    if ( $id ) {
        $wpdb->update( $t, $data, ['id'=>$id] );
    } else {
        $wpdb->insert( $t, $data );
    }

    wp_safe_redirect( admin_url('admin.php?page=sfs_hr_attendance&tab=shifts&saved=1') );
    exit;
}


    public function handle_shift_delete(): void {
        if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
        check_admin_referer( 'sfs_hr_att_shift_delete' );
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $id = (int)($_POST['id'] ?? 0);
        if ( $id ) { $wpdb->delete( $t, ['id'=>$id] ); }
        wp_safe_redirect( admin_url('admin.php?page=sfs_hr_attendance&tab=shifts&deleted=1') );
        exit;
    }

    /* ========================= ASSIGNMENTS (BULK) ========================= */

public function render_assignments(): void {
    if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
    global $wpdb;

    $dept_id_filter = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;

    // Shifts (active only)
    $shiftT = $wpdb->prefix . 'sfs_hr_attendance_shifts';
    $shifts = $wpdb->get_results( "SELECT id,name,dept,location_label,start_time,end_time,location_radius_m FROM {$shiftT} WHERE active=1 ORDER BY dept,name" );

// Employees (schema-robust; join wp_users and departments; no helpers required)
$empT   = $wpdb->prefix . 'sfs_hr_employees';
$usersT = $wpdb->users;
$deptT  = $wpdb->prefix . 'sfs_hr_departments';

// Inspect employee columns
$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$empT}", 0 ) ?: [];
$has  = static function($c) use ($cols) { return in_array($c, $cols, true); };

// Name expression (fallback chain)
$nameParts = [];
if ( $has('full_name') )                     $nameParts[] = 'e.full_name';
if ( $has('name') )                          $nameParts[] = 'e.name';
if ( $has('first_name') && $has('last_name'))$nameParts[] = "CONCAT(e.first_name,' ',e.last_name)";
$nameParts[] = 'u.display_name';
$nameParts[] = 'u.user_login';
$nameSQL = 'COALESCE(' . implode(',', $nameParts) . ') AS full_name';

// Department label (slug or via department_id join)
$deptSlugCol = $has('dept') ? 'e.dept' : ( $has('department') ? 'e.department' : null );
$deptIdCol   = $has('department_id') ? 'e.department_id' : ( $has('dept_id') ? 'e.dept_id' : null );

$deptLabelSQL = "'unknown' AS dept_label";
$joinDept = '';
if ( $deptSlugCol ) {
    $deptLabelSQL = "{$deptSlugCol} AS dept_label";
} elseif ( $deptIdCol ) {
    $exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $deptT) );
    if ( $exists ) {
        $joinDept = "LEFT JOIN {$deptT} d ON d.id = {$deptIdCol}";
        $deptLabelSQL = "COALESCE(d.name,'unknown') AS dept_label";
    }
}

// Optional department filter (by numeric department_id only)
$where = [];
if ( $dept_id_filter && $deptIdCol ) {
    $where[] = $wpdb->prepare("{$deptIdCol} = %d", $dept_id_filter);
}
$whereSQL = $where ? 'WHERE '.implode(' AND ', $where) : '';

// Final query
$sql = "
SELECT
    e.id AS id,
    {$nameSQL},
    {$deptLabelSQL}
FROM {$empT} e
LEFT JOIN {$usersT} u ON u.ID = e.user_id
{$joinDept}
{$whereSQL}
ORDER BY full_name
";
$employees   = $wpdb->get_results( $sql );
$departments = $this->get_departments( $wpdb );


    // Build SHIFT_META for inline preview
    $meta = [];
    foreach ( $shifts as $s ) {
        $meta[ (int)$s->id ] = [
            'dept'   => (string)$s->dept,
            'label'  => (string)$s->location_label,
            'start'  => (string)$s->start_time,
            'end'    => (string)$s->end_time,
            'radius' => (int)$s->location_radius_m,
        ];
    }
    $meta_json = wp_json_encode( $meta );
    ?>
    <div class="wrap">
        <h1>Assignments (Bulk)</h1>
        <p>Assign a shift over a date range (e.g., Ramadan). Shifts carry location/geofence and rules.</p>

        <!-- Department filter (server-side) -->
        <form method="get" style="margin:10px 0 20px 0;">
            <input type="hidden" name="page" value="sfs_hr_attendance"/>
            <input type="hidden" name="tab" value="assign"/>
            <label>Filter employees by Department:&nbsp;
                <select name="dept_id" onchange="this.form.submit()">
                    <option value="0">— All departments —</option>
                    <?php foreach ( $departments as $d ): ?>
                        <option value="<?php echo (int)$d['id']; ?>" <?php selected($dept_id_filter, (int)$d['id']); ?>>
                            <?php echo esc_html($d['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field( 'sfs_hr_att_assign_bulk' ); ?>
            <input type="hidden" name="action" value="sfs_hr_att_assign_bulk"/>

            <table class="form-table">
                <tr><th>Shift</th><td>
                    <select name="shift_id" required id="sfs-assign-shift">
                        <?php foreach ( $shifts as $s ): ?>
                            <option value="<?php echo (int)$s->id; ?>">
                                <?php echo esc_html("{$s->name} ({$s->dept})"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="sfs-shift-preview" class="description" style="margin-top:6px"></div>
                </td></tr>

                <tr><th>Date range</th><td>
                    <input type="date" name="start_date" required/> →
                    <input type="date" name="end_date" required/>
                </td></tr>

                <tr><th>Employees</th><td>
                    <select name="employee_id[]" multiple size="12" style="min-width:360px" required>
                        <?php foreach ( $employees as $e ): ?>
<option value="<?php echo (int)$e->id; ?>">
    <?php echo esc_html("{$e->full_name} ({$e->dept_label})"); ?>
</option>

                        <?php endforeach; ?>
                    </select>
                    <p class="description">Hold Ctrl/Cmd to select multiple.</p>
                </td></tr>

                <tr><th>Overwrite existing</th><td>
                    <label><input type="checkbox" name="overwrite" value="1"/> Replace existing assignments in range</label>
                </td></tr>
            </table>

            <?php submit_button('Assign'); ?>
        </form>
    </div>

    <script>
    (function(){
        const META = <?php echo $meta_json ? $meta_json : '{}'; ?>;
        const sel  = document.getElementById('sfs-assign-shift');
        const box  = document.getElementById('sfs-shift-preview');
        function render(){
            const id = sel.value;
            if (!META[id]) { box.textContent = ''; return; }
            const m = META[id];
            box.textContent = `Location: ${m.label} | Dept: ${m.dept} | ${m.start} → ${m.end} | Radius: ${m.radius}m`;
        }
        sel.addEventListener('change', render);
        render();
    })();
    </script>
    <?php
}


    public function handle_assign_bulk(): void {
        if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
        check_admin_referer( 'sfs_hr_att_assign_bulk' );

        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';

        $shift_id = (int)($_POST['shift_id'] ?? 0);
        $sd = sanitize_text_field( $_POST['start_date'] ?? '' );
        $ed = sanitize_text_field( $_POST['end_date'] ?? '' );
        $emps = array_map( 'intval', (array)($_POST['employee_id'] ?? []) );
        $overwrite = !empty($_POST['overwrite']);

        if ( ! $shift_id || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed) || empty($emps) ) {
            wp_die('Invalid input.');
        }
        if ( $ed < $sd ) { wp_die('End date before start date.'); }

        $start = new \DateTimeImmutable($sd);
        $end   = new \DateTimeImmutable($ed);
        $days  = (int)$start->diff($end)->format('%a');

        for ( $i=0; $i <= $days; $i++ ) {
            $d = $start->modify("+{$i} day")->format('Y-m-d');
            foreach ( $emps as $eid ) {
                if ( $overwrite ) {
                    $wpdb->delete( $t, ['employee_id'=>$eid, 'work_date'=>$d] );
                }
                // UNIQUE (employee_id, work_date) — insert ignore pattern
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$t} WHERE employee_id=%d AND work_date=%s LIMIT 1",
                    $eid, $d
                ) );
                if ( ! $exists ) {
                    $wpdb->insert( $t, [
                        'employee_id' => $eid,
                        'shift_id'    => $shift_id,
                        'work_date'   => $d,
                        'is_holiday'  => 0,
                        'override_json'=> null,
                    ] );
                }
            }
        }

        wp_safe_redirect( admin_url('admin.php?page=sfs_hr_attendance&tab=assign&done=1') );
        exit;
    }
    /* ========================= Exceptions ========================= */
    
public function render_exceptions(): void {
    if ( ! current_user_can('sfs_hr_attendance_view_team') ) { wp_die('Access denied'); }
    global $wpdb;

    $date = ( isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ) ? $_GET['date'] : wp_date('Y-m-d');
    $dept_id = isset($_GET['dept_id']) ? (int)$_GET['dept_id'] : 0;

    $sT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
    $eT = $wpdb->prefix . 'sfs_hr_employees';
    $uT = $wpdb->users;
    $dT = $wpdb->prefix . 'sfs_hr_departments';

    $joinDept  = '';
    $whereDept = '';
    $exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $dT) );
    if ( $exists ) {
        $joinDept = "LEFT JOIN {$dT} d ON d.id = e.department_id";
        if ( $dept_id > 0 ) {
            $whereDept = $wpdb->prepare("AND e.department_id=%d", $dept_id);
        }
    }

    // Check which flag columns exist in sessions table
    $sCols = array_map('strval', $wpdb->get_col("SHOW COLUMNS FROM {$sT}", 0) ?: []);
    $hasS  = static fn($c) => in_array($c, $sCols, true);

    // Build exception conditions only for columns that exist
    $conditions = [];
    if ($hasS('late_flag'))         $conditions[] = 's.late_flag=1';
    if ($hasS('early_flag'))        $conditions[] = 's.early_flag=1';
    if ($hasS('missed_punch_flag')) $conditions[] = 's.missed_punch_flag=1';
    if ($hasS('outside_geo_count')) $conditions[] = 's.outside_geo_count>0';
    if ($hasS('no_selfie_count'))   $conditions[] = 's.no_selfie_count>0';
    if ($hasS('flags_json'))        $conditions[] = "(s.flags_json IS NOT NULL AND s.flags_json LIKE '%over_break:%')";

    $exceptionsWhere = $conditions ? '(' . implode("\n   OR ", $conditions) . ')' : '1=0';

    $sql = $wpdb->prepare("
      SELECT s.*, e.user_id, u.display_name, COALESCE(d.name,'') AS dept_name
      FROM {$sT} s
      LEFT JOIN {$eT} e ON e.id = s.employee_id
      LEFT JOIN {$uT} u ON u.ID = e.user_id
      {$joinDept}
      WHERE s.work_date=%s
        AND {$exceptionsWhere}
        {$whereDept}
      ORDER BY u.display_name ASC
    ", $date);
    $rows = $wpdb->get_results( $sql );

    $departments = $this->get_departments( $wpdb );

    $export_url = esc_url( wp_nonce_url(
        add_query_arg(['action'=>'sfs_hr_att_export_csv','from'=>$date,'to'=>$date,'dept_id'=>$dept_id], admin_url('admin-post.php')),
        'sfs_hr_att_export_csv'
    ) );
    ?>
    <div class="wrap">
      <h1>Exceptions</h1>
      <form method="get" style="margin:10px 0">
        <input type="hidden" name="page" value="sfs_hr_attendance"/>
        <input type="hidden" name="tab"  value="exceptions"/>
        <label>Date:
          <input type="date" name="date" value="<?php echo esc_attr($date); ?>"/>
        </label>
        <label style="margin-left:16px">Department:
          <select name="dept_id">
            <option value="0">— All —</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?php echo (int)$d['id']; ?>" <?php selected($dept_id,(int)$d['id']); ?>>
                <?php echo esc_html($d['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="button">Filter</button>
        <a class="button" style="margin-left:8px" href="<?php echo $export_url; ?>">Export CSV</a>
      </form>

      <table class="widefat striped">
        <thead><tr>
          <th>Employee</th><th>Dept</th><th>In</th><th>Out</th><th>Worked (r)</th><th>OT</th><th>Flags</th><th>Geo</th><th>Selfie</th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php
                $profile_url = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . (int) $r->employee_id);
                $emp_label = $r->display_name ?: ('#'.$r->employee_id);
              ?><a href="<?php echo esc_url($profile_url); ?>" style="color:#2271b1;text-decoration:none;font-weight:500;"><?php echo esc_html($emp_label); ?></a></td>
              <td><?php echo esc_html( $r->dept_name ); ?></td>
              <td><?php echo esc_html( $r->in_time ?: '-' ); ?></td>
              <td><?php echo esc_html( $r->out_time ?: '-' ); ?></td>
              <td><?php echo (int)$r->worked_minutes_rounded; ?>m</td>
              <td><?php echo (int)$r->ot_minutes; ?>m</td>
              <td>
                <?php
                  $flags = [];
                  if (!empty($r->late_flag)) $flags[]='late';
                  if (!empty($r->early_flag)) $flags[]='early';
                  if (!empty($r->missed_punch_flag)) $flags[]='missed';
                  echo esc_html( $flags ? implode(', ', $flags) : '-' );
                ?>
              </td>
              <td><?php echo isset($r->outside_geo_count) ? (int)$r->outside_geo_count : '-'; ?></td>
              <td><?php echo isset($r->no_selfie_count) ? (int)$r->no_selfie_count : '-'; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="9">No exceptions for this selection.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
}


   
   public function handle_export_csv(): void {
    if ( ! current_user_can('sfs_hr_attendance_view_team') ) { wp_die('Access denied'); }
    check_admin_referer('sfs_hr_att_export_csv');

    global $wpdb;

    $from = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : wp_date('Y-m-01');
    $to   = isset($_GET['to'])   ? sanitize_text_field($_GET['to'])   : wp_date('Y-m-d');
    if ( !preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$to) || $to < $from ) {
        wp_die('Invalid date range');
    }

    // Optional on-demand rebuild
    if ( !empty($_GET['recalc']) ) {
        $pT = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $days = $wpdb->get_col( $wpdb->prepare("
            SELECT DATE(punch_time) AS d
            FROM {$pT}
            WHERE DATE(punch_time) BETWEEN %s AND %s
            GROUP BY d ORDER BY d ASC
        ", $from, $to) );
        if ($days) foreach ($days as $d) { $this->rebuild_sessions_for_date($d); }
    }

    $sT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
    $eT = $wpdb->prefix . 'sfs_hr_employees';
    $uT = $wpdb->users;

    // Be tolerant to column name differences
    $sCols = array_map('strval', $wpdb->get_col("SHOW COLUMNS FROM {$sT}", 0) ?: []);
    $hasS  = static fn($c) => in_array($c, $sCols, true);

    $roundedCol = $hasS('rounded_net_minutes') ? 's.rounded_net_minutes'
                  : ($hasS('worked_minutes_rounded') ? 's.worked_minutes_rounded' : 's.net_minutes');

    $otCol      = $hasS('overtime_minutes') ? 's.overtime_minutes'
                  : ($hasS('ot_minutes') ? 's.ot_minutes' : '0');

    $inCol      = $hasS('in_time')  ? 's.in_time'  : 'NULL';
    $outCol     = $hasS('out_time') ? 's.out_time' : 'NULL';
    $breakCol   = $hasS('break_minutes') ? 's.break_minutes' : '0';
    $netCol     = $hasS('net_minutes')   ? 's.net_minutes'   : $roundedCol;

    $lateCol    = $hasS('late_flag')         ? 's.late_flag'         : '0';
    $earlyCol   = $hasS('early_flag')        ? 's.early_flag'        : '0';
    $missedCol  = $hasS('missed_punch_flag') ? 's.missed_punch_flag' : '0';
    $lockedCol  = $hasS('locked')            ? 's.locked'            : '0';
    $flagsCol   = $hasS('flags_json')        ? 's.flags_json'        : 'NULL';
    $outGeoCol  = $hasS('outside_geo_count') ? 's.outside_geo_count' : '0';
    $noSelfCol  = $hasS('no_selfie_count')   ? 's.no_selfie_count'   : '0';

    // employee_code may not exist everywhere
    $eCols = array_map('strval', $wpdb->get_col("SHOW COLUMNS FROM {$eT}", 0) ?: []);
    $hasE  = static fn($c) => in_array($c, $eCols, true);
    $empCodeCol = $hasE('employee_code') ? 'e.employee_code' : "''";

    $rows = $wpdb->get_results( $wpdb->prepare("
        SELECT
            s.work_date,
            s.employee_id,
            {$empCodeCol}  AS employee_code,
            u.display_name AS name,
            {$inCol}       AS in_time_utc,
            {$outCol}      AS out_time_utc,
            {$breakCol}    AS break_minutes,
            {$netCol}      AS net_minutes,
            {$roundedCol}  AS rounded_minutes,
            {$otCol}       AS ot_minutes,
            {$lateCol}     AS late,
            {$earlyCol}    AS early,
            {$missedCol}   AS missed,
            {$outGeoCol}   AS outside_geo_count,
            {$noSelfCol}   AS no_selfie_count,
            {$flagsCol}    AS flags_json,
            {$lockedCol}   AS locked
        FROM {$sT} s
        LEFT JOIN {$eT} e ON e.id = s.employee_id
        LEFT JOIN {$uT} u ON u.ID = e.user_id
        WHERE s.work_date BETWEEN %s AND %s
        ORDER BY s.work_date ASC, u.display_name ASC
    ", $from, $to ), ARRAY_A );

    // Output CSV
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=attendance_'.$from.'_'.$to.'.csv');

    $out = fopen('php://output','w');
    fputcsv($out, [
        'date','employee_id','employee_code','name',
        'in_time_local','out_time_local',
        'break_minutes','net_minutes','rounded_minutes','ot_minutes',
        'late','early','missed','outside_geo','no_selfie','flags','locked'
    ]);

    foreach ((array)$rows as $r) {
        // Convert in/out to local
        $inLocal  = !empty($r['in_time_utc'])  ? \SFS\HR\Modules\Attendance\AttendanceModule::fmt_local($r['in_time_utc'])  : '';
        $outLocal = !empty($r['out_time_utc']) ? \SFS\HR\Modules\Attendance\AttendanceModule::fmt_local($r['out_time_utc']) : '';

        // Compact flags
        $flagsTxt = '';
        if (!empty($r['flags_json'])) {
            $fj = json_decode((string)$r['flags_json'], true);
            if (is_array($fj) && $fj) { $flagsTxt = implode(', ', array_map('strval', $fj)); }
        }

        fputcsv($out, [
            $r['work_date'],
            (int)$r['employee_id'],
            (string)$r['employee_code'],
            (string)$r['name'],
            $inLocal,
            $outLocal,
            (int)$r['break_minutes'],
            (int)$r['net_minutes'],
            (int)$r['rounded_minutes'],
            (int)$r['ot_minutes'],
            (int)$r['late'],
            (int)$r['early'],
            (int)$r['missed'],
            (int)$r['outside_geo_count'],
            (int)$r['no_selfie_count'],
            $flagsTxt,
            (int)$r['locked'],
        ]);
    }
    fclose($out);
    exit;
}

 
    
    
    /* ========================= DEVICES (KIOSK) ========================= */

    public function render_devices(): void {
        if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_devices';

        $rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY active DESC, allowed_dept, label" );
        // Get departments from Departments module
        $dept_list   = Helpers::get_departments_for_select( true );
        $dept_labels = [];
        foreach ( $dept_list as $slug => $d ) {
            $dept_labels[ $slug ] = $d['name'];
        }
        $dept_labels['any'] = __( 'Any', 'sfs-hr' );

        $editing = null;
        if ( isset($_GET['edit']) ) {
            $editing = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", (int)$_GET['edit']) );
        }
        $qr_enabled  = $editing ? ( (int)($editing->qr_enabled ?? 1) === 1 ) : false;
$selfie_mode = $editing ? (string)($editing->selfie_mode ?? 'inherit') : 'inherit';


        ?>
        <div class="wrap">
            <h1>Devices (Kiosk)</h1>

            <h2><?php echo $editing ? 'Edit Device' : 'Add Device'; ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field( 'sfs_hr_att_device_save' ); ?>
                <input type="hidden" name="action" value="sfs_hr_att_device_save"/>
                <?php if ( $editing ): ?><input type="hidden" name="id" value="<?php echo (int)$editing->id; ?>"/><?php endif; ?>

                <table class="form-table">
                    <tr><th>Label</th><td><input required type="text" name="label" value="<?php echo esc_attr($editing->label ?? ''); ?>" class="regular-text"/></td></tr>
                    <tr><th>Type</th><td>
                        <select name="type">
                            <?php foreach (['kiosk','mobile','web'] as $tp): ?>
                                <option value="<?php echo esc_attr($tp); ?>" <?php selected(($editing->type ?? 'kiosk'), $tp); ?>><?php echo esc_html($tp); ?></option>
                            <?php endforeach; ?>
                        </select>
                        &nbsp;<label><input type="checkbox" name="kiosk_enabled" value="1" <?php checked(!empty($editing->kiosk_enabled)); ?>/> Kiosk enabled</label>
                        &nbsp;<label><input type="checkbox" name="kiosk_offline" value="1" <?php checked(!empty($editing->kiosk_offline)); ?>/> Allow offline</label>
                        
                    </td></tr>
                    <tr>
  <th><?php esc_html_e('QR & Selfie','sfs-hr'); ?></th>
  <td>
    <label style="margin-right:12px;">
      <input type="checkbox" name="qr_enabled" value="1" <?php checked($qr_enabled); ?> />
      <?php esc_html_e('Enable QR scanning for this kiosk','sfs-hr'); ?>
    </label>
    <label style="margin-left:16px;">
      <?php esc_html_e('Selfie at kiosk:','sfs-hr'); ?>
      <select name="selfie_mode">
  <?php
  $modes = [
    'inherit' => 'Inherit (Shift/Employee/Dept)',
    'never'   => 'Never',
    'in_only' => 'Only on Clock In',
    'in_out'  => 'On In & Out',
    'all'     => 'All punches',
  ];
  foreach ($modes as $k=>$label) : ?>
    <option value="<?php echo esc_attr($k); ?>" <?php selected($selfie_mode,$k); ?>>
      <?php echo esc_html($label); ?>
    </option>
  <?php endforeach; ?>
</select>

    </label>
    <p class="description">
      <?php esc_html_e('These settings are per device. Shift “require selfie” still applies to web/self, but kiosk can enforce its own selfie rule.','sfs-hr'); ?>
    </p>
  </td>
</tr>

                    <tr><th>Manager PIN (set/replace)</th><td>
                        <input type="password" name="kiosk_pin" class="regular-text" placeholder="<?php echo $editing ? '(leave blank to keep existing)' : 'Set PIN'; ?>"/>
                        <p class="description">Stored as hashed; kiosk can work when manager is not present.</p>
                    </td></tr>
                    <tr><th>Geo lock (lat,lng,radius m)</th><td>
                        <input type="text" name="geo_lock_lat" style="width:120px" value="<?php echo esc_attr($editing->geo_lock_lat ?? ''); ?>"/>
                        <input type="text" name="geo_lock_lng" style="width:120px" value="<?php echo esc_attr($editing->geo_lock_lng ?? ''); ?>"/>
                        <input type="number" name="geo_lock_radius_m" min="10" step="1" style="width:120px" value="<?php echo esc_attr($editing->geo_lock_radius_m ?? ''); ?>"/>
                    </td></tr>
                                       <tr><th>Allowed dept</th><td>
                        <select name="allowed_dept">
                            <?php
                            $current_dept = (string) ( $editing->allowed_dept ?? 'any' );
                            ?>
                            <option value="any" <?php selected( $current_dept, 'any' ); ?>>
                                <?php esc_html_e( 'Any', 'sfs-hr' ); ?>
                            </option>
                            <?php if ( ! empty( $dept_list ) ) : ?>
                                <?php foreach ( $dept_list as $slug => $dept ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>"
                                        <?php selected( $current_dept, $slug ); ?>>
                                        <?php echo esc_html( $dept['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        &nbsp;<label><input type="checkbox" name="active" value="1" <?php checked(!isset($editing->active) || (int)$editing->active===1); ?>/> Active</label>
                    </td></tr>

                    <tr>
                        <th><?php esc_html_e('Time-Based Suggestions', 'sfs-hr'); ?></th>
                        <td>
                            <p class="description" style="margin-top:0;">
                                <?php esc_html_e('Configure typical times for each action. The kiosk will highlight actions when current time is within ±30 minutes of these times.', 'sfs-hr'); ?>
                            </p>
                            <table style="margin-top:8px;">
                                <tr>
                                    <td style="padding:4px 8px;"><strong><?php esc_html_e('Clock In:', 'sfs-hr'); ?></strong></td>
                                    <td style="padding:4px 8px;">
                                        <input type="time" name="suggest_in_time" value="<?php echo esc_attr($editing->suggest_in_time ?? ''); ?>" style="width:120px;"/>
                                        <span class="description"><?php esc_html_e('e.g., 08:00', 'sfs-hr'); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 8px;"><strong><?php esc_html_e('Break Start:', 'sfs-hr'); ?></strong></td>
                                    <td style="padding:4px 8px;">
                                        <input type="time" name="suggest_break_start_time" value="<?php echo esc_attr($editing->suggest_break_start_time ?? ''); ?>" style="width:120px;"/>
                                        <span class="description"><?php esc_html_e('e.g., 12:00', 'sfs-hr'); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 8px;"><strong><?php esc_html_e('Break End:', 'sfs-hr'); ?></strong></td>
                                    <td style="padding:4px 8px;">
                                        <input type="time" name="suggest_break_end_time" value="<?php echo esc_attr($editing->suggest_break_end_time ?? ''); ?>" style="width:120px;"/>
                                        <span class="description"><?php esc_html_e('e.g., 13:00', 'sfs-hr'); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 8px;"><strong><?php esc_html_e('Clock Out:', 'sfs-hr'); ?></strong></td>
                                    <td style="padding:4px 8px;">
                                        <input type="time" name="suggest_out_time" value="<?php echo esc_attr($editing->suggest_out_time ?? ''); ?>" style="width:120px;"/>
                                        <span class="description"><?php esc_html_e('e.g., 17:00', 'sfs-hr'); ?></span>
                                    </td>
                                </tr>
                            </table>
                            <p class="description">
                                <?php esc_html_e('Leave blank to disable highlighting for that action. Times use 24-hour format.', 'sfs-hr'); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <?php submit_button( $editing ? 'Update Device' : 'Add Device' ); ?>
            </form>

            <hr/>
            <h2>Existing Devices</h2>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>Label</th><th>Type</th><th>Dept</th><th>Offline</th><th>Active</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $rows as $r ): ?>
                    <tr>
                        <td><?php echo (int)$r->id; ?></td>
                        <td><?php echo esc_html($r->label); ?></td>
                        <td><?php echo esc_html($r->type); ?></td>
                        <td>
    <?php
    $key   = (string) $r->allowed_dept;
    $label = $dept_labels[ $key ] ?? $key;
    echo esc_html( $label );
    ?>
</td>

                        <td><?php echo $r->kiosk_offline ? 'Yes' : 'No'; ?></td>
                        <td><?php echo $r->active ? 'Yes' : 'No'; ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url( admin_url('admin.php?page=sfs_hr_attendance&tab=devices&edit='.(int)$r->id) ); ?>">Edit</a>
                            <form style="display:inline" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" onsubmit="return confirm('Delete this device?');">
                                <?php wp_nonce_field( 'sfs_hr_att_device_delete' ); ?>
                                <input type="hidden" name="action" value="sfs_hr_att_device_delete"/>
                                <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>"/>
                                <button class="button button-small" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_device_save(): void {
        if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
        check_admin_referer( 'sfs_hr_att_device_save' );
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_devices';

        $id    = (int)($_POST['id'] ?? 0);
        $label = sanitize_text_field( $_POST['label'] ?? '' );
        $type  = in_array( $_POST['type'] ?? 'kiosk', ['kiosk','mobile','web'], true ) ? $_POST['type'] : 'kiosk';
        $kiosk_enabled = !empty($_POST['kiosk_enabled']) ? 1 : 0;
        $kiosk_offline = !empty($_POST['kiosk_offline']) ? 1 : 0;
        $pin_raw = (string)($_POST['kiosk_pin'] ?? '');

        $lat = is_numeric($_POST['geo_lock_lat'] ?? null) ? (float)$_POST['geo_lock_lat'] : null;
        $lng = is_numeric($_POST['geo_lock_lng'] ?? null) ? (float)$_POST['geo_lock_lng'] : null;
        $rad = is_numeric($_POST['geo_lock_radius_m'] ?? null) ? max(10, (int)$_POST['geo_lock_radius_m']) : null;
        $allowed_dept = sanitize_text_field( $_POST['allowed_dept'] ?? 'any' );
        $active = !empty($_POST['active']) ? 1 : 0;
        
        $qr_enabled  = !empty($_POST['qr_enabled']) ? 1 : 0;
$selfie_mode = in_array(($_POST['selfie_mode'] ?? 'inherit'), ['inherit','never','in_only','in_out','all'], true) ? $_POST['selfie_mode'] : 'inherit';

        // Time-based suggestion fields (HH:MM format)
        $suggest_in_time         = $this->sanitize_time_input($_POST['suggest_in_time'] ?? '');
        $suggest_break_start_time = $this->sanitize_time_input($_POST['suggest_break_start_time'] ?? '');
        $suggest_break_end_time   = $this->sanitize_time_input($_POST['suggest_break_end_time'] ?? '');
        $suggest_out_time        = $this->sanitize_time_input($_POST['suggest_out_time'] ?? '');

// …and mirror into meta_json for backwards-compat (your TODO)
$meta = [];
if ($id) {
    $meta_existing = $wpdb->get_var( $wpdb->prepare("SELECT meta_json FROM {$t} WHERE id=%d", $id) );
    if ($meta_existing) { $tmp = json_decode((string)$meta_existing, true); if (is_array($tmp)) $meta = $tmp; }
}
// after reading/validating inputs and building $meta:
$meta['qr_enabled']  = (bool)$qr_enabled;
$meta['selfie_mode'] = $selfie_mode;

// Build final $data ONCE (do not overwrite later)
$data = [
    'label'             => $label,
    'type'              => $type,
    'kiosk_enabled'     => $kiosk_enabled,
    'kiosk_offline'     => $kiosk_offline,
    'geo_lock_lat'      => $lat,
    'geo_lock_lng'      => $lng,
    'geo_lock_radius_m' => $rad,
    'allowed_dept'      => $allowed_dept,
    'active'            => $active,

    // keep these in columns
    'qr_enabled'        => $qr_enabled,
    'selfie_mode'       => $selfie_mode,

    // time-based action suggestions
    'suggest_in_time'         => $suggest_in_time,
    'suggest_break_start_time' => $suggest_break_start_time,
    'suggest_break_end_time'   => $suggest_break_end_time,
    'suggest_out_time'        => $suggest_out_time,

    // mirror for compatibility/debug
    'meta_json'         => wp_json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
];

if ( $pin_raw !== '' ) {
    $data['kiosk_pin'] = wp_hash_password( $pin_raw );
}

if ( $id ) { $wpdb->update( $t, $data, ['id'=>$id] ); }
else       { $wpdb->insert( $t, $data ); }

wp_safe_redirect( admin_url('admin.php?page=sfs_hr_attendance&tab=devices&saved=1') );
exit;
    }

    public function handle_device_delete(): void {
        if ( ! current_user_can( 'sfs_hr_attendance_admin' ) ) { wp_die('Access denied'); }
        check_admin_referer( 'sfs_hr_att_device_delete' );
        global $wpdb; $t = $wpdb->prefix . 'sfs_hr_attendance_devices';
        $id = (int)($_POST['id'] ?? 0);
        if ( $id ) { $wpdb->delete( $t, ['id'=>$id] ); }
        wp_safe_redirect( admin_url('admin.php?page=sfs_hr_attendance&tab=devices&deleted=1') );
        exit;
    }

    /**
     * Sanitize time input (HH:MM or HH:MM:SS format)
     * Returns null for empty or invalid input, otherwise returns TIME string
     */
    private function sanitize_time_input( $input ): ?string {
        $input = trim( (string) $input );
        if ( empty( $input ) ) {
            return null;
        }

        // Validate HH:MM or HH:MM:SS format
        if ( ! preg_match( '/^([01]?[0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/', $input, $matches ) ) {
            return null;
        }

        // Normalize to HH:MM:SS format
        $hours   = str_pad( $matches[1], 2, '0', STR_PAD_LEFT );
        $minutes = $matches[2];
        $seconds = isset( $matches[3] ) ? $matches[3] : '00';

        return "{$hours}:{$minutes}:{$seconds}";
    }


    /* ========================= Punches & Session ========================= */

    /**
     * Output unified CSS styles for attendance pages
     */
    private function output_attendance_styles(): void {
        static $done = false;
        if ( $done ) { return; }
        $done = true;
        ?>
        <style>
            /* Toolbar Card */
            .sfs-hr-att-toolbar {
                background: #fff;
                border: 1px solid #e2e4e7;
                border-radius: 8px;
                padding: 16px;
                margin-bottom: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .sfs-hr-att-toolbar form {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                align-items: center;
                margin: 0;
            }
            .sfs-hr-att-toolbar label {
                font-weight: 500;
                font-size: 13px;
                color: #50575e;
                margin-right: 4px;
            }
            .sfs-hr-att-toolbar select,
            .sfs-hr-att-toolbar input[type="date"],
            .sfs-hr-att-toolbar input[type="month"],
            .sfs-hr-att-toolbar input[type="number"] {
                height: 36px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                padding: 0 10px;
                font-size: 13px;
            }
            .sfs-hr-att-toolbar .button {
                height: 36px;
                line-height: 34px;
                padding: 0 16px;
            }
            .sfs-hr-att-toolbar .description {
                font-size: 12px;
                color: #787c82;
            }

            /* Table Card */
            .sfs-hr-att-table-wrap {
                background: #fff;
                border: 1px solid #e2e4e7;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .sfs-hr-att-table-wrap .table-header {
                padding: 16px 20px;
                border-bottom: 1px solid #f0f0f1;
                background: #f9fafb;
            }
            .sfs-hr-att-table-wrap .table-header h3 {
                margin: 0;
                font-size: 14px;
                font-weight: 600;
                color: #1d2327;
            }
            .sfs-hr-att-table {
                width: 100%;
                border-collapse: collapse;
                margin: 0;
            }
            .sfs-hr-att-table th {
                background: #f9fafb;
                padding: 12px 14px;
                text-align: left;
                font-weight: 600;
                font-size: 12px;
                color: #50575e;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                border-bottom: 1px solid #e2e4e7;
            }
            .sfs-hr-att-table td {
                padding: 12px 14px;
                font-size: 13px;
                border-bottom: 1px solid #f0f0f1;
                vertical-align: middle;
            }
            .sfs-hr-att-table tbody tr:hover {
                background: #f9fafb;
            }
            .sfs-hr-att-table tbody tr:last-child td {
                border-bottom: none;
            }
            .sfs-hr-att-table .empty-state {
                text-align: center;
                padding: 40px 20px;
                color: #787c82;
            }
            .sfs-hr-att-table .empty-state p {
                margin: 0;
                font-size: 14px;
            }
            .sfs-hr-att-table .emp-name {
                display: block;
                font-weight: 500;
                color: #1d2327;
            }
            .sfs-hr-att-table .emp-code {
                display: block;
                font-size: 11px;
                color: #787c82;
                margin-top: 2px;
            }

            /* Status Pills */
            .sfs-hr-att-pill {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 500;
                line-height: 1.4;
            }
            .sfs-hr-att-pill--in { background: #e8f5e9; color: #2e7d32; }
            .sfs-hr-att-pill--out { background: #fff3e0; color: #e65100; }
            .sfs-hr-att-pill--break { background: #e3f2fd; color: #1565c0; }
            .sfs-hr-att-pill--ok { background: #e8f5e9; color: #2e7d32; }
            .sfs-hr-att-pill--issue { background: #ffebee; color: #c62828; }

            /* Mobile Responsive */
            @media screen and (max-width: 782px) {
                .sfs-hr-att-toolbar form {
                    flex-direction: column;
                    align-items: stretch;
                }
                .sfs-hr-att-toolbar select,
                .sfs-hr-att-toolbar input {
                    width: 100%;
                }
                .sfs-hr-att-toolbar .button {
                    width: 100%;
                    text-align: center;
                }
                .sfs-hr-att-table-wrap {
                    overflow-x: auto;
                }
                .sfs-hr-att-table {
                    min-width: 800px;
                }
                .sfs-hr-att-table th,
                .sfs-hr-att-table td {
                    padding: 10px 8px;
                    font-size: 12px;
                }
            }
        </style>
        <?php
    }

    private function render_punches(): void {
  if ( ! current_user_can('sfs_hr_attendance_view_team') ) { wp_die('Access denied'); }
  global $wpdb;

  $this->output_attendance_styles();

  $pT = $wpdb->prefix . 'sfs_hr_attendance_punches';
  $eT = $wpdb->prefix . 'sfs_hr_employees';
  $uT = $wpdb->users;

  // 1) Read filters FIRST
  $date  = (isset($_GET['date'])  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']))  ? $_GET['date']  : wp_date('Y-m-d');
  $mode  = (isset($_GET['mode'])  && $_GET['mode']==='month') ? 'month' : 'day';
  $month = (isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])) ? $_GET['month'] : wp_date('Y-m');
  $emp   = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

$debug = !empty($_GET['debug']);


  // 2) Employees for the dropdown (build BEFORE echoing the form)
  $empRows = $wpdb->get_results("
    SELECT e.id,
           COALESCE(
               NULLIF(TRIM(CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,''))), ''),
               NULLIF(TRIM(u.display_name), ''),
               CONCAT('#', e.id)
           ) AS name
    FROM {$eT} e
    LEFT JOIN {$uT} u ON u.ID = e.user_id
    WHERE e.status = 'active'
    ORDER BY name ASC
  ");

// 3) Build WHERE after we know mode/month/date — for **LOCAL-stored** punch_time
if ($mode === 'day') {
    // [local day start, next local day)
    $st = $date . ' 00:00:00';
    $en = (new \DateTimeImmutable($date . ' 00:00:00'))
            ->modify('+1 day')
            ->format('Y-m-d H:i:s');
} else {
    // [first day of local month, first day of next local month)
    // $month is YYYY-MM
    $st = $month . '-01 00:00:00';
    $en = (new \DateTimeImmutable($st))
            ->modify('first day of next month')
            ->format('Y-m-d H:i:s');
}

$where = $wpdb->prepare(
    "WHERE p.punch_time >= %s AND p.punch_time < %s",
    $st, $en
);

if ($emp > 0) {
    $where .= $wpdb->prepare(" AND p.employee_id=%d", $emp);
}

  // 4) Query rows (build SELECT safely based on existing columns)
$cols = $this->get_table_columns( $pT );
$has  = static function($c) use ($cols) { return in_array($c, $cols, true); };

$extra = [];
if ($has('selfie_media_id'))  $extra[] = 'p.selfie_media_id';
if ($has('selfie_url'))       $extra[] = 'p.selfie_url';
if ($has('selfie_path'))      $extra[] = 'p.selfie_path';
if ($has('selfie_meta_json')) $extra[] = 'p.selfie_meta_json';

$extraSQL = $extra ? (",\n         " . implode(",\n         ", $extra)) : '';

$sql = "
  SELECT p.id, p.employee_id,
         u.display_name,
         e.employee_code AS emp_code,
         p.punch_type, p.punch_time, p.source,
         p.geo_lat, p.geo_lng, p.geo_accuracy_m, p.valid_geo, p.valid_selfie
         {$extraSQL}
  FROM {$pT} p
  LEFT JOIN {$eT} e ON e.id = p.employee_id
  LEFT JOIN {$uT} u ON u.ID = e.user_id
  {$where}
  ORDER BY p.punch_time DESC
";

$rows = $wpdb->get_results( $sql );

$this->att_log('Punches query done', [
    'month_or_day' => $mode,
    'date' => $date,
    'month' => $month,
    'employee' => $emp,
    'row_count' => is_array($rows) ? count($rows) : 0,
    'punch_cols' => $this->get_table_columns($pT),
]);


  // 5) UI
  $totalPunches = is_array($rows) ? count($rows) : 0;
  echo '<div class="wrap"><h1>' . esc_html__('Punches', 'sfs-hr') . '</h1>';

  // Toolbar Card
  echo '<div class="sfs-hr-att-toolbar">';
  echo '<form method="get">';
  echo '<input type="hidden" name="page" value="sfs_hr_attendance"/><input type="hidden" name="tab" value="punches"/>';

  echo '<label>' . esc_html__('View:', 'sfs-hr') . '</label>';
  echo '<select name="mode" onchange="this.form.submit()">';
  echo '<option value="day"'.selected($mode,'day',false).'>' . esc_html__('Day', 'sfs-hr') . '</option>';
  echo '<option value="month"'.selected($mode,'month',false).'>' . esc_html__('Month', 'sfs-hr') . '</option>';
  echo '</select>';

  if ($mode==='day') {
    echo '<label>' . esc_html__('Date:', 'sfs-hr') . '</label>';
    echo '<input type="date" name="date" value="'.esc_attr($date).'"/>';
  } else {
    echo '<label>' . esc_html__('Month:', 'sfs-hr') . '</label>';
    echo '<input type="month" name="month" value="'.esc_attr($month).'"/>';
  }

  echo '<label>' . esc_html__('Employee:', 'sfs-hr') . '</label>';
  echo '<select name="employee_id"><option value="0">— ' . esc_html__('All', 'sfs-hr') . ' —</option>';
  foreach ($empRows as $r) {
    printf('<option value="%d"%s>%s</option>', (int)$r->id, selected($emp,(int)$r->id,false), esc_html($r->name));
  }
  echo '</select>';
  echo '<button class="button">' . esc_html__('Filter', 'sfs-hr') . '</button>';
  echo '</form></div>';

  // Table Card
  $showDebug = !empty($_GET['debug']);
  echo '<div class="sfs-hr-att-table-wrap">';
  echo '<div class="table-header"><h3>' . esc_html__('Punches', 'sfs-hr') . ' (' . esc_html($totalPunches) . ')</h3></div>';
  echo '<table class="sfs-hr-att-table"><thead><tr>
        <th>ID</th><th>' . esc_html__('Employee', 'sfs-hr') . '</th><th>' . esc_html__('Employee Code', 'sfs-hr') . '</th><th>' . esc_html__('Type', 'sfs-hr') . '</th><th>' . esc_html__('Time (local)', 'sfs-hr') . '</th>
        <th>' . esc_html__('Source', 'sfs-hr') . '</th><th>' . esc_html__('Geo', 'sfs-hr') . '</th><th>' . esc_html__('Selfie', 'sfs-hr') . '</th>';
if ($debug) { echo '<th>Selfie debug</th>'; }
echo '</tr></thead><tbody>';

  if ($rows) {
    foreach ($rows as $r) {
      $geo = ($r->geo_lat!==null && $r->geo_lng!==null)
        ? sprintf('%.6f, %.6f (%sm) %s', $r->geo_lat, $r->geo_lng, (int)$r->geo_accuracy_m, $r->valid_geo ? '✓' : '✗')
        : '-';
      $empCode = ($r->emp_code!==null && $r->emp_code!=='') ? $r->emp_code : ('#'.$r->employee_id);


// --- Selfie cell -------------------------------------------------
$selfieCell = $r->valid_selfie ? '✓' : '✗';

// helper: safe <img>
$imgTag = function(string $src): string {
    $style = 'width:60px;height:60px;object-fit:cover;border-radius:4px;display:block';
    return '<img src="'.esc_url($src).'" alt="" style="'.$style.'"/>';
};

$why = 'no_media_id';

$aid = (int)($r->selfie_media_id ?? 0);
if ( $aid > 0 ) {
    $full = wp_get_attachment_url($aid);
    if ( $full ) {
        // prefer native thumbnail if available
        $thumb = wp_get_attachment_image($aid, [60,60], false, [
            'style'=>'width:60px;height:60px;object-fit:cover;border-radius:4px;display:block'
        ]);
        $selfieCell = '<a href="'.esc_url($full).'" target="_blank" rel="noopener noreferrer">'.($thumb ?: $imgTag($full)).'</a>';
        $why = 'resolved:attachment_url';
    } else {
        // Attachment ID exists but URL didn’t resolve (deleted file, offload plugin, or bad ID)
        $edit_url = admin_url('post.php?post='.$aid.'&action=edit');
        $selfieCell = '<a href="'.esc_url($edit_url).'" target="_blank" rel="noopener noreferrer">#'.$aid.' (no URL)</a>';
        $why = 'attachment_id_but_no_url';
    }
} else {
    // keep ✓/✗ if no media id (valid_selfie may still be 1 from older records)
    $why = $r->valid_selfie ? 'valid=1_but_no_media_id' : 'no_media_id';
}


// 1) attachment ID
if ( isset($r->selfie_media_id) && !empty($r->selfie_media_id) ) {
  $attId = (int) $r->selfie_media_id;
  $full  = wp_get_attachment_url($attId);
  $thumb = wp_get_attachment_image($attId, [60,60], false, [
    'style'=>'width:60px;height:60px;object-fit:cover;border-radius:4px;display:block'
  ]);
  if ($full && $thumb) {
    $selfieCell = '<a href="'.esc_url($full).'" target="_blank" rel="noopener noreferrer">'.$thumb.'</a>';
  } elseif ($full) {
    $selfieCell = '<a href="'.esc_url($full).'" target="_blank" rel="noopener noreferrer">'.$imgTag($full).'</a>';
  }
}

// 2) absolute URL
if ( $selfieCell === ($r->valid_selfie ? '✓' : '✗') && isset($r->selfie_url) && !empty($r->selfie_url) && filter_var($r->selfie_url, FILTER_VALIDATE_URL) ) {
  $selfieCell = '<a href="'.esc_url($r->selfie_url).'" target="_blank" rel="noopener noreferrer">'.$imgTag($r->selfie_url).'</a>';
}

// 3) local path -> uploads URL
if ( $selfieCell === ($r->valid_selfie ? '✓' : '✗') && isset($r->selfie_path) && !empty($r->selfie_path) ) {
  $uploads = wp_get_upload_dir();
  $path    = (string) $r->selfie_path;

  // if the path already starts with uploads basedir, strip it
  if (strpos($path, $uploads['basedir']) === 0) {
    $rel = ltrim(substr($path, strlen($uploads['basedir'])), '/\\');
    $url = trailingslashit($uploads['baseurl']).$rel;
    $selfieCell = '<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">'.$imgTag($url).'</a>';
  } else {
    // try to join with baseurl (in case path is relative under uploads)
    $url = trailingslashit($uploads['baseurl']).ltrim($path, '/\\');
    $selfieCell = '<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">'.$imgTag($url).'</a>';
  }
}

// 4) meta JSON
if ( $selfieCell === ($r->valid_selfie ? '✓' : '✗') && isset($r->selfie_meta_json) && !empty($r->selfie_meta_json) ) {
  $m = json_decode((string)$r->selfie_meta_json, true);
  if (is_array($m)) {
    if (!empty($m['attachment_id'])) {
      $aid  = (int) $m['attachment_id'];
      $full = wp_get_attachment_url($aid);
      if ($full) {
        $selfieCell = '<a href="'.esc_url($full).'" target="_blank" rel="noopener noreferrer">'.$imgTag($full).'</a>';
      }
    } elseif (!empty($m['url']) && filter_var($m['url'], FILTER_VALIDATE_URL)) {
      $selfieCell = '<a href="'.esc_url($m['url']).'" target="_blank" rel="noopener noreferrer">'.$imgTag($m['url']).'</a>';
    } elseif (!empty($m['data_url']) && strpos($m['data_url'], 'data:image') === 0) {
      // inline data-uri (admin-only display)
      $selfieCell = $imgTag($m['data_url']);
    }
  }
}

$resolve_selfie = function($row) {
    // returns ['html' => string|null, 'why' => string]
    $why = [];

    // quick inline <img> builder
    $img = function($src) { 
        $style = 'width:60px;height:60px;object-fit:cover;border-radius:4px;display:block';
        return '<img src="'.esc_url($src).'" alt="" style="'.$style.'"/>';
    };

    // 1) attachment id
if (isset($row->selfie_media_id) && (int)$row->selfie_media_id > 0) {
    $aid  = (int)$row->selfie_media_id;
    $full = wp_get_attachment_url($aid);

    // extra logging: whether the attachment URL exists
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $this->att_log('Selfie media check', [
            'punch_id'        => (int)$row->id,
            'employee_id'     => (int)$row->employee_id,
            'selfie_media_id' => $aid,
            'valid_selfie'    => (int)$row->valid_selfie,
            'attachment_url'  => $full ?: null,
        ]);
    }

    if ($full) {
        return [
            'html'=> '<a href="'.esc_url($full).'" target="_blank" rel="noopener">'.
                        '<img src="'.esc_url($full).'" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:4px;display:block" />'.
                    '</a>',
            'why'=>'attachment_id_col'
        ];
    }

    // even if URL failed, show a fallback link to the attachment edit screen to help diagnose
    $edit_url = admin_url('post.php?post='.$aid.'&action=edit');
    return [
        'html'=> '<a href="'.esc_url($edit_url).'" target="_blank" rel="noopener">#'.$aid.' (no URL)</a>',
        'why'=>'attachment_id_col_but_no_url'
    ];
}

    // 2) absolute URL column
    if (isset($row->selfie_url) && is_string($row->selfie_url) && filter_var($row->selfie_url, FILTER_VALIDATE_URL)) {
        return ['html'=> '<a href="'.esc_url($row->selfie_url).'" target="_blank" rel="noopener">'.$img($row->selfie_url).'</a>', 'why'=>'abs_url_col'];
    } else { $why[] = 'no_abs_url_col'; }

    // 3) local path column → uploads URL
    if (isset($row->selfie_path) && is_string($row->selfie_path) && $row->selfie_path !== '') {
        $uploads = wp_get_upload_dir();
        $p = $row->selfie_path;

        // Normalize
        if (strpos($p, $uploads['basedir']) === 0) {
            $rel = ltrim(substr($p, strlen($uploads['basedir'])), '/\\');
            $url = trailingslashit($uploads['baseurl']).$rel;
            return ['html'=>'<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.$img($url).'</a>', 'why'=>'path_basedir'];
        } else {
            // maybe a relative path under uploads
            $url = trailingslashit($uploads['baseurl']).ltrim($p, '/\\');
            return ['html'=>'<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.$img($url).'</a>', 'why'=>'path_rel_uploads'];
        }
    } else { $why[] = 'no_path_col'; }

    // 4) meta json (try several common keys)
    if (isset($row->selfie_meta_json) && is_string($row->selfie_meta_json) && $row->selfie_meta_json !== '') {
        $m = json_decode($row->selfie_meta_json, true);
        if (is_array($m)) {
            // a) attachment_id
            if (!empty($m['attachment_id'])) {
                $aid  = (int)$m['attachment_id'];
                $full = wp_get_attachment_url($aid);
                if ($full) return ['html'=>'<a href="'.esc_url($full).'" target="_blank" rel="noopener">'.$img($full).'</a>', 'why'=>'meta.attachment_id'];
                $why[] = 'meta_aid_but_no_url';
            }
            // b) direct url / s3_url
            foreach (['url','s3_url'] as $k) {
                if (!empty($m[$k]) && filter_var($m[$k], FILTER_VALIDATE_URL)) {
                    return ['html'=>'<a href="'.esc_url($m[$k]).'" target="_blank" rel="noopener">'.$img($m[$k]).'</a>', 'why'=>'meta.'.$k];
                }
            }
            // c) data_url (base64)
            if (!empty($m['data_url']) && is_string($m['data_url']) && strpos($m['data_url'], 'data:image') === 0) {
                return ['html'=>$img($m['data_url']), 'why'=>'meta.data_url'];
            }
            // d) file/path under uploads
            foreach (['file','path','upload_rel','uploads_rel'] as $k) {
                if (!empty($m[$k]) && is_string($m[$k])) {
                    $uploads = wp_get_upload_dir();
                    $p = $m[$k];
                    if (strpos($p, $uploads['basedir']) === 0) {
                        $rel = ltrim(substr($p, strlen($uploads['basedir'])), '/\\');
                        $url = trailingslashit($uploads['baseurl']).$rel;
                        return ['html'=>'<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.$img($url).'</a>', 'why'=>'meta.path_like:'.$k];
                    } else {
                        $url = trailingslashit($uploads['baseurl']).ltrim($p, '/\\');
                        return ['html'=>'<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.$img($url).'</a>', 'why'=>'meta.path_rel:'.$k];
                    }
                }
            }
            // e) s3_key (non-public) → nothing to render
            if (!empty($m['s3_key'])) { $why[] = 'meta_has_s3_key_only'; }
        } else {
            $why[] = 'meta_not_array';
        }
    } else { $why[] = 'no_meta_json'; }

    return ['html'=>null, 'why'=> implode(',', $why) ];
};

// Human label for punch_type + local time
$type_map = [
    'in'          => 'IN',
    'out'         => 'OUT',
    'break_start' => 'BREAK START',
    'break_end'   => 'BREAK END',
];
$type_label = $type_map[$r->punch_type] ?? strtoupper((string)$r->punch_type);
$time_local = \SFS\HR\Modules\Attendance\AttendanceModule::fmt_local($r->punch_time);

// Final row
echo '<tr>';
printf('<td>%d</td>', (int)$r->id);
$profile_url_punch = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . (int) $r->employee_id);
$emp_label_punch = $r->display_name ?: ('#'.$r->employee_id);
echo '<td><a href="'.esc_url($profile_url_punch).'" style="color:#2271b1;text-decoration:none;font-weight:500;">'.esc_html($emp_label_punch).'</a></td>';
echo '<td>'.esc_html(($r->emp_code!==null && $r->emp_code!=='') ? $r->emp_code : ('#'.$r->employee_id)).'</td>';
echo '<td>'.esc_html($type_label).'</td>';          // was punch_time
echo '<td>'.esc_html($time_local).'</td>';          // was UTC; now local
echo '<td>'.esc_html($r->source).'</td>';
$geo = ($r->geo_lat!==null && $r->geo_lng!==null)
    ? sprintf('%.6f, %.6f (%sm) %s', $r->geo_lat, $r->geo_lng, (int)$r->geo_accuracy_m, $r->valid_geo ? '✓' : '✗')
    : '-';
echo '<td>'.esc_html($geo).'</td>';
echo '<td style="width:72px">'.$selfieCell.'</td>';
if ($debug) {
    // compact JSON-ish debug
    $dbg = [
        'aid'           => (int)($r->selfie_media_id ?? 0),
        'valid_selfie'  => (int)$r->valid_selfie,
        'why'           => $why,
    ];
    echo '<td><code>'.esc_html(wp_json_encode($dbg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'</code></td>';
}
echo '</tr>';


    }
  } else {
    echo '<tr><td colspan="8" class="empty-state"><p>' . esc_html__('No punches found for this selection.', 'sfs-hr') . '</p></td></tr>';
  }
  echo '</tbody></table></div></div>';
}


private function render_sessions(): void {
    if ( ! current_user_can('sfs_hr_attendance_view_team') ) { wp_die('Access denied'); }
    global $wpdb;

    $this->output_attendance_styles();

    $sT = $wpdb->prefix . 'sfs_hr_attendance_sessions';
    $eT = $wpdb->prefix . 'sfs_hr_employees';
    $uT = $wpdb->users;

    // Inputs
    $date   = ( isset($_GET['date'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date']) ) ? (string) $_GET['date'] : wp_date('Y-m-d');
    $emp    = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;

    // New: month/year period (25th → 25th)
    $year   = ( isset($_GET['year']) && preg_match('/^\d{4}$/', (string) $_GET['year']) ) ? (int) $_GET['year'] : (int) wp_date('Y');
    $month  = ( isset($_GET['month']) && preg_match('/^(0?[1-9]|1[0-2])$/', (string) $_GET['month']) ) ? (int) $_GET['month'] : (int) wp_date('n');

    // New: view mode (day or period_25)
    $mode = ( isset($_GET['mode']) && $_GET['mode'] === 'period_25' ) ? 'period_25' : 'day';

    // Compute WHERE
    $where = '';
    $params = [];

    if ( $mode === 'day' ) {
        $where = $wpdb->prepare("WHERE s.work_date=%s", $date);
    } else {
    // period_25: 25th of previous month → 24th of selected month
    $prevY = $year - ($month === 1 ? 1 : 0);
    $prevM = ($month === 1 ? 12 : $month - 1);

    $start = sprintf('%04d-%02d-25', $prevY, $prevM);
    $end   = sprintf('%04d-%02d-24', $year, $month);

    $where = $wpdb->prepare("WHERE s.work_date BETWEEN %s AND %s", $start, $end);
}

    if ( $emp > 0 ) {
        $where .= $wpdb->prepare(" AND s.employee_id=%d", $emp);
    }

    // Sessions rows (+ employee_code column) — stable sort
$sortName = "COALESCE(NULLIF(TRIM(u.display_name),''), NULLIF(TRIM(e.employee_code),''), CONCAT('#', s.employee_id))";
$orderSQL = ($mode === 'period_25')
    ? "{$sortName} ASC, s.work_date ASC"
    : "{$sortName} ASC";

$rows = $wpdb->get_results("
    SELECT s.*,
           u.display_name,
           e.employee_code
    FROM {$sT} s
    LEFT JOIN {$eT} e ON e.id = s.employee_id
    LEFT JOIN {$uT} u ON u.ID = e.user_id
    {$where}
    ORDER BY {$orderSQL}
");



    // Build employee options (smart name detection)
    $empT = $wpdb->prefix . 'sfs_hr_employees';
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$empT}", 0) ?: [];
    $has  = static function($c) use ($cols) { return in_array($c, $cols, true); };

    $nameParts = [];
    if ( $has('first_name') && $has('last_name') ) {
        $nameParts[] = "NULLIF(TRIM(CONCAT(e.first_name,' ',e.last_name)),'')";
    }
    if ( $has('full_name') ) {
        $nameParts[] = "NULLIF(TRIM(e.full_name),'')";
    }
    if ( $has('name') ) {
        $nameParts[] = "NULLIF(TRIM(e.name),'')";
    }
    $nameParts[] = "NULLIF(TRIM(u.display_name),'')";
    $nameParts[] = "NULLIF(TRIM(u.user_login),'')";
    $nameSQL = 'COALESCE(' . implode(',', $nameParts) . ", CONCAT('#', e.id)) AS name";

    $emps = $wpdb->get_results("
        SELECT e.id, {$nameSQL}
        FROM {$empT} e
        LEFT JOIN {$uT} u ON u.ID = e.user_id
        ORDER BY name ASC
    ");

    // Export URL (reuse existing export endpoint; pass 25th-range when in period mode, or single day)
    // in render_sessions(), Export URL (period or day)
if ( $mode === 'day' ) {
    $from = $date; 
    $to   = $date;
} else {
    // prev-25 → selected-24  (match the WHERE you already use)
    $prevY = $year - ($month === 1 ? 1 : 0);
    $prevM = ($month === 1 ? 12 : $month - 1);
    $from  = sprintf('%04d-%02d-25', $prevY, $prevM);
    $to    = sprintf('%04d-%02d-24',  $year,  $month);
}

$export_url = esc_url( wp_nonce_url(
    add_query_arg([
        'action' => 'sfs_hr_att_export_csv',
        'from'   => $from,
        'to'     => $to,
        'recalc' => 1, // keep the on-demand rebuild
    ], admin_url('admin-post.php')),
    'sfs_hr_att_export_csv'
) );



    $totalSessions = is_array($rows) ? count($rows) : 0;
    echo '<div class="wrap"><h1>' . esc_html__('Sessions', 'sfs-hr') . '</h1>';

    // Toolbar Card
    echo '<div class="sfs-hr-att-toolbar">';
    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="sfs_hr_attendance"/><input type="hidden" name="tab" value="sessions"/>';

    echo '<label>' . esc_html__('View:', 'sfs-hr') . '</label>';
    echo '<select name="mode" onchange="this.form.submit()">';
    echo '<option value="day"'.selected($mode,'day',false).'>' . esc_html__('Day', 'sfs-hr') . '</option>';
    echo '<option value="period_25"'.selected($mode,'period_25',false).'>' . esc_html__('Period (25→25)', 'sfs-hr') . '</option>';
    echo '</select>';

    if ( $mode === 'day' ) {
        echo '<label>' . esc_html__('Date:', 'sfs-hr') . '</label>';
        echo '<input type="date" id="sfs-sessions-date" name="date" value="' . esc_attr($date) . '"/>';
    } else {
    // month/year inputs for Period (25→25): prev-month 25 → selected-month 24
    $prevY = $year - ($month === 1 ? 1 : 0);
    $prevM = ($month === 1 ? 12 : $month - 1);

    $from  = sprintf('%04d-%02d-25', $prevY, $prevM);
    $to    = sprintf('%04d-%02d-24', $year, $month);

    echo '<label>' . esc_html__('Month:', 'sfs-hr') . '</label>';
    echo '<select name="month">';
    for ($m = 1; $m <= 12; $m++) {
        printf('<option value="%d"%s>%02d</option>', $m, selected($month, $m, false), $m);
    }
    echo '</select>';

    echo '<label>' . esc_html__('Year:', 'sfs-hr') . '</label>';
    echo '<input type="number" name="year" min="2000" step="1" value="'.esc_attr($year).'" style="width:90px"/>';
    echo '<span class="description">' . esc_html__('Range:', 'sfs-hr') . ' '.$from.' → '.$to.'</span>';
}

    echo '<label>' . esc_html__('Employee:', 'sfs-hr') . '</label>';
    echo '<select name="employee_id"><option value="0">— ' . esc_html__('All', 'sfs-hr') . ' —</option>';
    foreach ( (array) $emps as $r ) {
        printf('<option value="%d"%s>%s</option>', (int) $r->id, selected($emp, (int) $r->id, false), esc_html($r->name));
    }
    echo '</select>';

    echo '<button class="button">' . esc_html__('Filter', 'sfs-hr') . '</button>';
    echo '<a class="button" href="'.$export_url.'">' . esc_html__('Export CSV', 'sfs-hr') . '</a>';

// Rebuild button — Day view ONLY
if ( $mode === 'day' ) {
    $rebuild_url = esc_url( wp_nonce_url(
        add_query_arg(['action' => 'sfs_hr_att_rebuild_sessions_day', 'date' => $date], admin_url('admin-post.php')),
        'sfs_hr_att_rebuild_sessions_day'
    ) );
    echo '<a class="button button-primary" id="sfs-rebuild-link" href="'.$rebuild_url.'">' . esc_html__('Rebuild Sessions for', 'sfs-hr') . ' '.esc_html($date).'</a>';

    // Keep the link in sync when date changes
    echo "<script>
    (function(){
      var d = document.getElementById('sfs-sessions-date');
      var a = document.getElementById('sfs-rebuild-link');
      if (!d || !a) return;
      d.addEventListener('change', function(){
        try {
          var url = new URL(a.href, window.location.origin);
          url.searchParams.set('date', this.value || '".esc_js($date)."');
          a.href = url.toString();
          a.textContent = '".esc_js(__('Rebuild Sessions for', 'sfs-hr'))." ' + (this.value || '".esc_js($date)."');
        } catch(e) {}
      });
    })();
    </script>";
}
    echo '</form></div>';

    // Table Card
    echo '<div class="sfs-hr-att-table-wrap">';
    echo '<div class="table-header"><h3>' . esc_html__('Sessions', 'sfs-hr') . ' (' . esc_html($totalSessions) . ')</h3></div>';
    echo '<table class="sfs-hr-att-table"><thead><tr>
  <th>' . esc_html__('Employee', 'sfs-hr') . '</th><th>' . esc_html__('Employee Code', 'sfs-hr') . '</th>
  <th>' . esc_html__('In', 'sfs-hr') . '</th><th>' . esc_html__('Out', 'sfs-hr') . '</th><th>' . esc_html__('Break', 'sfs-hr') . '</th>
  <th>' . esc_html__('Worked (net)', 'sfs-hr') . '</th><th>' . esc_html__('OT', 'sfs-hr') . '</th>
  <th>' . esc_html__('Status', 'sfs-hr') . '</th><th>' . esc_html__('Geo', 'sfs-hr') . '</th><th>' . esc_html__('Selfie', 'sfs-hr') . '</th><th>' . esc_html__('Flags', 'sfs-hr') . '</th>
</tr></thead><tbody>';

if ( $rows ) {
    foreach ( $rows as $r ) {
        $geoTxt    = (isset($r->outside_geo_count) && (int)$r->outside_geo_count > 0) ? (int)$r->outside_geo_count : '✓';
        $selfieTxt = (isset($r->no_selfie_count) && (int)$r->no_selfie_count > 0) ? (int)$r->no_selfie_count : '✓';

        $flagsTxt = '—';
        if ( ! empty($r->flags_json) ) {
            $fj = json_decode((string) $r->flags_json, true);
            if ( is_array($fj) && $fj ) {
                $flagsTxt = implode(', ', array_map('sanitize_text_field', $fj));
            }
        }

        $in_local  = $r->in_time  ? \SFS\HR\Modules\Attendance\AttendanceModule::fmt_local($r->in_time)  : '-';
        $out_local = $r->out_time ? \SFS\HR\Modules\Attendance\AttendanceModule::fmt_local($r->out_time) : '-';

        $profile_url_sess = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . (int) $r->employee_id);
        $emp_name_sess = esc_html( $r->display_name ?: '' );
        $emp_link_sess = $emp_name_sess ? '<a href="'.esc_url($profile_url_sess).'" style="color:#2271b1;text-decoration:none;font-weight:500;">'.$emp_name_sess.'</a>' : '';

        printf(
            '<tr>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%dm</td>
                <td>%dm</td>
                <td>%dm</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
            </tr>',
            $emp_link_sess,
            esc_html( ($r->employee_code !== null && $r->employee_code !== '') ? (string)$r->employee_code : '' ),
            esc_html( $in_local ),
            esc_html( $out_local ),
            (int) $r->break_minutes,
            (int) $r->net_minutes,
            (int) $r->overtime_minutes,
            esc_html( $r->status ? $this->get_status_label( $r->status ) : '-' ),
            esc_html( (string) $geoTxt ),
            esc_html( (string) $selfieTxt ),
            esc_html( $flagsTxt )
        );
    }
} else {
    echo '<tr><td colspan="11" class="empty-state"><p>' . esc_html__('No sessions found for this selection.', 'sfs-hr') . '</p></td></tr>';
}

echo '</tbody></table></div></div>';

}



/** Mini logger (only logs when WP_DEBUG is true) */
private function att_log(string $msg, array $ctx = []): void {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[SFS-HR/Attendance] ' . $msg . ($ctx ? ' | ' . wp_json_encode($ctx) : ''));
    }
}

/**
 * Get human-readable status label for attendance session status
 */
private function get_status_label( string $status ): string {
    $labels = [
        'present'    => __( 'Present', 'sfs-hr' ),
        'late'       => __( 'Late', 'sfs-hr' ),
        'left_early' => __( 'Left Early', 'sfs-hr' ),
        'absent'     => __( 'Absent', 'sfs-hr' ),
        'incomplete' => __( 'Incomplete', 'sfs-hr' ),
        'on_leave'   => __( 'On Leave', 'sfs-hr' ),
        'holiday'    => __( 'Holiday', 'sfs-hr' ),
        'day_off'    => __( 'No Shift Assigned', 'sfs-hr' ),
    ];
    return $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
}


public function handle_rebuild_sessions_day(): void {
    if ( ! current_user_can('sfs_hr_attendance_admin') ) { wp_die('Access denied'); }

    if ( ! isset($_GET['_wpnonce']) || ! wp_verify_nonce($_GET['_wpnonce'], 'sfs_hr_att_rebuild_sessions_day') ) {
        wp_die('Invalid request');
    }

    check_admin_referer('sfs_hr_att_rebuild_sessions_day');

    global $wpdb;
    $pT      = $wpdb->prefix . 'sfs_hr_attendance_punches';
    $sT      = $wpdb->prefix . 'sfs_hr_attendance_sessions';
    $assignT = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';
    $shiftT  = $wpdb->prefix . 'sfs_hr_attendance_shifts';

    $date = ( isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date']) )
        ? (string) $_GET['date']
        : wp_date('Y-m-d');

    // كل موظف له punches في هذا اليوم
    $emps = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT employee_id FROM {$pT} WHERE DATE(punch_time)=%s",
        $date
    ) );

    foreach ( (array) $emps as $eid ) {
        $eid = (int) $eid;

        // أول IN وآخر OUT
        $in  = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(punch_time) FROM {$pT}
             WHERE employee_id=%d AND DATE(punch_time)=%s AND punch_type='in'",
            $eid, $date
        ) );
        $out = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(punch_time) FROM {$pT}
             WHERE employee_id=%d AND DATE(punch_time)=%s AND punch_type='out'",
            $eid, $date
        ) );

        // Geo / Selfie counters
        $geo_bad = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$pT}
             WHERE employee_id=%d AND DATE(punch_time)=%s
               AND valid_geo = 0
               AND geo_lat IS NOT NULL AND geo_lng IS NOT NULL",
            $eid, $date
        ) );

        $selfie_bad = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$pT}
             WHERE employee_id=%d AND DATE(punch_time)=%s
               AND valid_selfie = 0",
            $eid, $date
        ) );

        // إجمالي التواجد IN → OUT
        $worked = 0;
        if ( $in && $out && $out > $in ) {
            $worked = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT TIMESTAMPDIFF(MINUTE,%s,%s)", $in, $out)
            );
        }

        // حساب الاستراحة الفعلية من BREAK START / BREAK END
        $break_minutes    = 0;
        $break_incomplete = false;

        $breakRows = $wpdb->get_results( $wpdb->prepare(
            "SELECT punch_type, punch_time
             FROM {$pT}
             WHERE employee_id=%d AND DATE(punch_time)=%s
               AND punch_type IN ('break_start','break_end')
             ORDER BY punch_time ASC",
            $eid, $date
        ) );

        $open = null;
        foreach ( (array) $breakRows as $r ) {
            if ( $r->punch_type === 'break_start' ) {
                $open = $r->punch_time;
            } elseif ( $r->punch_type === 'break_end' ) {
                if ( $open && $r->punch_time > $open ) {
                    $diff = (int) $wpdb->get_var(
                        $wpdb->prepare("SELECT TIMESTAMPDIFF(MINUTE,%s,%s)", $open, $r->punch_time)
                    );
                    if ( $diff > 0 ) {
                        $break_minutes += $diff;
                    }
                }
                $open = null;
            }
        }

        // لو بدأ BREAK وما عمل END → نمده لوقت OUT
        if ( $open && $out && $out > $open ) {
            $diff = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT TIMESTAMPDIFF(MINUTE,%s,%s)", $open, $out)
            );
            if ( $diff > 0 ) {
                $break_minutes += $diff;
            }
            $break_incomplete = true;
        }

        // Allowed break من الشفت (unpaid_break_minutes) عندما يكون break_policy = 'punch'
        $allowed_break = 0;
        $extra_break   = 0;

        $shift = $wpdb->get_row( $wpdb->prepare(
            "SELECT sh.break_policy, sh.unpaid_break_minutes
             FROM {$assignT} a
             JOIN {$shiftT}  sh ON sh.id = a.shift_id
             WHERE a.employee_id=%d AND a.work_date=%s
             LIMIT 1",
            $eid, $date
        ) );

        if ( $shift && $shift->break_policy === 'punch' ) {
            $allowed_break = (int) ( $shift->unpaid_break_minutes ?? 0 );
        }

        if ( $allowed_break > 0 && $break_minutes > $allowed_break ) {
            $extra_break = $break_minutes - $allowed_break; // هذا هو الـ Delay
        }

        // صافي وقت العمل الفعلي
        $net = max( 0, $worked - $break_minutes );

        // Flags
        $flags = [];
        if ( $in && ! $out ) {
            $flags[] = 'incomplete';
        }
        if ( $break_incomplete ) {
            $flags[] = 'break_incomplete';
        }
        if ( $extra_break > 0 ) {
            $flags[] = 'over_break:' . $extra_break . 'm';
        }

        // upsert
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$sT} WHERE employee_id=%d AND work_date=%s",
            $eid, $date
        ) );

        $data = [
            'employee_id'         => $eid,
            'work_date'           => $date,
            'in_time'             => $in,
            'out_time'            => $out,
            'break_minutes'       => $break_minutes,
            'net_minutes'         => $net,
            'rounded_net_minutes' => $net, // ممكن نضيف rounding لاحقاً
            'overtime_minutes'    => 0,
            'status'              => ( $in && $out ) ? 'present' : 'incomplete',
            'flags_json'          => $flags ? wp_json_encode( $flags ) : null,
            'calc_meta_json'      => null,
            'last_recalc_at'      => current_time( 'mysql', true ),
        ];

        if ( $existing ) {
            $wpdb->update( $sT, $data, [ 'id' => $existing ] );
        } else {
            $wpdb->insert( $sT, $data );
        }
    }

    wp_safe_redirect( admin_url( 'admin.php?page=sfs_hr_attendance&tab=sessions&date=' . $date . '&rebuilt=1' ) );
    exit;
}

private function rebuild_sessions_for_date(string $date): void {
    global $wpdb;
    $pT      = $wpdb->prefix . 'sfs_hr_attendance_punches';
    $sT      = $wpdb->prefix . 'sfs_hr_attendance_sessions';
    $assignT = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';
    $shiftT  = $wpdb->prefix . 'sfs_hr_attendance_shifts';

    $emps = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT employee_id FROM {$pT} WHERE DATE(punch_time)=%s", $date
    ) );

    foreach ( (array) $emps as $eid ) {
        $eid = (int)$eid;

        $in  = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(punch_time) FROM {$pT}
             WHERE employee_id=%d AND DATE(punch_time)=%s AND punch_type='in'", $eid, $date
        ) );
        $out = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(punch_time) FROM {$pT}
             WHERE employee_id=%d AND DATE(punch_time)=%s AND punch_type='out'", $eid, $date
        ) );

        $geo_bad = (int)$wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$pT}
             WHERE employee_id=%d AND DATE(punch_time)=%s
               AND valid_geo=0
               AND geo_lat IS NOT NULL AND geo_lng IS NOT NULL", $eid, $date
        ) );
        $selfie_bad = (int)$wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$pT}
             WHERE employee_id=%d AND DATE(punch_time)=%s AND valid_selfie=0", $eid, $date
        ) );

        $worked = 0;
        if ($in && $out && $out > $in) {
            $worked = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT TIMESTAMPDIFF(MINUTE,%s,%s)", $in, $out
            ));
        }

        $break_minutes = 0; $break_incomplete = false; $open = null;
        $breakRows = $wpdb->get_results( $wpdb->prepare(
            "SELECT punch_type, punch_time
             FROM {$pT}
             WHERE employee_id=%d AND DATE(punch_time)=%s
               AND punch_type IN ('break_start','break_end')
             ORDER BY punch_time ASC", $eid, $date
        ) );
        foreach ((array)$breakRows as $r) {
            if ($r->punch_type==='break_start') { $open = $r->punch_time; }
            elseif ($r->punch_type==='break_end' && $open && $r->punch_time>$open) {
                $break_minutes += (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT TIMESTAMPDIFF(MINUTE,%s,%s)", $open, $r->punch_time
                ));
                $open = null;
            }
        }
        if ($open && $out && $out>$open) {
            $break_minutes += (int)$wpdb->get_var($wpdb->prepare(
                "SELECT TIMESTAMPDIFF(MINUTE,%s,%s)", $open, $out
            ));
            $break_incomplete = true;
        }

        $allowed_break = 0; $extra_break = 0;
        $shift = $wpdb->get_row( $wpdb->prepare(
            "SELECT sh.break_policy, sh.unpaid_break_minutes
             FROM {$assignT} a
             JOIN {$shiftT}  sh ON sh.id=a.shift_id
             WHERE a.employee_id=%d AND a.work_date=%s LIMIT 1", $eid, $date
        ) );
        if ($shift && $shift->break_policy==='punch') {
            $allowed_break = (int)$shift->unpaid_break_minutes;
        }
        if ($allowed_break>0 && $break_minutes>$allowed_break) {
            $extra_break = $break_minutes - $allowed_break;
        }

        $net = max(0, $worked - $break_minutes);
        $flags = [];
        if ($in && !$out) $flags[]='incomplete';
        if ($break_incomplete) $flags[]='break_incomplete';
        if ($extra_break>0) $flags[]='over_break:'.$extra_break.'m';

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$sT} WHERE employee_id=%d AND work_date=%s", $eid, $date
        ) );

        $data = [
            'employee_id'         => $eid,
            'work_date'           => $date,
            'in_time'             => $in,
            'out_time'            => $out,
            'break_minutes'       => $break_minutes,
            'net_minutes'         => $net,
            'rounded_net_minutes' => $net,
            'overtime_minutes'    => 0,
            'status'              => ($in && $out) ? 'present' : 'incomplete',
            'flags_json'          => $flags ? wp_json_encode($flags) : null,
            'calc_meta_json'      => null,
            'last_recalc_at'      => current_time('mysql', true),
        ];
        if ($existing) { $wpdb->update($sT, $data, ['id'=>$existing]); }
        else           { $wpdb->insert($sT, $data); }
    }
}

/**
 * Render Early Leave Requests tab - Admin view for managing early leave requests
 */
private function render_early_leave(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'sfs_hr_early_leave_requests';
    $emp_table = $wpdb->prefix . 'sfs_hr_employees';

    // Get filter parameters
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending';
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';

    // Build query
    $where = "WHERE 1=1";
    $params = [];

    if ($status_filter && $status_filter !== 'all') {
        $where .= " AND r.status = %s";
        $params[] = $status_filter;
    }

    if ($date_from) {
        $where .= " AND r.request_date >= %s";
        $params[] = $date_from;
    }

    if ($date_to) {
        $where .= " AND r.request_date <= %s";
        $params[] = $date_to;
    }

    // Count by status for tabs
    $counts = $wpdb->get_results("
        SELECT status, COUNT(*) as cnt
        FROM {$table}
        GROUP BY status
    ", OBJECT_K);

    $pending_count = isset($counts['pending']) ? $counts['pending']->cnt : 0;
    $approved_count = isset($counts['approved']) ? $counts['approved']->cnt : 0;
    $rejected_count = isset($counts['rejected']) ? $counts['rejected']->cnt : 0;
    $cancelled_count = isset($counts['cancelled']) ? $counts['cancelled']->cnt : 0;
    $total_count = $pending_count + $approved_count + $rejected_count + $cancelled_count;

    // Get requests with employee names
    $sql = "
        SELECT r.*, e.full_name as employee_name, e.emp_number,
               reviewer.display_name as reviewer_name
        FROM {$table} r
        LEFT JOIN {$emp_table} e ON r.employee_id = e.id
        LEFT JOIN {$wpdb->users} reviewer ON r.reviewed_by = reviewer.ID
        {$where}
        ORDER BY
            CASE r.status
                WHEN 'pending' THEN 1
                WHEN 'approved' THEN 2
                WHEN 'rejected' THEN 3
                WHEN 'cancelled' THEN 4
            END,
            r.created_at DESC
        LIMIT 100
    ";

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }

    $requests = $wpdb->get_results($sql);

    $reason_labels = [
        'sick' => __('Sickness', 'sfs-hr'),
        'external_task' => __('External Task', 'sfs-hr'),
        'personal' => __('Personal', 'sfs-hr'),
        'emergency' => __('Emergency', 'sfs-hr'),
        'other' => __('Other', 'sfs-hr'),
    ];

    $status_labels = [
        'pending' => __('Pending', 'sfs-hr'),
        'approved' => __('Approved', 'sfs-hr'),
        'rejected' => __('Rejected', 'sfs-hr'),
        'cancelled' => __('Cancelled', 'sfs-hr'),
    ];

    $base_url = admin_url('admin.php?page=sfs-hr-attendance&tab=early_leave');
    ?>
    <div class="wrap">
        <h2><?php esc_html_e('Early Leave Requests', 'sfs-hr'); ?></h2>
        <p class="description"><?php esc_html_e('Manage employee early leave requests. Approved requests do not affect salary calculations.', 'sfs-hr'); ?></p>

        <!-- Status Filter Tabs -->
        <ul class="subsubsub">
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', 'all', $base_url)); ?>"
                   class="<?php echo $status_filter === 'all' ? 'current' : ''; ?>">
                    <?php esc_html_e('All', 'sfs-hr'); ?> <span class="count">(<?php echo intval($total_count); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', 'pending', $base_url)); ?>"
                   class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>">
                    <?php esc_html_e('Pending', 'sfs-hr'); ?> <span class="count">(<?php echo intval($pending_count); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', 'approved', $base_url)); ?>"
                   class="<?php echo $status_filter === 'approved' ? 'current' : ''; ?>">
                    <?php esc_html_e('Approved', 'sfs-hr'); ?> <span class="count">(<?php echo intval($approved_count); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', 'rejected', $base_url)); ?>"
                   class="<?php echo $status_filter === 'rejected' ? 'current' : ''; ?>">
                    <?php esc_html_e('Rejected', 'sfs-hr'); ?> <span class="count">(<?php echo intval($rejected_count); ?>)</span>
                </a> |
            </li>
            <li>
                <a href="<?php echo esc_url(add_query_arg('status', 'cancelled', $base_url)); ?>"
                   class="<?php echo $status_filter === 'cancelled' ? 'current' : ''; ?>">
                    <?php esc_html_e('Cancelled', 'sfs-hr'); ?> <span class="count">(<?php echo intval($cancelled_count); ?>)</span>
                </a>
            </li>
        </ul>

        <br class="clear" />

        <!-- Date Range Filter -->
        <form method="get" class="early-leave-filters" style="margin: 15px 0; padding: 10px; background: #f9f9f9; border: 1px solid #e5e5e5;">
            <input type="hidden" name="page" value="sfs-hr-attendance" />
            <input type="hidden" name="tab" value="early_leave" />
            <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>" />

            <label>
                <?php esc_html_e('From:', 'sfs-hr'); ?>
                <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" />
            </label>

            <label style="margin-left: 10px;">
                <?php esc_html_e('To:', 'sfs-hr'); ?>
                <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" />
            </label>

            <button type="submit" class="button" style="margin-left: 10px;"><?php esc_html_e('Filter', 'sfs-hr'); ?></button>
            <a href="<?php echo esc_url($base_url); ?>" class="button" style="margin-left: 5px;"><?php esc_html_e('Reset', 'sfs-hr'); ?></a>
        </form>

        <?php if (empty($requests)): ?>
            <div class="notice notice-info">
                <p><?php esc_html_e('No early leave requests found.', 'sfs-hr'); ?></p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 60px;"><?php esc_html_e('ID', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Date', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Scheduled End', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Requested Leave', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Reason', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Reviewed By', 'sfs-hr'); ?></th>
                        <th style="width: 150px;"><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): ?>
                        <tr data-request-id="<?php echo intval($req->id); ?>">
                            <td><?php echo intval($req->id); ?></td>
                            <td>
                                <?php
                                $profile_url_el = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . (int) $req->employee_id);
                                $emp_name_el = $req->employee_name ?: __('Unknown', 'sfs-hr');
                                ?>
                                <a href="<?php echo esc_url($profile_url_el); ?>" style="color:#2271b1;text-decoration:none;">
                                    <strong><?php echo esc_html($emp_name_el); ?></strong>
                                </a>
                                <?php if ($req->emp_number): ?>
                                    <br><small class="description"><?php echo esc_html($req->emp_number); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($req->request_date))); ?></td>
                            <td><?php echo $req->scheduled_end_time ? esc_html(date_i18n('H:i', strtotime($req->scheduled_end_time))) : '—'; ?></td>
                            <td>
                                <strong><?php echo esc_html(date_i18n('H:i', strtotime($req->requested_leave_time))); ?></strong>
                                <?php if ($req->actual_leave_time): ?>
                                    <br><small><?php esc_html_e('Actual:', 'sfs-hr'); ?> <?php echo esc_html(date_i18n('H:i', strtotime($req->actual_leave_time))); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="reason-type reason-<?php echo esc_attr($req->reason_type); ?>">
                                    <?php echo esc_html($reason_labels[$req->reason_type] ?? $req->reason_type); ?>
                                </span>
                                <?php if ($req->reason_note): ?>
                                    <br><small class="description" title="<?php echo esc_attr($req->reason_note); ?>">
                                        <?php echo esc_html(wp_trim_words($req->reason_note, 8)); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status_class = 'status-' . $req->status;
                                $status_colors = [
                                    'pending' => '#f0ad4e',
                                    'approved' => '#5cb85c',
                                    'rejected' => '#d9534f',
                                    'cancelled' => '#777',
                                ];
                                ?>
                                <span class="<?php echo esc_attr($status_class); ?>" style="color: <?php echo esc_attr($status_colors[$req->status] ?? '#333'); ?>; font-weight: 600;">
                                    <?php echo esc_html($status_labels[$req->status] ?? $req->status); ?>
                                </span>
                                <?php if ($req->reviewed_at): ?>
                                    <br><small class="description"><?php echo esc_html(date_i18n('M j, H:i', strtotime($req->reviewed_at))); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req->reviewer_name): ?>
                                    <?php echo esc_html($req->reviewer_name); ?>
                                    <?php if ($req->manager_note): ?>
                                        <br><small class="description" title="<?php echo esc_attr($req->manager_note); ?>">
                                            "<?php echo esc_html(wp_trim_words($req->manager_note, 5)); ?>"
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req->status === 'pending'): ?>
                                    <button type="button" class="button button-primary button-small early-leave-approve"
                                            data-id="<?php echo intval($req->id); ?>"
                                            title="<?php esc_attr_e('Approve', 'sfs-hr'); ?>">
                                        <span class="dashicons dashicons-yes" style="margin-top: 3px;"></span>
                                    </button>
                                    <button type="button" class="button button-small early-leave-reject"
                                            data-id="<?php echo intval($req->id); ?>"
                                            title="<?php esc_attr_e('Reject', 'sfs-hr'); ?>">
                                        <span class="dashicons dashicons-no" style="margin-top: 3px;"></span>
                                    </button>
                                <?php elseif ($req->status === 'approved' || $req->status === 'rejected'): ?>
                                    <small class="description"><?php esc_html_e('Reviewed', 'sfs-hr'); ?></small>
                                <?php else: ?>
                                    <small class="description"><?php esc_html_e('Cancelled', 'sfs-hr'); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Review Modal -->
        <div id="early-leave-review-modal" style="display: none;">
            <div class="early-leave-modal-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000;"></div>
            <div class="early-leave-modal-content" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #fff; padding: 20px; border-radius: 5px; z-index: 100001; min-width: 400px; max-width: 500px;">
                <h3 id="early-leave-modal-title"><?php esc_html_e('Review Early Leave Request', 'sfs-hr'); ?></h3>
                <input type="hidden" id="early-leave-request-id" value="" />
                <input type="hidden" id="early-leave-action" value="" />

                <p>
                    <label for="early-leave-note"><?php esc_html_e('Manager Note (optional):', 'sfs-hr'); ?></label>
                    <textarea id="early-leave-note" rows="3" style="width: 100%;"></textarea>
                </p>

                <p class="submit" style="text-align: right;">
                    <button type="button" class="button early-leave-modal-cancel"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                    <button type="button" class="button button-primary early-leave-modal-submit"><?php esc_html_e('Submit', 'sfs-hr'); ?></button>
                </p>
            </div>
        </div>
    </div>

    <script>
    jQuery(function($) {
        var $modal = $('#early-leave-review-modal');

        // Approve button click
        $('.early-leave-approve').on('click', function() {
            var id = $(this).data('id');
            $('#early-leave-request-id').val(id);
            $('#early-leave-action').val('approved');
            $('#early-leave-modal-title').text('<?php echo esc_js(__('Approve Early Leave Request', 'sfs-hr')); ?>');
            $modal.show();
        });

        // Reject button click
        $('.early-leave-reject').on('click', function() {
            var id = $(this).data('id');
            $('#early-leave-request-id').val(id);
            $('#early-leave-action').val('rejected');
            $('#early-leave-modal-title').text('<?php echo esc_js(__('Reject Early Leave Request', 'sfs-hr')); ?>');
            $modal.show();
        });

        // Modal cancel
        $('.early-leave-modal-cancel, .early-leave-modal-overlay').on('click', function() {
            $modal.hide();
            $('#early-leave-note').val('');
        });

        // Modal submit
        $('.early-leave-modal-submit').on('click', function() {
            var id = $('#early-leave-request-id').val();
            var action = $('#early-leave-action').val();
            var note = $('#early-leave-note').val();

            $(this).prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'sfs-hr')); ?>');

            $.ajax({
                url: '<?php echo esc_url(rest_url('sfs-hr/v1/early-leave/review/')); ?>' + id,
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                },
                data: {
                    status: action,
                    manager_note: note
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.message || '<?php echo esc_js(__('An error occurred', 'sfs-hr')); ?>');
                        $('.early-leave-modal-submit').prop('disabled', false).text('<?php echo esc_js(__('Submit', 'sfs-hr')); ?>');
                    }
                },
                error: function(xhr) {
                    var msg = xhr.responseJSON && xhr.responseJSON.message
                        ? xhr.responseJSON.message
                        : '<?php echo esc_js(__('An error occurred', 'sfs-hr')); ?>';
                    alert(msg);
                    $('.early-leave-modal-submit').prop('disabled', false).text('<?php echo esc_js(__('Submit', 'sfs-hr')); ?>');
                }
            });
        });
    });
    </script>

    <style>
    .reason-sick { color: #d9534f; }
    .reason-external_task { color: #5bc0de; }
    .reason-personal { color: #f0ad4e; }
    .reason-emergency { color: #d9534f; font-weight: bold; }
    .reason-other { color: #777; }

    .early-leave-filters label {
        font-weight: 600;
    }
    </style>
    <?php
}


}