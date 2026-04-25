# Simple HR Suite — Standalone System Design Brief

**Plugin version at last brief refresh:** 3.0.1 (was 2.2.5 — the previous Arabic PDF brief was written against 2.2.5; many milestones called "planned" there have since shipped; this document supersedes that PDF).

**Purpose:** This document is a complete handoff for designing the standalone HR system. It is also the **fast-lookup reference for the existing plugin**: every active module, the data model, the REST API surface, the cross-cutting platform services, and the core business rules are catalogued here so future sessions can navigate the system without re-discovering it. Use it as the single source of truth for architectural decisions and as a map of what is built vs. still planned.

**Context:** The product owner has decided on a two-phase strategy:
1. **Phase A (current):** Continue building features in the WordPress plugin — it serves as the working specification and revenue generator
2. **Phase B (next):** Build a standalone system (not WordPress-based) with a web dashboard and mobile app consuming the same API

**What I need from this session:** Design the standalone system architecture — stack selection, database schema, API design, multi-tenancy model, module structure, and mobile app approach.

---

## PART 1: WHAT THE SYSTEM DOES

### Product Overview

Simple HR Suite is an HR management system built for Saudi/Gulf organizations. It handles the full employee lifecycle: hiring → onboarding → attendance → leave → payroll → performance → resignation → settlement. The primary market is Saudi Arabia, with expansion planned to UAE, Bahrain, Kuwait, Oman, and Qatar.

**Target users:**
- **HR managers:** Employee records, payroll processing, leave management, compliance reporting
- **Department managers:** Team attendance, leave approvals, performance reviews
- **Employees:** Self-service — punch in/out, request leave, view payslips, request loans
- **Finance:** Payroll approval, loan approval, settlement payment
- **General managers:** Final approvals on hiring, loans, resignations

**Languages:** Arabic (primary), English, Urdu, Filipino
**Direction:** RTL-first (Arabic), with LTR support
**Calendar:** Gregorian primary, Hijri display alongside
**Workweek:** Saturday–Thursday (Friday off). Some companies use Sunday–Thursday.
**Currency:** SAR (Saudi Riyal) primary, with multi-currency support planned

---

## PART 2: CURRENT MODULE INVENTORY

### 23 Active Modules (as of v3.0.1)

| # | Module | Status | What It Does |
|---|--------|--------|-------------|
| 1 | **Employees** | 80% | Employee master data, profiles, directory, status tracking, REST CRUD |
| 2 | **Departments** | Stable | Department hierarchy, manager assignment, HR responsible, default shift |
| 3 | **Attendance** | 90% | Punch clock, shifts, sessions, policies, geofencing, kiosk, early leave, M5 roster + UTC normalisation, biometric webhook |
| 4 | **Leave** | 90% | Leave types, requests, approvals, balances, tenure-based entitlements, **carry-forward + encashment + compensatory + half-day/hourly + multi-tier approval** (M2) |
| 5 | **Payroll** | 90% | Salary components, payroll runs, payslips, GOSI, overtime, **formula engine (M1.1), tax/statutory (M1.2), reversals/WPS export (M1.3), payslip distribution (M1.4)** |
| 6 | **Loans** | 80% | Cash advances, approval workflow (GM→Finance), installment tracking, payroll deduction, **REST CRUD + payment history (M9.1)** |
| 7 | **Performance** | 75% | Goals/OKRs, **full review cycles (self/manager/peer/360), competencies, calibration, alerts, snapshots, justifications** (M4) |
| 8 | **Hiring** | 85% | **Job requisitions, postings, applicant tracking, interview scorecards, reference checks, offer management, comm log** (M3) |
| 9 | **Resignation** | 90% | Multi-level workflow, daily termination, **notice enforcement, offboarding templates + tasks, exit interview** (M7); REST lifecycle (M9.1) |
| 10 | **Settlement** | 85% | End-of-service gratuity (multi-country labour law), leave encashment, clearance gates, hard-delete for pending (M9.1), REST |
| 11 | **Documents** | 80% | Employee document storage, expiry tracking, update requests, **versioning, compliance dashboard, letter templates** (M6); REST CRUD (M9.1) |
| 12 | **Assets** | Stable | Asset inventory, assignment/return workflows, QR codes |
| 13 | **ShiftSwap** | Stable | Shift swap requests between employees, manager approval |
| 14 | **Projects** | 70% | Project management, employee allocation, project shifts; full REST (M9.1) |
| 15 | **Surveys** | 90% | Survey builder, anonymous responses, results dashboard, REST (M9.1) |
| 16 | **Reminders** | Stable | Scheduled email reminders (document expiry, contract renewal, gov support, sick leave) |
| 17 | **Workforce Status** | Stable | Absence tracking, notifications to managers |
| 18 | **Employee Exit** | Stable | Exit checklist, termination status |
| 19 | **PWA** | Stub | Progressive Web App manifest/service worker — kiosk + employee shell |
| 20 | **Hiring → Onboarding** | 70% | Onboarding templates, task assignment on offer-accept, probation tracking (M3) |
| 21 | **Expenses** | 75% | Expense claims, categories, multi-tier approval, advances, payroll integration (M10) |
| 22 | **Training** | 70% | Training programs, sessions, enrolments, certifications, **skills catalogue + IDP gap analysis (M11.3)** |
| 23 | **Automation** | 50% | Rule-based triggers/actions, scheduled reminders (M12.1) |
| 24 | **Reporting** | 70% | Predefined + custom reports, HR analytics, compliance reports (M14) |

### Modules Now Built That Were "Planned" in the v2.2.5 PDF Brief

All six "planned" modules from the PDF have been delivered as part of M3, M7, M10, M11, M11.3, M12 work — none are still pending greenfield.

| PDF item | Delivered as | Status |
|----------|--------------|--------|
| Recruitment (High) | M3 — Hiring (requisitions, postings, ATS, interviews, offers) | ✅ shipped |
| Onboarding (High) | M3 — Hiring/Onboarding (templates, tasks, probation) | ✅ shipped |
| Offboarding (High) | M7 — Resignation/Offboarding (templates, tasks, exit interview) | ✅ shipped |
| Expense Management (Medium) | M10 — Expenses module | ✅ shipped |
| Training & Development (Medium) | M11 + M11.3 — Training + Skills/IDP | ✅ shipped |
| Automation Engine (Low) | M12.1 — Automation module | ✅ shipped (basic rules) |

---

## PART 3: COMPLETE DATABASE SCHEMA

### 37 Custom Tables (current)

All tables use `{prefix}sfs_hr_` naming. Below is the full schema.

---

#### CORE TABLES

**`employees`**
```
id              BIGINT PK AUTO_INCREMENT
user_id         BIGINT UNSIGNED (FK to auth user)
dept_id         BIGINT UNSIGNED NULL (FK to departments)
employee_code   VARCHAR(50) UNIQUE (e.g., "EMP-001")
first_name      VARCHAR(100)
last_name       VARCHAR(100)
email           VARCHAR(191)
phone           VARCHAR(50) NULL
position        VARCHAR(100) NULL
nationality     VARCHAR(100) NULL
national_id     VARCHAR(50) NULL
date_of_birth   DATE NULL
gender          ENUM('male','female') NULL
marital_status  VARCHAR(20) NULL
address         TEXT NULL
hire_date       DATE NULL (primary — use this for new code)
hired_at        DATE NULL (legacy duplicate — maintained for backward compat)
status          ENUM('active','on_leave','suspended','terminated','trainee') DEFAULT 'active'
contract_type   VARCHAR(50) NULL
contract_end    DATE NULL
probation_end   DATE NULL
last_working_day DATE NULL
bank_name       VARCHAR(100) NULL
bank_account    VARCHAR(50) NULL
iban            VARCHAR(50) NULL
basic_salary    DECIMAL(12,2) DEFAULT 0
photo_url       VARCHAR(500) NULL
emergency_contact_name    VARCHAR(100) NULL
emergency_contact_phone   VARCHAR(50) NULL
emergency_contact_relation VARCHAR(50) NULL
hidden_from_attendance    TINYINT(1) DEFAULT 0 (exempt from attendance tracking)
notes           TEXT NULL
created_at      DATETIME
updated_at      DATETIME
```

**`departments`**
```
id                    BIGINT PK
name                  VARCHAR(191) NOT NULL
name_ar               VARCHAR(191) NULL
manager_user_id       BIGINT UNSIGNED NULL (FK — dynamically grants leave.review cap)
hr_responsible_user_id BIGINT UNSIGNED NULL (FK — grants performance view)
parent_id             BIGINT UNSIGNED NULL (self-referencing hierarchy)
default_shift_id      BIGINT UNSIGNED NULL (FK to attendance_shifts)
auto_assign_role      VARCHAR(100) NULL (WP role assigned to new employees in this dept)
sort_order            INT DEFAULT 0
created_at            DATETIME
updated_at            DATETIME
```

**`leave_types`**
```
id              BIGINT PK
name            VARCHAR(100)
name_ar         VARCHAR(100) NULL
code            VARCHAR(20) NULL (ANNUAL, SICK_SHORT, SICK_LONG, EMERGENCY, UNPAID, etc.)
annual_quota    INT DEFAULT 0
is_paid         TINYINT(1) DEFAULT 1
is_annual       TINYINT(1) DEFAULT 0 (triggers tenure-based quota calculation)
special_code    VARCHAR(50) NULL (SICK_SHORT/SICK_LONG — enables 3-day backdating)
require_attachment TINYINT(1) DEFAULT 0
max_consecutive_days INT NULL
min_service_months INT DEFAULT 0 (minimum employment before eligible)
gender_restriction ENUM('all','male','female') DEFAULT 'all'
active          TINYINT(1) DEFAULT 1
display_order   INT DEFAULT 0
created_at      DATETIME
updated_at      DATETIME
```

**`leave_requests`**
```
id              BIGINT PK
employee_id     BIGINT UNSIGNED NOT NULL
type_id         BIGINT UNSIGNED NOT NULL (FK to leave_types)
request_number  VARCHAR(50) UNIQUE (LV-YYYY-NNNN)
start_date      DATE NOT NULL
end_date        DATE NOT NULL
days            INT (computed business days)
paid_days       INT DEFAULT 0
unpaid_days     INT DEFAULT 0
reason          TEXT NULL
status          ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending'
approved_by     BIGINT UNSIGNED NULL
approval_chain  LONGTEXT NULL (JSON: [{by, role, action, note, at}, ...])
rejection_reason TEXT NULL
attachment_id   BIGINT UNSIGNED NULL (FK to file storage)
decided_at      DATETIME NULL
created_at      DATETIME
updated_at      DATETIME
```

**`leave_balances`**
```
id              BIGINT PK
employee_id     BIGINT UNSIGNED NOT NULL
type_id         BIGINT UNSIGNED NOT NULL
year            YEAR NOT NULL
opening         DECIMAL(5,1) DEFAULT 0
accrued         DECIMAL(5,1) DEFAULT 0
carried_over    DECIMAL(5,1) DEFAULT 0
used            DECIMAL(5,1) DEFAULT 0
encashed        DECIMAL(5,1) DEFAULT 0
adjustment      DECIMAL(5,1) DEFAULT 0
notes           TEXT NULL
created_at      DATETIME
updated_at      DATETIME
UNIQUE(employee_id, type_id, year)
```

**`audit_trail`**
```
id              BIGINT PK
user_id         BIGINT UNSIGNED NULL
employee_id     BIGINT UNSIGNED NULL
action          VARCHAR(100) (employee_created, leave_approved, payroll_run, etc.)
entity_type     VARCHAR(50) NULL (employee, leave, payroll, etc.)
entity_id       BIGINT UNSIGNED NULL
old_value       LONGTEXT NULL (JSON)
new_value       LONGTEXT NULL (JSON)
ip_address      VARCHAR(45) NULL
user_agent      TEXT NULL
created_at      DATETIME
```

**`exit_history`**
```
id              BIGINT PK
resignation_id  BIGINT UNSIGNED NULL
settlement_id   BIGINT UNSIGNED NULL
user_id         BIGINT UNSIGNED NULL
event_type      VARCHAR(50)
meta            LONGTEXT NULL (JSON)
created_at      DATETIME
```

---

#### ATTENDANCE TABLES (10 tables)

**`attendance_punches`**
```
id              BIGINT PK
employee_id     BIGINT UNSIGNED NOT NULL
punch_time      DATETIME NOT NULL
punch_type      ENUM('in','out','break_start','break_end')
source          ENUM('kiosk','self_web','mobile','admin','api','biometric') DEFAULT 'self_web'
device_id       BIGINT UNSIGNED NULL
latitude        DECIMAL(10,7) NULL
longitude       DECIMAL(10,7) NULL
valid_geo       TINYINT(1) DEFAULT 1 (passed geofence check)
selfie_url      VARCHAR(500) NULL
pin_verified    TINYINT(1) DEFAULT 0
notes           TEXT NULL
created_at      DATETIME
INDEX(employee_id, punch_time)
INDEX(punch_time, punch_type)
```

**`attendance_sessions`** (daily work record — the core output)
```
id              BIGINT PK
employee_id     BIGINT UNSIGNED NOT NULL
work_date       DATE NOT NULL
shift_assign_id BIGINT UNSIGNED NULL
in_time         DATETIME NULL (first IN punch)
out_time        DATETIME NULL (last OUT punch)
break_minutes   SMALLINT UNSIGNED DEFAULT 0
break_delay_minutes SMALLINT UNSIGNED DEFAULT 0
no_break_taken  TINYINT(1) DEFAULT 0
net_minutes     SMALLINT UNSIGNED DEFAULT 0 (worked - breaks, before rounding)
rounded_net_minutes SMALLINT UNSIGNED DEFAULT 0 (after rounding rule)
overtime_minutes SMALLINT UNSIGNED DEFAULT 0
status          ENUM('present','late','left_early','absent','incomplete','on_leave','holiday','day_off')
flags_json      LONGTEXT NULL (JSON: {late, left_early, missed_punch, outside_geofence, no_selfie, overtime, manual_edit})
calc_meta_json  LONGTEXT NULL (internal calc state)
early_leave_approved TINYINT(1) DEFAULT 0
early_leave_request_id BIGINT UNSIGNED NULL
last_recalc_at  DATETIME
locked          TINYINT(1) DEFAULT 0
UNIQUE(employee_id, work_date)
```

**`attendance_shifts`** (shift template definition)
```
id              BIGINT PK
name            VARCHAR(100) NOT NULL
location_label  VARCHAR(100) NULL
location_lat    DECIMAL(10,7) NULL
location_lng    DECIMAL(10,7) NULL
location_radius_m SMALLINT UNSIGNED NULL
start_time      TIME
end_time        TIME
unpaid_break_minutes SMALLINT UNSIGNED DEFAULT 0
break_start_time TIME NULL
break_policy    ENUM('auto','punch','none')
grace_late_minutes TINYINT UNSIGNED DEFAULT 5
grace_early_leave_minutes TINYINT UNSIGNED DEFAULT 5
rounding_rule   ENUM('none','5','10','15') DEFAULT '5'
overtime_after_minutes SMALLINT UNSIGNED NULL
require_selfie  TINYINT(1) DEFAULT 0
overtime_buffer_minutes SMALLINT UNSIGNED NULL
clock_in_methods VARCHAR(255) NULL (JSON)
clock_out_methods VARCHAR(255) NULL (JSON)
break_methods   VARCHAR(255) NULL (JSON)
geofence_in     VARCHAR(20) NULL
geofence_out    VARCHAR(20) NULL
calculation_mode VARCHAR(20) NULL (shift_times | total_hours)
target_hours    DECIMAL(4,2) NULL
dept_id         BIGINT UNSIGNED NULL
dept_ids        TEXT NULL (JSON array)
weekly_overrides TEXT NULL (JSON: day-of-week time overrides)
period_overrides TEXT NULL (JSON: date-range overrides, e.g., Ramadan hours)
notes           TEXT NULL
active          TINYINT(1) DEFAULT 1
```

**`attendance_shift_assign`** (daily rota entry)
```
id, employee_id, shift_id, work_date (UNIQUE), is_holiday, override_json
```

**`attendance_emp_shifts`** (employee default shift history)
```
id, employee_id, shift_id, schedule_id, start_date, created_at, created_by
```

**`attendance_shift_schedules`** (rotation patterns)
```
id, name, cycle_days, anchor_date, entries(JSON), active, created_at, created_by
```

**`attendance_devices`** (kiosk/mobile/web endpoints)
```
id, label, type(kiosk|mobile|web), kiosk_enabled, kiosk_pin, geo_lock_lat/lng/radius,
allowed_dept_id, fingerprint_hash, qr_enabled, selfie_mode(inherit|never|in_only|in_out|all),
suggest_in/break_start/break_end/out_time, break_enabled, active, meta_json, last_sync_at
```

**`attendance_flags`** (exception tracking)
```
id, employee_id, session_id, punch_id,
flag_code(late|early_leave|missed_punch|outside_geofence|no_selfie|overtime|manual_edit),
flag_status(open|approved|rejected), manager_comment, created_at, resolved_at, resolved_by
```

**`attendance_audit`** (append-only trail)
```
id, actor_user_id, action_type, target_employee_id/punch_id/session_id,
before_json, after_json, created_at
```

**`early_leave_requests`**
```
id, employee_id, session_id, request_date, request_number(EL-YYYY-NNNN),
scheduled_end_time, requested_leave_time, actual_leave_time,
reason_type(sick|external_task|personal|emergency|other), reason_note,
status(pending|approved|rejected|cancelled), affects_salary,
manager_id, reviewed_by, manager_note, reviewed_at, created_at, updated_at
```

**`attendance_policies`** (role-based defaults)
```
id, name, clock_in_methods, clock_out_methods, break_methods,
clock_in_geofence, clock_out_geofence, calculation_mode, target_hours,
breaks_enabled, break_duration_minutes, active, created_at, updated_at
```

**`attendance_policy_roles`** (policy ↔ role mapping)
```
id, policy_id, role_slug (UNIQUE)
```

---

#### PAYROLL TABLES (6 tables)

**`salary_components`** (17 seeded defaults)
```
id, code(UNIQUE), name, name_ar, type(earning|deduction|benefit),
calculation_type(fixed|percentage|formula), default_amount,
percentage_of(base_salary|gross_salary), is_taxable, is_active, display_order,
description, created_at, updated_at

Seeded: BASE, HOUSING, TRANSPORT, FOOD, PHONE, OVERTIME, BONUS, COMMISSION,
        GOSI_EMP(9.75%), ABSENCE, LATE, LOAN, ADVANCE, OTHER_DED,
        GOSI_COMP(11.75%), MEDICAL
```

**`employee_components`** (per-employee overrides)
```
id, employee_id, component_id, amount, effective_from, effective_to,
is_active, notes, created_at, updated_at
```

**`payroll_periods`**
```
id, name, period_type(monthly|bi_weekly|weekly), start_date, end_date, pay_date,
status(upcoming|open|processing|closed|paid), notes, created_by, created_at, updated_at
UNIQUE(start_date, end_date)
```

**`payroll_runs`**
```
id, period_id, run_number, status(draft|calculating|review|approved|paid|cancelled),
total_gross, total_deductions, total_net, employee_count, notes,
calculated_at/by, approved_at/by, paid_at/by, created_at, updated_at
```

**`payroll_items`** (per-employee payroll record)
```
id, run_id, employee_id, base_salary, gross_salary,
total_earnings, total_deductions, net_salary,
working_days, days_worked, days_absent, days_late, days_leave,
overtime_hours, overtime_amount, attendance_deduction, loan_deduction,
components_json(JSON: [{code, name, amount}]),
bank_name, bank_account, iban,
payment_status(pending|paid|failed|hold), payment_reference,
notes, created_at, updated_at
```

**`payslips`**
```
id, payroll_item_id, employee_id, period_id, payslip_number(PS-YYYY-NNNN UNIQUE),
pdf_attachment_id, sent_at, viewed_at, created_at
```

---

#### PERFORMANCE TABLES (7 tables)

**`performance_goals`**
```
id, employee_id, title, description, target_date, weight, progress(0-100),
status(active|completed|cancelled|on_hold), category, parent_id,
created_by, created_at, updated_at
```

**`performance_goal_history`** — progress log
**`performance_reviews`** — type(self|manager|peer|360), ratings_json, status flow
**`performance_review_criteria`** — 6 seeded defaults (Job Knowledge 20%, Quality 20%, Productivity 15%, Communication 15%, Teamwork 15%, Attendance 15%)
**`performance_alerts`** — automated threshold alerts (severity, metric_value vs threshold)
**`performance_snapshots`** — monthly attendance commitment aggregates
**`performance_justifications`** — HR notes per review period

---

#### OTHER TABLES

**`candidates`** — hiring pipeline (status enum: applied→screening→hr_review→dept_approved→gm_approved→hired)
**`trainees`** — intern/trainee tracking with university, GPA, supervisor
**`loans`** — cash advances with approval workflow (pending_gm→pending_finance→active→repaid)
**`loan_payments`** — installment tracking with skip capability
**`resignations`** — multi-level workflow with final_exit tracking (Saudi foreign worker exit process)
**`settlements`** — EOS calculation with clearance checklist (asset/document/finance)
**`projects`** / **`project_employees`** / **`project_shifts`** — project management (stub)
**`surveys`** / **`survey_questions`** / **`survey_responses`** / **`survey_answers`** — survey system

---

#### TABLES ADDED SINCE v2.2.5 (≈25 new tables — total now ~60)

**Recruitment / Hiring (M3)**
- `requisitions` — headcount requests with approval chain (draft → pending_approval → approved → open → filled → cancelled)
- `job_postings` — public/internal postings derived from approved requisitions; bilingual AR/EN
- `job_applications` — applicant submissions per posting (separate from `candidates` master record)
- `interviews` — scheduled interview slots, interviewer, location/link
- `interview_scorecards` — per-criterion numeric scoring for interviewers
- `reference_checks` — reference contact + status + notes
- `candidate_comm_log` — email/call/note timeline per candidate
- `offers` — offer letter records, status (draft → pending_approval → sent → accepted → rejected → expired)
- `offer_templates` — bilingual templates with merge fields

**Onboarding (M3)**
- `onboarding_templates` / `onboarding_template_items` — checklist templates per dept/role
- `onboarding` / `onboarding_tasks` — instances and tasks per new hire, with auto-create-on-offer-accept

**Offboarding & Exit (M7)**
- `offboarding_templates` / `offboarding_tasks` — equipment return, access revocation, knowledge transfer
- `exit_interview_questions` / `exit_interviews` — anonymous-capable exit feedback

**Leave Enhancements (M2)**
- `leave_request_history` — append-only audit of approval chain transitions
- `leave_cancellations` — cancellation requests with refund-balance logic
- `leave_encashment` — encashment requests + payroll integration
- `leave_compensatory` — comp leave from holiday/off-day work, with expiry

**Documents (M6)**
- `document_versions` — version history per document
- `document_templates` — letter templates (employment certificate, salary cert, NOC) with merge fields

**Expenses (M10)**
- `expense_categories` — configurable categories with per-role spending limits
- `expense_claims` / `expense_items` — multi-line claims with receipts
- `expense_advances` — cash advance tracking with payroll deduction
- `expense_approvals` — multi-tier approval state log

**Training & Skills (M11 + M11.3)**
- `training_programs` / `training_sessions` / `training_enrollments` — catalogue + scheduling + per-employee enrolment
- `training_requests` — employee/manager training request workflow
- `training_certifications` / `training_cert_requirements` — certificate tracking + per-role required certs with expiry
- `skills` — skills catalogue (master)
- `role_skills` — required skill levels per position
- `employee_skills` — employee's current skill levels
- `idps` / `idp_items` — Individual Development Plans with linked goals/actions

**Gulf Compliance (M8)**
- `country_rules` — per-country labour-law parameters (gratuity formula, leave entitlements, notice periods, probation rules) — drives the Labor Law strategy classes (`Saudi_Labor_Law`, `UAE_Labor_Law`, `Bahrain_Labor_Law`, `Kuwait_Labor_Law`, `Oman_Labor_Law`, `Qatar_Labor_Law`)
- `social_insurance_schemes` — per-country contribution rules (Saudi GOSI, UAE WPS, Bahrain SIO, Kuwait PIFSS, Oman PASI, Qatar GRSIA)

**Platform / Integration (M9.2 + M9.3 + M12.2)**
- `webhooks` — declarative webhook config (url, events, secret, retry policy, status)
- `webhook_deliveries` — delivery log with retry tracking, response codes, HMAC verification trail
- `api_keys` — external integration keys (separate from WP session auth) with scope + rate-limit class
- `automation_*` (Automation module) — trigger/action/rule definitions for the no-code workflow engine

**Notifications (M12.2)**
- No new tables; SimpleNotify integration is via the `sfs_hr/notifications/sms_handler` and `sfs_hr/notifications/whatsapp_handler` filters (filter-based dependency inversion). The `whatsapp_enabled` setting key is in the existing `sfs_hr_notification_settings` option.

---

## PART 4: CORE BUSINESS LOGIC

### Settlement / Gratuity (Saudi Labor Law Article 84/85)

```
Daily Rate = basic_salary / 30

Gratuity (full):
  Years 0-5:  daily_rate × 15 × years  (half-month per year)
  Years 5+:   (daily_rate × 15 × 5) + (daily_rate × 30 × (years - 5))  (full month per year after 5)

Gratuity multiplier by trigger:
  Resignation < 2 years:     0%
  Resignation 2-5 years:     33.3% (1/3)
  Resignation 5-10 years:    66.6% (2/3)
  Resignation 10+ years:     100%
  Termination / Contract End: 100%

Leave Encashment = daily_rate × unused_leave_days
Settlement Total = gratuity + leave_encashment + final_salary + other_allowances - deductions
```

### Leave Calculation

```
Business Days: Saturday-Thursday (Friday off), excluding company holidays
Tenure-based Annual Leave:
  < 5 years service: 21 days/year
  ≥ 5 years service: 30 days/year
  Evaluated at employee's anniversary date, not Jan 1

Available = opening + accrued + carried_over - used
Sick leave: allows 3-day backdating
Overlap detection: row-level locking (FOR UPDATE) to prevent double-booking
```

### Attendance Session Engine (Punch → Session Algorithm)

```
1. Guards: reject future dates, protect locked/historical sessions
2. Leave/Holiday check: mark on_leave or holiday if applicable
3. Shift resolution cascade: daily override → period override → weekly override → employee default → dept default → system default
4. Punch window: shift_start - 2hrs to shift_end + overnight buffer
5. Punch processing: find first IN, last OUT; handle leading OUTs (close yesterday's session)
6. Segment evaluation: trim punches to shift boundaries, apply grace periods
7. Break deduction: auto (fixed deduct) | punch (measure actual) | none
8. Net = worked - breaks; rounded per rule (5/10/15 min)
9. Overtime = max(0, worked - scheduled)
10. Status: present | late | left_early | absent | incomplete | on_leave | holiday | day_off
11. Flags: late, left_early, missed_punch, outside_geofence, no_selfie, overtime, manual_edit
```

### Payroll Calculation

```
1. Pro-rata for mid-period hires: working_days_actual / working_days_full
2. Attendance aggregation: count present/absent/late/leave days + overtime
3. Absence reconciliation: subtract approved paid leave from absent count
4. Earnings: BASE (pro-rata), percentage components (of base or gross), OVERTIME (hours × hourly × 1.5)
5. Deductions: ABSENCE (daily_rate × genuine_absences), LATE (daily_rate × 0.5 × late_days), LOAN (installment), GOSI_EMP (9.75%)
6. Benefits: GOSI_COMP (11.75% employer), MEDICAL
7. Net = gross_earnings - total_deductions
```

### Role & Permission Model

```
Roles: employee, manager, trainee, administrator
Dynamic grants (runtime, per-request):
  - Department managers auto-get: view, leave.review, performance_view, attendance_view_team
  - HR responsible auto-gets: view, performance_view
  - Finance approver auto-gets: view, leave.review
  - Active employees auto-get: leave.request
  - Admins auto-elevated to all HR capabilities
```

---

## PART 5: COMPETITIVE INTELLIGENCE (from OSS audit)

### What We Learned from 3 OSS HR Systems

**OrangeHRM** (PHP/Symfony):
- DAO/Service separation per module — clean architecture pattern
- UTC + user-time + timezone-offset triple for attendance — we should adopt this
- Declarative API validation (`ParamRuleCollection`) — better than inline sanitization
- No payroll, no loans, no settlement — we're ahead on these
- No Arabic/RTL — we're ahead

**Horilla** (Python/Django):
- `HorillaCompanyManager` — transparent multi-company query filtering via custom model manager. Every model has company FK, manager auto-scopes queries. **Most pragmatic multi-tenancy pattern.**
- `HorillaModel` base class — all models inherit common fields (company, audit timestamps)
- Biometric integration (eSSL, ZKTeco)
- Compensatory leave as first-class concept
- Helpdesk module (HR ticketing) — unique feature

**Frappe HRMS** (Python/Frappe Framework):
- **Formula-based salary components** — HR admins write calculation formulas. Biggest gap in our payroll module.
- **Declarative webhook system** — per-entity event webhooks, no code required
- **Site-per-tenant multi-tenancy** (Bench system) — each tenant gets own database, DNS routing
- **Auto-generated REST APIs** from schema definitions — zero boilerplate
- **Gratuity Rule** — configurable EOS calculation per country (vs. our hardcoded Saudi law)
- **Full and Final Statement** — aggregates all separation dues into one document

### Our Competitive Advantages Over All Three

- Saudi labor law compliance (Article 84/85 settlement, tenure-based leave)
- GOSI awareness in payroll
- Arabic-first RTL interface
- Browser-based kiosk with selfie + geolocation (no hardware needed)
- Attendance policy engine with penalties and grace periods
- Government support reminders (Hadaf/HRDF)
- Hajj/Iddah leave types
- Ramadan shift overrides

---

## PART 6: ENHANCEMENT-PLAN DELIVERY STATUS

The 14 milestones from the original enhancement plan are mostly delivered. The 15 numbered "feature gaps to close" items are tracked here against current state.

| # | Feature | Milestone | Status |
|---|---------|-----------|--------|
| 1 | Configurable payroll engine (formula-based salary components) | M1.1 | ✅ shipped |
| 1a | Tax & statutory deductions; configurable GOSI; pension | M1.2 | ✅ shipped |
| 1b | Payroll reversals, WPS export, period locking | M1.3 | ✅ shipped |
| 1c | Payslip distribution + YTD totals | M1.4 | ✅ shipped |
| 2 | Recruitment module (requisitions, postings, ATS, interviews, offers) | M3 | ✅ shipped |
| 3 | Onboarding/offboarding workflows | M3 (onboarding) + M7 (offboarding) | ✅ shipped |
| 4 | Enhanced leave (carry-forward, comp, encashment, half-day/hourly, multi-tier approval) | M2 | ✅ shipped |
| 5 | Performance completion (360, competencies, calibration, PIPs) | M4 | ✅ shipped |
| 6 | Gulf compliance expansion (UAE/BH/KW/OM/QA labour law strategies + social insurance) | M8 (+ M8.4 Hijri) | ✅ shipped |
| 7 | Documents (versioning, expiry notifications, letter templates) | M6 | ✅ shipped |
| 8 | REST API completion across all modules | M9, M9.1, M9.2 | ✅ shipped (~270+ endpoints) |
| 9 | Webhook system (declarative per-entity events) | M9.3 | ✅ shipped |
| 10 | Expense management | M10 | ✅ shipped |
| 11 | Training & development + skill gap analysis | M11, M11.3 | ✅ shipped |
| 12 | Automation engine (rule-based triggers/actions) | M12.1 | 🟡 basic rules shipped, advanced rules pending |
| 12a | SMS/WhatsApp gateway integration (filter-based dispatch to SimpleNotify) | M12.2 | ✅ shipped |
| 13 | Reporting platform (custom reports, HR analytics, compliance reports) | M14 | ✅ shipped |
| 14 | Hijri calendar (display + calculations) | M8.4 | ✅ shipped |
| 15 | UTC timestamp normalisation for attendance | M5 | ✅ shipped |

### What's Still Open (work-in-progress / planned)

- **Advanced automation rules** (M12.x): pulling triggers from any field-level event, nested conditions, action fan-out — current engine handles common patterns but not arbitrary DAG-style flows.
- **Standalone-system port (Phase B)**: this document describes the spec; the SaaS implementation has not started.
- **Multi-tenant isolation**: still not enforced in the plugin (single-tenant per WP install). The standalone build will introduce `company_id` from day one — see PART 7.
- **Bulk/batch operations** in some admin pages where the v3 REST endpoints exist but UI still iterates.
- **Engagement / pulse-survey scheduling** (M13.x): survey builder is strong but recurring schedule + eNPS dashboards are partial.

---

## PART 6.5: REST API SURFACE (v1 + v2)

**Namespaces**: `sfs-hr/v1` (current production API, surface ~270 endpoints) and `sfs-hr/v2` (M9.2 — OpenAPI-spec'd, rate-limited, cursor-paginated; controllers being migrated incrementally — Employees controller is the reference impl).

**Endpoint count by module** (registered routes — counts include collection + item + sub-resource routes):

| Module | Routes | Notes |
|--------|--------|-------|
| Hiring | 37 | requisitions, postings, applications, interviews, scorecards, references, offers |
| Resignation | 25 | lifecycle (M9.1) — submit, approve, reject, cancel + offboarding tasks |
| Training | 26 + 12 (IDP) | programs, sessions, enrolments, certs, skills, IDPs |
| Performance | 21 | goals, OKRs, reviews, 360, calibration, alerts, snapshots |
| Surveys | 21 | surveys, questions, responses, anonymous submit, results |
| Attendance | 9 + 5 + 6 + 20 (M5) | admin REST + employee REST + early leave + roster/UTC |
| Expenses | 19 | claims, items, advances, approvals |
| Reporting | 13 | predefined + custom reports, scheduled exports |
| Payroll | 13 | components, periods, runs, payslips, reversals |
| Leave | 12 | requests, balances, encashment, comp leave, cancellations |
| Loans | 8 | request, approval, payments, skip installment |
| Documents | 5 | upload, list, versions, templates |
| Settlement | 6 | calculate, create, approve, hard-delete |
| Projects | 6 | projects, employee assignments, project shifts |
| Automation | 4 | rules, triggers, dry-run |
| Employees | 2 + V2 controller | basic CRUD + V2 reference impl |
| ShiftSwap | 2 + 2 (legacy) | swap requests, pending count |
| Webhooks (Core) | n/a | `Webhook_Service` + `Webhook_Rest` (admin-managed config) |
| Hijri (Core) | n/a | `Hijri_Service` + `Hijri_Rest` (date conversion endpoints) |
| API Keys (Core) | n/a | `Api_Key_Service` + `Api_Key_Rest` |

**v2 infrastructure (M9.2):**
- `Core/Rest/V2_Bootstrap.php` — controller registration
- `Core/Rest/OpenAPI_Generator.php` — spec generation (Developer admin page)
- `Core/Rest/Rate_Limiter.php` — per-key/per-role rate limits
- `Core/Rest/Rest_Response.php` — standardised error envelope
- `Core/Rest/Developer_Admin_Page.php` — API explorer + key issuance

---

## PART 6.6: CORE PLATFORM SERVICES

These are cross-cutting services that any module can call. They live under `includes/Core/` and are critical for the standalone port.

### Notifications (`Core/Notifications.php`)
- Event-driven: hooks into all major lifecycle actions (leave, attendance, employee, payroll, loan, contract, probation, daily digest)
- Channels: Email (native), SMS (native Twilio/Nexmo/Custom **with filter-based gateway override**), WhatsApp (filter-only via SimpleNotify)
- **Filter contract (M12.2)**: `apply_filters('sfs_hr/notifications/sms_handler', null, $context)` and `apply_filters('sfs_hr/notifications/whatsapp_handler', null, $context)` — listeners return a callable that takes `$context` and queues delivery. `is_safe_handler()` rejects string-name callables (e.g. `'system'`) so a hostile filter listener cannot trigger arbitrary PHP functions
- Locale-aware: `send_notification_localized()` switches locale to recipient's preference before building the message
- Digest queue: notifications can be deferred to a daily digest via the `dofs_should_send_notification_now` filter

### Labor Law Strategy (`Core/LaborLaw/`)
- `Labor_Law_Strategy` interface — country-agnostic contract for: gratuity calc, annual leave entitlement, sick leave structure, notice periods, probation rules, Friday/Saturday workweek
- Implementations: `Saudi_Labor_Law`, `UAE_Labor_Law`, `Bahrain_Labor_Law`, `Kuwait_Labor_Law`, `Oman_Labor_Law`, `Qatar_Labor_Law` — each encodes Article-level law
- `Labor_Law_Service` — facade that resolves the active strategy from company profile country
- `Hijri_Service` + `Hijri_Rest` — Hijri ↔ Gregorian conversion + Islamic holidays (M8.4)
- Active country selection drives gratuity formulas, leave entitlements, social insurance — settlement and payroll use this layer instead of hardcoded Saudi rules

### Social Insurance (`Core/SocialInsurance/`)
- `Social_Insurance_Service` — pluggable contribution rules (Saudi GOSI 9.75%/11.75%, UAE no-tax-but-WPS, Bahrain SIO, Kuwait PIFSS, Oman PASI, Qatar GRSIA)
- `WPS_Export` — UAE Wage Protection System file generation
- Country-aware: integrates with payroll component pipeline as a configurable component

### Webhooks (`Core/Webhooks/`)
- `Webhook_Service` — declarative event subscription, HMAC-signed delivery, retry policy with exponential backoff
- `Webhook_Rest` — admin REST for CRUD + delivery log inspection
- Events: `employee.created/updated/terminated`, `leave.requested/approved/rejected`, `payroll.run.completed`, `attendance.punch`, `loan.approved`, `resignation.submitted`, `settlement.approved`, etc.
- Tables: `webhooks`, `webhook_deliveries`

### API Keys (`Core/ApiKeys/`)
- `Api_Key_Service` — issue/rotate/revoke external integration keys (separate from WP session)
- Key scope + rate-limit class fed into `Rate_Limiter` for v2 endpoints
- Tables: `api_keys`

### Audit Trail (`Core/AuditTrail.php`)
- Centralised audit logging for all significant entity changes
- Captures: actor user, employee/entity context, before/after JSON, IP, user agent
- Append-only `audit_trail` table; entity-specific audits exist for attendance (`attendance_audit`)

### Company Profile (`Core/Company_Profile.php`)
- Single-tenant company settings: country (drives Labor Law), currency, calendar prefs, week structure, locale
- The standalone port will replace this with a `companies` table where each row is a tenant

### Capabilities & Roles (`Core/Capabilities.php`, `Core/Hooks.php`)
- Static role definitions: `sfs_hr_employee`, `sfs_hr_manager`, `sfs_hr_trainee`, `sfs_hr_terminated`
- **Dynamic capability grants** via `user_has_cap` filter: department managers get `sfs_hr.leave.review` + team-view caps, HR responsibles get `sfs_hr.performance.view`, finance approvers get loan caps. These are NOT visible in role-editor plugins
- Authentication filter blocks login for users with `status=terminated` after `last_working_day` passes

### Setup Wizard (`Core/Setup_Wizard.php`)
- First-run guided onboarding for the plugin itself: company profile, country, departments, leave types, shifts

---

## PART 6.7: ORIGINAL FEATURE-GAPS LIST (HISTORICAL)

The 15 items from the original v2.2.5 brief, kept here for traceability against PART 6 status table:

1. **Configurable payroll engine** — formula-based salary components (Frappe-inspired)
2. **Recruitment module** — job requisitions, postings, applicant tracking, interview scorecards, offer management
3. **Onboarding/offboarding workflows** — checklist-based, task assignment, equipment tracking
4. **Enhanced leave** — carry-forward with expiry, compensatory leave, leave encashment, half-day/hourly leave, multi-tier approval
5. **Performance completion** — 360-degree feedback, competency frameworks, review calibration, PIPs
6. **Gulf compliance expansion** — UAE, Bahrain, Kuwait, Oman, Qatar labor laws (gratuity formulas, leave rules, social insurance)
7. **Documents** — versioning, expiry notifications, letter templates (employment certificate, NOC)
8. **REST API completion** — all modules need full CRUD endpoints
9. **Webhook system** — declarative per-entity event webhooks
10. **Expense management** — claims, advances, receipt tracking, payroll integration
11. **Training** — programs, certifications, skill gap analysis
12. **Automation engine** — rule-based triggers and actions
13. **Reporting platform** — custom reports, HR analytics dashboard, compliance reports
14. **Hijri calendar** — display and calculation support
15. **UTC timestamp normalization** — timezone-aware attendance for multinational companies

---

## PART 7: STRATEGIC CONSTRAINTS & DECISIONS

### Already Decided
- **The standalone system is NOT WordPress.** It will be a purpose-built application.
- **The plugin remains alive** as a simpler self-hosted option for existing customers.
- **Multi-tenancy from day one.** The standalone system will support selling to multiple companies.
- **API-first.** Web dashboard and mobile app are both frontend clients consuming the same API.
- **Arabic-first RTL.** The system must be natively RTL with Arabic as the primary language.
- **Saudi law is the default,** but labor law rules must be configurable per country.
- **Mobile app is part of the same project,** not an afterthought. Employee self-service (punch, leave, payslips, approvals) must work on mobile from launch.

### Open Questions for the Design Session
1. **Stack selection:** Laravel (PHP — team familiarity) vs. Django (Python — stronger HR OSS ecosystem) vs. something else?
2. **Multi-tenancy model:** Database-per-tenant (strongest isolation, Frappe's approach) vs. schema-per-tenant vs. row-level isolation (Horilla's approach)?
3. **Mobile framework:** Flutter (single codebase) vs. React Native vs. native?
4. **Frontend framework:** Vue 3 vs. React for the web dashboard?
5. **Real-time:** WebSockets for live notifications/attendance updates?
6. **Formula engine:** How to implement admin-configurable salary formulas safely?
7. **File storage:** S3-compatible object storage per tenant?
8. **Queue system:** Redis + workers for payroll processing, notifications, report generation?
9. **Database:** PostgreSQL vs. MySQL/MariaDB?
10. **Deployment:** Docker + Kubernetes? Or simpler Docker Compose for initial scale?

### Scale Expectations (Year 1-2)
- 50-200 companies (tenants)
- 10-500 employees per company
- Peak load: 8:00 AM punch-in (all employees simultaneously)
- Payroll runs: monthly batch processing, largest company ~500 employees
- Geography: Saudi Arabia primary, Gulf region expansion

---

## PART 8: REFERENCE NUMBER FORMATS

| Entity | Format | Example |
|--------|--------|---------|
| Leave Request | LV-YYYY-NNNN | LV-2026-0042 |
| Resignation | RS-YYYY-NNNN | RS-2026-0008 |
| Settlement | ST-YYYY-NNNN | ST-2026-0005 |
| Early Leave | EL-YYYY-NNNN | EL-2026-0123 |
| Candidate | CND-YYYY-NNNN | CND-2026-0015 |
| Loan | LN-YYYY-NNNN | LN-2026-0003 |
| Payslip | PS-YYYY-NNNN | PS-2026-0401 |

Algorithm: `COUNT(*) + 1 WHERE number LIKE 'PREFIX-YYYY-%'`

---

## PART 9: KEY BUSINESS RULES SUMMARY

| Rule | Trigger | Effect |
|------|---------|--------|
| Tenure-aware leave quota | `is_annual=1` on leave type | Quota switches from 21→30 days at 5-year mark |
| Gratuity resignation multiplier | Resignation trigger type | 0% (<2y), 33% (2-5y), 67% (5-10y), 100% (10y+) |
| Sick leave backdating | `special_code=SICK_*` | Allow requests up to 3 days in the past |
| Absence-leave reconciliation | Payroll calculation | Don't deduct for approved paid leave days |
| Pro-rata salary | `hire_date > period_start` | Adjust earnings by `actual_days / full_days` |
| Overnight session closure | Leading OUT punches found | Retroactively close previous day's incomplete session |
| Shift resolution cascade | Every session recalc | Daily override → period override → weekly override → employee default → dept default |
| Break delay flagging | `break_policy=punch` | Compute excess break time, flag if over mandate |
| Geofence validation | Every punch | Mark `valid_geo=0` if outside device radius |
| Dynamic capability grants | Every permission check | Department managers auto-get leave.review + team views |
| Early leave auto-reject | 72-hour cron | Pending early leave requests auto-rejected after 72 hours |
| Terminated employee login block | Authentication filter | Block login after `last_working_day` passes |
| Loan one-per-fiscal-year | Loan request | Check no active loan in current fiscal year |
| Loan minimum service | Loan request | Employee must have ≥6 months tenure |
| Settlement clearance gates | Before payment | Asset return + document collection + finance sign-off required |
| Country-specific gratuity | Settlement calculation | Active `Labor_Law_Strategy` decides formula (SA/AE/BH/KW/OM/QA) — no hardcoded Saudi rules |
| Country-specific social insurance | Payroll calculation | `Social_Insurance_Service` resolves per-country contribution (GOSI/SIO/PIFSS/PASI/GRSIA) |
| Hijri date display | UI rendering | All date fields can render Hijri alongside Gregorian via `Hijri_Service` |
| WPS file export | UAE payroll closure | UAE-active companies emit Wage Protection System file on payroll approval |
| Probation review reminder | N days before probation_end | Auto-create review task for manager + HR |
| Comp leave from holiday work | Holiday/off-day attendance session | Prompt employee to file compensatory leave request, expires after N days if unused |
| Document expiry alert chain | 30 / 15 / 7 days before expiry | Notification cascade to employee → manager → HR |
| Webhook delivery retry | Delivery failure | 3 retries with exponential backoff; HMAC signature on every payload |
| Rate limit on v2 endpoints | Per API key + role class | `Core/Rest/Rate_Limiter.php` rejects with 429 above the configured budget |
| Filter-based SMS/WhatsApp dispatch | Every send | If a registered listener returns a safe callable, hand off; SMS otherwise falls back to native Twilio/Nexmo/Custom; WhatsApp otherwise drops silently |

---

## PART 10: WHAT THIS DOCUMENT ENABLES

With this document, you can:

1. **Design the database schema** for the standalone system — all 37+ tables with exact columns, types, and relationships are documented
2. **Design the API** — every entity, its fields, its status transitions, and its business rules are specified
3. **Design the module architecture** — 19 current + 6 planned modules with clear boundaries
4. **Make stack decisions** — you know the domain complexity, scale expectations, and multi-tenancy requirements
5. **Design the mobile app** — you know every employee self-service operation (punch, leave, payslips, loans, approvals)
6. **Port business logic** — formulas, algorithms, and edge cases are extracted, not just described
7. **Plan the Gulf expansion** — you know what's Saudi-specific and what needs to become configurable

**The plugin is the specification. This document is the blueprint. The standalone system is the product.**

---

## DOCUMENT REFRESH LOG

| Date | Plugin Version | What Changed |
|------|----------------|--------------|
| 2026-04-16 | 2.2.5 | Original brief — 19 active modules, 37 tables, 6 planned modules, 15 feature gaps |
| 2026-04-25 | 3.0.1 | Refresh after M1.x–M12.2: +4 active modules (Hiring/Onboarding wave, Expenses, Training, Automation, Reporting), ~25 new tables (~60 total), ~270+ REST endpoints, Labor Law strategy + Social Insurance + Hijri + Webhooks + API Keys + V2 OpenAPI + SimpleNotify integration. Original "planned modules" all shipped. |
