<?php
namespace SFS\HR\Modules\Surveys\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Rest\Rest_Response;
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

        // M9.1 — Surveys CRUD
        register_rest_route( $ns, '/surveys', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_surveys' ],
                'permission_callback' => [ self::class, 'can_view_list' ],
                'args'                => [
                    'status'   => [ 'type' => 'string', 'enum' => [ 'draft', 'published', 'closed' ] ],
                    'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
                    'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'create_survey' ],
                'permission_callback' => [ self::class, 'can_manage' ],
                'args'                => [
                    'title'        => [ 'type' => 'string',  'required' => true ],
                    'description'  => [ 'type' => 'string' ],
                    'is_anonymous' => [ 'type' => 'boolean', 'default' => true ],
                    'target_scope' => [ 'type' => 'string',  'enum' => [ 'all', 'department', 'individual' ], 'default' => 'all' ],
                    'target_ids'   => [ 'type' => 'array' ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/surveys/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'get_survey' ],
                'permission_callback' => [ self::class, 'can_view_list' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'update_survey' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'delete_survey' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/surveys/(?P<id>\d+)/publish', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'publish_survey' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/surveys/(?P<id>\d+)/close', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'close_survey' ],
            'permission_callback' => [ self::class, 'can_manage' ],
        ] );

        // M9.1 — Questions CRUD
        register_rest_route( $ns, '/surveys/(?P<id>\d+)/questions', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_questions' ],
                'permission_callback' => [ self::class, 'can_view_list' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'create_question' ],
                'permission_callback' => [ self::class, 'can_manage' ],
                'args'                => [
                    'question_text' => [ 'type' => 'string',  'required' => true ],
                    'question_type' => [ 'type' => 'string',  'enum' => [ 'rating', 'text', 'choice', 'yes_no' ], 'default' => 'rating' ],
                    'options'       => [ 'type' => 'array' ],
                    'is_required'   => [ 'type' => 'boolean', 'default' => true ],
                    'sort_order'    => [ 'type' => 'integer', 'minimum' => 0 ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/surveys/questions/(?P<qid>\d+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'update_question' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ self::class, 'delete_question' ],
                'permission_callback' => [ self::class, 'can_manage' ],
            ],
        ] );

        // M9.1 — Responses
        register_rest_route( $ns, '/surveys/(?P<id>\d+)/responses', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'list_responses' ],
                'permission_callback' => [ self::class, 'can_manage' ],
                'args'                => [
                    'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
                    'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'submit_response' ],
                'permission_callback' => [ self::class, 'can_submit_response' ],
                'args'                => [
                    'answers' => [ 'type' => 'array', 'required' => true ],
                ],
            ],
        ] );
    }

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    public static function can_view(): bool {
        return current_user_can( 'sfs_hr.view' );
    }

    /**
     * Any authenticated user can read the list/detail endpoints. Scoping
     * (targeted surveys, own responses) is applied inside each handler so
     * employees see only what's meant for them while admins see all.
     */
    public static function can_view_list(): bool {
        return is_user_logged_in();
    }

    /**
     * Any authenticated user mapped to an employee record may submit a
     * response — the handler enforces that the survey is published and
     * within the employee's target scope.
     */
    public static function can_submit_response(): bool {
        return is_user_logged_in() && self::current_employee_id() !== null;
    }

    /**
     * Resolve the current user's employee_id for self-service actions.
     */
    private static function current_employee_id(): ?int {
        global $wpdb;
        $uid = get_current_user_id();
        if ( ! $uid ) {
            return null;
        }
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d LIMIT 1",
            $uid
        ) );
        return $id ? (int) $id : null;
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
        // Resolve giver from the authenticated user; only admins may
        // specify a different giver_id in the request body.
        $giver_id = current_user_can( 'sfs_hr.manage' )
            ? absint( $req['giver_id'] ?? 0 ) ?: (int) ( self::current_employee_id() ?? 0 )
            : (int) ( self::current_employee_id() ?? 0 );

        if ( $giver_id <= 0 ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'Giver could not be resolved.', 'sfs-hr' ) ],
                400
            );
        }

        $result = Engagement_Service::give_recognition(
            $giver_id,
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

    /* ───────────────────────────────────────────────────────────── */
    /*  M9.1 — Surveys CRUD                                          */
    /* ───────────────────────────────────────────────────────────── */

    public static function list_surveys( \WP_REST_Request $req ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'sfs_hr_surveys';
        $paging = Rest_Response::parse_pagination( $req, 20, 100 );

        $where = [ '1=1' ];
        $args  = [];

        $status = (string) $req->get_param( 'status' );
        if ( in_array( $status, [ 'draft', 'published', 'closed' ], true ) ) {
            $where[] = 'status = %s';
            $args[]  = $status;
        }

        // Non-admins only see published surveys (can't view drafts/closed lists).
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            $where[] = "status = 'published'";
        }

        $where_sql = implode( ' AND ', $where );

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total     = (int) ( empty( $args )
            ? $wpdb->get_var( $count_sql )
            : $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) );

        $rows_args = array_merge( $args, [ $paging['per_page'], $paging['offset'] ] );
        $rows_sql  = "SELECT id, title, description, status, is_anonymous, target_scope, target_ids, created_by, published_at, closed_at, created_at, updated_at
                      FROM {$table}
                      WHERE {$where_sql}
                      ORDER BY id DESC
                      LIMIT %d OFFSET %d";
        $rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$rows_args ), ARRAY_A );

        $data = array_map( [ self::class, 'format_survey' ], $rows ?: [] );
        return Rest_Response::paginated( $data, $total, $paging['page'], $paging['per_page'] );
    }

    public static function get_survey( \WP_REST_Request $req ) {
        global $wpdb;
        $id  = (int) $req['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d",
            $id
        ), ARRAY_A );

        if ( ! $row ) {
            return Rest_Response::error( 'not_found', __( 'Survey not found.', 'sfs-hr' ), 404 );
        }

        // Non-admins can't view drafts/closed details.
        if ( ! current_user_can( 'sfs_hr.manage' ) && $row['status'] !== 'published' ) {
            return Rest_Response::error( 'forbidden', __( 'Survey not available.', 'sfs-hr' ), 403 );
        }

        return Rest_Response::success( self::format_survey( $row ) );
    }

    public static function create_survey( \WP_REST_Request $req ) {
        global $wpdb;

        $title = trim( (string) $req->get_param( 'title' ) );
        if ( $title === '' ) {
            return Rest_Response::error( 'invalid_input', __( 'title is required.', 'sfs-hr' ), 400 );
        }

        $target_scope = (string) $req->get_param( 'target_scope' ) ?: 'all';
        if ( ! in_array( $target_scope, [ 'all', 'department', 'individual' ], true ) ) {
            $target_scope = 'all';
        }

        $target_ids = $req->get_param( 'target_ids' );
        $target_ids = is_array( $target_ids ) ? array_values( array_map( 'intval', $target_ids ) ) : [];

        $now = current_time( 'mysql' );

        $inserted = $wpdb->insert( $wpdb->prefix . 'sfs_hr_surveys', [
            'title'        => sanitize_text_field( $title ),
            'description'  => sanitize_textarea_field( (string) $req->get_param( 'description' ) ),
            'status'       => 'draft',
            'is_anonymous' => $req->get_param( 'is_anonymous' ) === false ? 0 : 1,
            'target_scope' => $target_scope,
            'target_ids'   => $target_ids ? wp_json_encode( $target_ids ) : null,
            'created_by'   => get_current_user_id(),
            'created_at'   => $now,
            'updated_at'   => $now,
        ] );

        if ( ! $inserted ) {
            return Rest_Response::error( 'insert_failed', __( 'Failed to create survey.', 'sfs-hr' ), 500 );
        }

        $id  = (int) $wpdb->insert_id;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d", $id ), ARRAY_A );
        return Rest_Response::success( self::format_survey( $row ), 201 );
    }

    public static function update_survey( \WP_REST_Request $req ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_surveys';
        $id    = (int) $req['id'];

        $current = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );
        if ( ! $current ) {
            return Rest_Response::error( 'not_found', __( 'Survey not found.', 'sfs-hr' ), 404 );
        }
        if ( $current->status === 'closed' ) {
            return Rest_Response::error( 'invalid_state', __( 'Closed surveys cannot be edited.', 'sfs-hr' ), 409 );
        }

        $set = [];

        $title = $req->get_param( 'title' );
        if ( $title !== null ) {
            $title = trim( (string) $title );
            if ( $title === '' ) {
                return Rest_Response::error( 'invalid_input', __( 'title cannot be empty.', 'sfs-hr' ), 400 );
            }
            $set['title'] = sanitize_text_field( $title );
        }

        $description = $req->get_param( 'description' );
        if ( $description !== null ) {
            $set['description'] = sanitize_textarea_field( (string) $description );
        }

        $is_anon = $req->get_param( 'is_anonymous' );
        if ( $is_anon !== null ) {
            $set['is_anonymous'] = $is_anon ? 1 : 0;
        }

        $target_scope = $req->get_param( 'target_scope' );
        if ( $target_scope !== null ) {
            if ( ! in_array( $target_scope, [ 'all', 'department', 'individual' ], true ) ) {
                return Rest_Response::error( 'invalid_input', __( 'Invalid target_scope.', 'sfs-hr' ), 400 );
            }
            $set['target_scope'] = $target_scope;
        }

        $target_ids = $req->get_param( 'target_ids' );
        if ( $target_ids !== null ) {
            $target_ids          = is_array( $target_ids ) ? array_values( array_map( 'intval', $target_ids ) ) : [];
            $set['target_ids']   = $target_ids ? wp_json_encode( $target_ids ) : null;
        }

        if ( empty( $set ) ) {
            return Rest_Response::error( 'invalid_input', __( 'No fields to update.', 'sfs-hr' ), 400 );
        }

        $set['updated_at'] = current_time( 'mysql' );

        $wpdb->update( $table, $set, [ 'id' => $id ] );

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return Rest_Response::success( self::format_survey( $row ) );
    }

    public static function delete_survey( \WP_REST_Request $req ) {
        global $wpdb;
        $id    = (int) $req['id'];
        $table = $wpdb->prefix . 'sfs_hr_surveys';

        $current = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );
        if ( ! $current ) {
            return Rest_Response::error( 'not_found', __( 'Survey not found.', 'sfs-hr' ), 404 );
        }

        // Only drafts with zero responses can be hard-deleted; otherwise
        // moving to closed preserves data for historical reporting.
        $resp_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_survey_responses WHERE survey_id = %d",
            $id
        ) );

        if ( $current->status === 'draft' && $resp_count === 0 ) {
            $wpdb->delete( $wpdb->prefix . 'sfs_hr_survey_questions', [ 'survey_id' => $id ] );
            $wpdb->delete( $table, [ 'id' => $id ] );
            return Rest_Response::success( [ 'deleted' => true, 'id' => $id ] );
        }

        // Non-draft or has responses → mark closed instead of deleting.
        $wpdb->update(
            $table,
            [
                'status'     => 'closed',
                'closed_at'  => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ]
        );
        return Rest_Response::success( [ 'deleted' => false, 'closed' => true, 'id' => $id ] );
    }

    public static function publish_survey( \WP_REST_Request $req ) {
        global $wpdb;
        $id    = (int) $req['id'];
        $table = $wpdb->prefix . 'sfs_hr_surveys';

        $has_questions = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_survey_questions WHERE survey_id = %d",
            $id
        ) );

        if ( ! $has_questions ) {
            return Rest_Response::error( 'no_questions', __( 'Cannot publish a survey without questions.', 'sfs-hr' ), 409 );
        }

        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'published',
                 published_at = %s,
                 updated_at = %s
             WHERE id = %d AND status = 'draft'",
            current_time( 'mysql' ),
            current_time( 'mysql' ),
            $id
        ) );

        if ( $result === false || $result === 0 ) {
            return Rest_Response::error( 'invalid_state', __( 'Survey is not in draft status.', 'sfs-hr' ), 409 );
        }

        return Rest_Response::success( [ 'id' => $id, 'status' => 'published' ] );
    }

    public static function close_survey( \WP_REST_Request $req ) {
        global $wpdb;
        $id    = (int) $req['id'];
        $table = $wpdb->prefix . 'sfs_hr_surveys';

        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'closed',
                 closed_at = %s,
                 updated_at = %s
             WHERE id = %d AND status = 'published'",
            current_time( 'mysql' ),
            current_time( 'mysql' ),
            $id
        ) );

        if ( $result === false || $result === 0 ) {
            return Rest_Response::error( 'invalid_state', __( 'Only published surveys can be closed.', 'sfs-hr' ), 409 );
        }

        return Rest_Response::success( [ 'id' => $id, 'status' => 'closed' ] );
    }

    /* ─── Questions ─── */

    public static function list_questions( \WP_REST_Request $req ) {
        global $wpdb;
        $id = (int) $req['id'];

        $survey = $wpdb->get_row( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d",
            $id
        ) );
        if ( ! $survey ) {
            return Rest_Response::error( 'not_found', __( 'Survey not found.', 'sfs-hr' ), 404 );
        }
        if ( ! current_user_can( 'sfs_hr.manage' ) && $survey->status !== 'published' ) {
            return Rest_Response::error( 'forbidden', __( 'Survey not available.', 'sfs-hr' ), 403 );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, survey_id, sort_order, question_text, question_type, options_json, is_required
             FROM {$wpdb->prefix}sfs_hr_survey_questions
             WHERE survey_id = %d
             ORDER BY sort_order ASC, id ASC",
            $id
        ), ARRAY_A );

        return Rest_Response::success( array_map( [ self::class, 'format_question' ], $rows ?: [] ) );
    }

    public static function create_question( \WP_REST_Request $req ) {
        global $wpdb;
        $id = (int) $req['id'];

        $survey = $wpdb->get_row( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d",
            $id
        ) );
        if ( ! $survey ) {
            return Rest_Response::error( 'not_found', __( 'Survey not found.', 'sfs-hr' ), 404 );
        }
        if ( $survey->status === 'closed' ) {
            return Rest_Response::error( 'invalid_state', __( 'Cannot add questions to a closed survey.', 'sfs-hr' ), 409 );
        }

        $text = trim( (string) $req->get_param( 'question_text' ) );
        if ( $text === '' ) {
            return Rest_Response::error( 'invalid_input', __( 'question_text is required.', 'sfs-hr' ), 400 );
        }

        $type = (string) $req->get_param( 'question_type' ) ?: 'rating';
        if ( ! in_array( $type, [ 'rating', 'text', 'choice', 'yes_no' ], true ) ) {
            return Rest_Response::error( 'invalid_input', __( 'Invalid question_type.', 'sfs-hr' ), 400 );
        }

        $options = $req->get_param( 'options' );
        $options = is_array( $options ) ? array_values( array_map( 'sanitize_text_field', $options ) ) : [];

        $sort_order = $req->get_param( 'sort_order' );
        if ( $sort_order === null ) {
            $sort_order = 1 + (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(MAX(sort_order), -1) FROM {$wpdb->prefix}sfs_hr_survey_questions WHERE survey_id = %d",
                $id
            ) );
        }

        $inserted = $wpdb->insert( $wpdb->prefix . 'sfs_hr_survey_questions', [
            'survey_id'     => $id,
            'sort_order'    => (int) $sort_order,
            'question_text' => sanitize_text_field( $text ),
            'question_type' => $type,
            'options_json'  => $options ? wp_json_encode( $options ) : null,
            'is_required'   => $req->get_param( 'is_required' ) === false ? 0 : 1,
            'created_at'    => current_time( 'mysql' ),
        ] );

        if ( ! $inserted ) {
            return Rest_Response::error( 'insert_failed', __( 'Failed to create question.', 'sfs-hr' ), 500 );
        }

        $qid = (int) $wpdb->insert_id;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}sfs_hr_survey_questions WHERE id = %d", $qid ), ARRAY_A );
        return Rest_Response::success( self::format_question( $row ), 201 );
    }

    public static function update_question( \WP_REST_Request $req ) {
        global $wpdb;
        $qid   = (int) $req['qid'];
        $table = $wpdb->prefix . 'sfs_hr_survey_questions';

        $question = $wpdb->get_row( $wpdb->prepare(
            "SELECT q.*, s.status AS survey_status
             FROM {$table} q
             INNER JOIN {$wpdb->prefix}sfs_hr_surveys s ON s.id = q.survey_id
             WHERE q.id = %d",
            $qid
        ) );
        if ( ! $question ) {
            return Rest_Response::error( 'not_found', __( 'Question not found.', 'sfs-hr' ), 404 );
        }
        if ( $question->survey_status === 'closed' ) {
            return Rest_Response::error( 'invalid_state', __( 'Cannot edit questions on a closed survey.', 'sfs-hr' ), 409 );
        }

        $set = [];

        $text = $req->get_param( 'question_text' );
        if ( $text !== null ) {
            $text = trim( (string) $text );
            if ( $text === '' ) {
                return Rest_Response::error( 'invalid_input', __( 'question_text cannot be empty.', 'sfs-hr' ), 400 );
            }
            $set['question_text'] = sanitize_text_field( $text );
        }

        $type = $req->get_param( 'question_type' );
        if ( $type !== null ) {
            if ( ! in_array( $type, [ 'rating', 'text', 'choice', 'yes_no' ], true ) ) {
                return Rest_Response::error( 'invalid_input', __( 'Invalid question_type.', 'sfs-hr' ), 400 );
            }
            $set['question_type'] = $type;
        }

        $options = $req->get_param( 'options' );
        if ( $options !== null ) {
            $options             = is_array( $options ) ? array_values( array_map( 'sanitize_text_field', $options ) ) : [];
            $set['options_json'] = $options ? wp_json_encode( $options ) : null;
        }

        $sort_order = $req->get_param( 'sort_order' );
        if ( $sort_order !== null ) {
            $set['sort_order'] = (int) $sort_order;
        }

        $is_required = $req->get_param( 'is_required' );
        if ( $is_required !== null ) {
            $set['is_required'] = $is_required ? 1 : 0;
        }

        if ( empty( $set ) ) {
            return Rest_Response::error( 'invalid_input', __( 'No fields to update.', 'sfs-hr' ), 400 );
        }

        $wpdb->update( $table, $set, [ 'id' => $qid ] );

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $qid ), ARRAY_A );
        return Rest_Response::success( self::format_question( $row ) );
    }

    public static function delete_question( \WP_REST_Request $req ) {
        global $wpdb;
        $qid = (int) $req['qid'];

        $question = $wpdb->get_row( $wpdb->prepare(
            "SELECT q.survey_id, s.status AS survey_status
             FROM {$wpdb->prefix}sfs_hr_survey_questions q
             INNER JOIN {$wpdb->prefix}sfs_hr_surveys s ON s.id = q.survey_id
             WHERE q.id = %d",
            $qid
        ) );
        if ( ! $question ) {
            return Rest_Response::error( 'not_found', __( 'Question not found.', 'sfs-hr' ), 404 );
        }
        if ( $question->survey_status !== 'draft' ) {
            return Rest_Response::error( 'invalid_state', __( 'Only questions on draft surveys can be deleted.', 'sfs-hr' ), 409 );
        }

        $deleted = $wpdb->delete( $wpdb->prefix . 'sfs_hr_survey_questions', [ 'id' => $qid ] );
        if ( ! $deleted ) {
            return Rest_Response::error( 'delete_failed', __( 'Could not delete question.', 'sfs-hr' ), 500 );
        }

        return Rest_Response::success( [ 'deleted' => true, 'id' => $qid ] );
    }

    /* ─── Responses ─── */

    public static function list_responses( \WP_REST_Request $req ) {
        global $wpdb;
        $id     = (int) $req['id'];
        $paging = Rest_Response::parse_pagination( $req, 20, 100 );

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d",
            $id
        ) );
        if ( ! $exists ) {
            return Rest_Response::error( 'not_found', __( 'Survey not found.', 'sfs-hr' ), 404 );
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_survey_responses WHERE survey_id = %d",
            $id
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.survey_id, r.employee_id, r.submitted_at
             FROM {$wpdb->prefix}sfs_hr_survey_responses r
             WHERE r.survey_id = %d
             ORDER BY r.submitted_at DESC
             LIMIT %d OFFSET %d",
            $id,
            $paging['per_page'],
            $paging['offset']
        ), ARRAY_A );

        // Embed per-response answers.
        $data = [];
        foreach ( $rows as $row ) {
            $answers = $wpdb->get_results( $wpdb->prepare(
                "SELECT question_id, answer_text, answer_rating
                 FROM {$wpdb->prefix}sfs_hr_survey_answers
                 WHERE response_id = %d",
                (int) $row['id']
            ), ARRAY_A );

            $row['answers'] = array_map(
                function ( $a ) {
                    return [
                        'question_id'   => (int) $a['question_id'],
                        'answer_text'   => $a['answer_text'],
                        'answer_rating' => $a['answer_rating'] !== null ? (int) $a['answer_rating'] : null,
                    ];
                },
                $answers ?: []
            );

            $row['id']          = (int) $row['id'];
            $row['survey_id']   = (int) $row['survey_id'];
            $row['employee_id'] = (int) $row['employee_id'];

            $data[] = $row;
        }

        return Rest_Response::paginated( $data, $total, $paging['page'], $paging['per_page'] );
    }

    public static function submit_response( \WP_REST_Request $req ) {
        global $wpdb;
        $id          = (int) $req['id'];
        $employee_id = self::current_employee_id();

        if ( ! $employee_id ) {
            return Rest_Response::error( 'no_employee', __( 'No employee record for the current user.', 'sfs-hr' ), 403 );
        }

        $survey = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d",
            $id
        ) );
        if ( ! $survey ) {
            return Rest_Response::error( 'not_found', __( 'Survey not found.', 'sfs-hr' ), 404 );
        }
        if ( $survey->status !== 'published' ) {
            return Rest_Response::error( 'invalid_state', __( 'Survey is not accepting responses.', 'sfs-hr' ), 409 );
        }

        $already = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_survey_responses WHERE survey_id = %d AND employee_id = %d",
            $id,
            $employee_id
        ) );
        if ( $already ) {
            return Rest_Response::error( 'already_submitted', __( 'You have already responded to this survey.', 'sfs-hr' ), 409 );
        }

        $answers = $req->get_param( 'answers' );
        if ( ! is_array( $answers ) || empty( $answers ) ) {
            return Rest_Response::error( 'invalid_input', __( 'answers must be a non-empty array.', 'sfs-hr' ), 400 );
        }

        // Validate all answered question_ids belong to this survey and every
        // required question is answered.
        $questions = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, question_type, is_required
             FROM {$wpdb->prefix}sfs_hr_survey_questions
             WHERE survey_id = %d",
            $id
        ), OBJECT_K );

        if ( empty( $questions ) ) {
            return Rest_Response::error( 'no_questions', __( 'Survey has no questions.', 'sfs-hr' ), 409 );
        }

        $valid_qids  = array_map( 'intval', array_keys( $questions ) );
        $answered    = [];
        $answer_rows = [];

        foreach ( $answers as $a ) {
            if ( ! is_array( $a ) || ! isset( $a['question_id'] ) ) {
                return Rest_Response::error( 'invalid_input', __( 'Each answer must include question_id.', 'sfs-hr' ), 400 );
            }
            $qid = (int) $a['question_id'];
            if ( ! in_array( $qid, $valid_qids, true ) ) {
                return Rest_Response::error( 'invalid_input', __( 'Answer references an unknown question.', 'sfs-hr' ), 400 );
            }
            $answered[] = $qid;

            $answer_rows[] = [
                'question_id'   => $qid,
                'answer_text'   => isset( $a['answer_text'] ) ? sanitize_textarea_field( (string) $a['answer_text'] ) : null,
                'answer_rating' => isset( $a['answer_rating'] ) ? max( 0, min( 10, (int) $a['answer_rating'] ) ) : null,
            ];
        }

        foreach ( $questions as $qid => $q ) {
            if ( (int) $q->is_required === 1 && ! in_array( (int) $qid, $answered, true ) ) {
                return Rest_Response::error( 'missing_required', __( 'One or more required questions were not answered.', 'sfs-hr' ), 400 );
            }
        }

        $now = current_time( 'mysql' );

        $inserted = $wpdb->insert( $wpdb->prefix . 'sfs_hr_survey_responses', [
            'survey_id'    => $id,
            'employee_id'  => $employee_id,
            'submitted_at' => $now,
        ] );
        if ( ! $inserted ) {
            return Rest_Response::error( 'insert_failed', __( 'Failed to submit response.', 'sfs-hr' ), 500 );
        }

        $response_id = (int) $wpdb->insert_id;

        foreach ( $answer_rows as $row ) {
            $wpdb->insert( $wpdb->prefix . 'sfs_hr_survey_answers', [
                'response_id'   => $response_id,
                'question_id'   => $row['question_id'],
                'answer_text'   => $row['answer_text'],
                'answer_rating' => $row['answer_rating'],
            ] );
        }

        return Rest_Response::success( [
            'response_id' => $response_id,
            'submitted_at' => $now,
        ], 201 );
    }

    /* ─── Formatting ─── */

    private static function format_survey( array $row ): array {
        $target_ids = null;
        if ( ! empty( $row['target_ids'] ) ) {
            $decoded    = json_decode( (string) $row['target_ids'], true );
            $target_ids = is_array( $decoded ) ? array_values( array_map( 'intval', $decoded ) ) : null;
        }
        return [
            'id'           => (int) $row['id'],
            'title'        => (string) $row['title'],
            'description'  => $row['description'],
            'status'       => (string) $row['status'],
            'is_anonymous' => (bool) (int) $row['is_anonymous'],
            'target_scope' => (string) $row['target_scope'],
            'target_ids'   => $target_ids,
            'created_by'   => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'published_at' => $row['published_at'],
            'closed_at'    => $row['closed_at'],
            'created_at'   => $row['created_at'],
            'updated_at'   => $row['updated_at'],
        ];
    }

    private static function format_question( array $row ): array {
        $options = null;
        if ( ! empty( $row['options_json'] ) ) {
            $decoded = json_decode( (string) $row['options_json'], true );
            $options = is_array( $decoded ) ? array_values( $decoded ) : null;
        }
        return [
            'id'            => (int) $row['id'],
            'survey_id'     => (int) $row['survey_id'],
            'sort_order'    => (int) $row['sort_order'],
            'question_text' => (string) $row['question_text'],
            'question_type' => (string) $row['question_type'],
            'options'       => $options,
            'is_required'   => (bool) (int) $row['is_required'],
        ];
    }
}
