---
phase: 06-leave-audit
plan: 01
subsystem: leave
tags: [leave, balance, calculation, tenure, audit, security, performance]

# Dependency graph
requires: []
provides:
  - "Leave services/balance audit: 19 findings (3 Critical, 7 High, 6 Medium, 3 Low)"
  - "Findings report at .planning/phases/06-leave-audit/06-01-leave-services-balance-findings.md"
affects:
  - "06-02-leave-approval (approval/overlap audit)"
  - "Future fix phases addressing LV-CALC-001 through LV-DUP-005"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Audit-only: findings documented with severity, file:line, evidence, and fix recommendation"

key-files:
  created:
    - ".planning/phases/06-leave-audit/06-01-leave-services-balance-findings.md"
  modified: []

key-decisions:
  - "LV-CALC-001: Tenure boundary evaluated at Jan 1, not employee anniversary — misses mid-year 5-year promotions"
  - "LV-CALC-002: handle_approve() and handle_early_return() overwrite opening/carried_over with 0 on every approval"
  - "LV-CALC-003: available_days() silently substitutes annual_quota when accrued=0, masking deliberately zeroed balances"
  - "LV-CALC-004: handle_self_request() uses calendar days; shortcode_request() uses business_days() — inconsistent"
  - "LV-PERF-001: broadcast_holiday_added() is an unbounded synchronous bulk mailer — will timeout on large tenants"
  - "LV-SEC-004: is_hr_user() grants HR access to any WP administrator role, bypassing position-based HR gating"
  - "LV-PERF-004: render_calendar() uses column 'department_id' which does not exist — correct column is 'dept_id' (bug)"
  - "class-leave-ui.php: no findings — pure display helper with correct output escaping throughout"

patterns-established:
  - "Balance upsert pattern: must read existing opening/carried_over before overwriting any balance record"
  - "Business days vs calendar days: all day count calculations must use LeaveCalculationService::business_days()"

requirements-completed:
  - CRIT-02

# Metrics
duration: 3min
completed: 2026-03-16
---

# Phase 06 Plan 01: Leave Services / Balance Calculation Audit Summary

**19 findings (3 Critical) across LeaveCalculationService.php and LeaveModule.php balance/approval paths — tenure boundary off-by-year, opening balance destroyed on approval, and unbounded bulk mailer**

## Performance

- **Duration:** ~3 min
- **Started:** 2026-03-16T02:19:12Z
- **Completed:** 2026-03-16T02:22:04Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- Fully audited `LeaveCalculationService.php` (243 lines): identified critical tenure boundary issue, accrued=0 fallback override, and missing first-year pro-ration
- Audited `class-leave-ui.php` (105 lines): clean — all outputs correctly escaped, no $wpdb queries, no user input
- Audited `LeaveModule.php` balance/type methods: found handle_approve() destroys opening/carried_over on each approval (Critical), calendar-day vs business-day inconsistency in self-service path (High), N+1 department queries in request list (High), wrong column name in calendar employee count (High bug), Hajj once-only not enforced after approval, unbounded holiday mailer
- All 19 findings include severity, file:line, evidence snippet, and concrete fix recommendation

## Task Commits

1. **Task 1+2: Audit + Report** - `ee69a67` (feat)

## Files Created/Modified

- `.planning/phases/06-leave-audit/06-01-leave-services-balance-findings.md` — 19 findings with full detail (378 lines)

## Decisions Made

- Classified `LV-PERF-001` (broadcast_holiday_added) as Critical/Performance rather than just High — synchronous 500-email loop in an admin request will cause real timeouts in production
- Classified `LV-CALC-002` (opening/carried_over overwrite) as Critical because HR-managed balance adjustments are silently discarded on every leave approval, corrupting the balance audit trail
- `class-leave-ui.php` is entirely display-layer code; no security or correctness findings

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Plan 06-01 complete; findings report ready for Plan 06-02 (approval workflow and overlap detection audit)
- Key line ranges for Plan 06-02 scope: `handle_approve()` L1057-L1690, `handle_reject()` L1694-L1756, `handle_cancellation_approve()` L1966-L2175, overlap check via `LeaveCalculationService::has_overlap()` L86-L95
- No blockers for Plan 06-02

---
*Phase: 06-leave-audit*
*Completed: 2026-03-16*
