# Simple HR Suite — Code Quality & Fixes

## What This Is

A comprehensive WordPress HR plugin managing employees, departments, leave, attendance, payroll, loans, and more for Saudi/Arabic-speaking organizations. Through v1.0-v1.3, the plugin has been structurally refactored (Attendance module), fully audited (662 findings across 19 modules), hardened against all Critical/High auth vulnerabilities, and all Critical/High SQL injection, data integrity, performance, and logic/workflow findings have been fixed.

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
- ✓ All SQL queries use $wpdb->prepare() — no raw string interpolation in SQL — v1.3
- ✓ Migration helpers use idempotent SHOW-based patterns, no bare ALTER TABLE — v1.3
- ✓ Settlement EOS formula corrected to Saudi Article 84 with trigger_type support — v1.3
- ✓ Leave balance preserved on approval, tenure steps at anniversary — v1.3
- ✓ Payroll loan deductions use correct column, installment rounding aligned — v1.3
- ✓ N+1 queries eliminated, dashboard counters cached, all queries bounded — v1.3
- ✓ TOCTOU races closed with transactions/row locks on leave and loan operations — v1.3
- ✓ State machine guards block invalid transitions in Leave, Settlement, Performance — v1.3

### Active

(No active requirements — define next milestone to scope.)

### Planned for Future Milestones

- [ ] **PHPUnit test suite** — unit tests for critical logic (Settlement EOS, Leave transitions, balance preservation, ref number generation) + integration tests for hardened paths (SQL injection defense, transaction wrapping, state guard termination)
- [ ] **JavaScript/CSS audit** — v1.1 was PHP-only; frontend code has never been audited for security, performance, or quality issues

### Out of Scope

- Medium/Low severity findings — address in future milestones as needed
- New feature development — fix-only milestones until stability
- Third-party vendor code in `assets/vendor/` — not our code
- Mobile app — web-first approach, PWA works on mobile

## Context

Shipped v1.3 with 44 files changed, +2500/-1688 lines across all 19 modules.
Tech stack: WordPress, PHP 8.2+, `$wpdb` direct queries, no ORM.
REST API namespace: `sfs-hr/v1`. Capabilities: dotted `sfs_hr.*` format.
Codebase: ~92K lines PHP across 19 modules + Core/Frontend.
No CI/CD, no test suite, no Composer/npm.

Known tech debt (cosmetic only):
- Performance REST `get_managed_department_ids()` missing `AND active = 1` filter
- `sfs_hr_payslip_view` capability declared but never assigned to any role
- LeaveModule handle_approve() uses inline $valid_approve_from instead of shared is_valid_transition() (functionally equivalent)

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Audit-only v1.1, no fixes | Keep scope manageable | ✓ Good — completed full audit in 2 days |
| Review order by size/risk | Largest modules have most surface area | ✓ Good — found highest-severity issues in large modules first |
| Batch small modules (Ph 18-19) | Under 1.3K lines each, efficient grouping | ✓ Good — covered 6 modules in 2 phases |
| 4 metrics per module | Security + performance + duplication + logical | ✓ Good — comprehensive coverage |
| Saudi Labor Law EOS formula wrong | Settlement uses UAE 21-day formula | ✓ Fixed v1.3 — Article 84 rates + trigger_type |
| v1.2 scope: auth-only fixes | Focus on most critical security gaps first | ✓ Good — 32 auth gaps closed in 1 day |
| Capability-before-nonce pattern | Fails fast without revealing nonce validity | ✓ Good — consistent across Leave, Hiring, Loans, Assets |
| HMAC-SHA-256 for kiosk tokens | Prevents offline rainbow table attacks on QR tokens | ✓ Good — rotating nonce makes precomputation infeasible |
| Role allowlist for hiring | Blocks administrator escalation during conversion | ✓ Good — simple, auditable security boundary |
| TeamTab level < 40 threshold | HR/GM/Admin see org-wide; manager stays dept-scoped | ✓ Good — matches Role_Resolver level hierarchy |
| SQL-first fix ordering | Migration helpers before SQL injection fixes | ✓ Good — clean infrastructure for Phase 26 |
| Transient caching with 60s TTL | Dashboard/leave counters cached, no invalidation initially | ✓ Good — invalidation added in Phase 30 gap closure |
| Named locks for ref numbers | GET_LOCK instead of FOR UPDATE (callers may be in transactions) | ✓ Good — avoids nested transaction issues |
| Transaction-wrap approve path | Balance read-before-write race fixed in gap closure | ✓ Good — closes audit integration gap |

## Constraints

- **PHP 8.2+** target
- **No Composer/npm** — vendor assets committed directly
- **Saudi labor law** — Settlement, Leave tenure, weekend calculations must comply
- **Backward compatibility** — dual hire_date/hired_at, existing hooks/filters preserved
- **No CI/CD** — manual deployments, changes must be low-risk

---
*Last updated: 2026-03-18 after v1.3 milestone completion*
