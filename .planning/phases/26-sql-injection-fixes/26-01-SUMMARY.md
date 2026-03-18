---
phase: 26-sql-injection-fixes
plan: "01"
subsystem: Frontend Tabs, Loans Module, Core Admin
tags: [sql-injection, security, capability-checks, wpdb-prepare]
dependency_graph:
  requires: []
  provides: [SQL-03, DEBT-02]
  affects:
    - includes/Frontend/Tabs/OverviewTab.php
    - includes/Frontend/Tabs/ProfileTab.php
    - includes/Frontend/Tabs/PayslipsTab.php
    - includes/Modules/Loans/LoansModule.php
    - includes/Core/Setup_Wizard.php
    - includes/Core/Company_Profile.php
tech_stack:
  added: []
  patterns:
    - "$wpdb->prepare() wrapping SHOW TABLES LIKE and SHOW COLUMNS FROM queries"
    - "sfs_hr.manage capability for HR admin page access"
key_files:
  created: []
  modified:
    - includes/Frontend/Tabs/OverviewTab.php
    - includes/Frontend/Tabs/ProfileTab.php
    - includes/Frontend/Tabs/PayslipsTab.php
    - includes/Modules/Loans/LoansModule.php
    - includes/Core/Setup_Wizard.php
    - includes/Core/Company_Profile.php
decisions:
  - "manage_options NOT changed in LoansModule.php check_tables_notice and install_tables_action — those are intentionally admin-only and out of scope for DEBT-02"
  - "SHOW COLUMNS FROM table name interpolation ({$loans_table}) retained as safe — comes from $wpdb->prefix which is trusted infrastructure"
metrics:
  duration: "~10 minutes"
  completed_date: "2026-03-18"
  tasks_completed: 2
  files_modified: 6
---

# Phase 26 Plan 01: SQL Injection Fixes — Frontend Tabs, Loans, and Capability Checks Summary

**One-liner:** Wrapped all unprepared SHOW TABLES/COLUMNS queries with `$wpdb->prepare()` in 4 files and replaced `manage_options` with `sfs_hr.manage` in Setup Wizard and Company Profile.

## What Was Built

### Task 1: Unprepared SHOW Queries — Frontend Tabs and Loans Module

Fixed 13 unprepared SQL queries across 4 files:

- **OverviewTab.php** (3 fixes): `SHOW TABLES LIKE '{$loans_table}'`, `'{$sess_table}'`, `'{$assign_table}'` — all now use `$wpdb->prepare( "SHOW TABLES LIKE %s", $var )`
- **ProfileTab.php** (1 fix): `SHOW TABLES LIKE '{$assign_table}'` — wrapped with prepare()
- **PayslipsTab.php** (1 fix): `SHOW TABLES LIKE '{$payslips_table}'` — wrapped with prepare()
- **LoansModule.php** (8 fixes):
  - 3x `SHOW TABLES LIKE '{$loans_table}'` in `check_tables_notice()`, `maybe_upgrade_db()`, and `on_activation()`
  - 5x `SHOW COLUMNS FROM {$loans_table} LIKE 'column_name'` in `maybe_upgrade_db()` and `on_activation()` — LIKE string value moved to `%s` placeholder; table name interpolation retained (safe, from `$wpdb->prefix`)

### Task 2: Capability Alignment — Setup Wizard and Company Profile

Replaced 9 `manage_options` references with `sfs_hr.manage`:

- **Setup_Wizard.php** (6 replacements): `add_submenu_page` capability arg + 5 `current_user_can()` checks in `nudge_notice()`, `handle_dismiss()`, `handle_finish()`, `handle_save()`, `render()`
- **Company_Profile.php** (3 replacements): `add_submenu_page` capability arg + `current_user_can()` in `handle_save()` and `render_page()`

WordPress administrators retain full access via `Capabilities.php` dynamic grant that gives all `sfs_hr.*` caps to users who have `manage_options`.

## Commits

| Task | Commit | Description |
|------|--------|-------------|
| 1    | 48430a3 | fix(26-01): wrap unprepared SHOW TABLES/COLUMNS queries with wpdb->prepare() |
| 2    | 963ec53 | fix(26-01): replace manage_options with sfs_hr.manage in Setup Wizard and Company Profile |

## Verification

- Zero unprepared `SHOW TABLES LIKE '` patterns in all 4 target files
- Zero `manage_options` references in Setup_Wizard.php (was 6) and Company_Profile.php (was 3)
- `check_tables_notice()` and `install_tables_action()` in LoansModule.php intentionally retain `manage_options` (out of scope for DEBT-02)

## Deviations from Plan

None — plan executed exactly as written. The plan explicitly called out that `manage_options` in LoansModule.php lines 66 and 90 should NOT be changed, which was honored.

## Self-Check: PASSED

- `includes/Frontend/Tabs/OverviewTab.php` — exists, 3 prepare() calls confirmed
- `includes/Frontend/Tabs/ProfileTab.php` — exists, 1 prepare() call confirmed
- `includes/Frontend/Tabs/PayslipsTab.php` — exists, 1 prepare() call confirmed
- `includes/Modules/Loans/LoansModule.php` — exists, 8 prepare() calls confirmed
- `includes/Core/Setup_Wizard.php` — exists, 6 sfs_hr.manage references confirmed
- `includes/Core/Company_Profile.php` — exists, 3 sfs_hr.manage references confirmed
- Commits 48430a3 and 963ec53 verified in git log
