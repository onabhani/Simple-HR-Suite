# Projects Module Audit Findings

**Audited:** 2026-03-17
**Files:** 3 files, ~1,160 lines
**Module Status:** Stub / incomplete (per CLAUDE.md)

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High     | 5 |
| Medium   | 5 |
| Low      | 3 |

**Overall assessment:** The Projects module is architecturally cleaner than most modules audited in this series. All 6 POST handlers have correct dual guards (capability + nonce). The service layer uses `$wpdb->insert()`/`$wpdb->update()`/`$wpdb->prepare()` correctly across almost all paths. The module is stub/incomplete — several features are scaffolded but not wired (no REST API, no frontend shortcode, no notification system, no terminated-employee cleanup). The one Critical finding is the `information_schema` antipattern in `get_employee_project_on_date()`, consistent with the recurring pattern across phases 04/08/11/12/16.

---

## Security Findings

### PROJ-SEC-001 [Critical]: information_schema query in get_employee_project_on_date()

- **File:** `includes/Modules/Projects/Services/class-projects-service.php:218-225`
- **Impact:** On every call to `get_employee_project_on_date()` (invoked by the Attendance module's shift resolver for each punch-in/out), the first execution per request hits `information_schema.tables`. The static `$table_exists_cache` is per-request only — it does NOT persist across requests. This means every page load that triggers shift resolution (kiosk punch, attendance admin page, workforce status page) runs an `information_schema` query. This is the 6th recurrence of this antipattern across the audit series (Phase 04/08/11/12/16).
- **Code:**
  ```php
  $table_exists_cache[ $pe ] = (bool) $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
      $pe
  ) );
  ```
- **Fix:** Replace with `SHOW TABLES LIKE %s` pattern (prepared, no `information_schema`) and store the result in a WordPress transient (e.g., `sfs_hr_projects_tables_exist`, TTL 24h, invalidated on `maybe_install_tables()` run). Alternatively, since `maybe_install_tables()` always runs on `admin_init` and creates the tables, remove the existence check entirely and let the JOIN query fail gracefully with `$wpdb->suppress_errors()`.

---

### PROJ-SEC-002 [High]: wp_dropdown_users() shows all WordPress users for project manager assignment

- **File:** `includes/Modules/Projects/Admin/class-admin-pages.php:181-188`
- **Impact:** The project manager dropdown (`wp_dropdown_users()`) lists every WordPress user on the site — including subscribers, customers, and users from other plugins — with no filtering to HR roles. Any user selected becomes the `manager_user_id` for the project. While this field has no runtime capability effect (no dynamic capability grant, unlike department `manager_user_id`), it exposes all WP user accounts and may mislead HR administrators.
- **Code:**
  ```php
  wp_dropdown_users( [
      'name'             => 'manager_user_id',
      'id'               => 'prj-manager',
      'selected'         => $project->manager_user_id ?? 0,
      'show_option_none' => __( '— None —', 'sfs-hr' ),
      'option_none_value'=> 0,
  ] );
  ```
- **Fix:** Add `'role__in' => ['administrator', 'sfs_hr_manager', 'sfs_hr_employee']` (or a project-specific HR role set) to restrict the dropdown to users with HR-related roles. Additionally, validate on save in `handle_save_project()` that the submitted `manager_user_id` belongs to an allowed role.

---

### PROJ-SEC-003 [High]: manager_user_id not validated on save

- **File:** `includes/Modules/Projects/Admin/class-admin-pages.php:619`
- **Impact:** `handle_save_project()` accepts any integer as `manager_user_id` from POST data without verifying that the user exists or holds an appropriate role. While there is no dynamic capability grant for project managers (unlike department managers), storing an arbitrary user ID is a data integrity issue and a stepping stone risk if the field gains runtime significance in future.
- **Code:**
  ```php
  'manager_user_id' => (int) ( $_POST['manager_user_id'] ?? 0 ) ?: null,
  ```
- **Fix:** Add `get_userdata( $manager_user_id )` validation before storing. If user does not exist, store `null` and surface a save error.

---

### PROJ-SEC-004 [High]: Inline $wpdb queries in render_view() violate service layer pattern

- **File:** `includes/Modules/Projects/Admin/class-admin-pages.php:283-284`
- **Impact:** Two `$wpdb->get_results()` calls are made directly in the admin view controller for loading all shifts and all employees for the assignment dropdowns. These queries are static (no user-controlled interpolation), so there is no SQL injection risk. However, they violate the project's controller/view separation pattern — the admin pages controller is not a service layer. This is the same architectural violation noted in Phase 11 (AVIEW-SEC-001/002) and Phase 17 (SSADM delegation pattern as a positive example). In future, if these queries are copy-pasted, they may acquire user-controlled parameters.
- **Code:**
  ```php
  $all_shifts    = $wpdb->get_results( "SELECT id, name, start_time, end_time FROM {$wpdb->prefix}sfs_hr_attendance_shifts WHERE active = 1 ORDER BY name" );
  $all_employees = $wpdb->get_results( "SELECT id, first_name, last_name, employee_code FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active' ORDER BY first_name, last_name" );
  ```
- **Fix:** Move these queries to `Projects_Service` as `get_available_shifts()` and `get_assignable_employees()` static methods. The admin controller should call service methods only.

---

### PROJ-SEC-005 [Medium]: No duplicate assignment guard on employee assignment

- **File:** `includes/Modules/Projects/Services/class-projects-service.php:114-126`
- **Impact:** `assign_employee()` performs no check for existing overlapping assignments before inserting. The same employee can be assigned to the same project multiple times with identical or overlapping date ranges. The `sfs_hr_project_employees` table has no UNIQUE constraint on `(project_id, employee_id)`. This leads to duplicate rows in the employee list and inflated counts in `get_employee_counts()`.
- **Fix:** Add a UNIQUE constraint on `(project_id, employee_id, assigned_from)` in the schema. Alternatively, add a pre-insert check: `SELECT COUNT(*) FROM {$t} WHERE project_id = %d AND employee_id = %d AND assigned_from = %s` and reject duplicates. `wpdb->insert()` on duplicate should use `INSERT IGNORE` or `ON DUPLICATE KEY UPDATE` pattern.

---

## Performance Findings

### PROJ-PERF-001 [High]: N+1 query pattern in project attendance dashboard

- **File:** `includes/Modules/Projects/Admin/class-admin-pages.php:511-518`
- **Impact:** `render_project_dashboard()` calls `Attendance_Metrics::get_employee_metrics()` in a loop for each assigned employee. Each call to `get_employee_metrics()` executes multiple attendance queries internally. For a project with 50 employees over a 30-day range, this can generate 200-500 queries on a single page load. This is the same N+1 pattern as Phase 07 (PERF finding, 1,000+ queries per call on 200 employees), though narrowed to project scope.
- **Code:**
  ```php
  foreach ( $employees as $emp ) {
      $metrics = \SFS\HR\Modules\Performance\Services\Attendance_Metrics::get_employee_metrics(
          (int) $emp->employee_id,
          $start_date,
          $end_date
      );
      ...
  }
  ```
- **Fix:** `Attendance_Metrics` would need a batch variant (e.g., `get_batch_metrics( array $employee_ids, string $from, string $to ): array`). Until that is implemented, add a hard cap on the number of employees displayed in the dashboard (e.g., `LIMIT 50` on `get_project_employees()` for dashboard mode) and paginate. Log a TODO comment referencing this finding.

---

### PROJ-PERF-002 [High]: Unbounded SELECT on project listing and employee loading

- **File:** `includes/Modules/Projects/Services/class-projects-service.php:18`, `includes/Modules/Projects/Admin/class-admin-pages.php:284`
- **Impact:** `Projects_Service::get_all()` has no LIMIT — all projects are loaded for the list page. Similarly, the inline `$all_employees` query on line 284 fetches all active employees with no LIMIT. In an organization with 500+ employees or 200+ projects, these queries impose unnecessary memory and DB load. While projects are a bounded dataset in practice, the pattern is not future-safe.
- **Fix:** Add `LIMIT` + pagination to `get_all()`. Add `LIMIT 500` to the employee dropdown query. For the dropdown, prefer an AJAX-powered select2/autocomplete over a static dropdown for large employee counts.

---

### PROJ-PERF-003 [Medium]: get_employee_counts() counts all assignments, not active/date-filtered

- **File:** `includes/Modules/Projects/Services/class-projects-service.php:261-276`
- **Impact:** The project list page shows an "Employees" count column. `get_employee_counts()` counts all assignment rows (`COUNT(*)`) without filtering by `assigned_to IS NULL OR assigned_to >= CURDATE()`. This means terminated assignments from years ago inflate the displayed count, misleading HR managers about how many employees are currently on the project.
- **Fix:** Add a date filter to `get_employee_counts()`: `WHERE project_id IN ({$placeholders}) AND (assigned_to IS NULL OR assigned_to >= CURDATE())`.

---

## Duplication Findings

### PROJ-DUP-001 [Medium]: Employee listing query duplicated from Employees module

- **File:** `includes/Modules/Projects/Admin/class-admin-pages.php:284`
- **Impact:** The inline query `SELECT id, first_name, last_name, employee_code FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active' ORDER BY first_name, last_name` is a pattern duplicated across multiple modules (Departments, ShiftSwap, Hiring, Projects). Each module fetches active employees independently with slightly different column sets. There is no shared `Helpers::get_active_employees()` method in `Core/Helpers`.
- **Fix:** Add a static `get_active_employees( array $columns = ['id','first_name','last_name','employee_code'] ): array` helper to `SFS\HR\Core\Helpers`. All modules should call this helper. This was noted as a recurring pattern in Phase 16 and Phase 17.

---

### PROJ-DUP-002 [Low]: Date validation logic duplicated from other admin controllers

- **File:** `includes/Modules/Projects/Admin/class-admin-pages.php:471-483`
- **Impact:** The `render_project_dashboard()` method implements its own inline Y-m-d format validation (regex + `DateTimeImmutable::createFromFormat`) and start <= end clamping. The same validation logic appears in at least 3 other admin pages (Attendance, Payroll, Workforce_Status). No shared date validation helper exists in `Core/Helpers`.
- **Fix:** Extract a `Helpers::validate_date_range( string $from, string $to, string $default_from, string $default_to ): array` helper and use it across modules.

---

## Logical Findings

### PROJ-LOGIC-001 [High]: No terminated-employee cleanup from project assignments

- **File:** `includes/Modules/Projects/` (no cron or hook exists)
- **Impact:** When an employee is terminated (status = 'terminated'), their active project assignments are not ended. The employee will continue to appear in project dashboards, attendance metric calculations, and assignment counts. This creates data noise and — if project-based attendance is used for payroll or compliance — can produce incorrect reports.
- **Fix:** Add a hook in `ProjectsModule::hooks()` that listens for employee termination (or a `sfs_hr_employee_terminated` action if one is fired). On termination, set `assigned_to = last_working_day` for all active assignments for that employee. Alternatively, filter terminated employees in `get_project_employees()` by joining on `employees.status = 'active'`.

---

### PROJ-LOGIC-002 [Medium]: No project lifecycle state machine

- **File:** `includes/Modules/Projects/Services/class-projects-service.php` / `ProjectsModule.php`
- **Impact:** Projects have a binary `active` flag (1/0) but no formal status lifecycle (e.g., Draft → Active → Completed → Archived). The `end_date` field is informational only — a project with a past `end_date` remains `active = 1` unless manually toggled. There is no cron job to automatically mark projects inactive after their end date. An HR manager looking at the project list sees stale active projects that ended months ago.
- **Fix:** Add a cron job (e.g., `sfs_hr_projects_daily_cleanup`) that sets `active = 0` for projects where `end_date < CURDATE()`. Or introduce a formal `status` column with allowed transitions and a cron to advance stale projects.

---

### PROJ-LOGIC-003 [Medium]: No overlap check when assigning employee to multiple projects

- **File:** `includes/Modules/Projects/Services/class-projects-service.php:114-126`
- **Impact:** An employee can be assigned to multiple projects with identical or overlapping date ranges without any warning or guard. The `get_employee_project_on_date()` resolver handles this by returning `ORDER BY pe.id DESC LIMIT 1` (last assignment wins), but this silent "last wins" resolution is not visible in the UI or surfaced as a conflict to the assigning manager. An employee could appear to work on two projects simultaneously with no indication.
- **Fix:** Before inserting an assignment, check for existing non-ended assignments for the employee across all projects: `SELECT COUNT(*) FROM {$pe} WHERE employee_id = %d AND (assigned_to IS NULL OR assigned_to >= %s) AND assigned_from <= %s`. If overlap found, return an error and surface it in the admin UI.

---

### PROJ-LOGIC-004 [Low]: maybe_install_tables() DDL not using dbDelta()

- **File:** `includes/Modules/Projects/ProjectsModule.php:57-101`
- **Impact:** `CREATE TABLE IF NOT EXISTS` via raw `$wpdb->query()` is used instead of `dbDelta()`. This means future schema changes to the Projects tables cannot be handled automatically — a new column cannot be added without a separate `add_column_if_missing()` call. This is a low severity divergence from the `Migrations.php` pattern (`add_column_if_missing()` helper). The current schema has no columns that need migration, so there is no immediate harm.
- **Fix:** Move the Projects table DDL into `Migrations.php` following the `CREATE TABLE IF NOT EXISTS` + `add_column_if_missing()` pattern used by all other modules, and remove `maybe_install_tables()` from `ProjectsModule.php`. Update the version check to use the global `sfs_hr_db_ver` option.

---

### PROJ-LOGIC-005 [Low]: get_employee_project_on_date() ORDER BY pe.id DESC — silent last-wins

- **File:** `includes/Modules/Projects/Services/class-projects-service.php:234`
- **Impact:** When an employee has multiple overlapping project assignments, the resolver returns the most recently created assignment (`ORDER BY pe.id DESC LIMIT 1`). This is a silent disambiguation that can produce unexpected shift resolution behavior — if a manager creates an earlier-dated assignment after a later-dated one, the order of insertion (not the logical date priority) determines which project's shift applies.
- **Fix:** Change to `ORDER BY pe.assigned_from DESC LIMIT 1` to use the most recently started assignment as the priority, which is semantically correct. Document the tie-breaking rule.

---

## $wpdb Query Catalogue

All `$wpdb` calls across the 3 audited files:

| # | File | Line | Method | Prepared? | Notes |
|---|------|------|--------|-----------|-------|
| 1 | ProjectsModule.php | 57 | `query()` | N/A — DDL | `CREATE TABLE IF NOT EXISTS` — static DDL, no user values |
| 2 | ProjectsModule.php | 77 | `query()` | N/A — DDL | `CREATE TABLE IF NOT EXISTS` — static DDL, no user values |
| 3 | ProjectsModule.php | 92 | `query()` | N/A — DDL | `CREATE TABLE IF NOT EXISTS` — static DDL, no user values |
| 4 | Admin_Pages.php | 283 | `get_results()` | No (static) | Shift dropdown: static query, no user input in SQL |
| 5 | Admin_Pages.php | 284 | `get_results()` | No (static) | Employee dropdown: static query, no user input in SQL |
| 6 | Projects_Service.php | 18 | `get_results()` | No (static) | `get_all()`: static query with PHP bool flag only |
| 7 | Projects_Service.php | 27 | `get_row()` + `prepare()` | Yes | `get()` by ID |
| 8 | Projects_Service.php | 37 | `insert()` | Yes (implicit) | `insert()` |
| 9 | Projects_Service.php | 51 | `update()` | Yes (implicit) | `update()` |
| 10 | Projects_Service.php | 60 | `query()` | N/A | `START TRANSACTION` |
| 11 | Projects_Service.php | 63 | `delete()` | Yes (implicit) | delete assignments |
| 12 | Projects_Service.php | 65 | `query()` | N/A | `ROLLBACK` |
| 13 | Projects_Service.php | 69 | `delete()` | Yes (implicit) | delete shift links |
| 14 | Projects_Service.php | 71 | `query()` | N/A | `ROLLBACK` |
| 15 | Projects_Service.php | 75 | `delete()` | Yes (implicit) | delete project |
| 16 | Projects_Service.php | 77 | `query()` | N/A | `ROLLBACK` |
| 17 | Projects_Service.php | 81 | `query()` | N/A | `COMMIT` |
| 18 | Projects_Service.php | 108 | `get_results()` + `prepare()` | Yes | `get_project_employees()` |
| 19 | Projects_Service.php | 117 | `insert()` | Yes (implicit) | `assign_employee()` |
| 20 | Projects_Service.php | 133 | `delete()` | Yes (implicit) | `remove_employee_assignment()` |
| 21 | Projects_Service.php | 147 | `get_results()` + `prepare()` | Yes | `get_project_shifts()` |
| 22 | Projects_Service.php | 165 | `query()` | N/A | `START TRANSACTION` |
| 23 | Projects_Service.php | 169 | `update()` | Yes (implicit) | clear existing defaults |
| 24 | Projects_Service.php | 171 | `query()` | N/A | `ROLLBACK` |
| 25 | Projects_Service.php | 176 | `insert()` | Yes (implicit) | insert shift link |
| 26 | Projects_Service.php | 184 | `query()` | N/A | `ROLLBACK` |
| 27 | Projects_Service.php | 189 | `query()` | N/A | `COMMIT` |
| 28 | Projects_Service.php | 196 | `delete()` | Yes (implicit) | `remove_shift()` |
| 29 | Projects_Service.php | 218 | `get_var()` + `prepare()` | Yes | information_schema check — PROJ-SEC-001 |
| 30 | Projects_Service.php | 227 | `get_row()` + `prepare()` | Yes | get employee project on date |
| 31 | Projects_Service.php | 246 | `get_var()` + `prepare()` | Yes | get default shift |
| 32 | Projects_Service.php | 268 | `get_results()` + `prepare()` | Yes | `get_employee_counts()` |
| 33 | Projects_Service.php | 285 | `get_row()` + `prepare()` | Yes | `get_assignment()` |
| 34 | Projects_Service.php | 293 | `get_row()` + `prepare()` | Yes | `get_shift_link()` |

**SQL injection risk: NONE.** All 34 calls are either static DDL, prepared with `$wpdb->prepare()`, or use `$wpdb->insert()`/`$wpdb->update()`/`$wpdb->delete()` (which escape internally). The 2 inline queries in Admin_Pages.php (lines 283-284) are static SQL with no user-controlled values — no injection risk, but violate service layer pattern (PROJ-SEC-004).

---

## Stub/Incomplete Code Assessment

### 1. No REST API

**Exists:** ProjectsModule registers no REST routes. No `Rest/` subdirectory exists.

**Missing:** The Attendance module's `get_employee_project_on_date()` caller in shift resolution needs to call the service directly — this works because it's a PHP call, not REST. But there is no REST endpoint for:
- Listing/creating/editing projects (mobile kiosk or frontend access)
- Querying which project an employee is assigned to on a given date (needed if the kiosk is to use project-based geofencing)
- Project attendance summary

**Risk:** The `location_lat/lng/location_radius_m` fields exist in the schema and are stored, but they are never used in geofence validation. The kiosk attendance module does not read project geofence data. These fields are dead data.

### 2. No Frontend Shortcode

**Exists:** No `Frontend/` subdirectory. No shortcode is registered.

**Missing:** Employees cannot view their own project assignments from the frontend portal. The `sfs_hr_project_employees` table has `assigned_from`/`assigned_to` dates but there is no employee-facing view.

**Risk:** Low — employees cannot see or modify assignments. The absence is benign but means the feature is invisible to employees.

### 3. No Notification System

**Exists:** No `Notifications/` subdirectory. No email or in-app notification is sent on:
- Project assignment
- Project removal
- Project deletion (which removes all assignments silently)

**Missing:** When an employee is assigned to a project by a manager, no notification is sent to the employee. When a project is deleted (including all assignments), no notification is sent to any assigned employee.

**Risk:** Medium — silent mass-removal of assignments on project delete (PROJ-LOGIC-001 adjacent). Managers may delete a project accidentally and assigned employees are never informed.

### 4. Geofence Integration is Dead Code

**Exists:** Schema stores `location_lat`, `location_lng`, `location_radius_m` per project. `get_employee_project_on_date()` returns the project row with these fields.

**Missing:** Nothing in AttendanceModule reads project geofence data for kiosk validation. The `default_shift_id` field is attached to the row but is not currently consumed by any caller in the codebase (requires grep confirmation of callers).

**Risk:** Medium — HR managers may configure geofence data believing it is enforced, when it is not. This creates a false sense of security for project-based attendance enforcement.

### 5. No Cron / Lifecycle Management

**Exists:** No `Cron/` subdirectory. No scheduled job exists.

**Missing:**
- Auto-inactivate projects past their `end_date` (PROJ-LOGIC-002)
- Auto-end assignments for terminated employees (PROJ-LOGIC-001)
- Periodic sync to update `active` based on `start_date`/`end_date`

**Risk:** Stale data accumulates silently.

---

## Cross-Module Patterns

| Pattern | This Module | Prior Occurrences |
|---------|-------------|-------------------|
| `information_schema.tables` check | PROJ-SEC-001 (Projects_Service.php:218) | Phase 04 (Core), Phase 08 (Loans), Phase 11 (Assets), Phase 12 (Employees), Phase 16 (Documents) — 6th recurrence |
| Bare `ALTER TABLE` in bootstrap | NOT present — CLEAN | Phase 04 (Core), Phase 08 (Loans), Phase 16 (Documents) |
| Wrong capability at admin gate | NOT present — `sfs_hr.manage` is correct | Phase 15 (WADM-SEC-001), Phase 16 (DADM-SEC-001) |
| `__return_true` REST routes | NOT present — CLEAN | Phase 05 (Attendance, Critical) |
| Missing nonce + capability dual guard | NOT present — all 6 handlers CLEAN | Phase 13 (Hiring, Critical), Phase 07 (Performance) |
| N+1 query loop | PROJ-PERF-001 (dashboard metrics loop) | Phase 04 (org chart), Phase 07 (ranking), Phase 09 (payroll), Phase 15 (workforce) |
| Unbounded SELECT without LIMIT | PROJ-PERF-002 (get_all, employee dropdown) | Phase 08 (Loans), Phase 11 (Assets list), Phase 15 (workforce) |
| Inline $wpdb in admin controller | PROJ-SEC-004 (lines 283-284) | Phase 11 (AVIEW-SEC-001/002), Phase 04 (DashboardTab, ApprovalsTab) |
| Missing department scoping on data reads | PROJ-LOGIC-003 (overlapping project assignments) | Phase 11 (AVIEW-LOGIC-001), Phase 14 (RES-SEC-001), Phase 17 (SSADM) |
| No terminated-employee cleanup hook | PROJ-LOGIC-001 | Phase 06 (Leave balances), Phase 09 (Payroll active employee filter) |
| DDL outside Migrations.php | PROJ-LOGIC-004 | Phase 08 (Loans), Phase 16 (Documents) |

### Positive Patterns (Better Than Prior Modules)

- **Transactional deletes:** `delete()` and `add_shift()` both wrap multi-table operations in `START TRANSACTION / COMMIT / ROLLBACK` — the only module in the audit series to use transactions proactively. Contrast with Phase 13 (Hiring, HIR-LOGIC-001 Critical: no transactions on conversion workflows).
- **Assignment ownership verification:** `handle_remove_employee()` and `handle_remove_shift()` both verify that the assignment/link belongs to the submitted `project_id` before deletion — correct IDOR prevention. Contrast with Phase 16 (DADM-LOGIC-001: no ownership cross-validation).
- **Full dual-guard on all handlers:** All 6 POST handlers check `current_user_can('sfs_hr.manage')` before `check_admin_referer()`. Correct order. No handler uses nonce-only guard. Contrast with Phase 13 (HADM-SEC-002: 6 handlers with no capability check).
- **Service delegation pattern:** Admin_Pages delegates all DB operations to Projects_Service except for the 2 inline dropdown queries (flagged as PROJ-SEC-004). This is better than Phase 04 (Frontend tabs with direct $wpdb in views) and Phase 12 (my-profile.php inline queries).
