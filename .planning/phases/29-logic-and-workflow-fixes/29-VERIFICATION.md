---
phase: 29-logic-and-workflow-fixes
verified: 2026-03-18T05:00:00Z
status: passed
score: 9/9 must-haves verified
re_verification: false
---

# Phase 29: Logic and Workflow Fixes Verification Report

**Phase Goal:** Fix logic bugs, add state machine transition guards, and protect against TOCTOU race conditions
**Verified:** 2026-03-18T05:00:00Z
**Status:** PASSED
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| #  | Truth                                                                              | Status     | Evidence                                                                                                    |
|----|------------------------------------------------------------------------------------|------------|-------------------------------------------------------------------------------------------------------------|
| 1  | Payroll working-day calculation excludes both Friday and Saturday                  | VERIFIED   | `PayrollModule.php` line 672: `(int) $date->format( 'N' ) !== 5 && (int) $date->format( 'N' ) !== 6`; docblock at line 659 updated |
| 2  | A single early-leave event triggers exactly one notification                       | VERIFIED   | `Session_Service.php` line 658: `$was_early = in_array( 'left_early', $existing_flags, true )`; guard at line 705: `if ( $is_early && ! $was_early && $minutes_early > 0 )` |
| 3  | Dynamic capabilities resolve correctly inside AJAX requests                        | VERIFIED   | `Capabilities.php` lines 90–97: `static $request_id = null`; cache reset via `$_SERVER['REQUEST_TIME_FLOAT']` on each new HTTP request |
| 4  | A leave request cannot transition to an invalid state                              | VERIFIED   | `LeaveModule.php` line 20: `private const ALLOWED_TRANSITIONS` with 11 entries; line 4951: `is_valid_transition()` helper; guards in 6 handlers (approve, reject, cancel, cancel_approved, cancellation_approve, cancellation_reject) |
| 5  | A settlement cannot transition to an invalid state                                 | VERIFIED   | `class-settlement-service.php` line 16: `private const ALLOWED_TRANSITIONS`; line 186: guard in `update_status()` returns `false` before `$wpdb->update` on invalid transition |
| 6  | A performance review cannot transition to an invalid state                         | VERIFIED   | `Reviews_Service.php` line 22: `private const ALLOWED_TRANSITIONS`; line 34: `validate_transition()` helper; called at lines 262 (`acknowledge_review`) and 298 (`submit_review`) |
| 7  | Two simultaneous overlapping leave requests cannot both succeed                    | VERIFIED   | `LeaveModule.php` line 4595 and 6188: `START TRANSACTION`; `LeaveCalculationService.php` line 109: `has_overlap_locked()` with `FOR UPDATE`; called at lines 4597 and 6191 in LeaveModule; multiple ROLLBACK paths; COMMIT at lines 4781 and 6294 |
| 8  | Two simultaneous loan requests in the same fiscal year cannot both succeed         | VERIFIED   | `class-my-profile-loans.php` line 654: `START TRANSACTION`; `FOR UPDATE` at lines 680 and 700; ROLLBACK on all error paths; COMMIT at line 738 |
| 9  | Reference number generation cannot produce duplicates under concurrency            | VERIFIED   | `Helpers.php` lines 850–851: `GET_LOCK(%s, 5)` scoped per `sfs_hr_ref_{prefix}_{year}`; line 879: `RELEASE_LOCK`; fallback warning logged on lock timeout |

**Score:** 9/9 truths verified

---

### Required Artifacts

| Artifact                                                                   | Provides                                             | Status     | Details                                                                        |
|----------------------------------------------------------------------------|------------------------------------------------------|------------|--------------------------------------------------------------------------------|
| `includes/Modules/Payroll/PayrollModule.php`                               | Saudi weekend fix in `count_working_days`            | VERIFIED   | Contains `format( 'N' ) !== 5 && ... !== 6`; docblock updated                |
| `includes/Modules/Attendance/Services/Session_Service.php`                 | Early leave notification dedup via `$was_early` flag | VERIFIED   | Contains `$was_early` at line 658; guard at line 705                          |
| `includes/Core/Capabilities.php`                                           | AJAX-safe capability cache keying                    | VERIFIED   | Contains `REQUEST_TIME_FLOAT` cache-reset block at lines 90–97                |
| `includes/Modules/Leave/LeaveModule.php`                                   | State transition guards + transaction-wrapped creation | VERIFIED | Contains `ALLOWED_TRANSITIONS` (line 20), `is_valid_transition` (line 4951), `START TRANSACTION` (lines 4595, 6188), `COMMIT` (lines 4781, 6294) |
| `includes/Modules/Settlement/Services/class-settlement-service.php`        | Settlement state transition guards                   | VERIFIED   | Contains `ALLOWED_TRANSITIONS` (line 16), guard in `update_status` (line 186) |
| `includes/Modules/Performance/Services/Reviews_Service.php`                | Performance review state transition guards           | VERIFIED   | Contains `ALLOWED_TRANSITIONS` (line 22), `validate_transition` (line 34), used at lines 262 and 298 |
| `includes/Modules/Leave/Services/LeaveCalculationService.php`              | Row-locking overlap check with `FOR UPDATE`          | VERIFIED   | `has_overlap_locked()` at line 109; `FOR UPDATE` at line 116                 |
| `includes/Modules/Loans/Frontend/class-my-profile-loans.php`               | Transaction-wrapped fiscal year check + insert       | VERIFIED   | `START TRANSACTION` (line 654), `FOR UPDATE` (lines 680, 700), `COMMIT` (line 738) |
| `includes/Core/Helpers.php`                                                 | Atomic reference number generation                   | VERIFIED   | `GET_LOCK` (line 851), `RELEASE_LOCK` (line 879), lock name `sfs_hr_ref_*`   |

---

### Key Link Verification

| From                                        | To                                              | Via                                              | Status   | Details                                                              |
|---------------------------------------------|-------------------------------------------------|--------------------------------------------------|----------|----------------------------------------------------------------------|
| `PayrollModule.php`                         | `count_working_days`                            | Saturday skip added alongside Friday skip        | WIRED    | Line 672 checks both `!== 5` and `!== 6` in single `if` condition  |
| `Session_Service.php`                       | `Early_Leave_Service::maybe_create_early_leave_request` | `$was_early` flag guard prevents repeat call | WIRED    | `in_array('left_early', $existing_flags)` at line 658; guard at 705 |
| `LeaveModule.php`                           | `count_working_days`                            | Saturday skip in ALLOWED_TRANSITIONS map         | WIRED    | `ALLOWED_TRANSITIONS` at line 20; `is_valid_transition` at line 4951, called from 6 handler locations |
| `LeaveModule.php`                           | `LeaveCalculationService::has_overlap_locked`   | Called inside transaction — gap lock prevents concurrent inserts | WIRED | `START TRANSACTION` at lines 4595/6188; `has_overlap_locked` called at lines 4597/6191 |
| `class-settlement-service.php`              | `wpdb->update`                                  | Transition guard before status update            | WIRED    | Guard at line 186 returns false before `$wpdb->update` at line 200  |
| `class-my-profile-loans.php`                | `wpdb->insert`                                  | Fiscal year check and insert in same transaction | WIRED    | `START TRANSACTION` at 654; `FOR UPDATE` at 680/700; `COMMIT` at 738 |

---

### Requirements Coverage

| Requirement | Source Plan | Description                                                                                   | Status    | Evidence                                                                                                |
|-------------|-------------|-----------------------------------------------------------------------------------------------|-----------|---------------------------------------------------------------------------------------------------------|
| LOGIC-01    | 29-03       | Fix TOCTOU races with DB transactions or row-level locks (Leave overlap, Loans fiscal year, ref numbers) | SATISFIED | `START TRANSACTION`/`FOR UPDATE`/`COMMIT`/`ROLLBACK` in LeaveModule (frontend + admin paths) and Loans; `GET_LOCK` in Helpers |
| LOGIC-02    | 29-02       | Add state machine guards preventing invalid status transitions in Leave, Settlement, Performance | SATISFIED | `ALLOWED_TRANSITIONS` constant and guard helpers in all 3 modules; 6 Leave handlers protected |
| LOGIC-03    | 29-01       | Fix Saudi weekend bug — exclude both Friday AND Saturday in Payroll working-day calculation   | SATISFIED | `PayrollModule::count_working_days` line 672 skips `N=5` and `N=6`; docblock updated                  |
| LOGIC-04    | 29-01       | Fix duplicate early-leave notification suppression in Attendance deferred handlers            | SATISFIED | `$was_early` flag guard in `Session_Service::recalc_session_for` at lines 658/705                      |
| LOGIC-05    | 29-01       | Fix dynamic capability caching — static cache must survive AJAX requests                     | SATISFIED | `REQUEST_TIME_FLOAT`-scoped cache reset in `Capabilities::dynamic_caps` at lines 90–97                |

No orphaned requirements found. All 5 LOGIC requirements are claimed by a plan and implemented.

---

### Anti-Patterns Found

None. No TODO/FIXME/PLACEHOLDER comments, no empty implementations, and no stub returns found in any of the 7 modified files.

---

### Human Verification Required

#### 1. Payroll Pro-Rating Regression

**Test:** Create a payroll run for an employee who joined mid-month (e.g., joined Wednesday of week 2). Verify working days count matches expected Sun-Thu calendar for that partial month, excluding both Fridays and Saturdays.
**Expected:** Count matches manual calendar count of Sun-Thu days only.
**Why human:** Requires an active WP environment with test payroll data; cannot verify the integration path (PayrollModule -> count_working_days -> salary proration formula) programmatically.

#### 2. Leave State Machine — Concurrent Requests

**Test:** Simulate two simultaneous leave requests for the same employee and overlapping dates (e.g., using two browser tabs submitting within 100ms of each other).
**Expected:** Exactly one succeeds; the second receives an overlap error. No duplicate rows in `sfs_hr_leave_requests`.
**Why human:** Race condition behavior requires actual concurrent HTTP requests to the WP environment; grep cannot confirm timing semantics.

#### 3. Settlement Transition Blocking

**Test:** From WordPress admin, attempt to transition a `paid` settlement back to `pending` via a direct URL manipulation or API call.
**Expected:** The request is silently rejected (returns `false`), the DB record stays `paid`, and an error is logged.
**Why human:** Requires a live environment to confirm the guard fires correctly through the full admin handler chain.

---

### Gaps Summary

No gaps. All 9 observable truths are verified against the codebase. All 5 LOGIC requirements are satisfied. All 9 required artifacts exist, are substantive, and are wired into their respective call sites.

The 3 items in Human Verification Required are quality-confirmation tests, not gaps — the implementation evidence is complete.

---

_Verified: 2026-03-18T05:00:00Z_
_Verifier: Claude (gsd-verifier)_
