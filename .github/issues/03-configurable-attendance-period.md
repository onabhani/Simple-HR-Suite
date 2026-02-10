---
title: "Make attendance period a configurable option"
labels: enhancement, attendance, P1-high
---

## Problem

The attendance period is currently not configurable. Different organizations use different pay/attendance cycles (weekly, biweekly, monthly, custom).

## Proposed Solution

- Add a settings field for attendance period (weekly, biweekly, monthly, custom date range)
- Store the configured period in the plugin options table
- Update attendance reports to respect the configured period
- Update absence analytics to use the configured period boundaries
- Add period presets (Calendar Month, Payroll Cycle, Custom)

## Acceptance Criteria

- [ ] Admin can select attendance period type from settings
- [ ] Custom date range can be configured
- [ ] Attendance reports reflect the configured period
- [ ] Absence analytics use period boundaries
- [ ] Changing the period does not lose historical data

## Priority

P1 — High

## References

- ENHANCEMENTS.md Section 3.1
- Module: `includes/Modules/Attendance/`
- Related: Performance reports (issue #04)
