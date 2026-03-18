---
phase: 19-reminders-employeeexit-pwa-audit
plan: 02
subsystem: audit
tags: [pwa, service-worker, security, offline, push-notifications, indexeddb]

# Dependency graph
requires:
  - phase: 19-reminders-employeeexit-pwa-audit-01
    provides: Reminders + EmployeeExit audit findings — established audit pattern for Phase 19 batch
provides:
  - PWA module security audit findings with 13 findings (2 Critical, 4 High, 3 Medium, 4 Low)
  - Data leakage assessment for service worker cache and IndexedDB employee roster
  - Stub/incomplete code catalogue for push notification system and offline kiosk roster
  - $wpdb query catalogue (0 queries — cleanest module in series)
affects: [v1.2-planning, security-remediation, pwa-completion]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Audit-only: no code changes in v1.1"
    - "PWA audit covers PHP module + JS service worker + pwa-app.js together as a unit"

key-files:
  created:
    - .planning/phases/19-reminders-employeeexit-pwa-audit/19-02-pwa-findings.md
  modified: []

key-decisions:
  - "PWAModule.php has 0 $wpdb calls — cleanest module in audit series from SQL injection perspective"
  - "Push notification infrastructure is dead code: push event listener exists but no VAPID keys, no subscription endpoint, no PHP sender — stub module confirmed"
  - "nopriv_ AJAX manifest endpoint exposes admin URL as PWA shortcut (High severity) — different from __return_true REST pattern but same class of over-broad access"
  - "Service worker scope '/' is overly broad — SW intercepts all site requests, not scoped to HR portal"
  - "Dynamic HR pages (leave balance, profile, attendance) ARE cached by the SW fetch handler — REST/AJAX excluded but SSR pages are not"

patterns-established:
  - "PWA-specific audit covers: SW scope, manifest information disclosure, IndexedDB PII storage, push auth, cache strategy for dynamic data"

requirements-completed: [SML-06]

# Metrics
duration: 15min
completed: 2026-03-17
---

# Phase 19 Plan 02: PWA Module Audit Summary

**PWA module audit: 13 findings across 5 files — 0 SQL queries (cleanest module in series), push notification system is entirely dead code, service worker scoped to entire site origin, manifest exposes admin URL to unauthenticated callers**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-03-17T02:32:40Z
- **Completed:** 2026-03-17T02:47:00Z
- **Tasks:** 1/1
- **Files modified:** 1 created

## Accomplishments

- Audited PWAModule.php (414 lines) plus service-worker.js (219 lines), pwa-app.js (243 lines), manifest.json, and icon — 5 files total
- Confirmed 0 $wpdb calls in PWAModule.php — no SQL injection surface; cleanest module in the entire audit series
- Identified push notification system as dead code: SW listener registers but no VAPID keys, subscription endpoint, or PHP push sender exist anywhere in the codebase
- Confirmed service worker scope `/` intercepts all site GET requests — HR-specific data pages (leave, profile, attendance) ARE cached and persist after logout
- Found nopriv_ AJAX endpoints serving the manifest (including admin URL shortcut) and service worker JS to unauthenticated callers

## Task Commits

1. **Task 1: Read and audit PWA module** - `3bd4b96` (feat)

**Plan metadata:** (docs commit follows)

## Files Created/Modified

- `.planning/phases/19-reminders-employeeexit-pwa-audit/19-02-pwa-findings.md` — PWA audit findings: 2 Critical, 4 High, 3 Medium, 4 Low across security, performance, duplication, logical categories

## Decisions Made

- Push notification dead code: service-worker.js registers `push` event listener, but no PHP subscription management, no VAPID key configuration, and no server-side push sender exist. Recommendation: gate behind `sfs_hr_pwa_push_enabled` feature flag or remove until implementation is complete.
- Service worker scope: registered with `{ scope: '/' }` and served via `admin-ajax.php` with `Service-Worker-Allowed: /` override. This is a known limitation of AJAX-served service workers — may silently fail on subdirectory WP installations.
- Dynamic manifest: the static `assets/pwa/manifest.json` file is dead code — never served; the dynamic AJAX endpoint supersedes it. Also contains broken icon paths.
- No recurring SQL antipatterns found — PWA is the first module in the audit series with zero database interaction.

## Deviations from Plan

None — plan executed exactly as written. The audit scope was extended slightly to cover `assets/pwa/service-worker.js`, `assets/pwa/pwa-app.js`, and `assets/pwa/manifest.json` in addition to the primary `PWAModule.php`, since these files are integral to the security assessment (service worker data leakage, push notification dead code, manifest information disclosure). This is within the spirit of the plan's "service worker endpoint is checked for data leakage" criterion.

## Issues Encountered

None.

## User Setup Required

None — audit-only plan, no code changes.

## Next Phase Readiness

Phase 19 (Reminders + EmployeeExit + PWA audit) is now complete:
- Plan 01: Reminders + EmployeeExit findings delivered
- Plan 02: PWA findings delivered

The full v1.1 audit milestone is complete across all 16 phases (Phases 4-19). All 23 requirements have been audited and findings delivered.

Key cross-cutting findings for v1.2 remediation prioritization:
1. **Critical (recurring):** information_schema antipattern — 6 modules affected
2. **Critical (recurring):** bare ALTER TABLE — 3 modules affected
3. **Critical (new in Phase 19):** sfs_hr.view gating full settlement hub (EX-SEC-001 from Plan 01)
4. **High (PWA-specific):** nopriv_ manifest endpoint + service worker scope too broad
5. **High (PWA-specific):** Dynamic HR page caching without logout cache-clear

---
*Phase: 19-reminders-employeeexit-pwa-audit*
*Completed: 2026-03-17*
