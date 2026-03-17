# Phase 14, Plan 01: ResignationModule Orchestrator + Admin Pages Audit Findings

**Date:** 2026-03-16
**Auditor:** GSD execute-phase agent (claude-sonnet-4-6)
**Files audited:** 4 files, ~861 lines

| File | Lines | Role |
|------|-------|------|
| `includes/Modules/Resignation/ResignationModule.php` | 96 | Module bootstrap, backwards-compat delegates |
| `includes/Modules/Resignation/Admin/class-resignation-admin.php` | 25 | Admin menu router (deprecated stub) |
| `includes/Modules/Resignation/Admin/Views/class-resignation-list.php` | 604 | Admin resignation list, modals, action forms |
| `includes/Modules/Resignation/Admin/Views/class-resignation-settings.php` | 136 | Settings form |

**Note on scope:** `ResignationModule.php` has no `install()` method and contains zero DDL — table creation for `sfs_hr_resignations` is handled by `Migrations.php`. The Admin router stub (`class-resignation-admin.php`) is a deprecated empty class; real menu registration moved to `EmployeeExitModule`. This audit therefore covers the view files and their callers for security, performance, duplication, and logic issues.

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 1 | 3 | 1 | 0 | 5 |
| Performance | 0 | 2 | 1 | 1 | 4 |
| Duplication | 0 | 0 | 1 | 2 | 3 |
| Logical | 0 | 2 | 2 | 1 | 5 |
| **Total** | **1** | **7** | **5** | **4** | **17** |

---

## Security Findings

### RES-SEC-001 — Critical — Missing capability guard on Resignation_List::render() page

**Severity:** Critical
**Location:** `includes/Modules/EmployeeExit/Admin/class-employee-exit-admin.php:117` (caller) + `includes/Modules/Resignation/Admin/Views/class-resignation-list.php:18`
**Description:** The menu page `sfs-hr-lifecycle` is registered with capability `sfs_hr.view` (line 39 of `class-employee-exit-admin.php`). When the `resignations` tab is active, `render_resignations_content()` calls `Resignation_List::render()` directly without any capability check. Inside `render()`, the only capability check is:

```php
if (!current_user_can('sfs_hr.manage')) {
    $dept_ids = Resignation_Service::get_manager_dept_ids(get_current_user_id());
}
```

This means any user with `sfs_hr.view` (all employees via the dynamic cap filter) who navigates directly to `admin.php?page=sfs-hr-lifecycle&tab=resignations` will see the full resignation list scoped to their managed departments — **or all resignations if the `get_manager_dept_ids()` call returns an empty array** (e.g., for a non-manager employee who somehow has `sfs_hr.view`). An employee with `sfs_hr.view` but no managed departments receives `$dept_ids = []`, which causes `get_resignations()` to skip the dept filter (line 70–74 of service) and return **all resignations in the org**.

**Impact:** Any employee with `sfs_hr.view` (dynamically granted to all active employees at runtime) who reaches the admin UI can view all resignation records org-wide including reasons, approval chains, and last working days.

**Fix recommendation:**
```php
// In render() at the top, before any data fetch:
if (!current_user_can('sfs_hr.manage') && !current_user_can('sfs_hr.leave.review')) {
    wp_die(__('Access denied', 'sfs-hr'));
}
```
Alternatively, the `render_resignations_content()` caller in `Employee_Exit_Admin` should add:
```php
private function render_resignations_content(): void {
    if (!current_user_can('sfs_hr.manage') && empty(Resignation_Service::get_manager_dept_ids(get_current_user_id()))) {
        wp_die(__('Access denied', 'sfs-hr'));
    }
    Resignation_List::render();
}
```

---

### RES-SEC-002 — High — Unvalidated open redirect via `_wp_http_referer` in handle_submit()

**Severity:** High
**Location:** `includes/Modules/Resignation/Handlers/class-resignation-handlers.php:77`
**Description:**
```php
$redirect_url = isset($_POST['_wp_http_referer']) ? $_POST['_wp_http_referer'] : admin_url('admin.php?page=sfs-hr-my-profile');
Helpers::redirect_with_notice($redirect_url, 'success', ...);
```
`$_POST['_wp_http_referer']` is user-controlled POST data. While WordPress generates a `_wp_http_referer` hidden field with `wp_referer_field()`, nothing prevents a crafted POST from supplying an arbitrary redirect target. If `Helpers::redirect_with_notice()` uses `wp_redirect()` or header-based redirect without sanitization, this is an open redirect.

**Impact:** CSRF-protected phishing: an attacker can construct a form that posts to `admin-post.php?action=sfs_hr_resignation_submit` with a crafted `_wp_http_referer` pointing to an attacker-controlled URL. The nonce check prevents unauthorized submissions, but an authenticated employee submitting their own resignation is redirected off-site on success.

**Fix recommendation:** Replace with `wp_safe_redirect()` and force a safe default fallback:
```php
$redirect_url = isset($_POST['_wp_http_referer'])
    ? wp_validate_redirect(wp_unslash($_POST['_wp_http_referer']), admin_url('admin.php?page=sfs-hr-my-profile'))
    : admin_url('admin.php?page=sfs-hr-my-profile');
wp_safe_redirect($redirect_url);
exit;
```

---

### RADM-SEC-001 — High — Unprepared `$wpdb->get_results()` in Company Termination modal

**Severity:** High
**Location:** `includes/Modules/Resignation/Admin/Views/class-resignation-list.php:475`
**Description:**
```php
$active_emps = $wpdb->get_results("SELECT id, employee_code, first_name, last_name FROM {$emp_table} WHERE status = 'active' ORDER BY first_name, last_name");
```
This query has no user-supplied parameters (status is a hardcoded string literal, table name is `$wpdb->prefix . 'sfs_hr_employees'`), so there is **no SQL injection risk in this specific query**. However, the query bypasses `$wpdb->prepare()` entirely — a static query without parameterization. This violates CLAUDE.md conventions ("All database access uses `$wpdb` directly with `$wpdb->prepare()`") and is flagged as an architectural pattern violation consistent with Phase 09's designation of raw static queries as pattern violations (not injection risks).

Additionally, this inline `global $wpdb` call inside a view file is an architectural boundary violation: query logic in a view rather than a service or controller layer.

**Impact:** No immediate injection risk. Sets a bad precedent; if the WHERE clause is later modified to include a user-controlled variable (e.g., department filter), an injection vulnerability can be introduced without triggering code review, because the prepare() pattern is absent.

**Fix recommendation:** Move to `Resignation_Service::get_active_employees()` and use `$wpdb->get_results()` with a `$wpdb->prepare()` call even for static queries (or use a query with explicit prepare to establish the pattern):
```php
// In service layer:
public static function get_active_employees(): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, employee_code, first_name, last_name FROM {$wpdb->prefix}sfs_hr_employees WHERE status = %s ORDER BY first_name, last_name",
        'active'
    ), ARRAY_A);
}
```

---

### RADM-SEC-002 — High — Modal action buttons shown to all `sfs_hr.view` users (same pattern as Phase 10)

**Severity:** High
**Location:** `includes/Modules/Resignation/Admin/Views/class-resignation-list.php:534–539`
**Description:** The JavaScript `showResignationDetails()` function builds action buttons (Approve, Reject, Cancel) based solely on `tr.dataset.status === 'pending'` from the HTML data attributes:
```javascript
if(tr.dataset.status === 'pending') {
    btns += '<button ... onclick="showApproveModal('+id+');">Approve</button>';
    btns += '<button ... onclick="showRejectModal('+id+');">Reject</button>';
    btns += '<button ... onclick="showCancelModal('+id+');">Cancel</button>';
}
```
There is no PHP-side `current_user_can()` check to gate which users see these modal buttons. Any user with page access who views a `pending` resignation will see Approve/Reject/Cancel buttons. The server-side handlers do validate via `can_approve_resignation()`, so the handlers themselves are protected — but the UI misleadingly presents options to unauthorized users.

This is the same pattern identified in Phase 10 (SADM-SEC-001): action buttons rendered without server-side capability check in view.

**Impact:** Medium confusion/trust harm; server-side is protected. A dept manager from a different department will see Approve/Reject buttons for pending resignations in another department and receive a confusing error when they attempt the action.

**Fix recommendation:** Pass capability flags to the JS context (as the AJAX handler already does with `can_approve`/`can_manage` in the JSON response), or guard the action buttons on the PHP side:
```php
// In render_scripts(), output a PHP capability variable:
var sfsHrCanApprove = <?php echo wp_json_encode(Resignation_Service::can_approve_resignation_any()); ?>;
// Then in showResignationDetails(), check sfsHrCanApprove before rendering buttons.
```

---

### RADM-SEC-003 — Medium — Settings page `ok` notice uses unvalidated GET parameter

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Admin/Views/class-resignation-settings.php:58`
**Description:**
```php
<?php if (!empty($_GET['ok'])): ?>
    <div class="notice notice-success">...</div>
<?php endif; ?>
```
The value of `$_GET['ok']` is checked for truthiness but never used in output, so there is no XSS risk here. However, the pattern can be confused with `$_GET['updated']` WordPress convention and provides a trivially faked success notice — any user who appends `?ok=1` to the settings URL will see a "Settings saved successfully" notice even if no save occurred. The same pattern was used in Loans (Phase 08) and Payroll (Phase 09).

**Impact:** Low direct risk, but misleads admins into thinking settings were saved when visiting crafted URLs. Particularly relevant if an attacker sends a link with `?ok=1` to social-engineer an admin.

**Fix recommendation:** Use a transient-based notice approach (same as `Helpers::redirect_with_notice()` already does in handlers):
```php
// In handle_settings():
wp_safe_redirect(admin_url('admin.php?page=sfs-hr-lifecycle&tab=exit-settings'));
// Use Helpers::redirect_with_notice() instead of manual ?ok=1 query param.
```

---

## Performance Findings

### RES-PERF-001 — High — `get_status_counts()` issues 6 separate COUNT queries per page load

**Severity:** High
**Location:** `includes/Modules/Resignation/Services/class-resignation-service.php:130–140`
**Description:** `get_status_counts()` iterates over 6 status keys and runs one `SELECT COUNT(*)` query per iteration. The list view calls this on every render at line 29 of `class-resignation-list.php`. This is a minimum of 6 queries per page view (12 counting the main list query + total count). Prior phases identified similar tab-count N+1 patterns.

**Impact:** 6 separate round-trips to the database for what could be a single aggregated query.

**Fix recommendation:** Collapse into a single GROUP BY query:
```sql
SELECT status, resignation_type, COUNT(*) as cnt
FROM {$table} r
JOIN {$emp_t} e ON e.id = r.employee_id
WHERE {$where_clause}
GROUP BY status, resignation_type
```
Then distribute counts from the single result set into the `$counts` array in PHP. Handle `final_exit` tab by checking `resignation_type = 'final_exit'` in PHP post-processing.

---

### RES-PERF-002 — High — Inline `$wpdb` query inside view file executes on every modal open

**Severity:** High (architectural)
**Location:** `includes/Modules/Resignation/Admin/Views/class-resignation-list.php:473–483`
**Description:** The Company Termination modal renders a `<select>` element populated by a raw `$wpdb->get_results()` call directly in the view file, loading all active employees on every resignation list page view — even for users who will never interact with the termination modal (e.g., dept managers who do not have `sfs_hr.manage`). The modal is guarded by `current_user_can('sfs_hr.manage')` at line 456, but the data itself is still fetched eagerly.

Wait — on closer inspection, the query at line 473 is **inside** the `<?php if (current_user_can('sfs_hr.manage')): ?>` block at line 456, so it only runs for `sfs_hr.manage` users. This reduces severity but it remains:
- An architectural violation (query in view file)
- Loads all active employees (potentially hundreds) on every resignation list load for managers

**Impact:** No risk for non-manager users. For managers: loads entire active employee set every page view, with no caching or lazy loading (the select could be AJAX-populated on modal open).

**Fix recommendation:** Move employee list fetch to an AJAX call triggered when the termination modal opens, or to a `Resignation_Service::get_active_employees()` method called from the router before rendering. For large orgs, consider a Select2 search-based AJAX select.

---

### RES-PERF-003 — Medium — No DB index validation for `resignation_type` filter

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Services/class-resignation-service.php:64–66`
**Description:** The `final_exit` status tab filters by `r.resignation_type = 'final_exit'`. Whether `resignation_type` is indexed on `sfs_hr_resignations` cannot be confirmed from PHP code alone, but given the pattern in prior phases (no indexes on filter columns), this column likely lacks an index. As the resignation table grows, full-table scans will occur for this filter.

**Impact:** Performance degradation on `final_exit` tab queries as data grows.

**Fix recommendation:** Confirm and add index in Migrations.php:
```php
$wpdb->query("ALTER TABLE {$wpdb->prefix}sfs_hr_resignations ADD INDEX idx_resignation_type (resignation_type)");
```
Use `add_column_if_missing()` pattern or equivalent for idempotent index creation.

---

### RADM-PERF-001 — Low — Inline CSS via `<style>` block (145 lines) on every page render

**Severity:** Low
**Location:** `includes/Modules/Resignation/Admin/Views/class-resignation-list.php:196–347`
**Description:** All CSS (145 lines) is rendered inline via a `<style>` block on every page view. While the `static $done` guard prevents duplicate injection on the same PHP request, styles are not enqueued via `wp_enqueue_style()` and thus cannot be cached by browsers with cache-control headers.

**Impact:** Minor — 145 lines of CSS embedded in every HTML response for this admin page; cannot be browser-cached.

**Fix recommendation:** Extract to `assets/css/resignation-admin.css` and enqueue via `wp_enqueue_style()` in the admin hooks.

---

## Duplication Findings

### RES-DUP-001 — Medium — `status_badge()` and `render_status_pill()` both exist for the same purpose

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Services/class-resignation-service.php:253–311`
**Description:** Two methods (`status_badge()` and `render_status_pill()`) both render a status indicator for resignations. `status_badge()` uses inline `style` attributes; `render_status_pill()` uses CSS class names. Both contain identical conditional logic for `pending` approval level labels (Manager/HR/Finance/Final Exit). The logic is copy-pasted with minor variations.

**Impact:** Any change to the label text for a pending level requires updating both methods.

**Fix recommendation:** Deprecate `status_badge()` (which uses inline styles — harder to override) and standardize on `render_status_pill()`. Add `@deprecated` docblock to `status_badge()`. The backwards-compat delegates in `ResignationModule.php` already use `@deprecated` pattern.

---

### RES-DUP-002 — Low — `ResignationModule` backwards-compat delegates duplicate method signatures

**Severity:** Low
**Location:** `includes/Modules/Resignation/ResignationModule.php:57–95`
**Description:** Five `@deprecated` static methods in `ResignationModule` each delegate exactly one call to `Resignation_Service`. These exist for backwards compatibility. No indication from git history that any external code calls these directly.

**Impact:** Dead code burden; 39 lines of `ResignationModule` are nothing but pass-through wrappers. If callers were audited and found to be exclusively within this plugin (no external plugins), these delegates could be removed.

**Fix recommendation:** Audit call sites. If no external plugin uses these static methods, remove in a future refactor phase. This is informational — no action needed in v1.1 (audit-only milestone).

---

### RADM-DUP-002 — Low — `final_exit` resignation type inline pill duplicated between PHP and JS

**Severity:** Low
**Location:** `class-resignation-list.php:144–151` (PHP pill rendering) and `class-resignation-list.php:526` (JS modal type display)
**Description:** The resignation type labels (Final Exit, Termination, Regular) and their presentation are rendered in two places: once in the PHP table cell (lines 144–151) and once in the JavaScript `showResignationDetails()` function (line 526). If a new resignation type is added, it must be updated in both PHP and JS.

**Impact:** Maintenance risk. Adding `family_emergency` or any new type requires coordinated PHP + JS changes.

**Fix recommendation:** The PHP table already sets `data-type` on the `<tr>` element. The modal JS reads this attribute. Consider rendering the human-readable type label as a `data-type-label` attribute from PHP:
```php
data-type-label="<?php echo esc_attr($type_labels[$type] ?? $type); ?>"
```
Then the JS simply reads `tr.dataset.typeLabel` instead of re-mapping type keys.

---

## Logical Findings

### RADM-LOGIC-001 — High — Empty `$dept_ids` causes full org data leak for non-managers

**Severity:** High
**Location:** `includes/Modules/Resignation/Admin/Views/class-resignation-list.php:24–27`

**Description:**
```php
if (!current_user_can('sfs_hr.manage')) {
    $dept_ids = Resignation_Service::get_manager_dept_ids(get_current_user_id());
}
```
If `current_user_can('sfs_hr.view')` is true (all employees) but `sfs_hr.manage` is false and the user manages zero departments (i.e., they are not a dept manager), `get_manager_dept_ids()` returns `[]`.

In `Resignation_Service::get_resignations()`:
```php
if (!empty($dept_ids)) {
    // dept filter applied
}
// if $dept_ids is empty, no filter is applied — all records returned
```
This means a non-manager employee with `sfs_hr.view` who reaches this page sees ALL resignation records in the organization. Note: this is the same root cause as RES-SEC-001 (the missing outer capability guard), documented separately because the data-scoping bug exists independently and would still be present even if an outer guard were added — if the guard allows dept managers through, an employee who used to be a dept manager but had their department reassigned would still see all data until they no longer have `sfs_hr.view`.

**Fix recommendation:** After the dept_ids calculation, add an access guard:
```php
if (!current_user_can('sfs_hr.manage')) {
    $dept_ids = Resignation_Service::get_manager_dept_ids(get_current_user_id());
    if (empty($dept_ids)) {
        // Not an HR manager and not a dept manager — no access
        echo '<p>' . esc_html__('You do not have permission to view resignations.', 'sfs-hr') . '</p>';
        return;
    }
}
```

---

### RADM-LOGIC-002 — High — handle_approve() has no state-machine guard (TOCTOU)

**Severity:** High
**Location:** `includes/Modules/Resignation/Handlers/class-resignation-handlers.php:88–198`
**Description:** The approval handler:
1. Fetches the resignation
2. Checks `can_approve_resignation()`
3. Updates status/approval_level

Between steps 1–3 there is no atomic lock. Two concurrent approvals (e.g., two HR managers clicking Approve simultaneously) can both pass `can_approve_resignation()` at the same approval level and both execute the UPDATE, resulting in double-advancing the approval level (from 1 to 2 and simultaneously from 1 to 2 again = two updates writing approval_level=2, but the chain array gets two entries).

Additionally, the UPDATE does not include `WHERE status = 'pending' AND approval_level = %d` as a conditional guard. A resignation already at `approved` status could be double-approved by a race.

This is the same pattern as HADM-LOGIC-001 (Phase 13 Hiring): no state-machine transition enforcement.

**Impact:** In a multi-manager scenario, two approvals at the same level can fire simultaneously, corrupting the approval chain JSON and potentially auto-advancing to `approved` status twice.

**Fix recommendation:** Change the UPDATE to be conditional:
```php
$updated = $wpdb->query($wpdb->prepare(
    "UPDATE {$table} SET status = %s, approval_level = %d, approval_chain = %s, updated_at = %s
     WHERE id = %d AND approval_level = %d AND status = 'pending'",
    ...
));
if (!$updated) {
    wp_die(__('This resignation was already processed by another approver.', 'sfs-hr'));
}
```

---

### RES-LOGIC-001 — Medium — `get_status_counts()` final_exit count is not consistent with status filter

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Services/class-resignation-service.php:132–134`
**Description:** The `final_exit` tab counts records WHERE `resignation_type = 'final_exit'` regardless of status. The `final_exit` filter in `get_resignations()` uses the same WHERE clause. However, a `final_exit` resignation can have status `pending`, `approved`, `rejected`, or `completed`. When a user clicks the `final_exit` tab, they see records where `resignation_type = 'final_exit'` — but these records will also appear in the `pending`, `approved`, `completed` tabs (counted twice). The sum of all tab counts will exceed the `all` count.

**Impact:** Tab counts will appear inflated (double-counting final_exit resignations in status tabs). Users may be confused by "All (4)" but "Pending (2) + Final Exit (1) = 3" not adding up.

**Fix recommendation:** Define a clear model: either `final_exit` is a type filter (orthogonal to status) and should be removed from the status tabs system, or `final_exit` should be a distinct status value. Current mixing creates counting inconsistency. Recommend documenting intent and adding a comment explaining the orthogonal tab behavior.

---

### RADM-LOGIC-003 — Medium — Settings form `hr_approver` validation does not confirm selected user has the required capability

**Severity:** Medium
**Location:** `includes/Modules/Resignation/Handlers/class-resignation-handlers.php:470–471`
**Description:**
```php
$hr_approver = isset($_POST['hr_approver']) ? (int)$_POST['hr_approver'] : 0;
update_option('sfs_hr_resignation_hr_approver', (string)$hr_approver);
```
Any user ID can be stored as HR approver without validation that the user actually has `sfs_hr.manage` or any HR capability. The dropdown in the settings view is filtered to `sfs_hr.manage` users, but the handler accepts any integer via POST.

**Impact:** An admin could (accidentally or via crafted POST) assign a regular employee as HR approver. The `can_approve_resignation()` logic at level 2 checks `$hr_approver_id === $current_user_id`, so that employee would gain de-facto approval power for HR-level resignations.

**Fix recommendation:**
```php
$hr_approver = isset($_POST['hr_approver']) ? (int)$_POST['hr_approver'] : 0;
if ($hr_approver > 0 && !user_can($hr_approver, 'sfs_hr.manage')) {
    $hr_approver = 0; // Reject invalid approver
}
update_option('sfs_hr_resignation_hr_approver', (string)$hr_approver);
```

---

### RES-LOGIC-002 — Low — `Resignation_Admin` hooks() is a deprecated no-op but still instantiated

**Severity:** Low
**Location:** `includes/Modules/Resignation/ResignationModule.php:45`
**Description:**
```php
(new Resignation_Admin())->hooks();
```
`Resignation_Admin::hooks()` is a no-op (the method body is two comments). This class is marked `@deprecated` at both the class and method level, and the docblock confirms menu registration moved to `EmployeeExitModule`. However, `ResignationModule::hooks()` still instantiates and calls it on every request.

**Impact:** Negligible performance overhead (one object allocation + empty method call per request). The risk is confusion: a developer reading `ResignationModule::hooks()` sees the admin is initialized there, but the actual admin menu is elsewhere.

**Fix recommendation:** Remove the `(new Resignation_Admin())->hooks();` call from `ResignationModule::hooks()`. Once confirmed no external code depends on this instantiation path.

---

## $wpdb Call-Accounting Table

This table covers the 4 audited files only. Handler and service files are included for completeness since they are invoked from the audit scope.

### ResignationModule.php (0 $wpdb calls)

No direct database calls. All business logic delegated to `Resignation_Service`.

### class-resignation-admin.php (0 $wpdb calls)

No-op stub. No database access.

### class-resignation-list.php

| Line | Method | Query Type | Prepared | Notes |
|------|--------|------------|----------|-------|
| 473–475 | `$wpdb->get_results()` | SELECT | **No** | Static query — no user params, but bypasses prepare() convention; inline in view file; architectural violation |

### class-resignation-settings.php

| Line | Method | Query Type | Prepared | Notes |
|------|--------|------------|----------|-------|
| — | (none) | — | — | No direct $wpdb calls; uses `get_option()` / `get_users()` only |

### class-resignation-service.php (referenced by audited files)

| Line | Method | Query Type | Prepared | Notes |
|------|--------|------------|----------|-------|
| 34–40 | `$wpdb->get_row($wpdb->prepare(...))` | SELECT | Yes | get_resignation() — correct |
| 85 | `$wpdb->get_var($wpdb->prepare(...))` | SELECT | Yes (conditional) | Empty params case skips prepare() — see note |
| 96 | `$wpdb->get_results($wpdb->prepare(...))` | SELECT | Yes | get_resignations() with LIMIT/OFFSET — correct |
| 139 | `$wpdb->get_var($wpdb->prepare(...))` | SELECT | Yes (conditional) | Same empty-params pattern as line 85 |
| 156–170 | `$wpdb->insert()` | INSERT | Yes (via insert()) | Correct |
| 195 | `$wpdb->update()` | UPDATE | Yes (via update()) | Correct |
| 242–246 | `$wpdb->get_results($wpdb->prepare(...))` | SELECT | Yes | get_manager_dept_ids() — correct |

**Note on empty-params pattern (lines 85, 139):**
```php
$total = empty($params) ? (int)$wpdb->get_var($sql_total) : (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params));
```
When `$params` is empty (status='all', no dept filter, no search), `$sql_total` is a static string like `"SELECT COUNT(*) FROM {$table} r JOIN {$emp_t} e ON ... WHERE 1=1"`. This is a static query with no user input — no injection risk. However, the dual-path pattern (raw vs. prepared) is fragile: if future code adds a non-parameterized value into `$where` without adding a corresponding entry to `$params`, the raw path will execute a partially-interpolated query. This is Low risk today but a maintenance hazard.

**Fix recommendation:** Always use prepare() even with empty params by using a sentinel approach or simply always preparing:
```php
// Always prepare; wpdb->prepare() with no substitutions is safe
$total = (int)$wpdb->get_var(empty($params) ? $sql_total : $wpdb->prepare($sql_total, ...$params));
// Or alternatively: always build params, never use empty shortcut
```

---

## Cross-Reference with Prior Phase Findings

| Finding | This Phase | Prior Phase(s) |
|---------|-----------|----------------|
| Bare `ALTER TABLE` DDL in install() | **Not present** — ResignationModule has no install() | Core Phase 04, Loans Phase 08 |
| Unprepared `SHOW TABLES` / `information_schema` | **Not present** | Core Phase 04, Loans Phase 08, Assets Phase 11, Employees Phase 12 |
| Inline $wpdb in view file (no separation) | RADM-SEC-001 (static, safe) | Assets Phase 11 (AVIEW-SEC-001/002) |
| Status action buttons shown without capability check | RADM-SEC-002 | Settlement Phase 10 (SADM-SEC-001) |
| State-machine race / no conditional UPDATE | RADM-LOGIC-002 | Hiring Phase 13 (HADM-LOGIC-001) |
| Tab count N+1 pattern | RES-PERF-001 | Payroll Phase 09 (inline) |
| `?ok=1` success notice pattern | RADM-SEC-003 (Low) | Loans Phase 08 (implied), Payroll Phase 09 |
| All-org data leak via empty dept_ids | RADM-LOGIC-001 + RES-SEC-001 | Assets Phase 11 (AVIEW-LOGIC-001) |
| Dual-status-badge duplication | RES-DUP-001 | (unique to this module) |

---

## Key Positive Findings

1. **No DDL antipatterns**: `ResignationModule.php` has no `install()` method at all. Table DDL is cleanly delegated to `Migrations.php`. This is the correct pattern.

2. **Pagination implemented correctly**: The list view passes `$page`, `20` (per-page), and uses `LIMIT/OFFSET` via `Resignation_Service::get_resignations()`. No unbounded query on the list.

3. **Handlers have correct nonce + capability guards**: All 6 `admin_post_*` handlers in `class-resignation-handlers.php` use `check_admin_referer()` at entry. `handle_approve()`, `handle_reject()`, `handle_cancel()` additionally call `Resignation_Service::can_approve_resignation()`. `handle_final_exit_update()`, `handle_company_termination()`, `handle_settings()` have explicit `current_user_can('sfs_hr.manage')` guards.

4. **All output in views is escaped**: `esc_html()`, `esc_attr()`, `esc_url()` are used consistently throughout `class-resignation-list.php` and `class-resignation-settings.php`. No unescaped `$_GET`/`$_POST` or DB values in HTML.

5. **Department scoping exists**: The service layer supports `$dept_ids` filtering, and the view correctly populates this array for non-HR managers. The scoping is directionally correct — the gap is only when `$dept_ids` is empty (RES-SEC-001, RADM-LOGIC-001).

6. **AJAX handler properly secured**: `ajax_get_resignation()` uses `check_ajax_referer()` and requires `sfs_hr.manage` or `sfs_hr.view`, then returns only structured data (no raw DB rows).

7. **Settings uses correct option key prefixes**: All options are prefixed `sfs_hr_resignation_*` per the CLAUDE.md convention.
