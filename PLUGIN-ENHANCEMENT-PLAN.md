# Simple HR Suite — Plugin Enhancement Plan

**Date:** 2026-04-16 | **Version:** 2.2.5 | **Strategy:** Plugin-first, standalone SaaS later

This plan covers all enhancements, improvements, and missing features needed before the standalone SaaS build. Each milestone is scoped to deliver shippable value. Priorities are informed by the OSS competitive audit (OrangeHRM, Horilla, Frappe HRMS).

---

## Current Module Assessment

| Module | Completeness | Status |
|--------|-------------|--------|
| Attendance | 80% | Strongest module — policies, kiosk, geofencing, shifts |
| Surveys | 85% | Functional — needs export, templates, reminders |
| Leave | 75% | Functional — needs carry-forward logic, half-days, approval tiers |
| Settlement | 75% | Functional — Saudi law only, needs multi-country |
| Loans | 75% | Functional — zero-interest only, needs refinancing |
| Payroll | 70% | Functional — needs tax, formula flexibility, reversals |
| Hiring | 70% | Functional — needs assessments, offers, job requisitions |
| Resignation | 65% | Functional — needs notice enforcement, handover, exit survey |
| Documents | 60% | Basic — needs versioning, expiry alerts, compliance |
| Performance | 40% | Scaffolding — needs full 360, competencies, analytics |

---

## Milestone 1: Payroll Engine Overhaul

**Goal:** Replace fixed payroll calculations with an admin-configurable formula engine so different companies can define their own salary structures without code changes.

**Why first:** Identified as the biggest architectural gap in the OSS audit. Frappe HRMS's formula-based salary components allow HR admins to write calculation rules. Our fixed PHP logic limits every installation to the same calculation model.

### 1.1 — Formula-Based Salary Components

- Add `formula` TEXT column to `sfs_hr_salary_components` — admin-defined expressions (e.g., `basic * 0.4`)
- Add `condition` TEXT column — when the component applies (e.g., `grade = 'senior'`)
- Add `depends_on` TEXT column — ordered dependency resolution between components
- Build safe expression parser (use `symfony/expression-language` or a custom tokenizer — **no `eval()`**)
- Variables available in formulas: `basic`, `base`, `gross`, `net`, `working_days`, `absent_days`, `overtime_hours`, employee fields (`grade`, `department`, `tenure_years`)
- Admin UI: formula editor with syntax validation and preview
- Backward compatibility: existing fixed/percentage components continue working; formula is an additional calculation type

### 1.2 — Tax & Statutory Deductions

- Income tax slab definitions (configurable per country/region)
- Auto-calculate employee income tax per pay period
- GOSI calculation as a proper salary component (currently hardcoded 9.75% / 11.75%)
- Pension/social insurance as configurable statutory component
- Tax exemption tracking per employee

### 1.3 — Payroll Operations

- Payroll adjustment/correction mechanism (post-approval adjustments with audit trail)
- Payroll reversal for erroneous runs
- Payroll audit trail (who approved, when, what changed)
- Bank export formats: WPS file (Saudi bank format), SIF (Standard Interchange Format), CSV
- Payroll period locking (prevent edits to closed periods)
- Bulk payroll approval workflow (reviewer → approver → finance)

### 1.4 — Payslip Enhancements

- Payslip PDF template customization per company
- Payslip email distribution (batch send to employees)
- Year-to-date totals on payslip
- Payslip history comparison (month-over-month diff)

---

## Milestone 2: Leave System Enhancement

**Goal:** Bring leave management to feature parity with Frappe HRMS's 15-toggle leave type configuration.

### 2.1 — Carry-Forward & Expiry

- Implement carry-forward logic: per leave type toggle (on/off)
- Carry-forward cap: maximum days that roll over
- Carry-forward expiry: carried days expire after N months into new year
- Year-end processing cron: calculate carry-forward, expire old balances, open new year

### 2.2 — Leave Encashment

- Leave encashment request workflow (employee requests, manager approves)
- Encashment calculation: configurable daily rate formula
- Maximum encashable days cap per leave type
- Integration with payroll: encashment amount added as earning component in next payslip
- Encashment on separation: auto-calculate during settlement

### 2.3 — Compensatory Leave

- Compensatory leave request: employee works on holiday/off-day → submits request with proof
- Manager approval workflow
- Auto-credit leave balance on approval
- Expiry policy: compensatory leave expires after N days if unused
- Link to attendance: detect overtime/holiday work and prompt compensatory request

### 2.4 — Leave Request Improvements

- Half-day leave support (AM/PM selection)
- Hourly leave support (permission leave — common in Gulf)
- Multi-tier approval workflow: direct manager → department head → HR (configurable chain)
- Leave calendar visualization (team view for managers)
- Overlap detection improvement: warn on team capacity threshold (e.g., max 2 people from same team on leave)
- Leave request delegation: auto-assign approver when manager is on leave
- Bulk leave operations: HR can grant/adjust balances for multiple employees

### 2.5 — Additional Leave Types

- Maternity leave: configurable duration per country (Saudi: 70 days)
- Paternity leave
- Hajj leave (Saudi-specific: 10–15 days, once during employment)
- Iddah leave (bereavement leave for female employees per Islamic law)
- Marriage leave
- Exam leave (for employees studying)
- Each type with its own accrual rules, approval chain, and documentation requirements

---

## Milestone 3: Recruitment & Onboarding

**Goal:** Close the biggest feature gap vs. all three OSS competitors. Build a complete hiring pipeline from job requisition to employee onboarding.

### 3.1 — Job Requisitions

- Job requisition workflow: department head requests headcount → HR reviews → GM approves
- Position details: title, department, grade, salary band (min/mid/max), requirements
- Headcount tracking: approved vs. filled positions
- Requisition status: draft → pending_approval → approved → open → filled → cancelled

### 3.2 — Job Postings

- Create job postings from approved requisitions
- Arabic/English bilingual job descriptions
- Posting channels: internal (employee portal) + external (generates shareable link)
- Application form builder: configurable fields per job posting
- Auto-acknowledge receipt to applicants

### 3.3 — Applicant Tracking Enhancement

- Extend existing candidate pipeline with structured stages
- Interview scheduling: date, time, interviewer assignment, location/link
- Interview scorecard: configurable criteria per job, numeric scoring
- Reference check tracking: status, notes, contact details
- Background verification status tracking
- Candidate communication log: emails, calls, notes
- Candidate comparison view: side-by-side scorecard comparison

### 3.4 — Offer Management

- Offer letter generation from Arabic/English templates
- Dynamic merge fields: candidate name, position, salary, start date, benefits
- Offer approval workflow: HR drafts → hiring manager reviews → finance approves salary
- Offer status: draft → pending_approval → sent → accepted → rejected → expired
- Offer expiry: auto-expire after N days

### 3.5 — Onboarding Workflow

- Onboarding checklist templates: configurable per department/position
- Task types: document submission, IT setup, training, orientation, equipment
- Task assignment: auto-assign to responsible parties (IT, HR, manager)
- Task tracking: pending → in_progress → completed with due dates
- New hire portal: employee sees their onboarding checklist and progress
- Auto-convert candidate to employee on offer acceptance: create employee record, assign role, trigger onboarding
- Probation period tracking with review reminders

---

## Milestone 4: Performance Management Completion

**Goal:** Complete the performance module from scaffolding to a full goal-setting, review, and appraisal system.

### 4.1 — Goal & OKR Framework

- Goal hierarchies: company goals → department goals → individual goals (cascade)
- OKR support: Objective with measurable Key Results
- Goal alignment: link individual goals to department/company objectives
- Progress tracking: percentage, milestone-based, or binary
- Goal weighting for final score calculation
- Mid-cycle goal adjustment with history

### 4.2 — Review Cycles

- Review cycle configuration: name, period, participants, timeline
- Review types: self-assessment, manager review, peer review, 360-degree
- Review templates: configurable questions/criteria per cycle
- Rating scales: configurable (1–5, 1–10, letter grades)
- Competency-based assessment: define competencies per role, rate against them
- Review calibration: HR/management session to normalize scores across departments
- Review acknowledgment: employee signs off on final review

### 4.3 — Performance Analytics

- Individual performance dashboard: trend over time, goal completion rate, attendance score
- Department/team analytics: average scores, distribution, top/bottom performers
- Performance vs. attendance correlation
- Performance improvement plan (PIP): create, track milestones, review outcomes
- Promotion readiness indicators based on review scores and tenure

### 4.4 — 360-Degree Feedback

- Configurable feedback providers: self, manager, peers, direct reports, external
- Anonymous feedback option
- Feedback request workflow: manager selects reviewers → system sends invitations
- Feedback aggregation: average scores by category, display without identifying individual reviewers
- Feedback report generation

---

## Milestone 5: Attendance & Shift Enhancements

**Goal:** Add roster management, UTC normalization, compliance reporting, and shift flexibility.

### 5.1 — UTC Timestamp Normalization

- Add `punch_in_utc`, `punch_out_utc` DATETIME and `tz_offset` VARCHAR(6) columns to `sfs_hr_attendance_punches`
- Store all new punches in UTC + user-local-time + offset triple (OrangeHRM pattern)
- Backfill cron: convert existing timestamps using server timezone
- Session calculations use UTC for accuracy; display uses local time
- Support employees in different timezones within same company

### 5.2 — Roster & Shift Management

- Shift templates: named patterns (e.g., "4-on-2-off", "rotating week")
- Roster generation: auto-assign employees to shifts based on template + rules
- Roster calendar view: visual weekly/monthly grid
- Shift swap requests (enhance existing ShiftSwap module): employee requests, counterpart accepts, manager approves
- Shift bidding: employees bid for preferred shifts, manager assigns
- Minimum rest enforcement: prevent scheduling violations (e.g., <8 hours between shifts)

### 5.3 — Attendance Compliance

- Compliance reports: total hours worked vs. contracted, overtime compliance, rest period compliance
- Saudi labor law checks: maximum 8 hours/day (6 during Ramadan), mandatory rest periods
- Weekly off compliance: minimum 1 day off per 7 days
- Attendance exception management: admin can override flags (late, absent) with reason
- Fatigue tracking: alert when employee works N consecutive days without rest

### 5.4 — Biometric Integration API

- Standardized biometric device API: webhook endpoint that accepts punch data from external devices
- Supported formats: ZKTeco push protocol, generic JSON webhook
- Device registration and health monitoring
- Punch deduplication: handle duplicate pushes from devices
- Fallback: if device is offline, employees can punch via kiosk/web

---

## Milestone 6: Documents & Compliance

**Goal:** Build a proper document management system with expiry tracking, version control, and compliance features.

### 6.1 — Document Lifecycle

- Document categories: configurable (ID, passport, visa, contract, certificate, medical, etc.)
- Document versioning: upload new version, old version archived with history
- Expiry tracking with automatic notifications (30/15/7 days before expiry)
- Renewal workflow: HR requests renewal → employee uploads → HR verifies
- Bulk document upload for employee onboarding
- Document access control: per-category visibility (employee, manager, HR, finance)

### 6.2 — Compliance Dashboard

- Company-wide document compliance view: % of employees with valid documents
- Expiring documents report: sorted by urgency
- Missing documents report: employees missing required document types
- Iqama/visa expiry tracking (Saudi-specific: work permit compliance)
- Auto-flag employees with expired mandatory documents

### 6.3 — Document Templates

- Letter templates: employment certificate, salary certificate, experience letter, NOC
- Arabic/English bilingual templates
- Dynamic merge fields: employee name, position, salary, dates, company info
- One-click generation from employee profile
- Template management admin UI

---

## Milestone 7: Resignation & Offboarding

**Goal:** Build a structured exit process from resignation to final settlement.

### 7.1 — Notice Period Enforcement

- Notice period configuration per employment type (probation: 0 days, permanent: 30/60/90 days)
- Auto-calculate last working day from resignation date + notice period
- Notice period buyout: option to pay in lieu of notice
- Garden leave tracking (on notice but not working)
- Notice period adjustment: HR can extend/shorten with approval

### 7.2 — Offboarding Workflow

- Exit checklist templates: configurable per department
- Task types: equipment return, access revocation, knowledge transfer, exit interview, document collection
- Task assignment: auto-assign to IT (access), HR (documents), manager (knowledge transfer)
- Task tracking with due dates and escalation
- Equipment return tracking: link to Assets module — mark assets as returned/pending

### 7.3 — Exit Interview & Survey

- Exit interview form: configurable questions
- Anonymous option for sensitive feedback
- Exit reason categorization (better opportunity, compensation, management, relocation, personal)
- Exit analytics dashboard: trends in resignation reasons by department, tenure, role
- Retention risk indicators from exit data

### 7.4 — Settlement Integration

- Auto-trigger settlement calculation when resignation is approved
- Settlement checklist: pending items that block final payment (equipment return, loan clearance, document handover)
- Settlement approval workflow: HR calculates → finance reviews → management approves
- Final settlement report: consolidated view of all dues and deductions
- Post-separation: auto-schedule certificate of employment generation

---

## Milestone 8: Gulf Compliance Expansion

**Goal:** Extend labor law calculations beyond Saudi Arabia to support UAE, Bahrain, Kuwait, Oman, and Qatar.

### 8.1 — Multi-Country Labor Law Engine

- Country-specific rule sets stored in DB (not hardcoded)
- Rule configuration: gratuity formula, leave entitlements, notice periods, probation rules
- Company profile country selection drives which rules apply
- Override capability: company can customize rules within legal bounds

### 8.2 — Country-Specific Rules

**UAE:**
- Gratuity: 21 days/year for first 5 years, 30 days/year after (basic salary only)
- Annual leave: 30 calendar days after 1 year, 2 days/month during first year
- Sick leave: 90 days (15 full pay, 30 half pay, 45 unpaid)
- Notice period: 30 days (probation: 14 days)

**Bahrain:**
- Gratuity: 15 days/year for first 3 years, 1 month/year after
- Annual leave: 30 days after 1 year
- Sick leave: 55 days (15 full, 20 half, 20 unpaid)

**Kuwait:**
- Gratuity: 15 days/year for first 5 years, 1 month/year after (capped at 1.5 years salary)
- Annual leave: 30 days after 1 year
- Sick leave: 75 days (15 full, 10 three-quarter, 10 half, 10 quarter, 30 unpaid)

**Oman:**
- Gratuity: 15 days/year for first 3 years, 1 month/year after
- Annual leave: 30 days after 6 months
- Sick leave: 10 weeks (first 2 full, next 2 three-quarter, remaining half)

**Qatar:**
- Gratuity: 3 weeks/year for first 5 years, 1 month/year after
- Annual leave: 21 days for 1–5 years, 28 days after 5 years
- Sick leave: 12 weeks (first 2 full, next 4 three-quarter, rest unpaid)

### 8.3 — GOSI & Social Insurance

- Saudi GOSI: refine existing implementation as configurable payroll components
- UAE: No income tax, but WPS (Wage Protection System) file export
- Bahrain: SIO (Social Insurance Organization) contributions
- Kuwait: PIFSS contributions
- Make social insurance a pluggable system: country → contribution rules → payroll integration

### 8.4 — Hijri Calendar Support

- Hijri date display alongside Gregorian throughout the UI
- Hijri-based leave calculations where required (e.g., Hajj leave timing)
- Ramadan working hours: auto-apply 6-hour workday during Ramadan (existing shift override logic already supports this — formalize it)
- Islamic holiday detection (Eid al-Fitr, Eid al-Adha, National Day per country)

---

## Milestone 9: REST API Completion & Webhooks

**Goal:** Full REST API coverage for all modules + declarative webhook system for integrations.

### 9.1 — Missing REST Endpoints

| Module | Endpoints to Add |
|--------|-----------------|
| Hiring | CRUD for requisitions, postings, candidates, interviews, offers |
| Settlement | Calculate, create, approve, reject, list settlements |
| Resignation | Submit, approve, reject, list, status updates |
| Loans | Request, approve (GM/finance), list, payment history, skip installment |
| Employees | Full CRUD (currently admin-only pages) |
| Surveys | CRUD for surveys, questions; submit/list responses |
| Projects | CRUD for projects, employee assignments |
| Documents | Upload, list, update, delete, expiry reports |
| Onboarding | Checklist CRUD, task status updates (after Milestone 3) |

### 9.2 — API Infrastructure

- API versioning: `sfs-hr/v2` namespace (v1 remains for backward compatibility)
- OpenAPI/Swagger spec generation
- API rate limiting per user/role
- API key authentication (for external integrations, separate from WordPress session auth)
- Pagination standardization: cursor-based for large datasets
- Filtering/sorting parameter conventions across all endpoints
- Error response standardization (error code, message, field-level errors)

### 9.3 — Webhook System

- Webhook configuration table: `sfs_hr_webhooks` (url, events, secret, status, retry_policy)
- Supported events: employee.created, employee.updated, employee.terminated, leave.requested, leave.approved, leave.rejected, payroll.run.completed, attendance.punch, loan.approved, resignation.submitted, settlement.approved
- Payload: JSON with event type, timestamp, and relevant entity data
- HMAC signature verification: webhook secret used to sign payloads
- Retry logic: 3 retries with exponential backoff on failure
- Webhook delivery log: track successes, failures, response codes
- Admin UI: create/edit/test webhooks, view delivery history

---

## Milestone 10: Expense Management (New Module)

**Goal:** Add employee expense claim and advance management.

### 10.1 — Expense Claims

- Expense categories: travel, meals, accommodation, transportation, supplies, training, other
- Expense claim form: date, category, amount, description, receipt attachment
- Multi-line claims: one submission with multiple expense items
- Currency support: SAR primary, USD/EUR for travel
- Per-category spending limits configurable by role/grade
- Receipt image upload (required/optional per category)

### 10.2 — Approval Workflow

- Multi-tier approval: employee → direct manager → department head → finance
- Configurable approval thresholds: manager can approve up to X SAR, above requires finance
- Rejection with reason
- Partial approval: approve subset of items within a claim
- Auto-approve for amounts below threshold (configurable)

### 10.3 — Advances & Settlement

- Cash advance request workflow
- Advance tracking: outstanding balance per employee
- Expense claim against advance: deduct from outstanding advance
- Remaining advance refund tracking
- Integration with payroll: outstanding advances deducted from salary if not settled

### 10.4 — Reporting

- Expense reports by department, category, period
- Budget vs. actual tracking per department
- Policy violation flagging (over-limit claims, duplicate receipts)
- Export to CSV/Excel

---

## Milestone 11: Training & Development (New Module)

**Goal:** Track employee training, certifications, and development programs.

### 11.1 — Training Programs

- Training catalog: internal and external programs
- Training types: classroom, online, on-the-job, conference, certification
- Training request workflow: employee/manager requests → HR approves
- Training scheduling: dates, location, trainer, capacity
- Training cost tracking: per-program and per-employee

### 11.2 — Certifications

- Certification tracking: name, issuer, date obtained, expiry date
- Renewal reminders (30/15/7 days before expiry)
- Required certifications per role/position
- Compliance view: % of employees with required certifications

### 11.3 — Development Plans

- Individual development plans (IDP): link to performance review outcomes
- Skill gap analysis: required skills per role vs. employee's current skills
- Training recommendations based on skill gaps
- Development plan progress tracking

---

## Milestone 12: Automation & Workflow Engine

**Goal:** Build a rule-based automation system for HR workflows (inspired by Horilla's automations app).

### 12.1 — Automation Rules

- Trigger conditions: field change, date threshold, status transition, schedule
- Actions: send notification, update field, create task, escalate to manager
- Examples:
  - "When leave balance < 5 days, notify employee"
  - "When document expires in 30 days, notify HR"
  - "When employee completes 1 year, trigger annual review"
  - "When loan payment is 3 months overdue, notify finance"
- Admin UI: rule builder with condition + action pairs
- Rule testing: dry-run mode that shows what would trigger

### 12.2 — Notification Channels

- Email (existing)
- In-app bell notifications (existing)
- WhatsApp integration (via WhatsApp Business API)
- SMS gateway integration (for Saudi: Unifonic, Taqnyat)
- Notification preferences per employee: opt-in/out per channel per event type

### 12.3 — Scheduled Actions

- Probation review reminders (auto-create review task N days before probation ends)
- Contract renewal reminders
- Annual leave reminder ("You have X unused days, leave year ends in Y days")
- Birthday/work anniversary notifications to managers

---

## Milestone 13: Surveys Enhancement + Employee Engagement

**Goal:** Upgrade the existing surveys module and add engagement features.

### 13.1 — Survey Enhancements

- Survey templates: pre-built templates (employee satisfaction, exit, onboarding, pulse)
- Branching logic: conditional questions based on previous answers
- Survey scheduling: auto-distribute on schedule (e.g., monthly pulse survey)
- Non-respondent reminders: auto-remind after N days
- Response export: CSV/Excel with filtering
- Survey analytics: trend over time, department comparison, benchmark

### 13.2 — Employee Engagement

- Pulse surveys: short 3-5 question surveys on recurring schedule
- eNPS (Employee Net Promoter Score): standardized measurement
- Engagement dashboard: aggregated scores, trends, department heatmap
- Anonymous suggestion box: always-available channel for employee feedback
- Recognition feed: peer-to-peer kudos/recognition (lightweight gamification)

---

## Milestone 14: Reporting & Analytics Platform

**Goal:** Centralized reporting across all modules with export and visualization.

### 14.1 — Report Builder

- Predefined reports: headcount, attrition, leave utilization, payroll summary, attendance, hiring pipeline, training
- Custom report builder: select fields from any module, apply filters, group/aggregate
- Report scheduling: auto-generate and email on schedule (weekly/monthly)
- Export formats: CSV, Excel, PDF

### 14.2 — HR Analytics Dashboard

- Headcount trends: hires, terminations, net change over time
- Attrition rate by department, tenure, role
- Leave utilization patterns
- Payroll cost analysis: total cost, per-department, per-component breakdown
- Attendance patterns: late trends, overtime trends, absence rates
- Hiring funnel metrics: time-to-hire, offer acceptance rate, source effectiveness

### 14.3 — Compliance Reports

- GOSI contribution reports (Saudi)
- WPS file generation (UAE)
- Social insurance reports (per country)
- Workforce composition: nationality mix, gender ratio (Saudi Nitaqat compliance)
- Saudization percentage tracking and alerts

---

## Cross-Cutting Concerns (Apply Throughout All Milestones)

### Architecture Discipline

- **DAO/Service separation**: every new feature follows Service → DAO pattern. Services contain business logic, DAOs contain all `$wpdb` queries. No direct DB access from controllers, REST handlers, or AJAX handlers.
- **Forward-compatible schemas**: design all new tables/columns as if `company_id` will be added later. Use surrogate integer PKs, avoid composite natural keys that would need refactoring.
- **Interface-driven services**: define PHP interfaces for core services (PayrollCalculator, LeaveAccrual, SettlementCalculator). Implementations are swappable — critical for the standalone port.

### Internationalization

- All new UI strings wrapped in `__()` / `_e()` with `sfs-hr` text domain
- Add Arabic translation for every new string before committing
- All new date displays support Hijri toggle
- All currency displays respect company locale

### Security

- All new REST endpoints require explicit `permission_callback` with capability checks
- No new `wp_ajax_nopriv_` handlers without documented justification
- All new form handlers validate nonce
- All new DB queries use `$wpdb->prepare()`
- Formula parser must be sandboxed — no filesystem/network/eval access

### Testing Approach

- Manual testing on local WP environment (no automated test suite currently)
- Each milestone produces a testing checklist
- Settlement/payroll calculations verified against known-correct examples from Saudi labor law

---

## Execution Order & Dependencies

```
Milestone 1: Payroll Engine ──────────────────────────────┐
Milestone 2: Leave Enhancement ───────────────────────────┤
Milestone 3: Recruitment & Onboarding ────────────────────┤ (independent, can parallelize)
Milestone 4: Performance Completion ──────────────────────┤
Milestone 5: Attendance & Shifts ─────────────────────────┘
                                                          │
Milestone 6: Documents & Compliance ──────────────────────┤ (depends on M3 for onboarding docs)
Milestone 7: Resignation & Offboarding ───────────────────┤ (depends on M1 settlement integration)
Milestone 8: Gulf Compliance ─────────────────────────────┤ (depends on M1 payroll formulas)
                                                          │
Milestone 9: REST API & Webhooks ─────────────────────────┤ (depends on all modules being built)
Milestone 10: Expense Management ─────────────────────────┤ (depends on M1 payroll integration)
Milestone 11: Training & Development ─────────────────────┤ (depends on M4 performance link)
                                                          │
Milestone 12: Automation Engine ──────────────────────────┤ (depends on all modules for triggers)
Milestone 13: Surveys & Engagement ───────────────────────┤ (depends on M4 for 360 integration)
Milestone 14: Reporting & Analytics ──────────────────────┘ (depends on all modules for data)
```

**First wave (parallel):** Milestones 1, 2, 3, 4, 5
**Second wave:** Milestones 6, 7, 8, 10
**Third wave:** Milestones 9, 11, 12, 13, 14

---

## Success Criteria

The plugin is ready for the standalone SaaS build when:

- [ ] All 14 milestones delivered and stable
- [ ] Every module has full REST API coverage
- [ ] Every module follows DAO/Service pattern
- [ ] Arabic translations complete for all features
- [ ] Gulf compliance covers at minimum: Saudi Arabia, UAE, Bahrain
- [ ] Webhook system operational for all major events
- [ ] Settlement calculations verified against labor law for each supported country
- [ ] Payroll formula engine supports at least 3 different company salary structures
- [ ] Performance module handles a complete annual review cycle
- [ ] Recruitment module handles a complete hire from requisition to onboarding
