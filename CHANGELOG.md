# Changelog

All notable changes to Simple HR Suite will be documented in this file.

## [1.8.2] — 2026-03-05

### Fixed
- **Leave attachment not displayed in admin detail page** — the uploaded document
  (`doc_attachment_id`) is now shown as a clickable link in the Leave Information
  card on the admin leave detail page.
- **Frontend leave history attachment restricted to sick leave** — removed the
  `stripos($type_name, 'sick')` check so uploaded documents are visible for all
  leave types, not just sick leave.
- **Missing translations in Filipino and Urdu** — synced 39 missing keys
  (sick leave reminders, loan validation strings) to `fil.json` and `ur.json`.

## [1.7.3] — 2026-03-03

### Fixed
- **Leave gender filtering missing** — leave type dropdowns, balance cards, and
  request forms now filter by the `gender_required` setting on each leave type.
  Previously only MATERNITY was hidden by special_code; leave types configured as
  "Male Only" or "Female Only" were still shown to all employees.
- **Leave attachment requirement ignored** — the `requires_attachment` flag on
  leave types is now enforced during submission. Previously only hardcoded
  SICK_SHORT/SICK_LONG special codes required a document; custom leave types with
  the checkbox enabled could be submitted without any file. Also added the missing
  file upload field to the admin self-service leave form.

## [1.7.2] — 2026-03-03

### Fixed
- **Payslip "View" button non-functional** — replaced placeholder button with a
  working detail page showing employee info, earnings/deductions breakdown, bank
  snapshot, and PDF download link.
- **Payslip bank data stale** — payslip detail query now uses
  `COALESCE(payroll_item.bank_name, employee.bank_name)` so the payroll-run
  snapshot is preferred over current employee data.
- **Loan calculator max months hardcoded to 60** — language files now use `%d`
  placeholder replaced at runtime with the configured maximum.
- **Policy cache key conflation** — `resolve_effective_policy()` cache key now
  distinguishes null shift, shift without id, and shift with id (prevents
  incorrect cache reuse and PHP 8 null property access deprecation).
- **Payroll `emp_number` key mismatch** — renamed to `employee_code` to match
  the actual data source.
- **Filipino translation** — `exceeds_inst` string reworded to natural phrasing.

### Improved
- **Employee list action menu** — added three-dot menu with View, Edit, and
  Delete actions on both desktop table rows and mobile cards.
- **EmployeesTab code quality** — extracted `get_employee_urls()` helper,
  moved inline CSS/JS into `render_assets_once()` with static guard, namespaced
  global `sfsToggleEmpMenu` as `sfsHR.toggleEmpMenu`.
- **Loan calculator JS scoping** — wrapped in IIFE to prevent global scope
  pollution.
- **Payslip PDF link** — added `rel="noopener noreferrer"` to `target="_blank"`
  download link.
- **Loan warnings** — installment-exceeds-salary warning moved below months
  warning with warning prefix on all amount warnings.

## [1.7.1] — 2026-03-03

### Improved
- **Punch endpoint performance** — eliminated redundant DB queries per punch
  (~30–40% fewer queries, ~130–160 ms vs ~200 ms per punch):
  - Reuse already-resolved shift and selfie mode for same-day post-punch
    response instead of re-querying (saves 4–9 queries per punch).
  - Remove duplicate `update_post_meta` writes for selfie attachments
    (saves 2 queries per selfie punch).
  - Add per-request cache to `Policy_Service::resolve_effective_policy`
    so the 3 validation calls during a punch share one merged policy object.
  - Remove dead `$resp_extra` code.

### Added
- **Future Ideas section** in ENHANCEMENTS.md (Section 12) — selfie face
  detection / recognition roadmap for future consideration.

## [1.6.4] — 2026-02-28

### Fixed
- **CSV export recalc permission** — the on-demand rebuild now requires
  `sfs_hr_attendance_admin` (previously `sfs_hr_attendance_view_team` allowed
  view-only users to trigger writes) and is capped at 31 days per request.
- **Previous-day early leave session linkage** — when suppressing `left_early`
  for an approved overnight early leave request, the session row now persists
  `early_leave_approved = 1` and `early_leave_request_id`.
- **Early leave review DB error handling** — `$wpdb->update()` returning
  `false` (DB error) is now distinguished from `0` (race condition / already
  reviewed) with separate error responses.
- **Projects: `update()` false positive** — `Projects_Service::update()` no
  longer treats a 0-rows-changed result as failure (was `(bool)` cast, now
  `!== false`).
- **Projects: `add_shift` race condition** — the clear-default + insert is now
  wrapped in a DB transaction so failures roll back atomically.
- **Projects: ownership checks on delete** — removing an employee assignment or
  shift link now verifies the record belongs to the posted project ID before
  deleting.
- **Projects: success flag honesty** — handler redirects now only include
  success query flags when the underlying write actually succeeded.
- **Projects: date interval validation** — assigning an employee now rejects
  `assigned_to` earlier than `assigned_from` server-side.
- **Projects: dashboard date validation** — `dash_from`/`dash_to` are now
  validated as Y-m-d with start <= end enforcement and safe fallbacks.

### Improved
- **Projects: N+1 query elimination** — project list page now fetches employee
  counts in a single grouped query via `get_employee_counts()`.
- **Projects: `information_schema` cache** — `get_employee_project_on_date()`
  caches the table-existence check in a static variable.
- **ProjectsModule singleton** — added private constructor/clone/wakeup and
  switched `hr-suite.php` to use `::instance()`.
- **Attendance_Metrics option cache** — `get_option(OPT_SETTINGS)` is now
  cached in a static variable to avoid repeated DB lookups per employee.
- **Daily_Session_Builder require** — moved `require_once` from `hooks()` to
  file scope for consistency with other submodule includes.

## [1.6.3] — 2026-02-28

### Fixed
- **Early leave approval now suppresses `left_early` session status** —
  `recalc_session_for()` checks the early leave requests table for approved
  requests and changes status to `present` with an `early_leave` flag instead
  of penalizing the employee. Previously the `early_leave_approved` session
  flag was set but never read.
- **Race condition on early leave review** — the UPDATE now atomically checks
  `WHERE status = 'pending'`, preventing two managers from approving the same
  request simultaneously.
- **Approval with missing session link** — when a request's `session_id` is
  NULL at approval time (session created after request), the review endpoint
  now looks up and back-links the session before recalculating.
- **Auto-created early leave requests** now fire the
  `sfs_hr_early_leave_requested` hook for notification consistency with
  manually submitted requests.

## [1.6.1] — 2026-02-26

### Changed
- Version bump from 1.6.0 to 1.6.1.

## [1.5.9] — 2026-02-26

### Improved
- **CSV import status notices** — the import handler now shows dismissible
  WordPress admin notices (success/warning/error) with detailed counts of
  created, updated, and skipped rows. Previously, import results were silent.

## [1.5.8] — 2026-02-26

### Fixed
- **CSV import fallback upsert** now enforces the `can_terminate_employee` guard
  before setting status to 'terminated', matching the primary update path.
- **CSV import update paths** now check the return value of `$wpdb->update()`;
  a `false` return (DB error) no longer incorrectly increments the updated count.
- **Early-leave auto-creation** during attendance recalculation now checks for
  any existing request for that employee/date (including rejected/cancelled), so
  previously-rejected requests are not silently re-created.
- **Early-leave REST hook** (`sfs_hr_early_leave_requested`) now passes the
  correct `$emp_id` variable instead of undefined `$employee_id`.
- **CSV date import** now normalizes dates from multiple formats (dd/mm/yyyy,
  mm/dd/yyyy, d-m-Y, etc.) to MySQL `Y-m-d` before saving; previously,
  Excel-reformatted dates were passed verbatim and silently rejected by MySQL.

### Improved
- **CSV export** now formats date columns as `dd/mm/yyyy` for user-friendly
  display in spreadsheet applications.
- **Plugin activation**: removed redundant conditional re-invocations of
  `HiringModule::install()` and `SurveysModule::install()` that duplicated the
  unconditional calls made immediately above.

## [1.5.7] — 2026-02-26

### Fixed
- **Early leave requests not auto-created** when sessions flagged `left_early`:
  retro-close path (leading OUT closing yesterday's session) now detects
  left_early and creates the request; extracted shared
  `maybe_create_early_leave_request()` method; fixed `generate_reference_number()`
  to use MAX instead of COUNT to prevent UNIQUE constraint collisions.
- **Stale overnight sessions** staying open past shift-end + buffer: added
  shift-end deadline guard to `snapshot_for_today()`.
- **Impossible OUT < IN sessions**: leading OUT punches from previous day no
  longer set `$lastOut` before `$firstIn`; retro-close updates previous day's
  incomplete session directly.
- **CodeRabbit review fixes** (two batches): Leaflet CDN enqueue with SRI +
  local fallback, `allowed_dept_id` column correction, CSS fallback script,
  leading-OUT filter for downstream aggregations, rounding rule applied to
  `rounded_net_minutes` in retro-close, timezone-aware `$prevDate`, NULL vs 0
  for `overtime_buffer_minutes`, empty-segments fallback for total-hours shifts.

### Improved
- **Admin performance**: schema/migration checks (~56 information_schema queries)
  now skipped on steady-state admin pages via version guard; `dynamic_caps()` DB
  lookups cached per request via static variable.
- **Assets REST API**: removed unimplemented placeholder routes that exposed
  publicly-accessible endpoints returning empty responses.
- **Uninstall routine**: documented intentional data-retention policy.
- **Payroll component edit handler**: returns proper error instead of silently
  doing nothing.
- **Early-leave notifications**: manager and employee notification hooks now fire
  via `do_action()` on request creation and review.

### Changed
- Version references synchronized across README (was 1.2.4), CHANGELOG (was
  1.3.5), and plugin header/constant (1.5.7).
- Devices/Kiosk tab UI polished: pill-shaped buttons, compact punch timing
  fields, uniform 36px input heights.

## [1.3.5] — 2026-02-19

### Fixed
- **"Day off" checkbox not persisting after save** (two bugs):
  1. **Display**: The `??` (null coalescing) operator treated stored `null`
     (= day off) as "not set" and fell back to `'default'`, so the checkbox
     appeared unchecked on reload.  Now uses `array_key_exists()`.
  2. **Save**: The empty-times fallback (added in 1.3.3) matched ALL 7 days
     because the form always submits time inputs — even for days using shift
     defaults (empty `value=""`).  This saved every day as `null` (day off).
     Removed the fallback: days with empty times and no "Day off" checkbox
     are now omitted from the JSON and correctly use the shift's default hours.

## [1.3.3] — 2026-02-19

### Fixed
- **Weekly schedule empty times now treated as day off**: When a day in the
  shift's weekly schedule has empty/cleared times and the "Day off" checkbox
  is not explicitly checked, the day is now saved as a day off (null) instead
  of being silently omitted (which fell back to the shift's default working
  hours and caused absent marks).
- **Fix Off-Day Absences tool redesigned**: The tool now lets the admin select
  which day(s) of the week are off days (Fri, Sat, Sun checkboxes) and directly
  updates all absent sessions on those days to `day_off` — no longer depends
  on shift configuration resolving correctly.

## [1.3.2] — 2026-02-19

### Added
- **Fix Off-Day Absences** button on the Sessions period view — scans all
  `absent` records in the period, re-resolves each employee's shift, and
  corrects any that should be `day_off` (shift resolved to null).  Shows a
  result notification with the count of fixed vs total absent sessions.

## [1.3.1] — 2026-02-19

### Fixed
- Consolidated all attendance fixes from v1.3.0 into a stable release.

## [1.3.0] — 2026-02-18

### Fixed
- **Friday off-day marked as Absent (all shift types)**: Department automation
  overrides (e.g. Ramadan shift) replaced the default shift entirely, losing
  the original shift's `weekly_overrides` (off-day configuration).  The override
  shift now inherits the default shift's weekly off-days when it has none of its
  own, so rest days (Friday, Saturday) are preserved for all employees — both
  normal fixed-time shifts and total-hours policies.
- **Total-hours mode treating off-days as absent**: When a role-based total-hours
  policy was active and the shift resolved to null (day off), the status was
  incorrectly set to "absent" instead of "day_off".  Now checks if the shift
  resolver returned null before defaulting to absent.
- **Period overrides overriding all days**: Period overrides (e.g. Ramadan hours)
  applied new working times to every day including rest days (Friday/Saturday),
  causing employees to be marked absent on their off days.  Period overrides
  now support an `off_days` array so admins can specify which weekdays remain
  as days off during the override period.
- **Overnight Ramadan shifts clocked out at midnight**: Shifts ending past
  midnight (e.g. 22:00–01:30) lost clock-out punches because the punch query
  window was midnight-to-midnight.  The punch window now extends to cover the
  full shift segment when it crosses into the next calendar day.

### Added
- `off_days` checkboxes on the Period Override rows in the shift editor, allowing
  admins to mark specific weekdays as rest days during the override period.

## [1.2.4] — 2026-02-17

### Fixed
- Fix incomplete sessions showing inflated worked hours (e.g. 36h/day) —
  unmatched clock-ins now cap at shift end time instead of current moment.

## [1.2.3] — 2026-02-17

### Fixed
- Shift save now stays on the edit page and displays a success notice bar.
- Fix form fields overflowing card boundaries on employee profile edit page.
- Prevent attendance session rebuild from creating rows for future dates.
- Delete stale future-date session rows when rebuilding a period.
- Performance commitment table no longer shows future dates.

## [1.2.2] — 2026-02-17

### Fixed
- Employee profile page now full width with responsive breakpoints (stacks on mobile).
- Period override break field limit raised from 240 to 1440 minutes.

## [0.8.1] — 2026-02-13

### Fixed
- Fix PHP parse error in Performance dashboard — removed stray `<?php` tag.
- Fix `get_current_period()` calls to use static method syntax directly.

### Added
- Arabic translations for Phase 3/4 portal tab strings.

### Changed
- Updated §1.1 checkboxes in ENHANCEMENTS.md — marked completed shift/policy tasks.

## [0.8.0] — 2026-02-13

### Added
- **Admin Attendance Dashboard Widgets** (§10.3): organization-wide attendance
  dashboard accessible to HR, GM, and Admin roles.
  - **Today's Attendance gauge** — semicircle SVG gauge showing attendance rate
    with on-time (green) vs late (amber) arc segments.
  - **Summary counters** — Clocked In, Not Clocked In, On Leave/Holiday, Absent
    with color-coded KPI cards.
  - **Clock-in method breakdown** — Kiosk, Mobile, Web, Manual counts with icons
    in a 4-column grid.
  - **Department attendance bars** — horizontal bar chart showing attendance rate
    per department with color-coded thresholds (green ≥80%, amber ≥60%, red <60%).
  - **Calendar heatmap** — period calendar with color-coded cells (high/medium/low
    attendance) and today highlight. Hover shows detail tooltip.
  - **Employee status drill-down** — filterable employee list with status chips,
    clock-in/out times, and hours worked. Desktop table + mobile cards.

- **Frontend Portal for All Roles** (§10.0 Phase 3 & 4): manager, HR, GM, and
  Admin views added to the frontend portal.
  - **My Team tab** (Phase 3): team employee list for department managers (scoped
    to managed departments) with department and status filters. HR/GM/Admin see
    all employees. Desktop table + mobile card layouts.
  - **Approvals tab** (Phase 3): pending leave and loan approval queue. Managers
    see department-level leave requests; HR sees HR-level approvals; GM/Admin see
    all pending items. Each approval card shows employee info, request details,
    and inline Approve/Reject buttons with rejection reason prompt.
  - **Team Attendance tab** (Phase 3): team attendance summary for managers with
    today's KPI snapshot (present/late/absent/on-leave), period summary with
    attendance rate, and per-employee breakdown table showing present/late/absent
    days, average hours, and attendance rate percentage.
  - **Dashboard tab** (Phase 4): full organization attendance dashboard (§10.3
    widgets) for HR/GM/Admin roles.
  - **Employees tab** (Phase 4): employee directory for HR/GM/Admin with search
    (name, code, email), department/status filters, pagination, and KPI counters
    (active, terminated, resigned).
  - Navigation and Tab_Dispatcher updated to enable all Phase 3 & 4 tabs.

### Changed
- `Tab_Dispatcher` now imports and registers all 9 tab renderers (4 personal +
  3 team + 2 org).
- `Navigation::tab_has_renderer()` updated with all Phase 3/4 tab slugs.
- New CSS components: `.sfs-badge--neutral`, `.sfs-btn--success`, `.sfs-btn--danger`,
  `.sfs-btn--sm`, `.sfs-chip` filter chips, `.sfs-kpi-grid--4`, dashboard gauge,
  method breakdown, department bars, calendar heatmap, approval cards, and full
  dark mode overrides for all new components.

## [0.7.0] — 2026-02-13

### Added
- **Employee Self-Service UI Redesign** (§10.1): full card-based redesign of all
  employee portal tabs with a shared design system.
  - Design system CSS: reusable `.sfs-card`, `.sfs-kpi-grid`, `.sfs-badge`,
    `.sfs-alert`, `.sfs-form-*`, `.sfs-empty-state`, `.sfs-table`,
    `.sfs-history-card`, `.sfs-desktop-only` / `.sfs-mobile-only` utilities.
  - **KPI cards** at the top of Leave, Loans, and Documents tabs with
    colored icons and at-a-glance metrics.
  - **Status badges** with consistent color coding across all modules
    (approved=green, pending=amber, rejected=red, active=blue).
  - **Empty states** with icons and helpful messaging when no data exists.
  - **Improved forms**: unified form components (`.sfs-input`, `.sfs-select`,
    `.sfs-textarea`, `.sfs-btn`) with focus rings and responsive two-column
    layouts.
  - **Mobile history cards**: collapsible `<details>` cards replace dense
    tables on mobile for leave, loans, and resignation history.
  - **Desktop tables**: redesigned `.sfs-table` with uppercase header labels,
    hover rows, and cleaner spacing.
  - Typography hierarchy improvements: bigger headings, clearer section
    separation, better whitespace throughout.
  - Enhanced mobile bottom navigation with top-bar active indicator.

- **Leave Balance Visual Cards** (§10.2): redesigned leave balances section.
  - Color-coded cards per leave type (10-color palette: sky, rose, violet,
    amber, emerald, indigo, pink, orange, teal, slate).
  - Circular SVG progress ring showing remaining days.
  - Three metrics per card: Total Available, Consumed, Applied.
  - Mini progress bar showing balance usage percentage.
  - Cards are tappable links that scroll to the request form with the leave
    type pre-selected.

### Changed
- **Leave Tab** fully rewritten: KPI strip, balance cards, improved request
  form, desktop table + mobile card history with approver info and rejection
  reasons.
- **Loans Tab** fully rewritten: KPI cards (total borrowed, remaining, active,
  completed), improved request form with live payment calculator, desktop table
  + mobile card history with payment schedule.
- **Resignation Tab** fully rewritten: status alerts for pending/approved
  resignations, improved submission form with radio toggle for regular vs
  final exit, desktop table + mobile card history with final exit details.
- **Settlement Tab** fully rewritten: card-based settlement display with
  info tiles, line-item breakdown, clearance status grid, payment completion
  alert, and HR notes.
- **Documents Tab** fully rewritten: KPI strip (total/types/missing), missing
  documents alert, improved upload form using design system, card-based
  document library grouped by type with status badges.
- **Overview Tab** enhanced via CSS: improved typography for profile header,
  field rows, profile grid, assets section, completion bar, and chips.
- All dark mode selectors automatically apply to new design system components
  via CSS variable inheritance.

## [0.6.0] — 2026-02-13

### Added
- **Frontend Portal Framework** (§10.0 Phase 1): role-based navigation foundation
  for the unified frontend portal.
  - `Role_Resolver` service: detects user's highest portal role (admin, gm, hr,
    manager, employee, trainee) from capabilities, options, and department
    manager assignments.
  - `Navigation` registry: centralized tab definitions with role-based filtering,
    section grouping (personal/team/org/system), and conditional visibility
    (limited access, settlements, self-clock). Renders both desktop sidebar and
    mobile bottom bar from a single source of truth.
  - `Tab_Dispatcher`: routes tab rendering to dedicated Tab classes, with
    extensible `register()` method for future Phase 3/4 tabs.
  - **Desktop sidebar navigation**: icon + label sidebar on screens ≥768px with
    section dividers, active state highlighting, and sticky positioning.
  - **Mobile "More" menu**: overflow tabs shown in a popup menu when more than
    5 tabs are available, with smart active-tab swapping.
  - Tab validation: active tab is checked against the user's permitted tabs;
    unauthorized tabs fall back to overview.
  - `data-role` attribute on the app shell for role-based CSS targeting.
  - Full dark mode and RTL support for sidebar and more menu.

### Changed
- Shortcodes entry point (`my_profile`) refactored to use Navigation registry
  and Tab_Dispatcher instead of hardcoded tab HTML and if/elseif routing.
  All existing tabs continue to work exactly as before.

## [0.5.5] — 2026-02-12

### Added
- **System brochure** (`docs/Simple-HR-Suite-Brochure.md`): marketing-style
  documentation covering all 17 modules — employee management, attendance,
  leave, payroll, loans, performance, hiring, exit & settlement, assets,
  documents, shift swap, celebrations, workforce status, reports, self-service
  portal, audit trail, and mobile/offline capabilities. Designed for customer
  presentations during implementation (§6.1).

## [0.5.4] — 2026-02-12

### Fixed
- **Loans dashboard widget**: replaced hardcoded `Y-m-01` with
  `AttendanceModule::get_current_period()` so "this period" loan count
  respects custom attendance periods (e.g. 25th-to-25th).
- **Performance Calculator defaults**: three methods (`calculate_overall_score`,
  `get_performance_ranking`, `get_departments_summary`) now default to the
  configured attendance period instead of hardcoded calendar boundaries.

### Added
- **Period comparison on Performance Dashboard**: summary cards now show a
  delta indicator (▲/▼) comparing the current period average to the previous
  period. Department table gains a "Prev Period" column with per-department
  deltas. A "Previous Period" quick-nav button lets admins jump back one period.
- `AttendanceModule::get_previous_period()` — computes the period immediately
  before the current one.
- `AttendanceModule::format_period_label()` — returns human-readable labels
  like "February 2026" or "Jan 25 – Feb 24, 2026".

## [0.5.3] — 2026-02-12

### Added
- **Employee language preference**: new "Language" field on employee profile
  (edit & view mode) with English, Arabic, Filipino, Urdu options.
  Saving syncs to the linked WordPress user's locale.
- **Locale-aware email notifications**: all system emails (leave, attendance,
  loans, resignation, shift swap, payroll, reminders) now switch to the
  recipient's preferred language before building subject and body text.
  Uses `switch_to_locale()` + JSON translation reload so `__()` calls
  resolve in the correct language per recipient.
- Helper methods: `Helpers::get_available_languages()`,
  `Helpers::get_locale_for_email()`, `Helpers::send_mail_localized()`,
  `Helpers::reload_json_translations()`.

## [0.5.2] — 2026-02-12

### Fixed
- **Translation race condition**: language now auto-detected from WordPress locale
  (no longer defaults to English). After async translations load, dynamic elements
  (status chip, hints, button labels) are re-rendered so they always show the
  correct language.

### Added
- **Employee clock-in map**: interactive Leaflet/OpenStreetMap mini-map on the
  self-service attendance widget showing the geofence circle and the employee's
  live GPS position (blue dot). Only shown when shift has geofence coordinates.
- **Admin shift location map**: interactive map picker in the shift edit form.
  Click to set location, drag marker to adjust, radius circle updates live.
  Replaces the need to manually enter lat/lng coordinates.

## [0.5.1] — 2026-02-12

### Fixed
- **Untranslated status strings**: server-side label "Last: BREAK START at 19:30"
  now fully translated (punch type names + format string via `__()` and `sprintf()`).
  Client-side `punchTypeLabel()` now uses proper i18n keys (clock_in, clock_out,
  start_break, end_break, break_start, break_end, please_wait, seconds_short).
  Added missing keys to Arabic/English language JSON files.

### Improved
- **Mobile layout**: card now overlaps the teal header by 32px for a compact modern
  feel; reduced header height and increased card shadow for depth.
- **Color-coded success status**: after a successful punch, the status line shows a
  color-coded banner (green/red/amber/blue) matching the flash overlay.
- **Faster punch flow**: GPS validation and camera open now run in parallel for
  selfie punches (previously sequential). GPS preload on page load warms the cache
  so the first punch is faster.

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
