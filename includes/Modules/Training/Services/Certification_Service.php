<?php
namespace SFS\HR\Modules\Training\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Certification_Service
 *
 * M11.3 — Employee certification tracking, expiry monitoring, and
 * role-based compliance reporting.
 *
 * Certification statuses: active | expired | revoked
 *
 * @since M11
 */
class Certification_Service {

    // ── Employee certifications ─────────────────────────────────────────────

    /**
     * Add a certification record for an employee.
     *
     * @param int   $employee_id
     * @param array $data {
     *     @type string $cert_name       Required.
     *     @type string $issuing_body    Optional.
     *     @type string $credential_id   Optional. External credential/license number.
     *     @type string $issued_date     Optional. YYYY-MM-DD.
     *     @type string $expiry_date     Optional. YYYY-MM-DD.
     *     @type int    $cert_media_id   Optional. WP attachment ID.
     *     @type string $notes           Optional.
     * }
     * @return array { success: bool, id?: int, error?: string }
     */
    public static function add( int $employee_id, array $data ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_certifications';

        $cert_name = sanitize_text_field( (string) ( $data['cert_name'] ?? '' ) );

        if ( $employee_id <= 0 ) {
            return [ 'success' => false, 'error' => __( 'Employee ID is required.', 'sfs-hr' ) ];
        }
        if ( '' === $cert_name ) {
            return [ 'success' => false, 'error' => __( 'Certification name is required.', 'sfs-hr' ) ];
        }

        // Validate dates if provided.
        $issued_date = sanitize_text_field( (string) ( $data['issued_date'] ?? '' ) );
        $expiry_date = sanitize_text_field( (string) ( $data['expiry_date'] ?? '' ) );

        if ( '' !== $issued_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issued_date ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid issued_date format (YYYY-MM-DD).', 'sfs-hr' ) ];
        }
        if ( '' !== $expiry_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiry_date ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid expiry_date format (YYYY-MM-DD).', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );

        $ok = $wpdb->insert( $table, [
            'employee_id'   => $employee_id,
            'cert_name'     => $cert_name,
            'issuing_body'  => sanitize_text_field( (string) ( $data['issuing_body'] ?? '' ) ),
            'credential_id' => sanitize_text_field( (string) ( $data['credential_id'] ?? '' ) ),
            'issued_date'   => $issued_date ?: null,
            'expiry_date'   => $expiry_date ?: null,
            'cert_media_id' => isset( $data['cert_media_id'] ) && (int) $data['cert_media_id'] > 0
                                   ? (int) $data['cert_media_id'] : null,
            'status'        => 'active',
            'notes'         => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
            'created_at'    => $now,
            'updated_at'    => $now,
        ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ] );

        if ( ! $ok ) {
            return [ 'success' => false, 'error' => __( 'Failed to add certification.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Update an existing certification record.
     *
     * @param int   $id
     * @param array $data Updatable fields (same keys as add, minus employee_id).
     * @return array { success: bool, error?: string }
     */
    public static function update( int $id, array $data ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_certifications';

        $existing = self::get( $id );
        if ( ! $existing ) {
            return [ 'success' => false, 'error' => __( 'Certification not found.', 'sfs-hr' ) ];
        }

        $fields  = [];
        $formats = [];

        if ( isset( $data['cert_name'] ) ) {
            $name = sanitize_text_field( (string) $data['cert_name'] );
            if ( '' === $name ) {
                return [ 'success' => false, 'error' => __( 'Certification name cannot be empty.', 'sfs-hr' ) ];
            }
            $fields['cert_name'] = $name;
            $formats[]           = '%s';
        }
        if ( isset( $data['issuing_body'] ) ) {
            $fields['issuing_body'] = sanitize_text_field( (string) $data['issuing_body'] );
            $formats[]              = '%s';
        }
        if ( isset( $data['credential_id'] ) ) {
            $fields['credential_id'] = sanitize_text_field( (string) $data['credential_id'] );
            $formats[]               = '%s';
        }
        if ( isset( $data['issued_date'] ) ) {
            $d = sanitize_text_field( (string) $data['issued_date'] );
            if ( '' !== $d && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
                return [ 'success' => false, 'error' => __( 'Invalid issued_date format.', 'sfs-hr' ) ];
            }
            $fields['issued_date'] = $d ?: null;
            $formats[]             = '%s';
        }
        if ( isset( $data['expiry_date'] ) ) {
            $d = sanitize_text_field( (string) $data['expiry_date'] );
            if ( '' !== $d && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
                return [ 'success' => false, 'error' => __( 'Invalid expiry_date format.', 'sfs-hr' ) ];
            }
            $fields['expiry_date'] = $d ?: null;
            $formats[]             = '%s';
        }
        if ( isset( $data['cert_media_id'] ) ) {
            $fields['cert_media_id'] = (int) $data['cert_media_id'] > 0 ? (int) $data['cert_media_id'] : null;
            $formats[]               = '%d';
        }
        if ( isset( $data['status'] ) ) {
            $s = sanitize_key( (string) $data['status'] );
            if ( ! in_array( $s, [ 'active', 'expired', 'revoked' ], true ) ) {
                return [ 'success' => false, 'error' => __( 'Invalid certification status.', 'sfs-hr' ) ];
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

        $fields['updated_at'] = current_time( 'mysql' );
        $formats[]            = '%s';

        $ok = $wpdb->update( $table, $fields, [ 'id' => $id ], $formats, [ '%d' ] );

        if ( false === $ok ) {
            return [ 'success' => false, 'error' => __( 'Failed to update certification.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    /**
     * Get a single certification by ID.
     *
     * @param int $id
     * @return array|null
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_certifications';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * List all certifications for an employee.
     *
     * @param int $employee_id
     * @return array
     */
    public static function list_for_employee( int $employee_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_certifications';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE employee_id = %d ORDER BY expiry_date ASC, cert_name ASC",
                $employee_id
            ),
            ARRAY_A
        ) ?: [];
    }

    // ── Expiry monitoring ───────────────────────────────────────────────────

    /**
     * Get certifications expiring within N days, with employee info.
     *
     * @param int $days
     * @return array
     */
    public static function get_expiring( int $days = 30 ): array {
        global $wpdb;
        $certs     = $wpdb->prefix . 'sfs_hr_training_certifications';
        $employees = $wpdb->prefix . 'sfs_hr_employees';

        $today   = wp_date( 'Y-m-d' );
        $horizon = wp_date( 'Y-m-d', strtotime( "+{$days} days" ) );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.*, e.employee_code, e.first_name, e.last_name, e.dept_id
                 FROM {$certs} c
                 INNER JOIN {$employees} e ON e.id = c.employee_id
                 WHERE c.status = %s
                   AND c.expiry_date IS NOT NULL
                   AND c.expiry_date BETWEEN %s AND %s
                 ORDER BY c.expiry_date ASC",
                'active', $today, $horizon
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Find certifications that are past expiry but still status='active',
     * flip them to 'expired', and return the affected rows.
     *
     * Designed to be called by a daily cron job.
     *
     * @return array The rows that were just marked expired.
     */
    public static function get_expired(): array {
        global $wpdb;
        $certs     = $wpdb->prefix . 'sfs_hr_training_certifications';
        $employees = $wpdb->prefix . 'sfs_hr_employees';

        $today = wp_date( 'Y-m-d' );
        $now   = current_time( 'mysql' );

        // Find active certs that are past expiry.
        $expired_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.*, e.employee_code, e.first_name, e.last_name, e.dept_id
                 FROM {$certs} c
                 INNER JOIN {$employees} e ON e.id = c.employee_id
                 WHERE c.status = %s
                   AND c.expiry_date IS NOT NULL
                   AND c.expiry_date < %s
                 ORDER BY c.expiry_date ASC",
                'active', $today
            ),
            ARRAY_A
        ) ?: [];

        if ( ! empty( $expired_rows ) ) {
            // Batch-update all affected rows to 'expired'.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$certs}
                     SET status = 'expired', updated_at = %s
                     WHERE status = %s
                       AND expiry_date IS NOT NULL
                       AND expiry_date < %s",
                    $now, 'active', $today
                )
            );
        }

        return $expired_rows;
    }

    // ── Role-based requirements ─────────────────────────────────────────────

    /**
     * List required certifications for a given role/position.
     *
     * @param string $role
     * @return array
     */
    public static function list_required_for_role( string $role ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_cert_requirements';

        $role = sanitize_key( $role );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE role = %s ORDER BY cert_name ASC",
                $role
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Upsert a certification requirement for a role.
     *
     * @param string $role
     * @param string $cert_name
     * @param bool   $mandatory
     * @return array { success: bool, id?: int, error?: string }
     */
    public static function set_required( string $role, string $cert_name, bool $mandatory = true ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_cert_requirements';

        $role      = sanitize_key( $role );
        $cert_name = sanitize_text_field( $cert_name );

        if ( '' === $role || '' === $cert_name ) {
            return [ 'success' => false, 'error' => __( 'Role and certification name are required.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );

        // Check for existing requirement.
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE role = %s AND cert_name = %s LIMIT 1",
                $role, $cert_name
            )
        );

        if ( $existing_id > 0 ) {
            $ok = $wpdb->update(
                $table,
                [
                    'mandatory'  => $mandatory ? 1 : 0,
                    'updated_at' => $now,
                ],
                [ 'id' => $existing_id ],
                [ '%d', '%s' ],
                [ '%d' ]
            );

            if ( false === $ok ) {
                return [ 'success' => false, 'error' => __( 'Failed to update requirement.', 'sfs-hr' ) ];
            }

            return [ 'success' => true, 'id' => $existing_id ];
        }

        $ok = $wpdb->insert( $table, [
            'role'       => $role,
            'cert_name'  => $cert_name,
            'mandatory'  => $mandatory ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], [ '%s', '%s', '%d', '%s', '%s' ] );

        if ( ! $ok ) {
            return [ 'success' => false, 'error' => __( 'Failed to create requirement.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Remove a certification requirement by ID.
     *
     * @param int $id
     * @return bool
     */
    public static function remove_required( int $id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_training_cert_requirements';

        $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

        return false !== $deleted && $deleted > 0;
    }

    /**
     * Compliance report: for each employee, list required certs (based on their
     * WP role), and whether they hold a valid, expired, or missing certification.
     *
     * Returns an array of employee records, each with a `certifications` sub-array
     * keyed by cert_name containing { status: 'valid'|'expired'|'missing', cert_row? }.
     *
     * @return array
     */
    public static function compliance_report(): array {
        global $wpdb;
        $employees    = $wpdb->prefix . 'sfs_hr_employees';
        $certs        = $wpdb->prefix . 'sfs_hr_training_certifications';
        $requirements = $wpdb->prefix . 'sfs_hr_training_cert_requirements';

        // Get all requirements grouped by role.
        $all_reqs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$requirements} WHERE 1 = %d ORDER BY role, cert_name",
                1
            ),
            ARRAY_A
        ) ?: [];

        if ( empty( $all_reqs ) ) {
            return [];
        }

        // Group requirements by role.
        $reqs_by_role = [];
        foreach ( $all_reqs as $req ) {
            $reqs_by_role[ $req['role'] ][] = $req;
        }

        // Get all active employees with their user_id to look up WP role.
        $emp_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, employee_code, first_name, last_name, dept_id, user_id, position
                 FROM {$employees}
                 WHERE status = %s
                 ORDER BY first_name, last_name",
                'active'
            ),
            ARRAY_A
        ) ?: [];

        if ( empty( $emp_rows ) ) {
            return [];
        }

        // Pre-load all active/expired certs for all employees in a single query.
        $emp_ids = array_column( $emp_rows, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $emp_ids ), '%d' ) );

        $all_certs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$certs}
                 WHERE employee_id IN ({$placeholders})
                   AND status IN ('active', 'expired')
                 ORDER BY employee_id, cert_name",
                ...$emp_ids
            ),
            ARRAY_A
        ) ?: [];

        // Index certs by employee_id → cert_name.
        $certs_index = [];
        foreach ( $all_certs as $cert ) {
            $key = (int) $cert['employee_id'];
            $certs_index[ $key ][ $cert['cert_name'] ][] = $cert;
        }

        $report = [];

        foreach ( $emp_rows as $emp ) {
            $user_id = (int) $emp['user_id'];

            // Determine which roles apply to this employee.
            $user = $user_id > 0 ? get_userdata( $user_id ) : null;
            $user_roles = $user ? (array) $user->roles : [];

            // Collect applicable requirements.
            $applicable_reqs = [];
            foreach ( $user_roles as $role ) {
                if ( isset( $reqs_by_role[ $role ] ) ) {
                    foreach ( $reqs_by_role[ $role ] as $req ) {
                        // Deduplicate by cert_name (keep the mandatory one if both exist).
                        $cn = $req['cert_name'];
                        if ( ! isset( $applicable_reqs[ $cn ] ) || (int) $req['mandatory'] > (int) $applicable_reqs[ $cn ]['mandatory'] ) {
                            $applicable_reqs[ $cn ] = $req;
                        }
                    }
                }
            }

            if ( empty( $applicable_reqs ) ) {
                continue; // No requirements for this employee's roles.
            }

            $emp_id     = (int) $emp['id'];
            $emp_certs  = $certs_index[ $emp_id ] ?? [];
            $cert_items = [];

            foreach ( $applicable_reqs as $cn => $req ) {
                $held = $emp_certs[ $cn ] ?? [];

                if ( empty( $held ) ) {
                    $cert_items[ $cn ] = [
                        'status'    => 'missing',
                        'mandatory' => (bool) $req['mandatory'],
                        'cert_row'  => null,
                    ];
                    continue;
                }

                // Check if any held cert is active (not expired).
                $has_active = false;
                $latest     = null;
                foreach ( $held as $h ) {
                    if ( 'active' === $h['status'] ) {
                        $has_active = true;
                        $latest     = $h;
                        break;
                    }
                    $latest = $h; // Keep the last one as fallback.
                }

                $cert_items[ $cn ] = [
                    'status'    => $has_active ? 'valid' : 'expired',
                    'mandatory' => (bool) $req['mandatory'],
                    'cert_row'  => $latest,
                ];
            }

            $report[] = [
                'employee_id'    => $emp_id,
                'employee_code'  => $emp['employee_code'],
                'first_name'     => $emp['first_name'],
                'last_name'      => $emp['last_name'],
                'dept_id'        => (int) $emp['dept_id'],
                'position'       => $emp['position'],
                'certifications' => $cert_items,
            ];
        }

        return $report;
    }
}
