<?php
namespace SFS\HR\Modules\Documents\Handlers;

use SFS\HR\Modules\Documents\Services\Documents_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Documents Handlers
 * Handle form submissions for document upload/delete
 */
class Documents_Handlers {

    /**
     * Register hooks
     */
    public function hooks(): void {
        add_action('admin_post_sfs_hr_upload_document', [$this, 'handle_upload']);
        add_action('admin_post_sfs_hr_delete_document', [$this, 'handle_delete']);
        add_action('wp_ajax_sfs_hr_upload_document', [$this, 'ajax_upload']);
    }

    /**
     * Handle document upload
     */
    public function handle_upload(): void {
        $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
        $redirect_page = isset($_POST['redirect_page']) ? sanitize_key($_POST['redirect_page']) : 'sfs-hr-my-profile';

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_upload_document_' . $employee_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        // Validate employee
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$emp_table} WHERE id = %d",
            $employee_id
        ));

        if (!$employee) {
            wp_die(esc_html__('Employee not found.', 'sfs-hr'));
        }

        // Check permissions
        $current_user_id = get_current_user_id();
        if ((int)$employee->user_id !== $current_user_id && !current_user_can('sfs_hr.manage')) {
            wp_die(esc_html__('You do not have permission to upload documents for this employee.', 'sfs-hr'));
        }

        // Validate inputs
        $document_type = isset($_POST['document_type']) ? sanitize_key($_POST['document_type']) : '';
        $document_name = isset($_POST['document_name']) ? sanitize_text_field($_POST['document_name']) : '';
        $expiry_date = isset($_POST['expiry_date']) ? sanitize_text_field($_POST['expiry_date']) : null;
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';

        if (empty($document_type) || empty($document_name)) {
            $this->redirect_error($employee_id, $redirect_page, __('Document type and name are required.', 'sfs-hr'));
            return;
        }

        // Validate file
        if (empty($_FILES['document_file']['name'])) {
            $this->redirect_error($employee_id, $redirect_page, __('Please select a file to upload.', 'sfs-hr'));
            return;
        }

        $validation_errors = Documents_Service::validate_upload($_FILES['document_file']);
        if (!empty($validation_errors)) {
            $this->redirect_error($employee_id, $redirect_page, implode(' ', $validation_errors));
            return;
        }

        // Get MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $_FILES['document_file']['tmp_name']);
        finfo_close($finfo);

        // Upload file via WordPress
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $attachment_id = media_handle_upload('document_file', 0);

        if (is_wp_error($attachment_id)) {
            $this->redirect_error($employee_id, $redirect_page, $attachment_id->get_error_message());
            return;
        }

        // Create document record
        $doc_id = Documents_Service::create_document([
            'employee_id'   => $employee_id,
            'document_type' => $document_type,
            'document_name' => $document_name,
            'description'   => $description,
            'attachment_id' => $attachment_id,
            'file_name'     => $_FILES['document_file']['name'],
            'file_size'     => $_FILES['document_file']['size'],
            'mime_type'     => $mime_type,
            'expiry_date'   => $expiry_date,
            'uploaded_by'   => $current_user_id,
        ]);

        // Log action
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'document_uploaded',
                'employee_documents',
                $doc_id,
                null,
                [
                    'employee_id'   => $employee_id,
                    'document_type' => $document_type,
                    'document_name' => $document_name,
                ]
            );
        }

        $this->redirect_success($employee_id, $redirect_page, __('Document uploaded successfully.', 'sfs-hr'));
    }

    /**
     * Handle document deletion
     */
    public function handle_delete(): void {
        $document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
        $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
        $redirect_page = isset($_POST['redirect_page']) ? sanitize_key($_POST['redirect_page']) : 'sfs-hr-my-profile';

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_delete_document_' . $document_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        // Get document
        $document = Documents_Service::get_document($document_id);
        if (!$document) {
            wp_die(esc_html__('Document not found.', 'sfs-hr'));
        }

        // Check permissions
        $current_user_id = get_current_user_id();
        $can_delete = ((int)$document->uploaded_by === $current_user_id) || current_user_can('sfs_hr.manage');

        if (!$can_delete) {
            wp_die(esc_html__('You do not have permission to delete this document.', 'sfs-hr'));
        }

        // Archive document
        Documents_Service::archive_document($document_id);

        // Log action
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'document_deleted',
                'employee_documents',
                $document_id,
                ['status' => 'active'],
                ['status' => 'archived']
            );
        }

        $this->redirect_success($employee_id, $redirect_page, __('Document deleted.', 'sfs-hr'));
    }

    /**
     * AJAX upload handler
     */
    public function ajax_upload(): void {
        // Similar to handle_upload but returns JSON
        check_ajax_referer('sfs_hr_ajax_upload', 'nonce');

        // ... implement if needed
        wp_send_json_error(['message' => 'Not implemented']);
    }

    /**
     * Redirect with error message
     */
    private function redirect_error(int $employee_id, string $page, string $message): void {
        if ($page === 'sfs-hr-my-profile') {
            $url = admin_url('admin.php?page=sfs-hr-my-profile&tab=documents&error=' . rawurlencode($message));
        } else {
            $url = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $employee_id . '&tab=documents&error=' . rawurlencode($message));
        }
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Redirect with success message
     */
    private function redirect_success(int $employee_id, string $page, string $message): void {
        if ($page === 'sfs-hr-my-profile') {
            $url = admin_url('admin.php?page=sfs-hr-my-profile&tab=documents&success=' . rawurlencode($message));
        } else {
            $url = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $employee_id . '&tab=documents&success=' . rawurlencode($message));
        }
        wp_safe_redirect($url);
        exit;
    }
}
