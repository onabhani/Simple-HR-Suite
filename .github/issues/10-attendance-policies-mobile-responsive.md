---
title: "Make attendance policies page responsive for mobile"
labels: enhancement, ux, mobile, P1-high
---

## Problem

The attendance policies admin page does not work well on mobile devices. Tables, forms, and interactive elements are not properly sized or laid out for small screens.

## Proposed Solution

- Audit the attendance policies admin page on mobile viewports (320px–768px)
- Fix table layouts — use responsive tables or card-based layouts on small screens
- Fix form inputs and buttons for touch targets (minimum 44px)
- Test on actual mobile devices and browser DevTools
- Ensure all modals and dropdowns work on mobile

## Acceptance Criteria

- [ ] Page is usable on 320px viewport width
- [ ] All tables are readable without horizontal scrolling (or have proper scroll containers)
- [ ] Touch targets are minimum 44px
- [ ] Modals and dropdowns work on mobile
- [ ] No content is cut off or overlapping

## Priority

P1 — High

## References

- ENHANCEMENTS.md Section 3.2
- Module: `includes/Modules/Attendance/`
