<?php
namespace SFS\HR\Modules\Documents\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compliance Service
 *
 * M6.2 — Document Compliance Dashboard.
 * Provides compliance reporting, expiry tracking, and automated
 * flagging for the HR admin dashboard and cron jobs.
 */
class Compliance_Service {

    /**
     * Get company-wide compliance overview for all active employees.
     *
     * @return array{total_employees: int, fully_compliant: int, partially_compliant: int, non_compliant: int, compliance_percentage: float}
     */
    public static function get_company_compliance_overview(): array {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';

        // Get all active employees
        $employees = $wpdb->get_results(
            "SELECT id, employee_code FROM {$emp_table} WHERE status != 'terminated'"
        );

        $total = count( $employees );
        if ( $total === 0 ) {
            return [
                'total_employees'      => 0,
                'fully_compliant'      => 0,
                'partially_compliant'  => 0,
                'non_compliant'        => 0,
                'compliance_percentage' => 0.0,
            ];
        }

        $required_types = Documents_Service::get_required_document_types();
        $required_count = count( $required_types );

        // If no required types configured, everyone is compliant
        if ( $required_count === 0 ) {
            return [
                'total_employees'      => $total,
                'fully_compliant'      => $total,
                'partially_compliant'  => 0,
                'non_compliant'        => 0,
                'compliance_percentage' => 100.0,
            ];
        }

        // Batch-fetch all active documents for active employees
        $employee_ids = wp_list_pluck( $employees, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $employee_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
        $docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT employee_id, document_type, expiry_date
             FROM {$doc_table}
             WHERE employee_id IN ({$placeholders})
               AND status = 'active'",
            ...$employee_ids
        ) );

        // Group valid docs by employee
        $employee_docs = [];
        $today = current_time( 'Y-m-d' );
        foreach ( $docs as $doc ) {
            // Only count non-expired documents
            if ( empty( $doc->expiry_date ) || $doc->expiry_date >= $today ) {
                $employee_docs[ $doc->employee_id ][ $doc->document_type ] = true;
            }
        }

        $fully_compliant     = 0;
        $partially_compliant = 0;
        $non_compliant       = 0;

        foreach ( $employee_ids as $emp_id ) {
            $valid_types = $employee_docs[ $emp_id ] ?? [];
            $matched     = 0;

            foreach ( $required_types as $type_key => $label ) {
                if ( ! empty( $valid_types[ $type_key ] ) ) {
                    $matched++;
                }
            }

            if ( $matched === $required_count ) {
                $fully_compliant++;
            } elseif ( $matched > 0 ) {
                $partially_compliant++;
            } else {
                $non_compliant++;
            }
        }

        return [
            'total_employees'      => $total,
            'fully_compliant'      => $fully_compliant,
            'partially_compliant'  => $partially_compliant,
            'non_compliant'        => $non_compliant,
            'compliance_percentage' => round( ( $fully_compliant / $total ) * 100, 1 ),
        ];
    }

    /**
     * Get expiring documents report grouped by urgency tier.
     *
     * @param int      $days          Look-ahead window in days.
     * @param int|null $department_id Optional department filter.
     * @return array{critical: array, warning: array, notice: array}
     */
    public static function get_expiring_documents_report( int $days = 30, ?int $department_id = null ): array {
        global $wpdb;

        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $dep_table = $wpdb->prefix . 'sfs_hr_departments';

        $today    = current_time( 'Y-m-d' );
        $end_date = gmdate( 'Y-m-d', strtotime( "+{$days} days", strtotime( $today ) ) );

        $where_dept = '';
        $params     = [ $today, $end_date ];

        if ( $department_id !== null ) {
            $where_dept = 'AND e.department_id = %d';
            $params[]   = $department_id;
        }

        $sql = "SELECT d.id, d.document_type, d.expiry_date, d.employee_id,
                       e.first_name, e.last_name, e.employee_code, e.department_id,
                       dep.name AS department_name
                FROM {$doc_table} d
                INNER JOIN {$emp_table} e ON d.employee_id = e.id
                LEFT JOIN {$dep_table} dep ON e.department_id = dep.id
                WHERE d.status = 'active'
                  AND d.expiry_date >= %s
                  AND d.expiry_date <= %s
                  AND e.status != 'terminated'
                  {$where_dept}
                ORDER BY d.expiry_date ASC
                LIMIT 500";

        $results = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        $doc_types = Documents_Service::get_document_types();
        $tiers     = [ 'critical' => [], 'warning' => [], 'notice' => [] ];

        foreach ( $results as $row ) {
            $days_left = (int) ( ( strtotime( $row->expiry_date ) - strtotime( $today ) ) / DAY_IN_SECONDS );

            $entry = [
                'document_id'   => (int) $row->id,
                'employee_id'   => (int) $row->employee_id,
                'employee_name' => trim( $row->first_name . ' ' . $row->last_name ),
                'employee_code' => $row->employee_code,
                'department'    => $row->department_name ?? '',
                'document_type' => $row->document_type,
                'type_label'    => $doc_types[ $row->document_type ] ?? $row->document_type,
                'expiry_date'   => $row->expiry_date,
                'days_left'     => $days_left,
            ];

            if ( $days_left <= 7 ) {
                $tiers['critical'][] = $entry;
            } elseif ( $days_left <= 15 ) {
                $tiers['warning'][] = $entry;
            } else {
                $tiers['notice'][] = $entry;
            }
        }

        return $tiers;
    }

    /**
     * Get missing required documents report.
     *
     * @param int|null $department_id Optional department filter.
     * @return array List of employees with their missing document types.
     */
    public static function get_missing_documents_report( ?int $department_id = null ): array {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $dep_table = $wpdb->prefix . 'sfs_hr_departments';

        $required_types = Documents_Service::get_required_document_types();
        if ( empty( $required_types ) ) {
            return [];
        }

        $where_dept = '';
        $params     = [];

        if ( $department_id !== null ) {
            $where_dept = 'AND e.department_id = %d';
            $params[]   = $department_id;
        }

        // Get all active employees
        $sql = "SELECT e.id, e.first_name, e.last_name, e.employee_code, dep.name AS department_name
                FROM {$emp_table} e
                LEFT JOIN {$dep_table} dep ON e.department_id = dep.id
                WHERE e.status != 'terminated'
                {$where_dept}
                ORDER BY e.first_name ASC";

        $employees = empty( $params )
            ? $wpdb->get_results( $sql )
            : $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        if ( empty( $employees ) ) {
            return [];
        }

        $employee_ids   = wp_list_pluck( $employees, 'id' );
        $placeholders   = implode( ',', array_fill( 0, count( $employee_ids ), '%d' ) );
        $today          = current_time( 'Y-m-d' );

        // Batch-fetch valid documents
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
        $docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT employee_id, document_type, expiry_date
             FROM {$doc_table}
             WHERE employee_id IN ({$placeholders})
               AND status = 'active'",
            ...$employee_ids
        ) );

        // Build lookup: employee_id => [type => true]
        $emp_valid_docs = [];
        foreach ( $docs as $doc ) {
            if ( empty( $doc->expiry_date ) || $doc->expiry_date >= $today ) {
                $emp_valid_docs[ $doc->employee_id ][ $doc->document_type ] = true;
            }
        }

        $report = [];
        foreach ( $employees as $emp ) {
            $valid   = $emp_valid_docs[ $emp->id ] ?? [];
            $missing = [];

            foreach ( $required_types as $type_key => $label ) {
                if ( empty( $valid[ $type_key ] ) ) {
                    $missing[ $type_key ] = $label;
                }
            }

            if ( ! empty( $missing ) ) {
                $report[] = [
                    'employee_id'   => (int) $emp->id,
                    'employee_name' => trim( $emp->first_name . ' ' . $emp->last_name ),
                    'employee_code' => $emp->employee_code,
                    'department'    => $emp->department_name ?? '',
                    'missing_types' => $missing,
                ];
            }
        }

        return $report;
    }

    /**
     * Get Iqama and visa expiry report (Saudi-specific).
     *
     * @return array{expired: array, expiring_within_30: array, expiring_within_90: array}
     */
    public static function get_iqama_visa_report(): array {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $dep_table = $wpdb->prefix . 'sfs_hr_departments';

        $today  = current_time( 'Y-m-d' );
        $in_30  = gmdate( 'Y-m-d', strtotime( '+30 days', strtotime( $today ) ) );
        $in_90  = gmdate( 'Y-m-d', strtotime( '+90 days', strtotime( $today ) ) );

        $sql = "SELECT e.id, e.first_name, e.last_name, e.employee_code, e.department_id,
                       e.iqama_expiry, e.visa_expiry, dep.name AS department_name
                FROM {$emp_table} e
                LEFT JOIN {$dep_table} dep ON e.department_id = dep.id
                WHERE e.status != 'terminated'
                  AND (
                      (e.iqama_expiry IS NOT NULL AND e.iqama_expiry != '' AND e.iqama_expiry <= %s)
                      OR (e.visa_expiry IS NOT NULL AND e.visa_expiry != '' AND e.visa_expiry <= %s)
                  )
                ORDER BY LEAST(
                    COALESCE(NULLIF(e.iqama_expiry, ''), '9999-12-31'),
                    COALESCE(NULLIF(e.visa_expiry, ''), '9999-12-31')
                ) ASC";

        $results = $wpdb->get_results( $wpdb->prepare( $sql, $in_90, $in_90 ) );

        $tiers = [
            'expired'           => [],
            'expiring_within_30' => [],
            'expiring_within_90' => [],
        ];

        foreach ( $results as $row ) {
            $entry = [
                'employee_id'   => (int) $row->id,
                'employee_name' => trim( $row->first_name . ' ' . $row->last_name ),
                'employee_code' => $row->employee_code,
                'department'    => $row->department_name ?? '',
                'iqama_expiry'  => $row->iqama_expiry ?: null,
                'visa_expiry'   => $row->visa_expiry ?: null,
                'issues'        => [],
            ];

            // Determine issues and tier
            $tier = 'expiring_within_90';

            if ( ! empty( $row->iqama_expiry ) ) {
                if ( $row->iqama_expiry < $today ) {
                    $entry['issues'][] = __( 'Iqama expired', 'sfs-hr' );
                    $tier = 'expired';
                } elseif ( $row->iqama_expiry <= $in_30 ) {
                    $entry['issues'][] = __( 'Iqama expiring within 30 days', 'sfs-hr' );
                    if ( $tier !== 'expired' ) { $tier = 'expiring_within_30'; }
                } elseif ( $row->iqama_expiry <= $in_90 ) {
                    $entry['issues'][] = __( 'Iqama expiring within 90 days', 'sfs-hr' );
                }
            }

            if ( ! empty( $row->visa_expiry ) ) {
                if ( $row->visa_expiry < $today ) {
                    $entry['issues'][] = __( 'Visa expired', 'sfs-hr' );
                    $tier = 'expired';
                } elseif ( $row->visa_expiry <= $in_30 ) {
                    $entry['issues'][] = __( 'Visa expiring within 30 days', 'sfs-hr' );
                    if ( $tier !== 'expired' ) { $tier = 'expiring_within_30'; }
                } elseif ( $row->visa_expiry <= $in_90 ) {
                    $entry['issues'][] = __( 'Visa expiring within 90 days', 'sfs-hr' );
                }
            }

            $tiers[ $tier ][] = $entry;
        }

        return $tiers;
    }

    /**
     * Get compliance overview for a single department.
     *
     * @param int $department_id Department ID.
     * @return array Overview stats plus employee-level compliance list.
     */
    public static function get_department_compliance( int $department_id ): array {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';

        $employees = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, employee_code
             FROM {$emp_table}
             WHERE department_id = %d AND status != 'terminated'",
            $department_id
        ) );

        $total = count( $employees );
        if ( $total === 0 ) {
            return [
                'total_employees'      => 0,
                'fully_compliant'      => 0,
                'partially_compliant'  => 0,
                'non_compliant'        => 0,
                'compliance_percentage' => 0.0,
                'employees'            => [],
            ];
        }

        $required_types = Documents_Service::get_required_document_types();
        $required_count = count( $required_types );

        $employee_ids = wp_list_pluck( $employees, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $employee_ids ), '%d' ) );
        $today        = current_time( 'Y-m-d' );

        // Batch-fetch active docs
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
        $docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT employee_id, document_type, expiry_date
             FROM {$doc_table}
             WHERE employee_id IN ({$placeholders})
               AND status = 'active'",
            ...$employee_ids
        ) );

        $emp_valid_docs = [];
        foreach ( $docs as $doc ) {
            if ( empty( $doc->expiry_date ) || $doc->expiry_date >= $today ) {
                $emp_valid_docs[ $doc->employee_id ][ $doc->document_type ] = true;
            }
        }

        $fully_compliant     = 0;
        $partially_compliant = 0;
        $non_compliant       = 0;
        $employee_list       = [];

        foreach ( $employees as $emp ) {
            $valid   = $emp_valid_docs[ $emp->id ] ?? [];
            $matched = 0;

            if ( $required_count > 0 ) {
                foreach ( $required_types as $type_key => $label ) {
                    if ( ! empty( $valid[ $type_key ] ) ) {
                        $matched++;
                    }
                }
            }

            if ( $required_count === 0 || $matched === $required_count ) {
                $status = 'compliant';
                $fully_compliant++;
            } elseif ( $matched > 0 ) {
                $status = 'partial';
                $partially_compliant++;
            } else {
                $status = 'non_compliant';
                $non_compliant++;
            }

            $employee_list[] = [
                'employee_id'   => (int) $emp->id,
                'employee_name' => trim( $emp->first_name . ' ' . $emp->last_name ),
                'employee_code' => $emp->employee_code,
                'status'        => $status,
                'docs_present'  => $matched,
                'docs_required' => $required_count,
            ];
        }

        return [
            'total_employees'      => $total,
            'fully_compliant'      => $fully_compliant,
            'partially_compliant'  => $partially_compliant,
            'non_compliant'        => $non_compliant,
            'compliance_percentage' => round( ( $fully_compliant / $total ) * 100, 1 ),
            'employees'            => $employee_list,
        ];
    }

    /**
     * Lightweight compliance summary for the admin dashboard widget.
     * Uses COUNT queries to avoid fetching full rows.
     *
     * @return array{total_issues: int, expired_docs_count: int, expiring_soon_count: int, missing_required_count: int, iqama_expiring_count: int}
     */
    public static function get_compliance_summary_for_dashboard(): array {
        global $wpdb;

        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $today = current_time( 'Y-m-d' );
        $in_30 = gmdate( 'Y-m-d', strtotime( '+30 days', strtotime( $today ) ) );

        // Count expired documents for active employees
        $expired_docs_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$doc_table} d
             INNER JOIN {$emp_table} e ON d.employee_id = e.id
             WHERE d.status = 'active'
               AND d.expiry_date < %s
               AND d.expiry_date IS NOT NULL
               AND d.expiry_date != ''
               AND e.status != 'terminated'",
            $today
        ) );

        // Count expiring within 30 days
        $expiring_soon_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$doc_table} d
             INNER JOIN {$emp_table} e ON d.employee_id = e.id
             WHERE d.status = 'active'
               AND d.expiry_date >= %s
               AND d.expiry_date <= %s
               AND e.status != 'terminated'",
            $today,
            $in_30
        ) );

        // Count employees missing required documents
        $required_types = Documents_Service::get_required_document_types();
        $missing_required_count = 0;

        if ( ! empty( $required_types ) ) {
            $report = self::get_missing_documents_report();
            $missing_required_count = count( $report );
        }

        // Count Iqama/Visa expiring within 90 days
        $in_90 = gmdate( 'Y-m-d', strtotime( '+90 days', strtotime( $today ) ) );
        $iqama_expiring_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$emp_table}
             WHERE status != 'terminated'
               AND (
                   (iqama_expiry IS NOT NULL AND iqama_expiry != '' AND iqama_expiry <= %s)
                   OR (visa_expiry IS NOT NULL AND visa_expiry != '' AND visa_expiry <= %s)
               )",
            $in_90,
            $in_90
        ) );

        $total_issues = $expired_docs_count + $expiring_soon_count + $missing_required_count + $iqama_expiring_count;

        return [
            'total_issues'          => $total_issues,
            'expired_docs_count'    => $expired_docs_count,
            'expiring_soon_count'   => $expiring_soon_count,
            'missing_required_count' => $missing_required_count,
            'iqama_expiring_count'  => $iqama_expiring_count,
        ];
    }

    /**
     * Calculate compliance score for a single employee (0-100).
     *
     * Scoring:
     * - 50% weight: required documents present
     * - 30% weight: no expired documents
     * - 20% weight: no pending issues (expiring within 30 days)
     *
     * @param int $employee_id Employee ID.
     * @return array{score: int, issues: array}
     */
    public static function get_employee_compliance_score( int $employee_id ): array {
        global $wpdb;

        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $today     = current_time( 'Y-m-d' );
        $in_30     = gmdate( 'Y-m-d', strtotime( '+30 days', strtotime( $today ) ) );

        $issues         = [];
        $required_types = Documents_Service::get_required_document_types();
        $required_count = count( $required_types );

        // Get all documents for this employee
        $docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT document_type, expiry_date, status
             FROM {$doc_table}
             WHERE employee_id = %d AND status = 'active'",
            $employee_id
        ) );

        // Build valid docs map
        $valid_docs    = [];
        $expired_count = 0;
        $expiring_count = 0;

        foreach ( $docs as $doc ) {
            if ( ! empty( $doc->expiry_date ) && $doc->expiry_date < $today ) {
                $expired_count++;
                $issues[] = sprintf(
                    __( 'Document "%s" has expired', 'sfs-hr' ),
                    Documents_Service::get_document_types()[ $doc->document_type ] ?? $doc->document_type
                );
            } elseif ( ! empty( $doc->expiry_date ) && $doc->expiry_date <= $in_30 ) {
                $expiring_count++;
                $valid_docs[ $doc->document_type ] = true;
                $issues[] = sprintf(
                    __( 'Document "%s" expiring soon', 'sfs-hr' ),
                    Documents_Service::get_document_types()[ $doc->document_type ] ?? $doc->document_type
                );
            } else {
                $valid_docs[ $doc->document_type ] = true;
            }
        }

        // Score component 1: Required documents (50%)
        $required_score = 50;
        if ( $required_count > 0 ) {
            $present = 0;
            foreach ( $required_types as $type_key => $label ) {
                if ( ! empty( $valid_docs[ $type_key ] ) ) {
                    $present++;
                } else {
                    $issues[] = sprintf(
                        __( 'Required document "%s" is missing', 'sfs-hr' ),
                        $label
                    );
                }
            }
            $required_score = (int) round( ( $present / $required_count ) * 50 );
        }

        // Score component 2: No expired documents (30%)
        $total_docs    = count( $docs );
        $expired_score = 30;
        if ( $total_docs > 0 && $expired_count > 0 ) {
            $expired_score = (int) round( ( 1 - ( $expired_count / $total_docs ) ) * 30 );
        }

        // Score component 3: No expiring documents (20%)
        $expiring_score = 20;
        if ( $total_docs > 0 && $expiring_count > 0 ) {
            $expiring_score = (int) round( ( 1 - ( $expiring_count / $total_docs ) ) * 20 );
        }

        $score = min( 100, max( 0, $required_score + $expired_score + $expiring_score ) );

        return [
            'score'  => $score,
            'issues' => $issues,
        ];
    }

    /**
     * Flag documents that have passed their expiry date.
     * Intended to be called by a daily cron job.
     *
     * Updates status from 'active' to 'expired' where expiry_date < today.
     *
     * @return int Number of documents flagged as expired.
     */
    public static function flag_expired_documents(): int {
        global $wpdb;

        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $today     = current_time( 'Y-m-d' );

        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$doc_table}
             SET status = 'expired'
             WHERE expiry_date < %s
               AND expiry_date IS NOT NULL
               AND expiry_date != ''
               AND status = 'active'",
            $today
        ) );

        return (int) $updated;
    }

    /**
     * Send expiry notification emails for documents expiring within the given window.
     * Tracks sent notifications via a daily transient to avoid duplicates.
     *
     * @param int $days_before Number of days before expiry to notify.
     * @return array{sent: int, errors: int}
     */
    public static function send_expiry_notifications( int $days_before = 30 ): array {
        global $wpdb;

        $doc_table = $wpdb->prefix . 'sfs_hr_employee_documents';
        $emp_table = $wpdb->prefix . 'sfs_hr_employees';

        $today    = current_time( 'Y-m-d' );
        $end_date = gmdate( 'Y-m-d', strtotime( "+{$days_before} days", strtotime( $today ) ) );

        // Get transient of already-notified document IDs (per day)
        $transient_key = 'sfs_hr_doc_expiry_notified_' . $today;
        $notified_ids  = get_transient( $transient_key );
        if ( ! is_array( $notified_ids ) ) {
            $notified_ids = [];
        }

        $docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.id, d.document_type, d.expiry_date, d.employee_id,
                    e.first_name, e.last_name, e.employee_code, e.user_id
             FROM {$doc_table} d
             INNER JOIN {$emp_table} e ON d.employee_id = e.id
             WHERE d.status = 'active'
               AND d.expiry_date >= %s
               AND d.expiry_date <= %s
               AND e.status != 'terminated'
             ORDER BY d.expiry_date ASC",
            $today,
            $end_date
        ) );

        $sent   = 0;
        $errors = 0;

        $doc_types  = Documents_Service::get_document_types();
        $hr_email   = get_option( 'admin_email' );
        $company    = get_option( 'sfs_hr_company_profile', [] );
        if ( ! empty( $company['hr_email'] ) ) {
            $hr_email = $company['hr_email'];
        }

        foreach ( $docs as $doc ) {
            // Skip already-notified
            if ( in_array( (int) $doc->id, $notified_ids, true ) ) {
                continue;
            }

            $employee_name = trim( $doc->first_name . ' ' . $doc->last_name );
            $type_label    = $doc_types[ $doc->document_type ] ?? $doc->document_type;
            $days_left     = (int) ( ( strtotime( $doc->expiry_date ) - strtotime( $today ) ) / DAY_IN_SECONDS );

            $subject = sprintf(
                __( '[HR] Document Expiry Notice: %s - %s', 'sfs-hr' ),
                $type_label,
                $employee_name
            );

            $message = sprintf(
                __( "Hello,\n\nThis is a reminder that the following document is expiring soon:\n\nEmployee: %s (%s)\nDocument: %s\nExpiry Date: %s\nDays Remaining: %d\n\nPlease take action to renew or update this document.\n\nRegards,\nHR System", 'sfs-hr' ),
                $employee_name,
                $doc->employee_code,
                $type_label,
                $doc->expiry_date,
                $days_left
            );

            $recipients = [ $hr_email ];

            // Send to employee if they have a WP user
            if ( ! empty( $doc->user_id ) ) {
                $user = get_userdata( (int) $doc->user_id );
                if ( $user && ! empty( $user->user_email ) ) {
                    $recipients[] = $user->user_email;
                }
            }

            $recipients = array_unique( $recipients );
            $success    = wp_mail( $recipients, $subject, $message );

            if ( $success ) {
                $sent++;
                $notified_ids[] = (int) $doc->id;
            } else {
                $errors++;
            }
        }

        // Store notified IDs for today (expire at end of day)
        set_transient( $transient_key, $notified_ids, DAY_IN_SECONDS );

        return [
            'sent'   => $sent,
            'errors' => $errors,
        ];
    }
}
