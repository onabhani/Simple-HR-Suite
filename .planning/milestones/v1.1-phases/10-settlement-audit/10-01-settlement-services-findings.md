# Settlement Services Audit Findings
**Phase:** 10 — Settlement Audit
**Plan:** 10-01
**Files audited:**
- `includes/Modules/Settlement/SettlementModule.php` (87 lines)
- `includes/Modules/Settlement/Services/class-settlement-service.php` (296 lines)
- `includes/Modules/Settlement/Handlers/class-settlement-handlers.php` (288 lines)

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 1 | 1 | 0 | 0 | 2 |
| Performance | 0 | 1 | 1 | 0 | 2 |
| Duplication | 0 | 1 | 2 | 1 | 4 |
| Logical | 0 | 3 | 2 | 1 | 6 |
| **Total** | **1** | **6** | **5** | **2** | **14** |

---

## Security Findings

### SETT-SEC-001
**Severity:** Critical
**File:** `includes/Modules/Settlement/Services/class-settlement-service.php:107-115`
**Description:** `get_pending_resignations()` executes an unprepared SQL query via `$wpdb->get_results($sql, ARRAY_A)` where `$sql` is a raw string. No dynamic user input is present in this particular call (it uses only static table names and a hardcoded status literal `'approved'`), so there is no direct injection vector here. However, the pattern is a policy violation: the project convention is to use `$wpdb->prepare()` for all `$wpdb->get_results()` calls, and static-query raw calls have been flagged in prior phases (Phase 09) as pattern violations. More critically, if `$status` were ever parameterized in a future change without `prepare()`, this query would immediately become injectable. The query also performs no pagination — it returns all qualifying rows, which is also a performance concern (see SETT-PERF-001).

**Fix:** Wrap in `$wpdb->prepare()` even for fully-static queries to enforce consistent safe patterns and prevent future regression:
```php
$sql = $wpdb->prepare(
    "SELECT r.*, e.employee_code, e.first_name, e.last_name, e.base_salary, e.hire_date, e.hired_at
     FROM %i r
     JOIN %i e ON e.id = r.employee_id
     LEFT JOIN %i s ON s.resignation_id = r.id
     WHERE r.status = 'approved' AND s.id IS NULL
     ORDER BY r.last_working_day DESC",
    $resign_table, $emp_table, $settle_table
);
```
Alternatively, per Phase 09 convention: confirm static queries are documented as intentionally safe (no user-controlled variables). At minimum, add a `// @safe-static-query` inline comment.

---

### SETT-SEC-002
**Severity:** High
**File:** `includes/Modules/Settlement/Handlers/class-settlement-handlers.php:77-79`, `124-126`
**Description:** `handle_update()` and `handle_approve()` both call `Settlement_Service::get_settlement($settlement_id)` after passing the nonce and capability checks, but they do not verify that the retrieved settlement belongs to the current user's scope. Any `sfs_hr.manage` user can update or approve any other manager's draft settlement by supplying a different `settlement_id`. While `sfs_hr.manage` is a privileged admin-level capability, for financial records of this sensitivity, an ownership or scoping check (e.g., verifying the settlement was created within the requesting manager's department) is missing. `handle_reject()` has the same gap — it calls `update_status()` directly without first fetching the settlement to confirm it exists or is in a state allowing rejection (no status guard).

**Fix:** In `handle_reject()`, fetch and validate the settlement before update, consistent with `handle_update()`. Consider adding department-scoping assertions for organizations with multiple HR managers:
```php
$settlement = Settlement_Service::get_settlement($settlement_id);
if (!$settlement || !in_array($settlement['status'], ['pending'], true)) {
    wp_die(__('Settlement not found or cannot be rejected.', 'sfs-hr'));
}
```

---

## Performance Findings

### SETT-PERF-001
**Severity:** High
**File:** `includes/Modules/Settlement/Services/class-settlement-service.php:101-115`
**Description:** `get_pending_resignations()` is unbounded — it fetches all approved resignations that have no associated settlement with no LIMIT or pagination. For organizations with many historical approved resignations (including those from prior years that have since been settled via other means), this could return hundreds of rows. The function is called on every render of the settlement creation form (`class-settlement-form.php:19`).

**Fix:** Add pagination support or a reasonable LIMIT with a `last_working_day` recency filter (e.g., only resignations from the last 2 years):
```php
ORDER BY r.last_working_day DESC LIMIT 200
```
Or add optional `$limit` and `$offset` parameters mirroring `get_settlements()`.

---

### SETT-PERF-002
**Severity:** Medium
**File:** `includes/Modules/Settlement/Handlers/class-settlement-handlers.php:124-165`, `231-268`
**Description:** `handle_approve()` and `handle_payment()` both perform sequential loan-clearance and asset-clearance checks, each of which may invoke cross-module queries (LoansModule and AssetsModule). Each check is an independent DB call chain. For `handle_payment()` this means 4+ extra queries per payment action on top of the settlement fetch. While this is acceptable for a low-frequency admin action, the queries in `check_loan_clearance()` and `check_asset_clearance()` are not cached between the two handlers if both approval and payment happen in close succession.

**Fix:** This is acceptable given the low frequency of settlement operations. No immediate action required. Consider adding a `wp_cache_get()` wrapper if clearance checks are added to list views in the future.

---

## Duplication Findings

### SETT-DUP-001
**Severity:** High
**File:** `includes/Modules/Settlement/Services/class-settlement-service.php:240-256` vs. `includes/Modules/Settlement/Admin/Views/class-settlement-form.php:186-205`
**Description:** The gratuity calculation logic is implemented twice — once server-side in `Settlement_Service::calculate_gratuity()` and once client-side in the JavaScript `calculateGratuity()` function. While client-side pre-calculation for UX is acceptable, there is no server-side re-validation of the submitted `gratuity_amount`. The handler (`class-settlement-handlers.php:39`) accepts the client-submitted `gratuity_amount` via `floatval($_POST['gratuity_amount'])` directly without re-running `Settlement_Service::calculate_gratuity()` to verify the posted value matches the formula. A malicious or malfunctioning client could submit an inflated gratuity amount and it would be persisted without validation.

**Fix:** In `handle_create()`, re-calculate gratuity server-side and compare to posted value:
```php
$expected_gratuity = Settlement_Service::calculate_gratuity(
    $data['basic_salary'],
    $data['years_of_service']
);
// Allow small float tolerance
if (abs($data['gratuity_amount'] - $expected_gratuity) > 1.0) {
    $data['gratuity_amount'] = $expected_gratuity; // Use server-calculated value
}
```

---

### SETT-DUP-002
**Severity:** Medium
**File:** `includes/Modules/Settlement/Services/class-settlement-service.php:260-268` vs. `includes/Modules/Settlement/Admin/Views/class-settlement-form.php:216-219`
**Description:** Same duplication pattern as SETT-DUP-001 for leave encashment calculation. `calculate_leave_encashment()` exists server-side but the submitted `leave_encashment` value from the form is accepted without server-side re-validation. The formula is `(basic_salary / 30) * unused_days`.

**Fix:** Re-derive `leave_encashment` server-side in `handle_create()` from the submitted `basic_salary` and `unused_leave_days`, discarding the client-submitted `leave_encashment`:
```php
$data['leave_encashment'] = Settlement_Service::calculate_leave_encashment(
    $data['basic_salary'],
    $data['unused_leave_days']
);
```

---

### SETT-DUP-003
**Severity:** Medium
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-form.php:176-184` vs. Leave module tenure calculation
**Description:** Years-of-service calculation is implemented in JavaScript (`calculateYearsOfService()` using `diffDays / 365.25`) in the settlement form. The Leave module (Phase 06) has its own server-side tenure calculation. The settlement calculation relies on the JS-computed value posted as `years_of_service`. Prior phases found the Leave module uses Jan 1 boundary instead of anniversary — a different but related inconsistency. Here, using `365.25` (Julian year) for service days is technically more accurate than 365 but is inconsistent with whatever the settlement PHP service would compute (it doesn't have a tenure calculator — it accepts the posted value directly). This is the same root problem as SETT-DUP-001: JS-computed financial input accepted without server-side verification.

**Fix:** Add a server-side `calculate_years_of_service(string $hire_date, string $last_working_day): float` method to `Settlement_Service` that computes tenure from dates, and call it in `handle_create()` to override the posted `years_of_service`.

---

### SETT-DUP-004
**Severity:** Low
**File:** `includes/Modules/Settlement/SettlementModule.php:49-86`
**Description:** `SettlementModule.php` contains six `@deprecated` wrapper methods (lines 49-86) that delegate entirely to `Settlement_Service`. These exist for backwards compatibility with any code calling `SettlementModule::calculate_gratuity()` etc. directly. No internal code in the plugin uses these deprecated methods — all internal callers use `Settlement_Service` directly. The wrappers add maintenance surface without value.

**Fix:** Search codebase for callers of `SettlementModule::calculate_gratuity()`, `::get_settlement()`, `::check_loan_clearance()`, `::check_asset_clearance()`, `::get_status_labels()`, `::get_status_colors()` — if none found outside the module itself, schedule removal in next major version.

---

## Logical Findings

### SETT-LOGIC-001
**Severity:** High
**File:** `includes/Modules/Settlement/Services/class-settlement-service.php:240-256`
**Description:** **Saudi Labor Law Article 84-85 — Tenure bracket formula discrepancy.** The code implements:
- First 5 years: `(basic_salary / 30) * 21 * years`
- After 5 years: `(basic_salary / 30) * 21 * 5 + (basic_salary / 30) * 30 * remaining`

Saudi Labor Law Article 84 specifies:
- First 5 years: **half-month salary per year** (= 15 days, not 21 days)
- After 5 years: **full-month salary per year** (= 30 days, not 21 for first 5)

The code uses **21 days** for the first 5 years. This overpays employees by 40% for the first 5 years of service (`21/15 = 1.4x`). The 21-day formula is the UAE Labour Law formula (Article 59), not Saudi. The Saudi formula uses 15 days (half of 30).

**Correct formula:**
```php
$daily_rate = $basic_salary / 30;
if ($years_of_service <= 5) {
    return $daily_rate * 15 * $years_of_service;      // half-month
}
$first_5_years  = $daily_rate * 15 * 5;               // half-month for first 5
$remaining      = $years_of_service - 5;
$after_5_years  = $daily_rate * 30 * $remaining;      // full-month after 5
return $first_5_years + $after_5_years;
```

**Impact:** This is a High financial calculation error that overpays EOS for employees with under 5 years of service. For an employee on SAR 10,000/month with exactly 5 years service: current code pays SAR 35,000, correct Saudi law figure is SAR 25,000 — a SAR 10,000 overpayment.

**Note:** If this plugin targets both Saudi and UAE clients, a `$country` parameter should be added. CLAUDE.md states "Country default SA" and "labor law calculations assume Saudi labor law." The 21-day formula is therefore incorrect for the plugin's stated target.

---

### SETT-LOGIC-002
**Severity:** High
**File:** `includes/Modules/Settlement/Services/class-settlement-service.php:240-256`, `includes/Modules/Settlement/Handlers/class-settlement-handlers.php:34-65`
**Description:** **Missing trigger-type multipliers for resignation.** The `calculate_gratuity()` function takes only `$basic_salary` and `$years_of_service` as parameters — it has no `$trigger_type` (resignation / termination / contract end) parameter. Saudi Labor Law Article 85 specifies different entitlement multipliers depending on how the employment ended:

| Trigger | < 2 years | 2–5 years | 5–10 years | ≥ 10 years |
|---------|-----------|-----------|------------|------------|
| Resignation | 0% EOS | 33% EOS | 66% EOS | 100% EOS |
| Termination by employer | 100% | 100% | 100% | 100% |
| Contract end | 100% | 100% | 100% | 100% |

The current code always pays full gratuity regardless of the reason for separation. A voluntary resignation after 3 years should pay 0 SAR (resignation with < 2 years service) or 33% (2–5 years), but the code pays 100%.

The `sfs_hr_settlements` table schema has no `trigger_type` or `separation_reason` column, and the form has no such field. The resignation record (`sfs_hr_resignations`) may carry a reason type but it is not fetched or used.

**Fix:**
1. Add `trigger_type` column to `sfs_hr_settlements` (values: `resignation`, `termination`, `contract_end`, `mutual`).
2. Add `$trigger_type` parameter to `calculate_gratuity()`.
3. Apply multiplier table inside the function.
4. Populate trigger type from the resignation record when creating settlement from a resignation.

---

### SETT-LOGIC-003
**Severity:** High
**File:** `includes/Modules/Settlement/Services/class-settlement-service.php:121-151`
**Description:** **No double-settlement guard.** `create_settlement()` inserts a new settlement record without checking whether a settlement already exists for the same `employee_id`. The `get_pending_resignations()` query filters out resignations where `s.id IS NULL` (no existing settlement), which prevents duplicate creation via the UI form — but only for resignation-linked settlements. A settlement can be created without a `resignation_id` (the field is nullable), and `handle_create()` accepts `resignation_id = 0`. This means:
1. Two settlements can be created for the same employee if both are created without `resignation_id`.
2. A settlement can be created manually for an employee who already has a resignation-linked settlement.

There is no UNIQUE constraint on `employee_id` in `sfs_hr_settlements`.

**Fix:** Add a uniqueness check in `create_settlement()`:
```php
$existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$table} WHERE employee_id = %d AND status NOT IN ('rejected') LIMIT 1",
    $data['employee_id']
));
if ($existing) {
    return 0; // or throw, depending on error handling pattern
}
```
Consider adding a conditional DB-level constraint: `UNIQUE KEY uq_active_employee (employee_id)` — but this would need a migration.

---

### SETT-LOGIC-004
**Severity:** Medium
**File:** `includes/Modules/Settlement/Services/class-settlement-service.php:240-256`
**Description:** **Salary base missing housing allowance.** The gratuity calculation uses only `$basic_salary` as the base. Saudi Labor Law uses "last wage" as the base for EOS calculation. Per Ministry of HR guidance, "last wage" (الأجر الأخير) typically includes basic salary plus housing allowance. The `sfs_hr_employees` table stores `base_salary` and the form uses `data-base-salary` (which maps to `base_salary`). The `sfs_hr_employees` table has no standalone `housing_allowance` column — housing allowance is typically stored in payroll `components_json`. Therefore, the gratuity base may undercount the correct EOS entitlement by excluding housing allowance.

**Fix:** When creating a settlement, fetch the employee's most recent payroll run to extract housing allowance component and add it to the EOS base. Document this as an accepted limitation if housing allowance data is not reliably available.

---

### SETT-LOGIC-005
**Severity:** Medium
**File:** `includes/Modules/Settlement/Handlers/class-settlement-handlers.php:117-183`
**Description:** **No status guard on approval.** `handle_approve()` calls `Settlement_Service::update_status()` without verifying the settlement's current status is `pending`. A settlement that was already `approved`, `rejected`, or `paid` can be re-approved by calling the handler again. While this requires a valid nonce and `sfs_hr.manage` capability, it could accidentally reset a `paid` settlement back to `approved` with a new `decided_at` timestamp and `approver_note`, corrupting the payment audit trail.

**Fix:** Add a status guard before calling `update_status()`:
```php
if ($settlement['status'] !== 'pending') {
    wp_die(__('Settlement cannot be approved in its current status.', 'sfs-hr'));
}
```

---

### SETT-LOGIC-006
**Severity:** Low
**File:** `includes/Modules/Settlement/Services/class-settlement-service.php:240-256`, `includes/Modules/Settlement/Admin/Views/class-settlement-form.php:176-184`
**Description:** **Partial year proration inconsistency between PHP and JS.** The PHP `calculate_gratuity()` method accepts a raw `$years_of_service` float and uses it directly in the formula (e.g., `$years_of_service = 3.5` computes `daily_rate * 21 * 3.5`). The JavaScript `calculateYearsOfService()` computes `diffDays / 365.25`. The server-side handler does not recompute tenure from hire_date and last_working_day — it trusts the posted `years_of_service` float computed client-side (SETT-DUP-003). These are consistent if the client posts correctly, but the method of proration (365.25 Julian year) is never validated server-side. Saudi labor law specifies proration by actual days, not Julian years. Using 365.25 slightly understates tenure in leap years.

**Fix:** Implement server-side tenure computation in `Settlement_Service` using `DateInterval` for exact day counting, and apply it in `handle_create()`.

---

## Cross-Module Reference Summary

### Settlement vs. Payroll
- **Salary base**: Payroll stores components in `components_json` including housing allowance. Settlement does not read payroll data for the EOS base — it uses `employees.base_salary` only. This means housing allowance is excluded from the EOS base (SETT-LOGIC-004).
- **PAY-DUP-001 from Phase 09**: `l.monthly_installment` column does not exist in loans schema — loan deductions silently zero in payroll. Settlement's `check_loan_clearance()` calls `LoansModule::get_outstanding_balance()` which is a different path and may reflect a different (correct) balance figure.

### Settlement vs. Leave
- **Tenure calculation**: Leave module (LV-CALC-001, Phase 06) evaluates tenure boundary at Jan 1 instead of employee anniversary. Settlement evaluates tenure differently (JS `diffDays / 365.25`). Both are inconsistent with each other and with a correct day-accurate tenure calculation.
- **Leave encashment days**: Settlement accepts `unused_leave_days` as a manual input from HR — it does not query `sfs_hr_leave_balances` to auto-fill the remaining balance. This means unused leave days are manually entered and could be incorrect.

### Settlement vs. EmployeeExit
- Settlement Handlers call `EmployeeExitModule::log_settlement_event()` for audit trail — this is correct cross-module integration, not a duplication concern.
- Phase 19 (planned) notes "logical overlap with Settlement module" — the EmployeeExit module's exit history table (`sfs_hr_exit_history`) serves as the audit trail for settlement events. This integration is intentional and already implemented.

---

## Module Bootstrap Audit (SettlementModule.php)

**No Critical antipatterns found.** Unlike Loans (LOAN-SEC-001/002, Phase 08) and Core (Phase 04), the Settlement module:
- Does **not** have its own table creation in the module file — table creation is handled by `Migrations.php` (correct pattern)
- Does **not** use bare `ALTER TABLE` in `admin_init` — column additions use `add_column_if_missing()` helper (correct)
- Does **not** use unprepared `SHOW TABLES` — table existence checks are inside `Migrations.php`

`SettlementModule.php` is a thin orchestrator (87 lines) that only registers hooks and provides backwards-compatibility wrappers. No SQL is executed in this file.

---

## $wpdb Call Accounting

All `$wpdb` calls in the three audited files:

| File | Line | Method | Prepared? | Notes |
|------|------|--------|-----------|-------|
| class-settlement-service.php | 45 | `get_row()` | Yes (`$wpdb->prepare()`) | get_settlement() |
| class-settlement-service.php | 75 | `get_var()` | Conditional: Yes when status filter, No when all | get_settlements() count — see note |
| class-settlement-service.php | 87 | `get_results()` | Yes (`$wpdb->prepare()`) | get_settlements() rows |
| class-settlement-service.php | 107-115 | `get_results()` | **No** — raw static SQL | get_pending_resignations() — SETT-SEC-001 |
| class-settlement-service.php | 129 | `insert()` | N/A (`$wpdb->insert()` auto-escapes) | create_settlement() |
| class-settlement-service.php | 170 | `get_var()` | Yes (`$wpdb->prepare()`) | update_status() old_status lookup |
| class-settlement-service.php | 177 | `update()` | N/A (`$wpdb->update()` auto-escapes) | update_status() |
| class-settlement-handlers.php | 87 | `update()` | N/A (`$wpdb->update()` auto-escapes) | handle_update() |

**Line 75 note:** When `$status` is `'all'` or not in the allowed list, `get_settlements()` calls `$wpdb->get_var($sql_total)` without `prepare()`. However, `$sql_total` is fully static (no user data) because the `$where` clause remains `'1=1'` with `$params = []` in that branch. This is the same "static query without prepare" pattern flagged in Phase 09 — technically safe but a pattern violation.

**SettlementModule.php:** Zero direct `$wpdb` calls. All DB access is delegated to `Settlement_Service`.

**class-settlement-handlers.php:** Zero direct `$wpdb->prepare()` / `get_*` calls (only `$wpdb->update()` on line 87). All other DB access is via `Settlement_Service` methods.

---

## Handler Capability and Nonce Audit

| Handler | Nonce Check | Capability Check | Status Guard | Notes |
|---------|------------|-----------------|--------------|-------|
| `handle_create()` | `check_admin_referer('sfs_hr_settlement_create')` | `Helpers::require_cap('sfs_hr.manage')` | None needed | Clean |
| `handle_update()` | `check_admin_referer('sfs_hr_settlement_update')` | `Helpers::require_cap('sfs_hr.manage')` | Checks `status === 'pending'` | Clean |
| `handle_approve()` | `check_admin_referer('sfs_hr_settlement_approve')` | `Helpers::require_cap('sfs_hr.manage')` | **Missing** — no pending check | SETT-LOGIC-005 |
| `handle_reject()` | `check_admin_referer('sfs_hr_settlement_reject')` | `Helpers::require_cap('sfs_hr.manage')` | **Missing** — no status fetch | SETT-SEC-002 |
| `handle_payment()` | `check_admin_referer('sfs_hr_settlement_payment')` | `Helpers::require_cap('sfs_hr.manage')` | No `approved` check | SETT-LOGIC-005 pattern |

All handlers use `check_admin_referer()` (which calls `wp_verify_nonce()` internally and dies on failure). All handlers call `Helpers::require_cap('sfs_hr.manage')` as the first statement after nonce check. **No missing nonce or missing capability guards.**

The only gap is that `handle_approve()` and `handle_payment()` do not verify the current status before transitioning (no state machine guard).
