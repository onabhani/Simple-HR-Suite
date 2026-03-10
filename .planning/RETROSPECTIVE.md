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

## Cross-Milestone Trends

### Process Evolution

| Milestone | Sessions | Phases | Key Change |
|-----------|----------|--------|------------|
| v1.0 | ~2 | 3 | First milestone — established extraction patterns |

### Cumulative Quality

| Milestone | Tests | Coverage | Zero-Dep Additions |
|-----------|-------|----------|-------------------|
| v1.0 | 0 | 0% | 3 (Widget_Shortcode, Kiosk_Shortcode, Migration) |

### Top Lessons (Verified Across Milestones)

1. Verbatim extraction before refactoring — extract first, improve later
