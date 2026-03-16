---
phase: 08-loans-audit
plan: 01
subsystem: loans
tags: [php, wpdb, sql-injection, loans, installments, payroll, notifications]

requires:
  - phase: 07-performance-audit
    provides: audit patterns and finding format established in Performance module review

provides:
  - "19 categorized findings (2 Critical, 8 High, 6 Medium, 3 Low) for Loans orchestrator, frontend, and notifications"
  - "Critical cross-module bug: PayrollModule queries l.monthly_installment (column does not exist — loan deductions silently fail on every payroll run)"
  - "Critical SQL: unprepared SHOW TABLES and bare ALTER TABLE in LoansModule admin_init path"
  - "High IDOR-structure risk: employee_id read from POST before nonce verification in both admin_post and AJAX handlers"
  - "High logic: frontend and admin installment calculation formulas diverge — installment_amount * count != principal in many cases"
  - "High N+1: paid-count queries in render_loans_list and render_admin_loans_view"
  - "CRIT-04 requirement satisfied: Loans module installment arithmetic edge cases and cross-module Payroll interaction documented"

affects: [09-payroll-audit, 10-settlement-audit, 08-02-admin-rest-audit]

tech-stack:
  added: []
  patterns:
    - "Installment calculation: employee portal uses floor(principal/monthly)+remainder vs admin uses round(principal/count) — these produce different stored values"
    - "Fiscal year one-loan check uses TOCTOU read-then-insert with no transaction guard"
    - "Cross-plugin filter namespace leak: dofs_ filters in Loans notifications"

key-files:
  created:
    - .planning/phases/08-loans-audit/08-01-loans-services-findings.md
  modified: []

key-decisions:
  - "LOAN-LOGIC-002 (Critical runtime bug): PayrollModule.php:570 references l.monthly_installment which does not exist in sfs_hr_loans schema — loan deductions silently return NULL on every payroll run; remaining_balance is never updated by Payroll"
  - "LOAN-SEC-001/002: unprepared SHOW TABLES LIKE and bare ALTER TABLE DDL in LoansModule.php admin_init/activation paths — same antipattern flagged Critical in Phase 04 Core audit"
  - "LOAN-SEC-003/004: nonce action string built from POST-supplied employee_id before server verifies ownership — structurally unsound even though downstream ownership check prevents actual IDOR"
  - "LOAN-DUP-001: validation logic is duplicated between frontend validate_and_insert_loan and admin add-loan handler; admin path skips fiscal year and min-service-months constraints"
  - "LOAN-SEC-005: send_notification uses dofs_ filter namespace — violates project boundary rule against cross-plugin dependencies"

patterns-established:
  - "Loans module has no REST endpoints — all operations through admin-post and wp_ajax handlers, same as Leave module"

requirements-completed: [CRIT-04]

duration: 3min
completed: 2026-03-16
---

# Phase 8 Plan 1: Loans Services, Frontend, and Notifications Audit Summary

**19 findings across 3 files: Critical Payroll column-mismatch bug silently nullifies all loan deductions, plus unprepared DDL, nonce-IDOR structure, N+1 queries, and duplicated installment calculation formulas between frontend and admin paths**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-16T03:50:40Z
- **Completed:** 2026-03-16T03:54:23Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- Identified LOAN-LOGIC-002 (High): PayrollModule.php references `l.monthly_installment` — a column that does not exist in `sfs_hr_loans` (the correct column is `installment_amount`). This silently nullifies all loan deductions in every payroll run. Remaining balances are never reduced, loans never complete. This satisfies CRIT-04.
- Identified LOAN-SEC-001/002 (Critical): 14 unprepared `SHOW TABLES LIKE` and `SHOW COLUMNS FROM` calls, plus 5 bare `ALTER TABLE` DDL statements in `LoansModule::check_tables_notice()`, `maybe_upgrade_db()`, and `on_activation()` — same antipattern as Phase 04 Core audit Critical finding.
- Documented installment calculation divergence (LOAN-DUP-001 / LOAN-LOGIC-001): frontend stores `installment_amount` from employee-requested monthly amount while admin finance-approval path recalculates `round(principal/installments)` — these values differ, causing `installment_amount * installments_count != principal_amount` for many loans.
- Confirmed all `$wpdb->prepare()` usage in actual data queries (not schema inspection) is correct — no SQL injection risk in SELECT/INSERT/UPDATE operations.

## Task Commits

1. **Task 1+2: Read and audit all 3 files, write findings report** - `fc5ce3f` (feat)

## Files Created/Modified

- `.planning/phases/08-loans-audit/08-01-loans-services-findings.md` - Full audit findings: 19 findings with severity ratings, file:line references, and fix recommendations. Includes $wpdb inventory table.

## Decisions Made

- PayrollModule column-mismatch (LOAN-LOGIC-002) is a runtime data-integrity bug, not just an audit finding — the Payroll phase (09) must remediate this.
- Nonce-before-ownership-check issue (LOAN-SEC-003/004) does not constitute an exploitable IDOR in isolation because the ownership SQL query immediately follows, but the pattern is structurally unsound and should be fixed.
- Admin loan creation path skips fiscal-year and min-service validations that the employee portal enforces — this is a behavioral inconsistency documented in LOAN-DUP-002, not a security vulnerability, but it allows policy bypass by admins.

## Deviations from Plan

None — plan executed exactly as written. All 3 files audited, findings file created with all required sections.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Phase 08 Plan 02 (Loans Admin and REST audit) can proceed — additional context: the Admin pages class contains `generate_payment_schedule()` which does not adjust the last installment for rounding; this creates a sum mismatch unless the last row absorbs the penny difference.
- Phase 09 (Payroll audit) should prioritize verifying the `monthly_installment` vs `installment_amount` column name mismatch as its first task.

---
*Phase: 08-loans-audit*
*Completed: 2026-03-16*
