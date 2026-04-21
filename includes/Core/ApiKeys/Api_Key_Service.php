<?php
namespace SFS\HR\Core\ApiKeys;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Api_Key_Service
 *
 * M9.2 — API key issuance, storage, and authentication.
 *
 * Keys are stored as hashes (never plaintext) plus an indexable
 * `key_prefix` (first 12 chars) for lookup. On creation we return the
 * full plaintext one-time — clients must store it themselves.
 *
 * Key format: "shr_" + 44 base62 chars (256-bit entropy).
 *
 * Authentication: clients send `Authorization: Bearer shr_…`.
 * The REST middleware calls Api_Key_Service::authenticate() during
 * `rest_authentication_errors`, which sets the current WP user on
 * success so permission_callbacks based on current_user_can() continue
 * to work unchanged.
 *
 * @since M9
 */
class Api_Key_Service {

    /** Key body length (without the "shr_" prefix). */
    private const KEY_LEN = 44;

    /** Characters used when generating a fresh key body. */
    private const KEY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    /** Public prefix shown to admins and used for lookups. */
    private const PREFIX_SIZE = 12;

    // ── Creation ────────────────────────────────────────────────────────────

    /**
     * Issue a new API key for $user_id with the given label and scopes.
     *
     * @return array { id:int, key:string, prefix:string } The plaintext key
     *               is returned ONCE — it is never recoverable after creation.
     */
    public static function create( int $user_id, string $label, array $scopes = [], ?string $expires_at = null ): array {
        global $wpdb;

        if ( $user_id <= 0 ) {
            return [ 'error' => __( 'Invalid user.', 'sfs-hr' ) ];
        }
        $label = sanitize_text_field( $label );
        if ( '' === $label ) {
            $label = __( 'API key', 'sfs-hr' );
        }

        $body   = self::generate_random( self::KEY_LEN );
        $key    = 'shr_' . $body;
        $prefix = substr( $key, 0, self::PREFIX_SIZE );
        $hash   = self::hash_key( $key );

        $now = current_time( 'mysql' );

        $ok = $wpdb->insert( $wpdb->prefix . 'sfs_hr_api_keys', [
            'user_id'     => $user_id,
            'label'       => $label,
            'key_prefix'  => $prefix,
            'key_hash'    => $hash,
            'scopes'      => wp_json_encode( array_values( array_unique( array_map( 'strval', $scopes ) ) ) ),
            'status'      => 'active',
            'expires_at'  => $expires_at,
            'created_at'  => $now,
            'updated_at'  => $now,
        ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );

        if ( ! $ok ) {
            return [ 'error' => __( 'Failed to persist API key.', 'sfs-hr' ) ];
        }

        return [
            'id'     => (int) $wpdb->insert_id,
            'key'    => $key,
            'prefix' => $prefix,
        ];
    }

    // ── Authentication ──────────────────────────────────────────────────────

    /**
     * Try to authenticate a REST request by Bearer token.
     *
     * Hooked into `rest_authentication_errors`. Returns $previous_error
     * unchanged when no Bearer token is present (so WP cookie auth still
     * works), or null (authenticated successfully) after signing in the
     * associated user, or a WP_Error when an invalid key is supplied.
     *
     * @param mixed $previous_error Current auth state passed through by WP.
     * @return mixed
     */
    public static function authenticate( $previous_error ) {
        if ( ! empty( $previous_error ) && ! is_wp_error( $previous_error ) ) {
            return $previous_error; // already authenticated by cookie
        }

        $token = self::extract_bearer_token();
        if ( null === $token ) {
            return $previous_error; // no Bearer token — leave flow alone
        }

        // Token was present — from this point we succeed or fail hard.
        $record = self::find_key_record( $token );
        if ( ! $record ) {
            return new \WP_Error( 'invalid_api_key', __( 'Invalid API key.', 'sfs-hr' ), [ 'status' => 401 ] );
        }

        if ( 'active' !== $record->status ) {
            return new \WP_Error( 'revoked_api_key', __( 'API key has been revoked.', 'sfs-hr' ), [ 'status' => 401 ] );
        }

        if ( ! empty( $record->expires_at ) && strtotime( $record->expires_at ) < time() ) {
            return new \WP_Error( 'expired_api_key', __( 'API key has expired.', 'sfs-hr' ), [ 'status' => 401 ] );
        }

        // Sign in the associated WP user for the remainder of this request.
        wp_set_current_user( (int) $record->user_id );

        // Record usage (fire-and-forget; ignore failures).
        self::touch_last_used( (int) $record->id );

        return true; // signals "authenticated"
    }

    // ── Admin CRUD ──────────────────────────────────────────────────────────

    public static function list_for_user( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_api_keys';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, label, key_prefix, scopes, status, last_used_at, last_ip, expires_at, created_at
             FROM `{$table}` WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ), ARRAY_A ) ?: [];
    }

    public static function list_all(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_api_keys';
        return $wpdb->get_results(
            "SELECT id, user_id, label, key_prefix, scopes, status, last_used_at, last_ip, expires_at, created_at
             FROM `{$table}` ORDER BY created_at DESC"
        , ARRAY_A ) ?: [];
    }

    public static function revoke( int $id ): bool {
        global $wpdb;
        return false !== $wpdb->update(
            $wpdb->prefix . 'sfs_hr_api_keys',
            [ 'status' => 'revoked', 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . 'sfs_hr_api_keys',
            [ 'id' => $id ],
            [ '%d' ]
        );
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Pull the Bearer token from the Authorization header, if any.
     * Returns the raw token (with "shr_" prefix) or null when absent.
     */
    private static function extract_bearer_token(): ?string {
        $auth = '';
        if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif ( function_exists( 'apache_request_headers' ) ) {
            $h = apache_request_headers();
            if ( ! empty( $h['Authorization'] ) ) {
                $auth = (string) $h['Authorization'];
            }
        }

        if ( '' === $auth ) {
            return null;
        }
        // RFC 7235: auth-scheme is case-insensitive ("Bearer" / "bearer" / "BEARER").
        if ( ! preg_match( '/Bearer\s+(shr_[A-Za-z0-9]{' . self::KEY_LEN . '})/i', $auth, $m ) ) {
            return null;
        }
        return $m[1];
    }

    /**
     * Resolve a plaintext key to its DB record via prefix lookup + timing-safe
     * hash comparison. Returns null on no match.
     */
    private static function find_key_record( string $plaintext ): ?object {
        global $wpdb;

        $prefix = substr( $plaintext, 0, self::PREFIX_SIZE );
        $row    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_api_keys WHERE key_prefix = %s LIMIT 1",
            $prefix
        ) );
        if ( ! $row ) {
            // Spend a hash cycle to equalize timing regardless of match.
            self::hash_key( 'shr_timingsafe' );
            return null;
        }

        if ( ! hash_equals( (string) $row->key_hash, self::hash_key( $plaintext ) ) ) {
            return null;
        }
        return $row;
    }

    /**
     * SHA-256 with the WP AUTH_KEY salt. Not bcrypt — these are high-entropy
     * random tokens, so brute-force is not a concern; SHA-256 is plenty and
     * keeps lookups fast at scale.
     */
    private static function hash_key( string $plaintext ): string {
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'sfs_hr_default_salt';
        return hash_hmac( 'sha256', $plaintext, $salt );
    }

    private static function touch_last_used( int $id ): void {
        global $wpdb;
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? substr( (string) $_SERVER['REMOTE_ADDR'], 0, 45 ) : null;
        $wpdb->update(
            $wpdb->prefix . 'sfs_hr_api_keys',
            [ 'last_used_at' => current_time( 'mysql' ), 'last_ip' => $ip ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Cryptographically strong random string from the key alphabet.
     */
    private static function generate_random( int $length ): string {
        $alphabet = self::KEY_ALPHABET;
        $max      = strlen( $alphabet ) - 1;
        $out      = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $out .= $alphabet[ random_int( 0, $max ) ];
        }
        return $out;
    }
}
