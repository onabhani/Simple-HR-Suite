---
phase: 25-migration-pattern-fixes
plan: 01
subsystem: database
tags: [mysql, migrations, alter-table, wordpress, wpdb]

# Dependency graph
requires: []
provides:
  - Idempotent column-add helper (add_column_safe) in Core/Admin.php
  - add_index_if_missing() helper in Attendance/Migration.php
  - All bare ALTER TABLE ADD COLUMN calls in Admin.php guarded via add_column_safe()
  - All bare ALTER TABLE ADD COLUMN calls in Attendance/Migration.php guarded via add_column_if_missing()
  - hr-suite.php self-healing block verified: all column adds guarded by $column_exists closure
  - Leave/LeaveModule.php verified: all column adds guarded by $colExists closure
  - ShiftSwap/ShiftSwapModule.php verified: uses add_column_if_missing() helper already
affects: [Phase 26 SQL injection fixes, any future migration additions]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "All column additions in Core/Admin.php use add_column_safe(\$table, \$col, \$sql) private helper"
    - "All column additions in Attendance/Migration.php use \$this->add_column_if_missing(\$wpdb, \$table, \$col, \$ddl)"
    - "All KEY/index additions in Attendance/Migration.php use \$this->add_index_if_missing() to prevent duplicate index errors"

key-files:
  created: []
  modified:
    - includes/Core/Admin.php
    - includes/Modules/Attendance/Migration.php

key-decisions:
  - "Used SHOW COLUMNS FROM ... LIKE %s for add_column_safe() in Admin.php (consistent with existing instance helpers)"
  - "Added add_index_if_missing() to Attendance/Migration.php rather than duplicating inline information_schema checks"
  - "hr-suite.php bare ALTER TABLE calls not wrapped in helper — already guarded by existing $column_exists closure; plan confirms no functional change needed"
  - "LeaveModule.php bare ALTER TABLE calls not wrapped in helper — already guarded by existing $colExists closure inline"

patterns-established:
  - "Core/Admin.php: use add_column_safe(\$table, \$col, \$full_alter_sql) for all employee table column migrations"
  - "Attendance/Migration.php: use add_column_if_missing(\$wpdb, \$table, \$col, \$ddl_fragment) + add_index_if_missing() for KEY additions"

requirements-completed: [SQL-01]

# Metrics
duration: 15min
completed: 2026-03-17
---

# Phase 25 Plan 01: Migration Pattern Fixes Summary

**Eliminated all duplicate-column migration errors by replacing bare ALTER TABLE ADD COLUMN calls with guarded helpers across Core/Admin.php and Attendance/Migration.php**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-03-17T19:25:00Z
- **Completed:** 2026-03-17T19:40:00Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- Added `add_column_safe()` private helper to `Core/Admin.php` using `SHOW COLUMNS` existence check; refactored 3 migration methods (22 column additions total) to use it
- Added `add_index_if_missing()` private helper to `Attendance/Migration.php`; converted 4 inline information_schema + bare ALTER TABLE blocks to use the existing `add_column_if_missing()` instance method plus the new index helper
- Verified `hr-suite.php` self-healing block: all 5 bare ALTER TABLE ADD COLUMN calls already guarded by `$column_exists` closure — no functional change required
- Verified `Leave/LeaveModule.php`: all ALTER TABLE ADD COLUMN calls already guarded by `$colExists` closure inline — no change required
- Verified `ShiftSwap/ShiftSwapModule.php`: already uses `add_column_if_missing()` helper — no change required

## Task Commits

Each task was committed atomically:

1. **Task 1: Convert bare ALTER TABLE in hr-suite.php and Core/Admin.php** - `22088b4` (fix)
2. **Task 2: Convert bare ALTER TABLE in Attendance, Leave, and ShiftSwap** - `722d8e0` (fix)

**Plan metadata:** (docs commit follows)

## Files Created/Modified
- `includes/Core/Admin.php` - Added `add_column_safe()` helper; refactored `maybe_add_employee_photo_column()`, `maybe_install_qr_cols()`, `maybe_install_employee_extra_cols()`
- `includes/Modules/Attendance/Migration.php` - Added `add_index_if_missing()` helper; converted 4 inline migration blocks to use `add_column_if_missing()` + `add_index_if_missing()`

## Decisions Made
- Used `SHOW COLUMNS FROM ... LIKE %s` for the `add_column_safe()` helper in Admin.php (matches the pattern used by the existing Attendance and ShiftSwap instance helpers)
- `add_index_if_missing()` uses `information_schema.STATISTICS` check (same approach as the existing `add_unique_key_if_missing()` in the same file)
- hr-suite.php self-healing block left as-is: already uses `$column_exists` closure guards — wrapping in a separate helper would add complexity with no benefit
- Data migration backfill (`dept_id -> dept_ids` JSON) preserved but now only runs when the column is newly added (flag checked before calling helper)

## Deviations from Plan

None - plan executed exactly as written. hr-suite.php and LeaveModule.php were verified to already have proper guards; only Admin.php and Attendance/Migration.php required changes, as the plan specified.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required. No schema changes were made; only migration safety guards were added.

## Next Phase Readiness
- Phase 25 Plan 01 complete — migration infrastructure is now fully idempotent
- Phase 26 (SQL injection fixes) can proceed on a clean migration foundation
- No blockers

---
*Phase: 25-migration-pattern-fixes*
*Completed: 2026-03-17*
