<?php
namespace SFS\HR\Core\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * OpenAPI_Generator (M9.2)
 *
 * Introspects registered REST routes for a given namespace and emits an
 * OpenAPI 3.1 document. Designed to run on-demand (cheap — no network,
 * no DB) so no caching layer is needed; downstream clients can cache
 * the JSON with their own headers.
 *
 * Scope: v2 endpoints only (sfs-hr/v2). v1 endpoints were not designed
 * with schemas in mind; generating a spec for them would be misleading.
 *
 * Strategy:
 *   - Walk rest_get_server()->get_routes() filtered by namespace prefix
 *   - Convert WP path regex (?P<id>\d+) to OpenAPI {id} + parameter entry
 *   - Map WP arg 'type' (string/integer/number/boolean/array/object) to
 *     OpenAPI schema directly (WP uses JSON Schema draft-04 subset)
 *   - Infer required parameters from arg 'required' flag
 *   - Security: declare Bearer token auth scheme (from M9 Api_Key_Service)
 *
 * Limitations:
 *   - Request/response bodies are not introspected from callbacks (WP
 *     doesn't expose return type metadata). Endpoints that want rich
 *     response schemas can register a 'schema' key in their route args
 *     and this generator will honor it.
 *   - Permission callbacks are opaque — we can note "requires auth" but
 *     not enumerate exact capabilities without a registry.
 *
 * @since M9.2
 */
class OpenAPI_Generator {

	const OPENAPI_VERSION = '3.1.0';

	/**
	 * Generate the OpenAPI document as a PHP array. JSON-encode at the
	 * response boundary.
	 *
	 * @param string $namespace e.g. 'sfs-hr/v2'
	 */
	public static function generate( string $namespace = 'sfs-hr/v2' ): array {
		$server = rest_get_server();
		$routes = $server->get_routes();

		$paths = [];

		foreach ( $routes as $route => $handlers ) {
			if ( strpos( ltrim( $route, '/' ), $namespace . '/' ) !== 0
				&& ltrim( $route, '/' ) !== $namespace ) {
				continue;
			}

			[ $openapi_path, $path_params ] = self::convert_path( $route, $namespace );
			$path_item                       = [];

			foreach ( $handlers as $handler ) {
				foreach ( $handler['methods'] as $method => $enabled ) {
					if ( ! $enabled ) {
						continue;
					}
					$http_method = strtolower( $method );
					if ( ! in_array( $http_method, [ 'get', 'post', 'put', 'patch', 'delete' ], true ) ) {
						continue;
					}

					$path_item[ $http_method ] = self::build_operation(
						$route,
						$handler,
						$path_params,
						$namespace
					);
				}
			}

			if ( ! empty( $path_item ) ) {
				$paths[ $openapi_path ] = $path_item;
			}
		}

		return [
			'openapi' => self::OPENAPI_VERSION,
			'info'    => self::build_info(),
			'servers' => self::build_servers(),
			'paths'   => $paths,
			'components' => [
				'securitySchemes' => [
					'bearerAuth' => [
						'type'         => 'http',
						'scheme'       => 'bearer',
						'description'  => 'Bearer token issued via the Developer → API Keys admin page.',
					],
					'cookieAuth' => [
						'type' => 'apiKey',
						'in'   => 'cookie',
						'name' => 'wordpress_logged_in',
					],
				],
				'schemas' => self::build_common_schemas(),
			],
			'security' => [
				[ 'bearerAuth' => [] ],
				[ 'cookieAuth' => [] ],
			],
		];
	}

	private static function build_info(): array {
		$plugin_data = get_file_data( SFS_HR_PLUGIN_FILE, [
			'Name'        => 'Plugin Name',
			'Version'     => 'Version',
			'Description' => 'Description',
		] );

		return [
			'title'       => ( $plugin_data['Name'] ?: 'Simple HR Suite' ) . ' — API v2',
			'version'     => $plugin_data['Version'] ?: (string) ( defined( 'SFS_HR_VER' ) ? SFS_HR_VER : '0.0.0' ),
			'description' => trim( $plugin_data['Description'] ?: '' ) ?: 'REST API v2 for Simple HR Suite.',
		];
	}

	private static function build_servers(): array {
		return [
			[
				'url'         => esc_url_raw( rest_url() ),
				'description' => 'Current site REST root',
			],
		];
	}

	private static function build_common_schemas(): array {
		return [
			'Error' => [
				'type'     => 'object',
				'required' => [ 'code', 'message' ],
				'properties' => [
					'code'    => [ 'type' => 'string', 'description' => 'Machine-readable error code.' ],
					'message' => [ 'type' => 'string', 'description' => 'Human-readable message.' ],
					'data'    => [
						'type'       => 'object',
						'properties' => [
							'status' => [ 'type' => 'integer' ],
							'errors' => [
								'type'        => 'object',
								'description' => 'Optional field-level errors.',
								'additionalProperties' => true,
							],
						],
					],
				],
			],
			'Meta' => [
				'type'       => 'object',
				'properties' => [
					'page'        => [ 'type' => 'integer' ],
					'per_page'    => [ 'type' => 'integer' ],
					'total'       => [ 'type' => 'integer' ],
					'total_pages' => [ 'type' => 'integer' ],
					'has_next'    => [ 'type' => 'boolean' ],
					'has_prev'    => [ 'type' => 'boolean' ],
					'timestamp'   => [ 'type' => 'string', 'format' => 'date-time' ],
				],
			],
		];
	}

	/**
	 * Convert a WP REST route regex into an OpenAPI path + extracted path params.
	 *
	 * @return array{0:string, 1:array<string, array>}
	 */
	private static function convert_path( string $route, string $namespace ): array {
		// Strip leading slash + namespace prefix.
		$path = ltrim( $route, '/' );
		if ( strpos( $path, $namespace ) === 0 ) {
			$path = substr( $path, strlen( $namespace ) );
		}
		if ( $path === '' ) {
			$path = '/';
		} elseif ( $path[0] !== '/' ) {
			$path = '/' . $path;
		}

		$path_params = [];

		// Match patterns like (?P<name>pattern)
		$path = preg_replace_callback(
			'/\(\?P<([a-zA-Z_][a-zA-Z0-9_]*)>([^)]+)\)/',
			function ( $matches ) use ( &$path_params ) {
				$name    = $matches[1];
				$pattern = $matches[2];

				$schema = [ 'type' => 'string' ];
				if ( $pattern === '\d+' || $pattern === '[0-9]+' ) {
					$schema = [ 'type' => 'integer', 'format' => 'int64', 'minimum' => 1 ];
				} elseif ( strpos( $pattern, '[a-zA-Z]' ) !== false || strpos( $pattern, '[a-z]' ) !== false ) {
					$schema = [ 'type' => 'string', 'pattern' => $pattern ];
				}

				$path_params[ $name ] = [
					'name'     => $name,
					'in'       => 'path',
					'required' => true,
					'schema'   => $schema,
				];

				return '{' . $name . '}';
			},
			$path
		);

		return [ $path, $path_params ];
	}

	private static function build_operation( string $route, array $handler, array $path_params, string $namespace ): array {
		$args = $handler['args'] ?? [];
		$op_id = self::make_operation_id( $route, $namespace );

		$parameters = array_values( $path_params );

		// Query parameters from handler args that are not already path params.
		foreach ( $args as $name => $spec ) {
			if ( isset( $path_params[ $name ] ) ) {
				continue;
			}
			$parameters[] = [
				'name'        => $name,
				'in'          => 'query',
				'required'    => ! empty( $spec['required'] ),
				'description' => (string) ( $spec['description'] ?? '' ),
				'schema'      => self::arg_to_schema( $spec ),
			];
		}

		$operation = [
			'operationId' => $op_id,
			'tags'        => [ self::derive_tag( $route, $namespace ) ],
			'summary'     => (string) ( $handler['summary'] ?? '' ),
			'parameters'  => $parameters,
			'responses'   => [
				'200' => [
					'description' => 'Successful response',
					'content'     => [
						'application/json' => [
							'schema' => isset( $handler['schema'] )
								? self::sanitize_schema( $handler['schema'] )
								: [ 'type' => 'object' ],
						],
					],
				],
				'400' => self::error_response_ref( 'Bad request' ),
				'401' => self::error_response_ref( 'Unauthorized' ),
				'403' => self::error_response_ref( 'Forbidden' ),
				'404' => self::error_response_ref( 'Not found' ),
				'429' => self::error_response_ref( 'Too many requests' ),
			],
		];

		if ( empty( $operation['summary'] ) ) {
			unset( $operation['summary'] );
		}
		if ( empty( $operation['parameters'] ) ) {
			unset( $operation['parameters'] );
		}

		return $operation;
	}

	private static function error_response_ref( string $description ): array {
		return [
			'description' => $description,
			'content'     => [
				'application/json' => [
					'schema' => [ '$ref' => '#/components/schemas/Error' ],
				],
			],
		];
	}

	private static function arg_to_schema( array $spec ): array {
		$schema = [];

		$type = $spec['type'] ?? 'string';
		// WP REST can specify multiple types as array; OpenAPI 3.1 supports
		// this too, but keep it simple and pick the first.
		if ( is_array( $type ) ) {
			$type = $type[0] ?? 'string';
		}
		$schema['type'] = $type;

		if ( isset( $spec['default'] ) ) {
			$schema['default'] = $spec['default'];
		}
		if ( isset( $spec['enum'] ) && is_array( $spec['enum'] ) ) {
			$schema['enum'] = array_values( $spec['enum'] );
		}
		if ( isset( $spec['minimum'] ) ) {
			$schema['minimum'] = $spec['minimum'];
		}
		if ( isset( $spec['maximum'] ) ) {
			$schema['maximum'] = $spec['maximum'];
		}
		if ( isset( $spec['format'] ) ) {
			$schema['format'] = $spec['format'];
		}

		return $schema;
	}

	private static function sanitize_schema( array $schema ): array {
		// Pass-through; callers registering a 'schema' key are expected to
		// provide valid JSON Schema. Deep sanitization would risk dropping
		// legitimate properties.
		return $schema;
	}

	private static function derive_tag( string $route, string $namespace ): string {
		$path = ltrim( $route, '/' );
		if ( strpos( $path, $namespace ) === 0 ) {
			$path = trim( substr( $path, strlen( $namespace ) ), '/' );
		}
		$first = strtok( $path, '/' );
		return $first ? ucfirst( $first ) : 'Default';
	}

	private static function make_operation_id( string $route, string $namespace ): string {
		$path = ltrim( $route, '/' );
		if ( strpos( $path, $namespace ) === 0 ) {
			$path = substr( $path, strlen( $namespace ) );
		}
		$path = preg_replace( '/\(\?P<([a-zA-Z_]+)>[^)]+\)/', '{$1}', $path );
		$path = preg_replace( '/[^a-zA-Z0-9]+/', '_', (string) $path );
		return trim( (string) $path, '_' );
	}
}
