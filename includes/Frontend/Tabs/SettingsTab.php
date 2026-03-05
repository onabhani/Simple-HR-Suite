<?php
/**
 * Settings Tab — Admin-only system settings in the frontend portal.
 *
 * Exposes key attendance, leave, and notification settings so admins
 * can manage them without visiting wp-admin.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Frontend\Role_Resolver;
use SFS\HR\Core\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SettingsTab implements TabInterface {

    /** @var array|null Cached user list for select dropdowns. */
    private ?array $cached_users = null;

    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() ) {
            echo '<p>' . esc_html__( 'Please log in.', 'sfs-hr' ) . '</p>';
            return;
        }

        // Admin-only tab.
        $role  = Role_Resolver::resolve( get_current_user_id() );
        $level = Role_Resolver::role_level( $role );
        if ( $level < 60 ) {
            echo '<p>' . esc_html__( 'You do not have permission to access settings.', 'sfs-hr' ) . '</p>';
            return;
        }

        // Success message.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['settings_saved'] ) && $_GET['settings_saved'] === '1' ) {
            echo '<div class="sfs-alert sfs-alert--success" style="margin-bottom:20px;"><span data-i18n-key="settings_saved">' . esc_html__( 'Settings saved successfully.', 'sfs-hr' ) . '</span></div>';
        }

        // Header.
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="settings">' . esc_html__( 'Settings', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-section-subtitle" style="color:var(--sfs-text-muted,#6b7280);margin-top:4px;" data-i18n-key="manage_settings_desc">' . esc_html__( 'Manage attendance, leave, and notification settings.', 'sfs-hr' ) . '</p>';
        echo '</div>';

        // Tab navigation for settings sections.
        $this->render_section_tabs();

        // Form wraps all settings.
        $action_url = esc_url( admin_url( 'admin-post.php' ) );
        echo '<form method="post" action="' . $action_url . '" id="sfs-settings-form">';
        wp_nonce_field( 'sfs_hr_frontend_settings', '_sfs_settings_nonce' );
        echo '<input type="hidden" name="action" value="sfs_hr_frontend_settings">';

        $this->render_attendance_section();
        $this->render_leave_section();
        $this->render_loans_section();
        $this->render_employees_section();
        $this->render_notification_section();

        // Save button.
        echo '<div style="margin-top:24px;text-align:right;">';
        echo '<button type="submit" class="sfs-btn sfs-btn--primary" style="padding:10px 32px;font-size:15px;" data-i18n-key="save_settings">';
        echo esc_html__( 'Save Settings', 'sfs-hr' );
        echo '</button>';
        echo '</div>';

        echo '</form>';

        $this->render_scripts();
    }

    private function render_section_tabs(): void {
        echo '<div style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;">';
        $tabs = [
            'attendance'   => [ __( 'Attendance', 'sfs-hr' ), 'attendance' ],
            'leave'        => [ __( 'Leave', 'sfs-hr' ), 'leave' ],
            'loans'        => [ __( 'Loans', 'sfs-hr' ), 'loans' ],
            'employees'    => [ __( 'Employees', 'sfs-hr' ), 'employees' ],
            'notification' => [ __( 'Notifications', 'sfs-hr' ), 'notifications' ],
        ];
        $first = true;
        foreach ( $tabs as $id => $info ) {
            $active = $first ? ' sfs-btn--primary' : '';
            echo '<button type="button" class="sfs-btn sfs-settings-tab-btn' . $active . '" data-settings-tab="' . esc_attr( $id ) . '" data-i18n-key="' . esc_attr( $info[1] ) . '" onclick="sfsShowSettingsSection(\'' . esc_js( $id ) . '\')">';
            echo esc_html( $info[0] );
            echo '</button>';
            $first = false;
        }
        echo '</div>';
    }

    /* ── Attendance Section ────────────────────────────────────── */

    private function render_attendance_section(): void {
        $att = get_option( 'sfs_hr_attendance_settings', [] );

        echo '<div class="sfs-settings-section" data-settings-section="attendance">';
        echo '<div class="sfs-card" style="margin-bottom:20px;">';
        echo '<div class="sfs-card-body">';
        echo '<h3 style="font-size:16px;font-weight:700;color:var(--sfs-text);margin:0 0 16px;" data-i18n-key="attendance_settings">' . esc_html__( 'Attendance Settings', 'sfs-hr' ) . '</h3>';

        // Period type.
        $period_type = $att['period_type'] ?? 'full_month';
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="attendance_period">' . esc_html__( 'Attendance Period', 'sfs-hr' ) . '</label>';
        echo '<select name="att[period_type]" class="sfs-select">';
        echo '<option value="full_month"' . selected( $period_type, 'full_month', false ) . ' data-i18n-key="full_calendar_month">' . esc_html__( 'Full Calendar Month', 'sfs-hr' ) . '</option>';
        echo '<option value="custom"' . selected( $period_type, 'custom', false ) . ' data-i18n-key="custom_start_day">' . esc_html__( 'Custom Start Day', 'sfs-hr' ) . '</option>';
        echo '</select>';
        echo '</div>';

        // Period start day.
        $start_day = (int) ( $att['period_start_day'] ?? 1 );
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="period_start_day">' . esc_html__( 'Period Start Day', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="att[period_start_day]" class="sfs-input" value="' . esc_attr( (string) $start_day ) . '" min="1" max="28">';
        echo '<span class="sfs-form-hint" data-i18n-key="period_start_day_hint">' . esc_html__( 'Day of the month the attendance period starts (1-28).', 'sfs-hr' ) . '</span>';
        echo '</div>';

        // Grace minutes.
        $grace_late = (int) ( $att['default_grace_late'] ?? 5 );
        $grace_early = (int) ( $att['default_grace_early'] ?? 5 );
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="late_grace_min">' . esc_html__( 'Late Grace (min)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="att[default_grace_late]" class="sfs-input" value="' . esc_attr( (string) $grace_late ) . '" min="0" max="120">';
        echo '</div>';

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="early_leave_grace_min">' . esc_html__( 'Early Leave Grace (min)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="att[default_grace_early]" class="sfs-input" value="' . esc_attr( (string) $grace_early ) . '" min="0" max="120">';
        echo '</div>';

        echo '</div>';

        // Rounding rule.
        $rounding = $att['default_rounding_rule'] ?? '5';
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="rounding_rule_minutes">' . esc_html__( 'Rounding Rule (minutes)', 'sfs-hr' ) . '</label>';
        echo '<select name="att[default_rounding_rule]" class="sfs-select">';
        foreach ( [ '1', '5', '10', '15', '30' ] as $v ) {
            echo '<option value="' . esc_attr( $v ) . '"' . selected( $rounding, $v, false ) . '>' . esc_html( $v ) . ' <span data-i18n-key="min">' . esc_html__( 'min', 'sfs-hr' ) . '</span></option>';
        }
        echo '</select>';
        echo '</div>';

        // Selfie retention.
        $retention = (int) ( $att['selfie_retention_days'] ?? 30 );
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="selfie_retention_days">' . esc_html__( 'Selfie Retention (days)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="att[selfie_retention_days]" class="sfs-input" value="' . esc_attr( (string) $retention ) . '" min="1" max="365">';
        echo '</div>';

        // OT threshold.
        $ot_threshold = (int) ( $att['monthly_ot_threshold'] ?? 2400 );
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="monthly_ot_threshold_min">' . esc_html__( 'Monthly OT Threshold (minutes)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="att[monthly_ot_threshold]" class="sfs-input" value="' . esc_attr( (string) $ot_threshold ) . '" min="0">';
        echo '<span class="sfs-form-hint" data-i18n-key="ot_threshold_hint">' . esc_html( sprintf( __( 'Default: 2400 min (%d hours)', 'sfs-hr' ), 40 ) ) . '</span>';
        echo '</div>';

        echo '</div></div>'; // card-body + card
        echo '</div>'; // section
    }

    /* ── Leave Section ─────────────────────────────────────────── */

    private function render_leave_section(): void {
        $email    = get_option( 'sfs_hr_leave_email', '0' );
        $lt5      = get_option( 'sfs_hr_annual_lt5', '21' );
        $ge5      = get_option( 'sfs_hr_annual_ge5', '30' );
        $gm_id    = (int) get_option( 'sfs_hr_leave_gm_approver', 0 );
        $finance  = (int) get_option( 'sfs_hr_leave_finance_approver', 0 );
        $hol_notify   = get_option( 'sfs_hr_holiday_notify_on_add', '0' );
        $hol_remind   = get_option( 'sfs_hr_holiday_reminder_enabled', '0' );
        $hol_days     = get_option( 'sfs_hr_holiday_reminder_days', '1' );

        echo '<div class="sfs-settings-section" data-settings-section="leave" style="display:none;">';

        // ── Card 1: Leave Policy ──
        echo '<div class="sfs-card" style="margin-bottom:16px;">';
        echo '<div class="sfs-card-body">';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--sfs-border,#f3f4f6);">';
        echo '<div style="width:36px;height:36px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg viewBox="0 0 24 24" width="18" height="18" stroke="#3b82f6" fill="none" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>';
        echo '<div>';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0;" data-i18n-key="leave_policy">' . esc_html__( 'Leave Policy', 'sfs-hr' ) . '</h3>';
        echo '<p style="font-size:12px;color:var(--sfs-text-muted,#6b7280);margin:2px 0 0;" data-i18n-key="leave_policy_desc">' . esc_html__( 'Configure annual leave day quotas.', 'sfs-hr' ) . '</p>';
        echo '</div></div>';

        // Annual leave quotas.
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:4px;">';
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="annual_leave_lt5">' . esc_html__( 'Annual Leave (< 5 yrs)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="leave[annual_lt5]" class="sfs-input" value="' . esc_attr( (string) $lt5 ) . '" min="0" max="365">';
        echo '<span class="sfs-form-hint" data-i18n-key="days_per_year">' . esc_html__( 'Days per year', 'sfs-hr' ) . '</span>';
        echo '</div>';
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="annual_leave_ge5">' . esc_html__( 'Annual Leave (≥ 5 yrs)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="leave[annual_ge5]" class="sfs-input" value="' . esc_attr( (string) $ge5 ) . '" min="0" max="365">';
        echo '<span class="sfs-form-hint" data-i18n-key="days_per_year">' . esc_html__( 'Days per year', 'sfs-hr' ) . '</span>';
        echo '</div>';
        echo '</div>';

        echo '</div></div>';

        // ── Card 2: Approval Workflow ──
        echo '<div class="sfs-card" style="margin-bottom:16px;">';
        echo '<div class="sfs-card-body">';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--sfs-border,#f3f4f6);">';
        echo '<div style="width:36px;height:36px;border-radius:10px;background:#ecfdf5;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg viewBox="0 0 24 24" width="18" height="18" stroke="#059669" fill="none" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>';
        echo '<div>';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0;" data-i18n-key="approval_workflow">' . esc_html__( 'Approval Workflow', 'sfs-hr' ) . '</h3>';
        echo '<p style="font-size:12px;color:var(--sfs-text-muted,#6b7280);margin:2px 0 0;" data-i18n-key="approval_workflow_desc">' . esc_html__( 'Set up who approves leave requests.', 'sfs-hr' ) . '</p>';
        echo '</div></div>';

        // Email notifications.
        $this->toggle_field( 'leave[email]', __( 'Email Notifications', 'sfs-hr' ), $email, 'email_notifications', __( 'Send email notifications for leave requests.', 'sfs-hr' ), 'leave_email_hint' );

        // GM approver.
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="general_manager_approver">' . esc_html__( 'General Manager (Approver)', 'sfs-hr' ) . '</label>';
        $this->render_user_select( 'leave[gm_approver]', $gm_id );
        echo '<span class="sfs-form-hint" data-i18n-key="gm_approver_hint">' . esc_html__( 'First level approver for leave requests.', 'sfs-hr' ) . '</span>';
        echo '</div>';

        // Finance approver.
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="finance_approver">' . esc_html__( 'Finance Approver', 'sfs-hr' ) . '</label>';
        $this->render_user_select( 'leave[finance_approver]', $finance );
        echo '<span class="sfs-form-hint" data-i18n-key="finance_approver_hint">' . esc_html__( 'Final approver for leave requests (optional).', 'sfs-hr' ) . '</span>';
        echo '</div>';

        echo '</div></div>';

        // ── Card 3: Holiday Notifications ──
        echo '<div class="sfs-card" style="margin-bottom:16px;">';
        echo '<div class="sfs-card-body">';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--sfs-border,#f3f4f6);">';
        echo '<div style="width:36px;height:36px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg viewBox="0 0 24 24" width="18" height="18" stroke="#d97706" fill="none" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></div>';
        echo '<div>';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0;" data-i18n-key="holiday_notifications">' . esc_html__( 'Holiday Notifications', 'sfs-hr' ) . '</h3>';
        echo '<p style="font-size:12px;color:var(--sfs-text-muted,#6b7280);margin:2px 0 0;" data-i18n-key="holiday_notify_desc">' . esc_html__( 'Notify employees about upcoming holidays.', 'sfs-hr' ) . '</p>';
        echo '</div></div>';

        $this->toggle_field( 'leave[holiday_notify]', __( 'Notify on Holiday Add', 'sfs-hr' ), $hol_notify, 'notify_on_holiday_add', __( 'Email employees when a new holiday is added.', 'sfs-hr' ), 'holiday_add_hint' );
        $this->toggle_field( 'leave[holiday_remind]', __( 'Holiday Reminders', 'sfs-hr' ), $hol_remind, 'holiday_reminders', __( 'Send reminder before upcoming holidays.', 'sfs-hr' ), 'holiday_reminder_hint' );

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="reminder_days_before">' . esc_html__( 'Reminder Days Before', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="leave[holiday_reminder_days]" class="sfs-input" value="' . esc_attr( (string) $hol_days ) . '" min="1" max="30">';
        echo '</div>';

        echo '</div></div>';
        echo '</div>';
    }

    /* ── Loans Section ─────────────────────────────────────────── */

    private function render_loans_section(): void {
        $settings = class_exists( '\SFS\HR\Modules\Loans\LoansModule' )
            ? \SFS\HR\Modules\Loans\LoansModule::get_settings()
            : [];

        $enabled        = $settings['enabled'] ?? false;
        $show_profile   = $settings['show_in_my_profile'] ?? false;
        $allow_requests = $settings['allow_employee_requests'] ?? false;
        $max_amount     = $settings['max_loan_amount'] ?? 0;
        $gm_ids         = $settings['gm_user_ids'] ?? [];
        $finance_id     = (int) ( $settings['finance_user_id'] ?? 0 );

        echo '<div class="sfs-settings-section" data-settings-section="loans" style="display:none;">';

        // ── Card 1: Module Settings ──
        echo '<div class="sfs-card" style="margin-bottom:16px;">';
        echo '<div class="sfs-card-body">';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--sfs-border,#f3f4f6);">';
        echo '<div style="width:36px;height:36px;border-radius:10px;background:#dbeafe;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg viewBox="0 0 24 24" width="18" height="18" stroke="#3b82f6" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>';
        echo '<div>';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0;" data-i18n-key="loan_module_settings">' . esc_html__( 'Loan Module', 'sfs-hr' ) . '</h3>';
        echo '<p style="font-size:12px;color:var(--sfs-text-muted,#6b7280);margin:2px 0 0;" data-i18n-key="loan_module_desc">' . esc_html__( 'Enable and configure employee loan features.', 'sfs-hr' ) . '</p>';
        echo '</div></div>';

        $this->toggle_field( 'loans[enabled]', __( 'Enable Loans Module', 'sfs-hr' ), $enabled, 'enable_loans', __( 'Allow the loans feature across the system.', 'sfs-hr' ), 'loans_enabled_hint' );
        $this->toggle_field( 'loans[show_in_my_profile]', __( 'Show in Employee Portal', 'sfs-hr' ), $show_profile, 'show_loan_portal', __( 'Employees can view their loans in the portal.', 'sfs-hr' ), 'loans_portal_hint' );
        $this->toggle_field( 'loans[allow_employee_requests]', __( 'Allow Self-Service Requests', 'sfs-hr' ), $allow_requests, 'allow_loan_requests', __( 'Employees can submit loan requests themselves.', 'sfs-hr' ), 'loans_selfservice_hint' );

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="max_loan_amount">' . esc_html__( 'Max Loan Amount (SAR)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="loans[max_loan_amount]" class="sfs-input" value="' . esc_attr( (string) $max_amount ) . '" min="0" step="100">';
        echo '<span class="sfs-form-hint" data-i18n-key="max_loan_hint">' . esc_html__( 'Set to 0 for no limit.', 'sfs-hr' ) . '</span>';
        echo '</div>';

        echo '</div></div>';

        // ── Card 2: Loan Approvers ──
        echo '<div class="sfs-card" style="margin-bottom:16px;">';
        echo '<div class="sfs-card-body">';
        echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--sfs-border,#f3f4f6);">';
        echo '<div style="width:36px;height:36px;border-radius:10px;background:#ecfdf5;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg viewBox="0 0 24 24" width="18" height="18" stroke="#059669" fill="none" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>';
        echo '<div>';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0;" data-i18n-key="loan_approvers">' . esc_html__( 'Loan Approvers', 'sfs-hr' ) . '</h3>';
        echo '<p style="font-size:12px;color:var(--sfs-text-muted,#6b7280);margin:2px 0 0;" data-i18n-key="loan_approvers_desc">' . esc_html__( 'Loans require GM approval then Finance approval.', 'sfs-hr' ) . '</p>';
        echo '</div></div>';

        // GM approver (first from the array).
        $gm_selected = ! empty( $gm_ids ) ? (int) $gm_ids[0] : 0;
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="loan_gm_approver">' . esc_html__( 'GM Approver', 'sfs-hr' ) . '</label>';
        $this->render_user_select( 'loans[gm_user_id]', $gm_selected );
        echo '<span class="sfs-form-hint" data-i18n-key="loan_gm_hint">' . esc_html__( 'First-level approver for loan requests.', 'sfs-hr' ) . '</span>';
        echo '</div>';

        // Finance approver.
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="loan_finance_approver">' . esc_html__( 'Finance Approver', 'sfs-hr' ) . '</label>';
        $this->render_user_select( 'loans[finance_user_id]', $finance_id );
        echo '<span class="sfs-form-hint" data-i18n-key="loan_finance_hint">' . esc_html__( 'Final approver and loan disbursement authority.', 'sfs-hr' ) . '</span>';
        echo '</div>';

        echo '</div></div>';
        echo '</div>';
    }

    /* ── Employees Section ─────────────────────────────────────── */

    private function render_employees_section(): void {
        $nationalities = get_option( 'sfs_hr_nationalities', [] );
        if ( ! is_array( $nationalities ) ) {
            $nationalities = [];
        }
        // Auto-seed from DB on first load if nothing configured yet.
        if ( empty( $nationalities ) ) {
            $nationalities = \SFS\HR\Core\Helpers::get_nationalities_for_select();
        }
        $nationalities_str = implode( "\n", $nationalities );

        $gov_settings = get_option( 'sfs_hr_gov_support_settings', [] );
        $gov_enabled  = ! empty( $gov_settings['enabled'] );
        $gov_months   = (int) ( $gov_settings['threshold_months'] ?? 3 );
        $gov_nationality = $gov_settings['nationality'] ?? 'Saudi';

        echo '<div class="sfs-settings-section" data-settings-section="employees" style="display:none;">';
        echo '<div class="sfs-card" style="margin-bottom:20px;">';
        echo '<div class="sfs-card-body">';
        echo '<h3 style="font-size:16px;font-weight:700;color:var(--sfs-text);margin:0 0 16px;" data-i18n-key="nationality_settings">' . esc_html__( 'Nationality Settings', 'sfs-hr' ) . '</h3>';

        // Allowed nationalities textarea.
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="allowed_nationalities">' . esc_html__( 'Allowed Nationalities', 'sfs-hr' ) . '</label>';
        echo '<textarea name="emp[nationalities]" class="sfs-textarea" rows="8" style="width:100%;font-family:inherit;font-size:13px;" placeholder="' . esc_attr__( 'One nationality per line', 'sfs-hr' ) . '">';
        echo esc_textarea( $nationalities_str );
        echo '</textarea>';
        echo '<p class="sfs-form-help" style="color:var(--sfs-text-muted,#6b7280);font-size:12px;margin-top:4px;" data-i18n-key="nationalities_help">' . esc_html__( 'One nationality per line. These appear in the nationality dropdown when adding/editing employees.', 'sfs-hr' ) . '</p>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        // Government support reminder settings.
        echo '<div class="sfs-card" style="margin-bottom:20px;">';
        echo '<div class="sfs-card-body">';
        echo '<h3 style="font-size:16px;font-weight:700;color:var(--sfs-text);margin:0 0 16px;" data-i18n-key="gov_support_settings">' . esc_html__( 'Government Support Reminder', 'sfs-hr' ) . '</h3>';

        // Enabled toggle.
        echo '<div class="sfs-form-group" style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">';
        echo '<label class="sfs-toggle"><input type="checkbox" name="emp[gov_enabled]" value="1"' . checked( $gov_enabled, true, false ) . ' /><span class="sfs-toggle-slider"></span></label>';
        echo '<span class="sfs-form-label" style="margin:0;" data-i18n-key="enable_gov_reminder">' . esc_html__( 'Enable government support reminder', 'sfs-hr' ) . '</span>';
        echo '</div>';

        // Nationality to check.
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="gov_nationality">' . esc_html__( 'Nationality to Monitor', 'sfs-hr' ) . '</label>';
        \SFS\HR\Core\Helpers::render_nationality_select( $gov_nationality, 'emp[gov_nationality]', '', 'sfs-input' );
        echo '<p class="sfs-form-help" style="color:var(--sfs-text-muted,#6b7280);font-size:12px;margin-top:4px;" data-i18n-key="gov_nationality_help">' . esc_html__( 'Employees with this nationality will trigger the reminder. Must match the nationality value exactly.', 'sfs-hr' ) . '</p>';
        echo '</div>';

        // Threshold months.
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="gov_threshold_months">' . esc_html__( 'Threshold (Months)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="emp[gov_months]" class="sfs-input" value="' . esc_attr( $gov_months ) . '" min="1" max="120" style="max-width:120px;" />';
        echo '<p class="sfs-form-help" style="color:var(--sfs-text-muted,#6b7280);font-size:12px;margin-top:4px;" data-i18n-key="gov_months_help">' . esc_html__( 'Alert HR when an employee of the monitored nationality has been employed for this many months (based on hire date).', 'sfs-hr' ) . '</p>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    /* ── Notification Section ──────────────────────────────────── */

    private function render_notification_section(): void {
        $ns = class_exists( Notifications::class ) ? Notifications::get_settings() : [];

        echo '<div class="sfs-settings-section" data-settings-section="notification" style="display:none;">';
        echo '<div class="sfs-card" style="margin-bottom:20px;">';
        echo '<div class="sfs-card-body">';
        echo '<h3 style="font-size:16px;font-weight:700;color:var(--sfs-text);margin:0 0 16px;" data-i18n-key="notification_settings">' . esc_html__( 'Notification Settings', 'sfs-hr' ) . '</h3>';

        // Global toggles.
        $this->toggle_field( 'notif[enabled]', __( 'Notifications Enabled', 'sfs-hr' ), $ns['enabled'] ?? true, 'notifications_enabled' );
        $this->toggle_field( 'notif[email_enabled]', __( 'Email Notifications', 'sfs-hr' ), $ns['email_enabled'] ?? true, 'email_notifications' );

        // Recipients.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;" data-i18n-key="recipients">' . esc_html__( 'Recipients', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'notif[manager_notification]', __( 'Notify Manager', 'sfs-hr' ), $ns['manager_notification'] ?? true, 'notify_manager' );
        $this->toggle_field( 'notif[employee_notification]', __( 'Notify Employee', 'sfs-hr' ), $ns['employee_notification'] ?? true, 'notify_employee' );
        $this->toggle_field( 'notif[hr_notification]', __( 'Notify HR', 'sfs-hr' ), $ns['hr_notification'] ?? true, 'notify_hr' );

        // Leave notifications.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;" data-i18n-key="leave_notifications">' . esc_html__( 'Leave Notifications', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'notif[notify_leave_created]', __( 'Leave Created', 'sfs-hr' ), $ns['notify_leave_created'] ?? true, 'leave_created' );
        $this->toggle_field( 'notif[notify_leave_approved]', __( 'Leave Approved', 'sfs-hr' ), $ns['notify_leave_approved'] ?? true, 'leave_approved' );
        $this->toggle_field( 'notif[notify_leave_rejected]', __( 'Leave Rejected', 'sfs-hr' ), $ns['notify_leave_rejected'] ?? true, 'leave_rejected' );
        $this->toggle_field( 'notif[notify_leave_cancelled]', __( 'Leave Cancelled', 'sfs-hr' ), $ns['notify_leave_cancelled'] ?? true, 'leave_cancelled' );

        // Attendance notifications.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;" data-i18n-key="attendance_notifications">' . esc_html__( 'Attendance Notifications', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'notif[notify_late_arrival]', __( 'Late Arrival', 'sfs-hr' ), $ns['notify_late_arrival'] ?? true, 'late_arrival' );
        $this->toggle_field( 'notif[notify_early_leave]', __( 'Early Leave', 'sfs-hr' ), $ns['notify_early_leave'] ?? true, 'early_leave' );
        $this->toggle_field( 'notif[notify_missed_punch]', __( 'Missed Punch', 'sfs-hr' ), $ns['notify_missed_punch'] ?? true, 'missed_punch' );

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label" data-i18n-key="late_arrival_threshold_min">' . esc_html__( 'Late Arrival Threshold (min)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="notif[late_arrival_threshold]" class="sfs-input" value="' . esc_attr( (string) ( $ns['late_arrival_threshold'] ?? 15 ) ) . '" min="1" max="120">';
        echo '</div>';

        // Employee events.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;" data-i18n-key="employee_events">' . esc_html__( 'Employee Events', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'notif[notify_new_employee]', __( 'New Employee', 'sfs-hr' ), $ns['notify_new_employee'] ?? true, 'new_employee' );
        $this->toggle_field( 'notif[notify_birthday]', __( 'Birthday', 'sfs-hr' ), $ns['notify_birthday'] ?? true, 'birthday' );
        $this->toggle_field( 'notif[notify_anniversary]', __( 'Work Anniversary', 'sfs-hr' ), $ns['notify_anniversary'] ?? true, 'work_anniversary' );
        $this->toggle_field( 'notif[notify_contract_expiry]', __( 'Contract Expiry', 'sfs-hr' ), $ns['notify_contract_expiry'] ?? true, 'contract_expiry' );
        $this->toggle_field( 'notif[notify_probation_end]', __( 'Probation End', 'sfs-hr' ), $ns['notify_probation_end'] ?? true, 'probation_end' );

        // Payroll.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;" data-i18n-key="payroll_notifications">' . esc_html__( 'Payroll Notifications', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'notif[notify_payslip_ready]', __( 'Payslip Ready', 'sfs-hr' ), $ns['notify_payslip_ready'] ?? true, 'payslip_ready' );
        $this->toggle_field( 'notif[notify_payroll_processed]', __( 'Payroll Processed', 'sfs-hr' ), $ns['notify_payroll_processed'] ?? true, 'payroll_processed' );

        echo '</div></div>';
        echo '</div>';
    }

    /* ── Helper Renderers ──────────────────────────────────────── */

    private function toggle_field( string $name, string $label, $value, string $label_key = '', string $hint = '', string $hint_key = '' ): void {
        $checked = filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 'checked' : '';
        $label_attr = $label_key ? ' data-i18n-key="' . esc_attr( $label_key ) . '"' : '';
        $hint_attr  = $hint_key ? ' data-i18n-key="' . esc_attr( $hint_key ) . '"' : '';
        echo '<div class="sfs-form-group" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--sfs-border);">';
        echo '<div>';
        echo '<label class="sfs-form-label" style="margin-bottom:0;"' . $label_attr . '>' . esc_html( $label ) . '</label>';
        if ( $hint ) {
            echo '<span class="sfs-form-hint" style="display:block;margin-top:2px;"' . $hint_attr . '>' . esc_html( $hint ) . '</span>';
        }
        echo '</div>';
        echo '<label style="position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0;">';
        echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
        echo '<input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . $checked . ' style="opacity:0;width:0;height:0;position:absolute;">';
        echo '<span class="sfs-toggle-slider"></span>';
        echo '</label>';
        echo '</div>';
    }

    private function render_user_select( string $name, int $selected_id ): void {
        if ( $this->cached_users === null ) {
            $this->cached_users = get_users( [
                'fields'  => [ 'ID', 'display_name' ],
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'number'  => 200,
            ] );
        }
        $users = $this->cached_users;

        echo '<select name="' . esc_attr( $name ) . '" class="sfs-select">';
        echo '<option value="0" data-i18n-key="none_option">' . esc_html__( '— None —', 'sfs-hr' ) . '</option>';
        foreach ( $users as $u ) {
            echo '<option value="' . esc_attr( (string) $u->ID ) . '"' . selected( (int) $u->ID, $selected_id, false ) . '>';
            echo esc_html( $u->display_name );
            echo '</option>';
        }
        echo '</select>';
    }

    private function render_scripts(): void {
        ?>
        <style>
        .sfs-toggle-slider {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #d1d5db;
            border-radius: 24px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .sfs-toggle-slider::before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform 0.2s;
        }
        input:checked + .sfs-toggle-slider {
            background: var(--sfs-primary, #0284c7);
        }
        input:checked + .sfs-toggle-slider::before {
            transform: translateX(20px);
        }
        .sfs-settings-tab-btn {
            min-width: 100px;
        }
        .sfs-settings-tab-btn:not(.sfs-btn--primary) {
            background: var(--sfs-surface, #f9fafb);
            color: var(--sfs-text, #374151);
            border: 1px solid var(--sfs-border, #e5e7eb);
        }
        </style>
        <script>
        function sfsShowSettingsSection(section) {
            var sections = document.querySelectorAll('.sfs-settings-section');
            for (var i = 0; i < sections.length; i++) {
                sections[i].style.display = sections[i].getAttribute('data-settings-section') === section ? 'block' : 'none';
            }
            var btns = document.querySelectorAll('.sfs-settings-tab-btn');
            for (var j = 0; j < btns.length; j++) {
                if (btns[j].getAttribute('data-settings-tab') === section) {
                    btns[j].classList.add('sfs-btn--primary');
                } else {
                    btns[j].classList.remove('sfs-btn--primary');
                }
            }
        }
        </script>
        <?php
    }
}
