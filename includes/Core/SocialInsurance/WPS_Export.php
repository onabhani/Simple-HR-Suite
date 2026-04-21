<?php
namespace SFS\HR\Core\SocialInsurance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Company_Profile;

/**
 * WPS_Export
 *
 * M8.3 — UAE Wage Protection System (WPS) SIF-file generator.
 *
 * Produces a Salary Information File (SIF) that UAE employers upload
 * to their bank / exchange house for salary disbursal. Format complies
 * with MOHRE specification v1.2 (pipe-delimited, two record types:
 * EDR = Employer Details Record, SCR = Salary Card Records).
 *
 * Saudi Arabia's Mudad platform and Bahrain's LMRA can be added later
 * as additional record formats by extending this class.
 *
 * @since M8
 */
class WPS_Export {

    /**
     * Build the full SIF content for a pay period.
     *
     * @param array  $employees Array of employee arrays with required keys:
     *                          labour_card_number, emirates_id, bank_code, iban,
     *                          fixed_pay, variable_pay, total_salary, leave_days,
     *                          payment_date.
     * @param string $payment_date YYYY-MM-DD payment value date.
     * @return string Full file contents, newline-delimited.
     */
    public static function generate_uae_sif( array $employees, string $payment_date ): string {
        $profile = Company_Profile::get();

        // Employer Details Record (EDR)
        $edr = [
            'EDR',
            self::sanitize( $profile['employer_code'] ?? '' ),       // Employer Unique ID
            self::sanitize( $profile['bank_code'] ?? '' ),           // Employer bank code
            date( 'Ymd', strtotime( $payment_date ) ),               // File creation date
            current_time( 'Hi' ),                                    // File creation time (WP timezone)
            self::sanitize( $profile['currency'] ?? 'AED' ),         // Salary currency
            str_pad( (string) count( $employees ), 6, '0', STR_PAD_LEFT ), // Number of SCRs
            number_format( array_sum( array_column( $employees, 'total_salary' ) ), 2, '.', '' ),
        ];

        $lines   = [ implode( ',', $edr ) ];
        $period  = substr( $payment_date, 0, 7 ); // YYYY-MM
        $pay_ymd = date( 'Ymd', strtotime( $payment_date ) );

        foreach ( $employees as $e ) {
            // Salary Card Record (SCR)
            $lines[] = implode( ',', [
                'SCR',
                self::sanitize( $e['labour_card_number'] ?? '' ),    // Labour card / WPS personal ID
                self::sanitize( $e['emirates_id']        ?? '' ),
                self::sanitize( $e['bank_code']          ?? '' ),
                self::sanitize( $e['iban']               ?? '' ),
                $pay_ymd,
                str_replace( '-', '', $period ),                     // Salary YYYYMM
                number_format( (float) ( $e['fixed_pay']     ?? 0 ), 2, '.', '' ),
                number_format( (float) ( $e['variable_pay']  ?? 0 ), 2, '.', '' ),
                number_format( (float) ( $e['total_salary']  ?? 0 ), 2, '.', '' ),
                (int) ( $e['leave_days'] ?? 0 ),
            ] );
        }

        return implode( "\r\n", $lines ) . "\r\n";
    }

    /**
     * Emit a file download response (sets headers, echoes content, exits).
     *
     * The SIF contains payroll PII (Emirates IDs, IBANs, salaries); enforce
     * capability check at the edge as defense-in-depth. Callers MUST still
     * verify nonce and own capability gate before invoking.
     */
    public static function stream_download( string $filename, string $content ): void {
        if ( ! current_user_can( 'sfs_hr.manage' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'sfs-hr' ), '', [ 'response' => 403 ] );
        }

        // Audit trail: log the payroll PII export.
        if ( class_exists( '\\SFS\\HR\\Core\\AuditTrail' ) && method_exists( '\\SFS\\HR\\Core\\AuditTrail', 'log' ) ) {
            \SFS\HR\Core\AuditTrail::log( 'wps_export', [
                'filename' => $filename,
                'bytes'    => strlen( $content ),
                'user_id'  => get_current_user_id(),
            ] );
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=UTF-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
        header( 'Content-Length: ' . strlen( $content ) );
        echo $content; // Raw SIF content; not HTML, no escaping needed.
        exit;
    }

    /**
     * Strip delimiter characters AND defuse spreadsheet formula-injection
     * vectors. The SIF is typically opened by bank operators in Excel,
     * where a cell starting with = + - @ or TAB is interpreted as a
     * formula (CWE-1236).
     */
    private static function sanitize( $value ): string {
        $s = (string) $value;
        $s = str_replace( [ ',', '|', "\r", "\n", "\t" ], ' ', $s );
        $s = trim( $s );
        if ( $s !== '' && in_array( $s[0], [ '=', '+', '-', '@' ], true ) ) {
            $s = "'" . $s;
        }
        return $s;
    }
}
