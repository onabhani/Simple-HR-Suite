# Simple HR Suite

WordPress plugin for managing employees, departments, leave, attendance, payroll, loans, and more — built with PHP (no Composer/npm), targeting Saudi/Arabic-speaking organizations.

## Commands

- Dev: `wp plugin activate hr-suite` (local WP environment)
- Deploy: Manual — upload plugin folder or push via GitHub Plugin URI updater; do not automate
- Cache clear: `wp cache flush` (WP-CLI) or flush opcache on host

## Architecture

The plugin bootstraps from `hr-suite.php` (autoloader, activation hooks, admin_init self-healing migrations). Business logic is organized into `includes/Modules/{ModuleName}/` with each module containing its own Admin pages, REST endpoints, Services, Cron jobs, Handlers, and Notifications. The frontend portal is a tab-based SPA driven by shortcodes with tab rendering handled by `includes/Frontend/Tab_Dispatcher.php`.

- Main plugin file: `hr-suite.php`
- Core services: `includes/Core/` (Helpers, Hooks, Capabilities, Notifications, AuditTrail, Company_Profile)
- Modules: `includes/Modules/{ModuleName}/`
- Frontend tabs: `includes/Frontend/Tabs/`
- Shortcode registration: see `includes/Frontend/Shortcodes.php` and individual modules — all shortcodes prefixed `sfs_hr_`
- Migrations: `includes/Install/Migrations.php` — idempotent `CREATE TABLE IF NOT EXISTS` + `add_column_if_missing()` pattern; no numbered migration files
- `sql/` — reference scripts for manual DB ops; never `require` or `include` these in plugin code
- Translations: `languages/{en,ar,ur,fil}.json`
- Config: Settings stored in `wp_options` under `sfs_hr_*` keys (e.g., `sfs_hr_attendance_settings`, `sfs_hr_company_profile`, `sfs_hr_notification_settings`)

### Module Status

- Active development: Attendance, Leave, Loans, Workforce_Status
- Stable / don't refactor without asking: Payroll, Settlement, Assets
- Stub / incomplete: Surveys, Projects, PWA

### Boundaries

- `assets/vendor/` — third-party JS/CSS; do not modify
- `.github/` — issue templates; changes require confirmation
- All database access uses `$wpdb` directly with `$wpdb->prepare()` — there is no ORM; never use raw interpolation
- REST API namespace: `sfs-hr/v1` — all custom endpoints live under this namespace
- No direct dependency on other plugins. Does not share DB tables with DOFS or SimpleFlow.

## Domain Terminology

| Term | Meaning |
|------|---------|
| Employee Code | Unique identifier for each employee (`employee_code` column), separate from WP user ID |
| Settlement | End-of-service financial settlement (مستحقات نهاية الخدمة) calculated on termination/resignation |
| Kiosk | Attendance punch-in/punch-out terminal rendered via `[sfs_hr_kiosk]` shortcode, uses selfie + geolocation |
| Shift | Attendance shift definition with start/end times and weekly overrides stored in `sfs_hr_attendance_shifts` |
| Session | A single attendance day record (punch-in/out pair) in `sfs_hr_attendance_sessions` |
| Policy | Attendance policy defining late/early thresholds, linked to roles via `sfs_hr_attendance_policy_roles` |
| Early Leave | A formal request to leave before shift end, requires manager approval |
| Leave Balance | Per-employee, per-leave-type annual entitlement tracked in `sfs_hr_leave_balances` |
| Tenure-based | Leave types (e.g., Annual) where entitlement increases with years of service |
| Gov Support | Government support program reminders (specific to Saudi labor programs like Hadaf/HRDF) |
| Dept Manager | User assigned as `manager_user_id` on a department — dynamically granted `sfs_hr.leave.review` cap |
| HR Responsible | Per-department HR contact (`hr_responsible_user_id`) for performance justifications |

## Conventions

- Language in code: English
- Language in UI strings: Arabic primary (`ar.json`), with English (`en.json`), Urdu, and Filipino translations
- Naming: All custom DB tables prefixed `sfs_hr_` (e.g., `{$wpdb->prefix}sfs_hr_employees`)
- PHP namespace: `SFS\HR\*` — maps to `includes/` directory via PSR-4-ish autoloader in `hr-suite.php`
- Constants: `SFS_HR_VER`, `SFS_HR_DIR`, `SFS_HR_URL`, `SFS_HR_PLUGIN_FILE`
- Option keys: always prefixed `sfs_hr_` (e.g., `sfs_hr_holidays`, `sfs_hr_db_ver`)
- Capabilities: dotted format `sfs_hr.*` (e.g., `sfs_hr.view`, `sfs_hr.manage`, `sfs_hr.leave.review`)
- Roles: prefixed `sfs_hr_` (e.g., `sfs_hr_employee`, `sfs_hr_manager`, `sfs_hr_trainee`, `sfs_hr_terminated`)
- Module pattern: each module has a `{Name}Module.php` entry point, often with `Admin/`, `Rest/`, `Services/`, `Cron/`, `Handlers/`, `Notifications/`, `Frontend/` subdirectories
- Text domain: `sfs-hr`

## Known Gotchas

- **Self-healing migrations on every admin load**: `admin_init` checks table/column existence and re-runs migrations if `sfs_hr_db_ver` is outdated — avoid changing `SFS_HR_VER` without testing migration idempotency
- **Version mismatch**: header says `1.9.2` but `SFS_HR_VER` constant is `1.9.1` — when bumping version, update BOTH the plugin header comment AND the `define()` in `hr-suite.php`
- **Dual hire date columns**: `sfs_hr_employees` has both `hire_date` and `hired_at` — both are maintained for backwards compatibility; use `hire_date` for new code
- **Dynamic capabilities**: `sfs_hr.leave.review` and `sfs_hr.view` are granted dynamically at runtime via `user_has_cap` filter based on department manager mapping — they won't appear in role editor plugins
- **Terminated employee login block**: Users with `sfs_hr_terminated` role or `status='terminated'` are blocked at the `authenticate` filter — but only after their `last_working_day` passes
- **Uninstall preserves everything**: `uninstall.php` only removes the main settings option — all tables, roles, and user meta survive plugin deletion intentionally, for data safety and re-activation
- **No Composer/npm**: There is no dependency manager — vendor assets are committed directly in `assets/vendor/`
- **Country default SA**: Company profile defaults to Saudi Arabia (`country => 'SA'`); labor law calculations (settlement, leave tenure) assume Saudi labor law

## Workflow Rules

- Always run `wp cache flush` after direct DB schema changes or option updates
- Never modify migration logic in `Migrations::run()` without verifying idempotency — all statements must be safe to re-run
- Never delete `sfs_hr_*` tables without explicit user confirmation — employee data is not recoverable
- When adding a new module, follow the existing pattern: create `{Name}Module.php`, register in `hr-suite.php` activation hook, and add table checks to the `admin_init` self-healing block
- When adding new DB columns, use the `add_column_if_missing()` helper in `Migrations.php` — never use `ALTER TABLE ADD COLUMN` directly
- After adding or changing any key in `en.json`, add the same key to `ar.json` before committing
