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
        add_action('admin_post_sfs_hr_request_document_update', [$this, 'handle_request_update']);
        add_action('admin_post_sfs_hr_send_document_reminder', [$this, 'handle_send_reminder']);
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
        $is_hr_admin = current_user_can('sfs_hr.manage');
        $is_self_upload = ((int)$employee->user_id === $current_user_id);

        if (!$is_self_upload && !$is_hr_admin) {
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

        // Employee upload restrictions (HR/admin can always upload)
        $existing_doc = null;
        if ($is_self_upload && !$is_hr_admin) {
            $check = Documents_Service::can_employee_upload_document_type($employee_id, $document_type);
            if (!$check['allowed']) {
                $this->redirect_error($employee_id, $redirect_page, __('You cannot upload this document type. A valid document already exists. Contact HR if you need to update it.', 'sfs-hr'));
                return;
            }
            $existing_doc = $check['existing_doc'];
        } else {
            // HR/admin: check if there's an existing document to archive
            $existing_doc = Documents_Service::get_active_document_of_type($employee_id, $document_type);
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

        // If replacing an existing document, archive the old one first
        if ($existing_doc) {
            Documents_Service::archive_document($existing_doc->id);

            // Log the replacement
            if (class_exists('\SFS\HR\Core\AuditTrail')) {
                \SFS\HR\Core\AuditTrail::log(
                    'document_replaced',
                    'employee_documents',
                    $existing_doc->id,
                    ['status' => 'active'],
                    ['status' => 'archived', 'replaced_by_upload' => true]
                );
            }
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
                    'replaced_existing' => $existing_doc ? true : false,
                ]
            );
        }

        $message = $existing_doc
            ? __('Document updated successfully.', 'sfs-hr')
            : __('Document uploaded successfully.', 'sfs-hr');

        $this->redirect_success($employee_id, $redirect_page, $message);
    }

    /**
     * Handle document deletion
     * Only HR/Admin can delete documents - employees cannot delete their own documents
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

        // Only HR/Admin can delete documents
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(esc_html__('You do not have permission to delete documents. Only HR administrators can delete documents.', 'sfs-hr'));
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
     * Handle document update request (HR/Admin action)
     * This allows HR to flag a document for the employee to update
     */
    public function handle_request_update(): void {
        $document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
        $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
        $redirect_page = isset($_POST['redirect_page']) ? sanitize_key($_POST['redirect_page']) : 'sfs-hr-employee-profile';
        $reason = isset($_POST['update_reason']) ? sanitize_text_field($_POST['update_reason']) : '';

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_request_update_' . $document_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        // Only HR/Admin can request updates
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(esc_html__('You do not have permission to request document updates.', 'sfs-hr'));
        }

        // Get document
        $document = Documents_Service::get_document($document_id);
        if (!$document) {
            wp_die(esc_html__('Document not found.', 'sfs-hr'));
        }

        // Request update
        $current_user_id = get_current_user_id();
        $result = Documents_Service::request_document_update($document_id, $current_user_id, $reason);

        if (!$result) {
            $this->redirect_error($employee_id, $redirect_page, __('Failed to request document update.', 'sfs-hr'));
            return;
        }

        // Log action
        if (class_exists('\SFS\HR\Core\AuditTrail')) {
            \SFS\HR\Core\AuditTrail::log(
                'document_update_requested',
                'employee_documents',
                $document_id,
                ['update_requested_at' => null],
                [
                    'update_requested_at' => current_time('mysql'),
                    'update_requested_by' => $current_user_id,
                    'reason' => $reason,
                ]
            );
        }

        $this->redirect_success($employee_id, $redirect_page, __('Update request sent. Employee can now upload a new version.', 'sfs-hr'));
    }

    /**
     * Handle sending document reminder to employee
     */
    public function handle_send_reminder(): void {
        $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
        $custom_message = isset($_POST['custom_message']) ? sanitize_text_field($_POST['custom_message']) : '';

        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_send_document_reminder_' . $employee_id)) {
            wp_die(esc_html__('Security check failed.', 'sfs-hr'));
        }

        // Only HR/Admin can send reminders
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(esc_html__('You do not have permission to send document reminders.', 'sfs-hr'));
        }

        // Get missing documents
        $missing = Documents_Service::get_missing_required_documents($employee_id);

        if (empty($missing)) {
            $this->redirect_error($employee_id, 'sfs-hr-employee-profile', __('No missing documents to remind about.', 'sfs-hr'));
            return;
        }

        // Send reminder
        $result = Documents_Service::send_missing_documents_reminder($employee_id, $missing, $custom_message);

        if ($result) {
            // Log action
            if (class_exists('\SFS\HR\Core\AuditTrail')) {
                \SFS\HR\Core\AuditTrail::log(
                    'document_reminder_sent',
                    'employees',
                    $employee_id,
                    null,
                    [
                        'missing_types' => array_keys($missing),
                        'custom_message' => $custom_message,
                    ]
                );
            }

            $this->redirect_success($employee_id, 'sfs-hr-employee-profile', __('Reminder email sent to employee.', 'sfs-hr'));
        } else {
            $this->redirect_error($employee_id, 'sfs-hr-employee-profile', __('Failed to send reminder. Employee may not have an email address.', 'sfs-hr'));
        }
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
        $url = $this->get_redirect_url($employee_id, $page, 'error', $message);
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Redirect with success message
     */
    private function redirect_success(int $employee_id, string $page, string $message): void {
        $url = $this->get_redirect_url($employee_id, $page, 'success', $message);
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Get redirect URL based on context (admin vs frontend)
     */
    private function get_redirect_url(int $employee_id, string $page, string $type, string $message): string {
        // Check if request came from frontend (referer check)
        $referer = wp_get_referer();
        $is_frontend = $referer && strpos($referer, admin_url()) === false;

        if ($is_frontend && $referer) {
            // Frontend: redirect back to referer with documents tab
            $parsed = wp_parse_url($referer);
            $base_url = $parsed['scheme'] . '://' . $parsed['host'] . ($parsed['path'] ?? '');
            return add_query_arg([
                'sfs_hr_tab' => 'documents',
                $type => rawurlencode($message),
            ], $base_url);
        }

        // Admin pages
        if ($page === 'sfs-hr-my-profile') {
            return admin_url('admin.php?page=sfs-hr-my-profile&tab=documents&' . $type . '=' . rawurlencode($message));
        }

        return admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . $employee_id . '&tab=documents&' . $type . '=' . rawurlencode($message));
    }
}
