---
phase: 17-shiftswap-audit
plan: "02"
subsystem: audit
tags: [shiftswap, security, sql, rest-api, capability-gates, wpdb]

requires:
  - phase: 17-shiftswap-audit
    provides: "ShiftSwap service layer and module structure audit (17-01)"

provides:
  - "ShiftSwap admin tab and REST endpoints audit findings (17-02-shiftswap-admin-rest-findings.md)"
  - "Complete wpdb call accounting for ShiftSwap module"
  - "REST permission callback evaluation for /shift-swaps routes"

affects:
  - 18-departments-surveys-projects-audit
  - 19-reminders-employeeexit-pwa-audit

tech-stack:
  added: []
  patterns:
    - "Zero direct wpdb calls in admin/REST files — full delegation to service layer (best pattern in audit series)"
    - "Self-service tab ownership gating via user_id comparison instead of capability check"

key-files:
  created:
    - .planning/phases/17-shiftswap-audit/17-02-shiftswap-admin-rest-findings.md
  modified: []

key-decisions:
  - "ShiftSwap admin tab is employee self-service (My Profile), not a manager admin view — absence of current_user_can() is by design, replaced by ownership check"
  - "REST /shift-swaps endpoint returns all-org data without department scoping — Medium finding, same pattern as Assets/Resignation/Documents phases"
  - "Two raw unprepared static queries in service (get_pending_count, get_pending_for_managers) — no injection risk but violate project convention"
  - "TOCTOU race on manager approval confirmed: update_swap_status() uses unconditional WHERE id only — same pattern as Resignation Phase 14"

patterns-established:
  - "Zero wpdb in view/REST files is the gold standard for delegation — ShiftSwap admin tab and REST file both achieve this"

requirements-completed:
  - MED-07

duration: 3min
completed: 2026-03-17
---

# Phase 17 Plan 02: ShiftSwap Admin Tab and REST Endpoints Audit Summary

**ShiftSwap admin tab (self-service) and REST endpoint audit: 15 findings (0 Critical, 3 High, 4 Medium, 8 Low) with best-in-series delegation pattern (zero wpdb in view/REST files) and no __return_true REST routes**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-16T22:05:42Z
- **Completed:** 2026-03-16T22:08:41Z
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments

- Audited `class-shiftswap-tab.php` (317 lines) and `class-shiftswap-rest.php` (60 lines) against all 4 metrics
- Catalogued all 19 `$wpdb` calls in the service layer (17 prepared, 2 raw static); confirmed 0 direct calls in admin tab and REST files
- Evaluated and confirmed REST permission callbacks are correct — no `__return_true` patterns
- Identified architectural clarification: tab is employee self-service, not manager admin view — changes capability gate analysis
- Found 3 High issues carried from Phase 17-01 plus 12 new findings across security, performance, duplication, and logical categories

## Task Commits

1. **Task 1: Read and audit ShiftSwap admin tab and REST endpoints** - `bb19809` (feat)

**Plan metadata:** (pending final commit)

## Files Created/Modified

- `.planning/phases/17-shiftswap-audit/17-02-shiftswap-admin-rest-findings.md` - Audit findings report: 15 findings, wpdb accounting table, summary table

## Decisions Made

- ShiftSwap admin tab is an employee self-service tab (My Profile), not a manager management view — the file name `Admin/class-shiftswap-tab.php` is misleading; actual admin management happens via the Attendance module tab
- Classified two static unprepared queries as Medium (not Low) because they violate the project's stated convention in CLAUDE.md despite having no injection risk
- Carried forward High findings from Phase 17-01 (SS-LOGIC-001 execute_swap not atomic, SS-LOGIC-002 duplicate request guard) as they were also observed in the audited service layer

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 17 (ShiftSwap audit) complete — both plans executed
- Phase 18 (Departments + Surveys + Projects) can begin
- Key cross-module note: REST `/shift-swaps` all-org scoping bug should be tracked alongside similar findings in Assets (Phase 11), Resignation (Phase 14), and Documents (Phase 16) — a systemic pattern requiring a shared fix approach

## Self-Check: PASSED

- FOUND: `.planning/phases/17-shiftswap-audit/17-02-shiftswap-admin-rest-findings.md`
- FOUND: `.planning/phases/17-shiftswap-audit/17-02-SUMMARY.md`
- FOUND: commit `bb19809` (task 1 — findings report)
- FOUND: commit `525692c` (metadata — SUMMARY.md, STATE.md, ROADMAP.md)

---
*Phase: 17-shiftswap-audit*
*Completed: 2026-03-17*
