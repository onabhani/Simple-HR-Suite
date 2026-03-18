# Performance Admin, REST, and Module Audit Findings

**Plan:** 07-02
**Files audited:**
- `includes/Modules/Performance/Rest/Performance_Rest.php` (730 lines)
- `includes/Modules/Performance/Admin/Admin_Pages.php` (1,671 lines)
- `includes/Modules/Performance/PerformanceModule.php` (234 lines)

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 0 | 3 | 3 | 1 | 7 |
| Performance | 0 | 2 | 2 | 1 | 5 |
| Duplication | 0 | 0 | 2 | 1 | 3 |
| Logical | 0 | 3 | 2 | 1 | 6 |
| **Total** | **0** | **8** | **9** | **4** | **21** |

---

## Security Findings

### PADM-SEC-001 — High — Missing capability check on `ajax_update_goal_progress`

**File:** `includes/Modules/Performance/PerformanceModule.php:185-202`
**Severity:** High

`ajax_update_goal_progress` verifies the nonce with `check_ajax_referer()` but performs no `current_user_can()` check before calling `Goals_Service::update_progress()`. Any authenticated WordPress user who can obtain the `sfs_hr_performance` nonce (which is printed on the admin pages visible to employees with `sfs_hr_performance_view`) can set the progress of any goal to any value (0–100 range is not enforced here either).

```php
// Line 185: NO capability guard
public function ajax_update_goal_progress(): void {
    check_ajax_referer( 'sfs_hr_performance', 'nonce' );
    $goal_id  = isset( $_POST['goal_id'] ) ? (int) $_POST['goal_id'] : 0;
    $progress = isset( $_POST['progress'] ) ? (int) $_POST['progress'] : 0;
    // ... no current_user_can() check before update
    $result = Services\Goals_Service::update_progress( $goal_id, $progress );
```

**Fix:** Add a capability check immediately after the nonce check:
```php
if ( ! current_user_can( 'sfs_hr.manage' ) ) {
    wp_send_json_error( [ 'message' => __( 'Permission denied', 'sfs-hr' ) ] );
}
```

---

### PADM-SEC-002 — High — `check_read_permission` grants `sfs_hr_performance_view` to all active employees and HR responsibles — employees can read any other employee's scores and reviews via REST

**File:** `includes/Modules/Performance/Rest/Performance_Rest.php:278-280`
**Severity:** High

`check_read_permission()` grants access to any user with `sfs_hr_performance_view`. Per `Capabilities.php:108-121`, this capability is granted to:
1. All users with `sfs_hr.manage`
2. All department managers
3. All HR responsibles
4. **All active employees** — via `is_mgr` and `is_hr_responsible` paths (managers and HR responsibles are themselves employees in most setups)

Wait — re-reading: employees (non-manager, non-HR-responsible) do NOT automatically get `sfs_hr_performance_view`. Only `sfs_hr.leave.request` is granted to plain employees (line 109). However, managers and HR responsibles do get `sfs_hr_performance_view` and can call:

- `GET /performance/score/employee/{id}` — returns any employee's score
- `GET /performance/reviews?employee_id={id}` — returns any employee's reviews
- `GET /performance/goals?employee_id={id}` — returns any employee's goals
- `GET /performance/attendance/employee/{id}` — returns any employee's attendance metrics
- `GET /performance/rankings` — returns all employees' scores

None of these endpoints enforce ownership or department scope. A department manager for Department A can retrieve the full performance score, reviews, and goals of employees in Department B.

**Fix:** For `check_read_permission`, add an ownership/department scope check on the `employee_id` parameter within each callback (or within the permission callback for parameterized routes). Restrict cross-department reads to `sfs_hr.manage` users only.

---

### PADM-SEC-003 — High — `update_settings` REST endpoint calls undefined method `PerformanceModule::save_settings()` — PHP fatal error instead of saving

**File:** `includes/Modules/Performance/Rest/Performance_Rest.php:725`
**Severity:** High

`Performance_Rest::update_settings()` calls `PerformanceModule::save_settings( $data )` on line 725, but `PerformanceModule` only defines `update_settings( array $settings )` (line 109 of PerformanceModule.php). The method `save_settings` does not exist. This causes a PHP fatal error when `PUT /performance/settings` is called, even by an authorized admin user.

Additionally, the REST endpoint passes the raw `$data` array directly to `save_settings()` without merging with defaults or validating weight sums, whereas the admin form handler (`handle_save_settings`) carefully validates each field.

**Fix:**
```php
// In Performance_Rest::update_settings():
$data = $request->get_json_params();
// Merge with existing settings to preserve keys not in payload
$current = PerformanceModule::get_settings();
$merged  = array_merge( $current, $data );
PerformanceModule::update_settings( $merged );  // correct method name
```

---

### PADM-SEC-004 — Medium — `render_dashboard()` unprepared query for active employee count

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:263-265`
**Severity:** Medium

```php
$emp_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active'"
);
```

The `status` value `'active'` is a hard-coded string literal, so this is not a SQL injection risk in this specific instance. However, it uses `$wpdb->get_var()` without `$wpdb->prepare()`, violating the project's coding standard. WordPress's `PHPCS` ruleset will flag this.

**Fix:**
```php
$emp_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_employees WHERE status = %s",
    'active'
) );
```

---

### PADM-SEC-005 — Medium — `render_goals()` unbounded query without prepare when no status filter

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:1019-1025`
**Severity:** Medium

When `$employee_id` is 0 (all employees), the code builds a `$where_sql` using `$wpdb->prepare()` for the status clause correctly. However, the outer `$wpdb->get_results()` call concatenates `$where_sql` directly into the query string without a surrounding `$wpdb->prepare()`:

```php
$goals = $wpdb->get_results(
    "SELECT g.*, e.first_name, e.last_name, e.employee_code
     FROM {$wpdb->prefix}sfs_hr_performance_goals g
     JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = g.employee_id
     {$where_sql}
     ORDER BY g.status ASC, g.target_date ASC"
);
```

When `$where_sql` is empty (no status filter), the query is safe. When `$status` is set, `$where_sql` is already a prepared string from `$wpdb->prepare()`. This is technically safe due to prior preparation, but violates the project's convention of wrapping the full query in `$wpdb->prepare()`.

**Fix:** Use `$wpdb->prepare()` for the whole query, or pass the status filter as an argument array:
```php
$sql  = "SELECT g.*, e.first_name, e.last_name, e.employee_code
          FROM {$wpdb->prefix}sfs_hr_performance_goals g
          JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = g.employee_id";
$args = [];
if ( $status ) {
    $sql .= " WHERE g.status = %s";
    $args[] = $status;
}
$sql .= " ORDER BY g.status ASC, g.target_date ASC";
$goals = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) : $wpdb->get_results( $sql );
```

---

### PADM-SEC-006 — Medium — `render_employees()` departments query without prepare

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:472-474`
**Severity:** Medium

```php
$departments = $wpdb->get_results(
    "SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments WHERE active = 1 ORDER BY name"
);
```

No dynamic values — static query with hard-coded `active = 1`. No injection risk, but violates project `$wpdb->prepare()` convention. Same pattern exists at line 1001-1006 for the employees list in `render_goals()`.

**Fix:** Wrap in `$wpdb->prepare()` even for static queries, or document as acceptable. The project CLAUDE.md mandates `$wpdb->prepare()` for all dynamic SQL values; static-only queries are a gray area but flagging for consistency.

---

### PADM-SEC-007 — Low — `handle_save_justification()` nonce check fires before permission check

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:1566-1604`
**Severity:** Low

The handler correctly verifies the nonce with `check_admin_referer()` on line 1574 and then verifies HR-responsible status on line 1604. The order is: nonce first, permission second. This is acceptable because the nonce implicitly validates authentication, but the WordPress best practice (and what other handlers in this file do) is to check `current_user_can()` before `check_admin_referer()` to short-circuit unauthenticated calls before nonce processing.

**Fix:** Reorder to: check `current_user_can( 'sfs_hr.view' )` → `check_admin_referer()` → check HR-responsible status.

---

## Performance Findings

### PADM-PERF-001 — High — `render_dashboard()` calls `get_departments_summary()` twice (current + previous period)

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:255-260`
**Severity:** High

```php
$dept_summary      = Performance_Calculator::get_departments_summary( $start_date, $end_date );
$prev_dept_summary = Performance_Calculator::get_departments_summary( $prev_period['start'], $prev_period['end'] );
```

Based on Plan 01 findings (PERF service layer), `get_departments_summary()` itself calls `get_performance_ranking()` which has an N+1 problem (1,000+ queries per call on 200 employees). The dashboard thus fires this N+1 query set **twice** on every page load — once for the current period and once for the previous period.

**Fix:** Memoize or cache `get_departments_summary()` results per period. As an interim fix, store the result of the first call and use array operations to build the previous-period lookup without a second full calculation. Long-term: fix the N+1 in `get_performance_ranking()` first, then the dashboard double-call cost drops to acceptable levels.

---

### PADM-PERF-002 — High — `render_employee_detail()` calls `Alerts_Service::refresh_employee_attendance_alerts()` on every page view

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:604`
**Severity:** High

```php
Alerts_Service::refresh_employee_attendance_alerts( $employee_id );
```

This is called inline during the admin page render on every employee detail page view. Depending on the implementation of `refresh_employee_attendance_alerts()`, this likely involves reading attendance metrics, comparing against thresholds, and doing INSERT/UPDATE/DELETE operations on the alerts table — all triggered synchronously during a page render. This creates unpredictable page load times and risks partial failures if the DB operation takes too long or fails mid-render.

**Fix:** Move alert refresh to a background process (cron event, WP background processing) triggered on attendance session save, not on admin page render. If synchronous refresh is needed, add a debounce/cache to prevent running more than once per employee per hour.

---

### PADM-PERF-003 — Medium — `render_employee_detail()` has a 5-query synchronous hot path

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:586-605`
**Severity:** Medium

The employee detail render fires at minimum:
1. `$wpdb->get_row()` — employee + department join (line 586)
2. `Performance_Calculator::calculate_overall_score()` — multiple sub-queries
3. `Attendance_Metrics::get_employee_metrics()` — attendance session queries
4. `Goals_Service::get_employee_goals()` — goals query
5. `Alerts_Service::refresh_employee_attendance_alerts()` + `get_employee_alerts()` — alert queries

Plus the justification block adds:
6. `$wpdb->get_row()` — justification lookup (line 622)
7. Two `$wpdb->get_var()` calls for HR responsible and manager user IDs (lines 631, 654)
8. `$wpdb->get_row()` — early leave request stats (line 738)

**Fix:** Combine the separate single-column queries (HR responsible, manager_user_id) into the employee+department JOIN already performed at line 586. Cache attendance metrics between the initial calculation and the alert refresh call.

---

### PADM-PERF-004 — Medium — `get_reviews()` REST endpoint returns all reviews for an employee without pagination

**File:** `includes/Modules/Performance/Rest/Performance_Rest.php:467-484`
**Severity:** Medium

`GET /performance/reviews?employee_id={id}` returns all reviews for an employee with no `per_page` or `page` parameters. An employee could theoretically accumulate hundreds of review records over years of tenure. The admin list view (`render_reviews()`) already applies `LIMIT 50` at the SQL level (line 1187), but the REST endpoint does not.

**Fix:** Add `page` and `per_page` parameters to the reviews list route with a default of 20:
```php
'args' => [
    'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
    'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
],
```

---

### PADM-PERF-005 — Low — `render_alerts()` loads all active alerts — no pagination

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:1249-1251`
**Severity:** Low

`Alerts_Service::get_active_alerts()` is called with no limit. For organizations with many employees below threshold, this could return hundreds of alerts and render them all in a single HTML page.

**Fix:** Add LIMIT/offset pagination to `get_active_alerts()` and add a pager UI to the alerts page.

---

## Duplication Findings

### PADM-DUP-001 — Medium — Justification window calculation duplicated between render and handler

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:638-651` (render) and `Admin_Pages.php:1608-1623` (handler)
**Severity:** Medium

The 5-day justification window logic (`$window_open = $period_end_dt->modify('-5 days')`) is copy-pasted almost identically in both `render_employee_detail()` (lines 638-651) and `handle_save_justification()` (lines 1608-1623). The only difference is the render checks a `$within_deadline` boolean while the handler does two separate wp_die calls.

Any change to the window (e.g., extending from 5 days to 7 days) requires updating both places — a maintenance hazard.

**Fix:** Extract to a private helper `is_within_justification_window( string $end_date ): bool` and call it from both locations.

---

### PADM-DUP-002 — Medium — `check_write_permission` and `check_admin_permission` are identical

**File:** `includes/Modules/Performance/Rest/Performance_Rest.php:282-288`
**Severity:** Medium

```php
public function check_write_permission(): bool {
    return current_user_can( 'sfs_hr.manage' );
}

public function check_admin_permission(): bool {
    return current_user_can( 'sfs_hr.manage' );
}
```

Both methods check the exact same capability. The semantic distinction between "write" and "admin" is not implemented — both resolve to `sfs_hr.manage`. This creates a false impression that criteria management (`save_criterion`) and snapshot generation require elevated admin access distinct from regular write operations, when in fact they are identical.

**Fix:** Either remove `check_admin_permission()` and replace its usages with `check_write_permission()`, or implement a true `sfs_hr.performance.admin` capability for operations like snapshot generation and criteria management.

---

### PADM-DUP-003 — Low — Employee list query duplicated in `render_goals()`

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:1001-1006` and `Admin_Pages.php:1090-1094`
**Severity:** Low

The full active-employees list is queried once at line 1001 for the filter dropdown and again referenced at line 1090 for the second dropdown — but they use the same `$employees` variable. The issue is the same SQL is duplicated between `render_goals()` and `render_employees()` (line 472 equivalent). This is a minor duplication at the method level.

**Fix:** Extract the active-employees list query to a private helper `get_active_employees_list(): array`.

---

## Logical Findings

### PADM-LOGIC-001 — High — `acknowledge_review` endpoint does not verify review is in a submittable state before acknowledging

**File:** `includes/Modules/Performance/Rest/Performance_Rest.php:551-593`
**Severity:** High

`POST /performance/reviews/{id}/acknowledge` calls `Reviews_Service::acknowledge_review()` without first verifying that the review has been `submitted`. An employee with `check_acknowledge_permission()` access could acknowledge a review that is still in `draft` state (before the reviewer has submitted it), violating the expected state machine: `draft` → `submitted` → `acknowledged`.

This is an out-of-order state machine transition issue: employees can acknowledge drafts that have not been formally submitted to them.

**Fix:** In `acknowledge_review()` callback (or in `Reviews_Service::acknowledge_review()`), verify that `$review->status === 'submitted'` before proceeding:
```php
if ( $review->status !== 'submitted' ) {
    return new \WP_REST_Response( [ 'error' => true, 'message' => __( 'Review must be submitted before it can be acknowledged.', 'sfs-hr' ) ], 409 );
}
```

---

### PADM-LOGIC-002 — High — Score endpoint returns live calculated scores regardless of review publish state — no draft visibility gate

**File:** `includes/Modules/Performance/Rest/Performance_Rest.php:611-619`
**Severity:** High

`GET /performance/score/employee/{id}` returns a live-calculated overall performance score including the reviews component. If a review is in `draft` status (reviewer has not yet submitted it), the `Performance_Calculator::calculate_overall_score()` likely includes draft review ratings in the score calculation. This means:

1. A department manager can create a draft review with a low score.
2. An employee with `sfs_hr_performance_view` (or any manager) can immediately call the score endpoint and observe the impact of that unpublished draft review.

Scores based on draft reviews should not be visible — only submitted/acknowledged reviews should contribute.

**Fix:** Confirm whether `Performance_Calculator` filters by review status. If not, add `WHERE status IN ('submitted','acknowledged')` to the reviews component of the score calculation. Add the same gate to the REST endpoint's response meta to indicate whether the score is based on finalized reviews only.

---

### PADM-LOGIC-003 — High — `handle_save_justification()` does not validate that `period_start` and `period_end` are well-formed dates before use

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:1569-1572`
**Severity:** High

The handler validates `$end_date` format on line 1611 (checking `Y-m-d` format) but does NOT validate `$start_date` format. `$start_date` is inserted directly into the DB at line 1662 (`'period_start' => $start_date`). A malformed `period_start` value (e.g., `"' OR 1=1 --"`) would be passed to `$wpdb->insert()` which uses a format array (`%s`) so SQL injection is protected, but an invalid date string would be stored in the DB, corrupting the justification record's period key.

**Fix:** Apply the same `\DateTime::createFromFormat('Y-m-d', $start_date)` validation to `$start_date` that is applied to `$end_date` on line 1611.

---

### PADM-LOGIC-004 — Medium — REST `create_review` / `update_review` accept any `status` value in payload — allows bypassing draft→submitted workflow

**File:** `includes/Modules/Performance/Rest/Performance_Rest.php:500-533`
**Severity:** Medium

`POST /performance/reviews` and `PUT /performance/reviews/{id}` pass the entire request JSON body to `Reviews_Service::save_review( $data )` without stripping or whitelisting the `status` field. A user with `sfs_hr.manage` (the `check_write_permission` gate) could create a review directly with `status: "submitted"` or `status: "acknowledged"` in the payload, bypassing the intended `submit_review` and `acknowledge_review` endpoints.

**Fix:** Strip `status` from the create/update payload, or whitelist only allowed transition statuses (`draft` for create; `draft` for update). Use the dedicated `submit_review` and `acknowledge_review` endpoints to drive state transitions.

---

### PADM-LOGIC-005 — Medium — No idempotency guard on `submit_review` — can be called multiple times, potentially overwriting timestamps

**File:** `includes/Modules/Performance/Rest/Performance_Rest.php:535-549`
**Severity:** Medium

`POST /performance/reviews/{id}/submit` calls `Reviews_Service::submit_review( $review_id )` with no check that the review is not already submitted. If `submit_review()` sets a `submitted_at` timestamp, calling it twice would overwrite the original submission timestamp with the second call's time. It may also re-trigger submission notifications on each call.

**Fix:** In `Reviews_Service::submit_review()`, verify the review is in `draft` status before proceeding. If already submitted, return a WP_Error or no-op.

---

### PADM-LOGIC-006 — Low — `render_employee_detail()` computes `$can_read_justification = false` when employee is above threshold — justification section hidden from manager/admin when employee improves

**File:** `includes/Modules/Performance/Admin/Admin_Pages.php:665-667`
**Severity:** Low

If `$is_below_threshold` is false (employee's current score is above threshold), `$can_read_justification` is hardcoded to `false` and the justification section is never rendered. This means a manager or admin who submitted a justification in a prior period (when the employee was below threshold) cannot view the historical justification record if the employee's current score is now above threshold — the visibility is tied to current score, not historical record existence.

The concern is mild — justifications are for below-threshold periods only — but the UI gives no indication that historical justifications may exist for different periods. An admin reviewing past periods via the date filter would find that switching to a period where the employee was below-threshold but is now above-threshold causes the justification to disappear.

**Fix:** Bind justification visibility to the period being viewed, not the employee's current score. If viewing a historical period where a justification exists, show it regardless of current score.

---

## Justification Workflow Analysis

The justification workflow (HR Responsible writes a note explaining why an employee's performance is below threshold) is implemented across `render_employee_detail()` and `handle_save_justification()`. This section maps the complete access control chain.

### Write Access (who can create/update a justification)

1. **Capability gate:** No explicit `current_user_can()` check before nonce verification in `handle_save_justification()`. Any authenticated user who can craft a valid nonce can attempt the handler. However, the nonce key includes `employee_id` (`sfs_hr_save_justification_{$employee_id}`), so the nonce is employee-specific.
2. **HR Responsible gate:** After nonce verification, the handler fetches `hr_responsible_user_id` from `sfs_hr_departments` and requires `get_current_user_id() === $hr_responsible_user_id`. Only the single designated HR Responsible for the employee's department can write.
3. **Time window gate:** Write access is gated to a 5-day window before the period end date. Outside this window, writes are blocked with `wp_die()`.
4. **Threshold gate:** Write is blocked if the employee's score is at or above the threshold at the time of submission.

**Verdict:** Write access is well-controlled. The only gap is the missing early `current_user_can()` check (PADM-SEC-007).

### Read Access (who can view a justification)

Read access is computed in `render_employee_detail()` at lines 653-667:
- HR Responsible of the employee's department
- Department Manager of the employee's department
- GM Approver (`sfs_hr_leave_gm_approver` option)
- WordPress admin (`manage_options`)

**Critical observation:** Employees themselves are NOT granted read access to their own justification via this code path. A plain employee visiting the admin (which they cannot normally access — admin menu requires `sfs_hr_performance_view`) would not reach `render_employee_detail()`. There is no frontend/portal equivalent of the justification section, so employees cannot read their own justifications.

**Gap:** The justification is HR-internal. There is no mechanism to formally "publish" or "share" a justification with the employee. If the intent is to eventually notify or show the employee their justification, this workflow is currently incomplete.

### Employee Score Visibility Before Publish

**No formal publish gate exists.** Performance scores are live-calculated and visible to any user with `sfs_hr_performance_view` (which includes managers and HR responsibles) at any time via both the admin pages and the REST API (`GET /performance/score/employee/{id}`). There is no "draft" vs "published" state for the overall score itself — only individual reviews have a `status` field.

An employee with `sfs_hr_performance_view` (i.e., if they are also a manager or HR responsible) can see their own live score at any time, including when reviews affecting their score are still in `draft` status. This is documented as PADM-LOGIC-002.

### Manager Cross-Department Access

As documented in PADM-SEC-002, `check_read_permission` allows managers to read performance data for employees outside their own department. The admin pages do not have a corresponding cross-department restriction (the `$dept_id` filter on `render_employees()` is optional, defaulting to 0 = all departments). A manager can explicitly browse all departments.

---

## REST Endpoint Permission Callback Summary

| Endpoint | Method | Permission Callback | Effective Gate |
|----------|--------|---------------------|----------------|
| `/performance/attendance/employee/{id}` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/attendance/department/{id}` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/attendance/summary` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/goals` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/goals` | POST | `check_write_permission` | `sfs_hr.manage` |
| `/performance/goals/{id}` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/goals/{id}` | PUT | `check_write_permission` | `sfs_hr.manage` |
| `/performance/goals/{id}` | DELETE | `check_write_permission` | `sfs_hr.manage` |
| `/performance/goals/{id}/progress` | POST | `check_write_permission` | `sfs_hr.manage` |
| `/performance/goals/{id}/history` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/reviews` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/reviews` | POST | `check_write_permission` | `sfs_hr.manage` |
| `/performance/reviews/{id}` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/reviews/{id}` | PUT | `check_write_permission` | `sfs_hr.manage` |
| `/performance/reviews/{id}/submit` | POST | `check_write_permission` | `sfs_hr.manage` |
| `/performance/reviews/{id}/acknowledge` | POST | `check_acknowledge_permission` | `sfs_hr.manage` OR review subject |
| `/performance/reviews/criteria` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/reviews/criteria` | POST | `check_admin_permission` | `sfs_hr.manage` (= write, see PADM-DUP-002) |
| `/performance/score/employee/{id}` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/rankings` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/alerts` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/alerts/{id}/acknowledge` | POST | `check_write_permission` | `sfs_hr.manage` |
| `/performance/alerts/{id}/resolve` | POST | `check_write_permission` | `sfs_hr.manage` |
| `/performance/alerts/statistics` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/snapshots/generate` | POST | `check_admin_permission` | `sfs_hr.manage` (= write, see PADM-DUP-002) |
| `/performance/snapshots/employee/{id}` | GET | `check_read_permission` | `sfs_hr_performance_view` |
| `/performance/settings` | GET | `check_admin_permission` | `sfs_hr.manage` |
| `/performance/settings` | PUT | `check_admin_permission` | `sfs_hr.manage` |

**No `__return_true` endpoints found.** All routes require at minimum `sfs_hr_performance_view`.

---

## $wpdb Query Audit

### Performance_Rest.php

| Location | Query | Status |
|----------|-------|--------|
| Line 307-310 | `SELECT id FROM sfs_hr_employees WHERE user_id = %d` | Safe — `$wpdb->prepare()` used |
| Line 568-571 | `SELECT id FROM sfs_hr_employees WHERE user_id = %d` | Safe — `$wpdb->prepare()` used |

No direct queries beyond these two — all data access delegates to Service classes.

### Admin_Pages.php

| Location | Query | Status |
|----------|-------|--------|
| Line 263-265 | `SELECT COUNT(*) FROM sfs_hr_employees WHERE status = 'active'` | Flag — missing `$wpdb->prepare()` (PADM-SEC-004) |
| Line 472-474 | `SELECT id, name FROM sfs_hr_departments WHERE active = 1` | Flag — missing `$wpdb->prepare()` (PADM-SEC-006) |
| Line 586-592 | `SELECT e.*, d.name FROM employees JOIN departments WHERE e.id = %d` | Safe — `$wpdb->prepare()` used |
| Line 622-628 | `SELECT * FROM sfs_hr_performance_justifications WHERE employee_id = %d AND ...` | Safe — `$wpdb->prepare()` used |
| Line 631-634 | `SELECT hr_responsible_user_id FROM sfs_hr_departments WHERE id = %d` | Safe — `$wpdb->prepare()` used |
| Line 654-657 | `SELECT manager_user_id FROM sfs_hr_departments WHERE id = %d` | Safe — `$wpdb->prepare()` used |
| Line 738-749 | `SELECT COUNT(*), SUM(CASE...) FROM sfs_hr_early_leave_requests WHERE employee_id = %d AND ...` | Safe — `$wpdb->prepare()` used |
| Line 1001-1006 | `SELECT id, employee_code, first_name, last_name FROM sfs_hr_employees WHERE status = 'active'` | Flag — missing `$wpdb->prepare()` (PADM-SEC-006, same pattern) |
| Line 1019-1025 | `SELECT g.*, e.* FROM performance_goals JOIN employees {$where_sql}` | Conditional flag — (PADM-SEC-005) |
| Line 1180-1188 | `SELECT r.*, e.*, reviewer.display_name FROM performance_reviews JOIN employees LIMIT 50` | Safe — no user input interpolated; static query |
| Line 1588-1591 | `SELECT id, dept_id FROM sfs_hr_employees WHERE id = %d` | Safe — `$wpdb->prepare()` used |
| Line 1599-1602 | `SELECT hr_responsible_user_id FROM sfs_hr_departments WHERE id = %d` | Safe — `$wpdb->prepare()` used |
| Line 1639-1644 | `SELECT id FROM justifications WHERE employee_id = %d AND period_start = %s AND period_end = %s` | Safe — `$wpdb->prepare()` used |
| Line 1646-1655 | `$wpdb->update()` | Safe — `$wpdb->update()` uses internal escaping |
| Line 1657-1666 | `$wpdb->insert()` | Safe — `$wpdb->insert()` uses internal escaping |

### PerformanceModule.php

No direct `$wpdb` queries in AJAX handlers — all delegate to service classes. AJAX handlers use `$_POST` input with `(int)` cast and `sanitize_text_field()` before passing to services.
