---
phase: 05-attendance-audit
plan: 02
subsystem: api
tags: [attendance, rest-api, security, sql-injection, auth, kiosk, early-leave]

requires:
  - phase: 05-attendance-audit
    provides: Phase 05-01 Services/Cron audit context (Session_Service lock gap, Daily_Session_Builder UTC bug)

provides:
  - "20-finding security/performance/logic audit of Attendance Admin, REST, and Frontend layer"
  - "REST endpoint permission callback inventory (20 endpoints evaluated)"
  - "Admin-post handler CSRF/nonce inventory (18 handlers evaluated)"
  - "Kiosk security model documented (QR token flow, selfie upload, geofence bypass rationale)"

affects:
  - 05-attendance-audit (phase 3 plan if exists)
  - any fix phase targeting Attendance module

tech-stack:
  added: []
  patterns:
    - "Audit findings use ATT-API-{SEC,PERF,LOGIC}-NNN ID scheme"
    - "Kiosk QR token: scan → server scan token (transient TTL 10min) → punch endpoint"

key-files:
  created:
    - .planning/phases/05-attendance-audit/05-02-attendance-admin-rest-frontend-findings.md
  modified: []

key-decisions:
  - "GET /attendance/status __return_true is Critical: exposes device geofence + employee snapshot to unauthenticated callers"
  - "POST /attendance/verify-pin __return_true is Critical: PIN brute-force with no rate limiting"
  - "Scan token peek-not-consume is intentional design but creates window for multi-type forgery"
  - "Kiosk geofence bypass for kiosk-source punches is correct design (physical presence is gate)"
  - "assign_bulk N+1 query pattern (per-row get_var in date × employee loop) is High performance issue"

requirements-completed:
  - CRIT-01

duration: 28min
completed: 2026-03-16
---

# Phase 5 Plan 02: Attendance Admin / REST / Frontend Audit Summary

**20-finding audit of 14.3K lines across Admin, REST, and Frontend: 2 Critical (unauthenticated /status and /verify-pin endpoints), 9 High (missing CSRF nonce, LIMIT/OFFSET not prepared, kiosk token hash exposure, cross-module capability leak, N+1 queries), 7 Medium/Low (CDN SRI, transaction gaps, enqueue antipatterns)**

## Performance

- **Duration:** 28 min
- **Started:** 2026-03-16T01:53:36Z
- **Completed:** 2026-03-16T02:21:00Z
- **Tasks:** 2 (audited all 6 files; wrote findings report)
- **Files modified:** 1 (findings report created)

## Accomplishments

- Audited all 6 target PHP files: `Admin/class-admin-pages.php` (6984 lines), `Rest/class-attendance-rest.php` (1637 lines), `Rest/class-attendance-admin-rest.php` (667 lines), `Rest/class-early-leave-rest.php` (626 lines), `Frontend/Kiosk_Shortcode.php` (2514 lines), `Frontend/Widget_Shortcode.php` (1924 lines)
- Evaluated all 20 REST endpoint permission callbacks — 2 Critical flagged (`__return_true` on status and verify-pin)
- Evaluated all 18 admin-post AJAX handlers for capability check + nonce — 1 missing nonce on `handle_rebuild_sessions_day`
- Documented full kiosk security model: QR scan token flow, selfie validation, geofence bypass rationale, offline punch trust chain
- Identified early-leave approval hierarchy correctly implemented with transaction and race-condition guard; one cross-module capability leak flagged

## Task Commits

1. **Task 1+2: Audit 6 files and write findings report** — `4ef57cc` (feat)

## Files Created/Modified

- `.planning/phases/05-attendance-audit/05-02-attendance-admin-rest-frontend-findings.md` — 514-line structured audit report with 20 findings, REST permission table, admin-post handler table, kiosk security analysis

## Decisions Made

- `GET /attendance/status` with `__return_true` classified Critical because it exposes device geofence coordinates (lat/lng/radius) and selfie policy to unauthenticated callers — any internet scanner can enumerate kiosk device locations
- `POST /attendance/verify-pin` with `__return_true` classified Critical because PINs are typically 4–6 digits and there is no rate limiting or lockout on this endpoint
- Scan token peek-not-consume design (intentional per code comment) rated High Logic — creates a 10-minute window where a single QR scan can be used to submit all four punch types without re-scanning; recommended consuming on first success and minting a continuation token
- Kiosk geofence bypass for `source='kiosk'` punches correctly classified as acceptable design (physical kiosk presence is sufficient geo gate)
- `assign_bulk` N+1 query pattern (per-row `get_var` inside date × employee nested loop) rated High performance — a 30-day range with 50 employees generates 1500 queries

## Deviations from Plan

None — plan executed exactly as written. All 6 files audited, all metrics applied, findings report written to specified path.

## Issues Encountered

None.

## Next Phase Readiness

- Phase 05-02 findings complete; ready for Phase 06 (Leave module audit) or a dedicated fix phase targeting Attendance
- Top 2 Critical findings (unauthenticated /status and /verify-pin) should be addressed before any production deployment
- The N+1 dept label query in Admin_Pages (ATT-API-PERF-001) and the missing `check_admin_referer` on rebuild handler (ATT-API-SEC-006) are quick fixes that can be batched

---
*Phase: 05-attendance-audit*
*Completed: 2026-03-16*
