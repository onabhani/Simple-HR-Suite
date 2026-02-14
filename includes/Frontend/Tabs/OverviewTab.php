<?php
/**
 * Overview Tab - Dashboard-style overview for the employee portal.
 *
 * Shows greeting, quick attendance action, summary KPIs, leave balances,
 * recent requests, and profile completion.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Modules\Attendance\Rest\Public_REST as Attendance_Public_REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OverviewTab implements TabInterface {

    /** Color palette for balance mini-cards. */
    private const CARD_COLORS = [ 'sky', 'rose', 'violet', 'amber', 'emerald', 'indigo', 'pink', 'orange', 'teal', 'slate' ];

    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        global $wpdb;

        $first_name    = (string) ( $emp['first_name'] ?? '' );
        $last_name     = (string) ( $emp['last_name']  ?? '' );
        $first_name_ar = (string) ( $emp['first_name_ar'] ?? '' );
        $full_name     = trim( $first_name . ' ' . $last_name );
        $photo_id      = isset( $emp['photo_id'] ) ? (int) $emp['photo_id'] : 0;
        $can_self_clock = class_exists( Attendance_Public_REST::class )
            && Attendance_Public_REST::can_punch_self();

        // Greeting based on time of day.
        $hour = (int) wp_date( 'G' );
        if ( $hour < 12 ) {
            $greeting     = __( 'Good Morning', 'sfs-hr' );
            $greeting_key = 'good_morning';
        } elseif ( $hour < 17 ) {
            $greeting     = __( 'Good Afternoon', 'sfs-hr' );
            $greeting_key = 'good_afternoon';
        } else {
            $greeting     = __( 'Good Evening', 'sfs-hr' );
            $greeting_key = 'good_evening';
        }

        $year  = (int) current_time( 'Y' );
        $today = current_time( 'Y-m-d' );

        // ── Leave data ────────────────────────────────────────────
        $req_table  = $wpdb->prefix . 'sfs_hr_leave_requests';
        $type_table = $wpdb->prefix . 'sfs_hr_leave_types';
        $bal_table  = $wpdb->prefix . 'sfs_hr_leave_balances';

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

        $annual_balance = 0;
        $total_used     = 0;
        foreach ( $balances as $b ) {
            $total_used += (int) ( $b['used'] ?? 0 );
            if ( $annual_balance === 0 && ! empty( $b['is_annual'] ) ) {
                $annual_balance = (int) ( $b['closing'] ?? 0 );
            }
        }

        $requests_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$req_table} WHERE employee_id = %d AND YEAR(start_date) = %d",
                $emp_id,
                $year
            )
        );

        $pending_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$req_table} WHERE employee_id = %d AND status = 'pending'",
                $emp_id
            )
        );

        // Next approved leave.
        $next_leave = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, t.name AS type_name
                 FROM {$req_table} r
                 LEFT JOIN {$type_table} t ON t.id = r.type_id
                 WHERE r.employee_id = %d AND r.status = 'approved' AND r.start_date >= %s
                 ORDER BY r.start_date ASC LIMIT 1",
                $emp_id,
                $today
            )
        );

        // Recent leave requests (last 3).
        $recent_leaves = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, t.name AS type_name
                 FROM {$req_table} r
                 LEFT JOIN {$type_table} t ON t.id = r.type_id
                 WHERE r.employee_id = %d
                 ORDER BY r.created_at DESC
                 LIMIT 3",
                $emp_id
            )
        );

        // ── Loan data ─────────────────────────────────────────────
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        $recent_loans = [];
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$loans_table}'" ) === $loans_table ) {
            $recent_loans = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$loans_table} WHERE employee_id = %d ORDER BY created_at DESC LIMIT 3",
                    $emp_id
                )
            );
        }

        // ── Attendance this month ─────────────────────────────────
        $sess_table  = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $month_start = wp_date( 'Y-m-01' );
        $month_end   = wp_date( 'Y-m-t' );

        $att_present = 0;
        $att_absent  = 0;
        $att_late    = 0;

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sess_table}'" ) === $sess_table ) {
            $att_present = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$sess_table}
                     WHERE employee_id = %d AND work_date BETWEEN %s AND %s AND status = 'present'",
                    $emp_id,
                    $month_start,
                    $month_end
                )
            );
            $att_absent = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$sess_table}
                     WHERE employee_id = %d AND work_date BETWEEN %s AND %s AND status IN ('absent','not_clocked_in')",
                    $emp_id,
                    $month_start,
                    $month_end
                )
            );
            $att_late = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$sess_table}
                     WHERE employee_id = %d AND work_date BETWEEN %s AND %s AND status = 'late'",
                    $emp_id,
                    $month_start,
                    $month_end
                )
            );
        }

        // ── Profile completion ────────────────────────────────────
        $email       = (string) ( $emp['email'] ?? '' );
        $phone       = (string) ( $emp['phone'] ?? '' );
        $position    = (string) ( $emp['position'] ?? '' );
        $gender      = (string) ( $emp['gender'] ?? '' );
        $hire_date   = (string) ( $emp['hired_at'] ?? '' );
        $national_id = (string) ( $emp['national_id'] ?? '' );
        $emg_name    = (string) ( $emp['emergency_contact_name'] ?? '' );
        $emg_phone   = (string) ( $emp['emergency_contact_phone'] ?? '' );
        $dept_id     = (int) ( $emp['dept_id'] ?? 0 );

        $profile_fields = [
            'photo'       => $photo_id > 0,
            'name'        => $first_name !== '' && $last_name !== '',
            'email'       => $email !== '',
            'phone'       => $phone !== '',
            'gender'      => $gender !== '',
            'position'    => $position !== '',
            'department'  => $dept_id > 0,
            'hire_date'   => $hire_date !== '',
            'national_id' => $national_id !== '',
            'emergency'   => $emg_name !== '' && $emg_phone !== '',
        ];
        $profile_completed      = array_filter( $profile_fields );
        $profile_completion_pct = (int) round( ( count( $profile_completed ) / count( $profile_fields ) ) * 100 );

        // Build tab URLs.
        $base_url       = remove_query_arg( [ 'sfs_hr_tab', 'leave_err', 'leave_msg' ] );
        $leave_url      = add_query_arg( 'sfs_hr_tab', 'leave', $base_url );
        $loans_url      = add_query_arg( 'sfs_hr_tab', 'loans', $base_url );
        $attendance_url = add_query_arg( 'sfs_hr_tab', 'attendance', $base_url );
        $profile_url    = add_query_arg( 'sfs_hr_tab', 'profile', $base_url );

        // ─── Render ───────────────────────────────────────────────
        echo '<div class="sfs-overview">';

        // ── 1. Unified Hero Card ─────────────────────────────────
        $today_display = wp_date( 'l, j M Y' );
        $name_ar       = trim( $first_name_ar . ' ' . ( $emp['last_name_ar'] ?? '' ) ) ?: $full_name;

        echo '<div class="sfs-overview-hero">';
        echo '<div class="sfs-overview-hero-top">';

        // Avatar (64px)
        echo '<div class="sfs-overview-hero-avatar">';
        if ( $photo_id ) {
            echo wp_get_attachment_image(
                $photo_id,
                [ 64, 64 ],
                false,
                [
                    'class' => 'sfs-overview-avatar-img',
                    'style' => 'border-radius:50%;display:block;object-fit:cover;width:64px;height:64px;',
                ]
            );
        } else {
            $initials = strtoupper( mb_substr( $first_name, 0, 1 ) . mb_substr( $last_name, 0, 1 ) );
            echo '<div class="sfs-overview-avatar-placeholder">' . esc_html( $initials ) . '</div>';
        }
        echo '</div>';

        // Greeting text + date
        echo '<div class="sfs-overview-hero-text">';
        echo '<span class="sfs-overview-greeting-label" data-i18n-key="' . esc_attr( $greeting_key ) . '">' . esc_html( $greeting ) . '</span>';
        echo '<h2 class="sfs-overview-greeting-name" data-name-en="' . esc_attr( $full_name ) . '" data-name-ar="' . esc_attr( $name_ar ) . '">' . esc_html( $full_name ) . '</h2>';
        echo '<span class="sfs-overview-hero-date">' . esc_html( $today_display ) . '</span>';
        echo '</div>';

        // Attendance button (only if allowed)
        if ( $can_self_clock ) {
            echo '<a href="' . esc_url( $attendance_url ) . '" class="sfs-overview-action-btn" data-sfs-att-btn="overview" data-i18n-key="attendance">';
            echo esc_html__( 'Attendance', 'sfs-hr' );
            echo '</a>';
        }

        echo '</div>'; // .sfs-overview-hero-top

        // Conditional timing row — hidden by default, shown via JS when clocked in
        if ( $can_self_clock ) {
            echo '<div class="sfs-overview-hero-timing" id="sfs-hero-timing" style="display:none;">';
            echo '<div class="sfs-overview-hero-timing-item">';
            echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
            echo '<span class="sfs-overview-hero-timing-label" data-i18n-key="clock_in">' . esc_html__( 'Clock In', 'sfs-hr' ) . '</span>';
            echo '<span class="sfs-overview-hero-timing-value" id="sfs-hero-clock-in">--:--</span>';
            echo '</div>';
            echo '<div class="sfs-overview-hero-timing-sep"></div>';
            echo '<div class="sfs-overview-hero-timing-item">';
            echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/></svg>';
            echo '<span class="sfs-overview-hero-timing-label" data-i18n-key="working">' . esc_html__( 'Working', 'sfs-hr' ) . '</span>';
            echo '<span class="sfs-overview-hero-timing-value" id="sfs-hero-working">0h 0m</span>';
            echo '</div>';
            echo '</div>'; // .sfs-overview-hero-timing
        }

        echo '</div>'; // .sfs-overview-hero

        // Script to fetch current attendance status — updates button label + timing row
        if ( $can_self_clock ) {
            echo '<script>';
            echo '(function(){';
            echo 'var btn=document.querySelector(".sfs-overview-action-btn[data-sfs-att-btn=\'overview\']");';
            echo 'if(!btn)return;';
            echo 'var url=' . wp_json_encode( esc_url_raw( rest_url( 'sfs-hr/v1/attendance/status' ) ) ) . ';';
            echo 'var nonce=' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ';';
            echo 'fetch(url,{credentials:"same-origin",headers:{"X-WP-Nonce":nonce,"Cache-Control":"no-cache"}})';
            echo '.then(function(r){return r.ok?r.json():null})';
            echo '.then(function(d){';
            echo 'if(!d)return;var a=d.allow||d,l="",labels={';
            echo 'in:' . wp_json_encode( __( 'Clock In', 'sfs-hr' ) ) . ',';
            echo 'break_start:' . wp_json_encode( __( 'Start Break', 'sfs-hr' ) ) . ',';
            echo 'break_end:' . wp_json_encode( __( 'End Break', 'sfs-hr' ) ) . ',';
            echo 'out:' . wp_json_encode( __( 'Clock Out', 'sfs-hr' ) ) . '};';
            echo 'var keys={in:"clock_in",break_start:"start_break",break_end:"end_break",out:"clock_out"};';
            echo 'if(a.in){l=labels.in;btn.dataset.i18nKey=keys.in;}else if(a.break_start){l=labels.break_start;btn.dataset.i18nKey=keys.break_start;}else if(a.break_end){l=labels.break_end;btn.dataset.i18nKey=keys.break_end;}else if(a.out){l=labels.out;btn.dataset.i18nKey=keys.out;}';
            echo 'if(l)btn.textContent=l;';
            // Show timing row when clocked in (state !== idle)
            echo 'var st=d.state||"idle";';
            echo 'var timingRow=document.getElementById("sfs-hero-timing");';
            echo 'if(timingRow&&st!=="idle"){';
            echo 'timingRow.style.display="";';
            echo 'var ciEl=document.getElementById("sfs-hero-clock-in");';
            echo 'if(ciEl&&d.clock_in_time)ciEl.textContent=d.clock_in_time;';
            echo 'var wEl=document.getElementById("sfs-hero-working");';
            echo 'if(wEl&&typeof d.working_seconds==="number"){';
            echo 'var s=d.working_seconds,h=Math.floor(s/3600),m=Math.floor((s%3600)/60);';
            echo 'wEl.textContent=h+"h "+m+"m";';
            echo '}';
            echo '}';
            echo '}).catch(function(){});';
            echo '})();</script>';
        }

        // ── 3. Monthly Attendance Summary ─────────────────────────
        $month_num = (int) wp_date( 'n' );
        $year_num  = (int) wp_date( 'Y' );
        echo '<div class="sfs-overview-section">';
        echo '<h3 class="sfs-overview-section-title" data-i18n-key="attendance_this_month">' . esc_html__( 'Attendance This Month', 'sfs-hr' ) . '</h3>';
        echo '<span class="sfs-overview-section-sub" data-month="' . $month_num . '" data-year="' . $year_num . '">' . esc_html( wp_date( 'F Y' ) ) . '</span>';
        echo '</div>';

        echo '<div class="sfs-overview-att-grid">';

        echo '<div class="sfs-overview-att-card sfs-overview-att-card--present">';
        echo '<div class="sfs-overview-att-card-value">' . $att_present . '</div>';
        echo '<div class="sfs-overview-att-card-label" data-i18n-key="present">' . esc_html__( 'Present', 'sfs-hr' ) . '</div>';
        echo '</div>';

        echo '<div class="sfs-overview-att-card sfs-overview-att-card--absent">';
        echo '<div class="sfs-overview-att-card-value">' . $att_absent . '</div>';
        echo '<div class="sfs-overview-att-card-label" data-i18n-key="absent">' . esc_html__( 'Absent', 'sfs-hr' ) . '</div>';
        echo '</div>';

        echo '<div class="sfs-overview-att-card sfs-overview-att-card--late">';
        echo '<div class="sfs-overview-att-card-value">' . $att_late . '</div>';
        echo '<div class="sfs-overview-att-card-label" data-i18n-key="late">' . esc_html__( 'Late', 'sfs-hr' ) . '</div>';
        echo '</div>';

        echo '</div>'; // .sfs-overview-att-grid

        // ── 4. Leave Summary KPIs ─────────────────────────────────
        echo '<div class="sfs-overview-section">';
        echo '<h3 class="sfs-overview-section-title" data-i18n-key="leave_summary">' . esc_html__( 'Leave Summary', 'sfs-hr' ) . '</h3>';
        echo '<span class="sfs-overview-section-sub"><span data-i18n-key="year">' . esc_html__( 'Year', 'sfs-hr' ) . '</span> ' . $year . '</span>';
        echo '</div>';

        echo '<div class="sfs-kpi-grid">';

        // Annual balance
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#ecfdf5;"><svg viewBox="0 0 24 24" stroke="#10b981" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="annual_balance">' . esc_html__( 'Annual balance', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $annual_balance . ' <span class="sfs-kpi-sub" data-i18n-key="days">' . esc_html__( 'days', 'sfs-hr' ) . '</span></div>';
        echo '</div>';

        // Requests
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#eff6ff;"><svg viewBox="0 0 24 24" stroke="#3b82f6" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="requests_this_year">' . esc_html__( 'Requests', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $requests_count . '</div>';
        echo '</div>';

        // Used
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" stroke="#f59e0b" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="total_used">' . esc_html__( 'Used', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $total_used;
        if ( $pending_count > 0 ) {
            echo ' <span class="sfs-kpi-sub">+ ' . $pending_count . ' <span data-i18n-key="pending">' . esc_html__( 'pending', 'sfs-hr' ) . '</span></span>';
        }
        echo '</div></div>';

        // Next leave
        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#fce7f3;"><svg viewBox="0 0 24 24" stroke="#ec4899" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="next_approved_leave">' . esc_html__( 'Next leave', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value" style="font-size:14px;font-weight:600;">';
        if ( $next_leave ) {
            $start  = $next_leave->start_date ?: '';
            $end    = $next_leave->end_date ?: '';
            $period = ( $start && $end && $end !== $start ) ? ( $start . ' → ' . $end ) : $start;
            echo esc_html( $period );
        } else {
            echo '<span data-i18n-key="no_upcoming_leave">' . esc_html__( 'No upcoming', 'sfs-hr' ) . '</span>';
        }
        echo '</div></div>';

        echo '</div>'; // .sfs-kpi-grid

        // ── 5. Recent Activity ─────────────────────────────────────
        $has_activity = ! empty( $recent_leaves ) || ! empty( $recent_loans );
        if ( $has_activity ) {
            echo '<div class="sfs-overview-section">';
            echo '<h3 class="sfs-overview-section-title" data-i18n-key="recent_activity">' . esc_html__( 'Recent Activity', 'sfs-hr' ) . '</h3>';
            echo '</div>';

            echo '<div class="sfs-overview-activity-list">';

            // Recent leave requests.
            foreach ( $recent_leaves as $req ) {
                $type_name = $req->type_name ?: __( 'Leave', 'sfs-hr' );
                $status    = $req->status ?? 'pending';
                $start     = $req->start_date ?: '';
                $end       = $req->end_date ?: '';
                $period    = ( $start && $end && $end !== $start ) ? ( $start . ' → ' . $end ) : $start;
                $ref       = $req->request_number ?? '';

                echo '<a href="' . esc_url( $leave_url ) . '" class="sfs-overview-activity-item">';
                echo '<div class="sfs-overview-activity-icon sfs-overview-activity-icon--leave">';
                echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
                echo '</div>';
                echo '<div class="sfs-overview-activity-info">';
                echo '<div class="sfs-overview-activity-title">' . esc_html( $type_name );
                if ( $ref ) {
                    echo ' <span class="sfs-overview-activity-ref">#' . esc_html( $ref ) . '</span>';
                }
                echo '</div>';
                echo '<div class="sfs-overview-activity-meta">' . esc_html( $period ) . '</div>';
                echo '</div>';
                echo $this->status_badge( $status );
                echo '</a>';
            }

            // Recent loan requests.
            foreach ( $recent_loans as $loan ) {
                $status = $loan->status ?? 'pending';
                $amount = isset( $loan->principal_amount ) ? number_format_i18n( (float) $loan->principal_amount, 2 ) : '0';

                echo '<a href="' . esc_url( $loans_url ) . '" class="sfs-overview-activity-item">';
                echo '<div class="sfs-overview-activity-icon sfs-overview-activity-icon--loan">';
                echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
                echo '</div>';
                echo '<div class="sfs-overview-activity-info">';
                echo '<div class="sfs-overview-activity-title" data-i18n-key="loan">' . esc_html__( 'Loan', 'sfs-hr' ) . '</div>';
                echo '<div class="sfs-overview-activity-meta">' . esc_html( $amount ) . '</div>';
                echo '</div>';
                echo $this->status_badge( $status );
                echo '</a>';
            }

            echo '</div>'; // .sfs-overview-activity-list
        }

        // ── 7. Profile Completion Banner ──────────────────────────
        if ( $profile_completion_pct < 100 ) {
            echo '<a href="' . esc_url( $profile_url ) . '" class="sfs-overview-profile-banner">';
            echo '<div class="sfs-overview-profile-banner-text">';
            echo '<span class="sfs-overview-profile-banner-title" data-i18n-key="complete_your_profile">' . esc_html__( 'Complete Your Profile', 'sfs-hr' ) . '</span>';
            echo '<span class="sfs-overview-profile-banner-sub">' . esc_html( $profile_completion_pct ) . '%</span>';
            echo '</div>';
            echo '<div class="sfs-overview-profile-banner-bar">';
            echo '<div class="sfs-overview-profile-banner-fill" style="width:' . esc_attr( $profile_completion_pct ) . '%"></div>';
            echo '</div>';
            echo '<svg class="sfs-overview-profile-banner-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
            echo '</a>';
        }

        echo '</div>'; // .sfs-overview
    }

    /**
     * Render a status badge.
     *
     * @param string $status Status key.
     * @return string HTML.
     */
    private function status_badge( string $status ): string {
        $map = [
            'pending'   => [ 'label' => __( 'Pending', 'sfs-hr' ),  'class' => 'sfs-badge--warning' ],
            'approved'  => [ 'label' => __( 'Approved', 'sfs-hr' ), 'class' => 'sfs-badge--success' ],
            'rejected'  => [ 'label' => __( 'Rejected', 'sfs-hr' ), 'class' => 'sfs-badge--danger' ],
            'active'    => [ 'label' => __( 'Active', 'sfs-hr' ),   'class' => 'sfs-badge--info' ],
            'completed' => [ 'label' => __( 'Completed', 'sfs-hr' ), 'class' => 'sfs-badge--success' ],
            'cancelled' => [ 'label' => __( 'Cancelled', 'sfs-hr' ), 'class' => 'sfs-badge--muted' ],
        ];

        $info  = $map[ $status ] ?? [ 'label' => ucfirst( $status ), 'class' => 'sfs-badge--muted' ];
        return '<span class="sfs-badge ' . esc_attr( $info['class'] ) . '" data-i18n-key="' . esc_attr( $status ) . '">' . esc_html( $info['label'] ) . '</span>';
    }
}
