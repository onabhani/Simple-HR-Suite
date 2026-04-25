<?php
namespace SFS\HR\Modules\Resignation\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Resignation\Services\Notice_Service;
use SFS\HR\Modules\Resignation\Services\Offboarding_Service;
use SFS\HR\Modules\Resignation\Services\Exit_Interview_Service;

/**
 * Resignation REST API
 *
 * Endpoints for M7 features:
 *   - Notice Period management
 *   - Offboarding templates & tasks
 *   - Exit interviews & analytics
 *
 * Base: /sfs-hr/v1/resignation/...
 */
class Resignation_REST {

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        $ns = 'sfs-hr/v1';

        // ── Notice Period ──────────────────────────────────────────────────
        register_rest_route( $ns, '/resignation/notice/config', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'notice_config_get' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'notice_config_save' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/resignation/(?P<id>\d+)/notice/calculate', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'notice_calculate' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/resignation/(?P<id>\d+)/buyout', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'notice_buyout' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/resignation/(?P<id>\d+)/garden-leave', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'garden_leave_get' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'garden_leave_set' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/resignation/(?P<id>\d+)/adjust-notice', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'notice_adjust' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        // ── Offboarding Templates ──────────────────────────────────────────
        register_rest_route( $ns, '/resignation/offboarding/templates', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'templates_list' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'template_create' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/resignation/offboarding/templates/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'template_get' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [ __CLASS__, 'template_update' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'template_delete' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        // ── Offboarding Tasks ──────────────────────────────────────────────
        register_rest_route( $ns, '/resignation/(?P<id>\d+)/offboarding/generate', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'tasks_generate' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/resignation/(?P<id>\d+)/offboarding/tasks', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'tasks_list' ],
            'permission_callback' => [ __CLASS__, 'can_view_resignation' ],
        ] );

        register_rest_route( $ns, '/resignation/(?P<id>\d+)/offboarding/progress', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'tasks_progress' ],
            'permission_callback' => [ __CLASS__, 'can_view_resignation' ],
        ] );

        register_rest_route( $ns, '/resignation/offboarding/tasks/(?P<task_id>\d+)/complete', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'task_complete' ],
            'permission_callback' => [ __CLASS__, 'can_modify_task' ],
        ] );

        register_rest_route( $ns, '/resignation/offboarding/tasks/(?P<task_id>\d+)/skip', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'task_skip' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/resignation/offboarding/overdue', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'tasks_overdue' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        // ── Exit Interviews ────────────────────────────────────────────────
        register_rest_route( $ns, '/resignation/exit-interview/questions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'questions_list' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'question_create' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/resignation/exit-interview/questions/(?P<qid>\d+)', [
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [ __CLASS__, 'question_update' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'question_delete' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/resignation/exit-interview/questions/reorder', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'questions_reorder' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/resignation/(?P<id>\d+)/exit-interview', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'interview_get' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'interview_create' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/resignation/exit-interview/(?P<iid>\d+)/schedule', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'interview_schedule' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
        ] );

        register_rest_route( $ns, '/resignation/exit-interview/(?P<iid>\d+)/submit', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'interview_submit' ],
            'permission_callback' => [ __CLASS__, 'can_view' ],
        ] );

        register_rest_route( $ns, '/resignation/exit-interview/analytics', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'interview_analytics' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
            'args'                => [
                'start_date' => [ 'required' => true, 'type' => 'string' ],
                'end_date'   => [ 'required' => true, 'type' => 'string' ],
                'dept_id'    => [ 'type' => 'integer', 'default' => 0 ],
            ],
        ] );

        // ── M9.1: core resignation lifecycle ──────────────────────────────
        // Submit / list / detail / approve / reject / cancel. The existing
        // multi-tier (manager → HR → finance) approval flow lives in the
        // admin UI handlers. The /approve endpoint here applies a direct
        // admin override; full multi-tier remains an admin-UI flow until
        // refactored into the service.

        register_rest_route( $ns, '/resignations', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_resignations' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
                'args'                => [
                    'status'   => [ 'type' => 'string',  'default' => 'pending' ],
                    'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                    'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                    'search'   => [ 'type' => 'string' ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'submit_resignation' ],
                'permission_callback' => [ __CLASS__, 'can_submit_resignation' ],
                'args'                => [
                    'employee_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
                    'resignation_date' => [ 'type' => 'string',  'required' => true, 'description' => 'YYYY-MM-DD' ],
                    'last_working_day' => [ 'type' => 'string',  'description' => 'YYYY-MM-DD; auto-computed from notice when omitted' ],
                    'reason'           => [ 'type' => 'string',  'required' => true ],
                    'resignation_type' => [ 'type' => 'string',  'enum' => [ 'regular', 'final_exit' ], 'default' => 'regular' ],
                    'notice_period_days' => [ 'type' => 'integer', 'minimum' => 0 ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/resignations/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_resignation' ],
            'permission_callback' => [ __CLASS__, 'can_view_resignation_lifecycle' ],
        ] );

        register_rest_route( $ns, '/resignations/(?P<id>\d+)/approve', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'approve_resignation_lifecycle' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
            'args'                => [
                'note' => [ 'type' => 'string' ],
            ],
        ] );

        register_rest_route( $ns, '/resignations/(?P<id>\d+)/reject', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'reject_resignation_lifecycle' ],
            'permission_callback' => [ __CLASS__, 'can_manage' ],
            'args'                => [
                'reason' => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        register_rest_route( $ns, '/resignations/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'cancel_resignation_lifecycle' ],
            'permission_callback' => [ __CLASS__, 'can_cancel_resignation' ],
            'args'                => [
                'reason' => [ 'type' => 'string' ],
            ],
        ] );
    }

    // ── Permission callbacks ────────────────────────────────────────────

    public static function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    public static function can_view(): bool {
        return current_user_can( 'sfs_hr.view' );
    }

    /**
     * Submit-resignation gate. Admins can submit on anyone's behalf.
     * Employees can only submit for themselves (handler enforces that
     * employee_id, when provided, matches their own record).
     */
    public static function can_submit_resignation(): bool {
        return is_user_logged_in();
    }

    /**
     * View a single resignation. Admins always; otherwise the row's
     * employee must belong to the requester.
     */
    public static function can_view_resignation_lifecycle( \WP_REST_Request $req ): bool {
        if ( current_user_can( 'sfs_hr.manage' ) ) {
            return true;
        }
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $r = \SFS\HR\Modules\Resignation\Services\Resignation_Service::get_resignation( (int) $req['id'] );
        if ( ! $r ) {
            return false;
        }
        return self::is_owner_of_employee( (int) ( $r['employee_id'] ?? 0 ) );
    }

    /**
     * Cancel gate. Admins always; the owning employee can cancel their
     * own pending resignation.
     */
    public static function can_cancel_resignation( \WP_REST_Request $req ): bool {
        if ( current_user_can( 'sfs_hr.manage' ) ) {
            return true;
        }
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $r = \SFS\HR\Modules\Resignation\Services\Resignation_Service::get_resignation( (int) $req['id'] );
        return $r && self::is_owner_of_employee( (int) ( $r['employee_id'] ?? 0 ) );
    }

    private static function is_owner_of_employee( int $employee_id ): bool {
        if ( $employee_id <= 0 ) {
            return false;
        }
        global $wpdb;
        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d",
            $employee_id
        ) );
        return $owner > 0 && $owner === get_current_user_id();
    }

    private static function current_employee_id_or_null(): ?int {
        global $wpdb;
        $uid = get_current_user_id();
        if ( ! $uid ) {
            return null;
        }
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d AND status = 'active' LIMIT 1",
            $uid
        ) );
        return $id ? (int) $id : null;
    }

    /**
     * Resource-aware check: user must have sfs_hr.manage OR be the resignation's
     * employee or their department manager.
     */
    public static function can_view_resignation( \WP_REST_Request $req ): bool {
        if ( current_user_can( 'sfs_hr.manage' ) ) {
            return true;
        }
        $resignation = \SFS\HR\Modules\Resignation\Services\Resignation_Service::get_resignation( (int) $req['id'] );
        if ( ! $resignation ) {
            return false;
        }
        $current_user_id = get_current_user_id();
        // Owner check.
        if ( (int) ( $resignation['emp_user_id'] ?? 0 ) === $current_user_id ) {
            return true;
        }
        // Department manager check.
        $manager_depts = \SFS\HR\Modules\Resignation\Services\Resignation_Service::get_manager_dept_ids( $current_user_id );
        return in_array( (int) ( $resignation['dept_id'] ?? 0 ), $manager_depts, true );
    }

    /**
     * Resource-aware check for task mutation: user must have sfs_hr.manage OR
     * be the assigned_to user on the task.
     */
    public static function can_modify_task( \WP_REST_Request $req ): bool {
        if ( current_user_can( 'sfs_hr.manage' ) ) {
            return true;
        }
        global $wpdb;
        $task = $wpdb->get_row( $wpdb->prepare(
            "SELECT assigned_to FROM {$wpdb->prefix}sfs_hr_offboarding_tasks WHERE id = %d",
            (int) $req['task_id']
        ), ARRAY_A );
        if ( ! $task ) {
            return false;
        }
        return (int) ( $task['assigned_to'] ?? 0 ) === get_current_user_id();
    }

    // ── Notice Period endpoints ─────────────────────────────────────────

    public static function notice_config_get(): \WP_REST_Response {
        return rest_ensure_response( Notice_Service::get_notice_config() );
    }

    public static function notice_config_save( \WP_REST_Request $req ): \WP_REST_Response {
        $config = $req->get_json_params();
        $result = Notice_Service::save_notice_config( $config );
        return rest_ensure_response( $result );
    }

    public static function notice_calculate( \WP_REST_Request $req ): \WP_REST_Response {
        $resignation_id = (int) $req['id'];
        $resignation    = \SFS\HR\Modules\Resignation\Services\Resignation_Service::get_resignation( $resignation_id );
        if ( ! $resignation ) {
            return new \WP_REST_Response( [ 'error' => __( 'Resignation not found.', 'sfs-hr' ) ], 404 );
        }
        $notice_days = Notice_Service::get_notice_days_for_employee( (int) $resignation['employee_id'] );
        $last_day    = Notice_Service::calculate_last_working_day( $resignation['resignation_date'], $notice_days );
        return rest_ensure_response( [
            'notice_days'     => $notice_days,
            'last_working_day' => $last_day,
        ] );
    }

    public static function notice_buyout( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Notice_Service::process_buyout( (int) $req['id'], get_current_user_id() );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function garden_leave_get( \WP_REST_Request $req ): \WP_REST_Response {
        $data = Notice_Service::get_garden_leave( (int) $req['id'] );
        return rest_ensure_response( $data ?: [ 'start' => null, 'end' => null ] );
    }

    public static function garden_leave_set( \WP_REST_Request $req ): \WP_REST_Response {
        $params = $req->get_json_params();
        $result = Notice_Service::set_garden_leave(
            (int) $req['id'],
            sanitize_text_field( $params['start'] ?? '' ),
            sanitize_text_field( $params['end'] ?? '' ),
            get_current_user_id()
        );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function notice_adjust( \WP_REST_Request $req ): \WP_REST_Response {
        $params = $req->get_json_params();
        $result = Notice_Service::adjust_notice_period(
            (int) $req['id'],
            (int) ( $params['notice_days'] ?? 0 ),
            sanitize_text_field( $params['reason'] ?? '' ),
            get_current_user_id()
        );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    // ── Offboarding Template endpoints ──────────────────────────────────

    public static function templates_list( \WP_REST_Request $req ): \WP_REST_Response {
        $param  = $req->get_param( 'active_only' );
        $active = null === $param ? true : rest_sanitize_boolean( $param );
        return rest_ensure_response( Offboarding_Service::list_templates( $active ) );
    }

    public static function template_get( \WP_REST_Request $req ): \WP_REST_Response {
        $t = Offboarding_Service::get_template( (int) $req['id'] );
        if ( ! $t ) {
            return new \WP_REST_Response( [ 'error' => __( 'Template not found.', 'sfs-hr' ) ], 404 );
        }
        return rest_ensure_response( $t );
    }

    public static function template_create( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Offboarding_Service::create_template( $req->get_json_params() );
        $status = ( $result['success'] ?? false ) ? 201 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function template_update( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Offboarding_Service::update_template( (int) $req['id'], $req->get_json_params() );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function template_delete( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Offboarding_Service::delete_template( (int) $req['id'] );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    // ── Offboarding Task endpoints ──────────────────────────────────────

    public static function tasks_generate( \WP_REST_Request $req ): \WP_REST_Response {
        $params      = $req->get_json_params();
        $template_id = isset( $params['template_id'] ) ? (int) $params['template_id'] : null;
        $result      = Offboarding_Service::generate_tasks( (int) $req['id'], $template_id );
        $status      = ( $result['success'] ?? false ) ? 201 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function tasks_list( \WP_REST_Request $req ): \WP_REST_Response {
        return rest_ensure_response( Offboarding_Service::get_tasks( (int) $req['id'] ) );
    }

    public static function tasks_progress( \WP_REST_Request $req ): \WP_REST_Response {
        return rest_ensure_response( Offboarding_Service::get_progress( (int) $req['id'] ) );
    }

    public static function task_complete( \WP_REST_Request $req ): \WP_REST_Response {
        $params = $req->get_json_params();
        $result = Offboarding_Service::complete_task(
            (int) $req['task_id'],
            get_current_user_id(),
            sanitize_text_field( $params['notes'] ?? '' )
        );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function task_skip( \WP_REST_Request $req ): \WP_REST_Response {
        $params = $req->get_json_params();
        $result = Offboarding_Service::skip_task(
            (int) $req['task_id'],
            get_current_user_id(),
            sanitize_text_field( $params['reason'] ?? '' )
        );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function tasks_overdue(): \WP_REST_Response {
        return rest_ensure_response( Offboarding_Service::get_overdue_tasks() );
    }

    // ── Exit Interview endpoints ────────────────────────────────────────

    public static function questions_list(): \WP_REST_Response {
        return rest_ensure_response( Exit_Interview_Service::get_questions() );
    }

    public static function question_create( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Exit_Interview_Service::create_question( $req->get_json_params() );
        $status = ( $result['success'] ?? false ) ? 201 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function question_update( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Exit_Interview_Service::update_question( (int) $req['qid'], $req->get_json_params() );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function question_delete( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Exit_Interview_Service::delete_question( (int) $req['qid'] );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function questions_reorder( \WP_REST_Request $req ): \WP_REST_Response {
        $order  = $req->get_json_params();
        $result = Exit_Interview_Service::reorder_questions( $order );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function interview_get( \WP_REST_Request $req ): \WP_REST_Response {
        $data = Exit_Interview_Service::get_interview_by_resignation( (int) $req['id'] );
        if ( ! $data ) {
            return new \WP_REST_Response( [ 'error' => __( 'No exit interview found.', 'sfs-hr' ) ], 404 );
        }
        return rest_ensure_response( $data );
    }

    public static function interview_create( \WP_REST_Request $req ): \WP_REST_Response {
        $result = Exit_Interview_Service::create_interview( (int) $req['id'] );
        $status = ( $result['success'] ?? false ) ? 201 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function interview_schedule( \WP_REST_Request $req ): \WP_REST_Response {
        $params = $req->get_json_params();
        $result = Exit_Interview_Service::schedule_interview(
            (int) $req['iid'],
            sanitize_text_field( $params['date'] ?? '' ),
            (int) ( $params['interviewer_id'] ?? 0 )
        );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function interview_submit( \WP_REST_Request $req ): \WP_REST_Response {
        $params = $req->get_json_params();
        $result = Exit_Interview_Service::submit_responses(
            (int) $req['iid'],
            $params['responses'] ?? [],
            sanitize_text_field( $params['exit_reason'] ?? '' ),
            (bool) ( $params['is_anonymous'] ?? false ),
            sanitize_textarea_field( $params['comments'] ?? '' ),
            isset( $params['rehire_eligible'] ) ? (bool) $params['rehire_eligible'] : null,
            sanitize_text_field( $params['rehire_notes'] ?? '' )
        );
        $status = ( $result['success'] ?? false ) ? 200 : 400;
        return new \WP_REST_Response( $result, $status );
    }

    public static function interview_analytics( \WP_REST_Request $req ): \WP_REST_Response {
        $dept_id = (int) $req->get_param( 'dept_id' );
        $result  = Exit_Interview_Service::get_exit_analytics(
            sanitize_text_field( $req->get_param( 'start_date' ) ),
            sanitize_text_field( $req->get_param( 'end_date' ) ),
            $dept_id > 0 ? $dept_id : null
        );
        return rest_ensure_response( $result );
    }

    /* ───────────────────────────────────────────────────────────── */
    /*  M9.1 — Core resignation lifecycle handlers                    */
    /* ───────────────────────────────────────────────────────────── */

    public static function list_resignations( \WP_REST_Request $req ) {
        $svc      = '\\SFS\\HR\\Modules\\Resignation\\Services\\Resignation_Service';
        $page     = max( 1, (int) ( $req->get_param( 'page' ) ?? 1 ) );
        $per_page = max( 1, min( 100, (int) ( $req->get_param( 'per_page' ) ?? 20 ) ) );
        $status   = (string) ( $req->get_param( 'status' ) ?? 'pending' );
        $search   = (string) ( $req->get_param( 'search' ) ?? '' );

        // Non-admins are auto-scoped to their own resignations.
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            $own_emp_id = self::current_employee_id_or_null();
            if ( ! $own_emp_id ) {
                return \SFS\HR\Core\Rest\Rest_Response::paginated( [], 0, $page, $per_page );
            }
            $rows = $svc::get_resignations( $status, $page, $per_page, $search );
            $rows = array_values( array_filter(
                $rows,
                fn( $r ) => (int) ( $r['employee_id'] ?? 0 ) === $own_emp_id
            ) );
            return \SFS\HR\Core\Rest\Rest_Response::paginated( $rows, count( $rows ), $page, $per_page );
        }

        $rows   = $svc::get_resignations( $status, $page, $per_page, $search );
        $counts = $svc::get_status_counts( $search );
        $total  = (int) ( $counts[ $status ] ?? count( $rows ) );

        return \SFS\HR\Core\Rest\Rest_Response::paginated( $rows, $total, $page, $per_page );
    }

    public static function get_resignation( \WP_REST_Request $req ) {
        $svc = '\\SFS\\HR\\Modules\\Resignation\\Services\\Resignation_Service';
        $r   = $svc::get_resignation( (int) $req['id'] );
        if ( ! $r ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'not_found', __( 'Resignation not found.', 'sfs-hr' ), 404 );
        }
        return \SFS\HR\Core\Rest\Rest_Response::success( $r );
    }

    public static function submit_resignation( \WP_REST_Request $req ) {
        $svc = '\\SFS\\HR\\Modules\\Resignation\\Services\\Resignation_Service';

        $employee_id  = (int) ( $req->get_param( 'employee_id' ) ?? 0 );
        $admin        = current_user_can( 'sfs_hr.manage' );

        // Resolve employee_id when omitted; non-admins must self-submit.
        if ( ! $admin ) {
            $own = self::current_employee_id_or_null();
            if ( ! $own ) {
                return \SFS\HR\Core\Rest\Rest_Response::error( 'no_employee_record', __( 'No employee record for the current user.', 'sfs-hr' ), 403 );
            }
            if ( $employee_id && $employee_id !== $own ) {
                return \SFS\HR\Core\Rest\Rest_Response::error( 'forbidden', __( 'You can only submit a resignation for yourself.', 'sfs-hr' ), 403 );
            }
            $employee_id = $own;
        }

        if ( $employee_id <= 0 ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'invalid_input', __( 'employee_id is required.', 'sfs-hr' ), 400 );
        }

        $resignation_date = (string) $req->get_param( 'resignation_date' );
        $reason           = trim( (string) $req->get_param( 'reason' ) );
        $type             = (string) ( $req->get_param( 'resignation_type' ) ?? 'regular' );
        $type             = in_array( $type, [ 'regular', 'final_exit' ], true ) ? $type : 'regular';

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $resignation_date ) || $reason === '' ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'invalid_input', __( 'resignation_date (YYYY-MM-DD) and reason are required.', 'sfs-hr' ), 400 );
        }

        $notice_days = (int) ( $req->get_param( 'notice_period_days' ) ?? 30 );
        $notice_days = max( 0, $notice_days );

        $last_working_day = (string) $req->get_param( 'last_working_day' );
        if ( $last_working_day === '' || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $last_working_day ) ) {
            try {
                $dt = new \DateTimeImmutable( $resignation_date );
                $last_working_day = $dt->modify( '+' . $notice_days . ' days' )->format( 'Y-m-d' );
            } catch ( \Exception $e ) {
                return \SFS\HR\Core\Rest\Rest_Response::error( 'invalid_input', __( 'Could not compute last_working_day.', 'sfs-hr' ), 400 );
            }
        }

        $id = $svc::create_resignation( [
            'employee_id'        => $employee_id,
            'resignation_date'   => $resignation_date,
            'resignation_type'   => $type,
            'notice_period_days' => $notice_days,
            'last_working_day'   => $last_working_day,
            'reason'             => sanitize_textarea_field( $reason ),
        ] );

        if ( ! $id ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'insert_failed', __( 'Failed to submit resignation.', 'sfs-hr' ), 500 );
        }

        do_action( 'sfs_hr_resignation_submitted', (int) $id, $employee_id );

        return \SFS\HR\Core\Rest\Rest_Response::success( $svc::get_resignation( (int) $id ), 201 );
    }

    /**
     * Direct admin override approval — bypasses the multi-tier
     * (manager → HR → finance) workflow that the admin UI runs through.
     * Use this when the multi-tier flow is unavailable (e.g. external
     * client) or for edge-case overrides. The full multi-tier flow stays
     * in the admin handler until refactored into the service.
     */
    public static function approve_resignation_lifecycle( \WP_REST_Request $req ) {
        $svc = '\\SFS\\HR\\Modules\\Resignation\\Services\\Resignation_Service';
        $id  = (int) $req['id'];

        $r = $svc::get_resignation( $id );
        if ( ! $r ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'not_found', __( 'Resignation not found.', 'sfs-hr' ), 404 );
        }
        if ( ($r['status'] ?? '') !== 'pending' ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'invalid_state', __( 'Only pending resignations can be approved.', 'sfs-hr' ), 409 );
        }

        $note = sanitize_textarea_field( (string) ( $req->get_param( 'note' ) ?? '' ) );
        $now  = current_time( 'mysql' );

        $ok = $svc::update_status( $id, 'approved', [
            'approver_note' => $note ?: null,
            'decided_at'    => $now,
        ] );

        if ( ! $ok ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'update_failed', __( 'Could not approve resignation.', 'sfs-hr' ), 500 );
        }

        do_action( 'sfs_hr_resignation_status_changed', $id, $r['status'], 'approved' );

        return \SFS\HR\Core\Rest\Rest_Response::success( $svc::get_resignation( $id ) );
    }

    public static function reject_resignation_lifecycle( \WP_REST_Request $req ) {
        $svc = '\\SFS\\HR\\Modules\\Resignation\\Services\\Resignation_Service';
        $id  = (int) $req['id'];

        $r = $svc::get_resignation( $id );
        if ( ! $r ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'not_found', __( 'Resignation not found.', 'sfs-hr' ), 404 );
        }
        if ( ($r['status'] ?? '') !== 'pending' ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'invalid_state', __( 'Only pending resignations can be rejected.', 'sfs-hr' ), 409 );
        }

        $reason = trim( (string) $req->get_param( 'reason' ) );
        if ( $reason === '' ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'invalid_input', __( 'reason is required.', 'sfs-hr' ), 400 );
        }

        $ok = $svc::update_status( $id, 'rejected', [
            'approver_note' => sanitize_textarea_field( $reason ),
            'decided_at'    => current_time( 'mysql' ),
        ] );

        if ( ! $ok ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'update_failed', __( 'Could not reject resignation.', 'sfs-hr' ), 500 );
        }

        do_action( 'sfs_hr_resignation_status_changed', $id, $r['status'], 'rejected' );

        return \SFS\HR\Core\Rest\Rest_Response::success( $svc::get_resignation( $id ) );
    }

    /**
     * Withdraw a pending resignation. Owner can cancel their own;
     * admins can cancel anyone's. Non-pending resignations cannot be
     * cancelled (use the offboarding flow to halt an approved one).
     */
    public static function cancel_resignation_lifecycle( \WP_REST_Request $req ) {
        $svc = '\\SFS\\HR\\Modules\\Resignation\\Services\\Resignation_Service';
        $id  = (int) $req['id'];

        $r = $svc::get_resignation( $id );
        if ( ! $r ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'not_found', __( 'Resignation not found.', 'sfs-hr' ), 404 );
        }
        if ( ($r['status'] ?? '') !== 'pending' ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'invalid_state', __( 'Only pending resignations can be cancelled.', 'sfs-hr' ), 409 );
        }

        $reason = sanitize_textarea_field( (string) ( $req->get_param( 'reason' ) ?? '' ) );

        $ok = $svc::update_status( $id, 'cancelled', [
            'approver_note' => $reason ?: null,
            'decided_at'    => current_time( 'mysql' ),
        ] );

        if ( ! $ok ) {
            return \SFS\HR\Core\Rest\Rest_Response::error( 'update_failed', __( 'Could not cancel resignation.', 'sfs-hr' ), 500 );
        }

        do_action( 'sfs_hr_resignation_status_changed', $id, $r['status'], 'cancelled' );

        return \SFS\HR\Core\Rest\Rest_Response::success( $svc::get_resignation( $id ) );
    }
}
