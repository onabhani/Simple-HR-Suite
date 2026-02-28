<?php
namespace SFS\HR\Modules\Projects\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Projects\Services\Projects_Service;

class Admin_Pages {

    private static $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function hooks(): void {
        add_action( 'admin_post_sfs_hr_save_project',          [ $this, 'handle_save_project' ] );
        add_action( 'admin_post_sfs_hr_assign_project_employee', [ $this, 'handle_assign_employee' ] );
        add_action( 'admin_post_sfs_hr_remove_project_employee', [ $this, 'handle_remove_employee' ] );
        add_action( 'admin_post_sfs_hr_add_project_shift',      [ $this, 'handle_add_shift' ] );
        add_action( 'admin_post_sfs_hr_remove_project_shift',   [ $this, 'handle_remove_shift' ] );
    }

    /**
     * Main page router.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Access denied.', 'sfs-hr' ) );
        }

        $action = sanitize_key( $_GET['action'] ?? 'list' );

        echo '<div class="wrap sfs-hr-wrap">';

        switch ( $action ) {
            case 'new':
            case 'edit':
                $this->render_form();
                break;
            case 'view':
                $this->render_view();
                break;
            default:
                $this->render_list();
        }

        echo '</div>';
        $this->inline_styles();
    }

    /* ======================== LIST ======================== */

    private function render_list(): void {
        $projects = Projects_Service::get_all();
        ?>
        <h1>
            <?php esc_html_e( 'Projects', 'sfs-hr' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-projects&action=new' ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Add New', 'sfs-hr' ); ?>
            </a>
        </h1>

        <?php if ( ! empty( $_GET['saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Project saved.', 'sfs-hr' ); ?></p></div>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Project', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Location', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Start', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'End', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Employees', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $projects ) ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No projects yet.', 'sfs-hr' ); ?></td></tr>
                <?php else : ?>
                    <?php
                        $project_ids = array_map( fn( $p ) => (int) $p->id, $projects );
                        $emp_counts  = Projects_Service::get_employee_counts( $project_ids );
                    ?>
                    <?php foreach ( $projects as $p ) :
                        $emp_count = $emp_counts[ (int) $p->id ] ?? 0;
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-projects&action=view&id=' . $p->id ) ); ?>">
                                    <strong><?php echo esc_html( $p->name ); ?></strong>
                                </a>
                            </td>
                            <td><?php echo esc_html( $p->location_label ?: '—' ); ?></td>
                            <td><?php echo esc_html( $p->start_date ?: '—' ); ?></td>
                            <td><?php echo esc_html( $p->end_date ?: '—' ); ?></td>
                            <td><?php echo (int) $emp_count; ?></td>
                            <td>
                                <span class="sfs-hr-status-badge <?php echo $p->active ? 'sfs-hr-status-active' : 'sfs-hr-status-archived'; ?>">
                                    <?php echo $p->active ? esc_html__( 'Active', 'sfs-hr' ) : esc_html__( 'Inactive', 'sfs-hr' ); ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-projects&action=edit&id=' . $p->id ) ); ?>" class="button button-small">
                                    <?php esc_html_e( 'Edit', 'sfs-hr' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /* ======================== FORM (new / edit) ======================== */

    private function render_form(): void {
        $id      = (int) ( $_GET['id'] ?? 0 );
        $project = $id ? Projects_Service::get( $id ) : null;
        $is_edit = (bool) $project;

        ?>
        <h1><?php echo $is_edit ? esc_html__( 'Edit Project', 'sfs-hr' ) : esc_html__( 'New Project', 'sfs-hr' ); ?></h1>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'sfs_hr_save_project' ); ?>
            <input type="hidden" name="action" value="sfs_hr_save_project"/>
            <input type="hidden" name="project_id" value="<?php echo $id; ?>"/>

            <div class="sfs-hr-card">
                <h3><?php esc_html_e( 'Project Details', 'sfs-hr' ); ?></h3>

                <div class="sfs-hr-form-grid">
                    <div class="sfs-hr-form-row">
                        <label for="prj-name"><?php esc_html_e( 'Project Name', 'sfs-hr' ); ?> *</label>
                        <input type="text" id="prj-name" name="name" required
                               value="<?php echo esc_attr( $project->name ?? '' ); ?>"/>
                    </div>

                    <div class="sfs-hr-form-row">
                        <label for="prj-location"><?php esc_html_e( 'Location Label', 'sfs-hr' ); ?></label>
                        <input type="text" id="prj-location" name="location_label"
                               value="<?php echo esc_attr( $project->location_label ?? '' ); ?>"
                               placeholder="<?php esc_attr_e( 'e.g. Site A — Riyadh', 'sfs-hr' ); ?>"/>
                    </div>

                    <div class="sfs-hr-form-row">
                        <label for="prj-lat"><?php esc_html_e( 'Latitude', 'sfs-hr' ); ?></label>
                        <input type="text" id="prj-lat" name="location_lat"
                               value="<?php echo esc_attr( $project->location_lat ?? '' ); ?>"
                               placeholder="24.7136"/>
                    </div>

                    <div class="sfs-hr-form-row">
                        <label for="prj-lng"><?php esc_html_e( 'Longitude', 'sfs-hr' ); ?></label>
                        <input type="text" id="prj-lng" name="location_lng"
                               value="<?php echo esc_attr( $project->location_lng ?? '' ); ?>"
                               placeholder="46.6753"/>
                    </div>

                    <div class="sfs-hr-form-row">
                        <label for="prj-radius"><?php esc_html_e( 'Geofence Radius (m)', 'sfs-hr' ); ?></label>
                        <input type="number" id="prj-radius" name="location_radius_m" min="0" step="1"
                               value="<?php echo esc_attr( $project->location_radius_m ?? 200 ); ?>"/>
                    </div>

                    <div class="sfs-hr-form-row">
                        <label for="prj-manager"><?php esc_html_e( 'Project Manager', 'sfs-hr' ); ?></label>
                        <?php
                        wp_dropdown_users( [
                            'name'             => 'manager_user_id',
                            'id'               => 'prj-manager',
                            'selected'         => $project->manager_user_id ?? 0,
                            'show_option_none' => __( '— None —', 'sfs-hr' ),
                            'option_none_value'=> 0,
                        ] );
                        ?>
                    </div>

                    <div class="sfs-hr-form-row">
                        <label for="prj-start"><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></label>
                        <input type="date" id="prj-start" name="start_date"
                               value="<?php echo esc_attr( $project->start_date ?? '' ); ?>"/>
                    </div>

                    <div class="sfs-hr-form-row">
                        <label for="prj-end"><?php esc_html_e( 'End Date', 'sfs-hr' ); ?></label>
                        <input type="date" id="prj-end" name="end_date"
                               value="<?php echo esc_attr( $project->end_date ?? '' ); ?>"/>
                    </div>
                </div>

                <div class="sfs-hr-form-row">
                    <label for="prj-notes"><?php esc_html_e( 'Notes', 'sfs-hr' ); ?></label>
                    <textarea id="prj-notes" name="notes" rows="3" style="width:100%;max-width:600px;"><?php echo esc_textarea( $project->notes ?? '' ); ?></textarea>
                </div>

                <div class="sfs-hr-form-row">
                    <label>
                        <input type="checkbox" name="active" value="1" <?php checked( $project->active ?? 1, 1 ); ?>/>
                        <?php esc_html_e( 'Active', 'sfs-hr' ); ?>
                    </label>
                </div>
            </div>

            <?php submit_button( $is_edit ? __( 'Update Project', 'sfs-hr' ) : __( 'Create Project', 'sfs-hr' ) ); ?>
        </form>
        <?php
    }

    /* ======================== VIEW (detail page) ======================== */

    private function render_view(): void {
        $id      = (int) ( $_GET['id'] ?? 0 );
        $project = $id ? Projects_Service::get( $id ) : null;

        if ( ! $project ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Project not found.', 'sfs-hr' ) . '</p></div>';
            return;
        }

        $vtab = sanitize_key( $_GET['vtab'] ?? 'manage' );

        ?>
        <h1>
            <?php echo esc_html( $project->name ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-projects&action=edit&id=' . $id ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Edit', 'sfs-hr' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-projects' ) ); ?>" class="page-title-action" style="margin-left:4px;">
                <?php esc_html_e( 'Back to List', 'sfs-hr' ); ?>
            </a>
        </h1>

        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-projects&action=view&id=' . $id . '&vtab=manage' ) ); ?>"
               class="nav-tab <?php echo $vtab === 'manage' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Manage', 'sfs-hr' ); ?>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-projects&action=view&id=' . $id . '&vtab=dashboard' ) ); ?>"
               class="nav-tab <?php echo $vtab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Attendance Dashboard', 'sfs-hr' ); ?>
            </a>
        </nav>

        <?php
        if ( $vtab === 'dashboard' ) {
            $this->render_project_dashboard( $project );
            return;
        }

        // Manage tab
        $employees = Projects_Service::get_project_employees( $id );
        $shifts    = Projects_Service::get_project_shifts( $id );

        // Load all available shifts and employees for the assign forms
        global $wpdb;
        $all_shifts    = $wpdb->get_results( "SELECT id, name, start_time, end_time FROM {$wpdb->prefix}sfs_hr_attendance_shifts WHERE active = 1 ORDER BY name" );
        $all_employees = $wpdb->get_results( "SELECT id, first_name, last_name, employee_code FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active' ORDER BY first_name, last_name" );

        // Existing assigned employee IDs (to filter dropdown)
        $assigned_ids = array_map( fn( $e ) => (int) $e->employee_id, $employees );
        $linked_shift_ids = array_map( fn( $s ) => (int) $s->shift_id, $shifts );

        if ( ! empty( $_GET['employee_added'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Employee assigned.', 'sfs-hr' ) . '</p></div>';
        }
        if ( ! empty( $_GET['employee_removed'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Employee removed.', 'sfs-hr' ) . '</p></div>';
        }
        if ( ! empty( $_GET['shift_added'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shift linked.', 'sfs-hr' ) . '</p></div>';
        }
        if ( ! empty( $_GET['shift_removed'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shift removed.', 'sfs-hr' ) . '</p></div>';
        }
        ?>

        <!-- Project info -->
        <div class="sfs-hr-card">
            <h3><?php esc_html_e( 'Project Info', 'sfs-hr' ); ?></h3>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Location', 'sfs-hr' ); ?></th><td><?php echo esc_html( $project->location_label ?: '—' ); ?></td></tr>
                <?php if ( $project->location_lat && $project->location_lng ) : ?>
                <tr><th><?php esc_html_e( 'Geofence', 'sfs-hr' ); ?></th><td><?php echo esc_html( $project->location_lat . ', ' . $project->location_lng . ' (' . $project->location_radius_m . 'm)' ); ?></td></tr>
                <?php endif; ?>
                <tr><th><?php esc_html_e( 'Dates', 'sfs-hr' ); ?></th><td><?php echo esc_html( ( $project->start_date ?: '?' ) . ' — ' . ( $project->end_date ?: __( 'Ongoing', 'sfs-hr' ) ) ); ?></td></tr>
                <tr>
                    <th><?php esc_html_e( 'Manager', 'sfs-hr' ); ?></th>
                    <td><?php
                        if ( $project->manager_user_id ) {
                            $user = get_user_by( 'id', $project->manager_user_id );
                            echo $user ? esc_html( $user->display_name ) : '—';
                        } else {
                            echo '—';
                        }
                    ?></td>
                </tr>
                <?php if ( $project->notes ) : ?>
                <tr><th><?php esc_html_e( 'Notes', 'sfs-hr' ); ?></th><td><?php echo esc_html( $project->notes ); ?></td></tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Shifts -->
        <div class="sfs-hr-card">
            <h3><?php esc_html_e( 'Project Shifts', 'sfs-hr' ); ?></h3>

            <?php if ( ! empty( $shifts ) ) : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom:16px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Shift', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Time', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Location', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Default', 'sfs-hr' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $shifts as $s ) : ?>
                            <tr>
                                <td><?php echo esc_html( $s->shift_name ); ?></td>
                                <td><?php echo esc_html( ( $s->start_time ?? '' ) . ' — ' . ( $s->end_time ?? '' ) ); ?></td>
                                <td><?php echo esc_html( $s->location_label ?: '—' ); ?></td>
                                <td><?php echo (int) $s->is_default ? '<span class="dashicons dashicons-yes-alt" style="color:#2e7d32;"></span>' : ''; ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                        <?php wp_nonce_field( 'sfs_hr_remove_project_shift' ); ?>
                                        <input type="hidden" name="action" value="sfs_hr_remove_project_shift"/>
                                        <input type="hidden" name="link_id" value="<?php echo (int) $s->id; ?>"/>
                                        <input type="hidden" name="project_id" value="<?php echo $id; ?>"/>
                                        <button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Remove this shift?', 'sfs-hr' ); ?>');">
                                            <?php esc_html_e( 'Remove', 'sfs-hr' ); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color:#666;"><?php esc_html_e( 'No shifts linked to this project yet.', 'sfs-hr' ); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <?php wp_nonce_field( 'sfs_hr_add_project_shift' ); ?>
                <input type="hidden" name="action" value="sfs_hr_add_project_shift"/>
                <input type="hidden" name="project_id" value="<?php echo $id; ?>"/>
                <select name="shift_id" required>
                    <option value=""><?php esc_html_e( '— Select Shift —', 'sfs-hr' ); ?></option>
                    <?php foreach ( $all_shifts as $sh ) :
                        if ( in_array( (int) $sh->id, $linked_shift_ids, true ) ) { continue; }
                    ?>
                        <option value="<?php echo (int) $sh->id; ?>">
                            <?php echo esc_html( $sh->name . ' (' . $sh->start_time . '–' . $sh->end_time . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label style="display:flex;align-items:center;gap:4px;">
                    <input type="checkbox" name="is_default" value="1"/>
                    <?php esc_html_e( 'Default', 'sfs-hr' ); ?>
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Add Shift', 'sfs-hr' ); ?></button>
            </form>
        </div>

        <!-- Employees -->
        <div class="sfs-hr-card">
            <h3><?php esc_html_e( 'Assigned Employees', 'sfs-hr' ); ?></h3>

            <?php if ( ! empty( $employees ) ) : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom:16px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Code', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'From', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'To', 'sfs-hr' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $employees as $emp ) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-employees&action=view&id=' . (int) $emp->employee_id ) ); ?>">
                                        <?php echo esc_html( $emp->first_name . ' ' . $emp->last_name ); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html( $emp->employee_code ?? '—' ); ?></td>
                                <td><?php echo esc_html( $emp->assigned_from ); ?></td>
                                <td><?php echo esc_html( $emp->assigned_to ?: __( 'Ongoing', 'sfs-hr' ) ); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                        <?php wp_nonce_field( 'sfs_hr_remove_project_employee' ); ?>
                                        <input type="hidden" name="action" value="sfs_hr_remove_project_employee"/>
                                        <input type="hidden" name="assignment_id" value="<?php echo (int) $emp->id; ?>"/>
                                        <input type="hidden" name="project_id" value="<?php echo $id; ?>"/>
                                        <button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Remove this employee?', 'sfs-hr' ); ?>');">
                                            <?php esc_html_e( 'Remove', 'sfs-hr' ); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color:#666;"><?php esc_html_e( 'No employees assigned yet.', 'sfs-hr' ); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <?php wp_nonce_field( 'sfs_hr_assign_project_employee' ); ?>
                <input type="hidden" name="action" value="sfs_hr_assign_project_employee"/>
                <input type="hidden" name="project_id" value="<?php echo $id; ?>"/>
                <select name="employee_id" required>
                    <option value=""><?php esc_html_e( '— Select Employee —', 'sfs-hr' ); ?></option>
                    <?php foreach ( $all_employees as $emp ) :
                        if ( in_array( (int) $emp->id, $assigned_ids, true ) ) { continue; }
                    ?>
                        <option value="<?php echo (int) $emp->id; ?>">
                            <?php echo esc_html( $emp->first_name . ' ' . $emp->last_name . ' (' . $emp->employee_code . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="assigned_from" required value="<?php echo esc_attr( $project->start_date ?: wp_date( 'Y-m-d' ) ); ?>"/>
                <input type="date" name="assigned_to" value="<?php echo esc_attr( $project->end_date ?? '' ); ?>" placeholder="<?php esc_attr_e( 'End (optional)', 'sfs-hr' ); ?>"/>
                <button type="submit" class="button"><?php esc_html_e( 'Assign', 'sfs-hr' ); ?></button>
            </form>
        </div>
        <?php
    }

    /* ======================== DASHBOARD ======================== */

    private function render_project_dashboard( \stdClass $project ): void {
        $id = (int) $project->id;

        // Date range — default to project dates or current period
        $period     = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
        $start_date = sanitize_text_field( $_GET['dash_from'] ?? $project->start_date ?? $period['start'] );
        $end_date   = sanitize_text_field( $_GET['dash_to'] ?? $period['end'] );

        // Validate Y-m-d format; fall back to period defaults on malformed input
        $validate_ymd = static fn( string $d ): bool =>
            (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d )
            && \DateTimeImmutable::createFromFormat( 'Y-m-d', $d ) !== false;

        if ( ! $validate_ymd( $start_date ) ) { $start_date = $period['start']; }
        if ( ! $validate_ymd( $end_date ) )   { $end_date   = $period['end']; }

        // Ensure start <= end
        if ( $start_date > $end_date ) { $start_date = $end_date; }

        // Cap end date at today
        $today = wp_date( 'Y-m-d' );
        if ( $end_date > $today ) { $end_date = $today; }

        $employees = Projects_Service::get_project_employees( $id );

        ?>
        <!-- Date filter -->
        <div class="sfs-hr-card" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <strong><?php esc_html_e( 'Period:', 'sfs-hr' ); ?></strong>
            <form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <input type="hidden" name="page" value="sfs-hr-projects"/>
                <input type="hidden" name="action" value="view"/>
                <input type="hidden" name="id" value="<?php echo $id; ?>"/>
                <input type="hidden" name="vtab" value="dashboard"/>
                <input type="date" name="dash_from" value="<?php echo esc_attr( $start_date ); ?>"/>
                <span>—</span>
                <input type="date" name="dash_to" value="<?php echo esc_attr( $end_date ); ?>"/>
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'sfs-hr' ); ?></button>
            </form>
        </div>

        <?php if ( empty( $employees ) ) : ?>
            <div class="sfs-hr-card">
                <p style="color:#666;"><?php esc_html_e( 'No employees assigned to this project.', 'sfs-hr' ); ?></p>
            </div>
        <?php else : ?>
            <?php
            // Compute metrics for each assigned employee
            $rows = [];
            foreach ( $employees as $emp ) {
                $metrics = \SFS\HR\Modules\Performance\Services\Attendance_Metrics::get_employee_metrics(
                    (int) $emp->employee_id,
                    $start_date,
                    $end_date
                );
                if ( ! empty( $metrics['error'] ) ) { continue; }
                $rows[] = $metrics;
            }

            // Sort by commitment % ascending (worst first)
            usort( $rows, fn( $a, $b ) => $a['commitment_pct'] <=> $b['commitment_pct'] );

            // Summary stats
            $total      = count( $rows );
            $avg_commit = $total ? round( array_sum( array_column( $rows, 'commitment_pct' ) ) / $total, 1 ) : 0;
            $total_absent = array_sum( array_column( $rows, 'days_absent' ) );
            $total_late   = array_sum( array_column( $rows, 'late_count' ) );
            ?>

            <!-- Summary cards -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;">
                <div class="sfs-hr-card" style="text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#1565c0;"><?php echo $total; ?></div>
                    <div style="font-size:13px;color:#666;"><?php esc_html_e( 'Employees', 'sfs-hr' ); ?></div>
                </div>
                <div class="sfs-hr-card" style="text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:<?php echo $avg_commit >= 80 ? '#2e7d32' : ( $avg_commit >= 60 ? '#ef6c00' : '#c62828' ); ?>;">
                        <?php echo esc_html( $avg_commit . '%' ); ?>
                    </div>
                    <div style="font-size:13px;color:#666;"><?php esc_html_e( 'Avg Commitment', 'sfs-hr' ); ?></div>
                </div>
                <div class="sfs-hr-card" style="text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#c62828;"><?php echo $total_absent; ?></div>
                    <div style="font-size:13px;color:#666;"><?php esc_html_e( 'Total Absent Days', 'sfs-hr' ); ?></div>
                </div>
                <div class="sfs-hr-card" style="text-align:center;">
                    <div style="font-size:28px;font-weight:700;color:#ef6c00;"><?php echo $total_late; ?></div>
                    <div style="font-size:13px;color:#666;"><?php esc_html_e( 'Total Late Days', 'sfs-hr' ); ?></div>
                </div>
            </div>

            <!-- Employee table -->
            <div class="sfs-hr-card">
                <h3><?php esc_html_e( 'Employee Attendance', 'sfs-hr' ); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Present', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Absent', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Late', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Early Leave', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Incomplete', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Worked Hrs', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Commitment', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $m ) :
                            $pct = $m['commitment_pct'];
                            $pct_color = $pct >= 90 ? '#2e7d32' : ( $pct >= 75 ? '#ef6c00' : '#c62828' );
                            $worked_hrs = round( $m['total_worked_minutes'] / 60, 1 );
                        ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-employees&action=view&id=' . $m['employee_id'] ) ); ?>" style="text-decoration:none;">
                                        <strong><?php echo esc_html( $m['employee_name'] ); ?></strong>
                                    </a><br>
                                    <small style="color:#666;"><?php echo esc_html( $m['employee_code'] ); ?></small>
                                </td>
                                <td><?php echo (int) $m['days_present']; ?></td>
                                <td style="<?php echo $m['days_absent'] ? 'color:#c62828;font-weight:600;' : ''; ?>">
                                    <?php echo (int) $m['days_absent']; ?>
                                </td>
                                <td style="<?php echo $m['late_count'] ? 'color:#ef6c00;' : ''; ?>">
                                    <?php echo (int) $m['late_count']; ?>
                                </td>
                                <td><?php echo (int) $m['early_leave_count']; ?></td>
                                <td><?php echo (int) $m['incomplete_count']; ?></td>
                                <td><?php echo esc_html( $worked_hrs ); ?></td>
                                <td style="color:<?php echo esc_attr( $pct_color ); ?>;font-weight:600;">
                                    <?php echo esc_html( number_format( $pct, 1 ) . '%' ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php
    }

    /* ======================== HANDLERS ======================== */

    public function handle_save_project(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) { wp_die( 'Access denied.' ); }
        check_admin_referer( 'sfs_hr_save_project' );

        $id   = (int) ( $_POST['project_id'] ?? 0 );
        $data = [
            'name'              => sanitize_text_field( $_POST['name'] ?? '' ),
            'location_label'    => sanitize_text_field( $_POST['location_label'] ?? '' ),
            'location_lat'      => ! empty( $_POST['location_lat'] ) ? (float) $_POST['location_lat'] : null,
            'location_lng'      => ! empty( $_POST['location_lng'] ) ? (float) $_POST['location_lng'] : null,
            'location_radius_m' => max( 0, (int) ( $_POST['location_radius_m'] ?? 200 ) ),
            'start_date'        => ! empty( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : null,
            'end_date'          => ! empty( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : null,
            'manager_user_id'   => (int) ( $_POST['manager_user_id'] ?? 0 ) ?: null,
            'active'            => ! empty( $_POST['active'] ) ? 1 : 0,
            'notes'             => sanitize_textarea_field( $_POST['notes'] ?? '' ),
        ];

        if ( $id ) {
            $ok = Projects_Service::update( $id, $data );
        } else {
            $id = Projects_Service::insert( $data );
            $ok = $id > 0;
        }

        if ( $ok && $id ) {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-projects&action=view&id=' . $id . '&saved=1' ) );
        } else {
            wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-projects&save_error=1' ) );
        }
        exit;
    }

    public function handle_assign_employee(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) { wp_die( 'Access denied.' ); }
        check_admin_referer( 'sfs_hr_assign_project_employee' );

        $project_id  = (int) ( $_POST['project_id'] ?? 0 );
        $employee_id = (int) ( $_POST['employee_id'] ?? 0 );
        $from        = sanitize_text_field( $_POST['assigned_from'] ?? '' );
        $to          = ! empty( $_POST['assigned_to'] ) ? sanitize_text_field( $_POST['assigned_to'] ) : null;

        $success = false;
        if ( $project_id && $employee_id && $from ) {
            // Validate date interval: $to must not be earlier than $from
            if ( $to !== null && $to < $from ) {
                $success = false;
            } else {
                $success = Projects_Service::assign_employee( $project_id, $employee_id, $from, $to ) > 0;
            }
        }

        $flag = $success ? 'employee_added=1' : 'assign_error=1';
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-projects&action=view&id=' . $project_id . '&' . $flag ) );
        exit;
    }

    public function handle_remove_employee(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) { wp_die( 'Access denied.' ); }
        check_admin_referer( 'sfs_hr_remove_project_employee' );

        $assignment_id = (int) ( $_POST['assignment_id'] ?? 0 );
        $project_id    = (int) ( $_POST['project_id'] ?? 0 );

        $success = false;
        if ( $assignment_id && $project_id ) {
            // Verify the assignment belongs to the given project before deleting
            $assignment = Projects_Service::get_assignment( $assignment_id );
            if ( $assignment && (int) $assignment->project_id === $project_id ) {
                $success = Projects_Service::remove_employee_assignment( $assignment_id );
            }
        }

        $flag = $success ? 'employee_removed=1' : 'remove_error=1';
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-projects&action=view&id=' . $project_id . '&' . $flag ) );
        exit;
    }

    public function handle_add_shift(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) { wp_die( 'Access denied.' ); }
        check_admin_referer( 'sfs_hr_add_project_shift' );

        $project_id = (int) ( $_POST['project_id'] ?? 0 );
        $shift_id   = (int) ( $_POST['shift_id'] ?? 0 );
        $is_default = ! empty( $_POST['is_default'] );

        $success = false;
        if ( $project_id && $shift_id ) {
            $success = Projects_Service::add_shift( $project_id, $shift_id, $is_default ) > 0;
        }

        $flag = $success ? 'shift_added=1' : 'shift_error=1';
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-projects&action=view&id=' . $project_id . '&' . $flag ) );
        exit;
    }

    public function handle_remove_shift(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) { wp_die( 'Access denied.' ); }
        check_admin_referer( 'sfs_hr_remove_project_shift' );

        $link_id    = (int) ( $_POST['link_id'] ?? 0 );
        $project_id = (int) ( $_POST['project_id'] ?? 0 );

        $success = false;
        if ( $link_id && $project_id ) {
            // Verify the shift link belongs to the given project before deleting
            $link = Projects_Service::get_shift_link( $link_id );
            if ( $link && (int) $link->project_id === $project_id ) {
                $success = Projects_Service::remove_shift( $link_id );
            }
        }

        $flag = $success ? 'shift_removed=1' : 'shift_remove_error=1';
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-projects&action=view&id=' . $project_id . '&' . $flag ) );
        exit;
    }

    /* ======================== STYLES ======================== */

    private function inline_styles(): void {
        ?>
        <style>
            .sfs-hr-card { background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:20px; margin-bottom:20px; }
            .sfs-hr-card h3 { margin-top:0; padding-bottom:10px; border-bottom:1px solid #eee; }
            .sfs-hr-form-row { margin-bottom:15px; }
            .sfs-hr-form-row label { display:block; margin-bottom:5px; font-weight:600; }
            .sfs-hr-form-row input[type="text"],
            .sfs-hr-form-row input[type="number"],
            .sfs-hr-form-row input[type="date"],
            .sfs-hr-form-row select,
            .sfs-hr-form-row textarea { width:100%; max-width:400px; }
            .sfs-hr-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
            @media (max-width:782px) { .sfs-hr-form-grid { grid-template-columns:1fr; } }
            .sfs-hr-status-badge { display:inline-block; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500; }
            .sfs-hr-status-active { background:#e8f5e9; color:#2e7d32; }
            .sfs-hr-status-archived { background:#f5f5f5; color:#616161; }
        </style>
        <?php
    }
}
