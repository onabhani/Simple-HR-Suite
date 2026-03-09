<?php
namespace SFS\HR\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Setup_Wizard
 *
 * Seven-step setup wizard for first-run configuration.
 * Steps: Company → Departments → Work Schedule → Leave Policy
 *        → Approval Chain → Notifications → Done
 *
 * Optional with a dismissible admin notice nudge.
 *
 * @since 1.4.2
 */
class Setup_Wizard {

    /** Option: wizard completed flag. */
    const OPT_COMPLETED = 'sfs_hr_wizard_completed';

    /** Option: nudge dismissed flag. */
    const OPT_DISMISSED = 'sfs_hr_wizard_dismissed';

    /** Ordered step keys. */
    const STEPS = [
        'company',
        'departments',
        'schedule',
        'leave',
        'approvals',
        'notifications',
        'done',
    ];

    /* ───────── bootstrap ───────── */

    public function hooks(): void {
        add_action( 'admin_menu',    [ $this, 'menu' ] );
        add_action( 'admin_notices', [ $this, 'nudge_notice' ] );
        add_action( 'admin_post_sfs_hr_wizard_save',    [ $this, 'handle_save' ] );
        add_action( 'admin_post_sfs_hr_wizard_dismiss', [ $this, 'handle_dismiss' ] );
        add_action( 'admin_post_sfs_hr_wizard_finish',  [ $this, 'handle_finish' ] );
    }

    /** Hidden page (no menu item). */
    public function menu(): void {
        add_submenu_page(
            '',
            __( 'Setup Wizard', 'sfs-hr' ),
            __( 'Setup Wizard', 'sfs-hr' ),
            'manage_options',
            'sfs-hr-setup-wizard',
            [ $this, 'render' ]
        );
    }

    /* ───────── admin notice ───────── */

    public function nudge_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( get_option( self::OPT_COMPLETED ) || get_option( self::OPT_DISMISSED ) ) {
            return;
        }

        $wizard_url  = admin_url( 'admin.php?page=sfs-hr-setup-wizard' );
        $dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=sfs_hr_wizard_dismiss' ), 'sfs_hr_wizard_dismiss' );

        ?>
        <div class="notice notice-info is-dismissible sfs-hr-wizard-notice" style="padding:12px 15px;border-left-color:#2271b1;">
            <p style="font-size:14px;margin:0 0 8px;">
                <strong><?php esc_html_e( 'Welcome to Simple HR Suite!', 'sfs-hr' ); ?></strong>
                <?php esc_html_e( 'Complete the setup wizard to configure your company profile, departments, work schedule, and more.', 'sfs-hr' ); ?>
            </p>
            <p style="margin:0;">
                <a href="<?php echo esc_url( $wizard_url ); ?>" class="button button-primary"><?php esc_html_e( 'Start Setup Wizard', 'sfs-hr' ); ?></a>
                <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button" style="margin-left:8px;"><?php esc_html_e( 'Dismiss', 'sfs-hr' ); ?></a>
            </p>
        </div>
        <?php
    }

    public function handle_dismiss(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'sfs_hr_wizard_dismiss' );
        update_option( self::OPT_DISMISSED, 1 );
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr' ) );
        exit;
    }

    public function handle_finish(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'sfs_hr_wizard_finish' );
        update_option( self::OPT_COMPLETED, 1 );
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr' ) );
        exit;
    }

    /* ───────── step save router ───────── */

    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'sfs_hr_wizard', '_sfs_wiz_nonce' );

        $step = sanitize_key( $_POST['wizard_step'] ?? '' );

        switch ( $step ) {
            case 'company':
                $this->save_company();
                break;
            case 'departments':
                $this->save_departments();
                break;
            case 'schedule':
                $this->save_schedule();
                break;
            case 'leave':
                $this->save_leave();
                break;
            case 'approvals':
                $this->save_approvals();
                break;
            case 'notifications':
                $this->save_notifications();
                break;
        }

        // Advance to next step.
        $next = $this->next_step( $step );
        wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-setup-wizard&step=' . $next . '&saved=1' ) );
        exit;
    }

    /* ───────── step save handlers ───────── */

    private function save_company(): void {
        $fields = [
            'company_name', 'legal_name', 'cr_number', 'employer_code',
            'bank_code', 'address', 'city', 'country', 'phone', 'email',
            'website', 'timezone', 'currency', 'fiscal_year_start',
        ];
        $data = [];
        foreach ( $fields as $f ) {
            $data[ $f ] = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
        }
        $data['logo_id'] = absint( $_POST['logo_id'] ?? 0 );
        Company_Profile::update( $data );
    }

    private function save_departments(): void {
        $names = isset( $_POST['dept_names'] ) ? (array) $_POST['dept_names'] : [];
        if ( empty( $names ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_departments';

        foreach ( $names as $name ) {
            $name = sanitize_text_field( wp_unslash( $name ) );
            if ( empty( $name ) ) {
                continue;
            }
            // Skip if department already exists.
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE name = %s LIMIT 1",
                $name
            ) );
            if ( $exists ) {
                continue;
            }
            $wpdb->insert( $table, [
                'name'   => $name,
                'active' => 1,
            ] );
        }
    }

    private function save_schedule(): void {
        $settings = get_option( \SFS\HR\Modules\Attendance\AttendanceModule::OPT_SETTINGS, [] );

        if ( ! empty( $_POST['period_type'] ) ) {
            $settings['period_type'] = sanitize_key( $_POST['period_type'] );
        }
        if ( ! empty( $_POST['period_start_day'] ) ) {
            $settings['period_start_day'] = absint( $_POST['period_start_day'] );
        }

        update_option( \SFS\HR\Modules\Attendance\AttendanceModule::OPT_SETTINGS, $settings );

        // Create a default shift if none exists.
        global $wpdb;
        $shifts_table = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $has_shifts = $wpdb->get_var( "SELECT COUNT(*) FROM {$shifts_table}" );

        if ( ! $has_shifts && ! empty( $_POST['default_shift_start'] ) && ! empty( $_POST['default_shift_end'] ) ) {
            $wpdb->insert( $shifts_table, [
                'name'        => sanitize_text_field( $_POST['default_shift_name'] ?? __( 'Default Shift', 'sfs-hr' ) ),
                'start_time'  => sanitize_text_field( $_POST['default_shift_start'] ),
                'end_time'    => sanitize_text_field( $_POST['default_shift_end'] ),
                'break_minutes' => absint( $_POST['default_break_minutes'] ?? 60 ),
                'working_days'  => sanitize_text_field( $_POST['working_days'] ?? '0,1,2,3,4' ),
            ] );
        }
    }

    private function save_leave(): void {
        if ( isset( $_POST['annual_lt5'] ) ) {
            update_option( 'sfs_hr_annual_lt5', absint( $_POST['annual_lt5'] ) );
        }
        if ( isset( $_POST['annual_ge5'] ) ) {
            update_option( 'sfs_hr_annual_ge5', absint( $_POST['annual_ge5'] ) );
        }

        // Import holidays if requested.
        if ( ! empty( $_POST['import_holidays'] ) ) {
            $preset = sanitize_key( $_POST['import_holidays'] );
            $holidays = self::get_preset_holidays( $preset );
            if ( $holidays ) {
                $existing = get_option( 'sfs_hr_holidays', [] );
                if ( ! is_array( $existing ) ) {
                    $existing = [];
                }
                foreach ( $holidays as $h ) {
                    // Avoid duplicates.
                    $dup = false;
                    foreach ( $existing as $e ) {
                        if ( ( $e['name'] ?? '' ) === $h['name'] ) {
                            $dup = true;
                            break;
                        }
                    }
                    if ( ! $dup ) {
                        $existing[] = $h;
                    }
                }
                update_option( 'sfs_hr_holidays', $existing );
            }
        }
    }

    private function save_approvals(): void {
        if ( isset( $_POST['hr_approvers'] ) ) {
            $ids = array_map( 'absint', (array) $_POST['hr_approvers'] );
            update_option( 'sfs_hr_leave_hr_approvers', array_filter( $ids ) );
        }
        if ( isset( $_POST['gm_approver'] ) ) {
            update_option( 'sfs_hr_leave_gm_approver', absint( $_POST['gm_approver'] ) );
        }
        if ( isset( $_POST['global_approver_role'] ) ) {
            update_option( 'sfs_hr_global_approver_role', sanitize_key( $_POST['global_approver_role'] ) );
        }
    }

    private function save_notifications(): void {
        $settings = Notifications::get_settings();

        $settings['enabled'] = ! empty( $_POST['notif_enabled'] );

        if ( ! empty( $_POST['hr_emails'] ) ) {
            $raw = sanitize_textarea_field( wp_unslash( $_POST['hr_emails'] ) );
            $emails = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $raw ) ) );
            $settings['hr_emails'] = array_values( array_filter( $emails, 'is_email' ) );
        }

        if ( isset( $_POST['channels'] ) ) {
            $channels = (array) $_POST['channels'];
            $settings['channels']['email'] = in_array( 'email', $channels, true );
            $settings['channels']['sms']   = in_array( 'sms', $channels, true );
        }

        Notifications::save_settings( $settings );
    }

    /* ───────── navigation helpers ───────── */

    private function next_step( string $current ): string {
        $idx = array_search( $current, self::STEPS, true );
        return self::STEPS[ $idx + 1 ] ?? 'done';
    }

    private function prev_step( string $current ): string {
        $idx = array_search( $current, self::STEPS, true );
        return $idx > 0 ? self::STEPS[ $idx - 1 ] : self::STEPS[0];
    }

    private function step_label( string $key ): string {
        $labels = [
            'company'       => __( 'Company', 'sfs-hr' ),
            'departments'   => __( 'Departments', 'sfs-hr' ),
            'schedule'      => __( 'Work Schedule', 'sfs-hr' ),
            'leave'         => __( 'Leave Policy', 'sfs-hr' ),
            'approvals'     => __( 'Approvals', 'sfs-hr' ),
            'notifications' => __( 'Notifications', 'sfs-hr' ),
            'done'          => __( 'Done', 'sfs-hr' ),
        ];
        return $labels[ $key ] ?? $key;
    }

    /* ───────── render: main ───────── */

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $step = sanitize_key( $_GET['step'] ?? 'company' );
        if ( ! in_array( $step, self::STEPS, true ) ) {
            $step = 'company';
        }
        $step_idx = array_search( $step, self::STEPS, true );

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1 style="margin-bottom:5px;"><?php esc_html_e( 'Simple HR Suite — Setup Wizard', 'sfs-hr' ); ?></h1>
            <p style="color:#666;margin-top:0;"><?php esc_html_e( 'Configure the essential settings to get started.', 'sfs-hr' ); ?></p>

            <!-- Progress bar -->
            <div style="display:flex;gap:4px;margin:20px 0 30px;max-width:700px;">
                <?php foreach ( self::STEPS as $i => $s ) :
                    $is_done    = $i < $step_idx;
                    $is_current = $i === $step_idx;
                    $bg = $is_done ? '#22c55e' : ( $is_current ? '#2271b1' : '#ddd' );
                    $color = ( $is_done || $is_current ) ? '#fff' : '#666';
                ?>
                <div style="flex:1;text-align:center;">
                    <div style="background:<?php echo $bg; ?>;color:<?php echo $color; ?>;border-radius:4px;padding:6px 4px;font-size:12px;font-weight:600;">
                        <?php echo esc_html( ( $i + 1 ) . '. ' . $this->step_label( $s ) ); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:25px 30px;max-width:700px;">
                <?php
                $method = 'render_step_' . $step;
                if ( method_exists( $this, $method ) ) {
                    $this->$method();
                }
                ?>
            </div>

            <?php if ( $step !== 'done' ) : ?>
            <p style="margin-top:15px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr' ) ); ?>" style="color:#666;text-decoration:none;">
                    <?php esc_html_e( 'Skip wizard and go to Dashboard', 'sfs-hr' ); ?> &rarr;
                </a>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ───────── Step 1: Company ───────── */

    private function render_step_company(): void {
        $p = Company_Profile::get();
        $logo_url = $p['logo_id'] ? wp_get_attachment_image_url( $p['logo_id'], 'medium' ) : '';
        wp_enqueue_media();
        ?>
        <h2 style="margin-top:0;"><?php esc_html_e( 'Company Profile', 'sfs-hr' ); ?></h2>
        <p style="color:#666;"><?php esc_html_e( 'Enter your organisation details. These are used in payroll exports, reports, and official documents.', 'sfs-hr' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="sfs_hr_wizard_save" />
            <input type="hidden" name="wizard_step" value="company" />
            <?php wp_nonce_field( 'sfs_hr_wizard', '_sfs_wiz_nonce' ); ?>

            <table class="form-table" style="margin-top:0;">
                <tr><th><label for="company_name"><?php esc_html_e( 'Company Name', 'sfs-hr' ); ?> *</label></th>
                    <td><input type="text" name="company_name" id="company_name" class="regular-text" value="<?php echo esc_attr( $p['company_name'] ); ?>" required /></td></tr>
                <tr><th><label for="legal_name"><?php esc_html_e( 'Legal Name', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" name="legal_name" id="legal_name" class="regular-text" value="<?php echo esc_attr( $p['legal_name'] ); ?>" /></td></tr>
                <tr><th><label for="cr_number"><?php esc_html_e( 'CR Number', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" name="cr_number" id="cr_number" class="regular-text" value="<?php echo esc_attr( $p['cr_number'] ); ?>" /></td></tr>
                <tr><th><label for="employer_code"><?php esc_html_e( 'Employer Code (MOL)', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" name="employer_code" id="employer_code" class="regular-text" value="<?php echo esc_attr( $p['employer_code'] ); ?>" /></td></tr>
                <tr><th><label for="bank_code"><?php esc_html_e( 'Bank Code', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" name="bank_code" id="bank_code" class="regular-text" value="<?php echo esc_attr( $p['bank_code'] ); ?>" /></td></tr>
                <tr><th><label for="country"><?php esc_html_e( 'Country', 'sfs-hr' ); ?></label></th>
                    <td><select name="country" id="country">
                        <?php foreach ( Company_Profile::get_countries_public() as $code => $name ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $p['country'], $code ); ?>><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select></td></tr>
                <tr><th><label for="city"><?php esc_html_e( 'City', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" name="city" id="city" class="regular-text" value="<?php echo esc_attr( $p['city'] ); ?>" /></td></tr>
                <tr><th><label for="phone"><?php esc_html_e( 'Phone', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" name="phone" id="phone" class="regular-text" value="<?php echo esc_attr( $p['phone'] ); ?>" /></td></tr>
                <tr><th><label for="email"><?php esc_html_e( 'Email', 'sfs-hr' ); ?></label></th>
                    <td><input type="email" name="email" id="email" class="regular-text" value="<?php echo esc_attr( $p['email'] ); ?>" /></td></tr>
                <tr><th><label for="timezone"><?php esc_html_e( 'Timezone', 'sfs-hr' ); ?></label></th>
                    <td><select name="timezone" id="timezone"><?php echo wp_timezone_choice( $p['timezone'] ); ?></select></td></tr>
                <tr><th><label for="currency"><?php esc_html_e( 'Currency', 'sfs-hr' ); ?></label></th>
                    <td><select name="currency" id="currency">
                        <?php foreach ( Company_Profile::get_currencies_public() as $code => $label ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $p['currency'], $code ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select></td></tr>
                <tr><th><label><?php esc_html_e( 'Logo', 'sfs-hr' ); ?></label></th>
                    <td>
                        <input type="hidden" name="logo_id" id="logo_id" value="<?php echo esc_attr( $p['logo_id'] ); ?>" />
                        <div id="sfs-logo-preview" style="margin-bottom:8px;">
                            <?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" style="max-height:60px;" /><?php endif; ?>
                        </div>
                        <button type="button" class="button" id="sfs-upload-logo"><?php esc_html_e( 'Choose Logo', 'sfs-hr' ); ?></button>
                        <button type="button" class="button" id="sfs-remove-logo" style="<?php echo $logo_url ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'sfs-hr' ); ?></button>
                    </td></tr>
            </table>

            <?php $this->render_nav_buttons( 'company' ); ?>
        </form>

        <script>
        jQuery(function($){
            var frame;
            $('#sfs-upload-logo').on('click',function(e){
                e.preventDefault();
                if(frame){frame.open();return;}
                frame=wp.media({title:'Choose Logo',button:{text:'Use as Logo'},multiple:false});
                frame.on('select',function(){
                    var a=frame.state().get('selection').first().toJSON();
                    $('#logo_id').val(a.id);
                    $('#sfs-logo-preview').html('<img src="'+a.url+'" style="max-height:60px;" />');
                    $('#sfs-remove-logo').show();
                });
                frame.open();
            });
            $('#sfs-remove-logo').on('click',function(e){
                e.preventDefault();$('#logo_id').val(0);$('#sfs-logo-preview').html('');$(this).hide();
            });
        });
        </script>
        <?php
    }

    /* ───────── Step 2: Departments ───────── */

    private function render_step_departments(): void {
        global $wpdb;
        $existing = $wpdb->get_col( "SELECT name FROM {$wpdb->prefix}sfs_hr_departments WHERE active = 1 ORDER BY name" );

        $suggestions = [
            __( 'Human Resources', 'sfs-hr' ),
            __( 'Finance', 'sfs-hr' ),
            __( 'IT', 'sfs-hr' ),
            __( 'Operations', 'sfs-hr' ),
            __( 'Sales', 'sfs-hr' ),
            __( 'Marketing', 'sfs-hr' ),
            __( 'Administration', 'sfs-hr' ),
        ];
        ?>
        <h2 style="margin-top:0;"><?php esc_html_e( 'Departments', 'sfs-hr' ); ?></h2>
        <p style="color:#666;"><?php esc_html_e( 'Add your company departments. You can always add more later from HR → Departments.', 'sfs-hr' ); ?></p>

        <?php if ( ! empty( $existing ) ) : ?>
            <p><strong><?php esc_html_e( 'Existing departments:', 'sfs-hr' ); ?></strong> <?php echo esc_html( implode( ', ', $existing ) ); ?></p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="sfs_hr_wizard_save" />
            <input type="hidden" name="wizard_step" value="departments" />
            <?php wp_nonce_field( 'sfs_hr_wizard', '_sfs_wiz_nonce' ); ?>

            <p><strong><?php esc_html_e( 'Quick add from suggestions:', 'sfs-hr' ); ?></strong></p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:15px;">
                <?php foreach ( $suggestions as $s ) :
                    $already = in_array( $s, $existing, true );
                ?>
                <label style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;background:<?php echo $already ? '#f0f0f0' : '#f0f6fc'; ?>;border:1px solid <?php echo $already ? '#ccc' : '#2271b1'; ?>;border-radius:4px;cursor:<?php echo $already ? 'default' : 'pointer'; ?>;">
                    <input type="checkbox" name="dept_names[]" value="<?php echo esc_attr( $s ); ?>" <?php echo $already ? 'disabled checked' : ''; ?> style="margin:0;" />
                    <?php echo esc_html( $s ); ?>
                    <?php if ( $already ) : ?><small style="color:#666;">(<?php esc_html_e( 'exists', 'sfs-hr' ); ?>)</small><?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>

            <p><strong><?php esc_html_e( 'Or add custom departments:', 'sfs-hr' ); ?></strong></p>
            <div id="sfs-custom-depts">
                <div style="margin-bottom:6px;"><input type="text" name="dept_names[]" class="regular-text" placeholder="<?php esc_attr_e( 'Department name', 'sfs-hr' ); ?>" /></div>
            </div>
            <button type="button" class="button" onclick="var d=document.createElement('div');d.style.marginBottom='6px';d.innerHTML='<input type=\'text\' name=\'dept_names[]\' class=\'regular-text\' placeholder=\'Department name\' />';document.getElementById('sfs-custom-depts').appendChild(d);">+ <?php esc_html_e( 'Add row', 'sfs-hr' ); ?></button>

            <?php $this->render_nav_buttons( 'departments' ); ?>
        </form>
        <?php
    }

    /* ───────── Step 3: Work Schedule ───────── */

    private function render_step_schedule(): void {
        $settings = get_option( \SFS\HR\Modules\Attendance\AttendanceModule::OPT_SETTINGS, [] );
        $period_type = $settings['period_type'] ?? 'full_month';
        $start_day   = $settings['period_start_day'] ?? 1;
        ?>
        <h2 style="margin-top:0;"><?php esc_html_e( 'Work Schedule', 'sfs-hr' ); ?></h2>
        <p style="color:#666;"><?php esc_html_e( 'Configure the default work schedule and attendance period.', 'sfs-hr' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="sfs_hr_wizard_save" />
            <input type="hidden" name="wizard_step" value="schedule" />
            <?php wp_nonce_field( 'sfs_hr_wizard', '_sfs_wiz_nonce' ); ?>

            <h3><?php esc_html_e( 'Attendance Period', 'sfs-hr' ); ?></h3>
            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th><label for="period_type"><?php esc_html_e( 'Period Type', 'sfs-hr' ); ?></label></th>
                    <td>
                        <select name="period_type" id="period_type">
                            <option value="full_month" <?php selected( $period_type, 'full_month' ); ?>><?php esc_html_e( 'Full Calendar Month (1st to last day)', 'sfs-hr' ); ?></option>
                            <option value="custom" <?php selected( $period_type, 'custom' ); ?>><?php esc_html_e( 'Custom Start Day', 'sfs-hr' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr id="start-day-row" style="<?php echo $period_type === 'custom' ? '' : 'display:none;'; ?>">
                    <th><label for="period_start_day"><?php esc_html_e( 'Start Day of Month', 'sfs-hr' ); ?></label></th>
                    <td>
                        <select name="period_start_day" id="period_start_day">
                            <?php for ( $d = 1; $d <= 28; $d++ ) : ?>
                                <option value="<?php echo $d; ?>" <?php selected( $start_day, $d ); ?>><?php echo $d; ?></option>
                            <?php endfor; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'e.g. 26 means the period runs from the 26th to the 25th of the next month.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Default Shift', 'sfs-hr' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Create a default shift. You can add more shifts later from the Attendance module.', 'sfs-hr' ); ?></p>
            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th><label for="default_shift_name"><?php esc_html_e( 'Shift Name', 'sfs-hr' ); ?></label></th>
                    <td><input type="text" name="default_shift_name" id="default_shift_name" class="regular-text" value="<?php echo esc_attr( __( 'Default Shift', 'sfs-hr' ) ); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="default_shift_start"><?php esc_html_e( 'Start Time', 'sfs-hr' ); ?></label></th>
                    <td><input type="time" name="default_shift_start" id="default_shift_start" value="08:00" /></td>
                </tr>
                <tr>
                    <th><label for="default_shift_end"><?php esc_html_e( 'End Time', 'sfs-hr' ); ?></label></th>
                    <td><input type="time" name="default_shift_end" id="default_shift_end" value="17:00" /></td>
                </tr>
                <tr>
                    <th><label for="default_break_minutes"><?php esc_html_e( 'Break (minutes)', 'sfs-hr' ); ?></label></th>
                    <td><input type="number" name="default_break_minutes" id="default_break_minutes" value="60" min="0" max="120" style="width:80px;" /></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Working Days', 'sfs-hr' ); ?></label></th>
                    <td>
                        <?php
                        $day_names = [
                            0 => __( 'Sun', 'sfs-hr' ), 1 => __( 'Mon', 'sfs-hr' ),
                            2 => __( 'Tue', 'sfs-hr' ), 3 => __( 'Wed', 'sfs-hr' ),
                            4 => __( 'Thu', 'sfs-hr' ), 5 => __( 'Fri', 'sfs-hr' ),
                            6 => __( 'Sat', 'sfs-hr' ),
                        ];
                        $default_on = [ 0, 1, 2, 3, 4 ]; // Sun-Thu
                        foreach ( $day_names as $num => $name ) :
                        ?>
                        <label style="margin-right:12px;"><input type="checkbox" name="working_days_arr[]" value="<?php echo $num; ?>" <?php checked( in_array( $num, $default_on, true ) ); ?> /> <?php echo esc_html( $name ); ?></label>
                        <?php endforeach; ?>
                        <input type="hidden" name="working_days" id="working_days_hidden" value="0,1,2,3,4" />
                    </td>
                </tr>
            </table>

            <?php $this->render_nav_buttons( 'schedule' ); ?>
        </form>

        <script>
        jQuery(function($){
            $('#period_type').on('change',function(){
                $('#start-day-row').toggle($(this).val()==='custom');
            });
            $('input[name="working_days_arr[]"]').on('change',function(){
                var vals=[];
                $('input[name="working_days_arr[]"]:checked').each(function(){vals.push($(this).val());});
                $('#working_days_hidden').val(vals.join(','));
            });
        });
        </script>
        <?php
    }

    /* ───────── Step 4: Leave Policy ───────── */

    private function render_step_leave(): void {
        $lt5 = get_option( 'sfs_hr_annual_lt5', 21 );
        $ge5 = get_option( 'sfs_hr_annual_ge5', 30 );
        ?>
        <h2 style="margin-top:0;"><?php esc_html_e( 'Leave Policy', 'sfs-hr' ); ?></h2>
        <p style="color:#666;"><?php esc_html_e( 'Configure annual leave quotas and optionally import a holiday calendar.', 'sfs-hr' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="sfs_hr_wizard_save" />
            <input type="hidden" name="wizard_step" value="leave" />
            <?php wp_nonce_field( 'sfs_hr_wizard', '_sfs_wiz_nonce' ); ?>

            <h3><?php esc_html_e( 'Annual Leave Quotas', 'sfs-hr' ); ?></h3>
            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th><label for="annual_lt5"><?php esc_html_e( 'Less than 5 years tenure', 'sfs-hr' ); ?></label></th>
                    <td><input type="number" name="annual_lt5" id="annual_lt5" value="<?php echo esc_attr( $lt5 ); ?>" min="0" max="60" style="width:80px;" /> <?php esc_html_e( 'days/year', 'sfs-hr' ); ?></td>
                </tr>
                <tr>
                    <th><label for="annual_ge5"><?php esc_html_e( '5 years or more tenure', 'sfs-hr' ); ?></label></th>
                    <td><input type="number" name="annual_ge5" id="annual_ge5" value="<?php echo esc_attr( $ge5 ); ?>" min="0" max="60" style="width:80px;" /> <?php esc_html_e( 'days/year', 'sfs-hr' ); ?></td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Holiday Calendar', 'sfs-hr' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Import a preset holiday calendar. You can edit holidays later from the Attendance settings.', 'sfs-hr' ); ?></p>
            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th><label for="import_holidays"><?php esc_html_e( 'Import Preset', 'sfs-hr' ); ?></label></th>
                    <td>
                        <select name="import_holidays" id="import_holidays">
                            <option value=""><?php esc_html_e( '— None —', 'sfs-hr' ); ?></option>
                            <option value="sa"><?php esc_html_e( 'Saudi Arabia', 'sfs-hr' ); ?></option>
                            <option value="ae"><?php esc_html_e( 'UAE', 'sfs-hr' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php $this->render_nav_buttons( 'leave' ); ?>
        </form>
        <?php
    }

    /* ───────── Step 5: Approval Chain ───────── */

    private function render_step_approvals(): void {
        $hr_approvers = get_option( 'sfs_hr_leave_hr_approvers', [] );
        $gm_approver  = (int) get_option( 'sfs_hr_leave_gm_approver', 0 );
        $global_role   = get_option( 'sfs_hr_global_approver_role', 'sfs_hr_manager' );

        // Get admin-capable users for dropdowns.
        $users = get_users( [
            'role__in' => [ 'administrator', 'sfs_hr_manager', 'sfs_hr_admin' ],
            'orderby'  => 'display_name',
            'number'   => 100,
        ] );
        ?>
        <h2 style="margin-top:0;"><?php esc_html_e( 'Approval Chain', 'sfs-hr' ); ?></h2>
        <p style="color:#666;"><?php esc_html_e( 'Set up who approves leave requests, loans, and other HR actions.', 'sfs-hr' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="sfs_hr_wizard_save" />
            <input type="hidden" name="wizard_step" value="approvals" />
            <?php wp_nonce_field( 'sfs_hr_wizard', '_sfs_wiz_nonce' ); ?>

            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th><label><?php esc_html_e( 'HR Approver(s)', 'sfs-hr' ); ?></label></th>
                    <td>
                        <select name="hr_approvers[]" multiple style="min-width:300px;min-height:80px;">
                            <?php foreach ( $users as $u ) : ?>
                                <option value="<?php echo $u->ID; ?>" <?php echo in_array( $u->ID, (array) $hr_approvers, false ) ? 'selected' : ''; ?>>
                                    <?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="gm_approver"><?php esc_html_e( 'General Manager', 'sfs-hr' ); ?></label></th>
                    <td>
                        <select name="gm_approver" id="gm_approver">
                            <option value="0"><?php esc_html_e( '— Select —', 'sfs-hr' ); ?></option>
                            <?php foreach ( $users as $u ) : ?>
                                <option value="<?php echo $u->ID; ?>" <?php selected( $gm_approver, $u->ID ); ?>>
                                    <?php echo esc_html( $u->display_name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="global_approver_role"><?php esc_html_e( 'Default Approver Role', 'sfs-hr' ); ?></label></th>
                    <td>
                        <select name="global_approver_role" id="global_approver_role">
                            <option value="sfs_hr_manager" <?php selected( $global_role, 'sfs_hr_manager' ); ?>><?php esc_html_e( 'HR Manager', 'sfs-hr' ); ?></option>
                            <option value="sfs_hr_admin" <?php selected( $global_role, 'sfs_hr_admin' ); ?>><?php esc_html_e( 'HR Admin', 'sfs-hr' ); ?></option>
                            <option value="administrator" <?php selected( $global_role, 'administrator' ); ?>><?php esc_html_e( 'Administrator', 'sfs-hr' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Fallback role when no specific approver is assigned for a department.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php $this->render_nav_buttons( 'approvals' ); ?>
        </form>
        <?php
    }

    /* ───────── Step 6: Notifications ───────── */

    private function render_step_notifications(): void {
        $settings = Notifications::get_settings();
        $enabled  = $settings['enabled'] ?? false;
        $hr_emails = $settings['hr_emails'] ?? [];
        $channels  = $settings['channels'] ?? [];
        ?>
        <h2 style="margin-top:0;"><?php esc_html_e( 'Notifications', 'sfs-hr' ); ?></h2>
        <p style="color:#666;"><?php esc_html_e( 'Configure how HR notifications are delivered.', 'sfs-hr' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="sfs_hr_wizard_save" />
            <input type="hidden" name="wizard_step" value="notifications" />
            <?php wp_nonce_field( 'sfs_hr_wizard', '_sfs_wiz_nonce' ); ?>

            <table class="form-table" style="margin-top:0;">
                <tr>
                    <th><label for="notif_enabled"><?php esc_html_e( 'Enable Notifications', 'sfs-hr' ); ?></label></th>
                    <td><label><input type="checkbox" name="notif_enabled" id="notif_enabled" value="1" <?php checked( $enabled ); ?> /> <?php esc_html_e( 'Yes, send email notifications for HR events', 'sfs-hr' ); ?></label></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Channels', 'sfs-hr' ); ?></label></th>
                    <td>
                        <label style="margin-right:15px;"><input type="checkbox" name="channels[]" value="email" <?php checked( $channels['email'] ?? true ); ?> /> <?php esc_html_e( 'Email', 'sfs-hr' ); ?></label>
                        <label><input type="checkbox" name="channels[]" value="sms" <?php checked( $channels['sms'] ?? false ); ?> /> <?php esc_html_e( 'SMS', 'sfs-hr' ); ?> <small style="color:#666;">(<?php esc_html_e( 'requires provider setup in Settings', 'sfs-hr' ); ?>)</small></label>
                    </td>
                </tr>
                <tr>
                    <th><label for="hr_emails"><?php esc_html_e( 'HR Team Emails', 'sfs-hr' ); ?></label></th>
                    <td>
                        <textarea name="hr_emails" id="hr_emails" rows="3" class="regular-text" placeholder="hr@company.com&#10;admin@company.com"><?php echo esc_textarea( implode( "\n", $hr_emails ) ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One email per line. These addresses receive HR notifications.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php $this->render_nav_buttons( 'notifications' ); ?>
        </form>
        <?php
    }

    /* ───────── Step 7: Done ───────── */

    private function render_step_done(): void {
        $p = Company_Profile::get();
        $finish_url = wp_nonce_url( admin_url( 'admin-post.php?action=sfs_hr_wizard_finish' ), 'sfs_hr_wizard_finish' );
        ?>
        <div style="text-align:center;padding:20px 0;">
            <div style="font-size:48px;margin-bottom:10px;">&#10003;</div>
            <h2 style="margin:0 0 10px;"><?php esc_html_e( 'Setup Complete!', 'sfs-hr' ); ?></h2>
            <p style="color:#666;font-size:14px;max-width:450px;margin:0 auto 25px;">
                <?php
                if ( ! empty( $p['company_name'] ) ) {
                    printf(
                        esc_html__( '%s is now configured. You can change any of these settings at any time.', 'sfs-hr' ),
                        '<strong>' . esc_html( $p['company_name'] ) . '</strong>'
                    );
                } else {
                    esc_html_e( 'Your HR Suite is now configured. You can change any of these settings at any time.', 'sfs-hr' );
                }
                ?>
            </p>

            <h3 style="margin-bottom:15px;"><?php esc_html_e( 'What to do next', 'sfs-hr' ); ?></h3>
            <div style="display:flex;justify-content:center;gap:12px;flex-wrap:wrap;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-employees' ) ); ?>" class="button button-large">
                    <?php esc_html_e( 'Add Employees', 'sfs-hr' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-company-profile' ) ); ?>" class="button button-large">
                    <?php esc_html_e( 'Company Profile', 'sfs-hr' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-settings' ) ); ?>" class="button button-large">
                    <?php esc_html_e( 'Settings', 'sfs-hr' ); ?>
                </a>
            </div>

            <div style="margin-top:25px;">
                <a href="<?php echo esc_url( $finish_url ); ?>" class="button button-primary button-large"><?php esc_html_e( 'Go to Dashboard', 'sfs-hr' ); ?></a>
            </div>
        </div>
        <?php
    }

    /* ───────── shared nav buttons ───────── */

    private function render_nav_buttons( string $current_step ): void {
        $idx = array_search( $current_step, self::STEPS, true );
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:15px;border-top:1px solid #ddd;">
            <?php if ( $idx > 0 ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sfs-hr-setup-wizard&step=' . self::STEPS[ $idx - 1 ] ) ); ?>" class="button">
                    &larr; <?php esc_html_e( 'Back', 'sfs-hr' ); ?>
                </a>
            <?php else : ?>
                <span></span>
            <?php endif; ?>

            <button type="submit" class="button button-primary button-large">
                <?php esc_html_e( 'Save & Continue', 'sfs-hr' ); ?> &rarr;
            </button>
        </div>
        <?php
    }

    /* ───────── preset holidays ───────── */

    private static function get_preset_holidays( string $country ): array {
        $year = (int) wp_date( 'Y' );

        if ( $country === 'sa' ) {
            return [
                [ 'name' => __( 'Founding Day', 'sfs-hr' ),        'date' => $year . '-02-22', 'repeat_yearly' => 1 ],
                [ 'name' => __( 'Eid Al-Fitr', 'sfs-hr' ),         'date' => $year . '-03-30', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Eid Al-Fitr Day 2', 'sfs-hr' ),   'date' => $year . '-03-31', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Eid Al-Fitr Day 3', 'sfs-hr' ),   'date' => $year . '-04-01', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Arafat Day', 'sfs-hr' ),          'date' => $year . '-06-06', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Eid Al-Adha', 'sfs-hr' ),         'date' => $year . '-06-07', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Eid Al-Adha Day 2', 'sfs-hr' ),   'date' => $year . '-06-08', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Eid Al-Adha Day 3', 'sfs-hr' ),   'date' => $year . '-06-09', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Saudi National Day', 'sfs-hr' ),  'date' => $year . '-09-23', 'repeat_yearly' => 1 ],
            ];
        }

        if ( $country === 'ae' ) {
            return [
                [ 'name' => __( 'New Year', 'sfs-hr' ),            'date' => $year . '-01-01', 'repeat_yearly' => 1 ],
                [ 'name' => __( 'Eid Al-Fitr', 'sfs-hr' ),         'date' => $year . '-03-30', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Eid Al-Fitr Day 2', 'sfs-hr' ),   'date' => $year . '-03-31', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Eid Al-Fitr Day 3', 'sfs-hr' ),   'date' => $year . '-04-01', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Arafat Day', 'sfs-hr' ),          'date' => $year . '-06-06', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Eid Al-Adha', 'sfs-hr' ),         'date' => $year . '-06-07', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Eid Al-Adha Day 2', 'sfs-hr' ),   'date' => $year . '-06-08', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Eid Al-Adha Day 3', 'sfs-hr' ),   'date' => $year . '-06-09', 'repeat_yearly' => 0 ],
                [ 'name' => __( 'Commemoration Day', 'sfs-hr' ),   'date' => $year . '-11-30', 'repeat_yearly' => 1 ],
                [ 'name' => __( 'UAE National Day', 'sfs-hr' ),    'date' => $year . '-12-02', 'repeat_yearly' => 1 ],
                [ 'name' => __( 'UAE National Day 2', 'sfs-hr' ),  'date' => $year . '-12-03', 'repeat_yearly' => 1 ],
            ];
        }

        return [];
    }
}
