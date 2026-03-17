# Settlement Admin Views Audit Findings
**Phase:** 10 — Settlement Audit
**Plan:** 10-02
**Files audited:**
- `includes/Modules/Settlement/Admin/class-settlement-admin.php` (24 lines — admin page registration/routing)
- `includes/Modules/Settlement/Admin/Views/class-settlement-form.php` (230 lines — create settlement form)
- `includes/Modules/Settlement/Admin/Views/class-settlement-list.php` (376 lines — settlement list table)
- `includes/Modules/Settlement/Admin/Views/class-settlement-view.php` (425 lines — single settlement detail, approval actions)

---

## Summary Table

| Category   | Critical | High | Medium | Low | Total |
|------------|----------|------|--------|-----|-------|
| Security   | 0        | 3    | 1      | 1   | 5     |
| Performance| 0        | 0    | 0      | 1   | 1     |
| Duplication| 0        | 1    | 2      | 1   | 4     |
| Logical    | 1        | 2    | 2      | 1   | 6     |
| **Total**  | **1**    | **6**| **5**  | **4**| **16**|

---

## Security Findings

### SADM-SEC-001
**Severity:** High
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-view.php:261-278`
**Description:** `render_action_buttons()` shows "Approve Settlement", "Reject", and "Mark as Paid" buttons to any user who can view the settlement detail page. There is no `current_user_can()` guard on the button display — all users with access to the lifecycle admin page see these action buttons regardless of their specific capabilities. The server-side handlers (`handle_approve()`, `handle_reject()`, `handle_payment()` in `class-settlement-handlers.php`) do perform capability checks, so direct exploitation is blocked server-side. However, presenting these buttons to under-privileged users creates UI confusion and violates the principle of least privilege (display what you can do). Any user who can load this view — which is controlled only by the parent page's menu registration — sees the full approval interface.

**Fix:** Wrap button rendering in a capability check:
```php
private static function render_action_buttons(array $settlement): void {
    if (!current_user_can('sfs_hr.manage')) {
        return;
    }
    // existing button logic...
}
```

---

### SADM-SEC-002
**Severity:** High
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-view.php:304-318`
**Description:** `render_history()` decodes JSON meta from the audit log and echoes values inside an `echo` statement. The string branch (line 316) correctly assigns `$display_value = esc_html($value)` before use, so the actual output is technically escaped. However, the surrounding `echo` at line 318 constructs HTML by concatenation: `echo '<strong>' . esc_html($label) . ':</strong> ' . $display_value . '<br>';` — here `$display_value` is already escaped but the construction is error-prone. The `number_format` and boolean branches at lines 311-315 write values directly without escaping (e.g., `$display_value = number_format((float)$value, 2)` followed by the same echo). For numeric values, XSS is not currently possible, but for boolean values: `$display_value = $value ? __('Yes', 'sfs-hr') : __('No', 'sfs-hr')` — the translated string is emitted without `esc_html()`.

**Fix:** Apply `esc_html()` uniformly at the echo site regardless of prior escaping at assignment, and use `esc_html_e()` for translated values:
```php
echo '<strong>' . esc_html($label) . ':</strong> ' . esc_html($display_value) . '<br>';
```
For the boolean branch, assign the raw string and let the unified esc_html at echo handle it:
```php
} elseif (is_bool($value)) {
    $display_value = $value ? __('Yes', 'sfs-hr') : __('No', 'sfs-hr');
}
// then at echo:
echo '<strong>' . esc_html($label) . ':</strong> ' . esc_html($display_value) . '<br>';
```

---

### SADM-SEC-003
**Severity:** High
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-list.php:90`
**Description:** The status badge CSS class is constructed directly from `$row['status']` (a DB value): `class="sfs-hr-status-badge status-<?php echo esc_attr($row['status']); ?>"`. While `esc_attr()` is used (correct), the status value is not validated against the known allowlist `['pending', 'approved', 'paid', 'rejected']` before rendering. If an unexpected status value were ever inserted into the DB (e.g., via a future bug or direct DB edit), it would create an unrecognized CSS class — not XSS since `esc_attr()` is applied, but it produces broken badge styling with no visible indicator. More critically, a similar pattern at line 53 uses `__(ucfirst($status), 'sfs-hr')` where `$status` comes from `$_GET['status']` (sanitized via `sanitize_key()`). The `sanitize_key()` function does not validate against known statuses — it merely lowercases and strips special characters. If an unknown status like `cancelled` or `draft` is passed, the tab heading renders with that value, and `get_settlements()` falls back to no WHERE clause (returning all settlements), which may surprise users expecting an empty result.

**Fix:** Add status allowlist validation at the top of `render()`:
```php
$allowed_statuses = ['pending', 'approved', 'paid', 'rejected'];
$status = in_array($status, $allowed_statuses, true) ? $status : 'pending';
```
For the badge, validate before CSS class construction or use a status-to-class map.

---

### SADM-SEC-004
**Severity:** Medium
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-form.php:89`
**Description:** The `unused_leave_days` input has `min="0"` enforced client-side, and `basic_salary` and other financial inputs are `type="number"` — but there is no `min` attribute preventing zero or negative `basic_salary`. The form submits all calculated values (including `gratuity_amount`, `leave_encashment`, `total_settlement`) as hidden fields that are computed entirely in client-side JavaScript. Server-side handler `handle_create()` in `class-settlement-handlers.php` accepts these client-computed values via `floatval($_POST['gratuity_amount'])` etc. without re-running the server-side `calculate_gratuity()` (confirmed in Phase 10-01 as SETT-DUP-001). This means a malicious admin could POST a crafted gratuity amount that doesn't match the calculation. While `sfs_hr.manage` is a high-trust role, financial data manipulation should still require server-side validation.

**Fix:** Add `min="0.01"` to `basic_salary` input for client-side guard. More importantly, in `handle_create()`, re-calculate `gratuity_amount` server-side using `Settlement_Service::calculate_gratuity()` and compare with the submitted value, rejecting submissions where the variance exceeds an acceptable threshold (e.g., ±0.01 SAR due to rounding).

---

### SADM-SEC-005
**Severity:** Low
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-form.php:109`
**Description:** The `deduction_notes` textarea has no `maxlength` attribute and no server-side length validation. An admin could submit an arbitrarily long notes string which gets stored in the DB. While this is not an XSS vector (output is `esc_html()`-escaped), and the column likely has a TEXT type (unlimited), it could allow storage of multi-megabyte strings.

**Fix:** Add `maxlength="1000"` to the textarea and validate `strlen(sanitize_textarea_field($_POST['deduction_notes'])) <= 1000` in `handle_create()`.

---

## Performance Findings

### SADM-PERF-001
**Severity:** Low
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-view.php:26-27`
**Description:** The detail view fires two clearance check calls (`check_loan_clearance()` and `check_asset_clearance()`) on every page load. These are cross-module calls that each may trigger multiple DB queries to LoansModule and AssetsModule respectively. They are not cached between calls. This was noted at the service level in Phase 10-01 (SETT-PERF-002); the view layer is its immediate caller and each page view generates this overhead. For a single-record detail view, this is Low priority — not a hot path — but each view load runs 4+ extra queries beyond the settlement fetch.

**Fix:** Add a short-lived transient cache keyed on `employee_id` for loan/asset clearance results, invalidated on loan status change or asset return events. Or accept this as a low-frequency admin action where 4 extra queries is acceptable.

---

## Duplication Findings

### SADM-DUP-001
**Severity:** High
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-form.php:186-206` (JS) vs `includes/Modules/Settlement/Services/class-settlement-service.php:240-256` (PHP)
**Description:** The gratuity calculation logic is duplicated between the client-side JavaScript (`calculateGratuity()` in `class-settlement-form.php`) and the server-side PHP (`Settlement_Service::calculate_gratuity()`). Both implement the same formula (21 days/year for first 5 years, 30 days/year thereafter). The client-side JS also uses `baseSalary / 30` as the daily rate, matching the PHP service. If the formula needs to be corrected (e.g., to fix SETT-LOGIC-001 from Phase 10-01 — the Saudi vs UAE 15-day first-5-years correction), both implementations must be updated in sync. There is no mechanism to ensure they stay in agreement.

**Fix:** Add a REST endpoint (or use the existing one if available) that accepts `basic_salary` + `years_of_service` and returns the server-calculated gratuity. Have the JS `calculateGratuity()` call this endpoint instead of computing locally. This ensures a single source of truth and automatically picks up formula corrections.

---

### SADM-DUP-002
**Severity:** Medium
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-list.php:90` and `includes/Modules/Settlement/Admin/Views/class-settlement-view.php:234`
**Description:** Status badge rendering is done in two different ways across the admin views. In `class-settlement-list.php:90`, the badge is rendered with a CSS class approach: `<span class="sfs-hr-status-badge status-{$status}">`. In `class-settlement-view.php:139,143,147,234`, the badge is rendered via `Settlement_Service::status_badge()` which uses inline `style` attributes (`background:#color`). These two patterns produce visually inconsistent badges for the same status values. The `status_badge()` method uses hex color values from `get_status_colors()`, while the CSS class approach uses CSS rules defined locally in `class-settlement-list.php`.

**Fix:** Standardize on one approach. The CSS class approach is more maintainable (styles defined once in CSS, not scattered through HTML). Update `Settlement_Service::status_badge()` to emit CSS classes matching the list view pattern, or update the list view to use `Settlement_Service::status_badge()`.

---

### SADM-DUP-003
**Severity:** Medium
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-list.php:35-46` vs status badge in list and view
**Description:** The status tab navigation in `class-settlement-list.php` hardcodes four statuses (`pending`, `approved`, `paid`, `rejected`) matching only what the badge CSS covers. However, `Settlement_Service::get_status_labels()` already defines the canonical status set. The list view does not use `get_status_labels()` for its tab construction — it has the status values repeated inline. If a new status (e.g., `cancelled`, `draft`) is added to `get_status_labels()`, the list view tabs won't update automatically.

**Fix:** Drive the tab list from `Settlement_Service::get_status_labels()`:
```php
foreach (Settlement_Service::get_status_labels() as $slug => $label) {
    $active = $status === $slug ? 'current' : '';
    echo '<li><a href="' . esc_url(...) . '" class="' . $active . '">' . esc_html($label) . '</a></li>';
}
```

---

### SADM-DUP-004
**Severity:** Low
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-admin.php:1-24`
**Description:** `Settlement_Admin` is a deprecated class whose `hooks()` method is a documented no-op. It is imported by `SettlementModule.php` via `new Settlement_Admin()` and `$admin->hooks()` calls (confirmed in Phase 10-01). This means a class instantiation and method call happen on every admin page load for zero effect. The file exists only for backwards compatibility.

**Fix:** Remove the instantiation of `Settlement_Admin` from `SettlementModule.php` and delete `class-settlement-admin.php` (or retain the file with only the class stub and a PHP 8 `#[\Deprecated]` attribute if the class is referenced externally). No functional change required for this audit cycle (audit-only), but schedule for Phase 10 gap closure.

---

## Logical Findings

### SADM-LOGIC-001
**Severity:** Critical
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-form.php:191-206`
**Description:** The client-side `calculateGratuity()` JavaScript confirms the same wrong gratuity formula identified at the service level in Phase 10-01 (SETT-LOGIC-001). The comment on line 190-192 reads "Gratuity calculation per Saudi Labor Law: 21 days salary for each year of service (for first 5 years)" — but Saudi Labor Law Article 84 specifies **15 days** salary per year for the first 5 years of service (for resignations), not 21 days. The 21-day figure is from UAE Labor Law. This client-side formula drives what the admin sees and what is submitted as `gratuity_amount`. Because the server-side does not re-validate the submitted value (SETT-DUP-001 / SADM-SEC-004), the client-computed wrong value is stored directly. This is a Critical financial error: sub-5-year employees are overpaid by 40% (21/15 = 1.4x). This finding is a confirmation and extension of Phase 10-01's SETT-LOGIC-001 — it affects the admin form UI, not just the PHP service.

**Fix:** Correct the JavaScript formula to use 15 days for the first 5 years:
```js
if (yearsOfService <= 5) {
    gratuity = (baseSalary / 30) * 15 * yearsOfService;
} else {
    const first5Years = (baseSalary / 30) * 15 * 5;
    const remainingYears = yearsOfService - 5;
    const afterYears = (baseSalary / 30) * 30 * remainingYears;
    gratuity = first5Years + afterYears;
}
```
Also correct `Settlement_Service::calculate_gratuity()` (PHP) to match. Add a `trigger_type` parameter for resignation multipliers (0%/33%/66%/100% of EOS based on years served, per Saudi law) as identified in Phase 10-01 SETT-LOGIC-002.

---

### SADM-LOGIC-002
**Severity:** High
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-form.php:176-184`
**Description:** `calculateYearsOfService()` uses `Math.abs(lwd - hire) / (1000 * 60 * 60 * 24 * 365.25)` — a floating-point day count divided by 365.25. This fractional year value is then used directly in the gratuity formula (`calculateGratuity()`) without rounding or truncation to whole years. Saudi Labor Law Article 84 calculates EOS based on **complete years of service** — partial years should be prorated only for the partial year at the end, using days. The current implementation passes a float (e.g., `3.74 years`) directly into the gratuity multiplication, which is not the labor-law-correct approach. The PHP service has the same issue (Phase 10-01 SETT-LOGIC-003 noted the absence of partial-year handling).

**Fix:** The correct approach is: compute full years + remaining days separately. For the partial year, calculate daily rate prorated for the remaining days:
```js
const fullYears = Math.floor(yearsOfService);
const remainingDays = diffDays - (fullYears * 365);  // approximate
// Then pass fullYears + remainingDays/365 as the "service period" to gratuity calculation
// and handle partial year as (dailyRate * 15 * remainingDays / 365) for first 5 years
```

---

### SADM-LOGIC-003
**Severity:** High
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-view.php:262-278`
**Description:** `render_action_buttons()` only handles two settlement statuses: `pending` (shows Approve/Reject) and `approved` (shows Mark as Paid). The `rejected` and `paid` statuses show no buttons, which is correct behavior. However, if a settlement reaches an unknown state (e.g., `cancelled` or `draft` if ever introduced), no buttons are shown either — this is silent. More importantly, there is no `cancelled` state in the status taxonomy but the list view tabs include `rejected` — and a rejected settlement has no UI action to re-open or re-process it. The settlement workflow assumes a linear: pending → approved → paid, with rejection as a dead end. There is no cancel action and no re-open action.

**Fix:** Document the workflow as intentionally one-way in code comments. If re-opening rejected settlements is a business requirement, add a "Re-open" button for `rejected` status. If not, add a comment:
```php
// Status flow: pending → approved → paid (final) or pending → rejected (final)
// Cancelled state not implemented — no action buttons shown for terminal statuses.
```

---

### SADM-LOGIC-004
**Severity:** Medium
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-form.php:217-225`
**Description:** The `calculateSettlement()` JavaScript function allows negative `total_settlement` values if deductions exceed gratuity + encashment + final salary + allowances. The `total_settlement` hidden field would be submitted as a negative number (e.g., `-500.00`). There is no client-side guard (`Math.max(0, total)` not applied) and no server-side validation in `handle_create()` to reject negative total settlements. A negative settlement total stored in the DB would be financially incorrect and misleading.

**Fix:** Add a minimum-zero guard:
```js
const total = Math.max(0, gratuity + leaveEncashment + finalSalary + otherAllowances - deductions);
```
And validate server-side in `handle_create()`:
```php
if (floatval($_POST['total_settlement']) < 0) {
    wp_die(__('Total settlement cannot be negative.', 'sfs-hr'));
}
```

---

### SADM-LOGIC-005
**Severity:** Medium
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-list.php:53`
**Description:** The list heading uses `__(ucfirst($status), 'sfs-hr')` where `$status` is the raw `sanitize_key()` value from `$_GET['status']`. If an unrecognized status is passed (e.g., `?status=foobar`), the heading reads "Foobar Settlements" and the table shows ALL settlements (because `get_settlements()` skips the WHERE clause for unknown statuses — see `Settlement_Service::get_settlements():68`). This is a logical inconsistency: the tab heading implies a filtered view, but the table contents are unfiltered.

**Fix:** Apply the allowlist validation (SADM-SEC-003 fix) so unknown status values redirect to or default to `pending`. With that fix, this issue is also resolved.

---

### SADM-LOGIC-006
**Severity:** Low
**File:** `includes/Modules/Settlement/Admin/Views/class-settlement-view.php:162-165`
**Description:** In `render_loan_status()`, the loan outstanding balance is displayed via `sprintf()` where the `%s` placeholder is replaced by `'<strong>' . number_format($loan_status['outstanding'], 2) . '</strong>'`. The format string is correctly wrapped in `esc_html__()`. However, `number_format()` output is injected as raw HTML inside `<strong>` tags — the `number_format()` result is always a numeric string so XSS is not possible, but this pattern bypasses the intent of `esc_html__()` (the HTML `<strong>` tags in the substitution mean the final string contains HTML that the translation string did not account for). The same pattern occurs at line 195-198 for `render_asset_status()`.

**Fix:** Use `wp_kses()` or split the message into static and dynamic parts:
```php
echo esc_html(sprintf(
    __('This employee has an outstanding loan balance of %s SAR.', 'sfs-hr'),
    number_format($loan_status['outstanding'], 2)
));
echo ' <strong>' . esc_html(number_format($loan_status['outstanding'], 2)) . '</strong>';
```
Or keep the current approach with a code comment acknowledging that `number_format()` is safe here:
```php
// number_format() is safe — output is always a numeric string
```

---

## $wpdb Call Accounting Table

This table covers all four audited admin files. The admin view classes contain **zero direct $wpdb calls** — all DB access is delegated to `Settlement_Service` and `EmployeeExitModule::get_settlement_history()`. The Settlement_Service queries are audited in Phase 10-01; the following summarizes the indirect call chain invoked by these views:

| # | File | Line (caller) | Called Method | DB Method | Prepared | Notes |
|---|------|---------------|---------------|-----------|----------|-------|
| 1 | class-settlement-form.php | 19 | `Settlement_Service::get_pending_resignations()` | `get_results()` | No (raw static) | Flagged as SETT-SEC-001 in Phase 10-01 |
| 2 | class-settlement-list.php | 22 | `Settlement_Service::get_settlements()` count | `get_var()` | Yes (conditional) | Safe — prepared when status param present |
| 3 | class-settlement-list.php | 22 | `Settlement_Service::get_settlements()` rows | `get_results()` | Yes | Safe — LIMIT/OFFSET pagination correct |
| 4 | class-settlement-view.php | 20 | `Settlement_Service::get_settlement()` | `get_row()` | Yes | Safe |
| 5 | class-settlement-view.php | 26 | `Settlement_Service::check_loan_clearance()` | (cross-module) | N/A | Delegates to LoansModule |
| 6 | class-settlement-view.php | 27 | `Settlement_Service::check_asset_clearance()` | (cross-module) | N/A | Delegates to AssetsModule |
| 7 | class-settlement-view.php | 284 | `EmployeeExitModule::get_settlement_history()` | (external) | N/A | Not audited in this phase |

**Direct $wpdb calls in admin view files: 0**
**Indirect $wpdb calls via service layer: 7 call-sites (6 unique queries)**
**Raw unprepared queries reachable from admin views: 1** (via `get_pending_resignations()` — static SQL, flagged in 10-01)

---

## Cross-Reference with Phase 10-01

The following findings from Phase 10-01 (Settlement Services audit) are **confirmed to manifest in the admin views**:

| Phase 10-01 Finding | Admin View Manifestation | Admin Finding |
|--------------------|--------------------------|---------------|
| SETT-LOGIC-001: 21-day formula wrong | JS `calculateGratuity()` uses same 21-day formula | SADM-LOGIC-001 (Critical) |
| SETT-DUP-001: client-computed gratuity not re-validated | Confirmed — form submits JS-computed value as hidden field | SADM-SEC-004 (Medium) |
| SETT-PERF-001: unbounded get_pending_resignations() | Called in form render on every page load | SADM-PERF-001 (Low) |
| SETT-SEC-002: handle_reject() missing status guard | Reject button visible to all view-access users | SADM-SEC-001 (High) |

---

## Files with No Findings

**`class-settlement-admin.php`:** This file is fully deprecated — `hooks()` is a no-op, no $wpdb calls, no output rendering. The only issue is the unnecessary class instantiation overhead (SADM-DUP-004 Low). No security or performance findings.
