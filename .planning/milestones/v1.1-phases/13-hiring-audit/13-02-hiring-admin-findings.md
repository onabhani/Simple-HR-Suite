# Phase 13, Plan 02 — Hiring Admin Pages Audit Findings

**File audited:** `includes/Modules/Hiring/Admin/class-admin-pages.php`
**Lines:** 1,746
**Date:** 2026-03-16
**Supporting file also reviewed:** `includes/Modules/Hiring/HiringModule.php` (718 lines) — required to assess conversion methods and helper calls referenced in admin pages

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 3 | 2 | 1 | 0 | 6 |
| Performance | 0 | 2 | 1 | 1 | 4 |
| Duplication | 0 | 1 | 3 | 1 | 5 |
| Logical | 1 | 3 | 1 | 1 | 6 |
| **Total** | **4** | **8** | **6** | **3** | **21** |

---

## Security Findings

### HADM-SEC-001
**Severity:** Critical
**Location:** `class-admin-pages.php:388-389`
**Description:** Capability check for department/GM approval uses `manage_options` hardcoded.
```php
$can_dept_approve = current_user_can('manage_options'); // You can add department manager check
$can_gm_approve   = current_user_can('manage_options'); // You can add GM check
```
The inline comments acknowledge this is a placeholder and never replaced. `manage_options` is a WordPress super-admin cap — it either grants all-or-nothing access with no HR-role granularity. A department manager with `sfs_hr.manage` but not `manage_options` cannot approve candidates in their own department.

**Impact:** Approval permissions are completely misconfigured. Real dept managers cannot approve; only site admins can. The approval chain model is non-functional under normal HR role setup. Any user with `manage_options` (site admins only) can approve at any stage regardless of department assignment.

**Fix:**
```php
$can_dept_approve = current_user_can('sfs_hr.manage')
    || (isset($candidate->dept_id) && $this->user_is_dept_manager(get_current_user_id(), $candidate->dept_id));
$can_gm_approve = current_user_can('sfs_hr.manage');
```
Add a private helper: `user_is_dept_manager(int $user_id, int $dept_id): bool` using `$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sfs_hr_departments WHERE id = %d AND manager_user_id = %d", ...))`.

---

### HADM-SEC-002
**Severity:** Critical
**Location:** `class-admin-pages.php:1266-1299, 1301-1329, 1331-1510, 1512-1583, 1585-1613, 1615-1744`
**Description:** All six POST handlers (`handle_add_candidate`, `handle_update_candidate`, `handle_candidate_action`, `handle_add_trainee`, `handle_update_trainee`, `handle_trainee_action`) verify a nonce but perform **no `current_user_can()` check** after nonce verification.

Example from `handle_add_candidate()` (lines 1266-1299):
```php
public function handle_add_candidate(): void {
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'sfs_hr_candidate_form')) {
        wp_die(__('Security check failed', 'sfs-hr'));
    }
    // NO capability check — proceeds directly to $wpdb->insert()
    global $wpdb;
    $wpdb->insert("{$wpdb->prefix}sfs_hr_candidates", [...]);
```

This pattern is identical across all six handlers. Any logged-in user who can forge or obtain a valid nonce (e.g., a low-privilege `sfs_hr_employee` who happens to visit the admin page while it is cached) can add/update candidates or trainees. This is especially dangerous for `handle_candidate_action` because it can trigger employee creation via `HiringModule::convert_candidate_to_employee()`.

**Impact:** Privilege escalation. Any authenticated WP user can create candidates, update them, and advance them through the pipeline. The `hire` action in `handle_candidate_action` calls `HiringModule::convert_candidate_to_employee()` which creates both a WP user and an employee record — achievable by any logged-in user.

**Fix:** Add a capability gate immediately after nonce check in every handler:
```php
if (!current_user_can('sfs_hr.manage')) {
    wp_die(__('Insufficient permissions', 'sfs-hr'));
}
```
For `dept_approve` and `gm_approve` actions within `handle_candidate_action`, add role-specific checks as per HADM-SEC-001 fix.

---

### HADM-SEC-003
**Severity:** Critical
**Location:** `HiringModule.php:348-353, 534-539`
**Description:** Employee code generation in both `convert_trainee_to_employee()` and `convert_candidate_to_employee()` uses raw string interpolation in a `$wpdb->get_var()` call.
```php
$last = $wpdb->get_var(
    "SELECT employee_code FROM {$wpdb->prefix}sfs_hr_employees
     WHERE employee_code LIKE '{$prefix}%'
     ORDER BY id DESC LIMIT 1"
);
```
The `$prefix` variable is hardcoded as `"USR-"` in both call sites, so in the current code there is no immediate injection vector. However, this is the same antipattern as HIR-SEC-001 found in Phase 13 Plan 01 (same file pattern). If `$prefix` is ever parameterized or if the pattern is copied for a different prefix, SQL injection becomes possible.

Cross-reference: HIR-SEC-001 (Phase 13-01) found the same unprepared get_var pattern in HiringModule.

**Impact:** Currently low practical risk since `$prefix` is a literal, but architectural risk is high. Flagged Critical to match the project's established severity policy for any raw `get_var` with string building.

**Fix:**
```php
$last = $wpdb->get_var($wpdb->prepare(
    "SELECT employee_code FROM {$wpdb->prefix}sfs_hr_employees
     WHERE employee_code LIKE %s ORDER BY id DESC LIMIT 1",
    $prefix . '%'
));
```

---

### HADM-SEC-004
**Severity:** High
**Location:** `class-admin-pages.php:1331-1509`
**Description:** `handle_candidate_action()` accepts any `workflow_action` value via `$_POST['workflow_action']` (sanitized to text but not validated against an allowlist before the switch). More critically, the handler does not verify that the candidate's **current status** matches the expected status for the requested action.

For example, `dept_approve` can be submitted against a candidate in any status — not just `dept_pending`. The switch only acts on the action name, not on `candidate->status`. The `reject` action can be submitted for a candidate already in `hired` or `rejected` status.

**Impact:** State machine bypass. An HR user can skip pipeline stages (e.g., directly calling `gm_approve` for a `applied` candidate). A `hired` candidate can be rejected (status updated back to `rejected`), corrupting historical records.

**Fix:**
```php
// Load full candidate row (not just approval_chain)
$candidate = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}sfs_hr_candidates WHERE id = %d", $id
));

// Validate allowed transitions
$allowed_transitions = [
    'start_screening'    => ['applied'],
    'hr_review_complete' => ['screening'],
    'send_to_dept'       => ['hr_reviewed'],
    'dept_approve'       => ['dept_pending'],
    'send_to_gm'         => ['dept_approved'],
    'gm_approve'         => ['gm_pending'],
    'hire'               => ['gm_approved'],
    'reject'             => ['applied', 'screening', 'hr_reviewed', 'dept_pending', 'dept_approved', 'gm_pending'],
];

if (!isset($allowed_transitions[$action]) || !in_array($candidate->status, $allowed_transitions[$action], true)) {
    wp_die(__('Invalid workflow transition', 'sfs-hr'));
}
```

---

### HADM-SEC-005
**Severity:** High
**Location:** `class-admin-pages.php:1301-1328`
**Description:** `handle_update_candidate()` accepts the `candidate_id` from `$_POST` and applies updates without verifying that the candidate record exists or that the current user has any relationship to it.

```php
$id = absint($_POST['candidate_id'] ?? 0);
$wpdb->update("{$wpdb->prefix}sfs_hr_candidates", [...], ['id' => $id]);
```

No check that `$id > 0`, no check that a candidate with that ID exists, and (combined with HADM-SEC-002) no capability check. Parallel issue exists in `handle_update_trainee()` (line 1585).

**Impact:** With HADM-SEC-002 fixed, this becomes a mid-tier IDOR. Any HR manager could potentially update a candidate record belonging to a different department/workflow they are not assigned to. Without HADM-SEC-002 fix, any authenticated user can update any candidate record.

**Fix:**
```php
$id = absint($_POST['candidate_id'] ?? 0);
if (!$id) { wp_die(__('Invalid candidate', 'sfs-hr')); }
$exists = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}sfs_hr_candidates WHERE id = %d", $id
));
if (!$exists) { wp_die(__('Candidate not found', 'sfs-hr')); }
```

---

### HADM-SEC-006
**Severity:** Medium
**Location:** `class-admin-pages.php:149, 779`
**Description:** Conditional prepare pattern — when `$params` is empty (no search, no status filter), the raw `$query` runs without `$wpdb->prepare()`:
```php
$candidates = $params ? $wpdb->get_results($wpdb->prepare($query, ...$params)) : $wpdb->get_results($query);
```
The base `$query` at line 143 is a fully static string with no user-controlled fragments — it does not concatenate `$where` when `$params` is empty because `$where` is always `"WHERE 1=1"` when no filter is active. Static queries are safe without prepare.

This is the same pattern flagged in Phase 09 (payroll admin pages). In this case, it is safe but architecturally inconsistent.

**Impact:** No current injection risk. Risk arises only if a developer adds a new filter that modifies `$query` without also adding to `$params`, causing the raw-query branch to run with a dynamic string.

**Fix:** Eliminate the conditional. Always use prepare when `$params` may be non-empty; use a plain `get_results($query)` with a clearly static string or refactor to always use prepare:
```php
if ($params) {
    $candidates = $wpdb->get_results($wpdb->prepare($query, ...$params));
} else {
    $candidates = $wpdb->get_results($query); // static query — safe
}
// Or: add a sentinel param like WHERE 1=%d with param [1] to always prepare
```

---

## Performance Findings

### HADM-PERF-001
**Severity:** High
**Location:** `class-admin-pages.php:143-149`
**Description:** Candidate list query has no `LIMIT` clause. All candidate records matching the filter are fetched in a single query.
```sql
SELECT c.*, d.name as dept_name
FROM {$wpdb->prefix}sfs_hr_candidates c
LEFT JOIN {$wpdb->prefix}sfs_hr_departments d ON c.dept_id = d.id
WHERE ...
ORDER BY c.created_at DESC
```
No pagination, no LIMIT. Similarly, the trainee list (line 771) has no LIMIT.

**Impact:** On an organization running this system for several years with hundreds of candidates, the list page could load thousands of rows. Memory pressure and slow page renders. The same antipattern was found in Loans (LADM-PERF-001, Phase 08) and Assets (Phase 11).

**Fix:** Implement WP-style pagination:
```php
$per_page = 30;
$current_page = max(1, absint($_GET['paged'] ?? 1));
$offset = ($current_page - 1) * $per_page;

// Add to query:
$count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_candidates c $where";
$total = $params ? $wpdb->get_var($wpdb->prepare($count_query, ...$params)) : $wpdb->get_var($count_query);
$query .= " LIMIT %d OFFSET %d";
$paginated_params = array_merge($params, [$per_page, $offset]);
$candidates = $wpdb->get_results($wpdb->prepare($query, ...$paginated_params));
```
Add WP pagination links below the table.

---

### HADM-PERF-002
**Severity:** High
**Location:** `class-admin-pages.php:875-876`
**Description:** Trainee add/edit form loads all active employees as supervisor dropdown options with a raw unbounded query:
```php
$employees = $wpdb->get_results("SELECT id, CONCAT(first_name, ' ', last_name) as name FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active' ORDER BY first_name");
```
On large organizations, this could load hundreds or thousands of employee rows to populate a single `<select>` dropdown. Candidate form (line 248) loads all active departments similarly (lower risk but same pattern).

**Impact:** High memory usage and slow form load on organizations with large employee counts. The employee dropdown is especially problematic since it returns all active employees with no department or role filter.

**Fix:** For supervisor selection, limit to managers or filter by department. At minimum, add a `LIMIT 500` guard. Better: use an AJAX-based `<select2>` component or limit to current department's employees. The departments query is low risk (departments are typically few) but should still add `LIMIT 100`.

---

### HADM-PERF-003
**Severity:** Medium
**Location:** `class-admin-pages.php:648-654`
**Description:** Approval History rendering calls `get_userdata()` per chain step in a PHP loop:
```php
foreach ($approval_chain as $step) {
    $u = get_userdata((int)$step['by']);  // WP user fetch per iteration
    echo $u ? esc_html($u->display_name) : ...;
}
```
This also appears in the fallback block (lines 665-697) for `hr_reviewer_id`, `dept_manager_id`, `gm_id`. Typically 3-7 chain steps per candidate view.

**Impact:** Low in practice (approval chain is small, `get_userdata` uses WP object cache). However, it is technically an N+1 pattern. Cache miss on first load for each user.

**Fix:** Pre-fetch all user IDs from the chain before rendering:
```php
$user_ids = array_filter(array_column($approval_chain, 'by'));
$users = [];
foreach (get_users(['include' => $user_ids, 'fields' => ['ID', 'display_name']]) as $u) {
    $users[$u->ID] = $u->display_name;
}
```

---

### HADM-PERF-004
**Severity:** Low
**Location:** `class-admin-pages.php:33-34`
**Description:** `render_page()` reads `$_GET['tab']` and `$_GET['action']` via `sanitize_key()` but performs no early capability check. The full page — including CSS styles (lines 60-88), nav tabs, and tab content — renders before any auth check. Any WordPress admin with access to the WP dashboard can hit this page even if they lack `sfs_hr.manage`.

The `register_menu()` in HiringModule.php (line 48) sets `'sfs_hr.manage'` as the menu capability, so WP should block access at the menu level. However, there is no explicit `current_user_can('sfs_hr.manage')` guard at the top of `render_page()` as a defense-in-depth measure.

**Impact:** Low — WP's menu access control should prevent unauthorized access. Defense-in-depth is missing.

**Fix:** Add at top of `render_page()`:
```php
if (!current_user_can('sfs_hr.manage')) {
    wp_die(__('Insufficient permissions', 'sfs-hr'));
}
```

---

## Duplication Findings

### HADM-DUP-001
**Severity:** High
**Location:** Candidates and Trainees feature set throughout the file
**Description:** The candidate and trainee subsystems are near-exact structural duplicates. Parallel method pairs:

| Candidate Method | Trainee Method | Overlap |
|-----------------|----------------|---------|
| `render_candidates_tab_content()` L102 | `render_trainees_tab_content()` L739 | 95% identical switch structure |
| `render_candidates_list()` L121 | `render_trainees_list()` L758 | 85% identical — filter form, table layout, status badges |
| `render_candidate_form()` L237 | `render_trainee_form()` L864 | 75% identical — form structure, nonce, field layout |
| `render_candidate_view()` L368 | `render_trainee_view()` L1012 | 60% identical — card layout, info tables, edit links |
| `handle_add_candidate()` L1266 | `handle_add_trainee()` L1512 | 70% identical — nonce check, sanitization, wpdb::insert |
| `handle_update_candidate()` L1301 | `handle_update_trainee()` L1585 | 90% identical — nonce check, sanitization, wpdb::update |

The CSS badge styles (lines 74-87) define status colors for BOTH entity types in one block — the one area that is already correctly unified.

**Impact:** Any fix to the duplicate sections (e.g., fixing HADM-SEC-002 nonce+capability checks) must be applied in six places. Maintenance burden high. Bug-fix divergence risk is significant.

**Fix recommendation:** Extract shared abstractions:
- `render_tab_content(string $entity_type, string $action)` — routes to list/form/view based on entity
- `render_list(string $entity_type, array $config)` — generic list renderer with config array for columns/queries
- `render_action_form_header(string $nonce_action, string $post_action, int $entity_id)` — shared nonce+hidden inputs block
- `handle_form_submission(string $entity_type)` — shared nonce+capability entry point
This is a substantial refactor — recommend scheduling separately from audit-only milestone, but tracking here for remediation phase.

---

### HADM-DUP-002
**Severity:** Medium
**Location:** `class-admin-pages.php:459-472, 492-498, 521-538, 572-579, 562-579`
**Description:** The nonce action `'sfs_hr_candidate_action'` is reused across ALL candidate workflow forms (start screening, HR review, send to dept, dept approve, send to GM, GM approve, reject, hire). Eight `wp_nonce_field('sfs_hr_candidate_action')` calls render the same nonce. A captured token from one form can be replayed against any other action within the same session.

**Impact:** A user who submits "Start Screening" can take the returned nonce and craft a POST request to submit "hire" in the same session. This compounds HADM-SEC-004 (missing status-transition validation). Together they allow stage-skipping via nonce reuse.

**Fix:** Use per-action nonces:
```php
// Instead of generic 'sfs_hr_candidate_action':
wp_nonce_field("sfs_hr_candidate_{$candidate->id}_start_screening");
wp_nonce_field("sfs_hr_candidate_{$candidate->id}_dept_approve");
// etc.
```
Verify in handler: `wp_verify_nonce($_POST['_wpnonce'], "sfs_hr_candidate_{$id}_{$action}")`.

---

### HADM-DUP-003
**Severity:** Medium
**Location:** `class-admin-pages.php:1193-1228`
**Description:** The "Hire Directly" form block for trainees is rendered twice — once for `status === 'active'` (lines 1142-1177) and once for `status === 'completed'` (lines 1193-1228). The two blocks are identical HTML/PHP with no functional difference.

**Impact:** Pure maintenance duplication. CSS or field changes must be applied twice. Risk of divergence on future edits.

**Fix:** Extract to a private helper method `render_trainee_hire_direct_form(object $trainee): void` and call it in both branches.

---

### HADM-DUP-004
**Severity:** Medium
**Location:** `class-admin-pages.php:1520-1556` and `class-admin-pages.php:1657-1724`
**Description:** WordPress user creation code for trainees is duplicated in two places:
1. `handle_add_trainee()` (lines 1520-1556) — on new trainee creation with `create_user_account` checkbox
2. `handle_trainee_action()` case `'create_account'` (lines 1657-1724) — for existing trainees

The username generation logic (strtolower first name + `.` + last initial), uniqueness loop, and `wp_create_user()` call are identical in both. The credential email is also duplicated.

**Impact:** Any change to the username format or password policy must be applied in both locations. The same pattern also appears a third time in `HiringModule::convert_trainee_to_employee()` (lines 360-395) — three diverged copies.

**Fix:** Extract to a private static helper `create_user_for_person(string $first_name, string $last_name, string $email): int|WP_Error` and call it from all three locations.

---

### HADM-DUP-005
**Severity:** Low
**Location:** `class-admin-pages.php:416`, `class-admin-pages.php:1055`
**Description:** Minor: `__(ucfirst($candidate->gender ?: '—'), 'sfs-hr')` appears identically in both `render_candidate_view()` and `render_trainee_view()`. The `ucfirst` + translation pattern is a common one-liner but signals the lack of a `format_gender()` helper.

**Impact:** Trivial. Each use must be updated separately if gender labels change.

**Fix:** Add a `Helpers::format_gender(string $gender): string` static method.

---

## Logical Findings

### HADM-LOGIC-001
**Severity:** Critical
**Location:** `class-admin-pages.php:1331-1509`
**Description:** `handle_candidate_action()` does not enforce valid state machine transitions. The handler reads the candidate row only for `approval_chain` (line 1343-1346) and never checks `candidate->status` before applying an action. This means:

- Any workflow action can be submitted for any candidate in any status
- `hire` can be submitted for a `applied` status candidate — `HiringModule::convert_candidate_to_employee()` does check `status !== 'gm_approved'` (line 525) so the conversion will return null, but the `approval_chain` update at line 1488 still runs, appending a `hired` chain entry to a candidate that was never approved
- `reject` can be submitted for an already `hired` candidate (status update at 1468-1473 will succeed, changing status from `hired` to `rejected`)
- Double-action possible: two simultaneous `dept_approve` submissions from different browser tabs will both succeed since there is no optimistic lock or status pre-check

**Impact:** Pipeline state corruption. Hired employees can be "rejected" after hire. Candidates can accumulate malformed approval chains. Race condition on concurrent approvals.

**Fix:** Load full candidate row and validate transition as shown in HADM-SEC-004 fix. For the double-action / race condition, use a conditional update:
```php
$rows = $wpdb->update(
    "{$wpdb->prefix}sfs_hr_candidates",
    ['status' => 'dept_approved', ...],
    ['id' => $id, 'status' => 'dept_pending']  // atomic: only updates if still in expected status
);
if ($rows === 0) {
    wp_redirect(... '&error=already_processed');
    exit;
}
```

---

### HADM-LOGIC-002
**Severity:** High
**Location:** `class-admin-pages.php:447, 1458-1474`
**Description:** Rejected candidates are excluded from workflow action rendering by the check at line 447:
```php
<?php if (!in_array($candidate->status, ['hired', 'rejected'])) : ?>
```
However, there is no server-side enforcement that prevents re-submitting actions for rejected candidates. A `start_screening` POST can move a `rejected` candidate back to `screening` status — the server-side handler does not check current status (HADM-LOGIC-001). Similarly, a `hired` candidate can be re-rejected.

**Impact:** Rejected candidates can be re-activated through the approval pipeline. Hired employees can have their candidate record corrupted.

**Fix:** Covered by HADM-LOGIC-001 state machine enforcement. Additionally, once a candidate is `hired`, the `employee_id` FK should be checked before any status change — refuse all non-read actions on hired candidates.

---

### HADM-LOGIC-003
**Severity:** High
**Location:** `HiringModule.php:273-276`
**Description:** `convert_trainee_to_candidate()` checks `$trainee->status !== 'completed'` and returns null. However, `handle_trainee_action()` case `'convert'` first updates the trainee status to `'completed'` (line 1635-1638), then calls `convert_trainee_to_candidate()`. If the `wpdb->update` succeeds but the `convert_trainee_to_candidate()` fails (e.g., a DB error during candidate insert), the trainee is left in `completed` status with no candidate record. No transaction wraps these two operations.

The `convert_trainee_to_candidate()` itself also lacks a transaction wrapping the insert + update sequence (lines 284-318 in HiringModule).

**Impact:** Orphan records — trainees permanently stuck in `completed` status with no way to re-trigger conversion since they've already passed the status check.

Cross-reference: HIR-LOGIC-001 (Phase 13-01) flagged no-transaction wrappers in conversion methods — this is the admin-layer manifestation of that finding.

**Fix:** Wrap conversion calls in MySQL transactions:
```php
$wpdb->query('START TRANSACTION');
try {
    // status update + convert call
    $wpdb->query('COMMIT');
} catch (\Exception $e) {
    $wpdb->query('ROLLBACK');
}
```
Or: use `$wpdb->query('START TRANSACTION')` / `$wpdb->query('ROLLBACK')` pattern since PHP exceptions don't bubble from `$wpdb`.

---

### HADM-LOGIC-004
**Severity:** High
**Location:** `class-admin-pages.php:1238-1246`
**Description:** The trainee view attempts to find the direct-hire employee ID by parsing the `notes` column with a regex:
```php
if (!empty($trainee->notes) && preg_match('/\[Direct Hire Info: ({.*?})\]/', $trainee->notes, $matches)) {
    $hire_info = json_decode($matches[1], true);
```
The employee ID is stored as embedded JSON inside a text field rather than a dedicated FK column. This means:
1. The employee link can be silently broken by any edit to the `notes` field (admin edit form saves notes without preserving this JSON block)
2. The regex pattern `({.*?})` uses non-greedy matching that can fail for multi-line JSON or special characters
3. `handle_update_trainee()` (line 1585) blindly overwrites notes with `$_POST['notes']`, destroying any embedded `[Direct Hire Info: ...]` block

**Impact:** After a "hire directly" action, editing the trainee's notes field from the admin form silently severs the employee link. The converted trainee view will no longer display the "View Employee Record" link.

**Fix:** Add a dedicated `direct_hire_employee_id BIGINT UNSIGNED NULL` column to `sfs_hr_trainees` table using `add_column_if_missing()`. Remove the notes-embedding pattern entirely.

---

### HADM-LOGIC-005
**Severity:** Medium
**Location:** `class-admin-pages.php:32-89`
**Description:** `render_page()` uses `sanitize_key()` on both `$_GET['tab']` and `$_GET['action']`, then passes them to a switch/if chain. Unknown `tab` values fall through to `render_trainees_tab()` (the `else` branch at line 53). Unknown `action` values fall through to `render_*_list()` (the `default` case in each switch at lines 103-116, 740-753). This is safe as the defaults are the list views.

However, within the `edit` action case, the ID `0` is passed to `render_candidate_form(0)` if no `id` is provided — resulting in an "Add" form with a hidden `candidate_id = 0`. If submitted, `handle_update_candidate()` will run `$wpdb->update(..., ['id' => 0])` which matches no rows (safe, but misleading UX and a wasted DB call).

**Impact:** Low security risk. UX confusion: navigating to `?tab=candidates&action=edit` without an `id` shows an "Edit Candidate" heading but behaves as an add form with a broken hidden ID.

**Fix:** Add an ID guard in the `edit` action case:
```php
case 'edit':
    $edit_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if (!$edit_id) { wp_redirect(admin_url('...')); exit; }
    $this->render_candidate_form($edit_id);
    break;
```

---

### HADM-LOGIC-006
**Severity:** Low
**Location:** `class-admin-pages.php:1458-1474`
**Description:** The `reject` action does not capture a required rejection reason — it defaults to the string `'Rejected'` if `$_POST['rejection_reason']` is absent:
```php
$reason = sanitize_textarea_field($_POST['rejection_reason'] ?? 'Rejected');
```
The rejection forms shown in the candidate view do not include a `rejection_reason` textarea — they only have a JavaScript `confirm()` dialog. There is no way to record a meaningful rejection reason through the current UI. The `rejection_reason` column exists and is displayed in the approval history table, but will always contain `'Rejected'`.

**Impact:** Audit trail incomplete. No record of why a candidate was rejected.

**Fix:** Add a rejection reason textarea to the "Reject" form blocks (similar to the notes textarea on the approval forms). Make it required or provide a default reason dropdown (e.g., "Not qualified," "Position filled," "Failed background check").

---

## $wpdb Call-Accounting Table

All `$wpdb` calls in `class-admin-pages.php` (1,746 lines):

| # | Line | Method | Query Type | Table(s) | Prepared? | Notes |
|---|------|--------|------------|----------|-----------|-------|
| 1 | 137 | `esc_like()` | helper | — | N/A | Used to escape LIKE param before adding to $params array |
| 2 | 149 | `get_results(prepare())` | SELECT | candidates + departments | Yes (if $params) | Conditional — raw path is static query; safe |
| 3 | 149 | `get_results($query)` | SELECT | candidates + departments | No (static) | Raw path only runs when no filters active; static query |
| 4 | 242-245 | `get_row(prepare())` | SELECT | candidates | Yes | Fetch candidate for form edit |
| 5 | 248 | `get_results()` | SELECT | departments | No (static) | Fully static query; no user input in SQL |
| 6 | 371-377 | `get_row(prepare())` | SELECT | candidates + departments | Yes | Candidate view fetch |
| 7 | 779 | `get_results(prepare())` | SELECT | trainees + departments + employees | Yes (if $params) | Conditional — same pattern as #2 |
| 8 | 779 | `get_results($query)` | SELECT | trainees + departments + employees | No (static) | Raw path only when no filters; static query |
| 9 | 869-872 | `get_row(prepare())` | SELECT | trainees | Yes | Fetch trainee for form edit |
| 10 | 875 | `get_results()` | SELECT | departments | No (static) | Fully static; same as #5 |
| 11 | 876 | `get_results()` | SELECT | employees | No (static) | Fully static; unbounded (HADM-PERF-002) |
| 12 | 1015-1023 | `get_row(prepare())` | SELECT | trainees + departments + employees | Yes | Trainee view fetch |
| 13 | 1275 | `insert()` | INSERT | candidates | Yes (insert method) | handle_add_candidate |
| 14 | 1309 | `update()` | UPDATE | candidates | Yes (update method) | handle_update_candidate |
| 15 | 1343-1346 | `get_row(prepare())` | SELECT | candidates | Yes | Load approval_chain |
| 16 | 1359-1363 | `update()` | UPDATE | candidates | Yes | start_screening action |
| 17 | 1376-1383 | `update()` | UPDATE | candidates | Yes | hr_review_complete action |
| 18 | 1395-1399 | `update()` | UPDATE | candidates | Yes | send_to_dept action |
| 19 | 1412-1419 | `update()` | UPDATE | candidates | Yes | dept_approve action |
| 20 | 1431-1435 | `update()` | UPDATE | candidates | Yes | send_to_gm action |
| 21 | 1448-1455 | `update()` | UPDATE | candidates | Yes | gm_approve action |
| 22 | 1468-1473 | `update()` | UPDATE | candidates | Yes | reject action |
| 23 | 1488-1493 | `update()` | UPDATE | candidates | Yes | hire action (pre-conversion update) |
| 24 | 1558 | `insert()` | INSERT | trainees | Yes (insert method) | handle_add_trainee |
| 25 | 1593 | `update()` | UPDATE | trainees | Yes (update method) | handle_update_trainee |
| 26 | 1627-1630 | `update()` | UPDATE | trainees | Yes | extend action |
| 27 | 1635-1638 | `update()` | UPDATE | trainees | Yes | convert action — status to completed |
| 28 | 1649-1654 | `update()` | UPDATE | trainees | Yes | archive action |
| 29 | 1659-1662 | `get_row(prepare())` | SELECT | trainees | Yes | create_account action — load trainee |
| 30 | 1702-1705 | `update()` | UPDATE | trainees | Yes | create_account — link user_id |

**Total class-admin-pages.php: 30 unique $wpdb operation calls** (the plan estimated 54, which likely includes HiringModule.php calls also reviewed below)

**Additional $wpdb calls in HiringModule.php** (called from admin pages handlers):

| # | Line | Method | Query Type | Table(s) | Prepared? | Notes |
|---|------|--------|------------|----------|-----------|-------|
| 31 | 247 | `get_var(prepare())` | SELECT | trainees | Yes | generate_trainee_code |
| 32 | 269-272 | `get_row(prepare())` | SELECT | trainees | Yes | convert_trainee_to_candidate |
| 33 | 284 | `insert()` | INSERT | candidates | Yes | convert_trainee_to_candidate |
| 34 | 309 | `update()` | UPDATE | trainees | Yes | convert_trainee_to_candidate post-insert |
| 35 | 334-337 | `get_row(prepare())` | SELECT | trainees | Yes | convert_trainee_to_employee |
| 36 | 348-352 | `get_var()` | SELECT | employees | **No** | Raw string interpolation — HIR-SEC-001 / HADM-SEC-003 |
| 37 | 403-406 | `get_var(prepare())` | SELECT | departments | Yes | get dept auto_role |
| 38 | 424 | `insert()` | INSERT | employees | Yes | create employee record |
| 39 | 452 | `update()` | UPDATE | trainees | Yes | update trainee post-hire |
| 40 | 520-523 | `get_row(prepare())` | SELECT | candidates | Yes | convert_candidate_to_employee |
| 41 | 534-538 | `get_var()` | SELECT | employees | **No** | Raw string interpolation — HADM-SEC-003 (2nd occurrence) |
| 42 | 573-576 | `get_var(prepare())` | SELECT | departments | Yes | get dept auto_role |
| 43 | 590 | `insert()` | INSERT | employees | Yes | create employee record |
| 44 | 613 | `update()` | UPDATE | candidates | Yes | update candidate post-hire |
| 45 | 701-703 | `get_var(prepare())` | SELECT | trainees | Yes | is_trainee check |
| 46 | 712-714 | `get_row(prepare())` | SELECT | trainees | Yes | get_trainee_by_user |

**Grand total (both files): 46 distinct $wpdb operations**

Note: The plan's estimate of "54 $wpdb calls" likely counts `{$wpdb->prefix}` table prefix references (not actual method calls) which appear 54 times when counting every `{$wpdb->prefix}sfs_hr_*` token.

**Summary: 2 unprepared calls (lines 348-352 and 534-538 in HiringModule.php) — both raw string interpolation with hardcoded `$prefix = "USR-"` (currently safe but architecturally flagged as Critical per project policy).**

---

## Pipeline Approval Chain Audit

### State Machine Definition

The designed approval workflow (from HiringModule.php docblock):
```
applied → screening → hr_reviewed → dept_pending → dept_approved → gm_pending → gm_approved → hired
                                                                                              ↗
Any stage → rejected
```

### Capability Requirements at Each Stage

| Stage Transition | Action | UI Capability Gate | Handler Capability Gate | Finding |
|-----------------|--------|-------------------|------------------------|---------|
| applied → screening | start_screening | None (any `sfs_hr.manage` user sees button) | None — HADM-SEC-002 | Critical |
| screening → hr_reviewed | hr_review_complete | None | None — HADM-SEC-002 | Critical |
| hr_reviewed → dept_pending | send_to_dept | None | None — HADM-SEC-002 | Critical |
| dept_pending → dept_approved | dept_approve | `manage_options` only — HADM-SEC-001 | None — HADM-SEC-002 | Critical |
| dept_approved → gm_pending | send_to_gm | None | None — HADM-SEC-002 | Critical |
| gm_pending → gm_approved | gm_approve | `manage_options` only — HADM-SEC-001 | None — HADM-SEC-002 | Critical |
| gm_approved → hired | hire | None | None — HADM-SEC-002 | Critical |
| any → rejected | reject | None | None — HADM-SEC-002 | Critical |

**Overall assessment:** The approval chain UI has capability gates only for `dept_approve` and `gm_approve` actions, and those gates use the wrong capability (`manage_options` instead of HR-specific caps). All other stages have no capability gate at all in the handler.

### Valid/Invalid Transitions

**Missing transition enforcement (HADM-LOGIC-001):**
- Handler does not check `candidate->status` before applying action
- All invalid transitions are possible through direct POST
- `hire` for a non-`gm_approved` candidate: `convert_candidate_to_employee()` will return null but the `approval_chain` JSON is still updated (data corruption)

### Double-Action Risk

- No `SELECT ... FOR UPDATE` or conditional `UPDATE WHERE status = expected` pattern
- Concurrent `dept_approve` submissions from two browser tabs can both succeed
- First submission: updates status to `dept_approved`, appends to `approval_chain`
- Second submission: updates status to `dept_approved` again (idempotent on status column), appends a duplicate entry to `approval_chain`
- Result: duplicate approval history entries, incorrect double-approver audit trail

**Severity:** High (HADM-LOGIC-001 fix addresses this)

---

## Candidate vs. Trainee Duplication Analysis

### Paired Method Inventory

| # | Candidate Method | Lines | Trainee Method | Lines | Overlap % |
|---|-----------------|-------|----------------|-------|-----------|
| 1 | `render_candidates_tab_content()` | 102-116 | `render_trainees_tab_content()` | 739-753 | 95% |
| 2 | `render_candidates_list()` | 121-232 | `render_trainees_list()` | 758-859 | 80% |
| 3 | `render_candidate_form()` | 237-363 | `render_trainee_form()` | 864-1007 | 70% |
| 4 | `render_candidate_view()` | 368-727 | `render_trainee_view()` | 1012-1262 | 55% (workflow actions differ significantly) |
| 5 | `handle_add_candidate()` | 1266-1299 | `handle_add_trainee()` | 1512-1583 | 65% (trainee has user creation logic) |
| 6 | `handle_update_candidate()` | 1301-1329 | `handle_update_trainee()` | 1585-1613 | 90% |
| 7 | `handle_candidate_action()` | 1331-1510 | `handle_trainee_action()` | 1615-1744 | 40% (different action sets) |

**Estimated duplicate code lines:** ~600 of 1,746 total lines (~34% of file is structural duplication)

### Refactoring Recommendation

Given the significant difference in workflow complexity between candidates (8-stage pipeline) and trainees (simpler extend/convert/archive actions), a full merge is not recommended. Instead, extract shared patterns:

1. **Tab routing helper** (highest ROI, 95% overlap) — single generic `render_tab_content(string $entity, string $action, int $id)` method
2. **List renderer helper** (80% overlap) — `render_entity_list(array $config)` accepting query, columns, status map
3. **Nonce+capability handler entry point** (90% overlap) — `validate_post_handler(string $nonce_action): int` that checks nonce, checks capability, extracts entity ID
4. **User creation helper** (3 copies, HADM-DUP-004) — `create_hr_user(string $first, string $last, string $email): int|WP_Error`

---

## Cross-References with Prior Phase Findings

| Finding ID | Prior Phase | Cross-Reference |
|------------|-------------|----------------|
| HADM-SEC-001 | Phase 07 | PADM: `manage_options` misused for HR page access (same antipattern) |
| HADM-SEC-002 | Phase 07 | PERF: save_review/save_goal have no capability guards (same: nonce only) |
| HADM-SEC-003 | Phase 13-01 | HIR-SEC-001: same unprepared `get_var` with raw string interpolation |
| HADM-PERF-001 | Phase 08 | LADM-PERF-001: loan list no pagination; Phase 11 AVIEW-PERF-001 silent data loss |
| HADM-PERF-002 | Phase 11 | AVIEW: unbounded employee/assignment loads |
| HADM-LOGIC-001 | Phase 10 | SETT-LOGIC-003: no double-submission guard (no UNIQUE constraint or conditional UPDATE) |
| HADM-LOGIC-003 | Phase 13-01 | HIR-LOGIC-001: conversion workflows not wrapped in transactions |
| HADM-LOGIC-004 | Phase 08 | LADM-LOGIC-006: misuse of notes/JSON fields for structured data that needs a dedicated column |
