<?php
namespace SFS\HR\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Company_Profile
 *
 * Dedicated admin page for storing and displaying company/organisation details.
 * Data is persisted in wp_options under the key 'sfs_hr_company_profile'.
 *
 * @since 1.4.2
 */
class Company_Profile {

    /** Option key. */
    const OPTION_KEY = 'sfs_hr_company_profile';

    /** Default field values. */
    private static array $defaults = [
        'company_name'   => '',
        'legal_name'     => '',
        'cr_number'      => '',
        'employer_code'  => '',
        'bank_code'      => '',
        'address'        => '',
        'city'           => '',
        'country'        => 'SA',
        'phone'          => '',
        'email'          => '',
        'website'        => '',
        'logo_id'        => 0,
        'timezone'       => '',
        'currency'       => 'SAR',
        'fiscal_year_start' => '01',
    ];

    /* ───────── bootstrap ───────── */

    public function hooks(): void {
        add_action( 'admin_menu',        [ $this, 'menu' ], 11 );
        add_action( 'admin_post_sfs_hr_save_company_profile', [ $this, 'handle_save' ] );
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __( 'Company Profile', 'sfs-hr' ),
            __( 'Company Profile', 'sfs-hr' ),
            'manage_options',
            'sfs-hr-company-profile',
            [ $this, 'render_page' ]
        );
    }

    /* ───────── data helpers ───────── */

    /**
     * Get full company profile with defaults merged.
     */
    public static function get(): array {
        $stored = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }
        $profile = wp_parse_args( $stored, self::$defaults );

        // Fall-back: honour legacy options if profile fields are still empty.
        if ( empty( $profile['employer_code'] ) ) {
            $profile['employer_code'] = get_option( 'sfs_hr_employer_code', '' );
        }
        if ( empty( $profile['bank_code'] ) ) {
            $profile['bank_code'] = get_option( 'sfs_hr_bank_code', '' );
        }
        if ( empty( $profile['timezone'] ) ) {
            $profile['timezone'] = wp_timezone_string();
        }
        return $profile;
    }

    /**
     * Update company profile (partial or full).
     */
    public static function update( array $data ): void {
        $current = self::get();
        $merged  = wp_parse_args( $data, $current );
        update_option( self::OPTION_KEY, $merged );

        // Keep legacy options in sync for backward compatibility.
        if ( ! empty( $merged['employer_code'] ) ) {
            update_option( 'sfs_hr_employer_code', $merged['employer_code'] );
        }
        if ( ! empty( $merged['bank_code'] ) ) {
            update_option( 'sfs_hr_bank_code', $merged['bank_code'] );
        }
    }

    /* ───────── save handler ───────── */

    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }
        check_admin_referer( 'sfs_hr_company_profile', '_sfs_nonce' );

        $fields = [
            'company_name', 'legal_name', 'cr_number', 'employer_code', 'bank_code',
            'address', 'city', 'country', 'phone', 'email', 'website',
            'timezone', 'currency', 'fiscal_year_start',
        ];

        $data = [];
        foreach ( $fields as $f ) {
            $data[ $f ] = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
        }
        $data['logo_id'] = isset( $_POST['logo_id'] ) ? absint( $_POST['logo_id'] ) : 0;

        self::update( $data );

        wp_safe_redirect( add_query_arg( 'ok', '1', admin_url( 'admin.php?page=sfs-hr-company-profile' ) ) );
        exit;
    }

    /* ───────── render ───────── */

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ) );
        }

        $p = self::get();
        $logo_url = $p['logo_id'] ? wp_get_attachment_image_url( $p['logo_id'], 'medium' ) : '';

        ?>
        <div class="wrap sfs-hr-wrap">
            <?php Helpers::render_admin_nav(); ?>

            <h1><?php esc_html_e( 'Company Profile', 'sfs-hr' ); ?></h1>

            <?php if ( ! empty( $_GET['ok'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Company profile saved.', 'sfs-hr' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="sfs_hr_save_company_profile" />
                <?php wp_nonce_field( 'sfs_hr_company_profile', '_sfs_nonce' ); ?>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;max-width:900px;">
                    <!-- Left Column -->
                    <div>
                        <h2><?php esc_html_e( 'Organisation', 'sfs-hr' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="company_name"><?php esc_html_e( 'Company Name', 'sfs-hr' ); ?></label></th>
                                <td><input type="text" id="company_name" name="company_name" class="regular-text" value="<?php echo esc_attr( $p['company_name'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th><label for="legal_name"><?php esc_html_e( 'Legal Name', 'sfs-hr' ); ?></label></th>
                                <td><input type="text" id="legal_name" name="legal_name" class="regular-text" value="<?php echo esc_attr( $p['legal_name'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th><label for="cr_number"><?php esc_html_e( 'CR Number', 'sfs-hr' ); ?></label></th>
                                <td><input type="text" id="cr_number" name="cr_number" class="regular-text" value="<?php echo esc_attr( $p['cr_number'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th><label for="employer_code"><?php esc_html_e( 'Employer Code (MOL)', 'sfs-hr' ); ?></label></th>
                                <td><input type="text" id="employer_code" name="employer_code" class="regular-text" value="<?php echo esc_attr( $p['employer_code'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th><label for="bank_code"><?php esc_html_e( 'Bank Code', 'sfs-hr' ); ?></label></th>
                                <td><input type="text" id="bank_code" name="bank_code" class="regular-text" value="<?php echo esc_attr( $p['bank_code'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th><label><?php esc_html_e( 'Logo', 'sfs-hr' ); ?></label></th>
                                <td>
                                    <input type="hidden" id="logo_id" name="logo_id" value="<?php echo esc_attr( $p['logo_id'] ); ?>" />
                                    <div id="sfs-logo-preview" style="margin-bottom:8px;">
                                        <?php if ( $logo_url ) : ?>
                                            <img src="<?php echo esc_url( $logo_url ); ?>" style="max-height:80px;" />
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="button" id="sfs-upload-logo"><?php esc_html_e( 'Choose Logo', 'sfs-hr' ); ?></button>
                                    <button type="button" class="button" id="sfs-remove-logo" style="<?php echo $logo_url ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'sfs-hr' ); ?></button>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Right Column -->
                    <div>
                        <h2><?php esc_html_e( 'Contact & Location', 'sfs-hr' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th><label for="address"><?php esc_html_e( 'Address', 'sfs-hr' ); ?></label></th>
                                <td><textarea id="address" name="address" rows="3" class="regular-text"><?php echo esc_textarea( $p['address'] ); ?></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="city"><?php esc_html_e( 'City', 'sfs-hr' ); ?></label></th>
                                <td><input type="text" id="city" name="city" class="regular-text" value="<?php echo esc_attr( $p['city'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th><label for="country"><?php esc_html_e( 'Country', 'sfs-hr' ); ?></label></th>
                                <td>
                                    <select id="country" name="country">
                                        <?php foreach ( self::get_countries() as $code => $name ) : ?>
                                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $p['country'], $code ); ?>><?php echo esc_html( $name ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="phone"><?php esc_html_e( 'Phone', 'sfs-hr' ); ?></label></th>
                                <td><input type="text" id="phone" name="phone" class="regular-text" value="<?php echo esc_attr( $p['phone'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th><label for="email"><?php esc_html_e( 'Email', 'sfs-hr' ); ?></label></th>
                                <td><input type="email" id="email" name="email" class="regular-text" value="<?php echo esc_attr( $p['email'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th><label for="website"><?php esc_html_e( 'Website', 'sfs-hr' ); ?></label></th>
                                <td><input type="url" id="website" name="website" class="regular-text" value="<?php echo esc_attr( $p['website'] ); ?>" /></td>
                            </tr>
                            <tr>
                                <th><label for="timezone"><?php esc_html_e( 'Timezone', 'sfs-hr' ); ?></label></th>
                                <td>
                                    <select id="timezone" name="timezone">
                                        <?php echo wp_timezone_choice( $p['timezone'] ); ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="currency"><?php esc_html_e( 'Currency', 'sfs-hr' ); ?></label></th>
                                <td>
                                    <select id="currency" name="currency">
                                        <?php foreach ( self::get_currencies() as $code => $label ) : ?>
                                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $p['currency'], $code ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="fiscal_year_start"><?php esc_html_e( 'Fiscal Year Start', 'sfs-hr' ); ?></label></th>
                                <td>
                                    <select id="fiscal_year_start" name="fiscal_year_start">
                                        <?php for ( $m = 1; $m <= 12; $m++ ) : $mv = str_pad( $m, 2, '0', STR_PAD_LEFT ); ?>
                                            <option value="<?php echo esc_attr( $mv ); ?>" <?php selected( $p['fiscal_year_start'], $mv ); ?>><?php echo esc_html( date_i18n( 'F', mktime( 0, 0, 0, $m, 1 ) ) ); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <?php submit_button( __( 'Save Company Profile', 'sfs-hr' ) ); ?>
            </form>
        </div>

        <script>
        jQuery(function($){
            var frame;
            $('#sfs-upload-logo').on('click',function(e){
                e.preventDefault();
                if(frame){frame.open();return;}
                frame=wp.media({title:'<?php echo esc_js( __( 'Choose Logo', 'sfs-hr' ) ); ?>',button:{text:'<?php echo esc_js( __( 'Use as Logo', 'sfs-hr' ) ); ?>'},multiple:false});
                frame.on('select',function(){
                    var a=frame.state().get('selection').first().toJSON();
                    $('#logo_id').val(a.id);
                    $('#sfs-logo-preview').html('<img src="'+a.url+'" style="max-height:80px;" />');
                    $('#sfs-remove-logo').show();
                });
                frame.open();
            });
            $('#sfs-remove-logo').on('click',function(e){
                e.preventDefault();
                $('#logo_id').val(0);
                $('#sfs-logo-preview').html('');
                $(this).hide();
            });
        });
        </script>
        <?php
        wp_enqueue_media();
    }

    /* ───────── static data ───────── */

    public static function get_countries_public(): array {
        return self::get_countries();
    }

    public static function get_currencies_public(): array {
        return self::get_currencies();
    }

    private static function get_countries(): array {
        return [
            'SA' => __( 'Saudi Arabia', 'sfs-hr' ),
            'AE' => __( 'United Arab Emirates', 'sfs-hr' ),
            'BH' => __( 'Bahrain', 'sfs-hr' ),
            'KW' => __( 'Kuwait', 'sfs-hr' ),
            'OM' => __( 'Oman', 'sfs-hr' ),
            'QA' => __( 'Qatar', 'sfs-hr' ),
            'EG' => __( 'Egypt', 'sfs-hr' ),
            'JO' => __( 'Jordan', 'sfs-hr' ),
            'LB' => __( 'Lebanon', 'sfs-hr' ),
            'IQ' => __( 'Iraq', 'sfs-hr' ),
            'PK' => __( 'Pakistan', 'sfs-hr' ),
            'IN' => __( 'India', 'sfs-hr' ),
            'PH' => __( 'Philippines', 'sfs-hr' ),
            'BD' => __( 'Bangladesh', 'sfs-hr' ),
            'NP' => __( 'Nepal', 'sfs-hr' ),
            'US' => __( 'United States', 'sfs-hr' ),
            'GB' => __( 'United Kingdom', 'sfs-hr' ),
            'OTHER' => __( 'Other', 'sfs-hr' ),
        ];
    }

    private static function get_currencies(): array {
        return [
            'SAR' => 'SAR – ' . __( 'Saudi Riyal', 'sfs-hr' ),
            'AED' => 'AED – ' . __( 'UAE Dirham', 'sfs-hr' ),
            'BHD' => 'BHD – ' . __( 'Bahraini Dinar', 'sfs-hr' ),
            'KWD' => 'KWD – ' . __( 'Kuwaiti Dinar', 'sfs-hr' ),
            'OMR' => 'OMR – ' . __( 'Omani Rial', 'sfs-hr' ),
            'QAR' => 'QAR – ' . __( 'Qatari Riyal', 'sfs-hr' ),
            'EGP' => 'EGP – ' . __( 'Egyptian Pound', 'sfs-hr' ),
            'JOD' => 'JOD – ' . __( 'Jordanian Dinar', 'sfs-hr' ),
            'USD' => 'USD – ' . __( 'US Dollar', 'sfs-hr' ),
            'EUR' => 'EUR – ' . __( 'Euro', 'sfs-hr' ),
            'GBP' => 'GBP – ' . __( 'British Pound', 'sfs-hr' ),
            'PKR' => 'PKR – ' . __( 'Pakistani Rupee', 'sfs-hr' ),
            'INR' => 'INR – ' . __( 'Indian Rupee', 'sfs-hr' ),
            'PHP' => 'PHP – ' . __( 'Philippine Peso', 'sfs-hr' ),
        ];
    }
}
