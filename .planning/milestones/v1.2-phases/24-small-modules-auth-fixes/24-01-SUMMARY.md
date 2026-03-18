---
phase: 24-small-modules-auth-fixes
plan: "01"
subsystem: Assets, Core
tags: [auth, input-validation, export-limit, mime-allowlist, capability-ordering]
dependency_graph:
  requires: []
  provides: [AST-AUTH-01, AST-AUTH-02, CORE-AUTH-01]
  affects: [includes/Modules/Assets/Admin/class-admin-pages.php, includes/Core/Admin.php]
tech_stack:
  added: []
  patterns: [capability-first, mime-allowlist, row-limit]
key_files:
  created: []
  modified:
    - includes/Modules/Assets/Admin/class-admin-pages.php
    - includes/Core/Admin.php
decisions:
  - LIMIT 5000 chosen as safe upper bound for asset export -- prevents memory exhaustion with no practical organizational impact
  - sfs_hr.view included in sync handler gate because dept managers receive this capability dynamically via user_has_cap filter
  - Kept check_admin_referer after POST read -- nonce depends on dept_id value, capability gate provides the auth barrier before any POST data is touched
metrics:
  duration_minutes: 10
  completed_date: "2026-03-17"
  tasks_completed: 2
  tasks_total: 2
  files_modified: 2
---

# Phase 24 Plan 01: Assets Export Limit, MIME Allowlist, and Core Sync Auth Fix Summary

**One-liner:** Hardened asset export with LIMIT 5000, invoice upload with MIME allowlist blocking .php/.html/.js, and dept sync with capability-first POST guard.

## What Was Built

Three targeted security fixes in two files:

1. **Asset export row limit** (`handle_assets_export`): Added `LIMIT 5000` to the unbounded `SELECT * FROM sfs_hr_assets` query, preventing memory exhaustion on repeated export calls.

2. **Invoice upload MIME allowlist** (`handle_asset_save`): Added `mimes` parameter to `wp_handle_upload()` restricting uploads to jpeg, png, gif, pdf, doc/docx, xls/xlsx. Blocks .php, .html, .js, .svg and any other non-document type.

3. **Department sync capability ordering** (`handle_sync_dept_members`): Moved the `sfs_hr.manage || sfs_hr.view` gate to the first line of the method, before any `$_POST` data is read. Unprivileged callers now receive a denial without the request being processed at all.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Add row limit to asset export and MIME allowlist to invoice upload | eaf719f | includes/Modules/Assets/Admin/class-admin-pages.php |
| 2 | Fix handle_sync_dept_members capability ordering | 8d582a8 | includes/Core/Admin.php |

## Decisions Made

- **LIMIT 5000**: No practical HR organization needs more than 5000 asset records in a single export. The limit is applied server-side in the SQL query itself, not in PHP array slicing, so memory is protected before allocation.
- **sfs_hr.view in sync gate**: Department managers are dynamically granted `sfs_hr.view` (not `sfs_hr.manage`) via the `user_has_cap` filter. Including `sfs_hr.view` allows them to pass the first gate; the secondary `current_user_is_dept_manager()` check then restricts them to their own department.
- **check_admin_referer position unchanged**: The nonce token includes `dept_id` so it must be read after POST is parsed. The capability gate before POST provides the auth barrier; nonce provides CSRF protection — both are retained in correct order.

## Deviations from Plan

None - plan executed exactly as written.

## Self-Check

- [x] `LIMIT 5000` present in `handle_assets_export()` at line 1520
- [x] `mimes` key present in `wp_handle_upload()` call at line 1333
- [x] `current_user_can` at line 5033, first `$_POST` read at line 5037 in `handle_sync_dept_members`
- [x] Task 1 committed: eaf719f
- [x] Task 2 committed: 8d582a8
