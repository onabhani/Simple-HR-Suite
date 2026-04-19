<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Biometric_Service
 *
 * M5.4 — Biometric Integration API.
 *
 * Manages biometric device registration, webhook authentication, punch
 * ingestion from ZKTeco / Hikvision / generic devices, deduplication,
 * auto punch-type detection, and device health monitoring.
 *
 * Table dependency (added by Migration separately):
 *   {prefix}sfs_hr_attendance_biometric_devices
 *
 * Existing tables read/written:
 *   {prefix}sfs_hr_attendance_punches
 *   {prefix}sfs_hr_employees
 */
class Biometric_Service {

    // -------------------------------------------------------------------------
    // Device Management
    // -------------------------------------------------------------------------

    /**
     * Register a new biometric device.
     *
     * @param array $data {
     *   @type string      $device_serial   Required. Unique serial number.
     *   @type string      $device_type     'zkteco'|'generic'|'hikvision'. Default 'generic'.
     *   @type string|null $device_name     Human-readable label.
     *   @type string|null $location_label  Location description.
     *   @type float|null  $location_lat
     *   @type float|null  $location_lng
     *   @type array|null  $config          Arbitrary config stored as JSON.
     * }
     * @return array { ok: bool, device_id?: int, api_key?: string, error?: string }
     */
    public static function register_device( array $data ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';

        $serial = sanitize_text_field( $data['device_serial'] ?? '' );
        if ( empty( $serial ) ) {
            return [ 'ok' => false, 'error' => 'device_serial is required.' ];
        }

        // Duplicate serial check
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE device_serial = %s LIMIT 1",
            $serial
        ) );
        if ( $exists ) {
            return [ 'ok' => false, 'error' => 'A device with this serial already exists.' ];
        }

        $api_key = self::generate_api_key();
        $now     = gmdate( 'Y-m-d H:i:s' );

        $row = [
            'device_serial'  => $serial,
            'device_type'    => in_array( $data['device_type'] ?? '', [ 'zkteco', 'hikvision', 'generic' ], true )
                                    ? $data['device_type']
                                    : 'generic',
            'device_name'    => isset( $data['device_name'] ) ? sanitize_text_field( $data['device_name'] ) : null,
            'api_key'        => $api_key,
            'location_label' => isset( $data['location_label'] ) ? sanitize_text_field( $data['location_label'] ) : null,
            'location_lat'   => isset( $data['location_lat'] )   ? (float) $data['location_lat']   : null,
            'location_lng'   => isset( $data['location_lng'] )   ? (float) $data['location_lng']   : null,
            'is_active'      => 1,
            'config_json'    => isset( $data['config'] ) ? wp_json_encode( $data['config'] ) : null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        $formats = [
            '%s', '%s', '%s', '%s', '%s',
            '%f', '%f',
            '%d', '%s', '%s', '%s',
        ];

        $inserted = $wpdb->insert( $table, $row, $formats );
        if ( ! $inserted ) {
            return [ 'ok' => false, 'error' => 'Database insert failed.' ];
        }

        return [
            'ok'        => true,
            'device_id' => (int) $wpdb->insert_id,
            'api_key'   => $api_key,
        ];
    }

    /**
     * Update an existing device record.
     *
     * @param int   $id   Device primary key.
     * @param array $data Subset of fields to update (same keys as register_device $data, minus device_serial).
     * @return array { ok: bool, error?: string }
     */
    public static function update_device( int $id, array $data ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';

        $existing = self::get_device( $id );
        if ( ! $existing ) {
            return [ 'ok' => false, 'error' => 'Device not found.' ];
        }

        $allowed_string_fields = [ 'device_name', 'location_label', 'device_type' ];
        $allowed_float_fields  = [ 'location_lat', 'location_lng' ];
        $allowed_int_fields    = [ 'is_active' ];

        $update  = [];
        $formats = [];

        foreach ( $allowed_string_fields as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                if ( $field === 'device_type' && ! in_array( $data[ $field ], [ 'zkteco', 'hikvision', 'generic' ], true ) ) {
                    continue;
                }
                $update[ $field ] = sanitize_text_field( $data[ $field ] );
                $formats[]        = '%s';
            }
        }
        foreach ( $allowed_float_fields as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $update[ $field ] = $data[ $field ] !== null ? (float) $data[ $field ] : null;
                $formats[]        = '%f';
            }
        }
        foreach ( $allowed_int_fields as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $update[ $field ] = (int) $data[ $field ];
                $formats[]        = '%d';
            }
        }
        if ( array_key_exists( 'config', $data ) ) {
            $update['config_json'] = $data['config'] !== null ? wp_json_encode( $data['config'] ) : null;
            $formats[]             = '%s';
        }

        if ( empty( $update ) ) {
            return [ 'ok' => false, 'error' => 'No updatable fields provided.' ];
        }

        $update['updated_at'] = gmdate( 'Y-m-d H:i:s' );
        $formats[]            = '%s';

        $result = $wpdb->update( $table, $update, [ 'id' => $id ], $formats, [ '%d' ] );
        if ( $result === false ) {
            return [ 'ok' => false, 'error' => 'Database update failed.' ];
        }

        return [ 'ok' => true ];
    }

    /**
     * Fetch a single device by primary key.
     *
     * @param int $id
     * @return array|null
     */
    public static function get_device( int $id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Fetch a single device by serial number.
     *
     * @param string $serial
     * @return array|null
     */
    public static function get_device_by_serial( string $serial ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE device_serial = %s LIMIT 1", $serial ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * List devices, optionally filtering to active-only.
     *
     * @param bool $active_only Default true.
     * @return array[]
     */
    public static function list_devices( bool $active_only = true ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';

        if ( $active_only ) {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY device_name ASC",
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$table} ORDER BY device_name ASC",
                ARRAY_A
            );
        }

        return $rows ?: [];
    }

    /**
     * Soft-deactivate a device (sets is_active = 0).
     *
     * @param int $id
     * @return array { ok: bool, error?: string }
     */
    public static function deactivate_device( int $id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';

        $existing = self::get_device( $id );
        if ( ! $existing ) {
            return [ 'ok' => false, 'error' => 'Device not found.' ];
        }

        $result = $wpdb->update(
            $table,
            [ 'is_active' => 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => $id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        if ( $result === false ) {
            return [ 'ok' => false, 'error' => 'Database update failed.' ];
        }

        return [ 'ok' => true ];
    }

    /**
     * Generate a cryptographically random API key (64 hex chars = 256-bit).
     *
     * @return string
     */
    public static function generate_api_key(): string {
        if ( function_exists( 'random_bytes' ) ) {
            try {
                return bin2hex( random_bytes( 32 ) );
            } catch ( \Exception $e ) {
                // Fall through to wp_generate_password
            }
        }
        return wp_generate_password( 64, false );
    }

    /**
     * Validate a raw API key and return the matching device record.
     *
     * Only returns the device if it is active. The api_key column stores the
     * key in plain text (it is generated once and is the device's credential).
     *
     * @param string $api_key
     * @return array|null Device row, or null if not found / inactive.
     */
    public static function authenticate_device( string $api_key ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';

        if ( empty( $api_key ) ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE api_key = %s AND is_active = 1 LIMIT 1",
                $api_key
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    // -------------------------------------------------------------------------
    // Heartbeat
    // -------------------------------------------------------------------------

    /**
     * Record a device heartbeat.
     *
     * Called periodically by the device (or its push client) to signal it is
     * online. Updates last_heartbeat_at.
     *
     * @param string $device_serial
     * @return array { ok: bool, error?: string }
     */
    public static function heartbeat( string $device_serial ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';

        $device = self::get_device_by_serial( $device_serial );
        if ( ! $device ) {
            return [ 'ok' => false, 'error' => 'Device not found.' ];
        }

        $result = $wpdb->update(
            $table,
            [
                'last_heartbeat_at' => gmdate( 'Y-m-d H:i:s' ),
                'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ 'id' => (int) $device['id'] ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( $result === false ) {
            return [ 'ok' => false, 'error' => 'Database update failed.' ];
        }

        return [ 'ok' => true ];
    }

    /**
     * Return all active devices that have not sent a heartbeat within N minutes
     * (or have never sent one).
     *
     * @param int $minutes Threshold in minutes. Default 30.
     * @return array[]
     */
    public static function get_offline_devices( int $minutes = 30 ): array {
        global $wpdb;
        $table     = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';
        $threshold = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE is_active = 1
                   AND (last_heartbeat_at IS NULL OR last_heartbeat_at < %s)
                 ORDER BY device_name ASC",
                $threshold
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    // -------------------------------------------------------------------------
    // Punch Processing
    // -------------------------------------------------------------------------

    /**
     * Process a single punch received from a biometric device.
     *
     * Pipeline:
     *   1. Authenticate device via serial lookup (device must be active).
     *   2. Validate payload fields.
     *   3. Lookup employee by identifier.
     *   4. Deduplicate against recent punches.
     *   5. Auto-detect punch type when not provided.
     *   6. Insert into sfs_hr_attendance_punches (source = 'import_sync').
     *   7. Update device last_punch_at + total_punches counter.
     *   8. Schedule async session recalculation.
     *
     * @param array $payload {
     *   @type string      $device_serial       Required.
     *   @type string      $employee_identifier Required. employee_code, id_number, or WP user ID.
     *   @type string      $punch_time          Required. Y-m-d H:i:s (UTC).
     *   @type string|null $punch_type          'in'|'out'. Auto-detected when omitted.
     *   @type string      $verify_mode         'fingerprint'|'face'|'card'|'pin'. Default 'fingerprint'.
     * }
     * @return array { ok: bool, punch_id?: int, duplicate?: bool, error?: string }
     */
    public static function process_punch( array $payload ): array {
        global $wpdb;

        // 1. Device authentication by serial (no API key check here — callers must
        //    have already validated the API key via authenticate_device() in the
        //    REST handler before delegating to process_punch).
        $device_serial = sanitize_text_field( $payload['device_serial'] ?? '' );
        if ( empty( $device_serial ) ) {
            return [ 'ok' => false, 'error' => 'device_serial is required.' ];
        }

        $device = self::get_device_by_serial( $device_serial );
        if ( ! $device || ! (int) $device['is_active'] ) {
            return [ 'ok' => false, 'error' => 'Device not found or inactive.' ];
        }

        // 2. Validate punch_time
        $punch_time_raw = sanitize_text_field( $payload['punch_time'] ?? '' );
        $punch_ts       = strtotime( $punch_time_raw );
        if ( ! $punch_ts || $punch_ts <= 0 ) {
            return [ 'ok' => false, 'error' => 'Invalid punch_time format. Expected Y-m-d H:i:s.' ];
        }
        $punch_time = gmdate( 'Y-m-d H:i:s', $punch_ts );

        // 3. Employee lookup
        $identifier  = sanitize_text_field( $payload['employee_identifier'] ?? '' );
        if ( empty( $identifier ) ) {
            return [ 'ok' => false, 'error' => 'employee_identifier is required.' ];
        }
        $employee_id = self::lookup_employee( $identifier );
        if ( ! $employee_id ) {
            return [ 'ok' => false, 'error' => "Employee not found for identifier: {$identifier}" ];
        }

        // 4. Determine punch type
        $raw_type   = $payload['punch_type'] ?? null;
        $work_date  = gmdate( 'Y-m-d', $punch_ts );
        if ( in_array( $raw_type, [ 'in', 'out' ], true ) ) {
            $punch_type = $raw_type;
        } else {
            $punch_type = self::auto_detect_punch_type( $employee_id, $work_date );
        }

        // 5. Deduplication
        if ( self::is_duplicate( $employee_id, $punch_time, $punch_type ) ) {
            return [ 'ok' => true, 'duplicate' => true, 'punch_id' => null ];
        }

        // 6. Insert punch
        $verify_mode     = sanitize_text_field( $payload['verify_mode'] ?? 'fingerprint' );
        $allowed_modes   = [ 'fingerprint', 'face', 'card', 'pin' ];
        if ( ! in_array( $verify_mode, $allowed_modes, true ) ) {
            $verify_mode = 'fingerprint';
        }

        $punchT  = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $now_utc = gmdate( 'Y-m-d H:i:s' );

        $inserted = $wpdb->insert(
            $punchT,
            [
                'employee_id'  => $employee_id,
                'punch_type'   => $punch_type,
                'punch_time'   => $punch_time,
                'source'       => 'import_sync',
                'device_id'    => (int) $device['id'],
                'valid_geo'    => 1,
                'valid_selfie' => 1,
                'note'         => $verify_mode !== 'fingerprint' ? "verify:{$verify_mode}" : null,
                'created_at'   => $now_utc,
                'created_by'   => null,
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'error' => 'Failed to insert punch record.' ];
        }

        $punch_id = (int) $wpdb->insert_id;

        // 7. Update device stats
        $dev_table = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$dev_table}
                 SET last_punch_at = %s,
                     total_punches = total_punches + 1,
                     updated_at    = %s
                 WHERE id = %d",
                $punch_time,
                $now_utc,
                (int) $device['id']
            )
        );

        // 8. Schedule async session recalculation (fire-and-forget)
        wp_schedule_single_event(
            time(),
            'sfs_hr_attendance_deferred_recalc',
            [ $employee_id, $work_date, true ]
        );

        return [ 'ok' => true, 'duplicate' => false, 'punch_id' => $punch_id ];
    }

    /**
     * Process a batch of punches (bulk sync from a device).
     *
     * Each element in $punches has the same structure as process_punch() $payload.
     * Processing continues on individual failures — errors are collected.
     *
     * @param array $punches Array of punch payloads.
     * @return array {
     *   @type int   $total     Total received.
     *   @type int   $inserted  Successfully inserted.
     *   @type int   $duplicates  Skipped as duplicates.
     *   @type int   $errors    Failed records.
     *   @type array $error_log Array of { index, error } entries.
     * }
     */
    public static function process_batch( array $punches ): array {
        $total      = count( $punches );
        $inserted   = 0;
        $duplicates = 0;
        $errors     = 0;
        $error_log  = [];

        foreach ( $punches as $index => $payload ) {
            $result = self::process_punch( $payload );
            if ( ! $result['ok'] ) {
                $errors++;
                $error_log[] = [
                    'index' => $index,
                    'error' => $result['error'] ?? 'Unknown error.',
                ];
            } elseif ( $result['duplicate'] ?? false ) {
                $duplicates++;
            } else {
                $inserted++;
            }
        }

        return compact( 'total', 'inserted', 'duplicates', 'errors', 'error_log' );
    }

    /**
     * Check whether a near-identical punch already exists (deduplication).
     *
     * Matches on employee_id + punch_type within ±window_seconds of punch_time.
     *
     * @param int    $employee_id
     * @param string $punch_time     Y-m-d H:i:s (UTC).
     * @param string $punch_type     'in' or 'out'.
     * @param int    $window_seconds Default 60.
     * @return bool
     */
    public static function is_duplicate( int $employee_id, string $punch_time, string $punch_type, int $window_seconds = 60 ): bool {
        global $wpdb;
        $table     = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $ts        = strtotime( $punch_time );
        $low       = gmdate( 'Y-m-d H:i:s', $ts - $window_seconds );
        $high      = gmdate( 'Y-m-d H:i:s', $ts + $window_seconds );

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE employee_id = %d
                   AND punch_type  = %s
                   AND punch_time  BETWEEN %s AND %s
                 LIMIT 1",
                $employee_id,
                $punch_type,
                $low,
                $high
            )
        );

        return $count > 0;
    }

    /**
     * Auto-detect whether the next punch for an employee should be 'in' or 'out'.
     *
     * Logic: look at the last punch today. If it was 'in' → return 'out'.
     *        If it was 'out', or there are no punches today → return 'in'.
     *
     * @param int    $employee_id
     * @param string $date Y-m-d
     * @return string 'in' or 'out'
     */
    public static function auto_detect_punch_type( int $employee_id, string $date ): string {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_punches';

        $last_type = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT punch_type FROM {$table}
                 WHERE employee_id = %d
                   AND DATE(punch_time) = %s
                   AND punch_type IN ('in','out')
                 ORDER BY punch_time DESC
                 LIMIT 1",
                $employee_id,
                $date
            )
        );

        return ( $last_type === 'in' ) ? 'out' : 'in';
    }

    // -------------------------------------------------------------------------
    // ZKTeco Protocol
    // -------------------------------------------------------------------------

    /**
     * Parse a ZKTeco PUSH protocol request body into an array of normalized
     * punch payloads ready for process_punch() / process_batch().
     *
     * ZKTeco POST body format (application/x-www-form-urlencoded or raw):
     *   SN=SERIALXXX&table=ATTLOG&Stamp=9999
     *   PIN=1001\tTIME=2025-01-01 08:00:00\tSTATUS=0\tVERIFY=1\n
     *   PIN=1002\tTIME=2025-01-01 08:05:00\tSTATUS=1\tVERIFY=1\n
     *   ...
     *
     * STATUS codes: 0 = in, 1 = out, 2 = break_out (mapped → out), 3 = break_in (→ in),
     *               4 = OT-in (→ in), 5 = OT-out (→ out).
     *
     * VERIFY codes: 1 = fingerprint, 2 = face, 3 = card, 4 = pin.
     *
     * @param string $raw_body Raw HTTP request body.
     * @return array {
     *   @type string   $device_serial
     *   @type string   $table          ZKTeco table name (usually 'ATTLOG').
     *   @type array[]  $punches        Normalized punch payloads for process_batch().
     * }
     */
    public static function parse_zkteco_payload( string $raw_body ): array {
        $lines         = explode( "\n", str_replace( "\r\n", "\n", trim( $raw_body ) ) );
        $device_serial = '';
        $table_name    = '';
        $punches       = [];

        // ZKTeco verify mode mapping
        $verify_map = [
            '1' => 'fingerprint',
            '2' => 'face',
            '3' => 'card',
            '4' => 'pin',
        ];

        // ZKTeco status → punch type mapping
        $status_map = [
            '0' => 'in',
            '1' => 'out',
            '2' => 'out',   // break-out treated as out
            '3' => 'in',    // break-in treated as in
            '4' => 'in',    // OT-in
            '5' => 'out',   // OT-out
        ];

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            // Header line: key=value pairs separated by & (no tabs)
            if ( strpos( $line, "\t" ) === false && strpos( $line, '=' ) !== false && strpos( $line, 'PIN' ) === false ) {
                parse_str( $line, $header );
                if ( isset( $header['SN'] ) ) {
                    $device_serial = sanitize_text_field( $header['SN'] );
                }
                if ( isset( $header['table'] ) ) {
                    $table_name = sanitize_text_field( $header['table'] );
                }
                continue;
            }

            // Data line: tab-separated key=value pairs
            if ( strpos( $line, "\t" ) !== false ) {
                $fields = [];
                foreach ( explode( "\t", $line ) as $pair ) {
                    if ( strpos( $pair, '=' ) !== false ) {
                        [ $k, $v ] = explode( '=', $pair, 2 );
                        $fields[ strtoupper( trim( $k ) ) ] = trim( $v );
                    }
                }

                $pin         = $fields['PIN']    ?? null;
                $time_str    = $fields['TIME']   ?? null;
                $status_code = $fields['STATUS'] ?? '0';
                $verify_code = $fields['VERIFY'] ?? '1';

                if ( ! $pin || ! $time_str ) {
                    continue;
                }

                // Validate time
                $ts = strtotime( $time_str );
                if ( ! $ts ) {
                    continue;
                }

                $punch_type  = $status_map[ $status_code ] ?? 'in';
                $verify_mode = $verify_map[ $verify_code ]  ?? 'fingerprint';

                $punches[] = [
                    'device_serial'       => $device_serial,
                    'employee_identifier' => $pin,
                    'punch_time'          => gmdate( 'Y-m-d H:i:s', $ts ),
                    'punch_type'          => $punch_type,
                    'verify_mode'         => $verify_mode,
                ];
            }
        }

        return [
            'device_serial' => $device_serial,
            'table'         => $table_name,
            'punches'       => $punches,
        ];
    }

    // -------------------------------------------------------------------------
    // Employee Lookup
    // -------------------------------------------------------------------------

    /**
     * Resolve an opaque identifier string to an employee primary-key ID.
     *
     * Lookup order:
     *   1. employee_code (exact match, case-insensitive)
     *   2. id_number / Iqama (exact match)
     *   3. Numeric WP user_id
     *
     * @param string $identifier
     * @return int|null Employee PK, or null if not found.
     */
    public static function lookup_employee( string $identifier ): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';

        // 1. By employee_code
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE employee_code = %s AND status != 'terminated' LIMIT 1",
                $identifier
            )
        );
        if ( $id ) {
            return $id;
        }

        // 2. By id_number (Iqama)
        $id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE id_number = %s AND status != 'terminated' LIMIT 1",
                $identifier
            )
        );
        if ( $id ) {
            return $id;
        }

        // 3. By WP user_id (numeric identifier)
        if ( ctype_digit( $identifier ) ) {
            $user_id = (int) $identifier;
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE user_id = %d AND status != 'terminated' LIMIT 1",
                    $user_id
                )
            );
            if ( $id ) {
                return $id;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Health Dashboard
    // -------------------------------------------------------------------------

    /**
     * Aggregate device health data for the admin dashboard.
     *
     * Returns per-device status plus global summary counters.
     *
     * @return array {
     *   @type int     $total_devices
     *   @type int     $active_devices
     *   @type int     $offline_devices   Active but no heartbeat in 30 min.
     *   @type int     $inactive_devices
     *   @type array[] $devices           Full device rows with an additional
     *                                    'online' bool field.
     * }
     */
    public static function get_health_dashboard(): array {
        global $wpdb;
        $table     = $wpdb->prefix . 'sfs_hr_attendance_biometric_devices';
        $threshold = gmdate( 'Y-m-d H:i:s', time() - 1800 ); // 30 min

        $all_devices = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY is_active DESC, device_name ASC",
            ARRAY_A
        );

        $all_devices    = $all_devices ?: [];
        $total          = count( $all_devices );
        $active_count   = 0;
        $offline_count  = 0;
        $inactive_count = 0;

        foreach ( $all_devices as &$device ) {
            $is_active = (bool) (int) $device['is_active'];
            if ( ! $is_active ) {
                $inactive_count++;
                $device['online'] = false;
                continue;
            }
            $active_count++;
            $last_hb = $device['last_heartbeat_at'];
            $online  = $last_hb && $last_hb >= $threshold;
            if ( ! $online ) {
                $offline_count++;
            }
            $device['online'] = $online;
        }
        unset( $device );

        return [
            'total_devices'    => $total,
            'active_devices'   => $active_count,
            'offline_devices'  => $offline_count,
            'inactive_devices' => $inactive_count,
            'devices'          => $all_devices,
        ];
    }
}
