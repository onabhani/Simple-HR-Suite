---
phase: 11-assets-audit
plan: "01"
subsystem: Assets
tags: [audit, security, performance, duplication, logical]
dependency_graph:
  requires: []
  provides:
    - Assets module core logic audit findings (AssetsModule.php, class-admin-pages.php, class-assets-rest.php)
  affects:
    - MED-01 requirement (Assets audit)
tech_stack:
  added: []
  patterns:
    - wpdb call-accounting table pattern (established in Phase 04, continued)
    - Severity rating: Critical / High / Medium / Low
key_files:
  created:
    - .planning/phases/11-assets-audit/11-01-assets-core-findings.md
  modified: []
decisions:
  - "Assets module bootstrap is clean (prepared SHOW TABLES, no bare ALTER TABLE) — unlike Loans Phase 08 and Core Phase 04"
  - "REST file registers no routes (deliberate stub) — not a __return_true vulnerability"
  - "Dual asset status tracking (assets.status + assignments.status) is the primary architectural risk — no transaction wrapping paired updates"
  - "handle_assign_decision and handle_return_decision use is_user_logged_in() not capability check — consistent High pattern across modules"
  - "information_schema query in log_asset_event() on every event call — same Critical antipattern as Phase 04"
  - "sfs_hr_asset_logs table never provisioned by maybe_upgrade_db() — audit logging silently no-ops"
  - "Invoice file upload passes no MIME allowlist to wp_handle_upload() — Critical file upload risk"
  - "save_data_url_attachment() writes binary to disk before type validation — allows SVG with embedded JS"
metrics:
  duration: "~4 minutes"
  completed_date: "2026-03-16"
  tasks_completed: 2
  tasks_total: 2
  files_created: 1
  files_modified: 0
---

# Phase 11 Plan 01: Assets Core Logic Audit Summary

**One-liner:** Assets core audit — 22 findings (3 Critical, 9 High) including MIME-less invoice upload, information_schema audit log check, dual status tracking without transactions, and two handlers with login-only (not capability) guards.

## What Was Done

Audited the three core Assets module files:
- `includes/Modules/Assets/AssetsModule.php` (374 lines) — module bootstrap, DB provisioning, employee tab integration
- `includes/Modules/Assets/Rest/class-assets-rest.php` (23 lines) — intentional stub, no routes registered
- `includes/Modules/Assets/Admin/class-admin-pages.php` (2,101 lines) — all CRUD, assignment/return lifecycle, CSV export/import, QR generation, categories, audit log

All 55 `$wpdb` calls across the three files were catalogued in the call-accounting table.

## Findings Summary

| Category | Critical | High | Medium | Low | Total |
|----------|----------|------|--------|-----|-------|
| Security | 3 | 3 | 1 | 1 | 8 |
| Performance | 0 | 2 | 2 | 1 | 5 |
| Duplication | 0 | 1 | 2 | 0 | 3 |
| Logical | 0 | 3 | 2 | 1 | 6 |
| **Total** | **3** | **9** | **7** | **3** | **22** |

## Key Findings

**Critical:**
- ASSET-SEC-001: Export fetches `SELECT *` with no LIMIT — memory exhaustion risk; exports all financial fields
- ASSET-SEC-002: Invoice file upload passes no MIME allowlist; `save_data_url_attachment()` writes binary before type check; SVG/HTML injectable
- ASSET-SEC-003: `log_asset_event()` uses `information_schema.tables` query on every call — same antipattern as Phase 04 Critical and Phase 08 Critical; two raw `SHOW COLUMNS FROM` calls in handlers also flagged

**High (selected):**
- ASSET-SEC-004/005: `handle_assign_decision()` and `handle_return_decision()` gate on `is_user_logged_in()` only — any authenticated user can POST; ownership check is correct but capability gate is missing
- ASSET-SEC-006: Import `$wpdb->update()` has no explicit format array; import allows overwriting `status` column outside lifecycle
- ASSET-DUP-001: Dual status tracking (assets.status + assignments.status) updated in separate unatomic operations — divergence possible if either update fails
- ASSET-LOGIC-001: TOCTOU race in double-assignment guard — same class as Phase 06 `has_overlap()` finding
- ASSET-LOGIC-002: `sfs_hr_asset_logs` table never provisioned — audit logging silently no-ops on all installations

**Positive:**
- Bootstrap is clean (prepared SHOW TABLES, no bare ALTER TABLE) — contrast with Loans Phase 08
- REST stub correctly registers no routes rather than empty `__return_true` endpoints
- Assignment ownership verification is correctly implemented in both decision handlers
- All write handlers have nonce protection (`check_admin_referer`)
- Delete handler correctly blocks deletion of assets with active assignments

## Deviations from Plan

None — plan executed exactly as written. Both tasks completed within a single pass.

## Artifacts

- `.planning/phases/11-assets-audit/11-01-assets-core-findings.md` — Full findings report with 22 findings, wpdb call-accounting table (55 calls), module bootstrap assessment

## Self-Check: PASSED

- Findings file exists: FOUND
- Contains Security, Performance, Duplication, Logical sections: FOUND (31 `##` headings)
- Contains ASSET- IDs: FOUND (37 references)
- Commit b55de52 exists: FOUND
