<?php
namespace SFS\HR\Core\ApiKeys;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Api_Key_Rest
 *
 * M9.2 — REST endpoints for managing API keys.
 *
 * Base: /sfs-hr/v1/api-keys
 *   GET    /api-keys                 — list keys (admins see all; users see own)
 *   POST   /api-keys                 — issue a new key (plaintext returned once)
 *   POST   /api-keys/{id}/revoke     — revoke an active key
 *   DELETE /api-keys/{id}            — hard-delete (admin only)
 *
 * @since M9
 */
class Api_Key_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        register_rest_route( $ns, '/api-keys', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_keys' ],
                'permission_callback' => [ __CLASS__, 'can_access' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_key' ],
                'permission_callback' => [ __CLASS__, 'can_access' ],
            ],
        ] );

        register_rest_route( $ns, '/api-keys/(?P<id>\d+)/revoke', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'revoke_key' ],
            'permission_callback' => [ __CLASS__, 'can_access' ],
        ] );

        register_rest_route( $ns, '/api-keys/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ __CLASS__, 'delete_key' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );
    }

    public static function can_access(): bool {
        // Any authenticated WP user may list/create their own keys.
        return is_user_logged_in();
    }

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    /* ───────── Handlers ───────── */

    public static function list_keys(): \WP_REST_Response {
        if ( current_user_can( 'sfs_hr.manage' ) ) {
            return rest_ensure_response( Api_Key_Service::list_all() );
        }
        return rest_ensure_response( Api_Key_Service::list_for_user( get_current_user_id() ) );
    }

    public static function create_key( \WP_REST_Request $req ) {
        $label      = sanitize_text_field( (string) ( $req['label'] ?? '' ) );
        $scopes     = (array) ( $req['scopes'] ?? [] );
        $expires_at = null;
        if ( ! empty( $req['expires_at'] ) ) {
            $ts = strtotime( (string) $req['expires_at'] );
            if ( $ts && $ts > time() ) {
                $expires_at = gmdate( 'Y-m-d H:i:s', $ts );
            }
        }

        // Admins may issue keys on behalf of other users; everyone else gets one for themselves.
        $target_user = get_current_user_id();
        if ( current_user_can( 'sfs_hr.manage' ) && ! empty( $req['user_id'] ) ) {
            $target_user = (int) $req['user_id'];
        }

        $result = Api_Key_Service::create( $target_user, $label, $scopes, $expires_at );
        if ( isset( $result['error'] ) ) {
            return new \WP_Error( 'invalid', $result['error'], [ 'status' => 400 ] );
        }

        return new \WP_REST_Response( [
            'id'     => $result['id'],
            'key'    => $result['key'],
            'prefix' => $result['prefix'],
            'note'   => __( 'Store this key safely — the full value is only shown once.', 'sfs-hr' ),
        ], 201 );
    }

    public static function revoke_key( \WP_REST_Request $req ) {
        global $wpdb;
        $id  = (int) $req['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}sfs_hr_api_keys WHERE id = %d",
            $id
        ) );

        if ( ! $row ) {
            return new \WP_Error( 'not_found', __( 'API key not found.', 'sfs-hr' ), [ 'status' => 404 ] );
        }

        $is_owner = (int) $row->user_id === get_current_user_id();
        if ( ! $is_owner && ! current_user_can( 'sfs_hr.manage' ) ) {
            return new \WP_Error( 'forbidden', __( 'You may not revoke this key.', 'sfs-hr' ), [ 'status' => 403 ] );
        }

        if ( ! Api_Key_Service::revoke( $id ) ) {
            return new \WP_Error( 'revoke_failed', __( 'Failed to revoke key.', 'sfs-hr' ), [ 'status' => 500 ] );
        }
        return rest_ensure_response( [ 'revoked' => true ] );
    }

    public static function delete_key( \WP_REST_Request $req ) {
        $id = (int) $req['id'];
        if ( ! Api_Key_Service::delete( $id ) ) {
            return new \WP_Error( 'delete_failed', __( 'Failed to delete key.', 'sfs-hr' ), [ 'status' => 500 ] );
        }
        return rest_ensure_response( [ 'deleted' => true ] );
    }
}
