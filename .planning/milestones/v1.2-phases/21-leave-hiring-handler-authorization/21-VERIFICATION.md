---
phase: 21-leave-hiring-handler-authorization
verified: 2026-03-17T14:30:00Z
status: passed
score: 7/7 must-haves verified
re_verification: false
---

# Phase 21: Leave + Hiring Handler Authorization Verification Report

**Phase Goal:** Leave approval and cancellation handlers enforce capability and prevent self-approval; hiring conversion methods are capability-gated, use a role allowlist, and do not send plaintext passwords
**Verified:** 2026-03-17
**Status:** PASSED
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths (from ROADMAP Success Criteria)

| #  | Truth                                                                                                                          | Status     | Evidence                                                                                       |
|----|--------------------------------------------------------------------------------------------------------------------------------|------------|-----------------------------------------------------------------------------------------------|
| 1  | A user without `sfs_hr.leave.review` cannot trigger `handle_approve()` or `handle_cancel()` — both return error before processing | VERIFIED  | `handle_approve()` line 1057: cap check is first line. `handle_cancel()` line 1765: cap check is first line. Both call `wp_die(403)` before reading POST data or nonce. |
| 2  | An HR user cannot approve their own leave request at either the first or second approval stage in `handle_cancellation_approve()` | VERIFIED  | Lines 2020-2028: two sequential guards — (1) `$empInfo['user_id'] === $current_uid` prevents the leave requester from approving at any level; (2) `$cancel['created_by'] === $current_uid` prevents the cancellation initiator from approving at any level. Both guards precede the level-1/level-2 branch. |
| 3  | `is_hr_user()` denies access to users whose only HR-related role is `sfs_hr_manager` with no explicit HR responsibility assignment | VERIFIED  | `is_hr_user()` lines 2587-2593: checks only `sfs_hr_leave_hr_approvers` option array, `sfs_hr.leave.manage` capability, and `administrator`. The previous `sfs_hr_manager` role `in_array` block is absent. |
| 4  | Leave approval nonces are scoped per-request and cannot be replayed across different pending requests                          | VERIFIED  | All 7 occurrences of the approve nonce use the scoped form `sfs_hr_leave_approve_{id}` (lines 559, 1064, 6886, 7063, 7086, 7118, 7149). No bare `sfs_hr_leave_approve'` nonce string remains outside the action hook registration. |
| 5  | A user without `sfs_hr.manage` cannot trigger any of the 6 hiring conversion handlers — capability is verified before nonce   | VERIFIED  | All 6 handlers in `class-admin-pages.php` open with `current_user_can('sfs_hr.manage')` before `wp_verify_nonce()`: `handle_add_candidate` (1267), `handle_update_candidate` (1305), `handle_candidate_action` (1338), `handle_add_trainee` (1522), `handle_update_trainee` (1598), `handle_trainee_action` (1631). |
| 6  | The hiring role assignment pathway uses an allowlist; assigning the `administrator` role via conversion is blocked              | VERIFIED  | `get_allowed_hire_roles()` (line 682) returns `['sfs_hr_employee', 'sfs_hr_manager', 'sfs_hr_trainee', 'subscriber']`. `sanitize_hire_role()` (line 695) falls back to `sfs_hr_employee` for any role not in that list. Both `convert_trainee_to_employee()` (line 409) and `convert_candidate_to_employee()` (line 580) call `sanitize_hire_role()` instead of using the raw `$dept_role` value. |
| 7  | New employee welcome emails contain a password reset link, not a plaintext password                                            | VERIFIED  | `send_welcome_email()` (line 704) calls `get_password_reset_key($user)` (line 711) and constructs a `wp-login.php?action=rp` URL. No `Password:` string appears in the email body. The `$password` parameter is accepted for backward compatibility but never used in the message. The trainee `create_account` path in `class-admin-pages.php` (line 1726) delegates to `HiringModule::send_welcome_email()` — the inline email block with plaintext credentials is gone. |

**Score:** 7/7 truths verified

---

## Required Artifacts

| Artifact                                                          | Provides                                                         | Status     | Details                                                                                                       |
|-------------------------------------------------------------------|------------------------------------------------------------------|------------|---------------------------------------------------------------------------------------------------------------|
| `includes/Modules/Leave/LeaveModule.php`                          | Capability-gated leave handlers with scoped nonces and self-approval prevention | VERIFIED | Contains `current_user_can('sfs_hr.leave.review')`, 7 scoped nonce sites, `is_hr_user()` fixed, both self-approval guards present. |
| `includes/Modules/Hiring/Admin/class-admin-pages.php`             | Capability-gated hiring handlers                                 | VERIFIED   | 6 `current_user_can('sfs_hr.manage')` checks as first executable line in each of the 6 handlers.              |
| `includes/Modules/Hiring/HiringModule.php`                        | Role allowlist and password-reset welcome email                  | VERIFIED   | `get_allowed_hire_roles()`, `sanitize_hire_role()`, and rewritten `send_welcome_email()` with `get_password_reset_key()` all present. |

---

## Key Link Verification

| From                                     | To                                           | Via                                              | Status     | Details                                                                                                                |
|------------------------------------------|----------------------------------------------|--------------------------------------------------|------------|------------------------------------------------------------------------------------------------------------------------|
| `handle_approve()`                       | `current_user_can('sfs_hr.leave.review')`    | capability check before nonce                    | WIRED      | Line 1057 — cap check is line 1 of method; nonce at line 1064 after `$id` is read.                                    |
| `handle_cancel()`                        | `current_user_can('sfs_hr.leave.review')`    | capability check for non-requester cancellation  | WIRED      | Line 1765 — dual-cap check (`leave.review` OR `sfs_hr.view`) is line 1 of method; nonce at line 1770.                 |
| `handle_cancellation_approve()`          | self-approval block                          | both `empInfo['user_id']` and `cancel['created_by']` compared against `$current_uid` | WIRED | Lines 2020-2028 — two guards before level-1/level-2 branch; uses `created_by` (confirmed correct column per Migrations.php). |
| `is_hr_user()`                           | `sfs_hr_leave_hr_approvers` option           | checks explicit HR assignment, not bare sfs_hr_manager role | WIRED | Lines 2587-2593 — only `sfs_hr_leave_hr_approvers`, `sfs_hr.leave.manage`, and `administrator`; sfs_hr_manager role block removed. |
| `handle_candidate_action()` (and 5 peers) | `current_user_can('sfs_hr.manage')`        | capability check before nonce                    | WIRED      | All 6 handlers: cap check is first executable line; nonce follows.                                                     |
| `convert_candidate_to_employee()`        | role allowlist (`sanitize_hire_role()`)      | allowlist filter before `set_role()`             | WIRED      | Line 580 — `sanitize_hire_role($dept_role)` called; result assigned to `$wp_role` before `$user->set_role($wp_role)`. |
| `convert_trainee_to_employee()`          | role allowlist (`sanitize_hire_role()`)      | allowlist filter before `set_role()`             | WIRED      | Line 409 — same pattern as candidate conversion.                                                                       |
| `send_welcome_email()`                   | `get_password_reset_key()`                   | password reset link instead of plaintext         | WIRED      | Line 711 — `get_password_reset_key($user)` called; result used in `wp-login.php?action=rp` URL in message body. No `Password:` string in message. |

---

## Requirements Coverage

| Requirement  | Source Plan | Description                                                                  | Status    | Evidence                                                                                          |
|--------------|-------------|------------------------------------------------------------------------------|-----------|---------------------------------------------------------------------------------------------------|
| LV-AUTH-01   | 21-01       | `handle_approve()` must check capability before processing approval          | SATISFIED | Line 1057: `current_user_can('sfs_hr.leave.review')` is the first statement.                     |
| LV-AUTH-02   | 21-01       | `handle_cancel()` must check capability before processing cancellation       | SATISFIED | Line 1765: dual capability check (`leave.review` OR `sfs_hr.view`) is the first statement.        |
| LV-AUTH-03   | 21-01       | `is_hr_user()` must not grant HR access to all sfs_hr_manager role users     | SATISFIED | Lines 2587-2593: function body checks approver list, `leave.manage` cap, and `administrator` only. |
| LV-AUTH-04   | 21-01       | Approval nonce must be scoped per-request, not shared across all requests    | SATISFIED | All 7 approve nonce sites use `sfs_hr_leave_approve_{id}` — no bare nonce remains.                |
| LV-AUTH-05   | 21-01       | `handle_cancellation_approve()` must prevent HR self-approval at both stages | SATISFIED | Lines 2020-2028: leave-requester guard + cancellation-initiator (`created_by`) guard before level branching. |
| HIR-AUTH-01  | 21-02       | Conversion methods must require `sfs_hr.manage` capability                  | SATISFIED | All 6 handlers gate on `current_user_can('sfs_hr.manage')` before nonce; conversion methods are only reachable after nonce passes. |
| HIR-AUTH-02  | 21-02       | All 6 unguarded handlers must verify capability before nonce                 | SATISFIED | Confirmed for each handler: 1267, 1305, 1338, 1522, 1598, 1631.                                  |
| HIR-AUTH-03  | 21-02       | WP role assignment must use allowlist to prevent administrator escalation    | SATISFIED | `sanitize_hire_role()` in both conversion methods; allowlist excludes `administrator` and `editor`. |
| HIR-AUTH-04  | 21-02       | Welcome email must not send plaintext password                               | SATISFIED | `send_welcome_email()` uses `get_password_reset_key()`; no `Password:` in message body; trainee path delegates to this method. |

No orphaned requirements — all 9 IDs claimed in plans are accounted for, and REQUIREMENTS.md traceability table maps all 9 to Phase 21.

---

## Anti-Patterns Found

| File                                                  | Line | Pattern                                                     | Severity | Impact                                                                                                  |
|-------------------------------------------------------|------|-------------------------------------------------------------|----------|---------------------------------------------------------------------------------------------------------|
| `includes/Modules/Leave/LeaveModule.php`              | 2585 | Docblock still says "HR approver, sfs_hr_manager role, or leave.manage capability" — `sfs_hr_manager role` is no longer accurate after LV-AUTH-03 fix | Info | Comment-only mismatch; does not affect runtime behavior. Docblock should be updated for maintainability. |

No stub implementations, no empty handlers, no `TODO`/`FIXME` blockers in modified files.

---

## Human Verification Required

### 1. Manager-only user blocked from approving leave

**Test:** Log in as a WP user who holds only the `sfs_hr_manager` role (not in the `sfs_hr_leave_hr_approvers` list, no `sfs_hr.leave.manage` cap). Submit a POST to `admin-post.php?action=sfs_hr_leave_approve` with a valid leave ID and valid nonce.
**Expected:** `wp_die(403)` — request rejected before any DB write.
**Why human:** Dynamic capability `sfs_hr.leave.review` is granted at runtime via `user_has_cap` filter; automated grep cannot confirm the filter is wired correctly for this user class.

### 2. HR user self-approval blocked at both levels

**Test:** As an HR user who also has a pending leave request, navigate to the cancellation approve screen and attempt to approve your own cancellation (level 1 and level 2).
**Expected:** Both attempts redirect with the "cannot approve" error notice; the cancellation status stays `pending`.
**Why human:** The self-approval guards call `Helpers::redirect_with_notice()` which exits — confirming the redirect actually fires (vs. PHP falling through) requires a live request.

### 3. Welcome email contains reset link, not password

**Test:** Trigger a candidate-to-employee or trainee-to-employee conversion on a staging environment. Check the email received by the new employee.
**Expected:** Email body contains a `wp-login.php?action=rp&key=...&login=...` URL. No line starting with "Password:" appears.
**Why human:** `get_password_reset_key()` generates a real DB token only in a live WP environment; the email delivery path cannot be exercised programmatically in this review.

---

## Gaps Summary

No gaps. All 7 observable truths are verified, all 9 requirements are satisfied, all key links are wired, and no blocker anti-patterns were found. The single info-level finding (stale docblock on `is_hr_user()`) does not affect runtime behavior and does not block phase sign-off.

---

_Verified: 2026-03-17_
_Verifier: Claude (gsd-verifier)_
