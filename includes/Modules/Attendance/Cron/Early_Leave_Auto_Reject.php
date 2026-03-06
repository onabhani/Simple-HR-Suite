<?php
namespace SFS\HR\Modules\Attendance\Cron;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Auto-reject early leave requests that have been pending for more than 72 hours.
 */
class Early_Leave_Auto_Reject {

    const CRON_HOOK    = 'sfs_hr_early_leave_auto_reject';
    const EXPIRY_HOURS = 72;

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
     * Auto-reject pending early leave requests older than 72 hours.
     */
    public function run(): void {
        global $wpdb;

        $table    = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( self::EXPIRY_HOURS * 3600 ) );
        $now      = current_time( 'mysql' );

        // Find all pending requests created more than 72 hours ago
        $expired = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, employee_id, request_date
             FROM `{$table}`
             WHERE status = 'pending' AND created_at <= %s",
            $cutoff
        ) );

        if ( empty( $expired ) ) {
            return;
        }

        $ids = wp_list_pluck( $expired, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}`
             SET status       = 'rejected',
                 reviewed_by  = NULL,
                 reviewed_at  = %s,
                 manager_note = %s,
                 affects_salary = 1,
                 updated_at   = %s
             WHERE id IN ({$placeholders})",
            array_merge(
                [ $now, __( 'Auto-rejected: no action was taken within 3 days.', 'sfs-hr' ), $now ],
                $ids
            )
        ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Early leave auto-reject: rejected %d expired request(s).',
                count( $ids )
            ) );
        }
    }
}
