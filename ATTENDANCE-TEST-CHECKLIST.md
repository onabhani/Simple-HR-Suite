# Attendance Module — Manual Testing Checklist

**Version:** Post v1.0 refactor (2026-03-10)
**Purpose:** Verify zero behavior change after extracting Widget_Shortcode, Kiosk_Shortcode, and Migration from AttendanceModule.php.

> Test on a staging/live site with at least 2 employees, 1 manager, 1 admin, 1 shift with geofence, and 1 kiosk device configured.

---

## 1. Widget Shortcode (`[sfs_hr_attendance_widget]`)

**Shortcode class:** `Frontend/Widget_Shortcode::render()`
**Capability required:** `sfs_hr_attendance_clock_self`

### Loading & Layout

- [ ] Page with `[sfs_hr_attendance_widget]` loads without PHP errors
- [ ] Immersive mode hides theme header/footer (default `immersive="1"`)
- [ ] Non-immersive mode (`immersive="0"`) renders inside theme layout
- [ ] Logged-out user sees nothing / login prompt (not a fatal error)
- [ ] User without `sfs_hr_attendance_clock_self` cap sees permission message

### Clock & Status Display

- [ ] Real-time clock displays correct local time
- [ ] Current status label updates after each punch (Not Clocked In → Clocked In → On Break → etc.)
- [ ] Work hours progress ring reflects accumulated minutes
- [ ] Today's punch history lists all punches for the day with correct times

### Punch In / Out

- [ ] "Clock In" button submits punch via `POST /sfs-hr/v1/attendance/punch`
- [ ] Flash success overlay appears after successful punch
- [ ] Status changes to "Clocked In" after punch-in
- [ ] "Clock Out" button appears after punch-in
- [ ] Clock-out records correct time, status changes back
- [ ] Punch history updates immediately after each punch

### Break Start / End

- [ ] "Start Break" button appears while clocked in (if shift has break policy)
- [ ] Break start records correctly, status shows "On Break"
- [ ] "End Break" button appears during break
- [ ] Break end records correctly, status returns to "Clocked In"

### Geofence

- [ ] "Locate me" button triggers browser geolocation prompt
- [ ] Map (Leaflet) shows employee position and shift geofence circle
- [ ] Punch blocked when outside geofence radius (if `geo_enforce_in` or `geo_enforce_out` set)
- [ ] Punch allowed when inside geofence radius
- [ ] Punch allowed when shift has no geofence configured
- [ ] `data-geo-lat`, `data-geo-lng`, `data-geo-radius` attributes rendered correctly in HTML

### Selfie Capture

- [ ] Selfie overlay triggers when dept/employee requires selfie
- [ ] Video feed starts, canvas captures frame on confirm
- [ ] Selfie uploads as WP attachment, `selfie_media_id` stored on punch
- [ ] Selfie NOT required when department setting is off
- [ ] Selfie mode respects precedence: device → employee → dept → default

### Navigation

- [ ] Profile back-link navigates correctly
- [ ] No broken JS console errors on the page

---

## 2. Kiosk Shortcode (`[sfs_hr_kiosk]`)

**Shortcode class:** `Frontend/Kiosk_Shortcode::render()`
**Capability required:** `sfs_hr_attendance_clock_kiosk` or `sfs_hr_attendance_admin`

### Loading & Layout

- [ ] Page with `[sfs_hr_kiosk]` loads without PHP errors
- [ ] `device="0"` auto-selects first active kiosk device
- [ ] `device="N"` selects specific device by ID
- [ ] Immersive mode hides theme chrome (default `immersive="1"`)
- [ ] System name, date, and time display correctly
- [ ] Camera status badge shows green when camera active
- [ ] Instructions/tips panel renders

### QR Scanning

- [ ] Camera feed starts and QR scanner initializes (jsQR)
- [ ] Scanning a valid employee QR code triggers punch
- [ ] Continuous scan mode processes multiple scans without manual tap
- [ ] Scan hint with pulse animation visible when idle
- [ ] Invalid QR code shows error feedback (not a crash)

### Action Lanes

- [ ] Clock In lane processes punch as `punch_type=in`
- [ ] Clock Out lane processes punch as `punch_type=out`
- [ ] Break Start / Break End buttons appear only when `break_enabled=1` on device
- [ ] Lane switching works correctly between scans

### Live Log & Feedback

- [ ] Recent scans log updates after each scan with employee name + action + time
- [ ] Flash overlay shows success message per scan
- [ ] Mobile slide-up modal works for recent scans on small screens
- [ ] Session summary modal shows employee count + total duration

### Geofence (Kiosk)

- [ ] Device geofence enforced on both in and out punches
- [ ] `data-geo-lat`, `data-geo-lng`, `data-geo-radius` from device config rendered correctly
- [ ] Punch blocked when device location not within geofence

### Manager PIN

- [ ] PIN verification via `POST /sfs-hr/v1/attendance/verify-pin` works
- [ ] Invalid PIN rejected with error message
- [ ] Valid PIN grants access to kiosk operations

### Offline Mode

- [ ] Kiosk with `kiosk_offline=1` mints scan tokens via `GET /sfs-hr/v1/attendance/scan`
- [ ] Roster loads via `GET /sfs-hr/v1/attendance/kiosk-roster`
- [ ] Offline punches sync when connectivity restored (`offline_origin` field populated)

---

## 3. Migration & Table Creation

**Class:** `Migration::run()`
**Trigger:** `admin_init` → `maybe_install()` (self-healing)

### Fresh Install

- [ ] Activate plugin on a site with no `sfs_hr_attendance_*` tables
- [ ] All 10 tables created:
  - [ ] `sfs_hr_attendance_punches`
  - [ ] `sfs_hr_attendance_sessions`
  - [ ] `sfs_hr_attendance_shifts`
  - [ ] `sfs_hr_attendance_shift_assign`
  - [ ] `sfs_hr_attendance_emp_shifts`
  - [ ] `sfs_hr_attendance_shift_schedules`
  - [ ] `sfs_hr_attendance_devices`
  - [ ] `sfs_hr_attendance_flags`
  - [ ] `sfs_hr_attendance_audit`
  - [ ] `sfs_hr_early_leave_requests`
- [ ] Foreign keys created (check `SHOW CREATE TABLE` for each)
- [ ] Capabilities registered on relevant roles (`sfs_hr_attendance_clock_self`, etc.)
- [ ] Placeholder kiosk device seeded (if applicable)

### Upgrade (Existing Install)

- [ ] Deactivate and reactivate plugin — no errors, no duplicate columns
- [ ] `add_column_if_missing()` calls are idempotent (run migration twice — no SQL errors)
- [ ] `sfs_hr_db_ver` option updated to current version
- [ ] New columns added on upgrade:
  - [ ] `punches.offline_origin`
  - [ ] `sessions.early_leave_approved`, `sessions.early_leave_request_id`
  - [ ] `sessions.break_delay_minutes`, `sessions.no_break_taken`
  - [ ] `shifts.break_start_time`, `shifts.dept_ids`, `shifts.period_overrides`
  - [ ] `early_leave_requests.request_number`

### Data Safety

- [ ] Existing punch/session data preserved after migration re-run
- [ ] No data loss on plugin deactivation → reactivation cycle

---

## 4. REST API Endpoints

### Public / Employee Endpoints

| # | Endpoint | Method | Test |
|---|----------|--------|------|
| | **Status** | | |
| 4.1 | `/sfs-hr/v1/attendance/status` | GET | |
| - [ ] | Returns current punch status for logged-in employee | | |
| - [ ] | Returns limited data for unauthenticated request | | |
| | **Punch** | | |
| 4.2 | `/sfs-hr/v1/attendance/punch` | POST | |
| - [ ] | Punch-in with valid `punch_type=in` succeeds | | |
| - [ ] | Punch-out with `punch_type=out` succeeds | | |
| - [ ] | Break start/end with `punch_type=break_start/break_end` succeeds | | |
| - [ ] | Geolocation fields (`geo_lat`, `geo_lng`, `geo_accuracy_m`) stored correctly | | |
| - [ ] | Selfie data URL or media ID attached to punch | | |
| - [ ] | Offline punch with `client_punch_time` and `offline_origin` fields stored | | |
| - [ ] | Unauthorized user rejected (403) | | |
| | **Scan Token** | | |
| 4.3 | `/sfs-hr/v1/attendance/scan` | GET | |
| - [ ] | Returns short-lived token for offline kiosk use | | |
| - [ ] | Requires kiosk/admin capability | | |
| | **Kiosk Roster** | | |
| 4.4 | `/sfs-hr/v1/attendance/kiosk-roster` | GET | |
| - [ ] | Returns employee list for specified device | | |
| - [ ] | Respects device department restrictions | | |
| | **PIN Verify** | | |
| 4.5 | `/sfs-hr/v1/attendance/verify-pin` | POST | |
| - [ ] | Valid PIN returns success | | |
| - [ ] | Invalid PIN returns error | | |

### Admin Endpoints

| # | Endpoint | Method | Test |
|---|----------|--------|------|
| | **Shifts** | | |
| 4.6 | `/sfs-hr/v1/attendance/shifts` | GET | |
| - [ ] | Lists all shifts, filterable by `dept` and `active` | | |
| 4.7 | `/sfs-hr/v1/attendance/shifts` | POST | |
| - [ ] | Creates new shift with all fields | | |
| - [ ] | Updates existing shift by ID | | |
| 4.8 | `/sfs-hr/v1/attendance/shifts/{id}` | DELETE | |
| - [ ] | Deletes shift, cascades to assignments | | |
| | **Assignments** | | |
| 4.9 | `/sfs-hr/v1/attendance/assign` | GET | |
| - [ ] | Lists assignments for a specific date | | |
| 4.10 | `/sfs-hr/v1/attendance/assign` | POST | |
| - [ ] | Bulk assigns shifts to employees for date range | | |
| | **Devices** | | |
| 4.11 | `/sfs-hr/v1/attendance/devices` | GET | |
| - [ ] | Lists all kiosk devices | | |
| 4.12 | `/sfs-hr/v1/attendance/devices` | POST | |
| - [ ] | Creates/updates device with geofence and selfie settings | | |
| 4.13 | `/sfs-hr/v1/attendance/devices/{id}` | DELETE | |
| - [ ] | Deletes device | | |
| | **Punch Admin** | | |
| 4.14 | `/sfs-hr/v1/attendance/punches/admin-create` | POST | |
| - [ ] | Admin creates punch for employee on past date | | |
| - [ ] | Session recalculated after manual punch | | |
| 4.15 | `/sfs-hr/v1/attendance/punches/{id}/admin-edit` | PUT | |
| - [ ] | Edits punch time/type, session recalculated | | |
| - [ ] | Audit log entry created | | |
| 4.16 | `/sfs-hr/v1/attendance/punches/{id}/admin-delete` | DELETE | |
| - [ ] | Deletes punch, session recalculated | | |
| - [ ] | Audit log entry created | | |
| | **Session Rebuild** | | |
| 4.17 | `/sfs-hr/v1/attendance/sessions/rebuild` | POST | |
| - [ ] | Rebuilds sessions for specified date and employee | | |

### Early Leave Endpoints

| # | Endpoint | Method | Test |
|---|----------|--------|------|
| 4.18 | `/sfs-hr/v1/early-leave/request` | POST | |
| - [ ] | Employee creates ELR with reason_type and reason_note | | |
| - [ ] | Auto-generated `request_number` (EL-YYYY-NNNN) | | |
| 4.19 | `/sfs-hr/v1/early-leave/my-requests` | GET | |
| - [ ] | Returns only current employee's ELRs | | |
| 4.20 | `/sfs-hr/v1/early-leave/cancel/{id}` | POST | |
| - [ ] | Employee cancels own pending ELR | | |
| - [ ] | Cannot cancel already-approved/rejected ELR | | |
| 4.21 | `/sfs-hr/v1/early-leave/pending` | GET | |
| - [ ] | Manager sees pending ELRs for their department | | |
| 4.22 | `/sfs-hr/v1/early-leave/review/{id}` | POST | |
| - [ ] | Manager approves ELR — status changes, `reviewed_by` set | | |
| - [ ] | Manager rejects ELR — status changes, `manager_note` stored | | |
| - [ ] | `affects_salary` field respected per setting | | |
| 4.23 | `/sfs-hr/v1/early-leave/list` | GET | |
| - [ ] | Admin lists all ELRs across departments | | |

---

## 5. Admin Pages

**Menu:** HR → Attendance (`admin.php?page=sfs_hr_attendance`)

### Settings Tab

- [ ] Page loads without errors
- [ ] Department punch settings (web punch + selfie per dept) save correctly
- [ ] Selfie retention days saves and persists on reload
- [ ] Rounding rule dropdown saves (none/5/10/15)
- [ ] Grace period fields (late + early) save numeric values
- [ ] Monthly OT threshold saves (in minutes)
- [ ] No-break penalty toggle saves
- [ ] Period type toggle (full_month ↔ custom) saves
- [ ] Period start day field appears/hides based on period type
- [ ] ELR settings (enable, auto-create, require note, salary impact, max/month, auto-reject days) save

### Shifts Tab

- [ ] Shift list loads with correct data
- [ ] Create shift: name, times, break policy, location (lat/lng/radius), grace periods
- [ ] Edit shift: all fields update correctly
- [ ] Delete shift: confirmation prompt, cascade deletes assignments
- [ ] Weekly overrides: per-day start/end times save and display
- [ ] Period overrides: date-range overrides (e.g., Ramadan schedule) save

### Assignments Tab

- [ ] Assignment grid loads for selected date
- [ ] Bulk assign shifts to multiple employees
- [ ] Override JSON for special day configs accepted
- [ ] Assignments visible in shift resolution for subsequent punches

### Devices Tab

- [ ] Device list loads
- [ ] Create device: label, type, geofence coords, selfie mode, PIN, offline toggle, break enabled
- [ ] Edit device: all fields update
- [ ] Delete device: confirmation prompt
- [ ] `meta_json` custom settings save as valid JSON

### Punches Tab

- [ ] Punch list loads with filters (date range, employee, type)
- [ ] Admin create punch: past date, specific employee, punch type
- [ ] Admin edit punch: time correction triggers session recalc
- [ ] Admin delete punch: triggers session recalc
- [ ] Audit trail visible for edited/deleted punches

### Sessions Tab

- [ ] Session list loads with filters (date range, employee, department)
- [ ] Session details show: in/out times, break, net/rounded minutes, OT, status flags
- [ ] Rebuild sessions for single date works
- [ ] Rebuild sessions for date range works
- [ ] Fix off-day absences action works

### Automation Tab

- [ ] Department automation rules load and save
- [ ] Shift templates: create, edit, delete, apply to departments
- [ ] Auto-assign rules: save, run manually, delete
- [ ] Shift schedules (rotation patterns): create, edit, delete

### Exceptions Tab

- [ ] Exception/flag list loads
- [ ] Flags show: late, early-leave, no-break, break-delay
- [ ] Manager comment field on flags

### CSV Export

- [ ] Export attendance CSV downloads file
- [ ] Export punches CSV downloads file
- [ ] Date range filter applied to export
- [ ] Employee/department filter applied to export

---

## 6. Cron Jobs

### Daily Session Builder (`sfs_hr_daily_session_build`)

- [ ] Cron event registered: `wp cron event list | grep sfs_hr_daily_session_build`
- [ ] Scheduled as `twicedaily`
- [ ] Builds sessions for yesterday + today for all active employees
- [ ] Lock mechanism prevents concurrent runs (`sfs_hr_session_build_running` option)
- [ ] Fallback runs on `shutdown` if WP cron missed (check 6-hour transient)
- [ ] No duplicate sessions created on repeated runs

### Early Leave Auto-Reject (`sfs_hr_early_leave_auto_reject`)

- [ ] Cron event registered as `twicedaily`
- [ ] Rejects pending ELRs older than configured days (default: 3)
- [ ] Sets `status=rejected`, `manager_note` to auto-rejection reason
- [ ] `affects_salary` set per settings

### Selfie Cleanup (`sfs_hr_selfie_cleanup`)

- [ ] Cron event registered as `daily`
- [ ] Deletes WP attachments older than `selfie_retention_days`
- [ ] Clears `selfie_media_id` on related punch records
- [ ] Processes in batches of 100
- [ ] Physical files removed from uploads directory

---

## 7. Session Recalculation Engine

**Class:** `Session_Service::recalc_session_for()`

### Core Calculations

- [ ] Net minutes = total work time minus break time
- [ ] Rounding applied per shift/global setting (none/5/10/15 min)
- [ ] Overtime calculated when net minutes exceed shift threshold or global `monthly_ot_threshold`
- [ ] Break minutes deducted correctly from net time

### Flag Detection

- [ ] **Late arrival**: flagged when punch-in is after shift start + grace period
- [ ] **Early leave**: flagged when punch-out is before shift end - grace period
- [ ] **No break taken**: flagged when shift requires break but none recorded
- [ ] **Break delay**: flagged when break started later than configured window

### Leave & Holiday Integration

- [ ] Session skipped (or marked as leave) when employee is on approved leave
- [ ] Session skipped when date is a company holiday
- [ ] `sfs_hr_attendance_is_leave_or_holiday` filter respected

### Edge Cases

- [ ] Multiple punch-in/out pairs in a single day handled correctly
- [ ] Missing punch-out does not crash recalc (partial session created)
- [ ] Locked sessions (`locked=1`) not overwritten unless `force=true`
- [ ] Concurrent recalc prevented by MySQL `GET_LOCK()` per employee+date
- [ ] Deferred recalc via `sfs_hr_deferred_recalc` fires when lock fails

### Shift Resolution Cascade

- [ ] Date-specific assignment takes priority
- [ ] Employee default shift used as fallback
- [ ] Department automation rules used next
- [ ] Department fallback shift used last
- [ ] Weekly overrides applied on correct day-of-week
- [ ] Period overrides take precedence over weekly overrides

---

## 8. Hooks & Cross-Module Integration

### Actions Fired (verify other modules receive these)

- [ ] `sfs_hr_attendance_late` — fires on late arrival detection
- [ ] `sfs_hr_attendance_early_leave` — fires on early departure detection
- [ ] `sfs_hr_attendance_no_break_taken` — fires when break not recorded
- [ ] `sfs_hr_attendance_break_delay` — fires when break started late
- [ ] `sfs_hr_early_leave_requested` — fires when ELR created (auto or manual)
- [ ] `sfs_hr_early_leave_status_changed` — fires on ELR status transition
- [ ] `sfs_hr_early_leave_reviewed` — fires when manager reviews ELR

### Filters (verify customizations still work)

- [ ] `sfs_hr_attendance_is_leave_or_holiday` — third-party overrides respected

### Auto-Actions

- [ ] `sfs_hr_employee_created` → auto-assigns default shift to new employee

---

## 9. Capabilities & Access Control

| Capability | Who Has It | What It Grants |
|------------|-----------|----------------|
| `sfs_hr_attendance_clock_self` | Employees | Punch in/out via widget |
| `sfs_hr_attendance_view_self` | Employees | View own attendance records |
| `sfs_hr_attendance_clock_kiosk` | Kiosk operators | Use kiosk terminal |
| `sfs_hr_attendance_view_team` | Managers | View team attendance |
| `sfs_hr_attendance_edit_team` | Managers | Edit team records |
| `sfs_hr_attendance_admin` | HR Admins | Full admin access |
| `sfs_hr_attendance_edit_devices` | HR Admins | Manage kiosk devices |

- [ ] Employee without cap cannot access admin attendance page
- [ ] Employee without `clock_self` cannot punch via widget
- [ ] Manager can view team but not edit without `edit_team`
- [ ] Admin endpoints reject requests without `attendance_admin`
- [ ] Kiosk rejects access without `clock_kiosk` or `attendance_admin`

---

## 10. Quick Smoke Test (5-Minute Check)

Run this first — if any step fails, stop and investigate before continuing.

1. [ ] Visit admin Attendance page — loads without white screen or PHP errors
2. [ ] Visit a page with `[sfs_hr_attendance_widget]` as an employee — widget renders
3. [ ] Punch in via widget — success flash shown, punch recorded in DB
4. [ ] Punch out via widget — session calculated correctly in sessions table
5. [ ] Visit a page with `[sfs_hr_kiosk]` as admin — kiosk renders, camera prompt appears
6. [ ] Deactivate & reactivate plugin — no migration errors in `error_log`
7. [ ] Check `wp cron event list` — all 3 cron events registered

---

## Test Environment Notes

**Tested on:**
- Site URL: ___________________________
- WordPress version: ___________________________
- PHP version: ___________________________
- Plugin version: ___________________________
- Date tested: ___________________________
- Tested by: ___________________________

**Test accounts used:**
- Admin: ___________________________
- Manager: ___________________________
- Employee 1: ___________________________
- Employee 2: ___________________________

**Result:** [ ] PASS — All checks passed / [ ] FAIL — Issues found (see notes below)

### Issues Found

| # | Section | Item | Description | Severity |
|---|---------|------|-------------|----------|
| | | | | |
| | | | | |
| | | | | |
