---
phase: 29-logic-and-workflow-fixes
plan: "02"
subsystem: Leave, Settlement, Performance
tags: [state-machine, transition-guards, leave, settlement, performance]
dependency_graph:
  requires: []
  provides: [LOGIC-02]
  affects:
    - includes/Modules/Leave/LeaveModule.php
    - includes/Modules/Settlement/Services/class-settlement-service.php
    - includes/Modules/Performance/Services/Reviews_Service.php
tech_stack:
  added: []
  patterns:
    - ALLOWED_TRANSITIONS constant (private const) for state machine definition
    - validate_transition()/is_valid_transition() helpers centralizing guard logic
key_files:
  created: []
  modified:
    - includes/Modules/Leave/LeaveModule.php
    - includes/Modules/Settlement/Services/class-settlement-service.php
    - includes/Modules/Performance/Services/Reviews_Service.php
decisions:
  - "Leave transition map added cancel_pending to on_leave transitions to match existing handle_cancel_approved behavior that allows on_leave leaves to initiate cancellation"
  - "Leave transition map added cancelled to both approved and on_leave because handle_cancellation_approve HR approval skips a cancel_pending DB state and goes directly to cancelled"
  - "handle_cancellation_approve and handle_cancellation_reject guard on leave cancellability via is_valid_transition rather than adding a dedicated cancel_pending DB status"
metrics:
  duration_minutes: 3
  completed_date: "2026-03-18"
  tasks_completed: 2
  files_modified: 3
---

# Phase 29 Plan 02: State Machine Transition Guards Summary

**One-liner:** State machine ALLOWED_TRANSITIONS constants and guard checks added to Leave, Settlement, and Performance modules preventing invalid status transitions.

## What Was Built

Three modules now enforce explicit state transition rules via ALLOWED_TRANSITIONS maps, blocking invalid status changes (e.g., approved-to-pending, cancelled-to-approved, paid-to-pending) before they reach the database.

### Settlement (`class-settlement-service.php`)

- Added `private const ALLOWED_TRANSITIONS` to `Settlement_Service`: `pending -> approved/rejected`, `approved -> paid`, terminal states `rejected` and `paid`
- Added guard in `update_status()` after reading `$old_status`: checks `ALLOWED_TRANSITIONS[$old]`, returns `false` and logs an error if the transition is invalid
- Guard fires before `$wpdb->update()`, ensuring no invalid transition can persist

### Performance Reviews (`Reviews_Service.php`)

- Added `private const ALLOWED_TRANSITIONS` to `Reviews_Service`: `draft -> pending/submitted`, `pending -> submitted`, `submitted -> acknowledged`, terminal `acknowledged`
- Added `private static function validate_transition(string $from, string $to)` helper returning `true` or `WP_Error`
- `acknowledge_review()`: replaced `$review->status !== 'submitted'` check with `validate_transition($review->status, 'acknowledged')`
- `submit_review()`: replaced `$review->status !== 'draft' && $review->status !== 'pending'` check with `validate_transition($review->status, 'submitted')`

### Leave (`LeaveModule.php`)

- Added `private const ALLOWED_TRANSITIONS` with 11 status entries covering the full leave lifecycle including pending sub-states, cancellation workflow, and terminal states
- Added `private function is_valid_transition(string $from, string $to): bool` helper
- Guards added to 6 handlers:
  - `handle_approve()`: replaced narrow `'pending'` check with explicit `$valid_approve_from = ['pending', 'pending_gm', 'pending_hr']` guard
  - `handle_reject()`: `is_valid_transition($row['status'], 'rejected')` — allows rejection from all pre-decision states
  - `handle_cancel()`: `is_valid_transition($row['status'], 'cancelled')` — only pending requests can be directly cancelled
  - `handle_cancel_approved()`: `is_valid_transition($row['status'], 'cancel_pending')` — replaces inline array check
  - `handle_cancellation_approve()`: added guard that `$leave['status']` can still transition to `'cancelled'`
  - `handle_cancellation_reject()`: added guard that `$leave['status']` can still transition to `'cancelled'`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Leave transition map extended to match existing cancellation workflow**

- **Found during:** Task 2
- **Issue:** The plan's ALLOWED_TRANSITIONS map had `on_leave => ['returned', 'early_returned']` but the existing `handle_cancel_approved()` correctly allows `on_leave` leaves to initiate cancellation, and `handle_cancellation_approve()` (HR final approval) transitions the leave directly from `approved`/`on_leave` to `cancelled` without a `cancel_pending` DB state
- **Fix:** Added `cancel_pending` and `cancelled` to the `on_leave` transitions; added `cancelled` to the `approved` transitions to match the real workflow where the cancellation approval chain finalizes directly to `cancelled`
- **Files modified:** `includes/Modules/Leave/LeaveModule.php`
- **Commit:** 07331e4

## Commits

| Task | Commit | Message |
|------|--------|---------|
| Task 1 | 1a364fa | feat(29-02): add state transition guards to Settlement and Performance modules |
| Task 2 | 07331e4 | feat(29-02): add state transition guards to Leave module |

## Self-Check: PASSED

- `includes/Modules/Leave/LeaveModule.php` — modified with ALLOWED_TRANSITIONS and 6 guards
- `includes/Modules/Settlement/Services/class-settlement-service.php` — modified with ALLOWED_TRANSITIONS and update_status guard
- `includes/Modules/Performance/Services/Reviews_Service.php` — modified with ALLOWED_TRANSITIONS and validate_transition helper
- Commits 1a364fa and 07331e4 verified in git log
