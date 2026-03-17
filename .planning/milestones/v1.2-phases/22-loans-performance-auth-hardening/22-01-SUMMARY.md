---
phase: 22-loans-performance-auth-hardening
plan: 01
subsystem: auth
tags: [nonce, wordpress, loans, security, admin-handlers]

# Dependency graph
requires:
  - phase: 21-hiring-leave-auth-hardening
    provides: established pattern of nonce-before-capability, explicit wp_die rejections
provides:
  - Loans admin handler with nonce verified before any POST data read per action case
  - No installment nonces exposed in DOM data attributes
  - Explicit wp_die rejections for unauthorized loan actions
affects: [loans-module, installment-payments]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Nonce-before-data: each action case reads minimum POST field (loan_id), then check_admin_referer, then capability check, then remaining POST data"
    - "Nonce map pattern: PHP generates sfsInstNonces JS object inside script block, JS reads from map by ID instead of DOM data attributes"

key-files:
  created: []
  modified:
    - includes/Modules/Loans/Admin/class-admin-pages.php

key-decisions:
  - "Removed top-level loan_id read and top-level capability switch from handle_loan_actions — each case manages its own loan_id read, nonce check, and capability check independently"
  - "create_loan nonce remains static (sfs_hr_loan_create, not scoped to employee_id) — employee_id read moved to after capability check"
  - "Installment nonces moved from data-nonce DOM attributes into inline sfsInstNonces JS object — still per-installment, server-side check_admin_referer unchanged"
  - "update_loan/record_payment stub cases now verify nonce first (sfs_hr_loan_{action}) before capability, consistent with the pattern"
  - "Unauthorized actions use explicit wp_die rejection instead of silent return, matching Phase 21 established pattern"

patterns-established:
  - "Pattern: loan action handlers use loan_id -> check_admin_referer -> capability check ordering within each case"
  - "Pattern: installment nonces delivered via PHP-generated JS object map, not DOM attributes"

requirements-completed: [LOAN-AUTH-01, LOAN-AUTH-02, LOAN-AUTH-03, LOAN-AUTH-04]

# Metrics
duration: 20min
completed: 2026-03-17
---

# Phase 22 Plan 01: Loans Admin Auth Hardening Summary

**Loans nonce ordering fixed and installment nonces removed from DOM — nonce verified before any POST data read per action, per-installment nonces delivered via inline JS object instead of data attributes**

## Performance

- **Duration:** 20 min
- **Started:** 2026-03-17T14:10:00Z
- **Completed:** 2026-03-17T14:30:00Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- Restructured `handle_loan_actions()` so each case (approve_gm, approve_finance, reject_loan, cancel_loan, create_loan) reads its loan_id, verifies nonce, then checks capability before reading any other POST data
- Removed top-level `$loan_id` read at line 2052 and top-level capability switch (lines 2054-2084) that ran before nonce verification
- Removed `data-nonce` attribute from all installment table rows; replaced with `sfsInstNonces` PHP-generated JS object map inside the script block
- All three installment action forms (mark-paid, mark-skipped, partial payment) now pull nonces from `sfsInstNonces[id]`

## Task Commits

Each task was committed atomically:

1. **Task 1: Fix nonce-before-data ordering in handle_loan_actions** - `d7161ac` (fix)
2. **Task 2: Remove installment nonces from DOM data attributes** - `ddc8d2d` (fix)

**Plan metadata:** (docs commit to follow)

## Files Created/Modified

- `includes/Modules/Loans/Admin/class-admin-pages.php` - Fixed nonce ordering in handle_loan_actions; removed data-nonce from installment rows; added sfsInstNonces JS map; updated all JS nonce references

## Decisions Made

- Removed the top-level capability switch entirely rather than keeping it alongside per-case checks — the per-case checks are authoritative and the old switch was redundant after restructuring
- Used `sanitize_key()` on `$_POST['loan_action']` at the top of `handle_loan_actions()` — the only safe read before the switch
- The `update_loan` and `record_payment` stub cases now have nonce checks (`sfs_hr_loan_{action}`) for consistency even though they return early — this prevents the cases from being exploitable if implementation is added later

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- LOAN-AUTH-01 through LOAN-AUTH-04 requirements satisfied
- Ready for Phase 22 Plan 02 (Performance auth hardening) or any subsequent loans security work
- No blockers

---
*Phase: 22-loans-performance-auth-hardening*
*Completed: 2026-03-17*
