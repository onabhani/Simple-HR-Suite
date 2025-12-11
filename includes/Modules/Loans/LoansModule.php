<?php
namespace SFS\HR\Modules\Loans;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Loans Module (Cash Advances)
 *
 * Features:
 * - Cash advances with zero interest
 * - Monthly salary deductions
 * - Approval workflow: Employee → GM → Finance
 * - Schedule generation with skip capability
 * - Full audit trail
 */
class LoansModule {

    const OPT_SETTINGS = 'sfs_hr_loans_settings';

    public function __construct() {
        // Load Notifications class
        require_once __DIR__ . '/class-notifications.php';

        // Admin pages
        if ( is_admin() ) {
            require_once __DIR__ . '/Admin/class-admin-pages.php';
            new Admin\AdminPages();

            // Dashboard Widget
            require_once __DIR__ . '/Admin/class-dashboard-widget.php';
            new Admin\DashboardWidget();

            // Frontend: My Profile Loans tab
            require_once __DIR__ . '/Frontend/class-my-profile-loans.php';
            new Frontend\MyProfileLoans();
        }

        // Activation hook for DB
        register_activation_hook( SFS_HR_PLUGIN_FILE, [ __CLASS__, 'on_activation' ] );
    }

    /**
     * Create/update database tables on activation
     */
    public static function on_activation(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';
        $history_table = $wpdb->prefix . 'sfs_hr_loan_history';

        // Check if loans table exists and if loan_number column exists
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$loans_table}'" ) === $loans_table;

        if ( $table_exists ) {
            // Check if loan_number column exists
            $column_exists = $wpdb->get_results(
                "SHOW COLUMNS FROM {$loans_table} LIKE 'loan_number'"
            );

            // Add loan_number column if it doesn't exist
            if ( empty( $column_exists ) ) {
                $wpdb->query(
                    "ALTER TABLE {$loans_table}
                    ADD COLUMN loan_number varchar(50) NOT NULL AFTER id,
                    ADD UNIQUE KEY loan_number (loan_number)"
                );
            }
        }

        // 1. Loans table
        $sql_loans = "CREATE TABLE {$loans_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            loan_number varchar(50) NOT NULL,
            employee_id bigint(20) UNSIGNED NOT NULL,
            department varchar(100) DEFAULT NULL,
            principal_amount decimal(12,2) NOT NULL,
            currency varchar(10) DEFAULT 'SAR',
            installments_count int(10) UNSIGNED NOT NULL,
            installment_amount decimal(12,2) NOT NULL,
            first_due_date date DEFAULT NULL,
            last_due_date date DEFAULT NULL,
            remaining_balance decimal(12,2) NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'pending_gm',
            reason text DEFAULT NULL,
            internal_notes text DEFAULT NULL,
            request_source varchar(20) DEFAULT 'employee_portal',
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            approved_gm_by bigint(20) UNSIGNED DEFAULT NULL,
            approved_gm_at datetime DEFAULT NULL,
            approved_finance_by bigint(20) UNSIGNED DEFAULT NULL,
            approved_finance_at datetime DEFAULT NULL,
            rejected_by bigint(20) UNSIGNED DEFAULT NULL,
            rejected_at datetime DEFAULT NULL,
            rejection_reason text DEFAULT NULL,
            cancelled_by bigint(20) UNSIGNED DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY loan_number (loan_number),
            KEY employee_id (employee_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // 2. Loan payments (schedule & actuals)
        $sql_payments = "CREATE TABLE {$payments_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            loan_id bigint(20) UNSIGNED NOT NULL,
            sequence int(10) UNSIGNED NOT NULL,
            due_date date NOT NULL,
            amount_planned decimal(12,2) NOT NULL,
            amount_paid decimal(12,2) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'planned',
            paid_at datetime DEFAULT NULL,
            source varchar(20) DEFAULT 'payroll',
            notes text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY loan_id (loan_id),
            KEY due_date (due_date),
            KEY status (status)
        ) {$charset_collate};";

        // 3. Loan history (audit trail)
        $sql_history = "CREATE TABLE {$history_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            loan_id bigint(20) UNSIGNED NOT NULL,
            created_at datetime NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            event_type varchar(50) NOT NULL,
            meta longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY loan_id (loan_id),
            KEY created_at (created_at),
            KEY event_type (event_type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql_loans );
        dbDelta( $sql_payments );
        dbDelta( $sql_history );
    }

    /**
     * Get default settings
     */
    public static function get_default_settings(): array {
        return [
            'enabled'                          => true,
            'max_loan_amount'                  => 0, // 0 = no limit
            'max_loan_multiplier'              => 0, // 0 = disabled, e.g., 2.0 = 2x basic salary
            'max_installment_amount'           => 0,
            'max_installment_percent'          => 0, // e.g., 30 = 30% of basic salary
            'loan_start_offset_months'         => 2, // Start deductions N months after request
            'allow_multiple_active_loans'      => false,
            'max_active_loans_per_employee'    => 1,
            'require_gm_approval'              => true,
            'gm_user_ids'                      => [], // Array of user IDs who can approve as GM
            'require_finance_approval'         => true,
            'finance_user_ids'                 => [], // Array of user IDs who can approve as Finance
            'allow_early_repayment'            => true,
            'early_repayment_requires_approval'=> true,
            'show_in_my_profile'               => true,
            'allow_employee_requests'          => true,
            // Email notifications
            'enable_notifications'             => true,
            'notify_gm_new_request'            => true,
            'notify_finance_gm_approved'       => true,
            'notify_employee_approved'         => true,
            'notify_employee_rejected'         => true,
            'notify_employee_installment_skipped' => true,
        ];
    }

    /**
     * Get current settings
     */
    public static function get_settings(): array {
        $defaults = self::get_default_settings();
        $saved    = get_option( self::OPT_SETTINGS, [] );
        return wp_parse_args( $saved, $defaults );
    }

    /**
     * Generate unique loan number
     */
    public static function generate_loan_number(): string {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_loans';
        $year  = wp_date( 'Y' );

        // Get count for this year
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE loan_number LIKE %s",
                'LN-' . $year . '-%'
            )
        );

        $sequence = str_pad( $count + 1, 4, '0', STR_PAD_LEFT );
        return 'LN-' . $year . '-' . $sequence;
    }

    /**
     * Log loan event to history
     */
    public static function log_event( int $loan_id, string $event_type, array $meta = [] ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_loan_history';

        $wpdb->insert( $table, [
            'loan_id'    => $loan_id,
            'created_at' => current_time( 'mysql' ),
            'user_id'    => get_current_user_id(),
            'event_type' => $event_type,
            'meta'       => wp_json_encode( $meta ),
        ] );
    }

    /**
     * Check if employee has active loans
     */
    public static function has_active_loans( int $employee_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_loans';

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE employee_id = %d
             AND status = 'active'
             AND remaining_balance > 0",
            $employee_id
        ) );

        return $count > 0;
    }

    /**
     * Get outstanding balance for employee
     */
    public static function get_outstanding_balance( int $employee_id ): float {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_loans';

        $balance = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(remaining_balance) FROM {$table}
             WHERE employee_id = %d
             AND status = 'active'",
            $employee_id
        ) );

        return (float) ( $balance ?? 0 );
    }

    /**
     * Check if current user can approve as GM
     */
    public static function current_user_can_approve_as_gm(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        // If user has full manage capability, they can approve
        if ( current_user_can( 'sfs_hr.manage' ) ) {
            $settings = self::get_settings();
            $gm_user_ids = $settings['gm_user_ids'] ?? [];

            // If no GMs assigned, any manager can approve
            if ( empty( $gm_user_ids ) ) {
                return true;
            }

            // Check if current user is in the GM list
            return in_array( get_current_user_id(), $gm_user_ids, true );
        }

        return false;
    }

    /**
     * Check if current user can approve as Finance
     */
    public static function current_user_can_approve_as_finance(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        // If user has full manage capability, they can approve
        if ( current_user_can( 'sfs_hr.manage' ) ) {
            $settings = self::get_settings();
            $finance_user_ids = $settings['finance_user_ids'] ?? [];

            // If no Finance users assigned, any manager can approve
            if ( empty( $finance_user_ids ) ) {
                return true;
            }

            // Check if current user is in the Finance list
            return in_array( get_current_user_id(), $finance_user_ids, true );
        }

        return false;
    }

    /**
     * Get assigned GM users
     */
    public static function get_gm_users(): array {
        $settings = self::get_settings();
        $gm_user_ids = $settings['gm_user_ids'] ?? [];

        if ( empty( $gm_user_ids ) ) {
            return [];
        }

        $users = [];
        foreach ( $gm_user_ids as $user_id ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * Get assigned Finance users
     */
    public static function get_finance_users(): array {
        $settings = self::get_settings();
        $finance_user_ids = $settings['finance_user_ids'] ?? [];

        if ( empty( $finance_user_ids ) ) {
            return [];
        }

        $users = [];
        foreach ( $finance_user_ids as $user_id ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                $users[] = $user;
            }
        }

        return $users;
    }
}