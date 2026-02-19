<?php
namespace SFS\HR\Modules\Surveys\Frontend;

use SFS\HR\Core\Helpers;
use SFS\HR\Frontend\Tabs\TabInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SurveysTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        global $wpdb;

        $t_surveys   = $wpdb->prefix . 'sfs_hr_surveys';
        $t_questions = $wpdb->prefix . 'sfs_hr_survey_questions';
        $t_responses = $wpdb->prefix . 'sfs_hr_survey_responses';

        $dept_id = (int) ( $emp['dept_id'] ?? 0 );

        // Get published surveys this employee can see.
        $all_published = $wpdb->get_results(
            "SELECT * FROM {$t_surveys} WHERE status = 'published' ORDER BY published_at DESC",
            ARRAY_A
        );

        // Filter by scope.
        $available = [];
        foreach ( $all_published as $s ) {
            if ( $s['target_scope'] === 'all' ) {
                $available[] = $s;
            } elseif ( $s['target_scope'] === 'department' && ! empty( $s['target_ids'] ) ) {
                $ids = json_decode( $s['target_ids'], true ) ?: [];
                if ( in_array( $dept_id, $ids, true ) ) {
                    $available[] = $s;
                }
            }
        }

        // Check which ones the employee already completed.
        $completed_ids = [];
        if ( ! empty( $available ) ) {
            $survey_ids   = array_column( $available, 'id' );
            $placeholders = implode( ',', array_fill( 0, count( $survey_ids ), '%d' ) );
            $done = $wpdb->get_col( $wpdb->prepare(
                "SELECT survey_id FROM {$t_responses} WHERE employee_id = %d AND survey_id IN ({$placeholders})",
                $emp_id, ...$survey_ids
            ) );
            $completed_ids = array_map( 'intval', $done );
        }

        // Check if viewing a specific survey form.
        $view_survey_id = isset( $_GET['survey_id'] ) ? (int) $_GET['survey_id'] : 0;
        $just_submitted = isset( $_GET['survey_submitted'] );

        // Success message.
        if ( $just_submitted ) {
            echo '<div class="sfs-alert sfs-alert--success" style="margin-bottom:16px;padding:12px 16px;background:#d1fae5;color:#065f46;border-radius:8px;font-size:14px;">';
            echo esc_html__( 'Thank you! Your response has been submitted.', 'sfs-hr' );
            echo '</div>';
        }

        // If viewing a specific survey — render the form.
        if ( $view_survey_id > 0 && ! in_array( $view_survey_id, $completed_ids, true ) ) {
            $this->render_survey_form( $view_survey_id, $emp_id );
            return;
        }

        // Otherwise show the surveys list.
        $this->render_styles();

        $pending  = [];
        $finished = [];
        foreach ( $available as $s ) {
            if ( in_array( (int) $s['id'], $completed_ids, true ) ) {
                $finished[] = $s;
            } else {
                $pending[] = $s;
            }
        }

        echo '<div class="sfs-surveys-tab">';

        // Pending surveys.
        echo '<h2 class="sfs-section-title" data-i18n-key="pending_surveys">' . esc_html__( 'Pending Surveys', 'sfs-hr' ) . '</h2>';

        if ( empty( $pending ) ) {
            echo '<div class="sfs-empty-state" style="padding:24px;text-align:center;color:var(--sfs-text-muted,#6b7280);background:var(--sfs-card-bg,#fff);border-radius:12px;border:1px solid var(--sfs-border,#e5e7eb);">';
            echo '<div style="font-size:32px;margin-bottom:8px;">&#9989;</div>';
            echo '<p data-i18n-key="no_pending_surveys">' . esc_html__( 'No pending surveys. You\'re all caught up!', 'sfs-hr' ) . '</p>';
            echo '</div>';
        } else {
            foreach ( $pending as $s ) {
                $sid   = (int) $s['id'];
                $q_cnt = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$t_questions} WHERE survey_id = %d", $sid
                ) );
                $url = add_query_arg( [ 'sfs_hr_tab' => 'surveys', 'survey_id' => $sid ] );

                echo '<div class="sfs-survey-card sfs-survey-card--pending">';
                echo '<div class="sfs-survey-card__body">';
                echo '<h3 class="sfs-survey-card__title">' . esc_html( $s['title'] ) . '</h3>';
                if ( $s['description'] ) {
                    echo '<p class="sfs-survey-card__desc">' . esc_html( $s['description'] ) . '</p>';
                }
                echo '<div class="sfs-survey-card__meta">';
                echo '<span>' . sprintf( esc_html__( '%d questions', 'sfs-hr' ), $q_cnt ) . '</span>';
                if ( $s['is_anonymous'] ) {
                    echo ' &middot; <span data-i18n-key="anonymous">&#128275; ' . esc_html__( 'Anonymous', 'sfs-hr' ) . '</span>';
                }
                echo '</div>';
                echo '</div>';
                echo '<a href="' . esc_url( $url ) . '" class="sfs-btn sfs-btn--primary" data-i18n-key="take_survey">'
                    . esc_html__( 'Take Survey', 'sfs-hr' ) . '</a>';
                echo '</div>';
            }
        }

        // Completed surveys.
        if ( ! empty( $finished ) ) {
            echo '<h2 class="sfs-section-title" style="margin-top:24px;" data-i18n-key="completed_surveys">'
                . esc_html__( 'Completed Surveys', 'sfs-hr' ) . '</h2>';
            foreach ( $finished as $s ) {
                echo '<div class="sfs-survey-card sfs-survey-card--done">';
                echo '<div class="sfs-survey-card__body">';
                echo '<h3 class="sfs-survey-card__title">' . esc_html( $s['title'] ) . '</h3>';
                if ( $s['description'] ) {
                    echo '<p class="sfs-survey-card__desc">' . esc_html( $s['description'] ) . '</p>';
                }
                echo '</div>';
                echo '<span class="sfs-badge sfs-badge--approved" data-i18n-key="submitted">'
                    . esc_html__( 'Submitted', 'sfs-hr' ) . '</span>';
                echo '</div>';
            }
        }

        echo '</div>';
    }

    /* ─────── Render survey response form ─────── */

    private function render_survey_form( int $survey_id, int $emp_id ): void {
        global $wpdb;

        $survey = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_surveys WHERE id = %d AND status = 'published'",
            $survey_id
        ), ARRAY_A );

        if ( ! $survey ) {
            echo '<p>' . esc_html__( 'Survey not available.', 'sfs-hr' ) . '</p>';
            return;
        }

        $questions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}sfs_hr_survey_questions WHERE survey_id = %d ORDER BY sort_order ASC, id ASC",
            $survey_id
        ), ARRAY_A );

        $this->render_styles();

        $back_url = remove_query_arg( 'survey_id' );

        echo '<div class="sfs-surveys-tab">';
        echo '<a href="' . esc_url( $back_url ) . '" class="sfs-btn sfs-btn--sm" style="margin-bottom:16px;" data-i18n-key="back_to_surveys">'
            . '&larr; ' . esc_html__( 'Back to Surveys', 'sfs-hr' ) . '</a>';

        echo '<div class="sfs-survey-form-card">';
        echo '<h2 style="margin:0 0 4px;">' . esc_html( $survey['title'] ) . '</h2>';
        if ( $survey['description'] ) {
            echo '<p style="color:var(--sfs-text-muted,#6b7280);margin:0 0 16px;font-size:14px;">' . esc_html( $survey['description'] ) . '</p>';
        }
        if ( $survey['is_anonymous'] ) {
            echo '<div style="padding:8px 12px;background:#eff6ff;color:#1e40af;border-radius:6px;font-size:13px;margin-bottom:16px;" data-i18n-key="anonymous_note">';
            echo '&#128275; ' . esc_html__( 'Your response is anonymous. Your identity will not be linked to your answers.', 'sfs-hr' );
            echo '</div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'sfs_hr_survey_submit_response' );
        echo '<input type="hidden" name="action" value="sfs_hr_survey_submit_response" />';
        echo '<input type="hidden" name="survey_id" value="' . $survey_id . '" />';

        foreach ( $questions as $i => $q ) {
            $qid      = (int) $q['id'];
            $num      = $i + 1;
            $required = $q['is_required'] ? ' <span style="color:#dc2626;">*</span>' : '';

            echo '<div class="sfs-survey-question">';
            echo '<label class="sfs-survey-question__label">' . esc_html( $num . '. ' . $q['question_text'] ) . $required . '</label>';

            switch ( $q['question_type'] ) {
                case 'rating':
                    echo '<div class="sfs-survey-stars" data-name="q_' . $qid . '">';
                    for ( $s = 1; $s <= 5; $s++ ) {
                        echo '<label class="sfs-star-label">'
                            . '<input type="radio" name="q_' . $qid . '" value="' . $s . '"' . ( $q['is_required'] ? ' required' : '' ) . ' style="display:none;" />'
                            . '<span class="sfs-star" data-value="' . $s . '">&#9733;</span>'
                            . '</label>';
                    }
                    echo '</div>';
                    break;

                case 'text':
                    echo '<textarea name="q_' . $qid . '" class="sfs-input" rows="3" placeholder="'
                        . esc_attr__( 'Type your answer...', 'sfs-hr' ) . '"'
                        . ( $q['is_required'] ? ' required' : '' ) . '></textarea>';
                    break;

                case 'yes_no':
                    echo '<div style="display:flex;gap:12px;">';
                    echo '<label class="sfs-radio-card"><input type="radio" name="q_' . $qid . '" value="yes"'
                        . ( $q['is_required'] ? ' required' : '' ) . ' /> '
                        . '<span data-i18n-key="yes">' . esc_html__( 'Yes', 'sfs-hr' ) . '</span></label>';
                    echo '<label class="sfs-radio-card"><input type="radio" name="q_' . $qid . '" value="no" /> '
                        . '<span data-i18n-key="no">' . esc_html__( 'No', 'sfs-hr' ) . '</span></label>';
                    echo '</div>';
                    break;

                case 'choice':
                    $opts = json_decode( $q['options_json'], true ) ?: [];
                    foreach ( $opts as $opt ) {
                        echo '<label class="sfs-radio-card" style="display:block;margin:4px 0;">'
                            . '<input type="radio" name="q_' . $qid . '" value="' . esc_attr( $opt ) . '"'
                            . ( $q['is_required'] ? ' required' : '' ) . ' /> '
                            . esc_html( $opt ) . '</label>';
                    }
                    break;
            }

            echo '</div>';
        }

        echo '<div style="margin-top:20px;">';
        echo '<button type="submit" class="sfs-btn sfs-btn--primary sfs-btn--lg" data-i18n-key="submit_survey">'
            . esc_html__( 'Submit Response', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>'; // .sfs-survey-form-card
        echo '</div>'; // .sfs-surveys-tab

        // Star rating interaction JS.
        ?>
        <script>
        (function(){
            document.querySelectorAll('.sfs-survey-stars').forEach(function(group){
                var stars = group.querySelectorAll('.sfs-star');
                stars.forEach(function(star){
                    star.addEventListener('click', function(){
                        var val = parseInt(this.getAttribute('data-value'));
                        stars.forEach(function(s){
                            s.classList.toggle('sfs-star--active', parseInt(s.getAttribute('data-value')) <= val);
                        });
                    });
                    star.addEventListener('mouseenter', function(){
                        var val = parseInt(this.getAttribute('data-value'));
                        stars.forEach(function(s){
                            s.classList.toggle('sfs-star--hover', parseInt(s.getAttribute('data-value')) <= val);
                        });
                    });
                    star.addEventListener('mouseleave', function(){
                        stars.forEach(function(s){ s.classList.remove('sfs-star--hover'); });
                    });
                });
            });
        })();
        </script>
        <?php
    }

    /* ─────── Shared styles ─────── */

    private function render_styles(): void {
        static $rendered = false;
        if ( $rendered ) return;
        $rendered = true;
        ?>
        <style>
            .sfs-surveys-tab .sfs-section-title { font-size: 16px; font-weight: 600; color: var(--sfs-text,#1d2327); margin: 0 0 12px; }
            .sfs-survey-card {
                display: flex; align-items: center; justify-content: space-between; gap: 16px;
                background: var(--sfs-card-bg,#fff); border: 1px solid var(--sfs-border,#e5e7eb); border-radius: 12px;
                padding: 16px 20px; margin-bottom: 10px;
            }
            .sfs-survey-card--pending { border-left: 4px solid #2563eb; }
            .sfs-survey-card--done { border-left: 4px solid #16a34a; opacity: .8; }
            .sfs-survey-card__title { font-size: 15px; font-weight: 600; color: var(--sfs-text,#1d2327); margin: 0 0 4px; }
            .sfs-survey-card__desc { font-size: 13px; color: var(--sfs-text-muted,#6b7280); margin: 0 0 4px; }
            .sfs-survey-card__meta { font-size: 12px; color: var(--sfs-text-muted,#6b7280); }
            .sfs-survey-form-card {
                background: var(--sfs-card-bg,#fff); border: 1px solid var(--sfs-border,#e5e7eb);
                border-radius: 12px; padding: 24px;
            }
            .sfs-survey-question { margin-bottom: 20px; }
            .sfs-survey-question__label { display: block; font-weight: 600; font-size: 14px; color: var(--sfs-text,#1d2327); margin-bottom: 8px; }
            .sfs-survey-stars { display: flex; gap: 4px; }
            .sfs-star {
                font-size: 28px; color: #d1d5db; cursor: pointer; transition: color .15s; line-height: 1;
            }
            .sfs-star--active, .sfs-star--hover { color: #f59e0b; }
            .sfs-radio-card {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 8px 16px; border: 1px solid var(--sfs-border,#d1d5db); border-radius: 8px;
                cursor: pointer; font-size: 14px; transition: border-color .15s, background .15s;
            }
            .sfs-radio-card:has(input:checked) { border-color: #2563eb; background: #eff6ff; }
            .sfs-survey-question .sfs-input {
                width: 100%; padding: 10px 14px; border: 1px solid var(--sfs-border,#d1d5db);
                border-radius: 8px; font-size: 14px; resize: vertical; box-sizing: border-box;
            }
        </style>
        <?php
    }
}
