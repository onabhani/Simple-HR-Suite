---
gsd_state_version: 1.0
milestone: v1.1
milestone_name: Module-by-Module Code Audit
status: roadmap_ready
stopped_at: Completed 13-02-PLAN.md
last_updated: "2026-03-16T18:31:05.397Z"
last_activity: 2026-03-16 — Roadmap created (phases 4-19, 16 phases, 23 requirements mapped)
progress:
  total_phases: 16
  completed_phases: 10
  total_plans: 21
  completed_plans: 21
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
| Phase 08-loans-audit P01 | 3 | 2 tasks | 1 files |
| Phase 08-loans-audit P02 | 3 | 2 tasks | 1 files |
| Phase 09-payroll-audit P01 | 4 | 2 tasks | 1 files |
| Phase 09-payroll-audit P02 | 25 | 2 tasks | 1 files |
| Phase 09-payroll-audit P03 | 15 | 1 tasks | 1 files |
| Phase 10-settlement-audit P01 | 3 | 2 tasks | 1 files |
| Phase 10-settlement-audit P02 | 3 | 2 tasks | 1 files |
| Phase 11-assets-audit P01 | 195 | 2 tasks | 1 files |
| Phase 11-assets-audit P02 | 3 | 2 tasks | 1 files |
| Phase 12-employees-audit P01 | 4 | 2 tasks | 1 files |
| Phase 12-employees-audit P02 | 25 | 2 tasks | 1 files |
| Phase 13-hiring-audit P01 | 7 | 2 tasks | 1 files |
| Phase 13-hiring-audit P02 | 4 | 2 tasks | 1 files |

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
- [Phase 08-loans-audit]: LOAN-LOGIC-002: PayrollModule references l.monthly_installment which does not exist in sfs_hr_loans schema — loan deductions silently fail on every payroll run
- [Phase 08-loans-audit]: LOAN-SEC-001/002: unprepared SHOW TABLES and bare ALTER TABLE DDL in LoansModule admin_init/activation paths — same Critical antipattern as Phase 04
- [Phase 08-loans-audit]: LOAN-DUP-001: installment calculation diverges between frontend (floor+remainder) and admin (round(principal/count)) — stored installment_amount differs from schedule amounts
- [Phase 08-loans-audit]: LADM-SEC-002: installment nonce in data-nonce HTML attribute — Critical, DOM-readable by scripts
- [Phase 08-loans-audit]: LADM-LOGIC-001: Finance approval allows principal amount higher than GM-approved — no upper bound validation
- [Phase 08-loans-audit]: LADM-LOGIC-006: Admin always creates loans as pending_gm regardless of require_gm_approval setting
- [Phase 08-loans-audit]: LADM-PERF-001: loan list has no pagination — all loans loaded on every page view
- [Phase 09-payroll-audit]: PAY-DUP-001 Critical confirmed: PayrollModule queries l.monthly_installment which does not exist — loan deductions silently zero on every payroll run since module creation
- [Phase 09-payroll-audit]: PAY-LOGIC-001 High: count_working_days() excludes only Friday, missing Saturday — Saudi weekend is Fri+Sat — understates absence deductions by ~15%
- [Phase 09-payroll-audit]: PAY-LOGIC-003 Medium: transient-based payroll lock has TOCTOU race — recommend MySQL GET_LOCK() same as Attendance module
- [Phase 09-payroll-audit]: No __return_true REST endpoints in Payroll — all routes have real permission callbacks; run/approve capability guards are correct
- [Phase 09-payroll-audit]: PADM-SEC-001 Critical: handle_export_attendance() uses sfs_hr.view — any employee can export all-org attendance data
- [Phase 09-payroll-audit]: PADM-LOGIC-001 High: handle_approve_run() payslip generation has TOCTOU race — fix: atomic UPDATE WHERE status=review
- [Phase 09-payroll-audit]: PADM-DUP-001 High: components_json split duplicated 6 times — extract parse_components() helper
- [Phase 09-payroll-audit]: PADM-LOGIC-002 High: sfs_hr_payroll_run capability appears unregistered — dead permission slot
- [Phase 09-payroll-audit]: All 5 gap-flagged lines (261, 422, 818, 1731, 1736) in Admin_Pages.php are static queries — safe pattern violations, not injection vulnerabilities
- [Phase 09-payroll-audit]: Admin_Pages.php: 46 total wpdb calls — 36 prepared, 10 raw (all static); call-accounting table appended to 09-02 findings to close verification gap
- [Phase 10-settlement-audit]: SETT-LOGIC-001 Critical: 21-day EOS formula is UAE Labor Law — Saudi Article 84 requires 15 days per year for first 5 years; code overpays sub-5-year employees by 40%
- [Phase 10-settlement-audit]: SETT-LOGIC-002 High: calculate_gratuity() has no trigger_type parameter — Saudi resignation multipliers (0%/33%/66%/100%) completely absent; code always pays full EOS
- [Phase 10-settlement-audit]: SETT-LOGIC-003 High: no double-settlement guard — two settlements can be created for the same employee_id; no UNIQUE constraint on sfs_hr_settlements.employee_id
- [Phase 10-settlement-audit]: SETT-DUP-001 High: client-computed gratuity_amount accepted without server-side re-validation in handle_create()
- [Phase 10-settlement-audit]: SettlementModule.php bootstrap is clean: no bare ALTER TABLE, no unprepared SHOW TABLES — unlike Loans (Phase 08) and Core (Phase 04)
- [Phase 10-settlement-audit]: All 5 Settlement handlers have correct nonce (check_admin_referer) and capability (sfs_hr.manage) guards at entry — no missing auth guards
- [Phase 10-settlement-audit]: SADM-LOGIC-001 Critical: JS calculateGratuity() uses 21-day UAE formula in admin form — confirms SETT-LOGIC-001 affects the admin UI; sub-5-year employees are shown and stored a 40% overpayment
- [Phase 10-settlement-audit]: SADM-SEC-001 High: Approve/Reject/Pay buttons shown to all admin page viewers — no current_user_can() in render_action_buttons(); server-side handlers do check capability
- [Phase 10-settlement-audit]: Settlement admin view files have 0 direct wpdb calls — all DB access delegated to Settlement_Service; admin views are clean from SQL injection perspective
- [Phase 11-assets-audit]: ASSET-SEC-002: Invoice upload has no MIME allowlist; save_data_url_attachment writes binary before type validation — SVG/HTML injectable
- [Phase 11-assets-audit]: ASSET-SEC-003: log_asset_event uses information_schema.tables on every call — same Critical antipattern as Phase 04 and Phase 08
- [Phase 11-assets-audit]: ASSET-DUP-001: Dual asset status tracking (assets.status + assignments.status) updated without transactions — divergence possible
- [Phase 11-assets-audit]: ASSET-SEC-004/005: handle_assign_decision and handle_return_decision use is_user_logged_in() only — capability gate missing
- [Phase 11-assets-audit]: AssetsModule bootstrap is clean: prepared SHOW TABLES, no bare ALTER TABLE (unlike Loans Phase 08 and Core Phase 04)
- [Phase 11-assets-audit]: REST stub registers no routes (not __return_true pattern) — correct design decision
- [Phase 11-assets-audit]: AVIEW-SEC-001/002 High: inline $wpdb queries in assignments-list.php and assets-edit.php view files violate controller/view separation — queries are static/prepared so no injection risk but architectural violation
- [Phase 11-assets-audit]: AVIEW-LOGIC-001 High: assignments list shows all-org data to dept managers —  from controller unscoped; form correctly restricts to manager dept but list does not
- [Phase 11-assets-audit]: AVIEW-PERF-001 High: PHP-side re-filter after LIMIT 200 in assets-list.php causes silent data loss on filtered views when total assets exceed 200
- [Phase 12-employees-audit]: EMP-LOGIC-001: hire_date/hired_at diverge on edit — form saves hired_at only; hire_date not synced in handle_save_edit()
- [Phase 12-employees-audit]: WP user creation handler is correctly secured (create_users cap + nonce + subscriber role) — no privilege escalation risk
- [Phase 12-employees-audit]: 8 information_schema.tables checks per profile page load — same antipattern as Phase 04/08/11 (EMP-PERF-001)
- [Phase 12-employees-audit]: My Profile data scoping is CLEAN: all queries use employee_id derived from get_current_user_id(); no IDOR risk; asset write handlers re-validate ownership server-side
- [Phase 12-employees-audit]: EmployeesModule.php bootstrap is CLEAN: no ALTER TABLE, no bare SHOW TABLES, no information_schema — unlike Loans (Phase 08) and Core (Phase 04)
- [Phase 12-employees-audit]: 3 information_schema.tables calls per My Profile overview tab load — same antipattern as Phase 04/08/11; replace with SHOW TABLES LIKE or cached result
- [Phase 12-employees-audit]: hire_date/hired_at: both Employees files consistently prefer hired_at (inconsistent with CLAUDE.md); fix should be coordinated across both files
- [Phase 13-hiring-audit]: HiringModule.php install() is clean — no bare ALTER TABLE, no information_schema (unlike Loans Phase 08, Core Phase 04)
- [Phase 13-hiring-audit]: HIR-SEC-002 Critical: all 3 conversion methods (trainee-to-candidate, trainee-to-employee, candidate-to-employee) are public static with no current_user_can() check — any authenticated user can trigger employee creation
- [Phase 13-hiring-audit]: HIR-LOGIC-001 Critical: conversion workflows not wrapped in transactions — orphan WP users and duplicate employee records possible on partial failure
- [Phase 13-hiring-audit]: HIR-SEC-001 Critical: two unprepared employee code get_var() calls with raw string interpolation — same antipattern as Phase 04/08/11
- [Phase 13-hiring-audit]: HADM-SEC-002 Critical: all 6 POST handlers have no current_user_can() check — nonce-only guard insufficient; any authenticated user can add/modify candidates and trigger employee creation
- [Phase 13-hiring-audit]: HADM-SEC-001 Critical: manage_options used as capability for dept/GM approval with TODO comments — approval workflow non-functional for actual HR managers; needs sfs_hr.manage or dept-manager dynamic check
- [Phase 13-hiring-audit]: HADM-LOGIC-001 Critical: handle_candidate_action() has no state-machine transition enforcement — any stage can be skipped or reversed including moving a hired candidate to rejected; fix: conditional UPDATE WHERE status = expected
- [Phase 13-hiring-audit]: HADM-LOGIC-004 High: direct_hire_employee_id stored as embedded JSON in notes text column; editing notes destroys the employee link — needs dedicated direct_hire_employee_id BIGINT column in sfs_hr_trainees

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

Last session: 2026-03-16T18:26:23.768Z
Stopped at: Completed 13-02-PLAN.md
Resume file: None
