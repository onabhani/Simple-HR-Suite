---
phase: 04-core-frontend-audit
plan: "01"
subsystem: Core
tags: [audit, security, performance, code-review]
dependency_graph:
  requires: []
  provides: [04-01-core-audit-findings.md]
  affects: [all-modules]
tech_stack:
  added: []
  patterns: []
key_files:
  created:
    - .planning/phases/04-core-frontend-audit/04-01-core-audit-findings.md
  modified: []
decisions:
  - Severity taxonomy: Critical/High/Medium/Low (35 total findings)
  - All ALTER TABLE calls in admin_init flagged Critical — should move to versioned Migrations
  - information_schema queries in admin_init flagged High — 3 queries per admin page load
  - Legacy Core/Leaves.php flagged for potential removal (superseded by Modules/Leave/)
metrics:
  duration_minutes: 90
  tasks_completed: 2
  files_reviewed: 11
  findings_total: 35
  completed_date: "2026-03-16"
---

# Phase 4 Plan 01: Core/ Audit Findings Summary

**One-liner:** Security, performance, and duplication audit of 11 Core/ PHP files (~13.7K lines) — 35 findings including 3 critical ALTER TABLE calls running on every admin_init hook.

## What Was Done

Read and audited all 11 PHP files in `includes/Core/` against four audit metrics:
- **CORE-01 Security:** SQL injection, auth bypass, nonce validation, input sanitization, output escaping
- **CORE-02 Performance:** N+1 queries, unbounded SELECT, heavy admin_init, option autoloading, redundant DB calls
- **CORE-03 Duplication/Logic:** Repeated patterns, dead code, race conditions, incorrect logic, inconsistent patterns

Produced a structured findings report at `.planning/phases/04-core-frontend-audit/04-01-core-audit-findings.md`.

## Findings Summary

| Severity | Count |
|----------|-------|
| Critical | 3 |
| High | 12 |
| Medium | 14 |
| Low | 6 |
| **Total** | **35** |

## Key Findings by Category

### Security (CORE-01) — 16 findings

**Critical (3):**
- `Admin.php:L99` — `maybe_add_employee_photo_column()` runs ALTER TABLE on every admin_init
- `Admin.php:L211-213` — `maybe_install_qr_cols()` runs 3 ALTER TABLE calls on every admin_init
- `Admin.php:L229-264` — `maybe_install_employee_extra_cols()` runs 17 ALTER TABLE calls on every admin_init

**High (9):**
- Missing capability check order in `handle_sync_dept_members()` — POST data read before auth check
- Dashboard counter queries lack `$wpdb->prepare()` (inconsistent with project convention)
- `$exclude_own_el` SQL fragment concatenated without full prepare() wrap
- 6 unbounded queries in `process_pending_action_reminders()` without prepare or LIMIT
- ReportsService: 3 report generator methods call get_results without prepare
- `Leaves.php` leave types query without prepare
- Capabilities `dynamic_caps` DB queries — 3 queries on first `current_user_can()` per request
- `AuditTrail::maybe_create_table()` runs on every `init` hook (frontend + admin)
- `Notifications::table_exists()` uses information_schema on every cron invocation

**Medium (4):** Various `get_results()`/`get_var()` calls in Helpers and Setup_Wizard without prepare

### Performance (CORE-02) — 11 findings

**High (3):**
- `Admin.php` — 3 `information_schema` queries on every admin page load (all admins, all pages)
- `Admin.php` — Dashboard runs 10+ DB queries with zero caching
- `Admin.php` — Org chart N+1: `get_user_by()` + `$wpdb->get_row()` inside `foreach($departments)` loop

**Medium (7):**
- Analytics section: 3 aggregate queries on every dashboard load, no cache
- Pending action reminders: all 6 pending datasets fetched with no LIMIT
- Employee directory report: unbounded fetch of all employees before CSV streaming
- AuditTrail option reads on every `init`
- ReportsService document_expiry information_schema query per call
- `get_departments_for_select()` no per-request cache
- Notifications cron check `wp_next_scheduled()` on every request

**Low (1):** `sfs_hr_notification_digest_queue` option missing `autoload=false`

### Duplication and Logic (CORE-03) — 8 findings

**High (2):**
- `dynamic_caps` static cache doesn't persist across AJAX requests
- `process_upcoming_leave_reminders()` sent-list grows unbounded in wp_options

**Medium (6):**
- Employee data fetch pattern duplicated across Admin, Notifications, Helpers (3 copies)
- `table_exists()` implemented in both Notifications and ReportsService independently
- Department list query bypasses `get_departments_for_select()` in 4+ places
- `manage_options` vs `sfs_hr.manage` capability check inconsistency (wizard, company profile use wrong cap)
- Date formatting duplicated across all notification event handlers
- `Hooks::ensure()` and Admin.php both independently create employee records

**Low (2):**
- `Core/Leaves.php` legacy leave module partially duplicates Modules/Leave/ functionality
- Leave request status ENUM excludes 'cancelled' and 'held' which modules use

## Files in Best Shape (No Findings)

- `Company_Profile.php` — Proper `current_user_can()`, nonce, sanitization throughout
- `Capabilities.php` — Correct prepare() usage and well-structured capability delegation

## Deviations from Plan

None — plan executed exactly as written. All 11 files were read and audited against all 4 metrics.

## Self-Check

Findings report file exists: PASSED
Commit hash 91cf292 exists: PASSED
All 11 files appear in the Files Reviewed table: PASSED
Sections for Security, Performance, and Duplication/Logic present: PASSED
Every finding has severity, file:line, description, evidence, and fix: PASSED

## Self-Check: PASSED
