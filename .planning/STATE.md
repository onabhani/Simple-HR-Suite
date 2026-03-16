---
gsd_state_version: 1.0
milestone: v1.1
milestone_name: Module-by-Module Code Audit
status: roadmap_ready
stopped_at: Completed 07-02-PLAN.md
last_updated: "2026-03-16T03:35:50.944Z"
last_activity: 2026-03-16 — Roadmap created (phases 4-19, 16 phases, 23 requirements mapped)
progress:
  total_phases: 16
  completed_phases: 4
  total_plans: 8
  completed_plans: 8
  percent: 50
---

---
gsd_state_version: 1.0
milestone: v1.1
milestone_name: Module-by-Module Code Audit
status: roadmap_ready
stopped_at: Phase 4 not started — roadmap written, awaiting plan-phase
last_updated: "2026-03-16"
last_activity: 2026-03-16 — Roadmap created for v1.1 (phases 4-19)
progress:
  [█████░░░░░] 50%
  completed_phases: 0
  total_plans: 0
  completed_plans: 0
  percent: 0
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-16)

**Core value:** Identify security, performance, duplication, and logical issues across all modules with actionable findings reports
**Current focus:** Phase 4 — Core + Frontend Audit (not yet started)

## Current Position

Phase: 4 — Core + Frontend Audit
Plan: —
Status: Not started
Last activity: 2026-03-16 — Roadmap created (phases 4-19, 16 phases, 23 requirements mapped)

Progress: `[ ] [ ] [ ] [ ] [ ] [ ] [ ] [ ] [ ] [ ] [ ] [ ] [ ] [ ] [ ] [ ]`  0/16 phases

## Performance Metrics

| Metric | Value |
|--------|-------|
| Phases total | 16 |
| Phases complete | 0 |
| Requirements total | 23 |
| Requirements mapped | 23 |
| Coverage | 100% |
| Phase 04 P02 | 3 | 2 tasks | 1 files |
| Phase 05-attendance-audit P01 | 30 | 2 tasks | 1 files |
| Phase 05-attendance-audit P02 | 28 | 2 tasks | 1 files |
| Phase 06-leave-audit P01 | 3 | 2 tasks | 1 files |
| Phase 06-leave-audit P02 | 6 | 2 tasks | 1 files |
| Phase 07-performance-audit P01 | 35 | 2 tasks | 1 files |
| Phase 07-performance-audit P02 | 183 | 2 tasks | 1 files |

## Accumulated Context

### Decisions

- Audit-only milestone — no code changes permitted in v1.1
- Review order: Core+Frontend first, then modules by size/risk (Attendance down to ShiftSwap), then batched small modules
- 4 metrics per module: security (SQL injection, auth, nonces, escaping), performance (N+1, unbounded queries), duplication (repeated logic), logical issues (race conditions, bad calculations, dead code)
- Small modules batched into two phases: Departments+Surveys+Projects (Phase 18), Reminders+EmployeeExit+PWA (Phase 19)
- Every finding must have severity rating: Critical / High / Medium / Low
- Every finding must include a concrete fix recommendation
- [Phase 04]: Core/ audit complete: 35 findings (3 Critical, 12 High, 14 Medium, 6 Low). Critical = ALTER TABLE in admin_init. High = N+1 org chart queries, dashboard without cache, information_schema on every load.
- [Phase 04]: Frontend/ audit: 27 findings (3 Critical, 11 High, 8 Medium, 5 Low). Critical = unprepared SQL in DashboardTab, ApprovalsTab, EmployeesTab. High = missing ownership checks on OverviewTab/ProfileTab, TeamTab logic bug, 14-query OverviewTab hot path.
- [Phase 05-attendance-audit]: Session_Service uses MySQL GET_LOCK for recalc serialization — correct but deferred events not also lock-guarded (TOCTOU gap)
- [Phase 05-attendance-audit]: Daily_Session_Builder uses gmdate() (UTC) for date targeting, inconsistent with site-local session keys — High logic bug
- [Phase 05-attendance-audit]: GET /attendance/status __return_true is Critical: exposes device geofence + employee snapshot to unauthenticated callers
- [Phase 05-attendance-audit]: POST /attendance/verify-pin __return_true is Critical: PIN brute-force with no rate limiting or lockout
- [Phase 05-attendance-audit]: Scan token peek-not-consume (kiosk) is intentional design but creates 10-min window for multi-type punch forgery from one QR scan
- [Phase 06-leave-audit]: LV-CALC-002: handle_approve() destroys opening/carried_over on every approval — Critical balance corruption
- [Phase 06-leave-audit]: LV-CALC-001: Tenure boundary evaluated at Jan 1, not employee anniversary — mid-year 5-year mark missed
- [Phase 06-leave-audit]: LV-CALC-004: handle_self_request() uses calendar days; shortcode_request() uses business_days() — inconsistent
- [Phase 06-leave-audit]: handle_approve() missing up-front capability guard is Critical — role checks embedded in conditional branches are insufficient gates
- [Phase 06-leave-audit]: has_overlap() TOCTOU race: no DB lock around check-then-insert, concurrent leave submissions can double-book leave
- [Phase 06-leave-audit]: LeaveModule has no REST endpoints — all operations through admin-post handlers, reducing unauthenticated attack surface
- [Phase 07-performance-audit]: PERF: get_performance_ranking N+1 is Critical (1,000+ queries per call on 200 employees) — needs batch pre-fetch
- [Phase 07-performance-audit]: PERF: save_review and save_goal have no capability guards — any logged-in user can create/modify any employee's review or goal
- [Phase 07-performance-audit]: PERF: run_monthly_reports references undefined \ variable on line 545 — PHP notice on every monthly cron run
- [Phase 07-performance-audit]: No __return_true endpoints in Performance REST — all routes require sfs_hr_performance_view minimum
- [Phase 07-performance-audit]: PADM: REST update_settings calls undefined PerformanceModule::save_settings() — PHP fatal on PUT /performance/settings
- [Phase 07-performance-audit]: PADM: Performance scores live-calculated with no draft/published gate — draft review ratings visible via REST score endpoint

### Phase Structure

| Phase | Module(s) | Requirements | Lines |
|-------|-----------|--------------|-------|
| 4 | Core + Frontend | CORE-01, CORE-02, CORE-03, CORE-04 | ~25K |
| 5 | Attendance | CRIT-01 | ~18K |
| 6 | Leave | CRIT-02 | ~7.7K |
| 7 | Performance | CRIT-03 | ~6.1K |
| 8 | Loans | CRIT-04 | ~5.4K |
| 9 | Payroll | FIN-01 | ~3.5K |
| 10 | Settlement | FIN-02 | ~1.7K |
| 11 | Assets | MED-01 | ~4K |
| 12 | Employees | MED-02 | ~3.2K |
| 13 | Hiring | MED-03 | ~2.5K |
| 14 | Resignation | MED-04 | ~2.3K |
| 15 | Workforce_Status | MED-05 | ~2K |
| 16 | Documents | MED-06 | ~1.9K |
| 17 | ShiftSwap | MED-07 | ~1.3K |
| 18 | Departments + Surveys + Projects | SML-01, SML-02, SML-03 | ~3.3K |
| 19 | Reminders + EmployeeExit + PWA | SML-04, SML-05, SML-06 | ~1.8K |

### Pending Todos

None.

### Blockers/Concerns

None.

## Session Continuity

Last session: 2026-03-16T03:35:50.942Z
Stopped at: Completed 07-02-PLAN.md
Resume file: None
