<?php
namespace SFS\HR\Modules\Assets\Rest;

if ( ! defined('ABSPATH') ) { exit; }

class Assets_REST {

    const NS = 'sfs-hr/v1';

    public function hooks(): void {
        add_action('rest_api_init', [ $this, 'routes' ]);
    }

    public function routes(): void {
        register_rest_route(self::NS, '/assets/scan/(?P<code>[A-Za-z0-9\-]+)', [
            'methods'  => 'GET',
            'callback' => [ $this, 'handle_scan' ],
            'permission_callback' => '__return_true', // scanning can be public
        ]);

        register_rest_route(self::NS, '/assets/assign', [
            'methods'  => 'POST',
            'callback' => [ $this, 'handle_assign_request' ],
            'permission_callback' => [ $this, 'check_manager_cap' ],
        ]);

        register_rest_route(self::NS, '/assets/assign/approve', [
            'methods'  => 'POST',
            'callback' => [ $this, 'handle_employee_approve' ],
            'permission_callback' => '__return_true', // validated via nonce + logged-in user
        ]);

        register_rest_route(self::NS, '/assets/return/request', [
            'methods'  => 'POST',
            'callback' => [ $this, 'handle_return_request' ],
            'permission_callback' => [ $this, 'check_manager_cap' ],
        ]);

        register_rest_route(self::NS, '/assets/return/approve', [
            'methods'  => 'POST',
            'callback' => [ $this, 'handle_return_approve' ],
            'permission_callback' => '__return_true',
        ]);
    }

    public function check_manager_cap(): bool {
        return current_user_can('sfs_hr_assets_admin') || current_user_can('sfs_hr_manager');
    }

    public function handle_scan(\WP_REST_Request $req) {
        $code = sanitize_text_field( $req['code'] );
        // lookup asset by asset_code
        // return JSON: asset info + current assignment (if any)
    }

    public function handle_assign_request(\WP_REST_Request $req) {
        // manager creates a pending assignment
        // validate: asset available, sanitize inputs, insert row with status=pending_employee_approval
        // update assets.status = 'under_approval'
    }

    public function handle_employee_approve(\WP_REST_Request $req) {
        // employee approves or rejects assignment
        // if approve -> status=active, assets.status='assigned'
        // if reject -> status=rejected, assets.status='available'
    }

    public function handle_return_request(\WP_REST_Request $req) {
        // manager requests return: status=return_requested
    }

    public function handle_return_approve(\WP_REST_Request $req) {
        // employee hands back device:
        // status=returned, end_date=today, assets.status='available'
    }
}
