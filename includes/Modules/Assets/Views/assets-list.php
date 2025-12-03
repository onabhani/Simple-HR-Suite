<?php
if ( ! defined('ABSPATH') ) { exit; }
/** @var array $rows */

use SFS\HR\Core\Helpers;

// --------- Build simple filter options from current rows ---------
$all_statuses   = [];
$all_categories = [];
$all_departments= [];

foreach ( $rows as $r ) {
    if ( ! empty( $r->status ) ) {
        $all_statuses[ $r->status ] = $r->status;
    }
    if ( ! empty( $r->category ) ) {
        $all_categories[ $r->category ] = $r->category;
    }
    if ( ! empty( $r->department ) ) {
        $all_departments[ $r->department ] = $r->department;
    }
}

// Current filters from GET
$status_filter   = isset( $_GET['status'] )   ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$category_filter = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';
$dept_filter     = isset( $_GET['dept'] )     ? sanitize_text_field( wp_unslash( $_GET['dept'] ) ) : '';

// Apply filters in PHP (so you don’t have to touch the query yet)
$display_rows = [];
foreach ( $rows as $r ) {
    if ( $status_filter !== '' && (string) $r->status !== $status_filter ) {
        continue;
    }
    if ( $category_filter !== '' && (string) $r->category !== $category_filter ) {
        continue;
    }
    if ( $dept_filter !== '' && (string) $r->department !== $dept_filter ) {
        continue;
    }
    $display_rows[] = $r;
}

/**
 * Prefetch current assignees for displayed assets.
 *
 * - current = latest assignment per asset where status in (pending_employee_approval, active, return_requested)
 * - result: [ asset_id => (object){ employee_id, first_name, last_name } ]
 */
$assignees = [];

if ( ! empty( $display_rows ) ) {
    global $wpdb;

    $asset_ids = [];
    foreach ( $display_rows as $r ) {
        $asset_ids[] = (int) $r->id;
    }
    $asset_ids = array_values( array_unique( array_filter( $asset_ids ) ) );

    if ( $asset_ids ) {
        $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
        $emp_table    = $wpdb->prefix . 'sfs_hr_employees';

        // Build IN clause safely
        $placeholders = implode( ',', array_fill( 0, count( $asset_ids ), '%d' ) );
        $sql          = "
            SELECT aa.asset_id,
                   aa.employee_id,
                   aa.status,
                   e.first_name,
                   e.last_name
            FROM {$assign_table} aa
            JOIN {$emp_table} e ON e.id = aa.employee_id
            WHERE aa.asset_id IN ($placeholders)
              AND aa.status IN ('pending_employee_approval','active','return_requested')
            ORDER BY aa.id DESC
        ";

        $query_args = $asset_ids;
        $rows_ass   = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ) );

        if ( $rows_ass ) {
            foreach ( $rows_ass as $row ) {
                $asset_id = (int) $row->asset_id;
                if ( isset( $assignees[ $asset_id ] ) ) {
                    // already have the latest for this asset (ordered desc), skip
                    continue;
                }
                $assignees[ $asset_id ] = (object) [
                    'employee_id' => (int) $row->employee_id,
                    'first_name'  => (string) ( $row->first_name ?? '' ),
                    'last_name'   => (string) ( $row->last_name  ?? '' ),
                ];
            }
        }
    }
}
?>

<div style="margin-top:20px;">

    <p>
        <a href="<?php echo esc_url( add_query_arg( ['view' => 'edit', 'id' => 0] ) ); ?>"
           class="button button-primary">
            <?php esc_html_e('Add New Asset', 'sfs-hr'); ?>
        </a>
    </p>

    <h3><?php esc_html_e('Export / Import', 'sfs-hr'); ?></h3>

    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;margin-right:10px;">
        <?php wp_nonce_field('sfs_hr_assets_export'); ?>
        <input type="hidden" name="action" value="sfs_hr_assets_export" />
        <?php submit_button( __('Export CSV', 'sfs-hr'), 'secondary', '', false ); ?>
    </form>

    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-block;">
        <?php wp_nonce_field('sfs_hr_assets_import'); ?>
        <input type="hidden" name="action" value="sfs_hr_assets_import" />
        <input type="file" name="import_file" accept=".csv" />
        <?php submit_button( __('Import CSV', 'sfs-hr'), 'secondary', '', false ); ?>
    </form>

    <hr />

    <!-- ===== Filters (Status / Category / Department) ===== -->
    <form method="get" style="margin-bottom:10px;">
        <?php
        // Preserve page / tab if set
        if ( isset( $_GET['page'] ) ) {
            echo '<input type="hidden" name="page" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) . '" />';
        } else {
            echo '<input type="hidden" name="page" value="sfs-hr-assets" />';
        }
        if ( isset( $_GET['tab'] ) ) {
            echo '<input type="hidden" name="tab" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['tab'] ) ) ) . '" />';
        }
        ?>

        <label for="sfs-asset-status-filter"><?php esc_html_e( 'Status:', 'sfs-hr' ); ?></label>
        <select name="status" id="sfs-asset-status-filter">
            <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
            <?php foreach ( $all_statuses as $status_key => $raw ): ?>
                <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_filter, $status_key ); ?>>
                    <?php echo esc_html( ucfirst( str_replace( '_', ' ', $status_key ) ) ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="sfs-asset-category-filter" style="margin-left:8px;"><?php esc_html_e( 'Category:', 'sfs-hr' ); ?></label>
        <select name="category" id="sfs-asset-category-filter">
            <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
            <?php foreach ( $all_categories as $cat ): ?>
                <option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $category_filter, $cat ); ?>>
                    <?php echo esc_html( $cat ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="sfs-asset-dept-filter" style="margin-left:8px;"><?php esc_html_e( 'Department:', 'sfs-hr' ); ?></label>
        <select name="dept" id="sfs-asset-dept-filter">
            <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
            <?php foreach ( $all_departments as $dept ): ?>
                <option value="<?php echo esc_attr( $dept ); ?>" <?php selected( $dept_filter, $dept ); ?>>
                    <?php echo esc_html( $dept ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="button" style="margin-left:6px;">
            <?php esc_html_e( 'Filter', 'sfs-hr' ); ?>
        </button>
    </form>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Code', 'sfs-hr'); ?></th>
                <th><?php esc_html_e('Name', 'sfs-hr'); ?></th>
                <th><?php esc_html_e('Category', 'sfs-hr'); ?></th>
                <th><?php esc_html_e('Department', 'sfs-hr'); ?></th>
                <th><?php esc_html_e('Assignee', 'sfs-hr'); ?></th>
                <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                <th><?php esc_html_e('Condition', 'sfs-hr'); ?></th>
                <th><?php esc_html_e('Created At', 'sfs-hr'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $display_rows ) ) : ?>
            <tr>
                <td colspan="8"><?php esc_html_e('No assets found.', 'sfs-hr'); ?></td>
            </tr>
        <?php else : ?>
            <?php foreach ( $display_rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row->asset_code ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( ['view' => 'edit', 'id' => (int) $row->id] ) ); ?>">
                            <?php echo esc_html( $row->name ); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html( $row->category ); ?></td>
                    <td><?php echo esc_html( $row->department ); ?></td>
                    <td>
                        <?php
                        $assignee = $assignees[ (int) $row->id ] ?? null;
                        if ( $assignee ) {
                            $full_name = trim( $assignee->first_name . ' ' . $assignee->last_name );
                            if ( $full_name === '' ) {
                                $full_name = '#' . (int) $assignee->employee_id;
                            }

                            $profile_url = add_query_arg(
                                [
                                    'page'        => 'sfs-hr-employee-profile',
                                    'employee_id' => (int) $assignee->employee_id,
                                ],
                                admin_url( 'admin.php' )
                            );

                            echo '<a href="' . esc_url( $profile_url ) . '">'
                                 . esc_html( $full_name ) .
                                 '</a>';
                        } else {
                            echo '&mdash;';
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        // Centralized status badge
                        if ( method_exists( Helpers::class, 'asset_status_badge' ) ) {
                            echo Helpers::asset_status_badge( (string) $row->status );
                        } else {
                            echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) );
                        }
                        ?>
                    </td>
                    <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->condition ) ) ); ?></td>
                    <td><?php echo esc_html( $row->created_at ); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
