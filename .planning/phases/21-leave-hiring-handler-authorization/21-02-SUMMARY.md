---
phase: 21-leave-hiring-handler-authorization
plan: 02
subsystem: auth
tags: [capability-check, authorization, role-allowlist, password-reset, hiring, admin-post]

# Dependency graph
requires:
  - phase: 20-attendance-endpoint-authentication
    provides: Auth patterns established for admin_post handlers

provides:
  - Capability-gated hiring admin_post handlers (all 6)
  - Role allowlist blocking administrator escalation during hiring conversion
  - Secure welcome email using password reset link instead of plaintext password

affects:
  - hiring-module
  - candidate-conversion
  - trainee-conversion
  - welcome-email

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "capability-before-nonce: current_user_can() checked as first operation in admin_post handlers"
    - "role-allowlist: sanitize_hire_role() validates dept auto_role against sfs_hr_* allowlist before set_role()"
    - "password-reset-email: get_password_reset_key() + network_site_url(action=rp) instead of plaintext credentials"

key-files:
  created: []
  modified:
    - includes/Modules/Hiring/Admin/class-admin-pages.php
    - includes/Modules/Hiring/HiringModule.php

key-decisions:
  - "Capability check placed before nonce in all 6 hiring handlers — fails fast without revealing nonce validity"
  - "Role allowlist limited to sfs_hr_employee, sfs_hr_manager, sfs_hr_trainee, subscriber — blocks administrator and editor escalation"
  - "send_welcome_email() made public to allow reuse from class-admin-pages.php create_account handler"
  - "$password parameter kept in send_welcome_email() signature for backward compatibility but not sent in email body"
  - "get_password_reset_key() used for reset link — if it fails (WP_Error), email is silently skipped rather than sending incomplete email"

patterns-established:
  - "Pattern: All hiring admin_post handlers: current_user_can(sfs_hr.manage) → nonce verify → business logic"
  - "Pattern: All hiring role assignments: sanitize_hire_role(dept_role) validates against allowlist before set_role()"

requirements-completed: [HIR-AUTH-01, HIR-AUTH-02, HIR-AUTH-03, HIR-AUTH-04]

# Metrics
duration: 15min
completed: 2026-03-17
---

# Phase 21 Plan 02: Hiring Handler Authorization Summary

**Capability gates on 6 hiring admin_post handlers, role allowlist blocking administrator escalation in conversion, and password reset link replacing plaintext credentials in welcome emails**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-03-17T14:00:00Z
- **Completed:** 2026-03-17T14:15:00Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments

- All 6 hiring admin_post handlers (3 candidate + 3 trainee) now check `current_user_can('sfs_hr.manage')` before nonce verification — unprivileged users are rejected immediately
- Role allowlist (`get_allowed_hire_roles()` + `sanitize_hire_role()`) prevents administrator/editor role escalation when converting candidates and trainees to employees
- Welcome email rewritten to send a password reset link via `get_password_reset_key()` — no plaintext password is transmitted
- Inline trainee `create_account` email block in class-admin-pages.php replaced with centralized `HiringModule::send_welcome_email()` call

## Task Commits

Each task was committed atomically:

1. **Task 1: Add sfs_hr.manage capability gate to all 6 hiring handlers** - `fc35b15` (fix)
2. **Task 2: Add role allowlist and replace plaintext password with reset link** - `cf0ad32` (fix)

## Files Created/Modified

- `includes/Modules/Hiring/Admin/class-admin-pages.php` - Added `current_user_can('sfs_hr.manage')` as first check in all 6 handlers; replaced inline trainee welcome email with `HiringModule::send_welcome_email()` call
- `includes/Modules/Hiring/HiringModule.php` - Added `get_allowed_hire_roles()` and `sanitize_hire_role()` helpers; updated both conversion methods to use allowlist; rewrote `send_welcome_email()` with password reset link; changed visibility to `public static`

## Decisions Made

- Capability check placed before nonce in all 6 handlers — consistent with Phase 21 Plan 01 leave handler pattern
- Role allowlist: `sfs_hr_employee`, `sfs_hr_manager`, `sfs_hr_trainee`, `subscriber` — anything else (administrator, editor, etc.) falls back to `sfs_hr_employee`
- `send_welcome_email()` made `public static` so the trainee `create_account` path in class-admin-pages.php can reuse it without duplication
- Password parameter kept in signature for backward compatibility — callers need no changes, but value is never used in email body
- Silent skip if `get_password_reset_key()` returns `WP_Error` — avoids sending a broken or partial email

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 21 authorization work complete (both plans 01 and 02 done)
- All hiring handlers now require `sfs_hr.manage` capability
- Role escalation during hiring conversion is blocked
- Plaintext passwords are no longer sent via email in any hiring pathway
- Ready to proceed to next v1.2 phase

---
*Phase: 21-leave-hiring-handler-authorization*
*Completed: 2026-03-17*
