# Phase 15 Plan 01: WorkforceStatusModule Admin Findings

**Phase:** 15 — Workforce_Status Audit
**Plan:** 01
**Files audited:** 3 files (~1368 lines)
**Date:** 2026-03-16
**Auditor:** Claude Sonnet 4.6 (execute-phase)

---

## Files Audited

| File | Lines | Role |
|------|-------|------|
| `includes/Modules/Workforce_Status/WorkforceStatusModule.php` | 33 | Module bootstrap / hook registration |
| `includes/Modules/Workforce_Status/Admin/Admin_Pages.php` | 1335 | Workforce status dashboard (main logic) |
| `includes/Modules/Workforce_Status/Services/Status_Analytics.php` | 1 (empty) | Dead code placeholder |

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 0 | 1 | 2 | 0 | 3 |
| Performance | 0 | 3 | 2 | 0 | 5 |
| Duplication | 0 | 0 | 2 | 1 | 3 |
| Logical | 0 | 1 | 2 | 2 | 5 |
| **Total** | **0** | **5** | **8** | **3** | **16** |

---

## Security Findings

### WADM-SEC-001 — Wrong Capability Constant for Menu Registration

**Severity:** High
**Location:** `Admin/Admin_Pages.php:31` (menu registration) and `:38` (render_page gate)
**Description:**
The submenu is registered with `'sfs_hr_attendance_view_team'` and `render_page()` gates on `Helpers::require_cap('sfs_hr_attendance_view_team')`. This is the Attendance module's team-view capability, not a Workforce_Status-specific capability. The correct dotted-format capability per CLAUDE.md conventions should follow the pattern `sfs_hr.workforce.view_team` or reuse the generic manager cap `sfs_hr.manage`.

The immediate security concern is that any user with attendance team-view access (a role-assigned WordPress capability string, not a dynamic `sfs_hr.*` cap) can see the Workforce Status dashboard. The `sfs_hr_attendance_view_team` capability is a WordPress role-assigned string; if it is granted to roles not intended to have workforce dashboard access, there is no secondary scoping guard (other than the department scope for non-`sfs_hr.manage` users).

**Impact:** Incorrect capability string for access control. Any role holding `sfs_hr_attendance_view_team` (e.g., shift supervisors who should not see org-wide risk flags) gains access.
**Fix Recommendation:** Introduce `sfs_hr.workforce.view_team` capability in Capabilities.php (or reuse `sfs_hr.manage`), register it on the appropriate roles, and update both the `add_submenu_page` call and the `require_cap` guard to use the correct capability.
**Cross-reference:** Same wrong-capability-for-menu pattern was flagged in Phase 13 HADM-SEC-001 (manage_options for department approval).

---

### WADM-SEC-002 — Unprepared Query in `get_department_map()` All-Departments Path

**Severity:** Medium
**Location:** `Admin/Admin_Pages.php:813-816`
**Description:**
`get_department_map()` branches on whether `$allowed_depts` is `null` (all departments — sfs_hr.manage users). In the `null` branch (lines 814-816), the query is executed without `$wpdb->prepare()`:

```php
$rows = $params
    ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
    : $wpdb->get_results( $sql, ARRAY_A );
```

When `$params` is empty (the all-departments case), `$sql` is a static string with no user input (`SELECT id, name FROM {$table} WHERE active = 1 ORDER BY name ASC`). This is technically safe because the table name comes from `$wpdb->prefix` concatenation (not user input) and there are no user-controlled values in the WHERE clause. However, it violates the project convention (CLAUDE.md: "All database access uses `$wpdb` directly with `$wpdb->prepare()`") and creates a pattern that could be unsafe if the query is modified later.

**Impact:** No injection risk in current form (static query), but code style violation. Future maintainers may add user-controlled conditions before the branch and miss that the unprepared path exists.
**Fix Recommendation:** Always use `$wpdb->prepare()` even for static queries. Alternatively, restructure to a single code path: if `$params` is empty, pass a trivially-prepared query (`$wpdb->prepare("SELECT ... WHERE active = %d ORDER BY name ASC", 1)`).

---

### WADM-SEC-003 — `paginate_links()` Output Echoed Without Escaping

**Severity:** Medium
**Location:** `Admin/Admin_Pages.php:647`
**Description:**
```php
echo '<div class="sfs-hr-workforce-pagination">' . $page_links . '</div>';
```
`paginate_links()` returns HTML that includes URLs assembled from the current request's query string. WordPress's `paginate_links()` does call `esc_url()` internally on the `href` attributes it generates, so the anchor `href` values are escaped. However, the raw HTML string is echoed without `wp_kses_post()` or equivalent, relying on WordPress core to have escaped it internally. This is an implicit trust of a WordPress core function.

**Impact:** Low injection risk because WordPress's `paginate_links()` escapes URLs. However, the pattern sets a precedent of echoing raw HTML from helper functions — identical code in other contexts (e.g., a custom `paginate_links` wrapper) would be unsafe.
**Fix Recommendation:** Wrap with `wp_kses_post()` to make the escaping intent explicit: `echo '<div ...>' . wp_kses_post( $page_links ) . '</div>';`. This is the WordPress VIP recommended pattern.
**Cross-reference:** Phase 04 Frontend audit flagged raw `paginate_links` echo in DashboardTab — same pattern.

---

## Performance Findings

### WADM-PERF-001 — Unbounded Full-Table Employee Load (No LIMIT)

**Severity:** High
**Location:** `Admin/Admin_Pages.php:857-864`
**Description:**
`get_all_rows()` executes `SELECT id, employee_code, first_name, last_name, email, dept_id FROM sfs_hr_employees WHERE status='active' ...` with **no LIMIT clause**. All active employees are loaded into PHP memory. For a 500-employee organization this is a single 500-row result set — acceptable. However, after loading all employees, the code then calls three additional batch queries (`get_today_punch_map`, `get_today_leave_map`, `get_risk_flags_map`) with all employee IDs as IN-list parameters.

For large organizations (1000+ employees), each `IN (...)` clause becomes a 1000-element IN list, which forces a full index scan per query. The PHP-side filter + pagination (lines 90-103) means all 1000 employees are loaded even when the user requests page 2 of the "absent" tab showing 20 rows.

**Impact:** O(n) memory and O(n) query cost on every page view regardless of what the user is looking at. At 500 employees: ~10K rows total across all queries. At 2000 employees: ~40K rows.
**Fix Recommendation:** Implement server-side pagination. Move the tab filter into the SQL WHERE clause (`status` must be resolved differently), or pre-compute today's status for each employee via a combined query. As a minimum, add a LIMIT/OFFSET to the employee fetch and compute counts in a separate COUNT query scoped per status. This matches the pattern used in Attendance Admin (Phase 05) and Assets Admin (Phase 11).
**Cross-reference:** Phase 11 AVIEW-PERF-001 flagged PHP-side re-filter after LIMIT 200 causing data loss. The same architectural decision here (PHP-side pagination) has the same fundamental flaw — on large datasets, page N may be empty or incomplete.

---

### WADM-PERF-002 — N+1 Shift Resolution Loop

**Severity:** High
**Location:** `Admin/Admin_Pages.php:1182-1199`
**Description:**
`get_employee_shifts_map()` iterates over all employee IDs and calls `AttendanceModule::resolve_shift_for_date( $emp_id, $ymd )` once per employee:

```php
foreach ( $emp_ids as $emp_id ) {
    $shift = \SFS\HR\Modules\Attendance\AttendanceModule::resolve_shift_for_date( (int) $emp_id, $ymd );
    ...
}
```

If `resolve_shift_for_date()` executes a database query (looking up the employee's shift assignment for that date, then the shift definition), this is a classic N+1: 1 query to load employees + N queries to resolve shifts. For 500 employees that is 500+ additional queries on every page view.

**Impact:** Potentially 500+ synchronous DB queries on each workforce status page load. This would cause timeouts on large organizations and is the same Critical pattern flagged for `get_performance_ranking` in Phase 07 (PERF N+1 on 200 employees = 1000+ queries).
**Fix Recommendation:** Add a batch shift resolver to AttendanceModule: `AttendanceModule::resolve_shifts_for_date( array $emp_ids, string $ymd ): array` that fetches all shift assignments for the given employee IDs in one query and returns a map. The existing per-employee resolver can remain for single-employee use. This is the same fix pattern recommended for Phase 07 N+1.
**Cross-reference:** Phase 07 PERF N+1 (get_performance_ranking 1000+ queries); Phase 05 attendance audit identified `resolve_shift_for_date()` as a 2-query method (policy + shift lookup).

---

### WADM-PERF-003 — Redundant `is_holiday()` Call Per Employee

**Severity:** High
**Location:** `Admin/Admin_Pages.php:880` (global check) and `:1257` (per-employee check inside `is_working_day_for_employee()`)
**Description:**
`is_holiday()` is called once globally at line 880:
```php
$is_global_holiday = $this->is_holiday( $args['today_date'] );
```
Then `is_working_day_for_employee()` is called once per employee (line 911):
```php
$is_working_day_for_emp = ! $is_global_holiday && $this->is_working_day_for_employee( $args['today_date'], $shift_info );
```
However, `is_working_day_for_employee()` **also calls `$this->is_holiday()`** internally at line 1257:
```php
if ( $this->is_holiday( $ymd ) ) {
    return false;
}
```
Each `is_holiday()` call fetches `get_option('sfs_hr_holidays', [])` from the WordPress options cache and iterates all holiday entries. While `get_option()` is cached, the array iteration happens every call. For 500 employees, `is_holiday()` is called 501 times per page view (1 global + 500 per-employee) when 1 call would suffice.

**Impact:** Redundant work on every page view proportional to employee count. The short-circuit at line 911 (`! $is_global_holiday &&`) partially mitigates this on holiday days (skips the per-employee call), but on non-holiday working days the redundant per-employee call fires 500 times.
**Fix Recommendation:** Pass the pre-computed `$is_global_holiday` result into `is_working_day_for_employee()` as a parameter, or remove the internal `is_holiday()` call from `is_working_day_for_employee()` since the caller always pre-checks it before calling the method. The short-circuit at line 911 already makes the per-employee `is_holiday()` redundant.

---

### WADM-PERF-004 — Last Punch via PHP Instead of SQL MAX

**Severity:** Medium
**Location:** `Admin/Admin_Pages.php:1058-1070`
**Description:**
`get_today_punch_map()` fetches all punches for all employees for today ordered by `punch_time ASC`, then overwrites the map per employee (last row wins):
```php
$sql = "SELECT employee_id, punch_type, punch_time FROM {$table}
        WHERE employee_id IN ($placeholders)
          AND punch_time >= %s AND punch_time < %s
        ORDER BY punch_time ASC";
...
foreach ( $rows as $r ) {
    $eid = (int) $r['employee_id'];
    $map[ $eid ] = $r; // last row wins
}
```
This loads ALL punches for all employees for today (potentially many per employee), then does the aggregation in PHP. The equivalent SQL with a subquery or `GROUP BY ... ORDER BY punch_time DESC LIMIT 1` per employee would return only one row per employee.

**Impact:** Fetches all punches for today for all employees into PHP memory. An employee with 10 punch events (in/break_start/break_end/out multiple times) returns 10 rows where 1 is needed. At 500 employees averaging 4 punches each = 2000 rows instead of 500.
**Fix Recommendation:** Use a SQL MAX subquery pattern:
```sql
SELECT p.employee_id, p.punch_type, p.punch_time
FROM {$table} p
INNER JOIN (
    SELECT employee_id, MAX(punch_time) AS max_pt
    FROM {$table}
    WHERE employee_id IN ($placeholders) AND punch_time >= %s AND punch_time < %s
    GROUP BY employee_id
) latest ON p.employee_id = latest.employee_id AND p.punch_time = latest.max_pt
```

---

### WADM-PERF-005 — Unbounded 30-Day Risk Sessions Load

**Severity:** Medium
**Location:** `Admin/Admin_Pages.php:960-1034`
**Description:**
`get_risk_flags_map()` fetches all attendance session rows for all employees for the last 30 days with no LIMIT:
```sql
SELECT employee_id, status
FROM sfs_hr_attendance_sessions
WHERE employee_id IN ($placeholders)
  AND work_date BETWEEN %s AND %s
```
For 500 employees × 30 days = up to 15,000 rows returned on each page view. The query is correctly prepared and bounded to a date window, but there is no pagination or lazy-loading. The risk flag computation aggregates in PHP after loading all rows.

**Impact:** Up to 15K rows loaded on every workforce status page view. On larger organizations this becomes a significant memory and DB load spike.
**Fix Recommendation:** Move risk aggregation to SQL using `GROUP BY employee_id` with `SUM(CASE WHEN status='late' THEN 1 ELSE 0 END)` etc. This reduces the result set from 15K rows to N employee rows with pre-computed counts, matching the approach used in Phase 05's Session_Service aggregate queries.

---

## Duplication Findings

### WADM-DUP-001 — `is_holiday()` Duplicated Between Admin_Pages and Absent_Notifications

**Severity:** Medium
**Location:**
- `Admin/Admin_Pages.php:1126-1163` (instance method)
- `Notifications/Absent_Notifications.php:142-175` (private static method)

**Description:**
Both implementations are line-for-line identical: same option key (`sfs_hr_holidays`), same format support (start/end and start_date/end_date), same repeat-holiday year-expansion logic. The only structural difference is visibility (`protected` instance method in Admin_Pages vs `private static` in Absent_Notifications). Even the comment blocks are mirrored.

```php
// Admin_Pages.php:1136-1137
$s = isset( $h['start'] ) ? $h['start'] : ( isset( $h['start_date'] ) ? $h['start_date'] : '' );
$e = isset( $h['end'] ) ? $h['end'] : ( isset( $h['end_date'] ) ? $h['end_date'] : $s );

// Absent_Notifications.php:151-152 -- identical
$s = isset( $h['start'] ) ? $h['start'] : ( isset( $h['start_date'] ) ? $h['start_date'] : '' );
$e = isset( $h['end'] ) ? $h['end'] : ( isset( $h['end_date'] ) ? $h['end_date'] : $s );
```

**Impact:** Bug fixes or format changes to holiday logic must be applied in both places. A divergence has already occurred: Admin_Pages has an inline comment `// Support both old format (start_date/end_date) and new format (start/end)` that Absent_Notifications lacks, making the two copies slightly different to read even if logically equivalent.
**Fix Recommendation:** Extract to `Core\Helpers::is_holiday( string $ymd ): bool` or a dedicated `Core\Calendar` service. Both callers reference `get_option('sfs_hr_holidays')` which is a Core concern, not a Workforce_Status concern. The method already exists as a good candidate for `Core\Helpers`.
**Cross-reference:** Phase 05 attendance audit noted `is_holiday` use in Session_Service — likely a third copy exists there.

---

### WADM-DUP-002 — `manager_dept_ids_for_current_user()` Duplicated from `Core\Admin`

**Severity:** Medium
**Location:** `Admin/Admin_Pages.php:1317-1334`
**Description:**
The docblock explicitly acknowledges the duplication: `"Dept ids managed by current user (copy of Core\Admin::manager_dept_ids())"`. The implementation is identical: fetch `get_current_user_id()`, query `sfs_hr_departments WHERE manager_user_id=%d AND active=1`, return int array.

This copy was likely created to avoid adding a dependency on `Core\Admin` from within a module's Admin class, but the pattern is repeated across multiple modules (Phase 09 Payroll Admin, Phase 11 Assets Admin, Phase 12 Employees Admin all have similar department-scoping logic per prior audit phases).

**Impact:** Any change to the department manager query (e.g., supporting `hr_responsible_user_id` as an alternate manager, or adding a `deleted_at IS NULL` check) must be applied to all copies.
**Fix Recommendation:** Centralise in `Core\Helpers::manager_dept_ids_for_user( int $user_id ): array`. This is the same recommendation made in Phase 09 and Phase 12 audit reports. This module's copy makes the 3rd or 4th instance — the fix is overdue.
**Cross-reference:** Phase 09 PADM-DUP-001; Phase 12 Employees audit.

---

### WADM-DUP-003 — Department Scoping IN-placeholder Pattern Repeated Three Times

**Severity:** Low
**Location:** `Admin/Admin_Pages.php:808-810`, `:841-843`
**Description:**
The pattern of building an IN-placeholder string and params array is repeated in `get_department_map()` and `get_all_rows()` (and again in `manager_dept_ids_for_current_user()` via the WHERE clause). Each instance generates `implode( ',', array_fill( 0, count( $allowed_depts ), '%d' ) )` with corresponding `array_map( 'intval', $allowed_depts )`. This is a minor duplication compared to WADM-DUP-001/002 but is a code smell that could be a helper `$wpdb_helpers->in_int_list( array $ids ): array { placeholders, params }`.

**Impact:** Low. Purely cosmetic/readability concern. No bug risk because each instance is correctly constructed.
**Fix Recommendation:** Extract to a helper function or use a utility. Low priority; address when refactoring department-scoping duplication (WADM-DUP-002).

---

## Logical Findings

### WADM-LOGIC-001 — `is_friday()` Fallback Misses Saudi Saturday Weekend

**Severity:** High
**Location:** `Admin/Admin_Pages.php:1221-1225`
**Description:**
The fallback path (when AttendanceModule is not available) uses `is_friday()` to determine a day off:
```php
protected function is_friday( string $ymd ): bool {
    $date = new \DateTimeImmutable( $ymd . ' 00:00:00', $tz );
    return (int) $date->format( 'w' ) === 5;
}
```
Only Friday (day-of-week = 5) is checked. The Saudi weekend is Friday **and** Saturday. A Saturday would be treated as a working day in the fallback path, causing all employees to be incorrectly flagged as absent on Saturday mornings when AttendanceModule is unavailable.

The Absent_Notifications counterpart (`is_employee_day_off()` in `Notifications/Absent_Notifications.php:184-208`) correctly uses `sfs_hr_attendance_settings['weekly_off_days']` which defaults to `[5]` (Friday only by default, but configurable). This creates an inconsistency: Admin_Pages fallback only checks Friday, while Absent_Notifications checks the configured weekly off days.

**Impact:** Employees incorrectly shown as absent on Saturday when AttendanceModule is unavailable (e.g., during testing, module deactivation). Risk of incorrect risk-flag accumulation.
**Fix Recommendation:** Replace `is_friday()` fallback with the same pattern as Absent_Notifications:
```php
$att_settings = get_option( 'sfs_hr_attendance_settings' ) ?: [];
$off_days = array_map( 'intval', $att_settings['weekly_off_days'] ?? [ 5, 6 ] );
$dow = (int) (new \DateTimeImmutable( $ymd, wp_timezone() ))->format( 'w' );
return in_array( $dow, $off_days, true );
```
Default to `[5, 6]` (Fri+Sat) per CLAUDE.md "Country default SA" note.
**Cross-reference:** Phase 09 PAY-LOGIC-001 (count_working_days excludes only Friday — same Saudi weekend bug). Phase 05 attendance audit noted the same omission.

---

### WADM-LOGIC-002 — `compute_status_from_punch()`: Infinite "On Break" State

**Severity:** Medium
**Location:** `Admin/Admin_Pages.php:1106-1118`
**Description:**
```php
case 'break_start':
    return 'on_break';
case 'in':
case 'break_end':
    return 'clocked_in';
case 'out':
    return 'clocked_out';
```
If an employee punches `break_start` and never punches `break_end` or `out`, their last punch type is `break_start`. They will remain in the `on_break` tab permanently until the next business day's data wipes the punch map (next day, the today window shifts and they appear as `not_clocked_in`).

While this is a display issue (not a data corruption issue), a manager viewing the dashboard at end-of-day will see employees still "on break" after hours, which is misleading.

**Impact:** Cosmetic display issue — employees show as "on break" indefinitely after leaving without punching out. Could lead managers to assume employees are still in the building.
**Fix Recommendation:** Add a time-based guard: if the last punch is `break_start` AND the punch time is more than N hours ago (e.g., 4 hours), display as `unknown` or `clocked_out`. Alternatively, the attendance session completion cron (Phase 05) should auto-complete incomplete sessions; if it does, the punch record would have an `out` punch added and this display issue would self-resolve.
**Note:** This is a known limitation per the plan specification.

---

### WADM-LOGIC-003 — Second Shift Punch-In Overwrites First Punch-Out

**Severity:** Medium
**Location:** `Admin/Admin_Pages.php:1066-1070`
**Description:**
`get_today_punch_map()` keeps the last punch per employee (last row wins in ORDER BY punch_time ASC). If an employee works a split shift (punches in, out, then in again for a second shift), the sequence would be: `in → out → in`. The last punch is `in`, so the employee correctly shows as `clocked_in`. However, if the employee then punches `out` at the end of the second shift and the data is: `in → out → in → out`, the last punch is `out` (correct: clocked_out). This is the normal case.

The edge case is: if an employee punches `in` after hours for overtime that was not expected, the `out` punch from their regular shift (e.g., 5pm) is overwritten by the second `in` punch (e.g., 7pm). The employee correctly shows as `clocked_in`. But if the system did not capture the second punch (e.g., kiosk was offline), the employee remains shown as `clocked_out` even though they are actually working overtime. This is acceptable behavior for a dashboard that shows the last known state.

**Impact:** Minor edge case. The last-punch-wins logic is a deliberate design decision. The risk is that shift-end `out` is lost from the dashboard display when a second `in` occurs. No data loss — all punches are stored, only the display key is the last one.
**Fix Recommendation:** Document as known limitation in code comment. If dual-shift employees are common in the organization, consider surfacing ALL today's punches in a tooltip or expanded view rather than just the last one.
**Note:** This is a known limitation per the plan specification.

---

### WADM-LOGIC-004 — Risk Flag Thresholds Not Configurable

**Severity:** Low
**Location:** `Admin/Admin_Pages.php:17-20`
**Description:**
```php
private const RISK_LOOKBACK_DAYS       = 30;
private const RISK_LATE_MIN_DAYS       = 5;
private const RISK_LOW_PRES_MIN_DAYS   = 5;
private const RISK_LEAVE_MIN_DAYS      = 5;
```
All risk thresholds are hardcoded private constants. HR administrators cannot adjust the lookback window or minimum days thresholds without code changes. A department with 200 employees on a 2-week rotation may need different thresholds than a department with 10 office employees.

**Impact:** Risk flags may be meaningless for atypical shift patterns. No security risk.
**Fix Recommendation:** Store thresholds in `wp_options` under `sfs_hr_workforce_settings`. Provide admin UI (a settings section) with defaults matching the current constants. This is a Low-priority enhancement, not a bug.

---

### WADM-LOGIC-005 — `render_leave_badge()` Uses English String Matching for Translation-Incompatible Check

**Severity:** Low
**Location:** `Admin/Admin_Pages.php:780`
**Description:**
```php
$is_on_leave = ( stripos( $label, 'On leave' ) === 0 ); // text prefix is stable
```
The `$label` value is produced by `sprintf( __( 'On leave (%s)', 'sfs-hr' ), $r['type_name'] )` (line 1099), which is translated. In Arabic (`ar.json`), this string would begin with an Arabic prefix, not "On leave". The `stripos( $label, 'On leave' )` check would always return `false` for Arabic-locale admin users, meaning all on-leave employees would show with the `leave-duty` (green) CSS class instead of the `leave-on` (blue) class.

**Impact:** Incorrect leave badge color for non-English admin UI. The status categorization is correct (employee is in the `on_leave` tab), but the Leave column color indicator is wrong.
**Fix Recommendation:** Instead of matching on the translated label string, pass a `$is_on_leave` boolean alongside `$leave_label` in the row data, set by the non-translated `isset( $leave_map[ $emp_id ] )` check (line 905). Then `render_leave_badge()` can use the flag instead of string matching.

---

## $wpdb Call-Accounting Table

All direct `$wpdb` calls in `Admin/Admin_Pages.php`:

| Line | Method | Query Type | Prepared | Notes |
|------|--------|------------|----------|-------|
| 814-816 | `get_results()` | SELECT | Conditional (yes if `$params` non-empty; **no** if all-depts path) | See WADM-SEC-002 — static query, no injection risk but convention violation |
| 862-864 | `get_results()` | SELECT | Conditional (yes if any filter active; no if unfiltered all-depts, no search, no dept filter) | Same static-query pattern as above |
| 981 | `get_results()` | SELECT | Yes (`$wpdb->prepare()`) | `get_risk_flags_map()` — correct |
| 1064 | `get_results()` | SELECT | Yes (`$wpdb->prepare()`) | `get_today_punch_map()` — correct |
| 1094 | `get_results()` | SELECT | Yes (`$wpdb->prepare()`) | `get_today_leave_map()` — correct; JOIN with leave_types |
| 1326-1330 | `get_col()` | SELECT | Yes (`$wpdb->prepare()`) | `manager_dept_ids_for_current_user()` — correct |

**Total calls: 6** (across 4 helper methods)
**Prepared: 4 unconditionally prepared; 2 conditionally prepared (safe for current queries, convention violation)**
**Unprepared: 0** (no fully unprepared queries with user-controlled input)

---

## WorkforceStatusModule Orchestrator Assessment

**WFS-ORCH-001 — Clean Bootstrap (No Finding)**
`WorkforceStatusModule.php` (33 lines) contains only:
- `hooks()` method registering `init` action for Admin_Pages bootstrap (is_admin guard)
- `Absent_Cron::hooks()` registration

**Confirmed clean:**
- No `install()` or `activation()` method
- No `ALTER TABLE` statements (bare or otherwise)
- No `SHOW TABLES` calls
- No `information_schema` queries
- No `$wpdb` calls at all

Unlike Loans (Phase 08: LOAN-SEC-002 bare ALTER TABLE), Core (Phase 04: Critical ALTER TABLE in admin_init), and Assets (Phase 11: ASSET-SEC-003 information_schema in log_asset_event), WorkforceStatusModule has no DDL antipatterns. DDL is correctly delegated to Migrations.php.

---

## Status_Analytics Service Assessment

**WFS-SVC-001 — Dead Code: Empty Status_Analytics.php**

**Severity:** Low
**Location:** `Services/Status_Analytics.php` (1 line — PHP opening tag only, no class definition)
**Description:**
The file contains only `<?php` with no class declaration, no methods, no logic. It is an empty placeholder with no functionality. The class `SFS\HR\Modules\Workforce_Status\Services\Status_Analytics` is never referenced or used anywhere in the codebase.

**Impact:** Dead code. No security or functional impact. The autoloader will not fail (the file exists but no class is defined). A `new Status_Analytics()` call anywhere would throw a PHP fatal error.
**Fix Recommendation:** Either implement the service (moving analytics logic out of Admin_Pages into a proper service layer would address WADM-PERF-001/002/003) or delete the file to keep the codebase clean.

---

## Department Scoping Assessment

**Confirmed behavior (no finding):**

Lines 59-71 implement department scoping correctly:
1. `sfs_hr.manage` users get `$allowed_depts = null` → see all departments
2. Non-manage users call `manager_dept_ids_for_current_user()`
3. If the returned array is **empty** (user manages no departments), the code returns early with "No departments assigned to you" — this is correct. The empty array does NOT fall through to a full-org query.

The guard at lines 65-71 correctly blocks data access when `empty( $allowed_depts )` is true, preventing the scenario where a non-manager user could see org-wide data.

---

## Cross-Reference with Prior Phase Findings

| Finding | This Phase | Prior Phase |
|---------|-----------|-------------|
| Friday-only weekend check | WADM-LOGIC-001 | Phase 09 PAY-LOGIC-001, Phase 05 |
| PHP-side pagination / unbounded employee load | WADM-PERF-001 | Phase 11 AVIEW-PERF-001 |
| N+1 per-employee loop with DB queries | WADM-PERF-002 | Phase 07 PERF N+1 (1000+ queries) |
| `is_holiday()` duplication | WADM-DUP-001 | Phase 05 attendance audit (Session_Service) |
| `manager_dept_ids` duplication | WADM-DUP-002 | Phase 09 PADM-DUP-001, Phase 12 |
| Wrong capability constant for menu | WADM-SEC-001 | Phase 13 HADM-SEC-001 |
| `paginate_links()` echoed raw | WADM-SEC-003 | Phase 04 Frontend DashboardTab |
| Conditional prepared vs always-prepared | WADM-SEC-002 | Phase 04 Core, Phase 08 LOAN-SEC |
| information_schema / bare ALTER TABLE | Not present (clean) | Phase 04, 08, 11, 12 |
| Static query unprepared path | WADM-SEC-002 | Phase 09 all-static-query paths |
