---
phase: 18-departments-surveys-projects-audit
verified: 2026-03-17T05:10:00Z
status: passed
score: 5/5 success criteria verified
re_verification: false
---

# Phase 18: Departments + Surveys + Projects Audit Verification Report

**Phase Goal:** Security, performance, duplication, and logical issues in Departments (~775 lines), Surveys (~1.3K lines), and Projects (~1.2K lines) are documented
**Verified:** 2026-03-17
**Status:** PASSED
**Re-verification:** No — initial verification

---

## Goal Achievement

### Success Criteria (from ROADMAP.md)

| # | Criterion | Status | Evidence |
|---|-----------|--------|---------|
| 1 | Department manager and HR responsible assignment logic is audited for capability bypass | VERIFIED | DEPT-SEC-002 (High) and DEPT-SEC-003 (Medium) document `manager_user_id`/`hr_responsible_user_id` accepted from POST with `get_user_by` existence check but no role/capability validation; fix recommendation provided at DepartmentsModule.php:506-512 |
| 2 | Survey response endpoints checked for missing auth — unauthenticated submissions or cross-employee data access | VERIFIED | SURV-SEC-001 documents `handle_submit_response` using `is_user_logged_in()` instead of a capability check; confirmed server-side `Helpers::current_employee_id()` derivation prevents IDOR; STAB-SEC-002 flags `$emp_id` trust in Tab_Dispatcher chain |
| 3 | Project assignment logic checked for missing ownership validation | VERIFIED | PROJ-SEC-005 (Medium) documents missing duplicate-assignment guard; PROJ-LOGIC-003 (Medium) documents no overlap check across projects; positive finding confirmed: `handle_remove_employee()`/`handle_remove_shift()` correctly cross-validate `project_id` before deletion |
| 4 | All `$wpdb` queries across all three modules confirmed prepared or flagged | VERIFIED | Complete query catalogues produced: 9 calls in DepartmentsModule (all safe), 27 call sites in SurveysModule (all prepared/static), 5 calls in SurveysTab (all safe), 34 calls in 3 Projects files (0 injection risks) — total 75 call sites catalogued |
| 5 | A single findings report covering Departments, Surveys, and Projects exists with per-module severity ratings and fix recommendations | VERIFIED (note below) | Two findings files produced: `18-01-departments-surveys-findings.md` (417 lines, 25 sections) and `18-02-projects-findings.md` (306 lines, 29 sections). Both have structured severity tables, per-finding file:line references, and concrete fix recommendations. The ROADMAP criterion says "single" but the phase plan explicitly outputs two separate files — coverage is complete and findings are structured identically |

**Score:** 5/5 criteria verified

---

## Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `.planning/phases/18-departments-surveys-projects-audit/18-01-departments-surveys-findings.md` | Departments and Surveys audit findings containing `## Security Findings` | VERIFIED | File exists, 417 lines, 25 `##` section headers; contains Security, Performance, Duplication, and Logical sections for Departments, Surveys, and SurveysTab; full wpdb catalogue present |
| `.planning/phases/18-departments-surveys-projects-audit/18-02-projects-findings.md` | Projects module audit findings containing `## Security Findings` | VERIFIED | File exists, 306 lines, 29 `##` section headers; contains Security, Performance, Duplication, Logical, Stub/Incomplete, and Cross-Module sections; full wpdb catalogue present |

---

## Key Link Verification

| From | To | Via | Status | Details |
|------|----|----|--------|---------|
| `includes/Modules/Departments/DepartmentsModule.php` | `18-01-departments-surveys-findings.md` | manual code review | VERIFIED | File:line references confirmed accurate: line 506-512 (`manager_user_id` POST), line 33 (SELECT *), line 572-577 (`wp_verify_nonce` inconsistency), line 589-619 (delete handler), line 241-248 (N+1 manager lookup) — all verified against actual source |
| `includes/Modules/Surveys/SurveysModule.php` | `18-01-departments-surveys-findings.md` | manual code review | VERIFIED | Line 880-883 (`is_user_logged_in` guard), line 691-737 (`handle_survey_save` no status check before update), line 905-936 (TOCTOU race) — all spot-checked against actual source and confirmed accurate |
| `includes/Modules/Surveys/Frontend/SurveysTab.php` | `18-01-departments-surveys-findings.md` | manual code review | VERIFIED | Lines 65-67 (`view_survey_id` not checked against `$available`) confirmed in source — STAB-LOGIC-001 claim matches actual code |
| `includes/Modules/Projects/ProjectsModule.php` | `18-02-projects-findings.md` | manual code review | VERIFIED | File exists, 3 DDL `$wpdb->query()` calls (lines 57, 77, 92) confirmed static; capability gate at line 32 confirmed `sfs_hr.manage` |
| `includes/Modules/Projects/Admin/class-admin-pages.php` | `18-02-projects-findings.md` | manual code review | VERIFIED | 6 POST handlers all confirmed with `current_user_can('sfs_hr.manage')` + `check_admin_referer()` dual guard; lines 283-284 inline queries confirmed static |
| `includes/Modules/Projects/Services/class-projects-service.php` | `18-02-projects-findings.md` | manual code review | VERIFIED | `information_schema.tables` query at line 218-225 confirmed in source — PROJ-SEC-001 Critical finding is accurate |

---

## Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|---------|
| SML-01 | 18-01-PLAN.md | Audit Departments module (~775 lines) | SATISFIED | DepartmentsModule.php fully audited: 9 wpdb calls catalogued, 4 security findings (DEPT-SEC-001 through 004), 2 performance findings, 1 duplication, 3 logical findings; all 4 audit metrics addressed |
| SML-02 | 18-01-PLAN.md | Audit Surveys module (~1.3K lines) | SATISFIED | SurveysModule.php + SurveysTab.php fully audited: 32 wpdb call sites catalogued, 1 Critical + 5 High + 5 Medium + 4 Low findings across 4 audit categories; bootstrap DDL confirmed using `dbDelta()` correctly |
| SML-03 | 18-02-PLAN.md | Audit Projects module (~1.2K lines) | SATISFIED | 3 Projects files fully audited: 34 wpdb calls catalogued, 1 Critical + 5 High + 5 Medium + 3 Low findings; stub/incomplete assessment with 5 categories documented; cross-module pattern table produced |

**Orphaned requirements check:** REQUIREMENTS.md maps SML-01/SML-02/SML-03 to Phase 18 and marks all three complete. No requirements assigned to Phase 18 are absent from any plan. SML-04/SML-05/SML-06 are assigned to Phase 19 — not orphaned.

---

## Commit Verification

| Commit | Status | Details |
|--------|--------|---------|
| `4c867e1` (Departments + Surveys findings) | VERIFIED | Commit exists; adds `18-01-departments-surveys-findings.md` (+417 lines); message matches claimed work |
| `df74b18` (Projects findings) | VERIFIED | Commit exists; adds `18-02-projects-findings.md` (+306 lines); message matches claimed work |

---

## Anti-Patterns Found in Findings Files

No anti-patterns (placeholder content, stub sections, empty implementations) were found in the findings files themselves. Verification of key claims against the actual source code confirms accuracy:

- SURV-LOGIC-002 (`handle_survey_save` updates without checking status): Confirmed — line 724 shows `$wpdb->update( $table, $data, [ 'id' => $id ] )` with no prior status fetch when `$id > 0`
- DEPT-SEC-002 (manager assignment without role check): Confirmed — line 507 shows `get_user_by( 'id', $mgr )` existence check only, no `has_cap()` validation
- PROJ-SEC-001 (`information_schema` in Projects_Service): Confirmed — line 218-219 contains the exact query cited
- STAB-LOGIC-001 (out-of-scope survey form render): Confirmed — line 65-67 shows `$view_survey_id` check against `$completed_ids` only, not against `$available`
- DepartmentsModule bootstrap CLEAN: Confirmed — grep found zero occurrences of `ALTER TABLE`, `information_schema`, or `SHOW TABLES`
- Projects dual-guard on all 6 POST handlers: Confirmed — grep shows `current_user_can('sfs_hr.manage')` immediately before `check_admin_referer()` on all 6 handlers

---

## Human Verification Required

None. All phase deliverables are code-review documents (findings reports). Their content has been spot-verified against the actual source files via targeted grep and line-range reads. No runtime behavior or visual rendering is involved.

---

## Summary

Phase 18 achieved its goal. Both findings files are substantive (417 and 306 lines respectively), accurately reference actual source code, cover all 4 audit metrics for all 6 audited files, catalogue 75 total wpdb call sites, and satisfy all 5 ROADMAP success criteria. Requirements SML-01, SML-02, and SML-03 are fully satisfied with evidence.

The only minor note is that SC5 says "a single findings report" but two separate files were produced — this matches the two-plan structure and provides complete coverage; it is not a gap.

**Notable findings documented in this phase:**
- PROJ-SEC-001 (Critical): `information_schema` antipattern in Projects_Service — 6th recurrence across the audit series; fix recommendation provided
- SURV-LOGIC-002 (High): Published survey metadata editable via crafted POST — same server-side handler gap as Phase 13 Hiring module
- DEPT-SEC-002 (High): Department manager assignment grants `sfs_hr.leave.review` capability dynamically but accepts any WP user without role validation
- STAB-LOGIC-001 (Medium): SurveysTab renders survey form to out-of-scope employees (defence-in-depth gap; submission correctly rejected server-side)
- Projects module positive patterns: transactional deletes, assignment ownership verification, clean dual-guard on all handlers — best-in-series for handler security

---

_Verified: 2026-03-17_
_Verifier: Claude (gsd-verifier)_
