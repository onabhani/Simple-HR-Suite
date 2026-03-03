---
title: "Punch endpoint performance analysis — estimated time for 50 employees"
labels: performance, attendance, analysis
---

## Punch Endpoint Performance Analysis

### Overview

This document analyzes the `POST /sfs-hr/v1/attendance/punch` endpoint to estimate
per-punch server processing time and the impact on 50 concurrent employees.

---

### Current Flow — Database Queries per Single Punch

Each punch request executes the following sequential operations:

| # | Operation | DB Queries | Estimated Time |
|---|-----------|-----------|----------------|
| 1 | **WP REST auth + nonce + permission** (`can_punch`) | 1–2 (user capabilities) | ~5–10 ms |
| 2 | **Offline device validation** (if kiosk offline) | 1–2 (device lookup + employee check) | ~5 ms |
| 3 | **Scan token peek** (kiosk path) | 1 (`get_option`) | ~3 ms |
| 4 | **Resolve employee ID** (`employee_id_from_user`) | 1 (`SELECT FROM sfs_hr_employees`) | ~2 ms |
| 5 | **Snapshot for today** (`snapshot_for_today`) | 2–4 (last global punch + day's punches + overnight shift resolve) | ~10–20 ms |
| 6 | **Cooldown check** (last punch query) | 1 (`SELECT … ORDER BY punch_time DESC LIMIT 1`) | ~3 ms |
| 7 | **Resolve shift for date** (`resolve_shift_for_date`) | 3–7 (assignment → emp_shift → project → dept automation → fallback) | ~15–30 ms |
| 8 | **Policy method validation** (`Policy_Service::validate_method`) | 1–3 (employee → user → roles → policy lookup) | ~10–15 ms |
| 9 | **Leave check** (`is_on_approved_leave`) | 1–2 (holidays + leave requests) | ~5 ms |
| 10 | **Department web-punch block** | 1–2 (`get_option` + employee dept lookup) | ~5 ms |
| 11 | **Geofence validation** (assignment + device) | 0–2 (haversine calc is in-memory; device lookup if device_id set) | ~3–5 ms |
| 12 | **Selfie mode resolution** (`selfie_mode_for`) | 1–2 (`get_option` + device lookup) | ~5 ms |
| 13 | **Selfie upload** (if required — `wp_handle_upload` + `wp_insert_attachment`) | 2–4 (file I/O + attachment insert + metadata) | ~50–200 ms |
| 14 | **Acquire DB lock** (`GET_LOCK`) | 1 | ~2 ms |
| 15 | **Duplicate check** (within lock) | 1 (`SELECT … WHERE punch_time ± 30s`) | ~3 ms |
| 16 | **Insert punch** | 1 (`INSERT INTO sfs_hr_attendance_punches`) | ~3 ms |
| 17 | **Selfie post_meta** (if selfie) | 2–3 (`update_post_meta` × 2–3) | ~5 ms |
| 18 | **Audit log insert** | 1 (`INSERT INTO sfs_hr_attendance_audit`) | ~3 ms |
| 19 | **Recalc session** (`recalc_session_for`) | 5–10 (leave check + shift resolve + punches query + session upsert + segment evaluation) | ~30–60 ms |
| 20 | **Post-punch snapshot** (`snapshot_for_today` again) | 2–4 (same as step 5) | ~10–20 ms |
| 21 | **Post-punch shift resolve** (for next selfie mode) | 3–7 (same cascade as step 7) | ~15–30 ms |
| 22 | **Post-punch selfie mode** | 1–2 | ~5 ms |
| 23 | **Release DB lock** (`RELEASE_LOCK`) | 1 | ~1 ms |

**Total DB queries per punch: ~30–55 queries**

---

### Estimated Time per Punch (Current)

| Scenario | Estimated Server Time |
|----------|----------------------|
| **Simple punch (no selfie, no kiosk)** | **~150–250 ms** |
| **Punch with selfie upload** | **~250–500 ms** |
| **Punch with selfie + kiosk scan** | **~280–550 ms** |
| **Worst case (overnight shift, selfie, complex policy)** | **~400–700 ms** |

> Note: These are server-side processing times only. Add network latency (RTT)
> for total end-to-end time. On a typical server with SSD and MySQL 8, most
> individual queries take 1–5 ms.

---

### 50 Employees Punching — Current Estimate

**Scenario:** 50 employees punch in during a 5-minute morning rush (realistic
peak: shift start ± 5 min grace period).

| Metric | Value |
|--------|-------|
| Punches in window | 50 |
| Window duration | 300 seconds |
| Avg arrival rate | ~1 punch every 6 seconds |
| Avg server time per punch | ~200 ms (no selfie) |
| **DB queries total** | **~1,500–2,750** |
| MySQL `GET_LOCK` contention | Minimal — locks are per-employee, no cross-employee blocking |
| **Concurrent request bottleneck** | PHP-FPM worker pool (typically 5–20 workers) |
| Effective throughput (10 workers) | ~50 punches/sec max |
| **Will 50 punches in 5 min work?** | **Yes — well within capacity** |

**With selfie uploads (worst case):**

| Metric | Value |
|--------|-------|
| Avg server time per punch | ~400 ms (with selfie) |
| Effective throughput (10 workers) | ~25 punches/sec |
| **Will 50 punches in 5 min work?** | **Yes — still comfortable** |

---

### Identified Optimization Opportunities

These changes would reduce per-punch time. Estimated "after" times below.

#### 1. Eliminate duplicate `snapshot_for_today` call (~10–20 ms saved)
The punch handler calls `snapshot_for_today()` **twice**: once before the punch
(for transition validation) and once after (for the response). The post-punch
snapshot re-queries the same punches table. Instead, we can build the post-punch
state from the known pre-punch state + the new punch type.

#### 2. Eliminate duplicate `resolve_shift_for_date` call (~15–30 ms saved)
After inserting the punch, the handler calls `resolve_shift_for_date()` **again**
for the selfie mode computation. The shift for today doesn't change between
pre-punch and post-punch — reuse the already-resolved `$assign` variable.

#### 3. Eliminate duplicate `selfie_mode_for` call (~5 ms saved)
Called twice: once for validation, once for the response. The mode doesn't
change within the same request.

#### 4. Cache `Policy_Service::resolve_effective_policy` per request (~10 ms saved)
Already has `$cache` but only for the role-based lookup. The full `resolve_effective_policy`
with shift merging is computed fresh each time. A per-request cache keyed on
`employee_id + shift_id` would eliminate redundant work.

#### 5. Batch `update_post_meta` calls for selfie (~3–5 ms saved)
Three separate `update_post_meta` calls can be replaced with a single
`$wpdb->insert` for `_sfs_att_punch_id`, `_sfs_att_employee_id`, and
`_sfs_att_source`.

#### 6. Skip `wp_generate_attachment_metadata` — already done
The code already skips thumbnail generation (saves ~2–5 seconds per selfie).

---

### Estimated Time After Optimizations

| Scenario | Before | After | Savings |
|----------|--------|-------|---------|
| **Simple punch (no selfie)** | ~200 ms | **~130–160 ms** | ~30–40% faster |
| **Punch with selfie** | ~400 ms | **~330–370 ms** | ~10–15% faster |
| **DB queries per punch** | ~30–55 | **~18–30** | ~40–45% fewer queries |

#### 50 Employees After Optimizations

| Metric | Before | After |
|--------|--------|-------|
| Avg server time (no selfie) | ~200 ms | ~145 ms |
| Total server time (50 punches, sequential) | ~10 sec | ~7.25 sec |
| DB queries (50 punches) | ~1,500–2,750 | ~900–1,500 |
| **Peak throughput (10 workers)** | ~50/sec | **~69/sec** |
| **Can handle 50 in 5 min?** | Yes | Yes (with more headroom) |

---

### Conclusion

The current punch endpoint handles 50 employees comfortably even without
optimizations. The main bottleneck is not throughput but rather individual
latency — each punch takes ~200 ms server-side. The optimizations listed above
would reduce this by ~30–40% primarily by eliminating redundant DB queries
(duplicate snapshot, shift resolve, and selfie mode calls).

**For 50 employees, both before and after optimizations, the system performs
well within acceptable limits.** Optimizations become more important at
200+ concurrent employees or on slow database servers (shared hosting).

---

## References

- `includes/Modules/Attendance/Rest/class-attendance-rest.php` — punch handler
- `includes/Modules/Attendance/AttendanceModule.php` — session recalc, shift resolve
- `includes/Modules/Attendance/Services/Policy_Service.php` — policy validation
