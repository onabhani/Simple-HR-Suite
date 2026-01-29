-- Diagnostic query for "left_early" issue
-- Employee: Afrah Almutairi (USR-3918)

-- 1. Get employee details
SELECT
    e.id AS employee_id,
    e.employee_code,
    e.first_name,
    e.last_name,
    e.dept_id,
    d.name AS department_name
FROM wp_sfs_hr_employees e
LEFT JOIN wp_sfs_hr_departments d ON d.id = e.dept_id
WHERE e.employee_code = 'USR-3918';

-- 2. Check employee's shift history (from emp_shifts table)
SELECT
    es.id,
    es.employee_id,
    es.shift_id,
    es.start_date,
    sh.name AS shift_name,
    sh.start_time,
    sh.end_time,
    sh.dept_ids,
    sh.weekly_overrides
FROM wp_sfs_hr_attendance_emp_shifts es
JOIN wp_sfs_hr_attendance_shifts sh ON sh.id = es.shift_id
JOIN wp_sfs_hr_employees e ON e.id = es.employee_id
WHERE e.employee_code = 'USR-3918'
ORDER BY es.start_date DESC;

-- 3. Check the Head Office shift details
SELECT
    id,
    name,
    start_time,
    end_time,
    dept_id,
    dept_ids,
    weekly_overrides,
    active
FROM wp_sfs_hr_attendance_shifts
WHERE name LIKE '%Head Office%' OR id = 6;

-- 4. Check sessions with "left_early" status or flag for this employee
SELECT
    s.id,
    s.work_date,
    s.in_time,
    s.out_time,
    s.status,
    s.flags_json,
    s.calc_meta_json,
    s.net_minutes,
    s.rounded_net_minutes
FROM wp_sfs_hr_attendance_sessions s
JOIN wp_sfs_hr_employees e ON e.id = s.employee_id
WHERE e.employee_code = 'USR-3918'
  AND (s.status = 'left_early' OR s.flags_json LIKE '%left_early%')
ORDER BY s.work_date DESC
LIMIT 10;

-- 5. Check punches for a specific date (e.g., 2026-01-07)
SELECT
    p.id,
    p.punch_type,
    p.punch_time,
    p.valid_geo,
    p.valid_selfie
FROM wp_sfs_hr_attendance_punches p
JOIN wp_sfs_hr_employees e ON e.id = p.employee_id
WHERE e.employee_code = 'USR-3918'
  AND DATE(CONVERT_TZ(p.punch_time, 'UTC', 'Asia/Riyadh')) = '2026-01-07'
ORDER BY p.punch_time;

-- 6. Check if there's a date-specific shift assignment
SELECT
    sa.*,
    sh.name AS shift_name,
    sh.start_time,
    sh.end_time
FROM wp_sfs_hr_attendance_shift_assign sa
JOIN wp_sfs_hr_attendance_shifts sh ON sh.id = sa.shift_id
JOIN wp_sfs_hr_employees e ON e.id = sa.employee_id
WHERE e.employee_code = 'USR-3918'
ORDER BY sa.work_date DESC
LIMIT 10;

-- 7. Check department automation settings (stored in options)
SELECT option_value
FROM wp_options
WHERE option_name = 'sfs_hr_attendance_settings';

-- 8. Check all shifts and their department assignments
SELECT
    id,
    name,
    start_time,
    end_time,
    dept_id,
    dept_ids,
    active
FROM wp_sfs_hr_attendance_shifts
WHERE active = 1
ORDER BY id;

-- 9. CRITICAL: Check the calc_meta_json for a session - this shows what shift was actually used
SELECT
    s.work_date,
    s.status,
    s.flags_json,
    s.calc_meta_json
FROM wp_sfs_hr_attendance_sessions s
JOIN wp_sfs_hr_employees e ON e.id = s.employee_id
WHERE e.employee_code = 'USR-3918'
ORDER BY s.work_date DESC
LIMIT 5;

-- 10. Compare with a working employee on the same shift
-- First, find another employee with same shift assignment
SELECT
    e.employee_code,
    e.first_name,
    e.last_name,
    e.dept_id,
    es.shift_id,
    sh.name AS shift_name
FROM wp_sfs_hr_attendance_emp_shifts es
JOIN wp_sfs_hr_employees e ON e.id = es.employee_id
JOIN wp_sfs_hr_attendance_shifts sh ON sh.id = es.shift_id
WHERE sh.name LIKE '%Head Office%'
  AND e.employee_code != 'USR-3918'
ORDER BY es.start_date DESC;
