<?php
namespace SFS\HR\Modules\Attendance\Cron;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Auto-reject early leave requests that have been pending longer than the
 * configured auto_reject_days setting (default: 3 days).
 */
class Early_Leave_Auto_Reject {

    const CRON_HOOK = 'sfs_hr_early_leave_auto_reject';

    /**
     * Register hooks.
     */
    public function hooks(): void {
        add_action( 'init', [ $this, 'schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'run' ] );
    }

    /**
     * Schedule the cron job (runs twice daily).
     */
    public function schedule(): void {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }
        wp_schedule_event( time(), 'twicedaily', self::CRON_HOOK );
    }

    /**
     * Auto-reject pending early leave requests older than the configured expiry.
     */
    public function run(): void {
        global $wpdb;

        $table         = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $elr_settings  = get_option( 'sfs_hr_elr_settings', [] );
        if ( ! is_array( $elr_settings ) ) { $elr_settings = []; }
        $expiry_days   = max( 1, (int) ( $elr_settings['auto_reject_days'] ?? 3 ) );
        $affects_salary = (int) ( $elr_settings['affects_salary'] ?? 1 );
        $cutoff        = gmdate( 'Y-m-d H:i:s', time() - ( $expiry_days * 86400 ) );
        $now           = current_time( 'mysql', true );

        // Find all pending requests created more than the configured days ago
        $expired = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, employee_id, request_date
             FROM `{$table}`
             WHERE status = 'pending' AND created_at <= %s",
            $cutoff
        ) );

        if ( $wpdb->last_error ) {
            error_log( sprintf( '[SFS HR] Early leave auto-reject: SELECT failed — %s', $wpdb->last_error ) );
            return;
        }

        if ( empty( $expired ) ) {
            return;
        }

        $ids = wp_list_pluck( $expired, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $manager_note = sprintf(
            _n(
                'Auto-rejected: no action was taken within %d day.',
                'Auto-rejected: no action was taken within %d days.',
                $expiry_days,
                'sfs-hr'
            ),
            $expiry_days
        );

        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}`
             SET status       = 'rejected',
                 reviewed_by  = NULL,
                 reviewed_at  = %s,
                 manager_note = %s,
                 affects_salary = %d,
                 updated_at   = %s
             WHERE id IN ({$placeholders}) AND status = 'pending'",
            array_merge(
                [ $now, $manager_note, $affects_salary, $now ],
                $ids
            )
        ) );

        if ( $wpdb->last_error ) {
            error_log( sprintf( '[SFS HR] Early leave auto-reject: UPDATE failed — %s', $wpdb->last_error ) );
            return;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Early leave auto-reject: rejected %d of %d expired request(s).',
                (int) $affected,
                count( $ids )
            ) );
        }
    }
}
