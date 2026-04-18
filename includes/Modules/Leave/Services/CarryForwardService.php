<?php
namespace SFS\HR\Modules\Leave\Services;

defined('ABSPATH') || exit;

/**
 * CarryForwardService
 *
 * Handles year-end leave carry-forward processing, expiry enforcement,
 * and preview generation for leave types that allow carry-forward.
 *
 * Depends on columns added in the same migration:
 *   - sfs_hr_leave_types:    allow_carry_forward, max_carry_forward, carry_forward_expiry_months
 *   - sfs_hr_leave_balances: carried_expiry_date, expired_days, encashed
 */
class CarryForwardService {

    /**
     * Statuses that count as "used" days against the balance.
     */
    private const USED_STATUSES = ['approved', 'on_leave', 'returned', 'early_returned'];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Process year-end carry-forward for all eligible employee × leave-type pairs.
     *
     * Creates or updates the balance row for ($from_year + 1) with the
     * carried-over amount and correct quota for the new year.
     *
     * Uses a 10-minute transient lock to prevent concurrent runs.
     *
     * @param int $from_year  The year whose balances are being carried forward (e.g. 2025).
     * @return array{processed: int, skipped: int, errors: list<string>}
     */
    public static function process_year_end(int $from_year): array {
        // Prevent concurrent runs.
        if (get_transient('sfs_hr_carry_forward_lock')) {
            return [
                'processed' => 0,
                'skipped'   => 0,
                'errors'    => [__('Carry-forward already running. Try again in a few minutes.', 'sfs-hr')],
            ];
        }
        set_transient('sfs_hr_carry_forward_lock', 1, 10 * MINUTE_IN_SECONDS);

        $to_year = $from_year + 1;
        $result  = ['processed' => 0, 'skipped' => 0, 'errors' => []];

        try {
            global $wpdb;

            $tbl_employees = $wpdb->prefix . 'sfs_hr_employees';
            $tbl_types     = $wpdb->prefix . 'sfs_hr_leave_types';
            $tbl_balances  = $wpdb->prefix . 'sfs_hr_leave_balances';

            // Fetch active employees.
            $employees = $wpdb->get_results(
                "SELECT id, hire_date FROM {$tbl_employees} WHERE status = 'active'",
                ARRAY_A
            );

            // Fetch leave types that allow carry-forward.
            $types = $wpdb->get_results(
                "SELECT * FROM {$tbl_types} WHERE allow_carry_forward = 1",
                ARRAY_A
            );

            if (empty($employees) || empty($types)) {
                return $result;
            }

            foreach ($employees as $employee) {
                $employee_id = (int) $employee['id'];
                $hire_date   = $employee['hire_date'] ?? null;

                foreach ($types as $type) {
                    $type_id = (int) $type['id'];

                    try {
                        // ----------------------------------------------------------
                        // 1. Load the from-year balance row.
                        // ----------------------------------------------------------
                        $balance = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT opening, accrued, carried_over,
                                        COALESCE(encashed, 0)     AS encashed,
                                        COALESCE(expired_days, 0) AS expired_days
                                   FROM {$tbl_balances}
                                  WHERE employee_id = %d
                                    AND type_id     = %d
                                    AND year        = %d",
                                $employee_id,
                                $type_id,
                                $from_year
                            ),
                            ARRAY_A
                        );

                        // ----------------------------------------------------------
                        // 2. Sum actual used days from leave requests.
                        // ----------------------------------------------------------
                        $used_statuses_in = implode(
                            ',',
                            array_fill(0, count(self::USED_STATUSES), '%s')
                        );
                        $used = (int) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT COALESCE(SUM(days), 0)
                                   FROM {$wpdb->prefix}sfs_hr_leave_requests
                                  WHERE employee_id = %d
                                    AND type_id     = %d
                                    AND YEAR(start_date) = %d
                                    AND status IN ({$used_statuses_in})",
                                array_merge(
                                    [$employee_id, $type_id, $from_year],
                                    self::USED_STATUSES
                                )
                            )
                        );

                        // ----------------------------------------------------------
                        // 3. Compute remaining balance.
                        // ----------------------------------------------------------
                        $opening      = (int) ($balance['opening']      ?? 0);
                        $accrued      = (int) ($balance['accrued']      ?? 0);
                        $carried_over = (int) ($balance['carried_over'] ?? 0);
                        $encashed     = (int) ($balance['encashed']     ?? 0);
                        $expired_days = (int) ($balance['expired_days'] ?? 0);

                        $remaining = $opening + $accrued + $carried_over - $used - $encashed - $expired_days;
                        if ($remaining <= 0) {
                            $result['skipped']++;
                            continue;
                        }

                        // ----------------------------------------------------------
                        // 4. Apply carry-forward cap.
                        // ----------------------------------------------------------
                        $max_carry = (int) $type['max_carry_forward'];
                        $carry_amount = ($max_carry > 0)
                            ? min($remaining, $max_carry)
                            : $remaining;

                        // ----------------------------------------------------------
                        // 5. Compute expiry date for the carried-over days.
                        // ----------------------------------------------------------
                        $expiry_months = (int) $type['carry_forward_expiry_months'];
                        if ($expiry_months > 0) {
                            // Base: first day of the to_year, then add N months.
                            $expiry_ts   = strtotime("{$to_year}-01-01 +{$expiry_months} months");
                            $expiry_date = gmdate('Y-m-d', $expiry_ts);
                        } else {
                            $expiry_date = null;
                        }

                        // ----------------------------------------------------------
                        // 6. Compute opening quota for the new year.
                        // ----------------------------------------------------------
                        $new_year_quota = (int) $type['annual_quota'];
                        if (class_exists(__NAMESPACE__ . '\LeaveCalculationService')) {
                            $new_year_quota = LeaveCalculationService::compute_quota_for_year(
                                $type,
                                $hire_date,
                                $to_year
                            );
                        }

                        // ----------------------------------------------------------
                        // 7. Upsert the to-year balance row.
                        // ----------------------------------------------------------
                        $existing_to = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT id FROM {$tbl_balances}
                                  WHERE employee_id = %d
                                    AND type_id     = %d
                                    AND year        = %d",
                                $employee_id,
                                $type_id,
                                $to_year
                            )
                        );

                        if ($existing_to) {
                            $wpdb->update(
                                $tbl_balances,
                                [
                                    'carried_over'        => $carry_amount,
                                    'carried_expiry_date' => $expiry_date,
                                    'opening'             => 0,
                                    'accrued'             => $new_year_quota,
                                ],
                                [
                                    'employee_id' => $employee_id,
                                    'type_id'     => $type_id,
                                    'year'        => $to_year,
                                ],
                                ['%d', ($expiry_date ? '%s' : null), '%d', '%d'],
                                ['%d', '%d', '%d']
                            );
                        } else {
                            $wpdb->insert(
                                $tbl_balances,
                                [
                                    'employee_id'         => $employee_id,
                                    'type_id'             => $type_id,
                                    'year'                => $to_year,
                                    'opening'             => 0,
                                    'accrued'             => $new_year_quota,
                                    'used'                => 0,
                                    'carried_over'        => $carry_amount,
                                    'carried_expiry_date' => $expiry_date,
                                    'expired_days'        => 0,
                                    'encashed'            => 0,
                                ],
                                ['%d', '%d', '%d', '%d', '%d', '%d', '%d', ($expiry_date ? '%s' : null), '%d', '%d']
                            );
                        }

                        $result['processed']++;

                    } catch (\Throwable $e) {
                        $result['errors'][] = sprintf(
                            /* translators: 1: employee ID, 2: leave type ID, 3: error message */
                            __('Employee %1$d / type %2$d: %3$s', 'sfs-hr'),
                            $employee_id,
                            $type_id,
                            $e->getMessage()
                        );
                        $result['skipped']++;
                    }
                } // end foreach type
            } // end foreach employee

        } finally {
            delete_transient('sfs_hr_carry_forward_lock');
        }

        return $result;
    }

    /**
     * Cron handler: expire carried-over days that have passed their expiry date.
     *
     * For any balance row where `carried_expiry_date <= today` and
     * `carried_over > expired_days`, marks the remaining carried days as expired
     * and recalculates `closing`.
     *
     * @return void
     */
    public static function process_carry_expiry(): void {
        global $wpdb;

        $tbl = $wpdb->prefix . 'sfs_hr_leave_balances';
        $today = gmdate('Y-m-d');

        // Fetch rows with unexpired carry-forward that has now passed its deadline.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, carried_over, expired_days, opening, accrued, used,
                        COALESCE(encashed, 0) AS encashed
                   FROM {$tbl}
                  WHERE carried_expiry_date IS NOT NULL
                    AND carried_expiry_date <= %s
                    AND carried_over > expired_days",
                $today
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $new_expired = (int) $row['carried_over'];

            // closing = opening + accrued + carried_over - used - encashed - expired_days
            $closing = (int) $row['opening']
                     + (int) $row['accrued']
                     + (int) $row['carried_over']
                     - (int) $row['used']
                     - (int) $row['encashed']
                     - $new_expired;

            $wpdb->update(
                $tbl,
                [
                    'expired_days' => $new_expired,
                    'closing'      => max($closing, 0),
                ],
                ['id' => (int) $row['id']],
                ['%d', '%d'],
                ['%d']
            );
        }
    }

    /**
     * Build a preview of what would be carried forward for a given year.
     *
     * Returns one row per employee × leave-type pair that has a positive
     * carry amount, without writing anything to the database.
     *
     * @param int $from_year
     * @return list<array{
     *     employee_id: int,
     *     employee_name: string,
     *     type_name: string,
     *     remaining: int,
     *     carry_amount: int,
     *     expiry_date: string|null
     * }>
     */
    public static function get_carry_forward_preview(int $from_year): array {
        global $wpdb;

        $tbl_employees = $wpdb->prefix . 'sfs_hr_employees';
        $tbl_types     = $wpdb->prefix . 'sfs_hr_leave_types';
        $tbl_balances  = $wpdb->prefix . 'sfs_hr_leave_balances';
        $tbl_requests  = $wpdb->prefix . 'sfs_hr_leave_requests';

        $to_year = $from_year + 1;

        // Pull balances for all active employees × carry-forward-enabled types.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.id                          AS employee_id,
                        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                        lt.id                         AS type_id,
                        lt.name                       AS type_name,
                        lt.max_carry_forward,
                        lt.carry_forward_expiry_months,
                        COALESCE(b.opening, 0)        AS opening,
                        COALESCE(b.accrued, 0)        AS accrued,
                        COALESCE(b.carried_over, 0)   AS carried_over,
                        COALESCE(b.encashed, 0)       AS encashed,
                        COALESCE(b.expired_days, 0)   AS expired_days
                   FROM {$tbl_employees}  AS e
             CROSS JOIN {$tbl_types}      AS lt
              LEFT JOIN {$tbl_balances}   AS b
                     ON b.employee_id = e.id
                    AND b.type_id     = lt.id
                    AND b.year        = %d
                  WHERE e.status      = 'active'
                    AND lt.allow_carry_forward = 1",
                $from_year
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return [];
        }

        // Build a lookup of actual used days per (employee, type) from requests.
        $used_statuses_in = implode(
            ',',
            array_fill(0, count(self::USED_STATUSES), '%s')
        );
        $used_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT employee_id, type_id, COALESCE(SUM(days), 0) AS used
                   FROM {$tbl_requests}
                  WHERE YEAR(start_date) = %d
                    AND status IN ({$used_statuses_in})
               GROUP BY employee_id, type_id",
                array_merge([$from_year], self::USED_STATUSES)
            ),
            ARRAY_A
        );

        $used_map = [];
        foreach ($used_rows as $ur) {
            $used_map[(int) $ur['employee_id']][(int) $ur['type_id']] = (int) $ur['used'];
        }

        $preview = [];

        foreach ($rows as $row) {
            $employee_id = (int) $row['employee_id'];
            $type_id     = (int) $row['type_id'];

            $used = $used_map[$employee_id][$type_id] ?? 0;

            $remaining = (int) $row['opening']
                       + (int) $row['accrued']
                       + (int) $row['carried_over']
                       - $used
                       - (int) $row['encashed']
                       - (int) $row['expired_days'];

            if ($remaining <= 0) {
                continue;
            }

            $max_carry = (int) $row['max_carry_forward'];
            $carry_amount = ($max_carry > 0) ? min($remaining, $max_carry) : $remaining;

            $expiry_months = (int) $row['carry_forward_expiry_months'];
            if ($expiry_months > 0) {
                $expiry_ts   = strtotime("{$to_year}-01-01 +{$expiry_months} months");
                $expiry_date = gmdate('Y-m-d', $expiry_ts);
            } else {
                $expiry_date = null;
            }

            $preview[] = [
                'employee_id'   => $employee_id,
                'employee_name' => trim((string) $row['employee_name']),
                'type_name'     => (string) $row['type_name'],
                'remaining'     => $remaining,
                'carry_amount'  => $carry_amount,
                'expiry_date'   => $expiry_date,
            ];
        }

        return $preview;
    }
}
