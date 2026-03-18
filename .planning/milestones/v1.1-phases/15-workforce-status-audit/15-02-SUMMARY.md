---
phase: 15-workforce-status-audit
plan: "02"
subsystem: audit
tags: [audit, cron, notifications, workforce-status, absent, email, wpdb]

requires:
  - phase: 15-workforce-status-audit
    provides: "Plan 01 Admin_Pages and WorkforceStatusModule audit findings establishing N+1 shift resolution, Friday-only fallback, and WADM capability antipatterns"

provides:
  - "13-finding audit report for Absent_Cron.php and Absent_Notifications.php (2 files, ~664 lines)"
  - "Full $wpdb call-accounting table (3 calls, 2 correctly prepared, 1 unnecessary prepare() with static query)"
  - "WNTF-PERF-001: double absent detection confirmed — 3 queries executed twice per cron run"
  - "WNTF-LOGIC-001: Friday-only default misses Saudi Saturday (PAY-LOGIC-001 recurrence)"
  - "WCRN-LOGIC-001: no exception isolation between manager/employee notification sends"
  - "WNTF-DUP-001/002: is_holiday() verbatim duplication and day-off fallback inconsistency documented"

affects:
  - "Phase 16 Documents audit"
  - "v1.2 fix sprint — weekly_off_days default, double query elimination, is_holiday() extraction, trigger_manual() guard"

tech-stack:
  added: []
  patterns:
    - "All $wpdb calls in Absent_Notifications use parameterized prepare() for dynamic values; static query incorrectly wrapped in prepare() with empty array"
    - "Email output uses esc_html() throughout template methods — clean XSS posture"

key-files:
  created:
    - ".planning/phases/15-workforce-status-audit/15-02-workforce-cron-notifications-findings.md"
  modified: []

key-decisions:
  - "WNTF-PERF-001 (High): double absent detection is highest-ROI fix — single-line change to pass pre-computed result to both notification methods halves DB load"
  - "WNTF-LOGIC-001 (High): weekly_off_days default must change from [5] to [5, 6] — same root cause as PAY-LOGIC-001 (Phase 09)"
  - "WNTF-DUP-001 (Medium): is_holiday() extraction deferred to v1.2 refactor phase — identical logic in Admin_Pages and Notifications"
  - "trigger_manual() (WCRN-SEC-001): not currently exposed to any handler — informational risk only; defense-in-depth cap check recommended"
  - "All 3 $wpdb dynamic queries in Absent_Notifications are correctly prepared — no SQL injection risks"

patterns-established:
  - "Friday-only default [5] is a recurring antipattern across Phase 09 (Payroll), Phase 15 Plan 01 (Admin_Pages fallback), and Phase 15 Plan 02 (Notifications default)"
  - "N+1 resolve_shift_for_date() per employee is a module-wide pattern in Workforce_Status — both Admin_Pages and Notifications have this loop"

requirements-completed:
  - MED-05

duration: 3min
completed: "2026-03-16"
---

# Phase 15 Plan 02: Workforce_Status Cron & Notifications Audit Summary

**13 findings (6 High, 5 Medium, 2 Low) for Absent_Cron and Absent_Notifications — double absent query execution and Friday-only Saudi Saturday omission are highest-priority fixes**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-16T19:29:35Z
- **Completed:** 2026-03-16T19:32:50Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- Audited `Absent_Cron.php` (161 lines) and `Absent_Notifications.php` (503 lines) across all 4 metrics
- Confirmed all 3 dynamic $wpdb queries in `Absent_Notifications` use `$wpdb->prepare()` correctly — no SQL injection risk
- Identified WNTF-PERF-001 (High): `send_absent_notifications()` and `send_employee_absent_notifications()` both independently call `get_absent_employees_by_department()` — 3 DB queries run twice per cron execution
- Confirmed WNTF-LOGIC-001 (High): `weekly_off_days` defaults to `[5]` (Friday only) — same root cause as Phase 09 `PAY-LOGIC-001`; Saturday absence emails sent on every unconfigured deployment
- Catalogued `is_holiday()` verbatim duplication (WNTF-DUP-001) between `Admin_Pages` and `Absent_Notifications` — identical logic including old/new field name support and cross-year wrap

## Task Commits

Each task was committed atomically:

1. **Tasks 1+2: Audit and findings report** - `5aa16d7` (feat)

**Plan metadata:** (this commit — docs)

## Files Created/Modified

- `.planning/phases/15-workforce-status-audit/15-02-workforce-cron-notifications-findings.md` — 13 findings with severity ratings, fix recommendations, $wpdb call-accounting table, and cross-reference to prior phases

## Decisions Made

- Double query execution (WNTF-PERF-001) is the highest-ROI fix: passing pre-computed `$absent_by_dept` to both `send_*` methods eliminates redundant DB load with minimal code change
- Friday-only default is a systemic pattern across 3+ files — fix should be coordinated across Payroll, Workforce_Status Admin_Pages, and Notifications in a single v1.2 patch
- `trigger_manual()` has no current handler exposure — capability guard recommended as defense-in-depth but not a blocking security issue
- `update_settings()` is defined but not wired to any admin POST handler — low risk today, sanitization should be added proactively before wiring

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Phase 15 (Workforce_Status) fully audited — all files covered across Admin_Pages (Plan 01) and Cron + Notifications (Plan 02)
- Phase 16 (Documents module, ~1.9K lines) ready to begin
- Key carry-forward findings for v1.2 fix sprint:
  - `weekly_off_days` default `[5, 6]` change needed in Notifications (and Payroll from Phase 09)
  - Double `get_absent_employees_by_department()` call in `Absent_Cron::run()` to be eliminated
  - `is_holiday()` extraction to shared helper to be coordinated with Admin_Pages refactor
  - `trigger_manual()` capability guard recommended before any handler wires it

---
*Phase: 15-workforce-status-audit*
*Completed: 2026-03-16*
