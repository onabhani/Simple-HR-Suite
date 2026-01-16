<?php
if ( ! defined('ABSPATH') ) { exit; }

/** @var object|null $row */

$is_edit = $row && ! empty($row->id);

$id             = $is_edit ? (int) $row->id : 0;
$asset_code     = $is_edit ? $row->asset_code     : '';
$name           = $is_edit ? $row->name           : '';
$category       = $is_edit ? $row->category       : '';
$department     = $is_edit ? $row->department     : '';
$serial_number  = $is_edit ? $row->serial_number  : '';
$model          = $is_edit ? $row->model          : '';
$purchase_year  = $is_edit ? (int) $row->purchase_year : '';
$purchase_price = $is_edit ? $row->purchase_price : '';
$warranty_expiry= $is_edit ? $row->warranty_expiry: '';
$invoice_number = $is_edit ? $row->invoice_number : '';
$invoice_date   = $is_edit ? $row->invoice_date   : '';
$invoice_file   = $is_edit ? $row->invoice_file   : '';
$qr_code_path   = ( $is_edit && ! empty( $row->qr_code_path ) ) ? $row->qr_code_path : '';
$status         = $is_edit ? $row->status         : 'available';
$condition      = $is_edit ? $row->condition      : 'good';
$notes          = $is_edit ? $row->notes          : '';

$statuses = [
    'available'      => __('Available', 'sfs-hr'),
    'assigned'       => __('Assigned', 'sfs-hr'),
    'under_approval' => __('Under Approval', 'sfs-hr'),
    'returned'       => __('Returned', 'sfs-hr'),
    'archived'       => __('Archived', 'sfs-hr'),
];

$conditions = [
    'new'          => __('New', 'sfs-hr'),
    'good'         => __('Good', 'sfs-hr'),
    'damaged'      => __('Damaged', 'sfs-hr'),
    'needs_repair' => __('Needs Repair', 'sfs-hr'),
    'lost'         => __('Lost', 'sfs-hr'),
];

// Get categories from the Admin class
$admin_pages = new \SFS\HR\Modules\Assets\Admin\Admin_Pages();
$category_options = $admin_pages->get_asset_categories();

// Departments: from departments table if exists, else distinct employee departments
global $wpdb;
$departments = [];

$dept_table        = $wpdb->prefix . 'sfs_hr_departments';
$dept_table_exists = $wpdb->get_var( $wpdb->prepare(
    "SHOW TABLES LIKE %s", $dept_table
) );

if ( $dept_table_exists === $dept_table ) {
    $departments = $wpdb->get_col( "SELECT name FROM {$dept_table} ORDER BY name ASC" );
} else {
    $employees_table = $wpdb->prefix . 'sfs_hr_employees';
    $departments = $wpdb->get_col( "
        SELECT DISTINCT department
        FROM {$employees_table}
        WHERE department <> ''
        ORDER BY department ASC
    " );
}
?>

<div style="margin-top:20px;">
    <h2>
        <?php echo $is_edit ? esc_html__('Edit Asset', 'sfs-hr') : esc_html__('Add New Asset', 'sfs-hr'); ?>
    </h2>

    <form method="post"
          action="<?php echo esc_url( admin_url('admin-post.php') ); ?>"
          enctype="multipart/form-data">
        <?php wp_nonce_field('sfs_hr_assets_edit'); ?>
        <input type="hidden" name="action" value="sfs_hr_assets_save" />
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>" />

        <table class="form-table">
    <tr>
        <th scope="row">
            <label for="asset_code"><?php esc_html_e('Asset Code', 'sfs-hr'); ?></label>
        </th>
        <td>
            <input type="text" name="asset_code" id="asset_code"
                   value="<?php echo esc_attr( $asset_code ); ?>"
                   class="regular-text" required />
            <p class="description">
                <?php esc_html_e('Internal code, e.g. AST-000123', 'sfs-hr'); ?>
            </p>
        </td>
    </tr>

    <?php if ( ! empty( $qr_code_path ) ) : ?>
        <tr>
            <th scope="row">
                <?php esc_html_e( 'QR Code', 'sfs-hr' ); ?>
            </th>
            <td>
                <img src="<?php echo esc_url( $qr_code_path ); ?>"
                     alt="<?php esc_attr_e( 'Asset QR code', 'sfs-hr' ); ?>"
                     style="max-width:160px;height:auto;border:1px solid #ccd0d4;padding:4px;background:#fff;">
                <p class="description">
                    <a href="<?php echo esc_url( $qr_code_path ); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e( 'Open / download QR code', 'sfs-hr' ); ?>
                    </a>
                </p>
            </td>
        </tr>
    <?php endif; ?>

    <tr>
        <th scope="row">
            <label for="name"><?php esc_html_e('Name', 'sfs-hr'); ?></label>
        </th>
        <td>
            <input type="text" name="name" id="name"
                   value="<?php echo esc_attr( $name ); ?>"
                   class="regular-text" required />
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="category"><?php esc_html_e('Category', 'sfs-hr'); ?></label>
        </th>
        <td>
            <select name="category" id="category" required>
                <option value=""><?php esc_html_e('Select category', 'sfs-hr'); ?></option>
                <?php foreach ( $category_options as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"
                        <?php selected( $category, $key ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description">
                <?php esc_html_e('Predefined categories to avoid duplicates.', 'sfs-hr'); ?>
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="department"><?php esc_html_e('Department', 'sfs-hr'); ?></label>
        </th>
        <td>
            <select name="department" id="department">
                <option value=""><?php esc_html_e('Select department', 'sfs-hr'); ?></option>
                <?php foreach ( $departments as $dept ) : ?>
                    <option value="<?php echo esc_attr( $dept ); ?>"
                        <?php selected( $department, $dept ); ?>>
                        <?php echo esc_html( $dept ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="serial_number"><?php esc_html_e('Serial Number', 'sfs-hr'); ?></label>
        </th>
        <td>
            <input type="text" name="serial_number" id="serial_number"
                   value="<?php echo esc_attr( $serial_number ); ?>"
                   class="regular-text" />
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="model"><?php esc_html_e('Model', 'sfs-hr'); ?></label>
        </th>
        <td>
            <input type="text" name="model" id="model"
                   value="<?php echo esc_attr( $model ); ?>"
                   class="regular-text" />
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="purchase_year"><?php esc_html_e('Purchase Year', 'sfs-hr'); ?></label>
        </th>
        <td>
            <input type="number" name="purchase_year" id="purchase_year"
                   value="<?php echo esc_attr( $purchase_year ); ?>"
                   class="small-text" min="1990" max="<?php echo esc_attr( date('Y') + 1 ); ?>" />
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="purchase_price"><?php esc_html_e('Purchase Price', 'sfs-hr'); ?></label>
        </th>
        <td>
            <input type="number" name="purchase_price" id="purchase_price"
                   value="<?php echo esc_attr( $purchase_price ); ?>"
                   step="0.01" min="0" />
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="invoice_number"><?php esc_html_e('Invoice Number', 'sfs-hr'); ?></label>
        </th>
        <td>
            <input type="text" name="invoice_number" id="invoice_number"
                   value="<?php echo esc_attr( $invoice_number ); ?>"
                   class="regular-text" />
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="invoice_date"><?php esc_html_e('Invoice Date', 'sfs-hr'); ?></label>
        </th>
        <td>
            <input type="date" name="invoice_date" id="invoice_date"
                   value="<?php echo esc_attr( $invoice_date ); ?>" />
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="invoice_file"><?php esc_html_e('Invoice File', 'sfs-hr'); ?></label>
        </th>
        <td>
            <input type="file" name="invoice_file" id="invoice_file" />
            <?php if ( $invoice_file ) : ?>
                <p class="description">
                    <?php esc_html_e('Current invoice:', 'sfs-hr'); ?>
                    <a href="<?php echo esc_url( $invoice_file ); ?>" target="_blank">
                        <?php esc_html_e('Download', 'sfs-hr'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="warranty_expiry"><?php esc_html_e('Warranty Expiry', 'sfs-hr'); ?></label>
        </th>
        <td>
            <input type="date" name="warranty_expiry" id="warranty_expiry"
                   value="<?php echo esc_attr( $warranty_expiry ); ?>" />
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="status"><?php esc_html_e('Status', 'sfs-hr'); ?></label>
        </th>
        <td>
            <select name="status" id="status">
                <?php foreach ( $statuses as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"
                        <?php selected( $status, $key ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="condition"><?php esc_html_e('Condition', 'sfs-hr'); ?></label>
        </th>
        <td>
            <select name="condition" id="condition">
                <?php foreach ( $conditions as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"
                        <?php selected( $condition, $key ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="notes"><?php esc_html_e('Notes', 'sfs-hr'); ?></label>
        </th>
        <td>
            <textarea name="notes" id="notes" rows="4"
                      class="large-text"><?php echo esc_textarea( $notes ); ?></textarea>
        </td>
    </tr>
</table>

        <p class="submit" style="display:flex; gap:12px; align-items:center;">
            <?php submit_button( $is_edit ? __('Save Asset', 'sfs-hr') : __('Create Asset', 'sfs-hr'), 'primary', 'submit', false ); ?>

            <?php if ( $is_edit && $id > 0 ) : ?>
                <span style="color:#999;">|</span>
                <a href="#" onclick="document.getElementById('sfs-hr-delete-asset-form').style.display='block'; return false;" class="button button-link-delete">
                    <?php esc_html_e('Delete Asset', 'sfs-hr'); ?>
                </a>
            <?php endif; ?>
        </p>
    </form>

    <?php if ( $is_edit && $id > 0 ) : ?>
    <!-- Delete Asset Form (hidden) -->
    <div id="sfs-hr-delete-asset-form" style="display:none; background:#fee; border:1px solid #c00; padding:15px; border-radius:4px; margin-top:20px;">
        <p style="color:#c00; margin:0 0 10px;"><strong><?php esc_html_e('Are you sure you want to delete this asset?', 'sfs-hr'); ?></strong></p>
        <p style="margin:0 0 15px;"><?php esc_html_e('This action cannot be undone. Assets with active assignments cannot be deleted.', 'sfs-hr'); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;">
            <?php wp_nonce_field('sfs_hr_assets_delete'); ?>
            <input type="hidden" name="action" value="sfs_hr_assets_delete" />
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
            <button type="submit" class="button button-link-delete" style="color:#fff; background:#c00; border-color:#c00;">
                <?php esc_html_e('Yes, Delete This Asset', 'sfs-hr'); ?>
            </button>
            <button type="button" onclick="document.getElementById('sfs-hr-delete-asset-form').style.display='none';" class="button">
                <?php esc_html_e('Cancel', 'sfs-hr'); ?>
            </button>
        </form>
    </div>
    <?php endif; ?>

<?php if ( $is_edit && $id > 0 ) : ?>
    <hr />
    <h2><?php esc_html_e( 'Assignment History', 'sfs-hr' ); ?></h2>
    <?php
    global $wpdb;
    $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $emp_table    = $wpdb->prefix . 'sfs_hr_employees';

    $history = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT a.*,
                    e.employee_code,
                    TRIM(CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,''))) AS employee_name
             FROM {$assign_table} a
             LEFT JOIN {$emp_table} e ON e.id = a.employee_id
             WHERE a.asset_id = %d
             ORDER BY a.created_at DESC
             LIMIT 200",
            $id
        )
    );
    ?>
    <?php if ( empty( $history ) ) : ?>
        <p><?php esc_html_e( 'No assignment history for this asset yet.', 'sfs-hr' ); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Code', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Start', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'End / Return', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Notes', 'sfs-hr' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $history as $h ) : ?>
                <tr>
                    <td><?php echo esc_html( $h->employee_name ?: '—' ); ?></td>
                    <td><?php echo esc_html( $h->employee_code ?: '' ); ?></td>
                    <td><?php echo esc_html( $h->start_date ?: '' ); ?></td>
                    <td>
                        <?php
                        $end = $h->return_date ?: $h->end_date;
                        echo esc_html( $end ?: '—' );
                        ?>
                    </td>
                    <td><?php echo \SFS\HR\Core\Helpers::asset_status_badge( (string) $h->status ); ?></td>
                    <td><?php echo esc_html( $h->notes ?: '' ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

</div>

