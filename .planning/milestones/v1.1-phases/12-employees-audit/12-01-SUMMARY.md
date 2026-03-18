---
phase: 12-employees-audit
plan: "01"
subsystem: Employees
tags: [audit, security, performance, duplication, logical, employees]
dependency_graph:
  requires: []
  provides: [12-01-employee-profile-findings.md]
  affects: [EMP-SEC-001, EMP-SEC-002, EMP-SEC-003, EMP-SEC-004, EMP-SEC-005, EMP-PERF-001, EMP-PERF-002, EMP-PERF-003, EMP-PERF-004, EMP-DUP-001, EMP-DUP-002, EMP-DUP-003, EMP-DUP-004, EMP-LOGIC-001, EMP-LOGIC-002, EMP-LOGIC-003, EMP-LOGIC-004]
tech_stack:
  added: []
  patterns: [information_schema antipattern, dual-column hire_date/hired_at, information_schema table-existence check]
key_files:
  created:
    - .planning/phases/12-employees-audit/12-01-employee-profile-findings.md
  modified: []
decisions:
  - "28 actual DB execution calls found (plan estimated 80 — the discrepancy is that 80 counted all $wpdb-> references including ->prefix and ->prepare() lines; actual execution calls are 28)"
  - "WP user creation handler is correctly secured — create_users capability check + nonce + subscriber role assignment"
  - "hire_date / hired_at: display fallback pattern (line 175) is applied consistently; the edit form only updates hired_at, leaving hire_date to drift"
  - "8 information_schema.tables checks per page load — same antipattern as Phase 04, 08, 11"
  - "Two hardcoded Asia/Riyadh timezone references (format_time line 1531, month table lines 762/768) inconsistent with wp_timezone() used elsewhere"
metrics:
  duration: 4 minutes
  completed_date: "2026-03-16"
  tasks_completed: 2
  files_created: 1
---

# Phase 12 Plan 01: Employee Profile Audit Summary

Employee Profile admin page (1,982 lines, 28 DB execution calls) audited across security, performance, duplication, and logical dimensions — 17 findings, zero Critical, five High severity.

## What Was Done

Performed a complete code audit of `includes/Modules/Employees/Admin/class-employee-profile-page.php`, covering:
- All `$wpdb` execution calls catalogued with prepared/unprepared status
- Capability and nonce guards reviewed for all write paths
- WP user creation handler audited for privilege escalation
- Dual `hire_date`/`hired_at` pattern traced through all references
- Profile HTML output checked for escaping
- Performance hot path on page load analyzed
- Duplicate patterns identified across edit/view mode and badge rendering

## Findings Summary

**17 total findings** (0 Critical / 5 High / 8 Medium / 4 Low)

| ID | Severity | Category | Description |
|----|----------|----------|-------------|
| EMP-SEC-001 | High | Security | Menu capability uses `sfs_hr_attendance_view_team` role name instead of `sfs_hr.view` dotted capability |
| EMP-SEC-002 | High | Security | 1 raw `get_results()` at line 244 without `prepare()` — static query, no injection risk, but convention violation |
| EMP-SEC-003 | Medium | Security | 8 `information_schema.tables` existence checks per page load — same antipattern as Phase 04/08/11 |
| EMP-SEC-004 | Medium | Security | WP user creation handler embedded inside `render_page()` instead of dedicated `admin_post_*` hook |
| EMP-SEC-005 | Low | Security | `format_time()` hardcodes `Asia/Riyadh` instead of `wp_timezone()` |
| EMP-PERF-001 | High | Performance | 8 `information_schema` queries on every page load; should be cached per request |
| EMP-PERF-002 | High | Performance | `ensure_qr_token()`, `attendance_shifts_grouped()`, `get_emp_shift_history()` called unconditionally even in view mode |
| EMP-PERF-003 | Medium | Performance | Risk flag calculation fetches 30 days of sessions on every profile view without caching |
| EMP-PERF-004 | Low | Performance | Documents card runs 2 queries against `employee_documents` where 1 SQL with CASE would suffice |
| EMP-DUP-001 | Medium | Duplication | Date formatting closures `$fmt_input` and `$fmt_date` defined twice with near-identical logic |
| EMP-DUP-002 | Medium | Duplication | Shift display `$cs_parts` pattern duplicated in edit and view mode |
| EMP-DUP-003 | Medium | Duplication | `get_request_status_badge()` and `get_request_type_badge()` use identical `sprintf()` template — should share a helper |
| EMP-DUP-004 | Low | Duplication | Employee initials computation duplicated in header and edit mode photo sections |
| EMP-LOGIC-001 | High | Logical | Edit form saves `hired_at` only; `hire_date` column not synced — dual columns will diverge on edits |
| EMP-LOGIC-002 | Medium | Logical | Risk flag window uses PHP `date()` instead of `wp_date()` — off-by-one boundary on non-UTC servers |
| EMP-LOGIC-003 | Medium | Logical | Month summary table hardcodes `Asia/Riyadh` for in/out time display |
| EMP-LOGIC-004 | Low | Logical | `compute_status_from_punch()` duplicates Attendance module punch-type logic — will drift if new types added |

## Key Security Assessment

**WP User Creation (privilege escalation review):** CLEAN. The handler at lines 39-96 correctly enforces:
- `check_admin_referer()` nonce verification
- `current_user_can('create_users')` — WordPress core administrator capability
- Assigns `subscriber` role only (lowest WP role)
- No path to higher-privilege role assignment

**SQL injection risk:** 1 raw query at line 244. Static query with no user input — no injection vulnerability, but violates the project's prepare-everything convention.

**Capability gates on writes:** The `handle_save_edit`, `handle_regen_qr`, and `handle_toggle_qr` handlers all live in `Core/Admin.php` (not in this file) and have correct `Helpers::require_cap('sfs_hr.manage')` + nonce guards.

## $wpdb Call-Accounting Summary

- **Plan's stated count:** "80 $wpdb calls" (counts all `$wpdb->` references including `->prefix` and `->prepare()`)
- **Actual execution calls:** 28 (27 prepared, 1 raw static at line 244)
- **Actual `->prepare()` calls:** 26
- **Actual `->prefix` references:** 17
- **Total `$wpdb->` references:** 136

## Dual hire_date / hired_at Assessment

- Display: Line 175 uses `$emp['hired_at'] ?? ($emp['hire_date'] ?? '')` — correct fallback, applied once, used consistently via `$hire_date` variable throughout
- Edit form: Field named `hired_at` (line 497) — saves to `hired_at` column only
- **Gap:** `hire_date` column not updated when profile is edited via this form — divergence risk (EMP-LOGIC-001)

## Deviations from Plan

None — plan executed exactly as written. The `$wpdb` call count discrepancy (plan said 80, actual execution calls are 28) is a counting methodology difference — the plan's 80 likely counted all `$wpdb->` property accesses. The findings report documents this clearly.

## Self-Check

- [x] Findings file exists: `.planning/phases/12-employees-audit/12-01-employee-profile-findings.md`
- [x] All 28 DB execution calls catalogued in call-accounting table
- [x] Every finding has severity rating and fix recommendation
- [x] Dual hire_date/hired_at handling explicitly audited with dedicated subsection
- [x] WP user creation handler explicitly audited with verdict table
- [x] Finding IDs use EMP-SEC-NNN, EMP-PERF-NNN, EMP-DUP-NNN, EMP-LOGIC-NNN format
