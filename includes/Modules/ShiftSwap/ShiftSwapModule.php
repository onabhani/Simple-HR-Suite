<?php
namespace SFS\HR\Modules\ShiftSwap;

if (!defined('ABSPATH')) { exit; }

// Load submodules
require_once __DIR__ . '/Services/class-shiftswap-service.php';
require_once __DIR__ . '/Admin/class-shiftswap-tab.php';
require_once __DIR__ . '/Handlers/class-shiftswap-handlers.php';
require_once __DIR__ . '/Rest/class-shiftswap-rest.php';
require_once __DIR__ . '/Notifications/class-shiftswap-notifications.php';

use SFS\HR\Modules\ShiftSwap\Services\ShiftSwap_Service;
use SFS\HR\Modules\ShiftSwap\Admin\ShiftSwap_Tab;
use SFS\HR\Modules\ShiftSwap\Handlers\ShiftSwap_Handlers;
use SFS\HR\Modules\ShiftSwap\Rest\ShiftSwap_Rest;
use SFS\HR\Modules\ShiftSwap\Notifications\ShiftSwap_Notifications;

/**
 * Shift Swap Module
 * Allows employees to request shift swaps with colleagues
 *
 * Structure:
 * - Services/       Business logic and data access
 * - Admin/          Employee self-service tab UI
 * - Handlers/       Form submission handlers
 * - Rest/           REST API endpoints
 * - Notifications/  Email notifications
 *
 * Version: 0.2.0
 * Author: Omar Alnabhani (hdqah.com)
 */
class ShiftSwapModule {

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
        (new ShiftSwap_Tab())->hooks();
        (new ShiftSwap_Handlers())->hooks();
        (new ShiftSwap_Rest())->hooks();
        (new ShiftSwap_Notifications())->hooks();
    }

    /**
     * Install shift_swaps table
     */
    public function maybe_install_tables(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $charset_collate = $wpdb->get_charset_collate();

        $table_exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $table
        ));

        if (!$table_exists) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                request_number VARCHAR(50) NULL COMMENT 'Reference number like SS-2026-0001',
                requester_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Employee requesting the swap',
                requester_shift_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Shift assignment of requester',
                requester_date DATE NOT NULL,
                target_id BIGINT(20) UNSIGNED NOT NULL COMMENT 'Employee being asked to swap',
                target_shift_id BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'Shift assignment of target (optional)',
                target_date DATE NOT NULL,
                reason TEXT,
                status ENUM('pending','accepted','declined','cancelled','manager_pending','approved','rejected') DEFAULT 'pending',
                target_responded_at DATETIME DEFAULT NULL,
                manager_id BIGINT(20) UNSIGNED DEFAULT NULL,
                manager_responded_at DATETIME DEFAULT NULL,
                manager_note TEXT,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY request_number (request_number),
                KEY requester_id (requester_id),
                KEY target_id (target_id),
                KEY status (status),
                KEY requester_date (requester_date),
                KEY target_date (target_date)
            ) {$charset_collate}");
        }

        // Add request_number column if missing (for existing tables)
        self::add_column_if_missing($wpdb, $table, 'request_number', "request_number VARCHAR(50) NULL COMMENT 'Reference number like SS-2026-0001' AFTER id");
        self::add_unique_key_if_missing($wpdb, $table, 'request_number');
        self::backfill_shift_swap_request_numbers($wpdb);
    }

    /**
     * Add column if missing
     */
    private static function add_column_if_missing(\wpdb $wpdb, string $table, string $col, string $ddl): void {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
        if (!$exists) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
        }
    }

    /**
     * Add unique key if missing
     */
    private static function add_unique_key_if_missing(\wpdb $wpdb, string $table, string $key_name): void {
        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
            $table, $key_name
        ));
        if ((int)$index_exists === 0) {
            $col_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $key_name));
            if ($col_exists) {
                $wpdb->query("ALTER TABLE `$table` ADD UNIQUE KEY `$key_name` (`$key_name`)");
            }
        }
    }

    /**
     * Generate reference number for shift swap requests
     */
    public static function generate_shift_swap_request_number(): string {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $year = wp_date('Y');

        $count = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `$table` WHERE request_number LIKE %s",
                'SS-' . $year . '-%'
            )
        );

        $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
        return 'SS-' . $year . '-' . $sequence;
    }

    /**
     * Backfill reference numbers for existing shift swap requests
     */
    private static function backfill_shift_swap_request_numbers(\wpdb $wpdb): void {
        $table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        $missing = $wpdb->get_results(
            "SELECT id, created_at FROM `$table` WHERE request_number IS NULL OR request_number = '' ORDER BY id ASC"
        );
        foreach ($missing as $row) {
            $year = $row->created_at ? date('Y', strtotime($row->created_at)) : wp_date('Y');
            $count = (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `$table` WHERE request_number LIKE %s",
                    'SS-' . $year . '-%'
                )
            );
            $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
            $number = 'SS-' . $year . '-' . $sequence;
            $wpdb->update($table, ['request_number' => $number], ['id' => $row->id]);
        }
    }

    // =========================================================================
    // Backwards compatibility - delegate to Services class
    // =========================================================================

    /**
     * @deprecated Use ShiftSwap_Service::get_pending_count() instead
     */
    public static function get_pending_count(): int {
        return ShiftSwap_Service::get_pending_count();
    }

    /**
     * @deprecated Use ShiftSwap_Service::get_status_labels() instead
     */
    public static function get_status_labels(): array {
        return ShiftSwap_Service::get_status_labels();
    }

    /**
     * @deprecated Use ShiftSwap_Service::get_status_colors() instead
     */
    public static function get_status_colors(): array {
        return ShiftSwap_Service::get_status_colors();
    }
}
