<?php
namespace SFS\HR\Modules\Training\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Helpers;

/**
 * Enrollment_Service
 *
 * M11.2 — Training enrollments and training requests.
 *
 * Enrollment statuses: enrolled | attended | completed | cancelled
 * Request statuses:    pending | approved | rejected
 *
 * @since M11
 */
class Enrollment_Service {

    // ── Enrollments ─────────────────────────────────────────────────────────

    /**
     * Enroll an employee in a training session.
     *
     * Validates:
     *   - Session exists and is not cancelled/completed.
     *   - Capacity not exceeded (0 = unlimited).
     *   - No duplicate enrollment for the same employee + session.
     *
     * @param int $session_id
     * @param int $employee_id
     * @param int $enrolled_by  The user/employee who initiated the enrollment.
     * @return array { success: bool, id?: int, error?: string }
     */
    public static function enroll( int $session_id, int $employee_id, int $enrolled_by ): array {
        global $wpdb;
        $enrollments = $wpdb->prefix . 'sfs_hr_training_enrollments';
        $sessions    = $wpdb->prefix . 'sfs_hr_training_sessions';

        if ( $session_id <= 0 || $employee_id <= 0 ) {
            return [ 'success' => false, 'error' => __( 'Session ID and employee ID are required.', 'sfs-hr' ) ];
        }

        // Verify session exists and is enrollable.
        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status, capacity FROM {$sessions} WHERE id = %d LIMIT 1",
                $session_id
            ),
            ARRAY_A
        );

        if ( ! $session ) {
            return [ 'success' => false, 'error' => __( 'Session not found.', 'sfs-hr' ) ];
        }
        if ( in_array( $session['status'], [ 'cancelled', 'completed' ], true ) ) {
            return [ 'success' => false, 'error' => __( 'Cannot enroll in a cancelled or completed session.', 'sfs-hr' ) ];
        }

        // Check capacity.
        $capacity = (int) $session['capacity'];
        if ( $capacity > 0 ) {
            $current_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$enrollments} WHERE session_id = %d AND status NOT IN ('cancelled')",
                    $session_id
                )
            );
            if ( $current_count >= $capacity ) {
                return [ 'success' => false, 'error' => __( 'Session is at full capacity.', 'sfs-hr' ) ];
            }
        }

        // Check duplicate.
        $duplicate = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$enrollments} WHERE session_id = %d AND employee_id = %d AND status != 'cancelled'",
                $session_id, $employee_id
            )
        );
        if ( $duplicate > 0 ) {
            return [ 'success' => false, 'error' => __( 'Employee is already enrolled in this session.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );

        $ok = $wpdb->insert( $enrollments, [
            'session_id'  => $session_id,
            'employee_id' => $employee_id,
            'enrolled_by' => $enrolled_by,
            'status'      => 'enrolled',
            'enrolled_at' => $now,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%s' ] );

        if ( ! $ok ) {
            return [ 'success' => false, 'error' => __( 'Failed to create enrollment.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Cancel an enrollment. Only allowed when status is 'enrolled'.
     *
     * @param int $id Enrollment ID.
     * @return bool
     */
    public static function cancel_enrollment( int $id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_enrollments';

        $current = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d LIMIT 1", $id )
        );

        if ( 'enrolled' !== $current ) {
            return false;
        }

        $ok = $wpdb->update(
            $table,
            [
                'status'     => 'cancelled',
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return false !== $ok;
    }

    /**
     * Mark an enrollment as attended (employee showed up).
     *
     * @param int $id Enrollment ID.
     * @return bool
     */
    public static function mark_attended( int $id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_enrollments';

        $current = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d LIMIT 1", $id )
        );

        if ( 'enrolled' !== $current ) {
            return false;
        }

        $ok = $wpdb->update(
            $table,
            [
                'status'      => 'attended',
                'attended_at' => current_time( 'mysql' ),
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return false !== $ok;
    }

    /**
     * Complete an enrollment with optional score and certificate.
     *
     * @param int        $id
     * @param float|null $score         Optional assessment score.
     * @param int|null   $cert_media_id Optional WP attachment ID for certificate.
     * @return bool
     */
    public static function complete_enrollment( int $id, ?float $score = null, ?int $cert_media_id = null ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_enrollments';

        $current = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d LIMIT 1", $id )
        );

        if ( ! in_array( $current, [ 'enrolled', 'attended' ], true ) ) {
            return false;
        }

        $now = current_time( 'mysql' );

        $fields  = [
            'status'       => 'completed',
            'completed_at' => $now,
            'updated_at'   => $now,
        ];
        $formats = [ '%s', '%s', '%s' ];

        if ( null !== $score ) {
            $fields['score'] = $score;
            $formats[]       = '%f';
        }
        if ( null !== $cert_media_id && $cert_media_id > 0 ) {
            $fields['cert_media_id'] = $cert_media_id;
            $formats[]               = '%d';
        }

        $ok = $wpdb->update( $table, $fields, [ 'id' => $id ], $formats, [ '%d' ] );

        return false !== $ok;
    }

    /**
     * List enrollments for a session, with employee name and code.
     *
     * @param int $session_id
     * @return array
     */
    public static function list_session_enrollments( int $session_id ): array {
        global $wpdb;
        $enrollments = $wpdb->prefix . 'sfs_hr_training_enrollments';
        $employees   = $wpdb->prefix . 'sfs_hr_employees';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT en.*, e.employee_code, e.first_name, e.last_name
                 FROM {$enrollments} en
                 INNER JOIN {$employees} e ON e.id = en.employee_id
                 WHERE en.session_id = %d
                 ORDER BY en.enrolled_at ASC",
                $session_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * List enrollments for an employee, with program and session info.
     *
     * @param int $employee_id
     * @return array
     */
    public static function list_employee_enrollments( int $employee_id ): array {
        global $wpdb;
        $enrollments = $wpdb->prefix . 'sfs_hr_training_enrollments';
        $sessions    = $wpdb->prefix . 'sfs_hr_training_sessions';
        $programs    = $wpdb->prefix . 'sfs_hr_training_programs';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT en.*, s.title AS session_title, s.start_date, s.end_date, s.status AS session_status,
                        s.location, s.trainer,
                        p.id AS program_id, p.title AS program_title, p.code AS program_code, p.category AS program_category
                 FROM {$enrollments} en
                 INNER JOIN {$sessions} s ON s.id = en.session_id
                 INNER JOIN {$programs} p ON p.id = s.program_id
                 WHERE en.employee_id = %d
                 ORDER BY s.start_date DESC",
                $employee_id
            ),
            ARRAY_A
        ) ?: [];
    }

    // ── Training requests ───────────────────────────────────────────────────

    /**
     * Create a training request from an employee.
     *
     * @param int   $employee_id
     * @param array $data {
     *     @type string $training_title   Required.
     *     @type string $training_type    Optional. e.g. 'internal', 'external', 'online'.
     *     @type string $provider         Optional.
     *     @type float  $estimated_cost   Optional.
     *     @type string $currency         Optional.
     *     @type string $preferred_date   Optional. YYYY-MM-DD.
     *     @type string $justification    Optional.
     *     @type string $notes            Optional.
     * }
     * @return array { success: bool, id?: int, request_number?: string, error?: string }
     */
    public static function create_request( int $employee_id, array $data ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_requests';

        $title = sanitize_text_field( (string) ( $data['training_title'] ?? '' ) );

        if ( $employee_id <= 0 ) {
            return [ 'success' => false, 'error' => __( 'Employee ID is required.', 'sfs-hr' ) ];
        }
        if ( '' === $title ) {
            return [ 'success' => false, 'error' => __( 'Training title is required.', 'sfs-hr' ) ];
        }

        // Validate preferred_date if provided.
        $preferred_date = sanitize_text_field( (string) ( $data['preferred_date'] ?? '' ) );
        if ( '' !== $preferred_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $preferred_date ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid preferred_date format (YYYY-MM-DD).', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );
        $ref = Helpers::generate_reference_number( 'TR', $table, 'request_number' );

        $ok = $wpdb->insert( $table, [
            'request_number'  => $ref,
            'employee_id'     => $employee_id,
            'training_title'  => $title,
            'training_type'   => sanitize_key( (string) ( $data['training_type'] ?? '' ) ),
            'provider'        => sanitize_text_field( (string) ( $data['provider'] ?? '' ) ),
            'estimated_cost'  => (float) ( $data['estimated_cost'] ?? 0 ),
            'currency'        => sanitize_text_field( (string) ( $data['currency'] ?? 'SAR' ) ),
            'preferred_date'  => $preferred_date ?: null,
            'justification'   => sanitize_textarea_field( (string) ( $data['justification'] ?? '' ) ),
            'notes'           => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
            'status'          => 'pending',
            'created_at'      => $now,
            'updated_at'      => $now,
        ], [ '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );

        if ( ! $ok ) {
            return [ 'success' => false, 'error' => __( 'Failed to create training request.', 'sfs-hr' ) ];
        }

        return [
            'success'        => true,
            'id'             => (int) $wpdb->insert_id,
            'request_number' => $ref,
        ];
    }

    /**
     * Approve a pending training request.
     *
     * @param int    $id
     * @param int    $approver_id
     * @param string $note
     * @return bool
     */
    public static function approve_request( int $id, int $approver_id, string $note = '' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_requests';

        $current = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d LIMIT 1", $id )
        );

        if ( 'pending' !== $current ) {
            return false;
        }

        $ok = $wpdb->update(
            $table,
            [
                'status'       => 'approved',
                'approved_by'  => $approver_id,
                'approved_at'  => current_time( 'mysql' ),
                'approver_note' => sanitize_textarea_field( $note ),
                'updated_at'   => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return false !== $ok;
    }

    /**
     * Reject a pending training request.
     *
     * @param int    $id
     * @param int    $approver_id
     * @param string $note
     * @return bool
     */
    public static function reject_request( int $id, int $approver_id, string $note = '' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_requests';

        $current = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d LIMIT 1", $id )
        );

        if ( 'pending' !== $current ) {
            return false;
        }

        $ok = $wpdb->update(
            $table,
            [
                'status'        => 'rejected',
                'approved_by'   => $approver_id,
                'approved_at'   => current_time( 'mysql' ),
                'approver_note' => sanitize_textarea_field( $note ),
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return false !== $ok;
    }

    /**
     * List pending training requests with employee info.
     *
     * @return array
     */
    public static function list_pending_requests(): array {
        global $wpdb;
        $requests  = $wpdb->prefix . 'sfs_hr_training_requests';
        $employees = $wpdb->prefix . 'sfs_hr_employees';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, e.employee_code, e.first_name, e.last_name, e.dept_id
                 FROM {$requests} r
                 INNER JOIN {$employees} e ON e.id = r.employee_id
                 WHERE r.status = %s
                 ORDER BY r.created_at ASC",
                'pending'
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * List training requests for a specific employee.
     *
     * @param int $employee_id
     * @return array
     */
    public static function list_employee_requests( int $employee_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_requests';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE employee_id = %d ORDER BY created_at DESC",
                $employee_id
            ),
            ARRAY_A
        ) ?: [];
    }
}
