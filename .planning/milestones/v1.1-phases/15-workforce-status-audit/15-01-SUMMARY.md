---
phase: 15-workforce-status-audit
plan: 01
subsystem: audit
tags: [workforce-status, dashboard, admin-pages, security, performance, duplication, n+1, sql]

# Dependency graph
requires:
  - phase: 14-resignation-audit
    provides: audit findings format and cross-reference baseline for module series
provides:
  - WorkforceStatusModule orchestrator confirmed clean (no DDL antipatterns)
  - Admin_Pages.php fully audited: 16 findings across security/performance/duplication/logical
  - $wpdb call-accounting table: 6 calls catalogued with prepared/unprepared status
  - Status_Analytics.php flagged as dead code
affects:
  - 15-02 (next plan in phase — Absent_Cron and Absent_Notifications audit)
  - any future WorkforceStatusModule refactor

# Tech tracking
tech-stack:
  added: []
  patterns:
    - All 16 findings follow the established WFS-*/WADM-* ID format from prior phases

key-files:
  created:
    - .planning/phases/15-workforce-status-audit/15-01-workforce-admin-findings.md
  modified: []

key-decisions:
  - "WADM-SEC-001 High: sfs_hr_attendance_view_team is the wrong capability for the workforce dashboard menu -- should use sfs_hr.workforce.view_team or sfs_hr.manage"
  - "WADM-PERF-002 High: N+1 shift resolver loop -- one AttendanceModule::resolve_shift_for_date() call per employee; fix requires batch resolver in AttendanceModule"
  - "WADM-LOGIC-001 High: Friday-only fallback misses Saudi Saturday (same bug as Phase 09 PAY-LOGIC-001)"
  - "WADM-DUP-001 Medium: is_holiday() duplicated between Admin_Pages and Absent_Notifications -- extract to Core\\Helpers"
  - "WADM-DUP-002 Medium: manager_dept_ids_for_current_user() is acknowledged copy of Core\\Admin -- same recommendation as Phase 09 and Phase 12"
  - "WFS-ORCH-001: WorkforceStatusModule bootstrap is clean -- no ALTER TABLE, no SHOW TABLES, no information_schema"
  - "WFS-SVC-001 Low: Status_Analytics.php is an empty file (dead code) -- either implement or delete"

patterns-established:
  - "Conditional prepared/unprepared branching (static query path) flagged as Medium even when safe -- use prepare() always per project conventions"
  - "paginate_links() raw echo flagged as Medium -- wrap with wp_kses_post() for explicit escaping intent"

requirements-completed: [MED-05]

# Metrics
duration: 3min
completed: 2026-03-16
---

# Phase 15 Plan 01: WorkforceStatusModule Admin Findings

**16-finding audit of WorkforceStatusModule (orchestrator + Admin_Pages + dead Status_Analytics): 0 Critical, 5 High across N+1 shift loop, unbounded employee load, wrong capability, Saudi weekend fallback miss**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-16T19:22:32Z
- **Completed:** 2026-03-16T19:25:32Z
- **Tasks:** 2
- **Files modified:** 1 (created)

## Accomplishments

- Audited all 3 Workforce_Status module files (~1368 lines) across security, performance, duplication, and logical dimensions
- Produced $wpdb call-accounting table for Admin_Pages.php (6 total calls, 4 unconditionally prepared, 2 conditionally prepared with static queries)
- Confirmed WorkforceStatusModule orchestrator is clean: no DDL antipatterns, no information_schema, no bare ALTER TABLE
- Identified 5 High findings including N+1 shift resolver loop, unbounded employee load, wrong capability constant, and Saudi Saturday weekend miss
- Flagged Status_Analytics.php as dead code (empty file)
- Cross-referenced 8 findings against prior phases (Phase 04, 05, 07, 09, 11, 12)

## Task Commits

1. **Task 1 + Task 2: Read/audit and write findings report** - `8faf0cd` (feat)

## Files Created/Modified

- `.planning/phases/15-workforce-status-audit/15-01-workforce-admin-findings.md` - 16 findings with WFS-*/WADM-* IDs, severity ratings, fix recommendations, $wpdb call-accounting table, cross-references

## Decisions Made

- WADM-SEC-001 High: `sfs_hr_attendance_view_team` is an Attendance-module capability string used as the Workforce Status access gate -- wrong capability, should be `sfs_hr.workforce.view_team`
- WADM-PERF-001 High: No LIMIT on employee query -- full-table load then PHP-side pagination; all 4 sub-queries (punches, leave, risk, shifts) use the full employee ID list as IN parameters
- WADM-PERF-002 High: N+1 in `get_employee_shifts_map()` -- `AttendanceModule::resolve_shift_for_date()` called once per employee in a loop; same pattern as Phase 07 Critical N+1
- WADM-PERF-003 High: `is_holiday()` called 501 times per page view for 500 employees (once globally + once per employee in `is_working_day_for_employee()`)
- WADM-LOGIC-001 High: Fallback path uses `is_friday()` (day-of-week === 5 only); Saudi weekend is Friday+Saturday; `Absent_Notifications.is_employee_day_off()` reads `weekly_off_days` setting correctly but this fallback does not
- WADM-DUP-001/002 Medium: `is_holiday()` and `manager_dept_ids_for_current_user()` are acknowledged duplicates; third/fourth instances in the audit series

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 01 findings complete; ready for Phase 15 Plan 02 (Absent_Cron and Absent_Notifications audit)
- Key context for Plan 02: `is_holiday()` duplication cross-reference already established (WADM-DUP-001); `is_employee_day_off()` in Absent_Notifications correctly uses `weekly_off_days` setting (more correct than Admin_Pages fallback)
- No blockers

---
*Phase: 15-workforce-status-audit*
*Completed: 2026-03-16*
