---
phase: 30-leave-handler-safety-hardening
plan: "01"
subsystem: Leave
tags: [bug-fix, race-condition, cache, transaction, safety]
dependency_graph:
  requires: []
  provides: [LOGIC-02-fix, LOGIC-01-fix, PERF-03-fix]
  affects: [LeaveModule, leave-status-mutations, leave-balance-integrity]
tech_stack:
  added: []
  patterns:
    - DB transaction wrapping (START TRANSACTION / FOR UPDATE / COMMIT)
    - Transient cache invalidation via LIKE delete on wp_options
key_files:
  modified:
    - includes/Modules/Leave/LeaveModule.php
decisions:
  - "COMMIT placed after balance insert/update block; no ROLLBACK handler added — MySQL auto-rollbacks uncommitted transactions on connection close (matches Phase 29 pattern)"
  - "START TRANSACTION placed before final status UPDATE so status change and balance update are atomic"
  - "invalidate_leave_caches() uses direct DELETE LIKE for sfs_hr_leave_counts_* because key is md5-scoped; uses delete_transient() for fixed-key dashboard counts"
  - "handle_cancel_approved() correctly skipped for cache invalidation — it only creates a cancellation record, not a leave status change"
metrics:
  duration_seconds: 150
  completed_date: "2026-03-18"
  tasks_completed: 2
  tasks_total: 2
  files_modified: 1
  commits: 2
---

# Phase 30 Plan 01: Leave Handler Safety Hardening Summary

**One-liner:** Three gap closures in LeaveModule.php — reject guard fall-through fixed with exit, approve balance race condition fixed with DB transaction + FOR UPDATE, and all 5 mutation handlers now invalidate leave transient caches.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Fix reject guard exit + transaction-wrap approve balance | 1789dd9 | includes/Modules/Leave/LeaveModule.php |
| 2 | Add transient cache invalidation after all leave status mutations | 4dca293 | includes/Modules/Leave/LeaveModule.php |

## Changes Applied

### LOGIC-02: Missing exit after redirect in handle_reject() (Task 1)

Two guards in `handle_reject()` were missing `exit;` after `wp_safe_redirect()`, allowing execution to fall through into rejection logic:

- `if ($id<=0)` guard at ~line 1794 — added braces and `exit;`
- `if (!$row || !$this->is_valid_transition(...))` guard at ~line 1801 — added braces and `exit;`

### LOGIC-01: Race condition in handle_approve() balance update (Task 1)

The balance read-before-write in `handle_approve()` was outside any transaction, allowing concurrent dual-approvals to corrupt the leave balance:

- Added `$wpdb->query('START TRANSACTION')` before the final status UPDATE (so status change and balance update are atomic)
- Changed the balance `SELECT` to use `FOR UPDATE` to lock the row during concurrent access
- Added `$wpdb->query('COMMIT')` after the balance insert/update block

### PERF-03: Missing transient cache invalidation (Task 2)

Added `private function invalidate_leave_caches(): void` to LeaveModule:
- Deletes all `sfs_hr_leave_counts_*` transients via `DELETE ... LIKE` on `wp_options` (md5-scoped key, fixed-key API not usable)
- Deletes `sfs_hr_admin_dashboard_counts` transient via `delete_transient()`

Called `$this->invalidate_leave_caches()` after successful status mutations in:
1. `handle_approve()` — after COMMIT, before notify_requester
2. `handle_reject()` — after log_event, before notify_requester
3. `handle_cancel()` — after do_action, before final redirect
4. `handle_cancellation_approve()` — after balance_restored log_event, before notify_requester
5. `handle_cancellation_reject()` — after cancellation_rejected log_event, before notify_hr_users

`handle_cancel_approved()` was intentionally skipped — it only creates a cancellation record, it does not change the leave request status.

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check

- [x] File exists: includes/Modules/Leave/LeaveModule.php (modified)
- [x] Commit 1789dd9 exists — Task 1 (reject guard exit + transaction)
- [x] Commit 4dca293 exists — Task 2 (cache invalidation)
- [x] 6 occurrences of `invalidate_leave_caches` (1 definition + 5 call sites)
- [x] START TRANSACTION, FOR UPDATE, COMMIT present in handle_approve()
- [x] exit; present after both redirect guards in handle_reject()

## Self-Check: PASSED
