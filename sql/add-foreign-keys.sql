-- ============================================================================
-- Foreign Key Constraints for SFS HR Attendance Tables
-- Strategy:
--   RESTRICT  — audit, punches, sessions, flags, early_leave_requests
--               (compliance data; block employee deletion until archived)
--   SET NULL  — sessions.shift_assign_id (preserve session history)
--   CASCADE   — shift_assign.shift_id, emp_shifts.shift_id (helper tables)
--
-- Prerequisites:
--   - All tables must be InnoDB (MySQL default since 5.5)
--   - Orphaned rows must be cleaned before constraints are added
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

-- Step 2: Add foreign key constraints

-- Punches → employees (RESTRICT: cannot delete employee with punch records)
ALTER TABLE wp_sfs_hr_attendance_punches
  ADD CONSTRAINT fk_punches_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- Sessions → employees (RESTRICT)
ALTER TABLE wp_sfs_hr_attendance_sessions
  ADD CONSTRAINT fk_sessions_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- Sessions → shift_assign (SET NULL: preserve session when assignment removed)
ALTER TABLE wp_sfs_hr_attendance_sessions
  ADD CONSTRAINT fk_sessions_shift_assign
  FOREIGN KEY (shift_assign_id) REFERENCES wp_sfs_hr_attendance_shift_assign(id)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Shift_assign → employees (RESTRICT)
ALTER TABLE wp_sfs_hr_attendance_shift_assign
  ADD CONSTRAINT fk_shift_assign_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- Shift_assign → shifts (CASCADE: delete assignments when shift deleted)
ALTER TABLE wp_sfs_hr_attendance_shift_assign
  ADD CONSTRAINT fk_shift_assign_shift
  FOREIGN KEY (shift_id) REFERENCES wp_sfs_hr_attendance_shifts(id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- Emp_shifts → employees (RESTRICT)
ALTER TABLE wp_sfs_hr_attendance_emp_shifts
  ADD CONSTRAINT fk_emp_shifts_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- Emp_shifts → shifts (CASCADE)
ALTER TABLE wp_sfs_hr_attendance_emp_shifts
  ADD CONSTRAINT fk_emp_shifts_shift
  FOREIGN KEY (shift_id) REFERENCES wp_sfs_hr_attendance_shifts(id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- Flags → employees (RESTRICT)
ALTER TABLE wp_sfs_hr_attendance_flags
  ADD CONSTRAINT fk_flags_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- Audit → employees (SET NULL: audit rows survive employee deletion)
ALTER TABLE wp_sfs_hr_attendance_audit
  ADD CONSTRAINT fk_audit_employee
  FOREIGN KEY (target_employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Early leave requests → employees (RESTRICT)
ALTER TABLE wp_sfs_hr_early_leave_requests
  ADD CONSTRAINT fk_early_leave_employee
  FOREIGN KEY (employee_id) REFERENCES wp_sfs_hr_employees(id)
  ON DELETE RESTRICT ON UPDATE CASCADE;
