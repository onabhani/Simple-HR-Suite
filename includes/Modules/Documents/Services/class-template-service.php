<?php
namespace SFS\HR\Modules\Documents\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Template Service — M6.3 Document Letter Templates
 *
 * Manages document templates (employment certificates, salary letters, NOCs, etc.)
 * with merge-field substitution for employee-specific rendering.
 */
class Template_Service {

    /**
     * Table name (without prefix).
     */
    private const TABLE = 'sfs_hr_document_templates';

    /**
     * Get the fully-prefixed table name.
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * List all templates, optionally filtered to active only.
     *
     * @param bool $active_only Only return is_active = 1 rows.
     * @return array
     */
    public static function get_templates( bool $active_only = true ): array {
        global $wpdb;
        $table = self::table();

        $sql = "SELECT * FROM {$table}";
        if ( $active_only ) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY category ASC, name_en ASC";

        return $wpdb->get_results( $sql );
    }

    /**
     * Get a single template by primary key.
     *
     * @param int $id Template ID.
     * @return object|null
     */
    public static function get_template( int $id ): ?object {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ) );
    }

    /**
     * Get a single template by its unique key.
     *
     * @param string $key Template key (e.g. 'employment_certificate').
     * @return object|null
     */
    public static function get_template_by_key( string $key ): ?object {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE template_key = %s",
            $key
        ) );
    }

    /**
     * Create a new template.
     *
     * @param array $data Associative array of column values.
     * @return array ['ok' => bool, 'template_id' => int] or ['ok' => false, 'error' => string]
     */
    public static function create_template( array $data ): array {
        global $wpdb;
        $table = self::table();

        $required = [ 'template_key', 'name_en', 'name_ar', 'body_en', 'body_ar' ];
        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                return [ 'ok' => false, 'error' => sprintf( __( 'Missing required field: %s', 'sfs-hr' ), $field ) ];
            }
        }

        // Check uniqueness of template_key.
        $existing = self::get_template_by_key( $data['template_key'] );
        if ( $existing ) {
            return [ 'ok' => false, 'error' => __( 'A template with this key already exists.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );

        $insert = [
            'template_key' => sanitize_key( $data['template_key'] ),
            'name_en'      => sanitize_text_field( $data['name_en'] ),
            'name_ar'      => sanitize_text_field( $data['name_ar'] ),
            'body_en'      => wp_kses_post( $data['body_en'] ),
            'body_ar'      => wp_kses_post( $data['body_ar'] ),
            'category'     => in_array( $data['category'] ?? 'letter', [ 'certificate', 'letter', 'notice', 'contract' ], true )
                                ? $data['category'] : 'letter',
            'is_active'    => 1,
            'created_by'   => get_current_user_id() ?: null,
            'updated_by'   => get_current_user_id() ?: null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        $formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ];

        $result = $wpdb->insert( $table, $insert, $formats );

        if ( false === $result ) {
            return [ 'ok' => false, 'error' => __( 'Database insert failed.', 'sfs-hr' ) ];
        }

        return [ 'ok' => true, 'template_id' => (int) $wpdb->insert_id ];
    }

    /**
     * Update an existing template.
     *
     * @param int   $id   Template ID.
     * @param array $data Fields to update.
     * @return array ['ok' => bool] or ['ok' => false, 'error' => string]
     */
    public static function update_template( int $id, array $data ): array {
        global $wpdb;
        $table = self::table();

        $template = self::get_template( $id );
        if ( ! $template ) {
            return [ 'ok' => false, 'error' => __( 'Template not found.', 'sfs-hr' ) ];
        }

        $allowed = [ 'name_en', 'name_ar', 'body_en', 'body_ar', 'category', 'is_active' ];
        $update  = [];
        $formats = [];

        foreach ( $allowed as $col ) {
            if ( ! array_key_exists( $col, $data ) ) {
                continue;
            }
            switch ( $col ) {
                case 'name_en':
                case 'name_ar':
                    $update[ $col ] = sanitize_text_field( $data[ $col ] );
                    $formats[]      = '%s';
                    break;
                case 'body_en':
                case 'body_ar':
                    $update[ $col ] = wp_kses_post( $data[ $col ] );
                    $formats[]      = '%s';
                    break;
                case 'category':
                    if ( in_array( $data['category'], [ 'certificate', 'letter', 'notice', 'contract' ], true ) ) {
                        $update['category'] = $data['category'];
                        $formats[]          = '%s';
                    }
                    break;
                case 'is_active':
                    $update['is_active'] = (int) (bool) $data['is_active'];
                    $formats[]           = '%d';
                    break;
            }
        }

        if ( empty( $update ) ) {
            return [ 'ok' => false, 'error' => __( 'No valid fields to update.', 'sfs-hr' ) ];
        }

        $update['updated_by'] = get_current_user_id() ?: null;
        $formats[]            = '%d';
        $update['updated_at'] = current_time( 'mysql' );
        $formats[]            = '%s';

        $result = $wpdb->update( $table, $update, [ 'id' => $id ], $formats, [ '%d' ] );

        if ( false === $result ) {
            return [ 'ok' => false, 'error' => __( 'Database update failed.', 'sfs-hr' ) ];
        }

        return [ 'ok' => true ];
    }

    /**
     * Soft-delete a template (set is_active = 0).
     *
     * @param int $id Template ID.
     * @return array ['ok' => bool] or error.
     */
    public static function delete_template( int $id ): array {
        return self::update_template( $id, [ 'is_active' => 0 ] );
    }

    // -------------------------------------------------------------------------
    // Merge Fields
    // -------------------------------------------------------------------------

    /**
     * Return the list of available merge fields with descriptions, grouped by category.
     *
     * @return array Grouped merge field definitions.
     */
    public static function get_merge_fields(): array {
        return [
            'employee' => [
                '{employee_name}'    => __( 'Employee full name (English)', 'sfs-hr' ),
                '{employee_name_ar}' => __( 'Employee full name (Arabic)', 'sfs-hr' ),
                '{employee_code}'    => __( 'Employee code', 'sfs-hr' ),
                '{designation}'      => __( 'Job title / designation', 'sfs-hr' ),
                '{department}'       => __( 'Department name', 'sfs-hr' ),
                '{hire_date}'        => __( 'Hire date (Y-m-d)', 'sfs-hr' ),
                '{id_number}'        => __( 'National ID / Iqama number', 'sfs-hr' ),
                '{passport_number}'  => __( 'Passport number', 'sfs-hr' ),
                '{nationality}'      => __( 'Nationality', 'sfs-hr' ),
                '{basic_salary}'     => __( 'Basic salary', 'sfs-hr' ),
                '{total_salary}'     => __( 'Total salary (basic + allowances)', 'sfs-hr' ),
            ],
            'company' => [
                '{company_name}'    => __( 'Company name (English)', 'sfs-hr' ),
                '{company_name_ar}' => __( 'Company name (Arabic)', 'sfs-hr' ),
                '{company_address}' => __( 'Company address', 'sfs-hr' ),
                '{company_cr}'      => __( 'Commercial Registration number', 'sfs-hr' ),
                '{company_phone}'   => __( 'Company phone', 'sfs-hr' ),
                '{company_email}'   => __( 'Company email', 'sfs-hr' ),
            ],
            'dates' => [
                '{current_date}'           => __( 'Current date (Y-m-d)', 'sfs-hr' ),
                '{current_date_hijri}'     => __( 'Current date in Hijri calendar', 'sfs-hr' ),
                '{current_date_formatted}' => __( 'Current date formatted (d/m/Y)', 'sfs-hr' ),
            ],
            'document' => [
                '{reference_number}' => __( 'Document reference number', 'sfs-hr' ),
                '{issue_date}'       => __( 'Document issue date', 'sfs-hr' ),
            ],
        ];
    }

    /**
     * Resolve all merge fields for a given employee.
     *
     * @param int   $employee_id Employee row ID.
     * @param array $extra_fields Additional or override key=>value pairs.
     * @return array Key => value map of all merge fields.
     */
    public static function resolve_merge_fields( int $employee_id, array $extra_fields = [] ): array {
        global $wpdb;

        // Fetch employee.
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $employee  = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$emp_table} WHERE id = %d",
            $employee_id
        ) );

        if ( ! $employee ) {
            return [];
        }

        // Fetch department name.
        $dept_name = '';
        if ( ! empty( $employee->department_id ) ) {
            $dept_table = $wpdb->prefix . 'sfs_hr_departments';
            $dept_name  = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM {$dept_table} WHERE id = %d",
                $employee->department_id
            ) );
        }

        // Company profile.
        $company = get_option( 'sfs_hr_company_profile', [] );
        if ( ! is_array( $company ) ) {
            $company = [];
        }

        // Calculate total salary.
        $basic     = (float) ( $employee->basic_salary ?? 0 );
        $housing   = (float) ( $employee->housing_allowance ?? 0 );
        $transport = (float) ( $employee->transport_allowance ?? 0 );
        $total     = $basic + $housing + $transport;

        // Build field map.
        $fields = [
            '{employee_name}'    => trim( ( $employee->first_name ?? '' ) . ' ' . ( $employee->last_name ?? '' ) ),
            '{employee_name_ar}' => trim( ( $employee->first_name_ar ?? '' ) . ' ' . ( $employee->last_name_ar ?? '' ) ),
            '{employee_code}'    => $employee->employee_code ?? '',
            '{designation}'      => $employee->designation ?? '',
            '{department}'       => $dept_name,
            '{hire_date}'        => $employee->hire_date ?? '',
            '{id_number}'        => $employee->id_number ?? '',
            '{passport_number}'  => $employee->passport_number ?? '',
            '{nationality}'      => $employee->nationality ?? '',
            '{basic_salary}'     => number_format( $basic, 2 ),
            '{total_salary}'     => number_format( $total, 2 ),

            '{company_name}'    => $company['company_name'] ?? '',
            '{company_name_ar}' => $company['company_name_ar'] ?? '',
            '{company_address}' => $company['address'] ?? '',
            '{company_cr}'      => $company['cr_number'] ?? '',
            '{company_phone}'   => $company['phone'] ?? '',
            '{company_email}'   => $company['email'] ?? '',

            '{current_date}'           => current_time( 'Y-m-d' ),
            '{current_date_hijri}'     => self::gregorian_to_hijri( current_time( 'Y-m-d' ) ),
            '{current_date_formatted}' => current_time( 'd/m/Y' ),

            '{reference_number}' => '',
            '{issue_date}'       => current_time( 'Y-m-d' ),
        ];

        // Allow overrides / additions.
        foreach ( $extra_fields as $key => $value ) {
            // Ensure braces around key for consistency.
            $k = '{' . trim( $key, '{}' ) . '}';
            $fields[ $k ] = $value;
        }

        return $fields;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render a template for a specific employee.
     *
     * @param int    $template_id  Template ID.
     * @param int    $employee_id  Employee ID.
     * @param string $lang         Language code ('en' or 'ar').
     * @param array  $extra_fields Extra merge field overrides.
     * @return array ['ok' => true, 'html' => string, 'name' => string] or error.
     */
    public static function render_template( int $template_id, int $employee_id, string $lang = 'en', array $extra_fields = [] ): array {
        $template = self::get_template( $template_id );
        if ( ! $template ) {
            return [ 'ok' => false, 'error' => __( 'Template not found.', 'sfs-hr' ) ];
        }

        $fields = self::resolve_merge_fields( $employee_id, $extra_fields );
        if ( empty( $fields ) ) {
            return [ 'ok' => false, 'error' => __( 'Employee not found.', 'sfs-hr' ) ];
        }

        $body = ( 'ar' === $lang ) ? $template->body_ar : $template->body_en;
        $name = ( 'ar' === $lang ) ? $template->name_ar : $template->name_en;

        $html = str_replace( array_keys( $fields ), array_values( $fields ), $body );

        // Fire audit action.
        do_action( 'sfs_hr_document_generated', $employee_id, $template->template_key, $lang );

        return [ 'ok' => true, 'html' => $html, 'name' => $name ];
    }

    /**
     * Generate a print-ready HTML page suitable for browser print-to-PDF.
     *
     * @param int    $template_id  Template ID.
     * @param int    $employee_id  Employee ID.
     * @param string $lang         Language code ('en' or 'ar').
     * @param array  $extra_fields Extra merge field overrides.
     * @return array ['ok' => true, 'html' => string] or error.
     */
    public static function generate_pdf( int $template_id, int $employee_id, string $lang = 'en', array $extra_fields = [] ): array {
        $rendered = self::render_template( $template_id, $employee_id, $lang, $extra_fields );
        if ( ! $rendered['ok'] ) {
            return $rendered;
        }

        $dir  = ( 'ar' === $lang ) ? 'rtl' : 'ltr';
        $font = ( 'ar' === $lang ) ? "'Noto Naskh Arabic', 'Traditional Arabic', serif" : "'Times New Roman', serif";

        $html = '<!DOCTYPE html>
<html lang="' . esc_attr( $lang ) . '" dir="' . $dir . '">
<head>
    <meta charset="UTF-8">
    <title>' . esc_html( $rendered['name'] ) . '</title>
    <style>
        @page {
            size: A4;
            margin: 2cm;
        }
        body {
            font-family: ' . $font . ';
            font-size: 14px;
            line-height: 1.8;
            color: #000;
            direction: ' . $dir . ';
            padding: 20px;
        }
        @media print {
            body { padding: 0; }
        }
        h1, h2, h3 { margin-top: 0; }
        .letterhead { text-align: center; margin-bottom: 30px; }
        .signature-block { margin-top: 60px; }
    </style>
</head>
<body>
' . $rendered['html'] . '
</body>
</html>';

        return [ 'ok' => true, 'html' => $html ];
    }

    // -------------------------------------------------------------------------
    // Seeding
    // -------------------------------------------------------------------------

    /**
     * Seed default templates. Idempotent — skips templates that already exist by key.
     */
    public static function seed_default_templates(): void {
        $defaults = self::get_default_templates();

        foreach ( $defaults as $tpl ) {
            $existing = self::get_template_by_key( $tpl['template_key'] );
            if ( $existing ) {
                continue;
            }
            self::create_template( $tpl );
        }
    }

    /**
     * Return the built-in default templates.
     *
     * @return array[]
     */
    private static function get_default_templates(): array {
        return [
            // --- Employment Certificate ---
            [
                'template_key' => 'employment_certificate',
                'name_en'      => 'Employment Certificate',
                'name_ar'      => 'شهادة تعريف بالعمل',
                'category'     => 'certificate',
                'body_en'      => '<div class="letterhead">
<h2>{company_name}</h2>
<p>{company_address}<br>CR: {company_cr} | Tel: {company_phone}</p>
</div>

<p><strong>Date:</strong> {current_date_formatted}</p>
<p><strong>Reference:</strong> {reference_number}</p>

<h3 style="text-align:center;">TO WHOM IT MAY CONCERN</h3>

<p>This is to certify that <strong>{employee_name}</strong>, holding ID/Iqama No. <strong>{id_number}</strong>, is employed at <strong>{company_name}</strong> as <strong>{designation}</strong> in the <strong>{department}</strong> department since <strong>{hire_date}</strong>.</p>

<p>This certificate is issued upon the employee\'s request without any responsibility on the company.</p>

<div class="signature-block">
<p>Authorized Signatory<br><br><br>______________________<br>Human Resources Department<br>{company_name}</p>
</div>',
                'body_ar'      => '<div class="letterhead">
<h2>{company_name_ar}</h2>
<p>{company_address}<br>سجل تجاري: {company_cr} | هاتف: {company_phone}</p>
</div>

<p><strong>التاريخ:</strong> {current_date_formatted}</p>
<p><strong>المرجع:</strong> {reference_number}</p>

<h3 style="text-align:center;">إلى من يهمه الأمر</h3>

<p>نفيد نحن <strong>{company_name_ar}</strong> بأن الموظف/ة <strong>{employee_name_ar}</strong>، رقم الهوية/الإقامة <strong>{id_number}</strong>، يعمل لدينا بمسمى وظيفي <strong>{designation}</strong> في قسم <strong>{department}</strong> وذلك اعتباراً من تاريخ <strong>{hire_date}</strong> وحتى تاريخه.</p>

<p>أعطيت هذه الشهادة بناءً على طلب الموظف/ة دون أدنى مسؤولية على الشركة.</p>

<div class="signature-block">
<p>التوقيع المعتمد<br><br><br>______________________<br>إدارة الموارد البشرية<br>{company_name_ar}</p>
</div>',
            ],

            // --- Salary Certificate ---
            [
                'template_key' => 'salary_certificate',
                'name_en'      => 'Salary Certificate',
                'name_ar'      => 'شهادة تعريف بالراتب',
                'category'     => 'certificate',
                'body_en'      => '<div class="letterhead">
<h2>{company_name}</h2>
<p>{company_address}<br>CR: {company_cr} | Tel: {company_phone}</p>
</div>

<p><strong>Date:</strong> {current_date_formatted}</p>
<p><strong>Reference:</strong> {reference_number}</p>

<h3 style="text-align:center;">SALARY CERTIFICATE</h3>

<p>This is to certify that <strong>{employee_name}</strong>, holding ID/Iqama No. <strong>{id_number}</strong>, is employed at <strong>{company_name}</strong> as <strong>{designation}</strong> in the <strong>{department}</strong> department since <strong>{hire_date}</strong>.</p>

<p>The employee\'s total monthly salary is <strong>SAR {total_salary}</strong> (Saudi Riyals), broken down as follows:</p>
<ul>
<li>Basic Salary: SAR {basic_salary}</li>
</ul>

<p>This certificate is issued upon the employee\'s request for the purpose of presenting it to the concerned authorities, without any responsibility on the company.</p>

<div class="signature-block">
<p>Authorized Signatory<br><br><br>______________________<br>Human Resources Department<br>{company_name}</p>
</div>',
                'body_ar'      => '<div class="letterhead">
<h2>{company_name_ar}</h2>
<p>{company_address}<br>سجل تجاري: {company_cr} | هاتف: {company_phone}</p>
</div>

<p><strong>التاريخ:</strong> {current_date_formatted}</p>
<p><strong>المرجع:</strong> {reference_number}</p>

<h3 style="text-align:center;">شهادة تعريف بالراتب</h3>

<p>نفيد نحن <strong>{company_name_ar}</strong> بأن الموظف/ة <strong>{employee_name_ar}</strong>، رقم الهوية/الإقامة <strong>{id_number}</strong>، يعمل لدينا بمسمى وظيفي <strong>{designation}</strong> في قسم <strong>{department}</strong> وذلك اعتباراً من تاريخ <strong>{hire_date}</strong>.</p>

<p>ويتقاضى راتباً شهرياً إجمالياً قدره <strong>{total_salary} ريال سعودي</strong>، موزعاً كالتالي:</p>
<ul>
<li>الراتب الأساسي: {basic_salary} ريال سعودي</li>
</ul>

<p>أعطيت هذه الشهادة بناءً على طلب الموظف/ة لتقديمها للجهات المعنية دون أدنى مسؤولية على الشركة.</p>

<div class="signature-block">
<p>التوقيع المعتمد<br><br><br>______________________<br>إدارة الموارد البشرية<br>{company_name_ar}</p>
</div>',
            ],

            // --- Experience Letter ---
            [
                'template_key' => 'experience_letter',
                'name_en'      => 'Experience Letter',
                'name_ar'      => 'شهادة خبرة',
                'category'     => 'certificate',
                'body_en'      => '<div class="letterhead">
<h2>{company_name}</h2>
<p>{company_address}<br>CR: {company_cr} | Tel: {company_phone}</p>
</div>

<p><strong>Date:</strong> {current_date_formatted}</p>
<p><strong>Reference:</strong> {reference_number}</p>

<h3 style="text-align:center;">EXPERIENCE LETTER</h3>

<p>To Whom It May Concern,</p>

<p>This is to certify that <strong>{employee_name}</strong>, holding ID/Iqama No. <strong>{id_number}</strong>, Passport No. <strong>{passport_number}</strong>, Nationality: <strong>{nationality}</strong>, was employed at <strong>{company_name}</strong> as <strong>{designation}</strong> in the <strong>{department}</strong> department from <strong>{hire_date}</strong> until <strong>{current_date_formatted}</strong>.</p>

<p>During the period of employment, the employee demonstrated professional conduct and fulfilled assigned responsibilities satisfactorily.</p>

<p>We wish the employee success in future endeavors.</p>

<div class="signature-block">
<p>Authorized Signatory<br><br><br>______________________<br>Human Resources Department<br>{company_name}</p>
</div>',
                'body_ar'      => '<div class="letterhead">
<h2>{company_name_ar}</h2>
<p>{company_address}<br>سجل تجاري: {company_cr} | هاتف: {company_phone}</p>
</div>

<p><strong>التاريخ:</strong> {current_date_formatted}</p>
<p><strong>المرجع:</strong> {reference_number}</p>

<h3 style="text-align:center;">شهادة خبرة</h3>

<p>إلى من يهمه الأمر،</p>

<p>نفيد نحن <strong>{company_name_ar}</strong> بأن الموظف/ة <strong>{employee_name_ar}</strong>، رقم الهوية/الإقامة <strong>{id_number}</strong>، رقم الجواز <strong>{passport_number}</strong>، الجنسية <strong>{nationality}</strong>، قد عمل لدينا بمسمى وظيفي <strong>{designation}</strong> في قسم <strong>{department}</strong> وذلك في الفترة من <strong>{hire_date}</strong> وحتى <strong>{current_date_formatted}</strong>.</p>

<p>وخلال فترة عمله أبدى التزاماً مهنياً وأدى المهام الموكلة إليه بشكل مُرضٍ.</p>

<p>نتمنى له/ها التوفيق والنجاح في مسيرته/ها المهنية.</p>

<div class="signature-block">
<p>التوقيع المعتمد<br><br><br>______________________<br>إدارة الموارد البشرية<br>{company_name_ar}</p>
</div>',
            ],

            // --- No Objection Certificate (NOC) ---
            [
                'template_key' => 'noc_letter',
                'name_en'      => 'No Objection Certificate (NOC)',
                'name_ar'      => 'شهادة عدم ممانعة',
                'category'     => 'letter',
                'body_en'      => '<div class="letterhead">
<h2>{company_name}</h2>
<p>{company_address}<br>CR: {company_cr} | Tel: {company_phone}</p>
</div>

<p><strong>Date:</strong> {current_date_formatted}</p>
<p><strong>Reference:</strong> {reference_number}</p>

<h3 style="text-align:center;">NO OBJECTION CERTIFICATE</h3>

<p>To Whom It May Concern,</p>

<p>This is to confirm that <strong>{company_name}</strong> has no objection to its employee <strong>{employee_name}</strong>, holding ID/Iqama No. <strong>{id_number}</strong>, Passport No. <strong>{passport_number}</strong>, Nationality: <strong>{nationality}</strong>, currently working as <strong>{designation}</strong>, to proceed with the required formalities as per their request.</p>

<p>This certificate is issued without any financial or legal responsibility on the company.</p>

<div class="signature-block">
<p>Authorized Signatory<br><br><br>______________________<br>Human Resources Department<br>{company_name}</p>
</div>',
                'body_ar'      => '<div class="letterhead">
<h2>{company_name_ar}</h2>
<p>{company_address}<br>سجل تجاري: {company_cr} | هاتف: {company_phone}</p>
</div>

<p><strong>التاريخ:</strong> {current_date_formatted}</p>
<p><strong>المرجع:</strong> {reference_number}</p>

<h3 style="text-align:center;">شهادة عدم ممانعة</h3>

<p>إلى من يهمه الأمر،</p>

<p>نفيد نحن <strong>{company_name_ar}</strong> بأنه لا مانع لدينا تجاه الموظف/ة <strong>{employee_name_ar}</strong>، رقم الهوية/الإقامة <strong>{id_number}</strong>، رقم الجواز <strong>{passport_number}</strong>، الجنسية <strong>{nationality}</strong>، والذي يعمل لدينا بمسمى <strong>{designation}</strong>، لإتمام الإجراءات المطلوبة حسب طلبه.</p>

<p>أعطيت هذه الشهادة دون أدنى مسؤولية مالية أو قانونية على الشركة.</p>

<div class="signature-block">
<p>التوقيع المعتمد<br><br><br>______________________<br>إدارة الموارد البشرية<br>{company_name_ar}</p>
</div>',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Document Generation Log
    // -------------------------------------------------------------------------

    /**
     * Get generated documents log for an employee.
     *
     * Delegates to the audit trail via the 'sfs_hr_document_generated' action.
     * This method fires a filter so other systems (e.g. audit trail) can provide the log.
     *
     * @param int $employee_id Employee ID.
     * @return array List of generated document events.
     */
    public static function get_generated_documents_log( int $employee_id ): array {
        return (array) apply_filters( 'sfs_hr_generated_documents_log', [], $employee_id );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Approximate Gregorian-to-Hijri date conversion.
     *
     * Uses a simplified algorithm. For production accuracy, consider IntlDateFormatter
     * if the intl extension is available.
     *
     * @param string $date Gregorian date (Y-m-d).
     * @return string Hijri date string or original date on failure.
     */
    private static function gregorian_to_hijri( string $date ): string {
        // Use IntlDateFormatter if available for accurate conversion.
        if ( class_exists( 'IntlDateFormatter' ) ) {
            try {
                $formatter = new \IntlDateFormatter(
                    'ar_SA@calendar=islamic-civil',
                    \IntlDateFormatter::SHORT,
                    \IntlDateFormatter::NONE,
                    wp_timezone_string(),
                    \IntlDateFormatter::TRADITIONAL
                );
                $formatter->setPattern( 'yyyy/MM/dd' );
                $timestamp = strtotime( $date );
                if ( false !== $timestamp ) {
                    $result = $formatter->format( $timestamp );
                    if ( false !== $result ) {
                        return $result;
                    }
                }
            } catch ( \Exception $e ) {
                // Fall through to approximation.
            }
        }

        // Fallback: simplified Kuwaiti algorithm approximation.
        $timestamp = strtotime( $date );
        if ( false === $timestamp ) {
            return $date;
        }

        $jd   = gregoriantojd(
            (int) gmdate( 'n', $timestamp ),
            (int) gmdate( 'j', $timestamp ),
            (int) gmdate( 'Y', $timestamp )
        );
        $l     = $jd - 1948440 + 10632;
        $n     = (int) ( ( $l - 1 ) / 10631 );
        $l     = $l - 10631 * $n + 354;
        $j     = (int) ( ( 10985 - $l ) / 5316 ) * (int) ( ( 50 * $l ) / 17719 )
               + (int) ( $l / 5670 ) * (int) ( ( 43 * $l ) / 15238 );
        $l     = $l - (int) ( ( 30 - $j ) / 15 ) * (int) ( ( 17719 * $j ) / 50 )
               - (int) ( $j / 16 ) * (int) ( ( 15238 * $j ) / 43 ) + 29;
        $m     = (int) ( ( 24 * $l ) / 709 );
        $d     = $l - (int) ( ( 709 * $m ) / 24 );
        $y     = 30 * $n + $j - 30;

        return sprintf( '%04d/%02d/%02d', $y, $m, $d );
    }
}
