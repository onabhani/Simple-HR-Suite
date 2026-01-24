<?php
namespace SFS\HR\Modules\Hiring\Admin;

use SFS\HR\Modules\Hiring\HiringModule;

if (!defined('ABSPATH')) { exit; }

class AdminPages {

    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function hooks(): void {
        add_action('admin_post_sfs_hr_add_candidate', [$this, 'handle_add_candidate']);
        add_action('admin_post_sfs_hr_update_candidate', [$this, 'handle_update_candidate']);
        add_action('admin_post_sfs_hr_candidate_action', [$this, 'handle_candidate_action']);
        add_action('admin_post_sfs_hr_add_trainee', [$this, 'handle_add_trainee']);
        add_action('admin_post_sfs_hr_update_trainee', [$this, 'handle_update_trainee']);
        add_action('admin_post_sfs_hr_trainee_action', [$this, 'handle_trainee_action']);
    }

    /**
     * Render main page
     */
    public function render_page(): void {
        $tab = $_GET['tab'] ?? 'candidates';
        $action = $_GET['action'] ?? 'list';

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e('Hiring Management', 'sfs-hr'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=sfs-hr-hiring&tab=candidates" class="nav-tab <?php echo $tab === 'candidates' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Candidates', 'sfs-hr'); ?>
                </a>
                <a href="?page=sfs-hr-hiring&tab=trainees" class="nav-tab <?php echo $tab === 'trainees' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Trainees', 'sfs-hr'); ?>
                </a>
            </nav>

            <div class="tab-content" style="margin-top:20px;">
                <?php
                if ($tab === 'candidates') {
                    $this->render_candidates_tab($action);
                } else {
                    $this->render_trainees_tab($action);
                }
                ?>
            </div>
        </div>

        <style>
            .sfs-hr-card { background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:20px; margin-bottom:20px; }
            .sfs-hr-card h3 { margin-top:0; padding-bottom:10px; border-bottom:1px solid #eee; }
            .sfs-hr-form-row { margin-bottom:15px; }
            .sfs-hr-form-row label { display:block; margin-bottom:5px; font-weight:600; }
            .sfs-hr-form-row input[type="text"],
            .sfs-hr-form-row input[type="email"],
            .sfs-hr-form-row input[type="tel"],
            .sfs-hr-form-row input[type="date"],
            .sfs-hr-form-row input[type="number"],
            .sfs-hr-form-row select,
            .sfs-hr-form-row textarea { width:100%; max-width:400px; }
            .sfs-hr-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
            @media (max-width:782px) { .sfs-hr-form-grid { grid-template-columns:1fr; } }
            .sfs-hr-status-badge { display:inline-block; padding:4px 10px; border-radius:12px; font-size:12px; font-weight:500; }
            .sfs-hr-status-applied { background:#e3f2fd; color:#1565c0; }
            .sfs-hr-status-screening { background:#fff3e0; color:#ef6c00; }
            .sfs-hr-status-dept_pending { background:#fce4ec; color:#c2185b; }
            .sfs-hr-status-dept_approved { background:#e8f5e9; color:#2e7d32; }
            .sfs-hr-status-gm_pending { background:#f3e5f5; color:#7b1fa2; }
            .sfs-hr-status-gm_approved { background:#e0f2f1; color:#00695c; }
            .sfs-hr-status-hired { background:#c8e6c9; color:#1b5e20; }
            .sfs-hr-status-rejected { background:#ffebee; color:#c62828; }
            .sfs-hr-status-active { background:#e8f5e9; color:#2e7d32; }
            .sfs-hr-status-completed { background:#e3f2fd; color:#1565c0; }
            .sfs-hr-status-converted { background:#fff3e0; color:#ef6c00; }
            .sfs-hr-status-archived { background:#f5f5f5; color:#616161; }
        </style>
        <?php
    }

    /**
     * Render candidates tab
     */
    private function render_candidates_tab(string $action): void {
        switch ($action) {
            case 'add':
                $this->render_candidate_form();
                break;
            case 'edit':
                $this->render_candidate_form((int) ($_GET['id'] ?? 0));
                break;
            case 'view':
                $this->render_candidate_view((int) ($_GET['id'] ?? 0));
                break;
            default:
                $this->render_candidates_list();
        }
    }

    /**
     * Render candidates list
     */
    private function render_candidates_list(): void {
        global $wpdb;

        $status_filter = $_GET['status'] ?? '';
        $search = $_GET['s'] ?? '';

        $where = "WHERE 1=1";
        $params = [];

        if ($status_filter) {
            $where .= " AND c.status = %s";
            $params[] = $status_filter;
        }

        if ($search) {
            $where .= " AND (c.first_name LIKE %s OR c.last_name LIKE %s OR c.email LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $query = "SELECT c.*, d.name as dept_name
                  FROM {$wpdb->prefix}sfs_hr_candidates c
                  LEFT JOIN {$wpdb->prefix}sfs_hr_departments d ON c.dept_id = d.id
                  $where
                  ORDER BY c.created_at DESC";

        $candidates = $params ? $wpdb->get_results($wpdb->prepare($query, ...$params)) : $wpdb->get_results($query);
        $statuses = HiringModule::get_candidate_statuses();

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; border:none; padding:0;"><?php esc_html_e('Candidates', 'sfs-hr'); ?></h3>
                <a href="?page=sfs-hr-hiring&tab=candidates&action=add" class="button button-primary">
                    <?php esc_html_e('Add Candidate', 'sfs-hr'); ?>
                </a>
            </div>

            <!-- Filters -->
            <form method="get" style="margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap;">
                <input type="hidden" name="page" value="sfs-hr-hiring" />
                <input type="hidden" name="tab" value="candidates" />

                <select name="status">
                    <option value=""><?php esc_html_e('All Statuses', 'sfs-hr'); ?></option>
                    <?php foreach ($statuses as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($status_filter, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search...', 'sfs-hr'); ?>" />

                <button type="submit" class="button"><?php esc_html_e('Filter', 'sfs-hr'); ?></button>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Email', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Position', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Department', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Source', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Applied', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($candidates)) : ?>
                        <tr><td colspan="8"><?php esc_html_e('No candidates found.', 'sfs-hr'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($candidates as $c) : ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($c->first_name . ' ' . $c->last_name); ?></strong>
                                </td>
                                <td><?php echo esc_html($c->email); ?></td>
                                <td><?php echo esc_html($c->position_applied ?: '—'); ?></td>
                                <td><?php echo esc_html($c->dept_name ?: '—'); ?></td>
                                <td>
                                    <?php echo $c->application_source === 'manual'
                                        ? esc_html__('Manual', 'sfs-hr')
                                        : esc_html__('Applied', 'sfs-hr'); ?>
                                </td>
                                <td>
                                    <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($c->status); ?>">
                                        <?php echo esc_html($statuses[$c->status] ?? $c->status); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(wp_date('M j, Y', strtotime($c->created_at))); ?></td>
                                <td>
                                    <a href="?page=sfs-hr-hiring&tab=candidates&action=view&id=<?php echo (int) $c->id; ?>"><?php esc_html_e('View', 'sfs-hr'); ?></a>
                                    |
                                    <a href="?page=sfs-hr-hiring&tab=candidates&action=edit&id=<?php echo (int) $c->id; ?>"><?php esc_html_e('Edit', 'sfs-hr'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render candidate form (add/edit)
     */
    private function render_candidate_form(int $id = 0): void {
        global $wpdb;

        $candidate = null;
        if ($id > 0) {
            $candidate = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_candidates WHERE id = %d",
                $id
            ));
        }

        $departments = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments WHERE active = 1 ORDER BY name");
        $is_edit = $candidate !== null;

        ?>
        <div class="sfs-hr-card">
            <h3><?php echo $is_edit ? esc_html__('Edit Candidate', 'sfs-hr') : esc_html__('Add Candidate', 'sfs-hr'); ?></h3>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sfs_hr_candidate_form'); ?>
                <input type="hidden" name="action" value="<?php echo $is_edit ? 'sfs_hr_update_candidate' : 'sfs_hr_add_candidate'; ?>" />
                <?php if ($is_edit) : ?>
                    <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                <?php endif; ?>

                <div class="sfs-hr-form-grid">
                    <div>
                        <h4><?php esc_html_e('Personal Information', 'sfs-hr'); ?></h4>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('First Name', 'sfs-hr'); ?> *</label>
                            <input type="text" name="first_name" value="<?php echo esc_attr($candidate->first_name ?? ''); ?>" required />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Last Name', 'sfs-hr'); ?></label>
                            <input type="text" name="last_name" value="<?php echo esc_attr($candidate->last_name ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Email', 'sfs-hr'); ?> *</label>
                            <input type="email" name="email" value="<?php echo esc_attr($candidate->email ?? ''); ?>" required />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Phone', 'sfs-hr'); ?></label>
                            <input type="tel" name="phone" value="<?php echo esc_attr($candidate->phone ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Gender', 'sfs-hr'); ?></label>
                            <select name="gender">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <option value="male" <?php selected($candidate->gender ?? '', 'male'); ?>><?php esc_html_e('Male', 'sfs-hr'); ?></option>
                                <option value="female" <?php selected($candidate->gender ?? '', 'female'); ?>><?php esc_html_e('Female', 'sfs-hr'); ?></option>
                            </select>
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Date of Birth', 'sfs-hr'); ?></label>
                            <input type="date" name="date_of_birth" value="<?php echo esc_attr($candidate->date_of_birth ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Nationality', 'sfs-hr'); ?></label>
                            <input type="text" name="nationality" value="<?php echo esc_attr($candidate->nationality ?? ''); ?>" />
                        </div>
                    </div>

                    <div>
                        <h4><?php esc_html_e('Application Details', 'sfs-hr'); ?></h4>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Position Applied', 'sfs-hr'); ?></label>
                            <input type="text" name="position_applied" value="<?php echo esc_attr($candidate->position_applied ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Department', 'sfs-hr'); ?></label>
                            <select name="dept_id">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($departments as $d) : ?>
                                    <option value="<?php echo (int) $d->id; ?>" <?php selected($candidate->dept_id ?? '', $d->id); ?>>
                                        <?php echo esc_html($d->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Application Source', 'sfs-hr'); ?></label>
                            <select name="application_source">
                                <option value="applied" <?php selected($candidate->application_source ?? '', 'applied'); ?>><?php esc_html_e('Applied', 'sfs-hr'); ?></option>
                                <option value="manual" <?php selected($candidate->application_source ?? '', 'manual'); ?>><?php esc_html_e('Manual Entry', 'sfs-hr'); ?></option>
                            </select>
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Expected Salary', 'sfs-hr'); ?></label>
                            <input type="number" name="expected_salary" step="0.01" value="<?php echo esc_attr($candidate->expected_salary ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Available From', 'sfs-hr'); ?></label>
                            <input type="date" name="available_from" value="<?php echo esc_attr($candidate->available_from ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Resume URL', 'sfs-hr'); ?></label>
                            <input type="text" name="resume_url" value="<?php echo esc_attr($candidate->resume_url ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Notes', 'sfs-hr'); ?></label>
                            <textarea name="notes" rows="4"><?php echo esc_textarea($candidate->notes ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__('Update Candidate', 'sfs-hr') : esc_html__('Add Candidate', 'sfs-hr'); ?></button>
                    <a href="?page=sfs-hr-hiring&tab=candidates" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render candidate view with workflow actions
     */
    private function render_candidate_view(int $id): void {
        global $wpdb;

        $candidate = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, d.name as dept_name
             FROM {$wpdb->prefix}sfs_hr_candidates c
             LEFT JOIN {$wpdb->prefix}sfs_hr_departments d ON c.dept_id = d.id
             WHERE c.id = %d",
            $id
        ));

        if (!$candidate) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Candidate not found.', 'sfs-hr') . '</p></div>';
            return;
        }

        $statuses = HiringModule::get_candidate_statuses();
        $current_user_id = get_current_user_id();

        // Check if current user can approve
        $can_dept_approve = current_user_can('manage_options'); // You can add department manager check
        $can_gm_approve = current_user_can('manage_options'); // You can add GM check

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
                <div>
                    <h3 style="margin:0; border:none; padding:0;">
                        <?php echo esc_html($candidate->first_name . ' ' . $candidate->last_name); ?>
                    </h3>
                    <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($candidate->status); ?>" style="margin-top:10px;">
                        <?php echo esc_html($statuses[$candidate->status] ?? $candidate->status); ?>
                    </span>
                </div>
                <a href="?page=sfs-hr-hiring&tab=candidates&action=edit&id=<?php echo (int) $candidate->id; ?>" class="button">
                    <?php esc_html_e('Edit', 'sfs-hr'); ?>
                </a>
            </div>

            <div class="sfs-hr-form-grid">
                <div>
                    <h4><?php esc_html_e('Personal Information', 'sfs-hr'); ?></h4>
                    <table class="form-table">
                        <tr><th><?php esc_html_e('Email', 'sfs-hr'); ?></th><td><?php echo esc_html($candidate->email); ?></td></tr>
                        <tr><th><?php esc_html_e('Phone', 'sfs-hr'); ?></th><td><?php echo esc_html($candidate->phone ?: '—'); ?></td></tr>
                        <tr><th><?php esc_html_e('Gender', 'sfs-hr'); ?></th><td><?php echo esc_html(ucfirst($candidate->gender ?: '—')); ?></td></tr>
                        <tr><th><?php esc_html_e('Date of Birth', 'sfs-hr'); ?></th><td><?php echo $candidate->date_of_birth ? esc_html(wp_date('M j, Y', strtotime($candidate->date_of_birth))) : '—'; ?></td></tr>
                        <tr><th><?php esc_html_e('Nationality', 'sfs-hr'); ?></th><td><?php echo esc_html($candidate->nationality ?: '—'); ?></td></tr>
                    </table>
                </div>

                <div>
                    <h4><?php esc_html_e('Application Details', 'sfs-hr'); ?></h4>
                    <table class="form-table">
                        <tr><th><?php esc_html_e('Position', 'sfs-hr'); ?></th><td><?php echo esc_html($candidate->position_applied ?: '—'); ?></td></tr>
                        <tr><th><?php esc_html_e('Department', 'sfs-hr'); ?></th><td><?php echo esc_html($candidate->dept_name ?: '—'); ?></td></tr>
                        <tr><th><?php esc_html_e('Source', 'sfs-hr'); ?></th><td><?php echo $candidate->application_source === 'manual' ? esc_html__('Manual', 'sfs-hr') : esc_html__('Applied', 'sfs-hr'); ?></td></tr>
                        <tr><th><?php esc_html_e('Expected Salary', 'sfs-hr'); ?></th><td><?php echo $candidate->expected_salary ? number_format((float) $candidate->expected_salary, 2) : '—'; ?></td></tr>
                        <tr><th><?php esc_html_e('Available From', 'sfs-hr'); ?></th><td><?php echo $candidate->available_from ? esc_html(wp_date('M j, Y', strtotime($candidate->available_from))) : '—'; ?></td></tr>
                        <tr><th><?php esc_html_e('Applied Date', 'sfs-hr'); ?></th><td><?php echo esc_html(wp_date('M j, Y', strtotime($candidate->created_at))); ?></td></tr>
                    </table>
                </div>
            </div>

            <?php if ($candidate->notes) : ?>
                <h4><?php esc_html_e('Notes', 'sfs-hr'); ?></h4>
                <p><?php echo nl2br(esc_html($candidate->notes)); ?></p>
            <?php endif; ?>

            <?php if ($candidate->resume_url) : ?>
                <h4><?php esc_html_e('Resume', 'sfs-hr'); ?></h4>
                <p><a href="<?php echo esc_url($candidate->resume_url); ?>" target="_blank" class="button"><?php esc_html_e('View Resume', 'sfs-hr'); ?></a></p>
            <?php endif; ?>
        </div>

        <!-- Workflow Actions -->
        <?php if (!in_array($candidate->status, ['hired', 'rejected'])) : ?>
            <div class="sfs-hr-card">
                <h3><?php esc_html_e('Workflow Actions', 'sfs-hr'); ?></h3>

                <?php
                // Show appropriate action based on status
                switch ($candidate->status) {
                    case 'applied':
                    case 'screening':
                        // Move to Dept Manager Approval
                        ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:15px;">
                            <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                            <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                            <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                            <input type="hidden" name="workflow_action" value="send_to_dept" />
                            <button type="submit" class="button button-primary"><?php esc_html_e('Send to Department Manager for Approval', 'sfs-hr'); ?></button>
                        </form>
                        <?php
                        break;

                    case 'dept_pending':
                        if ($can_dept_approve) :
                            ?>
                            <p><strong><?php esc_html_e('Department Manager Approval Required', 'sfs-hr'); ?></strong></p>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:10px;">
                                <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                                <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                                <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                                <input type="hidden" name="workflow_action" value="dept_approve" />
                                <div class="sfs-hr-form-row">
                                    <label><?php esc_html_e('Notes (optional)', 'sfs-hr'); ?></label>
                                    <textarea name="notes" rows="2" style="width:100%;"></textarea>
                                </div>
                                <button type="submit" class="button button-primary"><?php esc_html_e('Approve', 'sfs-hr'); ?></button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                                <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                                <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                                <input type="hidden" name="workflow_action" value="reject" />
                                <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to reject this candidate?', 'sfs-hr'); ?>');"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>
                            </form>
                            <?php
                        endif;
                        break;

                    case 'dept_approved':
                        // Move to GM Approval
                        ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:15px;">
                            <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                            <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                            <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                            <input type="hidden" name="workflow_action" value="send_to_gm" />
                            <button type="submit" class="button button-primary"><?php esc_html_e('Send to GM for Final Approval', 'sfs-hr'); ?></button>
                        </form>
                        <?php
                        break;

                    case 'gm_pending':
                        if ($can_gm_approve) :
                            ?>
                            <p><strong><?php esc_html_e('GM Final Approval Required', 'sfs-hr'); ?></strong></p>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:15px;">
                                <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                                <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                                <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                                <input type="hidden" name="workflow_action" value="gm_approve" />
                                <div class="sfs-hr-form-row">
                                    <label><?php esc_html_e('Notes (optional)', 'sfs-hr'); ?></label>
                                    <textarea name="notes" rows="2" style="width:100%;"></textarea>
                                </div>
                                <button type="submit" class="button button-primary"><?php esc_html_e('Approve', 'sfs-hr'); ?></button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                                <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                                <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                                <input type="hidden" name="workflow_action" value="reject" />
                                <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to reject this candidate?', 'sfs-hr'); ?>');"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>
                            </form>
                            <?php
                        endif;
                        break;

                    case 'gm_approved':
                        // Can now hire
                        ?>
                        <p><strong><?php esc_html_e('Ready to Hire', 'sfs-hr'); ?></strong></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                            <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                            <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                            <input type="hidden" name="workflow_action" value="hire" />

                            <div class="sfs-hr-form-grid">
                                <div class="sfs-hr-form-row">
                                    <label><?php esc_html_e('Hire Date', 'sfs-hr'); ?> *</label>
                                    <input type="date" name="hired_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" required />
                                </div>
                                <div class="sfs-hr-form-row">
                                    <label><?php esc_html_e('Position', 'sfs-hr'); ?></label>
                                    <input type="text" name="offered_position" value="<?php echo esc_attr($candidate->position_applied); ?>" />
                                </div>
                                <div class="sfs-hr-form-row">
                                    <label><?php esc_html_e('Offered Salary', 'sfs-hr'); ?></label>
                                    <input type="number" name="offered_salary" step="0.01" value="<?php echo esc_attr($candidate->expected_salary); ?>" />
                                </div>
                                <div class="sfs-hr-form-row">
                                    <label><?php esc_html_e('Probation Period (months)', 'sfs-hr'); ?></label>
                                    <select name="probation_months">
                                        <option value="3"><?php esc_html_e('3 months', 'sfs-hr'); ?></option>
                                        <option value="6"><?php esc_html_e('6 months', 'sfs-hr'); ?></option>
                                    </select>
                                </div>
                            </div>

                            <button type="submit" class="button button-primary" style="margin-top:15px;"><?php esc_html_e('Hire & Create Employee', 'sfs-hr'); ?></button>
                        </form>
                        <?php
                        break;
                }
                ?>
            </div>
        <?php endif; ?>

        <!-- Approval History -->
        <?php if ($candidate->dept_approved_at || $candidate->gm_approved_at || $candidate->rejection_reason) : ?>
            <div class="sfs-hr-card">
                <h3><?php esc_html_e('Approval History', 'sfs-hr'); ?></h3>
                <table class="widefat">
                    <tbody>
                        <?php if ($candidate->dept_approved_at) : ?>
                            <tr>
                                <td><strong><?php esc_html_e('Dept. Manager Approved', 'sfs-hr'); ?></strong></td>
                                <td><?php echo esc_html(wp_date('M j, Y H:i', strtotime($candidate->dept_approved_at))); ?></td>
                                <td><?php echo $candidate->dept_notes ? esc_html($candidate->dept_notes) : '—'; ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($candidate->gm_approved_at) : ?>
                            <tr>
                                <td><strong><?php esc_html_e('GM Approved', 'sfs-hr'); ?></strong></td>
                                <td><?php echo esc_html(wp_date('M j, Y H:i', strtotime($candidate->gm_approved_at))); ?></td>
                                <td><?php echo $candidate->gm_notes ? esc_html($candidate->gm_notes) : '—'; ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($candidate->rejection_reason) : ?>
                            <tr style="background:#ffebee;">
                                <td><strong><?php esc_html_e('Rejected', 'sfs-hr'); ?></strong></td>
                                <td colspan="2"><?php echo esc_html($candidate->rejection_reason); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($candidate->hired_at) : ?>
                            <tr style="background:#e8f5e9;">
                                <td><strong><?php esc_html_e('Hired', 'sfs-hr'); ?></strong></td>
                                <td><?php echo esc_html(wp_date('M j, Y', strtotime($candidate->hired_at))); ?></td>
                                <td>
                                    <?php if ($candidate->employee_id) : ?>
                                        <a href="?page=sfs-hr-employees&action=view&id=<?php echo (int) $candidate->employee_id; ?>"><?php esc_html_e('View Employee Record', 'sfs-hr'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p>
            <a href="?page=sfs-hr-hiring&tab=candidates" class="button"><?php esc_html_e('Back to List', 'sfs-hr'); ?></a>
        </p>
        <?php
    }

    /**
     * Render trainees tab
     */
    private function render_trainees_tab(string $action): void {
        switch ($action) {
            case 'add':
                $this->render_trainee_form();
                break;
            case 'edit':
                $this->render_trainee_form((int) ($_GET['id'] ?? 0));
                break;
            case 'view':
                $this->render_trainee_view((int) ($_GET['id'] ?? 0));
                break;
            default:
                $this->render_trainees_list();
        }
    }

    /**
     * Render trainees list
     */
    private function render_trainees_list(): void {
        global $wpdb;

        $status_filter = $_GET['status'] ?? '';

        $where = "WHERE 1=1";
        $params = [];

        if ($status_filter) {
            $where .= " AND t.status = %s";
            $params[] = $status_filter;
        }

        $query = "SELECT t.*, d.name as dept_name,
                         CONCAT(e.first_name, ' ', e.last_name) as supervisor_name
                  FROM {$wpdb->prefix}sfs_hr_trainees t
                  LEFT JOIN {$wpdb->prefix}sfs_hr_departments d ON t.dept_id = d.id
                  LEFT JOIN {$wpdb->prefix}sfs_hr_employees e ON t.supervisor_id = e.id
                  $where
                  ORDER BY t.created_at DESC";

        $trainees = $params ? $wpdb->get_results($wpdb->prepare($query, ...$params)) : $wpdb->get_results($query);
        $statuses = HiringModule::get_trainee_statuses();

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; border:none; padding:0;"><?php esc_html_e('Trainees', 'sfs-hr'); ?></h3>
                <a href="?page=sfs-hr-hiring&tab=trainees&action=add" class="button button-primary">
                    <?php esc_html_e('Add Trainee', 'sfs-hr'); ?>
                </a>
            </div>

            <!-- Filters -->
            <form method="get" style="margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap;">
                <input type="hidden" name="page" value="sfs-hr-hiring" />
                <input type="hidden" name="tab" value="trainees" />

                <select name="status">
                    <option value=""><?php esc_html_e('All Statuses', 'sfs-hr'); ?></option>
                    <?php foreach ($statuses as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($status_filter, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="button"><?php esc_html_e('Filter', 'sfs-hr'); ?></button>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Code', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Name', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('University', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Department', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Supervisor', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Training Period', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trainees)) : ?>
                        <tr><td colspan="8"><?php esc_html_e('No trainees found.', 'sfs-hr'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($trainees as $t) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($t->trainee_code); ?></strong></td>
                                <td><?php echo esc_html($t->first_name . ' ' . $t->last_name); ?></td>
                                <td><?php echo esc_html($t->university ?: '—'); ?></td>
                                <td><?php echo esc_html($t->dept_name ?: '—'); ?></td>
                                <td><?php echo esc_html($t->supervisor_name ?: '—'); ?></td>
                                <td>
                                    <?php
                                    echo esc_html(wp_date('M j', strtotime($t->training_start)));
                                    echo ' - ';
                                    echo esc_html(wp_date('M j, Y', strtotime($t->training_extended_to ?: $t->training_end)));
                                    ?>
                                </td>
                                <td>
                                    <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($t->status); ?>">
                                        <?php echo esc_html($statuses[$t->status] ?? $t->status); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?page=sfs-hr-hiring&tab=trainees&action=view&id=<?php echo (int) $t->id; ?>"><?php esc_html_e('View', 'sfs-hr'); ?></a>
                                    |
                                    <a href="?page=sfs-hr-hiring&tab=trainees&action=edit&id=<?php echo (int) $t->id; ?>"><?php esc_html_e('Edit', 'sfs-hr'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render trainee form (add/edit)
     */
    private function render_trainee_form(int $id = 0): void {
        global $wpdb;

        $trainee = null;
        if ($id > 0) {
            $trainee = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_trainees WHERE id = %d",
                $id
            ));
        }

        $departments = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments WHERE active = 1 ORDER BY name");
        $employees = $wpdb->get_results("SELECT id, CONCAT(first_name, ' ', last_name) as name FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active' ORDER BY first_name");
        $is_edit = $trainee !== null;

        ?>
        <div class="sfs-hr-card">
            <h3><?php echo $is_edit ? esc_html__('Edit Trainee', 'sfs-hr') : esc_html__('Add Trainee', 'sfs-hr'); ?></h3>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sfs_hr_trainee_form'); ?>
                <input type="hidden" name="action" value="<?php echo $is_edit ? 'sfs_hr_update_trainee' : 'sfs_hr_add_trainee'; ?>" />
                <?php if ($is_edit) : ?>
                    <input type="hidden" name="trainee_id" value="<?php echo (int) $trainee->id; ?>" />
                <?php endif; ?>

                <div class="sfs-hr-form-grid">
                    <div>
                        <h4><?php esc_html_e('Personal Information', 'sfs-hr'); ?></h4>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('First Name', 'sfs-hr'); ?> *</label>
                            <input type="text" name="first_name" value="<?php echo esc_attr($trainee->first_name ?? ''); ?>" required />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Last Name', 'sfs-hr'); ?></label>
                            <input type="text" name="last_name" value="<?php echo esc_attr($trainee->last_name ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Email', 'sfs-hr'); ?> *</label>
                            <input type="email" name="email" value="<?php echo esc_attr($trainee->email ?? ''); ?>" required />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Phone', 'sfs-hr'); ?></label>
                            <input type="tel" name="phone" value="<?php echo esc_attr($trainee->phone ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Gender', 'sfs-hr'); ?></label>
                            <select name="gender">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <option value="male" <?php selected($trainee->gender ?? '', 'male'); ?>><?php esc_html_e('Male', 'sfs-hr'); ?></option>
                                <option value="female" <?php selected($trainee->gender ?? '', 'female'); ?>><?php esc_html_e('Female', 'sfs-hr'); ?></option>
                            </select>
                        </div>

                        <h4><?php esc_html_e('Education', 'sfs-hr'); ?></h4>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('University', 'sfs-hr'); ?></label>
                            <input type="text" name="university" value="<?php echo esc_attr($trainee->university ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Major', 'sfs-hr'); ?></label>
                            <input type="text" name="major" value="<?php echo esc_attr($trainee->major ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('GPA', 'sfs-hr'); ?></label>
                            <input type="text" name="gpa" value="<?php echo esc_attr($trainee->gpa ?? ''); ?>" />
                        </div>
                    </div>

                    <div>
                        <h4><?php esc_html_e('Training Details', 'sfs-hr'); ?></h4>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Department', 'sfs-hr'); ?></label>
                            <select name="dept_id">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($departments as $d) : ?>
                                    <option value="<?php echo (int) $d->id; ?>" <?php selected($trainee->dept_id ?? '', $d->id); ?>>
                                        <?php echo esc_html($d->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Supervisor', 'sfs-hr'); ?></label>
                            <select name="supervisor_id">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($employees as $e) : ?>
                                    <option value="<?php echo (int) $e->id; ?>" <?php selected($trainee->supervisor_id ?? '', $e->id); ?>>
                                        <?php echo esc_html($e->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Position/Role', 'sfs-hr'); ?></label>
                            <input type="text" name="position" value="<?php echo esc_attr($trainee->position ?? ''); ?>" />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Training Start Date', 'sfs-hr'); ?> *</label>
                            <input type="date" name="training_start" value="<?php echo esc_attr($trainee->training_start ?? ''); ?>" required />
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Training End Date', 'sfs-hr'); ?> *</label>
                            <input type="date" name="training_end" value="<?php echo esc_attr($trainee->training_end ?? ''); ?>" required />
                            <br><small><?php esc_html_e('Standard: 3 months, can be extended to 6 months', 'sfs-hr'); ?></small>
                        </div>

                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Notes', 'sfs-hr'); ?></label>
                            <textarea name="notes" rows="4"><?php echo esc_textarea($trainee->notes ?? ''); ?></textarea>
                        </div>

                        <?php if (!$is_edit) : ?>
                            <div class="sfs-hr-form-row">
                                <label>
                                    <input type="checkbox" name="create_user_account" value="1" />
                                    <?php esc_html_e('Create WordPress user account for trainee (for My HR Profile access)', 'sfs-hr'); ?>
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__('Update Trainee', 'sfs-hr') : esc_html__('Add Trainee', 'sfs-hr'); ?></button>
                    <a href="?page=sfs-hr-hiring&tab=trainees" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render trainee view with actions
     */
    private function render_trainee_view(int $id): void {
        global $wpdb;

        $trainee = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, d.name as dept_name,
                    CONCAT(e.first_name, ' ', e.last_name) as supervisor_name
             FROM {$wpdb->prefix}sfs_hr_trainees t
             LEFT JOIN {$wpdb->prefix}sfs_hr_departments d ON t.dept_id = d.id
             LEFT JOIN {$wpdb->prefix}sfs_hr_employees e ON t.supervisor_id = e.id
             WHERE t.id = %d",
            $id
        ));

        if (!$trainee) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Trainee not found.', 'sfs-hr') . '</p></div>';
            return;
        }

        $statuses = HiringModule::get_trainee_statuses();

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
                <div>
                    <h3 style="margin:0; border:none; padding:0;">
                        <?php echo esc_html($trainee->first_name . ' ' . $trainee->last_name); ?>
                    </h3>
                    <p style="margin:5px 0; color:#666;"><?php echo esc_html($trainee->trainee_code); ?></p>
                    <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($trainee->status); ?>">
                        <?php echo esc_html($statuses[$trainee->status] ?? $trainee->status); ?>
                    </span>
                </div>
                <a href="?page=sfs-hr-hiring&tab=trainees&action=edit&id=<?php echo (int) $trainee->id; ?>" class="button">
                    <?php esc_html_e('Edit', 'sfs-hr'); ?>
                </a>
            </div>

            <div class="sfs-hr-form-grid">
                <div>
                    <h4><?php esc_html_e('Personal Information', 'sfs-hr'); ?></h4>
                    <table class="form-table">
                        <tr><th><?php esc_html_e('Email', 'sfs-hr'); ?></th><td><?php echo esc_html($trainee->email); ?></td></tr>
                        <tr><th><?php esc_html_e('Phone', 'sfs-hr'); ?></th><td><?php echo esc_html($trainee->phone ?: '—'); ?></td></tr>
                        <tr><th><?php esc_html_e('Gender', 'sfs-hr'); ?></th><td><?php echo esc_html(ucfirst($trainee->gender ?: '—')); ?></td></tr>
                    </table>

                    <h4><?php esc_html_e('Education', 'sfs-hr'); ?></h4>
                    <table class="form-table">
                        <tr><th><?php esc_html_e('University', 'sfs-hr'); ?></th><td><?php echo esc_html($trainee->university ?: '—'); ?></td></tr>
                        <tr><th><?php esc_html_e('Major', 'sfs-hr'); ?></th><td><?php echo esc_html($trainee->major ?: '—'); ?></td></tr>
                        <tr><th><?php esc_html_e('GPA', 'sfs-hr'); ?></th><td><?php echo esc_html($trainee->gpa ?: '—'); ?></td></tr>
                    </table>
                </div>

                <div>
                    <h4><?php esc_html_e('Training Details', 'sfs-hr'); ?></h4>
                    <table class="form-table">
                        <tr><th><?php esc_html_e('Department', 'sfs-hr'); ?></th><td><?php echo esc_html($trainee->dept_name ?: '—'); ?></td></tr>
                        <tr><th><?php esc_html_e('Supervisor', 'sfs-hr'); ?></th><td><?php echo esc_html($trainee->supervisor_name ?: '—'); ?></td></tr>
                        <tr><th><?php esc_html_e('Position', 'sfs-hr'); ?></th><td><?php echo esc_html($trainee->position ?: '—'); ?></td></tr>
                        <tr><th><?php esc_html_e('Training Start', 'sfs-hr'); ?></th><td><?php echo esc_html(wp_date('M j, Y', strtotime($trainee->training_start))); ?></td></tr>
                        <tr><th><?php esc_html_e('Training End', 'sfs-hr'); ?></th><td><?php echo esc_html(wp_date('M j, Y', strtotime($trainee->training_extended_to ?: $trainee->training_end))); ?></td></tr>
                        <?php if ($trainee->user_id) : ?>
                            <tr><th><?php esc_html_e('User Account', 'sfs-hr'); ?></th><td><?php esc_html_e('Yes (has My HR Profile access)', 'sfs-hr'); ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <?php if ($trainee->status === 'active') : ?>
            <div class="sfs-hr-card">
                <h3><?php esc_html_e('Actions', 'sfs-hr'); ?></h3>

                <div style="display:flex; gap:20px; flex-wrap:wrap;">
                    <!-- Extend Training -->
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sfs_hr_trainee_action'); ?>
                        <input type="hidden" name="action" value="sfs_hr_trainee_action" />
                        <input type="hidden" name="trainee_id" value="<?php echo (int) $trainee->id; ?>" />
                        <input type="hidden" name="trainee_action" value="extend" />
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Extend Training To:', 'sfs-hr'); ?></label>
                            <input type="date" name="extend_to" required />
                        </div>
                        <button type="submit" class="button"><?php esc_html_e('Extend Training', 'sfs-hr'); ?></button>
                    </form>

                    <!-- Complete & Convert to Candidate -->
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sfs_hr_trainee_action'); ?>
                        <input type="hidden" name="action" value="sfs_hr_trainee_action" />
                        <input type="hidden" name="trainee_id" value="<?php echo (int) $trainee->id; ?>" />
                        <input type="hidden" name="trainee_action" value="convert" />
                        <p><strong><?php esc_html_e('Complete training and convert to job candidate:', 'sfs-hr'); ?></strong></p>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Convert to Candidate', 'sfs-hr'); ?></button>
                    </form>

                    <!-- Archive -->
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sfs_hr_trainee_action'); ?>
                        <input type="hidden" name="action" value="sfs_hr_trainee_action" />
                        <input type="hidden" name="trainee_id" value="<?php echo (int) $trainee->id; ?>" />
                        <input type="hidden" name="trainee_action" value="archive" />
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Archive Reason:', 'sfs-hr'); ?></label>
                            <textarea name="archive_reason" rows="2" style="width:200px;"></textarea>
                        </div>
                        <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to archive this trainee?', 'sfs-hr'); ?>');"><?php esc_html_e('Archive Trainee', 'sfs-hr'); ?></button>
                    </form>
                </div>
            </div>
        <?php elseif ($trainee->status === 'completed') : ?>
            <div class="sfs-hr-card">
                <h3><?php esc_html_e('Actions', 'sfs-hr'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_trainee_action'); ?>
                    <input type="hidden" name="action" value="sfs_hr_trainee_action" />
                    <input type="hidden" name="trainee_id" value="<?php echo (int) $trainee->id; ?>" />
                    <input type="hidden" name="trainee_action" value="convert" />
                    <button type="submit" class="button button-primary"><?php esc_html_e('Convert to Candidate', 'sfs-hr'); ?></button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($trainee->candidate_id) : ?>
            <div class="sfs-hr-card" style="background:#e8f5e9;">
                <p>
                    <strong><?php esc_html_e('This trainee was converted to a candidate.', 'sfs-hr'); ?></strong>
                    <a href="?page=sfs-hr-hiring&tab=candidates&action=view&id=<?php echo (int) $trainee->candidate_id; ?>"><?php esc_html_e('View Candidate Record', 'sfs-hr'); ?></a>
                </p>
            </div>
        <?php endif; ?>

        <p>
            <a href="?page=sfs-hr-hiring&tab=trainees" class="button"><?php esc_html_e('Back to List', 'sfs-hr'); ?></a>
        </p>
        <?php
    }

    // ========== Form Handlers ==========

    public function handle_add_candidate(): void {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_candidate_form')) {
            wp_die(__('Security check failed', 'sfs-hr'));
        }

        global $wpdb;
        $now = current_time('mysql');

        $wpdb->insert("{$wpdb->prefix}sfs_hr_candidates", [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'gender' => sanitize_text_field($_POST['gender'] ?? ''),
            'date_of_birth' => sanitize_text_field($_POST['date_of_birth'] ?? '') ?: null,
            'nationality' => sanitize_text_field($_POST['nationality'] ?? ''),
            'position_applied' => sanitize_text_field($_POST['position_applied'] ?? ''),
            'dept_id' => absint($_POST['dept_id'] ?? 0) ?: null,
            'application_source' => sanitize_text_field($_POST['application_source'] ?? 'applied'),
            'expected_salary' => floatval($_POST['expected_salary'] ?? 0) ?: null,
            'available_from' => sanitize_text_field($_POST['available_from'] ?? '') ?: null,
            'resume_url' => esc_url_raw($_POST['resume_url'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
            'status' => 'applied',
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=candidates&message=added'));
        exit;
    }

    public function handle_update_candidate(): void {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_candidate_form')) {
            wp_die(__('Security check failed', 'sfs-hr'));
        }

        global $wpdb;
        $id = absint($_POST['candidate_id'] ?? 0);

        $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'gender' => sanitize_text_field($_POST['gender'] ?? ''),
            'date_of_birth' => sanitize_text_field($_POST['date_of_birth'] ?? '') ?: null,
            'nationality' => sanitize_text_field($_POST['nationality'] ?? ''),
            'position_applied' => sanitize_text_field($_POST['position_applied'] ?? ''),
            'dept_id' => absint($_POST['dept_id'] ?? 0) ?: null,
            'application_source' => sanitize_text_field($_POST['application_source'] ?? 'applied'),
            'expected_salary' => floatval($_POST['expected_salary'] ?? 0) ?: null,
            'available_from' => sanitize_text_field($_POST['available_from'] ?? '') ?: null,
            'resume_url' => esc_url_raw($_POST['resume_url'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=candidates&action=view&id=' . $id . '&message=updated'));
        exit;
    }

    public function handle_candidate_action(): void {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_candidate_action')) {
            wp_die(__('Security check failed', 'sfs-hr'));
        }

        global $wpdb;
        $id = absint($_POST['candidate_id'] ?? 0);
        $action = sanitize_text_field($_POST['workflow_action'] ?? '');
        $now = current_time('mysql');

        switch ($action) {
            case 'send_to_dept':
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'dept_pending',
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'dept_approve':
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'dept_approved',
                    'dept_manager_id' => get_current_user_id(),
                    'dept_approved_at' => $now,
                    'dept_notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'send_to_gm':
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'gm_pending',
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'gm_approve':
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'gm_approved',
                    'gm_id' => get_current_user_id(),
                    'gm_approved_at' => $now,
                    'gm_notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'reject':
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'rejected',
                    'rejection_reason' => sanitize_textarea_field($_POST['rejection_reason'] ?? 'Rejected'),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'hire':
                // Update offered position/salary first
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'offered_position' => sanitize_text_field($_POST['offered_position'] ?? ''),
                    'offered_salary' => floatval($_POST['offered_salary'] ?? 0) ?: null,
                    'updated_at' => $now,
                ], ['id' => $id]);

                // Convert to employee
                $employee_id = HiringModule::convert_candidate_to_employee($id, [
                    'hired_date' => sanitize_text_field($_POST['hired_date'] ?? ''),
                    'probation_months' => absint($_POST['probation_months'] ?? 3),
                ]);

                if ($employee_id) {
                    wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=candidates&action=view&id=' . $id . '&message=hired'));
                    exit;
                }
                break;
        }

        wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=candidates&action=view&id=' . $id));
        exit;
    }

    public function handle_add_trainee(): void {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_trainee_form')) {
            wp_die(__('Security check failed', 'sfs-hr'));
        }

        global $wpdb;
        $now = current_time('mysql');
        $trainee_code = HiringModule::generate_trainee_code();

        // Create WordPress user if requested
        $user_id = null;
        if (!empty($_POST['create_user_account'])) {
            $email = sanitize_email($_POST['email'] ?? '');
            $first_name = sanitize_text_field($_POST['first_name'] ?? '');
            $last_name = sanitize_text_field($_POST['last_name'] ?? '');

            $username = sanitize_user(strtolower($first_name . '.' . $last_name));
            $username = substr($username, 0, 50);

            $base_username = $username;
            $counter = 1;
            while (username_exists($username)) {
                $username = $base_username . $counter;
                $counter++;
            }

            $password = wp_generate_password(12, true, true);
            $user_id = wp_create_user($username, $password, $email);

            if (!is_wp_error($user_id)) {
                wp_update_user([
                    'ID' => $user_id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'display_name' => trim($first_name . ' ' . $last_name),
                ]);

                // Mark as trainee
                update_user_meta($user_id, 'sfs_hr_is_trainee', 1);
            } else {
                $user_id = null;
            }
        }

        $wpdb->insert("{$wpdb->prefix}sfs_hr_trainees", [
            'user_id' => $user_id,
            'trainee_code' => $trainee_code,
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'gender' => sanitize_text_field($_POST['gender'] ?? ''),
            'university' => sanitize_text_field($_POST['university'] ?? ''),
            'major' => sanitize_text_field($_POST['major'] ?? ''),
            'gpa' => sanitize_text_field($_POST['gpa'] ?? ''),
            'dept_id' => absint($_POST['dept_id'] ?? 0) ?: null,
            'supervisor_id' => absint($_POST['supervisor_id'] ?? 0) ?: null,
            'position' => sanitize_text_field($_POST['position'] ?? ''),
            'training_start' => sanitize_text_field($_POST['training_start'] ?? ''),
            'training_end' => sanitize_text_field($_POST['training_end'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
            'status' => 'active',
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=trainees&message=added'));
        exit;
    }

    public function handle_update_trainee(): void {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_trainee_form')) {
            wp_die(__('Security check failed', 'sfs-hr'));
        }

        global $wpdb;
        $id = absint($_POST['trainee_id'] ?? 0);

        $wpdb->update("{$wpdb->prefix}sfs_hr_trainees", [
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'gender' => sanitize_text_field($_POST['gender'] ?? ''),
            'university' => sanitize_text_field($_POST['university'] ?? ''),
            'major' => sanitize_text_field($_POST['major'] ?? ''),
            'gpa' => sanitize_text_field($_POST['gpa'] ?? ''),
            'dept_id' => absint($_POST['dept_id'] ?? 0) ?: null,
            'supervisor_id' => absint($_POST['supervisor_id'] ?? 0) ?: null,
            'position' => sanitize_text_field($_POST['position'] ?? ''),
            'training_start' => sanitize_text_field($_POST['training_start'] ?? ''),
            'training_end' => sanitize_text_field($_POST['training_end'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=trainees&action=view&id=' . $id . '&message=updated'));
        exit;
    }

    public function handle_trainee_action(): void {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_trainee_action')) {
            wp_die(__('Security check failed', 'sfs-hr'));
        }

        global $wpdb;
        $id = absint($_POST['trainee_id'] ?? 0);
        $action = sanitize_text_field($_POST['trainee_action'] ?? '');
        $now = current_time('mysql');

        switch ($action) {
            case 'extend':
                $wpdb->update("{$wpdb->prefix}sfs_hr_trainees", [
                    'training_extended_to' => sanitize_text_field($_POST['extend_to'] ?? ''),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'convert':
                // Mark as completed first
                $wpdb->update("{$wpdb->prefix}sfs_hr_trainees", [
                    'status' => 'completed',
                    'updated_at' => $now,
                ], ['id' => $id]);

                // Convert to candidate
                $candidate_id = HiringModule::convert_trainee_to_candidate($id);
                if ($candidate_id) {
                    wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=candidates&action=view&id=' . $candidate_id . '&message=converted'));
                    exit;
                }
                break;

            case 'archive':
                $wpdb->update("{$wpdb->prefix}sfs_hr_trainees", [
                    'status' => 'archived',
                    'archive_reason' => sanitize_textarea_field($_POST['archive_reason'] ?? ''),
                    'archived_at' => $now,
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;
        }

        wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=trainees&action=view&id=' . $id));
        exit;
    }
}
