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

## Cross-Milestone Trends

### Process Evolution

| Milestone | Sessions | Phases | Key Change |
|-----------|----------|--------|------------|
| v1.0 | ~2 | 3 | First milestone — established extraction patterns |
| v1.1 | ~4 | 16 | Audit-only milestone — 662 findings across 87K lines |

### Cumulative Quality

| Milestone | Tests | Coverage | Findings | Code Changes |
|-----------|-------|----------|----------|-------------|
| v1.0 | 0 | 0% | — | +5400/-5391 (structural refactor) |
| v1.1 | 0 | 0% | 662 | 0 (audit-only) |

### Top Lessons (Verified Across Milestones)

1. Verbatim extraction before refactoring — extract first, improve later
2. Audit-only milestones are fast and effective — separating discovery from fixing keeps scope tight
3. Establishing taxonomy early (finding IDs, severity, wpdb accounting) pays compound dividends across later phases
4. Cross-module wiring validation catches integration bugs that single-module reviews miss
