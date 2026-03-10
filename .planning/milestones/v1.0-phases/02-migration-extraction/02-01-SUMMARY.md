---
phase: 02-migration-extraction
plan: 01
subsystem: database
tags: [migration, table-creation, capabilities, seeding, god-class-decomposition]

# Dependency graph
requires:
  - phase: 01-views-extraction
    provides: "Widget and Kiosk shortcode extraction established the extraction pattern"
provides:
  - "Migration class handling all attendance table creation, column migration, FK migration"
  - "Capability registration and seed data logic in dedicated class"
  - "AttendanceModule reduced to 489 lines (from 1047)"
affects: [02-migration-extraction, 03-service-extraction]

# Tech tracking
tech-stack:
  added: []
  patterns: [verbatim-extraction-to-dedicated-class, instance-method-delegation]

key-files:
  created:
    - includes/Modules/Attendance/Migration.php
  modified:
    - includes/Modules/Attendance/AttendanceModule.php

key-decisions:
  - "Converted static helper methods to instance methods on Migration class"
  - "Eliminated backfill_early_leave_request_numbers middleman delegate, calling Early_Leave_Service directly"
  - "Removed duplicate register_caps admin_init hook since it runs inside Migration::run() already"

patterns-established:
  - "Migration extraction: dedicated Migration class with run() entry point for all install logic"
  - "Constant reference: Migration references AttendanceModule::OPT_SETTINGS rather than duplicating"

requirements-completed: [MIGR-01, MIGR-02, MIGR-03]

# Metrics
duration: 4min
completed: 2026-03-09
---

# Phase 02 Plan 01: Migration Extraction Summary

**Extracted ~560 lines of migration, capability, and seed logic from AttendanceModule into dedicated Migration class with verbatim SQL preservation**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-09T21:36:52Z
- **Completed:** 2026-03-09T22:08:51Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- Created Migration.php with 574 lines containing all table creation, column migration, FK migration, cap registration, and seed logic
- AttendanceModule.php reduced from 1047 to 489 lines (-558 lines, 53% reduction)
- Zero behavior change -- identical SQL executed in identical order via delegation

## Task Commits

Each task was committed atomically:

1. **Task 1: Create Migration class with all installation logic** - `bdeedb9` (feat)
2. **Task 2: Wire AttendanceModule to delegate to Migration class** - `0569073` (refactor)

## Files Created/Modified
- `includes/Modules/Attendance/Migration.php` - New class with run() entry point containing all migration logic, helper methods, cap registration, and seed data
- `includes/Modules/Attendance/AttendanceModule.php` - Reduced to thin orchestrator; maybe_install() delegates to Migration::run()

## Decisions Made
- Converted all extracted static methods (add_column_if_missing, add_unique_key_if_missing, migrate_add_foreign_keys) to instance methods since they are internal to Migration
- Eliminated the backfill_early_leave_request_numbers deprecated delegate wrapper, calling Services\Early_Leave_Service directly in Migration::run()
- Removed the separate register_caps admin_init hook (line 59) since register_caps already runs inside maybe_install() -> Migration::run() which is hooked to admin_init

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- AttendanceModule now at 489 lines, approaching the Phase 3 target of under 500 lines
- Migration logic fully isolated, ready for remaining Phase 2 plans (policy extraction, session service extraction, etc.)
- Established pattern for extracting further service classes

## Self-Check: PASSED

All files exist, all commits verified.

---
*Phase: 02-migration-extraction*
*Completed: 2026-03-09*
