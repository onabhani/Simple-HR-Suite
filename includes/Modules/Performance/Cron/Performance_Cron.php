<?php
namespace SFS\HR\Modules\Performance\Cron;

use SFS\HR\Modules\Performance\Services\Alerts_Service;
use SFS\HR\Modules\Performance\Services\Attendance_Metrics;
use SFS\HR\Modules\Performance\Services\Performance_Calculator;
use SFS\HR\Modules\Performance\PerformanceModule;
use SFS\HR\Core\Helpers;
use SFS\HR\Core\Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Performance Cron
 *
 * Handles scheduled tasks:
 * - Daily alert checks
 * - Monthly snapshot generation
 * - Monthly performance reports (26th of each month)
 *
 * @version 1.1.0
 */
class Performance_Cron {

    const CRON_ALERTS = 'sfs_hr_performance_alerts_check';
    const CRON_SNAPSHOTS = 'sfs_hr_performance_snapshots';
    const CRON_REPORTS = 'sfs_hr_performance_monthly_reports';
    const CRON_WEEKLY_DIGEST = 'sfs_hr_performance_weekly_digest';

    /**
     * Register hooks.
     */
    public function hooks(): void {
        add_action( 'init', [ $this, 'schedule' ] );
        add_action( self::CRON_ALERTS, [ $this, 'run_alert_checks' ] );
        add_action( self::CRON_SNAPSHOTS, [ $this, 'run_snapshot_generation' ] );
        add_action( self::CRON_REPORTS, [ $this, 'run_monthly_reports' ] );
        add_action( self::CRON_WEEKLY_DIGEST, [ $this, 'run_weekly_digest' ] );
    }

    /**
     * Schedule cron jobs.
     */
    public function schedule(): void {
        // Daily alert check at 9 AM
        if ( ! wp_next_scheduled( self::CRON_ALERTS ) ) {
            $timestamp = $this->get_next_run_timestamp( '09:00' );
            wp_schedule_event( $timestamp, 'daily', self::CRON_ALERTS );
        }

        // Monthly snapshot on the 1st at midnight
        if ( ! wp_next_scheduled( self::CRON_SNAPSHOTS ) ) {
            $timestamp = $this->get_first_of_month_timestamp();
            wp_schedule_event( $timestamp, 'monthly', self::CRON_SNAPSHOTS );
        }

        // Monthly performance reports on the 26th at 8 AM
        if ( ! wp_next_scheduled( self::CRON_REPORTS ) ) {
            $timestamp = $this->get_26th_timestamp();
            wp_schedule_event( $timestamp, 'monthly', self::CRON_REPORTS );
        }

        // Weekly performance digest every Sunday at 8 AM
        if ( ! wp_next_scheduled( self::CRON_WEEKLY_DIGEST ) ) {
            $timestamp = $this->get_next_sunday_timestamp();
            wp_schedule_event( $timestamp, 'weekly', self::CRON_WEEKLY_DIGEST );
        }

        // Register custom intervals if not already present
        add_filter( 'cron_schedules', function( $schedules ) {
            if ( ! isset( $schedules['monthly'] ) ) {
                $schedules['monthly'] = [
                    'interval' => 30 * DAY_IN_SECONDS,
                    'display'  => __( 'Monthly', 'sfs-hr' ),
                ];
            }
            if ( ! isset( $schedules['weekly'] ) ) {
                $schedules['weekly'] = [
                    'interval' => 7 * DAY_IN_SECONDS,
                    'display'  => __( 'Weekly', 'sfs-hr' ),
                ];
            }
            return $schedules;
        } );
    }

    /**
     * Get next run timestamp for a given time.
     *
     * @param string $time H:i format
     * @return int
     */
    private function get_next_run_timestamp( string $time ): int {
        $tz = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );
        $target = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $time, $tz );

        if ( ! $target || $target <= $now ) {
            $target = $target ? $target->modify( '+1 day' ) : $now->modify( '+1 day' )->setTime( 9, 0 );
        }

        return $target->getTimestamp();
    }

    /**
     * Get timestamp for the 1st of next month.
     *
     * @return int
     */
    private function get_first_of_month_timestamp(): int {
        $tz = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );
        $first_of_next = $now->modify( 'first day of next month' )->setTime( 0, 0 );
        return $first_of_next->getTimestamp();
    }

    /**
     * Run alert checks.
     */
    public function run_alert_checks(): void {
        $settings = PerformanceModule::get_settings();

        if ( ! $settings['enabled'] || ! $settings['alerts']['enabled'] ) {
            return;
        }

        $created = 0;

        // Check attendance commitment
        $created += Alerts_Service::check_commitment_alerts();

        // Check overdue goals
        $created += Alerts_Service::check_goal_alerts();

        // Check due reviews
        $created += Alerts_Service::check_review_alerts();

        // Log
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR Performance] Alert check completed: %d alerts created/updated',
                $created
            ) );
        }

        do_action( 'sfs_hr_performance_alerts_checked', $created );
    }

    /**
     * Run monthly snapshot generation.
     */
    public function run_snapshot_generation(): void {
        global $wpdb;

        $settings = PerformanceModule::get_settings();

        if ( ! $settings['enabled'] || ! $settings['snapshots']['auto_generate'] ) {
            return;
        }

        $employees_table = $wpdb->prefix . 'sfs_hr_employees';

        // Get active employees
        $employees = $wpdb->get_col(
            "SELECT id FROM {$employees_table} WHERE status = 'active'"
        );

        // Calculate period (previous month)
        $period_start = date( 'Y-m-01', strtotime( 'first day of last month' ) );
        $period_end = date( 'Y-m-t', strtotime( 'last day of last month' ) );

        $generated = 0;

        foreach ( $employees as $employee_id ) {
            $result = Performance_Calculator::generate_snapshot( (int) $employee_id, $period_start, $period_end );
            if ( $result ) {
                $generated++;
            }
        }

        // Cleanup old snapshots
        $retention_days = $settings['snapshots']['retention_days'];
        if ( $retention_days > 0 ) {
            $cutoff_date = date( 'Y-m-d', strtotime( "-{$retention_days} days" ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}sfs_hr_performance_snapshots
                 WHERE created_at < %s",
                $cutoff_date
            ) );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR Performance] Monthly snapshots: %d generated for period %s to %s',
                $generated,
                $period_start,
                $period_end
            ) );
        }

        do_action( 'sfs_hr_performance_snapshots_generated', $generated, $period_start, $period_end );
    }

    /**
     * Get timestamp for the 26th of the current or next month at 8 AM.
     */
    private function get_26th_timestamp(): int {
        $tz  = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );
        $target = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m' ) . '-26 08:00', $tz );

        if ( ! $target || $target <= $now ) {
            $target = $now->modify( 'first day of next month' );
            $target = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $target->format( 'Y-m' ) . '-26 08:00', $tz );
        }

        return $target->getTimestamp();
    }

    /**
     * Get timestamp for next Sunday at 8 AM.
     */
    private function get_next_sunday_timestamp(): int {
        $tz  = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );

        $target = $now->modify( 'next Sunday' )->setTime( 8, 0 );

        return $target->getTimestamp();
    }

    /**
     * Run weekly performance digest for HR/admin and department managers.
     *
     * Replaces per-alert emails with a single consolidated report using the same
     * table format as the monthly GM report. Covers the current performance
     * period (26th of previous month through today).
     */
    public function run_weekly_digest(): void {
        global $wpdb;

        $settings = PerformanceModule::get_settings();

        if ( ! $settings['enabled'] ) {
            return;
        }

        $emp_t  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_t = $wpdb->prefix . 'sfs_hr_departments';

        // Current performance period: 26th of previous month → today
        $tz  = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );

        $day_of_month = (int) $now->format( 'd' );
        if ( $day_of_month >= 26 ) {
            // We're past the 26th, so current period started this month
            $start_date = $now->format( 'Y-m' ) . '-26';
        } else {
            // Before the 26th, period started last month
            $start_date = $now->modify( 'first day of last month' )->format( 'Y-m' ) . '-26';
        }
        $end_date = $now->format( 'Y-m-d' );

        $period_label = wp_date( 'M j', strtotime( $start_date ) ) . ' – ' . wp_date( 'M j, Y', strtotime( $end_date ) );

        // Collect all active employees with metrics
        $employees = $wpdb->get_results(
            "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.dept_id, e.user_id,
                    u.user_email, d.name AS dept_name
             FROM {$emp_t} e
             LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
             LEFT JOIN {$dept_t} d ON d.id = e.dept_id
             WHERE e.status = 'active'
             ORDER BY d.name, e.first_name"
        );

        if ( empty( $employees ) ) {
            return;
        }

        $all_data = [];
        $by_dept  = [];

        foreach ( $employees as $emp ) {
            $metrics = Attendance_Metrics::get_employee_metrics( (int) $emp->id, $start_date, $end_date );
            $entry = [
                'employee_id'   => (int) $emp->id,
                'employee_code' => $emp->employee_code,
                'name'          => trim( $emp->first_name . ' ' . $emp->last_name ),
                'dept_name'     => $emp->dept_name ?: __( 'General', 'sfs-hr' ),
                'dept_id'       => (int) $emp->dept_id,
                'commitment'    => $metrics['commitment_pct'] ?? 0,
                'late'          => $metrics['late_count'] ?? 0,
                'early'         => $metrics['early_leave_count'] ?? 0,
                'absent'        => $metrics['days_absent'] ?? 0,
                'incomplete'    => $metrics['incomplete_count'] ?? 0,
                'break_delay'   => $metrics['break_delay_count'] ?? 0,
                'no_break'      => $metrics['no_break_taken_count'] ?? 0,
                'working_days'  => $metrics['total_working_days'] ?? 0,
                'present'       => $metrics['days_present'] ?? 0,
            ];
            $all_data[] = $entry;
            $by_dept[ (int) $emp->dept_id ][] = $entry;
        }

        // --- Active alerts summary ---
        $alerts_table = $wpdb->prefix . 'sfs_hr_performance_alerts';
        $active_alerts = $wpdb->get_results(
            "SELECT a.alert_type, a.severity, a.title,
                    CONCAT( e.first_name, ' ', e.last_name ) AS employee_name,
                    e.employee_code
             FROM {$alerts_table} a
             JOIN {$emp_t} e ON e.id = a.employee_id
             WHERE a.status = 'active'
             ORDER BY
                CASE a.severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END,
                a.created_at DESC"
        );

        $alerts_section = '';
        if ( ! empty( $active_alerts ) ) {
            $alerts_section .= "\n\n" . __( 'Active Alerts', 'sfs-hr' ) . "\n";
            $alerts_section .= str_repeat( '=', 40 ) . "\n";
            $alerts_section .= sprintf( __( 'Total active alerts: %d', 'sfs-hr' ), count( $active_alerts ) ) . "\n\n";

            foreach ( $active_alerts as $a ) {
                $severity_tag = strtoupper( $a->severity );
                $alerts_section .= sprintf(
                    "[%s] %s (%s) – %s\n",
                    $severity_tag,
                    $a->employee_name,
                    $a->employee_code,
                    $a->title
                );
            }
        }

        $subject = sprintf( __( '[Weekly Performance Digest] %s', 'sfs-hr' ), $period_label );

        // --- Send to GM ---
        $gm_user_id = (int) get_option( 'sfs_hr_leave_gm_approver', 0 );
        $gm_email   = '';
        if ( $gm_user_id > 0 ) {
            $gm_user = get_user_by( 'id', $gm_user_id );
            if ( $gm_user && $gm_user->user_email ) {
                $gm_email = $gm_user->user_email;
                $body = $this->build_report_table( $all_data, $period_label, __( 'Weekly Performance Digest', 'sfs-hr' ) )
                       . $alerts_section;
                Helpers::send_mail( $gm_email, $subject, $body );
            }
        }

        // --- Send to HR ---
        $notif_settings = Notifications::get_settings();
        $hr_emails      = $notif_settings['hr_emails'] ?? [];
        if ( ! empty( $hr_emails ) ) {
            $hr_body = $this->build_report_table( $all_data, $period_label, __( 'Weekly Performance Digest', 'sfs-hr' ) )
                     . $alerts_section;
            foreach ( $hr_emails as $hr_email ) {
                if ( is_email( $hr_email ) && $hr_email !== $gm_email ) {
                    Helpers::send_mail( $hr_email, $subject, $hr_body );
                }
            }
        }

        // --- Send to each department manager (their dept only) ---
        $departments = $wpdb->get_results(
            "SELECT id, name, manager_user_id FROM {$dept_t} WHERE active = 1 AND manager_user_id IS NOT NULL"
        );

        foreach ( $departments as $dept ) {
            $mgr_uid = (int) $dept->manager_user_id;
            if ( $mgr_uid <= 0 || empty( $by_dept[ (int) $dept->id ] ) ) {
                continue;
            }
            $mgr_user = get_user_by( 'id', $mgr_uid );
            if ( ! $mgr_user || ! $mgr_user->user_email ) {
                continue;
            }
            // Skip if already received the full report as GM or HR
            if ( $mgr_user->user_email === $gm_email || in_array( $mgr_user->user_email, $hr_emails, true ) ) {
                continue;
            }
            $title = sprintf( __( 'Weekly Performance Digest – %s', 'sfs-hr' ), $dept->name );
            $body  = $this->build_report_table( $by_dept[ (int) $dept->id ], $period_label, $title );
            Helpers::send_mail(
                $mgr_user->user_email,
                sprintf( __( '[Weekly Performance Digest] %s – %s', 'sfs-hr' ), $dept->name, $period_label ),
                $body
            );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR Performance] Weekly digest sent: %d employees, %d active alerts, period %s to %s',
                count( $all_data ),
                count( $active_alerts ),
                $start_date,
                $end_date
            ) );
        }

        do_action( 'sfs_hr_performance_weekly_digest_sent', count( $all_data ), $start_date, $end_date );
    }

    /**
     * Run monthly performance reports (triggered on the 26th).
     *
     * Sends:
     * A) Full company report to GM
     * B) Department report to each department manager
     * C) Individual report to each employee (with thank-you if >= 95%)
     */
    public function run_monthly_reports(): void {
        global $wpdb;

        $emp_t  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_t = $wpdb->prefix . 'sfs_hr_departments';

        // Period: 26th of previous month → 25th of current month
        $tz  = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );
        $end_date   = $now->modify( '-1 day' )->format( 'Y-m-d' ); // 25th
        $start_date = $now->modify( '-1 month' )->format( 'Y-m-d' ); // 26th of last month

        // Collect all active employees with metrics
        $employees = $wpdb->get_results(
            "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.dept_id, e.user_id,
                    u.user_email, d.name AS dept_name
             FROM {$emp_t} e
             LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
             LEFT JOIN {$dept_t} d ON d.id = e.dept_id
             WHERE e.status = 'active'
             ORDER BY d.name, e.first_name"
        );

        if ( empty( $employees ) ) {
            return;
        }

        // Build metrics for every employee
        $all_data     = []; // flat list
        $by_dept      = []; // grouped by dept_id
        $period_label = wp_date( 'M j', strtotime( $start_date ) ) . ' – ' . wp_date( 'M j, Y', strtotime( $end_date ) );

        foreach ( $employees as $emp ) {
            $metrics = Attendance_Metrics::get_employee_metrics( (int) $emp->id, $start_date, $end_date );
            $entry = [
                'employee_id'   => (int) $emp->id,
                'employee_code' => $emp->employee_code,
                'name'          => trim( $emp->first_name . ' ' . $emp->last_name ),
                'dept_name'     => $emp->dept_name ?: __( 'General', 'sfs-hr' ),
                'dept_id'       => (int) $emp->dept_id,
                'email'         => $emp->user_email ?? '',
                'user_id'       => (int) $emp->user_id,
                'commitment'    => $metrics['commitment_pct'] ?? 0,
                'late'          => $metrics['late_count'] ?? 0,
                'early'         => $metrics['early_leave_count'] ?? 0,
                'absent'        => $metrics['days_absent'] ?? 0,
                'incomplete'    => $metrics['incomplete_count'] ?? 0,
                'break_delay'   => $metrics['break_delay_count'] ?? 0,
                'no_break'      => $metrics['no_break_taken_count'] ?? 0,
                'working_days'  => $metrics['total_working_days'] ?? 0,
                'present'       => $metrics['days_present'] ?? 0,
            ];
            $all_data[] = $entry;
            $by_dept[ (int) $emp->dept_id ][] = $entry;
        }

        // --- A) Send to GM ---
        $gm_user_id = (int) get_option( 'sfs_hr_leave_gm_approver', 0 );
        if ( $gm_user_id > 0 ) {
            $gm_user = get_user_by( 'id', $gm_user_id );
            if ( $gm_user && $gm_user->user_email ) {
                $body = $this->build_report_table( $all_data, $period_label, __( 'Company Performance Report', 'sfs-hr' ) );
                Helpers::send_mail(
                    $gm_user->user_email,
                    sprintf( __( '[Performance Report] %s', 'sfs-hr' ), $period_label ),
                    $body
                );
            }
        }

        // --- A2) Send to HR ---
        $notif_settings = Notifications::get_settings();
        $hr_emails      = $notif_settings['hr_emails'] ?? [];
        if ( ! empty( $hr_emails ) ) {
            $hr_body = $this->build_report_table( $all_data, $period_label, __( 'Company Performance Report', 'sfs-hr' ) );
            $gm_email_str = ( $gm_user_id > 0 && isset( $gm_user ) && $gm_user ) ? $gm_user->user_email : '';
            foreach ( $hr_emails as $hr_email ) {
                if ( is_email( $hr_email ) && $hr_email !== $gm_email_str ) {
                    Helpers::send_mail(
                        $hr_email,
                        sprintf( __( '[Performance Report] %s', 'sfs-hr' ), $period_label ),
                        $hr_body
                    );
                }
            }
        }

        // --- B) Send to each Department Manager ---
        $departments = $wpdb->get_results(
            "SELECT id, name, manager_user_id FROM {$dept_t} WHERE active = 1 AND manager_user_id IS NOT NULL"
        );

        foreach ( $departments as $dept ) {
            $mgr_uid = (int) $dept->manager_user_id;
            if ( $mgr_uid <= 0 || empty( $by_dept[ (int) $dept->id ] ) ) {
                continue;
            }
            $mgr_user = get_user_by( 'id', $mgr_uid );
            if ( ! $mgr_user || ! $mgr_user->user_email ) {
                continue;
            }
            $title = sprintf( __( '%s Department Performance Report', 'sfs-hr' ), $dept->name );
            $body  = $this->build_report_table( $by_dept[ (int) $dept->id ], $period_label, $title );
            Helpers::send_mail(
                $mgr_user->user_email,
                sprintf( __( '[Performance Report] %s – %s', 'sfs-hr' ), $dept->name, $period_label ),
                $body
            );
        }

        // --- C) Send to each employee ---
        $excellent_threshold = (float) ( $settings['attendance_thresholds']['excellent'] ?? 95 );
        $good_threshold      = (float) ( $settings['attendance_thresholds']['good'] ?? 85 );

        foreach ( $all_data as $entry ) {
            if ( empty( $entry['email'] ) ) {
                continue;
            }

            $commitment = (float) $entry['commitment'];

            // Build the metrics block (shared across all tiers)
            $metrics_block = sprintf( __( "Period: %s\n", 'sfs-hr' ), $period_label )
                           . sprintf( __( "Commitment: %.1f%%\n", 'sfs-hr' ), $commitment )
                           . sprintf( __( "Working Days: %d\n", 'sfs-hr' ), $entry['working_days'] )
                           . sprintf( __( "Days Present: %d\n", 'sfs-hr' ), $entry['present'] )
                           . sprintf( __( "Days Absent: %d\n", 'sfs-hr' ), $entry['absent'] )
                           . sprintf( __( "Late Arrivals: %d\n", 'sfs-hr' ), $entry['late'] )
                           . sprintf( __( "Early Leaves: %d\n", 'sfs-hr' ), $entry['early'] )
                           . sprintf( __( "Incomplete Days: %d\n", 'sfs-hr' ), $entry['incomplete'] )
                           . sprintf( __( "Break Delays: %d\n", 'sfs-hr' ), $entry['break_delay'] )
                           . sprintf( __( "No Break Taken: %d\n", 'sfs-hr' ), $entry['no_break'] );

            if ( $commitment >= $excellent_threshold ) {
                // --- Excellent performers: thank-you email ---
                $subject = sprintf( __( 'Great Job! Your Performance Report – %s', 'sfs-hr' ), $period_label );
                $greeting = sprintf(
                    __( "Dear %s,\n\nThank you for your outstanding commitment this period! Your attendance commitment is %.1f%%, which is excellent.\n\nYour dedication and discipline set a great example for the team. Keep up the fantastic work — we truly appreciate your efforts.\n", 'sfs-hr' ),
                    $entry['name'],
                    $commitment
                );
            } elseif ( $commitment >= $good_threshold ) {
                // --- Good performers: positive encouragement ---
                $subject = sprintf( __( 'Your Performance Report – %s', 'sfs-hr' ), $period_label );
                $greeting = sprintf(
                    __( "Dear %s,\n\nThank you for your good commitment this period — your attendance commitment is %.1f%%.\n\nYou are very close to achieving an excellent rating! A small improvement in the areas below can help you reach the top. We believe you can do it.\n", 'sfs-hr' ),
                    $entry['name'],
                    $commitment
                );
                $greeting .= self::build_improvement_hints( $entry );
            } else {
                // --- Needs improvement: encouraging + specific guidance ---
                $subject = sprintf( __( 'Your Performance Report – %s', 'sfs-hr' ), $period_label );
                $greeting = sprintf(
                    __( "Dear %s,\n\nPlease find below your attendance commitment report for this period. Your commitment is %.1f%%.\n\nWe would like to encourage you to focus on improving the following areas. Every day counts, and consistent attendance has a direct positive impact on your performance record and career growth.\n", 'sfs-hr' ),
                    $entry['name'],
                    $commitment
                );
                $greeting .= self::build_improvement_hints( $entry );
                $greeting .= __( "\nIf you are facing any challenges, please don't hesitate to reach out to your manager or HR. We are here to support you.\n", 'sfs-hr' );
            }

            $body = $greeting . "\n"
                  . $metrics_block
                  . "\n---\n" . get_bloginfo( 'name' ) . "\n" . __( 'HR Management System', 'sfs-hr' ) . "\n";

            Helpers::send_mail( $entry['email'], $subject, $body );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR Performance] Monthly reports sent: %d employees, period %s to %s',
                count( $all_data ),
                $start_date,
                $end_date
            ) );
        }

        do_action( 'sfs_hr_performance_reports_sent', count( $all_data ), $start_date, $end_date );
    }

    /**
     * Build a plain-text table for the performance report email.
     */
    private function build_report_table( array $data, string $period_label, string $title ): string {
        $site_name = get_bloginfo( 'name' );

        $lines   = [];
        $lines[] = $title;
        $lines[] = str_repeat( '=', strlen( $title ) );
        $lines[] = sprintf( __( 'Period: %s', 'sfs-hr' ), $period_label );
        $lines[] = sprintf( __( 'Total employees: %d', 'sfs-hr' ), count( $data ) );
        $lines[] = '';
        $lines[] = sprintf(
            '%-6s  %-25s  %-15s  %10s  %5s  %5s  %6s  %6s  %6s  %7s',
            __( 'Code', 'sfs-hr' ),
            __( 'Name', 'sfs-hr' ),
            __( 'Department', 'sfs-hr' ),
            __( 'Commitment', 'sfs-hr' ),
            __( 'Late', 'sfs-hr' ),
            __( 'Early', 'sfs-hr' ),
            __( 'Absent', 'sfs-hr' ),
            __( 'Incomp', 'sfs-hr' ),
            __( 'BrkDly', 'sfs-hr' ),
            __( 'NoBrk', 'sfs-hr' )
        );
        $lines[] = str_repeat( '-', 108 );

        // Sort by commitment ascending (lowest first)
        usort( $data, fn( $a, $b ) => $a['commitment'] <=> $b['commitment'] );

        foreach ( $data as $row ) {
            $lines[] = sprintf(
                '%-6s  %-25s  %-15s  %9.1f%%  %5d  %5d  %6d  %6d  %6d  %7d',
                $row['employee_code'],
                mb_substr( $row['name'], 0, 25 ),
                mb_substr( $row['dept_name'], 0, 15 ),
                $row['commitment'],
                $row['late'],
                $row['early'],
                $row['absent'],
                $row['incomplete'],
                $row['break_delay'] ?? 0,
                $row['no_break'] ?? 0
            );
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = $site_name;
        $lines[] = 'HR Management System';

        return implode( "\n", $lines );
    }

    /**
     * Build specific improvement hints based on the employee's metrics.
     *
     * @param array $entry Employee metrics entry.
     * @return string Bullet-point hints for areas that need improvement.
     */
    private static function build_improvement_hints( array $entry ): string {
        $hints = [];

        if ( ( $entry['absent'] ?? 0 ) > 0 ) {
            $hints[] = sprintf(
                __( 'Absences (%d days): Please ensure regular attendance. If you need to take a day off, submit a leave request in advance.', 'sfs-hr' ),
                $entry['absent']
            );
        }

        if ( ( $entry['late'] ?? 0 ) > 0 ) {
            $hints[] = sprintf(
                __( 'Late arrivals (%d times): Try to arrive on time for your scheduled shift. Punctuality directly impacts your commitment score.', 'sfs-hr' ),
                $entry['late']
            );
        }

        if ( ( $entry['early'] ?? 0 ) > 0 ) {
            $hints[] = sprintf(
                __( 'Early leaves (%d times): Please complete your full shift hours before leaving.', 'sfs-hr' ),
                $entry['early']
            );
        }

        if ( ( $entry['incomplete'] ?? 0 ) > 0 ) {
            $hints[] = sprintf(
                __( 'Incomplete days (%d): Ensure you clock both in and out to avoid incomplete records.', 'sfs-hr' ),
                $entry['incomplete']
            );
        }

        if ( ( $entry['break_delay'] ?? 0 ) > 0 ) {
            $hints[] = sprintf(
                __( 'Break delays (%d times): Please return from breaks on time.', 'sfs-hr' ),
                $entry['break_delay']
            );
        }

        if ( ( $entry['no_break'] ?? 0 ) > 0 ) {
            $hints[] = sprintf(
                __( 'Missed breaks (%d times): Remember to record your break — unrecorded breaks are automatically deducted.', 'sfs-hr' ),
                $entry['no_break']
            );
        }

        if ( empty( $hints ) ) {
            return '';
        }

        $output = "\n" . __( 'Areas for improvement:', 'sfs-hr' ) . "\n";
        foreach ( $hints as $hint ) {
            $output .= '  • ' . $hint . "\n";
        }

        return $output;
    }

    /**
     * Manually trigger alert checks.
     *
     * @return int Number of alerts created
     */
    public static function trigger_alerts(): int {
        $instance = new self();
        ob_start();
        $instance->run_alert_checks();
        ob_end_clean();

        return Alerts_Service::get_statistics()['total_active'];
    }

    /**
     * Manually trigger snapshot generation.
     *
     * @param string $period_start
     * @param string $period_end
     * @return int Number of snapshots generated
     */
    public static function trigger_snapshots( string $period_start = '', string $period_end = '' ): int {
        global $wpdb;

        if ( empty( $period_start ) ) {
            $period_start = date( 'Y-m-01', strtotime( 'first day of last month' ) );
        }
        if ( empty( $period_end ) ) {
            $period_end = date( 'Y-m-t', strtotime( 'last day of last month' ) );
        }

        $employees_table = $wpdb->prefix . 'sfs_hr_employees';
        $employees = $wpdb->get_col(
            "SELECT id FROM {$employees_table} WHERE status = 'active'"
        );

        $generated = 0;

        foreach ( $employees as $employee_id ) {
            $result = Performance_Calculator::generate_snapshot( (int) $employee_id, $period_start, $period_end );
            if ( $result ) {
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * Get cron status.
     *
     * @return array
     */
    public static function get_status(): array {
        $alerts_next    = wp_next_scheduled( self::CRON_ALERTS );
        $snapshots_next = wp_next_scheduled( self::CRON_SNAPSHOTS );
        $reports_next   = wp_next_scheduled( self::CRON_REPORTS );
        $digest_next    = wp_next_scheduled( self::CRON_WEEKLY_DIGEST );

        return [
            'alerts' => [
                'scheduled' => (bool) $alerts_next,
                'next_run'  => $alerts_next ? wp_date( 'Y-m-d H:i:s', $alerts_next ) : null,
            ],
            'snapshots' => [
                'scheduled' => (bool) $snapshots_next,
                'next_run'  => $snapshots_next ? wp_date( 'Y-m-d H:i:s', $snapshots_next ) : null,
            ],
            'reports' => [
                'scheduled' => (bool) $reports_next,
                'next_run'  => $reports_next ? wp_date( 'Y-m-d H:i:s', $reports_next ) : null,
            ],
            'weekly_digest' => [
                'scheduled' => (bool) $digest_next,
                'next_run'  => $digest_next ? wp_date( 'Y-m-d H:i:s', $digest_next ) : null,
            ],
        ];
    }
}
