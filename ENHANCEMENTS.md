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
- [ ] **Test** the early access functionality end-to-end
- [ ] **Document** what "early access" covers and expected behavior
- [ ] **Fix** any issues found during testing

---

## 3. Attendance Module Improvements

### 3.1 Configurable Attendance Period
- [ ] **Add** a settings field for attendance period (e.g., weekly, biweekly, monthly, custom date range)
- [ ] **Store** the configured period in the plugin options table
- [ ] **Update** attendance reports to respect the configured period
- [ ] **Update** absence analytics to use the configured period boundaries
- [ ] **Add** period presets (Calendar Month, Payroll Cycle, Custom)

### 3.2 Attendance Policies Page — Mobile Responsiveness
- [ ] **Audit** the attendance policies admin page on mobile viewports (320px–768px)
- [ ] **Fix** table layouts — use responsive tables or card-based layouts on small screens
- [ ] **Fix** form inputs and buttons for touch targets (minimum 44px)
- [ ] **Test** on actual mobile devices and browser DevTools
- [ ] **Ensure** all modals and dropdowns work on mobile

---

## 4. Performance Reports

### 4.1 Date Filtering on Performance Reports
- [ ] **Investigate** current report queries — confirm they use overall date range rather than filtered range
- [ ] **Update** report queries to respect user-selected date filters
- [ ] **Add** date range picker to performance report UI if not already present
- [ ] **Ensure** exported reports (CSV/PDF) also respect the selected date filter

### 4.2 Attendance Period Integration in Reports
- [ ] **Link** performance reports to the configurable attendance period (from item 3.1)
- [ ] **Allow** reports to be generated per-period automatically
- [ ] **Add** period comparison view (e.g., this month vs. last month)

---

## 5. Employee Profile Consolidation

### 5.1 Merge Admin Employee Profiles
- [ ] **Audit** all places where employee data is displayed to admins (Employee module, quick views, other module references)
- [ ] **Design** a single unified employee profile page for admins
- [ ] **Merge** data from all modules into the unified profile (leave balances, attendance summary, loans, assets, performance scores, documents)
- [ ] **Add** tabbed navigation on the unified profile (Overview, Leave, Attendance, Payroll, Performance, Documents, Assets)
- [ ] **Remove** or redirect duplicate/scattered profile views
- [ ] **Ensure** role-based access — tabs only visible if the admin has the relevant capability

---

## 6. Documentation

### 6.1 Full Plugin Documentation
- [ ] **Create** `docs/` directory with structured documentation
- [ ] **Write** Installation & Setup guide
- [ ] **Write** Configuration guide (General Settings, each module's settings)
- [ ] **Write** User guide for admins (managing employees, leave, attendance, payroll, etc.)
- [ ] **Write** User guide for employees (frontend shortcode usage)
- [ ] **Write** Developer guide (architecture, hooks/filters, extending modules, REST API)
- [ ] **Document** all database tables and their relationships
- [ ] **Document** all WordPress capabilities and roles
- [ ] **Document** all cron jobs and scheduled tasks
- [ ] **Add** FAQ / Troubleshooting section

---

## 7. Shortcodes Reference Page

### 7.1 Admin Shortcodes Display Page
- [ ] **Create** a "Shortcodes" tab or section within the main settings page
- [ ] **List** every available shortcode with its name, parameters, and description
- [ ] **Add** copy-to-clipboard button for each shortcode
- [ ] **Add** live preview or screenshot of each shortcode's output
- [ ] **Keep** the list auto-updated — pull from registered shortcodes dynamically

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
- [ ] **Audit** total page weight for admin and frontend pages (target < 500KB initial load)
- [ ] **Minify** and concatenate CSS/JS assets
- [ ] **Implement** lazy loading for non-critical UI sections (tabs, modals)
- [ ] **Add** AJAX pagination instead of loading full data tables at once
- [ ] **Implement** progressive loading indicators so users know data is coming
- [ ] **Cache** API responses on the client side where appropriate
- [ ] **Optimize** images and SVG assets
- [ ] **Consider** offline-capable features via the existing PWA/Service Worker infrastructure
- [ ] **Test** on throttled connections (3G simulation in DevTools)
- [ ] **Add** connection-quality detection to adjust data fetch sizes

---

## 10. Business Strategy (Selling the Plugin)

> These are strategic planning tasks, not code tasks. Tracked here for completeness.

### 10.1 Go-to-Market Plan
- [ ] **Research** competing WordPress HR plugins (WP ERP, OrangeHRM WP, Jejewe HR) — pricing, features, market positioning
- [ ] **Define** a pricing model (freemium with pro add-ons, flat license, per-employee pricing)
- [ ] **Set up** a product landing page on hdqah.com or a dedicated domain
- [ ] **Create** demo environment for potential buyers
- [ ] **Write** marketing copy highlighting unique features (modular design, PWA support, multi-language, etc.)
- [ ] **List** on WordPress plugin marketplaces (CodeCanyon / Envato, WordPress.org for free tier)
- [ ] **Set up** license key management and update delivery system (e.g., Easy Digital Downloads, WooCommerce + Software Licensing)
- [ ] **Plan** support channels (documentation, email, community forum)

### 10.2 WordPress Plugin vs. Standalone SaaS — Evaluation
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
| P2 — Medium | Attendance period in reports | 4.2 |
| P2 — Medium | Shortcodes reference page | 7.1 |
| P2 — Medium | Low-speed internet optimization | 9.1 |
| P3 — Low | Full plugin documentation | 6.1 |
| P3 — Low | Missing modules | 8.1 |
| P4 — Strategic | Go-to-market plan | 10.1 |
| P4 — Strategic | WP Plugin vs. SaaS evaluation | 10.2 |

---

*This file is the single source of truth for enhancement work. Update checkboxes as tasks are completed.*
