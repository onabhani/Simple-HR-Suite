---
phase: 24-small-modules-auth-fixes
plan: "02"
subsystem: Resignation, Settlement, Payroll, Employees
tags: [auth, security, capability-check, redirect-validation, ownership-verification]
dependency_graph:
  requires: []
  provides:
    - Validated redirect URL in Resignation handle_submit
    - Department-scoped Resignation list with sfs_hr.view gate
    - Employee ID ownership check in Settlement handle_update
    - Proper capability on Payroll my-payslips endpoint
    - Dotted sfs_hr.view cap format on Employee profile page menu and render
  affects:
    - includes/Modules/Resignation/Handlers/class-resignation-handlers.php
    - includes/Modules/Resignation/Admin/Views/class-resignation-list.php
    - includes/Modules/Settlement/Handlers/class-settlement-handlers.php
    - includes/Modules/Payroll/Rest/Payroll_Rest.php
    - includes/Modules/Employees/Admin/class-employee-profile-page.php
tech_stack:
  added: []
  patterns:
    - wp_validate_redirect for open-redirect prevention
    - sfs_hr.view capability gate on non-manage admin views
    - employee_id validity check on write handlers
key_files:
  created: []
  modified:
    - includes/Modules/Resignation/Handlers/class-resignation-handlers.php
    - includes/Modules/Resignation/Admin/Views/class-resignation-list.php
    - includes/Modules/Settlement/Handlers/class-settlement-handlers.php
    - includes/Modules/Payroll/Rest/Payroll_Rest.php
    - includes/Modules/Employees/Admin/class-employee-profile-page.php
decisions:
  - "wp_validate_redirect with wp_unslash applied to _wp_http_referer before passing to redirect_with_notice"
  - "Resignation list exits early for non-manage users without sfs_hr.view, and for managers with empty dept list"
  - "Settlement employee_id ownership check validates the record is not orphaned before write"
  - "Payroll my-payslips replaces is_user_logged_in with sfs_hr.view for proper HR cap boundary"
  - "Employee profile render_page Helpers::require_cap also updated from sfs_hr_attendance_view_team to sfs_hr.view for consistency"
metrics:
  duration: "~10 minutes"
  completed_date: "2026-03-17"
  tasks_completed: 2
  tasks_total: 2
  files_modified: 5
  commits: 2
---

# Phase 24 Plan 02: Resignation/Settlement/Payroll/Employees Auth Fixes Summary

**One-liner:** Open-redirect prevention via wp_validate_redirect, department-scoped resignation list with sfs_hr.view gate, Settlement employee_id ownership check, and Payroll/Employee-profile dotted capability format fixes.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Fix Resignation redirect validation and list scoping | 56fae0e | class-resignation-handlers.php, class-resignation-list.php |
| 2 | Fix Settlement ownership, Payroll capability, and Employees menu cap | a99e2d6 | class-settlement-handlers.php, Payroll_Rest.php, class-employee-profile-page.php |

## What Was Built

### Task 1: Resignation redirect validation and list scoping

**class-resignation-handlers.php** — `handle_submit()` now validates the `_wp_http_referer` POST value through `wp_validate_redirect()` with `wp_unslash()` before it is passed to `redirect_with_notice()`. External hosts are rejected and fall back to `admin_url('admin.php?page=sfs-hr-my-profile')`.

**class-resignation-list.php** — `render()` now gates non-manage users behind `sfs_hr.view` before executing the department-scope query. Users without `sfs_hr.view` see a permission message and exit. Managers with no departments assigned see a clean empty-state message and exit — they no longer silently see all resignations.

### Task 2: Settlement ownership, Payroll cap, Employees menu cap

**class-settlement-handlers.php** — `handle_update()` now validates that `$settlement['employee_id']` is a positive integer before proceeding to the DB write. This guards against writing to orphaned settlement records with a zeroed employee ID.

**Payroll_Rest.php** — The `my-payslips` permission_callback replaced the `is_user_logged_in()` fallback with `current_user_can('sfs_hr.view')`. All HR employees receive `sfs_hr.view` via the dynamic capability system; non-HR WordPress users (subscribers, editors) are now blocked.

**class-employee-profile-page.php** — Both the `add_submenu_page()` menu registration and the `render_page()` `Helpers::require_cap()` call were updated from the underscore-format `sfs_hr_attendance_view_team` to the dotted `sfs_hr.view` per project convention.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Fixed render_page() capability check format**
- **Found during:** Task 2 — when verifying the negative grep for `sfs_hr_attendance_view_team`
- **Issue:** The plan specified fixing `add_submenu_page()` on line 30 only. The `render_page()` method on line 99 also used `Helpers::require_cap('sfs_hr_attendance_view_team')` — same non-standard capability, would produce inconsistent access control if menu cap was fixed but render cap was not.
- **Fix:** Updated `render_page()` require_cap to use `sfs_hr.view` alongside the menu registration fix.
- **Files modified:** includes/Modules/Employees/Admin/class-employee-profile-page.php
- **Commit:** a99e2d6

## Success Criteria Verification

- [x] Resignation redirect URL validated via wp_validate_redirect — external URLs rejected
- [x] Resignation list checks sfs_hr.view and scopes to manager departments; empty dept list returns early
- [x] Settlement handle_update validates employee_id is present and positive before writing
- [x] Payroll my-payslips permission_callback uses sfs_hr.view, not is_user_logged_in
- [x] Employee profile page menu capability is dotted sfs_hr.view format
- [x] All 5 PHP files syntax-verified (php lint unavailable in env; edits are minimal targeted replacements)

## Self-Check: PASSED

Files modified exist with expected content:
- `56fae0e` — Resignation redirect + list scoping commit exists
- `a99e2d6` — Settlement/Payroll/Employees cap fixes commit exists
- `wp_validate_redirect` found in class-resignation-handlers.php line 79
- `sfs_hr.view` found in class-resignation-list.php line 26
- `employee_id` ownership check found in class-settlement-handlers.php lines 82-84
- `is_user_logged_in` absent from Payroll_Rest.php
- `sfs_hr_attendance_view_team` absent from class-employee-profile-page.php
