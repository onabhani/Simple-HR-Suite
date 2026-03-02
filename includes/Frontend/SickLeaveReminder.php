<?php
/**
 * Sick Leave Reminder — shows a notice on Overview and Leave tabs when an
 * employee has recent unexcused absences that are not covered by a leave request.
 *
 * The notice persists for up to 3 days from the absence date.  The employee can
 * click "Yes, I have a document" to go to the Leave tab, or "No" to dismiss
 * (stored in user meta so it doesn't reappear for those dates).
 *
 * @package SFS\HR\Frontend
 */

namespace SFS\HR\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SickLeaveReminder {

    private const META_KEY = 'sfs_hr_sick_reminder_dismissed';

    /**
     * Bootstrap — register the dismiss AJAX handler.
     */
    public static function init(): void {
        add_action( 'wp_ajax_sfs_hr_dismiss_sick_reminder', [ __CLASS__, 'ajax_dismiss' ] );
    }

    /**
     * Get uncovered absence dates for an employee within the last 3 days.
     *
     * Returns an array of Y-m-d date strings where:
     *  - attendance status is 'absent' or 'not_clocked_in'
     *  - no pending or approved leave request fully covers that date
     *  - the employee has not dismissed this date
     *
     * @return string[]
     */
    public static function get_uncovered_absences( int $emp_id, int $user_id ): array {
        global $wpdb;

        $sess_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $req_table  = $wpdb->prefix . 'sfs_hr_leave_requests';

        // Only look back 3 calendar days (today included): today, yesterday, day before.
        $today     = current_time( 'Y-m-d' );
        $three_ago = wp_date( 'Y-m-d', strtotime( '-2 days', strtotime( $today ) ) );

        // Absent dates in the window.
        $absent_dates = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT work_date FROM {$sess_table}
             WHERE employee_id = %d
               AND work_date BETWEEN %s AND %s
               AND status IN ('absent','not_clocked_in')
             ORDER BY work_date ASC",
            $emp_id,
            $three_ago,
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
            $three_ago,
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

        // Dates dismissed by the user.
        $dismissed = (array) get_user_meta( $user_id, self::META_KEY, true );

        $uncovered = [];
        foreach ( $absent_dates as $d ) {
            if ( isset( $covered[ $d ] ) ) {
                continue;
            }
            if ( in_array( $d, $dismissed, true ) ) {
                continue;
            }
            $uncovered[] = $d;
        }

        return $uncovered;
    }

    /**
     * Render the sick leave reminder banner.
     *
     * @param int      $emp_id   Employee ID.
     * @param int      $user_id  WordPress user ID.
     * @param string   $leave_url URL to the leave tab.
     */
    public static function render( int $emp_id, int $user_id, string $leave_url ): void {
        $dates = self::get_uncovered_absences( $emp_id, $user_id );
        if ( empty( $dates ) ) {
            return;
        }

        $formatted = array_map( static function ( string $d ): string {
            return wp_date( 'M j', strtotime( $d ) );
        }, $dates );

        $dates_json = wp_json_encode( $dates );
        $nonce      = wp_create_nonce( 'sfs_hr_dismiss_sick_reminder' );
        $ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );
        $dates_str  = implode( ', ', $formatted );
        $banner_id  = 'sfs-sick-reminder-' . $emp_id;

        // Build leave URL with pre-fill params: earliest absence → latest absence, auto-open modal.
        $leave_url = add_query_arg( [
            'sick_start' => $dates[0],
            'sick_end'   => end( $dates ),
            'open_modal' => '1',
        ], $leave_url );

        $heading = esc_html__( 'Unexcused Absence', 'sfs-hr' );
        $msg     = sprintf(
            /* translators: %s: comma-separated list of absence dates */
            esc_html__( 'You were marked absent on %s without a leave request. Do you have a sick leave document?', 'sfs-hr' ),
            '<strong>' . esc_html( $dates_str ) . '</strong>'
        );
        $yes_label = esc_html__( 'Yes, submit sick leave', 'sfs-hr' );
        $no_label  = esc_html__( 'No', 'sfs-hr' );

        echo '<div id="' . esc_attr( $banner_id ) . '" class="sfs-alert" style="background:#fffbeb;border:1px solid #fbbf24;border-radius:8px;padding:14px 16px;margin-bottom:16px;display:flex;align-items:flex-start;gap:12px;">';
        // Warning icon
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;flex-shrink:0;margin-top:2px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        echo '<div style="flex:1;">';
        echo '<div style="font-weight:600;font-size:14px;color:#92400e;margin-bottom:4px;" data-i18n-key="unexcused_absence">' . $heading . '</div>';
        echo '<div style="font-size:13px;color:#78350f;line-height:1.5;margin-bottom:12px;">' . $msg . '</div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        echo '<a href="' . esc_url( $leave_url ) . '" class="sfs-btn sfs-btn--primary" style="font-size:13px;padding:6px 14px;" data-i18n-key="yes_submit_sick_leave">' . $yes_label . '</a>';
        echo '<button type="button" class="sfs-btn" style="font-size:13px;padding:6px 14px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;" data-i18n-key="no" onclick="sfsDismissSickReminder(this)">' . $no_label . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Inline dismiss JS (only output once per page).
        static $js_output = false;
        if ( ! $js_output ) {
            $js_output = true;
            echo '<script>';
            echo 'function sfsDismissSickReminder(btn){';
            echo 'var banner=btn.closest("[id^=sfs-sick-reminder]");';
            echo 'if(banner)banner.style.display="none";';
            echo 'var fd=new FormData();';
            echo 'fd.append("action","sfs_hr_dismiss_sick_reminder");';
            echo 'fd.append("_wpnonce",' . wp_json_encode( $nonce ) . ');';
            echo 'fd.append("dates",' . wp_json_encode( $dates_json ) . ');';
            echo 'fetch(' . wp_json_encode( $ajax_url ) . ',{method:"POST",credentials:"same-origin",body:fd});';
            echo '}</script>';
        }
    }

    /**
     * AJAX handler: dismiss the sick leave reminder for specific dates.
     */
    public static function ajax_dismiss(): void {
        check_ajax_referer( 'sfs_hr_dismiss_sick_reminder' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'not_logged_in', 403 );
        }

        $raw   = isset( $_POST['dates'] ) ? sanitize_text_field( wp_unslash( $_POST['dates'] ) ) : '[]';
        $dates = json_decode( $raw, true );
        if ( ! is_array( $dates ) ) {
            wp_send_json_error( 'invalid', 400 );
        }

        $existing = (array) get_user_meta( $user_id, self::META_KEY, true );
        foreach ( $dates as $d ) {
            $d = sanitize_text_field( $d );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && ! in_array( $d, $existing, true ) ) {
                $existing[] = $d;
            }
        }

        // Keep only dates within the last 7 days to prevent unbounded growth.
        $cutoff   = wp_date( 'Y-m-d', strtotime( '-7 days' ) );
        $existing = array_values( array_filter( $existing, static fn( $d ) => $d >= $cutoff ) );

        update_user_meta( $user_id, self::META_KEY, $existing );
        wp_send_json_success();
    }
}
