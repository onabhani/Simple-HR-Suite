<?php
namespace SFS\HR\Core;
if ( ! defined('ABSPATH') ) { exit; }

class Leaves {
    public function hooks(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_sfs_hr_add_leave_type', [$this, 'handle_add_type']);
        add_action('admin_post_sfs_hr_delete_leave_type', [$this, 'handle_delete_type']);
        add_action('admin_post_sfs_hr_approve_leave', [$this, 'handle_approve_leave']);
        add_action('admin_post_sfs_hr_reject_leave', [$this, 'handle_reject_leave']);
        add_action('admin_post_sfs_hr_submit_leave', [$this, 'handle_submit_leave']);
    }
    public function menu(): void {
        add_submenu_page('sfs-hr', __('Leave Requests','sfs-hr'), __('Leave Requests','sfs-hr'), 'sfs_hr.manage', 'sfs-hr-leave-requests', [$this, 'render_requests']);
        add_submenu_page('sfs-hr', __('Leave Types','sfs-hr'), __('Leave Types','sfs-hr'), 'sfs_hr.manage', 'sfs-hr-leave-types', [$this, 'render_types']);
    }
    public function render_types(): void {
        Helpers::require_cap('sfs_hr.manage');
        global $wpdb; $t = $wpdb->prefix.'sfs_hr_leave_types';
        $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY name ASC", ARRAY_A);
        $nonce_add = wp_create_nonce('sfs_hr_add_leave_type');
        ?>
        <div class="wrap">
          <h1><?php esc_html_e('Leave Types','sfs-hr'); ?></h1>
          <h2><?php esc_html_e('Add New Type','sfs-hr'); ?></h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sfs_hr_add_leave_type" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_add); ?>" />
            <input type="text" name="name" class="regular-text" required />
            <?php submit_button(__('Add','sfs-hr'),'secondary','',false); ?>
          </form>
          <h2 style="margin-top:20px;"><?php esc_html_e('Existing Types','sfs-hr'); ?></h2>
          <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('ID','sfs-hr'); ?></th><th><?php esc_html_e('Name','sfs-hr'); ?></th><th><?php esc_html_e('Actions','sfs-hr'); ?></th></tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="3"><?php esc_html_e('No types yet.','sfs-hr'); ?></td></tr>
            <?php else: foreach($rows as $r):
                $del = wp_nonce_url( admin_url('admin-post.php?action=sfs_hr_delete_leave_type&id='.(int)$r['id']), 'sfs_hr_delete_leave_type_'.(int)$r['id'] );
            ?>
              <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><?php echo esc_html($r['name']); ?></td>
                <td><a href="<?php echo esc_url($del); ?>" onclick="return confirm('Delete this type? It will be blocked if in use.');"><?php esc_html_e('Delete','sfs-hr'); ?></a></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
    public function handle_add_type(): void {
        Helpers::require_cap('sfs_hr.manage');
        check_admin_referer('sfs_hr_add_leave_type');
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        if (!$name) { wp_safe_redirect( admin_url('admin.php?page=sfs-hr-leave-types&err=noname') ); exit; }
        global $wpdb; $t = $wpdb->prefix.'sfs_hr_leave_types';
        $exists = (int)$wpdb->get_var( $wpdb->prepare("SELECT id FROM {$t} WHERE name=%s", $name) );
        if ($exists) { wp_safe_redirect( admin_url('admin.php?page=sfs-hr-leave-types&err=duplicate') ); exit; }
        $wpdb->insert($t, ['name'=>$name,'created_at'=>Helpers::now_mysql()]);
        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-leave-types&ok=added') ); exit;
    }
    public function handle_delete_type(): void {
        Helpers::require_cap('sfs_hr.manage');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        check_admin_referer('sfs_hr_delete_leave_type_'.$id);
        if ($id<=0) { wp_safe_redirect( admin_url('admin.php?page=sfs-hr-leave-types&err=id') ); exit; }
        global $wpdb; 
        $req = $wpdb->prefix.'sfs_hr_leave_requests';
        $in_use = (int)$wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$req} WHERE type_id=%d", $id) );
        if ($in_use>0) { wp_safe_redirect( admin_url('admin.php?page=sfs-hr-leave-types&err=inuse') ); exit; }
        $t = $wpdb->prefix.'sfs_hr_leave_types';
        $wpdb->delete($t, ['id'=>$id], ['%d']);
        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-leave-types&ok=deleted') ); exit;
    }
    public function render_requests(): void {
        Helpers::require_cap('sfs_hr.manage');
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $valid = ['pending','approved','rejected'];
        if ($status && !in_array($status,$valid,true)) $status='';
        global $wpdb; $r = $wpdb->prefix.'sfs_hr_leave_requests'; $e=$wpdb->prefix.'sfs_hr_employees'; $t=$wpdb->prefix.'sfs_hr_leave_types';
        $where = '1=1'; $params=[];
        if ($status) { $where .= " AND lr.status=%s"; $params[]=$status; }
        $sql = "SELECT lr.*, e.employee_code, e.first_name, e.last_name, t.name AS type_name
                FROM {$r} lr
                JOIN {$e} e ON e.id = lr.employee_id
                JOIN {$t} t ON t.id = lr.type_id
                WHERE {$where} ORDER BY lr.id DESC";
        $rows = $params ? $wpdb->get_results( $wpdb->prepare($sql, ...$params), ARRAY_A ) : $wpdb->get_results($sql, ARRAY_A);
        ?>
        <div class="wrap">
          <h1><?php esc_html_e('Leave Requests','sfs-hr'); ?></h1>
          <form method="get" style="margin:10px 0;">
            <input type="hidden" name="page" value="sfs-hr-leave-requests" />
            <select name="status">
              <option value=""><?php esc_html_e('All statuses','sfs-hr'); ?></option>
              <?php foreach (['pending','approved','rejected'] as $s): ?>
                <option value="<?php echo esc_attr($s); ?>" <?php selected($status,$s); ?>><?php echo esc_html(ucfirst($s)); ?></option>
              <?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter','sfs-hr'),'secondary','',false); ?>
          </form>
          <table class="widefat striped">
            <thead>
              <tr>
                <th><?php esc_html_e('ID','sfs-hr'); ?></th>
                <th><?php esc_html_e('Employee','sfs-hr'); ?></th>
                <th><?php esc_html_e('Type','sfs-hr'); ?></th>
                <th><?php esc_html_e('Dates','sfs-hr'); ?></th>
                <th><?php esc_html_e('Days','sfs-hr'); ?></th>
                <th><?php esc_html_e('Reason','sfs-hr'); ?></th>
                <th><?php esc_html_e('Status','sfs-hr'); ?></th>
                <th><?php esc_html_e('Actions','sfs-hr'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="8"><?php esc_html_e('No requests found.','sfs-hr'); ?></td></tr>
              <?php else: foreach ($rows as $r): 
                  $approve = wp_nonce_url( admin_url('admin-post.php?action=sfs_hr_approve_leave&id='.(int)$r['id']), 'sfs_hr_approve_leave_'.(int)$r['id'] );
                  $reject  = wp_nonce_url( admin_url('admin-post.php?action=sfs_hr_reject_leave&id='.(int)$r['id']),  'sfs_hr_reject_leave_'.(int)$r['id'] );
              ?>
                <tr>
                  <td><?php echo (int)$r['id']; ?></td>
                  <td><?php echo esc_html($r['employee_code'].' - '.$r['first_name'].' '.$r['last_name']); ?></td>
                  <td><?php echo esc_html($r['type_name']); ?></td>
                  <td><?php echo esc_html($r['start_date'].' → '.$r['end_date']); ?></td>
                  <td><?php echo esc_html($r['days']); ?></td>
                  <td><?php echo esc_html($r['reason']); ?></td>
                  <td><?php echo esc_html(ucfirst($r['status'])); ?></td>
                  <td>
                    <?php if ($r['status']==='pending'): ?>
                      <a href="<?php echo esc_url($approve); ?>"><?php esc_html_e('Approve','sfs-hr'); ?></a> |
                      <a href="<?php echo esc_url($reject); ?>"><?php esc_html_e('Reject','sfs-hr'); ?></a>
                    <?php else: ?>
                      &ndash;
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php
    }
    public function handle_approve_leave(): void {
        Helpers::require_cap('sfs_hr.leave.approve');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        check_admin_referer('sfs_hr_approve_leave_'.$id);
        if ($id<=0) wp_safe_redirect( admin_url('admin.php?page=sfs-hr-leave-requests&err=id') );
        global $wpdb; $r = $wpdb->prefix.'sfs_hr_leave_requests';
        $wpdb->update($r, ['status'=>'approved','approved_by'=>get_current_user_id(),'approved_at'=>Helpers::now_mysql(),'updated_at'=>Helpers::now_mysql()], ['id'=>$id]);
        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-leave-requests&ok=approved') ); exit;
    }
    public function handle_reject_leave(): void {
        Helpers::require_cap('sfs_hr.leave.approve');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        check_admin_referer('sfs_hr_reject_leave_'.$id);
        if ($id<=0) wp_safe_redirect( admin_url('admin.php?page=sfs-hr-leave-requests&err=id') );
        global $wpdb; $r = $wpdb->prefix.'sfs_hr_leave_requests';
        $wpdb->update($r, ['status'=>'rejected','approved_by'=>get_current_user_id(),'approved_at'=>Helpers::now_mysql(),'updated_at'=>Helpers::now_mysql()], ['id'=>$id]);
        wp_safe_redirect( admin_url('admin.php?page=sfs-hr-leave-requests&ok=rejected') ); exit;
    }
    public function handle_submit_leave(): void {
        if ( ! is_user_logged_in() ) { wp_die( esc_html__('Login required','sfs-hr') ); }
        check_admin_referer('sfs_hr_submit_leave');
        $emp_id = Helpers::current_employee_id();
        if (!$emp_id) { wp_die( esc_html__('Your HR profile is not linked.','sfs-hr') ); }
        $type_id = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
        $start   = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end     = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';
        $reason  = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';
        if (!$type_id || !$start || !$end) { wp_safe_redirect( add_query_arg(['lr'=>'err'], wp_get_referer() ?: home_url()) ); exit; }
        // Simple validation
        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ) {
            wp_safe_redirect( add_query_arg(['lr'=>'date'], wp_get_referer() ?: home_url()) ); exit;
        }
        $days = (strtotime($end) - strtotime($start)) / 86400 + 1;
        if ($days <= 0) { wp_safe_redirect( add_query_arg(['lr'=>'range'], wp_get_referer() ?: home_url()) ); exit; }
        global $wpdb; $r = $wpdb->prefix.'sfs_hr_leave_requests';
        $wpdb->insert($r, [
            'employee_id' => $emp_id,
            'type_id'     => $type_id,
            'start_date'  => $start,
            'end_date'    => $end,
            'days'        => $days,
            'reason'      => $reason,
            'status'      => 'pending',
            'created_by'  => get_current_user_id(),
            'created_at'  => Helpers::now_mysql(),
            'updated_at'  => Helpers::now_mysql(),
        ], ['%d','%d','%s','%s','%f','%s','%s','%d','%s','%s']);
        wp_safe_redirect( add_query_arg(['lr'=>'ok'], wp_get_referer() ?: home_url()) ); exit;
    }
}
