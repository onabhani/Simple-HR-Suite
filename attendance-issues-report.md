# Attendance Module — Issues Report & Fixing Plan

> Generated: 2026-03-05
> Branch: `claude/fix-calendar-translations-b43kf`

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Issues Registry](#issues-registry)
   - [Critical & High Severity](#critical--high-severity-issues)
   - [Medium Severity](#medium-severity-issues)
   - [Low Severity](#low-severity-issues)
3. [Detailed Findings](#detailed-findings)
   - [A — Early Leave Flow & Bugs](#a--early-leave-flow--bugs)
   - [B — Manual Punch Corrections](#b--manual-punch-corrections)
   - [C — Session Recalculation Edge Cases](#c--session-recalculation-edge-cases)
   - [D — Retroactive Changes](#d--retroactive-changes)
   - [E — Midnight Crossing & Overnight Shifts](#e--midnight-crossing--overnight-shifts)
   - [F — Multi-Day Absences](#f--multi-day-absences)
   - [G — Geofencing & Location Validation](#g--geofencing--location-validation)
   - [H — Attendance Reports & Calculations](#h--attendance-reports--calculations)
   - [I — Data Integrity & Foreign Keys](#i--data-integrity--foreign-keys)
   - [J — Concurrent Access & Race Conditions](#j--concurrent-access--race-conditions)
   - [K — API Error Responses](#k--api-error-responses)
   - [L — Additional Findings](#l--additional-findings)
4. [Fixing Plan](#fixing-plan)
   - [Phase 1 — Critical Fixes](#phase-1--critical-fixes)
   - [Phase 2 — High-Priority Fixes](#phase-2--high-priority-fixes)
   - [Phase 3 — Medium-Priority Fixes](#phase-3--medium-priority-fixes)
   - [Phase 4 — Low-Priority & Hardening](#phase-4--low-priority--hardening)
5. [Testing Recommendations](#testing-recommendations)

---

## Executive Summary

A comprehensive code audit of the Attendance module uncovered **32 issues** across all severity levels. The most critical problems are: complete lack of foreign key constraints (risking orphaned data), missing admin punch correction UI, silent session calculation failures, and timezone bugs in the early leave auto-reject cron. The module has solid concurrency handling (named locks on punches) but significant gaps in data integrity, error handling, and edge-case coverage for overnight shifts.

---

## Issues Registry

### Critical & High Severity Issues

| # | Severity | Category | Bug | Root Cause | Impact |
|---|----------|----------|-----|-----------|--------|
| 1 | **CRITICAL** | Data Integrity | No foreign key constraints on any attendance table | Schema created without FK references between punches, sessions, flags, audit, and employees tables (lines 4114–4380) | Deleting an employee leaves orphaned punches, sessions, flags, and audit records; reports return stale/ghost data; storage bloat grows unbounded; no cascade cleanup possible |
| 2 | **CRITICAL** | Manual Corrections | No admin punch creation/edit UI or API | `source` ENUM includes `'manager_adjust'` but no REST endpoint or admin form exists to use it | Admins cannot correct wrong clock-in/out times, missed punches, or kiosk errors; payroll inaccuracies persist until employee re-punches or sessions are manually rebuilt |
| 3 | **CRITICAL** | Session Recalc | No error check on session insert/update | `$wpdb->update()` / `$wpdb->insert()` return value ignored; function returns void (line 4687) | Punch records succeed but session calculation silently fails (e.g., disk full, table lock); employee sees "punch recorded" but payroll never reflects the work — undetected data loss |
| 4 | **HIGH** | Early Leave | Timezone mismatch in auto-reject cron | `$cutoff` uses `gmdate()` (UTC) while `created_at` stored via `current_time('mysql')` (WP timezone) — lines 39–40 of Early_Leave_Auto_Reject.php | Early leave requests auto-rejected hours too early or too late depending on WP timezone offset; employees lose valid pending requests or stale requests linger past intended deadline |
| 5 | **HIGH** | Retroactive Changes | Shift time changes don't recalculate past sessions | No cascade recalc triggered when `shifts.start_time` or `shifts.end_time` is modified | All historical sessions retain stale late/early/absent flags based on old shift times; payroll pays incorrect overtime or deducts incorrectly until admin manually rebuilds each date |
| 6 | **HIGH** | Session Recalc | `break_delay_minutes` not calculated in normal session flow | Only computed during leading-out retroactive close path, not in standard recalc (lines 5001–5034) | Break delay metrics are always 0 for normal sessions; reports undercount break violations; policy enforcement for break compliance is non-functional |

### Medium Severity Issues

| # | Severity | Category | Bug | Root Cause | Impact |
|---|----------|----------|-----|-----------|--------|
| 7 | MEDIUM | Early Leave | Concurrent approval race condition | Two managers can pass permission checks simultaneously; `recalc_session_for()` called by both threads (lines 329–367) | Session flags/minutes may reflect an inconsistent state depending on which recalc finishes last; final session data is non-deterministic |
| 8 | MEDIUM | Early Leave | `affects_salary` forced to 1 on rejection | Hardcoded `$action === 'approve' ? $affects : 1` (line 323) | Rejected early leave always impacts salary; managers cannot reject without penalty, forcing workarounds or incorrect payroll deductions |
| 9 | MEDIUM | Early Leave | Orphaned request when session doesn't exist yet | Session lookup returns NULL during approval; no retry or back-link mechanism (lines 344–352) | Early leave request is approved but never linked to its session; audit trail is broken; reports can't correlate early leaves with attendance records |
| 10 | MEDIUM | Geofencing | No indication of which geofence failed | Both shift and device geofence failures set `valid_geo=0` without distinguishing source (lines 762–789) | Managers reviewing flagged punches can't determine if the employee was outside the office zone or the device zone; investigation and dispute resolution is impossible |
| 11 | MEDIUM | Geofencing | GPS unavailable doesn't block punch | Punch proceeds with `valid_geo=0` when GPS data is null (lines 764–765) | Employees learn they can disable GPS and still punch successfully; geofence enforcement becomes advisory-only; location compliance is unenforceable |
| 12 | MEDIUM | Overtime | Rounding applied before OT calculation | `$net` rounded down first, then `$ot = max(0, $net - $scheduled)` (lines 5058–5061) | Fractional overtime minutes are silently lost after rounding; employees are systematically underpaid for overtime; potential labor law compliance violation |
| 13 | MEDIUM | Overnight Shifts | Punch window tightening misattributes punches | 2-hour buffer before shift start captures previous day's carryover punches (lines 4754–4769) | A punch intended for yesterday's overnight shift gets pulled into today's session; previous day appears incomplete while today shows a phantom early arrival |
| 14 | MEDIUM | Overnight Shifts | Leading OUTs — only last OUT used | `$closingOut = end($leadingOuts)` discards earlier OUT punches (line 4812) | Previous day's session uses only the final OUT timestamp; intermediate OUT punches (device glitches) are lost from the audit trail; actual departure time may be wrong |
| 15 | MEDIUM | Overnight Shifts | Timezone mismatch in overnight close-out | `$closingOut` is UTC while `$shiftEndDt` is local TZ (lines 4847–4856) | `left_early` flag may be incorrectly set or missed for overnight shifts; employee penalized or not based on timezone math error rather than actual behavior |
| 16 | MEDIUM | Policy | Error suppression hides DB failures | `$wpdb->suppress_errors(true)` around policy lookup (lines 230–232) | Corrupted policy table, permission errors, or missing tables produce silent NULL results; debugging is impossible; employees may get no policy applied without any log entry |
| 17 | MEDIUM | Policy | Shift deletion orphans assignments | No cascade from `shifts.id` to `shift_assign.shift_id`; deleted shift causes JOIN to return NULL | Employees assigned to deleted shifts resolve to no shift; marked absent on days they should have had a valid assignment; historical session audit trail is broken |
| 18 | MEDIUM | Schema | Break policy enum mismatch | Code checks for `'free'` (line 5006) but schema ENUM only allows `('auto','punch','none')` (line 4181) | Attempting to save `break_policy='free'` causes MySQL to reject the value; feature referenced in logic is unusable; potential runtime errors on shift save |
| 19 | MEDIUM | Reporting | Status ambiguity with multiple flags | Employee both late AND left early gets single status `'late'`; `left_early` only in flags array (lines 5159, 5886) | Reports show "Late" but hide the early departure; managers see incomplete picture; penalty calculations may miss one of two violations |
| 20 | MEDIUM | Selfie | Selfie upload failure doesn't block punch | `valid_selfie=0` set but punch still succeeds and returns 200 (lines 815–862) | Employee sees success but selfie requirement was not met; manager must retroactively review; identity verification policy is effectively unenforced |
| 21 | MEDIUM | Performance | Session recalc runs inside punch lock | Lock held for entire recalc duration (lines 907–1065) | Under load (50+ employees punching simultaneously), kiosks experience 5+ second delays waiting for lock; peak check-in/out times cause visible queuing |
| 22 | MEDIUM | Selfie Cleanup | No automatic cleanup of orphaned selfie attachments | Selfie media attachments created via `save_selfie_attachment()` are never deleted when the associated punch or session is removed | Orphaned selfie images accumulate in `wp-content/uploads`, consuming disk space indefinitely; no cron or lifecycle hook exists to garbage-collect detached media; over time this degrades server storage and backup performance |

### Low Severity Issues

| # | Severity | Category | Bug | Root Cause | Impact |
|---|----------|----------|-----|-----------|--------|
| 23 | LOW | Reporting | Off-day vs absent confusion for total-hours shifts | Total-hours shift with no punches marked `'absent'`; no way to distinguish unscheduled day (lines 5073–5117) | Reports overcount absences for total-hours employees on genuinely off days; absence rate metrics are inflated |
| 24 | LOW | Reporting | `no_break_taken` shown alongside break deduction | Flag not suppressed when policy auto-deducts break in total-hours mode (lines 5054–5056) | UI shows contradictory information: break was deducted AND no break was taken; confuses employees reviewing their records |
| 25 | LOW | Caching | Policy cache not thread-safe | Static `$cache` array shared across concurrent AJAX requests (lines 23–33) | Concurrent requests may use stale policy data if admin updates policy mid-request; extremely rare but can cause one punch to validate against old rules |
| 26 | LOW | API | Inconsistent error codes across endpoints | Punch returns 503 for lock timeout; early leave returns 500 for DB error; no standard error schema | Frontend clients must handle errors inconsistently per endpoint; error recovery logic is fragile and endpoint-specific |
| 27 | LOW | Geofencing | No coordinate validation on device creation | Latitude/longitude not bounds-checked; only radius has `max(10,...)` (lines 289–291) | Admin typo (e.g., `lat=4071.28`) creates device with impossible coordinates; haversine returns NaN; all punches via that device get `valid_geo=0` |
| 28 | LOW | Data Integrity | Orphaned early leave requests after employee deletion | No FK constraint from `early_leave_requests.employee_id` to `employees.id` | Deleted employees leave behind pending early leave requests; "pending requests" count is inflated; admin sees requests for non-existent employees |
| 29 | LOW | Schema | Total-hours detection uses `start_time === end_time` | Both set to `00:00` means total-hours; accidental match triggers wrong mode (lines 72–77) | A shift accidentally configured with identical start/end times silently switches to total-hours mode; session calculations use wrong logic |
| 30 | LOW | Session | `locked` column defined but never used | Schema has `locked TINYINT(1)` (line 4163) but no code reads or writes it | Sessions can be recalculated unlimited times with potentially different results; no mechanism to freeze finalized payroll sessions |
| 31 | LOW | Offline Sync | Punch time compared with `time()` instead of WP time | Server `time()` vs `current_time('timestamp', true)` mismatch (lines 560–573) | Offline punch age validation may be off by timezone offset; mitigated by the large 24-hour tolerance window |
| 32 | LOW | Break Logic | `auto` break policy with 0 minutes is ambiguous | `$has_mandatory_break` is false when `$shift_break_minutes == 0` even if policy is `'auto'` (line 4975) | Admin sets break policy to "auto" expecting automatic deduction, but nothing happens because duration is 0; silent misconfiguration |

---

## Detailed Findings

### A — Early Leave Flow & Bugs

**How Early Leave Works:**

1. Employee calls `POST /sfs-hr/v1/early-leave/request` (line 66 in `Early_Leave_Rest.php`)
2. Request stored with `status='pending'` (line 145)
3. Manager reviews via `POST /sfs-hr/v1/early-leave/review/{id}` (line 277)
4. On approval: status changes to `'approved'`, session recalculated, early leave flag added
5. Auto-reject after 72 hours via cron (`Early_Leave_Auto_Reject.php`)

**A1. Timezone Discrepancy in Auto-Reject (Issue #4)**

File: `includes/Modules/Attendance/Cron/Early_Leave_Auto_Reject.php`

```php
// Line 39-40
$cutoff = gmdate('Y-m-d H:i:s', time() - (self::EXPIRY_HOURS * 3600)); // UTC
$now = current_time('mysql');                                            // WP TZ
```

The cutoff is calculated in UTC but `created_at` is stored in WordPress timezone. For WP set to UTC+5, requests are auto-rejected 5 hours earlier than intended.

**A2. Concurrent Approval Race Condition (Issue #7)**

File: `includes/Modules/Attendance/Rest/class-early-leave-rest.php` (lines 329–367)

The CAS update at line 329 prevents double-approval, but both threads can invoke `recalc_session_for()` independently. The final session state depends on which recalc finishes last.

**A3. `affects_salary` Override on Reject (Issue #8)**

```php
// Line 323
'affects_salary' => $action === 'approve' ? $affects : 1,
```

Rejection always sets `affects_salary=1`. Managers cannot reject without salary impact.

**A4. Session Linking Race (Issue #9)**

Lines 344–352: If the session doesn't exist at approval time (employee hasn't finished shift), the early leave request is never back-linked. The session is later created by cron but remains orphaned from the request.

---

### B — Manual Punch Corrections

**B1. No Admin Punch UI (Issue #2)**

The `source` ENUM includes `'manager_adjust'` (line 4119) but no REST endpoint (`POST /sfs-hr/v1/attendance/punches/admin-create`) or admin form exists. `render_punches()` (line 5095) is read-only.

**B2. Session Recalc Not Wrapped in Transaction (Issue #3)**

```php
// Line 4686-4687
if ($exists) $wpdb->update($sT, $data, ['id' => $exists]);
else $wpdb->insert($sT, $data);
// No error check — returns void
```

If the session insert/update fails, the punch exists but the session is incomplete. Payroll silently misses the work.

---

### C — Session Recalculation Edge Cases

**C1. Incomplete Session: Unmatched Clock-In**

An employee who forgets to clock out gets `status='incomplete'`. The next day's leading OUT punch retroactively closes the previous session. For overnight shifts, timezone comparison between UTC punch time and local shift end time can produce incorrect `left_early` flags.

**C2. Break Calculation Gap (Issue #6)**

`break_delay_minutes` is only calculated during the leading-out retroactive close path, not in the standard `recalc_session_for()` flow. Normal sessions always show `break_delay_minutes = 0`.

**C3. Status Rollup Ambiguity (Issue #19)**

An employee who is both late AND leaves early gets `$status = 'late'` (line 5159). The `left_early` flag exists in the flags array but is not reflected in the primary status field.

---

### D — Retroactive Changes

**D1. Shift Time Change (Issue #5)**

No cascade update when `shifts.start_time` or `shifts.end_time` changes. Past sessions retain stale flags. No "recalculate all sessions for this shift" function exists — admin must manually call `rebuild_sessions_for_date_static()` per date.

**D2. Policy Deletion**

`Policy_Service::delete_policy()` (lines 536–546) deletes the policy without cascade. Past sessions have `calc_meta_json` referencing the deleted policy but no way to trace back.

---

### E — Midnight Crossing & Overnight Shifts

**E1. Overnight Session Buffer Expiry (Issue #13)**

Lines 1197–1218: Buffer calculation uses current real time compared against yesterday's shift deadline. Segment-less (total-hours) shifts default to a 24-hour buffer. If an employee's overnight shift had a longer allowed window, they get a "stale session" error and cannot clock out.

**E2. Punch Window Tightening (Issue #13)**

Lines 4754–4769: The 2-hour buffer before shift start can capture punches that should belong to the previous day's overnight shift, causing misattribution.

**E3. Leading OUTs (Issue #14)**

`$closingOut = end($leadingOuts)` (line 4812) uses only the last OUT punch. Earlier OUT punches from device glitches are discarded from the previous session's audit trail.

**E4. Overnight Close-Out Timezone (Issue #15)**

`$closingOut` is in UTC while `$shiftEndDt` is converted to local timezone. The comparison may be off by hours, incorrectly setting or missing the `left_early` flag.

---

### F — Multi-Day Absences

The system marks each day independently. There is no consecutive absence detection, no `consecutive_absence` flag, and no way to distinguish "forgot to request leave for 5 days" from "5 random off days." This is a feature gap, not a bug.

---

### G — Geofencing & Location Validation

**G1. Dual Geofence — No Failure Source (Issue #10)**

Lines 762–789: Both shift and device geofence failures write to the same `valid_geo` column without any flag indicating which one failed.

**G2. Kiosk Geofence Bypass**

Kiosk punches skip geofence checks entirely (line 762: `if ($source !== 'kiosk')`). A kiosk used remotely with spoofed location records `valid_geo=1`.

**G3. GPS Unavailable (Issue #11)**

When GPS data is null, the punch proceeds with `valid_geo=0`. The enforcement is advisory-only.

**G4. No Coordinate Validation (Issue #27)**

Device creation accepts any numeric latitude/longitude without bounds checking (-90/90, -180/180). Invalid coordinates cause haversine to return NaN.

---

### H — Attendance Reports & Calculations

**H1. Overtime After Rounding (Issue #12)**

```php
// Line 5058: Round first
if ($roundN > 0) $net = (int)round($net / $roundN) * $roundN;
// Line 5061: OT calculated after rounding
$ot = max(0, $net - $scheduled);
```

Rounding down reduces reported OT. Example: 8h42m rounded to 8h40m with 8h scheduled = 40m OT instead of 42m.

**H2. Off-Day Status Confusion (Issue #23)**

Total-hours shift with no punches is marked `'absent'` (line 5079) even if the day was genuinely unscheduled. No way to distinguish in reports.

---

### I — Data Integrity & Foreign Keys

**I1. No Foreign Key Constraints (Issue #1)**

| Table | Column | Should Reference | FK? | ON DELETE? |
|-------|--------|-----------------|-----|-----------|
| punches | employee_id | employees.id | NO | — |
| sessions | employee_id | employees.id | NO | — |
| sessions | shift_assign_id | shift_assign.id | NO | — |
| shift_assign | shift_id | shifts.id | NO | — |
| shift_assign | employee_id | employees.id | NO | — |
| flags | employee_id | employees.id | NO | — |
| flags | session_id | sessions.id | NO | — |
| flags | punch_id | punches.id | NO | — |
| audit | target_employee_id | employees.id | NO | — |
| early_leave_requests | employee_id | employees.id | NO | — |
| early_leave_requests | session_id | sessions.id | NO | — |

**I2. Shift Deletion Orphans Assignments (Issue #17)**

No cascade delete from `shifts.id` to `shift_assign.shift_id`. Deleted shift causes all JOINs to return NULL; employees are marked absent on days they had valid assignments.

---

### J — Concurrent Access & Race Conditions

**J1. Punch Lock (Good)**

Lines 907–1065: Named lock `sfs_hr_punch_{emp}` with 5-second timeout, duplicate check inside lock, released in `finally` block. Correct implementation.

**J2. Performance Concern (Issue #21)**

Session recalc runs inside the lock. Under load (50+ employees), kiosks experience multi-second delays.

**J3. Offline Punch Sync**

Offline kiosk sync iterates punches sequentially. The per-employee lock prevents concurrent recalc overlap. This is safe but slow for bulk syncs.

---

### K — API Error Responses

**K1. Inconsistent Error Codes (Issue #26)**

No standard error schema across endpoints. Punch returns 503 for lock timeout; early leave returns 500 for DB errors.

**K2. Silent Session Failures (Issue #3)**

`recalc_session_for()` returns void. No error propagated to the API response even when session update fails.

**K3. Policy Error Suppression (Issue #16)**

`$wpdb->suppress_errors(true)` hides all DB errors during policy lookup (lines 230–232).

---

### L — Additional Findings

**L1. Break Policy Enum Mismatch (Issue #18)**

Schema: `ENUM('auto','punch','none')` — Code: checks for `'free'` which doesn't exist in the ENUM.

**L2. `locked` Column Unused (Issue #30)**

Schema defines `locked TINYINT(1)` but no code reads or writes it. Sessions can be recalculated unlimited times.

**L3. Selfie Attachment Orphaning (Issue #22)**

Selfie images uploaded via `save_selfie_attachment()` create WordPress media attachments. When a punch or session is deleted, the associated selfie attachment is never cleaned up. No cron job or lifecycle hook exists to detect and remove orphaned selfie media. Over time, these accumulate in `wp-content/uploads/` and consume disk space.

---

## Fixing Plan

### Phase 1 — Critical Fixes

These fixes address data integrity and silent failure modes that can cause payroll inaccuracies.

#### Fix 1.1 — Add Foreign Key Constraints (Issue #1)

**Files:** `AttendanceModule.php` (schema creation), new migration file

**Changes:**
- Add `ON DELETE CASCADE` foreign keys for all attendance tables referencing `employees.id`
- Add `ON DELETE SET NULL` for `sessions.shift_assign_id` → `shift_assign.id`
- Add `ON DELETE CASCADE` for `shift_assign.shift_id` → `shifts.id`
- Write a one-time migration to clean existing orphaned rows before adding constraints
- Add `InnoDB` engine enforcement (FK requires InnoDB)

#### Fix 1.2 — Add Error Handling to Session Recalc (Issue #3)

**File:** `AttendanceModule.php` — `recalc_session_for()`

**Changes:**
- Check return value of `$wpdb->update()` and `$wpdb->insert()`
- Return `WP_Error` on failure instead of void
- Log error via `error_log()` with session details
- Propagate error to punch API response so employee sees a warning
- Wrap session insert/update + audit + flags in a DB transaction

#### Fix 1.3 — Implement Admin Punch Correction API (Issue #2)

**Files:** New `class-attendance-admin-rest.php` endpoints, `Admin_Pages` UI additions

**Changes:**
- Add `POST /sfs-hr/v1/attendance/punches/admin-create` endpoint
- Add `PUT /sfs-hr/v1/attendance/punches/{id}/admin-edit` endpoint
- Add `DELETE /sfs-hr/v1/attendance/punches/{id}/admin-delete` endpoint
- Set `source='manager_adjust'` for all admin-created punches
- Require `manage_attendance` capability
- Auto-trigger `recalc_session_for()` after any change
- Add audit trail entry for every admin punch action
- Add "Add Punch" and "Edit" buttons to the admin punches table UI

---

### Phase 2 — High-Priority Fixes

#### Fix 2.1 — Fix Timezone in Early Leave Auto-Reject (Issue #4)

**File:** `Early_Leave_Auto_Reject.php`

**Changes:**
- Replace `gmdate()` with `current_time('mysql')` for cutoff calculation
- Ensure both cutoff and `created_at` use WordPress timezone consistently
- Add unit test with WP timezone set to UTC+5 and UTC-5

#### Fix 2.2 — Add Bulk Session Recalc on Shift Change (Issue #5)

**Files:** `AttendanceModule.php`, shift update REST handler

**Changes:**
- Hook into shift update to detect start_time/end_time changes
- Queue a background job (via `wp_schedule_single_event`) to recalculate all sessions for the affected shift within the last N days (configurable, default 30)
- Add admin UI button: "Recalculate sessions for this shift"
- Log recalculation results

#### Fix 2.3 — Calculate `break_delay_minutes` in Normal Flow (Issue #6)

**File:** `AttendanceModule.php` — `recalc_session_for()`

**Changes:**
- After calculating `break_total` from break_start/break_end punches, compare each break duration against the allowed break duration from shift config
- Sum excess break time into `break_delay_minutes`
- Store in session record alongside `break_minutes`

---

### Phase 3 — Medium-Priority Fixes

#### Fix 3.1 — Add Geofence Failure Source Column (Issue #10)

**Files:** `AttendanceModule.php` (schema), punch REST handler

**Changes:**
- Add `geo_fail_source ENUM('none','shift','device','both') DEFAULT 'none'` to punches table
- Set appropriately during punch validation
- Display in admin punch detail view

#### Fix 3.2 — Block Punch When GPS Required but Unavailable (Issue #11)

**File:** `class-attendance-rest.php` — punch handler

**Changes:**
- When `$enforce_geo` is true and `$lat`/`$lng` are null, return `400` error instead of proceeding with `valid_geo=0`
- Add error code `'geo_required'` with message "GPS location is required for this punch"

#### Fix 3.3 — Fix Overtime Calculation Order (Issue #12)

**File:** `AttendanceModule.php`

**Changes:**
- Calculate `$ot = max(0, $net - $scheduled)` BEFORE rounding `$net`
- Then round both `$net` and `$ot` independently
- This ensures fractional overtime is not lost to rounding

#### Fix 3.4 — Fix Overnight Shift Timezone Comparisons (Issues #13, #14, #15)

**File:** `AttendanceModule.php`

**Changes:**
- Normalize all punch times and shift times to UTC before comparison
- For leading OUTs: use the FIRST out punch (not last) for previous session close, and log all intermediate punches
- Add explicit timezone conversion in overnight close-out logic

#### Fix 3.5 — Fix Early Leave Concurrent Approval (Issue #7)

**File:** `class-early-leave-rest.php`

**Changes:**
- Move `recalc_session_for()` call inside a check that the CAS update succeeded (already partially done)
- Add a short MySQL lock around the approval + recalc sequence to prevent double recalc

#### Fix 3.6 — Allow `affects_salary` Control on Rejection (Issue #8)

**File:** `class-early-leave-rest.php`

**Changes:**
- Accept `affects_salary` parameter for both approve and reject actions
- Default to 0 for rejection (no salary impact) instead of hardcoded 1

#### Fix 3.7 — Back-Link Early Leave to Session via Cron (Issue #9)

**Files:** New cron job or hook into session creation

**Changes:**
- After any session is created/updated, check for unlinked early leave requests for the same employee + date
- Back-link by updating `early_leave_requests.session_id`

#### Fix 3.8 — Remove Policy Error Suppression (Issue #16)

**File:** `Policy_Service.php`

**Changes:**
- Remove `suppress_errors(true/false)` calls
- Add proper error checking: `if ($wpdb->last_error) error_log(...)`
- Return explicit null with logged context on failure

#### Fix 3.9 — Fix Break Policy Enum Mismatch (Issue #18)

**Files:** `AttendanceModule.php` (schema), code references

**Changes:**
- Either add `'free'` to the ENUM definition, or replace `'free'` checks in code with `'auto'`
- Verify which behavior `'free'` was intended to represent and align schema + code

#### Fix 3.10 — Add Compound Status for Reports (Issue #19)

**File:** `AttendanceModule.php`

**Changes:**
- When both `late` and `left_early` flags are present, set `$status = 'late_and_left_early'`
- Add this value to the status display map
- Alternatively, make reports read from the flags array instead of the single status field

#### Fix 3.11 — Enforce Selfie Requirement (Issue #20)

**File:** `class-attendance-rest.php`

**Changes:**
- When `$require_selfie` is true and selfie save fails, return a `400` error instead of proceeding
- Add error code `'selfie_required'` with message "Selfie upload failed; punch not recorded"
- Add a policy flag `selfie_enforcement` with values `'strict'` (block) or `'flag'` (current behavior) for backward compatibility

#### Fix 3.12 — Selfie Attachment Auto-Cleanup (Issue #22)

**Files:** New cron class `Selfie_Cleanup_Cron.php`, `AttendanceModule.php` (cron registration)

**Changes:**
- Register a daily WP cron event: `sfs_hr_selfie_cleanup`
- Query for selfie media attachments (by meta key or post type) whose associated punch no longer exists
- Delete orphaned attachments and their physical files via `wp_delete_attachment($id, true)`
- Add a configurable retention period (default: 90 days) — selfies older than retention with no linked punch are deleted
- Log cleanup results: count of deleted attachments, disk space freed
- Add admin settings field for retention period under Attendance > Settings
- On employee deletion cascade (from Fix 1.1), also delete their selfie attachments

#### Fix 3.13 — Move Session Recalc Outside Punch Lock (Issue #21)

**File:** `class-attendance-rest.php`

**Changes:**
- Release the punch lock after insert + audit
- Run `recalc_session_for()` outside the lock
- Add a separate, shorter lock for session recalc if needed
- This reduces lock hold time from ~2s to ~100ms

---

### Phase 4 — Low-Priority & Hardening

#### Fix 4.1 — Add Coordinate Validation (Issue #27)

**File:** `class-attendance-admin-rest.php`

**Changes:**
- Validate latitude (-90 to 90) and longitude (-180 to 180) on device creation/update
- Return `400` error for out-of-range values

#### Fix 4.2 — Standardize API Error Responses (Issue #26)

**Files:** All REST classes

**Changes:**
- Create a shared error response helper with consistent structure: `{ code, message, status, details }`
- Replace ad-hoc error returns across all endpoints

#### Fix 4.3 — Disambiguate Off-Day vs Absent (Issue #23)

**File:** `AttendanceModule.php`

**Changes:**
- For total-hours shifts with no punches, check if the day is a scheduled work day
- If not scheduled: `status = 'day_off'`; if scheduled: `status = 'absent'`

#### Fix 4.4 — Suppress Contradictory Break Flag (Issue #24)

**File:** `AttendanceModule.php`

**Changes:**
- When policy auto-deducts break, suppress the `no_break_taken` flag since the system handled it

#### Fix 4.5 — Add Policy Cache TTL (Issue #25)

**File:** `Policy_Service.php`

**Changes:**
- Add a timestamp to each cache entry
- Invalidate entries older than 60 seconds
- Clear cache on policy update (already done) and on policy delete

#### Fix 4.6 — Fix Offline Punch Time Comparison (Issue #31)

**File:** `class-attendance-rest.php`

**Changes:**
- Replace `time()` with `current_time('timestamp', true)` for consistency

#### Fix 4.7 — Add Total-Hours Shift Detection Guard (Issue #29)

**File:** `Policy_Service.php`

**Changes:**
- Add explicit `is_total_hours` boolean column to shifts table
- Stop relying on `start_time === end_time` heuristic

#### Fix 4.8 — Implement Session Locking (Issue #30)

**File:** `AttendanceModule.php`

**Changes:**
- Add admin action to lock a session (prevents recalculation)
- Check `locked` flag in `recalc_session_for()` and skip if locked
- Add "Lock/Unlock" button to session detail in admin UI

#### Fix 4.9 — Handle `auto` Break Policy with Zero Duration (Issue #32)

**File:** `AttendanceModule.php`

**Changes:**
- When `break_policy='auto'` and `unpaid_break_minutes=0`, log a warning
- Optionally surface a validation error in the admin shift form

---

## Testing Recommendations

### Concurrent Access
- Load test with 50+ simultaneous punches for different employees
- Test two managers approving the same early leave request simultaneously

### Timezone
- Run full test suite with WordPress set to UTC+5, UTC-5, and UTC+12
- Verify auto-reject cron fires at correct times
- Verify overnight shift close-out flags

### Overnight Shifts
- Test 23:00–02:00 shift across DST boundaries
- Test incomplete session close-out with next-day leading OUT punches
- Verify punch attribution for shifts crossing midnight

### Offline Sync
- Simulate 100 offline punches syncing at once
- Verify final session state matches expected values
- Measure lock contention under bulk sync

### Data Integrity
- Delete an employee and verify all related records cascade correctly
- Delete a shift and verify assignments update appropriately
- Verify no orphaned selfie attachments remain after punch deletion

### Selfie Cleanup
- Create punches with selfies, delete the punches, run cleanup cron
- Verify physical files are removed from `wp-content/uploads/`
- Test retention period boundary (89 days vs 91 days)
- Verify cleanup logs report accurate counts

### Geofencing
- Test punch with GPS disabled when geofence is enforced
- Test with coordinates at exact boundary of geofence radius
- Test device creation with invalid coordinates

### Payroll Accuracy
- Verify overtime calculation with various rounding values (5, 10, 15 min)
- Verify break delay is calculated in normal session flow
- Verify `affects_salary` behaves correctly for approved and rejected early leaves
