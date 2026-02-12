# Changelog

All notable changes to Simple HR Suite will be documented in this file.

## [0.5.0] — 2026-02-12

### Fixed
- **Break selfie enforcement**: shift-level `require_selfie` now always upgrades selfie
  mode to `all`, regardless of the current policy mode (previously only upgraded `never`
  and `optional`, leaving `in_only`/`in_out` unchanged — which excluded breaks).

### Added
- **Punch success feedback**: self-service widget now plays a short tone and shows a
  color-coded fullscreen flash on successful punch (green=in, red=out, amber=break start,
  blue=break end), matching the kiosk experience.

### Improved
- **Faster punch flow**: skip pre-punch status refresh if last refresh was within 10s;
  cache GPS coordinates from geofence pre-flight check (avoids duplicate GPS request);
  post-punch status refresh is now non-blocking (UI responds immediately).

## [0.4.9] — 2026-02-12

### Fixed
- **Shift save: start_time/end_time no longer required when weekly schedule provides
  per-day times.** Creating a shift with per-day overrides but empty main times was
  rejected with "Missing required fields: start_time, end_time." Fixed in both the
  admin form handler and the REST API endpoint.

## [0.4.8] — 2026-02-12

### Fixed
- **Break selfies not captured**: when a shift has `require_selfie` enabled and the
  default selfie policy is `optional` or `never`, the mode now upgrades to `all`
  (every punch type) instead of `in_out` (which excluded break_start/break_end).
- **Rapid break cycling**: added a 15-second cross-type cooldown between any two
  consecutive punches (in addition to the existing 30-second same-type cooldown).
  Prevents accidental break_start↔break_end loops that created noise in the log.

## [0.4.7] — 2026-02-12

### Fixed
- **Untranslated profile strings**: employee status (`active`/`inactive`/`terminated`) and
  gender (`male`/`female`) values are now translated instead of showing raw DB values.
- **Notification dot in My Documents**: red dot was stretched into an ellipse by the parent
  flex container; now properly constrained to 8×8px circle.
- **Mobile bottom tab bar**: removed phantom 70px right padding reserved for a non-existent
  notification bell element.
- **Card spacing on mobile**: fixed unequal gaps between profile info cards (Contact vs
  Identification) caused by `:last-child` margin reset within column wrappers.

## [0.4.6] — 2026-02-12

### Fixed
- **PWA loading on unrelated pages**: manifest meta tags and service worker registration
  were firing on every admin page for logged-in users. Now restricted to only the
  My Profile admin page and frontend pages containing HR shortcodes.

## [0.4.5] — 2026-02-12

### Added
- **Kiosk offline punch queueing** (§9.1): when a kiosk device has "Allow offline" enabled
  and the network is down, punches are stored in IndexedDB and automatically synced via
  Background Sync when connection is restored. Notification shown on successful sync.
- **Shortcodes reference page** (§7.1): new "Shortcodes" tab in Settings lists all 8
  available shortcodes with descriptions, parameters, capability requirements, and
  one-click copy buttons.

### Improved
- **Service worker v2**: removed broken `/offline.html` precache reference, skip caching
  `/wp-admin/` pages, improved sync with fresh nonce retrieval from open client windows,
  handle 409 duplicates during offline sync gracefully.
- **PWA app script**: responds to service worker nonce requests for reliable offline sync,
  removed notification permission auto-prompt, cleaner initialization.
- **Chart.js** loaded with `defer` attribute in admin dashboard for faster page rendering.

## [0.4.4] — 2026-02-12

### Improved
- **Selfie capture UI redesign**: replaced the inline camera panel with a fullscreen dark
  overlay that centres the viewfinder on screen. Users no longer need to scroll down to
  reach the camera or scroll back up to see status.
- The overlay shows the current action label (e.g. "Clock Out — Ready") at the top, and
  mirrors "Working…" status inside the overlay so progress is always visible.
- Capture and Cancel buttons now use the app's design language (rounded, properly sized).
- Body scroll is locked while the overlay is open; restored on close/cancel.
- On error during punch, the overlay auto-closes so the main status message is visible.

## [0.4.3] — 2026-02-12

### Fixed
- **Shift re-assignment**: changing an employee's shift back to one previously used on the
  same date now works. The duplicate check now only skips if the _latest_ assignment for
  that date is already the same shift (not any historical entry).
- **Duplicate selfie uploads**: tapping the capture button multiple times on mobile no
  longer creates multiple selfie uploads per punch. `pendingType` is cleared synchronously
  and the capture button is disabled immediately on first tap.

## [0.4.2] — 2026-02-12

### Fixed
- **Method policy check before camera/geo**: when an employee's policy only allows kiosk,
  the self-web UI now shows "Clock-in via Self Web is not allowed by your attendance policy"
  immediately — instead of opening the camera or showing a misleading geofence error.
- **Cooldown check before camera**: same-type cooldown is now checked client-side before
  opening the selfie camera, avoiding wasted photo captures.
- Status endpoint now returns `method_blocked` (per punch type) and `cooldown_type`/
  `cooldown_seconds` so the client can pre-validate before any heavy work.

## [0.4.1] — 2026-02-12

### Fixed
- **Punch cooldown**: replaced aggressive 5-minute blanket cooldown (blocked ALL punch
  types) with a 30-second same-type cooldown. Cross-type transitions (clock-in → clock-out)
  are now allowed immediately — the state-machine already validates transition legality.
- Error messages from failed selfie punches now scroll into view on mobile (prevents
  invisible errors when camera panel collapses).

## [0.4.0] — 2026-02-12

### Fixed
- **Critical recalc bug**: `total_hours` shifts without start/end times were incorrectly
  marked as `day_off` (empty segments short-circuited status rollup). Total-hours mode is
  now evaluated first, before the empty-segments fallback.
- **Selfie mode `in_only`**: server-side and client-side now correctly require selfie only
  for clock-in (not all punch types). Mode `in_out` → in+out, `all` → all types.
- **Punch response** now includes `shift_requires` in selfie mode calculation, matching
  the status endpoint logic.
- Client-side selfie decision uses per-punch-type check (`needsSelfieForType`) instead of
  global boolean.

## [0.3.9] — 2026-02-12

### Removed
- **Policies tab** removed from Attendance admin — all policy configuration now lives
  directly on shifts. Existing role-based policy data remains in DB as silent fallback.

### Changed
- Location and start/end time fields are now optional for `total_hours` mode shifts
  (HTML `required` removed, server-side validation relaxed).

## [0.3.8] — 2026-02-11

### Added
- **Shift-level attendance policies** (P1 §1.1): shifts now carry optional policy fields
  (`calculation_mode`, `target_hours`, `clock_in_methods`, `clock_out_methods`,
  `geofence_in`, `geofence_out`). When set, these override role-based policies for
  employees on that shift. When NULL (default), the existing role-based policy lookup
  continues to apply — fully backward compatible.
- **Per-day weekly schedule**: the Weekly Overrides section on shifts now supports
  per-day start/end time overrides and day-off marks instead of pointing to other shifts.
  Old integer-based overrides (shift ID) continue to work for backward compatibility.
- New `Policy_Service::resolve_effective_policy()` method — two-tier policy resolution:
  shift-level fields first, then role-based fallback per-field.

### Changed
- Shift admin form reorganised: weekly schedule uses inline time inputs + day-off
  checkboxes; new "Attendance Policy" section with calculation mode, methods, and
  geofence overrides.
- Shifts list table now shows "Mode" column (Total Hours / Shift Times / Default).
- All `Policy_Service` helper methods now accept optional `$shift` parameter for
  shift-aware resolution.
- REST punch endpoint reordered: shift is resolved before policy validation so
  shift-level policy fields take effect.
- Kiosk geofence check now uses shift-level policy when available.
- Session recalculation passes resolved shift to all Policy_Service calls.

## [0.3.7] — 2026-02-11

### Changed
- **Employee Profile redesign**: modern hero header with photo/initials avatar, status badges,
  and action buttons replacing the duplicate heading
- Overview tab reorganised into categorised cards (Personal, Contact, Job & Contract,
  Payroll, Documents, Driving License) using a responsive CSS Grid layout
- Quick-stats row shows today's attendance, leave status, present/late/absent day counts,
  and commitment percentage with grade (from Performance module)
- "Reports To" section redesigned with larger avatar, linked manager name and position
- Edit mode fields grouped into matching card categories with a cleaner grid form layout

### Added
- "Create WordPress User" button on profile header when no WP account is linked
  (generates username from first.last, assigns subscriber role)
- Loans tab on admin Employee Profile now shows the employee's loans with summary
  cards and links to admin loan detail pages (previously opened self-service "My Loans")

### Fixed
- Loans tab URL on admin Employee Profile now routes to the correct employee context
  instead of redirecting to the logged-in user's self-service page

## [0.3.6] — 2026-02-11

### Changed
- **Merged admin employee pages** (P1 §5.1): the old "Edit Employee" page now redirects
  to the unified Employee Profile page in edit mode
- Employee Profile edit mode expanded from ~15 to 35+ fields: Arabic names, nationality,
  marital status, work location, contract dates, GOSI salary, visa/sponsor, driving license,
  shift assignment with history, and QR code management
- Employee Profile view mode enriched with the same fields (job & contract, documents,
  driving license sections)
- QR regen/toggle and save redirects now return to the Profile page
- Success notices (updated, QR regen, QR toggle) displayed on Profile page

## [0.3.5] — 2026-02-11

### Added
- Configurable attendance period in Attendance Settings (full calendar month or custom start day)
- `AttendanceModule::get_current_period()` helper used across all modules
- All date range defaults (dashboards, reports, alerts, CSV exports, frontend widgets)
  now respect the configured attendance period instead of hardcoded calendar month

## [0.3.4] — 2026-02-10

### Fixed
- Duplicate leave requests: self-service handler now checks for overlapping pending/approved requests
  before inserting (matching the validation already present in the shortcode handler)
- Leave error/success flash messages now display on both admin My Profile and frontend leave tabs
  (previously redirect query params were set but never read/displayed)

## [0.3.3] — 2026-02-10

### Fixed
- XSS: escape all dynamic values in innerHTML across Resignation, Leave, and Loans modules
  (added sfsEsc() HTML entity encoder for dataset/AJAX values injected into modal markup)
- Settlement service: replaced NOT IN subquery with LEFT JOIN + IS NULL for better query performance

### Added
- Automated performance index creation in migration (employees, punches, sessions, shifts,
  audit trail, early leave, loans, loan payments — 20+ indexes added idempotently)
- Object cache (5 min TTL) for audit trail DISTINCT entity_type/action filter queries

## [0.3.2] — 2026-02-10

### Fixed
- Email button layout artifact (dark square) in absence notification emails
- send_mail() now skips wpautop() for pre-formatted HTML content

### Changed
- Weekly performance digest email redesigned from plain text to styled HTML table
  with color-coded commitment percentages and severity-badged alerts section
- All performance report emails (weekly + monthly) now use the HTML format
- Email buttons use single-line markup to prevent wpautop interference

## [0.3.1] — 2026-02-10

### Fixed
- Policies table no longer requires horizontal scrolling (fixed-layout columns)
- Early leave filter form page slug corrected (was breaking after date filter)
- REST URL escaping fixed in early leave review script
- Auto-rejected requests now display reason in status and reviewed-by columns

### Changed
- Auto-reject cron note updated to "no action was taken within 3 days"
- Reviewed-by column shows "System" with reason for auto-rejected requests

## [0.3.0] — 2026-02-10

### Fixed
- False early leave requests when employee clocks out on time or after shift end (0-minute flags)
- Total-hours mode incorrectly using irrelevant shift end time for early leave detection (e.g. 292-minute false positives)
- Segment-level rounding producing spurious left_early flags in total-hours mode

### Added
- Enhancement roadmap with 13 tracked improvement tasks (ENHANCEMENTS.md)
- GitHub issue templates for all planned enhancements (.github/issues/)
- Early leave request permissions: Admin sees all, department managers see only their department
- Only GM or department manager can approve/reject early leave requests
- 72-hour auto-reject cron for unactioned early leave requests (Early_Leave_Auto_Reject)
- Early leave requests card on HR dashboard (scoped by department)
- One-time cleanup migration to remove false early leave records created by pre-fix code
- GitHub Updater headers for automatic plugin updates from GitHub

### Changed
- Early leave review modal replaced with slide-up bottom sheet for consistency
- Attendance policies tab made responsive (breakpoints at 1024px and 782px)
- Early leave requests page made responsive with card-based mobile layout
- Total-hours mode now shows hours shortfall instead of shift-time difference

## [0.2.0] — Previous release

Initial tracked release.
