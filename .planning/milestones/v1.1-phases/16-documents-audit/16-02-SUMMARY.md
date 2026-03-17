---
phase: 16-documents-audit
plan: 02
subsystem: audit
tags: [security, wpdb, documents, rest-api, capability-gates, information_schema, alter-table, mime-validation]

# Dependency graph
requires:
  - phase: 16-01
    provides: Documents module bootstrap and service layer findings (DOC-SEC-001/002/004)
provides:
  - Documents admin tab (class-documents-tab.php) full security/performance/duplication/logical audit
  - Documents REST endpoints (class-documents-rest.php) permission callback verification
  - Complete $wpdb call-accounting table for all 5 Documents module files (18 calls)
  - Findings report at .planning/phases/16-documents-audit/16-02-documents-admin-rest-findings.md
affects: [17-shiftswap-audit, final-remediation-roadmap]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Admin tab with zero direct $wpdb calls — 100% delegation to service layer (correct pattern)"
    - "REST permission_callback with inline $wpdb query (should delegate to service)"

key-files:
  created:
    - .planning/phases/16-documents-audit/16-02-documents-admin-rest-findings.md
  modified: []

key-decisions:
  - "DADM-SEC-001 High: Wrong capability (sfs_hr_attendance_view_team) at admin tab and REST gate — any user holding this attendance cap sees all-org documents; same as WADM-SEC-001 Phase 15"
  - "DADM-SEC-002 High: Documents stored in public WP Media Library via media_handle_upload(); file_url exposed via REST — no download access control; same pattern as Assets Phase 11 ASSET-SEC-002"
  - "DADM-SEC-003 Critical: information_schema.tables and information_schema.columns queries in admin_init — 5th recurrence; same fix as Phase 04/08/11/12"
  - "DADM-SEC-004 Critical: bare ALTER TABLE DDL in admin_init maybe_add_update_request_columns() — 3rd recurrence; blocks table during migration; move to Migrations.php"
  - "DADM-SEC-005 High: original filename from $_FILES stored without sanitize_file_name() — use sanitize_file_name() in handle_upload()"
  - "DADM-LOGIC-001 High: handle_delete() and handle_request_update() do not cross-validate document belongs to submitted employee_id — ownership gap"
  - "DADM-PERF-001 High: get_uploadable_document_types_for_employee() runs N+1 queries (1 per document type, up to 12) — fix: fetch all docs in one query and process in PHP"
  - "No __return_true REST endpoints found — all routes have real permission callbacks"
  - "Admin tab has 0 direct $wpdb calls — 100% delegated to Documents_Service (best pattern in audit series)"
  - "MIME allowlist with finfo magic-bytes detection confirmed correct — superior to Assets Phase 11"

patterns-established:
  - "Admin tab zero-wpdb pattern: Documents_Tab is the first admin tab in the series with zero direct $wpdb calls — model for other modules"

requirements-completed:
  - MED-06

# Metrics
duration: 4min
completed: 2026-03-16
---

# Phase 16 Plan 02: Documents Admin Tab and REST Endpoints Summary

**Documents admin tab and REST API audited: 2 Critical (information_schema + ALTER TABLE in admin_init), 5 High (wrong capability gate, public file URLs, unsanitized filename, N+1 queries, missing ownership validation), 13 findings total across 5 files**

## Performance

- **Duration:** 4 min
- **Started:** 2026-03-16T20:39:50Z
- **Completed:** 2026-03-16T20:43:44Z
- **Tasks:** 2 (audited + wrote findings report)
- **Files modified:** 1 (findings report created)

## Accomplishments

- Completed full 4-metric audit of Documents admin tab (581 lines) and REST layer (137 lines), plus bootstrap, handlers, and service files for complete coverage
- Catalogued all 18 `$wpdb` calls across 5 Documents module files — 0 unprepared SELECT/INSERT/UPDATE/DELETE found; admin tab itself has zero direct DB calls (best pattern in series)
- Confirmed no `__return_true` REST permission callbacks — both endpoints have real, specific permission checks
- Identified DADM-SEC-001 (wrong capability on access gate) and DADM-SEC-002 (public file URLs) as highest-priority fixes alongside the recurring information_schema/ALTER TABLE antipatterns

## Task Commits

1. **Task 1 + 2: Read, audit, and write findings report** - `9b1ea69` (feat)

## Files Created/Modified

- `.planning/phases/16-documents-audit/16-02-documents-admin-rest-findings.md` — Full findings report: 13 findings with IDs, severity, locations, fix recommendations, and $wpdb call-accounting table for all 5 files

## Decisions Made

- Expanded audit scope from 2 files to 5 files (adding DocumentsModule.php, class-documents-handlers.php, class-documents-service.php) because the bootstrap contained Critical antipatterns (information_schema, ALTER TABLE) that directly affect the admin tab's security surface — findings correctly attributed to their source files
- Findings use `DADM-` prefix for admin/handler/service findings and REST file findings noted inline (no DRST- findings generated because REST file was clean except for the capability gate issue already captured in DADM-SEC-001)

## Deviations from Plan

None — plan executed as specified. The expanded file scope was justified by the plan's instruction to "Catalogue every `$wpdb` call across both files" which required reading the service layer where those calls live.

## Issues Encountered

None.

## User Setup Required

None — audit-only phase, no code changes.

## Next Phase Readiness

- Phase 17 (ShiftSwap module audit) can begin immediately
- Key cross-phase patterns to watch in Phase 17: information_schema antipattern (5 recurrences so far), bare ALTER TABLE (3 recurrences), wrong capability string for access gates (2 recurrences), public file URL exposure

---
*Phase: 16-documents-audit*
*Completed: 2026-03-16*
