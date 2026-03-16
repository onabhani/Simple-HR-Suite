---
phase: 04-core-frontend-audit
plan: "02"
subsystem: Frontend
tags: [audit, security, performance, xss, sql, auth, frontend]
dependency_graph:
  requires: [04-01-PLAN.md]
  provides: [04-02-frontend-audit-findings.md]
  affects: []
tech_stack:
  added: []
  patterns: [code-review, security-audit]
key_files:
  created:
    - .planning/phases/04-core-frontend-audit/04-02-frontend-audit-findings.md
  modified: []
decisions:
  - "27 findings total across 20 Frontend/ files: 3 Critical, 11 High, 8 Medium, 5 Low"
  - "Critical issues are 3 unprepared SQL queries (DashboardTab, ApprovalsTab, EmployeesTab) — no active injection risk but PHPCS violations"
  - "Two tab renderers (OverviewTab, ProfileTab) lack cross-employee ownership checks — patched by other tabs with user_id match"
  - "TeamTab logic bug: is_manager_only hardcoded true, HR/GM/Admin incorrectly scoped to managed depts only"
  - "OverviewTab fires 14+ DB queries per load — worst performance finding in Frontend/"
  - "GET-parameter flash messages in LoansTab expose social engineering vector via url-crafted error text"
metrics:
  duration: "3 minutes"
  completed_date: "2026-03-16"
  tasks_completed: 2
  files_created: 1
  files_modified: 0
---

# Phase 04 Plan 02: Frontend/ Audit Summary

**One-liner:** 27-finding security and performance audit of all 20 Frontend/ PHP files covering XSS, missing ownership checks, unprepared SQL, 14-query OverviewTab hot path, and TeamTab logic bug.

## What Was Done

Conducted a full manual code review of all 20 PHP files in `includes/Frontend/` (~11.2K lines total). Files were audited against four metric categories: XSS/output escaping, auth/access control, nonce validation, SQL injection (security), query count and patterns (performance), and code duplication/logic errors.

## Key Findings by Category

### Security — Critical
- **FE-SQL-001** `DashboardTab:L44` — `SELECT COUNT(*) WHERE status = 'active'` without `$wpdb->prepare()`
- **FE-SQL-002** `ApprovalsTab:L91-L115` — Loan approval queries not prepared (3 queries)
- **FE-SQL-003** `EmployeesTab:L91-99` — Stats and department queries not prepared

All three are WPCS violations with no active injection risk (all literals/safe values), but must be fixed before any refactor touches these files.

### Security — High
- **FE-AUTH-001** `OverviewTab:L23` — No cross-employee ownership check. All other personal tabs (`LeaveTab`, `LoansTab`, `PayslipsTab`, `ResignationTab`, `SettlementTab`) correctly compare `(int)($emp['user_id'] ?? 0) !== get_current_user_id()`. OverviewTab and ProfileTab are missing this.
- **FE-AUTH-002** `ProfileTab:L21` — Same as AUTH-001; exposes salary, national ID, passport, emergency contacts.
- **FE-AUTH-003** `TeamTab:L45` — Logic bug: `$is_manager_only = true` is hardcoded, causing HR/GM/Admin to see "No departments assigned" instead of org-wide employees.
- **FE-NONCE-001** `LoansTab:L99` — `$_GET['error']` is `urldecode()`'d and displayed (escaped, so not XSS but social engineering vector).
- **FE-PERF-001** `OverviewTab:L59-233` — 14 DB queries per tab load; batching could reduce to 4.

### Performance
- Role resolver fires 5 queries per page load with no per-request cache (`Role_Resolver::resolve()`).
- Asset query in main shortcode handler is LIMIT 200 and fires before tab dispatch.
- `SickLeaveReminder` scans full employment history on every Overview/Leave load.
- `LoansTab` fetches all loans with no LIMIT.

### Logic
- Profile completion logic duplicated in 3 files (Shortcodes, OverviewTab, ProfileTab).
- Large dead code block in `Shortcodes.php` (L483-L1283, guarded by `if (false)`).
- `Tab_Dispatcher::$tab_map` and `Navigation::tab_has_renderer()` maintain parallel tab lists.

## Tab Access Control Matrix Summary

12 of 13 tabs have correct role-based entry guards. The two tabs with missing ownership checks are OverviewTab and ProfileTab (not guarded by user_id comparison). TeamTab has a logic bug. All POST-action tabs have nonces.

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check

- [x] Findings report created at `.planning/phases/04-core-frontend-audit/04-02-frontend-audit-findings.md`
- [x] Report has sections for XSS, Auth, Nonce, SQLi, Performance, Duplication/Logic
- [x] All 20 Frontend/ files appear in Files Reviewed table
- [x] Tab Access Control Matrix covers all 14 tab files
- [x] Every finding has severity, file:line, description, evidence, fix recommendation
- [x] Task commit exists: `99a229f`
