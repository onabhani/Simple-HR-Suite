<?php
/**
 * Frontend Resignation Tab
 *
 * Self-service resignation submission form and read-only history.
 * Redesigned with §10.1 design system.
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

        // Header
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="resignation">' . esc_html__( 'Resignation', 'sfs-hr' ) . '</h2>';
        echo '</div>';

        // Status alert or form
        if ( $has_pending ) {
            echo '<div class="sfs-alert sfs-alert--warning">';
            echo '<svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" stroke="currentColor" fill="none" stroke-width="2"/><line x1="12" y1="9" x2="12" y2="13" stroke="currentColor" stroke-width="2"/><line x1="12" y1="17" x2="12.01" y2="17" stroke="currentColor" stroke-width="2"/></svg>';
            echo '<span data-i18n-key="pending_resignation_notice">' . esc_html__( 'You have a pending resignation request. You cannot submit a new one until the current request is processed.', 'sfs-hr' ) . '</span></div>';
        } elseif ( $has_approved ) {
            echo '<div class="sfs-alert sfs-alert--success">';
            echo '<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" fill="none" stroke-width="2"/><polyline points="22 4 12 14.01 9 11.01" stroke="currentColor" fill="none" stroke-width="2"/></svg>';
            echo '<span data-i18n-key="approved_resignation_notice">' . esc_html__( 'Your resignation has been approved. Please coordinate with HR for your exit process.', 'sfs-hr' ) . '</span></div>';
        } else {
            $this->render_form();
        }

        // History
        if ( ! empty( $resignations ) ) {
            $this->render_history( $resignations );
        }
    }

    private function render_form(): void {
        $notice_period = get_option( 'sfs_hr_resignation_notice_period', '30' );

        echo '<div class="sfs-card" style="margin-bottom:24px;">';
        echo '<div class="sfs-card-body">';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0 0 14px;" data-i18n-key="submit_resignation">' . esc_html__( 'Submit Resignation', 'sfs-hr' ) . '</h3>';

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
        echo '</div></div>';

        // Toggle JS
        echo '<script>function sfsToggleResignFields(){var fe=document.querySelector(\'input[name="resignation_type"][value="final_exit"]\'),df=document.getElementById("sfs-resign-date-field"),di=document.getElementById("sfs_resignation_date"),ff=document.getElementById("sfs-final-exit-fields"),fi=document.getElementById("sfs_expected_exit_date");if(fe&&fe.checked){df.style.display="none";di.removeAttribute("required");ff.style.display="flex";if(fi)fi.setAttribute("required","required");}else{df.style.display="flex";di.setAttribute("required","required");ff.style.display="none";if(fi)fi.removeAttribute("required");}}</script>';
    }

    private function render_history( array $resignations ): void {
        echo '<div class="sfs-section" style="margin-top:4px;">';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0 0 14px;" data-i18n-key="resignation_history">' . esc_html__( 'Resignation History', 'sfs-hr' ) . '</h3>';
        echo '</div>';

        // Desktop table
        echo '<div class="sfs-desktop-only"><table class="sfs-table">';
        echo '<thead><tr>';
        echo '<th data-i18n-key="ref">' . esc_html__( 'Ref #', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="type">' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="date">' . esc_html__( 'Date', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="last_working_day">' . esc_html__( 'Last Day', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $resignations as $r ) {
            $type    = $r['resignation_type'] ?? 'regular';
            $ref     = $r['request_number'] ?? '';
            $badge   = $this->status_badge( $r['status'], (int) ( $r['approval_level'] ?? 1 ) );
            $t_badge = $type === 'final_exit'
                ? '<span class="sfs-badge sfs-badge--info">' . esc_html__( 'Final Exit', 'sfs-hr' ) . '</span>'
                : '<span class="sfs-badge sfs-badge--completed">' . esc_html__( 'Regular', 'sfs-hr' ) . '</span>';

            echo '<tr>';
            echo '<td><strong>' . esc_html( $ref ?: '-' ) . '</strong></td>';
            echo '<td>' . $t_badge . '</td>';
            echo '<td>' . esc_html( $r['resignation_date'] ) . '</td>';
            echo '<td>' . esc_html( $r['last_working_day'] ?: '-' ) . '</td>';
            echo '<td>' . $badge . '</td>';
            echo '</tr>';

            // Details
            $this->render_detail_row_desktop( $r, $type );
        }

        echo '</tbody></table></div>';

        // Mobile cards
        echo '<div class="sfs-mobile-only sfs-history-list">';
        foreach ( $resignations as $r ) {
            $type  = $r['resignation_type'] ?? 'regular';
            $ref   = $r['request_number'] ?? '';
            $badge = $this->status_badge( $r['status'], (int) ( $r['approval_level'] ?? 1 ) );

            echo '<details class="sfs-history-card">';
            echo '<summary>';
            echo '<span class="sfs-history-card-title">' . esc_html( $ref ?: __( 'Resignation', 'sfs-hr' ) ) . '</span>';
            echo $badge;
            echo '</summary>';
            echo '<div class="sfs-history-card-body">';

            if ( $type === 'final_exit' ) {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Type', 'sfs-hr' ) . '</span><span class="sfs-detail-value"><span class="sfs-badge sfs-badge--info">' . esc_html__( 'Final Exit', 'sfs-hr' ) . '</span></span></div>';
            }
            echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Date', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $r['resignation_date'] ) . '</span></div>';
            echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Last Day', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $r['last_working_day'] ?: '-' ) . '</span></div>';

            if ( ! empty( $r['reason'] ) ) {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Reason', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . esc_html( $r['reason'] ) . '</span></div>';
            }
            $this->render_approver_info( $r );
            if ( $type === 'final_exit' ) {
                $this->render_final_exit_info( $r );
            }

            echo '</div></details>';
        }
        echo '</div>';
    }

    private function render_detail_row_desktop( array $r, string $type ): void {
        $has_content = ! empty( $r['reason'] ) || ! empty( $r['approver_note'] )
                     || ( in_array( $r['status'], [ 'approved', 'rejected' ], true ) && ! empty( $r['approver_id'] ) )
                     || $type === 'final_exit';

        if ( ! $has_content ) {
            return;
        }

        echo '<tr><td colspan="5" style="background:var(--sfs-background);font-size:12px;">';

        if ( ! empty( $r['reason'] ) ) {
            echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Reason:', 'sfs-hr' ) . '</strong> ' . nl2br( esc_html( $r['reason'] ) ) . '</p>';
        }

        if ( in_array( $r['status'], [ 'approved', 'rejected' ], true ) && ! empty( $r['approver_id'] ) ) {
            $u = get_user_by( 'id', (int) $r['approver_id'] );
            if ( $u ) {
                $lbl   = $r['status'] === 'rejected' ? __( 'Rejected by:', 'sfs-hr' ) : __( 'Approved by:', 'sfs-hr' );
                $color = $r['status'] === 'rejected' ? 'var(--sfs-danger)' : 'var(--sfs-success)';
                echo '<p style="margin:0 0 4px;color:' . $color . ';"><strong>' . esc_html( $lbl ) . '</strong> ' . esc_html( $u->display_name ) . '</p>';
            }
        }

        if ( ! empty( $r['approver_note'] ) ) {
            echo '<p style="margin:0 0 4px;"><strong>' . esc_html__( 'Note:', 'sfs-hr' ) . '</strong> ' . nl2br( esc_html( $r['approver_note'] ) ) . '</p>';
        }

        if ( $type === 'final_exit' ) {
            $this->render_final_exit_block( $r );
        }

        echo '</td></tr>';
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

    private function render_final_exit_block( array $r ): void {
        echo '<div style="margin-top:8px;padding:10px 12px;background:var(--sfs-surface);border:1px solid var(--sfs-border);border-radius:8px;">';
        echo '<strong style="font-size:12px;">' . esc_html__( 'Final Exit Information', 'sfs-hr' ) . '</strong>';
        echo '<div style="margin-top:6px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:6px;font-size:12px;">';

        $fields = [
            'final_exit_status'         => __( 'Status', 'sfs-hr' ),
            'final_exit_number'         => __( 'Exit #', 'sfs-hr' ),
            'government_reference'      => __( 'Gov. Ref', 'sfs-hr' ),
            'expected_country_exit_date' => __( 'Expected Exit', 'sfs-hr' ),
            'actual_exit_date'          => __( 'Actual Exit', 'sfs-hr' ),
            'final_exit_date'           => __( 'Issue Date', 'sfs-hr' ),
        ];
        foreach ( $fields as $key => $label ) {
            $val = $r[ $key ] ?? '';
            if ( ! $val || $val === 'not_required' ) {
                continue;
            }
            if ( $key === 'final_exit_status' ) {
                $val = ucwords( str_replace( '_', ' ', $val ) );
            }
            echo '<div><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $val ) . '</div>';
        }

        echo '</div></div>';
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
