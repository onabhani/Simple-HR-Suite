# 08-01: Loans Module — Orchestrator, Frontend, and Notifications Audit

**Date:** 2026-03-16
**Files audited:**
- `includes/Modules/Loans/LoansModule.php` (537 lines)
- `includes/Modules/Loans/Frontend/class-my-profile-loans.php` (810 lines)
- `includes/Modules/Loans/class-notifications.php` (720 lines)

---

## Summary Table

| Category  | Critical | High | Medium | Low | Total |
|-----------|----------|------|--------|-----|-------|
| Security  | 2        | 2    | 1      | 1   | 6     |
| Performance | 0      | 2    | 1      | 0   | 3     |
| Duplication | 0      | 1    | 2      | 1   | 4     |
| Logical   | 0        | 3    | 2      | 1   | 6     |
| **Total** | **2**    | **8**| **6**  | **3**| **19**|

---

## Security Findings

### LOAN-SEC-001
**Severity:** Critical
**File:** `includes/Modules/Loans/LoansModule.php:64`
**Issue:** Unprepared SQL in `check_tables_notice()` — SHOW TABLES LIKE with bare string interpolation.

```php
$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$loans_table}'" ) === $loans_table;
```

`$loans_table` is constructed as `$wpdb->prefix . 'sfs_hr_loans'`, which is not user input, but WPCS considers any unquoted `$wpdb->prefix` interpolation a violation. The same pattern appears at line 125 (`maybe_upgrade_db`) and line 154 (`on_activation`). The real risk is that `$wpdb->prefix` is set from `DB_TABLE_PREFIX` which an attacker with wp-config write access could manipulate; the pattern also teaches unsafe habits and must be fixed for WPCS compliance.

**Occurrences:**
- LoansModule.php:64 — `check_tables_notice()`
- LoansModule.php:125 — `maybe_upgrade_db()`
- LoansModule.php:154 — `on_activation()`
- LoansModule.php:159, 172, 183, 194, 205 — `on_activation()` SHOW COLUMNS calls (all interpolated, none prepared)

**Fix:** Use `$wpdb->prepare( "SHOW TABLES LIKE %s", $loans_table )` and `$wpdb->prepare( "SHOW COLUMNS FROM {$loans_table} LIKE %s", 'column_name' )`.

---

### LOAN-SEC-002
**Severity:** Critical
**File:** `includes/Modules/Loans/LoansModule.php:131–136`
**Issue:** `maybe_upgrade_db()` executes raw `ALTER TABLE` without `$wpdb->prepare()`.

```php
$wpdb->query(
    "ALTER TABLE {$loans_table}
    ADD COLUMN cancellation_reason text DEFAULT NULL AFTER cancelled_at"
);
```

While `$loans_table` is not direct user input, this is the same ALTER TABLE antipattern flagged Critical in Phase 04 (Core audit, `admin_init` block). It bypasses WPCS rules and sets a precedent for DDL statements to be unguarded. The identical pattern appears across `on_activation()` at lines 164–167, 177–179, 188–190, 198–200, 209–211. These run on every admin load until `sfs_hr_loans_db_version` reaches `1.1`.

**Fix:** Follow the `add_column_if_missing()` pattern from `Migrations.php`. Move all ALTER TABLE DDL out of `maybe_upgrade_db()` and `on_activation()` into the central `Migrations::run()` method, protected by the idempotency helper.

---

### LOAN-SEC-003
**Severity:** High
**File:** `includes/Modules/Loans/Frontend/class-my-profile-loans.php:502–510`
**Issue:** Nonce action string is employee-ID-scoped, but `$employee_id` is read from `$_POST` *before* the nonce is verified. An attacker who can forge `employee_id` and obtain a corresponding nonce could craft a valid request.

```php
$employee_id = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;
// Verify nonce
check_admin_referer( 'sfs_hr_submit_loan_request_' . $employee_id );
```

Although the subsequent ownership check (lines 524–531) catches this IDOR attempt — `WHERE e.id = %d AND e.user_id = %d` — the nonce pattern is structurally unsound. The nonce should be generated with the server-known employee ID, not the attacker-supplied one.

**Fix:** Derive `$employee_id` from the server side only for nonce verification:
```php
$current_user_id = get_current_user_id();
$emp = $wpdb->get_row( $wpdb->prepare(
    "SELECT id FROM {$emp_table} WHERE user_id = %d AND status = 'active'",
    $current_user_id
) );
$employee_id = $emp ? (int) $emp->id : 0;
check_admin_referer( 'sfs_hr_submit_loan_request_' . $employee_id );
```

---

### LOAN-SEC-004
**Severity:** High
**File:** `includes/Modules/Loans/Frontend/class-my-profile-loans.php:761–763`
**Issue:** AJAX handler (`handle_loan_request_ajax`) reads `$employee_id` from `$_POST` before nonce verification — same structural IDOR as LOAN-SEC-003, but the order is even more dangerous in the AJAX path: if `wp_verify_nonce()` returns false for employee_id=0 (attacker supplied), the handler terminates, but the nonce is never validated against the *real* server-owned employee_id.

```php
$employee_id = isset( $_POST['employee_id'] ) ? (int) $_POST['employee_id'] : 0;
if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'sfs_hr_submit_loan_request_' . $employee_id ) ) {
```

**Fix:** Same as LOAN-SEC-003 — derive employee_id from current user before nonce verification.

---

### LOAN-SEC-005
**Severity:** Medium
**File:** `includes/Modules/Loans/class-notifications.php:648`
**Issue:** `send_notification()` applies filter `dofs_user_wants_email_notification` which is namespaced for a *different plugin* (`dofs_`). This suggests a copy-paste from the DOFS plugin. If the DOFS plugin is not active, the default `true` fires. If it is active, DOFS can suppress or intercept loan notifications for any user without SFS HR's knowledge — an unintended cross-plugin dependency violating the project boundary rule ("No direct dependency on other plugins").

```php
if ( ! apply_filters( 'dofs_user_wants_email_notification', true, $user_id, $notification_type ) ) {
```

**Fix:** Rename filter to `sfs_hr_user_wants_email_notification` and add a corresponding `apply_filters( 'sfs_hr_should_send_notification_now', ... )` call. Remove the `dofs_` references.

---

### LOAN-SEC-006
**Severity:** Low
**File:** `includes/Modules/Loans/class-notifications.php:370–378`
**Issue:** HR notification emails for new loan requests include the full `$loan->reason` (employee-provided free text) without sanitization before including it in a plain-text email. While the email transport (plain text) limits XSS risk, it means unescaped user input is embedded in emails sent to admins. An employee can craft a reason that includes email header injection patterns.

**Fix:** Apply `sanitize_textarea_field()` to `$loan->reason` before email composition, or strip newlines: `str_replace( ["\r", "\n"], ' ', $loan->reason )`.

---

## Performance Findings

### LOAN-PERF-001
**Severity:** High
**File:** `includes/Modules/Loans/Frontend/class-my-profile-loans.php:386–391` and `204–207`
**Issue:** N+1 query pattern — both `render_loans_list()` and `render_admin_loans_view()` loop over all loans and execute a `SELECT COUNT(*)` per loan to get the `paid_count`.

```php
foreach ( $loans as $loan ) {
    $paid_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$payments_table} WHERE loan_id = %d AND status = 'paid'",
        $loan->id
    ) );
```

For an employee with 10 loans (common with annual loans over a career), this is 10 extra queries per page load. For an admin viewing all-employee loans, it scales to N × employees × loans.

**Fix:** Use a single `GROUP BY` pre-fetch before the loop:
```php
$loan_ids = wp_list_pluck( $loans, 'id' );
$placeholders = implode( ',', array_fill( 0, count( $loan_ids ), '%d' ) );
$paid_counts = $wpdb->get_results( $wpdb->prepare(
    "SELECT loan_id, COUNT(*) as cnt FROM {$payments_table}
     WHERE loan_id IN ($placeholders) AND status = 'paid'
     GROUP BY loan_id",
    ...$loan_ids
), OBJECT_K );
```
Then use `$paid_counts[ $loan->id ]->cnt ?? 0` inside the loop.

---

### LOAN-PERF-002
**Severity:** High
**File:** `includes/Modules/Loans/Frontend/class-my-profile-loans.php:415–418`
**Issue:** Inside `render_loans_list()`, for each active/completed loan, the full payment schedule is fetched with `SELECT *` — no limit and no pagination. An employee with a 60-month loan will trigger a query returning 60 rows, all loaded into PHP memory and rendered as an expandable table.

```php
$payments = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$payments_table} WHERE loan_id = %d ORDER BY sequence ASC",
    $loan->id
) );
```

If `render_loans_list()` is called inside `render_loans_content()` where the outer loop already fetches all loans, a 60-installment 3-loan history is 180 payment rows loaded simultaneously.

**Fix:** Lazy-load the payment schedule via AJAX when the user expands the detail row, or limit the initial fetch to the next 3 upcoming planned installments plus the count.

---

### LOAN-PERF-003
**Severity:** Medium
**File:** `includes/Modules/Loans/LoansModule.php:506–514` and `520–528`
**Issue:** `get_gm_users()` and `get_finance_users()` each call `get_userdata()` in a loop with no in-memory cache between calls. Both functions are called on every notification event. If GM list has 5 users, that is 5 individual `get_userdata()` calls (each potentially hitting the DB if the user object cache is cold).

**Fix:** `get_userdata()` uses WordPress's object cache so this is low-cost when warm, but the settings are re-fetched with `get_option()` on every call with no static cache. Add a `static $cache = null;` guard or use `wp_cache_get()` for the combined user list.

---

## Duplication Findings

### LOAN-DUP-001
**Severity:** High
**File:** Frontend: `class-my-profile-loans.php:576–578` vs Admin: `class-admin-pages.php:2199` and `2615`
**Issue:** Installment count calculation exists in two fundamentally different forms with different rounding behavior:

**Frontend formula** (`validate_and_insert_loan`, line 576–578):
```php
$full_months  = $monthly_amount > 0 ? (int) floor( $principal / $monthly_amount ) : 0;
$last_payment = $principal - ( $full_months * $monthly_amount );
$installments = $last_payment > 0 ? $full_months + 1 : $full_months;
```
This computes installments from a requested *monthly amount*, resulting in a possible non-uniform last installment.

**Admin formula** (`handle_approve_finance_form`, line 2199):
```php
$installment_amount = round( $principal / $installments, 2 );
```
This is the inverse: admin sets the installment *count* and the amount is derived. No last-installment rounding adjustment.

The two paths produce structurally different schedules — one employee-driven (amount→count), one admin-driven (count→amount). The `installment_amount` stored in the DB via the frontend path is `round($monthly_amount, 2)` (a clean number), while the schedule is generated from admin-input count with `principal / installments` (which may not be exact). These two code paths can result in `installment_amount * installments_count ≠ principal_amount`, which means remaining_balance will never reach zero cleanly.

**Fix:** Extract a shared `calculate_schedule( float $principal, float $installment_amount ): array` helper in `LoansModule.php` that returns `['installments' => N, 'installment_amount' => X, 'last_installment_amount' => Y]`. Both frontend and admin paths must use it.

---

### LOAN-DUP-002
**Severity:** Medium
**File:** `class-my-profile-loans.php:572–752` and `class-admin-pages.php:2425–2665`
**Issue:** Policy validation logic (max loan amount, salary multiplier, installment percentage, min service months, fiscal year one-loan check, max active loans) is fully implemented in `validate_and_insert_loan()` in the frontend class. The admin "add new loan" path in `class-admin-pages.php` implements its own independent copy of several of these checks (lines 2441–2603). The two copies diverge: the admin path checks `max_installment_percent` but using `principal / installments` instead of the raw monthly_amount; the admin path does NOT check the `one_loan_per_fiscal_year` constraint; the admin path does NOT check `min_service_months`.

This means admins can create loans that bypass fiscal year and service period constraints.

**Fix:** Move all validation to a static `LoansModule::validate_loan_request()` method and call it from both paths.

---

### LOAN-DUP-003
**Severity:** Medium
**File:** `class-notifications.php:351–448` vs `class-notifications.php:370–447`
**Issue:** `notify_hr_loan_event()` rebuilds email body with plain `sprintf()` while `get_email_template()` provides a consistent template system used by all other notification methods. The HR notification bypasses the template system and produces a different (less formatted) email layout.

**Fix:** Add an `'hr_event_notice'` template entry to `get_email_template()` or replace the `sprintf()` body in `notify_hr_loan_event()` with a template call.

---

### LOAN-DUP-004
**Severity:** Low
**File:** `class-my-profile-loans.php:466–491`
**Issue:** `get_status_badge()` and `get_payment_status_badge()` are private instance methods in `MyProfileLoans`, while the Admin pages class almost certainly has identical badge rendering for the same statuses. Both produce inline `<span style="...">` HTML with the same color scheme. This is view-level duplication that should be a shared helper.

**Fix:** Move status badge rendering to a `LoansModule::render_status_badge( string $status, string $context = 'loan' ): string` static helper.

---

## Logical Findings

### LOAN-LOGIC-001
**Severity:** High
**File:** `includes/Modules/Loans/Frontend/class-my-profile-loans.php:576–578` and `includes/Modules/Loans/Admin/class-admin-pages.php:2199, 2615`
**Issue:** Rounding remainder — the installment calculation creates a last-installment rounding imbalance.

**Frontend path** stores `installment_amount = round($monthly_amount, 2)`. The schedule is not generated at frontend request time — it is generated by admin at finance-approval time using `round($principal / $installments, 2)`. These two numbers are often different. Example: principal=1000, monthly_amount=333 → frontend stores installments=4 (3×333 + 1×1 remainder), installment_amount=333.00 SAR. Admin at finance approval recalculates installment_amount = round(1000/4, 2) = 250.00 SAR. The payment schedule has 4×250 = 1000, but `remaining_balance` is set to `principal_amount` (1000.00). The last installment always ends at zero because schedule sum = principal. However, if `remaining_balance` is set incorrectly during approval (see admin line 2207: `remaining_balance = %f` = principal), and Payroll deducts by `MIN(monthly_installment, remaining_balance)` where `monthly_installment` is the column stored from the employee request (333 SAR) while the schedule has 250 SAR — the two systems are inconsistent.

**Fix:** Unify to a single installment_amount stored in the DB, computed at finance-approval time. The employee request should only store `principal` and `installments_count`; the `installment_amount` column should only be written at finance approval.

---

### LOAN-LOGIC-002
**Severity:** High
**File:** `includes/Modules/Loans/Frontend/class-my-profile-loans.php:708` and `includes/Modules/Payroll/PayrollModule.php:580`
**Issue:** `remaining_balance` is set to `0` on loan creation (line 708: `'remaining_balance' => 0`). The balance is only set to `principal_amount` at Finance approval time (admin pages). However, Payroll deducts based on `remaining_balance > 0` and `status = 'active'`. This means:
- Before finance approval, `remaining_balance = 0` → Payroll will not deduct (correct).
- After finance approval, `remaining_balance = principal_amount` → Payroll deducts `MIN(monthly_installment, remaining_balance)`.

But the deduction uses `l.monthly_installment` (the column name in PayrollModule.php:570), while the loans table stores the column as `installment_amount`. The Payroll query references a column `monthly_installment` that **does not exist** in the `sfs_hr_loans` schema (CREATE TABLE shows `installment_amount`, not `monthly_installment`). This is a **runtime fatal bug** — Payroll loan deductions will silently fail or return NULL on every payroll run, meaning employees' loan balances are never reduced by Payroll.

**Fix:** Correct `PayrollModule.php:570` to reference `l.installment_amount` instead of `l.monthly_installment`, or add an alias column `monthly_installment` to the loans table definition.

---

### LOAN-LOGIC-003
**Severity:** High
**File:** `includes/Modules/Loans/Frontend/class-my-profile-loans.php:656–658`
**Issue:** Service period calculation uses `hired_at ?? hire_date` (lines 637–638), preferring `hired_at`. The CLAUDE.md project convention explicitly states: "use `hire_date` for new code." The field `hired_at` may be null or contain a different date than `hire_date` on some records (dual-column gotcha). The fallback is correct, but the preference order is backwards — `hire_date` should be primary.

**Fix:** Change line 637 to `$hired_at = $employee->hire_date ?? $employee->hired_at ?? null;`

---

### LOAN-LOGIC-004
**Severity:** Medium
**File:** `includes/Modules/Loans/Frontend/class-my-profile-loans.php:580–581`
**Issue:** Zero-installment edge case — if `$monthly_amount > $principal` (employee requests to pay the entire loan in one month), `floor($principal / $monthly_amount)` = 0, `$last_payment = $principal - 0 = $principal > 0`, so `$installments = 0 + 1 = 1`. This is correct behavior.

However, if `$monthly_amount = 0` (submitted via modified form), the guard on line 576 returns `$full_months = 0` and `$last_payment = $principal - 0 = $principal > 0`, resulting in `$installments = 1`. But the subsequent validation at line 581 catches `$installments <= 0` — this is fine. The actual logical gap: a negative `$monthly_amount` (e.g., `-100`) cast from `(float)$_POST['monthly_amount']` produces `$full_months = (int)floor($principal / -100)` which is a large negative integer. After the guard `$full_months > 0`, the negative result still passes `$installments > 0` on line 581 if `$last_payment = $principal - (negative * negative_amount)` overflows positively.

Example: principal=1000, monthly=-100 → floor(1000/-100) = -10 → $last_payment = 1000-(-10*-100) = 1000-1000 = 0 → installments=-10 → caught by `$installments <= 0`. Actually safe. But: monthly=-1 → floor(1000/-1) = -1000 → last_payment = 1000-(-1000*-1) = 0 → installments=-1000 → caught.

However the HTML input has `min="1"` which only enforces on client side. The real issue: `(float) $_POST['monthly_amount']` accepts negative floats. Adding `abs()` or explicit `> 0` guards would be defensive.

**Fix:** Add explicit `$monthly_amount = max(0.01, $monthly_amount)` or return invalid input error if either input is non-positive before arithmetic.

---

### LOAN-LOGIC-005
**Severity:** Medium
**File:** `includes/Modules/Loans/Frontend/class-my-profile-loans.php:654–686`
**Issue:** Fiscal year "one loan per year" check has a TOCTOU race condition. The check-then-insert pattern is:
1. `SELECT COUNT(*) ... WHERE employee_id = %d AND status NOT IN ('rejected','cancelled')` → returns 0
2. `$wpdb->insert( $loans_table, [...] )` → inserts the record

Between steps 1 and 2, a concurrent submission from the same employee (double-click, two browser tabs, or race) can pass the same check and both get inserted. Neither submission sees the other's pending loan.

**Fix:** Wrap the `SELECT COUNT` and `INSERT` in a transaction with `$wpdb->query('START TRANSACTION')` and `COMMIT`, or add a unique constraint on `(employee_id, fiscal_year)` for the active-per-year enforcement.

---

### LOAN-LOGIC-006
**Severity:** Low
**File:** `includes/Modules/Loans/LoansModule.php:344–374`
**Issue:** `get_default_settings()` includes `max_installment_amount => 0` as a distinct setting key (line 349), but `validate_and_insert_loan()` never checks `max_installment_amount`. The admin form at `class-admin-pages.php:1239` shows a UI field for it, and `save_settings()` saves it (line 1531), but neither the frontend nor the admin loan request handler enforces it. The setting has no effect — it is dead configuration.

**Fix:** Either remove `max_installment_amount` from the settings schema and UI, or add enforcement in `validate_and_insert_loan()` alongside the existing `max_installment_percent` check.

---

## Cross-Module Note (Payroll Interaction)

The most severe logical issue (LOAN-LOGIC-002) is a cross-module bug: `PayrollModule.php:570` queries `l.monthly_installment` which does not exist in `sfs_hr_loans`. This column mismatch means every payroll run silently returns NULL for loan deductions. The `remaining_balance` is never updated by Payroll, loans never complete, and employees are never properly charged. This finding satisfies CRIT-04 requirement for the Loans audit.

---

## $wpdb Query Inventory

All `$wpdb` calls across the three files evaluated:

| File | Line | Method | Prepared? | Finding |
|------|------|--------|-----------|---------|
| LoansModule.php | 64 | get_var | No | LOAN-SEC-001 |
| LoansModule.php | 125 | get_var | No | LOAN-SEC-001 |
| LoansModule.php | 131 | get_results | No | LOAN-SEC-001 |
| LoansModule.php | 133–136 | query (ALTER) | No | LOAN-SEC-002 |
| LoansModule.php | 154 | get_var | No | LOAN-SEC-001 |
| LoansModule.php | 158–160 | get_results | No | LOAN-SEC-001 |
| LoansModule.php | 164–169 | query (ALTER) | No | LOAN-SEC-002 |
| LoansModule.php | 172–174 | get_results | No | LOAN-SEC-001 |
| LoansModule.php | 177–179 | query (ALTER) | No | LOAN-SEC-002 |
| LoansModule.php | 183–185 | get_results | No | LOAN-SEC-001 |
| LoansModule.php | 188–190 | query (ALTER) | No | LOAN-SEC-002 |
| LoansModule.php | 194–196 | get_results | No | LOAN-SEC-001 |
| LoansModule.php | 198–200 | query (ALTER) | No | LOAN-SEC-002 |
| LoansModule.php | 205–207 | get_results | No | LOAN-SEC-001 |
| LoansModule.php | 209–211 | query (ALTER) | No | LOAN-SEC-002 |
| LoansModule.php | 401 | insert | Yes (array) | Safe |
| LoansModule.php | 417–424 | get_var (prepare) | Yes | Safe |
| LoansModule.php | 435–441 | get_var (prepare) | Yes | Safe |
| Frontend:84–88 | 84 | get_results (prepare) | Yes | Safe |
| Frontend:140–143 | 140 | get_results (prepare) | Yes | Safe |
| Frontend:204–207 | 204 | get_var (prepare) | Yes | Safe (N+1) LOAN-PERF-001 |
| Frontend:388–391 | 388 | get_var (prepare) | Yes | Safe (N+1) LOAN-PERF-001 |
| Frontend:415–418 | 415 | get_results (prepare) | Yes | Safe (unbounded) LOAN-PERF-002 |
| Frontend:524–531 | 524 | get_row (prepare) | Yes | Safe |
| Frontend:672–677 | 672 | get_var (prepare) | Yes | Safe |
| Frontend:692–696 | 692 | get_var (prepare) | Yes | Safe |
| Frontend:708 | 708 | insert (array) | Yes | Safe |
| Frontend:781–788 | 781 | get_row (prepare) | Yes | Safe |
| Notifications:463–476 | 463 | get_row (prepare) | Yes | Safe |
