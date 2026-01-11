<?php
namespace SFS\HR\Modules\Documents;

if (!defined('ABSPATH')) { exit; }

/**
 * Documents Module
 * Employee document management (ID copies, certificates, contracts, etc.)
 * Version: 0.1.0
 * Author: Omar Alnabhani (hdqah.com)
 */
class DocumentsModule {

    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function hooks(): void {
        // Install tables on admin_init if needed
        add_action('admin_init', [$this, 'maybe_install_tables']);

        // Add Documents tab to My Profile (employee self-service)
        add_action('sfs_hr_employee_tabs', [$this, 'add_documents_tab'], 30);
        add_action('sfs_hr_employee_tab_content', [$this, 'render_documents_tab_content'], 10, 2);

        // Handle document upload via admin-post
        add_action('admin_post_sfs_hr_upload_document', [$this, 'handle_document_upload']);
        add_action('admin_post_sfs_hr_delete_document', [$this, 'handle_document_delete']);

        // REST API for document operations
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // AJAX for document upload
        add_action('wp_ajax_sfs_hr_upload_document', [$this, 'ajax_upload_document']);
    }

    /**
     * Install documents table if not exists
     */
    public function maybe_install_tables(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $charset_collate = $wpdb->get_charset_collate();

        $table_exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        ));

        if (!$table_exists) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                employee_id BIGINT(20) UNSIGNED NOT NULL,
                document_type VARCHAR(50) NOT NULL,
                document_name VARCHAR(255) NOT NULL,
                description TEXT,
                attachment_id BIGINT(20) UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_size BIGINT(20) UNSIGNED DEFAULT 0,
                mime_type VARCHAR(100) DEFAULT '',
                expiry_date DATE DEFAULT NULL,
                uploaded_by BIGINT(20) UNSIGNED NOT NULL,
                status ENUM('active','archived','expired') DEFAULT 'active',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY employee_id (employee_id),
                KEY document_type (document_type),
                KEY status (status),
                KEY expiry_date (expiry_date)
            ) {$charset_collate}");
        }
    }

    /**
     * Get document types
     */
    public static function get_document_types(): array {
        return [
            'national_id'    => __('National ID', 'sfs-hr'),
            'passport'       => __('Passport', 'sfs-hr'),
            'visa'           => __('Visa / Work Permit', 'sfs-hr'),
            'contract'       => __('Employment Contract', 'sfs-hr'),
            'certificate'    => __('Certificate / Degree', 'sfs-hr'),
            'training'       => __('Training Certificate', 'sfs-hr'),
            'license'        => __('Professional License', 'sfs-hr'),
            'medical'        => __('Medical Report', 'sfs-hr'),
            'bank_details'   => __('Bank Details', 'sfs-hr'),
            'photo'          => __('Photo / Headshot', 'sfs-hr'),
            'other'          => __('Other', 'sfs-hr'),
        ];
    }

    /**
     * Add Documents tab to employee profile pages
     */
    public function add_documents_tab($employee): void {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        $tab_class = 'nav-tab' . ($active_tab === 'documents' ? ' nav-tab-active' : '');

        // Build URL - check which page we're on
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

        if ($page === 'sfs-hr-my-profile') {
            $url = admin_url('admin.php?page=sfs-hr-my-profile&tab=documents');
        } else {
            $employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : (int)$employee->id;
            $url = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $employee_id . '&tab=documents');
        }

        echo '<a href="' . esc_url($url) . '" class="' . esc_attr($tab_class) . '">';
        esc_html_e('Documents', 'sfs-hr');
        echo '</a>';
    }

    /**
     * Render Documents tab content
     */
    public function render_documents_tab_content($employee, string $active_tab): void {
        if ($active_tab !== 'documents') {
            return;
        }

        global $wpdb;

        $employee_id = (int)$employee->id;
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        $is_self_service = ($page === 'sfs-hr-my-profile');
        $current_user_id = get_current_user_id();

        // Check permissions
        if ($is_self_service) {
            // Employee can only view their own documents
            if ((int)$employee->user_id !== $current_user_id) {
                wp_die(esc_html__('You can only access your own documents.', 'sfs-hr'));
            }
            $can_upload = true;
            $can_delete = true; // Can delete own uploads
        } else {
            // HR manager viewing employee documents
            if (!current_user_can('sfs_hr.manage') && !current_user_can('sfs_hr_attendance_view_team')) {
                wp_die(esc_html__('You do not have permission to view this.', 'sfs-hr'));
            }
            $can_upload = current_user_can('sfs_hr.manage');
            $can_delete = current_user_can('sfs_hr.manage');
        }

        $table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $documents = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE employee_id = %d AND status = 'active' ORDER BY created_at DESC",
            $employee_id
        ));

        // Group by type
        $grouped = [];
        foreach ($documents as $doc) {
            $grouped[$doc->document_type][] = $doc;
        }

        $document_types = self::get_document_types();
        $upload_nonce = wp_create_nonce('sfs_hr_upload_document_' . $employee_id);

        // Show success/error messages
        if (isset($_GET['success'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['success']))) . '</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['error']))) . '</p></div>';
        }

        ?>
        <div class="sfs-hr-documents-wrap">
            <style>
                .sfs-hr-documents-wrap {
                    max-width: 900px;
                }
                .sfs-hr-doc-upload-form {
                    background: #f9f9f9;
                    padding: 15px;
                    border: 1px solid #e5e5e5;
                    margin-bottom: 20px;
                    border-radius: 4px;
                }
                .sfs-hr-doc-upload-form .form-table th {
                    width: 140px;
                }
                .sfs-hr-doc-list {
                    margin-top: 20px;
                }
                .sfs-hr-doc-category {
                    margin-bottom: 20px;
                }
                .sfs-hr-doc-category h4 {
                    margin: 0 0 8px;
                    padding: 8px 12px;
                    background: #f3f4f5;
                    border-radius: 4px;
                    font-size: 13px;
                }
                .sfs-hr-doc-item {
                    display: flex;
                    align-items: center;
                    padding: 10px 12px;
                    border: 1px solid #e2e4e7;
                    border-radius: 4px;
                    margin-bottom: 8px;
                    background: #fff;
                }
                .sfs-hr-doc-icon {
                    width: 40px;
                    height: 40px;
                    background: #f0f0f1;
                    border-radius: 4px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin-right: 12px;
                    font-size: 18px;
                }
                .sfs-hr-doc-icon.pdf { background: #e74c3c; color: #fff; }
                .sfs-hr-doc-icon.image { background: #3498db; color: #fff; }
                .sfs-hr-doc-icon.doc { background: #2980b9; color: #fff; }
                .sfs-hr-doc-info {
                    flex: 1;
                }
                .sfs-hr-doc-name {
                    font-weight: 600;
                    margin-bottom: 2px;
                }
                .sfs-hr-doc-meta {
                    font-size: 12px;
                    color: #666;
                }
                .sfs-hr-doc-actions {
                    display: flex;
                    gap: 8px;
                }
                .sfs-hr-doc-expiry {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 3px;
                    font-size: 11px;
                    margin-left: 8px;
                }
                .sfs-hr-doc-expiry.expired {
                    background: #fee2e2;
                    color: #dc2626;
                }
                .sfs-hr-doc-expiry.expiring-soon {
                    background: #fef3c7;
                    color: #d97706;
                }
                .sfs-hr-doc-expiry.valid {
                    background: #d1fae5;
                    color: #059669;
                }
                @media screen and (max-width: 782px) {
                    .sfs-hr-doc-item {
                        flex-wrap: wrap;
                    }
                    .sfs-hr-doc-actions {
                        width: 100%;
                        margin-top: 10px;
                        justify-content: flex-end;
                    }
                    .sfs-hr-doc-upload-form .form-table th,
                    .sfs-hr-doc-upload-form .form-table td {
                        display: block;
                        width: 100%;
                        padding: 8px 0;
                    }
                }
            </style>

            <?php if ($can_upload): ?>
            <div class="sfs-hr-doc-upload-form">
                <h3 style="margin-top:0;"><?php esc_html_e('Upload Document', 'sfs-hr'); ?></h3>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="sfs_hr_upload_document" />
                    <input type="hidden" name="employee_id" value="<?php echo (int)$employee_id; ?>" />
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($upload_nonce); ?>" />
                    <input type="hidden" name="redirect_page" value="<?php echo esc_attr($page); ?>" />

                    <table class="form-table">
                        <tr>
                            <th><label for="document_type"><?php esc_html_e('Document Type', 'sfs-hr'); ?></label></th>
                            <td>
                                <select name="document_type" id="document_type" required style="min-width:200px;">
                                    <option value=""><?php esc_html_e('— Select Type —', 'sfs-hr'); ?></option>
                                    <?php foreach ($document_types as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="document_name"><?php esc_html_e('Document Name', 'sfs-hr'); ?></label></th>
                            <td>
                                <input type="text" name="document_name" id="document_name" class="regular-text" required placeholder="<?php esc_attr_e('e.g., National ID Copy', 'sfs-hr'); ?>" />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="document_file"><?php esc_html_e('File', 'sfs-hr'); ?></label></th>
                            <td>
                                <input type="file" name="document_file" id="document_file" required accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx" />
                                <p class="description"><?php esc_html_e('Accepted: PDF, Images, Word, Excel (max 10MB)', 'sfs-hr'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="expiry_date"><?php esc_html_e('Expiry Date', 'sfs-hr'); ?></label></th>
                            <td>
                                <input type="date" name="expiry_date" id="expiry_date" class="regular-text" />
                                <p class="description"><?php esc_html_e('Optional - for IDs, passports, licenses, etc.', 'sfs-hr'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="description"><?php esc_html_e('Notes', 'sfs-hr'); ?></label></th>
                            <td>
                                <textarea name="description" id="description" rows="2" class="large-text" placeholder="<?php esc_attr_e('Optional notes...', 'sfs-hr'); ?>"></textarea>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Upload Document', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
            <?php endif; ?>

            <div class="sfs-hr-doc-list">
                <h3><?php esc_html_e('Documents', 'sfs-hr'); ?></h3>

                <?php if (empty($documents)): ?>
                    <p class="description"><?php esc_html_e('No documents uploaded yet.', 'sfs-hr'); ?></p>
                <?php else: ?>
                    <?php foreach ($document_types as $type_key => $type_label): ?>
                        <?php if (!empty($grouped[$type_key])): ?>
                            <div class="sfs-hr-doc-category">
                                <h4><?php echo esc_html($type_label); ?> (<?php echo count($grouped[$type_key]); ?>)</h4>

                                <?php foreach ($grouped[$type_key] as $doc): ?>
                                    <?php
                                    $icon_class = 'other';
                                    if (strpos($doc->mime_type, 'pdf') !== false) {
                                        $icon_class = 'pdf';
                                    } elseif (strpos($doc->mime_type, 'image') !== false) {
                                        $icon_class = 'image';
                                    } elseif (strpos($doc->mime_type, 'word') !== false || strpos($doc->mime_type, 'document') !== false) {
                                        $icon_class = 'doc';
                                    }

                                    $file_url = wp_get_attachment_url($doc->attachment_id);
                                    $file_size = size_format($doc->file_size, 1);

                                    // Expiry status
                                    $expiry_html = '';
                                    if ($doc->expiry_date) {
                                        $expiry_ts = strtotime($doc->expiry_date);
                                        $today_ts = strtotime(wp_date('Y-m-d'));
                                        $days_until = ($expiry_ts - $today_ts) / 86400;

                                        if ($days_until < 0) {
                                            $expiry_html = '<span class="sfs-hr-doc-expiry expired">' . esc_html__('Expired', 'sfs-hr') . '</span>';
                                        } elseif ($days_until <= 30) {
                                            $expiry_html = '<span class="sfs-hr-doc-expiry expiring-soon">' . sprintf(esc_html__('Expires in %d days', 'sfs-hr'), (int)$days_until) . '</span>';
                                        } else {
                                            $expiry_html = '<span class="sfs-hr-doc-expiry valid">' . sprintf(esc_html__('Expires %s', 'sfs-hr'), date_i18n(get_option('date_format'), $expiry_ts)) . '</span>';
                                        }
                                    }
                                    ?>
                                    <div class="sfs-hr-doc-item">
                                        <div class="sfs-hr-doc-icon <?php echo esc_attr($icon_class); ?>">
                                            <?php
                                            if ($icon_class === 'pdf') echo 'PDF';
                                            elseif ($icon_class === 'image') echo 'IMG';
                                            elseif ($icon_class === 'doc') echo 'DOC';
                                            else echo 'FILE';
                                            ?>
                                        </div>
                                        <div class="sfs-hr-doc-info">
                                            <div class="sfs-hr-doc-name">
                                                <?php echo esc_html($doc->document_name); ?>
                                                <?php echo $expiry_html; ?>
                                            </div>
                                            <div class="sfs-hr-doc-meta">
                                                <?php echo esc_html($doc->file_name); ?> &middot; <?php echo esc_html($file_size); ?> &middot;
                                                <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($doc->created_at))); ?>
                                                <?php if ($doc->description): ?>
                                                    <br><em><?php echo esc_html(wp_trim_words($doc->description, 15)); ?></em>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="sfs-hr-doc-actions">
                                            <?php if ($file_url): ?>
                                                <a href="<?php echo esc_url($file_url); ?>" class="button button-small" target="_blank" download>
                                                    <?php esc_html_e('Download', 'sfs-hr'); ?>
                                                </a>
                                                <?php if (strpos($doc->mime_type, 'image') !== false || strpos($doc->mime_type, 'pdf') !== false): ?>
                                                    <a href="<?php echo esc_url($file_url); ?>" class="button button-small" target="_blank">
                                                        <?php esc_html_e('View', 'sfs-hr'); ?>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($can_delete && ((int)$doc->uploaded_by === $current_user_id || current_user_can('sfs_hr.manage'))): ?>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete this document?', 'sfs-hr')); ?>');">
                                                    <input type="hidden" name="action" value="sfs_hr_delete_document" />
                                                    <input type="hidden" name="document_id" value="<?php echo (int)$doc->id; ?>" />
                                                    <input type="hidden" name="employee_id" value="<?php echo (int)$employee_id; ?>" />
                                                    <input type="hidden" name="redirect_page" value="<?php echo esc_attr($page); ?>" />
                                                    <?php wp_nonce_field('sfs_hr_delete_document_' . $doc->id); ?>
                                                    <button type="submit" class="button button-small" style="color:#a00;">
                                                        <?php esc_html_e('Delete', 'sfs-hr'); ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handle document upload
     */
    public function handle_document_upload(): void {
        $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
        $redirect_page = isset($_POST['redirect_page']) ? sanitize_key($_POST['redirect_page']) : 'sfs-hr-my-profile';

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_upload_document_' . $employee_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        // Check permissions
        $current_user_id = get_current_user_id();
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$emp_table} WHERE id = %d",
            $employee_id
        ));

        if (!$employee) {
            wp_die(esc_html__('Employee not found.', 'sfs-hr'));
        }

        // Self-service: user can only upload to their own profile
        // HR manager: can upload to any employee
        if ((int)$employee->user_id !== $current_user_id && !current_user_can('sfs_hr.manage')) {
            wp_die(esc_html__('You do not have permission to upload documents for this employee.', 'sfs-hr'));
        }

        // Validate inputs
        $document_type = isset($_POST['document_type']) ? sanitize_key($_POST['document_type']) : '';
        $document_name = isset($_POST['document_name']) ? sanitize_text_field($_POST['document_name']) : '';
        $expiry_date = isset($_POST['expiry_date']) ? sanitize_text_field($_POST['expiry_date']) : null;
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';

        if (empty($document_type) || empty($document_name)) {
            $this->redirect_with_error($employee_id, $redirect_page, __('Document type and name are required.', 'sfs-hr'));
            return;
        }

        // Handle file upload
        if (empty($_FILES['document_file']['name'])) {
            $this->redirect_with_error($employee_id, $redirect_page, __('Please select a file to upload.', 'sfs-hr'));
            return;
        }

        // Check file size (10MB max)
        $max_size = 10 * 1024 * 1024;
        if ($_FILES['document_file']['size'] > $max_size) {
            $this->redirect_with_error($employee_id, $redirect_page, __('File size exceeds 10MB limit.', 'sfs-hr'));
            return;
        }

        // Allowed file types
        $allowed_types = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $_FILES['document_file']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_types, true)) {
            $this->redirect_with_error($employee_id, $redirect_page, __('Invalid file type. Allowed: PDF, Images, Word, Excel.', 'sfs-hr'));
            return;
        }

        // Use WordPress media handling
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $attachment_id = media_handle_upload('document_file', 0);

        if (is_wp_error($attachment_id)) {
            $this->redirect_with_error($employee_id, $redirect_page, $attachment_id->get_error_message());
            return;
        }

        // Save to database
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $now = current_time('mysql');

        $wpdb->insert($table, [
            'employee_id'   => $employee_id,
            'document_type' => $document_type,
            'document_name' => $document_name,
            'description'   => $description,
            'attachment_id' => $attachment_id,
            'file_name'     => $_FILES['document_file']['name'],
            'file_size'     => $_FILES['document_file']['size'],
            'mime_type'     => $mime_type,
            'expiry_date'   => $expiry_date ?: null,
            'uploaded_by'   => $current_user_id,
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ], [
            '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s'
        ]);

        // Log the action
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'document_uploaded',
                'employee_documents',
                $wpdb->insert_id,
                null,
                [
                    'employee_id' => $employee_id,
                    'document_type' => $document_type,
                    'document_name' => $document_name,
                ]
            );
        }

        // Redirect with success
        $this->redirect_with_success($employee_id, $redirect_page, __('Document uploaded successfully.', 'sfs-hr'));
    }

    /**
     * Handle document deletion
     */
    public function handle_document_delete(): void {
        $document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
        $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
        $redirect_page = isset($_POST['redirect_page']) ? sanitize_key($_POST['redirect_page']) : 'sfs-hr-my-profile';

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_delete_document_' . $document_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $current_user_id = get_current_user_id();

        // Get document
        $document = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $document_id
        ));

        if (!$document) {
            wp_die(esc_html__('Document not found.', 'sfs-hr'));
        }

        // Check permissions: own document or HR manager
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$emp_table} WHERE id = %d",
            $document->employee_id
        ));

        $can_delete = false;
        if ((int)$document->uploaded_by === $current_user_id) {
            $can_delete = true; // Can delete own uploads
        }
        if (current_user_can('sfs_hr.manage')) {
            $can_delete = true; // HR manager can delete any
        }

        if (!$can_delete) {
            wp_die(esc_html__('You do not have permission to delete this document.', 'sfs-hr'));
        }

        // Soft delete (archive)
        $wpdb->update(
            $table,
            ['status' => 'archived', 'updated_at' => current_time('mysql')],
            ['id' => $document_id],
            ['%s', '%s'],
            ['%d']
        );

        // Log the action
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'document_deleted',
                'employee_documents',
                $document_id,
                ['status' => 'active'],
                ['status' => 'archived']
            );
        }

        // Redirect with success
        $this->redirect_with_success($employee_id, $redirect_page, __('Document deleted.', 'sfs-hr'));
    }

    /**
     * Redirect helpers
     */
    private function redirect_with_error(int $employee_id, string $page, string $message): void {
        if ($page === 'sfs-hr-my-profile') {
            $url = admin_url('admin.php?page=sfs-hr-my-profile&tab=documents&error=' . rawurlencode($message));
        } else {
            $url = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $employee_id . '&tab=documents&error=' . rawurlencode($message));
        }
        wp_safe_redirect($url);
        exit;
    }

    private function redirect_with_success(int $employee_id, string $page, string $message): void {
        if ($page === 'sfs-hr-my-profile') {
            $url = admin_url('admin.php?page=sfs-hr-my-profile&tab=documents&success=' . rawurlencode($message));
        } else {
            $url = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $employee_id . '&tab=documents&success=' . rawurlencode($message));
        }
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Register REST routes
     */
    public function register_rest_routes(): void {
        register_rest_route('sfs-hr/v1', '/documents/(?P<employee_id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'rest_get_documents'],
            'permission_callback' => function() {
                return is_user_logged_in();
            },
        ]);
    }

    /**
     * REST: Get employee documents
     */
    public function rest_get_documents(\WP_REST_Request $request): \WP_REST_Response {
        $employee_id = (int)$request->get_param('employee_id');
        $current_user_id = get_current_user_id();

        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$emp_table} WHERE id = %d",
            $employee_id
        ));

        if (!$employee) {
            return new \WP_REST_Response(['error' => 'Employee not found'], 404);
        }

        // Check permissions
        if ((int)$employee->user_id !== $current_user_id && !current_user_can('sfs_hr.manage') && !current_user_can('sfs_hr_attendance_view_team')) {
            return new \WP_REST_Response(['error' => 'Access denied'], 403);
        }

        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $documents = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$doc_table} WHERE employee_id = %d AND status = 'active' ORDER BY created_at DESC",
            $employee_id
        ));

        $result = [];
        foreach ($documents as $doc) {
            $result[] = [
                'id'            => (int)$doc->id,
                'document_type' => $doc->document_type,
                'document_name' => $doc->document_name,
                'description'   => $doc->description,
                'file_name'     => $doc->file_name,
                'file_size'     => (int)$doc->file_size,
                'mime_type'     => $doc->mime_type,
                'expiry_date'   => $doc->expiry_date,
                'file_url'      => wp_get_attachment_url($doc->attachment_id),
                'created_at'    => $doc->created_at,
            ];
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * Get documents count for an employee
     */
    public static function get_document_count(int $employee_id): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE employee_id = %d AND status = 'active'",
            $employee_id
        ));
    }

    /**
     * Get expiring documents (for notifications/reports)
     */
    public static function get_expiring_documents(int $days_ahead = 30): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $future_date = wp_date('Y-m-d', strtotime("+{$days_ahead} days"));
        $today = wp_date('Y-m-d');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, e.first_name, e.last_name, e.employee_code
             FROM {$table} d
             JOIN {$emp_table} e ON e.id = d.employee_id
             WHERE d.status = 'active'
               AND d.expiry_date IS NOT NULL
               AND d.expiry_date BETWEEN %s AND %s
             ORDER BY d.expiry_date ASC",
            $today,
            $future_date
        ));
    }
}
