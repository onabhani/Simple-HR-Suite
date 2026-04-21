<?php
namespace SFS\HR\Modules\Expenses\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Core\Helpers;

/**
 * Expense_Service
 *
 * M10.1 + M10.2 — Expense claim lifecycle.
 *
 * Statuses:
 *   draft → submitted → pending_manager → pending_finance → approved → paid
 *   Any pending state can transition to `rejected` or `cancelled`.
 *
 * Approval rules (configurable via option `sfs_hr_expense_settings`):
 *   - manager_threshold (default 1000): amounts below go pending_manager only,
 *     above also route to pending_finance.
 *   - auto_approve_below (default 0): amounts below this auto-approve.
 *
 * @since M10
 */
class Expense_Service {

    const STATUS_DRAFT            = 'draft';
    const STATUS_PENDING_MANAGER  = 'pending_manager';
    const STATUS_PENDING_FINANCE  = 'pending_finance';
    const STATUS_APPROVED         = 'approved';
    const STATUS_PAID             = 'paid';
    const STATUS_REJECTED         = 'rejected';
    const STATUS_CANCELLED        = 'cancelled';

    const SETTINGS_OPTION = 'sfs_hr_expense_settings';

    private const ALLOWED_TRANSITIONS = [
        // Draft can go straight to APPROVED when auto_approve_below clamps the claim.
        self::STATUS_DRAFT           => [ self::STATUS_PENDING_MANAGER, self::STATUS_APPROVED, self::STATUS_CANCELLED ],
        self::STATUS_PENDING_MANAGER => [ self::STATUS_PENDING_FINANCE, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED ],
        self::STATUS_PENDING_FINANCE => [ self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED ],
        self::STATUS_APPROVED        => [ self::STATUS_PAID ],
        self::STATUS_PAID            => [],
        self::STATUS_REJECTED        => [],
        self::STATUS_CANCELLED       => [],
    ];

    public static function get_status_labels(): array {
        return [
            self::STATUS_DRAFT           => __( 'Draft', 'sfs-hr' ),
            self::STATUS_PENDING_MANAGER => __( 'Pending Manager', 'sfs-hr' ),
            self::STATUS_PENDING_FINANCE => __( 'Pending Finance', 'sfs-hr' ),
            self::STATUS_APPROVED        => __( 'Approved', 'sfs-hr' ),
            self::STATUS_PAID            => __( 'Paid', 'sfs-hr' ),
            self::STATUS_REJECTED        => __( 'Rejected', 'sfs-hr' ),
            self::STATUS_CANCELLED       => __( 'Cancelled', 'sfs-hr' ),
        ];
    }

    public static function get_settings(): array {
        $defaults = [
            'manager_threshold'  => 1000.0,
            'auto_approve_below' => 0.0,
            'currency'           => 'SAR',
        ];
        $stored = get_option( self::SETTINGS_OPTION, [] );
        return wp_parse_args( is_array( $stored ) ? $stored : [], $defaults );
    }

    /**
     * Create a draft claim with line items.
     *
     * @param array $data {
     *     @type int   $employee_id Required.
     *     @type int   $advance_id  Optional — claim offsets this advance.
     *     @type string $title      Required.
     *     @type string $description Optional.
     *     @type string $currency   Default SAR.
     *     @type array  $items      [{ category_id, item_date, amount, description, receipt_media_id, merchant }]
     * }
     * @return array { success: bool, id?: int, total?: float, error?: string }
     */
    public static function create_draft( array $data ): array {
        global $wpdb;

        $employee_id = (int) ( $data['employee_id'] ?? 0 );
        $title       = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
        $items       = is_array( $data['items'] ?? null ) ? $data['items'] : [];

        if ( $employee_id <= 0 ) {
            return [ 'success' => false, 'error' => __( 'Employee is required.', 'sfs-hr' ) ];
        }
        if ( '' === $title ) {
            return [ 'success' => false, 'error' => __( 'Title is required.', 'sfs-hr' ) ];
        }
        if ( empty( $items ) ) {
            return [ 'success' => false, 'error' => __( 'At least one expense item is required.', 'sfs-hr' ) ];
        }

        $currency = sanitize_text_field( (string) ( $data['currency'] ?? self::get_settings()['currency'] ) );
        $advance  = isset( $data['advance_id'] ) && (int) $data['advance_id'] > 0 ? (int) $data['advance_id'] : null;

        // Security: advance_id must belong to the claiming employee, otherwise
        // paying the claim would debit somebody else's advance balance.
        if ( null !== $advance ) {
            $adv = Advance_Service::get( $advance );
            if ( ! $adv || (int) $adv['employee_id'] !== $employee_id ) {
                return [ 'success' => false, 'error' => __( 'Invalid advance link.', 'sfs-hr' ) ];
            }
        }

        // Pre-validate items + compute total.
        $total = 0.0;
        $validated_items = [];
        foreach ( $items as $idx => $it ) {
            $prepared = self::validate_item( $it, $currency, $employee_id );
            if ( isset( $prepared['error'] ) ) {
                return [ 'success' => false, 'error' => sprintf( __( 'Item %d: %s', 'sfs-hr' ), $idx + 1, $prepared['error'] ) ];
            }
            $validated_items[] = $prepared;
            $total += $prepared['amount'];
        }

        if ( $total <= 0 ) {
            return [ 'success' => false, 'error' => __( 'Claim total must be greater than zero.', 'sfs-hr' ) ];
        }

        $now = current_time( 'mysql' );
        $request_number = Helpers::generate_reference_number( 'EXP', $wpdb->prefix . 'sfs_hr_expense_claims' );

        $wpdb->query( 'START TRANSACTION' );
        try {
            $inserted = $wpdb->insert( $wpdb->prefix . 'sfs_hr_expense_claims', [
                'request_number' => $request_number,
                'employee_id'    => $employee_id,
                'advance_id'     => $advance,
                'title'          => $title,
                'description'    => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
                'total_amount'   => $total,
                'currency'       => $currency,
                'status'         => self::STATUS_DRAFT,
                'created_at'     => $now,
                'updated_at'     => $now,
            ], [ '%s', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s' ] );

            if ( ! $inserted ) {
                throw new \RuntimeException( 'claim_insert_failed' );
            }
            $claim_id = (int) $wpdb->insert_id;

            foreach ( $validated_items as $item ) {
                $item['claim_id']   = $claim_id;
                $item['created_at'] = $now;
                $item['updated_at'] = $now;
                $item_inserted = $wpdb->insert( $wpdb->prefix . 'sfs_hr_expense_items', $item );
                if ( ! $item_inserted ) {
                    throw new \RuntimeException( 'item_insert_failed' );
                }
            }

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'error' => __( 'Failed to save expense claim.', 'sfs-hr' ) ];
        }

        return [ 'success' => true, 'id' => $claim_id, 'total' => $total, 'request_number' => $request_number ];
    }

    /**
     * Validate a single line item. When $employee_id is supplied, receipt
     * attachments are verified to be uploaded by that employee's WP user
     * (unless the caller is an admin) to prevent receipt-media-ID injection
     * against other users' attachments.
     */
    private static function validate_item( array $item, string $claim_currency, int $employee_id = 0 ): array {
        $category_id = (int) ( $item['category_id'] ?? 0 );
        $amount      = (float) ( $item['amount'] ?? 0 );
        $date        = sanitize_text_field( (string) ( $item['item_date'] ?? '' ) );
        $description = sanitize_textarea_field( (string) ( $item['description'] ?? '' ) );
        $media_id    = isset( $item['receipt_media_id'] ) ? (int) $item['receipt_media_id'] : 0;
        $merchant    = sanitize_text_field( (string) ( $item['merchant'] ?? '' ) );

        if ( $category_id <= 0 ) {
            return [ 'error' => __( 'category_id is required.', 'sfs-hr' ) ];
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! checkdate(
            (int) substr( $date, 5, 2 ),
            (int) substr( $date, 8, 2 ),
            (int) substr( $date, 0, 4 )
        ) ) {
            return [ 'error' => __( 'item_date must be a valid YYYY-MM-DD date.', 'sfs-hr' ) ];
        }
        if ( $amount <= 0 ) {
            return [ 'error' => __( 'amount must be positive.', 'sfs-hr' ) ];
        }

        // H1 fix: enforce ownership of receipt attachment.
        if ( $media_id > 0 ) {
            $att = get_post( $media_id );
            if ( ! $att || 'attachment' !== $att->post_type ) {
                return [ 'error' => __( 'Invalid receipt attachment.', 'sfs-hr' ) ];
            }
            if ( ! current_user_can( 'sfs_hr.manage' ) && $employee_id > 0 ) {
                $owner_uid = self::employee_user_id( $employee_id );
                if ( ! $owner_uid || (int) $att->post_author !== $owner_uid ) {
                    return [ 'error' => __( 'You can only attach receipts you uploaded.', 'sfs-hr' ) ];
                }
            }
        }

        $category = Expense_Category_Service::get( $category_id );
        if ( ! $category || empty( $category['is_active'] ) ) {
            return [ 'error' => __( 'Category is not active.', 'sfs-hr' ) ];
        }
        if ( ! empty( $category['receipt_required'] ) && $media_id <= 0 ) {
            return [ 'error' => sprintf( __( 'Receipt required for category "%s".', 'sfs-hr' ), $category['name'] ) ];
        }
        if ( ! empty( $category['per_claim_limit'] ) && $amount > (float) $category['per_claim_limit'] ) {
            return [ 'error' => sprintf(
                /* translators: 1: amount, 2: limit */
                __( 'Amount %1$s exceeds the per-claim limit of %2$s for this category.', 'sfs-hr' ),
                number_format( $amount, 2 ),
                number_format( (float) $category['per_claim_limit'], 2 )
            ) ];
        }

        return [
            'category_id'     => $category_id,
            'item_date'       => $date,
            'amount'          => $amount,
            'currency'        => sanitize_text_field( (string) ( $item['currency'] ?? $claim_currency ) ),
            'description'     => $description,
            'receipt_media_id'=> $media_id > 0 ? $media_id : null,
            'merchant'        => $merchant,
            'status'          => 'pending',
        ];
    }

    /**
     * Submit a draft claim for approval.
     */
    public static function submit( int $claim_id ): array {
        $claim = self::get( $claim_id );
        if ( ! $claim ) {
            return [ 'success' => false, 'error' => __( 'Claim not found.', 'sfs-hr' ) ];
        }
        if ( self::STATUS_DRAFT !== $claim['status'] ) {
            return [ 'success' => false, 'error' => __( 'Only draft claims can be submitted.', 'sfs-hr' ) ];
        }

        $settings = self::get_settings();

        // Auto-approve tiny claims?
        if ( $claim['total_amount'] <= (float) $settings['auto_approve_below'] && $settings['auto_approve_below'] > 0 ) {
            return self::set_status( $claim_id, self::STATUS_APPROVED, [
                'approved_amount'     => $claim['total_amount'],
                'current_approver_id' => null,
                'decided_at'          => current_time( 'mysql' ),
            ] );
        }

        return self::set_status( $claim_id, self::STATUS_PENDING_MANAGER, [
            'submitted_at' => current_time( 'mysql' ),
            'approval_tier' => 1,
        ] );
    }

    /**
     * Manager decision. If amount exceeds manager_threshold and decision=='approved',
     * claim advances to pending_finance instead of approved.
     *
     * Authorization:
     *  - Admins with sfs_hr.manage can always decide.
     *  - Department managers can only decide claims for employees in their department.
     *  - Approver must not be the claim's employee (self-approval blocked).
     */
    public static function manager_decide( int $claim_id, string $decision, int $approver_id, string $note = '', ?float $approved_amount = null ): array {
        if ( ! in_array( $decision, [ 'approved', 'rejected' ], true ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid decision.', 'sfs-hr' ) ];
        }

        $claim = self::get( $claim_id );
        if ( ! $claim ) {
            return [ 'success' => false, 'error' => __( 'Claim not found.', 'sfs-hr' ) ];
        }
        if ( self::STATUS_PENDING_MANAGER !== $claim['status'] ) {
            return [ 'success' => false, 'error' => __( 'Claim is not pending manager review.', 'sfs-hr' ) ];
        }

        // Self-approval guard (C1/C2).
        $emp_uid = self::employee_user_id( (int) $claim['employee_id'] );
        if ( $emp_uid && $emp_uid === $approver_id ) {
            return [ 'success' => false, 'error' => __( 'You cannot approve your own claim.', 'sfs-hr' ) ];
        }

        // Department-scope guard for non-admins (C1).
        if ( ! user_can( $approver_id, 'sfs_hr.manage' ) && ! self::approver_in_scope_for_employee( $approver_id, (int) $claim['employee_id'] ) ) {
            return [ 'success' => false, 'error' => __( 'You can only review claims for employees in your department.', 'sfs-hr' ) ];
        }

        if ( 'rejected' === $decision ) {
            self::record_approval( 'claim', $claim_id, 1, 'manager', $approver_id, 'rejected', $note );
            return self::set_status( $claim_id, self::STATUS_REJECTED, [
                'rejection_reason' => sanitize_textarea_field( $note ),
                'decided_at'       => current_time( 'mysql' ),
            ] );
        }

        // H3 fix: clamp approved_amount to [0, total_amount].
        $total_amount     = (float) $claim['total_amount'];
        $effective_amount = $approved_amount ?? $total_amount;
        if ( $effective_amount < 0 || $effective_amount > $total_amount ) {
            return [ 'success' => false, 'error' => __( 'Approved amount must be between 0 and the claim total.', 'sfs-hr' ) ];
        }

        $settings = self::get_settings();

        self::record_approval( 'claim', $claim_id, 1, 'manager', $approver_id, 'approved', $note );

        // Route above threshold to finance; otherwise finalize.
        if ( $effective_amount > (float) $settings['manager_threshold'] ) {
            return self::set_status( $claim_id, self::STATUS_PENDING_FINANCE, [
                'approval_tier' => 2,
            ] );
        }

        return self::set_status( $claim_id, self::STATUS_APPROVED, [
            'approved_amount' => $effective_amount,
            'decided_at'      => current_time( 'mysql' ),
        ] );
    }

    public static function finance_decide( int $claim_id, string $decision, int $approver_id, string $note = '', ?float $approved_amount = null ): array {
        if ( ! in_array( $decision, [ 'approved', 'rejected' ], true ) ) {
            return [ 'success' => false, 'error' => __( 'Invalid decision.', 'sfs-hr' ) ];
        }

        // Finance-tier decisions require an explicit finance or admin capability.
        // Department managers are not sufficient for this tier.
        if ( ! user_can( $approver_id, 'sfs_hr.finance' ) && ! user_can( $approver_id, 'sfs_hr.manage' ) ) {
            return [ 'success' => false, 'error' => __( 'You do not have permission to review finance-tier claims.', 'sfs-hr' ) ];
        }

        $claim = self::get( $claim_id );
        if ( ! $claim ) {
            return [ 'success' => false, 'error' => __( 'Claim not found.', 'sfs-hr' ) ];
        }
        if ( self::STATUS_PENDING_FINANCE !== $claim['status'] ) {
            return [ 'success' => false, 'error' => __( 'Claim is not pending finance review.', 'sfs-hr' ) ];
        }

        // Self-approval guard.
        $emp_uid = self::employee_user_id( (int) $claim['employee_id'] );
        if ( $emp_uid && $emp_uid === $approver_id ) {
            return [ 'success' => false, 'error' => __( 'You cannot approve your own claim.', 'sfs-hr' ) ];
        }

        if ( 'rejected' === $decision ) {
            self::record_approval( 'claim', $claim_id, 2, 'finance', $approver_id, 'rejected', $note );
            return self::set_status( $claim_id, self::STATUS_REJECTED, [
                'rejection_reason' => sanitize_textarea_field( $note ),
                'decided_at'       => current_time( 'mysql' ),
            ] );
        }

        // H3 fix: clamp approved_amount to [0, total_amount].
        $total_amount     = (float) $claim['total_amount'];
        $effective_amount = $approved_amount ?? $total_amount;
        if ( $effective_amount < 0 || $effective_amount > $total_amount ) {
            return [ 'success' => false, 'error' => __( 'Approved amount must be between 0 and the claim total.', 'sfs-hr' ) ];
        }

        self::record_approval( 'claim', $claim_id, 2, 'finance', $approver_id, 'approved', $note );

        return self::set_status( $claim_id, self::STATUS_APPROVED, [
            'approved_amount' => $effective_amount,
            'decided_at'      => current_time( 'mysql' ),
        ] );
    }

    /**
     * Mark an approved claim as paid. Offsets against a linked advance if present.
     */
    public static function mark_paid( int $claim_id, string $reference = '', int $paid_by = 0 ): array {
        $claim = self::get( $claim_id );
        if ( ! $claim ) {
            return [ 'success' => false, 'error' => __( 'Claim not found.', 'sfs-hr' ) ];
        }
        if ( self::STATUS_APPROVED !== $claim['status'] ) {
            return [ 'success' => false, 'error' => __( 'Only approved claims can be marked paid.', 'sfs-hr' ) ];
        }

        // C2 fix — separation of duties: whoever approved the claim at any tier
        // must not also be the payer.
        if ( $paid_by > 0 ) {
            $emp_uid = self::employee_user_id( (int) $claim['employee_id'] );
            if ( $emp_uid && $emp_uid === $paid_by ) {
                return [ 'success' => false, 'error' => __( 'You cannot pay your own claim.', 'sfs-hr' ) ];
            }
            if ( self::approver_already_acted( 'claim', $claim_id, $paid_by ) ) {
                return [ 'success' => false, 'error' => __( 'Separation of duties: the approver cannot also record the payment.', 'sfs-hr' ) ];
            }
        }

        $result = self::set_status( $claim_id, self::STATUS_PAID, [
            'paid_at'           => current_time( 'mysql' ),
            'payment_reference' => sanitize_text_field( $reference ),
        ] );
        if ( ! ( $result['success'] ?? false ) ) {
            return $result;
        }

        // Offset advance outstanding balance if the claim is linked to one.
        if ( ! empty( $claim['advance_id'] ) ) {
            Advance_Service::offset_from_claim(
                (int) $claim['advance_id'],
                (float) ( $claim['approved_amount'] ?? $claim['total_amount'] )
            );
        }

        return $result;
    }

    public static function cancel( int $claim_id ): array {
        $claim = self::get( $claim_id );
        if ( ! $claim ) {
            return [ 'success' => false, 'error' => __( 'Claim not found.', 'sfs-hr' ) ];
        }
        $terminal = [ self::STATUS_APPROVED, self::STATUS_PAID, self::STATUS_REJECTED, self::STATUS_CANCELLED ];
        if ( in_array( $claim['status'], $terminal, true ) ) {
            return [ 'success' => false, 'error' => __( 'Claim cannot be cancelled from the current status.', 'sfs-hr' ) ];
        }
        return self::set_status( $claim_id, self::STATUS_CANCELLED, [
            'decided_at' => current_time( 'mysql' ),
        ] );
    }

    // ── Reads ───────────────────────────────────────────────────────────────

    public static function get( int $claim_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_claims';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $claim_id ), ARRAY_A );
        return $row ?: null;
    }

    public static function get_items( int $claim_id ): array {
        global $wpdb;
        $items = $wpdb->prefix . 'sfs_hr_expense_items';
        $cats  = $wpdb->prefix . 'sfs_hr_expense_categories';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, c.name AS category_name, c.code AS category_code
             FROM {$items} i
             LEFT JOIN {$cats} c ON c.id = i.category_id
             WHERE i.claim_id = %d
             ORDER BY i.item_date ASC, i.id ASC",
            $claim_id
        ), ARRAY_A ) ?: [];
    }

    public static function list_for_employee( int $employee_id, int $limit = 50 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_claims';
        $limit = max( 1, min( 500, $limit ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE employee_id = %d ORDER BY id DESC LIMIT %d",
            $employee_id,
            $limit
        ), ARRAY_A ) ?: [];
    }

    /**
     * List pending claims visible to $approver_user_id. Admins with sfs_hr.manage
     * see everything; department managers only see their own department's
     * claims. Finance-tier requests also require a finance capability holder.
     */
    public static function list_pending_for_approver( int $approver_user_id, string $status = self::STATUS_PENDING_MANAGER ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_claims';
        $emp   = $wpdb->prefix . 'sfs_hr_employees';

        if ( ! in_array( $status, [ self::STATUS_PENDING_MANAGER, self::STATUS_PENDING_FINANCE ], true ) ) {
            $status = self::STATUS_PENDING_MANAGER;
        }

        $is_admin = user_can( $approver_user_id, 'sfs_hr.manage' );

        // Finance-tier listing: requires a finance-capable or admin user.
        // Department managers without finance capability must not see
        // pending_finance claims regardless of their department scope.
        if ( self::STATUS_PENDING_FINANCE === $status
            && ! $is_admin
            && ! user_can( $approver_user_id, 'sfs_hr.finance' )
        ) {
            return [];
        }

        // Non-admins are scoped to departments they manage.
        if ( $is_admin ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT c.*, e.first_name, e.last_name, e.employee_code, e.dept_id
                 FROM {$table} c
                 INNER JOIN {$emp} e ON e.id = c.employee_id
                 WHERE c.status = %s
                 ORDER BY c.submitted_at ASC",
                $status
            ), ARRAY_A ) ?: [];
        }

        $dept_ids = self::manager_dept_ids( $approver_user_id );
        if ( empty( $dept_ids ) ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $dept_ids ), '%d' ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, e.first_name, e.last_name, e.employee_code, e.dept_id
             FROM {$table} c
             INNER JOIN {$emp} e ON e.id = c.employee_id
             WHERE c.status = %s
               AND e.dept_id IN ({$placeholders})
             ORDER BY c.submitted_at ASC",
            $status, ...$dept_ids
        ), ARRAY_A ) ?: [];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private static function set_status( int $claim_id, string $new_status, array $extra = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_expense_claims';

        $current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $claim_id ) );
        if ( ! $current ) {
            return [ 'success' => false, 'error' => __( 'Claim not found.', 'sfs-hr' ) ];
        }

        $allowed = self::ALLOWED_TRANSITIONS[ $current ] ?? [];
        if ( ! in_array( $new_status, $allowed, true ) ) {
            return [ 'success' => false, 'error' => sprintf( __( 'Cannot transition %1$s → %2$s.', 'sfs-hr' ), $current, $new_status ) ];
        }

        $data = array_merge( [
            'status'     => $new_status,
            'updated_at' => current_time( 'mysql' ),
        ], $extra );

        $ok = $wpdb->update( $table, $data, [ 'id' => $claim_id ] );
        if ( false === $ok ) {
            return [ 'success' => false, 'error' => __( 'DB update failed.', 'sfs-hr' ) ];
        }

        do_action( 'sfs_hr_expense_claim_status_changed', $claim_id, (string) $current, $new_status );
        if ( self::STATUS_PENDING_MANAGER === $new_status && self::STATUS_DRAFT === $current ) {
            do_action( 'sfs_hr_expense_claim_submitted', $claim_id, self::get( $claim_id ) ?? [] );
        }

        return [ 'success' => true, 'status' => $new_status ];
    }

    private static function record_approval( string $entity_type, int $entity_id, int $tier, string $role, int $approver_id, string $decision, string $note ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'sfs_hr_expense_approvals', [
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'tier'        => $tier,
            'role'        => $role,
            'approver_id' => $approver_id,
            'decision'    => $decision,
            'decided_at'  => current_time( 'mysql' ),
            'note'        => sanitize_textarea_field( $note ),
            'created_at'  => current_time( 'mysql' ),
        ], [ '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' ] );
    }

    // ── Authorization helpers ───────────────────────────────────────────────

    /**
     * Resolve an employee's linked WP user ID.
     */
    public static function employee_user_id( int $employee_id ): ?int {
        global $wpdb;
        $uid = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d LIMIT 1",
            $employee_id
        ) );
        return $uid ? (int) $uid : null;
    }

    /**
     * Department IDs managed by the given user (per sfs_hr_departments.manager_user_id).
     */
    public static function manager_dept_ids( int $user_id ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_departments WHERE manager_user_id = %d AND active = 1",
            $user_id
        ) );
        return array_map( 'intval', (array) $rows );
    }

    /**
     * True when $approver_user_id manages the department of $employee_id.
     */
    public static function approver_in_scope_for_employee( int $approver_user_id, int $employee_id ): bool {
        global $wpdb;
        $dept_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT dept_id FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d LIMIT 1",
            $employee_id
        ) );
        if ( $dept_id <= 0 ) {
            return false;
        }
        return in_array( $dept_id, self::manager_dept_ids( $approver_user_id ), true );
    }

    /**
     * True when $user_id has already recorded an approval decision on the
     * given entity. Used to enforce separation of duties (approver ≠ payer).
     */
    public static function approver_already_acted( string $entity_type, int $entity_id, int $user_id ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_expense_approvals
             WHERE entity_type = %s AND entity_id = %d AND approver_id = %d",
            $entity_type, $entity_id, $user_id
        ) );
        return $count > 0;
    }
}
