---
phase: 11-assets-audit
plan: "02"
subsystem: ui
tags: [php, wordpress, xss, csrf, sql, assets, views, audit]

# Dependency graph
requires:
  - phase: 11-assets-audit
    provides: "Assets core/service/handler audit findings (11-01)"
provides:
  - "Assets view templates audit findings with 16 categorized findings across 3 view files"
  - "Complete output escaping audit table (58 entries) for assets-edit.php, assets-list.php, assignments-list.php"
  - "Assignment ownership scoping gap documented (AVIEW-LOGIC-001)"
  - "CSRF protection confirmed present in both save and delete forms"
affects: [phase-12-employees-audit, phase-17-shiftswap-audit]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Inline $wpdb queries in view files identified as architectural violation pattern — views should be render-only"
    - "PHP-side filter after server-side LIMIT creates silent data loss — filters must be pushed to SQL WHERE"
    - "Status badge rendering: ucfirst/str_replace in assets-list.php diverges from Helpers::asset_status_badge() used in assignments-list.php and assets-edit.php"

key-files:
  created:
    - ".planning/phases/11-assets-audit/11-02-assets-views-findings.md"
  modified: []

key-decisions:
  - "AVIEW-SEC-001/002 High: assignments-list.php and assets-edit.php contain inline $wpdb queries — view files violate controller/view separation; assignment history query is prepared but still architecturally wrong"
  - "AVIEW-LOGIC-001 High: assignments list $rows from controller has no department scoping for managers — managers see all-org assignments even though the assignment form restricts to their departments"
  - "AVIEW-PERF-001 High: assets-list.php PHP-side re-filter after LIMIT 200 causes silent data loss on filtered views when total assets exceed 200"
  - "AVIEW-SEC-005 Low: two echo $emp_id without esc_attr() in assignments-list.php — integer cast makes it safe but violates project escaping convention"
  - "CSRF protection confirmed present: assets-edit.php save form (wp_nonce_field sfs_hr_assets_edit) and delete form (wp_nonce_field sfs_hr_assets_delete) both protected"
  - "XSS risk: 0 Critical or High unescaped outputs found — escaping discipline is good across all three files"
  - "assets-list.php action buttons (Add New, Edit link) require no additional capability check because the page requires sfs_hr.manage via admin_menu"

patterns-established:
  - "View audit pattern: check inline SQL, output escaping (all 58 dynamic outputs), CSRF nonces, capability gates on action buttons, ownership filtering for list views"
  - "Assignment scoping gap pattern: form scopes to manager dept, but list query unscoped — same gap possible in other modules with manager-level roles"

requirements-completed:
  - MED-01

# Metrics
duration: 3min
completed: 2026-03-16
---

# Phase 11 Plan 02: Assets View Templates Audit Summary

**16 findings across 3 view files (assets-edit.php, assets-list.php, assignments-list.php): architecture violations from inline SQL in views, a cross-department assignment data leak for managers, and a silent data loss bug from PHP-side filtering after a hard LIMIT 200**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-16T16:33:40Z
- **Completed:** 2026-03-16T16:36:51Z
- **Tasks:** 2
- **Files modified:** 1

## Accomplishments

- Full output escaping audit of 58 dynamic output points across all three view files — only 2 flagged (both Low, integer `$emp_id` without `esc_attr()`)
- CSRF protection confirmed: both the save form and delete form in `assets-edit.php` use `wp_nonce_field()`
- Identified cross-department assignment data leak (AVIEW-LOGIC-001 High): managers scoped to their departments in the assignment form but see all-org assignments in the list below
- Documented architectural violation: 5 `$wpdb` queries inline in view files (assignments-list.php has 4, assets-edit.php has 3 including one correctly prepared)
- Documented silent data loss: PHP-side re-filtering after `LIMIT 200` in assets-list.php causes filter results to silently miss assets beyond position 200

## Task Commits

1. **Task 1+2: Read and audit / Write findings report** - `3e1b4bf` (feat)

## Files Created/Modified

- `.planning/phases/11-assets-audit/11-02-assets-views-findings.md` - 16 findings with IDs, severity ratings, fix recommendations, and 58-entry escaping audit table

## Decisions Made

- AVIEW-LOGIC-001 High: The assignments-list.php view correctly scopes the assignment form employee dropdown to the manager's departments, but the `$rows` passed from the controller contains all assignments organization-wide. This is the same ownership scoping gap identified in Phase 05 (Attendance) and Phase 06 (Leave).
- AVIEW-SEC-001/002 High: Inline SQL in view files is an architectural violation. The queries are static or correctly prepared, so there is no injection risk, but the pattern couples data fetching to rendering and diverges from Settlement (0 direct wpdb calls in views) and Payroll (all DB access in controller).
- AVIEW-PERF-001 High: The double-filtering pattern (SQL + PHP) is worse than a miss for correctness — it silently drops records beyond position 200 when filters are applied. The fix (push filters to SQL and add pagination) was already partially implemented in the controller; the view-side PHP loop just needs to be removed.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Phase 11 Assets audit complete (both plans executed)
- Phase 12 Employees module audit is next (`includes/Modules/Employees/`, ~3.2K lines)
- Key patterns to carry forward: check for inline SQL in view files; verify assignment/data list queries apply the same scoping as the corresponding form dropdowns

---
*Phase: 11-assets-audit*
*Completed: 2026-03-16*
