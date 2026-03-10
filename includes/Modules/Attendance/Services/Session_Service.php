<?php
namespace SFS\HR\Modules\Attendance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Attendance\AttendanceModule;

/**
 * Session_Service
 *
 * Session recalculation logic — the core engine that turns punches into
 * attendance sessions with status, flags, overtime, and early leave detection.
 *
 * Extracted from AttendanceModule — the original static methods on
 * AttendanceModule now delegate here for backwards compatibility.
 */
class Session_Service {

    const OPT_SETTINGS = 'sfs_hr_attendance_settings';

    /**
     * WP-Cron callback for deferred recalculations.
     *
     * Needed because recalc_session_for() has $wpdb as its 3rd parameter,
     * but WP-Cron passes hook args positionally — so the $force boolean
     * would land in the $wpdb slot without this wrapper.
     */
    public static function run_deferred_recalc( int $employee_id, string $ymd, bool $force = false ): void {
        self::recalc_session_for( $employee_id, $ymd, null, $force );
    }

    /**
     * Recalculate the attendance session for an employee on a given date.
     *
     * @param int       $employee_id
     * @param string    $ymd         Work date (Y-m-d).
     * @param \wpdb|null $wpdb
     * @param bool      $force       When true, always recalculate even for historical dates.
     *                               Pass true when triggered by a punch operation (add/edit/delete)
     *                               or ELR approval.  Pass false (default) for bulk/cron rebuilds
     *                               so that already-finalized historical sessions are protected from
     *                               unintentional overwrite when shift config changes.
     */
    public static function recalc_session_for( int $employee_id, string $ymd, \wpdb $wpdb = null, bool $force = false ): void {
    $wpdb = $wpdb ?: $GLOBALS['wpdb'];
    $pT   = $wpdb->prefix . 'sfs_hr_attendance_punches';
    $sT   = $wpdb->prefix . 'sfs_hr_attendance_sessions';

    // Never create sessions for future dates
    if ( $ymd > wp_date( 'Y-m-d' ) ) {
        return;
    }

    // ── Historical data protection ──────────────────────────────────
    if ( ! $force ) {
        $yesterday = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
        if ( $ymd < $yesterday ) {
            $existing_session = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, in_time, status, locked FROM {$sT} WHERE employee_id = %d AND work_date = %s LIMIT 1",
                $employee_id, $ymd
            ) );
            if ( $existing_session && $existing_session->in_time !== null ) {
                return;
            }
        }
    }

    // Acquire per-employee recalc lock to prevent concurrent recalculations
    $recalc_lock = 'sfs_hr_recalc_' . $employee_id . '_' . $ymd;
    $got_lock    = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 3)", $recalc_lock ) );
    if ( ! $got_lock ) {
        if ( ! wp_next_scheduled( 'sfs_hr_deferred_recalc', [ $employee_id, $ymd, $force ] ) ) {
            wp_schedule_single_event( time() + 30, 'sfs_hr_deferred_recalc', [ $employee_id, $ymd, $force ] );
        }
        return;
    }
    try {

    // Leave/Holiday guard
    $is_leave_or_holiday = AttendanceModule::is_blocked_by_leave_or_holiday( $employee_id, $ymd );
    $is_holiday          = AttendanceModule::is_company_holiday( $ymd );

    if ( $is_leave_or_holiday ) {
        if ( $is_holiday ) {
            list( $peek_utc_start, $peek_utc_end ) = AttendanceModule::local_day_window_to_utc( $ymd );
            $has_clock_in = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}sfs_hr_attendance_punches
                 WHERE employee_id = %d AND punch_time >= %s AND punch_time < %s
                   AND punch_type = 'in'
                 LIMIT 1",
                $employee_id, $peek_utc_start, $peek_utc_end
            ) );

            if ( $has_clock_in ) {
                // Employee intentionally worked on the holiday — fall through
            } else {
                $data = [
                    'employee_id'         => $employee_id,
                    'work_date'           => $ymd,
                    'in_time'             => null,
                    'out_time'            => null,
                    'break_minutes'       => 0,
                    'break_delay_minutes' => 0,
                    'no_break_taken'      => 0,
                    'net_minutes'         => 0,
                    'rounded_net_minutes' => 0,
                    'overtime_minutes'    => 0,
                    'status'              => 'holiday',
                    'flags_json'          => wp_json_encode([]),
                    'calc_meta_json'      => wp_json_encode(['reason' => 'blocked_by_leave_or_holiday']),
                    'last_recalc_at'      => current_time('mysql', true),
                ];
                $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$sT} WHERE employee_id=%d AND work_date=%s LIMIT 1", $employee_id, $ymd) );
                if ($exists) {
                    $result = $wpdb->update($sT, $data, ['id' => (int) $exists, 'locked' => 0]);
                    if ( $result === false ) {
                        error_log("[SFS-HR] recalc_session_for: session write failed (holiday) db_error={$wpdb->last_error}");
                    } elseif ( $result === 0 ) {
                        $is_locked = (int) $wpdb->get_var( $wpdb->prepare("SELECT locked FROM {$sT} WHERE id=%d", (int) $exists) );
                        if ( $is_locked ) {
                            error_log("[SFS-HR] recalc_session_for: skipping locked session id={$exists} (holiday)");
                            return;
                        }
                    }
                } else {
                    $result = $wpdb->insert($sT,$data);
                    if ( $result === false ) {
                        error_log("[SFS-HR] recalc_session_for: session insert failed (holiday) db_error={$wpdb->last_error}");
                    }
                }
                return;
            }
        } else {
            $data = [
                'employee_id'         => $employee_id,
                'work_date'           => $ymd,
                'in_time'             => null,
                'out_time'            => null,
                'break_minutes'       => 0,
                'break_delay_minutes' => 0,
                'no_break_taken'      => 0,
                'net_minutes'         => 0,
                'rounded_net_minutes' => 0,
                'overtime_minutes'    => 0,
                'status'              => 'on_leave',
                'flags_json'          => wp_json_encode([]),
                'calc_meta_json'      => wp_json_encode(['reason' => 'blocked_by_leave_or_holiday']),
                'last_recalc_at'      => current_time('mysql', true),
            ];
            $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$sT} WHERE employee_id=%d AND work_date=%s LIMIT 1", $employee_id, $ymd) );
            if ($exists) {
                $result = $wpdb->update($sT, $data, ['id' => (int) $exists, 'locked' => 0]);
                if ( $result === false ) {
                    error_log("[SFS-HR] recalc_session_for: session write failed (on_leave) db_error={$wpdb->last_error}");
                } elseif ( $result === 0 ) {
                    $is_locked = (int) $wpdb->get_var( $wpdb->prepare("SELECT locked FROM {$sT} WHERE id=%d", (int) $exists) );
                    if ( $is_locked ) {
                        error_log("[SFS-HR] recalc_session_for: skipping locked session id={$exists} (on_leave)");
                        return;
                    }
                }
            } else {
                $result = $wpdb->insert($sT,$data);
                if ( $result === false ) {
                    error_log("[SFS-HR] recalc_session_for: session insert failed (on_leave) db_error={$wpdb->last_error}");
                }
            }
            return;
        }
    }

    // Resolve shift using the proper cascade
    $shift = Shift_Service::resolve_shift_for_date($employee_id, $ymd, [], $wpdb);

    // Build segments from resolved shift
    $segments = Shift_Service::build_segments_from_shift($shift, $ymd);

    // Get dept for calc_meta
    $dept = AttendanceModule::get_employee_dept_for_attendance($employee_id, $wpdb);

// Local-day → UTC window
$tz        = wp_timezone();
$dayLocal  = new \DateTimeImmutable($ymd . ' 00:00:00', $tz);
$nextLocal = $dayLocal->modify('+1 day');
$startUtc  = $dayLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$endUtc    = $nextLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

// For overnight shifts, extend the punch window past midnight
if ( ! empty( $segments ) ) {
    $lastSeg = end( $segments );

    $configuredBuffer = $shift->overtime_buffer_minutes ?? null;
    if ( $configuredBuffer !== null ) {
        $bufferMin = (int) $configuredBuffer;
    } else {
        $shiftDurationMin = $lastSeg['minutes'];
        $bufferMin        = min( (int) round( $shiftDurationMin * 0.5 ), 240 ); // max 4 hours
    }
    $segEndTs         = strtotime( $lastSeg['end_utc'] . ' UTC' );
    $extendedEndUtc   = gmdate( 'Y-m-d H:i:s', $segEndTs + $bufferMin * 60 );

    if ( $extendedEndUtc > $endUtc ) {
        $endUtc = $extendedEndUtc;
    }

    // Tighten the window START to (shift_start - 2 hours)
    if ( ! $is_holiday ) {
        $firstSeg        = reset( $segments );
        $segStartUtcTs   = strtotime( $firstSeg['start_utc'] . ' UTC' );
        $bufferSeconds   = 7200; // 2 hours before shift start
        $tightenedStartTs= $segStartUtcTs - $bufferSeconds;
        $midnightUtcTs   = strtotime( $startUtc . ' UTC' );
        if ( $tightenedStartTs > $midnightUtcTs ) {
            $startUtc = gmdate( 'Y-m-d H:i:s', $tightenedStartTs );
        }
    }
}

// Pull all punches for that window
$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT punch_type, punch_time, valid_geo, valid_selfie, source
     FROM {$pT}
     WHERE employee_id = %d
       AND punch_time >= %s AND punch_time < %s
     ORDER BY punch_time ASC",
    $employee_id, $startUtc, $endUtc
) );

$firstIn = null; $lastOut = null;
$leadingOuts = [];
foreach ($rows as $r) {
    if ($r->punch_type === 'in'  && $firstIn === null) $firstIn = $r->punch_time;
    if ($r->punch_type === 'out') {
        if ( $firstIn !== null ) {
            $lastOut = $r->punch_time;
        } else {
            $leadingOuts[] = $r->punch_time;
        }
    }
}

// Retroactively close the previous day's incomplete session when leading OUTs are found
if ( ! empty( $leadingOuts ) ) {
    self::retro_close_previous_session( $employee_id, $ymd, $leadingOuts, $wpdb, $sT );
}

// Strip leading OUTs from $rows
if ( ! empty( $leadingOuts ) ) {
    $rows = array_values( array_filter( $rows, function ( $r ) use ( $leadingOuts ) {
        return ! in_array( $r->punch_time, $leadingOuts, true );
    } ) );
}

    // Grace & rounding
    $settings = get_option(self::OPT_SETTINGS) ?: [];
    $globalGrLate  = (int)($settings['default_grace_late']  ?? 5);
    $globalGrEarly = (int)($settings['default_grace_early'] ?? 5);
    $globalRound   = (string)($settings['default_rounding_rule'] ?? '5');

    $shiftGrLate  = ( $shift && $shift->grace_late_minutes !== null )        ? (int)$shift->grace_late_minutes        : null;
    $shiftGrEarly = ( $shift && $shift->grace_early_leave_minutes !== null ) ? (int)$shift->grace_early_leave_minutes : null;
    $grLate  = $shiftGrLate  !== null ? $shiftGrLate  : $globalGrLate;
    $grEarly = $shiftGrEarly !== null ? $shiftGrEarly : $globalGrEarly;
    $round   = ( $shift && isset($shift->rounding_rule) )             ? (string)$shift->rounding_rule           : $globalRound;
    $roundN  = ($round === 'none') ? 0 : (int)$round;

    // Determine total-hours mode early — needed for dayCap calculation below
    $is_total_hours = Policy_Service::is_total_hours_mode( $employee_id, $shift );

    // For incomplete sessions, cap at shift end rather than midnight
    $dayCapUtcTs = strtotime($endUtc . ' UTC');
    if ( ! empty( $segments ) ) {
        $lastSeg = end( $segments );
        $segEndTs = strtotime( $lastSeg['end_utc'] . ' UTC' );
        if ( $segEndTs > 0 ) {
            $dayCapUtcTs = $segEndTs;
        }
    } elseif ( $is_total_hours && $firstIn ) {
        $th_target = Policy_Service::get_target_hours( $employee_id, $shift );
        $th_cap    = strtotime( $firstIn . ' UTC' ) + (int) ( $th_target * 3600 );
        if ( $th_cap > 0 ) {
            $dayCapUtcTs = $th_cap;
        }
    }

    // Evaluate
    $ev = self::evaluate_segments($segments, $rows, $grLate, $grEarly, $dayCapUtcTs);

    // ---- Break deduction logic ----
    $shift_break_minutes = $shift ? (int) ( $shift->unpaid_break_minutes ?? 0 ) : 0;
    $shift_break_policy  = $shift ? ( $shift->break_policy ?? 'none' ) : 'none';
    $shift_break_start   = $shift ? ( $shift->break_start_time ?? null ) : null;
    $has_mandatory_break = ( $shift_break_policy !== 'none' && $shift_break_minutes > 0 );
    $shift_no_break = ( $shift !== null && $shift_break_policy === 'none' );

    // Detect if all in/out punches came from kiosk
    $is_kiosk_day = false;
    if ( ! empty( $rows ) ) {
        $is_kiosk_day = true;
        foreach ( $rows as $r ) {
            if ( in_array( $r->punch_type, [ 'in', 'out' ], true ) && ( $r->source ?? '' ) !== 'kiosk' ) {
                $is_kiosk_day = false;
                break;
            }
        }
    }

    // Check if employee actually punched break_start/break_end
    $has_break_punches = false;
    foreach ( $rows as $r ) {
        if ( in_array( $r->punch_type, [ 'break_start', 'break_end' ], true ) ) {
            $has_break_punches = true;
            break;
        }
    }

    $break_delay_minutes = 0;
    $no_break_taken      = 0;
    $break_deduction     = 0;

    if ( $has_mandatory_break && count( $rows ) > 0 ) {
        $requires_break_punches = ! in_array( $shift_break_policy, [ 'free', 'auto' ], true );

        if ( $is_kiosk_day || ! $has_break_punches ) {
            $break_deduction = $shift_break_minutes;

            if ( ! $is_kiosk_day && ! $has_break_punches && $requires_break_punches ) {
                $no_break_taken = 1;
            }
        } else {
            $actual_break = (int) $ev['break_total'];

            if ( $actual_break > $shift_break_minutes ) {
                $break_delay_minutes = $actual_break - $shift_break_minutes;
            }
            $break_deduction = $shift_break_minutes + $break_delay_minutes;
        }
    } else {
        $break_deduction = $shift_no_break ? 0 : (int) $ev['break_total'];
    }

    // Net worked time = total worked minus break deduction
    $net = (int) $ev['worked_total'] - $break_deduction;
    $net = max( 0, $net );

    // ---- Total-hours mode ----
    $is_total_hours = Policy_Service::is_total_hours_mode( $employee_id, $shift );
    $policy_break   = Policy_Service::get_break_settings( $employee_id, $shift );

    if ( $is_total_hours && $policy_break['enabled'] && $policy_break['duration_minutes'] > 0 && ! $has_mandatory_break && ! $shift_no_break ) {
        $net = max( 0, $net - $policy_break['duration_minutes'] );
    }

    if ( $is_total_hours && $no_break_taken && ! $policy_break['enabled'] ) {
        $no_break_taken = 0;
    }

    // Compute OT from raw (unrounded) net
    $scheduled = (int)$ev['scheduled_total'];
    $raw_net   = $net;

    // Apply rounding to net worked time
    if ($roundN > 0) $net = (int)round($net / $roundN) * $roundN;

    // OT calculation
    $shift_ot_threshold = ( $shift && $shift->overtime_after_minutes !== null )
        ? (int) $shift->overtime_after_minutes
        : 0;

    $ot = max( 0, $raw_net - $scheduled - $shift_ot_threshold );
    if ( $roundN > 0 ) {
        $ot = (int) round( $ot / $roundN ) * $roundN;
    }

    // Status rollup
    $status = 'present';
    if ( $is_total_hours ) {
        $target_hours   = Policy_Service::get_target_hours( $employee_id, $shift );
        $target_minutes = (int) ( $target_hours * 60 );

        if ( count( $rows ) === 0 ) {
            if ( $shift === null ) {
                $status = $is_holiday ? 'holiday' : 'day_off';
            } else {
                $status = $is_holiday ? 'holiday' : 'absent';
            }
        } elseif ( in_array( 'incomplete', $ev['flags'], true ) ) {
            $status = 'incomplete';
        } elseif ( $net < $target_minutes ) {
            $actually_left_early = false;
            $has_meaningful_end  = (
                $shift
                && ! empty( $shift->end_time )
                && ( ! isset( $shift->start_time ) || $shift->end_time !== $shift->start_time )
            );
            if ( $lastOut && $has_meaningful_end ) {
                $tz_th        = wp_timezone();
                $shift_end_th = new \DateTimeImmutable( $ymd . ' ' . $shift->end_time, $tz_th );
                if ( ! empty( $shift->start_time ) && $shift->end_time < $shift->start_time ) {
                    $shift_end_th = $shift_end_th->modify( '+1 day' );
                }
                $last_out_th  = ( new \DateTimeImmutable( $lastOut, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz_th );
                $actually_left_early = ( $last_out_th < $shift_end_th );
            }
            if ( $actually_left_early || ! $has_meaningful_end ) {
                $status = 'left_early';
                $ev['flags'][] = 'left_early';
            } else {
                $status = 'present';
            }
        } else {
            $status = 'present';
        }

        // In total-hours mode, overtime is hours beyond target + threshold
        $ot = max( 0, $raw_net - $target_minutes - $shift_ot_threshold );
        if ( $roundN > 0 ) {
            $ot = (int) round( $ot / $roundN ) * $roundN;
        }
    } elseif (!$segments || count($segments)===0) {
        $status = $is_holiday ? 'holiday' : 'day_off';
    } elseif (in_array('incomplete', $ev['flags'], true)) {
        $status = 'incomplete';
    } elseif (in_array('missed_segment', $ev['flags'], true) && $net === 0) {
        $status = (count($rows) > 0 && (int)$ev['worked_total'] > 0) ? 'incomplete' : 'absent';
    } elseif (in_array('missed_segment', $ev['flags'], true)) {
        $status = 'present';
    }
    if ( ! $is_total_hours ) {
        if (in_array('left_early',$ev['flags'],true)) {
            $expected_work_min = max( 0, $scheduled - $shift_break_minutes );

            $policy_target_min = 0;
            $eff_policy = Policy_Service::resolve_effective_policy( $employee_id, $shift );
            if ( $eff_policy && ! empty( $eff_policy->target_hours ) ) {
                $policy_target_min = (int) ( (float) $eff_policy->target_hours * 60 );
            }

            $hours_fulfilled = (
                ( $expected_work_min > 0 && $net >= $expected_work_min ) ||
                ( $policy_target_min > 0 && $net >= $policy_target_min )
            );

            if ( $hours_fulfilled ) {
                $ev['flags'] = array_values( array_diff( $ev['flags'], [ 'left_early' ] ) );
            } else {
                $status = ($status==='present' ? 'left_early' : $status);
            }
        }
        if (in_array('late',$ev['flags'],true))       $status = ($status==='present' ? 'late'       : $status);
    }

    // Geo/selfie counters
    $outside_geo = 0; $no_selfie = 0;
    foreach ($rows as $r) {
        if ((int)$r->valid_geo === 0 && ($r->source ?? '') !== 'kiosk') $outside_geo++;
        if ((int)$r->valid_selfie === 0) $no_selfie++;
    }

    $flags = array_values(array_unique($ev['flags']));

    // In total-hours mode, strip segment-based left_early/late flags
    if ( $is_total_hours ) {
        $flags = array_values( array_diff( $flags, [ 'left_early', 'late' ] ) );
        if ( $status === 'left_early' && ! in_array( 'left_early', $flags, true ) ) {
            $flags[] = 'left_early';
        }
        foreach ( $ev['segments'] as &$seg_d ) {
            $seg_d['flags'] = array_values( array_diff( $seg_d['flags'], [ 'late', 'left_early' ] ) );
            $seg_d['late_minutes']  = 0;
            $seg_d['early_minutes'] = 0;
        }
        unset( $seg_d );
    }

    // Holiday overtime: employee worked on a holiday — all worked time is overtime
    if ( $is_holiday && count( $rows ) > 0 ) {
        $ot     = $net;
        $status = 'holiday';
        $flags  = array_values( array_diff( $flags, [ 'late', 'left_early', 'incomplete', 'missed_segment' ] ) );
        $flags[] = 'holiday_work';
    }

    // Suppress left_early when approved early leave request exists
    $approved_el_id = null;
    if ( $status === 'left_early' || in_array( 'left_early', $flags, true ) ) {
        $elr_table = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $approved_el_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$elr_table}
             WHERE employee_id = %d AND request_date = %s AND status = 'approved'
             LIMIT 1",
            $employee_id, $ymd
        ) );
        if ( $approved_el_id ) {
            if ( $status === 'left_early' ) {
                $status = 'present';
            }
            $flags = array_values( array_diff( $flags, [ 'left_early' ] ) );
            if ( ! in_array( 'early_leave', $flags, true ) ) {
                $flags[] = 'early_leave';
            }
        }
    }

    if ($outside_geo > 0) $flags[] = 'outside_geofence';
    if ($no_selfie > 0)   $flags[] = 'no_selfie';
    if ($no_break_taken)          $flags[] = 'no_break_taken';
    if ($break_delay_minutes > 0) $flags[] = 'break_delay';

    $calcMeta = [
        'dept'            => $dept,
        'segments'        => $ev['segments'],
        'scheduled_total' => $scheduled,
        'rounded_rule'    => $round,
        'grace'           => ['late'=>$grLate,'early'=>$grEarly],
        'counters'        => ['outside_geo'=>$outside_geo,'no_selfie'=>$no_selfie],
        'overtime'        => [
            'overtime_after_minutes'  => $shift_ot_threshold,
            'raw_net_before_rounding' => $raw_net,
        ],
    ];

    $calcMeta['break'] = [
        'shift_break_policy'   => $shift_break_policy,
        'shift_break_minutes'  => $shift_break_minutes,
        'has_mandatory_break'  => $has_mandatory_break,
        'shift_no_break'       => $shift_no_break,
        'actual_break_punches' => (int) $ev['break_total'],
        'break_deduction'      => $break_deduction,
        'break_delay'          => $break_delay_minutes,
        'no_break_taken'       => $no_break_taken,
        'is_kiosk_day'         => $is_kiosk_day,
        'worked_total'         => (int) $ev['worked_total'],
        'net_after_break'      => $net,
    ];

    if ( $is_total_hours ) {
        $calcMeta['policy_mode']           = 'total_hours';
        $calcMeta['target_hours']          = Policy_Service::get_target_hours( $employee_id, $shift );
        $calcMeta['target_minutes']        = (int) ( $calcMeta['target_hours'] * 60 );
        $calcMeta['policy_break']          = $policy_break;
        $calcMeta['policy_break_deducted'] = ( $policy_break['enabled'] && $policy_break['duration_minutes'] > 0 && ! $has_mandatory_break && ! $shift_no_break ) ? $policy_break['duration_minutes'] : 0;
    }

    $data = [
        'employee_id'         => $employee_id,
        'work_date'           => $ymd,
        'in_time'             => $firstIn,
        'out_time'            => $lastOut,
        'break_minutes'       => $break_deduction,
        'break_delay_minutes' => $break_delay_minutes,
        'no_break_taken'      => $no_break_taken,
        'net_minutes'         => (int)$ev['worked_total'],
        'rounded_net_minutes' => $net,
        'overtime_minutes'    => $ot,
        'status'              => $status,
        'flags_json'          => wp_json_encode($flags),
        'calc_meta_json'      => wp_json_encode($calcMeta),
        'last_recalc_at'      => current_time('mysql', true),
    ];

    if ( ! empty( $approved_el_id ) ) {
        $data['early_leave_approved']   = 1;
        $data['early_leave_request_id'] = (int) $approved_el_id;
    } else {
        $data['early_leave_approved']   = 0;
        $data['early_leave_request_id'] = null;
    }

    // Persist session
    $existing_flags = [];
    $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$sT} WHERE employee_id=%d AND work_date=%s LIMIT 1", $employee_id, $ymd) );
    if ($exists) {
        $existing_flags_json = $wpdb->get_var( $wpdb->prepare("SELECT flags_json FROM {$sT} WHERE id=%d", (int)$exists) );
        $existing_flags = $existing_flags_json ? (json_decode($existing_flags_json, true) ?: []) : [];
        $result = $wpdb->update($sT, $data, ['id' => (int)$exists, 'locked' => 0]);
        if ( $result === false ) {
            error_log("[SFS-HR] recalc_session_for: session write failed db_error={$wpdb->last_error}");
            return;
        } elseif ( $result === 0 ) {
            $is_locked = (int) $wpdb->get_var( $wpdb->prepare("SELECT locked FROM {$sT} WHERE id=%d", (int)$exists) );
            if ( $is_locked ) {
                error_log("[SFS-HR] recalc_session_for: skipping locked session id={$exists}");
                return;
            }
        }
    } else {
        $result = $wpdb->insert($sT, $data);
        if ( $result === false ) {
            error_log("[SFS-HR] recalc_session_for: session insert failed db_error={$wpdb->last_error}");
            return;
        }
    }

    $session_id = $exists ? (int) $exists : (int) $wpdb->insert_id;

    // Atomic back-link: set session_id on approved ELR only if still NULL
    if ( ! empty( $approved_el_id ) && $session_id ) {
        $bl_result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$elr_table} SET session_id = %d WHERE id = %d AND session_id IS NULL",
            $session_id,
            (int) $approved_el_id
        ) );
        if ( $bl_result === false ) {
            error_log("[SFS-HR] recalc_session_for: ELR back-link failed elr_id={$approved_el_id} db_error={$wpdb->last_error}");
        }
    }

    // Fire notification hooks for late arrival and early leave
    $was_late = in_array('late', $existing_flags, true);
    $was_early = in_array('left_early', $existing_flags, true);
    $is_late = in_array('late', $flags, true);
    $is_early = in_array('left_early', $flags, true);

    if ($is_late && !$was_late) {
        $minutes_late = 0;
        foreach ($ev['segments'] as $seg) {
            if (!empty($seg['late_minutes'])) {
                $minutes_late += (int) $seg['late_minutes'];
            }
        }
        do_action('sfs_hr_attendance_late', $employee_id, [
            'minutes_late' => $minutes_late,
            'work_date'    => $ymd,
            'type'         => 'attendance_flag',
        ]);
    }

    // Calculate minutes early
    $minutes_early = 0;
    if ( $is_early ) {
        if ( $is_total_hours ) {
            $th_target = (int) ( Policy_Service::get_target_hours( $employee_id, $shift ) * 60 );
            $minutes_early = max( 0, $th_target - $net );
        } else {
            foreach ($ev['segments'] as $seg) {
                if (!empty($seg['early_minutes'])) {
                    $minutes_early += (int) $seg['early_minutes'];
                }
            }
            if ( $minutes_early === 0 && $shift && ! empty( $shift->end_time ) && $lastOut ) {
                $tz_fb       = wp_timezone();
                $shift_end_dt = new \DateTimeImmutable( $ymd . ' ' . $shift->end_time, $tz_fb );
                if ( ! empty( $shift->start_time ) && $shift->end_time < $shift->start_time ) {
                    $shift_end_dt = $shift_end_dt->modify( '+1 day' );
                }
                $last_out_dt  = ( new \DateTimeImmutable( $lastOut, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz_fb );
                $diff_secs    = $shift_end_dt->getTimestamp() - $last_out_dt->getTimestamp();
                if ( $diff_secs > 0 ) {
                    $minutes_early = (int) round( $diff_secs / 60 );
                }
            }
        }
    }

    if ($is_early && !$was_early) {
        do_action('sfs_hr_attendance_early_leave', $employee_id, [
            'minutes_early' => $minutes_early,
            'work_date'     => $ymd,
            'type'          => 'attendance_flag',
        ]);
    }

    // Auto-create early leave request
    if ( $is_early && $minutes_early > 0 ) {
        Early_Leave_Service::maybe_create_early_leave_request(
            $employee_id,
            $ymd,
            $session_id,
            $lastOut,
            $minutes_early,
            $is_total_hours,
            $shift,
            $wpdb
        );
    }

    // Fire no-break-taken notification
    $was_no_break = in_array( 'no_break_taken', $existing_flags, true );
    if ( $no_break_taken && ! $was_no_break ) {
        do_action( 'sfs_hr_attendance_no_break_taken', $employee_id, [
            'work_date'           => $ymd,
            'configured_break'    => $shift_break_minutes,
            'type'                => 'attendance_flag',
        ] );
    }

    // Fire break-delay notification
    $was_break_delay = in_array( 'break_delay', $existing_flags, true );
    if ( $break_delay_minutes > 0 && ! $was_break_delay ) {
        do_action( 'sfs_hr_attendance_break_delay', $employee_id, [
            'work_date'           => $ymd,
            'delay_minutes'       => $break_delay_minutes,
            'configured_break'    => $shift_break_minutes,
            'actual_break'        => (int) $ev['break_total'],
            'type'                => 'attendance_flag',
        ] );
    }

    } finally {
        // Release recalc lock
        $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $recalc_lock ) );
    }
}

    /**
     * Retroactively close the previous day's incomplete session when leading
     * OUT punches are found on the current day.
     */
    private static function retro_close_previous_session(
        int $employee_id,
        string $ymd,
        array $leadingOuts,
        \wpdb $wpdb,
        string $sT
    ): void {
        $prevDateDt = ( new \DateTimeImmutable( $ymd, wp_timezone() ) )->modify( '-1 day' );
        $prevDate   = $prevDateDt->format( 'Y-m-d' );
        $prevSess   = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status, in_time, out_time, break_minutes, flags_json, locked FROM {$sT}
             WHERE employee_id = %d AND work_date = %s LIMIT 1",
            $employee_id, $prevDate
        ) );
        if ( ! $prevSess || ( $prevSess->status !== 'incomplete' && $prevSess->out_time !== null ) || ! $prevSess->in_time ) {
            return;
        }

        $closingOut   = end( $leadingOuts );
        $inTs         = strtotime( $prevSess->in_time . ' UTC' );
        $outTs        = strtotime( $closingOut . ' UTC' );
        $grossMinutes = ( $outTs > $inTs ) ? (int) round( ( $outTs - $inTs ) / 60 ) : 0;
        $netMinutes   = max( 0, $grossMinutes - (int) $prevSess->break_minutes );

        $prevShift  = Shift_Service::resolve_shift_for_date( $employee_id, $prevDate, [], $wpdb );
        $prevSettings = get_option( self::OPT_SETTINGS ) ?: [];
        $prevRound    = ( $prevShift && isset( $prevShift->rounding_rule ) )
            ? (string) $prevShift->rounding_rule
            : (string) ( $prevSettings['default_rounding_rule'] ?? '5' );
        $prevRoundN   = ( $prevRound === 'none' ) ? 0 : (int) $prevRound;
        $roundedNet   = $netMinutes;
        if ( $prevRoundN > 0 ) {
            $roundedNet = (int) round( $netMinutes / $prevRoundN ) * $prevRoundN;
        }

        // Remove 'incomplete' flag, keep other flags.
        $prevFlags = $prevSess->flags_json ? ( json_decode( $prevSess->flags_json, true ) ?: [] ) : [];
        $prevFlags = array_values( array_filter( $prevFlags, fn( $f ) => $f !== 'incomplete' ) );

        // Detect left_early from the closing OUT vs the previous day's shift end.
        $prevIsEarly     = false;
        $prevEarlyMin    = 0;
        $prevIsTotalHrs  = Policy_Service::is_total_hours_mode( $employee_id, $prevShift );
        if ( $prevIsTotalHrs ) {
            $prevTargetHrs = Policy_Service::get_target_hours( $employee_id, $prevShift );
            $prevTargetMin = (int) ( $prevTargetHrs * 60 );
            if ( $roundedNet < $prevTargetMin ) {
                $prevIsEarly  = true;
                $prevEarlyMin = $prevTargetMin - $roundedNet;
            }
        } elseif ( $prevShift && ! empty( $prevShift->end_time ) ) {
            $tz_rc        = wp_timezone();
            $shiftGrEarlyRc = ( $prevShift && $prevShift->grace_early_leave_minutes !== null )
                ? (int) $prevShift->grace_early_leave_minutes : null;
            $grEarlyRc = $shiftGrEarlyRc !== null
                ? $shiftGrEarlyRc
                : (int) ( $prevSettings['default_grace_early'] ?? 5 );
            $shiftEndDt   = new \DateTimeImmutable( $prevDate . ' ' . $prevShift->end_time, $tz_rc );
            if ( $prevShift->start_time && $prevShift->end_time < $prevShift->start_time ) {
                $shiftEndDt = $shiftEndDt->modify( '+1 day' );
            }
            $closingOutDt = ( new \DateTimeImmutable( $closingOut, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz_rc );
            $diffSecs     = $shiftEndDt->getTimestamp() - $closingOutDt->getTimestamp();
            if ( $diffSecs > ( $grEarlyRc * 60 ) ) {
                $prevIsEarly  = true;
                $prevEarlyMin = (int) round( $diffSecs / 60 );
            }
        }

        if ( $prevIsEarly && ! in_array( 'left_early', $prevFlags, true ) ) {
            $prevFlags[] = 'left_early';
        }

        // Suppress left_early if the previous day has an approved early leave request.
        $elr_prev_table  = $wpdb->prefix . 'sfs_hr_early_leave_requests';
        $prev_approved_elr_id = $prevIsEarly ? $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$elr_prev_table}
             WHERE employee_id = %d AND request_date = %s AND status = 'approved'
             LIMIT 1",
            $employee_id, $prevDate
        ) ) : null;
        if ( $prev_approved_elr_id ) {
            $prevIsEarly = false;
            $prevFlags   = array_values( array_diff( $prevFlags, [ 'left_early' ] ) );
            if ( ! in_array( 'early_leave', $prevFlags, true ) ) {
                $prevFlags[] = 'early_leave';
            }
        }

        $prevStatus = 'present';
        if ( in_array( 'late', $prevFlags, true ) && in_array( 'left_early', $prevFlags, true ) ) {
            $prevStatus = 'late';
        } elseif ( in_array( 'late', $prevFlags, true ) ) {
            $prevStatus = 'late';
        } elseif ( in_array( 'left_early', $prevFlags, true ) ) {
            $prevStatus = 'left_early';
        }

        // Compute overtime for the retro-closed session.
        $prevOtThreshold = ( $prevShift && $prevShift->overtime_after_minutes !== null )
            ? (int) $prevShift->overtime_after_minutes
            : 0;
        if ( $prevIsTotalHrs ) {
            $prevOt = max( 0, $netMinutes - $prevTargetMin - $prevOtThreshold );
        } else {
            $prevSegments  = Shift_Service::build_segments_from_shift( $prevShift, $prevDate );
            $prevScheduled = 0;
            foreach ( $prevSegments as $ps ) {
                $prevScheduled += (int) $ps['minutes'];
            }
            $prevOt = max( 0, $netMinutes - $prevScheduled - $prevOtThreshold );
        }
        if ( $prevRoundN > 0 ) {
            $prevOt = (int) round( $prevOt / $prevRoundN ) * $prevRoundN;
        }

        $prev_session_data = [
            'out_time'            => $closingOut,
            'net_minutes'         => $netMinutes,
            'rounded_net_minutes' => $roundedNet,
            'overtime_minutes'    => $prevOt,
            'status'              => $prevStatus,
            'flags_json'          => wp_json_encode( $prevFlags ),
            'last_recalc_at'      => current_time( 'mysql', true ),
        ];
        if ( $prev_approved_elr_id ) {
            $prev_session_data['early_leave_approved']   = 1;
            $prev_session_data['early_leave_request_id'] = (int) $prev_approved_elr_id;
        } else {
            $prev_session_data['early_leave_approved']   = 0;
            $prev_session_data['early_leave_request_id'] = null;
        }

        $retro_result = $wpdb->update( $sT, $prev_session_data, [ 'id' => (int) $prevSess->id, 'locked' => 0 ] );
        if ( $retro_result === false ) {
            error_log("[SFS-HR] recalc_session_for: retro-close write failed session_id={$prevSess->id} db_error={$wpdb->last_error}");
            $prevIsEarly = false;
        } elseif ( $retro_result === 0 ) {
            $is_locked = (int) $wpdb->get_var( $wpdb->prepare("SELECT locked FROM {$sT} WHERE id=%d", (int) $prevSess->id) );
            if ( $is_locked ) {
                error_log("[SFS-HR] recalc_session_for: skipping retro-close of locked session id={$prevSess->id}");
                $prevIsEarly = false;
            }
        }

        // Auto-create early leave request for the retro-closed session.
        if ( $prevIsEarly && $prevEarlyMin > 0 ) {
            Early_Leave_Service::maybe_create_early_leave_request(
                $employee_id,
                $prevDate,
                (int) $prevSess->id,
                $closingOut,
                $prevEarlyMin,
                $prevIsTotalHrs,
                $prevShift,
                $wpdb
            );
        }
    }

    /** Evaluate a day against split segments. Stores detail for calc. */
    public static function evaluate_segments(array $segments, array $punchesUTC, int $graceLateMin, int $graceEarlyMin, int $dayEndUtcTs = 0): array {
        // Build intervals from IN..OUT, ignore break types
        $intervals = [];
        $open = null;
        foreach ($punchesUTC as $r) {
            $t = strtotime($r->punch_time.' UTC');
            if ($r->punch_type === 'in') {
                if ($open===null) $open = $t;
            } elseif ($r->punch_type === 'out') {
                if ($open!==null && $t > $open) {
                    $intervals[] = [$open, $t];
                    $open = null;
                }
            }
        }
        $has_unmatched = ($open !== null);

        // Calculate break time from break_start..break_end pairs
        $break_total = 0;
        $break_open = null;
        foreach ($punchesUTC as $r) {
            $t = strtotime($r->punch_time.' UTC');
            if ($r->punch_type === 'break_start') {
                if ($break_open === null) $break_open = $t;
            } elseif ($r->punch_type === 'break_end') {
                if ($break_open !== null && $t > $break_open) {
                    $break_total += (int)round(($t - $break_open) / 60);
                    $break_open = null;
                }
            }
        }

        $flags = [];
        $worked_total = 0;
        $scheduled_total = 0;
        $seg_details = [];

        // Calculate ACTUAL worked time from all IN/OUT intervals
        $actual_worked_minutes = 0;
        foreach ($intervals as [$start, $end]) {
            $actual_worked_minutes += (int)round(($end - $start) / 60);
        }

        foreach ($segments as $seg) {
            $S = strtotime($seg['start_utc'].' UTC');
            $E = strtotime($seg['end_utc'].' UTC');
            $scheduled_total += $seg['minutes'];

            $ovMin = 0; $firstIn = null; $lastOut = null;
            foreach ($intervals as [$a,$b]) {
                $start = max($a,$S);
                $end   = min($b,$E);
                if ($end > $start) {
                    $ovMin += (int)round(($end - $start)/60);
                    if ($firstIn === null || $a < $firstIn) $firstIn = $a;
                    if ($lastOut === null || $b > $lastOut) $lastOut = $b;
                }
            }
            $segFlags = [];
            $late_min  = 0;
            $early_min = 0;

            if ($ovMin === 0) {
                $segFlags[] = 'missed_segment';
            } else {
                if ($firstIn !== null && ($firstIn - $S) > ($graceLateMin*60)) {
                    $segFlags[] = 'late';
                    $late_min = (int) round( ($firstIn - $S) / 60 );
                }
                if ($lastOut !== null && ($E - $lastOut) > ($graceEarlyMin*60)) {
                    $early_min = (int) round( ($E - $lastOut) / 60 );
                    if ( $early_min > 0 ) {
                        $segFlags[] = 'left_early';
                    }
                }
            }
            $flags = array_values(array_unique(array_merge($flags, $segFlags)));
            $seg_details[] = [
                'start' => $seg['start_l'],
                'end'   => $seg['end_l'],
                'scheduled_min' => $seg['minutes'],
                'worked_min'    => $ovMin,
                'flags' => $segFlags,
                'late_minutes'  => $late_min,
                'early_minutes' => $early_min,
            ];
        }

        $worked_total = $actual_worked_minutes;

        if ($has_unmatched) $flags[] = 'incomplete';
        $flags = array_values(array_unique($flags));

        return [
            'worked_total'    => $worked_total,
            'scheduled_total' => $scheduled_total,
            'break_total'     => $break_total,
            'flags'           => $flags,
            'segments'        => $seg_details,
        ];
    }

    /**
     * Rebuild sessions for ALL active employees on a given date (static version).
     */
    public static function rebuild_sessions_for_date_static( string $date ): void {
        global $wpdb;
        $pT = $wpdb->prefix . 'sfs_hr_attendance_punches';
        $eT = $wpdb->prefix . 'sfs_hr_employees';

        list( $utc_start, $utc_end ) = AttendanceModule::local_day_window_to_utc( $date );
        $punched = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT employee_id FROM {$pT} WHERE punch_time >= %s AND punch_time < %s",
            $utc_start, $utc_end
        ) );
        $punched = array_map( 'intval', (array) $punched );

        $all_active = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$eT} WHERE status = %s", 'active' ) );
        $all_active = array_map( 'intval', (array) $all_active );

        $all_ids = array_values( array_unique( array_merge( $punched, $all_active ) ) );
        foreach ( $all_ids as $eid ) {
            self::recalc_session_for( $eid, $date, $wpdb );
        }
    }
}
