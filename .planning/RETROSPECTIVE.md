# Project Retrospective

*A living document updated after each milestone. Lessons feed forward into future planning.*

## Milestone: v1.0 — Attendance Module Refactor Phase 2

**Shipped:** 2026-03-10
**Phases:** 3 | **Plans:** 4 | **Sessions:** ~2

### What Was Built
- Widget_Shortcode class (~1900 lines) extracted from AttendanceModule
- Kiosk_Shortcode class (~2800 lines) extracted from AttendanceModule
- Migration class with table creation, capabilities, and seed logic
- Clean 434-line orchestrator (down from ~5390 lines)

### What Worked
- Verbatim extraction pattern — no refactoring during extraction eliminated regressions
- Sequential phase dependencies (views -> migration -> cleanup) kept each step simple
- Fast execution: 4 plans completed in ~14 minutes total
- Audit-before-ship caught that all 10/10 requirements were satisfied

### What Was Inefficient
- SUMMARY.md files lacked `one_liner` frontmatter field (older format), requiring manual extraction
- STATE.md had duplicate YAML frontmatter blocks from multiple updates
- Nyquist validation was skipped for all 3 phases (not critical for this refactor scope)

### Patterns Established
- Frontend/ subdirectory for extracted shortcode renderers (parallel to Services/, Admin/, etc.)
- `static render()` method delegated from module shortcode method
- Module-local Migration.php for self-contained migration logic
- Instance methods over static helpers when extracting to dedicated classes

### Key Lessons
1. Verbatim extraction with zero refactoring is the safest god-class decomposition strategy — refactor after extraction
2. For large inline-asset shortcodes, keeping JS/CSS embedded during extraction avoids a whole class of breakage
3. Thin delegate methods in the orchestrator (2-3 lines each) make the extraction reversible

### Cost Observations
- Model mix: 100% opus (balanced profile)
- Sessions: ~2
- Notable: 14 minutes total execution across 4 plans — structural refactors with clear boundaries execute very fast

---

## Milestone: v1.1 — Module-by-Module Code Audit

**Shipped:** 2026-03-17
**Phases:** 16 | **Plans:** 33 | **Sessions:** ~4

### What Was Built
- Full security, performance, duplication, and logical audit of ~87K lines PHP
- 662 findings across 19 modules + Core/Frontend, each with severity rating and fix recommendation
- 7 recurring antipatterns catalogued with cross-module recurrence tracking
- Cross-module finding wiring validated (Loans/Payroll, Settlement/EmployeeExit, etc.)
- Milestone audit report with 3-source requirements cross-reference

### What Worked
- Size/risk ordering — auditing Attendance (18K) and Core (25K) first established the finding taxonomy and antipattern baseline that accelerated later phases
- Batching small modules (Phases 18-19) — 6 modules in 2 phases was efficient without sacrificing depth
- Cross-referencing findings across phases caught the Loans/Payroll `monthly_installment` column mismatch from both sides independently
- Audit-only scope discipline — zero code changes kept the milestone focused and fast (2 days for 16 phases)
- wpdb call-accounting methodology (introduced Phase 09) provided exhaustive SQL coverage for financial modules

### What Was Inefficient
- SUMMARY.md frontmatter still lacks `one_liner` and `requirements_completed` fields — manual extraction needed at milestone completion
- Three different finding heading styles evolved across phases (#### in 04-06, ### in 07-19, bold inline in 18-19) — should standardize upfront
- Phase 04 SUMMARY metadata count (35) understated actual findings (40) — summary metadata should be auto-counted
- Nyquist validation skipped for all 16 phases — acceptable for audit-only milestone but flagged in milestone audit
- Phase 7 ROADMAP.md progress table showed 1/2 incorrectly — data quality drift in progress tracking

### Patterns Established
- Finding ID format: `{MODULE}-{AREA}-{TYPE}-NNN` (e.g., `SETT-LOGIC-001`, `HADM-SEC-002`)
- wpdb call-accounting table per findings report (total calls, prepared, raw, static assessment)
- Cross-module wiring section in findings reports for inter-module dependencies
- Recurring antipattern tracking with recurrence count across phases

### Key Lessons
1. Audit-only milestones are viable and fast — separating discovery from fixing keeps scope manageable
2. Establishing finding taxonomy in Phase 4 and refining through Phase 9 created a consistent framework across 16 phases
3. Saudi Labor Law compliance issues (Settlement EOS formula, weekend calculation) require domain expert validation before code fixes
4. The information_schema antipattern appeared in 9 modules — systemic fixes should be centralized, not per-module
5. Cross-module finding wiring catches integration bugs that single-module audits miss

### Cost Observations
- Model mix: ~80% opus, ~20% sonnet (balanced profile)
- Sessions: ~4
- Notable: 662 findings across 87K lines in 2 days — audit phases execute very fast when scope is read-only

---

## Milestone: v1.2 — Auth & Access Control Fixes

**Shipped:** 2026-03-17
**Phases:** 5 | **Plans:** 8 | **Sessions:** ~2

### What Was Built
- Authentication gates on all attendance REST endpoints with HMAC-SHA-256 kiosk token rotation
- Capability-gated leave approval/cancellation with per-request scoped nonces and self-approval prevention
- Hiring handler authorization with role allowlist and password-reset welcome emails
- Loans nonce-before-data ordering with DOM nonce exposure eliminated
- Performance REST department-scope enforcement with tiered permission model
- Frontend tab ownership guards (OverviewTab, ProfileTab) and role-based TeamTab visibility
- Asset export row limits, MIME allowlist, Core sync auth, Resignation dept scoping, redirect validation
- Settlement ownership verification, Payroll/Employees capability format standardization

### What Worked
- Security-focused milestone with narrow scope (auth-only) — 32 requirements completed in 1 day
- Phase grouping by fix pattern (not module) — batching similar auth patterns accelerated execution
- Capability-before-nonce pattern established in Phase 20-21 carried cleanly through Phases 22-24
- Integration checker caught 3 non-blocking issues (active filter inconsistency, dormant capability, docs error) that manual review missed
- All 8 E2E flows verified complete — no broken cross-phase wiring despite touching 9 modules

### What Was Inefficient
- SUMMARY.md `requirements_completed` frontmatter populated for only 3 of 8 plans — 18 of 32 requirements required VERIFICATION.md cross-reference at audit time
- STATE.md accumulated duplicate YAML frontmatter blocks again (same issue as v1.0)
- Nyquist validation skipped for all 5 phases — VALIDATION.md missing across the board
- ROADMAP.md plan checkboxes for Phases 22-24 were never marked `[x]` during execution (cosmetic)
- CORE-AUTH-01 documentation described `&&` operator but code uses `||` — docs/code divergence not caught until integration checker

### Patterns Established
- Capability-before-nonce ordering for all admin_post handlers (check cap → read minimal POST → verify scoped nonce → business logic)
- Per-request scoped nonces: `sfs_hr_{action}_{id}` format prevents cross-request replay
- PHP-generated JS nonce map pattern (inline `sfsInstNonces` object) instead of DOM data attributes
- HMAC-SHA-256 with rotating nonce for offline token validation (replaces plain SHA-256 hash)
- Tiered REST permission model: manage=full, view=full, sfs_hr.view=dept-scoped
- Frontend tab ownership guard: `(int)($emp['user_id'] ?? 0) !== get_current_user_id()` early return
- Role level threshold pattern: `$level < 40` to separate manager scope from HR/GM/Admin scope

### Key Lessons
1. Auth-fix milestones execute fast when audit findings provide clear targets — no ambiguity in what to fix
2. Grouping by fix pattern (not module) creates reusable patterns that accelerate later phases
3. Integration checker is valuable for catching cross-module consistency issues (active filters, capability assignments)
4. SUMMARY frontmatter completeness should be enforced during execution, not discovered at audit time
5. Documentation-code divergence in verification reports is a real risk when code is modified after verification

### Cost Observations
- Model mix: ~70% opus, ~30% sonnet (balanced profile)
- Sessions: ~2
- Notable: 32 auth fixes across 9 modules in 1 day — targeted security fixes with audit-backed requirements execute extremely fast

---

## Cross-Milestone Trends

### Process Evolution

| Milestone | Sessions | Phases | Key Change |
|-----------|----------|--------|------------|
| v1.0 | ~2 | 3 | First milestone — established extraction patterns |
| v1.1 | ~4 | 16 | Audit-only milestone — 662 findings across 87K lines |
| v1.2 | ~2 | 5 | Auth-fix milestone — 32 security gaps closed across 9 modules |

### Cumulative Quality

| Milestone | Tests | Coverage | Findings | Code Changes |
|-----------|-------|----------|----------|-------------|
| v1.0 | 0 | 0% | — | +5400/-5391 (structural refactor) |
| v1.1 | 0 | 0% | 662 | 0 (audit-only) |
| v1.2 | 0 | 0% | 32 fixed | +366/-135 (auth hardening) |

### Top Lessons (Verified Across Milestones)

1. Verbatim extraction before refactoring — extract first, improve later
2. Audit-only milestones are fast and effective — separating discovery from fixing keeps scope tight
3. Establishing taxonomy early (finding IDs, severity, wpdb accounting) pays compound dividends across later phases
4. Cross-module wiring validation catches integration bugs that single-module reviews miss
5. Grouping fix phases by pattern (not module) creates reusable patterns that compound across later phases (v1.2)
6. Integration checker agents catch consistency issues (active filters, capability assignments) that per-phase verification misses (v1.2)
