# Leave Services / Balance Calculation Audit Findings

**Audit date:** 2026-03-16
**Scope:** LeaveCalculationService.php (243 lines), class-leave-ui.php (105 lines), LeaveModule.php balance/type methods (~7390 lines) — focus on calculation correctness and security
**Auditor:** Claude (automated code review)
**Requirement:** CRIT-02

---

## Executive Summary

- **Critical:** 3 findings
- **High:** 7 findings
- **Medium:** 6 findings
- **Low:** 3 findings
- **Total:** 19 findings

### Top Issues

1. **LV-CALC-001 (Critical):** Tenure years computed against Jan 1 of year — mid-year hire at 5-year boundary never gets the higher entitlement until the following year
2. **LV-CALC-002 (Critical):** Balance reset on every approval overwrites opening/carried_over with 0 — previously accrued carryover is destroyed on each leave approval
3. **LV-PERF-001 (Critical/Performance):** `broadcast_holiday_added()` queries all active employee emails with no `LIMIT` and calls `Helpers::send_mail()` in an unbounded loop — potential timeout and OOM on large tenants
4. **LV-CALC-003 (High):** `available_days()` fallback logic silently substitutes annual_quota when `accrued=0`, masking a zero-accrued balance record as "full quota available"
5. **LV-CALC-004 (High):** `handle_self_request()` counts leave days as calendar days (not business days), while `shortcode_request()` uses `business_days()` — inconsistent day calculation

---

## Calculation Findings

### Critical

#### LV-CALC-001: Tenure Boundary Evaluated at Jan 1 — Mid-Year 5-Year Anniversary Always Misses Higher Quota
- **File:** `includes/Modules/Leave/Services/LeaveCalculationService.php:L43-L53`
- **Description:** `compute_quota_for_year()` computes tenure as of `YYYY-01-01` of the requested year; an employee hired on 2021-06-15 reaching 5 years on 2026-06-15 is still given the <5 year entitlement for all of 2026 (their anniversary hasn't reached Jan 1, 2027).
- **Evidence:**
  ```php
  $as_of = strtotime($year . '-01-01');
  $hd = strtotime($hire_date);
  $years = (int)floor(($as_of - $hd) / (365.2425 * DAY_IN_SECONDS));
  return ($years >= 5) ? $ge5 : $lt5;
  ```
- **Fix:** Calculate tenure as of the employee's actual work anniversary within the year, or pro-rate: use the higher quota starting from the employee's anniversary month within the leave year. At minimum, document this as a known limitation — Saudi labor law (Article 109) grants the higher entitlement from the day the 5-year mark is reached, not from the start of the year.

---

#### LV-CALC-002: Balance Opening and Carried_Over Overwritten to 0 on Every Leave Approval
- **File:** `includes/Modules/Leave/LeaveModule.php:L1630-L1672` (also L5612-L5638, L1632-L1633)
- **Description:** Every call to `handle_approve()` and `handle_early_return()` hardcodes `$opening = 0; $carried = 0;` and upserts the balance record. If HR had previously set a non-zero opening balance (via `handle_update_balance()`) or a carryover, those values are silently destroyed the moment any leave for that type/year is approved.
- **Evidence:**
  ```php
  $opening = 0;
  $carried = 0;
  $accrued = $quota;
  $closing = $opening + $accrued + $carried - $used;
  // ...
  $wpdb->update($bal_t, ['opening'=>$opening,'accrued'=>$accrued,...], ['id'=>$bal_id]);
  ```
- **Fix:** Before updating the balance, read the existing row and preserve `opening` and `carried_over` unless those fields are explicitly being reset. Only overwrite `used` and `closing`. Pattern: `$opening = (int)($existing['opening'] ?? 0)` and keep `$carried = (int)($existing['carried_over'] ?? 0)`.

---

### High

#### LV-CALC-003: available_days() Fallback Silently Substitutes Annual Quota When accrued=0
- **File:** `includes/Modules/Leave/Services/LeaveCalculationService.php:L74-L77`
- **Description:** If a balance row exists with `accrued=0` (intentionally zeroed out by HR), the code ignores that and substitutes the full `annual_quota`. An employee whose accrued days were deliberately set to zero still sees a full entitlement.
- **Evidence:**
  ```php
  $accrued = isset($row['accrued']) ? (int)$row['accrued'] : (int)$annual_quota;
  if ((int)($row['accrued'] ?? 0) === 0) $accrued = (int)$annual_quota;
  ```
- **Fix:** Remove the second `if` override. Use accrued value from the row when a row exists: `$accrued = isset($row) ? (int)($row['accrued'] ?? 0) : (int)$annual_quota;` — only fall back to `$annual_quota` when no balance row exists at all.

---

#### LV-CALC-004: handle_self_request() Uses Calendar Days, Not Business Days
- **File:** `includes/Modules/Leave/LeaveModule.php:L6019-L6022`
- **Description:** `handle_self_request()` computes days as inclusive calendar days. `shortcode_request()` and `handle_update_leave_dates()` both call `LeaveCalculationService::business_days()`. This inconsistency means self-service requests include Fridays and holidays in the day count, inflating the deduction from the balance.
- **Evidence:**
  ```php
  $days = (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;
  if ( $days < 1 ) {
      $days = 1;
  }
  ```
- **Fix:** Replace with `$days = LeaveCalculationService::business_days($start, $end);` — the same approach used in `shortcode_request()` at L4484.

---

#### LV-CALC-005: Hajj Tenure Check Uses 365 Days Per Year, Not 365.2425
- **File:** `includes/Modules/Leave/LeaveModule.php:L4572`
- **Description:** Hajj eligibility (2 years of service) checks `(strtotime($start) - strtotime($hire)) < (2 * 365 * DAY_IN_SECONDS)`. Using 365 exact days means employees hired on a leap year are incorrectly considered ineligible for ~1 extra day, while the tenure service itself uses 365.2425.
- **Evidence:**
  ```php
  if (!$hire || (strtotime($start) - strtotime($hire)) < (2 * 365 * DAY_IN_SECONDS)) {
  ```
- **Fix:** Use `(2 * 365.2425 * DAY_IN_SECONDS)` to match the tenure calculation in `LeaveCalculationService::compute_quota_for_year()`.

---

#### LV-CALC-006: Hajj "Once in a Lifetime" Check Does Not Block After hajj_used_at Is Set via Approval Path
- **File:** `includes/Modules/Leave/LeaveModule.php:L4556-L4577`
- **Description:** The once-only Hajj guard checks `$emp['hajj_used_at']` in the frontend submission but nothing in `handle_approve()` sets `hajj_used_at`. So the guard works on submission (if the field happens to be set externally) but is never automatically set upon approval — meaning the employee could submit again after cancellation/rejection and the field is still null.
- **Evidence:**
  ```php
  if ($dup > 0 || !empty($emp['hajj_used_at'])) { // blocks submission
  // handle_approve() never sets hajj_used_at after approving a HAJJ leave
  ```
- **Fix:** In `handle_approve()`, after marking the request approved, check if `$type['special_code'] === 'HAJJ'` and set `hajj_used_at = $row['start_date']` on the employee row.

---

#### LV-CALC-007: recalc_balance_for_request() Silently No-ops If No Balance Row Exists
- **File:** `includes/Modules/Leave/LeaveModule.php:L2590-L2621`
- **Description:** `recalc_balance_for_request()` (called on hold/date-update) only updates if `$bal` row exists; if it doesn't exist it does nothing. After hold + re-activate, the balance might be in an undefined state.
- **Evidence:**
  ```php
  if ( $bal ) {
      // update ...
  }
  // else: silent no-op, no insert
  ```
- **Fix:** Add an `else` branch that inserts a balance row with the freshly computed values — the same upsert pattern used in `handle_approve()`.

---

### Medium

#### LV-CALC-008: Partial Year Pro-ration for New Hires Is Not Implemented
- **File:** `includes/Modules/Leave/Services/LeaveCalculationService.php:L38-L54`
- **Description:** An employee hired on June 15 of the current year receives the full annual quota (21 or 30 days) for that year without pro-ration. Saudi labor law (Article 109) entitles employees to leave proportional to the period served in the first year.
- **Evidence:**
  ```php
  return ($years >= 5) ? $ge5 : $lt5; // returns full quota regardless of partial first year
  ```
- **Fix:** Detect if `$year === (int)gmdate('Y', $hd)` (first year of employment) and pro-rate: `$quota = (int)round($base_quota * (12 - date('n', $hd) + 1) / 12)`. Document the chosen rounding rule.

---

#### LV-CALC-009: Terminated Employee Balances Continue Accruing
- **File:** `includes/Modules/Leave/Services/LeaveCalculationService.php:L59-L81`
- **Description:** `available_days()` and `compute_quota_for_year()` have no check for employee status. A terminated employee whose record still has a balance row will be shown available days and can theoretically submit a request (if login blocking hasn't kicked in yet, e.g. before `last_working_day`).
- **Evidence:** No `status` check in `LeaveCalculationService.php` methods.
- **Fix:** In `compute_quota_for_year()`, accept or look up `employee_status` and return `0` if `terminated`. Alternatively, enforce at the request submission layer before querying quota.

---

#### LV-CALC-010: No Negative Balance Protection in `handle_approve()` Calculation
- **File:** `includes/Modules/Leave/LeaveModule.php:L1632-L1633`
- **Description:** `closing = opening + accrued + carried - used` can produce a negative value in `handle_approve()` (unlike `available_days()` which clamps to `max($available, 0)`). The balance record can store a negative `closing`, which misleads the UI display in the widget.
- **Evidence:**
  ```php
  $closing = $opening + $accrued + $carried - $used; // no max(0, ...) guard
  ```
- **Fix:** Apply `$closing = max(0, $closing)` before storing, unless negative balances are intentional for `allow_negative` leave types. In that case, preserve the sign only when `$type['allow_negative'] === 1`.

---

## Security Findings

### Critical

#### LV-SEC-001: render_request_form() Queries Leave Types Without Escaping Table Name in Unparameterized Query
- **File:** `includes/Modules/Leave/LeaveModule.php:L4689-L4695`
- **Description:** The query in `render_request_form()` uses a bare string query (no `$wpdb->prepare()`) which is technically safe because the table name is derived from `$wpdb->prefix` (trusted), but the pattern is inconsistent with project standards and could be accidentally copied with interpolated variables.
- **Evidence:**
  ```php
  $types = $wpdb->get_results(
      "SELECT id, name, special_code, gender_required
       FROM {$wpdb->prefix}sfs_hr_leave_types
       WHERE active=1
       ORDER BY name ASC",
  ```
- **Fix:** This particular query has no user input so it is not an injection risk, but apply `$wpdb->prepare()` for consistency: wrap the static SQL in prepare with no substitutions or use `$wpdb->get_results($sql)` only for fully static queries and document that clearly.

---

### High

#### LV-SEC-002: render_requests() Uses Inline SQL String With Dynamic $where — Missing prepare() Call on Count Query When $params Is Empty
- **File:** `includes/Modules/Leave/LeaveModule.php:L257-L267`
- **Description:** The count and data queries build `$where` dynamically but fall through to `$wpdb->get_var($sql_count)` (no `prepare()`) when `$params` is empty. While `$where` is currently built from allowlisted and sanitized values, the pattern is fragile — a future change adding a non-allowlisted variable to `$where` would silently create an injection path.
- **Evidence:**
  ```php
  $counts['all'] = $count_params
      ? (int) $wpdb->get_var($wpdb->prepare($sql_count, ...$count_params))
      : (int) $wpdb->get_var($sql_count);
  ```
- **Fix:** Refactor to always use `$wpdb->prepare()` even for queries with no variable substitutions: `$wpdb->prepare($sql_count)` (no args) still sanitizes the SQL string through the prepare pipeline. This eliminates the "is params empty?" branching.

---

#### LV-SEC-003: `$_GET['err']` Output in render_requests() Accepts Any URL Parameter Without Validation
- **File:** `includes/Modules/Leave/LeaveModule.php:L322-L326`
- **Description:** The `err` query parameter is output using `esc_html(sanitize_text_field(wp_unslash($_GET['err'])))` which escapes HTML but the message is user-controlled. An attacker who can trick an admin into clicking a crafted URL can display an arbitrary text message in the admin panel (content injection).
- **Evidence:**
  ```php
  echo esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) );
  ```
- **Fix:** The escaping is correct and prevents XSS. The remaining risk is low-severity content injection. For consistency and defense in depth, consider restricting `err` to an allowlist of known error codes, similar to how `leave_err` is handled in `render_self_request_form()` at L5769.

---

#### LV-SEC-004: is_hr_user() Grants HR Access to All `administrator` Role Users
- **File:** `includes/Modules/Leave/LeaveModule.php:L2575-L2585`
- **Description:** `is_hr_user()` returns `true` for any user who passes `user_can($user_id, 'administrator')`, which means any WordPress administrator can hold/update leave dates as HR — even if they are not assigned as HR approvers. This bypasses the intended position-based HR gating.
- **Evidence:**
  ```php
  return user_can( $user_id, 'sfs_hr.leave.manage' ) || user_can( $user_id, 'administrator' );
  ```
- **Fix:** Remove the `administrator` bypass from `is_hr_user()`. If backward compatibility requires admins to access HR functions, grant them `sfs_hr.leave.manage` explicitly at role setup time rather than special-casing `administrator`.

---

### Medium

#### LV-SEC-005: handle_delete_type() Deletes Leave Type Without Checking Pending Balance Records
- **File:** `includes/Modules/Leave/LeaveModule.php:L3588-L3599`
- **Description:** `handle_delete_type()` checks if any leave requests reference the type but does not check `sfs_hr_leave_balances`. After deletion, orphaned balance rows for that type_id remain, which may cause incorrect total balance calculations in aggregate queries.
- **Evidence:**
  ```php
  $in_use = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $req WHERE type_id=%d", $id));
  if ($in_use>0) { /* block */ }
  $wpdb->delete($types, ['id'=>$id]); // orphans balance rows
  ```
- **Fix:** Also check `sfs_hr_leave_balances` for existing records. Either block deletion if balances exist, or `DELETE` orphaned balance rows in the same transaction.

---

## Performance Findings

### Critical

#### LV-PERF-001: broadcast_holiday_added() Is an Unbounded Bulk Mailer With No Rate Limit
- **File:** `includes/Modules/Leave/LeaveModule.php:L5149-L5188`
- **Description:** `broadcast_holiday_added()` queries all active employees with `SELECT email FROM $emp_t WHERE status='active'` (no `LIMIT`) and calls `Helpers::send_mail()` for each in a synchronous loop. A company with 500 employees triggers 500 synchronous SMTP calls in the admin request. This will time out on most shared hosts and can crash the process.
- **Evidence:**
  ```php
  $emails = $wpdb->get_col("SELECT email FROM $emp_t WHERE status='active' AND email<>''");
  // ...
  foreach ($emails as $to) {
      Helpers::send_mail($to, $subject, $body);
  }
  ```
- **Fix:** Dispatch holiday notifications via a background cron job (e.g., store the notification payload in an option and process it in batches of 50 per `wp_schedule_single_event` call). The existing `cron_daily()` pattern is a good model.

---

### High

#### LV-PERF-002: render_requests() Issues N+1 Queries in the Approval Status Loop
- **File:** `includes/Modules/Leave/LeaveModule.php:L392-L411`
- **Description:** For each row where `$r['status'] === 'pending'`, a single `$wpdb->get_var()` query fires to look up the department manager's user ID. With 20 rows per page and multiple pending requests, this adds up to 20 extra queries per page load in the worst case.
- **Evidence:**
  ```php
  $mgr_uid_check = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT manager_user_id FROM {$wpdb->prefix}sfs_hr_departments WHERE id=%d AND active=1",
      (int)$r['dept_id']
  ));
  ```
- **Fix:** Pre-fetch all department manager_user_ids for the departments present in `$rows` in a single `SELECT id, manager_user_id FROM $dept_t WHERE id IN (...)` query before the loop.

---

#### LV-PERF-003: render_requests() Issues N+1 get_user_by() Calls for Approver Names Inside JS Data Array
- **File:** `includes/Modules/Leave/LeaveModule.php:L531-L536`
- **Description:** Inside the `array_map` that builds `sfsHrLeaveData`, for each approved/rejected row, `get_user_by('id', ...)` is called individually. With a page of 20 rows this is up to 20 extra user lookups.
- **Evidence:**
  ```php
  $approver_user = get_user_by('id', (int)$r['approver_id']);
  if ($approver_user) {
      $approver_name = $approver_user->display_name;
  }
  ```
- **Fix:** Collect all `approver_id` values from `$rows`, call `get_users(['include' => $approver_ids])` once, and build a lookup map before the `array_map`.

---

### Medium

#### LV-PERF-004: render_calendar() Issues a Separate get_var() Per Holiday Date in Date-Expansion Loop
- **File:** `includes/Modules/Leave/LeaveModule.php:L6347-L6351`
- **Description:** `render_calendar()` builds holiday map in PHP by expanding yearly repeat holidays via a nested loop over `$cal_years` and every individual day. This is all in-memory and technically fine, but the query at L6348 `"SELECT COUNT(*) FROM {$emp_table} WHERE status='active'"` uses `$wpdb->prepare()` with the `%d` sprintf but the `department_id` column doesn't exist — the correct column is `dept_id`.
- **Evidence:**
  ```php
  $total_employees = (int) $wpdb->get_var(
      "SELECT COUNT(*) FROM {$emp_table} WHERE status = 'active'"
      . ( $filter_dept > 0 ? $wpdb->prepare( " AND department_id = %d", $filter_dept ) : '' )
  );
  ```
- **Fix:** Change `department_id` to `dept_id` to match the actual column name. Also wrap the base query in `$wpdb->prepare()` for consistency.

---

## Duplication Findings

### Medium

#### LV-DUP-001: Balance Recalculation Logic Duplicated in handle_approve(), handle_early_return(), and recalc_balance_for_request()
- **File:** `LeaveModule.php:L1616-L1672`, `L5589-L5638`, `L2590-L2621`
- **Description:** Three separate code blocks compute `$used`, `$opening`, `$accrued`, `$carried`, `$closing` and upsert the balance table. Each has slightly different assumptions (early return hardcodes `opening=0`, recalc_balance_for_request reads existing opening, handle_approve hardcodes opening=0). The divergence causes LV-CALC-002.
- **Evidence:** Three near-identical upsert blocks across the file.
- **Fix:** Extract a single `upsert_balance(int $employee_id, int $type_id, int $year, ?int $quota): void` method that reads the existing row for opening/carried_over, recomputes used from the request table, and upserts. Call it from all three locations.

---

#### LV-DUP-002: HR User Check Logic Duplicated in 8+ Methods
- **File:** `LeaveModule.php` — `handle_approve()`, `handle_reject()`, `handle_cancel()`, `handle_cancel_approved()`, `handle_cancellation_approve()`, `handle_cancellation_reject()`, `handle_early_return()`, `is_hr_user()`
- **Description:** The `$is_hr` check pattern — `get_option('sfs_hr_leave_hr_approvers') + current_user_can('sfs_hr.leave.manage')` — is repeated with subtle variations (some also add `current_user_can('administrator')`, some don't). This inconsistency is the source of LV-SEC-004.
- **Evidence:** 8 occurrences of nearly identical is-HR check code across the file.
- **Fix:** Canonicalize to `$this->is_hr_user($current_uid)` everywhere and ensure `is_hr_user()` itself is correct (see LV-SEC-004).

---

### Low

#### LV-DUP-003: gm_user_id Resolved via Loans Fallback in 5+ Locations
- **File:** `LeaveModule.php:L1120-L1126`, `L2814-L2819`, `L3700-L3705`, `L6770-L6776`, and `render_requests()` at L219-L223`
- **Description:** The "get GM user, fall back to Loans module setting" pattern is copy-pasted across at least 5 methods. Any change to the fallback logic must be updated in 5 places.
- **Evidence:** Repeated `$gm_user_id = (int) get_option('sfs_hr_leave_gm_approver', 0); if (!$gm_user_id) { $loan_settings = LoansModule::get_settings(); ... }` blocks.
- **Fix:** Extract a `private function get_gm_user_id(): int` method and call it from all 5 locations.

---

#### LV-DUP-004: validate_dates(), business_days(), compute_quota_for_year(), available_days(), has_overlap(), manager_dept_ids_for_user() Are Both in Service and Module
- **File:** `LeaveModule.php:L4779-L4804`
- **Description:** `LeaveModule` wraps each `LeaveCalculationService` method with a private proxy method. This is low-harm (delegation pattern) but adds dead weight. If `LeaveCalculationService` is ever moved, these proxies must also be updated.
- **Evidence:** 6 private wrapper methods that simply delegate to `LeaveCalculationService::`.
- **Fix:** Call `LeaveCalculationService::*()` directly at call sites, eliminating the proxy methods. Or keep them if the indirection is intentional for testability.

---

#### LV-DUP-005: Sick Leave Day Count Limits Differ Between Admin and Frontend Submission Paths
- **File:** `LeaveModule.php:L4580-L4597` (shortcode_request) vs. no corresponding check in `handle_self_request()`
- **Description:** `shortcode_request()` enforces `SICK_SHORT ≤ 29 days` and `SICK_LONG ≥ 30 days`, but `handle_self_request()` has no such enforcement. An employee using the self-service form on My Profile can submit a SICK_SHORT request for 30+ days.
- **Evidence:** SICK limits are in `shortcode_request()` only; `handle_self_request()` has no sick-day-range validation.
- **Fix:** Extract leave type special-code validation into a shared method `validate_special_code_limits(string $special, int $days, int $cal_days): ?string` and call it from both submission paths.

---

## Files Reviewed

| File | Lines | Findings |
|------|-------|----------|
| `includes/Modules/Leave/Services/LeaveCalculationService.php` | 243 | 5 (LV-CALC-001, LV-CALC-003, LV-CALC-008, LV-CALC-009, LV-SEC-001) |
| `includes/Modules/Leave/class-leave-ui.php` | 105 | 0 (all code is output-safe, no $wpdb queries, no user input processing) |
| `includes/Modules/Leave/LeaveModule.php` (balance/type methods) | ~7390 | 14 |

---

## Recommendations Priority

1. **Immediate (Critical):**
   - **LV-CALC-002**: Stop overwriting opening/carried_over on every approval — fix the upsert in `handle_approve()` and `handle_early_return()` to read existing balance first
   - **LV-CALC-001**: Document or fix the Jan-1 tenure boundary so Saudi-law anniversary is respected
   - **LV-PERF-001**: Move `broadcast_holiday_added()` to a background cron job to prevent admin request timeout

2. **Next sprint (High):**
   - **LV-CALC-003**: Fix the `accrued=0` fallback override — a zero balance should mean zero, not "full quota"
   - **LV-CALC-004**: Use `business_days()` in `handle_self_request()` instead of calendar days
   - **LV-CALC-006**: Set `hajj_used_at` on employee record after Hajj leave approval
   - **LV-SEC-004**: Remove `administrator` bypass from `is_hr_user()` — use explicit capability grant
   - **LV-PERF-002**: Pre-fetch department manager IDs in `render_requests()` to eliminate N+1
   - **LV-PERF-004**: Fix `department_id` → `dept_id` column name in calendar employee count query (this is a bug, not just performance)

3. **Backlog (Medium/Low):**
   - **LV-CALC-005**: Use 365.2425 in Hajj tenure check
   - **LV-CALC-007**: Add upsert (not just update) in `recalc_balance_for_request()`
   - **LV-CALC-008**: Implement first-year pro-ration per Saudi law
   - **LV-CALC-009**: Block balance accrual for terminated employees
   - **LV-CALC-010**: Guard `closing` against negative in non-allow_negative types
   - **LV-SEC-002**: Refactor dynamic WHERE builds to always use prepare()
   - **LV-SEC-003**: Restrict `?err=` to allowlist of known error codes
   - **LV-SEC-005**: Check balance table before deleting leave type
   - **LV-DUP-001**: Extract unified `upsert_balance()` helper
   - **LV-DUP-002**: Consolidate HR-check logic to `is_hr_user()` everywhere
   - **LV-DUP-003**: Extract `get_gm_user_id()` private method
   - **LV-DUP-005**: Extract `validate_special_code_limits()` and call from both submission paths
