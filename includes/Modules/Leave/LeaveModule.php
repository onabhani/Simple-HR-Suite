<?php
namespace SFS\HR\Modules\Leave;

use SFS\HR\Core\Helpers;

if (!defined('ABSPATH')) { exit; }
// Load UI helper for chips
require_once __DIR__ . '/class-leave-ui.php';

class LeaveModule {

    public function hooks(): void {
        add_action('admin_menu', [$this, 'menu']);

        add_action('admin_post_sfs_hr_leave_approve',        [$this, 'handle_approve']);
        add_action('admin_post_sfs_hr_leave_reject',         [$this, 'handle_reject']);
        add_action('admin_post_sfs_hr_leave_addtype',        [$this, 'handle_add_type']);
        add_action('admin_post_sfs_hr_leave_deltype',        [$this, 'handle_delete_type']);
        add_action('admin_post_sfs_hr_leave_markannual',     [$this, 'handle_mark_annual']);
        add_action('admin_post_sfs_hr_leave_settings',       [$this, 'handle_settings']);
        add_action('admin_post_sfs_hr_leave_update_balance', [$this, 'handle_update_balance']);

        // Holidays (option-based, supports single-day & multi-day with yearly repeat)
        add_action('admin_post_sfs_hr_holiday_add',          [$this, 'handle_holiday_add']);
        add_action('admin_post_sfs_hr_holiday_del',          [$this, 'handle_holiday_del']);

        // Cron for holiday reminders
        add_action('init',                                    [$this, 'ensure_cron']);
        add_action('sfs_hr_daily',                            [$this, 'cron_daily']);

        
        add_action('admin_post_sfs_hr_leave_early_return', [$this, 'handle_early_return']);
        add_shortcode('sfs_hr_leave_widget', [$this, 'shortcode_leave_widget']);
  // Employee Profile tab integration (manager view)
        add_action( 'sfs_hr_employee_tabs',         [ $this, 'employee_tab' ],         30, 1 );
        add_action( 'sfs_hr_employee_tab_content',  [ $this, 'employee_tab_content' ], 30, 2 );
        add_action( 'admin_post_sfs_hr_leave_request_self', [ $this, 'handle_self_request' ] );
       // add_action( 'admin_post_sfs_hr_leave_request_self', [ $this, 'handle_self_leave_request' ] );


    }

    public function menu(): void {
    add_submenu_page(
        'sfs-hr',
        __('Leave', 'sfs-hr'),
        __('Leave', 'sfs-hr'),
        'sfs_hr.leave.review',
        'sfs-hr-leave-requests',
        [ $this, 'render_leave_admin' ] // ✅ use the tabbed UI renderer
    );
}


public function render_leave_page(): void {
    // Which tabs are actually available for this user?
    $available = [];

    if ( current_user_can('sfs_hr.leave.review') ) {
        $available[] = 'requests';
    }
    if ( current_user_can('sfs_hr.leave.manage') ) {
        $available = array_merge($available, ['types', 'balances', 'settings']);
    }

    if ( empty($available) ) {
        echo '<div class="wrap"><h1>' . esc_html__('Leave', 'sfs-hr') . '</h1>';
        echo '<p>' . esc_html__('You do not have access to Leave admin.', 'sfs-hr') . '</p></div>';
        return;
    }

    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
    if ( ! in_array($tab, $available, true) ) {
        $tab = $available[0]; // default to first allowed tab
    }

    switch ($tab) {
        case 'types':
            $this->render_types();
            break;
        case 'balances':
            $this->render_balances();
            break;
        case 'settings':
            $this->render_settings();
            break;
        case 'requests':
        default:
            $this->render_requests();
            break;
    }
}




    /* ---------------------------------- Requests (Admin) ---------------------------------- */

public function render_requests(): void {
    global $wpdb;

    $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'pending';
    $page   = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $pp     = 20;
    $offset = ($page-1)*$pp;

    $req_t = $wpdb->prefix.'sfs_hr_leave_requests';
    $emp_t = $wpdb->prefix.'sfs_hr_employees';
    $typ_t = $wpdb->prefix.'sfs_hr_leave_types';

    $where  = '1=1';
    $params = [];

    if (in_array($status, ['pending','approved','rejected'], true)) {
        $where   .= " AND r.status = %s";
        $params[] = $status;
    }

    // Search filter
    if ( $search !== '' ) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= " AND (e.first_name LIKE %s OR e.last_name LIKE %s OR e.employee_code LIKE %s)";
        $params = array_merge($params, [$like, $like, $like]);
    }

    // Dept-manager scoping (HR/admins see all)
    $current_uid = get_current_user_id();
    $is_hr = current_user_can('sfs_hr.manage');
    $managed_depts = [];

    if ( ! $is_hr ) {
        $managed_depts = $this->manager_dept_ids_for_user($current_uid);
        if (empty($managed_depts)) {
            $this->output_leave_requests_styles();
            ?>
            <div class="sfs-hr-leave-table-wrap">
                <p style="padding: 20px;"><?php esc_html_e('No departments assigned to you.', 'sfs-hr'); ?></p>
            </div>
            <?php
            return;
        }
        $placeholders = implode(',', array_fill(0, count($managed_depts), '%d'));
        $where .= " AND e.dept_id IN ($placeholders)";
        $params = array_merge($params, array_map('intval', $managed_depts));
    }

    // Count by status for tabs
    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    $count_where = '1=1';
    $count_params = [];
    if ( ! $is_hr && ! empty($managed_depts) ) {
        $placeholders = implode(',', array_fill(0, count($managed_depts), '%d'));
        $count_where = "e.dept_id IN ($placeholders)";
        $count_params = array_map('intval', $managed_depts);
    }
    foreach (['pending', 'approved', 'rejected'] as $s) {
        $sql_count = "SELECT COUNT(*) FROM $req_t r JOIN $emp_t e ON e.id = r.employee_id WHERE r.status = %s" . ($count_where !== '1=1' ? " AND $count_where" : "");
        $c_params = array_merge([$s], $count_params);
        $counts[$s] = (int) $wpdb->get_var($wpdb->prepare($sql_count, ...$c_params));
    }

    $sql_total = "SELECT COUNT(*) FROM $req_t r JOIN $emp_t e ON e.id = r.employee_id WHERE $where";
    $total = $params ? (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params)) : (int)$wpdb->get_var($sql_total);

    $sql = "SELECT r.*, e.employee_code, e.first_name, e.last_name, e.user_id AS emp_user_id, e.dept_id,
                   t.name AS type_name, t.is_annual, t.annual_quota
            FROM $req_t r
            JOIN $emp_t e ON e.id = r.employee_id
            JOIN $typ_t t ON t.id = r.type_id
            WHERE $where
            ORDER BY r.id DESC
            LIMIT %d OFFSET %d";
    $params_rows = $params ? array_merge($params, [$pp, $offset]) : [$pp, $offset];
    $rows  = $wpdb->get_results($wpdb->prepare($sql, ...$params_rows), ARRAY_A);
    $pages = max(1, (int)ceil($total / $pp));

    $nonceA = wp_create_nonce('sfs_hr_leave_approve');
    $nonceR = wp_create_nonce('sfs_hr_leave_reject');

    // Output styles
    $this->output_leave_requests_styles();

    // Toolbar
    ?>
    <div class="sfs-hr-leave-toolbar">
        <form method="get">
            <input type="hidden" name="page" value="sfs-hr-leave-requests" />
            <input type="hidden" name="tab" value="requests" />
            <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>" />

            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search employee...', 'sfs-hr'); ?>" />

            <button type="submit" class="button button-primary"><?php esc_html_e('Search', 'sfs-hr'); ?></button>
        </form>
    </div>

    <!-- Status Tabs -->
    <div class="sfs-hr-leave-tabs">
        <?php
        $tabs = [
            'pending'  => __('Pending', 'sfs-hr'),
            'approved' => __('Approved', 'sfs-hr'),
            'rejected' => __('Rejected', 'sfs-hr'),
        ];
        foreach ($tabs as $k => $lbl) {
            $url = add_query_arg([
                'page'   => 'sfs-hr-leave-requests',
                'tab'    => 'requests',
                'status' => $k,
                'paged'  => 1,
            ], admin_url('admin.php'));
            $active = ($status === $k) ? ' active' : '';
            echo '<a href="' . esc_url($url) . '" class="sfs-tab' . $active . '">';
            echo esc_html($lbl);
            echo '<span class="count">' . esc_html($counts[$k]) . '</span>';
            echo '</a>';
        }
        ?>
    </div>

    <?php if (!empty($_GET['ok'])): ?>
        <div class="notice notice-success"><p><?php esc_html_e('Action completed.', 'sfs-hr'); ?></p></div>
    <?php endif; if (!empty($_GET['err'])): ?>
        <div class="notice notice-error"><p><?php echo esc_html($_GET['err']); ?></p></div>
    <?php endif; ?>

    <!-- Table Card -->
    <div class="sfs-hr-leave-table-wrap">
        <div class="table-header">
            <h3><?php echo esc_html($tabs[$status] ?? ''); ?> (<?php echo esc_html($total); ?>)</h3>
        </div>

        <table class="sfs-hr-leave-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                    <th class="hide-mobile"><?php esc_html_e('Type', 'sfs-hr'); ?></th>
                    <th class="hide-mobile"><?php esc_html_e('Dates', 'sfs-hr'); ?></th>
                    <th class="hide-mobile"><?php esc_html_e('Days', 'sfs-hr'); ?></th>
                    <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                    <th class="hide-mobile"><?php esc_html_e('Submitted', 'sfs-hr'); ?></th>
                    <th class="hide-mobile"><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    <th class="show-mobile" style="width:50px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8"><?php esc_html_e('No requests found.', 'sfs-hr'); ?></td></tr>
                <?php else: foreach ($rows as $idx => $r):
                    // Check if current user can approve this specific request
                    $can_approve = false;
                    if ($r['status'] === 'pending') {
                        // HR can approve all
                        if ($is_hr) {
                            $can_approve = true;
                        }
                        // Department managers can approve requests from their departments
                        elseif (!empty($managed_depts) && in_array((int)$r['dept_id'], $managed_depts, true)) {
                            $can_approve = true;
                        }
                        // Cannot approve own request
                        if ((int)($r['emp_user_id'] ?? 0) === $current_uid) {
                            $can_approve = false;
                        }
                    }

                    $today = current_time('Y-m-d');
                    $state_label = '—';
                    $state_class = '';
                    if ($r['status'] === 'approved') {
                        if ($today < $r['start_date']) {
                            $state_label = __('Upcoming', 'sfs-hr');
                            $state_class = 'sfs-hr-pill--status-upcoming';
                        } elseif ($today > $r['end_date']) {
                            $state_label = __('Returned', 'sfs-hr');
                            $state_class = 'sfs-hr-pill--status-returned';
                        } else {
                            $state_label = __('On leave', 'sfs-hr');
                            $state_class = 'sfs-hr-pill--status-onleave';
                        }
                    }

                    $status_key = (string) $r['status'];
                    if ($status_key === 'pending') {
                        $level = (int)($r['approval_level'] ?? 1);
                        $status_key = ($level <= 1) ? 'pending_manager' : 'pending_hr';
                    }
                ?>
                    <tr>
                        <td>
                            <span class="emp-name"><?php echo esc_html(trim($r['first_name'] . ' ' . $r['last_name'])); ?></span>
                            <span class="emp-code"><?php echo esc_html($r['employee_code']); ?></span>
                        </td>
                        <td class="hide-mobile"><?php echo esc_html($r['type_name']); ?></td>
                        <td class="hide-mobile"><?php echo esc_html($r['start_date'] . ' → ' . $r['end_date']); ?></td>
                        <td class="hide-mobile"><?php echo (int)$r['days']; ?></td>
                        <td>
                            <?php echo Leave_UI::leave_status_chip($status_key); ?>
                            <?php if ($r['status'] === 'approved' && $state_class): ?>
                                <span class="sfs-hr-pill <?php echo esc_attr($state_class); ?>" style="margin-left:4px;">
                                    <?php echo esc_html($state_label); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="hide-mobile"><?php echo $this->fmt_dt($r['created_at'] ?? ''); ?></td>
                        <td class="hide-mobile">
                            <?php if ($r['status'] === 'pending' && $can_approve): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="sfs_hr_leave_approve"/>
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonceA); ?>"/>
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"/>
                                    <button class="button button-primary button-small"><?php esc_html_e('Approve', 'sfs-hr'); ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:4px;">
                                    <input type="hidden" name="action" value="sfs_hr_leave_reject"/>
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonceR); ?>"/>
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"/>
                                    <button class="button button-small"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>
                                </form>
                            <?php elseif ($r['status'] === 'pending'): ?>
                                <span style="color:#666;"><?php esc_html_e('Not assigned to you', 'sfs-hr'); ?></span>
                            <?php else: ?>
                                <?php
                                    $by = $r['approver_id'] ? get_user_by('id', (int)$r['approver_id']) : null;
                                    echo esc_html($by ? $by->display_name : '—');
                                ?>
                            <?php endif; ?>
                        </td>
                        <td class="show-mobile">
                            <button type="button" class="sfs-hr-action-btn" onclick="sfsHrShowLeaveModal(<?php echo esc_attr($idx); ?>)">&#8942;</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($pages > 1): ?>
            <div class="sfs-hr-leave-pagination">
                <?php
                echo paginate_links([
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'current'   => $page,
                    'total'     => $pages,
                    'mid_size'  => 1,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'type'      => 'plain',
                ]);
                ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Mobile Modal -->
    <div id="sfs-hr-leave-modal" class="sfs-hr-leave-modal">
        <div class="sfs-hr-leave-modal-overlay" onclick="sfsHrCloseLeaveModal()"></div>
        <div class="sfs-hr-leave-modal-content">
            <div class="sfs-hr-leave-modal-header">
                <h3 id="sfs-hr-leave-modal-name"></h3>
                <button type="button" class="sfs-hr-leave-modal-close" onclick="sfsHrCloseLeaveModal()">&times;</button>
            </div>
            <div class="sfs-hr-leave-modal-row">
                <span class="sfs-hr-leave-modal-label"><?php esc_html_e('Type', 'sfs-hr'); ?></span>
                <span class="sfs-hr-leave-modal-value" id="sfs-hr-leave-modal-type"></span>
            </div>
            <div class="sfs-hr-leave-modal-row">
                <span class="sfs-hr-leave-modal-label"><?php esc_html_e('Dates', 'sfs-hr'); ?></span>
                <span class="sfs-hr-leave-modal-value" id="sfs-hr-leave-modal-dates"></span>
            </div>
            <div class="sfs-hr-leave-modal-row">
                <span class="sfs-hr-leave-modal-label"><?php esc_html_e('Days', 'sfs-hr'); ?></span>
                <span class="sfs-hr-leave-modal-value" id="sfs-hr-leave-modal-days"></span>
            </div>
            <div class="sfs-hr-leave-modal-row">
                <span class="sfs-hr-leave-modal-label"><?php esc_html_e('Status', 'sfs-hr'); ?></span>
                <span class="sfs-hr-leave-modal-value" id="sfs-hr-leave-modal-status"></span>
            </div>
            <div class="sfs-hr-leave-modal-row">
                <span class="sfs-hr-leave-modal-label"><?php esc_html_e('Reason', 'sfs-hr'); ?></span>
                <span class="sfs-hr-leave-modal-value" id="sfs-hr-leave-modal-reason"></span>
            </div>
            <div class="sfs-hr-leave-modal-row">
                <span class="sfs-hr-leave-modal-label"><?php esc_html_e('Submitted', 'sfs-hr'); ?></span>
                <span class="sfs-hr-leave-modal-value" id="sfs-hr-leave-modal-submitted"></span>
            </div>
            <div id="sfs-hr-leave-modal-actions" style="margin-top: 20px;"></div>
        </div>
    </div>

    <script>
    var sfsHrLeaveData = <?php echo wp_json_encode(array_values(array_map(function($r) use ($nonceA, $nonceR, $is_hr, $managed_depts, $current_uid) {
        $can_approve = false;
        if ($r['status'] === 'pending') {
            if ($is_hr) {
                $can_approve = true;
            } elseif (!empty($managed_depts) && in_array((int)$r['dept_id'], $managed_depts, true)) {
                $can_approve = true;
            }
            if ((int)($r['emp_user_id'] ?? 0) === $current_uid) {
                $can_approve = false;
            }
        }
        return [
            'id'        => (int)$r['id'],
            'name'      => trim($r['first_name'] . ' ' . $r['last_name']),
            'type'      => $r['type_name'],
            'dates'     => $r['start_date'] . ' → ' . $r['end_date'],
            'days'      => (int)$r['days'],
            'status'    => $r['status'],
            'reason'    => $r['reason'] ?: '—',
            'submitted' => $this->fmt_dt($r['created_at'] ?? ''),
            'canApprove'=> $can_approve,
            'nonceA'    => $nonceA,
            'nonceR'    => $nonceR,
        ];
    }, $rows))); ?>;

    function sfsHrShowLeaveModal(idx) {
        var data = sfsHrLeaveData[idx];
        if (!data) return;

        document.getElementById('sfs-hr-leave-modal-name').textContent = data.name;
        document.getElementById('sfs-hr-leave-modal-type').textContent = data.type;
        document.getElementById('sfs-hr-leave-modal-dates').textContent = data.dates;
        document.getElementById('sfs-hr-leave-modal-days').textContent = data.days;
        document.getElementById('sfs-hr-leave-modal-status').textContent = data.status;
        document.getElementById('sfs-hr-leave-modal-reason').textContent = data.reason;
        document.getElementById('sfs-hr-leave-modal-submitted').textContent = data.submitted;

        var actionsDiv = document.getElementById('sfs-hr-leave-modal-actions');
        if (data.status === 'pending' && data.canApprove) {
            actionsDiv.innerHTML = '<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;">' +
                '<input type="hidden" name="action" value="sfs_hr_leave_approve"/>' +
                '<input type="hidden" name="_wpnonce" value="' + data.nonceA + '"/>' +
                '<input type="hidden" name="id" value="' + data.id + '"/>' +
                '<button class="button button-primary" style="flex:1;"><?php esc_html_e('Approve', 'sfs-hr'); ?></button>' +
                '</form>' +
                '<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;margin-top:8px;">' +
                '<input type="hidden" name="action" value="sfs_hr_leave_reject"/>' +
                '<input type="hidden" name="_wpnonce" value="' + data.nonceR + '"/>' +
                '<input type="hidden" name="id" value="' + data.id + '"/>' +
                '<button class="button" style="flex:1;"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>' +
                '</form>';
        } else if (data.status === 'pending') {
            actionsDiv.innerHTML = '<p style="text-align:center;color:#666;"><?php esc_html_e('Not assigned to you', 'sfs-hr'); ?></p>';
        } else {
            actionsDiv.innerHTML = '';
        }

        document.getElementById('sfs-hr-leave-modal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function sfsHrCloseLeaveModal() {
        document.getElementById('sfs-hr-leave-modal').classList.remove('active');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            sfsHrCloseLeaveModal();
        }
    });
    </script>
    <?php
}

private function output_leave_requests_styles(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <style>
        /* Toolbar */
        .sfs-hr-leave-toolbar {
            background: #fff;
            border: 1px solid #e2e4e7;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .sfs-hr-leave-toolbar form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin: 0;
        }
        .sfs-hr-leave-toolbar input[type="search"] {
            height: 36px;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 0 12px;
            font-size: 13px;
            min-width: 200px;
        }
        .sfs-hr-leave-toolbar .button {
            height: 36px;
            line-height: 34px;
            padding: 0 16px;
        }

        /* Status Tabs */
        .sfs-hr-leave-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
        }
        .sfs-hr-leave-tabs .sfs-tab {
            display: inline-block;
            padding: 8px 16px;
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            color: #50575e;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .sfs-hr-leave-tabs .sfs-tab:hover {
            background: #fff;
            border-color: #2271b1;
            color: #2271b1;
        }
        .sfs-hr-leave-tabs .sfs-tab.active {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
        }
        .sfs-hr-leave-tabs .sfs-tab .count {
            display: inline-block;
            background: rgba(0,0,0,0.1);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            margin-left: 6px;
        }
        .sfs-hr-leave-tabs .sfs-tab.active .count {
            background: rgba(255,255,255,0.25);
        }

        /* Table Card */
        .sfs-hr-leave-table-wrap {
            background: #fff;
            border: 1px solid #e2e4e7;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .sfs-hr-leave-table-wrap .table-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f0f0f1;
            background: #f9fafb;
        }
        .sfs-hr-leave-table-wrap .table-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #1d2327;
        }
        .sfs-hr-leave-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        .sfs-hr-leave-table th {
            background: #f9fafb;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            color: #50575e;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 1px solid #e2e4e7;
        }
        .sfs-hr-leave-table td {
            padding: 14px 16px;
            font-size: 13px;
            border-bottom: 1px solid #f0f0f1;
            vertical-align: middle;
        }
        .sfs-hr-leave-table tbody tr:hover {
            background: #f9fafb;
        }
        .sfs-hr-leave-table tbody tr:last-child td {
            border-bottom: none;
        }
        .sfs-hr-leave-table .emp-name {
            display: block;
            font-weight: 500;
            color: #1d2327;
        }
        .sfs-hr-leave-table .emp-code {
            display: block;
            font-size: 11px;
            color: #787c82;
            margin-top: 2px;
        }

        /* Status Pills */
        .sfs-hr-pill--status-upcoming {
            background: #eff6ff;
            color: #1d4ed8;
        }
        .sfs-hr-pill--status-onleave {
            background: #fef3c7;
            color: #92400e;
        }
        .sfs-hr-pill--status-returned {
            background: #ecfdf3;
            color: #166534;
        }

        /* Action Button */
        .sfs-hr-action-btn {
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            transition: all 0.15s ease;
        }
        .sfs-hr-action-btn:hover {
            background: #fff;
            border-color: #2271b1;
        }

        /* Mobile Modal */
        .sfs-hr-leave-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 100000;
        }
        .sfs-hr-leave-modal.active {
            display: block;
        }
        .sfs-hr-leave-modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
        }
        .sfs-hr-leave-modal-content {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            border-radius: 16px 16px 0 0;
            padding: 24px;
            max-height: 80vh;
            overflow-y: auto;
            transform: translateY(100%);
            transition: transform 0.3s ease;
        }
        .sfs-hr-leave-modal.active .sfs-hr-leave-modal-content {
            transform: translateY(0);
        }
        .sfs-hr-leave-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e2e4e7;
        }
        .sfs-hr-leave-modal-header h3 {
            margin: 0;
            font-size: 18px;
            color: #1d2327;
        }
        .sfs-hr-leave-modal-close {
            background: #f6f7f7;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sfs-hr-leave-modal-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f1;
        }
        .sfs-hr-leave-modal-row:last-child {
            border-bottom: none;
        }
        .sfs-hr-leave-modal-label {
            color: #50575e;
            font-size: 13px;
        }
        .sfs-hr-leave-modal-value {
            font-weight: 500;
            color: #1d2327;
            font-size: 13px;
            text-align: right;
        }

        /* Pagination */
        .sfs-hr-leave-pagination {
            padding: 16px 20px;
            border-top: 1px solid #e2e4e7;
            background: #f9fafb;
            text-align: center;
        }
        .sfs-hr-leave-pagination .page-numbers {
            display: inline-block;
            padding: 6px 12px;
            margin: 0 2px;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            text-decoration: none;
            color: #2271b1;
            font-size: 13px;
        }
        .sfs-hr-leave-pagination .page-numbers.current {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
        }
        .sfs-hr-leave-pagination .page-numbers:hover:not(.current) {
            background: #f6f7f7;
        }

        /* Mobile Responsive */
        @media screen and (max-width: 782px) {
            .sfs-hr-leave-toolbar form {
                flex-direction: column;
                align-items: stretch;
            }
            .sfs-hr-leave-toolbar input[type="search"] {
                width: 100%;
                min-width: auto;
            }
            .sfs-hr-leave-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
                padding-bottom: 8px;
                -webkit-overflow-scrolling: touch;
            }
            .sfs-hr-leave-tabs .sfs-tab {
                flex-shrink: 0;
                padding: 6px 12px;
                font-size: 12px;
            }
            .hide-mobile {
                display: none !important;
            }
            .sfs-hr-leave-table th,
            .sfs-hr-leave-table td {
                padding: 12px;
            }
            .show-mobile {
                display: table-cell !important;
            }
        }
        @media screen and (min-width: 783px) {
            .show-mobile {
                display: none !important;
            }
        }
    </style>
    <?php
}

public function handle_approve(): void {
    check_admin_referer('sfs_hr_leave_approve');

    $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';

    $redirect_base = admin_url('admin.php?page=sfs-hr-leave-requests&tab=requests&status=pending');

    if ($id <= 0) {
        wp_safe_redirect($redirect_base);
        exit;
    }

    global $wpdb;
    $req_t   = $wpdb->prefix . 'sfs_hr_leave_requests';
    $types_t = $wpdb->prefix . 'sfs_hr_leave_types';
    $emp_t   = $wpdb->prefix . 'sfs_hr_employees';
    $bal_t   = $wpdb->prefix . 'sfs_hr_leave_balances';

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $req_t WHERE id=%d", $id),
        ARRAY_A
    );

    if ( ! $row || $row['status'] !== 'pending' ) {
        wp_safe_redirect($redirect_base);
        exit;
    }

    // Employee info
    $empInfo = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT user_id, dept_id, gender, COALESCE(hire_date, hired_at) AS hire
             FROM $emp_t WHERE id=%d",
            (int) $row['employee_id']
        ),
        ARRAY_A
    );

    $current_uid     = get_current_user_id();
    $is_hr           = current_user_can('sfs_hr.manage');
    $approval_level  = (int) ( $row['approval_level'] ?? 1 );

    // Nobody approves self
    if ( (int) ( $empInfo['user_id'] ?? 0 ) === $current_uid ) {
        wp_safe_redirect(
            add_query_arg(
                'err',
                rawurlencode(__('You cannot approve your own leave request.', 'sfs-hr')),
                $redirect_base
            )
        );
        exit;
    }

    // If not HR → must be dept manager of this employee
    if ( ! $is_hr ) {
        $managed = $this->manager_dept_ids_for_user($current_uid);
        if ( empty($managed) || ! in_array((int) ($empInfo['dept_id'] ?? 0), $managed, true) ) {
            wp_safe_redirect(
                add_query_arg(
                    'err',
                    rawurlencode(__('You can only review requests for your department.', 'sfs-hr')),
                    $redirect_base
                )
            );
            exit;
        }
    }

    // Manager stage (first approval) → escalate to HR, do NOT finalize, no balance changes
    if ( ! $is_hr ) {
        if ( $approval_level >= 2 ) {
            // Already escalated to HR
            wp_safe_redirect(
                add_query_arg(
                    'err',
                    rawurlencode(__('This request is already waiting for HR approval.', 'sfs-hr')),
                    $redirect_base
                )
            );
            exit;
        }

        $new_chain = $this->append_approval_chain(
            $row['approval_chain'] ?? null,
            [
                'by'     => $current_uid,
                'role'   => 'manager',
                'action' => 'approve',
                'note'   => $note,
            ]
        );

        $wpdb->update(
            $req_t,
            [
                'approval_level' => 2,
                'approver_id'    => $current_uid,
                'approver_note'  => $note,
                'approval_chain' => $new_chain,
                'updated_at'     => Helpers::now_mysql(),
            ],
            ['id' => $id],
            ['%d','%d','%s','%s','%s'],
            ['%d']
        );

        // Notify HR approvers
        $this->email_approvers_for_employee(
            (int) $row['employee_id'],
            __('Leave request waiting HR approval', 'sfs-hr'),
            sprintf(
                __('Manager approved leave request (%s → %s, %d days). Please review.', 'sfs-hr'),
                $row['start_date'],
                $row['end_date'],
                (int) $row['days']
            )
        );

        wp_safe_redirect(
            add_query_arg(
                'ok',
                1,
                $redirect_base
            )
        );
        exit;
    }

    // ---------------- HR stage (final) ----------------

    // Enforce manager-first if a manager exists for this employee's department
    $dept_has_manager = false;
    if ( ! empty( $empInfo['dept_id'] ) ) {
        $dept_t  = $wpdb->prefix . 'sfs_hr_departments';
        $mgr_uid = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT manager_user_id FROM $dept_t WHERE id=%d AND active=1",
                (int) $empInfo['dept_id']
            )
        );
        if ( $mgr_uid > 0 ) {
            $dept_has_manager = true;
        }
    }

    if ( $dept_has_manager && $approval_level < 2 ) {
        wp_safe_redirect(
            add_query_arg(
                'err',
                rawurlencode( __('Department manager must approve before HR.', 'sfs-hr') ),
                $redirect_base
            )
        );
        exit;
    }

    // Fetch type & apply all your existing business rules (maternity, annual, negative, probation, etc.)
    $type = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, annual_quota, allow_negative, is_annual, special_code
             FROM $types_t WHERE id=%d",
            (int) $row['type_id']
        ),
        ARRAY_A
    );
    if ( ! $type ) {
        wp_safe_redirect(
            add_query_arg(
                'err',
                rawurlencode(__('Invalid leave type.', 'sfs-hr')),
                $redirect_base
            )
        );
        exit;
    }

    // Example guard: maternity only for female
    $special = strtoupper(trim($type['special_code'] ?? ''));
    if ($special === 'MATERNITY' && strtolower((string)($empInfo['gender'] ?? '')) !== 'female') {
        wp_safe_redirect(
            add_query_arg(
                'err',
                rawurlencode(__('Maternity leave is available only to female employees.', 'sfs-hr')),
                $redirect_base
            )
        );
        exit;
    }

    // === Existing quota / balance logic ===
    $year  = (int) substr($row['start_date'], 0, 4);
    $quota = (int) ($type['annual_quota'] ?? 0);

    // Finalize: mark approved
    $new_chain = $this->append_approval_chain(
        $row['approval_chain'] ?? null,
        [
            'by'     => $current_uid,
            'role'   => 'hr',
            'action' => 'approve',
            'note'   => $note,
        ]
    );

    $wpdb->update(
        $req_t,
        [
            'status'         => 'approved',
            'approval_level' => max(2, $approval_level),
            'approver_id'    => $current_uid,
            'approver_note'  => $note,
            'approval_chain' => $new_chain,
            'decided_at'     => Helpers::now_mysql(),
            'updated_at'     => Helpers::now_mysql(),
        ],
        ['id' => $id]
    );

    // Audit Trail: leave request approved
    do_action('sfs_hr_leave_request_status_changed', $id, 'pending', 'approved');

    // Recalculate yearly used + closing balance for this type
    $used = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COALESCE(SUM(days),0)
             FROM $req_t
             WHERE employee_id=%d
               AND type_id=%d
               AND status='approved'
               AND YEAR(start_date)=%d",
            (int) $row['employee_id'],
            (int) $row['type_id'],
            $year
        )
    );

    $opening = 0;
    $carried = 0;
    $accrued = $quota;
    $closing = $opening + $accrued + $carried - $used;

    $bal_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $bal_t WHERE employee_id=%d AND type_id=%d AND year=%d",
            (int) $row['employee_id'],
            (int) $row['type_id'],
            $year
        )
    );

    if ($bal_id) {
        $wpdb->update(
            $bal_t,
            [
                'opening'      => $opening,
                'accrued'      => $accrued,
                'used'         => $used,
                'carried_over' => $carried,
                'closing'      => $closing,
                'updated_at'   => Helpers::now_mysql(),
            ],
            ['id' => $bal_id]
        );
    } else {
        $wpdb->insert(
            $bal_t,
            [
                'employee_id'  => (int) $row['employee_id'],
                'type_id'      => (int) $row['type_id'],
                'year'         => $year,
                'opening'      => $opening,
                'accrued'      => $accrued,
                'used'         => $used,
                'carried_over' => $carried,
                'closing'      => $closing,
                'updated_at'   => Helpers::now_mysql(),
            ]
        );
    }

    // Notify employee
    $this->notify_requester(
        (int) $row['employee_id'],
        __('Leave Approved', 'sfs-hr'),
        sprintf(
            __('Your leave request (%s → %s) has been approved. Note: %s', 'sfs-hr'),
            $row['start_date'],
            $row['end_date'],
            $note ?: __('None', 'sfs-hr')
        )
    );

    wp_safe_redirect(
        add_query_arg('ok', 1, $redirect_base)
    );
    exit;
}



public function handle_reject(): void {
    Helpers::require_cap('sfs_hr.leave.review');
    check_admin_referer('sfs_hr_leave_reject');

    $id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';
    if ($id<=0) wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=requests&status=pending'));

    global $wpdb;
    $req_t = $wpdb->prefix.'sfs_hr_leave_requests';
    $emp_t = $wpdb->prefix.'sfs_hr_employees';

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $req_t WHERE id=%d", $id), ARRAY_A);
    if (!$row || $row['status']!=='pending') wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=requests&status=pending'));

    // Guard: dept manager scope + self reject
    $empInfo = $wpdb->get_row($wpdb->prepare("SELECT user_id, dept_id FROM $emp_t WHERE id=%d", (int)$row['employee_id']), ARRAY_A);
    $current_uid = get_current_user_id();
    if ((int)($empInfo['user_id'] ?? 0) === (int)$current_uid) {
        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=requests&status=pending&err='.rawurlencode(__('You cannot reject your own request.','sfs-hr')))); exit;
    }
    if ( ! current_user_can('sfs_hr.manage') ) {
        $managed = $this->manager_dept_ids_for_user($current_uid);
        if (empty($managed) || !in_array((int)($empInfo['dept_id'] ?? 0), $managed, true)) {
            wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=requests&status=pending&err='.rawurlencode(__('You can only review requests in your department.','sfs-hr')))); exit;
        }
    }

    $wpdb->update($req_t, [
        'status'       => 'rejected',
        'approver_id'  => $current_uid,
        'approver_note'=> $note,
        'decided_at'   => Helpers::now_mysql(),
        'updated_at'   => Helpers::now_mysql(),
    ], ['id'=>$id]);

    // Audit Trail: leave request rejected
    do_action('sfs_hr_leave_request_status_changed', $id, 'pending', 'rejected');

    $this->notify_requester((int)$row['employee_id'], __('Leave Rejected','sfs-hr'),
        sprintf(__('Your leave request (%s → %s) has been rejected. Reason: %s','sfs-hr'),
            $row['start_date'], $row['end_date'], $note ?: __('Not specified','sfs-hr')));

    wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=requests&status=pending&ok=1')); exit;
}


    /* ---------------------------------- Types & Balances (Admin) ---------------------------------- */

    public function render_types(): void {
        Helpers::require_cap('sfs_hr.leave.manage');
        global $wpdb;
        $types_t = $wpdb->prefix.'sfs_hr_leave_types';
        $rows = $wpdb->get_results("SELECT * FROM $types_t WHERE active=1 ORDER BY id ASC", ARRAY_A);
        $nonce_add = wp_create_nonce('sfs_hr_leave_addtype');
        $nonce_del = wp_create_nonce('sfs_hr_leave_deltype');
        $nonce_mark = wp_create_nonce('sfs_hr_leave_markannual');
        ?>
                        
        <div class="wrap">
          <h2 class="title"><?php esc_html_e('Leave Types','sfs-hr'); ?></h2>


          <?php if(!empty($_GET['err'])): ?>
            <div class="notice notice-error"><p><?php echo esc_html($_GET['err']); ?></p></div>
          <?php endif; if(!empty($_GET['ok'])): ?>
            <div class="notice notice-success"><p><?php esc_html_e('Saved.','sfs-hr'); ?></p></div>
          <?php endif; ?>

          <table class="widefat striped">
            <thead><tr>
              <th><?php esc_html_e('ID','sfs-hr'); ?></th>
              <th><?php esc_html_e('Name','sfs-hr'); ?></th>
              <th><?php esc_html_e('Paid','sfs-hr'); ?></th>
              <th><?php esc_html_e('Requires Approval','sfs-hr'); ?></th>
              <th><?php esc_html_e('Annual Quota','sfs-hr'); ?></th>
              <th><?php esc_html_e('Allow Negative','sfs-hr'); ?></th>
              <th><?php esc_html_e('Annual (Tenure)','sfs-hr'); ?></th>
              <th><?php esc_html_e('Special Policy','sfs-hr'); ?></th>
              <th><?php esc_html_e('Actions','sfs-hr'); ?></th>
            </tr></thead>
            <tbody>
              <?php if(!$rows): ?>
                <tr><td colspan="9"><?php esc_html_e('No types.','sfs-hr'); ?></td></tr>
              <?php else: foreach($rows as $r): 
                $looks_annual = (stripos($r['name'],'annual') !== false);
              ?>
                <tr>
                  <td><?php echo (int)$r['id']; ?></td>
                  <td><?php echo esc_html($r['name']); ?></td>
                  <td><?php echo $r['is_paid']?'✔':'—'; ?></td>
                  <td><?php echo $r['requires_approval']?'✔':'—'; ?></td>
                  <td><?php printf('%d', (int)$r['annual_quota']); ?></td>
                  <td><?php echo !empty($r['allow_negative'])?'✔':'—'; ?></td>
                  <td><?php echo !empty($r['is_annual'])?'✔':'—'; ?></td>
                  <td><?php echo esc_html($r['special_code'] ?: '—'); ?></td>
                  <td>
                    <div style="display:flex;gap:6px;">
                      <?php if(!$r['is_annual'] && $looks_annual): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                          <input type="hidden" name="action" value="sfs_hr_leave_markannual"/>
                          <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_mark); ?>"/>
                          <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"/>
                          <button class="button button-small"><?php esc_html_e('Mark Annual','sfs-hr'); ?></button>
                        </form>
                      <?php endif; ?>
                      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_attr__('Delete this type?','sfs-hr'); ?>');">
                        <input type="hidden" name="action" value="sfs_hr_leave_deltype"/>
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_del); ?>"/>
                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"/>
                        <button class="button button-small"><?php esc_html_e('Delete','sfs-hr'); ?></button>
                      </form>
                    </div>
                  </td>
                </tr>
                
                
              <?php endforeach; endif; ?>
            </tbody>

          </table>

          <h2 style="margin-top:18px;"><?php esc_html_e('Add Type','sfs-hr'); ?></h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sfs_hr_leave_addtype"/>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_add); ?>"/>
            <table class="form-table">
              <tr><th><?php esc_html_e('Name','sfs-hr'); ?></th><td><input name="name" class="regular-text" required/></td></tr>
              <tr><th><?php esc_html_e('Paid','sfs-hr'); ?></th><td><label><input type="checkbox" name="is_paid" value="1" checked/> <?php esc_html_e('Paid leave','sfs-hr'); ?></label></td></tr>
              <tr><th><?php esc_html_e('Requires Approval','sfs-hr'); ?></th><td><label><input type="checkbox" name="requires_approval" value="1" checked/> <?php esc_html_e('Yes','sfs-hr'); ?></label></td></tr>
              <tr><th><?php esc_html_e('Annual Quota (fallback)','sfs-hr'); ?></th><td><input type="number" name="annual_quota" min="0" value="30" style="width:120px"/><br><small><?php esc_html_e('Used for non-annual types or when hire date is missing.','sfs-hr'); ?></small></td></tr>
              <tr><th><?php esc_html_e('Allow Negative','sfs-hr'); ?></th><td><label><input type="checkbox" name="allow_negative" value="1"/> <?php esc_html_e('Allow going below 0','sfs-hr'); ?></label></td></tr>
              <tr><th><?php esc_html_e('Annual (Tenure-based)','sfs-hr'); ?></th><td><label><input type="checkbox" name="is_annual" value="1"/> <?php esc_html_e('Apply <5y/≥5y policy','sfs-hr'); ?></label></td></tr>
              <tr>
  <th><?php esc_html_e('Special Policy','sfs-hr'); ?></th>
  <td>
    <select name="special_code">
      <option value=""><?php esc_html_e('— None —','sfs-hr'); ?></option>
      <option value="SICK_SHORT"><?php esc_html_e('Sick (Short) – ≤ 29 days','sfs-hr'); ?></option>
      <option value="SICK_LONG"><?php esc_html_e('Sick (Long) – 30–120 days (tiered pay)','sfs-hr'); ?></option>
      <option value="HAJJ"><?php esc_html_e('Hajj – one time, 10 days','sfs-hr'); ?></option>
      <option value="MATERNITY"><?php esc_html_e('Maternity – 10 weeks','sfs-hr'); ?></option>
      <option value="MARRIAGE"><?php esc_html_e('Marriage – 5 days','sfs-hr'); ?></option>
      <option value="BEREAVEMENT"><?php esc_html_e('Bereavement – 5 days','sfs-hr'); ?></option>
      <option value="PATERNITY"><?php esc_html_e('Paternity – 3 days','sfs-hr'); ?></option>
    </select>
  </td>
</tr>

            </table>
            <?php submit_button(__('Save','sfs-hr')); ?>
          </form>
        </div>
        <?php
    }

    public function handle_mark_annual(): void {
        Helpers::require_cap('sfs_hr.leave.manage');
        check_admin_referer('sfs_hr_leave_markannual');
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id<=0) wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=types'));
        global $wpdb; $t = $wpdb->prefix.'sfs_hr_leave_types';
        $wpdb->update($t, ['is_annual'=>1, 'updated_at'=>Helpers::now_mysql()], ['id'=>$id]);
        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=types&ok=1')); exit;
    }

    public function render_balances(): void {
        Helpers::require_cap('sfs_hr.leave.manage');
        global $wpdb;
        $year = isset($_GET['year']) ? max(2000, (int)$_GET['year']) : (int)current_time('Y');
        $bal = $wpdb->prefix.'sfs_hr_leave_balances';
        $emp = $wpdb->prefix.'sfs_hr_employees';
        $typ = $wpdb->prefix.'sfs_hr_leave_types';
        $nonce = wp_create_nonce('sfs_hr_leave_update_balance');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, e.employee_code, e.first_name, e.last_name, t.name AS type_name
             FROM $bal b
             JOIN $emp e ON e.id = b.employee_id
             JOIN $typ t ON t.id = b.type_id
             WHERE b.year=%d
             ORDER BY e.employee_code ASC, t.name ASC",
            $year
        ), ARRAY_A);

        ?>
        
        <div class="wrap">
          <h2 class="title"><?php esc_html_e('Leave Balances','sfs-hr'); ?></h2>
          <?php if(!empty($_GET['ok'])): ?>
            <div class="notice notice-success"><p><?php esc_html_e('Balance updated.','sfs-hr'); ?></p></div>
          <?php endif; if(!empty($_GET['err'])): ?>
            <div class="notice notice-error"><p><?php echo esc_html($_GET['err']); ?></p></div>
          <?php endif; ?>
          <form method="get" style="margin-bottom:12px;">
            <input type="hidden" name="page" value="sfs-hr-leave-requests"/>
            <input type="hidden" name="tab"  value="balances"/>
            <label><?php esc_html_e('Year','sfs-hr'); ?>:
              <input type="number" name="year" value="<?php echo (int)$year; ?>" min="2000" style="width:100px"/>
            </label>
            <?php submit_button(__('Filter','sfs-hr'), 'secondary', '', false); ?>
          </form>

          <table class="widefat striped">
            <thead><tr>
              <th><?php esc_html_e('Employee','sfs-hr'); ?></th>
              <th><?php esc_html_e('Type','sfs-hr'); ?></th>
              <th><?php esc_html_e('Opening','sfs-hr'); ?></th>
              <th><?php esc_html_e('Accrued','sfs-hr'); ?></th>
              <th><?php esc_html_e('Used','sfs-hr'); ?></th>
              <th><?php esc_html_e('Carry Over','sfs-hr'); ?></th>
              <th><?php esc_html_e('Closing','sfs-hr'); ?></th>
              <th><?php esc_html_e('Adjust','sfs-hr'); ?></th>
            </tr></thead>
            <tbody>
              <?php if(!$rows): ?>
                <tr><td colspan="8"><?php esc_html_e('No data for selected year.','sfs-hr'); ?></td></tr>
              <?php else: foreach($rows as $r): 
                    $emp_label = trim(($r['first_name']??'').' '.($r['last_name']??'')) . ' ('. $r['employee_code'] .')';
                    $closing = (int)$r['opening'] + (int)$r['accrued'] + (int)$r['carried_over'] - (int)$r['used'];
              ?>
                <tr>
                  <td><?php echo esc_html($emp_label); ?></td>
                  <td><?php echo esc_html($r['type_name']); ?></td>
                  <td><?php printf('%d',(int)$r['opening']); ?></td>
                  <td><?php printf('%d',(int)$r['accrued']); ?></td>
                  <td><?php printf('%d',(int)$r['used']); ?></td>
                  <td><?php printf('%d',(int)$r['carried_over']); ?></td>
                  <td><strong><?php printf('%d',(int)$closing); ?></strong></td>
                  <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:6px; align-items:center;">
                      <input type="hidden" name="action" value="sfs_hr_leave_update_balance"/>
                      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>"/>
                      <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"/>
                      <input type="number" name="opening"      value="<?php echo (int)$r['opening']; ?>"      style="width:80px"/>
                      <input type="number" name="accrued"      value="<?php echo (int)$r['accrued']; ?>"      style="width:80px"/>
                      <input type="number" name="carried_over" value="<?php echo (int)$r['carried_over']; ?>" style="width:100px"/>
                      <button class="button button-small"><?php esc_html_e('Save','sfs-hr'); ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }

    public function handle_add_type(): void {
    Helpers::require_cap('sfs_hr.leave.manage');
    check_admin_referer('sfs_hr_leave_addtype');

    global $wpdb;
    $t = $wpdb->prefix . 'sfs_hr_leave_types';

    $name    = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $is_paid = !empty($_POST['is_paid']) ? 1 : 0;
    $req     = !empty($_POST['requires_approval']) ? 1 : 0;
    $quota   = isset($_POST['annual_quota']) ? max(0, (int) $_POST['annual_quota']) : 30;
    $allow_n = !empty($_POST['allow_negative']) ? 1 : 0;
    $is_ann  = !empty($_POST['is_annual']) ? 1 : 0;
    $special = isset($_POST['special_code']) ? sanitize_text_field($_POST['special_code']) : '';

    $allowed = ['', 'SICK_SHORT','SICK_LONG','HAJJ','MATERNITY','MARRIAGE','BEREAVEMENT','PATERNITY'];
    if ( ! in_array($special, $allowed, true) ) {
        $special = '';
    }

    if ( ! $name ) {
        wp_safe_redirect(
            admin_url('admin.php?page=sfs-hr-leave-requests&tab=types&err=' . rawurlencode(__('Name required','sfs-hr')))
        );
        exit;
    }

    // 🔹 Generate a unique slug “code” for this type
    $base_code = sanitize_title($name);
    if ($base_code === '') {
        $base_code = 'type';
    }
    $base_code = substr($base_code, 0, 64);

    $code = $base_code;
    $i    = 2;
    while ( (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $t WHERE code=%s", $code) ) > 0 ) {
        $suffix = '-' . $i;
        $code   = substr($base_code, 0, max(1, 64 - strlen($suffix))) . $suffix;
        $i++;
    }

    $now = current_time('mysql');
    $ins = $wpdb->insert(
        $t,
        [
            'name'           => $name,
            'is_paid'        => $is_paid,
            'code'           => $code,
            'requires_approval' => $req,
            'annual_quota'   => $quota,
            'allow_negative' => $allow_n,
            'is_annual'      => $is_ann,
            'active'         => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
            'special_code'   => ($special ?: null),
        ]
    );

    if ( $ins === false ) {
        error_log('[SFS HR] Leave type insert failed: ' . $wpdb->last_error);
        wp_safe_redirect(
            admin_url(
                'admin.php?page=sfs-hr-leave-requests&tab=types&err=' .
                rawurlencode($wpdb->last_error ?: 'DB insert failed')
            )
        );
        exit;
    }

    wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=types&ok=1'));
    exit;
}


    public function handle_delete_type(): void {
        Helpers::require_cap('sfs_hr.leave.manage');
        check_admin_referer('sfs_hr_leave_deltype');
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id<=0) wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=types'));
        global $wpdb; 
        $req = $wpdb->prefix.'sfs_hr_leave_requests';
        $in_use = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $req WHERE type_id=%d", $id));
        if ($in_use>0) { wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=types&err='.rawurlencode(__('Type in use; cannot delete','sfs-hr')))); exit; }
        $types = $wpdb->prefix.'sfs_hr_leave_types';
        $wpdb->delete($types, ['id'=>$id]);
        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=types&ok=1')); exit;
    }

    /* ---------------------------------- Settings + Holidays ---------------------------------- */

    public function render_settings(): void {
        Helpers::require_cap('sfs_hr.leave.manage');

        $enabled_email = get_option('sfs_hr_leave_email', '1') === '1';

        // Tenure policy
        $lt5 = (int)get_option('sfs_hr_annual_lt5','21');
        $ge5 = (int)get_option('sfs_hr_annual_ge5','30');

        // Holiday notifications
        $notify_on_add   = get_option('sfs_hr_holiday_notify_on_add','0') === '1';
        $reminder_enable = get_option('sfs_hr_holiday_reminder_enabled','0') === '1';
        $reminder_days   = (int)get_option('sfs_hr_holiday_reminder_days','0');

        $nonce = wp_create_nonce('sfs_hr_leave_settings');

        $holidays = $this->get_holidays_option();
        $nonce_add = wp_create_nonce('sfs_hr_holiday_add');
        $nonce_del = wp_create_nonce('sfs_hr_holiday_del');
        ?>
        <div class="wrap">
          <h2 class="title"><?php esc_html_e('Leave Settings','sfs-hr'); ?></h2>
          <?php if(!empty($_GET['ok'])): ?>
            <div class="notice notice-success"><p><?php esc_html_e('Settings saved.','sfs-hr'); ?></p></div>
          <?php endif; if(!empty($_GET['err'])): ?>
            <div class="notice notice-error"><p><?php echo esc_html($_GET['err']); ?></p></div>
          <?php endif; ?>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:24px;">
            <input type="hidden" name="action" value="sfs_hr_leave_settings"/>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>"/>
            <table class="form-table">
              <tr>
                <th><?php esc_html_e('Email Notifications','sfs-hr'); ?></th>
                <td><label><input type="checkbox" name="leave_email" value="1" <?php checked($enabled_email, true); ?>/> <?php esc_html_e('Send emails on approve/reject','sfs-hr'); ?></label></td>
              </tr>
              <tr>
                <th><?php esc_html_e('Annual Leave Policy','sfs-hr'); ?></th>
                <td>
                  <label><?php esc_html_e('< 5 years','sfs-hr'); ?>:
                    <input type="number" name="annual_lt5" min="0" value="<?php echo (int)$lt5; ?>" style="width:100px"/>
                  </label>
                  &nbsp;&nbsp;
                  <label><?php esc_html_e('≥ 5 years','sfs-hr'); ?>:
                    <input type="number" name="annual_ge5" min="0" value="<?php echo (int)$ge5; ?>" style="width:100px"/>
                  </label>
                  <p class="description"><?php esc_html_e('Applied only to leave types marked "Annual (Tenure-based)". Uses employee hire_date or hired_at; if both missing, falls back to the type’s Annual Quota.','sfs-hr'); ?></p>
                </td>
              </tr>
              <tr>
                <th><?php esc_html_e('Holiday Notifications','sfs-hr'); ?></th>
                <td>
                  <label><input type="checkbox" name="holiday_notify_on_add" value="1" <?php checked($notify_on_add, true); ?>/> <?php esc_html_e('Email all employees when a holiday is added','sfs-hr'); ?></label><br/>
                  <label><input type="checkbox" name="holiday_reminder_enabled" value="1" <?php checked($reminder_enable, true); ?>/> <?php esc_html_e('Send automatic reminders for upcoming holidays','sfs-hr'); ?></label>
                  &nbsp; &nbsp;
                  <label><?php esc_html_e('Days before','sfs-hr'); ?>:
                    <input type="number" name="holiday_reminder_days" min="1" value="<?php echo (int)$reminder_days; ?>" style="width:80px"/>
                  </label>
                  <p class="description"><?php esc_html_e('Reminders run daily. We’ll avoid duplicates automatically.','sfs-hr'); ?></p>
                </td>
              </tr>
            </table>
            <?php submit_button(__('Save Settings','sfs-hr')); ?>
          </form>

          <h2><?php esc_html_e('Company Holidays','sfs-hr'); ?></h2>
          <p class="description"><?php esc_html_e('Fridays are always excluded from business days. Add single-day or multi-day holidays below. Use "Repeats yearly" for recurring ranges (e.g., Eid, National Day).','sfs-hr'); ?></p>

          <table class="widefat striped" style="max-width:900px;">
            <thead><tr>
              <th><?php esc_html_e('Start','sfs-hr'); ?></th>
              <th><?php esc_html_e('End','sfs-hr'); ?></th>
              <th><?php esc_html_e('Name','sfs-hr'); ?></th>
              <th><?php esc_html_e('Repeats yearly','sfs-hr'); ?></th>
              <th><?php esc_html_e('Actions','sfs-hr'); ?></th>
            </tr></thead>
            <tbody>
              <?php if(!$holidays): ?>
                <tr><td colspan="5"><?php esc_html_e('No holidays defined.','sfs-hr'); ?></td></tr>
              <?php else: foreach($holidays as $i=>$h): 
                    $start = isset($h['start']) ? $h['start'] : ($h['date'] ?? '');
                    $end   = isset($h['end'])   ? $h['end']   : ($h['date'] ?? '');
              ?>
                <tr>
                  <td><?php echo esc_html($start); ?></td>
                  <td><?php echo esc_html($end); ?></td>
                  <td><?php echo esc_html($h['name']); ?></td>
                  <td><?php echo !empty($h['repeat']) ? '✔' : '—'; ?></td>
                  <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_attr__('Remove this holiday?','sfs-hr'); ?>');" style="display:inline;">
                      <input type="hidden" name="action" value="sfs_hr_holiday_del"/>
                      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_del); ?>"/>
                      <input type="hidden" name="idx" value="<?php echo (int)$i; ?>"/>
                      <button class="button button-small"><?php esc_html_e('Delete','sfs-hr'); ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>

          <h3 style="margin-top:14px;"><?php esc_html_e('Add Holiday','sfs-hr'); ?></h3>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px;">
            <input type="hidden" name="action" value="sfs_hr_holiday_add"/>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_add); ?>"/>
            <table class="form-table">
              <tr>
                <th><?php esc_html_e('Start date','sfs-hr'); ?></th>
                <td><input type="date" name="start" required/></td>
              </tr>
              <tr>
                <th><?php esc_html_e('End date','sfs-hr'); ?></th>
                <td><input type="date" name="end"/><br><small><?php esc_html_e('Optional; leave empty for a single-day holiday.','sfs-hr'); ?></small></td>
              </tr>
              <tr>
                <th><?php esc_html_e('Name','sfs-hr'); ?></th>
                <td><input type="text" name="name" class="regular-text" required/></td>
              </tr>
              <tr>
                <th><?php esc_html_e('Repeats yearly','sfs-hr'); ?></th>
                <td><label><input type="checkbox" name="repeat" value="1"/> <?php esc_html_e('Yes','sfs-hr'); ?></label></td>
              </tr>
            </table>
            <?php submit_button(__('Add','sfs-hr')); ?>
          </form>
        </div>
        <?php
    }

    public function handle_settings(): void {
        Helpers::require_cap('sfs_hr.leave.manage');
        check_admin_referer('sfs_hr_leave_settings');

        update_option('sfs_hr_leave_email', !empty($_POST['leave_email']) ? '1' : '0');

        $lt5 = isset($_POST['annual_lt5']) ? max(0,(int)$_POST['annual_lt5']) : 21;
        $ge5 = isset($_POST['annual_ge5']) ? max(0,(int)$_POST['annual_ge5']) : 30;
        update_option('sfs_hr_annual_lt5', (string)$lt5);
        update_option('sfs_hr_annual_ge5', (string)$ge5);

        $notify_on_add   = !empty($_POST['holiday_notify_on_add']) ? '1' : '0';
        $reminder_enable = !empty($_POST['holiday_reminder_enabled']) ? '1' : '0';
        $reminder_days   = isset($_POST['holiday_reminder_days']) ? max(1,(int)$_POST['holiday_reminder_days']) : 0;

        update_option('sfs_hr_holiday_notify_on_add', $notify_on_add);
        update_option('sfs_hr_holiday_reminder_enabled', $reminder_enable);
        update_option('sfs_hr_holiday_reminder_days', (string)$reminder_days);

        // reschedule cron if needed
        $this->ensure_cron(true);

        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=settings&ok=1')); exit;
    }

    public function handle_holiday_add(): void {
        Helpers::require_cap('sfs_hr.leave.manage');
        check_admin_referer('sfs_hr_holiday_add');

        $start = isset($_POST['start']) ? sanitize_text_field($_POST['start']) : '';
        $end   = isset($_POST['end'])   ? sanitize_text_field($_POST['end'])   : '';
        $name  = isset($_POST['name'])  ? sanitize_text_field($_POST['name'])  : '';
        $rep   = !empty($_POST['repeat']) ? 1 : 0;

        if (!$start || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !$name) {
            wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=settings&err='.rawurlencode(__('Invalid holiday','sfs-hr')))); exit;
        }
        if ($end && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = '';
        if (!$end) $end = $start;
        if ($end < $start) { $tmp = $start; $start = $end; $end = $tmp; }

        $list = $this->get_holidays_option();
        $list[] = ['start'=>$start,'end'=>$end,'name'=>$name,'repeat'=>$rep];
        update_option('sfs_hr_holidays', $list, false);

        if (get_option('sfs_hr_holiday_notify_on_add','0')==='1') {
            $this->broadcast_holiday_added($start, $end, $name, $rep);
        }

        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=settings&ok=1')); exit;
    }

    public function handle_holiday_del(): void {
        Helpers::require_cap('sfs_hr.leave.manage');
        check_admin_referer('sfs_hr_holiday_del');
        $idx = isset($_POST['idx']) ? (int)$_POST['idx'] : -1;
        $list = $this->get_holidays_option();
        if ($idx>=0 && isset($list[$idx])) {
            array_splice($list, $idx, 1);
            update_option('sfs_hr_holidays', $list, false);
            wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=settings&ok=1')); exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=settings&err='.rawurlencode(__('Not found','sfs-hr')))); exit;
    }

    /* ---------------------------------- Frontend Shortcodes ---------------------------------- */

public function shortcode_leave_widget($atts = []): string {
    if ( ! is_user_logged_in() ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__('Please log in to view your leave dashboard.','sfs-hr') . '</div>';
    }

    global $wpdb;
    $uid = get_current_user_id();
    $emp = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, first_name, last_name FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id=%d",
            $uid
        ),
        ARRAY_A
    );

    if ( ! $emp ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__('Your HR profile is not linked. Please contact HR.','sfs-hr') . '</div>';
    }

    $emp_id = (int) $emp['id'];
    $year   = (int) current_time('Y');

    $bal_t = $wpdb->prefix . 'sfs_hr_leave_balances';
    $typ_t = $wpdb->prefix . 'sfs_hr_leave_types';
    $req_t = $wpdb->prefix . 'sfs_hr_leave_requests';

    // Balances for current year
    $balances = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT b.*, t.name, t.is_annual
             FROM $bal_t b
             JOIN $typ_t t ON t.id = b.type_id
             WHERE b.employee_id=%d AND b.year=%d
             ORDER BY t.is_annual DESC, t.name ASC",
            $emp_id,
            $year
        ),
        ARRAY_A
    );

    // Recent requests (for KPI + list)
    $requests = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT r.*, t.name AS type_name
         FROM $req_t r
         JOIN $typ_t t ON t.id = r.type_id
         WHERE r.employee_id=%d
         ORDER BY r.created_at DESC, r.id DESC
         LIMIT 10",
        $emp_id
    ),
    ARRAY_A
);
    $requests_count = count($requests);

    // KPI totals
    $total_available   = 0;
    $total_used        = 0;
    $annual_available  = null;

    foreach ( $balances as $b ) {
        $avail = (int) ( $b['closing'] ?? 0 );
        $used  = (int) ( $b['used'] ?? 0 );
        $total_available += $avail;
        $total_used      += $used;

        if ( $annual_available === null && ! empty( $b['is_annual'] ) ) {
            $annual_available = $avail;
        }
    }

    ob_start();
    ?>
    <div class="sfs-hr sfs-hr-leave-widget">
      <div class="sfs-hr-lw-header">
        <div>
          <h3><?php echo esc_html__('My Leave Dashboard','sfs-hr'); ?></h3>
          <p class="sfs-hr-lw-sub">
              <?php
              printf(
                  esc_html__('Employee: %s %s · Year: %d','sfs-hr'),
                  esc_html($emp['first_name'] ?? ''),
                  esc_html($emp['last_name'] ?? ''),
                  $year
              );
              ?>
          </p>
        </div>
      </div>

      <?php if ( ! empty( $balances ) ) : ?>
      <div class="sfs-hr-lw-kpis">
        <div class="sfs-hr-lw-kpi">
            <span class="sfs-hr-lw-kpi-label"><?php esc_html_e('Requests','sfs-hr'); ?></span>
            <span class="sfs-hr-lw-kpi-value"><?php echo esc_html( $requests_count ); ?></span>
        </div>
        <div class="sfs-hr-lw-kpi">
            <span class="sfs-hr-lw-kpi-label"><?php esc_html_e('Annual Leave Available','sfs-hr'); ?></span>
            <span class="sfs-hr-lw-kpi-value">
                <?php echo esc_html( $annual_available !== null ? $annual_available : '-' ); ?>
            </span>
        </div>
        <div class="sfs-hr-lw-kpi">
            <span class="sfs-hr-lw-kpi-label"><?php esc_html_e('Total Used','sfs-hr'); ?></span>
            <span class="sfs-hr-lw-kpi-value"><?php echo esc_html( $total_used ); ?></span>
        </div>
      </div>
      <?php endif; ?>

      <div class="sfs-hr-lw-card sfs-hr-lw-request-card">
        <h4><?php esc_html_e('Request New Leave','sfs-hr'); ?></h4>
        <?php
        // نفس الفورم الموجود عندك – نستخدمه كما هو
        echo $this->render_request_form( $emp );
        ?>
      </div>

      <!-- Leave Balance (full width) -->
      <div class="sfs-hr-lw-card sfs-hr-lw-balance-card">
        <h4><?php esc_html_e('Leave Balance','sfs-hr'); ?></h4>
        <?php if (empty($balances)): ?>
          <p class="sfs-hr-lw-muted"><?php esc_html_e('No balance data yet.','sfs-hr'); ?></p>
        <?php else: ?>
          <table class="sfs-hr-lw-table">
            <thead>
              <tr>
                <th><?php esc_html_e('Type','sfs-hr'); ?></th>
                <th><?php esc_html_e('Available','sfs-hr'); ?></th>
                <th><?php esc_html_e('Used','sfs-hr'); ?></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($balances as $b): ?>
              <?php
                $available = (int) ($b['closing'] ?? 0);
                $used      = (int) ($b['used'] ?? 0);
              ?>
              <tr>
                <td><?php echo esc_html($b['name']); ?></td>
                <td><?php echo esc_html($available); ?></td>
                <td><?php echo esc_html($used); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- Recent Requests (full width, under balance) -->
      <div class="sfs-hr-lw-card sfs-hr-lw-requests">
        <h4><?php esc_html_e('Recent Requests','sfs-hr'); ?></h4>
        <?php if (empty($requests)): ?>
          <p class="sfs-hr-lw-muted"><?php esc_html_e('No leave requests yet.','sfs-hr'); ?></p>
        <?php else: ?>
          <table class="sfs-hr-lw-table">
            <thead>
              <tr>
                <th><?php esc_html_e('Type','sfs-hr'); ?></th>
                <th><?php esc_html_e('Dates','sfs-hr'); ?></th>
                <th><?php esc_html_e('Days','sfs-hr'); ?></th>
                <th><?php esc_html_e('Status','sfs-hr'); ?></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
              <?php
              $status_label = ucfirst($r['status']);
              if ($r['status'] === 'pending') {
                  $lvl = (int)($r['approval_level'] ?? 1);
                  $status_label = $lvl <= 1
                      ? __('Pending (Manager)','sfs-hr')
                      : __('Pending (HR)','sfs-hr');
              }
              $today = current_time('Y-m-d');
if ($r['status'] === 'approved') {
    if ($today < $r['start_date']) {
        $status_label = __('Approved · Upcoming','sfs-hr');
    } elseif ($today > $r['end_date']) {
        $status_label = __('Approved · Returned','sfs-hr');
    } else {
        $status_label = __('Approved · On leave','sfs-hr');
    }
}

              ?>
              <tr>
                <td><?php echo esc_html($r['type_name']); ?></td>
                <td><?php echo esc_html($r['start_date'].' → '.$r['end_date']); ?></td>
                <td><?php echo (int) $r['days']; ?></td>
                <td>
                    <span class="sfs-hr-lw-status sfs-hr-lw-status-<?php echo esc_attr($r['status']); ?>">
                      <?php echo esc_html($status_label); ?>
                    </span>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <style>
    .sfs-hr-leave-widget {
        max-width: 900px;
        margin: 0 auto;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .sfs-hr-lw-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }
    .sfs-hr-lw-sub {
        margin: 4px 0 0;
        font-size: 13px;
        color: #6b7280;
    }

    /* KPIs row */
    .sfs-hr-lw-kpis {
        display: grid;
        grid-template-columns: repeat(3, minmax(0,1fr));
        gap: 12px;
        margin-bottom: 14px;
    }
    @media (max-width: 768px) {
        .sfs-hr-lw-kpis {
            grid-template-columns: minmax(0,1fr);
        }
    }
    .sfs-hr-lw-kpi {
        background: #f9fafb;
        border-radius: 10px;
        padding: 10px 12px;
        border: 1px solid #e5e7eb;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .sfs-hr-lw-kpi-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: #6b7280;
    }
    .sfs-hr-lw-kpi-value {
        font-size: 18px;
        font-weight: 600;
        color: #111827;
    }

    .sfs-hr-lw-card {
        background: #ffffff;
        border-radius: 12px;
        padding: 16px 18px;
        box-shadow: 0 1px 3px rgba(15,23,42,0.08);
        margin-bottom: 16px;
    }
    .sfs-hr-lw-card h4 {
        margin: 0 0 10px;
        font-size: 15px;
        font-weight: 600;
    }
    .sfs-hr-lw-muted {
        margin: 0;
        font-size: 13px;
        color: #9ca3af;
    }

    .sfs-hr-lw-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    .sfs-hr-lw-table th,
    .sfs-hr-lw-table td {
        padding: 6px 4px;
        border-bottom: 1px solid #f3f4f6;
        text-align: left;
    }
    .sfs-hr-lw-table th {
        font-weight: 600;
        color: #6b7280;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .sfs-hr-lw-status {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 500;
    }
    .sfs-hr-lw-status-pending {
        background: #eff6ff;
        color: #1d4ed8;
    }
    .sfs-hr-lw-status-approved {
        background: #ecfdf3;
        color: #166534;
    }
    .sfs-hr-lw-status-rejected {
        background: #fef2f2;
        color: #b91c1c;
    }
    .sfs-hr-alert {
        padding: 10px 12px;
        border-radius: 8px;
        background: #fef3c7;
        border: 1px solid #fde68a;
        font-size: 13px;
    }

    /* Inline form: Type + Start + End */
    .sfs-hr-lw-request-card form > p:nth-of-type(-n+3),
    .sfs-hr-lw-request-card form > div:nth-of-type(-n+3) {
        display: inline-block;
        width: 32%;
        margin-right: 2%;
        vertical-align: top;
    }
    .sfs-hr-lw-request-card form > p:nth-of-type(3),
    .sfs-hr-lw-request-card form > div:nth-of-type(3) {
        margin-right: 0;
    }
    @media (max-width: 768px) {
        .sfs-hr-lw-request-card form > p,
        .sfs-hr-lw-request-card form > div {
            display: block;
            width: 100%;
            margin-right: 0;
        }
    }
    </style>
    <?php

    return (string) ob_get_clean();
}




public function shortcode_request($atts = []): string {
    if (!is_user_logged_in()) {
        return '<p>' . esc_html__('Please log in to request leave.', 'sfs-hr') . '</p>';
    }
    if (!current_user_can('sfs_hr.leave.request')) {
        return '<p>' . esc_html__('No permission to request leave.', 'sfs-hr') . '</p>';
    }




    // --- begin patch: ensure current user has an employee row ---
    $uid = get_current_user_id();
    global $wpdb;
    $emp_t   = $wpdb->prefix . 'sfs_hr_employees';
    $types_t = $wpdb->prefix . 'sfs_hr_leave_types';

    // try cached helper first
    $employee_id = \SFS\HR\Core\Helpers::current_employee_id();

    if (!$employee_id) {
        $u = get_userdata($uid);

        // Try linking by email first (to an existing unlinked employee row)
        if ($u && $u->user_email) {
            $by_email = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM $emp_t WHERE email=%s AND (user_id IS NULL OR user_id=0) LIMIT 1",
                    sanitize_email($u->user_email)
                )
            );
            if ($by_email) {
                $wpdb->update(
                    $emp_t,
                    ['user_id' => $uid, 'updated_at' => current_time('mysql')],
                    ['id' => $by_email]
                );
                $employee_id = $by_email;
            }
        }

        // Create minimal record if still missing
        if (!$employee_id) {
            $name  = $u ? trim($u->display_name) : '';
            $first = '';
            $last  = '';
            if (strpos($name, ' ') !== false) {
                [$first, $last] = explode(' ', $name, 2);
            } else {
                $first = $name ?: ($u ? $u->user_login : '');
            }
            



            $wpdb->insert($emp_t, [
                'user_id'       => (int) $uid,
                'employee_code' => 'USR-' . (int) $uid,
                'first_name'    => sanitize_text_field($first),
                'last_name'     => sanitize_text_field($last),
                'email'         => $u ? sanitize_email($u->user_email) : '',
                'status'        => 'active',
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql'),
            ]);
            $employee_id = (int) $wpdb->insert_id;
        }
    }

    // Load the employee row we just ensured exists (defensive)
    $emp = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $emp_t WHERE id=%d", (int)$employee_id),
        ARRAY_A
    );
    if (!$emp) {
        // Should not happen, but keep a graceful message.
        return '<p>' . esc_html__('No employee record linked to your account.', 'sfs-hr') . '</p>';
    }
    // --- end patch ---

    $out = '';
    if (!empty($_GET['sfs_hr_ok'])) {
        $out .= '<div class="notice notice-success"><p>' .
                esc_html__('Request submitted. You will be notified once reviewed.', 'sfs-hr') .
                '</p></div>';
    }
    if (!empty($_GET['sfs_hr_err'])) {
        $out .= '<div class="notice notice-error"><p>' . esc_html($_GET['sfs_hr_err']) . '</p></div>';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sfs_hr_leave_submit'])) {
        // defense-in-depth
        if (!current_user_can('sfs_hr.leave.request')) {
            return $out . '<div class="notice notice-error"><p>' .
                   esc_html__('No permission to request leave.', 'sfs-hr') .
                   '</p></div>';
        }
        check_admin_referer('sfs_hr_leave_submit');

        $type_id = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
        $start   = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end     = isset($_POST['end_date'])   ? sanitize_text_field($_POST['end_date'])   : '';
        $reason  = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        $type = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, annual_quota, allow_negative, is_annual, special_code FROM $types_t WHERE id=%d AND active=1",
                $type_id
            ),
            ARRAY_A
        );
        if (!$type) {
            return $out . '<div class="notice notice-error"><p>' .
                   esc_html__('Invalid leave type.', 'sfs-hr') .
                   '</p></div>' . $this->render_request_form($emp);
        }

        $err = $this->validate_dates($start, $end);
        if ($err) {
            return $out . '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>' .
                   $this->render_request_form($emp);
        }

        $y1 = (int)substr($start, 0, 4);
        $y2 = (int)substr($end,   0, 4);
        if ($y1 !== $y2) {
            return $out . '<div class="notice notice-error"><p>' .
                   esc_html__('Please split requests by year (start and end must be in the same year).', 'sfs-hr') .
                   '</p></div>' . $this->render_request_form($emp);
        }

        if ($this->has_overlap((int)$emp['id'], $start, $end)) {
            return $out . '<div class="notice notice-error"><p>' .
                   esc_html__('You already have a request overlapping these dates.', 'sfs-hr') .
                   '</p></div>' . $this->render_request_form($emp);
        }

        $days  = (int)$this->business_days($start, $end); // excludes Fridays + company holidays
        if ($days <= 0) {
    $days = 1; // minimum one day if the period is valid
}

        $hire  = $emp['hire_date'] ?? ($emp['hired_at'] ?? null);
        $quota = $this->compute_quota_for_year($type, $hire, $y1);
        $available = $this->available_days((int)$emp['id'], (int)$type['id'], $y1, (int)$quota);

$special  = isset($type['special_code']) ? strtoupper(trim($type['special_code'])) : '';
$cal_days = (int) floor( (strtotime($end) - strtotime($start)) / DAY_IN_SECONDS ) + 1; // inclusive

// ---- Sick: require document upload
$attach_id = 0;
if (in_array($special, ['SICK_SHORT','SICK_LONG'], true)) {
    if (empty($_FILES['supporting_doc']['name'])) {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('A medical document is required for Sick leave.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/image.php';
    $attach_id = media_handle_upload('supporting_doc', 0);
    if (is_wp_error($attach_id)) {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Failed to upload the document.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }
}

// ---- Marriage: up to 5 business days
if ($special === 'MARRIAGE' && $days > 5) {
    return $out.'<div class="notice notice-error"><p>'.
        esc_html__('Marriage leave is limited to 5 business days.','sfs-hr').
        '</p></div>'.$this->render_request_form($emp);
}

// ---- Bereavement: up to 5 business days
if ($special === 'BEREAVEMENT' && $days > 5) {
    return $out.'<div class="notice notice-error"><p>'.
        esc_html__('Bereavement leave is limited to 5 business days.','sfs-hr').
        '</p></div>'.$this->render_request_form($emp);
}

// ---- Paternity: up to 3 business days (male only)
if ($special === 'PATERNITY') {
    if ($days > 3) {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Paternity leave is limited to 3 business days.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }
    if (strtolower((string)($emp['gender'] ?? '')) !== 'male') {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Paternity leave is available only to male employees.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }
}

// ---- Hajj: 10–15 calendar days, once, not split, and only after 2 years of service
if ($special === 'HAJJ') {
    // duration
    if ($cal_days < 10 || $cal_days > 15) {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Hajj leave must be between 10 and 15 calendar days.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }

    // once (block also if another Hajj is pending to prevent splitting)
    $req_t = $wpdb->prefix.'sfs_hr_leave_requests';
    $typ_t = $wpdb->prefix.'sfs_hr_leave_types';
    $dup = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $req_t r
         JOIN $typ_t t ON t.id = r.type_id
         WHERE r.employee_id=%d AND t.special_code='HAJJ'
           AND r.status IN ('pending','approved')",
        (int)$emp['id']
    ));
    if ($dup > 0 || !empty($emp['hajj_used_at'])) {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Hajj leave can be granted only once and cannot be split.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }

    // tenure ≥ 2 years as of start date
    $hire = $emp['hire_date'] ?? ($emp['hired_at'] ?? null);
    if (!$hire || (strtotime($start) - strtotime($hire)) < (2 * 365 * DAY_IN_SECONDS)) {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Hajj leave is available after completing 2 years of service.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }
}

// ---- Sick (Short/Long) existing limits
if ($special === 'SICK_SHORT') {
    if ($days > 29) {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Sick (Short) is limited to 29 business days.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }
} elseif ($special === 'SICK_LONG') {
    if ($days < 30) {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Sick (Long) requires at least 30 business days.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }
    if ($days > 120) {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Sick (Long) is limited to 120 days.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }
}

// ---- Maternity: allow extension up to 100 calendar days (last 30 unpaid)
if ($special === 'MATERNITY') {
    if (strtolower((string)($emp['gender'] ?? '')) !== 'female') {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Maternity leave is available only to female employees.','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }
    if ($cal_days > 100) {
        return $out.'<div class="notice notice-error"><p>'.
            esc_html__('Maternity leave can be up to 100 calendar days (last 30 unpaid).','sfs-hr').
            '</p></div>'.$this->render_request_form($emp);
    }
}





        if (!$type['allow_negative'] && $available < $days) {
            $msg = sprintf(__('Insufficient balance. Available: %d, requested: %d', 'sfs-hr'), $available, $days);
            return $out . '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>' .
                   $this->render_request_form($emp);
        }

        $req_t = $wpdb->prefix . 'sfs_hr_leave_requests';
        $now   = current_time('mysql');
        $ins = $wpdb->insert($req_t, [
            'employee_id' => (int)$emp['id'],
            'type_id'     => (int)$type['id'],
            'start_date'  => $start,
            'end_date'    => $end,
            'days'        => $days,
            'reason'      => $reason,
            'status'      => 'pending',
            'doc_attachment_id' => $attach_id ?: null,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $target = wp_get_referer();
        if (!$target && function_exists('get_permalink')) $target = get_permalink();

        if ($ins === false) {
            error_log('[SFS HR] Leave request insert failed: ' . $wpdb->last_error);
            $target = add_query_arg('sfs_hr_err', rawurlencode($wpdb->last_error ?: __('Save failed', 'sfs-hr')), $target);
        } else {
            // Audit Trail: leave request created
            do_action('sfs_hr_leave_request_created', $wpdb->insert_id, [
                'employee_id' => (int)$emp['id'],
                'type_id'     => (int)$type['id'],
                'start_date'  => $start,
                'end_date'    => $end,
                'days'        => $days,
            ]);
            if (get_option('sfs_hr_leave_email', '1') === '1') {
$this->email_approvers_for_employee(
    (int)$emp['id'],
    __('New Leave Request','sfs-hr'),
    sprintf(
        __('Employee %s requested leave (%s → %s), %d days.','sfs-hr'),
        trim(($emp['first_name']??'').' '.($emp['last_name']??'')) ?: $emp['employee_code'],
        $start, $end, $days
    )
);
            }
            $target = add_query_arg('sfs_hr_ok', '1', $target);
        }
        wp_safe_redirect($target); exit;
    }

    return $out . $this->render_request_form($emp);
}


    private function render_request_form(array $emp): string {
        global $wpdb;
        $types = $wpdb->get_results(
    "SELECT id, name, special_code
     FROM {$wpdb->prefix}sfs_hr_leave_types
     WHERE active=1
     ORDER BY name ASC",
    ARRAY_A
);

// Hide Maternity for non-female employees
$gender = strtolower((string)($emp['gender'] ?? ''));
$types = array_values(array_filter($types, function($t) use ($gender) {
    $special = strtoupper(trim((string)($t['special_code'] ?? '')));
    if ($special === 'MATERNITY' && $gender !== 'female') return false;
    return true;
}));

        ob_start(); ?>
        <form method="post" enctype="multipart/form-data" class="sfs-hr-leave-form">
          <?php wp_nonce_field('sfs_hr_leave_submit'); ?>
          <p><label><?php esc_html_e('Leave Type','sfs-hr'); ?> <br/>
            <select name="type_id" required>
              <?php foreach($types as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>"><?php echo esc_html($t['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </label></p>
          <p><label><?php esc_html_e('Start Date','sfs-hr'); ?> <br/><input type="date" name="start_date" required/></label></p>
          <p><label><?php esc_html_e('End Date','sfs-hr'); ?> <br/><input type="date" name="end_date" required/></label></p>
          <p><label><?php esc_html_e('Reason','sfs-hr'); ?> <br/><textarea name="reason" rows="4"></textarea></label></p>
          <p>
  <label><?php esc_html_e('Supporting document (PDF or image)','sfs-hr'); ?>
    <br/><input type="file" name="supporting_doc" accept=".pdf,image/*" />
  </label>
  <br/><small><?php esc_html_e('Required for Sick leave.','sfs-hr'); ?></small>
</p>

          <p><button class="button button-primary" name="sfs_hr_leave_submit" value="1"><?php esc_html_e('Submit Request','sfs-hr'); ?></button></p>
        </form>
        <?php
        return (string)ob_get_clean();
    }

    public function shortcode_my_leaves(): string {
        if (!is_user_logged_in()) return '';
        global $wpdb;
        $uid = get_current_user_id();
        $emp = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id=%d", $uid), ARRAY_A);
        if (!$emp) return '<p>'.esc_html__('No employee record.','sfs-hr').'</p>';

        $req_t = $wpdb->prefix.'sfs_hr_leave_requests';
        $typ_t = $wpdb->prefix.'sfs_hr_leave_types';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, t.name AS type_name
             FROM $req_t r JOIN $typ_t t ON t.id = r.type_id
             WHERE r.employee_id=%d ORDER BY r.id DESC LIMIT 200", (int)$emp['id']), ARRAY_A);

        ob_start(); ?>
        <table class="sfs-hr-table">
          <thead><tr>
            <th><?php esc_html_e('Type','sfs-hr'); ?></th>
            <th><?php esc_html_e('Dates','sfs-hr'); ?></th>
            <th><?php esc_html_e('Days','sfs-hr'); ?></th>
            <th><?php esc_html_e('Submitted','sfs-hr'); ?></th>
            <th><?php esc_html_e('Status','sfs-hr'); ?></th>
          </tr></thead>
          <tbody>
            <?php if(!$rows): ?>
              <tr><td colspan="5"><?php esc_html_e('No requests yet.','sfs-hr'); ?></td></tr>
            <?php else: foreach($rows as $r): ?>
              <tr>
                <td><?php echo esc_html($r['type_name']); ?></td>
                <td><?php echo esc_html($r['start_date'].' → '.$r['end_date']); ?></td>
                <td><?php printf('%d', (int)$r['days']); ?></td>
                <td><?php echo $this->fmt_dt($r['created_at'] ?? ''); ?></td>
                <td><?php echo esc_html(ucfirst($r['status'])); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <?php
        return (string)ob_get_clean();
    }

    /* ---------------------------------- Validation / Utils ---------------------------------- */

    private function validate_dates(string $start, string $end): ?string {
        if (!$start || !$end) return __('Start/End dates required.','sfs-hr');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))
            return __('Invalid date format (YYYY-MM-DD).','sfs-hr');
        if ($end < $start) return __('End date must be after start date.','sfs-hr');
        $today = current_time('Y-m-d');
        if ($start < $today) return __('Cannot request leave in the past.','sfs-hr');
        return null;
    }
    
    
private function append_approval_chain(?string $json, array $step): string {
    $chain = [];
    if ($json) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $chain = $decoded;
        }
    }
    $step['at'] = $step['at'] ?? Helpers::now_mysql();
    $chain[] = [
        'by'     => (int)($step['by'] ?? 0),
        'role'   => (string)($step['role'] ?? ''),
        'action' => (string)($step['action'] ?? ''),
        'note'   => (string)($step['note'] ?? ''),
        'at'     => (string)$step['at'],
    ];
    return wp_json_encode($chain);
}

    /** Business days for Sat–Thu workweek (Friday off) minus company holidays (option-based). Inclusive. */
    private function business_days(string $start, string $end): int {
        $s = strtotime($start);
        $e = strtotime($end);
        if ($e < $s) return 0;

        $holiday_map = array_fill_keys($this->holidays_in_range($start, $end), true);

        $days = 0;
        for ($d = $s; $d <= $e; $d += DAY_IN_SECONDS) {
            $ymd = gmdate('Y-m-d', $d);
            if (isset($holiday_map[$ymd])) continue;
            $w = (int) gmdate('w', $d); // 0 Sun..6 Sat; Friday=5
            if ($w === 5) continue;     // Friday off
            $days++;
        }
        return $days;
    }

    /** Tenure-aware quota using options (<5y vs ≥5y). Falls back to type.annual_quota. */
    private function compute_quota_for_year(array $type_row, ?string $hire_or_hired_at, int $year): int {
        $quota = (int)($type_row['annual_quota'] ?? 0);
        if (empty($type_row['is_annual'])) return $quota;
        if (empty($hire_or_hired_at)) return $quota;
        $as_of = strtotime($year.'-01-01');
        $hd = strtotime($hire_or_hired_at);
        if (!$hd) return $quota;
        $years = (int)floor(($as_of - $hd) / (365.2425*DAY_IN_SECONDS));
        if ($years < 0) $years = 0;
        $lt5 = (int)get_option('sfs_hr_annual_lt5','21');
        $ge5 = (int)get_option('sfs_hr_annual_ge5','30');
        return ($years >= 5) ? $ge5 : $lt5;
    }

    /** Available days = opening + accrued + carried_over - used (for year). */
    private function available_days(int $employee_id, int $type_id, int $year, int $annual_quota): int {
        global $wpdb;
        $bal = $wpdb->prefix.'sfs_hr_leave_balances';
        $req = $wpdb->prefix.'sfs_hr_leave_requests';

        $used = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(days),0) FROM $req WHERE employee_id=%d AND type_id=%d AND status='approved' AND YEAR(start_date)=%d",
            $employee_id, $type_id, $year
        ));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT opening, accrued, carried_over FROM $bal WHERE employee_id=%d AND type_id=%d AND year=%d",
            $employee_id, $type_id, $year
        ), ARRAY_A);

        $opening = (int)($row['opening'] ?? 0);
        $accrued = isset($row['accrued']) ? (int)$row['accrued'] : (int)$annual_quota;
        if ((int)($row['accrued'] ?? 0) === 0) $accrued = (int)$annual_quota;
        $carried = (int)($row['carried_over'] ?? 0);

        $available = $opening + $accrued + $carried - $used;
        return max($available, 0);
    }
    
    /** Dept ids managed by a user (from sfs_hr_departments.manager_user_id) */
private function manager_dept_ids_for_user(int $uid): array {
    global $wpdb;
    $tbl = $wpdb->prefix.'sfs_hr_departments';
    $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $tbl WHERE manager_user_id=%d AND active=1", $uid));
    return array_map('intval', $ids ?: []);
}

/** Email approvers for a given employee: dept manager if set, else HR managers/admins as fallback */
private function email_approvers_for_employee(int $employee_id, string $subject, string $msg): void {
    if (get_option('sfs_hr_leave_email','1')!=='1') return;
    global $wpdb;
    $emp_t  = $wpdb->prefix.'sfs_hr_employees';
    $dept_t = $wpdb->prefix.'sfs_hr_departments';

    $dept_id = (int)$wpdb->get_var($wpdb->prepare("SELECT dept_id FROM $emp_t WHERE id=%d", $employee_id));
    $emails = [];

    if ($dept_id) {
        $mgr_uid = (int)$wpdb->get_var($wpdb->prepare("SELECT manager_user_id FROM $dept_t WHERE id=%d AND active=1", $dept_id));
        if ($mgr_uid) {
            $mgr = get_user_by('id', $mgr_uid);
            if ($mgr && $mgr->user_email) $emails[] = $mgr->user_email;
        }
    }

    // Fallbacks
    if (!$emails) {
        $admins = get_users(['role'=>'administrator','fields'=>['user_email']]);
        foreach($admins as $u){ if ($u->user_email) $emails[] = $u->user_email; }
        $hrmgrs = get_users(['role'=>'sfs_hr_manager','fields'=>['user_email']]);
        foreach($hrmgrs as $u){ if ($u->user_email) $emails[] = $u->user_email; }
    }

    $emails = array_unique(array_filter($emails));
    foreach($emails as $to){ Helpers::send_mail($to, $subject, $msg); }
}


    private function has_overlap(int $employee_id, string $start, string $end): bool {
        global $wpdb; $t = $wpdb->prefix.'sfs_hr_leave_requests';
        $sql = "SELECT COUNT(*) FROM $t
                WHERE employee_id=%d
                  AND status IN ('pending','approved')
                  AND NOT (end_date < %s OR start_date > %s)";
        $cnt = (int)$wpdb->get_var($wpdb->prepare($sql, $employee_id, $start, $end));
        return $cnt > 0;
    }

    private function notify_requester(int $employee_id, string $subject, string $msg): void {
        global $wpdb; $emp_t = $wpdb->prefix.'sfs_hr_employees';
        $email = $wpdb->get_var($wpdb->prepare("SELECT email FROM $emp_t WHERE id=%d", $employee_id));
        if (get_option('sfs_hr_leave_email','1')==='1' && $email) {
            Helpers::send_mail($email, $subject, $msg);
        }
    }

    private function email_approvers(string $subject, string $msg): void {
        if (get_option('sfs_hr_leave_email','1')!=='1') return;
        $emails = [];
        $admins = get_users(['role'=>'administrator','fields'=>['user_email']]);
        foreach($admins as $u){ if ($u->user_email) $emails[] = $u->user_email; }
        $mgrs = get_users(['role'=>'sfs_hr_manager','fields'=>['user_email']]);
        foreach($mgrs as $u){ if ($u->user_email) $emails[] = $u->user_email; }
        $emails = array_unique(array_filter($emails));
        foreach($emails as $to){ Helpers::send_mail($to, $subject, $msg); }
    }

    private function paginate_admin(int $page, int $pages, array $args): string {
        if ($pages<=1) return '';
        $window = 7; $half = (int)floor(($window-1)/2);
        $start = max(2, $page - $half); $end = min($pages-1, $page + $half);
        if ($page <= $half) $end = min($pages-1, 1+$window);
        if ($page >= $pages - $half) $start = max(2, $pages - $window);

        $base = function($p) use ($args){
            return esc_url(add_query_arg(array_merge($args,['paged'=>$p]), admin_url('admin.php')));
        };
        ob_start(); ?>
        <div class="tablenav">
          <div class="tablenav-pages">
            <span class="pagination-links">
              <?php
                echo $page>1 ? '<a class="first-page button" href="'.$base(1).'"><span aria-hidden="true">«</span></a>'
                             : '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>';
                echo $page>1 ? '<a class="prev-page button" href="'.$base($page-1).'"><span aria-hidden="true">‹</span></a>'
                             : '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';

                echo $page===1 ? '<span class="page-numbers current">1</span>' : '<a class="page-numbers" href="'.$base(1).'">1</a>';
                if ($start>2) echo '<span class="page-numbers dots">…</span>';
                for($i=$start;$i<=$end;$i++){
                    if ($i===1||$i===$pages) continue;
                    echo $i===$page ? '<span class="page-numbers current">'.$i.'</span>' : '<a class="page-numbers" href="'.$base($i).'">'.$i.'</a>';
                }
                if ($end<$pages-1) echo '<span class="page-numbers dots">…</span>';
                if ($pages>1) echo $page===$pages ? '<span class="page-numbers current">'.$pages.'</span>' : '<a class="page-numbers" href="'.$base($pages).'">'.$pages.'</a>';

                echo $page<$pages ? '<a class="next-page button" href="'.$base($page+1).'"><span aria-hidden="true">›</span></a>'
                                  : '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
                echo $page<$pages ? '<a class="last-page button" href="'.$base($pages).'"><span aria-hidden="true">»</span></a>'
                                  : '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
              ?>
            </span>
          </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    /* ---------------------------------- Save adjustments ---------------------------------- */

    public function handle_update_balance(): void {
        Helpers::require_cap('sfs_hr.leave.manage');
        check_admin_referer('sfs_hr_leave_update_balance');

        $id      = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $opening = isset($_POST['opening']) ? (int)$_POST['opening'] : 0;
        $accrued = isset($_POST['accrued']) ? (int)$_POST['accrued'] : 0;
        $carried = isset($_POST['carried_over']) ? (int)$_POST['carried_over'] : 0;

        if ($id <= 0) wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=balances&err='.rawurlencode(__('Invalid record','sfs-hr'))));

        global $wpdb;
        $bal = $wpdb->prefix.'sfs_hr_leave_balances';
        $req = $wpdb->prefix.'sfs_hr_leave_requests';

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $bal WHERE id=%d", $id), ARRAY_A);
        if (!$row) wp_safe_redirect(admin_url('admin.php?page=sfs-hr-leave-requests&tab=balances&err='.rawurlencode(__('Record not found','sfs-hr'))));

        $used = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(days),0) FROM $req WHERE employee_id=%d AND type_id=%d AND status='approved' AND year(start_date)=%d",
            (int)$row['employee_id'], (int)$row['type_id'], (int)$row['year']
        ));
        $closing = (int)$opening + (int)$accrued + (int)$carried - $used;

        $wpdb->update($bal, [
            'opening'=>$opening,
            'accrued'=>$accrued,
            'carried_over'=>$carried,
            'used'=>$used,
            'closing'=>$closing,
            'updated_at'=> Helpers::now_mysql(),
        ], ['id'=>$id]);

        $qs = [
    'page' => 'sfs-hr-leave-requests',
    'tab'  => 'balances',
    'year' => (int) $row['year'],
    'ok'   => 1,
];
        wp_safe_redirect(add_query_arg($qs, admin_url('admin.php'))); exit;
    }

    /* ---------------------------------- Holidays helpers & cron ---------------------------------- */

    private function get_holidays_option(): array {
        $list = get_option('sfs_hr_holidays', []);
        if (!is_array($list)) $list = [];
        $out = [];
        foreach ($list as $h) {
            $n = isset($h['name']) ? sanitize_text_field($h['name']) : '';
            $r = !empty($h['repeat']) ? 1 : 0;

            // Back-compat: single day record {date}
            if (!empty($h['date'])) {
                $d = sanitize_text_field($h['date']);
                if ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $n) {
                    $out[] = ['start'=>$d, 'end'=>$d, 'name'=>$n, 'repeat'=>$r];
                }
                continue;
            }

            $s = isset($h['start']) ? sanitize_text_field($h['start']) : '';
            $e = isset($h['end'])   ? sanitize_text_field($h['end'])   : '';
            if (!$s || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) continue;
            if (!$e || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) $e = $s;
            if ($e < $s) { $tmp=$s; $s=$e; $e=$tmp; }
            if ($n) $out[] = ['start'=>$s, 'end'=>$e, 'name'=>$n, 'repeat'=>$r];
        }
        return $out;
    }

    /** Return list of Y-m-d dates to exclude within [start,end] inclusive, expanding holiday ranges & repeats. */
    private function holidays_in_range(string $start, string $end): array {
        $s = strtotime($start);
        $e = strtotime($end);
        if ($e < $s) return [];

        $list  = $this->get_holidays_option();
        $years = range((int)gmdate('Y',$s), (int)gmdate('Y',$e));
        $set   = [];

        foreach ($list as $h) {
            $s0 = $h['start'];
            $e0 = $h['end'];

            if (!empty($h['repeat'])) {
                $sm = substr($s0,5); $em = substr($e0,5);
                foreach ($years as $y) {
                    $rs = $y.'-'.$sm;
                    $re = ($em >= $sm) ? ($y.'-'.$em) : (($y+1).'-'.$em);
                    $this->add_range_days_clipped($rs, $re, $start, $end, $set);
                }
            } else {
                $this->add_range_days_clipped($s0, $e0, $start, $end, $set);
            }
        }
        return array_keys($set);
    }

    /** Add each day of [rangeStart, rangeEnd] to $set, but only where it intersects [clipStart, clipEnd]. */
    private function add_range_days_clipped(string $rangeStart, string $rangeEnd, string $clipStart, string $clipEnd, array &$set): void {
        $rs = strtotime($rangeStart);
        $re = strtotime($rangeEnd);
        if ($re < $rs) return;

        $cs = strtotime($clipStart);
        $ce = strtotime($clipEnd);

        $s = max($rs, $cs);
        $e = min($re, $ce);

        for ($d=$s; $d <= $e; $d += DAY_IN_SECONDS) {
            $set[ gmdate('Y-m-d', $d) ] = true;
        }
    }

    /** Email all active employees about a newly added holiday (single day or range). */
    private function broadcast_holiday_added(string $start, string $end, string $name, int $repeat): void {
        global $wpdb;
        $emp_t = $wpdb->prefix.'sfs_hr_employees';
        $emails = $wpdb->get_col("SELECT email FROM $emp_t WHERE status='active' AND email<>''");
        if (!$emails) return;

        $subject = __('New Company Holiday','sfs-hr');
        $body = sprintf(
            __('%s: %s%s%s','sfs-hr'),
            $name,
            $start,
            ($end && $end !== $start) ? ' → '.$end : '',
            $repeat ? ' ('.__('repeats yearly','sfs-hr').')' : ''
        );

        foreach ($emails as $to) {
            Helpers::send_mail($to, $subject, $body);
        }
    }

    /* --- Cron: schedule daily and send reminders X days before a holiday starts --- */

    public function ensure_cron(bool $force_reschedule = false): void {
        $enabled = get_option('sfs_hr_holiday_reminder_enabled','0') === '1';
        $hook    = 'sfs_hr_daily';

        if ($force_reschedule && wp_next_scheduled($hook)) {
            wp_clear_scheduled_hook($hook);
        }
        if ($enabled && ! wp_next_scheduled($hook)) {
            $ts = strtotime(date('Y-m-d 09:00:00', current_time('timestamp')));
            if ($ts <= current_time('timestamp')) $ts += DAY_IN_SECONDS;
            wp_schedule_event($ts, 'daily', $hook);
        }
        if (!$enabled && wp_next_scheduled($hook)) {
            wp_clear_scheduled_hook($hook);
        }
    }

    public function cron_daily(): void {
        if (get_option('sfs_hr_holiday_reminder_enabled','0') !== '1') return;
        $days = (int)get_option('sfs_hr_holiday_reminder_days','0');
        if ($days <= 0) return;

        $today = gmdate('Y-m-d');
        $to    = gmdate('Y-m-d', strtotime($today) + $days*DAY_IN_SECONDS);

        $instances = $this->holiday_instances_between($today, $to);
        if (!$instances) return;

        $sent = get_option('sfs_hr_holiday_reminded', []);
        if (!is_array($sent)) $sent = [];

        foreach ($instances as $inst) {
            $key = $inst['start'].'|'.$inst['end'].'|'.$inst['name'];
            if (!empty($sent[$key])) continue;

            $this->broadcast_holiday_added($inst['start'], $inst['end'], $inst['name'], (int)$inst['repeat']);
            $sent[$key] = gmdate('Y-m-d H:i:s');
        }

        if (count($sent) > 200) {
            $sent = array_slice($sent, -200, null, true);
        }
        update_option('sfs_hr_holiday_reminded', $sent, false);
    }

    /** Produce holiday start/end instances whose *start date* falls within [from,to] inclusive. */
    private function holiday_instances_between(string $from, string $to): array {
        $list = $this->get_holidays_option();
        if (!$list) return [];
        $fs = strtotime($from); $ts = strtotime($to);
        $out = [];

        foreach ($list as $h) {
            $s0 = $h['start']; $e0 = $h['end']; $name = $h['name']; $rep = !empty($h['repeat']) ? 1 : 0;

            if ($rep) {
                $sm = substr($s0,5); $em = substr($e0,5);
                $yStart = (int)gmdate('Y', $fs) - 1;
                $yEnd   = (int)gmdate('Y', $ts) + 1;

                for ($y=$yStart; $y <= $yEnd; $y++) {
                    $start = $y.'-'.$sm;
                    $end   = ($em >= $sm) ? ($y.'-'.$em) : (($y+1).'-'.$em);
                    if ($start >= $from && $start <= $to) {
                        $out[] = ['start'=>$start,'end'=>$end,'name'=>$name,'repeat'=>1];
                    }
                }
            } else {
                if ($s0 >= $from && $s0 <= $to) {
                    $out[] = ['start'=>$s0,'end'=>$e0,'name'=>$name,'repeat'=>0];
                }
            }
        }
        return $out;
    }

public function install(): void { $this->ensure_schema(); }

private function ensure_schema(): void {
    global $wpdb;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    // 1) Departments table
    $dept = $wpdb->prefix.'sfs_hr_departments';
    dbDelta("CREATE TABLE $dept (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(191) NOT NULL,
        manager_user_id BIGINT UNSIGNED NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY (id)
    ) $charset;");

   // Helpers
$colExists = function($table, $col) use ($wpdb): bool {
    if (empty($table) || empty($col)) return false;
    // very defensive: keep only valid characters in table name
    $table = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
    $col   = (string)$col;
    return (bool) $wpdb->get_var(
        $wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $col)
    );
};

// 2) Leave types: special_code + code (nullable + unique)
$types = $wpdb->prefix.'sfs_hr_leave_types';

if (!$colExists($types,'special_code')) {
    $wpdb->query("ALTER TABLE `$types` ADD COLUMN `special_code` VARCHAR(32) NULL AFTER `is_annual`");
}

/* ---- NEW: code column (slug) ---- */
if (!$colExists($types,'code')) {
    // Add as nullable so multiple NULLs won't violate unique index
    $wpdb->query("ALTER TABLE `$types` ADD COLUMN `code` VARCHAR(64) NULL DEFAULT NULL AFTER `name`");
} else {
    // Make sure existing installs are nullable (avoid '' duplicates)
    $wpdb->query("ALTER TABLE `$types` MODIFY `code` VARCHAR(64) NULL DEFAULT NULL");
}
// Backfill: convert empty strings to NULL before unique index
$wpdb->query("UPDATE `$types` SET `code` = NULL WHERE `code` = ''");

// Create the unique index if missing
$has_idx = (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'unique_code'",
    $types
));
if (!$has_idx) {
    $wpdb->query("CREATE UNIQUE INDEX `unique_code` ON `$types` (`code`)");
}



    // 3) Employees: hajj_used_at, dept_id
    $emp = $wpdb->prefix.'sfs_hr_employees';
    if (!$colExists($emp,'hajj_used_at')) $wpdb->query("ALTER TABLE $emp ADD COLUMN hajj_used_at DATE NULL AFTER hired_at");
    if (!$colExists($emp,'dept_id'))     $wpdb->query("ALTER TABLE $emp ADD COLUMN dept_id INT NULL AFTER email");
    if (!$colExists($emp,'gender')) $wpdb->query("ALTER TABLE $emp ADD COLUMN gender VARCHAR(16) NULL AFTER position");

    // 4) Leave requests: pay breakdown columns
    $req = $wpdb->prefix.'sfs_hr_leave_requests';
    if (!$colExists($req,'pay_breakdown')) $wpdb->query("ALTER TABLE $req ADD COLUMN pay_breakdown LONGTEXT NULL");
    if (!$colExists($req,'paid_days'))     $wpdb->query("ALTER TABLE $req ADD COLUMN paid_days INT NOT NULL DEFAULT 0");
    if (!$colExists($req,'unpaid_days'))   $wpdb->query("ALTER TABLE $req ADD COLUMN unpaid_days INT NOT NULL DEFAULT 0");
    if (!$colExists($req,'doc_attachment_id')) {
    $wpdb->query("ALTER TABLE $req ADD COLUMN doc_attachment_id BIGINT UNSIGNED NULL AFTER reason");
}
}


    /* ------------- small local formatter for mysql datetimes -> site format ------------- */
    private function fmt_dt( string $mysql ): string {
    if ( $mysql === '' || $mysql === '0000-00-00 00:00:00' ) {
        return '—';
    }
    
    $ts = strtotime( $mysql );
    if ( ! $ts || $ts < 0 ) {
        return '—';
    }
    
    $fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
    return esc_html( date_i18n( $fmt, $ts ) );
}

    public function render_leave_admin(): void {
    // Permission check
    \SFS\HR\Core\Helpers::require_cap( 'sfs_hr.leave.review' );

    // Which tab?
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'requests';

    $tabs = [
        'requests' => __( 'Requests',  'sfs-hr' ),
        'types'    => __( 'Types',     'sfs-hr' ),
        'balances' => __( 'Balances',  'sfs-hr' ),
        'settings' => __( 'Settings',  'sfs-hr' ),
    ];

    // If user cannot manage leave types/settings, hide those tabs
    if ( ! current_user_can( 'sfs_hr.leave.manage' ) ) {
        $tabs = [ 'requests' => $tabs['requests'] ];
        if ( $tab !== 'requests' ) {
            $tab = 'requests';
        }
    }

    if ( ! isset( $tabs[ $tab ] ) ) {
        $tab = 'requests';
    }

    echo '<div class="wrap sfs-hr-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'Leave', 'sfs-hr' ) . '</h1>';

    // 🔹 Global HR nav + breadcrumbs
    \SFS\HR\Core\Helpers::render_admin_nav();

    echo '<hr class="wp-header-end" />';

    echo '<h2 class="nav-tab-wrapper">';
    foreach ( $tabs as $slug => $label ) {
        $url = esc_url(
            add_query_arg(
                [
                    'page' => 'sfs-hr-leave-requests',
                    'tab'  => $slug,
                ],
                admin_url( 'admin.php' )
            )
        );
        $class = 'nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' );
        echo '<a href="' . $url . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
    }
    echo '</h2>';

    // Render the inner content (existing logic)
    switch ( $tab ) {
        case 'types':
            $this->render_types();
            break;
        case 'balances':
            $this->render_balances();
            break;
        case 'settings':
            $this->render_settings();
            break;
        case 'requests':
        default:
            $this->render_requests();
            break;
    }

    echo '</div>';
}

public function handle_early_return(): void {
    check_admin_referer('sfs_hr_leave_early_return');

    $id           = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $actual_return = isset($_POST['actual_return']) ? sanitize_text_field($_POST['actual_return']) : '';
    $note         = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';

    $redirect_base = admin_url('admin.php?page=sfs-hr-leave-requests&tab=requests&status=approved');

    if ($id <= 0 || ! $actual_return) {
        wp_safe_redirect(
            add_query_arg('err', rawurlencode(__('Missing data.', 'sfs-hr')), $redirect_base)
        );
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $actual_return)) {
        wp_safe_redirect(
            add_query_arg('err', rawurlencode(__('Invalid date format (YYYY-MM-DD).', 'sfs-hr')), $redirect_base)
        );
        exit;
    }

    global $wpdb;
    $req_t   = $wpdb->prefix . 'sfs_hr_leave_requests';
    $emp_t   = $wpdb->prefix . 'sfs_hr_employees';
    $bal_t   = $wpdb->prefix . 'sfs_hr_leave_balances';
    $types_t = $wpdb->prefix . 'sfs_hr_leave_types';

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $req_t WHERE id=%d", $id),
        ARRAY_A
    );

    if ( ! $row || $row['status'] !== 'approved' ) {
        wp_safe_redirect(
            add_query_arg('err', rawurlencode(__('Only approved leaves can be shortened.', 'sfs-hr')), $redirect_base)
        );
        exit;
    }

    // Employee info for permission check
    $empInfo = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT user_id, dept_id FROM $emp_t WHERE id=%d",
            (int) $row['employee_id']
        ),
        ARRAY_A
    );
    $current_uid = get_current_user_id();
    $is_hr       = current_user_can('sfs_hr.manage');

    if ( ! $is_hr ) {
        $managed = $this->manager_dept_ids_for_user($current_uid);
        if ( empty($managed) || ! in_array((int) ($empInfo['dept_id'] ?? 0), $managed, true) ) {
            wp_safe_redirect(
                add_query_arg('err', rawurlencode(__('You can only adjust leaves for your department.', 'sfs-hr')), $redirect_base)
            );
            exit;
        }
    }

    $start = $row['start_date'];
    $old_end = $row['end_date'];

    if ($actual_return <= $start) {
        wp_safe_redirect(
            add_query_arg('err', rawurlencode(__('Actual return must be after start date.', 'sfs-hr')), $redirect_base)
        );
        exit;
    }

    // New end date is the day BEFORE the actual return (so they can clock-in on return day)
    $new_end_ts = strtotime($actual_return . ' -1 day');
    $start_ts   = strtotime($start);
    $old_end_ts = strtotime($old_end);

   if ($new_end_ts < $start_ts || $new_end_ts > $old_end_ts) {
    wp_safe_redirect(
        add_query_arg('err', rawurlencode(__('Actual return is outside original leave range.', 'sfs-hr')), $redirect_base)
    );
    exit;
}

$new_end  = date('Y-m-d', $new_end_ts);

// use same business-day logic as normal requests
$new_days = (int) $this->business_days($start, $new_end);
if ($new_days <= 0) {
    $new_days = 1; // safety: never zero-length approved leave
}


    // Update request
    $chain = $this->append_approval_chain(
        $row['approval_chain'] ?? null,
        [
            'by'     => $current_uid,
            'role'   => $is_hr ? 'hr' : 'manager',
            'action' => 'shorten',
            'note'   => sprintf(
                'Early return: actual_return=%s, old_end=%s, new_end=%s. %s',
                $actual_return,
                $old_end,
                $new_end,
                $note
            ),
        ]
    );

    $wpdb->update(
        $req_t,
        [
            'end_date'       => $new_end,
            'days'           => $new_days,
            'approval_chain' => $chain,
            'updated_at'     => Helpers::now_mysql(),
        ],
        ['id' => $id],
        ['%s','%d','%s','%s'],
        ['%d']
    );

    // Recalculate balance after shortening (same logic as in handle_approve)
    $year = (int) substr($row['start_date'], 0, 4);

    $used = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COALESCE(SUM(days),0)
             FROM $req_t
             WHERE employee_id=%d
               AND type_id=%d
               AND status='approved'
               AND YEAR(start_date)=%d",
            (int) $row['employee_id'],
            (int) $row['type_id'],
            $year
        )
    );

    // Get type to fetch quota
    $type = $wpdb->get_row(
        $wpdb->prepare("SELECT annual_quota FROM $types_t WHERE id=%d", (int) $row['type_id']),
        ARRAY_A
    );
    $quota = (int) ( $type['annual_quota'] ?? 0 );

    $opening = 0;
    $carried = 0;
    $accrued = $quota;
    $closing = $opening + $accrued + $carried - $used;

    $bal_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $bal_t WHERE employee_id=%d AND type_id=%d AND year=%d",
            (int) $row['employee_id'],
            (int) $row['type_id'],
            $year
        )
    );

    if ($bal_id) {
        $wpdb->update(
            $bal_t,
            [
                'opening'      => $opening,
                'accrued'      => $accrued,
                'used'         => $used,
                'carried_over' => $carried,
                'closing'      => $closing,
                'updated_at'   => Helpers::now_mysql(),
            ],
            ['id' => $bal_id]
        );
    }

    // Notify employee
    $this->notify_requester(
        (int) $row['employee_id'],
        __('Leave shortened (early return)', 'sfs-hr'),
        sprintf(
            __('Your leave was shortened. New period: %s → %s (%d days).', 'sfs-hr'),
            $start,
            $new_end,
            $new_days
        )
    );

    wp_safe_redirect(
        add_query_arg('ok', 1, $redirect_base)
    );
    exit;
}

    /**
     * Adds "Leave" tab to the Employee Profile screen (admin only).
     */
    public function employee_tab( \stdClass $employee ): void {
    // We only care about Employee Profile + My Profile admin pages
    $page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';

    if ( $page !== 'sfs-hr-employee-profile' && $page !== 'sfs-hr-my-profile' ) {
        return;
    }

    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview';

    // Permissions:
    if ( $page === 'sfs-hr-employee-profile' ) {
        // Manager / HR view
        if ( ! current_user_can( 'sfs_hr_leave_admin' ) && ! current_user_can( 'manage_options' ) ) {
            return;
        }
    } else {
        // My Profile self-view: only the linked user can see their own Leave tab
        if ( ! is_user_logged_in() || (int) $employee->user_id !== get_current_user_id() ) {
            return;
        }
    }

    // Build base args for the tab URL
    $args = [
        'page' => $page,
    ];

    if ( $page === 'sfs-hr-employee-profile' ) {
        $args['employee_id'] = (int) $employee->id;

        // Preserve month filter if present
        if ( isset( $_GET['ym'] ) ) {
            $ym = sanitize_text_field( wp_unslash( (string) $_GET['ym'] ) );
            if ( preg_match( '/^\d{4}-\d{2}$/', $ym ) ) {
                $args['ym'] = $ym;
            }
        }
    }

    $url = add_query_arg(
        array_merge( $args, [ 'tab' => 'leave' ] ),
        admin_url( 'admin.php' )
    );

    $class = 'nav-tab' . ( $active_tab === 'leave' ? ' nav-tab-active' : '' );

    echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">';
    esc_html_e( 'Leave', 'sfs-hr' );
    echo '</a>';
}


    /**
     * Content for the "Leave" tab in Employee Profile.
     */
    public function employee_tab_content( \stdClass $employee, string $active_tab ): void {
    $page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';

    // Only run on our two profile pages
    if ( $page !== 'sfs-hr-employee-profile' && $page !== 'sfs-hr-my-profile' ) {
        return;
    }

    if ( $active_tab !== 'leave' ) {
        return;
    }

    // Permissions
    if ( $page === 'sfs-hr-employee-profile' ) {
        // Manager / HR view
        if ( ! current_user_can( 'sfs_hr_leave_admin' ) && ! current_user_can( 'manage_options' ) ) {
            echo '<p>' . esc_html__( 'You do not have permission to view leave details for this employee.', 'sfs-hr' ) . '</p>';
            return;
        }
    } else {
        // My Profile self-view
        if ( ! is_user_logged_in() || (int) $employee->user_id !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own leave information.', 'sfs-hr' ) . '</p>';
            return;
        }
    }

    global $wpdb;

    $req_table  = $wpdb->prefix . 'sfs_hr_leave_requests';
    $type_table = $wpdb->prefix . 'sfs_hr_leave_types';

    $rows = $wpdb->get_results(
    $wpdb->prepare(
        "
        SELECT r.*, t.name AS type_name
        FROM {$req_table} r
        LEFT JOIN {$type_table} t ON t.id = r.type_id
        WHERE r.employee_id = %d
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT 100
        ",
        (int) $employee->id
    )
);

    echo '<div class="sfs-hr-employee-leave-tab" style="margin-top:16px;">';

    if ( $page === 'sfs-hr-my-profile' ) {
        echo '<h2>' . esc_html__( 'My Leave', 'sfs-hr' ) . '</h2>';

        // Self-service request form (only on My Profile)
        $this->render_self_request_form( $employee );
    } else {
        echo '<h2>' . esc_html__( 'Leave for this employee', 'sfs-hr' ) . '</h2>';
    }

    // History table
    if ( empty( $rows ) ) {
        echo '<p>' . esc_html__( 'No leave requests found.', 'sfs-hr' ) . '</p>';
        echo '</div>';
        return;
    }

    echo '<h3 style="margin-top:16px;">' . esc_html__( 'Leave history', 'sfs-hr' ) . '</h3>';

    echo '<table class="widefat fixed striped" style="margin-top:8px;">';
echo '<thead><tr>';
echo '<th>' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Period', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Days', 'sfs-hr' ) . '</th>';  // ✅ Added
echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Requested at', 'sfs-hr' ) . '</th>';
echo '</tr></thead><tbody>';

foreach ( $rows as $row ) {
    $type_name = $row->type_name ?: __( 'N/A', 'sfs-hr' );

    $start  = $row->start_date ?: '';
    $end    = $row->end_date ?: '';
    $period = $start;
    if ( $start && $end && $end !== $start ) {
        $period = $start . ' → ' . $end;
    }

    $days = isset( $row->days ) ? (int) $row->days : 0;  // ✅ Get days

$status      = (string) $row->status;
$status_html = Leave_UI::leave_status_chip( $status );


    $created_at = $row->created_at ?? '';

    echo '<tr>';
    echo '<td>' . esc_html( $type_name ) . '</td>';
    echo '<td>' . esc_html( $period ) . '</td>';
    echo '<td>' . esc_html( $days ) . '</td>';  // ✅ Added
    echo '<td>' . $status_html . '</td>';
    echo '<td>' . $this->fmt_dt( $created_at ) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
    echo '</div>';
}

/**
 * Self-service leave request form on My Profile → Leave tab.
 */
private function render_self_request_form( \stdClass $employee ): void {
    global $wpdb;

    $type_table = $wpdb->prefix . 'sfs_hr_leave_types';

    $types = $wpdb->get_results(
        "SELECT id, name 
         FROM {$type_table}
         WHERE active = 1
         ORDER BY name ASC"
    );

    echo '<div class="sfs-hr-leave-self-form-wrap" style="margin-top:12px;margin-bottom:20px;">';
    echo '<h3>' . esc_html__( 'Request new leave', 'sfs-hr' ) . '</h3>';

    // Add mobile-specific CSS to fix spacing
    echo '<style>
        @media screen and (max-width: 782px) {
            .sfs-hr-leave-self-form textarea.large-text {
                min-height: 80px !important;
                height: auto !important;
            }
            .sfs-hr-leave-self-form .form-table th,
            .sfs-hr-leave-self-form .form-table td {
                padding: 10px 0;
            }
            .sfs-hr-leave-self-form input[type="date"],
            .sfs-hr-leave-self-form select,
            .sfs-hr-leave-self-form textarea {
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
        }
    </style>';

    if ( empty( $types ) ) {
        echo '<p class="description">' . esc_html__( 'Leave types are not configured yet. Please contact HR.', 'sfs-hr' ) . '</p>';
        echo '</div>';
        return;
    }

    $action_url = admin_url( 'admin-post.php' );

    echo '<form method="post" action="' . esc_url( $action_url ) . '" class="sfs-hr-leave-self-form">';
    wp_nonce_field( 'sfs_hr_leave_request_self' );

    echo '<input type="hidden" name="action" value="sfs_hr_leave_request_self" />';

    echo '<table class="form-table"><tbody>';

    // Type
    echo '<tr>';
    echo '<th scope="row"><label for="sfs-hr-leave-type">' . esc_html__( 'Leave type', 'sfs-hr' ) . '</label></th>';
    echo '<td>';
    echo '<select name="type_id" id="sfs-hr-leave-type" required>';
    echo '<option value="">' . esc_html__( 'Select type', 'sfs-hr' ) . '</option>';
    foreach ( $types as $type ) {
        echo '<option value="' . (int) $type->id . '">' . esc_html( $type->name ) . '</option>';
    }
    echo '</select>';
    echo '</td>';
    echo '</tr>';

    // Start date
    echo '<tr>';
    echo '<th scope="row"><label for="sfs-hr-leave-start">' . esc_html__( 'Start date', 'sfs-hr' ) . '</label></th>';
    echo '<td>';
    echo '<input type="date" name="start_date" id="sfs-hr-leave-start" required />';
    echo '</td>';
    echo '</tr>';

    // End date
    echo '<tr>';
    echo '<th scope="row"><label for="sfs-hr-leave-end">' . esc_html__( 'End date', 'sfs-hr' ) . '</label></th>';
    echo '<td>';
    echo '<input type="date" name="end_date" id="sfs-hr-leave-end" />';
    echo '<p class="description">' . esc_html__( 'If left empty, it will use the same as start date.', 'sfs-hr' ) . '</p>';
    echo '</td>';
    echo '</tr>';

    // Reason
    echo '<tr>';
    echo '<th scope="row"><label for="sfs-hr-leave-reason">' . esc_html__( 'Reason / note', 'sfs-hr' ) . '</label></th>';
    echo '<td>';
    echo '<textarea name="reason" id="sfs-hr-leave-reason" class="large-text" rows="3"></textarea>';
    echo '</td>';
    echo '</tr>';

    echo '</tbody></table>';

    submit_button( __( 'Submit leave request', 'sfs-hr' ) );

    echo '</form>';
    echo '</div>';
}


/**
 * Handle self-service leave request from My Profile.
 */
public function handle_self_request(): void {
    if ( ! is_user_logged_in() ) {
        wp_die( esc_html__( 'You must be logged in to request leave.', 'sfs-hr' ) );
    }

    check_admin_referer( 'sfs_hr_leave_request_self' );

    $user_id = get_current_user_id();

    global $wpdb;

    $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
    $req_table  = $wpdb->prefix . 'sfs_hr_leave_requests';
    $type_table = $wpdb->prefix . 'sfs_hr_leave_types';

    // Map user -> employee
    $employee_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$emp_table} WHERE user_id = %d LIMIT 1",
            $user_id
        )
    );

    if ( ! $employee_id ) {
        $this->redirect_back_with_msg( 'leave_err', 'no_employee' );
    }

    $type_id = isset( $_POST['type_id'] ) ? (int) $_POST['type_id'] : 0;
    $start   = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
    $end     = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
    $reason  = isset( $_POST['reason'] ) ? wp_kses_post( wp_unslash( $_POST['reason'] ) ) : '';

    if ( $type_id <= 0 || $start === '' ) {
        $this->redirect_back_with_msg( 'leave_err', 'missing_fields' );
    }

    if ( $end === '' ) {
        $end = $start;
    }

    $start_ts = strtotime( $start );
    $end_ts   = strtotime( $end );

    if ( ! $start_ts || ! $end_ts || $end_ts < $start_ts ) {
        $this->redirect_back_with_msg( 'leave_err', 'invalid_dates' );
    }

    // Inclusive days count
    $days = (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;
    if ( $days < 1 ) {
        $days = 1;
    }

    // Get leave type special_code to know if this is sick leave
    $type_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT special_code FROM {$type_table} WHERE id = %d",
            $type_id
        )
    );
    $special = $type_row && isset( $type_row->special_code )
        ? (string) $type_row->special_code
        : '';

    $requires_doc = in_array( $special, [ 'SICK_SHORT', 'SICK_LONG' ], true );

    // Handle supporting document upload (only if provided; required for sick leave)
    $attach_id = 0;

    if ( ! empty( $_FILES['supporting_doc']['name'] ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attach_id = media_handle_upload( 'supporting_doc', 0 );

        if ( is_wp_error( $attach_id ) ) {
            error_log( '[SFS HR] Leave doc upload failed: ' . $attach_id->get_error_message() );
            if ( $requires_doc ) {
                $this->redirect_back_with_msg( 'leave_err', 'doc_upload' );
            }
            $attach_id = 0;
        }
    } elseif ( $requires_doc ) {
        // Sick leave type but no file uploaded
        $this->redirect_back_with_msg( 'leave_err', 'doc_required' );
    }

    $now = current_time( 'mysql' );

    // Insert with all required fields
    $insert_data = [
        'employee_id'      => $employee_id,
        'type_id'          => $type_id,
        'start_date'       => $start,
        'end_date'         => $end,
        'days'             => $days,
        'reason'           => $reason,
        'status'           => 'pending',
        'doc_attachment_id'=> $attach_id ?: null,
        'created_at'       => $now,
        'updated_at'       => $now,
    ];

    $result = $wpdb->insert( $req_table, $insert_data );

    if ( $result === false ) {
        error_log( '[SFS HR] Leave request insert failed: ' . $wpdb->last_error );
        $this->redirect_back_with_msg( 'leave_err', 'db_error' );
    }

    // Optional: email approvers
    if ( get_option( 'sfs_hr_leave_email', '1' ) === '1' ) {
        $this->email_approvers_for_employee(
            $employee_id,
            __( 'New Leave Request', 'sfs-hr' ),
            sprintf(
                __( 'Employee requested leave: %s → %s (%d days).', 'sfs-hr' ),
                $start,
                $end,
                $days
            )
        );
    }

    $this->redirect_back_with_msg( 'leave_msg', 'submitted' );
}


/**
 * Redirect back to the page the user came from (frontend or admin).
 */
private function redirect_back_with_msg( string $key, string $value ): void {
    // Detect if referrer is frontend or admin
    $referrer = wp_get_referer();
    
    // Check if referrer contains 'wp-admin' to determine context
    $is_admin = $referrer && strpos( $referrer, '/wp-admin/' ) !== false;
    
    if ( $is_admin ) {
        // Admin context: redirect to admin My Profile
        $args = [
            'page' => 'sfs-hr-my-profile',
            'tab'  => 'leave',
            $key   => $value,
        ];
        $url = add_query_arg( $args, admin_url( 'admin.php' ) );
    } else {
        // Frontend context: redirect back to referrer with query params
        $url = add_query_arg( [ $key => $value ], $referrer ?: home_url() );
    }
    
    wp_safe_redirect( $url );
    exit;
}





}
