<?php
namespace SFS\HR\Modules\Reporting\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Helpers;

/**
 * Report Builder Service
 *
 * Custom report builder that queries across multiple HR tables.
 * Supports predefined report types, saved reports with scheduling,
 * CSV export, and cron-based email delivery.
 *
 * @since M11
 */
class Report_Builder_Service {

    /**
     * Get list of available predefined report types.
     *
     * @return array<int, array{type: string, name: string, description: string}>
     */
    public static function get_predefined_reports(): array {
        return [
            [
                'type'        => 'headcount',
                'name'        => __( 'Headcount Report', 'sfs-hr' ),
                'description' => __( 'Headcount by department, employment type, and gender.', 'sfs-hr' ),
            ],
            [
                'type'        => 'attrition',
                'name'        => __( 'Attrition Report', 'sfs-hr' ),
                'description' => __( 'Attrition rates by month and department.', 'sfs-hr' ),
            ],
            [
                'type'        => 'leave_utilization',
                'name'        => __( 'Leave Utilization Report', 'sfs-hr' ),
                'description' => __( 'Leave balances and usage per employee and type.', 'sfs-hr' ),
            ],
            [
                'type'        => 'payroll_summary',
                'name'        => __( 'Payroll Summary Report', 'sfs-hr' ),
                'description' => __( 'Payroll costs by month.', 'sfs-hr' ),
            ],
            [
                'type'        => 'attendance',
                'name'        => __( 'Attendance Report', 'sfs-hr' ),
                'description' => __( 'Attendance patterns including late arrivals, absences, and overtime.', 'sfs-hr' ),
            ],
            [
                'type'        => 'hiring_pipeline',
                'name'        => __( 'Hiring Pipeline Report', 'sfs-hr' ),
                'description' => __( 'Hiring pipeline stages and candidate counts.', 'sfs-hr' ),
            ],
            [
                'type'        => 'training',
                'name'        => __( 'Training Report', 'sfs-hr' ),
                'description' => __( 'Training session completion rates and costs.', 'sfs-hr' ),
            ],
            [
                'type'        => 'document_compliance',
                'name'        => __( 'Document Compliance Report', 'sfs-hr' ),
                'description' => __( 'Employee document expiry and compliance status.', 'sfs-hr' ),
            ],
        ];
    }

    /**
     * Execute a predefined report.
     *
     * @param string $type    Report type key.
     * @param array  $filters Optional filters: dept_id, date_from, date_to, limit.
     * @return array{columns: list<string>, rows: list<array>, generated_at: string}
     */
    public static function run_report( string $type, array $filters = [] ): array {
        $method = 'query_' . $type;

        if ( ! method_exists( static::class, $method ) ) {
            return [
                'columns'      => [],
                'rows'         => [],
                'generated_at' => Helpers::now_mysql(),
            ];
        }

        return static::$method( $filters );
    }

    /**
     * Export a report to CSV string.
     *
     * @param string $type    Report type key.
     * @param array  $filters Optional filters.
     * @param string $format  Export format (only 'csv' supported).
     * @return string CSV content with UTF-8 BOM.
     */
    public static function export_report( string $type, array $filters = [], string $format = 'csv' ): string {
        $report = static::run_report( $type, $filters );

        $handle = fopen( 'php://temp', 'r+' );
        if ( $handle === false ) {
            return '';
        }

        // UTF-8 BOM for Arabic character support in Excel
        fwrite( $handle, "\xEF\xBB\xBF" );

        // Header row
        fputcsv( $handle, $report['columns'] );

        // Data rows
        foreach ( $report['rows'] as $row ) {
            $values = [];
            foreach ( $report['columns'] as $col ) {
                $values[] = $row[ $col ] ?? '';
            }
            fputcsv( $handle, $values );
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        return $csv !== false ? $csv : '';
    }

    /**
     * Save a report configuration.
     *
     * @param array $data Report data: name, description, report_type, config, schedule_type, schedule_email.
     * @return array{success: bool, id?: int, error?: string}
     */
    public static function save_report( array $data ): array {
        global $wpdb;

        $name = sanitize_text_field( $data['name'] ?? '' );
        if ( empty( $name ) ) {
            return [ 'success' => false, 'error' => __( 'Report name is required.', 'sfs-hr' ) ];
        }

        $report_type = sanitize_text_field( $data['report_type'] ?? '' );
        if ( empty( $report_type ) ) {
            return [ 'success' => false, 'error' => __( 'Report type is required.', 'sfs-hr' ) ];
        }

        $table = $wpdb->prefix . 'sfs_hr_saved_reports';
        $now   = Helpers::now_mysql();

        $config = is_array( $data['config'] ?? null ) ? wp_json_encode( $data['config'] ) : ( $data['config'] ?? '{}' );
        $schedule_type = in_array( $data['schedule_type'] ?? 'none', [ 'none', 'daily', 'weekly', 'monthly' ], true )
            ? $data['schedule_type']
            : 'none';

        $inserted = $wpdb->insert(
            $table,
            [
                'name'           => $name,
                'description'    => sanitize_textarea_field( $data['description'] ?? '' ),
                'report_type'    => $report_type,
                'config'         => $config,
                'schedule_type'  => $schedule_type,
                'schedule_email' => sanitize_textarea_field( $data['schedule_email'] ?? '' ),
                'created_by'     => get_current_user_id(),
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( $inserted === false ) {
            return [ 'success' => false, 'error' => __( 'Failed to save report.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
    }

    /**
     * List saved reports.
     *
     * @param int|null $user_id Filter by creator (null = all).
     * @return array
     */
    public static function list_saved_reports( ?int $user_id = null ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_saved_reports';

        if ( $user_id !== null ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE created_by = %d ORDER BY updated_at DESC",
                    $user_id
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safe
            $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC", ARRAY_A );
        }

        return array_map( static function ( array $row ): array {
            $row['config'] = json_decode( $row['config'] ?? '{}', true );
            return $row;
        }, $rows ?: [] );
    }

    /**
     * Get a single saved report.
     *
     * @param int $id Report ID.
     * @return array|null
     */
    public static function get_saved_report( int $id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_saved_reports';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        $row['config'] = json_decode( $row['config'] ?? '{}', true );
        return $row;
    }

    /**
     * Delete a saved report.
     *
     * @param int $id Report ID.
     * @return bool
     */
    public static function delete_saved_report( int $id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_saved_reports';

        $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        return $deleted !== false;
    }

    /**
     * Run a saved report by ID and update last_run_at.
     *
     * @param int $id Saved report ID.
     * @return array Same format as run_report().
     */
    public static function run_saved_report( int $id ): array {
        global $wpdb;

        $saved = static::get_saved_report( $id );
        if ( ! $saved ) {
            return [ 'columns' => [], 'rows' => [], 'generated_at' => Helpers::now_mysql() ];
        }

        $config  = $saved['config'] ?? [];
        $filters = $config['filters'] ?? [];
        $result  = static::run_report( $saved['report_type'], $filters );

        $table = $wpdb->prefix . 'sfs_hr_saved_reports';
        $wpdb->update(
            $table,
            [ 'last_run_at' => Helpers::now_mysql(), 'updated_at' => Helpers::now_mysql() ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return $result;
    }

    /**
     * Process scheduled reports (called by daily cron).
     *
     * Daily: run every day. Weekly: run if Monday. Monthly: run if 1st.
     */
    public static function process_scheduled_reports(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_saved_reports';

        $day_of_week = (int) current_time( 'N' ); // 1=Mon ... 7=Sun
        $day_of_month = (int) current_time( 'j' );

        $types = [ "'daily'" ];
        if ( $day_of_week === 1 ) {
            $types[] = "'weekly'";
        }
        if ( $day_of_month === 1 ) {
            $types[] = "'monthly'";
        }

        $in_clause = implode( ',', $types );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ENUM literals, not user input
        $reports = $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE schedule_type IN ({$in_clause})
               AND schedule_email != ''
             ORDER BY id ASC",
            ARRAY_A
        );

        if ( empty( $reports ) ) {
            return;
        }

        foreach ( $reports as $report ) {
            $config  = json_decode( $report['config'] ?? '{}', true );
            $filters = $config['filters'] ?? [];
            $csv     = static::export_report( $report['report_type'], $filters );

            $emails = array_filter( array_map( 'trim', explode( ',', $report['schedule_email'] ) ) );
            if ( empty( $emails ) || empty( $csv ) ) {
                continue;
            }

            $subject = sprintf(
                /* translators: %s: report name */
                __( '[HR Report] %s', 'sfs-hr' ),
                $report['name']
            );

            $message = sprintf(
                /* translators: 1: report name, 2: date */
                __( 'Please find attached the scheduled report "%1$s" generated on %2$s.', 'sfs-hr' ),
                $report['name'],
                current_time( 'Y-m-d H:i' )
            );

            // Write CSV to temp file for attachment
            $temp_dir  = get_temp_dir();
            $file_name = sanitize_file_name( $report['name'] . '-' . current_time( 'Y-m-d' ) . '.csv' );
            $file_path = $temp_dir . $file_name;

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $file_path, $csv );

            $attachments = [ $file_path ];
            $headers     = [ 'Content-Type: text/html; charset=UTF-8' ];

            wp_mail( $emails, $subject, wpautop( $message ), $headers, $attachments );

            // Clean up temp file
            if ( file_exists( $file_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $file_path );
            }

            // Update last_run_at
            $wpdb->update(
                $table,
                [ 'last_run_at' => Helpers::now_mysql(), 'updated_at' => Helpers::now_mysql() ],
                [ 'id' => (int) $report['id'] ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Private query methods for each report type                        */
    /* ------------------------------------------------------------------ */

    /**
     * Headcount by department, type, and gender.
     */
    private static function query_headcount( array $filters ): array {
        global $wpdb;
        $emp  = $wpdb->prefix . 'sfs_hr_employees';
        $dept = $wpdb->prefix . 'sfs_hr_departments';

        $where = "e.status = 'active'";
        $args  = [];

        if ( ! empty( $filters['dept_id'] ) ) {
            $where .= ' AND e.dept_id = %d';
            $args[] = (int) $filters['dept_id'];
        }

        $sql = "SELECT
                    COALESCE(d.name, '--') AS department,
                    COALESCE(e.gender, '--') AS gender,
                    COUNT(*) AS headcount
                FROM {$emp} e
                LEFT JOIN {$dept} d ON d.id = e.dept_id
                WHERE {$where}
                GROUP BY d.name, e.gender
                ORDER BY d.name, e.gender";

        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        return [
            'columns'      => [ 'department', 'gender', 'headcount' ],
            'rows'         => $rows ?: [],
            'generated_at' => Helpers::now_mysql(),
        ];
    }

    /**
     * Attrition: terminated employees grouped by month and department.
     */
    private static function query_attrition( array $filters ): array {
        global $wpdb;
        $emp  = $wpdb->prefix . 'sfs_hr_employees';
        $dept = $wpdb->prefix . 'sfs_hr_departments';

        $where = "e.status = 'terminated'";
        $args  = [];

        if ( ! empty( $filters['dept_id'] ) ) {
            $where .= ' AND e.dept_id = %d';
            $args[] = (int) $filters['dept_id'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where .= ' AND e.updated_at >= %s';
            $args[] = sanitize_text_field( $filters['date_from'] ) . ' 00:00:00';
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where .= ' AND e.updated_at <= %s';
            $args[] = sanitize_text_field( $filters['date_to'] ) . ' 23:59:59';
        }

        $sql = "SELECT
                    DATE_FORMAT(e.updated_at, '%%Y-%%m') AS month,
                    COALESCE(d.name, '--') AS department,
                    COUNT(*) AS terminated_count
                FROM {$emp} e
                LEFT JOIN {$dept} d ON d.id = e.dept_id
                WHERE {$where}
                GROUP BY month, d.name
                ORDER BY month DESC, d.name";

        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        return [
            'columns'      => [ 'month', 'department', 'terminated_count' ],
            'rows'         => $rows ?: [],
            'generated_at' => Helpers::now_mysql(),
        ];
    }

    /**
     * Leave utilization: balances and usage per employee and type.
     */
    private static function query_leave_utilization( array $filters ): array {
        global $wpdb;
        $bal  = $wpdb->prefix . 'sfs_hr_leave_balances';
        $emp  = $wpdb->prefix . 'sfs_hr_employees';
        $lt   = $wpdb->prefix . 'sfs_hr_leave_types';

        $year  = (int) ( $filters['year'] ?? current_time( 'Y' ) );
        $where = 'b.year = %d AND e.status = %s';
        $args  = [ $year, 'active' ];

        if ( ! empty( $filters['dept_id'] ) ) {
            $where .= ' AND e.dept_id = %d';
            $args[] = (int) $filters['dept_id'];
        }

        $limit_clause = '';
        if ( ! empty( $filters['limit'] ) ) {
            $limit_clause = $wpdb->prepare( ' LIMIT %d', (int) $filters['limit'] );
        }

        $sql = "SELECT
                    e.employee_code,
                    CONCAT(e.first_name, ' ', COALESCE(e.last_name, '')) AS employee_name,
                    lt.name AS leave_type,
                    b.opening,
                    b.accrued,
                    b.used,
                    b.carried_over,
                    b.closing
                FROM {$bal} b
                INNER JOIN {$emp} e ON e.id = b.employee_id
                INNER JOIN {$lt} lt ON lt.id = b.type_id
                WHERE {$where}
                ORDER BY e.employee_code, lt.name{$limit_clause}";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

        return [
            'columns'      => [ 'employee_code', 'employee_name', 'leave_type', 'opening', 'accrued', 'used', 'carried_over', 'closing' ],
            'rows'         => $rows ?: [],
            'generated_at' => Helpers::now_mysql(),
        ];
    }

    /**
     * Payroll summary: costs grouped by period month.
     */
    private static function query_payroll_summary( array $filters ): array {
        global $wpdb;
        $runs    = $wpdb->prefix . 'sfs_hr_payroll_runs';
        $periods = $wpdb->prefix . 'sfs_hr_payroll_periods';

        $where = "r.status IN ('approved','paid')";
        $args  = [];

        if ( ! empty( $filters['date_from'] ) ) {
            $where .= ' AND p.start_date >= %s';
            $args[] = sanitize_text_field( $filters['date_from'] );
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where .= ' AND p.end_date <= %s';
            $args[] = sanitize_text_field( $filters['date_to'] );
        }

        $sql = "SELECT
                    DATE_FORMAT(p.start_date, '%%Y-%%m') AS period_month,
                    SUM(r.total_gross) AS total_gross,
                    SUM(r.total_deductions) AS total_deductions,
                    SUM(r.total_net) AS total_net,
                    SUM(r.employee_count) AS employee_count
                FROM {$runs} r
                INNER JOIN {$periods} p ON p.id = r.period_id
                WHERE {$where}
                GROUP BY period_month
                ORDER BY period_month DESC";

        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        return [
            'columns'      => [ 'period_month', 'total_gross', 'total_deductions', 'total_net', 'employee_count' ],
            'rows'         => $rows ?: [],
            'generated_at' => Helpers::now_mysql(),
        ];
    }

    /**
     * Attendance patterns: late, absent, overtime by month.
     */
    private static function query_attendance( array $filters ): array {
        global $wpdb;
        $sess = $wpdb->prefix . 'sfs_hr_attendance_sessions';
        $emp  = $wpdb->prefix . 'sfs_hr_employees';

        $where = '1=1';
        $args  = [];

        if ( ! empty( $filters['dept_id'] ) ) {
            $where .= ' AND e.dept_id = %d';
            $args[] = (int) $filters['dept_id'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where .= ' AND s.work_date >= %s';
            $args[] = sanitize_text_field( $filters['date_from'] );
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where .= ' AND s.work_date <= %s';
            $args[] = sanitize_text_field( $filters['date_to'] );
        }

        $sql = "SELECT
                    DATE_FORMAT(s.work_date, '%%Y-%%m') AS month,
                    COUNT(*) AS total_sessions,
                    SUM(CASE WHEN s.status = 'late' THEN 1 ELSE 0 END) AS late_count,
                    SUM(CASE WHEN s.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                    SUM(s.overtime_minutes) AS total_overtime_minutes,
                    ROUND(AVG(s.net_minutes), 0) AS avg_net_minutes
                FROM {$sess} s
                INNER JOIN {$emp} e ON e.id = s.employee_id
                WHERE {$where}
                GROUP BY month
                ORDER BY month DESC";

        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        return [
            'columns'      => [ 'month', 'total_sessions', 'late_count', 'absent_count', 'total_overtime_minutes', 'avg_net_minutes' ],
            'rows'         => $rows ?: [],
            'generated_at' => Helpers::now_mysql(),
        ];
    }

    /**
     * Hiring pipeline: candidates grouped by status.
     */
    private static function query_hiring_pipeline( array $filters ): array {
        global $wpdb;
        $cand = $wpdb->prefix . 'sfs_hr_candidates';

        $where = '1=1';
        $args  = [];

        if ( ! empty( $filters['dept_id'] ) ) {
            $where .= ' AND c.dept_id = %d';
            $args[] = (int) $filters['dept_id'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where .= ' AND c.created_at >= %s';
            $args[] = sanitize_text_field( $filters['date_from'] ) . ' 00:00:00';
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where .= ' AND c.created_at <= %s';
            $args[] = sanitize_text_field( $filters['date_to'] ) . ' 23:59:59';
        }

        $sql = "SELECT
                    c.status,
                    COUNT(*) AS candidate_count
                FROM {$cand} c
                WHERE {$where}
                GROUP BY c.status
                ORDER BY FIELD(c.status, 'applied','screening','hr_reviewed','dept_pending','dept_approved','gm_pending','gm_approved','hired','rejected')";

        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        return [
            'columns'      => [ 'status', 'candidate_count' ],
            'rows'         => $rows ?: [],
            'generated_at' => Helpers::now_mysql(),
        ];
    }

    /**
     * Training: session completion rates and total cost.
     */
    private static function query_training( array $filters ): array {
        global $wpdb;
        $sessions    = $wpdb->prefix . 'sfs_hr_training_sessions';
        $enrollments = $wpdb->prefix . 'sfs_hr_training_enrollments';
        $programs    = $wpdb->prefix . 'sfs_hr_training_programs';

        $where = '1=1';
        $args  = [];

        if ( ! empty( $filters['date_from'] ) ) {
            $where .= ' AND ts.start_date >= %s';
            $args[] = sanitize_text_field( $filters['date_from'] );
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where .= ' AND ts.start_date <= %s';
            $args[] = sanitize_text_field( $filters['date_to'] );
        }

        $sql = "SELECT
                    tp.title AS program_title,
                    ts.status AS session_status,
                    ts.start_date,
                    COUNT(te.id) AS total_enrolled,
                    SUM(CASE WHEN te.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    ROUND(
                        CASE WHEN COUNT(te.id) > 0
                            THEN SUM(CASE WHEN te.status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(te.id)
                            ELSE 0
                        END, 1
                    ) AS completion_rate,
                    COALESCE(tp.cost_per_person, 0) * COUNT(te.id) AS total_cost
                FROM {$sessions} ts
                INNER JOIN {$programs} tp ON tp.id = ts.program_id
                LEFT JOIN {$enrollments} te ON te.session_id = ts.id
                WHERE {$where}
                GROUP BY ts.id, tp.title, ts.status, ts.start_date, tp.cost_per_person
                ORDER BY ts.start_date DESC";

        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        return [
            'columns'      => [ 'program_title', 'session_status', 'start_date', 'total_enrolled', 'completed_count', 'completion_rate', 'total_cost' ],
            'rows'         => $rows ?: [],
            'generated_at' => Helpers::now_mysql(),
        ];
    }

    /**
     * Document compliance: expiry status counts per employee.
     */
    private static function query_document_compliance( array $filters ): array {
        global $wpdb;
        $docs = $wpdb->prefix . 'sfs_hr_employee_documents';
        $emp  = $wpdb->prefix . 'sfs_hr_employees';

        $today = current_time( 'Y-m-d' );
        $where = "e.status = 'active' AND doc.status = 'active'";
        $args  = [];

        if ( ! empty( $filters['dept_id'] ) ) {
            $where .= ' AND e.dept_id = %d';
            $args[] = (int) $filters['dept_id'];
        }

        $sql = "SELECT
                    e.employee_code,
                    CONCAT(e.first_name, ' ', COALESCE(e.last_name, '')) AS employee_name,
                    doc.document_type,
                    doc.document_name,
                    doc.expiry_date,
                    CASE
                        WHEN doc.expiry_date IS NULL THEN 'no_expiry'
                        WHEN doc.expiry_date < %s THEN 'expired'
                        WHEN doc.expiry_date <= DATE_ADD(%s, INTERVAL 30 DAY) THEN 'expiring_soon'
                        ELSE 'valid'
                    END AS compliance_status
                FROM {$docs} doc
                INNER JOIN {$emp} e ON e.id = doc.employee_id
                WHERE {$where}
                ORDER BY doc.expiry_date ASC";

        $all_args = array_merge( [ $today, $today ], $args );

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$all_args ), ARRAY_A );

        return [
            'columns'      => [ 'employee_code', 'employee_name', 'document_type', 'document_name', 'expiry_date', 'compliance_status' ],
            'rows'         => $rows ?: [],
            'generated_at' => Helpers::now_mysql(),
        ];
    }
}
