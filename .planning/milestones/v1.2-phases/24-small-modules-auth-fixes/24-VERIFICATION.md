---
phase: 24-small-modules-auth-fixes
verified: 2026-03-17T00:00:00Z
status: passed
score: 8/8 must-haves verified
re_verification: false
---

# Phase 24: Small Modules Auth Fixes — Verification Report

**Phase Goal:** Fix authorization and input-validation gaps in Assets, Core, Resignation, Settlement, Payroll, and Employees modules
**Verified:** 2026-03-17
**Status:** passed
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Asset export query is limited to a maximum of 5000 rows | VERIFIED | `LIMIT 5000` at line 1520 of class-admin-pages.php |
| 2 | Asset invoice upload rejects files with non-allowlisted MIME types | VERIFIED | `'mimes' => $allowed_mimes` at line 1333; allowlist covers jpeg/png/gif/pdf/doc/docx/xls/xlsx |
| 3 | handle_sync_dept_members checks capability before reading any POST data | VERIFIED | `current_user_can('sfs_hr.manage') && current_user_can('sfs_hr.view')` at line 5033, first `$_POST` read at line 5037 |
| 4 | Resignation list is department-scoped for managers via sfs_hr.view check | VERIFIED | `sfs_hr.view` gate at line 26; empty dept list returns early at line 31-34 |
| 5 | Resignation redirect URL rejects external hosts | VERIFIED | `wp_validate_redirect( wp_unslash( $_POST['_wp_http_referer'] ), $default_url )` at lines 78-79 |
| 6 | Settlement handle_update verifies the settlement record belongs to the employee before writing | VERIFIED | `empty($settlement['employee_id']) || (int)$settlement['employee_id'] <= 0` at lines 82-84 |
| 7 | Payroll my-payslips endpoint uses sfs_hr.view capability, not is_user_logged_in fallback | VERIFIED | Line 51: `current_user_can( 'sfs_hr_payslip_view' ) \|\| current_user_can( 'sfs_hr.view' )`; `is_user_logged_in` absent from file |
| 8 | Employee profile page menu uses dotted sfs_hr.view capability | VERIFIED | `add_submenu_page` line 30: `'sfs_hr.view'`; `render_page` line 99: `Helpers::require_cap( 'sfs_hr.view' )`; old `sfs_hr_attendance_view_team` absent |

**Score:** 8/8 truths verified

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `includes/Modules/Assets/Admin/class-admin-pages.php` | Row-limited export and MIME-allowlisted invoice upload | VERIFIED | LIMIT 5000 at line 1520; mimes allowlist at line 1333 |
| `includes/Core/Admin.php` | Capability-first sync handler | VERIFIED | Cap check at line 5033 precedes POST read at line 5037 |
| `includes/Modules/Resignation/Handlers/class-resignation-handlers.php` | Validated redirect URL in handle_submit | VERIFIED | wp_validate_redirect + wp_unslash at lines 78-79 |
| `includes/Modules/Resignation/Admin/Views/class-resignation-list.php` | Department-scoped list with sfs_hr.view check | VERIFIED | sfs_hr.view gate at line 26; empty dept early return at lines 31-34 |
| `includes/Modules/Settlement/Handlers/class-settlement-handlers.php` | Ownership verification in handle_update | VERIFIED | employee_id validity check at lines 82-84 |
| `includes/Modules/Payroll/Rest/Payroll_Rest.php` | Proper capability check on my-payslips | VERIFIED | sfs_hr.view at line 51; is_user_logged_in absent |
| `includes/Modules/Employees/Admin/class-employee-profile-page.php` | Dotted capability format on menu and render | VERIFIED | sfs_hr.view at lines 30 and 99; sfs_hr_attendance_view_team absent |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `handle_assets_export` | SQL query | LIMIT clause | WIRED | `LIMIT 5000` in SELECT at line 1520 |
| `handle_asset_save` | wp_handle_upload | mimes parameter | WIRED | `'mimes' => $allowed_mimes` with 8-type allowlist at line 1333 |
| `handle_sync_dept_members` | capability check | check before POST read | WIRED | current_user_can at line 5033; $_POST at line 5037 |
| `handle_submit` | redirect | wp_validate_redirect | WIRED | wp_validate_redirect wraps _wp_http_referer at line 79 |
| `Resignation_List::render` | department scope | sfs_hr.view + get_manager_dept_ids | WIRED | sfs_hr.view gate at line 26; get_manager_dept_ids at line 30 |
| `my-payslips permission_callback` | capability | current_user_can | WIRED | current_user_can('sfs_hr.view') at line 51 |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| AST-AUTH-01 | 24-01 | Asset export must enforce capability check and row limits | SATISFIED | Row limit LIMIT 5000 at line 1520; existing cap check confirmed present |
| AST-AUTH-02 | 24-01 | Invoice upload must enforce MIME type allowlist | SATISFIED | mimes allowlist with 8 types at line 1333 |
| CORE-AUTH-01 | 24-01 | handle_sync_dept_members must check capability before reading POST data | SATISFIED | Cap check line 5033 precedes POST read line 5037 |
| RES-AUTH-01 | 24-02 | Resignation_List::render must verify sfs_hr.view scoped to department | SATISFIED | sfs_hr.view check at line 26; dept scope at line 30 |
| RES-AUTH-02 | 24-02 | Redirect URL must be validated against allowed hosts | SATISFIED | wp_validate_redirect at line 79 |
| RES-AUTH-03 | 24-02 | Department manager scope must be enforced on resignation views | SATISFIED | Empty dept list causes early return at lines 31-34 |
| SETT-AUTH-01 | 24-02 | handle_update must verify settlement belongs to current user | SATISFIED | employee_id positive-integer check at lines 82-84 |
| PAY-AUTH-01 | 24-02 | /payroll/my-payslips must use proper capability check, not is_user_logged_in fallback | SATISFIED | is_user_logged_in absent; sfs_hr.view at line 51 |
| EMP-AUTH-01 | 24-02 | Menu capability must use dotted sfs_hr.* format, not role name | SATISFIED | sfs_hr.view at lines 30 and 99; old cap absent |

All 9 requirements satisfied. No orphaned requirements detected.

---

### Commits Verified

| Commit | Description | Status |
|--------|-------------|--------|
| eaf719f | fix(24-01): add row limit to asset export and MIME allowlist to invoice upload | EXISTS |
| 8d582a8 | fix(24-01): fix handle_sync_dept_members to check capability before reading POST data | EXISTS |
| 56fae0e | fix(24-02): harden Resignation redirect and list scoping | EXISTS |
| a99e2d6 | fix(24-02): Settlement ownership check, Payroll cap, Employee profile menu cap | EXISTS |

---

### Anti-Patterns Found

None detected in modified files. Targeted, minimal changes only — no unrelated file modifications, no placeholder returns, no TODO/FIXME comments introduced.

---

### Human Verification Required

None — all changes are logic-level PHP; automated grep confirms presence and absence of all required patterns.

---

## Summary

Phase 24 achieved its goal. All 9 authorization and input-validation gaps across 7 files in 6 modules (Assets, Core, Resignation, Settlement, Payroll, Employees) are closed:

- Unbounded asset export is now row-limited at 5000 via SQL `LIMIT`.
- Invoice upload now rejects non-document/non-image MIME types via the WordPress `mimes` allowlist parameter.
- The department sync handler now gates on capability before touching any request data.
- Resignation redirect is validated against allowed hosts via `wp_validate_redirect`.
- Resignation list now requires `sfs_hr.view` for non-manage users and correctly returns empty state for managers with no assigned departments.
- Settlement write path validates the target record has a positive `employee_id` before proceeding.
- Payroll `my-payslips` endpoint replaced the permissive `is_user_logged_in()` fallback with `sfs_hr.view`.
- Employee profile page menu and render both use dotted `sfs_hr.view` capability format consistently.

The executor also auto-fixed the `render_page()` capability in `class-employee-profile-page.php` (not in the original plan spec), which was a correct and necessary extension — the summary documents this deviation transparently.

---

_Verified: 2026-03-17_
_Verifier: Claude (gsd-verifier)_
