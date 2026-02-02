<?php
/**
 * Frontend Resignation Tab
 *
 * Self-service resignation submission form and read-only resignation history.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class ResignationTab
 *
 * Handles the resignation tab in the employee frontend profile.
 */
class ResignationTab implements TabInterface {

    /**
     * Render the resignation tab content
     *
     * @param array $emp Employee data array
     * @param int   $emp_id Employee ID
     * @return void
     */
    public function render( array $emp, int $emp_id ): void {
        if ( ! is_user_logged_in() || (int) ( $emp['user_id'] ?? 0 ) !== get_current_user_id() ) {
            echo '<p>' . esc_html__( 'You can only view your own resignation information.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;

        $employee_id = isset( $emp['id'] ) ? (int) $emp['id'] : 0;
        if ( $employee_id <= 0 ) {
            echo '<p>' . esc_html__( 'Employee record not found.', 'sfs-hr' ) . '</p>';
            return;
        }

        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';

        // Fetch existing resignations
        $resignations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$resign_table} WHERE employee_id = %d ORDER BY id DESC",
                $employee_id
            ),
            ARRAY_A
        );

        // Check if there's already a pending/approved resignation
        $has_pending = false;
        $has_approved = false;
        foreach ( $resignations as $r ) {
            if ( $r['status'] === 'pending' ) {
                $has_pending = true;
            }
            if ( $r['status'] === 'approved' ) {
                $has_approved = true;
            }
        }

        echo '<div class="sfs-hr-resignation-tab">';

        // Add CSS for resignation form to match leave form
        ?>
        <style>
        .sfs-hr-resignation-dashboard {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px 18px 18px;
            margin-bottom: 24px;
            background: #ffffff;
        }
        .sfs-hr-resignation-dashboard h4 {
            margin: 0 0 16px 0;
            font-size: 18px;
            font-weight: 600;
        }
        .sfs-hr-resignation-form-wrap {
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            padding: 14px 16px;
            margin-bottom: 14px;
        }
        .sfs-hr-resignation-form-wrap h5 {
            margin: 0 0 12px 0;
            font-size: 14px;
            font-weight: 600;
        }
        .sfs-hr-resign-fields {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .sfs-hr-resign-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .sfs-hr-resign-label {
            font-size: 12px;
            font-weight: 500;
            color: #374151;
        }
        .sfs-hr-resign-hint {
            font-size: 11px;
            color: #6b7280;
        }
        .sfs-hr-resign-radio-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 4px;
        }
        .sfs-hr-resign-radio-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            cursor: pointer;
        }
        [dir="rtl"] .sfs-hr-resign-radio-label {
            flex-direction: row-reverse;
            justify-content: flex-start;
        }
        .sfs-hr-resign-radio-label input[type="radio"] {
            width: 18px;
            height: 18px;
            margin: 0;
            cursor: pointer;
            flex-shrink: 0;
        }
        .sfs-hr-resignation-tab input[type="date"],
        .sfs-hr-resignation-tab input[type="number"],
        .sfs-hr-resignation-tab textarea {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            padding: 10px 12px;
            font-size: 14px;
        }
        .sfs-hr-resignation-tab input[type="date"] {
            min-height: 44px;
            -webkit-appearance: none;
            appearance: none;
        }
        .sfs-hr-resignation-tab textarea {
            min-height: 80px;
            resize: vertical;
        }
        .sfs-hr-resignation-tab input[readonly] {
            background: #f5f5f5;
            cursor: not-allowed;
        }
        .sfs-hr-resign-submit {
            width: 100%;
            padding: 12px 16px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            min-height: 44px;
            background: #4b5563;
            color: #fff;
        }
        .sfs-hr-resign-submit:hover {
            background: #374151;
        }
        /* Dark mode for resignation form */
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resignation-dashboard,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resignation-dashboard {
            background: var(--sfs-surface);
            border-color: var(--sfs-border);
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resignation-dashboard h4,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resignation-dashboard h4 {
            color: var(--sfs-text);
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resignation-form-wrap,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resignation-form-wrap {
            background: var(--sfs-background);
            border-color: var(--sfs-border);
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resignation-form-wrap h5,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resignation-form-wrap h5,
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resign-label,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resign-label {
            color: var(--sfs-text);
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resign-hint,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resign-hint {
            color: var(--sfs-text-muted);
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resign-radio-label,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resign-radio-label {
            color: var(--sfs-text);
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resignation-tab input,
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resignation-tab textarea,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resignation-tab input,
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resignation-tab textarea {
            background: var(--sfs-surface);
            border-color: var(--sfs-border);
            color: var(--sfs-text);
        }
        .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resignation-tab input[readonly],
        .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resignation-tab input[readonly] {
            background: var(--sfs-background);
        }
        </style>
        <?php

        // Show submission form if no pending/approved resignation
        if ( ! $has_pending && ! $has_approved ) {
            ?>
            <div class="sfs-hr-resignation-dashboard">
                <h4 data-i18n-key="resignation"><?php esc_html_e( 'Resignation', 'sfs-hr' ); ?></h4>

                <div class="sfs-hr-resignation-form-wrap">
                    <h5 data-i18n-key="submit_resignation"><?php esc_html_e( 'Submit Resignation', 'sfs-hr' ); ?></h5>

                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'sfs_hr_resignation_submit' ); ?>
                        <input type="hidden" name="action" value="sfs_hr_resignation_submit">

                        <div class="sfs-hr-resign-fields">
                            <div class="sfs-hr-resign-group">
                                <div class="sfs-hr-resign-label">
                                    <span data-i18n-key="resignation_type"><?php esc_html_e( 'Resignation Type', 'sfs-hr' ); ?></span>
                                    <span style="color:#ef4444;">*</span>
                                </div>
                                <div class="sfs-hr-resign-radio-group">
                                    <label class="sfs-hr-resign-radio-label">
                                        <input type="radio" name="resignation_type" value="regular" checked onchange="toggleResignationFields()">
                                        <span data-i18n-key="regular_resignation"><?php esc_html_e( 'Regular Resignation', 'sfs-hr' ); ?></span>
                                    </label>
                                    <label class="sfs-hr-resign-radio-label">
                                        <input type="radio" name="resignation_type" value="final_exit" onchange="toggleResignationFields()">
                                        <span data-i18n-key="final_exit_foreign"><?php esc_html_e( 'Final Exit (Foreign Employee)', 'sfs-hr' ); ?></span>
                                    </label>
                                </div>
                            </div>

                            <div class="sfs-hr-resign-group" id="resignation-date-field">
                                <div class="sfs-hr-resign-label">
                                    <span data-i18n-key="resignation_date"><?php esc_html_e( 'Resignation Date', 'sfs-hr' ); ?></span>
                                    <span style="color:#ef4444;">*</span>
                                </div>
                                <input type="date" name="resignation_date" id="resignation_date" required>
                            </div>

                            <div class="sfs-hr-resign-group">
                                <div class="sfs-hr-resign-label">
                                    <span data-i18n-key="notice_period_days"><?php esc_html_e( 'Notice Period (days)', 'sfs-hr' ); ?></span>
                                    <span style="color:#ef4444;">*</span>
                                </div>
                                <input type="number" name="notice_period_days" id="notice_period_days"
                                       value="<?php echo esc_attr( get_option( 'sfs_hr_resignation_notice_period', '30' ) ); ?>"
                                       min="0" readonly required>
                                <div class="sfs-hr-resign-hint" data-i18n-key="notice_period_hint">
                                    <?php esc_html_e( 'Set by HR based on company policy. Your last working day will be calculated based on this.', 'sfs-hr' ); ?>
                                </div>
                            </div>

                            <div class="sfs-hr-resign-group">
                                <div class="sfs-hr-resign-label">
                                    <span data-i18n-key="reason_for_resignation"><?php esc_html_e( 'Reason for Resignation', 'sfs-hr' ); ?></span>
                                    <span style="color:#ef4444;">*</span>
                                </div>
                                <textarea name="reason" id="reason" rows="3" required></textarea>
                            </div>

                            <div class="sfs-hr-resign-group" id="fe-final-exit-fields" style="display:none;">
                                <div class="sfs-hr-resign-label">
                                    <span data-i18n-key="expected_country_exit_date"><?php esc_html_e( 'Expected Country Exit Date', 'sfs-hr' ); ?></span>
                                    <span style="color:#ef4444;">*</span>
                                </div>
                                <input type="date" name="expected_country_exit_date" id="expected_country_exit_date">
                                <div class="sfs-hr-resign-hint" data-i18n-key="expected_exit_date_hint">
                                    <?php esc_html_e( 'Expected date when you plan to leave the country', 'sfs-hr' ); ?>
                                </div>
                            </div>

                            <div class="sfs-hr-resign-group" style="margin-top:8px;">
                                <button type="submit" class="sfs-hr-resign-submit sfs-hr-lf-submit" data-i18n-key="submit_resignation">
                                    <?php esc_html_e( 'Submit Resignation', 'sfs-hr' ); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <script>
                function toggleResignationFields() {
                    var finalExitRadio = document.querySelector('input[name="resignation_type"][value="final_exit"]');
                    var resignationDateField = document.getElementById('resignation-date-field');
                    var resignationDateInput = document.getElementById('resignation_date');
                    var finalExitFields = document.getElementById('fe-final-exit-fields');
                    var expectedExitDateInput = document.getElementById('expected_country_exit_date');

                    if (finalExitRadio && finalExitRadio.checked) {
                        resignationDateField.style.display = 'none';
                        resignationDateInput.removeAttribute('required');
                        finalExitFields.style.display = 'block';
                        if (expectedExitDateInput) {
                            expectedExitDateInput.setAttribute('required', 'required');
                        }
                    } else {
                        resignationDateField.style.display = 'flex';
                        resignationDateInput.setAttribute('required', 'required');
                        finalExitFields.style.display = 'none';
                        if (expectedExitDateInput) {
                            expectedExitDateInput.removeAttribute('required');
                        }
                    }
                }
                </script>
            </div>
            <?php
        } elseif ( $has_pending ) {
            echo '<div class="sfs-hr-resignation-dashboard">';
            echo '<h4 data-i18n-key="resignation">' . esc_html__( 'Resignation', 'sfs-hr' ) . '</h4>';
            echo '<div class="sfs-hr-alert" style="background:#fef3c7;color:#92400e;padding:14px 16px;border-radius:8px;border:1px solid #fcd34d;font-size:13px;">';
            echo '<strong data-i18n-key="notice">' . esc_html__( 'Notice:', 'sfs-hr' ) . '</strong> ';
            echo '<span data-i18n-key="pending_resignation_notice">' . esc_html__( 'You have a pending resignation request. You cannot submit a new one until the current request is processed.', 'sfs-hr' ) . '</span>';
            echo '</div>';
            echo '</div>';
        } elseif ( $has_approved ) {
            echo '<div class="sfs-hr-resignation-dashboard">';
            echo '<h4 data-i18n-key="resignation">' . esc_html__( 'Resignation', 'sfs-hr' ) . '</h4>';
            echo '<div class="sfs-hr-alert" style="background:#d1fae5;color:#065f46;padding:14px 16px;border-radius:8px;border:1px solid #6ee7b7;font-size:13px;">';
            echo '<strong data-i18n-key="notice">' . esc_html__( 'Notice:', 'sfs-hr' ) . '</strong> ';
            echo '<span data-i18n-key="approved_resignation_notice">' . esc_html__( 'Your resignation has been approved. Please coordinate with HR for your exit process.', 'sfs-hr' ) . '</span>';
            echo '</div>';
            echo '</div>';
        }

        // Show resignation history
        if ( ! empty( $resignations ) ) {
            $this->render_resignation_history( $resignations );
        }

        echo '</div>'; // .sfs-hr-resignation-tab
    }

    /**
     * Render resignation history table
     *
     * @param array $resignations Array of resignation records
     * @return void
     */
    private function render_resignation_history( array $resignations ): void {
        echo '<div class="sfs-hr-resignation-dashboard" style="margin-top:16px;">';
        echo '<h4 style="margin-bottom:12px;" data-i18n-key="resignation_history">' . esc_html__( 'Resignation History', 'sfs-hr' ) . '</h4>';

        // Add mobile CSS
        echo '<style>
            .sfs-hr-resignations-table {
                width: 100%;
                border-collapse: collapse;
                background: #fff;
                border-radius: 8px;
                overflow: hidden;
                border: 1px solid #e5e7eb;
            }
            .sfs-hr-resignations-table th {
                background: #f9fafb;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                color: #6b7280;
            }
            .sfs-hr-resignations-table th,
            .sfs-hr-resignations-table td {
                padding: 10px 12px;
                text-align: start;
                border-bottom: 1px solid #e5e7eb;
            }
            .sfs-hr-resignations-table td {
                font-size: 13px;
            }
            @media screen and (max-width: 782px) {
                .sfs-hr-resignations-table th.hide-mobile,
                .sfs-hr-resignations-table td.hide-mobile {
                    display: none !important;
                }
                .sfs-hr-resignations-table th,
                .sfs-hr-resignations-table td {
                    padding: 8px 6px !important;
                    font-size: 12px;
                }
            }
            /* Dark mode */
            .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resignations-table,
            .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resignations-table {
                background: var(--sfs-surface);
                border-color: var(--sfs-border);
            }
            .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resignations-table th,
            .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resignations-table th {
                background: var(--sfs-background);
                color: var(--sfs-text-muted);
            }
            .sfs-hr-pwa-app:not(.sfs-hr-light-mode) .sfs-hr-resignations-table td,
            .sfs-hr-pwa-app.sfs-hr-dark-mode .sfs-hr-resignations-table td {
                color: var(--sfs-text);
                border-color: var(--sfs-border);
            }
        </style>';

        echo '<table class="sfs-hr-resignations-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th data-i18n-key="ref">' . esc_html__( 'Ref #', 'sfs-hr' ) . '</th>';
        echo '<th class="hide-mobile" data-i18n-key="type">' . esc_html__( 'Type', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="date">' . esc_html__( 'Date', 'sfs-hr' ) . '</th>';
        echo '<th class="hide-mobile" data-i18n-key="last_working_day">' . esc_html__( 'Last Day', 'sfs-hr' ) . '</th>';
        echo '<th data-i18n-key="status">' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ( $resignations as $r ) {
            $status_badge = $this->resignation_status_badge( $r['status'], intval( $r['approval_level'] ?? 1 ) );
            $type = $r['resignation_type'] ?? 'regular';
            $request_number = $r['request_number'] ?? '';

            echo '<tr>';

            // Reference number column
            echo '<td><strong>' . esc_html( $request_number ?: '-' ) . '</strong></td>';

            // Type column
            echo '<td class="hide-mobile">';
            if ( $type === 'final_exit' ) {
                echo '<span style="background:#7c3aed;color:#fff;padding:3px 8px;border-radius:999px;font-size:11px;white-space:nowrap;">'
                    . esc_html__( 'Final Exit', 'sfs-hr' ) . '</span>';
            } else {
                echo '<span style="background:#64748b;color:#fff;padding:3px 8px;border-radius:999px;font-size:11px;">'
                    . esc_html__( 'Regular', 'sfs-hr' ) . '</span>';
            }
            echo '</td>';

            echo '<td>' . esc_html( $r['resignation_date'] ) . '</td>';
            echo '<td class="hide-mobile">' . esc_html( $r['last_working_day'] ?: '-' ) . '</td>';
            echo '<td>' . $status_badge . '</td>';
            echo '</tr>';

            // Show reason, notes, approver info, and Final Exit info in expanded row
            $this->render_resignation_details_row( $r, $type );
        }

        echo '</tbody>';
        echo '</table>';

        echo '</div>'; // .sfs-hr-resignation-dashboard (history)
    }

    /**
     * Render expanded details row for a resignation
     *
     * @param array  $r    Resignation record
     * @param string $type Resignation type
     * @return void
     */
    private function render_resignation_details_row( array $r, string $type ): void {
        $has_approver = in_array( $r['status'], [ 'approved', 'rejected' ], true ) && ! empty( $r['approver_id'] );
        if ( empty( $r['reason'] ) && empty( $r['approver_note'] ) && ! $has_approver && $type !== 'final_exit' ) {
            return;
        }

        echo '<tr>';
        echo '<td colspan="4" style="background:#f9fafb;font-size:12px;padding:10px 12px;">';

        if ( ! empty( $r['reason'] ) ) {
            echo '<div style="margin-bottom:8px;">';
            echo '<strong>' . esc_html__( 'Reason:', 'sfs-hr' ) . '</strong><br>';
            echo '<div style="margin-top:4px;">' . nl2br( esc_html( $r['reason'] ) ) . '</div>';
            echo '</div>';
        }

        // Show approver/rejector info
        if ( in_array( $r['status'], [ 'approved', 'rejected' ], true ) && ! empty( $r['approver_id'] ) ) {
            $approver_user = get_user_by( 'id', (int) $r['approver_id'] );
            if ( $approver_user ) {
                $action_label = $r['status'] === 'rejected' ? __( 'Rejected by:', 'sfs-hr' ) : __( 'Approved by:', 'sfs-hr' );
                $color = $r['status'] === 'rejected' ? '#dc3545' : '#28a745';
                echo '<div style="margin-bottom:8px;color:' . esc_attr( $color ) . ';">';
                echo '<strong>' . esc_html( $action_label ) . '</strong> ' . esc_html( $approver_user->display_name );
                echo '</div>';
            }
        }

        if ( ! empty( $r['approver_note'] ) ) {
            echo '<div style="margin-bottom:8px;">';
            echo '<strong>' . esc_html__( 'Note:', 'sfs-hr' ) . '</strong><br>';
            echo '<div style="margin-top:4px;">' . nl2br( esc_html( $r['approver_note'] ) ) . '</div>';
            echo '</div>';
        }

        // Final Exit information
        if ( $type === 'final_exit' ) {
            $this->render_final_exit_info( $r );
        }

        echo '</td>';
        echo '</tr>';
    }

    /**
     * Render final exit information block
     *
     * @param array $r Resignation record
     * @return void
     */
    private function render_final_exit_info( array $r ): void {
        $fe_status = $r['final_exit_status'] ?? 'not_required';
        echo '<div style="margin-top:8px;padding:10px;background:#e3f2fd;border-radius:4px;">';
        echo '<strong>' . esc_html__( 'Final Exit Information', 'sfs-hr' ) . '</strong><br>';
        echo '<div style="margin-top:4px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;">';

        echo '<div><strong>' . esc_html__( 'Status:', 'sfs-hr' ) . '</strong> ' . esc_html( ucwords( str_replace( '_', ' ', $fe_status ) ) ) . '</div>';

        if ( ! empty( $r['final_exit_number'] ) ) {
            echo '<div><strong>' . esc_html__( 'Exit Number:', 'sfs-hr' ) . '</strong> ' . esc_html( $r['final_exit_number'] ) . '</div>';
        }

        if ( ! empty( $r['government_reference'] ) ) {
            echo '<div><strong>' . esc_html__( 'Gov. Reference:', 'sfs-hr' ) . '</strong> ' . esc_html( $r['government_reference'] ) . '</div>';
        }

        if ( ! empty( $r['expected_country_exit_date'] ) ) {
            echo '<div><strong>' . esc_html__( 'Expected Exit:', 'sfs-hr' ) . '</strong> ' . esc_html( $r['expected_country_exit_date'] ) . '</div>';
        }

        if ( ! empty( $r['actual_exit_date'] ) ) {
            echo '<div><strong>' . esc_html__( 'Actual Exit:', 'sfs-hr' ) . '</strong> ' . esc_html( $r['actual_exit_date'] ) . '</div>';
        }

        if ( ! empty( $r['final_exit_date'] ) ) {
            echo '<div><strong>' . esc_html__( 'Exit Issue Date:', 'sfs-hr' ) . '</strong> ' . esc_html( $r['final_exit_date'] ) . '</div>';
        }

        echo '</div></div>';
    }

    /**
     * Render resignation status badge
     *
     * @param string $status         Resignation status
     * @param int    $approval_level Current approval level
     * @return string HTML for the status badge
     */
    private function resignation_status_badge( string $status, int $approval_level = 1 ): string {
        $colors = [
            'pending'   => '#f0ad4e',
            'approved'  => '#5cb85c',
            'rejected'  => '#d9534f',
            'cancelled' => '#6c757d',
        ];

        $color = $colors[ $status ] ?? '#777';

        // Make status clearer for pending resignations
        $label = ucfirst( $status );
        if ( $status === 'pending' ) {
            if ( $approval_level === 1 ) {
                $label = __( 'Pending - Manager', 'sfs-hr' );
            } elseif ( $approval_level === 2 ) {
                $label = __( 'Pending - HR', 'sfs-hr' );
            } elseif ( $approval_level === 3 ) {
                $label = __( 'Pending - Finance', 'sfs-hr' );
            } else {
                $label = __( 'Pending', 'sfs-hr' );
            }
        }

        return sprintf(
            '<span style="background:%s;color:#fff;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:500;white-space:nowrap;">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }
}
