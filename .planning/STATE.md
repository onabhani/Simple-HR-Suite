---
gsd_state_version: 1.0
milestone: v1.3
milestone_name: Audit Fixes (SQL, Data, Performance, Logic)
status: completed
stopped_at: Completed 30-01-PLAN.md
last_updated: "2026-03-18T05:24:31.521Z"
last_activity: 2026-03-18 — Phase 30 plan 01 complete; reject guard fall-through fixed, approve balance race fixed with DB transaction + FOR UPDATE, all 5 leave mutation handlers now invalidate transient caches
progress:
  total_phases: 6
  completed_phases: 6
  total_plans: 14
  completed_plans: 14
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-03-17)

**Core value:** Reliable, secure HR operations for Saudi organizations
**Current focus:** v1.3 gap closure — Phase 30 complete (Leave Handler Safety Hardening)

## Current Position

Phase: 30 of 30 (Leave Handler Safety Hardening) — COMPLETE
Plan: 30-01 complete (1/1 plans done — phase finished)
Status: Phase 30 complete; all 3 gap closures (LOGIC-02, LOGIC-01, PERF-03) applied
Last activity: 2026-03-18 — Phase 30 plan 01 complete; reject guard fall-through fixed, approve balance race fixed with DB transaction + FOR UPDATE, all 5 leave mutation handlers now invalidate transient caches

Progress: [██████████] 100% (v1.3+gap: 6/6 phases complete — Phases 25-30 all done)

## Accumulated Context

### Decisions

- v1.3 phase order: SQL migration fixes first (Phase 25) so Phase 26 SQL injection work runs on clean migration infrastructure
- DATA-01/06 (Settlement formula + trigger type) grouped in Phase 27 — legal review for EOS formula still advised before deployment
- DEBT-01/DEBT-02 attached to the phases whose code they touch (Leave in Phase 27, capability check in Phase 26)
- [Phase 25-01]: Used SHOW COLUMNS helper pattern for add_column_safe() in Core/Admin.php; added add_index_if_missing() to Attendance/Migration.php
- [Phase 25-02]: information_schema.STATISTICS retained in migration-only index helpers (no SHOW equivalent for index names); information_schema.TABLE_CONSTRAINTS retained in option-gated FK migration; SHOW COLUMNS FROM row uses Type/Null fields (not COLUMN_TYPE/IS_NULLABLE)
- [Phase 26-01]: manage_options retained in LoansModule check_tables_notice and install_tables_action — these are admin-only actions intentionally gated to WordPress administrators, not in scope for DEBT-02
- [Phase 26-01]: SHOW COLUMNS FROM table name interpolation retained ({loans_table} from wpdb->prefix) — safe as prefix is trusted infrastructure; only LIKE value moved to prepared %s placeholder
- [Phase 26-sql-injection-fixes]: SHOW COLUMNS FROM queries with prefix-derived table names (no user input/LIKE) left unchanged — no injection vector; only SHOW TABLES LIKE and LIKE clauses with variable values fixed
- [Phase 27-01]: Settlement EOS gratuity corrected to Saudi Article 84 (15-day for first 5 years, 30-day after); calculate_gratuity() retained for backward compat; calculate_gratuity_with_trigger() applies resignation multipliers; server-side recalculation in handle_create() prevents client tampering
- [Phase 27]: Read existing balance row before computing closing to preserve opening and carried_over in all 3 recalculation paths (handle_approve, cancellation_approve, early_return)
- [Phase 27]: Anniversary-based tenure: compute_quota_for_year uses hire_date MM-DD in target year; Mar 1 fallback for Feb 29 hire dates
- [Phase 27]: Per-request reject nonce sfs_hr_leave_reject_{id} applied to all 6 nonce points including 4 detail view inline forms across all approval stages
- [Phase 27]: installment_amount is the canonical column in sfs_hr_loans; frontend now computes round(principal/installments,2) to match admin approval path
- [Phase 28-01]: Dashboard counters 60s transient with _today date-aware cache bust for today-scoped stats
- [Phase 28-01]: Org chart N+1 eliminated via get_users(include) batch + single IN() query before foreach loops
- [Phase 28-01]: Reminders upcoming count rewritten to CASE/WHEN batch query (2 queries total) replacing 16 per-offset queries
- [Phase 28]: OverviewTab today_shift excluded from 60s transient cache — real-time attendance state must not be stale
- [Phase 28]: Leave status tab pending_* sub-states share the 'pending' DB GROUP BY count since they are PHP-derived from approval_level
- [Phase 28]: Resignation GROUP BY uses both r.status and r.resignation_type to correctly derive final_exit count
- [Phase 28]: WF Status batch shift resolution: falls back to resolve_shift_for_date() for employees with no assignment row
- [Phase 28]: Absent_Notifications: send_all_absent_notifications() combined dispatch avoids double DB query in cron
- [Phase 28]: ATT-API-PERF-001: dept_label_from_employee() never called in loop; assignments tab uses JOIN; no code change needed
- [Phase 29-01]: Leave business_days() not changed (Article 109 calendar days minus Fridays only); only Payroll count_working_days needed Saturday skip
- [Phase 29-01]: REQUEST_TIME_FLOAT used as capability cache key — constant per request, resets between requests in PHP-FPM workers
- [Phase 29]: Leave transition map extended: on_leave allows cancel_pending/cancelled; approved allows cancelled — matches real workflow where cancellation approval goes directly to cancelled DB state
- [Phase 29-03]: Named lock (GET_LOCK) chosen for generate_reference_number() instead of FOR UPDATE because callers may already be inside a transaction; nested transactions not supported in MySQL/InnoDB
- [Phase 29-03]: has_overlap_locked() added as new method alongside has_overlap() to preserve backward compatibility; both leave creation paths use the locked variant
- [Phase 30-01]: COMMIT placed after balance insert/update block; no ROLLBACK handler added — MySQL auto-rollbacks uncommitted transactions on connection close (matches Phase 29 pattern)
- [Phase 30-01]: START TRANSACTION placed before final status UPDATE so status change and balance update are atomic
- [Phase 30-01]: invalidate_leave_caches() uses direct DELETE LIKE for sfs_hr_leave_counts_* because key is md5-scoped; uses delete_transient() for fixed-key dashboard counts
- [Phase 30-01]: handle_cancel_approved() correctly skipped for cache invalidation — it only creates a cancellation record, not a leave status change

### Pending Todos

None.

### Blockers/Concerns

- DATA-01 resolved in 27-01 — Article 84 rates implemented; legal review with client still recommended before production deployment

## Session Continuity

Last session: 2026-03-18T05:20:09Z
Stopped at: Completed 30-01-PLAN.md
Resume file: None
