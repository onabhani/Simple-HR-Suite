---
phase: 28-performance-fixes
plan: 01
subsystem: admin
tags: [transient, caching, n+1, performance, wordpress, dashboard]

# Dependency graph
requires: []
provides:
  - Admin dashboard counter queries wrapped in 60s transient (sfs_hr_admin_dashboard_counts)
  - Analytics section queries wrapped in 300s transient (sfs_hr_admin_analytics)
  - Org chart manager fetches batched (get_users + single IN query, no per-department loops)
  - Loans dashboard widget stats wrapped in 120s transient (sfs_hr_loans_dashboard_widget)
  - Reminders upcoming count uses single CASE/WHEN batch query + 300s transient
  - Reminders widget result set cached in 300s transient (sfs_hr_reminders_upcoming_counts)
affects: [admin-dashboard, loans, reminders, org-chart]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Dashboard counter caching: get/set_transient pattern with _today date-aware cache bust"
    - "Org chart batch prefetch: get_users(include:[...]) + single IN() query before loops"
    - "Analytics transient: 300s TTL for aggregate data that changes slowly"

key-files:
  created: []
  modified:
    - includes/Core/Admin.php
    - includes/Modules/Loans/Admin/class-dashboard-widget.php
    - includes/Modules/Reminders/Services/class-reminders-service.php
    - includes/Modules/Reminders/Admin/class-dashboard-widget.php

key-decisions:
  - "Dashboard counters use 60s TTL with _today date key to force daily cache bust — prevents stale today-scoped stats (clocked_in_now, absent_today) from persisting across midnight"
  - "Analytics transient uses 300s TTL — aggregate trends change slowly and stale-by-5min is acceptable"
  - "Loans widget transient 120s — balance between freshness for approvals UI and reducing repeat queries"
  - "Reminders widget caches full result array (birthdays + anniversaries) separately from the count-only method; count method uses per-days cache key to support different call sites"
  - "Org chart GM lookup falls back to get_user_by when GM is not a department manager (GM may not appear in the manager batch map)"

patterns-established:
  - "Performance caching: wrap multi-query data assembly in get_transient; if false run queries, build array, set_transient"
  - "Date-aware transient: store _today in cache payload and bust when date changes for today-scoped counters"
  - "N+1 batch fix: collect all IDs before loop, single get_users(include) or single IN() query, build keyed map, look up in loop"

requirements-completed: [PERF-01, PERF-03]

# Metrics
duration: 25min
completed: 2026-03-18
---

# Phase 28 Plan 01: Performance Fixes — Dashboard, Org Chart, Analytics, Loans, Reminders Summary

**Transient caching for 13+ dashboard counter queries (60s), 3 analytics queries (300s), and org chart N+1 replaced with 2 batch queries; Loans widget cached (120s); Reminders widget N+1 replaced with CASE/WHEN batch queries and cached (300s)**

## Performance

- **Duration:** 25 min
- **Started:** 2026-03-18T00:00:00Z
- **Completed:** 2026-03-18T00:25:00Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments

- Admin dashboard fires near-zero repeated queries on cache hit — all 13+ counter queries wrapped in `sfs_hr_admin_dashboard_counts` transient (60s TTL with date-aware bust)
- Org chart N+1 eliminated — `render_organization_structure()` now batch-fetches all manager WP users via `get_users(include:[...])` and all manager employee records via single `IN()` query before either foreach loop
- Analytics section (dept headcount + attendance trend + leave trend) wrapped in `sfs_hr_admin_analytics` transient (300s)
- Loans widget stats (8 queries) cached in `sfs_hr_loans_dashboard_widget` (120s)
- Reminders `get_upcoming_count()` rewritten from N+1 per-offset queries to 2 batch CASE/WHEN queries; widget render result also cached (300s)

## Task Commits

Each task was committed atomically:

1. **Task 1: Cache Core admin dashboard counters + fix org chart N+1 + cache analytics** - `3a1eaf4` (feat)
2. **Task 2: Cache Loans dashboard widget + fix Reminders N+1 widget** - `2547e4a` (feat)

## Files Created/Modified

- `includes/Core/Admin.php` — Dashboard counter caching, org chart batch prefetch, analytics caching
- `includes/Modules/Loans/Admin/class-dashboard-widget.php` — Loans stats transient (120s)
- `includes/Modules/Reminders/Services/class-reminders-service.php` — Batch CASE/WHEN count query + transient
- `includes/Modules/Reminders/Admin/class-dashboard-widget.php` — Widget result set transient (300s)

## Decisions Made

- Dashboard counters use a `_today` date key inside the cached array to force a daily cache bust for today-scoped stats (clocked_in_now, absent_today) that would otherwise be stale after midnight with a 60s TTL.
- Org chart GM user lookup uses `$mgr_users_map[$gm_user_id] ?? get_user_by('id', $gm_user_id)` fallback because the GM may not be a department manager and therefore may not appear in the batch map.
- Reminders count caches per `$days` argument (cache key includes days count) to support different callers with different windows.
- Reminders widget caches the full `[birthdays, anniversaries]` array (not just counts) since the widget renders the full employee list.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

- PHP binary unavailable in shell environment; syntax correctness verified by manual review and grep pattern checks. No functional PHP linting was possible.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- All 5 performance fixes from PERF-01 and PERF-03 are in place.
- Ready to proceed with remaining performance phases (28-02 onward if any).
- Cache keys use `sfs_hr_` prefix per project conventions. No cache invalidation hooks were added — transients expire naturally. If real-time accuracy is needed for approval counts, callers can call `delete_transient('sfs_hr_admin_dashboard_counts')` after mutations.

---
*Phase: 28-performance-fixes*
*Completed: 2026-03-18*
