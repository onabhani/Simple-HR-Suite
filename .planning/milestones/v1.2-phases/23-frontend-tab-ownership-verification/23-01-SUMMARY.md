---
phase: 23-frontend-tab-ownership-verification
plan: 01
subsystem: Frontend/Tabs
tags: [auth, ownership-check, role-visibility, frontend]
completed: "2026-03-17T15:05:06Z"
duration_minutes: 5

dependency_graph:
  requires: []
  provides:
    - FE-AUTH-01 (OverviewTab ownership guard)
    - FE-AUTH-02 (ProfileTab ownership guard)
    - FE-AUTH-03 (TeamTab role-based visibility)
  affects:
    - includes/Frontend/Tabs/OverviewTab.php
    - includes/Frontend/Tabs/ProfileTab.php
    - includes/Frontend/Tabs/TeamTab.php

tech_stack:
  added: []
  patterns:
    - Ownership check pattern: (int)($emp['user_id'] ?? 0) !== get_current_user_id()
    - Role-level threshold: level < 40 for manager-only scope

key_files:
  created: []
  modified:
    - includes/Frontend/Tabs/OverviewTab.php
    - includes/Frontend/Tabs/ProfileTab.php
    - includes/Frontend/Tabs/TeamTab.php

decisions:
  - OverviewTab silently returns on ownership failure (no error message) — landing tab UX; shortcode already handles missing employee record
  - ProfileTab shows error message on ownership failure — contains PII (salary, national ID, passport)
  - TeamTab level threshold set at 40 (hr role level) — HR, GM (50), Admin (60) all get org-wide view; manager (30) stays department-scoped

metrics:
  tasks_completed: 2
  tasks_total: 2
  files_changed: 3
---

# Phase 23 Plan 01: Frontend Tab Ownership Verification Summary

Closed all three frontend auth gaps identified in the v1.1 audit by adding ownership guards to OverviewTab and ProfileTab, and replacing the hardcoded manager-only scope in TeamTab with role-level-based visibility logic.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Add ownership guards to OverviewTab and ProfileTab | 71920e4 | OverviewTab.php, ProfileTab.php |
| 2 | Fix TeamTab role-based visibility for HR/GM/Admin | e1bfa6e | TeamTab.php |

## Changes

### Task 1: Ownership guards

**OverviewTab.php** — replaced `if ( ! is_user_logged_in() )` with:
```php
if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
    return;
}
```
Silent return matches the UX intent of the landing/default tab.

**ProfileTab.php** — replaced `if ( ! is_user_logged_in() )` with:
```php
if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
    echo '<p>' . esc_html__( 'You can only view your own profile.', 'sfs-hr' ) . '</p>';
    return;
}
```
Error message rendered because ProfileTab exposes high-value PII (salary, national ID, passport).

Both match the established pattern already used by LeaveTab, LoansTab, PayslipsTab, ResignationTab, and SettlementTab.

### Task 2: TeamTab role-based visibility

Replaced the hardcoded `$is_manager_only = true` block with:
```php
$is_manager_only = ( $level < 40 );

$dept_ids = [];
if ( $is_manager_only ) {
    $dept_ids = Role_Resolver::get_manager_dept_ids( $user_id );
    if ( empty( $dept_ids ) ) { ... return; }
}
```

HR (level 40), GM (level 50), and Admin (level 60) now get org-wide visibility. Managers (level 30) remain department-scoped. The previously dead `if ( ! $is_manager_only )` branch is now reachable.

## Deviations from Plan

None — plan executed exactly as written.

## Requirements Closed

- FE-AUTH-01: OverviewTab ownership guard
- FE-AUTH-02: ProfileTab ownership guard
- FE-AUTH-03: TeamTab role-based visibility for HR/GM/Admin

## Self-Check: PASSED

- FOUND: includes/Frontend/Tabs/OverviewTab.php
- FOUND: includes/Frontend/Tabs/ProfileTab.php
- FOUND: includes/Frontend/Tabs/TeamTab.php
- FOUND: .planning/phases/23-frontend-tab-ownership-verification/23-01-SUMMARY.md
- FOUND commit 71920e4: fix(23-01): add ownership guards to OverviewTab and ProfileTab
- FOUND commit e1bfa6e: fix(23-01): fix TeamTab role-based visibility for HR/GM/Admin
