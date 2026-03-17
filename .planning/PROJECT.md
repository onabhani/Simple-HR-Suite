# Simple HR Suite — Code Quality & Fixes

## What This Is

A comprehensive WordPress HR plugin managing employees, departments, leave, attendance, payroll, loans, and more for Saudi/Arabic-speaking organizations. Through v1.0-v1.2, the plugin has been structurally refactored (Attendance module), fully audited (662 findings across 19 modules), and hardened against all Critical/High auth and access-control vulnerabilities.

## Core Value

Reliable, secure HR operations for Saudi organizations — built on WordPress with direct `$wpdb` queries, no ORM, no external dependencies.

## Requirements

### Validated

- ✓ AttendanceModule extracted to clean 434-line orchestrator — v1.0
- ✓ Full security audit of Core + Frontend (~25K lines) — v1.1
- ✓ Full audit of all 19 modules for security, performance, duplication, logical issues — v1.1
- ✓ 662 findings documented with severity ratings (Critical/High/Medium/Low) and fix recommendations — v1.1
- ✓ Cross-module finding wiring validated — v1.1
- ✓ 7 recurring antipatterns catalogued with recurrence counts — v1.1
- ✓ All attendance REST endpoints require authentication — v1.2
- ✓ Kiosk token hashes replaced with HMAC-SHA-256 rotating nonces — v1.2
- ✓ Leave approval/cancellation handlers enforce capability with scoped nonces — v1.2
- ✓ is_hr_user() requires explicit HR assignment, not bare manager role — v1.2
- ✓ Hiring handlers capability-gated with role allowlist blocking admin escalation — v1.2
- ✓ Welcome emails use password reset links, no plaintext passwords — v1.2
- ✓ Loans nonce-before-data ordering enforced, DOM nonce exposure eliminated — v1.2
- ✓ Performance REST endpoints enforce department scope for managers — v1.2
- ✓ Frontend tabs verify employee ownership before rendering PII — v1.2
- ✓ TeamTab shows org-wide data for HR/GM/Admin roles — v1.2
- ✓ Asset export row-limited, invoice upload MIME-allowlisted — v1.2
- ✓ Resignation views department-scoped, redirect URL validated — v1.2
- ✓ Settlement, Payroll, Employees capability format fixed to dotted sfs_hr.* — v1.2

### Active

- [ ] Fix unprepared SQL queries across 11 modules (SQL-01 through SQL-04)
- [ ] Correct Settlement EOS formula from UAE to Saudi Article 84 (DATA-01)
- [ ] Fix Leave balance corruption on approval (DATA-02)
- [ ] Fix Loans monthly_installment column missing (DATA-03)
- [ ] Fix N+1 query patterns across 8+ modules (PERF-01)
- [ ] Fix TOCTOU races in concurrent submissions (LOGIC-01)
- [ ] Fix state machine gaps (LOGIC-02)
- [ ] Fix Saudi weekend bug — Friday-only, missing Saturday (LOGIC-03)

### Out of Scope

- Medium/Low severity findings — address in future milestones as needed
- JavaScript/CSS audit — v1.1 was PHP-only audit
- Unit/integration test creation — separate effort
- New feature development — fix-only milestones until stability
- Third-party vendor code in `assets/vendor/` — not our code
- Mobile app — web-first approach, PWA works on mobile

## Context

Shipped v1.2 with 20 files changed across 9 modules.
Tech stack: WordPress, PHP 8.2+, `$wpdb` direct queries, no ORM.
REST API namespace: `sfs-hr/v1`. Capabilities: dotted `sfs_hr.*` format.
19 modules ranging from 414 lines (PWA) to 18,218 lines (Attendance).
Core + Frontend shared code: ~25K lines.
No CI/CD, no test suite, no Composer/npm.

Known tech debt from v1.2:
- Performance REST `get_managed_department_ids()` missing `AND active = 1` filter
- `sfs_hr_payslip_view` capability declared but never assigned to any role
- Stale docblock on `is_hr_user()` mentioning removed sfs_hr_manager role

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Audit-only v1.1, no fixes | Keep scope manageable | ✓ Good — completed full audit in 2 days |
| Review order by size/risk | Largest modules have most surface area | ✓ Good — found highest-severity issues in large modules first |
| Batch small modules (Ph 18-19) | Under 1.3K lines each, efficient grouping | ✓ Good — covered 6 modules in 2 phases |
| 4 metrics per module | Security + performance + duplication + logical | ✓ Good — comprehensive coverage |
| Saudi Labor Law EOS formula wrong | Settlement uses UAE 21-day formula | ⚠️ Revisit — requires legal confirmation before fix |
| v1.2 scope: auth-only fixes | Focus on most critical security gaps first | ✓ Good — 32 auth gaps closed in 1 day |
| Capability-before-nonce pattern | Fails fast without revealing nonce validity | ✓ Good — consistent across Leave, Hiring, Loans, Assets |
| HMAC-SHA-256 for kiosk tokens | Prevents offline rainbow table attacks on QR tokens | ✓ Good — rotating nonce makes precomputation infeasible |
| Role allowlist for hiring | Blocks administrator escalation during conversion | ✓ Good — simple, auditable security boundary |
| TeamTab level < 40 threshold | HR/GM/Admin see org-wide; manager stays dept-scoped | ✓ Good — matches Role_Resolver level hierarchy |

## Constraints

- **PHP 8.2+** target
- **No Composer/npm** — vendor assets committed directly
- **Saudi labor law** — Settlement, Leave tenure, weekend calculations must comply
- **Backward compatibility** — dual hire_date/hired_at, existing hooks/filters preserved
- **No CI/CD** — manual deployments, changes must be low-risk

---
*Last updated: 2026-03-17 after v1.2 milestone*
