<?php
namespace SFS\HR\Modules\Loans\Rest;

use SFS\HR\Core\Rest\Rest_Response;
use SFS\HR\Modules\Loans\LoansModule;
use SFS\HR\Modules\Loans\Notifications as Loans_Notifications;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Loans REST API (M9.1)
 *
 * Fills the M9.1 coverage gap — the Loans module had admin and frontend
 * handlers but no REST layer at all. Parity with the admin flow:
 * pending_gm → pending_finance → active → completed, with side branches
 * rejected and cancelled. All status transitions are atomic (WHERE on
 * current status) to prevent the race conditions the admin pages
 * already guard against.
 *
 * Permissions match existing admin rules:
 *   - Employee self-request: any authenticated user mapped to an employee
 *   - GM approval:           LoansModule::current_user_can_approve_as_gm()
 *   - Finance approval:      LoansModule::current_user_can_approve_as_finance()
 *   - Cancel / skip:         sfs_hr.manage (admin) or approver roles
 *   - List / get:            sfs_hr.view (own loans only for plain users)
 *
 * Notifications, audit events, and schedule generation mirror the admin
 * flow so external integrations see identical side effects whether a
 * loan moves through the UI or the API.
 *
 * @since M9.1
 */
class Loans_Rest {

	const NAMESPACE_V1 = 'sfs-hr/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		$ns = self::NAMESPACE_V1;

		register_rest_route( $ns, '/loans', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_loans' ],
				'permission_callback' => [ self::class, 'can_read' ],
				'args'                => [
					'status'      => [ 'type' => 'string' ],
					'employee_id' => [ 'type' => 'integer' ],
					'page'        => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
					'per_page'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'create_loan' ],
				'permission_callback' => [ self::class, 'can_request' ],
				'args'                => [
					'employee_id'        => [ 'type' => 'integer', 'required' => true ],
					'principal_amount'   => [ 'type' => 'number',  'required' => true ],
					'installments_count' => [ 'type' => 'integer', 'required' => true ],
					'reason'             => [ 'type' => 'string',  'required' => true ],
				],
			],
		] );

		register_rest_route( $ns, '/loans/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'get_loan' ],
			'permission_callback' => [ self::class, 'can_read_single' ],
		] );

		register_rest_route( $ns, '/loans/(?P<id>\d+)/approve-gm', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'approve_gm' ],
			'permission_callback' => fn() => LoansModule::current_user_can_approve_as_gm(),
			'args'                => [
				'approved_gm_amount' => [ 'type' => 'number' ],
				'approved_gm_note'   => [ 'type' => 'string' ],
			],
		] );

		register_rest_route( $ns, '/loans/(?P<id>\d+)/approve-finance', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'approve_finance' ],
			'permission_callback' => fn() => LoansModule::current_user_can_approve_as_finance(),
			'args'                => [
				'principal_amount'       => [ 'type' => 'number',  'required' => true ],
				'installments_count'     => [ 'type' => 'integer', 'required' => true ],
				'first_due_month'        => [ 'type' => 'string',  'required' => true, 'description' => 'YYYY-MM' ],
				'approved_finance_note'  => [ 'type' => 'string' ],
			],
		] );

		register_rest_route( $ns, '/loans/(?P<id>\d+)/reject', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'reject_loan' ],
			'permission_callback' => fn() => LoansModule::current_user_can_approve_as_gm()
				|| LoansModule::current_user_can_approve_as_finance(),
			'args'                => [
				'rejection_reason' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( $ns, '/loans/(?P<id>\d+)/cancel', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'cancel_loan' ],
			'permission_callback' => fn() => LoansModule::current_user_can_approve_as_gm()
				|| current_user_can( 'sfs_hr.manage' ),
			'args'                => [
				'cancellation_reason' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		register_rest_route( $ns, '/loans/(?P<id>\d+)/payments', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'list_payments' ],
			'permission_callback' => [ self::class, 'can_read_single' ],
		] );

		register_rest_route( $ns, '/loans/(?P<id>\d+)/payments/(?P<seq>\d+)/skip', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'skip_payment' ],
			'permission_callback' => fn() => LoansModule::current_user_can_approve_as_finance()
				|| current_user_can( 'sfs_hr.manage' ),
			'args'                => [
				'reason' => [ 'type' => 'string' ],
			],
		] );
	}

	/* ───────── Permission callbacks ───────── */

	public static function can_read(): bool {
		return is_user_logged_in()
			&& ( current_user_can( 'sfs_hr.manage' )
				|| current_user_can( 'sfs_hr.view' )
				|| LoansModule::current_user_can_approve_as_gm()
				|| LoansModule::current_user_can_approve_as_finance() );
	}

	public static function can_read_single( \WP_REST_Request $req ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'sfs_hr.manage' )
			|| LoansModule::current_user_can_approve_as_gm()
			|| LoansModule::current_user_can_approve_as_finance() ) {
			return true;
		}

		// Employees can view their own loans.
		global $wpdb;
		$loan_id = (int) $req['id'];
		$owner   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT e.user_id
			 FROM {$wpdb->prefix}sfs_hr_loans l
			 INNER JOIN {$wpdb->prefix}sfs_hr_employees e ON e.id = l.employee_id
			 WHERE l.id = %d",
			$loan_id
		) );

		return $owner > 0 && $owner === get_current_user_id();
	}

	public static function can_request(): bool {
		return is_user_logged_in();
	}

	/* ───────── Handlers ───────── */

	public static function list_loans( \WP_REST_Request $req ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_loans';

		$paging = Rest_Response::parse_pagination( $req, 20, 100 );

		$where = [ '1=1' ];
		$args  = [];

		$status = (string) $req->get_param( 'status' );
		if ( $status && in_array( $status, self::valid_statuses(), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$employee_id = (int) $req->get_param( 'employee_id' );
		if ( $employee_id > 0 ) {
			$where[] = 'employee_id = %d';
			$args[]  = $employee_id;
		}

		// Non-admins are scoped to their own loans regardless of filter args.
		if ( ! current_user_can( 'sfs_hr.manage' )
			&& ! LoansModule::current_user_can_approve_as_gm()
			&& ! LoansModule::current_user_can_approve_as_finance() ) {
			$own_emp_id = self::employee_id_for_current_user();
			if ( $own_emp_id <= 0 ) {
				return Rest_Response::paginated( [], 0, $paging['page'], $paging['per_page'] );
			}
			$where[] = 'employee_id = %d';
			$args[]  = $own_emp_id;
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( empty( $args )
			? $wpdb->get_var( $count_sql )
			: $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) );

		$rows_args = array_merge( $args, [ $paging['per_page'], $paging['offset'] ] );
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$rows_args ), ARRAY_A );

		$data = array_map( [ self::class, 'format_loan' ], $rows ?: [] );

		return Rest_Response::paginated( $data, $total, $paging['page'], $paging['per_page'] );
	}

	public static function get_loan( \WP_REST_Request $req ) {
		global $wpdb;
		$id  = (int) $req['id'];
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}sfs_hr_loans WHERE id = %d",
			$id
		), ARRAY_A );

		if ( ! $row ) {
			return Rest_Response::error( 'not_found', __( 'Loan not found.', 'sfs-hr' ), 404 );
		}

		return Rest_Response::success( self::format_loan( $row ) );
	}

	public static function create_loan( \WP_REST_Request $req ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_loans';

		$employee_id = (int) $req->get_param( 'employee_id' );
		$principal   = (float) $req->get_param( 'principal_amount' );
		$count       = (int) $req->get_param( 'installments_count' );
		$reason      = trim( (string) $req->get_param( 'reason' ) );

		if ( $employee_id <= 0 || $principal <= 0 || $count <= 0 || $reason === '' ) {
			return Rest_Response::error( 'invalid_input', __( 'employee_id, principal_amount, installments_count and reason are required.', 'sfs-hr' ), 400 );
		}

		// Self-request vs admin-on-behalf: non-admins may only create for themselves.
		if ( ! current_user_can( 'sfs_hr.manage' ) && ! current_user_can( 'sfs_hr_loans_manage' ) ) {
			$own_emp_id = self::employee_id_for_current_user();
			if ( $own_emp_id !== $employee_id ) {
				return Rest_Response::error( 'forbidden', __( 'You can only request loans for yourself.', 'sfs-hr' ), 403 );
			}
		}

		// Verify employee exists and is active.
		$employee = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, user_id, dept_id, status FROM {$wpdb->prefix}sfs_hr_employees WHERE id = %d",
			$employee_id
		) );
		if ( ! $employee || $employee->status !== 'active' ) {
			return Rest_Response::error( 'invalid_employee', __( 'Employee not found or inactive.', 'sfs-hr' ), 400 );
		}

		if ( LoansModule::has_active_loans( $employee_id ) ) {
			return Rest_Response::error( 'active_loan_exists', __( 'Employee already has an active loan.', 'sfs-hr' ), 409 );
		}

		$installment_amount = round( $principal / $count, 2 );
		$now                = current_time( 'mysql' );
		$loan_number        = LoansModule::generate_loan_number();

		$inserted = $wpdb->insert( $table, [
			'loan_number'        => $loan_number,
			'employee_id'        => $employee_id,
			'department'         => $employee->dept_id ? (string) $employee->dept_id : null,
			'principal_amount'   => $principal,
			'installments_count' => $count,
			'installment_amount' => $installment_amount,
			'remaining_balance'  => $principal,
			'status'             => 'pending_gm',
			'reason'             => $reason,
			'request_source'     => 'rest_api',
			'created_by'         => get_current_user_id(),
			'created_at'         => $now,
			'updated_at'         => $now,
		] );

		if ( ! $inserted ) {
			return Rest_Response::error( 'insert_failed', __( 'Failed to create loan.', 'sfs-hr' ), 500 );
		}

		$loan_id = (int) $wpdb->insert_id;
		LoansModule::log_event( $loan_id, 'created', [ 'source' => 'rest_api', 'principal' => $principal ] );
		Loans_Notifications::notify_new_loan_request( $loan_id );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $loan_id ), ARRAY_A );
		return Rest_Response::success( self::format_loan( $row ), 201 );
	}

	public static function approve_gm( \WP_REST_Request $req ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_loans';
		$id    = (int) $req['id'];

		$original = $wpdb->get_row( $wpdb->prepare(
			"SELECT principal_amount, status FROM {$table} WHERE id = %d",
			$id
		) );
		if ( ! $original ) {
			return Rest_Response::error( 'not_found', __( 'Loan not found.', 'sfs-hr' ), 404 );
		}
		if ( $original->status !== 'pending_gm' ) {
			return Rest_Response::error( 'invalid_state', __( 'Loan is not pending GM approval.', 'sfs-hr' ), 409 );
		}

		$amount = $req->get_param( 'approved_gm_amount' );
		$amount = is_numeric( $amount ) ? (float) $amount : null;
		$note   = sanitize_textarea_field( (string) $req->get_param( 'approved_gm_note' ) );
		$now    = current_time( 'mysql' );

		$set_parts = [
			$wpdb->prepare( 'status = %s',          'pending_finance' ),
			$wpdb->prepare( 'approved_gm_by = %d',  get_current_user_id() ),
			$wpdb->prepare( 'approved_gm_at = %s',  $now ),
			$wpdb->prepare( 'approved_gm_note = %s', $note ),
			$wpdb->prepare( 'updated_at = %s',      $now ),
		];

		if ( $amount !== null && $amount > 0 ) {
			$set_parts[] = $wpdb->prepare( 'approved_gm_amount = %f', $amount );
			$set_parts[] = $wpdb->prepare( 'principal_amount = %f',   $amount );
		}

		$set_sql = implode( ', ', $set_parts );
		$result  = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET {$set_sql} WHERE id = %d AND status = 'pending_gm'",
			$id
		) );

		if ( $result === false || $result === 0 ) {
			return Rest_Response::error( 'update_failed', __( 'Could not approve loan. It may have already been actioned.', 'sfs-hr' ), 409 );
		}

		$log_meta = [ 'status' => 'pending_gm → pending_finance' ];
		if ( $amount !== null && abs( $amount - (float) $original->principal_amount ) > 0.01 ) {
			$log_meta['original_amount'] = $original->principal_amount;
			$log_meta['approved_amount'] = $amount;
		}
		if ( $note ) {
			$log_meta['note'] = $note;
		}

		LoansModule::log_event( $id, 'gm_approved', $log_meta );
		do_action( 'sfs_hr_loan_status_changed', $id, 'pending_gm', 'pending_finance' );
		Loans_Notifications::notify_gm_approved( $id );

		return self::get_loan( $req );
	}

	public static function approve_finance( \WP_REST_Request $req ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_loans';
		$id    = (int) $req['id'];

		$current = $wpdb->get_row( $wpdb->prepare(
			"SELECT status FROM {$table} WHERE id = %d",
			$id
		) );
		if ( ! $current ) {
			return Rest_Response::error( 'not_found', __( 'Loan not found.', 'sfs-hr' ), 404 );
		}
		if ( $current->status !== 'pending_finance' ) {
			return Rest_Response::error( 'invalid_state', __( 'Loan is not pending finance approval.', 'sfs-hr' ), 409 );
		}

		$principal    = (float) $req->get_param( 'principal_amount' );
		$installments = (int)   $req->get_param( 'installments_count' );
		$first_month  = trim( (string) $req->get_param( 'first_due_month' ) );
		$note         = sanitize_textarea_field( (string) $req->get_param( 'approved_finance_note' ) );

		if ( $principal <= 0 || $installments <= 0 || ! preg_match( '/^\d{4}-\d{2}$/', $first_month ) ) {
			return Rest_Response::error( 'invalid_input', __( 'principal_amount, installments_count and first_due_month (YYYY-MM) are required.', 'sfs-hr' ), 400 );
		}

		$installment_amount = round( $principal / $installments, 2 );
		$now                = current_time( 'mysql' );

		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET principal_amount = %f,
			     installments_count = %d,
			     installment_amount = %f,
			     remaining_balance = %f,
			     status = 'active',
			     approved_finance_by = %d,
			     approved_finance_at = %s,
			     approved_finance_note = %s,
			     updated_at = %s
			 WHERE id = %d AND status = 'pending_finance'",
			$principal,
			$installments,
			$installment_amount,
			$principal,
			get_current_user_id(),
			$now,
			$note,
			$now,
			$id
		) );

		if ( $result === false || $result === 0 ) {
			return Rest_Response::error( 'update_failed', __( 'Could not finalize loan.', 'sfs-hr' ), 409 );
		}

		self::generate_payment_schedule( $id, $first_month, $installments, $installment_amount );

		$meta = [ 'status' => 'pending_finance → active', 'principal' => $principal, 'installments' => $installments ];
		if ( $note ) {
			$meta['note'] = $note;
		}
		LoansModule::log_event( $id, 'finance_approved', $meta );
		do_action( 'sfs_hr_loan_status_changed', $id, 'pending_finance', 'active' );
		Loans_Notifications::notify_finance_approved( $id );

		return self::get_loan( $req );
	}

	public static function reject_loan( \WP_REST_Request $req ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_loans';
		$id    = (int) $req['id'];

		$current = $wpdb->get_row( $wpdb->prepare(
			"SELECT status FROM {$table} WHERE id = %d",
			$id
		) );
		if ( ! $current ) {
			return Rest_Response::error( 'not_found', __( 'Loan not found.', 'sfs-hr' ), 404 );
		}
		if ( ! in_array( $current->status, [ 'pending_gm', 'pending_finance' ], true ) ) {
			return Rest_Response::error( 'invalid_state', __( 'Only pending loans can be rejected.', 'sfs-hr' ), 409 );
		}

		$reason = trim( (string) $req->get_param( 'rejection_reason' ) );
		if ( $reason === '' ) {
			return Rest_Response::error( 'invalid_input', __( 'rejection_reason is required.', 'sfs-hr' ), 400 );
		}

		$old_status = $current->status;
		$now        = current_time( 'mysql' );

		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'rejected',
			     rejected_by = %d,
			     rejected_at = %s,
			     rejection_reason = %s,
			     updated_at = %s
			 WHERE id = %d AND status IN ('pending_gm', 'pending_finance')",
			get_current_user_id(),
			$now,
			$reason,
			$now,
			$id
		) );

		if ( $result === false || $result === 0 ) {
			return Rest_Response::error( 'update_failed', __( 'Could not reject loan.', 'sfs-hr' ), 409 );
		}

		LoansModule::log_event( $id, 'rejected', [ 'reason' => $reason ] );
		do_action( 'sfs_hr_loan_status_changed', $id, $old_status, 'rejected' );
		Loans_Notifications::notify_loan_rejected( $id );

		return self::get_loan( $req );
	}

	public static function cancel_loan( \WP_REST_Request $req ) {
		global $wpdb;
		$table          = $wpdb->prefix . 'sfs_hr_loans';
		$payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';
		$id             = (int) $req['id'];

		$current = $wpdb->get_row( $wpdb->prepare(
			"SELECT status, remaining_balance FROM {$table} WHERE id = %d",
			$id
		) );
		if ( ! $current ) {
			return Rest_Response::error( 'not_found', __( 'Loan not found.', 'sfs-hr' ), 404 );
		}
		if ( in_array( $current->status, [ 'completed', 'cancelled' ], true ) ) {
			return Rest_Response::error( 'invalid_state', __( 'Loan is already completed or cancelled.', 'sfs-hr' ), 409 );
		}

		$reason = trim( (string) $req->get_param( 'cancellation_reason' ) );
		if ( $reason === '' ) {
			return Rest_Response::error( 'invalid_input', __( 'cancellation_reason is required.', 'sfs-hr' ), 400 );
		}

		$old_status = $current->status;
		$now        = current_time( 'mysql' );

		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'cancelled',
			     cancelled_by = %d,
			     cancelled_at = %s,
			     cancellation_reason = %s,
			     remaining_balance = 0,
			     updated_at = %s
			 WHERE id = %d AND status NOT IN ('completed', 'cancelled')",
			get_current_user_id(),
			$now,
			$reason,
			$now,
			$id
		) );

		if ( $result === false || $result === 0 ) {
			return Rest_Response::error( 'update_failed', __( 'Could not cancel loan.', 'sfs-hr' ), 409 );
		}

		// Mark any remaining planned payments as skipped.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$payments_table}
			 SET status = 'skipped', updated_at = %s
			 WHERE loan_id = %d AND status = 'planned'",
			$now,
			$id
		) );

		LoansModule::log_event( $id, 'loan_cancelled', [
			'reason'     => $reason,
			'old_status' => $old_status,
			'balance'    => $current->remaining_balance,
		] );
		do_action( 'sfs_hr_loan_status_changed', $id, $old_status, 'cancelled' );
		Loans_Notifications::notify_loan_cancelled( $id );

		return self::get_loan( $req );
	}

	public static function list_payments( \WP_REST_Request $req ) {
		global $wpdb;
		$id = (int) $req['id'];

		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}sfs_hr_loans WHERE id = %d",
			$id
		) );
		if ( ! $exists ) {
			return Rest_Response::error( 'not_found', __( 'Loan not found.', 'sfs-hr' ), 404 );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, sequence, due_date, amount_planned, amount_paid, status, paid_at, source, notes
			 FROM {$wpdb->prefix}sfs_hr_loan_payments
			 WHERE loan_id = %d
			 ORDER BY sequence ASC",
			$id
		), ARRAY_A );

		return Rest_Response::success( array_map( [ self::class, 'format_payment' ], $rows ?: [] ) );
	}

	public static function skip_payment( \WP_REST_Request $req ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sfs_hr_loan_payments';
		$id    = (int) $req['id'];
		$seq   = (int) $req['seq'];
		$note  = sanitize_textarea_field( (string) $req->get_param( 'reason' ) );

		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM {$table} WHERE loan_id = %d AND sequence = %d",
			$id,
			$seq
		) );

		if ( ! $payment ) {
			return Rest_Response::error( 'not_found', __( 'Installment not found.', 'sfs-hr' ), 404 );
		}
		if ( $payment->status !== 'planned' ) {
			return Rest_Response::error( 'invalid_state', __( 'Only planned installments can be skipped.', 'sfs-hr' ), 409 );
		}

		$now = current_time( 'mysql' );

		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			 SET status = 'skipped',
			     notes = %s,
			     updated_at = %s
			 WHERE id = %d AND status = 'planned'",
			$note,
			$now,
			$payment->id
		) );

		if ( $result === false || $result === 0 ) {
			return Rest_Response::error( 'update_failed', __( 'Could not skip installment.', 'sfs-hr' ), 409 );
		}

		LoansModule::log_event( $id, 'installment_skipped', [
			'sequence' => $seq,
			'reason'   => $note,
		] );

		return Rest_Response::success( [ 'loan_id' => $id, 'sequence' => $seq, 'status' => 'skipped' ] );
	}

	/* ───────── Helpers ───────── */

	/**
	 * Generate the installment schedule. Logic mirrors the admin handler
	 * (Admin\AdminPages::generate_payment_schedule) so both paths produce
	 * identical rows. Kept local rather than refactoring the admin method
	 * out — that refactor belongs in a dedicated service PR.
	 */
	private static function generate_payment_schedule( int $loan_id, string $first_month, int $installments, float $installment_amount ): void {
		global $wpdb;
		$payments_table = $wpdb->prefix . 'sfs_hr_loan_payments';

		$wpdb->delete( $payments_table, [ 'loan_id' => $loan_id ] );

		$first_date = new \DateTime( $first_month . '-01' );
		$last_date  = clone $first_date;

		for ( $i = 1; $i <= $installments; $i++ ) {
			$due_date = clone $first_date;
			$due_date->modify( '+' . ( $i - 1 ) . ' months' );

			$wpdb->insert( $payments_table, [
				'loan_id'        => $loan_id,
				'sequence'       => $i,
				'due_date'       => $due_date->format( 'Y-m-d' ),
				'amount_planned' => $installment_amount,
				'amount_paid'    => 0,
				'status'         => 'planned',
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			] );

			$last_date = $due_date;
		}

		$wpdb->update( $wpdb->prefix . 'sfs_hr_loans', [
			'first_due_date' => $first_date->format( 'Y-m-d' ),
			'last_due_date'  => $last_date->format( 'Y-m-d' ),
		], [ 'id' => $loan_id ] );

		LoansModule::log_event( $loan_id, 'schedule_generated', [
			'installments' => $installments,
			'first_due'    => $first_date->format( 'Y-m-d' ),
			'last_due'     => $last_date->format( 'Y-m-d' ),
		] );
	}

	private static function employee_id_for_current_user(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d AND status = 'active' LIMIT 1",
			get_current_user_id()
		) );
	}

	private static function valid_statuses(): array {
		return [ 'pending_gm', 'pending_finance', 'active', 'completed', 'rejected', 'cancelled' ];
	}

	private static function format_loan( array $row ): array {
		$numeric_fields = [
			'principal_amount',
			'installment_amount',
			'remaining_balance',
			'approved_gm_amount',
		];
		foreach ( $numeric_fields as $f ) {
			if ( array_key_exists( $f, $row ) && $row[ $f ] !== null ) {
				$row[ $f ] = (float) $row[ $f ];
			}
		}
		foreach ( [ 'id', 'employee_id', 'installments_count', 'created_by', 'approved_gm_by', 'approved_finance_by', 'rejected_by', 'cancelled_by' ] as $f ) {
			if ( array_key_exists( $f, $row ) && $row[ $f ] !== null ) {
				$row[ $f ] = (int) $row[ $f ];
			}
		}
		return $row;
	}

	private static function format_payment( array $row ): array {
		foreach ( [ 'amount_planned', 'amount_paid' ] as $f ) {
			if ( isset( $row[ $f ] ) ) {
				$row[ $f ] = (float) $row[ $f ];
			}
		}
		foreach ( [ 'id', 'sequence' ] as $f ) {
			if ( isset( $row[ $f ] ) ) {
				$row[ $f ] = (int) $row[ $f ];
			}
		}
		return $row;
	}
}
