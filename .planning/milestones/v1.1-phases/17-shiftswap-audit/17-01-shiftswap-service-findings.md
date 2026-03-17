# Phase 17 Plan 01: ShiftSwap Module Audit Findings

**Audit date:** 2026-03-17
**Files audited:**
- `includes/Modules/ShiftSwap/ShiftSwapModule.php` (186 lines)
- `includes/Modules/ShiftSwap/Services/class-shiftswap-service.php` (333 lines)
- `includes/Modules/ShiftSwap/Handlers/class-shiftswap-handlers.php` (227 lines)
- `includes/Modules/ShiftSwap/Notifications/class-shiftswap-notifications.php` (223 lines)

---

## Security Findings

### SS-SEC-001 — Critical: `information_schema.tables` in `maybe_install_tables()` (5th recurrence)

**File:** `ShiftSwapModule.php:67-70`

```php
$table_exists = (bool) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
    $table
));
```

**Severity:** Critical

**Issue:** Same antipattern confirmed in Phase 04 (Core), Phase 08 (Loans), Phase 11 (Assets), Phase 12 (Employees), Phase 16 (Documents). `information_schema` is queried on every `admin_init` call, even after the table exists. This is a performance penalty (slow query on large installs, potential lock contention) and violates the project convention of using `SHOW TABLES LIKE` or delegating to `Migrations.php`.

**Fix:** Replace with the established idiom already used in `add_column_if_missing()` within the same file:
```php
$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
```
Or, better, move DDL entirely into `Migrations.php` and remove the `admin_init` self-healing hook from this module.

---

### SS-SEC-002 — Critical: `information_schema.STATISTICS` queried on every `admin_init` (2nd recurrence)

**File:** `ShiftSwapModule.php:120-123`

```php
$index_exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
    $table, $key_name
));
```

**Severity:** Critical

**Issue:** `add_unique_key_if_missing()` is called unconditionally on every `admin_init`. `information_schema.STATISTICS` query runs even when the index already exists. This is the same antipattern as SS-SEC-001 but on a different `information_schema` table. No equivalent has been seen in prior phases — this is a novel variant.

**Fix:** Cache the result in a transient (e.g., `sfs_hr_shiftswap_index_checked`) or move the entire DDL operation to `Migrations.php` where it runs only on version bump.

---

### SS-SEC-003 — High: Bare `ALTER TABLE` DDL in `admin_init` path (3rd recurrence)

**File:** `ShiftSwapModule.php:112-113`

```php
if (!$exists) {
    $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
}
```

**File:** `ShiftSwapModule.php:127`

```php
$wpdb->query("ALTER TABLE `$table` ADD UNIQUE KEY `$key_name` (`$key_name`)");
```

**Severity:** High

**Issue:** Same antipattern as Phase 08 (Loans) and Phase 16 (Documents). `ALTER TABLE` DDL runs on every admin page load if the column/index is missing. The table name `$table` is constructed from `$wpdb->prefix . 'sfs_hr_shift_swaps'` (not user input), so there is no injection risk — but this is a Critical antipattern classification in prior phases for the `admin_init` context. The table name is safely constructed from constants, making actual injection impossible here, so this is rated High rather than Critical.

**Fix:** Move to `Migrations.php` using the project's `add_column_if_missing()` helper.

---

### SS-SEC-004 — High: Manager approval does not verify swap belongs to approver's department

**File:** `Handlers/class-shiftswap-handlers.php:177-179`

```php
if (!current_user_can('sfs_hr.manage') && !current_user_can('sfs_hr_attendance_admin')) {
    wp_die(esc_html__('Not authorized.', 'sfs-hr'));
}
```

**Severity:** High

**Issue:** The capability check accepts `sfs_hr.manage` (global HR admin) OR `sfs_hr_attendance_admin`. Neither check verifies that the approving manager is actually the department manager for the employees involved in the swap. A manager in Department A can approve swap requests for employees in Department B. The Resignation module (Phase 14) had the same pattern flagged as a High finding.

There is no department manager dynamic capability check (the pattern used in Leave module for `sfs_hr.leave.review`). The `get_swap_for_manager()` service method also does not scope by manager's department — it fetches any swap in `manager_pending` status.

**Fix:** After fetching the swap, verify that the current user manages the department of either the requester or the target employee:
```php
$requester_dept = /* get dept_id for $swap->requester_id */;
$target_dept    = /* get dept_id for $swap->target_id */;
$managed_depts  = /* get_managed_dept_ids( get_current_user_id() ) */;
if ( ! array_intersect( $managed_depts, [ $requester_dept, $target_dept ] ) ) {
    wp_die( esc_html__( 'Not authorized for this department.', 'sfs-hr' ) );
}
```

---

### SS-SEC-005 — High: TOCTOU race on manager approval — unconditional `update_swap_status()`

**File:** `Services/class-shiftswap-service.php:223-233`
**Called from:** `Handlers/class-shiftswap-handlers.php:194-198`

```php
// update_swap_status() performs:
$wpdb->update($table, $data, ['id' => $swap_id])
```

**Severity:** High

**Issue:** The approval flow is: (1) fetch swap with `get_swap_for_manager()` checking `status = 'manager_pending'`, (2) call `update_swap_status()` with no WHERE status guard. Between steps 1 and 2, a concurrent request could change the status. A second manager clicking approve simultaneously would both pass the fetch check, then both update — the second overwriting the first, and `execute_swap()` being called twice. Two `UPDATE` calls on the shift assignments would reassign shifts back to original owners (double swap = net no change) but with `approved` status logged twice.

Same pattern flagged as High in Phase 14 (Resignation) `RADM-LOGIC-002` and Phase 13 (Hiring) `HADM-LOGIC-001`.

**Fix:** Use conditional update with WHERE status guard:
```php
$updated = $wpdb->query( $wpdb->prepare(
    "UPDATE {$table} SET status = %s, manager_id = %d, manager_responded_at = %s, manager_note = %s, updated_at = %s
     WHERE id = %d AND status = 'manager_pending'",
    $new_status, $manager_id, $now, $note, $now, $swap_id
) );
if ( ! $updated ) {
    // Already processed — abort
}
```

---

### SS-SEC-006 — High: `handle_swap_cancel()` passes `$swap['status']` but `$swap` is an object, not array

**File:** `Handlers/class-shiftswap-handlers.php:161`

```php
do_action( 'sfs_hr_shift_swap_status_changed', $swap_id, $swap['status'] ?? 'pending', 'cancelled' );
```

**Severity:** High (runtime error / logical bug)

**Issue:** `get_swap_for_requester()` returns a `stdClass` object (from `$wpdb->get_row()`), but `$swap['status']` uses array syntax. In PHP, accessing a property of a `stdClass` using array syntax triggers a PHP notice (PHP 7) or TypeError (PHP 8+). The `?? 'pending'` fallback may suppress this in some PHP versions, but on PHP 8+ strict typing this will be a runtime error. The audit log will always record `'pending'` as the old status, losing audit accuracy.

**Fix:** Use object property access:
```php
do_action( 'sfs_hr_shift_swap_status_changed', $swap_id, $swap->status ?? 'pending', 'cancelled' );
```

---

### SS-SEC-007 — Medium: Requester date not validated in `handle_swap_request()` — raw string used in DB insert

**File:** `Handlers/class-shiftswap-handlers.php:44`

```php
list($shift_assign_id, $requester_date) = explode('_', $my_shift_data);
```

**Severity:** Medium

**Issue:** `$requester_date` is extracted from a POST parameter by splitting on `_`. While `$my_shift_data` is sanitized via `sanitize_text_field()`, the resulting `$requester_date` is not validated as a date format before being passed to `create_swap_request()` and stored in the DB. A crafted POST value like `123_not-a-date` passes the `strpos($my_shift_data, '_') !== false` check and inserts an invalid date string.

Additionally, `target_date` from POST is only sanitized with `sanitize_text_field()` but not validated as a valid date (YYYY-MM-DD).

**Fix:** Add date format validation:
```php
if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $requester_date ) ) {
    $this->redirect_with_error( __( 'Invalid shift date.', 'sfs-hr' ) );
    return;
}
```

---

### SS-SEC-008 — Medium: `send_notification()` uses `dofs_` prefixed filters (cross-plugin dependency violation)

**File:** `Notifications/class-shiftswap-notifications.php:162,167`

```php
if (!apply_filters('dofs_user_wants_email_notification', true, $user_id, $notification_type)) {
if (apply_filters('dofs_should_send_notification_now', true, $user_id, $notification_type)) {
```

**Severity:** Medium

**Issue:** Same finding as Phase 14 `RNTF-DUP-001`. The `dofs_` prefix belongs to a different plugin (DOFS/SimpleFlow). Per CLAUDE.md: "No direct dependency on other plugins. Does not share DB tables with DOFS or SimpleFlow." Using `dofs_` filter names creates an undocumented cross-plugin dependency. If DOFS is not active, the filters fall through to their defaults (true), which is safe — but if DOFS IS active, DOFS can silently suppress ShiftSwap notifications. This is the 2nd recurrence.

**Fix:** Rename filters to `sfs_hr_` namespace:
```php
apply_filters('sfs_hr_user_wants_email_notification', true, $user_id, $notification_type)
apply_filters('sfs_hr_should_send_notification_now', true, $user_id, $notification_type)
```

---

### SS-SEC-009 — Medium: Target employee not validated as active employee before swap creation

**File:** `Handlers/class-shiftswap-handlers.php:47,51-54` and `Services/class-shiftswap-service.php:196-218`

**Severity:** Medium

**Issue:** `handle_swap_request()` receives `colleague_id` from POST and casts it to `(int)`, then passes it directly to `create_swap_request()` as `target_id`. There is no validation that the `colleague_id` corresponds to a real, active employee. An attacker could submit any integer as `colleague_id`. The `get_colleagues()` method is available for the UI dropdown but the submitted value is never cross-referenced against it.

Note: `validate_shift_ownership()` confirms the requester's shift, but there is no equivalent `validate_employee_exists()` check for the target.

**Fix:** Add a lookup:
```php
$target_employee = ShiftSwap_Service::get_employee_with_email( $target_id );
if ( ! $target_employee ) {
    $this->redirect_with_error( __( 'Invalid colleague selected.', 'sfs-hr' ) );
    return;
}
```

---

### SS-SEC-010 — Low: Manager note not escaped in redirect URL

**File:** `Handlers/class-shiftswap-handlers.php:208`

```php
wp_safe_redirect(admin_url('admin.php?page=sfs-hr-attendance&tab=shift_swaps&success=' . rawurlencode(__('Swap ' . $decision . 'd.', 'sfs-hr'))));
```

**Severity:** Low

**Issue:** `$decision` is validated via `in_array($decision, ['approve', 'reject'], true)` before this line, so no injection is possible. However, the message construction `__('Swap ' . $decision . 'd.', 'sfs-hr')` concatenates before passing to `__()`, making it untranslatable (the string won't exist in translation files). This is a translation antipattern.

**Fix:** Use separate translatable strings:
```php
$msg = ( $decision === 'approve' ) ? __( 'Swap approved.', 'sfs-hr' ) : __( 'Swap rejected.', 'sfs-hr' );
wp_safe_redirect( admin_url( 'admin.php?page=sfs-hr-attendance&tab=shift_swaps&success=' . rawurlencode( $msg ) ) );
```

---

## Performance Findings

### SS-PERF-001 — High: `get_pending_count()` uses unprepared static query — no LIMIT

**File:** `Services/class-shiftswap-service.php:293-295`

```php
return (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM {$table} WHERE status = 'manager_pending'"
);
```

**Severity:** Medium (raw static — no injection risk, but this is the same antipattern as Phase 09 Admin_Pages.php raw static queries)

**Issue:** The query uses direct string interpolation for `$table` without `$wpdb->prepare()`. While `$table` is constructed from `$wpdb->prefix . 'sfs_hr_shift_swaps'` (no user input), this is the raw-static antipattern. Also, the method is called to populate the admin badge count — if called frequently, it runs a COUNT query on every page load. The REST endpoint `GET /shift-swaps/pending-count` also calls this.

**Fix:** Add `$wpdb->prepare()` wrapper (even for static queries, as per project convention) or cache result in a short-lived transient:
```php
return (int)$wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE status = %s",
    'manager_pending'
) );
```

---

### SS-PERF-002 — High: `get_pending_for_managers()` uses unprepared static query — unbounded result set

**File:** `Services/class-shiftswap-service.php:306-315`

```php
return $wpdb->get_results(
    "SELECT sw.*, ... FROM {$table} sw ... WHERE sw.status = 'manager_pending' ORDER BY sw.created_at DESC"
);
```

**Severity:** High

**Issue:** No `LIMIT` clause on the pending swaps list for managers. All `manager_pending` swap requests are returned at once. If the queue grows large (e.g., organization-wide swap event), this returns an unbounded result set. Additionally, the query is a raw static query (same as `get_pending_count()`).

**Fix:** Add `LIMIT` parameter with a reasonable default and pagination support, and use `$wpdb->prepare()`:
```php
return $wpdb->get_results( $wpdb->prepare(
    "SELECT sw.*, ... WHERE sw.status = %s ORDER BY sw.created_at DESC LIMIT %d",
    'manager_pending',
    $limit
) );
```

---

### SS-PERF-003 — Medium: `notify_swap_requested()` performs 2 extra DB queries after notification sent

**File:** `Notifications/class-shiftswap-notifications.php:44-48`

```php
$swap = ShiftSwap_Service::get_swap($swap_id);
if ($swap) {
    $requester = ShiftSwap_Service::get_employee_with_email($swap->requester_id);
    self::notify_hr_shift_swap_event($swap, $requester, $target, 'requested');
}
```

**Severity:** Medium

**Issue:** The swap data and requester data are fetched inside the notification method, but the caller (`handle_swap_request()`) already had the requester's `$employee_id` and swap context available. The handler passes only `$swap_id` and `$target_id` to the action hook, forcing the notification to re-fetch the swap and the requester. This is a mild N+1 — 2 extra queries per notification event that could be avoided by passing more context to the hook.

**Fix:** Expand the hook signature or pass a pre-fetched array:
```php
do_action( 'sfs_hr_shift_swap_requested', $swap_id, $target_id, $employee_id );
```

---

### SS-PERF-004 — Medium: `backfill_shift_swap_request_numbers()` runs N+1 queries inside loop

**File:** `ShiftSwapModule.php:145-159`

```php
$missing = $wpdb->get_results("SELECT id, created_at FROM `$table` WHERE request_number IS NULL OR request_number = '' ORDER BY id ASC");
foreach ($missing as $row) {
    $year = ...;
    $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE request_number LIKE %s", ...));
    $wpdb->update($table, [...], ['id' => $row->id]);
}
```

**Severity:** Medium

**Issue:** The backfill loop executes 2 queries per row (COUNT + UPDATE). On a fresh install with many legacy rows without request numbers, this is O(N) queries. The COUNT-based sequence number is also incorrect — if 10 rows need backfilling, each iteration counts the already-assigned ones only (due to LIKE), which may produce duplicates if rows share the same year and the function is interrupted mid-loop.

**Fix:** Calculate the starting sequence number once before the loop, then increment in PHP:
```php
$year = wp_date('Y');
$existing_count = (int)$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE request_number LIKE %s", 'SS-' . $year . '-%' ) );
$sequence = $existing_count + 1;
foreach ($missing as $row) {
    $number = 'SS-' . $year . '-' . str_pad($sequence++, 4, '0', STR_PAD_LEFT);
    $wpdb->update($table, ['request_number' => $number], ['id' => $row->id]);
}
```

---

## Duplication Findings

### SS-DUP-001 — Medium: `add_column_if_missing()` duplicated from `Migrations.php` project helper

**File:** `ShiftSwapModule.php:109-113`

```php
private static function add_column_if_missing(\wpdb $wpdb, string $table, string $col, string $ddl): void {
    $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
    if (!$exists) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$ddl}");
    }
}
```

**Severity:** Medium

**Issue:** `Migrations.php` already provides `add_column_if_missing()` as a project-wide helper. This file implements its own private copy. This is a DRY violation — any fix to the project helper won't automatically apply to this copy.

**Fix:** Call `\SFS\HR\Install\Migrations::add_column_if_missing()` directly, or move all DDL to `Migrations.php`.

---

### SS-DUP-002 — Medium: Approval workflow pattern duplicates Leave/Resignation without shared helper

**File:** `Handlers/class-shiftswap-handlers.php:169-209`

**Severity:** Low

**Issue:** The manager approval flow (nonce check → capability check → fetch pending swap → update status → execute side effect → audit log) follows the same pattern as Leave `handle_approve()`, Resignation `handle_approve()`, and Hiring `handle_candidate_action()`. Each module implements this independently with no shared approval workflow helper. The TOCTOU fix (SS-SEC-005) would need to be applied separately to each module.

**Note:** This is an observation for the v1.2 refactor milestone — no immediate fix required within audit-only scope.

---

### SS-DUP-003 — Low: `dofs_` filter duplication across Resignation and ShiftSwap

**File:** `Notifications/class-shiftswap-notifications.php:162,167`

**Severity:** Low (duplicate of SS-SEC-008)

**Issue:** The same `dofs_user_wants_email_notification` and `dofs_should_send_notification_now` filter pattern appears in both Resignation (`RNTF-DUP-001`, Phase 14) and ShiftSwap notifications. This suggests a copy-paste origin. Both need renaming.

---

## Logical Issues

### SS-LOGIC-001 — High: `execute_swap()` is not atomic — partial swap execution leaves inconsistent state

**File:** `Services/class-shiftswap-service.php:238-270`

```php
if ($requester_shift) {
    $wpdb->update($shifts_table, ['employee_id' => $swap->target_id, ...], ['id' => $requester_shift->id]);
}
if ($target_shift) {
    $wpdb->update($shifts_table, ['employee_id' => $swap->requester_id, ...], ['id' => $target_shift->id]);
}
```

**Severity:** High

**Issue:** The swap execution performs two independent `UPDATE` operations. If the second UPDATE fails (DB error, connection loss), the first has already committed — the requester's shift now belongs to the target, but the target's shift still belongs to the target. Net result: the target employee has two shifts and the requester has zero. There is no transaction wrapping these two updates.

Additionally, when `$target_shift` is `null` (target has no shift assigned for `target_date`), only the requester's shift is moved. The target employee never gets a shift for that date. This is a one-sided swap — only the requester loses their shift while the target gains it, with no reciprocal assignment. There is no error or guard for this case.

**Fix:**
1. Wrap both updates in a DB transaction:
```php
$wpdb->query('START TRANSACTION');
$ok1 = $wpdb->update($shifts_table, [...], ['id' => $requester_shift->id]);
$ok2 = $wpdb->update($shifts_table, [...], ['id' => $target_shift->id]);
if ($ok1 !== false && $ok2 !== false) {
    $wpdb->query('COMMIT');
} else {
    $wpdb->query('ROLLBACK');
    // revert swap status
}
```
2. Guard against missing target shift: if `$target_shift` is null, consider whether a one-sided reassignment is the intended design or if the swap should be blocked until both shifts exist.

---

### SS-LOGIC-002 — High: No duplicate swap request guard — same shift can have multiple pending requests

**File:** `Services/class-shiftswap-service.php:196-218`
**Called from:** `Handlers/class-shiftswap-handlers.php:64-71`

**Severity:** High

**Issue:** `create_swap_request()` performs no check for existing pending swap requests for the same `requester_shift_id`. An employee can submit multiple swap requests for the same shift assignment to different colleagues simultaneously. All would enter `pending` status. If multiple colleagues accept, multiple swaps escalate to `manager_pending`. If the manager approves more than one, `execute_swap()` is called multiple times on the same shift assignment — leading to the shift being reassigned in unpredictable ways.

There is no UNIQUE constraint on `(requester_shift_id, status)` in the DB schema either.

**Fix:** Add a uniqueness check before insert:
```php
$existing = $wpdb->get_var( $wpdb->prepare(
    "SELECT id FROM {$table} WHERE requester_shift_id = %d AND status IN ('pending','accepted','manager_pending')",
    $data['requester_shift_id']
) );
if ( $existing ) {
    return 0; // or return WP_Error
}
```

---

### SS-LOGIC-003 — High: No state-machine guard for target response — accepted swap can be re-declined

**File:** `Services/class-shiftswap-service.php:155-164`

```php
return $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$table} WHERE id = %d AND target_id = %d AND status = 'pending'",
    ...
));
```

**Severity:** Medium (mitigated by the `status = 'pending'` WHERE clause in `get_swap_for_target()`)

**Clarification:** `get_swap_for_target()` correctly scopes to `status = 'pending'` only, so a target cannot respond twice if the status has changed. This is SAFE for the target response flow.

However, `update_swap_status()` (called from `handle_swap_cancel()` and `handle_swap_response()`) uses an unconditional `$wpdb->update($table, $data, ['id' => $swap_id])`. The WHERE-based guards live in the `get_swap_for_*()` fetch methods, not in the update. If the fetch-to-update gap is exploited (TOCTOU), a cancelled swap could be re-cancelled (harmless) or a declined swap could have its status overwritten. The risk is lower here than for manager approval because employee actions are single-actor, but the pattern is still weaker than a conditional UPDATE.

**Severity revised:** Medium for this specific case.

---

### SS-LOGIC-004 — Medium: `backfill_shift_swap_request_numbers()` uses `date()` (local server time) not `wp_date()`

**File:** `ShiftSwapModule.php:149`

```php
$year = $row->created_at ? date('Y', strtotime($row->created_at)) : wp_date('Y');
```

**Severity:** Low

**Issue:** `date()` uses the PHP server timezone, not the WordPress site timezone. If the server is in UTC and the site is in GMT+3 (Saudi Arabia), a swap created at 23:30 local time on Dec 31 would be stored as Jan 1 UTC in `created_at`, but the backfill would assign it to the previous year's sequence. The main code uses `wp_date()` in the fallback but not when `created_at` exists.

**Fix:**
```php
$year = $row->created_at ? wp_date('Y', strtotime($row->created_at)) : wp_date('Y');
```

---

### SS-LOGIC-005 — Low: Notifications missing for manager approval outcome (approved/rejected)

**File:** `Notifications/class-shiftswap-notifications.php:19-21`

```php
add_action('sfs_hr_shift_swap_requested', [$this, 'notify_swap_requested'], 10, 2);
add_action('sfs_hr_shift_swap_responded', [$this, 'notify_swap_responded'], 10, 2);
```

**Severity:** Low

**Issue:** The notifications class registers listeners for `sfs_hr_shift_swap_requested` and `sfs_hr_shift_swap_responded`, but there is no listener for the manager's approval/rejection decision. The hook `sfs_hr_shift_swap_status_changed` is fired by the handlers when a manager approves or rejects (`Handlers/class-shiftswap-handlers.php:206`), but nothing listens to it in the notifications class. Employees never receive an email when their swap is approved or rejected by the manager.

**Fix:** Add a `notify_manager_decision()` method and register it:
```php
add_action( 'sfs_hr_shift_swap_status_changed', [ $this, 'notify_manager_decision' ], 10, 3 );
```

---

### SS-LOGIC-006 — Low: HR notifications use non-existent column `requester_shift_date` / `target_shift_date`

**File:** `Notifications/class-shiftswap-notifications.php:110-111, 122-123, 132-133`

```php
$swap->requester_shift_date ?? 'N/A',
$swap->target_shift_date ?? 'N/A',
```

**Severity:** Low

**Issue:** The `sfs_hr_shift_swaps` schema (defined in `ShiftSwapModule.php:73-97`) has columns `requester_date` and `target_date`, not `requester_shift_date` and `target_shift_date`. These references will always be `null`, so the HR notification email always shows `N/A` for both shift dates — reducing the notification's usefulness.

**Fix:**
```php
$swap->requester_date ?? 'N/A',
$swap->target_date ?? 'N/A',
```

---

## $wpdb Call Accounting

All `$wpdb` calls across the 4 audited files, catalogued with file:line, type, and prepared status:

| # | File | Line(s) | Method | Query type | Prepared? | Notes |
|---|------|---------|--------|------------|-----------|-------|
| 1 | ShiftSwapModule.php | 67–70 | `get_var` | SELECT information_schema.tables | Prepared (%s) | antipattern (SS-SEC-001) |
| 2 | ShiftSwapModule.php | 110 | `get_var` | SHOW COLUMNS LIKE | Prepared (%s) | Safe |
| 3 | ShiftSwapModule.php | 112 | `query` | ALTER TABLE ADD COLUMN | Raw static | Safe (constant table name) |
| 4 | ShiftSwapModule.php | 120–123 | `get_var` | SELECT information_schema.STATISTICS | Prepared (%s, %s) | antipattern (SS-SEC-002) |
| 5 | ShiftSwapModule.php | 125 | `get_var` | SHOW COLUMNS LIKE | Prepared (%s) | Safe |
| 6 | ShiftSwapModule.php | 127 | `query` | ALTER TABLE ADD UNIQUE KEY | Raw static | Safe (constant table name) |
| 7 | ShiftSwapModule.php | 145–147 | `get_results` | SELECT id,created_at WHERE request_number IS NULL | Raw static | Safe (constant table, no user input) |
| 8 | ShiftSwapModule.php | 150–155 | `get_var` | SELECT COUNT LIKE 'SS-year-%' | Prepared (%s) | Safe; N+1 in loop (SS-PERF-004) |
| 9 | ShiftSwapModule.php | 158 | `update` | UPDATE shift_swaps SET request_number | Via wpdb->update | Safe |
| 10 | class-shiftswap-service.php | 53–56 | `get_var` | SELECT id FROM employees WHERE user_id | Prepared (%d) | Safe |
| 11 | class-shiftswap-service.php | 67–77 | `get_results` | SELECT shift_assigns JOIN shifts WHERE employee_id | Prepared (%d, %s, %d) | Safe |
| 12 | class-shiftswap-service.php | 87–94 | `get_results` | SELECT employees WHERE dept_id | Prepared (%d, %d) | Safe |
| 13 | class-shiftswap-service.php | 105–114 | `get_results` | SELECT shift_swaps JOIN employees WHERE target_id | Prepared (%d) | Safe |
| 14 | class-shiftswap-service.php | 125–136 | `get_results` | SELECT shift_swaps JOIN employees WHERE requester_id | Prepared (%d, %d) | Safe |
| 15 | class-shiftswap-service.php | 146–149 | `get_row` | SELECT shift_swaps WHERE id | Prepared (%d) | Safe |
| 16 | class-shiftswap-service.php | 159–163 | `get_row` | SELECT shift_swaps WHERE id AND target_id AND status=pending | Prepared (%d, %d) | Safe |
| 17 | class-shiftswap-service.php | 173–177 | `get_row` | SELECT shift_swaps WHERE id AND requester_id AND status=pending | Prepared (%d, %d) | Safe |
| 18 | class-shiftswap-service.php | 187–190 | `get_row` | SELECT shift_swaps WHERE id AND status=manager_pending | Prepared (%d) | Safe |
| 19 | class-shiftswap-service.php | 204–215 | `insert` | INSERT INTO shift_swaps | Via wpdb->insert | Safe |
| 20 | class-shiftswap-service.php | 232 | `update` | UPDATE shift_swaps SET status | Via wpdb->update | Safe; missing WHERE status guard (SS-SEC-005) |
| 21 | class-shiftswap-service.php | 243–246 | `get_row` | SELECT shift_assigns WHERE id | Prepared (%d) | Safe |
| 22 | class-shiftswap-service.php | 249–253 | `get_row` | SELECT shift_assigns WHERE employee_id AND assign_date | Prepared (%d, %s) | Safe |
| 23 | class-shiftswap-service.php | 256–260 | `update` | UPDATE shift_assigns SET employee_id (requester→target) | Via wpdb->update | Safe; no transaction (SS-LOGIC-001) |
| 24 | class-shiftswap-service.php | 263–267 | `update` | UPDATE shift_assigns SET employee_id (target→requester) | Via wpdb->update | Safe; no transaction (SS-LOGIC-001) |
| 25 | class-shiftswap-service.php | 279–283 | `get_row` | SELECT shift_assigns WHERE id AND employee_id | Prepared (%d, %d) | Safe (ownership check) |
| 26 | class-shiftswap-service.php | 293–295 | `get_var` | SELECT COUNT shift_swaps WHERE status=manager_pending | Raw static | Antipattern (SS-PERF-001) |
| 27 | class-shiftswap-service.php | 306–315 | `get_results` | SELECT shift_swaps JOIN employees WHERE status=manager_pending | Raw static | Antipattern (SS-PERF-002); no LIMIT |
| 28 | class-shiftswap-service.php | 325–330 | `get_row` | SELECT employees JOIN users WHERE id | Prepared (%d) | Safe |
| **Total** | | | | | **28 calls** | 24 prepared/safe, 4 raw-static antipattern, 0 dynamic raw |

**Raw/unprepared summary:**
- Calls #1, #4: `information_schema` queries — prepared with `%s` placeholders but antipattern (Critical)
- Calls #3, #6: `ALTER TABLE` raw static DDL — table name from constant, no injection risk (High for context)
- Calls #7: Raw static SELECT — table from constant, no user input (Medium/antipattern)
- Calls #26, #27: Raw static SELECT — table from constant, no user input (Medium/antipattern)

**No dynamic raw SQL injection vulnerabilities found.** All user-controlled values are passed through `$wpdb->prepare()` or `$wpdb->insert()`/`$wpdb->update()`.

---

## Swap Ownership Validation Assessment

**Question:** When creating a swap request, does the code verify the requesting employee OWNS the source shift?

**Answer: YES — ownership is validated. SAFE.**

**Evidence:**
- `Handlers/class-shiftswap-handlers.php:57-61` calls `ShiftSwap_Service::validate_shift_ownership($shift_assign_id, $employee_id)` before creating the swap request.
- `Services/class-shiftswap-service.php:275-284` implements `validate_shift_ownership()` with `WHERE id = %d AND employee_id = %d` — a prepared query that confirms both the shift assignment ID and the employee ID match.
- `$employee_id` is derived from `get_current_user_id()` via `get_current_employee_id()` — not from POST parameters.

**Remaining concern:** The target employee is NOT validated (SS-SEC-009) — any integer can be submitted as `colleague_id`.

---

## Approval Capability Check Assessment

**Question:** Does the approval method check that the approver has manager capability?

**Answer: YES — capability is checked at the handler level. PARTIAL.**

**Evidence:**
- `Handlers/class-shiftswap-handlers.php:177-179` checks `current_user_can('sfs_hr.manage') || current_user_can('sfs_hr_attendance_admin')` before any processing.
- The check uses the correct dotted-format capability name `sfs_hr.manage`.

**Gap identified (SS-SEC-004):**
- The check is global — any `sfs_hr.manage` holder can approve any swap regardless of department.
- Department-scoped manager approval (the dynamic `sfs_hr.leave.review` pattern) is not implemented for shift swaps.
- The self-approval scenario: `handle_manager_approval()` does not check if the approving user is the same as the requester or target. A manager who is also an employee could submit a swap request and then approve it themselves (if they hold `sfs_hr.manage`). There is no `$swap->requester_user_id !== get_current_user_id()` guard.

---

## Summary Table

| ID | File | Category | Severity | Title |
|----|------|----------|----------|-------|
| SS-SEC-001 | ShiftSwapModule.php:67 | Security | **Critical** | `information_schema.tables` queried on every admin_init (5th recurrence) |
| SS-SEC-002 | ShiftSwapModule.php:120 | Security | **Critical** | `information_schema.STATISTICS` queried on every admin_init |
| SS-SEC-003 | ShiftSwapModule.php:112,127 | Security | **High** | Bare `ALTER TABLE` DDL in admin_init path (3rd recurrence) |
| SS-SEC-004 | Handlers:177 | Security | **High** | Manager approval not department-scoped; self-approval not blocked |
| SS-SEC-005 | Service:232 / Handlers:194 | Security | **High** | TOCTOU race on manager approval — unconditional update |
| SS-SEC-006 | Handlers:161 | Security | **High** | `$swap['status']` array syntax on object — PHP 8+ runtime error |
| SS-SEC-007 | Handlers:44 | Security | **Medium** | requester_date and target_date not validated as date format |
| SS-SEC-008 | Notifications:162,167 | Security | **Medium** | `dofs_` prefixed filter names — cross-plugin dependency violation (2nd recurrence) |
| SS-SEC-009 | Handlers:47 | Security | **Medium** | Target employee not validated as active employee before swap creation |
| SS-SEC-010 | Handlers:208 | Security | **Low** | Untranslatable message concatenation in redirect (minor) |
| SS-PERF-001 | Service:293 | Performance | **Medium** | `get_pending_count()` raw static query |
| SS-PERF-002 | Service:306 | Performance | **High** | `get_pending_for_managers()` raw static query, unbounded results |
| SS-PERF-003 | Notifications:44 | Performance | **Medium** | Extra DB queries in notification — swap+requester re-fetched unnecessarily |
| SS-PERF-004 | ShiftSwapModule.php:150 | Performance | **Medium** | `backfill_shift_swap_request_numbers()` N+1 COUNT+UPDATE per row |
| SS-DUP-001 | ShiftSwapModule.php:109 | Duplication | **Medium** | `add_column_if_missing()` duplicated from Migrations.php |
| SS-DUP-002 | Handlers:169 | Duplication | **Low** | Approval workflow pattern repeated without shared helper |
| SS-DUP-003 | Notifications:162 | Duplication | **Low** | `dofs_` filter duplication (same as Resignation Phase 14) |
| SS-LOGIC-001 | Service:238 | Logical | **High** | `execute_swap()` not atomic — partial swap on DB error; one-sided swap if target has no shift |
| SS-LOGIC-002 | Service:196 | Logical | **High** | No duplicate swap request guard — same shift can have multiple pending requests |
| SS-LOGIC-003 | Service:155 | Logical | **Medium** | Unconditional `update_swap_status()` — mitigated by fetch guards but TOCTOU residual |
| SS-LOGIC-004 | ShiftSwapModule.php:149 | Logical | **Low** | `date()` instead of `wp_date()` in backfill — timezone inconsistency |
| SS-LOGIC-005 | Notifications:19 | Logical | **Low** | No notification sent to employees when manager approves or rejects |
| SS-LOGIC-006 | Notifications:110 | Logical | **Low** | Wrong column names in HR notification (`requester_shift_date` → `requester_date`) |

### Severity Counts

| Severity | Count |
|----------|-------|
| Critical | 2 |
| High | 8 |
| Medium | 7 |
| Low | 6 |
| **Total** | **23** |

### Key Positive Findings

- **Swap ownership validation: SAFE** — `validate_shift_ownership()` correctly confirms the requester owns the shift before creating a swap request (file:line `Handlers:57-61`, `Service:275-284`).
- **No dynamic raw SQL injection vulnerabilities** — all 28 `$wpdb` calls are either prepared or use table names from constants.
- **Nonce verification on all 4 handlers** — every handler verifies a nonce before processing (unlike Hiring Phase 13 which had nonce-only guards with no capability check).
- **Employee self-service scope is correct** — `get_current_employee_id()` derives employee ID from `get_current_user_id()`, not from POST; employees cannot submit swap requests as other employees.
- **REST endpoints properly gated** — `ShiftSwap_Rest` uses `check_manager_permission()` on both routes; no `__return_true` pattern.
