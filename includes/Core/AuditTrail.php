<?php
/**
 * Audit Trail System
 *
 * Logs all changes to HR records including employees, leave, attendance, etc.
 * Tracks who made the change, when, and what was changed.
 *
 * @package SFS\HR\Core
 * @version 1.0.0
 */

namespace SFS\HR\Core;

defined( 'ABSPATH' ) || exit;

class AuditTrail {

    private static bool $initialized = false;
    private static string $table_name = '';

    /**
     * Initialize the audit trail system
     */
    public static function init(): void {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        global $wpdb;
        self::$table_name = $wpdb->prefix . 'sfs_hr_audit_log';

        // Create table on init
        add_action( 'init', [ self::class, 'maybe_create_table' ], 5 );

        // Hook into employee changes
        add_action( 'sfs_hr_employee_created', [ self::class, 'log_employee_created' ], 10, 2 );
        add_action( 'sfs_hr_employee_updated', [ self::class, 'log_employee_updated' ], 10, 3 );
        add_action( 'sfs_hr_employee_deleted', [ self::class, 'log_employee_deleted' ], 10, 2 );

        // Hook into leave request changes
        add_action( 'sfs_hr_leave_request_created', [ self::class, 'log_leave_created' ], 10, 2 );
        add_action( 'sfs_hr_leave_request_status_changed', [ self::class, 'log_leave_status_change' ], 10, 3 );

        // Hook into attendance changes
        add_action( 'sfs_hr_attendance_punch', [ self::class, 'log_attendance_punch' ], 10, 3 );
        add_action( 'sfs_hr_attendance_session_edited', [ self::class, 'log_session_edited' ], 10, 3 );

        // Hook into loan changes
        add_action( 'sfs_hr_loan_created', [ self::class, 'log_loan_created' ], 10, 2 );
        add_action( 'sfs_hr_loan_status_changed', [ self::class, 'log_loan_status_change' ], 10, 3 );

        // Hook into payroll changes
        add_action( 'sfs_hr_payroll_run_created', [ self::class, 'log_payroll_run' ], 10, 2 );
        add_action( 'sfs_hr_payroll_run_approved', [ self::class, 'log_payroll_approved' ], 10, 2 );

        // Hook into resignation changes
        add_action( 'sfs_hr_resignation_status_changed', [ self::class, 'log_resignation_status_change' ], 10, 3 );

        // Hook into settlement changes
        add_action( 'sfs_hr_settlement_status_changed', [ self::class, 'log_settlement_status_change' ], 10, 3 );

        // Hook into shift swap changes
        add_action( 'sfs_hr_shift_swap_status_changed', [ self::class, 'log_shift_swap_status_change' ], 10, 3 );

        // Hook into early leave changes
        add_action( 'sfs_hr_early_leave_status_changed', [ self::class, 'log_early_leave_status_change' ], 10, 3 );

        // Admin page
        if ( is_admin() ) {
            add_action( 'admin_menu', [ self::class, 'add_menu' ], 30 );
        }
    }

    /**
     * Create the audit log table
     */
    public static function maybe_create_table(): void {
        global $wpdb;

        $installed_version = get_option( 'sfs_hr_audit_db_version', '0' );
        $current_version = '1.0.0';

        if ( version_compare( $installed_version, $current_version, '>=' ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table = self::$table_name;

        dbDelta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL COMMENT 'User who made the change',
            user_name VARCHAR(100) NULL,
            user_email VARCHAR(100) NULL,
            action VARCHAR(50) NOT NULL COMMENT 'create, update, delete, status_change, etc.',
            entity_type VARCHAR(50) NOT NULL COMMENT 'employee, leave, attendance, loan, payroll, etc.',
            entity_id BIGINT UNSIGNED NULL,
            entity_name VARCHAR(255) NULL COMMENT 'Human-readable identifier',
            old_value LONGTEXT NULL COMMENT 'JSON of old values',
            new_value LONGTEXT NULL COMMENT 'JSON of new values',
            changes_summary TEXT NULL COMMENT 'Human-readable summary of changes',
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY entity_type_id (entity_type, entity_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;" );

        update_option( 'sfs_hr_audit_db_version', $current_version );
    }

    /**
     * Log an audit event
     */
    public static function log(
        string $action,
        string $entity_type,
        ?int $entity_id = null,
        ?string $entity_name = null,
        $old_value = null,
        $new_value = null,
        ?string $changes_summary = null
    ): int {
        global $wpdb;

        $user_id = get_current_user_id();
        $user = $user_id ? get_userdata( $user_id ) : null;

        $data = [
            'user_id'         => $user_id ?: null,
            'user_name'       => $user ? $user->display_name : 'System',
            'user_email'      => $user ? $user->user_email : null,
            'action'          => $action,
            'entity_type'     => $entity_type,
            'entity_id'       => $entity_id,
            'entity_name'     => $entity_name,
            'old_value'       => is_array( $old_value ) || is_object( $old_value ) ? wp_json_encode( $old_value ) : $old_value,
            'new_value'       => is_array( $new_value ) || is_object( $new_value ) ? wp_json_encode( $new_value ) : $new_value,
            'changes_summary' => $changes_summary,
            'ip_address'      => self::get_client_ip(),
            'user_agent'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : null,
            'created_at'      => current_time( 'mysql' ),
        ];

        $wpdb->insert( self::$table_name, $data );

        return (int) $wpdb->insert_id;
    }

    /**
     * Get client IP address
     */
    private static function get_client_ip(): ?string {
        $ip_keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // Handle comma-separated IPs (X-Forwarded-For)
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Compare two arrays and return changed fields
     */
    public static function get_changes( array $old, array $new ): array {
        $changes = [];

        // Check for changed/added values
        foreach ( $new as $key => $value ) {
            if ( ! isset( $old[ $key ] ) || $old[ $key ] !== $value ) {
                $changes[ $key ] = [
                    'old' => $old[ $key ] ?? null,
                    'new' => $value,
                ];
            }
        }

        // Check for removed values
        foreach ( $old as $key => $value ) {
            if ( ! isset( $new[ $key ] ) ) {
                $changes[ $key ] = [
                    'old' => $value,
                    'new' => null,
                ];
            }
        }

        return $changes;
    }

    /**
     * Generate human-readable summary of changes
     */
    public static function summarize_changes( array $changes, array $field_labels = [] ): string {
        if ( empty( $changes ) ) {
            return '';
        }

        // Fields to exclude from summary (internal/timestamps)
        $exclude_fields = [
            'id', 'created_at', 'updated_at', 'deleted_at', 'last_recalc_at',
            'calc_meta_json', 'flags_json', 'approval_chain', 'user_id',
            'photo_id', 'doc_attachment_id', 'asset_attachment_id', 'selfie_attachment_id',
        ];

        // Human-readable status labels
        $status_labels = [
            'pending'          => __( 'Pending', 'sfs-hr' ),
            'pending_gm'       => __( 'Pending GM Approval', 'sfs-hr' ),
            'pending_finance'  => __( 'Pending Finance Approval', 'sfs-hr' ),
            'pending_hr'       => __( 'Pending HR Approval', 'sfs-hr' ),
            'pending_manager'  => __( 'Pending Manager Approval', 'sfs-hr' ),
            'approved'         => __( 'Approved', 'sfs-hr' ),
            'rejected'         => __( 'Rejected', 'sfs-hr' ),
            'active'           => __( 'Active', 'sfs-hr' ),
            'inactive'         => __( 'Inactive', 'sfs-hr' ),
            'completed'        => __( 'Completed', 'sfs-hr' ),
            'cancelled'        => __( 'Cancelled', 'sfs-hr' ),
            'terminated'       => __( 'Terminated', 'sfs-hr' ),
            'present'          => __( 'Present', 'sfs-hr' ),
            'absent'           => __( 'Absent', 'sfs-hr' ),
            'late'             => __( 'Late', 'sfs-hr' ),
            'left_early'       => __( 'Left Early', 'sfs-hr' ),
            'on_leave'         => __( 'On Leave', 'sfs-hr' ),
            'day_off'          => __( 'Day Off', 'sfs-hr' ),
            'holiday'          => __( 'Holiday', 'sfs-hr' ),
        ];

        $parts = [];
        foreach ( $changes as $field => $change ) {
            // Skip excluded fields
            if ( in_array( $field, $exclude_fields, true ) ) {
                continue;
            }

            $label = $field_labels[ $field ] ?? ucfirst( str_replace( '_', ' ', $field ) );
            $old = $change['old'] ?? '';
            $new = $change['new'] ?? '';

            // Convert arrays to skip
            if ( is_array( $old ) || is_array( $new ) ) {
                continue;
            }

            // Apply status labels if this is a status field
            if ( $field === 'status' || strpos( $field, 'status' ) !== false ) {
                $old = $status_labels[ $old ] ?? ucfirst( str_replace( '_', ' ', (string) $old ) );
                $new = $status_labels[ $new ] ?? ucfirst( str_replace( '_', ' ', (string) $new ) );
            }

            // Skip if empty values
            if ( $old === '' && $new === '' ) {
                continue;
            }

            // Display format
            $old_display = $old ?: __( 'empty', 'sfs-hr' );
            $new_display = $new ?: __( 'empty', 'sfs-hr' );

            // Truncate long values
            if ( strlen( (string) $old_display ) > 40 ) $old_display = substr( (string) $old_display, 0, 37 ) . '...';
            if ( strlen( (string) $new_display ) > 40 ) $new_display = substr( (string) $new_display, 0, 37 ) . '...';

            $parts[] = "{$label}: {$old_display} → {$new_display}";
        }

        // Limit to first 3 changes to keep it concise
        if ( count( $parts ) > 3 ) {
            $more = count( $parts ) - 3;
            $parts = array_slice( $parts, 0, 3 );
            $parts[] = sprintf( __( '+%d more', 'sfs-hr' ), $more );
        }

        return implode( '; ', $parts );
    }

    // ===== Event Handlers =====

    public static function log_employee_created( int $employee_id, array $data ): void {
        $name = trim( ( $data['first_name'] ?? '' ) . ' ' . ( $data['last_name'] ?? '' ) );
        self::log(
            'create',
            'employee',
            $employee_id,
            $name ?: "Employee #{$employee_id}",
            null,
            $data,
            "Created employee: {$name}"
        );
    }

    public static function log_employee_updated( int $employee_id, array $old_data, array $new_data ): void {
        $changes = self::get_changes( (array) $old_data, (array) $new_data );
        if ( empty( $changes ) ) {
            return;
        }

        $name = trim( ( $new_data['first_name'] ?? '' ) . ' ' . ( $new_data['last_name'] ?? '' ) );
        $summary = self::summarize_changes( $changes );

        self::log(
            'update',
            'employee',
            $employee_id,
            $name ?: "Employee #{$employee_id}",
            $old_data,
            $new_data,
            $summary
        );
    }

    public static function log_employee_deleted( int $employee_id, array $data ): void {
        $name = trim( ( $data['first_name'] ?? '' ) . ' ' . ( $data['last_name'] ?? '' ) );
        self::log(
            'delete',
            'employee',
            $employee_id,
            $name ?: "Employee #{$employee_id}",
            $data,
            null,
            "Deleted employee: {$name}"
        );
    }

    public static function log_leave_created( int $request_id, array $data ): void {
        self::log(
            'create',
            'leave_request',
            $request_id,
            "Leave Request #{$request_id}",
            null,
            $data,
            sprintf( 'Created leave request: %s to %s', $data['start_date'] ?? '', $data['end_date'] ?? '' )
        );
    }

    public static function log_leave_status_change( int $request_id, string $old_status, string $new_status ): void {
        self::log(
            'status_change',
            'leave_request',
            $request_id,
            "Leave Request #{$request_id}",
            [ 'status' => $old_status ],
            [ 'status' => $new_status ],
            "Leave request status changed: {$old_status} → {$new_status}"
        );
    }

    public static function log_attendance_punch( int $employee_id, string $punch_type, array $data ): void {
        self::log(
            'punch_' . $punch_type,
            'attendance',
            $employee_id,
            "Attendance for Employee #{$employee_id}",
            null,
            $data,
            "Recorded {$punch_type} punch at " . ( $data['time'] ?? 'unknown time' )
        );
    }

    public static function log_session_edited( int $session_id, array $old_data, array $new_data ): void {
        $changes = self::get_changes( (array) $old_data, (array) $new_data );
        if ( empty( $changes ) ) {
            return;
        }

        $summary = self::summarize_changes( $changes );
        self::log(
            'update',
            'attendance_session',
            $session_id,
            "Session #{$session_id}",
            $old_data,
            $new_data,
            $summary
        );
    }

    public static function log_loan_created( int $loan_id, array $data ): void {
        self::log(
            'create',
            'loan',
            $loan_id,
            "Loan #{$loan_id}",
            null,
            $data,
            sprintf( 'Created loan request for amount: %s', $data['amount'] ?? 0 )
        );
    }

    public static function log_loan_status_change( int $loan_id, string $old_status, string $new_status ): void {
        self::log(
            'status_change',
            'loan',
            $loan_id,
            "Loan #{$loan_id}",
            [ 'status' => $old_status ],
            [ 'status' => $new_status ],
            "Loan status changed: {$old_status} → {$new_status}"
        );
    }

    public static function log_payroll_run( int $run_id, array $data ): void {
        self::log(
            'create',
            'payroll_run',
            $run_id,
            "Payroll Run #{$run_id}",
            null,
            $data,
            sprintf( 'Created payroll run for %d employees, total net: %s', $data['employee_count'] ?? 0, $data['total_net'] ?? 0 )
        );
    }

    public static function log_payroll_approved( int $run_id, array $data ): void {
        self::log(
            'approve',
            'payroll_run',
            $run_id,
            "Payroll Run #{$run_id}",
            null,
            $data,
            'Payroll run approved'
        );
    }

    public static function log_resignation_status_change( int $resignation_id, string $old_status, string $new_status ): void {
        self::log(
            'status_change',
            'resignation',
            $resignation_id,
            "Resignation #{$resignation_id}",
            [ 'status' => $old_status ],
            [ 'status' => $new_status ],
            "Resignation status changed: {$old_status} → {$new_status}"
        );
    }

    public static function log_settlement_status_change( int $settlement_id, string $old_status, string $new_status ): void {
        self::log(
            'status_change',
            'settlement',
            $settlement_id,
            "Settlement #{$settlement_id}",
            [ 'status' => $old_status ],
            [ 'status' => $new_status ],
            "Settlement status changed: {$old_status} → {$new_status}"
        );
    }

    public static function log_shift_swap_status_change( int $swap_id, string $old_status, string $new_status ): void {
        self::log(
            'status_change',
            'shift_swap',
            $swap_id,
            "Shift Swap #{$swap_id}",
            [ 'status' => $old_status ],
            [ 'status' => $new_status ],
            "Shift swap status changed: {$old_status} → {$new_status}"
        );
    }

    public static function log_early_leave_status_change( int $request_id, string $old_status, string $new_status ): void {
        self::log(
            'status_change',
            'early_leave',
            $request_id,
            "Early Leave #{$request_id}",
            [ 'status' => $old_status ],
            [ 'status' => $new_status ],
            "Early leave status changed: {$old_status} → {$new_status}"
        );
    }

    // ===== Admin Page =====

    public static function add_menu(): void {
        add_submenu_page(
            'sfs-hr',
            __( 'Audit Log', 'sfs-hr' ),
            __( 'Audit Log', 'sfs-hr' ),
            'sfs_hr.manage',
            'sfs-hr-audit-log',
            [ self::class, 'render_page' ]
        );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Access denied.', 'sfs-hr' ) );
        }

        global $wpdb;
        $table = self::$table_name;

        // Filters
        $entity_type = isset( $_GET['entity_type'] ) ? sanitize_key( $_GET['entity_type'] ) : '';
        $action_filter = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        $user_filter = isset( $_GET['user_id'] ) ? intval( $_GET['user_id'] ) : 0;
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
        $date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

        $per_page = 50;
        $page = max( 1, isset( $_GET['paged'] ) ? intval( $_GET['paged'] ) : 1 );
        $offset = ( $page - 1 ) * $per_page;

        // Build query
        $where = [];
        $args = [];

        if ( $entity_type ) {
            $where[] = 'entity_type = %s';
            $args[] = $entity_type;
        }

        if ( $action_filter ) {
            $where[] = 'action = %s';
            $args[] = $action_filter;
        }

        if ( $user_filter ) {
            $where[] = 'user_id = %d';
            $args[] = $user_filter;
        }

        if ( $date_from ) {
            $where[] = 'DATE(created_at) >= %s';
            $args[] = $date_from;
        }

        if ( $date_to ) {
            $where[] = 'DATE(created_at) <= %s';
            $args[] = $date_to;
        }

        if ( $search ) {
            $where[] = '(entity_name LIKE %s OR changes_summary LIKE %s OR user_name LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $total = $args ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : (int) $wpdb->get_var( $count_sql );

        // Get records
        $args[] = $per_page;
        $args[] = $offset;
        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $logs = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

        // Get distinct entity types and actions for filters (cached 5 min)
        $entity_types = wp_cache_get( 'sfs_hr_audit_entity_types' );
        if ( false === $entity_types ) {
            $entity_types = $wpdb->get_col( "SELECT DISTINCT entity_type FROM {$table} ORDER BY entity_type" );
            wp_cache_set( 'sfs_hr_audit_entity_types', $entity_types, '', 300 );
        }
        $actions = wp_cache_get( 'sfs_hr_audit_actions' );
        if ( false === $actions ) {
            $actions = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action" );
            wp_cache_set( 'sfs_hr_audit_actions', $actions, '', 300 );
        }

        $action_labels = [
            'create'                   => __( 'Created', 'sfs-hr' ),
            'update'                   => __( 'Updated', 'sfs-hr' ),
            'delete'                   => __( 'Deleted', 'sfs-hr' ),
            'status_change'            => __( 'Status Change', 'sfs-hr' ),
            'approve'                  => __( 'Approved', 'sfs-hr' ),
            'reject'                   => __( 'Rejected', 'sfs-hr' ),
            'punch_in'                 => __( 'Punched In', 'sfs-hr' ),
            'punch_out'                => __( 'Punched Out', 'sfs-hr' ),
            'document_uploaded'        => __( 'Document Uploaded', 'sfs-hr' ),
            'document_deleted'         => __( 'Document Deleted', 'sfs-hr' ),
            'document_replaced'        => __( 'Document Replaced', 'sfs-hr' ),
            'document_update_requested' => __( 'Update Requested', 'sfs-hr' ),
            'document_reminder_sent'   => __( 'Reminder Sent', 'sfs-hr' ),
        ];

        $entity_labels = [
            'employee'           => __( 'Employee', 'sfs-hr' ),
            'leave_request'      => __( 'Leave Request', 'sfs-hr' ),
            'attendance'         => __( 'Attendance', 'sfs-hr' ),
            'attendance_session' => __( 'Attendance Session', 'sfs-hr' ),
            'loan'               => __( 'Loan', 'sfs-hr' ),
            'payroll_run'        => __( 'Payroll Run', 'sfs-hr' ),
            'early_leave'        => __( 'Early Leave', 'sfs-hr' ),
            'resignation'        => __( 'Resignation', 'sfs-hr' ),
            'settlement'         => __( 'Settlement', 'sfs-hr' ),
            'shift_swap'         => __( 'Shift Swap', 'sfs-hr' ),
            'employee_documents' => __( 'Employee Documents', 'sfs-hr' ),
            'asset'              => __( 'Asset', 'sfs-hr' ),
            'candidate'          => __( 'Candidate', 'sfs-hr' ),
            'trainee'            => __( 'Trainee', 'sfs-hr' ),
        ];

        $base_url = admin_url( 'admin.php?page=sfs-hr-audit-log' );

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e( 'Audit Log', 'sfs-hr' ); ?></h1>
            <?php Helpers::render_admin_nav(); ?>
            <hr class="wp-header-end" />

            <!-- Filters -->
            <form method="get" style="margin:15px 0; padding:15px; background:#f9f9f9; border:1px solid #e5e5e5; border-radius:4px;">
                <input type="hidden" name="page" value="sfs-hr-audit-log" />

                <div style="display:flex; gap:15px; flex-wrap:wrap; align-items:flex-end;">
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Entity Type', 'sfs-hr' ); ?></label>
                        <select name="entity_type">
                            <option value=""><?php esc_html_e( 'All Types', 'sfs-hr' ); ?></option>
                            <?php foreach ( $entity_types as $et ): ?>
                            <option value="<?php echo esc_attr( $et ); ?>" <?php selected( $entity_type, $et ); ?>>
                                <?php echo esc_html( $entity_labels[ $et ] ?? __( ucfirst( str_replace( '_', ' ', $et ) ), 'sfs-hr' ) ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Action', 'sfs-hr' ); ?></label>
                        <select name="action">
                            <option value=""><?php esc_html_e( 'All Actions', 'sfs-hr' ); ?></option>
                            <?php foreach ( $actions as $a ): ?>
                            <option value="<?php echo esc_attr( $a ); ?>" <?php selected( $action_filter, $a ); ?>>
                                <?php echo esc_html( $action_labels[ $a ] ?? __( ucfirst( str_replace( '_', ' ', $a ) ), 'sfs-hr' ) ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'From', 'sfs-hr' ); ?></label>
                        <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
                    </div>

                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'To', 'sfs-hr' ); ?></label>
                        <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
                    </div>

                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e( 'Search', 'sfs-hr' ); ?></label>
                        <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search...', 'sfs-hr' ); ?>" />
                    </div>

                    <div>
                        <button type="submit" class="button"><?php esc_html_e( 'Filter', 'sfs-hr' ); ?></button>
                        <a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Reset', 'sfs-hr' ); ?></a>
                    </div>
                </div>
            </form>

            <!-- Results -->
            <p class="description"><?php printf( esc_html__( 'Showing %d of %d entries', 'sfs-hr' ), count( $logs ), $total ); ?></p>

            <style>
                .sfs-hr-audit-table {
                    background: #fff;
                    border: 1px solid #dcdcde;
                    border-radius: 6px;
                    margin-top: 16px;
                }
                .sfs-hr-audit-table table {
                    border: none;
                    border-radius: 6px;
                    margin: 0;
                    border-collapse: collapse;
                    width: 100%;
                }
                .sfs-hr-audit-table th {
                    background: #f8f9fa;
                    font-weight: 600;
                    font-size: 12px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    color: #50575e;
                    padding: 12px 16px;
                    text-align: start;
                    border-bottom: 1px solid #dcdcde;
                }
                .sfs-hr-audit-table td {
                    padding: 14px 16px;
                    vertical-align: middle;
                    border-bottom: 1px solid #f0f0f1;
                }
                .sfs-hr-audit-table tbody tr:last-child td {
                    border-bottom: none;
                }
                .sfs-hr-audit-table tbody tr:hover {
                    background: #f8f9fa;
                }
                .sfs-hr-audit-user {
                    font-weight: 500;
                    color: #1d2327;
                }
                .sfs-hr-audit-entity {
                    color: #1d2327;
                }
                .sfs-hr-audit-entity-id {
                    font-size: 12px;
                    color: #646970;
                }
                .hide-mobile { }
                @media (max-width: 782px) {
                    .sfs-hr-audit-table {
                        margin: 16px -12px;
                        border-radius: 0;
                        border-left: none;
                        border-right: none;
                    }
                    .sfs-hr-audit-table th,
                    .sfs-hr-audit-table td {
                        padding: 12px;
                    }
                    .hide-mobile {
                        display: none !important;
                    }
                }
            </style>

            <?php if ( empty( $logs ) ): ?>
            <div class="notice notice-info">
                <p><?php esc_html_e( 'No audit log entries found.', 'sfs-hr' ); ?></p>
            </div>
            <?php else: ?>
            <div class="sfs-hr-audit-table">
            <table>
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Time', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'User', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Entity', 'sfs-hr' ); ?></th>
                        <th class="hide-mobile"><?php esc_html_e( 'Details', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $logs as $log ):
                        $action_label = $action_labels[ $log->action ] ?? __( ucfirst( str_replace( '_', ' ', $log->action ) ), 'sfs-hr' );
                        $entity_label = $entity_labels[ $log->entity_type ] ?? __( ucfirst( str_replace( '_', ' ', $log->entity_type ) ), 'sfs-hr' );

                        $action_colors = [
                            'create' => '#00a32a',
                            'update' => '#0073aa',
                            'delete' => '#d9534f',
                            'status_change' => '#f0ad4e',
                            'approve' => '#00a32a',
                            'reject' => '#d9534f',
                        ];
                        $action_color = $action_colors[ $log->action ] ?? '#333';
                    ?>
                    <tr>
                        <td>
                            <?php echo esc_html( date_i18n( 'M j,', strtotime( $log->created_at ) ) ); ?><br>
                            <small style="color:#646970;"><?php echo esc_html( date_i18n( 'H:i', strtotime( $log->created_at ) ) ); ?></small>
                        </td>
                        <td>
                            <div class="sfs-hr-audit-user"><?php echo esc_html( $log->user_name ?: 'System' ); ?></div>
                        </td>
                        <td>
                            <span style="color:<?php echo esc_attr( $action_color ); ?>; font-weight:600;">
                                <?php echo esc_html( $action_label ); ?>
                            </span>
                        </td>
                        <td>
                            <div class="sfs-hr-audit-entity"><?php echo esc_html( $entity_label ); ?></div>
                            <?php if ( $log->entity_id ): ?>
                            <div class="sfs-hr-audit-entity-id">#<?php echo intval( $log->entity_id ); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="hide-mobile">
                            <?php if ( $log->changes_summary ): ?>
                            <small><?php echo esc_html( $log->changes_summary ); ?></small>
                            <?php elseif ( $log->entity_name ): ?>
                            <small><?php echo esc_html( $log->entity_name ); ?></small>
                            <?php else: ?>
                            <small style="color:#999;">—</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- .sfs-hr-audit-table -->

            <!-- Pagination -->
            <?php
            $total_pages = ceil( $total / $per_page );
            if ( $total_pages > 1 ):
                $pagination_args = [];
                if ( $entity_type ) $pagination_args['entity_type'] = $entity_type;
                if ( $action_filter ) $pagination_args['action'] = $action_filter;
                if ( $date_from ) $pagination_args['date_from'] = $date_from;
                if ( $date_to ) $pagination_args['date_to'] = $date_to;
                if ( $search ) $pagination_args['s'] = $search;
            ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php printf( esc_html__( '%d items', 'sfs-hr' ), $total ); ?></span>
                    <span class="pagination-links">
                        <?php if ( $page > 1 ): ?>
                        <a class="prev-page button" href="<?php echo esc_url( add_query_arg( array_merge( $pagination_args, [ 'paged' => $page - 1 ] ), $base_url ) ); ?>">‹</a>
                        <?php endif; ?>
                        <span class="paging-input">
                            <?php echo intval( $page ); ?> <?php esc_html_e( 'of', 'sfs-hr' ); ?> <?php echo intval( $total_pages ); ?>
                        </span>
                        <?php if ( $page < $total_pages ): ?>
                        <a class="next-page button" href="<?php echo esc_url( add_query_arg( array_merge( $pagination_args, [ 'paged' => $page + 1 ] ), $base_url ) ); ?>">›</a>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
