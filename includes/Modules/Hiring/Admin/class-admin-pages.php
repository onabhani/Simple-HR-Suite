<?php
namespace SFS\HR\Modules\Hiring\Admin;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Hiring\HiringModule;
use SFS\HR\Modules\Hiring\Services\RequisitionService;
use SFS\HR\Modules\Hiring\Services\JobPostingService;
use SFS\HR\Modules\Hiring\Services\InterviewService;
use SFS\HR\Modules\Hiring\Services\OfferService;
use SFS\HR\Modules\Hiring\Services\OnboardingService;

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
        require_once __DIR__ . '/../Services/RequisitionService.php';
        require_once __DIR__ . '/../Services/JobPostingService.php';
        require_once __DIR__ . '/../Services/InterviewService.php';
        require_once __DIR__ . '/../Services/OfferService.php';
        require_once __DIR__ . '/../Services/OnboardingService.php';

        add_action('admin_post_sfs_hr_add_candidate', [$this, 'handle_add_candidate']);
        add_action('admin_post_sfs_hr_update_candidate', [$this, 'handle_update_candidate']);
        add_action('admin_post_sfs_hr_candidate_action', [$this, 'handle_candidate_action']);
        add_action('admin_post_sfs_hr_add_trainee', [$this, 'handle_add_trainee']);
        add_action('admin_post_sfs_hr_update_trainee', [$this, 'handle_update_trainee']);
        add_action('admin_post_sfs_hr_trainee_action', [$this, 'handle_trainee_action']);

        // M3 Hiring feature hooks
        add_action('admin_post_sfs_hr_add_requisition', [$this, 'handle_add_requisition']);
        add_action('admin_post_sfs_hr_update_requisition', [$this, 'handle_update_requisition']);
        add_action('admin_post_sfs_hr_requisition_action', [$this, 'handle_requisition_action']);
        add_action('admin_post_sfs_hr_add_job_posting', [$this, 'handle_add_job_posting']);
        add_action('admin_post_sfs_hr_update_job_posting', [$this, 'handle_update_job_posting']);
        add_action('admin_post_sfs_hr_job_posting_action', [$this, 'handle_job_posting_action']);
        add_action('admin_post_sfs_hr_schedule_interview', [$this, 'handle_schedule_interview']);
        add_action('admin_post_sfs_hr_submit_scorecard', [$this, 'handle_submit_scorecard']);
        add_action('admin_post_sfs_hr_log_communication', [$this, 'handle_log_communication']);
        add_action('admin_post_sfs_hr_add_reference', [$this, 'handle_add_reference']);
        add_action('admin_post_sfs_hr_create_offer', [$this, 'handle_create_offer']);
        add_action('admin_post_sfs_hr_offer_action', [$this, 'handle_offer_action']);
        add_action('admin_post_sfs_hr_save_onboarding_template', [$this, 'handle_save_onboarding_template']);
        add_action('admin_post_sfs_hr_onboarding_task_action', [$this, 'handle_onboarding_task_action']);
        add_action('admin_post_sfs_hr_start_onboarding', [$this, 'handle_start_onboarding']);
    }

    /**
     * Render main page
     */
    public function render_page(): void {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'candidates';
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

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
                <a href="?page=sfs-hr-hiring&tab=requisitions" class="nav-tab <?php echo $tab === 'requisitions' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Requisitions', 'sfs-hr'); ?>
                </a>
                <a href="?page=sfs-hr-hiring&tab=postings" class="nav-tab <?php echo $tab === 'postings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Job Postings', 'sfs-hr'); ?>
                </a>
                <a href="?page=sfs-hr-hiring&tab=interviews" class="nav-tab <?php echo $tab === 'interviews' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Interviews', 'sfs-hr'); ?>
                </a>
                <a href="?page=sfs-hr-hiring&tab=offers" class="nav-tab <?php echo $tab === 'offers' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Offers', 'sfs-hr'); ?>
                </a>
                <a href="?page=sfs-hr-hiring&tab=onboarding" class="nav-tab <?php echo $tab === 'onboarding' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Onboarding', 'sfs-hr'); ?>
                </a>
            </nav>

            <div class="tab-content" style="margin-top:20px;">
                <?php
                switch ($tab) {
                    case 'candidates':
                        $this->render_candidates_tab($action);
                        break;
                    case 'trainees':
                        $this->render_trainees_tab($action);
                        break;
                    case 'requisitions':
                        $this->render_requisitions_tab($action);
                        break;
                    case 'postings':
                        $this->render_postings_tab($action);
                        break;
                    case 'interviews':
                        $this->render_interviews_tab($action);
                        break;
                    case 'offers':
                        $this->render_offers_tab($action);
                        break;
                    case 'onboarding':
                        $this->render_onboarding_tab($action);
                        break;
                    default:
                        $this->render_candidates_tab($action);
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
            .sfs-hr-status-hr_reviewed { background:#e1f5fe; color:#0277bd; }
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
            .sfs-hr-status-draft { background:#f5f5f5; color:#616161; }
            .sfs-hr-status-pending_approval { background:#fff3e0; color:#ef6c00; }
            .sfs-hr-status-open { background:#e8f5e9; color:#2e7d32; }
            .sfs-hr-status-closed { background:#ffebee; color:#c62828; }
            .sfs-hr-status-cancelled { background:#fce4ec; color:#c2185b; }
            .sfs-hr-status-published { background:#e8f5e9; color:#2e7d32; }
            .sfs-hr-status-scheduled { background:#e3f2fd; color:#1565c0; }
            .sfs-hr-status-confirmed { background:#e1f5fe; color:#0277bd; }
            .sfs-hr-status-no_show { background:#ffebee; color:#c62828; }
            .sfs-hr-status-pending { background:#fff3e0; color:#ef6c00; }
            .sfs-hr-status-manager_reviewed { background:#e1f5fe; color:#0277bd; }
            .sfs-hr-status-finance_approved { background:#e0f2f1; color:#00695c; }
            .sfs-hr-status-sent { background:#f3e5f5; color:#7b1fa2; }
            .sfs-hr-status-accepted { background:#c8e6c9; color:#1b5e20; }
            .sfs-hr-status-declined { background:#ffebee; color:#c62828; }
            .sfs-hr-status-in_progress { background:#e3f2fd; color:#1565c0; }
            .sfs-hr-onboarding-progress { background:#e0e0e0; border-radius:4px; height:20px; overflow:hidden; }
            .sfs-hr-onboarding-progress-bar { background:#4caf50; height:100%; transition:width 0.3s; }
        </style>
        <?php
    }

    /**
     * Render candidates tab
     */
    private function render_candidates_tab(string $action): void {
        $this->render_candidates_tab_content($action);
    }

    /**
     * Public method to render candidates tab content (for use by Employee Lifecycle page)
     */
    public function render_candidates_tab_content(string $action): void {
        switch ($action) {
            case 'add':
                $this->render_candidate_form();
                break;
            case 'edit':
                $this->render_candidate_form( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
                break;
            case 'view':
                $this->render_candidate_view( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
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

        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

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
                  ORDER BY c.created_at DESC
                  LIMIT 50";

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

            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Ref #', 'sfs-hr'); ?></th>
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
                        <tr><td colspan="9"><?php esc_html_e('No candidates found.', 'sfs-hr'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($candidates as $c) : ?>
                            <tr>
                                <td>
                                    <code><?php echo esc_html($c->request_number ?: '—'); ?></code>
                                </td>
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
                            <?php Helpers::render_nationality_select( (string) ( $candidate->nationality ?? '' ), 'nationality' ); ?>
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
                        <?php if ($candidate->request_number) : ?>
                            <code style="margin-left:10px; font-size:14px;"><?php echo esc_html($candidate->request_number); ?></code>
                        <?php endif; ?>
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
                        <tr><th><?php esc_html_e('Gender', 'sfs-hr'); ?></th><td><?php echo esc_html(__(ucfirst($candidate->gender ?: '—'), 'sfs-hr')); ?></td></tr>
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
                        // Step 1a: Start screening
                        ?>
                        <p><strong><?php esc_html_e('Step 1: HR Review', 'sfs-hr'); ?></strong></p>
                        <p class="description"><?php esc_html_e('Review the candidate application and begin the screening process.', 'sfs-hr'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:15px;">
                            <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                            <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                            <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                            <input type="hidden" name="workflow_action" value="start_screening" />
                            <button type="submit" class="button button-primary"><?php esc_html_e('Start Screening', 'sfs-hr'); ?></button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                            <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                            <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                            <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                            <input type="hidden" name="workflow_action" value="reject" />
                            <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to reject this candidate?', 'sfs-hr'); ?>');"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>
                        </form>
                        <?php
                        break;

                    case 'screening':
                        // Step 1b: Complete HR review
                        ?>
                        <p><strong><?php esc_html_e('Step 1: Complete HR Review', 'sfs-hr'); ?></strong></p>
                        <p class="description"><?php esc_html_e('Finalize the HR review and add your assessment notes.', 'sfs-hr'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:15px;">
                            <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                            <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                            <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                            <input type="hidden" name="workflow_action" value="hr_review_complete" />
                            <div class="sfs-hr-form-row">
                                <label><?php esc_html_e('HR Review Notes', 'sfs-hr'); ?></label>
                                <textarea name="notes" rows="3" style="width:100%; max-width:500px;"></textarea>
                            </div>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Complete HR Review', 'sfs-hr'); ?></button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                            <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                            <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                            <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                            <input type="hidden" name="workflow_action" value="reject" />
                            <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e('Are you sure you want to reject this candidate?', 'sfs-hr'); ?>');"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>
                        </form>
                        <?php
                        break;

                    case 'hr_reviewed':
                        // Step 2: Send to Dept Manager
                        ?>
                        <p><strong><?php esc_html_e('Step 2: Department Manager Approval', 'sfs-hr'); ?></strong></p>
                        <p class="description"><?php esc_html_e('HR review complete. Send to the department manager for approval.', 'sfs-hr'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:15px;">
                            <?php wp_nonce_field('sfs_hr_candidate_action'); ?>
                            <input type="hidden" name="action" value="sfs_hr_candidate_action" />
                            <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate->id; ?>" />
                            <input type="hidden" name="workflow_action" value="send_to_dept" />
                            <button type="submit" class="button button-primary"><?php esc_html_e('Send to Department Manager', 'sfs-hr'); ?></button>
                        </form>
                        <?php
                        break;

                    case 'dept_pending':
                        if ($can_dept_approve) :
                            ?>
                            <p><strong><?php esc_html_e('Step 2: Department Manager Approval Required', 'sfs-hr'); ?></strong></p>
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
                        <p><strong><?php esc_html_e('Step 3: GM Final Approval', 'sfs-hr'); ?></strong></p>
                        <p class="description"><?php esc_html_e('Department manager has approved. Send to GM for final approval.', 'sfs-hr'); ?></p>
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
                            <p><strong><?php esc_html_e('Step 3: GM Final Approval Required', 'sfs-hr'); ?></strong></p>
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
        <?php
        $has_history = $candidate->hr_reviewed_at || $candidate->dept_approved_at || $candidate->gm_approved_at || $candidate->rejection_reason || $candidate->hired_at;
        $approval_chain = !empty($candidate->approval_chain) ? json_decode($candidate->approval_chain, true) : [];
        ?>
        <?php if ($has_history || !empty($approval_chain)) : ?>
            <div class="sfs-hr-card">
                <h3><?php esc_html_e('Approval History', 'sfs-hr'); ?></h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Step', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('Date', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('By', 'sfs-hr'); ?></th>
                            <th><?php esc_html_e('Notes', 'sfs-hr'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($approval_chain)) : ?>
                            <?php foreach ($approval_chain as $step) : ?>
                                <tr<?php echo ($step['action'] ?? '') === 'reject' ? ' style="background:#ffebee;"' : ''; ?>>
                                    <td><strong><?php echo esc_html($step['label'] ?? $step['role'] ?? '—'); ?></strong></td>
                                    <td><?php echo isset($step['at']) ? esc_html(wp_date('M j, Y H:i', strtotime($step['at']))) : '—'; ?></td>
                                    <td><?php
                                        if (!empty($step['by'])) {
                                            $u = get_userdata((int)$step['by']);
                                            echo $u ? esc_html($u->display_name) : esc_html('#' . $step['by']);
                                        } else {
                                            echo '—';
                                        }
                                    ?></td>
                                    <td><?php echo !empty($step['note']) ? esc_html($step['note']) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <?php // Fallback: show from individual columns for older records ?>
                            <?php if ($candidate->hr_reviewed_at) : ?>
                                <tr>
                                    <td><strong><?php esc_html_e('HR Reviewed', 'sfs-hr'); ?></strong></td>
                                    <td><?php echo esc_html(wp_date('M j, Y H:i', strtotime($candidate->hr_reviewed_at))); ?></td>
                                    <td><?php
                                        if ($candidate->hr_reviewer_id) {
                                            $u = get_userdata((int)$candidate->hr_reviewer_id);
                                            echo $u ? esc_html($u->display_name) : '—';
                                        } else { echo '—'; }
                                    ?></td>
                                    <td><?php echo $candidate->hr_notes ? esc_html($candidate->hr_notes) : '—'; ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($candidate->dept_approved_at) : ?>
                                <tr>
                                    <td><strong><?php esc_html_e('Dept. Manager Approved', 'sfs-hr'); ?></strong></td>
                                    <td><?php echo esc_html(wp_date('M j, Y H:i', strtotime($candidate->dept_approved_at))); ?></td>
                                    <td><?php
                                        if ($candidate->dept_manager_id) {
                                            $u = get_userdata((int)$candidate->dept_manager_id);
                                            echo $u ? esc_html($u->display_name) : '—';
                                        } else { echo '—'; }
                                    ?></td>
                                    <td><?php echo $candidate->dept_notes ? esc_html($candidate->dept_notes) : '—'; ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($candidate->gm_approved_at) : ?>
                                <tr>
                                    <td><strong><?php esc_html_e('GM Approved', 'sfs-hr'); ?></strong></td>
                                    <td><?php echo esc_html(wp_date('M j, Y H:i', strtotime($candidate->gm_approved_at))); ?></td>
                                    <td><?php
                                        if ($candidate->gm_id) {
                                            $u = get_userdata((int)$candidate->gm_id);
                                            echo $u ? esc_html($u->display_name) : '—';
                                        } else { echo '—'; }
                                    ?></td>
                                    <td><?php echo $candidate->gm_notes ? esc_html($candidate->gm_notes) : '—'; ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($candidate->rejection_reason) : ?>
                            <tr style="background:#ffebee;">
                                <td><strong><?php esc_html_e('Rejected', 'sfs-hr'); ?></strong></td>
                                <td colspan="3"><?php echo esc_html($candidate->rejection_reason); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($candidate->hired_at) : ?>
                            <tr style="background:#e8f5e9;">
                                <td><strong><?php esc_html_e('Hired', 'sfs-hr'); ?></strong></td>
                                <td><?php echo esc_html(wp_date('M j, Y', strtotime($candidate->hired_at))); ?></td>
                                <td colspan="2">
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
        $this->render_trainees_tab_content($action);
    }

    /**
     * Public method to render trainees tab content (for use by Employee Lifecycle page)
     */
    public function render_trainees_tab_content(string $action): void {
        switch ($action) {
            case 'add':
                $this->render_trainee_form();
                break;
            case 'edit':
                $this->render_trainee_form( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
                break;
            case 'view':
                $this->render_trainee_view( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
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

        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

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
                  ORDER BY t.created_at DESC
                  LIMIT 50";

        $trainees = $params ? $wpdb->get_results($wpdb->prepare($query, ...$params)) : $wpdb->get_results($query);
        $statuses = HiringModule::get_trainee_statuses();

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <div>
                    <h3 style="margin:0; border:none; padding:0;"><?php esc_html_e('Trainees', 'sfs-hr'); ?></h3>
                    <p style="margin:5px 0 0 0; color:#666; font-size:13px;"><?php esc_html_e('Student Internship Program', 'sfs-hr'); ?></p>
                </div>
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

            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
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
                            <br><small><?php esc_html_e('Expected end date of the internship program', 'sfs-hr'); ?></small>
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
                        <tr><th><?php esc_html_e('Gender', 'sfs-hr'); ?></th><td><?php echo esc_html(__(ucfirst($trainee->gender ?: '—'), 'sfs-hr')); ?></td></tr>
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

        <!-- Create Account for existing trainee -->
        <?php if (!$trainee->user_id && in_array($trainee->status, ['active', 'completed'])) : ?>
            <div class="sfs-hr-card" style="background:#fff3cd; border-color:#ffc107;">
                <h3><?php esc_html_e('WordPress Account', 'sfs-hr'); ?></h3>
                <p><?php esc_html_e('This trainee does not have a WordPress account. Create one to allow access to My HR Profile.', 'sfs-hr'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_trainee_action'); ?>
                    <input type="hidden" name="action" value="sfs_hr_trainee_action" />
                    <input type="hidden" name="trainee_id" value="<?php echo (int) $trainee->id; ?>" />
                    <input type="hidden" name="trainee_action" value="create_account" />
                    <button type="submit" class="button button-primary"><?php esc_html_e('Create WordPress Account', 'sfs-hr'); ?></button>
                </form>
            </div>
        <?php endif; ?>

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

            <!-- Hire Directly (skip candidate workflow) -->
            <div class="sfs-hr-card" style="background:#e8f5e9; border-color:#4caf50;">
                <h3><?php esc_html_e('Hire Directly', 'sfs-hr'); ?></h3>
                <p><?php esc_html_e('Convert this trainee directly to an employee, bypassing the candidate approval workflow.', 'sfs-hr'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_trainee_action'); ?>
                    <input type="hidden" name="action" value="sfs_hr_trainee_action" />
                    <input type="hidden" name="trainee_id" value="<?php echo (int) $trainee->id; ?>" />
                    <input type="hidden" name="trainee_action" value="hire_direct" />

                    <div class="sfs-hr-form-grid">
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Hire Date', 'sfs-hr'); ?> *</label>
                            <input type="date" name="hired_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" required />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Position', 'sfs-hr'); ?></label>
                            <input type="text" name="offered_position" value="<?php echo esc_attr($trainee->position); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Salary', 'sfs-hr'); ?></label>
                            <input type="number" name="offered_salary" step="0.01" placeholder="<?php esc_attr_e('Enter salary', 'sfs-hr'); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Probation Period', 'sfs-hr'); ?></label>
                            <select name="probation_months">
                                <option value="3"><?php esc_html_e('3 months', 'sfs-hr'); ?></option>
                                <option value="6"><?php esc_html_e('6 months', 'sfs-hr'); ?></option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="button button-primary" style="margin-top:15px;" onclick="return confirm('<?php esc_attr_e('Are you sure you want to hire this trainee directly? This will create an employee record and WordPress account.', 'sfs-hr'); ?>');">
                        <?php esc_html_e('Hire as Employee', 'sfs-hr'); ?>
                    </button>
                </form>
            </div>
        <?php elseif ($trainee->status === 'completed') : ?>
            <div class="sfs-hr-card">
                <h3><?php esc_html_e('Actions', 'sfs-hr'); ?></h3>
                <div style="display:flex; gap:20px; flex-wrap:wrap;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sfs_hr_trainee_action'); ?>
                        <input type="hidden" name="action" value="sfs_hr_trainee_action" />
                        <input type="hidden" name="trainee_id" value="<?php echo (int) $trainee->id; ?>" />
                        <input type="hidden" name="trainee_action" value="convert" />
                        <button type="submit" class="button button-primary"><?php esc_html_e('Convert to Candidate', 'sfs-hr'); ?></button>
                    </form>
                </div>
            </div>

            <!-- Hire Directly for completed trainees too -->
            <div class="sfs-hr-card" style="background:#e8f5e9; border-color:#4caf50;">
                <h3><?php esc_html_e('Hire Directly', 'sfs-hr'); ?></h3>
                <p><?php esc_html_e('Convert this trainee directly to an employee, bypassing the candidate approval workflow.', 'sfs-hr'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('sfs_hr_trainee_action'); ?>
                    <input type="hidden" name="action" value="sfs_hr_trainee_action" />
                    <input type="hidden" name="trainee_id" value="<?php echo (int) $trainee->id; ?>" />
                    <input type="hidden" name="trainee_action" value="hire_direct" />

                    <div class="sfs-hr-form-grid">
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Hire Date', 'sfs-hr'); ?> *</label>
                            <input type="date" name="hired_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" required />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Position', 'sfs-hr'); ?></label>
                            <input type="text" name="offered_position" value="<?php echo esc_attr($trainee->position); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Salary', 'sfs-hr'); ?></label>
                            <input type="number" name="offered_salary" step="0.01" placeholder="<?php esc_attr_e('Enter salary', 'sfs-hr'); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Probation Period', 'sfs-hr'); ?></label>
                            <select name="probation_months">
                                <option value="3"><?php esc_html_e('3 months', 'sfs-hr'); ?></option>
                                <option value="6"><?php esc_html_e('6 months', 'sfs-hr'); ?></option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="button button-primary" style="margin-top:15px;" onclick="return confirm('<?php esc_attr_e('Are you sure you want to hire this trainee directly? This will create an employee record and WordPress account.', 'sfs-hr'); ?>');">
                        <?php esc_html_e('Hire as Employee', 'sfs-hr'); ?>
                    </button>
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
        <?php elseif ($trainee->status === 'converted' && !$trainee->candidate_id) :
            // Check if directly hired by parsing notes for employee_id
            $direct_hire_employee_id = null;
            if (!empty($trainee->notes) && preg_match('/\[Direct Hire Info: ({.*?})\]/', $trainee->notes, $matches)) {
                $hire_info = json_decode($matches[1], true);
                if ($hire_info && isset($hire_info['direct_hire_employee_id'])) {
                    $direct_hire_employee_id = (int) $hire_info['direct_hire_employee_id'];
                }
            }
        ?>
            <div class="sfs-hr-card" style="background:#c8e6c9;">
                <p>
                    <strong><?php esc_html_e('This trainee was hired directly as an employee.', 'sfs-hr'); ?></strong>
                    <?php if ($direct_hire_employee_id) : ?>
                        <a href="?page=sfs-hr-employees&action=view&id=<?php echo (int) $direct_hire_employee_id; ?>"><?php esc_html_e('View Employee Record', 'sfs-hr'); ?></a>
                    <?php endif; ?>
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
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'sfs-hr' ), 403 );
        }
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_candidate_form')) {
            wp_die(__('Security check failed', 'sfs-hr'));
        }

        global $wpdb;
        $now = current_time('mysql');
        $request_number = HiringModule::generate_candidate_reference();

        $wpdb->insert("{$wpdb->prefix}sfs_hr_candidates", [
            'request_number' => $request_number,
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
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'sfs-hr' ), 403 );
        }
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
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'sfs-hr' ), 403 );
        }
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_candidate_action')) {
            wp_die(__('Security check failed', 'sfs-hr'));
        }

        global $wpdb;
        $id = absint($_POST['candidate_id'] ?? 0);
        $action = sanitize_text_field($_POST['workflow_action'] ?? '');
        $now = current_time('mysql');
        $current_user_id = get_current_user_id();

        // Load existing approval chain
        $candidate = $wpdb->get_row($wpdb->prepare(
            "SELECT approval_chain FROM {$wpdb->prefix}sfs_hr_candidates WHERE id = %d",
            $id
        ));
        $chain = !empty($candidate->approval_chain) ? json_decode($candidate->approval_chain, true) : [];

        switch ($action) {
            case 'start_screening':
                $chain[] = [
                    'role' => 'hr',
                    'label' => __('Screening Started', 'sfs-hr'),
                    'action' => 'start_screening',
                    'by' => $current_user_id,
                    'at' => $now,
                    'note' => '',
                ];
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'screening',
                    'approval_chain' => wp_json_encode($chain),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'hr_review_complete':
                $notes = sanitize_textarea_field($_POST['notes'] ?? '');
                $chain[] = [
                    'role' => 'hr',
                    'label' => __('HR Review Completed', 'sfs-hr'),
                    'action' => 'hr_review',
                    'by' => $current_user_id,
                    'at' => $now,
                    'note' => $notes,
                ];
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'hr_reviewed',
                    'hr_reviewer_id' => $current_user_id,
                    'hr_reviewed_at' => $now,
                    'hr_notes' => $notes,
                    'approval_chain' => wp_json_encode($chain),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'send_to_dept':
                $chain[] = [
                    'role' => 'hr',
                    'label' => __('Sent to Dept. Manager', 'sfs-hr'),
                    'action' => 'escalate',
                    'by' => $current_user_id,
                    'at' => $now,
                    'note' => '',
                ];
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'dept_pending',
                    'approval_chain' => wp_json_encode($chain),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'dept_approve':
                $notes = sanitize_textarea_field($_POST['notes'] ?? '');
                $chain[] = [
                    'role' => 'manager',
                    'label' => __('Dept. Manager Approved', 'sfs-hr'),
                    'action' => 'approve',
                    'by' => $current_user_id,
                    'at' => $now,
                    'note' => $notes,
                ];
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'dept_approved',
                    'dept_manager_id' => $current_user_id,
                    'dept_approved_at' => $now,
                    'dept_notes' => $notes,
                    'approval_chain' => wp_json_encode($chain),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'send_to_gm':
                $chain[] = [
                    'role' => 'hr',
                    'label' => __('Sent to GM', 'sfs-hr'),
                    'action' => 'escalate',
                    'by' => $current_user_id,
                    'at' => $now,
                    'note' => '',
                ];
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'gm_pending',
                    'approval_chain' => wp_json_encode($chain),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'gm_approve':
                $notes = sanitize_textarea_field($_POST['notes'] ?? '');
                $chain[] = [
                    'role' => 'gm',
                    'label' => __('GM Approved', 'sfs-hr'),
                    'action' => 'approve',
                    'by' => $current_user_id,
                    'at' => $now,
                    'note' => $notes,
                ];
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'gm_approved',
                    'gm_id' => $current_user_id,
                    'gm_approved_at' => $now,
                    'gm_notes' => $notes,
                    'approval_chain' => wp_json_encode($chain),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'reject':
                $reason = sanitize_textarea_field($_POST['rejection_reason'] ?? 'Rejected');
                $chain[] = [
                    'role' => 'reviewer',
                    'label' => __('Rejected', 'sfs-hr'),
                    'action' => 'reject',
                    'by' => $current_user_id,
                    'at' => $now,
                    'note' => $reason,
                ];
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                    'approval_chain' => wp_json_encode($chain),
                    'updated_at' => $now,
                ], ['id' => $id]);
                break;

            case 'hire':
                // Track hire in approval chain
                $chain[] = [
                    'role' => 'hr',
                    'label' => __('Hired', 'sfs-hr'),
                    'action' => 'hire',
                    'by' => $current_user_id,
                    'at' => $now,
                    'note' => '',
                ];

                // Update offered position/salary first
                $wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [
                    'offered_position' => sanitize_text_field($_POST['offered_position'] ?? ''),
                    'offered_salary' => floatval($_POST['offered_salary'] ?? 0) ?: null,
                    'approval_chain' => wp_json_encode($chain),
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
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'sfs-hr' ), 403 );
        }
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

            // Username format: firstname.(first letter of lastname)
            $last_initial = $last_name ? strtolower(substr($last_name, 0, 1)) : '';
            $username = sanitize_user(strtolower($first_name) . ($last_initial ? '.' . $last_initial : ''));
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
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'sfs-hr' ), 403 );
        }
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
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( __( 'You do not have permission to perform this action.', 'sfs-hr' ), 403 );
        }
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

            case 'create_account':
                // Create WordPress account for existing trainee
                $trainee = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}sfs_hr_trainees WHERE id = %d",
                    $id
                ));

                if ($trainee && !$trainee->user_id) {
                    $email = $trainee->email;
                    $first_name = $trainee->first_name;
                    $last_name = $trainee->last_name ?? '';

                    // Generate username: firstname.(first letter of lastname)
                    $last_initial = $last_name ? strtolower(substr($last_name, 0, 1)) : '';
                    $username = sanitize_user(strtolower($first_name) . ($last_initial ? '.' . $last_initial : ''));
                    $username = substr($username, 0, 50);

                    $base_username = $username;
                    $counter = 1;
                    while (username_exists($username)) {
                        $username = $base_username . $counter;
                        $counter++;
                    }

                    // Check if email already exists
                    if (email_exists($email)) {
                        wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=trainees&action=view&id=' . $id . '&error=email_exists'));
                        exit;
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

                        // Link to trainee record
                        $wpdb->update("{$wpdb->prefix}sfs_hr_trainees", [
                            'user_id' => $user_id,
                            'updated_at' => $now,
                        ], ['id' => $id]);

                        // Send welcome email with password reset link (no plaintext password)
                        HiringModule::send_welcome_email( $user_id, $username, $password );

                        wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=trainees&action=view&id=' . $id . '&message=account_created'));
                        exit;
                    }
                }
                break;

            case 'hire_direct':
                // Directly convert trainee to employee (bypass candidate workflow)
                $employee_id = HiringModule::convert_trainee_to_employee($id, [
                    'hired_date' => sanitize_text_field($_POST['hired_date'] ?? ''),
                    'offered_position' => sanitize_text_field($_POST['offered_position'] ?? ''),
                    'offered_salary' => floatval($_POST['offered_salary'] ?? 0) ?: null,
                    'probation_months' => absint($_POST['probation_months'] ?? 3),
                ]);

                if ($employee_id) {
                    wp_redirect(admin_url('admin.php?page=sfs-hr-employees&action=view&id=' . $employee_id . '&message=hired'));
                    exit;
                }
                break;
        }

        wp_redirect(admin_url('admin.php?page=sfs-hr-hiring&tab=trainees&action=view&id=' . $id));
        exit;
    }

    // ========== Requisitions Tab ==========

    private function render_requisitions_tab(string $action): void {
        if ($action === 'add') {
            $this->render_requisition_form();
        } elseif ($action === 'edit' && !empty($_GET['id'])) {
            $this->render_requisition_form((int) $_GET['id']);
        } else {
            $this->render_requisitions_list();
        }
    }

    private function render_requisitions_list(): void {
        global $wpdb;

        $status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $dept_filter   = isset($_GET['dept_id']) ? absint($_GET['dept_id']) : 0;

        $where  = "WHERE 1=1";
        $params = [];

        if ($status_filter) {
            $where .= " AND r.status = %s";
            $params[] = $status_filter;
        }
        if ($dept_filter) {
            $where .= " AND r.dept_id = %d";
            $params[] = $dept_filter;
        }

        $query = "SELECT r.*, d.name as dept_name
                  FROM {$wpdb->prefix}sfs_hr_requisitions r
                  LEFT JOIN {$wpdb->prefix}sfs_hr_departments d ON r.dept_id = d.id
                  $where ORDER BY r.created_at DESC LIMIT 50";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($query, ...$params)) : $wpdb->get_results($query);
        $departments = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments WHERE active = 1 ORDER BY name");
        $statuses = RequisitionService::statuses();

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; border:none; padding:0;"><?php esc_html_e('Requisitions', 'sfs-hr'); ?></h3>
                <a href="?page=sfs-hr-hiring&tab=requisitions&action=add" class="button button-primary">
                    <?php esc_html_e('New Requisition', 'sfs-hr'); ?>
                </a>
            </div>

            <form method="get" style="margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap;">
                <input type="hidden" name="page" value="sfs-hr-hiring" />
                <input type="hidden" name="tab" value="requisitions" />
                <select name="status">
                    <option value=""><?php esc_html_e('All Statuses', 'sfs-hr'); ?></option>
                    <?php foreach ($statuses as $k => $v) : ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($status_filter, $k); ?>><?php echo esc_html($v); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="dept_id">
                    <option value=""><?php esc_html_e('All Departments', 'sfs-hr'); ?></option>
                    <?php foreach ($departments as $d) : ?>
                        <option value="<?php echo (int) $d->id; ?>" <?php selected($dept_filter, $d->id); ?>><?php echo esc_html($d->name); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'sfs-hr'); ?></button>
            </form>

            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Ref #', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Title', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Department', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Headcount', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Filled', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No requisitions found.', 'sfs-hr'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $r) : ?>
                            <tr>
                                <td><code><?php echo esc_html($r->request_number ?: '—'); ?></code></td>
                                <td><strong><?php echo esc_html($r->title); ?></strong></td>
                                <td><?php echo esc_html($r->dept_name ?: '—'); ?></td>
                                <td><?php echo (int) $r->headcount; ?></td>
                                <td><?php echo (int) ($r->filled ?? 0); ?></td>
                                <td>
                                    <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($r->status); ?>">
                                        <?php echo esc_html($statuses[$r->status] ?? $r->status); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?page=sfs-hr-hiring&tab=requisitions&action=edit&id=<?php echo (int) $r->id; ?>"><?php esc_html_e('Edit', 'sfs-hr'); ?></a>
                                    <?php $this->render_requisition_actions($r); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    private function render_requisition_actions(object $r): void {
        $actions = [];
        if ($r->status === 'draft') {
            $actions['submit'] = __('Submit for Approval', 'sfs-hr');
        }
        if ($r->status === 'pending_approval' && current_user_can('sfs_hr.manage')) {
            $actions['hr_approve'] = __('HR Approve', 'sfs-hr');
            $actions['reject']     = __('Reject', 'sfs-hr');
        }
        if ($r->status === 'approved' && current_user_can('manage_options')) {
            $actions['gm_approve'] = __('GM Approve', 'sfs-hr');
            $actions['reject']     = __('Reject', 'sfs-hr');
        }
        if (in_array($r->status, ['draft', 'pending_approval', 'approved', 'open'], true)) {
            $actions['cancel'] = __('Cancel', 'sfs-hr');
        }

        foreach ($actions as $wa => $label) {
            ?>
            | <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <?php wp_nonce_field('sfs_hr_requisition_action'); ?>
                <input type="hidden" name="action" value="sfs_hr_requisition_action" />
                <input type="hidden" name="requisition_id" value="<?php echo (int) $r->id; ?>" />
                <input type="hidden" name="workflow_action" value="<?php echo esc_attr($wa); ?>" />
                <button type="submit" class="button-link" <?php if ($wa === 'reject' || $wa === 'cancel') echo 'onclick="return confirm(\'' . esc_attr__('Are you sure?', 'sfs-hr') . '\');"'; ?>>
                    <?php echo esc_html($label); ?>
                </button>
            </form>
            <?php
        }
    }

    private function render_requisition_form(int $id = 0): void {
        global $wpdb;

        $row = null;
        if ($id > 0) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_requisitions WHERE id = %d", $id
            ));
        }

        $departments = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments WHERE active = 1 ORDER BY name");
        $is_edit = $row !== null;
        $statuses = RequisitionService::statuses();

        ?>
        <div class="sfs-hr-card">
            <h3><?php echo $is_edit ? esc_html__('Edit Requisition', 'sfs-hr') : esc_html__('New Requisition', 'sfs-hr'); ?></h3>
            <?php if ($is_edit) : ?>
                <p><?php esc_html_e('Status:', 'sfs-hr'); ?>
                    <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($row->status); ?>">
                        <?php echo esc_html($statuses[$row->status] ?? $row->status); ?>
                    </span>
                </p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field($is_edit ? 'sfs_hr_update_requisition' : 'sfs_hr_add_requisition'); ?>
                <input type="hidden" name="action" value="<?php echo $is_edit ? 'sfs_hr_update_requisition' : 'sfs_hr_add_requisition'; ?>" />
                <?php if ($is_edit) : ?>
                    <input type="hidden" name="requisition_id" value="<?php echo (int) $row->id; ?>" />
                <?php endif; ?>

                <div class="sfs-hr-form-grid">
                    <div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Title', 'sfs-hr'); ?> *</label>
                            <input type="text" name="title" value="<?php echo esc_attr($row->title ?? ''); ?>" required />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Department', 'sfs-hr'); ?> *</label>
                            <select name="dept_id" required>
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($departments as $d) : ?>
                                    <option value="<?php echo (int) $d->id; ?>" <?php selected($row->dept_id ?? '', $d->id); ?>><?php echo esc_html($d->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Grade', 'sfs-hr'); ?></label>
                            <input type="text" name="grade" value="<?php echo esc_attr($row->grade ?? ''); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Headcount', 'sfs-hr'); ?> *</label>
                            <input type="number" name="headcount" min="1" value="<?php echo esc_attr($row->headcount ?? 1); ?>" required />
                        </div>
                    </div>
                    <div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Salary Min', 'sfs-hr'); ?></label>
                            <input type="number" name="salary_min" step="0.01" value="<?php echo esc_attr($row->salary_min ?? ''); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Salary Mid', 'sfs-hr'); ?></label>
                            <input type="number" name="salary_mid" step="0.01" value="<?php echo esc_attr($row->salary_mid ?? ''); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Salary Max', 'sfs-hr'); ?></label>
                            <input type="number" name="salary_max" step="0.01" value="<?php echo esc_attr($row->salary_max ?? ''); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Requirements', 'sfs-hr'); ?></label>
                            <textarea name="requirements" rows="4"><?php echo esc_textarea($row->requirements ?? ''); ?></textarea>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Justification', 'sfs-hr'); ?></label>
                            <textarea name="justification" rows="4"><?php echo esc_textarea($row->justification ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__('Update', 'sfs-hr') : esc_html__('Create Requisition', 'sfs-hr'); ?></button>
                    <a href="?page=sfs-hr-hiring&tab=requisitions" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    // ========== Job Postings Tab ==========

    private function render_postings_tab(string $action): void {
        if ($action === 'add') {
            $this->render_posting_form();
        } elseif ($action === 'edit' && !empty($_GET['id'])) {
            $this->render_posting_form((int) $_GET['id']);
        } else {
            $this->render_postings_list();
        }
    }

    private function render_postings_list(): void {
        global $wpdb;

        $status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $where  = "WHERE 1=1";
        $params = [];

        if ($status_filter) {
            $where .= " AND p.status = %s";
            $params[] = $status_filter;
        }

        $query = "SELECT p.*, d.name as dept_name,
                         (SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_candidates c WHERE c.posting_id = p.id) as app_count
                  FROM {$wpdb->prefix}sfs_hr_job_postings p
                  LEFT JOIN {$wpdb->prefix}sfs_hr_departments d ON p.dept_id = d.id
                  $where ORDER BY p.created_at DESC LIMIT 50";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($query, ...$params)) : $wpdb->get_results($query);
        $statuses = JobPostingService::statuses();

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; border:none; padding:0;"><?php esc_html_e('Job Postings', 'sfs-hr'); ?></h3>
                <a href="?page=sfs-hr-hiring&tab=postings&action=add" class="button button-primary">
                    <?php esc_html_e('New Posting', 'sfs-hr'); ?>
                </a>
            </div>

            <form method="get" style="margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap;">
                <input type="hidden" name="page" value="sfs-hr-hiring" />
                <input type="hidden" name="tab" value="postings" />
                <select name="status">
                    <option value=""><?php esc_html_e('All Statuses', 'sfs-hr'); ?></option>
                    <?php foreach ($statuses as $k => $v) : ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($status_filter, $k); ?>><?php echo esc_html($v); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'sfs-hr'); ?></button>
            </form>

            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Title', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Department', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Channel', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Published', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Closes', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Applications', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="8"><?php esc_html_e('No postings found.', 'sfs-hr'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $p) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($p->title); ?></strong></td>
                                <td><?php echo esc_html($p->dept_name ?: '—'); ?></td>
                                <td><?php echo esc_html($p->channel ?: '—'); ?></td>
                                <td>
                                    <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($p->status); ?>">
                                        <?php echo esc_html($statuses[$p->status] ?? $p->status); ?>
                                    </span>
                                </td>
                                <td><?php echo $p->published_at ? esc_html(wp_date('M j, Y', strtotime($p->published_at))) : '—'; ?></td>
                                <td><?php echo $p->closes_at ? esc_html(wp_date('M j, Y', strtotime($p->closes_at))) : '—'; ?></td>
                                <td><?php echo (int) $p->app_count; ?></td>
                                <td>
                                    <a href="?page=sfs-hr-hiring&tab=postings&action=edit&id=<?php echo (int) $p->id; ?>"><?php esc_html_e('Edit', 'sfs-hr'); ?></a>
                                    <?php $this->render_posting_actions($p); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    private function render_posting_actions(object $p): void {
        $actions = [];
        if ($p->status === 'draft') {
            $actions['publish'] = __('Publish', 'sfs-hr');
        }
        if ($p->status === 'published') {
            $actions['close'] = __('Close', 'sfs-hr');
        }
        if (in_array($p->status, ['closed', 'draft'], true)) {
            $actions['archive'] = __('Archive', 'sfs-hr');
        }

        foreach ($actions as $wa => $label) {
            ?>
            | <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <?php wp_nonce_field('sfs_hr_job_posting_action'); ?>
                <input type="hidden" name="action" value="sfs_hr_job_posting_action" />
                <input type="hidden" name="posting_id" value="<?php echo (int) $p->id; ?>" />
                <input type="hidden" name="workflow_action" value="<?php echo esc_attr($wa); ?>" />
                <button type="submit" class="button-link"><?php echo esc_html($label); ?></button>
            </form>
            <?php
        }
    }

    private function render_posting_form(int $id = 0): void {
        global $wpdb;

        $row = null;
        if ($id > 0) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_job_postings WHERE id = %d", $id
            ));
        }

        $departments  = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments WHERE active = 1 ORDER BY name");
        $requisitions = $wpdb->get_results("SELECT id, request_number, title FROM {$wpdb->prefix}sfs_hr_requisitions WHERE status = 'open' ORDER BY created_at DESC");
        $is_edit = $row !== null;

        ?>
        <div class="sfs-hr-card">
            <h3><?php echo $is_edit ? esc_html__('Edit Job Posting', 'sfs-hr') : esc_html__('New Job Posting', 'sfs-hr'); ?></h3>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field($is_edit ? 'sfs_hr_update_job_posting' : 'sfs_hr_add_job_posting'); ?>
                <input type="hidden" name="action" value="<?php echo $is_edit ? 'sfs_hr_update_job_posting' : 'sfs_hr_add_job_posting'; ?>" />
                <?php if ($is_edit) : ?>
                    <input type="hidden" name="posting_id" value="<?php echo (int) $row->id; ?>" />
                <?php endif; ?>

                <div class="sfs-hr-form-grid">
                    <div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Requisition', 'sfs-hr'); ?></label>
                            <select name="requisition_id">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($requisitions as $rq) : ?>
                                    <option value="<?php echo (int) $rq->id; ?>" <?php selected($row->requisition_id ?? '', $rq->id); ?>>
                                        <?php echo esc_html($rq->request_number . ' — ' . $rq->title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Title (English)', 'sfs-hr'); ?> *</label>
                            <input type="text" name="title" value="<?php echo esc_attr($row->title ?? ''); ?>" required />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Title (Arabic)', 'sfs-hr'); ?></label>
                            <input type="text" name="title_ar" value="<?php echo esc_attr($row->title_ar ?? ''); ?>" dir="rtl" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Department', 'sfs-hr'); ?></label>
                            <select name="dept_id">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($departments as $d) : ?>
                                    <option value="<?php echo (int) $d->id; ?>" <?php selected($row->dept_id ?? '', $d->id); ?>><?php echo esc_html($d->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Location', 'sfs-hr'); ?></label>
                            <input type="text" name="location" value="<?php echo esc_attr($row->location ?? ''); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Employment Type', 'sfs-hr'); ?></label>
                            <select name="employment_type">
                                <?php foreach (['full_time' => 'Full Time', 'part_time' => 'Part Time', 'contract' => 'Contract', 'internship' => 'Internship'] as $k => $v) : ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($row->employment_type ?? '', $k); ?>><?php echo esc_html($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Salary Range', 'sfs-hr'); ?></label>
                            <input type="text" name="salary_range" value="<?php echo esc_attr($row->salary_range ?? ''); ?>" placeholder="<?php esc_attr_e('e.g. 5000-8000 SAR', 'sfs-hr'); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Channel', 'sfs-hr'); ?></label>
                            <select name="channel">
                                <?php foreach (['internal' => 'Internal', 'external' => 'External', 'both' => 'Both'] as $k => $v) : ?>
                                    <option value="<?php echo esc_attr($k); ?>" <?php selected($row->channel ?? '', $k); ?>><?php echo esc_html($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Closing Date', 'sfs-hr'); ?></label>
                            <input type="date" name="closes_at" value="<?php echo esc_attr($row->closes_at ?? ''); ?>" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Description (English)', 'sfs-hr'); ?></label>
                            <?php wp_editor($row->description ?? '', 'posting_description', ['textarea_name' => 'description', 'textarea_rows' => 8, 'media_buttons' => false]); ?>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Description (Arabic)', 'sfs-hr'); ?></label>
                            <?php wp_editor($row->description_ar ?? '', 'posting_description_ar', ['textarea_name' => 'description_ar', 'textarea_rows' => 8, 'media_buttons' => false]); ?>
                        </div>
                    </div>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__('Update', 'sfs-hr') : esc_html__('Create Posting', 'sfs-hr'); ?></button>
                    <a href="?page=sfs-hr-hiring&tab=postings" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    // ========== Interviews Tab ==========

    private function render_interviews_tab(string $action): void {
        if ($action === 'add') {
            $this->render_interview_form();
        } else {
            $this->render_interviews_list();
        }
    }

    private function render_interviews_list(): void {
        global $wpdb;

        $query = "SELECT i.*,
                         CONCAT(c.first_name, ' ', c.last_name) as candidate_name,
                         u.display_name as interviewer_name
                  FROM {$wpdb->prefix}sfs_hr_interviews i
                  LEFT JOIN {$wpdb->prefix}sfs_hr_candidates c ON i.candidate_id = c.id
                  LEFT JOIN {$wpdb->users} u ON i.interviewer_id = u.ID
                  ORDER BY i.scheduled_at DESC LIMIT 50";

        $rows = $wpdb->get_results($query);

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; border:none; padding:0;"><?php esc_html_e('Interviews', 'sfs-hr'); ?></h3>
                <a href="?page=sfs-hr-hiring&tab=interviews&action=add" class="button button-primary">
                    <?php esc_html_e('Schedule Interview', 'sfs-hr'); ?>
                </a>
            </div>

            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Candidate', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Stage', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Scheduled', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Interviewer', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Location / Link', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No interviews scheduled.', 'sfs-hr'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $iv) : ?>
                            <tr>
                                <td><?php echo esc_html($iv->candidate_name ?: '—'); ?></td>
                                <td><?php echo esc_html(ucfirst(str_replace('_', ' ', $iv->stage ?? '—'))); ?></td>
                                <td><?php echo $iv->scheduled_at ? esc_html(wp_date('M j, Y H:i', strtotime($iv->scheduled_at))) : '—'; ?></td>
                                <td><?php echo esc_html($iv->interviewer_name ?: '—'); ?></td>
                                <td>
                                    <?php if (!empty($iv->meeting_link)) : ?>
                                        <a href="<?php echo esc_url($iv->meeting_link); ?>" target="_blank"><?php esc_html_e('Join', 'sfs-hr'); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($iv->location ?: '—'); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($iv->status); ?>">
                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $iv->status))); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($iv->status === 'scheduled') : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                            <?php wp_nonce_field('sfs_hr_schedule_interview'); ?>
                                            <input type="hidden" name="action" value="sfs_hr_schedule_interview" />
                                            <input type="hidden" name="interview_id" value="<?php echo (int) $iv->id; ?>" />
                                            <input type="hidden" name="workflow_action" value="complete" />
                                            <button type="submit" class="button-link"><?php esc_html_e('Complete', 'sfs-hr'); ?></button>
                                        </form>
                                        |
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                            <?php wp_nonce_field('sfs_hr_schedule_interview'); ?>
                                            <input type="hidden" name="action" value="sfs_hr_schedule_interview" />
                                            <input type="hidden" name="interview_id" value="<?php echo (int) $iv->id; ?>" />
                                            <input type="hidden" name="workflow_action" value="cancel" />
                                            <button type="submit" class="button-link"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    private function render_interview_form(): void {
        global $wpdb;

        $candidates = $wpdb->get_results(
            "SELECT id, CONCAT(first_name, ' ', last_name) as name FROM {$wpdb->prefix}sfs_hr_candidates WHERE status NOT IN ('hired','rejected') ORDER BY first_name"
        );
        $postings = $wpdb->get_results(
            "SELECT id, title FROM {$wpdb->prefix}sfs_hr_job_postings WHERE status = 'published' ORDER BY title"
        );
        $interviewers = get_users(['capability' => 'sfs_hr.manage', 'number' => 100]);

        ?>
        <div class="sfs-hr-card">
            <h3><?php esc_html_e('Schedule Interview', 'sfs-hr'); ?></h3>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sfs_hr_schedule_interview'); ?>
                <input type="hidden" name="action" value="sfs_hr_schedule_interview" />
                <input type="hidden" name="workflow_action" value="create" />

                <div class="sfs-hr-form-grid">
                    <div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Candidate', 'sfs-hr'); ?> *</label>
                            <select name="candidate_id" required>
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($candidates as $c) : ?>
                                    <option value="<?php echo (int) $c->id; ?>"><?php echo esc_html($c->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Job Posting', 'sfs-hr'); ?></label>
                            <select name="posting_id">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($postings as $po) : ?>
                                    <option value="<?php echo (int) $po->id; ?>"><?php echo esc_html($po->title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Stage', 'sfs-hr'); ?> *</label>
                            <select name="stage" required>
                                <?php foreach (['phone_screen' => 'Phone Screen', 'technical' => 'Technical', 'behavioral' => 'Behavioral', 'final' => 'Final'] as $k => $v) : ?>
                                    <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Interviewer', 'sfs-hr'); ?></label>
                            <select name="interviewer_id">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($interviewers as $u) : ?>
                                    <option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html($u->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Date & Time', 'sfs-hr'); ?> *</label>
                            <input type="datetime-local" name="scheduled_at" required />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Duration (minutes)', 'sfs-hr'); ?></label>
                            <input type="number" name="duration_minutes" value="60" min="15" step="15" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Location', 'sfs-hr'); ?></label>
                            <input type="text" name="location" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Meeting Link', 'sfs-hr'); ?></label>
                            <input type="url" name="meeting_link" placeholder="https://" />
                        </div>
                    </div>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Schedule', 'sfs-hr'); ?></button>
                    <a href="?page=sfs-hr-hiring&tab=interviews" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    // ========== Offers Tab ==========

    private function render_offers_tab(string $action): void {
        if ($action === 'add') {
            $this->render_offer_form();
        } else {
            $this->render_offers_list();
        }
    }

    private function render_offers_list(): void {
        global $wpdb;

        $status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $where  = "WHERE 1=1";
        $params = [];

        if ($status_filter) {
            $where .= " AND o.status = %s";
            $params[] = $status_filter;
        }

        $query = "SELECT o.*, CONCAT(c.first_name, ' ', c.last_name) as candidate_name
                  FROM {$wpdb->prefix}sfs_hr_offers o
                  LEFT JOIN {$wpdb->prefix}sfs_hr_candidates c ON o.candidate_id = c.id
                  $where ORDER BY o.created_at DESC LIMIT 50";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($query, ...$params)) : $wpdb->get_results($query);
        $statuses = OfferService::statuses();

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; border:none; padding:0;"><?php esc_html_e('Offers', 'sfs-hr'); ?></h3>
                <a href="?page=sfs-hr-hiring&tab=offers&action=add" class="button button-primary">
                    <?php esc_html_e('Create Offer', 'sfs-hr'); ?>
                </a>
            </div>

            <form method="get" style="margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap;">
                <input type="hidden" name="page" value="sfs-hr-hiring" />
                <input type="hidden" name="tab" value="offers" />
                <select name="status">
                    <option value=""><?php esc_html_e('All Statuses', 'sfs-hr'); ?></option>
                    <?php foreach ($statuses as $k => $v) : ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($status_filter, $k); ?>><?php echo esc_html($v); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'sfs-hr'); ?></button>
            </form>

            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Ref #', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Candidate', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Position', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Salary', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Expires', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No offers found.', 'sfs-hr'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $o) : ?>
                            <tr>
                                <td><code><?php echo esc_html($o->request_number ?: '—'); ?></code></td>
                                <td><?php echo esc_html($o->candidate_name ?: '—'); ?></td>
                                <td><?php echo esc_html($o->position ?: '—'); ?></td>
                                <td><?php echo $o->salary ? number_format((float) $o->salary, 2) : '—'; ?></td>
                                <td>
                                    <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($o->status); ?>">
                                        <?php echo esc_html($statuses[$o->status] ?? $o->status); ?>
                                    </span>
                                </td>
                                <td><?php echo $o->expires_at ? esc_html(wp_date('M j, Y', strtotime($o->expires_at))) : '—'; ?></td>
                                <td>
                                    <?php $this->render_offer_actions($o); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    private function render_offer_actions(object $o): void {
        $actions = [];
        if ($o->status === 'draft') {
            $actions['submit'] = __('Submit for Approval', 'sfs-hr');
        }
        if ($o->status === 'pending') {
            $actions['manager_review'] = __('Manager Review', 'sfs-hr');
        }
        if ($o->status === 'manager_reviewed') {
            $actions['finance_approve'] = __('Finance Approve', 'sfs-hr');
        }
        if ($o->status === 'finance_approved') {
            $actions['send'] = __('Send to Candidate', 'sfs-hr');
        }
        if ($o->status === 'sent') {
            $actions['accepted']  = __('Mark Accepted', 'sfs-hr');
            $actions['declined']  = __('Mark Declined', 'sfs-hr');
        }

        $first = true;
        foreach ($actions as $wa => $label) {
            if (!$first) echo ' | ';
            $first = false;
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <?php wp_nonce_field('sfs_hr_offer_action'); ?>
                <input type="hidden" name="action" value="sfs_hr_offer_action" />
                <input type="hidden" name="offer_id" value="<?php echo (int) $o->id; ?>" />
                <input type="hidden" name="workflow_action" value="<?php echo esc_attr($wa); ?>" />
                <button type="submit" class="button-link"><?php echo esc_html($label); ?></button>
            </form>
            <?php
        }
    }

    private function render_offer_form(): void {
        global $wpdb;

        $candidates = $wpdb->get_results(
            "SELECT id, CONCAT(first_name, ' ', last_name) as name FROM {$wpdb->prefix}sfs_hr_candidates WHERE status = 'gm_approved' ORDER BY first_name"
        );
        $postings = $wpdb->get_results(
            "SELECT id, title FROM {$wpdb->prefix}sfs_hr_job_postings WHERE status IN ('published','closed') ORDER BY title"
        );
        $departments = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments WHERE active = 1 ORDER BY name");
        $templates = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}sfs_hr_offer_templates ORDER BY name");

        ?>
        <div class="sfs-hr-card">
            <h3><?php esc_html_e('Create Offer', 'sfs-hr'); ?></h3>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sfs_hr_create_offer'); ?>
                <input type="hidden" name="action" value="sfs_hr_create_offer" />

                <div class="sfs-hr-form-grid">
                    <div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Candidate', 'sfs-hr'); ?> *</label>
                            <select name="candidate_id" required>
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($candidates as $c) : ?>
                                    <option value="<?php echo (int) $c->id; ?>"><?php echo esc_html($c->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Job Posting', 'sfs-hr'); ?></label>
                            <select name="posting_id">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($postings as $po) : ?>
                                    <option value="<?php echo (int) $po->id; ?>"><?php echo esc_html($po->title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Position', 'sfs-hr'); ?> *</label>
                            <input type="text" name="position" required />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Department', 'sfs-hr'); ?></label>
                            <select name="dept_id">
                                <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                                <?php foreach ($departments as $d) : ?>
                                    <option value="<?php echo (int) $d->id; ?>"><?php echo esc_html($d->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Offer Template', 'sfs-hr'); ?></label>
                            <select name="template_id">
                                <option value=""><?php esc_html_e('None', 'sfs-hr'); ?></option>
                                <?php if (!empty($templates)) : foreach ($templates as $tpl) : ?>
                                    <option value="<?php echo (int) $tpl->id; ?>"><?php echo esc_html($tpl->name); ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Salary', 'sfs-hr'); ?> *</label>
                            <input type="number" name="salary" step="0.01" required />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Housing Allowance', 'sfs-hr'); ?></label>
                            <input type="number" name="housing_allowance" step="0.01" value="0" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Transport Allowance', 'sfs-hr'); ?></label>
                            <input type="number" name="transport_allowance" step="0.01" value="0" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Other Allowances', 'sfs-hr'); ?></label>
                            <input type="number" name="other_allowances" step="0.01" value="0" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Start Date', 'sfs-hr'); ?></label>
                            <input type="date" name="start_date" />
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Probation (months)', 'sfs-hr'); ?></label>
                            <select name="probation_months">
                                <option value="3"><?php esc_html_e('3 months', 'sfs-hr'); ?></option>
                                <option value="6"><?php esc_html_e('6 months', 'sfs-hr'); ?></option>
                            </select>
                        </div>
                        <div class="sfs-hr-form-row">
                            <label><?php esc_html_e('Expires At', 'sfs-hr'); ?></label>
                            <input type="date" name="expires_at" />
                        </div>
                    </div>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Create Offer', 'sfs-hr'); ?></button>
                    <a href="?page=sfs-hr-hiring&tab=offers" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    // ========== Onboarding Tab ==========

    private function render_onboarding_tab(string $action): void {
        $sub = isset($_GET['sub']) ? sanitize_key($_GET['sub']) : 'templates';

        echo '<div style="margin-bottom:15px;">';
        echo '<a href="?page=sfs-hr-hiring&tab=onboarding&sub=templates" class="button ' . ($sub === 'templates' ? 'button-primary' : '') . '">' . esc_html__('Templates', 'sfs-hr') . '</a> ';
        echo '<a href="?page=sfs-hr-hiring&tab=onboarding&sub=instances" class="button ' . ($sub === 'instances' ? 'button-primary' : '') . '">' . esc_html__('Active Onboarding', 'sfs-hr') . '</a>';
        echo '</div>';

        if ($sub === 'instances') {
            if ($action === 'add') {
                $this->render_onboarding_start_form();
            } elseif ($action === 'view' && !empty($_GET['id'])) {
                $this->render_onboarding_instance_view((int) $_GET['id']);
            } else {
                $this->render_onboarding_instances_list();
            }
        } else {
            if ($action === 'add' || ($action === 'edit' && !empty($_GET['id']))) {
                $this->render_onboarding_template_form($action === 'edit' ? (int) $_GET['id'] : 0);
            } else {
                $this->render_onboarding_templates_list();
            }
        }
    }

    private function render_onboarding_templates_list(): void {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_onboarding_templates ORDER BY name"
        );

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; border:none; padding:0;"><?php esc_html_e('Onboarding Templates', 'sfs-hr'); ?></h3>
                <a href="?page=sfs-hr-hiring&tab=onboarding&sub=templates&action=add" class="button button-primary">
                    <?php esc_html_e('New Template', 'sfs-hr'); ?>
                </a>
            </div>

            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Items', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="3"><?php esc_html_e('No templates found.', 'sfs-hr'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $tpl) :
                            $items = !empty($tpl->items) ? json_decode($tpl->items, true) : [];
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html($tpl->name); ?></strong></td>
                                <td><?php echo count($items); ?></td>
                                <td>
                                    <a href="?page=sfs-hr-hiring&tab=onboarding&sub=templates&action=edit&id=<?php echo (int) $tpl->id; ?>"><?php esc_html_e('Edit', 'sfs-hr'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    private function render_onboarding_template_form(int $id = 0): void {
        global $wpdb;

        $row = null;
        $items = [];
        if ($id > 0) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_onboarding_templates WHERE id = %d", $id
            ));
            if ($row && !empty($row->items)) {
                $items = json_decode($row->items, true) ?: [];
            }
        }
        $is_edit = $row !== null;

        ?>
        <div class="sfs-hr-card">
            <h3><?php echo $is_edit ? esc_html__('Edit Onboarding Template', 'sfs-hr') : esc_html__('New Onboarding Template', 'sfs-hr'); ?></h3>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sfs_hr_save_onboarding_template'); ?>
                <input type="hidden" name="action" value="sfs_hr_save_onboarding_template" />
                <?php if ($is_edit) : ?>
                    <input type="hidden" name="template_id" value="<?php echo (int) $row->id; ?>" />
                <?php endif; ?>

                <div class="sfs-hr-form-row">
                    <label><?php esc_html_e('Template Name', 'sfs-hr'); ?> *</label>
                    <input type="text" name="name" value="<?php echo esc_attr($row->name ?? ''); ?>" required />
                </div>

                <h4><?php esc_html_e('Checklist Items', 'sfs-hr'); ?></h4>
                <p class="description"><?php esc_html_e('One item per line. Format: Task title', 'sfs-hr'); ?></p>
                <div class="sfs-hr-form-row">
                    <textarea name="items_text" rows="10" style="width:100%; max-width:600px;"><?php
                        foreach ($items as $item) {
                            echo esc_textarea(is_array($item) ? ($item['title'] ?? '') : $item) . "\n";
                        }
                    ?></textarea>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__('Update', 'sfs-hr') : esc_html__('Create Template', 'sfs-hr'); ?></button>
                    <a href="?page=sfs-hr-hiring&tab=onboarding&sub=templates" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_onboarding_instances_list(): void {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ob.*, CONCAT(e.first_name, ' ', e.last_name) as employee_name, t.name as template_name
             FROM {$wpdb->prefix}sfs_hr_onboarding ob
             LEFT JOIN {$wpdb->prefix}sfs_hr_employees e ON ob.employee_id = e.id
             LEFT JOIN {$wpdb->prefix}sfs_hr_onboarding_templates t ON ob.template_id = t.id
             ORDER BY ob.created_at DESC LIMIT 50"
        );

        ?>
        <div class="sfs-hr-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; border:none; padding:0;"><?php esc_html_e('Active Onboarding', 'sfs-hr'); ?></h3>
                <a href="?page=sfs-hr-hiring&tab=onboarding&sub=instances&action=add" class="button button-primary">
                    <?php esc_html_e('Start Onboarding', 'sfs-hr'); ?>
                </a>
            </div>

            <div class="sfs-hr-table-responsive">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Template', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Progress', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Started', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)) : ?>
                        <tr><td colspan="6"><?php esc_html_e('No active onboarding found.', 'sfs-hr'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($rows as $ob) :
                            $tasks = !empty($ob->tasks) ? json_decode($ob->tasks, true) : [];
                            $total = count($tasks);
                            $done  = 0;
                            foreach ($tasks as $tk) { if (!empty($tk['completed'])) $done++; }
                            $pct = $total > 0 ? round(($done / $total) * 100) : 0;
                        ?>
                            <tr>
                                <td><?php echo esc_html($ob->employee_name ?: '—'); ?></td>
                                <td><?php echo esc_html($ob->template_name ?: '—'); ?></td>
                                <td style="min-width:120px;">
                                    <div class="sfs-hr-onboarding-progress">
                                        <div class="sfs-hr-onboarding-progress-bar" style="width:<?php echo (int) $pct; ?>%;"></div>
                                    </div>
                                    <small><?php echo (int) $done; ?>/<?php echo (int) $total; ?> (<?php echo (int) $pct; ?>%)</small>
                                </td>
                                <td>
                                    <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($ob->status); ?>">
                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $ob->status))); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(wp_date('M j, Y', strtotime($ob->created_at))); ?></td>
                                <td>
                                    <a href="?page=sfs-hr-hiring&tab=onboarding&sub=instances&action=view&id=<?php echo (int) $ob->id; ?>"><?php esc_html_e('View', 'sfs-hr'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    private function render_onboarding_start_form(): void {
        global $wpdb;

        $employees = $wpdb->get_results(
            "SELECT id, CONCAT(first_name, ' ', last_name) as name FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active' ORDER BY first_name"
        );
        $templates = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}sfs_hr_onboarding_templates ORDER BY name"
        );

        ?>
        <div class="sfs-hr-card">
            <h3><?php esc_html_e('Start Onboarding', 'sfs-hr'); ?></h3>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sfs_hr_start_onboarding'); ?>
                <input type="hidden" name="action" value="sfs_hr_start_onboarding" />

                <div class="sfs-hr-form-row">
                    <label><?php esc_html_e('Employee', 'sfs-hr'); ?> *</label>
                    <select name="employee_id" required>
                        <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                        <?php foreach ($employees as $e) : ?>
                            <option value="<?php echo (int) $e->id; ?>"><?php echo esc_html($e->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sfs-hr-form-row">
                    <label><?php esc_html_e('Template', 'sfs-hr'); ?> *</label>
                    <select name="template_id" required>
                        <option value=""><?php esc_html_e('Select...', 'sfs-hr'); ?></option>
                        <?php foreach ($templates as $tpl) : ?>
                            <option value="<?php echo (int) $tpl->id; ?>"><?php echo esc_html($tpl->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Start Onboarding', 'sfs-hr'); ?></button>
                    <a href="?page=sfs-hr-hiring&tab=onboarding&sub=instances" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></a>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_onboarding_instance_view(int $id): void {
        global $wpdb;

        $ob = $wpdb->get_row($wpdb->prepare(
            "SELECT ob.*, CONCAT(e.first_name, ' ', e.last_name) as employee_name
             FROM {$wpdb->prefix}sfs_hr_onboarding ob
             LEFT JOIN {$wpdb->prefix}sfs_hr_employees e ON ob.employee_id = e.id
             WHERE ob.id = %d", $id
        ));

        if (!$ob) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Onboarding record not found.', 'sfs-hr') . '</p></div>';
            return;
        }

        $tasks = !empty($ob->tasks) ? json_decode($ob->tasks, true) : [];

        ?>
        <div class="sfs-hr-card">
            <h3><?php echo esc_html(sprintf(__('Onboarding: %s', 'sfs-hr'), $ob->employee_name)); ?></h3>
            <span class="sfs-hr-status-badge sfs-hr-status-<?php echo esc_attr($ob->status); ?>">
                <?php echo esc_html(ucfirst(str_replace('_', ' ', $ob->status))); ?>
            </span>

            <table class="wp-list-table widefat striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th><?php esc_html_e('Task', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Action', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $idx => $task) :
                        $title = is_array($task) ? ($task['title'] ?? '') : $task;
                        $completed = is_array($task) && !empty($task['completed']);
                    ?>
                        <tr>
                            <td><?php echo (int) ($idx + 1); ?></td>
                            <td><?php echo esc_html($title); ?></td>
                            <td>
                                <?php if ($completed) : ?>
                                    <span class="sfs-hr-status-badge sfs-hr-status-completed"><?php esc_html_e('Done', 'sfs-hr'); ?></span>
                                <?php else : ?>
                                    <span class="sfs-hr-status-badge sfs-hr-status-pending"><?php esc_html_e('Pending', 'sfs-hr'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$completed) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <?php wp_nonce_field('sfs_hr_onboarding_task_action'); ?>
                                        <input type="hidden" name="action" value="sfs_hr_onboarding_task_action" />
                                        <input type="hidden" name="onboarding_id" value="<?php echo (int) $ob->id; ?>" />
                                        <input type="hidden" name="task_index" value="<?php echo (int) $idx; ?>" />
                                        <button type="submit" class="button button-small"><?php esc_html_e('Complete', 'sfs-hr'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p>
            <a href="?page=sfs-hr-hiring&tab=onboarding&sub=instances" class="button"><?php esc_html_e('Back to List', 'sfs-hr'); ?></a>
        </p>
        <?php
    }

    // ========== M3 Handlers ==========

    public function handle_add_requisition(): void {
        check_admin_referer('sfs_hr_add_requisition');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $data = [
            'title'         => sanitize_text_field($_POST['title'] ?? ''),
            'dept_id'       => absint($_POST['dept_id'] ?? 0) ?: null,
            'grade'         => sanitize_text_field($_POST['grade'] ?? ''),
            'salary_min'    => floatval($_POST['salary_min'] ?? 0) ?: null,
            'salary_mid'    => floatval($_POST['salary_mid'] ?? 0) ?: null,
            'salary_max'    => floatval($_POST['salary_max'] ?? 0) ?: null,
            'headcount'     => max(1, absint($_POST['headcount'] ?? 1)),
            'requirements'  => sanitize_textarea_field($_POST['requirements'] ?? ''),
            'justification' => sanitize_textarea_field($_POST['justification'] ?? ''),
        ];

        $id = RequisitionService::create($data);

        wp_redirect(add_query_arg([
            'page' => 'sfs-hr-hiring',
            'tab'  => 'requisitions',
            'msg'  => $id ? 'saved' : 'error',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_update_requisition(): void {
        check_admin_referer('sfs_hr_update_requisition');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $id   = absint($_POST['requisition_id'] ?? 0);
        $data = [
            'title'         => sanitize_text_field($_POST['title'] ?? ''),
            'dept_id'       => absint($_POST['dept_id'] ?? 0) ?: null,
            'grade'         => sanitize_text_field($_POST['grade'] ?? ''),
            'salary_min'    => floatval($_POST['salary_min'] ?? 0) ?: null,
            'salary_mid'    => floatval($_POST['salary_mid'] ?? 0) ?: null,
            'salary_max'    => floatval($_POST['salary_max'] ?? 0) ?: null,
            'headcount'     => max(1, absint($_POST['headcount'] ?? 1)),
            'requirements'  => sanitize_textarea_field($_POST['requirements'] ?? ''),
            'justification' => sanitize_textarea_field($_POST['justification'] ?? ''),
        ];

        RequisitionService::update($id, $data);

        wp_redirect(add_query_arg([
            'page'   => 'sfs-hr-hiring',
            'tab'    => 'requisitions',
            'action' => 'edit',
            'id'     => $id,
            'msg'    => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_requisition_action(): void {
        check_admin_referer('sfs_hr_requisition_action');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $id     = absint($_POST['requisition_id'] ?? 0);
        $action = sanitize_key($_POST['workflow_action'] ?? '');

        RequisitionService::transition($id, $action);

        wp_redirect(add_query_arg([
            'page' => 'sfs-hr-hiring',
            'tab'  => 'requisitions',
            'msg'  => 'updated',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_add_job_posting(): void {
        check_admin_referer('sfs_hr_add_job_posting');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $data = [
            'requisition_id'  => absint($_POST['requisition_id'] ?? 0) ?: null,
            'title'           => sanitize_text_field($_POST['title'] ?? ''),
            'title_ar'        => sanitize_text_field($_POST['title_ar'] ?? ''),
            'description'     => wp_kses_post($_POST['description'] ?? ''),
            'description_ar'  => wp_kses_post($_POST['description_ar'] ?? ''),
            'dept_id'         => absint($_POST['dept_id'] ?? 0) ?: null,
            'location'        => sanitize_text_field($_POST['location'] ?? ''),
            'employment_type' => sanitize_key($_POST['employment_type'] ?? 'full_time'),
            'salary_range'    => sanitize_text_field($_POST['salary_range'] ?? ''),
            'channel'         => sanitize_key($_POST['channel'] ?? 'internal'),
            'closes_at'       => sanitize_text_field($_POST['closes_at'] ?? '') ?: null,
        ];

        $id = JobPostingService::create($data);

        wp_redirect(add_query_arg([
            'page' => 'sfs-hr-hiring',
            'tab'  => 'postings',
            'msg'  => $id ? 'saved' : 'error',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_update_job_posting(): void {
        check_admin_referer('sfs_hr_update_job_posting');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $id   = absint($_POST['posting_id'] ?? 0);
        $data = [
            'requisition_id'  => absint($_POST['requisition_id'] ?? 0) ?: null,
            'title'           => sanitize_text_field($_POST['title'] ?? ''),
            'title_ar'        => sanitize_text_field($_POST['title_ar'] ?? ''),
            'description'     => wp_kses_post($_POST['description'] ?? ''),
            'description_ar'  => wp_kses_post($_POST['description_ar'] ?? ''),
            'dept_id'         => absint($_POST['dept_id'] ?? 0) ?: null,
            'location'        => sanitize_text_field($_POST['location'] ?? ''),
            'employment_type' => sanitize_key($_POST['employment_type'] ?? 'full_time'),
            'salary_range'    => sanitize_text_field($_POST['salary_range'] ?? ''),
            'channel'         => sanitize_key($_POST['channel'] ?? 'internal'),
            'closes_at'       => sanitize_text_field($_POST['closes_at'] ?? '') ?: null,
        ];

        JobPostingService::update($id, $data);

        wp_redirect(add_query_arg([
            'page'   => 'sfs-hr-hiring',
            'tab'    => 'postings',
            'action' => 'edit',
            'id'     => $id,
            'msg'    => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_job_posting_action(): void {
        check_admin_referer('sfs_hr_job_posting_action');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $id     = absint($_POST['posting_id'] ?? 0);
        $action = sanitize_key($_POST['workflow_action'] ?? '');

        JobPostingService::transition($id, $action);

        wp_redirect(add_query_arg([
            'page' => 'sfs-hr-hiring',
            'tab'  => 'postings',
            'msg'  => 'updated',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_schedule_interview(): void {
        check_admin_referer('sfs_hr_schedule_interview');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $wa = sanitize_key($_POST['workflow_action'] ?? 'create');

        if ($wa === 'create') {
            $data = [
                'candidate_id'    => absint($_POST['candidate_id'] ?? 0),
                'posting_id'      => absint($_POST['posting_id'] ?? 0) ?: null,
                'stage'           => sanitize_key($_POST['stage'] ?? 'phone_screen'),
                'scheduled_at'    => sanitize_text_field($_POST['scheduled_at'] ?? ''),
                'duration_minutes'=> absint($_POST['duration_minutes'] ?? 60),
                'location'        => sanitize_text_field($_POST['location'] ?? ''),
                'meeting_link'    => esc_url_raw($_POST['meeting_link'] ?? ''),
                'interviewer_id'  => absint($_POST['interviewer_id'] ?? 0) ?: null,
            ];
            InterviewService::create($data);
        } else {
            $interview_id = absint($_POST['interview_id'] ?? 0);
            InterviewService::transition($interview_id, $wa);
        }

        wp_redirect(add_query_arg([
            'page' => 'sfs-hr-hiring',
            'tab'  => 'interviews',
            'msg'  => 'updated',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_submit_scorecard(): void {
        check_admin_referer('sfs_hr_submit_scorecard');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $interview_id = absint($_POST['interview_id'] ?? 0);
        $scores       = array_map('absint', (array) ($_POST['scores'] ?? []));
        $notes        = sanitize_textarea_field($_POST['notes'] ?? '');

        InterviewService::save_scorecard($interview_id, $scores, $notes);

        wp_redirect(add_query_arg([
            'page' => 'sfs-hr-hiring',
            'tab'  => 'interviews',
            'msg'  => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_log_communication(): void {
        check_admin_referer('sfs_hr_log_communication');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $data = [
            'candidate_id' => absint($_POST['candidate_id'] ?? 0),
            'type'         => sanitize_key($_POST['type'] ?? 'note'),
            'subject'      => sanitize_text_field($_POST['subject'] ?? ''),
            'body'         => sanitize_textarea_field($_POST['body'] ?? ''),
        ];

        InterviewService::log_communication($data);

        wp_redirect(add_query_arg([
            'page'   => 'sfs-hr-hiring',
            'tab'    => 'candidates',
            'action' => 'view',
            'id'     => $data['candidate_id'],
            'msg'    => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_add_reference(): void {
        check_admin_referer('sfs_hr_add_reference');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $data = [
            'candidate_id'  => absint($_POST['candidate_id'] ?? 0),
            'referee_name'  => sanitize_text_field($_POST['referee_name'] ?? ''),
            'referee_email' => sanitize_email($_POST['referee_email'] ?? ''),
            'referee_phone' => sanitize_text_field($_POST['referee_phone'] ?? ''),
            'relationship'  => sanitize_text_field($_POST['relationship'] ?? ''),
            'notes'         => sanitize_textarea_field($_POST['notes'] ?? ''),
        ];

        InterviewService::add_reference($data);

        wp_redirect(add_query_arg([
            'page'   => 'sfs-hr-hiring',
            'tab'    => 'candidates',
            'action' => 'view',
            'id'     => $data['candidate_id'],
            'msg'    => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_create_offer(): void {
        check_admin_referer('sfs_hr_create_offer');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $data = [
            'candidate_id'       => absint($_POST['candidate_id'] ?? 0),
            'posting_id'         => absint($_POST['posting_id'] ?? 0) ?: null,
            'position'           => sanitize_text_field($_POST['position'] ?? ''),
            'dept_id'            => absint($_POST['dept_id'] ?? 0) ?: null,
            'salary'             => floatval($_POST['salary'] ?? 0),
            'housing_allowance'  => floatval($_POST['housing_allowance'] ?? 0),
            'transport_allowance'=> floatval($_POST['transport_allowance'] ?? 0),
            'other_allowances'   => floatval($_POST['other_allowances'] ?? 0),
            'start_date'         => sanitize_text_field($_POST['start_date'] ?? '') ?: null,
            'probation_months'   => absint($_POST['probation_months'] ?? 3),
            'template_id'        => absint($_POST['template_id'] ?? 0) ?: null,
            'expires_at'         => sanitize_text_field($_POST['expires_at'] ?? '') ?: null,
        ];

        $id = OfferService::create($data);

        wp_redirect(add_query_arg([
            'page' => 'sfs-hr-hiring',
            'tab'  => 'offers',
            'msg'  => $id ? 'saved' : 'error',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_offer_action(): void {
        check_admin_referer('sfs_hr_offer_action');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $id     = absint($_POST['offer_id'] ?? 0);
        $action = sanitize_key($_POST['workflow_action'] ?? '');

        OfferService::transition($id, $action);

        wp_redirect(add_query_arg([
            'page' => 'sfs-hr-hiring',
            'tab'  => 'offers',
            'msg'  => 'updated',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_save_onboarding_template(): void {
        check_admin_referer('sfs_hr_save_onboarding_template');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $template_id = absint($_POST['template_id'] ?? 0);
        $name        = sanitize_text_field($_POST['name'] ?? '');
        $lines       = array_filter(array_map('trim', explode("\n", sanitize_textarea_field($_POST['items_text'] ?? ''))));

        $items = [];
        foreach ($lines as $line) {
            if ($line !== '') {
                $items[] = ['title' => $line, 'completed' => false];
            }
        }

        $data = [
            'name'  => $name,
            'items' => wp_json_encode($items),
        ];

        OnboardingService::save_template($template_id, $data);

        wp_redirect(add_query_arg([
            'page' => 'sfs-hr-hiring',
            'tab'  => 'onboarding',
            'sub'  => 'templates',
            'msg'  => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_onboarding_task_action(): void {
        check_admin_referer('sfs_hr_onboarding_task_action');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $ob_id      = absint($_POST['onboarding_id'] ?? 0);
        $task_index = absint($_POST['task_index'] ?? 0);

        OnboardingService::complete_task($ob_id, $task_index);

        wp_redirect(add_query_arg([
            'page'   => 'sfs-hr-hiring',
            'tab'    => 'onboarding',
            'sub'    => 'instances',
            'action' => 'view',
            'id'     => $ob_id,
            'msg'    => 'updated',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_start_onboarding(): void {
        check_admin_referer('sfs_hr_start_onboarding');
        if (!current_user_can('sfs_hr.manage')) {
            wp_die(__('Unauthorized', 'sfs-hr'));
        }

        $employee_id = absint($_POST['employee_id'] ?? 0);
        $template_id = absint($_POST['template_id'] ?? 0);

        $id = OnboardingService::start($employee_id, $template_id);

        wp_redirect(add_query_arg([
            'page'   => 'sfs-hr-hiring',
            'tab'    => 'onboarding',
            'sub'    => 'instances',
            'action' => 'view',
            'id'     => $id ?: 0,
            'msg'    => $id ? 'started' : 'error',
        ], admin_url('admin.php')));
        exit;
    }
}
