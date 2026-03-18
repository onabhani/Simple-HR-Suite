---
phase: 20-attendance-endpoint-authentication
plan: "01"
subsystem: Attendance REST API / Kiosk
tags: [auth, rest-api, kiosk, hmac, nonce, security]
dependency_graph:
  requires: []
  provides: [ATT-AUTH-01, ATT-AUTH-02, ATT-AUTH-03, ATT-AUTH-04]
  affects: [attendance-kiosk-offline, attendance-rest-endpoints]
tech_stack:
  added: []
  patterns:
    - is_user_logged_in as REST permission_callback
    - check_admin_referer before current_user_can pattern
    - HMAC-SHA-256 with per-roster rotating nonce for offline token validation
    - Web Crypto API HMAC via crypto.subtle.importKey/sign
    - IndexedDB roster_meta reserved record pattern (__roster_meta__)
key_files:
  modified:
    - includes/Modules/Attendance/Rest/class-attendance-rest.php
    - includes/Modules/Attendance/Admin/class-admin-pages.php
    - includes/Modules/Attendance/Frontend/Kiosk_Shortcode.php
    - assets/pwa/pwa-app.js
decisions:
  - "Used is_user_logged_in (not a custom capability) for /status and /verify-pin — consistent with the fact that any authenticated operator is sufficient; additional capability checks are inside the handlers"
  - "Stored roster_nonce in employees IndexedDB store as a reserved __roster_meta__ record — avoids a DB version bump while keeping nonce retrievable"
  - "Left the token_hash IndexedDB index from v2 schema intact — unused but harmless, removing it requires a version bump with no functional benefit"
metrics:
  duration: ~15 minutes
  completed: "2026-03-17"
  tasks_completed: 2
  tasks_total: 2
  files_modified: 4
---

# Phase 20 Plan 01: Attendance Endpoint Authentication Summary

**One-liner:** Gated /status and /verify-pin REST endpoints behind is_user_logged_in, fixed nonce-before-capability ordering on rebuild handler, and replaced plain SHA-256 token hashes in kiosk roster with HMAC-SHA-256 bound to a per-roster rotating nonce.

## Tasks Completed

| # | Name | Commit | Key Files |
|---|------|--------|-----------|
| 1 | Gate /status and /verify-pin behind authentication, fix nonce ordering | b52a61d | class-attendance-rest.php, class-admin-pages.php |
| 2 | Replace SHA-256 token hash with HMAC-SHA-256 in kiosk roster and update client-side validation | ebcd2b7 | class-attendance-rest.php, Kiosk_Shortcode.php, pwa-app.js |

## What Was Built

### ATT-AUTH-01: /attendance/status authentication gate
Changed `permission_callback` from `'__return_true'` to `'is_user_logged_in'` on the GET /sfs-hr/v1/attendance/status route. Device geofence metadata and employee snapshots are no longer served to unauthenticated callers. The kiosk operator (always a logged-in WP user) is unaffected.

### ATT-AUTH-02: /attendance/verify-pin authentication gate
Changed `permission_callback` from `'__return_true'` (with comment "Public endpoint, PIN itself provides auth") to `'is_user_logged_in'` on the POST /sfs-hr/v1/attendance/verify-pin route. Removed misleading comment; replaced with "Kiosk operator must be authenticated." Rate limiting inside the handler is unchanged and still active.

### ATT-AUTH-03: Nonce-before-capability ordering on rebuild handler
In `handle_rebuild_sessions_day()`, swapped the ordering so `check_admin_referer('sfs_hr_att_rebuild_sessions_day')` runs first, before `current_user_can('sfs_hr_attendance_admin')`. CSRF attempts are now rejected at the nonce validation step even before capability is evaluated.

### ATT-AUTH-04: HMAC-SHA-256 kiosk roster token validation
Server-side (class-attendance-rest.php — kiosk_roster()):
- Generates a cryptographically random 32-character nonce via `wp_generate_password(32, false)` at the start of each roster response
- Replaced `'token_hash' => hash('sha256', $qr_token)` with `'token_hmac' => hash_hmac('sha256', $qr_token, $roster_nonce)`
- Added `roster_nonce` field to the REST response payload alongside employees, generated_at, and ttl

Client-side (pwa-app.js):
- Added `window.sfsHrPwa.hmacSha256(message, key)` helper using Web Crypto API (crypto.subtle.importKey + sign with HMAC/SHA-256)
- Updated `replaceRoster(employees, generatedAt, ttl, rosterNonce)` to accept and store `rosterNonce` as a reserved `__roster_meta__` record in the employees IndexedDB store (avoids version bump)
- Added `getRosterNonce()` method that retrieves the stored nonce from IndexedDB
- Updated `refreshRoster()` to pass `data.roster_nonce` to `replaceRoster`

Client-side (Kiosk_Shortcode.php — inline JS):
- Changed offline fallback guard from `window.sfsHrPwa.sha256` to `window.sfsHrPwa.hmacSha256`
- Changed field check from `offlineEmpRecord.token_hash` to `offlineEmpRecord.token_hmac`
- Replaced `sha256(token)` comparison with `hmacSha256(token, rosterNonce)` after retrieving nonce from `getRosterNonce()`
- Added guard for missing nonce (stale cache scenario): surfaces same "Cache may be stale" error

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check: PASSED

Files confirmed modified:
- `includes/Modules/Attendance/Rest/class-attendance-rest.php` — contains `is_user_logged_in` at lines 81/120, `hash_hmac` at line 326, `roster_nonce` at line 335
- `includes/Modules/Attendance/Admin/class-admin-pages.php` — `check_admin_referer` at line 6047 precedes `current_user_can` at line 6048
- `includes/Modules/Attendance/Frontend/Kiosk_Shortcode.php` — `hmacSha256` and `getRosterNonce` present
- `assets/pwa/pwa-app.js` — `hmacSha256` helper, `getRosterNonce`, updated `replaceRoster` with nonce parameter

Commits confirmed:
- b52a61d: Task 1
- ebcd2b7: Task 2
