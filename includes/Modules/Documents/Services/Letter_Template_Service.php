<?php
namespace SFS\HR\Modules\Documents\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Letter_Template_Service {

    private const TABLE = 'sfs_hr_letter_templates';

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function ensure_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = self::table();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(50) NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `name_ar` VARCHAR(100) NULL,
            `body_en` LONGTEXT NOT NULL,
            `body_ar` LONGTEXT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_letter_code` (`code`)
        ) {$charset}" );
    }

    public static function seed_defaults(): void {
        global $wpdb;
        $table = self::table();

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
        if ( $count > 0 ) {
            return;
        }

        $now = current_time( 'mysql' );

        $defaults = [
            [
                'code'    => 'employment_cert',
                'name'    => 'Employment Certificate',
                'name_ar' => 'شهادة عمل',
                'body_en' => 'This is to certify that {{employee_name}} (Employee Code: {{employee_code}}) is employed at {{company_name}} as {{position}} in the {{department}} department since {{hire_date}}.',
                'body_ar' => 'نشهد بأن الموظف/ة {{employee_name_ar}} (رقم الموظف: {{employee_code}}) يعمل لدى {{company_name_ar}} بمسمى وظيفي {{position}} في قسم {{department}} وذلك اعتباراً من تاريخ {{hire_date}}. وقد أُعطيت هذه الشهادة بناءً على طلبه/ا دون أدنى مسؤولية على الشركة.',
            ],
            [
                'code'    => 'salary_cert',
                'name'    => 'Salary Certificate',
                'name_ar' => 'شهادة راتب',
                'body_en' => 'This is to certify that {{employee_name}} (Employee Code: {{employee_code}}) is currently employed at {{company_name}} as {{position}} with a basic monthly salary of {{salary}} SAR.',
                'body_ar' => 'نشهد بأن الموظف/ة {{employee_name_ar}} (رقم الموظف: {{employee_code}}) يعمل حالياً لدى {{company_name_ar}} بمسمى وظيفي {{position}} ويتقاضى راتباً أساسياً شهرياً قدره {{salary}} ريال سعودي. وقد أُعطيت هذه الشهادة بناءً على طلبه/ا لتقديمها للجهات المختصة.',
            ],
            [
                'code'    => 'experience_letter',
                'name'    => 'Experience Letter',
                'name_ar' => 'شهادة خبرة',
                'body_en' => 'This is to certify that {{employee_name}} has been employed at {{company_name}} from {{hire_date}} as {{position}} in the {{department}} department.',
                'body_ar' => 'نشهد بأن الموظف/ة {{employee_name_ar}} قد عمل/ت لدى {{company_name_ar}} اعتباراً من تاريخ {{hire_date}} بمسمى وظيفي {{position}} في قسم {{department}}. وقد أثبت/ت كفاءة عالية خلال فترة عمله/ا.',
            ],
            [
                'code'    => 'noc',
                'name'    => 'No Objection Certificate',
                'name_ar' => 'شهادة عدم ممانعة',
                'body_en' => 'This is to certify that {{company_name}} has no objection to {{employee_name}} (Employee Code: {{employee_code}}, National ID: {{national_id}}) for any purpose it may serve.',
                'body_ar' => 'نشهد نحن {{company_name_ar}} بعدم ممانعتنا تجاه الموظف/ة {{employee_name_ar}} (رقم الموظف: {{employee_code}}، رقم الهوية: {{national_id}}) لأي غرض يراه مناسباً. وقد أُعطيت هذه الشهادة بناءً على طلبه/ا.',
            ],
        ];

        foreach ( $defaults as $tpl ) {
            $wpdb->insert( $table, [
                'code'       => $tpl['code'],
                'name'       => $tpl['name'],
                'name_ar'    => $tpl['name_ar'],
                'body_en'    => $tpl['body_en'],
                'body_ar'    => $tpl['body_ar'],
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ] );
        }
    }

    public static function list_templates(): array {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE 1 = %d ORDER BY code ASC", 1 ),
            ARRAY_A
        ) ?: [];
    }

    public static function get_template( int $id ): ?array {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_by_code( string $code ): ?array {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE code = %s", $code ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function upsert_template( array $data ): array {
        global $wpdb;
        $table = self::table();

        $code = sanitize_key( (string) ( $data['code'] ?? '' ) );
        $name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        $body_en = wp_kses_post( (string) ( $data['body_en'] ?? '' ) );

        if ( '' === $code || '' === $name || '' === $body_en ) {
            return [ 'success' => false, 'error' => __( 'Code, name, and English body are required.', 'sfs-hr' ) ];
        }

        $row = [
            'code'       => $code,
            'name'       => $name,
            'name_ar'    => isset( $data['name_ar'] ) ? sanitize_text_field( (string) $data['name_ar'] ) : null,
            'body_en'    => $body_en,
            'body_ar'    => isset( $data['body_ar'] ) ? wp_kses_post( (string) $data['body_ar'] ) : null,
            'is_active'  => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
            'updated_at' => current_time( 'mysql' ),
        ];

        $format = [ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ];

        $existing = self::get_by_code( $code );
        if ( $existing ) {
            $wpdb->update( $table, $row, [ 'id' => (int) $existing['id'] ], $format, [ '%d' ] );
            return [ 'success' => true, 'id' => (int) $existing['id'] ];
        }

        $row['created_at'] = current_time( 'mysql' );
        $format[]          = '%s';
        $wpdb->insert( $table, $row, $format );

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    public static function generate_letter( string $template_code, int $employee_id, string $lang = 'en' ): array {
        global $wpdb;

        $template = self::get_by_code( $template_code );
        if ( ! $template || ! (int) $template['is_active'] ) {
            return [ 'success' => false, 'error' => __( 'Template not found or inactive.', 'sfs-hr' ) ];
        }

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $employee = $wpdb->get_row( $wpdb->prepare(
            "SELECT e.*, d.name AS department_name
             FROM `{$emp_table}` e
             LEFT JOIN `{$dept_table}` d ON d.id = e.dept_id
             WHERE e.id = %d",
            $employee_id
        ), ARRAY_A );

        if ( ! $employee ) {
            return [ 'success' => false, 'error' => __( 'Employee not found.', 'sfs-hr' ) ];
        }

        $company = get_option( 'sfs_hr_company_profile', [] );

        $body = ( 'ar' === $lang && ! empty( $template['body_ar'] ) )
            ? $template['body_ar']
            : $template['body_en'];

        $full_name    = trim( ( $employee['first_name'] ?? '' ) . ' ' . ( $employee['last_name'] ?? '' ) );
        $full_name_ar = trim( ( $employee['first_name_ar'] ?? '' ) . ' ' . ( $employee['last_name_ar'] ?? '' ) );

        $fields = [
            '{{employee_name}}'    => $full_name,
            '{{employee_name_ar}}' => $full_name_ar ?: $full_name,
            '{{employee_code}}'    => $employee['employee_code'] ?? '',
            '{{position}}'         => $employee['position'] ?? '',
            '{{department}}'       => $employee['department_name'] ?? '',
            '{{hire_date}}'        => $employee['hire_date']
                ? date_i18n( get_option( 'date_format' ), strtotime( $employee['hire_date'] ) )
                : '',
            '{{salary}}'           => isset( $employee['base_salary'] )
                ? number_format( (float) $employee['base_salary'], 2 )
                : '0.00',
            '{{company_name}}'     => $company['name'] ?? $company['company_name'] ?? '',
            '{{company_name_ar}}'  => $company['name_ar'] ?? $company['company_name_ar'] ?? '',
            '{{today}}'            => date_i18n( get_option( 'date_format' ) ),
            '{{today_hijri}}'      => class_exists( 'IntlDateFormatter' )
                ? ( new \IntlDateFormatter( 'ar_SA@calendar=islamic-civil', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE ) )->format( time() )
                : date_i18n( get_option( 'date_format' ) ),
            '{{national_id}}'      => $employee['national_id'] ?? '',
        ];

        $rendered = str_replace( array_keys( $fields ), array_values( $fields ), $body );

        $template_name = ( 'ar' === $lang && ! empty( $template['name_ar'] ) )
            ? $template['name_ar']
            : $template['name'];

        return [
            'success'       => true,
            'html'          => $rendered,
            'template_name' => $template_name,
            'employee_name' => ( 'ar' === $lang && $full_name_ar ) ? $full_name_ar : $full_name,
        ];
    }

    public static function get_available_fields(): array {
        return [
            '{{employee_name}}'    => __( 'Full name (first + last)', 'sfs-hr' ),
            '{{employee_name_ar}}' => __( 'Arabic full name', 'sfs-hr' ),
            '{{employee_code}}'    => __( 'Employee code', 'sfs-hr' ),
            '{{position}}'         => __( 'Job title / position', 'sfs-hr' ),
            '{{department}}'       => __( 'Department name', 'sfs-hr' ),
            '{{hire_date}}'        => __( 'Hire date (formatted)', 'sfs-hr' ),
            '{{salary}}'           => __( 'Basic monthly salary', 'sfs-hr' ),
            '{{company_name}}'     => __( 'Company name (English)', 'sfs-hr' ),
            '{{company_name_ar}}'  => __( 'Company name (Arabic)', 'sfs-hr' ),
            '{{today}}'            => __( 'Current date', 'sfs-hr' ),
            '{{today_hijri}}'      => __( 'Current Hijri date', 'sfs-hr' ),
            '{{national_id}}'      => __( 'National ID number', 'sfs-hr' ),
        ];
    }
}
