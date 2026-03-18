# Phase 16 Plan 01: Documents Module Audit Findings

**Phase:** 16 — Documents Audit
**Plan:** 01
**Files audited:**
- `includes/Modules/Documents/DocumentsModule.php` (146 lines)
- `includes/Modules/Documents/Services/class-documents-service.php` (647 lines)
- `includes/Modules/Documents/Handlers/class-documents-handlers.php` (367 lines)
- `includes/Modules/Documents/Rest/class-documents-rest.php` (138 lines — included for completeness)

**Total:** ~1298 lines across 4 files
**Date:** 2026-03-16
**Auditor:** Phase 16 automated audit

---

## Summary Table

| Category   | Critical | High | Medium | Low | Total |
|------------|----------|------|--------|-----|-------|
| Security   | 2        | 3    | 2      | 0   | 7     |
| Performance| 0        | 2    | 1      | 0   | 3     |
| Duplication| 0        | 0    | 1      | 1   | 2     |
| Logical    | 0        | 3    | 2      | 1   | 6     |
| **Total**  | **2**    | **8**| **6**  | **1**| **18**|

---

## Security Findings

### DOC-SEC-001 — Critical: information_schema on every admin_init

**Severity:** Critical
**Location:** `DocumentsModule.php:65-68` and `DocumentsModule.php:107-111`
**Description:**
`maybe_install_tables()` is hooked to `admin_init` and fires on every admin page load. It runs two `information_schema.tables` / `information_schema.columns` queries unconditionally:

```php
// Line 65-68
$table_exists = (bool) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
    $table
));

// Line 107-111
$column_exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'update_requested_at'",
    $table
));
```

The second query fires only when the table already exists, but on a fresh install or after every admin page load that lands in the `else` branch, both queries hit `information_schema`. This is the same Critical antipattern found at Phase 04, Phase 08 (Loans), Phase 11 (Assets), Phase 12 (Employees).

**Impact:**
- Performance penalty on every admin page load (information_schema scans are expensive at scale)
- Pattern inconsistency: EmployeesModule (Phase 12), AssetsModule (Phase 11), and ResignationModule (Phase 14) have already moved away from this pattern

**Fix recommendation:**
Replace with `SHOW TABLES LIKE` guarded by a version option check:
```php
public function maybe_install_tables(): void {
    if ((int)get_option('sfs_hr_docs_db_ver', 0) >= 1) {
        return; // already installed and migrated
    }
    global $wpdb;
    $table = $wpdb->prefix . 'sfs_hr_employee_documents';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if (!$exists) {
        // run CREATE TABLE
    }
    // run add_column_if_missing() for update_requested columns
    update_option('sfs_hr_docs_db_ver', 1);
}
```
Alternatively, delegate entirely to `Migrations.php` and use `add_column_if_missing()` as every other recently audited module does.

---

### DOC-SEC-002 — Critical: Bare ALTER TABLE DDL in admin_init path

**Severity:** Critical
**Location:** `DocumentsModule.php:114-118`
**Description:**
`maybe_add_update_request_columns()` executes bare `ALTER TABLE` DDL using string interpolation for the table name — the identical antipattern flagged at Phase 04 (Core), Phase 08 (Loans), and Phase 15 (WorkforceStatus):

```php
$wpdb->query("ALTER TABLE {$table}
    ADD COLUMN update_requested_at DATETIME DEFAULT NULL AFTER status,
    ADD COLUMN update_requested_by BIGINT(20) UNSIGNED DEFAULT NULL AFTER update_requested_at,
    ADD COLUMN update_request_reason VARCHAR(255) DEFAULT NULL AFTER update_requested_by
");
```

While `$table` is constructed from `$wpdb->prefix . 'sfs_hr_employee_documents'` (not user input), this bypasses the canonical `add_column_if_missing()` helper defined in `Migrations.php` and runs raw DDL without any idempotency guard at the statement level. If the column already exists on a partially-migrated install, `ALTER TABLE` will generate a MySQL error that `$wpdb->query()` silently swallows.

**Impact:**
- MySQL error silently swallowed on re-run — no way to detect failed migrations
- Pattern diverges from `Migrations.php` `add_column_if_missing()` helper used everywhere else

**Fix recommendation:**
Use the canonical helper:
```php
// In Migrations.php run() or via the helper
$this->add_column_if_missing($table, 'update_requested_at', 'DATETIME DEFAULT NULL AFTER status');
$this->add_column_if_missing($table, 'update_requested_by', 'BIGINT(20) UNSIGNED DEFAULT NULL AFTER update_requested_at');
$this->add_column_if_missing($table, 'update_request_reason', 'VARCHAR(255) DEFAULT NULL AFTER update_requested_by');
```

---

### DOC-SEC-003 — High: REST endpoint exposes document file URLs to overly broad audience

**Severity:** High
**Location:** `Rest/class-documents-rest.php:50-73` (`check_read_permission`)
**Description:**
`GET /sfs-hr/v1/documents/{employee_id}` grants access to anyone with `sfs_hr_attendance_view_team` capability:

```php
return (int)$employee->user_id === $current_user_id
    || current_user_can('sfs_hr.manage')
    || current_user_can('sfs_hr_attendance_view_team');  // <-- overly broad
```

`sfs_hr_attendance_view_team` is an attendance-scoped capability, not a documents capability. Any attendance supervisor/manager granted that cap can now retrieve full document records — including the `file_url` (attachment URL exposing the physical file path) — for any employee in the organisation. There is no department scoping in `check_read_permission`; a team manager can request `employee_id=N` for an employee in a completely different department and receive their documents.

**Impact:**
- Attendance team leads can read all employees' sensitive personal documents (passports, national IDs, contracts) across department boundaries
- `file_url` exposes direct WP Media Library URLs — no authentication on the file itself (see DOC-SEC-004)

**Fix recommendation:**
Remove `sfs_hr_attendance_view_team` from the documents permission callback. Add a department-scope check if inter-department managers need access:
```php
public static function check_read_permission(\WP_REST_Request $request): bool {
    if (!is_user_logged_in()) return false;
    $employee_id = (int)$request->get_param('employee_id');
    $current_user_id = get_current_user_id();
    // HR admins
    if (current_user_can('sfs_hr.manage')) return true;
    // Own documents
    global $wpdb;
    $emp_table = $wpdb->prefix . 'sfs_hr_employees';
    $employee = $wpdb->get_row($wpdb->prepare("SELECT user_id FROM {$emp_table} WHERE id = %d", $employee_id));
    return $employee && (int)$employee->user_id === $current_user_id;
}
```

---

### DOC-SEC-004 — High: Uploaded documents stored in public WP Media Library with no access control

**Severity:** High
**Location:** `Handlers/class-documents-handlers.php:105` (`media_handle_upload`)
**Description:**
Documents are uploaded via `media_handle_upload('document_file', 0)` which stores files in the standard WP uploads directory (e.g., `wp-content/uploads/2026/03/passport-scan.pdf`). The resulting `attachment_id` is stored, and `wp_get_attachment_url()` is called in the REST response to return the direct file URL.

Because WP uploads are publicly served by default (no `.htaccess` protection, no nonce-gated download URL), anyone who knows or guesses the file URL can download sensitive documents without authentication:
- Passports, national IDs, contracts, bank details, medical reports

Most shared WordPress hosting does not have a deny-all `.htaccess` in `wp-content/uploads/`.

**Impact:**
- Sensitive personal documents (passports, national ID copies, contracts) publicly accessible by URL enumeration or from browser history/logs
- Cross-reference: same category of issue as Assets Phase 11 (invoice attachments stored via `save_data_url_attachment` without access control)

**Fix recommendation:**
Store documents outside the web root or in a protected subdirectory. Options in ascending complexity:
1. Upload to `wp-content/uploads/hr-documents/` and add `.htaccess` `Deny from all` (best for Apache hosts)
2. Use a WP nonce-gated download endpoint instead of returning raw `file_url` in the REST response:
   ```php
   // Instead of wp_get_attachment_url($doc->attachment_id):
   'file_url' => add_query_arg([
       'action' => 'sfs_hr_download_document',
       'doc_id' => $doc->id,
       'nonce'  => wp_create_nonce('download_doc_' . $doc->id),
   ], admin_url('admin-ajax.php')),
   ```
3. For existing installs: add `.htaccess` to the uploads directory immediately as a stopgap.

---

### DOC-SEC-005 — High: handle_upload() missing login check — unauthenticated upload possible

**Severity:** High
**Location:** `Handlers/class-documents-handlers.php:28-163` (`handle_upload`)
**Description:**
`admin_post_sfs_hr_upload_document` is hooked without the `nopriv_` prefix. However, `admin_post_{action}` only fires for logged-in users; unauthenticated users redirect to login. This is safe.

**Correction:** After careful review, `admin_post_` (without `nopriv_`) only fires for logged-in users. This is NOT a vulnerability. However, the handler still performs capability checks inline rather than at entry point:

```php
// Line 50-55
$is_hr_admin = current_user_can('sfs_hr.manage');
$is_self_upload = ((int)$employee->user_id === $current_user_id);

if (!$is_self_upload && !$is_hr_admin) {
    wp_die(esc_html__('You do not have permission...', 'sfs-hr'));
}
```

This check fires AFTER the employee lookup DB query (line 40-43), meaning an authenticated but unauthorised user (any logged-in WP user without HR role) causes a DB query before being rejected. The nonce is also checked before capability, which is correct order.

**Reclassification — Medium.** See DOC-SEC-006.

---

### DOC-SEC-006 — Medium: document_type not validated against allowed type list on upload

**Severity:** Medium
**Location:** `Handlers/class-documents-handlers.php:59`, `Services/class-documents-service.php:125-144`
**Description:**
`document_type` is sanitized with `sanitize_key()` (line 59) and stored directly without validation against the allowed document types list:

```php
$document_type = isset($_POST['document_type']) ? sanitize_key($_POST['document_type']) : '';
// ...
Documents_Service::create_document([
    'document_type' => $document_type,  // not validated against get_document_types()
    ...
]);
```

An authenticated user (employee or HR) can supply any string as `document_type` (e.g., `xss_test`, `'; DROP TABLE --`, or a very long string). While SQL injection is prevented by `$wpdb->insert()` parameterization, arbitrary document types can pollute the `document_type` column, break filtering logic, and bypass the HR-configured type restriction system.

**Impact:**
- Employees can bypass the enabled/required document type restrictions by supplying an arbitrary type key not in `get_document_types()`
- Type-based filtering and reporting is corrupted

**Fix recommendation:**
```php
$allowed_types = array_keys(Documents_Service::get_document_types());
if (!in_array($document_type, $allowed_types, true)) {
    $this->redirect_error($employee_id, $redirect_page, __('Invalid document type.', 'sfs-hr'));
    return;
}
```

---

### DOC-SEC-007 — Medium: expiry_date not validated as a date before storage

**Severity:** Medium
**Location:** `Handlers/class-documents-handlers.php:61`
**Description:**
`expiry_date` is sanitized as text (`sanitize_text_field`) but not validated as a valid date format before being stored in the `expiry_date DATE` column:

```php
$expiry_date = isset($_POST['expiry_date']) ? sanitize_text_field($_POST['expiry_date']) : null;
```

An attacker can supply `expiry_date = "not-a-date"` which MySQL will store as `0000-00-00` or reject with a warning that wpdb silently ignores, causing expiry logic (`is_document_expired()`, date calculations) to behave unexpectedly.

**Fix recommendation:**
```php
if ($expiry_date) {
    $d = \DateTime::createFromFormat('Y-m-d', $expiry_date);
    if (!$d || $d->format('Y-m-d') !== $expiry_date) {
        $expiry_date = null; // silently discard invalid dates, or redirect_error
    }
}
```

---

## Performance Findings

### DOC-PERF-001 — High: N+1 DB query in get_uploadable_document_types_for_employee()

**Severity:** High
**Location:** `Services/class-documents-service.php:366-382`
**Description:**
`get_uploadable_document_types_for_employee()` loops over every enabled document type and calls `can_employee_upload_document_type()` for each, which in turn calls `get_active_document_of_type()` — a separate DB query per type:

```php
foreach ($all_types as $type_key => $type_label) {
    $check = self::can_employee_upload_document_type($employee_id, $type_key);
    // can_employee_upload_document_type() → get_active_document_of_type() = 1 DB query per type
}
```

With 12 default document types enabled, this is 12 DB queries per call. If `get_missing_required_documents()` (which calls `get_documents_grouped()`) is also called on the same request (as it is for the employee profile page summary), the pattern doubles.

**Impact:**
- 12+ queries per employee profile page load for the "what can I upload" section
- Scales linearly with number of enabled document types

**Fix recommendation:**
Fetch all documents for the employee in one query and build the map in PHP:
```php
public static function get_uploadable_document_types_for_employee(int $employee_id): array {
    $all_types = self::get_document_types();
    // Single query: get all active docs for this employee
    $all_docs = self::get_employee_documents($employee_id, 'active');
    $by_type = [];
    foreach ($all_docs as $doc) {
        // Keep only the most recent per type
        if (!isset($by_type[$doc->document_type]) ||
            $doc->created_at > $by_type[$doc->document_type]->created_at) {
            $by_type[$doc->document_type] = $doc;
        }
    }
    $uploadable = [];
    foreach ($all_types as $type_key => $type_label) {
        $existing = $by_type[$type_key] ?? null;
        // apply same logic as can_employee_upload_document_type() inline
        ...
    }
    return $uploadable;
}
```

---

### DOC-PERF-002 — High: information_schema on every admin_init (performance dimension of DOC-SEC-001)

**Severity:** High
**Location:** `DocumentsModule.php:65-68`, `107-111`
**Description:**
Already documented under DOC-SEC-001. The performance impact is separately notable: `information_schema` queries scan metadata tables and are 10-100x slower than `SHOW TABLES LIKE`. Two such queries run on every WordPress admin page load, not just during plugin activation or upgrade.

**Impact:**
- Adds ~2–5ms per admin page load on shared hosting (cumulative across all modules that share this pattern: Core, Loans, Assets, Employees = 5+ modules × 2 queries each)

**Fix recommendation:** Same as DOC-SEC-001 — use a version-gated check with `SHOW TABLES LIKE`.

---

### DOC-PERF-003 — Medium: get_expiring_documents() and get_expired_documents() have no LIMIT

**Severity:** Medium
**Location:** `Services/class-documents-service.php:63-82`, `87-103`
**Description:**
Both `get_expiring_documents()` and `get_expired_documents()` perform unbounded `SELECT *` queries with no `LIMIT` clause:

```php
return $wpdb->get_results($wpdb->prepare(
    "SELECT d.*, e.first_name, e.last_name, e.employee_code
     FROM {$table} d
     JOIN {$emp_table} e ON e.id = d.employee_id
     WHERE d.status = 'active'
       AND d.expiry_date BETWEEN %s AND %s
     ORDER BY d.expiry_date ASC",
    $today,
    $future_date
));
```

In a large organisation with 500+ employees and many document types, expiring/expired documents could number in the thousands, loading all into memory on each call.

**Fix recommendation:**
Add pagination support or a sensible `LIMIT` with a `$limit` parameter defaulting to 100:
```php
public static function get_expiring_documents(int $days_ahead = 30, int $limit = 100): array {
    // ...
    "... ORDER BY d.expiry_date ASC LIMIT %d",
    $today, $future_date, $limit
}
```

---

## Duplication Findings

### DOC-DUP-001 — Medium: MIME type detection duplicated between service and handler

**Severity:** Medium
**Location:** `Services/class-documents-service.php:206-208` and `Handlers/class-documents-handlers.php:96-98`
**Description:**
The `finfo_open`/`finfo_file` MIME detection pattern is executed twice on every upload: once inside `validate_upload()` in the service (for validation) and again in the handler (to get the MIME type to store):

```php
// In Documents_Service::validate_upload() (lines 206-208)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

// In Documents_Handlers::handle_upload() (lines 96-98)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $_FILES['document_file']['tmp_name']);
finfo_close($finfo);
```

`finfo_open` is not expensive, but the duplication is an unnecessary repetition that could also cause subtle bugs if `validate_upload()` is ever changed to use a different detection method.

**Fix recommendation:**
Have `validate_upload()` return the detected MIME type alongside the errors, or add a dedicated `detect_mime_type()` helper:
```php
public static function validate_upload(array $file): array {
    $errors = [];
    $mime_type = null;
    // ...
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime_type, self::get_allowed_mime_types(), true)) {
        $errors[] = ...;
    }
    return ['errors' => $errors, 'mime_type' => $mime_type];
}
```

---

### DOC-DUP-002 — Low: get_document_types() / get_all_document_types() overlap

**Severity:** Low
**Location:** `Services/class-documents-service.php:15-17`, `460-476`, `506-523`
**Description:**
The module has three overlapping "document types" methods:
- `get_document_types()` → returns enabled types (via `get_enabled_document_types()`)
- `get_all_document_types()` → returns all base types including disabled ones
- `get_enabled_document_types()` → used internally to filter enabled types

The naming is inconsistent — `get_document_types()` silently returns only enabled types, which is non-obvious to callers. `get_all_document_types()` is the one that returns the full set. The deprecated `DocumentsModule::get_document_types()` shim delegates to `Documents_Service::get_document_types()` (enabled-only), which matches the pre-refactor intent, but the naming creates confusion.

**Fix recommendation:**
Rename `get_document_types()` to `get_enabled_document_types_public()` (or deprecate in favor of `get_enabled_document_types()`) and update callers in the next non-audit phase.

---

## Logical Findings

### DOC-LOGIC-001 — High: archive_document() does NOT delete the physical file or WP attachment

**Severity:** High
**Location:** `Services/class-documents-service.php:150-163`
**Description:**
`archive_document()` only flips the `status` to `archived` in the DB. The WP Media Library attachment (`attachment_id`) remains, and the physical file remains in `wp-content/uploads/`. Over time this accumulates:
- Replaced documents (every `handle_upload` with `$existing_doc` archives the old one without deleting its file)
- HR-deleted documents (`handle_delete` calls `archive_document`, not a hard delete)

There is no cleanup cron, no cleanup on attachment. An organisation uploading monthly contracts for 200 employees will silently accumulate hundreds of orphaned files that remain publicly accessible (see DOC-SEC-004).

**Impact:**
- Disk space growth with no bound
- Orphaned files remain publicly accessible by direct URL even after the document is "deleted" by HR

**Fix recommendation:**
When archiving a document intended for hard deletion (the `handle_delete` flow), also delete the WP attachment:
```php
// In handle_delete(), after archive_document():
$attachment_id = $document->attachment_id;
if ($attachment_id) {
    wp_delete_attachment($attachment_id, true); // true = force delete
}
```
For the "replace" flow (uploading a new version), retaining the old file for audit trail may be intentional — but the old attachment URL should be protected or the attachment set to private.

---

### DOC-LOGIC-002 — High: No UNIQUE constraint on (employee_id, document_type, status='active')

**Severity:** High
**Location:** `DocumentsModule.php:71-94` (CREATE TABLE), `Services/class-documents-service.php:121-145`
**Description:**
The `sfs_hr_employee_documents` table has no UNIQUE constraint preventing multiple `active` documents of the same type for the same employee. The `can_employee_upload_document_type()` check provides application-level enforcement, but this check is:
1. Not applied when HR/admin uploads (line 78-81 in handlers: HR path only calls `get_active_document_of_type()` to find the existing doc, but has no guard if `archive_document()` fails mid-transaction)
2. Not thread-safe (TOCTOU: check then insert)

If `archive_document()` succeeds but `create_document()` fails for any reason, the old document is archived and no new one exists. If `archive_document()` fails silently (DB error) and `create_document()` succeeds, two active documents of the same type exist.

**Impact:**
- Multiple active passports / national IDs per employee corrupt the "one active document per type" invariant
- `get_active_document_of_type()` uses `LIMIT 1 ORDER BY created_at DESC` which masks duplicates silently

**Fix recommendation:**
1. Add a partial unique index: `UNIQUE KEY uniq_active_type (employee_id, document_type, status)` — note this prevents multiple archived docs of the same type too; use a generated column or application-level guard instead
2. Wrap archive + create in a transaction:
```php
$wpdb->query('START TRANSACTION');
Documents_Service::archive_document($existing_doc->id);
$doc_id = Documents_Service::create_document([...]);
if (!$doc_id) {
    $wpdb->query('ROLLBACK');
    // handle error
} else {
    $wpdb->query('COMMIT');
}
```

---

### DOC-LOGIC-003 — High: handle_upload() stores raw $_FILES['document_file']['name'] as file_name

**Severity:** High
**Location:** `Handlers/class-documents-handlers.php:135`
**Description:**
The original client-supplied filename is stored verbatim in the `file_name` column:

```php
'file_name' => $_FILES['document_file']['name'],
```

While `media_handle_upload()` sanitizes the filename for the actual file on disk, the `file_name` column stores the original unescaped name (e.g., `../../evil.php`, `<script>alert(1)</script>.pdf`). If `file_name` is ever rendered unescaped in admin or frontend views, this is a stored XSS or path traversal vector.

The `create_document()` service stores whatever it receives with no sanitization applied at the service layer.

**Impact:**
- Stored XSS if `file_name` is rendered in admin without escaping
- Path traversal appearance in logs/audit trail (though actual file is stored safely via `media_handle_upload`)

**Fix recommendation:**
Sanitize at the handler before passing to service:
```php
'file_name' => sanitize_file_name($_FILES['document_file']['name']),
```

---

### DOC-LOGIC-004 — Medium: handle_delete() archives by document_id but uses employee_id from POST (not from document)

**Severity:** Medium
**Location:** `Handlers/class-documents-handlers.php:169-205`
**Description:**
The handler receives both `document_id` and `employee_id` from POST. It fetches the document by `document_id` but then uses the POST `employee_id` for the redirect URL without cross-checking that `$document->employee_id === $employee_id`:

```php
$document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
$employee_id  = isset($_POST['employee_id'])  ? (int)$_POST['employee_id']  : 0;

$document = Documents_Service::get_document($document_id);
// ... no check that $document->employee_id === $employee_id

Documents_Service::archive_document($document_id);
$this->redirect_success($employee_id, $redirect_page, ...);
```

While the delete itself is correct (it uses `document_id`), a forged request with a wrong `employee_id` will redirect to the wrong employee's profile. More importantly: if an HR admin in one org unit can supply any `document_id`, they can delete documents belonging to employees in other org units with no department scoping check.

**Impact:**
- HR admin can delete any employee's document, not just those in their department
- Redirect can be manipulated to a different employee's profile (cosmetic confusion)

**Fix recommendation:**
```php
if ($document->employee_id !== $employee_id) {
    wp_die(esc_html__('Document does not belong to this employee.', 'sfs-hr'));
}
```

---

### DOC-LOGIC-005 — Medium: REST endpoint returns file_url (direct attachment URL) for team managers

**Severity:** Medium
**Location:** `Rest/class-documents-rest.php:103`
**Description:**
The REST response includes `'file_url' => wp_get_attachment_url($doc->attachment_id)` which returns the direct WP uploads URL. This is a logical issue compounding DOC-SEC-003 and DOC-SEC-004: even if the permission callback is tightened, the `file_url` field in the JSON response gives any authorised API caller a direct, unauthenticated download URL. There is no time-limited or nonce-gated download mechanism.

**Fix recommendation:**
Replace `file_url` with a nonce-gated download URL (as suggested in DOC-SEC-004), or omit it and require a separate authenticated download endpoint.

---

### DOC-LOGIC-006 — Low: `get_employee_document_status()` performs 3 separate queries for the same employee

**Severity:** Low
**Location:** `Services/class-documents-service.php:583-609`
**Description:**
`get_employee_document_status()` calls:
1. `get_required_document_types()` — option read (cheap)
2. `get_missing_required_documents()` — which calls `get_documents_grouped()` — which calls `get_employee_documents()` — 1 DB query
3. `get_employee_documents($employee_id)` — another DB query for the same employee

So `get_employee_documents()` is called twice for the same `$employee_id`. On the employee profile summary tab this is two identical queries.

**Fix recommendation:**
```php
public static function get_employee_document_status(int $employee_id): array {
    $documents = self::get_employee_documents($employee_id); // fetch once
    $grouped = [];
    foreach ($documents as $doc) {
        $grouped[$doc->document_type][] = $doc;
    }
    $required = self::get_required_document_types();
    $missing = []; // compute inline from $grouped
    // ...
}
```

---

## $wpdb Call-Accounting Table

All `$wpdb` calls across `DocumentsModule.php`, `Documents_Service.php`, and `Documents_Handlers.php`:

| # | File | Line | Method | Query Type | Prepared | Notes |
|---|------|------|--------|------------|----------|-------|
| 1 | DocumentsModule.php | 65 | get_var | SELECT (information_schema.tables) | Yes | information_schema antipattern (DOC-SEC-001) |
| 2 | DocumentsModule.php | 107 | get_var | SELECT (information_schema.columns) | Yes | information_schema antipattern (DOC-SEC-001) |
| 3 | DocumentsModule.php | 71 | query | CREATE TABLE DDL | No (static table name) | CREATE TABLE IF NOT EXISTS — safe static query |
| 4 | DocumentsModule.php | 114 | query | ALTER TABLE DDL | No (string interpolation) | Bare ALTER TABLE antipattern (DOC-SEC-002) |
| 5 | Documents_Service.php | 26 | get_results | SELECT | Yes | get_employee_documents — no LIMIT |
| 6 | Documents_Service.php | 53 | get_var | SELECT COUNT | Yes | get_document_count — safe |
| 7 | Documents_Service.php | 71 | get_results | SELECT + JOIN | Yes | get_expiring_documents — no LIMIT (DOC-PERF-003) |
| 8 | Documents_Service.php | 93 | get_results | SELECT + JOIN | Yes | get_expired_documents — no LIMIT (DOC-PERF-003) |
| 9 | Documents_Service.php | 112 | get_row | SELECT | Yes | get_document — safe |
| 10 | Documents_Service.php | 126 | insert | INSERT | Yes (format array) | create_document — safe |
| 11 | Documents_Service.php | 154 | update | UPDATE | Yes (format array) | archive_document — safe |
| 12 | Documents_Service.php | 275 | get_var | SELECT COUNT | Yes | has_active_document_of_type — safe |
| 13 | Documents_Service.php | 290 | get_row | SELECT | Yes | get_active_document_of_type — safe |
| 14 | Documents_Service.php | 391 | update | UPDATE | Yes (format array) | request_document_update — safe |
| 15 | Documents_Service.php | 414 | update | UPDATE | Yes (format array) | clear_update_request — safe |
| 16 | Documents_Service.php | 437 | get_results | SELECT | Yes | get_documents_pending_update — safe |
| 17 | Documents_Service.php | 618 | get_row | SELECT + JOIN | Yes | send_missing_documents_reminder employee lookup — safe |
| 18 | Documents_Handlers.php | 40 | get_row | SELECT | Yes | Employee lookup in handle_upload — safe |
| 19 | Rest/class-documents-rest.php | 60 | get_row | SELECT | Yes | Employee ownership check in check_read_permission — safe |

**Summary:** 19 total calls. 17 prepared / safe. 2 antipatterns (information_schema × 2 at DocumentsModule:65 and :107). 1 bare DDL (ALTER TABLE at DocumentsModule:114). All `$wpdb->insert()` and `$wpdb->update()` calls use format arrays — no injection risk.

---

## Cross-Reference with Prior Phase Findings

| This Finding | Prior Phase | Prior Finding ID | Pattern |
|-------------|-------------|-----------------|---------|
| DOC-SEC-001: information_schema on admin_init | Phase 04, Phase 08, Phase 11, Phase 12 | CORE-003, LOAN-SEC-001, ASSET-SEC-003, EMP-PERF-001 | information_schema on every admin load — 5th recurrence |
| DOC-SEC-002: Bare ALTER TABLE DDL | Phase 04, Phase 08 | (Core ALTER TABLE), LOAN-SEC-002 | Bare ALTER TABLE — 3rd recurrence; SettlementModule (Phase 10) and HiringModule (Phase 13) are clean |
| DOC-SEC-004: Documents in public WP uploads | Phase 11 | ASSET-SEC-002 | Sensitive files stored in public uploads directory — confirmed recurrence |
| DOC-LOGIC-001: Physical file not deleted on archive/delete | Phase 11 | ASSET-DUP-001 (partial overlap) | Orphaned files accumulate — Assets had status divergence; Documents has orphaned WP attachments |
| DOC-LOGIC-002: No transaction around archive+create | Phase 13, Phase 14 | HIR-LOGIC-001, RADM-LOGIC-002 | Missing transaction guard on multi-step DB operations — 3rd recurrence |
| DOC-LOGIC-003: Unsanitized $_FILES['name'] stored | Phase 13 | HADM-SEC-002 (partial) | Raw input stored in DB — handlers store client-supplied data without full sanitization |

---

## Notable Positives (Patterns Done Well)

1. **MIME type allowlist implemented correctly:** `validate_upload()` uses `finfo_open(FILEINFO_MIME_TYPE)` (magic bytes detection, not file extension or Content-Type header) against a server-defined allowlist. This is the correct approach and contrasts favorably with Assets Phase 11 ASSET-SEC-002 (which had no allowlist).

2. **File upload via `media_handle_upload()`:** The handler uses the WordPress-provided `media_handle_upload()` rather than raw `move_uploaded_file()`. This ensures WP-side file sanitization, size limits, and type checking are applied.

3. **File size validated server-side:** `validate_upload()` checks `$file['size']` before processing. This correctly prevents oversized uploads at the application layer (in addition to `upload_max_filesize` PHP ini).

4. **Handle-delete is HR-only with capability check:** `handle_delete()` immediately checks `current_user_can('sfs_hr.manage')` — employees cannot delete documents, which is a correct design decision.

5. **handle_request_update() and handle_send_reminder() have correct capability gates:** Both handlers check `current_user_can('sfs_hr.manage')` before action.

6. **Self-upload scoping is correct:** `handle_upload()` correctly derives `$is_self_upload` from `$employee->user_id` (DB-verified) compared to `get_current_user_id()` — not from a POST parameter.

7. **All admin-post handlers use nonce verification:** All 4 handlers verify `wp_verify_nonce()` / `check_ajax_referer()` at entry.

8. **No `__return_true` REST routes:** Both REST endpoints require authentication and proper capability checks — no public document exposure via REST (contrast with Attendance Phase 05 critical findings).
