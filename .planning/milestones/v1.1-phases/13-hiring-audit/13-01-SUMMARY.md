---
phase: 13-hiring-audit
plan: "01"
subsystem: Hiring
tags: [audit, security, hiring, sql-injection, capability-check, toctou]
dependency_graph:
  requires: []
  provides: [13-01-hiring-module-findings.md]
  affects: [HiringModule.php, Admin/class-admin-pages.php]
tech_stack:
  added: []
  patterns: [wpdb-call-accounting, conversion-workflow-audit, capability-guard-review]
key_files:
  created:
    - .planning/phases/13-hiring-audit/13-01-hiring-module-findings.md
  modified: []
decisions:
  - HiringModule.php install() is clean — no bare ALTER TABLE, no information_schema (unlike Loans Phase 08, Core Phase 04)
  - Two employee code generation queries are unprepared ($prefix hardcoded today but Critical antipattern)
  - All three conversion methods (trainee-to-candidate, trainee-to-employee, candidate-to-employee) are missing capability checks
  - Conversion workflows are non-atomic with TOCTOU duplicate-conversion race
  - 75% code duplication between convert_trainee_to_employee and convert_candidate_to_employee
metrics:
  duration: "7 minutes"
  completed_date: "2026-03-16"
  tasks_completed: 2
  files_created: 1
---

# Phase 13 Plan 01: HiringModule Orchestrator Audit Summary

**One-liner:** HiringModule.php has 3 Criticals — unprepared employee code queries, missing capability checks on all 3 conversion methods, and non-atomic conversion workflows with TOCTOU race conditions.

## What Was Done

Performed a full security, performance, duplication, and logical audit of `includes/Modules/Hiring/HiringModule.php` (717 lines, 44 $wpdb references) and cross-referenced findings against `Admin/class-admin-pages.php`.

## Findings Summary

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 2 | 2 | 1 | 0 | 5 |
| Performance | 0 | 1 | 2 | 1 | 4 |
| Duplication | 0 | 2 | 1 | 0 | 3 |
| Logical | 1 | 3 | 1 | 1 | 6 |
| **Total** | **3** | **8** | **5** | **2** | **18** |

## Key Findings

**Critical:**
- HIR-SEC-001: Two `get_var()` calls use raw string interpolation for employee code generation (`"... LIKE '{$prefix}%'"`) — same antipattern as Phase 04/08/11
- HIR-SEC-002: All three conversion methods (`convert_trainee_to_candidate`, `convert_trainee_to_employee`, `convert_candidate_to_employee`) are `public static` with **no `current_user_can()` check** — any authenticated user can trigger employee creation
- HIR-LOGIC-001: Conversion workflows are not wrapped in transactions — WP user created, then employee INSERT fails → orphan WP user; or employee INSERT succeeds but status UPDATE fails → same source record can be converted twice

**High:**
- HIR-SEC-003: Plaintext password sent in welcome email (should use password-reset link instead)
- HIR-SEC-004: All 6 admin_post handlers in class-admin-pages.php lack `current_user_can()` — nonce alone is insufficient
- HIR-LOGIC-002: No atomic duplicate-conversion guard — two concurrent hire requests both pass status check before either updates it
- HIR-LOGIC-003: Employee code generation uses `substr($last, -4)` — corrupts once employee count exceeds 999
- HIR-DUP-001: ~75% code duplication between `convert_trainee_to_employee` and `convert_candidate_to_employee`
- HIR-DUP-002: `notify_hr_trainee_hired` and `notify_hr_new_hire` are near-identical

## install() DDL Assessment

`install()` is **clean**:
- Uses `dbDelta()` for all DDL — safe and idempotent
- No `ALTER TABLE` (Critical antipattern from Phase 04/08)
- No `SHOW TABLES` or `information_schema` queries (Critical antipattern from Phase 04/08/11)

## $wpdb Summary

44 total $wpdb references in HiringModule.php. Of 14 actual query operations:
- 12 are prepared/safe (using `$wpdb->prepare()`, `$wpdb->insert()`, or `$wpdb->update()`)
- **2 are unprepared** (lines 348, 534 — both employee code `get_var()` calls)

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check

- [x] Findings file exists at `.planning/phases/13-hiring-audit/13-01-hiring-module-findings.md`
- [x] Contains 31 `##` section headers
- [x] Contains 27 `HIR-` finding ID references
- [x] All 44 $wpdb references catalogued in call-accounting table
- [x] All 3 conversion methods audited for capability checks and atomicity
- [x] install() DDL checked for Phase 04/08 antipatterns (clean)
- [x] Commit 02018a6 exists
