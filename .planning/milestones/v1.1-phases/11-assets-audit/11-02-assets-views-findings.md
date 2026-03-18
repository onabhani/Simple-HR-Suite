# Phase 11 Plan 02: Assets View Templates Audit Findings

**Date:** 2026-03-16
**Scope:** `includes/Modules/Assets/Views/assets-edit.php` (385 lines), `includes/Modules/Assets/Views/assets-list.php` (632 lines), `includes/Modules/Assets/Views/assignments-list.php` (447 lines)
**Auditor:** Automated code review (Claude)

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 0 | 2 | 2 | 1 | 5 |
| Performance | 0 | 1 | 1 | 1 | 3 |
| Duplication | 0 | 0 | 2 | 1 | 3 |
| Logical | 0 | 1 | 2 | 2 | 5 |
| **Total** | **0** | **4** | **7** | **5** | **16** |

---

## Security Findings

### AVIEW-SEC-001
**Severity:** High
**File:** `includes/Modules/Assets/Views/assignments-list.php:34-82`
**Category:** Inline SQL — raw queries without `$wpdb->prepare()`

The view file contains five direct `$wpdb` queries issued without `prepare()`:

- Line 34–40: `$wpdb->get_results("SELECT id, name, asset_code, category, status FROM {$assets_table} ...")` — static, no user input.
- Line 43–50: `$wpdb->get_results("SELECT employee_id, COUNT(*) ... FROM {$assign_table} ...")` — static.
- Line 60–82: Employee list query with a dynamically constructed `$where` clause. The `$in` fragment is assembled from `array_map('intval', $manager_depts)` and injected directly into the SQL string (line 65–67). The final `$employees_sql` variable is passed to `$wpdb->get_results()` without `prepare()` (line 82).

The integer casting on `$manager_depts` makes this safe from injection in practice, but it violates the project's prepared-statement mandate and sets a pattern that is unsafe if the guard is ever weakened.

Additionally, all five queries belong in the controller (`render_assignments_list()` in `class-admin-pages.php`), not in the view. Views should only render data handed to them by the controller. The mix of data-fetching and rendering in one file also makes the view impossible to unit test in isolation.

**Fix:** Move all five queries to `Admin_Pages::render_assignments_list()`. Use `$wpdb->prepare()` for any query with runtime values, including the `IN (...)` clause (use `implode(',', array_fill(0, count($ids), '%d'))` pattern consistent with `assets-list.php`).

---

### AVIEW-SEC-002
**Severity:** High
**File:** `includes/Modules/Assets/Views/assets-edit.php:56,59-64`
**Category:** Inline SQL — raw queries without `$wpdb->prepare()`

Two `$wpdb->get_col()` calls issue raw SQL strings:

- Line 56: `$wpdb->get_col("SELECT name FROM {$dept_table} ORDER BY name ASC")` — static.
- Line 59–64: `$wpdb->get_col("SELECT DISTINCT department FROM {$employees_table} WHERE department <> '' ORDER BY department ASC")` — static.

Both are static (no user-supplied values) and not vulnerable to injection. However, they are in the view file rather than the controller, which is an architectural violation consistent with AVIEW-SEC-001. The `SHOW TABLES LIKE %s` at line 51–53 is correctly prepared.

**Fix:** Move both queries to `Admin_Pages::render_asset_edit()`. Pass the resulting `$departments` array into the view via a local variable, keeping the view data-free.

---

### AVIEW-SEC-003
**Severity:** Medium
**File:** `includes/Modules/Assets/Views/assets-edit.php:327-381`
**Category:** Inline SQL — view contains assignment history query

Lines 332–347 issue a `$wpdb->prepare()` query for assignment history directly inside the view. This query is correctly prepared (uses `%d` placeholder for `$id`), so there is no injection risk. However, the view is now responsible for fetching its own data, violating the controller/view separation architecture used consistently in other modules (Settlement views, for example, contain zero direct `$wpdb` calls).

**Fix:** Move the assignment history query to `render_asset_edit()` and pass the `$history` array into the view.

---

### AVIEW-SEC-004
**Severity:** Medium
**File:** `includes/Modules/Assets/Views/assets-edit.php:231`
**Category:** File upload — missing `accept` attribute on invoice file input

The invoice file input field at line 231 is:
```html
<input type="file" name="invoice_file" id="invoice_file" />
```
No `accept` attribute restricts the MIME type hint in the browser. From Phase 11-01 findings, `save_data_url_attachment()` in the Assets service has no MIME allowlist server-side (ASSET-SEC-002). The absence of a client-side `accept` attribute removes the browser-level defence-in-depth signal and makes it easier to accidentally submit an unsupported file type.

**Fix:** Add `accept=".pdf,.jpg,.jpeg,.png"` (or whatever the server-side policy is once ASSET-SEC-002 is fixed). The fix is low-impact (one attribute) but should be coordinated with the server-side MIME check fix.

---

### AVIEW-SEC-005
**Severity:** Low
**File:** `includes/Modules/Assets/Views/assignments-list.php:162,224`
**Category:** Unescaped attribute output (integer)

Lines 162 and 224 emit `echo $emp_id;` directly inside `<option value="...">` without `esc_attr()`:

```php
// line 162
<option value="<?php echo $emp_id; ?>">

// line 224
<option value="<?php echo $emp_id; ?>" ...>
```

`$emp_id` is always `(int)` cast, so in practice no injection is possible. However, the project convention requires `esc_attr()` for all attribute contexts. This creates an inconsistency with the rest of the codebase and could confuse future developers reviewing output escaping.

**Fix:** Replace `echo $emp_id` with `echo (int) $emp_id` or `echo esc_attr( $emp_id )` at both locations.

---

## Performance Findings

### AVIEW-PERF-001
**Severity:** High
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:137` (affects `assets-list.php`)
**Category:** Unbounded query — hard LIMIT 200 with in-memory PHP filtering

The controller `render_asset_list()` fetches up to 200 assets with `LIMIT 200` regardless of applied filters. The view `assets-list.php` then re-filters those 200 rows in PHP (lines 31–42) based on `$_GET` status/category/dept parameters. This means:

1. If the total asset inventory exceeds 200, filters that should return all matching assets will silently miss records beyond position 200 in the table.
2. The query fetches potentially hundreds of rows even when the user filters down to a handful.
3. There is no pagination — the entire filtered result set (up to 200 rows) renders as one HTML table.

For organizations with large inventories (>200 assets), records will silently disappear from filtered views without any warning to the user.

**Fix:** Push all filters down to the SQL `WHERE` clause in `render_asset_list()` (already partially done for status/category/dept). Remove the PHP-side filter loop in the view. Replace the hard `LIMIT 200` with paginated queries using `LIMIT {$per_page} OFFSET {$offset}` and render WordPress-style pagination links via `paginate_links()`.

---

### AVIEW-PERF-002
**Severity:** Medium
**File:** `includes/Modules/Assets/Admin/class-admin-pages.php:209` (affects `assignments-list.php`)
**Category:** Unbounded query — hard LIMIT 200, no pagination

`render_assignments_list()` fetches at most 200 assignments with `ORDER BY a.created_at DESC LIMIT 200` and renders them all in a single table. Organisations that accumulate many assignments (high-turnover environments) will silently lose older records from the view.

**Fix:** Same pattern as AVIEW-PERF-001 — add `LIMIT {$per_page} OFFSET {$offset}` and render pagination controls.

---

### AVIEW-PERF-003
**Severity:** Low
**File:** `includes/Modules/Assets/Views/assignments-list.php:354-410`
**Category:** Per-row `wp_get_attachment_url()` calls

In the Photos column (lines 354–410), up to four calls to `wp_get_attachment_url()` are made per assignment row. `wp_get_attachment_url()` issues a `$wpdb->get_var()` query unless the attachment post is in the object cache. For a table of 200 assignments each with four photos, this is up to 800 additional queries per page load if the object cache is cold.

**Fix:** Collect all attachment IDs from the rows before rendering. Use `get_posts(['include' => $attachment_ids, 'post_type' => 'attachment'])` or a single batch query to prime the object cache before the table loop, reducing attachment URL lookups to cache hits.

---

## Duplication Findings

### AVIEW-DUP-001
**Severity:** Medium
**File:** `includes/Modules/Assets/Views/assets-list.php` and `includes/Modules/Assets/Views/assignments-list.php`
**Category:** Duplicated employee name rendering logic

Both view files contain nearly identical logic for constructing a display name from `first_name`/`last_name` with a `'#' . $id` fallback:

`assets-list.php` lines 579–584:
```php
$assignee_name = trim( $assignee->first_name . ' ' . $assignee->last_name );
if ( $assignee_name === '' ) {
    $assignee_name = '#' . (int) $assignee->employee_id;
}
```

`assignments-list.php` lines 139–143 and 217–221:
```php
$name = trim( ( $emp->first_name ?? '' ) . ' ' . ( $emp->last_name ?? '' ) );
if ( $name === '' ) { $name = '#' . $emp_id; }
```

`assets-edit.php` lines 339–340 handles this in SQL via `TRIM(CONCAT(...))` for the history table.

The same pattern also exists in `class-admin-pages.php` line 198–204 (SQL CONCAT version).

**Fix:** Centralise into `Helpers::employee_display_name( $first, $last, $id )` — a static helper that returns the formatted string. All four locations become a single call. This is a Low-risk refactor: no behavior change, just extraction.

---

### AVIEW-DUP-002
**Severity:** Medium
**File:** `includes/Modules/Assets/Views/assets-list.php:588-589` and `includes/Modules/Assets/Views/assignments-list.php:320`
**Category:** Duplicated status rendering — ucfirst/str_replace vs. Helpers::asset_status_badge()

`assets-list.php` renders the asset status badge using a manual inline approach:
```php
$status_label = ucfirst( str_replace( '_', ' ', $row->status ) );
// then:
<span class="sfs-hr-asset-status status-<?php echo esc_attr( $status_class ); ?>">
    <?php echo esc_html( $status_label ); ?>
</span>
```

`assignments-list.php` renders the assignment status badge using the centralised helper:
```php
echo \SFS\HR\Core\Helpers::asset_status_badge( (string) $row->status );
```

`assets-edit.php` (history table, line 375) also uses `Helpers::asset_status_badge()`.

The two approaches diverge on what CSS classes and labels appear. If `Helpers::asset_status_badge()` is updated (e.g., to add a new status), `assets-list.php` will not reflect the change automatically.

**Fix:** Replace the manual inline badge in `assets-list.php` with `Helpers::asset_status_badge()` to unify all status badge rendering.

---

### AVIEW-DUP-003
**Severity:** Low
**File:** `includes/Modules/Assets/Views/assets-list.php:98-391` (inline CSS block)
**Category:** Inline CSS — 293-line style block embedded in view

`assets-list.php` contains a `<style>` block spanning lines 98–391 with all page-specific CSS. This is normal for WordPress admin pages but creates maintenance overhead: the same status badge classes (`.status-available`, `.status-assigned`, etc.) are defined here but also used in `assignments-list.php` which calls `Helpers::output_asset_status_badge_css()` to emit them. The badge CSS is defined in two locations.

**Fix:** Enqueue a dedicated `assets-admin.css` stylesheet via `wp_enqueue_style()` in `Admin_Pages::hooks()` for the assets admin page. Remove the inline `<style>` block. This also ensures the CSS is minifiable and cacheable.

---

## Logical Findings

### AVIEW-LOGIC-001
**Severity:** High
**File:** `includes/Modules/Assets/Views/assignments-list.php:57-82`
**Category:** Assignment list ownership scoping — manager sees own-dept employees in form, but all-org assignments in list

The view correctly scopes the **assignment form** employee dropdown to the manager's departments (lines 17–31, 57–68). However, the `$rows` variable containing the assignments list is passed from `render_assignments_list()` in the controller, which applies **no department scoping** — it fetches all assignments for all employees regardless of the requesting manager's scope (lines 176–215 in `class-admin-pages.php`).

This means a department manager can:
1. Only assign assets to employees in their own department (correct).
2. But see the full assignment history of employees from **all departments** in the list below (incorrect).

The inconsistency exposes cross-department assignment data (employee names, asset names, assignment dates, photos) to managers who should not have visibility into other departments.

**Fix:** In `render_assignments_list()`, when `! $can_manage_all`, apply the same department scoping to the assignments query as is applied to the employees query. Add a JOIN to `sfs_hr_employees` and filter by `e.dept_id IN (...)` using the manager's `$manager_depts` array. Use `$wpdb->prepare()` with the `array_fill` placeholder pattern.

---

### AVIEW-LOGIC-002
**Severity:** Medium
**File:** `includes/Modules/Assets/Views/assets-edit.php:299-304`
**Category:** Delete button shown without capability check

The delete button is rendered inside the edit form at lines 299–304:
```php
<?php if ( $is_edit && $id > 0 ) : ?>
    <a href="#" onclick="..." class="button button-link-delete">
        <?php esc_html_e('Delete Asset', 'sfs-hr'); ?>
    </a>
<?php endif; ?>
```

There is no `current_user_can()` guard here. The containing admin menu (`'sfs_hr.manage'`) is the only gate. If a future role is added with `sfs_hr.manage` but without delete privileges, or if a read-only manager role gains access to asset edit pages, the delete button will be visible to users who cannot complete the action (the handler does verify capability). This is the same pattern identified as SADM-SEC-001 in Phase 10 (Settlement approve/reject buttons shown to all page viewers), though lower severity here because the page itself requires `sfs_hr.manage`.

**Fix:** Wrap the delete button and delete form in `if ( current_user_can('sfs_hr.manage') ) :` to make the capability check explicit and forward-compatible.

---

### AVIEW-LOGIC-003
**Severity:** Medium
**File:** `includes/Modules/Assets/Views/assets-list.php:25-42`
**Category:** Client-side filter applied after server-side LIMIT — silent data loss

See also AVIEW-PERF-001. The view re-filters `$rows` in PHP based on GET parameters. Because the controller query uses `LIMIT 200` before filtering, the PHP filter operates on at most 200 rows. If the unfiltered set has 250 assets and a department filter matches assets 180–220, only assets 180–200 (21 records) will appear; assets 201–220 are never fetched and silently absent from the filtered result.

This is a logical correctness issue independent of the performance concern: filtered views show **fewer results than exist** without any indication to the user.

**Fix:** Remove the PHP-side filter loop. Push all filters into the SQL `WHERE` clause in the controller (already partially in place — just needs `LIMIT` removed or turned into pagination).

---

### AVIEW-LOGIC-004
**Severity:** Low
**File:** `includes/Modules/Assets/Views/assignments-list.php:275`
**Category:** Empty state uses `$rows` not `$display_rows`

The empty-state check at line 275 is:
```php
<?php if ( empty( $rows ) ) : ?>
```

This is the raw `$rows` from the controller, before any PHP-side filtering. (In `assignments-list.php` there is no PHP-side filter loop, so this is equivalent to checking the filtered set — the status and employee filters are applied in SQL by the controller.) This is actually correct, but the variable name is ambiguous given the pattern in `assets-list.php` where `$display_rows` is the filtered set and `$rows` is unfiltered. A future developer may confuse the semantics.

**Fix:** Low priority — rename `$rows` in the assignments view to `$display_rows` for consistency with `assets-list.php`, or add a comment clarifying that `$rows` is already controller-filtered.

---

### AVIEW-LOGIC-005
**Severity:** Low
**File:** `includes/Modules/Assets/Views/assets-edit.php:190`
**Category:** `date('Y')` instead of `wp_date('Y')` for max year boundary

The purchase year field's `max` attribute uses PHP's `date()` function:
```php
max="<?php echo esc_attr( date('Y') + 1 ); ?>"
```

`date()` returns server time in the server's PHP timezone, which may differ from the WordPress site timezone set in Settings > General. WordPress provides `wp_date()` (since WP 5.3) and `current_time()` for timezone-correct date output. This is Low severity because a one-year boundary difference is unlikely to cause practical problems, but it is inconsistent with the rest of the plugin which uses `current_time()`.

**Fix:** Replace `date('Y')` with `(int) current_time('Y')` or `(int) wp_date('Y')` for consistency.

---

## Output Escaping Audit Table

All dynamic value outputs in the three view files, evaluated against project escaping conventions.

| File | Line(s) | Variable / Expression | Escaping Used | Status |
|------|---------|-----------------------|---------------|--------|
| assets-edit.php | 70 | `esc_html__('Edit Asset'...)` / `esc_html__('Add New Asset'...)` | `esc_html__()` | Safe |
| assets-edit.php | 74 | `admin_url('admin-post.php')` | `esc_url()` | Safe |
| assets-edit.php | 78 | `(int) $id` | integer cast | Safe |
| assets-edit.php | 87 | `$asset_code` | `esc_attr()` | Safe |
| assets-edit.php | 101, 105 | `$qr_code_path` | `esc_url()` | Safe |
| assets-edit.php | 119 | `$name` | `esc_attr()` | Safe |
| assets-edit.php | 132-134 | `$key`, `$label` from `$category_options` | `esc_attr()` / `esc_html()` | Safe |
| assets-edit.php | 152-154 | `$dept` from `$departments` | `esc_attr()` / `esc_html()` | Safe |
| assets-edit.php | 166 | `$serial_number` | `esc_attr()` | Safe |
| assets-edit.php | 177 | `$model` | `esc_attr()` | Safe |
| assets-edit.php | 189 | `$purchase_year` | `esc_attr()` | Safe |
| assets-edit.php | 190 | `date('Y') + 1` | `esc_attr()` | Safe (but uses `date()` not `current_time()` — see AVIEW-LOGIC-005) |
| assets-edit.php | 199 | `$purchase_price` | `esc_attr()` | Safe |
| assets-edit.php | 210 | `$invoice_number` | `esc_attr()` | Safe |
| assets-edit.php | 222 | `$invoice_date` | `esc_attr()` | Safe |
| assets-edit.php | 235 | `$invoice_file` | `esc_url()` | Safe |
| assets-edit.php | 249 | `$warranty_expiry` | `esc_attr()` | Safe |
| assets-edit.php | 260-261, 274-275 | `$key` (status/condition keys) | `esc_attr()` | Safe |
| assets-edit.php | 263, 277 | `$label` (status/condition labels) | `esc_html()` | Safe |
| assets-edit.php | 291 | `$notes` | `esc_textarea()` | Safe |
| assets-edit.php | 316 | `(int) $id` | integer cast | Safe |
| assets-edit.php | 375 | `$h->status` passed to `Helpers::asset_status_badge()` | Internal escaping in helper | Safe (helper uses `esc_attr()` internally per Phase 11-01 audit) |
| assets-edit.php | 366 | `$h->employee_name` | `esc_html()` | Safe |
| assets-edit.php | 367 | `$h->employee_code` | `esc_html()` | Safe |
| assets-edit.php | 368 | `$h->start_date` | `esc_html()` | Safe |
| assets-edit.php | 372 | `$end` (`return_date` or `end_date`) | `esc_html()` | Safe |
| assets-edit.php | 376 | `$h->notes` | `esc_html()` | Safe |
| assets-list.php | 478 | `$_GET['page']` | `sanitize_text_field()` + `esc_attr()` | Safe |
| assets-list.php | 483 | `$_GET['tab']` | `sanitize_text_field()` + `esc_attr()` | Safe |
| assets-list.php | 491 | `$status_key` | `esc_attr()` | Safe |
| assets-list.php | 492 | `ucfirst(str_replace('_',' ',$status_key))` | `esc_html()` | Safe |
| assets-list.php | 503 | `$cat` | `esc_attr()` / `esc_html()` | Safe |
| assets-list.php | 515 | `$dept` (from DB) | `esc_attr()` / `esc_html()` | Safe |
| assets-list.php | 530 | `add_query_arg(...)` for Add New Asset | `esc_url()` | Safe |
| assets-list.php | 555 | `(int)$total_count` | integer cast | Safe |
| assets-list.php | 592, 597 | `$row->asset_code` | `esc_html()` | Safe |
| assets-list.php | 594-595 | `$edit_url` / `$row->name` | `esc_url()` / `esc_html()` | Safe |
| assets-list.php | 599 | `$row->category` | `esc_html()` | Safe |
| assets-list.php | 600 | `$row->department` | `esc_html()` | Safe |
| assets-list.php | 611 | `$profile_url` / `$assignee_name` | `esc_url()` / `esc_html()` | Safe |
| assets-list.php | 618 | `$status_class` | `esc_attr()` | Safe |
| assets-list.php | 619 | `$status_label` | `esc_html()` | Safe |
| assets-list.php | 622 | `$condition_label` | `esc_html()` | Safe |
| assets-list.php | 624 | `$row->name`, `$row->asset_code`, `$row->category`, `$row->department`, `$assignee_name`, `$status_label`, `$condition_label`, `$edit_url` in onclick | `esc_js()` for all | Safe |
| assignments-list.php | 91 | `admin_url('admin-post.php')` | `esc_url()` | Safe |
| assignments-list.php | 104 | `(int) $asset->id` in option value | integer cast | Safe |
| assignments-list.php | 120 | `implode(' – ', $parts)` (`$asset->name`, `$asset->asset_code`, `$asset->category`) | `esc_html()` | Safe |
| assignments-list.php | 162 | `$emp_id` (integer) in option value | `echo $emp_id` — no `esc_attr()` | **Flagged (Low)** — see AVIEW-SEC-005 |
| assignments-list.php | 163 | `$label` (name, dept, count string) | `esc_html()` | Safe |
| assignments-list.php | 185 | `current_time('Y-m-d')` for start_date default | `esc_attr()` | Safe |
| assignments-list.php | 224 | `$emp_id` (integer) in option value | `echo $emp_id` — no `esc_attr()` | **Flagged (Low)** — see AVIEW-SEC-005 |
| assignments-list.php | 225 | `$name` (employee name) | `esc_html()` | Safe |
| assignments-list.php | 243 | `$key` (status key) | `esc_attr()` | Safe |
| assignments-list.php | 244 | `$label` (status label) | `esc_html()` | Safe |
| assignments-list.php | 287-289 | `$row->asset_name` / `$row->asset_code` in printf() | `esc_html()` on both args | Safe |
| assignments-list.php | 308-310 | `$profile_url` / `$label` (employee name) | `esc_url()` / `esc_html()` | Safe |
| assignments-list.php | 312-313 | `$row->employee_name` (fallback) | `esc_html()` | Safe |
| assignments-list.php | 317 | `$row->start_date` | `esc_html()` | Safe |
| assignments-list.php | 318 | `$row->end_date` | `esc_html()` | Safe |
| assignments-list.php | 320 | `$row->status` via `Helpers::asset_status_badge()` | Internal escaping in helper | Safe |
| assignments-list.php | 358-360 | `$selfie_full` / alt text in sprintf() | `esc_url()` / `esc_attr__()` | Safe |
| assignments-list.php | 369-371 | `$asset_full` / alt text in sprintf() | `esc_url()` / `esc_attr__()` | Safe |
| assignments-list.php | 388-390 | `$return_selfie_full` / alt text | `esc_url()` / `esc_attr__()` | Safe |
| assignments-list.php | 399-401 | `$return_asset_full` / alt text | `esc_url()` / `esc_attr__()` | Safe |
| assignments-list.php | 418 | `$row->created_at` | `esc_html()` | Safe |
| assignments-list.php | 430 | `admin_url('admin-post.php')` | `esc_url()` | Safe |
| assignments-list.php | 434 | `(int) $row->id` in nonce action | integer cast | Safe |
| assignments-list.php | 434 | `(int) $row->id` in hidden input | integer cast | Safe |

**Summary:** 2 flagged outputs (both Low severity, both integer-cast `$emp_id` values rendered without `esc_attr()`). No High/Critical unescaped outputs found. The escaping discipline across all three view files is generally good, with the main risk being the inline SQL queries (AVIEW-SEC-001 through AVIEW-SEC-003) rather than XSS.

---

## Notes on Prior Audit Cross-References

- **ASSET-SEC-002** (Phase 11-01): Invoice upload MIME allowlist missing in service. The edit form has `enctype="multipart/form-data"` and the file input is present; AVIEW-SEC-004 above documents the missing `accept` attribute as the corresponding view-layer gap.
- **ASSET-SEC-004/005** (Phase 11-01): `handle_assign_decision` and `handle_return_decision` use `is_user_logged_in()` only. The assignments-list view correctly gates the "Request Return" button behind capability checks (lines 421–427), providing the correct UI signal even though the handlers themselves are insufficiently gated at the server side.
- **SADM-SEC-001** (Phase 10-02): Settlement approve/reject buttons shown to all page viewers. The Assets assignments view partially avoids this pattern — the "Request Return" button is gated. However, the assignment form (top of `assignments-list.php`) that creates new assignments is rendered for ALL users who reach the page (any user with `sfs_hr.manage` or `sfs_hr_manager`), and there is no `current_user_can()` check before the `<form>`. The handler `handle_assign()` correctly checks `sfs_hr.manage` or `sfs_hr_assets_admin`, but a plain `sfs_hr_manager` who reaches the assignments tab will see a form they cannot submit. This is a UI consistency issue (Low) rather than a security gap.
