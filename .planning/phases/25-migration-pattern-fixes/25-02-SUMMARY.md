---
phase: 25-migration-pattern-fixes
plan: "02"
subsystem: database
tags: [mysql, wpdb, show-tables, show-columns, migration, performance]

# Dependency graph
requires:
  - phase: 25-01
    provides: add_column_safe helper in Core/Admin.php; add_index_if_missing in Attendance/Migration.php
provides:
  - Zero information_schema references in any runtime code path across 14 files
  - Migration helpers (add_column_if_missing, make_column_nullable_if_exists, make_text_if_varchar255) using SHOW COLUMNS FROM
  - All runtime table/column existence checks using SHOW TABLES LIKE or SHOW COLUMNS FROM
affects: [Phase 26, Phase 27, all future modules that add table/column checks]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "SHOW TABLES LIKE %s for table existence (replaces information_schema.tables)"
    - "SHOW COLUMNS FROM `{table}` LIKE %s for column existence (replaces information_schema.COLUMNS)"
    - "SHOW COLUMNS FROM returns assoc row with Type/Null fields (replaces COLUMN_TYPE/IS_NULLABLE)"
    - "SHOW TABLE STATUS LIKE %s for engine check (replaces information_schema.TABLES ENGINE)"
    - "information_schema.STATISTICS acceptable in migration-only version-gated code (no SHOW equivalent for index names)"

key-files:
  created: []
  modified:
    - includes/Install/Migrations.php
    - hr-suite.php
    - includes/Core/Admin.php
    - includes/Core/Notifications.php
    - includes/Core/Admin/Services/ReportsService.php
    - includes/Modules/Attendance/Migration.php
    - includes/Modules/Attendance/Services/Shift_Service.php
    - includes/Modules/Documents/DocumentsModule.php
    - includes/Modules/Employees/Admin/class-my-profile-page.php
    - includes/Modules/Employees/Admin/class-employee-profile-page.php
    - includes/Modules/Reminders/Services/class-reminders-service.php
    - includes/Modules/Projects/Services/class-projects-service.php
    - includes/Modules/Assets/Admin/class-admin-pages.php
    - includes/Modules/ShiftSwap/ShiftSwapModule.php

key-decisions:
  - "information_schema.STATISTICS retained in migration-only helpers (add_index_if_missing, add_unique_key_if_missing) — no clean SHOW equivalent for index name lookups"
  - "information_schema.TABLE_CONSTRAINTS retained in Attendance FK migration — option-gated, one-time, no SHOW alternative for FK constraint names"
  - "SHOW COLUMNS FROM row uses Type field (not COLUMN_TYPE) and Null field (not IS_NULLABLE)"
  - "ENGINE check migrated to SHOW TABLE STATUS LIKE + row['Engine'] in Attendance/Migration.php"

patterns-established:
  - "Table existence: (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))"
  - "Column existence: $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM `{$table}` LIKE %s', $col))"
  - "Column type: $row = $wpdb->get_row(prepare('SHOW COLUMNS FROM `{$t}` LIKE %s', col), ARRAY_A); $col_type = $row ? $row['Type'] : null"
  - "Engine check: $row = $wpdb->get_row(prepare('SHOW TABLE STATUS LIKE %s', $t), ARRAY_A); $engine = $row ? $row['Engine'] : null"

requirements-completed: [SQL-02]

# Metrics
duration: 30min
completed: 2026-03-18
---

# Phase 25 Plan 02: information_schema Elimination Summary

**Replaced all runtime information_schema queries with SHOW TABLES LIKE / SHOW COLUMNS FROM across 14 files, leaving only version-gated migration-only STATISTICS checks annotated with comments**

## Performance

- **Duration:** ~30 min
- **Started:** 2026-03-18T00:00:00Z
- **Completed:** 2026-03-18
- **Tasks:** 2 of 2
- **Files modified:** 14

## Accomplishments

- Eliminated all runtime information_schema queries across Core admin, all module services, and employee profile pages (10 runtime files)
- Rewrote 3 core migration helpers in Migrations.php to use SHOW COLUMNS FROM instead of information_schema.COLUMNS
- Replaced closures in hr-suite.php admin_init block with SHOW TABLES LIKE / SHOW COLUMNS FROM patterns
- Replaced ENGINE check with SHOW TABLE STATUS LIKE in Attendance/Migration.php
- Annotated remaining STATISTICS checks in migration-only helpers with "migration-only, version-gated" comments

## Task Commits

Each task was committed atomically:

1. **Task 1: Replace information_schema in migration helpers and hr-suite.php** - `1a115b6` (fix)
2. **Task 2: Replace information_schema in runtime code (Admin, Services, Modules)** - `b931d97` (fix)

## Files Created/Modified

- `includes/Install/Migrations.php` - add_column_if_missing, make_column_nullable_if_exists, make_text_if_varchar255 use SHOW COLUMNS FROM; ensure_performance_indexes and run() table checks use SHOW TABLES LIKE
- `hr-suite.php` - table_exists/column_exists closures use SHOW TABLES LIKE / SHOW COLUMNS FROM; sessions ENUM check and admin_notices use SHOW
- `includes/Core/Admin.php` - 9 table existence checks converted to SHOW TABLES LIKE
- `includes/Core/Notifications.php` - table_exists() method uses SHOW TABLES LIKE
- `includes/Core/Admin/Services/ReportsService.php` - document table check uses SHOW TABLES LIKE
- `includes/Modules/Attendance/Migration.php` - ENGINE check uses SHOW TABLE STATUS LIKE; STATISTICS annotated migration-only
- `includes/Modules/Attendance/Services/Shift_Service.php` - 2 table checks use SHOW TABLES LIKE
- `includes/Modules/Documents/DocumentsModule.php` - table and column checks use SHOW equivalents
- `includes/Modules/Employees/Admin/class-my-profile-page.php` - 3 table checks use SHOW TABLES LIKE
- `includes/Modules/Employees/Admin/class-employee-profile-page.php` - 7 table checks use SHOW TABLES LIKE
- `includes/Modules/Reminders/Services/class-reminders-service.php` - birth_date column check uses SHOW COLUMNS FROM
- `includes/Modules/Projects/Services/class-projects-service.php` - cached table check uses SHOW TABLES LIKE
- `includes/Modules/Assets/Admin/class-admin-pages.php` - asset_logs table check uses SHOW TABLES LIKE
- `includes/Modules/ShiftSwap/ShiftSwapModule.php` - table check uses SHOW TABLES LIKE; STATISTICS annotated migration-only

## Decisions Made

- Retained information_schema.STATISTICS in `add_index_if_missing` and `add_unique_key_if_missing` helpers (Migrations.php, Attendance/Migration.php, ShiftSwap) — these are migration-only code inside version-gated or option-gated blocks; MySQL has no SHOW equivalent that supports WHERE on index name.
- Retained information_schema.TABLE_CONSTRAINTS in the FK migration closure in Attendance/Migration.php — it is option-gated, runs at most once, and there is no clean SHOW alternative for foreign key constraint names.
- SHOW COLUMNS FROM returns associative rows with `Type` (not `COLUMN_TYPE`) and `Null` (not `IS_NULLABLE`). Updated make_column_nullable_if_exists to check `$row['Null'] === 'NO'` and make_text_if_varchar255 to check `stripos($row['Type'], 'varchar(255)') === 0`.

## Deviations from Plan

None - plan executed exactly as written. All substitutions followed the interface patterns defined in the plan context.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- SQL-02 (information_schema elimination) is complete
- All future module code should follow the established SHOW TABLES LIKE / SHOW COLUMNS FROM patterns documented in patterns-established
- Phase 26 (SQL injection fixes) can proceed on clean migration infrastructure

---
*Phase: 25-migration-pattern-fixes*
*Completed: 2026-03-18*
