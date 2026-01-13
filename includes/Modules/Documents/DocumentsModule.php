<?php
namespace SFS\HR\Modules\Documents;

if (!defined('ABSPATH')) { exit; }

// Load submodules
require_once __DIR__ . '/Services/class-documents-service.php';
require_once __DIR__ . '/Admin/class-documents-tab.php';
require_once __DIR__ . '/Handlers/class-documents-handlers.php';
require_once __DIR__ . '/Rest/class-documents-rest.php';

use SFS\HR\Modules\Documents\Services\Documents_Service;
use SFS\HR\Modules\Documents\Admin\Documents_Tab;
use SFS\HR\Modules\Documents\Handlers\Documents_Handlers;
use SFS\HR\Modules\Documents\Rest\Documents_Rest;

/**
 * Documents Module
 * Employee document management (ID copies, certificates, contracts, etc.)
 *
 * Structure:
 * - Services/   Business logic and helpers
 * - Admin/      Admin UI (tabs, pages)
 * - Handlers/   Form handlers (upload, delete)
 * - Rest/       REST API endpoints
 *
 * Version: 0.2.0
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

    /**
     * Register all hooks
     */
    public function hooks(): void {
        // Install tables
        add_action('admin_init', [$this, 'maybe_install_tables']);

        // Initialize submodules
        (new Documents_Tab())->hooks();
        (new Documents_Handlers())->hooks();

        // REST API
        add_action('rest_api_init', [Documents_Rest::class, 'register']);
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
                update_requested_at DATETIME DEFAULT NULL,
                update_requested_by BIGINT(20) UNSIGNED DEFAULT NULL,
                update_request_reason VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY employee_id (employee_id),
                KEY document_type (document_type),
                KEY status (status),
                KEY expiry_date (expiry_date)
            ) {$charset_collate}");
        } else {
            // Add update_requested columns if they don't exist (migration)
            $this->maybe_add_update_request_columns($table);
        }
    }

    /**
     * Add update request columns if they don't exist (migration for existing installs)
     */
    private function maybe_add_update_request_columns(string $table): void {
        global $wpdb;

        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'update_requested_at'",
            $table
        ));

        if (!$column_exists) {
            $wpdb->query("ALTER TABLE {$table}
                ADD COLUMN update_requested_at DATETIME DEFAULT NULL AFTER status,
                ADD COLUMN update_requested_by BIGINT(20) UNSIGNED DEFAULT NULL AFTER update_requested_at,
                ADD COLUMN update_request_reason VARCHAR(255) DEFAULT NULL AFTER update_requested_by
            ");
        }
    }

    // =========================================================================
    // Backwards compatibility - delegate to Services class
    // =========================================================================

    /**
     * @deprecated Use Documents_Service::get_document_types() instead
     */
    public static function get_document_types(): array {
        return Documents_Service::get_document_types();
    }

    /**
     * @deprecated Use Documents_Service::get_document_count() instead
     */
    public static function get_document_count(int $employee_id): int {
        return Documents_Service::get_document_count($employee_id);
    }

    /**
     * @deprecated Use Documents_Service::get_expiring_documents() instead
     */
    public static function get_expiring_documents(int $days_ahead = 30): array {
        return Documents_Service::get_expiring_documents($days_ahead);
    }
}
