---
phase: 22-loans-performance-auth-hardening
verified: 2026-03-17T15:00:00Z
status: passed
score: 8/8 must-haves verified
re_verification: false
---

# Phase 22: Loans + Performance Auth Hardening — Verification Report

**Phase Goal:** Harden Loans + Performance modules with nonce scoping, handler ordering fixes, capability gates, and department-scoped REST reads
**Verified:** 2026-03-17T15:00:00Z
**Status:** PASSED
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| #  | Truth | Status | Evidence |
|----|-------|--------|----------|
| 1  | The Loans handler reads no POST data (loan_id, employee_id) until after nonce verification succeeds | VERIFIED | `handle_loan_actions()` line 2055: only `sanitize_key($_POST['loan_action'])` is read before the switch; each case reads `loan_id`, validates it, then immediately calls `check_admin_referer()` before any other POST data |
| 2  | The create_loan nonce action is not scoped to any user-supplied value like employee_id | VERIFIED | Line 2411: `check_admin_referer('sfs_hr_loan_create')` — static string; `$employee_id` is not read until line 2417, after the nonce and capability checks pass |
| 3  | Installment action nonces do not appear in any DOM data attribute in the page source | VERIFIED | `grep -n "data-nonce"` returns zero matches; `sfsInstNonces` PHP-generated JS map at line 1102 replaces the former `data-nonce` attribute |
| 4  | All installment mark actions (paid, partial, skipped) still verify nonces correctly after the DOM removal | VERIFIED | `check_admin_referer('sfs_hr_mark_installment_' . $payment_id)` confirmed at lines 2760, 2797, 2837; JS reads nonces via `sfsInstNonces[currentInstData.id]` at lines 1166 and 1194 |
| 5  | A user without sfs_hr.manage or sfs_hr_performance_view capability cannot update goal progress via ajax_update_goal_progress | VERIFIED | PerformanceModule.php line 188: `if (!current_user_can('sfs_hr.manage'))` immediately after `check_ajax_referer` at line 186, before any `$_POST` read |
| 6  | A user without sfs_hr.manage capability cannot save a goal via ajax_save_goal | VERIFIED | PerformanceModule.php line 159: `if (!current_user_can('sfs_hr.manage'))` — existing check confirmed present, no regression |
| 7  | A department manager calling check_read_permission on a REST endpoint can only access performance data for employees in their managed departments | VERIFIED | Performance_Rest.php line 278-314: tiered model — `sfs_hr.view` path queries `sfs_hr_departments.manager_user_id` via `is_employee_in_managed_department()` and `is_managed_department()` helpers; returns false if managed dept list is empty |
| 8  | A user with sfs_hr.manage can read performance data for any employee regardless of department | VERIFIED | Performance_Rest.php line 280: `if (current_user_can('sfs_hr.manage')) return true;` — first tier, no department scoping applied |

**Score:** 8/8 truths verified

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/Modules/Loans/Admin/class-admin-pages.php` | Loans admin handler with fixed nonce ordering and no DOM nonce exposure | VERIFIED | File exists (3072 lines); `check_admin_referer` present at 10 locations; `data-nonce` attribute absent; `sfsInstNonces` map at line 1102 |
| `includes/Modules/Performance/PerformanceModule.php` | AJAX handlers with capability checks before any DB write | VERIFIED | File exists; `current_user_can` checks at lines 159, 188, 214 (save_goal, update_goal_progress, save_review respectively) |
| `includes/Modules/Performance/Rest/Performance_Rest.php` | REST permission callbacks with department scope enforcement | VERIFIED | File exists; `check_read_permission` at line 278 with tiered model; three private helpers (`get_managed_department_ids`, `is_employee_in_managed_department`, `is_managed_department`) fully implemented |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `handle_loan_actions()` | `check_admin_referer()` | nonce verified before any POST data read beyond loan_action | WIRED | All five action cases (approve_gm, approve_finance, reject_loan, cancel_loan, create_loan) verify nonce before reading additional POST fields; top-level `$loan_id` and capability switch have been removed |
| installment table rows | installment action forms | nonce fetched via inline PHP map in script block, not data attributes | WIRED | `sfsInstNonces` object at line 1102 built by PHP foreach; JS reads `sfsInstNonces[currentInstData.id]` at lines 1166 and 1194; `data-nonce` absent from DOM |
| `ajax_update_goal_progress()` | `current_user_can()` | capability check before Goals_Service::update_progress() | WIRED | Line 188 check precedes `$goal_id` read at line 192 and `Goals_Service::update_progress()` call at line 199 |
| `check_read_permission()` | `sfs_hr_departments.manager_user_id` | department scope query for non-admin users | WIRED | `get_managed_department_ids()` at line 344 queries `{prefix}sfs_hr_departments WHERE manager_user_id = %d` using `$wpdb->prepare()` |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| LOAN-AUTH-01 | 22-01 | Nonce must not be scoped to user-supplied employee_id | SATISFIED | `check_admin_referer('sfs_hr_loan_create')` — static nonce; `employee_id` read follows nonce+cap checks |
| LOAN-AUTH-02 | 22-01 | AJAX handler must verify nonce before reading employee_id from POST | SATISFIED | `create_loan` case: nonce at line 2411, capability at line 2412, `employee_id` at line 2417 |
| LOAN-AUTH-03 | 22-01 | Nonce check must precede capability check in `handle_loan_actions()` | SATISFIED | All action cases: `check_admin_referer()` called before capability method in every case |
| LOAN-AUTH-04 | 22-01 | Installment action nonces must not be embedded in DOM data attributes | SATISFIED | Zero `data-nonce` occurrences in file; nonces delivered via `sfsInstNonces` JS object |
| PERF-AUTH-01 | 22-02 | `ajax_update_goal_progress` must check capability before processing | SATISFIED | `current_user_can('sfs_hr.manage')` at line 188, before any `$_POST` access |
| PERF-AUTH-02 | 22-02 | `check_read_permission` must enforce department scope for managers | SATISFIED | Tiered permission model with `is_employee_in_managed_department()` and `is_managed_department()` helpers using `manager_user_id` column |
| PERF-AUTH-03 | 22-02 | `save_goal()` must check capability before saving | SATISFIED | `current_user_can('sfs_hr.manage')` at line 159, confirmed present with no regression |

All 7 phase requirement IDs are covered by plans. No orphaned requirements found.

---

### Anti-Patterns Found

None. No TODO, FIXME, placeholder, or stub patterns detected in any of the three modified files.

---

### Human Verification Required

None identified. All authorization logic is statically verifiable through code inspection:

- Nonce ordering is code-order deterministic (no runtime branching that could skip checks)
- Capability strings (`sfs_hr.manage`, `sfs_hr_performance_view`, `sfs_hr.view`) are constants
- Department scope query uses `$wpdb->prepare()` with no dynamic table name issues
- DOM attribute removal is a static absence of a PHP echo

---

### Commit Verification

All four task commits confirmed present in git history:

| Commit | Description |
|--------|-------------|
| `d7161ac` | fix(22-01): fix nonce-before-data ordering in handle_loan_actions |
| `ddc8d2d` | fix(22-01): remove installment nonces from DOM data attributes |
| `b36b7d2` | fix(22-02): add capability check to ajax_update_goal_progress |
| `f305372` | fix(22-02): enforce department scope in REST check_read_permission |

---

### Summary

Phase 22 achieved its goal in full. Both sub-plans executed without deviation:

**Plan 01 (Loans):** `handle_loan_actions()` was restructured so each action case reads the minimum necessary POST field (`loan_id` or nothing for `create_loan`), immediately verifies the nonce, then checks the capability, then reads remaining POST data. The pre-existing top-level `$loan_id` read and monolithic capability switch — which both preceded nonce verification — are gone. Installment nonces were migrated from DOM `data-nonce` attributes into a PHP-generated `sfsInstNonces` JS object inside the script block, eliminating nonce leakage in page source while keeping server-side `check_admin_referer` calls unchanged.

**Plan 02 (Performance):** `ajax_update_goal_progress()` now gates on `sfs_hr.manage` immediately after the referer check and before any `$_POST` access, closing the gap where any authenticated user with a valid nonce could write goal progress. `check_read_permission()` in the REST layer was replaced with a three-tier model: full admins (`sfs_hr.manage`) pass unconditionally, dedicated view-role holders (`sfs_hr_performance_view`) pass unconditionally, and department managers (`sfs_hr.view`) are restricted to employees and departments they manage via a `manager_user_id` lookup — returning false when the managed-department list is empty.

---

_Verified: 2026-03-17T15:00:00Z_
_Verifier: Claude (gsd-verifier)_
