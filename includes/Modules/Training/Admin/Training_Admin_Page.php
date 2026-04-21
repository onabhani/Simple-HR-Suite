<?php
namespace SFS\HR\Modules\Training\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Training\Services\Training_Service;
use SFS\HR\Modules\Training\Services\Enrollment_Service;
use SFS\HR\Modules\Training\Services\Certification_Service;

/**
 * Training_Admin_Page (M11)
 *
 * Admin submenu with four tabs:
 *   1. Programs  — manage training programs catalogue
 *   2. Sessions  — schedule concrete training sessions
 *   3. Requests  — approve / reject employee training requests
 *   4. Certifications — expiring certs report + compliance overview
 *
 * @since M11
 */
class Training_Admin_Page {

    const MENU_SLUG = 'sfs-hr-training';

    public function hooks(): void {
        add_action( 'admin_menu', [ $this, 'menu' ], 25 );
        add_action( 'admin_post_sfs_hr_training_save_program',  [ $this, 'handle_save_program' ] );
        add_action( 'admin_post_sfs_hr_training_save_session',  [ $this, 'handle_save_session' ] );
        add_action( 'admin_post_sfs_hr_training_approve_request', [ $this, 'handle_approve_request' ] );
        add_action( 'admin_post_sfs_hr_training_reject_request',  [ $this, 'handle_reject_request' ] );
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __( 'Training', 'sfs-hr' ),
            __( 'Training', 'sfs-hr' ),
            'sfs_hr.view',
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    /* ===================================================================
     *  Main renderer + tab dispatch
     * =================================================================== */

    public function render(): void {
        if ( ! current_user_can( 'sfs_hr.view' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'programs';
        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e( 'Training & Development', 'sfs-hr' ); ?></h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ( [
                    'programs'       => __( 'Programs', 'sfs-hr' ),
                    'sessions'       => __( 'Sessions', 'sfs-hr' ),
                    'requests'       => __( 'Requests', 'sfs-hr' ),
                    'certifications' => __( 'Certifications', 'sfs-hr' ),
                ] as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) ) ); ?>"
                       class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ( ! empty( $_GET['msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['err'] ) ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( (string) wp_unslash( $_GET['err'] ) ) ); ?></p></div>
            <?php endif; ?>

            <div style="margin-top:20px;">
                <?php
                switch ( $tab ) {
                    case 'sessions':       $this->render_sessions();       break;
                    case 'requests':       $this->render_requests();       break;
                    case 'certifications': $this->render_certifications(); break;
                    case 'programs':
                    default:               $this->render_programs();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /* ───────── Programs tab ───────── */

    private function render_programs(): void {
        $programs = Training_Service::list_programs( false );
        $edit_id  = isset( $_GET['edit_program'] ) ? (int) $_GET['edit_program'] : 0;
        $editing  = $edit_id > 0 ? Training_Service::get_program( $edit_id ) : null;
        ?>
        <h2><?php esc_html_e( 'Training Programs', 'sfs-hr' ); ?></h2>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Code', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Title', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Category', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Duration (hrs)', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
            </tr></thead>
            <tbody>
                <?php if ( empty( $programs ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No programs found.', 'sfs-hr' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $programs as $p ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $p['code'] ); ?></code></td>
                            <td><?php echo esc_html( $p['title'] ); ?></td>
                            <td><?php echo esc_html( $p['category'] ?: '—' ); ?></td>
                            <td><?php echo (int) $p['duration_hours']; ?></td>
                            <td><?php echo (int) $p['is_active'] ? esc_html__( 'Active', 'sfs-hr' ) : esc_html__( 'Disabled', 'sfs-hr' ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'programs', 'edit_program' => (int) $p['id'] ], admin_url( 'admin.php' ) ) ); ?>"
                                   class="button button-small"><?php esc_html_e( 'Edit', 'sfs-hr' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h3 style="margin-top:30px;">
            <?php echo $editing ? esc_html__( 'Edit Program', 'sfs-hr' ) : esc_html__( 'Add Program', 'sfs-hr' ); ?>
        </h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:800px;">
            <input type="hidden" name="action" value="sfs_hr_training_save_program" />
            <?php wp_nonce_field( 'sfs_hr_training_save_program', '_sfs_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="code"><?php esc_html_e( 'Code', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" id="code" name="code" class="regular-text" required
                               pattern="[a-z0-9_-]+"
                               value="<?php echo esc_attr( $editing['code'] ?? '' ); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="title"><?php esc_html_e( 'Title', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" id="title" name="title" class="regular-text" required
                               value="<?php echo esc_attr( $editing['title'] ?? '' ); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="title_ar"><?php esc_html_e( 'Title (Arabic)', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" id="title_ar" name="title_ar" class="regular-text"
                               value="<?php echo esc_attr( $editing['title_ar'] ?? '' ); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="category"><?php esc_html_e( 'Category', 'sfs-hr' ); ?></label></th>
                    <td>
                        <select id="category" name="category">
                            <?php foreach ( [
                                ''           => __( '— Select —', 'sfs-hr' ),
                                'technical'  => __( 'Technical', 'sfs-hr' ),
                                'leadership' => __( 'Leadership', 'sfs-hr' ),
                                'compliance' => __( 'Compliance', 'sfs-hr' ),
                                'safety'     => __( 'Safety', 'sfs-hr' ),
                                'soft_skills'=> __( 'Soft Skills', 'sfs-hr' ),
                                'onboarding' => __( 'Onboarding', 'sfs-hr' ),
                                'other'      => __( 'Other', 'sfs-hr' ),
                            ] as $val => $lbl ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>"
                                    <?php selected( $editing['category'] ?? '', $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="duration_hours"><?php esc_html_e( 'Duration (hours)', 'sfs-hr' ); ?></label></th>
                    <td><input type="number" id="duration_hours" name="duration_hours" min="0"
                               value="<?php echo (int) ( $editing['duration_hours'] ?? 0 ); ?>" style="width:100px;" /></td>
                </tr>
                <tr>
                    <th><label for="description"><?php esc_html_e( 'Description', 'sfs-hr' ); ?></label></th>
                    <td><textarea id="description" name="description" rows="3" class="large-text"><?php echo esc_textarea( $editing['description'] ?? '' ); ?></textarea></td>
                </tr>
                <tr>
                    <th><label for="sort_order"><?php esc_html_e( 'Sort Order', 'sfs-hr' ); ?></label></th>
                    <td><input type="number" id="sort_order" name="sort_order"
                               value="<?php echo (int) ( $editing['sort_order'] ?? 0 ); ?>" style="width:80px;" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Active', 'sfs-hr' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" value="1"
                                <?php checked( $editing ? (int) $editing['is_active'] : 1, 1 ); ?> />
                            <?php esc_html_e( 'Yes', 'sfs-hr' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button( $editing ? __( 'Update Program', 'sfs-hr' ) : __( 'Save Program', 'sfs-hr' ) ); ?>
        </form>
        <?php
    }

    /* ───────── Sessions tab ───────── */

    private function render_sessions(): void {
        $filter_program = isset( $_GET['program_id'] ) ? (int) $_GET['program_id'] : 0;
        $filter_status  = isset( $_GET['status'] ) ? sanitize_key( (string) wp_unslash( $_GET['status'] ) ) : '';
        $sessions       = Training_Service::list_sessions( $filter_program, $filter_status );
        $programs       = Training_Service::list_programs( true );
        ?>
        <h2><?php esc_html_e( 'Training Sessions', 'sfs-hr' ); ?></h2>

        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:15px;">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
            <input type="hidden" name="tab" value="sessions" />
            <label><?php esc_html_e( 'Program', 'sfs-hr' ); ?>:
                <select name="program_id">
                    <option value="0"><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
                    <?php foreach ( $programs as $p ) : ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php selected( $filter_program, (int) $p['id'] ); ?>>
                            <?php echo esc_html( $p['code'] . ' — ' . $p['title'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e( 'Status', 'sfs-hr' ); ?>:
                <select name="status">
                    <option value=""><?php esc_html_e( 'All', 'sfs-hr' ); ?></option>
                    <?php foreach ( [ 'scheduled', 'in_progress', 'completed', 'cancelled' ] as $st ) : ?>
                        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $filter_status, $st ); ?>>
                            <?php echo esc_html( ucwords( str_replace( '_', ' ', $st ) ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php submit_button( __( 'Filter', 'sfs-hr' ), 'secondary', '', false ); ?>
        </form>

        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e( 'Program', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Session Title', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Dates', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Location', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Trainer', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Capacity', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
            </tr></thead>
            <tbody>
                <?php if ( empty( $sessions ) ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'No sessions found.', 'sfs-hr' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $sessions as $s ) :
                        $enrolled = count( Enrollment_Service::list_session_enrollments( (int) $s['id'] ) );
                        $cap      = (int) $s['capacity'];
                    ?>
                        <tr>
                            <td><?php echo esc_html( $s['program_code'] . ' — ' . $s['program_title'] ); ?></td>
                            <td><?php echo esc_html( $s['title'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $s['start_date'] . ' ~ ' . $s['end_date'] ); ?></td>
                            <td><?php echo esc_html( $s['location'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $s['trainer'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $enrolled . ( $cap > 0 ? ' / ' . $cap : '' ) ); ?></td>
                            <td><?php echo esc_html( ucwords( str_replace( '_', ' ', $s['status'] ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h3 style="margin-top:30px;"><?php esc_html_e( 'Add Session', 'sfs-hr' ); ?></h3>
        <?php if ( empty( $programs ) ) : ?>
            <p><?php esc_html_e( 'Create a program first before scheduling sessions.', 'sfs-hr' ); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:800px;">
                <input type="hidden" name="action" value="sfs_hr_training_save_session" />
                <?php wp_nonce_field( 'sfs_hr_training_save_session', '_sfs_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="program_id"><?php esc_html_e( 'Program', 'sfs-hr' ); ?></label></th>
                        <td>
                            <select id="program_id" name="program_id" required>
                                <option value=""><?php esc_html_e( '— Select —', 'sfs-hr' ); ?></option>
                                <?php foreach ( $programs as $p ) : ?>
                                    <option value="<?php echo (int) $p['id']; ?>">
                                        <?php echo esc_html( $p['code'] . ' — ' . $p['title'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="session_title"><?php esc_html_e( 'Session Title', 'sfs-hr' ); ?></label></th>
                        <td><input type="text" id="session_title" name="title" class="regular-text"
                                   placeholder="<?php esc_attr_e( 'Optional override', 'sfs-hr' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="start_date"><?php esc_html_e( 'Start Date', 'sfs-hr' ); ?></label></th>
                        <td><input type="date" id="start_date" name="start_date" required /></td>
                    </tr>
                    <tr>
                        <th><label for="end_date"><?php esc_html_e( 'End Date', 'sfs-hr' ); ?></label></th>
                        <td><input type="date" id="end_date" name="end_date" required /></td>
                    </tr>
                    <tr>
                        <th><label for="start_time"><?php esc_html_e( 'Start Time', 'sfs-hr' ); ?></label></th>
                        <td><input type="time" id="start_time" name="start_time" /></td>
                    </tr>
                    <tr>
                        <th><label for="end_time"><?php esc_html_e( 'End Time', 'sfs-hr' ); ?></label></th>
                        <td><input type="time" id="end_time" name="end_time" /></td>
                    </tr>
                    <tr>
                        <th><label for="location"><?php esc_html_e( 'Location', 'sfs-hr' ); ?></label></th>
                        <td><input type="text" id="location" name="location" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="trainer"><?php esc_html_e( 'Trainer', 'sfs-hr' ); ?></label></th>
                        <td><input type="text" id="trainer" name="trainer" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="capacity"><?php esc_html_e( 'Capacity', 'sfs-hr' ); ?></label></th>
                        <td>
                            <input type="number" id="capacity" name="capacity" min="0" value="0" style="width:100px;" />
                            <p class="description"><?php esc_html_e( '0 = unlimited', 'sfs-hr' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="notes"><?php esc_html_e( 'Notes', 'sfs-hr' ); ?></label></th>
                        <td><textarea id="notes" name="notes" rows="3" class="large-text"></textarea></td>
                    </tr>
                </table>
                <?php submit_button( __( 'Create Session', 'sfs-hr' ) ); ?>
            </form>
        <?php endif;
    }

    /* ───────── Requests tab ───────── */

    private function render_requests(): void {
        $pending = Enrollment_Service::list_pending_requests();
        ?>
        <h2><?php esc_html_e( 'Pending Training Requests', 'sfs-hr' ); ?></h2>
        <?php if ( empty( $pending ) ) : ?>
            <p><?php esc_html_e( 'No pending requests.', 'sfs-hr' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Ref', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Training', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Provider', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Est. Cost', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Preferred Date', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Justification', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Requested', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'sfs-hr' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $pending as $r ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $r['request_number'] ?? '—' ); ?></code></td>
                            <td><?php echo esc_html( trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) ) ?: '#' . (int) $r['employee_id'] ); ?></td>
                            <td><?php echo esc_html( $r['training_title'] ); ?></td>
                            <td><?php echo esc_html( $r['training_type'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $r['provider'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( number_format( (float) ( $r['estimated_cost'] ?? 0 ), 2 ) ); ?> <?php echo esc_html( $r['currency'] ?? 'SAR' ); ?></td>
                            <td><?php echo esc_html( $r['preferred_date'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $r['justification'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $r['created_at'] ); ?></td>
                            <td style="white-space:nowrap;">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="sfs_hr_training_approve_request" />
                                    <input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>" />
                                    <?php wp_nonce_field( 'sfs_hr_training_approve_request', '_sfs_nonce' ); ?>
                                    <button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Approve', 'sfs-hr' ); ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="sfs_hr_training_reject_request" />
                                    <input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>" />
                                    <?php wp_nonce_field( 'sfs_hr_training_reject_request', '_sfs_nonce' ); ?>
                                    <button type="submit" class="button button-small"><?php esc_html_e( 'Reject', 'sfs-hr' ); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /* ───────── Certifications tab ───────── */

    private function render_certifications(): void {
        $days     = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30;
        $days     = max( 1, min( 365, $days ) );
        $expiring = Certification_Service::get_expiring( $days );
        ?>
        <h2><?php esc_html_e( 'Expiring Certifications', 'sfs-hr' ); ?></h2>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:15px;">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
            <input type="hidden" name="tab" value="certifications" />
            <label><?php esc_html_e( 'Expiring within', 'sfs-hr' ); ?>:
                <input type="number" name="days" min="1" max="365" value="<?php echo esc_attr( $days ); ?>" style="width:80px;" />
                <?php esc_html_e( 'days', 'sfs-hr' ); ?>
            </label>
            <?php submit_button( __( 'Apply', 'sfs-hr' ), 'secondary', '', false ); ?>
        </form>

        <?php if ( empty( $expiring ) ) : ?>
            <p><?php esc_html_e( 'No certifications expiring in the selected window.', 'sfs-hr' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Certification', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Issuing Body', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Credential ID', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Issued', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Expires', 'sfs-hr' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $expiring as $c ) : ?>
                        <tr>
                            <td><?php echo esc_html( ( $c['employee_code'] ?? '' ) . ' — ' . trim( ( $c['first_name'] ?? '' ) . ' ' . ( $c['last_name'] ?? '' ) ) ); ?></td>
                            <td><?php echo esc_html( $c['cert_name'] ); ?></td>
                            <td><?php echo esc_html( $c['issuing_body'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $c['credential_id'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $c['issued_date'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $c['expiry_date'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2 style="margin-top:30px;"><?php esc_html_e( 'Compliance Overview', 'sfs-hr' ); ?></h2>
        <?php
        $compliance = Certification_Service::compliance_report();
        if ( empty( $compliance ) ) : ?>
            <p><?php esc_html_e( 'No role-based certification requirements configured, or no employees with applicable roles.', 'sfs-hr' ); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Position', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Certification', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Mandatory', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $compliance as $emp ) :
                        $emp_label = esc_html( ( $emp['employee_code'] ?? '' ) . ' — ' . trim( ( $emp['first_name'] ?? '' ) . ' ' . ( $emp['last_name'] ?? '' ) ) );
                        $first     = true;
                        foreach ( $emp['certifications'] as $cert_name => $info ) :
                    ?>
                        <tr>
                            <?php if ( $first ) : ?>
                                <td rowspan="<?php echo count( $emp['certifications'] ); ?>"><?php echo $emp_label; ?></td>
                                <td rowspan="<?php echo count( $emp['certifications'] ); ?>"><?php echo esc_html( $emp['position'] ?: '—' ); ?></td>
                            <?php $first = false; endif; ?>
                            <td><?php echo esc_html( $cert_name ); ?></td>
                            <td><?php echo $info['mandatory'] ? esc_html__( 'Yes', 'sfs-hr' ) : esc_html__( 'No', 'sfs-hr' ); ?></td>
                            <td>
                                <?php
                                $status_label = match ( $info['status'] ) {
                                    'valid'   => __( 'Valid', 'sfs-hr' ),
                                    'expired' => __( 'Expired', 'sfs-hr' ),
                                    'missing' => __( 'Missing', 'sfs-hr' ),
                                    default   => $info['status'],
                                };
                                $status_color = match ( $info['status'] ) {
                                    'valid'   => '#00a32a',
                                    'expired' => '#d63638',
                                    'missing' => '#dba617',
                                    default   => '#787c82',
                                };
                                ?>
                                <span style="color:<?php echo esc_attr( $status_color ); ?>;font-weight:600;">
                                    <?php echo esc_html( $status_label ); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    /* ===================================================================
     *  POST handlers
     * =================================================================== */

    public function handle_save_program(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_training_save_program', '_sfs_nonce' );

        $result = Training_Service::upsert_program( [
            'code'           => sanitize_key( (string) ( $_POST['code'] ?? '' ) ),
            'title'          => sanitize_text_field( (string) ( $_POST['title'] ?? '' ) ),
            'title_ar'       => sanitize_text_field( (string) ( $_POST['title_ar'] ?? '' ) ),
            'description'    => sanitize_textarea_field( (string) ( $_POST['description'] ?? '' ) ),
            'category'       => sanitize_key( (string) ( $_POST['category'] ?? '' ) ),
            'duration_hours' => (int) ( $_POST['duration_hours'] ?? 0 ),
            'sort_order'     => (int) ( $_POST['sort_order'] ?? 0 ),
            'is_active'      => ! empty( $_POST['is_active'] ) ? 1 : 0,
        ] );

        if ( ! $result['success'] ) {
            wp_safe_redirect( add_query_arg( [
                'page' => self::MENU_SLUG,
                'tab'  => 'programs',
                'err'  => $result['error'] ?? __( 'Failed to save program.', 'sfs-hr' ),
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'programs', 'msg' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_save_session(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_training_save_session', '_sfs_nonce' );

        $result = Training_Service::create_session( [
            'program_id' => (int) ( $_POST['program_id'] ?? 0 ),
            'title'      => sanitize_text_field( (string) ( $_POST['title'] ?? '' ) ),
            'start_date' => sanitize_text_field( (string) ( $_POST['start_date'] ?? '' ) ),
            'end_date'   => sanitize_text_field( (string) ( $_POST['end_date'] ?? '' ) ),
            'start_time' => sanitize_text_field( (string) ( $_POST['start_time'] ?? '' ) ),
            'end_time'   => sanitize_text_field( (string) ( $_POST['end_time'] ?? '' ) ),
            'location'   => sanitize_text_field( (string) ( $_POST['location'] ?? '' ) ),
            'trainer'    => sanitize_text_field( (string) ( $_POST['trainer'] ?? '' ) ),
            'capacity'   => (int) ( $_POST['capacity'] ?? 0 ),
            'notes'      => sanitize_textarea_field( (string) ( $_POST['notes'] ?? '' ) ),
        ] );

        if ( ! $result['success'] ) {
            wp_safe_redirect( add_query_arg( [
                'page' => self::MENU_SLUG,
                'tab'  => 'sessions',
                'err'  => $result['error'] ?? __( 'Failed to create session.', 'sfs-hr' ),
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'sessions', 'msg' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_approve_request(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_training_approve_request', '_sfs_nonce' );

        $request_id  = (int) ( $_POST['request_id'] ?? 0 );
        $approver_id = get_current_user_id();

        if ( $request_id <= 0 ) {
            wp_die( esc_html__( 'Invalid request ID.', 'sfs-hr' ) );
        }

        Enrollment_Service::approve_request( $request_id, $approver_id );

        wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'requests', 'msg' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_reject_request(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_training_reject_request', '_sfs_nonce' );

        $request_id  = (int) ( $_POST['request_id'] ?? 0 );
        $approver_id = get_current_user_id();

        if ( $request_id <= 0 ) {
            wp_die( esc_html__( 'Invalid request ID.', 'sfs-hr' ) );
        }

        Enrollment_Service::reject_request( $request_id, $approver_id );

        wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'requests', 'msg' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
