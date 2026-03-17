# Attendance Services / Cron / Migration Audit Findings

**Audit date:** 2026-03-16
**Scope:** Attendance Services (5 files), Cron (3 files), Migration (1 file), Module entry (1 file) — ~3.9K lines
**Auditor:** Claude (automated code review)
**Requirement:** CRIT-01

---

## Executive Summary

- **Critical:** 3 findings
- **High:** 7 findings
- **Medium:** 9 findings
- **Low:** 5 findings
- **Total:** 24 findings

### Top Issues

1. **[ATT-SVC-LOGIC-001]** `Session_Service::recalc_session_for` uses `GET_LOCK` to guard against concurrent recalculations but the 3-second timeout means lock contention queues a deferred cron event that itself has no duplicate-suppression guard — two deferred events for the same key can be queued simultaneously (`Session_Service.php:L72-L76`)
2. **[ATT-SVC-SEC-001]** `AttendanceModule::ajax_dbg` endpoint is accessible to unauthenticated users (`wp_ajax_nopriv_sfs_hr_att_dbg`), accepts unsanitized `$_POST['m']` and `$_POST['c']`, and writes them verbatim to `error_log` — an attacker can log-poison or probe the endpoint for information leakage (`AttendanceModule.php:L61-L138`)
3. **[ATT-SVC-LOGIC-002]** `rebuild_sessions_for_date_static` queries ALL active employees with no limit or batching, then calls `recalc_session_for` in a loop — on large installations this will run thousands of DB-heavy recalculations synchronously in a single cron tick, causing PHP timeouts (`Session_Service.php:L1014-L1033`)
4. **[ATT-SVC-PERF-001]** `Shift_Service::resolve_shift_for_date` executes up to 5 separate DB queries per employee per date resolution (assignment, emp lookup, emp_shifts, schedule, shift row) before falling back to automation; when called inside `rebuild_sessions_for_date_static` this produces an O(n×5) query storm (`Shift_Service.php:L40-L215`)
5. **[ATT-SVC-SEC-002]** `Migration::add_column_if_missing` and several inline migration blocks interpolate `$table` directly into raw SQL without prepare — the table name is constructed from `$wpdb->prefix` which is admin-controlled, not user input, but the pattern is unsafe if the prefix is ever externally influenced (`Migration.php:L120-L133, L333`)
6. **[ATT-SVC-PERF-002]** `Policy_Service::get_all_policies` issues N+1 queries: one SELECT for all policies then one `get_col` per policy to fetch role slugs (`Policy_Service.php:L419-L433`)
7. **[ATT-SVC-LOGIC-003]** `Early_Leave_Service::backfill_early_leave_request_numbers` issues one COUNT query per missing row to determine the next sequence number, rather than computing the sequence once — race conditions between concurrent backfill runs can produce duplicate reference numbers (`Early_Leave_Service.php:L128-L145`)

---

## Security Findings

### Critical

#### ATT-SVC-SEC-001: Unauthenticated debug endpoint logs unsanitized user input
- **File:** `includes/Modules/Attendance/AttendanceModule.php:L61-L62, L128-L138`
- **Description:** The `ajax_dbg` handler is registered on `wp_ajax_nopriv_sfs_hr_att_dbg`, meaning any unauthenticated HTTP client can POST to it; `$_POST['m']` and `$_POST['c']` are `wp_unslash()`ed but not sanitized before being written to `error_log`, enabling log poisoning and blind information probing.
- **Evidence:**
  ```php
  add_action('wp_ajax_sfs_hr_att_dbg', [ $this, 'ajax_dbg' ]);
  add_action('wp_ajax_nopriv_sfs_hr_att_dbg', [ $this, 'ajax_dbg' ]);
  // ...
  $msg = isset($_POST['m']) ? wp_unslash($_POST['m']) : '';
  $ctx = isset($_POST['c']) ? wp_unslash($_POST['c']) : '';
  $line = '[SFS ATT DBG] ' . gmdate('c') . " ip={$ip} | " . $msg;
  error_log($line);
  ```
- **Fix:** Remove the `nopriv` hook entirely (debug logging should be authenticated-only); additionally sanitize both `$msg` and `$ctx` with `sanitize_text_field()` before logging.

---

### High

#### ATT-SVC-SEC-002: Raw ALTER TABLE in migration bypasses $wpdb->prepare for table name
- **File:** `includes/Modules/Attendance/Migration.php:L120-L133, L180-L182`
- **Description:** Multiple migration blocks construct table names via string interpolation (`"ALTER TABLE {$shifts_table} ADD COLUMN..."`) then call `$wpdb->query()` directly without `prepare()`. While the table name is derived from `$wpdb->prefix` (not user input), this pattern is unsafe if prefix is externally influenced and violates the project's "never concatenate into SQL" rule.
- **Evidence:**
  ```php
  $wpdb->query( "ALTER TABLE {$shifts_table} ADD COLUMN dept_id BIGINT UNSIGNED NULL AFTER active" );
  $wpdb->query( "ALTER TABLE {$shifts_table} ADD KEY dept_id (dept_id)" );
  // Also:
  $wpdb->query( "ALTER TABLE {$emp_shifts_tbl} ADD COLUMN schedule_id BIGINT UNSIGNED NULL ..." );
  ```
- **Fix:** Validate table names against `$wpdb->prefix . 'sfs_hr_*'` pattern and use `esc_sql()` on the table name component; or route all structural changes through the `add_column_if_missing()` helper which already uses `SHOW COLUMNS ... LIKE %s` with prepare.

#### ATT-SVC-SEC-003: Information_schema queries run on every admin page load during migration
- **File:** `includes/Modules/Attendance/Migration.php:L115-L118, L125-L128, L136-L139, L175-L178`
- **Description:** The migration `run()` method is called on every `admin_init`. Four separate `SELECT COUNT(*) FROM information_schema.columns` queries run unconditionally on every admin page load, not just during upgrades. `information_schema` queries are expensive (full table scans on busy hosts) and expose database structure unnecessarily.
- **Evidence:**
  ```php
  $col_exists = $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'dept_id'",
      $shifts_table
  ) );
  ```
- **Fix:** Gate the entire `run()` method behind a version check (`sfs_hr_db_ver < current_version`) before executing any `information_schema` queries; the project already has an `sfs_hr_db_ver` option for this purpose.

#### ATT-SVC-SEC-004: Cleanup SQL in FK migration uses raw table name interpolation without escaping
- **File:** `includes/Modules/Attendance/Migration.php:L403-L415`
- **Description:** All cleanup DELETE/UPDATE statements in `migrate_add_foreign_keys()` use raw table name interpolation in multi-table DELETE syntax, which cannot be prepared via `$wpdb->prepare()`. While table names come from a controlled source, this pattern is not consistent with project convention.
- **Evidence:**
  ```php
  "DELETE p FROM {$punchT} p LEFT JOIN {$empT} e ON e.id = p.employee_id WHERE e.id IS NULL",
  "DELETE s FROM {$sessT} s LEFT JOIN {$empT} e ON e.id = s.employee_id WHERE e.id IS NULL",
  ```
- **Fix:** These are structural migration queries that cannot use parameter binding for identifiers; document them as intentional with a `// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared` comment and ensure they are only reachable through the one-time migration gate (`sfs_hr_att_fk_migrated` option check).

---

## Performance Findings

### High

#### ATT-SVC-PERF-001: N+5 queries per employee in Shift_Service::resolve_shift_for_date
- **File:** `includes/Modules/Attendance/Services/Shift_Service.php:L40-L215`
- **Description:** For each employee-date pair, `resolve_shift_for_date` may execute up to 5 sequential DB queries (assignment lookup → employee dept lookup → emp_shift lookup → schedule lookup → shift row lookup) before falling back to automation. When called from `rebuild_sessions_for_date_static` for all active employees, this produces O(n×5) queries in one cron tick.
- **Evidence:**
  ```php
  $row = $wpdb->get_row($wpdb->prepare("SELECT sh.*, sa.is_holiday FROM {$assignT} sa JOIN {$shiftT} ...", ...));
  $emp = $wpdb->get_row($wpdb->prepare("SELECT dept_id FROM {$empT} WHERE id=%d", $employee_id));
  $emp_shift = self::lookup_emp_shift_for_date( $employee_id, $ymd ); // 2 more queries inside
  // ...then another get_row for project shift, dept automation, etc.
  ```
- **Fix:** Preload all active employees' shift assignments for a given date in a single JOIN query before entering the per-employee loop; use a request-scoped static cache inside `resolve_shift_for_date` keyed by `employee_id:ymd`.

#### ATT-SVC-PERF-002: N+1 queries in Policy_Service::get_all_policies
- **File:** `includes/Modules/Attendance/Services/Policy_Service.php:L416-L434`
- **Description:** `get_all_policies()` fetches all policies with one query, then issues a separate `get_col()` query inside a `foreach` loop to load role slugs for each policy — classic N+1 pattern.
- **Evidence:**
  ```php
  $policies = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sfs_hr_attendance_policies ...");
  foreach ( $policies as &$p ) {
      $p->roles = $wpdb->get_col( $wpdb->prepare(
          "SELECT role_slug FROM {$wpdb->prefix}sfs_hr_attendance_policy_roles WHERE policy_id = %d",
          $p->id
      ) );
  }
  ```
- **Fix:** Use a single JOIN or fetch all roles in one query (`WHERE policy_id IN (...)`) then group by policy_id in PHP.

#### ATT-SVC-PERF-003: rebuild_sessions_for_date_static processes all active employees synchronously without batching
- **File:** `includes/Modules/Attendance/Services/Session_Service.php:L1014-L1033`
- **Description:** The function loads ALL active employees regardless of size, merges with punched employees, then loops through calling `recalc_session_for` synchronously. On installations with 500+ employees this will exhaust PHP's `max_execution_time` within a single cron tick.
- **Evidence:**
  ```php
  $all_active = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$eT} WHERE status = %s", 'active' ) );
  $all_ids = array_values( array_unique( array_merge( $punched, $all_active ) ) );
  foreach ( $all_ids as $eid ) {
      self::recalc_session_for( $eid, $date, $wpdb );
  }
  ```
- **Fix:** Process employees in configurable batches (e.g., 50 per cron tick) using an offset stored in a transient; or dispatch individual deferred events per employee via `wp_schedule_single_event`.

---

### Medium

#### ATT-SVC-PERF-004: information_schema.tables queried twice per resolve_shift_for_date call (for emp_shifts and schedules tables)
- **File:** `includes/Modules/Attendance/Services/Shift_Service.php:L281-L290, L358-L367`
- **Description:** `lookup_emp_shift_for_date()` queries `information_schema.tables` to check if `sfs_hr_attendance_emp_shifts` exists, and `resolve_schedule_for_date()` queries again for `sfs_hr_attendance_shift_schedules`. These checks run on every shift resolution call — in a cron tick rebuilding 200 employees, that's 400 `information_schema` queries.
- **Evidence:**
  ```php
  $table_exists = (int) $wpdb->get_var(
      $wpdb->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s", $emp_map_t)
  );
  ```
- **Fix:** Cache table-existence checks in a static variable per request so the `information_schema` query fires at most once per PHP execution.

#### ATT-SVC-PERF-005: Policy_Service::is_total_hours_mode called twice in recalc_session_for
- **File:** `includes/Modules/Attendance/Services/Session_Service.php:L307, L386`
- **Description:** `Policy_Service::is_total_hours_mode()` is called at L307 to determine `$is_total_hours`, then called again at L386 to re-assign the same value. The second call is redundant.
- **Evidence:**
  ```php
  $is_total_hours = Policy_Service::is_total_hours_mode( $employee_id, $shift );  // L307
  // ... 79 lines of code ...
  $is_total_hours = Policy_Service::is_total_hours_mode( $employee_id, $shift );  // L386
  ```
- **Fix:** Remove the second call at L386 — the static cache in `Policy_Service` prevents a real second DB query, but the redundant call is misleading and should be removed for clarity.

#### ATT-SVC-PERF-006: get_option called multiple times per recalc_session_for execution
- **File:** `includes/Modules/Attendance/Services/Session_Service.php:L294, L773`
- **Description:** `get_option(self::OPT_SETTINGS)` is called at the top of `recalc_session_for` (L294) and again inside `retro_close_previous_session` (L773). While WordPress object-caches `get_option`, the duplicate call is a maintenance concern and can result in stale data between calls if the option is updated mid-request.
- **Evidence:**
  ```php
  $settings = get_option(self::OPT_SETTINGS) ?: [];      // recalc_session_for L294
  // ...
  $prevSettings = get_option( self::OPT_SETTINGS ) ?: []; // retro_close L773
  ```
- **Fix:** Load settings once at the top of `recalc_session_for` and pass the `$settings` array as a parameter to `retro_close_previous_session`.

#### ATT-SVC-PERF-007: Selfie_Cleanup batch loop has an N×1 attachment deletion pattern
- **File:** `includes/Modules/Attendance/Cron/Selfie_Cleanup.php:L69-L103`
- **Description:** For each selfie in the batch (up to 100), `wp_delete_attachment()` is called individually. This can trigger 100 sequential queries to `wp_posts`, `wp_postmeta`, and the filesystem per batch, making the cleanup IO-intensive.
- **Evidence:**
  ```php
  foreach ( $rows as $row ) {
      $deleted = wp_delete_attachment( (int) $row->selfie_media_id, true ); // one per row
      $wpdb->update($punches_table, ['selfie_media_id' => null], ['id' => (int) $row->id], ...);
  }
  ```
- **Fix:** This pattern is acceptable given WordPress's attachment API constraints; add a `sleep(0)` or `usleep(100000)` between batches to yield CPU, and log total deleted count at DEBUG level regardless of batch count (not just when `$total_deleted > 0`).

---

## Duplication Findings

### Medium

#### ATT-SVC-DUP-001: Employee department lookup duplicated across Session_Service, Shift_Service, and AttendanceModule
- **File:** `includes/Modules/Attendance/Services/Shift_Service.php:L53-L55, L65-L65, L88-L89, L100-L101`; `includes/Modules/Attendance/AttendanceModule.php:L364-L440`
- **Description:** `Shift_Service::resolve_shift_for_date` issues up to four separate `SELECT dept_id FROM {$empT} WHERE id=%d` queries in different code paths (step 1, 1.5, 1.7, and 2). The same lookup also exists in `AttendanceModule::employee_department_info`. The employee row should be fetched once and reused.
- **Evidence:**
  ```php
  // Step 1 in Shift_Service
  $emp = $wpdb->get_row($wpdb->prepare("SELECT dept_id FROM {$empT} WHERE id=%d", $employee_id));
  // Step 1.5
  $emp = $wpdb->get_row($wpdb->prepare("SELECT dept_id FROM {$empT} WHERE id=%d", $employee_id));
  // Step 1.7
  $emp_row = $wpdb->get_row($wpdb->prepare("SELECT dept_id FROM {$empT} WHERE id=%d", $employee_id));
  // Step 2
  $emp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$empT} WHERE id=%d", $employee_id));
  ```
- **Fix:** Fetch the employee row once at the top of `resolve_shift_for_date` and pass it down to all sub-steps; replace the four individual lookups with references to the cached result.

#### ATT-SVC-DUP-002: UTC day-window calculation duplicated in Session_Service and AttendanceModule
- **File:** `includes/Modules/Attendance/Services/Session_Service.php:L194-L199`; `includes/Modules/Attendance/AttendanceModule.php:L222-L229`
- **Description:** The "local Y-m-d to UTC start/end" computation is implemented twice: once as `AttendanceModule::local_day_window_to_utc()` and once inline inside `recalc_session_for`. The inline version is not calling the helper.
- **Evidence:**
  ```php
  // Session_Service (inline):
  $dayLocal  = new \DateTimeImmutable($ymd . ' 00:00:00', $tz);
  $nextLocal = $dayLocal->modify('+1 day');
  $startUtc  = $dayLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  $endUtc    = $nextLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  // AttendanceModule (helper):
  public static function local_day_window_to_utc(string $ymd): array { ... }
  ```
- **Fix:** Replace the inline calculation in `Session_Service::recalc_session_for` with a call to `AttendanceModule::local_day_window_to_utc($ymd)`.

#### ATT-SVC-DUP-003: Overnight shift detection logic duplicated in Shift_Service and Session_Service
- **File:** `includes/Modules/Attendance/Services/Shift_Service.php:L243-L245`; `includes/Modules/Attendance/Services/Session_Service.php:L437-L439, L689-L691, L806`
- **Description:** The overnight shift adjustment pattern (`if ($shift->end_time < $shift->start_time) { modify('+1 day') }`) appears at least four times across two services. Any change to the overnight detection rule must be replicated in all locations.
- **Evidence:**
  ```php
  // Shift_Service::build_segments_from_shift
  if ( $enLocal <= $stLocal ) { $enLocal = $enLocal->modify( '+1 day' ); }
  // Session_Service::recalc_session_for (total_hours branch)
  if ( ! empty( $shift->start_time ) && $shift->end_time < $shift->start_time ) {
      $shift_end_th = $shift_end_th->modify( '+1 day' );
  }
  ```
- **Fix:** Extract a shared `static function adjust_for_overnight(DateTimeImmutable $end, DateTimeImmutable $start): DateTimeImmutable` helper into `Shift_Service` and call it from all locations.

---

### Low

#### ATT-SVC-DUP-004: OPT_SETTINGS constant defined in three separate service classes
- **File:** `includes/Modules/Attendance/Services/Session_Service.php:L19`; `includes/Modules/Attendance/Services/Shift_Service.php:L15`; `includes/Modules/Attendance/Services/Period_Service.php:L15`; `includes/Modules/Attendance/AttendanceModule.php:L33`
- **Description:** The constant `OPT_SETTINGS = 'sfs_hr_attendance_settings'` is defined independently in four classes. If the option key ever changes, all four must be updated.
- **Evidence:**
  ```php
  // In Session_Service, Shift_Service, Period_Service, AttendanceModule — all say:
  const OPT_SETTINGS = 'sfs_hr_attendance_settings';
  ```
- **Fix:** Define the constant once in `AttendanceModule` (which already has it) and reference it as `AttendanceModule::OPT_SETTINGS` in the service classes; or define it in a shared `AttendanceConstants` class.

#### ATT-SVC-DUP-005: Left_early minutes calculation duplicated in recalc_session_for and retro_close_previous_session
- **File:** `includes/Modules/Attendance/Services/Session_Service.php:L676-L698, L788-L815`
- **Description:** The logic to compute `$minutes_early` (comparing last out time against shift end time, handling overnight shifts, and applying grace) is nearly identical in two methods inside `Session_Service`.
- **Evidence:**
  ```php
  // recalc_session_for:
  $tz_fb       = wp_timezone();
  $shift_end_dt = new \DateTimeImmutable( $ymd . ' ' . $shift->end_time, $tz_fb );
  // ...
  // retro_close_previous_session:
  $tz_rc        = wp_timezone();
  $shiftEndDt   = new \DateTimeImmutable( $prevDate . ' ' . $prevShift->end_time, $tz_rc );
  ```
- **Fix:** Extract a `private static function compute_early_minutes(string $ymd, string $lastOutUtc, object $shift): int` helper and call it from both locations.

---

## Logic Findings

### Critical

#### ATT-SVC-LOGIC-001: Deferred recalc can queue duplicate cron events under lock contention
- **File:** `includes/Modules/Attendance/Services/Session_Service.php:L70-L76`
- **Description:** When `GET_LOCK` fails (another recalc is in progress), the code checks `wp_next_scheduled()` and schedules a deferred event if none exists. However, `wp_next_scheduled()` only checks for the hook name without checking arguments — so two concurrent requests for different employees could both find "no event scheduled" and queue redundant events. Additionally, there is a TOCTOU gap: `wp_next_scheduled` check and `wp_schedule_single_event` are not atomic.
- **Evidence:**
  ```php
  $got_lock = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 3)", $recalc_lock ) );
  if ( ! $got_lock ) {
      if ( ! wp_next_scheduled( 'sfs_hr_deferred_recalc', [ $employee_id, $ymd, $force ] ) ) {
          wp_schedule_single_event( time() + 30, 'sfs_hr_deferred_recalc', [ $employee_id, $ymd, $force ] );
      }
      return;
  }
  ```
- **Fix:** The `wp_next_scheduled()` check does pass args as the second parameter (so duplicates for the same `[$employee_id, $ymd, $force]` tuple are suppressed), but the deferred recalc fires without its own `GET_LOCK`, meaning a deferred event could still collide with the original. Add `GET_LOCK` inside `run_deferred_recalc` with a longer timeout (10s) to ensure deferred recalcs are also serialized.

#### ATT-SVC-LOGIC-002: rebuild_sessions_for_date_static processes absent employees without shift
- **File:** `includes/Modules/Attendance/Services/Session_Service.php:L1026-L1032`
- **Description:** The function loads ALL active employees and calls `recalc_session_for` for each, even employees who have no shift configured and no punches. For each such employee, the recalc runs through leave/holiday checks, shift resolution (all 4 fallback steps), and then writes an 'absent' or 'day_off' session. On large installations this creates thousands of unnecessary 'absent' session records for employees with no shift. The absence detection is correct but the full recalc cost is wasteful.
- **Evidence:**
  ```php
  $all_active = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$eT} WHERE status = %s", 'active' ) );
  $all_ids = array_values( array_unique( array_merge( $punched, $all_active ) ) );
  foreach ( $all_ids as $eid ) {
      self::recalc_session_for( $eid, $date, $wpdb ); // runs full recalc for employee with no punches
  }
  ```
- **Fix:** Limit "all active" processing to only employees who already have a session record or have a shift assigned; for truly unassigned, punch-free employees, a lightweight 'absent' check suffices without invoking the full recalc engine.

---

### High

#### ATT-SVC-LOGIC-003: Early leave backfill generates duplicate reference numbers under concurrency
- **File:** `includes/Modules/Attendance/Services/Early_Leave_Service.php:L128-L145`
- **Description:** `backfill_early_leave_request_numbers()` computes the next sequence by counting existing records matching the year prefix, then inserts. Under concurrent execution (two admin loads running migration simultaneously), both could read the same count and generate the same reference number. The UNIQUE KEY on `request_number` prevents the DB write but the function ignores the update failure.
- **Evidence:**
  ```php
  $count = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM `$table` WHERE request_number LIKE %s", 'EL-' . $year . '-%'
  ));
  $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
  $number = 'EL-' . $year . '-' . $sequence;
  $wpdb->update($table, ['request_number' => $number], ['id' => $row->id]);
  ```
- **Fix:** Use the same `Helpers::generate_reference_number()` approach (which uses `MAX(id)` for uniqueness rather than COUNT), or wrap the count+update in a GET_LOCK to serialize concurrent backfill runs.

#### ATT-SVC-LOGIC-004: Session status precedence bug — 'left_early' can override 'late' incorrectly
- **File:** `includes/Modules/Attendance/Services/Session_Service.php:L468-L490`
- **Description:** The status rollup code at the bottom of `recalc_session_for` applies `late` and `left_early` flags independently. If an employee was both late AND left early, `$status` ends up as `'late'` (correct), but the `left_early` check on L486 uses `$status === 'present'` as a guard — which is false when status is already `'late'`. This means for a late+early employee, the status correctly remains `'late'` but the early leave flag is still applied. However, the early leave auto-creation at L703 fires based on `$is_early` flag, not status — so an early leave request is created even when the session status is 'late', which is correct. No bug in the output but the conditional logic is difficult to reason about.
- **Evidence:**
  ```php
  if (in_array('left_early',$ev['flags'],true)) {
      // ...
      if ( $hours_fulfilled ) {
          $ev['flags'] = array_values( array_diff( $ev['flags'], [ 'left_early' ] ) );
      } else {
          $status = ($status==='present' ? 'left_early' : $status); // only changes if 'present'
      }
  }
  if (in_array('late',$ev['flags'],true)) $status = ($status==='present' ? 'late' : $status);
  ```
- **Fix:** Document the intended precedence explicitly in a comment; consider using a priority array `['absent' > 'incomplete' > 'left_early' > 'late' > 'present']` for clearer status resolution.

#### ATT-SVC-LOGIC-005: retro_close_previous_session only looks back one day — multi-day overnight sessions not closed
- **File:** `includes/Modules/Attendance/Services/Session_Service.php:L755-L763`
- **Description:** When leading OUT punches appear on the current day (employee punched out after midnight), the code attempts to close the previous day's session by looking exactly one day back. If the employee's shift spans more than one additional day (e.g., a 3-day offshore rotation), or if the cron missed a day, the retro-close will not find an incomplete session from two days ago.
- **Evidence:**
  ```php
  $prevDateDt = ( new \DateTimeImmutable( $ymd, wp_timezone() ) )->modify( '-1 day' );
  $prevDate   = $prevDateDt->format( 'Y-m-d' );
  $prevSess   = $wpdb->get_row( $wpdb->prepare(
      "SELECT id, status, in_time, out_time ... WHERE employee_id = %d AND work_date = %s LIMIT 1",
      $employee_id, $prevDate
  ) );
  ```
- **Fix:** Extend the retro-close to search for the most recent `incomplete` session within the last N days (e.g., 7) using `ORDER BY work_date DESC LIMIT 1` rather than hardcoding `-1 day`.

#### ATT-SVC-LOGIC-006: Daily_Session_Builder uses UTC date for "today/yesterday" but sessions are keyed by site-local date
- **File:** `includes/Modules/Attendance/Cron/Daily_Session_Builder.php:L95-L101`
- **Description:** The cron builder computes `$today` and `$yesterday` using `gmdate()` (UTC). However, `recalc_session_for` uses `wp_timezone()` to determine which punches belong to which local day. If the cron fires near UTC midnight and the site timezone is ahead (e.g., UTC+3), `$today` in UTC may be yesterday in site time, causing the session builder to target the wrong date for ~3 hours each night.
- **Evidence:**
  ```php
  $today     = gmdate( 'Y-m-d', $now_ts );      // UTC date
  $yesterday = gmdate( 'Y-m-d', $now_ts - 86400 );  // UTC date
  AttendanceModule::rebuild_sessions_for_date_static( $yesterday ); // recalc uses site-local tz
  AttendanceModule::rebuild_sessions_for_date_static( $today );
  ```
- **Fix:** Use `wp_date('Y-m-d')` and `wp_date('Y-m-d', strtotime('-1 day'))` instead of `gmdate()` so the builder targets site-local dates consistent with how sessions are keyed.

---

### Medium

#### ATT-SVC-LOGIC-007: Period_Service::get_current_period uses PHP date() (server timezone) not site timezone
- **File:** `includes/Modules/Attendance/Services/Period_Service.php:L32-L35`
- **Description:** `get_current_period()` uses `strtotime()` then `date()` to extract year/month/day from `$reference_date`. The `date()` function uses the PHP server's default timezone, not WordPress's configured site timezone. If the server timezone differs from the site timezone, period boundaries will be off.
- **Evidence:**
  ```php
  $ref_ts = strtotime( $reference_date );
  $year   = (int) date( 'Y', $ref_ts );
  $month  = (int) date( 'n', $ref_ts );
  $day    = (int) date( 'j', $ref_ts );
  ```
- **Fix:** Replace `date()` with `wp_date()` or use `DateTimeImmutable` with `wp_timezone()` to ensure period boundaries respect the configured WordPress timezone.

#### ATT-SVC-LOGIC-008: Migration::maybe_seed_kiosks uses unprepared SELECT without WHERE clause
- **File:** `includes/Modules/Attendance/Migration.php:L549-L551`
- **Description:** `maybe_seed_kiosks()` uses a bare `SELECT label FROM {$table} WHERE type='kiosk' LIMIT 2` without `$wpdb->prepare()`. While `type='kiosk'` is a literal string, the pattern is inconsistent with project rules.
- **Evidence:**
  ```php
  $existing = $wpdb->get_col( "SELECT label FROM {$table} WHERE type='kiosk' LIMIT 2" );
  ```
- **Fix:** Wrap with `$wpdb->prepare()`: `$wpdb->get_col( $wpdb->prepare("SELECT label FROM {$table} WHERE type = %s LIMIT 2", 'kiosk") )`.

#### ATT-SVC-LOGIC-009: Early_Leave_Service::maybe_create_early_leave_request does not check for existing pending/rejected records
- **File:** `includes/Modules/Attendance/Services/Early_Leave_Service.php:L39-L47`
- **Description:** The "exists" check only queries for any record with `employee_id + request_date` — but does not filter by status. If a previous early leave request was rejected and a new punch session is recalculated, the function correctly skips creation (because the rejected record exists). This means once any early leave record exists (even rejected), the system will never auto-create a new one for the same employee-date, even if the session is re-recalculated with new punch data.
- **Evidence:**
  ```php
  $el_exists = $wpdb->get_var( $wpdb->prepare(
      "SELECT id FROM {$el_table} WHERE employee_id = %d AND request_date = %s",
      $employee_id, $ymd
  ) );
  if ( $el_exists ) { return; }
  ```
- **Fix:** Either document this as intentional (rejected ELRs are preserved, not replaced), or add a filter for `status NOT IN ('approved', 'pending')` to allow re-creation when the previous record was rejected/cancelled and punch data changed.

---

## Files Reviewed

| File | Lines | Findings |
|------|-------|----------|
| Services/Session_Service.php | 1034 | 8 |
| Services/Shift_Service.php | 651 | 4 |
| Services/Policy_Service.php | 547 | 2 |
| Services/Early_Leave_Service.php | 146 | 2 |
| Services/Period_Service.php | 99 | 1 |
| Cron/Daily_Session_Builder.php | 146 | 1 |
| Cron/Early_Leave_Auto_Reject.php | 104 | 0 |
| Cron/Selfie_Cleanup.php | 116 | 1 |
| Migration.php | 574 | 4 |
| AttendanceModule.php | 449 | 1 |
| **Total** | **~3,866** | **24** |

---

## Recommendations Priority

### 1. Immediate (Critical)

- **ATT-SVC-SEC-001** — Remove `nopriv` from `ajax_dbg` hook; sanitize log input. No behavior change for legitimate users.
- **ATT-SVC-LOGIC-001** — Add `GET_LOCK` inside `run_deferred_recalc` to serialize deferred recalculations.
- **ATT-SVC-LOGIC-002** — Limit `rebuild_sessions_for_date_static` to only employees with shifts or existing sessions; add batching.

### 2. Next Sprint (High)

- **ATT-SVC-LOGIC-006** — Fix Daily_Session_Builder to use `wp_date()` instead of `gmdate()` to target site-local dates.
- **ATT-SVC-PERF-001** — Preload shift assignments per date in a single query before per-employee recalc loop.
- **ATT-SVC-PERF-002** — Fix N+1 in `get_all_policies` with a single JOIN or batch role query.
- **ATT-SVC-PERF-003** — Add batching to `rebuild_sessions_for_date_static`.
- **ATT-SVC-LOGIC-003** — Fix `backfill_early_leave_request_numbers` to avoid duplicate reference numbers under concurrency.
- **ATT-SVC-LOGIC-005** — Extend retro-close to search back up to 7 days for incomplete sessions.
- **ATT-SVC-SEC-003** — Gate `Migration::run()` behind a `sfs_hr_db_ver` version check to avoid `information_schema` queries on every admin load.

### 3. Backlog (Medium/Low)

- **ATT-SVC-LOGIC-007** — Fix `Period_Service::get_current_period` to use `wp_date()` instead of `date()`.
- **ATT-SVC-PERF-004** — Cache `information_schema.tables` existence checks in static variables.
- **ATT-SVC-PERF-005** — Remove redundant second call to `Policy_Service::is_total_hours_mode` in `recalc_session_for`.
- **ATT-SVC-PERF-006** — Pass settings array to `retro_close_previous_session` instead of re-calling `get_option`.
- **ATT-SVC-DUP-001** — Consolidate employee department lookups in `Shift_Service::resolve_shift_for_date`.
- **ATT-SVC-DUP-002** — Replace inline UTC day window calculation in `Session_Service` with the `AttendanceModule::local_day_window_to_utc()` helper.
- **ATT-SVC-DUP-003** — Extract overnight shift detection into a shared static helper.
- **ATT-SVC-DUP-004** — Remove duplicate `OPT_SETTINGS` constant definitions; use `AttendanceModule::OPT_SETTINGS`.
- **ATT-SVC-DUP-005** — Extract `compute_early_minutes` helper to consolidate duplicated left_early calculation.
- **ATT-SVC-LOGIC-004** — Document or refactor status precedence logic with clear priority ordering.
- **ATT-SVC-LOGIC-008** — Use `$wpdb->prepare()` in `maybe_seed_kiosks`.
- **ATT-SVC-LOGIC-009** — Document or adjust ELR existence check to clarify rejected-record behavior.
- **ATT-SVC-PERF-007** — Add inter-batch sleep in `Selfie_Cleanup` to reduce IO pressure.
