---
phase: 05-attendance-audit
plan: 01
subsystem: audit
tags: [attendance, session, shift, policy, cron, migration, sql-injection, race-condition, performance]

# Dependency graph
requires: []
provides:
  - "24-finding audit report for Attendance services, cron, and migration layer"
  - "Race condition analysis of GET_LOCK-based session recalculation guard"
  - "N+1/N+5 query storm documentation for shift resolution in cron context"
  - "Security finding: unauthenticated ajax_dbg debug endpoint"
affects:
  - "05-02 (Attendance REST/Admin audit — will reference SEC-001 nopriv endpoint)"
  - "Phase 06 (Leave audit — leave/holiday guard in Session_Service crosses module boundary)"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Audit finding IDs: ATT-SVC-{SEC|PERF|DUP|LOGIC}-NNN with file:line references"
    - "Severity tiers: Critical / High / Medium / Low per phase audit convention"

key-files:
  created:
    - ".planning/phases/05-attendance-audit/05-01-attendance-services-findings.md"
  modified: []

key-decisions:
  - "Session_Service uses MySQL GET_LOCK for per-employee recalc serialization — correct approach but deferred events are not also lock-guarded"
  - "Daily_Session_Builder uses gmdate() (UTC) for date targeting, inconsistent with site-local session keys — flagged as High logic bug"
  - "rebuild_sessions_for_date_static processes ALL active employees synchronously — correct behavior but requires batching for scale"
  - "Early leave auto-creation correctly idempotent per employee-date but rejected records block re-creation — documented as intentional"

patterns-established:
  - "Audit findings use ATT-SVC-{CATEGORY}-NNN IDs per plan template"
  - "Every finding has: severity, file:line, description, evidence snippet, fix recommendation"

requirements-completed:
  - CRIT-01

# Metrics
duration: 30min
completed: 2026-03-16
---

# Phase 5 Plan 01: Attendance Services/Cron/Migration Audit Summary

**24-finding security, performance, duplication, and logic audit of Attendance's 10-file business logic layer — including GET_LOCK race condition analysis, N+5 query storm in shift resolution, and unauthenticated debug endpoint (nopriv)**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-03-16T12:46:33Z
- **Completed:** 2026-03-16T13:16:00Z
- **Tasks:** 2 (read+audit all 10 files; write findings report)
- **Files modified:** 1

## Accomplishments

- Read and audited all 10 PHP files in the Attendance services/cron/migration scope (~3,866 lines total)
- Every `$wpdb` call evaluated — all service queries use `$wpdb->prepare()` correctly; migration layer has 3 inconsistent bare `ALTER TABLE` calls flagged
- Session punch-in/out race condition analyzed: `GET_LOCK` approach is sound but deferred recalc events are not themselves lock-guarded (TOCTOU gap documented as Critical)
- Identified 4 separate employee dept lookups in a single `resolve_shift_for_date()` call — N+5 query pattern documented with concrete fix
- Timezone bug found in `Daily_Session_Builder` (uses `gmdate()` UTC instead of `wp_date()` site-local) — sessions are keyed by site-local date, creating a ~3-hour nightly window of incorrect targeting
- `get_all_policies()` N+1 query pattern documented with fix
- `Period_Service::get_current_period` uses `date()` (server TZ) not `wp_date()` (site TZ) — period boundaries can drift

## Task Commits

1. **Task 1 + Task 2: Read all 10 files and write audit findings report** - `ddab9c7` (feat)

## Files Created/Modified

- `.planning/phases/05-attendance-audit/05-01-attendance-services-findings.md` — 447-line findings report with 24 findings across Security (4), Performance (7), Duplication (5), Logic (8) categories; all 10 files in "Files Reviewed" table

## Decisions Made

- `ajax_dbg` nopriv endpoint is a clear security issue (Critical): any unauthenticated user can POST to it and write to error_log; fix is removal of the nopriv hook
- The `GET_LOCK` + deferred cron pattern is sound in principle but needs the deferred handler to also acquire a lock; documented as Critical not because data corruption is certain, but because it is possible under load
- `rebuild_sessions_for_date_static` processing all active employees is architecturally correct (absent employees need sessions too) but needs batching — documented as Critical/High because it will cause PHP timeouts on real production installations
- `maybe_create_early_leave_request` rejecting re-creation when a rejected record exists is plausibly intentional; documented as Medium with recommendation to clarify

## Deviations from Plan

None — plan executed exactly as written. Both tasks (read+audit, write report) completed atomically in one commit.

## Issues Encountered

None — all 10 files were accessible and readable. Session_Service.php (1034 lines) required paginated reading due to output size limit.

## User Setup Required

None — no external service configuration required. This is an audit-only plan.

## Next Phase Readiness

- Findings report is ready for use by the fix phase (when v1.2 scope begins)
- Key cross-module finding: `AttendanceModule::is_blocked_by_leave_or_holiday()` calls into `LeaveCalculationService` — Phase 06 (Leave audit) should review this boundary
- The nopriv `ajax_dbg` endpoint (ATT-SVC-SEC-001) should be prioritized in any immediate hotfix pass

---
*Phase: 05-attendance-audit*
*Completed: 2026-03-16*
