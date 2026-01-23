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
        .sfs-hr-wrap .sfs-hr-section-card {
            margin-top: 16px;
            padding: 16px 20px;
            background: #fff;
            border-radius: 8px;
            border: 1px solid #dcdcde;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
        }
        .sfs-hr-wrap .sfs-hr-section-title {
            margin-top: 0;
            margin-bottom: 12px;
            font-size: 15px;
            font-weight: 600;
        }

        .sfs-hr-wrap .sfs-hr-dept-list {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .sfs-hr-wrap .sfs-hr-dept-card {
            flex: 1 1 260px;
            min-width: 260px;
            max-width: 420px;
            padding: 12px 14px;
            border-radius: 6px;
            border: 1px solid #dcdcde;
            background: #fdfdfd;
        }
        .sfs-hr-wrap .sfs-hr-dept-header {
            font-size: 12px;
            color: #646970;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }
        .sfs-hr-wrap .sfs-hr-dept-id {
            font-weight: 600;
            color: #1d2327;
        }

        .sfs-hr-wrap .sfs-hr-dept-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 8px 12px;
        }
        .sfs-hr-wrap .sfs-hr-field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #50575e;
            margin-bottom: 2px;
        }
        .sfs-hr-wrap .sfs-hr-field input.regular-text,
        .sfs-hr-wrap .sfs-hr-field select {
            width: 100%;
            max-width: 100%;
        }
        .sfs-hr-wrap .sfs-hr-field .sfs-hr-checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 400;
        }

        .sfs-hr-wrap .sfs-hr-dept-footer {
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .sfs-hr-wrap .sfs-hr-dept-footer-left {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .sfs-hr-wrap .sfs-hr-dept-footer-right {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .sfs-hr-wrap .sfs-hr-inline-label {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            color: #50575e;
        }

        /* Add Department card */
        .sfs-hr-wrap .sfs-hr-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px 16px;
        }
        .sfs-hr-wrap .sfs-hr-form-grid .sfs-hr-field input.regular-text,
        .sfs-hr-wrap .sfs-hr-form-grid .sfs-hr-field select {
            width: 100%;
            max-width: 100%;
        }
        .sfs-hr-wrap .sfs-hr-form-actions {
            margin-top: 16px;
        }

        @media (max-width: 782px) {
            .sfs-hr-wrap .sfs-hr-dept-card {
                max-width: 100%;
            }
        }
      </style>

      <!-- Existing departments -->
      <div class="sfs-hr-section-card">
        <h2 class="sfs-hr-section-title"><?php esc_html_e( 'Current Departments', 'sfs-hr' ); ?></h2>

        <?php if ( ! $rows ) : ?>
          <p><?php esc_html_e( 'No departments yet. Add one below.', 'sfs-hr' ); ?></p>
        <?php else : ?>
          <div class="sfs-hr-dept-list">
            <?php foreach ( $rows as $r ) : ?>
              <div class="sfs-hr-dept-card">
                <div class="sfs-hr-dept-header">
                  <span class="sfs-hr-dept-id">
                    <?php
                    printf(
                        esc_html__( 'Dept #%d', 'sfs-hr' ),
                        (int) $r['id']
                    );
                    ?>
                  </span>
                  <?php if ( ! empty( $r['auto_role'] ) ) : ?>
                    <span><?php esc_html_e( 'Auto-membership enabled', 'sfs-hr' ); ?></span>
                  <?php endif; ?>
                </div>

                <!-- SAVE FORM (wraps fields + Save button only) -->
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                  <input type="hidden" name="action" value="sfs_hr_dept_save" />
                  <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_save ); ?>" />
                  <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />

                  <div class="sfs-hr-dept-grid">
                    <div class="sfs-hr-field">
                      <label for="sfs-hr-dept-name-<?php echo (int) $r['id']; ?>">
                        <?php esc_html_e( 'Name', 'sfs-hr' ); ?>
                      </label>
                      <input type="text"
                             id="sfs-hr-dept-name-<?php echo (int) $r['id']; ?>"
                             name="name"
                             value="<?php echo esc_attr( $r['name'] ); ?>"
                             class="regular-text"
                             required />
                    </div>

                    <div class="sfs-hr-field">
                      <label for="sfs-hr-dept-manager-<?php echo (int) $r['id']; ?>">
                        <?php esc_html_e( 'Manager', 'sfs-hr' ); ?>
                      </label>
                      <?php
                      wp_dropdown_users(
                          [
                              'name'             => 'manager_user_id',
                              'id'               => 'sfs-hr-dept-manager-' . (int) $r['id'],
                              'selected'         => (int) $r['manager_user_id'],
                              'show'             => 'display_name',
                              'include_selected' => true,
                              'class'            => 'regular-text',
                              'show_option_none' => __( '— None —', 'sfs-hr' ),
                          ]
                      );
                      ?>
                    </div>

                    <?php
$auto_roles_selected = self::parse_role_list( $r['auto_role'] ?? '' );
?>
<div class="sfs-hr-field">
  <label for="sfs-hr-dept-auto-<?php echo (int) $r['id']; ?>">
    <?php esc_html_e( 'Auto-membership Roles', 'sfs-hr' ); ?>
  </label>
  <select name="auto_role[]" id="sfs-hr-dept-auto-<?php echo (int) $r['id']; ?>" multiple="multiple" size="5">
    <?php foreach ( $roles as $slug => $label ) : ?>
      <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( in_array( $slug, $auto_roles_selected, true ), true ); ?>>
        <?php echo esc_html( $label . ' (' . $slug . ')' ); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <p class="description">
    <?php esc_html_e( 'Hold Ctrl (Windows) or Cmd (Mac) to select multiple roles.', 'sfs-hr' ); ?>
  </p>
</div>


                    <div class="sfs-hr-field">
                      <label for="sfs-hr-dept-approver-<?php echo (int) $r['id']; ?>">
                        <?php esc_html_e( 'Approver Role (fallback)', 'sfs-hr' ); ?>
                      </label>
                      <select name="approver_role" id="sfs-hr-dept-approver-<?php echo (int) $r['id']; ?>">
                        <option value=""><?php esc_html_e( '—', 'sfs-hr' ); ?></option>
                        <?php foreach ( $roles as $slug => $label ) : ?>
                          <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $r['approver_role'], $slug ); ?>>
                            <?php echo esc_html( $label . ' (' . $slug . ')' ); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="sfs-hr-field">
                      <label for="sfs-hr-dept-color-<?php echo (int) $r['id']; ?>">
                        <?php esc_html_e( 'Card Color', 'sfs-hr' ); ?>
                      </label>
                      <input type="color"
                             id="sfs-hr-dept-color-<?php echo (int) $r['id']; ?>"
                             name="color"
                             value="<?php echo esc_attr( ! empty( $r['color'] ) ? $r['color'] : '#1e3a5f' ); ?>"
                             style="width:50px;height:30px;padding:0;border:1px solid #dcdcde;cursor:pointer;" />
                    </div>

                    <div class="sfs-hr-field">
                      <label>
                        <?php esc_html_e( 'Status', 'sfs-hr' ); ?>
                      </label>
                      <label class="sfs-hr-checkbox-label">
                        <input type="checkbox" name="active" value="1" <?php checked( (int) $r['active'], 1 ); ?> />
                        <?php esc_html_e( 'Active', 'sfs-hr' ); ?>
                      </label>
                    </div>
                  </div><!-- .sfs-hr-dept-grid -->

                  <div class="sfs-hr-dept-footer">
                    <div class="sfs-hr-dept-footer-left">
                      <button type="submit" class="button button-primary button-small">
                        <?php esc_html_e( 'Save', 'sfs-hr' ); ?>
                      </button>
                    </div>
                  </div>
                </form><!-- END SAVE FORM -->

                <!-- Delete + Sync forms (separate, no nesting) -->
                <div class="sfs-hr-dept-footer">
                  <div class="sfs-hr-dept-footer-right">
                    <form method="post"
                          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          onsubmit="return confirm('<?php echo esc_attr__( 'Delete this department?', 'sfs-hr' ); ?>');">
                      <input type="hidden" name="action" value="sfs_hr_dept_del" />
                      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_del ); ?>" />
                      <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />
                      <button class="button button-small" type="submit">
                        <?php esc_html_e( 'Delete', 'sfs-hr' ); ?>
                      </button>
                    </form>

                    <?php if ( ! empty( $r['auto_role'] ) ) : ?>
                      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sfs_hr_dept_sync" />
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_sync ); ?>" />
                        <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />
                        <label class="sfs-hr-inline-label">
                          <input type="checkbox" name="missing_only" value="1" checked />
                          <?php esc_html_e( 'Missing only', 'sfs-hr' ); ?>
                        </label>
                        <button class="button button-small" type="submit">
                          <?php esc_html_e( 'Sync membership', 'sfs-hr' ); ?>
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>

              </div><!-- .sfs-hr-dept-card -->
            <?php endforeach; ?>
          </div><!-- .sfs-hr-dept-list -->
        <?php endif; ?>
      </div><!-- .sfs-hr-section-card -->

      <!-- Add Department -->
      <h2 style="margin-top:24px;"><?php esc_html_e( 'Add Department', 'sfs-hr' ); ?></h2>
      <div class="sfs-hr-section-card">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
          <input type="hidden" name="action" value="sfs_hr_dept_save" />
          <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_save ); ?>" />

          <div class="sfs-hr-form-grid">
            <div class="sfs-hr-field">
              <label for="sfs-hr-dept-new-name">
                <?php esc_html_e( 'Name', 'sfs-hr' ); ?>
              </label>
              <input type="text"
                     id="sfs-hr-dept-new-name"
                     name="name"
                     class="regular-text"
                     required />
            </div>

            <div class="sfs-hr-field">
              <label for="sfs-hr-dept-new-manager">
                <?php esc_html_e( 'Manager', 'sfs-hr' ); ?>
              </label>
              <?php
              wp_dropdown_users(
                  [
                      'name'             => 'manager_user_id',
                      'id'               => 'sfs-hr-dept-new-manager',
                      'selected'         => 0,
                      'show'             => 'display_name',
                      'include_selected' => true,
                      'show_option_none' => __( '— None —', 'sfs-hr' ),
                  ]
              );
              ?>
            </div>

            <div class="sfs-hr-field">
  <label for="sfs-hr-dept-new-auto">
    <?php esc_html_e( 'Auto-membership Roles', 'sfs-hr' ); ?>
  </label>
  <select name="auto_role[]" id="sfs-hr-dept-new-auto" multiple="multiple" size="5">
    <?php foreach ( $roles as $slug => $label ) : ?>
      <option value="<?php echo esc_attr( $slug ); ?>">
        <?php echo esc_html( $label . ' (' . $slug . ')' ); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <p class="description">
    <?php esc_html_e( 'Hold Ctrl (Windows) or Cmd (Mac) to select multiple roles.', 'sfs-hr' ); ?>
  </p>
</div>


            <div class="sfs-hr-field">
              <label for="sfs-hr-dept-new-approver">
                <?php esc_html_e( 'Approver Role (fallback)', 'sfs-hr' ); ?>
              </label>
              <select name="approver_role" id="sfs-hr-dept-new-approver">
                <option value=""><?php esc_html_e( '—', 'sfs-hr' ); ?></option>
                <?php foreach ( $roles as $slug => $label ) : ?>
                  <option value="<?php echo esc_attr( $slug ); ?>">
                    <?php echo esc_html( $label . ' (' . $slug . ')' ); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="sfs-hr-field">
              <label for="sfs-hr-dept-new-color">
                <?php esc_html_e( 'Card Color', 'sfs-hr' ); ?>
              </label>
              <input type="color"
                     id="sfs-hr-dept-new-color"
                     name="color"
                     value="#1e3a5f"
                     style="width:50px;height:30px;padding:0;border:1px solid #dcdcde;cursor:pointer;" />
            </div>

            <div class="sfs-hr-field">
              <label>
                <?php esc_html_e( 'Status', 'sfs-hr' ); ?>
              </label>
              <label class="sfs-hr-checkbox-label">
                <input type="checkbox" name="active" value="1" checked />
                <?php esc_html_e( 'Active', 'sfs-hr' ); ?>
              </label>
            </div>
          </div><!-- .sfs-hr-form-grid -->
 
          <div class="sfs-hr-form-actions">
            <?php submit_button( __( 'Add Department', 'sfs-hr' ), 'primary', '', false ); ?>
          </div>
        </form>
      </div><!-- .sfs-hr-section-card -->
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
