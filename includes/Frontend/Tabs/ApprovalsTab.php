<?php
/**
 * Approvals Tab — Pending leave and loan approvals.
 *
 * Managers see their department's pending leave requests.
 * HR/GM/Admin see all pending requests at their approval level.
 *
 * @package SFS\HR\Frontend\Tabs
 */

namespace SFS\HR\Frontend\Tabs;

use SFS\HR\Frontend\Role_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ApprovalsTab implements TabInterface {

    public function render( array $emp, int $emp_id ): void {
        $user_id = get_current_user_id();
        $role    = Role_Resolver::resolve( $user_id );
        $level   = Role_Resolver::role_level( $role );

        if ( $level < 30 ) {
            echo '<p>' . esc_html__( 'You do not have permission to view this page.', 'sfs-hr' ) . '</p>';
            return;
        }

        global $wpdb;

        $leave_req_table  = $wpdb->prefix . 'sfs_hr_leave_requests';
        $leave_type_table = $wpdb->prefix . 'sfs_hr_leave_types';
        $loans_table      = $wpdb->prefix . 'sfs_hr_loans';
        $emp_table        = $wpdb->prefix . 'sfs_hr_employees';
        $dept_table       = $wpdb->prefix . 'sfs_hr_departments';

        $dept_ids = Role_Resolver::get_manager_dept_ids( $user_id );

        // ── Pending Leave Requests ──
        $leave_where = [];
        $leave_params = [];

        if ( $role === 'manager' ) {
            // Manager: level 1 approvals for their departments.
            if ( empty( $dept_ids ) ) {
                $pending_leaves = [];
            } else {
                $placeholders = implode( ',', array_fill( 0, count( $dept_ids ), '%d' ) );
                $leave_where[] = "r.status = 'pending'";
                $leave_where[] = "r.approval_level <= 1";
                $leave_where[] = "e.dept_id IN ({$placeholders})";
                $leave_params = $dept_ids;
            }
        } elseif ( $role === 'hr' ) {
            // HR: level 2 approvals (all departments).
            $leave_where[] = "r.status = 'pending'";
            $leave_where[] = "r.approval_level >= 2";
        } else {
            // GM/Admin: all pending.
            $leave_where[] = "r.status = 'pending'";
        }

        if ( ! empty( $leave_where ) ) {
            $where_sql = implode( ' AND ', $leave_where );
            $query = "SELECT r.*, t.name AS type_name,
                             e.first_name, e.last_name, e.employee_code,
                             d.name AS dept_name
                      FROM {$leave_req_table} r
                      JOIN {$emp_table} e ON e.id = r.employee_id
                      LEFT JOIN {$leave_type_table} t ON t.id = r.type_id
                      LEFT JOIN {$dept_table} d ON d.id = e.dept_id
                      WHERE {$where_sql}
                      ORDER BY r.created_at ASC
                      LIMIT 100";

            if ( ! empty( $leave_params ) ) {
                $pending_leaves = $wpdb->get_results( $wpdb->prepare( $query, ...$leave_params ), ARRAY_A );
            } else {
                $pending_leaves = $wpdb->get_results( $query, ARRAY_A );
            }
        }

        $pending_leaves = $pending_leaves ?? [];

        // ── Pending Loan Requests ──
        $pending_loans = [];
        if ( $level >= 50 ) { // GM/Admin
            $pending_loans = $wpdb->get_results(
                "SELECT l.*, e.first_name, e.last_name, e.employee_code,
                        d.name AS dept_name
                 FROM {$loans_table} l
                 JOIN {$emp_table} e ON e.id = l.employee_id
                 LEFT JOIN {$dept_table} d ON d.id = e.dept_id
                 WHERE l.status IN ('pending_gm','pending_finance')
                 ORDER BY l.created_at ASC
                 LIMIT 100",
                ARRAY_A
            );
        } elseif ( $role === 'hr' ) {
            // HR sees pending_finance.
            $pending_loans = $wpdb->get_results(
                "SELECT l.*, e.first_name, e.last_name, e.employee_code,
                        d.name AS dept_name
                 FROM {$loans_table} l
                 JOIN {$emp_table} e ON e.id = l.employee_id
                 LEFT JOIN {$dept_table} d ON d.id = e.dept_id
                 WHERE l.status = 'pending_finance'
                 ORDER BY l.created_at ASC
                 LIMIT 100",
                ARRAY_A
            );
        }

        $total_pending = count( $pending_leaves ) + count( $pending_loans );

        // ── Render ──
        $this->render_header( $total_pending );
        $this->render_kpis( count( $pending_leaves ), count( $pending_loans ) );

        // Category filter tabs.
        $this->render_filter_tabs( count( $pending_leaves ), count( $pending_loans ) );

        // Bulk approve button.
        if ( $total_pending > 0 ) {
            $this->render_bulk_actions();
        }

        if ( ! empty( $pending_leaves ) ) {
            $this->render_leave_approvals( $pending_leaves, $role );
        }

        if ( ! empty( $pending_loans ) ) {
            $this->render_loan_approvals( $pending_loans, $role );
        }

        if ( $total_pending === 0 ) {
            $this->render_empty_state();
        }

        if ( $total_pending > 0 ) {
            $this->render_filter_sort_js();
        }
    }

    private function render_header( int $total ): void {
        echo '<div class="sfs-section">';
        echo '<h2 class="sfs-section-title" data-i18n-key="pending_approvals">' . esc_html__( 'Pending Approvals', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-section-subtitle">'
            . esc_html( sprintf( _n( '%d item pending', '%d items pending', $total, 'sfs-hr' ), $total ) )
            . '</p>';
        echo '</div>';
    }

    private function render_kpis( int $leaves, int $loans ): void {
        echo '<div class="sfs-kpi-grid">';

        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#fef3c7;"><svg viewBox="0 0 24 24" stroke="#f59e0b" fill="none" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="leave_requests">' . esc_html__( 'Leave Requests', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $leaves . '</div>';
        echo '</div>';

        echo '<div class="sfs-kpi-card">';
        echo '<div class="sfs-kpi-icon" style="background:#eff6ff;"><svg viewBox="0 0 24 24" stroke="#3b82f6" fill="none" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>';
        echo '<div class="sfs-kpi-label" data-i18n-key="loan_requests">' . esc_html__( 'Loan Requests', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-kpi-value">' . $loans . '</div>';
        echo '</div>';

        echo '</div>';
    }

    private function render_leave_approvals( array $requests, string $role ): void {
        echo '<div class="sfs-section" style="margin-top:8px;">';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0 0 12px;" data-i18n-key="leave_approvals">'
            . esc_html__( 'Leave Requests', 'sfs-hr' ) . '</h3>';
        echo '</div>';

        $action_url = admin_url( 'admin-post.php' );

        foreach ( $requests as $r ) {
            $name    = esc_html( trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) ) );
            $code    = esc_html( $r['employee_code'] ?? '' );
            $dept    = esc_html( $r['dept_name'] ?? '—' );
            $type    = esc_html( $r['type_name'] ?? '—' );
            $start   = esc_html( $r['start_date'] ?? '' );
            $end     = esc_html( $r['end_date'] ?? '' );
            $period  = ( $start && $end && $end !== $start ) ? ( $start . ' → ' . $end ) : $start;
            $reason  = esc_html( $r['reason'] ?? '' );
            $req_num = esc_html( $r['request_number'] ?? '' );
            $days    = (int) ( $r['days'] ?? 1 );
            if ( $days <= 0 && $start && $end ) {
                $diff = strtotime( $end ) - strtotime( $start );
                $days = $diff > 0 ? (int) floor( $diff / DAY_IN_SECONDS ) + 1 : 1;
            }
            $req_id = (int) ( $r['id'] ?? 0 );
            $level_text = (int) ( $r['approval_level'] ?? 1 ) <= 1
                ? __( 'Manager Approval', 'sfs-hr' )
                : __( 'HR Approval', 'sfs-hr' );

            echo '<div class="sfs-card sfs-approval-card" data-category="leave" data-dept="' . esc_attr( $r['dept_name'] ?? '' ) . '" style="margin-bottom:12px;">';
            echo '<div class="sfs-card-body">';

            // Header row with checkbox.
            echo '<div class="sfs-approval-header">';
            echo '<label style="display:flex;align-items:center;gap:8px;flex:1;cursor:pointer;">';
            echo '<input type="checkbox" class="sfs-bulk-check" data-type="leave" data-id="' . $req_id . '" style="width:18px;height:18px;accent-color:var(--sfs-primary,#0284c7);">';
            echo '<div>';
            echo '<strong style="font-size:15px;">' . $name . '</strong>';
            echo '<div style="font-size:13px;color:var(--sfs-text-muted);margin-top:2px;">' . $code . ' &middot; ' . $dept . '</div>';
            echo '</div>';
            echo '</label>';
            echo '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">';
            echo '<span class="sfs-badge sfs-badge--pending">' . esc_html( $level_text ) . '</span>';
            echo '<span class="sfs-badge sfs-badge--info" style="font-size:11px;">' . $type . '</span>';
            echo '</div>';
            echo '</div>';

            // Duration circle badge + details.
            echo '<div class="sfs-approval-details">';
            echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">';
            echo '<div style="width:48px;height:48px;border-radius:50%;background:var(--sfs-primary-light,#e0f2fe);display:flex;align-items:center;justify-content:center;flex-shrink:0;">';
            echo '<span style="font-size:18px;font-weight:800;color:var(--sfs-primary,#0284c7);">' . $days . '</span>';
            echo '</div>';
            echo '<div>';
            echo '<div style="font-size:14px;font-weight:600;color:var(--sfs-text);">' . $period . '</div>';
            echo '<div style="font-size:12px;color:var(--sfs-text-muted);">' . $days . ' ' . esc_html( _n( 'day', 'days', $days, 'sfs-hr' ) ) . '</div>';
            echo '</div>';
            echo '</div>';
            if ( $req_num ) {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Ref #', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . $req_num . '</span></div>';
            }
            if ( $reason ) {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Reason', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . $reason . '</span></div>';
            }
            echo '</div>';

            // Action buttons.
            echo '<div class="sfs-approval-actions">';

            // Approve form.
            echo '<form method="post" action="' . esc_url( $action_url ) . '" style="display:inline;">';
            wp_nonce_field( 'sfs_hr_leave_approve_' . $req_id, '_wpnonce', false );
            echo '<input type="hidden" name="action" value="sfs_hr_leave_approve" />';
            echo '<input type="hidden" name="request_id" value="' . $req_id . '" />';
            echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) . '" />';
            echo '<button type="submit" class="sfs-btn sfs-btn--success sfs-btn--sm">';
            echo '<svg viewBox="0 0 24 24" style="width:14px;height:14px;margin-right:4px;"><polyline points="20 6 9 17 4 12" stroke="currentColor" fill="none" stroke-width="2.5"/></svg>';
            echo esc_html__( 'Approve', 'sfs-hr' );
            echo '</button>';
            echo '</form>';

            // Reject form.
            echo '<form method="post" action="' . esc_url( $action_url ) . '" style="display:inline;" class="sfs-reject-form">';
            wp_nonce_field( 'sfs_hr_leave_reject_' . $req_id, '_wpnonce', false );
            echo '<input type="hidden" name="action" value="sfs_hr_leave_reject" />';
            echo '<input type="hidden" name="request_id" value="' . $req_id . '" />';
            echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) . '" />';
            echo '<input type="hidden" name="rejection_reason" value="" class="sfs-reject-reason-input" />';
            echo '<button type="button" class="sfs-btn sfs-btn--danger sfs-btn--sm sfs-reject-btn">';
            echo '<svg viewBox="0 0 24 24" style="width:14px;height:14px;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2.5"/><line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2.5"/></svg>';
            echo esc_html__( 'Reject', 'sfs-hr' );
            echo '</button>';
            echo '</form>';

            echo '</div>'; // .sfs-approval-actions

            echo '</div></div>'; // .sfs-card-body, .sfs-card
        }
    }

    private function render_loan_approvals( array $loans, string $role ): void {
        echo '<div class="sfs-section" style="margin-top:16px;">';
        echo '<h3 style="font-size:15px;font-weight:700;color:var(--sfs-text);margin:0 0 12px;" data-i18n-key="loan_approvals">'
            . esc_html__( 'Loan Requests', 'sfs-hr' ) . '</h3>';
        echo '</div>';

        $action_url = admin_url( 'admin-post.php' );

        foreach ( $loans as $l ) {
            $name   = esc_html( trim( ( $l['first_name'] ?? '' ) . ' ' . ( $l['last_name'] ?? '' ) ) );
            $code   = esc_html( $l['employee_code'] ?? '' );
            $dept   = esc_html( $l['dept_name'] ?? '—' );
            $amount = number_format( (float) ( $l['amount_requested'] ?? 0 ), 2 );
            $installments = (int) ( $l['installments_requested'] ?? 0 );
            $loan_num = esc_html( $l['loan_number'] ?? '' );
            $reason = esc_html( $l['reason'] ?? '' );
            $loan_id = (int) ( $l['id'] ?? 0 );
            $status = $l['status'] ?? '';
            $is_gm_stage = ( $status === 'pending_gm' );

            $stage_text = $is_gm_stage
                ? __( 'GM Approval', 'sfs-hr' )
                : __( 'Finance Approval', 'sfs-hr' );

            echo '<div class="sfs-card sfs-approval-card" data-category="loan" data-dept="' . esc_attr( $l['dept_name'] ?? '' ) . '" style="margin-bottom:12px;">';
            echo '<div class="sfs-card-body">';

            // Header with checkbox.
            echo '<div class="sfs-approval-header">';
            echo '<label style="display:flex;align-items:center;gap:8px;flex:1;cursor:pointer;">';
            echo '<input type="checkbox" class="sfs-bulk-check" data-type="loan" data-id="' . $loan_id . '" style="width:18px;height:18px;accent-color:var(--sfs-primary,#0284c7);">';
            echo '<div>';
            echo '<strong style="font-size:15px;">' . $name . '</strong>';
            echo '<div style="font-size:13px;color:var(--sfs-text-muted);margin-top:2px;">' . $code . ' &middot; ' . $dept . '</div>';
            echo '</div>';
            echo '</label>';
            echo '<span class="sfs-badge sfs-badge--pending">' . esc_html( $stage_text ) . '</span>';
            echo '</div>';

            // Amount circle badge + details.
            echo '<div class="sfs-approval-details">';
            echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">';
            echo '<div style="width:48px;height:48px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">';
            echo '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#3b82f6" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
            echo '</div>';
            echo '<div>';
            echo '<div style="font-size:18px;font-weight:800;color:var(--sfs-text);">' . esc_html( $amount ) . '</div>';
            if ( $installments > 0 ) {
                echo '<div style="font-size:12px;color:var(--sfs-text-muted);">' . $installments . ' ' . esc_html__( 'months', 'sfs-hr' ) . '</div>';
            }
            echo '</div>';
            echo '</div>';
            if ( $loan_num ) {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Ref #', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . $loan_num . '</span></div>';
            }
            if ( $reason ) {
                echo '<div class="sfs-detail-row"><span class="sfs-detail-label">' . esc_html__( 'Reason', 'sfs-hr' ) . '</span><span class="sfs-detail-value">' . $reason . '</span></div>';
            }
            echo '</div>';

            // Actions — only show if user has the right role for this stage.
            $can_act = false;
            if ( $is_gm_stage && in_array( $role, [ 'gm', 'admin' ], true ) ) {
                $can_act = true;
            } elseif ( ! $is_gm_stage && in_array( $role, [ 'hr', 'gm', 'admin' ], true ) ) {
                $can_act = true;
            }

            if ( $can_act ) {
                $approve_action = $is_gm_stage ? 'sfs_hr_loan_action' : 'sfs_hr_loan_action';
                $approve_nonce  = $is_gm_stage ? 'sfs_hr_loan_approve_gm_' . $loan_id : 'sfs_hr_loan_approve_finance_' . $loan_id;
                $approve_type   = $is_gm_stage ? 'approve_gm' : 'approve_finance';

                echo '<div class="sfs-approval-actions">';

                // Approve.
                echo '<form method="post" action="' . esc_url( $action_url ) . '" style="display:inline;">';
                wp_nonce_field( $approve_nonce, '_wpnonce', false );
                echo '<input type="hidden" name="action" value="' . esc_attr( $approve_action ) . '" />';
                echo '<input type="hidden" name="loan_id" value="' . $loan_id . '" />';
                echo '<input type="hidden" name="loan_action" value="' . esc_attr( $approve_type ) . '" />';
                echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) . '" />';
                echo '<button type="submit" class="sfs-btn sfs-btn--success sfs-btn--sm">';
                echo '<svg viewBox="0 0 24 24" style="width:14px;height:14px;margin-right:4px;"><polyline points="20 6 9 17 4 12" stroke="currentColor" fill="none" stroke-width="2.5"/></svg>';
                echo esc_html__( 'Approve', 'sfs-hr' );
                echo '</button>';
                echo '</form>';

                // Reject.
                echo '<form method="post" action="' . esc_url( $action_url ) . '" style="display:inline;" class="sfs-reject-form">';
                wp_nonce_field( 'sfs_hr_loan_reject_' . $loan_id, '_wpnonce', false );
                echo '<input type="hidden" name="action" value="sfs_hr_loan_action" />';
                echo '<input type="hidden" name="loan_id" value="' . $loan_id . '" />';
                echo '<input type="hidden" name="loan_action" value="reject_loan" />';
                echo '<input type="hidden" name="rejection_reason" value="" class="sfs-reject-reason-input" />';
                echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) . '" />';
                echo '<button type="button" class="sfs-btn sfs-btn--danger sfs-btn--sm sfs-reject-btn">';
                echo '<svg viewBox="0 0 24 24" style="width:14px;height:14px;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2.5"/><line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2.5"/></svg>';
                echo esc_html__( 'Reject', 'sfs-hr' );
                echo '</button>';
                echo '</form>';

                echo '</div>';
            }

            echo '</div></div>';
        }

        // Rejection prompt JS.
        $this->render_reject_js();
    }

    private function render_filter_tabs( int $leave_count, int $loan_count ): void {
        $all_count = $leave_count + $loan_count;
        echo '<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">';
        echo '<button type="button" class="sfs-btn sfs-btn--primary sfs-approval-filter-btn" data-filter="all">'
            . esc_html__( 'All', 'sfs-hr' ) . ' (' . $all_count . ')</button>';
        echo '<button type="button" class="sfs-btn sfs-approval-filter-btn" data-filter="leave" style="background:var(--sfs-surface,#f9fafb);color:var(--sfs-text);border:1px solid var(--sfs-border,#e5e7eb);">'
            . esc_html__( 'Leave', 'sfs-hr' ) . ' (' . $leave_count . ')</button>';
        echo '<button type="button" class="sfs-btn sfs-approval-filter-btn" data-filter="loan" style="background:var(--sfs-surface,#f9fafb);color:var(--sfs-text);border:1px solid var(--sfs-border,#e5e7eb);">'
            . esc_html__( 'Loans', 'sfs-hr' ) . ' (' . $loan_count . ')</button>';
        echo '</div>';
    }

    private function render_bulk_actions(): void {
        echo '<div class="sfs-bulk-actions" id="sfs-bulk-actions" style="display:none;margin-bottom:16px;padding:12px 16px;background:var(--sfs-primary-light,#f0f9ff);border-radius:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
        echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:14px;font-weight:600;color:var(--sfs-text);">';
        echo '<input type="checkbox" id="sfs-bulk-select-all" style="width:18px;height:18px;accent-color:var(--sfs-primary,#0284c7);">';
        echo esc_html__( 'Select All', 'sfs-hr' );
        echo '</label>';
        echo '<span id="sfs-bulk-count" style="font-size:13px;color:var(--sfs-text-muted);">0 ' . esc_html__( 'selected', 'sfs-hr' ) . '</span>';
        echo '<div style="margin-left:auto;display:flex;gap:8px;">';
        echo '<button type="button" id="sfs-bulk-approve" class="sfs-btn sfs-btn--success sfs-btn--sm" disabled>';
        echo esc_html__( 'Approve Selected', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';
    }

    private function render_filter_sort_js(): void {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Category filter.
            var filterBtns = document.querySelectorAll('.sfs-approval-filter-btn');
            var cards = document.querySelectorAll('.sfs-approval-card');
            filterBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var filter = this.getAttribute('data-filter');
                    filterBtns.forEach(function(b) {
                        b.classList.remove('sfs-btn--primary');
                        b.style.background = 'var(--sfs-surface,#f9fafb)';
                        b.style.color = 'var(--sfs-text)';
                        b.style.border = '1px solid var(--sfs-border,#e5e7eb)';
                    });
                    this.classList.add('sfs-btn--primary');
                    this.style.background = '';
                    this.style.color = '';
                    this.style.border = '';
                    cards.forEach(function(card) {
                        if (filter === 'all' || card.getAttribute('data-category') === filter) {
                            card.style.display = '';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            });

            // Bulk select.
            var bulkBar = document.getElementById('sfs-bulk-actions');
            var selectAll = document.getElementById('sfs-bulk-select-all');
            var bulkCount = document.getElementById('sfs-bulk-count');
            var bulkApprove = document.getElementById('sfs-bulk-approve');
            var checks = document.querySelectorAll('.sfs-bulk-check');

            if (bulkBar && checks.length > 0) {
                bulkBar.style.display = 'flex';
            }

            function updateBulkCount() {
                var checked = document.querySelectorAll('.sfs-bulk-check:checked');
                if (bulkCount) bulkCount.textContent = checked.length + ' <?php echo esc_js( __( 'selected', 'sfs-hr' ) ); ?>';
                if (bulkApprove) bulkApprove.disabled = (checked.length === 0);
            }

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    var isChecked = this.checked;
                    checks.forEach(function(c) {
                        if (c.closest('.sfs-approval-card').style.display !== 'none') {
                            c.checked = isChecked;
                        }
                    });
                    updateBulkCount();
                });
            }

            checks.forEach(function(c) {
                c.addEventListener('change', updateBulkCount);
            });

            if (bulkApprove) {
                bulkApprove.addEventListener('click', function() {
                    var checked = document.querySelectorAll('.sfs-bulk-check:checked');
                    if (checked.length === 0) return;
                    if (!confirm('<?php echo esc_js( __( 'Approve all selected items?', 'sfs-hr' ) ); ?>')) return;

                    // Submit each approve form sequentially.
                    checked.forEach(function(c) {
                        var card = c.closest('.sfs-approval-card');
                        if (card) {
                            var approveBtn = card.querySelector('.sfs-btn--success[type="submit"]');
                            if (approveBtn) approveBtn.click();
                        }
                    });
                });
            }
        });
        </script>
        <?php
    }

    private function render_empty_state(): void {
        echo '<div class="sfs-card"><div class="sfs-empty-state">';
        echo '<div class="sfs-empty-state-icon"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" fill="none" stroke-width="1.5"/><polyline points="22 4 12 14.01 9 11.01" stroke="currentColor" fill="none" stroke-width="1.5"/></svg></div>';
        echo '<p class="sfs-empty-state-title">' . esc_html__( 'All caught up!', 'sfs-hr' ) . '</p>';
        echo '<p class="sfs-empty-state-text">' . esc_html__( 'There are no pending approvals at this time.', 'sfs-hr' ) . '</p>';
        echo '</div></div>';
    }

    private function render_reject_js(): void {
        static $rendered = false;
        if ( $rendered ) return;
        $rendered = true;

        echo '<script>document.addEventListener("DOMContentLoaded",function(){';
        echo 'document.querySelectorAll(".sfs-reject-btn").forEach(function(btn){';
        echo 'btn.addEventListener("click",function(){';
        echo 'var reason=prompt("' . esc_js( __( 'Please provide a reason for rejection:', 'sfs-hr' ) ) . '");';
        echo 'if(reason===null)return;';
        echo 'var form=this.closest(".sfs-reject-form");';
        echo 'form.querySelector(".sfs-reject-reason-input").value=reason;';
        echo 'form.submit();';
        echo '});});});</script>';
    }
}
