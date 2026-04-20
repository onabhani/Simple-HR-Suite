<?php
namespace SFS\HR\Modules\Surveys\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Surveys\Services\Survey_Enhancement_Service;
use SFS\HR\Modules\Surveys\Services\Engagement_Service;

class Survey_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        // Templates
        register_rest_route( $ns, '/surveys/templates', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'list_templates' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/surveys/templates/create-from', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'create_from_template' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/surveys/(?P<id>\d+)/save-as-template', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'save_as_template' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // Branching
        register_rest_route( $ns, '/surveys/questions/(?P<id>\d+)/branching', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'set_branching' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // Reminders
        register_rest_route( $ns, '/surveys/(?P<id>\d+)/reminders', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'send_reminders' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/surveys/(?P<id>\d+)/non-respondents', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'non_respondents' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // Export
        register_rest_route( $ns, '/surveys/(?P<id>\d+)/export', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'export_responses' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // eNPS
        register_rest_route( $ns, '/surveys/(?P<id>\d+)/enps', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'enps' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/surveys/enps/trend', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'enps_trend' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // Suggestions
        register_rest_route( $ns, '/engagement/suggestions', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_suggestions' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'submit_suggestion' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
        ] );

        register_rest_route( $ns, '/engagement/suggestions/(?P<id>\d+)/review', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'review_suggestion' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // Recognition
        register_rest_route( $ns, '/engagement/recognition', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'recognition_feed' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'give_recognition' ],
                'permission_callback' => [ self::class, 'can_view' ],
            ],
        ] );

        register_rest_route( $ns, '/engagement/recognition/stats/(?P<employee_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'recognition_stats' ],
            'permission_callback' => [ self::class, 'can_view' ],
        ] );

        // Dashboard
        register_rest_route( $ns, '/engagement/summary', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'engagement_summary' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );
    }

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    public static function can_view(): bool {
        return current_user_can( 'sfs_hr.view' );
    }

    /* ── Templates ── */

    public static function list_templates(): \WP_REST_Response {
        return new \WP_REST_Response( Survey_Enhancement_Service::list_templates() );
    }

    public static function create_from_template( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Survey_Enhancement_Service::create_from_template(
            absint( $req['template_id'] ?? 0 ),
            [ 'title' => sanitize_text_field( $req['title'] ?? '' ) ]
        );
        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function save_as_template( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Survey_Enhancement_Service::save_as_template(
            (int) $req['id'],
            sanitize_text_field( $req['name'] ?? '' )
        );
        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    /* ── Branching ── */

    public static function set_branching( \WP_REST_Request $req ): \WP_REST_Response {
        $rules = $req['rules'] ?? [];
        $ok = Survey_Enhancement_Service::set_branching( (int) $req['id'], $rules );
        return new \WP_REST_Response( [ 'success' => $ok ] );
    }

    /* ── Reminders ── */

    public static function send_reminders( \WP_REST_Request $req ): \WP_REST_Response {
        $count = Survey_Enhancement_Service::send_reminders(
            (int) $req['id'],
            absint( $req['max_reminders'] ?? 3 ) ?: 3
        );
        return new \WP_REST_Response( [ 'success' => true, 'sent' => $count ] );
    }

    public static function non_respondents( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( Survey_Enhancement_Service::get_non_respondents( (int) $req['id'] ) );
    }

    /* ── Export ── */

    public static function export_responses( \WP_REST_Request $req ): \WP_REST_Response {
        $csv = Survey_Enhancement_Service::export_responses( (int) $req['id'] );
        $response = new \WP_REST_Response( $csv );
        $response->header( 'Content-Type', 'text/csv; charset=UTF-8' );
        $response->header( 'Content-Disposition', 'attachment; filename="survey-' . $req['id'] . '-export.csv"' );
        return $response;
    }

    /* ── eNPS ── */

    public static function enps( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( Engagement_Service::calculate_enps( (int) $req['id'] ) );
    }

    public static function enps_trend( \WP_REST_Request $req ): \WP_REST_Response {
        $months = absint( $req['months'] ?? 6 ) ?: 6;
        return new \WP_REST_Response( Engagement_Service::get_enps_trend( $months ) );
    }

    /* ── Suggestions ── */

    public static function list_suggestions( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( Engagement_Service::list_suggestions( [
            'status'   => sanitize_text_field( $req['status'] ?? '' ) ?: null,
            'category' => sanitize_text_field( $req['category'] ?? '' ) ?: null,
            'limit'    => absint( $req['limit'] ?? 20 ) ?: 20,
            'offset'   => absint( $req['offset'] ?? 0 ),
        ] ) );
    }

    public static function submit_suggestion( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Engagement_Service::submit_suggestion(
            sanitize_textarea_field( $req['message'] ?? '' ),
            sanitize_text_field( $req['category'] ?? '' ) ?: null
        );
        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function review_suggestion( \WP_REST_Request $req ): \WP_REST_Response {
        $ok = Engagement_Service::review_suggestion(
            (int) $req['id'],
            sanitize_text_field( $req['status'] ?? '' ),
            sanitize_textarea_field( $req['notes'] ?? '' ) ?: null
        );
        return new \WP_REST_Response( [ 'success' => $ok ] );
    }

    /* ── Recognition ── */

    public static function recognition_feed( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( Engagement_Service::get_recognition_feed( [
            'is_public' => 1,
            'limit'     => absint( $req['limit'] ?? 20 ) ?: 20,
            'offset'    => absint( $req['offset'] ?? 0 ),
        ] ) );
    }

    public static function give_recognition( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Engagement_Service::give_recognition(
            absint( $req['giver_id'] ?? 0 ),
            absint( $req['recipient_id'] ?? 0 ),
            sanitize_textarea_field( $req['message'] ?? '' ),
            sanitize_text_field( $req['badge'] ?? '' ) ?: null
        );
        $status = ! empty( $result['success'] ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function recognition_stats( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( Engagement_Service::get_recognition_stats( (int) $req['employee_id'] ) );
    }

    /* ── Dashboard ── */

    public static function engagement_summary( \WP_REST_Request $req ): \WP_REST_Response {
        $dept_id = absint( $req['dept_id'] ?? 0 ) ?: null;
        return new \WP_REST_Response( Engagement_Service::get_engagement_summary( $dept_id ) );
    }
}
