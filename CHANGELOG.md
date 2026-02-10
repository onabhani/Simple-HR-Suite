# Changelog

All notable changes to Simple HR Suite will be documented in this file.

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
