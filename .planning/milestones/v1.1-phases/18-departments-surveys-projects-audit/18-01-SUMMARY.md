---
phase: 18-departments-surveys-projects-audit
plan: 01
subsystem: audit
tags: [departments, surveys, security, performance, wpdb, sql-injection, n+1, capability-check]

requires:
  - phase: 17-shiftswap-audit
    provides: ShiftSwap audit findings — established recurring antipattern registry

provides:
  - Departments module audit: 9 wpdb calls catalogued (all safe), CLEAN bootstrap, 2 High + 2 Medium security findings
  - Surveys module audit: 27 wpdb call sites catalogued (all safe/prepared), 1 Critical + 5 High findings
  - SurveysTab audit: 5 wpdb calls catalogued (all safe), frontend scoping gap identified
  - Complete findings report at 18-01-departments-surveys-findings.md

affects:
  - 18-02 (ProjectsModule audit — use same antipattern registry)
  - v1.2 fix planning (DEPT-PERF-001, SURV-LOGIC-002, SURV-SEC-003/004 are actionable)

tech-stack:
  added: []
  patterns:
    - "DepartmentsModule and SurveysModule are both CLEAN on the recurring antipatterns (no ALTER TABLE, information_schema, unprepared SHOW TABLES)"
    - "SurveysModule has own install() method using dbDelta — differs from Migrations.php delegation pattern used by most other modules"
    - "Survey response submission correctly derives employee_id from get_current_user_id() server-side — no IDOR risk"
    - "Conditional update guards (WHERE status='draft') used on publish/close transitions — correct pattern"

key-files:
  created:
    - .planning/phases/18-departments-surveys-projects-audit/18-01-departments-surveys-findings.md
  modified: []

key-decisions:
  - "DepartmentsModule and SurveysModule CLEAN on the 5 recurring antipatterns (ALTER TABLE, information_schema, SHOW TABLES, wrong capability gate, __return_true REST) — pattern improvement from early phases"
  - "SURV-SEC-002 (response insert race) is DB-guarded by UNIQUE KEY idx_survey_emp — risk is residual partial answer insertion, not duplicate responses"
  - "DEPT-LOGIC-001 dept deletion gap identified: survey target_ids JSON not cleaned up, no warning when no General dept exists"
  - "SURV-LOGIC-002 is the key actionable finding: handle_survey_save() allows editing published surveys via crafted POST — same pattern as Phase 13 Hiring handlers"
  - "SurveysTab STAB-LOGIC-001: out-of-scope survey form rendered to wrong-dept employee, but server-side submission rejected — defence-in-depth gap"

patterns-established:
  - "N+1 manager lookup in dept list (DEPT-PERF-001): pre-fetch all manager users before loop — same fix pattern as Phase 04 org chart N+1"
  - "N+1 question count in SurveysTab (SURV-PERF-001): use GROUP BY survey_id single query, map in PHP — same fix pattern as prior phases"

requirements-completed: [SML-01, SML-02]

duration: 3min
completed: 2026-03-17
---

# Phase 18 Plan 01: Departments + Surveys Audit Summary

**Security and performance audit of DepartmentsModule (775 lines) and SurveysModule + SurveysTab (1,293 lines): 17 findings (1 Critical, 7 High, 5 Medium, 4 Low); all 41 wpdb call sites catalogued as safe; both modules CLEAN on the 5 recurring antipatterns tracked since Phase 04**

## Performance

- **Duration:** ~3 min
- **Started:** 2026-03-17T01:35:13Z
- **Completed:** 2026-03-17T01:38:53Z
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments

- Catalogued all 41 wpdb query sites across 3 files — all confirmed prepared or static; zero raw interpolation of user input found
- Confirmed both modules CLEAN on the 5 recurring antipatterns: no bare ALTER TABLE, no information_schema, no unprepared SHOW TABLES, no wrong capability at gate, no `__return_true` REST callbacks
- Identified 17 actionable findings: SURV-LOGIC-002 (published survey edit via crafted POST), DEPT-PERF-001 (N+1 manager lookup), SURV-PERF-001 (N+1 question count), DEPT-SEC-002 (manager assignment without role check), SURV-SEC-002 (response TOCTOU mitigated by UNIQUE KEY), STAB-LOGIC-001 (out-of-scope form render)
- Noted SurveysModule as "Stub / incomplete" (per CLAUDE.md) but still identified correctness-critical gaps in form handlers

## Task Commits

Each task was committed atomically:

1. **Task 1: Read and audit Departments and Surveys modules** - `4c867e1` (feat)

**Plan metadata:** (pending final docs commit)

## Files Created/Modified

- `.planning/phases/18-departments-surveys-projects-audit/18-01-departments-surveys-findings.md` - Full audit findings with severity ratings, fix recommendations, and complete wpdb query catalogue

## Decisions Made

- SURV-SEC-001 reclassified from Critical to High: `handle_submit_response` uses `is_user_logged_in()` instead of a capability check, but `employee_id` is always derived server-side from `get_current_user_id()` — no IDOR risk; the weakest-link is that non-HR WP users with employee records can submit
- DEPT-LOGIC-003 (manager change not audit-logged) noted as UX/operational risk rather than security bug — dynamic `user_has_cap` filter means revocation is immediate and correct
- SURV-PERF-002 (unbounded survey list) and SURV-PERF-003 (N+1 answers in results) classified as Medium given the Stub/incomplete module status

## Deviations from Plan

None — plan executed exactly as written. All 4 audit metrics (security, performance, duplication, logical) addressed for each of the 3 files. All $wpdb calls catalogued.

## Issues Encountered

None. SurveysModule.php output was truncated by tool (58KB file) and required chunk-reading in three passes. Full audit completed from all chunks.

## User Setup Required

None — audit-only plan, no code changes, no external services.

## Next Phase Readiness

- Phase 18 Plan 01 complete: Departments and Surveys findings documented
- Phase 18 Plan 02 (ProjectsModule audit) is next — use same antipattern registry from this and prior phases
- Key patterns to check in ProjectsModule: information_schema, bare ALTER TABLE, REST auth, N+1 queries

---

## Self-Check

- [x] Findings file exists: `.planning/phases/18-departments-surveys-projects-audit/18-01-departments-surveys-findings.md`
- [x] Commit exists: `4c867e1`
- [x] All 3 files audited (DepartmentsModule.php, SurveysModule.php, SurveysTab.php)
- [x] All $wpdb calls catalogued (9 + 27 + 5 = 41 sites)
- [x] Department manager/HR responsible assignment auth checked (DEPT-SEC-002/003)
- [x] Survey response ownership and auth checked (SURV-SEC-001, STAB-SEC-002)
- [x] Bootstrap DDL antipatterns checked (CLEAN for both modules)
- [x] Cross-module pattern references to prior phases included

## Self-Check: PASSED

*Phase: 18-departments-surveys-projects-audit*
*Completed: 2026-03-17*
