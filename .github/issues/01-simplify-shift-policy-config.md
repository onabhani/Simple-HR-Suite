---
title: "Simplify shift and attendance policy configuration"
labels: enhancement, ux, P1-high
---

## Problem

There are too many paths and options for setting shifts and attendance policies. The current experience is confusing for administrators who need to set up shifts quickly.

## Proposed Solution

- Audit all current ways to create/edit shifts and attendance policies
- Consolidate shift creation into a single, guided workflow (wizard or stepped form)
- Reduce redundant policy fields — merge overlapping options
- Add sensible defaults so admins can set up a shift in fewer clicks
- Add bulk-assign shifts to departments or employee groups
- Improve inline help text / tooltips for policy fields

## Acceptance Criteria

- [ ] Single entry point for shift creation
- [ ] Shift can be created with 3 or fewer steps using defaults
- [ ] Bulk assignment to departments works
- [ ] Existing shifts and policies are not broken by migration

## Priority

P1 — High

## References

- ENHANCEMENTS.md Section 1.1
- Modules: `includes/Modules/Attendance/`, `includes/Modules/ShiftSwap/`
