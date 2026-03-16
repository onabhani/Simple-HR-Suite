# Phase 16 Plan 02: Documents Admin Tab and REST Endpoints — Audit Findings

**Phase:** 16-documents-audit
**Plan:** 02
**Files audited:** 5 files, ~881 lines
- `includes/Modules/Documents/Admin/class-documents-tab.php` (581 lines — admin document tab, forms, listing)
- `includes/Modules/Documents/Rest/class-documents-rest.php` (137 lines — REST endpoints)
- `includes/Modules/Documents/DocumentsModule.php` (147 lines — module bootstrap, schema management)
- `includes/Modules/Documents/Handlers/class-documents-handlers.php` (368 lines — form handlers)
- `includes/Modules/Documents/Services/class-documents-service.php` (648 lines — business logic / DB queries)

*Note: The plan scoped 2 files (~718 lines). The bootstrap and handlers were also reviewed because security issues in those files directly affect the admin tab's security surface. All findings below include file references.*

**Date:** 2026-03-16

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 2 | 3 | 0 | 1 | 6 |
| Performance | 0 | 1 | 2 | 0 | 3 |
| Duplication | 0 | 0 | 1 | 0 | 1 |
| Logical | 0 | 1 | 1 | 1 | 3 |
| **Total** | **2** | **5** | **4** | **2** | **13** |

---

## Security Findings

### DADM-SEC-001 — Critical: Wrong Capability for Manager-Level Access Gate

**Severity:** Critical
**Location:** `class-documents-tab.php:66`

**Description:**
The non-self-service branch of `render_content()` allows access if the user has `sfs_hr_attendance_view_team`:

```php
if (!$is_hr_admin && !current_user_can('sfs_hr_attendance_view_team')) {
    wp_die(esc_html__('You do not have permission to view this.', 'sfs-hr'));
}
```

`sfs_hr_attendance_view_team` is a legacy string-based role/capability specific to the Attendance module team view. It is not a registered `sfs_hr.*` dotted capability and its assignment is undefined in this module's context.

This same antipattern was flagged in Phase 15 as **WADM-SEC-001** (Workforce Status admin used the same wrong capability).

**Impact:**
Any user who happens to hold the `sfs_hr_attendance_view_team` capability can access any employee's full document listing, including sensitive documents (national ID, passport, medical reports, bank details). The intent was likely to allow department managers to view team documents, but the wrong capability gate is used. Additionally, since this is not a dynamic capability check (like `sfs_hr.leave.review`), there is no department-scoping either — all documents for all employees are accessible.

**Fix Recommendation:**
Replace `sfs_hr_attendance_view_team` with the documented dotted capability `sfs_hr.manage` (for full HR admin access) or implement a proper department-manager check using `Helpers::get_managed_departments()` similar to how `sfs_hr.leave.review` is dynamically granted. If department managers legitimately need document access, define `sfs_hr.documents.view_team` and grant it via the same `user_has_cap` dynamic filter mechanism used for leave review.

```php
// Fix:
if (!$is_hr_admin && !current_user_can('sfs_hr.documents.view_team')) {
    wp_die(esc_html__('You do not have permission to view this.', 'sfs-hr'));
}
// Pair with dynamic grant in Capabilities.php for dept managers.
```

---

### DADM-SEC-002 — High: Documents Stored in Public WP Media Library — No Download Access Control

**Severity:** High
**Location:** `class-documents-tab.php:504` (`wp_get_attachment_url()`), `class-documents-rest.php:103` (REST `file_url`), `class-documents-handlers.php:105` (`media_handle_upload()`)

**Description:**
Employee documents are uploaded via `media_handle_upload()` (handler line 105), which stores them in the standard WP Media Library (`wp-content/uploads/`). Download and view links are generated with `wp_get_attachment_url()` and presented directly to authenticated users. The same URL is also returned in REST API responses under the `file_url` key.

Because files land in the public uploads directory, anyone with the URL (authenticated or not) can download any employee document directly. There is no ownership check enforced at the point of file download — only at the point of rendering the URL.

This is the same pattern as **ASSET-SEC-002** (Phase 11) where invoice attachments had no access control on the download path.

**Impact:**
- HR administrators who have legitimate URL access can share or leak the URL externally — documents are permanently exposed via URL.
- Any attacker who enumerates or guesses a document URL can download sensitive employee documents (national IDs, passports, medical records, bank details) without authenticating to WordPress.
- The REST endpoint exposes `file_url` to anyone who passes `check_read_permission`, which includes `sfs_hr_attendance_view_team` users (see DADM-SEC-001).

**Fix Recommendation:**
Two options in increasing order of security:

1. **Short-term (Medium effort):** Move uploads to a non-public directory (outside `wp-content/uploads/` or protected by `.htaccess` / nginx rules). Replace `media_handle_upload()` with a custom upload handler that stores files under a protected path. Serve downloads via an authenticated PHP proxy endpoint that checks capability before streaming the file.

2. **Better (High effort):** Implement a dedicated REST endpoint `GET /sfs-hr/v1/documents/{id}/download` that validates the requesting user's ownership or `sfs_hr.manage` capability before streaming the attachment, and set the file storage to a non-web-accessible path.

Remove `file_url` from the REST response in `get_documents()` until a protected download endpoint is in place.

---

### DADM-SEC-003 — Critical: information_schema Queries in admin_init (5th Recurrence)

**Severity:** Critical
**Location:** `DocumentsModule.php:65`, `DocumentsModule.php:107`

**Description:**
`maybe_install_tables()` uses an `information_schema.tables` query on every `admin_init`:

```php
// DocumentsModule.php:65
$table_exists = (bool) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
    $table
));
```

`maybe_add_update_request_columns()` uses `information_schema.columns` for migration detection (line 107). Both queries execute on every admin page load.

This is the 5th recurrence of the same Critical antipattern across the audit series:
- Phase 04: Core (`admin_init`)
- Phase 08: Loans (`admin_init`)
- Phase 11: Assets (`log_asset_event`)
- Phase 12: Employees (`profile page loads` — 8 calls)
- Phase 16: Documents (`admin_init`)

**Impact:**
- `information_schema` queries can be slow on shared hosts and bypass the query cache on some MySQL configurations.
- They run on every admin page load, not just the Documents module page, inflating baseline admin load times.

**Fix Recommendation:**
Use `SHOW TABLES LIKE` (cached) or the `add_column_if_missing()` helper pattern from `Migrations.php`:

```php
// Replace information_schema.tables check:
$table_exists = $wpdb->get_var($wpdb->prepare(
    "SHOW TABLES LIKE %s", $table
));

// Replace information_schema.columns check:
$column_exists = $wpdb->get_var($wpdb->prepare(
    "SHOW COLUMNS FROM {$table} LIKE %s", 'update_requested_at'
));
```

Also consider moving the table installation entirely into `Migrations.php` (the canonical place for schema management, as established in the project architecture) and removing `maybe_install_tables()` from the module bootstrap entirely.

---

### DADM-SEC-004 — Critical: Bare ALTER TABLE DDL in admin_init (3rd Recurrence)

**Severity:** Critical
**Location:** `DocumentsModule.php:114`

**Description:**
`maybe_add_update_request_columns()` executes a bare `ALTER TABLE` with direct string interpolation of the table name (not a user-controlled value, so no injection risk, but still a pattern violation):

```php
$wpdb->query("ALTER TABLE {$table}
    ADD COLUMN update_requested_at DATETIME DEFAULT NULL AFTER status,
    ADD COLUMN update_requested_by BIGINT(20) UNSIGNED DEFAULT NULL AFTER update_requested_at,
    ADD COLUMN update_request_reason VARCHAR(255) DEFAULT NULL AFTER update_requested_by
");
```

This is executed on every `admin_init` when the column does not exist. Each `ALTER TABLE` on a live MySQL server acquires a metadata lock on the table, blocking all concurrent reads and writes to `sfs_hr_employee_documents` for the duration of the DDL.

This is the 3rd recurrence:
- Phase 04: Core (`admin_init` ALTER TABLE)
- Phase 08: Loans (`admin_init` ALTER TABLE)
- Phase 16: Documents (`admin_init` ALTER TABLE)

**Impact:**
On larger deployments, running `ALTER TABLE` from `admin_init` (triggered on every admin request) means any admin who loads a page while these columns do not exist will block all document queries for other users. On InnoDB with instant DDL (MySQL 8.0.12+), the impact is reduced but not eliminated.

**Fix Recommendation:**
Move column additions to `Migrations.php` using the `add_column_if_missing()` helper:

```php
// In Migrations.php run() method:
$this->add_column_if_missing(
    "{$wpdb->prefix}sfs_hr_employee_documents",
    'update_requested_at',
    'DATETIME DEFAULT NULL AFTER status'
);
```

Remove `maybe_add_update_request_columns()` from `DocumentsModule.php` entirely. The migration will run once at activation and whenever `sfs_hr_db_ver` is bumped.

---

### DADM-SEC-005 — High: Unsanitized Original Filename Stored in Database

**Severity:** High
**Location:** `class-documents-handlers.php:135`

**Description:**
The original filename from the browser upload is stored directly without sanitization:

```php
'file_name' => $_FILES['document_file']['name'],
```

While the stored value is escaped with `esc_html()` when rendered in the admin tab (line 532 of the tab), and `media_handle_upload()` handles the actual file using WordPress's own sanitization for the physical file path, the value in `sfs_hr_employee_documents.file_name` can contain arbitrary characters including path traversal sequences (`../`), null bytes, or multi-byte characters.

**Impact:**
- If any future code path uses `file_name` in a file system operation without re-sanitizing, path traversal becomes possible.
- `file_name` is also returned via the REST API in `get_documents()` (line 98 of the REST file). Consuming clients that render the filename without escaping could encounter XSS.

**Fix Recommendation:**
```php
'file_name' => sanitize_file_name($_FILES['document_file']['name']),
```

`sanitize_file_name()` removes path separators, null bytes, and normalizes the name. Apply this consistently in `handle_upload()` at line 135.

---

### DADM-SEC-006 — Low: No Rate Limiting on Document Reminder Email

**Severity:** Low
**Location:** `class-documents-handlers.php:263` (`handle_send_reminder()`)

**Description:**
`handle_send_reminder()` correctly verifies nonce and requires `sfs_hr.manage`, but there is no throttle or cooldown to prevent an HR admin from repeatedly sending reminder emails to the same employee. Each form submission triggers a fresh `wp_mail()` call via `send_missing_documents_reminder()`.

**Impact:**
An HR admin (either malicious or clicking the form multiple times in quick succession) could spam the employee's inbox. This is a Low-severity UX/policy issue rather than a security vulnerability since it requires `sfs_hr.manage`.

**Fix Recommendation:**
Store the last reminder timestamp in employee meta (`sfs_hr_last_doc_reminder_{employee_id}`) and add a cooldown check (e.g., 24 hours) before sending:

```php
$last_sent = get_option("sfs_hr_doc_reminder_{$employee_id}", 0);
if (time() - $last_sent < DAY_IN_SECONDS) {
    $this->redirect_error(..., __('A reminder was already sent today.', 'sfs-hr'));
    return;
}
update_option("sfs_hr_doc_reminder_{$employee_id}", time());
```

---

## Performance Findings

### DADM-PERF-001 — High: N+1 Queries in get_uploadable_document_types_for_employee()

**Severity:** High
**Location:** `class-documents-service.php:370`

**Description:**
`get_uploadable_document_types_for_employee()` iterates over all enabled document types and calls `can_employee_upload_document_type()` for each. Each call to `can_employee_upload_document_type()` executes `get_active_document_of_type()`, which issues a separate `SELECT` query:

```php
// Service line 370-381:
foreach ($all_types as $type_key => $type_label) {
    $check = self::can_employee_upload_document_type($employee_id, $type_key);
    // ... each call: SELECT * FROM ... WHERE employee_id = %d AND document_type = %s
}
```

With 12 default document types, this is 12 sequential queries. With all types enabled, every self-service tab load runs 12 separate `SELECT` statements in a loop.

**Impact:**
12 database round trips per self-service Documents tab load. Worsens proportionally if custom types are added via the `sfs_hr_document_types` filter.

**Fix Recommendation:**
Fetch all active documents for the employee in one query (already done by `get_employee_documents()`) and then process the type check entirely in PHP:

```php
public static function get_uploadable_document_types_for_employee(int $employee_id): array {
    $all_types = self::get_document_types();
    $existing_docs = [];
    // Fetch all active docs once
    foreach (self::get_employee_documents($employee_id) as $doc) {
        $existing_docs[$doc->document_type] = $doc;
    }

    $uploadable = [];
    foreach ($all_types as $type_key => $type_label) {
        $existing = $existing_docs[$type_key] ?? null;
        if (!$existing) {
            $reason = 'no_existing';
        } elseif (self::is_document_expired($existing->expiry_date)) {
            $reason = 'expired';
        } elseif (self::is_update_requested($existing)) {
            $reason = 'update_requested';
        } else {
            continue; // document exists and is valid
        }
        $uploadable[$type_key] = ['label' => $type_label, 'reason' => $reason, 'existing_doc' => $existing];
    }
    return $uploadable;
}
```

This reduces 12 queries to 1.

---

### DADM-PERF-002 — Medium: get_employee_document_status() Fetches All Documents Twice

**Severity:** Medium
**Location:** `class-documents-service.php:583`

**Description:**
`get_employee_document_status()` calls:
1. `get_missing_required_documents($employee_id)` — which calls `get_documents_grouped()` — which calls `get_employee_documents()` (query 1)
2. `get_employee_documents($employee_id)` again directly at line 586 (query 2)

Both queries are identical: `SELECT * FROM sfs_hr_employee_documents WHERE employee_id = %d AND status = 'active'`.

**Impact:**
Every Documents tab HR view triggers 2 identical queries for the same employee. Minor, but unnecessary.

**Fix Recommendation:**
Refactor `get_employee_document_status()` to fetch documents once and pass the result to the helpers:

```php
public static function get_employee_document_status(int $employee_id): array {
    $documents = self::get_employee_documents($employee_id);
    $grouped = [];
    foreach ($documents as $doc) {
        $grouped[$doc->document_type][] = $doc;
    }
    $required = self::get_required_document_types();
    // compute missing from $grouped in-memory (no extra query)
    // compute expiry counts from $documents
    ...
}
```

---

### DADM-PERF-003 — Medium: No Pagination on REST /documents/expiring Endpoint

**Severity:** Medium
**Location:** `class-documents-rest.php:114`, `class-documents-service.php:63`

**Description:**
`GET /sfs-hr/v1/documents/expiring` returns all expiring documents within the requested `days` window with no `per_page` / `LIMIT` constraint. On a large organization with many documents expiring, this endpoint returns an unbounded result set.

The `days` parameter is bounded to 1-365 (good), but for a 365-day window on a 500-employee organization with 5+ document types, the result set could be thousands of rows.

**Impact:**
- Memory and response size grow unboundedly with employee count and time window.
- No pagination means consuming clients cannot page through results incrementally.

**Fix Recommendation:**
Add `per_page` and `page` parameters to the REST endpoint, and add `LIMIT`/`OFFSET` to `get_expiring_documents()`:

```php
// REST route args:
'per_page' => ['default' => 50, 'validate_callback' => fn($p) => is_numeric($p) && $p > 0 && $p <= 200],
'page'     => ['default' => 1,  'validate_callback' => fn($p) => is_numeric($p) && $p > 0],

// Service:
public static function get_expiring_documents(int $days_ahead = 30, int $per_page = 50, int $page = 1): array {
    $offset = ($page - 1) * $per_page;
    // ... existing query ...
    . " LIMIT %d OFFSET %d",
    $today, $future_date, $per_page, $offset
```

---

## Duplication Findings

### DADM-DUP-001 — Medium: Document Listing Logic Independent in Admin Tab vs REST

**Severity:** Medium
**Location:** `class-documents-tab.php:74` (calls `get_documents_grouped`), `class-documents-rest.php:87` (calls `get_employee_documents`)

**Description:**
The admin tab calls `Documents_Service::get_documents_grouped()` which groups by type, while the REST endpoint calls `Documents_Service::get_employee_documents()` directly and loops to build its own response structure including expiry status. Both independently call `get_expiry_status()` per document in a foreach loop — this logic is duplicated between the admin view and the REST layer.

This is the same pattern noted in Phase 11 (AVIEW-SEC-001/002) where admin views duplicated service-layer logic. Here the delegation is correctly to the service, but the post-processing (expiry enrichment) is independently duplicated in both the tab and REST, creating divergence risk.

**Impact:**
Low risk currently. If expiry calculation logic changes in `get_expiry_status()`, the REST response and admin view will diverge if one consumer calls `get_expiry_status()` and the other does not update to match.

**Fix Recommendation:**
Create a `Documents_Service::format_document_for_output(object $doc): array` helper that returns the enriched document array (with expiry status, icon class, file URL) and call it from both the REST handler and any other consumer. This keeps the response shape and enrichment logic in one place.

---

## Logical Findings

### DADM-LOGIC-001 — High: handle_delete() Does Not Verify document Belongs to employee_id

**Severity:** High
**Location:** `class-documents-handlers.php:169-205`

**Description:**
`handle_delete()` receives both `document_id` and `employee_id` from `$_POST`. It fetches the document by `document_id` alone (line 180) and verifies the caller has `sfs_hr.manage`. However, it never verifies that `$document->employee_id === $employee_id`.

The redirect after deletion uses the POST `employee_id` for the URL. An attacker with `sfs_hr.manage` capability could:
1. Submit `document_id = X` (belonging to employee A) with `employee_id = Y` (their target)
2. Document X is deleted, and they are redirected to employee Y's profile
3. Since they already have `sfs_hr.manage`, the practical impact is limited — but the mismatch is a data integrity gap.

The same pattern exists in `handle_request_update()` (line 211): document ownership versus the submitted `employee_id` is not cross-validated.

**Impact:**
Medium-to-high data integrity gap. An HR admin could accidentally (or deliberately) delete a document belonging to a different employee than shown in the UI, especially if they have multiple profile tabs open. The nonce on `document_id` provides some protection but not full ownership validation.

**Fix Recommendation:**
After fetching the document, add an ownership check:

```php
$document = Documents_Service::get_document($document_id);
if (!$document) {
    wp_die(esc_html__('Document not found.', 'sfs-hr'));
}

// Verify document belongs to the employee in context
if ((int)$document->employee_id !== $employee_id) {
    wp_die(esc_html__('Document does not belong to this employee.', 'sfs-hr'));
}
```

Apply the same fix to `handle_request_update()`.

---

### DADM-LOGIC-002 — Medium: document_type Not Validated Against Allowed Types in Upload Handler

**Severity:** Medium
**Location:** `class-documents-handlers.php:59`

**Description:**
The uploaded `document_type` is sanitized with `sanitize_key()` but never validated against the list of enabled document types from `Documents_Service::get_enabled_document_types()`:

```php
$document_type = isset($_POST['document_type']) ? sanitize_key($_POST['document_type']) : '';
```

An attacker (including a self-service employee) can craft a POST request with an arbitrary `document_type` value (e.g., `document_type=system_override`). This value would be stored verbatim in the `document_type` column and later rendered in the admin tab (escaped, so no XSS, but logically invalid data).

**Impact:**
- Arbitrary document type keys can be stored in the database.
- The document would not appear in the categorized view (since the type is not in `get_document_types()`) — it would silently go into an uncategorized bucket.
- For employees (self-service), this bypass the upload restriction check: `can_employee_upload_document_type()` queries by the exact `document_type` string, so uploading with an unrecognised type bypasses the "already exists" check.

**Fix Recommendation:**
After sanitizing, validate the type against the enabled list:

```php
$allowed_types = Documents_Service::get_enabled_document_types();
if (!array_key_exists($document_type, $allowed_types)) {
    $this->redirect_error($employee_id, $redirect_page, __('Invalid document type.', 'sfs-hr'));
    return;
}
```

---

### DADM-LOGIC-003 — Low: expiry_date Not Validated as a Valid Date

**Severity:** Low
**Location:** `class-documents-handlers.php:61`

**Description:**
`expiry_date` is sanitized with `sanitize_text_field()` but not validated as a proper date or checked to be in the future:

```php
$expiry_date = isset($_POST['expiry_date']) ? sanitize_text_field($_POST['expiry_date']) : null;
```

Values like `"not-a-date"`, `"0000-00-00"`, or past dates can be submitted. `strtotime()` in `get_expiry_status()` will parse some invalid strings unpredictably.

**Fix Recommendation:**
```php
if (!empty($expiry_date)) {
    $parsed = \DateTime::createFromFormat('Y-m-d', $expiry_date);
    if (!$parsed || $parsed->format('Y-m-d') !== $expiry_date) {
        $expiry_date = null; // discard invalid date silently, or redirect with error
    }
}
```

---

## $wpdb Call-Accounting Table

All files in the Documents module audited. The admin tab (`class-documents-tab.php`) contains zero direct `$wpdb` calls — all DB access is delegated to `Documents_Service`. The REST file contains one `$wpdb` call (inline in a permission callback). The service file contains all the substantive queries.

| # | File | Line | Method | Query Type | Prepared | Notes |
|---|------|------|--------|-----------|---------|-------|
| 1 | `DocumentsModule.php` | 65 | `get_var()` | SELECT (information_schema.tables) | Yes (prepare) | Critical: information_schema on every admin_init |
| 2 | `DocumentsModule.php` | 107 | `get_var()` | SELECT (information_schema.columns) | Yes (prepare) | Critical: information_schema on every admin_init |
| 3 | `DocumentsModule.php` | 114 | `query()` | ALTER TABLE | No (table name interpolated — not user input) | Critical: bare DDL in admin_init |
| 4 | `class-documents-rest.php` | 60 | `get_row()` | SELECT | Yes (prepare) | Inline in permission_callback; should delegate to service |
| 5 | `class-documents-service.php` | 26 | `get_results()` | SELECT | Yes (prepare) | get_employee_documents — clean |
| 6 | `class-documents-service.php` | 53 | `get_var()` | SELECT COUNT | Yes (prepare) | get_document_count — clean |
| 7 | `class-documents-service.php` | 71 | `get_results()` | SELECT JOIN | Yes (prepare) | get_expiring_documents — clean |
| 8 | `class-documents-service.php` | 93 | `get_results()` | SELECT JOIN | Yes (prepare) | get_expired_documents — clean |
| 9 | `class-documents-service.php` | 112 | `get_row()` | SELECT | Yes (prepare) | get_document — clean |
| 10 | `class-documents-service.php` | 126 | `insert()` | INSERT | Yes (format array) | create_document — clean |
| 11 | `class-documents-service.php` | 154 | `update()` | UPDATE | Yes (format array) | archive_document — clean |
| 12 | `class-documents-service.php` | 275 | `get_var()` | SELECT COUNT | Yes (prepare) | has_active_document_of_type — clean |
| 13 | `class-documents-service.php` | 290 | `get_row()` | SELECT | Yes (prepare) | get_active_document_of_type — clean |
| 14 | `class-documents-service.php` | 391 | `update()` | UPDATE | Yes (format array) | request_document_update — clean |
| 15 | `class-documents-service.php` | 414 | `update()` | UPDATE | Yes (format array) | clear_update_request — clean |
| 16 | `class-documents-service.php` | 437 | `get_results()` | SELECT | Yes (prepare) | get_documents_pending_update — clean |
| 17 | `class-documents-service.php` | 618 | `get_row()` | SELECT JOIN | Yes (prepare) | send_missing_documents_reminder — clean |
| 18 | `class-documents-handlers.php` | 40 | `get_row()` | SELECT | Yes (prepare) | handle_upload employee validation — clean |

**Totals:**
- 18 `$wpdb` calls across 5 files
- 15 safe (prepared or typed insert/update)
- 2 flagged for `information_schema` antipattern (entries 1, 2)
- 1 flagged for bare ALTER TABLE DDL (entry 3)
- 1 inline in REST permission_callback (entry 4) — prepared but architecture concern
- **0 unprepared SELECT/INSERT/UPDATE/DELETE queries**
- The admin tab file itself has 0 direct `$wpdb` calls (clean delegation to service)

---

## Cross-Reference with Prior Phase Findings

| This Finding | Prior Phase | Finding ID | Pattern |
|-------------|-------------|-----------|---------|
| DADM-SEC-001: Wrong capability (`sfs_hr_attendance_view_team`) | Phase 15 | WADM-SEC-001 | Wrong capability string for access gate |
| DADM-SEC-002: Files in public Media Library | Phase 11 | ASSET-SEC-002 | Uploaded file has no server-side download access control |
| DADM-SEC-003: information_schema on admin_init | Phase 04, 08, 11, 12 | Multiple | 5th recurrence — same antipattern |
| DADM-SEC-004: Bare ALTER TABLE on admin_init | Phase 04, 08 | Multiple | 3rd recurrence — same antipattern |
| DADM-LOGIC-001: Document ownership not cross-validated in delete | Phase 14 | RADM-LOGIC-002 | TOCTOU / insufficient ownership validation in handler |
| DADM-PERF-001: N+1 per-type queries | Phase 07, 15 | PERF-SEC-001, WADM-PERF-002 | N+1 queries in per-record iteration |

---

## REST Endpoint Summary

| Endpoint | Method | Permission Callback | `__return_true`? | Notes |
|----------|--------|--------------------|----|-------|
| `/sfs-hr/v1/documents/{employee_id}` | GET | `check_read_permission` | No | Checks ownership or `sfs_hr.manage` or `sfs_hr_attendance_view_team` (wrong cap, see DADM-SEC-001) |
| `/sfs-hr/v1/documents/expiring` | GET | `check_admin_permission` | No | Requires `sfs_hr.manage` — correct |

**No `__return_true` patterns found.** REST endpoints are correctly gated. The only concern is that `check_read_permission` includes the same wrong capability (`sfs_hr_attendance_view_team`) as the admin tab, propagating DADM-SEC-001 to the REST layer.

---

## Notable Positives

- Documents module uses correct MIME allowlist with `finfo_open(FILEINFO_MIME_TYPE)` magic-bytes detection (documented in Phase 16 Plan 01 findings as superior to Assets Phase 11 which had no allowlist).
- All `Documents_Service` queries use `$wpdb->prepare()` — zero SQL injection risk in the service layer.
- The admin tab delegates 100% of DB access to the service — no inline `$wpdb` calls in the view, which is the correct pattern (unlike Assets Phase 11 AVIEW-SEC-001/002).
- Self-service (`sfs-hr-my-profile`) path correctly derives `employee_id` from the authenticated user's record (line 58: `$employee->user_id !== $current_user_id`), not from POST/GET params — no IDOR risk in the self-service path.
- `handle_delete()` and `handle_request_update()` both require `sfs_hr.manage` — employees cannot delete or request updates on their own documents through the handler layer (correct policy).
- Nonces are per-document (`sfs_hr_delete_document_{$doc->id}`, `sfs_hr_request_update_{$doc->id}`) — correct and specific.
- Output escaping is consistently applied throughout the admin tab: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()` used appropriately for all dynamic output.
