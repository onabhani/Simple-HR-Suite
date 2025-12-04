<?php
namespace SFS\HR\Frontend;
use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Leave\Leave_UI;
use SFS\HR\Modules\Attendance\Rest\Public_REST as Attendance_Public_REST;

if ( ! defined('ABSPATH') ) { exit; }

class Shortcodes {
    public function hooks(): void {
        add_shortcode('sfs_hr_my_profile', [$this, 'my_profile']);
        add_shortcode('sfs_hr_my_loans', [$this, 'my_loans']);
        add_shortcode('sfs_hr_leave_request', [$this, 'leave_request']);
        add_shortcode('sfs_hr_my_leaves', [$this, 'my_leaves']);
    }
    
    
    public function my_profile( $atts = [], $content = '' ): string {
    if ( ! is_user_logged_in() ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__( 'Please log in to view your profile.', 'sfs-hr' ) . '</div>';
    }

    $emp_id = Helpers::current_employee_id();
    if ( ! $emp_id ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__( 'Your HR profile is not linked. Please contact HR.', 'sfs-hr' ) . '</div>';
    }

    $emp = Helpers::get_employee_row( $emp_id );
    if ( ! $emp || ! is_array( $emp ) ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__( 'Profile not found.', 'sfs-hr' ) . '</div>';
    }

    global $wpdb;

    // Department name.
    $dept_name = '';
    if ( ! empty( $emp['dept_id'] ) ) {
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $dept_name  = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT name FROM {$dept_table} WHERE id = %d",
                (int) $emp['dept_id']
            )
        );
    }

    // Core fields.
    $first_name = (string) ( $emp['first_name'] ?? '' );
    $last_name  = (string) ( $emp['last_name']  ?? '' );
    $full_name  = trim( $first_name . ' ' . $last_name );
    if ( $full_name === '' ) {
        $full_name = '#' . (int) $emp_id;
    }

    $code        = (string) ( $emp['employee_code']         ?? '' );
    $status      = (string) ( $emp['status']                ?? '' );
    $position    = (string) ( $emp['position']              ?? '' );
    $email       = (string) ( $emp['email']                 ?? '' );
    $phone       = (string) ( $emp['phone']                 ?? '' );
    $hire_date   = (string) ( $emp['hired_at']              ?? '' );
    $gender      = (string) ( $emp['gender']                ?? '' );
    $base_salary = isset( $emp['base_salary'] ) && $emp['base_salary'] !== null
        ? number_format_i18n( (float) $emp['base_salary'], 2 )
        : '';

    $national_id = (string) ( $emp['national_id']           ?? '' );
    $nid_exp     = (string) ( $emp['national_id_expiry']    ?? '' );
    $passport_no = (string) ( $emp['passport_no']           ?? '' );
    $pass_exp    = (string) ( $emp['passport_expiry']       ?? '' );
    $emg_name    = (string) ( $emp['emergency_contact_name']  ?? '' );
    $emg_phone   = (string) ( $emp['emergency_contact_phone'] ?? '' );
    $photo_id    = isset( $emp['photo_id'] ) ? (int) $emp['photo_id'] : 0;

    // WP username (if linked).
    $wp_username = '';
    if ( ! empty( $emp['user_id'] ) ) {
        $u = get_userdata( (int) $emp['user_id'] );
        if ( $u && $u->user_login ) {
            $wp_username = (string) $u->user_login;
        }
    }
    
        // WP username (if linked).
    $wp_username = '';
    if ( ! empty( $emp['user_id'] ) ) {
        $u = get_userdata( (int) $emp['user_id'] );
        if ( $u && $u->user_login ) {
            $wp_username = (string) $u->user_login;
        }
    }


        // Can this user use self-web attendance?
    $can_self_clock = class_exists( Attendance_Public_REST::class )
        && Attendance_Public_REST::can_punch_self();

    // Active tab from query (?sfs_hr_tab=leave / attendance).
    $active_tab = isset( $_GET['sfs_hr_tab'] )
        ? sanitize_key( (string) $_GET['sfs_hr_tab'] )
        : 'overview';

    // Tab URLs (keep current query string but override sfs_hr_tab).
    $base_url        = remove_query_arg( 'sfs_hr_tab' );
    $overview_url    = add_query_arg( 'sfs_hr_tab', 'overview',   $base_url );
    $leave_url       = add_query_arg( 'sfs_hr_tab', 'leave',      $base_url );
    $attendance_url  = add_query_arg( 'sfs_hr_tab', 'attendance', $base_url );


    // Preload assets once – we’ll show them in both desktop table + mobile cards.
    $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $asset_table  = $wpdb->prefix . 'sfs_hr_assets';
    $asset_rows   = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT 
                a.*,
                ast.id         AS asset_id,
                ast.name       AS asset_name,
                ast.asset_code AS asset_code,
                ast.category   AS category
            FROM {$assign_table} a
            LEFT JOIN {$asset_table} ast ON ast.id = a.asset_id
            WHERE a.employee_id = %d
            ORDER BY a.created_at DESC
            LIMIT 200
            ",
            (int) $emp_id
        ),
        ARRAY_A
    );

    // Helper: status badge.
    $asset_status_badge_fn = static function ( string $status_key ): string {
        $status_key = trim( $status_key );
        if ( $status_key === '' ) {
            return '';
        }
        if ( method_exists( Helpers::class, 'asset_status_badge' ) ) {
            return Helpers::asset_status_badge( $status_key );
        }
        $label = ucfirst( str_replace( '_', ' ', $status_key ) );
        return '<span class="sfs-hr-asset-status-pill">' . esc_html( $label ) . '</span>';
    };

    // Helper for field rows in overview.
    $print_field = static function ( string $label, string $value ): void {
        if ( $value === '' ) {
            return;
        }
        echo '<div class="sfs-hr-field-row">';
        echo '<div class="sfs-hr-field-label">' . esc_html( $label ) . '</div>';
        echo '<div class="sfs-hr-field-value">' . esc_html( $value ) . '</div>';
        echo '</div>';
    };

    // Helper for asset field rows (cards).
    $asset_field_fn = static function ( string $label, ?string $value ): void {
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return;
        }
        echo '<div class="sfs-hr-asset-field-row">';
        echo '<div class="sfs-hr-asset-field-label">' . esc_html( $label ) . '</div>';
        echo '<div class="sfs-hr-asset-field-value">' . esc_html( $value ) . '</div>';
        echo '</div>';
    };

    ob_start();
    ?>
    <div class="sfs-hr sfs-hr-profile sfs-hr-profile--frontend">
        <h3><?php echo esc_html__( 'My HR Profile', 'sfs-hr' ); ?></h3>

                <div class="sfs-hr-profile-tabs">
            <a href="<?php echo esc_url( $overview_url ); ?>"
               class="sfs-hr-tab <?php echo ( $active_tab === 'overview' ) ? 'sfs-hr-tab-active' : ''; ?>">
                <?php esc_html_e( 'Overview', 'sfs-hr' ); ?>
            </a>
            <a href="<?php echo esc_url( $leave_url ); ?>"
               class="sfs-hr-tab <?php echo ( $active_tab === 'leave' ) ? 'sfs-hr-tab-active' : ''; ?>">
                <?php esc_html_e( 'Leave', 'sfs-hr' ); ?>
            </a>

            <?php if ( $can_self_clock ) : ?>
                <a href="<?php echo esc_url( $attendance_url ); ?>"
                   class="sfs-hr-tab <?php echo ( $active_tab === 'attendance' ) ? 'sfs-hr-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Attendance', 'sfs-hr' ); ?>
                </a>
            <?php endif; ?>
        </div>


                <?php if ( $active_tab === 'leave' ) : ?>

            <?php $this->render_frontend_leave_tab( $emp ); ?>

        <?php elseif ( $active_tab === 'attendance' && $can_self_clock ) : ?>

            <div class="sfs-hr-profile-attendance-tab" style="margin-top:24px;">
                <?php
                // Full self-web widget, non-immersive so it stays inline.
                echo do_shortcode( '[sfs_hr_attendance_widget immersive="0"]' );
                ?>
            </div>

        <?php else : ?>

            <div class="sfs-hr-profile-header">
    <div class="sfs-hr-profile-photo">
        <?php
        if ( $photo_id ) {
            echo wp_get_attachment_image(
                $photo_id,
                [ 96, 96 ],
                false,
                [
                    'class' => 'sfs-hr-emp-photo',
                    'style' => 'border-radius:50%;display:block;object-fit:cover;width:96px;height:96px;',
                ]
            );
        } else {
            echo '<div class="sfs-hr-emp-photo sfs-hr-emp-photo--empty">'
                 . esc_html__( 'No photo', 'sfs-hr' ) .
                 '</div>';
        }
        ?>
    </div>

    <div class="sfs-hr-profile-header-main">
        <h4 class="sfs-hr-profile-name">
            <?php echo esc_html( $full_name ); ?>
        </h4>

        <div class="sfs-hr-profile-chips">
            <?php if ( $code !== '' ) : ?>
                <span class="sfs-hr-chip sfs-hr-chip--code">
                    <?php echo esc_html__( 'Code', 'sfs-hr' ); ?>: <?php echo esc_html( $code ); ?>
                </span>
            <?php endif; ?>

            <?php if ( $status !== '' ) : ?>
                <span class="sfs-hr-chip sfs-hr-chip--status">
                    <?php echo esc_html( ucfirst( $status ) ); ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="sfs-hr-profile-meta-line">
            <?php
            $meta_parts = [];
            if ( $position !== '' ) {
                $meta_parts[] = $position;
            }
            if ( $dept_name !== '' ) {
                $meta_parts[] = $dept_name;
            }
            echo esc_html( implode( ' · ', $meta_parts ) );
            ?>
        </div>

                <?php if ( ! empty( $can_self_clock ) ) : ?>
    <div class="sfs-hr-profile-actions">
        <a href="<?php echo esc_url( $attendance_url ); ?>"
           class="sfs-hr-att-btn"
           data-sfs-att-btn="1">
            <?php esc_html_e( 'Attendance', 'sfs-hr' ); ?>
        </a>
    </div>
<?php endif; ?>
    </div>
</div>


                    
                </div>


            <div class="sfs-hr-profile-grid">
                <div class="sfs-hr-profile-col">
                    <div class="sfs-hr-profile-group">
                        <div class="sfs-hr-profile-group-title"><?php esc_html_e( 'Employment', 'sfs-hr' ); ?></div>
                        <div class="sfs-hr-profile-group-body">
                            <?php
                            $print_field( __( 'Status', 'sfs-hr' ),      $status );
                            $print_field( __( 'Gender', 'sfs-hr' ),      $gender );
                            $print_field( __( 'Department', 'sfs-hr' ),  $dept_name );
                            $print_field( __( 'Position', 'sfs-hr' ),    $position );
                            $print_field( __( 'Hire Date', 'sfs-hr' ),   $hire_date );
                            $print_field( __( 'Employee ID', 'sfs-hr' ), (string) $emp_id );
                            if ( $wp_username !== '' ) {
                                $print_field( __( 'WP Username', 'sfs-hr' ), $wp_username );
                            }
                            ?>
                        </div>
                    </div>

                    <div class="sfs-hr-profile-group">
                        <div class="sfs-hr-profile-group-title"><?php esc_html_e( 'Contact', 'sfs-hr' ); ?></div>
                        <div class="sfs-hr-profile-group-body">
                            <?php
                            $print_field( __( 'Email', 'sfs-hr' ), $email );
                            $print_field( __( 'Phone', 'sfs-hr' ), $phone );
                            $print_field(
                                __( 'Emergency contact', 'sfs-hr' ),
                                trim( $emg_name . ( $emg_phone ? ' / ' . $emg_phone : '' ) )
                            );
                            ?>
                        </div>
                    </div>
                </div>

                <div class="sfs-hr-profile-col">
                    <div class="sfs-hr-profile-group">
                        <div class="sfs-hr-profile-group-title"><?php esc_html_e( 'Identification', 'sfs-hr' ); ?></div>
                        <div class="sfs-hr-profile-group-body">
                            <?php
                            $print_field( __( 'National ID', 'sfs-hr' ),        $national_id );
                            $print_field( __( 'National ID Expiry', 'sfs-hr' ), $nid_exp );
                            $print_field( __( 'Passport No.', 'sfs-hr' ),       $passport_no );
                            $print_field( __( 'Passport Expiry', 'sfs-hr' ),    $pass_exp );
                            ?>
                        </div>
                    </div>

                    <div class="sfs-hr-profile-group">
                        <div class="sfs-hr-profile-group-title"><?php esc_html_e( 'Payroll', 'sfs-hr' ); ?></div>
                        <div class="sfs-hr-profile-group-body">
                            <?php
                            $print_field( __( 'Base salary', 'sfs-hr' ), $base_salary );
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ( ! empty( $asset_rows ) ) : ?>
                <div class="sfs-hr-my-assets-frontend">
                    <h4><?php echo esc_html__( 'My Assets', 'sfs-hr' ); ?></h4>

                    <!-- Desktop: table view -->
                    <div class="sfs-hr-assets-desktop">
                        <table class="sfs-hr-table sfs-hr-assets-table">
                            <thead>
                                <tr>
                                    <th><?php echo esc_html__( 'Asset', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'Code', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'Category', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'Start', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'End', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'Status', 'sfs-hr' ); ?></th>
                                    <th><?php echo esc_html__( 'Actions', 'sfs-hr' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $asset_rows as $row ) : ?>
                                <?php
                                $assignment_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
                                $row_status    = (string) ( $row['status'] ?? '' );
                                $asset_id      = isset( $row['asset_id'] ) ? (int) $row['asset_id'] : 0;
                                $asset_name    = (string) ( $row['asset_name'] ?? '' );
                                $asset_code    = (string) ( $row['asset_code'] ?? '' );
                                $category      = (string) ( $row['category']   ?? '' );
                                $start_date    = (string) ( $row['start_date'] ?? '' );
                                $end_date      = (string) ( $row['end_date']   ?? '' );

                                $title = $asset_name !== '' ? $asset_name : $asset_code;
                                if ( $title === '' ) {
                                    $title = sprintf( __( 'Asset #%d', 'sfs-hr' ), $assignment_id );
                                }

                                $title_html = esc_html( $title );
                                if ( $asset_id && ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr_assets_admin' ) ) ) {
                                    $edit_url   = add_query_arg(
                                        [
                                            'page' => 'sfs-hr-assets',
                                            'tab'  => 'assets',
                                            'view' => 'edit',
                                            'id'   => $asset_id,
                                        ],
                                        admin_url( 'admin.php' )
                                    );
                                    $title_html = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a>';
                                }

                                $status_html = $asset_status_badge_fn( $row_status );
                                ?>
                                <tr data-assignment-id="<?php echo (int) $assignment_id; ?>">
                                    <td><?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                    <td><?php echo esc_html( $asset_code ); ?></td>
                                    <td><?php echo esc_html( $category ); ?></td>
                                    <td><?php echo esc_html( $start_date ); ?></td>
                                    <td><?php echo esc_html( $end_date !== '' ? $end_date : '—' ); ?></td>
                                    <td><?php echo $status_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                    <td class="sfs-hr-asset-actions">
                                        <?php if ( $assignment_id && $row_status === 'pending_employee_approval' ) : ?>
                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form"
                                                  data-requires-photos="1">
                                                <?php wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="approve" />
                                                <button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">
                                                    <?php esc_html_e( 'Approve', 'sfs-hr' ); ?>
                                                </button>
                                            </form>

                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form">
                                                <?php wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="reject" />
                                                <button type="submit" class="sfs-hr-asset-btn sfs-hr-asset-btn--reject">
                                                    <?php esc_html_e( 'Reject', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ( $assignment_id && $row_status === 'return_requested' ) : ?>
                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form"
                                                  data-requires-photos="1">
                                                <?php wp_nonce_field( 'sfs_hr_assets_return_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_return_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="approve" />
                                                <button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">
                                                    <?php esc_html_e( 'Confirm Return', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile: collapsible cards -->
                    <div class="sfs-hr-assets-mobile">
                        <?php
                        foreach ( $asset_rows as $row ) :
                            $assignment_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
                            $row_status    = (string) ( $row['status'] ?? '' );
                            $asset_id      = isset( $row['asset_id'] ) ? (int) $row['asset_id'] : 0;
                            $asset_name    = (string) ( $row['asset_name'] ?? '' );
                            $asset_code    = (string) ( $row['asset_code'] ?? '' );
                            $category      = (string) ( $row['category']   ?? '' );
                            $start_date    = (string) ( $row['start_date'] ?? '' );
                            $end_date      = (string) ( $row['end_date']   ?? '' );

                            $title = $asset_name !== '' ? $asset_name : $asset_code;
                            if ( $title === '' ) {
                                $title = sprintf( __( 'Asset #%d', 'sfs-hr' ), $assignment_id );
                            }

                            $title_html = esc_html( $title );
                            if ( $asset_id && ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr_assets_admin' ) ) ) {
                                $edit_url   = add_query_arg(
                                    [
                                        'page' => 'sfs-hr-assets',
                                        'tab'  => 'assets',
                                        'view' => 'edit',
                                        'id'   => $asset_id,
                                    ],
                                    admin_url( 'admin.php' )
                                );
                                $title_html = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a>';
                            }

                            $status_html = $asset_status_badge_fn( $row_status );
                            ?>
                            <details class="sfs-hr-asset-card">
                                <summary class="sfs-hr-asset-summary">
                                    <span class="sfs-hr-asset-summary-title">
                                        <?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                    <span class="sfs-hr-asset-summary-status">
                                        <?php echo $status_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </span>
                                </summary>

                                <div class="sfs-hr-asset-body">
                                    <div class="sfs-hr-asset-fields">
                                        <?php
                                        $asset_field_fn( __( 'Code', 'sfs-hr' ), $asset_code );
                                        $asset_field_fn( __( 'Category', 'sfs-hr' ), $category );
                                        $asset_field_fn( __( 'Start', 'sfs-hr' ), $start_date );
                                        $asset_field_fn( __( 'End', 'sfs-hr' ), $end_date !== '' ? $end_date : '—' );
                                        ?>
                                    </div>

                                    <div class="sfs-hr-asset-actions">
                                        <?php if ( $assignment_id && $row_status === 'pending_employee_approval' ) : ?>
                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form"
                                                  data-requires-photos="1">
                                                <?php wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="approve" />
                                                <button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">
                                                    <?php esc_html_e( 'Approve', 'sfs-hr' ); ?>
                                                </button>
                                            </form>

                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form">
                                                <?php wp_nonce_field( 'sfs_hr_assets_assign_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_assign_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="reject" />
                                                <button type="submit" class="sfs-hr-asset-btn sfs-hr-asset-btn--reject">
                                                    <?php esc_html_e( 'Reject', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ( $assignment_id && $row_status === 'return_requested' ) : ?>
                                            <form method="post"
                                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                                  class="sfs-hr-asset-action-form"
                                                  data-requires-photos="1">
                                                <?php wp_nonce_field( 'sfs_hr_assets_return_decision_' . $assignment_id ); ?>
                                                <input type="hidden" name="action" value="sfs_hr_assets_return_decision" />
                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $assignment_id; ?>" />
                                                <input type="hidden" name="decision" value="approve" />
                                                <button type="button" class="sfs-hr-asset-btn sfs-hr-asset-btn--approve">
                                                    <?php esc_html_e( 'Confirm Return', 'sfs-hr' ); ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </details>
                            <?php
                        endforeach;
                        ?>
                    </div>

                </div>
            <?php endif; // assets ?>

            <?php
            // Attendance block (unchanged).
            if ( method_exists( $this, 'render_my_attendance_frontend' ) ) {
                $this->render_my_attendance_frontend( (int) $emp_id );
            }
            ?>

            <!-- Modal for selfie + asset capture -->
            <div id="sfs-hr-asset-photo-modal" class="sfs-hr-modal" style="display:none;">
                <div class="sfs-hr-modal-backdrop"></div>
                <div class="sfs-hr-modal-dialog">
                    <div class="sfs-hr-modal-header">
                        <h4 class="sfs-hr-modal-title"><?php esc_html_e( 'Verify Asset Handover', 'sfs-hr' ); ?></h4>
                        <button type="button" class="sfs-hr-modal-close" aria-label="<?php esc_attr_e( 'Close', 'sfs-hr' ); ?>">×</button>
                    </div>
                    <div class="sfs-hr-modal-body">
                        <p class="sfs-hr-modal-step-title">
                            <?php esc_html_e( 'Step 1 of 2: Take a selfie', 'sfs-hr' ); ?>
                        </p>
                        <div class="sfs-hr-modal-camera">
                            <video autoplay playsinline class="sfs-hr-modal-video"></video>
                            <canvas class="sfs-hr-modal-canvas" style="display:none;"></canvas>
                        </div>
                        <div class="sfs-hr-modal-previews">
                            <div class="sfs-hr-modal-preview-block">
                                <span class="sfs-hr-modal-preview-label"><?php esc_html_e( 'Selfie', 'sfs-hr' ); ?></span>
                                <img class="sfs-hr-preview-selfie" alt="" style="display:none;"/>
                            </div>
                            <div class="sfs-hr-modal-preview-block">
                                <span class="sfs-hr-modal-preview-label"><?php esc_html_e( 'Asset', 'sfs-hr' ); ?></span>
                                <img class="sfs-hr-preview-asset" alt="" style="display:none;"/>
                            </div>
                        </div>
                    </div>
                    <div class="sfs-hr-modal-footer">
                        <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-cancel-btn">
                            <?php esc_html_e( 'Cancel', 'sfs-hr' ); ?>
                        </button>
                        <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-back-btn" style="display:none;">
                            <?php esc_html_e( 'Back', 'sfs-hr' ); ?>
                        </button>
                        <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-capture-btn">
                            <?php esc_html_e( 'Capture', 'sfs-hr' ); ?>
                        </button>
                        <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-next-btn" style="display:none;" disabled>
                            <?php esc_html_e( 'Next', 'sfs-hr' ); ?>
                        </button>
                        <button type="button" class="sfs-hr-modal-btn sfs-hr-modal-done-btn" style="display:none;" disabled>
                            <?php esc_html_e( 'Done & Submit', 'sfs-hr' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <script>
            (function() {
                document.addEventListener('DOMContentLoaded', function() {
                    var modal = document.getElementById('sfs-hr-asset-photo-modal');
                    if (!modal) { return; }

                    // On desktop, we keep cards hidden anyway; this just ensures they're open if revealed.
                    if (window.matchMedia && window.matchMedia('(min-width: 768px)').matches) {
                        document.querySelectorAll('.sfs-hr-asset-card').forEach(function(d) {
                            d.setAttribute('open', 'open');
                        });
                    }

                    var backdrop     = modal.querySelector('.sfs-hr-modal-backdrop');
                    var closeBtn     = modal.querySelector('.sfs-hr-modal-close');
                    var cancelBtn    = modal.querySelector('.sfs-hr-modal-cancel-btn');
                    var backBtn      = modal.querySelector('.sfs-hr-modal-back-btn');
                    var captureBtn   = modal.querySelector('.sfs-hr-modal-capture-btn');
                    var nextBtn      = modal.querySelector('.sfs-hr-modal-next-btn');
                    var doneBtn      = modal.querySelector('.sfs-hr-modal-done-btn');
                    var stepTitle    = modal.querySelector('.sfs-hr-modal-step-title');
                    var video        = modal.querySelector('.sfs-hr-modal-video');
                    var canvas       = modal.querySelector('.sfs-hr-modal-canvas');
                    var previewSelf  = modal.querySelector('.sfs-hr-preview-selfie');
                    var previewAsset = modal.querySelector('.sfs-hr-preview-asset');

                    var currentForm  = null;
                    var currentStep  = 1; // 1 = selfie, 2 = asset
                    var stream       = null;
                    var selfieData   = '';
                    var assetData    = '';

                    function updateStepUI() {
                        if (currentStep === 1) {
                            stepTitle.textContent = <?php echo json_encode( __( 'Step 1 of 2: Take a selfie', 'sfs-hr' ) ); ?>;
                            if (backBtn)  backBtn.style.display = 'none';
                            if (captureBtn) captureBtn.style.display = 'inline-block';
                            if (nextBtn) {
                                nextBtn.style.display = 'inline-block';
                                nextBtn.disabled = !selfieData;
                            }
                            if (doneBtn) {
                                doneBtn.style.display = 'none';
                                doneBtn.disabled = true;
                            }
                        } else {
                            stepTitle.textContent = <?php echo json_encode( __( 'Step 2 of 2: Take photo of asset', 'sfs-hr' ) ); ?>;
                            if (backBtn)  backBtn.style.display = 'inline-block';
                            if (captureBtn) captureBtn.style.display = 'inline-block';
                            if (nextBtn)   nextBtn.style.display = 'none';
                            if (doneBtn) {
                                doneBtn.style.display = 'inline-block';
                                doneBtn.disabled = !assetData;
                            }
                        }
                    }

                    function stopStream() {
                        if (stream) {
                            try { stream.getTracks().forEach(function(t){ t.stop(); }); } catch(e) {}
                        }
                        stream = null;
                        if (video) {
                            video.srcObject = null;
                        }
                    }

                    function startStreamForStep(step) {
                        stopStream();

                        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !video) {
                            alert('<?php echo esc_js( __( 'Camera API not supported in this browser.', 'sfs-hr' ) ); ?>');
                            return;
                        }

                        var primaryConstraints;
                        if (step === 1) {
                            primaryConstraints = { video: { facingMode: 'user' } };
                        } else {
                            primaryConstraints = { video: { facingMode: { ideal: 'environment' } } };
                        }

                        function startStreamSuccess(s) {
                            stream = s;
                            try {
                                video.srcObject = s;
                                var playPromise = video.play();
                                if (playPromise && playPromise.catch) {
                                    playPromise.catch(function() {});
                                }
                            } catch (e) {}
                        }

                        navigator.mediaDevices.getUserMedia(primaryConstraints)
                            .then(startStreamSuccess)
                            .catch(function() {
                                navigator.mediaDevices.getUserMedia({ video: true })
                                    .then(startStreamSuccess)
                                    .catch(function() {
                                        alert('<?php echo esc_js( __( 'Unable to access the camera. Please allow camera permissions.', 'sfs-hr' ) ); ?>');
                                    });
                            });
                    }

                    function openModal(form) {
                        currentForm = form;
                        currentStep = 1;
                        selfieData  = '';
                        assetData   = '';

                        if (previewSelf) {
                            previewSelf.style.display = 'none';
                            previewSelf.src = '';
                        }
                        if (previewAsset) {
                            previewAsset.style.display = 'none';
                            previewAsset.src = '';
                        }

                        modal.style.display = 'block';
                        updateStepUI();
                        startStreamForStep(1);
                    }

                    function closeModal() {
                        stopStream();
                        modal.style.display = 'none';
                        currentForm = null;
                        currentStep = 1;
                        selfieData  = '';
                        assetData   = '';
                    }

                    function captureCurrent() {
                        if (!video || !canvas) { return; }
                        var w = video.videoWidth || 640;
                        var h = video.videoHeight || 480;
                        if (!w || !h) { return; }
                        canvas.width  = w;
                        canvas.height = h;
                        var ctx = canvas.getContext('2d');
                        ctx.drawImage(video, 0, 0, w, h);
                        var dataUrl = canvas.toDataURL('image/jpeg', 0.8);

                        if (currentStep === 1) {
                            selfieData = dataUrl;
                            if (previewSelf) {
                                previewSelf.src = dataUrl;
                                previewSelf.style.display = 'block';
                            }
                        } else {
                            assetData = dataUrl;
                            if (previewAsset) {
                                previewAsset.src = dataUrl;
                                previewAsset.style.display = 'block';
                            }
                        }
                        updateStepUI();
                    }

                    function submitWithPhotos() {
                        if (!currentForm) {
                            return;
                        }

                        var selfieInput = currentForm.querySelector('input[name="selfie_data"]');
                        if (!selfieInput) {
                            selfieInput = document.createElement('input');
                            selfieInput.type = 'hidden';
                            selfieInput.name = 'selfie_data';
                            currentForm.appendChild(selfieInput);
                        }
                        selfieInput.value = selfieData || '';

                        var assetInput = currentForm.querySelector('input[name="asset_data"]');
                        if (!assetInput) {
                            assetInput = document.createElement('input');
                            assetInput.type = 'hidden';
                            assetInput.name = 'asset_data';
                            currentForm.appendChild(assetInput);
                        }
                        assetInput.value = assetData || '';

                        currentForm.submit();
                        closeModal();
                    }

                    if (captureBtn) {
                        captureBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            captureCurrent();
                        });
                    }

                    if (nextBtn) {
                        nextBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            if (!selfieData) { return; }
                            currentStep = 2;
                            updateStepUI();
                            startStreamForStep(2);
                        });
                    }

                    if (backBtn) {
                        backBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            currentStep = 1;
                            updateStepUI();
                            startStreamForStep(1);
                        });
                    }

                    if (doneBtn) {
                        doneBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            submitWithPhotos();
                        });
                    }

                    if (cancelBtn) {
                        cancelBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            closeModal();
                        });
                    }

                    if (closeBtn) {
                        closeBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            closeModal();
                        });
                    }

                    if (backdrop) {
                        backdrop.addEventListener('click', function(e) {
                            e.preventDefault();
                            closeModal();
                        });
                    }

                    // Attach to Approve / Confirm Return buttons that require photos.
                    var container = document.querySelector('.sfs-hr-my-assets-frontend');
                    if (!container) { return; }

                    container.addEventListener('click', function(e) {
                        var target = e.target;
                        if (!target || !target.classList || !target.classList.contains('sfs-hr-asset-btn--approve')) {
                            return;
                        }
                        var form = target.closest('form');
                        if (!form) { return; }
                        if (form.getAttribute('data-requires-photos') !== '1') {
                            return;
                        }
                        e.preventDefault();
                        openModal(form);
                    });
                });
            })();
            </script>
               <script>
(function () {
    'use strict';
    
    function updateAttendanceButton() {
        var btn = document.querySelector('.sfs-hr-att-btn[data-sfs-att-btn="1"]');
        if (!btn) {
            console.warn('Attendance button not found');
            return;
        }

        var statusUrl = '<?php echo esc_js( rest_url( 'sfs-hr/v1/attendance/status' ) ); ?>' + '?dbg=1';
        var nonce = '<?php echo wp_create_nonce( 'wp_rest' ); ?>';

        // Add a loading indicator
        btn.textContent = '<?php echo esc_js( __( 'Loading...', 'sfs-hr' ) ); ?>';

        fetch(statusUrl, {
            credentials: 'same-origin',
            headers: {
                'Cache-Control': 'no-cache',
                'X-WP-Nonce': nonce
            }
        })
            .then(function (res) { 
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function (data) {
                if (!data) {
                    console.warn('No data returned from status endpoint');
                    btn.textContent = '<?php echo esc_js( __( 'Attendance', 'sfs-hr' ) ); ?>';
                    return;
                }

                console.log('Attendance status:', data); // Debug log
                if (data._debug) {
                    console.log('DEBUG INFO:', data._debug);
                }

                // Support both { allow: {...} } and flat { in: true, ... }
                var allow = data.allow || data;
                console.log('Allow object:', allow);
                console.log('State:', data.state, 'Label:', data.label);

                var label = '';

                // Check what actions are allowed - priority order matters
                if (allow.in) {
                    label = '<?php echo esc_js( __( 'Clock In', 'sfs-hr' ) ); ?>';
                } else if (allow.break_start) {
                    label = '<?php echo esc_js( __( 'Start Break', 'sfs-hr' ) ); ?>';
                } else if (allow.break_end) {
                    label = '<?php echo esc_js( __( 'End Break', 'sfs-hr' ) ); ?>';
                } else if (allow.out) {
                    label = '<?php echo esc_js( __( 'Clock Out', 'sfs-hr' ) ); ?>';
                }

                // Update button text or fallback to default
                if (label) {
                    btn.textContent = label;
                    console.log('Button updated to:', label); // Debug log
                } else {
                    btn.textContent = '<?php echo esc_js( __( 'Attendance', 'sfs-hr' ) ); ?>';
                    console.log('No specific action available, using default'); // Debug log
                }
            })
            .catch(function (err) {
                console.error('Attendance status fetch failed:', err);
                // Restore default text on error
                btn.textContent = '<?php echo esc_js( __( 'Attendance', 'sfs-hr' ) ); ?>';
            });
    }

    // Run after DOM is fully loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateAttendanceButton);
    } else {
        updateAttendanceButton();
    }
})();
</script>




        <?php endif; // overview/leave ?>

    </div>

    <style>
    .sfs-hr-profile-header {
        display:flex;
        align-items:center;
        gap:16px;
        margin:0 0 16px;
    }
    .sfs-hr-emp-photo {
        width:96px;
        height:96px;
        border-radius:50%;
        object-fit:cover;
    }
    .sfs-hr-emp-photo--empty {
        width:96px;
        height:96px;
        border-radius:50%;
        background:#f3f4f5;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:12px;
        color:#666;
    }
    .sfs-hr-profile-name {
        margin:0 0 4px;
        font-size:20px;
    }
    .sfs-hr-profile-chips {
        margin:0 0 6px;
    }
    .sfs-hr-chip {
        display:inline-block;
        border-radius:999px;
        padding:2px 10px;
        font-size:11px;
        margin-right:6px;
    }
    .sfs-hr-chip--code {
        background:#f1f1f1;
    }
    .sfs-hr-chip--status {
        background:#e5f5ff;
    }
    .sfs-hr-profile-meta-line {
        font-size:13px;
        color:#555;
    }

    .sfs-hr-profile-actions {
        margin-top:8px;
    }

    .sfs-hr-att-btn {
        display:inline-flex;
        align-items:center;
        padding:6px 14px;
        border-radius:999px;
        border:1px solid #2563eb;
        background:#2563eb;
        color:#ffffff;
        font-size:13px;
        font-weight:500;
        text-decoration:none;
        cursor:pointer;
        transition:
            background .15s ease,
            border-color .15s ease,
            box-shadow .15s ease,
            transform .05s ease;
    }

    .sfs-hr-att-btn:hover {
        background:#1d4ed8;
        border-color:#1d4ed8;
        box-shadow:0 4px 10px rgba(37,99,235,0.25);
        color:#ffffff;
        text-decoration:none;
        transform:translateY(-1px);
    }

    .sfs-hr-att-btn:active {
        transform:translateY(0);
        box-shadow:none;
    }

    /* Tabs */
    .sfs-hr-profile-tabs {
        margin:8px 0 16px;
        border-bottom:1px solid #eee;
    }
    .sfs-hr-tab {
        display:inline-block;
        padding:6px 12px;
        margin-right:4px;
        text-decoration:none;
        border-radius:4px 4px 0 0;
        border:1px solid transparent;
        border-bottom:none;
        background:#f3f4f6;
        color:#6b7280;
    }
    .sfs-hr-tab:hover {
        background:#e5e7eb;
        color:#111827;
    }
    .sfs-hr-tab-active {
        background:#ffffff;
        color:#111827;
        border-color:#2563eb #2563eb #fff;
        font-weight:600;
    }

    /* Overview grid layout */
    .sfs-hr-profile-grid {
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));
        gap:16px;
        margin-bottom:24px;
    }
    .sfs-hr-profile-group {
        background:#fff;
        border:1px solid #e5e7eb;
        border-radius:8px;
        padding:12px 14px;
        margin-bottom:12px;
    }
    .sfs-hr-profile-col .sfs-hr-profile-group:last-child {
        margin-bottom:0;
    }
    .sfs-hr-profile-group-title {
        font-size:13px;
        font-weight:600;
        margin-bottom:8px;
        text-transform:uppercase;
        letter-spacing:.03em;
        color:#4b5563;
    }
    .sfs-hr-field-row {
        display:flex;
        justify-content:space-between;
        gap:8px;
        padding:4px 0;
        font-size:13px;
        border-bottom:1px dashed #f1f1f1;
    }
    .sfs-hr-field-row:last-child {
        border-bottom:none;
    }
    .sfs-hr-field-label {
        color:#6b7280;
        flex:0 0 45%;
    }
    .sfs-hr-field-value {
        text-align:right;
        flex:1;
        color:#111827;
    }

    .sfs-hr-table {
        width:100%;
        border-collapse:collapse;
    }
    .sfs-hr-table th,
    .sfs-hr-table td {
        padding:8px 10px;
        border-bottom:1px solid #eee;
        text-align:left;
        font-size:13px;
    }

    .sfs-hr-alert {
        padding:12px;
        background:#fef3c7;
        border:1px solid:#fde68a;
        border-radius:8px;
        margin-bottom:10px;
    }

    /* My Assets wrapper */
    .sfs-hr-my-assets-frontend {
        border:1px solid #e5e7eb;
        border-radius:8px;
        padding:16px 18px 18px;
        margin-top:24px;
        margin-bottom:24px;
        background:#ffffff;
    }
    .sfs-hr-my-assets-frontend h4 {
        margin:0 0 12px;
    }

    .sfs-hr-assets-desktop { display:block; }
    .sfs-hr-assets-mobile  { display:none; }

    .sfs-hr-assets-desktop .sfs-hr-asset-actions {
        white-space:nowrap;
    }

    /* Collapsible cards (mobile block) */
    .sfs-hr-assets-mobile .sfs-hr-asset-card {
        border-top:1px solid #f3f4f6;
        padding:6px 0;
    }
    .sfs-hr-assets-mobile .sfs-hr-asset-card:first-of-type {
        border-top:none;
    }
    .sfs-hr-asset-summary {
        display:flex;
        align-items:center;
        gap:8px;
        cursor:pointer;
        padding:6px 0;
        list-style:none;
    }
    .sfs-hr-asset-summary::-webkit-details-marker { display:none; }
    .sfs-hr-asset-summary-title {
        flex:1;
        min-width:0;
    }
    .sfs-hr-asset-summary-status {
        margin-left:auto;
    }
    .sfs-hr-asset-summary::after {
        content:"›";
        font-size:14px;
        transform:rotate(90deg);
        opacity:0.4;
        margin-left:4px;
    }
    .sfs-hr-asset-card[open] .sfs-hr-asset-summary::after {
        transform:rotate(-90deg);
    }
    .sfs-hr-asset-summary-title a {
        text-decoration:none;
    }
    .sfs-hr-asset-body {
        padding:4px 0 8px;
        border-top:1px dashed #e5e7eb;
        margin-top:4px;
    }
    .sfs-hr-asset-fields {
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));
        gap:4px 16px;
        font-size:12px;
    }
    .sfs-hr-asset-field-row {
        display:flex;
        justify-content:space-between;
        gap:6px;
    }
    .sfs-hr-asset-field-label {
        color:#6b7280;
    }
    .sfs-hr-asset-field-value {
        text-align:right;
        color:#111827;
        font-weight:500;
    }
    .sfs-hr-asset-status-pill {
        display:inline-block;
        padding:1px 8px;
        border-radius:999px;
        border:1px solid #e5e7eb;
        background:#f3f4f6;
        color:#374151;
        font-size:11px;
        white-space:nowrap;
    }

    /* Asset actions buttons */
    .sfs-hr-asset-actions {
        margin-top:8px;
        display:flex;
        flex-wrap:wrap;
        gap:6px;
    }
    .sfs-hr-asset-action-form { margin:0; }

    .sfs-hr-asset-btn {
        appearance:none;
        border-radius:999px;
        border:1px solid #e5e7eb;
        background:#ffffff;
        padding:4px 14px;
        min-width:92px;
        font-size:11px;
        line-height:1.4;
        cursor:pointer;
        color:#111827;
        text-align:center;
        transition:background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .sfs-hr-asset-btn--approve {
        border-color:#16a34a;
        background:#ecfdf3;
        color:#166534;
    }
    .sfs-hr-asset-btn--approve:hover {
        background:#bbf7d0;
        border-color:#16a34a;
        box-shadow:0 0 0 1px rgba(22,163,74,0.15);
    }
    .sfs-hr-asset-btn--reject {
        border-color:#e5e7eb;
        background:#fef2f2;
        color:#b91c1c;
    }
    .sfs-hr-asset-btn--reject:hover {
        background:#fee2e2;
        border-color:#fecaca;
        box-shadow:0 0 0 1px rgba(248,113,113,0.25);
    }

    @media (max-width:640px) {
        .sfs-hr-assets-desktop { display:none; }
        .sfs-hr-assets-mobile  { display:block; }
        .sfs-hr-asset-fields   { grid-template-columns:1fr; }
        .sfs-hr-asset-summary  { align-items:flex-start; }
        .sfs-hr-asset-btn {
            min-width:86px;
            padding:3px 10px;
            font-size:10px;
        }
    }

    /* Attendance card */
    .sfs-hr-my-attendance-frontend {
        border:1px solid #e5e7eb;
        border-radius:8px;
        padding:16px 18px 18px;
        margin-top:24px;
        margin-bottom:24px;
        background:#ffffff;
    }
    .sfs-hr-my-attendance-frontend h4 {
        margin:0 0 12px;
    }

    /* Modal */
    .sfs-hr-modal {
        position:fixed;
        inset:0;
        z-index:9999;
    }
    .sfs-hr-modal-backdrop {
        position:absolute;
        inset:0;
        background:rgba(15,23,42,0.45);
    }
    .sfs-hr-modal-dialog {
        position:relative;
        max-width:480px;
        margin:40px auto;
        background:#ffffff;
        border-radius:12px;
        box-shadow:0 20px 40px rgba(15,23,42,0.4);
        display:flex;
        flex-direction:column;
        overflow:hidden;
    }
    .sfs-hr-modal-header {
        padding:10px 14px;
        border-bottom:1px solid #e5e7eb;
        display:flex;
        align-items:center;
        justify-content:space-between;
    }
    .sfs-hr-modal-title {
        margin:0;
        font-size:16px;
        font-weight:600;
    }
    .sfs-hr-modal-close {
        border:none;
        background:transparent;
        font-size:18px;
        line-height:1;
        cursor:pointer;
    }
    .sfs-hr-modal-body {
        padding:12px 14px 10px;
    }
    .sfs-hr-modal-step-title {
        margin:0 0 8px;
        font-size:13px;
        color:#4b5563;
    }
    .sfs-hr-modal-camera {
        background:#000;
        border-radius:8px;
        overflow:hidden;
        max-height:260px;
        display:flex;
        align-items:center;
        justify-content:center;
        margin-bottom:10px;
    }
    .sfs-hr-modal-video {
        width:100%;
        max-height:260px;
        object-fit:cover;
    }
    .sfs-hr-modal-previews {
        display:flex;
        gap:8px;
        margin-top:4px;
    }
    .sfs-hr-modal-preview-block {
        flex:1;
        border:1px dashed #e5e7eb;
        border-radius:8px;
        padding:4px;
        text-align:center;
    }
    .sfs-hr-modal-preview-label {
        display:block;
        font-size:11px;
        color:#6b7280;
        margin-bottom:4px;
    }
    .sfs-hr-modal-preview-block img {
        max-width:100%;
        max-height:80px;
        border-radius:6px;
    }
    .sfs-hr-modal-footer {
        padding:10px 14px;
        border-top:1px solid #e5e7eb;
        text-align:right;
    }
    .sfs-hr-modal-btn {
        display:inline-block;
        margin-left:6px;
        padding:4px 10px;
        font-size:12px;
        border-radius:999px;
        border:1px solid #e5e7eb;
        background:#f9fafb;
        cursor:pointer;
        color:#111827;
        transition:background-color .15s ease, border-color .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .sfs-hr-modal-btn:hover:not(:disabled) {
        background:#e5e7eb;
    }
    .sfs-hr-modal-done-btn,
    .sfs-hr-modal-next-btn {
        background:#2563eb;
        border-color:#2563eb;
        color:#ffffff;
    }
    .sfs-hr-modal-done-btn:hover:not(:disabled),
    .sfs-hr-modal-next-btn:hover:not(:disabled) {
        background:#1d4ed8;
        border-color:#1d4ed8;
    }
    .sfs-hr-modal-cancel-btn {
        background:#ffffff;
    }
    .sfs-hr-modal-btn:disabled,
    .sfs-hr-modal-done-btn:disabled,
    .sfs-hr-modal-next-btn:disabled {
        background:#f3f4f6;
        border-color:#e5e7eb;
        color:#9ca3af;
        opacity:1;
        cursor:not-allowed;
        box-shadow:none;
    }
    
    
    /* Leave tab block */
.sfs-hr-my-profile-leave {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px 18px 18px;
    margin-top: 24px;
    margin-bottom: 24px;
    background: #ffffff;
}
.sfs-hr-my-profile-leave h4 {
    margin: 0 0 12px;
}
.sfs-hr-my-profile-leave .sfs-hr-leave-self-form p {
    margin-bottom: 10px;
}
.sfs-hr-my-profile-leave table.sfs-hr-leave-table th,
.sfs-hr-my-profile-leave table.sfs-hr-leave-table td {
    font-size: 12px;
}

/* Leave history: desktop vs mobile */
.sfs-hr-leaves-desktop {
    display: block;
}
.sfs-hr-leaves-mobile {
    display: none;
}

/* Mobile leave cards */
.sfs-hr-leave-card {
    border-top: 1px solid #f3f4f6;
    padding: 8px 12px;
}
.sfs-hr-leave-card:first-of-type {
    border-top: none;
}
.sfs-hr-leave-summary {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 6px 0;
    list-style: none;
}
.sfs-hr-leave-summary::-webkit-details-marker {
    display: none;
}
.sfs-hr-leave-summary-title {
    flex: 1;
    min-width: 0;
    font-weight: 500;
}
.sfs-hr-leave-summary-status {
    margin-left: auto;
}
.sfs-hr-leave-summary::after {
    content: "›";
    font-size: 14px;
    transform: rotate(90deg);
    opacity: 0.4;
    margin-left: 4px;
}
.sfs-hr-leave-card[open] .sfs-hr-leave-summary::after {
    transform: rotate(-90deg);
}

.sfs-hr-leave-body {
    padding: 4px 0 8px;
    border-top: 1px dashed #e5e7eb;
    margin-top: 4px;
}
.sfs-hr-leave-field-row {
    display: flex;
    justify-content: space-between;
    gap: 6px;
    font-size: 12px;
    margin: 2px 0;
}
.sfs-hr-leave-field-label {
    color: #6b7280;
}
.sfs-hr-leave-field-value {
    text-align: right;
    color: #111827;
    font-weight: 500;
}

/* Mobile breakpoint */
@media (max-width: 640px) {
    .sfs-hr-leaves-desktop {
        display: none;
    }
    .sfs-hr-leaves-mobile {
        display: block;
    }
}



    </style>

    <?php

    return (string) ob_get_clean();
}






/**
 * Frontend Leave tab:
 * - Self-service leave request form
 * - Read-only history
 *
 * @param array $emp Employee row from Helpers::get_employee_row().
 */
private function render_frontend_leave_tab( array $emp ): void {
    if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
        echo '<p>' . esc_html__( 'You can only view your own leave information.', 'sfs-hr' ) . '</p>';
        return;
    }

    global $wpdb;

    $employee_id = isset( $emp['id'] ) ? (int) $emp['id'] : 0;
    if ( $employee_id <= 0 ) {
        echo '<p>' . esc_html__( 'Employee record not found.', 'sfs-hr' ) . '</p>';
        return;
    }

    $req_table   = $wpdb->prefix . 'sfs_hr_leave_requests';
    $type_table  = $wpdb->prefix . 'sfs_hr_leave_types';
    $bal_table   = $wpdb->prefix . 'sfs_hr_leave_balances';

    $year  = (int) current_time( 'Y' );
    $today = current_time( 'Y-m-d' );

    // ===== Leave types for the form =====
    $types = $wpdb->get_results(
        "SELECT id, name 
         FROM {$type_table}
         WHERE active = 1
         ORDER BY name ASC"
    );

    // ===== Recent leave history =====
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT r.*, t.name AS type_name
             FROM {$req_table} r
             LEFT JOIN {$type_table} t ON t.id = r.type_id
             WHERE r.employee_id = %d
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT 100",
            $employee_id
        )
    );

    // ===================== Dashboard KPIs =====================

    // Requests (this year)
    $requests_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$req_table}
             WHERE employee_id = %d
               AND YEAR(start_date) = %d",
            $employee_id,
            $year
        )
    );

    // Balances for current year
    $balances = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT b.*, t.name, t.is_annual
             FROM {$bal_table} b
             JOIN {$type_table} t ON t.id = b.type_id
             WHERE b.employee_id = %d
               AND b.year = %d
             ORDER BY t.is_annual DESC, t.name ASC",
            $employee_id,
            $year
        ),
        ARRAY_A
    );

    $total_used       = 0;
    $annual_available = null;

    if ( ! empty( $balances ) ) {
        foreach ( $balances as $b ) {
            $closing = (int) ( $b['closing'] ?? 0 );
            $used    = (int) ( $b['used'] ?? 0 );
            $total_used += $used;

            if ( $annual_available === null && ! empty( $b['is_annual'] ) ) {
                $annual_available = $closing;
            }
        }
    }

    if ( $annual_available === null ) {
        $annual_available = 0;
    }

    // Pending requests count (this year)
    $pending_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$req_table}
             WHERE employee_id = %d
               AND status = 'pending'
               AND YEAR(start_date) = %d",
            $employee_id,
            $year
        )
    );

    // Next approved leave (nearest future approved)
    $next_leave_text = esc_html__( 'No upcoming leave.', 'sfs-hr' );

    $next_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT r.*
             FROM {$req_table} r
             WHERE r.employee_id = %d
               AND r.status = 'approved'
               AND r.start_date >= %s
             ORDER BY r.start_date ASC, r.id ASC
             LIMIT 1",
            $employee_id,
            $today
        )
    );

    if ( $next_row ) {
        $start  = $next_row->start_date ?: '';
        $end    = $next_row->end_date   ?: '';
        $period = $start;

        if ( $start && $end && $end !== $start ) {
            $period = $start . ' → ' . $end;
        }

        $days = isset( $next_row->days ) ? (int) $next_row->days : 0;
        if ( $days <= 0 && $start !== '' ) {
            if ( $end === '' || $end === $start ) {
                $days = 1;
            } else {
                $start_ts = strtotime( $start );
                $end_ts   = strtotime( $end );
                if ( $start_ts && $end_ts && $end_ts >= $start_ts ) {
                    $days = (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;
                }
            }
        }
        if ( $days < 1 ) {
            $days = 1;
        }

        $next_leave_text = sprintf(
            '%1$s · %2$d %3$s',
            esc_html( $period ),
            (int) $days,
            ( $days === 1 )
                ? esc_html__( 'day', 'sfs-hr' )
                : esc_html__( 'days', 'sfs-hr' )
        );
    }

    // ===================== Output =====================

    // Output CSS first, before any HTML
    ?>
    <style>
    .sfs-hr-leave-self-form-wrap {
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        background: #f9fafb;
        padding: 14px 16px;
        margin-bottom: 14px;
    }
    .sfs-hr-leave-form-fields {
        max-width: 520px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .sfs-hr-lf-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .sfs-hr-lf-label {
        font-size: 12px;
        font-weight: 500;
        color: #374151;
    }
    .sfs-hr-lf-hint {
        font-size: 11px;
        color: #6b7280;
    }
    .sfs-hr-lf-row {
        display: flex;
        gap: 12px;
    }
    .sfs-hr-lf-row .sfs-hr-lf-group {
        flex: 1;
    }

    .sfs-hr-leave-self-form select,
    .sfs-hr-leave-self-form input[type="date"],
    .sfs-hr-leave-self-form input[type="file"],
    .sfs-hr-leave-self-form textarea {
        width: 100%;
        max-width: 100%;
    }

    .sfs-hr-lf-actions {
        margin-top: 8px;
    }
    .sfs-hr-lf-submit {
        min-width: 180px;
    }

    @media (max-width: 600px) {
        .sfs-hr-lf-row {
            flex-direction: column;
        }
        .sfs-hr-lf-submit {
            width: 100%;
            text-align: center;
        }
    }

    .sfs-hr-my-profile-leave {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 16px 18px 18px;
        margin-top: 24px;
        margin-bottom: 24px;
        background: #ffffff;
    }
    .sfs-hr-my-profile-leave h4 {
        margin: 0 0 8px;
    }
    .sfs-hr-lw-sub {
        margin: 0 0 10px;
        font-size: 12px;
        color: #6b7280;
    }
    .sfs-hr-lw-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
        margin-bottom: 14px;
    }
    .sfs-hr-lw-kpi-card {
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid #e5e7eb;
        background: #f9fafb;
    }
    .sfs-hr-lw-kpi-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        color: #6b7280;
        margin-bottom: 4px;
    }
    .sfs-hr-lw-kpi-value {
        font-size: 18px;
        font-weight: 600;
        color: #111827;
    }
    .sfs-hr-lw-kpi-sub {
        font-size: 12px;
        font-weight: 400;
        color: #4b5563;
    }
    .sfs-hr-lw-kpi-next {
        font-size: 13px;
        font-weight: 500;
    }

    .sfs-hr-leaves-desktop { display:block; }
    .sfs-hr-leaves-mobile  { display:none; }

    .sfs-hr-leave-card {
        border-radius: 10px;
        border: 1px solid #e5e7eb;
        padding: 8px 10px;
        margin-bottom: 8px;
        background: #f9fafb;
    }
    .sfs-hr-leave-summary {
        display:flex;
        align-items:center;
        justify-content:space-between;
        cursor:pointer;
    }
    .sfs-hr-leave-summary-title {
        font-weight:500;
        font-size:13px;
    }
    .sfs-hr-leave-summary-status {
        font-size:12px;
    }
    .sfs-hr-leave-body {
        margin-top:6px;
        font-size:12px;
    }
    .sfs-hr-leave-field-row {
        display:flex;
        justify-content:space-between;
        margin-bottom:3px;
    }
    .sfs-hr-leave-field-label {
        color:#6b7280;
        margin-right:8px;
    }
    .sfs-hr-leave-field-value {
        font-weight:500;
        text-align:right;
    }

    @media (max-width: 768px) {
        .sfs-hr-leaves-desktop { display:none; }
        .sfs-hr-leaves-mobile  { display:block; }
    }
    </style>
    <?php

    echo '<div class="sfs-hr-my-profile-leave">';

    // Header + employee / year
    echo '<h4>' . esc_html__( 'My Leave Dashboard', 'sfs-hr' ) . '</h4>';
    echo '<p class="sfs-hr-lw-sub">';
    printf(
        esc_html__( 'Employee: %1$s %2$s · Year: %3$d', 'sfs-hr' ),
        esc_html( (string) ( $emp['first_name'] ?? '' ) ),
        esc_html( (string) ( $emp['last_name'] ?? '' ) ),
        $year
    );
    echo '</p>';

    // KPI cards row
    echo '<div class="sfs-hr-lw-kpis">';

    // Card 1: Requests
    echo '<div class="sfs-hr-lw-kpi-card">';
    echo '  <div class="sfs-hr-lw-kpi-label">' . esc_html__( 'Requests (this year)', 'sfs-hr' ) . '</div>';
    echo '  <div class="sfs-hr-lw-kpi-value">' . (int) $requests_count . '</div>';
    echo '</div>';

    // Card 2: Annual leave available
    echo '<div class="sfs-hr-lw-kpi-card">';
    echo '  <div class="sfs-hr-lw-kpi-label">' . esc_html__( 'Annual leave available', 'sfs-hr' ) . '</div>';
    echo '  <div class="sfs-hr-lw-kpi-value">' . (int) $annual_available . '</div>';
    echo '</div>';

    // Card 3: Total used + pending
    echo '<div class="sfs-hr-lw-kpi-card">';
    echo '  <div class="sfs-hr-lw-kpi-label">' . esc_html__( 'Total used (this year)', 'sfs-hr' ) . '</div>';
    echo '  <div class="sfs-hr-lw-kpi-value">';
    echo        (int) $total_used;
    if ( $pending_count > 0 ) {
        echo ' <span class="sfs-hr-lw-kpi-sub">· ' . sprintf(
            esc_html__( '%d pending', 'sfs-hr' ),
            (int) $pending_count
        ) . '</span>';
    }
    echo '  </div>';
    echo '</div>';

    // Card 4: Next approved leave
    echo '<div class="sfs-hr-lw-kpi-card">';
    echo '  <div class="sfs-hr-lw-kpi-label">' . esc_html__( 'Next approved leave', 'sfs-hr' ) . '</div>';
    echo '  <div class="sfs-hr-lw-kpi-value sfs-hr-lw-kpi-next">' . $next_leave_text . '</div>';
    echo '</div>';

    echo '</div>'; // .sfs-hr-lw-kpis

    // ====== Self-service request form ======
    echo '<div class="sfs-hr-leave-self-form-wrap" style="margin-top:16px;">';
    echo '<h5 style="margin:0 0 10px 0;">' . esc_html__( 'Request new leave', 'sfs-hr' ) . '</h5>';

    if ( empty( $types ) ) {
        echo '<p class="description">' . esc_html__( 'Leave types are not configured yet. Please contact HR.', 'sfs-hr' ) . '</p>';
        echo '</div>'; // form wrap
    } else {
            $action_url = admin_url( 'admin-post.php' );

    echo '<form method="post" action="' . esc_url( $action_url ) . '" class="sfs-hr-leave-self-form" enctype="multipart/form-data">';
    wp_nonce_field( 'sfs_hr_leave_request_self' );
    echo '<input type="hidden" name="action" value="sfs_hr_leave_request_self" />';
    echo '<input type="hidden" name="employee_id" value="' . (int) $employee_id . '" />';

    echo '<div class="sfs-hr-leave-form-fields">';

    // Leave type
    echo '<div class="sfs-hr-lf-group">';
    echo '  <div class="sfs-hr-lf-label">' . esc_html__( 'Leave type', 'sfs-hr' ) . '</div>';
    echo '  <select name="type_id" required>';
    echo '      <option value="">' . esc_html__( 'Select type', 'sfs-hr' ) . '</option>';
    foreach ( $types as $type ) {
        echo '      <option value="' . (int) $type->id . '">' . esc_html( $type->name ) . '</option>';
    }
    echo '  </select>';
    echo '</div>';

    // Dates row
    echo '<div class="sfs-hr-lf-row">';

    // Start date
    echo '  <div class="sfs-hr-lf-group">';
    echo '      <div class="sfs-hr-lf-label">' . esc_html__( 'Start date', 'sfs-hr' ) . '</div>';
    echo '      <input type="date" name="start_date" required />';
    echo '  </div>';

    // End date
    echo '  <div class="sfs-hr-lf-group">';
    echo '      <div class="sfs-hr-lf-label">' . esc_html__( 'End date', 'sfs-hr' ) . '</div>';
    echo '      <input type="date" name="end_date" />';
    echo '      <div class="sfs-hr-lf-hint">' .
                esc_html__( 'If empty, it will be treated as a single-day leave.', 'sfs-hr' ) .
         '</div>';
    echo '  </div>';

    echo '</div>'; // .sfs-hr-lf-row

    // Reason
    echo '<div class="sfs-hr-lf-group">';
    echo '  <div class="sfs-hr-lf-label">' . esc_html__( 'Reason / note', 'sfs-hr' ) . '</div>';
    echo '  <textarea name="reason" rows="3"></textarea>';
    echo '</div>';

    // Supporting document
    echo '<div class="sfs-hr-lf-group">';
    echo '  <div class="sfs-hr-lf-label">' . esc_html__( 'Supporting document', 'sfs-hr' ) . '</div>';
    echo '  <input type="file" name="supporting_doc" accept=".pdf,image/*" />';
    echo '  <div class="sfs-hr-lf-hint">' .
                esc_html__( 'Required for Sick Leave.', 'sfs-hr' ) .
         '</div>';
    echo '</div>';

    // Submit
    echo '<div class="sfs-hr-lf-actions">';
    echo '  <button type="submit" class="button button-primary sfs-hr-lf-submit">';
    esc_html_e( 'Submit leave request', 'sfs-hr' );
    echo '  </button>';
    echo '</div>';

    echo '</div>'; // .sfs-hr-leave-form-fields
    echo '</form>';
    echo '</div>'; // form wrap

    }

    // ====== History ======
    echo '<h5 style="margin-top:18px;">' . esc_html__( 'Leave history', 'sfs-hr' ) . '</h5>';

    if ( empty( $rows ) ) {
        echo '<p>' . esc_html__( 'No leave requests found.', 'sfs-hr' ) . '</p>';
        echo '</div>'; // wrapper
        return;
    }

    // Normalize rows once so we can reuse for desktop + mobile
    $display_rows = [];

    foreach ( $rows as $row ) {
        $type_name = $row->type_name ?: __( 'N/A', 'sfs-hr' );

        $start  = $row->start_date ?: '';
        $end    = $row->end_date   ?: '';
        $period = $start;
        if ( $start && $end && $end !== $start ) {
            $period = $start . ' → ' . $end;
        }

        // Days: never show 0 for a valid period
        $days = isset( $row->days ) ? (int) $row->days : 0;
        if ( $days <= 0 && $start !== '' ) {
            if ( $end === '' || $end === $start ) {
                $days = 1;
            } else {
                $start_ts = strtotime( $start );
                $end_ts   = strtotime( $end );
                if ( $start_ts && $end_ts && $end_ts >= $start_ts ) {
                    $days = (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;
                }
            }
        }

        // Status key: mimic admin list ("Pending - Manager/HR")
        $status_key = (string) $row->status;
        if ( $status_key === 'pending' ) {
            $level      = isset( $row->approval_level ) ? (int) $row->approval_level : 1;
            $status_key = ( $level <= 1 ) ? 'pending_manager' : 'pending_hr';
        }

        // Status badge (frontend) – use Leave_UI helper if available
        if ( method_exists( \SFS\HR\Modules\Leave\Leave_UI::class, 'leave_status_chip' ) ) {
            try {
                $status_html = \SFS\HR\Modules\Leave\Leave_UI::leave_status_chip( $row );
            } catch ( \Throwable $e ) {
                $status_html = \SFS\HR\Modules\Leave\Leave_UI::leave_status_chip( $status_key );
            }
        } else {
            $status_label = ucfirst( str_replace( '_', ' ', $status_key ) );
            $status_html  = '<span class="sfs-hr-badge sfs-hr-leave-status sfs-hr-leave-status-'
                          . esc_attr( $status_key ) . '">'
                          . esc_html( $status_label )
                          . '</span>';
        }

        // Sick-leave document link
        $doc_html = '';
        $doc_id   = isset( $row->doc_attachment_id ) ? (int) $row->doc_attachment_id : 0;
        if ( $doc_id > 0 && stripos( $type_name, 'sick' ) !== false ) {
            $doc_url = wp_get_attachment_url( $doc_id );
            if ( $doc_url ) {
                $doc_html = sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    esc_url( $doc_url ),
                    esc_html__( 'View document', 'sfs-hr' )
                );
            }
        }

        $created_at = $row->created_at ?? '';

        $display_rows[] = [
            'type_name'   => $type_name,
            'period'      => $period,
            'days'        => $days,
            'status_key'  => $status_key,
            'status_html' => $status_html,
            'created_at'  => $created_at,
            'doc_html'    => $doc_html,
        ];
    }

    // ===== Desktop table =====
    echo '<div class="sfs-hr-leaves-desktop">';
    echo '<table class="sfs-hr-table sfs-hr-leave-table" style="margin-top:8px;">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Period', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Days', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Document', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Requested at', 'sfs-hr' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $display_rows as $r ) {
        echo '<tr>';
        echo '<td>' . esc_html( $r['type_name'] ) . '</td>';
        echo '<td>' . esc_html( $r['period'] ) . '</td>';
        echo '<td>' . esc_html( (string) $r['days'] ) . '</td>';
        echo '<td>' . $r['status_html'] . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        echo '<td>';
        if ( ! empty( $r['doc_html'] ) ) {
            echo $r['doc_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            echo '&mdash;';
        }
        echo '</td>';

        echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>'; // .sfs-hr-leaves-desktop

    // ===== Mobile cards =====
    echo '<div class="sfs-hr-leaves-mobile">';
    foreach ( $display_rows as $r ) {
        echo '<details class="sfs-hr-leave-card">';
        echo '  <summary class="sfs-hr-leave-summary">';
        echo '      <span class="sfs-hr-leave-summary-title">' . esc_html( $r['type_name'] ) . '</span>';
        echo '      <span class="sfs-hr-leave-summary-status">';
        echo            $r['status_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '      </span>';
        echo '  </summary>';

        echo '  <div class="sfs-hr-leave-body">';
        echo '      <div class="sfs-hr-leave-field-row">';
        echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Period', 'sfs-hr' ) . '</div>';
        echo '          <div class="sfs-hr-leave-field-value">' . esc_html( $r['period'] ) . '</div>';
        echo '      </div>';

        echo '      <div class="sfs-hr-leave-field-row">';
        echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Days', 'sfs-hr' ) . '</div>';
        echo '          <div class="sfs-hr-leave-field-value">' . esc_html( (string) $r['days'] ) . '</div>';
        echo '      </div>';

        if ( ! empty( $r['doc_html'] ) ) {
            echo '      <div class="sfs-hr-leave-field-row">';
            echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Document', 'sfs-hr' ) . '</div>';
            echo '          <div class="sfs-hr-leave-field-value">';
            echo                $r['doc_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '          </div>';
            echo '      </div>';
        }

        echo '      <div class="sfs-hr-leave-field-row">';
        echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Requested at', 'sfs-hr' ) . '</div>';
        echo '          <div class="sfs-hr-leave-field-value">' . esc_html( $r['created_at'] ) . '</div>';
        echo '      </div>';
        echo '  </div>';
        echo '</details>';
    }
    echo '</div>'; // .sfs-hr-leaves-mobile

    // Styles have been moved to the beginning of output for proper rendering

    echo '</div>'; // .sfs-hr-my-profile-leave wrapper
}





/**
 * Frontend "My Assets" block – read-only, with clickable asset names (to admin).
 * If the employee has no assets, nothing is rendered.
 */
private function render_my_assets_frontend( int $employee_id ): void {
    global $wpdb;

    $assign_table = $wpdb->prefix . 'sfs_hr_asset_assignments';
    $asset_table  = $wpdb->prefix . 'sfs_hr_assets';

    // Load assignments; if tables don't exist or there are no rows, this will be empty.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT 
                a.*,
                ast.id         AS asset_id,
                ast.name       AS asset_name,
                ast.asset_code AS asset_code,
                ast.category   AS asset_category
            FROM {$assign_table} a
            LEFT JOIN {$asset_table} ast ON ast.id = a.asset_id
            WHERE a.employee_id = %d
            ORDER BY a.created_at DESC
            LIMIT 50
            ",
            $employee_id
        ),
        ARRAY_A
    );

    // No assets → hide block completely.
    if ( empty( $rows ) ) {
        return;
    }

    echo '<div class="sfs-hr-my-assets-frontend" style="margin-top:24px;">';
    echo '<h4>' . esc_html__( 'My Assets', 'sfs-hr' ) . '</h4>';

    echo '<table class="sfs-hr-table sfs-hr-assets-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__( 'Asset', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Code', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Start', 'sfs-hr' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $rows as $row ) {
        $asset_label = trim( (string) ( $row['asset_name'] ?? '' ) );
        if ( ! empty( $row['asset_code'] ) ) {
            $asset_code  = (string) $row['asset_code'];
            $asset_label = $asset_label !== ''
                ? $asset_label . ' (' . $asset_code . ')'
                : $asset_code;
        }

        // Make asset name clickable → admin asset edit screen.
        $asset_cell = esc_html( $asset_label );
        if ( ! empty( $row['asset_id'] ) ) {
            $edit_url = add_query_arg(
                [
                    'page' => 'sfs-hr-assets',
                    'tab'  => 'assets',
                    'view' => 'edit',
                    'id'   => (int) $row['asset_id'],
                ],
                admin_url( 'admin.php' )
            );
            $asset_cell = '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $asset_label ) . '</a>';
        }

        echo '<tr>';
        echo '<td>' . $asset_cell . '</td>';
        echo '<td>' . esc_html( $row['asset_code'] ?? '' ) . '</td>';
        echo '<td>' . (
                method_exists( \SFS\HR\Core\Helpers::class, 'asset_status_badge' )
                    ? \SFS\HR\Core\Helpers::asset_status_badge( (string) ( $row['status'] ?? '' ) )
                    : esc_html( (string) ( $row['status'] ?? '' ) )
            ) . '</td>';
        echo '<td>' . esc_html( $row['start_date'] ?: '' ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}



/**
 * Frontend "My Attendance" block – current month summary + daily history.
 */
private function render_my_attendance_frontend( int $employee_id ): void {
    global $wpdb;

    $sess_table  = $wpdb->prefix . 'sfs_hr_attendance_sessions';
    $punch_table = $wpdb->prefix . 'sfs_hr_attendance_punches';

    // Require login + valid employee id.
    if ( ! is_user_logged_in() || $employee_id <= 0 ) {
        echo '<div id="sfs-hr-my-attendance" class="sfs-hr-my-attendance-frontend" style="margin-top:24px;">';
        echo '<h4>' . esc_html__( 'My Attendance', 'sfs-hr' ) . '</h4>';
        echo '<p class="description">' . esc_html__( 'You must be logged in to see your attendance.', 'sfs-hr' ) . '</p>';
        echo '</div>';
        return;
    }

    // ---------- Helpers ----------

    $status_label_fn = static function ( string $st ): string {
        $st = trim( $st );
        if ( $st === '' ) {
            return __( 'No record', 'sfs-hr' );
        }
        switch ( $st ) {
            case 'present':
                return __( 'Present', 'sfs-hr' );
            case 'late':
                return __( 'Late', 'sfs-hr' );
            case 'left_early':
                return __( 'Left early', 'sfs-hr' );
            case 'incomplete':
                return __( 'Incomplete', 'sfs-hr' );
            case 'on_leave':
                return __( 'On leave', 'sfs-hr' );
            case 'not_clocked_in':
                return __( 'Not Clocked-IN', 'sfs-hr' );
            case 'absent':
                return __( 'Absent', 'sfs-hr' );
            default:
                return ucfirst( str_replace( '_', ' ', $st ) );
        }
    };

    // Chip with color per status.
    $status_chip_fn = static function ( string $st ) use ( $status_label_fn ): string {
        $label = $status_label_fn( $st );
        if ( $label === '' ) {
            return '';
        }

        $classes = 'sfs-hr-status-chip';

        switch ( $st ) {
            case 'present':
                $classes .= ' sfs-hr-status-chip--present';
                break;
            case 'late':
                $classes .= ' sfs-hr-status-chip--late';
                break;
            case 'absent':
            case 'not_clocked_in':
                $classes .= ' sfs-hr-status-chip--absent';
                break;
            case 'incomplete':
                $classes .= ' sfs-hr-status-chip--incomplete';
                break;
            case 'on_leave':
                $classes .= ' sfs-hr-status-chip--on-leave';
                break;
            default:
                $classes .= ' sfs-hr-status-chip--neutral';
                break;
        }

        return '<span class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</span>';
    };

    // MySQL datetime (stored UTC) -> local time "6:08 am"
    $format_time_local = static function ( ?string $mysql ): string {
        $mysql = (string) $mysql;
        if ( $mysql === '' || $mysql === '0000-00-00 00:00:00' ) {
            return '';
        }
        $ts_utc = strtotime( $mysql . ' UTC' );
        if ( ! $ts_utc ) {
            return '';
        }
        return wp_date( 'g:i a', $ts_utc );
    };

    // ---------- Today line (with optional break info) ----------

    $today = wp_date( 'Y-m-d' );

    $today_row = $wpdb->get_row(
        $wpdb->prepare(
            "
            SELECT status, in_time, out_time
            FROM {$sess_table}
            WHERE employee_id = %d
              AND work_date   = %s
            ORDER BY id DESC
            LIMIT 1
            ",
            $employee_id,
            $today
        ),
        ARRAY_A
    );

    $today_status_key   = isset( $today_row['status'] ) ? (string) $today_row['status'] : '';
    $today_status_label = $status_label_fn( $today_status_key );

    $today_in_label  = ! empty( $today_row['in_time'] )
        ? $format_time_local( (string) $today_row['in_time'] )
        : '';
    $today_out_label = ! empty( $today_row['out_time'] )
        ? $format_time_local( (string) $today_row['out_time'] )
        : '';

    // Try to derive break start / end from punches table (if the table exists and structure matches).
    $break_start_label = '';
    $break_end_label   = '';

    $table_exists = $wpdb->get_var(
        $wpdb->prepare( "SHOW TABLES LIKE %s", $punch_table )
    );

    if ( $table_exists === $punch_table ) {
        $break_rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT punch_type, punch_time
                FROM {$punch_table}
                WHERE employee_id = %d
                  AND DATE(punch_time) = %s
                  AND punch_type IN ('break_start','break_end')
                ORDER BY punch_time ASC
                ",
                $employee_id,
                $today
            ),
            ARRAY_A
        );

        if ( ! empty( $break_rows ) ) {
            foreach ( $break_rows as $br ) {
                $type = (string) ( $br['punch_type'] ?? '' );
                $time = (string) ( $br['punch_time'] ?? '' );
                if ( $type === 'break_start' ) {
                    $break_start_label = $format_time_local( $time );
                } elseif ( $type === 'break_end' ) {
                    $break_end_label = $format_time_local( $time );
                }
            }
        }
    }

    // Build the human line.
    $today_parts = [];

    if ( $today_in_label !== '' ) {
        $today_parts[] = sprintf(
            /* translators: 1: time */
            __( 'Clocked in at %s', 'sfs-hr' ),
            $today_in_label
        );
    }
    if ( $today_out_label !== '' ) {
        $today_parts[] = sprintf(
            /* translators: 1: time */
            __( 'Clocked out at %s', 'sfs-hr' ),
            $today_out_label
        );
    }
    if ( $break_start_label !== '' ) {
        $today_parts[] = sprintf(
            /* translators: 1: time */
            __( 'Break start: %s', 'sfs-hr' ),
            $break_start_label
        );
    }
    if ( $break_end_label !== '' ) {
        $today_parts[] = sprintf(
            /* translators: 1: time */
            __( 'Break end: %s', 'sfs-hr' ),
            $break_end_label
        );
    }

    $today_line = '';
    if ( ! empty( $today_parts ) ) {
        $today_line = implode( ' · ', $today_parts );
        if ( $today_status_key !== '' ) {
            $today_line .= ' (' . $today_status_label . ')';
        }
    } elseif ( $today_status_key !== '' ) {
        $today_line = $today_status_label;
    } else {
        $today_line = __( 'No record', 'sfs-hr' );
    }

    // ---------- Current month history ----------

    $month_start = wp_date( 'Y-m-01' );
    $month_end   = wp_date( 'Y-m-t' );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT work_date, status, in_time, out_time
            FROM {$sess_table}
            WHERE employee_id = %d
              AND work_date BETWEEN %s AND %s
            ORDER BY work_date DESC
            ",
            $employee_id,
            $month_start,
            $month_end
        ),
        ARRAY_A
    );

    // Status counts (current month).
    $status_counts = [];
    foreach ( $rows as $row ) {
        $st = trim( (string) ( $row['status'] ?? '' ) );
        if ( $st === '' ) {
            continue;
        }
        if ( ! isset( $status_counts[ $st ] ) ) {
            $status_counts[ $st ] = 0;
        }
        $status_counts[ $st ]++;
    }
    $total_days = array_sum( $status_counts );

    // ---------- Output ----------

    echo '<div id="sfs-hr-my-attendance" class="sfs-hr-my-attendance-frontend" style="margin-top:24px;">';
    echo '<h4>' . esc_html__( 'My Attendance', 'sfs-hr' ) . '</h4>';

    echo '<p><strong>' . esc_html__( 'Today:', 'sfs-hr' ) . '</strong> ' . esc_html( $today_line ) . '</p>';

    if ( empty( $rows ) ) {
        echo '<p class="description">' . esc_html__( 'No attendance records for the last days.', 'sfs-hr' ) . '</p>';
        echo '</div>';
        return;
    }

    echo '<div class="sfs-hr-att-grid">';

    // ---- Status counts card ----
    echo '<div class="sfs-hr-att-card">';
    echo '<h5>' . esc_html__( 'Status counts', 'sfs-hr' ) . '</h5>';

    echo '<table class="sfs-hr-table sfs-hr-att-status-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Days', 'sfs-hr' ) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ( $status_counts as $st_key => $count ) {
        echo '<tr>';
        echo '<td>' . $status_chip_fn( (string) $st_key ) . '</td>';
        echo '<td>' . (int) $count . '</td>';
        echo '</tr>';
    }

    echo '<tr class="sfs-hr-att-total-row">';
    echo '<td><strong>' . esc_html__( 'Total days with records', 'sfs-hr' ) . '</strong></td>';
    echo '<td><strong>' . (int) $total_days . '</strong></td>';
    echo '</tr>';

    echo '</tbody></table>';
    echo '</div>'; // card 1

    // ---- Daily history card ----
    echo '<div class="sfs-hr-att-card">';
    echo '<h5>' . esc_html__( 'Daily history', 'sfs-hr' ) . '</h5>';

    echo '<table class="sfs-hr-table sfs-hr-attendance-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__( 'Date', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Time in', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Time out', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '</tr></thead><tbody>';

    $max_visible = 5;
    $has_more    = count( $rows ) > $max_visible;

    foreach ( $rows as $idx => $row ) {
        $date     = $row['work_date'] ?? '';
        $st       = (string) ( $row['status'] ?? '' );
        $time_in  = $format_time_local( $row['in_time']  ?? '' );
        $time_out = $format_time_local( $row['out_time'] ?? '' );
        if ( $time_out === '' ) {
            $time_out = '–';
        }

        $extra_attr = ( $has_more && $idx >= $max_visible )
            ? ' class="sfs-hr-att-extra" style="display:none;"'
            : '';

        echo "<tr{$extra_attr}>";
        echo '<td>' . esc_html( $date ) . '</td>';
        echo '<td>' . esc_html( $time_in ) . '</td>';
        echo '<td>' . esc_html( $time_out ) . '</td>';
        echo '<td>' . $status_chip_fn( $st ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    if ( $has_more ) {
        echo '<p class="sfs-hr-att-more-wrap">';
        echo '<button type="button" class="sfs-hr-show-more-days">';
        echo esc_html__( 'Show more days', 'sfs-hr' );
        echo '</button>';
        echo '</p>';
    }

    echo '</div>'; // card 2

    echo '</div>'; // grid
    echo '</div>'; // wrapper

    // ---------- CSS (cards + chips + button) ----------
    ?>
    <style>
    .sfs-hr-att-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 16px;
        margin-top: 12px;
    }
    .sfs-hr-att-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 12px 14px 14px;
    }
    .sfs-hr-att-card h5 {
        margin: 0 0 8px;
        font-size: 13px;
        font-weight: 600;
    }
    .sfs-hr-att-status-table th,
    .sfs-hr-att-status-table td,
    .sfs-hr-attendance-table th,
    .sfs-hr-attendance-table td {
        font-size: 12px;
    }
    .sfs-hr-att-total-row td {
        font-weight: 600;
    }

    /* Status chips */
    .sfs-hr-status-chip {
        display: inline-block;
        padding: 1px 8px;
        font-size: 11px;
        line-height: 1.4;
        border-radius: 999px;
        border: 1px solid #e5e7eb;
        background: #f3f4f6;
        color: #374151;
        white-space: nowrap;
    }
    .sfs-hr-status-chip--present {
        background: #ecfdf3;
        border-color: #bbf7d0;
        color: #166534;
    }
    .sfs-hr-status-chip--late,
    .sfs-hr-status-chip--absent {
        background: #fee2e2;
        border-color: #fecaca;
        color: #b91c1c;
    }
    .sfs-hr-status-chip--incomplete {
        background: #fef9c3;
        border-color: #fef08a;
        color: #92400e;
    }
    .sfs-hr-status-chip--on-leave {
        background: #e0f2fe;
        border-color: #bae6fd;
        color: #075985;
    }

    /* "Show more days" pill button */
    .sfs-hr-show-more-days {
        margin-top: 8px;
        padding: 6px 14px;
        font-size: 12px;
        border-radius: 999px;
        border: 1px solid #c4b5fd;
        background: #ede9fe;
        color: #4c1d95;
        cursor: pointer;
    }
    .sfs-hr-show-more-days:hover {
        background: #ddd6fe;
    }

    .sfs-hr-att-more-wrap {
        text-align: right;
    }
    </style>
    <script>
    (function () {
        if (window.sfsHrAttMoreInit) return;
        window.sfsHrAttMoreInit = true;

        document.addEventListener('click', function (e) {
            var btn = e.target;
            if (!btn.classList || !btn.classList.contains('sfs-hr-show-more-days')) return;

            e.preventDefault();
            var card  = btn.closest('.sfs-hr-att-card');
            if (!card) return;
            var rows  = card.querySelectorAll('.sfs-hr-att-extra');
            if (!rows.length) return;

            rows.forEach(function (tr) { tr.style.display = 'table-row'; });
            btn.style.display = 'none';
        });
    })();
    </script>
    <?php
}





    public function my_loans($atts = [], $content = ''): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="sfs-hr sfs-hr-alert">' . esc_html__('Please log in to view your loans.','sfs-hr') . '</div>';
        }
        $emp_id = Helpers::current_employee_id();
        if ( ! $emp_id ) {
            return '<div class="sfs-hr sfs-hr-alert">' . esc_html__('Your HR profile is not linked. Please contact HR.','sfs-hr') . '</div>';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_loans';
        $rows = $wpdb->get_results( $wpdb->prepare("SELECT id,status,principal_amount,outstanding_principal,installment_amount,start_period FROM {$table} WHERE employee_id=%d ORDER BY id DESC", $emp_id), ARRAY_A );
        ob_start(); ?>
        <div class="sfs-hr sfs-hr-loans">
          <h3><?php echo esc_html__('My Loans', 'sfs-hr'); ?></h3>
          <?php if (empty($rows)): ?>
            <p><?php echo esc_html__('No loans found.','sfs-hr'); ?></p>
          <?php else: ?>
          <table class="sfs-hr-table">
            <thead>
              <tr>
                <th><?php echo esc_html__('ID','sfs-hr'); ?></th>
                <th><?php echo esc_html__('Status','sfs-hr'); ?></th>
                <th><?php echo esc_html__('Principal','sfs-hr'); ?></th>
                <th><?php echo esc_html__('Outstanding','sfs-hr'); ?></th>
                <th><?php echo esc_html__('Monthly Deduction','sfs-hr'); ?></th>
                <th><?php echo esc_html__('Start Period','sfs-hr'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo esc_html( (string)$r['id'] ); ?></td>
                  <td><?php echo esc_html( $r['status'] ); ?></td>
                  <td><?php echo esc_html( number_format_i18n( (float)$r['principal_amount'], 2 ) ); ?></td>
                  <td><?php echo esc_html( number_format_i18n( (float)$r['outstanding_principal'], 2 ) ); ?></td>
                  <td><?php echo esc_html( number_format_i18n( (float)$r['installment_amount'], 2 ) ); ?></td>
                  <td><?php echo esc_html( (string)$r['start_period'] ); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
        <style>
        .sfs-hr-table { width:100%; border-collapse: collapse; }
        .sfs-hr-table th, .sfs-hr-table td { padding:8px 10px; border-bottom:1px solid #eee; text-align:left; }
        .sfs-hr-alert { padding:12px; background:#fef3c7; border:1px solid #fde68a; border-radius:8px; }
        </style>
        <?php
        return (string) ob_get_clean();
    }
    public function leave_request(): string {
    if ( ! is_user_logged_in() ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__('Please log in to request leave.','sfs-hr') . '</div>';
    }

    // If you have a dedicated My Profile page, replace get_permalink()
    // with that page's URL or ID.
    $profile_url = get_permalink(); // <- adjust if needed

    $html  = '<div class="sfs-hr sfs-hr-alert">';
    $html .= esc_html__( 'Leave requests are now handled from your HR profile page.', 'sfs-hr' );
    $html .= '</div>';

    return $html;
}

    public function my_leaves(): string {
    if ( ! is_user_logged_in() ) {
        return '<div class="sfs-hr sfs-hr-alert">' . esc_html__('Please log in to view your leaves.','sfs-hr') . '</div>';
    }

    $html  = '<div class="sfs-hr sfs-hr-alert">';
    $html .= esc_html__( 'Your leave history is available in your HR profile under the "Leave" tab.', 'sfs-hr' );
    $html .= '</div>';

    return $html;
}

    
    /**
 * Frontend "My Leave" tab:
 * - List all leave requests for the current employee (latest first).
 */
private function render_my_leave_frontend( int $employee_id ): void {
    global $wpdb;

    $req_table  = $wpdb->prefix . 'sfs_hr_leave_requests';
    $type_table = $wpdb->prefix . 'sfs_hr_leave_types';

    // Fetch leave requests for this employee
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT r.*, t.name AS type_name
            FROM {$req_table} r
            LEFT JOIN {$type_table} t ON t.id = r.type_id
            WHERE r.employee_id = %d
            ORDER BY r.start_date DESC
            LIMIT 100
            ",
            $employee_id
        )
    );

    echo '<div class="sfs-hr sfs-hr-my-leave-frontend" style="margin-top:16px;">';
    echo '<h3>' . esc_html__( 'My Leave', 'sfs-hr' ) . '</h3>';

    if ( empty( $rows ) ) {
        echo '<p>' . esc_html__( 'You have no leave requests yet.', 'sfs-hr' ) . '</p>';
        echo '</div>';
        return;
    }

    echo '<table class="sfs-hr-table sfs-hr-leave-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Period', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
    echo '<th>' . esc_html__( 'Requested at', 'sfs-hr' ) . '</th>';
    echo '</tr></thead><tbody>';

    // Normalize rows once so we can reuse for desktop + mobile
$display_rows = [];

foreach ( $rows as $row ) {
    $type_name = $row->type_name ?: __( 'N/A', 'sfs-hr' );

    $start  = $row->start_date ?: '';
    $end    = $row->end_date   ?: '';
    $period = $start;
    if ( $start && $end && $end !== $start ) {
        $period = $start . ' → ' . $end;
    }

    // Days: never show 0 for a valid period
    $days = isset( $row->days ) ? (int) $row->days : 0;
    if ( $days <= 0 && $start !== '' ) {
        if ( $end === '' || $end === $start ) {
            $days = 1;
        } else {
            $start_ts = strtotime( $start );
            $end_ts   = strtotime( $end );
            if ( $start_ts && $end_ts && $end_ts >= $start_ts ) {
                $days = (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;
            }
        }
    }

    // Status string: keep "pending_manager"/"pending_hr" for consistency
    $status_string = (string) $row->status;
    if ( $status_string === 'pending' ) {
        $level         = isset( $row->approval_level ) ? (int) $row->approval_level : 1;
        $status_string = ( $level <= 1 ) ? 'pending_manager' : 'pending_hr';
    }

    // Status badge: prefer Leave_UI::leave_status_chip( $row ), fallback to string
    $status_html = '';
    if ( method_exists( \SFS\HR\Modules\Leave\Leave_UI::class, 'leave_status_chip' ) ) {
        try {
            $status_html = \SFS\HR\Modules\Leave\Leave_UI::leave_status_chip( $row );
        } catch ( \Throwable $e ) {
            $status_html = \SFS\HR\Modules\Leave\Leave_UI::leave_status_chip( $status_string );
        }
    } else {
        $status_label = ucfirst( str_replace( '_', ' ', $status_string ) );
        $status_html  = '<span class="sfs-hr-badge sfs-hr-leave-status sfs-hr-leave-status-'
                      . esc_attr( $status_string ) . '">'
                      . esc_html( $status_label )
                      . '</span>';
    }

    // Sick-leave document link
    $doc_html = '';
    $doc_id   = isset( $row->doc_attachment_id ) ? (int) $row->doc_attachment_id : 0;
    if ( $doc_id > 0 && stripos( $type_name, 'sick' ) !== false ) {
        $doc_url = wp_get_attachment_url( $doc_id );
        if ( $doc_url ) {
            $doc_html = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url( $doc_url ),
                esc_html__( 'View document', 'sfs-hr' )
            );
        }
    }

    $created_at = $row->created_at ?? '';

    $display_rows[] = [
        'type_name'   => $type_name,
        'period'      => $period,
        'days'        => $days,
        'status_key'  => $status_string,
        'status_html' => $status_html,
        'created_at'  => $created_at,
        'doc_html'    => $doc_html,
    ];
}

// ===== Desktop table =====
echo '<div class="sfs-hr-leaves-desktop">';
echo '<table class="sfs-hr-table sfs-hr-leave-table" style="margin-top:8px;">';
echo '<thead><tr>';
echo '<th>' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Period', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Days', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Document', 'sfs-hr' ) . '</th>';
echo '<th>' . esc_html__( 'Requested at', 'sfs-hr' ) . '</th>';
echo '</tr></thead><tbody>';

foreach ( $display_rows as $r ) {
    echo '<tr>';
    echo '<td>' . esc_html( $r['type_name'] ) . '</td>';
    echo '<td>' . esc_html( $r['period'] ) . '</td>';
    echo '<td>' . esc_html( (string) $r['days'] ) . '</td>';
    echo '<td>' . $r['status_html'] . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

    echo '<td>';
    if ( ! empty( $r['doc_html'] ) ) {
        echo $r['doc_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    } else {
        echo '&mdash;';
    }
    echo '</td>';

    echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>'; // .sfs-hr-leaves-desktop

// ===== Mobile cards =====
echo '<div class="sfs-hr-leaves-mobile">';
foreach ( $display_rows as $r ) {
    echo '<details class="sfs-hr-leave-card">';
    echo '  <summary class="sfs-hr-leave-summary">';
    echo '      <span class="sfs-hr-leave-summary-title">' . esc_html( $r['type_name'] ) . '</span>';
    echo '      <span class="sfs-hr-leave-summary-status">';
    echo            $r['status_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '      </span>';
    echo '  </summary>';

    echo '  <div class="sfs-hr-leave-body">';

    echo '      <div class="sfs-hr-leave-field-row">';
    echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Period', 'sfs-hr' ) . '</div>';
    echo '          <div class="sfs-hr-leave-field-value">' . esc_html( $r['period'] ) . '</div>';
    echo '      </div>';

    echo '      <div class="sfs-hr-leave-field-row">';
    echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Days', 'sfs-hr' ) . '</div>';
    echo '          <div class="sfs-hr-leave-field-value">' . esc_html( (string) $r['days'] ) . '</div>';
    echo '      </div>';

    if ( ! empty( $r['doc_html'] ) ) {
        echo '      <div class="sfs-hr-leave-field-row">';
        echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Document', 'sfs-hr' ) . '</div>';
        echo '          <div class="sfs-hr-leave-field-value">';
        echo                $r['doc_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '          </div>';
        echo '      </div>';
    }

    echo '      <div class="sfs-hr-leave-field-row">';
    echo '          <div class="sfs-hr-leave-field-label">' . esc_html__( 'Requested at', 'sfs-hr' ) . '</div>';
    echo '          <div class="sfs-hr-leave-field-value">' . esc_html( $r['created_at'] ) . '</div>';
    echo '      </div>';

    echo '  </div>';
    echo '</details>';
}
echo '</div>'; // .sfs-hr-leaves-mobile

}


}
