# Requirements: Simple HR Suite

**Defined:** 2026-03-17
**Core Value:** Reliable, secure HR operations for Saudi organizations

## v1.2 Requirements

Requirements for auth & access control fix milestone. Each maps to roadmap phases.

### Attendance Auth

- [ ] **ATT-AUTH-01**: Unauthenticated `GET /attendance/status` endpoint must require authentication
- [ ] **ATT-AUTH-02**: Unauthenticated `POST /attendance/verify-pin` endpoint must require authentication with rate limiting
- [ ] **ATT-AUTH-03**: `handle_rebuild_sessions_day` must verify admin nonce before processing
- [ ] **ATT-AUTH-04**: `kiosk_roster` endpoint must not expose SHA-256 token hashes

### Leave Auth

- [ ] **LV-AUTH-01**: `handle_approve()` must check capability before processing approval
- [ ] **LV-AUTH-02**: `handle_cancel()` must check capability before processing cancellation
- [ ] **LV-AUTH-03**: `is_hr_user()` must not grant HR access to all sfs_hr_manager role users
- [ ] **LV-AUTH-04**: Approval nonce must be scoped per-request, not shared across all requests
- [ ] **LV-AUTH-05**: `handle_cancellation_approve()` must prevent HR self-approval at both stages

### Hiring Auth

- [ ] **HIR-AUTH-01**: Conversion methods must require `sfs_hr.manage` capability
- [ ] **HIR-AUTH-02**: All 6 unguarded handlers must verify capability before nonce
- [ ] **HIR-AUTH-03**: WP role assignment must use allowlist to prevent administrator escalation
- [ ] **HIR-AUTH-04**: Welcome email must not send plaintext password

### Assets Auth

- [ ] **AST-AUTH-01**: Asset export must enforce capability check and row limits
- [ ] **AST-AUTH-02**: Invoice upload must enforce MIME type allowlist

### Frontend Auth

- [ ] **FE-AUTH-01**: OverviewTab must verify employee ownership before rendering data
- [ ] **FE-AUTH-02**: ProfileTab must verify employee ownership before rendering data
- [ ] **FE-AUTH-03**: TeamTab must allow HR/GM to see all employees, not just managers

### Core Auth

- [ ] **CORE-AUTH-01**: `handle_sync_dept_members()` must check capability before reading POST data

### Loans Auth

- [ ] **LOAN-AUTH-01**: Nonce must not be scoped to user-supplied employee_id
- [ ] **LOAN-AUTH-02**: AJAX handler must verify nonce before reading employee_id from POST
- [ ] **LOAN-AUTH-03**: Nonce check must precede capability check in `handle_loan_actions()`
- [ ] **LOAN-AUTH-04**: Installment action nonces must not be embedded in DOM data attributes

### Performance Auth

- [ ] **PERF-AUTH-01**: `ajax_update_goal_progress` must check capability before processing
- [ ] **PERF-AUTH-02**: `check_read_permission` must enforce department scope for managers
- [ ] **PERF-AUTH-03**: `save_goal()` must check capability before saving

### Resignation Auth

- [ ] **RES-AUTH-01**: `Resignation_List::render()` must verify `sfs_hr.view` scoped to department
- [ ] **RES-AUTH-02**: Redirect URL must be validated against allowed hosts
- [ ] **RES-AUTH-03**: Department manager scope must be enforced on resignation views

### Settlement Auth

- [ ] **SETT-AUTH-01**: `handle_update()` must verify settlement belongs to current user

### Payroll Auth

- [ ] **PAY-AUTH-01**: `/payroll/my-payslips` must use proper capability check, not `is_user_logged_in()` fallback

### Employees Auth

- [ ] **EMP-AUTH-01**: Menu capability must use dotted `sfs_hr.*` format, not role name

## Future Requirements

Deferred to v1.3+. Tracked but not in current roadmap.

### SQL Injection / Prepared Statements

- **SQL-01**: Replace all bare ALTER TABLE with $wpdb->prepare() or add_column_if_missing()
- **SQL-02**: Replace information_schema queries with proper migration patterns
- **SQL-03**: Fix all unprepared SELECT/COUNT queries across 11 modules
- **SQL-04**: Fix raw string interpolation in LIKE clauses (Hiring, Core)

### Data Integrity

- **DATA-01**: Correct Settlement EOS formula from UAE to Saudi Article 84
- **DATA-02**: Fix Leave balance corruption (opening/carried overwritten on approval)
- **DATA-03**: Fix Loans monthly_installment column missing
- **DATA-04**: Fix Leave tenure boundary evaluation at Jan 1

### Performance

- **PERF-01**: Fix N+1 query patterns across 8+ modules
- **PERF-02**: Fix unbounded queries without pagination
- **PERF-03**: Add caching for repeated dashboard queries

### Logic & Workflow

- **LOGIC-01**: Fix TOCTOU races in concurrent submissions (6 modules)
- **LOGIC-02**: Fix state machine gaps (rejected→approved transitions)
- **LOGIC-03**: Fix Saudi weekend bug (Friday-only, missing Saturday)

## Out of Scope

| Feature | Reason |
|---------|--------|
| Medium/Low severity findings | Deferred to future milestone — focus on Critical/High first |
| JavaScript/CSS audit fixes | v1.1 was PHP-only audit |
| Unit/integration test creation | Separate effort |
| New feature development | Fix-only milestone |
| Third-party vendor code | Not our code |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| ATT-AUTH-01 | — | Pending |
| ATT-AUTH-02 | — | Pending |
| ATT-AUTH-03 | — | Pending |
| ATT-AUTH-04 | — | Pending |
| LV-AUTH-01 | — | Pending |
| LV-AUTH-02 | — | Pending |
| LV-AUTH-03 | — | Pending |
| LV-AUTH-04 | — | Pending |
| LV-AUTH-05 | — | Pending |
| HIR-AUTH-01 | — | Pending |
| HIR-AUTH-02 | — | Pending |
| HIR-AUTH-03 | — | Pending |
| HIR-AUTH-04 | — | Pending |
| AST-AUTH-01 | — | Pending |
| AST-AUTH-02 | — | Pending |
| FE-AUTH-01 | — | Pending |
| FE-AUTH-02 | — | Pending |
| FE-AUTH-03 | — | Pending |
| CORE-AUTH-01 | — | Pending |
| LOAN-AUTH-01 | — | Pending |
| LOAN-AUTH-02 | — | Pending |
| LOAN-AUTH-03 | — | Pending |
| LOAN-AUTH-04 | — | Pending |
| PERF-AUTH-01 | — | Pending |
| PERF-AUTH-02 | — | Pending |
| PERF-AUTH-03 | — | Pending |
| RES-AUTH-01 | — | Pending |
| RES-AUTH-02 | — | Pending |
| RES-AUTH-03 | — | Pending |
| SETT-AUTH-01 | — | Pending |
| PAY-AUTH-01 | — | Pending |
| EMP-AUTH-01 | — | Pending |

**Coverage:**
- v1.2 requirements: 32 total
- Mapped to phases: 0
- Unmapped: 32 ⚠️

---
*Requirements defined: 2026-03-17*
*Last updated: 2026-03-17 after initial definition*
