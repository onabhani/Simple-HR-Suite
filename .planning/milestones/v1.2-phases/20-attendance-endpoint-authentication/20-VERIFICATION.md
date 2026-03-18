---
phase: 20-attendance-endpoint-authentication
verified: 2026-03-17T00:00:00Z
status: passed
score: 5/5 must-haves verified
---

# Phase 20: Attendance Endpoint Authentication Verification Report

**Phase Goal:** Fix attendance endpoint authentication vulnerabilities — gate REST endpoints behind login, enforce nonce-before-capability on admin handlers, replace plain SHA-256 with HMAC-SHA-256 for kiosk tokens.
**Verified:** 2026-03-17
**Status:** PASSED
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | An unauthenticated request to GET /attendance/status receives a 401 response | VERIFIED | `permission_callback` is `'is_user_logged_in'` at line 81 of class-attendance-rest.php — WordPress REST infrastructure rejects unauthenticated callers with 401 |
| 2 | An unauthenticated request to POST /attendance/verify-pin receives a 401 response | VERIFIED | `permission_callback` is `'is_user_logged_in'` at line 120 of class-attendance-rest.php — comment updated to "Kiosk operator must be authenticated" |
| 3 | handle_rebuild_sessions_day verifies admin nonce before capability check and before reading any GET data | VERIFIED | Line 6047 is `check_admin_referer('sfs_hr_att_rebuild_sessions_day')`, line 6048 is `current_user_can('sfs_hr_attendance_admin')` — nonce runs first |
| 4 | The kiosk_roster response contains no SHA-256 token hashes — uses HMAC with a per-roster nonce instead | VERIFIED | `kiosk_roster()` generates `$roster_nonce` via `wp_generate_password(32, false)`, stores `'token_hmac' => hash_hmac('sha256', $qr_token, $roster_nonce)` at line 326, includes `roster_nonce` in response at line 335. No `token_hash` field exists in the response. |
| 5 | Offline kiosk QR validation still works using HMAC comparison | VERIFIED | Kiosk_Shortcode.php line 1460 guards on `window.sfsHrPwa.hmacSha256`, retrieves `rosterNonce` from `getRosterNonce()`, computes `hmacSha256(token, rosterNonce)` and compares to `offlineEmpRecord.token_hmac` at line 1468. pwa-app.js provides `hmacSha256` helper (lines 234-243) and `getRosterNonce()` (lines 164-176). `replaceRoster` stores the nonce as `__roster_meta__` record. `refreshRoster` passes `data.roster_nonce` through at line 274. |

**Score:** 5/5 truths verified

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/Modules/Attendance/Rest/class-attendance-rest.php` | Auth-gated REST endpoints and HMAC-based kiosk roster | VERIFIED | Contains `is_user_logged_in` at lines 81/120, `hash_hmac` at line 326, `roster_nonce` at line 335. No `__return_true` permission callbacks remain for /status or /verify-pin. |
| `includes/Modules/Attendance/Admin/class-admin-pages.php` | Nonce-first ordering on rebuild handler | VERIFIED | `check_admin_referer` at line 6047 precedes `current_user_can` at line 6048 in `handle_rebuild_sessions_day()` |
| `includes/Modules/Attendance/Frontend/Kiosk_Shortcode.php` | Client-side HMAC validation for offline kiosk | VERIFIED | References `hmacSha256`, `getRosterNonce`, and `token_hmac` at lines 1460-1468. No `token_hash` or `sha256(token)` comparison remains. |
| `assets/pwa/pwa-app.js` | Updated IndexedDB schema with token_hmac field, hmacSha256 helper | VERIFIED | `hmacSha256` helper at lines 234-243, `getRosterNonce()` at lines 164-176, `replaceRoster` accepts `rosterNonce` parameter at line 136, stores `__roster_meta__` record with `roster_nonce`. Note: old v2 schema index `token_hash` at line 86 is still declared but never populated — documented as intentional to avoid a DB version bump; the index is empty and harmless. |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `class-attendance-rest.php kiosk_roster()` | `Kiosk_Shortcode.php offline validation JS` | `roster_nonce` + `token_hmac` fields | WIRED | Server includes both fields in response; client reads `token_hmac` from `offlineEmpRecord` and `rosterNonce` from `getRosterNonce()` before computing `hmacSha256` |
| `class-attendance-rest.php kiosk_roster()` | `pwa-app.js replaceRoster()` | IndexedDB employees store | WIRED | `refreshRoster()` in pwa-app.js calls `replaceRoster(data.employees, data.generated_at, data.ttl, data.roster_nonce)` passing all four fields including the nonce |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| ATT-AUTH-01 | 20-01-PLAN.md | Unauthenticated GET /attendance/status must require authentication | SATISFIED | `permission_callback` changed from `'__return_true'` to `'is_user_logged_in'` at line 81 |
| ATT-AUTH-02 | 20-01-PLAN.md | Unauthenticated POST /attendance/verify-pin must require authentication with rate limiting | SATISFIED | `permission_callback` changed from `'__return_true'` to `'is_user_logged_in'` at line 120; existing rate limiting in handler is unchanged |
| ATT-AUTH-03 | 20-01-PLAN.md | handle_rebuild_sessions_day must verify admin nonce before processing | SATISFIED | `check_admin_referer` at line 6047 precedes `current_user_can` at line 6048 and any `$_GET` reads |
| ATT-AUTH-04 | 20-01-PLAN.md | kiosk_roster endpoint must not expose SHA-256 token hashes | SATISFIED | Response now contains `token_hmac` (HMAC-SHA-256 with rotating nonce) and `roster_nonce`; no `token_hash` field in response or in stored employee records |

All four requirement IDs from the PLAN frontmatter are accounted for. REQUIREMENTS.md marks all four as `[x]` complete and maps them to Phase 20. No orphaned requirements found.

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `includes/Modules/Attendance/Admin/class-admin-pages.php` | 4949 | `// …and mirror into meta_json for backwards-compat (your TODO)` | Info | Unrelated to this phase; pre-existing comment in a different method |
| `includes/Modules/Attendance/Frontend/Kiosk_Shortcode.php` | 2109 | `// TODO: Future — headcount counter` | Info | Pre-existing comment; unrelated to authentication changes |
| `assets/pwa/pwa-app.js` | 86 | `empStore.createIndex('token_hash', 'token_hash', ...)` | Info | Old v2 IndexedDB index; never populated now that roster stores `token_hmac`. Harmless — no SHA-256 data is written to it. Removing it would require a DB version bump for no functional gain. Documented decision in SUMMARY. |

No blockers. The `token_hash` index is a leftover schema declaration that is never populated — it does not expose hash data to attackers.

---

### Human Verification Required

#### 1. Unauthenticated REST 401 — live browser test

**Test:** Log out of WordPress, then open browser DevTools and issue `fetch('/wp-json/sfs-hr/v1/attendance/status')` and `fetch('/wp-json/sfs-hr/v1/attendance/verify-pin', {method:'POST'})` from the console.
**Expected:** Both return HTTP 401 with `{"code":"rest_not_logged_in",...}`.
**Why human:** Cannot execute live HTTP requests against the WP instance programmatically in this environment.

#### 2. Offline kiosk QR punch — end-to-end flow

**Test:** Load the kiosk page as an authenticated operator. Allow roster to sync (check IndexedDB for `__roster_meta__` record). Disable network (DevTools > Network > Offline). Scan a valid employee QR code.
**Expected:** Punch is accepted offline; console shows "offline fallback: QR verified for employee [id]". A stale or tampered token should show "Invalid QR".
**Why human:** Requires a live kiosk device, QR codes, and IndexedDB inspection — not automatable via static analysis.

---

### Gaps Summary

No gaps. All five observable truths are verified against the actual codebase. All four requirements are satisfied with direct code evidence. Commits b52a61d and ebcd2b7 both exist in git history.

---

_Verified: 2026-03-17_
_Verifier: Claude (gsd-verifier)_
