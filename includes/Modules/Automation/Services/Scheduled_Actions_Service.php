<?php
namespace SFS\HR\Modules\Automation\Services;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Scheduled_Actions_Service {

    public function run_daily(): void {
        $checks = [
            'check_probation_ending',
            'check_contract_expiring',
            'check_leave_balance_low',
            'check_birthdays',
            'check_work_anniversaries',
        ];

        foreach ( $checks as $method ) {
            try {
                $this->$method();
            } catch ( \Throwable $e ) {
                error_log( sprintf(
                    '[SFS HR Automation] %s failed: %s',
                    $method,
                    $e->getMessage()
                ) );
            }
        }
    }

    public function check_probation_ending(): void {
        global $wpdb;

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $today      = wp_date( 'Y-m-d' );

        foreach ( [ 30, 15, 7 ] as $days ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT e.first_name, e.last_name, e.position, e.dept_id, e.probation_end_date,
                        d.name AS dept_name, d.manager_user_id, d.hr_responsible_user_id
                 FROM `{$emp_table}` e
                 LEFT JOIN `{$dept_table}` d ON d.id = e.dept_id
                 WHERE e.status = 'active'
                   AND e.probation_end_date = DATE_ADD(%s, INTERVAL %d DAY)",
                $today,
                $days
            ) );

            if ( empty( $rows ) ) {
                continue;
            }

            foreach ( $rows as $row ) {
                $name    = trim( $row->first_name . ' ' . $row->last_name );
                $end     = wp_date( get_option( 'date_format' ), strtotime( $row->probation_end_date ) );
                $subject = sprintf(
                    __( 'Probation Review Reminder: %s (%d days remaining)', 'sfs-hr' ),
                    $name,
                    $days
                );
                $body = sprintf(
                    __( "The probation period for %1\$s (%2\$s) in %3\$s ends on %4\$s.\nPlease prepare the probation review.", 'sfs-hr' ),
                    $name,
                    $row->position ?? '',
                    $row->dept_name ?? '',
                    $end
                );

                $recipients = array_filter( [
                    $this->get_manager_email( (int) $row->dept_id ),
                    $this->get_hr_responsible_email( $row ),
                ] );

                foreach ( $recipients as $email ) {
                    Helpers::send_mail( $email, $subject, $body );
                }
            }
        }
    }

    public function check_contract_expiring(): void {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $today     = wp_date( 'Y-m-d' );

        foreach ( [ 60, 30, 7 ] as $days ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT e.id, e.user_id, e.first_name, e.last_name, e.position, e.contract_end_date
                 FROM `{$emp_table}` e
                 WHERE e.status = 'active'
                   AND e.contract_end_date = DATE_ADD(%s, INTERVAL %d DAY)",
                $today,
                $days
            ) );

            if ( empty( $rows ) ) {
                continue;
            }

            $hr_emails = $this->get_hr_emails();

            foreach ( $rows as $row ) {
                $name    = trim( $row->first_name . ' ' . $row->last_name );
                $end     = wp_date( get_option( 'date_format' ), strtotime( $row->contract_end_date ) );
                $subject = sprintf(
                    __( 'Contract Expiring: %s (%d days remaining)', 'sfs-hr' ),
                    $name,
                    $days
                );

                $hr_body = sprintf(
                    __( "The contract for %1\$s (%2\$s) expires on %3\$s.\nPlease initiate the renewal or offboarding process.", 'sfs-hr' ),
                    $name,
                    $row->position ?? '',
                    $end
                );

                if ( ! empty( $hr_emails ) ) {
                    Helpers::send_mail( $hr_emails, $subject, $hr_body );
                }

                if ( ! empty( $row->user_id ) ) {
                    $user = get_user_by( 'id', $row->user_id );
                    if ( $user && $user->user_email ) {
                        $emp_body = sprintf(
                            __( "Your contract expires on %s. Please contact HR for renewal details.", 'sfs-hr' ),
                            $end
                        );
                        Helpers::send_mail( $user->user_email, $subject, $emp_body );
                    }
                }
            }
        }
    }

    public function check_leave_balance_low(): void {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $bal_table = $wpdb->prefix . 'sfs_hr_leave_balances';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.user_id, e.first_name, e.last_name,
                    b.total_days, b.used_days, (b.total_days - b.used_days) AS remaining
             FROM `{$bal_table}` b
             JOIN `{$emp_table}` e ON e.id = b.employee_id
             WHERE b.leave_type_id = %d
               AND b.total_days > 0
               AND b.used_days >= (b.total_days - 5)
               AND e.status = 'active'",
            1
        ) );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            if ( empty( $row->user_id ) ) {
                continue;
            }

            $user = get_user_by( 'id', $row->user_id );
            if ( ! $user || ! $user->user_email ) {
                continue;
            }

            $name      = trim( $row->first_name . ' ' . $row->last_name );
            $remaining = max( 0, (float) $row->remaining );
            $subject   = __( 'Low Annual Leave Balance', 'sfs-hr' );
            $body      = sprintf(
                __( "Dear %1\$s,\n\nYour annual leave balance is running low. You have %2\$s day(s) remaining out of %3\$s.\n\nPlease plan your leave accordingly.", 'sfs-hr' ),
                $name,
                number_format_i18n( $remaining, 1 ),
                number_format_i18n( (float) $row->total_days, 1 )
            );

            Helpers::send_mail( $user->user_email, $subject, $body );
        }
    }

    public function check_birthdays(): void {
        global $wpdb;

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $today      = wp_date( 'Y-m-d' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.first_name, e.last_name, e.dept_id, d.manager_user_id
             FROM `{$emp_table}` e
             LEFT JOIN `{$dept_table}` d ON d.id = e.dept_id
             WHERE e.status = 'active'
               AND e.birth_date IS NOT NULL
               AND MONTH(e.birth_date) = MONTH(%s)
               AND DAY(e.birth_date) = DAY(%s)",
            $today,
            $today
        ) );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $manager_email = $this->get_manager_email( (int) $row->dept_id );
            if ( ! $manager_email ) {
                continue;
            }

            $name    = trim( $row->first_name . ' ' . $row->last_name );
            $subject = sprintf( __( 'Birthday Today: %s', 'sfs-hr' ), $name );
            $body    = sprintf(
                __( "Today is %s's birthday. You may wish to send your congratulations.", 'sfs-hr' ),
                $name
            );

            Helpers::send_mail( $manager_email, $subject, $body );
        }
    }

    public function check_work_anniversaries(): void {
        global $wpdb;

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $today      = wp_date( 'Y-m-d' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.first_name, e.last_name, e.hire_date, e.dept_id, d.manager_user_id
             FROM `{$emp_table}` e
             LEFT JOIN `{$dept_table}` d ON d.id = e.dept_id
             WHERE e.status = 'active'
               AND e.hire_date IS NOT NULL
               AND MONTH(e.hire_date) = MONTH(%s)
               AND DAY(e.hire_date) = DAY(%s)
               AND YEAR(e.hire_date) < YEAR(%s)",
            $today,
            $today,
            $today
        ) );

        if ( empty( $rows ) ) {
            return;
        }

        foreach ( $rows as $row ) {
            $manager_email = $this->get_manager_email( (int) $row->dept_id );
            if ( ! $manager_email ) {
                continue;
            }

            $years   = (int) wp_date( 'Y' ) - (int) date( 'Y', strtotime( $row->hire_date ) );
            $name    = trim( $row->first_name . ' ' . $row->last_name );
            $subject = sprintf( __( 'Work Anniversary: %s (%d years)', 'sfs-hr' ), $name, $years );
            $body    = sprintf(
                __( "Today marks %1\$d year(s) since %2\$s joined the company. You may wish to acknowledge this milestone.", 'sfs-hr' ),
                $years,
                $name
            );

            Helpers::send_mail( $manager_email, $subject, $body );
        }
    }

    public function get_manager_email( int $dept_id ): ?string {
        if ( $dept_id <= 0 ) {
            return null;
        }

        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $manager_user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT manager_user_id FROM `{$dept_table}` WHERE id = %d",
            $dept_id
        ) );

        if ( empty( $manager_user_id ) ) {
            return null;
        }

        $user = get_user_by( 'id', $manager_user_id );
        if ( ! $user || ! is_email( $user->user_email ) ) {
            return null;
        }

        return $user->user_email;
    }

    public function get_hr_emails(): array {
        $emails = [];
        $users  = get_users( [
            'capability' => 'sfs_hr.manage',
            'fields'     => [ 'user_email' ],
        ] );

        foreach ( $users as $user ) {
            if ( ! empty( $user->user_email ) && is_email( $user->user_email ) ) {
                $emails[] = $user->user_email;
            }
        }

        return $emails;
    }

    private function get_hr_responsible_email( object $row ): ?string {
        if ( empty( $row->hr_responsible_user_id ) ) {
            return null;
        }

        $user = get_user_by( 'id', $row->hr_responsible_user_id );
        if ( ! $user || ! is_email( $user->user_email ) ) {
            return null;
        }

        return $user->user_email;
    }
}
