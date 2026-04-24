<?php
namespace SFS\HR\Core\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Rate_Limiter (M9.2)
 *
 * Transient-backed per-user rate limiter for v2 endpoints. Each route
 * declares its limit via register_rest_route args; the limiter enforces
 * it in a permission_callback wrapper and emits standard X-RateLimit-*
 * headers on the response.
 *
 * Storage: WordPress transients (object cache when available, DB
 * otherwise). Accuracy is "good enough" — race conditions between
 * concurrent requests may allow a handful of requests over the limit,
 * but the limiter is meant to deter abuse, not enforce billing.
 *
 * Usage:
 *   register_rest_route( 'sfs-hr/v2', '/example', [
 *       'methods'             => 'GET',
 *       'callback'            => ...,
 *       'permission_callback' => Rate_Limiter::gate(
 *           fn() => current_user_can( 'sfs_hr.view' ),
 *           [ 'limit' => 60, 'window' => MINUTE_IN_SECONDS ]
 *       ),
 *   ] );
 *
 * Identification:
 *   - Authenticated users: user ID
 *   - Unauthenticated (fallback for public endpoints): IP address
 *
 * @since M9.2
 */
class Rate_Limiter {

	const DEFAULT_LIMIT  = 60;
	const DEFAULT_WINDOW = 60; // 1 minute.

	/** @var array<string, array{limit:int,window:int,remaining:int,reset:int,exceeded:bool}> */
	private static $last_state = [];

	/**
	 * Build a permission_callback that enforces rate limits on top of an
	 * existing authorization check.
	 *
	 * @param callable $auth_check   Existing permission_callback (returns bool|WP_Error).
	 * @param array    $opts         [ 'limit' => int, 'window' => seconds, 'bucket' => string ]
	 * @return \Closure
	 */
	public static function gate( callable $auth_check, array $opts = [] ): \Closure {
		$limit  = (int) ( $opts['limit']  ?? self::DEFAULT_LIMIT );
		$window = (int) ( $opts['window'] ?? self::DEFAULT_WINDOW );
		$bucket = (string) ( $opts['bucket'] ?? '' );

		return function ( \WP_REST_Request $req ) use ( $auth_check, $limit, $window, $bucket ) {
			$auth = $auth_check( $req );
			if ( $auth !== true && ! ( $auth === null ) ) {
				// Preserve WP_Error / false — rate limit doesn't apply if auth fails.
				return $auth;
			}

			$identity = self::identify( $req );
			$key      = self::make_key( $identity, $bucket ?: $req->get_route() );
			$state    = self::consume( $key, $limit, $window );

			self::$last_state[ $req->get_route() ] = $state;

			if ( $state['exceeded'] ) {
				return new \WP_Error(
					'rate_limit_exceeded',
					__( 'Too many requests. Please try again later.', 'sfs-hr' ),
					[
						'status'     => 429,
						'limit'      => $limit,
						'window'     => $window,
						'retry_after' => max( 1, $state['reset'] - time() ),
					]
				);
			}

			return $auth;
		};
	}

	/**
	 * Emit X-RateLimit-* headers on the response. Hook via rest_post_dispatch.
	 *
	 * Called by V2_Bootstrap::attach_headers — kept here so all header logic
	 * lives next to the consumer.
	 */
	public static function attach_headers_for_request( \WP_REST_Response $response, \WP_REST_Request $request ): \WP_REST_Response {
		$route = $request->get_route();
		if ( ! isset( self::$last_state[ $route ] ) ) {
			return $response;
		}

		$state = self::$last_state[ $route ];
		$response->header( 'X-RateLimit-Limit',     (string) $state['limit'] );
		$response->header( 'X-RateLimit-Remaining', (string) $state['remaining'] );
		$response->header( 'X-RateLimit-Reset',     (string) $state['reset'] );

		if ( $state['exceeded'] ) {
			$response->header( 'Retry-After', (string) max( 1, $state['reset'] - time() ) );
		}

		return $response;
	}

	/**
	 * Identify the caller. Prefers authenticated user ID; falls back to IP.
	 */
	private static function identify( \WP_REST_Request $req ): string {
		$uid = get_current_user_id();
		if ( $uid > 0 ) {
			return 'u:' . $uid;
		}

		// Trust REMOTE_ADDR only — do not trust XFF headers without an
		// explicit proxy allowlist (out of scope here).
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		return 'ip:' . $ip;
	}

	private static function make_key( string $identity, string $bucket ): string {
		return 'sfs_hr_rl_' . substr( md5( $identity . '|' . $bucket ), 0, 20 );
	}

	/**
	 * Consume one request from the bucket and return updated state.
	 *
	 * @return array{limit:int,window:int,remaining:int,reset:int,exceeded:bool}
	 */
	private static function consume( string $key, int $limit, int $window ): array {
		$now = time();

		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || ! isset( $stored['count'], $stored['reset'] ) || $stored['reset'] <= $now ) {
			$stored = [ 'count' => 0, 'reset' => $now + $window ];
		}

		$stored['count']++;

		// Store with TTL matching the remaining window.
		$ttl = max( 1, $stored['reset'] - $now );
		set_transient( $key, $stored, $ttl );

		$remaining = max( 0, $limit - $stored['count'] );
		$exceeded  = $stored['count'] > $limit;

		return [
			'limit'     => $limit,
			'window'    => $window,
			'remaining' => $remaining,
			'reset'     => (int) $stored['reset'],
			'exceeded'  => $exceeded,
		];
	}
}
