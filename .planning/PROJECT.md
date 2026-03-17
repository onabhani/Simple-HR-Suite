# Simple HR Suite — Code Quality & Fixes

## What This Is

A comprehensive WordPress HR plugin managing employees, departments, leave, attendance, payroll, loans, and more for Saudi/Arabic-speaking organizations. The v1.1 code audit has completed a full security, performance, duplication, and logical review of ~87K lines of PHP across 19 modules + Core/Frontend, producing 662 actionable findings.

## Core Value

Reliable, secure HR operations for Saudi organizations — built on WordPress with direct `$wpdb` queries, no ORM, no external dependencies.

## Current Milestone: v1.2 Critical/High Fix Implementation

**Goal:** Fix all 93 Critical and High severity findings from the v1.1 audit — security gaps, data integrity bugs, and performance antipatterns across 11 modules.

**Target features:**
- Fix 20 Critical findings (auth gaps, unauthenticated endpoints, SQL injection, unprepared queries)
- Fix 73 High findings (missing capability checks, N+1 queries, unprepared SQL, data corruption, TOCTOU races)
- Correct Settlement EOS formula from UAE to Saudi Article 84
- Fix Leave balance corruption on approval
- Guard Hiring conversion methods with capability checks
- Eliminate information_schema queries on admin_init across 9 modules
- Replace bare ALTER TABLE with prepared statements

## Requirements

### Validated

- ✓ AttendanceModule extracted to clean 434-line orchestrator — v1.0
- ✓ Full security audit of Core + Frontend (~25K lines) — v1.1
- ✓ Full audit of all 19 modules for security, performance, duplication, logical issues — v1.1
- ✓ 662 findings documented with severity ratings (Critical/High/Medium/Low) and fix recommendations — v1.1
- ✓ Cross-module finding wiring validated — v1.1
- ✓ 7 recurring antipatterns catalogued with recurrence counts — v1.1

### Active

- [ ] Fix 20 Critical severity findings across Core, Attendance, Leave, Loans, Assets, Hiring, Resignation
- [ ] Fix 73 High severity findings across all 11 affected modules
- [ ] Correct Settlement EOS formula to Saudi Article 84
- [ ] Fix Leave balance corruption (opening/carried overwritten on approval)
- [ ] Guard all unprotected endpoints and handlers with capability checks
- [ ] Replace information_schema queries with proper migration patterns
- [ ] Replace bare ALTER TABLE with $wpdb->prepare() or add_column_if_missing()

### Out of Scope

- Implementing audit fixes was out of scope for v1.1 — fixes are the next milestone
- Unit/integration test creation — separate effort
- Third-party vendor code in `assets/vendor/` — not our code
- JavaScript/CSS audit — v1.1 focused on PHP backend only

## Context

Tech stack: WordPress, PHP 8.2+, `$wpdb` direct queries, no ORM.
REST API namespace: `sfs-hr/v1`. Capabilities: dotted `sfs_hr.*` format.
19 modules ranging from 414 lines (PWA) to 18,218 lines (Attendance).
Core + Frontend shared code: ~25K lines.
No CI/CD, no test suite, no Composer/npm.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Audit-only v1.1, no fixes | Keep scope manageable | ✓ Good — completed full audit in 2 days |
| Review order by size/risk | Largest modules have most surface area | ✓ Good — found highest-severity issues in large modules first |
| Batch small modules (Ph 18-19) | Under 1.3K lines each, efficient grouping | ✓ Good — covered 6 modules in 2 phases |
| 4 metrics per module | Security + performance + duplication + logical | ✓ Good — comprehensive coverage |
| Saudi Labor Law EOS formula wrong | Settlement uses UAE 21-day formula | ⚠️ Revisit — requires legal confirmation before fix |

## Constraints

- **PHP 8.2+** target
- **No Composer/npm** — vendor assets committed directly
- **Saudi labor law** — Settlement, Leave tenure, weekend calculations must comply
- **Backward compatibility** — dual hire_date/hired_at, existing hooks/filters preserved
- **No CI/CD** — manual deployments, changes must be low-risk

---
*Last updated: 2026-03-17 after v1.2 milestone started*
