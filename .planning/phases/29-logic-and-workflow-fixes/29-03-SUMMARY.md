---
phase: 29-logic-and-workflow-fixes
plan: "03"
subsystem: database
tags: [transactions, race-conditions, toctou, mysql-locks, leave, loans, reference-numbers]

# Dependency graph
requires:
  - phase: 29-logic-and-workflow-fixes
    plan: "02"
    provides: ALLOWED_TRANSITIONS guards already applied to LeaveModule.php status-change handlers
provides:
  - Transaction-wrapped leave overlap check + insert (frontend and admin paths)
  - Row-locking has_overlap_locked() method in LeaveCalculationService
  - Transaction-wrapped loan fiscal year check + active loan check + insert
  - Atomic reference number generation via MySQL named locks
affects: [leave, loans, helpers, concurrent-requests]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "START TRANSACTION / FOR UPDATE / COMMIT / ROLLBACK for check-then-act sequences"
    - "MySQL GET_LOCK / RELEASE_LOCK for atomic counters when caller may already be in a transaction"

key-files:
  created: []
  modified:
    - includes/Modules/Leave/LeaveModule.php
    - includes/Modules/Leave/Services/LeaveCalculationService.php
    - includes/Modules/Loans/Frontend/class-my-profile-loans.php
    - includes/Core/Helpers.php

key-decisions:
  - "has_overlap_locked() added as new method alongside has_overlap() to preserve backward compatibility — existing callers outside transactions continue working"
  - "Named lock (GET_LOCK) chosen for generate_reference_number() instead of FOR UPDATE because callers (e.g. Payroll) may already be inside a transaction; nested transactions are not supported in MySQL/InnoDB"
  - "Lock name scoped per prefix+year (sfs_hr_ref_{prefix}_{year}) so LV, LN, EL etc. don't contend with each other"
  - "5-second lock timeout on GET_LOCK; proceeds optimistically on timeout with error_log warning since UNIQUE constraint on column catches any actual collision"
  - "ROLLBACK added to every early-return error path between START TRANSACTION and the insert in both leave creation paths"

patterns-established:
  - "Transactional check-then-act: START TRANSACTION before first SELECT that guards the INSERT; FOR UPDATE on that SELECT; ROLLBACK on all error returns; COMMIT after INSERT"
  - "Named lock for reference number generation: GET_LOCK before MAX() read, RELEASE_LOCK after number is computed — session-scoped so released on connection drop"

requirements-completed: [LOGIC-01]

# Metrics
duration: 20min
completed: 2026-03-18
---

# Phase 29 Plan 03: TOCTOU Race Condition Fixes Summary

**DB transaction + row-level lock wrapping for leave overlap, loan fiscal year, and atomic reference number generation to eliminate concurrent duplicate submissions**

## Performance

- **Duration:** 20 min
- **Started:** 2026-03-18T04:25:00Z
- **Completed:** 2026-03-18T04:45:00Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments

- Wrapped both leave request creation paths (frontend shortcode + admin handle_create) in START TRANSACTION with FOR UPDATE overlap check, with ROLLBACK on all error paths and COMMIT after successful insert
- Added `has_overlap_locked()` to `LeaveCalculationService` with `FOR UPDATE` to prevent gap between read and insert under concurrent requests
- Wrapped loan fiscal year check, active loan count check, and insert in a single transaction with FOR UPDATE on both SELECT queries
- Made `generate_reference_number()` atomic using MySQL named locks (GET_LOCK/RELEASE_LOCK) which safely compose with existing transactional callers

## Task Commits

Each task was committed atomically:

1. **Task 1: Add transaction wrapping to Leave and Loan request creation** - `91f0e5d` (fix)
2. **Task 2: Make reference number generation atomic** - `e25660c` (fix)

## Files Created/Modified

- `includes/Modules/Leave/LeaveModule.php` - START TRANSACTION before overlap check in both frontend and admin creation paths; ROLLBACK on all error returns; COMMIT after successful insert
- `includes/Modules/Leave/Services/LeaveCalculationService.php` - Added `has_overlap_locked()` with `FOR UPDATE`; existing `has_overlap()` retained for non-transactional callers
- `includes/Modules/Loans/Frontend/class-my-profile-loans.php` - Transaction wraps fiscal year + active loan count checks + insert; FOR UPDATE on both SELECT queries
- `includes/Core/Helpers.php` - `generate_reference_number()` uses GET_LOCK/RELEASE_LOCK named locks scoped per prefix+year

## Decisions Made

- `has_overlap_locked()` added as a new sibling to `has_overlap()` rather than modifying the existing method — the private `has_overlap()` wrapper on LeaveModule (line ~5063) calls the original `LeaveCalculationService::has_overlap()` and is used in read-only contexts; both callers inside the transaction now use `has_overlap_locked()` directly
- Named lock chosen over FOR UPDATE for `generate_reference_number()` because several callers (Payroll, Settlement) already open their own transactions before calling it; MySQL does not support nested transactions, so FOR UPDATE inside a new transaction would require SAVEPOINT complexity
- Proceeds optimistically if GET_LOCK times out: the UNIQUE constraint on the reference number column provides a final safety net, and the caller can retry

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None - all four files modified cleanly. The Edit tool required splitting the large frontend leave path changes into several smaller targeted edits due to CRLF line endings in the file, but no logic changes were needed beyond what the plan specified.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 29 plans 01, 02, and 03 are all complete — the phase is finished
- All TOCTOU race conditions identified in LOGIC-01 have been addressed
- Payroll and Attendance module existing protections (transient lock + transaction, GET_LOCK) were confirmed in place and left unchanged

---
*Phase: 29-logic-and-workflow-fixes*
*Completed: 2026-03-18*
