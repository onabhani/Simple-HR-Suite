<?php
namespace SFS\HR\Core\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * V2_Bootstrap (M9.2)
 *
 * Registers the sfs-hr/v2 namespace. Responsibilities:
 *   - Register the OpenAPI introspection endpoint at /v2/openapi.json
 *   - Register reference v2 controllers
 *   - Attach rate-limit headers to v2 responses
 *
 * v1 continues to exist untouched. v2 endpoints are additive.
 *
 * @since M9.2
 */
class V2_Bootstrap {

	const NAMESPACE_V2 = 'sfs-hr/v2';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
		add_filter( 'rest_post_dispatch', [ self::class, 'decorate_response' ], 10, 3 );
	}

	public static function register_routes(): void {
		// OpenAPI introspection.
		register_rest_route( self::NAMESPACE_V2, '/openapi.json', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'openapi' ],
			'permission_callback' => '__return_true',
			'summary'             => 'OpenAPI 3.1 specification for v2 endpoints',
		] );

		// Reference endpoints.
		V2_Employees_Controller::register();
	}

	public static function openapi( \WP_REST_Request $req ): \WP_REST_Response {
		$spec = OpenAPI_Generator::generate( self::NAMESPACE_V2 );

		$response = new \WP_REST_Response( $spec, 200 );
		$response->header( 'Content-Type', 'application/json' );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * Attach rate-limit headers for v2 responses. Also sets a hint header
	 * so clients can detect they hit v2.
	 */
	public static function decorate_response( $response, $server, $request ) {
		if ( ! ( $response instanceof \WP_REST_Response ) ) {
			return $response;
		}

		$route = (string) $request->get_route();
		if ( strpos( ltrim( $route, '/' ), self::NAMESPACE_V2 ) !== 0 ) {
			return $response;
		}

		$response->header( 'X-SFS-HR-API-Version', '2' );
		return Rate_Limiter::attach_headers_for_request( $response, $request );
	}
}
