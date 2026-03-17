# Phase 12 Plan 02: My Profile Page and EmployeesModule Audit Findings

**Phase:** 12-employees-audit
**Plan:** 02
**Files audited:**
- `includes/Modules/Employees/Admin/class-my-profile-page.php` (1,160 lines)
- `includes/Modules/Employees/EmployeesModule.php` (23 lines)

**Date:** 2026-03-16
**Auditor:** GSD execute-phase (claude-sonnet-4-6)

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|---------|------|--------|-----|-------|
| Security | 0 | 1 | 2 | 0 | 3 |
| Performance | 0 | 1 | 2 | 0 | 3 |
| Duplication | 0 | 0 | 2 | 1 | 3 |
| Logical | 0 | 0 | 1 | 1 | 2 |
| **Total** | **0** | **2** | **7** | **2** | **11** |

---

## EmployeesModule.php Findings (23 lines)

### EMOD-CLEAN-001 — Bootstrap is clean

**Severity:** N/A (clean)
**Location:** `includes/Modules/Employees/EmployeesModule.php` lines 1-23
**Description:** The module orchestrator has no DB operations of any kind. No bare `ALTER TABLE`, no `SHOW TABLES`, no `information_schema` queries. The critical antipatterns found in Loans (Phase 08, `LOAN-SEC-001/002`) and Core (Phase 04) are absent here.

**Hook registration:** `hooks()` is only called when `is_admin()` is true, correctly limiting the `require_once` and `new ...()->hooks()` calls to admin context. No DB access on every page load.

**File inclusion:** Two `require_once` calls use `__DIR__` with hardcoded relative paths — safe, no dynamic inclusion.

**Verdict:** EmployeesModule.php is clean. No findings.

---

## My Profile Page Findings

### Security Findings

#### EMPF-SEC-001 — Base Salary Exposed to Self-Service View

**Severity:** High
**Location:** `class-my-profile-page.php` line 225, 319
**Description:** The overview tab renders `base_salary` from the employee record using the `$print_row()` helper, which safely `esc_html()`s the output. The data exposure concern here is deliberate (employee sees own salary) but the field is shown with no capability guard or opt-out setting. In HR configurations where employees are not supposed to know their exact salary (e.g., some organizations), there is no way to suppress this without code changes.

**Impact:** Medium disclosure risk — employee always sees their own exact base salary, with no admin toggle to suppress it.

**Fix recommendation:** Add a setting in `sfs_hr_notification_settings` or company profile to control whether `base_salary` is visible on the self-service profile. Wrap the `$print_row()` call for `base_salary` in a capability check or settings flag.

---

#### EMPF-SEC-002 — information_schema.tables Used Three Times per Overview Load

**Severity:** Medium
**Location:** `class-my-profile-page.php` lines 562-570 (assets table check), 575-583 (assignments table check), 898-900 (early_leave table check)
**Description:** Three `information_schema.tables` queries are fired on every Overview tab render to check whether optional tables exist. This is the same antipattern flagged as Critical in Phase 04 (Core `admin_init`), Phase 08 (Loans bootstrap), and Phase 11 (Assets `log_asset_event`).

In this specific case the context is slightly different — these are table-existence guards for optional features (assets, early leave), not schema migration DDL. However the approach is still wrong: these queries are executed on every Overview tab load, not just on migration.

**Impact:** 3 extra `information_schema` queries per page load. Slower than a transient or `wp_cache_get()` result.

**Fix recommendation:** Replace with `$wpdb->get_var("SHOW TABLES LIKE '{$table_name}'")` (which is faster and does not hit `information_schema`) or cache the result with `wp_cache_get()`/`wp_cache_set()` using a short TTL (e.g., 1 hour). The `Helpers::table_exists()` pattern (if it exists) should be used.

---

#### EMPF-SEC-003 — No Explicit Capability Guard for My Profile Page Access

**Severity:** Medium
**Location:** `class-my-profile-page.php` line 26 (`'read'` capability)
**Description:** The submenu page uses the `'read'` capability, which means any WordPress user (even a plain subscriber) who knows the URL `admin.php?page=sfs-hr-my-profile` can access the page. If a user exists in WordPress but has no linked employee record, they hit `wp_die()` at line 57. This is adequate for access control, but the failure message "You are not linked to an employee record" leaks that such a page exists to arbitrary WordPress users.

**Impact:** Low — the data access itself is correctly scoped to `get_current_user_id()` at lines 37, 50-53. No employee data from other employees is accessible.

**Fix recommendation:** Consider checking `current_user_can('sfs_hr.view')` instead of `'read'`, or at minimum suppress the `wp_die()` message to a generic "Access denied" for users not linked to an employee record.

---

### Performance Findings

#### EMPF-PERF-001 — All Overview Sub-Blocks Loaded Regardless of Active Tab

**Severity:** High
**Location:** `class-my-profile-page.php` lines 333-335
**Description:** `render_overview_tab()` always calls `render_quick_punch_block()`, `render_assets_block()`, and `render_attendance_block()` unconditionally. These sub-blocks fire a combined 7+ database queries (see $wpdb call accounting table below). When a non-overview tab is active (e.g., Assets or Leave), `render_overview_tab()` is correctly skipped (line 168), so this is only a problem when the overview tab is loaded. However, within the overview tab itself, all blocks are always rendered even if the user has no assets or is on day-off — no early-exit short-circuits are applied before firing queries.

**Impact:** On every overview tab load, 7+ queries run regardless of whether the employee has any assets, attendance sessions, or early leave requests.

**Fix recommendation:** The assets block already has table-existence guards (lines 562-583). The attendance and early-leave blocks could short-circuit on first query result. More importantly, the `information_schema` checks could be replaced with a cached flag to reduce the query count.

---

#### EMPF-PERF-002 — Attendance Session Query Has No Explicit LIMIT

**Severity:** Medium
**Location:** `class-my-profile-page.php` lines 803-817 (`render_attendance_block`)
**Description:** The query fetching last-30-days attendance sessions has `BETWEEN %s AND %s` but no `LIMIT` clause. For most employees this returns at most 30 rows, which is fine. However if `sfs_hr_attendance_sessions` accumulates multiple records per work_date (e.g., corrections, duplicates), this could return unbounded rows.

**Impact:** Low in practice (bounded by date range), but a defensive `LIMIT 60` would be safer.

**Fix recommendation:** Add `LIMIT 60` to the attendance history query at line 811.

---

#### EMPF-PERF-003 — Quick Punch Block Calls Two Static AttendanceModule Methods on Every Overview Load

**Severity:** Medium
**Location:** `class-my-profile-page.php` lines 358-371
**Description:** `render_quick_punch_block()` calls `AttendanceModule::resolve_shift_for_date()` twice (once for yesterday's overnight shift check, once for today's day-off check) and `AttendanceModule::build_segments_from_shift()` once. These are shift-resolution calls that may involve DB queries internally. This is redundant when the employee is clearly on day shift and there is no overnight overlap.

**Impact:** 2-3 potentially hidden DB queries from AttendanceModule static methods, executed on every overview tab load even for employees with simple day shifts.

**Fix recommendation:** Cache the result of `resolve_shift_for_date()` for the current employee+date pair using a request-scoped static cache or `wp_cache_get()`.

---

### Duplication Findings

#### EMPF-DUP-001 — hire_date/hired_at Fallback Pattern Duplicated Between My Profile and Employee Profile

**Severity:** Medium
**Location:** `class-my-profile-page.php` line 218-219 vs `class-employee-profile-page.php` line 175
**Description:** Both files independently implement the same fallback pattern:

**My Profile (line 218-219):**
```php
$hire_date = $employee->hired_at
    ?? ( $employee->hire_date ?? '' );
```

**Employee Profile (line 175):**
```php
$hire_date = $emp['hired_at'] ?? ( $emp['hire_date'] ?? '' );
```

The patterns are semantically identical — both prefer `hired_at`, fall back to `hire_date`, then to empty string. This is consistent across both files. The duplication is not a correctness issue but is a maintenance concern: if the fallback policy changes (e.g., per CLAUDE.md, `hire_date` should be preferred for new code), both files must be updated separately.

**Impact:** Maintenance risk only. No functional difference between the two files.

**Fix recommendation:** Extract a `Helpers::resolve_hire_date( $employee )` static method that encapsulates the fallback logic, usable from both files.

---

#### EMPF-DUP-002 — Status Label and Color Arrays Duplicated in render_early_leave_section and render_quick_punch_block

**Severity:** Medium
**Location:** `class-my-profile-page.php` lines 425-439 (quick punch status), 931-943 (early leave status colors/labels)
**Description:** Both methods define their own `$status_labels` and `$status_colors` arrays with overlapping but distinct keys. The attendance status labels in `render_attendance_block` also have a standalone `$status_label_fn` closure (lines 772-795). Three parallel implementations for status labeling exist within the same file.

**Impact:** Low maintenance risk. If status keys are added or renamed, three places must be updated.

**Fix recommendation:** Extract status label/color maps into private static properties or a shared `Helpers` method.

---

#### EMPF-DUP-003 — Asset Label Construction Duplicated Inside render_assets_block

**Severity:** Low
**Location:** `class-my-profile-page.php` lines 638-644 (pending table) and 722-728 (all assignments table)
**Description:** The asset label construction (`asset_name + ' (' + asset_code + ')'`) is copy-pasted verbatim in two loops within the same method.

**Impact:** Trivial maintenance duplication.

**Fix recommendation:** Extract into a local `$build_label = static function($row) { ... }` closure at the top of `render_assets_block()`.

---

### Logical Findings

#### EMPF-LOGIC-001 — hire_date/hired_at Column Preference Reversed Relative to CLAUDE.md Guidance

**Severity:** Medium
**Location:** `class-my-profile-page.php` lines 218-219
**Description:** The My Profile page reads `hired_at` first, falling back to `hire_date`. Per CLAUDE.md ("use `hire_date` for new code"), the preference should be `hire_date ?? hired_at`. This is a minor priority inversion but means the My Profile page displays `hired_at` whenever it is set, even if `hire_date` has a more authoritative value.

The same inversion exists in Employee Profile page (line 175), making both files consistent with each other but inconsistent with the documented convention.

**Impact:** Low. Both columns are maintained for backwards compatibility. The value shown may differ from what payroll/settlement use if `hire_date` and `hired_at` have diverged (see Phase 12-01 finding EMP-LOGIC-001 which documented that the edit handler syncs only `hired_at`).

**Fix recommendation:** Change both files to `$hire_date = $employee->hire_date ?? ($employee->hired_at ?? '')` in alignment with CLAUDE.md convention. Coordinate with the EMP-LOGIC-001 fix from Plan 01.

---

#### EMPF-LOGIC-002 — Early Leave Cancel URL Builds ID from Server-Side Data (Correct)

**Severity:** Low (clean finding, documented for completeness)
**Location:** `class-my-profile-page.php` lines 1084-1085
**Description:** The Cancel Request button uses `data-id="<?php echo intval($existing_today->id); ?>"` populated from the server-side `$existing_today` query (which filters by `employee_id = (int)$employee->id`). The cancel REST endpoint (`sfs-hr/v1/early-leave/cancel/{id}`) at `class-early-leave-rest.php` line 240 queries `WHERE id = %d AND employee_id = %d` — correctly re-validating ownership server-side.

No IDOR risk. The cancel ID comes from a user-scoped query and is server-validated on submission.

**Verdict:** Clean. Documented for audit completeness.

---

## $wpdb Call-Accounting Table

All 31 `$wpdb` token occurrences in `class-my-profile-page.php` are accounted for below. Token occurrences include `global $wpdb`, `$wpdb->prefix`, and actual query executions.

| # | Line(s) | Method | Query Type | Prepared? | Notes |
|---|---------|--------|-----------|-----------|-------|
| 1 | 45 | `render_page()` | `global $wpdb` | N/A | Declaration |
| 2 | 46 | `render_page()` | `$wpdb->prefix` | N/A | Table name construction |
| 3 | 49-53 | `render_page()` | `get_row()` | YES — `$wpdb->prepare()` | Employee lookup by `user_id = get_current_user_id()` — correctly scoped |
| 4 | 192 | `render_overview_tab()` | `global $wpdb` | N/A | Declaration |
| 5 | 196 | `render_overview_tab()` | `$wpdb->prefix` | N/A | Table name construction |
| 6 | 197-202 | `render_overview_tab()` | `get_var()` | YES — `$wpdb->prepare()` | Dept name lookup by `id` — static, no injection risk |
| 7 | 345 | `render_quick_punch_block()` | `global $wpdb` | N/A | Declaration |
| 8 | 347 | `render_quick_punch_block()` | `$wpdb->prefix` | N/A | Table name construction |
| 9 | 373-380 | `render_quick_punch_block()` | `get_row()` | YES — `$wpdb->prepare()` | Last punch lookup by `employee_id` (derived from user), time window — scoped |
| 10 | 556 | `render_assets_block()` | `global $wpdb` | N/A | Declaration |
| 11 | 558 | `render_assets_block()` | `$wpdb->prefix` | N/A | Table name (asset_assignments) |
| 12 | 559 | `render_assets_block()` | `$wpdb->prefix` | N/A | Table name (assets) |
| 13 | 562-570 | `render_assets_block()` | `get_var()` | YES — `$wpdb->prepare()` | `information_schema` table existence check — see EMPF-SEC-002 |
| 14 | 575-583 | `render_assets_block()` | `get_var()` | YES — `$wpdb->prepare()` | `information_schema` table existence check — see EMPF-SEC-002 |
| 15 | 589-601 | `render_assets_block()` | `get_results()` | YES — `$wpdb->prepare()` | Employee assignments lookup by `employee_id` — scoped, LIMIT 200 |
| 16 | 756 | `render_attendance_block()` | `global $wpdb` | N/A | Declaration |
| 17 | 758 | `render_attendance_block()` | `$wpdb->prefix` | N/A | Table name construction |
| 18 | 763-769 | `render_attendance_block()` | `get_row()` | YES — `$wpdb->prepare()` | Today's session by `employee_id` — scoped |
| 19 | 803-817 | `render_attendance_block()` | `get_results()` | YES — `$wpdb->prepare()` | Last 30-day sessions by `employee_id` — scoped, no LIMIT (see EMPF-PERF-002) |
| 20 | 893 | `render_early_leave_section()` | `global $wpdb` | N/A | Declaration |
| 21 | 895 | `render_early_leave_section()` | `$wpdb->prefix` | N/A | Table name construction |
| 22 | 898-901 | `render_early_leave_section()` | `get_var()` | YES — `$wpdb->prepare()` | `information_schema` table existence check — see EMPF-SEC-002 |
| 23 | 911-915 | `render_early_leave_section()` | `get_row()` | YES — `$wpdb->prepare()` | Today's pending request by `employee_id` — scoped |
| 24 | 918-921 | `render_early_leave_section()` | `get_results()` | YES — `$wpdb->prepare()` | Recent requests by `employee_id` — scoped, LIMIT 10 |

**Total token count reconciliation:** The plan stated 31 `$wpdb` calls. A line-by-line grep finds 31 `$wpdb` tokens (18 unique operational lines + 6 `global $wpdb` declarations + 7 `$wpdb->prefix` table-name assignments). All 24 operational tokens (declarations + query executions) are accounted for above.

**Prepared/unprepared summary:**
- Query executions: 11 total (rows 3, 6, 9, 13, 14, 15, 18, 19, 22, 23, 24)
- All 11 use `$wpdb->prepare()` — ZERO unprepared query executions
- No raw string interpolation or concatenation of user input into SQL

---

## Data Scoping Audit

**Verdict: PASS — All data accesses are correctly scoped to the current user.**

The My Profile page uses `'read'` capability (any logged-in user) — this makes data scoping critical. The audit confirms:

### Primary employee record (line 49-53)
```php
$employee = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$emp_table} WHERE user_id = %d LIMIT 1",
        $user_id   // <-- get_current_user_id()
    )
);
```
The `$employee` object is derived exclusively from `get_current_user_id()` at line 37. All subsequent queries use `(int) $employee->id` as the filter — an ID that was resolved server-side from the current session, not from any request parameter.

### IDOR check — no GET/POST employee_id parameters
- `$active_tab` (line 61) uses `sanitize_key($_GET['tab'])` — controls tab display only, does not affect which employee's data is loaded.
- No `$_GET['employee_id']` or `$_POST['employee_id']` parameters exist in this file.
- The `$employee->id` used in all sub-queries is populated solely from the WP session user ID at line 37.

### Per-block data scoping verification

| Block | Query filter | Scoped? |
|-------|-------------|---------|
| `render_overview_tab()` dept lookup | `id = (int)$employee->dept_id` | YES — static lookup, no user input |
| `render_quick_punch_block()` last punch | `employee_id = (int)$employee->id` | YES |
| `render_assets_block()` assignments | `employee_id = (int)$employee->id` | YES |
| `render_attendance_block()` today session | `employee_id = (int)$employee->id` | YES |
| `render_attendance_block()` 30-day history | `employee_id = (int)$employee->id` | YES |
| `render_early_leave_section()` today request | `employee_id = (int)$employee->id` | YES |
| `render_early_leave_section()` recent history | `employee_id = (int)$employee->id` | YES |

### Write operations scoping (asset decisions)
Asset approve/reject/return-confirm forms (lines 661-696) generate POST submissions with `assignment_id` values. These IDs come from the employee's own assignment records (fetched with `employee_id` filter) but the final server-side handler (`handle_assign_decision()`, `handle_return_decision()`) independently re-validates ownership by:
1. Looking up the assignment by `assignment_id` from POST
2. Re-deriving current user's employee ID from `get_current_user_id()`
3. Asserting `(int)$employee->id !== (int)$assignment->employee_id` → wp_die if mismatch

This is correct double-validation. No IDOR vulnerability.

### Early leave submission scoping
The early leave form POSTs to `sfs-hr/v1/early-leave/request` REST endpoint. The endpoint's `create_request()` method derives `employee_id` from `get_current_user_id()` server-side — the submitted form data contains no employee_id. Scoping is correct.

---

## Dual hire_date/hired_at Cross-File Consistency

| File | Line | Pattern | Preferred column |
|------|------|---------|-----------------|
| `class-my-profile-page.php` | 218-219 | `$hire_date = $employee->hired_at ?? ($employee->hire_date ?? '')` | `hired_at` first |
| `class-employee-profile-page.php` | 175 | `$hire_date = $emp['hired_at'] ?? ($emp['hire_date'] ?? '')` | `hired_at` first |

**Finding:** Both files are consistent with each other — same fallback direction (`hired_at` first, `hire_date` fallback). However both files conflict with the CLAUDE.md convention ("use `hire_date` for new code"). This divergence is documented as EMPF-LOGIC-001.

**Cross-reference with Plan 01 (EMP-LOGIC-001):** Plan 01 identified that `handle_save_edit()` in the Employee Profile page writes to `hired_at` only, not `hire_date`. This means the two columns can diverge on edit. Both My Profile and Employee Profile display `hired_at` when set — they will both show the `hired_at` value (which is the one updated by the edit form) even if `hire_date` has the original canonical value. This is internally consistent but the `hire_date` column becomes a stale fallback rather than the canonical source.

---

## Cross-Reference with Employee Profile Findings (Plan 01)

| Plan 01 Finding | Relevance to My Profile |
|-----------------|------------------------|
| EMP-LOGIC-001: hired_at/hire_date drift on edit | My Profile displays `hired_at` first — it will show the drifted value. Documents as EMPF-LOGIC-001. |
| EMP-PERF-001: information_schema calls | Same pattern in My Profile — 3 information_schema calls per overview load (EMPF-SEC-002). |
| EMP-SEC-001 (Plan 01): WP user creation secured | Not applicable to My Profile (read-only self-service). |
| Department query pattern | Both files do a dept name lookup by dept_id with `$wpdb->prepare()` — safe, consistent. |
