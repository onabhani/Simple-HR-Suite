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
            echo '<div class="sfs-alert sfs-alert--success" style="margin-bottom:20px;">' . esc_html__( 'Settings saved successfully.', 'sfs-hr' ) . '</div>';
        }

        // Header.
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="settings">' . esc_html__( 'Settings', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-section-subtitle" style="color:var(--sfs-text-muted,#6b7280);margin-top:4px;">' . esc_html__( 'Manage attendance, leave, and notification settings.', 'sfs-hr' ) . '</p>';
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
        $this->render_notification_section();

        // Save button.
        echo '<div style="margin-top:24px;text-align:right;">';
        echo '<button type="submit" class="sfs-btn sfs-btn--primary" style="padding:10px 32px;font-size:15px;">';
        echo esc_html__( 'Save Settings', 'sfs-hr' );
        echo '</button>';
        echo '</div>';

        echo '</form>';

        $this->render_scripts();
    }

    private function render_section_tabs(): void {
        echo '<div style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;">';
        $tabs = [
            'attendance'   => __( 'Attendance', 'sfs-hr' ),
            'leave'        => __( 'Leave', 'sfs-hr' ),
            'notification' => __( 'Notifications', 'sfs-hr' ),
        ];
        $first = true;
        foreach ( $tabs as $id => $label ) {
            $active = $first ? ' sfs-btn--primary' : '';
            echo '<button type="button" class="sfs-btn sfs-settings-tab-btn' . $active . '" data-settings-tab="' . esc_attr( $id ) . '" onclick="sfsShowSettingsSection(\'' . esc_js( $id ) . '\')">';
            echo esc_html( $label );
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
        echo '<label class="sfs-form-label">' . esc_html__( 'Attendance Period', 'sfs-hr' ) . '</label>';
        echo '<select name="att[period_type]" class="sfs-select">';
        echo '<option value="full_month"' . selected( $period_type, 'full_month', false ) . '>' . esc_html__( 'Full Calendar Month', 'sfs-hr' ) . '</option>';
        echo '<option value="custom"' . selected( $period_type, 'custom', false ) . '>' . esc_html__( 'Custom Start Day', 'sfs-hr' ) . '</option>';
        echo '</select>';
        echo '</div>';

        // Period start day.
        $start_day = (int) ( $att['period_start_day'] ?? 1 );
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Period Start Day', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="att[period_start_day]" class="sfs-input" value="' . esc_attr( (string) $start_day ) . '" min="1" max="28">';
        echo '<span class="sfs-form-hint">' . esc_html__( 'Day of the month the attendance period starts (1-28).', 'sfs-hr' ) . '</span>';
        echo '</div>';

        // Grace minutes.
        $grace_late = (int) ( $att['default_grace_late'] ?? 5 );
        $grace_early = (int) ( $att['default_grace_early'] ?? 5 );
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Late Grace (min)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="att[default_grace_late]" class="sfs-input" value="' . esc_attr( (string) $grace_late ) . '" min="0" max="120">';
        echo '</div>';

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Early Leave Grace (min)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="att[default_grace_early]" class="sfs-input" value="' . esc_attr( (string) $grace_early ) . '" min="0" max="120">';
        echo '</div>';

        echo '</div>';

        // Rounding rule.
        $rounding = $att['default_rounding_rule'] ?? '5';
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Rounding Rule (minutes)', 'sfs-hr' ) . '</label>';
        echo '<select name="att[default_rounding_rule]" class="sfs-select">';
        foreach ( [ '1', '5', '10', '15', '30' ] as $v ) {
            echo '<option value="' . esc_attr( $v ) . '"' . selected( $rounding, $v, false ) . '>' . esc_html( $v ) . ' ' . esc_html__( 'min', 'sfs-hr' ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        // Selfie retention.
        $retention = (int) ( $att['selfie_retention_days'] ?? 30 );
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Selfie Retention (days)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="att[selfie_retention_days]" class="sfs-input" value="' . esc_attr( (string) $retention ) . '" min="1" max="365">';
        echo '</div>';

        // OT threshold.
        $ot_threshold = (int) ( $att['monthly_ot_threshold'] ?? 2400 );
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Monthly OT Threshold (minutes)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="att[monthly_ot_threshold]" class="sfs-input" value="' . esc_attr( (string) $ot_threshold ) . '" min="0">';
        echo '<span class="sfs-form-hint">' . esc_html( sprintf( __( 'Default: 2400 min (%d hours)', 'sfs-hr' ), 40 ) ) . '</span>';
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
        echo '<div class="sfs-card" style="margin-bottom:20px;">';
        echo '<div class="sfs-card-body">';
        echo '<h3 style="font-size:16px;font-weight:700;color:var(--sfs-text);margin:0 0 16px;" data-i18n-key="leave_settings">' . esc_html__( 'Leave Settings', 'sfs-hr' ) . '</h3>';

        // Email notifications.
        $this->toggle_field( 'leave[email]', __( 'Email Notifications', 'sfs-hr' ), $email, __( 'Send email notifications for leave requests.', 'sfs-hr' ) );

        // Annual leave quotas.
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Annual Leave (< 5 yrs)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="leave[annual_lt5]" class="sfs-input" value="' . esc_attr( (string) $lt5 ) . '" min="0" max="365">';
        echo '</div>';

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Annual Leave (≥ 5 yrs)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="leave[annual_ge5]" class="sfs-input" value="' . esc_attr( (string) $ge5 ) . '" min="0" max="365">';
        echo '</div>';

        echo '</div>';

        // GM approver.
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'General Manager (Approver)', 'sfs-hr' ) . '</label>';
        $this->render_user_select( 'leave[gm_approver]', $gm_id );
        echo '</div>';

        // Finance approver.
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Finance Approver', 'sfs-hr' ) . '</label>';
        $this->render_user_select( 'leave[finance_approver]', $finance );
        echo '</div>';

        // Holiday settings.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;">' . esc_html__( 'Holiday Notifications', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'leave[holiday_notify]', __( 'Notify on Holiday Add', 'sfs-hr' ), $hol_notify, __( 'Email employees when a new holiday is added.', 'sfs-hr' ) );
        $this->toggle_field( 'leave[holiday_remind]', __( 'Holiday Reminders', 'sfs-hr' ), $hol_remind, __( 'Send reminder before upcoming holidays.', 'sfs-hr' ) );

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Reminder Days Before', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="leave[holiday_reminder_days]" class="sfs-input" value="' . esc_attr( (string) $hol_days ) . '" min="1" max="30">';
        echo '</div>';

        echo '</div></div>';
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
        $this->toggle_field( 'notif[enabled]', __( 'Notifications Enabled', 'sfs-hr' ), $ns['enabled'] ?? true );
        $this->toggle_field( 'notif[email_enabled]', __( 'Email Notifications', 'sfs-hr' ), $ns['email_enabled'] ?? true );

        // Recipients.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;">' . esc_html__( 'Recipients', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'notif[manager_notification]', __( 'Notify Manager', 'sfs-hr' ), $ns['manager_notification'] ?? true );
        $this->toggle_field( 'notif[employee_notification]', __( 'Notify Employee', 'sfs-hr' ), $ns['employee_notification'] ?? true );
        $this->toggle_field( 'notif[hr_notification]', __( 'Notify HR', 'sfs-hr' ), $ns['hr_notification'] ?? true );

        // Leave notifications.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;">' . esc_html__( 'Leave Notifications', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'notif[notify_leave_created]', __( 'Leave Created', 'sfs-hr' ), $ns['notify_leave_created'] ?? true );
        $this->toggle_field( 'notif[notify_leave_approved]', __( 'Leave Approved', 'sfs-hr' ), $ns['notify_leave_approved'] ?? true );
        $this->toggle_field( 'notif[notify_leave_rejected]', __( 'Leave Rejected', 'sfs-hr' ), $ns['notify_leave_rejected'] ?? true );
        $this->toggle_field( 'notif[notify_leave_cancelled]', __( 'Leave Cancelled', 'sfs-hr' ), $ns['notify_leave_cancelled'] ?? true );

        // Attendance notifications.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;">' . esc_html__( 'Attendance Notifications', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'notif[notify_late_arrival]', __( 'Late Arrival', 'sfs-hr' ), $ns['notify_late_arrival'] ?? true );
        $this->toggle_field( 'notif[notify_early_leave]', __( 'Early Leave', 'sfs-hr' ), $ns['notify_early_leave'] ?? true );
        $this->toggle_field( 'notif[notify_missed_punch]', __( 'Missed Punch', 'sfs-hr' ), $ns['notify_missed_punch'] ?? true );

        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label">' . esc_html__( 'Late Arrival Threshold (min)', 'sfs-hr' ) . '</label>';
        echo '<input type="number" name="notif[late_arrival_threshold]" class="sfs-input" value="' . esc_attr( (string) ( $ns['late_arrival_threshold'] ?? 15 ) ) . '" min="1" max="120">';
        echo '</div>';

        // Employee events.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;">' . esc_html__( 'Employee Events', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'notif[notify_new_employee]', __( 'New Employee', 'sfs-hr' ), $ns['notify_new_employee'] ?? true );
        $this->toggle_field( 'notif[notify_birthday]', __( 'Birthday', 'sfs-hr' ), $ns['notify_birthday'] ?? true );
        $this->toggle_field( 'notif[notify_anniversary]', __( 'Work Anniversary', 'sfs-hr' ), $ns['notify_anniversary'] ?? true );
        $this->toggle_field( 'notif[notify_contract_expiry]', __( 'Contract Expiry', 'sfs-hr' ), $ns['notify_contract_expiry'] ?? true );
        $this->toggle_field( 'notif[notify_probation_end]', __( 'Probation End', 'sfs-hr' ), $ns['notify_probation_end'] ?? true );

        // Payroll.
        echo '<h4 style="font-size:14px;font-weight:700;color:var(--sfs-text);margin:20px 0 12px;">' . esc_html__( 'Payroll Notifications', 'sfs-hr' ) . '</h4>';
        $this->toggle_field( 'notif[notify_payslip_ready]', __( 'Payslip Ready', 'sfs-hr' ), $ns['notify_payslip_ready'] ?? true );
        $this->toggle_field( 'notif[notify_payroll_processed]', __( 'Payroll Processed', 'sfs-hr' ), $ns['notify_payroll_processed'] ?? true );

        echo '</div></div>';
        echo '</div>';
    }

    /* ── Helper Renderers ──────────────────────────────────────── */

    private function toggle_field( string $name, string $label, $value, string $hint = '' ): void {
        $checked = filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 'checked' : '';
        echo '<div class="sfs-form-group" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--sfs-border);">';
        echo '<div>';
        echo '<label class="sfs-form-label" style="margin-bottom:0;">' . esc_html( $label ) . '</label>';
        if ( $hint ) {
            echo '<span class="sfs-form-hint" style="display:block;margin-top:2px;">' . esc_html( $hint ) . '</span>';
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
        $users = get_users( [
            'fields'  => [ 'ID', 'display_name' ],
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'number'  => 200,
        ] );

        echo '<select name="' . esc_attr( $name ) . '" class="sfs-select">';
        echo '<option value="0">' . esc_html__( '— None —', 'sfs-hr' ) . '</option>';
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
