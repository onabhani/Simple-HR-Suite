<?php
namespace SFS\HR\Modules\Workforce_Status\Admin;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin_Pages
 * Workforce Status dashboard (read-only)
 * Version: 0.1.5-workforce-v1.1
 * Author: Omar Alnabhani (hdqah.com)
 */
class Admin_Pages {

    // ----- Risk config (v1.1) -----
    private const RISK_LOOKBACK_DAYS       = 30; // days including today
    private const RISK_LATE_MIN_DAYS       = 5;  // "High lateness"
    private const RISK_LOW_PRES_MIN_DAYS   = 5;  // "Low presence"
    private const RISK_LEAVE_MIN_DAYS      = 5;  // "Frequent leave"

    public function hooks(): void {
        add_action( 'admin_menu', [ $this, 'menu' ], 25 );
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __( 'Workforce Status', 'sfs-hr' ),
            __( 'Workforce Status', 'sfs-hr' ),
            'sfs_hr_attendance_view_team',
            'sfs-hr-workforce-status',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        Helpers::require_cap( 'sfs_hr_attendance_view_team' );

  echo '<div class="wrap sfs-hr-wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'Workforce Status', 'sfs-hr' ) . '</h1>';
    Helpers::render_admin_nav();
    echo '<hr class="wp-header-end" />';


        $tabs = $this->get_status_tabs();

        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'clocked_in';
        if ( ! isset( $tabs[ $current_tab ] ) ) {
            $current_tab = 'clocked_in';
        }

        $page_num  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $per_page  = isset( $_GET['per_page'] ) ? max( 5, (int) $_GET['per_page'] ) : 20;
        $search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $dept_val  = isset( $_GET['dept'] ) ? (int) $_GET['dept'] : 0;

        // ----- Department scoping -----
        $allowed_depts = null;
        $can_see_all   = current_user_can( 'sfs_hr.manage' );

        if ( ! $can_see_all ) {
            $allowed_depts = $this->manager_dept_ids_for_current_user();

            if ( empty( $allowed_depts ) ) {
                echo '<div class="wrap" id="sfs-hr-workforce-status-wrap">';
                $this->output_inline_styles();
                echo '<h1 class="wp-heading-inline">' . esc_html__( 'Workforce Status', 'sfs-hr' ) . '</h1>';
                echo '<p>' . esc_html__( 'No departments assigned to you.', 'sfs-hr' ) . '</p>';
                echo '</div>';
                return;
            }
        }

        $dept_map     = $this->get_department_map( $allowed_depts );
        $dept_options = [ 0 => __( 'All departments', 'sfs-hr' ) ] + $dept_map;

        // Today in local time (punch/leave helpers convert as needed)
        $today = wp_date( 'Y-m-d' );

        list( $all_rows, $counts ) = $this->get_all_rows(
            [
                'allowed_depts' => $allowed_depts,
                'dept_filter'   => $dept_val,
                'search'        => $search,
                'today_date'    => $today,
            ],
            $dept_map
        );

        $rows_for_tab = array_values(
            array_filter(
                $all_rows,
                static function ( array $row ) use ( $current_tab ) {
                    return $row['status_key'] === $current_tab;
                }
            )
        );

        $total_for_tab = count( $rows_for_tab );
        $pages         = max( 1, (int) ceil( $total_for_tab / $per_page ) );
        $page_num      = min( $page_num, $pages );
        $offset        = max( 0, ( $page_num - 1 ) * $per_page );
        $rows_page     = array_slice( $rows_for_tab, $offset, $per_page );

        $base_args = [
            'page'     => 'sfs-hr-workforce-status',
            'dept'     => $dept_val ?: 0,
            's'        => $search !== '' ? $search : null,
            'per_page' => $per_page,
        ];
        ?>
        <div class="wrap" id="sfs-hr-workforce-status-wrap">
            <?php $this->output_inline_styles(); ?>

            <h1 class="wp-heading-inline"><?php echo esc_html__( 'Workforce Status', 'sfs-hr' ); ?></h1>
            <hr class="wp-header-end" />

            <?php $this->render_filters( $dept_options, $dept_val, $search, $current_tab, $per_page ); ?>
            <?php $this->render_tabs( $tabs, $current_tab, $counts, $base_args ); ?>
            <?php $this->render_table( $rows_page, $total_for_tab, $page_num, $pages, $per_page, $current_tab, $base_args ); ?>
        </div>
        <?php
    }

    /**
     * Simple inline CSS for pills + compact table.
     */
    protected function output_inline_styles(): void {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;
        ?>
        <style>
            #sfs-hr-workforce-status-wrap .sfs-hr-workforce-table th,
            #sfs-hr-workforce-status-wrap .sfs-hr-workforce-table td{
                padding:4px 8px;
                font-size:12px;
                vertical-align:middle;
            }
            #sfs-hr-workforce-status-wrap .sfs-hr-pill{
                display:inline-block;
                padding:2px 8px;
                border-radius:999px;
                font-size:11px;
                font-weight:500;
                line-height:1.6;
                border:1px solid rgba(0,0,0,.05);
            }
            #sfs-hr-workforce-status-wrap .sfs-hr-pill--status-in{
                background:#46b4501a;
                color:#008a20;
                border-color:#46b4504d;
            }
            #sfs-hr-workforce-status-wrap .sfs-hr-pill--status-break{
                background:#ffb9001a;
                color:#aa7a00;
                border-color:#ffb90066;
            }
            #sfs-hr-workforce-status-wrap .sfs-hr-pill--status-out{
                background:#ccd0d41a;
                color:#555d66;
                border-color:#ccd0d4;
            }
            #sfs-hr-workforce-status-wrap .sfs-hr-pill--status-notin{
                background:#f1f1f1;
                color:#777;
                border-color:#e2e4e7;
            }
            #sfs-hr-workforce-status-wrap .sfs-hr-pill--leave-duty{
                background:#46b4500f;
                color:#008a20;
                border-color:#46b45040;
            }
            #sfs-hr-workforce-status-wrap .sfs-hr-pill--leave-on{
                background:#0073aa14;
                color:#005177;
                border-color:#0073aa40;
            }
            #sfs-hr-workforce-status-wrap .sfs-hr-workforce-table code{
                font-size:11px;
                opacity:.8;
            }
        </style>
        <?php
    }

    protected function get_status_tabs(): array {
        return [
            'clocked_in'     => [ 'label' => __( 'Clocked in', 'sfs-hr' ) ],
            'on_break'       => [ 'label' => __( 'On break', 'sfs-hr' ) ],
            'clocked_out'    => [ 'label' => __( 'Clocked out', 'sfs-hr' ) ],
            'not_clocked_in' => [ 'label' => __( 'Not Clocked-IN', 'sfs-hr' ) ],
            'on_leave'       => [ 'label' => __( 'On leave', 'sfs-hr' ) ],
        ];
    }

    protected function render_filters( array $dept_options, int $dept_val, string $search, string $current_tab, int $per_page ): void {
        ?>
        <form method="get" style="margin: 10px 0 15px;">
            <input type="hidden" name="page" value="sfs-hr-workforce-status" />
            <input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>" />

            <label for="sfs-hr-wfs-dept" class="screen-reader-text"><?php esc_html_e( 'Department', 'sfs-hr' ); ?></label>
            <select name="dept" id="sfs-hr-wfs-dept">
                <?php foreach ( $dept_options as $id => $label ) : ?>
                    <option value="<?php echo (int) $id; ?>" <?php selected( $dept_val, $id ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="sfs-hr-wfs-search" class="screen-reader-text"><?php esc_html_e( 'Search employees', 'sfs-hr' ); ?></label>
            <input type="search"
                   id="sfs-hr-wfs-search"
                   name="s"
                   value="<?php echo esc_attr( $search ); ?>"
                   placeholder="<?php echo esc_attr__( 'Search name/code/email', 'sfs-hr' ); ?>"
                   style="min-width:220px;" />

            <select name="per_page">
                <?php foreach ( [ 10, 20, 50, 100 ] as $pp ) : ?>
                    <option value="<?php echo (int) $pp; ?>" <?php selected( $per_page, $pp ); ?>>
                        <?php echo (int) $pp; ?>/<?php esc_html_e( 'page', 'sfs-hr' ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php submit_button( __( 'Filter', 'sfs-hr' ), 'secondary', '', false ); ?>
        </form>
        <?php
    }

    protected function render_tabs( array $tabs, string $current_tab, array $counts, array $base_args ): void {
        echo '<h2 class="nav-tab-wrapper" style="margin-bottom:10px;">';

        foreach ( $tabs as $key => $meta ) {
            $count   = isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0;
            $label   = sprintf( '%s (%d)', $meta['label'], $count );
            $args    = array_merge( $base_args, [ 'tab' => $key, 'paged' => 1 ] );
            $url     = add_query_arg( array_filter( $args, static function ( $v ) { return $v !== null; } ) );
            $classes = 'nav-tab' . ( $key === $current_tab ? ' nav-tab-active' : '' );

            echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $classes ) . '">';
            echo esc_html( $label );
            echo '</a>';
        }

        echo '</h2>';
    }

    protected function render_table(
        array $rows,
        int $total,
        int $page_num,
        int $pages,
        int $per_page,
        string $current_tab,
        array $base_args
    ): void {

        $tabs      = $this->get_status_tabs();
        $tab_label = $tabs[ $current_tab ]['label'] ?? '';

        ?>
        <h2 class="title" style="margin-top:5px;"><?php echo esc_html( $tab_label ); ?></h2>

        <table class="widefat striped sfs-hr-workforce-table">
            <thead>
            <tr>
                <th><?php esc_html_e( 'Employee', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Department', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Since', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Leave', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Last punch', 'sfs-hr' ); ?></th>
                <th><?php esc_html_e( 'Risk', 'sfs-hr' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr>
                    <td colspan="7">
                        <?php esc_html_e( 'No employees match the selected filters for today.', 'sfs-hr' ); ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $rows as $r ) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( $this->get_employee_edit_url( $r['employee_id'] ) ); ?>">
                                <?php echo esc_html( $r['employee_name'] ); ?>
                            </a>
                            <?php if ( ! empty( $r['employee_code'] ) ) : ?>
                                <br /><code><?php echo esc_html( $r['employee_code'] ); ?></code>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $r['department'] ); ?></td>
                        <td><?php echo $this->render_status_badge( $r['status_key'], $r['status_label'] ); ?></td>
                        <td><?php echo esc_html( $this->format_time( $r['since'] ) ); ?></td>
                        <td><?php echo $this->render_leave_badge( $r['leave_label'] ); ?></td>
                        <td><?php echo esc_html( $this->format_time( $r['last_punch'] ) ); ?></td>
                        <td><?php echo esc_html( $r['risk_flag'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ( $pages > 1 ) {
            $args = array_merge(
                $base_args,
                [
                    'tab'   => $current_tab,
                    'paged' => '%#%',
                ]
            );

            $page_links = paginate_links(
                [
                    'base'      => add_query_arg( array_filter( $args, static function ( $v ) { return $v !== null; } ) ),
                    'format'    => '',
                    'current'   => $page_num,
                    'total'     => $pages,
                    'mid_size'  => 1,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'type'      => 'plain',
                ]
            );

            if ( $page_links ) {
                echo '<div class="tablenav"><div class="tablenav-pages">' . $page_links . '</div></div>';
            }
        }
    }

    /**
     * Status pill.
     */
    protected function render_status_badge( string $status_key, string $label ): string {
    switch ( $status_key ) {
        case 'clocked_in':
            $class = 'sfs-hr-pill--status-in';
            break;
        case 'on_break':
            $class = 'sfs-hr-pill--status-break';
            break;
        case 'clocked_out':
            $class = 'sfs-hr-pill--status-out';
            break;
        case 'on_leave':
            // reuse the leave-on color so it's visually consistent
            $class = 'sfs-hr-pill--leave-on';
            break;
        case 'not_clocked_in':
        default:
            $class = 'sfs-hr-pill--status-notin';
            break;
    }

    return '<span class="sfs-hr-pill ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
}


    /**
     * Leave pill.
     * "On duty" → greenish, "On leave (...)" → bluish.
     */
    protected function render_leave_badge( string $label ): string {
        $is_on_leave = ( stripos( $label, 'On leave' ) === 0 ); // text prefix is stable

        $class = $is_on_leave ? 'sfs-hr-pill--leave-on' : 'sfs-hr-pill--leave-duty';

        return '<span class="sfs-hr-pill ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
    }

    /**
     * Active departments map [id => name], scoped if needed.
     */
    protected function get_department_map( ?array $allowed_depts ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_departments';

        $where  = 'active = 1';
        $params = [];

        if ( is_array( $allowed_depts ) ) {
            if ( empty( $allowed_depts ) ) {
                return [];
            }
            $placeholders = implode( ',', array_fill( 0, count( $allowed_depts ), '%d' ) );
            $where       .= " AND id IN ($placeholders)";
            $params       = array_map( 'intval', $allowed_depts );
        }

        $sql  = "SELECT id, name FROM {$table} WHERE {$where} ORDER BY name ASC";
        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        $map = [];
        foreach ( $rows as $r ) {
            $map[ (int) $r['id'] ] = $r['name'];
        }
        return $map;
    }

    /**
     * Load scoped employees + today's snapshot + today's leave + risk flags.
     * Returns [ rows[], counts_by_status[] ].
     */
    protected function get_all_rows( array $args, array $dept_map ): array {
        global $wpdb;

        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $where  = "status = 'active' AND dept_id IS NOT NULL";
        $params = [];

        if ( is_array( $args['allowed_depts'] ) ) {
            if ( empty( $args['allowed_depts'] ) ) {
                return [ [], $this->empty_counts() ];
            }
            $placeholders = implode( ',', array_fill( 0, count( $args['allowed_depts'] ), '%d' ) );
            $where       .= " AND dept_id IN ($placeholders)";
            $params       = array_merge( $params, array_map( 'intval', $args['allowed_depts'] ) );
        }

        if ( ! empty( $args['dept_filter'] ) ) {
            $where   .= ' AND dept_id = %d';
            $params[] = (int) $args['dept_filter'];
        }

        if ( $args['search'] !== '' ) {
            $like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where  .= ' AND (employee_code LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)';
            $params = array_merge( $params, [ $like, $like, $like, $like ] );
        }

        $sql = "SELECT id, employee_code, first_name, last_name, email, dept_id
                FROM {$emp_t}
                WHERE {$where}
                ORDER BY first_name ASC, last_name ASC, id ASC";

        $employees = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        if ( empty( $employees ) ) {
            return [ [], $this->empty_counts() ];
        }

        $emp_ids = array_map( 'intval', wp_list_pluck( $employees, 'id' ) );

        $punch_map = $this->get_today_punch_map( $emp_ids, $args['today_date'] );
        $leave_map = $this->get_today_leave_map( $emp_ids, $args['today_date'] );
        $risk_map  = $this->get_risk_flags_map( $emp_ids, $args['today_date'] );

        $tabs   = $this->get_status_tabs();
        $counts = $this->empty_counts();
        $rows   = [];

        foreach ( $employees as $e ) {
            $emp_id = (int) $e['id'];

$last   = $punch_map[ $emp_id ] ?? null;
$since  = null;
$last_p = null;

if ( $last ) {
    $status_key = $this->compute_status_from_punch( $last['punch_type'] );
    $since      = $last['punch_time'];
    $last_p     = $last['punch_time'];
} else {
    $status_key = 'not_clocked_in';
}

// Leave label for today
$leave_label = $leave_map[ $emp_id ] ?? __( 'On duty', 'sfs-hr' );

// If on leave today → force into "on_leave" tab
if ( isset( $leave_map[ $emp_id ] ) ) {
    $status_key = 'on_leave';
}

if ( ! isset( $tabs[ $status_key ] ) ) {
    $status_key = 'not_clocked_in';
}

$counts[ $status_key ]++;

$dept_id   = (int) $e['dept_id'];
$dept_name = $dept_map[ $dept_id ] ?? ( '#' . $dept_id );
$risk_flag = $risk_map[ $emp_id ] ?? '';


            $name = trim( ( $e['first_name'] ?? '' ) . ' ' . ( $e['last_name'] ?? '' ) );
            if ( $name === '' ) {
                $name = '#' . $emp_id;
            }

            $rows[] = [
                'employee_id'   => $emp_id,
                'employee_name' => $name,
                'employee_code' => $e['employee_code'],
                'department'    => $dept_name,
                'dept_id'       => $dept_id,
                'status_key'    => $status_key,
                'status_label'  => $tabs[ $status_key ]['label'],
                'since'         => $since,
                'leave_label'   => $leave_label,
                'last_punch'    => $last_p,
                'risk_flag'     => $risk_flag,
            ];
        }

        return [ $rows, $counts ];
    }

    /**
     * Risk flags per employee for the last N days, based on sessions table.
     */
    protected function get_risk_flags_map( array $emp_ids, string $today_ymd ): array {
        if ( empty( $emp_ids ) ) {
            return [];
        }

        global $wpdb;
        $sT = $wpdb->prefix . 'sfs_hr_attendance_sessions';

        // Date window
        $end   = $today_ymd;
        $start_ts = strtotime( $today_ymd . ' -' . ( self::RISK_LOOKBACK_DAYS - 1 ) . ' days' );
        $start = date( 'Y-m-d', $start_ts ?: time() );

        $placeholders = implode( ',', array_fill( 0, count( $emp_ids ), '%d' ) );
        $params       = array_merge( $emp_ids, [ $start, $end ] );

        $sql = "SELECT employee_id, status
                FROM {$sT}
                WHERE employee_id IN ($placeholders)
                  AND work_date BETWEEN %s AND %s";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        if ( ! $rows ) {
            return [];
        }

        $stats = [];
        foreach ( $rows as $r ) {
            $eid    = (int) $r['employee_id'];
            $status = (string) $r['status'];

            if ( ! isset( $stats[ $eid ] ) ) {
                $stats[ $eid ] = [
                    'late'        => 0,
                    'presenceBad' => 0,
                    'leave'       => 0,
                ];
            }

            switch ( $status ) {
                case 'late':
                    $stats[ $eid ]['late']++;
                    break;
                case 'absent':
                case 'incomplete':
                case 'left_early':
                    $stats[ $eid ]['presenceBad']++;
                    break;
                case 'on_leave':
                    $stats[ $eid ]['leave']++;
                    break;
                default:
                    // present / holiday / others → ignored for risk
                    break;
            }
        }

        $out = [];
        foreach ( $stats as $eid => $s ) {
            $flags = [];

            if ( $s['late'] >= self::RISK_LATE_MIN_DAYS ) {
                $flags[] = __( 'High lateness', 'sfs-hr' );
            }
            if ( $s['presenceBad'] >= self::RISK_LOW_PRES_MIN_DAYS ) {
                $flags[] = __( 'Low presence', 'sfs-hr' );
            }
            if ( $s['leave'] >= self::RISK_LEAVE_MIN_DAYS ) {
                $flags[] = __( 'Frequent leave', 'sfs-hr' );
            }

            $out[ $eid ] = implode( ', ', $flags );
        }

        return $out;
    }

    /**
     * Punches are stored UTC. Convert local "today" into UTC window and
     * get last punch per employee inside that window.
     */
    protected function get_today_punch_map( array $emp_ids, string $today_ymd ): array {
        if ( empty( $emp_ids ) ) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_attendance_punches';

        $tz          = wp_timezone();
        $start_local = new \DateTimeImmutable( $today_ymd . ' 00:00:00', $tz );
        $end_local   = $start_local->modify( '+1 day' );
        $start_utc   = $start_local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $end_utc     = $end_local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

        $placeholders = implode( ',', array_fill( 0, count( $emp_ids ), '%d' ) );
        $params       = array_merge( $emp_ids, [ $start_utc, $end_utc ] );

        $sql = "SELECT employee_id, punch_type, punch_time
                FROM {$table}
                WHERE employee_id IN ($placeholders)
                  AND punch_time >= %s AND punch_time < %s
                ORDER BY punch_time ASC";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        $map = [];
        foreach ( $rows as $r ) {
            $eid         = (int) $r['employee_id'];
            $map[ $eid ] = $r; // last row wins
        }
        return $map;
    }

    protected function get_today_leave_map( array $emp_ids, string $today ): array {
        if ( empty( $emp_ids ) ) {
            return [];
        }

        global $wpdb;
        $req_t  = $wpdb->prefix . 'sfs_hr_leave_requests';
        $type_t = $wpdb->prefix . 'sfs_hr_leave_types';

        $placeholders = implode( ',', array_fill( 0, count( $emp_ids ), '%d' ) );
        $params       = array_merge( $emp_ids, [ $today, $today ] );

        $sql = "SELECT r.employee_id, t.name AS type_name
                FROM {$req_t} r
                JOIN {$type_t} t ON t.id = r.type_id
                WHERE r.status = 'approved'
                  AND r.employee_id IN ($placeholders)
                  AND r.start_date <= %s
                  AND r.end_date >= %s";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        $map = [];
        foreach ( $rows as $r ) {
            $eid   = (int) $r['employee_id'];
            $label = sprintf( __( 'On leave (%s)', 'sfs-hr' ), $r['type_name'] );
            $map[ $eid ] = $label;
        }

        return $map;
    }

    protected function compute_status_from_punch( ?string $punch_type ): string {
        switch ( $punch_type ) {
            case 'break_start':
                return 'on_break';
            case 'in':
            case 'break_end':
                return 'clocked_in';
            case 'out':
                return 'clocked_out';
            default:
                return 'not_clocked_in';
        }
    }

    protected function empty_counts(): array {
        $keys = array_keys( $this->get_status_tabs() );
        $out  = [];
        foreach ( $keys as $k ) {
            $out[ $k ] = 0;
        }
        return $out;
    }

    protected function format_time( ?string $mysql ): string {
    if ( ! $mysql ) {
        return '—';
    }

    try {
        // Punches are stored in UTC – convert to site's local timezone.
        $utc = new \DateTimeImmutable( $mysql, new \DateTimeZone( 'UTC' ) );
    } catch ( \Exception $e ) {
        return '—';
    }

    // wp_date() takes a Unix timestamp (assumed UTC) and outputs in site timezone.
    return wp_date( get_option( 'time_format' ), $utc->getTimestamp() );
}


        protected function get_employee_edit_url( int $employee_id ): string {
        // For now this points to the new Employee Profile page (Phase 2).
        return admin_url(
            add_query_arg(
                [
                    'page'        => 'sfs-hr-employee-profile',
                    'employee_id' => $employee_id,
                ],
                'admin.php'
            )
        );
    }


    /**
     * Dept ids managed by current user (copy of Core\Admin::manager_dept_ids()).
     */
    protected function manager_dept_ids_for_current_user(): array {
        $uid = get_current_user_id();
        if ( ! $uid ) {
            return [];
        }

        global $wpdb;
        $dept_table = $wpdb->prefix . 'sfs_hr_departments';

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$dept_table} WHERE manager_user_id=%d AND active=1",
                $uid
            )
        );

        return array_map( 'intval', $ids ?: [] );
    }
}
