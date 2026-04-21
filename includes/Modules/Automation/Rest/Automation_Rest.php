<?php
namespace SFS\HR\Modules\Automation\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Automation\Services\Automation_Rule_Service;

class Automation_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        register_rest_route( $ns, '/automation/rules', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_rules' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'save_rule' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/automation/rules/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_rule' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'delete_rule' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/automation/rules/(?P<id>\d+)/dry-run', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'dry_run' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/automation/logs', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'list_logs' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
    }

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    public static function list_rules( \WP_REST_Request $req ): \WP_REST_Response {
        $args = [
            'is_active'    => $req['is_active'] ?? null,
            'trigger_type' => sanitize_text_field( $req['trigger_type'] ?? '' ) ?: null,
            'limit'        => absint( $req['limit'] ?? 20 ) ?: 20,
            'offset'       => absint( $req['offset'] ?? 0 ),
        ];
        return new \WP_REST_Response( Automation_Rule_Service::list_rules( $args ) );
    }

    public static function get_rule( \WP_REST_Request $req ): \WP_REST_Response {
        $rule = Automation_Rule_Service::get_rule( (int) $req['id'] );
        if ( ! $rule ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'Rule not found.', 'sfs-hr' ) ],
                404
            );
        }
        return new \WP_REST_Response( $rule );
    }

    public static function save_rule( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Automation_Rule_Service::save_rule( [
            'id'             => absint( $req['id'] ?? 0 ) ?: null,
            'name'           => $req['name'] ?? '',
            'description'    => $req['description'] ?? '',
            'trigger_type'   => $req['trigger_type'] ?? '',
            'trigger_config' => $req['trigger_config'] ?? [],
            'action_type'    => $req['action_type'] ?? '',
            'action_config'  => $req['action_config'] ?? [],
            'is_active'      => isset( $req['is_active'] ) ? (int) $req['is_active'] : 1,
            'priority'       => absint( $req['priority'] ?? 100 ),
        ] );

        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function delete_rule( \WP_REST_Request $req ): \WP_REST_Response {
        $ok = Automation_Rule_Service::delete_rule( (int) $req['id'] );
        if ( ! $ok ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'Rule not found or could not be deleted.', 'sfs-hr' ) ],
                404
            );
        }
        return new \WP_REST_Response( [ 'success' => true ] );
    }

    public static function dry_run( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Automation_Rule_Service::dry_run( (int) $req['id'] );
        if ( isset( $result['success'] ) && ! $result['success'] ) {
            return new \WP_REST_Response( $result, 404 );
        }
        return new \WP_REST_Response( $result );
    }

    public static function list_logs( \WP_REST_Request $req ): \WP_REST_Response {
        $args = [
            'rule_id'     => absint( $req['rule_id'] ?? 0 ) ?: null,
            'status'      => sanitize_text_field( $req['status'] ?? '' ) ?: null,
            'employee_id' => absint( $req['employee_id'] ?? 0 ) ?: null,
            'date_from'   => sanitize_text_field( $req['date_from'] ?? '' ) ?: null,
            'date_to'     => sanitize_text_field( $req['date_to'] ?? '' ) ?: null,
            'limit'       => absint( $req['limit'] ?? 50 ) ?: 50,
            'offset'      => absint( $req['offset'] ?? 0 ),
        ];
        return new \WP_REST_Response( Automation_Rule_Service::get_logs( $args ) );
    }
}
