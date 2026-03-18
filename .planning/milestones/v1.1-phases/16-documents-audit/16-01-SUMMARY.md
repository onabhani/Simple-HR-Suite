---
phase: 16-documents-audit
plan: "01"
subsystem: Documents
tags: [audit, security, documents, file-upload, access-control]
dependency_graph:
  requires: []
  provides: [16-01-documents-service-findings.md]
  affects: [MED-06]
tech_stack:
  added: []
  patterns: [information_schema-antipattern, bare-ALTER-TABLE, MIME-allowlist-correct, media_handle_upload]
key_files:
  created:
    - .planning/phases/16-documents-audit/16-01-documents-service-findings.md
  modified: []
decisions:
  - Documents module uses correct MIME allowlist with finfo magic-bytes detection — superior to Assets Phase 11 which had no allowlist
  - Uploaded files stored in public WP Media Library without access control — flagged as High security issue
  - information_schema antipattern confirmed for 5th time; same bare ALTER TABLE pattern as Phase 04 and Phase 08
  - archive_document() does not delete physical file — orphaned WP attachments accumulate with no bound
metrics:
  duration_minutes: 3
  completed_date: "2026-03-16"
  tasks_completed: 2
  tasks_total: 2
  files_created: 1
  files_modified: 0
---

# Phase 16 Plan 01: Documents Module Audit Summary

**One-liner:** Documents module has correct MIME allowlist and media_handle_upload pattern but stores sensitive files in public WP uploads, has information_schema/ALTER TABLE antipatterns on admin_init, and orphans physical files on delete.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Read and audit DocumentsModule orchestrator, service, and handlers | 0056d95 | — |
| 2 | Write Documents service and handlers findings report | 0056d95 | `.planning/phases/16-documents-audit/16-01-documents-service-findings.md` |

## Findings Summary

**18 total findings across 4 files (~1298 lines):**

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 2 | 3 | 2 | 0 | 7 |
| Performance | 0 | 2 | 1 | 0 | 3 |
| Duplication | 0 | 0 | 1 | 1 | 2 |
| Logical | 0 | 3 | 2 | 1 | 6 |
| **Total** | **2** | **8** | **6** | **1** | **18** |

## Key Findings

### Critical (2)

**DOC-SEC-001:** `information_schema` queries on every `admin_init` in `DocumentsModule.php:65-68,107-111`. 5th recurrence of this exact pattern (Phase 04, 08, 11, 12). Fix: version-gated check with `SHOW TABLES LIKE`.

**DOC-SEC-002:** Bare `ALTER TABLE` DDL in `maybe_add_update_request_columns()` at `DocumentsModule.php:114-118`. Uses string interpolation for table name, runs raw DDL outside canonical `add_column_if_missing()` helper. MySQL errors silently swallowed. Fix: delegate to `Migrations.php` `add_column_if_missing()`.

### High (8 selected)

**DOC-SEC-003:** REST permission callback grants access to documents for `sfs_hr_attendance_view_team` holders — attendance supervisors can read sensitive documents (passports, national IDs) for employees in other departments.

**DOC-SEC-004:** Documents stored via `media_handle_upload()` in public WP uploads — no `.htaccess` protection, no authenticated download endpoint. Direct URL grants unauthenticated access to sensitive personal documents.

**DOC-LOGIC-001:** `archive_document()` (called by both delete and replace flows) does not call `wp_delete_attachment()`. Orphaned files accumulate on disk and remain publicly accessible by URL even after HR "deletes" them.

**DOC-LOGIC-002:** No transaction around archive + create in document replacement. TOCTOU race: if `archive_document()` succeeds but `create_document()` fails, document is lost. No UNIQUE constraint on `(employee_id, document_type)` for active documents.

**DOC-LOGIC-003:** `$_FILES['document_file']['name']` stored verbatim in `file_name` column without `sanitize_file_name()`. Stored XSS vector if rendered unescaped.

**DOC-PERF-001:** `get_uploadable_document_types_for_employee()` issues 1 DB query per document type in a loop — 12+ queries per employee profile page load.

### Medium (selected)

**DOC-SEC-006:** `document_type` validated as a key but not checked against the allowed types list from `get_document_types()`. Employees can supply arbitrary type keys bypassing HR configuration.

**DOC-LOGIC-004:** `handle_delete()` does not verify that the document belongs to the provided `employee_id`. HR admin can delete any document org-wide with no department scope.

## Notable Positives

1. MIME allowlist uses `finfo_open(FILEINFO_MIME_TYPE)` (magic bytes) — correct approach, better than Assets Phase 11
2. File upload via `media_handle_upload()` — uses WP sanitization pipeline, not raw `move_uploaded_file()`
3. File size validated server-side before processing
4. All `admin_post_` handlers verify nonce with `wp_verify_nonce()` at entry
5. No `__return_true` REST routes — both REST endpoints properly authenticated
6. Self-upload scoping uses DB-derived `user_id`, not POST parameter — no IDOR on self-upload
7. Handle-delete correctly restricted to `sfs_hr.manage` only

## $wpdb Call Accounting

19 total calls across 3 files + REST file:
- 17 prepared/safe (all `get_results`, `get_row`, `get_var`, `insert`, `update` use parameterization)
- 2 antipatterns: information_schema queries at DocumentsModule:65, :107
- 1 bare DDL: ALTER TABLE at DocumentsModule:114

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check

- [x] Findings file exists at `.planning/phases/16-documents-audit/16-01-documents-service-findings.md`
- [x] All $wpdb calls catalogued (19 calls, table in findings report)
- [x] DocumentsModule.php checked for DDL antipatterns (bare ALTER TABLE found at line 114)
- [x] information_schema antipattern confirmed (lines 65-68 and 107-111)
- [x] File upload security audited: MIME validation (correct), file size (correct), path traversal (safe via media_handle_upload), direct access (High issue documented)
- [x] Document access control verified: self-upload scoping safe; REST overly broad (DOC-SEC-003)
- [x] Handler nonce and capability gates verified: all 4 handlers have nonce; capability checks present but inline in upload handler
- [x] Every finding has severity rating and fix recommendation
- [x] Cross-reference table links findings to prior phases
