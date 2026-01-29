<?php
namespace SFS\HR\Modules\Performance\Services;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Alerts Service
 *
 * Manages performance alerts including:
 * - Creating alerts for low commitment/performance
 * - Sending notifications to managers/HR
 * - Alert acknowledgment and resolution
 *
 * @version 1.0.0
 */
class Alerts_Service {

    /**
     * Alert types.
     */
    const TYPE_LOW_COMMITMENT     = 'low_commitment';
    const TYPE_EXCESSIVE_LATE     = 'excessive_late';
    const TYPE_EXCESSIVE_ABSENCE  = 'excessive_absence';
    const TYPE_GOAL_OVERDUE       = 'goal_overdue';
    const TYPE_REVIEW_DUE         = 'review_due';
    const TYPE_LOW_PERFORMANCE    = 'low_performance';

    /**
     * Create an alert.
     *
     * @param array $data Alert data
     * @return int Alert ID
     */
    public static function create_alert( array $data ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_alerts';

        $alert_data = [
            'employee_id'     => (int) $data['employee_id'],
            'alert_type'      => sanitize_text_field( $data['alert_type'] ),
            'severity'        => in_array( $data['severity'] ?? '', [ 'info', 'warning', 'critical' ], true )
                ? $data['severity'] : 'warning',
            'title'           => sanitize_text_field( $data['title'] ),
            'message'         => sanitize_textarea_field( $data['message'] ?? '' ),
            'metric_value'    => isset( $data['metric_value'] ) ? (float) $data['metric_value'] : null,
            'threshold_value' => isset( $data['threshold_value'] ) ? (float) $data['threshold_value'] : null,
            'status'          => 'active',
            'meta_json'       => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
            'created_at'      => current_time( 'mysql' ),
        ];

        // Check for duplicate active alert
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE employee_id = %d
               AND alert_type = %s
               AND status = 'active'
             LIMIT 1",
            $alert_data['employee_id'],
            $alert_data['alert_type']
        ) );

        if ( $existing ) {
            // Update existing alert
            $wpdb->update( $table, $alert_data, [ 'id' => $existing ] );
            return (int) $existing;
        }

        $wpdb->insert( $table, $alert_data );
        $alert_id = (int) $wpdb->insert_id;

        // Send notifications
        self::send_alert_notifications( $alert_id );

        return $alert_id;
    }

    /**
     * Get alerts for an employee.
     *
     * @param int    $employee_id
     * @param string $status Filter by status (empty for all)
     * @return array
     */
    public static function get_employee_alerts( int $employee_id, string $status = '' ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_alerts';

        $where = [ $wpdb->prepare( "employee_id = %d", $employee_id ) ];

        if ( ! empty( $status ) ) {
            $where[] = $wpdb->prepare( "status = %s", $status );
        }

        $where_sql = implode( ' AND ', $where );

        return $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE {$where_sql}
             ORDER BY
                CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END,
                created_at DESC"
        );
    }

    /**
     * Get all active alerts.
     *
     * @param int $dept_id Filter by department (0 for all)
     * @return array
     */
    public static function get_active_alerts( int $dept_id = 0 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_alerts';
        $employees_table = $wpdb->prefix . 'sfs_hr_employees';

        $join_where = "";
        if ( $dept_id > 0 ) {
            $join_where = $wpdb->prepare( "AND e.dept_id = %d", $dept_id );
        }

        return $wpdb->get_results(
            "SELECT a.*,
                    e.first_name, e.last_name, e.employee_code, e.dept_id
             FROM {$table} a
             JOIN {$employees_table} e ON e.id = a.employee_id
             WHERE a.status = 'active' {$join_where}
             ORDER BY
                CASE a.severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END,
                a.created_at DESC"
        );
    }

    /**
     * Acknowledge an alert.
     *
     * @param int $alert_id
     * @param int $user_id
     * @return bool
     */
    public static function acknowledge_alert( int $alert_id, int $user_id ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_alerts';

        return (bool) $wpdb->update(
            $table,
            [
                'status'          => 'acknowledged',
                'acknowledged_by' => $user_id,
                'acknowledged_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $alert_id ]
        );
    }

    /**
     * Resolve an alert.
     *
     * @param int $alert_id
     * @return bool
     */
    public static function resolve_alert( int $alert_id ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_alerts';

        return (bool) $wpdb->update(
            $table,
            [
                'status'      => 'resolved',
                'resolved_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $alert_id ]
        );
    }

    /**
     * Check and create alerts for low attendance commitment.
     *
     * @return int Number of alerts created
     */
    public static function check_commitment_alerts(): int {
        global $wpdb;

        $settings = \SFS\HR\Modules\Performance\PerformanceModule::get_settings();

        if ( ! $settings['alerts']['enabled'] ) {
            return 0;
        }

        $threshold = (float) $settings['alerts']['commitment_threshold'];
        $employees_table = $wpdb->prefix . 'sfs_hr_employees';

        // Get active employees
        $employees = $wpdb->get_results(
            "SELECT id, first_name, last_name, employee_code, dept_id
             FROM {$employees_table}
             WHERE status = 'active'"
        );

        $alerts_created = 0;
        $start_date = date( 'Y-m-01' ); // Current month
        $end_date = date( 'Y-m-d' );

        foreach ( $employees as $emp ) {
            $metrics = Attendance_Metrics::get_employee_metrics( $emp->id, $start_date, $end_date );

            if ( isset( $metrics['error'] ) || $metrics['total_working_days'] < 5 ) {
                continue; // Skip if no data or too few days
            }

            $commitment = $metrics['commitment_pct'];

            // Check for low commitment
            if ( $commitment < $threshold ) {
                $severity = $commitment < 60 ? 'critical' : 'warning';

                self::create_alert( [
                    'employee_id'     => $emp->id,
                    'alert_type'      => self::TYPE_LOW_COMMITMENT,
                    'severity'        => $severity,
                    'title'           => sprintf(
                        __( 'Low Attendance Commitment: %s%%', 'sfs-hr' ),
                        number_format( $commitment, 1 )
                    ),
                    'message'         => sprintf(
                        __( '%s has attendance commitment of %s%% (below %s%% threshold). Late: %d, Early Leave: %d, Absent: %d days.', 'sfs-hr' ),
                        trim( $emp->first_name . ' ' . $emp->last_name ),
                        number_format( $commitment, 1 ),
                        $threshold,
                        $metrics['late_count'],
                        $metrics['early_leave_count'],
                        $metrics['days_absent']
                    ),
                    'metric_value'    => $commitment,
                    'threshold_value' => $threshold,
                    'meta'            => [
                        'period_start' => $start_date,
                        'period_end'   => $end_date,
                        'late_count'   => $metrics['late_count'],
                        'early_leave_count' => $metrics['early_leave_count'],
                        'absent_days'  => $metrics['days_absent'],
                    ],
                ] );

                $alerts_created++;
            } else {
                // Auto-resolve if above threshold
                self::auto_resolve_alert( $emp->id, self::TYPE_LOW_COMMITMENT );
            }

            // Check for excessive lateness (more than 5 times in a month)
            if ( $metrics['late_count'] >= 5 ) {
                self::create_alert( [
                    'employee_id'     => $emp->id,
                    'alert_type'      => self::TYPE_EXCESSIVE_LATE,
                    'severity'        => $metrics['late_count'] >= 10 ? 'critical' : 'warning',
                    'title'           => sprintf(
                        __( 'Excessive Lateness: %d times', 'sfs-hr' ),
                        $metrics['late_count']
                    ),
                    'message'         => sprintf(
                        __( '%s was late %d times this month.', 'sfs-hr' ),
                        trim( $emp->first_name . ' ' . $emp->last_name ),
                        $metrics['late_count']
                    ),
                    'metric_value'    => $metrics['late_count'],
                    'threshold_value' => 5,
                ] );

                $alerts_created++;
            }

            // Check for excessive absence (more than 3 days in a month)
            if ( $metrics['days_absent'] >= 3 ) {
                self::create_alert( [
                    'employee_id'     => $emp->id,
                    'alert_type'      => self::TYPE_EXCESSIVE_ABSENCE,
                    'severity'        => $metrics['days_absent'] >= 5 ? 'critical' : 'warning',
                    'title'           => sprintf(
                        __( 'Excessive Absence: %d days', 'sfs-hr' ),
                        $metrics['days_absent']
                    ),
                    'message'         => sprintf(
                        __( '%s was absent for %d days this month.', 'sfs-hr' ),
                        trim( $emp->first_name . ' ' . $emp->last_name ),
                        $metrics['days_absent']
                    ),
                    'metric_value'    => $metrics['days_absent'],
                    'threshold_value' => 3,
                ] );

                $alerts_created++;
            }
        }

        return $alerts_created;
    }

    /**
     * Check for overdue goals and create alerts.
     *
     * @return int Number of alerts created
     */
    public static function check_goal_alerts(): int {
        $overdue_goals = Goals_Service::get_overdue_goals();
        $alerts_created = 0;

        foreach ( $overdue_goals as $goal ) {
            $days_overdue = (int) ( ( strtotime( 'today' ) - strtotime( $goal->target_date ) ) / 86400 );

            self::create_alert( [
                'employee_id'  => $goal->employee_id,
                'alert_type'   => self::TYPE_GOAL_OVERDUE,
                'severity'     => $days_overdue > 14 ? 'critical' : 'warning',
                'title'        => sprintf(
                    __( 'Goal Overdue: %s', 'sfs-hr' ),
                    $goal->title
                ),
                'message'      => sprintf(
                    __( 'Goal "%s" is %d days overdue. Progress: %d%%.', 'sfs-hr' ),
                    $goal->title,
                    $days_overdue,
                    $goal->progress
                ),
                'metric_value' => $days_overdue,
                'meta'         => [
                    'goal_id'     => $goal->id,
                    'target_date' => $goal->target_date,
                    'progress'    => $goal->progress,
                ],
            ] );

            $alerts_created++;
        }

        return $alerts_created;
    }

    /**
     * Check for due reviews and create alerts.
     *
     * @return int Number of alerts created
     */
    public static function check_review_alerts(): int {
        $due_reviews = Reviews_Service::get_due_reviews( 7 );
        $alerts_created = 0;

        foreach ( $due_reviews as $review ) {
            $days_until = (int) ( ( strtotime( $review->due_date ) - strtotime( 'today' ) ) / 86400 );

            self::create_alert( [
                'employee_id'  => $review->employee_id,
                'alert_type'   => self::TYPE_REVIEW_DUE,
                'severity'     => $days_until <= 0 ? 'critical' : 'warning',
                'title'        => sprintf(
                    __( 'Performance Review Due: %s', 'sfs-hr' ),
                    trim( $review->first_name . ' ' . $review->last_name )
                ),
                'message'      => $days_until <= 0
                    ? sprintf( __( 'Review for %s is overdue!', 'sfs-hr' ), trim( $review->first_name . ' ' . $review->last_name ) )
                    : sprintf( __( 'Review for %s is due in %d days.', 'sfs-hr' ), trim( $review->first_name . ' ' . $review->last_name ), $days_until ),
                'metric_value' => $days_until,
                'meta'         => [
                    'review_id' => $review->id,
                    'due_date'  => $review->due_date,
                    'reviewer_id' => $review->reviewer_id,
                ],
            ] );

            $alerts_created++;
        }

        return $alerts_created;
    }

    /**
     * Auto-resolve an alert if condition is no longer met.
     *
     * @param int    $employee_id
     * @param string $alert_type
     * @return bool
     */
    public static function auto_resolve_alert( int $employee_id, string $alert_type ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_alerts';

        return (bool) $wpdb->update(
            $table,
            [
                'status'      => 'resolved',
                'resolved_at' => current_time( 'mysql' ),
            ],
            [
                'employee_id' => $employee_id,
                'alert_type'  => $alert_type,
                'status'      => 'active',
            ]
        );
    }

    /**
     * Send notifications for an alert.
     *
     * @param int $alert_id
     */
    public static function send_alert_notifications( int $alert_id ): void {
        global $wpdb;

        $settings = \SFS\HR\Modules\Performance\PerformanceModule::get_settings();

        if ( ! $settings['alerts']['enabled'] ) {
            return;
        }

        $table = $wpdb->prefix . 'sfs_hr_performance_alerts';
        $employees_table = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $alert = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, e.first_name, e.last_name, e.email, e.dept_id,
                    d.manager_user_id, d.name as dept_name
             FROM {$table} a
             JOIN {$employees_table} e ON e.id = a.employee_id
             LEFT JOIN {$dept_table} d ON d.id = e.dept_id
             WHERE a.id = %d",
            $alert_id
        ) );

        if ( ! $alert ) {
            return;
        }

        $recipients = [];

        // Notify manager
        if ( $settings['alerts']['notify_manager'] && $alert->manager_user_id ) {
            $manager = get_user_by( 'ID', $alert->manager_user_id );
            if ( $manager && $manager->user_email ) {
                $recipients[] = $manager->user_email;
            }
        }

        // Notify HR
        if ( $settings['alerts']['notify_hr'] ) {
            $hr_users = get_users( [
                'role__in'   => [ 'administrator', 'sfs_hr_manager' ],
                'capability' => 'sfs_hr.manage',
            ] );
            foreach ( $hr_users as $user ) {
                if ( $user->user_email && ! in_array( $user->user_email, $recipients, true ) ) {
                    $recipients[] = $user->user_email;
                }
            }
        }

        // Notify employee
        if ( $settings['alerts']['notify_employee'] && $alert->email ) {
            if ( ! in_array( $alert->email, $recipients, true ) ) {
                $recipients[] = $alert->email;
            }
        }

        if ( empty( $recipients ) ) {
            return;
        }

        // Build email
        $employee_name = trim( $alert->first_name . ' ' . $alert->last_name );
        $severity_label = ucfirst( $alert->severity );

        $subject = sprintf(
            __( '[%s] Performance Alert: %s', 'sfs-hr' ),
            $severity_label,
            $alert->title
        );

        $message = self::build_alert_email( $alert, $employee_name );

        // Send emails
        foreach ( $recipients as $email ) {
            if ( class_exists( 'SFS\\HR\\Core\\Helpers' ) && method_exists( Helpers::class, 'send_mail' ) ) {
                Helpers::send_mail( $email, $subject, $message );
            } else {
                wp_mail( $email, $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
            }
        }
    }

    /**
     * Build alert email HTML.
     *
     * @param object $alert
     * @param string $employee_name
     * @return string
     */
    private static function build_alert_email( object $alert, string $employee_name ): string {
        $severity_colors = [
            'info'     => '#3b82f6',
            'warning'  => '#f59e0b',
            'critical' => '#ef4444',
        ];
        $color = $severity_colors[ $alert->severity ] ?? '#6b7280';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: <?php echo esc_attr( $color ); ?>; color: white; padding: 20px; border-radius: 8px 8px 0 0;">
                <h2 style="margin: 0; font-size: 18px;">
                    <?php echo esc_html( ucfirst( $alert->severity ) ); ?> Alert
                </h2>
            </div>

            <div style="background: #f9fafb; padding: 20px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px;">
                <h3 style="margin: 0 0 15px; color: #111827;">
                    <?php echo esc_html( $alert->title ); ?>
                </h3>

                <p style="margin: 0 0 15px;">
                    <strong><?php esc_html_e( 'Employee:', 'sfs-hr' ); ?></strong>
                    <?php echo esc_html( $employee_name ); ?>
                </p>

                <p style="margin: 0 0 15px;">
                    <?php echo esc_html( $alert->message ); ?>
                </p>

                <?php if ( $alert->metric_value !== null && $alert->threshold_value !== null ) : ?>
                <p style="margin: 0 0 15px; padding: 10px; background: white; border-radius: 4px;">
                    <strong><?php esc_html_e( 'Current Value:', 'sfs-hr' ); ?></strong>
                    <?php echo esc_html( number_format( $alert->metric_value, 1 ) ); ?>
                    <br>
                    <strong><?php esc_html_e( 'Threshold:', 'sfs-hr' ); ?></strong>
                    <?php echo esc_html( number_format( $alert->threshold_value, 1 ) ); ?>
                </p>
                <?php endif; ?>

                <p style="margin: 20px 0 0; font-size: 12px; color: #6b7280;">
                    <?php echo esc_html( sprintf(
                        __( 'Alert generated on %s', 'sfs-hr' ),
                        wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $alert->created_at ) )
                    ) ); ?>
                </p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Get alert statistics.
     *
     * @return array
     */
    public static function get_statistics(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_performance_alerts';

        $stats = [
            'total_active'   => 0,
            'by_severity'    => [ 'info' => 0, 'warning' => 0, 'critical' => 0 ],
            'by_type'        => [],
            'recent_alerts'  => [],
        ];

        // Count by severity
        $severity_counts = $wpdb->get_results(
            "SELECT severity, COUNT(*) as count
             FROM {$table}
             WHERE status = 'active'
             GROUP BY severity"
        );

        foreach ( $severity_counts as $row ) {
            $stats['by_severity'][ $row->severity ] = (int) $row->count;
            $stats['total_active'] += (int) $row->count;
        }

        // Count by type
        $type_counts = $wpdb->get_results(
            "SELECT alert_type, COUNT(*) as count
             FROM {$table}
             WHERE status = 'active'
             GROUP BY alert_type"
        );

        foreach ( $type_counts as $row ) {
            $stats['by_type'][ $row->alert_type ] = (int) $row->count;
        }

        // Recent alerts
        $stats['recent_alerts'] = $wpdb->get_results(
            "SELECT a.*, e.first_name, e.last_name, e.employee_code
             FROM {$table} a
             JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = a.employee_id
             WHERE a.status = 'active'
             ORDER BY a.created_at DESC
             LIMIT 10"
        );

        return $stats;
    }
}
