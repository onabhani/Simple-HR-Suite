<?php
namespace SFS\HR\Modules\Training\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Training_Service
 *
 * M11.1 — Training programs and sessions CRUD.
 *
 * Programs are reusable catalogue entries (e.g. "Fire Safety", "Leadership 101").
 * Sessions are concrete instances of a program with dates, location, and capacity.
 *
 * Session statuses: scheduled | in_progress | completed | cancelled
 *
 * @since M11
 */
class Training_Service {

    // ── Programs ────────────────────────────────────────────────────────────

    /**
     * List training programs, optionally filtered to active only.
     *
     * @param bool $active_only If true, return only is_active = 1 rows.
     * @return array
     */
    public static function list_programs( bool $active_only = true ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_programs';

        if ( $active_only ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE is_active = %d ORDER BY sort_order ASC, title ASC",
                    1
                ),
                ARRAY_A
            ) ?: [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE 1 = %d ORDER BY is_active DESC, sort_order ASC, title ASC",
                1
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get a single program by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function get_program( int $id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_programs';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Create or update a training program by its unique code.
     *
     * @param array $data {
     *     @type string $code       Required. Unique program code.
     *     @type string $title      Required. Program title.
     *     @type string $title_ar   Optional. Arabic title.
     *     @type string $description Optional.
     *     @type string $category   Optional. e.g. 'technical', 'leadership', 'compliance'.
     *     @type int    $duration_hours Optional. Default 0.
     *     @type int    $is_active  Optional. Default 1.
     *     @type int    $sort_order Optional. Default 0.
     * }
     * @return array { success: bool, id?: int, error?: string }
     */
    public static function upsert_program( array $data ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_programs';

        $code  = sanitize_key( (string) ( $data['code'] ?? '' ) );
        $title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );

        if ( '' === $code || '' === $title ) {
            return [ 'success' => false, 'error' => __( 'Program code and title are required.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );

        $fields = [
            'code'           => $code,
            'title'          => $title,
            'title_ar'       => sanitize_text_field( (string) ( $data['title_ar'] ?? '' ) ),
            'description'    => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
            'category'       => sanitize_key( (string) ( $data['category'] ?? '' ) ),
            'duration_hours' => (int) ( $data['duration_hours'] ?? 0 ),
            'is_active'      => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
            'sort_order'     => (int) ( $data['sort_order'] ?? 0 ),
            'updated_at'     => $now,
        ];
        $formats = [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ];

        // Check for existing row by code.
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s LIMIT 1", $code )
        );

        if ( $existing_id > 0 ) {
            $ok = $wpdb->update(
                $table,
                $fields,
                [ 'id' => $existing_id ],
                $formats,
                [ '%d' ]
            );

            if ( false === $ok ) {
                return [ 'success' => false, 'error' => __( 'Failed to update program.', 'sfs-hr' ) ];
            }

            return [ 'success' => true, 'id' => $existing_id ];
        }

        // Insert new program.
        $fields['created_at'] = $now;
        $formats[]            = '%s';

        $ok = $wpdb->insert( $table, $fields, $formats );

        if ( ! $ok ) {
            return [ 'success' => false, 'error' => __( 'Failed to create program.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Toggle a program's active status.
     *
     * @param int  $id
     * @param bool $active
     * @return bool
     */
    public static function set_program_active( int $id, bool $active ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_programs';

        $ok = $wpdb->update(
            $table,
            [
                'is_active'  => $active ? 1 : 0,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        return false !== $ok;
    }

    // ── Sessions ────────────────────────────────────────────────────────────

    /**
     * List sessions with program title, optionally filtered by program and/or status.
     *
     * @param int    $program_id 0 = all programs.
     * @param string $status     '' = all statuses.
     * @return array
     */
    public static function list_sessions( int $program_id = 0, string $status = '' ): array {
        global $wpdb;
        $sessions = $wpdb->prefix . 'sfs_hr_training_sessions';
        $programs = $wpdb->prefix . 'sfs_hr_training_programs';

        $where = [ '1 = %d' ];
        $args  = [ 1 ];

        if ( $program_id > 0 ) {
            $where[] = 's.program_id = %d';
            $args[]  = $program_id;
        }
        if ( '' !== $status ) {
            $where[] = 's.status = %s';
            $args[]  = sanitize_key( $status );
        }

        $where_sql = implode( ' AND ', $where );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.*, p.title AS program_title, p.code AS program_code, p.category AS program_category
                 FROM {$sessions} s
                 INNER JOIN {$programs} p ON p.id = s.program_id
                 WHERE {$where_sql}
                 ORDER BY s.start_date DESC",
                ...$args
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get a single session with its program info.
     *
     * @param int $id
     * @return array|null
     */
    public static function get_session( int $id ): ?array {
        global $wpdb;
        $sessions = $wpdb->prefix . 'sfs_hr_training_sessions';
        $programs = $wpdb->prefix . 'sfs_hr_training_programs';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT s.*, p.title AS program_title, p.code AS program_code, p.category AS program_category
                 FROM {$sessions} s
                 INNER JOIN {$programs} p ON p.id = s.program_id
                 WHERE s.id = %d
                 LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Create a training session.
     *
     * @param array $data {
     *     @type int    $program_id  Required.
     *     @type string $title       Optional override for session name.
     *     @type string $start_date  Required. YYYY-MM-DD.
     *     @type string $end_date    Required. YYYY-MM-DD.
     *     @type string $start_time  Optional. HH:MM.
     *     @type string $end_time    Optional. HH:MM.
     *     @type string $location    Optional.
     *     @type string $trainer     Optional. Trainer name.
     *     @type int    $capacity    Optional. 0 = unlimited.
     *     @type string $notes       Optional.
     * }
     * @return array { success: bool, id?: int, error?: string }
     */
    public static function create_session( array $data ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_sessions';

        $program_id = (int) ( $data['program_id'] ?? 0 );
        $start_date = sanitize_text_field( (string) ( $data['start_date'] ?? '' ) );
        $end_date   = sanitize_text_field( (string) ( $data['end_date'] ?? '' ) );

        if ( $program_id <= 0 ) {
            return [ 'success' => false, 'error' => __( 'Program ID is required.', 'sfs-hr' ) ];
        }

        // Verify program exists.
        $program = self::get_program( $program_id );
        if ( ! $program ) {
            return [ 'success' => false, 'error' => __( 'Program not found.', 'sfs-hr' ) ];
        }

        // Validate dates.
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
            return [ 'success' => false, 'error' => __( 'Valid start_date and end_date (YYYY-MM-DD) are required.', 'sfs-hr' ) ];
        }
        if ( $end_date < $start_date ) {
            return [ 'success' => false, 'error' => __( 'End date cannot be before start date.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );

        $ok = $wpdb->insert( $table, [
            'program_id' => $program_id,
            'title'      => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'start_time' => sanitize_text_field( (string) ( $data['start_time'] ?? '' ) ),
            'end_time'   => sanitize_text_field( (string) ( $data['end_time'] ?? '' ) ),
            'location'   => sanitize_text_field( (string) ( $data['location'] ?? '' ) ),
            'trainer'    => sanitize_text_field( (string) ( $data['trainer'] ?? '' ) ),
            'capacity'   => (int) ( $data['capacity'] ?? 0 ),
            'status'     => 'scheduled',
            'notes'      => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
            'created_at' => $now,
            'updated_at' => $now,
        ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ] );

        if ( ! $ok ) {
            return [ 'success' => false, 'error' => __( 'Failed to create session.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Update a training session.
     *
     * @param int   $id
     * @param array $data Updatable fields (same keys as create_session minus program_id).
     * @return array { success: bool, error?: string }
     */
    public static function update_session( int $id, array $data ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_sessions';

        $existing = self::get_session( $id );
        if ( ! $existing ) {
            return [ 'success' => false, 'error' => __( 'Session not found.', 'sfs-hr' ) ];
        }

        $fields  = [];
        $formats = [];

        if ( isset( $data['title'] ) ) {
            $fields['title']  = sanitize_text_field( (string) $data['title'] );
            $formats[]        = '%s';
        }
        if ( isset( $data['start_date'] ) ) {
            $sd = sanitize_text_field( (string) $data['start_date'] );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $sd ) ) {
                return [ 'success' => false, 'error' => __( 'Invalid start_date format.', 'sfs-hr' ) ];
            }
            $fields['start_date'] = $sd;
            $formats[]            = '%s';
        }
        if ( isset( $data['end_date'] ) ) {
            $ed = sanitize_text_field( (string) $data['end_date'] );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ed ) ) {
                return [ 'success' => false, 'error' => __( 'Invalid end_date format.', 'sfs-hr' ) ];
            }
            $fields['end_date'] = $ed;
            $formats[]          = '%s';
        }
        if ( isset( $data['start_time'] ) ) {
            $fields['start_time'] = sanitize_text_field( (string) $data['start_time'] );
            $formats[]            = '%s';
        }
        if ( isset( $data['end_time'] ) ) {
            $fields['end_time'] = sanitize_text_field( (string) $data['end_time'] );
            $formats[]          = '%s';
        }
        if ( isset( $data['location'] ) ) {
            $fields['location'] = sanitize_text_field( (string) $data['location'] );
            $formats[]          = '%s';
        }
        if ( isset( $data['trainer'] ) ) {
            $fields['trainer'] = sanitize_text_field( (string) $data['trainer'] );
            $formats[]         = '%s';
        }
        if ( isset( $data['capacity'] ) ) {
            $fields['capacity'] = (int) $data['capacity'];
            $formats[]          = '%d';
        }
        if ( isset( $data['status'] ) ) {
            $allowed = [ 'scheduled', 'in_progress', 'completed', 'cancelled' ];
            $s = sanitize_key( (string) $data['status'] );
            if ( ! in_array( $s, $allowed, true ) ) {
                return [ 'success' => false, 'error' => __( 'Invalid session status.', 'sfs-hr' ) ];
            }
            $fields['status'] = $s;
            $formats[]        = '%s';
        }
        if ( isset( $data['notes'] ) ) {
            $fields['notes'] = sanitize_textarea_field( (string) $data['notes'] );
            $formats[]       = '%s';
        }

        if ( empty( $fields ) ) {
            return [ 'success' => false, 'error' => __( 'No fields to update.', 'sfs-hr' ) ];
        }

        // Cross-validate dates if both are now known.
        $final_start = $fields['start_date'] ?? $existing['start_date'];
        $final_end   = $fields['end_date'] ?? $existing['end_date'];
        if ( $final_end < $final_start ) {
            return [ 'success' => false, 'error' => __( 'End date cannot be before start date.', 'sfs-hr' ) ];
        }

        $fields['updated_at'] = current_time( 'mysql' );
        $formats[]            = '%s';

        $ok = $wpdb->update( $table, $fields, [ 'id' => $id ], $formats, [ '%d' ] );

        if ( false === $ok ) {
            return [ 'success' => false, 'error' => __( 'Failed to update session.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    /**
     * Cancel a session. Only allowed when status is 'scheduled'.
     *
     * @param int $id
     * @return bool
     */
    public static function cancel_session( int $id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_sessions';

        $current_status = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d LIMIT 1", $id )
        );

        if ( 'scheduled' !== $current_status ) {
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
     * Mark a session as completed and auto-complete all 'enrolled' or 'attended'
     * enrollments tied to it.
     *
     * @param int $id
     * @return bool
     */
    public static function complete_session( int $id ): bool {
        global $wpdb;
        $sessions    = $wpdb->prefix . 'sfs_hr_training_sessions';
        $enrollments = $wpdb->prefix . 'sfs_hr_training_enrollments';

        $current_status = $wpdb->get_var(
            $wpdb->prepare( "SELECT status FROM {$sessions} WHERE id = %d LIMIT 1", $id )
        );

        if ( ! $current_status || 'cancelled' === $current_status || 'completed' === $current_status ) {
            return false;
        }

        $now = current_time( 'mysql' );

        // Mark session completed.
        $ok = $wpdb->update(
            $sessions,
            [
                'status'     => 'completed',
                'updated_at' => $now,
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $ok ) {
            return false;
        }

        // Auto-complete all enrolled/attended enrollments for this session.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$enrollments}
                 SET status = 'completed', completed_at = %s, updated_at = %s
                 WHERE session_id = %d AND status IN ('enrolled', 'attended')",
                $now, $now, $id
            )
        );

        return true;
    }
}
