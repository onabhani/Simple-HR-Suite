<?php
/**
 * Profile Tab - Modern employee profile card.
 *
 * Redesigned layout: cover header with avatar overlay, status/code chips,
 * grouped info sections in cards, profile completion ring, assets accordion.
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
        if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own profile.', 'sfs-hr' ) . '</p>';
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

        // Status color class.
        $status_class = 'sfs-p-chip--active';
        if ( $status_raw === 'inactive' ) {
            $status_class = 'sfs-p-chip--inactive';
        } elseif ( $status_raw === 'terminated' ) {
            $status_class = 'sfs-p-chip--terminated';
        }

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

        // Tab URLs.
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

        // Initials for avatar fallback.
        $initials = strtoupper( mb_substr( $first_name, 0, 1 ) . mb_substr( $last_name, 0, 1 ) );

        // ─── Render ───────────────────────────────────────────────
        echo '<div class="sfs-p">';

        // ── Cover + Avatar Header ─────────────────────────────────
        echo '<div class="sfs-p-cover">';
        echo '<div class="sfs-p-cover-inner">';

        // Avatar
        echo '<div class="sfs-p-avatar">';
        if ( $photo_id ) {
            echo wp_get_attachment_image(
                $photo_id,
                [ 96, 96 ],
                false,
                [
                    'class' => 'sfs-p-avatar-img',
                    'alt'   => esc_attr( $full_name ),
                ]
            );
        } else {
            echo '<div class="sfs-p-avatar-initials">' . esc_html( $initials ?: '?' ) . '</div>';
        }
        echo '</div>';

        // Name + meta
        echo '<div class="sfs-p-identity">';
        echo '<h2 class="sfs-p-name" data-name-en="' . esc_attr( $full_name ) . '" data-name-ar="' . esc_attr( $full_name_ar ?: $full_name ) . '">';
        echo esc_html( $full_name );
        echo '</h2>';

        if ( $position !== '' || $dept_name !== '' ) {
            $meta = array_filter( [ $position, $dept_name ] );
            echo '<div class="sfs-p-subtitle">' . esc_html( implode( ' · ', $meta ) ) . '</div>';
        }

        // Chips row
        echo '<div class="sfs-p-chips">';
        if ( $status !== '' ) {
            echo '<span class="sfs-p-chip ' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</span>';
        }
        if ( $code !== '' ) {
            echo '<span class="sfs-p-chip sfs-p-chip--code">' . esc_html( $code ) . '</span>';
        }
        echo '</div>';

        echo '</div>'; // .sfs-p-identity

        // Action buttons
        if ( $can_self_clock || ! $is_limited_access ) {
            echo '<div class="sfs-p-header-actions">';
            if ( $can_self_clock ) {
                echo '<a href="' . esc_url( $attendance_url ) . '" class="sfs-p-action-btn sfs-p-action-btn--primary" data-sfs-att-btn="1">';
                echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                echo '<span>' . esc_html__( 'Attendance', 'sfs-hr' ) . '</span>';
                echo '</a>';
            }
            if ( ! $is_limited_access ) {
                echo '<a href="' . esc_url( $documents_url ) . '" class="sfs-p-action-btn">';
                echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                echo '<span data-i18n-key="my_documents">' . esc_html__( 'Documents', 'sfs-hr' ) . '</span>';
                if ( $missing_docs_count > 0 ) {
                    echo '<span class="sfs-p-action-badge">' . (int) $missing_docs_count . '</span>';
                }
                echo '</a>';
            }
            echo '</div>';
        }

        echo '</div>'; // .sfs-p-cover-inner
        echo '</div>'; // .sfs-p-cover

        // ── Profile completion (only if < 100%) ────────────────
        if ( $profile_completion_pct < 100 ) {
            $this->render_completion_ring( $profile_completion_pct, $profile_missing );
        }

        // ── Info Cards Grid ───────────────────────────────────────
        echo '<div class="sfs-p-cards">';

        // Employment
        echo '<div class="sfs-p-card">';
        echo '<div class="sfs-p-card-header">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>';
        echo '<h3 data-i18n-key="employment">' . esc_html__( 'Employment', 'sfs-hr' ) . '</h3>';
        echo '</div>';
        echo '<div class="sfs-p-card-body">';
        $this->field( __( 'Status', 'sfs-hr' ), $status, 'status' );
        $this->field( __( 'Gender', 'sfs-hr' ), $gender, 'gender' );
        $this->field( __( 'Department', 'sfs-hr' ), $dept_name, 'department' );
        $this->field( __( 'Position', 'sfs-hr' ), $position, 'position' );
        $this->field( __( 'Hire Date', 'sfs-hr' ), $hire_date, 'hire_date' );
        $this->field( __( 'Employee ID', 'sfs-hr' ), (string) $emp_id, 'employee_id' );
        if ( $wp_username !== '' ) {
            $this->field( __( 'WP Username', 'sfs-hr' ), $wp_username, 'wp_username' );
        }
        echo '</div></div>';

        // Contact
        echo '<div class="sfs-p-card">';
        echo '<div class="sfs-p-card-header">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
        echo '<h3 data-i18n-key="contact">' . esc_html__( 'Contact', 'sfs-hr' ) . '</h3>';
        echo '</div>';
        echo '<div class="sfs-p-card-body">';
        $this->field( __( 'Email', 'sfs-hr' ), $email, 'email' );
        $this->field( __( 'Phone', 'sfs-hr' ), $phone, 'phone' );
        $this->field(
            __( 'Emergency Contact', 'sfs-hr' ),
            trim( $emg_name . ( $emg_phone ? ' / ' . $emg_phone : '' ) ),
            'emergency_contact'
        );
        echo '</div></div>';

        // Identification
        echo '<div class="sfs-p-card">';
        echo '<div class="sfs-p-card-header">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M15 8h2"/><path d="M15 12h2"/><path d="M7 16h10"/></svg>';
        echo '<h3 data-i18n-key="identification">' . esc_html__( 'Identification', 'sfs-hr' ) . '</h3>';
        echo '</div>';
        echo '<div class="sfs-p-card-body">';
        $this->field( __( 'National ID', 'sfs-hr' ), $national_id, 'national_id' );
        $this->field( __( 'National ID Expiry', 'sfs-hr' ), $nid_exp, 'national_id_expiry' );
        $this->field( __( 'Passport No.', 'sfs-hr' ), $passport_no, 'passport_no' );
        $this->field( __( 'Passport Expiry', 'sfs-hr' ), $pass_exp, 'passport_expiry' );
        echo '</div></div>';

        // Payroll
        echo '<div class="sfs-p-card">';
        echo '<div class="sfs-p-card-header">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
        echo '<h3 data-i18n-key="payroll">' . esc_html__( 'Payroll', 'sfs-hr' ) . '</h3>';
        echo '</div>';
        echo '<div class="sfs-p-card-body">';
        $this->field( __( 'Base Salary', 'sfs-hr' ), $base_salary, 'base_salary' );
        echo '</div></div>';

        echo '</div>'; // .sfs-p-cards

        // ── Assets section ────────────────────────────────────────
        $this->render_assets( $emp_id );

        echo '</div>'; // .sfs-p

        // ── Attendance button status script ───────────────────────
        if ( $can_self_clock ) {
            echo '<script>';
            echo '(function(){';
            echo '"use strict";';
            echo 'var btn=document.querySelector(\'.sfs-p-action-btn[data-sfs-att-btn="1"] span\');';
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
     * Render a single field row.
     */
    private function field( string $label, string $value, string $key = '' ): void {
        if ( $value === '' ) {
            return;
        }
        $key_attr = $key ? ' data-i18n-key="' . esc_attr( $key ) . '"' : '';
        echo '<div class="sfs-p-field">';
        echo '<span class="sfs-p-field-label"' . $key_attr . '>' . esc_html( $label ) . '</span>';
        echo '<span class="sfs-p-field-value">' . esc_html( $value ) . '</span>';
        echo '</div>';
    }

    /**
     * Render profile completion ring + missing fields.
     */
    private function render_completion_ring( int $pct, array $missing ): void {
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

        // SVG ring: circumference = 2 * PI * 40 = 251.3
        $circumference = 251.3;
        $offset        = $circumference - ( $circumference * $pct / 100 );

        echo '<div class="sfs-p-completion">';

        echo '<div class="sfs-p-completion-ring">';
        echo '<svg viewBox="0 0 100 100">';
        echo '<circle cx="50" cy="50" r="40" fill="none" stroke="var(--sfs-border, #e5e7eb)" stroke-width="6"/>';
        echo '<circle cx="50" cy="50" r="40" fill="none" stroke="var(--sfs-primary, #0f4c5c)" stroke-width="6" '
           . 'stroke-linecap="round" stroke-dasharray="' . esc_attr( $circumference ) . '" '
           . 'stroke-dashoffset="' . esc_attr( $offset ) . '" '
           . 'transform="rotate(-90 50 50)" class="sfs-p-ring-progress"/>';
        echo '</svg>';
        echo '<span class="sfs-p-ring-pct">' . esc_html( $pct ) . '%</span>';
        echo '</div>';

        echo '<div class="sfs-p-completion-info">';
        echo '<span class="sfs-p-completion-title" data-i18n-key="profile_completion">' . esc_html__( 'Profile Completion', 'sfs-hr' ) . '</span>';
        if ( ! empty( $missing ) ) {
            echo '<div class="sfs-p-completion-missing">';
            echo '<span data-i18n-key="missing">' . esc_html__( 'Missing', 'sfs-hr' ) . ':</span> ';
            $parts = [];
            foreach ( $missing as $field ) {
                $label   = $missing_labels[ $field ] ?? $field;
                $parts[] = '<span class="sfs-p-missing-tag">' . esc_html( $label ) . '</span>';
            }
            echo implode( ' ', $parts );
            echo '</div>';
        }
        echo '</div>';

        echo '</div>'; // .sfs-p-completion
    }

    /**
     * Render assets section.
     */
    private function render_assets( int $emp_id ): void {
        global $wpdb;

        $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
        $asset_table  = $wpdb->prefix . 'sfs_hr_assets';

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $assign_table ) ) !== $assign_table ) {
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

        echo '<div class="sfs-p-card" style="grid-column:1/-1;">';
        echo '<div class="sfs-p-card-header">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
        echo '<h3 data-i18n-key="my_assets">' . esc_html__( 'My Assets', 'sfs-hr' ) . '</h3>';
        echo '</div>';

        echo '<div class="sfs-p-card-body" style="padding:0;">';
        echo '<div class="sfs-history-list">';
        foreach ( $asset_rows as $row ) {
            $this->render_asset_card( $row, $status_badge_fn );
        }
        echo '</div>';
        echo '</div></div>';

        $this->render_asset_photo_modal();
    }

    /**
     * Render a single asset card.
     */
    private function render_asset_card( array $row, callable $status_badge_fn ): void {
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

        echo '<details class="sfs-history-card">';
        echo '<summary>';
        echo '<div class="sfs-history-card-info">';
        echo '<span class="sfs-history-card-title">' . $title_html . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<span class="sfs-history-card-meta">';
        if ( $asset_code ) {
            echo esc_html( $asset_code );
        }
        if ( $category ) {
            echo ( $asset_code ? ' · ' : '' ) . esc_html( $category );
        }
        echo '</span>';
        echo '</div>';
        echo $status_badge_fn( $row_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</summary>';

        echo '<div class="sfs-history-card-body">';
        echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Code', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $asset_code ?: '—' ) . '</span></div>';
        echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Category', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $category ?: '—' ) . '</span></div>';
        echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Start', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $start_date ?: '—' ) . '</span></div>';
        echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'End', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $end_date !== '' ? $end_date : '—' ) . '</span></div>';

        echo '<div style="margin-top:8px;display:flex;gap:8px;">';
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
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfs-hr-asset-action-form" data-requires-photos="1">';
            wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id );
            echo '<input type="hidden" name="action" value="sfs_hr_assets_assign_decision"/>';
            echo '<input type="hidden" name="assignment_id" value="' . $assignment_id . '"/>';
            echo '<input type="hidden" name="decision" value="approve"/>';
            echo '<button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">' . esc_html__( 'Approve', 'sfs-hr' ) . '</button>';
            echo '</form>';

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
                var container=document.querySelector('.sfs-p');
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
