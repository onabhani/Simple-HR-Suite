---
phase: 14-resignation-audit
plan: 02
subsystem: audit
tags: [audit, security, sql, state-machine, resignation, notifications, cron]

requires:
  - phase: 14-resignation-audit/14-01
    provides: Admin views and module orchestrator audit findings (RES-SEC-001, RADM-LOGIC-002 baseline)

provides:
  - Security audit of 5 resignation service/handler/frontend/cron/notification files
  - State machine transition map with backwards-transition vulnerability analysis
  - Self-submission enforcement audit (verdict: SAFE)
  - $wpdb call-accounting table (18 calls, 0 unprepared)
  - 19 findings with severity ratings and fix recommendations

affects:
  - Phase 15 (Workforce_Status audit — similar handler/service patterns)
  - Any future fix phase targeting Resignation module

tech-stack:
  added: []
  patterns:
    - "State machine audit pattern: map all transitions, check for conditional WHERE guards, test backwards-transition paths"
    - "Self-submission audit pattern: trace employee_id derivation from request to DB write"

key-files:
  created:
    - .planning/phases/14-resignation-audit/14-02-resignation-services-findings.md
  modified: []

key-decisions:
  - "Self-submission enforcement SAFE: handle_submit() derives employee_id from Helpers::current_employee_id() exclusively, not from POST params"
  - "State machine VULNERABLE (High): handle_approve/reject/cancel use unconditional $wpdb->update() with no WHERE status=expected — TOCTOU race confirmed"
  - "RCRN-LOGIC-002 High: cron auto-terminates employees but never marks resignation status as completed — records stay approved indefinitely"
  - "RHDL-SEC-003 High: handle_cancel() reuses can_approve_resignation() which breaks for dept managers after level-1 approval advances to level-2"
  - "RNTF-DUP-001 Low: send_notification() uses dofs_ prefixed filters — cross-plugin dependency violation per CLAUDE.md; rename to sfs_hr_ namespace in v1.2"

patterns-established:
  - "State machine audit: always check if UPDATE includes WHERE status=expected_status AND approval_level=expected_level"
  - "Self-submission audit: trace employee_id from POST action entry to create/update call — verify no request param can override"

requirements-completed:
  - MED-04

duration: 5min
completed: 2026-03-16
---

# Phase 14, Plan 02: Resignation Services, Handlers, Frontend, Cron, Notifications Summary

**Security and logic audit of 5 resignation files (~1,400 lines): state machine has no conditional WHERE guards (TOCTOU), self-submission is safe, cron never marks resignations completed, 18 wpdb calls all prepared**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-03-16T19:13:58Z
- **Completed:** 2026-03-16T19:18:21Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- Audited 5 files across 4 metrics (security, performance, duplication, logical): 19 findings (0 Critical, 6 High, 9 Medium, 4 Low)
- Confirmed self-submission enforcement is SAFE: no request parameter can override employee_id in handle_submit()
- Mapped complete state machine transition table with backwards-transition analysis: unconditional UPDATE is the vulnerability
- Catalogued all 18 $wpdb calls across 5 files: 16 fully prepared, 2 dual-path (low risk), 0 unprepared — clean SQL hygiene

## Task Commits

Each task was committed atomically:

1. **Tasks 1+2: Audit and write findings report** - `0cb45b0` (feat)

**Plan metadata:** (docs commit follows)

## Files Created/Modified

- `.planning/phases/14-resignation-audit/14-02-resignation-services-findings.md` — Full findings report: 19 findings, $wpdb call-accounting table, state machine audit, self-submission audit

## Decisions Made

- Self-submission: SAFE — `handle_submit()` derives `employee_id` exclusively from `Helpers::current_employee_id()` (server-side); no POST parameter can override it
- State machine: VULNERABLE — `handle_approve()`, `handle_reject()`, `handle_cancel()` all use `$wpdb->update()` with only `WHERE id = $resignation_id` — no status/level guard; TOCTOU race possible
- Cron logic gap: `process_expired_resignations()` updates employee status to terminated but never transitions the resignation record from `approved` to `completed` — records accumulate in `approved` state indefinitely
- Cancellation gate bug: `handle_cancel()` calls `can_approve_resignation()` which checks the current `approval_level` — a dept manager who approved at level 1 cannot cancel after the resignation advances to level 2 (because they fail the level-2 check)
- DOFS filter references in notification layer violate CLAUDE.md cross-plugin isolation rules; flagged for v1.2 rename to `sfs_hr_` namespace

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 14 Resignation audit complete (both plans executed)
- All findings documented with severity ratings and fix recommendations
- Phase 15 (Workforce_Status audit) can begin — similar handler/service pattern; use state machine audit approach from this phase

---
*Phase: 14-resignation-audit*
*Completed: 2026-03-16*
