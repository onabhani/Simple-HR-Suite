<?php
namespace SFS\HR\Modules\Surveys\Services;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Survey_Enhancement_Service {

    private static function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'sfs_hr_' . $name;
    }

    public static function list_templates(): array {
        global $wpdb;
        $table = self::t( 'surveys' );
        $rows  = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE is_template = 1 ORDER BY id DESC",
            ARRAY_A
        );
        return $rows ?: [];
    }

    public static function create_from_template( int $template_id, array $overrides = [] ): array {
        global $wpdb;
        $table = self::t( 'surveys' );

        $template = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND is_template = 1",
                $template_id
            ),
            ARRAY_A
        );

        if ( ! $template ) {
            return [ 'success' => false, 'error' => __( 'Template not found.', 'sfs-hr' ) ];
        }

        $now  = Helpers::now_mysql();
        $data = [
            'title'         => $overrides['title'] ?? $template['title'],
            'description'   => $overrides['description'] ?? $template['description'],
            'status'        => 'draft',
            'is_anonymous'  => $overrides['is_anonymous'] ?? $template['is_anonymous'],
            'target_scope'  => $overrides['target_scope'] ?? $template['target_scope'],
            'target_ids'    => $overrides['target_ids'] ?? $template['target_ids'],
            'created_by'    => get_current_user_id(),
            'is_template'   => 0,
            'template_id'   => $template_id,
            'schedule_type' => $overrides['schedule_type'] ?? 'none',
            'schedule_config' => isset( $overrides['schedule_config'] )
                ? wp_json_encode( $overrides['schedule_config'] )
                : null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        $wpdb->insert( $table, $data );
        $new_id = $wpdb->insert_id;

        if ( ! $new_id ) {
            return [ 'success' => false, 'error' => __( 'Failed to create survey.', 'sfs-hr' ) ];
        }

        self::clone_questions( $template_id, $new_id );

        return [ 'success' => true, 'survey_id' => $new_id ];
    }

    public static function save_as_template( int $survey_id, string $name ): array {
        global $wpdb;
        $table = self::t( 'surveys' );

        $source = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $survey_id ),
            ARRAY_A
        );

        if ( ! $source ) {
            return [ 'success' => false, 'error' => __( 'Survey not found.', 'sfs-hr' ) ];
        }

        $now = Helpers::now_mysql();
        $wpdb->insert( $table, [
            'title'        => sanitize_text_field( $name ),
            'description'  => $source['description'],
            'status'       => 'draft',
            'is_anonymous' => $source['is_anonymous'],
            'target_scope' => $source['target_scope'],
            'target_ids'   => $source['target_ids'],
            'created_by'   => get_current_user_id(),
            'is_template'  => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ] );
        $tpl_id = $wpdb->insert_id;

        if ( ! $tpl_id ) {
            return [ 'success' => false, 'error' => __( 'Failed to create template.', 'sfs-hr' ) ];
        }

        self::clone_questions( $survey_id, $tpl_id );

        return [ 'success' => true, 'survey_id' => $tpl_id ];
    }

    public static function set_branching( int $question_id, array $rules ): bool {
        global $wpdb;

        $sanitized = [];
        foreach ( $rules as $rule ) {
            if ( ! isset( $rule['answer_value'] ) ) {
                continue;
            }
            $sanitized[] = [
                'answer_value'     => sanitize_text_field( $rule['answer_value'] ),
                'next_question_id' => isset( $rule['next_question_id'] )
                    ? (int) $rule['next_question_id']
                    : null,
            ];
        }

        $result = $wpdb->update(
            self::t( 'survey_questions' ),
            [ 'branching_json' => wp_json_encode( $sanitized ) ],
            [ 'id' => $question_id ],
            [ '%s' ],
            [ '%d' ]
        );

        return $result !== false;
    }

    public static function get_next_question( int $question_id, string $answer ): ?int {
        global $wpdb;
        $q_table = self::t( 'survey_questions' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT survey_id, sort_order, branching_json
                 FROM {$q_table}
                 WHERE id = %d",
                $question_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        if ( ! empty( $row['branching_json'] ) ) {
            $rules = json_decode( $row['branching_json'], true );
            if ( is_array( $rules ) ) {
                foreach ( $rules as $rule ) {
                    if ( isset( $rule['answer_value'] ) && $rule['answer_value'] === $answer ) {
                        return $rule['next_question_id'];
                    }
                }
            }
        }

        $next_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$q_table}
                 WHERE survey_id = %d AND sort_order > %d
                 ORDER BY sort_order ASC, id ASC
                 LIMIT 1",
                (int) $row['survey_id'],
                (int) $row['sort_order']
            )
        );

        return $next_id ? (int) $next_id : null;
    }

    public static function get_scheduled_surveys(): array {
        global $wpdb;
        $table = self::t( 'surveys' );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE schedule_type != %s
                   AND status IN ('draft','published')
                   AND is_template = 0
                 ORDER BY next_run_date ASC",
                'none'
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    public static function process_scheduled(): void {
        global $wpdb;
        $table = self::t( 'surveys' );
        $today = wp_date( 'Y-m-d' );

        $due = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE schedule_type != %s
                   AND next_run_date IS NOT NULL
                   AND next_run_date <= %s
                   AND is_template = 0",
                'none',
                $today
            ),
            ARRAY_A
        );

        if ( empty( $due ) ) {
            return;
        }

        foreach ( $due as $survey ) {
            $source_id = ! empty( $survey['template_id'] )
                ? (int) $survey['template_id']
                : (int) $survey['id'];

            $now = Helpers::now_mysql();
            $wpdb->insert( $table, [
                'title'         => $survey['title'],
                'description'   => $survey['description'],
                'status'        => 'published',
                'is_anonymous'  => $survey['is_anonymous'],
                'target_scope'  => $survey['target_scope'],
                'target_ids'    => $survey['target_ids'],
                'created_by'    => $survey['created_by'],
                'is_template'   => 0,
                'template_id'   => $source_id,
                'schedule_type' => 'none',
                'published_at'  => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ] );
            $new_id = $wpdb->insert_id;

            if ( $new_id ) {
                self::clone_questions( $source_id, $new_id );
            }

            $config   = ! empty( $survey['schedule_config'] )
                ? json_decode( $survey['schedule_config'], true )
                : [];
            $next     = self::calculate_next_run( $survey['schedule_type'], $config ?: [] );

            $wpdb->update(
                $table,
                [ 'next_run_date' => $next, 'updated_at' => $now ],
                [ 'id' => (int) $survey['id'] ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    public static function calculate_next_run( string $schedule_type, array $config ): ?string {
        $today = wp_date( 'Y-m-d' );

        switch ( $schedule_type ) {
            case 'daily':
                return wp_date( 'Y-m-d', strtotime( $today . ' +1 day' ) );

            case 'weekly':
                $dow  = isset( $config['day_of_week'] ) ? (int) $config['day_of_week'] : 1;
                $dow  = max( 1, min( 7, $dow ) );
                $days = [ 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                          5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday' ];
                $next = strtotime( "next {$days[ $dow ]}", strtotime( $today ) );
                return wp_date( 'Y-m-d', $next );

            case 'monthly':
                $dom    = isset( $config['day_of_month'] ) ? (int) $config['day_of_month'] : 1;
                $dom    = max( 1, min( 28, $dom ) );
                $year   = (int) wp_date( 'Y' );
                $month  = (int) wp_date( 'n' );
                $candidate = sprintf( '%04d-%02d-%02d', $year, $month, $dom );

                if ( $candidate <= $today ) {
                    $month++;
                    if ( $month > 12 ) {
                        $month = 1;
                        $year++;
                    }
                    $candidate = sprintf( '%04d-%02d-%02d', $year, $month, $dom );
                }
                return $candidate;

            default:
                return null;
        }
    }

    public static function get_non_respondents( int $survey_id ): array {
        global $wpdb;
        $s_table   = self::t( 'surveys' );
        $emp_table = self::t( 'employees' );
        $res_table = self::t( 'survey_responses' );

        $survey = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT target_scope, target_ids FROM {$s_table} WHERE id = %d",
                $survey_id
            ),
            ARRAY_A
        );

        if ( ! $survey ) {
            return [];
        }

        if ( $survey['target_scope'] === 'department' && ! empty( $survey['target_ids'] ) ) {
            $dept_ids = json_decode( $survey['target_ids'], true );
            if ( ! is_array( $dept_ids ) || empty( $dept_ids ) ) {
                return [];
            }
            $placeholders = implode( ',', array_fill( 0, count( $dept_ids ), '%d' ) );
            $sql = $wpdb->prepare(
                "SELECT e.id, e.user_id, e.employee_code,
                        CONCAT(e.first_name, ' ', e.last_name) AS full_name
                 FROM {$emp_table} e
                 WHERE e.status = 'active'
                   AND e.dept_id IN ({$placeholders})
                   AND e.id NOT IN (
                       SELECT r.employee_id FROM {$res_table} r WHERE r.survey_id = %d
                   )
                 ORDER BY e.id ASC",
                ...array_merge( array_map( 'intval', $dept_ids ), [ $survey_id ] )
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT e.id, e.user_id, e.employee_code,
                        CONCAT(e.first_name, ' ', e.last_name) AS full_name
                 FROM {$emp_table} e
                 WHERE e.status = 'active'
                   AND e.id NOT IN (
                       SELECT r.employee_id FROM {$res_table} r WHERE r.survey_id = %d
                   )
                 ORDER BY e.id ASC",
                $survey_id
            );
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return $rows ?: [];
    }

    public static function send_reminders( int $survey_id, int $max_reminders = 3 ): int {
        global $wpdb;
        $s_table = self::t( 'surveys' );

        $survey = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, title, status FROM {$s_table} WHERE id = %d",
                $survey_id
            ),
            ARRAY_A
        );

        if ( ! $survey || $survey['status'] !== 'published' ) {
            return 0;
        }

        $non_respondents = self::get_non_respondents( $survey_id );
        if ( empty( $non_respondents ) ) {
            return 0;
        }

        $sent = 0;
        $now  = Helpers::now_mysql();

        foreach ( $non_respondents as $emp ) {
            $employee_id = (int) $emp['id'];

            $meta_key       = '_sfs_hr_survey_reminder_' . $survey_id;
            $reminder_data  = get_user_meta( (int) $emp['user_id'], $meta_key, true );
            $reminder_count = ! empty( $reminder_data['count'] ) ? (int) $reminder_data['count'] : 0;

            if ( $reminder_count >= $max_reminders ) {
                continue;
            }

            $user = get_userdata( (int) $emp['user_id'] );
            if ( ! $user || empty( $user->user_email ) ) {
                continue;
            }

            $subject = sprintf(
                __( 'Reminder: Please complete the survey "%s"', 'sfs-hr' ),
                $survey['title']
            );

            $message = sprintf(
                '<p>%s</p><p>%s</p>',
                sprintf(
                    __( 'Dear %s,', 'sfs-hr' ),
                    esc_html( $emp['full_name'] )
                ),
                sprintf(
                    __( 'This is a friendly reminder to complete the survey: <strong>%s</strong>. Your feedback is important.', 'sfs-hr' ),
                    esc_html( $survey['title'] )
                )
            );

            Helpers::send_mail( $user->user_email, $subject, $message );

            update_user_meta( (int) $emp['user_id'], $meta_key, [
                'count'   => $reminder_count + 1,
                'last_at' => $now,
            ] );

            $sent++;
        }

        return $sent;
    }

    public static function export_responses( int $survey_id, string $format = 'csv' ): string {
        global $wpdb;
        $s_table = self::t( 'surveys' );
        $r_table = self::t( 'survey_responses' );
        $a_table = self::t( 'survey_answers' );
        $q_table = self::t( 'survey_questions' );
        $e_table = self::t( 'employees' );

        $survey = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, is_anonymous FROM {$s_table} WHERE id = %d",
                $survey_id
            ),
            ARRAY_A
        );

        if ( ! $survey ) {
            return '';
        }

        $is_anon = (int) $survey['is_anonymous'];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.id AS response_id, r.employee_id, r.submitted_at,
                        q.question_text, q.question_type,
                        a.answer_text, a.answer_rating,
                        e.employee_code,
                        CONCAT(e.first_name, ' ', e.last_name) AS full_name
                 FROM {$r_table} r
                 JOIN {$a_table} a ON a.response_id = r.id
                 JOIN {$q_table} q ON q.id = a.question_id
                 LEFT JOIN {$e_table} e ON e.id = r.employee_id
                 WHERE r.survey_id = %d
                 ORDER BY r.submitted_at ASC, q.sort_order ASC, q.id ASC",
                $survey_id
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return '';
        }

        $handle = fopen( 'php://temp', 'r+' );

        fputcsv( $handle, [
            __( 'Employee Code', 'sfs-hr' ),
            __( 'Employee Name', 'sfs-hr' ),
            __( 'Question', 'sfs-hr' ),
            __( 'Answer', 'sfs-hr' ),
            __( 'Submitted At', 'sfs-hr' ),
        ] );

        foreach ( $rows as $row ) {
            $emp_code = $is_anon ? __( 'Anonymous', 'sfs-hr' ) : ( $row['employee_code'] ?? '' );
            $emp_name = $is_anon ? __( 'Anonymous', 'sfs-hr' ) : ( $row['full_name'] ?? '' );

            $answer = '';
            if ( $row['question_type'] === 'rating' && $row['answer_rating'] !== null ) {
                $answer = (string) $row['answer_rating'];
            } elseif ( $row['answer_text'] !== null ) {
                $answer = $row['answer_text'];
            }

            fputcsv( $handle, [
                $emp_code,
                $emp_name,
                $row['question_text'],
                $answer,
                $row['submitted_at'],
            ] );
        }

        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );

        return $csv;
    }

    private static function clone_questions( int $source_id, int $target_id ): void {
        global $wpdb;
        $q_table = self::t( 'survey_questions' );

        $questions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$q_table} WHERE survey_id = %d ORDER BY sort_order ASC, id ASC",
                $source_id
            ),
            ARRAY_A
        );

        if ( empty( $questions ) ) {
            return;
        }

        $now    = Helpers::now_mysql();
        $id_map = [];

        foreach ( $questions as $q ) {
            $wpdb->insert( $q_table, [
                'survey_id'      => $target_id,
                'sort_order'     => $q['sort_order'],
                'question_text'  => $q['question_text'],
                'question_type'  => $q['question_type'],
                'options_json'   => $q['options_json'],
                'is_required'    => $q['is_required'],
                'branching_json' => $q['branching_json'] ?? null,
                'created_at'     => $now,
            ] );
            $id_map[ (int) $q['id'] ] = $wpdb->insert_id;
        }

        foreach ( $questions as $q ) {
            if ( empty( $q['branching_json'] ) ) {
                continue;
            }
            $rules = json_decode( $q['branching_json'], true );
            if ( ! is_array( $rules ) ) {
                continue;
            }
            $changed = false;
            foreach ( $rules as &$rule ) {
                if ( isset( $rule['next_question_id'] ) && $rule['next_question_id'] !== null ) {
                    $old = (int) $rule['next_question_id'];
                    if ( isset( $id_map[ $old ] ) ) {
                        $rule['next_question_id'] = $id_map[ $old ];
                        $changed = true;
                    }
                }
            }
            unset( $rule );

            if ( $changed ) {
                $new_qid = $id_map[ (int) $q['id'] ] ?? 0;
                if ( $new_qid ) {
                    $wpdb->update(
                        $q_table,
                        [ 'branching_json' => wp_json_encode( $rules ) ],
                        [ 'id' => $new_qid ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                }
            }
        }
    }
}
