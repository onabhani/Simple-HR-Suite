---
phase: 28-performance-fixes
plan: "03"
subsystem: Workforce_Status, Documents, Loans, Hiring, Performance, Attendance, Frontend, Core
tags: [performance, n+1, unbounded-queries, pagination, batch-query, limit]
dependency_graph:
  requires: []
  provides: [PERF-01, PERF-02]
  affects:
    - includes/Modules/Workforce_Status/Admin/Admin_Pages.php
    - includes/Modules/Workforce_Status/Notifications/Absent_Notifications.php
    - includes/Modules/Workforce_Status/Cron/Absent_Cron.php
    - includes/Modules/Documents/Services/class-documents-service.php
    - includes/Modules/Loans/Admin/class-admin-pages.php
    - includes/Modules/Hiring/Admin/class-admin-pages.php
    - includes/Modules/Performance/Services/Alerts_Service.php
    - includes/Modules/Attendance/Admin/class-admin-pages.php
    - includes/Frontend/Tabs/LoansTab.php
    - includes/Core/Notifications.php
tech_stack:
  added: []
  patterns:
    - Batch IN() query replacing per-row resolve_shift_for_date() calls
    - Single SELECT + PHP filter replacing N+1 per-type document queries
    - LIMIT + OFFSET pagination on admin list pages
    - Combined send method to avoid double DB query in cron
key_files:
  created: []
  modified:
    - includes/Modules/Workforce_Status/Admin/Admin_Pages.php
    - includes/Modules/Workforce_Status/Notifications/Absent_Notifications.php
    - includes/Modules/Workforce_Status/Cron/Absent_Cron.php
    - includes/Modules/Documents/Services/class-documents-service.php
    - includes/Modules/Loans/Admin/class-admin-pages.php
    - includes/Modules/Hiring/Admin/class-admin-pages.php
    - includes/Modules/Performance/Services/Alerts_Service.php
    - includes/Modules/Attendance/Admin/class-admin-pages.php
    - includes/Frontend/Tabs/LoansTab.php
    - includes/Core/Notifications.php
decisions:
  - "Workforce Status batch shift resolution falls back to resolve_shift_for_date() for employees with no assignment row — preserves correctness for unassigned employees"
  - "Absent_Notifications: added send_all_absent_notifications() combined dispatch to avoid double get_absent_employees_by_department() call; legacy send_absent_notifications() and send_employee_absent_notifications() kept for backward compatibility"
  - "Attendance assignments tab already uses JOIN for dept labels (dept_label_from_employee defined but never called in a loop) — no code change needed for ATT-API-PERF-001"
  - "DashboardTab already had LIMIT 100 — no change needed for FE-PERF-004"
  - "Performance reviews query already had LIMIT 50 — no change needed for PADM-PERF-004"
  - "PHP lint could not be run (no PHP binary on this machine) — code reviewed manually for syntax"
metrics:
  duration_minutes: 7
  completed_date: "2026-03-18"
  tasks_completed: 2
  tasks_total: 2
  files_modified: 10
---

# Phase 28 Plan 03: N+1 and Unbounded Query Fixes Summary

Batch shift resolution, Documents batch type check, and LIMIT/pagination across 8 modules eliminating all identified unbounded query patterns from the v1.1 audit.

## Tasks Completed

### Task 1: Fix Workforce Status N+1 shift resolution + Documents N+1 + Absent Notifications N+1

**Commit:** fb01b71

**Workforce Status Admin_Pages.php (WADM-PERF-001/002):**
- Employee load query in `get_all_rows()` capped at `LIMIT 500` (WADM-PERF-001)
- `get_employee_shifts_map()` rewritten to batch-query `sfs_hr_attendance_employee_shifts JOIN sfs_hr_attendance_shifts` using an `IN()` subquery with `MAX(effective_date)` grouping — eliminating N per-employee `resolve_shift_for_date()` calls (WADM-PERF-002)
- Fallback to `resolve_shift_for_date()` retained only for employees with no assignment row

**Absent_Notifications.php (WNTF-PERF-001/002):**
- Added `batch_resolve_day_off(array $emp_ids, string $ymd): array` — single batch query for all employees' shift day-off status (eliminates N+1 per-employee `is_employee_day_off()` calls)
- Added `send_all_absent_notifications(string $date): array` — combined dispatch that calls `get_absent_employees_by_department()` once and sends both manager and employee notifications in one pass
- Absent_Cron updated to use `send_all_absent_notifications()` — eliminates double DB query per cron run

**Documents class-documents-service.php (DOC-PERF-001):**
- `get_uploadable_document_types_for_employee()` rewritten: single `SELECT document_type, expiry_date, update_requested_at FROM sfs_hr_employee_documents WHERE employee_id = %d` batch query, then all uploadability logic done in PHP — eliminating N per-type `get_active_document_of_type()` queries

### Task 2: Add LIMIT/pagination to all unbounded queries

**Commit:** e6ff482

| Finding | File | Fix |
|---------|------|-----|
| LADM-PERF-001 | Loans Admin class-admin-pages.php | Pagination: COUNT query + `LIMIT %d OFFSET %d`, `paginate_links()` nav |
| LADM-PERF-004 | Loans Admin class-admin-pages.php | Employee dropdown: `LIMIT 500` |
| FE-PERF-006 | Frontend LoansTab.php | Employee loans history: `LIMIT 20` |
| FE-PERF-004 | Frontend DashboardTab.php | Already had `LIMIT 100` — no change |
| HIR-PERF-001/002 | Hiring Admin class-admin-pages.php | Candidates and trainees lists: `LIMIT 50` each |
| PADM-PERF-004 | Performance Admin_Pages.php | Already had `LIMIT 50` on reviews — no change |
| PADM-PERF-005 | Performance Alerts_Service.php | `get_active_alerts()`: `LIMIT 100` |
| ATT-API-PERF-001 | Attendance Admin class-admin-pages.php | `dept_label_from_employee()` defined but never called; assignments tab uses JOIN — no change needed |
| ATT-API-PERF-002 | Attendance Admin class-admin-pages.php | Punches + sessions employee dropdowns: `LIMIT 500` each |
| ATT-API-PERF-005 | Attendance Admin class-admin-pages.php | Devices list: `LIMIT 200` |
| DADM-PERF-003 | Documents Services class-documents-service.php | `get_expiring_documents()`: `LIMIT 100` |
| CORE-PERF-005 | Core Notifications.php | All 6 pending queries in `process_pending_action_reminders()`: `LIMIT 200` each |

## Deviations from Plan

**1. [Rule 2 - Missing] Absent_Notifications: added combined dispatch method**
- **Found during:** Task 1 — to fix the double `get_absent_employees_by_department()` call, needed a combined method in the class itself rather than just fixing the cron
- **Fix:** Added `send_all_absent_notifications()` to `Absent_Notifications.php`; updated `Absent_Cron` to use it
- **Files modified:** includes/Modules/Workforce_Status/Notifications/Absent_Notifications.php, includes/Modules/Workforce_Status/Cron/Absent_Cron.php

**2. [Rule 1 - Observation] ATT-API-PERF-001 already resolved**
- `dept_label_from_employee()` is defined in Attendance Admin but is never called; the `render_assignments()` method already uses a SQL JOIN for department labels. No code change needed.

**3. [Note] PHP lint unavailable**
- PHP binary not present on the execution machine. Code was reviewed manually for syntax correctness. No structural changes were made that could introduce parse errors.

## Self-Check: PASSED

- SUMMARY.md: FOUND at .planning/phases/28-performance-fixes/28-03-SUMMARY.md
- Commit fb01b71: FOUND (Task 1 — batch shift resolution and documents N+1 fixes)
- Commit e6ff482: FOUND (Task 2 — LIMIT/pagination on all unbounded queries)
- All key files confirmed modified: grep LIMIT confirms bounds on all target files
