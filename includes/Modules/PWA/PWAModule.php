<?php
namespace SFS\HR\Modules\PWA;

if (!defined('ABSPATH')) { exit; }

/**
 * PWA Module
 * Progressive Web App infrastructure for mobile attendance
 * Provides: service worker, manifest, offline sync, install prompt
 * Version: 0.1.1
 * Author: Omar Alnabhani (hdqah.com)
 */
class PWAModule {

    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function hooks(): void {
        // Register PWA assets (manifest, meta tags)
        add_action('wp_head', [$this, 'output_pwa_meta']);
        add_action('wp_enqueue_scripts', [$this, 'register_pwa_scripts']);

        // Register REST route for PWA manifest
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Add rewrite rule for service worker
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'serve_service_worker']);

        // Add PWA install prompt on admin pages (My Profile)
        add_action('admin_footer', [$this, 'output_pwa_install_prompt']);

        // Add PWA install prompt to frontend attendance pages
        add_action('wp_footer', [$this, 'output_frontend_pwa_prompt']);
    }

    /**
     * Output PWA meta tags in head
     */
    public function output_pwa_meta(): void {
        if (!is_user_logged_in()) {
            return;
        }

        $manifest_url = rest_url('sfs-hr/v1/pwa/manifest.json');
        ?>
        <link rel="manifest" href="<?php echo esc_url($manifest_url); ?>">
        <meta name="theme-color" content="#2271b1">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="HR Suite">
        <link rel="apple-touch-icon" href="<?php echo esc_url(SFS_HR_URL . 'assets/pwa/icon-192.svg'); ?>">
        <?php
    }

    /**
     * Register PWA scripts
     */
    public function register_pwa_scripts(): void {
        if (!is_user_logged_in()) {
            return;
        }

        wp_enqueue_script(
            'sfs-hr-pwa',
            SFS_HR_URL . 'assets/pwa/pwa-app.js',
            [],
            SFS_HR_VER,
            true
        );

        wp_localize_script('sfs-hr-pwa', 'sfsHrPwa', [
            'serviceWorkerUrl' => home_url('/sfs-hr-sw.js'),
            'restUrl' => rest_url('sfs-hr/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'isLoggedIn' => is_user_logged_in(),
            'i18n' => [
                'installPrompt' => __('Install HR Suite app for quick access to punch in/out', 'sfs-hr'),
                'install' => __('Install', 'sfs-hr'),
                'notNow' => __('Not Now', 'sfs-hr'),
                'punchInSuccess' => __('Punched in successfully!', 'sfs-hr'),
                'punchOutSuccess' => __('Punched out successfully!', 'sfs-hr'),
                'breakStartSuccess' => __('Break started!', 'sfs-hr'),
                'breakEndSuccess' => __('Break ended!', 'sfs-hr'),
                'offlineQueued' => __('You are offline. Punch will be synced when connection is restored.', 'sfs-hr'),
                'error' => __('An error occurred. Please try again.', 'sfs-hr'),
            ],
        ]);
    }

    /**
     * Register REST routes for PWA
     */
    public function register_rest_routes(): void {
        // Serve manifest.json dynamically
        register_rest_route('sfs-hr/v1', '/pwa/manifest.json', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_manifest'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * REST: Get dynamic manifest
     */
    public function rest_get_manifest(): \WP_REST_Response {
        $manifest = [
            'name' => get_bloginfo('name') . ' - HR Suite',
            'short_name' => 'HR Suite',
            'description' => __('Employee self-service: punch in/out, leave requests, and more', 'sfs-hr'),
            'start_url' => home_url('/?pwa=1'),
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#2271b1',
            'orientation' => 'portrait',
            'icons' => [
                [
                    'src' => SFS_HR_URL . 'assets/pwa/icon-192.svg',
                    'sizes' => '192x192',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => SFS_HR_URL . 'assets/pwa/icon-192.svg',
                    'sizes' => '512x512',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any maskable',
                ],
            ],
            'shortcuts' => [
                [
                    'name' => __('Punch In/Out', 'sfs-hr'),
                    'short_name' => __('Punch', 'sfs-hr'),
                    'url' => home_url('/?pwa=1&action=punch'),
                ],
                [
                    'name' => __('My Profile', 'sfs-hr'),
                    'short_name' => __('Profile', 'sfs-hr'),
                    'url' => admin_url('admin.php?page=sfs-hr-my-profile'),
                ],
            ],
        ];

        $response = new \WP_REST_Response($manifest, 200);
        $response->header('Content-Type', 'application/manifest+json');
        return $response;
    }

    /**
     * Add rewrite rules for service worker
     */
    public function add_rewrite_rules(): void {
        add_rewrite_rule('^sfs-hr-sw\.js$', 'index.php?sfs_hr_sw=1', 'top');
    }

    /**
     * Add query vars
     */
    public function add_query_vars(array $vars): array {
        $vars[] = 'sfs_hr_sw';
        return $vars;
    }

    /**
     * Serve service worker
     */
    public function serve_service_worker(): void {
        if (!get_query_var('sfs_hr_sw')) {
            return;
        }

        header('Content-Type: application/javascript');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache');

        $sw_path = SFS_HR_DIR . 'assets/pwa/service-worker.js';
        if (file_exists($sw_path)) {
            readfile($sw_path);
        }
        exit;
    }

    /**
     * Output PWA install prompt on admin pages
     */
    public function output_pwa_install_prompt(): void {
        if (!is_user_logged_in()) {
            return;
        }

        // Only show on My Profile page
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'sfs-hr-my-profile') === false) {
            return;
        }

        ?>
        <div id="sfs-hr-pwa-install-banner" style="display:none; position:fixed; bottom:20px; left:20px; right:20px; max-width:400px; margin:0 auto; background:#fff; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.15); padding:16px; z-index:9999;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:48px; height:48px; background:linear-gradient(135deg, #2271b1 0%, #135e96 100%); border-radius:12px; display:flex; align-items:center; justify-content:center;">
                    <span style="color:#fff; font-size:24px; font-weight:bold;">HR</span>
                </div>
                <div style="flex:1;">
                    <strong style="display:block; margin-bottom:4px;"><?php esc_html_e('Install HR Suite', 'sfs-hr'); ?></strong>
                    <span style="font-size:13px; color:#666;"><?php esc_html_e('Quick access to punch in/out', 'sfs-hr'); ?></span>
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px;">
                <button type="button" id="pwa-install-btn" class="button button-primary" style="flex:1;"><?php esc_html_e('Install', 'sfs-hr'); ?></button>
                <button type="button" id="pwa-dismiss-btn" class="button" style="flex:1;"><?php esc_html_e('Not Now', 'sfs-hr'); ?></button>
            </div>
        </div>

        <script>
        (function() {
            let deferredPrompt;

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;

                // Check if already dismissed
                if (localStorage.getItem('sfs_hr_pwa_dismissed')) {
                    return;
                }

                // Show install banner
                document.getElementById('sfs-hr-pwa-install-banner').style.display = 'block';
            });

            document.getElementById('pwa-install-btn')?.addEventListener('click', async () => {
                if (!deferredPrompt) return;

                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;

                if (outcome === 'accepted') {
                    console.log('PWA installed');
                }

                deferredPrompt = null;
                document.getElementById('sfs-hr-pwa-install-banner').style.display = 'none';
            });

            document.getElementById('pwa-dismiss-btn')?.addEventListener('click', () => {
                localStorage.setItem('sfs_hr_pwa_dismissed', '1');
                document.getElementById('sfs-hr-pwa-install-banner').style.display = 'none';
            });
        })();
        </script>
        <?php
    }

    /**
     * Output PWA install prompt on frontend attendance pages
     * Shows on pages with [sfs_hr_my_profile] or attendance widgets
     */
    public function output_frontend_pwa_prompt(): void {
        if (!is_user_logged_in()) {
            return;
        }

        // Only show on frontend pages (not admin)
        if (is_admin()) {
            return;
        }

        // Check if this page has attendance content
        global $post;
        if (!$post) {
            return;
        }

        // Look for attendance-related shortcodes in content
        $has_attendance = (
            has_shortcode($post->post_content, 'sfs_hr_my_profile') ||
            has_shortcode($post->post_content, 'sfs_hr_attendance_widget') ||
            has_shortcode($post->post_content, 'sfs_hr_kiosk') ||
            isset($_GET['sfs_hr_tab']) && $_GET['sfs_hr_tab'] === 'attendance'
        );

        if (!$has_attendance) {
            return;
        }

        ?>
        <div id="sfs-hr-pwa-frontend-banner" style="display:none; position:fixed; bottom:20px; left:20px; right:20px; max-width:400px; margin:0 auto; background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,0.18); padding:20px; z-index:999999; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
            <div style="display:flex; align-items:center; gap:14px;">
                <div style="width:52px; height:52px; background:linear-gradient(135deg, #0f4c5c 0%, #135e96 100%); border-radius:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                </div>
                <div style="flex:1;">
                    <strong style="display:block; margin-bottom:3px; font-size:15px; color:#111827;"><?php esc_html_e('Install HR Suite', 'sfs-hr'); ?></strong>
                    <span style="font-size:13px; color:#6b7280; line-height:1.4;"><?php esc_html_e('Add to home screen for quick punch in/out access', 'sfs-hr'); ?></span>
                </div>
            </div>
            <div style="display:flex; gap:10px; margin-top:16px;">
                <button type="button" id="pwa-frontend-install-btn" style="flex:1; padding:12px 16px; background:linear-gradient(135deg, #0f4c5c 0%, #135e96 100%); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer;"><?php esc_html_e('Install App', 'sfs-hr'); ?></button>
                <button type="button" id="pwa-frontend-dismiss-btn" style="flex:1; padding:12px 16px; background:#f3f4f6; color:#374151; border:none; border-radius:10px; font-size:14px; font-weight:500; cursor:pointer;"><?php esc_html_e('Not Now', 'sfs-hr'); ?></button>
            </div>
        </div>

        <script>
        (function() {
            let deferredPrompt;

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;

                // Check if already dismissed
                if (localStorage.getItem('sfs_hr_pwa_dismissed')) {
                    return;
                }

                // Show install banner after a short delay
                setTimeout(() => {
                    const banner = document.getElementById('sfs-hr-pwa-frontend-banner');
                    if (banner) {
                        banner.style.display = 'block';
                        banner.style.animation = 'sfs-pwa-slide-up 0.3s ease-out';
                    }
                }, 2000);
            });

            document.getElementById('pwa-frontend-install-btn')?.addEventListener('click', async () => {
                if (!deferredPrompt) return;

                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;

                deferredPrompt = null;
                document.getElementById('sfs-hr-pwa-frontend-banner').style.display = 'none';
            });

            document.getElementById('pwa-frontend-dismiss-btn')?.addEventListener('click', () => {
                localStorage.setItem('sfs_hr_pwa_dismissed', '1');
                document.getElementById('sfs-hr-pwa-frontend-banner').style.display = 'none';
            });

            // Add slide-up animation
            if (!document.getElementById('sfs-pwa-animation-style')) {
                const style = document.createElement('style');
                style.id = 'sfs-pwa-animation-style';
                style.textContent = '@keyframes sfs-pwa-slide-up { from { transform: translateY(100px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }';
                document.head.appendChild(style);
            }
        })();
        </script>
        <?php
    }
}
