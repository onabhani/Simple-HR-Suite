<?php
namespace SFS\HR\Modules\Surveys;

use SFS\HR\Core\Helpers;
use SFS\HR\Frontend\Tab_Dispatcher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SurveysModule {

    /* ───────────────────────── hooks ───────────────────────── */

    public function hooks(): void {
        add_action( 'admin_menu', [ $this, 'menu' ], 20 );

        // Admin form handlers.
        add_action( 'admin_post_sfs_hr_survey_save',           [ $this, 'handle_survey_save' ] );
        add_action( 'admin_post_sfs_hr_survey_delete',         [ $this, 'handle_survey_delete' ] );
        add_action( 'admin_post_sfs_hr_survey_publish',        [ $this, 'handle_survey_publish' ] );
        add_action( 'admin_post_sfs_hr_survey_close',          [ $this, 'handle_survey_close' ] );
        add_action( 'admin_post_sfs_hr_survey_question_save',  [ $this, 'handle_question_save' ] );
        add_action( 'admin_post_sfs_hr_survey_question_del',   [ $this, 'handle_question_delete' ] );
        add_action( 'admin_post_sfs_hr_survey_submit_response', [ $this, 'handle_submit_response' ] );

        // Register frontend tab.
        Tab_Dispatcher::register( 'surveys', 'SFS\\HR\\Modules\\Surveys\\Frontend\\SurveysTab' );
    }

    /* ───────────────────────── admin menu ──────────────────── */

    public function menu(): void {
        add_submenu_page(
            'sfs-hr-performance',
            __( 'Surveys', 'sfs-hr' ),
            __( 'Surveys', 'sfs-hr' ),
            'sfs_hr.manage',
            'sfs-hr-surveys',
            [ $this, 'render' ]
        );
    }

    /* ───────────────────────── install ─────────────────────── */

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        // Surveys (the form itself).
        dbDelta( "CREATE TABLE {$wpdb->prefix}sfs_hr_surveys (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title         VARCHAR(255)    NOT NULL,
            description   TEXT,
            status        ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
            is_anonymous  TINYINT(1)      NOT NULL DEFAULT 1,
            target_scope  ENUM('all','department','individual') NOT NULL DEFAULT 'all',
            target_ids    TEXT            COMMENT 'JSON array of dept / employee IDs when scope != all',
            created_by    BIGINT UNSIGNED DEFAULT NULL,
            published_at  DATETIME        DEFAULT NULL,
            closed_at     DATETIME        DEFAULT NULL,
            created_at    DATETIME        NOT NULL,
            updated_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_status (status)
        ) $charset;" );

        // Questions within a survey.
        dbDelta( "CREATE TABLE {$wpdb->prefix}sfs_hr_survey_questions (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            survey_id     BIGINT UNSIGNED NOT NULL,
            sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            question_text TEXT            NOT NULL,
            question_type ENUM('rating','text','choice','yes_no') NOT NULL DEFAULT 'rating',
            options_json  TEXT            COMMENT 'JSON array for choice-type options',
            is_required   TINYINT(1)      NOT NULL DEFAULT 1,
            created_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_survey (survey_id)
        ) $charset;" );

        // Responses (one row per employee per survey).
        dbDelta( "CREATE TABLE {$wpdb->prefix}sfs_hr_survey_responses (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            survey_id     BIGINT UNSIGNED NOT NULL,
            employee_id   BIGINT UNSIGNED NOT NULL,
            submitted_at  DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_survey_emp (survey_id, employee_id)
        ) $charset;" );

        // Individual answers (one row per question per response).
        dbDelta( "CREATE TABLE {$wpdb->prefix}sfs_hr_survey_answers (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            response_id   BIGINT UNSIGNED NOT NULL,
            question_id   BIGINT UNSIGNED NOT NULL,
            answer_text   TEXT,
            answer_rating TINYINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_response (response_id),
            KEY idx_question (question_id)
        ) $charset;" );
    }

    /* ───────────────────────── admin render ────────────────── */

    public function render(): void {
        Helpers::require_cap( 'sfs_hr.manage' );

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        switch ( $action ) {
            case 'new':
            case 'edit':
                $this->render_edit_page();
                break;
            case 'questions':
                $this->render_questions_page();
                break;
            case 'results':
                $this->render_results_page();
                break;
            default:
                $this->render_list_page();
        }
    }

    /* ─────────────── List page ─────────────── */

    private function render_list_page(): void {
        global $wpdb;
        $t    = $wpdb->prefix . 'sfs_hr_surveys';
        $tq   = $wpdb->prefix . 'sfs_hr_survey_questions';
        $tr   = $wpdb->prefix . 'sfs_hr_survey_responses';
        $rows = $wpdb->get_results(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM {$tq} WHERE survey_id = s.id) AS question_count,
                    (SELECT COUNT(*) FROM {$tr} WHERE survey_id = s.id) AS response_count
             FROM {$t} s ORDER BY s.id DESC",
            ARRAY_A
        );
        ?>
        <div class="wrap sfs-hr-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Employee Surveys', 'sfs-hr' ); ?></h1>
            <?php Helpers::render_admin_nav(); ?>
            <hr class="wp-header-end" />

            <style>
                .sfs-hr-surveys-page { max-width: 1100px; }
                .sfs-hr-surveys-page .sfs-hr-section-card {
                    margin-top: 16px; background: #fff; border-radius: 8px;
                    border: 1px solid #e2e4e7; box-shadow: 0 1px 3px rgba(0,0,0,.04); overflow: hidden;
                }
                .sfs-hr-surveys-page .sfs-hr-section-header {
                    padding: 16px 20px; border-bottom: 1px solid #f0f0f1; background: #f9fafb;
                    display: flex; justify-content: space-between; align-items: center;
                }
                .sfs-hr-surveys-page .sfs-hr-section-title { margin: 0; font-size: 14px; font-weight: 600; color: #1d2327; }
                .sfs-hr-survey-table { width: 100%; border-collapse: collapse; }
                .sfs-hr-survey-table th {
                    background: #f9fafb; padding: 12px 16px; text-align: start; font-weight: 600;
                    font-size: 12px; color: #50575e; text-transform: uppercase; letter-spacing: .3px;
                    border-bottom: 1px solid #e2e4e7;
                }
                .sfs-hr-survey-table td { padding: 14px 16px; font-size: 13px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
                .sfs-hr-survey-table tbody tr:last-child td { border-bottom: none; }
                .sfs-hr-survey-table tbody tr:hover { background: #f9fafb; }
                .sfs-hr-survey-badge {
                    display: inline-block; padding: 3px 10px; border-radius: 10px;
                    font-size: 11px; font-weight: 500;
                }
                .sfs-hr-survey-badge--draft     { background: #e5e7eb; color: #374151; }
                .sfs-hr-survey-badge--published  { background: #d1fae5; color: #065f46; }
                .sfs-hr-survey-badge--closed     { background: #fee2e2; color: #991b1b; }
                .sfs-hr-survey-actions a,
                .sfs-hr-survey-actions button { margin-right: 6px; font-size: 12px; }
            </style>

            <div class="sfs-hr-surveys-page">
                <div class="sfs-hr-section-card">
                    <div class="sfs-hr-section-header">
                        <h2 class="sfs-hr-section-title"><?php esc_html_e( 'All Surveys', 'sfs-hr' ); ?></h2>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-surveys&action=new' ) ); ?>" class="button button-primary">
                            + <?php esc_html_e( 'New Survey', 'sfs-hr' ); ?>
                        </a>
                    </div>
                    <?php if ( empty( $rows ) ) : ?>
                        <div style="padding:32px;text-align:center;color:#6b7280;">
                            <?php esc_html_e( 'No surveys yet. Create your first survey to gather employee feedback.', 'sfs-hr' ); ?>
                        </div>
                    <?php else : ?>
                        <table class="sfs-hr-survey-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Title', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Questions', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Responses', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Anonymous', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Created', 'sfs-hr' ); ?></th>
                                    <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $rows as $row ) :
                                $id  = (int) $row['id'];
                                $st  = $row['status'];
                            ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $row['title'] ); ?></strong></td>
                                    <td><span class="sfs-hr-survey-badge sfs-hr-survey-badge--<?php echo esc_attr( $st ); ?>"><?php echo esc_html( ucfirst( $st ) ); ?></span></td>
                                    <td><?php echo (int) $row['question_count']; ?></td>
                                    <td><?php echo (int) $row['response_count']; ?></td>
                                    <td><?php echo $row['is_anonymous'] ? esc_html__( 'Yes', 'sfs-hr' ) : esc_html__( 'No', 'sfs-hr' ); ?></td>
                                    <td><?php echo esc_html( date_i18n( 'Y-m-d', strtotime( $row['created_at'] ) ) ); ?></td>
                                    <td class="sfs-hr-survey-actions">
                                        <?php if ( $st === 'draft' ) : ?>
                                            <a href="<?php echo esc_url( admin_url( "admin.php?page=sfs-hr-surveys&action=edit&id={$id}" ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'sfs-hr' ); ?></a>
                                            <a href="<?php echo esc_url( admin_url( "admin.php?page=sfs-hr-surveys&action=questions&id={$id}" ) ); ?>" class="button button-small"><?php esc_html_e( 'Questions', 'sfs-hr' ); ?></a>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                                <?php wp_nonce_field( 'sfs_hr_survey_publish' ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_survey_publish" />
                                                <input type="hidden" name="id" value="<?php echo $id; ?>" />
                                                <button type="submit" class="button button-small button-primary" onclick="return confirm('<?php esc_attr_e( 'Publish this survey? Employees will be able to respond.', 'sfs-hr' ); ?>');"><?php esc_html_e( 'Publish', 'sfs-hr' ); ?></button>
                                            </form>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                                <?php wp_nonce_field( 'sfs_hr_survey_delete' ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_survey_delete" />
                                                <input type="hidden" name="id" value="<?php echo $id; ?>" />
                                                <button type="submit" class="button button-small" style="color:#b91c1c;" onclick="return confirm('<?php esc_attr_e( 'Delete this survey?', 'sfs-hr' ); ?>');"><?php esc_html_e( 'Delete', 'sfs-hr' ); ?></button>
                                            </form>
                                        <?php elseif ( $st === 'published' ) : ?>
                                            <a href="<?php echo esc_url( admin_url( "admin.php?page=sfs-hr-surveys&action=results&id={$id}" ) ); ?>" class="button button-small"><?php esc_html_e( 'Results', 'sfs-hr' ); ?></a>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                                <?php wp_nonce_field( 'sfs_hr_survey_close' ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_survey_close" />
                                                <input type="hidden" name="id" value="<?php echo $id; ?>" />
                                                <button type="submit" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Close this survey? No more responses will be accepted.', 'sfs-hr' ); ?>');"><?php esc_html_e( 'Close', 'sfs-hr' ); ?></button>
                                            </form>
                                        <?php else : ?>
                                            <a href="<?php echo esc_url( admin_url( "admin.php?page=sfs-hr-surveys&action=results&id={$id}" ) ); ?>" class="button button-small"><?php esc_html_e( 'Results', 'sfs-hr' ); ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* ─────────────── Edit page (new / edit) ─────────────── */

    private function render_edit_page(): void {
        global $wpdb;
        $id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        $row  = null;
        $is_edit = false;

        if ( $id > 0 ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d", $id
            ), ARRAY_A );
            if ( ! $row || $row['status'] !== 'draft' ) {
                Helpers::redirect_with_notice( admin_url( 'admin.php?page=sfs-hr-surveys' ), 'error', __( 'Survey not found or not editable.', 'sfs-hr' ) );
            }
            $is_edit = true;
        }

        $departments = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments ORDER BY name ASC", ARRAY_A
        );

        $nonce = wp_create_nonce( 'sfs_hr_survey_save' );
        ?>
        <div class="wrap sfs-hr-wrap">
            <h1 class="wp-heading-inline"><?php echo $is_edit ? esc_html__( 'Edit Survey', 'sfs-hr' ) : esc_html__( 'New Survey', 'sfs-hr' ); ?></h1>
            <?php Helpers::render_admin_nav(); ?>
            <hr class="wp-header-end" />

            <style>
                .sfs-hr-survey-form { max-width: 700px; margin-top: 16px; }
                .sfs-hr-survey-form .sfs-field { margin-bottom: 16px; }
                .sfs-hr-survey-form label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; color: #1d2327; }
                .sfs-hr-survey-form input[type="text"],
                .sfs-hr-survey-form textarea,
                .sfs-hr-survey-form select {
                    width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;
                }
                .sfs-hr-survey-form textarea { min-height: 80px; resize: vertical; }
                .sfs-hr-survey-form .sfs-field-hint { font-size: 12px; color: #6b7280; margin-top: 2px; }
            </style>

            <div class="sfs-hr-survey-form">
                <div class="sfs-hr-section-card" style="background:#fff;border:1px solid #e2e4e7;border-radius:8px;padding:24px;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'sfs_hr_survey_save' ); ?>
                        <input type="hidden" name="action" value="sfs_hr_survey_save" />
                        <input type="hidden" name="id" value="<?php echo $id; ?>" />

                        <div class="sfs-field">
                            <label for="title"><?php esc_html_e( 'Survey Title', 'sfs-hr' ); ?></label>
                            <input type="text" name="title" id="title" required
                                   value="<?php echo esc_attr( $row['title'] ?? '' ); ?>"
                                   placeholder="<?php esc_attr_e( 'e.g. Employee Satisfaction Q1 2026', 'sfs-hr' ); ?>" />
                        </div>

                        <div class="sfs-field">
                            <label for="description"><?php esc_html_e( 'Description', 'sfs-hr' ); ?></label>
                            <textarea name="description" id="description" placeholder="<?php esc_attr_e( 'Brief description of the survey purpose...', 'sfs-hr' ); ?>"><?php echo esc_textarea( $row['description'] ?? '' ); ?></textarea>
                        </div>

                        <div class="sfs-field">
                            <label>
                                <input type="checkbox" name="is_anonymous" value="1" <?php checked( $row['is_anonymous'] ?? 1 ); ?> />
                                <?php esc_html_e( 'Anonymous responses', 'sfs-hr' ); ?>
                            </label>
                            <div class="sfs-field-hint"><?php esc_html_e( 'When enabled, individual responses are not linked to employee names in results.', 'sfs-hr' ); ?></div>
                        </div>

                        <div class="sfs-field">
                            <label for="target_scope"><?php esc_html_e( 'Target Audience', 'sfs-hr' ); ?></label>
                            <select name="target_scope" id="target_scope" onchange="document.getElementById('target-ids-row').style.display = this.value === 'all' ? 'none' : 'block';">
                                <option value="all" <?php selected( $row['target_scope'] ?? 'all', 'all' ); ?>><?php esc_html_e( 'All Employees', 'sfs-hr' ); ?></option>
                                <option value="department" <?php selected( $row['target_scope'] ?? '', 'department' ); ?>><?php esc_html_e( 'Specific Departments', 'sfs-hr' ); ?></option>
                            </select>
                        </div>

                        <div class="sfs-field" id="target-ids-row" style="<?php echo ( $row['target_scope'] ?? 'all' ) === 'all' ? 'display:none;' : ''; ?>">
                            <label><?php esc_html_e( 'Select Departments', 'sfs-hr' ); ?></label>
                            <?php
                            $selected_ids = [];
                            if ( ! empty( $row['target_ids'] ) ) {
                                $selected_ids = json_decode( $row['target_ids'], true ) ?: [];
                            }
                            foreach ( $departments as $dept ) :
                            ?>
                                <label style="display:block;margin:4px 0;font-weight:normal;">
                                    <input type="checkbox" name="target_ids[]" value="<?php echo (int) $dept['id']; ?>"
                                        <?php checked( in_array( (int) $dept['id'], $selected_ids, true ) ); ?> />
                                    <?php echo esc_html( $dept['name'] ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top:20px;">
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Survey', 'sfs-hr' ); ?></button>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-surveys' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'sfs-hr' ); ?></a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /* ─────────────── Questions page ─────────────── */

    private function render_questions_page(): void {
        global $wpdb;
        $survey_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        $survey    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d", $survey_id
        ), ARRAY_A );

        if ( ! $survey || $survey['status'] !== 'draft' ) {
            Helpers::redirect_with_notice( admin_url( 'admin.php?page=sfs-hr-surveys' ), 'error', __( 'Survey not found or not editable.', 'sfs-hr' ) );
        }

        $questions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_survey_questions WHERE survey_id = %d ORDER BY sort_order ASC, id ASC",
            $survey_id
        ), ARRAY_A );

        $nonce_save = wp_create_nonce( 'sfs_hr_survey_question_save' );
        $nonce_del  = wp_create_nonce( 'sfs_hr_survey_question_del' );
        ?>
        <div class="wrap sfs-hr-wrap">
            <h1 class="wp-heading-inline">
                <?php printf( esc_html__( 'Questions — %s', 'sfs-hr' ), esc_html( $survey['title'] ) ); ?>
            </h1>
            <?php Helpers::render_admin_nav(); ?>
            <hr class="wp-header-end" />

            <style>
                .sfs-hr-questions-page { max-width: 800px; margin-top: 16px; }
                .sfs-hr-q-card {
                    background: #fff; border: 1px solid #e2e4e7; border-radius: 8px;
                    padding: 16px 20px; margin-bottom: 12px;
                    display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;
                }
                .sfs-hr-q-card .q-num { font-weight: 700; color: #6b7280; min-width: 24px; }
                .sfs-hr-q-card .q-body { flex: 1; }
                .sfs-hr-q-card .q-text { font-weight: 600; color: #1d2327; margin-bottom: 4px; }
                .sfs-hr-q-card .q-meta { font-size: 12px; color: #6b7280; }
                .sfs-hr-q-type-badge {
                    display: inline-block; padding: 2px 8px; border-radius: 8px;
                    font-size: 11px; font-weight: 500; background: #dbeafe; color: #1e40af;
                }
                .sfs-hr-q-add-form { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 20px; margin-top: 16px; }
                .sfs-hr-q-add-form .sfs-field { margin-bottom: 12px; }
                .sfs-hr-q-add-form label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; }
                .sfs-hr-q-add-form input[type="text"],
                .sfs-hr-q-add-form textarea,
                .sfs-hr-q-add-form select {
                    width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;
                }
                .sfs-hr-q-add-form textarea { min-height: 60px; resize: vertical; }
            </style>

            <div class="sfs-hr-questions-page">
                <?php if ( empty( $questions ) ) : ?>
                    <div style="padding:20px;text-align:center;color:#6b7280;background:#fff;border:1px solid #e2e4e7;border-radius:8px;">
                        <?php esc_html_e( 'No questions yet. Add your first question below.', 'sfs-hr' ); ?>
                    </div>
                <?php else : ?>
                    <?php foreach ( $questions as $i => $q ) : ?>
                        <div class="sfs-hr-q-card">
                            <span class="q-num"><?php echo $i + 1; ?>.</span>
                            <div class="q-body">
                                <div class="q-text"><?php echo esc_html( $q['question_text'] ); ?></div>
                                <div class="q-meta">
                                    <span class="sfs-hr-q-type-badge"><?php echo esc_html( ucfirst( str_replace( '_', '/', $q['question_type'] ) ) ); ?></span>
                                    <?php if ( $q['question_type'] === 'choice' && ! empty( $q['options_json'] ) ) :
                                        $opts = json_decode( $q['options_json'], true ) ?: [];
                                    ?>
                                        &nbsp;—&nbsp;<?php echo esc_html( implode( ', ', $opts ) ); ?>
                                    <?php endif; ?>
                                    <?php if ( $q['is_required'] ) : ?>
                                        &nbsp;<span style="color:#dc2626;">*<?php esc_html_e( 'Required', 'sfs-hr' ); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_del ); ?>" />
                                <input type="hidden" name="action" value="sfs_hr_survey_question_del" />
                                <input type="hidden" name="question_id" value="<?php echo (int) $q['id']; ?>" />
                                <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>" />
                                <button type="submit" class="button button-small" style="color:#b91c1c;" onclick="return confirm('<?php esc_attr_e( 'Delete this question?', 'sfs-hr' ); ?>');">&times;</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Add question form -->
                <div class="sfs-hr-q-add-form">
                    <h3 style="margin-top:0;"><?php esc_html_e( 'Add Question', 'sfs-hr' ); ?></h3>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce_save ); ?>" />
                        <input type="hidden" name="action" value="sfs_hr_survey_question_save" />
                        <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>" />

                        <div class="sfs-field">
                            <label for="question_text"><?php esc_html_e( 'Question', 'sfs-hr' ); ?></label>
                            <textarea name="question_text" id="question_text" required placeholder="<?php esc_attr_e( 'Enter your question...', 'sfs-hr' ); ?>"></textarea>
                        </div>

                        <div style="display:flex;gap:12px;">
                            <div class="sfs-field" style="flex:1;">
                                <label for="question_type"><?php esc_html_e( 'Type', 'sfs-hr' ); ?></label>
                                <select name="question_type" id="question_type" onchange="document.getElementById('options-row').style.display = this.value === 'choice' ? 'block' : 'none';">
                                    <option value="rating"><?php esc_html_e( 'Rating (1–5 stars)', 'sfs-hr' ); ?></option>
                                    <option value="text"><?php esc_html_e( 'Text (free-form)', 'sfs-hr' ); ?></option>
                                    <option value="choice"><?php esc_html_e( 'Multiple Choice', 'sfs-hr' ); ?></option>
                                    <option value="yes_no"><?php esc_html_e( 'Yes / No', 'sfs-hr' ); ?></option>
                                </select>
                            </div>
                            <div class="sfs-field" style="flex:0 0 120px;">
                                <label for="sort_order"><?php esc_html_e( 'Order', 'sfs-hr' ); ?></label>
                                <input type="number" name="sort_order" id="sort_order" value="<?php echo count( $questions ) + 1; ?>" min="0" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;" />
                            </div>
                        </div>

                        <div class="sfs-field" id="options-row" style="display:none;">
                            <label for="options_json"><?php esc_html_e( 'Options (one per line)', 'sfs-hr' ); ?></label>
                            <textarea name="options_json" id="options_json" placeholder="<?php esc_attr_e( "Option A\nOption B\nOption C", 'sfs-hr' ); ?>"></textarea>
                        </div>

                        <div class="sfs-field">
                            <label>
                                <input type="checkbox" name="is_required" value="1" checked />
                                <?php esc_html_e( 'Required', 'sfs-hr' ); ?>
                            </label>
                        </div>

                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Add Question', 'sfs-hr' ); ?></button>
                    </form>
                </div>

                <div style="margin-top:16px;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-surveys' ) ); ?>" class="button">&larr; <?php esc_html_e( 'Back to Surveys', 'sfs-hr' ); ?></a>
                </div>
            </div>
        </div>
        <?php
    }

    /* ─────────────── Results page ─────────────── */

    private function render_results_page(): void {
        global $wpdb;
        $survey_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        $survey    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d", $survey_id
        ), ARRAY_A );

        if ( ! $survey ) {
            Helpers::redirect_with_notice( admin_url( 'admin.php?page=sfs-hr-surveys' ), 'error', __( 'Survey not found.', 'sfs-hr' ) );
        }

        $questions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_survey_questions WHERE survey_id = %d ORDER BY sort_order ASC, id ASC",
            $survey_id
        ), ARRAY_A );

        $response_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_survey_responses WHERE survey_id = %d", $survey_id
        ) );

        // Build answer data per question.
        $q_data = [];
        foreach ( $questions as $q ) {
            $qid = (int) $q['id'];
            $answers = $wpdb->get_results( $wpdb->prepare(
                "SELECT a.* FROM {$wpdb->prefix}sfs_hr_survey_answers a
                 JOIN {$wpdb->prefix}sfs_hr_survey_responses r ON a.response_id = r.id
                 WHERE a.question_id = %d",
                $qid
            ), ARRAY_A );

            $q_data[ $qid ] = [
                'question' => $q,
                'answers'  => $answers,
            ];
        }
        ?>
        <div class="wrap sfs-hr-wrap">
            <h1 class="wp-heading-inline">
                <?php printf( esc_html__( 'Results — %s', 'sfs-hr' ), esc_html( $survey['title'] ) ); ?>
            </h1>
            <?php Helpers::render_admin_nav(); ?>
            <hr class="wp-header-end" />

            <style>
                .sfs-hr-results-page { max-width: 900px; margin-top: 16px; }
                .sfs-hr-results-summary {
                    background: #fff; border: 1px solid #e2e4e7; border-radius: 8px;
                    padding: 20px; margin-bottom: 16px; display: flex; gap: 24px; flex-wrap: wrap;
                }
                .sfs-hr-results-stat { text-align: center; }
                .sfs-hr-results-stat .num { font-size: 28px; font-weight: 700; color: #1d2327; }
                .sfs-hr-results-stat .lbl { font-size: 12px; color: #6b7280; }
                .sfs-hr-result-card {
                    background: #fff; border: 1px solid #e2e4e7; border-radius: 8px;
                    padding: 20px; margin-bottom: 12px;
                }
                .sfs-hr-result-card .q-title { font-weight: 600; color: #1d2327; margin-bottom: 12px; }
                .sfs-hr-rating-bar { display: flex; align-items: center; gap: 8px; margin: 4px 0; font-size: 13px; }
                .sfs-hr-rating-bar .bar-track { flex: 1; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; }
                .sfs-hr-rating-bar .bar-fill { height: 100%; background: #2563eb; border-radius: 4px; }
                .sfs-hr-text-answer { padding: 8px 12px; background: #f9fafb; border-radius: 6px; margin: 4px 0; font-size: 13px; color: #374151; }
            </style>

            <div class="sfs-hr-results-page">
                <div class="sfs-hr-results-summary">
                    <div class="sfs-hr-results-stat">
                        <div class="num"><?php echo $response_count; ?></div>
                        <div class="lbl"><?php esc_html_e( 'Total Responses', 'sfs-hr' ); ?></div>
                    </div>
                    <div class="sfs-hr-results-stat">
                        <div class="num"><?php echo count( $questions ); ?></div>
                        <div class="lbl"><?php esc_html_e( 'Questions', 'sfs-hr' ); ?></div>
                    </div>
                    <div class="sfs-hr-results-stat">
                        <div class="num"><?php echo esc_html( $survey['is_anonymous'] ? __( 'Yes', 'sfs-hr' ) : __( 'No', 'sfs-hr' ) ); ?></div>
                        <div class="lbl"><?php esc_html_e( 'Anonymous', 'sfs-hr' ); ?></div>
                    </div>
                </div>

                <?php if ( $response_count === 0 ) : ?>
                    <div style="padding:32px;text-align:center;color:#6b7280;background:#fff;border:1px solid #e2e4e7;border-radius:8px;">
                        <?php esc_html_e( 'No responses yet.', 'sfs-hr' ); ?>
                    </div>
                <?php else :
                    $num = 0;
                    foreach ( $q_data as $qid => $data ) :
                        $num++;
                        $q       = $data['question'];
                        $answers = $data['answers'];
                ?>
                    <div class="sfs-hr-result-card">
                        <div class="q-title"><?php echo esc_html( $num . '. ' . $q['question_text'] ); ?></div>
                        <?php
                        switch ( $q['question_type'] ) {
                            case 'rating':
                                // Calculate distribution.
                                $dist = [ 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 ];
                                $total = 0; $sum = 0;
                                foreach ( $answers as $a ) {
                                    $r = (int) $a['answer_rating'];
                                    if ( $r >= 1 && $r <= 5 ) {
                                        $dist[ $r ]++;
                                        $sum += $r;
                                        $total++;
                                    }
                                }
                                $avg = $total > 0 ? round( $sum / $total, 1 ) : 0;
                                echo '<div style="font-size:13px;color:#6b7280;margin-bottom:8px;">'
                                    . sprintf( esc_html__( 'Average: %s / 5 (%d responses)', 'sfs-hr' ), '<strong>' . $avg . '</strong>', $total )
                                    . '</div>';
                                for ( $star = 5; $star >= 1; $star-- ) {
                                    $pct = $total > 0 ? round( ( $dist[ $star ] / $total ) * 100 ) : 0;
                                    echo '<div class="sfs-hr-rating-bar">'
                                        . '<span style="min-width:16px;">' . $star . '★</span>'
                                        . '<div class="bar-track"><div class="bar-fill" style="width:' . $pct . '%;"></div></div>'
                                        . '<span style="min-width:36px;text-align:right;">' . $pct . '%</span>'
                                        . '</div>';
                                }
                                break;

                            case 'yes_no':
                                $yes = 0; $no = 0;
                                foreach ( $answers as $a ) {
                                    $v = strtolower( trim( $a['answer_text'] ?? '' ) );
                                    if ( $v === 'yes' ) $yes++;
                                    else $no++;
                                }
                                $total_yn = $yes + $no;
                                $yes_pct  = $total_yn > 0 ? round( ( $yes / $total_yn ) * 100 ) : 0;
                                $no_pct   = $total_yn > 0 ? round( ( $no / $total_yn ) * 100 ) : 0;
                                echo '<div class="sfs-hr-rating-bar">'
                                    . '<span style="min-width:32px;color:#16a34a;">' . esc_html__( 'Yes', 'sfs-hr' ) . '</span>'
                                    . '<div class="bar-track"><div class="bar-fill" style="width:' . $yes_pct . '%;background:#16a34a;"></div></div>'
                                    . '<span style="min-width:52px;text-align:right;">' . $yes . ' (' . $yes_pct . '%)</span>'
                                    . '</div>';
                                echo '<div class="sfs-hr-rating-bar">'
                                    . '<span style="min-width:32px;color:#dc2626;">' . esc_html__( 'No', 'sfs-hr' ) . '</span>'
                                    . '<div class="bar-track"><div class="bar-fill" style="width:' . $no_pct . '%;background:#dc2626;"></div></div>'
                                    . '<span style="min-width:52px;text-align:right;">' . $no . ' (' . $no_pct . '%)</span>'
                                    . '</div>';
                                break;

                            case 'choice':
                                $opts_all = json_decode( $q['options_json'], true ) ?: [];
                                $tally    = array_fill_keys( $opts_all, 0 );
                                foreach ( $answers as $a ) {
                                    $v = trim( $a['answer_text'] ?? '' );
                                    if ( isset( $tally[ $v ] ) ) $tally[ $v ]++;
                                }
                                $total_c = array_sum( $tally );
                                foreach ( $tally as $opt => $cnt ) {
                                    $pct = $total_c > 0 ? round( ( $cnt / $total_c ) * 100 ) : 0;
                                    echo '<div class="sfs-hr-rating-bar">'
                                        . '<span style="min-width:80px;">' . esc_html( $opt ) . '</span>'
                                        . '<div class="bar-track"><div class="bar-fill" style="width:' . $pct . '%;"></div></div>'
                                        . '<span style="min-width:52px;text-align:right;">' . $cnt . ' (' . $pct . '%)</span>'
                                        . '</div>';
                                }
                                break;

                            case 'text':
                                foreach ( $answers as $a ) {
                                    $txt = trim( $a['answer_text'] ?? '' );
                                    if ( $txt !== '' ) {
                                        echo '<div class="sfs-hr-text-answer">' . esc_html( $txt ) . '</div>';
                                    }
                                }
                                break;
                        }
                        ?>
                    </div>
                <?php
                    endforeach;
                endif;
                ?>

                <div style="margin-top:16px;">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-surveys' ) ); ?>" class="button">&larr; <?php esc_html_e( 'Back to Surveys', 'sfs-hr' ); ?></a>
                </div>
            </div>
        </div>
        <?php
    }

    /* ───────────────────── form handlers ───────────────────── */

    public function handle_survey_save(): void {
        Helpers::require_cap( 'sfs_hr.manage' );
        check_admin_referer( 'sfs_hr_survey_save' );

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_surveys';

        $id          = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
        $title       = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '';
        $description = isset( $_POST['description'] ) ? sanitize_textarea_field( $_POST['description'] ) : '';
        $is_anon     = isset( $_POST['is_anonymous'] ) ? 1 : 0;
        $scope       = isset( $_POST['target_scope'] ) ? sanitize_key( $_POST['target_scope'] ) : 'all';
        $target_ids  = null;

        if ( $scope === 'department' && ! empty( $_POST['target_ids'] ) ) {
            $target_ids = json_encode( array_map( 'intval', (array) $_POST['target_ids'] ) );
        }

        if ( empty( $title ) ) {
            Helpers::redirect_with_notice( admin_url( 'admin.php?page=sfs-hr-surveys&action=new' ), 'error', __( 'Title is required.', 'sfs-hr' ) );
        }

        $now  = Helpers::now_mysql();
        $data = [
            'title'        => $title,
            'description'  => $description,
            'is_anonymous' => $is_anon,
            'target_scope' => in_array( $scope, [ 'all', 'department', 'individual' ], true ) ? $scope : 'all',
            'target_ids'   => $target_ids,
            'updated_at'   => $now,
        ];

        if ( $id > 0 ) {
            $wpdb->update( $table, $data, [ 'id' => $id ] );
        } else {
            $data['created_by'] = get_current_user_id();
            $data['created_at'] = $now;
            $data['status']     = 'draft';
            $wpdb->insert( $table, $data );
            $id = $wpdb->insert_id;
        }

        Helpers::redirect_with_notice(
            admin_url( "admin.php?page=sfs-hr-surveys&action=questions&id={$id}" ),
            'success',
            __( 'Survey saved. Now add your questions.', 'sfs-hr' )
        );
    }

    public function handle_survey_delete(): void {
        Helpers::require_cap( 'sfs_hr.manage' );
        check_admin_referer( 'sfs_hr_survey_delete' );

        global $wpdb;
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

        if ( $id > 0 ) {
            // Delete answers for this survey's responses.
            $response_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}sfs_hr_survey_responses WHERE survey_id = %d", $id
            ) );
            if ( ! empty( $response_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $response_ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}sfs_hr_survey_answers WHERE response_id IN ({$placeholders})",
                    ...$response_ids
                ) );
            }
            $wpdb->delete( $wpdb->prefix . 'sfs_hr_survey_responses', [ 'survey_id' => $id ] );
            $wpdb->delete( $wpdb->prefix . 'sfs_hr_survey_questions', [ 'survey_id' => $id ] );
            $wpdb->delete( $wpdb->prefix . 'sfs_hr_surveys', [ 'id' => $id ] );
        }

        Helpers::redirect_with_notice( admin_url( 'admin.php?page=sfs-hr-surveys' ), 'success', __( 'Survey deleted.', 'sfs-hr' ) );
    }

    public function handle_survey_publish(): void {
        Helpers::require_cap( 'sfs_hr.manage' );
        check_admin_referer( 'sfs_hr_survey_publish' );

        global $wpdb;
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

        // Must have at least one question.
        $q_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_survey_questions WHERE survey_id = %d", $id
        ) );
        if ( $q_count === 0 ) {
            Helpers::redirect_with_notice(
                admin_url( "admin.php?page=sfs-hr-surveys&action=questions&id={$id}" ),
                'error',
                __( 'Add at least one question before publishing.', 'sfs-hr' )
            );
        }

        $wpdb->update(
            $wpdb->prefix . 'sfs_hr_surveys',
            [ 'status' => 'published', 'published_at' => Helpers::now_mysql(), 'updated_at' => Helpers::now_mysql() ],
            [ 'id' => $id, 'status' => 'draft' ]
        );

        Helpers::redirect_with_notice( admin_url( 'admin.php?page=sfs-hr-surveys' ), 'success', __( 'Survey published!', 'sfs-hr' ) );
    }

    public function handle_survey_close(): void {
        Helpers::require_cap( 'sfs_hr.manage' );
        check_admin_referer( 'sfs_hr_survey_close' );

        global $wpdb;
        $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

        $wpdb->update(
            $wpdb->prefix . 'sfs_hr_surveys',
            [ 'status' => 'closed', 'closed_at' => Helpers::now_mysql(), 'updated_at' => Helpers::now_mysql() ],
            [ 'id' => $id, 'status' => 'published' ]
        );

        Helpers::redirect_with_notice( admin_url( 'admin.php?page=sfs-hr-surveys' ), 'success', __( 'Survey closed.', 'sfs-hr' ) );
    }

    public function handle_question_save(): void {
        Helpers::require_cap( 'sfs_hr.manage' );
        check_admin_referer( 'sfs_hr_survey_question_save' );

        global $wpdb;
        $survey_id     = isset( $_POST['survey_id'] ) ? (int) $_POST['survey_id'] : 0;
        $question_text = isset( $_POST['question_text'] ) ? sanitize_textarea_field( $_POST['question_text'] ) : '';
        $question_type = isset( $_POST['question_type'] ) ? sanitize_key( $_POST['question_type'] ) : 'rating';
        $sort_order    = isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0;
        $is_required   = isset( $_POST['is_required'] ) ? 1 : 0;

        if ( empty( $question_text ) || $survey_id <= 0 ) {
            Helpers::redirect_with_notice(
                admin_url( "admin.php?page=sfs-hr-surveys&action=questions&id={$survey_id}" ),
                'error',
                __( 'Question text is required.', 'sfs-hr' )
            );
        }

        $valid_types = [ 'rating', 'text', 'choice', 'yes_no' ];
        if ( ! in_array( $question_type, $valid_types, true ) ) {
            $question_type = 'rating';
        }

        $options_json = null;
        if ( $question_type === 'choice' && ! empty( $_POST['options_json'] ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( $_POST['options_json'] ) ) ) );
            $options_json = json_encode( array_values( $lines ) );
        }

        $wpdb->insert( $wpdb->prefix . 'sfs_hr_survey_questions', [
            'survey_id'     => $survey_id,
            'sort_order'    => $sort_order,
            'question_text' => $question_text,
            'question_type' => $question_type,
            'options_json'  => $options_json,
            'is_required'   => $is_required,
            'created_at'    => Helpers::now_mysql(),
        ] );

        Helpers::redirect_with_notice(
            admin_url( "admin.php?page=sfs-hr-surveys&action=questions&id={$survey_id}" ),
            'success',
            __( 'Question added.', 'sfs-hr' )
        );
    }

    public function handle_question_delete(): void {
        Helpers::require_cap( 'sfs_hr.manage' );
        check_admin_referer( 'sfs_hr_survey_question_del' );

        global $wpdb;
        $question_id = isset( $_POST['question_id'] ) ? (int) $_POST['question_id'] : 0;
        $survey_id   = isset( $_POST['survey_id'] ) ? (int) $_POST['survey_id'] : 0;

        if ( $question_id > 0 ) {
            $wpdb->delete( $wpdb->prefix . 'sfs_hr_survey_answers', [ 'question_id' => $question_id ] );
            $wpdb->delete( $wpdb->prefix . 'sfs_hr_survey_questions', [ 'id' => $question_id ] );
        }

        Helpers::redirect_with_notice(
            admin_url( "admin.php?page=sfs-hr-surveys&action=questions&id={$survey_id}" ),
            'success',
            __( 'Question removed.', 'sfs-hr' )
        );
    }

    /* ─────── Employee response handler (used from frontend) ─────── */

    public function handle_submit_response(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'You must be logged in.', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_survey_submit_response' );

        global $wpdb;
        $survey_id   = isset( $_POST['survey_id'] ) ? (int) $_POST['survey_id'] : 0;
        $employee_id = Helpers::current_employee_id();

        if ( ! $employee_id || $survey_id <= 0 ) {
            wp_safe_redirect( wp_get_referer() ?: home_url() );
            exit;
        }

        // Verify survey is published.
        $survey = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d AND status = 'published'", $survey_id
        ), ARRAY_A );
        if ( ! $survey ) {
            wp_safe_redirect( wp_get_referer() ?: home_url() );
            exit;
        }

        // Check not already responded.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_survey_responses WHERE survey_id = %d AND employee_id = %d",
            $survey_id, $employee_id
        ) );
        if ( $existing ) {
            wp_safe_redirect( wp_get_referer() ?: home_url() );
            exit;
        }

        // Check scope targeting.
        if ( $survey['target_scope'] === 'department' && ! empty( $survey['target_ids'] ) ) {
            $emp = Helpers::get_employee_row( $employee_id );
            $target_dept_ids = json_decode( $survey['target_ids'], true ) ?: [];
            if ( ! in_array( (int) ( $emp['dept_id'] ?? 0 ), $target_dept_ids, true ) ) {
                wp_safe_redirect( wp_get_referer() ?: home_url() );
                exit;
            }
        }

        // Get questions.
        $questions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_survey_questions WHERE survey_id = %d ORDER BY sort_order ASC, id ASC",
            $survey_id
        ), ARRAY_A );

        // Create response.
        $wpdb->insert( $wpdb->prefix . 'sfs_hr_survey_responses', [
            'survey_id'    => $survey_id,
            'employee_id'  => $employee_id,
            'submitted_at' => Helpers::now_mysql(),
        ] );
        $response_id = $wpdb->insert_id;

        // Save answers.
        foreach ( $questions as $q ) {
            $qid  = (int) $q['id'];
            $key  = 'q_' . $qid;

            $answer_text   = null;
            $answer_rating = null;

            switch ( $q['question_type'] ) {
                case 'rating':
                    $val = isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0;
                    if ( $val >= 1 && $val <= 5 ) {
                        $answer_rating = $val;
                    }
                    break;
                case 'yes_no':
                    $answer_text = isset( $_POST[ $key ] ) ? sanitize_text_field( $_POST[ $key ] ) : '';
                    break;
                case 'choice':
                    $answer_text = isset( $_POST[ $key ] ) ? sanitize_text_field( $_POST[ $key ] ) : '';
                    break;
                case 'text':
                    $answer_text = isset( $_POST[ $key ] ) ? sanitize_textarea_field( $_POST[ $key ] ) : '';
                    break;
            }

            $wpdb->insert( $wpdb->prefix . 'sfs_hr_survey_answers', [
                'response_id'   => $response_id,
                'question_id'   => $qid,
                'answer_text'   => $answer_text,
                'answer_rating' => $answer_rating,
            ] );
        }

        // Redirect back with success.
        $referer = wp_get_referer() ?: home_url();
        $referer = add_query_arg( 'survey_submitted', '1', $referer );
        wp_safe_redirect( $referer );
        exit;
    }
}
