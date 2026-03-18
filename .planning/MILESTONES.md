# Milestones
## v1.3 Audit Fixes (SQL, Data, Performance, Logic) (Shipped: 2026-03-18)

**Phases completed:** 6 phases, 14 plans
**Requirements:** 20/20 satisfied
**Audit:** Passed (re-audit after gap closure Phase 30)
**Timeline:** 1 day (2026-03-18), 56 commits

**Key accomplishments:**
- Eliminated SQL injection vectors: all 50+ unprepared queries across 11 modules now use `$wpdb->prepare()`, LIKE clauses use `esc_like()`, migration helpers replaced bare ALTER TABLE with idempotent `add_column_if_missing()`
- Corrected Settlement EOS formula from UAE 21-day to Saudi Article 84 (15/30-day), added trigger_type support for resignation/termination/contract-end payout percentages
- Fixed Leave balance corruption (opening/carried_over preserved on approval), tenure anniversary-based entitlement, per-request reject nonce, and Payroll loan column mismatch
- Eliminated N+1 query patterns across 14+ locations, added transient caching for dashboard/overview counters (60-300s TTL), and LIMIT/pagination on all unbounded queries
- Closed TOCTOU race conditions with DB transactions and row locks on leave/loan creation and approval paths, atomic reference number generation via MySQL named locks
- Added state machine transition guards to Leave (6 handlers), Settlement, and Performance modules with proper execution termination and cache invalidation on mutations

**Stats:**
- 44 files changed, +2500/-1688 lines
- Codebase: ~92K lines PHP
- Git range: fix(25-01) → docs(phase-30)
- 56 commits (2026-03-18)

**Residual tech debt (cosmetic only):**
- LeaveModule.php line 5326: INFORMATION_SCHEMA.STATISTICS lacks migration-only annotation
- handle_approve() uses inline $valid_approve_from instead of shared is_valid_transition() (functionally equivalent)

---

## v1.2 Auth & Access Control Fixes (Shipped: 2026-03-17)

**Phases completed:** 5 phases, 8 plans
**Requirements:** 32/32 satisfied
**Timeline:** 1 day (2026-03-17), 20 commits

**Key accomplishments:**
- Gated attendance REST endpoints behind authentication and replaced SHA-256 token hashes with HMAC-SHA-256 bound to per-roster rotating nonces for offline kiosk security
- Enforced capability checks on leave approval/cancellation with per-request scoped nonces, self-approval prevention, and fixed is_hr_user() to require explicit HR assignment
- Secured hiring conversion handlers with capability gates, role allowlist blocking administrator escalation, and password reset links replacing plaintext passwords
- Hardened Loans nonce ordering (nonce-before-data) and removed installment nonces from DOM; added Performance capability checks with department-scoped REST reads
- Added frontend tab ownership guards — OverviewTab/ProfileTab verify employee identity, TeamTab shows org-wide data for HR/GM/Admin
- Patched 6 remaining modules — asset export limits, MIME allowlist, Core sync auth, Resignation dept scoping + redirect validation, Settlement ownership, Payroll/Employees capability format

**Stats:**
- 20 files changed, +366/-135 lines
- Git range: fix(20-01) → fix(24-02)
- 20 commits (2026-03-17)

**Known tech debt (accepted):**
- Performance REST `get_managed_department_ids()` missing `AND active = 1` filter
- `sfs_hr_payslip_view` capability declared but never assigned (dormant)
- Stale docblock on `is_hr_user()`, CORE-AUTH-01 docs operator error

---

## v1.1 Module-by-Module Code Audit (Shipped: 2026-03-17)

**Phases completed:** 16 phases, 33 plans
**Findings:** ~662 total across 19 modules + Core/Frontend
**Requirements:** 23/23 satisfied
**Timeline:** 2 days (2026-03-16 → 2026-03-17), 114 commits

**Key accomplishments:**
- Audited ~87K lines of PHP across 19 modules + Core/Frontend — 662 findings with severity ratings and fix recommendations
- Discovered Settlement module uses UAE Labor Law formula instead of Saudi Article 84 — 40% EOS overpayment for sub-5-year employees
- Found critical auth gaps in Hiring module — any authenticated user can trigger employee creation via unguarded conversion methods
- Catalogued 7 recurring antipatterns: information_schema queries (9 modules), bare ALTER TABLE (4 modules), TOCTOU races (6 modules), N+1 queries (8+ modules), wrong capability gates (3 modules), missing current_user_can() (5 modules), dofs_ namespace violations (4 modules)
- Confirmed Loans/Payroll integration bug — `monthly_installment` column doesn't exist; loan deductions silently zero on every payroll run
- Validated cross-module finding wiring — all cross-module dependencies independently confirmed from both sides

**Stats:**
- Codebase audited: ~87K lines PHP
- Git range: feat(04-core-frontend-audit) → docs(phase-19)
- 114 commits over 2 days (2026-03-16 → 2026-03-17)

---


## v1.0 Attendance Module Refactor Phase 2 (Shipped: 2026-03-10)

**Phases completed:** 3 phases, 4 plans, 0 tasks

**Key accomplishments:**
- Extracted Widget_Shortcode (~1900 lines) into Frontend/Widget_Shortcode.php with thin delegate pattern
- Extracted Kiosk_Shortcode (~2800 lines) into Frontend/Kiosk_Shortcode.php with full kiosk rendering
- Extracted migration, capability registration, and seed logic into dedicated Migration class
- Reduced AttendanceModule.php from ~5390 lines to 434 lines — clean orchestrator with zero dead code
- Zero behavior change — all existing functionality preserved through pure structural refactor

**Stats:**
- 4 source files changed, +5400/-5391 lines (structural refactor)
- 21 commits over 2 days (2026-03-09 → 2026-03-10)
- Git range: af7a974..3d71a3a

---

