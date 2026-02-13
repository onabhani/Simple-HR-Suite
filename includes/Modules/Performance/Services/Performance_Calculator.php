<?php
namespace SFS\HR\Modules\Performance\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Performance Calculator Service
 *
 * Calculates overall performance scores by combining:
 * - Attendance commitment
 * - Goals completion
 * - Review scores
 *
 * Uses configurable weights for each component.
 *
 * @version 1.0.0
 */
class Performance_Calculator {

    /**
     * Calculate overall performance score for an employee.
     *
     * @param int    $employee_id
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function calculate_overall_score( int $employee_id, string $start_date = '', string $end_date = '' ): array {
        // Default to current attendance period
        if ( empty( $start_date ) || empty( $end_date ) ) {
            $att_period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
            if ( empty( $start_date ) ) { $start_date = $att_period['start']; }
            if ( empty( $end_date ) )   { $end_date   = $att_period['end']; }
        }

        $settings = \SFS\HR\Modules\Performance\PerformanceModule::get_settings();
        $weights = $settings['weights'];

        // Get individual metrics
        $attendance_metrics = Attendance_Metrics::get_employee_metrics( $employee_id, $start_date, $end_date );
        $goals_metrics = Goals_Service::calculate_goals_metrics( $employee_id, $start_date, $end_date );
        $review_metrics = Reviews_Service::calculate_review_metrics( $employee_id, $start_date, $end_date );

        // Initialize result
        $result = [
            'employee_id'   => $employee_id,
            'period_start'  => $start_date,
            'period_end'    => $end_date,
            'weights'       => $weights,
            'components'    => [
                'attendance' => [
                    'weight'     => $weights['attendance'],
                    'raw_score'  => null,
                    'normalized' => null,
                    'weighted'   => null,
                    'grade'      => null,
                    'details'    => [],
                ],
                'goals' => [
                    'weight'     => $weights['goals'],
                    'raw_score'  => null,
                    'normalized' => null,
                    'weighted'   => null,
                    'details'    => [],
                ],
                'reviews' => [
                    'weight'     => $weights['reviews'],
                    'raw_score'  => null,
                    'normalized' => null,
                    'weighted'   => null,
                    'details'    => [],
                ],
            ],
            'overall_score' => null,
            'overall_grade' => null,
            'has_data'      => false,
        ];

        $total_weighted = 0;
        $total_weight_used = 0;

        // Process attendance (0-100 scale)
        if ( ! isset( $attendance_metrics['error'] ) && $attendance_metrics['total_working_days'] > 0 ) {
            $attendance_score = $attendance_metrics['commitment_pct'];
            $result['components']['attendance']['raw_score'] = $attendance_score;
            $result['components']['attendance']['normalized'] = $attendance_score; // Already 0-100
            $result['components']['attendance']['weighted'] = ( $attendance_score * $weights['attendance'] ) / 100;
            $result['components']['attendance']['grade'] = $attendance_metrics['attendance_grade'];
            $result['components']['attendance']['details'] = [
                'working_days'      => $attendance_metrics['total_working_days'],
                'days_present'      => $attendance_metrics['days_present'],
                'days_absent'       => $attendance_metrics['days_absent'],
                'late_count'        => $attendance_metrics['late_count'],
                'early_leave_count' => $attendance_metrics['early_leave_count'],
            ];

            $total_weighted += $result['components']['attendance']['weighted'];
            $total_weight_used += $weights['attendance'];
            $result['has_data'] = true;
        }

        // Process goals (0-100 scale)
        if ( $goals_metrics['total_goals'] > 0 ) {
            $goals_score = $goals_metrics['weighted_completion_pct'];
            $result['components']['goals']['raw_score'] = $goals_score;
            $result['components']['goals']['normalized'] = $goals_score; // Already 0-100
            $result['components']['goals']['weighted'] = ( $goals_score * $weights['goals'] ) / 100;
            $result['components']['goals']['details'] = [
                'total_goals'     => $goals_metrics['total_goals'],
                'completed_goals' => $goals_metrics['completed_goals'],
                'active_goals'    => $goals_metrics['active_goals'],
                'avg_progress'    => $goals_metrics['avg_progress'],
            ];

            $total_weighted += $result['components']['goals']['weighted'];
            $total_weight_used += $weights['goals'];
            $result['has_data'] = true;
        }

        // Process reviews (1-5 scale, normalize to 0-100)
        if ( $review_metrics['avg_rating'] !== null ) {
            $review_score = $review_metrics['avg_rating'];
            $normalized_review = self::normalize_rating( $review_score, 1, 5, 0, 100 );

            $result['components']['reviews']['raw_score'] = $review_score;
            $result['components']['reviews']['normalized'] = $normalized_review;
            $result['components']['reviews']['weighted'] = ( $normalized_review * $weights['reviews'] ) / 100;
            $result['components']['reviews']['details'] = [
                'total_reviews'    => $review_metrics['total_reviews'],
                'avg_rating'       => $review_metrics['avg_rating'],
                'latest_rating'    => $review_metrics['latest_rating'],
                'review_breakdown' => $review_metrics['review_breakdown'],
            ];

            $total_weighted += $result['components']['reviews']['weighted'];
            $total_weight_used += $weights['reviews'];
            $result['has_data'] = true;
        }

        // Calculate overall score
        if ( $total_weight_used > 0 ) {
            // Adjust for missing components by redistributing weights
            $adjustment_factor = 100 / $total_weight_used;
            $result['overall_score'] = round( $total_weighted * $adjustment_factor, 2 );
            $result['overall_grade'] = self::get_overall_grade( $result['overall_score'] );
        }

        return $result;
    }

    /**
     * Normalize a value from one scale to another.
     *
     * @param float $value
     * @param float $from_min
     * @param float $from_max
     * @param float $to_min
     * @param float $to_max
     * @return float
     */
    public static function normalize_rating( float $value, float $from_min, float $from_max, float $to_min, float $to_max ): float {
        if ( $from_max === $from_min ) {
            return $to_min;
        }
        $normalized = ( ( $value - $from_min ) / ( $from_max - $from_min ) ) * ( $to_max - $to_min ) + $to_min;
        return round( $normalized, 2 );
    }

    /**
     * Get overall grade based on score.
     *
     * @param float $score (0-100)
     * @return string
     */
    public static function get_overall_grade( float $score ): string {
        if ( $score >= 90 ) {
            return 'exceptional';
        } elseif ( $score >= 80 ) {
            return 'exceeds';
        } elseif ( $score >= 70 ) {
            return 'meets';
        } elseif ( $score >= 60 ) {
            return 'developing';
        }
        return 'needs_improvement';
    }

    /**
     * Get grade display info.
     *
     * @param string $grade
     * @return array
     */
    public static function get_grade_display( string $grade ): array {
        $grades = [
            'exceptional' => [
                'label' => __( 'Exceptional', 'sfs-hr' ),
                'color' => '#22c55e',
                'bg'    => '#dcfce7',
                'icon'  => 'star-filled',
            ],
            'exceeds' => [
                'label' => __( 'Exceeds Expectations', 'sfs-hr' ),
                'color' => '#3b82f6',
                'bg'    => '#dbeafe',
                'icon'  => 'awards',
            ],
            'meets' => [
                'label' => __( 'Meets Expectations', 'sfs-hr' ),
                'color' => '#f59e0b',
                'bg'    => '#fef3c7',
                'icon'  => 'yes-alt',
            ],
            'developing' => [
                'label' => __( 'Developing', 'sfs-hr' ),
                'color' => '#f97316',
                'bg'    => '#ffedd5',
                'icon'  => 'chart-line',
            ],
            'needs_improvement' => [
                'label' => __( 'Needs Improvement', 'sfs-hr' ),
                'color' => '#ef4444',
                'bg'    => '#fee2e2',
                'icon'  => 'warning',
            ],
        ];

        return $grades[ $grade ] ?? $grades['needs_improvement'];
    }

    /**
     * Get department performance ranking.
     *
     * @param int    $dept_id    0 for all departments
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function get_performance_ranking( int $dept_id = 0, string $start_date = '', string $end_date = '' ): array {
        global $wpdb;

        $employees_table = $wpdb->prefix . 'sfs_hr_employees';

        // Default to current attendance period
        if ( empty( $start_date ) || empty( $end_date ) ) {
            $att_period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
            if ( empty( $start_date ) ) { $start_date = $att_period['start']; }
            if ( empty( $end_date ) )   { $end_date   = $att_period['end']; }
        }

        // Get active employees
        $where = "WHERE status = 'active'";
        if ( $dept_id > 0 ) {
            $where .= $wpdb->prepare( " AND dept_id = %d", $dept_id );
        }

        $employees = $wpdb->get_results(
            "SELECT id, employee_code, first_name, last_name, dept_id
             FROM {$employees_table}
             {$where}
             ORDER BY first_name, last_name"
        );

        $rankings = [];

        foreach ( $employees as $emp ) {
            $score_data = self::calculate_overall_score( $emp->id, $start_date, $end_date );

            if ( $score_data['has_data'] && $score_data['overall_score'] !== null ) {
                $rankings[] = [
                    'employee_id'   => $emp->id,
                    'employee_code' => $emp->employee_code,
                    'employee_name' => trim( $emp->first_name . ' ' . $emp->last_name ),
                    'dept_id'       => $emp->dept_id,
                    'overall_score' => $score_data['overall_score'],
                    'overall_grade' => $score_data['overall_grade'],
                    'attendance_score' => $score_data['components']['attendance']['normalized'],
                    'goals_score'      => $score_data['components']['goals']['normalized'],
                    'reviews_score'    => $score_data['components']['reviews']['normalized'],
                ];
            }
        }

        // Sort by overall score ascending (least commitment first)
        usort( $rankings, function( $a, $b ) {
            return $a['overall_score'] <=> $b['overall_score'];
        } );

        // Add rank
        $rank = 1;
        foreach ( $rankings as &$entry ) {
            $entry['rank'] = $rank++;
        }

        return $rankings;
    }

    /**
     * Get department summary.
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function get_departments_summary( string $start_date = '', string $end_date = '' ): array {
        global $wpdb;

        $dept_table = $wpdb->prefix . 'sfs_hr_departments';
        $employees_table = $wpdb->prefix . 'sfs_hr_employees';

        // Default to current attendance period
        if ( empty( $start_date ) || empty( $end_date ) ) {
            $att_period = \SFS\HR\Modules\Attendance\AttendanceModule::get_current_period();
            if ( empty( $start_date ) ) { $start_date = $att_period['start']; }
            if ( empty( $end_date ) )   { $end_date   = $att_period['end']; }
        }

        $departments = $wpdb->get_results(
            "SELECT id, name FROM {$dept_table} WHERE active = 1 ORDER BY name"
        );

        $results = [];

        foreach ( $departments as $dept ) {
            // Get employees in department
            $employees = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$employees_table}
                 WHERE dept_id = %d AND status = 'active'",
                $dept->id
            ) );

            if ( empty( $employees ) ) {
                continue;
            }

            $total_score = 0;
            $score_count = 0;
            $grade_distribution = [
                'exceptional'       => 0,
                'exceeds'           => 0,
                'meets'             => 0,
                'developing'        => 0,
                'needs_improvement' => 0,
            ];

            foreach ( $employees as $emp_id ) {
                $score_data = self::calculate_overall_score( $emp_id, $start_date, $end_date );

                if ( $score_data['has_data'] && $score_data['overall_score'] !== null ) {
                    $total_score += $score_data['overall_score'];
                    $score_count++;
                    $grade_distribution[ $score_data['overall_grade'] ]++;
                }
            }

            $results[] = [
                'dept_id'            => $dept->id,
                'dept_name'          => $dept->name,
                'employee_count'     => count( $employees ),
                'scored_count'       => $score_count,
                'avg_score'          => $score_count > 0 ? round( $total_score / $score_count, 2 ) : null,
                'grade_distribution' => $grade_distribution,
            ];
        }

        // Sort by average score descending
        usort( $results, function( $a, $b ) {
            if ( $a['avg_score'] === null ) return 1;
            if ( $b['avg_score'] === null ) return -1;
            return $b['avg_score'] <=> $a['avg_score'];
        } );

        return $results;
    }

    /**
     * Generate performance snapshot for an employee.
     *
     * @param int    $employee_id
     * @param string $period_start
     * @param string $period_end
     * @return int|false Snapshot ID or false on failure
     */
    public static function generate_snapshot( int $employee_id, string $period_start, string $period_end ) {
        global $wpdb;

        $score_data = self::calculate_overall_score( $employee_id, $period_start, $period_end );

        if ( ! $score_data['has_data'] ) {
            return false;
        }

        // First save the attendance snapshot
        $snapshot_id = Attendance_Metrics::save_snapshot( $employee_id, $period_start, $period_end );

        if ( ! $snapshot_id ) {
            return false;
        }

        // Update with goals and review scores
        $table = $wpdb->prefix . 'sfs_hr_performance_snapshots';

        $wpdb->update(
            $table,
            [
                'goals_completion_pct' => $score_data['components']['goals']['normalized'],
                'review_score'         => $score_data['components']['reviews']['normalized'],
                'overall_score'        => $score_data['overall_score'],
            ],
            [ 'id' => $snapshot_id ]
        );

        return $snapshot_id;
    }
}
