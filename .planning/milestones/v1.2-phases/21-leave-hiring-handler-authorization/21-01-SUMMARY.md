---
phase: 21-leave-hiring-handler-authorization
plan: 01
subsystem: auth
tags: [leave, authorization, nonce, capability-check, self-approval]

# Dependency graph
requires:
  - phase: 20-attendance-endpoint-authentication
    provides: auth pattern established for admin_post handlers
provides:
  - Capability-gated leave approval and cancel handlers with scoped nonces
  - is_hr_user() that requires explicit HR assignment (not bare manager role)
  - Self-approval prevention for cancellation requester in handle_cancellation_approve()
affects: [leave-module, 21-leave-hiring-handler-authorization]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Capability gate before nonce check in admin_post handlers (check capability first, read POST data, then verify scoped nonce)"
    - "Per-request nonce scoping: sfs_hr_leave_approve_{id} prevents replay across different leave requests"
    - "is_hr_user() checks explicit HR approver list and capabilities only — role membership alone is insufficient"

key-files:
  created: []
  modified:
    - includes/Modules/Leave/LeaveModule.php

key-decisions:
  - "Capability check placed before nonce check in handle_approve() — capability is cheaper and fails fast for unauthorized users"
  - "Bare sfs_hr_manager role removed from is_hr_user() — department managers should not automatically have HR-level leave management access"
  - "cancellation self-approval uses created_by column (not requested_by) per actual DB schema in Migrations.php"

patterns-established:
  - "Leave handler auth order: capability check → read POST id → scoped nonce verify → business logic"
  - "Scoped nonces: always append _{id} to action-specific nonces when acting on a specific record"

requirements-completed:
  - LV-AUTH-01
  - LV-AUTH-02
  - LV-AUTH-03
  - LV-AUTH-04
  - LV-AUTH-05

# Metrics
duration: 15min
completed: 2026-03-17
---

# Phase 21 Plan 01: Leave Handler Authorization Summary

**Capability gates, per-request nonce scoping, and HR self-approval prevention in the leave approval and cancellation workflow**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-03-17T13:34:00Z
- **Completed:** 2026-03-17T13:49:59Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- handle_approve() now rejects users without `sfs_hr.leave.review` capability before any DB access
- handle_cancel() now blocks completely unprivileged users (no `sfs_hr.leave.review` or `sfs_hr.view`) at the handler entry point
- All six approve nonce generation/verification sites updated from shared `sfs_hr_leave_approve` to per-request `sfs_hr_leave_approve_{id}` — prevents nonce replay across different leave requests
- is_hr_user() no longer returns true for bare `sfs_hr_manager` role — HR access requires explicit HR approver assignment or `sfs_hr.leave.manage` capability
- handle_cancellation_approve() blocks both the leave requester and the cancellation initiator (created_by) from approving their own cancellation

## Task Commits

Each task was committed atomically:

1. **Tasks 1 + 2: Auth guards + is_hr_user fix + self-approval prevention** - `625cfb1` (fix)

**Plan metadata:** _(docs commit pending)_

## Files Created/Modified

- `includes/Modules/Leave/LeaveModule.php` - All five authorization fixes applied (LV-AUTH-01 through LV-AUTH-05)

## Decisions Made

- Placed capability check before nonce check in handle_approve() — capability failure is cheaper and avoids leaking whether a valid nonce was submitted
- Used `$cancel['created_by']` for the cancellation self-approval check after confirming column name in Migrations.php (not `requested_by` as the plan initially suggested)
- Removed the `$nonceA` variable from the list view entirely — it is now generated inline per-row as `wp_create_nonce('sfs_hr_leave_approve_' . $r['id'])` inside the JS data mapping closure

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Used created_by instead of requested_by for cancellation self-approval check**
- **Found during:** Task 2 (is_hr_user fix and self-approval prevention)
- **Issue:** Plan draft referenced `$cancel['requested_by']` but the actual cancellations table schema in Migrations.php uses `created_by`
- **Fix:** Used `$cancel['created_by']` in the self-approval check
- **Files modified:** includes/Modules/Leave/LeaveModule.php
- **Verification:** grep confirms `created_by` column in Migrations.php schema
- **Committed in:** 625cfb1

---

**Total deviations:** 1 auto-fixed (1 bug — wrong column name in plan)
**Impact on plan:** Fix essential for correctness. No scope creep.

## Issues Encountered

- PHP binary not available in shell — syntax validation was done by careful manual inspection rather than `php -l`. All bracket/brace structure verified by reading the modified sections.

## Next Phase Readiness

- Leave handler auth fixes complete (LV-AUTH-01 through LV-AUTH-05)
- Ready for remaining Phase 21 plans (hiring handler authorization)

---
*Phase: 21-leave-hiring-handler-authorization*
*Completed: 2026-03-17*
