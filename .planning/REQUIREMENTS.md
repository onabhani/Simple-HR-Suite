# Requirements: Simple HR Suite

**Defined:** 2026-03-17
**Core Value:** Reliable, secure HR operations for Saudi organizations

## v1.3 Requirements

Requirements for audit fix milestone. Each maps to roadmap phases.

### SQL Injection / Prepared Statements

- [x] **SQL-01**: Replace all bare ALTER TABLE with add_column_if_missing() helper (Core, Attendance, Loans — ~25 calls)
- [x] **SQL-02**: Replace information_schema queries with version-gated migration pattern (Core, Attendance, Notifications, Shift_Service — 9+ queries)
- [x] **SQL-03**: Fix all unprepared SELECT/COUNT/DELETE queries across 11 modules (~50+ queries)
- [x] **SQL-04**: Fix raw string interpolation in LIKE clauses (Hiring, Core)

### Data Integrity

- [x] **DATA-01**: Correct Settlement EOS formula from UAE 21-day to Saudi Article 84 (15-day for first 5 years, 30-day after)
- [x] **DATA-02**: Fix Leave balance corruption — opening/carried_over zeroed on every approval; preserve existing values
- [x] **DATA-03**: Fix Loans column mismatch — Payroll reads monthly_installment but column is installment_amount (zero deductions)
- [x] **DATA-04**: Fix Leave tenure boundary evaluation — compute at employee anniversary, not Jan 1
- [x] **DATA-05**: Fix Loans installment rounding imbalance — reconcile frontend vs admin calculation paths
- [x] **DATA-06**: Add Settlement trigger_type parameter — resignation vs termination vs contract-end affects payout percentage per Saudi law

### Performance

- [x] **PERF-01**: Fix N+1 query patterns across 9 modules (14+ locations — batch or JOIN instead of loop queries)
- [x] **PERF-02**: Add LIMIT/pagination to unbounded queries across 8 modules (10+ locations)
- [x] **PERF-03**: Add transient caching for dashboard/overview counter queries (Core admin 13+, Frontend OverviewTab 14, Leave status counts 11)

### Logic & Workflow

- [ ] **LOGIC-01**: Fix TOCTOU races with DB transactions or row-level locks (Leave overlap, Loans fiscal year, Attendance ref numbers, Payroll lock — 6 locations)
- [x] **LOGIC-02**: Add state machine guards preventing invalid status transitions in Leave, Settlement, Performance (6 locations)
- [x] **LOGIC-03**: Fix Saudi weekend bug — exclude both Friday AND Saturday in Payroll working-day calculation
- [x] **LOGIC-04**: Fix duplicate early-leave notification suppression in Attendance deferred handlers
- [x] **LOGIC-05**: Fix dynamic capability caching — static cache must survive AJAX requests

### Tech Debt

- [x] **DEBT-01**: Replace shared Leave approval nonce with per-request scoped nonce
- [x] **DEBT-02**: Align Setup_Wizard and Company_Profile capability check to sfs_hr.manage instead of manage_options

## Out of Scope

| Feature | Reason |
|---------|--------|
| Medium/Low severity findings | Focus on Critical/High only |
| JavaScript/CSS audit fixes | v1.1 was PHP-only audit |
| Unit/integration test creation | Separate effort |
| New feature development | Fix-only milestone |
| Third-party vendor code | Not our code (assets/vendor/) |
| dofs_ cross-plugin filter hooks cleanup | Requires cross-plugin coordination; low runtime risk |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| SQL-01 | Phase 25 | Complete |
| SQL-02 | Phase 25 | Complete |
| SQL-03 | Phase 26 | Complete |
| SQL-04 | Phase 26 | Complete |
| DATA-01 | Phase 27 | Complete |
| DATA-02 | Phase 27 | Complete |
| DATA-03 | Phase 27 | Complete |
| DATA-04 | Phase 27 | Complete |
| DATA-05 | Phase 27 | Complete |
| DATA-06 | Phase 27 | Complete |
| PERF-01 | Phase 28 | Complete |
| PERF-02 | Phase 28 | Complete |
| PERF-03 | Phase 28 | Complete |
| LOGIC-01 | Phase 29 | Pending |
| LOGIC-02 | Phase 29 | Complete |
| LOGIC-03 | Phase 29 | Complete |
| LOGIC-04 | Phase 29 | Complete |
| LOGIC-05 | Phase 29 | Complete |
| DEBT-01 | Phase 27 | Complete |
| DEBT-02 | Phase 26 | Complete |

**Coverage:**
- v1.3 requirements: 20 total
- Mapped to phases: 20
- Unmapped: 0

---
*Requirements defined: 2026-03-17*
*Last updated: 2026-03-17 after roadmap creation (v1.3 Phases 25-29)*
