---
phase: 19-reminders-employeeexit-pwa-audit
plan: 01
subsystem: audit
tags: [reminders, employee-exit, cron, notifications, birthday, anniversary, settlement, resignation]

# Dependency graph
requires:
  - phase: 18-departments-surveys-projects-audit
    provides: Prior audit findings establishing recurring antipattern baseline for Phase 19 comparison

provides:
  - Reminders module security/performance/duplication/logical audit findings (5 files, ~915 lines)
  - EmployeeExit module security/performance/duplication/logical audit findings (2 files, ~490 lines)
  - Settlement overlap analysis confirming EmployeeExit has no EOS formula duplication
  - Complete $wpdb call catalogue for all 7 files (12 queries, all prepared)

affects: [19-02-pwa-audit, v1.2-fixes]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - information_schema antipattern 7th recurrence — same fix applies (SHOW COLUMNS LIKE or transient cache)
    - cross-plugin dofs_ filter namespace violation — 2nd recurrence after Phase 14

key-files:
  created:
    - .planning/phases/19-reminders-employeeexit-pwa-audit/19-01-reminders-employeeexit-findings.md
  modified: []

key-decisions:
  - "EmployeeExitModule has no EOS calculation logic -- zero UAE formula duplication; Phase 10 SETT-LOGIC-001 scope is Settlement module only"
  - "EX-SEC-001 Critical: sfs_hr.view gates full resignation+settlement hub -- financial settlement data exposed to dept managers; fix requires sfs_hr.manage gate"
  - "REM-PERF-001 High: get_upcoming_birthdays() fires one query per offset (8 queries per call) -- fix with IN() predicate over MM-DD list"
  - "Both modules clean of bare ALTER TABLE and unprepared SHOW TABLES (same clean pattern as Phase 14 Resignation)"
  - "Digest queue in Reminders cron (queue_for_digest) is dead code -- no consumer processes queued notifications"

patterns-established: []

requirements-completed:
  - SML-04
  - SML-05

# Metrics
duration: 4min
completed: 2026-03-17
---

# Phase 19 Plan 01: Reminders + EmployeeExit Audit Summary

**17 findings across 7 files (1,405 lines): 1 Critical (sfs_hr.view gates financial data hub), 7 High, 5 Medium, 4 Low — zero EOS formula duplication in EmployeeExit; all $wpdb calls properly prepared**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-17T02:27:54Z
- **Completed:** 2026-03-17T02:31:12Z
- **Tasks:** 1/1
- **Files modified:** 1

## Accomplishments

- Catalogued all 12 $wpdb calls across all 7 files — every call is properly prepared via `$wpdb->prepare()` or `$wpdb->insert()`, zero raw interpolation found
- Confirmed EmployeeExit module contains no end-of-service calculation logic — Phase 10 SETT-LOGIC-001 (UAE 21-day formula) scope remains Settlement module only, no duplication in EmployeeExit
- Identified EX-SEC-001 Critical: `sfs_hr.view` capability gates the full Employee Exit admin hub, allowing dept managers to access all-org resignation records and financial settlement data
- Identified REM-PERF-001 High: dashboard widget + count badge together issue 32 separate queries against the employees table per page load — fixable by consolidating to IN() predicate queries
- Confirmed both modules are clean of bare ALTER TABLE and unprepared SHOW TABLES — continuation of the clean pattern first seen in Phase 14 Resignation module

## Task Commits

1. **Task 1: Read and audit Reminders and EmployeeExit modules** — `352944a` (feat)

**Plan metadata:** (docs commit follows)

## Files Created/Modified

- `.planning/phases/19-reminders-employeeexit-pwa-audit/19-01-reminders-employeeexit-findings.md` — Full audit findings with $wpdb catalogue, 17 findings across security/performance/duplication/logical categories, Settlement overlap analysis, cross-module antipattern scorecard

## Decisions Made

- EmployeeExitModule is confirmed to be a pure orchestrator — it delegates to Resignation and Settlement modules with no own calculations. The UAE formula bug (Phase 10) is isolated in Settlement_Service only.
- EX-SEC-001 rated Critical (not High) because `sfs_hr.view` exposes financial settlement data (EOS amounts, resignation details) to dept managers who should only see their own department's data.
- Digest queue in Reminders cron (`queue_for_digest` / `sfs_hr_notification_digest_queue`) is dead code — there is no cron or handler that processes the queue and delivers the accumulated digest messages.

## Deviations from Plan

None — plan executed exactly as written. All 7 files audited, all 4 metrics (security, performance, duplication, logical) addressed for both modules, Settlement overlap section written with concrete comparison, every finding has severity + fix + file:line reference.

## Issues Encountered

None.

## User Setup Required

None — audit plan, no external service configuration required.

## Next Phase Readiness

- Phase 19-02 (PWA module audit) is ready to begin — PWA module is flagged as stub/incomplete in CLAUDE.md
- Key fix priorities established for v1.2: EX-SEC-001 (capability gate on hub page), REM-PERF-001 (IN() batch query), REM-SEC-001 (information_schema → SHOW COLUMNS), EX-PERF-001 (LIMIT on contracts queries)

---
*Phase: 19-reminders-employeeexit-pwa-audit*
*Completed: 2026-03-17*
