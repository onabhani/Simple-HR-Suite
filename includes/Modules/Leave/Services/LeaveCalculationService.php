<?php
namespace SFS\HR\Modules\Leave\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Leave Calculation Service
 * Handles business day calculations, quota computations, and holiday processing
 */
class LeaveCalculationService {

    /**
     * Calculate business days for Sat-Thu workweek (Friday off) minus company holidays.
     * Dates are inclusive.
     */
    public static function business_days(string $start, string $end): int {
        $s = strtotime($start);
        $e = strtotime($end);
        if ($e < $s) return 0;

        $holiday_map = array_fill_keys(self::holidays_in_range($start, $end), true);

        $days = 0;
        for ($d = $s; $d <= $e; $d += DAY_IN_SECONDS) {
            $ymd = gmdate('Y-m-d', $d);
            if (isset($holiday_map[$ymd])) continue;
            $w = (int) gmdate('w', $d); // 0 Sun..6 Sat; Friday=5
            if ($w === 5) continue;     // Friday off
            $days++;
        }
        return $days;
    }

    /**
     * Compute tenure-aware quota for a year using policy options.
     * Falls back to type.annual_quota if tenure not applicable.
     */
    public static function compute_quota_for_year(array $type_row, ?string $hire_date, int $year): int {
        $quota = (int)($type_row['annual_quota'] ?? 0);
        if (empty($type_row['is_annual'])) return $quota;
        if (empty($hire_date)) return $quota;

        $as_of = strtotime($year . '-01-01');
        $hd = strtotime($hire_date);
        if (!$hd) return $quota;

        $years = (int)floor(($as_of - $hd) / (365.2425 * DAY_IN_SECONDS));
        if ($years < 0) $years = 0;

        $lt5 = (int)get_option('sfs_hr_annual_lt5', '21');
        $ge5 = (int)get_option('sfs_hr_annual_ge5', '30');

        return ($years >= 5) ? $ge5 : $lt5;
    }

    /**
     * Calculate available leave days = opening + accrued + carried_over - used
     */
    public static function available_days(int $employee_id, int $type_id, int $year, int $annual_quota): int {
        global $wpdb;
        $bal = $wpdb->prefix . 'sfs_hr_leave_balances';
        $req = $wpdb->prefix . 'sfs_hr_leave_requests';

        $used = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(days),0) FROM $req WHERE employee_id=%d AND type_id=%d AND status='approved' AND YEAR(start_date)=%d",
            $employee_id, $type_id, $year
        ));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT opening, accrued, carried_over FROM $bal WHERE employee_id=%d AND type_id=%d AND year=%d",
            $employee_id, $type_id, $year
        ), ARRAY_A);

        $opening = (int)($row['opening'] ?? 0);
        $accrued = isset($row['accrued']) ? (int)$row['accrued'] : (int)$annual_quota;
        if ((int)($row['accrued'] ?? 0) === 0) $accrued = (int)$annual_quota;
        $carried = (int)($row['carried_over'] ?? 0);

        $available = $opening + $accrued + $carried - $used;
        return max($available, 0);
    }

    /**
     * Check if employee has overlapping leave requests for the given date range
     */
    public static function has_overlap(int $employee_id, string $start, string $end): bool {
        global $wpdb;
        $t = $wpdb->prefix . 'sfs_hr_leave_requests';
        $sql = "SELECT COUNT(*) FROM $t
                WHERE employee_id=%d
                  AND status IN ('pending','approved')
                  AND NOT (end_date < %s OR start_date > %s)";
        $cnt = (int)$wpdb->get_var($wpdb->prepare($sql, $employee_id, $start, $end));
        return $cnt > 0;
    }

    /**
     * Validate leave request dates
     * @return string|null Error message or null if valid
     */
    public static function validate_dates(string $start, string $end): ?string {
        if (!$start || !$end) {
            return __('Start/End dates required.', 'sfs-hr');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return __('Invalid date format (YYYY-MM-DD).', 'sfs-hr');
        }
        if ($end < $start) {
            return __('End date must be after start date.', 'sfs-hr');
        }
        $today = current_time('Y-m-d');
        if ($start < $today) {
            return __('Cannot request leave in the past.', 'sfs-hr');
        }
        return null;
    }

    /**
     * Get holidays from option, sanitized and normalized
     */
    public static function get_holidays(): array {
        $list = get_option('sfs_hr_holidays', []);
        if (!is_array($list)) $list = [];

        $out = [];
        foreach ($list as $h) {
            $n = isset($h['name']) ? sanitize_text_field($h['name']) : '';
            $r = !empty($h['repeat']) ? 1 : 0;

            // Back-compat: single day record {date}
            if (!empty($h['date'])) {
                $d = sanitize_text_field($h['date']);
                if ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $n) {
                    $out[] = ['start' => $d, 'end' => $d, 'name' => $n, 'repeat' => $r];
                }
                continue;
            }

            $s = isset($h['start']) ? sanitize_text_field($h['start']) : '';
            $e = isset($h['end']) ? sanitize_text_field($h['end']) : '';
            if (!$s || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) continue;
            if (!$e || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) $e = $s;
            if ($e < $s) { $tmp = $s; $s = $e; $e = $tmp; }
            if ($n) $out[] = ['start' => $s, 'end' => $e, 'name' => $n, 'repeat' => $r];
        }
        return $out;
    }

    /**
     * Get list of Y-m-d dates to exclude within [start,end] inclusive
     */
    public static function holidays_in_range(string $start, string $end): array {
        $s = strtotime($start);
        $e = strtotime($end);
        if ($e < $s) return [];

        $list = self::get_holidays();
        $years = range((int)gmdate('Y', $s), (int)gmdate('Y', $e));
        $set = [];

        foreach ($list as $h) {
            $s0 = $h['start'];
            $e0 = $h['end'];

            if (!empty($h['repeat'])) {
                $sm = substr($s0, 5);
                $em = substr($e0, 5);
                foreach ($years as $y) {
                    $rs = $y . '-' . $sm;
                    $re = ($em >= $sm) ? ($y . '-' . $em) : (($y + 1) . '-' . $em);
                    self::add_range_days_clipped($rs, $re, $start, $end, $set);
                }
            } else {
                self::add_range_days_clipped($s0, $e0, $start, $end, $set);
            }
        }
        return array_keys($set);
    }

    /**
     * Add each day of [rangeStart, rangeEnd] to $set, clipped to [clipStart, clipEnd]
     */
    private static function add_range_days_clipped(string $rangeStart, string $rangeEnd, string $clipStart, string $clipEnd, array &$set): void {
        $rs = strtotime($rangeStart);
        $re = strtotime($rangeEnd);
        if ($re < $rs) return;

        $cs = strtotime($clipStart);
        $ce = strtotime($clipEnd);

        $s = max($rs, $cs);
        $e = min($re, $ce);

        for ($d = $s; $d <= $e; $d += DAY_IN_SECONDS) {
            $set[gmdate('Y-m-d', $d)] = true;
        }
    }

    /**
     * Append an approval step to the approval chain JSON
     */
    public static function append_approval_chain(?string $json, array $step): string {
        $chain = [];
        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $chain = $decoded;
            }
        }
        $step['at'] = $step['at'] ?? \SFS\HR\Core\Helpers::now_mysql();
        $chain[] = [
            'by'     => (int)($step['by'] ?? 0),
            'role'   => (string)($step['role'] ?? ''),
            'action' => (string)($step['action'] ?? ''),
            'note'   => (string)($step['note'] ?? ''),
            'at'     => (string)$step['at'],
        ];
        return wp_json_encode($chain);
    }

    /**
     * Get department IDs managed by a user
     */
    public static function manager_dept_ids_for_user(int $user_id): array {
        global $wpdb;
        $tbl = $wpdb->prefix . 'sfs_hr_departments';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $tbl WHERE manager_user_id=%d AND active=1",
            $user_id
        ));
        return array_map('intval', $ids ?: []);
    }
}
