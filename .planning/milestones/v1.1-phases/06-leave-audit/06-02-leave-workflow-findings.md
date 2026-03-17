# Leave Approval Workflow / REST / Admin Audit Findings

**Audit date:** 2026-03-16
**Scope:** LeaveModule.php workflow/REST/admin methods (~7390 lines), class-leave-ui.php (105 lines) — focus on authorization, overlap detection, and endpoint security
**Auditor:** Claude (automated code review)
**Requirement:** CRIT-02

## Executive Summary

- **Critical:** 3 findings
- **High:** 6 findings
- **Medium:** 5 findings
- **Low:** 2 findings
- **Total:** 16 findings

### Top Issues

1. **LV-WF-001** (`LeaveModule.php:1057`) — `handle_approve()` has no up-front capability guard; any logged-in user who can submit a valid nonce can reach approval logic, relying solely on post-hoc position-based checks.
2. **LV-WF-003** (`LeaveModule.php:1761`) — `handle_cancel()` has no capability check at all; any logged-in user may attempt to cancel a leave request and the permission gate is purely manual attribute comparison.
3. **LV-SEC-001** (`LeaveCalculationService.php:86`) — `has_overlap()` is a plain SELECT-then-INSERT; two simultaneous requests for overlapping dates can both pass the overlap check before either row is committed (TOCTOU race condition).

---

## Workflow Findings

### Critical

#### LV-WF-001: handle_approve() Missing Up-Front Capability Check

- **File:** `includes/Modules/Leave/LeaveModule.php:L1057`
- **Description:** `handle_approve()` calls `check_admin_referer()` but never calls `Helpers::require_cap()` or any `current_user_can()` check before processing the approval. Any authenticated user who can guess or replay a valid nonce (e.g. a regular employee who once saw an approve form) can attempt to trigger the approval path. The function then relies on role checks embedded deep in multi-level conditional branches (position-based HR, GM, department manager), which is fragile defence-in-depth rather than a gate.
- **Evidence:**
  ```php
  public function handle_approve(): void {
      check_admin_referer('sfs_hr_leave_approve');

      $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
      $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';
      // ... no current_user_can() guard here
  ```
- **Fix:** Add `Helpers::require_cap('sfs_hr.leave.review')` as the very first line of `handle_approve()`, matching the pattern used in `handle_reject()`.

---

#### LV-WF-003: handle_cancel() Has No Capability or Login Check

- **File:** `includes/Modules/Leave/LeaveModule.php:L1761`
- **Description:** `handle_cancel()` checks the nonce but never verifies the user is logged in or has any capability. The permission logic (is_requester, is_hr, is_dept_manager) is correct in isolation, but a completely anonymous or low-privilege user who posts with a valid nonce passes straight through to the DB reads and potential state write before any auth check fires.
- **Evidence:**
  ```php
  public function handle_cancel(): void {
      $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
      check_admin_referer( 'sfs_hr_leave_cancel_' . $id );
      // ... no login check, no capability check
  ```
- **Fix:** Add `if ( ! is_user_logged_in() ) { wp_die(); }` and a minimal `current_user_can('sfs_hr.leave.request')` check at function entry, since both employees and HR users must be authenticated.

---

#### LV-WF-005: is_hr_user() Grants HR-Equivalent Access to Any sfs_hr_manager Role

- **File:** `includes/Modules/Leave/LeaveModule.php:L2575`
- **Description:** `is_hr_user()` — used by `handle_hold_leave()` and `handle_update_leave_dates()` to gate HR-only operations — grants HR status to **all users with the `sfs_hr_manager` role**, not just users listed as HR approvers. This means any department manager can hold/update leave dates for employees outside their department, which is an unauthorized privilege escalation.
- **Evidence:**
  ```php
  $user = get_user_by( 'id', $user_id );
  if ( $user && in_array( 'sfs_hr_manager', (array) $user->roles, true ) ) {
      return true;
  }
  ```
- **Fix:** Remove the role-based branch. `is_hr_user()` should only check the configured `sfs_hr_leave_hr_approvers` list and the `sfs_hr.leave.manage` capability, not any role.

---

### High

#### LV-WF-002: handle_approve() Accepts Nonce Shared Across All Requests

- **File:** `includes/Modules/Leave/LeaveModule.php:L1058`
- **Description:** `handle_approve()` uses the same single shared nonce `sfs_hr_leave_approve` for ALL leave request approvals. This means a nonce captured for approving leave ID 5 can be replayed to approve leave ID 999 before the nonce expires. The nonce should incorporate the request ID to prevent cross-request replay.
- **Evidence:**
  ```php
  check_admin_referer('sfs_hr_leave_approve');
  // ...
  $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
  ```
- **Fix:** Change the nonce action to `'sfs_hr_leave_approve_' . $id` (matching the pattern used for cancel: `sfs_hr_leave_cancel_' . $id`). Update nonce generation in `render_requests()` (L281) and all approval form views accordingly. Note: since the requests list renders many nonces at once, the preferred approach is to generate per-request nonces in the list loop.

---

#### LV-WF-004: rejected → approved State Transition Allowed via Pending Re-submission

- **File:** `includes/Modules/Leave/LeaveModule.php:L1081`
- **Description:** `handle_approve()` only processes requests where `status = 'pending'` (L1081). Similarly `handle_reject()` only transitions from `pending`. However the status machine has no enforcement against a cancelled/rejected request being manually re-set to `pending` via `handle_update_balance()` or direct DB manipulation. The state machine is enforced only at the handler level, not at the DB level; there is no `CHECK` constraint or enum validation on the status column.
- **Evidence:**
  ```php
  if ( ! $row || $row['status'] !== 'pending' ) {
      wp_safe_redirect($redirect_base);
      exit;
  }
  ```
- **Fix:** Add a DB-level `ENUM` constraint on `sfs_hr_leave_requests.status` to limit allowed values, and document the valid transition graph (pending → approved|rejected|cancelled; approved → cancelled; cancelled → [terminal]).

---

#### LV-WF-006: Balance Corruption in handle_approve() — Opening and Carried Over Destroyed

- **File:** `includes/Modules/Leave/LeaveModule.php:L1630`
- **Description:** (Confirmed in Plan 01 as LV-CALC-002, reproduced here for completeness in the workflow context.) On every approval the balance update hardcodes `$opening = 0` and `$carried = 0`, destroying any manually set opening balance or carried-over days stored in the DB:
- **Evidence:**
  ```php
  $opening = 0;
  $carried = 0;
  $accrued = $quota;
  $closing = $opening + $accrued + $carried - $used;
  ```
- **Fix:** Fetch the existing balance row first and preserve `opening` and `carried_over` values. Only set them to 0 if no balance row exists yet.

---

#### LV-WF-007: handle_cancellation_approve() Does Not Prevent HR from Self-Approving Cancellation at Manager Stage

- **File:** `includes/Modules/Leave/LeaveModule.php:L2024`
- **Description:** At Level 1 (department manager stage), `handle_cancellation_approve()` correctly blocks anyone who is not the department manager. However, the self-approval guard only compares employee's `user_id` to the current user — it does not block an HR user who also happens to be in the requester's department from approving at the manager stage on behalf of "HR authority", then immediately approving again at the HR stage. An HR user who is also a department manager can complete the entire two-stage cancellation workflow alone.
- **Evidence:**
  ```php
  // Level 1: only dept manager may approve
  if ( $approval_level < 2 ) {
      if ( ! $is_dept_manager ) { ... redirect }
      // HR can also satisfy is_dept_manager if assigned to dept
  }
  // Level 2: only HR may approve
  if ( ! $is_hr ) { ... redirect }
  ```
- **Fix:** At Level 1, if the approver is HR and the department has a manager who is different from the current user, require that manager to approve first. Track which user performed the Level 1 approval and block the same user from approving at Level 2.

---

### Medium

#### LV-WF-008: handle_approve() Does Not Re-validate Overlap After Approval Chain Escalation

- **File:** `includes/Modules/Leave/LeaveModule.php:L1440`
- **Description:** The overlap check is only performed when a request is initially submitted (in `shortcode_request()` and `handle_self_request()`). Between submission and final approval (which may take days through a multi-level chain), another request for the same dates could be approved first. By the time the final HR approval is processed, there is no re-validation against already-approved overlapping requests.
- **Evidence:**
  ```php
  // Final approval section: no has_overlap() call
  $wpdb->update($req_t, ['status' => 'approved', ...], ['id' => $id]);
  ```
- **Fix:** Call `LeaveCalculationService::has_overlap($employee_id, $start, $end)` inside `handle_approve()` at the final approval step (after the pending status check), excluding the current request from the overlap query.

---

## Security Findings

### Critical

#### LV-SEC-001: TOCTOU Race Condition in has_overlap() — Concurrent Submissions

- **File:** `includes/Modules/Leave/Services/LeaveCalculationService.php:L86`
- **Description:** `has_overlap()` performs a plain `SELECT COUNT(*)` to check for existing overlapping requests before a new request is inserted. There is no database-level lock (`SELECT ... FOR UPDATE`, transaction, or unique constraint) around the check-then-insert sequence. Two concurrent leave requests submitted simultaneously for overlapping dates will both see an empty overlap count and both be inserted successfully, resulting in double-booked leave.
- **Evidence:**
  ```php
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
  ```
- **Fix:** Wrap the overlap check and insert in a transaction with a per-employee advisory lock using `GET_LOCK("sfs_hr_leave_{$employee_id}", 3)` (the pattern already used by Attendance's Session_Service for recalculation). Release the lock after insert. Alternatively, add a composite `UNIQUE` index on `(employee_id, start_date, end_date)` to cause duplicate inserts to fail at the DB level, though this only prevents exact duplicates, not partial overlaps.

---

### High

#### LV-SEC-002: handle_approve() Uses Shared Nonce for Finance-Stage Approvals

- **File:** `includes/Modules/Leave/LeaveModule.php:L1539`
- **Description:** The finance approval stage (Level 3) shares the same `sfs_hr_leave_approve` nonce with manager and HR approval stages. A finance approver's captured nonce could be replayed by any other approved user to force a finance-stage approval before it should occur.
- **Evidence:**
  ```php
  if ( $approval_level >= 3 ) {
      $is_finance = current_user_can('sfs_hr_loans_finance_approve');
      $is_assigned_finance = ( $current_uid === $finance_approver_id );
      if ( ! $is_finance && ! $is_assigned_finance && ! $is_hr ) { ... }
  }
  // All reached via the same check_admin_referer('sfs_hr_leave_approve') at L1058
  ```
- **Fix:** This is resolved as part of the fix for LV-WF-002 — use per-request nonces that incorporate the request ID.

---

#### LV-SEC-003: handle_cancel_approved() Grants administrator Role Access to Cancel Approved Leave

- **File:** `includes/Modules/Leave/LeaveModule.php:L1872`
- **Description:** `handle_cancel_approved()` grants cancellation initiation rights to any user with the WP `administrator` role, including administrators who are not part of the HR system. This is inconsistent with `handle_approve()` which explicitly blocks pure administrators via `guard_admin_cannot_approve_or_reject()`.
- **Evidence:**
  ```php
  $is_hr = ( ! empty( $hr_user_ids ) && in_array( $current_uid, $hr_user_ids, true ) )
           || current_user_can( 'sfs_hr.leave.manage' )
           || current_user_can( 'administrator' );
  ```
- **Fix:** Remove `current_user_can('administrator')` from the `$is_hr` check in `handle_cancel_approved()`, `handle_cancellation_approve()`, and `handle_cancellation_reject()`. Only users in the `sfs_hr_leave_hr_approvers` list or with `sfs_hr.leave.manage` should have HR authority in the leave system.

---

#### LV-SEC-004: render_requests() Unbounded Result Set When is_hr_or_gm_for_view is True

- **File:** `includes/Modules/Leave/LeaveModule.php:L267`
- **Description:** The `render_requests()` admin list query has a hardcoded `LIMIT 20` (per-page), but the count query used for tab badges (`$counts`) runs a full table-scan `SELECT COUNT(*)` for every status tab on every page load. For organisations with tens of thousands of leave requests, this fires 11+ unbounded aggregate queries per admin page load, every time HR or a GM opens the leave requests page.
- **Evidence:**
  ```php
  foreach ( array_keys( $status_tabs ) as $s ) {
      if ( $s === 'all' ) {
          $sql_count = "SELECT COUNT(*) FROM $req_t r JOIN $emp_t e ...";
          $counts['all'] = ...
      } else {
          $sql_count = "SELECT COUNT(*) FROM $req_t r JOIN $emp_t e WHERE r.status = %s ...";
  ```
- **Fix:** Cache the status counts in a transient (e.g., `sfs_hr_leave_counts_{$current_uid}`) with a 60-second TTL, or lazily load tab badge counts via AJAX.

---

### Medium

#### LV-SEC-005: handle_approve() Does Not Verify employee_id Integrity Against Session

- **File:** `includes/Modules/Leave/LeaveModule.php:L1057`
- **Description:** The `id` parameter (leave request ID) is taken from POST and used to fetch the associated request. However, only the request ID is validated — there is no check that the employee linked to the request actually belongs to a department managed by the approver before the DB fetch. The department-membership check comes after the employee info fetch (L1262), meaning the DB query is executed regardless of whether the current user has any relationship with the request.
- **Evidence:**
  ```php
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $req_t WHERE id=%d", $id), ARRAY_A);
  // ... several more queries ...
  if ( ! $is_hr_or_gm ) {
      $managed = $this->manager_dept_ids_for_user($current_uid);
      if ( empty($managed) || ! in_array((int)($empInfo['dept_id'] ?? 0), $managed, true) ) {
  ```
- **Fix:** This is a defence-in-depth concern rather than a direct exploit (the check does occur before any state change), but ideally move the `is_hr_or_gm` / department check immediately after fetching the row, before fetching employee info.

---

#### LV-SEC-006: Calendar View Leaks All Employee Leave to Any Leave Reviewer

- **File:** `includes/Modules/Leave/LeaveModule.php:L6266`
- **Description:** `render_calendar()` is called after `render_leave_admin()` which requires `sfs_hr.leave.review`. However, department managers with `sfs_hr.leave.review` who should only see their own department's data are shown **all approved leave for all employees** in the calendar — there is no department-scoping filter applied to the calendar query equivalent to what `render_requests()` implements.
- **Evidence:**
  ```php
  $dept_where = '';
  if ( $filter_dept > 0 ) {
      $dept_where = $wpdb->prepare( " AND e.dept_id = %d", $filter_dept );
  }
  // No automatic scoping to manager's own departments
  $leaves = $wpdb->get_results( $wpdb->prepare(
      "SELECT r.id, r.employee_id, ...", ...
  ```
- **Fix:** Apply the same `$managed_depts` scoping used in `render_requests()` to the calendar query: if the current user is not HR/GM, restrict the calendar to their managed departments.

---

## Logic Findings

### High

#### LV-LOGIC-001: handle_self_request() Uses Calendar Days; shortcode_request() Uses Business Days

- **File:** `includes/Modules/Leave/LeaveModule.php:L6020` vs `L4484`
- **Description:** (Confirmed in Plan 01 as LV-CALC-004.) The two frontend leave submission paths calculate the `days` field differently. `handle_self_request()` uses inclusive calendar day count (`floor((end_ts - start_ts) / DAY) + 1`), while `shortcode_request()` calls `$this->business_days()` (which excludes Fridays and company holidays). This means the same request submitted via the employee portal versus the profile-tab form will record different day counts, affecting balance deduction and pay calculations.
- **Evidence:**
  ```php
  // handle_self_request() L6020:
  $days = (int) floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;

  // shortcode_request() L4484:
  $days = (int)$this->business_days($start, $end);
  ```
- **Fix:** Standardise all leave day counting on `LeaveCalculationService::business_days()`. Remove the calendar-day formula from `handle_self_request()`.

---

### Medium

#### LV-LOGIC-002: handle_cancellation_approve() Does Not Restore Attendance Session Status

- **File:** `includes/Modules/Leave/LeaveModule.php:L2094`
- **Description:** When a leave cancellation is approved, the leave request is set to `cancelled` and the balance is restored. However, any attendance sessions that were flipped to `on_leave` status (by the retroactive flip in `handle_approve()` at L1602) are **not restored to their prior status** (e.g., `absent`). This means attendance records remain incorrect after a cancelled leave is approved.
- **Evidence:**
  ```php
  // handle_cancellation_approve() — no attendance session update
  $wpdb->update( $req_t, ['status' => 'cancelled', ...], ['id' => (int)$cancel['leave_request_id']] );
  // balance restored, but no sessions_t update
  ```
- **Fix:** After setting the leave status to `cancelled`, run the same reverse query used in `handle_hold_leave()` (L2338) to flip `on_leave` sessions back to `absent` for the leave's date range.

---

#### LV-LOGIC-003: handle_approve() Business-Day Count Not Re-Validated at Final Approval

- **File:** `includes/Modules/Leave/LeaveModule.php:L1574`
- **Description:** The `days` value stored on the request at submission time is used directly for balance deduction at final approval time. If a company holiday is added between request submission and final approval, the day count becomes stale — the employee would be charged fewer days than the actual approved period covers.
- **Evidence:**
  ```php
  $used = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(days),0) FROM $req_t
       WHERE employee_id=%d AND type_id=%d AND status='approved' AND YEAR(start_date)=%d",
      ...
  ));
  // uses stored days from request row, not recalculated
  ```
- **Fix:** Recalculate `LeaveCalculationService::business_days($row['start_date'], $row['end_date'])` at final approval time and update the `days` column before computing the balance.

---

#### LV-LOGIC-004: handle_approve() Balance Recalculation Uses Full-Year SUM Without Index Guard

- **File:** `includes/Modules/Leave/LeaveModule.php:L1616`
- **Description:** On every approval, balance recalculation performs `SUM(days)` over all approved requests for the employee+type+year. For an employee with many approved requests, this is a full table scan within that year. There is no index on `(employee_id, type_id, status, start_date)` to make this efficient. In the context of `shortcode_request()` and `handle_self_request()` this runs on every leave approval.
- **Evidence:**
  ```php
  $used = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(days),0) FROM $req_t
       WHERE employee_id=%d AND type_id=%d AND status='approved' AND YEAR(start_date)=%d",
  ```
- **Fix:** Add a composite index on `sfs_hr_leave_requests(employee_id, type_id, status, start_date)` via `add_column_if_missing()` pattern in Migrations.php to support this common query pattern.

---

## Performance Findings

### Medium

#### LV-API-PERF-001: render_requests() Fires 11+ Count Queries Per Page Load

- **File:** `includes/Modules/Leave/LeaveModule.php:L255`
- **Description:** The requests list page fires a separate `COUNT(*)` query for each of the 11 status tabs on every page load, plus the main listing query. This is 12+ queries per HR page view. At scale this becomes a measurable performance issue.
- **Evidence:**
  ```php
  foreach ( array_keys( $status_tabs ) as $s ) {
      // 11 iterations, each firing a COUNT query
  ```
- **Fix:** Replace with a single GROUP BY query: `SELECT status, COUNT(*) FROM $req_t GROUP BY status` (with department scoping WHERE clause applied). Cache in a short-lived transient.

---

### Low

#### LV-API-PERF-002: broadcast_holiday_added() Sends Email Per Recipient in a Loop

- **File:** `includes/Modules/Leave/LeaveModule.php:L5185`
- **Description:** Holiday broadcast emails are sent one at a time in a foreach loop over all active employee email addresses. For an organisation with 500 employees, this blocks the HTTP request for the duration of all 500 `wp_mail()` calls.
- **Evidence:**
  ```php
  foreach ($emails as $to) {
      Helpers::send_mail($to, $subject, $body);
  }
  ```
- **Fix:** Dispatch each email via a background action using `do_action_ref_array()` / WP Cron batch, or use a single BCC/batch-SMTP approach.

---

#### LV-API-PERF-003: employee_tab_content() Fetches Leave History Without Pagination

- **File:** `includes/Modules/Leave/LeaveModule.php:L5750`
- **Description:** The leave tab in Employee Profile / My Profile fetches up to 100 leave requests without any user-controlled pagination, including all their historical requests. For long-tenured employees this loads 100 rows unconditionally.
- **Evidence:**
  ```php
  "...WHERE r.employee_id = %d ORDER BY r.created_at DESC, r.id DESC LIMIT 100",
  ```
- **Fix:** Add pagination controls, or reduce default limit to 20 with a "show more" button.

---

## Files Reviewed

| File | Lines | Findings |
|------|-------|----------|
| LeaveModule.php (workflow/REST/admin) | 7390 | 15 |
| class-leave-ui.php | 105 | 1 (clean — all output properly escaped) |

## Notes on REST Endpoints

The audit found **no `register_rest_route()` calls** in LeaveModule.php. The Leave module does not expose any REST API endpoints. All leave operations are handled exclusively through `admin_post_*` form submissions (which are subject to WordPress nonce and admin authentication checks by default). This is a positive finding — the attack surface is limited to authenticated admin-post handlers only.

There are no `wp_ajax_*` handlers in LeaveModule.php — no AJAX handlers to audit for this module.

## Notes on class-leave-ui.php

`class-leave-ui.php` is a pure utility class with three static methods: `leave_status_label()`, `leave_status_color()`, and `leave_status_chip()`. All string output is properly sanitized with `sanitize_key()` and properly escaped with `esc_attr()` and `esc_html()`. No SQL queries or user input processing is present. **No findings.**

## Recommendations Priority

1. **Immediate (Critical):**
   - LV-WF-001: Add `Helpers::require_cap('sfs_hr.leave.review')` to `handle_approve()`
   - LV-WF-003: Add login check + capability check to `handle_cancel()`
   - LV-SEC-001: Wrap overlap-check + insert in a per-employee advisory lock (`GET_LOCK`)

2. **Next sprint (High):**
   - LV-WF-002 / LV-SEC-002: Migrate to per-request nonces for approve/reject
   - LV-WF-005: Remove `sfs_hr_manager` role from `is_hr_user()` definition
   - LV-WF-006: Fix balance corruption in `handle_approve()` (preserve opening/carried_over)
   - LV-WF-007: Prevent same HR user from approving both stages of cancellation
   - LV-SEC-003: Remove bare `administrator` role from cancel-approved permission checks
   - LV-SEC-004: Cache or optimise status-tab count queries
   - LV-LOGIC-001: Standardise day counting on `business_days()` in both submission paths

3. **Backlog (Medium/Low):**
   - LV-WF-008: Re-validate overlap at final approval step
   - LV-SEC-006: Scope calendar view to manager's departments
   - LV-LOGIC-002: Restore attendance sessions when cancellation is approved
   - LV-LOGIC-003: Recalculate business days at final approval time
   - LV-LOGIC-004: Add composite index on leave_requests for balance SUM query
   - LV-API-PERF-001: Replace 11 count queries with single GROUP BY query
   - LV-API-PERF-002: Batch holiday broadcast emails via background processing
   - LV-API-PERF-003: Paginate employee leave history tab
