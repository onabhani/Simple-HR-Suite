<?php
namespace SFS\HR\Core;
if ( ! defined('ABSPATH') ) { exit; }

class Database {
public static function install(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    /* ---------------- Employees ---------------- */
$employees = "CREATE TABLE {$wpdb->prefix}sfs_hr_employees (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL UNIQUE,
  employee_code VARCHAR(64) NOT NULL UNIQUE,
  first_name VARCHAR(191) NULL,
  last_name VARCHAR(191) NULL,
  email VARCHAR(191) NULL,
  phone VARCHAR(64) NULL,
  dept_id BIGINT UNSIGNED NULL,
  position VARCHAR(191) NULL,
  gender VARCHAR(16) NULL,
  status ENUM('active','inactive','terminated') NOT NULL DEFAULT 'active',
  hired_at DATE NULL,
  base_salary DECIMAL(12,2) NULL DEFAULT NULL,
  national_id VARCHAR(64) NULL,
  national_id_expiry DATE NULL,
  passport_no VARCHAR(64) NULL,
  passport_expiry DATE NULL,
  emergency_contact_name VARCHAR(191) NULL,
  emergency_contact_phone VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_status (status),
  KEY idx_dept (dept_id)
) $charset;";
dbDelta($employees);


    /* ---------------- Departments ---------------- */
    $departments = "CREATE TABLE {$wpdb->prefix}sfs_hr_departments (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(191) NOT NULL,
      manager_user_id BIGINT UNSIGNED NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NULL,
      updated_at DATETIME NULL,
      PRIMARY KEY (id)
    ) $charset;";
    dbDelta($departments);

    /* ---------------- Leave types ---------------- */
    $leave_types = "CREATE TABLE {$wpdb->prefix}sfs_hr_leave_types (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      name VARCHAR(191) NOT NULL,
      is_paid TINYINT(1) NOT NULL DEFAULT 1,             -- NEW
      requires_approval TINYINT(1) NOT NULL DEFAULT 1,   -- NEW
      annual_quota INT NOT NULL DEFAULT 30,              -- NEW
      allow_negative TINYINT(1) NOT NULL DEFAULT 0,      -- NEW
      is_annual TINYINT(1) NOT NULL DEFAULT 0,           -- NEW
      active TINYINT(1) NOT NULL DEFAULT 1,              -- NEW
      special_code VARCHAR(32) NULL,                     -- NEW (SICK_SHORT/SICK_LONG/HAJJ/MATERNITY)
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,                      -- NEW
      PRIMARY KEY (id),
      UNIQUE KEY uniq_name (name)
    ) $charset;";
    dbDelta($leave_types);

    /* ---------------- Leave requests ---------------- */
    $leave_requests = "CREATE TABLE {$wpdb->prefix}sfs_hr_leave_requests (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      employee_id BIGINT UNSIGNED NOT NULL,
      type_id BIGINT UNSIGNED NOT NULL,
      start_date DATE NOT NULL,
      end_date DATE NOT NULL,
      days INT NOT NULL DEFAULT 0,                       -- align with module (int days)
      reason TEXT NULL,
      status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
      created_by BIGINT UNSIGNED NULL,
      approver_id BIGINT UNSIGNED NULL,                  -- NEW (used by module)
      approver_note TEXT NULL,                           -- NEW
      decided_at DATETIME NULL,                          -- NEW
      approved_by BIGINT UNSIGNED NULL,                  -- legacy-safe (ok to keep)
      approved_at DATETIME NULL,                         -- legacy-safe (ok to keep)
      pay_breakdown LONGTEXT NULL,                       -- NEW (json tiers for sick long)
      paid_days INT NOT NULL DEFAULT 0,                  -- NEW
      unpaid_days INT NOT NULL DEFAULT 0,                -- NEW
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY idx_emp (employee_id),
      KEY idx_type (type_id),
      KEY idx_status (status)
    ) $charset;";
    dbDelta($leave_requests);

    /* ---------------- Leave balances ---------------- */
    $leave_balances = "CREATE TABLE {$wpdb->prefix}sfs_hr_leave_balances (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      employee_id BIGINT UNSIGNED NOT NULL,
      type_id BIGINT UNSIGNED NOT NULL,
      year INT NOT NULL,
      opening INT NOT NULL DEFAULT 0,
      accrued INT NOT NULL DEFAULT 0,
      used INT NOT NULL DEFAULT 0,
      carried_over INT NOT NULL DEFAULT 0,
      closing INT NOT NULL DEFAULT 0,
      created_at DATETIME NULL,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_emp_type_year (employee_id, type_id, year),
      KEY idx_emp (employee_id),
      KEY idx_type (type_id)
    ) $charset;";
    dbDelta($leave_balances);

    /* ---------------- Seed defaults (safe) ---------------- */
    $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_leave_types");
    if ($count === 0) {
        $now = current_time('mysql');
        $wpdb->insert("{$wpdb->prefix}sfs_hr_leave_types", [
          'name'=>'Annual','is_paid'=>1,'requires_approval'=>1,'annual_quota'=>30,'allow_negative'=>0,'is_annual'=>1,
          'active'=>1,'special_code'=>null,'created_at'=>$now,'updated_at'=>$now
        ]);
        $wpdb->insert("{$wpdb->prefix}sfs_hr_leave_types", [
          'name'=>'Sick (Short)','is_paid'=>1,'requires_approval'=>1,'annual_quota'=>30,'allow_negative'=>0,'is_annual'=>0,
          'active'=>1,'special_code'=>'SICK_SHORT','created_at'=>$now,'updated_at'=>$now
        ]);
        $wpdb->insert("{$wpdb->prefix}sfs_hr_leave_types", [
          'name'=>'Sick (Long)','is_paid'=>1,'requires_approval'=>1,'annual_quota'=>0,'allow_negative'=>0,'is_annual'=>0,
          'active'=>1,'special_code'=>'SICK_LONG','created_at'=>$now,'updated_at'=>$now
        ]);
        $wpdb->insert("{$wpdb->prefix}sfs_hr_leave_types", [
          'name'=>'Hajj','is_paid'=>1,'requires_approval'=>1,'annual_quota'=>0,'allow_negative'=>0,'is_annual'=>0,
          'active'=>1,'special_code'=>'HAJJ','created_at'=>$now,'updated_at'=>$now
        ]);
        $wpdb->insert("{$wpdb->prefix}sfs_hr_leave_types", [
          'name'=>'Maternity','is_paid'=>1,'requires_approval'=>1,'annual_quota'=>0,'allow_negative'=>0,'is_annual'=>0,
          'active'=>1,'special_code'=>'MATERNITY','created_at'=>$now,'updated_at'=>$now
        ]);
        $wpdb->insert("{$wpdb->prefix}sfs_hr_leave_types", [
          'name'=>'Unpaid','is_paid'=>0,'requires_approval'=>1,'annual_quota'=>0,'allow_negative'=>1,'is_annual'=>0,
          'active'=>1,'special_code'=>null,'created_at'=>$now,'updated_at'=>$now
        ]);
    }
}

}
