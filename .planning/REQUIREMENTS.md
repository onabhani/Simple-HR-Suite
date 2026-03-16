# Requirements: Simple HR Suite Code Audit

**Defined:** 2026-03-16
**Core Value:** Identify security, performance, duplication, and logical issues across all modules

## v1.1 Requirements

Requirements for module-by-module code audit. Each maps to roadmap phases.

### Core Infrastructure

- [x] **CORE-01**: Audit Core/ (~25K lines) for security vulnerabilities (SQL injection, missing prepare, auth bypass)
- [x] **CORE-02**: Audit Core/ for performance issues (N+1 queries, unbounded queries, heavy admin_init)
- [x] **CORE-03**: Audit Core/ for code duplication and logical issues
- [x] **CORE-04**: Audit Frontend/ tabs and shortcodes for security and performance

### Critical Modules

- [x] **CRIT-01**: Audit Attendance module (~18K lines) — security, performance, duplication, logical issues
- [x] **CRIT-02**: Audit Leave module (~7.7K lines) — security, performance, duplication, logical issues
- [x] **CRIT-03**: Audit Performance module (~6.1K lines) — security, performance, duplication, logical issues
- [x] **CRIT-04**: Audit Loans module (~5.4K lines) — security, performance, duplication, logical issues

### Financial Modules

- [x] **FIN-01**: Audit Payroll module (~3.5K lines) — security, performance, duplication, logical issues
- [x] **FIN-02**: Audit Settlement module (~1.7K lines) — security, performance, duplication, logical issues

### Medium Modules

- [ ] **MED-01**: Audit Assets module (~4K lines) — security, performance, duplication, logical issues
- [ ] **MED-02**: Audit Employees module (~3.2K lines) — security, performance, duplication, logical issues
- [ ] **MED-03**: Audit Hiring module (~2.5K lines) — security, performance, duplication, logical issues
- [ ] **MED-04**: Audit Resignation module (~2.3K lines) — security, performance, duplication, logical issues
- [ ] **MED-05**: Audit Workforce_Status module (~2K lines) — security, performance, duplication, logical issues
- [ ] **MED-06**: Audit Documents module (~1.9K lines) — security, performance, duplication, logical issues
- [ ] **MED-07**: Audit ShiftSwap module (~1.3K lines) — security, performance, duplication, logical issues

### Small Modules

- [ ] **SML-01**: Audit Departments module (~775 lines) — security, performance, duplication, logical issues
- [ ] **SML-02**: Audit Surveys module (~1.3K lines) — security, performance, duplication, logical issues
- [ ] **SML-03**: Audit Projects module (~1.2K lines) — security, performance, duplication, logical issues
- [ ] **SML-04**: Audit Reminders module (~915 lines) — security, performance, duplication, logical issues
- [ ] **SML-05**: Audit EmployeeExit module (~490 lines) — security, performance, duplication, logical issues
- [ ] **SML-06**: Audit PWA module (~414 lines) — security, performance, duplication, logical issues

## Out of Scope

| Feature | Reason |
|---------|--------|
| Implementing fixes | Audit-only milestone; fixes in separate milestone |
| Code refactoring | Audit identifies issues, does not change code |
| Unit/integration tests | Separate effort |
| Vendor assets audit | `assets/vendor/` is third-party code |
| JavaScript/CSS audit | Focus is PHP backend code |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| CORE-01 | Phase 4 | Complete |
| CORE-02 | Phase 4 | Complete |
| CORE-03 | Phase 4 | Complete |
| CORE-04 | Phase 4 | Complete |
| CRIT-01 | Phase 5 | Complete |
| CRIT-02 | Phase 6 | Complete |
| CRIT-03 | Phase 7 | Complete |
| CRIT-04 | Phase 8 | Complete |
| FIN-01 | Phase 9 | Complete |
| FIN-02 | Phase 10 | Complete |
| MED-01 | Phase 11 | Pending |
| MED-02 | Phase 12 | Pending |
| MED-03 | Phase 13 | Pending |
| MED-04 | Phase 14 | Pending |
| MED-05 | Phase 15 | Pending |
| MED-06 | Phase 16 | Pending |
| MED-07 | Phase 17 | Pending |
| SML-01 | Phase 18 | Pending |
| SML-02 | Phase 18 | Pending |
| SML-03 | Phase 18 | Pending |
| SML-04 | Phase 19 | Pending |
| SML-05 | Phase 19 | Pending |
| SML-06 | Phase 19 | Pending |

**Coverage:**
- v1.1 requirements: 23 total
- Mapped to phases: 23
- Unmapped: 0 ✓

---
*Requirements defined: 2026-03-16*
*Last updated: 2026-03-16 after roadmap creation (phases 4-19)*
