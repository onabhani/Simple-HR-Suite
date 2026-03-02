<?php
/**
 * Overview Tab - Dashboard-style overview for the employee portal.
 *
 * Shows greeting, quick attendance action, attendance summary, leave KPIs,
 * today's shift, pending/upcoming requests, action-required items,
 * upcoming timeline, documents, and profile completion.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Modules\Attendance\Rest\Public_REST as Attendance_Public_REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OverviewTab implements TabInterface {

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

        // Pending leave requests (waiting for approval).
        $pending_leaves = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, t.name AS type_name
                 FROM {$req_table} r
                 LEFT JOIN {$type_table} t ON t.id = r.type_id
                 WHERE r.employee_id = %d AND r.status = 'pending'
                 ORDER BY r.created_at DESC
                 LIMIT 5",
                $emp_id
            )
        );

        // Upcoming approved leaves (future).
        $upcoming_leaves = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, t.name AS type_name
                 FROM {$req_table} r
                 LEFT JOIN {$type_table} t ON t.id = r.type_id
                 WHERE r.employee_id = %d AND r.status = 'approved' AND r.start_date >= %s
                 ORDER BY r.start_date ASC
                 LIMIT 5",
                $emp_id,
                $today
            )
        );

        // ── Loan data ─────────────────────────────────────────────
        $loans_table   = $wpdb->prefix . 'sfs_hr_loans';
        $pending_loans = [];
        $active_loans  = [];
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$loans_table}'" ) === $loans_table ) {
            $pending_loans = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$loans_table} WHERE employee_id = %d AND status = 'pending' ORDER BY created_at DESC LIMIT 5",
                    $emp_id
                )
            );
            $active_loans = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$loans_table} WHERE employee_id = %d AND status = 'active' ORDER BY created_at DESC LIMIT 3",
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

        // ── Today's shift ─────────────────────────────────────────
        $today_shift = null;
        if ( class_exists( '\SFS\HR\Modules\Attendance\AttendanceModule' ) ) {
            $today_shift = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( $emp_id, $today );
        }

        // ── Action required items ─────────────────────────────────
        $action_items = [];

        // Assets pending employee approval.
        $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$assign_table}'" ) === $assign_table ) {
            $pending_assets = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$assign_table} WHERE employee_id = %d AND status = 'pending_employee_approval'",
                    $emp_id
                )
            );
            if ( $pending_assets > 0 ) {
                $action_items[] = [
                    'icon'    => 'package',
                    'color'   => '#8b5cf6',
                    'bg'      => '#ede9fe',
                    'text'    => sprintf( _n( '%d asset awaiting your approval', '%d assets awaiting your approval', $pending_assets, 'sfs-hr' ), $pending_assets ),
                    'tab'     => 'profile',
                    'i18n'    => 'asset_approval',
                ];
            }
        }

        // Missing documents.
        $missing_docs_count = 0;
        $expired_docs_count = 0;
        $doc_total_count    = 0;
        if ( class_exists( '\SFS\HR\Modules\Documents\Services\Documents_Service' ) ) {
            $doc_svc            = '\SFS\HR\Modules\Documents\Services\Documents_Service';
            $missing_docs       = $doc_svc::get_missing_required_documents( $emp_id );
            $missing_docs_count = count( $missing_docs );
            $doc_total_count    = $doc_svc::get_document_count( $emp_id );
            $doc_status         = $doc_svc::get_employee_document_status( $emp_id );
            $expired_docs_count = $doc_status ? (int) ( $doc_status['expired_count'] ?? 0 ) : 0;
        }
        if ( $missing_docs_count > 0 ) {
            $action_items[] = [
                'icon'    => 'file-warning',
                'color'   => '#dc2626',
                'bg'      => '#fef2f2',
                'text'    => sprintf( _n( '%d required document missing', '%d required documents missing', $missing_docs_count, 'sfs-hr' ), $missing_docs_count ),
                'tab'     => 'documents',
                'i18n'    => 'docs_missing',
            ];
        }
        if ( $expired_docs_count > 0 ) {
            $action_items[] = [
                'icon'    => 'alert-triangle',
                'color'   => '#d97706',
                'bg'      => '#fffbeb',
                'text'    => sprintf( _n( '%d document expired', '%d documents expired', $expired_docs_count, 'sfs-hr' ), $expired_docs_count ),
                'tab'     => 'documents',
                'i18n'    => 'docs_expired',
            ];
        }

        // ── Upcoming timeline events ──────────────────────────────
        $timeline = [];

        // Next approved leave.
        if ( $next_leave ) {
            $timeline[] = [
                'date'  => $next_leave->start_date,
                'label' => $next_leave->type_name ?: __( 'Leave', 'sfs-hr' ),
                'icon'  => 'calendar',
                'color' => '#3b82f6',
                'bg'    => '#eff6ff',
            ];
        }

        // Expiring documents (within 60 days).
        if ( class_exists( '\SFS\HR\Modules\Documents\Services\Documents_Service' ) ) {
            $doc_svc_class = '\SFS\HR\Modules\Documents\Services\Documents_Service';
            if ( method_exists( $doc_svc_class, 'get_expiring_documents' ) ) {
                $expiring = $doc_svc_class::get_expiring_documents( $emp_id, 60 );
                foreach ( $expiring as $edoc ) {
                    $exp_date  = $edoc->expiry_date ?? ( $edoc->expiration_date ?? '' );
                    $doc_label = $edoc->name ?? ( $edoc->document_name ?? __( 'Document', 'sfs-hr' ) );
                    if ( $exp_date ) {
                        $timeline[] = [
                            'date'  => $exp_date,
                            /* translators: %s: document name */
                            'label' => sprintf( __( '%s expires', 'sfs-hr' ), $doc_label ),
                            'icon'  => 'file-text',
                            'color' => '#d97706',
                            'bg'    => '#fffbeb',
                        ];
                    }
                }
            }
        }

        // National ID / Passport expiry if within 90 days.
        $nid_exp  = (string) ( $emp['national_id_expiry'] ?? '' );
        $pass_exp = (string) ( $emp['passport_expiry'] ?? '' );
        if ( $nid_exp && $nid_exp >= $today && $nid_exp <= wp_date( 'Y-m-d', strtotime( '+90 days' ) ) ) {
            $timeline[] = [
                'date'  => $nid_exp,
                'label' => __( 'National ID expires', 'sfs-hr' ),
                'icon'  => 'credit-card',
                'color' => '#dc2626',
                'bg'    => '#fef2f2',
            ];
        }
        if ( $pass_exp && $pass_exp >= $today && $pass_exp <= wp_date( 'Y-m-d', strtotime( '+90 days' ) ) ) {
            $timeline[] = [
                'date'  => $pass_exp,
                'label' => __( 'Passport expires', 'sfs-hr' ),
                'icon'  => 'book-open',
                'color' => '#dc2626',
                'bg'    => '#fef2f2',
            ];
        }

        // Sort timeline by date.
        usort( $timeline, fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) );
        $timeline = array_slice( $timeline, 0, 5 );

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
            'documents'   => $missing_docs_count === 0,
        ];
        $profile_completed      = array_filter( $profile_fields );
        $profile_completion_pct = (int) round( ( count( $profile_completed ) / count( $profile_fields ) ) * 100 );

        // Build tab URLs.
        $base_url       = remove_query_arg( [ 'sfs_hr_tab', 'leave_err', 'leave_msg' ] );
        $leave_url      = add_query_arg( 'sfs_hr_tab', 'leave', $base_url );
        $loans_url      = add_query_arg( 'sfs_hr_tab', 'loans', $base_url );
        $attendance_url = add_query_arg( 'sfs_hr_tab', 'attendance', $base_url );
        $profile_url    = add_query_arg( 'sfs_hr_tab', 'profile', $base_url );
        $documents_url  = add_query_arg( 'sfs_hr_tab', 'documents', $base_url );

        // ─── Render ───────────────────────────────────────────────
        echo '<div class="sfs-overview">';

        // ── 1. Unified Hero Card ─────────────────────────────────
        $today_display = wp_date( 'l, j M Y' );
        $name_ar       = trim( $first_name_ar . ' ' . ( $emp['last_name_ar'] ?? '' ) ) ?: $full_name;

        echo '<div class="sfs-overview-hero">';
        echo '<div class="sfs-overview-hero-top">';

        // Avatar (64px) — clickable to profile tab
        echo '<a href="' . esc_url( $profile_url ) . '" class="sfs-overview-hero-avatar" style="text-decoration:none;">';
        if ( $photo_id ) {
            echo wp_get_attachment_image(
                $photo_id,
                [ 64, 64 ],
                false,
                [
                    'class' => 'sfs-overview-avatar-img',
                    'style' => 'border-radius:50%;display:block;object-fit:cover;width:64px;height:64px;cursor:pointer;',
                ]
            );
        } else {
            $initials = strtoupper( mb_substr( $first_name, 0, 1 ) . mb_substr( $last_name, 0, 1 ) );
            echo '<div class="sfs-overview-avatar-placeholder" style="cursor:pointer;">' . esc_html( $initials ) . '</div>';
        }
        echo '</a>';

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

        // Script to fetch current attendance status
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

        // ── Sick leave reminder (uncovered absences) ─────────────
        \SFS\HR\Frontend\SickLeaveReminder::render( $emp_id, get_current_user_id(), $leave_url );

        // ── 2. Action Required ──────────────────────────────────
        if ( ! empty( $action_items ) ) {
            echo '<div class="sfs-overview-block">';
            echo '<div class="sfs-overview-section">';
            echo '<h3 class="sfs-overview-section-title" data-i18n-key="action_required">' . esc_html__( 'Action Required', 'sfs-hr' ) . '</h3>';
            echo '</div>';
            echo '<div class="sfs-overview-activity-list">';
            foreach ( $action_items as $item ) {
                $tab_url = add_query_arg( 'sfs_hr_tab', $item['tab'], $base_url );
                echo '<a href="' . esc_url( $tab_url ) . '" class="sfs-overview-activity-item">';
                echo '<div class="sfs-overview-activity-icon" style="background:' . esc_attr( $item['bg'] ) . ';">';
                echo $this->icon_svg( $item['icon'], $item['color'] );
                echo '</div>';
                echo '<div class="sfs-overview-activity-info">';
                echo '<div class="sfs-overview-activity-title" data-i18n-key="' . esc_attr( $item['i18n'] ) . '">' . esc_html( $item['text'] ) . '</div>';
                echo '</div>';
                echo '<svg class="sfs-overview-activity-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
                echo '</a>';
            }
            echo '</div></div>';
        }

        // ── 3. Today's Shift ────────────────────────────────────
        if ( $today_shift ) {
            $shift_name  = esc_html( $today_shift->name ?? __( 'Shift', 'sfs-hr' ) );
            $shift_start = esc_html( $today_shift->start_time ?? '--:--' );
            $shift_end   = esc_html( $today_shift->end_time ?? '--:--' );

            echo '<div class="sfs-overview-block">';
            echo '<div class="sfs-overview-section">';
            echo '<h3 class="sfs-overview-section-title" data-i18n-key="todays_shift">' . esc_html__( "Today's Shift", 'sfs-hr' ) . '</h3>';
            echo '</div>';
            echo '<div class="sfs-overview-shift-card">';
            echo '<div class="sfs-overview-shift-icon">';
            echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
            echo '</div>';
            echo '<div class="sfs-overview-shift-info">';
            echo '<div class="sfs-overview-shift-name">' . $shift_name . '</div>';
            echo '<div class="sfs-overview-shift-time">' . $shift_start . ' — ' . $shift_end . '</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        // ── 4. Monthly Attendance Summary ─────────────────────────
        $month_num = (int) wp_date( 'n' );
        $year_num  = (int) wp_date( 'Y' );
        echo '<div class="sfs-overview-block">';
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
        echo '</div>'; // .sfs-overview-block

        // ── 5. Leave Summary KPIs ─────────────────────────────────
        echo '<div class="sfs-overview-block">';
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
        echo '</div>'; // .sfs-overview-block

        // ── 6. Pending & Upcoming Requests ───────────────────────
        $has_pending  = ! empty( $pending_leaves ) || ! empty( $pending_loans );
        $has_upcoming = ! empty( $upcoming_leaves ) || ! empty( $active_loans );

        if ( $has_pending || $has_upcoming ) {
            echo '<div class="sfs-overview-block">';
            echo '<div class="sfs-overview-section">';
            echo '<h3 class="sfs-overview-section-title" data-i18n-key="pending_upcoming">' . esc_html__( 'Pending & Upcoming', 'sfs-hr' ) . '</h3>';
            echo '</div>';

            echo '<div class="sfs-overview-activity-list">';

            // Pending leave requests.
            foreach ( $pending_leaves as $req ) {
                $type_name = $req->type_name ?: __( 'Leave', 'sfs-hr' );
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
                echo $this->status_badge( 'pending' );
                echo '</a>';
            }

            // Upcoming approved leaves.
            foreach ( $upcoming_leaves as $req ) {
                $type_name = $req->type_name ?: __( 'Leave', 'sfs-hr' );
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
                echo $this->status_badge( 'approved' );
                echo '</a>';
            }

            // Pending loan requests.
            foreach ( $pending_loans as $loan ) {
                $amount = isset( $loan->principal_amount ) ? number_format_i18n( (float) $loan->principal_amount, 2 ) : '0';
                echo '<a href="' . esc_url( $loans_url ) . '" class="sfs-overview-activity-item">';
                echo '<div class="sfs-overview-activity-icon sfs-overview-activity-icon--loan">';
                echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
                echo '</div>';
                echo '<div class="sfs-overview-activity-info">';
                echo '<div class="sfs-overview-activity-title" data-i18n-key="loan">' . esc_html__( 'Loan', 'sfs-hr' ) . '</div>';
                echo '<div class="sfs-overview-activity-meta">' . esc_html( $amount ) . '</div>';
                echo '</div>';
                echo $this->status_badge( 'pending' );
                echo '</a>';
            }

            // Active loans (in repayment).
            foreach ( $active_loans as $loan ) {
                $amount = isset( $loan->principal_amount ) ? number_format_i18n( (float) $loan->principal_amount, 2 ) : '0';
                echo '<a href="' . esc_url( $loans_url ) . '" class="sfs-overview-activity-item">';
                echo '<div class="sfs-overview-activity-icon sfs-overview-activity-icon--loan">';
                echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
                echo '</div>';
                echo '<div class="sfs-overview-activity-info">';
                echo '<div class="sfs-overview-activity-title" data-i18n-key="loan">' . esc_html__( 'Loan', 'sfs-hr' ) . '</div>';
                echo '<div class="sfs-overview-activity-meta">' . esc_html( $amount ) . '</div>';
                echo '</div>';
                echo $this->status_badge( 'active' );
                echo '</a>';
            }

            echo '</div>'; // .sfs-overview-activity-list
            echo '</div>'; // .sfs-overview-block
        }

        // ── 7. Upcoming Timeline ─────────────────────────────────
        if ( ! empty( $timeline ) ) {
            echo '<div class="sfs-overview-block">';
            echo '<div class="sfs-overview-section">';
            echo '<h3 class="sfs-overview-section-title" data-i18n-key="upcoming_timeline">' . esc_html__( 'Upcoming', 'sfs-hr' ) . '</h3>';
            echo '</div>';
            echo '<div class="sfs-overview-timeline">';
            foreach ( $timeline as $i => $event ) {
                $is_last = ( $i === count( $timeline ) - 1 );
                echo '<div class="sfs-overview-timeline-item' . ( $is_last ? ' sfs-overview-timeline-item--last' : '' ) . '">';
                echo '<div class="sfs-overview-timeline-dot" style="background:' . esc_attr( $event['bg'] ) . ';border-color:' . esc_attr( $event['color'] ) . ';">';
                echo '<div class="sfs-overview-timeline-dot-inner" style="background:' . esc_attr( $event['color'] ) . ';"></div>';
                echo '</div>';
                echo '<div class="sfs-overview-timeline-content">';
                echo '<span class="sfs-overview-timeline-label">' . esc_html( $event['label'] ) . '</span>';
                echo '<span class="sfs-overview-timeline-date">' . esc_html( $event['date'] ) . '</span>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div></div>';
        }

        // ── 9. Profile Completion Banner ──────────────────────────
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

        // ── 10. Floating "+ Request" button ──────────────────────
        echo '<div class="sfs-fab-container" id="sfs-fab-request">';
        echo '<button class="sfs-fab-btn" type="button" aria-label="' . esc_attr__( 'New Request', 'sfs-hr' ) . '" aria-expanded="false" onclick="this.parentElement.classList.toggle(\'sfs-fab-open\');this.setAttribute(\'aria-expanded\',this.parentElement.classList.contains(\'sfs-fab-open\'))">';
        echo '<svg class="sfs-fab-icon-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
        echo '<span class="sfs-fab-label" data-i18n-key="new_request">' . esc_html__( 'New Request', 'sfs-hr' ) . '</span>';
        echo '</button>';

        echo '<div class="sfs-fab-menu">';
        // Leave request
        echo '<a href="' . esc_url( $leave_url ) . '" class="sfs-fab-menu-item">';
        echo '<div class="sfs-fab-menu-icon" style="background:#eff6ff;"><svg viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>';
        echo '<span data-i18n-key="request_leave">' . esc_html__( 'Request Leave', 'sfs-hr' ) . '</span>';
        echo '</a>';
        // Loan request
        echo '<a href="' . esc_url( $loans_url ) . '" class="sfs-fab-menu-item">';
        echo '<div class="sfs-fab-menu-icon" style="background:#ecfdf5;"><svg viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>';
        echo '<span data-i18n-key="request_loan">' . esc_html__( 'Request Loan', 'sfs-hr' ) . '</span>';
        echo '</a>';
        echo '</div>'; // .sfs-fab-menu
        echo '</div>'; // .sfs-fab-container

        // Backdrop overlay to close FAB menu on outside click.
        echo '<div class="sfs-fab-backdrop" onclick="document.getElementById(\'sfs-fab-request\').classList.remove(\'sfs-fab-open\');"></div>';

        echo '</div>'; // .sfs-overview
    }

    /**
     * Render a status badge.
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
        $info = $map[ $status ] ?? [ 'label' => ucfirst( $status ), 'class' => 'sfs-badge--muted' ];
        return '<span class="sfs-badge ' . esc_attr( $info['class'] ) . '" data-i18n-key="' . esc_attr( $status ) . '">' . esc_html( $info['label'] ) . '</span>';
    }

    /**
     * Get a small inline SVG icon by name.
     */
    private function icon_svg( string $name, string $color = 'currentColor' ): string {
        $icons = [
            'package'        => '<svg viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
            'file-warning'   => '<svg viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="12" x2="12" y2="16"/><circle cx="12" cy="19" r="0.5"/></svg>',
            'alert-triangle' => '<svg viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            'calendar'       => '<svg viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'file-text'      => '<svg viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
            'credit-card'    => '<svg viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
            'book-open'      => '<svg viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
        ];
        return $icons[ $name ] ?? '';
    }
}
