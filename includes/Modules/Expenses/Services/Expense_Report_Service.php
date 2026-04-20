<?php
namespace SFS\HR\Modules\Expenses\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Expense_Report_Service
 *
 * M10.4 — Reporting: per-department / per-category spend, policy-violation
 * flagging, CSV export.
 *
 * @since M10
 */
class Expense_Report_Service {

    /**
     * Aggregate approved/paid expense amounts grouped by department and category
     * over a date range.
     */
    public static function by_department( string $start_date, string $end_date, ?int $dept_id = null ): array {
        global $wpdb;
        $claims = $wpdb->prefix . 'sfs_hr_expense_claims';
        $items  = $wpdb->prefix . 'sfs_hr_expense_items';
        $emp    = $wpdb->prefix . 'sfs_hr_employees';
        $cats   = $wpdb->prefix . 'sfs_hr_expense_categories';

        $where  = [ "c.status IN ('approved','paid')", 'i.item_date BETWEEN %s AND %s' ];
        $args   = [ $start_date, $end_date ];
        if ( null !== $dept_id ) {
            $where[] = 'e.dept_id = %d';
            $args[]  = $dept_id;
        }
        $where_sql = implode( ' AND ', $where );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                e.dept_id,
                cat.id   AS category_id,
                cat.code AS category_code,
                cat.name AS category_name,
                COUNT(DISTINCT c.id) AS claim_count,
                SUM(i.amount)         AS total_submitted,
                SUM(COALESCE(i.approved_amount, i.amount)) AS total_approved
             FROM {$items} i
             INNER JOIN {$claims} c ON c.id = i.claim_id
             INNER JOIN {$emp}    e ON e.id = c.employee_id
             INNER JOIN {$cats}   cat ON cat.id = i.category_id
             WHERE {$where_sql}
             GROUP BY e.dept_id, cat.id
             ORDER BY e.dept_id ASC, total_approved DESC",
            ...$args
        ), ARRAY_A ) ?: [];
    }

    /**
     * Per-employee totals for a period.
     */
    public static function by_employee( string $start_date, string $end_date, ?int $dept_id = null ): array {
        global $wpdb;
        $claims = $wpdb->prefix . 'sfs_hr_expense_claims';
        $items  = $wpdb->prefix . 'sfs_hr_expense_items';
        $emp    = $wpdb->prefix . 'sfs_hr_employees';

        $where  = [ 'i.item_date BETWEEN %s AND %s' ];
        $args   = [ $start_date, $end_date ];
        if ( null !== $dept_id ) {
            $where[] = 'e.dept_id = %d';
            $args[]  = $dept_id;
        }
        $where_sql = implode( ' AND ', $where );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                e.id           AS employee_id,
                e.employee_code,
                e.first_name,
                e.last_name,
                e.dept_id,
                COUNT(DISTINCT c.id) AS claim_count,
                SUM(CASE WHEN c.status IN ('approved','paid') THEN i.amount ELSE 0 END) AS approved_amount,
                SUM(CASE WHEN c.status IN ('rejected') THEN i.amount ELSE 0 END)        AS rejected_amount,
                SUM(CASE WHEN c.status IN ('pending_manager','pending_finance') THEN i.amount ELSE 0 END) AS pending_amount,
                SUM(i.amount) AS total_amount
             FROM {$items} i
             INNER JOIN {$claims} c ON c.id = i.claim_id
             INNER JOIN {$emp}    e ON e.id = c.employee_id
             WHERE {$where_sql}
             GROUP BY e.id
             ORDER BY approved_amount DESC",
            ...$args
        ), ARRAY_A ) ?: [];
    }

    /**
     * Flag policy violations:
     *   - over_per_claim_limit: item amount exceeds category per_claim_limit
     *   - over_monthly_limit:   cumulative monthly amount exceeds monthly_limit
     *   - duplicate_receipt:    same receipt_media_id reused across claims
     */
    public static function policy_violations( string $start_date, string $end_date ): array {
        global $wpdb;
        $items  = $wpdb->prefix . 'sfs_hr_expense_items';
        $claims = $wpdb->prefix . 'sfs_hr_expense_claims';
        $cats   = $wpdb->prefix . 'sfs_hr_expense_categories';

        $violations = [];

        // Over per-claim limit.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.id AS item_id, i.claim_id, i.amount, i.item_date,
                    cat.name AS category_name, cat.per_claim_limit,
                    c.employee_id
             FROM {$items} i
             INNER JOIN {$claims} c ON c.id = i.claim_id
             INNER JOIN {$cats}   cat ON cat.id = i.category_id
             WHERE i.item_date BETWEEN %s AND %s
               AND cat.per_claim_limit IS NOT NULL
               AND i.amount > cat.per_claim_limit",
            $start_date, $end_date
        ), ARRAY_A ) ?: [];
        foreach ( $rows as $r ) {
            $violations[] = array_merge( $r, [ 'violation' => 'over_per_claim_limit' ] );
        }

        // Duplicate receipts: same media id used twice (excluding NULL).
        $dup_media = $wpdb->get_results( $wpdb->prepare(
            "SELECT receipt_media_id, GROUP_CONCAT(id ORDER BY id) AS item_ids, COUNT(*) AS n
             FROM {$items}
             WHERE receipt_media_id IS NOT NULL
               AND item_date BETWEEN %s AND %s
             GROUP BY receipt_media_id
             HAVING n > 1",
            $start_date, $end_date
        ), ARRAY_A ) ?: [];
        foreach ( $dup_media as $d ) {
            $violations[] = [
                'violation'        => 'duplicate_receipt',
                'receipt_media_id' => (int) $d['receipt_media_id'],
                'item_ids'         => $d['item_ids'],
                'count'            => (int) $d['n'],
            ];
        }

        // Over monthly limit (per employee + category).
        $monthly = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.employee_id, cat.id AS category_id, cat.name AS category_name, cat.monthly_limit,
                    DATE_FORMAT(i.item_date, '%%Y-%%m') AS month,
                    SUM(i.amount) AS total
             FROM {$items} i
             INNER JOIN {$claims} c ON c.id = i.claim_id
             INNER JOIN {$cats}   cat ON cat.id = i.category_id
             WHERE i.item_date BETWEEN %s AND %s
               AND cat.monthly_limit IS NOT NULL
               AND c.status IN ('approved','paid','pending_manager','pending_finance')
             GROUP BY c.employee_id, cat.id, month
             HAVING total > cat.monthly_limit",
            $start_date, $end_date
        ), ARRAY_A ) ?: [];
        foreach ( $monthly as $m ) {
            $violations[] = array_merge( $m, [ 'violation' => 'over_monthly_limit' ] );
        }

        return $violations;
    }

    /**
     * CSV export for the by-employee report. Returns raw CSV content.
     */
    public static function export_by_employee_csv( string $start_date, string $end_date, ?int $dept_id = null ): string {
        $rows = self::by_employee( $start_date, $end_date, $dept_id );

        $out  = "Employee Code,First Name,Last Name,Dept ID,Claims,Approved,Rejected,Pending,Total\r\n";
        foreach ( $rows as $r ) {
            $out .= sprintf(
                "%s,%s,%s,%s,%d,%.2f,%.2f,%.2f,%.2f\r\n",
                self::csv_cell( (string) $r['employee_code'] ),
                self::csv_cell( (string) $r['first_name'] ),
                self::csv_cell( (string) $r['last_name'] ),
                (int) $r['dept_id'],
                (int) $r['claim_count'],
                (float) $r['approved_amount'],
                (float) $r['rejected_amount'],
                (float) $r['pending_amount'],
                (float) $r['total_amount']
            );
        }
        return $out;
    }

    /**
     * CSV cell sanitization — strips delimiters, newlines, and neutralizes
     * Excel formula-injection prefixes (CWE-1236).
     */
    private static function csv_cell( string $value ): string {
        $v = str_replace( [ ',', '"', "\r", "\n", "\t" ], ' ', $value );
        $v = trim( $v );
        if ( $v !== '' && in_array( $v[0], [ '=', '+', '-', '@' ], true ) ) {
            $v = "'" . $v;
        }
        return $v;
    }
}
