---
gsd_state_version: 1.0
milestone: v1.2
milestone_name: Auth & Access Control Fixes
status: in_progress
stopped_at: Completed 24-02-PLAN.md
last_updated: "2026-03-17T15:31:26.910Z"
last_activity: "2026-03-17 — Completed 20-01: attendance endpoint auth gates + HMAC kiosk roster"
progress:
  total_phases: 5
  completed_phases: 5
  total_plans: 8
  completed_plans: 8
---

---
gsd_state_version: 1.0
milestone: v1.2
milestone_name: Auth & Access Control Fixes
status: in_progress
stopped_at: "Completed 20-01-PLAN.md"
last_updated: "2026-03-17"
last_activity: 2026-03-17 — Completed Phase 20 Plan 01 (attendance endpoint authentication)
progress:
  total_phases: 5
  completed_phases: 0
  total_plans: 8
  completed_plans: 1
  percent: 13
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-17)

**Core value:** Reliable, secure HR operations for Saudi organizations
**Current focus:** v1.2 Phase 20 — Attendance Endpoint Authentication

## Current Position

Phase: 20 of 24 (Attendance Endpoint Authentication)
Plan: 01 of 1 complete (20-01 done)
Status: Phase 20 complete — ready for next phase
Last activity: 2026-03-17 — Completed 20-01: attendance endpoint auth gates + HMAC kiosk roster

Progress: [█░░░░░░░░░] 13% (1/8 plans complete)

## Accumulated Context

### Decisions

- v1.2 scope narrowed to auth/access-control fixes only (32 requirements) — SQL, data integrity, and performance deferred to v1.3+
- Settlement EOS formula fix deferred — requires Saudi Article 84 legal confirmation before touching
- Phases grouped by module similarity and fix pattern, not one-phase-per-module
- Phase 20 is the highest-priority starting point — unauthenticated REST endpoints are the most exposed surface
- Used is_user_logged_in (not a custom capability) for /status and /verify-pin — any authenticated operator is sufficient; capability checks are inside the handlers
- Stored roster_nonce in employees IndexedDB store as a reserved __roster_meta__ record — avoids a DB version bump while keeping nonce retrievable
- Left the token_hash IndexedDB index from v2 schema intact — unused but harmless; removing it requires a version bump with no functional benefit
- [Phase 21]: Capability check placed before nonce check in leave handlers (handle_approve, handle_cancel) — fails fast for unauthorized users without revealing nonce validity
- [Phase 21]: Bare sfs_hr_manager role removed from is_hr_user() — department managers must be explicitly assigned as HR approvers or have sfs_hr.leave.manage capability for HR-level access
- [Phase 21]: Approve nonces scoped per-request as sfs_hr_leave_approve_{id} — prevents replay of a captured nonce against a different leave request
- [Phase 21]: Capability check before nonce in all 6 hiring handlers — fails fast without revealing nonce validity
- [Phase 21]: Hiring role allowlist: sfs_hr_employee/manager/trainee/subscriber — blocks administrator and editor escalation during conversion
- [Phase 21]: send_welcome_email() uses get_password_reset_key() — no plaintext password in any hiring email pathway
- [Phase 22-01]: Removed top-level loan_id read and capability switch from handle_loan_actions; each case now reads loan_id, verifies nonce, checks capability independently
- [Phase 22-01]: Installment nonces moved from DOM data-nonce attributes to inline sfsInstNonces JS object map; server-side check_admin_referer unchanged
- [Phase 22]: ajax_update_goal_progress uses sfs_hr.manage only (write op = same as ajax_save_goal)
- [Phase 22]: check_read_permission accepts WP_REST_Request nullable param; tiered model: manage/view=full, sfs_hr.view=dept-scoped
- [Phase 23]: OverviewTab silently returns on ownership failure (landing tab UX); ProfileTab shows error message (PII content)
- [Phase 23]: TeamTab level threshold 40: HR/GM/Admin see org-wide employees; manager (30) stays department-scoped
- [Phase 24]: LIMIT 5000 chosen as safe upper bound for asset export to prevent memory exhaustion
- [Phase 24]: sfs_hr.view included in sync dept gate because dept managers receive this cap dynamically via user_has_cap filter
- [Phase 24]: handle_sync_dept_members: capability gate moved before POST read; check_admin_referer kept after dept_id parse as nonce depends on dept_id value
- [Phase 24-small-modules-auth-fixes]: wp_validate_redirect with wp_unslash applied to resignation redirect; my-payslips uses sfs_hr.view cap; employee profile uses dotted sfs_hr.view cap format in both menu and render

### Pending Todos

None.

### Blockers/Concerns

- Settlement EOS formula (DATA-01) deferred to v1.3 — legal review needed before changing financial calculations

## Session Continuity

Last session: 2026-03-17T15:31:26.908Z
Stopped at: Completed 24-02-PLAN.md
Resume file: None
