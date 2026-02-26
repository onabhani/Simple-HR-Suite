<?php
/**
 * Uninstall handler for Simple HR Suite.
 *
 * INTENTIONAL DATA RETENTION: This handler only removes the plugin's main
 * settings option.  Custom database tables, per-module options, roles,
 * capabilities, and user meta are deliberately preserved so that
 * re-activating the plugin restores all employee/attendance/payroll data.
 *
 * If full data removal is required (e.g. GDPR right-to-erasure), an admin
 * should drop the sfs_hr_* tables and delete sfs_hr_* options manually, or
 * use a dedicated cleanup tool.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'sfs_hr_settings' );
