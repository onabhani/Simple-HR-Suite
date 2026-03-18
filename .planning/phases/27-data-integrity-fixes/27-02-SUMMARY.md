---
phase: 27-data-integrity-fixes
plan: "02"
subsystem: Leave
tags: [bug-fix, data-integrity, security, nonce, leave-balance, tenure]
dependency_graph:
  requires: []
  provides: [correct-leave-balance-recalculation, anniversary-based-tenure, per-request-reject-nonce]
  affects: [sfs_hr_leave_balances, LeaveModule, LeaveCalculationService]
tech_stack:
  added: []
  patterns: [read-before-write balance pattern, per-request scoped nonce]
key_files:
  created: []
  modified:
    - includes/Modules/Leave/LeaveModule.php
    - includes/Modules/Leave/Services/LeaveCalculationService.php
decisions:
  - Read existing balance row before computing closing to preserve opening and carried_over in all 3 recalculation paths
  - Use anniversary date (hire_date MM-DD in target year) for tenure calculation, with Mar 1 fallback for Feb 29 hire dates
  - Per-request nonce sfs_hr_leave_reject_{id} applied to all 6 nonce creation/verification points (handler check, JS list data, detail view variable, 4 inline wp_nonce_field calls)
metrics:
  duration_minutes: 5
  tasks_completed: 2
  files_modified: 2
  completed_date: "2026-03-18"
requirements: [DATA-02, DATA-04, DEBT-01]
---

# Phase 27 Plan 02: Leave Balance Integrity and Nonce Security Summary

**One-liner:** Fixed leave balance corruption in 3 handler paths by reading existing opening/carried_over before update, corrected tenure boundary to use hire anniversary instead of Jan 1, and replaced shared rejection nonce with per-request scoped nonce.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Fix balance corruption in handle_approve, cancellation approve, and early-return shorten | ccf58bc | includes/Modules/Leave/LeaveModule.php |
| 2 | Fix tenure boundary to use hire anniversary + replace shared reject nonce | 8acd08e | includes/Modules/Leave/LeaveModule.php, includes/Modules/Leave/Services/LeaveCalculationService.php |

## Changes Made

### Task 1: Leave Balance Corruption Fix (DATA-02)

Three locations in LeaveModule.php used `$opening = 0; $carried = 0; $accrued = $quota;` before computing the closing balance, zeroing out any existing opening balance or carried-over days on every approve, cancel, or shorten operation.

All three locations now issue a `SELECT opening, accrued, carried_over FROM sfs_hr_leave_balances` before the closing calculation. If `accrued` is zero in the existing row (new row insert path), it falls back to `$quota`. This preserves user-entered and carry-forward values.

Locations fixed:
- `handle_approve()` — balance recalculation after approval (with full insert/update path)
- `handle_cancellation_approve()` — balance restore when cancellation is approved
- `handle_early_return()` shorten path — balance update after early return shortens leave days

### Task 2: Tenure Boundary Fix (DATA-04)

`LeaveCalculationService::compute_quota_for_year()` previously computed tenure as of `{year}-01-01`. An employee hired 2022-06-15 would not receive the 5-year (30-day) entitlement until Jan 1 2028, when the correct date is Jun 15 2027.

Fixed to compute tenure at the employee's anniversary date within the target year (`{year}-{MM}-{DD}`). Feb 29 hire dates fall back to Mar 1 to avoid `strtotime` returning false.

### Task 2: Per-Request Reject Nonce (DEBT-01)

The approve action already used per-request nonce `sfs_hr_leave_approve_{id}`, but reject used a shared `sfs_hr_leave_reject` nonce. This allowed a nonce generated for one request to be replayed to reject a different request.

Six points updated:
1. `handle_reject()` — `check_admin_referer('sfs_hr_leave_reject_' . $id)` (moved after `$id` is read)
2. List view `$nonceR` shared variable — removed entirely
3. List view JS data array — `wp_create_nonce('sfs_hr_leave_reject_' . $r['id'])` per row
4. Detail view `$nonce_reject` variable — `wp_create_nonce('sfs_hr_leave_reject_' . $request_id)`
5-8. Detail view inline `wp_nonce_field` calls for all 4 approval stages (GM, Manager, HR, Finance) — `'sfs_hr_leave_reject_' . $request_id`

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical fix] Additional reject nonce fields in detail view forms**

- **Found during:** Task 2
- **Issue:** Plan mentioned updating line ~6887 and the JS data array, but the detail view contains 4 separate reject forms (one per approval stage: GM, Manager, HR, Finance) each with their own `wp_nonce_field('sfs_hr_leave_reject', '_wpnonce')` at lines 7070, 7093, 7125, and 7156.
- **Fix:** Used `replace_all` to update all 4 `wp_nonce_field` calls to use `sfs_hr_leave_reject_{request_id}`. Without this, the nonce fix would be incomplete — the handler would reject form submissions from the detail view.
- **Files modified:** includes/Modules/Leave/LeaveModule.php
- **Commit:** 8acd08e

## Verification Results

1. `$opening = 0` in recalculation paths — zero matches (all 3 locations fixed)
2. `01-01` in LeaveCalculationService — zero matches (anniversary-based)
3. Bare `sfs_hr_leave_reject'` nonce — zero nonce creation/verification matches (only action hook registration remains, which is correct)
4. Per-request `sfs_hr_leave_reject_` — 7 occurrences (handler check + list JS + detail variable + 4 inline forms)

## Self-Check: PASSED

Files verified:
- [x] includes/Modules/Leave/LeaveModule.php — modified and committed (ccf58bc, 8acd08e)
- [x] includes/Modules/Leave/Services/LeaveCalculationService.php — modified and committed (8acd08e)
