---
phase: 22-loans-performance-auth-hardening
plan: "02"
subsystem: Performance
tags: [auth, capability-check, department-scope, rest-api, ajax]
dependency_graph:
  requires: []
  provides: [PERF-AUTH-01, PERF-AUTH-02, PERF-AUTH-03]
  affects: [Performance REST endpoints, Performance AJAX handlers]
tech_stack:
  added: []
  patterns: [tiered-permission-model, department-scope-query, current_user_can-gate]
key_files:
  modified:
    - includes/Modules/Performance/PerformanceModule.php
    - includes/Modules/Performance/Rest/Performance_Rest.php
decisions:
  - ajax_update_goal_progress uses sfs_hr.manage only (not the read pair) -- consistent with ajax_save_goal since both are write operations on goal data
  - check_read_permission accepts WP_REST_Request as optional nullable param -- WordPress passes request to permission_callback; existing routes unaffected
  - Department scope uses get_managed_department_ids() helper to centralize the sfs_hr_departments manager_user_id query pattern
metrics:
  duration: "~5 minutes"
  completed_date: "2026-03-17T14:38:32Z"
  tasks_completed: 2
  files_modified: 2
---

# Phase 22 Plan 02: Performance Auth Hardening Summary

**One-liner:** Gated `ajax_update_goal_progress` with `sfs_hr.manage` and enforced department-scoped read access in REST `check_read_permission` for non-admin users.

## What Was Built

### Task 1: Capability Check in ajax_update_goal_progress (PERF-AUTH-01 + PERF-AUTH-03)

Added `current_user_can('sfs_hr.manage')` gate in `PerformanceModule::ajax_update_goal_progress()` immediately after the nonce check and before any `$_POST` data is read. This closes the gap where any authenticated user with a valid nonce could update goal progress without an HR role.

`ajax_save_goal()` was confirmed to already have the same check at line 159 — no regression (PERF-AUTH-03 satisfied).

### Task 2: Department Scope in REST check_read_permission (PERF-AUTH-02)

Replaced the single-capability `check_read_permission()` with a tiered model:

1. `sfs_hr.manage` — full unrestricted read access (any employee, any department)
2. `sfs_hr_performance_view` — full unrestricted read access (dedicated view role)
3. `sfs_hr.view` (department managers) — scoped: employee-specific routes check `is_employee_in_managed_department()`, department routes check `is_managed_department()`; summary/listing routes pass through (endpoint-level filtering expected)
4. All others — denied (returns `false`)

Three private helpers added to Performance_Rest:
- `get_managed_department_ids()` — queries `sfs_hr_departments.manager_user_id` for current user
- `is_employee_in_managed_department(int $employee_id)` — checks employee's dept_id is in managed list
- `is_managed_department(int $dept_id)` — checks dept_id is in managed list

Method signature updated to `check_read_permission(\WP_REST_Request $request = null): bool` — WordPress passes the request object to permission callbacks; the default null keeps existing call sites safe.

## Verification

1. `grep -n "current_user_can" PerformanceModule.php` shows check at line 188 (ajax_update_goal_progress) in addition to existing checks at 159 (ajax_save_goal) and 214 (ajax_save_review) -- PERF-AUTH-01 satisfied.
2. `grep -n "managed_department" Performance_Rest.php` shows is_employee_in_managed_department, is_managed_department, get_managed_department_ids -- PERF-AUTH-02 satisfied.
3. ajax_save_goal capability check at line 159 confirmed present, no regression -- PERF-AUTH-03 satisfied.

## Deviations from Plan

None -- plan executed exactly as written.

## Commits

- `b36b7d2` fix(22-02): add capability check to ajax_update_goal_progress
- `f305372` fix(22-02): enforce department scope in REST check_read_permission

## Self-Check: PASSED

Files exist:
- includes/Modules/Performance/PerformanceModule.php: FOUND
- includes/Modules/Performance/Rest/Performance_Rest.php: FOUND

Commits exist:
- b36b7d2: FOUND
- f305372: FOUND
