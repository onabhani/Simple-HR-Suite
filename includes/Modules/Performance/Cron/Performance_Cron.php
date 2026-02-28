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

        $subject = sprintf( __( '[Weekly Performance Digest] %s', 'sfs-hr' ), $period_label );

        // --- Send to GM ---
        $gm_user_id = (int) get_option( 'sfs_hr_leave_gm_approver', 0 );
        $gm_email   = '';
        if ( $gm_user_id > 0 ) {
            $gm_user = get_user_by( 'id', $gm_user_id );
            if ( $gm_user && $gm_user->user_email ) {
                $gm_email = $gm_user->user_email;
                $body = $this->build_report_table( $all_data, $period_label, __( 'Weekly Performance Digest', 'sfs-hr' ), $active_alerts );
                Helpers::send_mail( $gm_email, $subject, $body );
            }
        }

        // --- Send to HR ---
        $notif_settings = Notifications::get_settings();
        $hr_emails      = $notif_settings['hr_emails'] ?? [];
        if ( ! empty( $hr_emails ) ) {
            $hr_body = $this->build_report_table( $all_data, $period_label, __( 'Weekly Performance Digest', 'sfs-hr' ), $active_alerts );
            foreach ( $hr_emails as $hr_email ) {
                if ( is_email( $hr_email ) && $hr_email !== $gm_email ) {
                    Helpers::send_mail( $hr_email, $subject, $hr_body );
                }
            }
        }

        // --- Send to each department manager & HR responsible (their dept only) ---
        $departments = $wpdb->get_results(
            "SELECT id, name, manager_user_id, hr_responsible_user_id FROM {$dept_t} WHERE active = 1"
        );

        $sent_emails = array_merge( [ $gm_email ], $hr_emails );

        foreach ( $departments as $dept ) {
            if ( empty( $by_dept[ (int) $dept->id ] ) ) {
                continue;
            }

            $dept_subject = sprintf( __( '[Weekly Performance Digest] %s – %s', 'sfs-hr' ), $dept->name, $period_label );
            $title = sprintf( __( 'Weekly Performance Digest – %s', 'sfs-hr' ), $dept->name );
            $body  = $this->build_report_table( $by_dept[ (int) $dept->id ], $period_label, $title );

            // Department manager
            $mgr_uid = (int) $dept->manager_user_id;
            if ( $mgr_uid > 0 ) {
                $mgr_user = get_user_by( 'id', $mgr_uid );
                if ( $mgr_user && $mgr_user->user_email && ! in_array( $mgr_user->user_email, $sent_emails, true ) ) {
                    Helpers::send_mail( $mgr_user->user_email, $dept_subject, $body );
                }
            }

            // HR responsible
            $hr_resp_uid = (int) $dept->hr_responsible_user_id;
            if ( $hr_resp_uid > 0 && $hr_resp_uid !== $mgr_uid ) {
                $hr_resp_user = get_user_by( 'id', $hr_resp_uid );
                if ( $hr_resp_user && $hr_resp_user->user_email && ! in_array( $hr_resp_user->user_email, $sent_emails, true ) ) {
                    Helpers::send_mail( $hr_resp_user->user_email, $dept_subject, $body );
                }
            }
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

        // --- B) Send to each Department Manager & HR Responsible ---
        // Collect company-level recipients to avoid duplicates at department level.
        $sent_emails = [];
        $gm_email_lc = ( $gm_user_id > 0 && isset( $gm_user ) && $gm_user && $gm_user->user_email )
            ? strtolower( trim( $gm_user->user_email ) ) : '';
        if ( $gm_email_lc !== '' ) {
            $sent_emails[ $gm_email_lc ] = true;
        }
        foreach ( ( $hr_emails ?? [] ) as $hr_e ) {
            if ( is_email( $hr_e ) ) {
                $sent_emails[ strtolower( trim( $hr_e ) ) ] = true;
            }
        }

        $departments = $wpdb->get_results(
            "SELECT id, name, manager_user_id, hr_responsible_user_id FROM {$dept_t} WHERE active = 1"
        );

        foreach ( $departments as $dept ) {
            if ( empty( $by_dept[ (int) $dept->id ] ) ) {
                continue;
            }

            $title = sprintf( __( '%s Department Performance Report', 'sfs-hr' ), $dept->name );
            $body  = $this->build_report_table( $by_dept[ (int) $dept->id ], $period_label, $title );
            $dept_subject = sprintf( __( '[Performance Report] %s – %s', 'sfs-hr' ), $dept->name, $period_label );

            // Department manager
            $mgr_uid = (int) $dept->manager_user_id;
            if ( $mgr_uid > 0 ) {
                $mgr_user = get_user_by( 'id', $mgr_uid );
                if ( $mgr_user && $mgr_user->user_email ) {
                    $mgr_email_lc = strtolower( trim( $mgr_user->user_email ) );
                    if ( empty( $sent_emails[ $mgr_email_lc ] ) ) {
                        Helpers::send_mail( $mgr_user->user_email, $dept_subject, $body );
                        $sent_emails[ $mgr_email_lc ] = true;
                    }
                }
            }

            // HR responsible
            $hr_resp_uid = (int) $dept->hr_responsible_user_id;
            if ( $hr_resp_uid > 0 && $hr_resp_uid !== $mgr_uid ) {
                $hr_resp_user = get_user_by( 'id', $hr_resp_uid );
                if ( $hr_resp_user && $hr_resp_user->user_email ) {
                    $hr_resp_email_lc = strtolower( trim( $hr_resp_user->user_email ) );
                    if ( empty( $sent_emails[ $hr_resp_email_lc ] ) ) {
                        Helpers::send_mail( $hr_resp_user->user_email, $dept_subject, $body );
                        $sent_emails[ $hr_resp_email_lc ] = true;
                    }
                }
            }
        }

        // --- C) Send to each employee ---
        $excellent_threshold = (float) ( $settings['attendance_thresholds']['excellent'] ?? 95 );
        $good_threshold      = (float) ( $settings['attendance_thresholds']['good'] ?? 85 );

        foreach ( $all_data as $entry ) {
            if ( empty( $entry['email'] ) ) {
                continue;
            }

            $commitment = (float) $entry['commitment'];

            if ( $commitment >= $excellent_threshold ) {
                $subject = sprintf( __( 'Great Job! Your Performance Report – %s', 'sfs-hr' ), $period_label );
            } else {
                $subject = sprintf( __( 'Your Performance Report – %s', 'sfs-hr' ), $period_label );
            }

            $body = $this->build_employee_report_email( $entry, $period_label, $commitment, $excellent_threshold, $good_threshold );

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
    private function build_report_table( array $data, string $period_label, string $title, array $alerts = [] ): string {
        $site_name = get_bloginfo( 'name' );

        // Sort by commitment ascending (lowest first)
        usort( $data, fn( $a, $b ) => $a['commitment'] <=> $b['commitment'] );

        $th = 'padding:8px 6px;text-align:left;border-bottom:2px solid #ddd;font-size:12px;color:#50575e;white-space:nowrap;';
        $td = 'padding:8px 6px;border-bottom:1px solid #eee;font-size:13px;color:#1d2327;';
        $num = $td . 'text-align:center;';

        ob_start();
        ?>
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;max-width:700px;margin:0 auto;">
            <h2 style="color:#1d2327;border-bottom:1px solid #ddd;padding-bottom:10px;"><?php echo esc_html( $title ); ?></h2>
            <p style="color:#50575e;font-size:14px;margin:4px 0;">
                <?php printf( esc_html__( 'Period: %s', 'sfs-hr' ), '<strong>' . esc_html( $period_label ) . '</strong>' ); ?>
            </p>
            <p style="color:#50575e;font-size:14px;margin:4px 0 16px;">
                <?php printf( esc_html__( 'Total employees: %d', 'sfs-hr' ), count( $data ) ); ?>
            </p>
            <table style="width:100%;border-collapse:collapse;margin:0 0 20px;">
                <thead>
                    <tr style="background:#f0f0f1;">
                        <th style="<?php echo $th; ?>"><?php esc_html_e( 'Code', 'sfs-hr' ); ?></th>
                        <th style="<?php echo $th; ?>"><?php esc_html_e( 'Name', 'sfs-hr' ); ?></th>
                        <th style="<?php echo $th; ?>"><?php esc_html_e( 'Dept', 'sfs-hr' ); ?></th>
                        <th style="<?php echo $th; ?>text-align:center;"><?php esc_html_e( 'Commit.', 'sfs-hr' ); ?></th>
                        <th style="<?php echo $th; ?>text-align:center;"><?php esc_html_e( 'Late', 'sfs-hr' ); ?></th>
                        <th style="<?php echo $th; ?>text-align:center;"><?php esc_html_e( 'Early', 'sfs-hr' ); ?></th>
                        <th style="<?php echo $th; ?>text-align:center;"><?php esc_html_e( 'Absent', 'sfs-hr' ); ?></th>
                        <th style="<?php echo $th; ?>text-align:center;"><?php esc_html_e( 'Incomp', 'sfs-hr' ); ?></th>
                        <th style="<?php echo $th; ?>text-align:center;"><?php esc_html_e( 'Brk Dly', 'sfs-hr' ); ?></th>
                        <th style="<?php echo $th; ?>text-align:center;"><?php esc_html_e( 'No Brk', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $data as $i => $row ) :
                        $bg = $i % 2 ? '#f9f9f9' : '#fff';
                        $c  = (float) $row['commitment'];
                        if ( $c < 70 ) {
                            $c_color = '#d63638'; $c_bg = '#fce4e4';
                        } elseif ( $c < 85 ) {
                            $c_color = '#996800'; $c_bg = '#fef8ee';
                        } else {
                            $c_color = '#00a32a'; $c_bg = '#edfaef';
                        }
                    ?>
                    <tr style="background:<?php echo $bg; ?>;">
                        <td style="<?php echo $td; ?>font-size:12px;"><?php echo esc_html( $row['employee_code'] ); ?></td>
                        <td style="<?php echo $td; ?>"><?php echo esc_html( $row['name'] ); ?></td>
                        <td style="<?php echo $td; ?>font-size:12px;"><?php echo esc_html( $row['dept_name'] ); ?></td>
                        <td style="<?php echo $num; ?>font-weight:700;color:<?php echo $c_color; ?>;background:<?php echo $c_bg; ?>;border-radius:3px;"><?php echo esc_html( number_format( $c, 1 ) ); ?>%</td>
                        <td style="<?php echo $num; ?><?php echo $row['late'] > 0 ? 'color:#996800;font-weight:600;' : ''; ?>"><?php echo (int) $row['late']; ?></td>
                        <td style="<?php echo $num; ?><?php echo $row['early'] > 0 ? 'color:#996800;font-weight:600;' : ''; ?>"><?php echo (int) $row['early']; ?></td>
                        <td style="<?php echo $num; ?><?php echo $row['absent'] > 0 ? 'color:#d63638;font-weight:600;' : ''; ?>"><?php echo (int) $row['absent']; ?></td>
                        <td style="<?php echo $num; ?>"><?php echo (int) $row['incomplete']; ?></td>
                        <td style="<?php echo $num; ?>"><?php echo (int) ( $row['break_delay'] ?? 0 ); ?></td>
                        <td style="<?php echo $num; ?>"><?php echo (int) ( $row['no_break'] ?? 0 ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ( ! empty( $alerts ) ) : ?>
            <div style="margin:20px 0;padding:16px;background:#fef8ee;border-left:4px solid #dba617;border-radius:3px;">
                <h3 style="margin:0 0 10px;font-size:15px;color:#1d2327;"><?php esc_html_e( 'Active Alerts', 'sfs-hr' ); ?> <span style="color:#787c82;font-weight:400;">(<?php echo count( $alerts ); ?>)</span></h3>
                <table style="width:100%;border-collapse:collapse;">
                    <?php foreach ( $alerts as $a ) :
                        $sev = strtolower( $a->severity ?? 'info' );
                        if ( $sev === 'critical' ) { $badge_bg = '#d63638'; $badge_c = '#fff'; }
                        elseif ( $sev === 'warning' ) { $badge_bg = '#dba617'; $badge_c = '#fff'; }
                        else { $badge_bg = '#2271b1'; $badge_c = '#fff'; }
                    ?>
                    <tr>
                        <td style="padding:4px 0;font-size:13px;color:#1d2327;vertical-align:top;">
                            <span style="display:inline-block;background:<?php echo $badge_bg; ?>;color:<?php echo $badge_c; ?>;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;text-transform:uppercase;"><?php echo esc_html( $a->severity ); ?></span>
                            <?php echo esc_html( $a->employee_name ); ?> (<?php echo esc_html( $a->employee_code ); ?>) – <?php echo esc_html( $a->title ); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
            <p style="color:#787c82;font-size:12px;margin-top:30px;padding-top:20px;border-top:1px solid #ddd;">
                <?php echo esc_html( $site_name ); ?> · <?php esc_html_e( 'HR Management System', 'sfs-hr' ); ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Build specific improvement hints based on the employee's metrics.
     *
     * @param array $entry Employee metrics entry.
     * @return string Bullet-point hints for areas that need improvement.
     */
    private static function build_improvement_hints( array $entry ): string {
        $hints = self::build_improvement_hints_array( $entry );

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
     * Build styled HTML email for an individual employee's monthly report.
     */
    private function build_employee_report_email( array $entry, string $period_label, float $commitment, float $excellent_threshold, float $good_threshold ): string {
        $site_name = get_bloginfo( 'name' );

        // Determine tier
        if ( $commitment >= $excellent_threshold ) {
            $tier        = 'excellent';
            $accent      = '#00a32a';
            $accent_bg   = '#edfaef';
            $badge_label = __( 'Excellent', 'sfs-hr' );
        } elseif ( $commitment >= $good_threshold ) {
            $tier        = 'good';
            $accent      = '#0d6efd';
            $accent_bg   = '#e8f0fe';
            $badge_label = __( 'Good', 'sfs-hr' );
        } elseif ( $commitment >= 70 ) {
            $tier        = 'fair';
            $accent      = '#996800';
            $accent_bg   = '#fef8ee';
            $badge_label = __( 'Needs Improvement', 'sfs-hr' );
        } else {
            $tier        = 'poor';
            $accent      = '#d63638';
            $accent_bg   = '#fce4e4';
            $badge_label = __( 'Below Threshold', 'sfs-hr' );
        }

        // Metrics rows
        $metrics = [
            [ __( 'Working Days', 'sfs-hr' ),  $entry['working_days'], false ],
            [ __( 'Days Present', 'sfs-hr' ),  $entry['present'],      false ],
            [ __( 'Days Absent', 'sfs-hr' ),   $entry['absent'],       $entry['absent'] > 0 ],
            [ __( 'Late Arrivals', 'sfs-hr' ), $entry['late'],         $entry['late'] > 0 ],
            [ __( 'Early Leaves', 'sfs-hr' ),  $entry['early'],        $entry['early'] > 0 ],
            [ __( 'Incomplete Days', 'sfs-hr' ), $entry['incomplete'],  $entry['incomplete'] > 0 ],
            [ __( 'Break Delays', 'sfs-hr' ),  $entry['break_delay'],  $entry['break_delay'] > 0 ],
            [ __( 'No Break Taken', 'sfs-hr' ), $entry['no_break'],    $entry['no_break'] > 0 ],
        ];

        // Improvement hints as array
        $hints = [];
        if ( $tier !== 'excellent' ) {
            $hints = self::build_improvement_hints_array( $entry );
        }

        ob_start();
        ?>
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;max-width:600px;margin:0 auto;background:#ffffff;">
            <!-- Header -->
            <div style="background:<?php echo $accent; ?>;padding:24px 30px;border-radius:8px 8px 0 0;">
                <h1 style="margin:0;font-size:20px;font-weight:600;color:#ffffff;">
                    <?php esc_html_e( 'Performance Report', 'sfs-hr' ); ?>
                </h1>
                <p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.85);">
                    <?php echo esc_html( $period_label ); ?>
                </p>
            </div>

            <div style="padding:24px 30px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;">
                <!-- Greeting -->
                <p style="font-size:15px;color:#1d2327;margin:0 0 6px;">
                    <?php printf( esc_html__( 'Dear %s,', 'sfs-hr' ), '<strong>' . esc_html( $entry['name'] ) . '</strong>' ); ?>
                </p>

                <?php if ( $tier === 'excellent' ) : ?>
                <p style="font-size:14px;color:#374151;line-height:1.6;margin:10px 0 20px;">
                    <?php printf(
                        esc_html__( 'Thank you for your outstanding commitment this period! Your attendance commitment is %s, which is excellent. Your dedication and discipline set a great example for the team. Keep up the fantastic work!', 'sfs-hr' ),
                        '<strong>' . esc_html( number_format( $commitment, 1 ) ) . '%</strong>'
                    ); ?>
                </p>
                <?php elseif ( $tier === 'good' ) : ?>
                <p style="font-size:14px;color:#374151;line-height:1.6;margin:10px 0 20px;">
                    <?php printf(
                        esc_html__( 'Thank you for your good commitment this period — your attendance commitment is %s. You are very close to achieving an excellent rating! A small improvement in the areas highlighted below can help you reach the top.', 'sfs-hr' ),
                        '<strong>' . esc_html( number_format( $commitment, 1 ) ) . '%</strong>'
                    ); ?>
                </p>
                <?php else : ?>
                <p style="font-size:14px;color:#374151;line-height:1.6;margin:10px 0 20px;">
                    <?php printf(
                        esc_html__( 'Please find below your attendance commitment report for this period. Your commitment is %s. We encourage you to focus on the areas highlighted below. Consistent attendance has a direct positive impact on your performance and career growth.', 'sfs-hr' ),
                        '<strong>' . esc_html( number_format( $commitment, 1 ) ) . '%</strong>'
                    ); ?>
                </p>
                <?php endif; ?>

                <!-- Commitment Score Card -->
                <div style="background:<?php echo $accent_bg; ?>;border:1px solid <?php echo $accent; ?>33;border-radius:8px;padding:16px 20px;margin:0 0 20px;text-align:center;">
                    <p style="margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:#50575e;font-weight:600;">
                        <?php esc_html_e( 'Commitment Score', 'sfs-hr' ); ?>
                    </p>
                    <p style="margin:0 0 6px;font-size:32px;font-weight:700;color:<?php echo $accent; ?>;">
                        <?php echo esc_html( number_format( $commitment, 1 ) ); ?>%
                    </p>
                    <span style="display:inline-block;background:<?php echo $accent; ?>;color:#fff;padding:3px 12px;border-radius:12px;font-size:12px;font-weight:600;">
                        <?php echo esc_html( $badge_label ); ?>
                    </span>
                </div>

                <!-- Metrics Table -->
                <h3 style="font-size:14px;color:#1d2327;margin:0 0 10px;font-weight:600;">
                    <?php esc_html_e( 'Attendance Breakdown', 'sfs-hr' ); ?>
                </h3>
                <table style="width:100%;border-collapse:collapse;margin:0 0 20px;" cellpadding="0" cellspacing="0">
                    <?php foreach ( $metrics as $i => $m ) :
                        $bg       = $i % 2 ? '#f8fafc' : '#ffffff';
                        $val_style = $m[2] ? 'color:' . ( (int) $m[1] >= 3 ? '#d63638' : '#996800' ) . ';font-weight:600;' : 'color:#1d2327;';
                    ?>
                    <tr style="background:<?php echo $bg; ?>;">
                        <td style="padding:10px 12px;font-size:13px;color:#50575e;border-bottom:1px solid #f0f0f1;">
                            <?php echo esc_html( $m[0] ); ?>
                        </td>
                        <td style="padding:10px 12px;font-size:14px;text-align:right;border-bottom:1px solid #f0f0f1;<?php echo $val_style; ?>">
                            <?php echo (int) $m[1]; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <?php if ( ! empty( $hints ) ) : ?>
                <!-- Improvement Hints -->
                <div style="background:#fef8ee;border:1px solid #f0d9a0;border-radius:8px;padding:16px 20px;margin:0 0 20px;">
                    <h3 style="font-size:14px;color:#996800;margin:0 0 10px;font-weight:600;">
                        <?php esc_html_e( 'Areas for Improvement', 'sfs-hr' ); ?>
                    </h3>
                    <?php foreach ( $hints as $hint ) : ?>
                    <p style="font-size:13px;color:#374151;line-height:1.5;margin:0 0 8px;padding-left:16px;position:relative;">
                        <span style="position:absolute;left:0;color:#996800;font-weight:bold;">&bull;</span>
                        <?php echo esc_html( $hint ); ?>
                    </p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ( $tier === 'poor' || $tier === 'fair' ) : ?>
                <p style="font-size:13px;color:#374151;line-height:1.5;margin:0 0 20px;padding:12px 16px;background:#f0f7ff;border-radius:6px;border:1px solid #bdd7f1;">
                    <?php esc_html_e( 'If you are facing any challenges, please don\'t hesitate to reach out to your manager or HR. We are here to support you.', 'sfs-hr' ); ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div style="padding:16px 30px;text-align:center;">
                <p style="margin:0;font-size:12px;color:#9ca3af;">
                    <?php echo esc_html( $site_name ); ?> &middot; <?php esc_html_e( 'HR Management System', 'sfs-hr' ); ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Build improvement hints as an array (for use in HTML email).
     */
    private static function build_improvement_hints_array( array $entry ): array {
        $hints = [];

        if ( ( $entry['absent'] ?? 0 ) > 0 ) {
            $hints[] = sprintf(
                __( 'Absences (%d days): Please ensure regular attendance. If you need a day off, submit a leave request in advance.', 'sfs-hr' ),
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

        return $hints;
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
