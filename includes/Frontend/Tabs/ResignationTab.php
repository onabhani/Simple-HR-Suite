<?php
/**
 * Frontend Resignation Tab
 *
 * Self-service resignation submission form (modal) and card-based history.
 * Redesigned with §10.1 design system — no tables, consistent card layout.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ResignationTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own resignation information.', 'sfs-hr' ) . '</p>';
            return;
        }

        $employee_id = isset( $emp['id'] ) ? (int) $emp['id'] : 0;
        if ( $employee_id <= 0 ) {
            echo '<p>' . esc_html__( 'Employee record not found.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;
        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';

        $resignations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$resign_table} WHERE employee_id = %d ORDER BY id DESC",
                $employee_id
            ),
            ARRAY_A
        );

        $has_pending  = false;
        $has_approved = false;
        foreach ( $resignations as $r ) {
            if ( $r['status'] === 'pending' ) {
                $has_pending = true;
            }
            if ( $r['status'] === 'approved' ) {
                $has_approved = true;
            }
        }

        $can_submit = ! $has_pending && ! $has_approved;

        // Header with action button
        echo '<div class="sfs-section" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">';
        echo '<h2 class="sfs-section-title" data-i18n-key="resignation" style="margin:0;">' . esc_html__( 'Resignation', 'sfs-hr' ) . '</h2>';
        if ( $can_submit ) {
            echo '<button type="button" class="sfs-btn sfs-btn--primary" onclick="document.getElementById(\'sfs-resign-modal\').classList.add(\'sfs-modal-active\')" data-i18n-key="submit_resignation" style="white-space:nowrap;">';
            echo '<svg viewBox="0 0 24 24" style="width:16px;height:16px;margin-inline-end:4px;vertical-align:-2px;" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
            echo esc_html__( 'Submit Resignation', 'sfs-hr' );
            echo '</button>';
        }
        echo '</div>';

        // Status alert
        if ( $has_pending ) {
            echo '<div class="sfs-alert sfs-alert--warning">';
            echo '<svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" stroke="currentColor" fill="none" stroke-width="2"/><line x1="12" y1="9" x2="12" y2="13" stroke="currentColor" stroke-width="2"/><line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor" stroke-width="2"/></svg>';
            echo '<span data-i18n-key="pending_resignation_notice">' . esc_html__( 'You have a pending resignation request. You cannot submit a new one until the current request is processed.', 'sfs-hr' ) . '</span></div>';
        } elseif ( $has_approved ) {
            echo '<div class="sfs-alert sfs-alert--success">';
            echo '<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" fill="none" stroke-width="2"/><polyline points="22 4 12 14.01 9 11.01" stroke="currentColor" fill="none" stroke-width="2"/></svg>';
            echo '<span data-i18n-key="approved_resignation_notice">' . esc_html__( 'Your resignation has been approved. Please coordinate with HR for your exit process.', 'sfs-hr' ) . '</span></div>';
        }

        // History (card-based)
        if ( ! empty( $resignations ) ) {
            $this->render_history( $resignations );
        }

        // Resignation form modal
        if ( $can_submit ) {
            $this->render_form_modal();
        }
    }

    /* ──────────────────────────────────────────────────────────
       Resignation Form Modal
    ────────────────────────────────────────────────────────── */
    private function render_form_modal(): void {
        $notice_period = get_option( 'sfs_hr_resignation_notice_period', '30' );

        echo '<div id="sfs-resign-modal" class="sfs-form-modal-overlay">';
        echo '<div class="sfs-form-modal-backdrop" onclick="document.getElementById(\'sfs-resign-modal\').classList.remove(\'sfs-modal-active\')"></div>';
        echo '<div class="sfs-form-modal">';

        echo '<div class="sfs-form-modal-header">';
        echo '<h3 class="sfs-form-modal-title" data-i18n-key="submit_resignation">' . esc_html__( 'Submit Resignation', 'sfs-hr' ) . '</h3>';
        echo '<button type="button" class="sfs-form-modal-close" onclick="document.getElementById(\'sfs-resign-modal\').classList.remove(\'sfs-modal-active\')" aria-label="' . esc_attr__( 'Close', 'sfs-hr' ) . '">&times;</button>';
        echo '</div>';

        echo '<div class="sfs-form-modal-body">';

        echo '<form method="POST" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'sfs_hr_resignation_submit' );
        echo '<input type="hidden" name="action" value="sfs_hr_resignation_submit">';

        echo '<div class="sfs-form-fields">';

        // Type radio
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label"><span data-i18n-key="resignation_type">' . esc_html__( 'Resignation Type', 'sfs-hr' ) . '</span> <span class="sfs-required">*</span></label>';
        echo '<div style="display:flex;flex-direction:column;gap:10px;margin-top:6px;">';
        echo '<label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;color:var(--sfs-text);">';
        echo '<input type="radio" name="resignation_type" value="regular" checked onchange="sfsToggleResignFields()" style="width:18px;height:18px;margin:0;flex-shrink:0;">';
        echo '<span data-i18n-key="regular_resignation">' . esc_html__( 'Regular Resignation', 'sfs-hr' ) . '</span></label>';
        echo '<label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;color:var(--sfs-text);">';
        echo '<input type="radio" name="resignation_type" value="final_exit" onchange="sfsToggleResignFields()" style="width:18px;height:18px;margin:0;flex-shrink:0;">';
        echo '<span data-i18n-key="final_exit_foreign">' . esc_html__( 'Final Exit (Foreign Employee)', 'sfs-hr' ) . '</span></label>';
        echo '</div></div>';

        // Resignation date
        echo '<div class="sfs-form-group" id="sfs-resign-date-field">';
        echo '<label class="sfs-form-label"><span data-i18n-key="resignation_date">' . esc_html__( 'Resignation Date', 'sfs-hr' ) . '</span> <span class="sfs-required">*</span></label>';
        echo '<input type="date" name="resignation_date" id="sfs_resignation_date" class="sfs-input" required>';
        echo '</div>';

        // Notice period
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label"><span data-i18n-key="notice_period_days">' . esc_html__( 'Notice Period (days)', 'sfs-hr' ) . '</span> <span class="sfs-required">*</span></label>';
        echo '<input type="number" name="notice_period_days" value="' . esc_attr( $notice_period ) . '" min="0" readonly required class="sfs-input" style="background:var(--sfs-background);cursor:not-allowed;">';
        echo '<span class="sfs-form-hint" data-i18n-key="notice_period_hint">' . esc_html__( 'Set by HR based on company policy.', 'sfs-hr' ) . '</span>';
        echo '</div>';

        // Reason
        echo '<div class="sfs-form-group">';
        echo '<label class="sfs-form-label"><span data-i18n-key="reason_for_resignation">' . esc_html__( 'Reason for Resignation', 'sfs-hr' ) . '</span> <span class="sfs-required">*</span></label>';
        echo '<textarea name="reason" rows="3" required class="sfs-textarea"></textarea>';
        echo '</div>';

        // Final exit date (hidden by default)
        echo '<div class="sfs-form-group" id="sfs-final-exit-fields" style="display:none;">';
        echo '<label class="sfs-form-label"><span data-i18n-key="expected_country_exit_date">' . esc_html__( 'Expected Country Exit Date', 'sfs-hr' ) . '</span> <span class="sfs-required">*</span></label>';
        echo '<input type="date" name="expected_country_exit_date" id="sfs_expected_exit_date" class="sfs-input">';
        echo '<span class="sfs-form-hint" data-i18n-key="expected_exit_date_hint">' . esc_html__( 'Expected date when you plan to leave the country', 'sfs-hr' ) . '</span>';
        echo '</div>';

        // Submit
        echo '<button type="submit" class="sfs-btn sfs-btn--primary sfs-btn--full" data-i18n-key="submit_resignation">';
        echo '<svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
        esc_html_e( 'Submit Resignation', 'sfs-hr' );
        echo '</button>';

        echo '</div>'; // .sfs-form-fields
        echo '</form>';
        echo '</div>'; // .sfs-form-modal-body
        echo '</div>'; // .sfs-form-modal
        echo '</div>'; // .sfs-form-modal-overlay

        // Toggle JS + Escape key close
        echo '<script>';
        echo 'function sfsToggleResignFields(){var fe=document.querySelector(\'input[name="resignation_type"][value="final_exit"]\'),df=document.getElementById("sfs-resign-date-field"),di=document.getElementById("sfs_resignation_date"),ff=document.getElementById("sfs-final-exit-fields"),fi=document.getElementById("sfs_expected_exit_date");if(fe&&fe.checked){df.style.display="none";di.removeAttribute("required");ff.style.display="flex";if(fi)fi.setAttribute("required","required");}else{df.style.display="flex";di.setAttribute("required","required");ff.style.display="none";if(fi)fi.removeAttribute("required");}}';
        echo '(function(){var m=document.getElementById("sfs-resign-modal");if(!m)return;';
        echo 'document.addEventListener("keydown",function(e){if(e.key==="Escape")m.classList.remove("sfs-modal-active");});';
        echo '})();';
        echo '</script>';
    }

    /* ──────────────────────────────────────────────────────────
       Resignation History (Card-based)
    ────────────────────────────────────────────────────────── */
    private function render_history( array $resignations ): void {
        echo '<div class="sfs-section" style="margin-top:4px;">';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0 0 14px;" data-i18n-key="resignation_history">' . esc_html__( 'Resignation History', 'sfs-hr' ) . '</h3>';
        echo '</div>';

        echo '<div class="sfs-history-list">';
        foreach ( $resignations as $r ) {
            $type  = $r['resignation_type'] ?? 'regular';
            $ref   = $r['request_number'] ?? '';
            $badge = $this->status_badge( $r['status'], (int) ( $r['approval_level'] ?? 1 ) );

            $type_label = $type === 'final_exit'
                ? __( 'Final Exit', 'sfs-hr' )
                : __( 'Regular', 'sfs-hr' );

            echo '<details class="sfs-history-card">';
            echo '<summary>';
            echo '<div class="sfs-history-card-info">';
            echo '<span class="sfs-history-card-title">' . esc_html( $ref ?: __( 'Resignation', 'sfs-hr' ) ) . '</span>';
            echo '<span class="sfs-history-card-meta">' . esc_html( $type_label ) . ' · ' . esc_html( $r['resignation_date'] ) . '</span>';
            echo '</div>';
            echo $badge;
            echo '</summary>';

            echo '<div class="sfs-history-card-body">';

            // Type badge
            if ( $type === 'final_exit' ) {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Type', 'sfs-hr' ) . '</span><span class="sfs-detail-value"><span class="sfs-badge sfs-badge--info">' . esc_html__( 'Final Exit', 'sfs-hr' ) . '</span></span></div>';
            } else {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Type', 'sfs-hr' ) . '</span><span class="sfs-detail-value"><span class="sfs-badge sfs-badge--completed">' . esc_html__( 'Regular', 'sfs-hr' ) . '</span></span></div>';
            }

            echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Date', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $r['resignation_date'] ) . '</span></div>';
            echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Last Day', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $r['last_working_day'] ?: '-' ) . '</span></div>';

            if ( ! empty( $r['reason'] ) ) {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Reason', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $r['reason'] ) . '</span></div>';
            }

            // Approver info
            $this->render_approver_info( $r );

            // Final exit info
            if ( $type === 'final_exit' ) {
                $this->render_final_exit_info( $r );
            }

            echo '</div></details>';
        }
        echo '</div>';
    }

    private function render_approver_info( array $r ): void {
        if ( ! in_array( $r['status'], [ 'approved', 'rejected' ], true ) || empty( $r['approver_id'] ) ) {
            return;
        }
        $u = get_user_by( 'id', (int) $r['approver_id'] );
        if ( ! $u ) {
            return;
        }
        $lbl   = $r['status'] === 'rejected' ? __( 'Rejected by', 'sfs-hr' ) : __( 'Approved by', 'sfs-hr' );
        $color = $r['status'] === 'rejected' ? 'var(--sfs-danger)' : 'var(--sfs-success)';
        echo '<div class="sfs-detail-row" style="color:' . $color . ';"><span class="sfs-detail-label">' . esc_html( $lbl ) . '</span><span class="sfs-detail-value">' . esc_html( $u->display_name ) . '</span></div>';

        if ( ! empty( $r['approver_note'] ) ) {
            echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Note', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $r['approver_note'] ) . '</span></div>';
        }
    }

    private function render_final_exit_info( array $r ): void {
        $fields = [
            'final_exit_status'         => __( 'Exit Status', 'sfs-hr' ),
            'final_exit_number'         => __( 'Exit Number', 'sfs-hr' ),
            'government_reference'      => __( 'Gov. Ref', 'sfs-hr' ),
            'expected_country_exit_date' => __( 'Expected Exit', 'sfs-hr' ),
            'actual_exit_date'          => __( 'Actual Exit', 'sfs-hr' ),
            'final_exit_date'           => __( 'Exit Issue Date', 'sfs-hr' ),
        ];
        foreach ( $fields as $key => $label ) {
            $val = $r[ $key ] ?? '';
            if ( ! $val || $val === 'not_required' ) {
                continue;
            }
            if ( $key === 'final_exit_status' ) {
                $val = ucwords( str_replace( '_', ' ', $val ) );
            }
            echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html( $label ) . '</span><span class="sfs-detail-value">' . esc_html( $val ) . '</span></div>';
        }
    }

    private function status_badge( string $status, int $level = 1 ): string {
        $label = ucfirst( $status );
        $class = 'pending';
        if ( $status === 'pending' ) {
            if ( $level === 1 ) {
                $label = __( 'Pending - Manager', 'sfs-hr' );
            } elseif ( $level === 2 ) {
                $label = __( 'Pending - HR', 'sfs-hr' );
            } elseif ( $level === 3 ) {
                $label = __( 'Pending - Finance', 'sfs-hr' );
            } else {
                $label = __( 'Pending', 'sfs-hr' );
            }
        } elseif ( $status === 'approved' ) {
            $class = 'approved';
            $label = __( 'Approved', 'sfs-hr' );
        } elseif ( $status === 'rejected' ) {
            $class = 'rejected';
            $label = __( 'Rejected', 'sfs-hr' );
        } elseif ( $status === 'cancelled' ) {
            $class = 'cancelled';
            $label = __( 'Cancelled', 'sfs-hr' );
        }
        return '<span class="sfs-badge sfs-badge--' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
    }
}
