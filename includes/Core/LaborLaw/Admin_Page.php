<?php
namespace SFS\HR\Core\LaborLaw;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Company_Profile;
use SFS\HR\Core\SocialInsurance\Social_Insurance_Service;

/**
 * Admin_Page (M8)
 *
 * "Gulf Compliance" admin screen. Three tabs:
 *   1. Labor Law — read-only overview of the active country's rules.
 *   2. Social Insurance — list/edit statutory contribution schemes.
 *   3. Hijri Settings — toggle Hijri display + Ramadan override.
 *
 * @since M8
 */
class Admin_Page {

    const MENU_SLUG    = 'sfs-hr-gulf-compliance';
    const OPT_HIJRI    = 'sfs_hr_hijri_display';

    public function hooks(): void {
        add_action( 'admin_menu', [ $this, 'menu' ], 12 );
        add_action( 'admin_post_sfs_hr_social_insurance_save', [ $this, 'handle_social_insurance_save' ] );
        add_action( 'admin_post_sfs_hr_hijri_save', [ $this, 'handle_hijri_save' ] );
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __( 'Gulf Compliance', 'sfs-hr' ),
            __( 'Gulf Compliance', 'sfs-hr' ),
            'sfs_hr.manage',
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'labor_law';
        $profile = Company_Profile::get();
        $country = (string) ( $profile['country'] ?? 'SA' );

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e( 'Gulf Compliance', 'sfs-hr' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'labor_law' ], admin_url( 'admin.php' ) ) ); ?>"
                   class="nav-tab <?php echo $tab === 'labor_law' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Labor Law', 'sfs-hr' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'social_insurance' ], admin_url( 'admin.php' ) ) ); ?>"
                   class="nav-tab <?php echo $tab === 'social_insurance' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Social Insurance', 'sfs-hr' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'hijri' ], admin_url( 'admin.php' ) ) ); ?>"
                   class="nav-tab <?php echo $tab === 'hijri' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Hijri Calendar', 'sfs-hr' ); ?>
                </a>
            </nav>

            <?php if ( ! empty( $_GET['ok'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>

            <div style="margin-top:20px;">
                <?php
                switch ( $tab ) {
                    case 'social_insurance':
                        $this->render_social_insurance();
                        break;
                    case 'hijri':
                        $this->render_hijri();
                        break;
                    case 'labor_law':
                    default:
                        $this->render_labor_law( $country );
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /* ───────── Labor Law tab ───────── */

    private function render_labor_law( string $country ): void {
        $strategy = Labor_Law_Service::for_country( $country );
        $all      = Labor_Law_Service::describe_all();

        ?>
        <p><?php
            printf(
                /* translators: %s: country name */
                esc_html__( 'Active country: %s. Change your country in Company Profile to switch rule sets.', 'sfs-hr' ),
                '<strong>' . esc_html( $strategy->country_name() ) . '</strong>'
            );
        ?></p>

        <h2><?php esc_html_e( 'Current Rules', 'sfs-hr' ); ?></h2>
        <table class="widefat striped" style="max-width:900px;">
            <tbody>
                <tr>
                    <th style="width:220px;"><?php esc_html_e( 'Gratuity Formula', 'sfs-hr' ); ?></th>
                    <td><?php echo esc_html( $strategy->gratuity_formula_description() ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Annual Leave (< 1 yr tenure)', 'sfs-hr' ); ?></th>
                    <td><?php echo esc_html( $strategy->annual_leave_days( 0.5 ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Annual Leave (1–5 yrs)', 'sfs-hr' ); ?></th>
                    <td><?php echo esc_html( $strategy->annual_leave_days( 3 ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Annual Leave (5+ yrs)', 'sfs-hr' ); ?></th>
                    <td><?php echo esc_html( $strategy->annual_leave_days( 7 ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Probation Period (days)', 'sfs-hr' ); ?></th>
                    <td><?php echo esc_html( $strategy->probation_period_days() ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Notice (probation)', 'sfs-hr' ); ?></th>
                    <td><?php echo esc_html( $strategy->notice_period_days( 'probation' ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Notice (permanent)', 'sfs-hr' ); ?></th>
                    <td><?php echo esc_html( $strategy->notice_period_days( 'permanent' ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Sick Leave Bands', 'sfs-hr' ); ?></th>
                    <td>
                        <?php foreach ( $strategy->sick_leave_bands() as $band ) : ?>
                            <?php echo esc_html( sprintf( '%d days @ %s%%', (int) $band['days'], (string) $band['pay_percentage'] ) ); ?><br />
                        <?php endforeach; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 style="margin-top:30px;"><?php esc_html_e( 'Supported Countries', 'sfs-hr' ); ?></h2>
        <table class="widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th style="width:60px;"><?php esc_html_e( 'Code', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Country', 'sfs-hr' ); ?></th>
                    <th><?php esc_html_e( 'Gratuity Formula', 'sfs-hr' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $all as $code => $info ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $info['code'] ); ?></code></td>
                        <td><?php echo esc_html( $info['name'] ); ?></td>
                        <td><?php echo esc_html( $info['formula'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ───────── Social Insurance tab ───────── */

    private function render_social_insurance(): void {
        $rows = Social_Insurance_Service::list_all();

        ?>
        <h2><?php esc_html_e( 'Social Insurance Schemes', 'sfs-hr' ); ?></h2>
        <p><?php esc_html_e( 'Statutory contribution rates per country. Seeded on activation with regional defaults — adjust to match current law.', 'sfs-hr' ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="sfs_hr_social_insurance_save" />
            <?php wp_nonce_field( 'sfs_hr_social_insurance_save', '_sfs_nonce' ); ?>

            <table class="widefat striped" style="max-width:1200px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Country', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Scheme', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Employee %', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Employer %', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Applies To', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Ceiling', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Floor', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Active', 'sfs-hr' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="8"><?php esc_html_e( 'No schemes yet. Deactivate and reactivate the plugin to seed defaults.', 'sfs-hr' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $r ) : $id = (int) $r['id']; ?>
                            <tr>
                                <td><code><?php echo esc_html( $r['country_code'] ); ?></code></td>
                                <td>
                                    <strong><?php echo esc_html( $r['scheme_name'] ); ?></strong><br />
                                    <small><code><?php echo esc_html( $r['scheme_code'] ); ?></code></small>
                                </td>
                                <td><input type="number" step="0.01" min="0" max="100" name="schemes[<?php echo esc_attr( $id ); ?>][employee_rate]" value="<?php echo esc_attr( $r['employee_rate'] ); ?>" style="width:80px;" /></td>
                                <td><input type="number" step="0.01" min="0" max="100" name="schemes[<?php echo esc_attr( $id ); ?>][employer_rate]" value="<?php echo esc_attr( $r['employer_rate'] ); ?>" style="width:80px;" /></td>
                                <td>
                                    <select name="schemes[<?php echo esc_attr( $id ); ?>][applies_to]">
                                        <?php foreach ( [ 'all', 'nationals_only', 'none' ] as $opt ) : ?>
                                            <option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $r['applies_to'], $opt ); ?>><?php echo esc_html( str_replace( '_', ' ', $opt ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" step="0.01" min="0" name="schemes[<?php echo esc_attr( $id ); ?>][ceiling]" value="<?php echo esc_attr( $r['ceiling'] ); ?>" style="width:120px;" /></td>
                                <td><input type="number" step="0.01" min="0" name="schemes[<?php echo esc_attr( $id ); ?>][floor]" value="<?php echo esc_attr( $r['floor'] ); ?>" style="width:120px;" /></td>
                                <td><input type="checkbox" name="schemes[<?php echo esc_attr( $id ); ?>][is_active]" value="1" <?php checked( (int) $r['is_active'], 1 ); ?> /></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php submit_button( __( 'Save Social Insurance Settings', 'sfs-hr' ) ); ?>
        </form>
        <?php
    }

    /* ───────── Hijri tab ───────── */

    private function render_hijri(): void {
        $settings = wp_parse_args( get_option( self::OPT_HIJRI, [] ), [
            'enabled'             => 0,
            'show_alongside'      => 1,
            'ramadan_short_hours' => 1,
        ] );

        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="sfs_hr_hijri_save" />
            <?php wp_nonce_field( 'sfs_hr_hijri_save', '_sfs_nonce' ); ?>

            <h2><?php esc_html_e( 'Hijri Calendar Display', 'sfs-hr' ); ?></h2>
            <table class="form-table" style="max-width:900px;">
                <tr>
                    <th><?php esc_html_e( 'Enable Hijri display', 'sfs-hr' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked( (int) $settings['enabled'], 1 ); ?> />
                            <?php esc_html_e( 'Show Hijri dates in date displays', 'sfs-hr' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Display mode', 'sfs-hr' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_alongside" value="1" <?php checked( (int) $settings['show_alongside'], 1 ); ?> />
                            <?php esc_html_e( 'Show Hijri alongside Gregorian (recommended)', 'sfs-hr' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Ramadan short working hours', 'sfs-hr' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ramadan_short_hours" value="1" <?php checked( (int) $settings['ramadan_short_hours'], 1 ); ?> />
                            <?php esc_html_e( 'Automatically apply 6-hour workday during Ramadan via shift period overrides', 'sfs-hr' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'When enabled, Hijri_Service::ramadan_range_for_year() is used to detect Ramadan dates each year. Attendance shift period overrides still need to be configured for each shift.', 'sfs-hr' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Intl extension', 'sfs-hr' ); ?></th>
                    <td>
                        <?php if ( Hijri_Service::intl_available() ) : ?>
                            <span style="color:#46b450;">✓</span> <?php esc_html_e( 'PHP Intl extension available — using Umm al-Qura calendar.', 'sfs-hr' ); ?>
                        <?php else : ?>
                            <span style="color:#d63638;">!</span> <?php esc_html_e( 'PHP Intl extension missing — using arithmetic approximation (±1 day).', 'sfs-hr' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Hijri Settings', 'sfs-hr' ) ); ?>
        </form>
        <?php
    }

    /* ───────── Handlers ───────── */

    public function handle_social_insurance_save(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_social_insurance_save', '_sfs_nonce' );

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_social_insurance_schemes';
        $now   = current_time( 'mysql' );

        $schemes = isset( $_POST['schemes'] ) && is_array( $_POST['schemes'] ) ? wp_unslash( $_POST['schemes'] ) : [];
        foreach ( $schemes as $id => $vals ) {
            $id = (int) $id;
            if ( $id <= 0 || ! is_array( $vals ) ) {
                continue;
            }

            $applies_to = isset( $vals['applies_to'] ) ? sanitize_text_field( (string) $vals['applies_to'] ) : 'nationals_only';
            if ( ! in_array( $applies_to, [ 'all', 'nationals_only', 'none' ], true ) ) {
                $applies_to = 'nationals_only';
            }

            $ceiling_raw = isset( $vals['ceiling'] ) ? (string) $vals['ceiling'] : '';
            $floor_raw   = isset( $vals['floor'] )   ? (string) $vals['floor']   : '';
            $ceiling     = $ceiling_raw !== '' ? (float) $ceiling_raw : null;
            $floor       = $floor_raw   !== '' ? (float) $floor_raw   : null;

            // Handle nullable ceiling/floor separately — $wpdb->update() with
            // %f format would coerce NULL to 0.0, silently zeroing out the
            // contribution base and under-deducting statutory contributions.
            if ( $ceiling === null || $floor === null ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table}
                     SET ceiling = " . ( $ceiling === null ? 'NULL' : '%f' ) . ",
                         floor   = " . ( $floor   === null ? 'NULL' : '%f' ) . "
                     WHERE id = %d",
                    ...array_merge(
                        $ceiling === null ? [] : [ $ceiling ],
                        $floor   === null ? [] : [ $floor ],
                        [ $id ]
                    )
                ) );
            }

            $data = [
                'employee_rate' => max( 0.0, min( 100.0, (float) ( $vals['employee_rate'] ?? 0 ) ) ),
                'employer_rate' => max( 0.0, min( 100.0, (float) ( $vals['employer_rate'] ?? 0 ) ) ),
                'applies_to'    => $applies_to,
                'is_active'     => ! empty( $vals['is_active'] ) ? 1 : 0,
                'updated_at'    => $now,
            ];
            $formats = [ '%f', '%f', '%s', '%d', '%s' ];

            if ( $ceiling !== null ) {
                $data['ceiling'] = $ceiling;
                $formats[]       = '%f';
            }
            if ( $floor !== null ) {
                $data['floor'] = $floor;
                $formats[]     = '%f';
            }

            $wpdb->update( $table, $data, [ 'id' => $id ], $formats, [ '%d' ] );
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'tab' => 'social_insurance', 'ok' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function handle_hijri_save(): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_hijri_save', '_sfs_nonce' );

        $settings = [
            'enabled'             => ! empty( $_POST['enabled'] )             ? 1 : 0,
            'show_alongside'      => ! empty( $_POST['show_alongside'] )      ? 1 : 0,
            'ramadan_short_hours' => ! empty( $_POST['ramadan_short_hours'] ) ? 1 : 0,
        ];
        update_option( self::OPT_HIJRI, $settings );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'tab' => 'hijri', 'ok' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
