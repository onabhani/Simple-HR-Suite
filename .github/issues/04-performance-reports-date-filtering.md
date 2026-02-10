---
title: "Fix performance reports to use filtered dates instead of overall data"
labels: bug, reports, P0-critical
---

## Problem

Performance reports currently show data based on the overall date range, ignoring any date filters the user has applied. This makes it impossible to view performance data for a specific period.

## Proposed Solution

- Investigate current report queries — confirm they use overall date range rather than filtered range
- Update report queries to respect user-selected date filters
- Add date range picker to performance report UI if not already present
- Ensure exported reports (CSV/PDF) also respect the selected date filter
- Link performance reports to the configurable attendance period (from issue #03)
- Allow reports to be generated per-period automatically
- Add period comparison view (e.g., this month vs. last month)

## Acceptance Criteria

- [ ] Date filter on reports actually filters the data
- [ ] Exported reports match the filtered view
- [ ] Period-based report generation works with configurable attendance period
- [ ] Comparison view shows two periods side by side

## Priority

P0 — Critical

## References

- ENHANCEMENTS.md Sections 4.1, 4.2
- Module: `includes/Modules/Performance/`
- Service: `includes/Core/Admin/Services/ReportsService.php`
