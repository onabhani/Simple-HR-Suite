<?php
namespace SFS\HR\Modules\Expenses\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Expense_Category_Service
 *
 * M10.1 — Expense category management. Seeds default categories on
 * activation and exposes CRUD for admins.
 *
 * @since M10
 */
class Expense_Category_Service {

    /** Default categories seeded on activation. Idempotent by code. */
    private const SEED_DEFAULTS = [
        [ 'code' => 'travel',         'name' => 'Travel',         'name_ar' => 'السفر',              'receipt_required' => 1, 'sort_order' => 10 ],
        [ 'code' => 'meals',          'name' => 'Meals',          'name_ar' => 'الوجبات',            'receipt_required' => 1, 'sort_order' => 20 ],
        [ 'code' => 'accommodation',  'name' => 'Accommodation',  'name_ar' => 'الإقامة',            'receipt_required' => 1, 'sort_order' => 30 ],
        [ 'code' => 'transportation', 'name' => 'Transportation', 'name_ar' => 'النقل',              'receipt_required' => 1, 'sort_order' => 40 ],
        [ 'code' => 'supplies',       'name' => 'Office Supplies','name_ar' => 'لوازم مكتبية',       'receipt_required' => 1, 'sort_order' => 50 ],
        [ 'code' => 'training',       'name' => 'Training',       'name_ar' => 'التدريب',            'receipt_required' => 1, 'sort_order' => 60 ],
        [ 'code' => 'client',         'name' => 'Client Entertainment', 'name_ar' => 'ضيافة العملاء', 'receipt_required' => 1, 'sort_order' => 70 ],
        [ 'code' => 'other',          'name' => 'Other',          'name_ar' => 'أخرى',               'receipt_required' => 0, 'sort_order' => 99 ],
    ];

    /**
     * Seed default categories if none exist (or fill missing codes).
     */
    public static function seed_defaults(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_categories';

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) {
            return;
        }

        $now = current_time( 'mysql' );
        foreach ( self::SEED_DEFAULTS as $cat ) {
            $found = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE code = %s",
                $cat['code']
            ) );
            if ( $found > 0 ) {
                continue;
            }
            $wpdb->insert( $table, [
                'code'             => $cat['code'],
                'name'             => $cat['name'],
                'name_ar'          => $cat['name_ar'] ?? null,
                'receipt_required' => (int) $cat['receipt_required'],
                'is_active'        => 1,
                'sort_order'       => (int) $cat['sort_order'],
                'created_at'       => $now,
                'updated_at'       => $now,
            ], [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ] );
        }
    }

    public static function list_active(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_categories';
        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function list_all(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_categories';
        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY is_active DESC, sort_order ASC, name ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_categories';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return $row ?: null;
    }

    public static function get_by_code( string $code ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_categories';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Create or update a category. Identified by code.
     */
    public static function upsert( array $data ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_categories';

        $code = sanitize_key( (string) ( $data['code'] ?? '' ) );
        $name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        if ( '' === $code || '' === $name ) {
            return [ 'success' => false, 'error' => __( 'Code and name are required.', 'sfs-hr' ) ];
        }

        $row = [
            'code'             => $code,
            'name'             => $name,
            'name_ar'          => isset( $data['name_ar'] )          ? sanitize_text_field( (string) $data['name_ar'] )      : null,
            'description'      => isset( $data['description'] )      ? sanitize_textarea_field( (string) $data['description'] ) : null,
            'receipt_required' => ! empty( $data['receipt_required'] ) ? 1 : 0,
            'monthly_limit'    => isset( $data['monthly_limit'] ) && $data['monthly_limit'] !== '' ? (float) $data['monthly_limit'] : null,
            'per_claim_limit'  => isset( $data['per_claim_limit'] ) && $data['per_claim_limit'] !== '' ? (float) $data['per_claim_limit'] : null,
            'is_active'        => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
            'sort_order'       => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
            'updated_at'       => current_time( 'mysql' ),
        ];

        $existing = self::get_by_code( $code );
        if ( $existing ) {
            $wpdb->update( $table, $row, [ 'id' => (int) $existing['id'] ] );
            return [ 'success' => true, 'id' => (int) $existing['id'] ];
        }

        $row['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $table, $row );
        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    public static function set_active( int $id, bool $active ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_categories';
        return false !== $wpdb->update(
            $table,
            [ 'is_active' => $active ? 1 : 0, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Month-to-date spending for an employee in a category.
     */
    public static function employee_mtd_spend( int $employee_id, int $category_id, ?string $as_of = null ): float {
        global $wpdb;
        $items_table  = $wpdb->prefix . 'sfs_hr_expense_items';
        $claims_table = $wpdb->prefix . 'sfs_hr_expense_claims';

        $as_of = $as_of ?: current_time( 'Y-m-d' );
        $month_start = substr( $as_of, 0, 7 ) . '-01';

        $total = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(i.amount), 0)
             FROM {$items_table} i
             INNER JOIN {$claims_table} c ON c.id = i.claim_id
             WHERE c.employee_id = %d
               AND i.category_id = %d
               AND c.status IN ('approved','paid')
               AND i.item_date BETWEEN %s AND %s",
            $employee_id,
            $category_id,
            $month_start,
            $as_of
        ) );
        return (float) $total;
    }
}
