<?php
namespace SFS\HR\Modules\Payroll\Services;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * BankExportService
 *
 * Generates bank transfer files for payroll runs in three formats:
 *   - WPS  : Saudi Wage Protection System (MOL-compliant)
 *   - SIF  : Standard Interchange Format (generic banking)
 *   - CSV  : Generic bank transfer CSV
 *
 * Also provides a detailed component-level CSV report.
 *
 * Design notes:
 *   - All methods are static; no instantiation required.
 *   - Never throws — errors are returned in the result array under `error`.
 *   - All DB access uses $wpdb->prepare(). No raw interpolation.
 *   - Amounts in WPS are in halalas (SAR × 100, integer, no decimals).
 *   - SIF amounts are in the smallest currency unit (fils/cents × 100).
 *   - Company settings are read from get_option('sfs_hr_company_profile').
 */
class BankExportService {

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generate WPS (Wage Protection System) file content for a payroll run.
     *
     * Saudi MOL format:
     *   Header  : HDR|<MOL_ID>|<BANK_CODE>|<RECORD_COUNT>|<TOTAL_HALALAS>|<FILE_REF>|<YYYYMMDD>
     *   Records : EMP|<IQAMA_OR_ID>|<BANK_CODE>|<ACCOUNT>|<AMOUNT_HALALAS>|01
     *   Trailer : TRL|<RECORD_COUNT>|<TOTAL_HALALAS>
     *
     * @param int   $run_id  Payroll run ID.
     * @param array $options Optional overrides: mol_id, bank_code, bank_account, currency.
     * @return array{success: bool, filename?: string, content?: string, mime?: string, error?: string}
     */
    public static function export_wps( int $run_id, array $options = [] ): array {
        $run = self::get_run( $run_id );
        if ( ! $run ) {
            return [ 'success' => false, 'error' => __( 'Payroll run not found.', 'sfs-hr' ) ];
        }

        $items = self::get_run_items( $run_id );
        if ( empty( $items ) ) {
            return [ 'success' => false, 'error' => __( 'No payroll items found for this run.', 'sfs-hr' ) ];
        }

        $company  = self::get_company_bank_settings();
        $mol_id   = $options['mol_id']   ?? $company['mol_id']   ?? '';
        $bk_code  = $options['bank_code'] ?? $company['bank_code'] ?? '';
        $file_ref = 'PAY' . $run_id . date( 'Ymd' );
        $date_str = date( 'Yyyymd' ); // YYYYMMDD

        // Only include items that haven't already been paid (or include all if explicitly requested).
        $include_paid = ! empty( $options['include_paid'] );
        $payable      = array_filter( $items, static function ( $item ) use ( $include_paid ) {
            if ( $include_paid ) {
                return true;
            }
            return $item->payment_status !== 'paid';
        } );

        if ( empty( $payable ) ) {
            return [ 'success' => false, 'error' => __( 'No unpaid payroll items to export.', 'sfs-hr' ) ];
        }

        $record_count  = count( $payable );
        $total_halalas = 0;
        $lines         = [];

        foreach ( $payable as $item ) {
            $amount_halalas = self::format_wps_amount( (float) $item->net_salary );
            $total_halalas += (int) $amount_halalas;

            // Prefer IBAN if available; fall back to bank_account; last resort: employee_code.
            $account     = ! empty( $item->iban ) ? $item->iban : ( $item->bank_account ?? '' );
            $emp_bank_code = ! empty( $item->bank_name ) ? strtoupper( substr( sanitize_text_field( $item->bank_name ), 0, 10 ) ) : $bk_code;
            // Employee identifier: id_number (Iqama / Saudi ID).
            $emp_id      = ! empty( $item->id_number ) ? $item->id_number : $item->employee_code;

            $lines[] = implode( '|', [
                'EMP',
                $emp_id,
                $emp_bank_code,
                $account,
                $amount_halalas,
                '01', // Payment method: 01 = bank transfer
            ] );
        }

        $header  = implode( '|', [
            'HDR',
            $mol_id,
            $bk_code,
            $record_count,
            $total_halalas,
            $file_ref,
            date( 'Ymd' ),
        ] );
        $trailer = implode( '|', [
            'TRL',
            $record_count,
            $total_halalas,
        ] );

        $content  = $header . "\r\n";
        $content .= implode( "\r\n", $lines ) . "\r\n";
        $content .= $trailer . "\r\n";

        $filename = sprintf( 'WPS_%s_%s.txt', sanitize_file_name( $company['company_name'] ?? 'payroll' ), date( 'Ymd_His' ) );

        return [
            'success'  => true,
            'filename' => $filename,
            'content'  => $content,
            'mime'     => 'text/plain',
        ];
    }

    /**
     * Generate SIF (Standard Interchange Format) file content for a payroll run.
     *
     * Format:
     *   Header : @|<BANK_CODE>|<COMPANY_ACCOUNT>|<YYYYMMDD>
     *   Detail : D|<ACCOUNT_NUMBER>|<AMOUNT_CENTS>|<EMPLOYEE_NAME_MAX30>
     *
     * @param int   $run_id  Payroll run ID.
     * @param array $options Optional overrides: bank_code, bank_account, include_paid.
     * @return array{success: bool, filename?: string, content?: string, mime?: string, error?: string}
     */
    public static function export_sif( int $run_id, array $options = [] ): array {
        $run = self::get_run( $run_id );
        if ( ! $run ) {
            return [ 'success' => false, 'error' => __( 'Payroll run not found.', 'sfs-hr' ) ];
        }

        $items = self::get_run_items( $run_id );
        if ( empty( $items ) ) {
            return [ 'success' => false, 'error' => __( 'No payroll items found for this run.', 'sfs-hr' ) ];
        }

        $company      = self::get_company_bank_settings();
        $bank_code    = $options['bank_code']    ?? $company['bank_code']    ?? '';
        $bank_account = $options['bank_account'] ?? $company['bank_account'] ?? '';

        $include_paid = ! empty( $options['include_paid'] );
        $payable      = array_filter( $items, static function ( $item ) use ( $include_paid ) {
            return $include_paid || $item->payment_status !== 'paid';
        } );

        if ( empty( $payable ) ) {
            return [ 'success' => false, 'error' => __( 'No unpaid payroll items to export.', 'sfs-hr' ) ];
        }

        $header_line = '@|' . $bank_code . '|' . $bank_account . '|' . date( 'Ymd' );
        $lines       = [ $header_line ];

        foreach ( $payable as $item ) {
            // SIF amount: smallest currency unit (halalas / cents × 100).
            $amount_cents = (int) round( (float) $item->net_salary * 100 );
            $full_name    = trim( ( $item->first_name ?? '' ) . ' ' . ( $item->last_name ?? '' ) );
            $emp_name     = substr( $full_name, 0, 30 ); // SIF spec: max 30 chars

            $account = ! empty( $item->iban ) ? $item->iban : ( $item->bank_account ?? '' );

            $lines[] = implode( '|', [
                'D',
                $account,
                $amount_cents,
                $emp_name,
            ] );
        }

        $content  = implode( "\r\n", $lines ) . "\r\n";
        $filename = sprintf( 'SIF_%s_%s.txt', sanitize_file_name( $company['company_name'] ?? 'payroll' ), date( 'Ymd_His' ) );

        return [
            'success'  => true,
            'filename' => $filename,
            'content'  => $content,
            'mime'     => 'text/plain',
        ];
    }

    /**
     * Generate a generic bank transfer CSV for a payroll run.
     *
     * Columns: Employee Code, Employee Name, Bank Name, Account Number, IBAN, Net Salary, Currency
     *
     * @param int   $run_id  Payroll run ID.
     * @param array $options Optional overrides: currency, include_paid, delimiter.
     * @return array{success: bool, filename?: string, content?: string, mime?: string, error?: string}
     */
    public static function export_csv( int $run_id, array $options = [] ): array {
        $run = self::get_run( $run_id );
        if ( ! $run ) {
            return [ 'success' => false, 'error' => __( 'Payroll run not found.', 'sfs-hr' ) ];
        }

        $items = self::get_run_items( $run_id );
        if ( empty( $items ) ) {
            return [ 'success' => false, 'error' => __( 'No payroll items found for this run.', 'sfs-hr' ) ];
        }

        $currency     = $options['currency']     ?? 'SAR';
        $include_paid = ! empty( $options['include_paid'] );
        $delimiter    = $options['delimiter']    ?? ',';

        $payable = array_filter( $items, static function ( $item ) use ( $include_paid ) {
            return $include_paid || $item->payment_status !== 'paid';
        } );

        if ( empty( $payable ) ) {
            return [ 'success' => false, 'error' => __( 'No unpaid payroll items to export.', 'sfs-hr' ) ];
        }

        ob_start();
        $fh = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel compatibility.
        fwrite( $fh, "\xEF\xBB\xBF" );

        fputcsv( $fh, [
            __( 'Employee Code', 'sfs-hr' ),
            __( 'Employee Name', 'sfs-hr' ),
            __( 'Bank Name', 'sfs-hr' ),
            __( 'Account Number', 'sfs-hr' ),
            __( 'IBAN', 'sfs-hr' ),
            __( 'Net Salary', 'sfs-hr' ),
            __( 'Currency', 'sfs-hr' ),
        ], $delimiter );

        foreach ( $payable as $item ) {
            $full_name = trim( ( $item->first_name ?? '' ) . ' ' . ( $item->last_name ?? '' ) );
            fputcsv( $fh, [
                $item->employee_code   ?? '',
                $full_name,
                $item->bank_name       ?? '',
                $item->bank_account    ?? '',
                $item->iban            ?? '',
                number_format( (float) $item->net_salary, 2, '.', '' ),
                $currency,
            ], $delimiter );
        }

        fclose( $fh );
        $content = ob_get_clean();

        $filename = sprintf( 'BankTransfer_%s_%s.csv', sanitize_file_name( $run->period_label ?? $run_id ), date( 'Ymd_His' ) );

        return [
            'success'  => true,
            'filename' => $filename,
            'content'  => $content,
            'mime'     => 'text/csv',
        ];
    }

    /**
     * Generate a detailed payroll report CSV with all salary components per employee.
     *
     * Columns: Employee Code, Employee Name, <dynamic component columns...>,
     *          Gross Salary, Total Deductions, Net Salary, Payment Status
     *
     * @param int $run_id Payroll run ID.
     * @return array{success: bool, filename?: string, content?: string, mime?: string, error?: string}
     */
    public static function export_detailed( int $run_id ): array {
        $run = self::get_run( $run_id );
        if ( ! $run ) {
            return [ 'success' => false, 'error' => __( 'Payroll run not found.', 'sfs-hr' ) ];
        }

        $items = self::get_run_items( $run_id );
        if ( empty( $items ) ) {
            return [ 'success' => false, 'error' => __( 'No payroll items found for this run.', 'sfs-hr' ) ];
        }

        // Collect all component keys across all items to build a unified header.
        $all_component_keys = [];
        $parsed_items       = [];

        foreach ( $items as $item ) {
            $components = [];
            if ( ! empty( $item->components_json ) ) {
                $decoded = json_decode( $item->components_json, true );
                if ( is_array( $decoded ) ) {
                    $components = $decoded;
                }
            }
            $parsed_items[]     = [ 'item' => $item, 'components' => $components ];
            $all_component_keys = array_unique( array_merge( $all_component_keys, array_keys( $components ) ) );
        }

        sort( $all_component_keys );

        ob_start();
        $fh = fopen( 'php://output', 'w' );
        fwrite( $fh, "\xEF\xBB\xBF" );

        // Build header row.
        $header = [
            __( 'Employee Code', 'sfs-hr' ),
            __( 'Employee Name', 'sfs-hr' ),
        ];
        foreach ( $all_component_keys as $key ) {
            $header[] = ucwords( str_replace( '_', ' ', $key ) );
        }
        $header[] = __( 'Gross Salary', 'sfs-hr' );
        $header[] = __( 'Total Deductions', 'sfs-hr' );
        $header[] = __( 'Net Salary', 'sfs-hr' );
        $header[] = __( 'Payment Status', 'sfs-hr' );
        $header[] = __( 'Payment Reference', 'sfs-hr' );

        fputcsv( $fh, $header );

        foreach ( $parsed_items as $entry ) {
            $item       = $entry['item'];
            $components = $entry['components'];
            $full_name  = trim( ( $item->first_name ?? '' ) . ' ' . ( $item->last_name ?? '' ) );

            $row = [
                $item->employee_code ?? '',
                $full_name,
            ];

            foreach ( $all_component_keys as $key ) {
                $row[] = isset( $components[ $key ] )
                    ? number_format( (float) $components[ $key ], 2, '.', '' )
                    : '0.00';
            }

            // Derive gross/deductions from components when not stored separately.
            $gross       = (float) ( $item->gross_salary ?? 0 );
            $deductions  = (float) ( $item->total_deductions ?? 0 );
            $net         = (float) $item->net_salary;

            // If the run item doesn't carry gross/deductions columns, compute from components.
            if ( $gross == 0.0 && ! empty( $components ) ) {
                foreach ( $components as $key => $val ) {
                    if ( strpos( $key, 'deduction' ) !== false || strpos( $key, 'deduct' ) !== false ) {
                        $deductions += (float) $val;
                    } else {
                        $gross += (float) $val;
                    }
                }
            }

            $row[] = number_format( $gross, 2, '.', '' );
            $row[] = number_format( $deductions, 2, '.', '' );
            $row[] = number_format( $net, 2, '.', '' );
            $row[] = $item->payment_status    ?? '';
            $row[] = $item->payment_reference ?? '';

            fputcsv( $fh, $row );
        }

        fclose( $fh );
        $content = ob_get_clean();

        $filename = sprintf( 'PayrollDetail_%s_%s.csv', sanitize_file_name( $run->period_label ?? $run_id ), date( 'Ymd_His' ) );

        return [
            'success'  => true,
            'filename' => $filename,
            'content'  => $content,
            'mime'     => 'text/csv',
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Get company bank settings from plugin options.
     *
     * @return array{company_name: string, mol_id: string, bank_code: string, bank_account: string}
     */
    private static function get_company_bank_settings(): array {
        $profile = get_option( 'sfs_hr_company_profile', [] );
        if ( ! is_array( $profile ) ) {
            $profile = [];
        }

        return [
            'company_name' => (string) ( $profile['company_name'] ?? '' ),
            'mol_id'       => (string) ( $profile['mol_id']       ?? '' ),
            'bank_code'    => (string) ( $profile['bank_code']    ?? '' ),
            'bank_account' => (string) ( $profile['bank_account'] ?? '' ),
        ];
    }

    /**
     * Format a SAR amount as halalas (integer, no decimals) for WPS.
     *
     * @param float $amount Amount in SAR.
     * @return string Integer string representing halalas (SAR × 100).
     */
    private static function format_wps_amount( float $amount ): string {
        return (string) (int) round( $amount * 100 );
    }

    /**
     * Get a single payroll run row.
     *
     * @param int $run_id Payroll run ID.
     * @return object|null
     */
    private static function get_run( int $run_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_payroll_runs';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
                $run_id
            )
        ) ?: null;
    }

    /**
     * Get all payroll items with joined employee details for a payroll run.
     *
     * Joins sfs_hr_payroll_items with sfs_hr_employees to pull banking details
     * and personal info. Employee-level bank fields are used as fallback when
     * the payroll item row does not have them populated.
     *
     * @param int $run_id Payroll run ID.
     * @return object[]
     */
    private static function get_run_items( int $run_id ): array {
        global $wpdb;

        $items_table = $wpdb->prefix . 'sfs_hr_payroll_items';
        $emp_table   = $wpdb->prefix . 'sfs_hr_employees';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    pi.id,
                    pi.employee_id,
                    pi.net_salary,
                    pi.payment_status,
                    pi.payment_reference,
                    pi.components_json,
                    /* Bank fields: prefer payroll item value; fall back to employee record. */
                    COALESCE( NULLIF( pi.bank_name, '' ),    e.bank_name    ) AS bank_name,
                    COALESCE( NULLIF( pi.bank_account, '' ), e.bank_account ) AS bank_account,
                    COALESCE( NULLIF( pi.iban, '' ),         e.iban         ) AS iban,
                    /* Employee identifiers and name. */
                    e.employee_code,
                    e.first_name,
                    e.last_name,
                    e.id_number,
                    e.nationality
                FROM {$items_table} pi
                INNER JOIN {$emp_table} e ON e.id = pi.employee_id
                WHERE pi.run_id = %d
                ORDER BY e.employee_code ASC",
                $run_id
            )
        );

        return $rows ?: [];
    }
}
