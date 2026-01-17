<?php
/**
 * Leave Tab - Frontend leave dashboard and request form
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Modules\Leave\Leave_UI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LeaveTab
 *
 * Handles the Leave tab rendering in the employee profile.
 * - Self-service leave request form
 * - Leave history display
 * - Leave balance KPIs
 */
class LeaveTab implements TabInterface {

    /**
     * Render the leave tab content
     *
     * @param array $emp Employee data array
     * @param int   $emp_id Employee ID
     * @return void
     */
    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own leave information.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;

        $employee_id = $emp_id;
        if ( $employee_id <= 0 ) {
            echo '<p>' . esc_html__( 'Employee record not found.', 'sfs-hr' ) . '</p>';
            return;
        }

        $req_table   = $wpdb->prefix . 'sfs_hr_leave_requests';
        $type_table  = $wpdb->prefix . 'sfs_hr_leave_types';
        $bal_table   = $wpdb->prefix . 'sfs_hr_leave_balances';

        $year  = (int) current_time( 'Y' );
        $today = current_time( 'Y-m-d' );

        // ===== Leave types for the form =====
        $types = $wpdb->get_results(
            "SELECT id, name
             FROM {$type_table}
             WHERE active = 1
             ORDER BY name ASC"
        );

        // ===== Recent leave history =====
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, t.name AS type_name
                 FROM {$req_table} r
                 LEFT JOIN {$type_table} t ON t.id = r.type_id
                 WHERE r.employee_id = %d
                 ORDER BY r.created_at DESC, r.id DESC
                 LIMIT 100",
                $employee_id
            )
        );

        // ===================== Dashboard KPIs =====================

        // Requests (this year)
        $requests_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$req_table}
                 WHERE employee_id = %d
                   AND YEAR(start_date) = %d",
                $employee_id,
                $year
            )
        );

        // Balances for current year
        $balances = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.*, t.name, t.is_annual
                 FROM {$bal_table} b
                 JOIN {$type_table} t ON t.id = b.type_id
                 WHERE b.employee_id = %d
                   AND b.year = %d
                 ORDER BY t.is_annual DESC, t.name ASC",
                $employee_id,
                $year
            ),
            ARRAY_A
        );

        $total_used       = 0;
        $annual_available = null;

        if ( ! empty( $balances ) ) {
            foreach ( $balances as $b ) {
                $closing = (int) ( $b['closing'] ?? 0 );
                $used    = (int) ( $b['used'] ?? 0 );
                $total_used += $used;

                if ( $annual_available === null && ! empty( $b['is_annual'] ) ) {
                    $annual_available = $closing;
                }
            }
        }

        if ( $annual_available === null ) {
            $annual_available = 0;
        }

        // Pending requests count (this year)
        $pending_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$req_table}
                 WHERE employee_id = %d
                   AND status = 'pending'
                   AND YEAR(start_date) = %d",
                $employee_id,
                $year
            )
        );

        // Next approved leave (nearest future approved)
        $next_leave_text = esc_html__( 'No upcoming leave.', 'sfs-hr' );

        $next_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*
                 FROM {$req_table} r
                 WHERE r.employee_id = %d
                   AND r.status = 'approved'
                   AND r.start_date >= %s
                 ORDER BY r.start_date ASC, r.id ASC
                 LIMIT 1",
                $employee_id,
                $today
            )
        );

        if ( $next_row ) {
            $start  = $next_row->start_date ?: '';
            $end    = $next_row->end_date   ?: '';
            $period = $start;

            if ( $start && $end && $end !== $start ) {
                $period = $start . ' → ' . $end;
            }

            $days = isset( $next_row->days ) ? (int) $next_row->days : 0;
            if ( $days <= 0 && $start !== '' ) {
                if ( $end === '' || $end === $start ) {
                    $days = 1;
                } else {
                    $start_ts = strtotime( $start );
                    $end_ts   = strtotime( $end );
                    if ( $start_ts && $end_ts && $end_ts >= $start_ts ) {
                        $days = (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;
                    }
                }
            }
            if ( $days < 1 ) {
                $days = 1;
            }

            $next_leave_text = sprintf(
                '%1$s · %2$d %3$s',
                esc_html( $period ),
                (int) $days,
                ( $days === 1 )
                    ? esc_html__( 'day', 'sfs-hr' )
                    : esc_html__( 'days', 'sfs-hr' )
            );
        }

        // ===================== Output =====================
        $this->render_styles();
        $this->render_dashboard( $emp, $year, $requests_count, $annual_available, $total_used, $pending_count, $next_leave_text );
        $this->render_request_form( $employee_id, $types );
        $this->render_history( $rows );

        echo '</div>'; // .sfs-hr-my-profile-leave wrapper
    }

    /**
     * Render tab-specific CSS styles
     */
    private function render_styles(): void {
        ?>
        <style>
        .sfs-hr-leave-self-form-wrap {
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            padding: 14px 16px;
            margin-bottom: 14px;
        }
        .sfs-hr-leave-form-fields {
            max-width: 520px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .sfs-hr-lf-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .sfs-hr-lf-label {
            font-size: 12px;
            font-weight: 500;
            color: #374151;
        }
        .sfs-hr-lf-hint {
            font-size: 11px;
            color: #6b7280;
        }
        .sfs-hr-lf-row {
            display: flex;
            gap: 12px;
        }
        .sfs-hr-lf-row .sfs-hr-lf-group {
            flex: 1;
        }

        .sfs-hr-leave-self-form select,
        .sfs-hr-leave-self-form input[type="date"],
        .sfs-hr-leave-self-form input[type="file"],
        .sfs-hr-leave-self-form textarea {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            padding: 10px 12px;
        }

        .sfs-hr-pwa-app input[type="file"] {
            border-radius: 6px !important;
            padding: 8px !important;
        }
        .sfs-hr-pwa-app input[type="file"]::file-selector-button {
            border: none !important;
            border-radius: 4px !important;
            padding: 6px 12px !important;
            margin-right: 10px !important;
            cursor: pointer !important;
            font-weight: 500 !important;
        }

        .sfs-hr-leave-self-form input[type="date"] {
            padding: 10px 12px;
            min-height: 44px;
        }

        @media (max-width: 768px) {
            .sfs-hr-leave-self-form-wrap {
                margin-left: 0;
                margin-right: 0;
            }
            .sfs-hr-leave-form-fields {
                max-width: 100%;
                width: 100%;
                box-sizing: border-box;
            }
            .sfs-hr-lf-row {
                gap: 8px;
            }
        }

        .sfs-hr-lf-actions {
            margin-top: 8px;
        }
        .sfs-hr-lf-submit {
            min-width: 180px;
        }

        .sfs-hr-pwa-app .sfs-hr-lf-submit,
        .sfs-hr-pwa-app button[type="submit"].sfs-hr-lf-submit {
            width: 100%;
            padding: 12px 16px !important;
            border-radius: 8px !important;
            border: none !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            min-height: 44px !important;
        }

        @media (max-width: 600px) {
            .sfs-hr-lf-row {
                flex-direction: column;
            }
            .sfs-hr-lf-submit {
                width: 100%;
                text-align: center;
            }
            .sfs-hr-leave-self-form textarea {
                min-height: 80px !important;
                height: auto !important;
            }
        }

        .sfs-hr-my-profile-leave {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px 18px 18px;
            margin-top: 24px;
            margin-bottom: 24px;
            background: #ffffff;
        }
        .sfs-hr-my-profile-leave h4 {
            margin: 0 0 8px;
        }
        .sfs-hr-lw-sub {
            margin: 0 0 10px;
            font-size: 12px;
            color: #6b7280;
        }
        .sfs-hr-lw-kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .sfs-hr-lw-kpi-card {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        .sfs-hr-lw-kpi-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .sfs-hr-lw-kpi-value {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }
        .sfs-hr-lw-kpi-sub {
            font-size: 12px;
            font-weight: 400;
            color: #4b5563;
        }
        .sfs-hr-lw-kpi-next {
            font-size: 13px;
            font-weight: 500;
        }

        .sfs-hr-leaves-desktop { display:block; }
        .sfs-hr-leaves-mobile  { display:none; }

        .sfs-hr-leave-card {
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            padding: 8px 10px;
            margin-bottom: 8px;
            background: #f9fafb;
        }
        .sfs-hr-leave-summary {
            display:flex;
            align-items:center;
            justify-content:space-between;
            cursor:pointer;
        }
        .sfs-hr-leave-summary-title {
            font-weight:500;
            font-size:13px;
        }
        .sfs-hr-leave-summary-status {
            font-size:12px;
        }
        .sfs-hr-leave-body {
            margin-top:6px;
            font-size:12px;
        }
        .sfs-hr-leave-field-row {
            display:flex;
            justify-content:space-between;
            margin-bottom:3px;
        }
        .sfs-hr-leave-field-label {
            color:#6b7280;
            margin-right:8px;
        }
        .sfs-hr-leave-field-value {
            font-weight:500;
            text-align:right;
        }

        @media (max-width: 768px) {
            .sfs-hr-leaves-desktop { display:none; }
            .sfs-hr-leaves-mobile  { display:block; }
        }

        /* Dark mode overrides */
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-my-profile-leave,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-my-profile-leave {
            background: var(--sfs-surface) !important;
            border-color: var(--sfs-border) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-my-profile-leave h4,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-my-profile-leave h4 {
            color: var(--sfs-text) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-lw-sub,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-lw-sub {
            color: var(--sfs-text-muted) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-lw-kpi-card,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-lw-kpi-card {
            background: var(--sfs-background) !important;
            border-color: var(--sfs-border) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-lw-kpi-label,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-lw-kpi-label {
            color: var(--sfs-text-muted) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-lw-kpi-value,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-lw-kpi-value {
            color: var(--sfs-text) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-lw-kpi-sub,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-lw-kpi-sub {
            color: var(--sfs-text-muted) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-self-form-wrap,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-self-form-wrap {
            background: var(--sfs-background) !important;
            border-color: var(--sfs-border) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-self-form-wrap h5,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-self-form-wrap h5 {
            color: var(--sfs-text) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-lf-label,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-lf-label {
            color: var(--sfs-text) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-lf-hint,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-lf-hint {
            color: var(--sfs-text-muted) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-card,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-card {
            background: var(--sfs-background) !important;
            border-color: var(--sfs-border) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-summary-title,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-summary-title {
            color: var(--sfs-text) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-field-label,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-field-label {
            color: var(--sfs-text-muted) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-field-value,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-field-value {
            color: var(--sfs-text) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-body,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-body {
            border-top-color: var(--sfs-border) !important;
            color: var(--sfs-text) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-my-profile-leave h5,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-my-profile-leave h5,
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-self-form-wrap h5,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-self-form-wrap h5 {
            color: var(--sfs-text) !important;
        }
        .sfs-hr-pwa-app .sfs-hr-leave-self-form select,
        .sfs-hr-pwa-app .sfs-hr-leave-self-form input,
        .sfs-hr-pwa-app .sfs-hr-leave-self-form textarea {
            border-radius: 8px !important;
            padding: 10px 12px !important;
            font-size: 14px !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-self-form select,
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-self-form input,
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-leave-self-form textarea,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-self-form select,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-self-form input,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-leave-self-form textarea {
            background: var(--sfs-surface) !important;
            border-color: var(--sfs-border) !important;
            color: var(--sfs-text) !important;
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-lf-submit,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-lf-submit {
            background: #6b7280 !important;
            color: #fff !important;
        }
        .sfs-hr-pwa-app .sfs-hr-leave-summary-status .sfs-hr-status-chip {
            font-size: 11px !important;
            padding: 2px 8px !important;
        }
        .sfs-hr-pwa-app .sfs-hr-leave-card {
            padding: 10px 12px !important;
            border-radius: 10px !important;
        }
        </style>
        <?php
    }

    /**
     * Render the dashboard header and KPI cards
     */
    private function render_dashboard( array $emp, int $year, int $requests_count, int $annual_available, int $total_used, int $pending_count, string $next_leave_text ): void {
        echo '<div class="sfs-hr-my-profile-leave">';

        echo '<h4 data-i18n-key="my_leave_dashboard">' . esc_html__( 'My Leave Dashboard', 'sfs-hr' ) . '</h4>';
        echo '<p class="sfs-hr-lw-sub">';
        echo '<span data-i18n-key="employee">' . esc_html__( 'Employee', 'sfs-hr' ) . '</span>: ';
        echo esc_html( (string) ( $emp['first_name'] ?? '' ) ) . ' ';
        echo esc_html( (string) ( $emp['last_name'] ?? '' ) ) . ' · ';
        echo '<span data-i18n-key="year">' . esc_html__( 'Year', 'sfs-hr' ) . '</span>: ' . $year;
        echo '</p>';

        echo '<div class="sfs-hr-lw-kpis">';

        // Card 1: Requests
        echo '<div class="sfs-hr-lw-kpi-card">';
        echo '  <div class="sfs-hr-lw-kpi-label" data-i18n-key="requests_this_year">' . esc_html__( 'Requests (this year)', 'sfs-hr' ) . '</div>';
        echo '  <div class="sfs-hr-lw-kpi-value">' . (int) $requests_count . '</div>';
        echo '</div>';

        // Card 2: Annual leave available
        echo '<div class="sfs-hr-lw-kpi-card">';
        echo '  <div class="sfs-hr-lw-kpi-label" data-i18n-key="annual_leave_available">' . esc_html__( 'Annual leave available', 'sfs-hr' ) . '</div>';
        echo '  <div class="sfs-hr-lw-kpi-value">' . (int) $annual_available . '</div>';
        echo '</div>';

        // Card 3: Total used + pending
        echo '<div class="sfs-hr-lw-kpi-card">';
        echo '  <div class="sfs-hr-lw-kpi-label" data-i18n-key="total_used_this_year">' . esc_html__( 'Total used (this year)', 'sfs-hr' ) . '</div>';
        echo '  <div class="sfs-hr-lw-kpi-value">';
        echo        (int) $total_used;
        if ( $pending_count > 0 ) {
            echo ' <span class="sfs-hr-lw-kpi-sub">· <span data-i18n-key="pending">' . esc_html__( 'pending', 'sfs-hr' ) . '</span> ' . (int) $pending_count . '</span>';
        }
        echo '  </div>';
        echo '</div>';

        // Card 4: Next approved leave
        echo '<div class="sfs-hr-lw-kpi-card">';
        echo '  <div class="sfs-hr-lw-kpi-label" data-i18n-key="next_approved_leave">' . esc_html__( 'Next approved leave', 'sfs-hr' ) . '</div>';
        echo '  <div class="sfs-hr-lw-kpi-value sfs-hr-lw-kpi-next">' . $next_leave_text . '</div>';
        echo '</div>';

        echo '</div>'; // .sfs-hr-lw-kpis
    }

    /**
     * Render the leave request form
     */
    private function render_request_form( int $employee_id, array $types ): void {
        echo '<div class="sfs-hr-leave-self-form-wrap" style="margin-top:16px;">';
        echo '<h5 style="margin:0 0 10px 0;" data-i18n-key="request_new_leave">' . esc_html__( 'Request new leave', 'sfs-hr' ) . '</h5>';

        if ( empty( $types ) ) {
            echo '<p class="description" data-i18n-key="leave_types_not_configured">' . esc_html__( 'Leave types are not configured yet. Please contact HR.', 'sfs-hr' ) . '</p>';
            echo '</div>';
            return;
        }

        $action_url = admin_url( 'admin-post.php' );

        echo '<form method="post" action="' . esc_url( $action_url ) . '" class="sfs-hr-leave-self-form" enctype="multipart/form-data">';
        wp_nonce_field( 'sfs_hr_leave_request_self' );
        echo '<input type="hidden" name="action" value="sfs_hr_leave_request_self" />';
        echo '<input type="hidden" name="employee_id" value="' . (int) $employee_id . '" />';

        echo '<div class="sfs-hr-leave-form-fields">';

        // Leave type
        echo '<div class="sfs-hr-lf-group">';
        echo '  <div class="sfs-hr-lf-label" data-i18n-key="leave_type">' . esc_html__( 'Leave type', 'sfs-hr' ) . '</div>';
        echo '  <select name="type_id" required>';
        echo '      <option value="" data-i18n-key="select_type">' . esc_html__( 'Select type', 'sfs-hr' ) . '</option>';
        foreach ( $types as $type ) {
            echo '      <option value="' . (int) $type->id . '">' . esc_html( $type->name ) . '</option>';
        }
        echo '  </select>';
        echo '</div>';

        // Dates row
        echo '<div class="sfs-hr-lf-row">';
        echo '  <div class="sfs-hr-lf-group">';
        echo '      <div class="sfs-hr-lf-label" data-i18n-key="start_date">' . esc_html__( 'Start date', 'sfs-hr' ) . '</div>';
        echo '      <input type="date" name="start_date" required />';
        echo '  </div>';
        echo '  <div class="sfs-hr-lf-group">';
        echo '      <div class="sfs-hr-lf-label" data-i18n-key="end_date">' . esc_html__( 'End date', 'sfs-hr' ) . '</div>';
        echo '      <input type="date" name="end_date" />';
        echo '      <div class="sfs-hr-lf-hint" data-i18n-key="single_day_leave_hint">' . esc_html__( 'If empty, it will be treated as a single-day leave.', 'sfs-hr' ) . '</div>';
        echo '  </div>';
        echo '</div>';

        // Reason
        echo '<div class="sfs-hr-lf-group">';
        echo '  <div class="sfs-hr-lf-label" data-i18n-key="reason_note">' . esc_html__( 'Reason / note', 'sfs-hr' ) . '</div>';
        echo '  <textarea name="reason" rows="3"></textarea>';
        echo '</div>';

        // Supporting document
        echo '<div class="sfs-hr-lf-group">';
        echo '  <div class="sfs-hr-lf-label" data-i18n-key="supporting_document">' . esc_html__( 'Supporting document', 'sfs-hr' ) . '</div>';
        echo '  <input type="file" name="supporting_doc" accept=".pdf,image/*" />';
        echo '  <div class="sfs-hr-lf-hint" data-i18n-key="required_for_sick_leave">' . esc_html__( 'Required for Sick Leave.', 'sfs-hr' ) . '</div>';
        echo '</div>';

        // Submit
        echo '<div class="sfs-hr-lf-actions">';
        echo '  <button type="submit" class="button button-primary sfs-hr-lf-submit" data-i18n-key="submit_leave_request">';
        esc_html_e( 'Submit leave request', 'sfs-hr' );
        echo '  </button>';
        echo '</div>';

        echo '</div>'; // .sfs-hr-leave-form-fields
        echo '</form>';
        echo '</div>'; // form wrap
    }

    /**
     * Render leave history (desktop table + mobile cards)
     */
    private function render_history( array $rows ): void {
        echo '<h5 style="margin-top:18px;" data-i18n-key="leave_history">' . esc_html__( 'Leave history', 'sfs-hr' ) . '</h5>';

        if ( empty( $rows ) ) {
            echo '<p data-i18n-key="no_leave_requests">' . esc_html__( 'No leave requests found.', 'sfs-hr' ) . '</p>';
            return;
        }

        $display_rows = $this->prepare_display_rows( $rows );

        // Desktop table
        $this->render_desktop_table( $display_rows );

        // Mobile cards
        $this->render_mobile_cards( $display_rows );
    }

    /**
     * Prepare rows for display
     */
    private function prepare_display_rows( array $rows ): array {
        $display_rows = [];

        foreach ( $rows as $row ) {
            $type_name = $row->type_name ?: __( 'N/A', 'sfs-hr' );

            $start  = $row->start_date ?: '';
            $end    = $row->end_date   ?: '';
            $period = $start;
            if ( $start && $end && $end !== $start ) {
                $period = $start . ' → ' . $end;
            }

            $days = isset( $row->days ) ? (int) $row->days : 0;
            if ( $days <= 0 && $start !== '' ) {
                if ( $end === '' || $end === $start ) {
                    $days = 1;
                } else {
                    $start_ts = strtotime( $start );
                    $end_ts   = strtotime( $end );
                    if ( $start_ts && $end_ts && $end_ts >= $start_ts ) {
                        $days = (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;
                    }
                }
            }

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
                $status_label = ucfirst( str_replace( '_', ' ', $status_key ) );
                $status_html  = '<span class="sfs-hr-badge sfs-hr-leave-status sfs-hr-leave-status-'
                              . esc_attr( $status_key ) . '">'
                              . esc_html( $status_label )
                              . '</span>';
            }

            $doc_html = '';
            $doc_id   = isset( $row->doc_attachment_id ) ? (int) $row->doc_attachment_id : 0;
            if ( $doc_id > 0 && stripos( $type_name, 'sick' ) !== false ) {
                $doc_url = wp_get_attachment_url( $doc_id );
                if ( $doc_url ) {
                    $doc_html = sprintf(
                        '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                        esc_url( $doc_url ),
                        esc_html__( 'View document', 'sfs-hr' )
                    );
                }
            }

            $approver_name = '';
            $approver_note = '';
            if ( in_array( $row->status, [ 'approved', 'rejected' ], true ) ) {
                if ( ! empty( $row->approver_id ) ) {
                    $approver_user = get_user_by( 'id', (int) $row->approver_id );
                    $approver_name = $approver_user ? $approver_user->display_name : '';
                }
                $approver_note = $row->approver_note ?? '';
            }

            $display_rows[] = [
                'type_name'     => $type_name,
                'period'        => $period,
                'days'          => $days,
                'status_key'    => $status_key,
                'status_html'   => $status_html,
                'created_at'    => $row->created_at ?? '',
                'doc_html'      => $doc_html,
                'approver_name' => $approver_name,
                'approver_note' => $approver_note,
                'raw_status'    => (string) $row->status,
            ];
        }

        return $display_rows;
    }

    /**
     * Render desktop history table
     */
    private function render_desktop_table( array $display_rows ): void {
        echo '<div class="sfs-hr-leaves-desktop">';
        echo '<table class="sfs-hr-table sfs-hr-leave-table" style="margin-top:8px;">';
        echo '<thead><tr>';
        echo '<th data-i18n-key="type">' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="period">' . esc_html__( 'Period', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="days">' . esc_html__( 'Days', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="document">' . esc_html__( 'Document', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="requested_at">' . esc_html__( 'Requested at', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $display_rows as $r ) {
            echo '<tr>';
            echo '<td>' . esc_html( $r['type_name'] ) . '</td>';
            echo '<td>' . esc_html( $r['period'] ) . '</td>';
            echo '<td>' . esc_html( (string) $r['days'] ) . '</td>';
            echo '<td>';
            echo $r['status_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if ( ! empty( $r['approver_name'] ) ) {
                $action_label = $r['raw_status'] === 'rejected' ? __( 'Rejected by', 'sfs-hr' ) : __( 'Approved by', 'sfs-hr' );
                echo '<br><small style="color:#666;">' . esc_html( $action_label ) . ': ' . esc_html( $r['approver_name'] ) . '</small>';
            }
            if ( $r['raw_status'] === 'rejected' && ! empty( $r['approver_note'] ) ) {
                echo '<br><small style="color:#b32d2e;"><strong>' . esc_html__( 'Reason:', 'sfs-hr' ) . '</strong> ' . esc_html( $r['approver_note'] ) . '</small>';
            }
            echo '</td>';
            echo '<td>';
            if ( ! empty( $r['doc_html'] ) ) {
                echo $r['doc_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                echo '&mdash;';
            }
            echo '</td>';
            echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /**
     * Render mobile history cards
     */
    private function render_mobile_cards( array $display_rows ): void {
        echo '<div class="sfs-hr-leaves-mobile">';
        foreach ( $display_rows as $r ) {
            echo '<details class="sfs-hr-leave-card">';
            echo '  <summary class="sfs-hr-leave-summary">';
            echo '      <span class="sfs-hr-leave-summary-title">' . esc_html( $r['type_name'] ) . '</span>';
            echo '      <span class="sfs-hr-leave-summary-status">';
            echo            $r['status_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '      </span>';
            echo '  </summary>';

            echo '  <div class="sfs-hr-leave-body">';
            echo '      <div class="sfs-hr-leave-field-row">';
            echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Period', 'sfs-hr' ) . '</div>';
            echo '          <div class="sfs-hr-leave-field-value">' . esc_html( $r['period'] ) . '</div>';
            echo '      </div>';

            echo '      <div class="sfs-hr-leave-field-row">';
            echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Days', 'sfs-hr' ) . '</div>';
            echo '          <div class="sfs-hr-leave-field-value">' . esc_html( (string) $r['days'] ) . '</div>';
            echo '      </div>';

            if ( ! empty( $r['doc_html'] ) ) {
                echo '      <div class="sfs-hr-leave-field-row">';
                echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Document', 'sfs-hr' ) . '</div>';
                echo '          <div class="sfs-hr-leave-field-value">';
                echo                $r['doc_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '          </div>';
                echo '      </div>';
            }

            echo '      <div class="sfs-hr-leave-field-row">';
            echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Requested at', 'sfs-hr' ) . '</div>';
            echo '          <div class="sfs-hr-leave-field-value">' . esc_html( $r['created_at'] ) . '</div>';
            echo '      </div>';

            if ( ! empty( $r['approver_name'] ) ) {
                $action_label = $r['raw_status'] === 'rejected' ? __( 'Rejected by', 'sfs-hr' ) : __( 'Approved by', 'sfs-hr' );
                echo '      <div class="sfs-hr-leave-field-row">';
                echo '          <div class="sfs-hr-leave-field-label">' . esc_html( $action_label ) . '</div>';
                echo '          <div class="sfs-hr-leave-field-value">' . esc_html( $r['approver_name'] ) . '</div>';
                echo '      </div>';
            }
            if ( $r['raw_status'] === 'rejected' && ! empty( $r['approver_note'] ) ) {
                echo '      <div class="sfs-hr-leave-field-row">';
                echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Reason', 'sfs-hr' ) . '</div>';
                echo '          <div class="sfs-hr-leave-field-value" style="color:#b32d2e;">' . esc_html( $r['approver_note'] ) . '</div>';
                echo '      </div>';
            }

            echo '  </div>';
            echo '</details>';
        }
        echo '</div>';
    }
}
