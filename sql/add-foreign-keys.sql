-- ============================================================================
-- Foreign Key Constraints for SFS HR Attendance Tables
-- Strategy:
--   RESTRICT  — punches, sessions, flags, early_leave_requests
--               (compliance data; block employee deletion until archived)
--   SET NULL  — audit.target_employee_id (preserve audit rows after deletion),
--               sessions.shift_assign_id (preserve session history)
--   CASCADE   — shift_assign.shift_id, emp_shifts.shift_id (helper tables)
--
-- Prerequisites:
--   - All tables must be InnoDB (MySQL default since 5.5)
--   - Orphaned rows must be cleaned before constraints are added
--
-- NOTE: Table names below use the default "wp_" prefix. In production the
-- PHP migration (AttendanceModule::maybe_add_foreign_keys) builds names
-- dynamically via $wpdb->prefix. If running this SQL manually on a site
-- with a custom prefix, find-and-replace "wp_" accordingly.
-- ============================================================================

-- Step 0: Ensure InnoDB on all attendance tables
ALTER TABLE wp_sfs_hr_attendance_punches       ENGINE = InnoDB;
ALTER TABLE wp_sfs_hr_attendance_sessions      ENGINE = InnoDB;
ALTER TABLE wp_sfs_hr_attendance_shifts         ENGINE = InnoDB;
ALTER TABLE wp_sfs_hr_attendance_shift_assign   ENGINE = InnoDB;
ALTER TABLE wp_sfs_hr_attendance_emp_shifts     ENGINE = InnoDB;
ALTER TABLE wp_sfs_hr_attendance_flags          ENGINE = InnoDB;
ALTER TABLE wp_sfs_hr_attendance_audit          ENGINE = InnoDB;
ALTER TABLE wp_sfs_hr_early_leave_requests     ENGINE = InnoDB;
ALTER TABLE wp_sfs_hr_attendance_devices        ENGINE = InnoDB;

-- Step 1: Clean orphaned rows (employees that no longer exist)
DELETE p FROM wp_sfs_hr_attendance_punches p
  LEFT JOIN wp_sfs_hr_employees e ON e.id = p.employee_id
  WHERE e.id IS NULL;

DELETE s FROM wp_sfs_hr_attendance_sessions s
  LEFT JOIN wp_sfs_hr_employees e ON e.id = s.employee_id
  WHERE e.id IS NULL;

DELETE sa FROM wp_sfs_hr_attendance_shift_assign sa
  LEFT JOIN wp_sfs_hr_employees e ON e.id = sa.employee_id
  WHERE e.id IS NULL;

DELETE es FROM wp_sfs_hr_attendance_emp_shifts es
  LEFT JOIN wp_sfs_hr_employees e ON e.id = es.employee_id
  WHERE e.id IS NULL;

DELETE f FROM wp_sfs_hr_attendance_flags f
  LEFT JOIN wp_sfs_hr_employees e ON e.id = f.employee_id
  WHERE e.id IS NULL;

-- Audit: SET NULL for orphaned employee references (preserve audit rows)
UPDATE wp_sfs_hr_attendance_audit a
  LEFT JOIN wp_sfs_hr_employees e ON e.id = a.target_employee_id
  SET a.target_employee_id = NULL
  WHERE a.target_employee_id IS NOT NULL AND e.id IS NULL;

DELETE el FROM wp_sfs_hr_early_leave_requests el
  LEFT JOIN wp_sfs_hr_employees e ON e.id = el.employee_id
  WHERE e.id IS NULL;

-- Clean orphaned shift references
DELETE sa FROM wp_sfs_hr_attendance_shift_assign sa
  LEFT JOIN wp_sfs_hr_attendance_shifts sh ON sh.id = sa.shift_id
  WHERE sh.id IS NULL;

DELETE es FROM wp_sfs_hr_attendance_emp_shifts es
  LEFT JOIN wp_sfs_hr_attendance_shifts sh ON sh.id = es.shift_id
  WHERE sh.id IS NULL;

-- Null out orphaned shift_assign_id in sessions
UPDATE wp_sfs_hr_attendance_sessions s
  LEFT JOIN wp_sfs_hr_attendance_shift_assign sa ON sa.id = s.shift_assign_id
  SET s.shift_assign_id = NULL
  WHERE s.shift_assign_id IS NOT NULL AND sa.id IS NULL;

-- Step 2: Add foreign key constraints (idempotent: safe to re-run)
-- Each block drops the constraint if it already exists, then re-adds it.

-- Punches → employees (RESTRICT: cannot delete employee with punch records)
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_sfs_hr_attendance_punches' AND CONSTRAINT_NAME = 'fk_punches_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk IS NOT NULL, 'ALTER TABLE wp_sfs_hr_attendance_punches DROP FOREIGN KEY fk_punches_employee', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE wp_sfs_hr_attendance_punches
  ADD CONSTRAINT fk_punches_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- Sessions → employees (RESTRICT)
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_sfs_hr_attendance_sessions' AND CONSTRAINT_NAME = 'fk_sessions_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk IS NOT NULL, 'ALTER TABLE wp_sfs_hr_attendance_sessions DROP FOREIGN KEY fk_sessions_employee', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE wp_sfs_hr_attendance_sessions
  ADD CONSTRAINT fk_sessions_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- Sessions → shift_assign (SET NULL: preserve session when assignment removed)
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_sfs_hr_attendance_sessions' AND CONSTRAINT_NAME = 'fk_sessions_shift_assign' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk IS NOT NULL, 'ALTER TABLE wp_sfs_hr_attendance_sessions DROP FOREIGN KEY fk_sessions_shift_assign', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE wp_sfs_hr_attendance_sessions
  ADD CONSTRAINT fk_sessions_shift_assign
  FOREIGN KEY (shift_assign_id) REFERENCES wp_sfs_hr_attendance_shift_assign(id)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Shift_assign → employees (RESTRICT)
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_sfs_hr_attendance_shift_assign' AND CONSTRAINT_NAME = 'fk_shift_assign_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk IS NOT NULL, 'ALTER TABLE wp_sfs_hr_attendance_shift_assign DROP FOREIGN KEY fk_shift_assign_employee', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE wp_sfs_hr_attendance_shift_assign
  ADD CONSTRAINT fk_shift_assign_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- Shift_assign → shifts (CASCADE: delete assignments when shift deleted)
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_sfs_hr_attendance_shift_assign' AND CONSTRAINT_NAME = 'fk_shift_assign_shift' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk IS NOT NULL, 'ALTER TABLE wp_sfs_hr_attendance_shift_assign DROP FOREIGN KEY fk_shift_assign_shift', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE wp_sfs_hr_attendance_shift_assign
  ADD CONSTRAINT fk_shift_assign_shift
  FOREIGN KEY (shift_id) REFERENCES wp_sfs_hr_attendance_shifts(id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- Emp_shifts → employees (RESTRICT)
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_sfs_hr_attendance_emp_shifts' AND CONSTRAINT_NAME = 'fk_emp_shifts_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk IS NOT NULL, 'ALTER TABLE wp_sfs_hr_attendance_emp_shifts DROP FOREIGN KEY fk_emp_shifts_employee', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE wp_sfs_hr_attendance_emp_shifts
  ADD CONSTRAINT fk_emp_shifts_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- Emp_shifts → shifts (CASCADE)
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_sfs_hr_attendance_emp_shifts' AND CONSTRAINT_NAME = 'fk_emp_shifts_shift' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk IS NOT NULL, 'ALTER TABLE wp_sfs_hr_attendance_emp_shifts DROP FOREIGN KEY fk_emp_shifts_shift', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE wp_sfs_hr_attendance_emp_shifts
  ADD CONSTRAINT fk_emp_shifts_shift
  FOREIGN KEY (shift_id) REFERENCES wp_sfs_hr_attendance_shifts(id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- Flags → employees (RESTRICT)
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_sfs_hr_attendance_flags' AND CONSTRAINT_NAME = 'fk_flags_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk IS NOT NULL, 'ALTER TABLE wp_sfs_hr_attendance_flags DROP FOREIGN KEY fk_flags_employee', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE wp_sfs_hr_attendance_flags
  ADD CONSTRAINT fk_flags_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- Audit → employees (SET NULL: audit rows survive employee deletion)
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_sfs_hr_attendance_audit' AND CONSTRAINT_NAME = 'fk_audit_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk IS NOT NULL, 'ALTER TABLE wp_sfs_hr_attendance_audit DROP FOREIGN KEY fk_audit_employee', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE wp_sfs_hr_attendance_audit
  ADD CONSTRAINT fk_audit_employee
  FOREIGN KEY (target_employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Early leave requests → employees (RESTRICT)
SET @fk = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_sfs_hr_early_leave_requests' AND CONSTRAINT_NAME = 'fk_early_leave_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql = IF(@fk IS NOT NULL, 'ALTER TABLE wp_sfs_hr_early_leave_requests DROP FOREIGN KEY fk_early_leave_employee', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE wp_sfs_hr_early_leave_requests
  ADD CONSTRAINT fk_early_leave_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;
