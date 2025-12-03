<?php
namespace SFS\HR\Modules\Assets;

if ( ! defined('ABSPATH') ) { exit; }

// Load submodules at file scope (NOT inside the class!)
require_once __DIR__ . '/Admin/class-admin-pages.php';
require_once __DIR__ . '/Rest/class-assets-rest.php';

use SFS\HR\Modules\Assets\Admin\Admin_Pages;
use SFS\HR\Modules\Assets\Rest\Assets_REST;

class AssetsModule {

    const VERSION        = '0.1.9-assets-mvp';
    const OPT_DB_VERSION = 'sfs_hr_assets_db_version';

    public function hooks(): void {
        // Admin pages
        (new Admin_Pages())->hooks();

        // REST
        (new Assets_REST())->hooks();

        // DB
        add_action('plugins_loaded', [ $this, 'maybe_upgrade_db' ]);

        // Employee profile integration
        add_action('sfs_hr_employee_tabs',        [ $this, 'employee_tab' ], 20, 1);
        add_action('sfs_hr_employee_tab_content', [ $this, 'employee_tab_content' ], 20, 2);
    }



    public function maybe_upgrade_db(): void {
    global $wpdb;

    $current = get_option( self::OPT_DB_VERSION );

    $charset_collate = $wpdb->get_charset_collate();
    $assets_table    = $wpdb->prefix . 'sfs_hr_assets';
    $assign_table    = $wpdb->prefix . 'sfs_hr_asset_assignments';

    // Check if tables exist
    $assets_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $assets_table )
    );
    $assign_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $assign_table )
    );

    $needs_upgrade = false;

    // 1) Version changed → upgrade
    if ( $current !== self::VERSION ) {
        $needs_upgrade = true;
    }

    // 2) Table missing → upgrade no matter what
    if ( $assets_exists !== $assets_table || $assign_exists !== $assign_table ) {
        $needs_upgrade = true;
    }

    // 3) Column sanity check for assignments table
    if ( $assign_exists === $assign_table && ! $needs_upgrade ) {
        $expected_cols = [
            'selfie_attachment_id',
            'asset_attachment_id',
            'return_selfie_attachment_id',
            'return_asset_attachment_id',
        ];

        foreach ( $expected_cols as $col ) {
            $has_col = $wpdb->get_var(
                $wpdb->prepare(
                    "SHOW COLUMNS FROM {$assign_table} LIKE %s",
                    $col
                )
            );

            if ( ! $has_col ) {
                $needs_upgrade = true;
                break;
            }
        }
    }

    if ( ! $needs_upgrade ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql_assets = "CREATE TABLE {$assets_table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        asset_code VARCHAR(50) NOT NULL,
        name VARCHAR(255) NOT NULL,
        category VARCHAR(50) NOT NULL,
        department VARCHAR(100) DEFAULT '',
        serial_number VARCHAR(100) DEFAULT '',
        model VARCHAR(150) DEFAULT '',
        purchase_year SMALLINT(4) DEFAULT NULL,
        purchase_price DECIMAL(10,2) DEFAULT NULL,
        warranty_expiry DATE DEFAULT NULL,
        invoice_number VARCHAR(100) DEFAULT '',
        invoice_date DATE DEFAULT NULL,
        invoice_file VARCHAR(255) DEFAULT '',
        qr_code_path VARCHAR(255) DEFAULT '',
        status ENUM('available','assigned','under_approval','returned','archived') DEFAULT 'available',
        condition ENUM('new','good','damaged','needs_repair','lost') DEFAULT 'good',
        notes TEXT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY asset_code (asset_code),
        KEY category (category),
        KEY status (status),
        KEY department (department)
    ) {$charset_collate};";

    $sql_assign = "CREATE TABLE {$assign_table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        asset_id BIGINT(20) UNSIGNED NOT NULL,
        employee_id BIGINT(20) UNSIGNED NOT NULL,
        assigned_by BIGINT(20) UNSIGNED NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE DEFAULT NULL,
        status ENUM(
            'pending_employee_approval',
            'active',
            'return_requested',
            'returned',
            'rejected'
        ) NOT NULL DEFAULT 'pending_employee_approval',
        return_requested_by BIGINT(20) UNSIGNED DEFAULT NULL,
        return_date DATE DEFAULT NULL,

        -- Photos captured on initial approval
        selfie_attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
        asset_attachment_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,

        -- Photos captured on return confirmation
        return_selfie_attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
        return_asset_attachment_id  BIGINT(20) UNSIGNED NULL DEFAULT NULL,

        notes TEXT,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY asset_id (asset_id),
        KEY employee_id (employee_id),
        KEY status (status)
    ) {$charset_collate};";

    dbDelta( $sql_assets );
    dbDelta( $sql_assign );

    update_option( self::OPT_DB_VERSION, self::VERSION );
}



    /**
     * Add "Assets" tab in employee profile.
     */
    public function employee_tab( $employee ): void {
        // Only managers or the employee himself
        if (
            ! current_user_can('sfs_hr_manager')
            && ! current_user_can('sfs_hr_assets_admin')
            && get_current_user_id() !== (int) $employee->user_id
        ) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        $classes    = 'nav-tab';
        if ( $active_tab === 'assets' ) {
            $classes .= ' nav-tab-active';
        }

        $url = add_query_arg('tab', 'assets');

        echo '<a href="' . esc_url($url) . '" class="' . esc_attr($classes) . '">'
            . esc_html__('Assets', 'sfs-hr')
            . '</a>';
    }

    /**
     * Render assets tab content (active + history + approvals).
     */
    public function employee_tab_content( $employee, string $active_tab ): void {
    if ( $active_tab !== 'assets' ) {
        return;
    }

    // Only manager / assets admin / the employee himself can see this
    if (
        ! current_user_can( 'sfs_hr_manager' )
        && ! current_user_can( 'sfs_hr_assets_admin' )
        && get_current_user_id() !== (int) $employee->user_id
    ) {
        wp_die( __( 'Access denied', 'sfs-hr' ) );
    }

    global $wpdb;
    $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $asset_table  = $wpdb->prefix . 'sfs_hr_assets';

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT a.*,
                   ast.name       AS asset_name,
                   ast.asset_code AS asset_code,
                   ast.category   AS category
            FROM {$assign_table} a
            LEFT JOIN {$asset_table} ast ON ast.id = a.asset_id
            WHERE a.employee_id = %d
            ORDER BY a.created_at DESC
            LIMIT 200
            ",
            (int) $employee->id
        )
    );
    ?>
    <div class="wrap">
        <h2><?php echo esc_html__( 'Employee Assets', 'sfs-hr' ); ?></h2>

        <?php if ( empty( $rows ) ) : ?>
            <p><?php echo esc_html__( 'No assets found for this employee.', 'sfs-hr' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Asset', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Category', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Code', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'End Date', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row->asset_name ?? '' ); ?></td>
                        <td><?php echo esc_html( $row->category   ?? '' ); ?></td>
                        <td><?php echo esc_html( $row->asset_code ?? '' ); ?></td>
                        <td><?php echo esc_html( $row->start_date ?: '' ); ?></td>
                        <td><?php echo esc_html( $row->end_date   ?: '—' ); ?></td>
                        <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?></td>
                        <td>
                            <?php
                            $current_user_id = get_current_user_id();

                            // Only the employee himself gets the buttons
                            if ( (int) $employee->user_id === $current_user_id ) {

                                // 1) New assignment approval
                                if ( $row->status === 'pending_employee_approval' ) : ?>
                                    <form method="post"
                                          style="display:inline-block;"
                                          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <?php wp_nonce_field( 'sfs_hr_assets_assign_decision_' . (int) $row->id ); ?>
                                        <input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />
                                        <input type="hidden" name="assignment_id" value="<?php echo (int) $row->id; ?>" />
                                        <input type="hidden" name="decision" value="approve" />
                                        <button type="submit" class="button button-primary">
                                            <?php esc_html_e( 'Approve', 'sfs-hr' ); ?>
                                        </button>
                                    </form>

                                    <form method="post"
                                          style="display:inline-block;margin-left:4px;"
                                          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <?php wp_nonce_field( 'sfs_hr_assets_assign_decision_' . (int) $row->id ); ?>
                                        <input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />
                                        <input type="hidden" name="assignment_id" value="<?php echo (int) $row->id; ?>" />
                                        <input type="hidden" name="decision" value="reject" />
                                        <button type="submit" class="button">
                                            <?php esc_html_e( 'Reject', 'sfs-hr' ); ?>
                                        </button>
                                    </form>
                                <?php
                                endif;

                                // 2) Return request approval (employee confirms the return)
                                if ( $row->status === 'return_requested' ) : ?>
                                    <form method="post"
                                          style="display:inline-block;"
                                          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <?php wp_nonce_field( 'sfs_hr_assets_return_decision_' . (int) $row->id ); ?>
                                        <input type="hidden" name="action" value="sfs_hr_assets_return_decision" />
                                        <input type="hidden" name="assignment_id" value="<?php echo (int) $row->id; ?>" />
                                        <input type="hidden" name="decision" value="approve" />
                                        <button type="submit" class="button button-primary">
                                            <?php esc_html_e( 'Confirm Return', 'sfs-hr' ); ?>
                                        </button>
                                    </form>
                                <?php
                                endif;
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

}
