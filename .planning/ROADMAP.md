# Roadmap: Attendance Module Refactor - Phase 2

## Overview

This roadmap covers the second phase of the AttendanceModule god-class refactoring. The work extracts ~4900 remaining lines from AttendanceModule.php into focused classes — two large shortcode views into Frontend/, migration logic into a module-local Migration class — then cleans up the module file to a thin orchestrator under 500 lines. Three phases follow the natural structure: extract views first (largest piece), extract migration logic, then consolidate the orchestrator.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Views Extraction** - Extract shortcode_widget and shortcode_kiosk into Frontend/ classes (completed 2026-03-09)
- [ ] **Phase 2: Migration Extraction** - Extract maybe_install into module-local Migration class
- [ ] **Phase 3: Orchestrator Cleanup** - Slim AttendanceModule.php to a thin orchestrator under 500 lines

## Phase Details

### Phase 1: Views Extraction
**Goal**: Shortcode rendering logic lives in dedicated Frontend classes, keeping all inline assets intact
**Depends on**: Nothing (first phase)
**Requirements**: VIEW-01, VIEW-02, VIEW-03, VIEW-04
**Success Criteria** (what must be TRUE):
  1. `Frontend/Widget_Shortcode.php` exists with a `render()` method that produces identical output to the current `shortcode_widget()`
  2. `Frontend/Kiosk_Shortcode.php` exists with a `render()` method that produces identical output to the current `shortcode_kiosk()`
  3. All inline JS and CSS remains embedded in the rendered output (no external asset files created)
  4. AttendanceModule shortcode registration methods delegate to the new Frontend classes
**Plans:** 2/2 plans complete

Plans:
- [x] 01-01-PLAN.md — Extract Widget_Shortcode into Frontend/ class
- [x] 01-02-PLAN.md — Extract Kiosk_Shortcode into Frontend/ class and complete delegation wiring

### Phase 2: Migration Extraction
**Goal**: Attendance migration logic is encapsulated in its own class following existing project patterns
**Depends on**: Phase 1
**Requirements**: MIGR-01, MIGR-02, MIGR-03
**Success Criteria** (what must be TRUE):
  1. `Migration.php` exists in the Attendance module directory with all table creation and column-add logic from `maybe_install()`
  2. Migration class uses `CREATE TABLE IF NOT EXISTS` and `add_column_if_missing()` patterns consistently
  3. AttendanceModule calls the Migration class from its existing activation/admin_init hook — migration behavior unchanged
**Plans:** 1 plan

Plans:
- [ ] 02-01-PLAN.md — Extract migration, capability, and seed logic into Migration class

### Phase 3: Orchestrator Cleanup
**Goal**: AttendanceModule.php is a clean orchestrator that only wires dependencies and delegates
**Depends on**: Phase 1, Phase 2
**Requirements**: CLEN-01, CLEN-02, CLEN-03
**Success Criteria** (what must be TRUE):
  1. AttendanceModule.php is under 500 lines of code
  2. No orphaned helper methods remain that duplicate logic now living in extracted classes
  3. All existing user-facing functionality works identically — zero behavior change from before the refactor
**Plans**: TBD

Plans:
- [ ] 03-01: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 -> 2 -> 3

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Views Extraction | 2/2 | Complete   | 2026-03-09 |
| 2. Migration Extraction | 0/1 | Not started | - |
| 3. Orchestrator Cleanup | 0/? | Not started | - |
