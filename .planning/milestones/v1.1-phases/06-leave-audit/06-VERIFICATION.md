---
phase: 06-leave-audit
verified: 2026-03-16T04:00:00Z
status: passed
score: 7/7 must-haves verified
re_verification: false
---

# Phase 06: Leave Audit Verification Report

**Phase Goal:** All security, performance, duplication, and logical issues in the Leave module (~7.7K lines) are documented
**Verified:** 2026-03-16T04:00:00Z
**Status:** passed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Leave balance calculation logic is audited for correctness — tenure-based accrual rules and edge cases checked | VERIFIED | 10 Calculation findings (LV-CALC-001 through LV-CALC-010) in 06-01 report; `compute_quota_for_year()` tenure boundary verified against Saudi law (Article 109) at L43-53 of LeaveCalculationService.php |
| 2 | Every $wpdb query in LeaveCalculationService.php and class-leave-ui.php is evaluated for SQL injection risk | VERIFIED | 06-01 report explicitly confirms class-leave-ui.php has zero DB queries and zero findings; LeaveCalculationService.php $wpdb calls evaluated (LV-SEC-001 in 06-01); all calls use `$wpdb->prepare()` |
| 3 | LeaveModule.php balance-related methods (get/set/recalc balance, leave type CRUD, accrual) are audited for correctness and security | VERIFIED | LV-CALC-002 (handle_approve/handle_early_return overwrite opening/carried_over), LV-CALC-007 (recalc_balance_for_request silent no-op), LV-SEC-005 (handle_delete_type orphans balance rows), LV-DUP-001 (3 duplicated upsert blocks) all confirmed |
| 4 | Edge cases in balance calculation are documented: hire mid-year, partial periods, terminated employees, zero-balance requests | VERIFIED | LV-CALC-008 (partial year pro-ration not implemented), LV-CALC-009 (terminated employee balances continue accruing), LV-CALC-003 (zero accrued fallback overrides to full quota), LV-CALC-001 (mid-year tenure boundary) all documented |
| 5 | Leave request approval workflow is checked for missing capability checks and unauthorized state transitions | VERIFIED | LV-WF-001 (handle_approve missing up-front capability guard confirmed at L1057-1058), LV-WF-003 (handle_cancel no login/cap check confirmed at L1761-1763), LV-WF-004 (no DB-level state machine enforcement) documented |
| 6 | Overlap detection logic (concurrent leave requests) is evaluated for race conditions | VERIFIED | LV-SEC-001 in 06-02: TOCTOU race condition confirmed in has_overlap() at LeaveCalculationService.php:L86-94 — plain SELECT COUNT(*) with no transaction or lock before INSERT |
| 7 | Every REST endpoint and admin AJAX handler in LeaveModule.php is checked for auth and nonce validation | VERIFIED | 06-02 report confirms zero `register_rest_route()` calls and zero `wp_ajax_*` handlers in LeaveModule.php — all operations are admin_post only (positive finding, documented explicitly) |

**Score:** 7/7 truths verified

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `.planning/phases/06-leave-audit/06-01-leave-services-balance-findings.md` | Leave services and balance calculation audit findings | VERIFIED | Exists, 378 lines, contains 19 findings across Calculation / Security / Performance / Duplication sections |
| `.planning/phases/06-leave-audit/06-02-leave-workflow-findings.md` | Leave approval workflow and overlap detection audit findings | VERIFIED | Exists, 418 lines, contains 16 findings across Workflow / Security / Logic / Performance sections |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `includes/Modules/Leave/Services/LeaveCalculationService.php` | `06-01-leave-services-balance-findings.md` | Manual code review — file:line references | VERIFIED | LV-CALC-001 L43-53, LV-CALC-003 L74-77, LV-SEC-001 L86-94 cross-checked against actual source; all evidence snippets match live code |
| `includes/Modules/Leave/LeaveModule.php` (balance/type methods) | `06-01-leave-services-balance-findings.md` | Manual code review | VERIFIED | LV-CALC-002 confirmed: `$opening = 0; $carried = 0;` at L1630-1631, L2137-2138, L5612-5613 (three locations); LV-PERF-004 `department_id` bug confirmed at L6348-6350 |
| `includes/Modules/Leave/LeaveModule.php` (workflow/REST/admin) | `06-02-leave-workflow-findings.md` | Manual code review | VERIFIED | LV-WF-001 confirmed: handle_approve() at L1057 starts with `check_admin_referer` but no `require_cap` (vs handle_reject() at L1694-1695 which does call `Helpers::require_cap`); LV-WF-005 is_hr_user() at L2575-2584 confirmed with sfs_hr_manager role branch and administrator bypass |

---

### Requirements Coverage

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| CRIT-02 | 06-01-PLAN.md, 06-02-PLAN.md | Audit Leave module (~7.7K lines) — security, performance, duplication, logical issues | SATISFIED | 35 total findings across both reports: 6 Critical, 13 High, 11 Medium, 5 Low. All four audit categories (security, performance, duplication, logical) covered. REQUIREMENTS.md traceability table marks CRIT-02 as Complete for Phase 6. |

No orphaned requirements — only CRIT-02 is mapped to Phase 6 in REQUIREMENTS.md and both plans claim it.

---

### Anti-Patterns Found

No production code was modified in this phase (audit-only). Both plans' `files_modified` fields list only the findings reports. No anti-patterns to scan for in the produced artifacts.

---

### Human Verification Required

None. This phase produces documentation artifacts (findings reports), not functional code. Goal achievement is fully verifiable by inspecting the reports against the source files.

---

## Finding Counts Cross-Check

| Report | Claimed Total | Verified Heading-4 Blocks | Match |
|--------|--------------|--------------------------|-------|
| 06-01 (services/balance) | 19 findings | 24 heading-4 blocks (including section-level headers like "### Critical") | Consistent — 19 uniquely-numbered LV-* findings |
| 06-02 (workflow/approval) | 16 findings | 21 heading-4 blocks | Consistent — 16 uniquely-numbered LV-* findings |
| **Combined** | **35 findings** | — | Covers all four audit categories from the phase goal |

---

## Evidence Spot-Checks Passed

The following findings were directly verified against live source code:

1. **LV-CALC-001 (tenure boundary):** `$as_of = strtotime($year . '-01-01')` at LeaveCalculationService.php:L43 — confirmed. Jan 1 evaluation confirmed. Mid-year anniversary promotions are indeed missed.

2. **LV-CALC-002 (opening/carried_over overwrite):** `$opening = 0; $carried = 0;` at LeaveModule.php:L1630-1631, L2137-2138, and L5612-5613 — all three locations confirmed. Three distinct upsert blocks all hardcode these zeros.

3. **LV-CALC-003 (accrued=0 fallback):** Double override at LeaveCalculationService.php:L75-76 — `isset($row['accrued']) ? ... : $annual_quota` followed immediately by `if ((int)($row['accrued'] ?? 0) === 0) $accrued = (int)$annual_quota` — confirmed.

4. **LV-SEC-001 / TOCTOU (has_overlap):** Plain `SELECT COUNT(*) FROM ... WHERE employee_id=%d AND status IN ('pending','approved')` at LeaveCalculationService.php:L86-94, no transaction or lock — confirmed.

5. **LV-WF-001 (handle_approve missing cap guard):** `public function handle_approve(): void { check_admin_referer('sfs_hr_leave_approve');` at LeaveModule.php:L1057-1058 — no `require_cap` call. Contrast with handle_reject() at L1694-1695 which has `Helpers::require_cap('sfs_hr.leave.review')` — confirmed asymmetry.

6. **LV-WF-005 / LV-SEC-004 (is_hr_user sfs_hr_manager branch):** `if ($user && in_array('sfs_hr_manager', (array)$user->roles, true)) { return true; }` at LeaveModule.php:L2581-2583 — confirmed. `administrator` bypass also at L2584.

7. **LV-PERF-004 (wrong column name):** `AND department_id = %d` at LeaveModule.php:L6350 — confirmed bug; actual column is `dept_id` as used in all other queries in the file.

8. **LV-PERF-001 (broadcast_holiday_added unbounded mailer):** `$emails = $wpdb->get_col("SELECT email FROM $emp_t WHERE status='active' AND email<>''")` followed by `foreach($emails as $to) { Helpers::send_mail(...) }` at LeaveModule.php:L5150-5187 — confirmed synchronous loop.

9. **class-leave-ui.php clean:** No `$wpdb` calls and no `$_POST`/`$_GET` in 105-line file — confirmed.

---

## Gaps Summary

None. All phase must-haves are verified.

---

_Verified: 2026-03-16T04:00:00Z_
_Verifier: Claude (gsd-verifier)_
