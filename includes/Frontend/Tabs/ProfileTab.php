<?php
/**
 * Profile Tab - Employee profile information.
 *
 * Displays employee photo, personal details, employment info,
 * contact, identification, payroll, quick links, and assigned assets.
 * Content moved from inline rendering in Shortcodes.php.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ProfileTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        global $wpdb;

        // ── Extract employee fields ───────────────────────────────
        $first_name    = (string) ( $emp['first_name'] ?? '' );
        $last_name     = (string) ( $emp['last_name']  ?? '' );
        $full_name     = trim( $first_name . ' ' . $last_name );
        $first_name_ar = (string) ( $emp['first_name_ar'] ?? '' );
        $last_name_ar  = (string) ( $emp['last_name_ar']  ?? '' );
        $full_name_ar  = trim( $first_name_ar . ' ' . $last_name_ar );

        if ( $full_name === '' ) {
            $full_name = '#' . $emp_id;
        }

        $code        = (string) ( $emp['employee_code'] ?? '' );
        $status_raw  = (string) ( $emp['status'] ?? '' );
        $position    = (string) ( $emp['position'] ?? '' );
        $email       = (string) ( $emp['email'] ?? '' );
        $phone       = (string) ( $emp['phone'] ?? '' );
        $hire_date   = (string) ( $emp['hired_at'] ?? '' );
        $gender_raw  = (string) ( $emp['gender'] ?? '' );
        $national_id = (string) ( $emp['national_id'] ?? '' );
        $nid_exp     = (string) ( $emp['national_id_expiry'] ?? '' );
        $passport_no = (string) ( $emp['passport_no'] ?? '' );
        $pass_exp    = (string) ( $emp['passport_expiry'] ?? '' );
        $emg_name    = (string) ( $emp['emergency_contact_name'] ?? '' );
        $emg_phone   = (string) ( $emp['emergency_contact_phone'] ?? '' );
        $photo_id    = isset( $emp['photo_id'] ) ? (int) $emp['photo_id'] : 0;

        $base_salary = isset( $emp['base_salary'] ) && $emp['base_salary'] !== null
            ? number_format_i18n( (float) $emp['base_salary'], 2 )
            : '';

        // Translate enum values.
        $status_map = [
            'active'     => __( 'Active', 'sfs-hr' ),
            'inactive'   => __( 'Inactive', 'sfs-hr' ),
            'terminated' => __( 'Terminated', 'sfs-hr' ),
        ];
        $gender_map = [
            'male'   => __( 'Male', 'sfs-hr' ),
            'female' => __( 'Female', 'sfs-hr' ),
        ];
        $status = $status_map[ $status_raw ] ?? ucfirst( $status_raw );
        $gender = $gender_map[ $gender_raw ] ?? ucfirst( $gender_raw );

        // Department name.
        $dept_name = '';
        if ( ! empty( $emp['dept_id'] ) ) {
            $dept_table = $wpdb->prefix . 'sfs_hr_departments';
            $dept_name  = (string) $wpdb->get_var(
                $wpdb->prepare( "SELECT name FROM {$dept_table} WHERE id = %d", (int) $emp['dept_id'] )
            );
        }

        // WP username.
        $wp_username = '';
        if ( ! empty( $emp['user_id'] ) ) {
            $u = get_userdata( (int) $emp['user_id'] );
            if ( $u && $u->user_login ) {
                $wp_username = (string) $u->user_login;
            }
        }

        // ── Profile completion ────────────────────────────────────
        $missing_docs_count = 0;
        if ( class_exists( '\SFS\HR\Modules\Documents\Services\Documents_Service' ) ) {
            $missing_docs       = \SFS\HR\Modules\Documents\Services\Documents_Service::get_missing_required_documents( $emp_id );
            $missing_docs_count = count( $missing_docs );
        }

        $profile_fields = [
            'photo'       => $photo_id > 0,
            'name'        => $first_name !== '' && $last_name !== '',
            'email'       => $email !== '',
            'phone'       => $phone !== '',
            'gender'      => $gender_raw !== '',
            'position'    => $position !== '',
            'department'  => $dept_name !== '',
            'hire_date'   => $hire_date !== '',
            'national_id' => $national_id !== '',
            'emergency'   => $emg_name !== '' && $emg_phone !== '',
            'documents'   => $missing_docs_count === 0,
        ];
        $profile_completed      = array_filter( $profile_fields );
        $profile_completion_pct = (int) round( ( count( $profile_completed ) / count( $profile_fields ) ) * 100 );
        $profile_missing        = array_keys( array_filter( $profile_fields, fn( $v ) => ! $v ) );

        // ── Tab URLs ──────────────────────────────────────────────
        $base_url       = remove_query_arg( 'sfs_hr_tab' );
        $documents_url  = add_query_arg( 'sfs_hr_tab', 'documents', $base_url );
        $attendance_url = add_query_arg( 'sfs_hr_tab', 'attendance', $base_url );

        // Can self-clock?
        $can_self_clock = class_exists( '\SFS\HR\Modules\Attendance\Rest\Public_REST' )
            && \SFS\HR\Modules\Attendance\Rest\Public_REST::can_punch_self();

        // Is limited access?
        $is_limited_access = ( $status_raw === 'terminated' );
        if ( ! $is_limited_access ) {
            $resign_table = $wpdb->prefix . 'sfs_hr_resignations';
            $approved     = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$resign_table} WHERE employee_id = %d AND status = 'approved' LIMIT 1",
                    $emp_id
                )
            );
            $is_limited_access = ( $approved > 0 );
        }

        // ── Helper: print a field row ─────────────────────────────
        $label_to_key = static function ( string $label ): string {
            $map = [
                'Status' => 'status', 'Gender' => 'gender', 'Department' => 'department',
                'Position' => 'position', 'Hire Date' => 'hire_date', 'Hire date' => 'hire_date',
                'Employee ID' => 'employee_id', 'WP Username' => 'wp_username',
                'Email' => 'email', 'Phone' => 'phone',
                'Emergency contact' => 'emergency_contact', 'Emergency Contact' => 'emergency_contact',
                'National ID' => 'national_id', 'National ID Expiry' => 'national_id_expiry',
                'Passport No.' => 'passport_no', 'Passport Expiry' => 'passport_expiry',
                'Base salary' => 'base_salary', 'Base Salary' => 'base_salary',
                // Arabic labels
                'الحالة' => 'status', 'الجنس' => 'gender', 'القسم' => 'department',
                'المنصب' => 'position', 'تاريخ التعيين' => 'hire_date',
                'رقم الموظف' => 'employee_id', 'اسم المستخدم' => 'wp_username',
                'البريد الإلكتروني' => 'email', 'الهاتف' => 'phone',
                'جهة اتصال الطوارئ' => 'emergency_contact',
                'رقم الهوية' => 'national_id', 'انتهاء الهوية' => 'national_id_expiry',
                'رقم الجواز' => 'passport_no', 'انتهاء الجواز' => 'passport_expiry',
                'الراتب الأساسي' => 'base_salary',
            ];
            return $map[ $label ] ?? '';
        };

        $print_field = static function ( string $label, string $value ) use ( $label_to_key ): void {
            if ( $value === '' ) {
                return;
            }
            $key      = $label_to_key( $label );
            $key_attr = $key ? ' data-i18n-key="' . esc_attr( $key ) . '"' : '';
            echo '<div class="sfs-hr-field-row">';
            echo '<div class="sfs-hr-field-label"' . $key_attr . '>' . esc_html( $label ) . '</div>';
            echo '<div class="sfs-hr-field-value">' . esc_html( $value ) . '</div>';
            echo '</div>';
        };

        // ─── Render ───────────────────────────────────────────────

        // Profile header
        echo '<div class="sfs-hr-profile-header">';
        echo '<div class="sfs-hr-profile-photo">';
        if ( $photo_id ) {
            echo wp_get_attachment_image(
                $photo_id,
                [ 96, 96 ],
                false,
                [
                    'class' => 'sfs-hr-emp-photo',
                    'style' => 'border-radius:50%;display:block;object-fit:cover;width:96px;height:96px;',
                ]
            );
        } else {
            echo '<div class="sfs-hr-emp-photo sfs-hr-emp-photo--empty">'
                . esc_html__( 'No photo', 'sfs-hr' )
                . '</div>';
        }
        echo '</div>';

        echo '<div class="sfs-hr-profile-header-main">';
        echo '<h4 class="sfs-hr-profile-name" data-name-en="' . esc_attr( $full_name ) . '" data-name-ar="' . esc_attr( $full_name_ar ?: $full_name ) . '">';
        echo esc_html( $full_name );
        echo '</h4>';

        echo '<div class="sfs-hr-profile-chips">';
        if ( $code !== '' ) {
            echo '<span class="sfs-hr-chip sfs-hr-chip--code">'
                . esc_html__( 'Code', 'sfs-hr' ) . ': ' . esc_html( $code )
                . '</span>';
        }
        if ( $status !== '' ) {
            echo '<span class="sfs-hr-chip sfs-hr-chip--status">' . esc_html( $status ) . '</span>';
        }
        echo '</div>';

        echo '<div class="sfs-hr-profile-meta-line">';
        $meta_parts = [];
        if ( $position !== '' ) {
            $meta_parts[] = $position;
        }
        if ( $dept_name !== '' ) {
            $meta_parts[] = $dept_name;
        }
        echo esc_html( implode( ' · ', $meta_parts ) );
        echo '</div>';

        if ( $can_self_clock ) {
            echo '<div class="sfs-hr-profile-actions">';
            echo '<a href="' . esc_url( $attendance_url ) . '" class="sfs-hr-att-btn" data-sfs-att-btn="1">';
            echo esc_html__( 'Attendance', 'sfs-hr' );
            echo '</a>';
            echo '</div>';
        }
        echo '</div>'; // .sfs-hr-profile-header-main
        echo '</div>'; // .sfs-hr-profile-header

        // Profile completion.
        if ( $profile_completion_pct < 100 ) {
            echo '<div class="sfs-hr-profile-completion">';
            echo '<div class="sfs-hr-completion-header">';
            echo '<span class="sfs-hr-completion-title" data-i18n-key="profile_completion">' . esc_html__( 'Profile Completion', 'sfs-hr' ) . '</span>';
            echo '<span class="sfs-hr-completion-pct">' . esc_html( $profile_completion_pct ) . '%</span>';
            echo '</div>';
            echo '<div class="sfs-hr-completion-bar">';
            echo '<div class="sfs-hr-completion-fill" style="width:' . esc_attr( $profile_completion_pct ) . '%"></div>';
            echo '</div>';

            if ( ! empty( $profile_missing ) ) {
                $missing_labels = [
                    'photo'       => __( 'Photo', 'sfs-hr' ),
                    'name'        => __( 'Full name', 'sfs-hr' ),
                    'email'       => __( 'Email', 'sfs-hr' ),
                    'phone'       => __( 'Phone', 'sfs-hr' ),
                    'gender'      => __( 'Gender', 'sfs-hr' ),
                    'position'    => __( 'Position', 'sfs-hr' ),
                    'department'  => __( 'Department', 'sfs-hr' ),
                    'hire_date'   => __( 'Hire date', 'sfs-hr' ),
                    'national_id' => __( 'National ID', 'sfs-hr' ),
                    'emergency'   => __( 'Emergency contact', 'sfs-hr' ),
                    'documents'   => __( 'Documents', 'sfs-hr' ),
                ];
                $missing_key_map = [
                    'photo' => 'photo', 'name' => 'full_name', 'email' => 'email',
                    'phone' => 'phone', 'gender' => 'gender', 'position' => 'position',
                    'department' => 'department', 'hire_date' => 'hire_date',
                    'national_id' => 'national_id', 'emergency' => 'emergency_contact',
                    'documents' => 'documents',
                ];

                echo '<div class="sfs-hr-completion-hint">';
                echo '<span data-i18n-key="missing">' . esc_html__( 'Missing', 'sfs-hr' ) . ':</span> ';
                foreach ( $profile_missing as $idx => $field ) {
                    $key   = $missing_key_map[ $field ] ?? $field;
                    $label = $missing_labels[ $field ] ?? $field;
                    echo '<span class="sfs-hr-missing-field" data-i18n-key="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</span>';
                    if ( $idx < count( $profile_missing ) - 1 ) {
                        echo ', ';
                    }
                }
                echo '</div>';
            }
            echo '</div>'; // .sfs-hr-profile-completion
        }

        // ── Profile detail groups ─────────────────────────────────
        echo '<div class="sfs-hr-profile-grid">';
        echo '<div class="sfs-hr-profile-col">';

        // Employment group.
        echo '<div class="sfs-hr-profile-group">';
        echo '<div class="sfs-hr-profile-group-title" data-i18n-key="employment">' . esc_html__( 'Employment', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-hr-profile-group-body">';
        $print_field( __( 'Status', 'sfs-hr' ), $status );
        $print_field( __( 'Gender', 'sfs-hr' ), $gender );
        $print_field( __( 'Department', 'sfs-hr' ), $dept_name );
        $print_field( __( 'Position', 'sfs-hr' ), $position );
        $print_field( __( 'Hire Date', 'sfs-hr' ), $hire_date );
        $print_field( __( 'Employee ID', 'sfs-hr' ), (string) $emp_id );
        if ( $wp_username !== '' ) {
            $print_field( __( 'WP Username', 'sfs-hr' ), $wp_username );
        }
        echo '</div></div>';

        // Contact group.
        echo '<div class="sfs-hr-profile-group">';
        echo '<div class="sfs-hr-profile-group-title" data-i18n-key="contact">' . esc_html__( 'Contact', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-hr-profile-group-body">';
        $print_field( __( 'Email', 'sfs-hr' ), $email );
        $print_field( __( 'Phone', 'sfs-hr' ), $phone );
        $print_field(
            __( 'Emergency contact', 'sfs-hr' ),
            trim( $emg_name . ( $emg_phone ? ' / ' . $emg_phone : '' ) )
        );
        echo '</div></div>';

        echo '</div>'; // .sfs-hr-profile-col

        echo '<div class="sfs-hr-profile-col">';

        // Identification group.
        echo '<div class="sfs-hr-profile-group">';
        echo '<div class="sfs-hr-profile-group-title" data-i18n-key="identification">' . esc_html__( 'Identification', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-hr-profile-group-body">';
        $print_field( __( 'National ID', 'sfs-hr' ), $national_id );
        $print_field( __( 'National ID Expiry', 'sfs-hr' ), $nid_exp );
        $print_field( __( 'Passport No.', 'sfs-hr' ), $passport_no );
        $print_field( __( 'Passport Expiry', 'sfs-hr' ), $pass_exp );
        echo '</div></div>';

        // Payroll group.
        echo '<div class="sfs-hr-profile-group">';
        echo '<div class="sfs-hr-profile-group-title" data-i18n-key="payroll">' . esc_html__( 'Payroll', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-hr-profile-group-body">';
        $print_field( __( 'Base salary', 'sfs-hr' ), $base_salary );
        echo '</div></div>';

        // Quick links.
        if ( ! $is_limited_access ) {
            echo '<div class="sfs-hr-profile-group sfs-hr-quick-links">';
            echo '<div class="sfs-hr-profile-group-title" data-i18n-key="quick_links">' . esc_html__( 'Quick Links', 'sfs-hr' ) . '</div>';
            echo '<div class="sfs-hr-profile-group-body">';
            echo '<a href="' . esc_url( $documents_url ) . '" class="sfs-hr-quick-link">';
            echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>';
            echo '<span data-i18n-key="my_documents">' . esc_html__( 'My Documents', 'sfs-hr' ) . '</span>';
            if ( $missing_docs_count > 0 ) {
                echo '<span class="sfs-hr-notification-dot" title="' . esc_attr(
                    sprintf(
                        _n( '%d missing document', '%d missing documents', $missing_docs_count, 'sfs-hr' ),
                        $missing_docs_count
                    )
                ) . '"></span>';
            }
            echo '<svg class="sfs-hr-quick-link-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';
            echo '</a>';
            echo '</div></div>';
        }

        echo '</div>'; // .sfs-hr-profile-col
        echo '</div>'; // .sfs-hr-profile-grid

        // ── Assets section ────────────────────────────────────────
        $this->render_assets( $emp_id );

        // ── Attendance button status script ───────────────────────
        if ( $can_self_clock ) {
            echo '<script>';
            echo '(function(){';
            echo '"use strict";';
            echo 'var btn=document.querySelector(\'.sfs-hr-att-btn[data-sfs-att-btn="1"]\');';
            echo 'if(!btn)return;';
            echo 'var url=' . wp_json_encode( esc_url_raw( rest_url( 'sfs-hr/v1/attendance/status' ) ) ) . ';';
            echo 'var nonce=' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ';';
            echo 'btn.textContent=' . wp_json_encode( __( 'Loading...', 'sfs-hr' ) ) . ';';
            echo 'fetch(url,{credentials:"same-origin",headers:{"X-WP-Nonce":nonce,"Cache-Control":"no-cache"}})';
            echo '.then(function(r){return r.ok?r.json():null})';
            echo '.then(function(d){';
            echo 'if(!d){btn.textContent=' . wp_json_encode( __( 'Attendance', 'sfs-hr' ) ) . ';return;}';
            echo 'var a=d.allow||d,l="";';
            echo 'if(a.in)l=' . wp_json_encode( __( 'Clock In', 'sfs-hr' ) ) . ';';
            echo 'else if(a.break_start)l=' . wp_json_encode( __( 'Start Break', 'sfs-hr' ) ) . ';';
            echo 'else if(a.break_end)l=' . wp_json_encode( __( 'End Break', 'sfs-hr' ) ) . ';';
            echo 'else if(a.out)l=' . wp_json_encode( __( 'Clock Out', 'sfs-hr' ) ) . ';';
            echo 'btn.textContent=l||' . wp_json_encode( __( 'Attendance', 'sfs-hr' ) ) . ';';
            echo '}).catch(function(){btn.textContent=' . wp_json_encode( __( 'Attendance', 'sfs-hr' ) ) . ';});';
            echo '})();</script>';
        }
    }

    /**
     * Render assets section (desktop table + mobile cards + photo modal).
     */
    private function render_assets( int $emp_id ): void {
        global $wpdb;

        $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
        $asset_table  = $wpdb->prefix . 'sfs_hr_assets';

        // Check table exists.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$assign_table}'" ) !== $assign_table ) {
            return;
        }

        $asset_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.*, ast.id AS asset_id, ast.name AS asset_name, ast.asset_code, ast.category
                 FROM {$assign_table} a
                 LEFT JOIN {$asset_table} ast ON ast.id = a.asset_id
                 WHERE a.employee_id = %d
                 ORDER BY a.created_at DESC LIMIT 200",
                $emp_id
            ),
            ARRAY_A
        );

        if ( empty( $asset_rows ) ) {
            return;
        }

        $status_badge_fn = static function ( string $st ): string {
            $st = trim( $st );
            if ( $st === '' ) {
                return '';
            }
            if ( method_exists( Helpers::class, 'asset_status_badge' ) ) {
                return Helpers::asset_status_badge( $st );
            }
            $label = ucfirst( str_replace( '_', ' ', $st ) );
            return '<span class="sfs-hr-asset-status-pill">' . esc_html( $label ) . '</span>';
        };

        $field_fn = static function ( string $label, ?string $value ): void {
            $value = trim( (string) $value );
            if ( $value === '' ) {
                return;
            }
            echo '<div class="sfs-hr-asset-field-row">';
            echo '<div class="sfs-hr-asset-field-label">' . esc_html( $label ) . '</div>';
            echo '<div class="sfs-hr-asset-field-value">' . esc_html( $value ) . '</div>';
            echo '</div>';
        };

        echo '<div class="sfs-hr-my-assets-frontend">';
        echo '<h4 data-i18n-key="my_assets">' . esc_html__( 'My Assets', 'sfs-hr' ) . '</h4>';

        // ── Desktop table ─────────────────────────────────────────
        echo '<div class="sfs-hr-assets-desktop">';
        echo '<table class="sfs-hr-table sfs-hr-assets-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Asset', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Code', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Category', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Start', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'End', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $asset_rows as $row ) {
            $this->render_asset_table_row( $row, $status_badge_fn );
        }

        echo '</tbody></table></div>';

        // ── Mobile cards ──────────────────────────────────────────
        echo '<div class="sfs-hr-assets-mobile">';
        foreach ( $asset_rows as $row ) {
            $this->render_asset_card( $row, $status_badge_fn, $field_fn );
        }
        echo '</div>';

        echo '</div>'; // .sfs-hr-my-assets-frontend

        // ── Asset photo modal ─────────────────────────────────────
        $this->render_asset_photo_modal();
    }

    /**
     * Render a single asset table row (desktop).
     */
    private function render_asset_table_row( array $row, callable $status_badge_fn ): void {
        $assignment_id = (int) ( $row['id'] ?? 0 );
        $row_status    = (string) ( $row['status'] ?? '' );
        $asset_id      = (int) ( $row['asset_id'] ?? 0 );
        $asset_name    = (string) ( $row['asset_name'] ?? '' );
        $asset_code    = (string) ( $row['asset_code'] ?? '' );
        $category      = (string) ( $row['category'] ?? '' );
        $start_date    = (string) ( $row['start_date'] ?? '' );
        $end_date      = (string) ( $row['end_date'] ?? '' );

        $title = $asset_name !== '' ? $asset_name : $asset_code;
        if ( $title === '' ) {
            $title = sprintf( __( 'Asset #%d', 'sfs-hr' ), $assignment_id );
        }

        $title_html = esc_html( $title );
        if ( $asset_id && ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr_assets_admin' ) ) ) {
            $edit_url   = add_query_arg(
                [ 'page' => 'sfs-hr-assets', 'tab' => 'assets', 'view' => 'edit', 'id' => $asset_id ],
                admin_url( 'admin.php' )
            );
            $title_html = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a>';
        }

        echo '<tr data-assignment-id="' . $assignment_id . '">';
        echo '<td>' . $title_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<td>' . esc_html( $asset_code ) . '</td>';
        echo '<td>' . esc_html( $category ) . '</td>';
        echo '<td>' . esc_html( $start_date ) . '</td>';
        echo '<td>' . esc_html( $end_date !== '' ? $end_date : '—' ) . '</td>';
        echo '<td>' . $status_badge_fn( $row_status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<td class="sfs-hr-asset-actions">';
        $this->render_asset_actions( $assignment_id, $row_status );
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Render a single asset mobile card.
     */
    private function render_asset_card( array $row, callable $status_badge_fn, callable $field_fn ): void {
        $assignment_id = (int) ( $row['id'] ?? 0 );
        $row_status    = (string) ( $row['status'] ?? '' );
        $asset_id      = (int) ( $row['asset_id'] ?? 0 );
        $asset_name    = (string) ( $row['asset_name'] ?? '' );
        $asset_code    = (string) ( $row['asset_code'] ?? '' );
        $category      = (string) ( $row['category'] ?? '' );
        $start_date    = (string) ( $row['start_date'] ?? '' );
        $end_date      = (string) ( $row['end_date'] ?? '' );

        $title = $asset_name !== '' ? $asset_name : $asset_code;
        if ( $title === '' ) {
            $title = sprintf( __( 'Asset #%d', 'sfs-hr' ), $assignment_id );
        }

        $title_html = esc_html( $title );
        if ( $asset_id && ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr_assets_admin' ) ) ) {
            $edit_url   = add_query_arg(
                [ 'page' => 'sfs-hr-assets', 'tab' => 'assets', 'view' => 'edit', 'id' => $asset_id ],
                admin_url( 'admin.php' )
            );
            $title_html = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a>';
        }

        echo '<details class="sfs-hr-asset-card">';
        echo '<summary class="sfs-hr-asset-summary">';
        echo '<span class="sfs-hr-asset-summary-title">' . $title_html . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<span class="sfs-hr-asset-summary-status">' . $status_badge_fn( $row_status ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</summary>';

        echo '<div class="sfs-hr-asset-body">';
        echo '<div class="sfs-hr-asset-fields">';
        $field_fn( __( 'Code', 'sfs-hr' ), $asset_code );
        $field_fn( __( 'Category', 'sfs-hr' ), $category );
        $field_fn( __( 'Start', 'sfs-hr' ), $start_date );
        $field_fn( __( 'End', 'sfs-hr' ), $end_date !== '' ? $end_date : '—' );
        echo '</div>';

        echo '<div class="sfs-hr-asset-actions">';
        $this->render_asset_actions( $assignment_id, $row_status );
        echo '</div>';

        echo '</div></details>';
    }

    /**
     * Render action buttons for an asset assignment.
     */
    private function render_asset_actions( int $assignment_id, string $status ): void {
        if ( ! $assignment_id ) {
            return;
        }

        if ( $status === 'pending_employee_approval' ) {
            // Approve
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfs-hr-asset-action-form" data-requires-photos="1">';
            wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id );
            echo '<input type="hidden" name="action" value="sfs_hr_assets_assign_decision"/>';
            echo '<input type="hidden" name="assignment_id" value="' . $assignment_id . '"/>';
            echo '<input type="hidden" name="decision" value="approve"/>';
            echo '<button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">' . esc_html__( 'Approve', 'sfs-hr' ) . '</button>';
            echo '</form>';

            // Reject
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfs-hr-asset-action-form">';
            wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id );
            echo '<input type="hidden" name="action" value="sfs_hr_assets_assign_decision"/>';
            echo '<input type="hidden" name="assignment_id" value="' . $assignment_id . '"/>';
            echo '<input type="hidden" name="decision" value="reject"/>';
            echo '<button type="submit" class="sfs-hr-asset-btn sfs-hr-asset-btn--reject">' . esc_html__( 'Reject', 'sfs-hr' ) . '</button>';
            echo '</form>';
        }

        if ( $status === 'return_requested' ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfs-hr-asset-action-form" data-requires-photos="1">';
            wp_nonce_field( 'sfs_hr_assets_return_decision_' . $assignment_id );
            echo '<input type="hidden" name="action" value="sfs_hr_assets_return_decision"/>';
            echo '<input type="hidden" name="assignment_id" value="' . $assignment_id . '"/>';
            echo '<input type="hidden" name="decision" value="approve"/>';
            echo '<button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">' . esc_html__( 'Confirm Return', 'sfs-hr' ) . '</button>';
            echo '</form>';
        }
    }

    /**
     * Render the asset photo verification modal and its JS.
     */
    private function render_asset_photo_modal(): void {
        ?>
        <div id="sfs-hr-asset-photo-modal" class="sfs-hr-modal" style="display:none;">
            <div class="sfs-hr-modal-backdrop"></div>
            <div class="sfs-hr-modal-dialog">
                <div class="sfs-hr-modal-header">
                    <h4 class="sfs-hr-modal-title"><?php esc_html_e( 'Verify Asset Handover', 'sfs-hr' ); ?></h4>
                    <button type="button" class="sfs-hr-modal-close" aria-label="<?php esc_attr_e( 'Close', 'sfs-hr' ); ?>">&times;</button>
                </div>
                <div class="sfs-hr-modal-body">
                    <p class="sfs-hr-modal-step-title"><?php esc_html_e( 'Step 1 of 2: Take a selfie', 'sfs-hr' ); ?></p>
                    <div class="sfs-hr-modal-camera">
                        <video autoplay playsinline class="sfs-hr-modal-video"></video>
                        <canvas class="sfs-hr-modal-canvas" style="display:none;"></canvas>
                    </div>
                    <div class="sfs-hr-modal-previews">
                        <div class="sfs-hr-modal-preview-block">
                            <span class="sfs-hr-modal-preview-label"><?php esc_html_e( 'Selfie', 'sfs-hr' ); ?></span>
                            <img class="sfs-hr-preview-selfie" alt="" style="display:none;"/>
                        </div>
                        <div class="sfs-hr-modal-preview-block">
                            <span class="sfs-hr-modal-preview-label"><?php esc_html_e( 'Asset', 'sfs-hr' ); ?></span>
                            <img class="sfs-hr-preview-asset" alt="" style="display:none;"/>
                        </div>
                    </div>
                </div>
                <div class="sfs-hr-modal-footer">
                    <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-cancel-btn"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></button>
                    <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-back-btn" style="display:none;"><?php esc_html_e( 'Back', 'sfs-hr' ); ?></button>
                    <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-capture-btn"><?php esc_html_e( 'Capture', 'sfs-hr' ); ?></button>
                    <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-next-btn" style="display:none;" disabled><?php esc_html_e( 'Next', 'sfs-hr' ); ?></button>
                    <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-done-btn" style="display:none;" disabled><?php esc_html_e( 'Done & Submit', 'sfs-hr' ); ?></button>
                </div>
            </div>
        </div>
        <script>
        (function(){
            document.addEventListener('DOMContentLoaded',function(){
                var modal=document.getElementById('sfs-hr-asset-photo-modal');
                if(!modal)return;
                if(window.matchMedia&&window.matchMedia('(min-width:768px)').matches){
                    document.querySelectorAll('.sfs-hr-asset-card').forEach(function(d){d.setAttribute('open','open');});
                }
                var backdrop=modal.querySelector('.sfs-hr-modal-backdrop'),
                    closeBtn=modal.querySelector('.sfs-hr-modal-close'),
                    cancelBtn=modal.querySelector('.sfs-hr-modal-cancel-btn'),
                    backBtn=modal.querySelector('.sfs-hr-modal-back-btn'),
                    captureBtn=modal.querySelector('.sfs-hr-modal-capture-btn'),
                    nextBtn=modal.querySelector('.sfs-hr-modal-next-btn'),
                    doneBtn=modal.querySelector('.sfs-hr-modal-done-btn'),
                    stepTitle=modal.querySelector('.sfs-hr-modal-step-title'),
                    video=modal.querySelector('.sfs-hr-modal-video'),
                    canvas=modal.querySelector('.sfs-hr-modal-canvas'),
                    previewSelf=modal.querySelector('.sfs-hr-preview-selfie'),
                    previewAsset=modal.querySelector('.sfs-hr-preview-asset'),
                    currentForm=null,currentStep=1,stream=null,selfieData='',assetData='';

                function updateStepUI(){
                    if(currentStep===1){
                        stepTitle.textContent=<?php echo wp_json_encode( __( 'Step 1 of 2: Take a selfie', 'sfs-hr' ) ); ?>;
                        if(backBtn)backBtn.style.display='none';
                        if(captureBtn)captureBtn.style.display='inline-block';
                        if(nextBtn){nextBtn.style.display='inline-block';nextBtn.disabled=!selfieData;}
                        if(doneBtn){doneBtn.style.display='none';doneBtn.disabled=true;}
                    }else{
                        stepTitle.textContent=<?php echo wp_json_encode( __( 'Step 2 of 2: Take photo of asset', 'sfs-hr' ) ); ?>;
                        if(backBtn)backBtn.style.display='inline-block';
                        if(captureBtn)captureBtn.style.display='inline-block';
                        if(nextBtn)nextBtn.style.display='none';
                        if(doneBtn){doneBtn.style.display='inline-block';doneBtn.disabled=!assetData;}
                    }
                }
                function stopStream(){
                    if(stream){try{stream.getTracks().forEach(function(t){t.stop()});}catch(e){}}
                    stream=null;if(video)video.srcObject=null;
                }
                function startStreamForStep(step){
                    stopStream();
                    if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||!video){
                        alert(<?php echo wp_json_encode( __( 'Camera API not supported in this browser.', 'sfs-hr' ) ); ?>);return;
                    }
                    var c=step===1?{video:{facingMode:'user'}}:{video:{facingMode:{ideal:'environment'}}};
                    function ok(s){stream=s;try{video.srcObject=s;var p=video.play();if(p&&p.catch)p.catch(function(){});}catch(e){}}
                    navigator.mediaDevices.getUserMedia(c).then(ok).catch(function(){
                        navigator.mediaDevices.getUserMedia({video:true}).then(ok).catch(function(){
                            alert(<?php echo wp_json_encode( __( 'Unable to access the camera. Please allow camera permissions.', 'sfs-hr' ) ); ?>);
                        });
                    });
                }
                function openModal(form){
                    currentForm=form;currentStep=1;selfieData='';assetData='';
                    if(previewSelf){previewSelf.style.display='none';previewSelf.src='';}
                    if(previewAsset){previewAsset.style.display='none';previewAsset.src='';}
                    modal.style.display='block';updateStepUI();startStreamForStep(1);
                }
                function closeModal(){
                    stopStream();modal.style.display='none';currentForm=null;currentStep=1;selfieData='';assetData='';
                }
                function captureCurrent(){
                    if(!video||!canvas)return;
                    var w=video.videoWidth||640,h=video.videoHeight||480;
                    if(!w||!h)return;canvas.width=w;canvas.height=h;
                    canvas.getContext('2d').drawImage(video,0,0,w,h);
                    var d=canvas.toDataURL('image/jpeg',0.8);
                    if(currentStep===1){selfieData=d;if(previewSelf){previewSelf.src=d;previewSelf.style.display='block';}}
                    else{assetData=d;if(previewAsset){previewAsset.src=d;previewAsset.style.display='block';}}
                    updateStepUI();
                }
                function submitWithPhotos(){
                    if(!currentForm)return;
                    var si=currentForm.querySelector('input[name="selfie_data"]');
                    if(!si){si=document.createElement('input');si.type='hidden';si.name='selfie_data';currentForm.appendChild(si);}
                    si.value=selfieData||'';
                    var ai=currentForm.querySelector('input[name="asset_data"]');
                    if(!ai){ai=document.createElement('input');ai.type='hidden';ai.name='asset_data';currentForm.appendChild(ai);}
                    ai.value=assetData||'';
                    currentForm.submit();closeModal();
                }
                if(captureBtn)captureBtn.addEventListener('click',function(e){e.preventDefault();captureCurrent();});
                if(nextBtn)nextBtn.addEventListener('click',function(e){e.preventDefault();if(!selfieData)return;currentStep=2;updateStepUI();startStreamForStep(2);});
                if(backBtn)backBtn.addEventListener('click',function(e){e.preventDefault();currentStep=1;updateStepUI();startStreamForStep(1);});
                if(doneBtn)doneBtn.addEventListener('click',function(e){e.preventDefault();submitWithPhotos();});
                if(cancelBtn)cancelBtn.addEventListener('click',function(e){e.preventDefault();closeModal();});
                if(closeBtn)closeBtn.addEventListener('click',function(e){e.preventDefault();closeModal();});
                if(backdrop)backdrop.addEventListener('click',function(e){e.preventDefault();closeModal();});
                var container=document.querySelector('.sfs-hr-my-assets-frontend');
                if(!container)return;
                container.addEventListener('click',function(e){
                    var t=e.target;
                    if(!t||!t.classList||!t.classList.contains('sfs-hr-asset-btn--approve'))return;
                    var form=t.closest('form');
                    if(!form||form.getAttribute('data-requires-photos')!=='1')return;
                    e.preventDefault();openModal(form);
                });
            });
        })();
        </script>
        <?php
    }
}
