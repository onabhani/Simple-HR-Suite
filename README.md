# Simple HR Suite (Standalone) v0.1.5

- Employees admin: list, edit, terminate, delete (delete blocked if loans exist), CSV import/export, sync/link/add.
- Leave module:
  - DB tables: sfs_hr_leave_types, sfs_hr_leave_requests
  - Admin: Leave Types (add/delete), Leave Requests (approve/reject)
  - Frontend: [sfs_hr_leave_request], [sfs_hr_my_leaves]
- Roles/Caps: sfs_hr.manage, sfs_hr.employee.view, sfs_hr.employee.edit, sfs_hr.leave.approve
- Auto-ensure HR row for WP users on register/first login.
