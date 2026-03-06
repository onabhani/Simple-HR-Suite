# Attendance Module — Issues Report & Fixing Plan

> Generated: 2026-03-05
> Branch: `claude/fix-calendar-translations-b43kf`

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Issues Registry](#issues-registry)
   - [Critical Severity (C1–C9)](#critical-severity-c1c9)
   - [High Severity (H1–H12)](#high-severity-h1h12)
   - [Medium Severity (M1–M13)](#medium-severity-m1m13)
   - [Low Severity (L1–L9)](#low-severity-l1l9)
3. [Functional Flow Scenarios (F1–F30)](#functional-flow-scenarios-f1f30)
4. [Supplementary Findings — Extended Kiosk Investigation (S1–S10)](#supplementary-findings--extended-kiosk-investigation-s1s10)
5. [Fixing Plan](#fixing-plan)
   - [Phase 1 — Critical Fixes](#phase-1--critical-fixes)
   - [Phase 2 — High-Priority Fixes](#phase-2--high-priority-fixes)
   - [Phase 3 — Medium-Priority Fixes](#phase-3--medium-priority-fixes)
   - [Phase 4 — Low-Priority & Hardening](#phase-4--low-priority--hardening)
6. [Testing Recommendations](#testing-recommendations)

---

## Executive Summary

A comprehensive code audit of the Attendance module across multiple investigation rounds uncovered **~65 distinct issues** spanning all severity levels, plus 30 functional flow scenarios that expose real-world edge cases. The most critical problems include: lack of foreign key constraints, missing admin punch correction API, device department restrictions being ignored, kiosk operators able to punch for any employee, and silent session calculation failures. The module has solid per-employee punch locking but significant gaps in data integrity, security enforcement, timezone handling, and edge-case coverage for overnight shifts.

---

## Issues Registry

### Critical Severity (C1–C9)

| # | Issue | Root Cause | Impact |
|---|-------|-----------|--------|
| C1 | Missing `'UTC'` suffix in `strtotime()` — calculation errors | `strtotime($timestamp)` called without appending `' UTC'`, so PHP uses server default timezone (line 556 in `class-attendance-rest.php`) | Punch time comparisons, buffer expiry checks, and session attribution off by timezone offset; punches attributed to wrong day for non-UTC servers |
| C2 | Missing `'UTC'` in `strtotime()` — display/comparison errors | Same root cause at lines 1202, 1207 in `class-attendance-rest.php`; `$last_ts_g = strtotime($last_punch_utc)` doesn't append UTC | Overnight buffer deadline and stale-session detection use wrong timestamps; employees incorrectly locked out or allowed to punch |
| C3 | Incomplete session permanently locks out employee | `snapshot_for_employee()` (lines 684–703) finds stale `'incomplete'` session from previous day and treats it as active, blocking new session creation | Employee cannot punch in next day until admin manually closes old session or cron runs; effectively locked out of attendance system |
| C4 | No foreign key constraints on any attendance table | Schema (lines 4114–4380 in `AttendanceModule.php`) uses no FK references between punches, sessions, flags, audit, and employees | Deleting an employee leaves orphaned punches, sessions, flags, and audit records; reports return stale/ghost data; storage bloat grows unbounded |
| C5 | Manual punch correction feature missing entirely | `source` ENUM includes `'manager_adjust'` (line 4119) but no REST endpoint or admin form exists | Admins cannot correct wrong clock-in/out times, missed punches, or kiosk errors; payroll inaccuracies persist indefinitely |
| C6 | Device department restriction bypassed | Device table has `department_id` column (line ~4291) but punch handler (lines 870–880) never validates employee department matches device department | Employees can punch from any kiosk regardless of department; department-level access control is theater |
| C7 | Kiosk operator can punch for ANY employee | Kiosk punch flow (lines 848–865) accepts any `employee_id` without verifying assignment to kiosk location or operator authority | Buddy punching trivially possible; any kiosk operator can clock in/out any employee in the entire system |
| C8 | Offline punch timestamp not server-validated against shift | Offline validation (lines 556–573) only checks staleness (24h) and future drift (5min), not whether punch falls within employee's shift window | Offline kiosk can submit punches for any time in the last 24h regardless of shift assignment; time fraud possible |
| C9 | `overtime_after_minutes` stored but never used in OT calculation | Shifts table has `overtime_after_minutes` column (line ~4140) but `recalc_session_for()` calculates `$ot = max(0, $net - $scheduled)` without referencing it (lines 5060–5070) | Admin-configured OT thresholds silently ignored; all time above scheduled counts as OT regardless of threshold setting |

### High Severity (H1–H12)

| # | Issue | Root Cause | Impact |
|---|-------|-----------|--------|
| H1 | `time()` vs `current_time()` mismatch in multiple places | Inconsistent use of PHP `time()` (line 556), `gmdate(time())` (auto-reject line 39), and WP `current_time()` (line 1202) across the codebase | Time comparisons off by timezone offset; deadline calculations, offline validation, and auto-reject fire at wrong times |
| H2 | State machine check before lock acquisition (TOCTOU) | Punch state validation (is employee clocked in? on break?) runs at lines ~848–900 before per-employee lock acquired at line 910 | Two concurrent punches both pass state checks; duplicate clock-in or invalid state transition (e.g., double clock-out) possible |
| H3 | Session recalc race with `Daily_Session` cron | Cron's `rebuild_sessions_for_date_static()` and real-time punch both call `recalc_session_for()` for same employee+date with no coordination lock | Cron overwrites more recent session state, losing latest punch data; race window is the entire cron execution period |
| H4 | Timezone bug in early leave auto-reject | `$cutoff` uses `gmdate()` (UTC) while `created_at` stored via `current_time('mysql')` (WP timezone) — lines 39–40 of `Early_Leave_Auto_Reject.php` | Requests auto-rejected hours too early or too late; employees lose valid pending requests or stale ones linger |
| H5 | Absence never detected if no punches and no cron runs | Absence detection relies entirely on `rebuild_sessions_for_date_static()` running; if cron fails, absent employees get no session record at all | Absent employees invisible to reports; attendance metrics undercount absences; no fallback mechanism |
| H6 | Segment-level grace flags employee late when grace=0 | `evaluate_segments()` (line ~6350): if grace not configured, `$graceLateMin` defaults to 0; any delay > 0 seconds triggers `'late'` flag | Employees flagged late for arriving 1 second after shift start when no grace period explicitly set; false positives in lateness |
| H7 | PIN brute force protection too weak | No exponential backoff or account lockout after N failed PIN attempts; cooldown is minimal | 4-digit PINs (10,000 combinations) can be brute-forced with automated requests in minutes |
| H8 | No IP geofencing for kiosk devices | Device validation only checks device_id + token + active status; no IP range or network CIDR restriction | Stolen/cloned kiosk token usable from any network worldwide; device authentication relies solely on token secrecy |
| H9 | Device fingerprint stored but never validated | Device registration stores fingerprint, but punch handler validates only device_id + token without fingerprint comparison | Device fingerprint provides zero security; cloned credentials work from any physical device |
| H10 | Session insert/update failures are silent | `$wpdb->update()` / `$wpdb->insert()` return value ignored; `recalc_session_for()` returns void (line 4687) | Punch succeeds but session silently broken; payroll never reflects the work; data loss undetected |
| H11 | Geofence failure doesn't distinguish shift vs device | Both shift geofence and device geofence failures set same `valid_geo=0` without distinguishing source (lines 762–789) | Managers can't determine which geofence failed; investigation and dispute resolution impossible |
| H12 | Retroactive overnight session closure skips overtime calculation | Leading-OUT retroactive closure path (lines 4894–4910) computes `netMinutes` and `roundedNet` but never calculates or stores `overtime_minutes`; also skips `break_delay_minutes`, `no_break_taken`, and `calc_meta_json` | Employees whose overnight sessions close retroactively get `overtime_minutes=0` permanently regardless of actual hours; payroll underpays overtime for all overnight shifts closed via this path |

### Medium Severity (M1–M13)

| # | Issue | Root Cause | Impact |
|---|-------|-----------|--------|
| M1 | Concurrent early leave approval race condition | Two managers pass permission checks simultaneously; `recalc_session_for()` called by both threads (lines 329–367) | Session flags/minutes non-deterministic depending on which recalc finishes last |
| M2 | Rounding applied before OT calculation | `$net` rounded first, then `$ot = max(0, $net - $scheduled)` (lines 5058–5061) | Fractional overtime minutes lost to rounding; systematic OT underpayment |
| M3 | Punch window tightening misattributes overnight punches | 2-hour buffer before shift start captures previous day's carryover punches (lines 4754–4769) | Punches attributed to wrong day; previous day appears incomplete, today shows phantom early arrival |
| M4 | Holiday overnight OUT not correctly attributed | When overnight shift spans a holiday boundary, the OUT punch on the holiday side may be attributed to the holiday session instead of the regular shift session | Employee's regular shift appears incomplete; holiday session gets unexpected punch data |
| M5 | Early leave request orphaned when session doesn't exist yet | Session lookup returns NULL during approval; no retry or back-link (lines 344–352) | Approved early leave never linked to session; audit trail broken; reports can't correlate |
| M6 | Selfie requirement bypassed in offline kiosk mode | Offline kiosk stores punches locally without capturing selfie; when synced, `valid_selfie` not enforced | Employees punch without identity verification during offline periods; selfie policy unenforceable |
| M7 | Scan token expires during manual selfie capture | QR scan token has short expiry window that doesn't account for camera/selfie capture time | Employee scans QR successfully but punch fails after taking selfie; must re-scan and redo flow |
| M8 | Offline roster becomes stale for new employees | Kiosk downloads employee roster at sync time; no push notification for roster changes | New employees cannot punch on offline kiosks until kiosk reconnects and re-syncs roster |
| M9 | Off-day blocks ALL punches including authorized overtime | When `resolve_shift_for_date()` returns null and policy doesn't allow shiftless punches, employee is blocked entirely | Employees authorized for overtime on off-days cannot punch; must wait for admin to create shift assignment |
| M10 | Orphaned data when employee/shift/device deleted | No FK constraints (C4); no cleanup hooks on entity deletion | Orphaned records accumulate across all attendance tables; reports return ghost data |
| M11 | Batch offline sync triggers N separate recalculations | Each synced punch triggers full `recalc_session_for()` individually (lines 530–574) | 20 offline punches = 20 full session rebuilds; slow sync, heavy DB load, kiosk appears frozen |
| M12 | `Policy_Service` suppresses DB errors | `$wpdb->suppress_errors(true)` around policy lookup (lines 230–232 in `Policy_Service.php`) | Corrupted table or permission errors produce silent NULL; debugging impossible; employees get no policy |
| M13 | `affects_salary` hardcoded to 1 on ELR rejection | `$action === 'approve' ? $affects : 1` (line 323 in `class-early-leave-rest.php`) | Rejected early leave always impacts salary; managers cannot reject without penalty |

### Low Severity (L1–L9)

| # | Issue | Root Cause | Impact |
|---|-------|-----------|--------|
| L1 | Cooldown check before lock can give false negative | Duplicate/cooldown query runs outside the per-employee lock critical section | Rare false negative on duplicate detection; mitigated by second check inside lock |
| L2 | Client-side cooldown display doesn't match server | Kiosk client and server use independent timers; clock skew possible | Employee sees "ready to punch" but server rejects, or vice versa; minor UX confusion |
| L3 | Cross-device token reuse when `device_id` changes | No token revocation on device deactivation; old tokens may remain valid | Deactivated device tokens potentially still usable from other devices |
| L4 | No half-day / short-day status | Session status enum has no `half_day` or `short_day` value | Reports can't distinguish planned half-days from unexpected early departures |
| L5 | No consecutive absence detection | Each day marked independently; no multi-day absence tracking or flags | Can't distinguish "forgot to request leave for 5 days" from "5 random off days" |
| L6 | No absence reason tracking | Sessions marked `absent` have no `reason` or `absence_type` field | Reports show absences but can't categorize (sick, personal, unauthorized); HR must cross-reference leave system |
| L7 | Selfie has no temporal freshness validation | Selfie is accepted as binary blob; no EXIF timestamp check or liveness detection | Employee can submit pre-captured photo as their punch selfie; identity verification weakened |
| L8 | Inconsistent API error codes across endpoints | Punch returns 503 for lock timeout; early leave returns 500 for DB error; no standard schema | Frontend must handle errors inconsistently per endpoint; error recovery logic fragile |
| L9 | Break state requires extra click to clock out | State machine requires BREAK_END before OUT; no shortcut "end break and leave" transition | Minor UX friction; employee must tap twice to leave while on break |

---

## Functional Flow Scenarios (F1–F30)

These scenarios describe real-world edge cases, what happens in the code, and the user-visible consequence.

| # | Scenario | What Happens | Consequence |
|---|----------|-------------|-------------|
| F1 | Employee punches in, never punches out | Session stays `status='incomplete'`; next day's leading OUT retroactively closes it; if no OUT comes, session stays incomplete indefinitely | Payroll can't calculate hours; incomplete sessions accumulate; admin must manually close |
| F2 | Employee punches out without punching in | OUT punch has no matching IN; treated as "leading OUT" for previous day's incomplete session; if no incomplete session exists, punch is orphaned | Orphaned punch in DB; no session created; employee's attendance not recorded |
| F3 | Employee double-punches (clock in twice) | Duplicate check inside lock (lines 927–949) catches exact duplicates within cooldown window; if outside window, second IN creates state conflict | Within cooldown: rejected (409); outside cooldown: may create corrupt session with two INs |
| F4 | Employee punches during approved leave | Punch proceeds normally; session created alongside leave record; no cross-check with leave system | Employee appears both "on leave" and "present"; payroll may double-count or conflict |
| F5 | Employee's shift changes mid-day | Already-punched session uses old shift parameters; `recalc_session_for()` not triggered on shift change | Session retains stale shift data until manual recalc; late/early flags based on old schedule |
| F6 | Kiosk goes offline during shift change | Kiosk stores punches in IndexedDB; uses cached roster and shift data; punches timestamped with client clock | Punches synced later with potentially stale shift assignments; client clock drift creates wrong timestamps |
| F7 | Employee transfers departments mid-shift | Department change effective immediately but current session references old department/shift assignment | Session completed under old department; reporting shows mixed department data for that day |
| F8 | Employee has approved leave but attends anyway | No cross-check between leave and attendance; session built normally from punches | Both leave record and attendance session exist for same day; payroll conflict |
| F9 | Admin changes shift start from 09:00 to 08:00 | Past sessions not recalculated (H3/issue #5); employees who arrived at 08:30 now appear "late" for new 08:00 start but their sessions still show old calculation | Historical data inconsistent until manual recalc per date |
| F10 | Employee deleted from HR system | No FK cascade (C4); punches, sessions, flags, audit, early leave requests all remain with dangling `employee_id` | Ghost records in all tables; reports return data for non-existent employees; storage bloat |
| F11 | Shift deleted while employees assigned to it | No cascade from `shifts.id` to `shift_assign.shift_id`; JOINs return NULL; employees resolve to no shift | Previously assigned employees suddenly marked absent; historical sessions retain stale shift reference |
| F12 | Employee in 2 WP roles with different attendance policies | `Policy_Service::get_effective_policy()` resolves by priority; if priorities equal or unconfigured, result is non-deterministic | Employee may get wrong break rules, grace period, or OT thresholds depending on which policy wins |
| F13 | Employee punches in at 10:00 AM but shift starts at 08:00 | `evaluate_segments()` calculates `firstIn - segStart = 120min` > grace → `late` flag; late_minutes = 120 | Same "Late" status whether 1 minute or 2 hours late; no severity grading in reports |
| F14 | Employee punches in at 09:00 AM but shift changed to 10:00 | Past session still calculated against old 08:00 start; no retroactive recalc triggered | Employee's historical record shows incorrect lateness based on outdated shift times |
| F15 | GPS disabled on employee phone, geofence enforced | `$lat`/`$lng` are null; `valid_geo` set to 0; punch still proceeds and succeeds (lines 764–765) | Employee punches successfully with GPS off; geofence enforcement is advisory-only |
| F16 | Kiosk used from different network/location | Kiosk device physically moved; token still valid; kiosk punches bypass geofence (line 762) | Punches appear fully valid from wrong location; no alert to admin; no IP-based restriction |
| F17 | Manager approves ELR while `Daily_Session` cron running | Both trigger `recalc_session_for()` for same employee+date simultaneously (race H3) | Early leave flag may be lost if cron's recalc finishes after approval's recalc |
| F18 | Holiday falls on employee's day off | `$is_holiday = true`, `$shift = null` → status set to `'holiday'` instead of `'day_off'` | May affect holiday pay calculations if holiday-on-day-off has different rules; employee sees "Holiday" |
| F19 | Employee works on holiday | Session built normally with `$is_holiday = true`; OT calculated as regular OT without holiday multiplier | Employee works holiday but gets regular rate, not holiday premium; payroll underpayment |
| F20 | Employee clocks in, takes break, wants to leave early | IN → BREAK_START → BREAK_END → ELR approved → session recalc with early leave + break deduction | Possible double-deduction: break minutes deducted AND early leave reduces net hours |
| F21 | IndexedDB cleared on offline kiosk | Browser data cleared (update, manual clear); all stored offline punches permanently lost | Employees who punched while offline have no record; punches irrecoverable; no backup mechanism |
| F22 | 100+ employees punch simultaneously at shift start | 100 concurrent DB connections; 100 per-employee locks (no cross-employee contention); 100 session recalculations | DB connection pool may exhaust `max_connections`; some get 503; kiosk queue builds up at peak times |
| F23 | `overtime_after_minutes` set to 30 on shift but ignored | Employee works 8h30m (scheduled 8h); `$ot = max(0, 510 - 480) = 30`; threshold field never read (C9) | 30 min OT counted when policy says OT starts only after 30 min of extra work; should be 0 |
| F24 | Employee in split-shift (08-12, 14-18), late to second segment | `evaluate_segments()` checks per-segment; 14:15 arrival → 15min > 5min grace → late flag on segment 2 | Overall status shows "Late" with no indication it was only the second segment; first segment was on-time |
| F25 | Auto-reject cron runs at 72h but timezone mismatch | Same as H4; cutoff in UTC, `created_at` in WP timezone | Requests expire at wrong time depending on WP timezone offset |
| F26 | QR code photo shared between employees | Employee B photographs Employee A's QR code; scans photo at kiosk; kiosk reads QR as valid | Buddy punching via QR photo; Employee A appears present when absent; no liveness check on QR |
| F27 | Network failure during punch (after lock, before response) | Lock acquired → duplicate check → punch inserted → network drops before response sent to client | Employee sees error but punch was actually recorded; retry creates 409 duplicate; confusing UX |
| F28 | Break delay: employee takes 90min break on 30min allowance | BREAK_START → 90min → BREAK_END; `break_total = 90`; but `break_delay_minutes` not calculated in normal flow (H12/issue #6) | 60-minute break overrun not recorded; reports show break taken but no flag for exceeding allowance |
| F29 | `selfie_retention_days` configured but no cleanup runs | Admin sets retention in settings; no cron job exists to act on it; selfies accumulate indefinitely | Server storage fills up over months; admin must manually clean files; config is non-functional |
| F30 | Policy `calculation_mode='total_hours'` edge cases | Shift has `start_time = end_time = 00:00`; total-hours mode; no segment-based late/early detection | Employee can start at any time and be "present" as long as total hours meet target; no lateness tracking |

---

## Supplementary Findings — Extended Kiosk Investigation (S1–S10)

| # | Severity | Issue | Root Cause | Impact |
|---|----------|-------|-----------|--------|
| S1 | CRITICAL | No rate limiting on punch endpoint | WordPress REST has no built-in rate limiting; plugin adds none; only per-employee cooldown exists | Automated tools can flood punch endpoint causing DB contention and DoS; brute-force attacks unthrottled |
| S2 | HIGH | Off-day detection bypassed depending on policy | Off-day enforcement depends on `allow_punch_without_shift` policy config which may not be set | Inconsistent off-day enforcement; some employees blocked, others not, depending on policy gaps |
| S3 | HIGH | Admin rebuild endpoint has no rate limiting | `rebuild_sessions` endpoint checks capability but allows unlimited rebuilds with no throttle | Compromised admin account can trigger sustained heavy DB load via repeated rebuild requests |
| S4 | HIGH | Offline punch sync doesn't validate timestamps against shifts | Offline timestamps validated for staleness only (24h window), not against employee's actual shift (same as C8) | Fraudulent offline punches possible for any time in last 24 hours |
| S5 | MEDIUM | jsQR loaded from CDN without SRI (Subresource Integrity) | QR scanning library loaded from external CDN without `integrity` hash attribute | CDN compromise could inject malicious code into kiosk interface; supply-chain risk |
| S6 | MEDIUM | IndexedDB quota not monitored before writes | Offline kiosk writes to IndexedDB without checking available storage quota | If IndexedDB fills up, offline punches silently fail to store; employee thinks they punched but data lost |
| S7 | MEDIUM | Policy cache not invalidated on all update paths | Static `$cache` cleared on `delete_policy()` (line 546) but may miss some update code paths | Stale policy used for session calculations within same PHP request after policy change |
| S8 | MEDIUM | No PIN rotation enforcement | No `pin_last_changed` tracking or expiry mechanism; PINs valid indefinitely | Compromised PINs remain usable forever; no forced rotation degrades security posture over time |
| S9 | MEDIUM | Session recalc doesn't exclude locked sessions | `recalc_session_for()` never checks `$session->locked` flag (column exists but unused — line 4163) | "Locked" sessions silently overwritten by any recalc trigger; no way to freeze finalized payroll sessions |
| S10 | MEDIUM | Audit log insert failure is silent | `$wpdb->insert()` for audit log (line ~997) return value not checked | Audit trail may have gaps; compliance-critical punch events go unrecorded without any error |

---

## Fixing Plan

### Phase 1 — Critical Fixes

These fixes address data integrity, security gaps, and silent failure modes.

#### Fix 1.1 — Add Foreign Key Constraints (C4, M10, F10, F11)

**Files:** `AttendanceModule.php` (schema), new migration file

- Add `ON DELETE CASCADE` foreign keys for all tables referencing `employees.id`
- Add `ON DELETE SET NULL` for `sessions.shift_assign_id` → `shift_assign.id`
- Add `ON DELETE CASCADE` for `shift_assign.shift_id` → `shifts.id`
- Write one-time migration to clean existing orphaned rows before adding constraints
- Enforce `InnoDB` engine (required for FK)

#### Fix 1.2 — Add Error Handling & Transactions to Session Recalc (C3 via H10, S10)

**File:** `AttendanceModule.php` — `recalc_session_for()`

- Check return value of `$wpdb->update()` and `$wpdb->insert()`
- Return `WP_Error` on failure instead of void
- Log errors via `error_log()` with session details
- Wrap session insert/update + audit + flags in DB transaction
- Also check audit log insert return value (S10)

#### Fix 1.3 — Implement Admin Punch Correction API (C5)

**Files:** New REST endpoints in `class-attendance-admin-rest.php`, `Admin_Pages` UI

- `POST /sfs-hr/v1/attendance/punches/admin-create`
- `PUT /sfs-hr/v1/attendance/punches/{id}/admin-edit`
- `DELETE /sfs-hr/v1/attendance/punches/{id}/admin-delete`
- Set `source='manager_adjust'`; require `manage_attendance` capability
- Auto-trigger `recalc_session_for()` after change; add audit trail

#### Fix 1.4 — Enforce Device Department Restriction (C6)

**File:** `class-attendance-rest.php` — punch handler

- After loading device at lines 870–880, compare `$device->department_id` with employee's department
- Return `403` if mismatch; add error code `'device_department_mismatch'`

#### Fix 1.5 — Restrict Kiosk Punch to Assigned Employees (C7)

**File:** `class-attendance-rest.php` — kiosk punch flow

- Validate employee is assigned to the kiosk's location/department before accepting punch
- Add `kiosk_employee_assignment` check or use shift assignment as proxy

#### Fix 1.6 — Append `' UTC'` to All `strtotime()` Calls (C1, C2)

**Files:** `class-attendance-rest.php` (lines 556, 1202, 1207), other affected files

- Audit all `strtotime()` calls in attendance module
- Append `' UTC'` to timestamp strings that represent UTC values
- Add unit tests verifying correct behavior across timezone configurations

#### Fix 1.7 — Add Rate Limiting to Punch Endpoint (S1)

**File:** `class-attendance-rest.php`

- Implement IP-based rate limiting (e.g., max 60 requests/minute per IP)
- Return `429 Too Many Requests` when exceeded
- Consider using WordPress transients for lightweight tracking

---

### Phase 2 — High-Priority Fixes

#### Fix 2.1 — Fix Timezone in Early Leave Auto-Reject (H4, F25)

**File:** `Early_Leave_Auto_Reject.php`

- Replace `gmdate()` with `current_time('mysql')` for cutoff calculation
- Ensure both cutoff and `created_at` use WordPress timezone consistently

#### Fix 2.2 — Move State Machine Check Inside Lock (H2)

**File:** `class-attendance-rest.php`

- Move snapshot/state validation (lines ~848–900) to after lock acquisition (line 912)
- Eliminates TOCTOU race between state check and punch insert

#### Fix 2.3 — Add Session Recalc Coordination Lock (H3, F17)

**File:** `AttendanceModule.php`

- Add a per-employee MySQL named lock in `recalc_session_for()` (separate from punch lock)
- Prevents cron rebuild and real-time punch from racing on same employee+date

#### Fix 2.4 — Add Bulk Session Recalc on Shift Change (F5, F9, F14)

**Files:** `AttendanceModule.php`, shift update REST handler

- Hook into shift update to detect start_time/end_time changes
- Queue background job to recalculate affected sessions for last N days (default 30)
- Add admin UI button: "Recalculate sessions for this shift"

#### Fix 2.5 — Fix Retroactive Overnight Closure to Include OT & Break Delay (H12, F28)

**File:** `AttendanceModule.php` — leading-OUT closure path (lines 4894–4910)

- Calculate `overtime_minutes` same as normal path (line 5061)
- Calculate `break_delay_minutes` from break punch durations vs allowance
- Update `no_break_taken` and `calc_meta_json` fields
- Alternatively, call full `recalc_session_for()` after retroactive close

#### Fix 2.6 — Ensure Absence Detection Without Cron (H5)

**File:** `AttendanceModule.php`, reporting queries

- Add fallback: reports should detect "no session" for a scheduled work day as absent
- Don't rely solely on session records existing; check shift assignments vs sessions

#### Fix 2.7 — Set Sane Grace Period Default (H6)

**File:** `AttendanceModule.php` — `evaluate_segments()`

- Default `$graceLateMin` to a configurable global value (e.g., 1 minute) instead of 0
- Or: require explicit grace configuration per shift and warn admin if unset

#### Fix 2.8 — Strengthen PIN Brute Force Protection (H7)

**File:** `class-attendance-rest.php`

- Implement exponential backoff after failed PIN attempts (1s, 2s, 4s, 8s...)
- Lock account after N failures (e.g., 5) for configurable duration
- Track failed attempts per employee_id AND per IP

#### Fix 2.9 — Add IP Restriction for Kiosk Devices (H8)

**Files:** Device schema + `class-attendance-rest.php`

- Add `allowed_ip_range` column to devices table
- Validate source IP against allowed range during punch
- Optional: allow CIDR notation for network ranges

#### Fix 2.10 — Validate Device Fingerprint on Punch (H9)

**File:** `class-attendance-rest.php`

- Compare submitted fingerprint against stored fingerprint during punch validation
- Flag or reject mismatches depending on policy setting

#### Fix 2.11 — Add Geofence Failure Source Column (H11)

**Files:** `AttendanceModule.php` (schema), `class-attendance-rest.php`

- Add `geo_fail_source ENUM('none','shift','device','both') DEFAULT 'none'` to punches
- Set appropriately during validation; display in admin punch detail

#### Fix 2.12 — Validate Offline Punch Against Shift Window (C8, S4)

**File:** `class-attendance-rest.php` — offline sync handler

- After staleness check, resolve employee's shift for the punch date
- Validate punch time falls within shift window ± buffer
- Reject punches outside reasonable window

---

### Phase 3 — Medium-Priority Fixes

#### Fix 3.1 — Fix Incomplete Session Lockout (C3)

**File:** `class-attendance-rest.php` — `snapshot_for_employee()`

- Add age check: if incomplete session is > 24h old, auto-close it or allow new session
- Alternatively, allow new session creation while old one is incomplete (different date)

#### Fix 3.2 — Fix Overtime Calculation Order (M2)

**File:** `AttendanceModule.php`

- Calculate `$ot = max(0, $net - $scheduled)` BEFORE rounding `$net`
- Round both `$net` and `$ot` independently

#### Fix 3.3 — Implement `overtime_after_minutes` Threshold (C9, F23)

**File:** `AttendanceModule.php` — OT calculation

- Read `$shift->overtime_after_minutes` during session recalc
- Change: `$ot = max(0, $excess - $ot_threshold)` where `$excess = $net - $scheduled`

#### Fix 3.4 — Fix Overnight Shift Timezone Comparisons (M3)

**File:** `AttendanceModule.php`

- Normalize all punch times and shift times to UTC before comparison
- Add explicit UTC conversion in overnight close-out logic

#### Fix 3.5 — Fix Early Leave Concurrent Approval (M1)

**File:** `class-early-leave-rest.php`

- Add MySQL named lock around approval + recalc sequence

#### Fix 3.6 — Allow `affects_salary` Control on Rejection (M13)

**File:** `class-early-leave-rest.php`

- Accept `affects_salary` parameter for both approve and reject actions
- Default to 0 for rejection

#### Fix 3.7 — Back-Link Early Leave to Session via Hook (M5)

- After any session created/updated, check for unlinked early leave requests for same employee + date
- Back-link by updating `early_leave_requests.session_id`

#### Fix 3.8 — Enforce Selfie in Offline Mode (M6)

- Require offline kiosk to capture and store selfie locally alongside punch data
- Validate selfie exists during sync; reject punches without selfie if policy requires it

#### Fix 3.9 — Extend Scan Token Expiry for Selfie Flow (M7)

- Increase token expiry window when selfie is required (e.g., 120 seconds instead of 30)
- Or: refresh token automatically after successful QR scan

#### Fix 3.10 — Add Push Notification for Roster Changes (M8)

- Implement server-sent events or polling endpoint for roster updates
- Kiosk checks for roster version changes on each sync attempt

#### Fix 3.11 — Allow Off-Day Overtime Punching (M9)

- Add `allow_offday_overtime` policy flag
- When set, allow punches on off-days; mark session as `overtime_offday`

#### Fix 3.12 — Batch Session Recalc for Offline Sync (M11)

**File:** `class-attendance-rest.php` — offline sync handler

- Collect all synced punches per employee+date
- Insert all punches first, then call `recalc_session_for()` once per employee+date

#### Fix 3.13 — Remove Policy Error Suppression (M12)

**File:** `Policy_Service.php`

- Remove `suppress_errors(true/false)` calls
- Add proper error logging

#### Fix 3.14 — Selfie Attachment Auto-Cleanup (F29)

**Files:** New `Selfie_Cleanup_Cron.php`, `AttendanceModule.php`

- Register daily WP cron: `sfs_hr_selfie_cleanup`
- Delete orphaned selfie attachments older than `selfie_retention_days`
- Delete physical files via `wp_delete_attachment($id, true)`
- Log cleanup results

#### Fix 3.15 — Block Punch When GPS Required but Unavailable (F15)

**File:** `class-attendance-rest.php`

- When `$enforce_geo` is true and `$lat`/`$lng` null, return `400` error

#### Fix 3.16 — Add SRI to CDN-Loaded Scripts (S5)

- Add `integrity` and `crossorigin` attributes to any CDN-loaded JS (jsQR, etc.)
- Or: bundle the library locally instead of using CDN

#### Fix 3.17 — Monitor IndexedDB Quota (S6)

- Check `navigator.storage.estimate()` before writing offline punch data
- Warn user if storage is near capacity; attempt cleanup of old synced data

#### Fix 3.18 — Add PIN Rotation Enforcement (S8)

- Add `pin_last_changed` column to employees
- Enforce PIN change after configurable period (e.g., 90 days)
- Prompt employee to change PIN on kiosk login if expired

#### Fix 3.19 — Respect Session Locked Flag (S9)

**File:** `AttendanceModule.php` — `recalc_session_for()`

- Check `$session->locked` before recalculating
- Skip recalc and log warning if session is locked

---

### Phase 4 — Low-Priority & Hardening

#### Fix 4.1 — Add Coordinate Validation on Device Creation (related to H8)

- Validate latitude (-90 to 90) and longitude (-180 to 180)
- Return `400` for out-of-range values

#### Fix 4.2 — Standardize API Error Responses (L8)

- Create shared error helper: `{ code, message, status, details }`
- Replace ad-hoc error returns across all REST endpoints

#### Fix 4.3 — Add Half-Day / Short-Day Status (L4)

- Add `half_day` and `short_day` to session status enum
- Set based on configured threshold (e.g., < 50% of scheduled = absent, 50-80% = short_day)

#### Fix 4.4 — Add Consecutive Absence Detection (L5)

- After daily session build, check for N+ consecutive absent days per employee
- Add `consecutive_absence` flag; optionally trigger notification

#### Fix 4.5 — Add Absence Reason Tracking (L6)

- Add `absence_reason ENUM('sick','personal','unauthorized','other')` to sessions
- Allow admin to set reason via UI

#### Fix 4.6 — Add Selfie Freshness Validation (L7)

- Check EXIF timestamp if available; reject selfies older than N minutes
- Consider basic liveness detection (blink, head turn) for high-security deployments

#### Fix 4.7 — Synchronize Client-Side Cooldown with Server (L2)

- Return remaining cooldown duration in punch response
- Client uses server-provided value instead of local timer

#### Fix 4.8 — Revoke Tokens on Device Deactivation (L3)

- On device deactivation, invalidate all associated tokens
- Add token revocation list or change device secret

#### Fix 4.9 — Add "End Break and Leave" Shortcut (L9)

- Add composite action that performs BREAK_END + OUT in single request
- Reduces kiosk taps from 2 to 1 for this common flow

#### Fix 4.10 — Add Policy Cache TTL (S7)

- Add timestamp to each cache entry; invalidate after 60 seconds
- Ensure cache cleared on all policy update paths

#### Fix 4.11 — Rate-Limit Admin Rebuild Endpoint (S3)

- Add cooldown (e.g., 1 rebuild per shift per 5 minutes) to prevent abuse

---

## Testing Recommendations

### Security & Authentication
- Brute-force PIN with automated tool; verify lockout after N attempts
- Test kiosk punch with cloned device token from different IP
- Verify device department restriction blocks cross-department punches
- Test QR code photo replay attack; verify detection/prevention
- Scan for rate limiting on punch endpoint under load

### Concurrent Access
- Load test with 100+ simultaneous punches for different employees
- Test two managers approving same early leave request simultaneously
- Run punch during `Daily_Session` cron execution; verify no data loss
- Test offline sync of 50+ punches; verify single recalc per employee+date

### Timezone
- Run full test suite with WordPress set to UTC+5, UTC-5, and UTC+12
- Verify auto-reject cron fires at correct times in all timezone configs
- Verify `strtotime()` calls produce correct UTC timestamps
- Test overnight shift close-out flags across timezone boundaries

### Overnight Shifts
- Test 23:00–02:00 shift across DST boundaries
- Test incomplete session close-out with next-day leading OUT punches
- Verify overtime calculated correctly for retroactively closed overnight sessions (H12)
- Verify punch attribution for shifts crossing midnight

### Offline & Kiosk
- Simulate IndexedDB clear during offline operation; verify data loss detection
- Test offline punch with timestamp outside shift window; verify rejection
- Add new employee while kiosk offline; verify roster refresh on reconnect
- Test selfie capture timeout with slow camera; verify token doesn't expire

### Data Integrity
- Delete an employee and verify all related records cascade correctly
- Delete a shift and verify assignments update appropriately
- Verify no orphaned selfie attachments remain after punch deletion
- Run cleanup cron; verify retention period respected

### Payroll Accuracy
- Verify overtime with `overtime_after_minutes` threshold set
- Verify OT calculation order (before vs after rounding)
- Verify break delay recorded in normal session flow
- Verify `affects_salary` on both approved and rejected early leaves
- Verify retroactive overnight sessions include overtime and break data
