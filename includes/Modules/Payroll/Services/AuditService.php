<?php
namespace SFS\HR\Modules\Payroll\Services;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AuditService
 *
 * Centralized payroll audit trail. Logs every significant payroll action to
 * `sfs_hr_payroll_audit_log` and provides retrieval methods for admin UIs and
 * API endpoints.
 *
 * Design goals:
 *   - NEVER throws — log failures are silently swallowed so a logging error
 *     can never break a payroll operation.
 *   - All reads JOIN wp_users for human-readable actor names.
 *   - All queries use $wpdb->prepare() — no raw interpolation.
 *   - Table creation is handled by Migrations; this class only reads/writes.
 */
class AuditService {

	// ---------------------------------------------------------------------------
	// Valid enum values — enforced on write so bad callers can't pollute the log.
	// ---------------------------------------------------------------------------

	private const VALID_ENTITY_TYPES = [
		'run', 'item', 'adjustment', 'period',
	];

	private const VALID_ACTIONS = [
		'created', 'calculated', 'approved', 'rejected',
		'paid', 'reversed', 'adjusted', 'locked', 'reopened',
	];

	// ---------------------------------------------------------------------------
	// Write
	// ---------------------------------------------------------------------------

	/**
	 * Log a payroll audit event.
	 *
	 * @param string $entity_type One of: run, item, adjustment, period.
	 * @param int    $entity_id   Primary key of the affected entity.
	 * @param string $action      One of: created, calculated, approved, rejected,
	 *                            paid, reversed, adjusted, locked, reopened.
	 * @param int    $actor_id    WordPress user ID performing the action.
	 * @param array  $details     Optional before/after snapshots, amounts, reasons.
	 *                            Encoded to JSON automatically.
	 *
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public static function log(
		string $entity_type,
		int $entity_id,
		string $action,
		int $actor_id,
		array $details = []
	): int {
		global $wpdb;

		// Reject invalid enum values — log a PHP warning rather than silently
		// inserting garbage that makes audit reports meaningless.
		if ( ! in_array( $entity_type, self::VALID_ENTITY_TYPES, true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			trigger_error(
				sprintf( 'AuditService::log() — unknown entity_type "%s"', $entity_type ),
				E_USER_WARNING
			);
			return 0;
		}

		if ( ! in_array( $action, self::VALID_ACTIONS, true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			trigger_error(
				sprintf( 'AuditService::log() — unknown action "%s"', $action ),
				E_USER_WARNING
			);
			return 0;
		}

		$ip = self::get_client_ip();

		$result = $wpdb->insert(
			$wpdb->prefix . 'sfs_hr_payroll_audit_log',
			[
				'entity_type'  => $entity_type,
				'entity_id'    => $entity_id,
				'action'       => $action,
				'actor_id'     => $actor_id,
				'details_json' => ! empty( $details ) ? wp_json_encode( $details ) : null,
				'ip_address'   => $ip,
				'created_at'   => Helpers::now_mysql(),
			],
			[ '%s', '%d', '%s', '%d', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	// ---------------------------------------------------------------------------
	// Read — single entity
	// ---------------------------------------------------------------------------

	/**
	 * Get audit trail for a specific entity.
	 *
	 * Each row includes a `actor_display_name` column sourced from wp_users.
	 *
	 * @param string $entity_type One of: run, item, adjustment, period.
	 * @param int    $entity_id   Primary key of the entity.
	 * @param int    $limit       Maximum rows to return (1–500).
	 *
	 * @return array<int, array<string, mixed>> Rows ordered newest-first.
	 */
	public static function get_trail(
		string $entity_type,
		int $entity_id,
		int $limit = 50
	): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );

		$table = $wpdb->prefix . 'sfs_hr_payroll_audit_log';
		$users = $wpdb->users;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, u.display_name AS actor_display_name
				 FROM {$table} a
				 LEFT JOIN {$users} u ON u.ID = a.actor_id
				 WHERE a.entity_type = %s
				   AND a.entity_id   = %d
				 ORDER BY a.id DESC
				 LIMIT %d",
				$entity_type,
				$entity_id,
				$limit
			),
			ARRAY_A
		);

		return self::decode_rows( $rows ?: [] );
	}

	// ---------------------------------------------------------------------------
	// Read — payroll run (run + all its items and adjustments)
	// ---------------------------------------------------------------------------

	/**
	 * Get the full audit trail for a payroll run, including all items and
	 * adjustments that belong to it.
	 *
	 * Items and adjustments are identified by looking up their `run_id` foreign
	 * key in the respective tables, so the result covers everything that was
	 * modified as part of this run.
	 *
	 * @param int $run_id Primary key of the payroll run.
	 * @param int $limit  Maximum rows to return (1–1000).
	 *
	 * @return array<int, array<string, mixed>> Rows ordered newest-first.
	 */
	public static function get_run_trail( int $run_id, int $limit = 100 ): array {
		global $wpdb;

		$limit = max( 1, min( 1000, $limit ) );

		$audit  = $wpdb->prefix . 'sfs_hr_payroll_audit_log';
		$items  = $wpdb->prefix . 'sfs_hr_payroll_items';
		$adj    = $wpdb->prefix . 'sfs_hr_payroll_adjustments';
		$users  = $wpdb->users;

		/*
		 * Strategy:
		 *   1. All audit rows for the run itself                      (entity_type='run')
		 *   2. All audit rows for items that belong to this run       (entity_type='item')
		 *   3. All audit rows for adjustments that belong to this run (entity_type='adjustment')
		 *
		 * We use a UNION so a single query covers all three sets.
		 */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, u.display_name AS actor_display_name
				 FROM {$audit} a
				 LEFT JOIN {$users} u ON u.ID = a.actor_id
				 WHERE a.entity_type = 'run'
				   AND a.entity_id   = %d

				 UNION ALL

				 SELECT a.*, u.display_name AS actor_display_name
				 FROM {$audit} a
				 LEFT JOIN {$users} u ON u.ID = a.actor_id
				 INNER JOIN {$items} i ON i.id = a.entity_id
				 WHERE a.entity_type = 'item'
				   AND i.run_id      = %d

				 UNION ALL

				 SELECT a.*, u.display_name AS actor_display_name
				 FROM {$audit} a
				 LEFT JOIN {$users} u ON u.ID = a.actor_id
				 INNER JOIN {$adj} adj ON adj.id = a.entity_id
				 WHERE a.entity_type = 'adjustment'
				   AND adj.run_id    = %d

				 ORDER BY id DESC
				 LIMIT %d",
				$run_id,
				$run_id,
				$run_id,
				$limit
			),
			ARRAY_A
		);

		return self::decode_rows( $rows ?: [] );
	}

	// ---------------------------------------------------------------------------
	// Read — recent activity feed
	// ---------------------------------------------------------------------------

	/**
	 * Get recent activity across all payroll operations.
	 *
	 * Useful for dashboard widgets and admin activity feeds.
	 *
	 * @param int $limit  Number of rows per page (1–500).
	 * @param int $offset Row offset for pagination.
	 *
	 * @return array<int, array<string, mixed>> Rows ordered newest-first.
	 */
	public static function get_recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );

		$table = $wpdb->prefix . 'sfs_hr_payroll_audit_log';
		$users = $wpdb->users;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, u.display_name AS actor_display_name
				 FROM {$table} a
				 LEFT JOIN {$users} u ON u.ID = a.actor_id
				 ORDER BY a.id DESC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return self::decode_rows( $rows ?: [] );
	}

	// ---------------------------------------------------------------------------
	// Maintenance
	// ---------------------------------------------------------------------------

	/**
	 * Purge audit log entries older than $days days (retention policy).
	 *
	 * Safe to run from a WP-Cron job. Returns the number of rows deleted.
	 *
	 * @param int $days Minimum age in days before a row is eligible for deletion.
	 *                  Must be at least 30 to prevent accidental mass-deletion.
	 *
	 * @return int Number of rows deleted, or 0 on failure/nothing to do.
	 */
	public static function purge_old( int $days = 365 ): int {
		global $wpdb;

		// Safety floor: never purge data that is less than 30 days old.
		$days = max( 30, $days );

		$table  = $wpdb->prefix . 'sfs_hr_payroll_audit_log';
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);

		return ( false === $result ) ? 0 : (int) $result;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Decode details_json in a result set to native arrays.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw DB rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function decode_rows( array $rows ): array {
		foreach ( $rows as &$row ) {
			if ( isset( $row['details_json'] ) && null !== $row['details_json'] ) {
				$decoded = json_decode( $row['details_json'], true );
				$row['details'] = is_array( $decoded ) ? $decoded : [];
			} else {
				$row['details'] = [];
			}
			// Keep details_json for callers that want the raw string.
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Resolve the client IP address, respecting common reverse-proxy headers
	 * only when they are explicitly trusted (i.e., only the first hop).
	 *
	 * We do NOT blindly trust X-Forwarded-For because it can be spoofed.
	 * The result is stored as-is; the caller (admin UI) is responsible for
	 * any display escaping.
	 *
	 * @return string IPv4 or IPv6 address, up to 45 chars. Empty string on CLI.
	 */
	private static function get_client_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		// Truncate to column limit (VARCHAR 45) as a safety net.
		return substr( $ip, 0, 45 );
	}
}
