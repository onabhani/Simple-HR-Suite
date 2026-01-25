<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** @var array $rows */

global $wpdb;

$assets_table    = $wpdb->prefix . 'sfs_hr_assets';
$employees_table = $wpdb->prefix . 'sfs_hr_employees';
$dept_table      = $wpdb->prefix . 'sfs_hr_departments';
$assign_table    = $wpdb->prefix . 'sfs_hr_asset_assignments';

$current_user_id = get_current_user_id();

// Can see all employees / depts
$can_manage_all = current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr_assets_admin' );

// ---------- Manager dept scoping ----------
$manager_depts = [];
if ( ! $can_manage_all ) {
    $manager_depts = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id 
             FROM {$dept_table} 
             WHERE manager_user_id = %d
               AND active = 1",
            $current_user_id
        )
    );
    $manager_depts = array_map( 'intval', $manager_depts ?: [] );
}

// ---------- Assets (for dropdown) ----------
$assets = $wpdb->get_results(
    "
    SELECT id, name, asset_code, category, status
    FROM {$assets_table}
    ORDER BY name ASC, id ASC
"
);

// ---------- Active assignments per employee ----------
$active_counts_raw = $wpdb->get_results(
    "
    SELECT employee_id, COUNT(*) AS c
    FROM {$assign_table}
    WHERE status IN ('pending_employee_approval','active','return_requested')
    GROUP BY employee_id
"
);

$active_counts = [];
foreach ( $active_counts_raw as $r ) {
    $active_counts[ (int) $r->employee_id ] = (int) $r->c;
}

// ---------- Employees (for dropdowns + filters) ----------
$where = "WHERE e.status = 'active'";

if ( ! $can_manage_all ) {
    if ( empty( $manager_depts ) ) {
        // Manager has no departments → no employees to assign
        $where .= " AND 1 = 0";
    } else {
        $in = implode( ',', array_map( 'intval', $manager_depts ) );
        $where .= " AND e.dept_id IN ({$in})";
    }
}

$employees_sql = "
    SELECT e.id,
           e.first_name,
           e.last_name,
           e.dept_id,
           d.name AS dept_name
    FROM {$employees_table} e
    LEFT JOIN {$dept_table} d ON d.id = e.dept_id
    {$where}
    ORDER BY e.first_name, e.last_name, e.id
";

$employees = $wpdb->get_results( $employees_sql );

// ---------- Filters (from GET) ----------
$status_filter   = isset( $_GET['asset_status'] ) ? sanitize_key( wp_unslash( $_GET['asset_status'] ) ) : '';
$employee_filter = isset( $_GET['emp'] ) ? (int) $_GET['emp'] : 0;
?>

<h2><?php esc_html_e( 'New Assignment', 'sfs-hr' ); ?></h2>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'sfs_hr_assets_assign' ); ?>
    <input type="hidden" name="action" value="sfs_hr_assets_assign" />

    <table class="form-table">
        <tr>
            <th scope="row">
                <label for="asset_id"><?php esc_html_e( 'Asset', 'sfs-hr' ); ?></label>
            </th>
            <td>
                <select name="asset_id" id="asset_id" required>
                    <option value=""><?php esc_html_e( 'Select asset', 'sfs-hr' ); ?></option>
                    <?php foreach ( $assets as $asset ) : ?>
                        <option value="<?php echo (int) $asset->id; ?>">
                            <?php
                            $parts = [];

                            $parts[] = $asset->name !== ''
                                ? $asset->name
                                : sprintf( '#%d', (int) $asset->id );

                            if ( ! empty( $asset->asset_code ) ) {
                                $parts[] = $asset->asset_code;
                            }

                            if ( ! empty( $asset->category ) ) {
                                $parts[] = $asset->category;
                            }

                            echo esc_html( implode( ' – ', $parts ) );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="employee_id"><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></label>
            </th>
            <td>
                <select name="employee_id" id="employee_id" required>
                    <option value=""><?php esc_html_e( 'Select employee', 'sfs-hr' ); ?></option>
                    <?php foreach ( $employees as $emp ) : ?>
                        <?php
                        $emp_id = (int) $emp->id;

                        $name = trim(
                            ( $emp->first_name ?? '' ) . ' ' . ( $emp->last_name ?? '' )
                        );
                        if ( $name === '' ) {
                            $name = '#' . $emp_id;
                        }

                        $label_parts = [ $name ];

                        if ( ! empty( $emp->dept_name ) ) {
                            $label_parts[] = $emp->dept_name;
                        }

                        $active_count = $active_counts[ $emp_id ] ?? 0;
                        if ( $active_count > 0 ) {
                            $suffix = $active_count === 1
                                ? sprintf( __( '%d active asset', 'sfs-hr' ), $active_count )
                                : sprintf( __( '%d active assets', 'sfs-hr' ), $active_count );
                            $label_parts[] = $suffix;
                        }

                        $label = implode( ' – ', $label_parts );
                        ?>
                        <option value="<?php echo $emp_id; ?>">
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ( empty( $employees ) ) : ?>
                    <p class="description">
                        <?php esc_html_e( 'No employees available for assignment (check departments & status).', 'sfs-hr' ); ?>
                    </p>
                <?php endif; ?>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="start_date"><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></label>
            </th>
            <td>
                <input type="date"
                       name="start_date"
                       id="start_date"
                       required
                       value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="notes"><?php esc_html_e( 'Notes', 'sfs-hr' ); ?></label>
            </th>
            <td>
                <textarea name="notes" id="notes" rows="3" class="large-text"></textarea>
            </td>
        </tr>
    </table>

    <?php submit_button( __( 'Create Assignment (Pending Employee Approval)', 'sfs-hr' ) ); ?>
</form>

<hr />

<h2><?php esc_html_e( 'Assignments List', 'sfs-hr' ); ?></h2>

<form method="get" style="margin-bottom:8px;">
    <input type="hidden" name="page" value="sfs-hr-assets" />
    <input type="hidden" name="tab" value="assignments" />

    <label for="filter_emp"><?php esc_html_e( 'Employee:', 'sfs-hr' ); ?></label>
    <select name="emp" id="filter_emp">
        <option value="0"><?php esc_html_e( 'All employees', 'sfs-hr' ); ?></option>
        <?php foreach ( $employees as $emp ) : ?>
            <?php
            $emp_id = (int) $emp->id;

            $name = trim(
                ( $emp->first_name ?? '' ) . ' ' . ( $emp->last_name ?? '' )
            );
            if ( $name === '' ) {
                $name = '#' . $emp_id;
            }
            ?>
            <option value="<?php echo $emp_id; ?>" <?php selected( $employee_filter, $emp_id ); ?>>
                <?php echo esc_html( $name ); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="filter_status" style="margin-left:10px;"><?php esc_html_e( 'Status:', 'sfs-hr' ); ?></label>
    <select name="asset_status" id="filter_status">
        <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
        <?php
        $statuses = [
            'pending_employee_approval' => __( 'Pending', 'sfs-hr' ),
            'active'                    => __( 'Active', 'sfs-hr' ),
            'return_requested'          => __( 'Return Requested', 'sfs-hr' ),
            'returned'                  => __( 'Returned', 'sfs-hr' ),
            'rejected'                  => __( 'Rejected', 'sfs-hr' ),
        ];

        foreach ( $statuses as $key => $label ) : ?>
            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status_filter, $key ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="button" style="margin-left:6px;">
        <?php esc_html_e( 'Filter', 'sfs-hr' ); ?>
    </button>
</form>

<?php
// Print asset status badge CSS once
\SFS\HR\Core\Helpers::output_asset_status_badge_css();
?>

<div class="sfs-hr-table-responsive">
<table class="widefat striped">
    <thead>
    <tr>
        <th><?php esc_html_e( 'Asset', 'sfs-hr' ); ?></th>
        <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
        <th><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></th>
        <th><?php esc_html_e( 'End Date', 'sfs-hr' ); ?></th>
        <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
        <th><?php esc_html_e( 'Photos', 'sfs-hr' ); ?></th>
        <th><?php esc_html_e( 'Created At', 'sfs-hr' ); ?></th>
        <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
    </tr>
</thead>

    <tbody>
    <?php if ( empty( $rows ) ) : ?>
    <tr>
        <td colspan="8"><?php esc_html_e( 'No assignments found.', 'sfs-hr' ); ?></td>
    </tr>
<?php else : ?>

        <?php foreach ( $rows as $row ) : ?>
    <tr>
        <td>
            <?php
            printf(
                '%s (%s)',
                esc_html( $row->asset_name ),
                esc_html( $row->asset_code )
            );
            ?>
        </td>

        <td>
            <?php
            $employee_id = isset( $row->employee_id ) ? (int) $row->employee_id : 0;

            if ( $employee_id > 0 ) {
                $profile_url = add_query_arg(
                    [
                        'page'        => 'sfs-hr-employee-profile',
                        'employee_id' => $employee_id,
                    ],
                    admin_url( 'admin.php' )
                );

                $label = $row->employee_name ?: ( '#' . $employee_id );

                echo '<a href="' . esc_url( $profile_url ) . '">'
                     . esc_html( $label ) .
                     '</a>';
            } else {
                echo esc_html( $row->employee_name ?: '—' );
            }
            ?>
        </td>

        <td><?php echo esc_html( $row->start_date ); ?></td>
        <td><?php echo esc_html( $row->end_date ?: '—' ); ?></td>

        <td><?php echo \SFS\HR\Core\Helpers::asset_status_badge( (string) $row->status ); ?></td>

                        <!-- Photos column -->
        <td>
            <?php
            // Initial assignment photos (receive)
            $approve_selfie_id = ! empty( $row->selfie_attachment_id ?? 0 )
                ? (int) $row->selfie_attachment_id
                : 0;
            $approve_asset_id  = ! empty( $row->asset_attachment_id ?? 0 )
                ? (int) $row->asset_attachment_id
                : 0;

            // Return photos
            $return_selfie_id  = ! empty( $row->return_selfie_attachment_id ?? 0 )
                ? (int) $row->return_selfie_attachment_id
                : 0;
            $return_asset_id   = ! empty( $row->return_asset_attachment_id ?? 0 )
                ? (int) $row->return_asset_attachment_id
                : 0;

            $has_any = $approve_selfie_id || $approve_asset_id || $return_selfie_id || $return_asset_id;

            if ( ! $has_any ) {
                echo '—';
            } else {
                echo '<div class="sfs-hr-asset-photos-cell" style="display:flex;flex-direction:column;gap:3px;">';

                // ---- Receive row ----
                if ( $approve_selfie_id || $approve_asset_id ) {
                    echo '<div class="sfs-hr-asset-photos-row" style="display:flex;align-items:center;gap:4px;">';
                    echo '<span class="sfs-hr-asset-photos-label" style="font-size:11px;opacity:0.7;min-width:52px;">' .
                         esc_html__( 'Receive', 'sfs-hr' ) . ':</span>';

                    if ( $approve_selfie_id ) {
                        $selfie_full = wp_get_attachment_url( $approve_selfie_id );
                        if ( $selfie_full ) {
                            echo sprintf(
                                '<a href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" class="sfs-hr-asset-photo-thumb sfs-hr-asset-photo-thumb--selfie" style="width:28px;height:28px;border-radius:999px;object-fit:cover;display:inline-block;" alt="%2$s" /></a>',
                                esc_url( $selfie_full ),
                                esc_attr__( 'Selfie (receive)', 'sfs-hr' )
                            );
                        }
                    }

                    if ( $approve_asset_id ) {
                        $asset_full = wp_get_attachment_url( $approve_asset_id );
                        if ( $asset_full ) {
                            echo sprintf(
                                '<a href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" class="sfs-hr-asset-photo-thumb sfs-hr-asset-photo-thumb--asset" style="width:28px;height:28px;border-radius:6px;object-fit:cover;display:inline-block;" alt="%2$s" /></a>',
                                esc_url( $asset_full ),
                                esc_attr__( 'Asset photo (receive)', 'sfs-hr' )
                            );
                        }
                    }

                    echo '</div>'; // .sfs-hr-asset-photos-row
                }

                // ---- Return row ----
                if ( $return_selfie_id || $return_asset_id ) {
                    echo '<div class="sfs-hr-asset-photos-row" style="display:flex;align-items:center;gap:4px;">';
                    echo '<span class="sfs-hr-asset-photos-label" style="font-size:11px;opacity:0.7;min-width:52px;">' .
                         esc_html__( 'Return', 'sfs-hr' ) . ':</span>';

                    if ( $return_selfie_id ) {
                        $return_selfie_full = wp_get_attachment_url( $return_selfie_id );
                        if ( $return_selfie_full ) {
                            echo sprintf(
                                '<a href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" class="sfs-hr-asset-photo-thumb sfs-hr-asset-photo-thumb--selfie" style="width:28px;height:28px;border-radius:999px;object-fit:cover;display:inline-block;" alt="%2$s" /></a>',
                                esc_url( $return_selfie_full ),
                                esc_attr__( 'Selfie (return)', 'sfs-hr' )
                            );
                        }
                    }

                    if ( $return_asset_id ) {
                        $return_asset_full = wp_get_attachment_url( $return_asset_id );
                        if ( $return_asset_full ) {
                            echo sprintf(
                                '<a href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" class="sfs-hr-asset-photo-thumb sfs-hr-asset-photo-thumb--asset" style="width:28px;height:28px;border-radius:6px;object-fit:cover;display:inline-block;" alt="%2$s" /></a>',
                                esc_url( $return_asset_full ),
                                esc_attr__( 'Asset photo (return)', 'sfs-hr' )
                            );
                        }
                    }

                    echo '</div>'; // .sfs-hr-asset-photos-row
                }

                echo '</div>'; // .sfs-hr-asset-photos-cell
            }
            ?>
        </td>
        <!-- end Photos -->



        <td><?php echo esc_html( $row->created_at ); ?></td>

        <td>
            <?php if (
                $row->status === 'active'
                && (
                    current_user_can( 'sfs_hr.manage' )
                    || current_user_can( 'sfs_hr_assets_admin' )
                    || current_user_can( 'sfs_hr_manager' )
                )
            ) : ?>
                <form method="post"
                      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      style="display:inline-block;">
                    <?php wp_nonce_field( 'sfs_hr_assets_return_request_' . (int) $row->id ); ?>
                    <input type="hidden" name="action" value="sfs_hr_assets_return_request" />
                    <input type="hidden" name="assignment_id" value="<?php echo (int) $row->id; ?>" />
                    <button type="submit" class="button">
                        <?php esc_html_e( 'Request Return', 'sfs-hr' ); ?>
                    </button>
                </form>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>

    <?php endif; ?>
    </tbody>
</table>
</div>
