# Phase 12 Plan 01: Employee Profile Page Audit Findings

**File audited:** `includes/Modules/Employees/Admin/class-employee-profile-page.php`
**Line count:** 1,982 lines
**Date:** 2026-03-16
**Phase:** 12 — Employees module audit
**Auditor:** Phase executor (automated review)

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 0 | 2 | 2 | 1 | 5 |
| Performance | 0 | 2 | 1 | 1 | 4 |
| Duplication | 0 | 0 | 3 | 1 | 4 |
| Logical | 0 | 1 | 2 | 1 | 4 |
| **Total** | **0** | **5** | **8** | **4** | **17** |

---

## Security Findings

### EMP-SEC-001
**Severity:** High
**Location:** `class-employee-profile-page.php:30`
**Description:** Menu capability registered as `sfs_hr_attendance_view_team`. This is a WordPress role capability name (not the plugin's dotted-format `sfs_hr.*` capability). Using a role name as a menu capability is unusual and may bypass the plugin's capability system. The menu is hidden (parent is `null`) so it is not directly exploitable for privilege escalation, but the intent is unclear.
**Impact:** If `sfs_hr_attendance_view_team` is a WordPress role name rather than a dedicated capability, it may grant menu access to users who have that role regardless of HR-specific capability grants. This is inconsistent with the `sfs_hr.*` dotted convention used everywhere else in the plugin.
**Fix recommendation:** Change the menu capability to `sfs_hr.view` (the standard plugin capability for read-only access) or document why `sfs_hr_attendance_view_team` is used. The render_page() guard on line 99 already calls `Helpers::require_cap('sfs_hr_attendance_view_team')` which is consistent, but the inconsistency with the rest of the codebase creates confusion.

---

### EMP-SEC-002
**Severity:** High
**Location:** `class-employee-profile-page.php:244`
**Description:** One `$wpdb->get_results()` call at line 244 is executed without `$wpdb->prepare()`. The query fetches all active departments: `"SELECT id, name FROM {$dept_table} WHERE active=1 ORDER BY name ASC"`. No user input is interpolated — only the table name prefix is injected.
**Impact:** The table name is always a static value derived from `$wpdb->prefix` (a server-side constant), so there is no SQL injection risk in this specific case. However, the pattern violates the project's convention of always using `$wpdb->prepare()` for all database calls. In a larger context (copy-paste of this pattern to a method that does accept user input), the risk becomes real.
**Fix recommendation:** Wrap in `$wpdb->prepare()` using a static placeholder or use WPCS suppress comment if the raw query is intentional. Simplest fix: `$wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$dept_table} WHERE active=1 ORDER BY name ASC" ), ARRAY_A )`. Although `prepare()` without parameters is not strictly necessary, it documents intent.

---

### EMP-SEC-003
**Severity:** Medium
**Location:** `class-employee-profile-page.php:229-233, 1212-1220, 1226-1234, 1565-1570, 1692-1697, 1742-1747, 1778-1783, 1832-1837`
**Description:** Table-existence checks using `information_schema.tables` appear 8 times across `render_page()`, `render_assets_card()`, `render_documents_card()`, and `render_requests_card()`. The same Critical antipattern was flagged in Phase 04, Phase 08, and Phase 11 (ASSET-SEC-003). While each individual query is prepared with `$wpdb->prepare()`, the pattern itself is costly (see EMP-PERF-001).
**Impact:** No direct injection risk since the table names passed to `information_schema` are server-side derived strings, not user input. But the pattern is architecturally incorrect — table existence should be resolved once during the request bootstrap, not on every page render.
**Fix recommendation:** Use `$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}sfs_hr_assets'")` or better yet, use the plugin's own `sfs_hr_db_ver` or a static method like `Migrations::table_exists()` checked once in a request-level cache. See Phase 04 and Phase 08 precedents. Cross-reference: EMP-PERF-001.

---

### EMP-SEC-004
**Severity:** Medium
**Location:** `class-employee-profile-page.php:98-105`
**Description:** The `render_page()` method dispatches to `handle_create_wp_user()` when `$_GET['do_create_wp_user']` is set AND `current_user_can('create_users')` is true. The handler itself (`handle_create_wp_user()`) also checks `current_user_can('create_users')` at line 43 and verifies the nonce. This is correct — the guard is applied in both places. **However**, the `render_page()` method is the callback for a menu page registered with capability `sfs_hr_attendance_view_team`. If team managers with `sfs_hr_attendance_view_team` also have `create_users` in any configuration, the user creation path is accessible from the profile viewer role.
**Impact:** Low risk in default configuration because `create_users` is a WordPress core administrator capability. But the architecture conflates a read-only page with a write action handler inside the same `render_page()` method — a design smell that increases review complexity and future regression risk.
**Fix recommendation:** Move `handle_create_wp_user()` to a dedicated `admin_post_sfs_hr_create_wp_user` hook (consistent with how `handle_save_edit`, `handle_regen_qr`, and `handle_toggle_qr` are registered in `Core/Admin.php`). This removes the write action from the page renderer entirely.

---

### EMP-SEC-005
**Severity:** Low
**Location:** `class-employee-profile-page.php:1531`
**Description:** `format_time()` hardcodes timezone to `new \DateTimeZone('Asia/Riyadh')` instead of using `wp_timezone()`. This is inconsistent with other methods in the same file that correctly use `wp_timezone()` (e.g., `get_today_last_punch()` at line 1078, `get_month_meta()` at line 1389).
**Impact:** Attendance time display will be incorrect for organizations not in the Asia/Riyadh (UTC+3) timezone, even if the WordPress site timezone is set differently.
**Fix recommendation:** Replace `new \DateTimeZone('Asia/Riyadh')` with `wp_timezone()` in `format_time()`, matching the pattern used elsewhere in the file.

---

## Performance Findings

### EMP-PERF-001
**Severity:** High
**Location:** `class-employee-profile-page.php:229, 1212, 1226, 1565, 1692, 1742, 1778, 1832`
**Description:** Eight `information_schema.tables` existence checks are executed on every profile page load, regardless of whether the feature is used. These calls occur unconditionally in `render_page()` and in every `render_*_card()` method, even when those cards are only rendered in view mode. Cross-reference: EMP-SEC-003. Same antipattern flagged in ASSET-SEC-003 (Phase 11).
**Impact:** 8 metadata queries per page load. `information_schema` queries require a metadata lock and can be significantly slower than user-data queries on busy MySQL instances. On a WordPress site with many concurrent admin users, this becomes a bottleneck.
**Fix recommendation:** Cache table existence results using WordPress transients or a static class property per request. Example:
```php
private static array $table_cache = [];
private function table_exists( string $table ): bool {
    if ( ! isset( self::$table_cache[ $table ] ) ) {
        global $wpdb;
        self::$table_cache[ $table ] = (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW TABLES LIKE %s", $table )
        );
    }
    return self::$table_cache[ $table ];
}
```

---

### EMP-PERF-002
**Severity:** High
**Location:** `class-employee-profile-page.php:220-223`
**Description:** Three calls to `Core\Admin` static methods are made on every profile page load:
- `\SFS\HR\Core\Admin::ensure_qr_token()` — may perform a DB write (generates and stores a QR token if missing)
- `\SFS\HR\Core\Admin::attendance_shifts_grouped()` — fetches all shift definitions from DB
- `\SFS\HR\Core\Admin::get_emp_shift_history()` — fetches shift history for this employee

These calls are made even when viewing an employee in view-only mode, and even when the QR section and shift section are not visible (because they are inside the edit-mode block at line 441). `ensure_qr_token()` is called unconditionally even in view mode where the QR is not displayed in edit form.
**Impact:** Unnecessary DB writes (QR token generation) and reads on every profile page view, even in view-only mode. The shifts query loads all active shifts for the dropdown — appropriate in edit mode, wasteful in view mode.
**Fix recommendation:** Gate `attendance_shifts_grouped()` and the edit-mode QR calls behind `if ($mode === 'edit')`. Move `ensure_qr_token()` inside the edit mode block or inside the QR rendering section that is already gated by `$mode === 'edit'`.

---

### EMP-PERF-003
**Severity:** Medium
**Location:** `class-employee-profile-page.php:1142-1198`
**Description:** `get_risk_flag_for_employee()` fetches all attendance session status values for the last 30 days (`RISK_LOOKBACK_DAYS = 30`) on every profile page load via `SELECT status FROM sfs_hr_attendance_sessions WHERE employee_id=? AND work_date BETWEEN ? AND ?`. For employees with daily sessions, this returns up to 30 rows each time.
**Impact:** An uncached per-page SELECT on the attendance sessions table per profile view. Not critical but adds to the hot path. The query is bounded (30 days, one employee) so it is not unbounded — but it runs on every view even when the employee has no risk indicators.
**Fix recommendation:** Consider adding a short-lived transient cache on the result (e.g., 5-minute TTL per employee ID). The risk calculation itself is not computationally heavy; the concern is the query frequency when multiple managers are viewing the same employee profile concurrently.

---

### EMP-PERF-004
**Severity:** Low
**Location:** `class-employee-profile-page.php:1596-1610`
**Description:** In `render_documents_card()`, two separate queries are executed against `sfs_hr_employee_documents`: one to get counts grouped by type (line 1577) and one to get all expiry dates (line 1596). The expiry-date query fetches all non-null expiry dates to do PHP-side filtering, when the same result could be computed with a single SQL CASE expression or a conditional COUNT.
**Impact:** Two queries where one would suffice.
**Fix recommendation:** Merge into a single query:
```sql
SELECT document_type,
       COUNT(*) AS count,
       SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
       SUM(CASE WHEN expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS expiring_soon
FROM {$doc_table}
WHERE employee_id = %d AND status = 'active'
GROUP BY document_type
```

---

## Duplication Findings

### EMP-DUP-001
**Severity:** Medium
**Location:** `class-employee-profile-page.php:445-449` (edit mode `$fmt_input`), `556-561` (view mode `$fmt_date`)
**Description:** Date formatting closures are defined twice: `$fmt_input` (converts `Y-m-d` to `d/m/Y` for input fields) and `$fmt_date` (converts `Y-m-d` to `d/m/Y` for display, returning `—` on empty). Both implement `DateTimeImmutable::createFromFormat('Y-m-d', $d)` and handle the same string-to-date conversion. The only difference is empty-value return: `''` vs `'—'`.
**Impact:** Two closures doing the same thing. Any change to the date format must be made in two places.
**Fix recommendation:** Extract to a protected method:
```php
protected function format_date_ymd( string $d, string $empty = '—' ): string {
    if ( ! $d ) return $empty;
    $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $d );
    return $dt ? $dt->format( 'd/m/Y' ) : $d;
}
```
Call with `$empty = ''` for edit inputs and `$empty = '—'` for view display.

---

### EMP-DUP-002
**Severity:** Medium
**Location:** `class-employee-profile-page.php:511-513` (edit mode shift current display), `675-676` (view mode shift display)
**Description:** Shift display logic (building `$cs_parts` array with name, time range, dept) is duplicated verbatim in two places. The edit mode version includes a `$current_shift['dept']` component; the view mode version omits it. The structural pattern is otherwise identical.
**Impact:** Cosmetic difference between edit and view mode shift display without explanation. Any change to shift label format must be applied in both places.
**Fix recommendation:** Extract to `protected function format_shift_label(array $shift, bool $include_dept = true): string`.

---

### EMP-DUP-003
**Severity:** Medium
**Location:** `class-employee-profile-page.php:1914-1951` (`get_request_status_badge()`), `1957-1980` (`get_request_type_badge()`), and similar badge patterns in `render_status_badge()` at line 1498 and `render_leave_badge()` at line 1518
**Description:** Four badge-rendering methods all produce `<span>` elements with inline style or class attributes. The `get_request_status_badge()` and `get_request_type_badge()` methods use identical `sprintf()` template with `esc_attr` and `esc_html` — differing only in the lookup array.
**Impact:** Minor code repetition; the risk is inconsistency in the badge HTML structure over time.
**Fix recommendation:** Extract a generic `render_colored_badge(string $label, string $bg, string $color): string` helper in `Helpers` class, and have `get_request_status_badge()` and `get_request_type_badge()` call it.

---

### EMP-DUP-004
**Severity:** Low
**Location:** `class-employee-profile-page.php:361-362` (header photo initials), `464-465` (edit mode photo initials)
**Description:** Employee initials computation (`strtoupper(mb_substr($first_name ?: $name, 0, 1) . mb_substr($last_name, 0, 1))`) is duplicated in two places — once in the header area and once in the edit mode photo section.
**Impact:** Minor duplication.
**Fix recommendation:** Extract to `protected function compute_initials(string $first, string $last, string $fallback = ''): string`.

---

## Logical Findings

### EMP-LOGIC-001
**Severity:** High
**Location:** `class-employee-profile-page.php:175`, `497`
**Description:** The dual `hire_date` / `hired_at` fallback is only used in one place. The fallback pattern (`$emp['hired_at'] ?? ($emp['hire_date'] ?? '')`) is applied at line 175 when reading the employee record for display. However, the edit form submits the field under the name `hired_at` (line 497: `name="hired_at"`). The save handler in `Core/Admin.php::handle_save_edit()` must accept `hired_at` as the POST field. This means editing always updates `hired_at`, and the display reads `hired_at` first — correct. But the `hire_date` column (the canonical column per CLAUDE.md: "use hire_date for new code") is never updated from this form. Over time, `hire_date` and `hired_at` will diverge if only `hired_at` is updated by this form.
**Impact:** The display correctly shows `hired_at` (the most recently updated value), but downstream calculations that read `hire_date` (e.g., Settlement, Leave tenure) may use a stale value if the profile was edited via this form after the original hire date was stored in `hire_date`.
**Fix recommendation:** Either (a) also update `hire_date` when saving `hired_at` in `handle_save_edit()` (recommended per CLAUDE.md dual-column maintenance requirement), or (b) change the form field name to `hire_date` and update both columns in the save handler. Document the decision in CLAUDE.md.

---

### EMP-LOGIC-002
**Severity:** Medium
**Location:** `class-employee-profile-page.php:1142-1198`
**Description:** `get_risk_flag_for_employee()` calculates the lookback window using PHP's `strtotime()` with `date()` (non-WP functions) at line 1148: `$start_ts = strtotime($today_ymd . ' -' . (self::RISK_LOOKBACK_DAYS - 1) . ' days')`. The surrounding code uses `wp_date()` and `wp_timezone()` consistently. Using `date()` instead of `wp_date()` means the start-of-window date is calculated in server PHP timezone, not WordPress site timezone. For a server in UTC and a site in Asia/Riyadh (UTC+3), the lookback period boundary could shift by one day.
**Impact:** Risk flag calculated against wrong date boundaries for non-UTC servers. An employee may have their risk status appear/disappear by one day depending on server timezone vs site timezone.
**Fix recommendation:** Replace `date('Y-m-d', $start_ts)` with `wp_date('Y-m-d', $start_ts)` or better yet: derive `$start` as `wp_date('Y-m-d', strtotime('-' . (self::RISK_LOOKBACK_DAYS - 1) . ' days', strtotime($today_ymd)))`.

---

### EMP-LOGIC-003
**Severity:** Medium
**Location:** `class-employee-profile-page.php:760-769`
**Description:** Month summary attendance table hardcodes `new \DateTimeZone('Asia/Riyadh')` for displaying `in_time` and `out_time` values (lines 762, 768), while other time display methods use `wp_timezone()`. Cross-reference: EMP-SEC-005 (same issue in `format_time()`).
**Impact:** Attendance in/out times in the month summary table will show incorrect times for non-Riyadh timezone deployments.
**Fix recommendation:** Replace `new \DateTimeZone('Asia/Riyadh')` with `wp_timezone()` in the `$in_display` and `$out_display` calculation blocks.

---

### EMP-LOGIC-004
**Severity:** Low
**Location:** `class-employee-profile-page.php:1102-1114`
**Description:** `compute_status_from_punch()` maps `punch_type` values to status strings. It maps both `'in'` and `'break_end'` to `'clocked_in'`, and `'break_start'` to `'on_break'`. The case `'out'` maps to `'clocked_out'`. The `default` case maps to `'not_clocked_in'`. This logic is duplicated (in spirit) from the Attendance module's punch processing logic. If the Attendance module adds new punch types (e.g., `'break_start_2'`, `'supervisor_in'`), this method becomes stale without any compile-time warning.
**Impact:** If new punch types are added to the Attendance module, the profile page's "Today" status badge will show "Not Clocked-IN" for employees in states that have valid punch types not handled here.
**Fix recommendation:** Either (a) share the status computation via a static method on the Attendance module's service class, or (b) document this as a known divergence point with a comment pointing to the canonical location.

---

## $wpdb Call-Accounting Table

The plan specified "80 $wpdb calls" as the expected count. Actual counts in this file:
- **Total `$wpdb->` references:** 136 (includes `->prefix`, `->prepare()`, and execution calls)
- **Table prefix references (`$wpdb->prefix`):** 17
- **`$wpdb->prepare()` calls:** 26 (inline parameter binding, not execution)
- **DB execution calls:** 28
- **Raw (non-prepared) execution calls:** 1 (line 244)

The plan's "80 calls" appears to be a prior estimate. The actual execution call count is 28.

| # | Line | Method | Query Type | Prepared? | Notes |
|---|------|--------|------------|-----------|-------|
| 1 | 49 | `handle_create_wp_user()` | SELECT | Yes | Fetch employee by ID |
| 2 | 93 | `handle_create_wp_user()` | UPDATE | Yes (`$wpdb->update()`) | Link WP user_id to employee |
| 3 | 120-123 | `render_page()` | SELECT | Yes | Fetch employee by ID |
| 4 | 229-234 | `render_page()` | SELECT (information_schema) | Yes | Check dept table existence — see EMP-PERF-001 |
| 5 | 237-241 | `render_page()` | SELECT | Yes | Fetch dept name by ID |
| 6 | 244 | `render_page()` | SELECT | **No** | Fetch all active depts — see EMP-SEC-002 |
| 7 | 257-262 | `render_page()` | SELECT | Yes | Fetch dept manager_user_id |
| 8 | 268-278 | `render_page()` | SELECT | Yes | Fetch direct reports |
| 9 | 281-289 | `render_page()` | SELECT | Yes | Fetch manager employee record |
| 10 | 1090-1093 | `get_today_last_punch()` | SELECT | Yes | Fetch today's punches (UTC window) |
| 11 | 1130-1133 | `get_today_leave_label()` | SELECT | Yes | Fetch approved leave for today |
| 12 | 1155-1158 | `get_risk_flag_for_employee()` | SELECT | Yes | Fetch sessions for risk window |
| 13 | 1212-1220 | `render_assets_card()` | SELECT (information_schema) | Yes | Check assets table existence |
| 14 | 1226-1234 | `render_assets_card()` | SELECT (information_schema) | Yes | Check assignments table existence |
| 15 | 1239-1252 | `render_assets_card()` | SELECT | Yes | Fetch employee's asset assignments |
| 16 | 1425-1428 | `get_month_summary_for_employee()` | SELECT | Yes | Fetch month attendance sessions |
| 17 | 1546-1551 | `manager_dept_ids_for_current_user()` | SELECT | Yes | Fetch manager's dept IDs |
| 18 | 1565-1570 | `render_documents_card()` | SELECT (information_schema) | Yes | Check documents table existence |
| 19 | 1577-1586 | `render_documents_card()` | SELECT | Yes | Fetch doc counts by type |
| 20 | 1596-1603 | `render_documents_card()` | SELECT | Yes | Fetch expiry dates — see EMP-PERF-004 |
| 21 | 1692-1697 | `render_requests_card()` | SELECT (information_schema) | Yes | Check leave_requests table existence |
| 22 | 1700-1712 | `render_requests_card()` | SELECT | Yes | Fetch recent leave requests |
| 23 | 1742-1747 | `render_requests_card()` | SELECT (information_schema) | Yes | Check loans table existence |
| 24 | 1750-1760 | `render_requests_card()` | SELECT | Yes | Fetch recent loans |
| 25 | 1778-1783 | `render_requests_card()` | SELECT (information_schema) | Yes | Check resignations table existence |
| 26 | 1786-1796 | `render_requests_card()` | SELECT | Yes | Fetch recent resignations |
| 27 | 1832-1837 | `render_requests_card()` | SELECT (information_schema) | Yes | Check settlements table existence |
| 28 | 1840-1850 | `render_requests_card()` | SELECT | Yes | Fetch recent settlements |

**Summary:** 27 prepared execution calls, 1 raw (line 244). The raw query at line 244 uses only a server-side-derived table prefix — no user input — so it carries no injection risk. It still violates the project's prepare-everything convention.

---

## Dual hire_date / hired_at Audit

Per CLAUDE.md: "sfs_hr_employees has both hire_date and hired_at — both are maintained for backwards compatibility; use hire_date for new code."

| Location | Column Used | Pattern | Notes |
|----------|-------------|---------|-------|
| Line 175 | `hired_at ?? (hire_date ?? '')` | Fallback | Canonical pattern — reads `hired_at` first, falls back to `hire_date`. Correct for display. |
| Line 389 (badge display) | `$hire_date` variable | Via line 175 | Inherits the fallback from line 175. |
| Line 497 (edit form) | `name="hired_at"` | Write only to `hired_at` | **Problem:** Form submit updates only `hired_at` via `handle_save_edit()`. The `hire_date` column is not updated by this form. See EMP-LOGIC-001. |
| Line 642 (view mode "Hire date") | `$hire_date` variable | Via line 175 | Display only. |

**Consistency assessment:** The fallback display pattern (line 175) is applied in ONE place and the `$hire_date` variable is used consistently for all display purposes. This is correct. The problem is the edit form saving to `hired_at` only — the `hire_date` column is not synced and will drift from `hired_at` over time. The save handler (`Core/Admin.php::handle_save_edit()`) should be audited separately to confirm whether it also updates `hire_date` when saving `hired_at`. If it does not, EMP-LOGIC-001 is a confirmed data integrity issue.

---

## WP User Creation Handler Audit

**Handler:** `handle_create_wp_user()` at lines 39-96
**Entry point:** `render_page()` at lines 102-105

**Privilege escalation assessment:**

| Check | Status | Notes |
|-------|--------|-------|
| Nonce verification | **PASS** | `check_admin_referer('sfs_hr_create_wp_user_' . $employee_id)` at line 41, using URL-embedded nonce |
| Capability check | **PASS** | `current_user_can('create_users')` at line 43 — WordPress core administrator capability |
| Employee ID validation | **PASS** | `(int) $_GET['employee_id']` with `$employee_id <= 0` guard |
| Already-linked guard | **PASS** | Lines 53-56 prevent creating duplicate WP users for linked employees |
| Email validation | **PASS** | `is_email()` at line 59, `email_exists()` at line 63 |
| Username collision | **PASS** | Incrementing suffix loop at lines 75-76 |
| Password generation | **PASS** | `wp_generate_password(16, true, true)` — strong password |
| Role assigned | **PASS** | `'role' => 'subscriber'` at line 85 — lowest WP role, correct |
| No privilege escalation path | **PASS** | Cannot assign administrator or higher role |
| `$wpdb->update()` | **PASS** | Prepared via `$wpdb->update()` helper which uses `$wpdb->prepare()` internally |

**Verdict:** The WP user creation handler is correctly implemented. No privilege escalation risk. The only architectural concern is that it lives inside `render_page()` rather than as a dedicated `admin_post_*` handler (see EMP-SEC-004).

---

## Cross-References to Prior Phase Findings

| Prior Finding | Applies Here? | Notes |
|---------------|--------------|-------|
| Phase 04: ALTER TABLE in admin_init | No | No DDL in this file |
| Phase 04: information_schema in admin_init | Yes — EMP-SEC-003, EMP-PERF-001 | Same antipattern, 8 occurrences |
| Phase 05: __return_true REST routes | No | No REST endpoints in this file |
| Phase 07: Missing capability guards on save | No | Save handlers in Core/Admin.php have `Helpers::require_cap('sfs_hr.manage')` guards |
| Phase 11: ASSET-SEC-003 information_schema | Yes — EMP-SEC-003 | Same antipattern confirmed in render_assets_card() |
| Phase 09: components_json duplication | No | Not applicable |
