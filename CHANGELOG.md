# Changelog

All notable changes to Simple HR Suite will be documented in this file.

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
