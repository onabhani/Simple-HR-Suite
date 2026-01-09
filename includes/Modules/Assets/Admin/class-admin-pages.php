<?php
namespace SFS\HR\Modules\Assets\Admin;

if ( ! defined('ABSPATH') ) { exit; }

class Admin_Pages {

    public function hooks(): void {
        add_action('admin_menu', [ $this, 'menu' ], 35);

        // Asset create/update
        add_action('admin_post_sfs_hr_assets_save',            [ $this, 'handle_asset_save' ]);

        // Assignment create (manager)
        add_action('admin_post_sfs_hr_assets_assign',          [ $this, 'handle_assign' ]);

        // Employee decision for new assignment (approve / reject)
        add_action('admin_post_sfs_hr_assets_assign_decision', [ $this, 'handle_assign_decision' ]);

        // Manager requests return
        add_action('admin_post_sfs_hr_assets_return_request',  [ $this, 'handle_return_request' ]);

        // Employee approves return
        add_action('admin_post_sfs_hr_assets_return_decision', [ $this, 'handle_return_decision' ]);

        // Export / Import
        add_action('admin_post_sfs_hr_assets_export', [ $this, 'handle_assets_export' ]);
        add_action('admin_post_sfs_hr_assets_import', [ $this, 'handle_assets_import' ]);
    }

    public function menu(): void {
        // HR root slug from your core Admin::menu()
        $parent_slug = 'sfs-hr';

        // Single submenu only: "Assets"
        add_submenu_page(
            $parent_slug,
            __('Assets', 'sfs-hr'),
            __('Assets', 'sfs-hr'),
            'sfs_hr.manage',
            'sfs-hr-assets',
            [ $this, 'render_assets' ]
        );
    }

    /**
     * Main Assets screen – uses internal tabs:
     * - ?page=sfs-hr-assets&tab=assets
     * - ?page=sfs-hr-assets&tab=assignments
     */
    public function render_assets(): void {
        if ( ! current_user_can('sfs_hr.manage') ) {
            wp_die(__('Access denied', 'sfs-hr'));
        }

        $tab  = isset($_GET['tab'])  ? sanitize_key($_GET['tab'])  : 'assets';
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'list';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Assets', 'sfs-hr'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <?php
                $base_url        = remove_query_arg( ['tab','view','id'] );
                $assets_url      = add_query_arg( ['page' => 'sfs-hr-assets', 'tab' => 'assets'], $base_url );
                $assignments_url = add_query_arg( ['page' => 'sfs-hr-assets', 'tab' => 'assignments'], $base_url );

                $assets_class      = ( $tab === 'assets' ) ? 'nav-tab nav-tab-active' : 'nav-tab';
                $assignments_class = ( $tab === 'assignments' ) ? 'nav-tab nav-tab-active' : 'nav-tab';
                ?>
                <a href="<?php echo esc_url( $assets_url ); ?>" class="<?php echo esc_attr( $assets_class ); ?>">
                    <?php esc_html_e('Assets', 'sfs-hr'); ?>
                </a>
                <a href="<?php echo esc_url( $assignments_url ); ?>" class="<?php echo esc_attr( $assignments_class ); ?>">
                    <?php esc_html_e('Assignments', 'sfs-hr'); ?>
                </a>
            </h2>
        <?php

        if ( $tab === 'assignments' ) {
            $this->render_assignments_list();
        } else {
            if ( $view === 'edit' ) {
                $this->render_asset_edit();
            } else {
                $this->render_asset_list();
            }
        }

        echo '</div>';
    }

    private function render_asset_list(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_assets';

        $status     = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $category   = isset( $_GET['category'] ) ? sanitize_key( $_GET['category'] ) : '';
        $department = isset( $_GET['dept'] ) ? sanitize_text_field( wp_unslash( $_GET['dept'] ) ) : '';

        $conditions = [];
        $values     = [];

        if ( $status !== '' ) {
            $conditions[] = 'status = %s';
            $values[]     = $status;
        }

        if ( $category !== '' ) {
            $conditions[] = 'category = %s';
            $values[]     = $category;
        }

        if ( $department !== '' ) {
            $conditions[] = 'department = %s';
            $values[]     = $department;
        }

        $where_sql = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';

        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT 200";

        $rows = $values
            ? $wpdb->get_results( $wpdb->prepare( $sql, $values ) )
            : $wpdb->get_results( $sql );

        require __DIR__ . '/../Views/assets-list.php';
    }

    private function render_asset_edit(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_assets';

        $id   = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $row  = null;

        if ( $id > 0 ) {
            $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id) );
        }

        require __DIR__ . '/../Views/assets-edit.php';
    }

    private function render_assignments_list(): void {
        if ( ! current_user_can('sfs_hr.manage')
             && ! current_user_can('sfs_hr_assets_admin')
             && ! current_user_can('sfs_hr_manager') ) {
            wp_die( __('Access denied', 'sfs-hr') );
        }

        global $wpdb;

        $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
        $assets_table = $wpdb->prefix . 'sfs_hr_assets';
        $emp_table    = $wpdb->prefix . 'sfs_hr_employees';

        $status_filter   = isset($_GET['asset_status']) ? sanitize_key( $_GET['asset_status'] ) : '';
        $employee_filter = isset($_GET['emp']) ? (int) $_GET['emp'] : 0;

        $conditions = [];
        $values     = [];

        if ( $status_filter !== '' ) {
            $conditions[] = 'a.status = %s';
            $values[]     = $status_filter;
        }

        if ( $employee_filter > 0 ) {
            $conditions[] = 'a.employee_id = %d';
            $values[]     = $employee_filter;
        }

        $where_sql = '';
        if ( $conditions ) {
            $where_sql = 'WHERE ' . implode( ' AND ', $conditions );
        }

        $sql = "
            SELECT a.*,
                   ast.name AS asset_name,
                   ast.asset_code,
                   TRIM(CONCAT(
                        COALESCE(emp.first_name, ''),
                        ' ',
                        COALESCE(emp.last_name, '')
                   )) AS employee_name
            FROM {$assign_table} a
            LEFT JOIN {$assets_table} ast ON ast.id = a.asset_id
            LEFT JOIN {$emp_table}    emp ON emp.id = a.employee_id
            {$where_sql}
            ORDER BY a.created_at DESC
            LIMIT 200
        ";

        if ( $values ) {
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
        } else {
            $rows = $wpdb->get_results( $sql );
        }

        require __DIR__ . '/../Views/assignments-list.php';
    }

    private function generate_qr_url_for_asset( int $asset_id, string $asset_code ): string {
        // 1) Resolve upload paths
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            return '';
        }

        $subdir    = 'sfs-hr/assets-qr';
        $base_dir  = trailingslashit( $upload['basedir'] ) . $subdir . '/';
        $base_url  = trailingslashit( $upload['baseurl'] ) . $subdir . '/';

        // 2) Ensure directory exists
        if ( ! wp_mkdir_p( $base_dir ) ) {
            return '';
        }

        // 3) Deterministic filename per asset
        $filename = 'asset-' . $asset_id . '.png';
        $filepath = $base_dir . $filename;
        $fileurl  = $base_url . $filename;

        // 4) Payload: where the QR points (asset edit screen)
        $qr_payload = add_query_arg(
            [
                'page' => 'sfs-hr-assets',
                'view' => 'edit',
                'id'   => $asset_id,
            ],
            admin_url( 'admin.php' )
        );

        // 5) Load QR library from Gravity Forms
        if ( ! class_exists( '\QRcode' ) ) {
            if ( defined( 'GF_PLUGIN_DIR_PATH' ) ) {
                $gf_qr_lib = trailingslashit( GF_PLUGIN_DIR_PATH ) . 'includes/phpqrcode/phpqrcode.php';
                if ( file_exists( $gf_qr_lib ) ) {
                    require_once $gf_qr_lib;
                }
            }
        }

        // If still no library, bail (don’t pretend success)
        if ( ! class_exists( '\QRcode' ) ) {
            return '';
        }

        // 6) Generate QR PNG into file (no output to browser)
        // @phpstan-ignore-next-line
        \QRcode::png( $qr_payload, $filepath, QR_ECLEVEL_L, 4 );

        // 7) Double-check file exists
        if ( ! file_exists( $filepath ) ) {
            return '';
        }

        // Return public URL to store in qr_code_path
        return $fileurl;
    }

    public function handle_assign(): void {
    if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'sfs_hr_assets_admin' ) ) {
        wp_die( __( 'Access denied.', 'sfs-hr' ) );
    }

    check_admin_referer( 'sfs_hr_assets_assign' );

    $asset_id    = isset( $_POST['asset_id'] ) ? (int) $_POST['asset_id'] : 0;
    $employee_id = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;
    $start_date  = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : '';
    $notes       = isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : '';

    // Debug logging
    error_log( 'handle_assign called: asset_id=' . $asset_id . ', employee_id=' . $employee_id );

    if ( $asset_id <= 0 || $employee_id <= 0 || $start_date === '' ) {
        wp_die( __( 'Asset, employee and start date are required.', 'sfs-hr' ) );
    }

    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
        wp_die( __( 'Invalid start date.', 'sfs-hr' ) );
    }

    global $wpdb;
    $assign_table    = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $assets_table    = $wpdb->prefix . 'sfs_hr_assets';
    $employees_table = $wpdb->prefix . 'sfs_hr_employees';

    // ---- Check if asset already has an active assignment ----
    $current_active = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) 
         FROM {$assign_table}
         WHERE asset_id = %d
           AND status IN ('pending_employee_approval', 'active', 'return_requested')",
        $asset_id
    ) );

    error_log( 'Current active assignments: ' . $current_active );

    if ( $current_active > 0 ) {
        error_log( 'Asset already active, redirecting with error' );
        $redirect = add_query_arg(
            [
                'page'          => 'sfs-hr-assets',
                'tab'           => 'assignments',
                'asset_error'   => 'already_active',
                'asset_id'      => $asset_id,
                'employee_id'   => $employee_id,
            ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    $now          = current_time( 'mysql' );
    $current_user = get_current_user_id();

    error_log( 'Inserting assignment record' );

    // Insert assignment
    $inserted = $wpdb->insert(
        $assign_table,
        [
            'asset_id'    => $asset_id,
            'employee_id' => $employee_id,
            'start_date'  => $start_date,
            'status'      => 'pending_employee_approval',
            'notes'       => $notes,
            'assigned_by' => $current_user ?: null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ],
        [
            '%d', // asset_id
            '%d', // employee_id
            '%s', // start_date
            '%s', // status
            '%s', // notes
            '%d', // assigned_by
            '%s', // created_at
            '%s', // updated_at
        ]
    );

    if ( ! $inserted ) {
        error_log( 'Insert failed: ' . $wpdb->last_error );
        wp_die(
            'Failed to create assignment. DB error: ' . esc_html( $wpdb->last_error )
        );
    }

    $assignment_id = (int) $wpdb->insert_id;
    error_log( 'Assignment created with ID: ' . $assignment_id );

    // Update asset status
    $updated = $wpdb->update(
        $assets_table,
        [
            'status'     => 'pending_employee_approval',
            'updated_at' => $now,
        ],
        [ 'id' => $asset_id ],
        [ '%s', '%s' ],
        [ '%d' ]
    );

    error_log( 'Asset update result: ' . ( $updated !== false ? 'success' : 'failed' ) );

    // Audit log
    if ( method_exists( $this, 'log_asset_event' ) ) {
        $this->log_asset_event(
            'assignment_created',
            $asset_id,
            $assignment_id,
            [
                'employee_id' => $employee_id,
                'start_date'  => $start_date,
                'assigned_by' => $current_user ?: null,
            ]
        );
    }
    
    // Fire event hook
    if ( method_exists( $this, 'fire_asset_event' ) ) {
        $this->fire_asset_event( 'assignment_created', [
            'assignment_id' => $assignment_id,
            'asset_id'      => $asset_id,
            'employee_id'   => $employee_id,
            'assigned_by'   => $current_user ?: null,
            'start_date'    => $start_date,
        ] );
    }

    // ================== NOTIFICATION: asset assigned ==================
    error_log( 'Preparing notification email' );
    
    // Fetch asset info
    $asset_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT name, asset_code FROM {$assets_table} WHERE id = %d LIMIT 1",
            $asset_id
        )
    );

    $asset_name = ( $asset_row && isset( $asset_row->name ) )
        ? (string) $asset_row->name
        : '';
    $asset_code = ( $asset_row && isset( $asset_row->asset_code ) )
        ? (string) $asset_row->asset_code
        : '';

    // Get employee -> user_id
    $emp_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT user_id FROM {$employees_table} WHERE id = %d LIMIT 1",
            $employee_id
        )
    );

    if ( $emp_row && ! empty( $emp_row->user_id ) ) {
        $user = get_userdata( (int) $emp_row->user_id );
        if ( $user && ! empty( $user->user_email ) ) {

            $title_asset = $asset_name !== '' ? $asset_name : $asset_code;

            $subject = sprintf(
                __( 'New asset assigned: %s', 'sfs-hr' ),
                $title_asset !== '' ? $title_asset : __( 'Asset', 'sfs-hr' )
            );

            $body = sprintf(
                __( "You have been assigned a new asset.\n\nAsset: %s\nCode: %s\nStart date: %s\n\nPlease log in to your HR portal to review and approve the assignment.", 'sfs-hr' ),
                $asset_name !== '' ? $asset_name : '-',
                $asset_code !== '' ? $asset_code : '-',
                $start_date
            );

            $mail_sent = wp_mail( $user->user_email, $subject, $body );
            error_log( 'Email sent to ' . $user->user_email . ': ' . ( $mail_sent ? 'success' : 'failed' ) );
        } else {
            error_log( 'User not found or no email for user_id: ' . $emp_row->user_id );
        }
    } else {
        error_log( 'Employee record not found for employee_id: ' . $employee_id );
    }
    // ================================================================

    error_log( 'Preparing final redirect' );

    $redirect = add_query_arg(
        [
            'page'         => 'sfs-hr-assets',
            'tab'          => 'assignments',
            'assigned'     => 1,
            'asset_id'     => $asset_id,
            'employee_id'  => $employee_id,
        ],
        admin_url( 'admin.php' )
    );

    error_log( 'Redirecting to: ' . $redirect );

    wp_safe_redirect( $redirect );
    exit;
}


    public function handle_assign_decision(): void {
    if ( ! is_user_logged_in() ) {
        wp_die( esc_html__( 'You must be logged in.', 'sfs-hr' ) );
    }

    $assignment_id = isset( $_POST['assignment_id'] ) ? (int) $_POST['assignment_id'] : 0;
    $decision      = isset( $_POST['decision'] ) ? sanitize_key( $_POST['decision'] ) : '';

    if ( ! $assignment_id || ! in_array( $decision, [ 'approve', 'reject' ], true ) ) {
        wp_die( esc_html__( 'Invalid request.', 'sfs-hr' ) );
    }

    check_admin_referer( 'sfs_hr_assets_assign_decision_' . $assignment_id );

    global $wpdb;
    $assign_table    = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $assets_table    = $wpdb->prefix . 'sfs_hr_assets';
    $employees_table = $wpdb->prefix . 'sfs_hr_employees';

    // Load assignment
    $assignment = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$assign_table} WHERE id = %d LIMIT 1",
            $assignment_id
        )
    );

    if ( ! $assignment ) {
        wp_die( esc_html__( 'Assignment not found.', 'sfs-hr' ) );
    }

    if ( $assignment->status !== 'pending_employee_approval' ) {
        wp_die( esc_html__( 'This assignment is not awaiting employee approval.', 'sfs-hr' ) );
    }

    // Resolve employee for current user
    $current_user_id = get_current_user_id();
    $employee        = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$employees_table} WHERE user_id = %d LIMIT 1",
            $current_user_id
        )
    );

    if ( ! $employee ) {
        wp_die( esc_html__( 'You are not linked to an employee record.', 'sfs-hr' ) );
    }

    if ( (int) $employee->id !== (int) $assignment->employee_id ) {
        wp_die( esc_html__( 'You are not allowed to approve this assignment.', 'sfs-hr' ) );
    }

    $now      = current_time( 'mysql' );
    $asset_id = (int) $assignment->asset_id;
    $emp_id   = (int) $employee->id;
    $user_id  = (int) $current_user_id;

    // ---------- Preload asset + employee display info for notifications ----------
    $asset_name    = '';
    $asset_code    = '';
    $asset_title   = '';
    $employee_name = '';

    // Asset info
    $asset_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT name, asset_code FROM {$assets_table} WHERE id = %d LIMIT 1",
            $asset_id
        )
    );
    if ( $asset_row ) {
        $asset_name  = isset( $asset_row->name ) ? (string) $asset_row->name : '';
        $asset_code  = isset( $asset_row->asset_code ) ? (string) $asset_row->asset_code : '';
        $asset_title = $asset_name !== '' ? $asset_name : $asset_code;
    }

    // Employee display name
    $employee_user = get_userdata( $user_id );
    if ( $employee_user && ! empty( $employee_user->display_name ) ) {
        $employee_name = $employee_user->display_name;
    } else {
        $employee_name = sprintf( __( 'Employee #%d', 'sfs-hr' ), $emp_id );
    }
    // ---------------------------------------------------------------------------

    if ( $decision === 'approve' ) {

        // ---- Double-safety: check for other active/pending assignments for same asset ----
        $conflict = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) 
                 FROM {$assign_table}
                 WHERE asset_id = %d
                   AND id <> %d
                   AND status IN ('pending_employee_approval','active','return_requested')",
                $asset_id,
                $assignment_id
            )
        );

        if ( $conflict > 0 ) {
            wp_die(
                esc_html__(
                    'This asset is already assigned or pending for another employee. Please contact HR.',
                    'sfs-hr'
                )
            );
        }

        // --------- Photos from modal ---------
        $selfie_data = isset( $_POST['selfie_data'] ) ? trim( (string) $_POST['selfie_data'] ) : '';
        $asset_data  = isset( $_POST['asset_data'] )  ? trim( (string) $_POST['asset_data'] )  : '';

        $selfie_id    = 0;
        $asset_img_id = 0;

        if ( $selfie_data && strpos( $selfie_data, 'data:image' ) === 0 && method_exists( $this, 'save_data_url_attachment' ) ) {
            $selfie_id = $this->save_data_url_attachment(
                $selfie_data,
                'asset-assignment-' . $assignment_id . '-selfie'
            );
        }

        if ( $asset_data && strpos( $asset_data, 'data:image' ) === 0 && method_exists( $this, 'save_data_url_attachment' ) ) {
            $asset_img_id = $this->save_data_url_attachment(
                $asset_data,
                'asset-assignment-' . $assignment_id . '-asset'
            );
        }

        // ---- Check if photo columns actually exist ----
        $can_store_photos = false;
        $cols             = $wpdb->get_col( "SHOW COLUMNS FROM {$assign_table}", 0 );
        if ( is_array( $cols ) ) {
            if ( in_array( 'selfie_attachment_id', $cols, true ) && in_array( 'asset_attachment_id', $cols, true ) ) {
                $can_store_photos = true;
            }
        }

        // Set assignment active (+ photos only if columns exist)
        $update_data = [
            'status'     => 'active',
            'updated_at' => $now,
        ];
        $update_fmt  = [ '%s', '%s' ];

        if ( $can_store_photos && $selfie_id ) {
            $update_data['selfie_attachment_id'] = $selfie_id;
            $update_fmt[] = '%d';
        }
        if ( $can_store_photos && $asset_img_id ) {
            $update_data['asset_attachment_id'] = $asset_img_id;
            $update_fmt[] = '%d';
        }

        $updated = $wpdb->update(
            $assign_table,
            $update_data,
            [ 'id' => $assignment_id ],
            $update_fmt,
            [ '%d' ]
        );

        // Fallback: if update failed for ANY reason, at least set status like the old version
        if ( $updated === false ) {
            $wpdb->update(
                $assign_table,
                [
                    'status'     => 'active',
                    'updated_at' => $now,
                ],
                [ 'id' => $assignment_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }

        // Asset now "assigned"
        $wpdb->update(
            $assets_table,
            [
                'status'     => 'assigned',
                'updated_at' => $now,
            ],
            [ 'id' => $asset_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        // ---- Audit log + event: employee approved assignment ----
        if ( method_exists( $this, 'log_asset_event' ) ) {
            $this->log_asset_event(
                'assignment_approved_by_employee',
                $asset_id,
                $assignment_id,
                [
                    'employee_id'           => $emp_id,
                    'user_id'               => $user_id,
                    'decision'              => 'approve',
                    'selfie_attachment_id'  => $selfie_id,
                    'asset_attachment_id'   => $asset_img_id,
                ]
            );
        }

        if ( method_exists( $this, 'fire_asset_event' ) ) {
            $this->fire_asset_event(
                'assignment_approved_by_employee',
                [
                    'asset_id'             => $asset_id,
                    'assignment_id'        => $assignment_id,
                    'employee_id'          => $emp_id,
                    'user_id'              => $user_id,
                    'decision'             => 'approve',
                    'selfie_attachment_id' => $selfie_id,
                    'asset_attachment_id'  => $asset_img_id,
                ]
            );
        }

        // ===== Notification: asset received by employee (to manager) =====
        $manager_user_id = isset( $assignment->assigned_by ) ? (int) $assignment->assigned_by : 0;
        if ( $manager_user_id > 0 ) {
            $manager_user = get_userdata( $manager_user_id );
            if ( $manager_user && ! empty( $manager_user->user_email ) ) {
                $subject = sprintf(
                    __( 'Asset received by %s: %s', 'sfs-hr' ),
                    $employee_name,
                    $asset_title !== '' ? $asset_title : __( 'Asset', 'sfs-hr' )
                );

                $body = sprintf(
                    __( "The employee %s has confirmed receiving the asset.\n\nAsset: %s\nCode: %s\nAssignment ID: %d\nDate: %s", 'sfs-hr' ),
                    $employee_name,
                    $asset_name !== '' ? $asset_name : '-',
                    $asset_code !== '' ? $asset_code : '-',
                    $assignment_id,
                    $now
                );

                wp_mail( $manager_user->user_email, $subject, $body );
            }
        }

    } else {
        // decision === 'reject'

        // Mark assignment rejected
        $wpdb->update(
            $assign_table,
            [
                'status'     => 'rejected',
                'updated_at' => $now,
            ],
            [ 'id' => $assignment_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        // Asset back to "available"
        $wpdb->update(
            $assets_table,
            [
                'status'     => 'available',
                'updated_at' => $now,
            ],
            [ 'id' => $asset_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        // ---- Audit log + event: employee rejected assignment ----
        if ( method_exists( $this, 'log_asset_event' ) ) {
            $this->log_asset_event(
                'assignment_rejected_by_employee',
                $asset_id,
                $assignment_id,
                [
                    'employee_id' => $emp_id,
                    'user_id'     => $user_id,
                    'decision'    => 'reject',
                ]
            );
        }

        if ( method_exists( $this, 'fire_asset_event' ) ) {
            $this->fire_asset_event(
                'assignment_rejected_by_employee',
                [
                    'asset_id'      => $asset_id,
                    'assignment_id' => $assignment_id,
                    'employee_id'   => $emp_id,
                    'user_id'       => $user_id,
                    'decision'      => 'reject',
                ]
            );
        }

        // ===== Notification: asset assignment rejected (to manager) =====
        $manager_user_id = isset( $assignment->assigned_by ) ? (int) $assignment->assigned_by : 0;
        if ( $manager_user_id > 0 ) {
            $manager_user = get_userdata( $manager_user_id );
            if ( $manager_user && ! empty( $manager_user->user_email ) ) {
                $subject = sprintf(
                    __( 'Asset assignment rejected by %s: %s', 'sfs-hr' ),
                    $employee_name,
                    $asset_title !== '' ? $asset_title : __( 'Asset', 'sfs-hr' )
                );

                $body = sprintf(
                    __( "The employee %s has rejected the asset assignment.\n\nAsset: %s\nCode: %s\nAssignment ID: %d\nDate: %s", 'sfs-hr' ),
                    $employee_name,
                    $asset_name !== '' ? $asset_name : '-',
                    $asset_code !== '' ? $asset_code : '-',
                    $assignment_id,
                    $now
                );

                wp_mail( $manager_user->user_email, $subject, $body );
            }
        }
    }

    // Redirect back with decision flag (same behaviour as before)
    $redirect = remove_query_arg( [ 'assigned' ], wp_get_referer() ?: admin_url() );
    if ( $redirect ) {
        $redirect = add_query_arg( 'decision', $decision, $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    wp_safe_redirect( admin_url() );
    exit;
}





    public function handle_return_request(): void {
    // Managers or Assets admins only
    if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'sfs_hr_assets_admin' ) ) {
        wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
    }

    $assignment_id = isset( $_POST['assignment_id'] ) ? (int) $_POST['assignment_id'] : 0;
    if ( ! $assignment_id ) {
        wp_die( esc_html__( 'Invalid request.', 'sfs-hr' ) );
    }

    check_admin_referer( 'sfs_hr_assets_return_request_' . $assignment_id );

    global $wpdb;
    $assign_table    = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $employees_table = $wpdb->prefix . 'sfs_hr_employees';
    $assets_table    = $wpdb->prefix . 'sfs_hr_assets';

    // Load assignment
    $assignment = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$assign_table} WHERE id = %d LIMIT 1",
            $assignment_id
        )
    );

    if ( ! $assignment ) {
        wp_die( esc_html__( 'Assignment not found.', 'sfs-hr' ) );
    }

    if ( $assignment->status !== 'active' ) {
        wp_die( esc_html__( 'Only active assignments can be requested for return.', 'sfs-hr' ) );
    }

    $now        = current_time( 'mysql' );
    $manager_id = get_current_user_id();

    // Update assignment -> return_requested
    $updated = $wpdb->update(
        $assign_table,
        [
            'status'              => 'return_requested',
            'return_requested_by' => $manager_id,
            'updated_at'          => $now,
        ],
        [ 'id' => $assignment_id ],
        [ '%s', '%d', '%s' ],
        [ '%d' ]
    );

    if ( $updated === false ) {
        wp_die( esc_html__( 'Failed to mark assignment as return requested.', 'sfs-hr' ) );
    }

    $asset_id     = (int) $assignment->asset_id;
    $employee_id  = (int) $assignment->employee_id;
    $emp_user_id  = null;

    // Resolve employee user_id (for logging / notifications)
    if ( $employee_id > 0 ) {
        $emp_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT user_id FROM {$employees_table} WHERE id = %d LIMIT 1",
                $employee_id
            )
        );
        if ( $emp_row && isset( $emp_row->user_id ) ) {
            $emp_user_id = (int) $emp_row->user_id;
        }
    }

    // Preload asset info for notification
    $asset_name  = '';
    $asset_code  = '';
    $asset_label = '';

    $asset_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT name, asset_code FROM {$assets_table} WHERE id = %d LIMIT 1",
            $asset_id
        )
    );

    if ( $asset_row ) {
        $asset_name  = isset( $asset_row->name ) ? (string) $asset_row->name : '';
        $asset_code  = isset( $asset_row->asset_code ) ? (string) $asset_row->asset_code : '';
        $asset_label = $asset_name !== '' ? $asset_name : $asset_code;
    }

    // Manager display name (for email body)
    $manager_name = '';
    $manager_user = get_userdata( $manager_id );
    if ( $manager_user && ! empty( $manager_user->display_name ) ) {
        $manager_name = $manager_user->display_name;
    } else {
        $manager_name = sprintf( __( 'User #%d', 'sfs-hr' ), $manager_id );
    }

    // ---- Audit log: manager requested return ----
    if ( method_exists( $this, 'log_asset_event' ) ) {
        $this->log_asset_event(
            'assignment_return_requested',
            $asset_id,
            $assignment_id,
            [
                'requested_by_user_id' => $manager_id,
                'employee_id'          => $employee_id,
                'employee_user_id'     => $emp_user_id,
            ]
        );
    }

    // ---- Event hook: for notifications / integrations ----
    if ( method_exists( $this, 'fire_asset_event' ) ) {
        $this->fire_asset_event(
            'assignment_return_requested',
            [
                'asset_id'          => $asset_id,
                'assignment_id'     => $assignment_id,
                'requested_by'      => $manager_id,
                'employee_id'       => $employee_id,
                'employee_user_id'  => $emp_user_id,
            ]
        );
    }

    // ================== Notification: return request to employee ==================
    if ( $emp_user_id ) {
        $employee_user = get_userdata( $emp_user_id );
        if ( $employee_user && ! empty( $employee_user->user_email ) ) {

            $employee_name = ! empty( $employee_user->display_name )
                ? $employee_user->display_name
                : sprintf( __( 'Employee #%d', 'sfs-hr' ), $employee_id );

            $subject = sprintf(
                __( 'Return request for asset: %s', 'sfs-hr' ),
                $asset_label !== '' ? $asset_label : __( 'Asset', 'sfs-hr' )
            );

            $body = sprintf(
                __( "Dear %1\$s,\n\nA return has been requested for an asset currently assigned to you.\n\nAsset: %2\$s\nCode: %3\$s\nAssignment ID: %4\$d\nRequested by: %5\$s\nRequest date: %6\$s\n\nPlease log in to your HR portal to review and complete the return process.", 'sfs-hr' ),
                $employee_name,
                $asset_name !== '' ? $asset_name : '-',
                $asset_code !== '' ? $asset_code : '-',
                $assignment_id,
                $manager_name,
                $now
            );

            wp_mail( $employee_user->user_email, $subject, $body );
        }
    }
    // =====================================================================

    $redirect = add_query_arg(
        [
            'page'           => 'sfs-hr-assets',
            'tab'            => 'assignments',
            'return_request' => '1',
        ],
        admin_url( 'admin.php' )
    );

    wp_safe_redirect( $redirect );
    exit;
}


    public function handle_return_decision(): void {
    if ( ! is_user_logged_in() ) {
        wp_die( esc_html__( 'You must be logged in.', 'sfs-hr' ) );
    }

    $assignment_id = isset( $_POST['assignment_id'] ) ? (int) $_POST['assignment_id'] : 0;
    $decision      = isset( $_POST['decision'] ) ? sanitize_key( $_POST['decision'] ) : '';

    // For now we only support "approve" (confirm return)
    if ( ! $assignment_id || $decision !== 'approve' ) {
        wp_die( esc_html__( 'Invalid request.', 'sfs-hr' ) );
    }

    check_admin_referer( 'sfs_hr_assets_return_decision_' . $assignment_id );

    global $wpdb;
    $assign_table    = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $assets_table    = $wpdb->prefix . 'sfs_hr_assets';
    $employees_table = $wpdb->prefix . 'sfs_hr_employees';

    // --- Load assignment ---
    $assignment = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
             FROM {$assign_table}
             WHERE id = %d
             LIMIT 1",
            $assignment_id
        )
    );

    if ( ! $assignment ) {
        wp_die( esc_html__( 'Assignment not found.', 'sfs-hr' ) );
    }

    if ( $assignment->status !== 'return_requested' ) {
        wp_die( esc_html__( 'This assignment is not awaiting return approval.', 'sfs-hr' ) );
    }

    // --- Validate that current user = employee who owns the asset ---
    $current_user_id = get_current_user_id();

    $employee = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
             FROM {$employees_table}
             WHERE user_id = %d
             LIMIT 1",
            $current_user_id
        )
    );

    if ( ! $employee ) {
        wp_die( esc_html__( 'You are not linked to an employee record.', 'sfs-hr' ) );
    }

    if ( (int) $employee->id !== (int) $assignment->employee_id ) {
        wp_die( esc_html__( 'You are not allowed to confirm this return.', 'sfs-hr' ) );
    }

    $now   = current_time( 'mysql' );
    $today = current_time( 'Y-m-d' );

    // ---------- Preload asset + employee display info for notification ----------
    $asset_id    = (int) $assignment->asset_id;
    $asset_name  = '';
    $asset_code  = '';
    $asset_label = '';

    $asset_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT name, asset_code FROM {$assets_table} WHERE id = %d LIMIT 1",
            $asset_id
        )
    );
    if ( $asset_row ) {
        $asset_name  = isset( $asset_row->name ) ? (string) $asset_row->name : '';
        $asset_code  = isset( $asset_row->asset_code ) ? (string) $asset_row->asset_code : '';
        $asset_label = $asset_name !== '' ? $asset_name : $asset_code;
    }

    $employee_user = get_userdata( $current_user_id );
    if ( $employee_user && ! empty( $employee_user->display_name ) ) {
        $employee_name = $employee_user->display_name;
    } else {
        $employee_name = sprintf( __( 'Employee #%d', 'sfs-hr' ), (int) $employee->id );
    }

    // Choose who to notify as "manager": prefer return_requested_by, fallback to assigned_by if exists
    $manager_user_id = 0;
    if ( isset( $assignment->return_requested_by ) && (int) $assignment->return_requested_by > 0 ) {
        $manager_user_id = (int) $assignment->return_requested_by;
    } elseif ( isset( $assignment->assigned_by ) && (int) $assignment->assigned_by > 0 ) {
        $manager_user_id = (int) $assignment->assigned_by;
    }
    // ---------------------------------------------------------------------------

    // ---------- Return photos (selfie + asset) ----------
    $selfie_data = isset( $_POST['selfie_data'] ) ? trim( (string) $_POST['selfie_data'] ) : '';
    $asset_data  = isset( $_POST['asset_data'] )  ? trim( (string) $_POST['asset_data'] )  : '';

    $return_selfie_id = 0;
    $return_asset_id  = 0;

    // Check if return_* columns actually exist before we try to write into them
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$assign_table}", 0 );
    $can_store_return_photos = is_array( $cols )
        && in_array( 'return_selfie_attachment_id', $cols, true )
        && in_array( 'return_asset_attachment_id',  $cols, true );

    if ( $can_store_return_photos && $selfie_data && strpos( $selfie_data, 'data:image' ) === 0 && method_exists( $this, 'save_data_url_attachment' ) ) {
        $return_selfie_id = $this->save_data_url_attachment(
            $selfie_data,
            'asset-assignment-' . $assignment_id . '-return-selfie'
        );
    }

    if ( $can_store_return_photos && $asset_data && strpos( $asset_data, 'data:image' ) === 0 && method_exists( $this, 'save_data_url_attachment' ) ) {
        $return_asset_id = $this->save_data_url_attachment(
            $asset_data,
            'asset-assignment-' . $assignment_id . '-return-asset'
        );
    }

    // ---------- Update assignment row ----------
    $update_data = [
        'status'      => 'returned',
        'end_date'    => $today,
        'return_date' => $today,
        'updated_at'  => $now,
    ];
    $update_fmt = [ '%s', '%s', '%s', '%s' ];

    if ( $can_store_return_photos && $return_selfie_id ) {
        $update_data['return_selfie_attachment_id'] = $return_selfie_id;
        $update_fmt[] = '%d';
    }
    if ( $can_store_return_photos && $return_asset_id ) {
        $update_data['return_asset_attachment_id'] = $return_asset_id;
        $update_fmt[] = '%d';
    }

    $updated_assign = $wpdb->update(
        $assign_table,
        $update_data,
        [ 'id' => $assignment_id ],
        $update_fmt,
        [ '%d' ]
    );

    if ( $updated_assign === false ) {
        wp_die(
            esc_html__(
                'Failed to update assignment as returned.',
                'sfs-hr'
            ) . ' DB: ' . esc_html( $wpdb->last_error )
        );
    }

    // ---------- Make asset "available" again ----------
    $updated_asset = $wpdb->update(
        $assets_table,
        [
            'status'     => 'available',
            'updated_at' => $now,
        ],
        [ 'id' => (int) $assignment->asset_id ],
        [ '%s', '%s' ],
        [ '%d' ]
    );

    if ( $updated_asset === false ) {
        wp_die(
            esc_html__(
                'Failed to update asset status.',
                'sfs-hr'
            ) . ' DB: ' . esc_html( $wpdb->last_error )
        );
    }

    // ---------- Audit log ----------
    if ( method_exists( $this, 'log_asset_event' ) ) {
        $this->log_asset_event(
            'assignment_return_confirmed',
            (int) $assignment->asset_id,
            (int) $assignment_id,
            [
                'employee_id'                 => (int) $employee->id,
                'employee_user_id'            => $current_user_id,
                'return_selfie_attachment_id' => $return_selfie_id,
                'return_asset_attachment_id'  => $return_asset_id,
            ]
        );
    }

    if ( method_exists( $this, 'fire_asset_event' ) ) {
        $this->fire_asset_event(
            'assignment_return_confirmed',
            [
                'asset_id'                    => (int) $assignment->asset_id,
                'assignment_id'               => (int) $assignment_id,
                'employee_id'                 => (int) $employee->id,
                'employee_user_id'            => $current_user_id,
                'return_selfie_attachment_id' => $return_selfie_id,
                'return_asset_attachment_id'  => $return_asset_id,
            ]
        );
    }

    // ================== Notification: asset returned (to manager) ==================
    if ( $manager_user_id > 0 ) {
        $manager_user = get_userdata( $manager_user_id );
        if ( $manager_user && ! empty( $manager_user->user_email ) ) {

            $manager_name = ! empty( $manager_user->display_name )
                ? $manager_user->display_name
                : sprintf( __( 'User #%d', 'sfs-hr' ), $manager_user_id );

            $subject = sprintf(
                __( 'Asset returned by %1$s: %2$s', 'sfs-hr' ),
                $employee_name,
                $asset_label !== '' ? $asset_label : __( 'Asset', 'sfs-hr' )
            );

            $body = sprintf(
                __( "Dear %1\$s,\n\nThe employee %2\$s has confirmed the return of an asset.\n\nAsset: %3\$s\nCode: %4\$s\nAssignment ID: %5\$d\nReturn date: %6\$s\n\nYou can log in to the HR portal to review the details and photos (if available).", 'sfs-hr' ),
                $manager_name,
                $employee_name,
                $asset_name !== '' ? $asset_name : '-',
                $asset_code !== '' ? $asset_code : '-',
                (int) $assignment_id,
                $today
            );

            wp_mail( $manager_user->user_email, $subject, $body );
        }
    }
    // ======================================================================

    // Redirect back with flag
    $redirect = wp_get_referer() ?: admin_url();
    $redirect = add_query_arg( 'return_confirmed', '1', $redirect );

    wp_safe_redirect( $redirect );
    exit;
}



    public function handle_asset_save(): void {
        if ( ! current_user_can('sfs_hr.manage') ) {
            wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
        }

        check_admin_referer( 'sfs_hr_assets_edit' );

        $id             = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $asset_code     = isset( $_POST['asset_code'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_code'] ) ) : '';
        $name           = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $category       = isset( $_POST['category'] ) ? sanitize_key( $_POST['category'] ) : '';
        $department     = isset( $_POST['department'] ) ? sanitize_text_field( wp_unslash( $_POST['department'] ) ) : '';
        $serial_number  = isset( $_POST['serial_number'] ) ? sanitize_text_field( wp_unslash( $_POST['serial_number'] ) ) : '';
        $model          = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
        $purchase_year  = isset( $_POST['purchase_year'] ) ? (int) $_POST['purchase_year'] : 0;
        $purchase_price = isset( $_POST['purchase_price'] ) ? (float) $_POST['purchase_price'] : 0;
        $warranty_expiry= isset( $_POST['warranty_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['warranty_expiry'] ) ) : '';
        $invoice_number = isset( $_POST['invoice_number'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice_number'] ) ) : '';
        $invoice_date   = isset( $_POST['invoice_date'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice_date'] ) ) : '';
        $status         = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'available';
        $condition      = isset( $_POST['condition'] ) ? sanitize_key( $_POST['condition'] ) : 'good';
        $notes          = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

        if ( $asset_code === '' || $name === '' || $category === '' ) {
            wp_die( esc_html__( 'Asset code, name and category are required.', 'sfs-hr' ) );
        }

        if ( $warranty_expiry !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $warranty_expiry ) ) {
            wp_die( esc_html__( 'Invalid warranty expiry date.', 'sfs-hr' ) );
        }

        if ( $invoice_date !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $invoice_date ) ) {
            wp_die( esc_html__( 'Invalid invoice date.', 'sfs-hr' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_assets';

        // Hard check: table exists
        $exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );
        if ( $exists !== $table ) {
            wp_die(
                sprintf(
                    'Assets table NOT FOUND: %s',
                    esc_html( $table )
                )
            );
        }

        // Load existing row (for keeping invoice_file if no new upload)
        $existing = null;
        if ( $id > 0 ) {
            $existing = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id )
            );
        }

        // Handle invoice file upload
        $invoice_file = ( $existing && ! empty( $existing->invoice_file ) )
            ? $existing->invoice_file
            : '';

        if ( isset( $_FILES['invoice_file'] ) && ! empty( $_FILES['invoice_file']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $upload = wp_handle_upload(
                $_FILES['invoice_file'],
                [ 'test_form' => false ]
            );

            if ( isset( $upload['error'] ) && $upload['error'] ) {
                wp_die(
                    sprintf(
                        'Upload error: %s',
                        esc_html( $upload['error'] )
                    )
                );
            }

            if ( ! empty( $upload['url'] ) ) {
                $invoice_file = esc_url_raw( $upload['url'] );
            }
        }

        $now = current_time( 'mysql' );

        // Data payload – send most as strings for safety
        $data = [
            'asset_code'      => $asset_code,
            'name'            => $name,
            'category'        => $category,
            'department'      => $department,
            'serial_number'   => $serial_number,
            'model'           => $model,
            'purchase_year'   => $purchase_year ? (string) $purchase_year : null,
            'purchase_price'  => $purchase_price ? (string) $purchase_price : null,
            'warranty_expiry' => $warranty_expiry ?: null,
            'invoice_number'  => $invoice_number,
            'invoice_date'    => $invoice_date ?: null,
            'invoice_file'    => $invoice_file,
            'status'          => $status,
            'condition'       => $condition,
            'notes'           => $notes,
            'updated_at'      => $now,
        ];

        $formats = [
            '%s', // asset_code
            '%s', // name
            '%s', // category
            '%s', // department
            '%s', // serial_number
            '%s', // model
            '%s', // purchase_year
            '%s', // purchase_price
            '%s', // warranty_expiry
            '%s', // invoice_number
            '%s', // invoice_date
            '%s', // invoice_file
            '%s', // status
            '%s', // condition
            '%s', // notes
            '%s', // updated_at
        ];

        // Insert or update
        if ( $id > 0 ) {
            $updated = $wpdb->update(
                $table,
                $data,
                [ 'id' => $id ],
                $formats,
                [ '%d' ]
            );

            if ( $updated === false ) {
                wp_die(
                    'Failed to update asset. DB error: ' . esc_html( $wpdb->last_error )
                );
            }

            // ---- Audit log + event: asset updated ----
            if ( method_exists( $this, 'log_asset_event' ) ) {
                $this->log_asset_event(
                    'asset_updated',
                    $id,
                    null,
                    [
                        'asset_code' => $asset_code,
                        'name'       => $name,
                        'status'     => $status,
                        'condition'  => $condition,
                    ]
                );
            }

            if ( method_exists( $this, 'fire_asset_event' ) ) {
                $this->fire_asset_event( 'asset_updated', [
                    'asset_id'    => $id,
                    'asset_code'  => $asset_code,
                    'name'        => $name,
                    'status'      => $status,
                    'condition'   => $condition,
                ] );
            }

        } else {
            $data['created_at'] = $now;
            $formats[] = '%s';

            $inserted = $wpdb->insert(
                $table,
                $data,
                $formats
            );

            if ( ! $inserted ) {
                wp_die(
                    'Failed to create asset. DB error: ' . esc_html( $wpdb->last_error )
                );
            }

            $id = (int) $wpdb->insert_id;

            // ---- Audit log + event: asset created ----
            if ( method_exists( $this, 'log_asset_event' ) ) {
                $this->log_asset_event(
                    'asset_created',
                    $id,
                    null,
                    [
                        'asset_code' => $asset_code,
                        'name'       => $name,
                        'status'     => $status,
                        'condition'  => $condition,
                    ]
                );
            }

            if ( method_exists( $this, 'fire_asset_event' ) ) {
                $this->fire_asset_event( 'asset_created', [
                    'asset_id'    => $id,
                    'asset_code'  => $asset_code,
                    'name'        => $name,
                    'status'      => $status,
                    'condition'   => $condition,
                ] );
            }
        }

        // --- QR code update (after we have final $id and $asset_code) ---
        if ( $id > 0 && $asset_code !== '' && method_exists( $this, 'generate_qr_url_for_asset' ) ) {
            $qr_url = $this->generate_qr_url_for_asset( (int) $id, (string) $asset_code );

            if ( $qr_url ) {
                $wpdb->update(
                    $table,
                    [
                        'qr_code_path' => $qr_url,
                        'updated_at'   => current_time( 'mysql' ),
                    ],
                    [ 'id' => $id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
            }
        }

        $redirect = add_query_arg(
            [
                'page' => 'sfs-hr-assets',
                'tab'  => 'assets',
                'view' => 'edit',
                'id'   => $id,
                'saved'=> 1,
            ],
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_assets_export(): void {
        if ( ! current_user_can('sfs_hr.manage') ) {
            wp_die(__('Access denied', 'sfs-hr'));
        }

        check_admin_referer('sfs_hr_assets_export');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_assets';

        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);

        if ( headers_sent() ) {
            wp_die('Headers already sent.');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=assets-export-' . date('Ymd-His') . '.csv');

        $out = fopen('php://output', 'w');

        if ( ! empty($rows) ) {
            // Header row
            fputcsv($out, array_keys($rows[0]));
            foreach ( $rows as $row ) {
                fputcsv($out, $row);
            }
        } else {
            fputcsv($out, ['no_data']);
        }

        fclose($out);
        exit;
    }

    public function handle_assets_import(): void {
        if ( ! current_user_can('sfs_hr.manage') ) {
            wp_die(__('Access denied', 'sfs-hr'));
        }

        check_admin_referer('sfs_hr_assets_import');

        // Helper to redirect with error
        $redirect_error = function( string $error_code ) {
            $redirect = add_query_arg(
                [
                    'page'         => 'sfs-hr-assets',
                    'tab'          => 'assets',
                    'import_error' => $error_code,
                ],
                admin_url('admin.php')
            );
            wp_safe_redirect($redirect);
            exit;
        };

        if ( empty($_FILES['import_file']['tmp_name']) ) {
            $redirect_error('no_file');
        }

        $file = $_FILES['import_file']['tmp_name'];

        // Read file content for encoding detection and BOM handling
        $content = file_get_contents($file);
        if ( $content === false ) {
            $redirect_error('read_error');
        }

        // Remove UTF-8 BOM if present (common in Excel exports)
        $bom = "\xEF\xBB\xBF";
        if ( substr($content, 0, 3) === $bom ) {
            $content = substr($content, 3);
        }

        // Detect and convert encoding to UTF-8 for Arabic support
        // Use only commonly supported encodings to avoid errors
        $supported_encodings = ['UTF-8', 'ISO-8859-1', 'ASCII'];

        // Check if additional Arabic encodings are available
        $available_encodings = mb_list_encodings();
        if ( in_array('Windows-1256', $available_encodings, true) ) {
            array_splice($supported_encodings, 1, 0, ['Windows-1256']);
        }
        if ( in_array('ISO-8859-6', $available_encodings, true) ) {
            array_splice($supported_encodings, 1, 0, ['ISO-8859-6']);
        }

        $encoding = mb_detect_encoding($content, $supported_encodings, true);
        if ( $encoding && $encoding !== 'UTF-8' ) {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        // Ensure content is valid UTF-8 (fallback conversion)
        if ( ! mb_check_encoding($content, 'UTF-8') ) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        // Write cleaned content to temp file for CSV parsing
        $temp_file = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($temp_file, $content);

        $handle = fopen($temp_file, 'r');
        if ( ! $handle ) {
            @unlink($temp_file);
            $redirect_error('process_error');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_assets';

        $header = fgetcsv($handle);
        if ( ! $header ) {
            fclose($handle);
            @unlink($temp_file);
            $redirect_error('empty_csv');
        }

        // Normalize header (trim and remove any remaining BOM artifacts)
        $header = array_map(function($h) {
            return trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $h));
        }, $header);

        while ( ( $row = fgetcsv($handle) ) !== false ) {
            if ( count($row) === 1 && $row[0] === '' ) {
                continue;
            }

            $data = array_combine($header, $row);
            if ( ! $data ) {
                continue;
            }

            $asset_code = isset($data['asset_code']) ? sanitize_text_field($data['asset_code']) : '';
            if ( $asset_code === '' ) {
                continue;
            }

            // Clean some fields
            $data['name']       = isset($data['name']) ? sanitize_text_field($data['name']) : '';
            $data['category']   = isset($data['category']) ? sanitize_key($data['category']) : '';
            $data['department'] = isset($data['department']) ? sanitize_text_field($data['department']) : '';

            if ( $data['category'] === '' || $data['name'] === '' ) {
                continue;
            }

            // We **never** trust CSV for created_at / updated_at
            unset( $data['created_at'], $data['updated_at'] );

            // Upsert by asset_code
            $existing_id = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE asset_code = %s LIMIT 1", $asset_code)
            );

            $now = current_time('mysql');

            if ( $existing_id ) {
                // Existing asset: KEEP old created_at, only bump updated_at
                $data['updated_at'] = $now;

                $wpdb->update(
                    $table,
                    $data,
                    [ 'id' => (int) $existing_id ]
                );
            } else {
                // New asset: created_at = now, updated_at = now (ignore CSV values)
                $data['created_at'] = $now;
                $data['updated_at'] = $now;

                $wpdb->insert(
                    $table,
                    $data
                );
            }
        }

        fclose($handle);
        @unlink($temp_file); // Clean up temp file

        $redirect = add_query_arg(
            [
                'page'   => 'sfs-hr-assets',
                'tab'    => 'assets',
                'import' => '1',
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Simple audit log for asset-related actions.
     *
     * Table: {prefix}sfs_hr_asset_logs
     * Columns (suggested):
     *  - id BIGINT pk
     *  - created_at DATETIME
     *  - user_id BIGINT NULL
     *  - asset_id BIGINT NULL
     *  - assignment_id BIGINT NULL
     *  - event_type VARCHAR(50)
     *  - meta_json LONGTEXT NULL
     */
    protected function log_asset_event(
        string $event_type,
        ?int $asset_id = null,
        ?int $assignment_id = null,
        array $meta = []
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_asset_logs';

        // Hard-existence check; if no table → silently skip
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = %s",
                $table
            )
        );

        if ( ! $exists ) {
            return;
        }

        $user_id = get_current_user_id() ?: null;
        $now     = current_time( 'mysql' );

        $wpdb->insert(
            $table,
            [
                'created_at'    => $now,
                'user_id'       => $user_id,
                'asset_id'      => $asset_id,
                'assignment_id' => $assignment_id,
                'event_type'    => $event_type,
                'meta_json'     => $meta ? wp_json_encode( $meta ) : null,
            ],
            [
                '%s',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
            ]
        );
    }
    
    /**
     * Fire a generic event hook for integrations (email / Telegram / n8n).
     */
    protected function fire_asset_event( string $event_type, array $data = [] ): void {
        /**
         * sfs_hr_asset_event
         *
         * @param string $event_type  e.g. 'assignment_created'
         * @param array  $data        payload (asset_id, employee_id, etc.)
         */
        do_action( 'sfs_hr_asset_event', $event_type, $data );
    }
    
    
    /**
     * Save a base64 data URL as a WP attachment and return attachment ID.
     */
    protected function save_data_url_attachment( string $data_url, string $title ): int {
    if ( ! preg_match( '#^data:image/(\w+);base64,#', $data_url, $m ) ) {
        return 0;
    }

    $ext     = strtolower( $m[1] );
    $data    = substr( $data_url, strpos( $data_url, ',' ) + 1 );
    $binary  = base64_decode( $data );
    if ( ! $binary ) {
        return 0;
    }

    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        return 0;
    }

    $filename = sanitize_file_name( $title ) . '-' . time() . '.' . $ext;
    $filepath = trailingslashit( $upload['path'] ) . $filename;

    if ( ! file_put_contents( $filepath, $binary ) ) {
        return 0;
    }

    $filetype = wp_check_filetype( $filename, null );

    $attachment_id = wp_insert_attachment(
        [
            'post_mime_type' => $filetype['type'] ?? 'image/jpeg',
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        ],
        $filepath
    );

    if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $metadata = wp_generate_attachment_metadata( $attachment_id, $filepath );
    wp_update_attachment_metadata( $attachment_id, $metadata );

    return (int) $attachment_id;
}

private static $asset_assign_has_photo_cols = null;

private function assignment_has_photo_columns(): bool {
    if ( self::$asset_assign_has_photo_cols !== null ) {
        return self::$asset_assign_has_photo_cols;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );

    if ( ! is_array( $cols ) ) {
        self::$asset_assign_has_photo_cols = false;
    } else {
        self::$asset_assign_has_photo_cols = (
            in_array( 'selfie_attachment_id', $cols, true ) &&
            in_array( 'asset_attachment_id',  $cols, true )
        );
    }

    return self::$asset_assign_has_photo_cols;
}



}