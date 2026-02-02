<?php
namespace SFS\HR\Modules\Resignation\Admin\Views;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Resignation\Services\Resignation_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Resignation List View
 * Admin list view with search, status tabs, and modals
 */
class Resignation_List {

    /**
     * Render the resignations list
     */
    public static function render(): void {
        $current_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'pending';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        // Department scoping for non-HR managers
        $dept_ids = [];
        if (!current_user_can('sfs_hr.manage')) {
            $dept_ids = Resignation_Service::get_manager_dept_ids(get_current_user_id());
        }

        $counts = Resignation_Service::get_status_counts($search, $dept_ids);
        $result = Resignation_Service::get_resignations($current_status, $page, 20, $search, $dept_ids);
        $status_tabs = Resignation_Service::get_status_tabs();

        self::render_styles();
        ?>

        <!-- Search Toolbar -->
        <div class="sfs-hr-resignation-toolbar">
            <form method="get">
                <input type="hidden" name="page" value="sfs-hr-resignations" />
                <input type="hidden" name="tab" value="resignations" />
                <input type="hidden" name="status" value="<?php echo esc_attr($current_status); ?>" />
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search employee name or code...', 'sfs-hr'); ?>" />
                <button type="submit" class="button"><?php esc_html_e('Search', 'sfs-hr'); ?></button>
                <?php if ($search !== ''): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-lifecycle&tab=resignations&tab=resignations&status=' . $current_status)); ?>" class="button"><?php esc_html_e('Clear', 'sfs-hr'); ?></a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Status Tabs -->
        <div class="sfs-hr-resignation-tabs">
            <?php foreach ($status_tabs as $key => $label): ?>
                <?php
                $url = add_query_arg([
                    'page'   => 'sfs-hr-resignations',
                    'tab'    => 'resignations',
                    'status' => $key,
                    's'      => $search !== '' ? $search : null,
                ], admin_url('admin.php'));
                $url = remove_query_arg('paged', $url);
                $classes = 'sfs-tab' . ($key === $current_status ? ' active' : '');
                ?>
                <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($classes); ?>">
                    <?php echo esc_html($label); ?>
                    <span class="count"><?php echo esc_html($counts[$key]); ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Table Card -->
        <div class="sfs-hr-resignation-table-wrap">
            <div class="table-header">
                <h3><?php echo esc_html($status_tabs[$current_status] ?? __('Resignations', 'sfs-hr')); ?> (<?php echo esc_html($result['total']); ?>)</h3>
            </div>

            <table class="sfs-hr-resignation-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Ref #', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Type', 'sfs-hr'); ?></th>
                        <th class="hide-mobile"><?php esc_html_e('Resignation Date', 'sfs-hr'); ?></th>
                        <th class="hide-mobile"><?php esc_html_e('Last Working Day', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th class="hide-mobile"><?php esc_html_e('Reason', 'sfs-hr'); ?></th>
                        <th class="hide-mobile" style="width:50px;"></th>
                        <th class="show-mobile" style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($result['rows'])): ?>
                        <tr>
                            <td colspan="9" class="empty-state">
                                <p><?php esc_html_e('No resignations found.', 'sfs-hr'); ?></p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($result['rows'] as $row): ?>
                            <tr data-id="<?php echo esc_attr($row['id']); ?>"
                                data-ref="<?php echo esc_attr($row['request_number'] ?? ''); ?>"
                                data-employee="<?php echo esc_attr($row['first_name'] . ' ' . $row['last_name']); ?>"
                                data-code="<?php echo esc_attr($row['employee_code']); ?>"
                                data-type="<?php echo esc_attr($row['resignation_type'] ?? 'regular'); ?>"
                                data-date="<?php echo esc_attr($row['resignation_date']); ?>"
                                data-lwd="<?php echo esc_attr($row['last_working_day'] ?: 'N/A'); ?>"
                                data-notice="<?php echo esc_attr($row['notice_period_days']); ?>"
                                data-status="<?php echo esc_attr($row['status']); ?>"
                                data-level="<?php echo esc_attr($row['approval_level'] ?? 1); ?>"
                                data-reason="<?php echo esc_attr($row['reason']); ?>">
                                <td><strong><?php echo esc_html($row['request_number'] ?? '-'); ?></strong></td>
                                <td>
                                    <?php
                                    $profile_url = admin_url('admin.php?page=sfs-hr-employee-profile&employee_id=' . (int) $row['employee_id']);
                                    ?>
                                    <a href="<?php echo esc_url($profile_url); ?>" class="emp-name"><?php echo esc_html($row['first_name'] . ' ' . $row['last_name']); ?></a>
                                    <span class="emp-code"><?php echo esc_html($row['employee_code']); ?></span>
                                    <?php
                                    if (class_exists('\SFS\HR\Modules\Loans\Admin\DashboardWidget')) {
                                        echo \SFS\HR\Modules\Loans\Admin\DashboardWidget::render_employee_loan_badge($row['employee_id']);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $type = $row['resignation_type'] ?? 'regular';
                                    if ($type === 'final_exit') {
                                        echo '<span class="sfs-hr-pill sfs-hr-pill--final-exit">' . esc_html__('Final Exit', 'sfs-hr') . '</span>';
                                    } else {
                                        echo '<span class="sfs-hr-pill sfs-hr-pill--regular">' . esc_html__('Regular', 'sfs-hr') . '</span>';
                                    }
                                    ?>
                                </td>
                                <td class="hide-mobile"><?php echo esc_html($row['resignation_date'] ?: 'N/A'); ?></td>
                                <td class="hide-mobile"><?php echo esc_html($row['last_working_day'] ?: 'N/A'); ?></td>
                                <td><?php echo Resignation_Service::render_status_pill($row['status'], intval($row['approval_level'] ?? 1)); ?></td>
                                <td class="hide-mobile"><?php echo esc_html(wp_trim_words($row['reason'], 8, '...')); ?></td>
                                <td class="hide-mobile">
                                    <button type="button" class="sfs-hr-view-btn" onclick="showResignationDetails(<?php echo esc_attr($row['id']); ?>);" title="<?php esc_attr_e('View Details', 'sfs-hr'); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                </td>
                                <td class="show-mobile">
                                    <button type="button" class="sfs-hr-view-btn sfs-hr-view-btn--mobile sfs-mobile-detail-btn" title="<?php esc_attr_e('View Details', 'sfs-hr'); ?>">
                                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($result['total_pages'] > 1): ?>
                <div class="sfs-hr-resignation-pagination">
                    <?php
                    echo paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $result['total_pages'],
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <?php
        self::render_modals();
        self::render_scripts();
    }

    /**
     * Render CSS styles
     */
    private static function render_styles(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
        <style>
            .sfs-hr-resignation-main-tabs { display: flex; gap: 8px; margin-bottom: 20px; }
            .sfs-hr-resignation-main-tabs .sfs-main-tab {
                padding: 10px 20px;
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                text-decoration: none;
                color: #50575e;
                font-weight: 500;
            }
            .sfs-hr-resignation-main-tabs .sfs-main-tab:hover { background: #fff; border-color: #2271b1; color: #2271b1; }
            .sfs-hr-resignation-main-tabs .sfs-main-tab.active { background: #2271b1; border-color: #2271b1; color: #fff; }
            .sfs-hr-resignation-toolbar {
                background: #fff; border: 1px solid #e2e4e7; border-radius: 8px;
                padding: 16px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .sfs-hr-resignation-toolbar form { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin: 0; }
            .sfs-hr-resignation-toolbar input[type="search"] {
                height: 36px; border: 1px solid #dcdcde; border-radius: 4px;
                padding: 0 12px; font-size: 13px; min-width: 200px;
            }
            .sfs-hr-resignation-toolbar .button { height: 36px; line-height: 34px; padding: 0 16px; }
            .sfs-hr-resignation-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
            .sfs-hr-resignation-tabs .sfs-tab {
                display: inline-block; padding: 8px 16px; background: #f6f7f7;
                border: 1px solid #dcdcde; border-radius: 20px; font-size: 13px;
                font-weight: 500; color: #50575e; text-decoration: none; transition: all 0.15s ease;
            }
            .sfs-hr-resignation-tabs .sfs-tab:hover { background: #fff; border-color: #2271b1; color: #2271b1; }
            .sfs-hr-resignation-tabs .sfs-tab.active { background: #2271b1; border-color: #2271b1; color: #fff; }
            .sfs-hr-resignation-tabs .sfs-tab .count {
                display: inline-block; background: rgba(0,0,0,0.1);
                padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 6px;
            }
            .sfs-hr-resignation-tabs .sfs-tab.active .count { background: rgba(255,255,255,0.25); }
            .sfs-hr-resignation-table-wrap {
                background: #fff; border: 1px solid #e2e4e7; border-radius: 8px;
                overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            }
            .sfs-hr-resignation-table-wrap .table-header {
                padding: 16px 20px; border-bottom: 1px solid #f0f0f1; background: #f9fafb;
            }
            .sfs-hr-resignation-table-wrap .table-header h3 { margin: 0; font-size: 14px; font-weight: 600; color: #1d2327; }
            .sfs-hr-resignation-table { width: 100%; border-collapse: collapse; margin: 0; }
            .sfs-hr-resignation-table th {
                background: #f9fafb; padding: 12px 16px; text-align: start;
                font-weight: 600; font-size: 12px; color: #50575e;
                text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid #e2e4e7;
            }
            .sfs-hr-resignation-table td { padding: 14px 16px; font-size: 13px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
            .sfs-hr-resignation-table tbody tr:hover { background: #f9fafb; }
            .sfs-hr-resignation-table tbody tr:last-child td { border-bottom: none; }
            .sfs-hr-resignation-table .emp-name { display: block; font-weight: 500; color: #2271b1; text-decoration: none; }
            .sfs-hr-resignation-table a.emp-name:hover { color: #135e96; text-decoration: underline; }
            .sfs-hr-resignation-table .emp-code { display: block; font-size: 11px; color: #787c82; margin-top: 2px; }
            .sfs-hr-resignation-table .empty-state { text-align: center; padding: 40px 20px; color: #787c82; }
            .sfs-hr-pill { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; line-height: 1.4; }
            .sfs-hr-pill--final-exit { background: #e8def8; color: #5e35b1; }
            .sfs-hr-pill--regular { background: #eceff1; color: #546e7a; }
            .sfs-hr-pill--pending { background: #fff3e0; color: #e65100; }
            .sfs-hr-pill--approved { background: #e8f5e9; color: #2e7d32; }
            .sfs-hr-pill--rejected { background: #ffebee; color: #c62828; }
            .sfs-hr-pill--cancelled { background: #fafafa; color: #757575; }
            .sfs-hr-pill--completed { background: #e3f2fd; color: #1565c0; }
            .sfs-hr-view-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 36px;
                height: 36px;
                background: #f0f6fc;
                border: 1px solid #c5d9ed;
                border-radius: 50%;
                cursor: pointer;
                transition: all 0.15s ease;
            }
            .sfs-hr-view-btn .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
                color: #2271b1;
            }
            .sfs-hr-view-btn:hover {
                background: #2271b1;
                border-color: #2271b1;
            }
            .sfs-hr-view-btn:hover .dashicons {
                color: #fff;
            }
            .sfs-hr-view-btn--mobile {
                width: 32px;
                height: 32px;
                background: transparent;
                border: none;
            }
            .sfs-hr-view-btn--mobile .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
                color: #787c82;
            }
            .sfs-hr-view-btn--mobile:hover {
                background: #f0f6fc;
            }
            .sfs-hr-view-btn--mobile:hover .dashicons {
                color: #2271b1;
            }
            .sfs-hr-resignation-pagination {
                padding: 16px 20px; border-top: 1px solid #e2e4e7; background: #f9fafb; text-align: center;
            }
            .sfs-hr-resignation-pagination .page-numbers {
                display: inline-block; padding: 6px 12px; margin: 0 2px;
                border: 1px solid #dcdcde; border-radius: 4px; text-decoration: none; color: #2271b1; font-size: 13px;
            }
            .sfs-hr-resignation-pagination .page-numbers.current { background: #2271b1; border-color: #2271b1; color: #fff; }
            .sfs-hr-resignation-modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 100000; }
            .sfs-hr-resignation-modal.active { display: block; }
            .sfs-hr-resignation-modal-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); }
            .sfs-hr-resignation-modal-content {
                position: absolute; bottom: 0; left: 0; right: 0;
                background: #fff; border-radius: 16px 16px 0 0; padding: 24px;
                max-height: 85vh; overflow-y: auto; transform: translateY(100%); transition: transform 0.3s ease;
            }
            .sfs-hr-resignation-modal.active .sfs-hr-resignation-modal-content { transform: translateY(0); }
            .sfs-hr-resignation-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #e2e4e7; }
            .sfs-hr-resignation-modal-header h3 { margin: 0; font-size: 18px; color: #1d2327; }
            .sfs-hr-resignation-modal-close { background: #f6f7f7; border: none; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center; }
            .sfs-hr-resignation-modal-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f0f0f1; }
            .sfs-hr-resignation-modal-row:last-child { border-bottom: none; }
            .sfs-hr-resignation-modal-label { color: #50575e; font-size: 13px; }
            .sfs-hr-resignation-modal-value { font-weight: 500; color: #1d2327; font-size: 13px; text-align: right; }
            @media screen and (max-width: 782px) {
                .sfs-hr-resignation-toolbar form { flex-direction: column; align-items: stretch; }
                .sfs-hr-resignation-toolbar input[type="search"] { width: 100%; min-width: auto; }
                .sfs-hr-resignation-tabs { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 8px; -webkit-overflow-scrolling: touch; }
                .sfs-hr-resignation-tabs .sfs-tab { flex-shrink: 0; padding: 6px 12px; font-size: 12px; }
                .sfs-hr-resignation-main-tabs { flex-wrap: wrap; }
                .hide-mobile { display: none !important; }
                .sfs-hr-resignation-table th, .sfs-hr-resignation-table td { padding: 12px; }
                .show-mobile { display: table-cell !important; }
            }
            @media screen and (min-width: 783px) { .show-mobile { display: none !important; } }
        </style>
        <?php
    }

    /**
     * Render modals
     */
    private static function render_modals(): void {
        ?>
        <!-- Mobile Slide-up Modal -->
        <div id="sfs-hr-resignation-modal" class="sfs-hr-resignation-modal">
            <div class="sfs-hr-resignation-modal-overlay" onclick="closeResignationModal();"></div>
            <div class="sfs-hr-resignation-modal-content">
                <div class="sfs-hr-resignation-modal-header">
                    <h3><?php esc_html_e('Resignation Details', 'sfs-hr'); ?></h3>
                    <button type="button" class="sfs-hr-resignation-modal-close" onclick="closeResignationModal();">×</button>
                </div>
                <div id="sfs-hr-resignation-modal-body"></div>
                <div id="sfs-hr-resignation-modal-actions" style="margin-top:20px;display:flex;flex-wrap:wrap;gap:8px;"></div>
            </div>
        </div>

        <!-- Approve Modal -->
        <div id="approve-modal" class="sfs-hr-resignation-modal">
            <div class="sfs-hr-resignation-modal-overlay" onclick="hideApproveModal();"></div>
            <div class="sfs-hr-resignation-modal-content">
                <div class="sfs-hr-resignation-modal-header">
                    <h3><?php esc_html_e('Approve Resignation', 'sfs-hr'); ?></h3>
                    <button type="button" class="sfs-hr-resignation-modal-close" onclick="hideApproveModal();">×</button>
                </div>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_resignation_approve'); ?>
                    <input type="hidden" name="action" value="sfs_hr_resignation_approve">
                    <input type="hidden" name="resignation_id" id="approve-resignation-id">
                    <p>
                        <label><?php esc_html_e('Note (optional):', 'sfs-hr'); ?></label><br>
                        <textarea name="note" rows="4" style="width:100%;border:1px solid #dcdcde;border-radius:4px;padding:8px;"></textarea>
                    </p>
                    <p style="display:flex;gap:8px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Approve', 'sfs-hr'); ?></button>
                        <button type="button" onclick="hideApproveModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Reject Modal -->
        <div id="reject-modal" class="sfs-hr-resignation-modal">
            <div class="sfs-hr-resignation-modal-overlay" onclick="hideRejectModal();"></div>
            <div class="sfs-hr-resignation-modal-content">
                <div class="sfs-hr-resignation-modal-header">
                    <h3><?php esc_html_e('Reject Resignation', 'sfs-hr'); ?></h3>
                    <button type="button" class="sfs-hr-resignation-modal-close" onclick="hideRejectModal();">×</button>
                </div>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_resignation_reject'); ?>
                    <input type="hidden" name="action" value="sfs_hr_resignation_reject">
                    <input type="hidden" name="resignation_id" id="reject-resignation-id">
                    <p>
                        <label><?php esc_html_e('Reason for rejection:', 'sfs-hr'); ?></label><br>
                        <textarea name="note" rows="4" style="width:100%;border:1px solid #dcdcde;border-radius:4px;padding:8px;" required></textarea>
                    </p>
                    <p style="display:flex;gap:8px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>
                        <button type="button" onclick="hideRejectModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Cancel Modal -->
        <div id="cancel-modal" class="sfs-hr-resignation-modal">
            <div class="sfs-hr-resignation-modal-overlay" onclick="hideCancelModal();"></div>
            <div class="sfs-hr-resignation-modal-content">
                <div class="sfs-hr-resignation-modal-header">
                    <h3><?php esc_html_e('Cancel Resignation', 'sfs-hr'); ?></h3>
                    <button type="button" class="sfs-hr-resignation-modal-close" onclick="hideCancelModal();">×</button>
                </div>
                <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_resignation_cancel'); ?>
                    <input type="hidden" name="action" value="sfs_hr_resignation_cancel">
                    <input type="hidden" name="resignation_id" id="cancel-resignation-id">
                    <p style="color:#dc3545;font-weight:500;">
                        <?php esc_html_e('Are you sure you want to cancel this resignation?', 'sfs-hr'); ?>
                    </p>
                    <p>
                        <label><?php esc_html_e('Reason for cancellation:', 'sfs-hr'); ?></label><br>
                        <textarea name="note" rows="4" style="width:100%;border:1px solid #dcdcde;border-radius:4px;padding:8px;" required></textarea>
                    </p>
                    <p style="display:flex;gap:8px;">
                        <button type="submit" class="button" style="background:#dc3545;border-color:#dc3545;color:#fff;"><?php esc_html_e('Cancel Resignation', 'sfs-hr'); ?></button>
                        <button type="button" onclick="hideCancelModal();" class="button"><?php esc_html_e('Close', 'sfs-hr'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Final Exit Modal -->
        <div id="final-exit-modal" class="sfs-hr-resignation-modal">
            <div class="sfs-hr-resignation-modal-overlay" onclick="hideFinalExitModal();"></div>
            <div class="sfs-hr-resignation-modal-content" style="max-height:90vh;">
                <div class="sfs-hr-resignation-modal-header">
                    <h3><?php esc_html_e('Final Exit Management', 'sfs-hr'); ?></h3>
                    <button type="button" class="sfs-hr-resignation-modal-close" onclick="hideFinalExitModal();">×</button>
                </div>
                <div id="final-exit-content">
                    <p><?php esc_html_e('Loading...', 'sfs-hr'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render JavaScript
     */
    private static function render_scripts(): void {
        ?>
        <script>
        var sfsResignationAjaxNonce = '<?php echo wp_create_nonce('sfs_hr_resignation_ajax'); ?>';
        var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';

        function showResignationDetails(id) {
            var tr = document.querySelector('tr[data-id="'+id+'"]');
            if(!tr) return;
            var body = document.getElementById('sfs-hr-resignation-modal-body');
            var actions = document.getElementById('sfs-hr-resignation-modal-actions');

            // Show basic info from data attributes first
            body.innerHTML = '<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php esc_html_e('Reference #', 'sfs-hr'); ?></span><span class="sfs-hr-resignation-modal-value"><strong>'+(tr.dataset.ref||'-')+'</strong></span></div>'
                +'<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php esc_html_e('Employee', 'sfs-hr'); ?></span><span class="sfs-hr-resignation-modal-value">'+tr.dataset.employee+'</span></div>'
                +'<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php esc_html_e('Code', 'sfs-hr'); ?></span><span class="sfs-hr-resignation-modal-value">'+tr.dataset.code+'</span></div>'
                +'<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php esc_html_e('Type', 'sfs-hr'); ?></span><span class="sfs-hr-resignation-modal-value">'+(tr.dataset.type==='final_exit'?'<?php esc_html_e('Final Exit', 'sfs-hr'); ?>':'<?php esc_html_e('Regular', 'sfs-hr'); ?>')+'</span></div>'
                +'<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php esc_html_e('Date', 'sfs-hr'); ?></span><span class="sfs-hr-resignation-modal-value">'+tr.dataset.date+'</span></div>'
                +'<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php esc_html_e('Last Working Day', 'sfs-hr'); ?></span><span class="sfs-hr-resignation-modal-value">'+tr.dataset.lwd+'</span></div>'
                +'<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php esc_html_e('Status', 'sfs-hr'); ?></span><span class="sfs-hr-resignation-modal-value">'+tr.dataset.status+'</span></div>'
                +'<div class="sfs-hr-resignation-modal-row"><span class="sfs-hr-resignation-modal-label"><?php esc_html_e('Reason', 'sfs-hr'); ?></span><span class="sfs-hr-resignation-modal-value">'+tr.dataset.reason+'</span></div>'
                +'<div id="sfs-hr-resignation-history" style="margin-top:15px;"><p style="color:#999;font-size:12px;"><?php esc_html_e('Loading history...', 'sfs-hr'); ?></p></div>';

            var btns = '';
            if(tr.dataset.status === 'pending') {
                btns += '<button class="button button-primary" onclick="showApproveModal('+id+');"><?php esc_html_e('Approve', 'sfs-hr'); ?></button>';
                btns += '<button class="button" onclick="showRejectModal('+id+');"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>';
                btns += '<button class="button" onclick="showCancelModal('+id+');"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>';
            }
            actions.innerHTML = btns;
            document.getElementById('sfs-hr-resignation-modal').classList.add('active');

            // Fetch history via AJAX
            var formData = new FormData();
            formData.append('action', 'sfs_hr_get_resignation');
            formData.append('nonce', sfsResignationAjaxNonce);
            formData.append('resignation_id', id);

            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(result) {
                var historyDiv = document.getElementById('sfs-hr-resignation-history');
                if(result.success && result.data.history && result.data.history.length > 0) {
                    var html = '<h4 style="margin:0 0 10px 0;padding-top:10px;border-top:1px solid #eee;"><?php esc_html_e('History', 'sfs-hr'); ?></h4>';
                    html += '<div style="max-height:200px;overflow-y:auto;">';
                    result.data.history.forEach(function(event) {
                        html += '<div style="border-bottom:1px solid #f0f0f1;padding:8px 0;font-size:12px;">';
                        html += '<div style="color:#666;font-size:11px;">'+event.date+'</div>';
                        html += '<div style="font-weight:600;">'+event.event_type+'</div>';
                        html += '<div style="color:#555;">'+event.user_name+'</div>';
                        if(event.meta && Object.keys(event.meta).length > 0) {
                            html += '<div style="background:#f9f9f9;padding:4px 6px;margin-top:4px;border-radius:3px;font-size:11px;">';
                            for(var key in event.meta) {
                                if(event.meta[key] !== null && event.meta[key] !== '') {
                                    var label = key.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                                    html += '<strong>'+label+':</strong> '+event.meta[key]+'<br>';
                                }
                            }
                            html += '</div>';
                        }
                        html += '</div>';
                    });
                    html += '</div>';
                    historyDiv.innerHTML = html;
                } else {
                    historyDiv.innerHTML = '<p style="color:#999;font-size:12px;margin:0;"><?php esc_html_e('No history recorded yet.', 'sfs-hr'); ?></p>';
                }
            })
            .catch(function(error) {
                document.getElementById('sfs-hr-resignation-history').innerHTML = '';
            });
        }

        function closeResignationModal() { document.getElementById('sfs-hr-resignation-modal').classList.remove('active'); }
        function showApproveModal(id) { closeResignationModal(); document.getElementById('approve-resignation-id').value = id; document.getElementById('approve-modal').classList.add('active'); }
        function hideApproveModal() { document.getElementById('approve-modal').classList.remove('active'); }
        function showRejectModal(id) { closeResignationModal(); document.getElementById('reject-resignation-id').value = id; document.getElementById('reject-modal').classList.add('active'); }
        function hideRejectModal() { document.getElementById('reject-modal').classList.remove('active'); }
        function showCancelModal(id) { closeResignationModal(); document.getElementById('cancel-resignation-id').value = id; document.getElementById('cancel-modal').classList.add('active'); }
        function hideCancelModal() { document.getElementById('cancel-modal').classList.remove('active'); }
        function hideFinalExitModal() { document.getElementById('final-exit-modal').classList.remove('active'); }

        document.querySelectorAll('.sfs-mobile-detail-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var tr = this.closest('tr');
                if(tr && tr.dataset.id) showResignationDetails(tr.dataset.id);
            });
        });
        </script>
        <?php
    }
}
