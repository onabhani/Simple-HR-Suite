<?php
namespace SFS\HR\Modules\PWA;

if (!defined('ABSPATH')) { exit; }

/**
 * PWA Module
 * Progressive Web App for mobile punch in/out
 * Version: 0.1.0
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
        // Register PWA assets
        add_action('wp_head', [$this, 'output_pwa_meta']);
        add_action('wp_enqueue_scripts', [$this, 'register_pwa_scripts']);

        // Register shortcode for mobile punch interface
        add_shortcode('sfs_hr_mobile_punch', [$this, 'render_mobile_punch_shortcode']);

        // Register REST route for PWA manifest
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Add rewrite rule for service worker
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'serve_service_worker']);

        // Add PWA install prompt on admin pages
        add_action('admin_footer', [$this, 'output_pwa_install_prompt']);
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
        <link rel="apple-touch-icon" href="<?php echo esc_url(SFS_HR_URL . 'assets/pwa/icon-192.png'); ?>">
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
                    'src' => SFS_HR_URL . 'assets/pwa/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => SFS_HR_URL . 'assets/pwa/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
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
     * Render mobile punch shortcode
     */
    public function render_mobile_punch_shortcode(array $atts = []): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to use the punch clock.', 'sfs-hr') . '</p>';
        }

        // Get current user's employee record
        global $wpdb;
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $employee = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$emp_table} WHERE user_id = %d AND status = 'active'",
            get_current_user_id()
        ));

        if (!$employee) {
            return '<p>' . esc_html__('You are not linked to an active employee record.', 'sfs-hr') . '</p>';
        }

        // Get today's status
        $sessions_table = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $punches_table = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $today = wp_date('Y-m-d');

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sessions_table} WHERE employee_id = %d AND work_date = %s",
            $employee->id,
            $today
        ));

        // Get last punch to determine current state
        $last_punch = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$punches_table}
             WHERE employee_id = %d AND DATE(punch_time) = %s
             ORDER BY punch_time DESC LIMIT 1",
            $employee->id,
            $today
        ));

        $current_status = 'not_clocked_in';
        $can_punch_in = true;
        $can_punch_out = false;
        $can_start_break = false;
        $can_end_break = false;

        if ($last_punch) {
            switch ($last_punch->punch_type) {
                case 'in':
                case 'break_end':
                    $current_status = 'clocked_in';
                    $can_punch_in = false;
                    $can_punch_out = true;
                    $can_start_break = true;
                    break;
                case 'break_start':
                    $current_status = 'on_break';
                    $can_punch_in = false;
                    $can_punch_out = false;
                    $can_end_break = true;
                    break;
                case 'out':
                    $current_status = 'clocked_out';
                    $can_punch_in = true;
                    $can_punch_out = false;
                    break;
            }
        }

        $name = trim($employee->first_name . ' ' . $employee->last_name);

        ob_start();
        ?>
        <div id="sfs-hr-mobile-punch" class="sfs-hr-mobile-punch-app" data-employee-id="<?php echo (int)$employee->id; ?>">
            <style>
                .sfs-hr-mobile-punch-app {
                    max-width: 400px;
                    margin: 0 auto;
                    padding: 20px;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                }
                .sfs-hr-punch-header {
                    text-align: center;
                    margin-bottom: 30px;
                }
                .sfs-hr-punch-avatar {
                    width: 80px;
                    height: 80px;
                    border-radius: 50%;
                    background: linear-gradient(135deg, #2271b1 0%, #135e96 100%);
                    color: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 32px;
                    font-weight: 600;
                    margin: 0 auto 15px;
                }
                .sfs-hr-punch-name {
                    font-size: 18px;
                    font-weight: 600;
                    margin: 0;
                }
                .sfs-hr-punch-time {
                    font-size: 48px;
                    font-weight: 300;
                    text-align: center;
                    margin: 20px 0;
                    font-variant-numeric: tabular-nums;
                }
                .sfs-hr-punch-date {
                    text-align: center;
                    color: #666;
                    margin-bottom: 30px;
                }
                .sfs-hr-punch-status {
                    text-align: center;
                    margin-bottom: 30px;
                }
                .sfs-hr-punch-status-badge {
                    display: inline-block;
                    padding: 8px 20px;
                    border-radius: 20px;
                    font-size: 14px;
                    font-weight: 600;
                }
                .sfs-hr-punch-status-badge.not_clocked_in {
                    background: #f0f0f1;
                    color: #666;
                }
                .sfs-hr-punch-status-badge.clocked_in {
                    background: #d1fae5;
                    color: #059669;
                }
                .sfs-hr-punch-status-badge.on_break {
                    background: #fef3c7;
                    color: #d97706;
                }
                .sfs-hr-punch-status-badge.clocked_out {
                    background: #fee2e2;
                    color: #dc2626;
                }
                .sfs-hr-punch-buttons {
                    display: grid;
                    gap: 12px;
                }
                .sfs-hr-punch-btn {
                    display: block;
                    width: 100%;
                    padding: 16px;
                    border: none;
                    border-radius: 12px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.2s;
                }
                .sfs-hr-punch-btn:disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }
                .sfs-hr-punch-btn:not(:disabled):hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                }
                .sfs-hr-punch-btn:not(:disabled):active {
                    transform: translateY(0);
                }
                .sfs-hr-punch-btn.punch-in {
                    background: linear-gradient(135deg, #059669 0%, #047857 100%);
                    color: #fff;
                }
                .sfs-hr-punch-btn.punch-out {
                    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
                    color: #fff;
                }
                .sfs-hr-punch-btn.break-start {
                    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                    color: #fff;
                }
                .sfs-hr-punch-btn.break-end {
                    background: linear-gradient(135deg, #2271b1 0%, #135e96 100%);
                    color: #fff;
                }
                .sfs-hr-punch-message {
                    text-align: center;
                    padding: 12px;
                    border-radius: 8px;
                    margin-top: 20px;
                    display: none;
                }
                .sfs-hr-punch-message.success {
                    background: #d1fae5;
                    color: #059669;
                    display: block;
                }
                .sfs-hr-punch-message.error {
                    background: #fee2e2;
                    color: #dc2626;
                    display: block;
                }
                .sfs-hr-punch-history {
                    margin-top: 30px;
                    border-top: 1px solid #e5e7eb;
                    padding-top: 20px;
                }
                .sfs-hr-punch-history h4 {
                    margin: 0 0 15px;
                    font-size: 14px;
                    color: #666;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                }
                .sfs-hr-punch-history-list {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                }
                .sfs-hr-punch-history-item {
                    display: flex;
                    justify-content: space-between;
                    padding: 10px 0;
                    border-bottom: 1px solid #f3f4f6;
                }
                .sfs-hr-punch-history-item:last-child {
                    border-bottom: none;
                }
                .sfs-hr-offline-indicator {
                    text-align: center;
                    padding: 8px;
                    background: #fef3c7;
                    color: #92400e;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    display: none;
                }
                .sfs-hr-offline-indicator.visible {
                    display: block;
                }
            </style>

            <div class="sfs-hr-offline-indicator" id="offline-indicator">
                <?php esc_html_e('You are offline. Punches will sync when connected.', 'sfs-hr'); ?>
            </div>

            <div class="sfs-hr-punch-header">
                <div class="sfs-hr-punch-avatar">
                    <?php echo esc_html(mb_substr($employee->first_name, 0, 1) . mb_substr($employee->last_name, 0, 1)); ?>
                </div>
                <h2 class="sfs-hr-punch-name"><?php echo esc_html($name); ?></h2>
            </div>

            <div class="sfs-hr-punch-time" id="punch-clock">
                <?php echo esc_html(wp_date('H:i:s')); ?>
            </div>

            <div class="sfs-hr-punch-date">
                <?php echo esc_html(wp_date('l, F j, Y')); ?>
            </div>

            <div class="sfs-hr-punch-status">
                <span class="sfs-hr-punch-status-badge <?php echo esc_attr($current_status); ?>" id="punch-status">
                    <?php
                    $status_labels = [
                        'not_clocked_in' => __('Not Clocked In', 'sfs-hr'),
                        'clocked_in' => __('Clocked In', 'sfs-hr'),
                        'on_break' => __('On Break', 'sfs-hr'),
                        'clocked_out' => __('Clocked Out', 'sfs-hr'),
                    ];
                    echo esc_html($status_labels[$current_status] ?? $current_status);
                    ?>
                </span>
            </div>

            <div class="sfs-hr-punch-buttons">
                <button type="button" class="sfs-hr-punch-btn punch-in" id="btn-punch-in" <?php disabled(!$can_punch_in); ?> data-action="in">
                    <?php esc_html_e('Punch In', 'sfs-hr'); ?>
                </button>

                <button type="button" class="sfs-hr-punch-btn break-start" id="btn-break-start" <?php disabled(!$can_start_break); ?> data-action="break_start">
                    <?php esc_html_e('Start Break', 'sfs-hr'); ?>
                </button>

                <button type="button" class="sfs-hr-punch-btn break-end" id="btn-break-end" <?php disabled(!$can_end_break); ?> data-action="break_end">
                    <?php esc_html_e('End Break', 'sfs-hr'); ?>
                </button>

                <button type="button" class="sfs-hr-punch-btn punch-out" id="btn-punch-out" <?php disabled(!$can_punch_out); ?> data-action="out">
                    <?php esc_html_e('Punch Out', 'sfs-hr'); ?>
                </button>
            </div>

            <div class="sfs-hr-punch-message" id="punch-message"></div>

            <?php
            // Get today's punches for history
            $today_punches = $wpdb->get_results($wpdb->prepare(
                "SELECT punch_type, punch_time FROM {$punches_table}
                 WHERE employee_id = %d AND DATE(punch_time) = %s
                 ORDER BY punch_time ASC",
                $employee->id,
                $today
            ));
            ?>

            <?php if (!empty($today_punches)): ?>
            <div class="sfs-hr-punch-history">
                <h4><?php esc_html_e("Today's Activity", 'sfs-hr'); ?></h4>
                <ul class="sfs-hr-punch-history-list" id="punch-history">
                    <?php
                    $punch_labels = [
                        'in' => __('Punched In', 'sfs-hr'),
                        'out' => __('Punched Out', 'sfs-hr'),
                        'break_start' => __('Break Started', 'sfs-hr'),
                        'break_end' => __('Break Ended', 'sfs-hr'),
                    ];
                    foreach ($today_punches as $p):
                        $time = date_i18n('H:i', strtotime($p->punch_time));
                        $label = $punch_labels[$p->punch_type] ?? $p->punch_type;
                    ?>
                    <li class="sfs-hr-punch-history-item">
                        <span><?php echo esc_html($label); ?></span>
                        <span><?php echo esc_html($time); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            // Update clock every second
            function updateClock() {
                const clock = document.getElementById('punch-clock');
                if (clock) {
                    const now = new Date();
                    clock.textContent = now.toLocaleTimeString('en-GB');
                }
            }
            setInterval(updateClock, 1000);

            // Offline indicator
            function updateOfflineIndicator() {
                const indicator = document.getElementById('offline-indicator');
                if (indicator) {
                    indicator.classList.toggle('visible', !navigator.onLine);
                }
            }
            window.addEventListener('online', updateOfflineIndicator);
            window.addEventListener('offline', updateOfflineIndicator);
            updateOfflineIndicator();

            // Punch button handlers
            document.querySelectorAll('.sfs-hr-punch-btn').forEach(btn => {
                btn.addEventListener('click', async function() {
                    if (this.disabled) return;

                    const action = this.dataset.action;
                    const message = document.getElementById('punch-message');
                    const employeeId = document.getElementById('sfs-hr-mobile-punch').dataset.employeeId;

                    this.disabled = true;
                    this.textContent = '<?php echo esc_js(__('Processing...', 'sfs-hr')); ?>';

                    try {
                        const response = await fetch('<?php echo esc_url(rest_url('sfs-hr/v1/attendance/punch')); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                            },
                            body: JSON.stringify({
                                punch_type: action
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            message.className = 'sfs-hr-punch-message success';
                            message.textContent = data.message || '<?php echo esc_js(__('Punch recorded!', 'sfs-hr')); ?>';

                            // Reload page to update status
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            message.className = 'sfs-hr-punch-message error';
                            message.textContent = data.message || '<?php echo esc_js(__('An error occurred.', 'sfs-hr')); ?>';
                            this.disabled = false;
                            this.textContent = this.dataset.originalText || 'Punch';
                        }
                    } catch (error) {
                        // Offline - queue for sync
                        if (!navigator.onLine && 'serviceWorker' in navigator && navigator.serviceWorker.controller) {
                            // Store in IndexedDB for later sync
                            const db = await openDB();
                            await storePunch(db, {
                                url: '<?php echo esc_url(rest_url('sfs-hr/v1/attendance/punch')); ?>',
                                nonce: '<?php echo wp_create_nonce('wp_rest'); ?>',
                                data: { punch_type: action }
                            });

                            message.className = 'sfs-hr-punch-message success';
                            message.textContent = '<?php echo esc_js(__('Offline. Punch will sync when connected.', 'sfs-hr')); ?>';

                            if ('sync' in navigator.serviceWorker.registration) {
                                navigator.serviceWorker.ready.then(reg => {
                                    reg.sync.register('sfs-hr-punch-sync');
                                });
                            }
                        } else {
                            message.className = 'sfs-hr-punch-message error';
                            message.textContent = '<?php echo esc_js(__('Connection error. Please try again.', 'sfs-hr')); ?>';
                        }
                        this.disabled = false;
                        this.textContent = this.dataset.originalText || 'Punch';
                    }
                });

                // Store original text
                btn.dataset.originalText = btn.textContent;
            });

            // IndexedDB helpers
            function openDB() {
                return new Promise((resolve, reject) => {
                    const request = indexedDB.open('sfs-hr-punches', 1);
                    request.onerror = () => reject(request.error);
                    request.onsuccess = () => resolve(request.result);
                    request.onupgradeneeded = (event) => {
                        const db = event.target.result;
                        if (!db.objectStoreNames.contains('punches')) {
                            db.createObjectStore('punches', { keyPath: 'id', autoIncrement: true });
                        }
                    };
                });
            }

            function storePunch(db, punch) {
                return new Promise((resolve, reject) => {
                    const transaction = db.transaction('punches', 'readwrite');
                    const store = transaction.objectStore('punches');
                    const request = store.add(punch);
                    request.onerror = () => reject(request.error);
                    request.onsuccess = () => resolve(request.result);
                });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
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
}
