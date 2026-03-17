# Roadmap: Simple HR Suite — Code Audit

## Milestones

- ✅ **v1.0 Attendance Module Refactor Phase 2** — Phases 1-3 (shipped 2026-03-10)
- ✅ **v1.1 Module-by-Module Code Audit** — Phases 4-19 (shipped 2026-03-17)
- ✅ **v1.2 Auth & Access Control Fixes** — Phases 20-24 (shipped 2026-03-17)
- 🚧 **v1.3 Audit Fixes (SQL, Data, Performance, Logic)** — Phases 25-29 (in progress)

## Phases

<details>
<summary>✅ v1.0 Attendance Module Refactor Phase 2 (Phases 1-3) — SHIPPED 2026-03-10</summary>

- [x] Phase 1: Views Extraction (2/2 plans) — completed 2026-03-09
- [x] Phase 2: Migration Extraction (1/1 plan) — completed 2026-03-09
- [x] Phase 3: Orchestrator Cleanup (1/1 plan) — completed 2026-03-10

Full details: `milestones/v1.0-ROADMAP.md`

</details>

<details>
<summary>✅ v1.1 Module-by-Module Code Audit (Phases 4-19) — SHIPPED 2026-03-17</summary>

- [x] Phase 4: Core + Frontend Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 5: Attendance Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 6: Leave Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 7: Performance Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 8: Loans Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 9: Payroll Audit (3/3 plans) — completed 2026-03-16
- [x] Phase 10: Settlement Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 11: Assets Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 12: Employees Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 13: Hiring Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 14: Resignation Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 15: Workforce_Status Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 16: Documents Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 17: ShiftSwap Audit (2/2 plans) — completed 2026-03-16
- [x] Phase 18: Departments + Surveys + Projects Audit (2/2 plans) — completed 2026-03-17
- [x] Phase 19: Reminders + EmployeeExit + PWA Audit (2/2 plans) — completed 2026-03-17

Full details: `milestones/v1.1-ROADMAP.md`

</details>

<details>
<summary>✅ v1.2 Auth & Access Control Fixes (Phases 20-24) — SHIPPED 2026-03-17</summary>

- [x] Phase 20: Attendance Endpoint Authentication (1/1 plan) — completed 2026-03-17
- [x] Phase 21: Leave + Hiring Handler Authorization (2/2 plans) — completed 2026-03-17
- [x] Phase 22: Loans + Performance Auth Hardening (2/2 plans) — completed 2026-03-17
- [x] Phase 23: Frontend Tab Ownership Verification (1/1 plan) — completed 2026-03-17
- [x] Phase 24: Small Modules Auth Fixes (2/2 plans) — completed 2026-03-17

Full details: `milestones/v1.2-ROADMAP.md`

</details>

### 🚧 v1.3 Audit Fixes (SQL, Data, Performance, Logic) (In Progress)

**Milestone Goal:** Fix all remaining Critical/High findings from the v1.1 audit — SQL injection, data integrity, performance, and logic/workflow issues across all 19 modules.

## Phase Details

### Phase 25: Migration Pattern Fixes
**Goal**: All migration code uses safe, idempotent helpers — no bare schema changes, no information_schema coupling
**Depends on**: Phase 24
**Requirements**: SQL-01, SQL-02
**Success Criteria** (what must be TRUE):
  1. Every schema change in Core, Attendance, and Loans uses `add_column_if_missing()` — no bare `ALTER TABLE ADD COLUMN` calls remain
  2. No module queries `information_schema` at runtime or during `admin_init` — version-gated migration pattern used instead
  3. Plugin activation and admin page load produce no `ALTER TABLE` errors when tables already exist
  4. Running migrations twice on an already-current database produces zero DB errors
**Plans:** 1/2 plans executed

Plans:
- [ ] 25-01-PLAN.md — Replace bare ALTER TABLE ADD COLUMN with add_column_if_missing() helpers
- [ ] 25-02-PLAN.md — Replace information_schema queries with SHOW-based equivalents

### Phase 26: SQL Injection Fixes
**Goal**: Every database query across all 11 affected modules uses `$wpdb->prepare()` — no user-supplied values reach SQL as raw strings
**Depends on**: Phase 25
**Requirements**: SQL-03, SQL-04, DEBT-02
**Success Criteria** (what must be TRUE):
  1. No `SELECT`, `COUNT`, or `DELETE` query in any module constructs its WHERE clause via string concatenation with user input
  2. All `LIKE` clauses in Hiring and Core use `$wpdb->prepare()` with `$wpdb->esc_like()` for the search term
  3. Setup Wizard and Company Profile capability checks use `sfs_hr.manage` — not `manage_options`
  4. A search input containing SQL metacharacters (quotes, percent signs, underscores) produces no DB error and returns correct results
**Plans**: TBD

### Phase 27: Data Integrity Fixes
**Goal**: Settlement, Leave, and Loans calculations produce correct values — formulas match Saudi labor law, balance fields are preserved, column names are consistent
**Depends on**: Phase 25
**Requirements**: DATA-01, DATA-02, DATA-03, DATA-04, DATA-05, DATA-06, DEBT-01
**Success Criteria** (what must be TRUE):
  1. Settlement EOS payout uses Saudi Article 84 rates (half-month per year for first 5 years, full month per year after) — not the former UAE 21-day formula
  2. Settlement distinguishes resignation, termination, and contract-end trigger types — each applies the correct payout percentage per Saudi law
  3. Approving a leave request preserves the employee's existing `opening` and `carried_over` balance values — neither field is zeroed
  4. Leave tenure entitlement steps up at the employee's anniversary date, not on January 1
  5. Payroll deducts loan installments correctly — `monthly_installment` column exists and Payroll reads the right column name
  6. Loan installment amounts are consistent between the frontend display and the admin calculation path
  7. Leave approval uses a per-request scoped nonce — the shared approval nonce is replaced
**Plans**: TBD

### Phase 28: Performance Fixes
**Goal**: Dashboard and list pages execute a bounded number of queries — no per-row query loops, no unguarded full-table scans, repeated counters served from cache
**Depends on**: Phase 26
**Requirements**: PERF-01, PERF-02, PERF-03
**Success Criteria** (what must be TRUE):
  1. No module issues a separate SQL query for each row in a result set — batch queries or JOINs replace all 14+ identified N+1 locations
  2. Every list or count query that previously had no LIMIT now includes pagination or an explicit cap — unbounded queries eliminated at 10+ identified locations
  3. Core admin dashboard, Frontend OverviewTab, and Leave status count queries are served from transient cache on repeated loads — query count drops to near zero on cache hit
  4. Admin dashboard page load time is visibly faster on a dataset of 50+ employees
**Plans**: TBD

### Phase 29: Logic and Workflow Fixes
**Goal**: Concurrent operations are safe, invalid state transitions are blocked, Saudi calendar rules are correct, and notification deduplication works
**Depends on**: Phase 27
**Requirements**: LOGIC-01, LOGIC-02, LOGIC-03, LOGIC-04, LOGIC-05
**Success Criteria** (what must be TRUE):
  1. Submitting two overlapping leave requests simultaneously does not result in both being approved — DB transaction or row-level lock prevents the race
  2. A leave, settlement, or performance record cannot transition to an invalid next state (e.g., approved-to-pending, cancelled-to-approved) — guards reject the attempt and return an error
  3. Payroll working-day calculation excludes both Friday and Saturday — a work week spanning Fri-Sat produces the correct reduced day count
  4. A single early-leave event triggers exactly one notification — duplicate deferred-handler notifications are suppressed
  5. Dynamic capabilities (`sfs_hr.leave.review`, `sfs_hr.view`) resolve correctly inside AJAX requests — static cache does not return stale false-negatives
**Plans**: TBD

## Progress

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1. Views Extraction | v1.0 | 2/2 | Complete | 2026-03-09 |
| 2. Migration Extraction | v1.0 | 1/1 | Complete | 2026-03-09 |
| 3. Orchestrator Cleanup | v1.0 | 1/1 | Complete | 2026-03-10 |
| 4. Core + Frontend Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 5. Attendance Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 6. Leave Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 7. Performance Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 8. Loans Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 9. Payroll Audit | v1.1 | 3/3 | Complete | 2026-03-16 |
| 10. Settlement Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 11. Assets Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 12. Employees Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 13. Hiring Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 14. Resignation Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 15. Workforce_Status Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 16. Documents Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 17. ShiftSwap Audit | v1.1 | 2/2 | Complete | 2026-03-16 |
| 18. Departments + Surveys + Projects | v1.1 | 2/2 | Complete | 2026-03-17 |
| 19. Reminders + EmployeeExit + PWA | v1.1 | 2/2 | Complete | 2026-03-17 |
| 20. Attendance Endpoint Auth | v1.2 | 1/1 | Complete | 2026-03-17 |
| 21. Leave + Hiring Handler Auth | v1.2 | 2/2 | Complete | 2026-03-17 |
| 22. Loans + Performance Auth | v1.2 | 2/2 | Complete | 2026-03-17 |
| 23. Frontend Tab Ownership | v1.2 | 1/1 | Complete | 2026-03-17 |
| 24. Small Modules Auth Fixes | v1.2 | 2/2 | Complete | 2026-03-17 |
| 25. Migration Pattern Fixes | 1/2 | In Progress|  | - |
| 26. SQL Injection Fixes | v1.3 | 0/TBD | Not started | - |
| 27. Data Integrity Fixes | v1.3 | 0/TBD | Not started | - |
| 28. Performance Fixes | v1.3 | 0/TBD | Not started | - |
| 29. Logic and Workflow Fixes | v1.3 | 0/TBD | Not started | - |
