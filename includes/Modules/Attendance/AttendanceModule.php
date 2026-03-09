<?php
namespace SFS\HR\Modules\Attendance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AttendanceModule
 * Version: 0.1.2-admin-crud
 * Author: hdqah.com
 *
 * Notes:
 * - Employee mapping: {prefix}sfs_hr_employees.id and .user_id (to wp_users.ID)
 * - Leaves: {prefix}sfs_hr_leave_requests (status='approved', start_date, end_date)
 * - Holidays: option 'sfs_hr_holidays' (array of date ranges)
 */

// Load submodules at file scope (NOT inside the class!)
require_once __DIR__ . '/Services/Period_Service.php';
require_once __DIR__ . '/Services/Shift_Service.php';
require_once __DIR__ . '/Services/Early_Leave_Service.php';
require_once __DIR__ . '/Services/Session_Service.php';
require_once __DIR__ . '/Admin/class-admin-pages.php';
require_once __DIR__ . '/Rest/class-attendance-admin-rest.php';
require_once __DIR__ . '/Rest/class-attendance-rest.php';
require_once __DIR__ . '/Rest/class-early-leave-rest.php';
require_once __DIR__ . '/Cron/Daily_Session_Builder.php';
require_once __DIR__ . '/Frontend/Widget_Shortcode.php';
require_once __DIR__ . '/Frontend/Kiosk_Shortcode.php';
require_once __DIR__ . '/Migration.php';

class AttendanceModule {

    const OPT_SETTINGS = 'sfs_hr_attendance_settings';

    /**
     * @deprecated Delegate to Period_Service. Kept for backwards compatibility.
     */
    public static function get_current_period( string $reference_date = '' ): array {
        return Services\Period_Service::get_current_period( $reference_date );
    }

    /** @deprecated Delegate to Period_Service. */
    public static function get_previous_period( string $reference_date = '' ): array {
        return Services\Period_Service::get_previous_period( $reference_date );
    }

    /** @deprecated Delegate to Period_Service. */
    public static function format_period_label( array $period ): string {
        return Services\Period_Service::format_period_label( $period );
    }

    public function hooks(): void {
        add_action('admin_init', [ $this, 'maybe_install' ]);

        // Deferred recalc hook — fires when a recalc was skipped due to lock contention.
        // Uses a wrapper because recalc_session_for's 3rd param is $wpdb, not $force.
        add_action( 'sfs_hr_deferred_recalc', [ self::class, 'run_deferred_recalc' ], 10, 3 );

        add_shortcode('sfs_hr_kiosk', [ $this, 'shortcode_kiosk' ]);

        add_action('wp_ajax_sfs_hr_att_dbg', [ $this, 'ajax_dbg' ]);
        add_action('wp_ajax_nopriv_sfs_hr_att_dbg', [ $this, 'ajax_dbg' ]);

        // Keep Admin pages on init
        add_action('init', function () {
            ( new \SFS\HR\Modules\Attendance\Admin\Admin_Pages() )->hooks();
        });

        // Register REST routes (Admin + Public) in the right hook
        add_action('rest_api_init', function () {
            // Admin REST
            if (method_exists(\SFS\HR\Modules\Attendance\Rest\Admin_REST::class, 'routes')) {
                \SFS\HR\Modules\Attendance\Rest\Admin_REST::routes();
            } elseif (method_exists(\SFS\HR\Modules\Attendance\Rest\Admin_REST::class, 'register')) {
                // fallback if Admin_REST only has register()
                \SFS\HR\Modules\Attendance\Rest\Admin_REST::register();
            }

            // Public REST — call register() so nocache headers are attached
            \SFS\HR\Modules\Attendance\Rest\Public_REST::register();

            // Early Leave Requests REST
            \SFS\HR\Modules\Attendance\Rest\Early_Leave_Rest::register_routes();
        }, 10);

        add_shortcode('sfs_hr_attendance_widget', [ $this, 'shortcode_widget' ]);

        // Auto-reject early leave requests after 72 hours of no action
        ( new \SFS\HR\Modules\Attendance\Cron\Early_Leave_Auto_Reject() )->hooks();

        // Daily session builder — ensures sessions exist for yesterday/today
        ( new \SFS\HR\Modules\Attendance\Cron\Daily_Session_Builder() )->hooks();

        // Selfie cleanup — deletes attachments older than selfie_retention_days
        ( new \SFS\HR\Modules\Attendance\Cron\Selfie_Cleanup() )->hooks();
    }

    /**
     * Minimal employee widget (shortcode) with nonce + REST calls.
     * Place on a page restricted to logged-in employees.
     */
    public function shortcode_widget(): string {
        return Frontend\Widget_Shortcode::render();
    }

    /**
     * Kiosk Widget
     */
    public function shortcode_kiosk( $atts = [] ): string {
        return Frontend\Kiosk_Shortcode::render( $atts );
    }

    /**
     * Generate reference number for early leave requests
     */
    /** @deprecated Delegate to Early_Leave_Service. */
    public static function generate_early_leave_request_number(): string {
        return Services\Early_Leave_Service::generate_early_leave_request_number();
    }

    /**
     * Create / upgrade tables and initialize caps & defaults.
     */
    public function maybe_install(): void {
        ( new Migration() )->run();
    }

    public function ajax_dbg(): void {
        // Minimal, safe logger
        $msg = isset($_POST['m']) ? wp_unslash($_POST['m']) : '';
        $ctx = isset($_POST['c']) ? wp_unslash($_POST['c']) : '';
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $line = '[SFS ATT DBG] ' . gmdate('c') . " ip={$ip} | " . $msg;
        if ($ctx !== '') { $line .= ' | ' . $ctx; }
        $line .= ' | UA=' . substr($ua, 0, 120);
        error_log($line);
        wp_send_json_success();
    }

    /* ---------------- Core helpers ---------------- */

    /** Resolve employee_id from WP user_id via {prefix}sfs_hr_employees.user_id */
    public static function employee_id_from_user( int $user_id ): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_employees';
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d LIMIT 1",
            $user_id
        ) );
        return $id ? (int) $id : null;
    }

    /** Holidays/Leave guard. */
    public static function is_blocked_by_leave_or_holiday( int $employee_id, string $dateYmd ): bool {
        $blocked = false;

        // Holidays (uses holidays_in_range which handles yearly repeat expansion)
        $holiday_dates = \SFS\HR\Modules\Leave\Services\LeaveCalculationService::holidays_in_range( $dateYmd, $dateYmd );
        if ( ! empty( $holiday_dates ) ) {
            $blocked = true;
        }

        // Leaves
        if ( ! $blocked ) {
            global $wpdb;
            $table = $wpdb->prefix . 'sfs_hr_leave_requests';
            $has = $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$table}
                 WHERE employee_id = %d
                   AND status = 'approved'
                   AND %s BETWEEN start_date AND end_date
                 LIMIT 1",
                $employee_id, $dateYmd
            ) );
            $blocked = (bool) $has;
        }

        return (bool) apply_filters( 'sfs_hr_attendance_is_leave_or_holiday', $blocked, $employee_id, $dateYmd );
    }

    /** Check if employee is on approved leave (excludes company holidays). */
    public static function is_on_approved_leave( int $employee_id, string $dateYmd ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_leave_requests';
        $has = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$table}
             WHERE employee_id = %d
               AND status = 'approved'
               AND %s BETWEEN start_date AND end_date
             LIMIT 1",
            $employee_id, $dateYmd
        ) );
        return (bool) $has;
    }

    /**
     * Check if a date is a company holiday (not employee-specific leave)
     *
     * @param string $dateYmd Date in Y-m-d format
     * @return bool
     */
    public static function is_company_holiday( string $dateYmd ): bool {
        $holiday_dates = \SFS\HR\Modules\Leave\Services\LeaveCalculationService::holidays_in_range( $dateYmd, $dateYmd );
        return ! empty( $holiday_dates );
    }

    /** Local Y-m-d -> [start_utc, end_utc) */
    public static function local_day_window_to_utc(string $ymd): array {
        $tz  = wp_timezone();
        $stL = new \DateTimeImmutable($ymd.' 00:00:00', $tz);
        $enL = $stL->modify('+1 day');
        $stU = $stL->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $enU = $enL->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return [$stU, $enU];
    }

    /** Format a UTC MySQL datetime into site-local using WP formats. */
    public static function fmt_local(?string $utc_mysql): string {
        if (!$utc_mysql) return '';
        $ts = strtotime($utc_mysql.' UTC');
        return wp_date(get_option('date_format').' '.get_option('time_format'), $ts);
    }

    /** @deprecated Delegate to Session_Service. */
    public static function run_deferred_recalc( int $employee_id, string $ymd, bool $force = false ): void {
        Services\Session_Service::run_deferred_recalc( $employee_id, $ymd, $force );
    }

    /** @deprecated Delegate to Session_Service. */
    public static function recalc_session_for( int $employee_id, string $ymd, \wpdb $wpdb = null, bool $force = false ): void {
        Services\Session_Service::recalc_session_for( $employee_id, $ymd, $wpdb, $force );
    }

    /** @deprecated Delegate to Shift_Service. */
    public static function resolve_shift_for_date(
        int $employee_id,
        string $ymd,
        array $settings = [],
        \wpdb $wpdb_in = null
    ): ?\stdClass {
        return Services\Shift_Service::resolve_shift_for_date( $employee_id, $ymd, $settings, $wpdb_in );
    }

    /** @deprecated Delegate to Shift_Service. */
    public static function build_segments_from_shift( ?\stdClass $shift, string $ymd ): array {
        return Services\Shift_Service::build_segments_from_shift( $shift, $ymd );
    }

    /** @deprecated Delegate to Session_Service. */
    public static function rebuild_sessions_for_date_static( string $date ): void {
        Services\Session_Service::rebuild_sessions_for_date_static( $date );
    }

    /** Return selfie mode resolved by precedence. */
    public static function selfie_mode_for( int $employee_id, $dept_id, array $ctx = [] ): string {
        // Global options
        $opt    = get_option( self::OPT_SETTINGS, [] );
        $policy = is_array( $opt ) ? ( $opt['selfie_policy'] ?? [] ) : [];

        $default_mode = $policy['default'] ?? 'optional';
        $dept_modes   = $policy['by_dept_id'] ?? [];
        $emp_modes    = $policy['by_employee'] ?? [];

        $mode = $default_mode;

        $dept_id = (int) $dept_id;
        if ( $dept_id > 0 && ! empty( $dept_modes[ $dept_id ] ) ) {
            $mode = (string) $dept_modes[ $dept_id ];
        }

        if ( ! empty( $emp_modes[ $employee_id ] ) ) {
            $mode = (string) $emp_modes[ $employee_id ];
        }

        if ( ! empty( $ctx['device_id'] ) ) {
            global $wpdb;
            $dT  = $wpdb->prefix . 'sfs_hr_attendance_devices';
            $dev = $wpdb->get_row( $wpdb->prepare(
                "SELECT selfie_mode FROM {$dT} WHERE id=%d AND active=1",
                (int) $ctx['device_id']
            ) );
            if ( $dev && ! empty( $dev->selfie_mode ) && $dev->selfie_mode !== 'inherit' ) {
                $mode = (string) $dev->selfie_mode;
            }
        }

        $shift_requires = ! empty( $ctx['shift_requires'] );
        if ( $shift_requires && $mode !== 'all' ) {
            $mode = 'all';
        }

        if ( ! in_array( $mode, [ 'never', 'optional', 'in_only', 'in_out', 'all' ], true ) ) {
            $mode = 'optional';
        }

        return $mode;
    }

    /* ---------- Dept helpers (safe, backend-only) ---------- */

    /**
     * Internal: cache of employee table columns so we don't hammer SHOW COLUMNS.
     *
     * @return string[] column names
     */
    private static function employee_table_columns(): array {
        static $cols = null;

        if ( $cols !== null ) {
            return $cols;
        }

        global $wpdb;
        $table     = $wpdb->prefix . 'sfs_hr_employees';
        $table_sql = esc_sql( $table );

        // SHOW COLUMNS is safe here; table name is local, not user input.
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_sql}`", 0 );
        if ( ! is_array( $cols ) ) {
            $cols = [];
        }

        return $cols;
    }

    /**
     * Normalize a string "work location" / dept name to a simple slug.
     * Returns sanitized slug from work location string.
     */
    private static function normalize_work_location( string $raw ): string {
        $s = trim( $raw );
        if ( $s === '' ) {
            return '';
        }
        return sanitize_title( $s );
    }

    /**
     * Fetch department info for an employee in a safe/defensive way.
     *
     * Returns:
     * [
     *   'id'   => int|null,   // dept id if available
     *   'name' => string,     // raw dept name/label
     *   'slug' => string,     // normalized slug
     * ]
     */
    public static function employee_department_info( int $employee_id ): array {
        global $wpdb;

        $table     = $wpdb->prefix . 'sfs_hr_employees';
        $table_sql = esc_sql( $table );
        $cols      = self::employee_table_columns();

        // Build a SELECT that only uses existing columns to avoid "Unknown column" errors.
        $select_cols = [ 'id' ];

        if ( in_array( 'dept_id', $cols, true ) ) {
            $select_cols[] = 'dept_id';
        } elseif ( in_array( 'department_id', $cols, true ) ) {
            $select_cols[] = 'department_id';
        }

        if ( in_array( 'dept', $cols, true ) ) {
            $select_cols[] = 'dept';
        } elseif ( in_array( 'department', $cols, true ) ) {
            $select_cols[] = 'department';
        } elseif ( in_array( 'dept_label', $cols, true ) ) {
            $select_cols[] = 'dept_label';
        }

        if ( in_array( 'work_location', $cols, true ) ) {
            $select_cols[] = 'work_location';
        }

        $select_sql = implode( ', ', array_map( 'esc_sql', $select_cols ) );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$select_sql} FROM `{$table_sql}` WHERE id=%d LIMIT 1",
                $employee_id
            )
        );

        if ( ! $row ) {
            return [
                'id'   => null,
                'name' => '',
                'slug' => '',
            ];
        }

        // Dept id
        $dept_id = null;
        if ( isset( $row->dept_id ) && is_numeric( $row->dept_id ) ) {
            $dept_id = (int) $row->dept_id;
        } elseif ( isset( $row->department_id ) && is_numeric( $row->department_id ) ) {
            $dept_id = (int) $row->department_id;
        }

        // Dept name / label
        $dept_name = '';
        if ( isset( $row->dept ) && $row->dept !== '' ) {
            $dept_name = (string) $row->dept;
        } elseif ( isset( $row->department ) && $row->department !== '' ) {
            $dept_name = (string) $row->department;
        } elseif ( isset( $row->dept_label ) && $row->dept_label !== '' ) {
            $dept_name = (string) $row->dept_label;
        }

        // Slug
        $slug = '';
        if ( isset( $row->work_location ) && $row->work_location !== '' ) {
            $slug = self::normalize_work_location( (string) $row->work_location );
        } elseif ( $dept_name !== '' ) {
            $slug = sanitize_title( $dept_name );
        }

        return [
            'id'   => $dept_id,
            'name' => $dept_name,
            'slug' => $slug,
        ];
    }

    /**
     * Simple helper: best-effort dept slug for attendance logic.
     */
    public static function get_employee_dept_for_attendance( int $employee_id ): string {
        $info = self::employee_department_info( $employee_id );
        return $info['slug'];
    }
}
