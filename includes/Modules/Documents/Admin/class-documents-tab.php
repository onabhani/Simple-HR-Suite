<?php
namespace SFS\HR\Modules\Documents\Admin;

use SFS\HR\Modules\Documents\Services\Documents_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Documents Tab
 * Admin UI for document management in employee profiles
 */
class Documents_Tab {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('sfs_hr_employee_tabs', [$this, 'add_tab'], 30);
        add_action('sfs_hr_employee_tab_content', [$this, 'render_content'], 10, 2);
    }

    /**
     * Add Documents tab to profile
     */
    public function add_tab($employee): void {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview';
        $tab_class = 'nav-tab' . ($active_tab === 'documents' ? ' nav-tab-active' : '');
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
    public function render_content($employee, string $active_tab): void {
        if ($active_tab !== 'documents') {
            return;
        }

        $employee_id = (int)$employee->id;
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        $is_self_service = ($page === 'sfs-hr-my-profile');
        $current_user_id = get_current_user_id();
        $is_hr_admin = current_user_can('sfs_hr.manage');

        // Check permissions
        if ($is_self_service) {
            if ((int)$employee->user_id !== $current_user_id) {
                wp_die(esc_html__('You can only access your own documents.', 'sfs-hr'));
            }
            $can_upload = true;
            // Employees cannot delete documents - only HR/admin can
            $can_delete = false;
            $can_request_update = false;
        } else {
            if (!$is_hr_admin && !current_user_can('sfs_hr_attendance_view_team')) {
                wp_die(esc_html__('You do not have permission to view this.', 'sfs-hr'));
            }
            $can_upload = $is_hr_admin;
            $can_delete = $is_hr_admin;
            $can_request_update = $is_hr_admin;
        }

        $grouped = Documents_Service::get_documents_grouped($employee_id);
        $document_types = Documents_Service::get_document_types();
        $upload_nonce = wp_create_nonce('sfs_hr_upload_document_' . $employee_id);

        // For employees: get only uploadable document types
        $uploadable_types = null;
        if ($is_self_service) {
            $uploadable_types = Documents_Service::get_uploadable_document_types_for_employee($employee_id);
        }

        // Show messages
        $this->show_messages();

        // Render styles
        $this->render_styles();

        ?>
        <div class="sfs-hr-documents-wrap">
            <?php if ($can_upload): ?>
                <?php
                if ($is_self_service) {
                    $this->render_employee_upload_form($employee_id, $page, $upload_nonce, $uploadable_types);
                } else {
                    $this->render_upload_form($employee_id, $page, $upload_nonce, $document_types);
                }
                ?>
            <?php endif; ?>

            <?php $this->render_documents_list($grouped, $document_types, $employee_id, $page, $can_delete, $current_user_id, $can_request_update); ?>
        </div>
        <?php
    }

    /**
     * Show success/error messages
     */
    private function show_messages(): void {
        if (isset($_GET['success'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['success']))) . '</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['error']))) . '</p></div>';
        }
    }

    /**
     * Render CSS styles
     */
    private function render_styles(): void {
        ?>
        <style>
            .sfs-hr-documents-wrap { max-width: 900px; }
            .sfs-hr-doc-upload-form {
                background: #f9f9f9;
                padding: 15px;
                border: 1px solid #e5e5e5;
                margin-bottom: 20px;
                border-radius: 4px;
            }
            .sfs-hr-doc-upload-form .form-table th { width: 140px; }
            .sfs-hr-doc-list { margin-top: 20px; }
            .sfs-hr-doc-category { margin-bottom: 20px; }
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
            .sfs-hr-doc-info { flex: 1; }
            .sfs-hr-doc-name { font-weight: 600; margin-bottom: 2px; }
            .sfs-hr-doc-meta { font-size: 12px; color: #666; }
            .sfs-hr-doc-actions { display: flex; gap: 8px; }
            .sfs-hr-doc-expiry {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                margin-left: 8px;
            }
            .sfs-hr-doc-expiry.expired { background: #fee2e2; color: #dc2626; }
            .sfs-hr-doc-expiry.expiring-soon { background: #fef3c7; color: #d97706; }
            .sfs-hr-doc-expiry.valid { background: #d1fae5; color: #059669; }
            .sfs-hr-doc-update-requested {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                margin-left: 8px;
                background: #dbeafe;
                color: #1d4ed8;
            }
            .sfs-hr-no-upload-notice {
                background: #f0f6fc;
                border: 1px solid #d0d7de;
                padding: 12px 16px;
                border-radius: 6px;
                margin-bottom: 20px;
                color: #24292f;
            }
            .sfs-hr-upload-hint {
                font-size: 12px;
                color: #666;
                margin-top: 4px;
            }
            @media screen and (max-width: 782px) {
                .sfs-hr-doc-item { flex-wrap: wrap; }
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
        <?php
    }

    /**
     * Render upload form
     */
    private function render_upload_form(int $employee_id, string $page, string $nonce, array $document_types): void {
        ?>
        <div class="sfs-hr-doc-upload-form">
            <h3 style="margin-top:0;"><?php esc_html_e('Upload Document', 'sfs-hr'); ?></h3>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="sfs_hr_upload_document" />
                <input type="hidden" name="employee_id" value="<?php echo (int)$employee_id; ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
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
        <?php
    }

    /**
     * Render upload form for employees (with restricted document types)
     * Employees can only upload:
     * - Document types they don't have yet
     * - Documents that are expired
     * - Documents that HR has requested an update for
     */
    private function render_employee_upload_form(int $employee_id, string $page, string $nonce, array $uploadable_types): void {
        // If no uploadable types, show message
        if (empty($uploadable_types)) {
            ?>
            <div class="sfs-hr-no-upload-notice">
                <strong><?php esc_html_e('All documents are up to date', 'sfs-hr'); ?></strong>
                <p class="sfs-hr-upload-hint">
                    <?php esc_html_e('You have already uploaded all required document types. If you need to update a document, please contact HR.', 'sfs-hr'); ?>
                </p>
            </div>
            <?php
            return;
        }

        ?>
        <div class="sfs-hr-doc-upload-form">
            <h3 style="margin-top:0;"><?php esc_html_e('Upload Document', 'sfs-hr'); ?></h3>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="sfs_hr_upload_document" />
                <input type="hidden" name="employee_id" value="<?php echo (int)$employee_id; ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
                <input type="hidden" name="redirect_page" value="<?php echo esc_attr($page); ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="document_type"><?php esc_html_e('Document Type', 'sfs-hr'); ?></label></th>
                        <td>
                            <select name="document_type" id="document_type" required style="min-width:200px;">
                                <option value=""><?php esc_html_e('— Select Type —', 'sfs-hr'); ?></option>
                                <?php foreach ($uploadable_types as $key => $info): ?>
                                    <?php
                                    $label = $info['label'];
                                    $hint = '';
                                    if ($info['reason'] === 'expired') {
                                        $hint = ' (' . __('expired - update required', 'sfs-hr') . ')';
                                    } elseif ($info['reason'] === 'update_requested') {
                                        $hint = ' (' . __('update requested by HR', 'sfs-hr') . ')';
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label . $hint); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description sfs-hr-upload-hint">
                                <?php esc_html_e('Only document types that need to be added or updated are shown.', 'sfs-hr'); ?>
                            </p>
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
        <?php
    }

    /**
     * Render documents list
     */
    private function render_documents_list(array $grouped, array $document_types, int $employee_id, string $page, bool $can_delete, int $current_user_id, bool $can_request_update = false): void {
        ?>
        <div class="sfs-hr-doc-list">
            <h3><?php esc_html_e('Documents', 'sfs-hr'); ?></h3>

            <?php if (empty($grouped)): ?>
                <p class="description"><?php esc_html_e('No documents uploaded yet.', 'sfs-hr'); ?></p>
            <?php else: ?>
                <?php foreach ($document_types as $type_key => $type_label): ?>
                    <?php if (!empty($grouped[$type_key])): ?>
                        <div class="sfs-hr-doc-category">
                            <h4><?php echo esc_html($type_label); ?> (<?php echo count($grouped[$type_key]); ?>)</h4>

                            <?php foreach ($grouped[$type_key] as $doc): ?>
                                <?php $this->render_document_item($doc, $employee_id, $page, $can_delete, $current_user_id, $can_request_update); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render single document item
     */
    private function render_document_item(object $doc, int $employee_id, string $page, bool $can_delete, int $current_user_id, bool $can_request_update = false): void {
        $icon_class = Documents_Service::get_icon_class($doc->mime_type);
        $file_url = wp_get_attachment_url($doc->attachment_id);
        $file_size = size_format($doc->file_size, 1);
        $expiry = Documents_Service::get_expiry_status($doc->expiry_date);
        $has_update_request = !empty($doc->update_requested_at);

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
                    <?php if ($expiry['label']): ?>
                        <span class="sfs-hr-doc-expiry <?php echo esc_attr($expiry['class']); ?>"><?php echo esc_html($expiry['label']); ?></span>
                    <?php endif; ?>
                    <?php if ($has_update_request): ?>
                        <span class="sfs-hr-doc-update-requested" title="<?php echo esc_attr($doc->update_request_reason ?: __('Update requested by HR', 'sfs-hr')); ?>">
                            <?php esc_html_e('Update Requested', 'sfs-hr'); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="sfs-hr-doc-meta">
                    <?php echo esc_html($doc->file_name); ?> &middot; <?php echo esc_html($file_size); ?> &middot;
                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($doc->created_at))); ?>
                    <?php if ($doc->description): ?>
                        <br><em><?php echo esc_html(wp_trim_words($doc->description, 15)); ?></em>
                    <?php endif; ?>
                    <?php if ($has_update_request && $doc->update_request_reason): ?>
                        <br><strong><?php esc_html_e('Reason:', 'sfs-hr'); ?></strong> <?php echo esc_html($doc->update_request_reason); ?>
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
                <?php if ($can_request_update && !$has_update_request): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Request employee to update this document?', 'sfs-hr')); ?>');">
                        <input type="hidden" name="action" value="sfs_hr_request_document_update" />
                        <input type="hidden" name="document_id" value="<?php echo (int)$doc->id; ?>" />
                        <input type="hidden" name="employee_id" value="<?php echo (int)$employee_id; ?>" />
                        <input type="hidden" name="redirect_page" value="<?php echo esc_attr($page); ?>" />
                        <?php wp_nonce_field('sfs_hr_request_update_' . $doc->id); ?>
                        <button type="submit" class="button button-small" style="color:#2271b1;">
                            <?php esc_html_e('Request Update', 'sfs-hr'); ?>
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($can_delete): ?>
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
        <?php
    }
}
