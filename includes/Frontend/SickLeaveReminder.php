<?php
/**
 * Sick Leave Reminder — shows a persistent notice on Overview and Leave tabs
 * when an employee has unexcused absences not covered by a leave request.
 *
 * The notice persists until HR explicitly dismisses it.  When new uncovered
 * absences are detected, both a dashboard alert AND an email notification
 * are sent (to the employee and HR).
 *
 * Uses the employee's hired_at date as the lookback start.
 *
 * @package SFS\HR\Frontend
 */

namespace SFS\HR\Frontend;

use SFS\HR\Core\Helpers;
use SFS\HR\Core\Notifications;
use SFS\HR\Modules\Attendance\Services\Shift_Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SickLeaveReminder {

    /** Option key: array of "{emp_id}:{date}" strings dismissed by HR. */
    private const OPT_DISMISSED = 'sfs_hr_sick_reminder_hr_dismissed';

    /** User meta key: tracks dates for which the email was already sent. */
    private const META_EMAILED = 'sfs_hr_sick_reminder_emailed';

    /**
     * Bootstrap hooks.
     */
    public static function init(): void {
        add_action( 'wp_ajax_sfs_hr_dismiss_sick_reminder', [ __CLASS__, 'ajax_dismiss' ] );
    }

    /**
     * Get uncovered absence dates for an employee since their hire date.
     *
     * Returns an array of Y-m-d date strings where:
     *  - attendance status is 'absent' or 'not_clocked_in'
     *  - no pending or approved leave request fully covers that date
     *  - HR has not dismissed this date for this employee
     *
     * @return string[]
     */
    public static function get_uncovered_absences( int $emp_id ): array {
        global $wpdb;

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $sess_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $req_table  = $wpdb->prefix . 'sfs_hr_leave_requests';

        $today = current_time( 'Y-m-d' );

        // Look back to the employee's hire date (fall back to 90 days ago).
        $hire_date = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(hired_at, hire_date) FROM {$emp_table} WHERE id = %d",
            $emp_id
        ) );
        $lookback = $hire_date ?: wp_date( 'Y-m-d', strtotime( '-90 days', strtotime( $today ) ) );

        // Absent dates in the window.
        $absent_dates = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT work_date FROM {$sess_table}
             WHERE employee_id = %d
               AND work_date BETWEEN %s AND %s
               AND status IN ('absent','not_clocked_in')
             ORDER BY work_date ASC",
            $emp_id,
            $lookback,
            $today
        ) );

        if ( empty( $absent_dates ) ) {
            return [];
        }

        // Dates already covered by a pending or approved leave request.
        $covered = [];
        $leave_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT start_date, end_date FROM {$req_table}
             WHERE employee_id = %d
               AND status IN ('pending','approved')
               AND COALESCE(end_date, start_date) >= %s AND start_date <= %s",
            $emp_id,
            $lookback,
            $today
        ) );

        foreach ( $leave_rows as $lr ) {
            $end = ! empty( $lr->end_date ) ? $lr->end_date : $lr->start_date;
            $cur = $lr->start_date;
            while ( $cur <= $end ) {
                $covered[ $cur ] = true;
                $cur = wp_date( 'Y-m-d', strtotime( $cur . ' +1 day' ) );
            }
        }

        // Dates dismissed by HR.
        $dismissed_raw = get_option( self::OPT_DISMISSED, [] );
        $dismissed_set = array_flip( is_array( $dismissed_raw ) ? $dismissed_raw : [] );

        $uncovered = [];
        foreach ( $absent_dates as $d ) {
            if ( isset( $covered[ $d ] ) ) {
                continue;
            }
            $key = $emp_id . ':' . $d;
            if ( isset( $dismissed_set[ $key ] ) ) {
                continue;
            }
            // Don't flag a date until at least 60 minutes after the last shift ends,
            // so employees aren't notified before their workday is actually over.
            if ( self::is_too_early_to_flag( $emp_id, $d ) ) {
                continue;
            }
            $uncovered[] = $d;
        }

        return $uncovered;
    }

    /**
     * Send email notification for uncovered absences (if not already sent).
     *
     * Emails both the employee and HR.
     */
    public static function maybe_send_email( int $emp_id, int $user_id, array $dates ): void {
        if ( empty( $dates ) ) {
            return;
        }

        // Check which dates have already been emailed (stored per employee, not per viewer).
        $emailed = (array) get_user_meta( $emp_id, self::META_EMAILED, true );
        $new_dates = array_diff( $dates, $emailed );

        if ( empty( $new_dates ) ) {
            return;
        }

        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $emp = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name, email, employee_code FROM {$emp_table} WHERE id = %d",
            $emp_id
        ) );

        if ( ! $emp ) {
            return;
        }

        $name       = trim( $emp->first_name . ' ' . $emp->last_name );
        $formatted  = array_map( static fn( $d ) => wp_date( 'M j, Y', strtotime( $d ) ), $new_dates );
        $dates_list = implode( ', ', $formatted );

        // Email to employee.
        if ( ! empty( $emp->email ) && is_email( $emp->email ) ) {
            $subject = __( 'Unexcused Absence Notice', 'sfs-hr' );
            $message = sprintf(
                /* translators: 1: employee name, 2: absence dates */
                __( 'Dear %1$s,<br><br>You were marked absent on the following date(s) without a leave request: <strong>%2$s</strong>.<br><br>If you have a sick leave document, please submit a leave request as soon as possible.', 'sfs-hr' ),
                esc_html( $name ),
                esc_html( $dates_list )
            );
            Helpers::send_mail( $emp->email, $subject, $message );
        }

        // Email to HR.
        $notification_settings = class_exists( Notifications::class )
            ? Notifications::get_settings()
            : [];
        $hr_emails = $notification_settings['hr_emails'] ?? [];
        if ( ! empty( $hr_emails ) ) {
            $hr_subject = sprintf(
                /* translators: %s: employee name */
                __( '[Unexcused Absence] %s has uncovered absences', 'sfs-hr' ),
                $name
            );
            $hr_message = sprintf(
                /* translators: 1: employee name, 2: employee code, 3: absence dates */
                __( '%1$s (%2$s) was marked absent on the following date(s) without a leave request: <strong>%3$s</strong>.<br><br>Please review and take appropriate action.', 'sfs-hr' ),
                esc_html( $name ),
                esc_html( $emp->employee_code ?: 'N/A' ),
                esc_html( $dates_list )
            );
            Helpers::send_mail( $hr_emails, $hr_subject, $hr_message );
        }

        // Mark these dates as emailed (stored per employee).
        $emailed = array_unique( array_merge( $emailed, array_values( $new_dates ) ) );
        update_user_meta( $emp_id, self::META_EMAILED, $emailed );
    }

    /**
     * Render the sick leave reminder banner (dashboard alert).
     *
     * Employee sees the alert but cannot dismiss it — only HR can.
     *
     * @param int    $emp_id   Employee ID.
     * @param int    $user_id  WordPress user ID.
     * @param string $leave_url URL to the leave tab.
     */
    public static function render( int $emp_id, int $user_id, string $leave_url ): void {
        $dates = self::get_uncovered_absences( $emp_id );
        if ( empty( $dates ) ) {
            return;
        }

        // Send email notification for any new dates.
        self::maybe_send_email( $emp_id, $user_id, $dates );

        $formatted = array_map( static function ( string $d ): string {
            return wp_date( 'M j', strtotime( $d ) );
        }, $dates );

        $dates_str = implode( ', ', $formatted );
        $banner_id = 'sfs-sick-reminder-' . $emp_id;

        // Build leave URL with pre-fill params: earliest absence → latest absence, auto-open modal.
        $leave_url = add_query_arg( [
            'sick_start' => $dates[0],
            'sick_end'   => end( $dates ),
            'open_modal' => '1',
        ], $leave_url );

        $heading   = esc_html__( 'Unexcused Absence', 'sfs-hr' );
        $msg       = sprintf(
            /* translators: %s: comma-separated list of absence dates */
            esc_html__( 'You were marked absent on %s without a leave request. Do you have a sick leave document?', 'sfs-hr' ),
            '<strong>' . esc_html( $dates_str ) . '</strong>'
        );
        $yes_label = esc_html__( 'Yes, submit sick leave', 'sfs-hr' );

        $is_hr = current_user_can( 'sfs_hr.manage' );

        echo '<div id="' . esc_attr( $banner_id ) . '" class="sfs-alert" style="background:#fffbeb;border:1px solid #fbbf24;border-radius:8px;padding:14px 16px;margin-bottom:16px;display:flex;align-items:flex-start;gap:12px;">';
        // Warning icon
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;flex-shrink:0;margin-top:2px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        echo '<div style="flex:1;">';
        echo '<div style="font-weight:600;font-size:14px;color:#92400e;margin-bottom:4px;" data-i18n-key="unexcused_absence">' . $heading . '</div>';
        echo '<div style="font-size:13px;color:#78350f;line-height:1.5;margin-bottom:12px;" data-sick-dates="' . esc_attr( wp_json_encode( array_values( $dates ) ) ) . '">' . $msg . '</div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        echo '<a href="' . esc_url( $leave_url ) . '" class="sfs-btn sfs-btn--primary" style="font-size:13px;padding:6px 14px;" data-i18n-key="yes_submit_sick_leave">' . $yes_label . '</a>';

        // Only HR can dismiss.
        if ( $is_hr ) {
            $nonce    = wp_create_nonce( 'sfs_hr_dismiss_sick_reminder' );
            $ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
            $dates_json = wp_json_encode( $dates );
            $dismiss_label = esc_html__( 'Dismiss', 'sfs-hr' );

            echo '<button type="button" class="sfs-btn" style="font-size:13px;padding:6px 14px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;" data-i18n-key="dismiss" data-dates="' . esc_attr( $dates_json ) . '" onclick="sfsDismissSickReminder(this,' . (int) $emp_id . ')">' . $dismiss_label . '</button>';
        }

        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Inline dismiss JS for HR (only output once per page).
        if ( $is_hr ) {
            static $js_output = false;
            if ( ! $js_output ) {
                $js_output = true;
                echo '<script>';
                echo 'function sfsDismissSickReminder(btn,empId){';
                echo 'var banner=btn.closest("[id^=sfs-sick-reminder]");';
                echo 'if(banner)banner.style.display="none";';
                echo 'var fd=new FormData();';
                echo 'fd.append("action","sfs_hr_dismiss_sick_reminder");';
                echo 'fd.append("_wpnonce",' . wp_json_encode( $nonce ) . ');';
                echo 'fd.append("dates",btn.getAttribute("data-dates")||"[]");';
                echo 'fd.append("emp_id",empId);';
                echo 'fetch(' . wp_json_encode( $ajax_url ) . ',{method:"POST",credentials:"same-origin",body:fd});';
                echo '}</script>';
            }
        }
    }

    /**
     * Check whether it's too early to flag a date as an unexcused absence.
     *
     * Returns true if the employee's last shift on $date hasn't ended yet
     * (plus a 60-minute grace buffer).  For past dates where no shift can be
     * resolved, returns false so the date is still flagged.
     *
     * @param int    $emp_id Employee ID.
     * @param string $date   Date in Y-m-d format.
     */
    private static function is_too_early_to_flag( int $emp_id, string $date ): bool {
        $now_local = current_time( 'Y-m-d H:i:s' );
        $today     = current_time( 'Y-m-d' );

        // Past dates are never "too early".
        if ( $date < $today ) {
            return false;
        }

        // Resolve the employee's shift for this date.
        $shift = Shift_Service::resolve_shift_for_date( $emp_id, $date );

        if ( ! $shift || empty( $shift->end_time ) ) {
            // No shift found — use end-of-day (23:59) + 60 min as a safe default.
            $cutoff = $date . ' 23:59:00';
        } else {
            $tz       = wp_timezone();
            $end_time = $shift->end_time; // e.g. '17:00:00'
            $end_dt   = new \DateTimeImmutable( $date . ' ' . $end_time, $tz );

            // Handle overnight shifts: end_time < start_time means shift ends next day.
            if ( ! empty( $shift->start_time ) && $end_time < $shift->start_time ) {
                $end_dt = $end_dt->modify( '+1 day' );
            }

            $cutoff = $end_dt->format( 'Y-m-d H:i:s' );
        }

        // Add 60-minute grace period after shift end.
        $cutoff_ts = strtotime( $cutoff ) + 3600;
        $now_ts    = strtotime( $now_local );

        return $now_ts < $cutoff_ts;
    }

    /**
     * AJAX handler: HR dismisses the sick leave reminder for specific dates.
     */
    public static function ajax_dismiss(): void {
        check_ajax_referer( 'sfs_hr_dismiss_sick_reminder' );

        // Only HR can dismiss.
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_send_json_error( 'forbidden', 403 );
        }

        $emp_id = absint( $_POST['emp_id'] ?? 0 );
        if ( ! $emp_id ) {
            wp_send_json_error( 'missing_emp_id', 400 );
        }

        $raw   = isset( $_POST['dates'] ) ? sanitize_text_field( wp_unslash( $_POST['dates'] ) ) : '[]';
        $dates = json_decode( $raw, true );
        if ( ! is_array( $dates ) ) {
            wp_send_json_error( 'invalid', 400 );
        }

        $existing = get_option( self::OPT_DISMISSED, [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        foreach ( $dates as $d ) {
            $d = sanitize_text_field( $d );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
                $key = $emp_id . ':' . $d;
                if ( ! in_array( $key, $existing, true ) ) {
                    $existing[] = $key;
                }
            }
        }

        // Prune entries older than 1 year to prevent unbounded growth.
        $cutoff   = wp_date( 'Y-m-d', strtotime( '-1 year' ) );
        $existing = array_values( array_filter( $existing, static function ( $entry ) use ( $cutoff ) {
            $parts = explode( ':', $entry, 2 );
            return isset( $parts[1] ) && $parts[1] >= $cutoff;
        } ) );

        update_option( self::OPT_DISMISSED, $existing, false );
        wp_send_json_success();
    }
}
