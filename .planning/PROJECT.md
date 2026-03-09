# Attendance Module Refactor — Phase 2

## What This Is

A continuation of the AttendanceModule god-class refactoring in Simple HR Suite. Phase 1 extracted 5 service classes (Period, Shift, Early_Leave, Session, Policy). Phase 2 extracts the remaining ~4900 lines — shortcode views, migration logic, and helper methods — to reduce AttendanceModule.php to a thin orchestrator under 500 lines.

## Core Value

AttendanceModule.php becomes a clean orchestrator that delegates to focused classes, making the attendance module maintainable and each piece independently testable.

## Requirements

### Validated

- ✓ Period_Service extracted — existing
- ✓ Shift_Service extracted — existing
- ✓ Early_Leave_Service extracted — existing
- ✓ Session_Service extracted — existing
- ✓ Policy_Service extracted — existing

### Active

- [ ] Extract shortcode_widget() (~1900 lines) into Frontend/Widget_Shortcode.php
- [ ] Extract shortcode_kiosk() (~2800 lines) into Frontend/Kiosk_Shortcode.php
- [ ] Extract maybe_install() (~300 lines) into module-local Migration.php
- [ ] Review and clean up remaining helper/delegate methods
- [ ] AttendanceModule.php reduced to under 500 lines as thin orchestrator

### Out of Scope

- Extracting JS/CSS to separate asset files — too high risk of breakage for this refactor
- Adding unit/integration tests — separate effort after refactor
- Refactoring other modules — this is Attendance-only
- Changing any user-facing behavior — pure structural refactor

## Context

- AttendanceModule.php is currently ~5390 lines
- Two massive shortcodes account for ~4700 lines: `shortcode_widget` (employee self-service) and `shortcode_kiosk` (terminal mode)
- Both contain inline HTML, JS, and CSS that should move as-is into view classes
- The module already has Admin/, Cron/, Rest/, Services/ subdirectories
- Frontend/ subdirectory will be created to match existing module patterns
- Inline JS/CSS stays embedded in view output to minimize risk
- Migration logic uses the project's `add_column_if_missing()` pattern

## Constraints

- **Zero behavior change**: This is a pure structural refactor — no functional changes
- **Inline assets preserved**: JS/CSS remain inline in view render output, not extracted to files
- **Migration pattern**: Must follow existing `CREATE TABLE IF NOT EXISTS` + `add_column_if_missing()` pattern
- **PHP namespace**: New classes under `SFS\HR\Modules\Attendance\*`

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Frontend/ subdirectory for views | Matches existing module pattern used elsewhere in the plugin | — Pending |
| Module-local Migration.php | Keeps module self-contained; migration logic is Attendance-specific | — Pending |
| Keep inline JS/CSS | Minimal risk — move code as-is, verify behavior unchanged | — Pending |
| Phase 1 service extraction | Already validated in previous refactor commit (ea325b3) | ✓ Good |

---
*Last updated: 2026-03-09 after initialization*
