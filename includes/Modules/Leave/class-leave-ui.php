<?php
namespace SFS\HR\Modules\Leave;

use SFS\HR\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Leave_UI {

    /**
     * Map internal status key → human label.
     */
    public static function leave_status_label( string $status ): string {
        $key = sanitize_key( $status ?: 'unknown' );

        $labels = [
    'pending'           => __( 'Pending', 'sfs-hr' ),
    'pending_hr'        => __( 'Pending - HR', 'sfs-hr' ),
    'pending_manager'   => __( 'Pending - Manager', 'sfs-hr' ),
    'pending_gm'        => __( 'Pending - GM', 'sfs-hr' ),
    'pending_finance'   => __( 'Pending - Finance', 'sfs-hr' ),
    'approved'          => __( 'Approved', 'sfs-hr' ),
    'rejected'          => __( 'Rejected', 'sfs-hr' ),
    'cancelled'         => __( 'Cancelled', 'sfs-hr' ),
    'on_leave'          => __( 'On Leave', 'sfs-hr' ),
    'returned'          => __( 'Returned', 'sfs-hr' ),
    'early_returned'    => __( 'Early Returned', 'sfs-hr' ),
    'unknown'           => __( 'Unknown', 'sfs-hr' ),
];

        return $labels[ $key ] ?? $labels['unknown'];
    }

    /**
     * Map status → chip color modifier.
     */
    public static function leave_status_color( string $status ): string {
    $key = sanitize_key( $status ?: 'unknown' );

    switch ( $key ) {
    case 'approved':
    case 'returned':
    case 'early_returned':
        return 'green';

    case 'pending':
    case 'pending_hr':
        return 'blue';   // Pending - HR

    case 'pending_manager':
        return 'yellow'; // Pending - Manager

    case 'pending_gm':
        return 'orange'; // Pending - GM

    case 'pending_finance':
        return 'teal'; // Pending - Finance

    case 'on_leave':
        return 'purple';

    case 'rejected':
    case 'cancelled':
        return 'red';

    default:
        return 'gray';
}
}


    /**
     * Render a status chip HTML.
     */
    public static function leave_status_chip( string $status ): string {
        // Ensure CSS (badges + chips) is printed once.
        Helpers::output_asset_status_badge_css();

        $key   = sanitize_key( $status ?: 'unknown' );
        $label = self::leave_status_label( $key );
        $color = self::leave_status_color( $key );

        $class = 'sfs-hr-status-chip sfs-hr-status-chip--' . $color;

        return '<span class="' . esc_attr( $class ) . '" data-i18n-key="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</span>';
    }
}
