# Phase 09 Plan 01: Payroll Orchestrator and REST Audit Findings

**Files audited:**
- `includes/Modules/Payroll/PayrollModule.php` (700 lines)
- `includes/Modules/Payroll/Rest/Payroll_Rest.php` (263 lines)
- `includes/Modules/Payroll/Admin/Admin_Pages.php` (payroll run orchestration, reviewed lines 1391-1626)

**Date:** 2026-03-16

---

## Summary Table

| Category  | Critical | High | Medium | Low | Total |
|-----------|----------|------|--------|-----|-------|
| Security  | 0        | 2    | 2      | 0   | 4     |
| Performance| 0       | 1    | 1      | 1   | 3     |
| Duplication| 1       | 0    | 1      | 1   | 3     |
| Logical   | 0        | 1    | 3      | 1   | 5     |
| **Total** | **1**    | **4**| **7**  | **3** | **15** |

---

## Security Findings

### PAY-SEC-001 — High
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1459`
**Issue:** Unbounded `$wpdb->get_col()` call with no `$wpdb->prepare()` wrapper.

```php
$employees = $wpdb->get_col(
    "SELECT id FROM {$emp_table} WHERE status = 'active'"
);
```

The query has no user-supplied dynamic values so there is no SQL injection risk from this specific call, but the pattern violates the project convention that all `$wpdb` calls must use `$wpdb->prepare()`. A future developer adding a filter parameter (e.g., department) to this query would be tempted to use the same pattern, creating injection risk. The bigger concern is that this call is inside a transaction (`START TRANSACTION`/`COMMIT`) and loads every active employee ID in a single unbounded query — see PAY-PERF-001 for the performance dimension.

**Fix:** Even for static queries, wrap in `$wpdb->prepare()` to enforce the pattern, or add a documented comment explaining why prepare is not needed (no dynamic values). For the performance issue, paginate — see PAY-PERF-001.

---

### PAY-SEC-002 — High
**File:** `includes/Modules/Payroll/Rest/Payroll_Rest.php:51`
**Issue:** `/payroll/my-payslips` permission callback uses `is_user_logged_in()` as a fallback gate.

```php
'permission_callback' => fn() => current_user_can( 'sfs_hr_payslip_view' ) || is_user_logged_in(),
```

Any authenticated WordPress user — including users with no HR role (e.g., shop managers, subscribers, other plugin users on a multi-purpose site) — satisfies `is_user_logged_in()` and reaches the endpoint. The endpoint then checks for an employee record and returns a 403 if none is found, so data leakage is mitigated for non-employees, but it still exposes internal endpoint existence and error messages to all authenticated users. More importantly, if a future code change removes the employee lookup guard, all authenticated users immediately have payslip access.

**Fix:** Replace with an explicit capability check:
```php
'permission_callback' => fn() => current_user_can( 'sfs_hr_payslip_view' ) || current_user_can( 'sfs_hr.view' ),
```

---

### PAY-SEC-003 — Medium
**File:** `includes/Modules/Payroll/Rest/Payroll_Rest.php:163-178`
**Issue:** `POST /payroll/calculate-preview` exposes full salary and compensation breakdown for any `employee_id` to any holder of `sfs_hr_payroll_run` capability — no ownership or scope restriction.

```php
$employee_id = (int) ( $req['employee_id'] ?? 0 );
$period_id = (int) ( $req['period_id'] ?? 0 );
// ...
$result = PayrollModule::calculate_employee_payroll( $employee_id, $period_id );
```

A department-level manager who is granted `sfs_hr_payroll_run` can request the full salary breakdown (base salary, all allowances, loan deductions, bank IBAN) of any employee in the organization by iterating employee IDs.

**Fix:** Add an employee scope check before calling `calculate_employee_payroll`. If the requester does not have `sfs_hr.manage` or `sfs_hr_payroll_admin`, restrict to employees in their department:
```php
if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'sfs_hr_payroll_admin' ) ) {
    // Check employee is in requester's managed departments
    return new \WP_Error( 'forbidden', __( 'Access denied.', 'sfs-hr' ), [ 'status' => 403 ] );
}
```

---

### PAY-SEC-004 — Medium
**File:** `includes/Modules/Payroll/PayrollModule.php:568-593`
**Issue:** `FOR UPDATE` row lock used outside a database transaction.

```php
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $loans_table ) ) ) {
    $loans = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.id, l.monthly_installment, l.remaining_balance
         FROM {$loans_table} l
         WHERE l.employee_id = %d AND l.status = 'active' AND l.remaining_balance > 0
         FOR UPDATE",
        $employee_id
    ) );
```

`FOR UPDATE` only prevents concurrent modification of the locked rows within the **same transaction**. `calculate_employee_payroll()` is called from within a transaction in `handle_run_payroll()` (Admin_Pages.php:1431), but it can also be called standalone (e.g., from `calculate_preview` REST endpoint, or directly). When called outside a transaction, `FOR UPDATE` is a no-op and the lock provides no protection. Additionally — this issue is compounded by PAY-DUP-001 below: the column being locked (`monthly_installment`) does not exist, so the query returns NULL for every loan anyway.

**Fix:** Move `FOR UPDATE` lock logic into `handle_run_payroll()` transaction scope explicitly, or ensure `calculate_employee_payroll()` documents that it must be called within a transaction if loan deductions are to be safe. Add a `$within_transaction = false` parameter if needed.

---

## Performance Findings

### PAY-PERF-001 — High
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1459-1514`
**Issue:** Payroll run loads all active employees in one unbounded query and processes all in a single PHP request with no pagination, no memory limit guard, and no timeout protection.

```php
$employees = $wpdb->get_col(
    "SELECT id FROM {$emp_table} WHERE status = 'active'"
);

foreach ( $employees as $emp_id ) {
    $calc = PayrollModule::calculate_employee_payroll( (int) $emp_id, $period_id, [...] );
    // 4-6 DB queries per employee (see PAY-PERF-002)
    // ...
}
```

For an organization with 500 active employees, this is a single HTTP request that executes 2,000–3,000 DB queries and runs for potentially minutes. PHP's default `max_execution_time` (30s) will kill the request mid-run, leaving the transaction partially committed or rolled back, with the period stuck in `processing` status and the transient lock potentially expired.

**Fix:** Process payroll in batches via a WP Cron job or AJAX background task. Set the period to `processing` status immediately, then process employees in chunks of 50-100 per cron tick. Alternatively, increase `max_execution_time` for this specific request with `set_time_limit(0)` (with documentation of the risk) as a short-term mitigation.

---

### PAY-PERF-002 — Medium
**File:** `includes/Modules/Payroll/PayrollModule.php:305-656`
**Issue:** `calculate_employee_payroll()` executes 4–6 DB queries per employee (N+5 pattern).

Per-employee queries:
1. Employee row (line 316)
2. Period row (line 331)
3. Attendance aggregate (line 370)
4. `SHOW TABLES LIKE` for leave table (line 397) — runs on every employee
5. Approved leave days (line 402)
6. Unpaid leave days (line 417)
7. `SHOW TABLES LIKE` for loans table (line 568) — runs on every employee
8. Active loans `FOR UPDATE` (line 569)
9. Salary components join (line 284) — inside `get_employee_components()`

For 100 employees: ~900 queries. For 500 employees: ~4,500 queries.

**Fix:**
- Pre-fetch period row once before the employee loop in `handle_run_payroll()`, pass it as an `$options` parameter to avoid the per-employee period lookup.
- Cache `SHOW TABLES` results for leave and loans tables in a static variable — they will not change during a single payroll run.
- Pre-fetch all salary components once (components are shared, not per-employee), then apply per-employee amounts from `sfs_hr_employee_components` in a batch JOIN.

---

### PAY-PERF-003 — Low
**File:** `includes/Modules/Payroll/PayrollModule.php:661-678`
**Issue:** `count_working_days()` iterates day-by-day in PHP using `DatePeriod`.

```php
foreach ( $period as $date ) {
    if ( $date->format( 'N' ) != 5 ) {
        $working_days++;
    }
}
```

For a 30-day period this is trivial, but the function is called twice per employee (line 358-359) and once more for the payroll run total. For a month-long period it loops 30 times per call. Not a hot-path issue but could be replaced with a deterministic formula.

**Fix:** Use integer arithmetic: total days minus Fridays in range. A closed-form formula exists for counting weekdays excluding a specific day of week between two dates.

---

## Duplication Findings

### PAY-DUP-001 — Critical
**File:** `includes/Modules/Payroll/PayrollModule.php:570`
**Cross-reference:** LOAN-LOGIC-002 from Phase 08 — verified and confirmed

**Issue:** PayrollModule queries `l.monthly_installment` which does not exist in `sfs_hr_loans`. The actual column name is `installment_amount` (confirmed in `includes/Modules/Loans/LoansModule.php:225`).

```php
// PayrollModule.php:569-577
$loans = $wpdb->get_results( $wpdb->prepare(
    "SELECT l.id, l.monthly_installment, l.remaining_balance
     FROM {$loans_table} l
     WHERE l.employee_id = %d
       AND l.status = 'active'
       AND l.remaining_balance > 0
     FOR UPDATE",
    $employee_id
) );
```

MySQL returns `NULL` for the non-existent column `monthly_installment` without raising an error in default SQL mode. The next line:
```php
$installment = min( (float) $loan->monthly_installment, (float) $loan->remaining_balance );
```
evaluates as `min(0.0, remaining_balance)` = `0.0`. Every active loan deduction silently produces `0` on every payroll run. Employees with loans receive their full gross salary with no loan deduction applied.

**Fix:** Replace `l.monthly_installment` with `l.installment_amount`:
```php
"SELECT l.id, l.installment_amount, l.remaining_balance
 FROM {$loans_table} l ..."
// and:
$installment = min( (float) $loan->installment_amount, (float) $loan->remaining_balance );
```

This is a data-integrity Critical: loan deductions have been silently zero for every payroll run since the Payroll module was created.

---

### PAY-DUP-002 — Medium
**File:** `includes/Modules/Payroll/PayrollModule.php:661-678`
**Issue:** `count_working_days()` is a standalone static method on `PayrollModule` that duplicates working-day counting logic present in the Attendance module. No shared Core helper is used.

If the Attendance module's definition of "working day" changes (e.g., adding Saturday exclusion, adding holiday awareness), `PayrollModule::count_working_days()` will silently diverge.

**Fix:** Extract to a Core helper method (e.g., `\SFS\HR\Core\Helpers::count_working_days()`) and have both modules reference the same implementation. See also PAY-LOGIC-001 for the Saturday omission.

---

### PAY-DUP-003 — Low
**File:** `includes/Modules/Payroll/PayrollModule.php:687-688`
**Issue:** `generate_period_name()` uses raw PHP `date()` instead of WordPress `wp_date()` for formatting timestamps.

```php
if ( date( 'Y-m', $start_ts ) === date( 'Y-m', $end_ts ) ) {
    return date_i18n( 'F Y', $start_ts );
}
return date_i18n( 'M j', $start_ts ) . ' - ' . date_i18n( 'M j, Y', $end_ts );
```

The comparison `date('Y-m', ...)` uses the server's local timezone, while `date_i18n()` on line 691 uses the WordPress timezone. On servers where server timezone differs from WP timezone, a period ending at midnight could compare as the wrong month.

**Fix:** Use `wp_date( 'Y-m', $start_ts )` consistently for the comparison:
```php
if ( wp_date( 'Y-m', $start_ts ) === wp_date( 'Y-m', $end_ts ) ) {
```

---

## Logical Findings

### PAY-LOGIC-001 — High
**File:** `includes/Modules/Payroll/PayrollModule.php:671-673`
**Issue:** `count_working_days()` excludes only Friday (day 5), but Saudi Arabia's official weekend is **Friday and Saturday** (both days off).

```php
// Skip Fridays (5) - Saudi weekend
if ( $date->format( 'N' ) != 5 ) {
    $working_days++;
}
```

Saturday (day 6) is not excluded. This overstates working days by approximately 4 days per month (one Saturday per week). The payroll calculation uses `$working_days_full` as the divisor for daily rate, attendance deductions, and late deductions. Overstating `$working_days_full` by ~4 days (26 vs 22) understates the daily deduction rate by ~15%, meaning absence deductions are too small.

Example: Employee with SAR 10,000/month base. Working days (correct, Fri+Sat off): ~22. With bug (only Fri off): ~26.
Daily rate: SAR 454.5 (correct) vs SAR 384.6 (buggy). Each absent day: short-deducts by SAR 70.

**Fix:** Exclude both Friday (5) and Saturday (6):
```php
if ( ! in_array( (int) $date->format('N'), [5, 6], true ) ) {
    $working_days++;
}
```
Consider making the excluded days filterable via `apply_filters('sfs_hr_weekend_days', [5, 6])` to support organizations with different weekend schedules.

---

### PAY-LOGIC-002 — Medium
**File:** `includes/Modules/Payroll/PayrollModule.php:358-359, 661-678`
**Issue:** `count_working_days()` does not consult the `sfs_hr_holidays` option/table for public holidays.

Saudi public holidays (National Day, Eid al-Fitr, Eid al-Adha, etc.) are stored in `sfs_hr_holidays` (confirmed via option key in CLAUDE.md). The payroll working-day count ignores these, so periods containing public holidays overstate working days and understate pro-rata salary for new hires and partial-period calculations.

This is compounded by PAY-LOGIC-001: the already-wrong weekend exclusion means both the numerator and denominator of pro-rata calculations are off.

**Fix:** After computing the day-by-day count, subtract the count of holidays falling within the range:
```php
$holidays = get_option( 'sfs_hr_holidays', [] );
// filter $holidays to those within [$start, $end] and subtract from $working_days
```

---

### PAY-LOGIC-003 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1411-1417`
**Issue:** Transient-based payroll concurrency lock has a TOCTOU (Time-Of-Check-Time-Of-Use) race condition.

```php
$lock_key = 'sfs_hr_payroll_lock_' . $period_id;
if ( get_transient( $lock_key ) ) {          // check
    wp_safe_redirect( ... );
    exit;
}
set_transient( $lock_key, get_current_user_id(), 600 ); // use (set)
```

Two simultaneous requests can both read no transient (check = false) and both proceed to `set_transient` before either sets it. On a non-object-cache WordPress installation, `set_transient` uses `wp_options` which supports `INSERT ... ON DUPLICATE KEY` update but the read-check-write pattern is not atomic at the application layer.

This is a lower-risk race on typical shared hosting (no true concurrency), but on multi-threaded or multi-process hosts (LiteSpeed, PHP-FPM with multiple workers) two simultaneous payroll runs for the same period can proceed, resulting in duplicate `sfs_hr_payroll_items` rows for every employee.

**Fix:** Replace the transient-based lock with a MySQL advisory lock:
```php
$lock_result = $wpdb->get_var(
    $wpdb->prepare( "SELECT GET_LOCK(%s, 0)", 'sfs_hr_payroll_' . $period_id )
);
if ( $lock_result !== '1' ) {
    wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-payroll&error=payroll_in_progress' ) );
    exit;
}
// ... run payroll ...
$wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", 'sfs_hr_payroll_' . $period_id ) );
```
`GET_LOCK(name, 0)` returns immediately (0 = no wait timeout) and is atomic at the MySQL level. This is the same pattern correctly used in `Attendance/Services/Session_Service.php`.

---

### PAY-LOGIC-004 — Medium
**File:** `includes/Modules/Payroll/PayrollModule.php:396-430`
**Issue:** `SHOW TABLES LIKE %s` called with full table name including `$wpdb->prefix`.

```php
$leave_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $leave_table ) );
```

Where `$leave_table = $wpdb->prefix . 'sfs_hr_leave_requests'`. The `SHOW TABLES LIKE` pattern uses SQL wildcard characters (`%` and `_`). On WordPress installs where the table prefix contains underscores (very common, e.g., `wp_` → no issue, but `wp_mysite_` has underscores), the `_` in the prefix matches any single character in `SHOW TABLES LIKE` without escaping. This can cause the check to return a false positive (match a different table).

Additionally, this check runs **per employee** on every payroll run (inside `calculate_employee_payroll()`), meaning for 100 employees it executes 200 `SHOW TABLES` queries just to check table existence — already covered in PAY-PERF-002 but the correctness issue is separate.

**Fix:** Escape wildcards before passing to `SHOW TABLES LIKE`:
```php
$escaped = str_replace( ['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $leave_table );
$leave_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $escaped ) );
```
Or cache the result in a static variable so the check runs only once per request.

---

### PAY-LOGIC-005 — Low
**File:** `includes/Modules/Payroll/PayrollModule.php:351-367`
**Issue:** Pro-rata calculation produces `$pro_rata = 0` when the employee's hire date falls on the period end date and that date is a non-working day (e.g., a Friday hire date).

```php
$working_days = self::count_working_days( $effective_start, $end_date );
// Guard only covers working_days_full <= 0, not working_days = 0
$pro_rata = $working_days / $working_days_full;
```

If `$hire_date` equals `$end_date` and `$end_date` is a Friday, `count_working_days($end_date, $end_date)` = 0. The function does not return an error; instead `$pro_rata = 0.0` and all salary components become 0. The employee receives a zero payslip for their first month rather than a meaningful error message.

This is a realistic edge case: new hires joining on the last Friday of a month.

**Fix:** Add a guard after computing `$working_days`:
```php
if ( $working_days <= 0 ) {
    // Employee started on last day of period which is a non-working day
    // Carry over to next period or handle as 1 day
    return [ 'error' => 'Employee effective start date falls on a non-working day at period end — defer to next period' ];
}
```

---

## Cross-Module Reference: LOAN-LOGIC-002 Verification

**Status: CONFIRMED and CRITICAL**

Phase 08 finding LOAN-LOGIC-002 stated: "PayrollModule references `l.monthly_installment` which does not exist in `sfs_hr_loans` schema — loan deductions silently fail on every payroll run."

This finding is **confirmed by direct code inspection**:

- `sfs_hr_loans` schema (LoansModule.php:225): column is `installment_amount DECIMAL(12,2) NOT NULL`
- PayrollModule.php:570: `SELECT l.id, l.monthly_installment, l.remaining_balance FROM {$loans_table}`
- MySQL behavior: selecting a non-existent column in a `SELECT` returns `NULL` (not an error) in lenient SQL mode
- Result: `(float) NULL = 0.0`, so `min(0.0, remaining_balance) = 0.0` → zero loan deduction on every employee

This bug has been present since the Payroll module was written. Every employee with an active loan has received their full gross salary without loan deduction on every payroll run. This is a Critical data-integrity issue requiring immediate fix before the next payroll run.

The fix is tracked as PAY-DUP-001 above.

---

## All $wpdb Call Accounting

### PayrollModule.php

| Line | Method | Prepared? | Status |
|------|--------|-----------|--------|
| 220 | `get_var("SELECT COUNT(*) FROM {$table}")` | No | Pattern violation — no user input, low risk, but should use prepare |
| 284 | `get_results($wpdb->prepare(...))` | Yes | OK |
| 316 | `get_row($wpdb->prepare(...))` | Yes | OK |
| 331 | `get_row($wpdb->prepare(...))` | Yes | OK |
| 370 | `get_row($wpdb->prepare(...))` | Yes | OK |
| 397 | `get_var($wpdb->prepare("SHOW TABLES LIKE %s", ...))` | Yes | OK — wildcard escape concern (PAY-LOGIC-004) |
| 402 | `get_var($wpdb->prepare(...))` | Yes | OK |
| 417 | `get_var($wpdb->prepare(...))` | Yes | OK |
| 568 | `get_var($wpdb->prepare("SHOW TABLES LIKE %s", ...))` | Yes | OK — wildcard escape concern (PAY-LOGIC-004) |
| 569 | `get_results($wpdb->prepare(...))` | Yes | Wrong column name (PAY-DUP-001 Critical) |

### Payroll_Rest.php

| Line | Method | Prepared? | Status |
|------|--------|-----------|--------|
| 83 | `get_results($wpdb->prepare($sql, ...$args))` | Yes (conditional) | OK — status validated against whitelist |
| 117 | `get_results($wpdb->prepare($sql, ...$args))` | Yes (conditional) | OK |
| 134 | `get_row($wpdb->prepare(...))` | Yes | OK |
| 146 | `get_results($wpdb->prepare(...))` | Yes | OK |
| 193 | `get_row($wpdb->prepare(...))` | Yes | OK |
| 204 | `get_results($wpdb->prepare(...))` | Yes | OK |
| 253-259 | `get_results($sql)` without prepare when `$args` is empty | Conditional | **Medium concern**: when no type filter and `active_only=false`, `$args` is empty and `$sql = $wpdb->prepare($sql, ...$args)` is skipped (line 255-257), so `get_results($sql)` is called without prepare. The SQL has no user input at that point (table name from server-side `$wpdb->prefix`), but this is a pattern to flag. |

### Admin_Pages.php (payroll run orchestration, lines 1391-1626)

| Line | Method | Prepared? | Status |
|------|--------|-----------|--------|
| 1419 | `get_row($wpdb->prepare(...))` | Yes | OK |
| 1435 | `get_var($wpdb->prepare(...))` | Yes | OK |
| 1444 | `insert(...)` | Yes (insert) | OK |
| 1459 | `get_col("SELECT id FROM ...")` | No | PAY-SEC-001 — no user input but pattern violation |
| 1479 | `insert(...)` | Yes (insert) | OK |
| 1517 | `update(...)` | Yes (update) | OK |
| 1533 | `update(...)` | Yes (update) | OK |
| 1574 | `get_row($wpdb->prepare(...))` | Yes | OK |
| 1588 | `update(...)` | Yes (update) | OK |
| 1601 | `get_results($wpdb->prepare(...))` | Yes | OK |
| 1609 | `insert(...)` | Yes (insert) | OK |
| 1619 | `update(...)` | Yes (update) | OK |

---

## REST Endpoint Permission Callback Summary

| Endpoint | Method | Permission Callback | Status |
|----------|--------|---------------------|--------|
| `/payroll/periods` | GET | `sfs_hr_payroll_view` OR `sfs_hr.manage` | OK |
| `/payroll/runs` | GET | `sfs_hr_payroll_view` OR `sfs_hr.manage` | OK |
| `/payroll/runs/{id}` | GET | `sfs_hr_payroll_view` OR `sfs_hr.manage` | OK |
| `/payroll/calculate-preview` | POST | `sfs_hr_payroll_run` OR `sfs_hr.manage` | OK — but no employee ownership scope (PAY-SEC-003) |
| `/payroll/my-payslips` | GET | `sfs_hr_payslip_view` OR `is_user_logged_in()` | High — too-broad fallback (PAY-SEC-002) |
| `/payroll/components` | GET | `sfs_hr_payroll_admin` OR `sfs_hr.manage` | OK |

No `__return_true` endpoints found. All routes have explicit capability checks (with the one concern above).

---

## Payroll Run Capability Guard

**Admin POST handler** (`handle_run_payroll`, Admin_Pages.php:1391-1395):
```php
if ( ! current_user_can( 'sfs_hr_payroll_run' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
    wp_die(...);
}
check_admin_referer( 'sfs_hr_payroll_run_payroll' );
```
Capability check is first, nonce validation is second — correct order, properly guarded.

**Admin POST handler** (`handle_approve_run`, Admin_Pages.php:1560-1564):
```php
if ( ! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
    wp_die(...);
}
check_admin_referer( 'sfs_hr_payroll_approve_run' );
```
Properly guarded. `manage_options` as a fallback is acceptable (super-admin).

**Conclusion:** Payroll run orchestration capability and nonce guards are correct — no Critical issues found in the run/approve flow itself.

---

## Positive Observations

1. **Transaction wrapping:** The payroll run is wrapped in `START TRANSACTION`/`COMMIT`/`ROLLBACK` (Admin_Pages.php:1431, 1538, 1548) — correct practice for a multi-row financial operation.
2. **Net salary floor:** `max(0, $gross_salary - $total_deductions)` at PayrollModule.php:604 prevents negative net pay.
3. **Rounding strategy:** Amounts are accumulated as floats and rounded once at the end (PayrollModule.php:606-618) — correct approach to prevent cumulative rounding error.
4. **Pro-rata for mid-period hires:** Logic exists and correctly adjusts salary for employees hired mid-period (PayrollModule.php:351-367).
5. **Approved leave cross-check:** The payroll deduction logic cross-checks absences against approved leave records to avoid deducting for paid leave days (PayrollModule.php:395-433) — a thoughtful integration.
6. **No `__return_true` endpoints:** Every REST endpoint has a real permission callback.
