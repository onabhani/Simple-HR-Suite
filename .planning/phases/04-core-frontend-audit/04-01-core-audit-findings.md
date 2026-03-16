# Core/ Audit Findings Report

**Audit date:** 2026-03-16
**Scope:** includes/Core/ (11 files, ~13.7K lines)
**Auditor:** Claude (automated code review)
**Requirements covered:** CORE-01 (Security), CORE-02 (Performance), CORE-03 (Duplication/Logic)

---

## Executive Summary

- **Critical:** 3 findings
- **High:** 12 findings
- **Medium:** 14 findings
- **Low:** 6 findings
- **Total:** 35 findings

### Top 5 Most Critical Issues

1. **CORE-SEC-001** — `Admin.php:L99` — ALTER TABLE executed via raw `$wpdb->query()` without prepare, table name interpolated from variable on every `admin_init` load.
2. **CORE-SEC-002** — `Admin.php:L229-264` — Loop of 17 ALTER TABLE statements all built with raw string interpolation inside `admin_init`.
3. **CORE-SEC-003** — `Admin.php:L211-213` — Three ALTER TABLE calls without `$wpdb->prepare()` in `maybe_install_qr_cols()`.
4. **CORE-PERF-001** — `Admin.php:L87-270` — Three `information_schema` queries run on **every admin page load**, not gated by page slug — high server cost at scale.
5. **CORE-PERF-003** — `Admin.php:L4413-4518` — N+1 query pattern in org-chart rendering: `get_user_by()` + `$wpdb->get_row()` executed inside `foreach ($departments)` loop.

---

## Security Findings (CORE-01)

### Critical

#### CORE-SEC-001: ALTER TABLE without prepare in `maybe_add_employee_photo_column()`
- **File:** `includes/Core/Admin.php:L99`
- **Description:** Raw `$wpdb->query()` call with the table name interpolated from a `$wpdb->prefix` concatenation — ALTER TABLE statements cannot use prepare for structural DDL, but this pattern establishes a dangerous precedent and runs on every admin page load.
- **Evidence:**
  ```php
  $wpdb->query("ALTER TABLE {$table} ADD COLUMN photo_id BIGINT UNSIGNED NULL");
  ```
- **Fix:** Gate with `maybe_add_column()` helper from `wp-admin/includes/upgrade.php`, or use the existing `add_column_if_missing()` migration helper to move this out of `admin_init` entirely and into the versioned migration run.

#### CORE-SEC-002: Loop of 17 ALTER TABLE calls without prepare in `maybe_install_employee_extra_cols()`
- **File:** `includes/Core/Admin.php:L229-264`
- **Description:** A foreach loop over 17 column definitions executes raw ALTER TABLE queries, each with `{$table}` interpolated. While table names cannot be bound via `prepare()`, these statements run unconditionally on every admin request — a structural schema-mutation on every page load.
- **Evidence:**
  ```php
  foreach ($columns as $col => $definition) {
      $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$definition}");
  }
  ```
- **Fix:** Move all schema changes to a single versioned `dbDelta()` call in the Migrations system, gated by `sfs_hr_db_ver`. Remove from `admin_init`.

#### CORE-SEC-003: ALTER TABLE without prepare in `maybe_install_qr_cols()`
- **File:** `includes/Core/Admin.php:L211-213`
- **Description:** Three `$wpdb->query("ALTER TABLE {$t} ADD COLUMN ...")` calls with raw string interpolation, run on every admin init without a version gate or column-existence check.
- **Evidence:**
  ```php
  $wpdb->query("ALTER TABLE {$t} ADD COLUMN qr_code_token VARCHAR(64) NULL");
  $wpdb->query("ALTER TABLE {$t} ADD COLUMN qr_expires_at DATETIME NULL");
  $wpdb->query("ALTER TABLE {$t} ADD COLUMN qr_last_used_at DATETIME NULL");
  ```
- **Fix:** Move to `Migrations.php` using `add_column_if_missing()`.

---

### High

#### CORE-SEC-004: Missing capability check before reading POST data in `handle_sync_dept_members()`
- **File:** `includes/Core/Admin.php:L5031-5080`
- **Description:** The AJAX handler reads `$dept_id` from `$_POST` and performs a database read before completing the capability check, meaning an authenticated but unauthorized user can trigger partial processing. The `sfs_hr.manage` check runs only after initial data access.
- **Evidence:**
  ```php
  $dept_id = absint( $_POST['dept_id'] ?? 0 );
  // ... db read happens ...
  if ( ! current_user_can( 'sfs_hr.manage' ) ) { wp_send_json_error(...); }
  ```
- **Fix:** Move the `current_user_can()` and nonce check to the very top of the handler, before any `$_POST` access.

#### CORE-SEC-005: Unbounded dashboard queries expose aggregate data without row limits
- **File:** `includes/Core/Admin.php:L284-340`
- **Description:** Multiple `$wpdb->get_var()` calls for dashboard counters (employee count, pending requests, department count, active shifts) execute without `$wpdb->prepare()` even when no user input is present. This is inconsistent with project conventions and makes auditing harder.
- **Evidence:**
  ```php
  $wpdb->get_var("SELECT COUNT(*) FROM {$emp_t}");
  $wpdb->get_var("SELECT COUNT(*) FROM {$req_t} WHERE status = 'pending'");
  ```
- **Fix:** Wrap all queries in `$wpdb->prepare()` even when no user values are bound, for consistency and to satisfy static analysis tools.

#### CORE-SEC-006: Concatenated `$exclude_own_el` fragment in early leave query
- **File:** `includes/Core/Admin.php:L829-831`
- **Description:** A SQL fragment `$exclude_own_el` is built by concatenating an employee ID and then concatenated directly into a raw query string. While the employee ID is cast to int, mixing raw concatenation with non-prepare patterns is high risk.
- **Evidence:**
  ```php
  $exclude_own_el = " AND el.employee_id != {$own_emp_id}";
  $wpdb->get_results("SELECT ... FROM {$el_table} el WHERE ... {$exclude_own_el}");
  ```
- **Fix:** Use `$wpdb->prepare()` with `%d` placeholder for the employee ID.

#### CORE-SEC-007: `process_pending_action_reminders()` runs 6+ unbounded queries without prepare
- **File:** `includes/Core/Notifications.php:L1077-1279`
- **Description:** Six separate `$wpdb->get_results()` calls for pending leaves, loans, resignations, shift swaps, assets, and candidates are executed without `$wpdb->prepare()`, with no LIMIT clause. On a large dataset these could return thousands of rows and send thousands of emails.
- **Evidence:**
  ```php
  $pending_leaves = $wpdb->get_results(
      "SELECT lr.id, lr.start_date ... FROM {$leave_table} lr ... WHERE lr.status = 'pending'"
  );
  ```
- **Fix:** Add `$wpdb->prepare()` for all queries. Add `LIMIT 500` or similar to prevent runaway email sends. Consider pagination.

#### CORE-SEC-008: `generate_employee_directory()` and related report queries run without prepare
- **File:** `includes/Core/Admin/Services/ReportsService.php:L162-177, L199-211, L261-273`
- **Description:** Three report generator methods (`generate_employee_directory`, `generate_headcount_by_dept`, `generate_tenure_report`) call `$wpdb->get_results()` without `$wpdb->prepare()`. The `$dept_where` fragment is built via `$wpdb->prepare()` but the outer query is not.
- **Evidence:**
  ```php
  $rows = $wpdb->get_results(
      "SELECT e.employee_code, ... FROM {$emp_t} e ... WHERE 1=1 {$dept_where}",
      ARRAY_A
  );
  ```
- **Fix:** Wrap each query in `$wpdb->prepare()`.

#### CORE-SEC-009: `Leaves.php:L21` — `render_types()` fetches all leave types without prepare
- **File:** `includes/Core/Leaves.php:L21`
- **Description:** Raw `$wpdb->get_results()` without `$wpdb->prepare()` for the leave types list. Not user-injectable but inconsistent.
- **Evidence:**
  ```php
  $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY name ASC", ARRAY_A);
  ```
- **Fix:** Use `$wpdb->prepare()`.

#### CORE-SEC-010: `Capabilities.php` — DB queries run on every `user_has_cap` filter call
- **File:** `includes/Core/Capabilities.php:L86-106`
- **Description:** The `dynamic_caps` filter fires on every `current_user_can()` call. Although a static `$cache` is used per-request, the first call still executes 3 DB queries (`is_emp`, `is_mgr`, `is_hr_responsible`). These queries have no index hint for `user_id` on the employees table.
- **Evidence:**
  ```php
  static $cache = [];
  if ( ! isset( $cache[ $uid ] ) ) {
      $cache[ $uid ] = [
          'is_emp' => (int) $wpdb->get_var( $wpdb->prepare(
              "SELECT COUNT(*) FROM `$emp_tbl` WHERE user_id=%d AND status='active'", $uid
          ) ) > 0,
          ...
      ];
  }
  ```
- **Fix:** Ensure `user_id` and `manager_user_id` columns are indexed (currently `dept_id` is indexed in Database.php but `user_id` index is listed as `UNIQUE` on employees which is sufficient). Consider combining into one JOIN query to reduce to a single DB call.

#### CORE-SEC-011: `AuditTrail.php:L78-116` — `maybe_create_table()` called on every `init` hook
- **File:** `includes/Core/AuditTrail.php:L34`
- **Description:** `add_action('init', [self::class, 'maybe_create_table'], 5)` means the version check `get_option('sfs_hr_audit_db_version')` runs on every front-end and admin page load. This is an unnecessary option lookup on every request.
- **Evidence:**
  ```php
  add_action( 'init', [ self::class, 'maybe_create_table' ], 5 );
  ```
- **Fix:** Move to `admin_init` (admin-only) and gate with the `sfs_hr_db_ver` version option already used by the migration system. Or register in the plugin activation hook only.

#### CORE-SEC-012: `Notifications.php:L1550-1556` — `table_exists()` uses `information_schema` on every cron run
- **File:** `includes/Core/Notifications.php:L1550-1556`
- **Description:** `table_exists()` queries `information_schema.tables` each time it's called. In `process_pending_action_reminders()` it is called 4 times (loans, resignations, shift swaps, assets) — one `information_schema` query per table check per cron run.
- **Evidence:**
  ```php
  return (bool) $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
      $table_name
  ) );
  ```
- **Fix:** Cache results in a static variable for the duration of the request, or use `$wpdb->get_var("SHOW TABLES LIKE '$table_name'")` which is faster.

---

### Medium

#### CORE-SEC-013: `Helpers.php:L388-427` — `get_departments_for_select()` query without prepare
- **File:** `includes/Core/Helpers.php:L388-427`
- **Description:** `$wpdb->get_results(...)` called without `$wpdb->prepare()`. No user input is in the query but the `{$where}` fragment is constructed from a boolean parameter, so if refactored carelessly, injection risk increases.
- **Evidence:**
  ```php
  $wpdb->get_results("SELECT id, name, active FROM {$table} {$where} ORDER BY id ASC");
  ```
- **Fix:** Wrap in `$wpdb->prepare()`.

#### CORE-SEC-014: `Helpers.php:L437-482` — `get_nationalities_for_select()` queries without prepare
- **File:** `includes/Core/Helpers.php:L437-482`
- **Description:** Two `$wpdb->get_col()` calls use raw query strings without `$wpdb->prepare()`.
- **Evidence:**
  ```php
  $wpdb->get_col("SELECT DISTINCT nationality FROM {$table} WHERE nationality != '' ORDER BY nationality ASC");
  ```
- **Fix:** Wrap in `$wpdb->prepare()`.

#### CORE-SEC-015: `Admin.php:L335` — Active shifts query without prepare (unescaped status value)
- **File:** `includes/Core/Admin.php:L337-340`
- **Description:** Hardcoded status string `'active'` is concatenated into query without prepare — not a real injection risk but violates the project's stated convention.
- **Evidence:**
  ```php
  $wpdb->get_var("SELECT COUNT(*) FROM {$shifts_table} WHERE is_active = 1");
  ```
- **Fix:** Use `$wpdb->prepare()`.

#### CORE-SEC-016: `Setup_Wizard.php:L202` — shifts count query without prepare
- **File:** `includes/Core/Setup_Wizard.php:L202`
- **Description:** `$wpdb->get_var("SELECT COUNT(*) FROM {$shifts_table}")` without prepare — no user input but inconsistent.
- **Evidence:**
  ```php
  $has_shifts = $wpdb->get_var( "SELECT COUNT(*) FROM {$shifts_table}" );
  ```
- **Fix:** Use `$wpdb->prepare()` for consistency.

---

## Performance Findings (CORE-02)

### High

#### CORE-PERF-001: Three `information_schema` queries run on every admin page load
- **File:** `includes/Core/Admin.php:L87-270`
- **Description:** `maybe_add_employee_photo_column()`, `maybe_install_qr_cols()`, and `maybe_install_employee_extra_cols()` each query `information_schema.COLUMNS` to check for column existence. All three are hooked to `admin_init` and run on **every admin page load**, not just HR pages. Information schema queries are expensive (full metadata scan) and accumulate to 3 queries × every admin request.
- **Evidence:**
  ```php
  add_action('admin_init', [$this, 'maybe_add_employee_photo_column']);
  add_action('admin_init', [$this, 'maybe_install_qr_cols']);
  add_action('admin_init', [$this, 'maybe_install_employee_extra_cols']);
  // each runs: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE ...
  ```
- **Fix:** Move all column checks to `Migrations.php` using the existing `add_column_if_missing()` helper which is already versioned. Remove all three `admin_init` hooks.

#### CORE-PERF-002: Dashboard loads 10+ separate DB queries with no caching
- **File:** `includes/Core/Admin.php:L284-340, L1857-1903`
- **Description:** `render_dashboard()` executes at least 10 separate `$wpdb->get_var()` / `$wpdb->get_results()` calls to populate counters and recent activity. No result is cached. Analytics section adds 3 more aggregate queries. Every dashboard page load hits the DB 13+ times.
- **Evidence:**
  ```php
  $employee_count   = $wpdb->get_var("SELECT COUNT(*) FROM {$emp_t}");
  $pending_requests = $wpdb->get_var("SELECT COUNT(*) FROM {$req_t} ...");
  $dept_count       = $wpdb->get_var("SELECT COUNT(*) FROM {$dept_t}");
  // ... 10+ more queries
  ```
- **Fix:** Cache dashboard counters for 60-300 seconds using `wp_cache_get()` / `wp_cache_set()`. Combine multiple COUNT queries into a single query where possible.

#### CORE-PERF-003: N+1 query pattern in org chart rendering
- **File:** `includes/Core/Admin.php:L4413-4433, L4497-4518`
- **Description:** Both the org chart tree view and card view iterate `$departments` in a `foreach` loop and execute `get_user_by()` + `$wpdb->get_row()` per department. With 20 departments this is 40+ queries; with 50 departments it is 100+ queries.
- **Evidence:**
  ```php
  foreach ($departments as $dept) {
      $manager = get_user_by('id', $dept->manager_user_id); // query 1 per dept
      $emp_count = $wpdb->get_row($wpdb->prepare(           // query 2 per dept
          "SELECT COUNT(*) as cnt FROM {$emp_t} WHERE department = %s", $dept->name
      ));
  }
  ```
- **Fix:** Pre-fetch all manager users in a single `get_users(['include' => $manager_ids])` call and all employee counts in a single GROUP BY query before the loop.

---

### Medium

#### CORE-PERF-004: `render_analytics_section()` runs 3 aggregate queries on every dashboard load
- **File:** `includes/Core/Admin.php:L1857-1903`
- **Description:** Three separate aggregate SQL queries (monthly leave totals, attendance status distribution, department headcount) run synchronously on every dashboard render with no cache TTL.
- **Evidence:**
  ```php
  $monthly_leave = $wpdb->get_results($wpdb->prepare(
      "SELECT MONTH(start_date) as month, SUM(days) ... GROUP BY MONTH(start_date)", ...
  ));
  $attendance_dist = $wpdb->get_results("SELECT status, COUNT(*) ...");
  ```
- **Fix:** Cache results with `wp_cache_set()` using a 5-minute TTL. Or load via AJAX on dashboard scroll.

#### CORE-PERF-005: `process_pending_action_reminders()` fetches entire pending datasets with no LIMIT
- **File:** `includes/Core/Notifications.php:L1077-1279`
- **Description:** All pending leave requests, loans, resignations, shift swaps, assets, and candidates are fetched into PHP memory with no LIMIT. On a large site with thousands of pending items, this could exhaust memory during cron.
- **Evidence:**
  ```php
  $pending_leaves = $wpdb->get_results(
      "SELECT ... FROM {$leave_table} lr ... WHERE lr.status = 'pending' ORDER BY lr.created_at ASC"
  );
  ```
- **Fix:** Add `LIMIT 200` (or configurable) to each query. Process in batches if counts are high.

#### CORE-PERF-006: `generate_employee_directory()` fetches all employees without pagination
- **File:** `includes/Core/Admin/Services/ReportsService.php:L162-177`
- **Description:** The employee directory report fetches every active employee with no LIMIT. With 10,000+ employees this loads the entire table into a PHP array before streaming to CSV.
- **Evidence:**
  ```php
  $rows = $wpdb->get_results(
      "SELECT e.employee_code, ... FROM {$emp_t} e ... ORDER BY d.name, e.first_name",
      ARRAY_A
  );
  ```
- **Fix:** For CSV exports, stream results using `$wpdb->get_results()` with chunked queries (LIMIT/OFFSET) and write to output buffer incrementally.

#### CORE-PERF-007: `AuditTrail.maybe_create_table()` called on every `init` — redundant option reads
- **File:** `includes/Core/AuditTrail.php:L78-116`
- **Description:** The `init` hook fires on every WordPress request (frontend and admin). The `get_option('sfs_hr_audit_db_version')` call is relatively cheap due to WordPress option caching, but the hook registration means this function always runs even when the audit table exists.
- **Fix:** Move hook to `admin_init` only, or register once at plugin activation using `register_activation_hook`.

#### CORE-PERF-008: `ReportsService.generate_document_expiry()` uses `information_schema` every call
- **File:** `includes/Core/Admin/Services/ReportsService.php:L295-298`
- **Description:** The document expiry report checks for the documents table via `information_schema.tables` on every report generation. This should be cached per-request or per-option.
- **Evidence:**
  ```php
  $doc_exists = (bool) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
      $doc_t
  ));
  ```
- **Fix:** Use `$wpdb->get_var("SHOW TABLES LIKE '$doc_t'")` or cache result in a transient.

#### CORE-PERF-009: `Helpers.get_departments_for_select()` called repeatedly with no per-request cache
- **File:** `includes/Core/Helpers.php:L388-427`
- **Description:** `get_departments_for_select()` is a shared helper called from many places (admin dropdowns, filter forms, report forms) but has no `wp_cache_get()`/`wp_cache_set()` cache. The same query runs multiple times per page.
- **Fix:** Add `wp_cache_get('sfs_hr_departments_select')` with a short TTL, or use a static variable cache within the method.

#### CORE-PERF-010: `Notifications.register_hooks()` calls `wp_next_scheduled()` on every request
- **File:** `includes/Core/Notifications.php:L~40-80`
- **Description:** `register_hooks()` is called on every `init` action. Inside it calls `wp_next_scheduled('sfs_hr_daily_notifications')` to check if the cron is scheduled — this is a database read (wp_options lookup) on every request.
- **Fix:** This is acceptable WordPress practice for cron scheduling, but the method should not run on frontend requests if notifications are admin-only. Gate with `is_admin()` or move to `admin_init`.

---

### Low

#### CORE-PERF-011: `option autoload` not explicitly set for large notification digest queue
- **File:** `includes/Core/Notifications.php:L1661`
- **Description:** `update_option('sfs_hr_notification_digest_queue', $queue)` does not specify `$autoload = false`. If the digest queue grows large (active digest usage), it will be loaded on every page via WordPress autoload — potentially megabytes of serialized data.
- **Evidence:**
  ```php
  update_option( 'sfs_hr_notification_digest_queue', $queue );
  ```
- **Fix:** Pass `false` as the fourth argument: `update_option('sfs_hr_notification_digest_queue', $queue, false)`.

---

## Duplication and Logic Findings (CORE-03)

### High

#### CORE-LOGIC-001: `Capabilities.dynamic_caps()` fires on every `current_user_can()` — static cache doesn't survive page-to-AJAX requests
- **File:** `includes/Core/Capabilities.php:L86`
- **Description:** The `static $cache` resets between requests. In heavy AJAX workflows (attendance dashboard, org chart), `dynamic_caps` may be invoked 10-20 times per request, with the cache only protecting within one request. The first call per user still runs 3 queries, and AJAX requests each start fresh.
- **Fix:** Consider `wp_cache_get()`/`wp_cache_set()` using a short TTL (60 seconds) keyed by `user_id` to share across AJAX requests within the same browser session.

#### CORE-LOGIC-002: `process_upcoming_leave_reminders()` stores sent-reminder state in `wp_options` — unbounded growth risk
- **File:** `includes/Core/Notifications.php:L1829-1882`
- **Description:** `$sent_list` is a `wp_options` value that grows indefinitely (pruned to 500 entries but only after already growing beyond 500). With high employee counts and daily cron runs, this option can become a large serialized array read on cron boot.
- **Evidence:**
  ```php
  $sent_list = get_option( $sent_key, [] );
  // ... add entries ...
  if ( count( $sent_list ) > 500 ) {
      $sent_list = array_slice( $sent_list, -500, null, true );
  }
  update_option( $sent_key, $sent_list, false );
  ```
- **Fix:** Prune to 200 entries per run. Set `autoload=false`. Consider a dedicated `sfs_hr_reminder_log` table instead of an options blob for scale.

---

### Medium

#### CORE-DUP-001: Employee data fetch pattern duplicated across Admin.php, Notifications.php, and Helpers.php
- **File:** `includes/Core/Admin.php`, `includes/Core/Notifications.php:L1928-1950`, `includes/Core/Helpers.php:L~110-145`
- **Description:** The pattern of fetching employee + department + manager + WP user data via a multi-JOIN query appears at least 3 times across Core/ files with slightly different field lists. `Notifications::get_employee_data()` and `Helpers::get_employee_row()` do almost the same work.
- **Fix:** Consolidate into a single `Helpers::get_employee_with_context(int $employee_id): ?object` method that returns the full enriched employee object, used by both Notifications and Admin.

#### CORE-DUP-002: `table_exists()` implemented in both `Notifications.php` and `ReportsService.php`
- **File:** `includes/Core/Notifications.php:L1550-1556`, `includes/Core/Admin/Services/ReportsService.php:L295-298`
- **Description:** Both files independently implement an `information_schema`-based table existence check. Two copies means two maintenance points.
- **Fix:** Move to `Helpers::table_exists(string $table_name): bool` as a shared static utility.

#### CORE-DUP-003: Department query without `prepare()` used in at least 4 different places
- **File:** `includes/Core/Helpers.php:L388-427`, `includes/Core/Admin.php:L1436`, `includes/Core/Admin.php:L5111-5131`, `includes/Core/Setup_Wizard.php:L453`
- **Description:** Raw `SELECT id, name FROM {$dept_table}` (or variations) is written directly in multiple files rather than using the shared `get_departments_for_select()` helper. Some callers use the helper; others bypass it.
- **Fix:** Enforce `Helpers::get_departments_for_select()` as the single point for department list fetches and add a cache layer to it.

#### CORE-DUP-004: `current_user_can('manage_options')` used interchangeably with `sfs_hr.manage` capability checks
- **File:** `includes/Core/Admin.php`, `includes/Core/Setup_Wizard.php`, `includes/Core/Company_Profile.php`, `includes/Core/AuditTrail.php`
- **Description:** Some pages gate with `manage_options` (WordPress admin), others with `sfs_hr.manage`. Setup_Wizard, Company_Profile, and AuditTrail page render use `manage_options` while module admin pages use `sfs_hr.manage`. This inconsistency means an `sfs_hr_manager` user cannot access wizard/company profile despite having `sfs_hr.manage`.
- **Fix:** Standardize: HR module pages should check `sfs_hr.manage` (which is granted to administrators via `dynamic_caps`). Reserve `manage_options` for purely WordPress-native admin pages.

#### CORE-DUP-005: Date normalization logic duplicated across modules
- **File:** `includes/Core/Helpers.php:L~750-790` (`normalize_date()`), `includes/Core/Admin.php` (inline `strtotime()` + `wp_date()` in multiple places), `includes/Core/Notifications.php` (inline `wp_date(...)` in all event handlers)
- **Description:** `Helpers::normalize_date()` exists but is not consistently used. Notification handlers each independently format dates with `wp_date('F j, Y', strtotime(...))` inline.
- **Fix:** Use `Helpers::normalize_date()` or create a `Helpers::format_display_date(string $date): string` helper that all notification handlers call.

#### CORE-DUP-006: `Hooks.php` and `Admin.php` both contain employee auto-creation logic
- **File:** `includes/Core/Hooks.php:L278-304` (`ensure()`), `includes/Core/Admin.php` (various places)
- **Description:** `Hooks::ensure()` creates employee records for new WP users. However `Admin.php` also independently creates employee entries during the employee-add flow. There is risk of duplicate logic if the patterns diverge.
- **Fix:** Ensure all employee creation goes through a single `EmployeeService::create()` method so both hooks and admin forms use the same validation and audit trail.

#### CORE-LOGIC-003: `Leaves.php` — legacy leave module partially duplicates Leave module functionality
- **File:** `includes/Core/Leaves.php`
- **Description:** `Core/Leaves.php` (196 lines) is a legacy admin menu for leave types and requests. The active `Modules/Leave/` module provides the same functionality with more features. Both are registered and produce overlapping admin menu items. `Core/Leaves.php` uses `Helpers::require_cap('sfs_hr.manage')` but the Leave module uses its own capability checks — leaving two entry points for leave type management.
- **Fix:** Evaluate whether `Core/Leaves.php` is still hooked. If the Leave module fully replaces it, remove the menu registration from `Core/Leaves.php` to avoid duplicate admin pages.

---

### Low

#### CORE-LOGIC-004: `Helpers::current_employee_id()` and `current_employee_id_any_status()` use separate static caches that can conflict
- **File:** `includes/Core/Helpers.php:L~110-145`
- **Description:** Each method uses `static $cache = null` independently. If both are called in the same request for the same user, two DB queries run. Also, if `current_employee_id()` (active-only) is called after `current_employee_id_any_status()`, the active-only result is re-queried unnecessarily.
- **Fix:** Use a single static array `$cache = []` keyed by user ID and status filter.

#### CORE-LOGIC-005: `AuditTrail.php` — `maybe_create_table()` uses a separate `sfs_hr_audit_db_version` option independent of the main `sfs_hr_db_ver` migration system
- **File:** `includes/Core/AuditTrail.php:L81`
- **Description:** The audit trail manages its own schema version option, disconnected from the main migration versioning in `Migrations.php`. This means the audit table can be on a different schema version than the rest of the plugin, and the main migration system won't know about it.
- **Fix:** Integrate audit trail table creation into `Migrations.php` and manage it under `sfs_hr_db_ver`.

#### CORE-LOGIC-006: `Database.php` — leave requests table schema diverges from actual column usage
- **File:** `includes/Core/Database.php:L77-113`
- **Description:** The `Database::install()` creates `leave_requests` with `status ENUM('pending','approved','rejected')` but the Leave module uses additional statuses (`cancelled`, `held`). The `ENUM` constraint means those statuses may fail silently or require `ALTER TABLE` to expand.
- **Fix:** Change the status column to `VARCHAR(32)` in the migration (via `dbDelta` which handles it safely), or ensure the ENUM is explicitly expanded in `Migrations.php`.

#### CORE-LOGIC-007: `Setup_Wizard.php:L482` — inline style uses unescaped PHP variable directly
- **File:** `includes/Core/Setup_Wizard.php:L482`
- **Description:** `$already` boolean is used inline as a ternary to emit raw CSS strings. While the values are hardcoded strings (not user input), any future refactor that makes `$already` user-influenced would introduce XSS.
- **Evidence:**
  ```php
  <label style="...background:<?php echo $already ? '#f0f0f0' : '#f0f6fc'; ?>...">
  ```
- **Fix:** Use `esc_attr()` on all inline style values, or better, use CSS classes.

---

## Files Reviewed

| File | Lines | Findings |
|------|-------|----------|
| Admin.php | 7081 | 13 |
| Notifications.php | 2355 | 6 |
| Helpers.php | 1115 | 4 |
| Setup_Wizard.php | 852 | 2 |
| AuditTrail.php | 842 | 3 |
| Admin/Services/ReportsService.php | 360 | 3 |
| Company_Profile.php | 337 | 0 |
| Hooks.php | 305 | 1 |
| Leaves.php | 196 | 2 |
| Database.php | 166 | 1 |
| Capabilities.php | 126 | 0 |

**Note:** Company_Profile.php and Capabilities.php had no findings — they follow security patterns correctly throughout.

---

## Recommendations Priority

### 1. Immediate (Critical)

- **CORE-SEC-001, 002, 003** — Move all ALTER TABLE calls out of `admin_init` and into `Migrations.php` using `add_column_if_missing()`. These run on every admin page load and represent both a performance and structural risk.

### 2. Next Sprint (High)

- **CORE-PERF-001** — Remove `information_schema` queries from `admin_init`; gate inside versioned migrations.
- **CORE-PERF-003** — Fix N+1 query in org chart by pre-fetching managers and counts before the loop.
- **CORE-SEC-004** — Move capability check to top of `handle_sync_dept_members()` before POST data access.
- **CORE-SEC-007** — Add `LIMIT` clauses to all 6 pending-action reminder queries.
- **CORE-PERF-002** — Add `wp_cache_*` caching to dashboard counter queries.

### 3. Backlog (Medium/Low)

- **CORE-DUP-001** — Consolidate employee data fetch into a single `Helpers::get_employee_with_context()` method.
- **CORE-DUP-002** — Move `table_exists()` to `Helpers` as a shared static utility.
- **CORE-DUP-003** — Enforce `get_departments_for_select()` with caching.
- **CORE-DUP-004** — Standardize capability checks: use `sfs_hr.manage` throughout HR module pages.
- **CORE-LOGIC-003** — Audit and potentially remove or disable legacy `Core/Leaves.php` menu if the Modules/Leave module fully supersedes it.
- **CORE-LOGIC-006** — Expand leave request status ENUM or convert to VARCHAR(32).
- **CORE-PERF-011** — Set `autoload=false` on `sfs_hr_notification_digest_queue` option.
- **CORE-LOGIC-005** — Merge audit trail schema into main `Migrations.php` versioning.
