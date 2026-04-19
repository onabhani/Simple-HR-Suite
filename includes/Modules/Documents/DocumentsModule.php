<?php
namespace SFS\HR\Modules\Documents;

if (!defined('ABSPATH')) { exit; }

// Load submodules
require_once __DIR__ . '/Services/class-documents-service.php';
require_once __DIR__ . '/Services/class-version-service.php';
require_once __DIR__ . '/Services/class-compliance-service.php';
require_once __DIR__ . '/Services/class-template-service.php';
require_once __DIR__ . '/Admin/class-documents-tab.php';
require_once __DIR__ . '/Handlers/class-documents-handlers.php';
require_once __DIR__ . '/Rest/class-documents-rest.php';

use SFS\HR\Modules\Documents\Services\Documents_Service;
use SFS\HR\Modules\Documents\Services\Version_Service;
use SFS\HR\Modules\Documents\Services\Compliance_Service;
use SFS\HR\Modules\Documents\Services\Template_Service;
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
 * Version: 1.0.0
 * Author: hdqah.com
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

        // M6: Daily cron — flag expired documents + send expiry notifications
        add_action('sfs_hr_daily_document_compliance', [self::class, 'run_daily_compliance']);
        if ( ! wp_next_scheduled('sfs_hr_daily_document_compliance') ) {
            wp_schedule_event(time(), 'daily', 'sfs_hr_daily_document_compliance');
        }
    }

    /**
     * Daily cron callback: flag expired docs and send expiry notifications.
     */
    public static function run_daily_compliance(): void {
        Compliance_Service::flag_expired_documents();
        Compliance_Service::send_expiry_notifications(30);
        Compliance_Service::send_expiry_notifications(15);
        Compliance_Service::send_expiry_notifications(7);
    }

    /**
     * Install documents table if not exists
     */
    public function maybe_install_tables(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $charset_collate = $wpdb->get_charset_collate();

        $table_exists = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

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

        // M6.1: Document versions table
        $versions_table = $wpdb->prefix . 'sfs_hr_document_versions';
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$versions_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            document_id BIGINT(20) UNSIGNED NOT NULL,
            version_number INT UNSIGNED NOT NULL,
            attachment_id BIGINT(20) UNSIGNED NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size BIGINT(20) UNSIGNED DEFAULT 0,
            mime_type VARCHAR(100) DEFAULT '',
            uploaded_by BIGINT(20) UNSIGNED NOT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY document_id (document_id),
            KEY doc_version (document_id, version_number)
        ) {$charset_collate}");

        // M6.3: Document letter templates table
        $templates_table = $wpdb->prefix . 'sfs_hr_document_templates';
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$templates_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key VARCHAR(50) NOT NULL,
            name_en VARCHAR(255) NOT NULL,
            name_ar VARCHAR(255) NOT NULL,
            body_en LONGTEXT NOT NULL,
            body_ar LONGTEXT NOT NULL,
            category ENUM('certificate','letter','notice','contract') DEFAULT 'letter',
            is_active TINYINT(1) DEFAULT 1,
            created_by BIGINT(20) UNSIGNED NULL,
            updated_by BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_template_key (template_key),
            KEY category (category),
            KEY is_active (is_active)
        ) {$charset_collate}");

        // M6.3: Seed default letter templates (idempotent)
        Template_Service::seed_default_templates();
    }

    /**
     * Add update request columns if they don't exist (migration for existing installs)
     */
    private function maybe_add_update_request_columns(string $table): void {
        global $wpdb;

        $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'update_requested_at'));

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
