# ShiftSwap Admin Tab and REST Endpoints Audit Findings

**Phase:** 17-shiftswap-audit
**Plan:** 02
**Files audited:**
- `includes/Modules/ShiftSwap/Admin/class-shiftswap-tab.php` (317 lines)
- `includes/Modules/ShiftSwap/Rest/class-shiftswap-rest.php` (60 lines)

**Supporting file reviewed (service layer):**
- `includes/Modules/ShiftSwap/Services/class-shiftswap-service.php` (333 lines)
- `includes/Modules/ShiftSwap/Handlers/class-shiftswap-handlers.php` (227 lines)

**Date:** 2026-03-17
**Auditor:** Automated code review (Claude)

---

## Architectural Clarification

The file named `class-shiftswap-tab.php` in `Admin/` is **not** an admin management interface for HR managers — it is an **employee self-service tab** rendered inside the "My Profile" admin page. It is scoped to the currently logged-in employee's own swap requests. There is no separate dedicated admin management view for shift swaps in this module; manager approval occurs via the Attendance admin tab (handled by `class-shiftswap-handlers.php::handle_manager_approval()`).

This distinction affects the capability-gate analysis: the absence of a `current_user_can()` check at the top of `render()` is by design (it's an employee self-service view, not a management view), but it does have an explicit ownership guard that replaces it.

---

## Security Findings

### SS-ADM-SEC-001 — Low: Missing `is_user_logged_in()` Guard Before Ownership Check

**Severity:** Low
**File:** `includes/Modules/ShiftSwap/Admin/class-shiftswap-tab.php:53`

**Issue:**
The `render()` method checks `(int)$employee->user_id !== $current_user_id` as its sole access control. If `$current_user_id` is 0 (unauthenticated user somehow reaching an admin page), and `$employee->user_id` is also 0 (a theoretical edge case), the check would pass. WordPress admin page access already requires login, so this is effectively mitigated by the admin page gate — but an explicit `is_user_logged_in()` check would be cleaner and defense-in-depth.

**Fix:** Add at the top of `render()`:
```php
if (!is_user_logged_in()) {
    wp_die(esc_html__('You must be logged in.', 'sfs-hr'));
}
```

---

### SS-ADM-SEC-002 — Low: GET Parameter Content Reflected in Notice Without URL Validation

**Severity:** Low
**File:** `includes/Modules/ShiftSwap/Admin/class-shiftswap-tab.php:83-87`

**Issue:**
The `show_messages()` method reads `$_GET['success']` and `$_GET['error']` and echoes them via `esc_html(sanitize_text_field(wp_unslash(...)))`. While `esc_html()` prevents XSS, the pattern echoes any arbitrary string a user or attacker appends to the URL. This is an open-redirect-adjacent pattern: an attacker could craft a URL like `?error=Your+password+has+expired.+Please+re-enter+here:+https://evil.com` and send it to an employee, displaying attacker-controlled text in a WP admin notice.

This is a social engineering / phishing risk, not a direct XSS risk.

**Fix:** Validate that the `success`/`error` values are either a known set of slugs or strip them entirely in favor of `admin_notices` action + transient pattern:
```php
// Use transients instead of GET params
if ($msg = get_transient('sfs_hr_swap_notice_' . get_current_user_id())) {
    delete_transient('sfs_hr_swap_notice_' . get_current_user_id());
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
}
```

---

### SS-SVC-SEC-001 — Medium: Unprepared `$wpdb->get_var()` in `get_pending_count()` (Static — No Injection, but Antipattern)

**Severity:** Medium
**File:** `includes/Modules/ShiftSwap/Services/class-shiftswap-service.php:294`

**Issue:**
```php
return (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM {$table} WHERE status = 'manager_pending'"
);
```

The query uses a hard-coded string literal — no user input is interpolated, so there is no SQL injection risk. However, it skips `$wpdb->prepare()`, violating the plugin's own coding convention (CLAUDE.md: "All database access uses `$wpdb` directly with `$wpdb->prepare()`") and the established pattern used consistently throughout the rest of the service. The same finding was catalogued in Phase 09 (PADM-SEC-001 comment on static unprepared queries).

This is the same architectural antipattern as Phase 09 (`Admin_Pages.php` raw statics), Phase 11 (Assets), and Phase 16 (Documents admin).

**Fix:**
```php
return (int)$wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE status = %s",
    'manager_pending'
));
```

---

### SS-SVC-SEC-002 — Medium: Unprepared `$wpdb->get_results()` in `get_pending_for_managers()` (Static — No Injection, but Antipattern)

**Severity:** Medium
**File:** `includes/Modules/ShiftSwap/Services/class-shiftswap-service.php:306-315`

**Issue:**
```php
return $wpdb->get_results(
    "SELECT sw.*, ... WHERE sw.status = 'manager_pending' ORDER BY sw.created_at DESC"
);
```

Same pattern as SS-SVC-SEC-001 above — static query with no user-controlled variables, but not wrapped in `$wpdb->prepare()`. Called by `ShiftSwap_Rest::get_swaps()` REST endpoint.

**Fix:**
```php
return $wpdb->get_results($wpdb->prepare(
    "SELECT sw.*, ... WHERE sw.status = %s ORDER BY sw.created_at DESC",
    'manager_pending'
));
```

---

### SS-REST-SEC-001 — Medium: REST `/shift-swaps` Returns All-Org Data Without Department Scoping

**Severity:** Medium
**File:** `includes/Modules/ShiftSwap/Rest/class-shiftswap-rest.php:48-51`
**Service:** `includes/Modules/ShiftSwap/Services/class-shiftswap-service.php:301-316`

**Issue:**
The `get_swaps()` REST endpoint calls `ShiftSwap_Service::get_pending_for_managers()` which returns ALL `manager_pending` swaps across the entire organization. The permission callback (`check_manager_permission`) grants access to anyone with `sfs_hr.manage` OR `sfs_hr_attendance_admin`. A department manager who has only `sfs_hr_attendance_admin` for their own department gets all-org swap data.

This is the same scoping vulnerability found across the audit series (Assets Phase 11 AVIEW-LOGIC-001, Resignation Phase 14 RES-SEC-001, Documents Phase 16 DADM-SEC-001).

**Fix:** Add department scoping in `get_pending_for_managers()`. If the caller is not a full `sfs_hr.manage` user, join against the departments they manage:
```php
// Pass optional dept_ids constraint
public static function get_pending_for_managers(array $dept_ids = []): array {
    // ...
    if (!empty($dept_ids)) {
        // join employees to dept_id and filter by $dept_ids
    }
}
```
Or: scope in the REST callback using the department-manager dynamic caps.

---

### SS-HDL-SEC-001 — High: `handle_manager_approval()` Missing Conditional UPDATE Guard (TOCTOU Race)

**Severity:** High
**File:** `includes/Modules/ShiftSwap/Handlers/class-shiftswap-handlers.php:194-198`

**Issue:**
The handler correctly fetches the swap with `get_swap_for_manager()` which checks `WHERE id = %d AND status = 'manager_pending'`. However, the subsequent `update_swap_status()` call uses:
```php
$wpdb->update($table, $data, ['id' => $swap_id])
```
This is an unconditional update — it does not include `status = 'manager_pending'` in the WHERE clause. Between the fetch and the update, a concurrent request (e.g., another manager approving simultaneously) could change the status, and this handler would blindly overwrite it.

This is the same TOCTOU race documented in Resignation Phase 14 (RADM-LOGIC-002), noted in Phase 17 Phase 01 audit as SS-SEC-005.

**Fix:** Change `update_swap_status()` to support a conditional where clause, or use a direct `$wpdb->update()` call with `['id' => $swap_id, 'status' => 'manager_pending']` as the WHERE condition:
```php
$rows = $wpdb->update($table, $data, ['id' => $swap_id, 'status' => 'manager_pending']);
if ($rows === 0) {
    // already processed — abort
}
```

---

### SS-HDL-SEC-002 — Low: Non-Translatable String Concatenation in Redirect Message

**Severity:** Low (correctness/i18n issue, not a security finding strictly)
**File:** `includes/Modules/ShiftSwap/Handlers/class-shiftswap-handlers.php:208`

**Issue:**
```php
wp_safe_redirect(admin_url('admin.php?...&success=' . rawurlencode(__('Swap ' . $decision . 'd.', 'sfs-hr'))));
```

The string `'Swap ' . $decision . 'd.'` is passed to `__()` at runtime. The translation system cannot catalogue or serve a translated version of a dynamically constructed string. For `approve`, this produces `"Swap approved."` and for `reject`, `"Swap rejectd."` (note the typo: `rejectd` instead of `rejected`).

**Fix:** Use separate translated strings:
```php
$success_msg = $decision === 'approve'
    ? __('Swap approved.', 'sfs-hr')
    : __('Swap rejected.', 'sfs-hr');
```

---

## Performance Findings

### SS-ADM-PERF-001 — Low: No Pagination on Incoming/Outgoing Request Lists

**Severity:** Low
**File:** `includes/Modules/ShiftSwap/Services/class-shiftswap-service.php:100-115, 120-137`

**Issue:**
`get_incoming_requests()` has no LIMIT clause — if an employee is a popular swap target, all pending requests load at once. `get_outgoing_requests()` has `LIMIT 20` hardcoded with no pagination UI. For typical use, this is unlikely to be a performance problem (employees rarely have dozens of pending swaps), but unbounded queries have been flagged across the audit series as a consistent pattern.

**Fix:** Add a reasonable LIMIT to `get_incoming_requests()` (e.g., LIMIT 50) and document that older requests are paginated if needed.

---

### SS-SVC-PERF-001 — Low: `execute_swap()` Fetches Target Shift Without LIMIT

**Severity:** Low
**File:** `includes/Modules/ShiftSwap/Services/class-shiftswap-service.php:249-252`

**Issue:**
```php
$target_shift = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$shifts_table} WHERE employee_id = %d AND assign_date = %s",
    $swap->target_id,
    $swap->target_date
));
```

`get_row()` fetches one row, but there is no `LIMIT 1` in the query. If the shift assignments table ever has duplicate entries for the same `(employee_id, assign_date)`, `get_row()` returns only the first row silently. Adding `LIMIT 1` is defensive best practice.

**Fix:** Add `LIMIT 1` to the query.

---

## Duplication Findings

### SS-ADM-DUP-001 — Low: Inline CSS Styles via `render_styles()` Pattern

**Severity:** Low
**File:** `includes/Modules/ShiftSwap/Admin/class-shiftswap-tab.php:93-141`

**Issue:**
The tab inlines a `<style>` block on every render via `render_styles()`. This is duplicated within this file and adds ~45 lines of CSS inline on every profile page load. The plugin uses `wp_enqueue_style()` for most assets, and inline styles via `wp_add_inline_style()` would be the proper pattern.

**Fix:** Move CSS to a dedicated `assets/css/shift-swap-tab.css` file, enqueue it via `wp_enqueue_style()`, or at minimum use `wp_add_inline_style()` with an existing style handle to prevent multiple injections if the hook fires more than once.

---

### SS-ADM-DUP-002 — Low: Duplicate Nonce Form Pattern in `render_incoming_requests()`

**Severity:** Low
**File:** `includes/Modules/ShiftSwap/Admin/class-shiftswap-tab.php:169-183`

**Issue:**
Each incoming request renders two separate `<form>` tags (Accept and Decline), both posting to `admin-post.php` with the same nonce. These could be combined into a single form with two buttons sharing the same nonce, or a shared hidden nonce field with JS-based response differentiation. As-is, this pattern doubles the HTML form count per request item.

This is a minor architectural concern — not a security issue, but it adds HTML weight and complexity.

**Fix:** Combine into a single form with `name="response"` differentiated by button value, reducing from 2 forms to 1 per request row.

---

## Logical Issues

### SS-ADM-LOGIC-001 — Medium: Tab Scoped to My Profile Only — No Management View Exists for Managers

**Severity:** Medium
**File:** `includes/Modules/ShiftSwap/Admin/class-shiftswap-tab.php:31-33`
**Related:** `includes/Modules/ShiftSwap/Rest/class-shiftswap-rest.php`

**Issue:**
The admin tab only renders on `sfs-hr-my-profile` (employee self-service). The REST endpoints expose `GET /shift-swaps` and `GET /shift-swaps/pending-count` for managers, but there is no corresponding admin view that consumes these REST endpoints. The `handle_manager_approval()` handler is registered (`admin_post_sfs_hr_approve_shift_swap`) but the form that submits to it is presumably in the Attendance admin tab — which is outside this module's files and was not audited in this plan.

The REST endpoints for managers effectively have no frontend consumer in this module. If the Attendance admin tab does not render the approval interface, manager approval would be inaccessible via the UI.

**Recommendation:** Verify that the Attendance admin tab (`includes/Modules/Attendance/Admin/`) correctly renders the manager approval interface for `manager_pending` swap requests and properly links to the `sfs_hr_approve_shift_swap` POST handler.

---

### SS-LOGIC-001 — High: `execute_swap()` Not Atomic (No Transaction) — Carried Forward from Phase 17 P01

**Severity:** High
**File:** `includes/Modules/ShiftSwap/Services/class-shiftswap-service.php:256-269`
**Previously documented:** Phase 17-01 findings (SS-LOGIC-001)

**Issue:**
`execute_swap()` performs two `$wpdb->update()` calls without wrapping them in a transaction:
1. Update requester's shift assignment to target's employee_id
2. Update target's shift assignment to requester's employee_id

If step 2 fails (e.g., DB error, connection drop), the swap is partially executed: the requester's shift has been assigned to the target, but the target's shift still belongs to the target. This leaves both employees with overlapping shift ownership and no way to automatically detect or recover.

**Fix:** Wrap both updates in a transaction:
```php
$wpdb->query('START TRANSACTION');
// ... updates ...
if (both updates succeeded) {
    $wpdb->query('COMMIT');
} else {
    $wpdb->query('ROLLBACK');
    throw new \RuntimeException('Swap execution failed');
}
```

---

### SS-LOGIC-002 — High: No Duplicate Swap Request Guard — Carried Forward from Phase 17 P01

**Severity:** High
**File:** `includes/Modules/ShiftSwap/Services/class-shiftswap-service.php:196-218`
**Previously documented:** Phase 17-01 findings (SS-LOGIC-002)

**Issue:**
`create_swap_request()` performs a `$wpdb->insert()` with no prior check for an existing pending swap between the same requester and target for the same shifts/dates. An employee can submit the form multiple times (browser back button, double-click, etc.) and create multiple identical pending swap requests. When a target accepts one, the others remain as `pending` orphans. If the manager approves a second one that reaches `manager_pending` before the first is executed, `execute_swap()` can run twice.

**Fix:** Add a uniqueness check before insert, or add a UNIQUE constraint on `(requester_id, requester_shift_id, target_id, status)` with status = 'pending':
```php
$existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$table} WHERE requester_id = %d AND requester_shift_id = %d AND target_id = %d AND status = 'pending'",
    $data['requester_id'], $data['requester_shift_id'], $data['target_id']
));
if ($existing) {
    return 0; // or throw
}
```

---

### SS-LOGIC-003 — Low: `$req->status` Rendered Directly if Not in `$status_labels` Array

**Severity:** Low
**File:** `includes/Modules/ShiftSwap/Admin/class-shiftswap-tab.php:300`

**Issue:**
```php
echo esc_html($status_labels[$req->status] ?? $req->status);
```

If the status is not found in `$status_labels`, the raw DB status value is rendered. While `esc_html()` prevents XSS, rendering raw DB status values directly to users could expose internal state machine labels (e.g., `manager_pending`) as raw English strings in what should be an Arabic-first UI. This is a minor UX/i18n issue.

**Fix:** Add a fallback translated string:
```php
echo esc_html($status_labels[$req->status] ?? __('Unknown', 'sfs-hr'));
```

---

## $wpdb Call Accounting

### class-shiftswap-tab.php

| # | Line | Method | Query Summary | Prepared? | Notes |
|---|------|--------|---------------|-----------|-------|
| — | —    | —      | No direct `$wpdb` calls | N/A | All DB access delegated to ShiftSwap_Service — clean delegation pattern |

**Total in class-shiftswap-tab.php: 0 direct wpdb calls**

---

### class-shiftswap-rest.php

| # | Line | Method | Query Summary | Prepared? | Notes |
|---|------|--------|---------------|-----------|-------|
| — | —    | —      | No direct `$wpdb` calls | N/A | All DB access delegated to ShiftSwap_Service |

**Total in class-shiftswap-rest.php: 0 direct wpdb calls**

---

### class-shiftswap-service.php (service layer called by both audited files)

| # | Line | Method | Query Summary | Prepared? | Notes |
|---|------|--------|---------------|-----------|-------|
| 1 | 53-56 | `get_current_employee_id()` | SELECT id FROM employees WHERE user_id | YES | Prepared |
| 2 | 67-77 | `get_employee_shifts()` | SELECT shift assigns + LEFT JOIN shifts WHERE employee_id | YES | Prepared |
| 3 | 87-94 | `get_colleagues()` | SELECT employees WHERE dept_id AND status | YES | Prepared |
| 4 | 105-114 | `get_incoming_requests()` | SELECT swaps JOIN employees WHERE target_id AND status=pending | YES | Prepared |
| 5 | 125-136 | `get_outgoing_requests()` | SELECT swaps JOIN employees WHERE requester_id | YES | Prepared |
| 6 | 146-149 | `get_swap()` | SELECT swap WHERE id | YES | Prepared |
| 7 | 159-163 | `get_swap_for_target()` | SELECT swap WHERE id AND target_id AND status=pending | YES | Prepared |
| 8 | 173-177 | `get_swap_for_requester()` | SELECT swap WHERE id AND requester_id AND status=pending | YES | Prepared |
| 9 | 187-190 | `get_swap_for_manager()` | SELECT swap WHERE id AND status=manager_pending | YES | Prepared |
| 10 | 204-217 | `create_swap_request()` | INSERT INTO swaps | YES | Uses `$wpdb->insert()` — safe pattern |
| 11 | 232 | `update_swap_status()` | UPDATE swaps WHERE id | YES | Uses `$wpdb->update()` — safe pattern; TOCTOU gap noted in SS-HDL-SEC-001 |
| 12 | 243-246 | `execute_swap()` | SELECT shift assigns WHERE id (requester) | YES | Prepared |
| 13 | 249-253 | `execute_swap()` | SELECT shift assigns WHERE employee_id AND date (target) | YES | Prepared |
| 14 | 256-260 | `execute_swap()` | UPDATE shift assigns SET employee_id (requester shift) | YES | Uses `$wpdb->update()` — no transaction (SS-LOGIC-001) |
| 15 | 263-268 | `execute_swap()` | UPDATE shift assigns SET employee_id (target shift) | YES | Uses `$wpdb->update()` — no transaction (SS-LOGIC-001) |
| 16 | 279-283 | `validate_shift_ownership()` | SELECT shift assigns WHERE id AND employee_id | YES | Prepared |
| 17 | 293-295 | `get_pending_count()` | SELECT COUNT(*) WHERE status=manager_pending | **RAW** | Static literal — no injection risk but violates prepare convention (SS-SVC-SEC-001) |
| 18 | 306-315 | `get_pending_for_managers()` | SELECT swaps JOIN employees WHERE status=manager_pending | **RAW** | Static literal — no injection risk but violates prepare convention (SS-SVC-SEC-002) |
| 19 | 325-330 | `get_employee_with_email()` | SELECT employee + user_email WHERE id | YES | Prepared |

**Total in class-shiftswap-service.php: 19 wpdb calls — 17 prepared, 2 raw (both static, no injection risk)**

---

## Summary Table

| ID | Severity | Category | File | Finding |
|----|----------|----------|------|---------|
| SS-HDL-SEC-001 | **High** | Security | class-shiftswap-handlers.php:194 | TOCTOU race on manager approval — unconditional UPDATE without status guard |
| SS-LOGIC-001 | **High** | Logical | class-shiftswap-service.php:256 | `execute_swap()` not atomic — no DB transaction wrapping two shift updates |
| SS-LOGIC-002 | **High** | Logical | class-shiftswap-service.php:196 | No duplicate swap request guard — double-submission creates orphan requests |
| SS-REST-SEC-001 | **Medium** | Security | class-shiftswap-rest.php:48 | REST `/shift-swaps` returns all-org data — no department scoping for non-admin managers |
| SS-SVC-SEC-001 | **Medium** | Security | class-shiftswap-service.php:294 | Raw (unprepared) query in `get_pending_count()` — static but violates convention |
| SS-SVC-SEC-002 | **Medium** | Security | class-shiftswap-service.php:306 | Raw (unprepared) query in `get_pending_for_managers()` — static but violates convention |
| SS-ADM-LOGIC-001 | **Medium** | Logical | class-shiftswap-tab.php:31 | No manager admin view in this module — management UI existence unverified |
| SS-ADM-SEC-001 | **Low** | Security | class-shiftswap-tab.php:53 | Missing explicit `is_user_logged_in()` guard (mitigated by WP admin gate) |
| SS-ADM-SEC-002 | **Low** | Security | class-shiftswap-tab.php:83 | GET param content reflected in notices — social engineering/phishing risk |
| SS-HDL-SEC-002 | **Low** | Security | class-shiftswap-handlers.php:208 | String concat in `__()` — untranslatable message + typo (`rejectd`) |
| SS-ADM-PERF-001 | **Low** | Performance | class-shiftswap-service.php:100 | No LIMIT on `get_incoming_requests()` — unbounded for high-traffic targets |
| SS-SVC-PERF-001 | **Low** | Performance | class-shiftswap-service.php:249 | No `LIMIT 1` on target shift fetch in `execute_swap()` |
| SS-ADM-DUP-001 | **Low** | Duplication | class-shiftswap-tab.php:93 | Inline `<style>` block should be enqueued asset |
| SS-ADM-DUP-002 | **Low** | Duplication | class-shiftswap-tab.php:169 | Duplicate form pattern for Accept/Decline — could be one form |
| SS-LOGIC-003 | **Low** | Logical | class-shiftswap-tab.php:300 | Raw DB status fallback renders internal state labels to user |

---

## Severity Counts

| Severity | Count |
|----------|-------|
| Critical | 0 |
| High | 3 |
| Medium | 4 |
| Low | 8 |
| **Total** | **15** |

---

## Positive Findings

- **Zero direct `$wpdb` calls in admin tab or REST files** — All DB access cleanly delegated to ShiftSwap_Service. Best delegation pattern in the audit series.
- **No `__return_true` permission callbacks** — Both REST routes have real `check_manager_permission()` implementations using correct capability checks (`sfs_hr.manage || sfs_hr_attendance_admin`).
- **Employee self-service ownership scoping is SAFE** — `render()` enforces `$employee->user_id === get_current_user_id()` before any rendering; no IDOR risk on the self-service tab.
- **Swap ownership validation in handler is SAFE** — `handle_swap_request()` calls `validate_shift_ownership()` with the current employee_id before creating the swap, preventing IDOR on shift selection.
- **Target ownership validation is SAFE** — `get_swap_for_target()` uses `WHERE id AND target_id AND status=pending` — employees can only respond to swaps targeting them.
- **Requester ownership validation is SAFE** — `get_swap_for_requester()` uses `WHERE id AND requester_id AND status=pending` — employees can only cancel their own swaps.
- **All output in admin tab is properly escaped** — `esc_html()`, `esc_attr()`, `esc_url()` used consistently throughout `class-shiftswap-tab.php`. No raw echo of DB values.
- **Nonces present on all action forms** — All three form actions (`request_shift_swap`, `respond_shift_swap`, `cancel_shift_swap`) include `wp_nonce_field()` with request-specific nonce names.
- **Manager approval handler has capability check** — `handle_manager_approval()` includes `current_user_can('sfs_hr.manage') || current_user_can('sfs_hr_attendance_admin')` check.
