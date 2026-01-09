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

// Apply filters in PHP (so you don't have to touch the query yet)
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

$total_count = count( $display_rows );
?>

<style>
/* Assets Page Styles */
.sfs-hr-assets-toolbar {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 6px;
    padding: 16px;
    margin: 16px 0;
}
.sfs-hr-assets-toolbar-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}
.sfs-hr-assets-toolbar-row:last-child {
    margin-bottom: 0;
}
.sfs-hr-assets-toolbar-row.search-row {
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e5e5;
}
.sfs-hr-assets-toolbar select {
    height: 36px;
    border-radius: 4px;
    min-width: 120px;
}
.sfs-hr-assets-toolbar .button {
    height: 36px;
    line-height: 34px;
}
.sfs-hr-assets-toolbar-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
.sfs-hr-assets-toolbar-divider {
    width: 1px;
    height: 24px;
    background: #dcdcde;
    margin: 0 4px;
}
.sfs-hr-assets-toolbar label {
    font-size: 13px;
    font-weight: 500;
    color: #50575e;
}

/* Assets table styles */
.sfs-hr-assets-table {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 6px;
    margin-top: 16px;
}
.sfs-hr-assets-table .widefat {
    border: none;
    border-radius: 6px;
    margin: 0;
}
.sfs-hr-assets-table .widefat th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #50575e;
    padding: 12px 16px;
}
.sfs-hr-assets-table .widefat td {
    padding: 12px 16px;
    vertical-align: middle;
}
.sfs-hr-assets-table .widefat tbody tr:hover {
    background: #f8f9fa;
}
.sfs-hr-assets-table .asset-name {
    font-weight: 500;
    color: #1d2327;
    text-decoration: none;
}
.sfs-hr-assets-table .asset-name:hover {
    color: #2271b1;
}
.sfs-hr-assets-table .asset-code {
    font-family: monospace;
    font-size: 11px;
    color: #50575e;
    display: block;
    margin-top: 2px;
}

/* Mobile details button */
.sfs-hr-asset-mobile-btn {
    display: none;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #2271b1;
    color: #fff;
    border: none;
    cursor: pointer;
    font-size: 16px;
    padding: 0;
    align-items: center;
    justify-content: center;
}
.sfs-hr-asset-mobile-btn:hover {
    background: #135e96;
}

/* Asset Modal */
.sfs-hr-asset-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 100000;
    background: rgba(0,0,0,0.5);
    align-items: flex-end;
    justify-content: center;
}
.sfs-hr-asset-modal.active {
    display: flex;
}
.sfs-hr-asset-modal-content {
    background: #fff;
    width: 100%;
    max-width: 400px;
    border-radius: 16px 16px 0 0;
    padding: 20px;
    animation: sfsAssetSlideUp 0.2s ease-out;
    max-height: 80vh;
    overflow-y: auto;
}
@keyframes sfsAssetSlideUp {
    from { transform: translateY(100%); }
    to { transform: translateY(0); }
}
.sfs-hr-asset-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e5e5;
}
.sfs-hr-asset-modal-title {
    font-size: 18px;
    font-weight: 600;
    color: #1d2327;
    margin: 0;
}
.sfs-hr-asset-modal-close {
    background: #f6f7f7;
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.sfs-hr-asset-details-list {
    list-style: none;
    margin: 0 0 16px;
    padding: 0;
}
.sfs-hr-asset-details-list li {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f1;
}
.sfs-hr-asset-details-list li:last-child {
    border-bottom: none;
}
.sfs-hr-asset-label {
    font-weight: 500;
    color: #50575e;
    font-size: 13px;
}
.sfs-hr-asset-value {
    color: #1d2327;
    font-size: 13px;
    text-align: right;
}
.sfs-hr-asset-modal-buttons {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.sfs-hr-asset-modal-buttons .button {
    width: 100%;
    padding: 12px 20px;
    font-size: 14px;
    border-radius: 8px;
    text-align: center;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

/* Status badges */
.sfs-hr-asset-status {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.sfs-hr-asset-status.status-available { background: #d1fae5; color: #065f46; }
.sfs-hr-asset-status.status-assigned { background: #dbeafe; color: #1e40af; }
.sfs-hr-asset-status.status-maintenance { background: #fef3c7; color: #92400e; }
.sfs-hr-asset-status.status-retired { background: #f3f4f6; color: #6b7280; }
.sfs-hr-asset-status.status-lost { background: #fee2e2; color: #991b1b; }
.sfs-hr-asset-status.status-in_use { background: #dbeafe; color: #1e40af; }

/* Action Button */
.sfs-hr-action-btn {
    background: #f6f7f7;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    transition: all 0.15s ease;
}
.sfs-hr-action-btn:hover {
    background: #fff;
    border-color: #2271b1;
}

/* Mobile responsive */
@media (max-width: 782px) {
    .sfs-hr-assets-toolbar {
        padding: 12px;
    }
    .sfs-hr-assets-toolbar-row {
        flex-direction: column;
        align-items: stretch;
    }
    .sfs-hr-assets-toolbar select {
        width: 100%;
    }
    .sfs-hr-assets-toolbar .button {
        width: 100%;
        text-align: center;
    }
    .sfs-hr-assets-toolbar-group {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
    }
    .sfs-hr-assets-toolbar-divider {
        display: none;
    }
    .sfs-hr-assets-toolbar label {
        margin-bottom: 4px;
    }

    /* Hide columns on mobile */
    .hide-mobile {
        display: none !important;
    }

    /* Show mobile-only columns */
    .show-mobile {
        display: table-cell !important;
    }

    .sfs-hr-assets-table .widefat th,
    .sfs-hr-assets-table .widefat td {
        padding: 12px;
    }

    /* Mobile button always visible on mobile */
    .sfs-hr-asset-mobile-btn {
        display: inline-flex;
    }
}
@media (min-width: 783px) {
    .show-mobile {
        display: none !important;
    }
}
</style>

<!-- Asset Modal -->
<div class="sfs-hr-asset-modal" id="sfs-hr-asset-modal">
    <div class="sfs-hr-asset-modal-content">
        <div class="sfs-hr-asset-modal-header">
            <h3 class="sfs-hr-asset-modal-title" id="sfs-hr-asset-modal-name">Asset Details</h3>
            <button type="button" class="sfs-hr-asset-modal-close" onclick="sfsHrCloseAssetModal()">&times;</button>
        </div>
        <ul class="sfs-hr-asset-details-list">
            <li><span class="sfs-hr-asset-label"><?php esc_html_e('Code', 'sfs-hr'); ?></span><span class="sfs-hr-asset-value" id="sfs-hr-asset-code"></span></li>
            <li><span class="sfs-hr-asset-label"><?php esc_html_e('Category', 'sfs-hr'); ?></span><span class="sfs-hr-asset-value" id="sfs-hr-asset-category"></span></li>
            <li><span class="sfs-hr-asset-label"><?php esc_html_e('Department', 'sfs-hr'); ?></span><span class="sfs-hr-asset-value" id="sfs-hr-asset-dept"></span></li>
            <li><span class="sfs-hr-asset-label"><?php esc_html_e('Assignee', 'sfs-hr'); ?></span><span class="sfs-hr-asset-value" id="sfs-hr-asset-assignee"></span></li>
            <li><span class="sfs-hr-asset-label"><?php esc_html_e('Status', 'sfs-hr'); ?></span><span class="sfs-hr-asset-value" id="sfs-hr-asset-status"></span></li>
            <li><span class="sfs-hr-asset-label"><?php esc_html_e('Condition', 'sfs-hr'); ?></span><span class="sfs-hr-asset-value" id="sfs-hr-asset-condition"></span></li>
        </ul>
        <div class="sfs-hr-asset-modal-buttons">
            <a href="#" class="button button-primary" id="sfs-hr-asset-edit-btn">
                <span class="dashicons dashicons-edit"></span> <?php esc_html_e('View / Edit Asset', 'sfs-hr'); ?>
            </a>
        </div>
    </div>
</div>

<script>
function sfsHrOpenAssetModal(name, code, category, dept, assignee, status, condition, editUrl) {
    document.getElementById('sfs-hr-asset-modal-name').textContent = name || 'Asset Details';
    document.getElementById('sfs-hr-asset-code').textContent = code;
    document.getElementById('sfs-hr-asset-category').textContent = category || '-';
    document.getElementById('sfs-hr-asset-dept').textContent = dept || '-';
    document.getElementById('sfs-hr-asset-assignee').textContent = assignee || '-';
    document.getElementById('sfs-hr-asset-status').textContent = status;
    document.getElementById('sfs-hr-asset-condition').textContent = condition;
    document.getElementById('sfs-hr-asset-edit-btn').href = editUrl;
    document.getElementById('sfs-hr-asset-modal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function sfsHrCloseAssetModal() {
    document.getElementById('sfs-hr-asset-modal').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('sfs-hr-asset-modal').addEventListener('click', function(e) {
    if (e.target === this) sfsHrCloseAssetModal();
});
</script>

<?php
// Show success/error notices
if ( isset( $_GET['import'] ) && $_GET['import'] === '1' ) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'CSV import completed successfully.', 'sfs-hr' ) . '</p></div>';
}
if ( isset( $_GET['saved'] ) && $_GET['saved'] === '1' ) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Asset saved successfully.', 'sfs-hr' ) . '</p></div>';
}
if ( isset( $_GET['import_error'] ) ) {
    $error_code = sanitize_key( $_GET['import_error'] );
    $error_messages = [
        'no_file'       => __( 'Please select a CSV file to import.', 'sfs-hr' ),
        'read_error'    => __( 'Unable to read the uploaded file.', 'sfs-hr' ),
        'process_error' => __( 'Unable to process the uploaded file.', 'sfs-hr' ),
        'empty_csv'     => __( 'The CSV file is empty or invalid.', 'sfs-hr' ),
    ];
    $error_msg = $error_messages[ $error_code ] ?? __( 'An error occurred during import.', 'sfs-hr' );
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error_msg ) . '</p></div>';
}
?>

<div style="margin-top:20px;">

    <div class="sfs-hr-assets-toolbar">
        <!-- Filter Row -->
        <form method="get" class="sfs-hr-assets-toolbar-row search-row">
            <?php
            if ( isset( $_GET['page'] ) ) {
                echo '<input type="hidden" name="page" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) . '" />';
            } else {
                echo '<input type="hidden" name="page" value="sfs-hr-assets" />';
            }
            if ( isset( $_GET['tab'] ) ) {
                echo '<input type="hidden" name="tab" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['tab'] ) ) ) . '" />';
            }
            ?>
            <div class="sfs-hr-assets-toolbar-group">
                <label for="sfs-asset-status-filter"><?php esc_html_e( 'Status', 'sfs-hr' ); ?></label>
                <select name="status" id="sfs-asset-status-filter">
                    <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
                    <?php foreach ( $all_statuses as $status_key => $raw ): ?>
                        <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_filter, $status_key ); ?>>
                            <?php echo esc_html( ucfirst( str_replace( '_', ' ', $status_key ) ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sfs-hr-assets-toolbar-group">
                <label for="sfs-asset-category-filter"><?php esc_html_e( 'Category', 'sfs-hr' ); ?></label>
                <select name="category" id="sfs-asset-category-filter">
                    <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
                    <?php foreach ( $all_categories as $cat ): ?>
                        <option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $category_filter, $cat ); ?>>
                            <?php echo esc_html( $cat ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sfs-hr-assets-toolbar-group">
                <label for="sfs-asset-dept-filter"><?php esc_html_e( 'Department', 'sfs-hr' ); ?></label>
                <select name="dept" id="sfs-asset-dept-filter">
                    <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
                    <?php foreach ( $all_departments as $dept ): ?>
                        <option value="<?php echo esc_attr( $dept ); ?>" <?php selected( $dept_filter, $dept ); ?>>
                            <?php echo esc_html( $dept ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="button button-primary">
                <?php esc_html_e( 'Filter', 'sfs-hr' ); ?>
            </button>
        </form>

        <!-- Actions Row -->
        <div class="sfs-hr-assets-toolbar-row">
            <div class="sfs-hr-assets-toolbar-group">
                <a href="<?php echo esc_url( add_query_arg( ['view' => 'edit', 'id' => 0] ) ); ?>"
                   class="button button-primary">
                    <?php esc_html_e('Add New Asset', 'sfs-hr'); ?>
                </a>
            </div>

            <div class="sfs-hr-assets-toolbar-divider"></div>

            <div class="sfs-hr-assets-toolbar-group">
                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-flex;">
                    <?php wp_nonce_field('sfs_hr_assets_export'); ?>
                    <input type="hidden" name="action" value="sfs_hr_assets_export" />
                    <button type="submit" class="button"><?php esc_html_e('Export CSV', 'sfs-hr'); ?></button>
                </form>

                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline-flex;align-items:center;gap:8px;">
                    <?php wp_nonce_field('sfs_hr_assets_import'); ?>
                    <input type="hidden" name="action" value="sfs_hr_assets_import" />
                    <input type="file" name="import_file" accept=".csv" required style="max-width:200px;" />
                    <button type="submit" class="button"><?php esc_html_e('Import', 'sfs-hr'); ?></button>
                </form>
            </div>
        </div>
    </div>

    <h2><?php esc_html_e('Assets', 'sfs-hr'); ?> <span style="font-weight:normal; font-size:14px; color:#50575e;">(<?php echo (int)$total_count; ?> <?php esc_html_e('total', 'sfs-hr'); ?>)</span></h2>

    <div class="sfs-hr-assets-table">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th class="hide-mobile"><?php esc_html_e('Code', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Name', 'sfs-hr'); ?></th>
                    <th class="hide-mobile"><?php esc_html_e('Category', 'sfs-hr'); ?></th>
                    <th class="hide-mobile"><?php esc_html_e('Department', 'sfs-hr'); ?></th>
                    <th class="hide-mobile"><?php esc_html_e('Assignee', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                    <th class="hide-mobile"><?php esc_html_e('Condition', 'sfs-hr'); ?></th>
                    <th class="show-mobile" style="width:50px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $display_rows ) ) : ?>
                <tr>
                    <td colspan="8"><?php esc_html_e('No assets found.', 'sfs-hr'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $display_rows as $row ) :
                    $assignee = $assignees[ (int) $row->id ] ?? null;
                    $assignee_name = '';
                    if ( $assignee ) {
                        $assignee_name = trim( $assignee->first_name . ' ' . $assignee->last_name );
                        if ( $assignee_name === '' ) {
                            $assignee_name = '#' . (int) $assignee->employee_id;
                        }
                    }
                    $edit_url = add_query_arg( ['view' => 'edit', 'id' => (int) $row->id] );
                    $status_class = sanitize_html_class( $row->status );
                    $status_label = ucfirst( str_replace( '_', ' ', $row->status ) );
                    $condition_label = ucfirst( str_replace( '_', ' ', $row->condition ) );
                ?>
                    <tr>
                        <td class="hide-mobile"><span class="asset-code" style="display:inline;"><?php echo esc_html( $row->asset_code ); ?></span></td>
                        <td>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="asset-name">
                                <?php echo esc_html( $row->name ); ?>
                            </a>
                            <span class="asset-code"><?php echo esc_html( $row->asset_code ); ?></span>
                        </td>
                        <td class="hide-mobile"><?php echo esc_html( $row->category ); ?></td>
                        <td class="hide-mobile"><?php echo esc_html( $row->department ); ?></td>
                        <td class="hide-mobile">
                            <?php
                            if ( $assignee ) {
                                $profile_url = add_query_arg(
                                    [
                                        'page'        => 'sfs-hr-employee-profile',
                                        'employee_id' => (int) $assignee->employee_id,
                                    ],
                                    admin_url( 'admin.php' )
                                );
                                echo '<a href="' . esc_url( $profile_url ) . '">' . esc_html( $assignee_name ) . '</a>';
                            } else {
                                echo '&mdash;';
                            }
                            ?>
                        </td>
                        <td>
                            <span class="sfs-hr-asset-status status-<?php echo esc_attr( $status_class ); ?>">
                                <?php echo esc_html( $status_label ); ?>
                            </span>
                        </td>
                        <td class="hide-mobile"><?php echo esc_html( $condition_label ); ?></td>
                        <td class="show-mobile">
                            <button type="button" class="sfs-hr-action-btn" onclick="sfsHrOpenAssetModal('<?php echo esc_js( $row->name ); ?>', '<?php echo esc_js( $row->asset_code ); ?>', '<?php echo esc_js( $row->category ); ?>', '<?php echo esc_js( $row->department ); ?>', '<?php echo esc_js( $assignee_name ); ?>', '<?php echo esc_js( $status_label ); ?>', '<?php echo esc_js( $condition_label ); ?>', '<?php echo esc_js( $edit_url ); ?>')">&#8942;</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
