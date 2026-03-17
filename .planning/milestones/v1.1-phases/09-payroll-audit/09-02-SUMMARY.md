---
phase: 09-payroll-audit
plan: 02
subsystem: audit
tags: [payroll, security, audit, admin-pages, export, wpdb, capability-checks]

# Dependency graph
requires:
  - phase: 09-payroll-audit
    provides: "09-01 PayrollModule/REST audit findings for cross-reference"
provides:
  - "Payroll Admin_Pages.php full audit findings (24 findings)"
  - "Export/report generation security evaluation (Success Criteria #4)"
  - "Department data leakage risk evaluated"
  - "PADM-SEC-001 Critical: attendance export accessible to all employees"
affects:
  - 10-settlement-audit
  - future-fix-payroll

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Status display via whitelist map (not ucfirst on DB values)"
    - "Atomic UPDATE-then-check pattern for TOCTOU-safe approval"
    - "Pre-fetch GROUP BY for N+1 attendance query elimination"

key-files:
  created:
    - .planning/phases/09-payroll-audit/09-02-payroll-admin-findings.md
  modified: []

key-decisions:
  - "PADM-SEC-001 Critical: handle_export_attendance() uses sfs_hr.view — any employee can export all-organization attendance data; fix: raise to sfs_hr.manage"
  - "PADM-PERF-001 High: N+1 per-employee attendance query in handle_export_detailed() — fix: single GROUP BY pre-fetch"
  - "PADM-LOGIC-001 High: handle_approve_run() payslip generation TOCTOU race — fix: atomic UPDATE WHERE status='review'"
  - "PADM-DUP-001 High: components_json split logic duplicated in 6 locations — extract parse_components() helper"
  - "PADM-LOGIC-002 High: sfs_hr_payroll_run capability not registered/assigned anywhere — dead permission slot"
  - "PADM-SEC-007 Medium: Content-Disposition headers missing quotes on all 4 export handlers"
  - "Export auth gates: bank/WPS/detailed exports correctly require sfs_hr.manage; only attendance export is under-gated"

patterns-established:
  - "Atomic approval pattern: UPDATE WHERE status='expected' then check affected rows to eliminate TOCTOU"
  - "Export capability gate: all financial exports should require sfs_hr_payroll_admin or sfs_hr.manage minimum"
  - "components_json split: shared parse_components() helper prevents 6-way duplication"

requirements-completed:
  - FIN-01

# Metrics
duration: 25min
completed: 2026-03-16
---

# Phase 09 Plan 02: Payroll Admin Pages Audit Summary

**Payroll Admin_Pages.php (2,576 lines) audited: 24 findings including one Critical export auth bypass allowing any employee to download full organization-wide attendance data**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-03-16T05:36:42Z
- **Completed:** 2026-03-16T06:01:00Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- Audited all 2,576 lines of `Admin_Pages.php` across 4 metrics (security, performance, duplication, logical)
- Identified Critical: `handle_export_attendance()` capability gate is `sfs_hr.view` (every employee), allowing full org attendance data export
- Identified N+1 query pattern in `handle_export_detailed()` — 1 DB query per employee for attendance summary; batch fix documented
- Confirmed TOCTOU race in payslip generation on concurrent approval; atomic UPDATE-WHERE fix documented
- Evaluated all 4 export handlers for auth bypass, output escaping, data leakage, and nonce validation
- Confirmed: bank, WPS, and detailed exports are correctly gated; no bookmarkable/GET bypass exists

## Task Commits

1. **Tasks 1 + 2: Audit Admin_Pages.php and write findings report** - `2a024be` (feat)

**Plan metadata:** (see below — final commit)

## Files Created/Modified

- `.planning/phases/09-payroll-audit/09-02-payroll-admin-findings.md` — 24 findings across security (8), performance (5), duplication (5), logical (6) categories

## Decisions Made

- Critical finding: attendance export under-gated — any employee with `sfs_hr.view` can export full org attendance
- The `sfs_hr_payroll_run` capability appears unregistered in the codebase — the "Run Payroll" button may be hidden for all non-manage users, making the capability a dead slot
- `handle_approve_run()` relies on a single-read status check with no atomicity guarantee — TOCTOU-safe approval pattern documented
- `components_json` parsing is duplicated 6 times — a shared `parse_components()` helper is the correct fix
- All 4 `Content-Disposition` headers across export handlers are missing quotes around the filename (RFC 6266 violation)

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## Next Phase Readiness

- Phase 09 is complete: PayrollModule + REST (09-01) and Admin_Pages (09-02) both audited, FIN-01 satisfied
- Phase 10 (Settlement audit) can proceed — no blockers
- Key cross-cutting fix to track: `handle_export_attendance()` cap gate should be patched in a future fix phase before any public deployment

---
*Phase: 09-payroll-audit*
*Completed: 2026-03-16*
