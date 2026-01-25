<?php
namespace SFS\HR\Modules\Departments;

use SFS\HR\Core\Helpers;

if (!defined('ABSPATH')) { exit; }

class DepartmentsModule {

    public function hooks(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_sfs_hr_dept_save',  [$this, 'handle_save']);
        add_action('admin_post_sfs_hr_dept_del',   [$this, 'handle_delete']);
        add_action('admin_post_sfs_hr_dept_sync',  [$this, 'handle_sync']);
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __('Departments','sfs-hr'),
            __('Departments','sfs-hr'),
            'sfs_hr.manage',
            'sfs-hr-departments',
            [$this, 'render']
        );
    }

    public function render(): void {
    Helpers::require_cap( 'sfs_hr.manage' );
    global $wpdb, $wp_roles;

    $t    = $wpdb->prefix . 'sfs_hr_departments';
    $rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id ASC", ARRAY_A );

    $roles      = is_object( $wp_roles ) ? $wp_roles->get_names() : [];
    $nonce_save = wp_create_nonce( 'sfs_hr_dept_save' );
    $nonce_del  = wp_create_nonce( 'sfs_hr_dept_del' );
    $nonce_sync = wp_create_nonce( 'sfs_hr_dept_sync' );
    ?>
    <div class="wrap sfs-hr-wrap">
      <h1 class="wp-heading-inline"><?php esc_html_e( 'Departments', 'sfs-hr' ); ?></h1>
      <?php Helpers::render_admin_nav(); ?>
      <hr class="wp-header-end" />

      <style>
        .sfs-hr-dept-page { max-width: 900px; }
        .sfs-hr-dept-page .sfs-hr-section-card {
            margin-top: 16px;
            background: #fff;
            border-radius: 8px;
            border: 1px solid #e2e4e7;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
            overflow: hidden;
        }
        .sfs-hr-dept-page .sfs-hr-section-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f0f0f1;
            background: #f9fafb;
        }
        .sfs-hr-dept-page .sfs-hr-section-title {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #1d2327;
        }

        /* Department table */
        .sfs-hr-dept-table {
            width: 100%;
            border-collapse: collapse;
        }
        .sfs-hr-dept-table th {
            background: #f9fafb;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            color: #50575e;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 1px solid #e2e4e7;
        }
        .sfs-hr-dept-table td {
            padding: 14px 16px;
            font-size: 13px;
            border-bottom: 1px solid #f0f0f1;
            vertical-align: middle;
        }
        .sfs-hr-dept-table tbody tr:last-child td { border-bottom: none; }
        .sfs-hr-dept-table tbody tr:hover { background: #f9fafb; }
        .sfs-hr-dept-name { font-weight: 600; color: #1d2327; }
        .sfs-hr-dept-manager { color: #50575e; font-size: 12px; }
        .sfs-hr-dept-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
        }
        .sfs-hr-dept-badge--active { background: #d1fae5; color: #065f46; }
        .sfs-hr-dept-badge--inactive { background: #fee2e2; color: #991b1b; }
        .sfs-hr-dept-badge--auto { background: #dbeafe; color: #1e40af; margin-left: 6px; }
        .sfs-hr-color-swatch {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 1px solid #dcdcde;
            vertical-align: middle;
        }

        /* Accordion for editing */
        .sfs-hr-dept-accordion {
            border-top: 1px solid #f0f0f1;
            background: #fafafa;
            display: none;
        }
        .sfs-hr-dept-accordion.active { display: block; }
        .sfs-hr-dept-accordion-inner {
            padding: 20px;
        }
        .sfs-hr-dept-form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .sfs-hr-dept-form-grid .sfs-hr-field { margin-bottom: 0; }
        .sfs-hr-dept-form-grid .sfs-hr-field.full-width { grid-column: 1 / -1; }
        .sfs-hr-dept-form-grid .sfs-hr-field label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #50575e;
            margin-bottom: 6px;
        }
        .sfs-hr-dept-form-grid .sfs-hr-field input[type="text"],
        .sfs-hr-dept-form-grid .sfs-hr-field select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            font-size: 13px;
        }
        .sfs-hr-dept-form-grid .sfs-hr-field select[multiple] {
            height: auto;
            min-height: 80px;
        }
        .sfs-hr-dept-form-grid .sfs-hr-field input[type="color"] {
            width: 50px;
            height: 36px;
            padding: 2px;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            cursor: pointer;
        }
        .sfs-hr-dept-form-grid .sfs-hr-field .description {
            font-size: 11px;
            color: #757575;
            margin-top: 4px;
        }
        .sfs-hr-dept-form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #e2e4e7;
            flex-wrap: wrap;
            gap: 10px;
        }
        .sfs-hr-dept-form-actions-left { display: flex; gap: 8px; flex-wrap: wrap; }
        .sfs-hr-dept-form-actions-right { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

        /* Action buttons */
        .sfs-hr-dept-action-btn {
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
            color: #2271b1;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .sfs-hr-dept-action-btn:hover {
            background: #fff;
            border-color: #2271b1;
            color: #135e96;
        }
        .sfs-hr-dept-action-btn--danger { color: #d63638; }
        .sfs-hr-dept-action-btn--danger:hover { border-color: #d63638; }

        /* Add department form */
        .sfs-hr-add-dept-form {
            padding: 20px;
        }

        /* Checkbox styling */
        .sfs-hr-checkbox-inline {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #1d2327;
        }

        @media (max-width: 782px) {
            .sfs-hr-dept-table th:nth-child(3),
            .sfs-hr-dept-table td:nth-child(3),
            .sfs-hr-dept-table th:nth-child(4),
            .sfs-hr-dept-table td:nth-child(4) { display: none; }
            .sfs-hr-dept-form-grid { grid-template-columns: 1fr; }
        }
      </style>

      <div class="sfs-hr-dept-page">
      <!-- Existing departments -->
      <div class="sfs-hr-section-card">
        <div class="sfs-hr-section-header">
          <h2 class="sfs-hr-section-title"><?php esc_html_e( 'Current Departments', 'sfs-hr' ); ?></h2>
        </div>

        <?php if ( ! $rows ) : ?>
          <p style="padding: 20px;"><?php esc_html_e( 'No departments yet. Add one below.', 'sfs-hr' ); ?></p>
        <?php else : ?>
          <table class="sfs-hr-dept-table">
            <thead>
              <tr>
                <th><?php esc_html_e( 'Department', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Manager', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Color', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                <th style="width: 100px;"><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ( $rows as $r ) :
                $manager_name = '';
                if ( ! empty( $r['manager_user_id'] ) ) {
                  $manager_user = get_user_by( 'id', (int) $r['manager_user_id'] );
                  $manager_name = $manager_user ? $manager_user->display_name : '';
                }
                $auto_roles_selected = self::parse_role_list( $r['auto_role'] ?? '' );
                $auto_roles_count = count( $auto_roles_selected );
              ?>
                <tr>
                  <td>
                    <span class="sfs-hr-dept-name"><?php echo esc_html( $r['name'] ); ?></span>
                    <?php if ( $auto_roles_count > 0 ) : ?>
                      <span class="sfs-hr-dept-badge sfs-hr-dept-badge--auto">
                        <?php printf( esc_html( _n( '%d role', '%d roles', $auto_roles_count, 'sfs-hr' ) ), $auto_roles_count ); ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="sfs-hr-dept-manager"><?php echo esc_html( $manager_name ?: '—' ); ?></span>
                  </td>
                  <td>
                    <span class="sfs-hr-color-swatch" style="background-color: <?php echo esc_attr( ! empty( $r['color'] ) ? $r['color'] : '#1e3a5f' ); ?>;"></span>
                  </td>
                  <td>
                    <?php if ( (int) $r['active'] === 1 ) : ?>
                      <span class="sfs-hr-dept-badge sfs-hr-dept-badge--active"><?php esc_html_e( 'Active', 'sfs-hr' ); ?></span>
                    <?php else : ?>
                      <span class="sfs-hr-dept-badge sfs-hr-dept-badge--inactive"><?php esc_html_e( 'Inactive', 'sfs-hr' ); ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <button type="button" class="sfs-hr-dept-action-btn" onclick="sfsHrToggleDeptEdit(<?php echo (int) $r['id']; ?>);">
                      <?php esc_html_e( 'Edit', 'sfs-hr' ); ?>
                    </button>
                  </td>
                </tr>
                <tr>
                  <td colspan="5" style="padding: 0;">
                    <div id="sfs-hr-dept-accordion-<?php echo (int) $r['id']; ?>" class="sfs-hr-dept-accordion">
                      <div class="sfs-hr-dept-accordion-inner">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                          <input type="hidden" name="action" value="sfs_hr_dept_save" />
                          <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_save ); ?>" />
                          <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />

                          <div class="sfs-hr-dept-form-grid">
                            <div class="sfs-hr-field">
                              <label for="sfs-hr-dept-name-<?php echo (int) $r['id']; ?>"><?php esc_html_e( 'Department Name', 'sfs-hr' ); ?></label>
                              <input type="text" id="sfs-hr-dept-name-<?php echo (int) $r['id']; ?>" name="name" value="<?php echo esc_attr( $r['name'] ); ?>" required />
                            </div>

                            <div class="sfs-hr-field">
                              <label for="sfs-hr-dept-manager-<?php echo (int) $r['id']; ?>"><?php esc_html_e( 'Manager', 'sfs-hr' ); ?></label>
                              <?php
                              wp_dropdown_users( [
                                'name'             => 'manager_user_id',
                                'id'               => 'sfs-hr-dept-manager-' . (int) $r['id'],
                                'selected'         => (int) $r['manager_user_id'],
                                'show'             => 'display_name',
                                'include_selected' => true,
                                'show_option_none' => __( '— None —', 'sfs-hr' ),
                              ] );
                              ?>
                            </div>

                            <div class="sfs-hr-field">
                              <label for="sfs-hr-dept-auto-<?php echo (int) $r['id']; ?>"><?php esc_html_e( 'Auto-membership Roles', 'sfs-hr' ); ?></label>
                              <select name="auto_role[]" id="sfs-hr-dept-auto-<?php echo (int) $r['id']; ?>" multiple="multiple">
                                <?php foreach ( $roles as $slug => $label ) : ?>
                                  <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( in_array( $slug, $auto_roles_selected, true ), true ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                  </option>
                                <?php endforeach; ?>
                              </select>
                              <p class="description"><?php esc_html_e( 'Ctrl/Cmd + click to select multiple', 'sfs-hr' ); ?></p>
                            </div>

                            <div class="sfs-hr-field">
                              <label for="sfs-hr-dept-approver-<?php echo (int) $r['id']; ?>"><?php esc_html_e( 'Approver Role (fallback)', 'sfs-hr' ); ?></label>
                              <select name="approver_role" id="sfs-hr-dept-approver-<?php echo (int) $r['id']; ?>">
                                <option value=""><?php esc_html_e( '— None —', 'sfs-hr' ); ?></option>
                                <?php foreach ( $roles as $slug => $label ) : ?>
                                  <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $r['approver_role'], $slug ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                  </option>
                                <?php endforeach; ?>
                              </select>
                            </div>

                            <div class="sfs-hr-field">
                              <label for="sfs-hr-dept-color-<?php echo (int) $r['id']; ?>"><?php esc_html_e( 'Card Color', 'sfs-hr' ); ?></label>
                              <input type="color" id="sfs-hr-dept-color-<?php echo (int) $r['id']; ?>" name="color" value="<?php echo esc_attr( ! empty( $r['color'] ) ? $r['color'] : '#1e3a5f' ); ?>" />
                            </div>

                            <div class="sfs-hr-field">
                              <label><?php esc_html_e( 'Status', 'sfs-hr' ); ?></label>
                              <label class="sfs-hr-checkbox-inline">
                                <input type="checkbox" name="active" value="1" <?php checked( (int) $r['active'], 1 ); ?> />
                                <?php esc_html_e( 'Active', 'sfs-hr' ); ?>
                              </label>
                            </div>
                          </div>

                          <div class="sfs-hr-dept-form-actions">
                            <div class="sfs-hr-dept-form-actions-left">
                              <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'sfs-hr' ); ?></button>
                              <button type="button" class="button" onclick="sfsHrToggleDeptEdit(<?php echo (int) $r['id']; ?>);"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></button>
                            </div>
                            <div class="sfs-hr-dept-form-actions-right">
                              <?php if ( ! empty( $r['auto_role'] ) ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                  <input type="hidden" name="action" value="sfs_hr_dept_sync" />
                                  <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_sync ); ?>" />
                                  <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />
                                  <input type="hidden" name="missing_only" value="1" />
                                  <button type="submit" class="sfs-hr-dept-action-btn"><?php esc_html_e( 'Sync Members', 'sfs-hr' ); ?></button>
                                </form>
                              <?php endif; ?>
                            </div>
                          </div>
                        </form>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this department?', 'sfs-hr' ) ); ?>');" style="margin-top: 10px; text-align: right;">
                          <input type="hidden" name="action" value="sfs_hr_dept_del" />
                          <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_del ); ?>" />
                          <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />
                          <button type="submit" class="sfs-hr-dept-action-btn sfs-hr-dept-action-btn--danger"><?php esc_html_e( 'Delete Department', 'sfs-hr' ); ?></button>
                        </form>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div><!-- .sfs-hr-section-card -->

      <!-- Add Department -->
      <div class="sfs-hr-section-card" style="margin-top: 24px;">
        <div class="sfs-hr-section-header">
          <h2 class="sfs-hr-section-title"><?php esc_html_e( 'Add New Department', 'sfs-hr' ); ?></h2>
        </div>
        <div class="sfs-hr-add-dept-form">
          <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="sfs_hr_dept_save" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_save ); ?>" />

            <div class="sfs-hr-dept-form-grid">
              <div class="sfs-hr-field">
                <label for="sfs-hr-dept-new-name"><?php esc_html_e( 'Department Name', 'sfs-hr' ); ?></label>
                <input type="text" id="sfs-hr-dept-new-name" name="name" required />
              </div>

              <div class="sfs-hr-field">
                <label for="sfs-hr-dept-new-manager"><?php esc_html_e( 'Manager', 'sfs-hr' ); ?></label>
                <?php
                wp_dropdown_users( [
                  'name'             => 'manager_user_id',
                  'id'               => 'sfs-hr-dept-new-manager',
                  'selected'         => 0,
                  'show'             => 'display_name',
                  'include_selected' => true,
                  'show_option_none' => __( '— None —', 'sfs-hr' ),
                ] );
                ?>
              </div>

              <div class="sfs-hr-field">
                <label for="sfs-hr-dept-new-auto"><?php esc_html_e( 'Auto-membership Roles', 'sfs-hr' ); ?></label>
                <select name="auto_role[]" id="sfs-hr-dept-new-auto" multiple="multiple">
                  <?php foreach ( $roles as $slug => $label ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e( 'Ctrl/Cmd + click to select multiple', 'sfs-hr' ); ?></p>
              </div>

              <div class="sfs-hr-field">
                <label for="sfs-hr-dept-new-approver"><?php esc_html_e( 'Approver Role (fallback)', 'sfs-hr' ); ?></label>
                <select name="approver_role" id="sfs-hr-dept-new-approver">
                  <option value=""><?php esc_html_e( '— None —', 'sfs-hr' ); ?></option>
                  <?php foreach ( $roles as $slug => $label ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="sfs-hr-field">
                <label for="sfs-hr-dept-new-color"><?php esc_html_e( 'Card Color', 'sfs-hr' ); ?></label>
                <input type="color" id="sfs-hr-dept-new-color" name="color" value="#1e3a5f" />
              </div>

              <div class="sfs-hr-field">
                <label><?php esc_html_e( 'Status', 'sfs-hr' ); ?></label>
                <label class="sfs-hr-checkbox-inline">
                  <input type="checkbox" name="active" value="1" checked />
                  <?php esc_html_e( 'Active', 'sfs-hr' ); ?>
                </label>
              </div>
            </div>

            <div style="margin-top: 20px;">
              <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Department', 'sfs-hr' ); ?></button>
            </div>
          </form>
        </div>
      </div><!-- .sfs-hr-section-card -->
      </div><!-- .sfs-hr-dept-page -->

      <script>
      function sfsHrToggleDeptEdit(id) {
        var accordion = document.getElementById('sfs-hr-dept-accordion-' + id);
        if (accordion) {
          accordion.classList.toggle('active');
        }
      }
      </script>
    </div><!-- .wrap -->
    <?php
}





    public function handle_save(): void {
    Helpers::require_cap( 'sfs_hr.manage' );
    check_admin_referer( 'sfs_hr_dept_save' );

    $base_url = admin_url( 'admin.php?page=sfs-hr-departments' );

    $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
    $mgr  = isset( $_POST['manager_user_id'] ) ? (int) $_POST['manager_user_id'] : 0;
    $auto_raw = $_POST['auto_role'] ?? '';
    $auto     = self::stringify_role_list( $auto_raw );
    $appr = isset( $_POST['approver_role'] ) ? sanitize_text_field( $_POST['approver_role'] ) : '';
    $act  = ! empty( $_POST['active'] ) ? 1 : 0;
    $color = isset( $_POST['color'] ) ? sanitize_hex_color( $_POST['color'] ) : '#1e3a5f';

    if ( $name === '' ) {
        Helpers::redirect_with_notice(
            $base_url,
            'error',
            __( 'Department name is required.', 'sfs-hr' )
        );
    }

    global $wpdb;
    $t   = $wpdb->prefix . 'sfs_hr_departments';
    $now = current_time( 'mysql' );

    $data = [
        'name'            => $name,
        'manager_user_id' => ( $mgr ?: null ),
        'auto_role'       => ( $auto ?: null ),
        'approver_role'   => ( $appr ?: null ),
        'active'          => $act,
        'color'           => ( $color ?: '#1e3a5f' ),
    ];

    if ( $id > 0 ) {
        $data['updated_at'] = $now;
        $wpdb->update( $t, $data, [ 'id' => $id ] );

        Helpers::redirect_with_notice(
            $base_url,
            'success',
            __( 'Department updated successfully.', 'sfs-hr' )
        );
    }

    // New department
    $data['created_at'] = $now;
    $data['updated_at'] = $now;
    $wpdb->insert( $t, $data );

    Helpers::redirect_with_notice(
        $base_url,
        'success',
        __( 'Department added successfully.', 'sfs-hr' )
    );
}


    public function handle_delete(): void {
    Helpers::require_cap( 'sfs_hr.manage' );

    $base_url = admin_url( 'admin.php?page=sfs-hr-departments' );

    // Nonce check
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'sfs_hr_dept_del' ) ) {
        Helpers::redirect_with_notice(
            $base_url,
            'error',
            __( 'Security check failed while deleting department.', 'sfs-hr' )
        );
    }

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id <= 0 ) {
        Helpers::redirect_with_notice(
            $base_url,
            'error',
            __( 'Invalid department.', 'sfs-hr' )
        );
    }

    global $wpdb;

    $dept_table = $wpdb->prefix . 'sfs_hr_departments';
    $emp_table  = $wpdb->prefix . 'sfs_hr_employees';

    // Optional: reassign employees before deleting
    $general_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$dept_table} WHERE name = %s LIMIT 1",
            'General'
        )
    );

    if ( $general_id > 0 ) {
        // Move employees to "General"
        $wpdb->update(
            $emp_table,
            [ 'dept_id' => $general_id ],
            [ 'dept_id' => $id ]
        );
    } else {
        // Or just null them out
        $wpdb->update(
            $emp_table,
            [ 'dept_id' => null ],
            [ 'dept_id' => $id ]
        );
    }

    // Delete department
    $wpdb->delete( $dept_table, [ 'id' => $id ] );

    Helpers::redirect_with_notice(
        $base_url,
        'success',
        __( 'Department deleted successfully.', 'sfs-hr' )
    );
}


    public function handle_sync(): void {
    Helpers::require_cap( 'sfs_hr.manage' );
    check_admin_referer( 'sfs_hr_dept_sync' );

    $base_url     = admin_url( 'admin.php?page=sfs-hr-departments' );
    $id           = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $missing_only = ! empty( $_POST['missing_only'] );

    if ( $id <= 0 ) {
        Helpers::redirect_with_notice(
            $base_url,
            'error',
            __( 'Invalid department.', 'sfs-hr' )
        );
    }

    global $wpdb;

    $dept_table = $wpdb->prefix . 'sfs_hr_departments';
    $emp_table  = $wpdb->prefix . 'sfs_hr_employees';

    $dept = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$dept_table} WHERE id = %d",
            $id
        ),
        ARRAY_A
    );

    if ( ! $dept ) {
        Helpers::redirect_with_notice(
            $base_url,
            'error',
            __( 'Department not found.', 'sfs-hr' )
        );
    }

    $auto_roles = self::parse_role_list( $dept['auto_role'] ?? '' );

if ( empty( $auto_roles ) ) {
    Helpers::redirect_with_notice(
        $base_url,
        'error',
        __( 'No auto-membership roles set for this department.', 'sfs-hr' )
    );
}

// Fetch all users having any of these roles.
$users = get_users(
    [
        'role__in' => $auto_roles,
        'fields'   => [ 'ID' ],
    ]
);


    if ( ! $users ) {
        Helpers::redirect_with_notice(
            $base_url,
            'info',
            __( 'No users found with this auto-membership role. Nothing to sync.', 'sfs-hr' )
        );
    }

    // Map to user IDs
    $uids = array_map(
        static function ( $u ) {
            return (int) $u->ID;
        },
        $users
    );

    if ( ! $uids ) {
        Helpers::redirect_with_notice(
            $base_url,
            'info',
            __( 'No users found to sync.', 'sfs-hr' )
        );
    }

    // Build IN() placeholders
    $placeholders = implode( ',', array_fill( 0, count( $uids ), '%d' ) );
    $params       = array_merge( [ $id ], $uids );

    if ( $missing_only ) {
        $sql = "
            UPDATE {$emp_table}
               SET dept_id = %d
             WHERE (dept_id IS NULL OR dept_id = 0)
               AND user_id IN ({$placeholders})
        ";
    } else {
        $sql = "
            UPDATE {$emp_table}
               SET dept_id = %d
             WHERE user_id IN ({$placeholders})
        ";
    }

    $wpdb->query( $wpdb->prepare( $sql, $params ) );
    $updated = (int) $wpdb->rows_affected;

    if ( $updated === 0 ) {
        $msg = $missing_only
            ? __( 'No employees were updated. Either all mapped employees already have a department, or there are no employee records for these users.', 'sfs-hr' )
            : __( 'No employees were updated. It looks like there are no employee records linked to these users.', 'sfs-hr' );

        Helpers::redirect_with_notice(
            $base_url,
            'info',
            $msg
        );
    }

    Helpers::redirect_with_notice(
        $base_url,
        'success',
        sprintf(
            /* translators: %d: number of employees updated */
            __( 'Department membership updated for %d employees.', 'sfs-hr' ),
            $updated
        )
    );
}

private static function parse_role_list( $value ): array {
    if ( is_array( $value ) ) {
        $roles = $value;
    } else {
        $roles = explode( ',', (string) $value );
    }

    $roles = array_map( 'sanitize_key', $roles );
    $roles = array_filter( $roles );
    $roles = array_values( array_unique( $roles ) );

    return $roles;
}

private static function stringify_role_list( $value ): string {
    $roles = self::parse_role_list( $value );
    return implode( ',', $roles );
}



}
