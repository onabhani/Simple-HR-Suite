---
phase: 07-performance-audit
plan: 02
subsystem: Performance
tags: [audit, security, performance, rest-api, admin-pages]
dependency_graph:
  requires: []
  provides:
    - 07-02-performance-admin-rest-findings.md
  affects:
    - includes/Modules/Performance/Rest/Performance_Rest.php
    - includes/Modules/Performance/Admin/Admin_Pages.php
    - includes/Modules/Performance/PerformanceModule.php
tech_stack:
  added: []
  patterns:
    - Audit-only — no code changes
key_files:
  created:
    - .planning/phases/07-performance-audit/07-02-performance-admin-rest-findings.md
  modified: []
decisions:
  - "No __return_true endpoints found in Performance REST — all routes require at minimum sfs_hr_performance_view"
  - "check_write_permission and check_admin_permission are identical (both check sfs_hr.manage) — false semantic distinction"
  - "Justification workflow write access is well-controlled (HR Responsible + time window + threshold gate); read access lacks formal publish mechanism"
  - "Performance scores are live-calculated with no draft/published state gate — draft review ratings visible via REST score endpoint"
  - "REST update_settings calls undefined PerformanceModule::save_settings() — PHP fatal on PUT /performance/settings"
metrics:
  duration_seconds: 183
  completed_date: "2026-03-16"
  tasks_completed: 2
  files_created: 1
  files_modified: 0
---

# Phase 07 Plan 02: Performance Admin, REST, and Module Audit Summary

**One-liner:** 21 findings in Performance REST/admin layer — undefined `save_settings` method causes REST fatal, missing capability guard on AJAX progress update, and cross-department score access via `check_read_permission`.

## Findings Overview

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 0 | 3 | 3 | 1 | 7 |
| Performance | 0 | 2 | 2 | 1 | 5 |
| Duplication | 0 | 0 | 2 | 1 | 3 |
| Logical | 0 | 3 | 2 | 1 | 6 |
| **Total** | **0** | **8** | **9** | **4** | **21** |

## Key Findings

### PADM-SEC-003 (High) — Fatal: REST `update_settings` calls undefined method

`Performance_Rest::update_settings()` line 725 calls `PerformanceModule::save_settings($data)` — the method is named `update_settings()`. Any authenticated admin calling `PUT /performance/settings` gets a PHP fatal error. Fix: rename call to `update_settings()` and merge with defaults.

### PADM-SEC-001 (High) — `ajax_update_goal_progress` missing capability check

The AJAX handler verifies nonce but performs no `current_user_can()` check. Any user who can obtain the `sfs_hr_performance` nonce can set any goal's progress to any value.

### PADM-SEC-002 (High) — Cross-department score/review access via REST

`check_read_permission` grants access to all managers and HR responsibles. None of the GET endpoints (`/performance/score`, `/performance/reviews`, `/performance/goals`) enforce ownership or department scope — a manager for Department A can read all scores for Department B employees.

### PADM-PERF-001 (High) — Dashboard double N+1

`render_dashboard()` calls `Performance_Calculator::get_departments_summary()` twice (current + previous period). Combined with the N+1 issue from Plan 01 (1,000+ queries per ranking call), the dashboard fires this N+1 query set twice on every page load.

### PADM-PERF-002 (High) — Inline alert refresh on page render

`Alerts_Service::refresh_employee_attendance_alerts()` is called synchronously during `render_employee_detail()`. This triggers INSERT/UPDATE/DELETE operations during a page render, creating unpredictable page load times.

### PADM-LOGIC-001 (High) — Out-of-order state machine: acknowledge on draft review

`POST /performance/reviews/{id}/acknowledge` does not verify the review is in `submitted` status. Employees can acknowledge draft reviews before they are formally submitted.

### PADM-LOGIC-002 (High) — Draft review ratings visible via REST score endpoint

`GET /performance/score/employee/{id}` returns live-calculated scores that likely include draft review ratings. No publish gate exists.

## Justification Workflow Summary

Write access is well-controlled: HR Responsible only, 5-day window before period end, employee must be below threshold. Read access is granted to HR Responsible + department manager + GM + admin. Employees cannot read their own justifications. No formal publish/notify mechanism exists. Score visibility is not gated on review publish state (PADM-LOGIC-002).

## REST Endpoint Security Summary

All 28 registered REST routes use named permission callbacks. No `__return_true` endpoints were found. `check_read_permission` resolves to `sfs_hr_performance_view`, granted to managers, HR responsibles, and `sfs_hr.manage` users. Write/admin callbacks both resolve to `sfs_hr.manage` (duplication: PADM-DUP-002).

## $wpdb Audit Summary

- 3 queries missing `$wpdb->prepare()` (static queries with hard-coded literals — style violations, not injection risks)
- 11 queries confirmed safe with `$wpdb->prepare()` or `$wpdb->insert()`/`$wpdb->update()`
- 1 conditional query in `render_goals()` is safe due to prior preparation but violates project convention

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check

- [x] Findings file exists at `.planning/phases/07-performance-audit/07-02-performance-admin-rest-findings.md`
- [x] All 3 source files referenced in findings
- [x] Every REST endpoint's permission_callback documented (28 endpoints in table)
- [x] All `$wpdb` calls accounted for
- [x] Justification workflow access paths explicitly documented
- [x] Each finding has severity + fix recommendation
- [x] 30 PADM- IDs in findings file
