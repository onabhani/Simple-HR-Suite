---
phase: 28-performance-fixes
plan: 02
subsystem: database
tags: [transient-cache, n+1-queries, group-by, batch-fetch, pagination, performance]

# Dependency graph
requires:
  - phase: 28-01
    provides: Dashboard and org-chart query optimizations (prior plan in same phase)
provides:
  - OverviewTab counter queries cached per-employee with 60s transient
  - Role_Resolver per-request static cache
  - Leave admin status badges loaded from 1 GROUP BY query instead of 11 COUNTs
  - Leave approver names batch-fetched before loop (no per-row get_user_by)
  - Leave employee history limited to 50 rows
  - Resignation status counts from 1 GROUP BY instead of 6 COUNTs
affects: [leave-admin, frontend-portal, resignation-admin]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - sfs_hr_overview_tab_{emp_id} transient pattern (60s TTL) for frontend portal employee counters
    - private static $cache per-request memoization in Role_Resolver
    - GROUP BY + PHP aggregation replacing N separate COUNT queries for status tab badges
    - batch SELECT from wp_users WHERE ID IN (...) before foreach loops for user display names
    - sfs_hr_leave_counts_{md5(scope)} transient for Leave status tab badge counts

key-files:
  created: []
  modified:
    - includes/Frontend/Tabs/OverviewTab.php
    - includes/Frontend/Role_Resolver.php
    - includes/Modules/Leave/LeaveModule.php
    - includes/Modules/Resignation/Services/class-resignation-service.php

key-decisions:
  - "OverviewTab today_shift kept outside cache — real-time attendance state should not be delayed 60s"
  - "pending_manager/pending_hr/pending_gm/pending_finance display tabs share the 'pending' DB count because they are PHP-derived sub-states from approval_level, not separate DB status values"
  - "Resignation GROUP BY uses both r.status and r.resignation_type columns to correctly derive final_exit count from resignation_type = 'final_exit' filter"
  - "Leave counts transient keyed by md5 of scoped dept IDs so manager-scoped counts are isolated from global counts"

patterns-established:
  - "OverviewTab cache pattern: sfs_hr_overview_tab_{emp_id} transient, 60s TTL, stores all counter data as array"
  - "Role resolver static cache: private static array, checked at start of resolve(), stored before each return"
  - "Status badge GROUP BY: single query SELECT status, COUNT(*) GROUP BY status, PHP array aggregation for tab display"
  - "Approver batch fetch: collect unique approver IDs from result set, single IN() query, lookup array for display"

requirements-completed: [PERF-01, PERF-02, PERF-03]

# Metrics
duration: 15min
completed: 2026-03-18
---

# Phase 28 Plan 02: Performance Fixes (Frontend + Leave + Resignation) Summary

**N+1 and multi-COUNT query patterns eliminated: OverviewTab 9+ queries cached to 0 on hit, Leave admin 11 COUNTs replaced by 1 GROUP BY, approver N+1 batch-fetched, Resignation 6 COUNTs replaced by 1 GROUP BY, Role_Resolver 4+ queries cached per-request**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-03-18T03:07:00Z
- **Completed:** 2026-03-18T03:22:45Z
- **Tasks:** 2
- **Files modified:** 4

## Accomplishments

- OverviewTab counter queries (9+ queries) wrapped in per-employee transient cache with 60s TTL — zero DB queries on cache hit
- Role_Resolver `resolve()` now uses a private static array cache — 4+ queries reduced to 0 on second call within the same PHP request
- Leave admin status tab badges now run 1 GROUP BY query (cached 60s) instead of 11 separate COUNT queries
- Leave approver display names batch-fetched before the array_map closure; removed per-row `get_user_by()` call
- Leave employee history LIMIT reduced from 100 to 50 rows
- Resignation `get_status_counts()` rewritten with single GROUP BY on both `status` and `resignation_type`; `final_exit` count correctly derived from `resignation_type = 'final_exit'`

## Task Commits

Each task was committed atomically:

1. **Task 1: Cache Frontend OverviewTab counters + add Role_Resolver per-request cache** - `4a63182` (perf)
2. **Task 2: Fix Leave status count N+1 + approver N+1 + pagination + Resignation count N+1** - `483b7ba` (perf)

## Files Created/Modified

- `includes/Frontend/Tabs/OverviewTab.php` - All counter/data queries wrapped in `get_transient`/`set_transient` with 60s TTL; today_shift kept outside cache
- `includes/Frontend/Role_Resolver.php` - `private static $cache = []` added; all return paths store result before returning
- `includes/Modules/Leave/LeaveModule.php` - 11-COUNT loop replaced by 1 GROUP BY with 60s transient; approver names batch-fetched before closure; history LIMIT 100 -> 50
- `includes/Modules/Resignation/Services/class-resignation-service.php` - 6-COUNT loop replaced by single `GROUP BY r.status, r.resignation_type` query

## Decisions Made

- OverviewTab today_shift excluded from cache — real-time attendance state must not be stale
- pending_manager/pending_hr/pending_gm/pending_finance display tabs share the 'pending' DB count (they are PHP-derived from approval_level, not real DB status values)
- Leave counts transient keyed by `md5(scope_key)` to isolate manager-scoped counts from global admin view
- Resignation GROUP BY uses both columns to correctly aggregate `final_exit` (which is a `resignation_type` value, not a `status` value)

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- All 5 performance requirements (PERF-01 through PERF-03) addressed across Phase 28 plans 01 and 02
- Phase 28 (performance-fixes) is complete
- No blockers for next phase

---
*Phase: 28-performance-fixes*
*Completed: 2026-03-18*
