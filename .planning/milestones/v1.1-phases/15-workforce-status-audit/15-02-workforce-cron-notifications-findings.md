# Phase 15, Plan 02: Workforce_Status Cron & Notifications Audit Findings

**Phase:** 15 — Workforce_Status Module Audit
**Plan:** 02 of 02
**Files audited:** 2 files, ~664 lines
**Date:** 2026-03-16
**Auditor:** GSD Execute Agent (claude-sonnet-4-6)

---

## Files Audited

| File | Lines | Role |
|------|-------|------|
| `includes/Modules/Workforce_Status/Cron/Absent_Cron.php` | 161 | Cron scheduling, manual trigger, status reporting |
| `includes/Modules/Workforce_Status/Notifications/Absent_Notifications.php` | 503 | Absent employee detection, manager/employee email notifications |

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 0 | 1 | 1 | 1 | 3 |
| Performance | 0 | 3 | 1 | 0 | 4 |
| Duplication | 0 | 0 | 2 | 0 | 2 |
| Logical | 0 | 2 | 1 | 1 | 4 |
| **Total** | **0** | **6** | **5** | **2** | **13** |

---

## Security Findings

### WNTF-SEC-001 — Unnecessary `$wpdb->prepare()` With Empty Array Triggers WP Deprecation Notice

**Severity:** Medium
**Location:** `Absent_Notifications.php:32-44`

**Description:**
The employees query on line 32 is wrapped in `$wpdb->prepare(...)` with an empty array as the second argument:

```php
$employees = $wpdb->get_results( $wpdb->prepare(
    "SELECT e.id, e.employee_code, ... WHERE e.status = 'active' ...",
    []
) );
```

The base SQL string contains no `%s` or `%d` placeholders. WordPress 6.x+ emits a deprecation notice: _"wpdb::prepare was called incorrectly. The query argument does not contain a placeholder."_ The call is unnecessary and generates noise in debug logs.

**Impact:** PHP deprecation notice on every cron run when WP_DEBUG is enabled. Not a security vulnerability, but indicates incorrect API usage and clutters error logs.

**Fix Recommendation:** Remove the `$wpdb->prepare()` wrapper entirely and call `$wpdb->get_results()` directly with the static query. The query is fully static (all values are hardcoded string literals), so no preparation is needed:

```php
// Before
$employees = $wpdb->get_results( $wpdb->prepare(
    "SELECT e.id ... WHERE e.status = 'active'...",
    []
), ARRAY_A );

// After
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static query, no user input
$employees = $wpdb->get_results(
    "SELECT e.id ... WHERE e.status = 'active'...",
    ARRAY_A
);
```

---

### WNTF-SEC-002 — `update_settings()` Has No Sanitization — Caller Responsibility Undocumented

**Severity:** Low
**Location:** `Absent_Notifications.php:500-502`

**Description:**
`update_settings()` accepts an arbitrary `array $settings` and passes it directly to `update_option()` without any sanitization, type coercion, or allowlist enforcement:

```php
public static function update_settings( array $settings ): void {
    update_option( 'sfs_hr_absent_notification_settings', $settings );
}
```

However, an audit of all callers in the Workforce_Status module (via grep) shows that `update_settings()` is **currently not called by any admin POST handler** in the module — it is defined but never invoked from a handler that accepts user input. The method is dead from a security-attack perspective today.

**Impact:** If a handler is added in the future that calls this without sanitization, arbitrary data could be persisted in `wp_options`. Low risk today because the method is not wired up.

**Fix Recommendation:** Either add sanitization inside `update_settings()` itself (preferred — defense in depth), or add a PHPDoc note documenting that callers are responsible for sanitizing before calling:

```php
public static function update_settings( array $settings ): void {
    $clean = [
        'enabled'          => (bool) ( $settings['enabled'] ?? true ),
        'send_time'        => sanitize_text_field( $settings['send_time'] ?? '20:00' ),
        'min_employees'    => max( 0, (int) ( $settings['min_employees'] ?? 0 ) ),
        'notify_employees' => (bool) ( $settings['notify_employees'] ?? true ),
    ];
    update_option( 'sfs_hr_absent_notification_settings', $clean );
}
```

---

### WCRN-SEC-001 — `trigger_manual()` Not Exposed to Any Handler — Informational

**Severity:** High (if exposed), currently Informational
**Location:** `Absent_Cron.php:133-142`

**Description:**
`trigger_manual()` is a `public static` method that sends absent notifications for any arbitrary date. A full audit of all files in `includes/Modules/Workforce_Status/` confirms it is **not registered with any `admin-post` action, REST endpoint, or AJAX hook**. `WorkforceStatusModule::hooks()` only registers `Absent_Cron::hooks()`, which wires up `schedule`, `reschedule`, and `run` — not `trigger_manual`.

```php
public static function trigger_manual( ?string $date = null ): int {
    // No capability check
    if ( ! $date ) {
        $date = current_time( 'Y-m-d' );
    }
    $manager_sent  = Absent_Notifications::send_absent_notifications( $date );
    $employee_sent = Absent_Notifications::send_employee_absent_notifications( $date );
    return $manager_sent + $employee_sent;
}
```

The method contains **no capability check**. If any future developer wires it to an admin-post or REST action without adding their own capability check, notification spam to all managers and employees for any historical date becomes possible by any authenticated user.

**Impact:** No current attack surface. Future exposure risk is High.

**Fix Recommendation:** Add a capability check inside `trigger_manual()` as defense in depth:

```php
public static function trigger_manual( ?string $date = null ): int {
    if ( ! current_user_can( 'sfs_hr.manage' ) ) {
        return 0;
    }
    // ... rest of method
}
```

---

## Performance Findings

### WNTF-PERF-001 — Double Absent Employee Detection Per Cron Run (Duplicate Query Execution)

**Severity:** High
**Location:** `Absent_Cron.php:105-106`, `Absent_Notifications.php:217`, `Absent_Notifications.php:360`

**Description:**
`Absent_Cron::run()` calls both notification methods sequentially:

```php
// Absent_Cron.php:105-106
$manager_sent  = Absent_Notifications::send_absent_notifications( $today );   // line 105
$employee_sent = Absent_Notifications::send_employee_absent_notifications( $today ); // line 106
```

Each of these methods begins by independently calling `get_absent_employees_by_department()`:

```php
// send_absent_notifications() — line 217
$absent_by_dept = self::get_absent_employees_by_department( $date );

// send_employee_absent_notifications() — line 360
$absent_by_dept = self::get_absent_employees_by_department( $date );
```

`get_absent_employees_by_department()` runs **3 database queries** (employees join departments+users, clocked-in employees, on-leave employees). This means on every single cron execution, these 3 queries run **twice**. The second execution is entirely redundant — same date, same data.

**Impact:** On a 500-employee org, each cron run executes 6 database queries when 3 would suffice. The employee query fetches all active employees with joins on every run.

**Fix Recommendation:** Refactor `Absent_Cron::run()` to call `get_absent_employees_by_department()` once and pass the result to both notification methods:

```php
public function run(): void {
    $settings = Absent_Notifications::get_settings();
    if ( ! $settings['enabled'] ) { return; }

    $today          = current_time( 'Y-m-d' );
    $absent_by_dept = Absent_Notifications::get_absent_employees_by_department( $today );

    $manager_sent  = Absent_Notifications::send_absent_notifications( $today, $absent_by_dept );
    $employee_sent = Absent_Notifications::send_employee_absent_notifications( $today, $absent_by_dept );
    // ...
}
```

Both `send_absent_notifications()` and `send_employee_absent_notifications()` would need to accept an optional pre-computed `$absent_by_dept` parameter, falling back to `get_absent_employees_by_department()` when called independently.

---

### WNTF-PERF-002 — N+1 Shift Resolution Per Absent Employee

**Severity:** High
**Location:** `Absent_Notifications.php:107`, `Absent_Notifications.php:184-208`

**Description:**
Inside `get_absent_employees_by_department()`, for each active employee that is not clocked in and not on leave, `is_employee_day_off()` is called:

```php
// line 107
if ( self::is_employee_day_off( $emp_id, $date ) ) {
    continue;
}
```

`is_employee_day_off()` at line 184-208 calls `AttendanceModule::resolve_shift_for_date()` once **per employee** (line 203):

```php
$shift = AttendanceModule::resolve_shift_for_date( $emp_id, $ymd );
```

This is an N+1 pattern: for 500 active employees, this produces up to 500 individual calls to `resolve_shift_for_date()`. This is the same antipattern identified in Plan 01 (`WADM-PERF-002` — N+1 in `Admin_Pages::get_employee_shifts_map()`).

**Impact:** On a 500-employee org, cron run executes up to 500 shift-resolution calls. Each call likely involves a DB query. This can make cron run for tens of seconds.

**Fix Recommendation:** Batch shift resolution using the same approach recommended for `Admin_Pages`: build a batch resolver in `AttendanceModule` that accepts an array of employee IDs and returns a map, then call it once before the employee loop:

```php
// Before loop: resolve all shifts in one batch query
$shifts_map = AttendanceModule::resolve_shifts_for_date_batch( $emp_ids, $date );

foreach ( $employees as $emp ) {
    $emp_id = (int) $emp['id'];
    // ...
    $shift = $shifts_map[ $emp_id ] ?? null;
    if ( $shift === null && /* not global off day */ ) {
        continue; // day off
    }
}
```

Cross-reference: Same antipattern as `WADM-PERF-002` (Phase 15 Plan 01).

---

### WNTF-PERF-003 — All Active Employees Loaded With No LIMIT on Every Cron Run

**Severity:** High
**Location:** `Absent_Notifications.php:32-45`

**Description:**
The employees query fetches **all active employees** with no `LIMIT` clause:

```php
$employees = $wpdb->get_results( $wpdb->prepare(
    "SELECT e.id, ... FROM {$emp_table} e
     LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
     LEFT JOIN {$dept_table} d ON e.dept_id = d.id
     WHERE e.status = 'active'
       AND e.dept_id IS NOT NULL
       AND d.manager_user_id IS NOT NULL
       AND d.active = 1",
    []
), ARRAY_A );
```

At 500+ employees, this returns a potentially large result set into PHP memory on every cron execution.

**Impact:** Memory pressure on cron server for large organizations. Combined with WNTF-PERF-001 (query runs twice), this is 2× unbounded employee fetches per cron run.

**Fix Recommendation:** Pagination is not straightforward for a cron job that must process all employees. However, batching the loop in chunks of 200 employees and processing each chunk would reduce peak memory usage. Long-term fix pairs with WNTF-PERF-001 (single call) and WNTF-PERF-002 (batch shift resolution) to minimize total query cost.

---

### WNTF-PERF-004 — Sequential SMTP Connections: One Per Absent Employee

**Severity:** Medium
**Location:** `Absent_Notifications.php:386`

**Description:**
`send_employee_absent_notifications()` issues one `Helpers::send_mail()` call per absent employee inside a nested loop:

```php
foreach ( $absent_by_dept as $dept ) {
    foreach ( $dept['employees'] as $emp ) {
        // ...
        Helpers::send_mail( $email, $subject, $message );
    }
}
```

If 50 employees are absent, this triggers 50 sequential SMTP connections. Combined with the 1 SMTP call per department for manager notifications, a large org with many absent employees faces slow cron execution.

**Impact:** With 50 absent employees, 50+ SMTP handshakes in series. If SMTP is remote, each call adds 100-500ms latency. Total cron wall time can reach 30+ seconds for large absence events.

**Fix Recommendation:** Use a queued/batched email approach. In the short term, consider using `wp_mail()` with BCC for employee notifications (grouping by same message template), or implement an async queue via a separate cron wave. Document the current behavior as a known scaling limitation until a mail queue is available.

---

## Duplication Findings

### WNTF-DUP-001 — `is_holiday()` Duplicated Verbatim Between Notifications and Admin_Pages

**Severity:** Medium
**Location:** `Absent_Notifications.php:142-175` vs `Admin_Pages.php:1126-1163`

**Description:**
`Absent_Notifications::is_holiday()` (lines 142-175) and `Admin_Pages::is_holiday()` (lines 1126-1163) are **functionally identical** — both methods:
- Load `sfs_hr_holidays` from `get_option()`
- Support both `start`/`end` and legacy `start_date`/`end_date` field names
- Handle repeating vs non-repeating holidays with identical year-wrap logic
- Return `bool`

The only differences are the access modifier (`private static` in Notifications vs `protected` in Admin_Pages) and whitespace/comment style.

**Impact:** Any bug fix or change to holiday logic must be applied in two places. The repeating holiday cross-year wrap logic is subtle and already present twice.

**Fix Recommendation:** Extract a shared `is_holiday()` method into `SFS\HR\Core\Helpers` or a new `SFS\HR\Core\HolidayHelper` class:

```php
// SFS\HR\Core\HolidayHelper::is_holiday( string $ymd ): bool
```

Both `Absent_Notifications` and `Admin_Pages` delegate to the shared helper. Cross-reference: same duplication category as `WADM-LOGIC-001` (Phase 15 Plan 01) and `PAY-LOGIC-001` (Phase 09).

---

### WNTF-DUP-002 — Day-Off Logic Diverges Between Notifications and Admin_Pages Fallback

**Severity:** Medium
**Location:** `Absent_Notifications.php:184-208` vs `Admin_Pages.php:1200-1212`

**Description:**
Both files determine if a date is an employee's day off, but with different fallback behaviors when `AttendanceModule` is unavailable:

| Aspect | Absent_Notifications `is_employee_day_off()` | Admin_Pages `get_employee_shifts_map()` fallback |
|--------|----------------------------------------------|--------------------------------------------------|
| Reads `weekly_off_days` setting | Yes (line 191) | No — Friday hardcoded (line 1202-1207) |
| Default off days | `[5]` — Friday only (line 191) | Friday only via `is_friday()` |
| AttendanceModule present | Calls `resolve_shift_for_date()` per employee | Calls `resolve_shift_for_date()` per employee |

`Absent_Notifications` at least reads `sfs_hr_attendance_settings.weekly_off_days`, while `Admin_Pages` has a pure Friday-only fallback. Both share the same N+1 shift resolution pattern when `AttendanceModule` is available.

**Impact:** Inconsistent absent classification — Admin_Pages could show an employee as "Not Clocked In" (not absent) on Saturday while Notifications correctly identifies Saturday as a day off (if `weekly_off_days` includes 6). This produces conflicting data between the dashboard and notification emails.

**Fix Recommendation:** Consolidate day-off detection into a shared service (paired with WNTF-DUP-001 extract). The shared helper should always read `weekly_off_days` and default to `[5, 6]` (Friday + Saturday) for Saudi-context deployments. Cross-reference: `WADM-LOGIC-001` (Phase 15 Plan 01), `PAY-LOGIC-001` (Phase 09).

---

## Logical Findings

### WNTF-LOGIC-001 — Friday-Only Off Day Default Misses Saudi Saturday

**Severity:** High
**Location:** `Absent_Notifications.php:191`

**Description:**
`is_employee_day_off()` defaults to `[5]` (Friday only) when `weekly_off_days` is not set in `sfs_hr_attendance_settings`:

```php
$off_days = $att_settings['weekly_off_days'] ?? [ 5 ]; // Default: Friday
```

The Saudi weekend is **Friday + Saturday** (days 5 and 6 in PHP's `w` format). If `sfs_hr_attendance_settings` is not configured or `weekly_off_days` is empty, Saturdays are treated as working days, and all employees not clocked in on a Saturday will be flagged as absent and receive absence notification emails.

**Impact:** On any unconfigured deployment, Saturday generates a false-positive absence wave — all active employees with a managed department will receive emails on Saturdays. This is the same bug as `PAY-LOGIC-001` (Phase 09) where `count_working_days()` excluded only Friday.

**Fix Recommendation:** Change the default to `[5, 6]` to match Saudi labor law. Add a comment referencing the Saudi weekend:

```php
// Saudi weekend: Friday (5) + Saturday (6). See PAY-LOGIC-001 fix note.
$off_days = $att_settings['weekly_off_days'] ?? [ 5, 6 ];
```

Cross-reference: `PAY-LOGIC-001` (Phase 09 — same root cause), `WADM-LOGIC-001` (Phase 15 Plan 01 — same file, different code path).

---

### WCRN-LOGIC-001 — No Exception Isolation Between Manager and Employee Notification Sends

**Severity:** High
**Location:** `Absent_Cron.php:105-106`

**Description:**
`Absent_Cron::run()` calls both notification methods sequentially with no try/catch isolation:

```php
$manager_sent  = Absent_Notifications::send_absent_notifications( $today );   // line 105
$employee_sent = Absent_Notifications::send_employee_absent_notifications( $today ); // line 106
```

If `send_absent_notifications()` throws an uncaught exception (e.g., SMTP connection failure, `get_userdata()` returning unexpected value, any unexpected DB error), PHP will propagate the exception up through `run()` and `employee_sent` will never execute. Managers and employees are notified from separate method calls — a failure in manager notification silently cancels all employee notifications.

**Impact:** Absent employees receive no notification on SMTP failure for manager emails. The cron action `sfs_hr_absent_cron_completed` is never fired, so there is no record of the partial run.

**Fix Recommendation:** Wrap each notification call in an independent try/catch:

```php
$manager_sent  = 0;
$employee_sent = 0;

try {
    $manager_sent = Absent_Notifications::send_absent_notifications( $today );
} catch ( \Throwable $e ) {
    error_log( '[SFS HR] Manager absent notifications failed: ' . $e->getMessage() );
}

try {
    $employee_sent = Absent_Notifications::send_employee_absent_notifications( $today );
} catch ( \Throwable $e ) {
    error_log( '[SFS HR] Employee absent notifications failed: ' . $e->getMessage() );
}
```

---

### WCRN-LOGIC-002 — Subtle Null-Coalescence in `get_next_run_timestamp()` Loses `send_time`

**Severity:** Medium
**Location:** `Absent_Cron.php:83-88`

**Description:**
`get_next_run_timestamp()` builds the target from format:

```php
$target = \DateTimeImmutable::createFromFormat(
    'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $send_time, $tz
);

if ( ! $target || $target <= $now ) {
    $target = $target
        ? $target->modify( '+1 day' )
        : $now->modify( '+1 day' )->setTime( 20, 0 );
}
```

If `createFromFormat()` returns `false` (malformed `$send_time` such as `"25:99"` from bad settings data), the fallback hardcodes `20:00` (8 PM) regardless of what `$send_time` was. This means a corrupt `send_time` setting silently defaults to 20:00 rather than surfacing an error or preserving the intended time.

**Impact:** Low risk in practice because `send_time` comes from admin settings and defaults to `'20:00'`. However, if an operator enters an invalid time format (e.g., `"8pm"`, `"20:00:00"` with seconds), the cron silently migrates to the 20:00 hardcoded fallback with no log entry.

**Fix Recommendation:** Add a log entry when the fallback is used:

```php
if ( ! $target ) {
    error_log( '[SFS HR] Invalid send_time "' . $send_time . '" for absent cron; defaulting to 20:00.' );
    $target = $now->modify( '+1 day' )->setTime( 20, 0 );
} elseif ( $target <= $now ) {
    $target = $target->modify( '+1 day' );
}
```

---

### WNTF-LOGIC-002 — Hardcoded Profile URL Breaks on Non-Standard Portal Slugs

**Severity:** Low
**Location:** `Absent_Notifications.php:368`

**Description:**
The leave portal URL in employee absent notification emails is hardcoded:

```php
$leave_url = home_url( '/my-profile/?sfs_hr_tab=leave' );
```

If the site publishes the HR portal shortcode on a page with a different slug (e.g., `/hr-portal/`, `/employee/`, `/portal/`), the "Submit Leave Request" button in the absent employee email leads to a 404.

**Impact:** Employees receive a non-functional link in absence notification emails. They cannot reach the leave submission form from the email. Low severity because the page does still exist at a different URL — it is just not linked correctly.

**Fix Recommendation:** Add a filterable option to configure the portal URL, or read it from a plugin setting:

```php
$leave_url = apply_filters(
    'sfs_hr_employee_portal_leave_url',
    home_url( '/my-profile/?sfs_hr_tab=leave' )
);
```

This allows deployments to override the URL without modifying plugin code. Long-term: store the portal page ID as a plugin setting and use `get_permalink()`.

---

## $wpdb Call-Accounting Table (Absent_Notifications.php)

| # | Line | Method | Query Type | Prepared | Placeholders | Notes |
|---|------|--------|------------|----------|--------------|-------|
| 1 | 32–45 | `$wpdb->get_results()` | SELECT (employees + dept JOIN) | `$wpdb->prepare([], [])` — unnecessary | None (static query) | Deprecated: prepare() with empty array triggers WP 6.x notice. Should be plain `get_results()`. |
| 2 | 67–75 | `$wpdb->get_col()` | SELECT DISTINCT (punches) | Yes — `$wpdb->prepare()` | `%d` × N (emp IDs), `%s` (start_utc), `%s` (end_utc) | Correct. Dynamic emp IDs and UTC timestamps parameterized. |
| 3 | 79–87 | `$wpdb->get_col()` | SELECT DISTINCT (leave requests) | Yes — `$wpdb->prepare()` | `%d` × N (emp IDs), `%s` (date), `%s` (date) | Correct. Dynamic emp IDs and date parameterized. |

**Total $wpdb calls in Absent_Notifications.php:** 3
**Correctly prepared:** 2 of 3 (queries 2 and 3)
**Incorrectly using prepare():** 1 (query 1 — unnecessary but not an injection risk; static query)
**Unprepared with user input:** 0

---

## Cross-Reference: Prior Phase Findings

| Finding ID | Antipattern | Prior Phase Finding | Notes |
|------------|-------------|---------------------|-------|
| WNTF-LOGIC-001 | Friday-only weekend default | Phase 09 PAY-LOGIC-001 | Exact same root cause: `weekly_off_days` defaults to `[5]` only. Saudi Saturday excluded. |
| WNTF-PERF-002 | N+1 shift resolution per employee | Phase 15 Plan 01 WADM-PERF-002 | Both files call `resolve_shift_for_date()` per employee in a loop — same antipattern. |
| WNTF-DUP-001 | `is_holiday()` duplicated | Phase 15 Plan 01 audit | Identical logic in `Admin_Pages` and `Notifications` — same file set, same module. |
| WNTF-DUP-002 | Day-off fallback inconsistency | Phase 15 Plan 01 WADM-LOGIC-001 | Admin_Pages fallback is Friday-only; Notifications reads `weekly_off_days`. Both in same module. |
| WNTF-PERF-001 | Double query execution per cron | (New — not in prior phases) | Unique to Absent_Notifications architecture. Both `send_*` methods independently call `get_absent_employees_by_department()`. |
| WNTF-SEC-001 | Unnecessary `$wpdb->prepare()` with empty array | Phase 09 (confirmed static-query pattern) | Static-query prepare() pattern seen in Payroll Admin_Pages too; resolved there as safe but incorrect API usage. |

---

## Audit Summary

Both files are **well-structured and clean at the security layer**. No SQL injection risks were found — all dynamic values in the two prepared queries use parameterized placeholders. Email output correctly uses `esc_html()` throughout the template methods. No unprepared SQL with user input exists.

The dominant issues are **architectural performance and logical correctness**:

1. **Double query execution** (WNTF-PERF-001, High) is the most impactful fix — eliminating the second call to `get_absent_employees_by_department()` halves DB load on every cron run with a minimal code change.
2. **N+1 shift resolution** (WNTF-PERF-002, High) is the highest-effort fix, requiring a batch resolver in `AttendanceModule` shared between Admin_Pages and Notifications.
3. **Friday-only default** (WNTF-LOGIC-001, High) is a one-line fix with immediate correctness impact for Saudi deployments.
4. **is_holiday() duplication** (WNTF-DUP-001, Medium) should be deferred to a dedicated refactor phase (v1.2) targeting shared helper extraction.
5. **trigger_manual() defense-in-depth** (WCRN-SEC-001, informational) is a low-effort guard that prevents future exposure.
