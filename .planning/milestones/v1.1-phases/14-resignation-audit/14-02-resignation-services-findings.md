# Phase 14, Plan 02: Resignation Services, Handlers, Frontend, Cron, Notifications Audit Findings

**Date:** 2026-03-16
**Auditor:** GSD execute-phase agent (claude-sonnet-4-6)
**Files audited:** 5 files, ~1,400 lines

| File | Lines | Role |
|------|-------|------|
| `includes/Modules/Resignation/Services/class-resignation-service.php` | 382 | Core business logic, DB queries, resignation CRUD |
| `includes/Modules/Resignation/Handlers/class-resignation-handlers.php` | 537 | POST handlers: submit, approve, reject, cancel, final exit, termination, settings |
| `includes/Modules/Resignation/Frontend/class-resignation-shortcodes.php` | 140 | Employee-facing resignation form and status |
| `includes/Modules/Resignation/Cron/class-resignation-cron.php` | 76 | Daily auto-termination job |
| `includes/Modules/Resignation/Notifications/class-resignation-notifications.php` | 265 | Email notification dispatch |

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 0 | 3 | 2 | 1 | 6 |
| Performance | 0 | 1 | 2 | 1 | 4 |
| Duplication | 0 | 0 | 2 | 1 | 3 |
| Logical | 0 | 2 | 3 | 1 | 6 |
| **Total** | **0** | **6** | **9** | **4** | **19** |

---

## Phase Success Criteria Results

| Criterion | Result |
|-----------|--------|
| Resignation submission self-only enforcement | **SAFE** — `handle_submit()` derives `employee_id` exclusively from `Helpers::current_employee_id()` (current WP user); no request parameter can override it |
| Approval state machine backwards-transition analysis | **VULNERABLE (High)** — `handle_approve()` does not enforce `WHERE status='pending' AND approval_level=%d` on UPDATE; `handle_reject()` and `handle_cancel()` also lack conditional guards |
| All `$wpdb` calls catalogued | **COMPLETE** — see call-accounting table below |
| Handlers checked for nonce + capability at entry | **PARTIAL** — `handle_submit()`, `handle_approve()`, `handle_reject()`, `handle_cancel()` use nonce only at entry; capability check is deferred to `can_approve_resignation()` (which is called after nonce, so adequate in practice but inconsistent with the module's own pattern) |
| Frontend shortcode data scoping | **SAFE** — `render_my_resignations()` queries `WHERE employee_id = %d` using `current_employee_id()`; no IDOR risk |
| Findings report with severity ratings | **COMPLETE** |

---

## Security Findings

### RHDL-SEC-001 — High — `handle_submit()` uses `$_POST['_wp_http_referer']` for open redirect

**Severity:** High
**Location:** `includes/Modules/Resignation/Handlers/class-resignation-handlers.php:77`
**Description:**
```php
$redirect_url = isset($_POST['_wp_http_referer']) ? $_POST['_wp_http_referer'] : admin_url('...');
Helpers::redirect_with_notice($redirect_url, 'success', ...);
```
The redirect target is taken directly from a user-controlled POST parameter. Even though the nonce check at line 37 prevents unauthenticated submissions, an authenticated employee crafting a resignation POST can set `_wp_http_referer` to an external URL and be redirected off-site on success.

**Impact:** CSRF-protected phishing vector: an attacker can trick an employee into submitting their own resignation (which may be the desired action!) while being redirected to an attacker-controlled URL on success. This is the same finding as RES-SEC-002 from Phase 14 Plan 01, now confirmed in the handler file itself.

**Fix recommendation:**
```php
$redirect_url = isset($_POST['_wp_http_referer'])
    ? wp_validate_redirect(wp_unslash($_POST['_wp_http_referer']), admin_url('admin.php?page=sfs-hr-my-profile'))
    : admin_url('admin.php?page=sfs-hr-my-profile');
wp_safe_redirect($redirect_url);
exit;
```

---

### RHDL-SEC-002 — High — `handle_approve()`, `handle_reject()`, `handle_cancel()` have no top-level `current_user_can()` guard

**Severity:** High
**Location:**
- `class-resignation-handlers.php:88` (`handle_approve`)
- `class-resignation-handlers.php:204` (`handle_reject`)
- `class-resignation-handlers.php:265` (`handle_cancel`)

**Description:** Three of the seven POST handlers use nonce verification at entry (`check_admin_referer()`) but do NOT call `current_user_can()` at the top. Capability enforcement is deferred to `Resignation_Service::can_approve_resignation()` which is called after the nonce check. In contrast, `handle_final_exit_update()` (line 343), `handle_company_termination()` (line 404), and `handle_settings()` (line 461) all use explicit `current_user_can('sfs_hr.manage')` at entry.

While `can_approve_resignation()` does perform capability logic, the inconsistency creates risk: if `can_approve_resignation()` logic changes (e.g., returns `true` on a default or error path), the handlers would have no fallback gate. This is the same pattern flagged in Phase 13 Hiring (HADM-SEC-002) and Phase 06 Leave (`handle_approve()` missing up-front capability guard).

**Impact:** Not an immediate vulnerability because `can_approve_resignation()` does correctly enforce capability rules. However, the pattern is fragile: a refactor of `can_approve_resignation()` could silently remove the gate. The inconsistency also makes code review harder.

**Fix recommendation:** Add a fast-fail capability guard at the top of each handler:
```php
public function handle_approve(): void {
    check_admin_referer('sfs_hr_resignation_approve');
    if (!current_user_can('sfs_hr.manage') && !current_user_can('sfs_hr.leave.review')) {
        wp_die(__('Access denied', 'sfs-hr'));
    }
    // ... rest of handler
}
```
The same pattern applies to `handle_reject()` and `handle_cancel()`. Use `sfs_hr.manage` OR `sfs_hr.leave.review` (dept managers) as the allowed capabilities.

---

### RHDL-SEC-003 — High — `handle_cancel()` reuses approval-capability check for cancellation

**Severity:** High
**Location:** `includes/Modules/Resignation/Handlers/class-resignation-handlers.php:280`
**Description:**
```php
if (!$resignation || !Resignation_Service::can_approve_resignation($resignation)) {
    wp_die(__('You cannot cancel this resignation.', 'sfs-hr'));
}
```
Cancellation is gated on `can_approve_resignation()` — the same check used for approvals. This means only department managers and the configured HR/Finance approvers can cancel a resignation. However, there is no business logic reason why *cancellation* requires the same capability profile as *approval*. More critically, `can_approve_resignation()` returns `true` based on the current `approval_level` — so at approval_level=2 (HR stage), the original dept manager who approved at level 1 would **fail** `can_approve_resignation()` and be unable to cancel a resignation they previously approved.

Additionally, the employee who submitted the resignation cannot cancel it themselves via this handler (there is no self-cancellation path).

**Impact:** A dept manager who approved a level-1 resignation cannot cancel it once it advances to HR stage — the check blocks them based on wrong-level logic. HR-initiated cancellations at level 2 work correctly, but manager-initiated cancellations are broken at level 2+.

**Fix recommendation:** Define a separate cancellation capability check that allows: `sfs_hr.manage`, OR any dept manager who has managed the employee's department (regardless of current approval_level), OR the employee themselves for pending-only resignations:
```php
private function can_cancel_resignation(array $resignation): bool {
    if (current_user_can('sfs_hr.manage')) return true;
    $managed = Resignation_Service::get_manager_dept_ids(get_current_user_id());
    return in_array($resignation['dept_id'], $managed, true);
}
```

---

### RSVC-SEC-001 — Medium — `can_approve_resignation()` returns `true` for all `sfs_hr.manage` users on any resignation status

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Services/class-resignation-service.php:206`
**Description:**
```php
if (current_user_can('sfs_hr.manage')) {
    return true;
}
```
`can_approve_resignation()` returns `true` for any `sfs_hr.manage` user regardless of the resignation's current status. This means an HR manager can "approve" an already-approved or already-rejected resignation via the handler. The handler reads the resignation, appends to the approval chain, and issues an UPDATE — no status validation occurs before the update.

Combined with the lack of conditional UPDATE guard (RHDL-LOGIC-001 below), an HR manager can re-approve a `rejected` or `approved` resignation, corrupting its state.

**Impact:** HR managers can accidentally or intentionally re-approve completed/rejected resignations. The database record would show inconsistent status transitions.

**Fix recommendation:** In `can_approve_resignation()`, add a status check:
```php
if (current_user_can('sfs_hr.manage')) {
    return $resignation['status'] === 'pending';
}
```
Or validate `status === 'pending'` in the handler before calling `can_approve_resignation()`.

---

### RNTF-SEC-001 — Medium — HR notification for submission includes full resignation reason text

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Notifications/class-resignation-notifications.php:163–167`
**Description:**
```php
__("...\n\nReason:\n%s\n\nView details: %s", 'sfs-hr'),
...,
$resignation['reason'] ?? 'Not specified',
$admin_url
```
The `submitted` HR notification includes the full resignation reason in the email body. The HR email list comes from `CoreNotifications::get_settings()['hr_emails']` — a potentially broad distribution list. If the resignation reason contains sensitive personal content (e.g., illness, family dispute, workplace conflict), it is sent in plaintext to all HR email recipients.

**Impact:** Resignation reasons may be confidential to the employee and their direct manager. Broadcasting the full text to all HR emails violates the principle of least exposure.

**Fix recommendation:** Remove the reason body from the HR notification email; include only the admin_url link. Reviewers can see the reason upon logging in:
```php
__("A new resignation has been submitted.\n\nEmployee: %s\nEmployee Code: %s\nResignation Date: %s\nLast Working Day: %s\n\nView details: %s", 'sfs-hr'),
$employee_name, $resignation['employee_code'] ?? 'N/A',
$resignation['resignation_date'], $resignation['last_working_day'], $admin_url
```

---

### RSVC-SEC-002 — Low — Dual-path `get_var()` without `prepare()` for empty-params case

**Severity:** Low
**Location:** `includes/Modules/Resignation/Services/class-resignation-service.php:85` and `139`
**Description:**
```php
$total = empty($params) ? (int)$wpdb->get_var($sql_total) : (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params));
```
When `$params` is empty (status='all', no dept filter, no search), `$sql_total` is a static query and is executed without `prepare()`. The query contains no user-supplied values, so no injection risk exists today. However, the dual-path pattern is fragile: a future developer could add a filter that appends a value to `$where` without updating `$params`, causing the raw path to execute with interpolated data.

This same pattern was flagged in Phase 14 Plan 01 ($wpdb call-accounting notes).

**Impact:** Low risk today. Maintenance hazard for future changes.

**Fix recommendation:** Consolidate to a single path that always uses `prepare()`:
```php
if (empty($params)) {
    $total = (int)$wpdb->get_var($sql_total);
} else {
    $total = (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params));
}
// Or: use wpdb->prepare() even for static queries by checking if $params is empty
// before building the prepare call — wpdb->prepare() with no %s/%d is safe
```

---

## Performance Findings

### RSVC-PERF-001 — High — `get_status_counts()` fires 6 sequential COUNT queries per page load

**Severity:** High
**Location:** `includes/Modules/Resignation/Services/class-resignation-service.php:131–139`
**Description:**
```php
foreach (array_keys(self::get_status_tabs()) as $st) {
    // One COUNT query per iteration
    $counts[$st] = ... $wpdb->get_var(...);
}
```
Six separate `SELECT COUNT(*)` queries are issued per page view. This is the same N+1 pattern identified as RES-PERF-001 in Phase 14 Plan 01. Confirmed in the service file: the method issues exactly 6 round-trips.

**Impact:** Minimum 6 additional DB round-trips on every resignation list page load. Scales poorly with dept filter + search parameters (each query re-applies same WHERE).

**Fix recommendation:** Collapse into a single `GROUP BY` query:
```sql
SELECT status, resignation_type, COUNT(*) as cnt
FROM {$table} r
JOIN {$emp_t} e ON e.id = r.employee_id
WHERE {$where_clause}
GROUP BY status, resignation_type
```
Then distribute counts in PHP, treating `resignation_type = 'final_exit'` records as their own bucket.

---

### RCRN-PERF-001 — Medium — Cron queries all active employees with approved resignations in one SELECT

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Cron/class-resignation-cron.php:37–45`
**Description:**
```php
$expired_resignations = $wpdb->get_results($wpdb->prepare(
    "SELECT r.id, r.employee_id, r.last_working_day, e.status as employee_status, e.user_id
     FROM {$resign_table} r
     JOIN {$emp_t} e ON e.id = r.employee_id
     WHERE r.status = 'approved'
     AND r.last_working_day < %s
     AND e.status = 'active'",
    $today
), ARRAY_A);
```
The query is correctly bounded by `status = 'approved'` and `last_working_day < today` — this is NOT an unbounded query. For normal operations, this set stays small (only resignations whose last working day has passed but employee hasn't been terminated yet). The query is safe and cron design is correct. Minor concern: if the cron is disabled for an extended period (weeks/months), a backlog could accumulate, and the subsequent catch-up run would process many employees in a single PHP request, potentially hitting PHP execution time limits.

**Impact:** Low risk in normal operation. Potential timeout risk in catch-up scenario after cron downtime.

**Fix recommendation:** Add a `LIMIT` clause as a safety valve:
```php
"... AND e.status = 'active'
 ORDER BY r.last_working_day ASC
 LIMIT 100"
```
Process in batches if needed. Log a warning if the result set hits the limit.

---

### RSVC-PERF-002 — Medium — No DB index on `status` or `last_working_day` for cron and list queries

**Severity:** Medium
**Location:** `class-resignation-cron.php:37–45` and `class-resignation-service.php:48–96`
**Description:** The main query path in `get_resignations()` filters by `r.status` and the cron filters by `r.status` AND `r.last_working_day`. Whether these columns are indexed on `sfs_hr_resignations` cannot be confirmed from PHP code alone. Based on the pattern across prior phases (no index validation), they likely lack indexes. The `status` column is queried on every list view and every cron run.

**Impact:** Full table scans on `sfs_hr_resignations` for every list page view and every daily cron run as the table grows.

**Fix recommendation:** Add index declarations in Migrations.php using the idempotent pattern:
```php
// status index (used by list queries + cron)
$wpdb->query("ALTER TABLE {$wpdb->prefix}sfs_hr_resignations ADD INDEX IF NOT EXISTS idx_res_status (status)");
// last_working_day index (used by cron)
$wpdb->query("ALTER TABLE {$wpdb->prefix}sfs_hr_resignations ADD INDEX IF NOT EXISTS idx_res_lwd (last_working_day)");
```
Note: use `add_column_if_missing()` pattern or a guarded `ADD INDEX IF NOT EXISTS` — MySQL 8.0+ supports `IF NOT EXISTS` for indexes.

---

### RHDL-PERF-001 — Low — `handle_approve()` calls `check_loan_clearance()` and `check_asset_clearance()` cross-module on every approval

**Severity:** Low
**Location:** `includes/Modules/Resignation/Handlers/class-resignation-handlers.php:178–191`
**Description:**
```php
$loan_status = Resignation_Service::check_loan_clearance($resignation['employee_id']);
$asset_status = Resignation_Service::check_asset_clearance($resignation['employee_id']);
```
On every approval (including intermediate approvals at level 1 that don't result in final `approved` status), the handler calls into two external modules (`LoansModule`, `AssetsModule`) to build a warning message. This cross-module check fires even when the approval advances from level 1 to level 2 (before final approval), which seems premature — clearance checks are most meaningful at final approval.

**Impact:** Two additional cross-module queries on every intermediate approval step. Not a major issue for current usage but adds unnecessary overhead for multi-level approval chains.

**Fix recommendation:** Limit clearance checks to final approvals only:
```php
if ($is_final_approval) {
    $loan_status = Resignation_Service::check_loan_clearance($resignation['employee_id']);
    $asset_status = Resignation_Service::check_asset_clearance($resignation['employee_id']);
    // append warnings to $success_message
}
```

---

## Duplication Findings

### RSVC-DUP-001 — Medium — Notification methods duplicate subject/message structure

**Severity:** Medium
**Location:**
- `class-resignation-notifications.php:43–52` (notify_new_submission — manager)
- `class-resignation-notifications.php:126–131` (notify_next_approver)

**Description:** `notify_new_submission()` (manager notification) and `notify_next_approver()` produce nearly identical email content:
- Both: "A resignation has been submitted/is pending your approval from {name}"
- Both include resignation_date and last_working_day
- Both: "Please log in to review and approve."

The only difference is the subject line and a minor phrase variation. If the email template needs updating (e.g., adding a direct approval link), it must be updated in two places.

**Impact:** Template divergence risk. The `notify_new_submission` message uses the employee's `first_name`/`last_name` from the resignation array while `notify_next_approver` also uses those fields — consistent, but duplicated template logic.

**Fix recommendation:** Extract a shared `build_approval_request_message(array $resignation): array` helper returning subject and message, called by both methods:
```php
private static function build_approval_request_message(array $resignation, string $context = 'submitted'): array {
    $subject = $context === 'submitted'
        ? __('New Resignation Submitted', 'sfs-hr')
        : __('Resignation Pending Your Approval', 'sfs-hr');
    $message = sprintf(__('A resignation %s by %s %s and requires your approval.', 'sfs-hr'), ...);
    // ... shared fields
    return compact('subject', 'message');
}
```

---

### RSVC-DUP-002 — Medium — `get_status_counts()` duplicates WHERE clause construction from `get_resignations()`

**Severity:** Medium
**Location:**
- `class-resignation-service.php:48–103` (`get_resignations`)
- `class-resignation-service.php:109–143` (`get_status_counts`)

**Description:** Both methods build identical WHERE clause fragments for dept filter and search filter:
```php
// In get_resignations():
if (!empty($dept_ids)) {
    $placeholders = implode(',', array_fill(0, count($dept_ids), '%d'));
    $where .= " AND e.dept_id IN ($placeholders)";
    $params = array_merge($params, array_map('intval', $dept_ids));
}
if ($search !== '') {
    $where .= " AND (e.first_name LIKE %s OR e.last_name LIKE %s OR e.employee_code LIKE %s)";
    $like = '%' . $wpdb->esc_like($search) . '%';
    $params = array_merge($params, [$like, $like, $like]);
}
```
This 10-line block appears in both methods with identical logic and structure. If a new filter is added (e.g., date range, resignation type), it must be added in both places.

**Impact:** Maintenance risk. A future filter added to `get_resignations()` that is not reflected in `get_status_counts()` will cause tab counts to diverge from actual list counts.

**Fix recommendation:** Extract to a private helper:
```php
private static function build_filter_clause(string $search, array $dept_ids): array {
    $where = ''; $params = [];
    if (!empty($dept_ids)) { ... }
    if ($search !== '') { ... }
    return [$where, $params];
}
```
Both `get_resignations()` and `get_status_counts()` call this helper.

---

### RNTF-DUP-001 — Low — `send_notification()` (legacy) and `send_notification_localized()` both exist; old method partially dead

**Severity:** Low
**Location:** `class-resignation-notifications.php:202–216` and `221–237`
**Description:** `send_notification()` (lines 202–216) is the old direct-call method. `send_notification_localized()` (lines 221–237) is the newer wrapper that handles locale switching before building content and then calls `send_notification()` internally. All public methods now call `send_notification_localized()` — the old `send_notification()` is only called from `send_notification_localized()`. However, `send_notification()` is declared `private static` (not deprecated) and remains as implementation detail rather than dead code.

The DOFS filter references in `send_notification()` (`dofs_user_wants_email_notification`, `dofs_should_send_notification_now`) are cross-plugin hooks that reference a separate CLAUDE.md-prohibited integration point. CLAUDE.md explicitly states "No direct dependency on other plugins. Does not share DB tables with DOFS or SimpleFlow." These filter names reference DOFS directly.

**Impact:** The DOFS filter references at lines 204 and 209 create a soft dependency on DOFS plugin. If DOFS is not installed, the filters return `true` (default), so email is sent normally — no break. But these are named with a DOFS prefix that violates the no-cross-plugin-dependency rule.

**Fix recommendation:** Rename the filters to `sfs_hr_*` namespace:
```php
// Line 204:
if (!apply_filters('sfs_hr_user_wants_email_notification', true, $user_id, $notification_type)) {
// Line 209:
if (apply_filters('sfs_hr_send_notification_now', true, $user_id, $notification_type)) {
```
This is a breaking change for any site using DOFS hooks — document the rename in a changelog. In v1.1 (audit-only), flag for v1.2 fix.

---

## Logical Findings

### RHDL-LOGIC-001 — High — `handle_approve()` and `handle_reject()` lack conditional UPDATE guard (TOCTOU)

**Severity:** High
**Location:**
- `class-resignation-handlers.php:153` (`handle_approve` UPDATE)
- `class-resignation-handlers.php:233–240` (`handle_reject` UPDATE)

**Description:** Both handlers follow the pattern:
1. Load resignation (`get_resignation()`)
2. Check `can_approve_resignation()` — passes if status is pending and user has correct role
3. Build `$update_data` / chain entries
4. Execute `$wpdb->update($table, $update_data, ['id' => $resignation_id])` — **no WHERE status/level condition**

Between steps 1 and 4, another request can execute the same flow. Two concurrent approvals at the same level will both read `approval_level=1`, both write `approval_level=2`, and both append to the chain JSON — resulting in a corrupted chain with two level-1 entries.

More critically, `handle_approve()` does not verify `status = 'pending'` before the UPDATE. An already-approved resignation can be re-approved by any `sfs_hr.manage` user (since `can_approve_resignation()` returns `true` for manage cap on any status — see RSVC-SEC-001). This was flagged as RADM-LOGIC-002 in Phase 14 Plan 01. This finding confirms the vulnerability exists at the handler level with direct code evidence.

**Impact:** Race condition in multi-approver scenarios (two managers at level 1); double-approval of already-decided resignations by HR users.

**Fix recommendation:**
```php
// In handle_approve():
$updated = $wpdb->query($wpdb->prepare(
    "UPDATE {$table} SET status = %s, approval_level = %d, approval_chain = %s,
     approver_id = %d, approver_note = %s, updated_at = %s
     WHERE id = %d AND status = 'pending' AND approval_level = %d",
    $update_data['status'] ?? 'pending',
    $update_data['approval_level'] ?? $current_level,
    $update_data['approval_chain'],
    get_current_user_id(), $note, current_time('mysql'),
    $resignation_id, $current_level
));
if (!$updated) {
    wp_die(__('This resignation was already processed by another approver.', 'sfs-hr'));
}

// In handle_reject():
$updated = $wpdb->query($wpdb->prepare(
    "UPDATE {$table} SET status = 'rejected', approval_chain = %s, approver_note = %s,
     approver_id = %d, decided_at = %s, updated_at = %s
     WHERE id = %d AND status = 'pending'",
    ...
));
```

---

### RHDL-LOGIC-002 — High — `handle_cancel()` can cancel already-approved or rejected resignations

**Severity:** High
**Location:** `includes/Modules/Resignation/Handlers/class-resignation-handlers.php:293–300`
**Description:**
```php
$wpdb->update($table, [
    'status'         => 'cancelled',
    ...
], ['id' => $resignation_id]);
```
The cancellation UPDATE has no WHERE status constraint. Any resignation — regardless of current status — can be cancelled by a user who passes `can_approve_resignation()`. This means:
- An `approved` resignation can be cancelled (might be intentional for cases where the employee changes their mind after approval)
- A `rejected` resignation can be cancelled (logically meaningless — a rejected resignation is already effectively cancelled)
- A `completed` resignation can be cancelled (would revert a terminated employee to active after they've already exited)

The employee reactivation logic at lines 308–325 compounds this: if a `completed` resignation is cancelled, the employee's status is reverted to `active` even though they may have already physically departed.

**Impact:** Data integrity risk: cancelling a `completed` or `final_exit` resignation would trigger automatic employee reactivation, which is likely incorrect for departed employees.

**Fix recommendation:** Add status guard before UPDATE:
```php
if (!in_array($resignation['status'], ['pending', 'approved'], true)) {
    wp_die(__('Cannot cancel a resignation with status: ' . $resignation['status'], 'sfs-hr'));
}
```
Additionally, for the `approved` cancellation path, consider requiring `sfs_hr.manage` rather than relying on `can_approve_resignation()`.

---

### RCRN-LOGIC-001 — Medium — Cron uses `current_time('Y-m-d')` (local time) which may not align with last_working_day stored dates

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Cron/class-resignation-cron.php:34`
**Description:**
```php
$today = current_time('Y-m-d');
```
`current_time()` returns the WordPress site's local time. The `handle_submit()` handler at line 54 calculates `last_working_day` using PHP's `date()` function:
```php
$last_working_day = date('Y-m-d', strtotime($resignation_date . ' + ' . $notice_period_days . ' days'));
```
PHP's `date()` uses the PHP server's timezone, which may differ from WordPress's site timezone. The cron comparison (`last_working_day < $today`) could fire 1 day early or late if the server timezone differs from WP site timezone.

Phase 05 (Attendance audit) documented a similar UTC vs. local time inconsistency (`Daily_Session_Builder uses gmdate() (UTC) for date targeting, inconsistent with site-local session keys`).

**Impact:** Employees may be auto-terminated one day early or late relative to their expected last working day, depending on the server timezone offset from WP site timezone.

**Fix recommendation:** Use consistent timezone source:
```php
// In handle_submit() (handlers):
$last_working_day = wp_date('Y-m-d', strtotime($resignation_date . ' +' . $notice_period_days . ' days'));
// wp_date() uses WP site timezone

// In cron:
$today = current_time('Y-m-d'); // already uses WP timezone — this is correct
// Fix the handler to also use WP timezone
```

---

### RHDL-LOGIC-003 — Medium — `last_working_day` calculation does not account for weekends or public holidays

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Handlers/class-resignation-handlers.php:54`
**Description:**
```php
$last_working_day = date('Y-m-d', strtotime($resignation_date . ' + ' . $notice_period_days . ' days'));
```
The last working day is calculated as exactly `notice_period_days` calendar days from the resignation date. No adjustment is made for weekends (Friday+Saturday in Saudi Arabia) or public holidays stored in `sfs_hr_holidays`.

For a 30-day notice period, the actual last working day after excluding weekends and public holidays could be 6–8 calendar days later. Employees and managers see a date that does not reflect actual working days.

**Impact:** Last working day date shown in approval emails and the resignation record does not match what HR would calculate based on working days. May cause disputes about actual departure date.

**Fix recommendation:** Use a working-day calculator that respects Saudi weekend (Fri+Sat) and holiday calendar:
```php
$last_working_day = Helpers::add_working_days($resignation_date, $notice_period_days);
// Implement add_working_days() using the holiday option and Fri+Sat exclusion
// (similar to how leave_service calculates business_days)
```

---

### RSVC-LOGIC-001 — Medium — `handle_company_termination()` auto-approves at level 2 instead of marking directly as approved

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Handlers/class-resignation-handlers.php:428–432`
**Description:**
```php
Resignation_Service::update_status($resignation_id, 'approved', [
    'approver_id'    => get_current_user_id(),
    'decided_at'     => current_time('mysql'),
    'approval_level' => 2,
]);
```
When HR creates a company termination, the record is auto-approved with `approval_level=2`. This sets a semantically incorrect state: `approval_level=2` normally means "approved by dept manager, waiting for HR". A completed termination at level 2 would appear as "Pending - HR" in `status_badge()` when `status='approved'` and `approval_level=2`. The combination is confusing.

**Impact:** Company termination records display an incorrect status badge in the resignation list. If the cron queries `status = 'approved'` (which it does), the cron will correctly auto-terminate the employee — so functional behavior is not broken. The issue is UI presentation and data semantics.

**Fix recommendation:** For company terminations, set `approval_level=0` (or a dedicated value like `99`) to indicate HR-direct action:
```php
Resignation_Service::update_status($resignation_id, 'approved', [
    'approver_id'    => get_current_user_id(),
    'decided_at'     => current_time('mysql'),
    'approval_level' => 0, // 0 = company-initiated, bypasses normal chain
]);
```
Update `status_badge()` to handle `approval_level=0` as "Company Initiated".

---

### RCRN-LOGIC-002 — Medium — Cron does not update resignation status to `completed` after auto-termination

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Cron/class-resignation-cron.php:52–73`
**Description:** The cron job correctly updates `e.status = 'terminated'` on the employee record and demotes the WP user role. However, it does NOT update the `sfs_hr_resignations.status` from `approved` to `completed`. The resignation record remains `status='approved'` indefinitely, even after the employee has been terminated.

**Impact:**
1. The resignation list will show auto-terminated employees' resignations under the `approved` tab rather than `completed`
2. The cron will re-query and re-process these resignations on subsequent daily runs (since `WHERE r.status = 'approved' AND e.status = 'active'` — the `e.status` will now be `terminated`, so the cron won't re-terminate, but it will still query the record and iterate over it unnecessarily)
3. Reports and dashboards counting `completed` resignations will undercount

**Fix recommendation:** Add status update in the cron loop:
```php
foreach ($expired_resignations as $resignation) {
    $wpdb->update($emp_table, ['status' => 'terminated', ...], ['id' => $resignation['employee_id']]);
    // Update resignation to completed
    $wpdb->update($resign_table, [
        'status'     => 'completed',
        'updated_at' => current_time('mysql'),
    ], ['id' => $resignation['id']]);
    // ... role demotion, audit log
}
```

---

### RSVC-LOGIC-002 — Low — `can_approve_resignation()` for level 2/3 compares user ID to a single configurable approver — no fallback if option is unset

**Severity:** Low
**Location:** `includes/Modules/Resignation/Services/class-resignation-service.php:218–230`
**Description:**
```php
if ($approval_level === 2) {
    $hr_approver_id = (int)get_option('sfs_hr_resignation_hr_approver', 0);
    if ($hr_approver_id > 0 && $hr_approver_id === $current_user_id) {
        return true;
    }
}
```
If the HR approver option is not configured (returns `0`), no one except `sfs_hr.manage` users can approve at level 2. This means if a resignation advances to level 2 and no HR approver is configured, it becomes permanently stuck — no one can approve it without `sfs_hr.manage`. There is no fallback to allow any `sfs_hr.manage` user to approve at any level.

Wait — `sfs_hr.manage` users are already handled at the top of `can_approve_resignation()` (lines 206–208, returns `true` for manage). So manage users can always approve at any level. The issue is only for orgs where no HR approver is explicitly configured: only `sfs_hr.manage` users can approve at level 2.

The lower risk is: the option allows any user ID (validated by handler only with `int` cast), and there is no expiry — if an HR approver leaves the company and their option is not updated, resignation approvals at level 2 will become blocked for non-manage users.

**Impact:** Low — manage users can always unblock. But user experience is poor if the designated HR approver leaves and the option isn't updated.

**Fix recommendation:** Document this behavior in CLAUDE.md gotchas. Add a settings validation check that warns if HR approver user account has been deactivated or deleted.

---

## $wpdb Call-Accounting Table

### class-resignation-service.php

| Line | Method | Query Type | Prepared | Notes |
|------|--------|------------|----------|-------|
| 34–40 | `get_row($wpdb->prepare(...))` | SELECT JOIN | Yes | `get_resignation()` — correct |
| 85 | `get_var($wpdb->prepare(...))` | SELECT COUNT | Yes (conditional) | Empty-params shortcut skips prepare(); low risk (static query) — see RSVC-SEC-002 |
| 96 | `get_results($wpdb->prepare(...))` | SELECT JOIN LIMIT | Yes | `get_resignations()` with LIMIT/OFFSET — correct |
| 139 | `get_var($wpdb->prepare(...))` | SELECT COUNT | Yes (conditional) | Same dual-path pattern as line 85 |
| 156–170 | `insert()` | INSERT | Yes (via wpdb->insert()) | `create_resignation()` — correct |
| 195 | `update()` | UPDATE | Yes (via wpdb->update()) | `update_status()` — correct |
| 242–246 | `get_results($wpdb->prepare(...))` | SELECT | Yes | `get_manager_dept_ids()` — correct |

**Total service calls: 7 | Prepared: 5 fully, 2 dual-path (safe today) | Unprepared: 0**

### class-resignation-handlers.php

| Line | Method | Query Type | Prepared | Notes |
|------|--------|------------|----------|-------|
| 92–153 | `update()` (via `$wpdb->update()`) | UPDATE | Yes | `handle_approve()` — correct but lacks conditional WHERE (RHDL-LOGIC-001) |
| 207–240 | `update()` (via `$wpdb->update()`) | UPDATE | Yes | `handle_reject()` — same issue |
| 269–300 | `update()` (via `$wpdb->update()`) | UPDATE | Yes | `handle_cancel()` — same issue |
| 308–311 | `get_row($wpdb->prepare(...))` | SELECT | Yes | `handle_cancel()` employee status check — correct |
| 315–318 | `update()` (via `$wpdb->update()`) | UPDATE | Yes | `handle_cancel()` employee reactivation — correct |
| 348 | `update()` (via `$wpdb->update()`) | UPDATE | Yes | `handle_final_exit_update()` — correct |
| 444–447 | `update()` (via `$wpdb->update()`) | UPDATE | Yes | `handle_company_termination()` employee termination — correct |

**Total handler calls: 7 | Prepared: 7 (all via wpdb->update/get_row) | Unprepared: 0**

### class-resignation-shortcodes.php

| Line | Method | Query Type | Prepared | Notes |
|------|--------|------------|----------|-------|
| 101–104 | `get_results($wpdb->prepare(...))` | SELECT | Yes | `render_my_resignations()` scoped to current employee_id — correct |

**Total shortcode calls: 1 | Prepared: 1 | Unprepared: 0**

### class-resignation-cron.php

| Line | Method | Query Type | Prepared | Notes |
|------|--------|------------|----------|-------|
| 37–45 | `get_results($wpdb->prepare(...))` | SELECT JOIN | Yes | `process_expired_resignations()` — correct |
| 53–56 | `update()` (via `$wpdb->update()`) | UPDATE | Yes | Employee termination — correct |

**Total cron calls: 2 | Prepared: 2 | Unprepared: 0**

### class-resignation-notifications.php

| Line | Method | Query Type | Prepared | Notes |
|------|--------|------------|----------|-------|
| 32–35 | `get_row($wpdb->prepare(...))` | SELECT | Yes | Department manager lookup — correct |

**Total notification calls: 1 | Prepared: 1 | Unprepared: 0**

### Overall $wpdb Summary

| File | Total Calls | Fully Prepared | Dual-Path (low risk) | Unprepared |
|------|-------------|----------------|----------------------|------------|
| class-resignation-service.php | 7 | 5 | 2 | 0 |
| class-resignation-handlers.php | 7 | 7 | 0 | 0 |
| class-resignation-shortcodes.php | 1 | 1 | 0 | 0 |
| class-resignation-cron.php | 2 | 2 | 0 | 0 |
| class-resignation-notifications.php | 1 | 1 | 0 | 0 |
| **Total** | **18** | **16** | **2** | **0** |

**Verdict:** No SQL injection vulnerabilities found across all 5 files. The dual-path pattern in `class-resignation-service.php` (lines 85, 139) is a maintenance hazard only.

---

## State Machine Audit

### Transition Map

All transitions are driven by `$wpdb->update()` in the handlers. No conditional `WHERE status = expected` guards exist unless noted.

| From Status | From Level | Action | Handler | To Status | To Level | WHERE Guard | Backwards Possible? |
|-------------|------------|--------|---------|-----------|----------|-------------|---------------------|
| `pending` | 1 | Approve | `handle_approve()` | `pending` | 2 | **No** | N/A |
| `pending` | 2 | Approve (no finance) | `handle_approve()` | `approved` | 2 | **No** | N/A |
| `pending` | 2 | Approve (with finance) | `handle_approve()` | `pending` | 3 | **No** | N/A |
| `pending` | 3 | Approve | `handle_approve()` | `approved` | 3 | **No** | N/A |
| `pending` | 4 | (Final exit path) | `handle_approve()` | `pending` | 4 | **No** | N/A |
| `pending` | any | Reject | `handle_reject()` | `rejected` | unchanged | **No** | N/A |
| `pending`/`approved` | any | Cancel | `handle_cancel()` | `cancelled` | unchanged | **No** | Yes — approved → cancelled |
| `approved` | any | Final exit complete | `handle_final_exit_update()` | `completed` | unchanged | **No** | N/A |
| `approved` | any | Cron expiry | `process_expired_resignations()` | (employee terminated, resignation stays `approved`) | unchanged | N/A | N/A |
| Any | any | Approve (manage user) | `handle_approve()` | (see above) | — | **No** | **Yes** — rejected/approved → can be re-approved |

### Backwards Transition Analysis

**Unauthorized backwards transitions ARE possible due to:**

1. **No conditional UPDATE guard in `handle_approve()`**: An `sfs_hr.manage` user can approve an already-`approved` or `rejected` resignation because `can_approve_resignation()` returns `true` for manage cap without checking current status (RSVC-SEC-001). The UPDATE will succeed and overwrite `status` fields.

2. **`handle_cancel()` accepts any status**: No guard prevents cancelling a `completed` or `rejected` resignation. A `rejected` resignation being "cancelled" (making it `cancelled` from `rejected`) is a semantically backwards transition. A `completed` resignation being cancelled triggers employee reactivation.

3. **Race condition at the same approval level**: Two concurrent approvals at level 1 will both run the full handler and both execute `$wpdb->update()` — the second write wins, but both audit log entries are recorded, making the chain appear to have two level-1 approvals (corrupted history).

**Verdict:** The state machine has no enforced transition graph. Any valid approver (or manage user) can write any status to any resignation. The absence of `WHERE status = expected_status AND approval_level = expected_level` in the UPDATE clause is the root cause.

---

## Self-Submission Audit

### How `handle_submit()` Derives `employee_id`

```php
// class-resignation-handlers.php:39–43
$employee_id = Helpers::current_employee_id();
if (!$employee_id) {
    wp_die(__('No employee record found.', 'sfs-hr'));
}
```

`Helpers::current_employee_id()` is a Core helper that returns the employee record ID for the currently authenticated WordPress user. It does not accept any parameters and does not read from `$_POST` or `$_GET`.

### Can It Be Overridden by Request Parameters?

No. The `handle_submit()` handler does not read `employee_id` from `$_POST` at any point. The only POST parameters read are: `resignation_date`, `resignation_type`, `reason`, and `_wp_http_referer`. The `employee_id` used in `Resignation_Service::create_resignation()` at line 56 is exclusively derived from `Helpers::current_employee_id()`.

The frontend form (`class-resignation-shortcodes.php:44`) does not include a hidden `employee_id` field, confirming the handler intentionally derives it server-side.

### Can a Manager Submit on Behalf?

No. `handle_submit()` has no path that accepts an `employee_id` parameter. A manager who submits via this handler will have their own employee_id resolved (if they are also an employee), or will receive "No employee record found." if they have no employee record.

**The exception is `handle_company_termination()`**: This handler at lines 401–455 accepts `$_POST['employee_id']` directly, creating a resignation for an arbitrary employee. This is guarded by `current_user_can('sfs_hr.manage')` and is an intentional HR-only feature (company-initiated terminations). This is NOT a vulnerability.

### Verdict: SAFE

Resignation submission is correctly enforced as self-only. No request parameter can override the employee_id in `handle_submit()`. The company termination path is a separate, properly-guarded HR action.

---

## Cross-Reference with Prior Phase Findings

| Finding | This Phase | Prior Phase(s) |
|---------|-----------|----------------|
| State machine no conditional WHERE guard | RHDL-LOGIC-001 (High) | Hiring Phase 13 (HADM-LOGIC-001), confirmed in Phase 14 Plan 01 (RADM-LOGIC-002) |
| Missing top-level `current_user_can()` in handlers | RHDL-SEC-002 (High) | Leave Phase 06 (`handle_approve()` missing up-front capability guard), Hiring Phase 13 (HADM-SEC-002) |
| Cross-plugin filter name references | RNTF-DUP-001 (Low) | CLAUDE.md convention violation — unique appearance in this module |
| Tab count N+1 query pattern | RSVC-PERF-001 (High) | Payroll Phase 09, confirmed in Phase 14 Plan 01 (RES-PERF-001) |
| Open redirect via `_wp_http_referer` | RHDL-SEC-001 (High) | Phase 14 Plan 01 (RES-SEC-002 — same finding, now with handler code confirmed) |
| Cron timezone inconsistency (local vs WP) | RCRN-LOGIC-001 (Medium) | Attendance Phase 05 (Daily_Session_Builder UTC vs local) |
| Notification includes full reason text | RNTF-SEC-001 (Medium) | (unique to this module) |
| Missing DB indexes on filter columns | RSVC-PERF-002 (Medium) | Assets Phase 11, Performance Phase 07 (implied) |
| `sfs_hr.manage` check returns true regardless of status | RSVC-SEC-001 (Medium) | Unique pattern — no prior equivalent |
| Cancellation wrong-capability gate | RHDL-SEC-003 (High) | Unique combination — analogous to wrong-status-gate issue |

---

## Key Positive Findings

1. **Self-submission enforcement is correct**: `handle_submit()` derives `employee_id` exclusively from `Helpers::current_employee_id()`. No IDOR risk on resignation submission.

2. **Frontend shortcode data scoping is correct**: `render_my_resignations()` uses `WHERE employee_id = %d` with the current user's employee ID. An employee cannot view another employee's resignations.

3. **No SQL injection vulnerabilities**: All 18 `$wpdb` calls across 5 files use `$wpdb->prepare()` or `$wpdb->insert()`/`$wpdb->update()`. The two dual-path cases are low-risk static queries.

4. **Cron query is correctly bounded**: The daily cron filters by `status='approved'` AND `last_working_day < today` AND `e.status='active'`. It does not load all resignations or all employees.

5. **Notifications do not send full approval chain to employees**: Employee approval/rejection emails include only the outcome and last working day — not the full approval chain history or approver identity (except the rejection reason, which is appropriate).

6. **`handle_final_exit_update()` and `handle_company_termination()` have correct `current_user_can('sfs_hr.manage')` guards**: These high-privilege operations are properly gated.

7. **`ajax_get_resignation()` is properly secured**: Uses `check_ajax_referer()` + capability check before returning any data.

8. **Notification locale switching is implemented**: `send_notification_localized()` correctly switches locale for the recipient's preferred language before building email content.
