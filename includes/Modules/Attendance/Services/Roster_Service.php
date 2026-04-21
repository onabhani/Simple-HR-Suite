<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Roster_Service
 *
 * M5.2 Roster & Shift Management.
 *
 * Handles shift template CRUD, roster generation from templates,
 * rest-hour enforcement, shift bidding workflow, and roster copy/clear utilities.
 *
 * Tables used:
 *   - sfs_hr_attendance_shift_templates  (managed here)
 *   - sfs_hr_attendance_shift_bids       (managed here)
 *   - sfs_hr_attendance_shift_assign     (read/write for roster generation)
 *   - sfs_hr_attendance_shifts           (read for shift metadata)
 *   - sfs_hr_employees                   (read for dept_id filtering)
 */
class Roster_Service {

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------

    /**
     * Create a shift template.
     *
     * @param array $data {
     *     @type string $name           Required. Template name (max 100 chars).
     *     @type string $pattern_type   'fixed'|'rotating'|'custom'. Default 'fixed'.
     *     @type array  $pattern_json   Required. Array of day entries.
     *     @type int    $cycle_days     Default 7.
     *     @type int    $min_rest_hours Default 8.
     *     @type string $description    Optional.
     *     @type int    $is_active      0|1. Default 1.
     * }
     * @return array { success: bool, id?: int, error?: string }
     */
    public static function create_template( array $data ): array {
        global $wpdb;

        $validated = self::validate_template_data( $data );
        if ( isset( $validated['error'] ) ) {
            return $validated;
        }

        $now = current_time( 'mysql' );
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_attendance_shift_templates',
            [
                'name'           => $validated['name'],
                'pattern_type'   => $validated['pattern_type'],
                'pattern_json'   => wp_json_encode( $validated['pattern_json'] ),
                'cycle_days'     => $validated['cycle_days'],
                'min_rest_hours' => $validated['min_rest_hours'],
                'description'    => $validated['description'],
                'is_active'      => $validated['is_active'],
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return [ 'success' => false, 'error' => __( 'Database error creating template.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Update a shift template.
     *
     * @param int   $id
     * @param array $data  Same shape as create_template $data (partial updates allowed).
     * @return array { success: bool, error?: string }
     */
    public static function update_template( int $id, array $data ): array {
        global $wpdb;

        $existing = self::get_template( $id );
        if ( ! $existing ) {
            return [ 'success' => false, 'error' => __( 'Template not found.', 'sfs-hr' ) ];
        }

        // Merge so partial updates work, then re-validate.
        $merged = array_merge( $existing, $data );
        // pattern_json is stored as JSON string in DB; decode for validation.
        if ( is_string( $merged['pattern_json'] ) ) {
            $merged['pattern_json'] = json_decode( $merged['pattern_json'], true );
        }

        $validated = self::validate_template_data( $merged );
        if ( isset( $validated['error'] ) ) {
            return $validated;
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'sfs_hr_attendance_shift_templates',
            [
                'name'           => $validated['name'],
                'pattern_type'   => $validated['pattern_type'],
                'pattern_json'   => wp_json_encode( $validated['pattern_json'] ),
                'cycle_days'     => $validated['cycle_days'],
                'min_rest_hours' => $validated['min_rest_hours'],
                'description'    => $validated['description'],
                'is_active'      => $validated['is_active'],
                'updated_at'     => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return [ 'success' => false, 'error' => __( 'Database error updating template.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    /**
     * Get a single template by ID.
     *
     * @param int $id
     * @return array|null  Decoded template row, or null if not found.
     */
    public static function get_template( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_attendance_shift_templates WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        return self::decode_template_row( $row );
    }

    /**
     * List templates.
     *
     * @param bool $active_only  If true, only returns is_active = 1 rows.
     * @return array  Array of decoded template rows.
     */
    public static function list_templates( bool $active_only = true ): array {
        global $wpdb;

        $where = $active_only ? 'WHERE is_active = 1' : '';
        $rows  = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_attendance_shift_templates {$where} ORDER BY name ASC",
            ARRAY_A
        );

        if ( ! $rows ) {
            return [];
        }

        return array_map( [ self::class, 'decode_template_row' ], $rows );
    }

    /**
     * Soft-delete (deactivate) a template.
     * Hard-deletes only if the template has never been used in shift_assign.
     *
     * @param int $id
     * @return array { success: bool, deleted: bool, deactivated: bool, error?: string }
     */
    public static function delete_template( int $id ): array {
        global $wpdb;

        if ( ! self::get_template( $id ) ) {
            return [ 'success' => false, 'error' => __( 'Template not found.', 'sfs-hr' ) ];
        }

        // Check if template has a stored reference in bids or assign (via template_id column if present).
        // Because shift_assign doesn't store template_id we can always hard-delete; just deactivate to be safe.
        $result = $wpdb->update(
            $wpdb->prefix . 'sfs_hr_attendance_shift_templates',
            [ 'is_active' => 0, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        if ( false === $result ) {
            return [ 'success' => false, 'error' => __( 'Database error deleting template.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'deleted' => false, 'deactivated' => true ];
    }

    // -------------------------------------------------------------------------
    // Roster Generation
    // -------------------------------------------------------------------------

    /**
     * Generate roster assignments for a date range from a template.
     *
     * Iterates the date range, maps each day to the template pattern using
     * `(day_offset % cycle_days)`, checks rest violations when min_rest_hours > 0,
     * and bulk-inserts (or replaces) rows into sfs_hr_attendance_shift_assign.
     *
     * @param int[]  $employee_ids
     * @param int    $template_id
     * @param string $start_date   Y-m-d
     * @param string $end_date     Y-m-d
     * @param array  $options {
     *     @type bool $overwrite      Replace existing assignments. Default false.
     *     @type bool $skip_holidays  Skip dates that are already marked as holiday. Default true.
     * }
     * @return array {
     *     success:        bool,
     *     inserted:       int,
     *     skipped:        int,
     *     violations:     array,
     *     error?:         string,
     * }
     */
    public static function generate_roster(
        array $employee_ids,
        int $template_id,
        string $start_date,
        string $end_date,
        array $options = []
    ): array {
        global $wpdb;

        $overwrite     = (bool) ( $options['overwrite']     ?? false );
        $skip_holidays = (bool) ( $options['skip_holidays'] ?? true );

        $template = self::get_template( $template_id );
        if ( ! $template ) {
            return [ 'success' => false, 'error' => __( 'Template not found.', 'sfs-hr' ) ];
        }

        if ( empty( $employee_ids ) ) {
            return [ 'success' => false, 'error' => __( 'No employees specified.', 'sfs-hr' ) ];
        }

        $start_ts = strtotime( $start_date );
        $end_ts   = strtotime( $end_date );
        if ( ! $start_ts || ! $end_ts || $start_ts > $end_ts ) {
            return [ 'success' => false, 'error' => __( 'Invalid date range.', 'sfs-hr' ) ];
        }

        $pattern    = $template['pattern_json'];   // already decoded array
        $cycle_days = (int) $template['cycle_days'];
        $min_rest   = (int) $template['min_rest_hours'];

        if ( $cycle_days < 1 ) {
            $cycle_days = 7;
        }

        // Index pattern by day (0-based within cycle).
        $pattern_map = [];
        foreach ( $pattern as $entry ) {
            $day_idx = (int) $entry['day'] - 1; // pattern uses 1-based day
            $pattern_map[ $day_idx ] = $entry;
        }

        $assign_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';
        $shift_table  = $wpdb->prefix . 'sfs_hr_attendance_shifts';

        // Pre-load shift end times for rest violation checks.
        $shift_end_cache = [];

        $inserted   = 0;
        $skipped    = 0;
        $violations = [];

        $date_range = self::date_range( $start_date, $end_date );

        foreach ( $employee_ids as $employee_id ) {
            $employee_id = (int) $employee_id;
            if ( $employee_id < 1 ) {
                continue;
            }

            foreach ( $date_range as $day_offset => $ymd ) {
                $pattern_idx = $day_offset % $cycle_days;
                $entry       = $pattern_map[ $pattern_idx ] ?? null;

                // No entry for this day slot → treat as off day.
                if ( ! $entry ) {
                    $skipped++;
                    continue;
                }

                $is_off     = ! empty( $entry['is_off'] );
                $shift_id   = isset( $entry['shift_id'] ) ? (int) $entry['shift_id'] : 0;
                $is_holiday = 0;

                if ( $is_off || ! $shift_id ) {
                    // Mark as holiday/off in assignment.
                    $is_holiday = 1;
                    $shift_id   = 0;
                }

                // Skip holiday dates when requested (existing holiday rows in DB).
                if ( $skip_holidays && ! $is_holiday ) {
                    $existing_holiday = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT is_holiday FROM {$assign_table}
                             WHERE employee_id = %d AND work_date = %s
                             LIMIT 1",
                            $employee_id,
                            $ymd
                        )
                    );
                    if ( '1' === (string) $existing_holiday ) {
                        $skipped++;
                        continue;
                    }
                }

                // Check rest violation before assigning a real shift.
                if ( $shift_id && $min_rest > 0 ) {
                    $violation = self::check_rest_violation( $employee_id, $ymd, $shift_id, $min_rest, $shift_end_cache );
                    if ( $violation ) {
                        $violations[] = $violation;
                        // Still insert but flag the violation — caller decides.
                    }
                }

                if ( $overwrite ) {
                    // Delete existing row first, then insert.
                    $wpdb->delete(
                        $assign_table,
                        [
                            'employee_id' => $employee_id,
                            'work_date'   => $ymd,
                        ],
                        [ '%d', '%s' ]
                    );
                } else {
                    // Skip if row already exists.
                    $exists = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM {$assign_table}
                             WHERE employee_id = %d AND work_date = %s
                             LIMIT 1",
                            $employee_id,
                            $ymd
                        )
                    );
                    if ( $exists ) {
                        $skipped++;
                        continue;
                    }
                }

                $row_data = [
                    'employee_id' => $employee_id,
                    'shift_id'    => $shift_id ?: null,
                    'work_date'   => $ymd,
                    'is_holiday'  => $is_holiday,
                ];
                $formats = [ '%d', $shift_id ? '%d' : 'NULL', '%s', '%d' ];

                // wpdb->insert doesn't support NULL via format; use direct prepare.
                if ( $shift_id ) {
                    $wpdb->insert( $assign_table, $row_data, [ '%d', '%d', '%s', '%d' ] );
                } else {
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT INTO {$assign_table} (employee_id, shift_id, work_date, is_holiday)
                             VALUES (%d, NULL, %s, %d)",
                            $employee_id,
                            $ymd,
                            $is_holiday
                        )
                    );
                }

                if ( $wpdb->insert_id ) {
                    $inserted++;
                }
            }
        }

        return [
            'success'    => true,
            'inserted'   => $inserted,
            'skipped'    => $skipped,
            'violations' => $violations,
        ];
    }

    /**
     * Get roster for a date range.
     *
     * Returns a structured array: [ employee_id => [ ymd => row ] ]
     * Each row includes shift details merged from sfs_hr_attendance_shifts.
     *
     * @param string     $start_date  Y-m-d
     * @param string     $end_date    Y-m-d
     * @param int|null   $dept_id     Optional department filter.
     * @param int[]|null $employee_ids  Optional explicit employee list.
     * @return array
     */
    public static function get_roster(
        string $start_date,
        string $end_date,
        ?int $dept_id = null,
        ?array $employee_ids = null
    ): array {
        global $wpdb;

        $assign_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';
        $shift_table  = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $emp_table    = $wpdb->prefix . 'sfs_hr_employees';

        $where   = [ 'sa.work_date BETWEEN %s AND %s' ];
        $params  = [ $start_date, $end_date ];

        if ( ! empty( $employee_ids ) ) {
            $ids_placeholders = implode( ',', array_fill( 0, count( $employee_ids ), '%d' ) );
            $where[]  = "sa.employee_id IN ({$ids_placeholders})";
            $params   = array_merge( $params, array_map( 'intval', $employee_ids ) );
        } elseif ( $dept_id ) {
            $where[]  = 'e.dept_id = %d';
            $params[] = $dept_id;
        }

        $where_sql = implode( ' AND ', $where );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sa.employee_id,
                        sa.work_date,
                        sa.is_holiday,
                        sa.id AS assign_id,
                        sh.id AS shift_id,
                        sh.name AS shift_name,
                        sh.start_time,
                        sh.end_time,
                        e.first_name,
                        e.last_name,
                        e.dept_id
                 FROM {$assign_table} sa
                 LEFT JOIN {$shift_table} sh ON sh.id = sa.shift_id
                 LEFT JOIN {$emp_table} e    ON e.id  = sa.employee_id
                 WHERE {$where_sql}
                 ORDER BY sa.employee_id ASC, sa.work_date ASC",
                ...$params
            ),
            ARRAY_A
        );

        $roster = [];
        foreach ( (array) $rows as $row ) {
            $emp_id = (int) $row['employee_id'];
            $ymd    = $row['work_date'];

            $roster[ $emp_id ][ $ymd ] = [
                'assign_id'  => (int) $row['assign_id'],
                'shift_id'   => $row['shift_id'] ? (int) $row['shift_id'] : null,
                'shift_name' => $row['shift_name'],
                'start_time' => $row['start_time'],
                'end_time'   => $row['end_time'],
                'is_holiday' => (bool) $row['is_holiday'],
                'employee'   => [
                    'id'         => $emp_id,
                    'first_name' => $row['first_name'],
                    'last_name'  => $row['last_name'],
                    'dept_id'    => $row['dept_id'] ? (int) $row['dept_id'] : null,
                ],
            ];
        }

        return $roster;
    }

    /**
     * Get calendar view data for a week/month.
     *
     * Returns an array indexed by date, each containing a list of employee shifts.
     * Suitable for calendar rendering.
     *
     * @param string   $start_date Y-m-d
     * @param string   $end_date   Y-m-d
     * @param int|null $dept_id    Optional department filter.
     * @return array  [ ymd => [ [ employee_id, shift_id, shift_name, start_time, end_time, is_holiday ], ... ] ]
     */
    public static function get_calendar_data(
        string $start_date,
        string $end_date,
        ?int $dept_id = null
    ): array {
        $roster   = self::get_roster( $start_date, $end_date, $dept_id );
        $calendar = [];

        foreach ( $roster as $emp_id => $days ) {
            foreach ( $days as $ymd => $entry ) {
                $calendar[ $ymd ][] = [
                    'employee_id' => $emp_id,
                    'shift_id'    => $entry['shift_id'],
                    'shift_name'  => $entry['shift_name'],
                    'start_time'  => $entry['start_time'],
                    'end_time'    => $entry['end_time'],
                    'is_holiday'  => $entry['is_holiday'],
                    'employee'    => $entry['employee'],
                ];
            }
        }

        // Ensure dates are sorted.
        ksort( $calendar );

        return $calendar;
    }

    // -------------------------------------------------------------------------
    // Rest Enforcement
    // -------------------------------------------------------------------------

    /**
     * Check if assigning a shift would violate minimum rest hours.
     *
     * Looks at the previous day's shift end_time and the proposed shift's start_time.
     * Returns a violation descriptor array, or null if no violation.
     *
     * @param int    $employee_id
     * @param string $date         Y-m-d  (the date being assigned)
     * @param int    $shift_id     The shift to be assigned.
     * @param int    $min_rest_hours  Minimum required rest. Defaults to 8.
     * @param array  &$shift_cache  Internal cache for shift rows (passed by ref).
     * @return array|null {
     *     employee_id, date, previous_end, proposed_start, rest_hours, min_rest_hours
     * }
     */
    public static function check_rest_violation(
        int $employee_id,
        string $date,
        int $shift_id,
        int $min_rest_hours = 8,
        array &$shift_cache = []
    ): ?array {
        global $wpdb;

        $assign_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';
        $shift_table  = $wpdb->prefix . 'sfs_hr_attendance_shifts';

        // Proposed shift start_time.
        $proposed_shift = self::get_shift_cached( $shift_id, $shift_cache );
        if ( ! $proposed_shift || empty( $proposed_shift['start_time'] ) ) {
            return null;
        }

        $prev_date = gmdate( 'Y-m-d', strtotime( $date . ' -1 day' ) );

        $prev_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT sa.shift_id, sh.start_time, sh.end_time
                 FROM {$assign_table} sa
                 JOIN {$shift_table} sh ON sh.id = sa.shift_id
                 WHERE sa.employee_id = %d
                   AND sa.work_date   = %s
                   AND sa.is_holiday  = 0
                   AND sa.shift_id IS NOT NULL
                 LIMIT 1",
                $employee_id,
                $prev_date
            ),
            ARRAY_A
        );

        if ( ! $prev_row || empty( $prev_row['end_time'] ) ) {
            return null;
        }

        // Build DateTime objects to handle shifts crossing midnight.
        $prev_end_dt      = new \DateTime( $prev_date . ' ' . $prev_row['end_time'] );
        $proposed_start_dt = new \DateTime( $date       . ' ' . $proposed_shift['start_time'] );

        // Detect overnight shift: if end_time <= start_time, the shift crosses midnight.
        if ( ! empty( $prev_row['start_time'] ) && $prev_row['end_time'] <= $prev_row['start_time'] ) {
            $prev_end_dt->modify( '+1 day' );
        }

        // Safety: if prev end is still after proposed start, adjust.
        if ( $prev_end_dt > $proposed_start_dt ) {
            // This means the previous shift's effective end is in the same calendar day
            // as the proposed start — rest period is negative (overlap).
        }

        $diff_seconds = $proposed_start_dt->getTimestamp() - $prev_end_dt->getTimestamp();
        $rest_hours   = $diff_seconds / 3600;

        if ( $rest_hours < $min_rest_hours ) {
            return [
                'employee_id'    => $employee_id,
                'date'           => $date,
                'previous_date'  => $prev_date,
                'previous_end'   => $prev_row['end_time'],
                'proposed_start' => $proposed_shift['start_time'],
                'rest_hours'     => round( $rest_hours, 2 ),
                'min_rest_hours' => $min_rest_hours,
            ];
        }

        return null;
    }

    /**
     * Validate entire roster for rest violations before applying.
     *
     * @param int[]  $employee_ids
     * @param int    $template_id
     * @param string $start_date  Y-m-d
     * @param string $end_date    Y-m-d
     * @return array {
     *     valid:       bool,
     *     violations:  array,
     * }
     */
    public static function validate_roster(
        array $employee_ids,
        int $template_id,
        string $start_date,
        string $end_date
    ): array {
        $template = self::get_template( $template_id );
        if ( ! $template ) {
            return [ 'valid' => false, 'violations' => [], 'error' => __( 'Template not found.', 'sfs-hr' ) ];
        }

        $pattern    = $template['pattern_json'];
        $cycle_days = max( 1, (int) $template['cycle_days'] );
        $min_rest   = (int) $template['min_rest_hours'];

        if ( $min_rest < 1 ) {
            return [ 'valid' => true, 'violations' => [] ];
        }

        $pattern_map = [];
        foreach ( $pattern as $entry ) {
            $pattern_map[ (int) $entry['day'] - 1 ] = $entry;
        }

        $date_range   = self::date_range( $start_date, $end_date );
        $violations   = [];
        $shift_cache  = [];

        foreach ( $employee_ids as $employee_id ) {
            $employee_id = (int) $employee_id;
            // Track the previous day's shift within this template for intra-template checks.
            $prev_template_shift_id = 0;

            foreach ( $date_range as $day_offset => $ymd ) {
                $entry    = $pattern_map[ $day_offset % $cycle_days ] ?? null;
                $shift_id = $entry && ! empty( $entry['shift_id'] ) && empty( $entry['is_off'] )
                    ? (int) $entry['shift_id']
                    : 0;

                if ( ! $shift_id ) {
                    $prev_template_shift_id = 0;
                    continue;
                }

                // First check against DB (previous day's stored assignment).
                $violation = self::check_rest_violation( $employee_id, $ymd, $shift_id, $min_rest, $shift_cache );
                if ( $violation ) {
                    $violations[] = $violation;
                } elseif ( $prev_template_shift_id && $day_offset > 0 ) {
                    // Also check against the intra-template previous day's shift
                    // (may not be in DB yet during dry-run validation).
                    $prev_shift = self::get_shift_cached( $prev_template_shift_id, $shift_cache );
                    $curr_shift = self::get_shift_cached( $shift_id, $shift_cache );
                    if ( $prev_shift && $curr_shift && ! empty( $prev_shift['end_time'] ) && ! empty( $curr_shift['start_time'] ) ) {
                        $prev_ymd  = $date_range[ $day_offset - 1 ] ?? '';
                        $prev_end  = new \DateTime( $prev_ymd . ' ' . $prev_shift['end_time'] );
                        $curr_start = new \DateTime( $ymd . ' ' . $curr_shift['start_time'] );
                        // Handle overnight previous shift.
                        if ( ! empty( $prev_shift['start_time'] ) && $prev_shift['end_time'] <= $prev_shift['start_time'] ) {
                            $prev_end->modify( '+1 day' );
                        }
                        $rest_hours = ( $curr_start->getTimestamp() - $prev_end->getTimestamp() ) / 3600;
                        if ( $rest_hours < $min_rest ) {
                            $violations[] = [
                                'employee_id'    => $employee_id,
                                'date'           => $ymd,
                                'previous_date'  => $prev_ymd,
                                'previous_end'   => $prev_shift['end_time'],
                                'proposed_start' => $curr_shift['start_time'],
                                'rest_hours'     => round( $rest_hours, 2 ),
                                'min_rest_hours' => $min_rest,
                            ];
                        }
                    }
                }

                $prev_template_shift_id = $shift_id;
            }
        }

        return [
            'valid'      => empty( $violations ),
            'violations' => $violations,
        ];
    }

    // -------------------------------------------------------------------------
    // Shift Bidding
    // -------------------------------------------------------------------------

    /**
     * Create a shift bid (employee requests preferred shift for a date).
     *
     * @param int    $employee_id
     * @param string $date               Y-m-d
     * @param int    $preferred_shift_id
     * @param string $reason             Optional reason text.
     * @return array { success: bool, id?: int, error?: string }
     */
    public static function create_bid(
        int $employee_id,
        string $date,
        int $preferred_shift_id,
        string $reason = ''
    ): array {
        global $wpdb;

        if ( $employee_id < 1 || $preferred_shift_id < 1 ) {
            return [ 'success' => false, 'error' => __( 'Invalid employee or shift.', 'sfs-hr' ) ];
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid date format.', 'sfs-hr' ) ];
        }

        // Only one pending bid per employee per date.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sfs_hr_attendance_shift_bids
                 WHERE employee_id = %d AND work_date = %s AND status = 'pending'
                 LIMIT 1",
                $employee_id,
                $date
            )
        );
        if ( $existing ) {
            return [ 'success' => false, 'error' => __( 'A pending bid already exists for this date.', 'sfs-hr' ) ];
        }

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sfs_hr_attendance_shift_bids',
            [
                'employee_id'        => $employee_id,
                'work_date'          => $date,
                'preferred_shift_id' => $preferred_shift_id,
                'reason'             => $reason,
                'status'             => 'pending',
                'created_at'         => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            return [ 'success' => false, 'error' => __( 'Database error creating bid.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * Get bids for a date range (for manager view).
     *
     * @param string   $start_date Y-m-d
     * @param string   $end_date   Y-m-d
     * @param int|null $dept_id    Optional department filter.
     * @return array  Array of bid rows with employee and shift info joined.
     */
    public static function get_bids(
        string $start_date,
        string $end_date,
        ?int $dept_id = null
    ): array {
        global $wpdb;

        $bids_table  = $wpdb->prefix . 'sfs_hr_attendance_shift_bids';
        $emp_table   = $wpdb->prefix . 'sfs_hr_employees';
        $shift_table = $wpdb->prefix . 'sfs_hr_attendance_shifts';

        $where  = [ 'b.work_date BETWEEN %s AND %s' ];
        $params = [ $start_date, $end_date ];

        if ( $dept_id ) {
            $where[]  = 'e.dept_id = %d';
            $params[] = $dept_id;
        }

        $where_sql = implode( ' AND ', $where );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.*,
                        e.first_name, e.last_name, e.dept_id,
                        sh.name AS shift_name, sh.start_time, sh.end_time
                 FROM {$bids_table} b
                 LEFT JOIN {$emp_table}   e  ON e.id  = b.employee_id
                 LEFT JOIN {$shift_table} sh ON sh.id = b.preferred_shift_id
                 WHERE {$where_sql}
                 ORDER BY b.work_date ASC, b.created_at ASC",
                ...$params
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Approve a bid — assigns the employee to the requested shift.
     *
     * @param int $bid_id
     * @param int $approved_by  WP user ID of the approver.
     * @return array { success: bool, error?: string }
     */
    public static function approve_bid( int $bid_id, int $approved_by ): array {
        global $wpdb;

        $bid = self::get_bid( $bid_id );
        if ( ! $bid ) {
            return [ 'success' => false, 'error' => __( 'Bid not found.', 'sfs-hr' ) ];
        }

        if ( 'pending' !== $bid['status'] ) {
            return [ 'success' => false, 'error' => __( 'Bid is not pending.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );

        // Wrap in transaction for atomicity.
        $wpdb->query( 'START TRANSACTION' );

        try {
            // Create/overwrite the shift assignment.
            $assign_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';

            $wpdb->delete(
                $assign_table,
                [ 'employee_id' => (int) $bid['employee_id'], 'work_date' => $bid['work_date'] ],
                [ '%d', '%s' ]
            );

            $wpdb->insert(
                $assign_table,
                [
                    'employee_id' => (int) $bid['employee_id'],
                    'shift_id'    => (int) $bid['preferred_shift_id'],
                    'work_date'   => $bid['work_date'],
                    'is_holiday'  => 0,
                ],
                [ '%d', '%d', '%s', '%d' ]
            );

            // Update bid status.
            $updated = $wpdb->update(
                $wpdb->prefix . 'sfs_hr_attendance_shift_bids',
                [
                    'status'      => 'approved',
                    'decided_by'  => $approved_by,
                    'decided_at'  => $now,
                ],
                [ 'id' => $bid_id ],
                [ '%s', '%d', '%s' ],
                [ '%d' ]
            );

            if ( false === $updated ) {
                $wpdb->query( 'ROLLBACK' );
                return [ 'success' => false, 'error' => __( 'Database error approving bid.', 'sfs-hr' ) ];
            }

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }

        return [ 'success' => true ];
    }

    /**
     * Reject a bid.
     *
     * @param int    $bid_id
     * @param int    $rejected_by  WP user ID.
     * @param string $reason       Optional rejection reason.
     * @return array { success: bool, error?: string }
     */
    public static function reject_bid( int $bid_id, int $rejected_by, string $reason = '' ): array {
        global $wpdb;

        $bid = self::get_bid( $bid_id );
        if ( ! $bid ) {
            return [ 'success' => false, 'error' => __( 'Bid not found.', 'sfs-hr' ) ];
        }

        if ( 'pending' !== $bid['status'] ) {
            return [ 'success' => false, 'error' => __( 'Bid is not pending.', 'sfs-hr' ) ];
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'sfs_hr_attendance_shift_bids',
            [
                'status'           => 'rejected',
                'decided_by'       => $rejected_by,
                'decided_at'       => current_time( 'mysql' ),
                'rejection_reason' => $reason,
            ],
            [ 'id' => $bid_id ],
            [ '%s', '%d', '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            return [ 'success' => false, 'error' => __( 'Database error rejecting bid.', 'sfs-hr' ) ];
        }

        return [ 'success' => true ];
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Clear roster assignments for a date range.
     *
     * @param int[]  $employee_ids
     * @param string $start_date  Y-m-d
     * @param string $end_date    Y-m-d
     * @return int  Number of rows deleted.
     */
    public static function clear_roster(
        array $employee_ids,
        string $start_date,
        string $end_date
    ): int {
        global $wpdb;

        if ( empty( $employee_ids ) ) {
            return 0;
        }

        $ids_placeholders = implode( ',', array_fill( 0, count( $employee_ids ), '%d' ) );
        $params           = array_map( 'intval', $employee_ids );
        $params[]         = $start_date;
        $params[]         = $end_date;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}sfs_hr_attendance_shift_assign
                 WHERE employee_id IN ({$ids_placeholders})
                   AND work_date BETWEEN %s AND %s",
                ...$params
            )
        );

        return (int) $wpdb->rows_affected;
    }

    /**
     * Copy roster from one date range to another.
     *
     * The source range is mapped 1-to-1 by day-of-offset onto the target range.
     * Source and target ranges must be the same length.
     *
     * @param string     $source_start  Y-m-d
     * @param string     $source_end    Y-m-d
     * @param string     $target_start  Y-m-d
     * @param int[]|null $employee_ids  Optional filter. If null, copies all employees.
     * @return array {
     *     success:  bool,
     *     copied:   int,
     *     skipped:  int,
     *     error?:   string,
     * }
     */
    public static function copy_roster(
        string $source_start,
        string $source_end,
        string $target_start,
        ?array $employee_ids = null
    ): array {
        global $wpdb;

        $source_dates = self::date_range( $source_start, $source_end );
        $day_count    = count( $source_dates );

        if ( $day_count < 1 ) {
            return [ 'success' => false, 'error' => __( 'Invalid source date range.', 'sfs-hr' ) ];
        }

        $target_dates = self::date_range(
            $target_start,
            gmdate( 'Y-m-d', strtotime( $target_start . ' +' . ( $day_count - 1 ) . ' days' ) )
        );

        $assign_table = $wpdb->prefix . 'sfs_hr_attendance_shift_assign';

        // Fetch source assignments.
        $where  = [ 'work_date BETWEEN %s AND %s' ];
        $params = [ $source_start, $source_end ];

        if ( ! empty( $employee_ids ) ) {
            $ids_placeholders = implode( ',', array_fill( 0, count( $employee_ids ), '%d' ) );
            $where[]  = "employee_id IN ({$ids_placeholders})";
            $params   = array_merge( $params, array_map( 'intval', $employee_ids ) );
        }

        $where_sql = implode( ' AND ', $where );

        $source_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT employee_id, shift_id, work_date, is_holiday
                 FROM {$assign_table}
                 WHERE {$where_sql}
                 ORDER BY work_date ASC",
                ...$params
            ),
            ARRAY_A
        );

        // Index source rows by offset+employee.
        $source_index = [];
        foreach ( (array) $source_rows as $row ) {
            $offset = array_search( $row['work_date'], $source_dates, true );
            if ( false !== $offset ) {
                $source_index[ $offset ][ (int) $row['employee_id'] ] = $row;
            }
        }

        $copied  = 0;
        $skipped = 0;

        foreach ( $source_dates as $offset => $source_ymd ) {
            $target_ymd = $target_dates[ $offset ] ?? null;
            if ( ! $target_ymd ) {
                continue;
            }

            $day_employees = $source_index[ $offset ] ?? [];
            foreach ( $day_employees as $emp_id => $row ) {
                // Skip if target already has an assignment.
                $exists = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$assign_table}
                         WHERE employee_id = %d AND work_date = %s LIMIT 1",
                        $emp_id,
                        $target_ymd
                    )
                );
                if ( $exists ) {
                    $skipped++;
                    continue;
                }

                if ( null !== $row['shift_id'] ) {
                    $wpdb->insert(
                        $assign_table,
                        [
                            'employee_id' => $emp_id,
                            'shift_id'    => (int) $row['shift_id'],
                            'work_date'   => $target_ymd,
                            'is_holiday'  => (int) $row['is_holiday'],
                        ],
                        [ '%d', '%d', '%s', '%d' ]
                    );
                } else {
                    $wpdb->query(
                        $wpdb->prepare(
                            "INSERT INTO {$assign_table} (employee_id, shift_id, work_date, is_holiday)
                             VALUES (%d, NULL, %s, %d)",
                            $emp_id,
                            $target_ymd,
                            (int) $row['is_holiday']
                        )
                    );
                }

                if ( $wpdb->insert_id ) {
                    $copied++;
                }
            }
        }

        return [
            'success' => true,
            'copied'  => $copied,
            'skipped' => $skipped,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate and sanitize template input data.
     *
     * @param array $data
     * @return array  Sanitized data, or [ 'error' => string ] on failure.
     */
    private static function validate_template_data( array $data ): array {
        $allowed_pattern_types = [ 'fixed', 'rotating', 'custom' ];

        $name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
        if ( '' === $name ) {
            return [ 'error' => __( 'Template name is required.', 'sfs-hr' ) ];
        }
        if ( mb_strlen( $name ) > 100 ) {
            return [ 'error' => __( 'Template name must be 100 characters or fewer.', 'sfs-hr' ) ];
        }

        $pattern_type = isset( $data['pattern_type'] ) ? (string) $data['pattern_type'] : 'fixed';
        if ( ! in_array( $pattern_type, $allowed_pattern_types, true ) ) {
            return [ 'error' => __( 'Invalid pattern type.', 'sfs-hr' ) ];
        }

        $pattern_json = $data['pattern_json'] ?? [];
        if ( is_string( $pattern_json ) ) {
            $pattern_json = json_decode( $pattern_json, true );
        }
        if ( ! is_array( $pattern_json ) || empty( $pattern_json ) ) {
            return [ 'error' => __( 'pattern_json must be a non-empty array.', 'sfs-hr' ) ];
        }

        $cycle_days     = max( 1, (int) ( $data['cycle_days']     ?? 7 ) );
        $min_rest_hours = max( 0, (int) ( $data['min_rest_hours'] ?? 8 ) );
        $description    = isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : null;
        $is_active      = isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1;

        return compact( 'name', 'pattern_type', 'pattern_json', 'cycle_days', 'min_rest_hours', 'description', 'is_active' );
    }

    /**
     * Decode a template DB row (JSON-decode pattern_json, cast numeric fields).
     *
     * @param array $row  Raw associative row from DB.
     * @return array
     */
    private static function decode_template_row( array $row ): array {
        $row['id']             = (int) $row['id'];
        $row['cycle_days']     = (int) $row['cycle_days'];
        $row['min_rest_hours'] = (int) $row['min_rest_hours'];
        $row['is_active']      = (bool) $row['is_active'];

        if ( is_string( $row['pattern_json'] ) ) {
            $decoded = json_decode( $row['pattern_json'], true );
            $row['pattern_json'] = is_array( $decoded ) ? $decoded : [];
        }

        return $row;
    }

    /**
     * Get a single bid row by ID.
     *
     * @param int $bid_id
     * @return array|null
     */
    private static function get_bid( int $bid_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_attendance_shift_bids WHERE id = %d",
                $bid_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get a shift row by ID, with an in-memory cache.
     *
     * @param int   $shift_id
     * @param array &$cache  Reference to caller's cache array.
     * @return array|null
     */
    private static function get_shift_cached( int $shift_id, array &$cache ): ?array {
        if ( isset( $cache[ $shift_id ] ) ) {
            return $cache[ $shift_id ];
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name, start_time, end_time FROM {$wpdb->prefix}sfs_hr_attendance_shifts WHERE id = %d",
                $shift_id
            ),
            ARRAY_A
        );

        $cache[ $shift_id ] = $row ?: null;

        return $cache[ $shift_id ];
    }

    /**
     * Build an ordered array of Y-m-d strings between two dates (inclusive).
     * Returns [ 0 => 'Y-m-d', 1 => 'Y-m-d', ... ] indexed by day-offset.
     *
     * @param string $start  Y-m-d
     * @param string $end    Y-m-d
     * @return string[]
     */
    private static function date_range( string $start, string $end ): array {
        $dates    = [];
        $current  = strtotime( $start );
        $end_ts   = strtotime( $end );

        while ( $current <= $end_ts ) {
            $dates[] = gmdate( 'Y-m-d', $current );
            $current  = strtotime( '+1 day', $current );
        }

        return $dates;
    }
}
