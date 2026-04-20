<?php
namespace SFS\HR\Modules\Documents\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Document_Compliance_Service {

    public static function get_compliance_overview( ?int $dept_id = null ): array {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $today     = wp_date( 'Y-m-d' );

        $where = "e.status = 'active'";
        $args  = [];

        if ( $dept_id ) {
            $where .= ' AND e.dept_id = %d';
            $args[] = $dept_id;
        }

        $total_employees = (int) $wpdb->get_var(
            $args
                ? $wpdb->prepare( "SELECT COUNT(*) FROM {$emp_table} e WHERE {$where}", ...$args )
                : "SELECT COUNT(*) FROM {$emp_table} e WHERE {$where}"
        );

        if ( $total_employees === 0 ) {
            return [
                'total_employees'      => 0,
                'types'                => [],
                'overall_compliance_pct' => 100.0,
            ];
        }

        $required_types = Documents_Service::get_required_document_types();

        if ( empty( $required_types ) ) {
            return [
                'total_employees'      => $total_employees,
                'types'                => [],
                'overall_compliance_pct' => 100.0,
            ];
        }

        // Batch-fetch counts per document type: valid and expired
        $type_keys        = array_keys( $required_types );
        $type_placeholders = implode( ',', array_fill( 0, count( $type_keys ), '%s' ) );

        $count_query_args = $type_keys;
        $count_where      = "e.status = 'active'";

        if ( $dept_id ) {
            $count_where     .= ' AND e.dept_id = %d';
            $count_query_args[] = $dept_id;
        }

        // Valid docs: active status + (no expiry OR expiry >= today)
        $count_query_args_valid   = array_merge( $count_query_args, [ $today ] );
        $valid_counts_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.document_type, COUNT(DISTINCT d.employee_id) AS cnt
             FROM {$doc_table} d
             JOIN {$emp_table} e ON e.id = d.employee_id
             WHERE d.document_type IN ({$type_placeholders})
               AND d.status = 'active'
               AND (d.expiry_date IS NULL OR d.expiry_date >= %s)
               AND {$count_where}
             GROUP BY d.document_type",
            ...$count_query_args_valid
        ) );

        $valid_map = [];
        foreach ( $valid_counts_raw as $row ) {
            $valid_map[ $row->document_type ] = (int) $row->cnt;
        }

        // Expired docs: active status + expiry_date < today
        $count_query_args_expired = array_merge( $count_query_args, [ $today ] );
        $expired_counts_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.document_type, COUNT(DISTINCT d.employee_id) AS cnt
             FROM {$doc_table} d
             JOIN {$emp_table} e ON e.id = d.employee_id
             WHERE d.document_type IN ({$type_placeholders})
               AND d.status = 'active'
               AND d.expiry_date IS NOT NULL
               AND d.expiry_date < %s
               AND {$count_where}
             GROUP BY d.document_type",
            ...$count_query_args_expired
        ) );

        $expired_map = [];
        foreach ( $expired_counts_raw as $row ) {
            $expired_map[ $row->document_type ] = (int) $row->cnt;
        }

        $types            = [];
        $compliant_sum    = 0;
        $type_count       = count( $required_types );

        foreach ( $required_types as $type_key => $label ) {
            $valid   = $valid_map[ $type_key ] ?? 0;
            $expired = $expired_map[ $type_key ] ?? 0;
            $missing = max( 0, $total_employees - $valid - $expired );
            $pct     = round( ( $valid / $total_employees ) * 100, 1 );

            $types[ $type_key ] = [
                'required'       => true,
                'valid'          => $valid,
                'expired'        => $expired,
                'missing'        => $missing,
                'compliance_pct' => $pct,
            ];

            $compliant_sum += $pct;
        }

        $overall = $type_count > 0 ? round( $compliant_sum / $type_count, 1 ) : 100.0;

        return [
            'total_employees'        => $total_employees,
            'types'                  => $types,
            'overall_compliance_pct' => $overall,
        ];
    }

    public static function get_expiring_report( int $days = 30, ?int $dept_id = null ): array {
        global $wpdb;

        $doc_table  = $wpdb->prefix . 'sfs_hr_employee_documents';
        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $today      = wp_date( 'Y-m-d' );
        $future     = wp_date( 'Y-m-d', strtotime( "+{$days} days" ) );

        $where = "d.status = 'active'
              AND d.expiry_date IS NOT NULL
              AND d.expiry_date BETWEEN %s AND %s
              AND e.status = 'active'";
        $args  = [ $today, $future ];

        if ( $dept_id ) {
            $where .= ' AND e.dept_id = %d';
            $args[] = $dept_id;
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.employee_code,
                    CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                    dep.name AS department_name,
                    d.document_type,
                    d.document_name,
                    d.expiry_date,
                    DATEDIFF(d.expiry_date, %s) AS days_remaining
             FROM {$doc_table} d
             JOIN {$emp_table} e ON e.id = d.employee_id
             LEFT JOIN {$dept_table} dep ON dep.id = e.dept_id
             WHERE {$where}
             ORDER BY d.expiry_date ASC",
            $today, ...$args
        ) );

        return $rows ?: [];
    }

    public static function get_missing_report( ?int $dept_id = null ): array {
        global $wpdb;

        $emp_table  = $wpdb->prefix . 'sfs_hr_employees';
        $doc_table  = $wpdb->prefix . 'sfs_hr_employee_documents';
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $today      = wp_date( 'Y-m-d' );

        $required_types = Documents_Service::get_required_document_types();

        if ( empty( $required_types ) ) {
            return [];
        }

        $emp_where = "e.status = 'active'";
        $emp_args  = [];

        if ( $dept_id ) {
            $emp_where .= ' AND e.dept_id = %d';
            $emp_args[] = $dept_id;
        }

        $employees = $emp_args
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT e.id, e.employee_code, e.first_name, e.last_name, dep.name AS department_name
                 FROM {$emp_table} e
                 LEFT JOIN {$dept_table} dep ON dep.id = e.dept_id
                 WHERE {$emp_where}
                 ORDER BY e.first_name, e.last_name",
                ...$emp_args
            ) )
            : $wpdb->get_results(
                "SELECT e.id, e.employee_code, e.first_name, e.last_name, dep.name AS department_name
                 FROM {$emp_table} e
                 LEFT JOIN {$dept_table} dep ON dep.id = e.dept_id
                 WHERE {$emp_where}
                 ORDER BY e.first_name, e.last_name"
            );

        if ( empty( $employees ) ) {
            return [];
        }

        $emp_ids      = wp_list_pluck( $employees, 'id' );
        $id_placeholders = implode( ',', array_fill( 0, count( $emp_ids ), '%d' ) );

        $type_keys        = array_keys( $required_types );
        $type_placeholders = implode( ',', array_fill( 0, count( $type_keys ), '%s' ) );

        $query_args = array_merge( $emp_ids, $type_keys, [ $today ] );

        $existing = $wpdb->get_results( $wpdb->prepare(
            "SELECT employee_id, document_type
             FROM {$doc_table}
             WHERE employee_id IN ({$id_placeholders})
               AND document_type IN ({$type_placeholders})
               AND status = 'active'
               AND (expiry_date IS NULL OR expiry_date >= %s)
             GROUP BY employee_id, document_type",
            ...$query_args
        ) );

        $existing_map = [];
        foreach ( $existing as $row ) {
            $existing_map[ $row->employee_id ][ $row->document_type ] = true;
        }

        $report = [];

        foreach ( $employees as $emp ) {
            $missing = [];
            foreach ( $type_keys as $type_key ) {
                if ( empty( $existing_map[ $emp->id ][ $type_key ] ) ) {
                    $missing[] = $type_key;
                }
            }

            if ( ! empty( $missing ) ) {
                $report[] = [
                    'employee_id'     => (int) $emp->id,
                    'employee_code'   => $emp->employee_code,
                    'employee_name'   => trim( $emp->first_name . ' ' . $emp->last_name ),
                    'department_name' => $emp->department_name ?? '',
                    'missing_types'   => $missing,
                ];
            }
        }

        return $report;
    }

    public static function get_employee_compliance( int $employee_id ): array {
        global $wpdb;

        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $today     = wp_date( 'Y-m-d' );

        $required_types = Documents_Service::get_required_document_types();

        $docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT document_type, expiry_date, status
             FROM {$doc_table}
             WHERE employee_id = %d AND status = 'active'
             ORDER BY created_at DESC",
            $employee_id
        ) );

        $docs_by_type = [];
        foreach ( $docs as $doc ) {
            if ( ! isset( $docs_by_type[ $doc->document_type ] ) ) {
                $docs_by_type[ $doc->document_type ] = $doc;
            }
        }

        $types_status   = [];
        $all_valid      = true;
        $expiring_soon  = [];

        foreach ( $required_types as $type_key => $label ) {
            $doc = $docs_by_type[ $type_key ] ?? null;

            if ( ! $doc ) {
                $types_status[ $type_key ] = [
                    'label'  => $label,
                    'status' => 'missing',
                ];
                $all_valid = false;
                continue;
            }

            $is_expired = $doc->expiry_date && strtotime( $doc->expiry_date ) < strtotime( $today );

            if ( $is_expired ) {
                $types_status[ $type_key ] = [
                    'label'  => $label,
                    'status' => 'expired',
                ];
                $all_valid = false;
                continue;
            }

            $types_status[ $type_key ] = [
                'label'  => $label,
                'status' => 'valid',
            ];

            if ( $doc->expiry_date ) {
                $days_left = (int) ( ( strtotime( $doc->expiry_date ) - strtotime( $today ) ) / 86400 );
                if ( $days_left <= 30 ) {
                    $expiring_soon[] = [
                        'document_type' => $type_key,
                        'label'         => $label,
                        'expiry_date'   => $doc->expiry_date,
                        'days_remaining' => $days_left,
                    ];
                }
            }
        }

        return [
            'types'          => $types_status,
            'compliant'      => $all_valid,
            'expiring_soon'  => $expiring_soon,
        ];
    }
}
