# Attendance Module Refactor — Phase 2

## What This Is

A structural refactor of the AttendanceModule god-class in Simple HR Suite. Phase 1 (pre-existing) extracted 5 service classes. Phase 2 (v1.0) extracted shortcode views and migration logic, reducing AttendanceModule.php from ~5390 lines to a 434-line clean orchestrator.

## Core Value

AttendanceModule.php is a clean orchestrator that delegates to focused classes, making the attendance module maintainable and each piece independently testable.

## Requirements

### Validated

- ✓ Period_Service extracted — existing
- ✓ Shift_Service extracted — existing
- ✓ Early_Leave_Service extracted — existing
- ✓ Session_Service extracted — existing
- ✓ Policy_Service extracted — existing
- ✓ Extract shortcode_widget() into Frontend/Widget_Shortcode.php — v1.0
- ✓ Extract shortcode_kiosk() into Frontend/Kiosk_Shortcode.php — v1.0
- ✓ All inline JS/CSS preserved as-is — v1.0
- ✓ Shortcode methods become thin delegates — v1.0
- ✓ Extract maybe_install() into Migration.php — v1.0
- ✓ Migration follows CREATE TABLE + add_column_if_missing pattern — v1.0
- ✓ AttendanceModule calls Migration from existing hook — v1.0
- ✓ Remove orphaned helper/delegate methods — v1.0
- ✓ AttendanceModule.php under 500 lines — v1.0
- ✓ Zero behavior change — v1.0

### Active

(None — next milestone not yet defined)

### Out of Scope

- Extracting JS/CSS to separate asset files — high risk of breakage, deferred
- Unit/integration tests — separate effort
- Refactoring other modules — this project is Attendance-only
- Changing user-facing behavior — pure structural refactor

## Context

Shipped v1.0 with 18,094 LOC PHP across the Attendance module.
Tech stack: WordPress, PHP 8.2+, `$wpdb` direct queries.
AttendanceModule.php is now 434 lines — a thin orchestrator delegating to:
- `Services/` — Period, Shift, Early_Leave, Session, Policy
- `Frontend/` — Widget_Shortcode, Kiosk_Shortcode
- `Migration.php` — table creation, capabilities, seed data

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Frontend/ subdirectory for views | Matches existing module pattern | ✓ Good |
| Module-local Migration.php | Keeps module self-contained | ✓ Good |
| Keep inline JS/CSS | Minimal risk — move code as-is | ✓ Good |
| Phase 1 service extraction | Already validated in commit ea325b3 | ✓ Good |
| Verbatim extraction, no refactoring | Zero behavior change guarantee | ✓ Good |
| self:: → AttendanceModule:: in extracted classes | Required for correct static dispatch outside original class | ✓ Good |
| Instance methods on Migration class | Cleaner than static helpers for extraction | ✓ Good |
| Eliminate middleman delegates | backfill_early_leave calls Early_Leave_Service directly | ✓ Good |

## Constraints

- **Zero behavior change**: Pure structural refactor
- **Inline assets preserved**: JS/CSS remain inline in view output
- **Migration pattern**: CREATE TABLE IF NOT EXISTS + add_column_if_missing()
- **PHP namespace**: SFS\HR\Modules\Attendance\*

---
*Last updated: 2026-03-10 after v1.0 milestone*
