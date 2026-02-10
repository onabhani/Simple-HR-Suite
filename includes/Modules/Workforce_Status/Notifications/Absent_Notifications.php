<?php
namespace SFS\HR\Modules\Workforce_Status\Notifications;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Attendance\AttendanceModule;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Absent Notifications
 * Handles sending notifications to department managers about absent employees.
 *
 * @version 1.0.0
 */
class Absent_Notifications {

    /**
     * Get all employees who were absent on a given date, grouped by department.
     *
     * @param string $date Date in Y-m-d format.
     * @return array Array of departments with absent employees.
     */
    public static function get_absent_employees_by_department( string $date ): array {
        global $wpdb;

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $leave_table = $wpdb->prefix . 'sfs_hr_leave_requests';
        $punch_table = $wpdb->prefix . 'sfs_hr_attendance_punches';

        // Get all active employees with their departments
        $employees = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.email, e.dept_id,
                    e.user_id, e.phone,
                    COALESCE( u.user_email, e.email ) AS wp_email,
                    d.name as dept_name, d.manager_user_id
             FROM {$emp_table} e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$dept_table} d ON e.dept_id = d.id
             WHERE e.status = 'active'
               AND e.dept_id IS NOT NULL
               AND d.manager_user_id IS NOT NULL
               AND d.active = 1",
            []
        ), ARRAY_A );

        if ( empty( $employees ) ) {
            return [];
        }

        // Check if date is a holiday
        if ( self::is_holiday( $date ) ) {
            return [];
        }

        // Get employees who clocked in on this date
        $tz = wp_timezone();
        $day_start = new \DateTimeImmutable( $date . ' 00:00:00', $tz );
        $day_end = $day_start->modify( '+1 day' );
        $start_utc = $day_start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $end_utc = $day_end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

        $emp_ids = array_map( 'intval', wp_list_pluck( $employees, 'id' ) );
        $placeholders = implode( ',', array_fill( 0, count( $emp_ids ), '%d' ) );

        // Get employees who clocked in
        $clocked_in = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT employee_id
             FROM {$punch_table}
             WHERE employee_id IN ({$placeholders})
               AND punch_time >= %s
               AND punch_time < %s
               AND punch_type = 'in'",
            array_merge( $emp_ids, [ $start_utc, $end_utc ] )
        ) );
        $clocked_in_map = array_flip( $clocked_in );

        // Get employees on leave
        $on_leave = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT employee_id
             FROM {$leave_table}
             WHERE employee_id IN ({$placeholders})
               AND status = 'approved'
               AND start_date <= %s
               AND end_date >= %s",
            array_merge( $emp_ids, [ $date, $date ] )
        ) );
        $on_leave_map = array_flip( $on_leave );

        // Build absent employees list by department
        $absent_by_dept = [];

        foreach ( $employees as $emp ) {
            $emp_id = (int) $emp['id'];

            // Skip if clocked in
            if ( isset( $clocked_in_map[ $emp_id ] ) ) {
                continue;
            }

            // Skip if on leave
            if ( isset( $on_leave_map[ $emp_id ] ) ) {
                continue;
            }

            // Check shift-specific day off
            if ( self::is_employee_day_off( $emp_id, $date ) ) {
                continue;
            }

            // Employee is absent
            $dept_id = (int) $emp['dept_id'];
            if ( ! isset( $absent_by_dept[ $dept_id ] ) ) {
                $absent_by_dept[ $dept_id ] = [
                    'dept_id'         => $dept_id,
                    'dept_name'       => $emp['dept_name'],
                    'manager_user_id' => (int) $emp['manager_user_id'],
                    'employees'       => [],
                ];
            }

            $absent_by_dept[ $dept_id ]['employees'][] = [
                'id'            => $emp_id,
                'employee_code' => $emp['employee_code'],
                'name'          => trim( $emp['first_name'] . ' ' . $emp['last_name'] ),
                'email'         => $emp['email'],
                'wp_email'      => $emp['wp_email'] ?? $emp['email'],
                'user_id'       => (int) ( $emp['user_id'] ?? 0 ),
                'phone'         => $emp['phone'] ?? '',
            ];
        }

        return array_values( $absent_by_dept );
    }

    /**
     * Check if a date is a holiday.
     *
     * @param string $ymd Date in Y-m-d format.
     * @return bool True if holiday.
     */
    private static function is_holiday( string $ymd ): bool {
        $holidays = get_option( 'sfs_hr_holidays', [] );
        if ( ! is_array( $holidays ) ) {
            return false;
        }

        $year = (int) substr( $ymd, 0, 4 );

        foreach ( $holidays as $h ) {
            $s = isset( $h['start'] ) ? $h['start'] : ( isset( $h['start_date'] ) ? $h['start_date'] : '' );
            $e = isset( $h['end'] ) ? $h['end'] : ( isset( $h['end_date'] ) ? $h['end_date'] : $s );

            if ( empty( $s ) ) {
                continue;
            }

            if ( ! empty( $h['repeat'] ) ) {
                $sm = substr( $s, 5 );
                $em = substr( $e, 5 );
                $rs = $year . '-' . $sm;
                $re = ( $em >= $sm ) ? ( $year . '-' . $em ) : ( ( $year + 1 ) . '-' . $em );

                if ( $ymd >= $rs && $ymd <= $re ) {
                    return true;
                }
            } else {
                if ( $ymd >= $s && $ymd <= $e ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if it's the employee's day off based on their shift.
     *
     * @param int    $emp_id Employee ID.
     * @param string $ymd    Date in Y-m-d format.
     * @return bool True if day off.
     */
    private static function is_employee_day_off( int $emp_id, string $ymd ): bool {
        // Check global weekly off days first (e.g., Friday = 5)
        $tz   = wp_timezone();
        $date = new \DateTimeImmutable( $ymd . ' 00:00:00', $tz );
        $dow  = (int) $date->format( 'w' ); // 0=Sun, 1=Mon, ..., 5=Fri, 6=Sat

        $att_settings = get_option( 'sfs_hr_attendance_settings' ) ?: [];
        $off_days     = $att_settings['weekly_off_days'] ?? [ 5 ]; // Default: Friday
        if ( ! is_array( $off_days ) ) {
            $off_days = [ 5 ];
        }
        $off_days = array_map( 'intval', $off_days );

        if ( in_array( $dow, $off_days, true ) ) {
            return true;
        }

        // Use AttendanceModule if available — null shift means day off
        if ( class_exists( '\SFS\HR\Modules\Attendance\AttendanceModule' ) ) {
            $shift = AttendanceModule::resolve_shift_for_date( $emp_id, $ymd );
            return $shift === null;
        }

        return false;
    }

    /**
     * Send absent employee notifications to department managers.
     *
     * @param string $date Date in Y-m-d format.
     * @return int Number of notifications sent.
     */
    public static function send_absent_notifications( string $date ): int {
        $absent_by_dept = self::get_absent_employees_by_department( $date );

        if ( empty( $absent_by_dept ) ) {
            return 0;
        }

        $sent_count = 0;
        $formatted_date = wp_date( 'l, F j, Y', strtotime( $date ) );

        foreach ( $absent_by_dept as $dept ) {
            $manager = get_userdata( $dept['manager_user_id'] );
            if ( ! $manager || ! $manager->user_email ) {
                continue;
            }

            $absent_count = count( $dept['employees'] );
            $subject = sprintf(
                /* translators: 1: number of employees, 2: department name, 3: date */
                __( '[Attendance] %1$d employee(s) absent in %2$s on %3$s', 'sfs-hr' ),
                $absent_count,
                $dept['dept_name'],
                $formatted_date
            );

            $message = self::build_notification_message( $dept, $date, $formatted_date, $manager );

            // Check if manager wants to receive notifications
            if ( apply_filters( 'sfs_hr_manager_wants_absent_notification', true, $manager->ID, $dept['dept_id'] ) ) {
                Helpers::send_mail( $manager->user_email, $subject, $message );
                $sent_count++;

                // Log the notification
                do_action( 'sfs_hr_absent_notification_sent', [
                    'manager_id'    => $manager->ID,
                    'manager_email' => $manager->user_email,
                    'dept_id'       => $dept['dept_id'],
                    'dept_name'     => $dept['dept_name'],
                    'date'          => $date,
                    'absent_count'  => $absent_count,
                ] );
            }
        }

        return $sent_count;
    }

    /**
     * Build the notification email message.
     *
     * @param array    $dept           Department data with absent employees.
     * @param string   $date           Date in Y-m-d format.
     * @param string   $formatted_date Human-readable date.
     * @param \WP_User $manager        Manager user object.
     * @return string HTML email content.
     */
    private static function build_notification_message( array $dept, string $date, string $formatted_date, \WP_User $manager ): string {
        $absent_count = count( $dept['employees'] );
        $site_name = get_bloginfo( 'name' );
        $workforce_url = admin_url( 'admin.php?page=sfs-hr-workforce-status&tab=absent' );

        ob_start();
        ?>
        <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif; max-width: 600px; margin: 0 auto;">
            <h2 style="color: #1d2327; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                <?php esc_html_e( 'Absent Employee Report', 'sfs-hr' ); ?>
            </h2>

            <p style="color: #50575e; font-size: 14px;">
                <?php
                printf(
                    /* translators: 1: manager name */
                    esc_html__( 'Dear %s,', 'sfs-hr' ),
                    esc_html( $manager->display_name )
                );
                ?>
            </p>

            <p style="color: #50575e; font-size: 14px;">
                <?php
                printf(
                    /* translators: 1: number of employees, 2: department name, 3: date */
                    esc_html__( 'The following %1$d employee(s) from %2$s were absent on %3$s:', 'sfs-hr' ),
                    $absent_count,
                    '<strong>' . esc_html( $dept['dept_name'] ) . '</strong>',
                    '<strong>' . esc_html( $formatted_date ) . '</strong>'
                );
                ?>
            </p>

            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <thead>
                    <tr style="background: #f0f0f1;">
                        <th style="padding: 10px; text-align: left; border: 1px solid #ddd;"><?php esc_html_e( 'Code', 'sfs-hr' ); ?></th>
                        <th style="padding: 10px; text-align: left; border: 1px solid #ddd;"><?php esc_html_e( 'Name', 'sfs-hr' ); ?></th>
                        <th style="padding: 10px; text-align: left; border: 1px solid #ddd;"><?php esc_html_e( 'Email', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $dept['employees'] as $emp ) : ?>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #ddd;"><?php echo esc_html( $emp['employee_code'] ); ?></td>
                            <td style="padding: 10px; border: 1px solid #ddd;"><?php echo esc_html( $emp['name'] ); ?></td>
                            <td style="padding: 10px; border: 1px solid #ddd;"><?php echo esc_html( $emp['email'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 20px;">
                <a href="<?php echo esc_url( $workforce_url ); ?>" style="display: inline-block; background: #2271b1; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;"><?php esc_html_e( 'View Workforce Status', 'sfs-hr' ); ?></a>
            </p>

            <p style="color: #787c82; font-size: 12px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                <?php
                printf(
                    /* translators: 1: site name */
                    esc_html__( 'This is an automated notification from %s HR System.', 'sfs-hr' ),
                    esc_html( $site_name )
                );
                ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Send absent notifications directly to the absent employees.
     *
     * Informs each absent employee that they were marked absent and reminds them
     * that they can submit a sick leave request within 3 days (with a supporting
     * medical document) to clear the absence.
     *
     * @param string $date Date in Y-m-d format.
     * @return int Number of employee notifications sent.
     */
    public static function send_employee_absent_notifications( string $date ): int {
        $settings = self::get_settings();

        if ( ! ( $settings['notify_employees'] ?? true ) ) {
            return 0;
        }

        $absent_by_dept = self::get_absent_employees_by_department( $date );

        if ( empty( $absent_by_dept ) ) {
            return 0;
        }

        $sent_count     = 0;
        $formatted_date = wp_date( 'l, F j, Y', strtotime( $date ) );
        $leave_url      = home_url( '/my-profile/?tab=leave' );
        $site_name      = get_bloginfo( 'name' );

        foreach ( $absent_by_dept as $dept ) {
            foreach ( $dept['employees'] as $emp ) {
                $email = $emp['wp_email'] ?? $emp['email'] ?? '';
                if ( ! $email || ! is_email( $email ) ) {
                    continue;
                }

                $subject = sprintf(
                    /* translators: 1: date */
                    __( '[Absence Notice] You were marked absent on %s', 'sfs-hr' ),
                    $formatted_date
                );

                $message = self::build_employee_absent_message( $emp, $date, $formatted_date, $leave_url, $site_name );

                Helpers::send_mail( $email, $subject, $message );
                $sent_count++;

                do_action( 'sfs_hr_employee_absent_notification_sent', [
                    'employee_id' => $emp['id'],
                    'email'       => $email,
                    'date'        => $date,
                ] );
            }
        }

        return $sent_count;
    }

    /**
     * Build the notification email sent to an absent employee.
     *
     * @param array  $emp            Employee data.
     * @param string $date           Date in Y-m-d format.
     * @param string $formatted_date Human-readable date.
     * @param string $leave_url      URL to the leave-request page.
     * @param string $site_name      Site name.
     * @return string HTML email content.
     */
    private static function build_employee_absent_message( array $emp, string $date, string $formatted_date, string $leave_url, string $site_name ): string {
        $deadline = wp_date( 'l, F j, Y', strtotime( $date . ' +3 days' ) );

        ob_start();
        ?>
        <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif; max-width: 600px; margin: 0 auto;">
            <h2 style="color: #1d2327; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                <?php esc_html_e( 'Absence Notice', 'sfs-hr' ); ?>
            </h2>

            <p style="color: #50575e; font-size: 14px;">
                <?php
                printf(
                    /* translators: 1: employee name */
                    esc_html__( 'Dear %s,', 'sfs-hr' ),
                    esc_html( $emp['name'] )
                );
                ?>
            </p>

            <p style="color: #50575e; font-size: 14px;">
                <?php
                printf(
                    /* translators: 1: date */
                    esc_html__( 'Our records indicate that you were absent on %s. No clock-in was recorded for your scheduled shift and no approved leave was found for that date.', 'sfs-hr' ),
                    '<strong>' . esc_html( $formatted_date ) . '</strong>'
                );
                ?>
            </p>

            <div style="background: #fef8ee; border-left: 4px solid #dba617; padding: 14px 18px; margin: 20px 0; border-radius: 3px;">
                <p style="color: #50575e; font-size: 14px; margin: 0 0 8px 0;">
                    <strong><?php esc_html_e( 'Important: You may still clear this absence', 'sfs-hr' ); ?></strong>
                </p>
                <p style="color: #50575e; font-size: 14px; margin: 0;">
                    <?php
                    printf(
                        /* translators: 1: deadline date */
                        esc_html__( 'If you were sick, you can submit a Sick Leave request for this date before %s (within 3 days of the absence). You must attach a supporting medical document. Once your sick leave is approved, the absence will be removed from your record.', 'sfs-hr' ),
                        '<strong>' . esc_html( $deadline ) . '</strong>'
                    );
                    ?>
                </p>
            </div>

            <p style="margin-top: 20px;">
                <a href="<?php echo esc_url( $leave_url ); ?>" style="display: inline-block; background: #2271b1; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;"><?php esc_html_e( 'Submit Leave Request', 'sfs-hr' ); ?></a>
            </p>

            <p style="color: #50575e; font-size: 13px; margin-top: 20px;">
                <?php esc_html_e( 'If you believe this is an error, please contact your department manager or HR.', 'sfs-hr' ); ?>
            </p>

            <p style="color: #787c82; font-size: 12px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                <?php
                printf(
                    /* translators: 1: site name */
                    esc_html__( 'This is an automated notification from %s HR System.', 'sfs-hr' ),
                    esc_html( $site_name )
                );
                ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get notification settings.
     *
     * @return array Settings array.
     */
    public static function get_settings(): array {
        $defaults = [
            'enabled'          => true,
            'send_time'        => '20:00', // 8 PM default
            'min_employees'    => 0,       // Send even for 1 absent employee
            'notify_employees' => true,    // Also notify the absent employees themselves
        ];

        $settings = get_option( 'sfs_hr_absent_notification_settings', [] );

        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Update notification settings.
     *
     * @param array $settings New settings.
     */
    public static function update_settings( array $settings ): void {
        update_option( 'sfs_hr_absent_notification_settings', $settings );
    }
}
