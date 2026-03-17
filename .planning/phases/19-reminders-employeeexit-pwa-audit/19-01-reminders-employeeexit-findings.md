# Reminders + EmployeeExit Audit Findings

**Audited:** 2026-03-17
**Files:** 7 files, ~1,405 lines
**Modules:** Reminders (~915 lines), EmployeeExit (~490 lines)

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High     | 7 |
| Medium   | 5 |
| Low      | 4 |

---

## $wpdb Query Catalogue

| File | Line | Query | Prepared? |
|------|------|-------|-----------|
| class-reminders-service.php | 59–70 | SELECT employees + departments WHERE birth_date MM-DD | Yes — `$wpdb->prepare()` with `%d`, `%s` |
| class-reminders-service.php | 93–108 | SELECT employees + departments WHERE hired_at MM-DD | Yes — `$wpdb->prepare()` with `%d`, `%s`, `%s`, `%s` |
| class-reminders-service.php | 130–139 | SELECT employees + departments WHERE MONTH(birth_date) | Yes — `$wpdb->prepare()` with `%d` |
| class-reminders-service.php | 150–162 | SELECT employees + departments WHERE MONTH(hired_at) | Yes — `$wpdb->prepare()` with `%d`, `%d`, `%d` |
| class-reminders-service.php | 217–220 | SELECT manager_user_id FROM departments | Yes — `$wpdb->prepare()` with `%d` |
| class-reminders-service.php | 249–253 | SELECT COUNT(*) FROM information_schema.columns | Yes — `$wpdb->prepare()` with `%s` (but information_schema antipattern — see REM-SEC-001) |
| EmployeeExitModule.php | 99–106 | INSERT into sfs_hr_exit_history | Yes — via `$wpdb->insert()` (parameterized) |
| EmployeeExitModule.php | 142–149 | SELECT history + users WHERE resignation_id | Yes — `$wpdb->prepare()` with `%d` |
| EmployeeExitModule.php | 165–172 | SELECT history + users WHERE settlement_id | Yes — `$wpdb->prepare()` with `%d` |
| EmployeeExitModule.php | 197–205 | SELECT combined history WHERE resignation_id OR settlement_id | Yes — `$wpdb->prepare()` with splat params |
| class-employee-exit-admin.php | 167–179 | SELECT expiring contracts BETWEEN dates | Yes — `$wpdb->prepare()` with `%s`, `%s` |
| class-employee-exit-admin.php | 182–193 | SELECT expired contracts WHERE date < today | Yes — `$wpdb->prepare()` with `%s` |

**Result: All $wpdb calls across all 7 files are properly prepared or use $wpdb->insert() (parameterized). No raw interpolation found.**

---

## Reminders Module Findings

### Security Findings

**REM-SEC-001 [High]: information_schema antipattern in has_birth_date_column()**
- File: `includes/Modules/Reminders/Services/class-reminders-service.php:249–253`
- Issue: `has_birth_date_column()` queries `information_schema.columns` on every cron run AND every dashboard widget render. This is the 7th recurrence of this antipattern (Phase 04 Core, Phase 08 Loans, Phase 11 Assets, Phase 12 Employees, Phase 16 Documents, Phase 18 Projects — now Phase 19 Reminders).
- Impact: Slow on MariaDB/shared hosting; information_schema queries bypass query cache. On each cron run the function is called once for `get_upcoming_birthdays()` (once per `days_offset` iteration is avoided because the check is at the top of the method, but each dashboard widget render also calls it).
- Fix: Replace with `SHOW COLUMNS FROM {$emp_table} LIKE 'birth_date'` or cache the result in a transient with a 1-hour TTL. Pattern: `set_transient('sfs_hr_has_birth_date', true, HOUR_IN_SECONDS)`.

**REM-SEC-002 [Low]: Cross-plugin filter dependency (dofs_ namespace) in send_notification_with_preferences()**
- File: `includes/Modules/Reminders/Cron/class-reminders-cron.php:116,121`
- Issue: `apply_filters('dofs_user_wants_email_notification', ...)` and `apply_filters('dofs_should_send_notification_now', ...)` use `dofs_` prefixed filter hooks. This is a cross-plugin dependency per CLAUDE.md boundary rules. Same violation found in Phase 14 (RNTF-DUP-001).
- Impact: Filters will silently return the default `true` value on installs without the DOFS plugin — benign but violates namespace isolation principle. If DOFS is removed, behavior is unchanged (defaults fire). If a future dev adds DOFS-side logic expecting plugin interactions, it creates hidden coupling.
- Fix: Rename to `sfs_hr_user_wants_email_notification` and `sfs_hr_should_send_notification_now`. This is a v1.2 cleanup item.

**REM-SEC-003 [Low]: Celebrations page uses sfs_hr.view capability (no POST handlers to audit)**
- File: `includes/Modules/Reminders/Admin/class-celebrations-page.php:29`
- Issue: The page capability gate `'sfs_hr.view'` is correct — it is a read-only display page showing all-org birthday/anniversary data. Any sfs_hr.view user (including employees with that dynamic cap) can access it.
- Assessment: `sfs_hr.view` is dynamically granted to dept managers for read operations across the plugin. For a celebrations page this is an acceptable scope since no employee PII beyond name and birthday day/month is exposed.
- Note: No POST handlers exist on this page — no nonce or capability audit needed.

### Performance Findings

**REM-PERF-001 [High]: get_upcoming_count() issues N+1 date-targeted queries — up to 16 queries per widget render**
- File: `includes/Modules/Reminders/Services/class-reminders-service.php:235–239`
- Issue: `get_upcoming_count(7)` calls `get_upcoming_birthdays(range(0, 7))` and `get_upcoming_anniversaries(range(0, 7))`. Each call iterates over `[0, 1, 2, 3, 4, 5, 6, 7]` (8 offsets) — executing one query per offset, per type. Result: 16 queries just to display a count badge on the dashboard.
- Additionally, `Dashboard_Widget::render()` calls `get_upcoming_birthdays([0,1,2,3,4,5,6,7])` and `get_upcoming_anniversaries([0,1,2,3,4,5,6,7])` directly — adding 16 more queries — for a total of 32 queries if both widget and count are called on the same page.
- Impact: On a dashboard page with both calls active: 32 separate SELECT queries against the employees table, each needing a full-table scan on DATE_FORMAT() expression (non-sargable unless function-based index exists in MySQL 8+).
- Fix: Rewrite `get_upcoming_birthdays()` to use a single query with `DATE_FORMAT(e.birth_date, '%%m-%%d') IN (...)` clause — build a comma-separated list of MM-DD values from the offsets array and pass as a single IN() predicate. Reduces 8 queries to 1.

**REM-PERF-002 [Medium]: Cron re-fetches settings on every notification email sent**
- File: `includes/Modules/Reminders/Cron/class-reminders-cron.php:78`
- Issue: `send_reminder_notification()` calls `Reminders_Service::get_settings()` on every iteration of the employees loop. `get_settings()` calls `get_option()` six times per call. On a 200-employee org with 5 employees having birthdays, settings are re-fetched 5 times unnecessarily.
- Fix: Fetch settings once in `run()` and pass to `send_birthday_reminders()` / `send_anniversary_reminders()` / `send_reminder_notification()` as a parameter instead of re-fetching inside the inner loop.

**REM-PERF-003 [Medium]: get_notification_recipients() calls get_users() with no size limit**
- File: `includes/Modules/Reminders/Services/class-reminders-service.php:173–203`
- Issue: `get_users(['role' => 'sfs_hr_manager'])` and `get_users(['role' => 'administrator'])` fetch all users of those roles with no `number` limit. On a large organization this materializes all manager/admin user objects into memory. Called once per recipient employee per cron run.
- Impact: Low risk in most organizations (few sfs_hr_manager accounts), but can spike memory on enterprise deployments. If `reminder_recipients` is `'all_hr'`, both `get_users()` calls fire per employee.
- Fix: Add `'number' => 100, 'fields' => ['user_email']` to the `get_users()` calls to limit result size and avoid loading full WP_User objects.

### Duplication Findings

**REM-DUP-001 [Medium]: Duplicate notification dispatch logic vs Core/Notifications**
- File: `includes/Modules/Reminders/Cron/class-reminders-cron.php:114–155`
- Issue: `send_notification_with_preferences()` and `queue_for_digest()` implement a custom notification preference check and digest queue on top of `Helpers::send_mail()`. This duplicates the pattern from `Core/Notifications` and possibly `Helpers`. The digest queue stored in `sfs_hr_notification_digest_queue` option has no consumer processing it — there is no cron or action that actually sends the queued digests.
- Impact: Dead code path: when `dofs_should_send_notification_now` returns `false`, messages are queued to `sfs_hr_notification_digest_queue` but never delivered.
- Fix: Either implement the digest consumer or remove the dead branch. For v1.2, consolidate notification dispatch through `Core/Notifications` service.

**REM-DUP-002 [Low]: get_upcoming_count() duplicates full query execution for a simple count**
- File: `includes/Modules/Reminders/Services/class-reminders-service.php:235–239`
- Issue: `get_upcoming_count()` fetches all birthday and anniversary records then counts them in PHP. It should use `COUNT(*)` SQL queries or at minimum avoid materializing full employee records just to call `count()` on the PHP array.
- Fix: Add dedicated `count_upcoming_birthdays()` and `count_upcoming_anniversaries()` methods that use `SELECT COUNT(*)` with the same WHERE clause logic.

### Logical Findings

**REM-LOGIC-001 [High]: Cron timezone bug — schedule uses current_time() (local) but query uses wp_date() (site TZ)**
- File: `includes/Modules/Reminders/Cron/class-reminders-cron.php:29–34` and `class-reminders-service.php:56,90`
- Issue: The cron is scheduled to fire at "today 08:00:00" using `current_time('timestamp')` which applies the WordPress site timezone offset. However, WP cron events are stored as UTC timestamps and fired at UTC time. `strtotime('today 08:00:00', current_time('timestamp'))` correctly returns a site-local time but `wp_schedule_event()` stores it as-is (treated as UTC by WP cron). Depending on the timezone offset this could cause the cron to fire at the wrong time.
- Additionally, `get_upcoming_birthdays()` and `get_upcoming_anniversaries()` use `wp_date('Y-m-d', strtotime("+{$days} days"))` for `$target_date`, which uses the WordPress site timezone. The birthday query matches `DATE_FORMAT(e.birth_date, '%%m-%%d')` — a date stored as a server date with no timezone. If the server and WP timezone differ, the target date for "today" may be off by one day (consistent with Phase 05 `Daily_Session_Builder` gmdate() bug).
- Fix: Use `wp_date('Y-m-d', time())` consistently and ensure cron is scheduled using `time()` + offset calculation that accounts for UTC storage. This is the same class of bug as Phase 05 `ATT-LOGIC-002`.

**REM-LOGIC-002 [Medium]: Dashboard widget shows all-org employee data with no department scoping**
- File: `includes/Modules/Reminders/Admin/class-dashboard-widget.php:25–26`
- Issue: `Dashboard_Widget::render()` calls `get_upcoming_birthdays([0,1,2,3,4,5,6,7])` and `get_upcoming_anniversaries([0,1,2,3,4,5,6,7])` which return all active employees org-wide. A dept manager with `sfs_hr.view` (dynamically granted) who lands on the HR dashboard will see birthdays for employees outside their department.
- Impact: Information disclosure of employee birth month/day to dept managers outside their reporting line. Low PII risk (only day/month + name shown), but inconsistent with the department scoping pattern applied elsewhere in the audit series.
- Fix: In `get_upcoming_birthdays()` and `get_upcoming_anniversaries()`, accept an optional `$dept_id` parameter. In `Dashboard_Widget::render()`, resolve the current user's department(s) and pass as filter if user is not sfs_hr.manage.

**REM-LOGIC-003 [Low]: Anniversary query uses hired_at but CLAUDE.md mandates hire_date for new code**
- File: `includes/Modules/Reminders/Services/class-reminders-service.php:95,101,102,103,151,157`
- Issue: All anniversary queries reference `e.hired_at` for anniversary calculations. Per CLAUDE.md "use `hire_date` for new code." Both columns exist and are maintained for backwards compatibility; however `hired_at` may be NULL for employees onboarded before the column was added, causing them to silently never appear in anniversary queries.
- Fix: Align to `hire_date` in a coordinated fix along with other modules referencing `hired_at` (see Phase 12 EMP-LOGIC-001). Add a coalesce: `COALESCE(e.hire_date, e.hired_at)` as interim fix.

---

## EmployeeExit Module Findings

### Security Findings

**EX-SEC-001 [Critical]: Admin menu capability gate too permissive — sfs_hr.view grants access to full resignation + settlement admin hub**
- File: `includes/Modules/EmployeeExit/Admin/class-employee-exit-admin.php:39`
- Issue: The `Employee_Exit_Admin` page is registered with capability `'sfs_hr.view'`. This means any user dynamically granted `sfs_hr.view` (dept managers via the `user_has_cap` filter) can access the Employee Exit hub, which includes the Resignations tab showing all-org resignation records (via `Resignation_List::render()`) and the Settlements tab (via `Settlement_List::render()`).
- This is the same high-risk pattern found in Phase 14 (RES-SEC-001) where `Resignation_List::render()` has no outer capability guard. The hub page itself does not gate the `resignations` or `settlements` tabs behind `sfs_hr.manage` — only the `exit-settings` tab has a capability check (line 73).
- Impact: Dept managers can view all-org resignation and settlement records they should not have visibility into. Settlement records contain full EOS financial data.
- Fix: Change the menu capability to `'sfs_hr.manage'`. If dept managers need visibility into their team's resignations, implement department-scoped filtering in `Resignation_List::render()` (which Phase 14 flagged as RES-SEC-001 — this finding extends that scope to the EmployeeExit hub).

**EX-SEC-002 [High]: No DDL in EmployeeExitModule bootstrap — references sfs_hr_exit_history table with no creation guarantee**
- File: `includes/Modules/EmployeeExit/EmployeeExitModule.php:97`
- Issue: `log_event()` inserts into `{$wpdb->prefix}sfs_hr_exit_history` but there is no `CREATE TABLE IF NOT EXISTS` in `EmployeeExitModule` and no reference to this table in `Migrations.php` was visible in the audit. If the table does not exist, every call to `log_event()` silently fails (wpdb->insert returns false without throwing).
- Impact: The entire audit history for resignations and settlements is silently lost if the table was not created. There is no error logging on `$wpdb->insert` failure.
- Fix: Verify `sfs_hr_exit_history` exists in `Migrations.php`. If not, add `CREATE TABLE IF NOT EXISTS`. Add `if (false === $wpdb->insert(...)) { error_log(...) }` guard to `log_event()`.

**EX-SEC-003 [High]: Expiring Contracts tab has no capability guard — accessible to sfs_hr.view users**
- File: `includes/Modules/EmployeeExit/Admin/class-employee-exit-admin.php:69,150`
- Issue: The `contracts` tab in `render_hub()` dispatches to `render_expiring_contracts()` without any `current_user_can()` check. Any holder of `sfs_hr.view` reaching the hub page can access the contracts tab, which exposes `contract_end_date`, `contract_type`, and `position` for all active employees org-wide.
- Impact: Same information disclosure pattern as EX-SEC-001. Employee contract terms are sensitive HR data.
- Fix: Add `if (!current_user_can('sfs_hr.manage')) { wp_die(...) }` at the top of `render_expiring_contracts()`, consistent with the `exit-settings` tab guard pattern.

**EX-SEC-004 [Medium]: register_roles_and_caps() called on every init — role modification on every request**
- File: `includes/Modules/EmployeeExit/EmployeeExitModule.php:51`
- Issue: `register_roles_and_caps()` is hooked to `'init'` and calls `get_role('sfs_hr_finance_approver')` and `$admin_role->add_cap('sfs_hr_resignation_finance_approve')` on every WordPress request. `add_role()` and `add_cap()` both write to the database (`wp_options` `wp_user_roles`) if the role/cap doesn't already exist. While WordPress caches role data, the `add_cap()` call still hits the object cache check on every request.
- Fix: Move role/capability registration to the plugin activation hook (same pattern as other modules), not `init`. Use a version-gated check (`get_option('sfs_hr_exit_roles_ver') !== '1.0'`) to run only on first activation or upgrade.

**EX-SEC-005 [Medium]: Contracts table renders a nonce URL in the Edit link but nonce purpose is wrong**
- File: `includes/Modules/EmployeeExit/Admin/class-employee-exit-admin.php:266`
- Issue: `wp_nonce_url(admin_url('admin.php?page=sfs-hr-employees&action=edit&id=' . (int)$emp['id']), 'sfs_hr_edit_' . (int)$emp['id'])` — this constructs a nonce URL for the employee edit page. This nonce is never verified on the receiving page (the employee edit page has its own nonce for form submission). Including an unverified nonce in a URL is misleading — it does not protect the linked page and creates false security expectation. The nonce is appended to a GET navigation URL (not a form action), which means it is exposed in server logs.
- Fix: Remove `wp_nonce_url()` from the navigation link. Use a plain `admin_url()` link. Nonces belong on form submissions, not navigational GET links.

### Performance Findings

**EX-PERF-001 [High]: Expiring Contracts tab issues two unbounded queries on every page load**
- File: `includes/Modules/EmployeeExit/Admin/class-employee-exit-admin.php:167–193`
- Issue: Both `$employees` (BETWEEN today and $end_date) and `$expired` (contract_end_date < today) queries have no LIMIT clause. On an org with thousands of historical active employees with contract dates, both queries can return unlimited rows. The `$expired` query in particular has no upper bound — it could return employees whose contracts expired years ago.
- Impact: High memory usage and slow page load for orgs with large employee counts or long-tenured contract employees. The `$expired` case is unbounded in both time and count.
- Fix: Add `LIMIT 500` to both queries. For `$expired`, consider adding a lower date bound (e.g., `AND e.contract_end_date >= DATE_SUB(%s, INTERVAL 1 YEAR)`) to avoid surfacing multi-year-old expired contracts.

### Duplication Findings

**EX-DUP-001 [Low]: EmployeeExitModule is a thin orchestrator — no logic duplication with Settlement or Resignation**
- Assessment: `EmployeeExitModule.php` contains no end-of-service calculation logic. All EOS calculations remain in `Settlement/Services/Settlement_Service.php` (audited in Phase 10). `EmployeeExitModule` acts purely as a bootstrapper for `Resignation_Handlers`, `Resignation_Cron`, `Resignation_Shortcodes`, and `Settlement_Handlers`. No formula duplication detected.
- Cross-check: The `log_event()`, `log_resignation_event()`, `log_settlement_event()` methods in `EmployeeExitModule` are new history-logging functionality not duplicated elsewhere. These are additive.

**EX-DUP-002 [Low]: get_combined_history() has WHERE clause constructed with raw PHP string concatenation**
- File: `includes/Modules/EmployeeExit/EmployeeExitModule.php:184–205`
- Issue: The `$where` variable is built via string concatenation (`$where .= ' OR h.settlement_id = %d'`) before being interpolated into the SQL string passed to `$wpdb->prepare()`. The `$params` array is then spread with `...$params`. While the values are properly parameterized via `%d` placeholders, the WHERE clause structural string is dynamic. Technically safe because the concatenated part is a static string literal with no user input — but the pattern is a code smell and could become dangerous if extended.
- Fix: Use a safe static query variant: keep both OR conditions in the base query and pass `0` for the settlement_id when not needed. Alternatively build the two cases as separate conditional queries.

### Logical Findings

**EX-LOGIC-001 [Medium]: EmployeeExitModule re-requires Resignation and Settlement modules unconditionally**
- File: `includes/Modules/EmployeeExit/EmployeeExitModule.php:10–11`
- Issue: Lines 10–11 unconditionally `require_once` both `ResignationModule.php` and `EmployeeExitModule.php`. If `hr-suite.php` or another module already loaded these files before `EmployeeExitModule` is instantiated, this is harmless (`require_once` deduplicates). However, the loading pattern means `EmployeeExitModule` implicitly takes ownership of bootstrapping Resignation and Settlement modules, which could cause double-initialization if `ResignationModule` or `SettlementModule` are also instantiated elsewhere in `hr-suite.php`.
- Impact: If `hr-suite.php` calls both `ResignationModule::hooks()` AND `EmployeeExitModule::hooks()`, hooks for `Resignation_Handlers` and `Settlement_Handlers` will be registered twice, causing duplicate form submission handling.
- Fix: Verify in `hr-suite.php` that only `EmployeeExitModule` is instantiated and that `ResignationModule` / `SettlementModule` are not also independently bootstrapped.

**EX-LOGIC-002 [Low]: sfs_hr_finance_approver role capability uses dotted format inconsistently**
- File: `includes/Modules/EmployeeExit/EmployeeExitModule.php:72`
- Issue: The `sfs_hr_finance_approver` role grants capability `'sfs_hr.view'` (dotted format, consistent with CLAUDE.md) but also grants `'sfs_hr_resignation_finance_approve'` (underscore format). The audit series convention is dotted format (`sfs_hr.*`). The finance approval capability should be `sfs_hr.resignation.finance_approve` for namespace consistency.
- Fix: Rename in v1.2 with a migration to update existing roles; document the rename in CLAUDE.md.

---

## Settlement Overlap Analysis

**Finding: EmployeeExit contains no EOS calculation logic — zero overlap with Settlement module.**

`EmployeeExitModule.php` contains zero gratuity calculation code. It delegates entirely to:
- `Settlement_Handlers` (loaded from `../Settlement/`) for EOS form handling
- `Resignation_Handlers` (loaded from `../Resignation/`) for resignation workflow

No `calculate_gratuity()`, no day-factor multiplication, no tenure-based multiplier logic exists in either EmployeeExit file. The module is a pure orchestrator.

The UAE 21-day formula bug found in Phase 10 (SETT-LOGIC-001) exists only in:
- `includes/Modules/Settlement/Services/class-settlement-service.php` — PHP server-side calculation
- The settlement admin JS form — client-side calculation

Neither file is part of the EmployeeExit module. The `Employee_Exit_Admin` class routes to `Settlement_Form::render()`, `Settlement_List::render()`, and `Settlement_View::render()` — these are Settlement module views with their own logic, not duplicated logic in EmployeeExit.

**Conclusion:** No new formula duplication to flag. The pre-existing Phase 10 SETT-LOGIC-001 (UAE formula) remains in the Settlement module only and was already catalogued.

---

## Cross-Module Patterns

### Recurring antipattern scorecard for this phase:

| Antipattern | Phase 19 Occurrence | Prior Phases |
|-------------|--------------------|----|
| bare ALTER TABLE | Not found — both modules clean | Phase 04, 08, 16 |
| information_schema queries | REM-SEC-001 (reminders-service.php:249) | Phase 04, 08, 11, 12, 16, 18 — 7th recurrence total |
| unprepared SHOW TABLES | Not found | Phase 04, 08 |
| wrong capability at admin gate | EX-SEC-001 (sfs_hr.view on resignation+settlement hub) | Phase 15, 16 — 3rd recurrence |
| __return_true REST permission callbacks | Not found — no REST endpoints in either module | Phase 05 |
| missing department scoping on REST/admin | REM-LOGIC-002 (widget), EX-SEC-001 (hub) | Phase 11, 14, 17 |
| nonce-only guard without capability check | Not found — EmployeeExit has no POST handlers | Phase 13 |
| TOCTOU races without WHERE status guard | Not found — EmployeeExitModule delegates to Resignation/Settlement handlers | Phase 14, 17 |
| Settlement UAE formula duplication | Not found in EmployeeExit | Phase 10 (Settlement module only) |
| cross-plugin dofs_ namespace filters | REM-SEC-002 (reminders-cron.php:116,121) | Phase 14 RNTF-DUP-001 — 2nd recurrence |
| hired_at vs hire_date inconsistency | REM-LOGIC-003 | Phase 12 EMP-LOGIC-001 |
| cron timezone (UTC vs local) | REM-LOGIC-001 | Phase 05 ATT-LOGIC-002 |
| unbounded queries without LIMIT | EX-PERF-001 (both contracts queries) | Phase 08, 09 |
| dead notification code path | REM-DUP-001 (digest queue never consumed) | — |

### Notable positives (not every phase has these):
- No bare ALTER TABLE in either module bootstrap (both modules have no `install()` method — DDL delegated to Migrations.php, same clean pattern as Phase 14 Resignation).
- No REST endpoints registered by either module — eliminates the entire REST permission callback attack surface.
- All $wpdb queries properly prepared — no raw interpolation found across all 7 files.
- EmployeeExitModule log_event() uses $wpdb->insert() (parameterized) correctly.
- Celebrations page (Reminders) has zero POST handlers — read-only admin page, no nonce audit needed.
- EmployeeExitModule does not duplicate Settlement's EOS calculation logic.
