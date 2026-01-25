<?php
namespace SFS\HR\Core\Admin\Services;

if (!defined('ABSPATH')) { exit; }

/**
 * Reports Service
 * Handles report data generation and CSV export
 */
class ReportsService {

    /**
     * Generate report data based on type and filters
     */
    public static function generate(string $type, string $date_from, string $date_to, int $dept_id): array {
        global $wpdb;

        $emp_t = $wpdb->prefix . 'sfs_hr_employees';
        $dept_t = $wpdb->prefix . 'sfs_hr_departments';
        $sessions_t = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $leave_t = $wpdb->prefix . 'sfs_hr_leave_requests';
        $type_t = $wpdb->prefix . 'sfs_hr_leave_types';

        $dept_where = $dept_id > 0 ? $wpdb->prepare(" AND e.dept_id = %d", $dept_id) : '';

        switch ($type) {
            case 'attendance_summary':
                return self::generate_attendance_summary($wpdb, $emp_t, $dept_t, $sessions_t, $date_from, $date_to, $dept_where);

            case 'leave_report':
                return self::generate_leave_report($wpdb, $emp_t, $dept_t, $leave_t, $type_t, $date_from, $date_to, $dept_where);

            case 'employee_directory':
                return self::generate_employee_directory($wpdb, $emp_t, $dept_t, $dept_where);

            case 'headcount_by_dept':
                return self::generate_headcount_by_dept($wpdb, $emp_t, $dept_t);

            case 'contract_expiry':
                return self::generate_contract_expiry($wpdb, $emp_t, $dept_t, $date_from, $date_to, $dept_where);

            case 'tenure_report':
                return self::generate_tenure_report($wpdb, $emp_t, $dept_t, $dept_where);

            case 'document_expiry':
                return self::generate_document_expiry($wpdb, $emp_t, $dept_t, $date_from, $date_to, $dept_where);

            default:
                return [];
        }
    }

    /**
     * Export report data to CSV
     */
    public static function export_csv(string $type, array $report_data): void {
        $filename = sanitize_file_name($type . '_' . date('Y-m-d_His') . '.csv');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // BOM for Excel UTF-8
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Header row
        fputcsv($output, $report_data['columns']);

        // Data rows
        foreach ($report_data['rows'] as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    private static function generate_attendance_summary($wpdb, $emp_t, $dept_t, $sessions_t, $date_from, $date_to, $dept_where): array {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                e.employee_code,
                CONCAT(e.first_name, ' ', e.last_name) AS name,
                d.name AS department,
                SUM(CASE WHEN s.status = 'present' THEN 1 ELSE 0 END) AS present_days,
                SUM(CASE WHEN s.status = 'late' THEN 1 ELSE 0 END) AS late_days,
                SUM(CASE WHEN s.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
                SUM(CASE WHEN s.status = 'on_leave' THEN 1 ELSE 0 END) AS leave_days,
                SUM(COALESCE(s.overtime_minutes, 0)) AS total_ot_minutes
             FROM {$emp_t} e
             LEFT JOIN {$dept_t} d ON d.id = e.dept_id
             LEFT JOIN {$sessions_t} s ON s.employee_id = e.id AND s.work_date BETWEEN %s AND %s
             WHERE e.status = 'active' {$dept_where}
             GROUP BY e.id
             ORDER BY d.name, e.first_name",
            $date_from, $date_to
        ), ARRAY_A);

        return [
            'title' => __('Attendance Summary Report', 'sfs-hr'),
            'description' => sprintf(__('Period: %s to %s', 'sfs-hr'), $date_from, $date_to),
            'columns' => [__('Code','sfs-hr'), __('Name','sfs-hr'), __('Department','sfs-hr'), __('Present','sfs-hr'), __('Late','sfs-hr'), __('Absent','sfs-hr'), __('Leave','sfs-hr'), __('OT Hours','sfs-hr')],
            'rows' => array_map(function($r) {
                return [
                    $r['employee_code'] ?? '',
                    $r['name'] ?? '',
                    $r['department'] ?? '',
                    $r['present_days'] ?? 0,
                    $r['late_days'] ?? 0,
                    $r['absent_days'] ?? 0,
                    $r['leave_days'] ?? 0,
                    round(($r['total_ot_minutes'] ?? 0) / 60, 1),
                ];
            }, $rows),
        ];
    }

    private static function generate_leave_report($wpdb, $emp_t, $dept_t, $leave_t, $type_t, $date_from, $date_to, $dept_where): array {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                e.employee_code,
                CONCAT(e.first_name, ' ', e.last_name) AS name,
                d.name AS department,
                t.name AS leave_type,
                r.start_date,
                r.end_date,
                r.days,
                r.status,
                r.reason
             FROM {$leave_t} r
             JOIN {$emp_t} e ON e.id = r.employee_id
             LEFT JOIN {$dept_t} d ON d.id = e.dept_id
             LEFT JOIN {$type_t} t ON t.id = r.type_id
             WHERE r.start_date <= %s AND r.end_date >= %s {$dept_where}
             ORDER BY r.start_date DESC",
            $date_to, $date_from
        ), ARRAY_A);

        return [
            'title' => __('Leave Report', 'sfs-hr'),
            'description' => sprintf(__('Period: %s to %s', 'sfs-hr'), $date_from, $date_to),
            'columns' => [__('Code','sfs-hr'), __('Name','sfs-hr'), __('Department','sfs-hr'), __('Type','sfs-hr'), __('From','sfs-hr'), __('To','sfs-hr'), __('Days','sfs-hr'), __('Status','sfs-hr')],
            'rows' => array_map(function($r) {
                return [
                    $r['employee_code'] ?? '',
                    $r['name'] ?? '',
                    $r['department'] ?? '',
                    $r['leave_type'] ?? '',
                    $r['start_date'] ?? '',
                    $r['end_date'] ?? '',
                    $r['days'] ?? 0,
                    ucfirst($r['status'] ?? ''),
                ];
            }, $rows),
        ];
    }

    private static function generate_employee_directory($wpdb, $emp_t, $dept_t, $dept_where): array {
        $rows = $wpdb->get_results(
            "SELECT
                e.employee_code,
                CONCAT(e.first_name, ' ', e.last_name) AS name,
                e.email,
                e.phone,
                d.name AS department,
                e.position,
                e.hired_at,
                e.status
             FROM {$emp_t} e
             LEFT JOIN {$dept_t} d ON d.id = e.dept_id
             WHERE 1=1 {$dept_where}
             ORDER BY d.name, e.first_name",
            ARRAY_A
        );

        return [
            'title' => __('Employee Directory', 'sfs-hr'),
            'description' => __('Complete employee listing', 'sfs-hr'),
            'columns' => [__('Code','sfs-hr'), __('Name','sfs-hr'), __('Email','sfs-hr'), __('Phone','sfs-hr'), __('Department','sfs-hr'), __('Position','sfs-hr'), __('Hire Date','sfs-hr'), __('Status','sfs-hr')],
            'rows' => array_map(function($r) {
                return [
                    $r['employee_code'] ?? '',
                    $r['name'] ?? '',
                    $r['email'] ?? '',
                    $r['phone'] ?? '',
                    $r['department'] ?? '',
                    $r['position'] ?? '',
                    $r['hired_at'] ?? '',
                    ucfirst($r['status'] ?? ''),
                ];
            }, $rows),
        ];
    }

    private static function generate_headcount_by_dept($wpdb, $emp_t, $dept_t): array {
        $rows = $wpdb->get_results(
            "SELECT
                d.name AS department,
                COUNT(CASE WHEN e.status = 'active' THEN 1 END) AS active,
                COUNT(CASE WHEN e.status = 'inactive' THEN 1 END) AS inactive,
                COUNT(CASE WHEN e.status = 'terminated' THEN 1 END) AS terminated,
                COUNT(e.id) AS total
             FROM {$dept_t} d
             LEFT JOIN {$emp_t} e ON e.dept_id = d.id
             WHERE d.active = 1
             GROUP BY d.id
             ORDER BY d.name",
            ARRAY_A
        );

        return [
            'title' => __('Headcount by Department', 'sfs-hr'),
            'description' => __('Employee count breakdown by department', 'sfs-hr'),
            'columns' => [__('Department','sfs-hr'), __('Active','sfs-hr'), __('Inactive','sfs-hr'), __('Terminated','sfs-hr'), __('Total','sfs-hr')],
            'rows' => array_map(function($r) {
                return [$r['department'] ?? '', $r['active'] ?? 0, $r['inactive'] ?? 0, $r['terminated'] ?? 0, $r['total'] ?? 0];
            }, $rows),
        ];
    }

    private static function generate_contract_expiry($wpdb, $emp_t, $dept_t, $date_from, $date_to, $dept_where): array {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                e.employee_code,
                CONCAT(e.first_name, ' ', e.last_name) AS name,
                d.name AS department,
                e.contract_type,
                e.contract_end_date,
                DATEDIFF(e.contract_end_date, CURDATE()) AS days_remaining
             FROM {$emp_t} e
             LEFT JOIN {$dept_t} d ON d.id = e.dept_id
             WHERE e.status = 'active'
               AND e.contract_end_date IS NOT NULL
               AND e.contract_end_date BETWEEN %s AND %s
               {$dept_where}
             ORDER BY e.contract_end_date ASC",
            $date_from, $date_to
        ), ARRAY_A);

        return [
            'title' => __('Contract Expiry Report', 'sfs-hr'),
            'description' => sprintf(__('Contracts expiring between %s and %s', 'sfs-hr'), $date_from, $date_to),
            'columns' => [__('Code','sfs-hr'), __('Name','sfs-hr'), __('Department','sfs-hr'), __('Contract Type','sfs-hr'), __('End Date','sfs-hr'), __('Days Left','sfs-hr')],
            'rows' => array_map(function($r) {
                return [
                    $r['employee_code'] ?? '',
                    $r['name'] ?? '',
                    $r['department'] ?? '',
                    $r['contract_type'] ?? '',
                    $r['contract_end_date'] ?? '',
                    $r['days_remaining'] ?? '',
                ];
            }, $rows),
        ];
    }

    private static function generate_tenure_report($wpdb, $emp_t, $dept_t, $dept_where): array {
        $rows = $wpdb->get_results(
            "SELECT
                e.employee_code,
                CONCAT(e.first_name, ' ', e.last_name) AS name,
                d.name AS department,
                e.hired_at,
                TIMESTAMPDIFF(YEAR, e.hired_at, CURDATE()) AS years,
                TIMESTAMPDIFF(MONTH, e.hired_at, CURDATE()) % 12 AS months
             FROM {$emp_t} e
             LEFT JOIN {$dept_t} d ON d.id = e.dept_id
             WHERE e.status = 'active' AND e.hired_at IS NOT NULL {$dept_where}
             ORDER BY e.hired_at ASC",
            ARRAY_A
        );

        return [
            'title' => __('Employee Tenure Report', 'sfs-hr'),
            'description' => __('Employee service duration', 'sfs-hr'),
            'columns' => [__('Code','sfs-hr'), __('Name','sfs-hr'), __('Department','sfs-hr'), __('Hire Date','sfs-hr'), __('Tenure','sfs-hr')],
            'rows' => array_map(function($r) {
                $tenure = sprintf(__('%d years, %d months', 'sfs-hr'), $r['years'] ?? 0, $r['months'] ?? 0);
                return [
                    $r['employee_code'] ?? '',
                    $r['name'] ?? '',
                    $r['department'] ?? '',
                    $r['hired_at'] ?? '',
                    $tenure,
                ];
            }, $rows),
        ];
    }

    private static function generate_document_expiry($wpdb, $emp_t, $dept_t, $date_from, $date_to, $dept_where): array {
        $doc_t = $wpdb->prefix . 'sfs_hr_employee_documents';
        $doc_exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $doc_t
        ));

        if (!$doc_exists) {
            return [
                'title' => __('Document Expiry Report', 'sfs-hr'),
                'description' => __('Documents table not found.', 'sfs-hr'),
                'columns' => [],
                'rows' => [],
            ];
        }

        $doc_types = \SFS\HR\Modules\Documents\DocumentsModule::get_document_types();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    doc.document_type,
                    doc.document_name,
                    doc.expiry_date,
                    e.employee_code,
                    CONCAT(e.first_name, ' ', e.last_name) AS emp_name,
                    d.name AS department,
                    DATEDIFF(doc.expiry_date, CURDATE()) AS days_until
                 FROM {$doc_t} doc
                 JOIN {$emp_t} e ON e.id = doc.employee_id
                 LEFT JOIN {$dept_t} d ON d.id = e.dept_id
                 WHERE doc.status = 'active'
                   AND doc.expiry_date IS NOT NULL
                   AND doc.expiry_date BETWEEN %s AND %s
                   AND e.status = 'active' {$dept_where}
                 ORDER BY doc.expiry_date ASC",
                $date_from,
                $date_to
            ),
            ARRAY_A
        );

        return [
            'title' => __('Document Expiry Report', 'sfs-hr'),
            'description' => sprintf(__('Documents expiring between %s and %s', 'sfs-hr'), $date_from, $date_to),
            'columns' => [__('Employee','sfs-hr'), __('Code','sfs-hr'), __('Department','sfs-hr'), __('Document Type','sfs-hr'), __('Document Name','sfs-hr'), __('Expiry Date','sfs-hr'), __('Status','sfs-hr')],
            'rows' => array_map(function($r) use ($doc_types) {
                $days = (int)$r['days_until'];
                if ($days < 0) {
                    $status = __('EXPIRED', 'sfs-hr');
                } elseif ($days <= 30) {
                    $status = sprintf(__('Expires in %d days', 'sfs-hr'), $days);
                } else {
                    $status = sprintf(__('%d days remaining', 'sfs-hr'), $days);
                }
                return [
                    $r['emp_name'] ?? '',
                    $r['employee_code'] ?? '',
                    $r['department'] ?? '',
                    $doc_types[$r['document_type']] ?? ucfirst(str_replace('_', ' ', $r['document_type'])),
                    $r['document_name'] ?? '',
                    $r['expiry_date'] ?? '',
                    $status,
                ];
            }, $rows),
        ];
    }
}
