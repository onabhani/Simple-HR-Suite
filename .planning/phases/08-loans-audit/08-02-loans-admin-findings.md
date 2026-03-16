# 08-02 Loans Admin Pages & Dashboard Widget — Audit Findings

**Audited files:**
- `includes/Modules/Loans/Admin/class-admin-pages.php` (3,066 lines)
- `includes/Modules/Loans/Admin/class-dashboard-widget.php` (273 lines)

**Audit date:** 2026-03-16
**Plan:** 08-02 (Phase 08 — Loans Module Audit)

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 2 | 3 | 3 | 1 | 9 |
| Performance | 0 | 2 | 2 | 1 | 5 |
| Duplication | 0 | 1 | 2 | 1 | 4 |
| Logical | 0 | 3 | 3 | 1 | 7 |
| **Total** | **2** | **9** | **10** | **4** | **25** |

---

## Security Findings

### LADM-SEC-001 — Critical: Nonce check deferred after capability check in `handle_loan_actions()`

**Severity:** Critical
**File:** `class-admin-pages.php:2046–2095`

**Description:**
`handle_loan_actions()` is registered on `admin_post_sfs_hr_loan_action`. The method first checks the action type and capability (`$allowed`), then dispatches into a `switch` where each `case` calls `check_admin_referer()` individually. However, for the `create_loan` action the nonce is checked at line 2420 (`check_admin_referer( 'sfs_hr_loan_create' )`), which is correct. But the overall handler has no upfront nonce check. A CSRF attacker can enumerate capabilities by crafting requests for various actions and observing whether they silently return vs. process. More critically, any future developer adding a `case` to the switch can forget `check_admin_referer`, and WordPress will not enforce it.

The correct pattern for `admin_post_*` handlers is to verify the nonce first (before any DB reads or capability checks), then check capability.

**Fix:**
Add `check_admin_referer( ... )` as the first step inside each action case (which is already done for approve_gm, approve_finance, reject, cancel, and create). Document explicitly that each new case MUST include `check_admin_referer`. Add a comment at the top of the method stating this requirement. The missing nonce for `update_loan` and `record_payment` cases is the real gap — those actions are listed in the capability switch at line 2073–2076 but have NO corresponding handler code in the `switch` at lines 2089–2678. If those cases are ever added, they must include nonce verification.

---

### LADM-SEC-002 — Critical: `handle_installment_actions()` forms post to `action=""` (current page) without CSRF pattern enforcement

**Severity:** Critical
**File:** `class-admin-pages.php:1085–1097, 1159–1161`

**Description:**
The installment action forms in the installments tab use `action=""` — i.e., they POST to the current admin page. The handler `handle_installment_actions()` is registered on `admin_init` (line 17). While each individual payment case does call `check_admin_referer('sfs_hr_mark_installment_' . $payment_id)`, the nonce value is embedded in a `data-nonce` HTML attribute on the table row (line 1042):

```php
data-nonce="<?php echo wp_create_nonce( 'sfs_hr_mark_installment_' . $inst->id ); ?>"
```

This nonce is then populated into the hidden input field via JavaScript in the modal. This means the nonce is visible in the DOM to any script on the page (including third-party admin scripts). If a malicious plugin or XSS vector reads `data-nonce` attributes, it can forge installment-marking requests. The standard secure pattern stores nonces in form fields server-side rather than exposing them as data attributes.

Additionally, the bulk action form references nonce `sfs_hr_bulk_installments` (line 2862) but there is no bulk action form rendered in the visible installments tab HTML — the form was never surfaced to the UI, making bulk operations unreachable.

**Fix:**
- Instead of embedding nonces in data attributes, generate nonces per-row in the form `<input>` directly, or use `admin-post.php` as the form target (consistent with other loan actions).
- Alternatively, use a single page-level nonce (`wp_create_nonce('sfs_hr_installment_actions')`) valid for the whole page session, validated once in `handle_installment_actions()`.

---

### LADM-SEC-003 — High: Unprepared query at line 145 (loan list status count for "All" tab)

**Severity:** High
**File:** `class-admin-pages.php:145–146`

**Description:**
The loop that counts loans per status tab has a special case for `$st === ''` (All Loans) that constructs a bare SQL string without `$wpdb->prepare()`:

```php
$count_sql = "SELECT COUNT(*) FROM {$table}";
$counts[ $st ] = (int) $wpdb->get_var( $count_sql );
```

The table name `$table` is constructed from `$wpdb->prefix . 'sfs_hr_loans'` (constant string, not user input), so there is no injection risk from this specific query. However, it violates the project's mandatory `$wpdb->prepare()` rule for all queries and sets a dangerous precedent. It is flagged as High because the pattern can be copied to introduce real vulnerabilities.

**Fix:**
```php
$counts[ $st ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
```
This is already the actual code; but the convention should be wrapped or documented. Per project CLAUDE.md: "All database access uses `$wpdb` directly with `$wpdb->prepare()`". The query should be `$wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$table}") )` to satisfy the linter convention, even if there is no injection risk.

---

### LADM-SEC-004 — High: `render_create_loan_form()` employee list query is unprepared

**Severity:** High
**File:** `class-admin-pages.php:742–748`

**Description:**
```php
$employees = $wpdb->get_results(
    "SELECT e.id, e.employee_code, e.first_name, e.last_name,
            COALESCE(d.name, 'N/A') as department
     FROM {$emp_table} e
     LEFT JOIN {$dept_table} d ON e.dept_id = d.id
     WHERE e.status = 'active'
     ORDER BY e.first_name, e.last_name"
);
```

No `$wpdb->prepare()` call. Both table names are constants built from `$wpdb->prefix`, so there is no immediate injection vector. However, this violates the project's mandatory `prepare()` rule and is High severity because it normalizes the unprepared pattern in a large file.

**Fix:**
Wrap in `$wpdb->prepare()`:
```php
$employees = $wpdb->get_results( $wpdb->prepare(
    "SELECT e.id, e.employee_code, e.first_name, e.last_name,
            COALESCE(d.name, 'N/A') as department
     FROM {$emp_table} e
     LEFT JOIN {$dept_table} d ON e.dept_id = d.id
     WHERE e.status = %s
     ORDER BY e.first_name, e.last_name",
    'active'
) );
```

---

### LADM-SEC-005 — High: Dashboard widget queries in `get_loan_statistics()` are unprepared (lines 70–108)

**Severity:** High
**File:** `class-dashboard-widget.php:70–108`

**Description:**
Seven of the eight queries in `get_loan_statistics()` are not wrapped in `$wpdb->prepare()`:

```php
$active_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$loans_table} WHERE status = 'active'"
);
$outstanding = (float) $wpdb->get_var(
    "SELECT SUM(remaining_balance) FROM {$loans_table} WHERE status = 'active'"
);
// ... (4 more similar bare queries)
$total_disbursed = (float) $wpdb->get_var(
    "SELECT SUM(principal_amount) FROM {$loans_table} WHERE status IN ('active', 'completed')"
);
```

Only `$this_month_count` (line 95) correctly uses `$wpdb->prepare()`. The status strings are hardcoded so there is no injection risk, but it violates the mandatory `prepare()` convention. Marked High for consistency violation in a file that runs on every admin page load.

**Fix:**
Wrap all bare queries in `$wpdb->prepare()`. Since these queries have no dynamic user parameters, a no-parameter prepare call is acceptable:
```php
$active_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$loans_table} WHERE status = %s", 'active'
) );
```

---

### LADM-SEC-006 — Medium: Loan list query with no parameters skips `$wpdb->prepare()` entirely (line 183)

**Severity:** Medium
**File:** `class-admin-pages.php:170–183`

**Description:**
The main loan list query (`render_loans_tab()`) correctly prepares when parameters exist, but when `$params` is empty (no status filter, no search), the raw query string is passed directly to `$wpdb->get_results()`:

```php
if ( ! empty( $params ) ) {
    $query = $wpdb->prepare( $query, ...$params );
}
$loans = $wpdb->get_results( $query );  // unprepared when no params
```

Table names are constants, so no injection risk here, but the conditional prepare pattern is fragile. If a developer later adds a parameter path that doesn't append to `$params`, the query runs unprepared.

**Fix:**
Use `$wpdb->prepare()` unconditionally, or restructure to always have at least one parameter (e.g., a `1=1` dummy):
```php
$loans = $wpdb->get_results( $params ? $wpdb->prepare( $query, ...$params ) : $query );
```
Alternatively, refactor to always call `$wpdb->prepare()`.

---

### LADM-SEC-007 — Medium: Menu capability is `read` — any logged-in WP user can navigate to the loans menu URL

**Severity:** Medium
**File:** `class-admin-pages.php:40–43`

**Description:**
```php
add_submenu_page(
    $parent_slug,
    __( 'Loans', 'sfs-hr' ),
    __( 'Loans', 'sfs-hr' ),
    'read', // Use 'read' as base capability, we check permissions internally
    'sfs-hr-loans',
    [ $this, 'loans_page' ]
);
```

The menu is registered with `'read'` capability (every logged-in WP user has this). The comment acknowledges this is intentional, relying on `loans_page()` to check `$can_access`. However, if `loans_page()` is ever bypassed (direct URL access, programmatic call, or future refactor), the underlying page is still accessible. WordPress registers the admin-ajax endpoint for the page slug regardless of the capability. `'read'` also means the menu appears in the WP admin sidebar for any subscriber-level user who has been granted access to `wp-admin`.

**Fix:**
Use the minimum required capability. Since all three checked caps are HR-specific, use `sfs_hr.view` (or `sfs_hr.manage`) as the menu capability, eliminating the reliance on internal checks:
```php
'capability' => 'sfs_hr.manage',
```

---

### LADM-SEC-008 — Medium: Export CSV has no nonce verification (`export_installments_csv()`)

**Severity:** Medium
**File:** `class-admin-pages.php:2977–3066`

**Description:**
The CSV export endpoint (`admin_init` hook, triggered by `?tab=installments&action=export_csv`) checks `current_user_can('sfs_hr.manage')` but does NOT verify a nonce. Any page that can make a GET request as the authenticated admin (e.g., an `<img>` tag on a third-party site) could trigger a financial data export. CSV injection attacks are also possible if employee names contain formula characters (`=`, `+`, `-`, `@`).

**Fix:**
1. Add nonce verification: Add `?_wpnonce=...` to the export URL and call `check_admin_referer('sfs_hr_export_installments')` at the top of `export_installments_csv()`.
2. Sanitize CSV values to prevent CSV injection by prefixing formula characters with a single quote.

---

### LADM-SEC-009 — Low: `render_loan_detail()` history meta `$display_value` rendered without escaping

**Severity:** Low
**File:** `class-admin-pages.php:2021–2026`

**Description:**
In the loan history panel, numeric meta values from `json_decode()` are formatted with `number_format()` and printed unescaped:

```php
if ( is_numeric( $value ) && $key !== 'installments' ) {
    $display_value = number_format( (float) $value, 2 );
} else {
    $display_value = esc_html( $value );  // non-numeric is escaped
}
echo '<strong>' . esc_html( $label ) . ':</strong> ' . $display_value . '<br>';
```

`number_format()` returns a string of digits, commas, and decimal points only, so there is no XSS risk from this specific path. However, the asymmetry (numeric not escaped, string escaped) is misleading. If the `is_numeric` branch is later modified to include formatted strings, it creates a silent XSS vector.

**Fix:**
Apply `esc_html()` to both branches for consistency:
```php
echo '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $display_value ) . '<br>';
```

---

## Performance Findings

### LADM-PERF-001 — High: Loan list has no pagination — loads ALL loans on every page view

**Severity:** High
**File:** `class-admin-pages.php:170–183`

**Description:**
`render_loans_tab()` queries all loans matching the filter with `ORDER BY l.created_at DESC` but no `LIMIT` or `OFFSET`. The result is stored in `$loans` (line 183) and all rows are rendered in the table. On an organization with hundreds of loans this query will:
- Fetch all rows from the DB into PHP memory
- Render all rows as HTML table rows in one page response
- Cause browser freezes on large result sets

The total count `$total = count( $loans )` (line 184) confirms pagination was never implemented.

Additionally, the `$status_tabs` loop runs 7 separate `COUNT(*)` queries to populate the tab badges (lines 143–150) — that is 7 queries on every loans page load, in addition to the unbounded main query.

**Fix:**
1. Add `LIMIT 50 OFFSET $offset` to the main query.
2. Add a `$page` parameter from `$_GET['paged']` and render WP-style pagination links.
3. Use a single `GROUP BY status` query for tab counts instead of 7 separate queries.

---

### LADM-PERF-002 — High: Dashboard widget runs 8 uncached queries on every admin page load

**Severity:** High
**File:** `class-dashboard-widget.php:65–119`

**Description:**
`get_loan_statistics()` executes 8 separate `$wpdb->get_var()` calls every time the WP admin dashboard (or any page triggering the `sfs_hr_dashboard_widgets` action) loads. With no caching, this means:
- On a site with 5 admins each loading the dashboard once per hour = 40 queries/hour just for the widget
- Each query scans the entire `sfs_hr_loans` table (no composite indexes on `status` columns confirmed in Plan 01 findings)
- The `SUM(remaining_balance)` and `SUM(principal_amount)` queries are full table aggregations

**Fix:**
Cache the result of `get_loan_statistics()` using a transient:
```php
$stats = get_transient('sfs_hr_loan_stats_cache');
if ( false === $stats ) {
    $stats = $this->compute_loan_statistics(); // existing code
    set_transient('sfs_hr_loan_stats_cache', $stats, 5 * MINUTE_IN_SECONDS);
}
```
Invalidate the transient on any loan status change via `sfs_hr_loan_status_changed` action hook.

---

### LADM-PERF-003 — Medium: `render_installments_tab()` also has no pagination — loads all installments for a month

**Severity:** Medium
**File:** `class-admin-pages.php:899–913`

**Description:**
The installments tab query loads all payment records for the selected month with no `LIMIT`. For a company with 300 active loans, a single month could have 300 rows. While this is inherently bounded by the number of active loans (unlike the loan list which is unbounded), it still returns all rows into PHP memory. The 4-query status count loop (lines 877–895) adds 4 additional queries per page load.

**Fix:**
Add pagination with `LIMIT 50 OFFSET $offset`. Consolidate status counts into a single `SELECT status, COUNT(*) FROM ... GROUP BY status` query.

---

### LADM-PERF-004 — Medium: `render_create_loan_form()` loads all active employees without limit

**Severity:** Medium
**File:** `class-admin-pages.php:742–748`

**Description:**
The create loan form loads all active employees into a `<select>` dropdown. On organizations with 500+ employees this renders a very large dropdown and transfers significant HTML. There is no search/autocomplete.

**Fix:**
Replace the static dropdown with an AJAX employee search input (type-ahead), or add a `LIMIT 200` and note in the UI that only the first 200 employees are listed with a search hint.

---

### LADM-PERF-005 — Low: `render_loan_detail()` loads ALL payment schedule rows regardless of count

**Severity:** Low
**File:** `class-admin-pages.php:1644–1648`

**Description:**
```php
$payments = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$payments_table} WHERE loan_id = %d ORDER BY sequence ASC",
    $loan_id
) );
```

For a 60-installment loan this is 60 rows — acceptable. However, if installments are ever regenerated (see LADM-LOGIC-005), duplicate rows could inflate this set. No upper bound is enforced.

**Fix:**
Low risk. Consider adding `LIMIT 120` as a safety guard to prevent unexpected data expansion from corrupting the page render.

---

## Duplication Findings

### LADM-DUP-001 — High: Installment amount formula is `round(principal / count, 2)` in admin, confirmed divergence from frontend

**Severity:** High
**File:** `class-admin-pages.php:2199, 2594–2595, 2615`

**Description:**
Three places in `class-admin-pages.php` calculate the installment amount using `round($principal / $installments, 2)`:
- Line 2199: `$installment_amount = round( $principal / $installments, 2 );` (finance approval)
- Line 2594: `$installment_amount_check = round( $principal / $installments, 2 );` (create validation)
- Line 2615: `$installment_amount = round( $principal / $installments, 2 );` (create insertion)

Per Phase 08-01 findings (LOAN-DUP-001), the frontend handler uses `floor` + remainder distribution. The admin pages consistently use `round`. This means:
- A loan created/approved by admin stores `installment_amount = round(1000/3, 2) = 333.33`
- The frontend would have stored `floor(1000/3, 2) = 333.33` with the final installment as `333.34`
- The `generate_payment_schedule()` uses the stored `$installment_amount` uniformly, so all installments get `333.33` × 3 = 999.99 — a 1 cent gap in `remaining_balance`

**Fix:**
Centralize installment calculation in `LoansModule::calculate_installment_amounts(float $principal, int $count): array` that returns an array of per-installment amounts using the floor+remainder pattern. Call this from both admin and frontend paths.

---

### LADM-DUP-002 — Medium: `get_status_pill()` and `get_status_badge()` are separate methods with identical loan-status label arrays

**Severity:** Medium
**File:** `class-admin-pages.php:715–731, 1567–1588`

**Description:**
Two private methods exist for rendering loan status:
- `get_status_pill()` (line 715): uses CSS classes from `output_loans_styles()`
- `get_status_badge()` (line 1567): uses inline `background` color styles

Both contain the same 6-element label array (`pending_gm`, `pending_finance`, `active`, `completed`, `rejected`, `cancelled`). Adding a new status requires updating both methods.

**Fix:**
Consolidate into a single method (prefer `get_status_pill()` as it uses CSS classes). Update `render_loan_detail()` to call `get_status_pill()` instead of `get_status_badge()`.

---

### LADM-DUP-003 — Medium: Fiscal-year boundary calculation duplicated between admin (`create_loan`) and frontend handler

**Severity:** Medium
**File:** `class-admin-pages.php:2506–2557`

**Description:**
The fiscal year boundary logic (lines 2506–2557) computes `$fy_start` and `$fy_end` for the one-loan-per-fiscal-year check. The same logic almost certainly exists in the frontend loan request handler (`Handlers/class-loan-handler.php` from Plan 01 scope). If any fiscal-year edge case is fixed in one place, the other copy will not be updated.

**Fix:**
Extract to `LoansModule::get_fiscal_year_boundaries(): array` returning `['start' => '...', 'end' => '...']`. Call from both admin and frontend handler.

---

### LADM-DUP-004 — Low: `update_loan_balance()` inline in AdminPages; likely duplicated in LoanService

**Severity:** Low
**File:** `class-admin-pages.php:2929–2972`

**Description:**
`update_loan_balance()` is a private method of `AdminPages`. It recalculates `remaining_balance` from payment records and auto-completes the loan when balance reaches zero. This logic is almost certainly needed in the frontend payment handler and cron jobs as well. If the service layer has a similar function, changes must be made in both places.

**Fix:**
Move `update_loan_balance()` to `Services/LoanService.php` and call it from `AdminPages`. This makes it testable independently.

---

## Logical Findings

### LADM-LOGIC-001 — High: Finance approval can overwrite GM-approved amount without any upper bound validation

**Severity:** High
**File:** `class-admin-pages.php:2190–2197`

**Description:**
When Finance approves a loan, they can freely enter any `principal_amount` value in the form (line 1855). The handler reads this directly:

```php
$principal = (float) ( $_POST['principal_amount'] ?? 0 );
```

There is no validation that the Finance-approved amount does not exceed the originally requested or GM-approved amount. A Finance approver could increase the loan amount beyond what the employee requested or the GM approved. The only validation is `$principal <= 0 || $installments <= 0` (line 2195).

**Fix:**
Validate that `$principal <= $original_requested_amount` (or `$approved_gm_amount` if set). Display the original/GM amount in the Finance approval form as a non-editable reference.

---

### LADM-LOGIC-002 — High: `generate_payment_schedule()` deletes and recreates the entire payment schedule without checking for existing paid/partial payments

**Severity:** High
**File:** `class-admin-pages.php:2684–2725`

**Description:**
```php
// Clear existing schedule
$wpdb->delete( $payments_table, [ 'loan_id' => $loan_id ] );
```

The schedule generator first deletes ALL payment rows for the loan, then inserts fresh rows. This is called from:
1. `handle_loan_actions()` → `approve_finance` (line 2244)

Finance approval activates the loan for the first time, so at this point no payments should exist yet. However, there is no guard that prevents Finance from re-approving (the status guard at line 2185 prevents this at the SQL level via `AND status = 'pending_finance'`). The concern is that if `generate_payment_schedule()` is ever called from another code path (e.g., a future "reschedule" feature), it would silently delete paid installments.

**Fix:**
Add an explicit guard at the top of `generate_payment_schedule()`:
```php
$paid_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$payments_table} WHERE loan_id = %d AND status IN ('paid', 'partial')",
    $loan_id
) );
if ( $paid_count > 0 ) {
    throw new \RuntimeException( 'Cannot regenerate schedule: paid installments exist.' );
}
```

---

### LADM-LOGIC-003 — High: `cancel_loan` action marks all `planned` payments as `skipped` but does NOT update the `remaining_balance` of each individual payment

**Severity:** High
**File:** `class-admin-pages.php:2362–2403`

**Description:**
When a loan is cancelled, the loan record gets `remaining_balance = 0` (line 2370). The planned payment rows are set to `status = 'skipped'` (lines 2397–2403). However, the individual payment `amount_paid` column remains `0` for skipped rows. This means:

1. `update_loan_balance()` (which sums `amount_paid` across all payments) would compute a non-zero remaining balance if called after cancellation, contradicting the `remaining_balance = 0` set on the loan.
2. Reporting queries that join payments to compute outstanding debt would show active payment obligations for cancelled loans.

The `cancel_loan` branch never calls `update_loan_balance()`, relying instead on directly setting `remaining_balance = 0`. This is technically correct for the loan record but inconsistent with the balance-from-payments calculation model used everywhere else.

**Fix:**
After the loan is cancelled, call `update_loan_balance($loan_id)` to recompute from payment records, OR accept that cancellation writes `remaining_balance = 0` directly and exclude cancelled loans from all payment-based balance queries with `AND l.status != 'cancelled'`.

---

### LADM-LOGIC-004 — Medium: Payment over-recording is only server-side validated for `partial`, not for `mark_paid`

**Severity:** Medium
**File:** `class-admin-pages.php:2766–2784, 2800–2803`

**Description:**
For `mark_installment_partial`, the server validates `$partial_amount > (float) $payment->amount_planned` (line 2801) and dies with an error. For `mark_installment_paid`, the `amount_paid` is set to `$payment->amount_planned` (line 2768), which is correct. However, neither action checks whether the payment is already in a `paid` status — a Finance user can re-mark a `paid` installment as `paid` again, which recalculates the balance again (double-counting is avoided only because `amount_paid` is overwritten, not accumulated).

For partial payments, re-marking a `partial` payment as `partial` with a different amount silently replaces the amount without an audit entry for the previous amount.

**Fix:**
Add status guard in each case:
```php
if ( $payment->status === 'paid' ) {
    wp_die( __('This installment is already fully paid.', 'sfs-hr') );
}
```
Also log the previous `amount_paid` value in the `payment_marked_partial` event for auditing.

---

### LADM-LOGIC-005 — Medium: Loan creation via admin skips eligibility checks that the frontend handler enforces

**Severity:** Medium
**File:** `class-admin-pages.php:2419–2678`

**Description:**
The admin `create_loan` action validates:
- Minimum service period (lines 2475–2503)
- One-loan-per-fiscal-year (lines 2505–2557)
- Salary multiplier limit (lines 2559–2580)
- Max installment % of salary (lines 2582–2609)

But it does NOT validate:
- `max_loan_amount` (absolute SAR cap from settings — line 1223). The settings field exists but the create handler never reads it.
- `allow_multiple_active_loans` and `max_active_loans_per_employee` — admin can create a second active loan even when the setting disallows it.

The frontend handler likely validates both. This means admin can bypass hard limits set in Loan Settings.

**Fix:**
Add missing validations in the `create_loan` case:
```php
$max_amount = (float) ( $settings['max_loan_amount'] ?? 0 );
if ( $max_amount > 0 && $principal > $max_amount ) {
    // redirect with error
}
if ( ! $settings['allow_multiple_active_loans'] ) {
    // check existing active loans for employee
}
```

---

### LADM-LOGIC-006 — Medium: Admin creates loans with `status = 'pending_gm'` even when `require_gm_approval = false`

**Severity:** Medium
**File:** `class-admin-pages.php:2626–2627`

**Description:**
The loan creation handler always inserts with `'status' => 'pending_gm'` (line 2627) regardless of the `require_gm_approval` setting. If an organization configures "no GM approval required", loans created via admin still get stuck in `pending_gm` state and cannot progress to Finance.

The frontend handler presumably checks this setting. Admin-created loans are stranded in `pending_gm` with no mechanism to advance them unless an admin user has GM approval capability.

**Fix:**
Check `$settings['require_gm_approval']` in the create handler:
```php
$initial_status = $settings['require_gm_approval'] ? 'pending_gm' : 'pending_finance';
```

---

### LADM-LOGIC-007 — Low: Loan history `event_type` rendered using `__()` + `str_replace` + `ucwords` without controlled keys

**Severity:** Low
**File:** `class-admin-pages.php:2012`

**Description:**
```php
echo esc_html( __( str_replace( '_', ' ', ucwords( $event->event_type, '_' ) ), 'sfs-hr' ) );
```

`$event->event_type` comes from the database (written by `LoansModule::log_event()`). The `__()` call wraps an arbitrary string with no guarantee it is in the translation catalog. The `str_replace` and `ucwords` calls operate on DB data before translation, which means the translation key is the transformed DB value (e.g., `"Gm Approved"` rather than the raw key `"gm_approved"`). These generated keys will never match real translation strings.

**Fix:**
Define an explicit map of `event_type => translated label`:
```php
$event_labels = [
    'loan_created'         => __( 'Loan Created', 'sfs-hr' ),
    'gm_approved'          => __( 'GM Approved', 'sfs-hr' ),
    'finance_approved'     => __( 'Finance Approved', 'sfs-hr' ),
    'rejected'             => __( 'Rejected', 'sfs-hr' ),
    'loan_cancelled'       => __( 'Loan Cancelled', 'sfs-hr' ),
    'payment_marked_paid'  => __( 'Payment Marked Paid', 'sfs-hr' ),
    'payment_marked_partial' => __( 'Payment Partial', 'sfs-hr' ),
    'payment_skipped'      => __( 'Payment Skipped', 'sfs-hr' ),
    'schedule_generated'   => __( 'Schedule Generated', 'sfs-hr' ),
    'loan_completed'       => __( 'Loan Completed', 'sfs-hr' ),
];
$display = $event_labels[$event->event_type] ?? esc_html( ucwords( str_replace('_', ' ', $event->event_type) ) );
```

---

## Additional Observations

### LADM-OBS-001: `add_dashboard_widget()` in DashboardWidget checks `sfs_hr.manage` but `render_hr_widget()` also checks at line 50

The double check is correct (defensive programming) and consistent. Not a finding.

### LADM-OBS-002: `get_employee_loan_summary()` in DashboardWidget is a public static method called from external code

This method (line 217) correctly uses `$wpdb->prepare()` and is safe. The `has_active_loans()` check before querying is a minor unnecessary duplication (the prepare query returns 0 for no loans anyway) but not a security or performance issue.

### LADM-OBS-003: `update_loan_balance()` is called inside loop (`bulk_update_installments`)

Lines 2892 and 2911: `update_loan_balance()` is called inside a `foreach` loop over `$payment_ids`. Each call does 2 queries (get loan, get sum). For a bulk operation marking 20 payments paid, this triggers 40 DB queries. Medium performance concern but below threshold for a separate finding given bulk operations are infrequent.

### LADM-OBS-004: Finance approval form has no nonce on the settings form that uses `action=""`

`render_settings_tab()` form uses `action=""` (line 1206) with `wp_nonce_field('sfs_hr_loans_settings')` (line 1207) — this IS correctly nonce-protected via `save_settings()` which calls `check_admin_referer('sfs_hr_loans_settings')` at line 1513. Correct pattern — no finding.

### LADM-OBS-005: `approved_gm_amount` can be 0 if the input field is left blank

At line 2097, `$approved_gm_amount = (float) $_POST['approved_gm_amount']`. If the field is empty, `(float) ''` = `0.0`. The condition at line 2122 checks `$approved_gm_amount !== null && $approved_gm_amount > 0`, so a zero value is correctly ignored and the original principal is preserved. Correct behavior.

---

## Financial Write Action Auth Summary

| Action | Nonce | Capability Check | Status Guard |
|--------|-------|-----------------|--------------|
| `approve_gm` | check_admin_referer per-loan-ID | current_user_can_approve_as_gm() | AND status='pending_gm' |
| `approve_finance` | check_admin_referer per-loan-ID | current_user_can_approve_as_finance() | AND status='pending_finance' |
| `reject_loan` | check_admin_referer per-loan-ID | can_approve_as_gm OR as_finance | AND status IN ('pending_gm','pending_finance') |
| `cancel_loan` | check_admin_referer per-loan-ID | can_approve_as_gm OR sfs_hr.manage | AND status NOT IN ('completed','cancelled') |
| `create_loan` | check_admin_referer 'sfs_hr_loan_create' | sfs_hr.manage or sfs_hr_loans_manage | none |
| `update_loan` | NOT IMPLEMENTED — code stub only | listed in capability switch | N/A |
| `record_payment` | NOT IMPLEMENTED — code stub only | listed in capability switch | N/A |
| `mark_installment_paid` | check_admin_referer per-payment-ID | sfs_hr.manage (handler top) | none (can re-mark paid) |
| `mark_installment_partial` | check_admin_referer per-payment-ID | sfs_hr.manage (handler top) | validates amount <= planned |
| `mark_installment_skipped` | check_admin_referer per-payment-ID | sfs_hr.manage (handler top) | none |
| `bulk_update_installments` | check_admin_referer 'sfs_hr_bulk' | sfs_hr.manage (handler top) | UI form not rendered |
| save_settings | check_admin_referer 'sfs_hr_loans_settings' | sfs_hr.manage | N/A |
| export_csv | MISSING NONCE | sfs_hr.manage | N/A |

**Result:** All financial write actions have capability checks. Nonce is present on all loan state-transition actions (approve, reject, cancel, create). The CSV export and installment nonce-in-data-attribute patterns are the security gaps (LADM-SEC-001, LADM-SEC-002, LADM-SEC-008).
