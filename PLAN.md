# UI/UX Modernization — Implementation Plan

## Summary

Transform the employee self-service portal into a **unified frontend portal** for all roles (Employee, Department Manager, HR, GM, Admin) with modern, app-like UI inspired by mewurk, efacility, Jibble, and Bayzat reference designs.

---

## Current State

- **Frontend:** Single `[sfs_hr_my_profile]` shortcode with 7 employee tabs (Overview, Leave, Loans, Resignation, Settlement, Attendance, Documents)
- **Admin:** 17 modules, 40+ admin pages, 70+ form actions — all in wp-admin
- **Roles:** 3 custom WP roles + department manager (via `departments.manager_user_id`) + GM/Finance (via WP options)
- **REST APIs:** Good coverage for Attendance, Payroll, Performance, Assets, Documents, ShiftSwap. Missing for Leave, Loans, Resignation, Settlement, Employee CRUD, Departments
- **CSS:** Single `pwa-styles.css` with CSS variables, dark mode, responsive breakpoints
- **Tab system:** PHP `TabInterface` with URL routing via `?sfs_hr_tab=xxx`

---

## Implementation Phases

### Phase 1: Role Detection & Navigation Framework (§10.0 foundation)

**Goal:** Extend the shortcode to detect the user's role and show role-appropriate navigation.

#### Step 1.1 — Role Resolver Service
- **Create** `includes/Frontend/Role_Resolver.php`
- **Method:** `resolve(int $user_id): string` — returns highest role: `admin`, `gm`, `hr`, `manager`, `employee`, `trainee`
- **Logic:**
  - `manage_options` → `admin`
  - User ID matches `get_option('sfs_hr_leave_gm_approver')` → `gm`
  - `current_user_can('sfs_hr.manage')` → `hr`
  - User in `sfs_hr_departments.manager_user_id` (active=1) → `manager`
  - Active employee record → `employee`
  - Trainee role → `trainee`
- **Method:** `get_permissions(string $role): array` — returns list of allowed tab slugs per role
- **Method:** `get_manager_dept_ids(int $user_id): array` — returns department IDs managed by user (reuse `Admin::manager_dept_ids()` logic)

#### Step 1.2 — Navigation Registry
- **Create** `includes/Frontend/Navigation.php`
- **Define** all available tabs with metadata:
  ```php
  [
    'slug' => 'overview', 'label' => 'Overview', 'icon' => 'user',
    'roles' => ['employee','trainee','manager','hr','gm','admin'],
    'section' => 'personal',  // personal | team | org | system
  ]
  ```
- **Sections** (visual grouping in nav):
  - `personal` — Overview, Leave, Loans, Resignation, Settlement, Attendance, Documents, Payslips
  - `team` — Team List, Team Attendance, Approvals (manager+)
  - `org` — All Employees, Attendance Dashboard, Leave Management, Reports & Analytics (HR+)
  - `system` — Shifts, Policies, Settings (admin only)
- **Method:** `get_tabs_for_role(string $role): array` — returns filtered, ordered tabs

#### Step 1.3 — Update Shortcode Entry Point
- **Modify** `Shortcodes::my_profile()` to:
  1. Call `Role_Resolver::resolve()` to get user role
  2. Call `Navigation::get_tabs_for_role()` to get allowed tabs
  3. Render tab navigation from the registry (not hardcoded)
  4. Route to the correct tab renderer based on `?sfs_hr_tab=` parameter
- **Keep** backward compatibility — existing employee tabs work exactly as before
- **Add** `data-role="manager"` attribute to the app shell for role-based CSS

#### Step 1.4 — Tab Renderer Dispatcher
- **Create** `includes/Frontend/Tab_Dispatcher.php`
- **Method:** `render(string $tab, string $role, array $emp, int $emp_id): void`
- Routes to the correct Tab class:
  - Existing tabs: `LeaveTab`, `LoansTab`, `ResignationTab`, `SettlementTab`
  - New tabs created in later phases
- **Fallback:** If tab not found or not permitted → render overview

#### Step 1.5 — Navigation UI Redesign
- **Desktop:** Icon sidebar (left) + content area (right) — like the teal HR SaaS reference
  - Icons with tooltip labels on hover
  - Section dividers between personal/team/org/system groups
  - Active item highlighted with brand color
  - Collapsible (icon-only ↔ icon+label)
- **Mobile:** Keep bottom tab bar but limit to 5 most relevant tabs
  - "More" tab (hamburger) for additional tabs
  - Tab order: most-used first based on role
  - Manager sees: Overview, Approvals, Team, Attendance, More
  - Employee sees: Overview, Leave, Attendance, Documents, More

**Files touched:**
- `includes/Frontend/Shortcodes.php` (modify shortcode handler)
- `includes/Frontend/Role_Resolver.php` (new)
- `includes/Frontend/Navigation.php` (new)
- `includes/Frontend/Tab_Dispatcher.php` (new)
- `assets/frontend/pwa-styles.css` (add sidebar + updated nav styles)

**Version bump:** 0.6.0

---

### Phase 2: Employee UI Modernization (§10.1 + §10.2)

**Goal:** Redesign the employee-facing tabs with modern card-based UI.

#### Step 2.1 — Design System CSS Update
- **Add** new CSS variables for the expanded color palette:
  - Leave type colors (8 distinct hues for balance cards)
  - Status colors (approved/pending/rejected/cancelled)
  - Card shadows, border-radius tokens
  - Typography scale (heading sizes, body, caption)
- **Add** reusable card component styles:
  - `.sfs-hr-card` — base card (surface bg, rounded corners, subtle shadow)
  - `.sfs-hr-stat-card` — KPI/metric card (big number + label)
  - `.sfs-hr-badge` — status pill badge (colored bg + text)
  - `.sfs-hr-timeline` — vertical timeline for punch/event history
  - `.sfs-hr-empty-state` — illustration + message for empty data
- **Add** form component upgrades:
  - Floating label inputs
  - Better select dropdowns
  - Inline validation styling

#### Step 2.2 — Leave Tab Redesign (§10.2)
- **Replace** leave balance table with color-coded cards:
  - Horizontal scroll strip on mobile, 2-col grid on desktop
  - Each card: colored header bar, leave type name, circular balance badge (remaining days), three metrics (Available / Consumed / Applied), mini progress ring
  - Tappable → pre-selects that leave type in the request form
- **Replace** leave history table with card-based list:
  - Each card: leave type tag (colored), date range, days count circle, status badge
  - Expandable details on tap
- **Add** KPI row at top: "X requests this year", "Y days remaining (annual)", "Z pending"
- **Add** empty state when no leave history

#### Step 2.3 — Overview Tab Refresh
- **Add** greeting header: "Good morning, [First Name]" + date/time (like Jibble)
- **Add** quick stats row: 3-4 mini metric cards
  - Attendance this period (% or days)
  - Leave balance (primary leave type)
  - Pending requests count
  - Next shift time (if attendance enabled)
- **Clean up** profile sections with better card grouping and spacing
- **Improve** profile completion indicator with progress ring

#### Step 2.4 — Other Tabs Polish
- **Loans tab:** Card-based loan history, status badges, expandable payment schedule
- **Resignation tab:** Cleaner form, timeline-style status tracking
- **Settlement tab:** Better card layout with status progression
- **Documents tab:** Grid view option for documents, better upload UX
- **Attendance tab:** Timeline-style punch history (green IN / red OUT badges with timestamps)

**Files touched:**
- `assets/frontend/pwa-styles.css` (major additions)
- `includes/Frontend/Tabs/LeaveTab.php` (rewrite rendering)
- `includes/Frontend/Shortcodes.php` (overview tab section)
- `includes/Frontend/Tabs/LoansTab.php` (polish)
- `includes/Frontend/Tabs/ResignationTab.php` (polish)
- `includes/Frontend/Tabs/SettlementTab.php` (polish)

**Version bump:** 0.7.0

---

### Phase 3: Manager & HR Frontend Views (§10.0 continued)

**Goal:** Add team management and approval views to the frontend portal.

#### Step 3.1 — REST API Expansion
Build REST endpoints for actions currently handled by `admin_post_*`:

**Leave endpoints** (highest priority — managers approve daily):
- `POST /sfs-hr/v1/leave/approve/{id}` — Approve leave request
- `POST /sfs-hr/v1/leave/reject/{id}` — Reject leave request
- `GET /sfs-hr/v1/leave/pending` — Get pending requests (filtered by role scope)
- `GET /sfs-hr/v1/leave/team/{dept_id}` — Get team leave requests

**Team endpoints:**
- `GET /sfs-hr/v1/team/employees` — Get employees for manager's departments
- `GET /sfs-hr/v1/team/attendance/today` — Today's attendance for team

**Loan endpoints:**
- `POST /sfs-hr/v1/loans/approve/{id}` — Approve loan
- `POST /sfs-hr/v1/loans/reject/{id}` — Reject loan
- `GET /sfs-hr/v1/loans/pending` — Get pending loans

#### Step 3.2 — Team List Tab (manager+)
- **Create** `includes/Frontend/Tabs/TeamTab.php`
- Employee list with avatar + name + status pill + position (like the teal SaaS reference)
- Search bar at top
- "All / My Team" toggle (for HR who manages all vs department managers)
- Tap employee → drill-down to their profile summary

#### Step 3.3 — Approvals Tab (manager+)
- **Create** `includes/Frontend/Tabs/ApprovalsTab.php`
- Unified approvals inbox combining: leave requests, loan requests, early leave, shift swaps, resignation
- Category filter sidebar/tabs: "All", "Leave", "Loans", "Early Leave", "Shift Swap"
- Each request as a card: employee name + avatar, request type tag, date/duration, status badge
- Inline approve/reject buttons (AJAX via REST API)
- Pending count badge on the navigation tab

#### Step 3.4 — Team Attendance Tab (manager+)
- **Create** `includes/Frontend/Tabs/TeamAttendanceTab.php`
- Today's view: employee list with clock-in time + status bar (green/red like Jibble)
- Filter tabs with counts: "All (45) / Clocked In (42) / On Break (0) / Not In (3)"
- Week navigation strip (M/T/W/T/F/S/S)
- Tap employee → drill-down to their daily details

**Files touched:**
- `includes/Modules/Leave/Rest/` (new REST endpoints)
- `includes/Modules/Loans/Rest/` (new REST endpoints)
- `includes/Frontend/Tabs/TeamTab.php` (new)
- `includes/Frontend/Tabs/ApprovalsTab.php` (new)
- `includes/Frontend/Tabs/TeamAttendanceTab.php` (new)
- `assets/frontend/pwa-styles.css` (additions)

**Version bump:** 0.8.0

---

### Phase 4: Admin Dashboard & Reports (§10.3)

**Goal:** Add attendance dashboard widgets and reporting views to the frontend portal.

#### Step 4.1 — Dashboard Tab (HR/GM/Admin)
- **Create** `includes/Frontend/Tabs/DashboardTab.php`
- Widget cards grid (like the Reporting & Analytics reference):
  - "Today's Attendance" gauge chart (on-time vs late — pure CSS/SVG, no chart library)
  - Clock-in method breakdown (Kiosk / Mobile / Web with icons)
  - "Clocked In / Not Clocked In" counters
  - Leave utilization donut (if leave module enabled)
  - Active employees count + pending requests count
- Time filter: "Today / 7D / 30D / 3M"
- Widgets clickable → navigate to filtered views

#### Step 4.2 — Employee Management Tab (HR/Admin)
- **Create** `includes/Frontend/Tabs/EmployeesTab.php`
- Employee list with: emp code, avatar+name, status pill, position, department
- Search + status filter
- "Add Employee" button → inline form or modal
- Tap employee → full profile view with all tabs (reuse existing profile rendering)

#### Step 4.3 — Leave Management Tab (HR/Admin)
- **Create** `includes/Frontend/Tabs/LeaveManagementTab.php`
- All leave requests across the organization
- Filter by: status, department, leave type, date range
- Bulk approve/reject for HR
- Leave type configuration (add/edit types)
- Holiday calendar management

#### Step 4.4 — Reports Tab (HR/GM/Admin)
- **Create** `includes/Frontend/Tabs/ReportsTab.php`
- Attendance reports (daily, period summary)
- Leave reports (utilization, balances)
- Export to CSV
- Date range picker
- Department filter

**Version bump:** 0.9.0

---

### Phase 5: Settings & Polish

#### Step 5.1 — Settings Tab (Admin only)
- Move key settings to frontend: attendance settings, leave settings, notification settings
- Keep advanced/technical settings in wp-admin

#### Step 5.2 — Payslips Tab (Employee)
- **Create** `includes/Frontend/Tabs/PayslipsTab.php`
- Monthly payslip cards with: period, gross, deductions, net
- Expandable detail view
- Download PDF button
- Uses existing `Payroll_Rest::my_payslips()` endpoint

#### Step 5.3 — Final polish
- Empty states with SVG illustrations for all tabs
- Loading skeletons for AJAX-loaded content
- Transition animations between tabs
- Print-friendly styles for payslips/reports

**Version bump:** 1.0.0

---

## Implementation Order (What to code first)

| Order | What | Why | Effort |
|-------|------|-----|--------|
| 1 | Phase 1 (Role detection + nav framework) | Foundation — everything else depends on this | Medium |
| 2 | Phase 2.1 (Design system CSS) | Shared styles used by all subsequent tabs | Small |
| 3 | Phase 2.2 (Leave tab redesign) | Highest-impact employee feature, contained scope | Medium |
| 4 | Phase 2.3 (Overview tab refresh) | Second most visible, greeting + stats | Small |
| 5 | Phase 3.3 (Approvals tab) | Most requested manager feature | Medium |
| 6 | Phase 3.2 (Team list) | Managers need to see their team | Small |
| 7 | Phase 3.4 (Team attendance) | Managers' daily workflow | Medium |
| 8 | Phase 4.1 (Dashboard) | HR/GM overview | Medium |
| 9 | Phase 2.4 (Other tabs polish) | Consistency pass | Small |
| 10 | Phase 4.2-4.4 (Admin views) | Full frontend coverage | Large |
| 11 | Phase 5 (Settings + polish) | Final mile | Medium |

---

## Technical Decisions

1. **No JavaScript framework** — Continue with vanilla JS + PHP server-rendered HTML. The PWA shell is already solid. Adding React/Vue would be a rewrite, not an enhancement.
2. **No chart library** — Use pure CSS/SVG for gauges, progress rings, and simple charts. Keeps the bundle tiny.
3. **REST API for all new write operations** — New manager/HR actions use `wp_rest` nonce + `fetch()`. Existing employee form submissions (leave request, loan request) keep working via `admin_post_*` but get REST alternatives.
4. **Progressive enhancement** — Each phase is independently shippable. Phase 1 adds the framework without changing existing UI. Phase 2 modernizes employee views. Phase 3+ adds new views.
5. **No breaking changes** — Existing shortcodes, URLs, and query parameters continue to work. New tabs are additive.

---

## Migration Notes (DB)

- **No new tables needed** for Phases 1-3
- Phase 1 may need a migration to add `sfs_hr_frontend_role` option for caching role assignments (optional optimization)
- All data queries reuse existing `$wpdb` calls from the admin pages — just exposed through REST endpoints or called from frontend Tab classes

---

## Files Created (New)

| File | Phase |
|------|-------|
| `includes/Frontend/Role_Resolver.php` | 1 |
| `includes/Frontend/Navigation.php` | 1 |
| `includes/Frontend/Tab_Dispatcher.php` | 1 |
| `includes/Frontend/Tabs/PayslipsTab.php` | 5 |
| `includes/Frontend/Tabs/TeamTab.php` | 3 |
| `includes/Frontend/Tabs/ApprovalsTab.php` | 3 |
| `includes/Frontend/Tabs/TeamAttendanceTab.php` | 3 |
| `includes/Frontend/Tabs/DashboardTab.php` | 4 |
| `includes/Frontend/Tabs/EmployeesTab.php` | 4 |
| `includes/Frontend/Tabs/LeaveManagementTab.php` | 4 |
| `includes/Frontend/Tabs/ReportsTab.php` | 4 |
| `includes/Modules/Leave/Rest/Leave_Rest.php` | 3 |
| `includes/Modules/Loans/Rest/Loans_Rest.php` | 3 |
