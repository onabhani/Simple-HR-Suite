---
title: "Merge and unify admin employee profile views"
labels: enhancement, ux, P1-high
---

## Problem

Employee data for admins is scattered across multiple views and modules. Admins have to navigate to different places to see leave balances, attendance, loans, assets, and performance data for a single employee.

## Proposed Solution

- Audit all places where employee data is displayed to admins
- Design a single unified employee profile page for admins
- Merge data from all modules into the unified profile (leave balances, attendance summary, loans, assets, performance scores, documents)
- Add tabbed navigation (Overview, Leave, Attendance, Payroll, Performance, Documents, Assets)
- Remove or redirect duplicate/scattered profile views
- Ensure role-based access — tabs only visible if admin has the relevant capability

## Acceptance Criteria

- [ ] Single employee profile page with all data in tabs
- [ ] All existing profile entry points redirect to unified page
- [ ] Role-based tab visibility works correctly
- [ ] No data is lost or hidden compared to the old views

## Priority

P1 — High

## References

- ENHANCEMENTS.md Section 5.1
- Module: `includes/Modules/Employees/`
- Capabilities: `includes/Core/Capabilities.php`
