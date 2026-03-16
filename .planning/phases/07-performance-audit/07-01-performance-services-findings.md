# 07-01: Performance Services, Calculator & Cron — Audit Findings

**Phase:** 07-performance-audit
**Plan:** 01
**Files audited:** 6
**Total lines audited:** ~3,465 (Performance_Calculator: 415, Reviews_Service: 460, Goals_Service: 388, Attendance_Metrics: 520, Alerts_Service: 706, Performance_Cron: 976)
**Date:** 2026-03-16

---

## Summary Table

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 2 | 3 | 2 | 1 | 8 |
| Performance | 1 | 4 | 2 | 0 | 7 |
| Duplication | 0 | 1 | 4 | 2 | 7 |
| Logical | 0 | 3 | 4 | 2 | 9 |
| **TOTAL** | **3** | **11** | **12** | **5** | **31** |

---

## Security Findings

### PERF-SEC-001
**Severity:** Critical
**File:** `includes/Modules/Performance/Services/Performance_Calculator.php:257-262`
**Issue:** `get_performance_ranking()` builds a SQL query with raw string concatenation. The `$where` variable is conditionally appended with `$wpdb->prepare()` for the `dept_id`, but the base query at line 257 is executed as a raw string `"SELECT id, employee_code, first_name, last_name, dept_id FROM {$employees_table} {$where} ORDER BY first_name, last_name"` passed directly to `$wpdb->get_results()`. Although in this specific path the `$where` clause content is sanitized, the SQL string itself is never wrapped in `$wpdb->prepare()`, bypassing the WP API contract. If the non-dept_id branch is followed, the string `WHERE status = 'active'` is a literal concatenation with no parameterization.
**Fix:** Restructure query using `$wpdb->prepare()` for the full statement, or explicitly document that the static `WHERE status = 'active'` literal is safe and the dept_id branch already uses `$wpdb->prepare()`. At minimum, adopt the same guarded-prepare pattern from `get_department_metrics()` (Attendance_Metrics.php:267-273) for consistency and to silence security scanners.

### PERF-SEC-002
**Severity:** Critical
**File:** `includes/Modules/Performance/Services/Performance_Calculator.php:318-320`
**Issue:** `get_departments_summary()` executes `$wpdb->get_results("SELECT id, name FROM {$dept_table} WHERE active = 1 ORDER BY name")` — raw string, no `$wpdb->prepare()`. Table name interpolation is safe but the broader pattern violates the project coding standard (CLAUDE.md: "All database access uses `$wpdb` directly with `$wpdb->prepare()`"). Static queries with no dynamic values are borderline acceptable, but several other raw queries exist throughout (see below), creating an inconsistent baseline that makes security review harder.
**Fix:** Wrap static filter-only queries in `$wpdb->prepare()` or add an inline comment `/* no user input */` to clearly document intentional omissions. Apply consistently.

### PERF-SEC-003
**Severity:** High
**File:** `includes/Modules/Performance/Services/Reviews_Service.php:93-95`
**Issue:** `calculate_overall_rating()` executes `$wpdb->get_results("SELECT id, weight FROM {$criteria_table} WHERE active = 1", OBJECT_K)` without `$wpdb->prepare()`. Same static-query pattern. More importantly, the `$criterion_id` key from the caller's `$ratings` array (line 101) is used as an array key lookup only — it is never inserted into SQL directly — so no injection here. However, the unvalidated `$criterion_id` could be used to look up arbitrary criteria weights, allowing a caller to blend in weights for deactivated or non-existent criteria by submitting crafted criterion IDs (non-existent IDs fall back to `weight = 100` at line 102).
**Fix:** Validate that all submitted `$criterion_id` values exist in `$criteria` (active criteria set) before processing them. Discard ratings for unknown criteria rather than defaulting to weight 100.

### PERF-SEC-004
**Severity:** High
**File:** `includes/Modules/Performance/Services/Reviews_Service.php:24-76`
**Issue:** `save_review()` has no capability check. Any authenticated user who can reach this method (via REST or admin-post) can create or update any employee's review. `reviewer_id` defaults to `get_current_user_id()` but is overridable via `$data['reviewer_id']` with no validation that the current user has the right to review that specific employee. There is no check that the current user has `sfs_hr.manage` or a reviewer-specific capability.
**Fix:** Add a capability guard at the top of `save_review()`: `if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'sfs_hr.leave.review' ) ) { return new \WP_Error( 'forbidden', ... ); }`. Also validate that `reviewer_id` equals `get_current_user_id()` unless the caller has admin-level access.

### PERF-SEC-005
**Severity:** High
**File:** `includes/Modules/Performance/Services/Goals_Service.php:24-67`
**Issue:** `save_goal()` and `update_progress()` (lines 77-118) have no capability checks. Any user who can call these methods can create goals for any `employee_id` or advance another employee's goal progress. `created_by` is set to `get_current_user_id()` but the `employee_id` is accepted without verifying the caller owns the employee record or has manager access to that employee.
**Fix:** Add a capability check: allow only if `current_user_can( 'sfs_hr.manage' )` or (for self-service) the calling user's employee record matches `$data['employee_id']`. Similarly gate `delete_goal()`.

### PERF-SEC-006
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Alerts_Service.php:116-136`
**Issue:** `get_active_alerts()` builds `$join_where` with `$wpdb->prepare()` for `dept_id` (line 124) and appends it to the query string by concatenation (line 131). The prepared fragment is a safe `AND e.dept_id = %d` snippet, but appending prepared fragments into a larger raw string bypasses the normal `$wpdb->prepare()` contract and could confuse future maintainers into thinking the outer query is also prepared.
**Fix:** Restructure as a single `$wpdb->prepare()` call with conditional clause inclusion, or note clearly in a comment that `$join_where` is always either empty string or a fragment produced by `$wpdb->prepare()`.

### PERF-SEC-007
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Alerts_Service.php:146-160`
**Issue:** `acknowledge_alert()` and `resolve_alert()` accept arbitrary `$alert_id` with no ownership verification. Any user who can reach the endpoint can acknowledge or resolve alerts for any employee. There is no check that the caller has a management role or is the employee named in the alert.
**Fix:** Load the alert record first, verify `alert->employee_id` belongs to the caller's department or the caller has `sfs_hr.manage`, then proceed.

### PERF-SEC-008
**Severity:** Low
**File:** `includes/Modules/Performance/Services/Alerts_Service.php:544`
**Issue:** `__( ucfirst( $alert->severity ), 'sfs-hr' )` passes a raw DB value through `ucfirst()` before translation. While `severity` is constrained to `info|warning|critical` on insert (line 44-45), if the stored value is corrupted or injected via direct DB write, this could translate unexpected strings. Minor risk because the value is only rendered in email body, which is HTML-escaped elsewhere.
**Fix:** Validate `$alert->severity` against the known set before calling `ucfirst()`. Use: `$severity = in_array( $alert->severity, ['info','warning','critical'], true ) ? $alert->severity : 'info';`.

---

## Performance Findings

### PERF-PERF-001
**Severity:** Critical
**File:** `includes/Modules/Performance/Services/Performance_Calculator.php:264-296` (get_performance_ranking)
**Issue:** Severe N+1 query pattern in `get_performance_ranking()`. The method first fetches all active employees in a single query (safe), then calls `self::calculate_overall_score()` once per employee (line 267). Each `calculate_overall_score()` call triggers three sub-service calls: `Attendance_Metrics::get_employee_metrics()` (2 queries: employee lookup + sessions), `Goals_Service::calculate_goals_metrics()` (1 query), `Reviews_Service::calculate_review_metrics()` (2 queries). This is **5 queries per employee**. For a company with 200 employees this is 1,001 queries per `get_performance_ranking()` call. `get_departments_summary()` (lines 305-374) has the same pattern layered one level deeper: it iterates departments, fetches employee IDs per department, then calls `calculate_overall_score()` per employee — the outer queries plus 5N inner queries.
**Fix:** Add a batch query path for ranking/summary use cases. Pre-fetch all employee sessions in one query with `WHERE employee_id IN (...)`, all goals in one query, and all reviews in one query, then compute metrics in PHP. Cache `PerformanceModule::get_settings()` outside the loop (it already loads settings per call — currently safe if WP object cache is active, but it adds a cache hit per employee). At minimum, add a transient or static cache for `get_performance_ranking()` results.

### PERF-PERF-002
**Severity:** High
**File:** `includes/Modules/Performance/Cron/Performance_Cron.php:164-179` (run_snapshot_generation)
**Issue:** `run_snapshot_generation()` fetches ALL active employees with no LIMIT (line 164-165), then calls `Performance_Calculator::generate_snapshot()` per employee in a loop (line 175). `generate_snapshot()` internally calls `calculate_overall_score()` (5 queries) plus a snapshot INSERT/UPDATE. For 200 employees, this is ~1,200 queries in a single cron execution with no batching, no timeout guard, and no progress checkpointing. If PHP max_execution_time is 30s, this will time out on any real deployment.
**Fix:** Implement batch processing: process employees in chunks of 25-50 per cron tick using an offset stored in a transient, or use WP's `Action Scheduler` / a custom queue. Add `set_time_limit(0)` guard or break into multiple cron events.

### PERF-PERF-003
**Severity:** High
**File:** `includes/Modules/Performance/Cron/Performance_Cron.php:268-304` (run_weekly_digest)
**Issue:** `run_weekly_digest()` fetches all active employees (unbounded, line 268) then calls `Attendance_Metrics::get_employee_metrics()` per employee in a loop (line 286). This is 2 queries per employee (employee record + sessions). Additionally, `get_active_alerts()` is called once (line 309) — this is safe as a single query. But the per-employee metrics loop (N×2 queries) runs in a single WP-Cron request with no batching.
**Fix:** Pre-fetch all sessions for the period in a single `WHERE employee_id IN (...)` query and process in PHP. The weekly digest email build should also be deferred — currently `build_report_table()` is called once per department manager/HR responsible, rebuilding the same HTML multiple times. Cache the built HTML.

### PERF-PERF-004
**Severity:** High
**File:** `includes/Modules/Performance/Cron/Performance_Cron.php:403-576` (run_monthly_reports)
**Issue:** `run_monthly_reports()` has the same unbounded per-employee loop as the weekly digest (line 435-457, `Attendance_Metrics::get_employee_metrics()` per employee). Additionally, `build_report_table()` is called multiple times (once for GM, once for HR, once per department) — each call runs `usort()` on the full dataset. For a large company with many departments, this is significant repeated sorting with no caching.
**Fix:** Sort the dataset once before dispatching emails. Cache `build_report_table()` output keyed by dept_id to avoid rebuilding identical table HTML.

### PERF-PERF-005
**Severity:** High
**File:** `includes/Modules/Performance/Services/Alerts_Service.php:188-311` (check_commitment_alerts)
**Issue:** `check_commitment_alerts()` fetches all active employees (line 201, unbounded) then calls `Attendance_Metrics::get_employee_metrics()` per employee (line 213). This is called daily by cron. It also calls `self::create_alert()` per employee per alert type (up to 3 alerts per employee per day), each of which issues a duplicate-check SELECT + INSERT/UPDATE. For 200 employees, this is up to 200×2 (metrics) + 600 (alert SELECTs) + 600 (INSERT/UPDATEs) = 1,400 queries per daily cron run.
**Fix:** Batch the metrics collection. Pre-load all active alerts in one query at the start of `check_commitment_alerts()`, then compare in PHP instead of doing per-employee duplicate SELECTs in `create_alert()`.

### PERF-PERF-006
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Attendance_Metrics.php:250-333` (get_department_metrics)
**Issue:** `get_department_metrics()` fetches employee list (1 query) then calls `self::get_employee_metrics()` per employee (2 queries each). This is N+1 within a single department call. When `get_all_departments_summary()` (line 342) iterates departments and calls `get_department_metrics()` per department, it becomes D×(1 + 2N) queries for D departments and N employees per department.
**Fix:** For the summary use case, pass pre-fetched session data into `get_employee_metrics()`, or add a bulk variant that accepts a pre-keyed sessions array.

### PERF-PERF-007
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Reviews_Service.php:399-409` (calculate_review_metrics)
**Issue:** `calculate_review_metrics()` fetches reviews for the date range (line 361-372), then issues a second `get_var()` for the latest rating (lines 399-408). The latest rating could be derived from the already-fetched `$reviews` array in PHP (sort by `period_end DESC`, take first) without an extra query.
**Fix:** Remove the second DB query. Sort `$reviews` by `period_end DESC` after fetching, then set `$metrics['latest_rating'] = (float) $reviews[0]->overall_rating`.

---

## Duplication Findings

### PERF-DUP-001
**Severity:** High
**File:** `includes/Modules/Performance/Cron/Performance_Cron.php:239-304` and `403-457`
**Issue:** `run_weekly_digest()` and `run_monthly_reports()` share nearly identical employee-fetching + metrics-building logic (the JOIN query at lines 268-275 vs 416-424, and the metrics loop at 285-304 vs 435-457). The `$entry` array structure is identical in both methods. `build_report_table()` is also called in both. The only differences are the period date calculation and the recipient list.
**Fix:** Extract the employee+metrics collection into a private helper method `collect_employee_metrics_for_period( string $start_date, string $end_date ): array` returning the `$all_data`/`$by_dept` pair. Both cron methods call this helper.

### PERF-DUP-002
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Alerts_Service.php:188-311` vs `399-482`
**Issue:** `check_commitment_alerts()` and `refresh_employee_attendance_alerts()` implement identical alert logic (low commitment, excessive late, excessive absence) with duplicated code blocks. The three alert type checks are copy-pasted between methods with minor differences (the full method processes all employees; `refresh_*` processes one). Any threshold change must be updated in both places.
**Fix:** Extract the three alert condition checks into a private helper `evaluate_and_update_attendance_alerts( object $emp, array $metrics, float $threshold ): int`. Both methods call this helper.

### PERF-DUP-003
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Reviews_Service.php:24-76` vs `Goals_Service.php:24-67`
**Issue:** `Reviews_Service::save_review()` and `Goals_Service::save_goal()` follow the identical upsert pattern: validate required fields, build data array, check `$data['id']`, call `$wpdb->update()` or `$wpdb->insert()`. The pattern is not harmful as-is, but any future change to the upsert semantics (e.g. adding audit trail logging on every write) must be applied to both files and any future service.
**Fix:** Consider a shared `DB_Upsert::save( string $table, array $data, array $format_hints ): int|\WP_Error` helper in `Core/Helpers.php` — low priority; only worthwhile if a third service is added.

### PERF-DUP-004
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Performance_Calculator.php:245-249`, `Attendance_Metrics.php:255-263`, `Alerts_Service.php:208-209`
**Issue:** Default date range fallback (get current attendance period, then assign `$start_date`/`$end_date` if empty) is repeated in at least 5 places across the performance module. Any change to how the default period is derived requires updating all sites.
**Fix:** Extract `PerformanceModule::get_default_period(): array` that returns `['start' => ..., 'end' => ...]` using the current attendance period. All callers use this instead of inline date derivation.

### PERF-DUP-005
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Attendance_Metrics.php:209-234` vs Attendance module's `Commitment_Calculator` (if exists)
**Issue:** The commitment percentage formula (line 209-234 in Attendance_Metrics.php) with its flag weights (late=0.25, early_leave=0.25, incomplete=0.5, break_delay=0.25) almost certainly duplicates or should derive from the Attendance module's own deduction logic. If the Attendance module changes its deduction weights, the Performance module's calculation will silently diverge, producing inconsistent scores.
**Fix:** Audit whether the Attendance module exposes a `get_commitment_score( array $metrics ): float` helper. If so, delegate to it. If not, add a cross-module contract/interface. This is a medium risk: currently both use the same values, but there is no shared constant enforcing it.

### PERF-DUP-006
**Severity:** Low
**File:** `includes/Modules/Performance/Services/Performance_Calculator.php:175-186` vs `Attendance_Metrics.php:391-403`
**Issue:** Both files define a grade-mapping function: `Performance_Calculator::get_overall_grade()` maps 0-100 scores to five grade labels; `Attendance_Metrics::get_grade()` maps commitment percentages to four grade labels. The boundaries (90/80/70/60 vs configurable thresholds) differ but the pattern is the same. `get_grade_display()` is also defined in both files with different grade keys.
**Fix:** Low priority — the two grade scales serve different conceptual purposes (overall performance vs attendance). Document this distinction explicitly so future developers don't attempt to merge them.

### PERF-DUP-007
**Severity:** Low
**File:** `includes/Modules/Performance/Cron/Performance_Cron.php:677-696` vs `850-896`
**Issue:** `build_improvement_hints()` (line 677) immediately delegates to `build_improvement_hints_array()` (line 850) and wraps the result in a plain-text format. The plain-text version (`build_improvement_hints`) is dead code: the HTML email builder at line 736 calls `build_improvement_hints_array()` directly, and there is no other caller of the plain-text wrapper.
**Fix:** Remove `build_improvement_hints()` (the plain-text wrapper at line 677-690). Keep only `build_improvement_hints_array()`.

---

## Logical Findings

### PERF-LOGIC-001
**Severity:** High
**File:** `includes/Modules/Performance/Cron/Performance_Cron.php:411-413`
**Issue:** `run_monthly_reports()` calculates the reporting period with a hard-coded offset: `$end_date = $now->modify('-1 day')` and `$start_date = $now->modify('-1 month')`. This assumes the cron fires exactly on the 26th. But the cron is scheduled with interval `30 * DAY_IN_SECONDS` (line 73), meaning it drifts — WordPress cron runs when the next request arrives after the scheduled time. If the cron fires on the 27th, `$start_date` becomes the 27th of last month (not the 26th). The period calculation is fragile.
**Fix:** Hard-code the period logic: `$start_date = date('Y-m-26', strtotime('last month'))` and `$end_date = date('Y-m-25')` (current month's 25th). Or, calculate based on `$now->format('d')` and the known 26th anchor, similar to how `run_weekly_digest()` correctly handles it (lines 256-262).

### PERF-LOGIC-002
**Severity:** High
**File:** `includes/Modules/Performance/Services/Reviews_Service.php:259-287` (submit_review)
**Issue:** `submit_review()` has no capability check. Any user who knows a review ID can submit it (transition to `submitted` status). The reviewer's identity is not verified: there is no check that `$review->reviewer_id === get_current_user_id()` or that the caller has a management role. Combined with PERF-SEC-004 (no capability check on `save_review()`), the review workflow has no access control at the service layer.
**Fix:** Add `if ( (int) $review->reviewer_id !== get_current_user_id() && ! current_user_can( 'sfs_hr.manage' ) ) { return new \WP_Error( 'forbidden', ... ); }` at the start of `submit_review()`.

### PERF-LOGIC-003
**Severity:** High
**File:** `includes/Modules/Performance/Services/Reviews_Service.php:270`
**Issue:** `submit_review()` allows re-submission: `if ( $review->status !== 'draft' && $review->status !== 'pending' )` means a review in `pending` status can be submitted (status transitions from `pending` → `submitted`). But `save_review()` can also set `status = 'pending'` directly. There is no guard against submitting an already `submitted` review if its status is somehow reset to `pending` again. Combined with missing update guards in `save_review()` (which accepts any status without state-machine validation on update), it's possible to re-open and re-submit a finalized review by calling `save_review()` with `status=pending` then `submit_review()`.
**Fix:** In `save_review()` on UPDATE, enforce that status can only progress forward through the defined state machine (`draft` → `pending` → `submitted` → `acknowledged`). Reject any attempt to set `status` to a value that would move the review backward.

### PERF-LOGIC-004
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Goals_Service.php:231-245` (calculate_goals_metrics query)
**Issue:** The goals query includes goals where `target_date IS NULL` (line 233). This means all un-dated goals for an employee are always included in every period's metrics calculation, regardless of when they were created. An employee with 10 old un-dated goals from 3 years ago will have those permanently weighted into every period's score. This inflates or deflates the `weighted_completion_pct` unexpectedly.
**Fix:** For time-bounded period calculations, exclude goals without a `target_date` unless they were created within the period (`created_at BETWEEN %s AND %s` — this condition already exists at line 239 as an OR). Remove the `target_date IS NULL` OR arm from the WHERE clause when `$start_date` and `$end_date` are provided, keeping only `target_date` within range OR `created_at` within range.

### PERF-LOGIC-005
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Performance_Calculator.php:141-145`
**Issue:** Weight redistribution logic: when some components have no data, the overall score redistributes weights: `$adjustment_factor = 100 / $total_weight_used`. This means if an employee has no reviews (weight 30%) and only attendance data (weight 40%), their score is calculated as `attendance_weighted * (100/40)` = attendance is worth 100%. This produces a misleading overall score: an employee who has perfect attendance but no reviews will show 100% overall. The caller receives `$result['weights']` showing the original weights, but the score reflects different effective weights with no disclosure.
**Fix:** Add `$result['effective_weights']` to the result showing the redistributed weights actually used for calculation. Alternatively, do not redistribute — return `null` for overall score if any component is missing data, or make redistribution opt-in via a settings flag.

### PERF-LOGIC-006
**Severity:** Medium
**File:** `includes/Modules/Performance/Services/Attendance_Metrics.php:209-225`
**Issue:** The commitment percentage formula accumulates `$issue_score` with fractional deductions, then computes `$effective_present = $metrics['total_working_days'] - $issue_score`. If an employee has many overlapping flags (e.g., a day that is both `late` AND triggers a break delay), they accumulate `-0.25` for late AND `-0.25` for break delay on the same day. A single day could theoretically contribute up to `-1.5` to `$issue_score` (absent=1.0, late=0.25, early=0.25, incomplete=0.5, break_delay=0.25, no_break=0.15 — though absent and the others are mutually exclusive for the most part). `max(0, $effective_present)` caps at zero, preventing negative scores, but an employee with 5 working days who is late every day and has break delays every day would score `5 - (5×0.25 + 5×0.25) = 5 - 2.5 = 2.5/5 = 50%` — mathematically reasonable. However, the formula is undocumented and the combination weights are not visible to administrators.
**Fix:** Document the formula and its interaction with overlapping flags in a docblock. Expose the formula as a configurable setting so administrators can tune penalty weights.

### PERF-LOGIC-007
**Severity:** Medium
**File:** `includes/Modules/Performance/Cron/Performance_Cron.php:44-84` (schedule)
**Issue:** The `schedule()` method registers the `cron_schedules` filter (line 70-84) inside the `init` hook callback, which runs on every page load. The filter is added via `add_filter()` inside a method that is itself hooked to `init`. While WordPress deduplicates filter execution per request, re-adding the same anonymous closure on every `init` call means the filter is registered multiple times per request if somehow `init` fires more than once (unlikely in practice, but poor pattern). More importantly, `monthly` and `weekly` intervals are registered as `30 * DAY_IN_SECONDS` and `7 * DAY_IN_SECONDS` respectively — but the `monthly` interval is imprecise (February gets a short month, months with 31 days get early triggers). The `CRON_REPORTS` is supposed to fire on the 26th, but with a drift-prone 30-day interval it will desync.
**Fix:** Move the `add_filter('cron_schedules', ...)` call out of the `init` callback and into the class constructor or a separate `register_schedules()` method called during plugin load. This prevents duplicate filter registration.

### PERF-LOGIC-008
**Severity:** Low
**File:** `includes/Modules/Performance/Services/Alerts_Service.php:315-350` (check_goal_alerts)
**Issue:** `check_goal_alerts()` calls `Goals_Service::get_overdue_goals()` (no employee filter, all active overdue goals). For each overdue goal, it calls `self::create_alert()` which checks for an existing active alert of type `TYPE_GOAL_OVERDUE`. However, the duplicate-check in `create_alert()` (line 56-64) keys only on `(employee_id, alert_type, status='active')` — not on `goal_id`. If an employee has 3 overdue goals, only 1 alert record is kept (the check would find the first `TYPE_GOAL_OVERDUE` alert and update it with the latest goal's data, silently discarding the other 2 goal overdue conditions). The alert `meta_json` for the last processed goal overwrites the previous ones.
**Fix:** Add `goal_id` to the deduplication check in `create_alert()`, or change the duplicate key to include `meta_json->goal_id`. This requires either a structural change to the alerts table (new `reference_id` column) or a more flexible duplicate-check mechanism.

### PERF-LOGIC-009
**Severity:** Low
**File:** `includes/Modules/Performance/Cron/Performance_Cron.php:545-546`
**Issue:** In `run_monthly_reports()`, `$settings` is referenced on line 545 (`$settings['attendance_thresholds']`) but `$settings` is never assigned in `run_monthly_reports()`. The method does not call `PerformanceModule::get_settings()`. This will cause a PHP `Undefined variable: $settings` notice/warning. The code appears to have been intended to call `get_settings()` but the assignment was omitted.
**Fix:** Add `$settings = PerformanceModule::get_settings();` at the top of `run_monthly_reports()` (before line 411 or at minimum before line 545).

---

## $wpdb Query Inventory

All `$wpdb` calls by file:

| File | Method | Line | Type | Prepared? | Status |
|------|--------|------|------|-----------|--------|
| Performance_Calculator.php | get_performance_ranking | 257 | get_results | NO | Flag (PERF-SEC-001) |
| Performance_Calculator.php | get_performance_ranking | 254 | prepare fragment | YES (partial) | See PERF-SEC-001 |
| Performance_Calculator.php | get_departments_summary | 318 | get_results | NO | Flag (PERF-SEC-002) |
| Performance_Calculator.php | get_departments_summary | 326-329 | get_col | YES | Safe |
| Performance_Calculator.php | generate_snapshot | 403-411 | update | YES (via API) | Safe |
| Reviews_Service.php | calculate_overall_rating | 93-95 | get_results | NO | Flag (PERF-SEC-002 pattern) |
| Reviews_Service.php | save_review | 67, 71 | update/insert | YES (via API) | Safe |
| Reviews_Service.php | get_review | 127-137 | get_row | YES | Safe |
| Reviews_Service.php | get_employee_reviews | 167-173 | get_results | YES (fragments) | Safe |
| Reviews_Service.php | get_pending_reviews | 196-205 | get_results | YES | Safe |
| Reviews_Service.php | acknowledge_review | 222-225 | get_row | YES | Safe |
| Reviews_Service.php | acknowledge_review | 239-248 | update | YES (via API) | Safe |
| Reviews_Service.php | submit_review | 274-280 | update | YES (via API) | Safe |
| Reviews_Service.php | get_criteria | 302-303 | get_results | NO | Acceptable (no user input) |
| Reviews_Service.php | save_criterion | 330, 334 | update/insert | YES (via API) | Safe |
| Reviews_Service.php | calculate_review_metrics | 361-372 | get_results | YES | Safe |
| Reviews_Service.php | calculate_review_metrics | 399-408 | get_var | YES | Safe (redundant — see PERF-PERF-007) |
| Reviews_Service.php | get_due_reviews | 427-438 | get_results | YES | Safe |
| Goals_Service.php | save_goal | 57, 62 | update/insert | YES (via API) | Safe |
| Goals_Service.php | update_progress | 84-87 | get_row | YES | Safe |
| Goals_Service.php | update_progress | 107, 110-116 | update/insert | YES (via API) | Safe |
| Goals_Service.php | get_goal | 132-134 | get_row | YES | Safe |
| Goals_Service.php | get_employee_goals | 173-185 | get_results | YES (fragments) | Safe |
| Goals_Service.php | get_goal_history | 199-206 | get_results | YES | Safe |
| Goals_Service.php | calculate_goals_metrics | 231-246 | get_results | YES | Safe |
| Goals_Service.php | delete_goal | 321, 324 | delete | YES (via API) | Safe |
| Goals_Service.php | get_categories | 337-340 | get_col | NO | Acceptable (no user input) |
| Goals_Service.php | get_overdue_goals | 380-386 | get_results | YES (fragments) | Safe |
| Attendance_Metrics.php | get_employee_metrics | 44-48 | get_row | YES | Safe |
| Attendance_Metrics.php | get_employee_metrics | 63-74 | get_results | YES | Safe |
| Attendance_Metrics.php | get_department_metrics | 272-276 | get_results | NO (partial) | Flag (PERF-SEC-002 pattern) |
| Attendance_Metrics.php | get_all_departments_summary | 358-359 | get_results | NO | Acceptable (no user input) |
| Attendance_Metrics.php | save_snapshot | 458-464 | get_var | YES | Safe |
| Attendance_Metrics.php | save_snapshot | 490, 493 | update/insert | YES (via API) | Safe |
| Attendance_Metrics.php | get_employee_history | 510-517 | get_results | YES | Safe |
| Alerts_Service.php | create_alert | 56-64 | get_var | YES | Safe |
| Alerts_Service.php | create_alert | 68, 72 | update/insert | YES (via API) | Safe |
| Alerts_Service.php | get_employee_alerts | 101-107 | get_results | YES (fragments) | Safe |
| Alerts_Service.php | get_active_alerts | 127-135 | get_results | NO (partial) | Flag (PERF-SEC-006) |
| Alerts_Service.php | acknowledge_alert | 151-158 | update | YES (via API) | Safe |
| Alerts_Service.php | resolve_alert | 173-179 | update | YES (via API) | Safe |
| Alerts_Service.php | auto_resolve_alert | 496-506 | update | YES (via API) | Safe |
| Alerts_Service.php | send_alert_notifications | 531-536 | get_row | YES | Safe |
| Alerts_Service.php | send_alert_notifications | 568-571 | get_var | YES | Safe |
| Alerts_Service.php | get_statistics | 670-673, 683-686, 695-700 | get_results×3 | NO | Acceptable (no user input) |
| Performance_Cron.php | run_snapshot_generation | 164-165 | get_col | NO | Acceptable (no user input) |
| Performance_Cron.php | run_snapshot_generation | 185-189 | query | YES | Safe |
| Performance_Cron.php | run_weekly_digest | 268-276 | get_results | NO | Acceptable (no user input, JOIN) |
| Performance_Cron.php | run_weekly_digest | 309-319 | get_results | NO | Acceptable (no user input) |
| Performance_Cron.php | run_weekly_digest | 348-349 | get_results | NO | Acceptable (no user input) |
| Performance_Cron.php | run_monthly_reports | 416-424 | get_results | NO | Acceptable (no user input) |
| Performance_Cron.php | run_monthly_reports | 504-505 | get_results | NO | Acceptable (no user input) |
| Performance_Cron.php | trigger_snapshots | 930-931 | get_col | NO | Acceptable (no user input) |

---

## Cross-File Reference Coverage

All 6 source files referenced in findings:

- `Performance_Calculator.php` — PERF-SEC-001, PERF-SEC-002, PERF-PERF-001, PERF-DUP-004, PERF-DUP-006, PERF-LOGIC-005
- `Reviews_Service.php` — PERF-SEC-003, PERF-SEC-004, PERF-PERF-007, PERF-DUP-003, PERF-LOGIC-002, PERF-LOGIC-003
- `Goals_Service.php` — PERF-SEC-005, PERF-DUP-003, PERF-LOGIC-004
- `Attendance_Metrics.php` — PERF-PERF-006, PERF-DUP-004, PERF-DUP-005, PERF-DUP-006, PERF-LOGIC-006
- `Alerts_Service.php` — PERF-SEC-006, PERF-SEC-007, PERF-SEC-008, PERF-PERF-005, PERF-DUP-002, PERF-LOGIC-008
- `Performance_Cron.php` — PERF-PERF-002, PERF-PERF-003, PERF-PERF-004, PERF-DUP-001, PERF-DUP-007, PERF-LOGIC-001, PERF-LOGIC-007, PERF-LOGIC-009
