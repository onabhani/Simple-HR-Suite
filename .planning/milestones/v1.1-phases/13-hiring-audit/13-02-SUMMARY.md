---
phase: 13-hiring-audit
plan: "02"
subsystem: Hiring
tags: [audit, security, hiring, candidates, trainees, pipeline]
dependency_graph:
  requires: [13-01-PLAN.md]
  provides: [13-02-hiring-admin-findings.md]
  affects: []
tech_stack:
  added: []
  patterns: [wpdb-call-accounting, pipeline-state-machine-audit, capability-audit, duplication-analysis]
key_files:
  created:
    - .planning/phases/13-hiring-audit/13-02-hiring-admin-findings.md
  modified: []
decisions:
  - "Plan estimated 54 $wpdb calls; actual count is 46 (30 in class-admin-pages.php, 16 in HiringModule.php) — discrepancy because plan likely counted {$wpdb->prefix} table-name tokens, not actual method calls"
  - "HiringModule.php reviewed in addition to class-admin-pages.php — required to assess conversion method security (called directly from handlers)"
  - "Audit-only milestone: no code changes — all findings documented for remediation phase"
metrics:
  duration_minutes: 4
  tasks_completed: 2
  files_created: 1
  completed_date: "2026-03-16"
---

# Phase 13 Plan 02: Hiring Admin Pages Audit Summary

**One-liner:** Hiring admin pages (class-admin-pages.php, 1,746 lines) audited — 21 findings including missing capability checks on all six POST handlers, manage_options misuse for approval gates, and no state-machine enforcement in the candidate approval pipeline.

## What Was Done

Performed a comprehensive 4-metric audit of the Hiring module's admin pages controller and its supporting HiringModule.php:

- **Files audited:** `class-admin-pages.php` (1,746 lines), `HiringModule.php` (718 lines, reviewed for conversion method security)
- **$wpdb calls:** 46 total catalogued (30 in admin pages, 16 in HiringModule); 2 unprepared raw-interpolation calls in HiringModule employee code generation
- **Findings:** 21 total — 4 Critical, 8 High, 6 Medium, 3 Low

## Key Findings

### Critical Issues (4)

1. **HADM-SEC-002** — All six POST handlers (`handle_add_candidate`, `handle_update_candidate`, `handle_candidate_action`, `handle_add_trainee`, `handle_update_trainee`, `handle_trainee_action`) verify nonces but have NO `current_user_can()` check. Any authenticated user can add/update/action candidates and trainees, including triggering employee creation.

2. **HADM-SEC-001** — Dept manager and GM approval capability gates use `current_user_can('manage_options')` with inline `// TODO` comments. Real HR managers with `sfs_hr.manage` cannot approve; only site admins can. The approval workflow is non-functional under normal HR role configuration.

3. **HADM-LOGIC-001** — `handle_candidate_action()` never checks the candidate's current status before applying a transition. Any workflow action can be submitted for any candidate in any status. Combined with the shared nonce (HADM-DUP-002), this allows pipeline stage skipping, status reversal on hired candidates, and double-action race conditions.

4. **HADM-SEC-003** — Employee code generation in both conversion methods uses raw string interpolation in `$wpdb->get_var()` (same HIR-SEC-001 antipattern from Phase 13-01). Currently safe due to hardcoded prefix but architecturally flagged Critical per project policy.

### High Issues (8)

- **HADM-SEC-004**: No allowlist validation of `workflow_action` POST parameter and no status-prerequisite check in handler
- **HADM-SEC-005**: `handle_update_candidate/trainee` accept entity IDs from POST with no existence verification
- **HADM-PERF-001**: No pagination on candidate/trainee list queries — all records loaded in one query
- **HADM-PERF-002**: Trainee form loads all active employees (potentially thousands) as supervisor dropdown options
- **HADM-DUP-001**: ~34% of file is structural duplication across 7 candidate/trainee method pairs
- **HADM-LOGIC-002**: Rejected candidates can be re-activated through pipeline; hired candidates can be re-rejected
- **HADM-LOGIC-003**: Trainee-to-candidate conversion has no transaction wrapper — orphan records on partial failure
- **HADM-LOGIC-004**: Direct-hire employee ID stored as embedded JSON in `notes` text field; edit form destroys it

## Pipeline Approval Chain Assessment

All 8 stage transitions in the candidate approval chain lack server-side capability gates in the handler. The UI shows dept/GM approval buttons only to `manage_options` users (wrong cap). No state-machine enforcement means any transition can be submitted for any candidate in any status. No double-action protection.

## $wpdb Call Summary

- **30 calls in class-admin-pages.php**: All prepared except 2 raw static queries (lines 248, 875-876 — department/employee dropdowns — safe)
- **16 calls in HiringModule.php**: All prepared except 2 (lines 348, 534 — employee code `get_var` with `{$prefix}%` interpolation)
- **Conditional prepare pattern** (lines 149, 779): Same antipattern from Phase 09 — safe in this context but architecturally inconsistent

## Duplication Analysis

~600 of 1,746 lines (~34%) are structural duplication between candidate and trainee subsystems. 7 paired method sets. WordPress user creation logic duplicated 3 times (add form, create_account action, convert_trainee_to_employee). Recommend: shared nonce+capability entry point, generic list renderer, and a `create_hr_user()` helper as the highest-ROI extractions.

## Deviations from Plan

### Scope Extension

**[Rule 3 - Scope] HiringModule.php reviewed alongside class-admin-pages.php**
- **Found during:** Task 1
- **Issue:** Several class-admin-pages.php handlers call `HiringModule::convert_candidate_to_employee()` and `HiringModule::convert_trainee_to_employee()` directly. Assessing the security of the admin handlers required reviewing these conversion methods (specifically the unprepared `get_var` calls and missing capability guards in HIR-SEC-001/002 from Phase 13-01).
- **Resolution:** Reviewed HiringModule.php (718 lines); found 2 additional unprepared queries confirming HADM-SEC-003; $wpdb call-accounting table extended to cover both files (46 total).

### $wpdb Count Discrepancy

**Plan stated "54 $wpdb calls"** — actual count is 46 across both files. The likely explanation: the plan counted every `{$wpdb->prefix}sfs_hr_*` table-name token (54 occurrences) rather than counting only distinct `$wpdb->method()` invocations. This does not affect audit completeness since all actual DB calls were catalogued.

## Cross-References to Prior Phases

| This Phase | Prior Phase | Pattern |
|-----------|-------------|---------|
| HADM-SEC-001 | Phase 07 (PERF manage_options) | manage_options misused for HR role-gated actions |
| HADM-SEC-002 | Phase 07 (PERF save_review) | Nonce-only guard without capability check |
| HADM-SEC-003 | Phase 13-01 (HIR-SEC-001) | Unprepared get_var with string interpolation |
| HADM-PERF-001 | Phase 08 (LADM-PERF-001) | No pagination on list queries |
| HADM-LOGIC-001 | Phase 10 (SETT-LOGIC-003) | No double-submission guard |
| HADM-LOGIC-003 | Phase 13-01 (HIR-LOGIC-001) | No transaction on conversion workflows |

## Self-Check: PASSED

- `.planning/phases/13-hiring-audit/13-02-hiring-admin-findings.md` — FOUND
- Commit `06591d9` — FOUND
- 51 HADM- finding references in findings file — FOUND
- All 46 $wpdb calls catalogued with prepared/unprepared status — VERIFIED
- Pipeline approval chain explicitly audited — VERIFIED
- Candidate/trainee duplication documented — VERIFIED
