# Roadmap: Simple HR Suite — Code Audit

## Milestones

- ✅ **v1.0 Attendance Module Refactor Phase 2** — Phases 1-3 (shipped 2026-03-10)
- ✅ **v1.1 Module-by-Module Code Audit** — Phases 4-19 (shipped 2026-03-17)
- 🚧 **v1.2 Auth & Access Control Fixes** — Phases 20-24 (in progress)

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

### 🚧 v1.2 Auth & Access Control Fixes (In Progress)

**Milestone Goal:** Eliminate all auth/access-control security gaps found in the v1.1 audit — unauthenticated endpoints, missing capability checks, unsafe nonce patterns, and data ownership verification failures across 9 modules.

- [x] **Phase 20: Attendance Endpoint Authentication** - Require auth on all attendance endpoints and remove token hash exposure from kiosk roster (completed 2026-03-17)
- [x] **Phase 21: Leave + Hiring Handler Authorization** - Enforce capability checks on leave approval/cancellation flows and all hiring conversion handlers; prevent role escalation and plaintext passwords (completed 2026-03-17)
- [x] **Phase 22: Loans + Performance Auth Hardening** - Fix nonce scoping and read-before-verify ordering in Loans; add capability checks to all Performance goal handlers (completed 2026-03-17)
- [x] **Phase 23: Frontend Tab Ownership Verification** - Enforce employee data ownership in OverviewTab and ProfileTab; fix TeamTab visibility for HR/GM roles (completed 2026-03-17)
- [ ] **Phase 24: Small Modules Auth Fixes** - Patch capability checks and data ownership in Assets, Core, Resignation, Settlement, Payroll, and Employees

## Phase Details

### Phase 20: Attendance Endpoint Authentication
**Goal**: All attendance endpoints and AJAX handlers require proper authentication before processing any data
**Depends on**: Nothing (first phase of v1.2)
**Requirements**: ATT-AUTH-01, ATT-AUTH-02, ATT-AUTH-03, ATT-AUTH-04
**Success Criteria** (what must be TRUE):
  1. An unauthenticated HTTP request to `GET /attendance/status` receives a 401 response, not attendance data
  2. An unauthenticated request to `POST /attendance/verify-pin` receives a 401 response; no rate-limit bypass is possible for logged-in users
  3. `handle_rebuild_sessions_day` rejects any request that does not include a valid admin nonce and returns a nonce-failure response without processing
  4. The `kiosk_roster` endpoint response contains no SHA-256 token hashes in any field for any employee
**Plans**: 1 plan

Plans:
- [x] 20-01: Fix unauthenticated attendance REST endpoints and kiosk token hash exposure

### Phase 21: Leave + Hiring Handler Authorization
**Goal**: Leave approval and cancellation handlers enforce capability and prevent self-approval; hiring conversion methods are capability-gated, use a role allowlist, and do not send plaintext passwords
**Depends on**: Phase 20
**Requirements**: LV-AUTH-01, LV-AUTH-02, LV-AUTH-03, LV-AUTH-04, LV-AUTH-05, HIR-AUTH-01, HIR-AUTH-02, HIR-AUTH-03, HIR-AUTH-04
**Success Criteria** (what must be TRUE):
  1. A user without `sfs_hr.leave.review` capability cannot trigger `handle_approve()` or `handle_cancel()` — both handlers return an error before processing
  2. An HR user cannot approve their own leave request at either the first or second approval stage in `handle_cancellation_approve()`
  3. `is_hr_user()` denies access to users whose only HR-related role is `sfs_hr_manager` with no explicit HR responsibility assignment
  4. Leave approval nonces are scoped per-request and cannot be replayed across different pending requests
  5. A user without `sfs_hr.manage` cannot trigger any of the 6 hiring conversion handlers — capability is verified before nonce
  6. The hiring role assignment pathway uses an allowlist; assigning the `administrator` role via conversion is blocked
  7. New employee welcome emails contain a password reset link, not a plaintext password
**Plans**: 2 plans

Plans:
- [x] 21-01: Fix Leave handler capability checks, nonce scoping, and self-approval prevention
- [x] 21-02: Fix Hiring capability gates, role allowlist, and plaintext password in welcome email

### Phase 22: Loans + Performance Auth Hardening
**Goal**: Loans nonce and capability checks execute in correct order with no DOM nonce exposure; Performance module goal handlers verify capability before writing
**Depends on**: Phase 21
**Requirements**: LOAN-AUTH-01, LOAN-AUTH-02, LOAN-AUTH-03, LOAN-AUTH-04, PERF-AUTH-01, PERF-AUTH-02, PERF-AUTH-03
**Success Criteria** (what must be TRUE):
  1. Loans nonces are scoped server-side and cannot be predicted or pre-generated by supplying a chosen `employee_id` value
  2. The Loans AJAX handler verifies its nonce before reading any POST data — `employee_id` is not accessed until after nonce validation passes
  3. Installment action nonces are not present in DOM data attributes — they are not readable in page source
  4. A user without the required Performance capability cannot update goal progress or save a goal via `ajax_update_goal_progress` or `save_goal()` — the handler rejects before any database write
  5. A department manager calling `check_read_permission` can only read performance data for employees in departments they manage; cross-department reads are denied
**Plans**: 2 plans

Plans:
- [ ] 22-01: Fix Loans nonce scoping, read-before-verify ordering, and DOM nonce exposure
- [ ] 22-02: Fix Performance module capability checks and department scope enforcement

### Phase 23: Frontend Tab Ownership Verification
**Goal**: Frontend portal tabs render only data that belongs to the authenticated employee; HR and GM access full team data through properly gated logic
**Depends on**: Phase 22
**Requirements**: FE-AUTH-01, FE-AUTH-02, FE-AUTH-03
**Success Criteria** (what must be TRUE):
  1. An employee accessing OverviewTab with a modified employee ID in the request receives an error or empty state — no other employee's data is rendered
  2. An employee accessing ProfileTab cannot view another employee's profile record by passing a different employee ID — the tab verifies ownership before rendering
  3. An HR user or GM accessing TeamTab sees all employees across all departments, not only direct reports of the current manager
**Plans**: 1 plan

Plans:
- [ ] 23-01: Fix OverviewTab and ProfileTab ownership verification; fix TeamTab role-based visibility

### Phase 24: Small Modules Auth Fixes
**Goal**: Assets, Core, Resignation, Settlement, Payroll, and Employees modules all enforce correct capability format, data ownership, and input validation with no unguarded export, upload, sync, or mutation path remaining
**Depends on**: Phase 23
**Requirements**: AST-AUTH-01, AST-AUTH-02, CORE-AUTH-01, RES-AUTH-01, RES-AUTH-02, RES-AUTH-03, SETT-AUTH-01, PAY-AUTH-01, EMP-AUTH-01
**Success Criteria** (what must be TRUE):
  1. Asset export requires `sfs_hr.manage` and enforces a row limit — an unprivileged or unauthenticated request receives an auth error, not a data file
  2. Asset invoice upload rejects any file whose MIME type is not on the allowlist — a file with a `.php` extension or non-image/non-document MIME type is blocked
  3. `handle_sync_dept_members()` checks capability before reading POST data — an unprivileged AJAX call returns an auth error without processing
  4. Resignation list views are department-scoped for managers — a manager cannot see resignations from departments they do not manage
  5. The resignation redirect URL is validated against allowed hosts — an external URL in the redirect parameter is rejected
  6. `handle_update()` in Settlement verifies the settlement record belongs to the currently authenticated user before writing any changes
  7. The `/payroll/my-payslips` endpoint uses an `sfs_hr.*` capability check — `is_user_logged_in()` alone no longer gates that endpoint
  8. The Employees admin menu capability is registered using dotted `sfs_hr.*` format, not a bare role name string
**Plans**: 2 plans

Plans:
- [ ] 24-01: Fix Assets capability + MIME allowlist and Core department sync auth
- [ ] 24-02: Fix Resignation scoping + redirect validation, Settlement ownership, Payroll capability, and Employees menu cap

## Progress

**Execution Order:**
Phases execute in numeric order: 20 → 21 → 22 → 23 → 24

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
| 20. Attendance Endpoint Auth | 1/1 | Complete    | 2026-03-17 | - |
| 21. Leave + Hiring Handler Auth | 2/2 | Complete    | 2026-03-17 | - |
| 22. Loans + Performance Auth | 2/2 | Complete    | 2026-03-17 | - |
| 23. Frontend Tab Ownership | 1/1 | Complete    | 2026-03-17 | - |
| 24. Small Modules Auth Fixes | v1.2 | 0/2 | Not started | - |
