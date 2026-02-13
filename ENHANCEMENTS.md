# Simple HR Suite - Enhancement Roadmap

Tracked enhancement tasks for the Simple HR Suite plugin. Each item includes a priority, category, and description. Work through these one by one.

---

## Status Legend

- [ ] Not started
- [x] Completed

---

## 1. UX & Policy Simplification

### 1.1 Simplify Shift & Policy Configuration
- [ ] **Audit** all current ways to create/edit shifts and attendance policies
- [ ] **Consolidate** shift creation into a single, guided workflow (wizard or stepped form)
- [ ] **Reduce** redundant policy fields — merge overlapping options
- [ ] **Add** sensible defaults so admins can set up a shift in fewer clicks
- [ ] **Add** bulk-assign shifts to departments or employee groups
- [ ] **Improve** inline help text / tooltips for policy fields

> **Context:** Currently there are too many paths and options for setting shifts and attendance policies. The goal is a simpler, more intuitive experience without losing flexibility.

---

## 2. Bug Fixes & Performance

### 2.1 General Bug Audit
- [x] **Run** a full code review across all 17 modules for common bugs
- [ ] **Check** PHP error logs and WordPress debug output for warnings/notices
- [x] **Fix** any SQL injection, XSS, or CSRF vulnerabilities found
- [x] **Test** all AJAX endpoints for proper nonce verification and capability checks

### 2.2 Performance Audit
- [x] **Profile** database queries — identify slow queries (especially in Attendance and Leave modules which are the largest)
- [x] **Add** missing database indexes (reference existing `sql/performance-indexes.sql`)
- [x] **Implement** query caching for frequently accessed data (employee lists, department trees)
- [ ] **Audit** admin page load times and optimize asset loading
- [x] **Review** cron job efficiency (Reminders, Workforce Status modules)

### 2.3 Early Access Feature Review
- [x] **Test** the early access functionality end-to-end
- [x] **Document** what "early access" covers and expected behavior
- [x] **Fix** any issues found during testing

---

## 3. Attendance Module Improvements

### 3.1 Configurable Attendance Period
- [x] **Add** a settings field for attendance period (e.g., weekly, biweekly, monthly, custom date range)
- [x] **Store** the configured period in the plugin options table
- [x] **Update** attendance reports to respect the configured period
- [x] **Update** absence analytics to use the configured period boundaries
- [x] **Add** period presets (Calendar Month, Payroll Cycle, Custom)

### 3.2 Attendance Policies Page — Mobile Responsiveness
- [x] **Audit** the attendance policies admin page on mobile viewports (320px–768px)
- [x] **Fix** table layouts — use responsive tables or card-based layouts on small screens
- [x] **Fix** form inputs and buttons for touch targets (minimum 44px)
- [x] **Test** on actual mobile devices and browser DevTools
- [x] **Ensure** all modals and dropdowns work on mobile

---

## 4. Performance Reports

### 4.1 Date Filtering on Performance Reports
- [x] **Investigate** current report queries — confirm they use overall date range rather than filtered range
- [x] **Update** report queries to respect user-selected date filters
- [x] **Add** date range picker to performance report UI if not already present
- [x] **Ensure** exported reports (CSV/PDF) also respect the selected date filter

### 4.2 Attendance Period Integration in Reports
- [x] **Link** performance reports to the configurable attendance period (from item 3.1)
- [x] **Allow** reports to be generated per-period automatically
- [x] **Add** period comparison view (e.g., this month vs. last month)

---

## 5. Employee Profile Consolidation

### 5.1 Merge Admin Employee Profiles
- [x] **Audit** all places where employee data is displayed to admins (Employee module, quick views, other module references)
- [x] **Design** a single unified employee profile page for admins
- [x] **Merge** data from all modules into the unified profile (leave balances, attendance summary, loans, assets, performance scores, documents)
- [x] **Add** tabbed navigation on the unified profile (Overview, Leave, Attendance, Payroll, Performance, Documents, Assets)
- [x] **Remove** or redirect duplicate/scattered profile views
- [x] **Ensure** role-based access — tabs only visible if the admin has the relevant capability

---

## 6. Documentation

### 6.1 Full Plugin Documentation
- [x] **Create** `docs/` directory with structured documentation
- [x] **Write** System brochure (`docs/Simple-HR-Suite-Brochure.md`) — marketing-style overview of all 17 modules, self-service portal, manager tools, reporting, security, multi-language, mobile/offline, and quick implementation guide
- [ ] ~~**Write** Installation & Setup guide~~ *(skipped — brochure is non-technical)*
- [ ] ~~**Write** Configuration guide (General Settings, each module's settings)~~ *(skipped — brochure is non-technical)*
- [ ] ~~**Write** User guide for admins~~ *(skipped — brochure is non-technical)*
- [ ] ~~**Write** User guide for employees~~ *(skipped — brochure is non-technical)*
- [ ] ~~**Write** Developer guide~~ *(skipped — brochure is non-technical)*
- [ ] ~~**Document** all database tables~~ *(skipped — brochure is non-technical)*
- [ ] ~~**Document** all WordPress capabilities and roles~~ *(skipped — brochure is non-technical)*
- [ ] ~~**Document** all cron jobs and scheduled tasks~~ *(skipped — brochure is non-technical)*
- [ ] ~~**Add** FAQ / Troubleshooting section~~ *(skipped — brochure is non-technical)*

---

## 7. Shortcodes Reference Page

### 7.1 Admin Shortcodes Display Page
- [x] **Create** a "Shortcodes" tab or section within the main settings page
- [x] **List** every available shortcode with its name, parameters, and description
- [x] **Add** copy-to-clipboard button for each shortcode
- [x] **Add** live preview or screenshot of each shortcode's output
- [x] **Keep** the list auto-updated — pull from registered shortcodes dynamically

> **Current known shortcodes:** `[sfs_hr_leave_request]`, `[sfs_hr_my_leaves]`, and others registered in `includes/Frontend/Shortcodes.php`

---

## 8. Missing Modules

### 8.1 Identify and Implement Missing Modules
- [ ] **Audit** the current 17 modules against standard HR system features
- [ ] **Prioritize** missing modules by business value
- [ ] **Candidate modules to evaluate:**
  - [ ] Training & Development tracking
  - [ ] Employee Self-Service portal enhancements
  - [ ] Grievance / Complaints management
  - [ ] Travel & Expense management
  - [ ] Employee Surveys / Feedback
  - [ ] Organizational Chart
  - [ ] Timesheet management (distinct from attendance)
  - [ ] Benefits administration
- [ ] **Implement** each approved module following the existing modular architecture

---

## 9. Low-Speed Internet Optimization

### 9.1 Performance on Slow Connections
- [x] **Audit** total page weight for admin and frontend pages (target < 500KB initial load)
- [x] **Minify** and concatenate CSS/JS assets
- [x] **Implement** lazy loading for non-critical UI sections (tabs, modals)
- [x] **Add** AJAX pagination instead of loading full data tables at once
- [x] **Implement** progressive loading indicators so users know data is coming
- [x] **Cache** API responses on the client side where appropriate
- [x] **Optimize** images and SVG assets
- [x] **Consider** offline-capable features via the existing PWA/Service Worker infrastructure
- [x] **Test** on throttled connections (3G simulation in DevTools)
- [x] **Add** connection-quality detection to adjust data fetch sizes

---

## 10. UI/UX Modernization

> **Reference designs:** mewurk.com (attendance dashboard), efacility.ae (clock in/out, approvals, leave availability), Jibble (time tracking, team view), Bayzat (leave approvals), various Arabic HR SaaS (dashboard tiles, mobile app). Goal is a modern, app-like experience that's easier to read and navigate.

### 10.0 Frontend Portal for All Roles (P1 — Foundation)
- [ ] **Extend** the existing `[sfs_hr_my_profile]` shortcode with role-based view detection (Employee, Department Manager, HR, GM, Admin)
- [ ] **Add** department manager views: team attendance, leave/loan approvals, team employee list
- [ ] **Add** HR views: all employees, leave management, attendance dashboard, loans, settlements, payroll overview, documents, performance
- [ ] **Add** GM views: organization-wide dashboards, cross-department reports & analytics, all approvals, headcount/turnover/payroll summaries
- [ ] **Add** admin views: everything GM + HR has + shifts, policies, system settings, configuration
- [ ] **Build** REST API endpoints for all manager/HR/admin actions (currently in wp-admin via `admin-post.php`)
- [ ] **Add** role-based tab/navigation rendering — each role sees only their permitted sections
- [ ] **Keep** wp-admin as a fallback for plugin configuration only (settings, migrations, advanced config)
- [ ] **Ensure** the frontend portal is fully PWA-capable (offline, installable) — reuse existing service worker infrastructure
- [ ] **Design** for future app wrapping (Capacitor/TWA) — no wp-admin dependencies in the frontend portal

> **Why:** Moving all roles to the frontend creates a single app-like entry point, eliminates the need for users to access wp-admin, and makes the system ready to be wrapped as a native mobile app. The existing PWA shell + tab system + CSS framework are the foundation.

### 10.1 Employee Self-Service UI Redesign (P1)
- [ ] **Redesign** the entire self-service portal with modern card-based layouts
- [ ] **Replace** dense table views with scannable card components (leave history, loan history, documents)
- [ ] **Add** visual KPI cards at the top of each tab (leave balance summary, attendance stats, loan overview)
- [ ] **Improve** form design — floating labels, better spacing, grouped inputs, inline validation
- [ ] **Add** status badges with color coding (green=approved, orange=pending, red=rejected) consistently across all modules
- [ ] **Redesign** mobile bottom navigation with cleaner icons and active-state indicators
- [ ] **Improve** typography hierarchy — bigger headings, clearer section separation, better whitespace
- [ ] **Add** empty states with illustrations when no data (no leaves, no loans, etc.)

### 10.2 Leave Balance Visual Cards (P1)
- [ ] **Redesign** leave balances as color-coded cards (each leave type gets a unique color — like efacility's Leave Availability screen)
- [ ] **Add** circular balance badge on each card showing remaining days
- [ ] **Show** three metrics per leave type: Total Available, Consumed, Applied
- [ ] **Add** a mini progress bar or ring showing balance usage percentage
- [ ] **Make** cards tappable to go directly to that leave type's request form

### 10.3 Admin Attendance Dashboard Widgets (P1)
- [ ] **Add** "Today's Attendance" gauge chart widget showing on-time vs late-in counts (like mewurk dashboard)
- [ ] **Add** clock-in method breakdown (Kiosk / Mobile / Web counts with icons)
- [ ] **Add** "Clocked In / Not Clocked In" summary counters
- [ ] **Add** attendance calendar heat-map showing daily status at a glance
- [ ] **Make** widgets clickable — drill down to filtered employee lists

### 10.4 Clock In/Out Experience Redesign (Maybe Add)
- [ ] **Add** circular progress timer showing hours worked vs target hours (like efacility's clock screen)
- [ ] **Redesign** punch history as color-coded cards — green IN badge, red OUT badge
- [ ] **Show** location tag and clock-in method on each punch card
- [ ] **Add** daily total hours display with animated counter
- [ ] **Add** status indicator (Currently In / Currently Out) with visual cue

### 10.5 Card-Based Approval Interface (Maybe Add)
- [ ] **Redesign** leave/loan approval screens with card-based layout (like efacility's Approvals screen)
- [ ] **Add** bulk approve/reject buttons at the top with multi-select checkboxes
- [ ] **Show** request summary on each card: employee name, date, duration circle badge, leave type tag
- [ ] **Add** filter/sort options (by status, date, department, leave type)
- [ ] **Add** swipe-to-approve gesture on mobile

### 10.6 Admin Pages Visual Refresh (Maybe Add)
- [ ] **Audit** admin pages for visual consistency with the modernized self-service design
- [ ] **Replace** raw WP admin tables with styled card/table hybrid components
- [ ] **Add** summary stats/KPIs at the top of each admin module page
- [ ] **Improve** admin navigation — breadcrumbs, better tab styling
- [ ] **Add** quick-action buttons (approve, edit, view) with icons on list items

---

## 11. Business Strategy (Selling the Plugin)

> These are strategic planning tasks, not code tasks. Tracked here for completeness.

### 11.1 Go-to-Market Plan
- [ ] **Research** competing WordPress HR plugins (WP ERP, OrangeHRM WP, Jejewe HR) — pricing, features, market positioning
- [ ] **Define** a pricing model (freemium with pro add-ons, flat license, per-employee pricing)
- [ ] **Set up** a product landing page on hdqah.com or a dedicated domain
- [ ] **Create** demo environment for potential buyers
- [ ] **Write** marketing copy highlighting unique features (modular design, PWA support, multi-language, etc.)
- [ ] **List** on WordPress plugin marketplaces (CodeCanyon / Envato, WordPress.org for free tier)
- [ ] **Set up** license key management and update delivery system (e.g., Easy Digital Downloads, WooCommerce + Software Licensing)
- [ ] **Plan** support channels (documentation, email, community forum)

### 11.2 WordPress Plugin vs. Standalone SaaS — Evaluation
- [ ] **Document** current architecture constraints as a WP plugin
- [ ] **Evaluate** pros/cons of each approach:

| Factor | WordPress Plugin | Standalone SaaS |
|---|---|---|
| **Market reach** | Large existing WP user base | Broader, platform-independent |
| **Development speed** | Faster — leverages WP core | Slower — build auth, UI, infra from scratch |
| **Revenue model** | One-time license + renewals | Recurring subscription (higher LTV) |
| **Hosting/Ops** | Customer-managed | You manage (or use PaaS) |
| **Scalability** | Limited by WP/shared hosting | Full control |
| **Updates** | Manual or auto-update via WP | Instant for all users |
| **Data control** | Customer owns data | You host data (compliance concerns) |

- [ ] **Recommendation:** Consider a hybrid approach — continue the WP plugin for existing market, and evaluate a SaaS version (using Laravel, Django, or Node.js) as a Phase 2 product once plugin revenue validates the market
- [ ] **Estimate** development effort for SaaS rewrite vs. continued plugin enhancement
- [ ] **Decision:** Make a go/no-go decision on SaaS based on plugin sales traction after 6 months

---

## Priority Order (Suggested)

| Priority | Task | Section |
|---|---|---|
| P0 — Critical | Bug fixes & performance audit | 2.1, 2.2 |
| P0 — Critical | Performance reports date filtering fix | 4.1 |
| P1 — High | Simplify shift & policy configuration | 1.1 |
| P1 — High | Configurable attendance period | 3.1 |
| P1 — High | Attendance policies mobile responsiveness | 3.2 |
| P1 — High | Merge admin employee profiles | 5.1 |
| P1 — High | Early access review | 2.3 |
| **P1 — High** | **Frontend portal for all roles (foundation)** | **10.0** |
| **P1 — High** | **Employee self-service UI redesign** | **10.1** |
| **P1 — High** | **Leave balance visual cards** | **10.2** |
| **P1 — High** | **Admin attendance dashboard widgets** | **10.3** |
| P2 — Medium | Attendance period in reports | 4.2 |
| P2 — Medium | Shortcodes reference page | 7.1 |
| P2 — Medium | Low-speed internet optimization | 9.1 |
| P3 — Low | Full plugin documentation | 6.1 |
| P3 — Low | Missing modules | 8.1 |
| **P3 — Maybe** | **Clock in/out experience redesign** | **10.4** |
| **P3 — Maybe** | **Card-based approval interface** | **10.5** |
| **P3 — Maybe** | **Admin pages visual refresh** | **10.6** |
| P4 — Strategic | Go-to-market plan | 11.1 |
| P4 — Strategic | WP Plugin vs. SaaS evaluation | 11.2 |

---

*This file is the single source of truth for enhancement work. Update checkboxes as tasks are completed.*
