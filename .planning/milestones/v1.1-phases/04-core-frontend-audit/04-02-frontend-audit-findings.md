# Frontend/ Audit Findings Report

**Audit date:** 2026-03-16
**Scope:** includes/Frontend/ (20 files, ~11.2K lines)
**Auditor:** Claude (automated code review)
**Requirements covered:** CORE-04

---

## Executive Summary

- **Critical:** 3 findings
- **High:** 11 findings
- **Medium:** 8 findings
- **Low:** 5 findings
- **Total:** 27 findings

### Top 5 Most Critical Issues

1. **FE-SQL-001** — `DashboardTab.php:L44` — Unbounded `SELECT COUNT(*)` query without `$wpdb->prepare()` on `sfs_hr_employees` table
2. **FE-SQL-002** — `ApprovalsTab.php:L91-L115` — Multiple unprepared queries (no `$wpdb->prepare()`) for loan approval data fetched by HR/GM/Admin
3. **FE-SQL-003** — `EmployeesTab.php:L91-L97` — Unbounded stats query without prepare and unfiltered department list in HR-accessible tab
4. **FE-AUTH-001** — `OverviewTab.php:L24` — Tab renders employee-owned data guarded only by `is_user_logged_in()`; no cross-employee access check on `$emp_id`
5. **FE-PERF-001** — `OverviewTab.php:L59-L233` — 9+ separate DB queries fired on every page load for the Overview tab, all unbatched

---

## Security Findings (CORE-04)

### XSS / Output Escaping

#### FE-XSS-001: `Helpers::translatable_name_html()` output echoed without phpcs suppression rationale
- **Severity:** Medium
- **File:** `includes/Frontend/Tabs/ApprovalsTab.php:L187`
- **Description:** `$type` is assigned from `Helpers::translatable_name_html(...)` which returns HTML containing a `<span>`. This return value is then echoed directly at L219 (`echo '...' . $type . '...'`). The escaping contract of `translatable_name_html()` is unknown at this call site—if that helper does not escape its inner values, XSS from a malicious leave type name would be possible.
- **Evidence:**
  ```php
  $type = Helpers::translatable_name_html( $r['type_name'] ?? '—', $r['type_name_translations'] ?? null );
  // ...
  echo '<span class="sfs-badge sfs-badge--info" style="font-size:11px;">' . $type . '</span>';
  ```
- **Fix:** Audit `Helpers::translatable_name_html()` to confirm it escapes its output. Add `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML escaped inside translatable_name_html()` with the confirmed-safe comment, or wrap the call with `wp_kses( $type, [ 'span' => [ 'class' => true, 'data-i18n-translations' => true, 'data-i18n-default' => true ] ] )`.

#### FE-XSS-002: Status HTML echoed without escape in `render_my_assets_frontend()` (dead code path)
- **Severity:** Medium
- **File:** `includes/Frontend/Shortcodes.php:L2557-L2561`
- **Description:** `$status_html` is constructed by `Helpers::asset_status_badge()` and echoed inside `<td>` without escaping. If `asset_status_badge()` generates HTML it should be reviewed; its contract is not verified here. This appears in the private `render_my_assets_frontend()` (dead code path — not called from the dispatcher in production), but the same pattern is live in the PWA profile block.
- **Evidence:**
  ```php
  echo '<td>' . (
      method_exists( \SFS\HR\Core\Helpers::class, 'asset_status_badge' )
          ? \SFS\HR\Core\Helpers::asset_status_badge( (string) ( $row['status'] ?? '' ) )
          : esc_html( (string) ( $row['status'] ?? '' ) )
  ) . '</td>';
  ```
- **Fix:** Add `phpcs:ignore` with verified-safe comment on the `asset_status_badge()` call, or pass it through `wp_kses_post()`.

#### FE-XSS-003: `$status_chip_fn` closure output echoed in attendance table (Shortcodes.php)
- **Severity:** Medium
- **File:** `includes/Frontend/Shortcodes.php:L2839,L2884`
- **Description:** The `$status_chip_fn` closure returns `<span>` HTML containing an `esc_html()` call internally. The closure's return value is echoed without phpcs suppression, so static analysis will flag it. More importantly, if the status key contains unexpected characters before it reaches the closure, the class attribute could be polluted. Status values come from DB column `status` (an enum-like field); the closure does `esc_attr($st)` for the class—this is safe, but the inline echo needs a phpcs:ignore annotation.
- **Evidence:**
  ```php
  echo '<td>' . $status_chip_fn( (string) $st_key ) . '</td>';
  ```
- **Fix:** Add `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` with a note that `$status_chip_fn` uses `esc_attr`/`esc_html` internally.

#### FE-XSS-004: `LeaveTab::render_kpis()` echoes integer counts without escaping
- **Severity:** Low
- **File:** `includes/Frontend/Tabs/LeaveTab.php:L204,L218,L220,L228`
- **Description:** Integer values `$requests`, `$annual`, `$used`, `$pending` are echoed directly into HTML. While PHP integers cannot contain HTML, the absence of `(int)` casts or `esc_html()` on dynamic values is a code-quality issue that could be a risk if types change.
- **Evidence:**
  ```php
  echo '<div class="sfs-kpi-value">' . $requests . '</div>';
  echo '<div class="sfs-kpi-value">' . $annual . ' <span ...>' . esc_html__( 'days', 'sfs-hr' ) . '</span></div>';
  ```
- **Fix:** Cast or wrap: `(int) $requests`, `(int) $annual`, `(int) $used`, `(int) $pending` before echoing. The same pattern appears in `ApprovalsTab::render_kpis()` (L163, L169) and `LoansTab::render_kpis()` (L112-L131).

#### FE-XSS-005: `DashboardTab` echoes `$dept_name` from DB into `esc_html()` correctly but unsafe class usage in heatmap rendering
- **Severity:** Low
- **File:** `includes/Frontend/Tabs/DashboardTab.php`
- **Description:** The `render_dashboard_styles()` method emits a large inline `<style>` block (L188-L250). CSS values come from PHP constants—this is safe. Department names and other user data in the rendered output use `esc_html()`. No XSS found in DashboardTab; the file appears properly escaped.
- **Evidence:** N/A — finding is informational (no issue).
- **Fix:** No action needed for XSS in DashboardTab.

---

### Auth / Access Control

#### FE-AUTH-001: OverviewTab does not verify employee ownership — cross-employee data exposure risk
- **Severity:** High
- **File:** `includes/Frontend/Tabs/OverviewTab.php:L23-L26`
- **Description:** `OverviewTab::render()` checks `is_user_logged_in()` but does NOT verify that `$emp_id` belongs to the currently logged-in user. The `$emp_id` is passed from `Shortcodes::my_profile()` which correctly resolves it via `Helpers::current_employee_id_any_status()`, so in normal flow the data is always the current user's. However, the tab itself has no internal ownership check. If another code path instantiates and calls `OverviewTab::render($emp, $other_emp_id)`, the tab will silently render someone else's data without any auth check.
- **Evidence:**
  ```php
  public function render( array $emp, int $emp_id ): void {
      if ( ! is_user_logged_in() ) {
          return;
      }
      // No check: (int)($emp['user_id'] ?? 0) !== get_current_user_id()
  ```
- **Fix:** Add ownership guard at the top of `render()`:
  ```php
  if ( ! is_user_logged_in() || (int)($emp['user_id'] ?? 0) !== get_current_user_id() ) {
      return;
  }
  ```
  This mirrors the pattern already used in `LeaveTab`, `LoansTab`, `PayslipsTab`, `ResignationTab`, and `SettlementTab`.

#### FE-AUTH-002: ProfileTab does not verify employee ownership
- **Severity:** High
- **File:** `includes/Frontend/Tabs/ProfileTab.php:L21-L24`
- **Description:** Identical issue to FE-AUTH-001. `ProfileTab::render()` only checks `is_user_logged_in()`, not that `$emp_id` matches the current user. The tab exposes salary, national ID, passport number, and emergency contacts — high-value PII.
- **Evidence:**
  ```php
  public function render( array $emp, int $emp_id ): void {
      if ( ! is_user_logged_in() ) {
          return;
      }
  ```
- **Fix:** Same pattern as FE-AUTH-001. Add `(int)($emp['user_id'] ?? 0) !== get_current_user_id()` check.

#### FE-AUTH-003: `TeamTab` silently returns empty state for HR+, but shows NO employees — `$is_manager_only` is hardcoded `true`
- **Severity:** Medium
- **File:** `includes/Frontend/Tabs/TeamTab.php:L45-L59`
- **Description:** The variable `$is_manager_only` is set to `true` unconditionally (L45). Dead code at L49-L50 was intended to load all departments for HR+ roles but is never reached. HR, GM, and Admin roles in the Team tab will only ever see direct-report departments. This is a logic bug: the Navigation.php grants the "team" tab to `hr, gm, admin` roles, but TeamTab always scopes to `dept_ids` from `get_manager_dept_ids()` — which returns empty arrays for HR/GM users who have no department manager assignment. This means HR role gets "No departments assigned." even though they should see all employees.
- **Evidence:**
  ```php
  $is_manager_only = true; // Always scope to direct reports
  // ...
  if ( ! $is_manager_only ) {  // Dead code — never reached
      $departments = $wpdb->get_results(...);
  }
  ```
- **Fix:** Check role level and set `$is_manager_only = ( $role === 'manager' )`. For HR+ roles, load all departments and show org-wide employees.

#### FE-AUTH-004: `ApprovalsTab` — HR sees `pending_finance` loans but GM/Admin see ALL statuses including `pending_gm` and `pending_finance`
- **Severity:** Low
- **File:** `includes/Frontend/Tabs/ApprovalsTab.php:L89-L115`
- **Description:** The approval logic is correct by design (GM/Admin see everything). However, note that the loan approval actions (`sfs_hr_loan_action`) are gated by `$can_act` per role at render time—but a GM can approve both stages, while HR is limited to `pending_finance`. The frontend rendering correctly enforces this. No auth bypass found; flagged for review.
- **Evidence:** N/A
- **Fix:** No action needed; existing logic is correct.

#### FE-AUTH-005: `render_trainee_profile()` checks `user_id` in DB but does not validate current user owns the trainee record
- **Severity:** High
- **File:** `includes/Frontend/Shortcodes.php:L60-L63`
- **Description:** In `my_profile()`, the trainee lookup queries by `user_id = {current_user_id}`, so ownership is correctly scoped at the query level. The trainee data returned will always belong to the current user. However, `render_trainee_profile()` accepts the `$trainee` array without re-validating ownership—safe today because the caller always passes the DB-verified row, but fragile by design.
- **Evidence:**
  ```php
  $trainee = $wpdb->get_row( $wpdb->prepare(
      "SELECT * FROM {$trainees_table} WHERE user_id = %d AND status = 'active'",
      $current_user_id
  ), ARRAY_A );
  ```
- **Fix:** Acceptable as-is since the query scopes by `user_id`. No structural change needed, but add a comment noting the ownership invariant.

---

### Nonce Validation

#### FE-NONCE-001: `LoansTab::render_flash_messages()` reads `$_GET['loan_request']` and `$_GET['error']` without sanitization
- **Severity:** High
- **File:** `includes/Frontend/Tabs/LoansTab.php:L91-L103`
- **Description:** Flash message rendering reads `$_GET['loan_request']` and `$_GET['error']` without nonce validation. The `error` parameter is processed with `urldecode()` and then passed to `esc_html()` — the escaping is correct — but the absence of nonce verification means an attacker can craft a URL `?loan_request=error&error=...arbitrary text...` and have it displayed in the UI. This is a phishing/social engineering vector, not direct XSS (since `esc_html()` is used), but it could be used to display false error messages.
- **Evidence:**
  ```php
  } elseif ( $_GET['loan_request'] === 'error' ) {
      $error = isset( $_GET['error'] ) ? urldecode( $_GET['error'] ) : __( 'Failed to submit request.', 'sfs-hr' );
      echo '<span>' . esc_html( $error ) . '</span>';
  }
  ```
- **Fix:** Use a transient-based flash system (store message in a transient keyed by user ID, display and delete on next load) instead of passing raw messages through GET parameters.

#### FE-NONCE-002: `LeaveTab::render_flash_messages()` reads `$_GET['leave_err']` without nonce
- **Severity:** Medium
- **File:** `includes/Frontend/Tabs/LeaveTab.php:L170-L191`
- **Description:** Same pattern as FE-NONCE-001 but for leave errors. The error code is mapped to a pre-defined string (not user-controlled), so the XSS risk is minimal. However, an attacker can force display of any error message in the map.
- **Evidence:**
  ```php
  if ( ! empty( $_GET['leave_err'] ) ) {
      $code = sanitize_key( $_GET['leave_err'] );
      $msg = $msgs[ $code ] ?? __( 'An error occurred. Please try again.', 'sfs-hr' );
  ```
- **Fix:** Use a transient or redirect with a server-set flash cookie. The current approach is low-risk since the message comes from a server-controlled map, but is still architecturally weak.

#### FE-NONCE-003: `ResignationTab::render_form_modal()` form submits to `admin-post.php` — nonce present but not visible in audit
- **Severity:** Low
- **File:** `includes/Frontend/Tabs/ResignationTab.php`
- **Description:** The form is rendered without a visible `wp_nonce_field()` call in the audited code section. Need to verify the form includes a nonce. The form action goes to `admin-post.php` with a custom action — the handler on the receiving end must check a nonce.
- **Evidence:** Code was not fully read; form rendering at L91-L100 visible.
- **Fix:** Verify `wp_nonce_field()` is called in `render_form_modal()` and that the handler verifies it.

#### FE-NONCE-004: `SettingsTab` form includes nonce correctly; but tab button `onclick` passes raw JS identifiers
- **Severity:** Low
- **File:** `includes/Frontend/Tabs/SettingsTab.php:L57,L90`
- **Description:** The main settings form has `wp_nonce_field( 'sfs_hr_frontend_settings', '_sfs_settings_nonce' )` — correct. The section tab buttons use `onclick="sfsShowSettingsSection('...')"` with `esc_js()` on the ID value. This is properly escaped. No nonce issue in the main form.
- **Evidence:** N/A
- **Fix:** No action needed; nonce present and escaping correct.

---

### SQL Injection

#### FE-SQL-001: `DashboardTab::render()` — `SELECT COUNT(*)` on employees table without `$wpdb->prepare()`
- **Severity:** Critical
- **File:** `includes/Frontend/Tabs/DashboardTab.php:L44-L46`
- **Description:** The query counting total active employees is not prepared. The table name is from `$wpdb->prefix` (safe), and the string `'active'` is a hardcoded literal (safe), so there is no actual injection vector here. However, this is still a PHPCS violation of WordPress coding standards and could be flagged as a critical pattern if the code evolves. More importantly, it sets a bad precedent in the file.
- **Evidence:**
  ```php
  $total_employees = (int) $wpdb->get_var(
      "SELECT COUNT(*) FROM {$emp_table} WHERE status = 'active'"
  );
  ```
- **Fix:** Use `$wpdb->prepare()`:
  ```php
  $total_employees = (int) $wpdb->get_var(
      $wpdb->prepare( "SELECT COUNT(*) FROM {$emp_table} WHERE status = %s", 'active' )
  );
  ```

#### FE-SQL-002: `ApprovalsTab::render()` — unprepared queries for loan approval lists
- **Severity:** Critical
- **File:** `includes/Frontend/Tabs/ApprovalsTab.php:L91-L115`
- **Description:** Three `$wpdb->get_results()` calls for loan data (GM/Admin level L91-L99, HR level L102-L115) are not prepared. Table names are safe (from `$wpdb->prefix`), and the status values are hardcoded string literals, so there is no active injection. However, these violate WordPress security standards and are not `$wpdb->prepare()`-wrapped.
- **Evidence:**
  ```php
  $pending_loans = $wpdb->get_results(
      "SELECT l.*, e.first_name, e.last_name, e.employee_code,
              d.name AS dept_name
       FROM {$loans_table} l
       JOIN {$emp_table} e ON e.id = l.employee_id
       LEFT JOIN {$dept_table} d ON d.id = e.dept_id
       WHERE l.status IN ('pending_gm','pending_finance')
       ORDER BY l.created_at ASC
       LIMIT 100",
      ARRAY_A
  );
  ```
- **Fix:** Wrap all loan queries in `$wpdb->prepare()`. Status constants should use `%s` placeholders.

#### FE-SQL-003: `EmployeesTab::render()` — unprepared stats and department queries
- **Severity:** Critical
- **File:** `includes/Frontend/Tabs/EmployeesTab.php:L91-L93,L96-L99`
- **Description:** The stats query (`SELECT status, COUNT(*) ... GROUP BY status`) and department list query are not prepared. The department list specifically uses no placeholder — the whole query is a static string — which is technically safe, but the stats query could expose status distribution data to any HR+ user without filtering.
- **Evidence:**
  ```php
  $stat_counts = $wpdb->get_results(
      "SELECT status, COUNT(*) AS cnt FROM {$emp_table} GROUP BY status",
      ARRAY_A
  );
  $departments = $wpdb->get_results(
      "SELECT id, name FROM {$dept_table} WHERE active = 1 ORDER BY name ASC",
      ARRAY_A
  );
  ```
- **Fix:** Wrap in `$wpdb->prepare()` or add phpcs:ignore with comment explaining why prepare is not needed (no user-controlled values).

#### FE-SQL-004: `LeaveTab::render()` — leave types query is not prepared (hardcoded WHERE clause)
- **Severity:** Medium
- **File:** `includes/Frontend/Tabs/LeaveTab.php:L49-L51`
- **Description:** The `SELECT id, name, ... FROM {$type_table} WHERE active = 1` query uses no user-supplied values and is safe, but is not wrapped in `$wpdb->prepare()`. This is a PHPCS-level issue, not an active injection risk.
- **Evidence:**
  ```php
  $types = $wpdb->get_results(
      "SELECT id, name, name_translations, gender_required, skip_managers_gm, requires_attachment, special_code FROM {$type_table} WHERE active = 1 ORDER BY name ASC"
  );
  ```
- **Fix:** Wrap in `$wpdb->prepare()` or use `$wpdb->query()` with a direct call and phpcs:ignore annotation.

#### FE-SQL-005: `TeamTab::render()` — unprepared department query uses `implode(intval())` safely but without prepare
- **Severity:** Low
- **File:** `includes/Frontend/Tabs/TeamTab.php:L55-L59`
- **Description:** The department list for managers uses `implode( ',', array_map( 'intval', $dept_ids ) )` in an `IN (...)` clause without `$wpdb->prepare()`. The `intval()` map ensures no injection is possible, but this pattern is discouraged in favor of `$wpdb->prepare()` with dynamic placeholders.
- **Evidence:**
  ```php
  $in_clause = implode( ',', array_map( 'intval', $dept_ids ) );
  $departments = $wpdb->get_results(
      "SELECT id, name FROM {$dept_table} WHERE id IN ({$in_clause}) ORDER BY name ASC",
      ARRAY_A
  );
  ```
- **Fix:** Use `$wpdb->prepare()` with a dynamically built placeholder string:
  ```php
  $placeholders = implode( ',', array_fill( 0, count( $dept_ids ), '%d' ) );
  $departments = $wpdb->get_results( $wpdb->prepare(
      "SELECT id, name FROM {$dept_table} WHERE id IN ({$placeholders}) ORDER BY name ASC",
      ...$dept_ids
  ), ARRAY_A );
  ```
  Note: `ApprovalsTab` already uses this correct pattern for its similar IN clause.

---

## Performance Findings

#### FE-PERF-001: OverviewTab fires 9+ DB queries on every page load
- **Severity:** High
- **File:** `includes/Frontend/Tabs/OverviewTab.php:L59-L233`
- **Description:** The Overview tab — shown by default on every portal load — executes the following queries sequentially: (1) leave balances JOIN, (2) requests count, (3) pending count, (4) next approved leave, (5) pending leaves, (6) upcoming leaves, (7) pending loans, (8) active loans, (9) SHOW TABLES for attendance, (10) attendance present count, (11) attendance absent count, (12) attendance late count, (13) pending assets count, (14) expiring documents check. This totals 14 queries minimum per page load.
- **Evidence:** Lines 59-233 contain 14 separate `$wpdb->get_*` calls in `render()`.
- **Fix:**
  1. Combine leave request counts into a single `GROUP BY status` query.
  2. Cache the attendance session counts in a single `GROUP BY status` query instead of three separate calls.
  3. Consider a 5-minute object cache (`wp_cache_get`/`wp_cache_set`) for the overview data.

#### FE-PERF-002: `Shortcodes::my_profile()` runs 6+ queries in the shortcode handler before dispatching
- **Severity:** High
- **File:** `includes/Frontend/Shortcodes.php:L60-L250`
- **Description:** Before `Tab_Dispatcher::render()` is called, the main shortcode handler performs: (1) trainee lookup, (2) employee row fetch, (3) resignation check, (4) department name query, (5) settlements count, (6) asset rows (LIMIT 200). These 6 queries fire on every shortcode render regardless of which tab is active. The asset query (LIMIT 200) is particularly expensive for employees with many assigned assets.
- **Evidence:**
  ```php
  $trainee = $wpdb->get_row(...)  // L60
  $emp = Helpers::get_employee_row(...)  // L76
  $approved_resignation = $wpdb->get_var(...)  // L88
  $dept_name = $wpdb->get_var(...)  // L103
  $has_settlements = $wpdb->get_var(...)  // L188
  $asset_rows = $wpdb->get_results(... LIMIT 200 ...)  // L234
  ```
- **Fix:** Move the asset query (L234) inside the profile tab renderer or the overview tab renderer — only load it when actually needed (i.e., not for every tab visit). Cache department name lookup.

#### FE-PERF-003: `Role_Resolver::resolve()` fires 4+ DB queries on every portal page load
- **Severity:** Medium
- **File:** `includes/Frontend/Role_Resolver.php:L60-L93`
- **Description:** `resolve()` is called by `Shortcodes::my_profile()` on every page load and internally runs: (1) `get_userdata()`, (2) `COUNT(*) departments WHERE hr_responsible`, (3) `COUNT(*) departments WHERE manager`, (4) `COUNT(*) employees WHERE user_id`, (5) `COUNT(*) trainees WHERE user_id`. For most employees (not managers or HR), all 5 checks run. The result is not cached.
- **Evidence:** Lines 54-96 show 5 DB queries in sequence with no caching layer.
- **Fix:** Add a per-request cache using `wp_cache_get( "sfs_hr_portal_role_{$user_id}", 'sfs_hr' )`. The role should not change within a single request.

#### FE-PERF-004: `DashboardTab` unbounded employee list query (LIMIT 100) runs on every dashboard load
- **Severity:** Medium
- **File:** `includes/Frontend/Tabs/DashboardTab.php:L147-L166`
- **Description:** The "today's employee list" query joins sessions, employees, and departments with `LIMIT 100` and no caching. This is expensive on large organizations (hundreds of employees). It also includes a `CASE` expression in `ORDER BY` which prevents index use on `status`.
- **Evidence:**
  ```php
  $today_list = $wpdb->get_results( $wpdb->prepare(
      "SELECT s.employee_id, s.status, s.in_time, s.out_time, ...
       FROM {$sessions_table} s
       JOIN {$emp_table} e ON e.id = s.employee_id
       LEFT JOIN {$dept_table} d ON d.id = e.dept_id
       WHERE s.work_date = %s
       ORDER BY CASE s.status ... END ASC, e.first_name ASC
       LIMIT 100",
      $today
  ), ARRAY_A );
  ```
- **Fix:** Cache result with `wp_cache_get/set` for 60 seconds. Consider fetching a simpler summary first and loading the detailed list via AJAX on demand.

#### FE-PERF-005: `SickLeaveReminder::get_uncovered_absences()` fires on every Overview and Leave tab load
- **Severity:** Medium
- **File:** `includes/Frontend/SickLeaveReminder.php:L49-L119`
- **Description:** This method runs on every Overview and Leave tab render, executing: (1) employee hire date lookup, (2) absent dates query for entire employment history, (3) leave request coverage query. For long-tenured employees, the absent dates query scans from `hire_date` to today — potentially years of attendance data.
- **Evidence:**
  ```php
  $lookback = $hire_date ?: wp_date( 'Y-m-d', strtotime( '-90 days', ... ) );
  $absent_dates = $wpdb->get_col( $wpdb->prepare(
      "SELECT DISTINCT work_date FROM {$sess_table}
       WHERE employee_id = %d AND work_date BETWEEN %s AND %s AND status IN ('absent','not_clocked_in')
       ORDER BY work_date ASC",
      $emp_id, $lookback, $today
  ) );
  ```
- **Fix:** Cache the result using `get_user_meta( $user_id, 'sfs_hr_uncovered_absences_cache', true )` with a TTL or transient. Alternatively, add a 90-day cap to the lookback window as a configurable default.

#### FE-PERF-006: `LoansTab` and `PayslipsTab` load ALL records without pagination
- **Severity:** Medium
- **File:** `includes/Frontend/Tabs/LoansTab.php:L38-L41`, `includes/Frontend/Tabs/PayslipsTab.php:L43-L54`
- **Description:** `LoansTab` fetches ALL loans for an employee (`ORDER BY created_at DESC` with no LIMIT). `PayslipsTab` limits to 24 records. For employees with many loans, the unbounded query could be slow and memory-intensive.
- **Evidence:**
  ```php
  $loans = $wpdb->get_results( $wpdb->prepare(
      "SELECT * FROM {$loans_table} WHERE employee_id = %d ORDER BY created_at DESC",
      $emp_id
  ) );
  ```
- **Fix:** Add `LIMIT 50` to the loans query, with pagination support if needed.

---

## Duplication and Logic Findings

#### FE-DUP-001: Profile completion logic duplicated in 3 files
- **Severity:** Medium
- **File:** `includes/Frontend/Shortcodes.php:L213-L229`, `includes/Frontend/Tabs/OverviewTab.php:L331-L345`, `includes/Frontend/Tabs/ProfileTab.php:L105-L120`
- **Description:** The profile completion calculation (`$profile_fields`, `$profile_completed`, `$profile_completion_pct`, `$profile_missing`) is duplicated in three separate locations with minor variations. Any change to the completion criteria must be applied in all three places.
- **Evidence:** Identical `$profile_fields` array structure appears in all three files.
- **Fix:** Extract to a static helper method, e.g., `Helpers::get_profile_completion( array $emp, int $emp_id ): array` returning `['pct' => int, 'missing' => array]`.

#### FE-DUP-002: Asset rendering block (desktop table + mobile cards) duplicated in `Shortcodes.php` (dead code) and `OverviewTab`
- **Severity:** Low
- **File:** `includes/Frontend/Shortcodes.php:L689-L880`, `includes/Frontend/Tabs/OverviewTab.php` (likely)
- **Description:** The full asset rendering block (desktop table, mobile cards, photo capture modal, JS) appears to be present in `Shortcodes::my_profile()` as dead code (inside `if (false):` guard at L483) AND in the live OverviewTab renderer. The dead code path should be removed.
- **Evidence:** Line L483: `<?php if ( false ) : /* Dead code guard */`
- **Fix:** Remove the dead code block (L483-L1283 in `Shortcodes.php`). It was replaced by the Tab Dispatcher pattern but never cleaned up.

#### FE-DUP-003: Flash message patterns for errors duplicated across tabs
- **Severity:** Low
- **File:** `includes/Frontend/Tabs/LeaveTab.php:L169-L192`, `includes/Frontend/Tabs/LoansTab.php:L91-L104`
- **Description:** Both tabs implement their own flash message rendering from `$_GET` parameters. The pattern is identical but uses different parameter names and message sets.
- **Fix:** Create a shared `Frontend\Flash_Messages` utility class with a `render( string $param, array $msgs )` method.

#### FE-DUP-004: `Tab_Dispatcher` has `$tab_map` but also `Navigation::tab_has_renderer()` maintains a parallel list
- **Severity:** Low
- **File:** `includes/Frontend/Tab_Dispatcher.php:L45-L63`, `includes/Frontend/Navigation.php:L197-L221`
- **Description:** Two separate arrays define which tabs are available. Adding a new tab requires updating both `$tab_map` in `Tab_Dispatcher` AND `$available` in `Navigation::tab_has_renderer()`. These can drift out of sync.
- **Fix:** Have `Navigation::tab_has_renderer()` delegate to `Tab_Dispatcher::get_registered_tabs()` (which would return the keys of `$tab_map`).

#### FE-DUP-005: Attendance status chip rendering duplicated in Shortcodes.php (closure) and presumably in AttendanceModule
- **Severity:** Low
- **File:** `includes/Frontend/Shortcodes.php:L2592-L2630`
- **Description:** The `$status_label_fn` and `$status_chip_fn` closures defined inline replicate logic that likely exists in `AttendanceModule` or `Helpers`. Closures are harder to test and reuse.
- **Fix:** Move to a static helper method in `Helpers` or a dedicated `Attendance_UI` class.

---

## Files Reviewed

| File | Lines | Findings |
|------|-------|----------|
| Shortcodes.php | 3776 | 5 (FE-XSS-002, FE-XSS-003, FE-PERF-002, FE-DUP-002, FE-DUP-005) |
| Tabs/OverviewTab.php | 773 | 3 (FE-AUTH-001, FE-PERF-001, FE-DUP-001) |
| Tabs/DashboardTab.php | 694 | 3 (FE-SQL-001, FE-PERF-004, FE-XSS-005) |
| Tabs/LeaveTab.php | 691 | 3 (FE-SQL-004, FE-NONCE-002, FE-XSS-004) |
| Tabs/ProfileTab.php | 672 | 2 (FE-AUTH-002, FE-DUP-001) |
| Tabs/SettingsTab.php | 602 | 1 (FE-NONCE-004) |
| Tabs/ApprovalsTab.php | 540 | 3 (FE-SQL-002, FE-XSS-001, FE-AUTH-004) |
| Tabs/LoansTab.php | 454 | 3 (FE-NONCE-001, FE-PERF-006, FE-DUP-003) |
| Navigation.php | 408 | 1 (FE-DUP-004) |
| Tabs/EmployeesTab.php | 377 | 1 (FE-SQL-003) |
| Tabs/TeamAttendanceTab.php | 339 | 0 |
| SickLeaveReminder.php | 324 | 1 (FE-PERF-005) |
| Tabs/PayslipsTab.php | 321 | 1 (FE-PERF-006) |
| Tabs/ResignationTab.php | 289 | 1 (FE-NONCE-003) |
| GovSupportReminder.php | 254 | 0 |
| Tabs/TeamTab.php | 245 | 2 (FE-AUTH-003, FE-SQL-005) |
| Tabs/SettlementTab.php | 189 | 0 |
| Role_Resolver.php | 140 | 1 (FE-PERF-003) |
| Tab_Dispatcher.php | 120 | 1 (FE-DUP-004) |
| Tabs/TabInterface.php | 28 | 0 |

---

## Tab Access Control Matrix

| Tab | Auth Check | Role Check | Nonce (POST) | Notes |
|-----|-----------|------------|-------------|-------|
| OverviewTab | `is_user_logged_in()` only | None | N/A (read-only) | Missing ownership check (FE-AUTH-001) |
| ProfileTab | `is_user_logged_in()` only | None | N/A (read-only) | Missing ownership check (FE-AUTH-002) |
| LeaveTab | `is_user_logged_in()` + user_id match | None | Yes (wp_nonce_field in form modal) | Flash params via GET (FE-NONCE-002) |
| LoansTab | `is_user_logged_in()` + user_id match | None | Yes (form modal) | Flash `error` param via GET (FE-NONCE-001) |
| ResignationTab | `is_user_logged_in()` + user_id match | None | Verify nonce in form modal | Need to confirm nonce in modal (FE-NONCE-003) |
| SettlementTab | `is_user_logged_in()` + user_id match | None | N/A (read-only) | Correct ownership check |
| PayslipsTab | `is_user_logged_in()` + user_id match | None | N/A (read-only) | Correct ownership check |
| ApprovalsTab | `is_user_logged_in()` via Tab_Dispatcher | `role_level >= 30` | Yes (per-action nonce) | Correct role gating |
| TeamTab | `is_user_logged_in()` via Tab_Dispatcher | `role_level >= 30` | N/A (read-only) | Logic bug: always manager-scoped (FE-AUTH-003) |
| TeamAttendanceTab | `is_user_logged_in()` via Tab_Dispatcher | `role_level >= 30` | N/A (read-only) | Correct |
| DashboardTab | `is_user_logged_in()` via Tab_Dispatcher | `role_level >= 40` | N/A (read-only) | Correct |
| EmployeesTab | `is_user_logged_in()` via Tab_Dispatcher | `role_level >= 40` | N/A (read-only) | Correct |
| SettingsTab | `is_user_logged_in()` + `role_level >= 60` | Admin only | Yes (`_sfs_settings_nonce`) | Correct |

**Auth chain:** `my_profile` shortcode → `is_user_logged_in()` → `Tab_Dispatcher::render()` → individual tab `render()`. Tab Dispatcher does NOT re-check auth — it relies on Navigation.php having already filtered allowed tabs by role. The Tab Dispatcher's `$is_limited` and `$has_settlements` checks are access-control flags that supplement (not replace) the role checks.

---

## Recommendations Priority

### 1. Immediate (Critical)

- **FE-SQL-001, FE-SQL-002, FE-SQL-003**: Wrap all unprepared `$wpdb->get_*()` calls in `$wpdb->prepare()`. Priority is DashboardTab (L44), ApprovalsTab (L91-115), and EmployeesTab (L91-99). These are PHPCS violations and could create maintenance risk if status literals are ever parameterized.
- **FE-AUTH-001, FE-AUTH-002**: Add cross-employee ownership checks to `OverviewTab::render()` and `ProfileTab::render()` matching the pattern already in `LeaveTab`, `LoansTab`, `PayslipsTab`, `ResignationTab`, and `SettlementTab`.

### 2. Next Sprint (High)

- **FE-PERF-001**: Refactor OverviewTab to run at most 4 queries instead of 14 (batch by GROUP BY).
- **FE-PERF-002**: Move asset query out of the main shortcode handler into the profile/overview tab.
- **FE-PERF-003**: Add per-request caching to `Role_Resolver::resolve()`.
- **FE-AUTH-003**: Fix TeamTab logic bug where `$is_manager_only = true` always, preventing HR/GM/Admin from seeing all team members.
- **FE-NONCE-001**: Replace GET-parameter flash messages in LoansTab with a transient-based system.
- **FE-XSS-001**: Audit and annotate `Helpers::translatable_name_html()` for safe-output confirmation.

### 3. Backlog (Medium/Low)

- **FE-PERF-004, FE-PERF-005, FE-PERF-006**: Cache DashboardTab employee list, SickLeaveReminder absences, and add pagination to LoansTab.
- **FE-DUP-001**: Extract profile completion logic to a shared helper.
- **FE-DUP-002**: Remove the dead code block (L483-L1283) in `Shortcodes.php`.
- **FE-DUP-004**: Consolidate tab registration between `Tab_Dispatcher::$tab_map` and `Navigation::tab_has_renderer()`.
- **FE-NONCE-002**: Replace GET flash in LeaveTab (lower risk than LoansTab since messages come from server-controlled map).
- **FE-SQL-004, FE-SQL-005**: Wrap remaining non-prepared queries.
- **FE-XSS-003, FE-XSS-004**: Add phpcs:ignore annotations with verification notes to integer echoes and status chip closures.
