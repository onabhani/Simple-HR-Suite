<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shift_Service
 *
 * Shift resolution, weekly/period overrides, and segment building.
 * Extracted from AttendanceModule — the original static methods on
 * AttendanceModule now delegate here for backwards compatibility.
 */
class Shift_Service {

    const OPT_SETTINGS = 'sfs_hr_attendance_settings';

    /**
     * Resolve effective shift for Y-m-d:
     * 1) Explicit date-specific assignment
     * 1.5) Employee-specific default shift (from emp_shifts table)
     * 1.7) Project shift
     * 2) Dept identity for automation
     * 3) Department Automation
     * 4) Fallback by dept_id
     */
    public static function resolve_shift_for_date(
        int $employee_id,
        string $ymd,
        array $settings = [],
        \wpdb $wpdb_in = null,
        bool $include_off_days = false
    ): ?\stdClass {
        $wpdb   = $wpdb_in ?: $GLOBALS['wpdb'];
        $p      = $wpdb->prefix;
        $assignT= "{$p}sfs_hr_attendance_shift_assign";
        $shiftT = "{$p}sfs_hr_attendance_shifts";
        $empT   = "{$p}sfs_hr_employees";

        // --- 1) Assignment
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT sh.*, sa.is_holiday
             FROM {$assignT} sa
             JOIN {$shiftT}  sh ON sh.id = sa.shift_id
             WHERE sa.employee_id=%d AND sa.work_date=%s
             LIMIT 1",
            $employee_id,
            $ymd
        ));
        if ($row) {
            $row->__virtual  = 0;
            // If shift doesn't have dept_id set, derive from employee
            if ( ! isset( $row->dept_id ) || $row->dept_id === null ) {
                $emp = $wpdb->get_row($wpdb->prepare("SELECT dept_id FROM {$empT} WHERE id=%d", $employee_id));
                $row->dept_id = $emp && ! empty( $emp->dept_id ) ? (int) $emp->dept_id : null;
            }
            error_log( sprintf( '[SFS ATT RESOLVE] emp=%d date=%s step=1_assignment shift_id=%d wo=%s', $employee_id, $ymd, $row->id ?? 0, $row->weekly_overrides ?? '(empty)' ) );
            $row = self::apply_weekly_override( $row, $ymd, $wpdb, $include_off_days );
            return self::apply_period_override( $row, $ymd, $include_off_days );
        }

        // --- 1.5) Employee-specific shift (from emp_shifts mapping)
        $emp_shift = self::lookup_emp_shift_for_date( $employee_id, $ymd );
        if ( $emp_shift ) {
            // Get employee dept_id for context
            $emp = $wpdb->get_row($wpdb->prepare("SELECT dept_id FROM {$empT} WHERE id=%d", $employee_id));

            // Set dept_id and other required fields
            $emp_shift->dept_id    = $emp && ! empty( $emp->dept_id ) ? (int) $emp->dept_id : null;
            $emp_shift->__virtual  = 0;
            $emp_shift->is_holiday = 0;

            error_log( sprintf( '[SFS ATT RESOLVE] emp=%d date=%s step=1.5_emp_shift shift_id=%d wo=%s', $employee_id, $ymd, $emp_shift->id ?? 0, $emp_shift->weekly_overrides ?? '(empty)' ) );
            $emp_shift = self::apply_weekly_override( $emp_shift, $ymd, $wpdb, $include_off_days );
            return self::apply_period_override( $emp_shift, $ymd, $include_off_days );
        }

        // --- 1.7) Project shift — if employee is assigned to an active project on this date
        if ( class_exists( '\SFS\HR\Modules\Projects\Services\Projects_Service' ) ) {
            $prj = \SFS\HR\Modules\Projects\Services\Projects_Service::get_employee_project_on_date( $employee_id, $ymd );
            if ( $prj && $prj->default_shift_id ) {
                $psh = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$shiftT} WHERE id = %d AND active = 1 LIMIT 1",
                        $prj->default_shift_id
                    )
                );
                if ( $psh ) {
                    $emp_row      = $wpdb->get_row( $wpdb->prepare( "SELECT dept_id FROM {$empT} WHERE id=%d", $employee_id ) );
                    $psh->dept_id    = $emp_row && ! empty( $emp_row->dept_id ) ? (int) $emp_row->dept_id : null;
                    $psh->__virtual  = 0;
                    $psh->is_holiday = 0;
                    error_log( sprintf( '[SFS ATT RESOLVE] emp=%d date=%s step=1.7_project project=%d shift_id=%d', $employee_id, $ymd, $prj->id, $psh->id ?? 0 ) );
                    $psh = self::apply_weekly_override( $psh, $ymd, $wpdb, $include_off_days );
                    return self::apply_period_override( $psh, $ymd, $include_off_days );
                }
            }
        }

        // --- 2) Dept identity (id, slug, name) for automation
        $emp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$empT} WHERE id=%d", $employee_id));
        if (!$emp) {
            error_log('[SFS ATT] no employee row for id '.$employee_id);
            return null;
        }

        $dept_id = null; $dept_name = null; $dept_slug = null;
        foreach (['dept_id','department_id'] as $c) {
            if (isset($emp->$c) && is_numeric($emp->$c)) {
                $dept_id = (int)$emp->$c;
                break;
            }
        }
        foreach (['dept','department','dept_label'] as $c) {
            if (!empty($emp->$c)) {
                $dept_name = (string)$emp->$c;
                break;
            }
        }
        if ($dept_name) {
            $dept_slug = sanitize_title($dept_name);
        }

        // --- 3) Department Automation
        $auto    = self::load_automation_map_and_keytype(); // ['keytype','map','source']
        $map     = $auto['map'];
        $keytype = $auto['keytype'];

        // Build candidate keys in the order that matches keytype first
        $candidates = [];
        if ($keytype === 'id') {
            if ($dept_id !== null) { $candidates[] = (string)$dept_id; }
            if ($dept_slug)        { $candidates[] = $dept_slug; }
            if ($dept_name)        { $candidates[] = $dept_name; }
        } elseif ($keytype === 'slug') {
            if ($dept_slug)        { $candidates[] = $dept_slug; }
            if ($dept_name)        { $candidates[] = $dept_name; }
            if ($dept_id !== null) { $candidates[] = (string)$dept_id; }
        } else { // 'name'
            if ($dept_name)        { $candidates[] = $dept_name; }
            if ($dept_slug)        { $candidates[] = $dept_slug; }
            if ($dept_id !== null) { $candidates[] = (string)$dept_id; }
        }

        // Try to find config
        $conf = null;
        foreach ($candidates as $k) {
            if (isset($map[$k]) && is_array($map[$k])) {
                $conf = $map[$k];
                break;
            }
        }

        if ($conf) {
            $shift_id = null;
            foreach (['default_shift_id','default','shift','shift_id'] as $k) {
                if (isset($conf[$k]) && is_numeric($conf[$k])) {
                    $shift_id = (int)$conf[$k];
                    break;
                }
            }

            if ($shift_id) {
                $sh = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$shiftT} WHERE id=%d AND active=1 LIMIT 1",
                        $shift_id
                    )
                );
                if ($sh) {
                    $sh->__virtual  = 1;
                    $sh->is_holiday = 0;
                    $sh->dept_id    = $dept_id;
                    error_log( sprintf( '[SFS ATT RESOLVE] emp=%d date=%s step=3_dept_auto shift_id=%d wo=%s', $employee_id, $ymd, $sh->id ?? 0, $sh->weekly_overrides ?? '(empty)' ) );
                    $sh = self::apply_weekly_override( $sh, $ymd, $wpdb, $include_off_days );
                    return self::apply_period_override( $sh, $ymd, $include_off_days );
                }
            }
        }

        // --- 4) Optional fallback by dept_id to keep system usable
        // Check both old dept_id column and new dept_ids JSON array
        if ($dept_id) {
            // First try: match dept_ids JSON array (multi-department support)
            $fb = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$shiftT}
                     WHERE active=1
                       AND (dept_ids IS NOT NULL AND JSON_CONTAINS(dept_ids, %s))
                     ORDER BY id ASC LIMIT 1",
                    (string) $dept_id
                )
            );

            // Fallback: match legacy single dept_id column
            if ( ! $fb ) {
                $fb = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$shiftT} WHERE active=1 AND dept_id=%d ORDER BY id ASC LIMIT 1",
                        $dept_id
                    )
                );
            }

            if ($fb) {
                $fb->__virtual  = 1;
                $fb->is_holiday = 0;
                error_log( sprintf( '[SFS ATT RESOLVE] emp=%d date=%s step=4_fallback shift_id=%d wo=%s', $employee_id, $ymd, $fb->id ?? 0, $fb->weekly_overrides ?? '(empty)' ) );
                $fb = self::apply_weekly_override( $fb, $ymd, $wpdb, $include_off_days );
                return self::apply_period_override( $fb, $ymd, $include_off_days );
            }
        }

        error_log( sprintf( '[SFS ATT RESOLVE] emp=%d date=%s step=none (no shift found)', $employee_id, $ymd ) );
        return null;
    }

    /**
     * Build segments from a resolved shift object.
     * Returns an array of segment arrays with start/end times in UTC and local.
     */
    public static function build_segments_from_shift( ?\stdClass $shift, string $ymd ): array {
        if ( ! $shift || empty( $shift->start_time ) || empty( $shift->end_time ) ) {
            return [];
        }

        // When start_time == end_time (e.g. 00:00–00:00), there are no meaningful
        // shift boundaries.  This is typical for "Total Hours" shifts that only
        // care about the number of worked hours, not fixed start/end times.
        if ( $shift->start_time === $shift->end_time ) {
            return [];
        }

        $tz = wp_timezone();

        // Format start_time and end_time (TIME columns like '09:00:00')
        $start_time = $shift->start_time;
        $end_time   = $shift->end_time;

        // Build local datetime from date + shift times
        $stLocal = new \DateTimeImmutable( $ymd . ' ' . $start_time, $tz );
        $enLocal = new \DateTimeImmutable( $ymd . ' ' . $end_time, $tz );

        // Handle overnight shifts (end_time < start_time means next day)
        if ( $enLocal <= $stLocal ) {
            $enLocal = $enLocal->modify( '+1 day' );
        }

        // Convert to UTC
        $stUTC = $stLocal->setTimezone( new \DateTimeZone( 'UTC' ) );
        $enUTC = $enLocal->setTimezone( new \DateTimeZone( 'UTC' ) );

        return [
            [
                'start_utc' => $stUTC->format( 'Y-m-d H:i:s' ),
                'end_utc'   => $enUTC->format( 'Y-m-d H:i:s' ),
                'start_l'   => $stLocal->format( 'Y-m-d H:i:s' ),
                'end_l'     => $enLocal->format( 'Y-m-d H:i:s' ),
                'minutes'   => (int) round( ( $enUTC->getTimestamp() - $stUTC->getTimestamp() ) / 60 ),
            ],
        ];
    }

    /**
     * Employee-specific default shift (history table).
     *
     * Returns the shift row for the employee that is in effect on $ymd,
     * using the last record with start_date <= $ymd.
     */
    private static function lookup_emp_shift_for_date( int $employee_id, string $ymd ): ?\stdClass {
        global $wpdb;

        $p         = $wpdb->prefix;
        $shifts_t  = "{$p}sfs_hr_attendance_shifts";
        $emp_map_t = "{$p}sfs_hr_attendance_emp_shifts";

        if ( $employee_id <= 0 || $ymd === '' ) {
            return null;
        }

        // Bail quickly if mapping table is not installed yet.
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $emp_map_t));

        if ( ! $table_exists ) {
            return null;
        }

        // Latest mapping whose start_date <= target date.
        // Also fetch schedule_id for rotation support.
        $mapping = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT es.shift_id, es.schedule_id, es.start_date AS mapping_start_date
                 FROM {$emp_map_t} es
                 WHERE es.employee_id = %d
                   AND es.start_date  <= %s
                 ORDER BY es.start_date DESC, es.id DESC
                 LIMIT 1",
                $employee_id,
                $ymd
            )
        );

        if ( ! $mapping ) {
            return null;
        }

        // If a schedule is assigned, resolve rotation to find the correct shift for this date.
        if ( ! empty( $mapping->schedule_id ) ) {
            $resolved = self::resolve_schedule_for_date( (int) $mapping->schedule_id, $ymd, $wpdb );
            if ( $resolved ) {
                if ( ! isset( $resolved->__virtual ) ) {
                    $resolved->__virtual = 0;
                }
                return $resolved;
            }
            // Schedule couldn't resolve (bad data) — fall through to static shift_id.
        }

        // Static shift assignment.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT sh.* FROM {$shifts_t} sh WHERE sh.id = %d AND sh.active = 1 LIMIT 1",
                (int) $mapping->shift_id
            )
        );

        if ( $row instanceof \stdClass ) {
            if ( ! isset( $row->__virtual ) ) {
                $row->__virtual = 0;
            }
            return $row;
        }

        return null;
    }

    /**
     * Resolve a shift schedule rotation for a given date.
     *
     * Calculates which day of the cycle the date falls on using the anchor
     * date, then looks up the shift for that cycle day in the entries JSON.
     *
     * @return \stdClass|null  Shift row, or null if day off / not found.
     */
    private static function resolve_schedule_for_date( int $schedule_id, string $ymd, \wpdb $wpdb = null ): ?\stdClass {
        $wpdb  = $wpdb ?: $GLOBALS['wpdb'];
        $p     = $wpdb->prefix;
        $schedT = "{$p}sfs_hr_attendance_shift_schedules";
        $shiftT = "{$p}sfs_hr_attendance_shifts";

        // Check table exists.
        $tbl_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $schedT));
        if ( ! $tbl_exists ) {
            return null;
        }

        $schedule = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$schedT} WHERE id = %d AND active = 1 LIMIT 1", $schedule_id )
        );
        if ( ! $schedule || empty( $schedule->entries ) || (int) $schedule->cycle_days <= 0 ) {
            return null;
        }

        $entries = json_decode( $schedule->entries, true );
        if ( ! is_array( $entries ) || empty( $entries ) ) {
            return null;
        }

        // Calculate which day of the cycle this date falls on.
        $anchor = new \DateTimeImmutable( $schedule->anchor_date );
        $target = new \DateTimeImmutable( $ymd );
        $diff_days = (int) $anchor->diff( $target )->format( '%r%a' );

        $cycle_days = (int) $schedule->cycle_days;
        // Modulo that handles dates before anchor (wraps correctly).
        $cycle_day = ( ( $diff_days % $cycle_days ) + $cycle_days ) % $cycle_days;
        // Entries use 1-based day numbering.
        $cycle_day_1 = $cycle_day + 1;

        // Find the entry for this cycle day.
        $shift_id = null;
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            if ( (int) ( $entry['day'] ?? 0 ) === $cycle_day_1 ) {
                if ( ! empty( $entry['day_off'] ) ) {
                    return null; // Day off in schedule.
                }
                $shift_id = (int) ( $entry['shift_id'] ?? 0 );
                break;
            }
        }

        if ( ! $shift_id ) {
            return null;
        }

        $shift = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$shiftT} WHERE id = %d AND active = 1 LIMIT 1", $shift_id )
        );

        return $shift ?: null;
    }

    /**
     * Apply weekly overrides to a shift for a given date.
     *
     * Supports two formats in the weekly_overrides JSON:
     *  - Legacy (integer): loads a different shift by ID.
     *  - New per-day schedule:
     *      {"friday": {"start":"08:00:00","end":"14:00:00"}} — override times
     *      {"saturday": null}                                 — day off
     *    When a day key is missing the shift's default times apply.
     */
    private static function apply_weekly_override( ?\stdClass $shift, string $ymd, \wpdb $wpdb = null, bool $include_off_days = false ): ?\stdClass {
        if ( ! $shift ) {
            return $shift;
        }

        if ( empty( $shift->weekly_overrides ) ) {
            return $shift;
        }

        $wpdb = $wpdb ?: $GLOBALS['wpdb'];

        $overrides = json_decode( $shift->weekly_overrides, true );
        if ( ! is_array( $overrides ) || empty( $overrides ) ) {
            return $shift;
        }

        $tz = wp_timezone();
        $date = new \DateTimeImmutable( $ymd . ' 00:00:00', $tz );
        $day_of_week = strtolower( $date->format( 'l' ) );

        if ( ! array_key_exists( $day_of_week, $overrides ) ) {
            return $shift;
        }

        $override_value = $overrides[ $day_of_week ];

        // --- New format: null = day off ---
        if ( $override_value === null ) {
            if ( $include_off_days ) {
                $shift->__off_day = true;
                return $shift;
            }
            return null;
        }

        // --- New format: object with start/end times ---
        if ( is_array( $override_value ) && isset( $override_value['start'], $override_value['end'] ) ) {
            $cloned = clone $shift;
            $cloned->start_time = $override_value['start'];
            $cloned->end_time   = $override_value['end'];
            return $cloned;
        }

        // --- Legacy format: integer shift ID ---
        $override_shift_id = (int) $override_value;
        if ( $override_shift_id <= 0 ) {
            return $shift;
        }

        $shiftT = $wpdb->prefix . 'sfs_hr_attendance_shifts';
        $override_shift = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$shiftT} WHERE id=%d AND active=1 LIMIT 1",
                $override_shift_id
            )
        );

        if ( ! $override_shift ) {
            return $shift;
        }

        $override_shift->__virtual  = $shift->__virtual ?? 0;
        $override_shift->is_holiday = $shift->is_holiday ?? 0;
        $override_shift->dept_id    = $shift->dept_id ?? null;

        return $override_shift;
    }

    /**
     * Apply period overrides to a shift for a given date.
     *
     * Period overrides allow temporary time changes on a shift (e.g., Ramadan hours)
     * without creating a separate shift definition. The override only changes
     * start_time and end_time for dates within the specified range.
     *
     * When `off_days` is present the override will return null (day off) for those
     * weekdays, preventing the system from marking employees as absent on their
     * scheduled rest days (e.g. Friday, Saturday).
     */
    private static function apply_period_override( ?\stdClass $shift, string $ymd, bool $include_off_days = false ): ?\stdClass {
        if ( ! $shift ) {
            return $shift;
        }

        if ( empty( $shift->period_overrides ) ) {
            return $shift;
        }

        $overrides = json_decode( $shift->period_overrides, true );
        if ( ! is_array( $overrides ) || empty( $overrides ) ) {
            return $shift;
        }

        foreach ( $overrides as $ov ) {
            if ( ! is_array( $ov ) ) {
                continue;
            }
            $s = $ov['start_date'] ?? '';
            $e = $ov['end_date']   ?? '';
            $st = $ov['start_time'] ?? '';
            $et = $ov['end_time']   ?? '';

            if ( $s && $e && $st && $et && $ymd >= $s && $ymd <= $e ) {
                // Check if the current day of week is an off day for this override.
                if ( ! empty( $ov['off_days'] ) && is_array( $ov['off_days'] ) ) {
                    $tz = wp_timezone();
                    $date = new \DateTimeImmutable( $ymd . ' 00:00:00', $tz );
                    $day_of_week = strtolower( $date->format( 'l' ) );
                    if ( in_array( $day_of_week, $ov['off_days'], true ) ) {
                        if ( $include_off_days ) {
                            $shift->__off_day = true;
                            return $shift;
                        }
                        return null; // Scheduled day off during this override period.
                    }
                }

                $cloned = clone $shift;
                $cloned->start_time = $st;
                $cloned->end_time   = $et;
                if ( ! empty( $ov['break_start_time'] ) ) {
                    $cloned->break_start_time = $ov['break_start_time'];
                }
                if ( isset( $ov['unpaid_break_minutes'] ) ) {
                    $cloned->unpaid_break_minutes = (int) $ov['unpaid_break_minutes'];
                }
                return $cloned;
            }
        }

        return $shift;
    }

    /** Build split segments for Y-m-d from dept_id + settings (legacy, kept for backwards compatibility). */
    public static function build_segments_for_date_from_dept( $dept_id_or_slug, string $ymd ): array {
        $settings = get_option(self::OPT_SETTINGS) ?: [];
        // Support both dept_id (int) and legacy dept slug (string)
        $key = is_numeric( $dept_id_or_slug ) ? (int) $dept_id_or_slug : (string) $dept_id_or_slug;
        $map = $settings['dept_weekly_segments'][ $key ] ?? null;
        if ( ! $map ) { $map = []; }

        // day-of-week as 'sun'..'sat' using site timezone
        $tz = wp_timezone();
        $d  = new \DateTimeImmutable($ymd.' 00:00:00', $tz);
        $dow = strtolower($d->format('D')); // sun, mon, ...

        $segments = [];
        foreach ((array)($map[$dow] ?? []) as $pair) {
            if (!is_array($pair) || count($pair) < 2) { continue; }
            [$start,$end] = [$pair[0], $pair[1]];
            $stLocal = new \DateTimeImmutable($ymd.' '.$start, $tz);
            $enLocal = new \DateTimeImmutable($ymd.' '.$end,   $tz);
            if ($enLocal <= $stLocal) { $enLocal = $enLocal->modify('+1 day'); }
            $stUTC = $stLocal->setTimezone(new \DateTimeZone('UTC'));
            $enUTC = $enLocal->setTimezone(new \DateTimeZone('UTC'));
            $segments[] = [
                'start_utc' => $stUTC->format('Y-m-d H:i:s'),
                'end_utc'   => $enUTC->format('Y-m-d H:i:s'),
                'start_l'   => $stLocal->format('Y-m-d H:i:s'),
                'end_l'     => $enLocal->format('Y-m-d H:i:s'),
                'minutes'   => (int)round(($enUTC->getTimestamp() - $stUTC->getTimestamp())/60),
            ];
        }
        return $segments;
    }

    /** Load Department → Shift automation, normalized to [deptKey(string) => configArray]. */
    private static function load_automation_map_and_keytype(): array {
        // 1) Dedicated options (legacy & current)
        foreach (['sfs_hr_attendance_automation','sfs_hr_attendance_auto','sfs_hr_attendance_dept_map'] as $optKey) {
            $opt = get_option($optKey);
            if (is_array($opt) && !empty($opt)) {
                // wrapped shape: { department_key_type:'id'|'slug'|'name', map:{...} }
                if (isset($opt['map']) && is_array($opt['map'])) {
                    $keytype = in_array(($opt['department_key_type'] ?? 'id'), ['id','slug','name'], true) ? $opt['department_key_type'] : 'id';
                    $map = [];
                    foreach ($opt['map'] as $k => $v) { $map[(string)$k] = is_array($v) ? $v : []; }
                    return ['keytype'=>$keytype, 'map'=>$map, 'source'=>$optKey];
                }
                // direct map
                $map = [];
                foreach ($opt as $k => $v) { $map[(string)$k] = is_array($v) ? $v : []; }
                return ['keytype'=>'id', 'map'=>$map, 'source'=>$optKey];
            }
        }

        // 2) Nested under main settings
        $settings = get_option(self::OPT_SETTINGS);
        if (is_array($settings)) {
            // (a) Older nested maps
            foreach (['automation','dept_automation','dept_map','attendance_automation'] as $sub) {
                if (!empty($settings[$sub]) && is_array($settings[$sub])) {
                    $map = [];
                    foreach ($settings[$sub] as $k => $v) { $map[(string)$k] = is_array($v) ? $v : []; }
                    return ['keytype'=>'id', 'map'=>$map, 'source'=>self::OPT_SETTINGS.'/'.$sub];
                }
            }
            if (!empty($settings['attendance']) && is_array($settings['attendance'])) {
                foreach (['automation','dept_automation','dept_map'] as $sub) {
                    if (!empty($settings['attendance'][$sub]) && is_array($settings['attendance'][$sub])) {
                        $map = [];
                        foreach ($settings['attendance'][$sub] as $k => $v) { $map[(string)$k] = is_array($v) ? $v : []; }
                        return ['keytype'=>'id', 'map'=>$map, 'source'=>self::OPT_SETTINGS.'/attendance/'.$sub];
                    }
                }
            }

            // (b) **Current UI storage**: dept_defaults
            $defaults = isset($settings['dept_defaults']) && is_array($settings['dept_defaults']) ? $settings['dept_defaults'] : [];

            if (!empty($defaults)) {
                $map = [];
                foreach ($defaults as $dept_id => $shift_id) {
                    $dept_id  = (string)(int)$dept_id;
                    $shift_id = (int)$shift_id;
                    if ($shift_id > 0) {
                        $map[$dept_id]['default_shift_id'] = $shift_id;
                    }
                }
                return ['keytype'=>'id', 'map'=>$map, 'source'=>self::OPT_SETTINGS.'/dept_defaults'];
            }
        }

        return ['keytype'=>'id', 'map'=>[], 'source'=>'(none)'];
    }
}
