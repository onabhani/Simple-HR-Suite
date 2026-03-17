---
phase: 08-loans-audit
plan: 02
subsystem: audit
tags: [loans, security, performance, sql, nonce, csrf, pagination]

# Dependency graph
requires:
  - phase: 08-loans-audit
    provides: Plan 01 findings for Loans services/handler/cron — LOAN- prefix findings

provides:
  - Loans admin pages audit: 25 findings (2 Critical, 9 High, 10 Medium, 4 Low) — LADM- prefix
  - Financial write action auth table: all approve/reject/cancel/create actions verified for nonce + capability
  - Security gaps identified: nonce-in-data-attribute on installment forms, CSV export missing nonce
  - Performance gaps: unbounded loan list query, 8 uncached dashboard widget queries per admin load
  - Logical gaps: Finance approval can exceed GM-approved amount, cancel balance inconsistency, admin bypasses max_loan_amount setting

affects:
  - 08-03 (if exists — Phase 08 remaining plans)
  - 09-payroll-audit (Payroll reads loan installments, LOAN-LOGIC-002 cross-reference)
  - Any fix phase for Loans module

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Installment nonce-in-data-attribute pattern confirmed as LADM-SEC-002 (Critical)"
    - "Admin generates schedules with round() vs frontend floor() — LADM-DUP-001 confirmed"
    - "All financial state transitions (approve/reject/cancel) use atomic UPDATE WHERE status = 'expected' guard"

key-files:
  created:
    - .planning/phases/08-loans-audit/08-02-loans-admin-findings.md
  modified: []

key-decisions:
  - "LADM-SEC-002: nonce embedded in data-nonce HTML attribute on installment rows — Critical, allows DOM-reading scripts to forge requests"
  - "LADM-SEC-008: CSV export endpoint has no nonce — any GET request by authenticated admin can trigger financial data export"
  - "LADM-PERF-001: loan list is fully unbounded — no pagination at all in a 3066-line admin file"
  - "LADM-LOGIC-001: Finance approval allows principal amount higher than originally requested or GM-approved — no upper bound"
  - "LADM-LOGIC-006: Admin always creates loans as pending_gm even when require_gm_approval=false — loans get stranded"
  - "All atomic UPDATE WHERE status guards confirmed: approve_gm/approve_finance/reject/cancel all use AND status='expected' to prevent race conditions and double-processing"

requirements-completed:
  - CRIT-04

# Metrics
duration: 3min
completed: 2026-03-16
---

# Phase 08 Plan 02: Loans Admin Pages & Dashboard Widget Audit Summary

**25 findings across admin pages (3066 lines) and dashboard widget — Critical: nonce-in-data-attribute on installment forms and missing nonce on CSV export; all financial write state transitions confirmed nonce+capability protected with atomic status guards**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-16T04:16:38Z
- **Completed:** 2026-03-16T04:19:38Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- Audited all $wpdb queries in class-admin-pages.php and class-dashboard-widget.php — 7 unprepared queries identified across both files (all table-name-constant only, no injection risk, but violate mandatory prepare() rule)
- Verified every financial write action (approve_gm, approve_finance, reject_loan, cancel_loan, create_loan, mark_installment_paid/partial/skipped) for nonce + capability — all confirmed except CSV export
- Identified Critical installment nonce pattern: nonces embedded in data-nonce HTML attributes and populated by JavaScript, exposing them to DOM-reading scripts
- Confirmed admin installment forms use `action=""` (current page, admin_init handler) while loan actions correctly use admin-post.php — inconsistent patterns
- Found loan list has zero pagination — all loans loaded on every page view; dashboard widget runs 8 uncached queries on every admin load
- Finance approval can set principal to any amount (even higher than requested); admin create_loan skips max_loan_amount and allow_multiple_active_loans settings
- All state-transition UPDATE queries confirmed to include `AND status='expected_state'` guards (atomic CAS pattern — correct approach to prevent race conditions and double-processing)

## Task Commits

1. **Task 1 + 2: Audit and write findings report** - `9e47e54` (feat)

## Files Created/Modified

- `.planning/phases/08-loans-audit/08-02-loans-admin-findings.md` — 25 findings with LADM- IDs, severity ratings, file:line references, fix recommendations, and financial write action auth summary table

## Decisions Made

- All financial write state transitions confirmed to use atomic `AND status='current'` guards — this is a strong pattern that prevents double-approval race conditions; document as a positive finding in Phase 08 overall report
- Installment nonce pattern (data-nonce attribute) is a systemic design choice not a one-off bug — affects all installment row actions (mark paid, partial, skipped)

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 08 (Loans) is complete: Plan 01 audited services/handler/cron (LOAN- prefix, 3 findings from STATE.md summary), Plan 02 audited admin pages/dashboard widget (LADM- prefix, 25 findings)
- Both audited files are referenced in findings with file:line references
- Ready to advance to Phase 09 (Payroll audit)
- LOAN-LOGIC-002 (Payroll reads l.monthly_installment which doesn't exist) cross-references Phase 09

---
*Phase: 08-loans-audit*
*Completed: 2026-03-16*
