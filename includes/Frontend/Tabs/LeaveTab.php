<?php
/**
 * Leave Tab - Frontend leave dashboard, balance cards and request form
 *
 * Redesigned with §10.1 design system + §10.2 leave balance visual cards.
 * Card-based history (no tables), request form in modal, history before balances.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Modules\Leave\Leave_UI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LeaveTab implements TabInterface {

    /** Color palette for leave type cards (cycles). */
    private const CARD_COLORS = [ 'sky', 'rose', 'violet', 'amber', 'emerald', 'indigo', 'pink', 'orange', 'teal', 'slate' ];

    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own leave information.', 'sfs-hr' ) . '</p>';
            return;
        }

        if ( $emp_id <= 0 ) {
            echo '<p>' . esc_html__( 'Employee record not found.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;

        $req_table  = $wpdb->prefix . 'sfs_hr_leave_requests';
        $type_table = $wpdb->prefix . 'sfs_hr_leave_types';
        $bal_table  = $wpdb->prefix . 'sfs_hr_leave_balances';

        $year  = (int) current_time( 'Y' );
        $today = current_time( 'Y-m-d' );

        // Active leave types
        $types = $wpdb->get_results(
            "SELECT id, name FROM {$type_table} WHERE active = 1 ORDER BY name ASC"
        );

        // Leave history
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, t.name AS type_name
                 FROM {$req_table} r
                 LEFT JOIN {$type_table} t ON t.id = r.type_id
                 WHERE r.employee_id = %d
                 ORDER BY r.created_at DESC, r.id DESC
                 LIMIT 100",
                $emp_id
            )
        );

        // KPI data
        $requests_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$req_table} WHERE employee_id = %d AND YEAR(start_date) = %d",
                $emp_id,
                $year
            )
        );

        $balances = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.*, t.name, t.is_annual
                 FROM {$bal_table} b
                 JOIN {$type_table} t ON t.id = b.type_id
                 WHERE b.employee_id = %d AND b.year = %d
                 ORDER BY t.is_annual DESC, t.name ASC",
                $emp_id,
                $year
            ),
            ARRAY_A
        );

        $total_used       = 0;
        $annual_available = 0;
        foreach ( $balances as $b ) {
            $total_used += (int) ( $b['used'] ?? 0 );
            if ( $annual_available === 0 && ! empty( $b['is_annual'] ) ) {
                $annual_available = (int) ( $b['closing'] ?? 0 );
            }
        }

        $pending_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$req_table} WHERE employee_id = %d AND status = 'pending' AND YEAR(start_date) = %d",
                $emp_id,
                $year
            )
        );

        // Next approved leave
        $next_leave_text = '<span data-i18n-key="no_upcoming_leave">' . esc_html__( 'No upcoming leave', 'sfs-hr' ) . '</span>';
        $next_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$req_table} WHERE employee_id = %d AND status = 'approved' AND start_date >= %s ORDER BY start_date ASC LIMIT 1",
                $emp_id,
                $today
            )
        );
        if ( $next_row ) {
            $start = $next_row->start_date ?: '';
            $end   = $next_row->end_date ?: '';
            $period = ( $start && $end && $end !== $start ) ? ( $start . ' → ' . $end ) : $start;
            $days = $this->calc_days( $next_row );
            $next_leave_text = sprintf( '%s · %d %s', esc_html( $period ), $days, $days === 1 ? esc_html__( 'day', 'sfs-hr' ) : esc_html__( 'days', 'sfs-hr' ) );
        }

        // Build the page URL for card links
        $base_url = remove_query_arg( [ 'leave_err', 'leave_msg', 'sfs_hr_tab' ] );
        $leave_url = add_query_arg( 'sfs_hr_tab', 'leave', $base_url );

        // ─── Render ────────────────────────────────────────────
        $this->render_flash_messages();

        echo '<div class="sfs-section" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">';
        echo '<div>';
        echo '<h2 class="sfs-section-title" data-i18n-key="my_leave_dashboard" style="margin:0;">' . esc_html__( 'My Leave Dashboard', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-section-subtitle" style="margin:2px 0 0;">' . esc_html( ( $emp['first_name'] ?? '' ) . ' ' . ( $emp['last_name'] ?? '' ) ) . ' · ' . esc_html__( 'Year', 'sfs-hr' ) . ' ' . $year . '</p>';
        echo '</div>';
        echo '<button type="button" class="sfs-btn sfs-btn--primary" onclick="document.getElementById(\'sfs-leave-modal\').classList.add(\'sfs-modal-active\')" data-i18n-key="new_leave_request" style="white-space:nowrap;">';
        echo '<svg viewBox="0 0 24 24" style="width:16px;height:16px;margin-inline-end:4px;vertical-align:-2px;" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
        echo esc_html__( 'New Leave Request', 'sfs-hr' );
        echo '</button>';
        echo '</div>';

        // KPI strip
        $this->render_kpis( $requests_count, $annual_available, $total_used, $pending_count, $next_leave_text );

        // Leave history (card-based, before balances)
        $this->render_history( $rows );

        // §10.2 Leave Balance Cards
        $this->render_balance_cards( $balances, $leave_url );

        // Request form modal
        $this->render_request_modal( $emp_id, $types );
    }

    /* ──────────────────────────────────────────────────────────
       Flash Messages
    ────────────────────────────────────────────────────────── */
    private function render_flash_messages(): void {
        if ( ! empty( $_GET['leave_err'] ) ) {
            $code = sanitize_key( $_GET['leave_err'] );
            $msgs = [
                'no_employee'    => __( 'Your account is not linked to an employee record.', 'sfs-hr' ),
                'missing_fields' => __( 'Please fill in all required fields.', 'sfs-hr' ),
                'invalid_dates'  => __( 'Invalid dates. End date must be on or after the start date.', 'sfs-hr' ),
                'overlap'        => __( 'You already have a pending or approved request overlapping these dates.', 'sfs-hr' ),
                'doc_upload'     => __( 'Supporting document upload failed. Please try again.', 'sfs-hr' ),
                'doc_required'   => __( 'A supporting document is required for sick leave.', 'sfs-hr' ),
                'db_error'       => __( 'Something went wrong saving your request. Please try again.', 'sfs-hr' ),
            ];
            $msg = $msgs[ $code ] ?? __( 'An error occurred. Please try again.', 'sfs-hr' );
            echo '<div class="sfs-alert sfs-alert--error">';
            echo '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" fill="none" stroke-width="2"/><line x1="12" y1="8" x2="12" y2="12" stroke="currentColor" stroke-width="2"/><line x1="12" y1="16" x2="12.01" y2="16" stroke="currentColor" stroke-width="2"/></svg>';
            echo '<span>' . esc_html( $msg ) . '</span></div>';
        } elseif ( ! empty( $_GET['leave_msg'] ) && $_GET['leave_msg'] === 'submitted' ) {
            echo '<div class="sfs-alert sfs-alert--success">';
            echo '<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" fill="none" stroke-width="2"/><polyline points="22 4 12 14.01 9 11.01" stroke="currentColor" fill="none" stroke-width="2"/></svg>';
            echo '<span>' . esc_html__( 'Your leave request has been submitted successfully.', 'sfs-hr' ) . '</span></div>';
        }
    }

    /* ──────────────────────────────────────────────────────────
       KPI Strip
    ────────────────────────────────────────────────────────── */
    private function render_kpis( int $requests, int $annual, int $used, int $pending, string $next_text ): void {
        echo '<div class="sfs-kpi-grid">';

        // Requests
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#eff6ff;"><svg viewBox="0 0 24 24" stroke="#3b82f6"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="requests_this_year">' . esc_html__( 'Requests', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $requests . '</div>';
        echo '</div>';

        // Annual balance
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#ecfdf5;"><svg viewBox="0 0 24 24" stroke="#10b981"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="annual_leave_available">' . esc_html__( 'Annual balance', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $annual . ' <span class="sfs-kpi-sub">' . esc_html__( 'days', 'sfs-hr' ) . '</span></div>';
        echo '</div>';

        // Used
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" stroke="#f59e0b"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="total_used_this_year">' . esc_html__( 'Used', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $used;
        if ( $pending > 0 ) {
            echo ' <span class="sfs-kpi-sub">+ ' . $pending . ' ' . esc_html__( 'pending', 'sfs-hr' ) . '</span>';
        }
        echo '</div></div>';

        // Next leave
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#fce7f3;"><svg viewBox="0 0 24 24" stroke="#ec4899"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="next_approved_leave">' . esc_html__( 'Next leave', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value" style="font-size:14px;font-weight:600;">' . $next_text . '</div>';
        echo '</div>';

        echo '</div>'; // .sfs-kpi-grid
    }

    /* ──────────────────────────────────────────────────────────
       §10.2 Leave Balance Cards
    ────────────────────────────────────────────────────────── */
    private function render_balance_cards( array $balances, string $leave_url ): void {
        if ( empty( $balances ) ) {
            return;
        }

        echo '<div class="sfs-section" style="margin-bottom:8px;">';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0 0 12px;" data-i18n-key="leave_balances">' . esc_html__( 'Leave Balances', 'sfs-hr' ) . '</h3>';
        echo '</div>';

        echo '<div class="sfs-balance-grid">';

        $colors = self::CARD_COLORS;
        $i = 0;
        foreach ( $balances as $b ) {
            $name      = esc_html( $b['name'] ?? __( 'Leave', 'sfs-hr' ) );
            $total     = (int) ( $b['closing'] ?? 0 );
            $consumed  = (int) ( $b['used'] ?? 0 );
            $applied   = (int) ( $b['pending'] ?? 0 );
            $remaining = max( 0, $total - $consumed );
            $pct       = $total > 0 ? min( 100, round( ( $consumed / $total ) * 100 ) ) : 0;
            $color     = $colors[ $i % count( $colors ) ];
            $i++;

            // Circumference for SVG ring (radius=22, C=2πr≈138.2)
            $circ   = 138.23;
            $offset = $circ - ( $circ * $pct / 100 );

            $type_id = (int) ( $b['type_id'] ?? 0 );
            $card_url = $type_id > 0 ? add_query_arg( 'leave_type', $type_id, $leave_url ) : $leave_url;

            echo '<a href="' . esc_url( $card_url ) . '" class="sfs-balance-card" data-color="' . esc_attr( $color ) . '">';

            // Head: name + ring
            echo '<div class="sfs-balance-card-head">';
            echo '<div class="sfs-balance-card-name">' . $name . '</div>';
            echo '<div class="sfs-balance-ring">';
            echo '<svg viewBox="0 0 52 52"><circle class="sfs-balance-ring-bg" cx="26" cy="26" r="22"/>';
            echo '<circle class="sfs-balance-ring-fill" cx="26" cy="26" r="22" stroke-dasharray="' . $circ . '" stroke-dashoffset="' . $offset . '"/></svg>';
            echo '<div class="sfs-balance-ring-value">' . $remaining . '</div>';
            echo '</div></div>';

            // Metrics row
            echo '<div class="sfs-balance-metrics">';
            echo '<div><div class="sfs-balance-metric-val">' . $total . '</div><div class="sfs-balance-metric-label" data-i18n-key="total_lbl">' . esc_html__( 'Total', 'sfs-hr' ) . '</div></div>';
            echo '<div><div class="sfs-balance-metric-val">' . $consumed . '</div><div class="sfs-balance-metric-label" data-i18n-key="consumed_lbl">' . esc_html__( 'Consumed', 'sfs-hr' ) . '</div></div>';
            echo '<div><div class="sfs-balance-metric-val">' . $applied . '</div><div class="sfs-balance-metric-label" data-i18n-key="applied_lbl">' . esc_html__( 'Applied', 'sfs-hr' ) . '</div></div>';
            echo '</div>';

            // Progress bar
            echo '<div class="sfs-balance-bar"><div class="sfs-balance-bar-fill" style="width:' . $pct . '%;"></div></div>';

            echo '</a>'; // .sfs-balance-card
        }

        echo '</div>'; // .sfs-balance-grid
    }

    /* ──────────────────────────────────────────────────────────
       Request Form Modal
    ────────────────────────────────────────────────────────── */
    private function render_request_modal( int $emp_id, array $types ): void {
        echo '<div id="sfs-leave-modal" class="sfs-form-modal-overlay">';
        echo '<div class="sfs-form-modal-backdrop" onclick="document.getElementById(\'sfs-leave-modal\').classList.remove(\'sfs-modal-active\')"></div>';
        echo '<div class="sfs-form-modal">';

        // Header
        echo '<div class="sfs-form-modal-header">';
        echo '<h3 class="sfs-form-modal-title" data-i18n-key="request_new_leave">' . esc_html__( 'Request New Leave', 'sfs-hr' ) . '</h3>';
        echo '<button type="button" class="sfs-form-modal-close" onclick="document.getElementById(\'sfs-leave-modal\').classList.remove(\'sfs-modal-active\')" aria-label="' . esc_attr__( 'Close', 'sfs-hr' ) . '">&times;</button>';
        echo '</div>';

        // Body
        echo '<div class="sfs-form-modal-body">';

        if ( empty( $types ) ) {
            echo '<p class="sfs-form-hint" data-i18n-key="leave_types_not_configured">' . esc_html__( 'Leave types are not configured yet. Please contact HR.', 'sfs-hr' ) . '</p>';
            echo '</div></div></div>';
            return;
        }

        $action_url = admin_url( 'admin-post.php' );

        echo '<form method="post" action="' . esc_url( $action_url ) . '" enctype="multipart/form-data">';
        wp_nonce_field( 'sfs_hr_leave_request_self' );
        echo '<input type="hidden" name="action" value="sfs_hr_leave_request_self" />';
        echo '<input type="hidden" name="employee_id" value="' . $emp_id . '" />';

        echo '<div class="sfs-form-fields">';

        // Leave type
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="leave_type">' . esc_html__( 'Leave type', 'sfs-hr' ) . ' <span class="sfs-required">*</span></label>';
        echo '<select name="type_id" class="sfs-select" id="sfs-leave-type-select" required>';
        echo '<option value="" data-i18n-key="select_type">' . esc_html__( 'Select type', 'sfs-hr' ) . '</option>';
        foreach ( $types as $type ) {
            echo '<option value="' . (int) $type->id . '">' . esc_html( $type->name ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Dates row
        echo '<div class="sfs-form-row">';
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="start_date">' . esc_html__( 'Start date', 'sfs-hr' ) . ' <span class="sfs-required">*</span></label>';
        echo '<input type="date" name="start_date" class="sfs-input" required />';
        echo '</div>';
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="end_date">' . esc_html__( 'End date', 'sfs-hr' ) . '</label>';
        echo '<input type="date" name="end_date" class="sfs-input" />';
        echo '<span class="sfs-form-hint" data-i18n-key="single_day_leave_hint">' . esc_html__( 'Leave empty for a single-day leave.', 'sfs-hr' ) . '</span>';
        echo '</div>';
        echo '</div>';

        // Reason
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="reason_note">' . esc_html__( 'Reason / note', 'sfs-hr' ) . '</label>';
        echo '<textarea name="reason" rows="3" class="sfs-textarea"></textarea>';
        echo '</div>';

        // Supporting document
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="supporting_document">' . esc_html__( 'Supporting document', 'sfs-hr' ) . '</label>';
        echo '<label class="sfs-file-upload">';
        echo '<input type="file" name="supporting_doc" accept=".pdf,image/*" onchange="this.closest(\'.sfs-file-upload\').querySelector(\'.sfs-file-upload-text\').textContent=this.files[0]?this.files[0].name:this.getAttribute(\'data-empty\')" data-empty="' . esc_attr__( 'No file selected', 'sfs-hr' ) . '" />';
        echo '<span class="sfs-file-upload-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' . esc_html__( 'Choose file', 'sfs-hr' ) . '</span>';
        echo '<span class="sfs-file-upload-text">' . esc_html__( 'No file selected', 'sfs-hr' ) . '</span>';
        echo '</label>';
        echo '<span class="sfs-form-hint" data-i18n-key="required_for_sick_leave">' . esc_html__( 'Required for Sick Leave.', 'sfs-hr' ) . '</span>';
        echo '</div>';

        // Submit
        echo '<button type="submit" class="sfs-btn sfs-btn--primary sfs-btn--full" data-i18n-key="submit_leave_request">';
        echo '<svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
        esc_html_e( 'Submit Leave Request', 'sfs-hr' );
        echo '</button>';

        echo '</div>'; // .sfs-form-fields
        echo '</form>';
        echo '</div>'; // .sfs-form-modal-body
        echo '</div>'; // .sfs-form-modal
        echo '</div>'; // .sfs-form-modal-overlay

        // JS: pre-select leave type from balance card link + Escape key close
        echo '<script>';
        echo '(function(){';
        echo 'var m=document.getElementById("sfs-leave-modal");if(!m)return;';
        echo 'document.addEventListener("keydown",function(e){if(e.key==="Escape")m.classList.remove("sfs-modal-active");});';
        echo 'var u=new URLSearchParams(window.location.search),t=u.get("leave_type"),s=document.getElementById("sfs-leave-type-select");';
        echo 'if(t&&s){s.value=t;m.classList.add("sfs-modal-active");}';
        echo '})();</script>';
    }

    /* ──────────────────────────────────────────────────────────
       Leave History (Card-based)
    ────────────────────────────────────────────────────────── */
    private function render_history( array $rows ): void {
        echo '<div class="sfs-section" style="margin-top:4px;">';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0 0 14px;" data-i18n-key="leave_history">' . esc_html__( 'Leave History', 'sfs-hr' ) . '</h3>';
        echo '</div>';

        if ( empty( $rows ) ) {
            echo '<div class="sfs-card"><div class="sfs-empty-state">';
            echo '<div class="sfs-empty-state-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" fill="none" stroke-width="1.5"/><line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="1.5"/><line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="1.5"/><line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="1.5"/></svg></div>';
            echo '<p class="sfs-empty-state-title" data-i18n-key="no_leave_requests">' . esc_html__( 'No leave requests yet', 'sfs-hr' ) . '</p>';
            echo '<p class="sfs-empty-state-text">' . esc_html__( 'Your leave requests will appear here once you submit one.', 'sfs-hr' ) . '</p>';
            echo '</div></div>';
            return;
        }

        $display = $this->prepare_display_rows( $rows );

        echo '<div class="sfs-history-list">';
        foreach ( $display as $r ) {
            echo '<details class="sfs-history-card">';
            echo '<summary>';
            echo '<div class="sfs-history-card-info">';
            echo '<span class="sfs-history-card-title">';
            if ( ! empty( $r['request_number'] ) ) {
                echo '<strong>' . esc_html( $r['request_number'] ) . '</strong> — ';
            }
            echo esc_html( $r['type_name'] ) . '</span>';
            echo '<span class="sfs-history-card-meta">' . esc_html( $r['period'] ) . ' · ' . (int) $r['days'] . ' ' . esc_html__( 'days', 'sfs-hr' ) . '</span>';
            echo '</div>';
            echo $r['status_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</summary>';

            echo '<div class="sfs-history-card-body">';
            if ( ! empty( $r['doc_html'] ) ) {
                $this->detail_row( __( 'Document', 'sfs-hr' ), $r['doc_html'], 'document', true );
            }
            $this->detail_row( __( 'Requested', 'sfs-hr' ), esc_html( $r['created_at'] ), 'requested_at' );
            if ( ! empty( $r['approver_name'] ) ) {
                $lbl = $r['raw_status'] === 'rejected' ? __( 'Rejected by', 'sfs-hr' ) : __( 'Approved by', 'sfs-hr' );
                $this->detail_row( $lbl, esc_html( $r['approver_name'] ) );
            }
            if ( $r['raw_status'] === 'rejected' && ! empty( $r['approver_note'] ) ) {
                echo '<div class="sfs-detail-row" style="color:var(--sfs-danger);"><span class="sfs-detail-label">' . esc_html__( 'Reason', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $r['approver_note'] ) . '</span></div>';
            }
            echo '</div></details>';
        }
        echo '</div>';
    }

    /* ──────────────────────────────────────────────────────────
       Helpers
    ────────────────────────────────────────────────────────── */
    private function detail_row( string $label, string $value, string $key = '', bool $allow_html = false ): void {
        $i18n = $key ? ' data-i18n-key="' . esc_attr( $key ) . '"' : '';
        echo '<div class="sfs-detail-row"><span class="sfs-detail-label"' . $i18n . '>' . esc_html( $label ) . '</span>';
        echo '<span class="sfs-detail-value">' . ( $allow_html ? $value : esc_html( $value ) ) . '</span></div>';
    }

    private function calc_days( object $row ): int {
        $days = isset( $row->days ) ? (int) $row->days : 0;
        if ( $days > 0 ) {
            return $days;
        }
        $start = $row->start_date ?? '';
        $end   = $row->end_date ?? '';
        if ( ! $start ) {
            return 1;
        }
        if ( ! $end || $end === $start ) {
            return 1;
        }
        $diff = strtotime( $end ) - strtotime( $start );
        return $diff > 0 ? (int) floor( $diff / DAY_IN_SECONDS ) + 1 : 1;
    }

    private function prepare_display_rows( array $rows ): array {
        $display = [];
        foreach ( $rows as $row ) {
            $type_name = $row->type_name ?: __( 'N/A', 'sfs-hr' );
            $start  = $row->start_date ?: '';
            $end    = $row->end_date ?: '';
            $period = ( $start && $end && $end !== $start ) ? ( $start . ' → ' . $end ) : $start;
            $days   = $this->calc_days( $row );

            $status_key = (string) $row->status;
            if ( $status_key === 'pending' ) {
                $level      = isset( $row->approval_level ) ? (int) $row->approval_level : 1;
                $status_key = ( $level <= 1 ) ? 'pending_manager' : 'pending_hr';
            }

            if ( method_exists( Leave_UI::class, 'leave_status_chip' ) ) {
                try {
                    $status_html = Leave_UI::leave_status_chip( $row );
                } catch ( \Throwable $e ) {
                    $status_html = Leave_UI::leave_status_chip( $status_key );
                }
            } else {
                $css_map = [
                    'pending_manager' => 'pending',
                    'pending_hr'      => 'pending',
                    'approved'        => 'approved',
                    'rejected'        => 'rejected',
                ];
                $badge_class = 'sfs-badge sfs-badge--' . ( $css_map[ $status_key ] ?? 'pending' );
                $status_html = '<span class="' . esc_attr( $badge_class ) . '">' . esc_html( ucfirst( str_replace( '_', ' ', $status_key ) ) ) . '</span>';
            }

            $doc_html = '';
            $doc_id   = isset( $row->doc_attachment_id ) ? (int) $row->doc_attachment_id : 0;
            if ( $doc_id > 0 && stripos( $type_name, 'sick' ) !== false ) {
                $doc_url = wp_get_attachment_url( $doc_id );
                if ( $doc_url ) {
                    $doc_html = '<a href="' . esc_url( $doc_url ) . '" target="_blank" rel="noopener noreferrer" style="color:var(--sfs-primary);">' . esc_html__( 'View', 'sfs-hr' ) . '</a>';
                }
            }

            $approver_name = '';
            $approver_note = '';
            if ( in_array( $row->status, [ 'approved', 'rejected' ], true ) && ! empty( $row->approver_id ) ) {
                $u = get_user_by( 'id', (int) $row->approver_id );
                $approver_name = $u ? $u->display_name : '';
                $approver_note = $row->approver_note ?? '';
            }

            $display[] = [
                'request_number' => $row->request_number ?? '',
                'type_name'      => $type_name,
                'period'         => $period,
                'days'           => $days,
                'status_key'     => $status_key,
                'status_html'    => $status_html,
                'created_at'     => $row->created_at ?? '',
                'doc_html'       => $doc_html,
                'approver_name'  => $approver_name,
                'approver_note'  => $approver_note,
                'raw_status'     => (string) $row->status,
            ];
        }
        return $display;
    }
}
