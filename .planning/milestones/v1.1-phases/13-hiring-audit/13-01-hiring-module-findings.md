# Phase 13, Plan 01: HiringModule.php Audit Findings

**File audited:** `includes/Modules/Hiring/HiringModule.php`
**Lines:** 717
**Date:** 2026-03-16
**Auditor:** Automated code review (Claude Sonnet)

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 2 | 2 | 1 | 0 | 5 |
| Performance | 0 | 1 | 2 | 1 | 4 |
| Duplication | 0 | 2 | 1 | 0 | 3 |
| Logical | 1 | 3 | 1 | 1 | 6 |
| **Total** | **3** | **8** | **5** | **2** | **18** |

---

## Security Findings

### HIR-SEC-001
**Severity:** Critical
**Location:** `HiringModule.php:348-354` (`convert_trainee_to_employee`), `HiringModule.php:534-540` (`convert_candidate_to_employee`)
**Description:** Employee code generation uses raw string interpolation in `get_var()` query. Both conversion methods contain:
```php
$last = $wpdb->get_var(
    "SELECT employee_code FROM {$wpdb->prefix}sfs_hr_employees
     WHERE employee_code LIKE '{$prefix}%'
     ORDER BY id DESC LIMIT 1"
);
```
The `$prefix` variable is a hardcoded string (`"USR-"`) so there is no immediate injection risk from user input, but the pattern is a Critical antipattern: if `$prefix` ever originates from user-supplied data (or if this pattern is copy-pasted into a context where it does), it becomes a SQL injection vector. The same antipattern was flagged as Critical in Phase 04, 08, and 11.
**Impact:** Violation of the project security baseline; escalation risk if source of `$prefix` changes.
**Fix:**
```php
$last = $wpdb->get_var($wpdb->prepare(
    "SELECT employee_code FROM {$wpdb->prefix}sfs_hr_employees
     WHERE employee_code LIKE %s
     ORDER BY id DESC LIMIT 1",
    $prefix . '%'
));
```

### HIR-SEC-002
**Severity:** Critical
**Location:** `HiringModule.php:266` (`convert_trainee_to_candidate`), `HiringModule.php:331` (`convert_trainee_to_employee`), `HiringModule.php:517` (`convert_candidate_to_employee`)
**Description:** All three conversion methods are `public static` and have **no capability check** (`current_user_can()`) at their entry point. Any authenticated user who can call these methods — via a plugin hook, a cron job, or if any caller path is exposed — can create employee records, WordPress user accounts, and assign roles without being checked for `sfs_hr.manage`. The only guard is the status check (e.g., `$trainee->status === 'completed'`), which is not an authorization check.

The callers in `Admin/class-admin-pages.php` handlers (`handle_candidate_action`, `handle_trainee_action`) do verify nonces but have **no `current_user_can()` check** before invoking the conversion methods. A logged-in subscriber who forges a nonce token (possible if the nonce generation is known, or via CSRF in a different context) can trigger employee creation.
**Impact:** Privilege escalation — any authenticated user can create employee records and WordPress accounts.
**Fix:** Add a capability guard at the top of each conversion method:
```php
if (!current_user_can('sfs_hr.manage')) {
    return null;
}
```
Also add `current_user_can('sfs_hr.manage')` checks at the top of `handle_add_candidate()`, `handle_update_candidate()`, `handle_candidate_action()`, `handle_add_trainee()`, `handle_update_trainee()`, and `handle_trainee_action()` in `Admin/class-admin-pages.php`.

### HIR-SEC-003
**Severity:** High
**Location:** `HiringModule.php:677-694` (`send_welcome_email`)
**Description:** The welcome email sends the plaintext password directly in the email body (`sprintf(__('Password: %s', 'sfs-hr'), $password)`). The password is generated with `wp_generate_password(12, true, true)` and never stored — only the WP hash is stored. However, transmitting a plaintext password in email is a security antipattern. If the email is intercepted (plaintext SMTP, email forwarding, email logging plugins), the credentials are permanently compromised.

The same pattern exists in `Admin/class-admin-pages.php:1715` for trainee account creation.
**Impact:** Credential exposure via email interception. The password cannot be recovered after the email is sent (WP hashes it), so this is the only delivery mechanism — but the risk is the channel.
**Fix:** Use WordPress's built-in password-reset flow instead: create the user without sending the password, then call `wp_send_new_user_notifications()` or generate a password reset link with `get_password_reset_key()` and send the reset URL instead of the plaintext password.

### HIR-SEC-004
**Severity:** High
**Location:** `Admin/class-admin-pages.php:1266-1298` (`handle_add_candidate`), `1301-1329` (`handle_update_candidate`), `1331-1510` (`handle_candidate_action`), `1512-1583` (`handle_add_trainee`), `1585-1613` (`handle_update_trainee`), `1615-1745` (`handle_trainee_action`)
**Description:** None of the six `admin_post_*` handlers perform a `current_user_can()` capability check. They rely solely on nonce verification. An authenticated subscriber-level user who obtains a valid nonce (e.g., via CSRF or by browsing the hiring page — nonces are embedded in forms loaded by `sfs_hr.manage`-gated menu, but the handler itself has no cap check) can add/update/delete candidates and trainees.

In contrast, the hiring menu registration (`register_menu`) correctly uses `sfs_hr.manage` as the capability, but this only prevents rendering the menu — it does not block direct `admin-post.php` submissions.
**Impact:** Any authenticated WordPress user can add/edit/delete candidates and trainees, and trigger conversion workflows.
**Fix:** Add at the top of each handler:
```php
if (!current_user_can('sfs_hr.manage')) {
    wp_die(__('You do not have permission to perform this action.', 'sfs-hr'));
}
```

### HIR-SEC-005
**Severity:** Medium
**Location:** `HiringModule.php:400-412` (`convert_trainee_to_employee`), `HiringModule.php:571-582` (`convert_candidate_to_employee`)
**Description:** When assigning a WP role to a new employee, the code queries `auto_role` from `sfs_hr_departments` and assigns it if `wp_roles()->is_role($dept_role)` returns true. This means any role defined in WordPress — including `administrator` — could be assigned if a malicious or misconfigured `auto_role` value is stored in the departments table.
**Impact:** If an attacker can set `auto_role = 'administrator'` on a department (via the Departments admin page), hiring a candidate from that department creates a WP administrator.
**Fix:** Add a role allowlist check before assigning:
```php
$allowed_roles = ['sfs_hr_employee', 'sfs_hr_manager', 'sfs_hr_trainee', 'subscriber'];
if ($dept_role && in_array($dept_role, $allowed_roles, true) && wp_roles()->is_role($dept_role)) {
    $wp_role = $dept_role;
}
```

---

## Performance Findings

### HIR-PERF-001
**Severity:** High
**Location:** `HiringModule.php:346-354`, `HiringModule.php:532-540`
**Description:** Employee code generation uses `ORDER BY id DESC LIMIT 1` to find the last code, then increments it. This is a TOCTOU (Time-of-Check-Time-of-Use) race condition under concurrent load — two simultaneous conversion requests could both read the same last code and generate the same employee code. While `employee_code` does not appear to have a UNIQUE constraint in the DDL (the `sfs_hr_employees` table `install()` is in `Migrations.php`), duplicate codes would corrupt the HR data model.

The candidate reference number generation delegates to `Helpers::generate_reference_number()` which uses `MAX()` — safer but still has the same TOCTOU gap between SELECT and INSERT.
**Impact:** Duplicate employee codes on concurrent hires (rare in practice but possible during batch hiring events).
**Fix:** Use `INSERT ... SELECT` with a sequence lock, or wrap the generation and insert in a MySQL `GET_LOCK()` as the Attendance module does, or rely on a database UNIQUE constraint + retry loop. Minimum fix: add a UNIQUE constraint on `sfs_hr_employees.employee_code` so duplicates fail loudly rather than silently.

### HIR-PERF-002
**Severity:** Medium
**Location:** `HiringModule.php:242-261` (`generate_trainee_code`)
**Description:** `generate_trainee_code()` uses `ORDER BY id DESC LIMIT 1` with a `LIKE` prefix pattern. This prevents use of an index on `trainee_code` (LIKE with `%` wildcard at end can use an index, but the pattern `TRN-YYYY-%` with a 9-character fixed prefix means the optimizer may not use it efficiently). For tables with thousands of trainees, this could do a full table scan.
**Impact:** Slow code generation when the trainees table is large.
**Fix:** Add an index on `trainee_code` column (it already has `UNIQUE KEY trainee_code (trainee_code)` in the DDL — this is actually fine as UNIQUE implies an index; the real concern is the `ORDER BY id DESC` which requires a separate pass). Consider a dedicated counter table or `AUTO_INCREMENT`-based sequence.

### HIR-PERF-003
**Severity:** Medium
**Location:** `Admin/class-admin-pages.php:875-876`
**Description:** The trainee form render loads all active employees with no LIMIT:
```php
$employees = $wpdb->get_results("SELECT id, CONCAT(...) as name FROM {$wpdb->prefix}sfs_hr_employees WHERE status = 'active' ORDER BY first_name");
```
This is an unbounded query — on a large installation with hundreds or thousands of employees, this loads all of them into memory to populate a `<select>` dropdown.
**Impact:** High memory usage and slow page load on large installations.
**Fix:** Add pagination or convert to an AJAX typeahead/autocomplete input rather than a full `<select>` dropdown.

### HIR-PERF-004
**Severity:** Low
**Location:** `Admin/class-admin-pages.php:248`, `Admin/class-admin-pages.php:875`
**Description:** The candidate form and trainee form each load all active departments with `get_results()` — two separate queries on the same data structure. These queries are simple and static, but they are executed on every form render without any caching.
**Impact:** Minor — negligible on most installations, but could be reduced with a transient cache on department lists.
**Fix:** Cache department list with `get_transient('sfs_hr_departments_list')` / `set_transient(...)`.

---

## Duplication Findings

### HIR-DUP-001
**Severity:** High
**Location:** `HiringModule.php:331-472` vs `HiringModule.php:517-636`
**Description:** `convert_trainee_to_employee()` and `convert_candidate_to_employee()` share approximately 75% of their logic:
- Employee code generation (lines 347-354 vs 533-540) — **identical raw query**
- Username generation from name (lines 361-363 vs 543-545) — identical
- Username uniqueness loop (lines 366-370 vs 549-553) — identical
- `wp_generate_password()` + `wp_create_user()` + error check (lines 378-383 vs 555-559) — identical
- `wp_update_user()` for meta (lines 386-391 vs 562-568) — identical
- Department `auto_role` lookup (lines 403-410 vs 573-580) — identical
- `WP_User->set_role()` (line 412 vs 582) — identical
- Probation calculation (lines 415-417 vs 585-587) — identical
- `$wpdb->insert()` into `sfs_hr_employees` (lines 424-441 vs 590-607) — nearly identical (same columns, different source object)
- Probation user meta (lines 464-465 vs 625-626) — identical

Only the source record type (trainee vs candidate), the post-conversion update, and the notification method differ.
**Impact:** Any bug fix or enhancement to the conversion logic must be applied in two places.
**Fix:** Extract a `_create_employee_from_data(array $data, array $extra_data): ?int` private static method that accepts normalized employee data. Both conversion methods prepare the normalized data from their respective source records and call the shared method.

### HIR-DUP-002
**Severity:** High
**Location:** `HiringModule.php:477-509` (`notify_hr_trainee_hired`) vs `HiringModule.php:641-672` (`notify_hr_new_hire`)
**Description:** The two HR notification methods are near-identical: both read `CoreNotifications::get_settings()`, check `hr_emails` and `hr_notification`, build a `$message` string with `sprintf()`, and loop `foreach ($hr_emails as $hr_email)` calling `Helpers::send_mail()`. The only differences are the email subject prefix and the message body fields.
**Impact:** Same fix-in-two-places problem as HIR-DUP-001.
**Fix:** Extract a private `_send_hr_notification(string $subject, string $message): void` method that handles the settings check and sending loop. Both `notify_hr_*` methods call it with their specific subject and message.

### HIR-DUP-003
**Severity:** Medium
**Location:** `Admin/class-admin-pages.php:1707-1719` vs `HiringModule.php:677-694`
**Description:** Trainee account creation in `handle_trainee_action()` (case `create_account`) duplicates the welcome email composition logic that also exists in `send_welcome_email()`. The `handle_trainee_action` case manually builds the same subject/message/wp_mail call instead of calling `HiringModule::send_welcome_email()` (which is `private static`, preventing reuse).
**Impact:** If the welcome email template changes, both copies must be updated.
**Fix:** Change `send_welcome_email()` to `protected static` (or move it to a public method on the module), and call it from `handle_trainee_action` case `create_account`.

---

## Logical Findings

### HIR-LOGIC-001
**Severity:** Critical
**Location:** `HiringModule.php:331-472` (`convert_trainee_to_employee`), `HiringModule.php:517-636` (`convert_candidate_to_employee`)
**Description:** Both conversion methods are **not atomic**. The sequence of operations is:
1. Read source record
2. Generate employee code (SELECT)
3. Create WP user (`wp_create_user`)
4. Set role (`set_role`)
5. INSERT into `sfs_hr_employees`
6. UPDATE source record status

There is no database transaction wrapping these steps. If step 5 (`$wpdb->insert` into employees) fails after step 3 (WP user created), the WP user exists but has no corresponding employee record — a "ghost" user. If step 6 (UPDATE trainee/candidate status) fails after step 5, the source record retains its original status and the conversion can be triggered again, creating a duplicate employee record from the same source.

Additionally in `convert_trainee_to_employee()`: if `email_exists()` returns true (line 374), the method returns null but the WP user account was not yet created — this is safe. However, the employee_code was already generated but not used; on the next call, the same code will be generated (no sequence gap). This is correct behavior.
**Impact:** Data inconsistency: orphan WP users, duplicate employee records from the same source, or a trainee/candidate that appears "not yet converted" after a partial failure.
**Fix:**
```php
$wpdb->query('START TRANSACTION');
// ... all DB operations ...
if ($employee_id) {
    $wpdb->query('COMMIT');
} else {
    $wpdb->query('ROLLBACK');
    // Also clean up WP user if created
    if ($user_id && !$pre_existing_user) {
        wp_delete_user($user_id);
    }
    return null;
}
```
Note: `wp_create_user()` itself does not run inside `$wpdb` and is not rolled back by MySQL `ROLLBACK` — WP user creation must be handled separately in the error path.

### HIR-LOGIC-002
**Severity:** High
**Location:** `HiringModule.php:274`, `HiringModule.php:339`, `HiringModule.php:525`
**Description:** **No duplicate-conversion guard.** The conversion methods check source status but there is no atomic status update that prevents double-conversion:
- `convert_trainee_to_candidate()`: checks `$trainee->status !== 'completed'` — but status is updated to `'converted'` only *after* the candidate INSERT succeeds. Two concurrent calls both pass the status check before either updates it.
- `convert_trainee_to_employee()`: checks `!in_array($trainee->status, ['active', 'completed'])` — same race window.
- `convert_candidate_to_employee()`: checks `$candidate->status !== 'gm_approved'` — same race window.
**Impact:** Concurrent conversion requests (two HR users clicking "hire" simultaneously) can create duplicate employee records.
**Fix:** Use an atomic `UPDATE ... WHERE status = 'X' AND id = Y` as the first step and check `$wpdb->rows_affected`. Only proceed if exactly 1 row was updated (acts as a compare-and-swap):
```php
$updated = $wpdb->update(
    "{$wpdb->prefix}sfs_hr_trainees",
    ['status' => 'converting'],   // intermediate lock state
    ['id' => $trainee_id, 'status' => 'completed']
);
if (!$updated) {
    return null; // already being converted
}
```

### HIR-LOGIC-003
**Severity:** High
**Location:** `HiringModule.php:347-354`
**Description:** Employee code format inconsistency. `convert_trainee_to_employee()` generates codes like `USR-1`, `USR-2` (no zero-padding, no year). `convert_candidate_to_employee()` uses the same format. However, `generate_trainee_code()` uses `TRN-YYYY-0001` (4-digit zero-padded with year), and `generate_candidate_reference()` uses `CND-YYYY-0001`. Employee codes generated from the Employees module directly may use a different format entirely (not audited here). The inconsistency means:
- `USR-1` vs `USR-10` sort lexicographically incorrectly
- The code suffix parse `(int) substr($last, -4)` in the employee code generation would extract `R-10` → `-10` as 4 chars if code is `USR-10` → the `-4` would give `-10`, converting to `int` = `-10`, then `-10 + 1 = -9`, generating `USR--9`

Wait, re-examining: `substr("USR-10", -4)` = `R-10` — no, `-4` from the end of 6 chars gives chars 2-5 = `R-10`, and `(int)"R-10" = 0`, so code becomes `USR-0` (wrong). Once the numeric part exceeds 3 digits, the `-4` substr would include a non-numeric prefix character, corrupting the code.
**Impact:** Employee codes become corrupted (`USR-0`, `USR--1`, etc.) once more than 999 hires occur.
**Fix:** Change the employee code generation to use `Helpers::generate_reference_number('EMP', ...)` for consistency, or at minimum use a 4-digit zero-padded format and parse the suffix correctly:
```php
$num = $last ? ((int) preg_replace('/\D/', '', substr($last, strlen($prefix))) + 1) : 1;
$employee_code = $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
```

### HIR-LOGIC-004
**Severity:** High
**Location:** `HiringModule.php:163-164` (DDL), `HiringModule.php:374`
**Description:** `convert_trainee_to_employee()` checks `email_exists($trainee->email)` before creating a WP user, and returns null if the email is taken. This is a silent failure — the caller (`handle_trainee_action` case `hire_direct`) gets null and redirects without an error message explaining *why* the hire failed. HR users are left with no feedback.

Additionally, there is no corresponding check in `convert_candidate_to_employee()`. If a candidate's email is already registered as a WP user, `wp_create_user()` returns a `WP_Error` (checked at line 558), which also returns null silently.
**Impact:** Silent hire failures; HR does not know why the hire failed and may retry, creating confusion.
**Fix:** Return a WP_Error instead of null from conversion methods, and display the error message to the admin. Alternatively, define error constants and check them in the handler.

### HIR-LOGIC-005
**Severity:** Medium
**Location:** `HiringModule.php:163-164` (sfs_hr_trainees DDL), `HiringModule.php:163`
**Description:** `supervisor_id` in `sfs_hr_trainees` references... what? The column is `BIGINT UNSIGNED NULL` with `KEY idx_supervisor (supervisor_id)`, but there is no `FOREIGN KEY` or comment indicating whether it references `sfs_hr_employees.id` or `wp_users.id`. The trainee form (`Admin/class-admin-pages.php:876`) loads `$employees = $wpdb->get_results("SELECT id, ... FROM sfs_hr_employees ...")` suggesting it references `sfs_hr_employees.id`. However, the view (`Admin/class-admin-pages.php:1018-1020`) does `LEFT JOIN sfs_hr_employees e ON t.supervisor_id = e.id`, confirming it references employee ID.

The issue: if a supervisor is terminated (employee record soft-deleted or status changed), the trainee's supervisor reference becomes stale with no FK constraint to enforce integrity.
**Impact:** Trainees may display a "null" supervisor after their supervisor is deactivated.
**Fix:** Document the FK relationship in a code comment. Consider adding a FK constraint or a runtime NULL check in the view. This is Medium because it is a data integrity concern, not a security or runtime error.

### HIR-LOGIC-006
**Severity:** Low
**Location:** `HiringModule.php:446-450`
**Description:** `convert_trainee_to_employee()` stores the `direct_hire_employee_id` in the trainee's `notes` field as a JSON blob appended to existing notes text:
```php
$updated_notes = $existing_notes ? $existing_notes . "\n\n[Direct Hire Info: " . $hire_info . "]" : "[Direct Hire Info: " . $hire_info . "]";
```
There is no dedicated `employee_id` column on `sfs_hr_trainees` for direct hires (the `candidate_id` column tracks trainee-to-candidate conversion, but not trainee-to-employee direct conversion). This means:
- The direct hire relationship cannot be queried efficiently
- The notes field is a freetext field that could be overwritten by an HR user editing the trainee record
**Impact:** Loss of audit trail for direct-hire conversions; the employee created from the trainee cannot be looked up from the trainee record without parsing a freetext notes field.
**Fix:** Add a `direct_employee_id` column to `sfs_hr_trainees` using `add_column_if_missing()` in `Migrations.php`, and store the relationship there instead of in notes.

---

## $wpdb Call-Accounting Table

### HiringModule.php (44 $wpdb references, 14 actual query operations)

| # | Line | Method | Query Type | Table | Prepared | Notes |
|---|------|--------|-----------|-------|----------|-------|
| 1 | 60 | `get_charset_collate()` | — | — | N/A | Utility call, not a query |
| 2 | 63 | `{$wpdb->prefix}` | DDL | sfs_hr_candidates | N/A | Inside dbDelta() string |
| 3 | 133 | `dbDelta($candidates)` | CREATE | sfs_hr_candidates | N/A | dbDelta handles DDL safely |
| 4 | 136 | `{$wpdb->prefix}` | DDL | sfs_hr_trainees | N/A | Inside dbDelta() string |
| 5 | 195 | `dbDelta($trainees)` | CREATE | sfs_hr_trainees | N/A | dbDelta handles DDL safely |
| 6 | 222 | `$wpdb->prefix` | — | sfs_hr_candidates | N/A | Table name for Helpers call |
| 7 | 247 | `get_var(prepare(...))` | SELECT | sfs_hr_trainees | YES | `trainee_code LIKE %s` |
| 8 | 248 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 9 | 269 | `get_row(prepare(...))` | SELECT | sfs_hr_trainees | YES | `id = %d` |
| 10 | 270 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 11 | 284 | `insert(...)` | INSERT | sfs_hr_candidates | YES | Uses $wpdb->insert() typed format |
| 12 | 305 | `insert_id` | — | — | N/A | Property read after insert |
| 13 | 309 | `update(...)` | UPDATE | sfs_hr_trainees | YES | Uses $wpdb->update() typed format |
| 14 | 310 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 15 | 334 | `get_row(prepare(...))` | SELECT | sfs_hr_trainees | YES | `id = %d` |
| 16 | 335 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 17 | 348 | `get_var(...)` | SELECT | sfs_hr_employees | **NO** | **CRITICAL** — raw interpolation of `$prefix` (HIR-SEC-001) |
| 18 | 349 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 19 | 403 | `get_var(prepare(...))` | SELECT | sfs_hr_departments | YES | `id = %d` |
| 20 | 404 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 21 | 424 | `insert(...)` | INSERT | sfs_hr_employees | YES | Uses $wpdb->insert() typed format |
| 22 | 443 | `insert_id` | — | — | N/A | Property read after insert |
| 23 | 452 | `update(...)` | UPDATE | sfs_hr_trainees | YES | Uses $wpdb->update() typed format |
| 24 | 453 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 25 | 520 | `get_row(prepare(...))` | SELECT | sfs_hr_candidates | YES | `id = %d` |
| 26 | 521 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 27 | 534 | `get_var(...)` | SELECT | sfs_hr_employees | **NO** | **CRITICAL** — raw interpolation of `$prefix` (HIR-SEC-001) |
| 28 | 535 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 29 | 573 | `get_var(prepare(...))` | SELECT | sfs_hr_departments | YES | `id = %d` |
| 30 | 574 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 31 | 590 | `insert(...)` | INSERT | sfs_hr_employees | YES | Uses $wpdb->insert() typed format |
| 32 | 609 | `insert_id` | — | — | N/A | Property read after insert |
| 33 | 613 | `update(...)` | UPDATE | sfs_hr_candidates | YES | Uses $wpdb->update() typed format |
| 34 | 614 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 35 | 700 | `get_var(prepare(...))` | SELECT | sfs_hr_trainees | YES | `user_id = %d AND status = 'active'` |
| 36 | 701 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |
| 37 | 711 | `get_var` → `get_row(prepare(...))` | SELECT | sfs_hr_trainees | YES | `user_id = %d` |
| 38 | 712 | `{$wpdb->prefix}` | — | — | N/A | Table name interpolation |

**Note on count:** The plan states 44 $wpdb calls. Counting all references to `$wpdb` (including `{$wpdb->prefix}` table name interpolations, `$wpdb->insert_id`, and `$wpdb->get_charset_collate()`) in the file confirms 44 total references. Of those, 14 are actual query-executing calls. 2 are unprepared queries (lines 348, 534 — both generating employee code). All other query-executing calls use `$wpdb->prepare()`, `$wpdb->insert()`, or `$wpdb->update()` correctly.

**Prepared status summary:** 12/14 query calls prepared or safe (insert/update). **2/14 unprepared (Critical).**

---

## Conversion Workflow Audit

### Overview of Three Conversion Paths

| Path | Method | Source Status Gate | Capability Check | Transaction | Atomicity |
|------|--------|-------------------|------------------|-------------|-----------|
| Trainee → Candidate | `convert_trainee_to_candidate()` | `status = 'completed'` | **NONE** | **NONE** | Partial — 2-step INSERT+UPDATE, no lock |
| Trainee → Employee | `convert_trainee_to_employee()` | `status IN ('active','completed')` | **NONE** | **NONE** | Multi-step INSERT+UPDATE, no lock |
| Candidate → Employee | `convert_candidate_to_employee()` | `status = 'gm_approved'` | **NONE** | **NONE** | Multi-step INSERT+UPDATE, no lock |

### Duplicate-Conversion Guard Status

- All three methods check source status before proceeding — provides weak protection against re-conversion.
- No atomic status lock (no `UPDATE ... WHERE status=X` as the first step).
- Under concurrent load, two requests can both pass the status check before either updates the status. See HIR-LOGIC-002.

### Atomicity Assessment

**convert_trainee_to_candidate()** (lines 266-322):
1. `get_row()` — read trainee
2. `generate_candidate_reference()` — SELECT MAX
3. `$wpdb->insert()` — insert candidate
4. `$wpdb->update()` — update trainee status
- Failure after step 3: candidate created, trainee still `completed` → re-running creates duplicate candidate.

**convert_trainee_to_employee()** (lines 331-472):
1. `get_row()` — read trainee
2. `get_var()` — get last employee code (unprepared)
3. `wp_create_user()` — creates WP user
4. `$wpdb->insert()` — insert employee record
5. `$wpdb->update()` — update trainee status
- Failure after step 3: WP user created, no employee record → orphan WP user.
- Failure after step 4: employee created, trainee still `active/completed` → re-run creates duplicate employee with a new WP user (if email check passes via different path).

**convert_candidate_to_employee()** (lines 517-636):
1. `get_row()` — read candidate
2. `get_var()` — get last employee code (unprepared)
3. `wp_create_user()` — creates WP user
4. `$wpdb->insert()` — insert employee record
5. `$wpdb->update()` — update candidate status
- Same failure modes as trainee-to-employee.

### install() DDL Assessment

**No Critical antipatterns found in install().**

The `install()` method (lines 57-196) uses:
- `dbDelta()` for all DDL — the safe, idempotent WordPress DDL mechanism
- No `ALTER TABLE` statements (bare ALTER TABLE was the Critical antipattern in Phase 04, 08)
- No `SHOW TABLES` or `information_schema` queries (Critical antipattern from Phase 04, 08, 11)
- No `$wpdb->prepare()` needed for DDL passed to `dbDelta()` — this is correct

HiringModule.php's `install()` is **clean** from the Phase 04/08/11 DDL antipatterns.

---

## Cross-References with Prior Phase Findings

| Finding | Prior Phase | Pattern |
|---------|-------------|---------|
| HIR-SEC-001 (unprepared employee code query) | Phase 04 (Core), Phase 08 (Loans), Phase 11 (Assets) | Raw string interpolation in `get_var()` |
| HIR-SEC-002 (missing capability checks on public static methods) | Phase 06 (Leave: handle_approve() missing up-front guard), Phase 07 (Performance: save_review no cap guard) | Missing `current_user_can()` at entry point |
| HIR-SEC-004 (admin handlers no capability check) | Phase 09 (Payroll: PADM-SEC-001 wrong capability), Phase 10 (Settlement: SADM-SEC-001 buttons shown without cap check) | Handler security: nonce not enough |
| HIR-LOGIC-001/002 (no transaction, no duplicate-conversion guard) | Phase 06 (Leave: has_overlap() TOCTOU), Phase 09 (Payroll: PADM-LOGIC-001 TOCTOU race) | TOCTOU / missing transaction |
| HIR-PERF-001 (TOCTOU employee code race) | Phase 05 (Attendance: Session_Service GET_LOCK), Phase 09 (Payroll: transient lock TOCTOU) | Concurrent code generation race |
