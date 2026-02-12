# Simple HR Suite

A comprehensive WordPress HR management plugin built for small-to-medium businesses. Covers the full employee lifecycle — from hiring and onboarding through daily attendance, leave management, payroll, performance tracking, and end-of-service settlement.

**Current Version:** 0.4.7
**Requires WordPress:** 5.8+
**Requires PHP:** 7.4+
**License:** Proprietary
**Author:** [hdqah.com](https://hdqah.com)

---

## Features at a Glance

| Module | Description |
|--------|-------------|
| **Employees** | Master data, profile management, CSV import/export, QR codes |
| **Departments** | Org structure, manager assignment, auto-role sync |
| **Attendance** | Shift scheduling, punch in/out, kiosk mode, geofence, selfie verification |
| **Leave** | Leave types, balances, multi-level approval, holiday calendar |
| **Payroll** | Salary components, payroll runs, payslips, GOSI, bank integration |
| **Loans** | Cash advance requests, GM/Finance approval chain, installment tracking |
| **Performance** | Attendance commitment, goals/OKRs, reviews, alerts, snapshots |
| **Hiring** | Candidate pipeline, trainee program, multi-stage approval, conversion to employee |
| **Employee Exit** | Resignation workflow, final exit tracking, end-of-service settlement |
| **Assets** | Inventory, assignment/return workflow, condition tracking, QR scanning |
| **Documents** | Employee document uploads, expiry tracking, update requests |
| **Shift Swap** | Employee-to-employee shift swap with manager approval |
| **Reminders** | Birthday and work anniversary notifications |
| **Workforce Status** | Real-time headcount dashboard, absence notifications |
| **PWA** | Progressive Web App with offline punch queueing and install prompts |

---

## Modules

### Employees

- Full employee profile: personal info, contact, job details, contract dates, payroll data, immigration/visa, driving license
- Arabic name fields for bilingual environments
- Photo/avatar with initials fallback
- QR code generation (for kiosk attendance scanning)
- CSV import and export
- Auto-create WordPress user from employee record
- Employee profile tabs: Overview, Attendance, Leave, Loans, Documents, Assets, Resignation, Settlement, Performance

### Departments

- Department CRUD with colour coding
- Manager and approver assignment per department
- Auto-role assignment on hire
- Department-scoped filtering across all modules

### Attendance

- **Shifts** — Configurable start/end times, weekly schedule with per-day overrides (custom times or day off), calculation modes (shift times or total hours)
- **Shift-level policies** — Clock-in/out methods (`self_web`, `kiosk`, `manual`), geofence enforcement (in/out separately), selfie capture modes (`in_only`, `in_out`, `all`), target hours for total-hours mode. Falls back to role-based policies when not set on the shift
- **Self-service widget** — `[sfs_hr_attendance_widget]` shortcode: live clock, punch buttons with state machine (in → break_start → break_end → out), fullscreen selfie overlay, geofence validation, pre-flight policy checks
- **Kiosk mode** — `[sfs_hr_kiosk]` shortcode: immersive full-screen terminal with QR scanner, auto-punch by scan order, selfie capture from QR camera, device-level geo-lock. Supports offline punch queueing via IndexedDB + Background Sync
- **Sessions** — Daily session records with statuses: `present`, `late`, `left_early`, `absent`, `incomplete`, `on_leave`, `holiday`, `day_off`. Automatic recalculation with segment evaluation
- **Early leave** — Automatic detection, employee can submit early leave requests, manager approval, 72-hour auto-reject cron
- **Devices** — Kiosk device management with geo-lock, selfie requirements, suggest times, offline toggle
- **Configurable period** — Attendance period can start on any day of the month (not just the 1st)

### Leave

- Configurable leave types with annual quotas, colour coding, gender restrictions, attachment requirements
- Leave balances: opening, accrued, used, carried over, closing (per year per type)
- Multi-level approval workflow with approval chain tracking
- Leave cancellation with manager re-approval
- Early return from leave
- Holiday calendar (single-day and multi-day with yearly repeat)
- Frontend: leave dashboard with KPI cards, request form, history
- Admin: request management with bulk actions, balance adjustments

### Payroll

- Salary components: earnings, deductions, benefits — fixed, percentage, or formula-based
- Employee-level component overrides
- Payroll periods (monthly, bi-weekly, weekly)
- Payroll run workflow: draft → calculating → review → approved → paid
- Auto-calculation from attendance (absence deductions, late deductions, overtime)
- Loan installment deductions
- GOSI deductions (employee + employer share)
- Bank transfer file generation (IBAN)
- PDF payslip generation
- Employee self-service payslip viewing

### Loans (Cash Advances)

- Employee loan request submission
- Two-level approval: GM → Finance
- GM can approve a different amount than requested
- Installment-based repayment schedule
- Payment tracking with skip capability
- Full audit history
- Integration with payroll for automatic deductions

### Performance

- Attendance commitment scoring (percentage-based)
- Weighted performance score: 40% attendance, 35% goals, 25% reviews
- Goals and OKRs with progress tracking
- Performance reviews (self, manager, peer) with configurable cycles
- Threshold-based alerts (e.g. below 80% commitment)
- Monthly and weekly performance snapshots
- Department-level analytics
- Chart.js dashboards

### Hiring

**Candidates:**
- Application tracking with multi-stage workflow: applied → screening → HR reviewed → department pending → department approved → GM pending → GM approved → hired/rejected
- Multi-level approval chain with notes at each stage
- Resume and cover letter uploads
- Conversion to employee with WordPress user creation

**Trainees:**
- Trainee onboarding program (3–6 months)
- University and education tracking
- Supervisor assignment, training period extension
- Conversion to candidate after completion

### Employee Exit

**Resignations:**
- Submission with notice period and handover notes
- Approval workflow with multi-level chain
- Final exit tracking for foreign employees: government reference, expected/actual exit dates, ticket booking status, exit stamp

**Settlements:**
- End-of-service gratuity calculation (based on years of service)
- Leave encashment for unused balance
- Final salary adjustments
- Other allowances and deductions
- Clearance checklist

### Assets

- Asset inventory: code, name, category, serial, model, purchase year, price, warranty
- QR code generation per asset
- Assignment workflow: pending employee approval → active → return requested → returned
- Selfie capture on employee acceptance
- Condition tracking: new, good, damaged, needs repair, lost
- Return request with photos

### Documents

- Document type management (ID copies, certificates, contracts, etc.)
- Expiry date tracking with status alerts
- Upload by employee or HR
- Update request workflow (HR requests, employee fulfils)
- Required vs optional documents per employee

### Shift Swap

- Employee-to-employee shift swap requests
- Two-step approval: target employee accepts → manager approves
- Request numbering (SS-YYYY-NNNN)
- Email notifications at each stage

### Reminders

- Automatic birthday and work anniversary reminders
- Configurable lead time (0, 1, or 7 days before)
- Recipient selection: HR managers, department manager, or all HR staff

### Workforce Status

- Real-time dashboard: present, absent, on leave, working counts
- Department-level breakdown
- Daily absent-employee notifications to department managers

### PWA (Progressive Web App)

- Service worker with offline page caching
- Web App Manifest for "Add to Home Screen"
- Offline punch queueing (IndexedDB + Background Sync)
- Install prompt on attendance pages
- Online/offline status detection with auto-sync

---

## Shortcodes

| Shortcode | Description | Parameters |
|-----------|-------------|------------|
| `[sfs_hr_my_profile]` | Employee self-service hub with tabs (Overview, Leave, Attendance, Loans, Resignation, Settlement, Documents, Assets) | — |
| `[sfs_hr_attendance_widget]` | Self-service punch in/out with clock, selfie, geofence | `immersive` (1/0, default 1) |
| `[sfs_hr_kiosk]` | Full-screen attendance kiosk with QR scanner | `device` (ID), `immersive` (1/0) |
| `[sfs_hr_leave_widget]` | Leave dashboard with KPI cards and recent requests | — |
| `[sfs_hr_resignation_submit]` | Resignation submission form | — |
| `[sfs_hr_my_resignations]` | Employee resignation history | — |
| `[sfs_hr_my_loans]` | Employee loan listing with balances | — |

A full reference with copy-to-clipboard is available in **HR Settings → Shortcodes**.

---

## REST API

All endpoints are under `/wp-json/sfs-hr/v1/` and require authentication via `X-WP-Nonce` header.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/attendance/status` | GET | Current punch state, allowed actions, method restrictions, cooldown |
| `/attendance/punch` | POST | Record a punch (in/out/break_start/break_end) with optional selfie and geo |
| `/attendance/scan` | GET | Validate a QR code scan and issue a short-lived scan token |
| `/attendance/verify-pin` | POST | PIN-based employee verification |
| `/attendance/shifts` | GET | List shifts |
| `/attendance/assign` | POST | Assign shift to employee |
| `/attendance/sessions/rebuild` | POST | Rebuild/recalculate session data |
| `/early-leave/request` | POST | Submit early leave request |
| `/early-leave/my-requests` | GET | List own early leave requests |
| `/early-leave/pending` | GET | List pending approvals (manager) |
| `/early-leave/review/{id}` | POST | Approve/reject early leave |
| `/assets/scan/{code}` | GET | Look up asset by QR code |
| `/assets/assign` | POST | Assign asset to employee |
| `/assets/return/request` | POST | Request asset return |
| `/documents/{employee_id}` | GET | List employee documents |
| `/documents/expiring` | GET | List expiring documents |
| `/shift-swap/request` | POST | Create shift swap request |
| `/shift-swap/respond/{id}` | POST | Accept/decline swap |
| `/shift-swap/my-requests` | GET | List own swap requests |
| `/performance/metrics` | GET | Employee performance metrics |
| `/performance/goals` | GET/POST | Goals CRUD |
| `/performance/goals/{id}/progress` | POST | Update goal progress |
| `/performance/reviews` | POST | Submit performance review |
| `/payroll/periods` | GET | List payroll periods |
| `/payroll/runs` | POST | Create/calculate payroll run |
| `/payroll/my-payslips` | GET | View own payslips |

---

## Database

The plugin creates **30+ tables** under the `wp_sfs_hr_` prefix. All migrations are idempotent and version-gated via the `sfs_hr_db_ver` option. Tables use `CREATE TABLE IF NOT EXISTS`, and columns are added with `add_column_if_missing()`.

Key table groups:

- **Core:** `employees`, `departments`
- **Attendance:** `attendance_shifts`, `attendance_sessions`, `attendance_punches`, `attendance_policies`, `attendance_policy_roles`, `attendance_devices`, `early_leave_requests`
- **Leave:** `leave_types`, `leave_requests`, `leave_balances`, `leave_cancellations`, `leave_request_history`, `holidays`
- **Payroll:** `salary_components`, `employee_components`, `payroll_periods`, `payroll_runs`, `payroll_items`, `payslips`
- **Loans:** `loans`, `loan_payments`, `loan_history`
- **Hiring:** `candidates`, `trainees`
- **Exit:** `resignations`, `settlements`, `exit_history`
- **Other:** `assets`, `asset_assignments`, `employee_documents`, `performance_snapshots`, `performance_goals`, `performance_reviews`, `performance_alerts`, `shift_swaps`, `audit_trail`

---

## Capabilities & Roles

| Capability | Scope |
|------------|-------|
| `sfs_hr.manage` | Full HR administration (employees, settings, hiring) |
| `sfs_hr.view` | View employee data |
| `sfs_hr.employee.edit` | Edit employee records |
| `sfs_hr.leave.review` | Approve/reject leave requests (department scoped) |
| `sfs_hr.leave.manage` | Manage leave types, settings, balances |
| `sfs_hr_attendance_clock_self` | Self-service punch in/out |
| `sfs_hr_attendance_clock_kiosk` | Access kiosk mode |
| `sfs_hr.attendance.view` | View attendance records |
| `sfs_hr_attendance_admin` | Manage attendance settings, devices, shifts |
| `sfs_hr_loans_gm_approve` | GM-level loan approval |
| `sfs_hr_loans_finance_approve` | Finance-level loan approval |
| `sfs_hr_assets_admin` | Manage assets |
| `sfs_hr_payroll_admin` | Manage payroll settings |
| `sfs_hr_payroll_run` | Execute payroll runs |
| `sfs_hr_payslip_view` | View own payslips |
| `sfs_hr_performance_view` | View performance metrics |
| `sfs_hr_resignation_finance_approve` | Finance approval for exit settlements |

---

## Cron Jobs

| Schedule | Purpose |
|----------|---------|
| Daily | Holiday reminders, resignation expiration check, absent-employee notifications, birthday/anniversary reminders, performance alert evaluation |
| Twice daily | Early leave request auto-reject (72 hours) |
| Weekly | Performance digest email |
| Monthly | Performance snapshot generation |

---

## Email Notifications

Centralised via `Helpers::send_mail()` with automatic HTML detection (skips `wpautop()` for pre-formatted HTML). Notifications are sent for:

- Leave request submission, approval, rejection, cancellation
- Loan request submission, GM approval, finance approval, rejection
- Resignation submission and approval
- Settlement finalisation
- Early leave requests and approvals
- Shift swap requests, acceptances, manager decisions
- Birthday and anniversary reminders
- Absent employee alerts
- Weekly/monthly performance digests

---

## Security

- **CSRF** — `wp_verify_nonce()` / `check_admin_referer()` on all form handlers and REST endpoints
- **XSS** — `esc_html()`, `esc_attr()`, `esc_js()` on output; `sfsEsc()` helper for inline JS innerHTML
- **SQL injection** — All queries use `$wpdb->prepare()` with parameterised placeholders
- **Capability checks** — Every admin page and REST endpoint verifies user capabilities
- **File uploads** — MIME type validation, WordPress media library integration

---

## Installation

1. Upload the `hr-suite` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **HR → Dashboard** to begin setup
4. Create departments, configure leave types, set up attendance shifts
5. Add employees (or import via CSV)
6. Place shortcodes on frontend pages for employee self-service

**Automatic updates** are supported via [GitHub Updater](https://github.com/afragen/github-updater) — the plugin includes the required `GitHub Plugin URI` and `Primary Branch` headers.

---

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- MySQL 5.7 / MariaDB 10.3 or higher
- HTTPS recommended (required for PWA, geofence, and camera features)

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed version history.
