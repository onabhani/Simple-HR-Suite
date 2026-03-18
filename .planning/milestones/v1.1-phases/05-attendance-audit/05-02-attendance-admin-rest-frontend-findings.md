# Attendance Admin / REST / Frontend Audit Findings

**Audit date:** 2026-03-16
**Scope:** Admin pages (1 file, 6984 lines), REST endpoints (3 files, 2930 lines), Frontend shortcodes (2 files, 4438 lines) — ~14.3K lines
**Auditor:** Claude (automated code review)
**Requirement:** CRIT-01

---

## Executive Summary

- **Critical:** 2 findings
- **High:** 9 findings
- **Medium:** 7 findings
- **Low:** 2 findings
- **Total:** 20 findings

### Top Issues

1. **`/attendance/status` is `__return_true`** — unauthenticated endpoint exposes device geofence config and employee snapshots (class-attendance-rest.php:78)
2. **`/attendance/verify-pin` is `__return_true`** — PIN brute-force on public endpoint, no rate limiting (class-attendance-rest.php:120)
3. **N+1 `dept_label_from_employee` in admin hub** — per-row DB query inside employee loop on Assignments tab (class-admin-pages.php:182)
4. **Unbounded employee query on Punches/Sessions tabs** — `$empRows` has no LIMIT when loading all employees for dropdown (class-admin-pages.php:5217)
5. **Sessions query interpolates `$where` string directly** — although `$where` is built via `prepare()`, the composed query at line 5688 is not wrapped in `prepare()`, meaning the raw `LIMIT/OFFSET` integer literals are injected without `%d` (class-admin-pages.php:5688)
6. **Early-leave `pending_requests()` GM branch runs unprepared SQL** — literal `get_results()` with no dynamic values but uses a hardcoded subquery which could be incorrectly extended (class-early-leave-rest.php:299)
7. **`kiosk_roster` exposes SHA-256 of raw QR token to any authenticated kiosk user** — token hash is enough to forge offline punches if adversary controls a kiosk device (class-attendance-rest.php:322)

---

## Security Findings

### Critical

#### ATT-API-SEC-001: `GET /attendance/status` uses `__return_true` — unauthenticated access

- **File:** `includes/Modules/Attendance/Rest/class-attendance-rest.php:78-82`
- **Description:** The `/sfs-hr/v1/attendance/status` endpoint has `permission_callback => '__return_true'`, making device geofence coordinates, selfie policy, and — when the requester happens to have a valid WP session — the employee's full attendance snapshot available without any authentication check.
- **Evidence:**
  ```php
  register_rest_route('sfs-hr/v1', '/attendance/status', [
      'methods'  => 'GET',
      'callback' => [ __CLASS__, 'status' ],
      // public JSON; we only include self snapshot when logged in
      'permission_callback' => '__return_true',
  ]);
  ```
- **Fix:** Change permission_callback to require `is_user_logged_in()` at minimum. Device configuration (geo coordinates, selfie policy) should require the kiosk role or admin capability. The snapshot data is already gated by `is_user_logged_in() && current_user_can('sfs_hr_attendance_view_self')` inside the handler, but unauthenticated callers still receive full device metadata including geofence lat/lng/radius.

---

#### ATT-API-SEC-002: `POST /attendance/verify-pin` uses `__return_true` — PIN brute-force possible

- **File:** `includes/Modules/Attendance/Rest/class-attendance-rest.php:117-125`
- **Description:** The verify-pin endpoint is fully public with no authentication or rate limiting. An attacker can make unlimited requests to brute-force the 4–6 digit kiosk manager PIN. The punch endpoint has per-IP rate limiting but this separate endpoint does not.
- **Evidence:**
  ```php
  register_rest_route('sfs-hr/v1', '/attendance/verify-pin', [
      'methods'  => 'POST',
      'callback' => [ __CLASS__, 'verify_pin' ],
      'permission_callback' => '__return_true', // Public endpoint, PIN itself provides auth
      'args' => [
          'device_id' => [ 'type'=>'integer', 'required'=>true ],
          'pin'       => [ 'type'=>'string',  'required'=>true ],
      ],
  ]);
  ```
- **Fix:** Add rate limiting inside the `verify_pin` handler (mirror the punch rate-limit pattern: per-IP transient with exponential backoff). Add progressive lockout after N failed attempts per device (e.g., 5 failures → 15-minute lockout stored in a transient keyed to `device_id`). Consider requiring `is_user_logged_in()` since the kiosk operator is always authenticated.

---

### High

#### ATT-API-SEC-003: Admin page `render_punches()` composes LIMIT/OFFSET literals directly into SQL string

- **File:** `includes/Modules/Attendance/Admin/class-admin-pages.php:5274-5289`
- **Description:** The punches query injects `{$per_page}` and `{$offset}` as raw PHP integers into the SQL string without using `$wpdb->prepare()`. Although both variables are derived from `(int)` casts (making them safe here), the query is never passed through `prepare()`, creating a pattern that's risky for future modifications.
- **Evidence:**
  ```php
  $sql = "
    SELECT p.id, p.employee_id, ...
    FROM {$pT} p ...
    {$where}
    ORDER BY p.punch_time DESC, p.id DESC
    LIMIT {$per_page} OFFSET {$offset}
  ";
  $rows = $wpdb->get_results( $sql );
  ```
- **Fix:** Wrap the final SELECT in `$wpdb->prepare()` using `%d` placeholders for `$per_page` and `$offset`. The `$where` clause is already safely composed, but the full query should go through `prepare()`:
  ```php
  $rows = $wpdb->get_results( $wpdb->prepare( $sql . " LIMIT %d OFFSET %d", $per_page, $offset ) );
  ```

---

#### ATT-API-SEC-004: Sessions view composes LIMIT/OFFSET literals directly into SQL string

- **File:** `includes/Modules/Attendance/Admin/class-admin-pages.php:5688-5698`
- **Description:** Same pattern as ATT-API-SEC-003 in the sessions rendering. `$sess_per_page` and `$sess_offset` are PHP integers but the query is not wrapped in `prepare()`.
- **Evidence:**
  ```php
  $rows = $wpdb->get_results("
      SELECT s.*, u.display_name, e.employee_code
      FROM {$sT} s ...
      {$where}
      ORDER BY {$orderSQL}
      LIMIT {$sess_per_page} OFFSET {$sess_offset}
  ");
  ```
- **Fix:** Wrap the full query in `$wpdb->prepare()` with `%d` for LIMIT and OFFSET values.

---

#### ATT-API-SEC-005: `kiosk_roster` exposes SHA-256 token hashes allowing offline punch forgery

- **File:** `includes/Modules/Attendance/Rest/class-attendance-rest.php:317-323`
- **Description:** The endpoint sends the SHA-256 hash of each employee's `qr_token` to any authenticated kiosk operator. An attacker who compromises one kiosk device (or obtains its credentials) can retrieve SHA-256 hashes for all employees in the allowed department and use them to forge offline punches, since the offline punch path validates by checking the hash directly.
- **Evidence:**
  ```php
  $employees[] = [
      'id'         => (int) $r['id'],
      'code'       => (string) ( $r['employee_code'] ?? '' ),
      'name'       => $name,
      // SHA-256 hash — client computes same hash on scanned token to verify
      'token_hash' => hash( 'sha256', (string) $r['qr_token'] ),
  ];
  ```
- **Fix:** HMAC the token with a server-side secret key (use `wp_hash()` or `hash_hmac('sha256', $token, AUTH_KEY)`) and rotate the roster's signed tokens periodically. Store HMAC in the roster, not a plain SHA-256 of the raw secret. Consider requiring device ID + timestamp signature in the roster payload.

---

#### ATT-API-SEC-006: Missing `check_admin_referer` on `handle_rebuild_sessions_day` admin-post handler

- **File:** `includes/Modules/Attendance/Admin/class-admin-pages.php:6046-6047` (handle_rebuild_sessions_day)
- **Description:** The rebuild-sessions POST handler checks `current_user_can` but does not call `check_admin_referer()` before processing. An admin can be CSRF-tricked into triggering a session rebuild for any date.
- **Evidence:**
  ```php
  if ( ! current_user_can('sfs_hr_attendance_admin') ) { wp_die( esc_html__( 'Access denied', 'sfs-hr' ) ); }
  // No check_admin_referer() call before rebuild logic
  ```
- **Fix:** Add `check_admin_referer('sfs_hr_att_rebuild_sessions_day')` immediately after the capability check. The rebuild link in the UI already uses `wp_nonce_url()` with this action string, so adding the server-side check is a one-liner.

---

#### ATT-API-SEC-007: Admin REST `can_admin()` uses WordPress capability without `is_user_logged_in()` guard

- **File:** `includes/Modules/Attendance/Rest/class-attendance-admin-rest.php:130-132`
- **Description:** `can_admin()` only calls `current_user_can('sfs_hr_attendance_admin')`, which WordPress evaluates as false for logged-out users — but does not explicitly check `is_user_logged_in()`. This means REST WordPress auto-authentication (nonce or application password) is the only gate, which is correct for REST but is inconsistent with the rest of the codebase and may allow capability grants from unexpected auth paths.
- **Evidence:**
  ```php
  public static function can_admin(): bool {
      return current_user_can( 'sfs_hr_attendance_admin' );
  }
  ```
- **Fix:** Add explicit `is_user_logged_in()` check: `return is_user_logged_in() && current_user_can('sfs_hr_attendance_admin');` — consistent with `can_punch()` and other permission callbacks.

---

#### ATT-API-SEC-008: Early-leave `review_request` accepts `sfs_hr_loans_gm_approve` as a valid capability at route level

- **File:** `includes/Modules/Attendance/Rest/class-early-leave-rest.php:51-56`
- **Description:** The route-level permission check for the review endpoint accepts `sfs_hr_loans_gm_approve` (a Loans module capability) as a valid gate, meaning any Loans GM can call this endpoint. The handler does re-check whether the user is the configured GM, but a Loans GM who is not the configured HR GM still passes the route check and receives the full request payload before being rejected.
- **Evidence:**
  ```php
  'permission_callback' => fn() => current_user_can( 'sfs_hr_loans_gm_approve' )
      || current_user_can( 'sfs_hr.leave.review' ),
  ```
- **Fix:** Restrict to `sfs_hr.leave.review` only. The handler's GM check uses `sfs_hr_leave_gm_approver` option (a user ID), so any user who passes the route check and is the configured GM will be handled correctly. Removing the Loans cap from route-level avoids cross-module privilege leakage.

---

#### ATT-API-SEC-009: `get_pending_count_for_user()` runs unprepared query in GM branch

- **File:** `includes/Modules/Attendance/Rest/class-early-leave-rest.php:594-601`
- **Description:** The GM branch of `get_pending_count_for_user()` uses `$wpdb->get_var()` with a raw string query (no `$wpdb->prepare()`). While there are no direct user inputs in this specific query, the pattern is inconsistent with the codebase standard and fragile if the query is ever extended.
- **Evidence:**
  ```php
  $count += (int) $wpdb->get_var(
      "SELECT COUNT(*) FROM {$table} r
       JOIN {$emp_t} e ON e.id = r.employee_id
       WHERE r.status = 'pending'
         AND e.user_id IN (
             SELECT manager_user_id FROM {$dept_t} WHERE active = 1 AND manager_user_id IS NOT NULL
         )"
  );
  ```
- **Fix:** Wrap in `$wpdb->prepare()`. Even though there are no user-supplied values, this enforces consistent pattern and protects future changes.

---

### Medium

#### ATT-API-SEC-010: Widget_Shortcode loads Leaflet from `unpkg.com` CDN without SRI integrity

- **File:** `includes/Modules/Attendance/Frontend/Widget_Shortcode.php:82-83`
- **Description:** The widget shortcode unconditionally loads Leaflet CSS and JS from `unpkg.com` without Subresource Integrity (SRI) attributes. The admin pages version uses SRI + a local fallback; the frontend widget does not.
- **Evidence:**
  ```php
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
  ```
- **Fix:** Add `integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="` to the CSS link and `integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="` to the script tag (matching the admin page's SRI values for 1.9.4). Add a local fallback like the admin page does.

---

#### ATT-API-SEC-011: `Kiosk_Shortcode` inlines `jsQR` from CDN without SRI

- **File:** `includes/Modules/Attendance/Frontend/Kiosk_Shortcode.php:777`
- **Description:** The kiosk loads `jsQR` from jsDelivr CDN without an SRI integrity hash. A CDN compromise could inject malicious QR scanning code on the kiosk terminal.
- **Evidence:**
  ```html
  <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js" defer></script>
  ```
- **Fix:** Compute `sha256-...` integrity hash for `jsQR@1.4.0` and add `integrity="..."` attribute. Better: vendor the file to `assets/vendor/jsqr/` and serve locally, eliminating the CDN dependency.

---

## Performance Findings

### High

#### ATT-API-PERF-001: N+1 query in `dept_label_from_employee()` on Assignments tab

- **File:** `includes/Modules/Attendance/Admin/class-admin-pages.php:176-186`
- **Description:** `dept_label_from_employee()` fires a separate `SELECT name FROM sfs_hr_departments WHERE id=%d` query for every employee row that has a `dept_id` but no `dept_slug`. On the Assignments tab where all active employees are loaded, this creates one DB query per employee.
- **Evidence:**
  ```php
  if ( $map['dept_id'] && isset($row->{$map['dept_id']}) ) {
      $deptT = $wpdb->prefix . 'sfs_hr_departments';
      $name  = $wpdb->get_var( $wpdb->prepare("SELECT name FROM {$deptT} WHERE id=%d", (int)$row->{$map['dept_id']}) );
  ```
- **Fix:** Pre-load departments into a keyed array before the employee loop:
  ```php
  $dept_map = wp_list_pluck( $this->get_departments( $wpdb ), 'name', 'id' );
  ```
  Then replace the per-row query with `$dept_map[(int)$row->dept_id] ?? 'unknown'`.

---

#### ATT-API-PERF-002: Unbounded employee dropdown query on Punches and Sessions tabs

- **File:** `includes/Modules/Attendance/Admin/class-admin-pages.php:5217-5228` (Punches), `5724-5730` (Sessions)
- **Description:** Both the Punches tab and Sessions tab load ALL active employees into an HTML `<select>` for the filter dropdown with no LIMIT. On large deployments (hundreds or thousands of employees), this means the admin page loads the full employee roster on every page load.
- **Evidence:**
  ```php
  $empRows = $wpdb->get_results("
      SELECT e.id, COALESCE(...) AS name
      FROM {$eT} e
      LEFT JOIN {$uT} u ON u.ID = e.user_id
      WHERE e.status = 'active'
      ORDER BY name ASC
  ");
  ```
- **Fix:** Replace the `<select>` with an AJAX-powered autocomplete or add a `LIMIT 200` cap (with a note when truncated). Alternatively, paginate or lazy-load the employee list only when the filter is focused.

---

### Medium

#### ATT-API-PERF-003: `render_sessions` calls `SHOW COLUMNS FROM` on every admin page load

- **File:** `includes/Modules/Attendance/Admin/class-admin-pages.php:5704`
- **Description:** `SHOW COLUMNS FROM` is executed on every sessions page load to detect schema variants. This is an `information_schema` query that MySQL/MariaDB may not cache effectively.
- **Evidence:**
  ```php
  $cols = $wpdb->get_col("SHOW COLUMNS FROM {$empT}", 0) ?: [];
  ```
- **Fix:** Cache the column list in a short-lived transient (e.g., `sfs_hr_emp_col_cache`, TTL 1 hour) or use `wp_cache_get/set`.

---

#### ATT-API-PERF-004: Count query for sessions is not prepared when `$where` is empty

- **File:** `includes/Modules/Attendance/Admin/class-admin-pages.php:5681`
- **Description:** When no `$where` clause exists (all-employees, single-day query resolves to empty string at initialization), the count query runs without a `prepare()` call, and the large sessions table is counted without any index-friendly filter.
- **Evidence:**
  ```php
  $totalSessions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sT} s {$where}");
  ```
- **Fix:** Enforce a date default so `$where` is never empty (always filter by at least one date). This both prevents full-table scans and ensures the query benefits from the `work_date` index.

---

#### ATT-API-PERF-005: `Admin_REST::devices_list` runs `SELECT *` with no LIMIT

- **File:** `includes/Modules/Attendance/Rest/class-attendance-admin-rest.php:305-307`
- **Description:** The devices list REST endpoint returns all devices with no pagination or LIMIT. While device counts are expected to be small, the unbounded pattern is inconsistent and risky if the table grows unexpectedly.
- **Evidence:**
  ```php
  $rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY active DESC, allowed_dept_id, label" );
  ```
- **Fix:** Minor — add `LIMIT 200` or add pagination parameters to the endpoint.

---

## Logic Findings

### High

#### ATT-API-LOGIC-001: Scan token is "peeked not consumed" — any punch type can be submitted with the same token

- **File:** `includes/Modules/Attendance/Rest/class-attendance-rest.php:637-656`
- **Description:** The comment documents this intentionally: the scan token is never consumed after a successful punch, allowing the same scan token to be reused for all four punch types (in, out, break_start, break_end) during the 10-minute TTL. A malicious kiosk operator could scan one employee's QR code and then submit multiple punch types for that employee in quick succession without re-scanning.
- **Evidence:**
  ```php
  $payload = self::get_scan_token( $scan_token ); // ← peek, don't consume (allows multiple punches)
  ```
- **Fix:** Consume the token on the FIRST successful punch, then mint a new continuation token (with the same `employee_id` and `device_id`) that covers the remaining valid transitions. Return this continuation token in the punch response. The JS client should use it for subsequent punches in the same sequence.

---

#### ATT-API-LOGIC-002: `pending_requests` GM branch may return requests the GM has no authority to approve

- **File:** `includes/Modules/Attendance/Rest/class-early-leave-rest.php:295-309`
- **Description:** The GM's pending list shows ELRs from all department managers. But the GM can only approve ELRs from managers at the first hierarchy level — if a manager-of-managers exists (e.g., a regional director who manages department managers), the GM would see their requests but the handler's `review_request` logic only checks `sfs_hr_leave_gm_approver` option, so a second-level manager could never get their ELR approved through this path.
- **Evidence:**
  ```php
  $rows = $wpdb->get_results(
      "SELECT r.*, e.first_name, e.last_name, e.employee_number
       FROM {$table} r
       LEFT JOIN {$emp_t} e ON e.id = r.employee_id
       WHERE r.status = 'pending'
         AND e.user_id IN (
             SELECT manager_user_id FROM {$dept_t} WHERE active = 1 AND manager_user_id IS NOT NULL
         ) ..."
  );
  ```
- **Fix:** Document the single-level hierarchy assumption clearly. If multi-level management is ever needed, the approval chain logic will need redesigning. For now, add a code comment confirming the GM only approves direct department managers.

---

### Medium

#### ATT-API-LOGIC-003: `Admin_REST::assign_bulk` deletes existing assignments before re-checking, no transaction

- **File:** `includes/Modules/Attendance/Rest/class-attendance-admin-rest.php:283-297`
- **Description:** When `$overwrite` is true, the handler deletes the existing assignment then re-inserts, but this is not wrapped in a transaction. A crash between delete and insert leaves the day without an assignment.
- **Evidence:**
  ```php
  if ( $overwrite ) {
      $wpdb->delete( $t, ['employee_id'=>$eid, 'work_date'=>$d] );
  }
  $exists = $wpdb->get_var( ... );
  if ( ! $exists ) {
      $wpdb->insert( $t, [ ... ] );
  }
  ```
- **Fix:** Wrap the delete+insert pair in `START TRANSACTION` / `COMMIT` or use `INSERT ... ON DUPLICATE KEY UPDATE` (requires a unique key on `(employee_id, work_date)`).

---

#### ATT-API-LOGIC-004: `assign_bulk` iterates up to 366 × N employees with per-row `get_var` existence check

- **File:** `includes/Modules/Attendance/Rest/class-attendance-admin-rest.php:280-297`
- **Description:** For each day in the range and each employee, a separate `SELECT id` query is executed to check if an assignment already exists. A 30-day range with 50 employees = 1500 separate queries.
- **Evidence:**
  ```php
  for ( $i=0; $i<= $days; $i++ ) {
      $d = $start->modify("+{$i} day")->format('Y-m-d');
      foreach ( $emps as $eid ) {
          ...
          $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE employee_id=%d AND work_date=%s LIMIT 1", $eid, $d ) );
          if ( ! $exists ) { $wpdb->insert( ... ); }
      }
  }
  ```
- **Fix:** Use `INSERT IGNORE` (with a unique key on `employee_id, work_date`) or batch the existence check into a single `SELECT employee_id, work_date FROM … WHERE (employee_id, work_date) IN (…)` query, then only insert missing rows.

---

#### ATT-API-LOGIC-005: Widget_Shortcode loads Leaflet CSS/JS inline on every shortcode render, even when Leaflet is already registered

- **File:** `includes/Modules/Attendance/Frontend/Widget_Shortcode.php:82-83`
- **Description:** The widget shortcode renders `<link>` and `<script>` tags inline in the shortcode output (inside the content area). If two attendance widgets appear on the same page, or if Leaflet is already enqueued by the theme/admin, duplicate scripts are inserted. This is also an anti-pattern — assets should be enqueued via `wp_enqueue_*` hooks, not echoed inline.
- **Evidence:**
  ```php
  ob_start(); ?>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
  ```
- **Fix:** Move Leaflet registration and enqueuing to the `wp_enqueue_scripts` hook. Register with `wp_register_script/style` once, then call `wp_enqueue_*` from the shortcode render method (before `ob_start()`). WordPress deduplicates enqueued handles automatically.

---

#### ATT-API-LOGIC-006: Kiosk shortcode registers `wp-api` script inline — may conflict with theme

- **File:** `includes/Modules/Attendance/Frontend/Kiosk_Shortcode.php:780`
- **Description:** The kiosk shortcode calls `wp_enqueue_script('wp-api')` inside the render function after `ob_start()`. This is called during content rendering, which is too late for the script to be reliably enqueued in `<head>` if themes use `wp_head()` before content.
- **Evidence:**
  ```php
  // Make sure WP exposes a nonce helper on the front-end
  wp_enqueue_script('wp-api');
  ```
- **Fix:** Enqueue `wp-api` in a `wp_enqueue_scripts` action before output starts, or attach to the `shortcode_init` phase. The script is output-buffered anyway so the tag renders correctly, but the order is non-standard.

---

## Files Reviewed

| File | Lines | Findings |
|------|-------|----------|
| Admin/class-admin-pages.php | 6984 | 6 |
| Rest/class-attendance-rest.php | 1637 | 5 |
| Rest/class-attendance-admin-rest.php | 667 | 4 |
| Rest/class-early-leave-rest.php | 626 | 3 |
| Frontend/Kiosk_Shortcode.php | 2514 | 2 |
| Frontend/Widget_Shortcode.php | 1924 | 2 |

---

## REST Endpoint Permission Callback Summary

| Endpoint | Method | Permission Callback | Status |
|----------|--------|---------------------|--------|
| `/attendance/punch` | POST | `can_punch()` (checks `sfs_hr_attendance_clock_self`, `clock_kiosk`, or `attendance_admin`) | OK |
| `/attendance/status` | GET | `__return_true` | **CRITICAL — see ATT-API-SEC-001** |
| `/attendance/scan` | GET | `is_user_logged_in() && (clock_kiosk OR attendance_admin)` | OK |
| `/attendance/kiosk-roster` | GET | `is_user_logged_in() && (clock_kiosk OR attendance_admin)` | OK |
| `/attendance/verify-pin` | POST | `__return_true` | **CRITICAL — see ATT-API-SEC-002** |
| `/attendance/shifts` (GET/POST) | GET,POST | `can_admin()` → `current_user_can(attendance_admin)` | OK (add is_user_logged_in) |
| `/attendance/shifts/{id}` (DELETE) | DELETE | `can_admin()` | OK |
| `/attendance/assign` | GET,POST | `can_admin()` | OK |
| `/attendance/devices` | GET,POST | `can_admin()` | OK |
| `/attendance/devices/{id}` (DELETE) | DELETE | `can_admin()` | OK |
| `/attendance/punches/admin-create` | POST | `can_admin()` | OK |
| `/attendance/punches/{id}/admin-edit` | PUT | `can_admin()` | OK |
| `/attendance/punches/{id}/admin-delete` | DELETE | `can_admin()` | OK |
| `/attendance/sessions/rebuild` | POST | `can_admin()` | OK |
| `/early-leave/request` | POST | `sfs_hr_attendance_clock_self` | OK |
| `/early-leave/my-requests` | GET | `sfs_hr_attendance_view_self` | OK |
| `/early-leave/cancel/{id}` | POST | `sfs_hr_attendance_clock_self` | OK |
| `/early-leave/pending` | GET | `view_team OR leave.review` | OK |
| `/early-leave/review/{id}` | POST | `loans_gm_approve OR leave.review` | HIGH — see ATT-API-SEC-008 |
| `/early-leave/list` | GET | `attendance_admin` | OK |

---

## Admin AJAX / Admin-Post Handler Summary

| Action | Handler | Cap Check | Nonce Check | Status |
|--------|---------|-----------|-------------|--------|
| `sfs_hr_att_save_settings` | `handle_save_settings` | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_shift_save` | `handle_shift_save` | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_shift_delete` | `handle_shift_delete` | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_assign_bulk` | `handle_assign_bulk` | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_bulk_default_shift` | `handle_bulk_default_shift` | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_device_save` | `handle_device_save` | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_device_delete` | `handle_device_delete` | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_save_automation` | `handle_save_automation` | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_export_csv` | `handle_export_csv` | `view_team` | `check_admin_referer` | OK |
| `sfs_hr_att_rebuild_sessions_day` | `handle_rebuild_sessions_day` | `attendance_admin` | **MISSING** | HIGH — see ATT-API-SEC-006 |
| `sfs_hr_att_save_template` | handler | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_delete_template` | handler | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_apply_template` | `handle_apply_template` | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_save_auto_rules` | handler | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_delete_auto_rule` | handler | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_run_auto_rules` | handler | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_schedule_save` | handler | `attendance_admin` | `check_admin_referer` | OK |
| `sfs_hr_att_schedule_delete` | handler | `attendance_admin` | `check_admin_referer` | OK |

---

## Kiosk Security Analysis

The kiosk shortcode (`Kiosk_Shortcode.php`) implements a multi-layer security model:

**Authentication:** Requires `sfs_hr_attendance_clock_kiosk` or `sfs_hr_attendance_admin` capability (line 22). Correct.

**QR token flow:**
1. Employee QR code is scanned by camera (client-side, jsQR)
2. Client calls `GET /scan` with `emp` + `token` → server mints a short-lived scan token (10-minute TTL, stored as WP transient)
3. Client uses scan token in `POST /punch`
4. Server validates scan token exists in transients → accepts punch

**Issues identified:**
- Token is peeked, not consumed after first use (see ATT-API-LOGIC-001 — Medium/High)
- Offline mode sends SHA-256 token hashes in the roster response (ATT-API-SEC-005 — High)

**Session state machine:** The kiosk enforces punch transitions (in → break_start/out → break_end → out) correctly via the `snapshot_for_today()` state machine. Double-punch within 30 seconds is rejected with a cooldown. Rate limiting (30/min/IP) is applied at the punch endpoint.

**Upload security:** Selfie uploads are validated with `wp_check_filetype_and_ext()` and restricted to MIME types `image/jpeg`, `image/png`, `image/webp`, `image/heic`. Stored as private attachments. This is correct.

**Geofence bypass:** Kiosk punches skip geofence validation intentionally (physical presence at kiosk is the gate). This design decision is documented in code and acceptable.

---

## Recommendations Priority

### 1. Immediate (Critical)

- **ATT-API-SEC-001**: Add `is_user_logged_in()` gate (at minimum) to `GET /attendance/status` permission_callback. Consider requiring kiosk/admin cap for device metadata exposure.
- **ATT-API-SEC-002**: Add per-device rate limiting and progressive lockout to `POST /attendance/verify-pin`.

### 2. Next Sprint (High)

- **ATT-API-SEC-006**: Add `check_admin_referer('sfs_hr_att_rebuild_sessions_day')` to the rebuild handler.
- **ATT-API-SEC-003 & 004**: Wrap LIMIT/OFFSET into `$wpdb->prepare()` in punches and sessions queries.
- **ATT-API-SEC-005**: Replace plain SHA-256 token hash in kiosk roster with HMAC-SHA-256 using server secret.
- **ATT-API-SEC-007**: Add `is_user_logged_in()` to `Admin_REST::can_admin()`.
- **ATT-API-SEC-008**: Remove `sfs_hr_loans_gm_approve` from early-leave review route permission.
- **ATT-API-PERF-001**: Fix N+1 dept label query — pre-load departments before employee loop.
- **ATT-API-PERF-002**: Add LIMIT or autocomplete to employee dropdown queries.
- **ATT-API-LOGIC-001**: Consume scan token on first successful punch; mint continuation token.

### 3. Backlog (Medium/Low)

- **ATT-API-SEC-010 & 011**: Add SRI hashes to CDN-loaded Leaflet and jsQR in frontend shortcodes.
- **ATT-API-SEC-009**: Wrap GM count query in `$wpdb->prepare()`.
- **ATT-API-PERF-003**: Cache `SHOW COLUMNS` result in a transient.
- **ATT-API-PERF-004**: Ensure sessions count query always has a date filter.
- **ATT-API-PERF-005**: Add LIMIT to devices list REST endpoint.
- **ATT-API-LOGIC-003**: Wrap `assign_bulk` overwrite delete+insert in a transaction.
- **ATT-API-LOGIC-004**: Batch existence checks in `assign_bulk` to avoid per-row queries.
- **ATT-API-LOGIC-005**: Move Widget_Shortcode Leaflet enqueue to `wp_enqueue_scripts` hook.
- **ATT-API-LOGIC-006**: Move Kiosk `wp-api` script enqueue to a proper hook.
