<?php
namespace SFS\HR\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralized Notification System
 *
 * Handles email and SMS notifications for all HR events:
 * - Leave requests (created, approved, rejected)
 * - Attendance (late arrivals, missed punches, early leaves)
 * - Employee events (birthdays, anniversaries, contract expiry)
 * - Payroll (payslip ready, salary updates)
 * - Loans (all statuses)
 */
class Notifications {

    /** Option key for notification settings */
    const OPT_SETTINGS = 'sfs_hr_notification_settings';

    /** SMS provider constants */
    const SMS_PROVIDER_NONE    = 'none';
    const SMS_PROVIDER_TWILIO  = 'twilio';
    const SMS_PROVIDER_NEXMO   = 'nexmo';
    const SMS_PROVIDER_CUSTOM  = 'custom';

    /**
     * Initialize the notification system
     */
    public static function init(): void {
        // Hook into all HR events
        self::register_hooks();
    }

    /**
     * Register all event hooks
     */
    private static function register_hooks(): void {
        // Leave events
        add_action( 'sfs_hr_leave_request_created', [ __CLASS__, 'on_leave_request_created' ], 10, 2 );
        add_action( 'sfs_hr_leave_request_status_changed', [ __CLASS__, 'on_leave_status_changed' ], 10, 3 );

        // Attendance events
        add_action( 'sfs_hr_attendance_punch', [ __CLASS__, 'on_attendance_punch' ], 10, 3 );
        add_action( 'sfs_hr_attendance_late', [ __CLASS__, 'on_attendance_late' ], 10, 2 );
        add_action( 'sfs_hr_attendance_early_leave', [ __CLASS__, 'on_early_leave' ], 10, 2 );

        // Employee events
        add_action( 'sfs_hr_employee_created', [ __CLASS__, 'on_employee_created' ], 10, 2 );
        add_action( 'sfs_hr_employee_birthday', [ __CLASS__, 'on_employee_birthday' ], 10, 1 );
        add_action( 'sfs_hr_employee_anniversary', [ __CLASS__, 'on_employee_anniversary' ], 10, 2 );
        add_action( 'sfs_hr_contract_expiring', [ __CLASS__, 'on_contract_expiring' ], 10, 2 );
        add_action( 'sfs_hr_probation_ending', [ __CLASS__, 'on_probation_ending' ], 10, 2 );

        // Payroll events
        add_action( 'sfs_hr_payroll_run_approved', [ __CLASS__, 'on_payroll_approved' ], 10, 2 );
        add_action( 'sfs_hr_payslip_ready', [ __CLASS__, 'on_payslip_ready' ], 10, 2 );

        // Daily cron for scheduled notifications
        add_action( 'sfs_hr_daily_notifications', [ __CLASS__, 'process_daily_notifications' ] );

        // Schedule daily cron if not already scheduled
        if ( ! wp_next_scheduled( 'sfs_hr_daily_notifications' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 8:00' ), 'daily', 'sfs_hr_daily_notifications' );
        }
    }

    /**
     * Get notification settings with defaults
     *
     * @return array
     */
    public static function get_settings(): array {
        $defaults = [
            // Global settings
            'enabled'                  => true,
            'email_enabled'            => true,
            'sms_enabled'              => false,
            'sms_provider'             => self::SMS_PROVIDER_NONE,

            // SMS provider settings
            'twilio_sid'               => '',
            'twilio_token'             => '',
            'twilio_from'              => '',
            'nexmo_api_key'            => '',
            'nexmo_api_secret'         => '',
            'nexmo_from'               => '',
            'custom_sms_endpoint'      => '',
            'custom_sms_api_key'       => '',

            // Notification recipients
            'hr_emails'                => [],
            'manager_notification'     => true,
            'employee_notification'    => true,
            'hr_notification'          => true,

            // Leave notifications
            'notify_leave_created'     => true,
            'notify_leave_approved'    => true,
            'notify_leave_rejected'    => true,
            'notify_leave_cancelled'   => true,

            // Attendance notifications
            'notify_late_arrival'      => true,
            'late_arrival_threshold'   => 15, // minutes
            'notify_early_leave'       => true,
            'notify_missed_punch'      => true,

            // Employee notifications
            'notify_new_employee'      => true,
            'notify_birthday'          => true,
            'birthday_days_before'     => 1,
            'notify_anniversary'       => true,
            'anniversary_days_before'  => 1,
            'notify_contract_expiry'   => true,
            'contract_expiry_days'     => [ 30, 14, 7 ],
            'notify_probation_end'     => true,
            'probation_days_before'    => 7,

            // Payroll notifications
            'notify_payslip_ready'     => true,
            'notify_payroll_processed' => true,

            // Daily pending actions reminder
            'notify_pending_actions'   => true,
        ];

        $saved = get_option( self::OPT_SETTINGS, [] );
        return wp_parse_args( $saved, $defaults );
    }

    /**
     * Save notification settings
     *
     * @param array $settings Settings to save
     */
    public static function save_settings( array $settings ): void {
        update_option( self::OPT_SETTINGS, $settings );
    }

    // =========================================================================
    // EVENT HANDLERS
    // =========================================================================

    /**
     * Handle leave request created
     *
     * @param int   $request_id Leave request ID
     * @param array $data       Request data
     */
    public static function on_leave_request_created( int $request_id, array $data ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] || ! $settings['notify_leave_created'] ) {
            return;
        }

        $leave = self::get_leave_request_data( $request_id );
        if ( ! $leave ) {
            return;
        }

        // Notify manager
        if ( $settings['manager_notification'] && $leave->manager_email ) {
            $subject = sprintf(
                __( '[Leave Request] %s has requested %s leave', 'sfs-hr' ),
                $leave->employee_name,
                $leave->leave_type_name
            );

            $message = self::get_email_template( 'leave_request_to_manager', [
                'manager_name'   => $leave->manager_name,
                'employee_name'  => $leave->employee_name,
                'employee_code'  => $leave->employee_code,
                'leave_type'     => $leave->leave_type_name,
                'start_date'     => wp_date( 'F j, Y', strtotime( $leave->start_date ) ),
                'end_date'       => wp_date( 'F j, Y', strtotime( $leave->end_date ) ),
                'days'           => $leave->days,
                'reason'         => $leave->reason ?: __( 'No reason provided', 'sfs-hr' ),
                'review_url'     => admin_url( 'admin.php?page=sfs-hr-leave&tab=pending' ),
            ] );

            // Get manager user_id for preference check
            $manager_user_id = 0;
            if ( isset( $leave->manager_user_id ) ) {
                $manager_user_id = (int) $leave->manager_user_id;
            }
            self::send_notification( $leave->manager_email, $subject, $message, $leave->manager_phone, $manager_user_id, 'leave_request_created' );
        }

        // Notify HR
        if ( $settings['hr_notification'] && ! empty( $settings['hr_emails'] ) ) {
            $subject = sprintf(
                __( '[Leave Request] New leave request from %s', 'sfs-hr' ),
                $leave->employee_name
            );

            $message = self::get_email_template( 'leave_request_to_hr', [
                'employee_name'  => $leave->employee_name,
                'employee_code'  => $leave->employee_code,
                'department'     => $leave->department_name ?: __( 'N/A', 'sfs-hr' ),
                'leave_type'     => $leave->leave_type_name,
                'start_date'     => wp_date( 'F j, Y', strtotime( $leave->start_date ) ),
                'end_date'       => wp_date( 'F j, Y', strtotime( $leave->end_date ) ),
                'days'           => $leave->days,
                'reason'         => $leave->reason ?: __( 'No reason provided', 'sfs-hr' ),
                'review_url'     => admin_url( 'admin.php?page=sfs-hr-leave&tab=pending' ),
            ] );

            foreach ( $settings['hr_emails'] as $hr_email ) {
                self::send_notification( $hr_email, $subject, $message, '', 0, 'leave_request_created' );
            }
        }
    }

    /**
     * Handle leave status change
     *
     * @param int    $request_id  Leave request ID
     * @param string $old_status  Previous status
     * @param string $new_status  New status
     */
    public static function on_leave_status_changed( int $request_id, string $old_status, string $new_status ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] ) {
            return;
        }

        $leave = self::get_leave_request_data( $request_id );
        if ( ! $leave ) {
            return;
        }

        // Only notify employee
        if ( ! $settings['employee_notification'] || ! $leave->employee_email ) {
            return;
        }

        if ( $new_status === 'approved' && $settings['notify_leave_approved'] ) {
            $subject = sprintf(
                __( '[Leave Approved] Your %s leave request has been approved', 'sfs-hr' ),
                $leave->leave_type_name
            );

            $message = self::get_email_template( 'leave_approved_to_employee', [
                'employee_name' => $leave->employee_name,
                'leave_type'    => $leave->leave_type_name,
                'start_date'    => wp_date( 'F j, Y', strtotime( $leave->start_date ) ),
                'end_date'      => wp_date( 'F j, Y', strtotime( $leave->end_date ) ),
                'days'          => $leave->days,
                'approved_by'   => wp_get_current_user()->display_name,
            ] );

            $employee_user_id = isset( $leave->employee_user_id ) ? (int) $leave->employee_user_id : 0;
            self::send_notification( $leave->employee_email, $subject, $message, $leave->employee_phone, $employee_user_id, 'leave_approved' );

        } elseif ( $new_status === 'rejected' && $settings['notify_leave_rejected'] ) {
            $subject = sprintf(
                __( '[Leave Declined] Your %s leave request has been declined', 'sfs-hr' ),
                $leave->leave_type_name
            );

            $message = self::get_email_template( 'leave_rejected_to_employee', [
                'employee_name'    => $leave->employee_name,
                'leave_type'       => $leave->leave_type_name,
                'start_date'       => wp_date( 'F j, Y', strtotime( $leave->start_date ) ),
                'end_date'         => wp_date( 'F j, Y', strtotime( $leave->end_date ) ),
                'days'             => $leave->days,
                'rejected_by'      => wp_get_current_user()->display_name,
                'rejection_reason' => $leave->rejection_reason ?: __( 'No reason provided', 'sfs-hr' ),
            ] );

            $employee_user_id = isset( $leave->employee_user_id ) ? (int) $leave->employee_user_id : 0;
            self::send_notification( $leave->employee_email, $subject, $message, $leave->employee_phone, $employee_user_id, 'leave_rejected' );

        } elseif ( $new_status === 'cancelled' && $settings['notify_leave_cancelled'] ) {
            // Notify manager about cancellation
            if ( $settings['manager_notification'] && $leave->manager_email ) {
                $subject = sprintf(
                    __( '[Leave Cancelled] %s has cancelled their leave request', 'sfs-hr' ),
                    $leave->employee_name
                );

                $message = self::get_email_template( 'leave_cancelled_to_manager', [
                    'manager_name'  => $leave->manager_name,
                    'employee_name' => $leave->employee_name,
                    'leave_type'    => $leave->leave_type_name,
                    'start_date'    => wp_date( 'F j, Y', strtotime( $leave->start_date ) ),
                    'end_date'      => wp_date( 'F j, Y', strtotime( $leave->end_date ) ),
                    'days'          => $leave->days,
                ] );

                $manager_user_id = isset( $leave->manager_user_id ) ? (int) $leave->manager_user_id : 0;
                self::send_notification( $leave->manager_email, $subject, $message, '', $manager_user_id, 'leave_cancelled' );
            }
        }
    }

    /**
     * Handle attendance punch
     *
     * @param int    $employee_id Employee ID
     * @param string $type        Punch type (in/out)
     * @param array  $data        Punch data
     */
    public static function on_attendance_punch( int $employee_id, string $type, array $data ): void {
        // This is triggered frequently, so we just log it
        // Late arrival notifications are handled separately
    }

    /**
     * Handle late arrival
     *
     * @param int   $employee_id  Employee ID
     * @param array $data         Late arrival data
     */
    public static function on_attendance_late( int $employee_id, array $data ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] || ! $settings['notify_late_arrival'] ) {
            return;
        }

        $employee = self::get_employee_data( $employee_id );
        if ( ! $employee ) {
            return;
        }

        $minutes_late = $data['minutes_late'] ?? 0;
        if ( $minutes_late < ( $settings['late_arrival_threshold'] ?? 15 ) ) {
            return;
        }

        // Notify manager
        if ( $settings['manager_notification'] && $employee->manager_email ) {
            $subject = sprintf(
                __( '[Late Arrival] %s arrived %d minutes late', 'sfs-hr' ),
                $employee->full_name,
                $minutes_late
            );

            $message = self::get_email_template( 'late_arrival_to_manager', [
                'manager_name'  => $employee->manager_name,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'date'          => wp_date( 'F j, Y' ),
                'expected_time' => $data['expected_time'] ?? 'N/A',
                'actual_time'   => $data['actual_time'] ?? 'N/A',
                'minutes_late'  => $minutes_late,
            ] );

            $manager_user_id = isset( $employee->manager_user_id ) ? (int) $employee->manager_user_id : 0;
            self::send_notification( $employee->manager_email, $subject, $message, '', $manager_user_id, 'late_arrival' );
        }
    }

    /**
     * Handle early leave request
     *
     * @param int   $employee_id Employee ID
     * @param array $data        Early leave data
     */
    public static function on_early_leave( int $employee_id, array $data ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] || ! $settings['notify_early_leave'] ) {
            return;
        }

        $employee = self::get_employee_data( $employee_id );
        if ( ! $employee ) {
            return;
        }

        // Notify manager
        if ( $settings['manager_notification'] && $employee->manager_email ) {
            $subject = sprintf(
                __( '[Early Leave] %s has requested early leave', 'sfs-hr' ),
                $employee->full_name
            );

            $message = self::get_email_template( 'early_leave_to_manager', [
                'manager_name'  => $employee->manager_name,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'date'          => wp_date( 'F j, Y' ),
                'reason'        => $data['reason'] ?? __( 'No reason provided', 'sfs-hr' ),
                'review_url'    => admin_url( 'admin.php?page=sfs_hr_attendance&tab=early-leave' ),
            ] );

            $manager_user_id = isset( $employee->manager_user_id ) ? (int) $employee->manager_user_id : 0;
            self::send_notification( $employee->manager_email, $subject, $message, '', $manager_user_id, 'early_leave' );
        }
    }

    /**
     * Handle new employee created
     *
     * @param int   $employee_id Employee ID
     * @param array $data        Employee data
     */
    public static function on_employee_created( int $employee_id, array $data ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] || ! $settings['notify_new_employee'] ) {
            return;
        }

        $employee = self::get_employee_data( $employee_id );
        if ( ! $employee ) {
            return;
        }

        // Notify HR
        if ( $settings['hr_notification'] && ! empty( $settings['hr_emails'] ) ) {
            $subject = sprintf(
                __( '[New Employee] %s has been added to the system', 'sfs-hr' ),
                $employee->full_name
            );

            $message = self::get_email_template( 'new_employee_to_hr', [
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'department'    => $employee->department_name ?: __( 'N/A', 'sfs-hr' ),
                'job_title'     => $employee->job_title ?: __( 'N/A', 'sfs-hr' ),
                'hire_date'     => $employee->hire_date ? wp_date( 'F j, Y', strtotime( $employee->hire_date ) ) : __( 'N/A', 'sfs-hr' ),
                'view_url'      => admin_url( 'admin.php?page=sfs-hr-employees&action=view&id=' . $employee_id ),
            ] );

            foreach ( $settings['hr_emails'] as $hr_email ) {
                self::send_notification( $hr_email, $subject, $message, '', 0, 'new_employee' );
            }
        }

        // Notify manager
        if ( $settings['manager_notification'] && $employee->manager_email ) {
            $subject = sprintf(
                __( '[New Team Member] %s has joined your team', 'sfs-hr' ),
                $employee->full_name
            );

            $message = self::get_email_template( 'new_employee_to_manager', [
                'manager_name'  => $employee->manager_name,
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'job_title'     => $employee->job_title ?: __( 'N/A', 'sfs-hr' ),
                'hire_date'     => $employee->hire_date ? wp_date( 'F j, Y', strtotime( $employee->hire_date ) ) : __( 'N/A', 'sfs-hr' ),
                'view_url'      => admin_url( 'admin.php?page=sfs-hr-employees&action=view&id=' . $employee_id ),
            ] );

            $manager_user_id = isset( $employee->manager_user_id ) ? (int) $employee->manager_user_id : 0;
            self::send_notification( $employee->manager_email, $subject, $message, '', $manager_user_id, 'new_employee' );
        }
    }

    /**
     * Handle employee birthday
     *
     * @param int $employee_id Employee ID
     */
    public static function on_employee_birthday( int $employee_id ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] || ! $settings['notify_birthday'] ) {
            return;
        }

        $employee = self::get_employee_data( $employee_id );
        if ( ! $employee || ! $employee->email ) {
            return;
        }

        $subject = __( '🎂 Happy Birthday from the HR Team!', 'sfs-hr' );

        $message = self::get_email_template( 'birthday_to_employee', [
            'employee_name' => $employee->full_name,
        ] );

        $employee_user_id = isset( $employee->user_id ) ? (int) $employee->user_id : 0;
        self::send_notification( $employee->email, $subject, $message, $employee->phone, $employee_user_id, 'birthday' );
    }

    /**
     * Handle employee work anniversary
     *
     * @param int $employee_id Employee ID
     * @param int $years       Years of service
     */
    public static function on_employee_anniversary( int $employee_id, int $years ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] || ! $settings['notify_anniversary'] ) {
            return;
        }

        $employee = self::get_employee_data( $employee_id );
        if ( ! $employee ) {
            return;
        }

        // Notify employee
        if ( $employee->email ) {
            $subject = sprintf(
                __( '🎉 Congratulations on %d years with us!', 'sfs-hr' ),
                $years
            );

            $message = self::get_email_template( 'anniversary_to_employee', [
                'employee_name' => $employee->full_name,
                'years'         => $years,
            ] );

            $employee_user_id = isset( $employee->user_id ) ? (int) $employee->user_id : 0;
            self::send_notification( $employee->email, $subject, $message, $employee->phone, $employee_user_id, 'anniversary' );
        }

        // Notify HR
        if ( $settings['hr_notification'] && ! empty( $settings['hr_emails'] ) ) {
            $subject = sprintf(
                __( '[Work Anniversary] %s completes %d years', 'sfs-hr' ),
                $employee->full_name,
                $years
            );

            $message = self::get_email_template( 'anniversary_to_hr', [
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'department'    => $employee->department_name ?: __( 'N/A', 'sfs-hr' ),
                'years'         => $years,
                'hire_date'     => $employee->hire_date ? wp_date( 'F j, Y', strtotime( $employee->hire_date ) ) : __( 'N/A', 'sfs-hr' ),
            ] );

            foreach ( $settings['hr_emails'] as $hr_email ) {
                self::send_notification( $hr_email, $subject, $message, '', 0, 'anniversary' );
            }
        }
    }

    /**
     * Handle contract expiring notification
     *
     * @param int $employee_id Employee ID
     * @param int $days_until  Days until expiry
     */
    public static function on_contract_expiring( int $employee_id, int $days_until ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] || ! $settings['notify_contract_expiry'] ) {
            return;
        }

        $employee = self::get_employee_data( $employee_id );
        if ( ! $employee ) {
            return;
        }

        // Notify HR
        if ( $settings['hr_notification'] && ! empty( $settings['hr_emails'] ) ) {
            $subject = sprintf(
                __( '[Contract Expiry] %s contract expires in %d days', 'sfs-hr' ),
                $employee->full_name,
                $days_until
            );

            $message = self::get_email_template( 'contract_expiry_to_hr', [
                'employee_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'department'    => $employee->department_name ?: __( 'N/A', 'sfs-hr' ),
                'days_until'    => $days_until,
                'expiry_date'   => $employee->contract_end ? wp_date( 'F j, Y', strtotime( $employee->contract_end ) ) : __( 'N/A', 'sfs-hr' ),
                'view_url'      => admin_url( 'admin.php?page=sfs-hr-employees&action=view&id=' . $employee_id ),
            ] );

            foreach ( $settings['hr_emails'] as $hr_email ) {
                self::send_notification( $hr_email, $subject, $message, '', 0, 'contract_expiry' );
            }
        }
    }

    /**
     * Handle probation ending notification
     *
     * @param int $employee_id Employee ID
     * @param int $days_until  Days until probation ends
     */
    public static function on_probation_ending( int $employee_id, int $days_until ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] || ! $settings['notify_probation_end'] ) {
            return;
        }

        $employee = self::get_employee_data( $employee_id );
        if ( ! $employee ) {
            return;
        }

        // Notify HR and Manager
        $recipients = [];
        if ( $settings['hr_notification'] && ! empty( $settings['hr_emails'] ) ) {
            $recipients = array_merge( $recipients, $settings['hr_emails'] );
        }
        if ( $settings['manager_notification'] && $employee->manager_email ) {
            $recipients[] = $employee->manager_email;
        }

        if ( empty( $recipients ) ) {
            return;
        }

        $subject = sprintf(
            __( '[Probation Review] %s probation period ends in %d days', 'sfs-hr' ),
            $employee->full_name,
            $days_until
        );

        $message = self::get_email_template( 'probation_end_notice', [
            'employee_name' => $employee->full_name,
            'employee_code' => $employee->employee_code,
            'department'    => $employee->department_name ?: __( 'N/A', 'sfs-hr' ),
            'days_until'    => $days_until,
            'hire_date'     => $employee->hire_date ? wp_date( 'F j, Y', strtotime( $employee->hire_date ) ) : __( 'N/A', 'sfs-hr' ),
            'view_url'      => admin_url( 'admin.php?page=sfs-hr-employees&action=view&id=' . $employee_id ),
        ] );

        foreach ( array_unique( $recipients ) as $email ) {
            self::send_notification( $email, $subject, $message, '', 0, 'probation_end' );
        }
    }

    /**
     * Handle payroll approved
     *
     * @param int   $run_id Payroll run ID
     * @param array $data   Payroll data
     */
    public static function on_payroll_approved( int $run_id, array $data ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] || ! $settings['notify_payroll_processed'] ) {
            return;
        }

        // Notify HR
        if ( $settings['hr_notification'] && ! empty( $settings['hr_emails'] ) ) {
            $subject = sprintf(
                __( '[Payroll] Payroll run #%d has been approved', 'sfs-hr' ),
                $run_id
            );

            $message = self::get_email_template( 'payroll_approved_to_hr', [
                'run_id'         => $run_id,
                'employee_count' => $data['employee_count'] ?? 0,
                'total_net'      => number_format( (float) ( $data['total_net'] ?? 0 ), 2 ),
                'approved_by'    => wp_get_current_user()->display_name,
                'view_url'       => admin_url( 'admin.php?page=sfs-hr-payroll' ),
            ] );

            foreach ( $settings['hr_emails'] as $hr_email ) {
                self::send_notification( $hr_email, $subject, $message, '', 0, 'payroll_approved' );
            }
        }
    }

    /**
     * Handle payslip ready
     *
     * @param int   $employee_id Employee ID
     * @param array $data        Payslip data
     */
    public static function on_payslip_ready( int $employee_id, array $data ): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] || ! $settings['notify_payslip_ready'] ) {
            return;
        }

        if ( ! $settings['employee_notification'] ) {
            return;
        }

        $employee = self::get_employee_data( $employee_id );
        if ( ! $employee || ! $employee->email ) {
            return;
        }

        $subject = sprintf(
            __( '[Payslip] Your payslip for %s is ready', 'sfs-hr' ),
            $data['period_name'] ?? __( 'this period', 'sfs-hr' )
        );

        $message = self::get_email_template( 'payslip_ready_to_employee', [
            'employee_name' => $employee->full_name,
            'period_name'   => $data['period_name'] ?? __( 'this period', 'sfs-hr' ),
            'net_salary'    => isset( $data['net_salary'] ) ? number_format( (float) $data['net_salary'], 2 ) : 'N/A',
            'view_url'      => home_url( '/my-profile/?tab=payslips' ),
        ] );

        $employee_user_id = isset( $employee->user_id ) ? (int) $employee->user_id : 0;
        self::send_notification( $employee->email, $subject, $message, $employee->phone, $employee_user_id, 'payslip_ready' );
    }

    /**
     * Process daily scheduled notifications
     * Called by WP Cron
     */
    public static function process_daily_notifications(): void {
        $settings = self::get_settings();

        if ( ! $settings['enabled'] ) {
            return;
        }

        // Process birthdays
        if ( $settings['notify_birthday'] ) {
            self::process_birthday_notifications( $settings['birthday_days_before'] ?? 1 );
        }

        // Process anniversaries
        if ( $settings['notify_anniversary'] ) {
            self::process_anniversary_notifications( $settings['anniversary_days_before'] ?? 1 );
        }

        // Process contract expiries
        if ( $settings['notify_contract_expiry'] ) {
            $days = $settings['contract_expiry_days'] ?? [ 30, 14, 7 ];
            foreach ( $days as $day ) {
                self::process_contract_expiry_notifications( (int) $day );
            }
        }

        // Process probation endings
        if ( $settings['notify_probation_end'] ) {
            self::process_probation_notifications( $settings['probation_days_before'] ?? 7 );
        }

        // Process pending action reminders
        if ( $settings['notify_pending_actions'] ) {
            self::process_pending_action_reminders();
        }

        // Process upcoming leave reminders (2-3 days before leave starts)
        self::process_upcoming_leave_reminders();
    }

    /**
     * Process birthday notifications for a given day offset
     *
     * @param int $days_before Days before birthday to notify (0 = today)
     */
    private static function process_birthday_notifications( int $days_before = 0 ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_employees';
        $target_date = gmdate( 'Y-m-d', strtotime( "+{$days_before} days" ) );
        $month_day = gmdate( 'm-d', strtotime( $target_date ) );

        $employees = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE status = 'active'
             AND date_of_birth IS NOT NULL
             AND DATE_FORMAT(date_of_birth, '%%m-%%d') = %s",
            $month_day
        ) );

        foreach ( $employees as $emp ) {
            do_action( 'sfs_hr_employee_birthday', (int) $emp->id );
        }
    }

    /**
     * Process anniversary notifications
     *
     * @param int $days_before Days before anniversary to notify
     */
    private static function process_anniversary_notifications( int $days_before = 0 ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_employees';
        $target_date = gmdate( 'Y-m-d', strtotime( "+{$days_before} days" ) );
        $month_day = gmdate( 'm-d', strtotime( $target_date ) );
        $current_year = (int) gmdate( 'Y' );

        $employees = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, hire_date FROM {$table}
             WHERE status = 'active'
             AND hire_date IS NOT NULL
             AND DATE_FORMAT(hire_date, '%%m-%%d') = %s
             AND YEAR(hire_date) < %d",
            $month_day,
            $current_year
        ) );

        foreach ( $employees as $emp ) {
            $hire_year = (int) gmdate( 'Y', strtotime( $emp->hire_date ) );
            $years = $current_year - $hire_year;
            if ( $years > 0 ) {
                do_action( 'sfs_hr_employee_anniversary', (int) $emp->id, $years );
            }
        }
    }

    /**
     * Process contract expiry notifications
     *
     * @param int $days Days until expiry
     */
    private static function process_contract_expiry_notifications( int $days ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_employees';
        $target_date = gmdate( 'Y-m-d', strtotime( "+{$days} days" ) );

        $employees = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE status = 'active'
             AND contract_end = %s",
            $target_date
        ) );

        foreach ( $employees as $emp ) {
            do_action( 'sfs_hr_contract_expiring', (int) $emp->id, $days );
        }
    }

    /**
     * Process probation ending notifications
     *
     * @param int $days Days until probation ends
     */
    private static function process_probation_notifications( int $days ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_employees';
        $target_date = gmdate( 'Y-m-d', strtotime( "+{$days} days" ) );

        $employees = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE status = 'active'
             AND probation_end = %s",
            $target_date
        ) );

        foreach ( $employees as $emp ) {
            do_action( 'sfs_hr_probation_ending', (int) $emp->id, $days );
        }
    }

    /**
     * Process daily pending action reminders
     * Sends reminders to specific assignees AND a summary to HR staff
     */
    private static function process_pending_action_reminders(): void {
        global $wpdb;
        $settings = self::get_settings();
        $site_name = get_bloginfo( 'name' );
        $today = wp_date( 'F j, Y' );

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $hr_pending_items = [];
        $assignee_reminders = []; // email => [ items ]

        // Helper to add item to assignee's reminder list
        $add_assignee_item = function( $email, $type, $item_data ) use ( &$assignee_reminders ) {
            if ( ! $email || ! is_email( $email ) ) return;
            if ( ! isset( $assignee_reminders[ $email ] ) ) {
                $assignee_reminders[ $email ] = [];
            }
            if ( ! isset( $assignee_reminders[ $email ][ $type ] ) ) {
                $assignee_reminders[ $email ][ $type ] = [];
            }
            $assignee_reminders[ $email ][ $type ][] = $item_data;
        };

        // 1. Pending Leave Requests - notify manager
        $leave_table = $wpdb->prefix . 'sfs_hr_leave_requests';
        $pending_leaves = $wpdb->get_results(
            "SELECT lr.id, lr.start_date, lr.end_date, lr.created_at,
                    CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                    e.manager_id,
                    CONCAT(m.first_name, ' ', m.last_name) as manager_name,
                    mu.user_email as manager_email
             FROM {$leave_table} lr
             LEFT JOIN {$emp_table} e ON lr.employee_id = e.id
             LEFT JOIN {$emp_table} m ON e.manager_id = m.id
             LEFT JOIN {$wpdb->users} mu ON m.user_id = mu.ID
             WHERE lr.status = 'pending'
             ORDER BY lr.created_at ASC"
        );
        if ( ! empty( $pending_leaves ) ) {
            $hr_pending_items['leave_requests'] = [
                'title' => __( 'Pending Leave Requests', 'sfs-hr' ),
                'count' => count( $pending_leaves ),
                'items' => $pending_leaves,
                'url'   => admin_url( 'admin.php?page=sfs-hr-leave-requests&status=pending' ),
            ];
            // Add to manager reminders
            foreach ( $pending_leaves as $leave ) {
                if ( $leave->manager_email ) {
                    $add_assignee_item( $leave->manager_email, 'leave_requests', $leave );
                }
            }
        }

        // 2. Pending Loan Approvals - notify GM or Finance
        $loans_table = $wpdb->prefix . 'sfs_hr_loans';
        if ( self::table_exists( $loans_table ) ) {
            $pending_loans = $wpdb->get_results(
                "SELECT l.id, l.amount, l.created_at, l.status,
                        CONCAT(e.first_name, ' ', e.last_name) as employee_name
                 FROM {$loans_table} l
                 LEFT JOIN {$emp_table} e ON l.employee_id = e.id
                 WHERE l.status IN ('pending_gm', 'pending_finance')
                 ORDER BY l.created_at ASC"
            );
            if ( ! empty( $pending_loans ) ) {
                $hr_pending_items['loans'] = [
                    'title' => __( 'Pending Loan Approvals', 'sfs-hr' ),
                    'count' => count( $pending_loans ),
                    'items' => $pending_loans,
                    'url'   => admin_url( 'admin.php?page=sfs-hr-loans' ),
                ];
                // Get GM and Finance approver emails
                $gm_emails = self::get_users_with_capability( 'sfs_hr_loans_gm_approve' );
                $finance_emails = self::get_users_with_capability( 'sfs_hr_loans_finance_approve' );

                foreach ( $pending_loans as $loan ) {
                    if ( $loan->status === 'pending_gm' ) {
                        foreach ( $gm_emails as $email ) {
                            $add_assignee_item( $email, 'loans_gm', $loan );
                        }
                    } elseif ( $loan->status === 'pending_finance' ) {
                        foreach ( $finance_emails as $email ) {
                            $add_assignee_item( $email, 'loans_finance', $loan );
                        }
                    }
                }
            }
        }

        // 3. Pending Resignations - notify manager
        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';
        if ( self::table_exists( $resign_table ) ) {
            $pending_resignations = $wpdb->get_results(
                "SELECT r.id, r.submitted_date, r.requested_last_day,
                        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                        CONCAT(m.first_name, ' ', m.last_name) as manager_name,
                        mu.user_email as manager_email
                 FROM {$resign_table} r
                 LEFT JOIN {$emp_table} e ON r.employee_id = e.id
                 LEFT JOIN {$emp_table} m ON e.manager_id = m.id
                 LEFT JOIN {$wpdb->users} mu ON m.user_id = mu.ID
                 WHERE r.status = 'pending'
                 ORDER BY r.submitted_date ASC"
            );
            if ( ! empty( $pending_resignations ) ) {
                $hr_pending_items['resignations'] = [
                    'title' => __( 'Pending Resignations', 'sfs-hr' ),
                    'count' => count( $pending_resignations ),
                    'items' => $pending_resignations,
                    'url'   => admin_url( 'admin.php?page=sfs-hr-lifecycle&tab=resignations' ),
                ];
                foreach ( $pending_resignations as $resign ) {
                    if ( $resign->manager_email ) {
                        $add_assignee_item( $resign->manager_email, 'resignations', $resign );
                    }
                }
            }
        }

        // 4. Pending Shift Swap Requests - notify target employee or HR
        $swap_table = $wpdb->prefix . 'sfs_hr_shift_swaps';
        if ( self::table_exists( $swap_table ) ) {
            $pending_swaps = $wpdb->get_results(
                "SELECT s.id, s.swap_date, s.created_at, s.status,
                        CONCAT(e1.first_name, ' ', e1.last_name) as requester_name,
                        CONCAT(e2.first_name, ' ', e2.last_name) as target_name,
                        u2.user_email as target_email
                 FROM {$swap_table} s
                 LEFT JOIN {$emp_table} e1 ON s.requester_employee_id = e1.id
                 LEFT JOIN {$emp_table} e2 ON s.target_employee_id = e2.id
                 LEFT JOIN {$wpdb->users} u2 ON e2.user_id = u2.ID
                 WHERE s.status IN ('pending', 'accepted')
                 ORDER BY s.created_at ASC"
            );
            if ( ! empty( $pending_swaps ) ) {
                $hr_pending_items['shift_swaps'] = [
                    'title' => __( 'Pending Shift Swap Requests', 'sfs-hr' ),
                    'count' => count( $pending_swaps ),
                    'items' => $pending_swaps,
                    'url'   => admin_url( 'admin.php?page=sfs-hr-shift-swaps' ),
                ];
                foreach ( $pending_swaps as $swap ) {
                    if ( $swap->status === 'pending' && $swap->target_email ) {
                        // Pending target employee acceptance
                        $add_assignee_item( $swap->target_email, 'shift_swaps_pending', $swap );
                    }
                    // 'accepted' status means HR needs to approve - already in HR summary
                }
            }
        }

        // 5. Pending Asset Assignments - notify employee or HR
        $asset_assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
        if ( self::table_exists( $asset_assign_table ) ) {
            $pending_assets = $wpdb->get_results(
                "SELECT aa.id, aa.start_date, aa.status,
                        CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                        u.user_email as employee_email,
                        a.name as asset_name
                 FROM {$asset_assign_table} aa
                 LEFT JOIN {$emp_table} e ON aa.employee_id = e.id
                 LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                 LEFT JOIN {$wpdb->prefix}sfs_hr_assets a ON aa.asset_id = a.id
                 WHERE aa.status IN ('pending_employee_approval', 'return_requested')
                 ORDER BY aa.created_at ASC"
            );
            if ( ! empty( $pending_assets ) ) {
                $hr_pending_items['assets'] = [
                    'title' => __( 'Pending Asset Actions', 'sfs-hr' ),
                    'count' => count( $pending_assets ),
                    'items' => $pending_assets,
                    'url'   => admin_url( 'admin.php?page=sfs-hr-assets' ),
                ];
                foreach ( $pending_assets as $asset ) {
                    if ( $asset->status === 'pending_employee_approval' && $asset->employee_email ) {
                        // Employee needs to accept asset
                        $add_assignee_item( $asset->employee_email, 'assets_pending', $asset );
                    }
                    // 'return_requested' goes to HR - already in HR summary
                }
            }
        }

        // 6. Pending Hiring Candidates - notify dept manager or GM
        $candidates_table = $wpdb->prefix . 'sfs_hr_candidates';
        if ( self::table_exists( $candidates_table ) ) {
            $pending_candidates = $wpdb->get_results(
                "SELECT c.id, c.first_name, c.last_name, c.position_applied, c.status, c.created_at,
                        c.department
                 FROM {$candidates_table} c
                 WHERE c.status IN ('applied', 'screening', 'dept_pending', 'gm_pending', 'gm_approved')
                 ORDER BY c.created_at ASC"
            );
            if ( ! empty( $pending_candidates ) ) {
                $hr_pending_items['hiring'] = [
                    'title' => __( 'Pending Hiring Actions', 'sfs-hr' ),
                    'count' => count( $pending_candidates ),
                    'items' => $pending_candidates,
                    'url'   => admin_url( 'admin.php?page=sfs-hr-hiring&tab=candidates' ),
                ];

                // Get GM emails (users with manage_options)
                $gm_emails = self::get_users_with_capability( 'manage_options' );

                foreach ( $pending_candidates as $candidate ) {
                    if ( $candidate->status === 'dept_pending' && $candidate->department ) {
                        // Get department manager email
                        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
                        $dept_manager_email = $wpdb->get_var( $wpdb->prepare(
                            "SELECT u.user_email
                             FROM {$dept_table} d
                             LEFT JOIN {$wpdb->users} u ON d.manager_user_id = u.ID
                             WHERE d.name = %s AND d.active = 1",
                            $candidate->department
                        ) );
                        if ( $dept_manager_email ) {
                            $add_assignee_item( $dept_manager_email, 'hiring_dept', $candidate );
                        }
                    } elseif ( $candidate->status === 'gm_pending' ) {
                        // Notify GM users
                        foreach ( $gm_emails as $email ) {
                            $add_assignee_item( $email, 'hiring_gm', $candidate );
                        }
                    }
                    // 'applied', 'screening', 'gm_approved' go to HR - already in HR summary
                }
            }
        }

        // Send individual assignee reminders
        self::send_assignee_reminders( $assignee_reminders, $site_name, $today );

        // Send HR summary if there are pending items and HR emails configured
        if ( ! empty( $hr_pending_items ) && ! empty( $settings['hr_emails'] ) ) {
            self::send_hr_summary_reminder( $hr_pending_items, $settings['hr_emails'], $site_name, $today );
        }
    }

    /**
     * Send individual reminders to assignees
     */
    private static function send_assignee_reminders( array $assignee_reminders, string $site_name, string $today ): void {
        $type_labels = [
            'leave_requests'      => __( 'Leave Requests Awaiting Your Approval', 'sfs-hr' ),
            'loans_gm'            => __( 'Loan Requests Awaiting GM Approval', 'sfs-hr' ),
            'loans_finance'       => __( 'Loan Requests Awaiting Finance Approval', 'sfs-hr' ),
            'resignations'        => __( 'Resignations Awaiting Your Review', 'sfs-hr' ),
            'shift_swaps_pending' => __( 'Shift Swap Requests Awaiting Your Response', 'sfs-hr' ),
            'assets_pending'      => __( 'Assets Awaiting Your Acceptance', 'sfs-hr' ),
            'hiring_dept'         => __( 'Candidates Awaiting Department Review', 'sfs-hr' ),
            'hiring_gm'           => __( 'Candidates Awaiting GM Approval', 'sfs-hr' ),
        ];

        $type_urls = [
            'leave_requests'      => admin_url( 'admin.php?page=sfs-hr-leave-requests&status=pending' ),
            'loans_gm'            => admin_url( 'admin.php?page=sfs-hr-loans' ),
            'loans_finance'       => admin_url( 'admin.php?page=sfs-hr-loans' ),
            'resignations'        => admin_url( 'admin.php?page=sfs-hr-lifecycle&tab=resignations' ),
            'shift_swaps_pending' => home_url( '/my-profile/' ),
            'assets_pending'      => home_url( '/my-profile/?tab=assets' ),
            'hiring_dept'         => admin_url( 'admin.php?page=sfs-hr-hiring&tab=candidates' ),
            'hiring_gm'           => admin_url( 'admin.php?page=sfs-hr-hiring&tab=candidates' ),
        ];

        foreach ( $assignee_reminders as $email => $types ) {
            $total_items = 0;
            foreach ( $types as $items ) {
                $total_items += count( $items );
            }

            if ( $total_items === 0 ) continue;

            $subject = sprintf(
                __( '[Action Required] %d item(s) awaiting your response', 'sfs-hr' ),
                $total_items
            );

            $message = sprintf(
                __( "Hello,\n\nThis is a reminder that you have %d pending item(s) requiring your action as of %s.\n\n", 'sfs-hr' ),
                $total_items,
                $today
            );

            foreach ( $types as $type => $items ) {
                $label = $type_labels[ $type ] ?? $type;
                $url = $type_urls[ $type ] ?? admin_url();
                $count = count( $items );

                $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                $message .= sprintf( "%s (%d)\n", $label, $count );
                $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

                $shown = 0;
                foreach ( $items as $item ) {
                    if ( $shown >= 5 ) {
                        $message .= sprintf( __( "... and %d more\n", 'sfs-hr' ), $count - 5 );
                        break;
                    }

                    switch ( $type ) {
                        case 'leave_requests':
                            $message .= sprintf(
                                "• %s - %s to %s\n",
                                $item->employee_name,
                                wp_date( 'M j', strtotime( $item->start_date ) ),
                                wp_date( 'M j', strtotime( $item->end_date ) )
                            );
                            break;
                        case 'loans_gm':
                        case 'loans_finance':
                            $message .= sprintf(
                                "• %s - %s\n",
                                $item->employee_name,
                                number_format( (float) $item->amount, 2 )
                            );
                            break;
                        case 'resignations':
                            $message .= sprintf(
                                "• %s - Last day: %s\n",
                                $item->employee_name,
                                wp_date( 'M j, Y', strtotime( $item->requested_last_day ) )
                            );
                            break;
                        case 'shift_swaps_pending':
                            $message .= sprintf(
                                "• %s wants to swap shifts on %s\n",
                                $item->requester_name,
                                wp_date( 'M j', strtotime( $item->swap_date ) )
                            );
                            break;
                        case 'assets_pending':
                            $message .= sprintf(
                                "• %s assigned to you\n",
                                $item->asset_name
                            );
                            break;
                        case 'hiring_dept':
                        case 'hiring_gm':
                            $message .= sprintf(
                                "• %s %s - %s\n",
                                $item->first_name,
                                $item->last_name,
                                $item->position_applied ?: __( 'N/A', 'sfs-hr' )
                            );
                            break;
                    }
                    $shown++;
                }

                $message .= sprintf( __( "\nTake action: %s\n\n", 'sfs-hr' ), $url );
            }

            $message .= sprintf( __( "---\n%s\nHR Management System\n", 'sfs-hr' ), $site_name );

            Helpers::send_mail( $email, $subject, $message );
        }
    }

    /**
     * Send HR summary reminder
     */
    private static function send_hr_summary_reminder( array $pending_items, array $hr_emails, string $site_name, string $today ): void {
        $total_pending = array_sum( array_column( $pending_items, 'count' ) );

        $subject = sprintf(
            __( '[Daily Reminder] %d pending items require your attention', 'sfs-hr' ),
            $total_pending
        );

        $message = sprintf( __( "Good morning!\n\nThis is your daily HR reminder for %s.\n\nYou have %d pending items that require action:\n\n", 'sfs-hr' ), $today, $total_pending );

        foreach ( $pending_items as $key => $section ) {
            $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $message .= sprintf( "%s (%d)\n", $section['title'], $section['count'] );
            $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

            $shown = 0;
            foreach ( $section['items'] as $item ) {
                if ( $shown >= 5 ) {
                    $remaining = $section['count'] - 5;
                    $message .= sprintf( __( "... and %d more\n", 'sfs-hr' ), $remaining );
                    break;
                }

                switch ( $key ) {
                    case 'leave_requests':
                        $message .= sprintf(
                            "• %s - %s to %s\n",
                            $item->employee_name,
                            wp_date( 'M j', strtotime( $item->start_date ) ),
                            wp_date( 'M j', strtotime( $item->end_date ) )
                        );
                        break;
                    case 'loans':
                        $status_label = $item->status === 'pending_gm' ? __( 'Awaiting GM', 'sfs-hr' ) : __( 'Awaiting Finance', 'sfs-hr' );
                        $message .= sprintf(
                            "• %s - %s (%s)\n",
                            $item->employee_name,
                            number_format( (float) $item->amount, 2 ),
                            $status_label
                        );
                        break;
                    case 'resignations':
                        $message .= sprintf(
                            "• %s - Last day: %s\n",
                            $item->employee_name,
                            wp_date( 'M j, Y', strtotime( $item->requested_last_day ) )
                        );
                        break;
                    case 'shift_swaps':
                        $message .= sprintf(
                            "• %s ↔ %s - %s\n",
                            $item->requester_name,
                            $item->target_name,
                            wp_date( 'M j', strtotime( $item->swap_date ) )
                        );
                        break;
                    case 'assets':
                        $message .= sprintf(
                            "• %s - %s (%s)\n",
                            $item->employee_name,
                            $item->asset_name,
                            $item->status === 'return_requested' ? __( 'Return Requested', 'sfs-hr' ) : __( 'Pending Approval', 'sfs-hr' )
                        );
                        break;
                    case 'hiring':
                        $status_labels = [
                            'applied'      => __( 'New', 'sfs-hr' ),
                            'screening'    => __( 'Screening', 'sfs-hr' ),
                            'dept_pending' => __( 'Dept Review', 'sfs-hr' ),
                            'gm_pending'   => __( 'GM Review', 'sfs-hr' ),
                            'gm_approved'  => __( 'Ready to Hire', 'sfs-hr' ),
                        ];
                        $message .= sprintf(
                            "• %s %s - %s (%s)\n",
                            $item->first_name,
                            $item->last_name,
                            $item->position_applied ?: __( 'N/A', 'sfs-hr' ),
                            $status_labels[ $item->status ] ?? $item->status
                        );
                        break;
                }
                $shown++;
            }

            $message .= sprintf( __( "\nReview: %s\n\n", 'sfs-hr' ), $section['url'] );
        }

        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= sprintf( __( "\nHR Dashboard: %s\n", 'sfs-hr' ), admin_url( 'admin.php?page=sfs-hr' ) );
        $message .= sprintf( __( "\n---\n%s\nHR Management System\n", 'sfs-hr' ), $site_name );

        foreach ( $hr_emails as $hr_email ) {
            if ( is_email( $hr_email ) ) {
                Helpers::send_mail( $hr_email, $subject, $message );
            }
        }
    }

    /**
     * Get users with a specific capability
     *
     * @param string $capability The capability to check
     * @return array List of user emails
     */
    private static function get_users_with_capability( string $capability ): array {
        $emails = [];
        $users = get_users( [
            'capability' => $capability,
            'fields'     => [ 'user_email' ],
        ] );
        foreach ( $users as $user ) {
            if ( is_email( $user->user_email ) ) {
                $emails[] = $user->user_email;
            }
        }
        return $emails;
    }

    /**
     * Check if a database table exists
     *
     * @param string $table_name Full table name
     * @return bool
     */
    private static function table_exists( string $table_name ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $table_name
        ) );
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Send notification via email and optionally SMS
     *
     * @param string $email             Recipient email
     * @param string $subject           Email subject
     * @param string $message           Email body
     * @param string $phone             Optional phone for SMS
     * @param int    $user_id           Optional user ID for preference checks
     * @param string $notification_type Optional notification type for preference checks
     */
    private static function send_notification( string $email, string $subject, string $message, string $phone = '', int $user_id = 0, string $notification_type = 'general' ): void {
        $settings = self::get_settings();

        // Try to get user_id from email if not provided
        if ( ! $user_id && $email ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                $user_id = $user->ID;
            }
        }

        // Send email with preference checks
        if ( $settings['email_enabled'] && $email ) {
            // Check if user wants this type of email notification
            if ( apply_filters( 'dofs_user_wants_email_notification', true, $user_id, $notification_type ) ) {
                // Check if notification should be sent now or queued for digest
                if ( apply_filters( 'dofs_should_send_notification_now', true, $user_id, $notification_type ) ) {
                    // Send email immediately
                    Helpers::send_mail( $email, $subject, $message );
                } else {
                    // Queue for digest
                    self::queue_for_digest( $user_id, $email, $subject, $message, $notification_type );
                }
            }
        }

        // Send SMS if enabled and phone provided (SMS is always immediate)
        if ( $settings['sms_enabled'] && $phone ) {
            // Strip HTML and limit message length for SMS
            $sms_message = wp_strip_all_tags( $message );
            $sms_message = substr( $sms_message, 0, 160 );
            self::send_sms( $phone, $sms_message );
        }
    }

    /**
     * Queue notification for digest delivery
     *
     * @param int    $user_id           User ID
     * @param string $email             Recipient email
     * @param string $subject           Email subject
     * @param string $message           Email body
     * @param string $notification_type Notification type
     */
    private static function queue_for_digest( int $user_id, string $email, string $subject, string $message, string $notification_type ): void {
        $queue = get_option( 'sfs_hr_notification_digest_queue', [] );

        $queue[] = [
            'user_id'           => $user_id,
            'email'             => $email,
            'subject'           => $subject,
            'message'           => $message,
            'notification_type' => $notification_type,
            'queued_at'         => current_time( 'mysql' ),
        ];

        update_option( 'sfs_hr_notification_digest_queue', $queue );

        // Fire action for external digest handlers
        do_action( 'sfs_hr_notification_queued_for_digest', $user_id, $email, $subject, $message, $notification_type );
    }

    /**
     * Send SMS notification
     *
     * @param string $phone   Phone number
     * @param string $message SMS message (max 160 chars)
     */
    private static function send_sms( string $phone, string $message ): void {
        $settings = self::get_settings();

        if ( ! $settings['sms_enabled'] ) {
            return;
        }

        $phone = preg_replace( '/[^0-9+]/', '', $phone );
        if ( empty( $phone ) ) {
            return;
        }

        switch ( $settings['sms_provider'] ) {
            case self::SMS_PROVIDER_TWILIO:
                self::send_sms_twilio( $phone, $message, $settings );
                break;

            case self::SMS_PROVIDER_NEXMO:
                self::send_sms_nexmo( $phone, $message, $settings );
                break;

            case self::SMS_PROVIDER_CUSTOM:
                self::send_sms_custom( $phone, $message, $settings );
                break;
        }
    }

    /**
     * Send SMS via Twilio
     */
    private static function send_sms_twilio( string $phone, string $message, array $settings ): void {
        if ( empty( $settings['twilio_sid'] ) || empty( $settings['twilio_token'] ) || empty( $settings['twilio_from'] ) ) {
            return;
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $settings['twilio_sid'] . '/Messages.json';

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $settings['twilio_sid'] . ':' . $settings['twilio_token'] ),
            ],
            'body'    => [
                'From' => $settings['twilio_from'],
                'To'   => $phone,
                'Body' => $message,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'SFS HR SMS Error (Twilio): ' . $response->get_error_message() );
        }
    }

    /**
     * Send SMS via Nexmo/Vonage
     */
    private static function send_sms_nexmo( string $phone, string $message, array $settings ): void {
        if ( empty( $settings['nexmo_api_key'] ) || empty( $settings['nexmo_api_secret'] ) || empty( $settings['nexmo_from'] ) ) {
            return;
        }

        $url = 'https://rest.nexmo.com/sms/json';

        $response = wp_remote_post( $url, [
            'body' => [
                'api_key'    => $settings['nexmo_api_key'],
                'api_secret' => $settings['nexmo_api_secret'],
                'from'       => $settings['nexmo_from'],
                'to'         => $phone,
                'text'       => $message,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'SFS HR SMS Error (Nexmo): ' . $response->get_error_message() );
        }
    }

    /**
     * Send SMS via custom API endpoint
     */
    private static function send_sms_custom( string $phone, string $message, array $settings ): void {
        if ( empty( $settings['custom_sms_endpoint'] ) ) {
            return;
        }

        $headers = [];
        if ( ! empty( $settings['custom_sms_api_key'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $settings['custom_sms_api_key'];
        }

        $response = wp_remote_post( $settings['custom_sms_endpoint'], [
            'headers' => $headers,
            'body'    => [
                'phone'   => $phone,
                'message' => $message,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'SFS HR SMS Error (Custom): ' . $response->get_error_message() );
        }
    }

    /**
     * Send reminders to employees whose approved leave starts in 2-3 days.
     *
     * Notifies the employee that:
     * - Their leave is approaching
     * - They will not be able to clock in during the leave period
     * - They must apply for leave cancellation if they will not be taking the leave
     * - They are responsible for any absence or delay if they fail to cancel
     */
    private static function process_upcoming_leave_reminders(): void {
        global $wpdb;

        $settings = self::get_settings();
        if ( ! $settings['enabled'] || ! $settings['email_enabled'] ) {
            return;
        }

        $leaves_table = $wpdb->prefix . 'sfs_hr_leave_requests';
        $emp_table    = $wpdb->prefix . 'sfs_hr_employees';
        $types_table  = $wpdb->prefix . 'sfs_hr_leave_types';

        $today   = wp_date( 'Y-m-d' );
        $day_2   = wp_date( 'Y-m-d', strtotime( '+2 days' ) );
        $day_3   = wp_date( 'Y-m-d', strtotime( '+3 days' ) );

        // Find approved leaves starting in exactly 2 or 3 days
        $leaves = $wpdb->get_results( $wpdb->prepare(
            "SELECT lr.id, lr.employee_id, lr.start_date, lr.end_date, lr.days,
                    lt.name AS leave_type_name,
                    COALESCE(NULLIF(TRIM(CONCAT(e.first_name, ' ', e.last_name)), ''), e.employee_code) AS employee_name,
                    e.employee_code,
                    e.phone AS employee_phone,
                    e.user_id,
                    u.user_email AS employee_email
             FROM {$leaves_table} lr
             LEFT JOIN {$types_table} lt ON lr.type_id = lt.id
             LEFT JOIN {$emp_table} e ON lr.employee_id = e.id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             WHERE lr.status = 'approved'
               AND lr.start_date IN (%s, %s)
               AND e.status = 'active'",
            $day_2,
            $day_3
        ) );

        if ( empty( $leaves ) ) {
            return;
        }

        // Track sent reminders to avoid duplicates across cron runs
        $sent_key  = 'sfs_hr_leave_reminder_sent';
        $sent_list = get_option( $sent_key, [] );
        if ( ! is_array( $sent_list ) ) {
            $sent_list = [];
        }

        foreach ( $leaves as $leave ) {
            $reminder_key = $leave->id . '|' . $today;

            // Skip if already reminded today for this request
            if ( ! empty( $sent_list[ $reminder_key ] ) ) {
                continue;
            }

            if ( empty( $leave->employee_email ) ) {
                continue;
            }

            $days_until = (int) ( ( strtotime( $leave->start_date ) - strtotime( $today ) ) / DAY_IN_SECONDS );

            $subject = sprintf(
                __( '[Leave Reminder] Your %s leave starts in %d days', 'sfs-hr' ),
                $leave->leave_type_name ?: __( 'Leave', 'sfs-hr' ),
                $days_until
            );

            $message = self::get_email_template( 'leave_upcoming_reminder_to_employee', [
                'employee_name' => $leave->employee_name,
                'leave_type'    => $leave->leave_type_name ?: __( 'Leave', 'sfs-hr' ),
                'start_date'    => wp_date( 'F j, Y', strtotime( $leave->start_date ) ),
                'end_date'      => wp_date( 'F j, Y', strtotime( $leave->end_date ) ),
                'days'          => $leave->days,
                'days_until'    => $days_until,
            ] );

            self::send_notification(
                $leave->employee_email,
                $subject,
                $message,
                $leave->employee_phone ?? '',
                (int) $leave->user_id,
                'leave_upcoming_reminder'
            );

            $sent_list[ $reminder_key ] = wp_date( 'Y-m-d H:i:s' );
        }

        // Prune old entries (keep last 500)
        if ( count( $sent_list ) > 500 ) {
            $sent_list = array_slice( $sent_list, -500, null, true );
        }

        update_option( $sent_key, $sent_list, false );
    }

    /**
     * Get leave request data with related info
     *
     * @param int $request_id Leave request ID
     * @return object|null
     */
    private static function get_leave_request_data( int $request_id ) {
        global $wpdb;

        $leaves_table = $wpdb->prefix . 'sfs_hr_leave_requests';
        $types_table  = $wpdb->prefix . 'sfs_hr_leave_types';
        $emp_table    = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table   = $wpdb->prefix . 'sfs_hr_departments';

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT lr.*,
                    lt.name as leave_type_name,
                    COALESCE(NULLIF(TRIM(CONCAT(e.first_name, ' ', e.last_name)), ''), e.employee_code) as employee_name,
                    e.employee_code,
                    e.phone as employee_phone,
                    u.user_email as employee_email,
                    d.name as department_name,
                    COALESCE(NULLIF(TRIM(CONCAT(m.first_name, ' ', m.last_name)), ''), m.employee_code) as manager_name,
                    mu.user_email as manager_email,
                    m.phone as manager_phone
             FROM {$leaves_table} lr
             LEFT JOIN {$types_table} lt ON lr.type_id = lt.id
             LEFT JOIN {$emp_table} e ON lr.employee_id = e.id
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$dept_table} d ON e.department = d.name
             LEFT JOIN {$emp_table} m ON e.manager_id = m.id
             LEFT JOIN {$wpdb->users} mu ON m.user_id = mu.ID
             WHERE lr.id = %d",
            $request_id
        ) );
    }

    /**
     * Get employee data with related info
     *
     * @param int $employee_id Employee ID
     * @return object|null
     */
    private static function get_employee_data( int $employee_id ) {
        global $wpdb;

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT e.*,
                    COALESCE(NULLIF(TRIM(CONCAT(e.first_name, ' ', e.last_name)), ''), e.employee_code) as full_name,
                    u.user_email as email,
                    d.name as department_name,
                    COALESCE(NULLIF(TRIM(CONCAT(m.first_name, ' ', m.last_name)), ''), m.employee_code) as manager_name,
                    mu.user_email as manager_email,
                    m.phone as manager_phone
             FROM {$emp_table} e
             LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
             LEFT JOIN {$dept_table} d ON e.department = d.name
             LEFT JOIN {$emp_table} m ON e.manager_id = m.id
             LEFT JOIN {$wpdb->users} mu ON m.user_id = mu.ID
             WHERE e.id = %d",
            $employee_id
        ) );
    }

    /**
     * Get email template by name
     *
     * @param string $template Template name
     * @param array  $vars     Template variables
     * @return string
     */
    private static function get_email_template( string $template, array $vars ): string {
        $site_name = get_bloginfo( 'name' );
        $vars['site_name'] = $site_name;

        $templates = [
            // Leave templates
            'leave_request_to_manager' => "
Hello {manager_name},

A new leave request has been submitted and requires your approval.

Leave Request Details:
----------------------
Employee: {employee_name} ({employee_code})
Leave Type: {leave_type}
Start Date: {start_date}
End Date: {end_date}
Duration: {days} day(s)

Reason:
{reason}

Please review and approve or reject this request:
{review_url}

---
{site_name}
HR Management System
",

            'leave_request_to_hr' => "
Hello HR Team,

A new leave request has been submitted.

Leave Request Details:
----------------------
Employee: {employee_name} ({employee_code})
Department: {department}
Leave Type: {leave_type}
Start Date: {start_date}
End Date: {end_date}
Duration: {days} day(s)

Reason:
{reason}

Review pending requests:
{review_url}

---
{site_name}
HR Management System
",

            'leave_approved_to_employee' => "
Hello {employee_name},

Great news! Your leave request has been approved.

Leave Details:
--------------
Leave Type: {leave_type}
Start Date: {start_date}
End Date: {end_date}
Duration: {days} day(s)
Approved By: {approved_by}

Enjoy your time off!

---
{site_name}
HR Management System
",

            'leave_rejected_to_employee' => "
Hello {employee_name},

We regret to inform you that your leave request has been declined.

Leave Details:
--------------
Leave Type: {leave_type}
Start Date: {start_date}
End Date: {end_date}
Duration: {days} day(s)
Declined By: {rejected_by}

Reason for Decline:
{rejection_reason}

If you have any questions, please contact HR.

---
{site_name}
HR Management System
",

            'leave_cancelled_to_manager' => "
Hello {manager_name},

This is to inform you that a leave request has been cancelled.

Cancelled Leave Details:
------------------------
Employee: {employee_name}
Leave Type: {leave_type}
Start Date: {start_date}
End Date: {end_date}
Duration: {days} day(s)

No action is required from your side.

---
{site_name}
HR Management System
",

            'leave_upcoming_reminder_to_employee' => "
Hello {employee_name},

This is a reminder that your approved leave is starting in {days_until} day(s).

Leave Details:
--------------
Leave Type: {leave_type}
Start Date: {start_date}
End Date: {end_date}
Duration: {days} day(s)

Important Notice:
-----------------
- You will NOT be able to clock in during your leave period.
- If for any reason you will not be taking this leave, you MUST submit a Leave Cancellation Request before your leave start date.
- You will be held responsible for any delay or days without clocking in if you fail to cancel your leave in advance.

Please plan accordingly.

---
{site_name}
HR Management System
",

            // Attendance templates
            'late_arrival_to_manager' => "
Hello {manager_name},

An employee in your team arrived late today.

Late Arrival Details:
---------------------
Employee: {employee_name} ({employee_code})
Date: {date}
Expected Time: {expected_time}
Actual Time: {actual_time}
Minutes Late: {minutes_late}

---
{site_name}
HR Management System
",

            'early_leave_to_manager' => "
Hello {manager_name},

An employee has requested early leave today.

Early Leave Request:
--------------------
Employee: {employee_name} ({employee_code})
Date: {date}
Reason: {reason}

Please review:
{review_url}

---
{site_name}
HR Management System
",

            // Employee templates
            'new_employee_to_hr' => "
Hello HR Team,

A new employee has been added to the system.

New Employee Details:
---------------------
Name: {employee_name}
Employee Code: {employee_code}
Department: {department}
Job Title: {job_title}
Hire Date: {hire_date}

View employee profile:
{view_url}

---
{site_name}
HR Management System
",

            'new_employee_to_manager' => "
Hello {manager_name},

A new team member has been added to your team.

New Team Member:
----------------
Name: {employee_name}
Employee Code: {employee_code}
Job Title: {job_title}
Start Date: {hire_date}

View employee profile:
{view_url}

---
{site_name}
HR Management System
",

            'birthday_to_employee' => "
Hello {employee_name},

Wishing you a very Happy Birthday!

May this special day bring you happiness, joy, and all the things you deserve.

Best wishes from the entire team!

---
{site_name}
HR Management System
",

            'anniversary_to_employee' => "
Hello {employee_name},

Congratulations on your {years}-year work anniversary!

Your dedication and hard work over the past {years} years have been invaluable to our team. Thank you for being such an important part of our organization.

Here's to many more years of success together!

---
{site_name}
HR Management System
",

            'anniversary_to_hr' => "
Hello HR Team,

An employee is celebrating a work anniversary.

Anniversary Details:
--------------------
Employee: {employee_name} ({employee_code})
Department: {department}
Years of Service: {years}
Hire Date: {hire_date}

---
{site_name}
HR Management System
",

            'contract_expiry_to_hr' => "
Hello HR Team,

An employee's contract is expiring soon and requires attention.

Contract Expiry Alert:
----------------------
Employee: {employee_name} ({employee_code})
Department: {department}
Days Until Expiry: {days_until}
Expiry Date: {expiry_date}

Please take necessary action:
{view_url}

---
{site_name}
HR Management System
",

            'probation_end_notice' => "
Hello,

An employee's probation period is ending soon and requires review.

Probation Review Alert:
-----------------------
Employee: {employee_name} ({employee_code})
Department: {department}
Days Until Probation Ends: {days_until}
Hire Date: {hire_date}

Please conduct the probation review:
{view_url}

---
{site_name}
HR Management System
",

            // Payroll templates
            'payroll_approved_to_hr' => "
Hello HR Team,

A payroll run has been approved and processed.

Payroll Run Details:
--------------------
Run ID: #{run_id}
Employees Processed: {employee_count}
Total Net Pay: {total_net}
Approved By: {approved_by}

View payroll details:
{view_url}

---
{site_name}
HR Management System
",

            'payslip_ready_to_employee' => "
Hello {employee_name},

Your payslip for {period_name} is now available.

Payment Summary:
----------------
Period: {period_name}
Net Salary: {net_salary}

You can view and download your payslip here:
{view_url}

---
{site_name}
HR Management System
",
        ];

        $template_content = $templates[ $template ] ?? '';

        // Replace variables
        foreach ( $vars as $key => $value ) {
            $template_content = str_replace( '{' . $key . '}', $value, $template_content );
        }

        return $template_content;
    }
}
