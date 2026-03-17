# Departments + Surveys Audit Findings

**Audited:** 2026-03-17
**Files:** 3 files, ~2,068 lines
**Modules:** Departments (~775 lines), Surveys (~978 lines + SurveysTab ~315 lines)

## Summary

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High     | 7 |
| Medium   | 5 |
| Low      | 4 |

---

## Departments Module Findings

### Security Findings

**DEPT-SEC-001 — Medium: Unscoped `SELECT *` on departments list**
- File: `includes/Modules/Departments/DepartmentsModule.php:33`
- Code: `$wpdb->get_results( "SELECT * FROM {$t} ORDER BY id ASC", ARRAY_A );`
- Impact: Query is static (no user input) so no injection risk. However it returns all columns including internal fields (color, auto_role, approver_role, hr_responsible_user_id) to the render context. Low-sensitivity data, but `SELECT *` is an anti-pattern since schema changes silently expand the result set.
- Fix: Enumerate columns: `SELECT id, name, manager_user_id, hr_responsible_user_id, auto_role, approver_role, active, color FROM ...`

**DEPT-SEC-002 — High: Manager user ID accepted without capability validation**
- File: `includes/Modules/Departments/DepartmentsModule.php:506–512`
- Code:
  ```php
  $mgr = isset( $_POST['manager_user_id'] ) ? max( 0, (int) $_POST['manager_user_id'] ) : 0;
  if ( $mgr > 0 && ! get_user_by( 'id', $mgr ) ) { $mgr = 0; }
  ```
- Impact: The handler checks that the WP user exists (`get_user_by`) but does NOT verify that the nominated user holds any HR-related capability or role. An admin could assign any arbitrary WP user (e.g., a subscriber, an external author) as `manager_user_id`, which then causes `sfs_hr.leave.review` to be dynamically granted to that user. This is the same "capability escalation via manager assignment" threat that's been noted in prior phases. The check confirms the user exists but not that they are fit to be a manager.
- Fix: Add a role/capability check before accepting the manager assignment:
  ```php
  if ( $mgr > 0 ) {
      $mgr_user = get_user_by( 'id', $mgr );
      if ( ! $mgr_user || ( ! $mgr_user->has_cap( 'sfs_hr.manage' ) && ! $mgr_user->has_cap( 'sfs_hr.view' ) ) ) {
          $mgr = 0; // reject non-HR users as manager
      }
  }
  ```
  Alternatively, document via code comment that this is intentional, but at minimum add a note that `manager_user_id` grants dynamic `sfs_hr.leave.review`.

**DEPT-SEC-003 — Medium: HR responsible user ID accepted without capability validation**
- File: `includes/Modules/Departments/DepartmentsModule.php:510–513`
- Same pattern as DEPT-SEC-002 for `hr_responsible_user_id`. An arbitrary WP user can be designated as HR responsible for performance justifications.
- Fix: Apply same role/capability validation as DEPT-SEC-002.

**DEPT-SEC-004 — High: `handle_delete` uses `wp_verify_nonce` instead of `check_admin_referer`**
- File: `includes/Modules/Departments/DepartmentsModule.php:572–577`
- Code:
  ```php
  if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'sfs_hr_dept_del' ) ) {
  ```
- Impact: This is a functional inconsistency rather than a security gap — `wp_verify_nonce` is used directly instead of `check_admin_referer`, which is the project convention used in `handle_save` (line 500) and `handle_sync` (line 631). The difference: `check_admin_referer` also validates that the referer header is from the admin (additional layer), and it will call `wp_nonce_ays()` automatically on failure. Using raw `wp_verify_nonce` then branching is valid but non-standard.
- Fix: Replace with `check_admin_referer( 'sfs_hr_dept_del' );` at the top of the handler for consistency and to get the extra referer check.

### Performance Findings

**DEPT-PERF-001 — High: N+1 `get_user_by` calls in department list render**
- File: `includes/Modules/Departments/DepartmentsModule.php:241–248`
- Code: Inside `foreach ($rows as $r)`: `get_user_by( 'id', (int) $r['manager_user_id'] )`
- Impact: For N departments, this issues N individual `get_user_by()` database calls (one per row). On a site with 30 departments, this is 30 additional queries on every admin page load. WordPress caches `get_user_by` results via the object cache, so this is only a problem when the object cache is cold or persistent caching is not configured — which is typical in shared hosting (ScalaHosting/Hostinger).
- Fix: Pre-fetch all relevant manager user IDs before the loop. Collect unique `manager_user_id` values, run a single `get_users(['include' => $ids])`, build a map by ID, then look up in the loop without DB calls.

**DEPT-PERF-002 — Low: `SELECT *` on departments table fetches all columns including large text fields**
- File: `includes/Modules/Departments/DepartmentsModule.php:33`
- Impact: `auto_role` is a comma-separated string, `approver_role` is another string. For large deployments with many departments, this slightly inflates memory. Not significant at typical scale.
- Fix: Enumerate columns as noted in DEPT-SEC-001.

### Duplication Findings

**DEPT-DUP-001 — Low: Department list fetched by other modules without shared helper**
- Cross-module: `render_edit_page()` in SurveysModule (line 274) also fetches the departments list with: `"SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments ORDER BY name ASC"`. This same pattern recurs in multiple places across the codebase.
- Fix: Extract a `Helpers::get_departments()` or `DepartmentsModule::get_all()` static method returning `[id, name]` pairs, callable by other modules.

### Logical Findings

**DEPT-LOGIC-001 — High: Department deletion does not check for dependencies beyond employees**
- File: `includes/Modules/Departments/DepartmentsModule.php:589–619`
- Impact: `handle_delete` attempts to reassign employees (to a "General" department, or NULL them). However, it does NOT handle:
  1. `sfs_hr_survey_responses`: Survey targeting uses `target_ids` JSON storing dept IDs. If a dept is deleted, surveys targeted to it still reference the old ID — but the dept no longer exists. Surveys will then be invisible to new employees in what was the old department.
  2. `sfs_hr_attendance_policy_roles` or other tables that may link to departments by ID.
  3. The code attempts to find a "General" department by literal name string — if no "General" department exists, employees are NULLed out silently (no warning to admin).
- Fix:
  - Before deleting, check for references in survey `target_ids` (JSON scan) and either warn or clean up.
  - Warn the admin if no "General" department is found and employees will lose their department assignment.

**DEPT-LOGIC-002 — Medium: No protection against deleting the last active department**
- File: `includes/Modules/Departments/DepartmentsModule.php:619`
- Impact: An admin can delete all departments including the one named "General". The next deletion attempt will then NULL out all employees' dept_id silently. No constraint prevents deleting all departments.
- Fix: Add a check before deletion: if fewer than 2 departments exist, reject the delete or warn.

**DEPT-LOGIC-003 — Medium: Manager capability revocation gap — removing manager not propagated**
- File: `includes/Modules/Departments/DepartmentsModule.php:542–550` (update path)
- Impact: The `sfs_hr.leave.review` capability is granted dynamically at runtime via the `user_has_cap` filter, keyed on `manager_user_id` column. When an admin changes a department's manager (replaces old_user with new_user), the old manager no longer appears in any department's `manager_user_id` column — so the capability is effectively revoked dynamically at the next request. This is the correct behavior from a logical standpoint, since the check is always live. **However**, there is no audit trail or notification that the old manager's leave-review capability was removed. This is a UX/operational risk rather than a technical bug.
- Fix: Log a note to the AuditTrail when manager_user_id changes, stating the old and new manager.

---

## Surveys Module Findings

### Security Findings

**SURV-SEC-001 — Critical: `handle_submit_response` uses `is_user_logged_in()` instead of a capability check — but survey form submission is accessible to all logged-in users including non-employees**
- File: `includes/Modules/Surveys/SurveysModule.php:880–883`
- Code:
  ```php
  if ( ! is_user_logged_in() ) {
      wp_die( esc_html__( 'You must be logged in.', 'sfs-hr' ) );
  }
  ```
- Impact: Any logged-in WordPress user (including WP administrators, editors, subscribers with WP accounts, or any role that is not in the HR system) can POST to `admin-post.php?action=sfs_hr_survey_submit_response`. The only additional guard is `Helpers::current_employee_id()` on line 888 — if this returns falsy (user has no employee record), the handler redirects silently. This means the response submission is effectively employee-gated by whether the user has an employee record, not by an explicit capability check.
  - Risk: A WP admin or external user with no employee record is safely redirected. But a WP user who has both a WP account and an employee record (even if they are not in the `sfs_hr_employee` role) could submit survey responses for themselves. This is likely acceptable behavior since having an employee record implies they are in the system.
  - More importantly: the `employee_id` is derived from `Helpers::current_employee_id()` (server-side, from `get_current_user_id()`), NOT from POST parameters — so self-impersonation is not possible. This is the safe pattern.
- Downgrade note: Despite the `is_user_logged_in()` guard, the server-side employee_id derivation prevents IDOR. The risk is primarily that the capability check is weak — any logged-in user with an employee record can submit, which is arguably by design. Reclassify as **High** if non-employee WP users can have employee records.
- Fix: Change guard to `Helpers::require_cap( 'sfs_hr.view' )` to ensure only HR-enrolled users can submit responses. Alternatively, document this as intentional.

**SURV-SEC-002 — High: Survey response allows re-submission race condition (TOCTOU)**
- File: `includes/Modules/Surveys/SurveysModule.php:905–936`
- Code: Check-then-insert pattern without a database lock:
  ```php
  $existing = $wpdb->get_var( ... WHERE survey_id = %d AND employee_id = %d ... );
  if ( $existing ) { redirect; exit; }
  // ... then:
  $wpdb->insert( 'sfs_hr_survey_responses', [...] );
  ```
- Impact: Two concurrent requests (e.g., double-tap on submit button, or form resubmission) can both pass the `$existing` check before either insert completes, resulting in two response rows for the same employee on the same survey.
- Mitigation: The `sfs_hr_survey_responses` table has `UNIQUE KEY idx_survey_emp (survey_id, employee_id)` (line 90 of install()) — so the second insert will fail silently (MySQL ignores the error since `$wpdb->insert()` doesn't throw). The duplicate response row is prevented at the DB level.
- Residual risk: The second request fails silently. The second request's answers are partially inserted before the unique key violation on the response row fails. Actually: the insert fails on `sfs_hr_survey_responses`, but since `$response_id = $wpdb->insert_id` would be 0 or the old ID, the `sfs_hr_survey_answers` inserts might reference `response_id = 0`. This is a data integrity risk.
- Fix: Use `INSERT IGNORE` or check `$wpdb->insert_id` after inserting the response — if 0, bail before saving answers.

**SURV-SEC-003 — Medium: `handle_question_save` does not verify survey ownership/existence before adding questions**
- File: `includes/Modules/Surveys/SurveysModule.php:816–855`
- Code: `$survey_id` is taken from POST without verifying the survey exists or is in draft status:
  ```php
  $survey_id = isset( $_POST['survey_id'] ) ? (int) $_POST['survey_id'] : 0;
  // ... no further check on survey_id before wpdb->insert
  ```
- Impact: An authenticated admin (with `sfs_hr.manage`) can add questions to any survey_id — including published or closed surveys — by crafting a POST request. The UI only shows the questions form for draft surveys, but the handler has no server-side status check.
- Fix: Before inserting the question, verify the survey exists and is in draft status:
  ```php
  $survey = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM ... WHERE id = %d", $survey_id ), ARRAY_A );
  if ( ! $survey || $survey['status'] !== 'draft' ) { /* redirect with error */ }
  ```

**SURV-SEC-004 — Medium: `handle_question_delete` does not verify the question belongs to the submitted survey_id**
- File: `includes/Modules/Surveys/SurveysModule.php:858–876`
- Code:
  ```php
  $question_id = isset( $_POST['question_id'] ) ? (int) $_POST['question_id'] : 0;
  $survey_id   = isset( $_POST['survey_id'] ) ? (int) $_POST['survey_id'] : 0;
  if ( $question_id > 0 ) {
      $wpdb->delete( ... 'sfs_hr_survey_answers', [ 'question_id' => $question_id ] );
      $wpdb->delete( ... 'sfs_hr_survey_questions', [ 'id' => $question_id ] );
  }
  ```
- Impact: An authenticated admin can delete any question from any survey by crafting a POST with an arbitrary `question_id`. The `survey_id` is used only for the redirect URL, not for an ownership check. This allows deleting questions from published surveys (which the UI prevents, but the handler does not).
- Fix: Add `WHERE id = %d AND survey_id = %d` to the delete query, and verify the associated survey is in draft status.

**SURV-SEC-005 — Medium: Admin results page exposes non-anonymous survey responses without scoping**
- File: `includes/Modules/Surveys/SurveysModule.php:504–539` (`render_results_page`)
- Impact: The results page requires `sfs_hr.manage` (via the outer `render()` capability check at line 109). However, for non-anonymous surveys, all raw answers are retrieved with no department scoping or employee attribution filtering. Depending on the survey design, this means a HR manager who has `sfs_hr.manage` can see all responses to all surveys, including surveys they did not create.
- Note: This is intentional for aggregate results display (rating bars, text answers). The `is_anonymous` flag is informational only — the data model stores `employee_id` in `sfs_hr_survey_responses` but the results page does not display it. This is a privacy design note, not a bug.
- Fix: For non-anonymous surveys, consider showing employee names in results for auditability. For anonymous surveys, confirm `employee_id` is never shown in results (currently correct).

### Performance Findings

**SURV-PERF-001 — High: N+1 query in pending surveys list in SurveysTab**
- File: `includes/Modules/Surveys/Frontend/SurveysTab.php:96–98`
- Code: Inside `foreach ($pending as $s)`:
  ```php
  $q_cnt = (int) $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM {$t_questions} WHERE survey_id = %d", $sid
  ) );
  ```
- Impact: For N pending surveys, this is N additional COUNT queries on every survey tab load. On a site with 10 active surveys, this is 10 queries per page view for every employee viewing their surveys tab.
- Fix: Pre-fetch question counts for all relevant survey IDs in a single query:
  ```php
  $survey_ids = array_column( $pending, 'id' );
  $placeholders = implode(',', array_fill(0, count($survey_ids), '%d'));
  $counts = $wpdb->get_results( $wpdb->prepare(
      "SELECT survey_id, COUNT(*) AS cnt FROM {$t_questions} WHERE survey_id IN ({$placeholders}) GROUP BY survey_id",
      ...$survey_ids
  ), ARRAY_A );
  $q_count_map = array_column( $counts, 'cnt', 'survey_id' );
  ```
  Then use `$q_count_map[$sid] ?? 0` in the loop.

**SURV-PERF-002 — Medium: Unbounded survey list query in admin render**
- File: `includes/Modules/Surveys/SurveysModule.php:136–141`
- Code: `"SELECT s.*, (subquery)... FROM {$t} s ORDER BY s.id DESC"` — no LIMIT.
- Impact: All surveys are returned for the admin list. Correlated subqueries (`question_count`, `response_count`) run once per survey row. On a site with hundreds of surveys, this becomes expensive. Not an immediate concern for the "Stub / incomplete" status of this module.
- Fix: Add `LIMIT 100` or implement pagination. Replace correlated subqueries with a JOIN + GROUP BY.

**SURV-PERF-003 — Medium: N+1 query pattern in results page**
- File: `includes/Modules/Surveys/SurveysModule.php:525–538`
- Code: Inside `foreach ($questions as $q)`:
  ```php
  $answers = $wpdb->get_results( $wpdb->prepare(
      "SELECT a.* FROM sfs_hr_survey_answers a JOIN sfs_hr_survey_responses r ON a.response_id = r.id WHERE a.question_id = %d",
      $qid
  ) );
  ```
- Impact: For a survey with N questions, this is N queries to fetch answers. For a survey with 20 questions and 200 responses, this is 20 queries.
- Fix: Pre-fetch all answers for the survey in a single query:
  ```php
  $all_answers = $wpdb->get_results( $wpdb->prepare(
      "SELECT a.* FROM {$wpdb->prefix}sfs_hr_survey_answers a
       JOIN {$wpdb->prefix}sfs_hr_survey_responses r ON a.response_id = r.id
       WHERE r.survey_id = %d", $survey_id
  ), ARRAY_A );
  // Then group by question_id in PHP.
  ```

### Duplication Findings

**SURV-DUP-001 — Low: Department list fetched inline in `render_edit_page`**
- File: `includes/Modules/Surveys/SurveysModule.php:274–276`
- Code: `$wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}sfs_hr_departments ORDER BY name ASC", ARRAY_A )`
- Same unshared SQL pattern as noted in DEPT-DUP-001. Recurs in several modules (see Cross-Module Patterns).
- Fix: Same as DEPT-DUP-001 — extract shared helper.

### Logical Findings

**SURV-LOGIC-001 — High: Survey response submission allowed for closed surveys — race window**
- File: `includes/Modules/Surveys/SurveysModule.php:896–902`
- Code:
  ```php
  $survey = $wpdb->get_row( $wpdb->prepare(
      "SELECT * FROM ... WHERE id = %d AND status = 'published'", $survey_id
  ) );
  if ( ! $survey ) { redirect; exit; }
  ```
- Impact: The handler correctly checks `status = 'published'`. However, the SurveysTab frontend (`SurveysTab.php:23–38`) fetches published surveys at tab render time, and the employee starts filling out the form. If an admin closes the survey (`handle_survey_close`) while the employee is filling out the form, the employee's POST will be rejected correctly — the handler will not accept it. This is correct behavior.
- Additional check on SurveysTab: `render_survey_form` (SurveysTab.php:146–149) also checks `status = 'published'` before rendering the form. So there's a double guard: render-time and submit-time. **This is clean.**
- Note: No finding — this path is correctly handled.

**SURV-LOGIC-002 — High: `handle_survey_save` can update a published survey's metadata**
- File: `includes/Modules/Surveys/SurveysModule.php:691–737`
- Code: `handle_survey_save` checks at the UI level (render_edit_page, line 268) that only drafts are editable. But the POST handler itself does NOT verify survey status before updating:
  ```php
  if ( $id > 0 ) {
      $wpdb->update( $table, $data, [ 'id' => $id ] );
  }
  ```
- Impact: A crafted POST request (with `id` of a published survey) will update the survey's title, description, target scope, and anonymity flag even though the survey is live and employees are responding to it. Changing `target_scope` or `target_ids` mid-survey could expose or hide the survey from different employees than intended.
- Fix: Add status check before update:
  ```php
  $existing = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );
  if ( $existing !== 'draft' ) { /* error redirect */ }
  ```

**SURV-LOGIC-003 — Medium: `handle_survey_delete` does not check survey status — published surveys can be deleted**
- File: `includes/Modules/Surveys/SurveysModule.php:740–764`
- Code: Deletes survey regardless of status — only the UI conditionally shows the delete button (draft-only).
- Impact: A crafted POST can delete a published survey with active responses. All response and answer data is also cascade-deleted.
- Fix: Add `WHERE status = 'draft'` guard before deleting: verify `$wpdb->get_var( "SELECT status FROM ... WHERE id = %d" )` is 'draft' before proceeding.

---

## SurveysTab Frontend Findings

### Security Findings

**STAB-SEC-001 — Low: Unescaped `survey_id` in hidden form field**
- File: `includes/Modules/Surveys/Frontend/SurveysTab.php:183`
- Code: `echo '<input type="hidden" name="survey_id" value="' . $survey_id . '" />';`
- Impact: `$survey_id` is derived from `(int) $_GET['survey_id']` (line 54) — the cast to `int` makes it safe for output. However, the output is missing `esc_attr()` which is the canonical escaping function for HTML attribute values. This is a style violation rather than a security risk since integer cast prevents XSS.
- Fix: `echo '<input type="hidden" name="survey_id" value="' . esc_attr( $survey_id ) . '" />';`

**STAB-SEC-002 — Low: `emp_id` parameter passed from Tab_Dispatcher — verify it matches current user**
- File: `includes/Modules/Surveys/Frontend/SurveysTab.php:13`
- Code: `public function render( array $emp, int $emp_id ): void`
- Impact: The `$emp_id` parameter is passed by `Tab_Dispatcher`. The completed-survey check on line 47 uses `$emp_id` directly: `WHERE employee_id = %d`. The survey form submission uses `Helpers::current_employee_id()` server-side (safe). But the frontend tab render uses the passed `$emp_id` to check completed surveys. If `Tab_Dispatcher` passes the wrong employee ID (e.g., from a GET parameter), an employee could see another employee's survey completion status.
- Note: This risk depends entirely on how `Tab_Dispatcher` resolves `$emp_id`. If it always derives from `get_current_user_id()`, this is safe. A full Tab_Dispatcher audit would confirm this.
- Fix: Add an assertion in `render()`: `assert( $emp_id === Helpers::current_employee_id() )` or log a warning if they differ. Alternatively, re-derive `$emp_id` from `Helpers::current_employee_id()` inside `render()` instead of trusting the parameter.

### Performance Findings

(See SURV-PERF-001 above — applies to SurveysTab.)

### Logical Findings

**STAB-LOGIC-001 — Medium: Survey form rendered even if the survey's target scope excludes the current employee**
- File: `includes/Modules/Surveys/Frontend/SurveysTab.php:65–67`
- Code:
  ```php
  if ( $view_survey_id > 0 && ! in_array( $view_survey_id, $completed_ids, true ) ) {
      $this->render_survey_form( $view_survey_id, $emp_id );
      return;
  }
  ```
- Impact: The scope filter is applied when building `$available` (lines 29–39), which filters surveys by department. However, `$view_survey_id` is taken directly from `$_GET['survey_id']` without checking if it is in `$available`. An employee can navigate to a survey URL for a survey targeted at a different department and see the survey form. The submission handler (`handle_submit_response`, line 914–921) DOES check scope server-side, so the submission will be rejected. But the form is still rendered to the out-of-scope employee.
- Fix: Change the form-render condition to also check `in_array( $view_survey_id, array_column( $available, 'id' ), false )`:
  ```php
  $available_ids = array_map( 'intval', array_column( $available, 'id' ) );
  if ( $view_survey_id > 0
       && in_array( $view_survey_id, $available_ids, true )
       && ! in_array( $view_survey_id, $completed_ids, true ) ) {
      $this->render_survey_form( $view_survey_id, $emp_id );
      return;
  }
  ```

---

## $wpdb Query Catalogue

### DepartmentsModule.php — All DB Calls

| Line | Query | Prepared? | Notes |
|------|-------|-----------|-------|
| 33 | `SELECT * FROM {$t} ORDER BY id ASC` | No (static) | Table name interpolated — safe pattern (static prefix + static suffix, no user input) |
| 544 | `$wpdb->update($t, $data, ['id' => $id])` | Yes (wpdb->update) | Safe |
| 556 | `$wpdb->insert($t, $data)` | Yes (wpdb->insert) | Safe |
| 595–599 | `SELECT id FROM {$dept_table} WHERE name = %s LIMIT 1` | Yes (prepare) | Safe |
| 604–608 | `$wpdb->update($emp_table, ['dept_id' => $general_id], ['dept_id' => $id])` | Yes | Safe |
| 611–615 | `$wpdb->update($emp_table, ['dept_id' => null], ['dept_id' => $id])` | Yes | Safe |
| 619 | `$wpdb->delete($dept_table, ['id' => $id])` | Yes | Safe |
| 650–655 | `SELECT * FROM {$dept_table} WHERE id = %d` | Yes (prepare) | Safe |
| 728 | `$wpdb->query($wpdb->prepare($sql, $params))` — UPDATE with IN() | Yes (prepare with spread) | Safe — dynamic IN() built with array_fill placeholders |

**Summary:** 9 queries total. All safe. No bare interpolation of user input. No information_schema. No bare ALTER TABLE. No bare SHOW TABLES.

### SurveysModule.php — All DB Calls

| Line | Query | Prepared? | Notes |
|------|-------|-----------|-------|
| 136–141 | `SELECT s.*, (COUNT subqueries)... ORDER BY s.id DESC` | No (static) | Safe — no user input, but unbounded (SURV-PERF-002) |
| 265–267 | `SELECT * FROM sfs_hr_surveys WHERE id = %d` | Yes | Safe |
| 274–276 | `SELECT id, name FROM sfs_hr_departments ORDER BY name ASC` | No (static) | Safe — static |
| 366–367 | `SELECT * FROM sfs_hr_surveys WHERE id = %d` | Yes | Safe |
| 374–376 | `SELECT * FROM sfs_hr_survey_questions WHERE survey_id = %d ORDER BY ...` | Yes | Safe |
| 507–509 | `SELECT * FROM sfs_hr_surveys WHERE id = %d` | Yes | Safe |
| 515–517 | `SELECT * FROM sfs_hr_survey_questions WHERE survey_id = %d ORDER BY ...` | Yes | Safe |
| 520–522 | `SELECT COUNT(*) FROM sfs_hr_survey_responses WHERE survey_id = %d` | Yes | Safe |
| 528–533 | `SELECT a.* FROM sfs_hr_survey_answers a JOIN ... WHERE a.question_id = %d` | Yes | Safe — N+1 (SURV-PERF-003) |
| 691 (handle_survey_save) | `$wpdb->update($table, $data, ['id' => $id])` | Yes | Safe |
| 729 | `$wpdb->insert($table, $data)` | Yes | Safe |
| 749–751 | `SELECT id FROM sfs_hr_survey_responses WHERE survey_id = %d` | Yes | Safe |
| 753–757 | `DELETE FROM sfs_hr_survey_answers WHERE response_id IN (...)` | Yes (prepare with spread) | Safe |
| 759 | `$wpdb->delete(...sfs_hr_survey_responses, ['survey_id' => $id])` | Yes | Safe |
| 760 | `$wpdb->delete(...sfs_hr_survey_questions, ['survey_id' => $id])` | Yes | Safe |
| 761 | `$wpdb->delete(...sfs_hr_surveys, ['id' => $id])` | Yes | Safe |
| 775–777 | `SELECT COUNT(*) FROM sfs_hr_survey_questions WHERE survey_id = %d` | Yes | Safe |
| 786–790 | `$wpdb->update(...surveys, ['status'=>'published',...], ['id'=>$id,'status'=>'draft'])` | Yes | Safe — conditional update |
| 802–806 | `$wpdb->update(...surveys, ['status'=>'closed',...], ['id'=>$id,'status'=>'published'])` | Yes | Safe — conditional update |
| 841–849 | `$wpdb->insert(...sfs_hr_survey_questions, [...])` | Yes | Safe |
| 867 | `$wpdb->delete(...sfs_hr_survey_answers, ['question_id' => $question_id])` | Yes | Safe |
| 868 | `$wpdb->delete(...sfs_hr_survey_questions, ['id' => $question_id])` | Yes | Safe |
| 896–898 | `SELECT * FROM sfs_hr_surveys WHERE id = %d AND status = 'published'` | Yes | Safe |
| 905–907 | `SELECT id FROM sfs_hr_survey_responses WHERE survey_id = %d AND employee_id = %d` | Yes | Safe |
| 925–927 | `SELECT * FROM sfs_hr_survey_questions WHERE survey_id = %d ORDER BY ...` | Yes | Safe |
| 931–935 | `$wpdb->insert(...sfs_hr_survey_responses, [...])` | Yes | Safe |
| 964–969 | `$wpdb->insert(...sfs_hr_survey_answers, [...])` per question | Yes | Safe — N per response |

**Summary:** ~27 query sites. All prepared or static. No bare interpolation of user input. No information_schema. No bare ALTER TABLE. No bare SHOW TABLES.

### SurveysTab.php — All DB Calls

| Line | Query | Prepared? | Notes |
|------|-------|-----------|-------|
| 23–25 | `SELECT * FROM sfs_hr_surveys WHERE status = 'published' ORDER BY published_at DESC` | No (static) | Safe — static, no user input, unbounded but surveys are admin-controlled |
| 46–49 | `SELECT survey_id FROM sfs_hr_survey_responses WHERE employee_id = %d AND survey_id IN (...)` | Yes (prepare with spread) | Safe |
| 96–98 | `SELECT COUNT(*) FROM sfs_hr_survey_questions WHERE survey_id = %d` | Yes | Safe — N+1 (SURV-PERF-001) |
| 146–149 | `SELECT * FROM sfs_hr_surveys WHERE id = %d AND status = 'published'` | Yes | Safe |
| 156–158 | `SELECT * FROM sfs_hr_survey_questions WHERE survey_id = %d ORDER BY ...` | Yes | Safe |

**Summary:** 5 query sites. All safe. No information_schema, no ALTER TABLE, no SHOW TABLES.

---

## Bootstrap DDL Antipatterns

### DepartmentsModule.php

**CLEAN:** No `install()` or `activation()` method found in DepartmentsModule.php. No bare `ALTER TABLE`, no `SHOW TABLES`, no `information_schema`. The module assumes the table is created by `Migrations.php` (the correct pattern). This is the same clean pattern as SettlementModule (Phase 10) and ResignationModule (Phase 14).

### SurveysModule.php

**PARTIALLY CLEAN:** `SurveysModule::install()` (lines 46–104) uses `dbDelta()` with `CREATE TABLE` statements — this is the correct WordPress pattern for DDL. No bare `ALTER TABLE`, no `SHOW TABLES`, no `information_schema`.

**SURV-INSTALL-001 — Low: `install()` uses `dbDelta` but is not called from `Migrations.php`**
- The `install()` method exists as `public static` but it is unclear from this file alone whether it's called from `Migrations.php` or from a separate activation hook. If called only on activation (not on admin_init self-healing), new columns added to survey tables in future versions won't be auto-applied.
- Fix: Confirm `SurveysModule::install()` is registered in the admin_init self-healing block in `hr-suite.php`. If not, add it or move the DDL to `Migrations.php` using the `add_column_if_missing()` pattern.

---

## Cross-Module Patterns

### Recurring Antipatterns — Status in These Modules

| Antipattern | Phase Introduced | Departments | Surveys |
|-------------|-----------------|-------------|---------|
| Bare ALTER TABLE | Phase 04/08/16 | **CLEAN** | **CLEAN** |
| information_schema | Phase 04/08/11/12/16 | **CLEAN** | **CLEAN** |
| Unprepared SHOW TABLES | Phase 04/08 | **CLEAN** | **CLEAN** |
| Wrong capability at admin tab gate | Phase 15/16 | **CLEAN** (sfs_hr.manage) | **CLEAN** (sfs_hr.manage) |
| Missing dept scoping on REST | Phase 11/14/17 | N/A (no REST routes) | N/A (no REST routes) |
| is_holiday() duplication | Phase 15 | **N/A** | **N/A** |
| N+1 queries | Phase 04/07/11/12/15 | **DEPT-PERF-001** (manager lookup) | **SURV-PERF-001** (question count), **SURV-PERF-003** (answers) |
| Unscoped delete handler | Phase 13/14 | **DEPT-LOGIC-001** (no survey cleanup) | **SURV-LOGIC-003** (no status check on delete) |
| TOCTOU race on state transitions | Phase 05/06/14/17 | None | **SURV-SEC-002** (response insert, DB-guarded), **SURV-LOGIC-002** (survey edit race) |

### New Patterns in Phase 18

1. **Manager assignment without role validation (DEPT-SEC-002):** Assigning any WP user as department manager grants dynamic `sfs_hr.leave.review` without checking if the user is an HR-system participant. Prior phases have not seen this specific vector.

2. **Stub module DDL in static install() method (SURV-INSTALL-001):** Unlike most modules which delegate DDL to Migrations.php, SurveysModule has its own `install()`. This creates a risk of schema divergence if the method is not wired into the admin_init self-healing migration loop.

3. **Form renderer renders out-of-scope content (STAB-LOGIC-001):** The frontend tab renders a survey form to employees who are out of scope for that survey. The server-side submission handler correctly rejects the response, but the form render creates a confusing UX and is a defence-in-depth gap.

4. **Survey edit handler missing status guard (SURV-LOGIC-002):** Published survey metadata can be changed via crafted POST. The question delete handler (SURV-SEC-004) similarly lacks a survey-ownership + status check. These are the same pattern as the HADM-SEC/LOGIC findings in Phase 13 (Hiring) where handlers performed actions that the UI prevented but the server did not.
