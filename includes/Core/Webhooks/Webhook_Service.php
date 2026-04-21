<?php
namespace SFS\HR\Core\Webhooks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Webhook_Service
 *
 * M9.3 — Outgoing webhook dispatcher with HMAC-SHA256 signing,
 * exponential-backoff retry logic, and delivery logging.
 *
 * Supported events are listed in Webhook_Service::EVENTS. Internal
 * do_action hooks are mapped to webhook events by init().
 *
 * Security:
 *  - HTTPS-only URLs in production (WP_DEBUG bypass for development).
 *  - SSRF guard: rejects localhost, private RFC1918 ranges, link-local,
 *    and metadata-service IPs (169.254.169.254).
 *  - Payload sanitization strips password / token / IBAN / national_id keys
 *    before dispatch.
 *  - Secret rotation supported via rotate_secret().
 *
 * @since M9
 */
class Webhook_Service {

    private static bool $initialized = false;

    /** Supported webhook event names (stable public contract). */
    const EVENTS = [
        'employee.created',
        'employee.updated',
        'employee.terminated',
        'leave.requested',
        'leave.approved',
        'leave.rejected',
        'attendance.punch_in',
        'attendance.punch_out',
        'payroll.run.completed',
        'payroll.run.approved',
        'resignation.submitted',
        'resignation.approved',
        'loan.created',
        'loan.approved',
        'document.uploaded',
        'settlement.approved',
    ];

    /** Keys scrubbed from webhook payloads. */
    private const SENSITIVE_KEYS = [
        'password', 'secret', 'token', 'api_key', 'api_secret',
        'bank_account', 'iban', 'id_number', 'national_id',
        'passport', 'visa_number',
    ];

    // ── Initialization ──────────────────────────────────────────────────────

    /**
     * Map internal do_action hooks to webhook events. Idempotent.
     */
    public static function init(): void {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        // Employee events
        add_action( 'sfs_hr_employee_created',  [ __CLASS__, 'on_employee_created' ], 20, 2 );
        add_action( 'sfs_hr_employee_updated',  [ __CLASS__, 'on_employee_updated' ], 20, 3 );
        add_action( 'sfs_hr_employee_deleted',  [ __CLASS__, 'on_employee_terminated' ], 20, 2 );

        // Leave events
        add_action( 'sfs_hr_leave_request_created',        [ __CLASS__, 'on_leave_requested' ], 20, 2 );
        add_action( 'sfs_hr_leave_request_status_changed', [ __CLASS__, 'on_leave_status_changed' ], 20, 3 );

        // Attendance events (dispatched from Session_Service when punches complete)
        add_action( 'sfs_hr_attendance_punch', [ __CLASS__, 'on_attendance_punch' ], 20, 3 );

        // Payroll events
        add_action( 'sfs_hr_payroll_run_completed', [ __CLASS__, 'on_payroll_run_completed' ], 20, 2 );
        add_action( 'sfs_hr_payroll_run_approved',  [ __CLASS__, 'on_payroll_run_approved' ], 20, 2 );

        // Resignation events
        add_action( 'sfs_hr_resignation_status_changed', [ __CLASS__, 'on_resignation_status_changed' ], 20, 3 );

        // Loan events
        add_action( 'sfs_hr_loan_created',        [ __CLASS__, 'on_loan_created' ], 20, 2 );
        add_action( 'sfs_hr_loan_status_changed', [ __CLASS__, 'on_loan_status_changed' ], 20, 3 );

        // Settlement events
        add_action( 'sfs_hr_settlement_status_changed', [ __CLASS__, 'on_settlement_status_changed' ], 20, 3 );

        // Document events
        add_action( 'sfs_hr_document_uploaded', [ __CLASS__, 'on_document_uploaded' ], 20, 2 );

        // Retry cron — runs every 5 min, looks up deliveries due for retry
        add_action( 'sfs_hr_webhook_retry', [ __CLASS__, 'process_retries' ] );
        if ( ! wp_next_scheduled( 'sfs_hr_webhook_retry' ) ) {
            wp_schedule_event( time() + 60, 'sfs_hr_five_min', 'sfs_hr_webhook_retry' );
        }

        add_filter( 'cron_schedules', [ __CLASS__, 'register_cron_interval' ] );
    }

    public static function register_cron_interval( array $schedules ): array {
        if ( ! isset( $schedules['sfs_hr_five_min'] ) ) {
            $schedules['sfs_hr_five_min'] = [
                'interval' => 300,
                'display'  => __( 'Every 5 minutes (SFS HR webhooks)', 'sfs-hr' ),
            ];
        }
        return $schedules;
    }

    // ── Event handlers ──────────────────────────────────────────────────────

    public static function on_employee_created( int $employee_id, array $data ): void {
        self::dispatch( 'employee.created', [
            'employee_id' => $employee_id,
            'data'        => self::sanitize_payload( $data ),
        ] );
    }

    public static function on_employee_updated( int $employee_id, array $new_data, array $old_data ): void {
        self::dispatch( 'employee.updated', [
            'employee_id' => $employee_id,
            'changes'     => self::sanitize_payload( $new_data ),
        ] );
    }

    public static function on_employee_terminated( int $employee_id, array $data ): void {
        self::dispatch( 'employee.terminated', [
            'employee_id' => $employee_id,
            'data'        => self::sanitize_payload( $data ),
        ] );
    }

    public static function on_leave_requested( int $request_id, array $data ): void {
        self::dispatch( 'leave.requested', [
            'request_id'  => $request_id,
            'employee_id' => $data['employee_id'] ?? 0,
            'leave_type'  => $data['leave_type'] ?? '',
            'start_date'  => $data['start_date'] ?? '',
            'end_date'    => $data['end_date'] ?? '',
        ] );
    }

    /**
     * Parameter order matches emitters across the codebase:
     * do_action( 'sfs_hr_leave_request_status_changed', $id, $old, $new ).
     */
    public static function on_leave_status_changed( int $request_id, string $old_status, string $new_status ): void {
        $event = match ( $new_status ) {
            'approved' => 'leave.approved',
            'rejected' => 'leave.rejected',
            default    => null,
        };
        if ( ! $event ) {
            return;
        }
        self::dispatch( $event, [
            'request_id' => $request_id,
            'status'     => $new_status,
            'old_status' => $old_status,
        ] );
    }

    public static function on_attendance_punch( int $employee_id, string $type, array $data ): void {
        $event = 'in' === $type ? 'attendance.punch_in' : 'attendance.punch_out';
        self::dispatch( $event, [
            'employee_id' => $employee_id,
            'type'        => $type,
            'time'        => $data['time'] ?? current_time( 'mysql' ),
        ] );
    }

    public static function on_payroll_run_completed( int $run_id, array $data ): void {
        self::dispatch( 'payroll.run.completed', [
            'run_id' => $run_id,
            'data'   => self::sanitize_payload( $data ),
        ] );
    }

    public static function on_payroll_run_approved( int $run_id, array $data ): void {
        self::dispatch( 'payroll.run.approved', [
            'run_id' => $run_id,
            'data'   => self::sanitize_payload( $data ),
        ] );
    }

    public static function on_resignation_status_changed( int $resignation_id, string $old_status, string $new_status ): void {
        $event = match ( $new_status ) {
            'pending', 'submitted' => 'resignation.submitted',
            'approved'             => 'resignation.approved',
            default                => null,
        };
        if ( ! $event ) {
            return;
        }
        self::dispatch( $event, [
            'resignation_id' => $resignation_id,
            'status'         => $new_status,
            'old_status'     => $old_status,
        ] );
    }

    public static function on_loan_created( int $loan_id, array $data ): void {
        self::dispatch( 'loan.created', [
            'loan_id'     => $loan_id,
            'employee_id' => $data['employee_id'] ?? 0,
            'amount'      => $data['amount'] ?? 0,
        ] );
    }

    public static function on_loan_status_changed( int $loan_id, string $old_status, string $new_status ): void {
        if ( 'active' !== $new_status ) {
            return; // Only fire on final approval
        }
        self::dispatch( 'loan.approved', [
            'loan_id'    => $loan_id,
            'status'     => $new_status,
            'old_status' => $old_status,
        ] );
    }

    public static function on_settlement_status_changed( int $settlement_id, string $old_status, string $new_status ): void {
        if ( 'approved' !== $new_status ) {
            return;
        }
        self::dispatch( 'settlement.approved', [
            'settlement_id' => $settlement_id,
            'status'        => $new_status,
        ] );
    }

    public static function on_document_uploaded( int $document_id, array $data ): void {
        self::dispatch( 'document.uploaded', [
            'document_id' => $document_id,
            'employee_id' => $data['employee_id'] ?? 0,
            'type'        => $data['document_type'] ?? '',
        ] );
    }

    // ── Core dispatch ───────────────────────────────────────────────────────

    /**
     * Dispatch an event to every active webhook subscribed to it.
     * Short-circuits fast when no webhooks match the event.
     */
    public static function dispatch( string $event, array $payload ): void {
        global $wpdb;

        if ( ! in_array( $event, self::EVENTS, true ) ) {
            return;
        }

        $table = $wpdb->prefix . 'sfs_hr_webhooks';

        // Guard: table may not exist on very old installs between migrations.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) {
            return;
        }

        $webhooks = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE status = 'active' AND FIND_IN_SET(%s, events) > 0",
            $event
        ) );

        if ( empty( $webhooks ) ) {
            return;
        }

        $payload['event']     = $event;
        $payload['timestamp'] = gmdate( 'c' );
        $payload['site_url']  = home_url();

        foreach ( $webhooks as $webhook ) {
            self::deliver( $webhook, $event, $payload, 1 );
        }
    }

    /**
     * Deliver a single webhook request with HMAC signing.
     * Logs the attempt to sfs_hr_webhook_deliveries.
     *
     * Security notes:
     *  - Re-runs the SSRF guard against the URL's current DNS resolution
     *    immediately before dispatch, to block DNS-rebinding TOCTOU attacks.
     *  - Sets `'redirection' => 0` so an attacker-controlled endpoint
     *    cannot bounce us into a private-range URL after passing the check.
     *  - Only stores response_body on 2xx responses. On errors (any non-2xx
     *    including redirects and transport errors), the body is redacted to
     *    "[redacted on failure]" to prevent blind-SSRF-to-exfil chains.
     */
    private static function deliver( object $webhook, string $event, array $payload, int $attempt ): bool {
        global $wpdb;
        $log_table = $wpdb->prefix . 'sfs_hr_webhook_deliveries';

        $json      = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
        $signature = hash_hmac( 'sha256', $json, $webhook->secret );

        // Insert pending delivery log
        $wpdb->insert( $log_table, [
            'webhook_id' => $webhook->id,
            'event'      => $event,
            'payload'    => $json,
            'attempt'    => $attempt,
            'status'     => 'pending',
            'created_at' => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%d', '%s', '%s' ] );
        $delivery_id = (int) $wpdb->insert_id;

        // Defense against DNS rebinding: validate the URL's DNS resolution
        // at dispatch time, not just at registration.
        if ( ! self::url_ssrf_safe( (string) $webhook->url ) ) {
            $wpdb->update( $log_table, [
                'response_code' => 0,
                'response_body' => '[blocked: url resolves to private or disallowed address]',
                'status'        => 'failed',
                'delivered_at'  => current_time( 'mysql' ),
                'next_retry_at' => null,
            ], [ 'id' => $delivery_id ], [ '%d', '%s', '%s', '%s', '%s' ], [ '%d' ] );
            return false;
        }

        $response = wp_remote_post( $webhook->url, [
            'timeout'     => 15,
            'blocking'    => true,
            'sslverify'   => true,
            'redirection' => 0, // Do not follow redirects — attacker could bounce to internal.
            'headers'     => [
                'Content-Type'       => 'application/json; charset=utf-8',
                'X-SFS-HR-Event'     => $event,
                'X-SFS-HR-Signature' => 'sha256=' . $signature,
                'X-SFS-HR-Delivery'  => (string) $delivery_id,
                'X-SFS-HR-Attempt'   => (string) $attempt,
                'User-Agent'         => 'SFS-HR-Webhook/' . SFS_HR_VER,
            ],
            'body' => $json,
        ] );

        if ( is_wp_error( $response ) ) {
            $wpdb->update( $log_table, [
                'response_code' => 0,
                // Error message is safe to store (comes from our HTTP client, not target).
                'response_body' => substr( $response->get_error_message(), 0, 500 ),
                'status'        => 'failed',
                'delivered_at'  => current_time( 'mysql' ),
                'next_retry_at' => self::compute_next_retry( $attempt, (int) $webhook->retry_policy ),
            ], [ 'id' => $delivery_id ], [ '%d', '%s', '%s', '%s', '%s' ], [ '%d' ] );
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $ok   = $code >= 200 && $code < 300;

        // Store body only on 2xx to avoid logging attacker-controlled internal
        // service responses (cloud metadata creds, 401 bodies, etc.).
        $body = $ok
            ? substr( (string) wp_remote_retrieve_body( $response ), 0, 1000 )
            : '[redacted on failure]';

        $wpdb->update( $log_table, [
            'response_code' => $code,
            'response_body' => $body,
            'status'        => $ok ? 'success' : 'failed',
            'delivered_at'  => current_time( 'mysql' ),
            'next_retry_at' => $ok ? null : self::compute_next_retry( $attempt, (int) $webhook->retry_policy ),
        ], [ 'id' => $delivery_id ], [ '%d', '%s', '%s', '%s', '%s' ], [ '%d' ] );

        return $ok;
    }

    /**
     * Exponential backoff: 1min, 5min, 25min, 2h05, 10h25, …
     * Cap at 24 hours. Returns null when no more retries permitted.
     */
    private static function compute_next_retry( int $attempt, int $retry_policy ): ?string {
        if ( $attempt >= $retry_policy ) {
            return null;
        }
        $seconds = (int) min( 60 * pow( 5, $attempt - 1 ), 86400 );
        return gmdate( 'Y-m-d H:i:s', time() + $seconds );
    }

    /**
     * Cron handler: retry failed deliveries whose next_retry_at has passed.
     */
    public static function process_retries(): void {
        global $wpdb;

        $log_table     = $wpdb->prefix . 'sfs_hr_webhook_deliveries';
        $webhook_table = $wpdb->prefix . 'sfs_hr_webhooks';
        $now           = current_time( 'mysql', true ); // GMT

        $due = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.*, w.url, w.secret, w.retry_policy
             FROM `{$log_table}` d
             INNER JOIN `{$webhook_table}` w ON w.id = d.webhook_id AND w.status = 'active'
             WHERE d.status = 'failed'
               AND d.next_retry_at IS NOT NULL
               AND d.next_retry_at <= %s
               AND d.attempt < w.retry_policy
             ORDER BY d.id ASC
             LIMIT 50",
            $now
        ) );

        foreach ( $due as $delivery ) {
            $payload = json_decode( (string) $delivery->payload, true );
            if ( ! is_array( $payload ) ) {
                continue;
            }
            $webhook = (object) [
                'id'           => (int) $delivery->webhook_id,
                'url'          => $delivery->url,
                'secret'       => $delivery->secret,
                'retry_policy' => (int) $delivery->retry_policy,
            ];
            // Clear next_retry_at on the old row so we don't pick it up again.
            $wpdb->update( $log_table, [ 'next_retry_at' => null ], [ 'id' => $delivery->id ], [ '%s' ], [ '%d' ] );
            self::deliver( $webhook, (string) $delivery->event, $payload, (int) $delivery->attempt + 1 );
        }
    }

    // ── Admin CRUD ──────────────────────────────────────────────────────────

    public static function get_all(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_webhooks';
        return $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY created_at DESC" ) ?: [];
    }

    public static function get( int $id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_webhooks';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ) );
        return $row ?: null;
    }

    /**
     * Create a new webhook.
     *
     * @return int|WP_Error New webhook ID on success, WP_Error on validation failure.
     */
    public static function create( string $url, array $events, int $retry_policy = 3 ) {
        global $wpdb;

        $url = esc_url_raw( trim( $url ) );
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new \WP_Error( 'invalid_url', __( 'Webhook URL is not a valid URL.', 'sfs-hr' ) );
        }

        // HTTPS-only in production
        $is_debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
        if ( ! $is_debug && stripos( $url, 'https://' ) !== 0 ) {
            return new \WP_Error( 'insecure_url', __( 'Webhook URL must use HTTPS.', 'sfs-hr' ) );
        }

        if ( ! self::url_ssrf_safe( $url ) ) {
            return new \WP_Error( 'ssrf_blocked', __( 'Webhook URL points to a private/loopback address and is not allowed.', 'sfs-hr' ) );
        }

        $valid_events = array_values( array_intersect( $events, self::EVENTS ) );
        if ( empty( $valid_events ) ) {
            return new \WP_Error( 'no_events', __( 'At least one valid event must be selected.', 'sfs-hr' ) );
        }

        $secret = wp_generate_password( 40, false );
        $now    = current_time( 'mysql' );

        $inserted = $wpdb->insert( $wpdb->prefix . 'sfs_hr_webhooks', [
            'url'          => $url,
            'secret'       => $secret,
            'events'       => implode( ',', $valid_events ),
            'status'       => 'active',
            'retry_policy' => min( 10, max( 1, $retry_policy ) ),
            'created_by'   => get_current_user_id(),
            'created_at'   => $now,
            'updated_at'   => $now,
        ], [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ] );

        if ( ! $inserted ) {
            return new \WP_Error( 'db_error', __( 'Failed to create webhook.', 'sfs-hr' ) );
        }

        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_webhooks';

        $update = [ 'updated_at' => current_time( 'mysql' ) ];
        $format = [ '%s' ];

        if ( isset( $data['url'] ) ) {
            $url = esc_url_raw( trim( (string) $data['url'] ) );
            if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! self::url_ssrf_safe( $url ) ) {
                return false;
            }
            $update['url'] = $url;
            $format[] = '%s';
        }
        if ( isset( $data['events'] ) && is_array( $data['events'] ) ) {
            $valid = array_values( array_intersect( $data['events'], self::EVENTS ) );
            if ( empty( $valid ) ) {
                return false;
            }
            $update['events'] = implode( ',', $valid );
            $format[] = '%s';
        }
        if ( isset( $data['status'] ) && in_array( $data['status'], [ 'active', 'inactive' ], true ) ) {
            $update['status'] = $data['status'];
            $format[] = '%s';
        }
        if ( isset( $data['retry_policy'] ) ) {
            $update['retry_policy'] = min( 10, max( 1, (int) $data['retry_policy'] ) );
            $format[] = '%d';
        }

        return false !== $wpdb->update( $table, $update, [ 'id' => $id ], $format, [ '%d' ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $prefix = $wpdb->prefix;
        $wpdb->delete( "{$prefix}sfs_hr_webhook_deliveries", [ 'webhook_id' => $id ], [ '%d' ] );
        return (bool) $wpdb->delete( "{$prefix}sfs_hr_webhooks", [ 'id' => $id ], [ '%d' ] );
    }

    public static function rotate_secret( int $id ): ?string {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_webhooks';
        $new   = wp_generate_password( 40, false );
        $ok    = false !== $wpdb->update( $table, [
            'secret'     => $new,
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
        return $ok ? $new : null;
    }

    public static function get_deliveries( int $webhook_id, int $limit = 50 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_webhook_deliveries';
        $limit = max( 1, min( 500, $limit ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE webhook_id = %d ORDER BY id DESC LIMIT %d",
            $webhook_id,
            $limit
        ) ) ?: [];
    }

    /**
     * Send a synthetic test payload to verify connectivity & signing.
     */
    public static function test_delivery( int $id ): array {
        $webhook = self::get( $id );
        if ( ! $webhook ) {
            return [ 'ok' => false, 'error' => __( 'Webhook not found.', 'sfs-hr' ) ];
        }

        self::deliver( $webhook, 'test.ping', [
            'event'     => 'test.ping',
            'timestamp' => gmdate( 'c' ),
            'site_url'  => home_url(),
            'message'   => 'SFS HR Suite webhook test delivery',
        ], 1 );

        // Fetch last delivery log for reporting.
        global $wpdb;
        $log   = $wpdb->prefix . 'sfs_hr_webhook_deliveries';
        $last  = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, response_code, response_body FROM `{$log}` WHERE webhook_id = %d ORDER BY id DESC LIMIT 1",
            $id
        ), ARRAY_A );

        return [
            'ok'            => ( $last && $last['status'] === 'success' ),
            'status'        => $last['status'] ?? 'failed',
            'response_code' => (int) ( $last['response_code'] ?? 0 ),
            'response_body' => (string) ( $last['response_body'] ?? '' ),
        ];
    }

    // ── Security helpers ────────────────────────────────────────────────────

    /**
     * SSRF guard — reject URLs that resolve to loopback, private networks,
     * link-local, or well-known cloud metadata endpoints.
     *
     * Metadata-endpoint blocks apply unconditionally, even in debug mode.
     * In debug mode we only allow a narrow set of developer-friendly
     * hostnames (localhost, host.docker.internal) to still fail the
     * private-range check — the block of IMDS endpoints is always in force.
     */
    private static function url_ssrf_safe( string $url ): bool {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return false;
        }

        $host  = strtolower( $parts['host'] );
        $debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

        // Hostnames blocked in ALL environments (incl. debug).
        $always_blocked_hosts = [
            '0.0.0.0',
            'metadata.google.internal',
            'metadata',
            'instance-data',
            'metadata.azure.com',
        ];
        if ( in_array( $host, $always_blocked_hosts, true ) ) {
            return false;
        }

        // In debug mode, allow a narrow set of dev hostnames to bypass the
        // private-range IP check. Metadata-IP block below still applies.
        $debug_allowlist = [ 'localhost', '127.0.0.1', '::1', 'host.docker.internal', 'docker.for.mac.localhost' ];
        $allow_private   = $debug && in_array( $host, $debug_allowlist, true );

        // Non-debug: reject the plain loopback hostname outright.
        if ( ! $debug && 'localhost' === $host ) {
            return false;
        }

        // Resolve to IP(s) and vet each.
        $ips = [];
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record( $host, DNS_A + DNS_AAAA );
            if ( is_array( $records ) ) {
                foreach ( $records as $r ) {
                    if ( ! empty( $r['ip'] ) )  { $ips[] = $r['ip']; }
                    if ( ! empty( $r['ipv6'] ) ) { $ips[] = $r['ipv6']; }
                }
            }
        }

        if ( empty( $ips ) ) {
            // Unresolvable host — fail closed.
            return false;
        }

        // Always-blocked IPs (cloud metadata services, regardless of WP_DEBUG).
        $metadata_ips = [
            '169.254.169.254', // AWS / GCP / Azure
            'fd00:ec2::254',   // AWS IMDSv2 IPv6
        ];

        foreach ( $ips as $ip ) {
            if ( in_array( $ip, $metadata_ips, true ) ) {
                return false;
            }
            $is_public = (bool) filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ( ! $is_public && ! $allow_private ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strip sensitive keys recursively before dispatch. Comparison is
     * case-insensitive and also matches when a substring of the SENSITIVE_KEYS
     * list appears inside a field name (e.g. "PassportNumber" matches "passport").
     */
    private static function sanitize_payload( array $data ): array {
        foreach ( $data as $k => $v ) {
            if ( is_string( $k ) && self::is_sensitive_key( $k ) ) {
                unset( $data[ $k ] );
                continue;
            }
            if ( is_array( $v ) ) {
                $data[ $k ] = self::sanitize_payload( $v );
            }
        }
        return $data;
    }

    private static function is_sensitive_key( string $key ): bool {
        $normalized = strtolower( str_replace( [ '-', '_', ' ' ], '', $key ) );
        foreach ( self::SENSITIVE_KEYS as $sensitive ) {
            $needle = str_replace( [ '-', '_', ' ' ], '', strtolower( $sensitive ) );
            if ( $normalized === $needle || str_contains( $normalized, $needle ) ) {
                return true;
            }
        }
        return false;
    }
}
