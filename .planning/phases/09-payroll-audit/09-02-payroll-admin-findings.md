# Phase 09 Plan 02: Payroll Admin Pages Audit Findings

**File audited:** `includes/Modules/Payroll/Admin/Admin_Pages.php` (2,576 lines)

**Date:** 2026-03-16

---

## Summary Table

| Category    | Critical | High | Medium | Low | Total |
|-------------|----------|------|--------|-----|-------|
| Security    | 1        | 3    | 3      | 1   | 8     |
| Performance | 0        | 2    | 2      | 1   | 5     |
| Duplication | 0        | 1    | 3      | 1   | 5     |
| Logical     | 0        | 2    | 3      | 1   | 6     |
| **Total**   | **1**    | **8**| **11** | **4** | **24** |

---

## Security Findings

### PADM-SEC-001 — Critical
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1946`
**Issue:** `handle_export_attendance()` uses `sfs_hr.view` as its capability gate — the same capability granted to every employee via the dynamic `user_has_cap` filter. Any active employee can trigger an attendance export that returns the full attendance record for **all active employees** in the organization, including names, departments, job titles, daily presence/absence/overtime, and work hours.

```php
public function handle_export_attendance(): void {
    if ( ! current_user_can( 'sfs_hr.view' ) ) {    // Line 1946 — ALL employees pass this
        wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
    }
```

The resulting query (line 1981–2006) performs a full GROUP BY on `sfs_hr_attendance_sessions` joined with `sfs_hr_employees` and `sfs_hr_departments`, returning one row per active employee with no department scoping. A regular employee accessing the export form at `admin.php?page=sfs-hr-payroll&payroll_tab=export` and submitting it receives every colleague's attendance data.

**Fix:** Raise the capability gate for attendance export to match the other export handlers:
```php
if ( ! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) ) {
    wp_die( esc_html__( 'Access denied', 'sfs-hr' ) );
}
```

---

### PADM-SEC-002 — High
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:134–246` (render_overview)
**Issue:** Four `$wpdb` queries in `render_overview()` are raw, unprepared queries — no dynamic user-controlled values, but the pattern violates project conventions and creates a copy-paste injection risk. More critically, the overview is rendered inside `render_hub()` which checks only `sfs_hr.view` (line 75), meaning any employee who can reach the Payroll admin page sees aggregate financial data: total employee count, total payroll periods, and **the net total of the last approved payroll run** including employee count.

```php
// Lines 143–161 — no $wpdb->prepare()
$current_period = $wpdb->get_row(
    "SELECT * FROM {$periods_table} WHERE status IN ('open','processing') ORDER BY start_date DESC LIMIT 1"
);
// ...
$last_run = $wpdb->get_row(
    "SELECT * FROM {$runs_table} WHERE status IN ('approved','paid') ORDER BY approved_at DESC LIMIT 1"
);
```

The `$last_run->total_net` and `$last_run->employee_count` values are displayed to all users with `sfs_hr.view`. An employee learning the total salary budget for the organization from the overview card constitutes information disclosure.

**Fix:** Wrap all static queries in `$wpdb->prepare()` (use `%d` / `%s` placeholders even for static values per project convention). Restrict the overview's aggregate payroll stats (total_net, employee_count of last run) to `sfs_hr.manage` or `sfs_hr_payroll_admin`:
```php
<?php if ( current_user_can( 'sfs_hr.manage' ) || current_user_can( 'sfs_hr_payroll_admin' ) ): ?>
    <!-- Last Payroll Net stat card -->
<?php endif; ?>
```

---

### PADM-SEC-003 — High
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1099–1108`
**Issue:** Admin payslips listing has an unbounded `LIMIT 100` query with no `$wpdb->prepare()` wrapper for the admin branch.

```php
if ( $is_admin ) {
    $payslips = $wpdb->get_results(
        "SELECT ps.*, p.name as period_name, e.first_name, e.last_name, e.employee_code,
                i.net_salary
         FROM {$payslips_table} ps
         LEFT JOIN {$periods_table} p ON p.id = ps.period_id
         LEFT JOIN {$emp_table} e ON e.id = ps.employee_id
         LEFT JOIN {$items_table} i ON i.id = ps.payroll_item_id
         ORDER BY ps.created_at DESC
         LIMIT 100"    // Line 1107 — raw query, no prepare()
    );
```

While there are no user-controlled variables in this specific query (no injection risk here), the absence of `$wpdb->prepare()` is a pattern violation and makes it easy to introduce injection if a filter is added. The `LIMIT 100` cap means a payroll admin can see salary (net_salary) for 100 payslips per page load with no department scoping — any admin-role user can view all employees' net salaries. If the payroll admin role is granted to department-level HR users, this is a cross-department data leakage path.

**Fix:** Wrap in `$wpdb->prepare()`. Add department-scoping for non-`sfs_hr.manage` payroll admins. Add pagination (see PADM-PERF-003).

---

### PADM-SEC-004 — High
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:2131–2178`
**Issue:** The WPS SIF export output uses `echo` directly for raw field values without any sanitization or output escaping, and uses PHP `date()` instead of `wp_date()`:

```php
echo 'EDR';
echo str_pad( $emp_id ?? '', 15 );          // national_id or employee_code — unescaped
echo str_pad( $item->iban ?? '', 24 );       // IBAN — unescaped
echo str_pad( date( 'Ymd', ... ), 8 );       // PHP date() not wp_date()
```

For a text file format, raw `echo` is appropriate. However, `$emp_id` is derived from `$item->national_id ?: $item->employee_code ?: ''` where both values come directly from the DB without any format validation. A national ID or employee code containing newlines (`\n`) would break the SIF record structure (SIF is line-based — extra newlines corrupt the file). This is an injection-into-output-format issue, not an HTML XSS issue.

Additionally, the header value `$employer_code` comes from `get_option( 'sfs_hr_employer_code', ... )` at line 2117 and is written directly into the SIF header without length/format validation. An employer code with spaces or special chars would produce an invalid SIF file.

**Fix:** Sanitize IDs and codes for SIF output:
```php
$emp_id = preg_replace( '/[^A-Z0-9\-]/', '', strtoupper( $emp_id ) );
$iban   = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $item->iban ?? '' ) );
```
Replace `date()` with `wp_date()` for timezone consistency.

---

### PADM-SEC-005 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:840–848`
**Issue:** Query parameter `$_GET['comp_error']` is decoded and output in a notice with `esc_html()` — that part is safe. However, the same pattern was flagged in Phase 08 Loans (LADM-SEC-003) because arbitrary error messages can be injected into the URL and displayed to other admins if a malicious link is shared:

```php
<?php if ( ! empty( $_GET['comp_error'] ) ): ?>
<div class="notice notice-error is-dismissible"><p>
    <?php echo esc_html( urldecode( $_GET['comp_error'] ) ); ?>
</p></div>
```

`urldecode()` is applied before `esc_html()`, so HTML entities are decoded first. The resulting string is then HTML-escaped, which does protect against XSS. However, the message is uncontrolled — a crafted URL can display any error message, enabling social engineering attacks against admins (e.g., a fake "Your session has expired, re-enter your credentials" message). The `handle_save_component()` handler itself also includes `$wpdb->last_error` in the error redirect at line 1694:
```php
wp_safe_redirect( add_query_arg( 'comp_error',
    rawurlencode( __( 'Failed to save component.', 'sfs-hr' ) . ' ' . $wpdb->last_error ),
    $redirect ) );
```
`$wpdb->last_error` may contain SQL snippets that are then URL-encoded and reflected back to the browser — this leaks database schema information.

**Fix:** Pass only an error code in the URL (e.g., `comp_error=save_failed`) and map codes to safe messages server-side. Remove `$wpdb->last_error` from user-facing redirects.

---

### PADM-SEC-006 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:199, 309, 476, 557`
**Issue:** Multiple places use the pattern `esc_html( __( ucfirst( $db_value ), 'sfs-hr' ) )` to display DB-sourced status values:

```php
// Line 199
echo esc_html( __( ucfirst( $current_period->status ), 'sfs-hr' ) ); // status = 'open', 'paid', etc.

// Line 309
echo esc_html( __( ucfirst( $period->status ), 'sfs-hr' ) );

// Line 476
echo esc_html( __( ucfirst( $run->status ), 'sfs-hr' ) );

// Line 557
echo esc_html( __( ucfirst( $run->status ), 'sfs-hr' ) );
```

Passing a DB-sourced value directly to `__()` (the translation function) opens a "translation injection" vector: if a malicious value is stored in the status column, `__()` will attempt to translate that value and could return a translated string different from the DB value. More importantly, `ucfirst()` is applied before `esc_html()` on an unvalidated DB value — if the status column were to contain a value like `"<script>alert(1)"` due to a DB injection, `esc_html()` would protect the output, but the translation system would still attempt to look it up. The correct pattern is to validate the status against a known whitelist before display.

**Fix:** Use a whitelist map for status display (already partially done with `$status_colors` arrays on lines 299–306 and 465–472) — extend those maps to handle display labels and use `$status_labels[ $period->status ] ?? ucfirst( $period->status )` with all values pre-escaped.

---

### PADM-SEC-007 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:2534`
**Issue:** The bank export CSV `Content-Disposition` header at line 2537–2538 uses `$run->period_name` directly in the filename, which was sanitized with `sanitize_file_name()` at line 2534 — that part is correct. However, the WPS SIF `Content-Disposition` header at line 2132 does NOT use `Content-Disposition: attachment; filename="..."` (with quotes), making the filename vulnerable to header injection if it contains special characters that bypass `sanitize_file_name()`:

```php
// Line 2132 — no quotes around filename
header( 'Content-Disposition: attachment; filename=' . $filename . '.sif' );

// Line 2537 — also no quotes
header( 'Content-Disposition: attachment; filename=' . $filename );
```

RFC 6266 requires quoting filenames in `Content-Disposition`. Without quotes, a filename containing spaces or semicolons can break header parsing in some browsers, and in edge cases with certain proxy configurations may be exploitable for response splitting.

**Fix:** Quote all `Content-Disposition` filenames:
```php
header( 'Content-Disposition: attachment; filename="' . $filename . '.sif"' );
```
This is already missing from all four export handlers (lines 2132, 2451, 2481, 2537).

---

### PADM-SEC-008 — Low
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:75`
**Issue:** `render_hub()` checks `sfs_hr.view` (line 75) as the gate for the Payroll admin page. All six tab-rendering methods (`render_overview`, `render_periods`, `render_runs`, `render_components`, `render_payslips`, `render_export`) are then called without re-checking capability. The tabs that manage payroll data (`components`, `export`) have additional in-form capability checks before showing action buttons (e.g., line 833, 863), but the underlying render methods do not re-assert capability before executing DB queries and outputting data.

For example, `render_components()` at line 806 calls `$wpdb->get_results(...)` and lists all salary components to all `sfs_hr.view` holders. Salary component details (amounts, percentages) are sensitive remuneration data that should be restricted to HR managers.

**Fix:** Add a secondary capability check at the top of `render_components()` and `render_export()`:
```php
private function render_components(): void {
    if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Access denied.', 'sfs-hr' ) . '</p></div>';
        return;
    }
    // ...
}
```

---

## Performance Findings

### PADM-PERF-001 — High
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:2367–2389`
**Issue:** N+1 query pattern in `handle_export_detailed()`. When `$include_attendance` is checked, the export loop issues one `$wpdb->get_row()` per employee to fetch attendance summary:

```php
foreach ( $items as $item ) {
    // ...
    if ( $include_attendance ) {
        $att = $wpdb->get_row( $wpdb->prepare(   // Line 2369 — 1 query per employee
            "SELECT COUNT(*) as total_days, SUM(net_minutes) ...
             FROM {$sessions_table}
             WHERE employee_id = %d AND work_date BETWEEN %s AND %s",
            $item->employee_id, $run->start_date, $run->end_date
        ) );
```

For an organization with 200 employees, this generates 200+ queries on `sfs_hr_attendance_sessions` in a single export request. `sfs_hr_attendance_sessions` is a high-volume table (documented as ~18K lines of module code, daily session records). The result could time out or cause significant DB load.

**Fix:** Pre-fetch all attendance summaries in a single GROUP BY query before the loop, then look up per-employee:
```php
$att_map = [];
$att_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT employee_id, COUNT(*) as total_days, SUM(net_minutes) as work_minutes,
            SUM(overtime_minutes) as ot_minutes,
            SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) as absent_days
     FROM {$sessions_table}
     WHERE work_date BETWEEN %s AND %s
     GROUP BY employee_id",
    $run->start_date, $run->end_date
) );
foreach ( $att_rows as $ar ) {
    $att_map[ $ar->employee_id ] = $ar;
}
// Then in the loop: $att = $att_map[ $item->employee_id ] ?? null;
```

---

### PADM-PERF-002 — High
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1459`
**Issue:** `handle_run_payroll()` fetches all active employee IDs with a single unbounded query, then calls `PayrollModule::calculate_employee_payroll()` for each in a synchronous loop inside a DB transaction:

```php
$employees = $wpdb->get_col(
    "SELECT id FROM {$emp_table} WHERE status = 'active'"  // Line 1459 — no LIMIT
);

foreach ( $employees as $emp_id ) {
    $calc = PayrollModule::calculate_employee_payroll( (int) $emp_id, $period_id, ... );
    // ...
    $wpdb->insert( $items_table, [...] );
}
```

For 500 employees, this is 500+ `calculate_employee_payroll()` calls (each of which makes multiple DB queries per PAY-PERF-001 from 09-01 findings), all within a single `START TRANSACTION` block with a 10-minute transient lock. At scale, this will exhaust PHP's `max_execution_time` and leave the transaction open, requiring manual `ROLLBACK`.

This finding was also partially flagged as PAY-SEC-001 in the 09-01 audit for the missing `$wpdb->prepare()` wrapper.

**Fix:** Move payroll calculation out of a synchronous HTTP request entirely — use WordPress Cron (`wp_schedule_single_event`) or an Action Scheduler job. The HTTP handler should:
1. Create the run record (status: `calculating`)
2. Schedule an async job to process employees in batches of 50
3. Redirect to a run status page that polls until complete

Short-term mitigation: add `set_time_limit(0)` and `ignore_user_abort(true)` at the start of `handle_run_payroll()`, and increase MySQL `wait_timeout` via `$wpdb->query("SET SESSION wait_timeout=600")` before `START TRANSACTION`.

---

### PADM-PERF-003 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1099–1108, 261, 422–428`
**Issue:** Several listing queries use fixed hard-coded `LIMIT` values with no pagination UI or cursor:

- Line 261: `LIMIT 50` on payroll periods — `render_periods()`
- Lines 422–428: `LIMIT 50` on payroll runs — `render_runs()`
- Line 1107: `LIMIT 100` on payslips for admin — `render_payslips()`

As the organization grows, payroll admins managing multiple years of history will hit these caps silently — the UI shows no "page 2" link and gives no indication that records are being truncated. A payroll manager searching for a specific old period will not find it.

**Fix:** Add WordPress-style `WP_List_Table` pagination: count total rows first, then apply `LIMIT` + `OFFSET` based on `$_GET['paged']`. Alternatively, add a "Load more" link that appends records via AJAX. At minimum, add a notice: "Showing most recent 50 of {$total} periods."

---

### PADM-PERF-004 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:611–617`
**Issue:** In `render_run_detail()`, `components_json` is decoded per row in a PHP loop:

```php
foreach ( $items as $idx => $item ):
    $components = ! empty( $item->components_json ) ? json_decode( $item->components_json, true ) : [];
    $earnings   = is_array( $components ) ? array_filter( $components, fn( $c ) => ... ) : [];
    $deductions = is_array( $components ) ? array_filter( $components, fn( $c ) => ... ) : [];
    $benefits   = is_array( $components ) ? array_filter( $components, fn( $c ) => ... ) : [];
```

Each `array_filter()` call iterates the full components array three times per employee row. For a 300-employee run where each employee has 10+ components, this is 9,000+ closure invocations just to split components into three buckets. More critically, the same split logic is duplicated verbatim in `render_payslip_detail()` (lines 1241–1243), `handle_export_detailed()` (lines 2341–2353), `handle_export_wps()` (lines 2153–2161), `format_wps_csv_data()` (lines 2210–2220), and in the SIF export body (lines 2150–2162) — see PADM-DUP-001.

**Fix:** Extract a `split_components( array $components ): array` utility method returning `['earnings' => [...], 'deductions' => [...], 'benefits' => [...]]` and replace all six occurrences. This also reduces future maintenance risk when new component types are added.

---

### PADM-PERF-005 — Low
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1730–1741`
**Issue:** `render_export()` fetches up to 24 periods and 24 approved/paid runs unconditionally on every page load of the export tab, even when the user is only interested in one export type. The `$runs` query JOINs `sfs_hr_payroll_runs` with `sfs_hr_payroll_periods` and filters by status — this is a reasonable query, but populating two independent dropdowns unconditionally means loading data for both WPS and Detailed exports even if neither is used.

**Fix:** Low priority — the `LIMIT 24` cap mitigates this. Consider lazy-loading dropdown options via AJAX on dropdown focus if the number of runs grows large.

---

## Duplication Findings

### PADM-DUP-001 — High
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php` (multiple locations)
**Issue:** The `components_json` splitting logic (separate earnings, deductions, benefits from a flat array by `type`) is copy-pasted in six places:

| Location | Lines |
|----------|-------|
| `render_run_detail()` | 614–617 |
| `render_payslip_detail()` | 1241–1243 |
| `handle_export_detailed()` | 2341–2353 |
| `handle_export_wps()` (SIF) | 2153–2161 |
| `format_wps_csv_data()` | 2210–2220 |
| housing/transport extraction (additional sub-pattern) | 2337–2353 |

The housing and transport allowance extraction (filtering earnings by specific codes) is also repeated between `format_wps_csv_data()` and `handle_export_detailed()` with near-identical logic.

**Fix:** Add a private static helper:
```php
private static function parse_components( array $components ): array {
    $result = [ 'earnings' => [], 'deductions' => [], 'benefits' => [] ];
    foreach ( $components as $c ) {
        $type = $c['type'] ?? 'earning';
        if ( isset( $result[ $type . 's' ] ) ) {
            $result[ $type . 's' ][] = $c;
        }
    }
    return $result;
}

private static function extract_named_component( array $earnings, string $code ): float {
    foreach ( $earnings as $c ) {
        if ( ( $c['code'] ?? '' ) === $code ) return (float) ( $c['amount'] ?? 0 );
    }
    return 0.0;
}
```

---

### PADM-DUP-002 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php` (multiple locations)
**Issue:** The `$status_colors` array for payroll run statuses is defined twice — once in `render_runs()` (lines 465–472) and once in `render_run_detail()` (not present there but status display logic at line 557 is inline). The period status colors array is also separately defined in `render_periods()` (lines 299–306). These duplicate definitions mean that adding a new status (e.g., `archived`) requires changes in two places.

**Fix:** Define status label and color maps as private class constants or a private method:
```php
private function get_run_status_meta( string $status ): array {
    return [
        'draft'       => [ 'color' => '#f0ad4e', 'label' => __( 'Draft', 'sfs-hr' ) ],
        'calculating' => [ 'color' => '#5bc0de', 'label' => __( 'Calculating', 'sfs-hr' ) ],
        // ...
    ][ $status ] ?? [ 'color' => '#777', 'label' => ucfirst( $status ) ];
}
```

---

### PADM-DUP-003 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1201–1204, 1093–1096`
**Issue:** The "is admin" check appears in three methods with slightly different capability combinations:

```php
// render_payslips() lines 1093–1096
$is_admin = current_user_can( 'sfs_hr_payroll_admin' )
         || current_user_can( 'sfs_hr.manage' )
         || current_user_can( 'manage_options' );

// render_payslip_detail() lines 1201–1203
$is_admin = current_user_can( 'sfs_hr_payroll_admin' )
         || current_user_can( 'sfs_hr.manage' )
         || current_user_can( 'manage_options' );

// render_runs() line 488
current_user_can( 'sfs_hr_payroll_admin' ) || current_user_can( 'sfs_hr.manage' ) || current_user_can( 'manage_options' )

// handle_approve_run() line 1561
! current_user_can( 'sfs_hr_payroll_admin' ) && ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'manage_options' )
```

Four places define the same three-capability admin check. If `sfs_hr_payroll_admin` is renamed or a new admin capability is added, all four must be updated consistently.

**Fix:** Extract to a private helper:
```php
private function is_payroll_admin(): bool {
    return current_user_can( 'sfs_hr_payroll_admin' )
        || current_user_can( 'sfs_hr.manage' )
        || current_user_can( 'manage_options' );
}
```

---

### PADM-DUP-004 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:2444–2496`
**Issue:** `export_csv()` and `export_xlsx()` share identical logic for output buffering cleanup, header-setting boilerplate, BOM writing, and `fputcsv()` iteration — the only difference is the `Content-Type`, `Content-Disposition` extension, and the `fputcsv()` delimiter (`','` vs `"\t"`):

```php
private function export_csv( ... ): void {
    while ( ob_get_level() ) { ob_end_clean(); }
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );
    // ...
    $output = fopen( 'php://output', 'w' );
    fwrite( $output, "\xEF\xBB\xBF" );
    foreach ( $rows as $row ) { fputcsv( $output, $row ); }
    fclose( $output );
    exit;
}

private function export_xlsx( ... ): void {
    // Identical except Content-Type, filename suffix, and "\t" delimiter
}
```

**Fix:** Consolidate into one method with a format parameter:
```php
private function stream_tabular_export( string $filename, array $rows, string $format = 'csv' ): void {
    $delimiter = $format === 'xlsx' ? "\t" : ',';
    $content_type = $format === 'xlsx'
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'text/csv; charset=utf-8';
    // ...
}
```

---

### PADM-DUP-005 — Low
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:336–404` vs `1351–1389`
**Issue:** Both `render_period_form()` and `handle_create_period()` compute date defaults for a new period:

```php
// render_period_form() lines 337–340
$now = new \DateTime();
$start_of_month = $now->format( 'Y-m-01' );
$end_of_month = $now->format( 'Y-m-t' );
$default_pay_date = ( new \DateTime( $end_of_month ) )->modify( '+5 days' )->format( 'Y-m-d' );
```

This is display-only duplication (the form uses these for default field values, the handler receives them via POST). Not a bug, but worth noting that if the default pay date logic changes (e.g., to +7 days), only the form would be updated without the handler needing changes. Low risk.

---

## Logical Findings

### PADM-LOGIC-001 — High
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1560–1626`
**Issue:** `handle_approve_run()` generates payslips for all items in the run but does not check for duplicate payslips. If the approve handler is somehow called twice (e.g., double-form submission, browser back-button), it will:
1. Silently succeed on the second `$wpdb->update()` for the run (status is already `approved`, so the WHERE `status = 'review'` check at line 1579 prevents the duplicate approval)

Wait — the check `$run->status !== 'review'` at line 1579 does protect against double approval. However, the run record is fetched once and the status check is done once; if two simultaneous requests reach the handler at the same time, both may fetch the run in `review` status before either updates it to `approved` (classic TOCTOU race). The resulting double-approval would generate duplicate payslips for all employees in the run, creating duplicate payslip records with the same `payslip_number` (if `PayrollModule::generate_payslip_number()` is deterministic) or different numbers (if it uses timestamp/random).

The payslip insert at lines 1609–1615 has no duplicate-prevention mechanism:
```php
foreach ( $items as $item ) {
    $payslip_number = PayrollModule::generate_payslip_number( ... );
    $wpdb->insert( $payslips_table, [ ... ] );  // No ON DUPLICATE KEY or existence check
}
```

**Fix:** Wrap the status check and status update in a single atomic UPDATE-then-check:
```php
$updated = $wpdb->update(
    $runs_table,
    [ 'status' => 'approved', 'approved_at' => $now, 'approved_by' => $user_id, 'updated_at' => $now ],
    [ 'id' => $run_id, 'status' => 'review' ]  // Atomic: only updates if still in 'review'
);
if ( ! $updated ) {
    // Already approved or not found
    wp_safe_redirect( ... );
    exit;
}
```
Then fetch the run after the successful update. This eliminates the TOCTOU window.

---

### PADM-LOGIC-002 — High
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:202`
**Issue:** The "Run Payroll" form on the overview tab checks `current_user_can( 'sfs_hr_payroll_run' )` to show the submit button, but the corresponding `handle_run_payroll()` handler at line 1391–1392 checks `sfs_hr_payroll_run` OR `sfs_hr.manage`. The capability name `sfs_hr_payroll_run` uses an underscore (WordPress roles/caps format), while the rest of the plugin uses dotted format (`sfs_hr.manage`, `sfs_hr.view`). It is unclear whether `sfs_hr_payroll_run` is ever actually assigned to any role — there is no evidence in the codebase of this capability being registered or assigned.

If `sfs_hr_payroll_run` is never assigned to any role, then only `sfs_hr.manage` holders can run payroll (the form-show check at line 202 would always evaluate false for all non-manage users, hiding the button for everyone except managers who also see a Run button in the periods table at line 314 — which has the same check). This creates a dead permission slot that may confuse future developers.

**Fix:** Audit whether `sfs_hr_payroll_run` is registered and assigned anywhere in the plugin. If not, either:
(a) Remove it and use only `sfs_hr.manage` for payroll run permission, or
(b) Register it in the Capabilities class and assign it to the appropriate role.

---

### PADM-LOGIC-003 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1411–1417`
**Issue:** The transient-based payroll lock check has a TOCTOU race documented in the 09-01 findings (PAY-LOGIC-003), and repeated here for completeness in the Admin_Pages context. The `get_transient` / `set_transient` sequence is not atomic — two concurrent requests can both pass the `get_transient()` check before either calls `set_transient()`:

```php
if ( get_transient( $lock_key ) ) {         // Line 1413 — read
    // ... redirect
}
set_transient( $lock_key, ... , 600 );       // Line 1417 — write (not atomic with read)
```

On object-cache backends (Redis/Memcached), this race is small but real. On database-backed transients (default WordPress), the window is larger due to MySQL round-trips.

**Fix:** Use `wp_cache_add()` (which is atomic on proper cache backends) or MySQL `GET_LOCK()` (same pattern used by Attendance module `Session_Service`):
```php
$wpdb->query( $wpdb->prepare( "SELECT GET_LOCK(%s, 0)", $lock_key ) );
```

---

### PADM-LOGIC-004 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1459–1513`
**Issue:** `handle_run_payroll()` skips employees where `calculate_employee_payroll()` returns an `error` key (line 1474–1476) and records the error in the run notes, but does not surface the skipped employee count to the approver in any UI element. The `$errors` array is concatenated into the run's `notes` column, but the run detail page renders `$run->notes` inside a plain `notice-info` box (line 575) with no distinction between informational notes and error records.

A payroll admin approving a run with 450 employees in the table might not notice that 5 employees were silently skipped due to calculation errors. Those employees' payslips are never generated.

**Fix:** Add a `skipped_count` column to the payroll run record (or parse the notes on the detail page) and display a prominent warning:
```php
<?php if ( $run->skipped_count > 0 ): ?>
<div class="notice notice-warning">
    <p><?php printf( esc_html__( '%d employee(s) were skipped due to calculation errors. Review the notes below.', 'sfs-hr' ), intval( $run->skipped_count ) ); ?></p>
</div>
<?php endif; ?>
```

---

### PADM-LOGIC-005 — Medium
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:1319–1334`
**Issue:** The payslip detail view (`render_payslip_detail()`) only renders an Earnings section if `! empty( $earnings )` and only renders a Deductions section if `! empty( $deductions )`. Since earnings and deductions are filtered from `components_json`, if `components_json` is NULL or empty (e.g., for payslips created before components were introduced, or where the payroll calculation did not produce components), the payslip renders as:

- Base Salary: {amount}
- Net Salary: {amount}
- (no earnings breakdown)
- (no deductions breakdown)

This is "silent missing data" — the payslip totals are correct (from direct DB columns), but the detailed breakdown is absent with no explanation. An employee or auditor looking at such a payslip cannot verify how the net was calculated.

**Fix:** Add an explicit fallback when `components_json` is empty:
```php
<?php if ( empty( $earnings ) && empty( $deductions ) ): ?>
<div class="notice notice-info">
    <p><?php esc_html_e( 'Detailed salary breakdown not available for this payslip.', 'sfs-hr' ); ?></p>
</div>
<?php else: ?>
    <!-- earnings and deductions tables -->
<?php endif; ?>
```

---

### PADM-LOGIC-006 — Low
**File:** `includes/Modules/Payroll/Admin/Admin_Pages.php:2121`
**Issue:** The WPS SIF export calculates `$period_days` as:
```php
$period_days = ( strtotime( $run->end_date ) - strtotime( $run->start_date ) ) / 86400 + 1;
```
This calculation counts calendar days including weekends. Saudi Arabia's WPS SIF format expects the number of "days of wage entitlement" — for a standard monthly employee, this is the total calendar days in the period (correct). However, for weekly or bi-weekly payroll periods, the period might not align with complete calendar months, and the WPS SIF standard may expect the number of working days rather than calendar days depending on the employment contract type. The same `$period_days` calculation is duplicated in `format_wps_csv_data()` at line 2185.

The calculation also does not use `wp_date()` or account for WordPress timezone — `strtotime()` will use the server timezone, which may differ from the configured WordPress timezone, causing a 1-day off-by-one error in timezone-boundary months.

**Fix:** Replace `strtotime()` with `wp_date()` for timezone-consistent date arithmetic:
```php
$start_ts = strtotime( get_date_from_gmt( $run->start_date . ' 12:00:00' ) );
$end_ts   = strtotime( get_date_from_gmt( $run->end_date . ' 12:00:00' ) );
$period_days = (int) round( ( $end_ts - $start_ts ) / 86400 ) + 1;
```
Document whether WPS expects calendar days or working days.

---

## Export / Report Security Summary (Success Criteria #4)

The following table summarizes the export handlers against the security audit requirements from the plan:

| Export Handler | Capability Gate | Nonce | SQL Injection | Output Escaping | Data Leakage |
|----------------|-----------------|-------|---------------|-----------------|--------------|
| `handle_export_attendance()` | **CRITICAL: `sfs_hr.view`** (any employee) | Yes | Safe | Safe | **CRITICAL: all employees** |
| `handle_export_bank()` | `sfs_hr_payroll_admin` or `sfs_hr.manage` | Yes | Safe (prepare used) | Safe (fputcsv) | Correct scope |
| `handle_export_wps()` | `sfs_hr_payroll_admin` or `sfs_hr.manage` | Yes | Safe (prepare used) | SIF: partial (PADM-SEC-004) | Correct scope |
| `handle_export_detailed()` | `sfs_hr_payroll_admin` or `sfs_hr.manage` | Yes | Safe (prepare used) | Safe (number_format, fputcsv) | Correct scope |

**Key finding:** `handle_export_attendance()` is the only export handler with an insufficient capability gate — rated Critical because it allows any authenticated employee to download a full cross-organizational attendance report.

All export handlers require nonce validation (`check_admin_referer`). All except the attendance handler require management-level capabilities. No export handler is accessible via GET request (all require POST with a nonce). Bookmarked/shared export URLs cannot bypass auth.

Department-level data leakage: None of the export handlers filter by the requesting user's department. This is intentional for `sfs_hr.manage` holders (org-wide view), but the attendance export's `sfs_hr.view` gate means employees access data beyond their own records.
