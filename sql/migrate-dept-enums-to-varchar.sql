-- Migration: Convert department ENUM columns to VARCHAR for flexibility
-- This allows using actual departments from wp_sfs_hr_departments table
-- Existing data ('office', 'showroom', 'warehouse', 'factory') is preserved automatically

-- 1. Shifts table: dept column
ALTER TABLE wp_sfs_hr_attendance_shifts
MODIFY COLUMN dept VARCHAR(100) NOT NULL
COMMENT 'Department slug - can be predefined (office/showroom/warehouse/factory) or custom department slug';

-- 2. Devices table: allowed_dept column
ALTER TABLE wp_sfs_hr_attendance_devices
MODIFY COLUMN allowed_dept VARCHAR(100) NOT NULL DEFAULT 'any'
COMMENT 'Allowed department slug - can be predefined, custom, or "any" for all departments';

-- Verification queries (run after migration to check):
-- SELECT DISTINCT dept FROM wp_sfs_hr_attendance_shifts;
-- SELECT DISTINCT allowed_dept FROM wp_sfs_hr_attendance_devices;
