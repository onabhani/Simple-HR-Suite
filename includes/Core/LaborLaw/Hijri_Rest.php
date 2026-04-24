<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Rest\Rest_Response;

/**
 * Hijri_Rest (M8.4)
 *
 * Public REST surface for Hijri calendar operations. Enables the
 * frontend (and any external client) to convert dates, fetch today's
 * Hijri date, enumerate Islamic holidays for a year, and read the
 * Ramadan range — without reimplementing the calendar logic on the
 * client side.
 *
 * All endpoints are publicly readable (Hijri conversion is not
 * sensitive data); callers that need authentication should layer it
 * externally. Rate limits may be applied via v2 endpoints later.
 *
 * @since M8.4
 */
class Hijri_Rest {

	const NS = 'sfs-hr/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/hijri/today', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'today' ],
			'permission_callback' => '__return_true',
		] );

		$validate_year = function ( $value ) {
			if ( $value === null || $value === '' ) {
				return true; // optional — handler defaults to current year.
			}
			$v = (int) $value;
			return ( $v >= 1900 && $v <= 2200 )
				? true
				: new \WP_Error( 'rest_invalid_param', __( 'year must be between 1900 and 2200.', 'sfs-hr' ), [ 'status' => 400 ] );
		};

		register_rest_route( self::NS, '/hijri/convert', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'convert' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'date' => [
					'type'              => 'string',
					'description'       => 'Gregorian date Y-m-d',
					'validate_callback' => function ( $value ) {
						if ( $value === null || $value === '' ) {
							return true;
						}
						return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value )
							? true
							: new \WP_Error( 'rest_invalid_param', __( 'date must be YYYY-MM-DD.', 'sfs-hr' ), [ 'status' => 400 ] );
					},
				],
				'hijri_y' => [
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 9999,
					'validate_callback' => function ( $value ) {
						if ( $value === null || $value === '' ) {
							return true;
						}
						$v = (int) $value;
						return ( $v >= 1 && $v <= 9999 )
							? true
							: new \WP_Error( 'rest_invalid_param', __( 'hijri_y must be between 1 and 9999.', 'sfs-hr' ), [ 'status' => 400 ] );
					},
				],
				'hijri_m' => [
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 12,
					'validate_callback' => function ( $value ) {
						if ( $value === null || $value === '' ) {
							return true;
						}
						$v = (int) $value;
						return ( $v >= 1 && $v <= 12 )
							? true
							: new \WP_Error( 'rest_invalid_param', __( 'hijri_m must be between 1 and 12.', 'sfs-hr' ), [ 'status' => 400 ] );
					},
				],
				'hijri_d' => [
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 30,
					'validate_callback' => function ( $value ) {
						if ( $value === null || $value === '' ) {
							return true;
						}
						$v = (int) $value;
						return ( $v >= 1 && $v <= 30 )
							? true
							: new \WP_Error( 'rest_invalid_param', __( 'hijri_d must be between 1 and 30.', 'sfs-hr' ), [ 'status' => 400 ] );
					},
				],
			],
		] );

		register_rest_route( self::NS, '/hijri/holidays', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'holidays' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'year' => [
					'type'              => 'integer',
					'minimum'           => 1900,
					'maximum'           => 2200,
					'validate_callback' => $validate_year,
				],
			],
		] );

		register_rest_route( self::NS, '/hijri/ramadan', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'ramadan' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'year' => [
					'type'              => 'integer',
					'minimum'           => 1900,
					'maximum'           => 2200,
					'validate_callback' => $validate_year,
				],
			],
		] );
	}

	/* ───────── Handlers ───────── */

	public static function today( \WP_REST_Request $req ) {
		$today_ymd = current_time( 'Y-m-d' );
		$h         = Hijri_Service::to_hijri( $today_ymd );

		return Rest_Response::success( [
			'gregorian'     => $today_ymd,
			'hijri'         => $h,
			'hijri_short'   => Hijri_Service::format( $today_ymd, 'short' ),
			'hijri_full'    => Hijri_Service::format( $today_ymd, 'full' ),
			'is_ramadan'    => Hijri_Service::is_ramadan( $today_ymd ),
			'is_holiday'    => Hijri_Service::is_islamic_holiday( $today_ymd ),
		] );
	}

	public static function convert( \WP_REST_Request $req ) {
		$date    = (string) $req->get_param( 'date' );
		$hijri_y = (int) $req->get_param( 'hijri_y' );
		$hijri_m = (int) $req->get_param( 'hijri_m' );
		$hijri_d = (int) $req->get_param( 'hijri_d' );

		// Gregorian → Hijri.
		if ( $date !== '' ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return Rest_Response::error( 'invalid_date', __( 'date must be YYYY-MM-DD.', 'sfs-hr' ), 400 );
			}
			$h = Hijri_Service::to_hijri( $date );
			if ( ! $h['y'] ) {
				return Rest_Response::error( 'conversion_failed', __( 'Could not convert date.', 'sfs-hr' ), 400 );
			}
			return Rest_Response::success( [
				'gregorian'   => $date,
				'hijri'       => $h,
				'hijri_short' => Hijri_Service::format( $date, 'short' ),
				'hijri_full'  => Hijri_Service::format( $date, 'full' ),
			] );
		}

		// Hijri → Gregorian.
		if ( $hijri_y > 0 && $hijri_m > 0 && $hijri_d > 0 ) {
			$g = Hijri_Service::to_gregorian( $hijri_y, $hijri_m, $hijri_d );
			if ( $g === '' ) {
				return Rest_Response::error( 'conversion_failed', __( 'Could not convert Hijri date.', 'sfs-hr' ), 400 );
			}
			return Rest_Response::success( [
				'hijri'     => [ 'y' => $hijri_y, 'm' => $hijri_m, 'd' => $hijri_d ],
				'gregorian' => $g,
			] );
		}

		return Rest_Response::error(
			'invalid_input',
			__( 'Provide either ?date=YYYY-MM-DD or all of ?hijri_y, ?hijri_m, ?hijri_d.', 'sfs-hr' ),
			400
		);
	}

	public static function holidays( \WP_REST_Request $req ) {
		$year = (int) $req->get_param( 'year' );
		if ( $year <= 0 ) {
			$year = (int) current_time( 'Y' );
		}
		return Rest_Response::success( [
			'year'     => $year,
			'holidays' => Hijri_Service::islamic_holidays_for_year( $year ),
		] );
	}

	public static function ramadan( \WP_REST_Request $req ) {
		$year = (int) $req->get_param( 'year' );
		if ( $year <= 0 ) {
			$year = (int) current_time( 'Y' );
		}
		$range = Hijri_Service::ramadan_range_for_year( $year );
		return Rest_Response::success( array_merge( [ 'year' => $year ], $range ) );
	}
}
