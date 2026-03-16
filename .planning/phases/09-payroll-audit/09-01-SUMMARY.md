---
phase: 09-payroll-audit
plan: 01
subsystem: payroll
tags: [payroll, sql, rest-api, security, performance, audit, loans, attendance]

# Dependency graph
requires:
  - phase: 08-loans-audit
    provides: LOAN-LOGIC-002 finding — monthly_installment column mismatch — confirmed in this audit
provides:
  - Payroll orchestrator and REST endpoint audit findings with 15 categorized issues
  - Confirmed Critical: loan deductions silently zero on every payroll run (wrong column name)
  - Confirmed High: Saudi weekend (Fri+Sat) only partially excluded, understating absence deductions
  - PAY-DUP-001 through PAY-LOGIC-005 findings with fix recommendations
affects: [10-settlement-audit, fix-payroll-loan-deductions, fix-payroll-working-days]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Audit-only: findings documented without code changes"
    - "All $wpdb calls individually accounted for in a call-accounting table"
    - "REST permission_callback matrix documented for each endpoint"

key-files:
  created:
    - .planning/phases/09-payroll-audit/09-01-payroll-services-findings.md
  modified: []

key-decisions:
  - "PAY-DUP-001 Critical confirmed: PayrollModule.php queries l.monthly_installment which does not exist — actual column is installment_amount — loan deductions zero on every payroll run since module creation"
  - "PAY-LOGIC-001 High: count_working_days() excludes only Friday, missing Saturday (Saudi weekend is Fri+Sat) — understates absence deductions by ~15%"
  - "PAY-PERF-001 High: payroll run processes all employees in single unbounded HTTP request — PHP timeout risk at 500+ employees"
  - "PAY-LOGIC-003 Medium: transient-based payroll lock has TOCTOU race — recommend MySQL GET_LOCK() same as Attendance module"
  - "PAY-SEC-002 High: my-payslips REST endpoint uses is_user_logged_in() as fallback permission — should use explicit HR capability"
  - "No __return_true REST endpoints found — all routes have real permission callbacks"
  - "Payroll run capability and nonce guards in Admin_Pages.php are correct — no Critical auth issues in run/approve flow"
  - "Net salary floor (max 0) and end-of-loop rounding are correct patterns — positive observations documented"

patterns-established:
  - "Call-accounting table: enumerate every $wpdb call with line, method, prepare status, and disposition"
  - "REST permission matrix: table documenting each endpoint's permission_callback and any scope gaps"

requirements-completed: [FIN-01]

# Metrics
duration: 4min
completed: 2026-03-16
---

# Phase 9 Plan 01: Payroll Orchestrator and REST Audit Summary

**15 findings (1 Critical, 4 High, 7 Medium, 3 Low) across PayrollModule.php and Payroll_Rest.php — Critical confirmed: loan deductions silently zero on every payroll run due to wrong column name (`monthly_installment` vs `installment_amount`)**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-16T04:50:29Z
- **Completed:** 2026-03-16T04:54:16Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- Confirmed Phase 08 finding LOAN-LOGIC-002: `monthly_installment` column does not exist — every employee with an active loan has received zero loan deduction on every historical payroll run
- Identified PAY-LOGIC-001 (High): `count_working_days()` excludes only Friday, not Saturday — Saudi weekend is both days, causing ~15% understatement of daily deduction rates
- Audited all 12 `$wpdb` calls across PayrollModule.php and Payroll_Rest.php — no SQL injection vulnerabilities found; one column-name bug (Critical) and two pattern violations documented
- Verified all 6 REST endpoints have real `permission_callback` values — no `__return_true` present; one too-broad fallback (PAY-SEC-002)
- Confirmed payroll run `handle_run_payroll()` has correct capability guard + nonce validation; transaction wrapping is correct; net salary floor is correct

## Task Commits

1. **Task 1+2: Audit and write findings report** — `c9315a7` (feat)

**Plan metadata:** (this commit)

## Files Created/Modified

- `.planning/phases/09-payroll-audit/09-01-payroll-services-findings.md` — 15 findings with IDs, severity ratings, file:line references, fix recommendations, $wpdb call accounting table, REST permission matrix

## Decisions Made

- LOAN-LOGIC-002 (Phase 08) is confirmed Critical — `l.monthly_installment` does not exist; correct column is `l.installment_amount`; fix is a single-line column rename in the SQL query
- Working-day calculation excludes only Friday — Saturday must also be excluded for Saudi labor law compliance
- Payroll run uses `START TRANSACTION`/`COMMIT`/`ROLLBACK` correctly — the transaction is sound, but `FOR UPDATE` inside `calculate_employee_payroll()` is outside the transaction context when called standalone
- Transient lock is a pattern borrowed from other modules but is not atomically safe — MySQL `GET_LOCK()` is the correct solution (same pattern as Attendance Session_Service)

## Deviations from Plan

None — plan executed exactly as written. Both tasks (audit + write findings) executed as specified. No code changes made (audit-only milestone).

## Issues Encountered

None. Both source files were straightforward to audit. The LOAN-LOGIC-002 confirmation was immediate once the Loans schema was checked (column name `installment_amount` confirmed at LoansModule.php:225).

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Phase 09-02 (Settlement module audit) can proceed immediately
- The Critical finding PAY-DUP-001 should be prioritized for a hotfix before the next payroll run — it is a single-line fix (`monthly_installment` → `installment_amount`) with high financial impact
- PAY-LOGIC-001 (Saturday exclusion) should be fixed alongside PAY-DUP-001 as both affect every payroll calculation

---
*Phase: 09-payroll-audit*
*Completed: 2026-03-16*
