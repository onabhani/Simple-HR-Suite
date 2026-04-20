<?php
namespace SFS\HR\Core\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Rest_Response
 *
 * M9.2 — Standard envelope + pagination helpers for new REST endpoints.
 *
 * Usage:
 *   return Rest_Response::success( $data );
 *   return Rest_Response::paginated( $rows, $total, $page, $per_page );
 *   return Rest_Response::error( 'not_found', 'Employee not found', 404 );
 *
 * The envelope is opt-in: existing endpoints continue to work with raw
 * payloads. All new endpoints should use this helper so clients see a
 * predictable shape.
 *
 * Success shape:
 *   {
 *     "data": <any>,
 *     "meta": { "timestamp": "..." }
 *   }
 *
 * Paginated shape:
 *   {
 *     "data": [ ... ],
 *     "meta": {
 *       "page": 1, "per_page": 20, "total": 137, "total_pages": 7,
 *       "has_next": true, "has_prev": false,
 *       "timestamp": "..."
 *     }
 *   }
 *
 * Error shape (via WP_Error — kept compatible with WP REST conventions):
 *   {
 *     "code": "not_found",
 *     "message": "Employee not found",
 *     "data": { "status": 404, "errors": { ... } }
 *   }
 *
 * @since M9
 */
class Rest_Response {

    /**
     * Wrap successful payloads in the standard envelope.
     *
     * @param mixed $data Data to return.
     * @param int   $status HTTP status code (default 200).
     */
    public static function success( $data, int $status = 200 ): \WP_REST_Response {
        return new \WP_REST_Response( [
            'data' => $data,
            'meta' => [
                'timestamp' => gmdate( 'c' ),
            ],
        ], $status );
    }

    /**
     * Paginated response with meta block.
     *
     * @param array $rows      Page of rows.
     * @param int   $total     Total number of rows available.
     * @param int   $page      Current page (1-indexed).
     * @param int   $per_page  Page size.
     */
    public static function paginated( array $rows, int $total, int $page, int $per_page ): \WP_REST_Response {
        $per_page = max( 1, $per_page );
        $total    = max( 0, $total );
        $total_pages = (int) ceil( $total / $per_page );

        $response = new \WP_REST_Response( [
            'data' => $rows,
            'meta' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $total_pages,
                'has_next'    => $page < $total_pages,
                'has_prev'    => $page > 1,
                'timestamp'   => gmdate( 'c' ),
            ],
        ], 200 );

        $response->header( 'X-Total-Count', (string) $total );
        $response->header( 'X-Page',        (string) $page );
        $response->header( 'X-Per-Page',    (string) $per_page );
        $response->header( 'X-Total-Pages', (string) $total_pages );

        return $response;
    }

    /**
     * Standardized error response via WP_Error (honored by the REST server).
     *
     * @param string $code    Machine-readable error code.
     * @param string $message Human-readable message.
     * @param int    $status  HTTP status code.
     * @param array  $errors  Optional field-level errors: [ 'field' => 'reason', ... ].
     */
    public static function error( string $code, string $message, int $status = 400, array $errors = [] ): \WP_Error {
        $data = [ 'status' => $status ];
        if ( ! empty( $errors ) ) {
            $data['errors'] = $errors;
        }
        return new \WP_Error( $code, $message, $data );
    }

    /**
     * Parse and validate common pagination parameters from a REST request.
     *
     * @return array{ page:int, per_page:int, offset:int }
     */
    public static function parse_pagination( \WP_REST_Request $req, int $default_per_page = 20, int $max_per_page = 100 ): array {
        $page     = max( 1, (int) ( $req->get_param( 'page' )     ?? 1 ) );
        $per_page = max( 1, min( $max_per_page, (int) ( $req->get_param( 'per_page' ) ?? $default_per_page ) ) );
        $offset   = ( $page - 1 ) * $per_page;
        return compact( 'page', 'per_page', 'offset' );
    }
}
