---
phase: 18-departments-surveys-projects-audit
plan: "02"
subsystem: Projects
tags: [audit, security, performance, stub-assessment, sql-safety]
dependency_graph:
  requires: []
  provides: [18-02-projects-findings.md]
  affects: [ProjectsModule, Projects_Service, Admin_Pages]
tech_stack:
  added: []
  patterns: [audit-findings, wpdb-catalogue, stub-assessment]
key_files:
  created:
    - .planning/phases/18-departments-surveys-projects-audit/18-02-projects-findings.md
  modified: []
decisions:
  - Projects module POST handlers are all cleanly guarded (capability + nonce) — no Critical auth gaps
  - information_schema antipattern in get_employee_project_on_date() is the 6th recurrence — systemic fix needed
  - Transactional deletes and assignment ownership verification are positive patterns unique in the audit series
metrics:
  duration: 179s
  completed: 2026-03-17
  tasks_completed: 1
  files_created: 1
---

# Phase 18 Plan 02: Projects Module Audit Summary

**One-liner:** Projects module audit: 1 Critical (information_schema 6th recurrence), 5 High, 5 Medium, 3 Low — all handlers clean, stub/incomplete features catalogued.

## What Was Built

A comprehensive security, performance, duplication, and logical audit of the Projects module across 3 files (~1,160 lines). Produced a structured findings report with 14 findings, full $wpdb call catalogue (34 calls), stub/incomplete feature assessment, and cross-module pattern reference table.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Read and audit Projects module | df74b18 | .planning/phases/18-departments-surveys-projects-audit/18-02-projects-findings.md |

## Findings Summary

| Severity | Count | Key Finding |
|----------|-------|-------------|
| Critical | 1 | PROJ-SEC-001: information_schema.tables in get_employee_project_on_date() — 6th recurrence |
| High | 5 | N+1 dashboard loop; unbounded SELECTs; unfiltered wp_dropdown_users; manager_user_id not validated; inline wpdb in controller |
| Medium | 5 | No duplicate assignment guard; stale employee counts; no lifecycle state machine; no multi-project overlap check; date validation duplication |
| Low | 3 | DDL outside Migrations.php; last-wins silent disambiguation; date validation duplication |

## Key Findings Detail

**PROJ-SEC-001 [Critical]:** `information_schema.tables` query in `get_employee_project_on_date()` at Projects_Service.php:218. Per-request static cache does not persist across requests. Called on every kiosk punch-in/out and attendance page load. Fix: SHOW TABLES LIKE with transient cache, or remove the check and let the JOIN fail gracefully.

**PROJ-PERF-001 [High]:** N+1 loop in `render_project_dashboard()` — `Attendance_Metrics::get_employee_metrics()` called per employee in a foreach. For a 50-person project over 30 days, generates 200-500 queries per page load.

**PROJ-PERF-002 [High]:** `get_all()` and employee dropdown query have no LIMIT. Unbounded for large organizations.

**PROJ-SEC-002/003 [High]:** `wp_dropdown_users()` shows all WordPress users (not filtered to HR roles) for project manager assignment. `manager_user_id` is accepted from POST without user existence validation.

## Positive Patterns (Unique to Projects Module)

- Transactional deletes: `delete()` and `add_shift()` use START TRANSACTION / COMMIT / ROLLBACK — only module in audit series to use transactions proactively.
- Assignment ownership verification: `handle_remove_employee()` and `handle_remove_shift()` cross-validate `project_id` before deletion — correct IDOR prevention (contrast Phase 16 DADM-LOGIC-001).
- Clean dual-guard on all 6 POST handlers: `current_user_can('sfs_hr.manage')` before `check_admin_referer()`.
- Service delegation: 32 of 34 wpdb calls are in Projects_Service; only 2 static queries exist inline in Admin_Pages.

## Stub/Incomplete Assessment

| Feature | Status | Risk |
|---------|--------|------|
| REST API | Missing entirely | Dead geofence schema fields (lat/lng/radius) are never read by kiosk |
| Frontend shortcode | Missing | Employees cannot see their own project assignments |
| Notifications | Missing | Silent assignment and deletion |
| Geofence enforcement | Dead code | Schema stores location but kiosk ignores it |
| Cron / lifecycle | Missing | Stale projects and terminated-employee assignments accumulate |

## Cross-Module Patterns

| Pattern | Occurrence |
|---------|-----------|
| information_schema | 6th recurrence (Phases 04, 08, 11, 12, 16, now 18) |
| Inline wpdb in admin controller | 3rd recurrence (Phases 04, 11, now 18) |
| N+1 metrics loop | 5th recurrence (Phases 04, 07, 09, 15, now 18) |
| DDL outside Migrations.php | 3rd recurrence (Phases 08, 16, now 18) |
| No terminated-employee cleanup | 3rd recurrence (Phases 06, 09, now 18) |

## $wpdb SQL Safety

**34 total calls across 3 files. 0 SQL injection risks.** All data-bearing queries use `$wpdb->prepare()` or `$wpdb->insert()`/`$wpdb->update()`/`$wpdb->delete()`. The 2 inline queries in Admin_Pages.php (lines 283-284) are static with no user-controlled values.

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check: PASSED

- [x] `.planning/phases/18-departments-surveys-projects-audit/18-02-projects-findings.md` — confirmed created
- [x] Commit df74b18 — confirmed in git log
- [x] All 4 audit metrics (security, performance, duplication, logical) covered
- [x] Stub/incomplete assessment included
- [x] Every finding has severity + fix + file:line reference
- [x] Cross-module pattern table present
- [x] 34 wpdb calls catalogued
