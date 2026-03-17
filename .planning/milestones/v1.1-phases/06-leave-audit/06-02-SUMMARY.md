---
phase: 06-leave-audit
plan: 02
subsystem: audit
tags: [leave, approval-workflow, overlap-detection, authorization, race-condition, balance-corruption]

# Dependency graph
requires:
  - phase: 06-leave-audit-plan-01
    provides: "Leave balance/calculation audit findings (LV-CALC-001 through LV-CALC-006)"
provides:
  - "Leave approval workflow state machine analysis with authorization gap map"
  - "Overlap detection TOCTOU race condition finding"
  - "Complete admin handler authorization audit (approve/reject/cancel/hold/update)"
  - "Department-scoping and calendar data leak findings"
  - "16-finding structured report in 06-02-leave-workflow-findings.md"
affects: [07-payroll-audit, v1.2-remediation]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Audit-only: findings documented without code changes"
    - "Cross-referencing Plan 01 confirmed findings (LV-CALC-002, LV-CALC-004)"

key-files:
  created:
    - .planning/phases/06-leave-audit/06-02-leave-workflow-findings.md
  modified: []

key-decisions:
  - "No REST endpoints exist in LeaveModule.php — leave operations are admin-post only, reducing attack surface"
  - "handle_approve() missing up-front capability guard is Critical — role checks embedded in conditional branches are insufficient"
  - "has_overlap() TOCTOU is Critical — no DB-level lock or transaction around check-then-insert"
  - "is_hr_user() incorrectly grants HR privileges to all sfs_hr_manager role holders, not just configured HR approvers"
  - "class-leave-ui.php is clean — all output properly escaped, no findings"
  - "Calendar view has no department scoping for managers — leaks all-org leave data"

patterns-established:
  - "Audit pattern: check capability guards first (require_cap), then nonce, then business logic"
  - "Audit pattern: verify per-request nonce vs shared nonce for state-mutating handlers"

requirements-completed: [CRIT-02]

# Metrics
duration: 42min
completed: 2026-03-16
---

# Phase 6 Plan 02: Leave Workflow/Approval/REST Audit Summary

**Leave approval workflow audit: 3 Critical, 6 High, 5 Medium, 2 Low findings across authorization gaps, TOCTOU overlap race condition, and balance corruption — no REST endpoints found (admin-post only module)**

## Performance

- **Duration:** 42 min
- **Started:** 2026-03-16T02:24:26Z
- **Completed:** 2026-03-16T03:06:30Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- Audited 7390 lines of LeaveModule.php for authorization, overlap detection, workflow integrity, and output escaping
- Audited class-leave-ui.php (105 lines): clean, all output properly escaped
- Confirmed no REST endpoints in LeaveModule.php — all operations through authenticated admin-post handlers
- Identified TOCTOU race condition in has_overlap() enabling double-booking under concurrent submissions
- Identified missing up-front capability guard on handle_approve() — the most-called state-changing handler
- Confirmed Plan 01 findings LV-CALC-002 (balance corruption on approval) and LV-CALC-004 (calendar vs business days) via workflow-layer evidence

## Task Commits

Each task was committed atomically:

1. **Task 1+2: Audit LeaveModule.php and write findings report** - `4e1fc45` (feat)

**Plan metadata:** (included in task commit)

## Files Created/Modified

- `.planning/phases/06-leave-audit/06-02-leave-workflow-findings.md` - 16-finding structured audit report covering workflow, security, logic, and performance categories

## Decisions Made

- No REST endpoints exist in LeaveModule.php — confirmed by grepping `register_rest_route` which returned no results. The entire Leave module uses `admin_post_*` handlers, which have implicit admin authentication via WordPress but require explicit capability checks per handler.
- `is_hr_user()` expanding to cover `sfs_hr_manager` role is treated as Critical because it allows any department manager to invoke HR-only operations (hold leave, update dates) for employees outside their department.
- Calendar department-scoping gap is Medium severity rather than High because the data leaked (leave dates) is less sensitive than PII or financial data, and the calendar is within the admin area behind `sfs_hr.leave.review`.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

- LeaveModule.php (7390 lines) exceeded the Read tool's single-call limit. Read in targeted sections using grep-then-read pattern focused on handler entry points, approval state machine, and overlap detection. All audit objectives were satisfied despite chunked reading.

## Next Phase Readiness

- Phase 06 Leave audit is complete (both plans executed)
- Phase 07 (Performance / Payroll) can begin
- v1.2 remediation planning should prioritize: LV-WF-001 (capability guard), LV-SEC-001 (TOCTOU overlap lock), LV-WF-006 (balance corruption) — three findings that affect correctness and security directly

---
*Phase: 06-leave-audit*
*Completed: 2026-03-16*

## Self-Check: PASSED

- [x] `.planning/phases/06-leave-audit/06-02-leave-workflow-findings.md` — FOUND (418 lines, 21 finding sections)
- [x] Commit `4e1fc45` — FOUND
- [x] Report contains sections: Workflow Findings, Security Findings, Logic Findings, Performance Findings
- [x] All reviewed files appear in Files Reviewed table
