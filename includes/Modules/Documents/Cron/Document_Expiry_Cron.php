<?php
namespace SFS\HR\Modules\Documents\Cron;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Document_Expiry_Cron {

    const CRON_HOOK = 'sfs_hr_document_expiry_check';

    public static function register(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
        add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
    }

    public static function run(): void {
        $expired_count  = self::expire_documents();
        $reminder_count = self::send_expiry_reminders();

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR Documents] Expiry check: %d expired, %d reminders sent.',
                $expired_count,
                $reminder_count
            ) );
        }
    }

    public static function expire_documents(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'sfs_hr_employee_documents';

        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}` SET status = 'expired', updated_at = %s WHERE expiry_date < %s AND status = 'active'",
            current_time( 'mysql' ),
            wp_date( 'Y-m-d' )
        ) );

        if ( $wpdb->last_error ) {
            error_log( sprintf( '[SFS HR Documents] expire_documents failed: %s', $wpdb->last_error ) );
            return 0;
        }

        return (int) $affected;
    }

    public static function send_expiry_reminders(): int {
        global $wpdb;

        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $sent      = 0;

        foreach ( [ 30, 15, 7 ] as $threshold ) {
            $docs = $wpdb->get_results( $wpdb->prepare(
                "SELECT d.id, d.document_name, d.document_type, d.expiry_date, e.user_id
                 FROM `{$doc_table}` d
                 JOIN `{$emp_table}` e ON e.id = d.employee_id
                 WHERE d.expiry_date = DATE_ADD(%s, INTERVAL %d DAY)
                   AND d.status = 'active'
                   AND e.status = 'active'",
                wp_date( 'Y-m-d' ),
                $threshold
            ) );

            if ( empty( $docs ) ) {
                continue;
            }

            foreach ( $docs as $doc ) {
                if ( empty( $doc->user_id ) ) {
                    continue;
                }

                $user = get_user_by( 'id', $doc->user_id );
                if ( ! $user || ! $user->user_email ) {
                    continue;
                }

                $subject = sprintf(
                    __( 'Document Expiring: %s', 'sfs-hr' ),
                    $doc->document_name
                );

                $body = sprintf(
                    __( 'Your document %1$s (%2$s) will expire on %3$s. Please upload an updated version.', 'sfs-hr' ),
                    $doc->document_name,
                    $doc->document_type,
                    wp_date( get_option( 'date_format' ), strtotime( $doc->expiry_date ) )
                );

                Helpers::send_mail( $user->user_email, $subject, $body );
                $sent++;
            }

            // Notify HR for 7-day threshold
            if ( $threshold === 7 ) {
                $hr_emails = self::get_hr_emails();
                if ( ! empty( $hr_emails ) ) {
                    foreach ( $docs as $doc ) {
                        $user = $doc->user_id ? get_user_by( 'id', $doc->user_id ) : null;
                        $employee_name = $user ? $user->display_name : __( 'Unknown', 'sfs-hr' );

                        $subject = sprintf(
                            __( 'Document Expiring in 7 Days: %s', 'sfs-hr' ),
                            $doc->document_name
                        );

                        $body = sprintf(
                            __( 'The document %1$s (%2$s) for employee %3$s will expire on %4$s. Please follow up to ensure an updated version is uploaded.', 'sfs-hr' ),
                            $doc->document_name,
                            $doc->document_type,
                            $employee_name,
                            wp_date( get_option( 'date_format' ), strtotime( $doc->expiry_date ) )
                        );

                        Helpers::send_mail( $hr_emails, $subject, $body );
                        $sent++;
                    }
                }
            }
        }

        return $sent;
    }

    public static function get_hr_emails(): array {
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
}
