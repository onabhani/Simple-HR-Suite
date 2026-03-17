# Assets Module Core Logic Audit Findings
## Phase 11, Plan 01 — AssetsModule.php + class-assets-rest.php + class-admin-pages.php

**Audit scope:** ~2,524 lines across 3 files
**Files audited:**
- `includes/Modules/Assets/AssetsModule.php` (374 lines)
- `includes/Modules/Assets/Rest/class-assets-rest.php` (23 lines)
- `includes/Modules/Assets/Admin/class-admin-pages.php` (2,101 lines)

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 3 | 3 | 1 | 1 | 8 |
| Performance | 0 | 2 | 2 | 1 | 5 |
| Duplication | 0 | 1 | 2 | 0 | 3 |
| Logical | 0 | 3 | 2 | 1 | 6 |
| **Total** | **3** | **9** | **7** | **3** | **22** |

---

## Security Findings

### ASSET-SEC-001
**Severity:** Critical
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:1507`
**Description:** `handle_assets_export()` fetches all assets with a raw (unprepared) `$wpdb->get_results()` call:
```php
$rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A);
```
While the table name is constructed from `$wpdb->prefix . 'sfs_hr_assets'` (not user input), this is a fully unbounded export — no pagination, no row cap — and the query is not wrapped in `$wpdb->prepare()`. More critically, every row including `invoice_file`, `serial_number`, and financial fields is exported to a plain-text CSV with no column restriction. Any user with `sfs_hr.manage` can exfiltrate the entire asset registry in one request.
**Fix:** The SQL itself is static and safe from injection, but the export should: (1) add a `LIMIT` as a safety cap against accidental memory exhaustion; (2) restrict exported columns to an explicit allowlist rather than `SELECT *`; (3) log export events to the audit trail.

---

### ASSET-SEC-002
**Severity:** Critical
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:1316–1335`
**Description:** Invoice file upload via `handle_asset_save()` uses `wp_handle_upload()` with `['test_form' => false]` — this disables WordPress's own form-origin check but still runs MIME validation via `wp_check_filetype_and_ext()`. However, there is **no explicit allowed-types allowlist** passed to `wp_handle_upload()`. WordPress's default MIME check allows all types permitted by `get_allowed_mime_types()`, which includes `.php` if misconfigured, and notably includes `.html`/`.htm` files. An HTML file containing a malicious `<script>` or `<meta http-equiv=refresh>` tag could be uploaded as an "invoice" and the resulting public URL stored in `invoice_file`. If an admin later clicks that link it executes in browser context.

The `save_data_url_attachment()` method (line 2029–2075) decodes a base64 data URL and writes it directly to the uploads directory with a time-based filename. It calls `wp_check_filetype()` after writing — not before. This means the raw binary hits disk before type validation, creating a brief window. Additionally, `wp_check_filetype()` is not the same as `wp_check_filetype_and_ext()` — it only checks the extension, not the MIME magic bytes.
**Fix:** Pass `['mimes' => ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png']]` to `wp_handle_upload()` for invoice uploads. In `save_data_url_attachment()`, validate the declared MIME from the data URL header against an explicit image allowlist (`image/jpeg`, `image/png`, `image/gif`, `image/webp`) before writing to disk, and use `wp_check_filetype_and_ext()` with magic-byte verification.

---

### ASSET-SEC-003
**Severity:** Critical
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:1963–1986`
**Description:** `log_asset_event()` checks whether the audit log table exists by querying `information_schema.tables`:
```php
$wpdb->prepare(
    "SELECT COUNT(*)
     FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = %s",
    $table
)
```
This is the same antipattern flagged as Critical in Phase 04 (Core) and Phase 08 (Loans). `information_schema` queries are slow on large MySQL servers (full catalog scan), bypass query cache, and produce different results across MySQL 5.x vs 8.x under certain `lower_case_table_names` settings. The pattern also appears in `assignment_has_photo_columns()` implicitly via the `SHOW COLUMNS FROM {$table}` calls on lines 623 and 1108 — both are unprepared.

**SHOW COLUMNS** at lines 623 and 1108:
```php
$wpdb->get_col( "SHOW COLUMNS FROM {$assign_table}", 0 );
```
These are raw queries (no `prepare()`) — table name is from `$wpdb->prefix` so not injectable, but the pattern is flagged by the project audit standards.
**Fix:** Replace `information_schema` lookup with `$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))` (the same safe pattern used in AssetsModule.php:46-50). Replace raw `SHOW COLUMNS FROM` with `$wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col)` if specific column presence is needed, or with the `add_column_if_missing()` migration helper pattern.

---

### ASSET-SEC-004
**Severity:** High
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:491–503`
**Description:** `handle_assign_decision()` checks `is_user_logged_in()` but does **not** check for a specific capability. Any authenticated WordPress user (even one without any `sfs_hr_*` role) can invoke this handler. The nonce check (`check_admin_referer`) happens after the login check but the nonce is derived from the `assignment_id` which appears in the employee-facing form in the HTML — a non-employee logged-in user who can view the admin-post URL could attempt to intercept or replay nonces.

The ownership check at line 539 (`(int) $employee->id !== (int) $assignment->employee_id`) does prevent a wrong employee from approving another's assignment. However, a user who has no employee record (contributor, subscriber without HR role) simply hits the `wp_die('You are not linked to an employee record')` path — which is not a proper capability gate; it leaks that the assignment ID exists.
**Fix:** Add `if (!current_user_can('sfs_hr.view')) { wp_die(...) }` at the top of `handle_assign_decision()` before the nonce check, as the minimum gate. The ownership check that follows is correct and should be kept.

---

### ASSET-SEC-005
**Severity:** High
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:1003–1016`
**Description:** `handle_return_decision()` has the same pattern as ASSET-SEC-004: `is_user_logged_in()` only, no capability check. Any logged-in user can POST to this action. The ownership check at line 1059 is correct and prevents cross-employee return confirmation, but the lack of a capability gate leaks assignment existence via error message.
**Fix:** Same as ASSET-SEC-004 — add `current_user_can('sfs_hr.view')` guard at handler entry.

---

### ASSET-SEC-006
**Severity:** High
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:1680–1693`
**Description:** `handle_assets_import()` performs a CSV upsert (update or insert) with `$wpdb->update()` and `$wpdb->insert()`. The update call at line 1680 passes `$clean_data` without an explicit `$format` argument:
```php
$wpdb->update(
    $table,
    $clean_data,
    [ 'id' => (int) $existing_id ]
);
```
Without a `$format` array, `$wpdb->update()` treats all values as `%s` strings, which is technically safe from injection but means numeric fields (`purchase_year`, `purchase_price`) are stored as strings with no type enforcement. More importantly, the import overwrites **any** column in `$allowed_columns` including `status` — meaning a crafted CSV can change asset status to `archived` or `assigned` without going through the normal assignment lifecycle.
**Fix:** (1) Add explicit `$format` arrays to both `$wpdb->update()` and `$wpdb->insert()` in the import handler; (2) exclude `status` and `condition` from `$allowed_columns` in the import, or apply an ENUM allowlist validation before writing.

---

### ASSET-SEC-007
**Severity:** Medium
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:600–619`
**Description:** `handle_assign_decision()` accepts `selfie_data` and `asset_data` from `$_POST` — base64-encoded image data URLs. The only validation is `strpos($selfie_data, 'data:image') === 0`. This check allows any `data:image/*` MIME type including `data:image/svg+xml` which can contain embedded JavaScript (`<svg onload="...">`). SVG files stored as WP attachments are served with `Content-Type: image/svg+xml` which allows script execution in all major browsers.
**Fix:** In `save_data_url_attachment()`, restrict accepted MIME types to `image/jpeg`, `image/png`, `image/gif`, `image/webp` via an explicit allowlist check against the declared MIME in the data URL. Reject `image/svg+xml` and any non-raster type.

---

### ASSET-SEC-008
**Severity:** Low
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:292`
**Description:** `handle_assign()` and several other handlers contain `error_log()` calls logging user-supplied IDs and DB errors (`$wpdb->last_error`) to PHP error log. While not a direct vulnerability, DB error strings can contain table names, column names, and query fragments. On shared hosting these logs may be accessible via LFI or direct file access.
**Fix:** Remove `error_log()` debug statements from production handlers. If tracing is needed, gate them behind `WP_DEBUG` constant check.

---

## Performance Findings

### ASSET-PERF-001
**Severity:** High
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:1507`
**Description:** `handle_assets_export()` fetches `SELECT * FROM {$table} ORDER BY id ASC` with no `LIMIT`. For an organization with thousands of assets, this loads the entire table into PHP memory in a single query. Combined with the CSV output loop, this can cause memory exhaustion on hosts with low `memory_limit`.
**Fix:** Stream the export in batches (e.g., `LIMIT 500 OFFSET $offset` in a loop writing to `php://output` incrementally), or add a hard cap (e.g., `LIMIT 10000`) with a warning if the count exceeds it.

---

### ASSET-PERF-002
**Severity:** High
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:1632–1694`
**Description:** `handle_assets_import()` performs a `SELECT id ... WHERE asset_code = %s` lookup for **every row** in the CSV (line 1670). For a 1,000-row import this is 1,000 individual queries. There is no batch pre-fetch or upsert strategy.
**Fix:** Pre-fetch all existing asset codes in one query into a PHP associative array, then perform batch insert/update logic against that in-memory map. For large imports, consider chunking and running via WP-Cron.

---

### ASSET-PERF-003
**Severity:** Medium
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:1781`
**Description:** `render_categories()` issues a raw `GROUP BY` query against the assets table without any caching:
```php
$counts = $wpdb->get_results("SELECT category, COUNT(*) as count FROM {$table} GROUP BY category", OBJECT_K);
```
This is a full table scan on every page load of the Categories tab. The query is also raw (no `prepare()`) though the table name is not user-controlled.
**Fix:** Cache the result with a short transient (`get_transient / set_transient` for 5 minutes), and wrap in `$wpdb->prepare()` for consistency (even though table name is static).

---

### ASSET-PERF-004
**Severity:** Medium
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:621–628` and `1107–1111`
**Description:** `handle_assign_decision()` issues `SHOW COLUMNS FROM {$assign_table}` (line 623) on every assignment approval to check if photo columns exist. `handle_return_decision()` does the same (line 1108). There is a static cache helper `assignment_has_photo_columns()` at line 2079 that caches this using a static property, but it is **not called** by these two handlers — they re-run the raw query inline instead.
**Fix:** Replace the inline `SHOW COLUMNS` calls in `handle_assign_decision()` and `handle_return_decision()` with `$this->assignment_has_photo_columns()`, which already implements the static cache.

---

### ASSET-PERF-005
**Severity:** Low
**File:** `includes/Modules/Assets/AssetsModule.php:307–320` and `359–372`
**Description:** `has_unreturned_assets()` (line 307) and `get_unreturned_assets_count()` (line 359) execute nearly identical `COUNT(*)` queries with the same `WHERE` clause. Any caller that needs to know both count > 0 and the actual count will issue two queries. These are static methods called from external modules (e.g., Settlement) during offboarding checks.
**Fix:** Have `has_unreturned_assets()` call `get_unreturned_assets_count()` internally rather than running a separate query.

---

## Duplication Findings

### ASSET-DUP-001
**Severity:** High
**File:** `includes/Modules/Assets/AssetsModule.php:108` and `includes/Modules/Assets/Admin/class-admin-pages.php:127`
**Description:** Asset status is tracked in **two places**: the `sfs_hr_assets.status` column and the `sfs_hr_asset_assignments.status` column. These are semantically linked but managed separately in every handler:
- `handle_assign()`: sets `assets.status = 'pending_employee_approval'` AND `assignments.status = 'pending_employee_approval'`
- `handle_assign_decision()` approve: sets `assets.status = 'assigned'` AND `assignments.status = 'active'`
- `handle_assign_decision()` reject: sets `assets.status = 'available'` AND `assignments.status = 'rejected'`
- `handle_return_decision()`: sets `assets.status = 'available'` AND `assignments.status = 'returned'`

If either update fails without the other being rolled back, the two tables diverge (e.g., asset shows `assigned` but all assignments show `returned`). There is no transaction wrapping these paired updates. The schema also defines different ENUM values: assets use `under_approval` while assignments use `pending_employee_approval` — this naming inconsistency complicates status reasoning.
**Fix:** Wrap paired `$wpdb->update()` calls in a MySQL transaction (`$wpdb->query('START TRANSACTION')` / `$wpdb->query('COMMIT')` / `$wpdb->query('ROLLBACK')`). Consider whether the asset-level status is truly needed, or if it can be derived from the assignment table via a view or helper query, eliminating the dual-tracking entirely.

---

### ASSET-DUP-002
**Severity:** Medium
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:417–468` (in `handle_assign()`) and `555–573` (in `handle_assign_decision()`), and `900–917` (in `handle_return_request()`), and `1072–1082` (in `handle_return_decision()`)
**Description:** Asset info pre-load (`SELECT name, asset_code FROM {$assets_table} WHERE id = %d`) is duplicated in all four handler methods for notification purposes. The pattern is identical each time. Similarly, employee-to-user resolution (`SELECT user_id FROM {$employees_table} WHERE id = %d`) is duplicated across handlers.
**Fix:** Extract a private `get_asset_display_info(int $asset_id): array` helper and a private `get_employee_user_id(int $employee_id): ?int` helper.

---

### ASSET-DUP-003
**Severity:** Medium
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php`
**Description:** The assignment double-assignment guard logic appears in two places:
1. `handle_assign()` lines 307–332: checks `COUNT(*) WHERE asset_id = %d AND status IN (...)` before creating
2. `handle_assign_decision()` lines 579–598: re-checks same condition on approve with an additional `id <> %d` exclusion

While having both is correct (defense in depth), the SQL pattern is repeated rather than extracted to a private method. If the status IN list changes, both places must be updated in sync.
**Fix:** Extract `is_asset_currently_assigned(int $asset_id, int $exclude_assignment_id = 0): bool` private helper.

---

## Logical Findings

### ASSET-LOGIC-001
**Severity:** High
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:307–332` and `579–598`
**Description:** Double-assignment prevention has a TOCTOU (time-of-check to time-of-use) race condition. Both `handle_assign()` and `handle_assign_decision()` perform a `COUNT(*)` check and then — in a separate operation — insert/update the assignment. Two concurrent assignment requests for the same asset could both pass the count check before either has committed. Unlike Attendance (Phase 05) and Payroll (Phase 09) which use MySQL `GET_LOCK()`, the Assets module has no advisory lock or atomic `INSERT WHERE NOT EXISTS` pattern.
**Fix:** Use MySQL advisory lock (`SELECT GET_LOCK('sfs_hr_asset_{$asset_id}', 5)`) around the check-then-insert in `handle_assign()`, or use an atomic `INSERT ... WHERE NOT EXISTS` with a UNIQUE constraint on active assignments.

---

### ASSET-LOGIC-002
**Severity:** High
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:1718–1757` (specifically line 1757)
**Description:** `handle_asset_delete()` calls `$this->log_asset_event(...)` directly on line 1757 without the `method_exists()` guard used everywhere else in the class. If the class is extended and `log_asset_event()` is removed or renamed in a subclass, this will throw a fatal. More practically, if the audit log table (`sfs_hr_asset_logs`) has never been created (it is not provisioned by `maybe_upgrade_db()` in AssetsModule.php — the schema only creates `sfs_hr_assets` and `sfs_hr_asset_assignments`), the delete still proceeds but the log call will silently no-op (because `log_asset_event()` has its own internal table-existence check). This means deletions are logged only if the admin manually creates the audit log table — there is no guarantee.
**Fix:** Add `sfs_hr_asset_logs` table creation to `maybe_upgrade_db()` so audit logging always works. The schema comment in `log_asset_event()` (lines 1952–1962) documents the required columns — move this to actual `CREATE TABLE IF NOT EXISTS` DDL.

---

### ASSET-LOGIC-003
**Severity:** High
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:860`
**Description:** `handle_return_request()` only allows `status = 'active'` assignments to be return-requested. It does **not** handle the case where an employee has approved the assignment (status is `active`) but the assignment was created with `start_date` in the future — the asset has not yet been physically received but can already be return-requested. This is an edge case in the status machine: `active` semantically means "employee confirmed receipt", so future-dated active assignments are logically inconsistent but possible because `handle_assign_decision()` sets `status = 'active'` immediately on employee approval regardless of `start_date`.
**Fix:** Add `AND start_date <= CURDATE()` to the status check, or validate `start_date <= today` in `handle_assign()`.

---

### ASSET-LOGIC-004
**Severity:** Medium
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:1380–1494`
**Description:** `handle_asset_save()` allows updating `status` via `$_POST['status']` on an existing asset (line 1271, 1354). This means a manager with `sfs_hr.manage` can manually set an asset to `available` even if it has an active assignment, bypassing the assignment lifecycle. The assignment record will still show `active` but the asset record will show `available` — a direct contradiction.
**Fix:** When updating an existing asset, exclude `status` from the update payload if the asset has any active/pending assignments. Only allow manual status override for assets in `archived` or `returned` states.

---

### ASSET-LOGIC-005
**Severity:** Medium
**File:** `includes/Modules/Assets/AssetsModule.php:154–157`
**Description:** `employee_tab()` and `employee_tab_content()` check `current_user_can('sfs_hr_manager')` — this is a WordPress **role** check, not a **capability** check. The correct pattern across the codebase is to check `sfs_hr.manage` capability. A user with the `sfs_hr_manager` role always has this capability, but someone granted `sfs_hr.manage` directly (e.g., via capability override) without the role will not see the tab. Inconsistency with the rest of the module.
**Fix:** Replace `current_user_can('sfs_hr_manager')` with `current_user_can('sfs_hr.manage')` in both `employee_tab()` and `employee_tab_content()`.

---

### ASSET-LOGIC-006
**Severity:** Low
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:252–254` (QR code URL path)
**Description:** `generate_qr_url_for_asset()` generates a QR code whose payload is the WP admin asset-edit URL (`admin_url('admin.php?page=sfs-hr-assets&view=edit&id=...')`). QR codes are physically attached to assets. If an employee scans a QR code with their phone, they are directed to the admin interface which requires admin login — employees cannot log into the admin dashboard. The QR code is useless for non-admin users.
**Fix:** Change the QR payload to a frontend profile URL (e.g., `home_url('/my-profile/?tab=assets&asset_id={$asset_id}')`) or a dedicated asset-detail shortcode URL accessible to employees.

---

## wpdb Call-Accounting Table

All `$wpdb` method calls across the three audited files:

| # | File | Line | Method | Query Summary | Prepared? | Status |
|---|------|------|--------|---------------|-----------|--------|
| 1 | AssetsModule.php | 46 | get_var | SHOW TABLES LIKE assets_table | Yes (`prepare()`) | Safe |
| 2 | AssetsModule.php | 48 | get_var | SHOW TABLES LIKE assign_table | Yes (`prepare()`) | Safe |
| 3 | AssetsModule.php | 74 | get_var | SHOW COLUMNS FROM assign_table LIKE col | Yes (`prepare()`) | Safe |
| 4 | AssetsModule.php | 93 | query | CREATE TABLE IF NOT EXISTS sfs_hr_assets | No (static DDL, no user values) | Safe |
| 5 | AssetsModule.php | 120 | query | CREATE TABLE IF NOT EXISTS sfs_hr_asset_assignments | No (static DDL, no user values) | Safe |
| 6 | AssetsModule.php | 195 | get_results | SELECT assignments + JOIN assets WHERE employee_id | Yes (`prepare()`) | Safe |
| 7 | AssetsModule.php | 311 | get_var | SELECT COUNT(*) assignments WHERE employee_id | Yes (`prepare()`) | Safe |
| 8 | AssetsModule.php | 333 | get_results | SELECT assignments + JOIN assets WHERE employee_id | Yes (`prepare()`) | Safe |
| 9 | AssetsModule.php | 363 | get_var | SELECT COUNT(*) assignments WHERE employee_id | Yes (`prepare()`) | Safe |
| 10 | class-admin-pages.php | 140 | get_results | SELECT * FROM assets with dynamic WHERE | Yes (prepare if values; raw if no values) | Safe |
| 11 | class-admin-pages.php | 141 | get_results | SELECT * FROM assets (no filter case) | No (`$wpdb->get_results($sql)` raw) | Safe (static if no conditions) |
| 12 | class-admin-pages.php | 154 | get_row | SELECT * FROM assets WHERE id | Yes (`prepare()`) | Safe |
| 13 | class-admin-pages.php | 212 | get_results | SELECT assignments + JOIN assets + JOIN employees | Yes (prepare if values; raw if no values) | Safe |
| 14 | class-admin-pages.php | 214 | get_results | SELECT assignments (no filter case) | No (raw if no conditions) | Safe (static if no conditions) |
| 15 | class-admin-pages.php | 308 | get_var | SELECT COUNT(*) WHERE asset_id + status IN | Yes (`prepare()`) | Safe |
| 16 | class-admin-pages.php | 340 | insert | INSERT INTO assignments | Yes (via `$wpdb->insert`) | Safe |
| 17 | class-admin-pages.php | 375 | update | UPDATE assets SET status | Yes (via `$wpdb->update`) | Safe |
| 18 | class-admin-pages.php | 417 | get_row | SELECT name, asset_code FROM assets WHERE id | Yes (`prepare()`) | Safe |
| 19 | class-admin-pages.php | 432 | get_row | SELECT user_id FROM employees WHERE id | Yes (`prepare()`) | Safe |
| 20 | class-admin-pages.php | 511 | get_row | SELECT * FROM assignments WHERE id | Yes (`prepare()`) | Safe |
| 21 | class-admin-pages.php | 528 | get_row | SELECT * FROM employees WHERE user_id | Yes (`prepare()`) | Safe |
| 22 | class-admin-pages.php | 555 | get_row | SELECT name, asset_code FROM assets WHERE id | Yes (`prepare()`) | Safe |
| 23 | class-admin-pages.php | 579 | get_var | SELECT COUNT(*) WHERE asset_id AND id <> ... | Yes (`prepare()`) | Safe |
| 24 | class-admin-pages.php | 623 | get_col | SHOW COLUMNS FROM assign_table | **No** (raw) | Flagged (ASSET-SEC-003) |
| 25 | class-admin-pages.php | 646 | update | UPDATE assignments SET status+photos | Yes (via `$wpdb->update`) | Safe |
| 26 | class-admin-pages.php | 656 | update | UPDATE assignments SET status (fallback) | Yes (via `$wpdb->update`) | Safe |
| 27 | class-admin-pages.php | 669 | update | UPDATE assets SET status=assigned | Yes (via `$wpdb->update`) | Safe |
| 28 | class-admin-pages.php | 739 | update | UPDATE assignments SET status=rejected | Yes (via `$wpdb->update`) | Safe |
| 29 | class-admin-pages.php | 751 | update | UPDATE assets SET status=available | Yes (via `$wpdb->update`) | Safe |
| 30 | class-admin-pages.php | 849 | get_row | SELECT * FROM assignments WHERE id | Yes (`prepare()`) | Safe |
| 31 | class-admin-pages.php | 868 | update | UPDATE assignments SET return_requested | Yes (via `$wpdb->update`) | Safe |
| 32 | class-admin-pages.php | 889 | get_row | SELECT user_id FROM employees WHERE id | Yes (`prepare()`) | Safe |
| 33 | class-admin-pages.php | 906 | get_row | SELECT name, asset_code FROM assets WHERE id | Yes (`prepare()`) | Safe |
| 34 | class-admin-pages.php | 1024 | get_row | SELECT * FROM assignments WHERE id | Yes (`prepare()`) | Safe |
| 35 | class-admin-pages.php | 1045 | get_row | SELECT * FROM employees WHERE user_id | Yes (`prepare()`) | Safe |
| 36 | class-admin-pages.php | 1072 | get_row | SELECT name, asset_code FROM assets WHERE id | Yes (`prepare()`) | Safe |
| 37 | class-admin-pages.php | 1108 | get_col | SHOW COLUMNS FROM assign_table | **No** (raw) | Flagged (ASSET-SEC-003) |
| 38 | class-admin-pages.php | 1145 | update | UPDATE assignments SET returned+photos | Yes (via `$wpdb->update`) | Safe |
| 39 | class-admin-pages.php | 1163 | update | UPDATE assets SET status=available | Yes (via `$wpdb->update`) | Safe |
| 40 | class-admin-pages.php | 1291 | get_var | SHOW TABLES LIKE assets_table | Yes (`prepare()`) | Safe |
| 41 | class-admin-pages.php | 1306 | get_row | SELECT * FROM assets WHERE id | Yes (`prepare()`) | Safe |
| 42 | class-admin-pages.php | 1381 | update | UPDATE assets (save) | Yes (via `$wpdb->update`) | Safe |
| 43 | class-admin-pages.php | 1424 | insert | INSERT INTO assets (create) | Yes (via `$wpdb->insert`) | Safe |
| 44 | class-admin-pages.php | 1469 | update | UPDATE assets SET qr_code_path | Yes (via `$wpdb->update`) | Safe |
| 45 | class-admin-pages.php | 1507 | get_results | SELECT * FROM assets (export, no LIMIT) | **No** (raw, no user values) | Flagged (ASSET-SEC-001) |
| 46 | class-admin-pages.php | 1670 | get_var | SELECT id FROM assets WHERE asset_code (import loop) | Yes (`prepare()`) | Safe |
| 47 | class-admin-pages.php | 1680 | update | UPDATE assets (import, no format array) | No explicit format | Flagged (ASSET-SEC-006) |
| 48 | class-admin-pages.php | 1690 | insert | INSERT INTO assets (import, no format array) | No explicit format | Flagged (ASSET-SEC-006) |
| 49 | class-admin-pages.php | 1735 | get_var | SELECT COUNT(*) assignments WHERE asset_id | Yes (`prepare()`) | Safe |
| 50 | class-admin-pages.php | 1754 | delete | DELETE FROM assets WHERE id | Yes (via `$wpdb->delete`) | Safe |
| 51 | class-admin-pages.php | 1781 | get_results | SELECT category, COUNT(*) GROUP BY category | **No** (raw) | Flagged (ASSET-PERF-003) |
| 52 | class-admin-pages.php | 1908 | get_var | SELECT COUNT(*) WHERE category = | Yes (`prepare()`) | Safe |
| 53 | class-admin-pages.php | 1974 | get_var | SELECT COUNT(*) FROM information_schema.tables | Yes (`prepare()`) | Flagged (ASSET-SEC-003) |
| 54 | class-admin-pages.php | 1991 | insert | INSERT INTO asset_logs | Yes (via `$wpdb->insert`) | Safe |
| 55 | class-admin-pages.php | 2086 | get_col | SHOW COLUMNS FROM assign_table | **No** (raw) | Flagged (ASSET-SEC-003) |

**Total wpdb calls: 55**
- Prepared / safe: 49
- Flagged: 6 (lines 623, 1108, 1507, 1680, 1690, 1781, 1974, 2086)

---

## Module Bootstrap Assessment (vs. Prior Phase Antipatterns)

| Check | Status | Notes |
|-------|--------|-------|
| Bare `ALTER TABLE` in bootstrap | **Clean** | `maybe_upgrade_db()` uses `CREATE TABLE IF NOT EXISTS` — no bare ALTER TABLE |
| Unprepared `SHOW TABLES` in bootstrap | **Clean** | Lines 46/48 use `$wpdb->prepare("SHOW TABLES LIKE %s", ...)` — correct pattern |
| `__return_true` REST callbacks | **N/A** | `class-assets-rest.php` registers no routes — stub only |
| REST stub security | **Clean** | Comment explicitly explains routes not registered to avoid empty public endpoints |
| information_schema in hot path | **Flagged** | `log_asset_event()` uses information_schema on every event (ASSET-SEC-003) |
| Audit log table never provisioned | **Flagged** | `sfs_hr_asset_logs` is referenced but never created by `maybe_upgrade_db()` (ASSET-LOGIC-002) |

---

## Key Observations

**Positive findings:**
1. AssetsModule.php bootstrap is significantly cleaner than Loans (Phase 08) and Core (Phase 04) — SHOW TABLES uses `prepare()`, no bare ALTER TABLE.
2. The REST file deliberately registers no routes (not a `__return_true` stub), which is the right choice.
3. Assignment ownership verification is correctly implemented in `handle_assign_decision()` and `handle_return_decision()` — the employee identity check at lines 539 and 1059 is sound.
4. Double-assignment prevention exists at both the manager-create step and the employee-approve step (defense in depth).
5. All write handlers use `check_admin_referer()` or `check_admin_referer()` with unique nonce actions.
6. `handle_asset_delete()` correctly blocks deletion of assets with active assignments.

**Patterns of concern matching prior phases:**
- ASSET-SEC-003: `information_schema` query in `log_asset_event()` — same antipattern as Phase 04 Critical
- ASSET-DUP-001: Dual asset status tracking without transaction safety — architectural risk
- ASSET-LOGIC-001: TOCTOU race in double-assignment guard — same class as Phase 06 `has_overlap()` finding
