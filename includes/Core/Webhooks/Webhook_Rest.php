<?php
namespace SFS\HR\Core\Webhooks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Webhook_Rest
 *
 * M9.3 — REST endpoints for managing webhooks.
 *
 * Base: /sfs-hr/v1/webhooks
 *   GET    /webhooks                 — list all webhooks
 *   POST   /webhooks                 — create
 *   GET    /webhooks/{id}            — fetch one (secret redacted)
 *   PUT    /webhooks/{id}            — update (url/events/status/retry_policy)
 *   DELETE /webhooks/{id}            — delete
 *   POST   /webhooks/{id}/rotate     — generate new signing secret
 *   POST   /webhooks/{id}/test       — send synthetic test.ping event
 *   GET    /webhooks/{id}/deliveries — delivery log
 *   GET    /webhooks/events          — list supported event names
 *
 * All routes require sfs_hr.manage.
 *
 * @since M9
 */
class Webhook_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        register_rest_route( $ns, '/webhooks', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_webhooks' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_webhook' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/webhooks/events', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_events' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/webhooks/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_webhook' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [ __CLASS__, 'update_webhook' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_webhook' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/webhooks/(?P<id>\d+)/rotate', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'rotate_secret' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/webhooks/(?P<id>\d+)/test', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'test_webhook' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/webhooks/(?P<id>\d+)/deliveries', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_deliveries' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
            'args' => [
                'limit' => [ 'type' => 'integer', 'default' => 50 ],
            ],
        ] );
    }

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    /* ───────── Handlers ───────── */

    public static function list_webhooks(): \WP_REST_Response {
        $rows = array_map( [ __CLASS__, 'redact_secret' ], Webhook_Service::get_all() );
        return rest_ensure_response( $rows );
    }

    public static function list_events(): \WP_REST_Response {
        return rest_ensure_response( Webhook_Service::EVENTS );
    }

    public static function get_webhook( \WP_REST_Request $req ) {
        $wh = Webhook_Service::get( (int) $req['id'] );
        if ( ! $wh ) {
            return new \WP_Error( 'not_found', __( 'Webhook not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( self::redact_secret( $wh ) );
    }

    public static function create_webhook( \WP_REST_Request $req ) {
        $url          = (string) ( $req['url'] ?? '' );
        $events       = (array)  ( $req['events'] ?? [] );
        $retry_policy = (int)    ( $req['retry_policy'] ?? 3 );

        $result = Webhook_Service::create( $url, array_map( 'strval', $events ), $retry_policy );
        if ( is_wp_error( $result ) ) {
            return new \WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 400 ] );
        }

        // Return the new webhook INCLUDING secret, one-time, for the client to capture.
        $wh = Webhook_Service::get( $result );
        return new \WP_REST_Response( [
            'id'     => $result,
            'secret' => $wh->secret ?? '',
            'data'   => self::redact_secret( $wh ),
            'note'   => __( 'Store the secret safely — it is only returned once at creation.', 'sfs-hr' ),
        ], 201 );
    }

    public static function update_webhook( \WP_REST_Request $req ) {
        $id   = (int) $req['id'];
        if ( ! Webhook_Service::get( $id ) ) {
            return new \WP_Error( 'not_found', __( 'Webhook not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }

        $data = array_filter( [
            'url'          => $req->has_param( 'url' )          ? (string) $req['url']          : null,
            'events'       => $req->has_param( 'events' )       ? (array)  $req['events']       : null,
            'status'       => $req->has_param( 'status' )       ? (string) $req['status']       : null,
            'retry_policy' => $req->has_param( 'retry_policy' ) ? (int)    $req['retry_policy'] : null,
        ], fn( $v ) => $v !== null );

        $ok = Webhook_Service::update( $id, $data );
        if ( ! $ok ) {
            return new \WP_Error( 'invalid', __( 'Update rejected — check URL, events, or permissions.', 'sfs-hr' ), [ 'status' => 400 ] );
        }
        return rest_ensure_response( self::redact_secret( Webhook_Service::get( $id ) ) );
    }

    public static function delete_webhook( \WP_REST_Request $req ) {
        $id = (int) $req['id'];
        if ( ! Webhook_Service::get( $id ) ) {
            return new \WP_Error( 'not_found', __( 'Webhook not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }
        Webhook_Service::delete( $id );
        return rest_ensure_response( [ 'deleted' => true ] );
    }

    public static function rotate_secret( \WP_REST_Request $req ) {
        $id     = (int) $req['id'];
        $secret = Webhook_Service::rotate_secret( $id );
        if ( null === $secret ) {
            return new \WP_Error( 'not_found', __( 'Webhook not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }
        return rest_ensure_response( [
            'secret' => $secret,
            'note'   => __( 'New secret — update your endpoint and store it safely.', 'sfs-hr' ),
        ] );
    }

    public static function test_webhook( \WP_REST_Request $req ) {
        $id     = (int) $req['id'];
        $result = Webhook_Service::test_delivery( $id );
        if ( ! empty( $result['error'] ) ) {
            return new \WP_Error( 'test_failed', $result['error'], [ 'status' => 400 ] );
        }
        return rest_ensure_response( $result );
    }

    public static function list_deliveries( \WP_REST_Request $req ) {
        $id    = (int) $req['id'];
        $limit = (int) $req->get_param( 'limit' );
        return rest_ensure_response( Webhook_Service::get_deliveries( $id, $limit ) );
    }

    /* ───────── Helpers ───────── */

    private static function redact_secret( $webhook ) {
        if ( is_object( $webhook ) ) {
            $copy = clone $webhook;
            if ( isset( $copy->secret ) ) {
                $copy->secret = substr( (string) $copy->secret, 0, 4 ) . '…';
            }
            return $copy;
        }
        if ( is_array( $webhook ) && isset( $webhook['secret'] ) ) {
            $webhook['secret'] = substr( (string) $webhook['secret'], 0, 4 ) . '…';
        }
        return $webhook;
    }
}
