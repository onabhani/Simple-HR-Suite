<?php
namespace SFS\HR\Modules\Training\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Training\Services\Training_Service;
use SFS\HR\Modules\Training\Services\Enrollment_Service;
use SFS\HR\Modules\Training\Services\Certification_Service;

/**
 * Training_Rest
 *
 * M11 — REST endpoints for training programs, sessions, enrollments,
 *        training requests, certifications, and role requirements.
 *
 * Base: /sfs-hr/v1/training
 *
 * Programs:
 *   GET    /programs                          — active programs
 *   GET    /programs/all                      — all programs (admin)
 *   POST   /programs                          — upsert program (admin)
 *   POST   /programs/{id}/toggle              — activate/deactivate (admin)
 *
 * Sessions:
 *   GET    /sessions                          — list by program/status
 *   POST   /sessions                          — create session (admin)
 *   GET    /sessions/{id}                     — detail
 *   PUT    /sessions/{id}                     — update session (admin)
 *   POST   /sessions/{id}/cancel              — cancel (admin)
 *   POST   /sessions/{id}/complete            — mark complete (admin)
 *
 * Enrollments:
 *   GET    /sessions/{id}/enrollments         — list enrollments for session (admin)
 *   POST   /enrollments                       — enroll employee (admin)
 *   POST   /enrollments/{id}/cancel           — cancel enrollment (admin)
 *   POST   /enrollments/{id}/attended         — mark attended (admin)
 *   POST   /enrollments/{id}/complete         — complete with score (admin)
 *   GET    /my-enrollments                    — current employee enrollments
 *
 * Requests:
 *   GET    /requests/pending                  — pending requests (admin)
 *   POST   /requests                          — create request (employee)
 *   GET    /my-requests                       — current employee requests
 *   POST   /requests/{id}/approve             — approve (admin)
 *   POST   /requests/{id}/reject              — reject (admin)
 *
 * Certifications:
 *   POST   /certifications                    — add certification (admin)
 *   PUT    /certifications/{id}               — update (admin)
 *   GET    /certifications/{id}               — get single
 *   GET    /certifications/employee/{id}      — list for employee
 *   GET    /certifications/expiring           — expiring soon (admin)
 *   GET    /compliance                        — compliance report (admin)
 *
 * Role Requirements:
 *   GET    /requirements/{role}               — list required certs for role (admin)
 *   POST   /requirements                      — set required (admin)
 *   DELETE /requirements/{id}                 — remove requirement (admin)
 *
 * @since M11
 */
class Training_Rest {

    public static function register(): void {
        add_action( 'rest_api_init', [ new self(), 'routes' ] );
    }

    public function routes(): void {
        $ns   = 'sfs-hr/v1';
        $base = '/training';

        // ── Programs ───────────────────────────────────────────────────────

        register_rest_route( $ns, $base . '/programs', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'list_programs_active' ], 'permission_callback' => [ $this, 'can_view' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'upsert_program' ],       'permission_callback' => [ $this, 'can_manage' ] ],
        ] );

        register_rest_route( $ns, $base . '/programs/all', [
            'methods' => 'GET', 'callback' => [ $this, 'list_programs_all' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/programs/(?P<id>\d+)/toggle', [
            'methods' => 'POST', 'callback' => [ $this, 'toggle_program' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        // ── Sessions ───────────────────────────────────────────────────────

        register_rest_route( $ns, $base . '/sessions', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'list_sessions' ],  'permission_callback' => [ $this, 'can_view' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_session' ], 'permission_callback' => [ $this, 'can_manage' ] ],
        ] );

        register_rest_route( $ns, $base . '/sessions/(?P<id>\d+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_session' ], 'permission_callback' => [ $this, 'can_view' ] ],
            [ 'methods' => 'PUT', 'callback' => [ $this, 'update_session' ], 'permission_callback' => [ $this, 'can_manage' ] ],
        ] );

        register_rest_route( $ns, $base . '/sessions/(?P<id>\d+)/cancel', [
            'methods' => 'POST', 'callback' => [ $this, 'cancel_session' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/sessions/(?P<id>\d+)/complete', [
            'methods' => 'POST', 'callback' => [ $this, 'complete_session' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        // ── Enrollments ────────────────────────────────────────────────────

        register_rest_route( $ns, $base . '/sessions/(?P<id>\d+)/enrollments', [
            'methods' => 'GET', 'callback' => [ $this, 'list_session_enrollments' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/enrollments', [
            'methods' => 'POST', 'callback' => [ $this, 'enroll' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/enrollments/(?P<id>\d+)/cancel', [
            'methods' => 'POST', 'callback' => [ $this, 'cancel_enrollment' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/enrollments/(?P<id>\d+)/attended', [
            'methods' => 'POST', 'callback' => [ $this, 'mark_attended' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/enrollments/(?P<id>\d+)/complete', [
            'methods' => 'POST', 'callback' => [ $this, 'complete_enrollment' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/my-enrollments', [
            'methods' => 'GET', 'callback' => [ $this, 'my_enrollments' ], 'permission_callback' => [ $this, 'can_view' ],
        ] );

        // ── Requests ───────────────────────────────────────────────────────

        register_rest_route( $ns, $base . '/requests/pending', [
            'methods' => 'GET', 'callback' => [ $this, 'list_pending_requests' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/requests', [
            'methods' => 'POST', 'callback' => [ $this, 'create_request' ], 'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, $base . '/my-requests', [
            'methods' => 'GET', 'callback' => [ $this, 'my_requests' ], 'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, $base . '/requests/(?P<id>\d+)/approve', [
            'methods' => 'POST', 'callback' => [ $this, 'approve_request' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/requests/(?P<id>\d+)/reject', [
            'methods' => 'POST', 'callback' => [ $this, 'reject_request' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        // ── Certifications ─────────────────────────────────────────────────

        register_rest_route( $ns, $base . '/certifications', [
            'methods' => 'POST', 'callback' => [ $this, 'add_certification' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/certifications/expiring', [
            'methods' => 'GET', 'callback' => [ $this, 'get_expiring_certifications' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/certifications/employee/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [ $this, 'list_employee_certifications' ], 'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, $base . '/certifications/(?P<id>\d+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_certification' ], 'permission_callback' => [ $this, 'can_view' ] ],
            [ 'methods' => 'PUT', 'callback' => [ $this, 'update_certification' ], 'permission_callback' => [ $this, 'can_manage' ] ],
        ] );

        register_rest_route( $ns, $base . '/compliance', [
            'methods' => 'GET', 'callback' => [ $this, 'compliance_report' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        // ── Role Requirements ──────────────────────────────────────────────

        register_rest_route( $ns, $base . '/requirements/(?P<role>[a-z0-9_-]+)', [
            'methods' => 'GET', 'callback' => [ $this, 'list_required_for_role' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/requirements', [
            'methods' => 'POST', 'callback' => [ $this, 'set_required' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );

        register_rest_route( $ns, $base . '/requirements/(?P<id>\d+)', [
            'methods' => 'DELETE', 'callback' => [ $this, 'remove_required' ], 'permission_callback' => [ $this, 'can_manage' ],
        ] );
    }

    // ── Permission callbacks ───────────────────────────────────────────────

    public function can_view(): bool {
        return is_user_logged_in();
    }

    public function can_manage(): bool {
        return current_user_can( 'sfs_hr.manage' );
    }

    // ── Handlers: Programs ─────────────────────────────────────────────────

    public function list_programs_active(): \WP_REST_Response {
        return new \WP_REST_Response( Training_Service::list_programs( true ), 200 );
    }

    public function list_programs_all(): \WP_REST_Response {
        return new \WP_REST_Response( Training_Service::list_programs( false ), 200 );
    }

    public function upsert_program( \WP_REST_Request $req ): \WP_REST_Response {
        $data = [];

        foreach ( [ 'code', 'title', 'title_ar', 'description', 'category', 'provider',
                     'duration_hours', 'is_active', 'sort_order' ] as $key ) {
            $val = $req->get_param( $key );
            if ( $val !== null ) {
                $data[ $key ] = in_array( $key, [ 'duration_hours', 'is_active', 'sort_order' ], true )
                    ? (int) $val
                    : ( $key === 'description'
                        ? sanitize_textarea_field( (string) $val )
                        : sanitize_text_field( (string) $val ) );
            }
        }

        $result = Training_Service::upsert_program( $data );
        return $this->result_to_response( $result );
    }

    public function toggle_program( \WP_REST_Request $req ): \WP_REST_Response {
        $id        = (int) $req->get_param( 'id' );
        $is_active = (bool) $req->get_param( 'is_active' );

        $ok = Training_Service::set_program_active( $id, $is_active );
        return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 400 );
    }

    // ── Handlers: Sessions ─────────────────────────────────────────────────

    public function list_sessions( \WP_REST_Request $req ): \WP_REST_Response {
        $program_id = (int) $req->get_param( 'program_id' );
        $status     = sanitize_text_field( (string) ( $req->get_param( 'status' ) ?? '' ) );

        return new \WP_REST_Response( Training_Service::list_sessions( $program_id, $status ), 200 );
    }

    public function create_session( \WP_REST_Request $req ): \WP_REST_Response {
        $data = [];

        foreach ( [ 'program_id', 'title', 'start_date', 'end_date', 'start_time', 'end_time',
                     'location', 'trainer', 'capacity', 'notes', 'status' ] as $key ) {
            $val = $req->get_param( $key );
            if ( $val !== null ) {
                $data[ $key ] = in_array( $key, [ 'program_id', 'capacity' ], true )
                    ? (int) $val
                    : ( $key === 'notes'
                        ? sanitize_textarea_field( (string) $val )
                        : sanitize_text_field( (string) $val ) );
            }
        }

        $result = Training_Service::create_session( $data );
        return $this->result_to_response( $result );
    }

    public function get_session( \WP_REST_Request $req ): \WP_REST_Response {
        $id      = (int) $req->get_param( 'id' );
        $session = Training_Service::get_session( $id );

        if ( ! $session ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Session not found.', 'sfs-hr' ) ], 404 );
        }

        return new \WP_REST_Response( $session, 200 );
    }

    public function update_session( \WP_REST_Request $req ): \WP_REST_Response {
        $id   = (int) $req->get_param( 'id' );
        $data = [];

        foreach ( [ 'title', 'start_date', 'end_date', 'start_time', 'end_time',
                     'location', 'trainer', 'capacity', 'notes', 'status' ] as $key ) {
            $val = $req->get_param( $key );
            if ( $val !== null ) {
                $data[ $key ] = in_array( $key, [ 'capacity' ], true )
                    ? (int) $val
                    : ( $key === 'notes'
                        ? sanitize_textarea_field( (string) $val )
                        : sanitize_text_field( (string) $val ) );
            }
        }

        $result = Training_Service::update_session( $id, $data );
        return $this->result_to_response( $result );
    }

    public function cancel_session( \WP_REST_Request $req ): \WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $ok = Training_Service::cancel_session( $id );
        return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 400 );
    }

    public function complete_session( \WP_REST_Request $req ): \WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $ok = Training_Service::complete_session( $id );
        return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 400 );
    }

    // ── Handlers: Enrollments ──────────────────────────────────────────────

    public function list_session_enrollments( \WP_REST_Request $req ): \WP_REST_Response {
        $session_id = (int) $req->get_param( 'id' );
        return new \WP_REST_Response( Enrollment_Service::list_session_enrollments( $session_id ), 200 );
    }

    public function enroll( \WP_REST_Request $req ): \WP_REST_Response {
        $session_id  = (int) $req->get_param( 'session_id' );
        $employee_id = (int) $req->get_param( 'employee_id' );
        $enrolled_by = get_current_user_id();

        $result = Enrollment_Service::enroll( $session_id, $employee_id, $enrolled_by );
        return $this->result_to_response( $result );
    }

    public function cancel_enrollment( \WP_REST_Request $req ): \WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $ok = Enrollment_Service::cancel_enrollment( $id );
        return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 400 );
    }

    public function mark_attended( \WP_REST_Request $req ): \WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $ok = Enrollment_Service::mark_attended( $id );
        return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 400 );
    }

    public function complete_enrollment( \WP_REST_Request $req ): \WP_REST_Response {
        $id            = (int) $req->get_param( 'id' );
        $score         = $req->get_param( 'score' ) !== null ? (float) $req->get_param( 'score' ) : null;
        $cert_media_id = $req->get_param( 'cert_media_id' ) !== null ? (int) $req->get_param( 'cert_media_id' ) : null;

        $ok = Enrollment_Service::complete_enrollment( $id, $score, $cert_media_id );
        return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 400 );
    }

    public function my_enrollments(): \WP_REST_Response {
        $emp_id = $this->current_employee_id();
        if ( ! $emp_id ) {
            return new \WP_REST_Response( [], 200 );
        }
        return new \WP_REST_Response( Enrollment_Service::list_employee_enrollments( $emp_id ), 200 );
    }

    // ── Handlers: Training Requests ────────────────────────────────────────

    public function list_pending_requests(): \WP_REST_Response {
        return new \WP_REST_Response( Enrollment_Service::list_pending_requests(), 200 );
    }

    public function create_request( \WP_REST_Request $req ): \WP_REST_Response {
        $emp_id = $this->current_employee_id();
        if ( ! $emp_id ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'error' => __( 'No HR profile linked to your account.', 'sfs-hr' ) ],
                403
            );
        }

        $data = [];

        foreach ( [ 'training_title', 'training_type', 'provider', 'estimated_cost',
                     'currency', 'preferred_date', 'justification', 'notes' ] as $key ) {
            $val = $req->get_param( $key );
            if ( $val !== null ) {
                $data[ $key ] = $key === 'estimated_cost'
                    ? (float) $val
                    : ( in_array( $key, [ 'justification', 'notes' ], true )
                        ? sanitize_textarea_field( (string) $val )
                        : sanitize_text_field( (string) $val ) );
            }
        }

        $result = Enrollment_Service::create_request( $emp_id, $data );
        return $this->result_to_response( $result );
    }

    public function my_requests(): \WP_REST_Response {
        $emp_id = $this->current_employee_id();
        if ( ! $emp_id ) {
            return new \WP_REST_Response( [], 200 );
        }
        return new \WP_REST_Response( Enrollment_Service::list_employee_requests( $emp_id ), 200 );
    }

    public function approve_request( \WP_REST_Request $req ): \WP_REST_Response {
        $id         = (int) $req->get_param( 'id' );
        $note       = sanitize_textarea_field( (string) ( $req->get_param( 'note' ) ?? '' ) );
        $approver   = get_current_user_id();

        $result = Enrollment_Service::approve_request( $id, $approver, $note );
        return $this->result_to_response( $result );
    }

    public function reject_request( \WP_REST_Request $req ): \WP_REST_Response {
        $id         = (int) $req->get_param( 'id' );
        $note       = sanitize_textarea_field( (string) ( $req->get_param( 'note' ) ?? '' ) );
        $rejector   = get_current_user_id();

        $result = Enrollment_Service::reject_request( $id, $rejector, $note );
        return $this->result_to_response( $result );
    }

    // ── Handlers: Certifications ───────────────────────────────────────────

    public function add_certification( \WP_REST_Request $req ): \WP_REST_Response {
        $employee_id = (int) $req->get_param( 'employee_id' );
        $data        = [];

        foreach ( [ 'cert_name', 'issuing_body', 'credential_id',
                     'issued_date', 'expiry_date', 'cert_media_id', 'notes' ] as $key ) {
            $val = $req->get_param( $key );
            if ( $val !== null ) {
                $data[ $key ] = in_array( $key, [ 'cert_media_id' ], true )
                    ? (int) $val
                    : ( $key === 'notes'
                        ? sanitize_textarea_field( (string) $val )
                        : sanitize_text_field( (string) $val ) );
            }
        }

        $result = Certification_Service::add( $employee_id, $data );
        return $this->result_to_response( $result );
    }

    public function update_certification( \WP_REST_Request $req ): \WP_REST_Response {
        $id   = (int) $req->get_param( 'id' );
        $data = [];

        foreach ( [ 'cert_name', 'issuing_body', 'credential_id',
                     'issued_date', 'expiry_date', 'cert_media_id', 'notes' ] as $key ) {
            $val = $req->get_param( $key );
            if ( $val !== null ) {
                $data[ $key ] = in_array( $key, [ 'cert_media_id' ], true )
                    ? (int) $val
                    : ( $key === 'notes'
                        ? sanitize_textarea_field( (string) $val )
                        : sanitize_text_field( (string) $val ) );
            }
        }

        $result = Certification_Service::update( $id, $data );
        return $this->result_to_response( $result );
    }

    public function get_certification( \WP_REST_Request $req ): \WP_REST_Response {
        $id   = (int) $req->get_param( 'id' );
        $cert = Certification_Service::get( $id );

        if ( ! $cert ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => __( 'Certification not found.', 'sfs-hr' ) ], 404 );
        }

        return new \WP_REST_Response( $cert, 200 );
    }

    public function list_employee_certifications( \WP_REST_Request $req ): \WP_REST_Response {
        $employee_id = (int) $req->get_param( 'id' );

        // Non-admin users can only view their own certifications.
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            $own_id = $this->current_employee_id();
            if ( ! $own_id || $own_id !== $employee_id ) {
                return new \WP_REST_Response(
                    [ 'success' => false, 'error' => __( 'You can only view your own certifications.', 'sfs-hr' ) ],
                    403
                );
            }
        }

        return new \WP_REST_Response( Certification_Service::list_for_employee( $employee_id ), 200 );
    }

    public function get_expiring_certifications( \WP_REST_Request $req ): \WP_REST_Response {
        $days = (int) ( $req->get_param( 'days' ) ?? 30 );
        if ( $days < 1 ) {
            $days = 30;
        }
        return new \WP_REST_Response( Certification_Service::get_expiring( $days ), 200 );
    }

    public function compliance_report(): \WP_REST_Response {
        return new \WP_REST_Response( Certification_Service::compliance_report(), 200 );
    }

    // ── Handlers: Role Requirements ────────────────────────────────────────

    public function list_required_for_role( \WP_REST_Request $req ): \WP_REST_Response {
        $role = sanitize_text_field( (string) $req->get_param( 'role' ) );
        return new \WP_REST_Response( Certification_Service::list_required_for_role( $role ), 200 );
    }

    public function set_required( \WP_REST_Request $req ): \WP_REST_Response {
        $role      = sanitize_text_field( (string) $req->get_param( 'role' ) );
        $cert_name = sanitize_text_field( (string) $req->get_param( 'cert_name' ) );
        $mandatory = (bool) $req->get_param( 'mandatory' );

        $result = Certification_Service::set_required( $role, $cert_name, $mandatory );
        return $this->result_to_response( $result );
    }

    public function remove_required( \WP_REST_Request $req ): \WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $ok = Certification_Service::remove_required( $id );
        return new \WP_REST_Response( [ 'success' => $ok ], $ok ? 200 : 400 );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function current_employee_id(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d LIMIT 1",
            get_current_user_id()
        ) );
    }

    private function result_to_response( array $result ): \WP_REST_Response {
        if ( ! $result['success'] ) {
            return new \WP_REST_Response( $result, 400 );
        }
        return new \WP_REST_Response( $result, 200 );
    }
}
