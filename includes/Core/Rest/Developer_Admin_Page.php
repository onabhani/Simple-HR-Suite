<?php
namespace SFS\HR\Core\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Webhooks\Webhook_Service;
use SFS\HR\Core\ApiKeys\Api_Key_Service;

/**
 * Developer_Admin_Page (M9)
 *
 * "Developer" submenu with three tabs:
 *   1. Webhooks        — CRUD + test delivery + delivery log.
 *   2. API Keys        — issue/revoke Bearer tokens.
 *   3. REST Overview   — list of registered sfs-hr/v1 routes (helpful for devs).
 *
 * @since M9
 */
class Developer_Admin_Page {

    const MENU_SLUG = 'sfs-hr-developer';

    public function hooks(): void {
        add_action( 'admin_menu', [ $this, 'menu' ], 13 );
        add_action( 'admin_post_sfs_hr_webhook_create',  [ $this, 'handle_webhook_create' ] );
        add_action( 'admin_post_sfs_hr_webhook_delete',  [ $this, 'handle_webhook_delete' ] );
        add_action( 'admin_post_sfs_hr_webhook_toggle',  [ $this, 'handle_webhook_toggle' ] );
        add_action( 'admin_post_sfs_hr_webhook_test',    [ $this, 'handle_webhook_test' ] );
        add_action( 'admin_post_sfs_hr_webhook_rotate',  [ $this, 'handle_webhook_rotate' ] );
        add_action( 'admin_post_sfs_hr_apikey_create',   [ $this, 'handle_apikey_create' ] );
        add_action( 'admin_post_sfs_hr_apikey_revoke',   [ $this, 'handle_apikey_revoke' ] );
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __( 'Developer', 'sfs-hr' ),
            __( 'Developer', 'sfs-hr' ),
            'sfs_hr.manage',
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'webhooks';
        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e( 'Developer', 'sfs-hr' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'webhooks' ], admin_url( 'admin.php' ) ) ); ?>"
                   class="nav-tab <?php echo $tab === 'webhooks' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Webhooks', 'sfs-hr' ); ?></a>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'api_keys' ], admin_url( 'admin.php' ) ) ); ?>"
                   class="nav-tab <?php echo $tab === 'api_keys' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'API Keys', 'sfs-hr' ); ?></a>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'rest' ], admin_url( 'admin.php' ) ) ); ?>"
                   class="nav-tab <?php echo $tab === 'rest' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'REST Overview', 'sfs-hr' ); ?></a>
            </nav>

            <?php $this->render_flash(); ?>

            <div style="margin-top:20px;">
                <?php
                switch ( $tab ) {
                    case 'api_keys': $this->render_api_keys(); break;
                    case 'rest':     $this->render_rest_overview(); break;
                    case 'webhooks':
                    default:         $this->render_webhooks();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render flash messages. Fresh secrets/API keys are pulled from a
     * user-scoped transient (set by the handler) rather than URL params
     * to avoid exposing credentials in access logs, referrers, and
     * browser history.
     */
    private function render_flash(): void {
        if ( ! empty( $_GET['msg'] ) ) {
            $msg = sanitize_text_field( (string) wp_unslash( $_GET['msg'] ) );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }
        if ( ! empty( $_GET['err'] ) ) {
            $err = sanitize_text_field( (string) wp_unslash( $_GET['err'] ) );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
        }

        // Read-once transients for one-time credential reveal.
        $uid = get_current_user_id();

        $secret_key = 'sfs_hr_new_webhook_secret_' . $uid;
        $secret     = get_transient( $secret_key );
        if ( $secret ) {
            delete_transient( $secret_key );
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Store this secret safely — it will not be shown again:', 'sfs-hr' ) . '</strong><br><code>' . esc_html( (string) $secret ) . '</code></p></div>';
        }

        $apikey_key = 'sfs_hr_new_apikey_' . $uid;
        $apikey     = get_transient( $apikey_key );
        if ( $apikey ) {
            delete_transient( $apikey_key );
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Your new API key — copy it now, it will not be shown again:', 'sfs-hr' ) . '</strong><br><code>' . esc_html( (string) $apikey ) . '</code></p></div>';
        }
    }

    /* ───────── Webhooks tab ───────── */

    private function render_webhooks(): void {
        $webhooks = Webhook_Service::get_all();
        ?>
        <h2><?php esc_html_e( 'Outgoing Webhooks', 'sfs-hr' ); ?></h2>
        <p><?php esc_html_e( 'Register URLs that will receive JSON event notifications with HMAC-SHA256 signatures in the X-SFS-HR-Signature header.', 'sfs-hr' ); ?></p>

        <h3><?php esc_html_e( 'Create Webhook', 'sfs-hr' ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
            <input type="hidden" name="action" value="sfs_hr_webhook_create" />
            <?php wp_nonce_field( 'sfs_hr_webhook_create', '_sfs_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="url"><?php esc_html_e( 'URL', 'sfs-hr' ); ?></label></th>
                    <td><input type="url" id="url" name="url" class="regular-text" required placeholder="https://example.com/hook" /></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Events', 'sfs-hr' ); ?></label></th>
                    <td>
                        <?php foreach ( Webhook_Service::EVENTS as $event ) : ?>
                            <label style="display:inline-block;min-width:230px;margin:3px 0;">
                                <input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>" />
                                <code><?php echo esc_html( $event ); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="retry_policy"><?php esc_html_e( 'Max Retries', 'sfs-hr' ); ?></label></th>
                    <td><input type="number" id="retry_policy" name="retry_policy" value="3" min="1" max="10" style="width:80px;" /></td>
                </tr>
            </table>
            <?php submit_button( __( 'Create Webhook', 'sfs-hr' ) ); ?>
        </form>

        <h3><?php esc_html_e( 'Registered Webhooks', 'sfs-hr' ); ?></h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'URL', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Events', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Retries', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $webhooks ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No webhooks configured.', 'sfs-hr' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $webhooks as $wh ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $wh->url ); ?></code></td>
                            <td>
                                <?php
                                $events = array_filter( explode( ',', (string) $wh->events ) );
                                foreach ( $events as $e ) {
                                    echo '<code style="display:inline-block;margin:1px 2px;">' . esc_html( $e ) . '</code> ';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( $wh->status ); ?></td>
                            <td><?php echo (int) $wh->retry_policy; ?></td>
                            <td style="white-space:nowrap;">
                                <?php echo $this->webhook_action_button( (int) $wh->id, 'test', __( 'Test', 'sfs-hr' ) ); ?>
                                <?php echo $this->webhook_action_button( (int) $wh->id, 'toggle', $wh->status === 'active' ? __( 'Disable', 'sfs-hr' ) : __( 'Enable', 'sfs-hr' ) ); ?>
                                <?php echo $this->webhook_action_button( (int) $wh->id, 'rotate', __( 'Rotate Secret', 'sfs-hr' ) ); ?>
                                <?php echo $this->webhook_action_button( (int) $wh->id, 'delete', __( 'Delete', 'sfs-hr' ), 'button-link-delete' ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function webhook_action_button( int $id, string $action, string $label, string $class = 'button button-small' ): string {
        $url = admin_url( 'admin-post.php' );
        $nonce = wp_create_nonce( 'sfs_hr_webhook_' . $action . '_' . $id );
        return sprintf(
            '<form method="post" action="%s" style="display:inline-block;margin:0 2px;"><input type="hidden" name="action" value="sfs_hr_webhook_%s" /><input type="hidden" name="id" value="%d" /><input type="hidden" name="_sfs_nonce" value="%s" /><button type="submit" class="%s">%s</button></form>',
            esc_url( $url ),
            esc_attr( $action ),
            $id,
            esc_attr( $nonce ),
            esc_attr( $class ),
            esc_html( $label )
        );
    }

    /* ───────── API Keys tab ───────── */

    private function render_api_keys(): void {
        $keys = Api_Key_Service::list_all();
        ?>
        <h2><?php esc_html_e( 'API Keys', 'sfs-hr' ); ?></h2>
        <p><?php esc_html_e( 'Bearer tokens for external applications. Send as "Authorization: Bearer shr_…". Only the creating user\'s permissions apply to requests authenticated by the key.', 'sfs-hr' ); ?></p>

        <h3><?php esc_html_e( 'Issue New Key', 'sfs-hr' ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
            <input type="hidden" name="action" value="sfs_hr_apikey_create" />
            <?php wp_nonce_field( 'sfs_hr_apikey_create', '_sfs_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="label"><?php esc_html_e( 'Label', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" id="label" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Payroll sync integration', 'sfs-hr' ); ?>" required /></td>
                </tr>
                <tr>
                    <th><label for="user_id"><?php esc_html_e( 'On behalf of user', 'sfs-hr' ); ?></label></th>
                    <td>
                        <?php
                        wp_dropdown_users( [
                            'name'             => 'user_id',
                            'id'               => 'user_id',
                            'selected'         => get_current_user_id(),
                            'include_selected' => true,
                        ] );
                        ?>
                        <p class="description"><?php esc_html_e( 'The key inherits this user\'s capabilities.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="expires_at"><?php esc_html_e( 'Expires', 'sfs-hr' ); ?></label></th>
                    <td>
                        <input type="date" id="expires_at" name="expires_at" />
                        <p class="description"><?php esc_html_e( 'Optional. Leave blank for no expiration.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Issue Key', 'sfs-hr' ) ); ?>
        </form>

        <h3><?php esc_html_e( 'Active Keys', 'sfs-hr' ); ?></h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Label', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'User', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Prefix', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Last Used', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Expires', 'sfs-hr' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $keys ) ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No API keys issued.', 'sfs-hr' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $keys as $k ) :
                        $user = get_userdata( (int) $k['user_id'] );
                    ?>
                        <tr>
                            <td><?php echo esc_html( $k['label'] ); ?></td>
                            <td><?php echo $user ? esc_html( $user->display_name ) : '—'; ?></td>
                            <td><code><?php echo esc_html( $k['key_prefix'] ); ?>…</code></td>
                            <td><?php echo esc_html( $k['status'] ); ?></td>
                            <td><?php echo esc_html( $k['last_used_at'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $k['expires_at'] ?: __( 'Never', 'sfs-hr' ) ); ?></td>
                            <td>
                                <?php if ( 'active' === $k['status'] ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="sfs_hr_apikey_revoke" />
                                        <input type="hidden" name="id" value="<?php echo (int) $k['id']; ?>" />
                                        <?php wp_nonce_field( 'sfs_hr_apikey_revoke_' . (int) $k['id'], '_sfs_nonce' ); ?>
                                        <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Revoke', 'sfs-hr' ); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /* ───────── REST Overview tab ───────── */

    private function render_rest_overview(): void {
        $server = rest_get_server();
        $routes = method_exists( $server, 'get_routes' ) ? $server->get_routes() : [];
        $our    = [];
        foreach ( $routes as $path => $handlers ) {
            if ( strpos( $path, '/sfs-hr/v1' ) === 0 ) {
                $our[ $path ] = $handlers;
            }
        }
        ksort( $our );
        ?>
        <h2><?php esc_html_e( 'Registered Endpoints', 'sfs-hr' ); ?></h2>
        <p><?php esc_html_e( 'All endpoints below are available under the sfs-hr/v1 namespace.', 'sfs-hr' ); ?></p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Methods', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Path', 'sfs-hr' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $our as $path => $handlers ) : ?>
                    <?php
                    $methods = [];
                    foreach ( $handlers as $h ) {
                        foreach ( array_keys( (array) ( $h['methods'] ?? [] ) ) as $m ) {
                            $methods[ $m ] = true;
                        }
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html( implode( ', ', array_keys( $methods ) ) ); ?></td>
                        <td><code><?php echo esc_html( $path ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ───────── admin_post handlers ───────── */

    public function handle_webhook_create(): void {
        $this->require_cap();
        check_admin_referer( 'sfs_hr_webhook_create', '_sfs_nonce' );

        $url          = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '';
        $events       = isset( $_POST['events'] ) && is_array( $_POST['events'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['events'] ) )
            : [];
        $retry_policy = isset( $_POST['retry_policy'] ) ? (int) $_POST['retry_policy'] : 3;

        $result = Webhook_Service::create( $url, $events, $retry_policy );
        if ( is_wp_error( $result ) ) {
            $this->redirect( 'webhooks', [ 'err' => $result->get_error_message() ] );
        }

        // Stash secret in a user-scoped transient (60s). Transient is
        // read+deleted by render_flash() so the secret never touches the URL.
        $wh = Webhook_Service::get( $result );
        if ( $wh && ! empty( $wh->secret ) ) {
            set_transient( 'sfs_hr_new_webhook_secret_' . get_current_user_id(), $wh->secret, 60 );
        }
        $this->redirect( 'webhooks', [
            'msg' => __( 'Webhook created.', 'sfs-hr' ),
        ] );
    }

    public function handle_webhook_delete(): void {
        $this->require_cap();
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'sfs_hr_webhook_delete_' . $id, '_sfs_nonce' );
        Webhook_Service::delete( $id );
        $this->redirect( 'webhooks', [ 'msg' => __( 'Webhook deleted.', 'sfs-hr' ) ] );
    }

    public function handle_webhook_toggle(): void {
        $this->require_cap();
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'sfs_hr_webhook_toggle_' . $id, '_sfs_nonce' );
        $wh = Webhook_Service::get( $id );
        if ( $wh ) {
            Webhook_Service::update( $id, [ 'status' => $wh->status === 'active' ? 'inactive' : 'active' ] );
        }
        $this->redirect( 'webhooks', [ 'msg' => __( 'Status toggled.', 'sfs-hr' ) ] );
    }

    public function handle_webhook_test(): void {
        $this->require_cap();
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'sfs_hr_webhook_test_' . $id, '_sfs_nonce' );
        $result = Webhook_Service::test_delivery( $id );
        $msg    = $result['ok']
            ? sprintf( __( 'Test delivery succeeded (HTTP %d).', 'sfs-hr' ), (int) ( $result['response_code'] ?? 0 ) )
            : sprintf( __( 'Test delivery failed (HTTP %d).', 'sfs-hr' ), (int) ( $result['response_code'] ?? 0 ) );
        $this->redirect( 'webhooks', [ $result['ok'] ? 'msg' : 'err' => $msg ] );
    }

    public function handle_webhook_rotate(): void {
        $this->require_cap();
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'sfs_hr_webhook_rotate_' . $id, '_sfs_nonce' );
        $secret = Webhook_Service::rotate_secret( $id );
        if ( null === $secret ) {
            $this->redirect( 'webhooks', [ 'err' => __( 'Webhook not found.', 'sfs-hr' ) ] );
        }
        set_transient( 'sfs_hr_new_webhook_secret_' . get_current_user_id(), $secret, 60 );
        $this->redirect( 'webhooks', [ 'msg' => __( 'Secret rotated.', 'sfs-hr' ) ] );
    }

    public function handle_apikey_create(): void {
        $this->require_cap();
        check_admin_referer( 'sfs_hr_apikey_create', '_sfs_nonce' );

        $label      = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '';
        $user_id    = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : get_current_user_id();
        $expires_at = ! empty( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['expires_at'] ) ) : null;
        if ( $expires_at && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires_at ) ) {
            $expires_at .= ' 23:59:59';
        } else {
            $expires_at = null;
        }

        $result = Api_Key_Service::create( $user_id, $label, [], $expires_at );
        if ( isset( $result['error'] ) ) {
            $this->redirect( 'api_keys', [ 'err' => $result['error'] ] );
        }
        // Stash the plaintext key in a user-scoped transient (60s) — never
        // in the URL, so it can't leak via logs, referrer, or browser history.
        set_transient( 'sfs_hr_new_apikey_' . get_current_user_id(), $result['key'], 60 );
        $this->redirect( 'api_keys', [
            'msg' => __( 'API key issued.', 'sfs-hr' ),
        ] );
    }

    public function handle_apikey_revoke(): void {
        $this->require_cap();
        $id = (int) ( $_POST['id'] ?? 0 );
        check_admin_referer( 'sfs_hr_apikey_revoke_' . $id, '_sfs_nonce' );
        Api_Key_Service::revoke( $id );
        $this->redirect( 'api_keys', [ 'msg' => __( 'API key revoked.', 'sfs-hr' ) ] );
    }

    /* ───────── helpers ───────── */

    private function require_cap(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }
    }

    private function redirect( string $tab, array $args = [] ): void {
        $args = array_merge( [ 'page' => self::MENU_SLUG, 'tab' => $tab ], $args );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }
}
