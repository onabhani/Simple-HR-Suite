<?php
namespace SFS\HR\Modules\Expenses\Frontend;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use SFS\HR\Modules\Expenses\Services\Expense_Service;
use SFS\HR\Modules\Expenses\Services\Expense_Category_Service;
use SFS\HR\Modules\Expenses\Services\Advance_Service;

/**
 * Expense_Shortcode
 *
 * [sfs_hr_expenses] — Employee expense portal.
 *
 * Shows:
 *  - Outstanding advance summary
 *  - Submit claim form (multi-line items + receipt media picker)
 *  - List of employee's own claims with status
 *  - Request advance form
 *
 * @since M10
 */
class Expense_Shortcode {

    public static function render( $atts = [] ): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="sfs-hr-error">' . esc_html__( 'Please log in to manage expenses.', 'sfs-hr' ) . '</div>';
        }

        global $wpdb;
        $uid    = get_current_user_id();
        $emp_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}sfs_hr_employees WHERE user_id = %d LIMIT 1",
            $uid
        ) );
        if ( ! $emp_id ) {
            return '<div class="sfs-hr-error">' . esc_html__( 'Your HR profile is not linked yet. Contact HR.', 'sfs-hr' ) . '</div>';
        }

        $cats        = Expense_Category_Service::list_active();
        $claims      = Expense_Service::list_for_employee( $emp_id, 30 );
        $advances    = Advance_Service::list_for_employee( $emp_id );
        $outstanding = Advance_Service::total_outstanding( $emp_id );
        $settings    = Expense_Service::get_settings();
        $currency    = $settings['currency'];

        ob_start();
        ?>
        <div class="sfs-hr-expenses-wrap" style="max-width:960px;">
            <?php if ( ! empty( $_GET['expense'] ) && 'ok' === $_GET['expense'] ) : ?>
                <div class="sfs-hr-notice sfs-hr-notice-success">
                    <?php
                    printf(
                        /* translators: %s reference number */
                        esc_html__( 'Expense claim submitted (Ref %s).', 'sfs-hr' ),
                        esc_html( (string) wp_unslash( $_GET['ref'] ?? '' ) )
                    );
                    ?>
                </div>
            <?php endif; ?>

            <?php if ( $outstanding > 0 ) : ?>
                <div class="sfs-hr-advance-outstanding" style="padding:10px;background:#fff3cd;border:1px solid #ffe69c;margin-bottom:16px;">
                    <?php
                    printf(
                        /* translators: 1: amount, 2: currency */
                        esc_html__( 'Outstanding advance balance: %1$s %2$s', 'sfs-hr' ),
                        '<strong>' . esc_html( number_format( $outstanding, 2 ) ) . '</strong>',
                        esc_html( $currency )
                    );
                    ?>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Submit Expense Claim', 'sfs-hr' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sfs-hr-expense-form">
                <input type="hidden" name="action" value="sfs_hr_expense_submit_claim" />
                <?php wp_nonce_field( 'sfs_hr_expense_submit_claim', '_sfs_nonce' ); ?>

                <p>
                    <label><?php esc_html_e( 'Title', 'sfs-hr' ); ?></label><br>
                    <input type="text" name="title" required style="width:100%;" />
                </p>
                <p>
                    <label><?php esc_html_e( 'Description', 'sfs-hr' ); ?></label><br>
                    <textarea name="description" rows="2" style="width:100%;"></textarea>
                </p>

                <h3><?php esc_html_e( 'Line Items', 'sfs-hr' ); ?></h3>
                <table class="sfs-hr-items" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid #ccc;">
                            <th><?php esc_html_e( 'Date', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Category', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Amount', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Description', 'sfs-hr' ); ?></th>
                            <th><?php esc_html_e( 'Receipt (WP media ID)', 'sfs-hr' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sfs-hr-items-body">
                        <?php for ( $i = 0; $i < 3; $i++ ) : ?>
                            <tr>
                                <td><input type="date" name="item_date[]" /></td>
                                <td>
                                    <select name="item_category[]">
                                        <option value=""><?php esc_html_e( '— select —', 'sfs-hr' ); ?></option>
                                        <?php foreach ( $cats as $c ) : ?>
                                            <option value="<?php echo (int) $c['id']; ?>"><?php echo esc_html( $c['name'] ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" step="0.01" min="0" name="item_amount[]" style="width:110px;" /></td>
                                <td><input type="text" name="item_description[]" style="width:100%;" /></td>
                                <td><input type="number" min="0" name="item_receipt_media_id[]" style="width:110px;" placeholder="0" /></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e( 'Leave rows empty to skip. Receipts should be uploaded via WordPress media library; paste the attachment ID here.', 'sfs-hr' ); ?></p>

                <p>
                    <label><input type="checkbox" name="submit_for_approval" value="1" checked /> <?php esc_html_e( 'Submit immediately for approval', 'sfs-hr' ); ?></label>
                </p>

                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Claim', 'sfs-hr' ); ?></button></p>
            </form>

            <h2 style="margin-top:30px;"><?php esc_html_e( 'Your Claims', 'sfs-hr' ); ?></h2>
            <?php if ( empty( $claims ) ) : ?>
                <p><?php esc_html_e( 'No claims yet.', 'sfs-hr' ); ?></p>
            <?php else : ?>
                <table class="sfs-hr-table" style="width:100%;border-collapse:collapse;">
                    <thead><tr style="border-bottom:1px solid #ccc;">
                        <th><?php esc_html_e( 'Ref', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Title', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Total', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Submitted', 'sfs-hr' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php $labels = Expense_Service::get_status_labels(); ?>
                        <?php foreach ( $claims as $c ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $c['request_number'] ?? '—' ); ?></code></td>
                                <td><?php echo esc_html( $c['title'] ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $c['total_amount'], 2 ) ); ?> <?php echo esc_html( $c['currency'] ); ?></td>
                                <td><?php echo esc_html( $labels[ $c['status'] ] ?? $c['status'] ); ?></td>
                                <td><?php echo esc_html( $c['submitted_at'] ?? '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top:30px;"><?php esc_html_e( 'Request Cash Advance', 'sfs-hr' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:500px;">
                <input type="hidden" name="action" value="sfs_hr_expense_request_advance" />
                <?php wp_nonce_field( 'sfs_hr_expense_request_advance', '_sfs_nonce' ); ?>
                <p>
                    <label><?php esc_html_e( 'Amount', 'sfs-hr' ); ?></label><br>
                    <input type="number" step="0.01" min="0" name="amount" required />
                </p>
                <p>
                    <label><?php esc_html_e( 'Purpose', 'sfs-hr' ); ?></label><br>
                    <input type="text" name="purpose" required style="width:100%;" />
                </p>
                <p>
                    <label><?php esc_html_e( 'Notes', 'sfs-hr' ); ?></label><br>
                    <textarea name="notes" rows="2" style="width:100%;"></textarea>
                </p>
                <p><button type="submit" class="button"><?php esc_html_e( 'Request Advance', 'sfs-hr' ); ?></button></p>
            </form>

            <h3 style="margin-top:20px;"><?php esc_html_e( 'Your Advances', 'sfs-hr' ); ?></h3>
            <?php if ( empty( $advances ) ) : ?>
                <p><?php esc_html_e( 'No advances yet.', 'sfs-hr' ); ?></p>
            <?php else : ?>
                <table class="sfs-hr-table" style="width:100%;border-collapse:collapse;">
                    <thead><tr style="border-bottom:1px solid #ccc;">
                        <th><?php esc_html_e( 'Ref', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Amount', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Outstanding', 'sfs-hr' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'sfs-hr' ); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ( $advances as $a ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $a['request_number'] ?? '—' ); ?></code></td>
                                <td><?php echo esc_html( number_format( (float) $a['amount'], 2 ) ); ?> <?php echo esc_html( $a['currency'] ); ?></td>
                                <td><?php echo esc_html( number_format( (float) $a['outstanding_amount'], 2 ) ); ?></td>
                                <td><?php echo esc_html( $a['status'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
