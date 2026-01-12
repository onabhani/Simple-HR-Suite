<?php
namespace SFS\HR\Modules\Documents\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Documents Service
 * Business logic and helper functions for employee documents
 */
class Documents_Service {

    /**
     * Get document types with labels
     */
    public static function get_document_types(): array {
        return apply_filters('sfs_hr_document_types', [
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
        ]);
    }

    /**
     * Get documents for an employee
     */
    public static function get_employee_documents(int $employee_id, string $status = 'active'): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE employee_id = %d AND status = %s ORDER BY created_at DESC",
            $employee_id,
            $status
        ));
    }

    /**
     * Get documents grouped by type
     */
    public static function get_documents_grouped(int $employee_id): array {
        $documents = self::get_employee_documents($employee_id);
        $grouped = [];

        foreach ($documents as $doc) {
            $grouped[$doc->document_type][] = $doc;
        }

        return $grouped;
    }

    /**
     * Get document count for an employee
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
     * Get expiring documents
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

    /**
     * Get expired documents
     */
    public static function get_expired_documents(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $today = wp_date('Y-m-d');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, e.first_name, e.last_name, e.employee_code
             FROM {$table} d
             JOIN {$emp_table} e ON e.id = d.employee_id
             WHERE d.status = 'active'
               AND d.expiry_date IS NOT NULL
               AND d.expiry_date < %s
             ORDER BY d.expiry_date ASC",
            $today
        ));
    }

    /**
     * Get document by ID
     */
    public static function get_document(int $document_id): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $document_id
        ));
    }

    /**
     * Create a document record
     */
    public static function create_document(array $data): int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $now = current_time('mysql');

        $wpdb->insert($table, [
            'employee_id'   => $data['employee_id'],
            'document_type' => $data['document_type'],
            'document_name' => $data['document_name'],
            'description'   => $data['description'] ?? '',
            'attachment_id' => $data['attachment_id'],
            'file_name'     => $data['file_name'],
            'file_size'     => $data['file_size'] ?? 0,
            'mime_type'     => $data['mime_type'] ?? '',
            'expiry_date'   => $data['expiry_date'] ?: null,
            'uploaded_by'   => $data['uploaded_by'],
            'status'        => 'active',
            'created_at'    => $now,
            'updated_at'    => $now,
        ], [
            '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s'
        ]);

        return $wpdb->insert_id;
    }

    /**
     * Archive (soft delete) a document
     */
    public static function archive_document(int $document_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employee_documents';

        $result = $wpdb->update(
            $table,
            ['status' => 'archived', 'updated_at' => current_time('mysql')],
            ['id' => $document_id],
            ['%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Get allowed MIME types for uploads
     */
    public static function get_allowed_mime_types(): array {
        return apply_filters('sfs_hr_document_allowed_mimes', [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Get max file size for uploads (in bytes)
     */
    public static function get_max_file_size(): int {
        return apply_filters('sfs_hr_document_max_size', 10 * 1024 * 1024); // 10MB
    }

    /**
     * Validate upload file
     */
    public static function validate_upload(array $file): array {
        $errors = [];

        // Check file exists
        if (empty($file['name'])) {
            $errors[] = __('Please select a file to upload.', 'sfs-hr');
            return $errors;
        }

        // Check file size
        if ($file['size'] > self::get_max_file_size()) {
            $errors[] = __('File size exceeds limit.', 'sfs-hr');
        }

        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, self::get_allowed_mime_types(), true)) {
            $errors[] = __('Invalid file type. Allowed: PDF, Images, Word, Excel.', 'sfs-hr');
        }

        return $errors;
    }

    /**
     * Get expiry status for a document
     */
    public static function get_expiry_status(?string $expiry_date): array {
        if (!$expiry_date) {
            return ['status' => 'none', 'label' => '', 'class' => ''];
        }

        $expiry_ts = strtotime($expiry_date);
        $today_ts = strtotime(wp_date('Y-m-d'));
        $days_until = ($expiry_ts - $today_ts) / 86400;

        if ($days_until < 0) {
            return [
                'status' => 'expired',
                'label'  => __('Expired', 'sfs-hr'),
                'class'  => 'expired',
            ];
        } elseif ($days_until <= 30) {
            return [
                'status' => 'expiring_soon',
                'label'  => sprintf(__('Expires in %d days', 'sfs-hr'), (int)$days_until),
                'class'  => 'expiring-soon',
            ];
        } else {
            return [
                'status' => 'valid',
                'label'  => sprintf(__('Expires %s', 'sfs-hr'), date_i18n(get_option('date_format'), $expiry_ts)),
                'class'  => 'valid',
            ];
        }
    }

    /**
     * Get icon class for MIME type
     */
    public static function get_icon_class(string $mime_type): string {
        if (strpos($mime_type, 'pdf') !== false) {
            return 'pdf';
        } elseif (strpos($mime_type, 'image') !== false) {
            return 'image';
        } elseif (strpos($mime_type, 'word') !== false || strpos($mime_type, 'document') !== false) {
            return 'doc';
        }
        return 'other';
    }
}
