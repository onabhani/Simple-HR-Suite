---
phase: 29-logic-and-workflow-fixes
plan: "01"
subsystem: payroll, attendance, core
tags: [payroll, saudi-weekend, early-leave, capabilities, ajax, rest-api, php-fpm]

# Dependency graph
requires: []
provides:
  - Saudi Sun-Thu working-day calculation in PayrollModule::count_working_days (skips Fri+Sat)
  - Early-leave notification dedup via $was_early flag guard in Session_Service
  - Request-scoped capability cache in Capabilities::dynamic_caps via REQUEST_TIME_FLOAT
affects: [payroll, attendance, capabilities]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Request-scope static cache pattern: use REQUEST_TIME_FLOAT as cache key to reset per HTTP request in PHP-FPM persistent workers"
    - "Flag guard pattern for notification hooks: check was_X before firing on_X to prevent duplicate fires on recalc"

key-files:
  created: []
  modified:
    - includes/Modules/Payroll/PayrollModule.php
    - includes/Modules/Attendance/Services/Session_Service.php
    - includes/Core/Capabilities.php

key-decisions:
  - "Leave module business_days() (Article 109 calendar days minus Fridays) intentionally NOT changed — only Payroll count_working_days needed Saturday skip"
  - "plugins_loaded already covers REST API for ensure_roles_caps filter registration; no rest_api_init hook needed"
  - "REQUEST_TIME_FLOAT used as cache key (not microtime) since it is constant per request, avoiding cache miss on each user_has_cap call within same request"

patterns-established:
  - "Flag guard pattern: add $was_X = in_array(X, $existing_flags) and guard if ($is_X && !$was_X) to mirror was_late pattern"

requirements-completed: [LOGIC-03, LOGIC-04, LOGIC-05]

# Metrics
duration: 10min
completed: 2026-03-18
---

# Phase 29 Plan 01: Logic and Workflow Fixes Summary

**Saudi weekend payroll fix (Fri+Sat skip), early-leave notification dedup via flag guard, and REQUEST_TIME_FLOAT request-scoped capability cache for AJAX/REST correctness**

## Performance

- **Duration:** ~10 min
- **Started:** 2026-03-18T04:00:00Z
- **Completed:** 2026-03-18T04:10:00Z
- **Tasks:** 2
- **Files modified:** 3

## Accomplishments

- `PayrollModule::count_working_days` now skips both Friday (N=5) and Saturday (N=6) per Saudi Sun-Thu work week, so a Mon-Sun span correctly returns 5 working days instead of 6
- `Session_Service::recalc_session_for` early-leave path now guarded by `$was_early` flag (mirroring `$was_late`), preventing repeated early-leave request creation and its associated manager notification hook on subsequent recalcs
- `Capabilities::dynamic_caps` static cache reset per HTTP request using `REQUEST_TIME_FLOAT`, preventing stale false-negative capability reads in PHP-FPM persistent workers and AJAX requests

## Task Commits

Each task was committed atomically:

1. **Task 1: Saudi weekend and early-leave notification dedup** - `a783dbb` (fix)
2. **Task 2: Request-scope capability cache** - `76c65cc` (fix)

## Files Created/Modified

- `includes/Modules/Payroll/PayrollModule.php` - count_working_days skips Fri (N=5) and Sat (N=6); updated docblock comment
- `includes/Modules/Attendance/Services/Session_Service.php` - added $was_early flag; guarded early-leave creation with !$was_early
- `includes/Core/Capabilities.php` - added static $request_id and REQUEST_TIME_FLOAT cache reset logic

## Decisions Made

- Leave module's `LeaveCalculationService::business_days()` was intentionally NOT changed — it correctly skips only Friday per Saudi labor law Article 109 (annual leave counts calendar days minus Fridays). Only the Payroll working-day count needed the Saturday addition.
- `ensure_roles_caps()` is already called on `plugins_loaded` which fires for all request types including REST API, so no additional `rest_api_init` hook was needed.
- `REQUEST_TIME_FLOAT` chosen over `microtime(true)` as the cache key because it stays constant throughout a single HTTP request, ensuring cache hits within the same request while still resetting between requests.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 29-01 complete; ready for 29-02 (early-leave consolidation) and 29-03 (leave module fixes)
- No blockers

---
*Phase: 29-logic-and-workflow-fixes*
*Completed: 2026-03-18*
