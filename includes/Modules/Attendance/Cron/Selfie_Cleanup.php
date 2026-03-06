<?php
namespace SFS\HR\Modules\Attendance\Cron;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Daily cron that deletes selfie attachments older than the configured
 * retention period (selfie_retention_days).
 *
 * Selfies are stored as WordPress attachments referenced by
 * selfie_media_id in the punches table.  After deletion the column
 * is set to NULL so no orphaned references remain.
 */
class Selfie_Cleanup {

    const CRON_HOOK  = 'sfs_hr_selfie_cleanup';
    const BATCH_SIZE = 100;

    /**
     * Register hooks.
     */
    public function hooks(): void {
        add_action( 'init', [ $this, 'schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'run' ] );
    }

    /**
     * Schedule the cron job (runs once daily).
     */
    public function schedule(): void {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }
        wp_schedule_event( time(), 'daily', self::CRON_HOOK );
    }

    /**
     * Delete selfie attachments whose punch_time is older than
     * selfie_retention_days.  Processes in batches to avoid memory
     * pressure on large installations.
     */
    public function run(): void {
        global $wpdb;

        $settings       = get_option( 'sfs_hr_attendance_settings', [] );
        $retention_days = max( 1, (int) ( $settings['selfie_retention_days'] ?? 30 ) );

        $punches_table = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $cutoff        = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * 86400 ) );

        $total_deleted = 0;

        // Process in batches to keep memory usage bounded.
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, selfie_media_id
                 FROM `{$punches_table}`
                 WHERE selfie_media_id IS NOT NULL
                   AND punch_time < %s
                 LIMIT %d",
                $cutoff,
                self::BATCH_SIZE
            ) );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                // Delete the physical file + attachment post.
                wp_delete_attachment( (int) $row->selfie_media_id, true );

                // Clear the reference so the punch record stays clean.
                $wpdb->update(
                    $punches_table,
                    [ 'selfie_media_id' => null ],
                    [ 'id' => (int) $row->id ],
                    [ null ],
                    [ '%d' ]
                );

                $total_deleted++;
            }
        } while ( count( $rows ) === self::BATCH_SIZE );

        if ( $total_deleted > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SFS HR] Selfie cleanup: deleted %d selfie attachment(s) older than %d day(s).',
                $total_deleted,
                $retention_days
            ) );
        }
    }
}
