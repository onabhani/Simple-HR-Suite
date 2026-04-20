<?php
namespace SFS\HR\Modules\Hiring\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Hiring\Services\RequisitionService;
use SFS\HR\Modules\Hiring\Services\JobPostingService;
use SFS\HR\Modules\Hiring\Services\InterviewService;
use SFS\HR\Modules\Hiring\Services\OfferService;
use SFS\HR\Modules\Hiring\Services\OnboardingService;

/**
 * Thin REST wrapper for the Hiring module services.
 * All write operations require sfs_hr.manage; reads require sfs_hr.view.
 * Public job listings + applications are accessible without authentication.
 */
class Hiring_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        /* ── Requisitions ── */
        register_rest_route( $ns, '/hiring/requisitions', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'req_list' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'req_create' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/requisitions/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'req_get' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'req_update' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/requisitions/(?P<id>\d+)/submit', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'req_submit' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/requisitions/(?P<id>\d+)/hr-review', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'req_hr_review' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/requisitions/(?P<id>\d+)/gm-approve', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'req_gm_approve' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/requisitions/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'req_cancel' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        /* ── Job Postings ── */
        register_rest_route( $ns, '/hiring/postings', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'post_list' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'post_create' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/postings/public', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'post_public' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( $ns, '/hiring/postings/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'post_get' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'post_update' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/postings/(?P<id>\d+)/publish', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'post_publish' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/postings/(?P<id>\d+)/close', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'post_close' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/postings/(?P<id>\d+)/applications', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'post_applications' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'post_apply' ],
                'permission_callback' => [ self::class, 'public_apply_throttle' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/applications/(?P<id>\d+)/status', [
            'methods'             => 'PUT',
            'callback'            => [ self::class, 'app_update_status' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/applications/(?P<id>\d+)/convert', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'app_convert' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        /* ── Interviews ── */
        register_rest_route( $ns, '/hiring/interviews', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'int_schedule' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/interviews/upcoming', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'int_upcoming' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );
        register_rest_route( $ns, '/hiring/interviews/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'int_get' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'int_update' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/interviews/(?P<id>\d+)/complete', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'int_complete' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/interviews/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'int_cancel' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/interviews/(?P<id>\d+)/scorecard', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'int_scorecards' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'int_submit_scorecard' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/candidates/(?P<id>\d+)/interviews', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'int_for_candidate' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );

        /* ── Offers ── */
        register_rest_route( $ns, '/hiring/offers', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'offer_list' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'offer_create' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/offers/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'offer_get' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'offer_update' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/offers/(?P<id>\d+)/submit', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'offer_submit' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/offers/(?P<id>\d+)/manager-review', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'offer_manager_review' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/offers/(?P<id>\d+)/finance-approve', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'offer_finance_approve' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/offers/(?P<id>\d+)/send', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'offer_send' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/offers/(?P<id>\d+)/response', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'offer_response' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/offers/(?P<id>\d+)/letter', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'offer_letter' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        /* ── Onboarding ── */
        register_rest_route( $ns, '/hiring/onboarding/templates', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'ob_template_list' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'ob_template_create' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/onboarding/templates/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'ob_template_get' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'ob_template_update' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );
        register_rest_route( $ns, '/hiring/onboarding/start', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'ob_start' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/onboarding/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'ob_get' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );
        register_rest_route( $ns, '/hiring/onboarding/(?P<id>\d+)/progress', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'ob_progress' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );
        register_rest_route( $ns, '/hiring/onboarding/(?P<id>\d+)/tasks', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'ob_tasks' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );
        register_rest_route( $ns, '/hiring/onboarding/tasks/(?P<id>\d+)/status', [
            'methods'             => 'PUT',
            'callback'            => [ self::class, 'ob_update_task' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
        register_rest_route( $ns, '/hiring/onboarding/tasks/(?P<id>\d+)/complete', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'ob_complete_task' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
    }

    /* ── Permission callbacks ── */

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    public static function can_view(): bool {
        return current_user_can( 'sfs_hr.view' ) || current_user_can( 'sfs_hr.manage' );
    }

    /**
     * Throttle public job applications by IP.
     * Caps at 5 applications per IP per hour by default.
     * Fires `sfs_hr_pre_public_apply` so site owners can hook in reCAPTCHA
     * or additional checks (return WP_Error to block).
     */
    public static function public_apply_throttle( \WP_REST_Request $req ): bool|\WP_Error {
        /**
         * Filter: sfs_hr_public_apply_pre_check
         * Allow integrations (reCAPTCHA, honeypot, etc.) to reject an application early.
         * Return WP_Error to block.
         */
        $pre = apply_filters( 'sfs_hr_public_apply_pre_check', null, $req );
        if ( is_wp_error( $pre ) ) {
            return $pre;
        }

        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ( '' === $ip ) {
            return true;
        }

        $key = 'sfs_hr_apply_ip_' . md5( $ip );
        $n   = (int) get_transient( $key );

        /** Filter max applications per IP per hour. */
        $limit = (int) apply_filters( 'sfs_hr_public_apply_limit_per_hour', 5 );

        if ( $n >= $limit ) {
            return new \WP_Error(
                'rest_too_many_applications',
                __( 'Too many applications from this IP. Please try again later.', 'sfs-hr' ),
                [ 'status' => 429 ]
            );
        }
        set_transient( $key, $n + 1, HOUR_IN_SECONDS );
        return true;
    }

    /* ── Requisitions handlers ── */

    public static function req_list( \WP_REST_Request $req ): \WP_REST_Response {
        $filters = [
            'status'   => sanitize_text_field( $req['status'] ?? '' ),
            'dept_id'  => absint( $req['dept_id'] ?? 0 ),
            'limit'    => absint( $req['limit'] ?? 50 ),
            'offset'   => absint( $req['offset'] ?? 0 ),
        ];
        return new \WP_REST_Response( RequisitionService::get_list( array_filter( $filters ) ) );
    }

    public static function req_get( \WP_REST_Request $req ): \WP_REST_Response {
        $row = RequisitionService::get( (int) $req['id'] );
        return $row
            ? new \WP_REST_Response( $row )
            : new \WP_REST_Response( [ 'error' => __( 'Requisition not found.', 'sfs-hr' ) ], 404 );
    }

    public static function req_create( \WP_REST_Request $req ): \WP_REST_Response {
        $id = RequisitionService::create( (array) $req->get_json_params() );
        return $id
            ? new \WP_REST_Response( [ 'success' => true, 'id' => $id ], 201 )
            : new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Failed to create requisition.', 'sfs-hr' ) ], 400 );
    }

    public static function req_update( \WP_REST_Request $req ): \WP_REST_Response {
        $ok = RequisitionService::update( (int) $req['id'], (array) $req->get_json_params() );
        return new \WP_REST_Response( [ 'success' => $ok ] );
    }

    public static function req_submit( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => RequisitionService::submit_for_approval( (int) $req['id'] ) ] );
    }

    public static function req_hr_review( \WP_REST_Request $req ): \WP_REST_Response {
        $action = sanitize_text_field( $req['action'] ?? '' );
        if ( ! in_array( $action, [ 'approve', 'reject' ], true ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Invalid action.', 'sfs-hr' ) ], 400 );
        }
        $notes = sanitize_textarea_field( $req['notes'] ?? '' );
        return new \WP_REST_Response( [ 'success' => RequisitionService::hr_review( (int) $req['id'], $action, $notes ) ] );
    }

    public static function req_gm_approve( \WP_REST_Request $req ): \WP_REST_Response {
        $action = sanitize_text_field( $req['action'] ?? '' );
        if ( ! in_array( $action, [ 'approve', 'reject' ], true ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Invalid action.', 'sfs-hr' ) ], 400 );
        }
        $notes = sanitize_textarea_field( $req['notes'] ?? '' );
        return new \WP_REST_Response( [ 'success' => RequisitionService::gm_approve( (int) $req['id'], $action, $notes ) ] );
    }

    public static function req_cancel( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => RequisitionService::cancel( (int) $req['id'] ) ] );
    }

    /* ── Job Postings handlers ── */

    public static function post_list( \WP_REST_Request $req ): \WP_REST_Response {
        $filters = [
            'status'   => sanitize_text_field( $req['status'] ?? '' ),
            'limit'    => absint( $req['limit'] ?? 50 ),
            'offset'   => absint( $req['offset'] ?? 0 ),
        ];
        return new \WP_REST_Response( JobPostingService::get_list( array_filter( $filters ) ) );
    }

    public static function post_public(): \WP_REST_Response {
        return new \WP_REST_Response( JobPostingService::get_public_listings() );
    }

    public static function post_get( \WP_REST_Request $req ): \WP_REST_Response {
        $row = JobPostingService::get( (int) $req['id'] );
        return $row
            ? new \WP_REST_Response( $row )
            : new \WP_REST_Response( [ 'error' => __( 'Posting not found.', 'sfs-hr' ) ], 404 );
    }

    public static function post_create( \WP_REST_Request $req ): \WP_REST_Response {
        $id = JobPostingService::create( (array) $req->get_json_params() );
        return $id
            ? new \WP_REST_Response( [ 'success' => true, 'id' => $id ], 201 )
            : new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Failed to create posting.', 'sfs-hr' ) ], 400 );
    }

    public static function post_update( \WP_REST_Request $req ): \WP_REST_Response {
        $ok = JobPostingService::update( (int) $req['id'], (array) $req->get_json_params() );
        return new \WP_REST_Response( [ 'success' => $ok ] );
    }

    public static function post_publish( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => JobPostingService::publish( (int) $req['id'] ) ] );
    }

    public static function post_close( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => JobPostingService::close( (int) $req['id'] ) ] );
    }

    public static function post_applications( \WP_REST_Request $req ): \WP_REST_Response {
        $status = sanitize_text_field( $req['status'] ?? '' ) ?: null;
        return new \WP_REST_Response( JobPostingService::get_applications( (int) $req['id'], $status ) );
    }

    public static function post_apply( \WP_REST_Request $req ): \WP_REST_Response {
        $id = JobPostingService::submit_application( (int) $req['id'], (array) $req->get_json_params() );
        return $id
            ? new \WP_REST_Response( [ 'success' => true, 'application_id' => $id ], 201 )
            : new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Failed to submit application.', 'sfs-hr' ) ], 400 );
    }

    public static function app_update_status( \WP_REST_Request $req ): \WP_REST_Response {
        $status = sanitize_text_field( $req['status'] ?? '' );
        return new \WP_REST_Response( [ 'success' => JobPostingService::update_application_status( (int) $req['id'], $status ) ] );
    }

    public static function app_convert( \WP_REST_Request $req ): \WP_REST_Response {
        $cand_id = JobPostingService::convert_to_candidate( (int) $req['id'] );
        return $cand_id
            ? new \WP_REST_Response( [ 'success' => true, 'candidate_id' => $cand_id ] )
            : new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Failed to convert.', 'sfs-hr' ) ], 400 );
    }

    /* ── Interviews handlers ── */

    public static function int_schedule( \WP_REST_Request $req ): \WP_REST_Response {
        $id = InterviewService::schedule( (array) $req->get_json_params() );
        return $id
            ? new \WP_REST_Response( [ 'success' => true, 'id' => $id ], 201 )
            : new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Failed to schedule.', 'sfs-hr' ) ], 400 );
    }

    public static function int_get( \WP_REST_Request $req ): \WP_REST_Response {
        $row = InterviewService::get( (int) $req['id'] );
        return $row
            ? new \WP_REST_Response( $row )
            : new \WP_REST_Response( [ 'error' => __( 'Interview not found.', 'sfs-hr' ) ], 404 );
    }

    public static function int_update( \WP_REST_Request $req ): \WP_REST_Response {
        $ok = InterviewService::update( (int) $req['id'], (array) $req->get_json_params() );
        return new \WP_REST_Response( [ 'success' => $ok ] );
    }

    public static function int_complete( \WP_REST_Request $req ): \WP_REST_Response {
        $notes = sanitize_textarea_field( $req['notes'] ?? '' );
        return new \WP_REST_Response( [ 'success' => InterviewService::complete( (int) $req['id'], $notes ) ] );
    }

    public static function int_cancel( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => InterviewService::cancel( (int) $req['id'] ) ] );
    }

    public static function int_upcoming( \WP_REST_Request $req ): \WP_REST_Response {
        $limit = absint( $req['limit'] ?? 20 ) ?: 20;
        $limit = min( $limit, 100 );
        return new \WP_REST_Response( InterviewService::get_upcoming( $limit ) );
    }

    public static function int_for_candidate( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( InterviewService::get_for_candidate( (int) $req['id'] ) );
    }

    public static function int_scorecards( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( InterviewService::get_scorecards( (int) $req['id'] ) );
    }

    public static function int_submit_scorecard( \WP_REST_Request $req ): \WP_REST_Response {
        $data = (array) $req->get_json_params();
        $data['interview_id'] = (int) $req['id'];
        $id = InterviewService::submit_scorecard( $data );
        return $id
            ? new \WP_REST_Response( [ 'success' => true, 'id' => $id ], 201 )
            : new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Failed to submit scorecard.', 'sfs-hr' ) ], 400 );
    }

    /* ── Offers handlers ── */

    public static function offer_list( \WP_REST_Request $req ): \WP_REST_Response {
        $filters = [
            'status'       => sanitize_text_field( $req['status'] ?? '' ),
            'candidate_id' => absint( $req['candidate_id'] ?? 0 ),
            'limit'        => absint( $req['limit'] ?? 50 ),
            'offset'       => absint( $req['offset'] ?? 0 ),
        ];
        return new \WP_REST_Response( OfferService::get_list( array_filter( $filters ) ) );
    }

    public static function offer_get( \WP_REST_Request $req ): \WP_REST_Response {
        $row = OfferService::get( (int) $req['id'] );
        return $row
            ? new \WP_REST_Response( $row )
            : new \WP_REST_Response( [ 'error' => __( 'Offer not found.', 'sfs-hr' ) ], 404 );
    }

    public static function offer_create( \WP_REST_Request $req ): \WP_REST_Response {
        $id = OfferService::create( (array) $req->get_json_params() );
        return $id
            ? new \WP_REST_Response( [ 'success' => true, 'id' => $id ], 201 )
            : new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Failed to create offer.', 'sfs-hr' ) ], 400 );
    }

    public static function offer_update( \WP_REST_Request $req ): \WP_REST_Response {
        $ok = OfferService::update( (int) $req['id'], (array) $req->get_json_params() );
        return new \WP_REST_Response( [ 'success' => $ok ] );
    }

    public static function offer_submit( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => OfferService::submit_for_approval( (int) $req['id'] ) ] );
    }

    public static function offer_manager_review( \WP_REST_Request $req ): \WP_REST_Response {
        $action = sanitize_text_field( $req['action'] ?? '' );
        if ( ! in_array( $action, [ 'approve', 'reject' ], true ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Invalid action.', 'sfs-hr' ) ], 400 );
        }
        $notes = sanitize_textarea_field( $req['notes'] ?? '' );
        return new \WP_REST_Response( [ 'success' => OfferService::manager_review( (int) $req['id'], $action, $notes ) ] );
    }

    public static function offer_finance_approve( \WP_REST_Request $req ): \WP_REST_Response {
        $action = sanitize_text_field( $req['action'] ?? '' );
        if ( ! in_array( $action, [ 'approve', 'reject' ], true ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Invalid action.', 'sfs-hr' ) ], 400 );
        }
        $notes = sanitize_textarea_field( $req['notes'] ?? '' );
        return new \WP_REST_Response( [ 'success' => OfferService::finance_approve( (int) $req['id'], $action, $notes ) ] );
    }

    public static function offer_send( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [ 'success' => OfferService::send_offer( (int) $req['id'] ) ] );
    }

    public static function offer_response( \WP_REST_Request $req ): \WP_REST_Response {
        $response = sanitize_text_field( $req['response'] ?? '' );
        if ( ! in_array( $response, [ 'accepted', 'rejected', 'negotiating' ], true ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Invalid response.', 'sfs-hr' ) ], 400 );
        }
        return new \WP_REST_Response( [ 'success' => OfferService::record_response( (int) $req['id'], $response ) ] );
    }

    public static function offer_letter( \WP_REST_Request $req ): \WP_REST_Response {
        $letter = OfferService::generate_letter( (int) $req['id'] );
        return new \WP_REST_Response( [ 'letter' => $letter ] );
    }

    /* ── Onboarding handlers ── */

    public static function ob_template_list( \WP_REST_Request $req ): \WP_REST_Response {
        $filters = [
            'is_active' => isset( $req['is_active'] ) ? (int) $req['is_active'] : null,
        ];
        return new \WP_REST_Response( OnboardingService::get_templates( array_filter( $filters, fn( $v ) => $v !== null ) ) );
    }

    public static function ob_template_get( \WP_REST_Request $req ): \WP_REST_Response {
        $row = OnboardingService::get_template( (int) $req['id'] );
        if ( ! $row ) {
            return new \WP_REST_Response( [ 'error' => __( 'Template not found.', 'sfs-hr' ) ], 404 );
        }
        $items = OnboardingService::get_template_items( (int) $req['id'] );
        return new \WP_REST_Response( [ 'template' => $row, 'items' => $items ] );
    }

    public static function ob_template_create( \WP_REST_Request $req ): \WP_REST_Response {
        $id = OnboardingService::create_template( (array) $req->get_json_params() );
        return $id
            ? new \WP_REST_Response( [ 'success' => true, 'id' => $id ], 201 )
            : new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Failed to create template.', 'sfs-hr' ) ], 400 );
    }

    public static function ob_template_update( \WP_REST_Request $req ): \WP_REST_Response {
        $ok = OnboardingService::update_template( (int) $req['id'], (array) $req->get_json_params() );
        return new \WP_REST_Response( [ 'success' => $ok ] );
    }

    public static function ob_start( \WP_REST_Request $req ): \WP_REST_Response {
        $body        = (array) $req->get_json_params();
        $employee_id = absint( $body['employee_id'] ?? 0 );
        $template_id = ! empty( $body['template_id'] ) ? absint( $body['template_id'] ) : null;
        if ( ! $employee_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'employee_id is required.', 'sfs-hr' ) ], 400 );
        }
        $id = OnboardingService::start_onboarding( $employee_id, $template_id );
        return $id
            ? new \WP_REST_Response( [ 'success' => true, 'onboarding_id' => $id ], 201 )
            : new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Failed to start onboarding.', 'sfs-hr' ) ], 400 );
    }

    public static function ob_get( \WP_REST_Request $req ): \WP_REST_Response {
        $row = OnboardingService::get_onboarding( (int) $req['id'] );
        return $row
            ? new \WP_REST_Response( $row )
            : new \WP_REST_Response( [ 'error' => __( 'Onboarding not found.', 'sfs-hr' ) ], 404 );
    }

    public static function ob_progress( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( OnboardingService::get_progress( (int) $req['id'] ) );
    }

    public static function ob_tasks( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( OnboardingService::get_tasks( (int) $req['id'] ) );
    }

    public static function ob_update_task( \WP_REST_Request $req ): \WP_REST_Response {
        $status = sanitize_text_field( $req['status'] ?? '' );
        return new \WP_REST_Response( [ 'success' => OnboardingService::update_task_status( (int) $req['id'], $status ) ] );
    }

    public static function ob_complete_task( \WP_REST_Request $req ): \WP_REST_Response {
        $notes = sanitize_textarea_field( $req['notes'] ?? '' );
        return new \WP_REST_Response( [ 'success' => OnboardingService::complete_task( (int) $req['id'], $notes ) ] );
    }
}
