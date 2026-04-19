<?php
namespace SFS\HR\Modules\Documents\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Version_Service
 *
 * Manages document version history for employee documents.
 * Each upload creates a new version row; the parent document always
 * points to the latest file. Supports revert, history browsing,
 * and lazy initialization for pre-existing documents.
 *
 * @since M6.1
 */
class Version_Service {

    /**
     * Table name for document versions.
     */
    private static function versions_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sfs_hr_document_versions';
    }

    /**
     * Table name for employee documents.
     */
    private static function documents_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'sfs_hr_employee_documents';
    }

    /**
     * Upload a new version of a document.
     *
     * @param int    $document_id Parent document ID.
     * @param array  $file_data   Must contain: attachment_id, file_name, file_size, mime_type.
     * @param int    $uploaded_by WP user ID performing the upload.
     * @param string $notes       Optional note for this version.
     * @return array ['ok' => bool, 'version_id' => int, 'version_number' => int] or ['ok' => false, 'error' => string]
     */
    public static function upload_new_version( int $document_id, array $file_data, int $uploaded_by, string $notes = '' ): array {
        global $wpdb;

        $documents_table = self::documents_table();
        $versions_table  = self::versions_table();

        // Validate document exists.
        $document = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$documents_table} WHERE id = %d",
            $document_id
        ) );

        if ( ! $document ) {
            return [ 'ok' => false, 'error' => __( 'Document not found.', 'sfs-hr' ) ];
        }

        // Validate required file_data keys.
        $required_keys = [ 'attachment_id', 'file_name', 'file_size', 'mime_type' ];
        foreach ( $required_keys as $key ) {
            if ( ! isset( $file_data[ $key ] ) ) {
                return [ 'ok' => false, 'error' => sprintf( __( 'Missing required file data: %s', 'sfs-hr' ), $key ) ];
            }
        }

        // Get next version number.
        $max_version = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(version_number) FROM {$versions_table} WHERE document_id = %d",
            $document_id
        ) );
        $next_version = $max_version + 1;

        $now = current_time( 'mysql', true );

        // Insert version row.
        $inserted = $wpdb->insert(
            $versions_table,
            [
                'document_id'    => $document_id,
                'version_number' => $next_version,
                'attachment_id'  => (int) $file_data['attachment_id'],
                'file_name'      => sanitize_file_name( $file_data['file_name'] ),
                'file_size'      => (int) $file_data['file_size'],
                'mime_type'      => sanitize_mime_type( $file_data['mime_type'] ),
                'uploaded_by'    => $uploaded_by,
                'notes'          => $notes,
                'created_at'     => $now,
            ],
            [ '%d', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'error' => __( 'Failed to insert version record.', 'sfs-hr' ) ];
        }

        $version_id = (int) $wpdb->insert_id;

        // Update parent document to point to new file.
        $wpdb->update(
            $documents_table,
            [
                'attachment_id'         => (int) $file_data['attachment_id'],
                'file_name'             => sanitize_file_name( $file_data['file_name'] ),
                'file_size'             => (int) $file_data['file_size'],
                'mime_type'             => sanitize_mime_type( $file_data['mime_type'] ),
                'updated_at'            => $now,
                'update_requested_at'   => null,
                'update_requested_by'   => null,
                'update_request_reason' => null,
            ],
            [ 'id' => $document_id ],
            [ '%d', '%s', '%d', '%s', '%s', null, null, null ],
            [ '%d' ]
        );

        return [
            'ok'             => true,
            'version_id'     => $version_id,
            'version_number' => $next_version,
        ];
    }

    /**
     * Get full version history for a document, newest first.
     *
     * @param int $document_id Parent document ID.
     * @return array List of version objects with uploader display_name.
     */
    public static function get_version_history( int $document_id ): array {
        global $wpdb;

        $versions_table = self::versions_table();
        $users_table    = $wpdb->users;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT v.*, u.display_name AS uploaded_by_name
             FROM {$versions_table} v
             LEFT JOIN {$users_table} u ON u.ID = v.uploaded_by
             WHERE v.document_id = %d
             ORDER BY v.version_number DESC",
            $document_id
        ) );

        return $results ?: [];
    }

    /**
     * Get a single version row by ID.
     *
     * @param int $version_id Version row ID.
     * @return object|null
     */
    public static function get_version( int $version_id ): ?object {
        global $wpdb;

        $versions_table = self::versions_table();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$versions_table} WHERE id = %d",
            $version_id
        ) );

        return $row ?: null;
    }

    /**
     * Get the latest (highest version_number) version for a document.
     *
     * @param int $document_id Parent document ID.
     * @return object|null
     */
    public static function get_latest_version( int $document_id ): ?object {
        global $wpdb;

        $versions_table = self::versions_table();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$versions_table}
             WHERE document_id = %d
             ORDER BY version_number DESC
             LIMIT 1",
            $document_id
        ) );

        return $row ?: null;
    }

    /**
     * Revert a document to a previous version.
     *
     * Creates a NEW version (next number) with the old version's file info,
     * then updates the parent document to point to it.
     *
     * @param int $document_id Parent document ID.
     * @param int $version_id  Version to revert to.
     * @param int $reverted_by WP user ID performing the revert.
     * @return array ['ok' => bool, ...] or ['ok' => false, 'error' => string]
     */
    public static function revert_to_version( int $document_id, int $version_id, int $reverted_by ): array {
        global $wpdb;

        // Get the target version and validate it belongs to this document.
        $version = self::get_version( $version_id );

        if ( ! $version ) {
            return [ 'ok' => false, 'error' => __( 'Version not found.', 'sfs-hr' ) ];
        }

        if ( (int) $version->document_id !== $document_id ) {
            return [ 'ok' => false, 'error' => __( 'Version does not belong to this document.', 'sfs-hr' ) ];
        }

        // Create a new version using the old version's file data.
        $file_data = [
            'attachment_id' => (int) $version->attachment_id,
            'file_name'     => $version->file_name,
            'file_size'     => (int) $version->file_size,
            'mime_type'     => $version->mime_type,
        ];

        $notes = sprintf(
            __( 'Reverted to version %d', 'sfs-hr' ),
            (int) $version->version_number
        );

        return self::upload_new_version( $document_id, $file_data, $reverted_by, $notes );
    }

    /**
     * Delete a version row.
     *
     * Constraints:
     * - Cannot delete if it is the only version.
     * - Cannot delete the current (latest) version — must revert first.
     * - WP attachment is preserved for safety.
     *
     * @param int $version_id Version row ID.
     * @return array ['ok' => bool] or ['ok' => false, 'error' => string]
     */
    public static function delete_version( int $version_id ): array {
        global $wpdb;

        $versions_table = self::versions_table();

        $version = self::get_version( $version_id );

        if ( ! $version ) {
            return [ 'ok' => false, 'error' => __( 'Version not found.', 'sfs-hr' ) ];
        }

        $document_id = (int) $version->document_id;

        // Cannot delete if only version.
        $count = self::get_version_count( $document_id );
        if ( $count <= 1 ) {
            return [ 'ok' => false, 'error' => __( 'Cannot delete the only version of a document.', 'sfs-hr' ) ];
        }

        // Cannot delete the latest version.
        $latest = self::get_latest_version( $document_id );
        if ( $latest && (int) $latest->id === $version_id ) {
            return [ 'ok' => false, 'error' => __( 'Cannot delete the current version. Revert to a previous version first.', 'sfs-hr' ) ];
        }

        // Remove the row (soft-delete — attachment preserved).
        $deleted = $wpdb->delete(
            $versions_table,
            [ 'id' => $version_id ],
            [ '%d' ]
        );

        if ( ! $deleted ) {
            return [ 'ok' => false, 'error' => __( 'Failed to delete version.', 'sfs-hr' ) ];
        }

        return [ 'ok' => true ];
    }

    /**
     * Get total version count for a document.
     *
     * @param int $document_id Parent document ID.
     * @return int
     */
    public static function get_version_count( int $document_id ): int {
        global $wpdb;

        $versions_table = self::versions_table();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$versions_table} WHERE document_id = %d",
            $document_id
        ) );
    }

    /**
     * Initialize version 1 for a document that existed before versioning was added.
     *
     * Idempotent: if a version already exists for this document, returns early.
     *
     * @param int $document_id Parent document ID.
     * @return array ['ok' => bool, ...] or ['ok' => false, 'error' => string]
     */
    public static function initialize_version_for_existing_document( int $document_id ): array {
        global $wpdb;

        $documents_table = self::documents_table();
        $versions_table  = self::versions_table();

        // Idempotent: skip if version already exists.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$versions_table} WHERE document_id = %d",
            $document_id
        ) );

        if ( (int) $existing > 0 ) {
            return [ 'ok' => true, 'skipped' => true ];
        }

        // Get parent document.
        $document = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$documents_table} WHERE id = %d",
            $document_id
        ) );

        if ( ! $document ) {
            return [ 'ok' => false, 'error' => __( 'Document not found.', 'sfs-hr' ) ];
        }

        if ( empty( $document->attachment_id ) ) {
            return [ 'ok' => false, 'error' => __( 'Document has no attachment to version.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $versions_table,
            [
                'document_id'    => $document_id,
                'version_number' => 1,
                'attachment_id'  => (int) $document->attachment_id,
                'file_name'      => $document->file_name ?? '',
                'file_size'      => (int) ( $document->file_size ?? 0 ),
                'mime_type'      => $document->mime_type ?? '',
                'uploaded_by'    => (int) ( $document->uploaded_by ?? 0 ),
                'notes'          => __( 'Initial version (migrated)', 'sfs-hr' ),
                'created_at'     => $document->created_at ?? $now,
            ],
            [ '%d', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'error' => __( 'Failed to create initial version record.', 'sfs-hr' ) ];
        }

        return [
            'ok'             => true,
            'version_id'     => (int) $wpdb->insert_id,
            'version_number' => 1,
        ];
    }
}
