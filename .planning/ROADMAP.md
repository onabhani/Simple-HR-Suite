# Roadmap: Simple HR Suite — Code Audit

## Milestones

- [x] **v1.0 Attendance Module Refactor Phase 2** — Phases 1-3 (shipped 2026-03-10)
- [ ] **v1.1 Module-by-Module Code Audit** — Phases 4-19 (in progress)

## Phases

<details>
<summary>v1.0 Attendance Module Refactor Phase 2 (Phases 1-3) — SHIPPED 2026-03-10</summary>

- [x] Phase 1: Views Extraction (2/2 plans) — completed 2026-03-09
- [x] Phase 2: Migration Extraction (1/1 plan) — completed 2026-03-09
- [x] Phase 3: Orchestrator Cleanup (1/1 plan) — completed 2026-03-10

Full details: `milestones/v1.0-ROADMAP.md`

</details>

### v1.1 Module-by-Module Code Audit

- [x] **Phase 4: Core + Frontend Audit** — Audit shared Core infrastructure and Frontend tab/shortcode layer (completed 2026-03-16)
- [x] **Phase 5: Attendance Audit** — Audit the largest module (~18K lines) for all 4 metrics (completed 2026-03-16)
- [x] **Phase 6: Leave Audit** — Audit Leave module including balance, request, and approval logic (completed 2026-03-16)
- [ ] **Phase 7: Performance Audit** — Audit employee performance review and justification module
- [x] **Phase 8: Loans Audit** — Audit Loans module including installment and repayment logic (completed 2026-03-16)
- [x] **Phase 9: Payroll Audit** — Audit Payroll calculation, run, and export logic (completed 2026-03-16)
- [x] **Phase 10: Settlement Audit** — Audit end-of-service settlement calculation module (completed 2026-03-16)
- [x] **Phase 11: Assets Audit** — Audit Assets assignment, tracking, and return logic (completed 2026-03-16)
- [x] **Phase 12: Employees Audit** — Audit employee CRUD, profile, and status management (completed 2026-03-16)
- [x] **Phase 13: Hiring Audit** — Audit Hiring module (applicants, pipeline, onboarding) (completed 2026-03-16)
- [x] **Phase 14: Resignation Audit** — Audit Resignation submission and approval workflow (completed 2026-03-16)
- [x] **Phase 15: Workforce_Status Audit** — Audit workforce status tracking and dashboard (completed 2026-03-16)
- [x] **Phase 16: Documents Audit** — Audit document upload, storage, and access control (completed 2026-03-16)
- [x] **Phase 17: ShiftSwap Audit** — Audit shift swap request and approval workflow (completed 2026-03-16)
- [ ] **Phase 18: Departments + Surveys + Projects Audit** — Batch audit of three small modules (~2.2K lines combined)
- [ ] **Phase 19: Reminders + EmployeeExit + PWA Audit** — Batch audit of three small modules (~1.8K lines combined)

## Phase Details

### Phase 4: Core + Frontend Audit
**Goal**: Security, performance, duplication, and logical issues in Core/ and Frontend/ are fully documented with findings and fix recommendations
**Depends on**: Nothing (first phase of v1.1)
**Requirements**: CORE-01, CORE-02, CORE-03, CORE-04
**Success Criteria** (what must be TRUE):
  1. Every raw SQL call in Core/ is evaluated — each either confirmed safe with `$wpdb->prepare()` or flagged as a Critical/High finding
  2. Every REST endpoint and admin action in Core/ is checked for capability and nonce validation — gaps rated and documented
  3. Admin-init hooks and shared helpers are evaluated for performance cost — heavy operations flagged with severity
  4. All Frontend/ tab renderers and shortcodes are checked for unescaped output and missing auth checks
  5. A findings report for Core + Frontend exists listing every issue with severity rating and a concrete fix recommendation
**Plans**: 2 plans
Plans:
- [ ] 04-01-PLAN.md -- Core/ security, performance, duplication audit (~13.7K lines, 11 files)
- [ ] 04-02-PLAN.md -- Frontend/ security, performance, duplication audit (~11.2K lines, 20 files)

### Phase 5: Attendance Audit
**Goal**: All security, performance, duplication, and logical issues in the Attendance module (~18K lines) are documented
**Depends on**: Phase 4
**Requirements**: CRIT-01
**Success Criteria** (what must be TRUE):
  1. Every `$wpdb` query in Attendance is reviewed — raw interpolations flagged Critical, missing prepare() calls flagged High
  2. Session punch-in/out logic is checked for race conditions and edge cases (overlapping sessions, timezone handling, off-day overtime)
  3. N+1 query patterns and unbounded result sets in attendance reporting are identified and rated
  4. Duplicated logic across Attendance services (Period_Service, Shift_Service, etc.) that survived the v1.0 refactor is catalogued
  5. A findings report for Attendance exists with severity ratings and fix recommendations for every issue found
**Plans**: 2 plans
Plans:
- [ ] 05-01-PLAN.md -- Services, Cron, Migration audit (~3.9K lines, 10 files)
- [ ] 05-02-PLAN.md -- Admin, REST, Frontend audit (~14.3K lines, 6 files)

### Phase 6: Leave Audit
**Goal**: All security, performance, duplication, and logical issues in the Leave module (~7.7K lines) are documented
**Depends on**: Phase 5
**Requirements**: CRIT-02
**Success Criteria** (what must be TRUE):
  1. Leave balance calculation logic is audited for correctness — tenure-based accrual rules and edge cases (hire mid-year, partial periods) checked
  2. Leave request approval workflow is checked for missing capability checks and unauthorized state transitions
  3. All `$wpdb` queries in Leave are confirmed prepared or flagged by severity
  4. Overlap detection logic (concurrent leave requests) is evaluated for race conditions
  5. A findings report for Leave exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 06-01-PLAN.md -- Leave services, balance calculation, and $wpdb security audit (3 files, ~7.7K lines)
- [ ] 06-02-PLAN.md -- Leave approval workflow, overlap detection, REST/admin handlers audit (2 files, ~7.5K lines)

### Phase 7: Performance Audit
**Goal**: All security, performance, duplication, and logical issues in the Performance module (~6.1K lines) are documented
**Depends on**: Phase 6
**Requirements**: CRIT-03
**Success Criteria** (what must be TRUE):
  1. Performance review submission and scoring logic is checked for missing auth/nonce validation
  2. Justification workflows are audited for unauthorized access paths (employee reading own score before publish, etc.)
  3. All `$wpdb` queries confirmed prepared or flagged
  4. Repeated scoring or aggregation logic that could be a shared helper is identified
  5. A findings report for Performance exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 07-01-PLAN.md -- Services, Calculator, Cron audit (~3.5K lines, 6 files)
- [ ] 07-02-PLAN.md -- Admin, REST, Module orchestrator audit (~2.6K lines, 3 files)

### Phase 8: Loans Audit
**Goal**: All security, performance, duplication, and logical issues in the Loans module (~5.4K lines) are documented
**Depends on**: Phase 7
**Requirements**: CRIT-04
**Success Criteria** (what must be TRUE):
  1. Loan disbursement and installment calculation logic is checked for arithmetic edge cases (rounding, partial months, early payoff)
  2. All financial write endpoints in Loans are confirmed to have nonce + capability checks
  3. All `$wpdb` queries confirmed prepared or flagged by severity
  4. Repeated installment/schedule logic that duplicates Payroll deduction logic is identified
  5. A findings report for Loans exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 08-01-PLAN.md -- Module orchestrator, frontend, and notifications audit (~2K lines, 3 files)
- [ ] 08-02-PLAN.md -- Admin pages and dashboard widget audit (~3.3K lines, 2 files)
### Phase 9: Payroll Audit
**Goal**: All security, performance, duplication, and logical issues in the Payroll module (~3.5K lines) are documented
**Depends on**: Phase 8
**Requirements**: FIN-01
**Success Criteria** (what must be TRUE):
  1. Payroll run and calculation logic is checked for floating-point rounding issues and missing employee edge cases (zero-day periods, mid-month hires)
  2. All Payroll write operations confirmed to require appropriate capability (e.g., `sfs_hr.manage`)
  3. All `$wpdb` queries confirmed prepared or flagged
  4. Export/report generation checked for unescaped output and data leakage across departments
  5. A findings report for Payroll exists with severity ratings and fix recommendations
**Plans**: 3 plans
Plans:
- [ ] 09-01-PLAN.md -- Payroll module orchestrator and REST endpoints audit (~963 lines, 2 files)
- [ ] 09-02-PLAN.md -- Payroll admin pages audit (~2,576 lines, 1 file)
- [ ] 09-03-PLAN.md -- Gap closure: add wpdb call-accounting table to Admin_Pages findings

### Phase 10: Settlement Audit
**Goal**: All security, performance, duplication, and logical issues in the Settlement module (~1.7K lines) are documented
**Depends on**: Phase 9
**Requirements**: FIN-02
**Success Criteria** (what must be TRUE):
  1. End-of-service entitlement calculation is audited against Saudi labor law formula — incorrect tenure brackets or missing edge cases flagged
  2. Settlement trigger conditions (resignation vs. termination vs. contract end) are checked for logical correctness
  3. All `$wpdb` queries confirmed prepared or flagged
  4. A findings report for Settlement exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 10-01-PLAN.md -- Settlement services, handlers, and module orchestrator audit (~671 lines, 3 files)
- [ ] 10-02-PLAN.md -- Settlement admin controller and view classes audit (~1,055 lines, 4 files)

### Phase 11: Assets Audit
**Goal**: All security, performance, duplication, and logical issues in the Assets module (~4K lines) are documented
**Depends on**: Phase 10
**Requirements**: MED-01
**Success Criteria** (what must be TRUE):
  1. Asset assignment and return logic is checked for missing ownership verification (employee can only see/return own assets)
  2. File upload handling in Assets is audited for MIME type validation and path traversal risk
  3. All `$wpdb` queries confirmed prepared or flagged
  4. Duplicate asset status tracking logic identified and documented
  5. A findings report for Assets exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 11-01-PLAN.md -- Assets module orchestrator, REST, and admin controller audit (~2.5K lines, 3 files)
- [ ] 11-02-PLAN.md -- Assets view templates audit (~1.5K lines, 3 files)
### Phase 12: Employees Audit
**Goal**: All security, performance, duplication, and logical issues in the Employees module (~3.2K lines) are documented
**Depends on**: Phase 11
**Requirements**: MED-02
**Success Criteria** (what must be TRUE):
  1. Employee CRUD endpoints are checked — each confirmed to require appropriate capability before write operations
  2. Profile data output (REST responses, admin views) is checked for unescaped fields
  3. Dual hire_date/hired_at handling is audited for consistency across all query paths
  4. All `$wpdb` queries confirmed prepared or flagged
  5. A findings report for Employees exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 12-01-PLAN.md -- Employee Profile admin page audit (1,982 lines, 1 file)
- [ ] 12-02-PLAN.md -- My Profile self-service page and EmployeesModule orchestrator audit (1,183 lines, 2 files)

### Phase 13: Hiring Audit
**Goal**: All security, performance, duplication, and logical issues in the Hiring module (~2.5K lines) are documented
**Depends on**: Phase 12
**Requirements**: MED-03
**Success Criteria** (what must be TRUE):
  1. Applicant data endpoints are checked for public/unauthenticated access paths that should be restricted
  2. Pipeline stage transitions are audited for missing capability checks
  3. All `$wpdb` queries confirmed prepared or flagged
  4. A findings report for Hiring exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 13-01-PLAN.md -- HiringModule orchestrator audit (717 lines, 1 file)
- [ ] 13-02-PLAN.md -- Hiring admin pages controller audit (1,746 lines, 1 file)

### Phase 14: Resignation Audit
**Goal**: All security, performance, duplication, and logical issues in the Resignation module (~2.3K lines) are documented
**Depends on**: Phase 13
**Requirements**: MED-04
**Success Criteria** (what must be TRUE):
  1. Resignation submission is checked — employee can only submit for themselves; managers cannot submit on behalf without explicit capability
  2. Approval state machine is audited for unauthorized backwards transitions (approved back to pending, etc.)
  3. All `$wpdb` queries confirmed prepared or flagged
  4. A findings report for Resignation exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 14-01-PLAN.md -- ResignationModule orchestrator and admin pages audit (~861 lines, 4 files)
- [ ] 14-02-PLAN.md -- Services, handlers, frontend, cron, notifications audit (~1,400 lines, 5 files)

### Phase 15: Workforce_Status Audit
**Goal**: All security, performance, duplication, and logical issues in the Workforce_Status module (~2K lines) are documented
**Depends on**: Phase 14
**Requirements**: MED-05
**Success Criteria** (what must be TRUE):
  1. Status change operations are checked for missing capability validation (who can change an employee's workforce status)
  2. Dashboard queries are audited for N+1 patterns or full-table scans on large employee sets
  3. All `$wpdb` queries confirmed prepared or flagged
  4. A findings report for Workforce_Status exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 15-01-PLAN.md -- Module orchestrator, Admin_Pages dashboard, and empty Status_Analytics audit (~1,368 lines, 3 files)
- [ ] 15-02-PLAN.md -- Absent_Cron scheduler and Absent_Notifications service audit (~664 lines, 2 files)

### Phase 16: Documents Audit
**Goal**: All security, performance, duplication, and logical issues in the Documents module (~1.9K lines) are documented
**Depends on**: Phase 15
**Requirements**: MED-06
**Success Criteria** (what must be TRUE):
  1. Document upload handling is audited for MIME type validation, file size limits, and direct file access without authentication
  2. Document access control is verified — employees cannot access other employees' documents
  3. All `$wpdb` queries confirmed prepared or flagged
  4. A findings report for Documents exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 16-01-PLAN.md -- DocumentsModule orchestrator, service layer, and handlers audit (~1,160 lines, 3 files)
- [ ] 16-02-PLAN.md -- Documents admin tab and REST endpoints audit (~718 lines, 2 files)

### Phase 17: ShiftSwap Audit
**Goal**: All security, performance, duplication, and logical issues in the ShiftSwap module (~1.3K lines) are documented
**Depends on**: Phase 16
**Requirements**: MED-07
**Success Criteria** (what must be TRUE):
  1. Swap request creation is checked — employees cannot create swap requests for other employees' shifts
  2. Approval logic is audited for missing manager capability checks
  3. All `$wpdb` queries confirmed prepared or flagged
  4. A findings report for ShiftSwap exists with severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 17-01-PLAN.md -- ShiftSwapModule orchestrator, service, handlers, and notifications audit (~969 lines, 4 files)
- [ ] 17-02-PLAN.md -- ShiftSwap admin tab and REST endpoints audit (~377 lines, 2 files)

### Phase 18: Departments + Surveys + Projects Audit
**Goal**: Security, performance, duplication, and logical issues in Departments (~775 lines), Surveys (~1.3K lines), and Projects (~1.2K lines) are documented
**Depends on**: Phase 17
**Requirements**: SML-01, SML-02, SML-03
**Success Criteria** (what must be TRUE):
  1. Department manager and HR responsible assignment logic is audited for capability bypass (any user promoting themselves)
  2. Survey response endpoints are checked for missing auth — unauthenticated submissions or cross-employee data access
  3. Project assignment logic is checked for missing ownership validation
  4. All `$wpdb` queries across all three modules confirmed prepared or flagged
  5. A single findings report covering Departments, Surveys, and Projects exists with per-module severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 18-01-PLAN.md -- Departments + Surveys audit: DepartmentsModule, SurveysModule, SurveysTab (~2,068 lines, 3 files)
- [ ] 18-02-PLAN.md -- Projects audit: ProjectsModule, Admin pages, Projects service (~1,160 lines, 3 files)

### Phase 19: Reminders + EmployeeExit + PWA Audit
**Goal**: Security, performance, duplication, and logical issues in Reminders (~915 lines), EmployeeExit (~490 lines), and PWA (~414 lines) are documented
**Depends on**: Phase 18
**Requirements**: SML-04, SML-05, SML-06
**Success Criteria** (what must be TRUE):
  1. Reminder cron jobs are checked for unbounded queries (no LIMIT on employee notification queries) and missing output escaping in notifications
  2. EmployeeExit logic is audited for logical overlap with Settlement module — duplicate end-of-service calculations flagged
  3. PWA service worker and manifest endpoints are checked for data leakage (auth tokens, employee data in cacheable responses)
  4. All `$wpdb` queries across all three modules confirmed prepared or flagged
  5. A single findings report covering Reminders, EmployeeExit, and PWA exists with per-module severity ratings and fix recommendations
**Plans**: 2 plans
Plans:
- [ ] 07-01-PLAN.md -- Services, Calculator, Cron audit (~3.5K lines, 6 files)
- [ ] 07-02-PLAN.md -- Admin, REST, Module orchestrator audit (~2.6K lines, 3 files)

## Progress

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1. Views Extraction | v1.0 | 2/2 | Complete | 2026-03-09 |
| 2. Migration Extraction | v1.0 | 1/1 | Complete | 2026-03-09 |
| 3. Orchestrator Cleanup | v1.0 | 1/1 | Complete | 2026-03-10 |
| 4. Core + Frontend Audit | 2/2 | Complete   | 2026-03-16 | — |
| 5. Attendance Audit | 2/2 | Complete   | 2026-03-16 | — |
| 6. Leave Audit | 2/2 | Complete   | 2026-03-16 | — |
| 7. Performance Audit | 1/2 | In Progress|  | — |
| 8. Loans Audit | 2/2 | Complete   | 2026-03-16 | — |
| 9. Payroll Audit | 3/3 | Complete   | 2026-03-16 | — |
| 10. Settlement Audit | 2/2 | Complete    | 2026-03-16 | — |
| 11. Assets Audit | 2/2 | Complete    | 2026-03-16 | — |
| 12. Employees Audit | 2/2 | Complete    | 2026-03-16 | — |
| 13. Hiring Audit | 2/2 | Complete    | 2026-03-16 | — |
| 14. Resignation Audit | 2/2 | Complete    | 2026-03-16 | — |
| 15. Workforce_Status Audit | 2/2 | Complete    | 2026-03-16 | — |
| 16. Documents Audit | 2/2 | Complete    | 2026-03-16 | — |
| 17. ShiftSwap Audit | 2/2 | Complete    | 2026-03-16 | — |
| 18. Departments + Surveys + Projects Audit | 1/2 | In Progress|  | — |
| 19. Reminders + EmployeeExit + PWA Audit | v1.1 | 0/? | Not started | — |
