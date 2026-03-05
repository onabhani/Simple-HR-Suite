<?php
/**
 * Government Support Reminder — shows a persistent notice on Overview tab
 * when a Saudi (or configured nationality) employee has been employed for
 * longer than a configurable threshold (months since hired_at).
 *
 * The notice persists until HR explicitly dismisses it.  An email notification
 * is also sent to HR on first detection.
 *
 * @package SFS\HR\Frontend
 */

namespace SFS\HR\Frontend;

use SFS\HR\Core\Helpers;
use SFS\HR\Core\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GovSupportReminder {

    /** Option key: array of employee IDs dismissed by HR. */
    private const OPT_DISMISSED = 'sfs_hr_gov_support_dismissed';

    /** User meta key: tracks employees for whom the email was already sent. */
    private const META_EMAILED = 'sfs_hr_gov_support_emailed';

    /**
     * Bootstrap hooks.
     */
    public static function init(): void {
        add_action( 'wp_ajax_sfs_hr_dismiss_gov_support', [ __CLASS__, 'ajax_dismiss' ] );
        add_action( 'sfs_hr_queue_gov_support_emails', [ __CLASS__, 'process_queued_emails' ], 10, 2 );
    }

    /**
     * Background handler: send government support reminder emails for queued employees.
     *
     * @param int[] $emp_ids  Employee IDs to process.
     * @param array $emp_data Associative array of employee ID => row data.
     */
    public static function process_queued_emails( array $emp_ids, array $emp_data ): void {
        foreach ( $emp_ids as $emp_id ) {
            if ( isset( $emp_data[ $emp_id ] ) ) {
                self::maybe_send_email( (int) $emp_id, $emp_data[ $emp_id ] );
            }
        }
    }

    /**
     * Check whether an employee qualifies for the government support reminder.
     *
     * An employee qualifies when:
     *  1. The feature is enabled in settings.
     *  2. The employee's nationality matches the configured nationality.
     *  3. The employee has been employed for >= threshold months (based on hired_at).
     *  4. HR has not dismissed the reminder for this employee.
     *
     * @param int   $emp_id   HR employee ID.
     * @param array $emp      Employee row data.
     * @return bool
     */
    public static function qualifies( int $emp_id, array $emp ): bool {
        $settings = get_option( 'sfs_hr_gov_support_settings', [] );

        if ( empty( $settings['enabled'] ) ) {
            return false;
        }

        $target_nationality = $settings['nationality'] ?? 'Saudi';
        $threshold_months   = max( 1, (int) ( $settings['threshold_months'] ?? 3 ) );

        // Check nationality (case-insensitive match).
        $emp_nationality = trim( (string) ( $emp['nationality'] ?? '' ) );
        $lower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
        if ( $lower( $emp_nationality ) !== $lower( $target_nationality ) ) {
            return false;
        }

        // Check hire date.
        $hired_at = $emp['hired_at'] ?? $emp['hire_date'] ?? '';
        if ( empty( $hired_at ) ) {
            return false;
        }

        $hire_ts   = strtotime( $hired_at );
        $now_ts    = current_time( 'timestamp' );
        if ( ! $hire_ts || $hire_ts > $now_ts ) {
            return false;
        }

        $months_employed = (int) floor( ( $now_ts - $hire_ts ) / ( 30.44 * DAY_IN_SECONDS ) );
        if ( $months_employed < $threshold_months ) {
            return false;
        }

        // Check if HR dismissed this employee's reminder.
        $dismissed = get_option( self::OPT_DISMISSED, [] );
        if ( is_array( $dismissed ) && in_array( $emp_id, $dismissed, true ) ) {
            return false;
        }

        return true;
    }

    /**
     * Send email notification to HR for a qualifying employee (if not already sent).
     */
    public static function maybe_send_email( int $emp_id, array $emp ): void {
        // Check if already emailed for this employee (stored globally, not per-user).
        $emailed = get_option( self::META_EMAILED, [] );
        if ( ! is_array( $emailed ) ) {
            $emailed = [];
        }
        if ( in_array( $emp_id, $emailed, true ) ) {
            return;
        }

        $settings         = get_option( 'sfs_hr_gov_support_settings', [] );
        $threshold_months = max( 1, (int) ( $settings['threshold_months'] ?? 3 ) );

        $name = trim( ( $emp['first_name'] ?? '' ) . ' ' . ( $emp['last_name'] ?? '' ) );
        $code = $emp['employee_code'] ?? 'N/A';

        $notification_settings = class_exists( Notifications::class )
            ? Notifications::get_settings()
            : [];
        $hr_emails = $notification_settings['hr_emails'] ?? [];

        if ( ! empty( $hr_emails ) ) {
            $subject = sprintf(
                /* translators: %s: employee name */
                __( '[Government Support] %s may be eligible', 'sfs-hr' ),
                $name
            );
            $message = sprintf(
                /* translators: 1: employee name, 2: employee code, 3: number of months, 4: nationality */
                __( '%1$s (%2$s) has been employed for more than %3$d months and is of %4$s nationality.<br><br>Please review whether government support registration is required.', 'sfs-hr' ),
                esc_html( $name ),
                esc_html( $code ),
                $threshold_months,
                esc_html( $settings['nationality'] ?? 'Saudi' )
            );
            Helpers::send_mail( $hr_emails, $subject, $message );

            // Mark as emailed only after an actual send attempt.
            $emailed[] = $emp_id;
            update_option( self::META_EMAILED, $emailed, false );
        }
    }

    /**
     * Render the government support reminder banner.
     *
     * Only visible to HR/admin users. Includes a dismiss button.
     *
     * @param int   $emp_id  HR employee ID.
     * @param array $emp     Employee row data.
     */
    public static function render( int $emp_id, array $emp ): void {
        if ( ! self::qualifies( $emp_id, $emp ) ) {
            return;
        }

        // Only show to HR/admin.
        $is_hr = current_user_can( 'sfs_hr.manage' ) || current_user_can( 'manage_options' );
        if ( ! $is_hr ) {
            return;
        }

        // Send email on first detection.
        self::maybe_send_email( $emp_id, $emp );

        $settings         = get_option( 'sfs_hr_gov_support_settings', [] );
        $threshold_months = max( 1, (int) ( $settings['threshold_months'] ?? 3 ) );
        $nationality      = $settings['nationality'] ?? 'Saudi';

        $name = trim( ( $emp['first_name'] ?? '' ) . ' ' . ( $emp['last_name'] ?? '' ) );

        $banner_id = 'sfs-gov-support-' . $emp_id;
        $heading   = esc_html__( 'Government Support Reminder', 'sfs-hr' );
        $msg       = sprintf(
            /* translators: 1: employee name, 2: nationality, 3: number of months */
            esc_html__( '%1$s is a %2$s national and has been employed for more than %3$d months. Please check if government support registration is required.', 'sfs-hr' ),
            '<strong>' . esc_html( $name ) . '</strong>',
            esc_html( $nationality ),
            $threshold_months
        );

        echo '<div id="' . esc_attr( $banner_id ) . '" class="sfs-alert" style="background:#eff6ff;border:1px solid #60a5fa;border-radius:8px;padding:14px 16px;margin-bottom:16px;display:flex;align-items:flex-start;gap:12px;">';
        // Info icon.
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;flex-shrink:0;margin-top:2px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
        echo '<div style="flex:1;">';
        echo '<div style="font-weight:600;font-size:14px;color:#1e40af;margin-bottom:4px;" data-i18n-key="gov_support_reminder">' . $heading . '</div>';
        echo '<div style="font-size:13px;color:#1e3a5f;line-height:1.5;margin-bottom:12px;">' . $msg . '</div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';

        $nonce    = wp_create_nonce( 'sfs_hr_dismiss_gov_support' );
        $ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
        $dismiss_label = esc_html__( 'Dismiss', 'sfs-hr' );

        echo '<button type="button" class="sfs-btn" style="font-size:13px;padding:6px 14px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;" data-i18n-key="dismiss" onclick="sfsDismissGovSupport(this,' . (int) $emp_id . ')">' . $dismiss_label . '</button>';

        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Inline dismiss JS (output once per page).
        static $js_output = false;
        if ( ! $js_output ) {
            $js_output = true;
            echo '<script>';
            echo 'function sfsDismissGovSupport(btn,empId){';
            echo 'var banner=btn.closest("[id^=sfs-gov-support]");';
            echo 'if(banner)banner.style.display="none";';
            echo 'var fd=new FormData();';
            echo 'fd.append("action","sfs_hr_dismiss_gov_support");';
            echo 'fd.append("_wpnonce",' . wp_json_encode( $nonce ) . ');';
            echo 'fd.append("emp_id",empId);';
            echo 'fetch(' . wp_json_encode( $ajax_url ) . ',{method:"POST",credentials:"same-origin",body:fd});';
            echo '}</script>';
        }
    }

    /**
     * AJAX handler: HR dismisses the government support reminder for an employee.
     */
    public static function ajax_dismiss(): void {
        check_ajax_referer( 'sfs_hr_dismiss_gov_support' );

        if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'forbidden', 403 );
        }

        $emp_id = absint( $_POST['emp_id'] ?? 0 );
        if ( ! $emp_id ) {
            wp_send_json_error( 'missing_emp_id', 400 );
        }

        $dismissed = get_option( self::OPT_DISMISSED, [] );
        if ( ! is_array( $dismissed ) ) {
            $dismissed = [];
        }

        if ( ! in_array( $emp_id, $dismissed, true ) ) {
            $dismissed[] = $emp_id;
        }

        update_option( self::OPT_DISMISSED, $dismissed, false );
        wp_send_json_success();
    }
}
