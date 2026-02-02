<?php
namespace SFS\HR\Core;
if ( ! defined('ABSPATH') ) { exit; }

/**
 * Design Samples Page
 *
 * A reference page showcasing all UI components used in HR Suite.
 * Browse and approve designs here -- only approved patterns will remain.
 */
class DesignSamples {

    public static function render(): void {
        Helpers::require_cap( 'manage_options' );

        echo '<div class="wrap sfs-hr-wrap">';
        echo '<h1>' . esc_html__( 'Design Samples', 'sfs-hr' ) . '</h1>';
        Helpers::render_admin_nav();
        echo '<hr class="wp-header-end" />';

        // Hide generic WP notices
        echo '<style>#wpbody-content .notice:not(.sfs-hr-notice) { display: none; }</style>';

        // Output all component CSS
        self::output_styles();

        // Table of contents
        self::render_toc();

        // Render each component section
        self::render_buttons_section();
        self::render_badges_and_chips_section();
        self::render_status_pills_section();
        self::render_alerts_section();
        self::render_cards_section();
        self::render_tables_section();
        self::render_filters_toolbar_section();
        self::render_tabs_section();
        self::render_forms_section();
        self::render_modals_section();
        self::render_navigation_section();
        self::render_progress_bars_section();
        self::render_typography_section();

        echo '</div>'; // .wrap
    }

    /* =========================================================================
     * TABLE OF CONTENTS
     * ====================================================================== */
    private static function render_toc(): void {
        $sections = [
            'ds-buttons'     => __( 'Buttons', 'sfs-hr' ),
            'ds-badges'      => __( 'Badges & Status Chips', 'sfs-hr' ),
            'ds-pills'       => __( 'Status Pills (Loans / Resignation)', 'sfs-hr' ),
            'ds-alerts'      => __( 'Alerts & Notices', 'sfs-hr' ),
            'ds-cards'       => __( 'Cards', 'sfs-hr' ),
            'ds-tables'      => __( 'Tables', 'sfs-hr' ),
            'ds-filters'     => __( 'Filters & Toolbars', 'sfs-hr' ),
            'ds-tabs'        => __( 'Tabs', 'sfs-hr' ),
            'ds-forms'       => __( 'Forms', 'sfs-hr' ),
            'ds-modals'      => __( 'Modals', 'sfs-hr' ),
            'ds-nav'         => __( 'Navigation', 'sfs-hr' ),
            'ds-progress'    => __( 'Progress Bars', 'sfs-hr' ),
            'ds-typography'  => __( 'Typography & Spacing', 'sfs-hr' ),
        ];

        echo '<div class="sfs-ds-toc">';
        echo '<h2>' . esc_html__( 'Components', 'sfs-hr' ) . '</h2>';
        echo '<div class="sfs-ds-toc-grid">';
        foreach ( $sections as $id => $label ) {
            echo '<a href="#' . esc_attr( $id ) . '" class="sfs-ds-toc-link">' . esc_html( $label ) . '</a>';
        }
        echo '</div></div>';
    }

    /* =========================================================================
     * 1. BUTTONS
     * ====================================================================== */
    private static function render_buttons_section(): void {
        echo '<div class="sfs-ds-section" id="ds-buttons">';
        echo '<h2>' . esc_html__( 'Buttons', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'WordPress core button classes used throughout the plugin.', 'sfs-hr' ) . '</p>';

        // Standard buttons
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Standard Buttons', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        echo '<button class="button button-primary">' . esc_html__( 'Primary Button', 'sfs-hr' ) . '</button>';
        echo '<button class="button button-secondary">' . esc_html__( 'Secondary Button', 'sfs-hr' ) . '</button>';
        echo '<button class="button">' . esc_html__( 'Default Button', 'sfs-hr' ) . '</button>';
        echo '<a href="#" class="button button-primary">' . esc_html__( 'Link as Button', 'sfs-hr' ) . '</a>';
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.button .button-primary | .button .button-secondary | .button</code></div>';
        echo '</div>';

        // Small buttons
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Small Buttons', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        echo '<button class="button button-small">' . esc_html__( 'View', 'sfs-hr' ) . '</button>';
        echo '<button class="button button-small button-primary">' . esc_html__( 'Edit', 'sfs-hr' ) . '</button>';
        echo '<button class="button button-small button-link-delete">' . esc_html__( 'Delete', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.button .button-small</code></div>';
        echo '</div>';

        // Disabled
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Disabled Buttons', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        echo '<button class="button button-primary" disabled>' . esc_html__( 'Primary Disabled', 'sfs-hr' ) . '</button>';
        echo '<button class="button" disabled>' . esc_html__( 'Default Disabled', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';

        // Buttons with icons
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Buttons with Icons', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        echo '<button class="button button-primary"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;margin-right:2px;font-size:16px;width:16px;height:16px;"></span> ' . esc_html__( 'Add New', 'sfs-hr' ) . '</button>';
        echo '<button class="button"><span class="dashicons dashicons-download" style="margin-top:3px;margin-right:2px;font-size:16px;width:16px;height:16px;"></span> ' . esc_html__( 'Export CSV', 'sfs-hr' ) . '</button>';
        echo '<button class="button"><span class="dashicons dashicons-upload" style="margin-top:3px;margin-right:2px;font-size:16px;width:16px;height:16px;"></span> ' . esc_html__( 'Import', 'sfs-hr' ) . '</button>';
        echo '<button class="button button-primary"><span class="dashicons dashicons-edit" style="margin-top:3px;margin-right:2px;font-size:16px;width:16px;height:16px;"></span> ' . esc_html__( 'View / Edit', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';

        // Button group
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Button Group (Actions)', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        echo '<div style="display:flex;gap:8px;">';
        echo '<button class="button button-primary">' . esc_html__( 'Approve', 'sfs-hr' ) . '</button>';
        echo '<button class="button">' . esc_html__( 'Cancel', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 2. BADGES & STATUS CHIPS
     * ====================================================================== */
    private static function render_badges_and_chips_section(): void {
        // Make sure CSS is output
        Helpers::output_asset_status_badge_css();

        echo '<div class="sfs-ds-section" id="ds-badges">';
        echo '<h2>' . esc_html__( 'Badges & Status Chips', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'Color-coded status indicators used for leave requests, assets, and general status display.', 'sfs-hr' ) . '</p>';

        // Generic status chips
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Generic Status Chips', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        $chip_colors = [
            'gray'   => __( 'Draft', 'sfs-hr' ),
            'green'  => __( 'Approved', 'sfs-hr' ),
            'blue'   => __( 'In Review', 'sfs-hr' ),
            'red'    => __( 'Rejected', 'sfs-hr' ),
            'purple' => __( 'Escalated', 'sfs-hr' ),
            'yellow' => __( 'Pending', 'sfs-hr' ),
            'orange' => __( 'Warning', 'sfs-hr' ),
            'teal'   => __( 'On Leave', 'sfs-hr' ),
        ];
        foreach ( $chip_colors as $color => $label ) {
            echo '<span class="sfs-hr-status-chip sfs-hr-status-chip--' . esc_attr( $color ) . '">' . esc_html( $label ) . '</span>';
        }
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.sfs-hr-status-chip .sfs-hr-status-chip--{gray|green|blue|red|purple|yellow|orange|teal}</code></div>';
        echo '</div>';

        // Leave approval chips
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Leave Approval Chips', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        $leave_chips = [
            'pending-manager'  => __( 'Pending Manager', 'sfs-hr' ),
            'pending-gm'      => __( 'Pending GM', 'sfs-hr' ),
            'pending-hr'      => __( 'Pending HR', 'sfs-hr' ),
            'approved-manager' => __( 'Approved by Manager', 'sfs-hr' ),
            'approved-hr'     => __( 'Approved by HR', 'sfs-hr' ),
            'rejected'        => __( 'Rejected', 'sfs-hr' ),
            'cancelled'       => __( 'Cancelled', 'sfs-hr' ),
        ];
        foreach ( $leave_chips as $status => $label ) {
            echo '<span class="sfs-hr-leave-chip sfs-hr-leave-chip-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
        }
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.sfs-hr-leave-chip .sfs-hr-leave-chip-{status}</code></div>';
        echo '</div>';

        // Asset status badges
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Asset Status Badges', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        $asset_statuses = [
            'active'                    => __( 'Active', 'sfs-hr' ),
            'pending_employee_approval' => __( 'Pending Approval', 'sfs-hr' ),
            'return_requested'          => __( 'Return Requested', 'sfs-hr' ),
            'returned'                  => __( 'Returned', 'sfs-hr' ),
            'rejected'                  => __( 'Rejected', 'sfs-hr' ),
        ];
        foreach ( $asset_statuses as $status => $label ) {
            echo '<span class="sfs-hr-asset-status sfs-hr-asset-status--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
        }
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.sfs-hr-asset-status .sfs-hr-asset-status--{status}</code></div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 3. STATUS PILLS (Loans / Resignation)
     * ====================================================================== */
    private static function render_status_pills_section(): void {
        echo '<div class="sfs-ds-section" id="ds-pills">';
        echo '<h2>' . esc_html__( 'Status Pills (Loans / Resignation)', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'Rounded pill badges used in Loans and Resignation modules.', 'sfs-hr' ) . '</p>';

        // Loan pills
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Loan Status Pills', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        $loan_statuses = [
            'pending-gm'      => __( 'Pending GM', 'sfs-hr' ),
            'pending-finance'  => __( 'Pending Finance', 'sfs-hr' ),
            'active'           => __( 'Active', 'sfs-hr' ),
            'completed'        => __( 'Completed', 'sfs-hr' ),
            'rejected'         => __( 'Rejected', 'sfs-hr' ),
            'cancelled'        => __( 'Cancelled', 'sfs-hr' ),
        ];
        foreach ( $loan_statuses as $status => $label ) {
            echo '<span class="sfs-hr-pill sfs-hr-pill--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
        }
        echo '</div>';
        echo '</div>';

        // Installment pills
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Installment Status Pills', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        $inst_statuses = [
            'planned'  => __( 'Planned', 'sfs-hr' ),
            'paid'     => __( 'Paid', 'sfs-hr' ),
            'partial'  => __( 'Partial', 'sfs-hr' ),
            'skipped'  => __( 'Skipped', 'sfs-hr' ),
        ];
        foreach ( $inst_statuses as $status => $label ) {
            echo '<span class="sfs-hr-pill sfs-hr-pill--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
        }
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.sfs-hr-pill .sfs-hr-pill--{status}</code></div>';
        echo '</div>';

        // Resignation pills
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Resignation Status Pills', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        $resign_statuses = [
            'pending'   => __( 'Pending', 'sfs-hr' ),
            'approved'  => __( 'Approved', 'sfs-hr' ),
            'rejected'  => __( 'Rejected', 'sfs-hr' ),
            'cancelled' => __( 'Cancelled', 'sfs-hr' ),
        ];
        foreach ( $resign_statuses as $status => $label ) {
            echo '<span class="sfs-hr-pill sfs-hr-pill--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
        }
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 4. ALERTS & NOTICES
     * ====================================================================== */
    private static function render_alerts_section(): void {
        echo '<div class="sfs-ds-section" id="ds-alerts">';
        echo '<h2>' . esc_html__( 'Alerts & Notices', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'WordPress-standard notices and custom alert components.', 'sfs-hr' ) . '</p>';

        // WordPress notices
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'WordPress Admin Notices', 'sfs-hr' ) . '</h3>';

        echo '<div class="notice notice-success sfs-hr-notice sfs-ds-notice-demo"><p>' . esc_html__( 'Action completed successfully.', 'sfs-hr' ) . '</p></div>';
        echo '<div class="notice notice-error sfs-hr-notice sfs-ds-notice-demo"><p>' . esc_html__( 'An error occurred. Please try again.', 'sfs-hr' ) . '</p></div>';
        echo '<div class="notice notice-warning sfs-hr-notice sfs-ds-notice-demo"><p>' . esc_html__( 'Warning: Please review the data before proceeding.', 'sfs-hr' ) . '</p></div>';
        echo '<div class="notice notice-info sfs-hr-notice sfs-ds-notice-demo"><p>' . esc_html__( 'Information: The system will be updated tonight.', 'sfs-hr' ) . '</p></div>';

        echo '<div class="sfs-ds-code"><code>.notice .notice-{success|error|warning|info} .sfs-hr-notice</code></div>';
        echo '</div>';

        // Custom inline alerts
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Inline Alert', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-hr-alert">' . esc_html__( 'Please log in to view your profile.', 'sfs-hr' ) . '</div>';
        echo '<div class="sfs-ds-code"><code>.sfs-hr-alert</code></div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 5. CARDS
     * ====================================================================== */
    private static function render_cards_section(): void {
        echo '<div class="sfs-ds-section" id="ds-cards">';
        echo '<h2>' . esc_html__( 'Cards', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'Dashboard KPI cards, approval cards, and navigation cards.', 'sfs-hr' ) . '</p>';

        // KPI cards
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'KPI / Dashboard Cards', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-hr-dashboard-grid">';

        $cards = [
            [ 'title' => __( 'Employees', 'sfs-hr' ), 'count' => '142', 'meta' => __( '150 total employees', 'sfs-hr' ) ],
            [ 'title' => __( 'Leave', 'sfs-hr' ),     'count' => '5',   'meta' => __( 'Pending leave requests', 'sfs-hr' ) ],
            [ 'title' => __( 'Departments', 'sfs-hr' ), 'count' => '8', 'meta' => __( 'Active departments', 'sfs-hr' ) ],
            [ 'title' => __( 'Attendance', 'sfs-hr' ), 'count' => '3',  'meta' => __( 'Active shifts today', 'sfs-hr' ) ],
        ];
        foreach ( $cards as $c ) {
            echo '<div class="sfs-hr-card">';
            echo '<h2>' . esc_html( $c['title'] ) . '</h2>';
            echo '<div class="sfs-hr-card-count">' . esc_html( $c['count'] ) . '</div>';
            echo '<div class="sfs-hr-card-meta">' . esc_html( $c['meta'] ) . '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.sfs-hr-card > .sfs-hr-card-count + .sfs-hr-card-meta</code></div>';
        echo '</div>';

        // Approval cards
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Approval Cards (urgent)', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-hr-dashboard-grid">';

        $approvals = [
            [ 'title' => __( 'Pending Loans', 'sfs-hr' ),          'count' => '3', 'meta' => __( 'Awaiting your approval', 'sfs-hr' ) ],
            [ 'title' => __( 'Pending Leave Requests', 'sfs-hr' ), 'count' => '7', 'meta' => __( 'Awaiting your approval', 'sfs-hr' ) ],
            [ 'title' => __( 'Pending Resignations', 'sfs-hr' ),   'count' => '2', 'meta' => __( 'Awaiting approval', 'sfs-hr' ) ],
        ];
        foreach ( $approvals as $a ) {
            echo '<div class="sfs-hr-card sfs-hr-approval-card">';
            echo '<h2>' . esc_html( $a['title'] ) . '</h2>';
            echo '<div class="sfs-hr-card-count">' . esc_html( $a['count'] ) . '</div>';
            echo '<div class="sfs-hr-card-meta">' . esc_html( $a['meta'] ) . '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.sfs-hr-card .sfs-hr-approval-card</code></div>';
        echo '</div>';

        // Nav cards
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Quick Access / Navigation Cards', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-hr-quick-access-grid">';

        $nav_items = [
            [ 'icon' => 'dashicons-id-alt',       'label' => __( 'Employees', 'sfs-hr' ) ],
            [ 'icon' => 'dashicons-networking',    'label' => __( 'Departments', 'sfs-hr' ) ],
            [ 'icon' => 'dashicons-calendar-alt',  'label' => __( 'Leave', 'sfs-hr' ) ],
            [ 'icon' => 'dashicons-clock',         'label' => __( 'Attendance', 'sfs-hr' ) ],
            [ 'icon' => 'dashicons-money-alt',     'label' => __( 'Loans', 'sfs-hr' ) ],
            [ 'icon' => 'dashicons-laptop',        'label' => __( 'Assets', 'sfs-hr' ) ],
        ];
        foreach ( $nav_items as $item ) {
            echo '<span class="sfs-hr-nav-card">';
            echo '<span class="dashicons ' . esc_attr( $item['icon'] ) . '"></span>';
            echo '<span>' . esc_html( $item['label'] ) . '</span>';
            echo '</span>';
        }
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.sfs-hr-nav-card > .dashicons + span</code></div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 6. TABLES
     * ====================================================================== */
    private static function render_tables_section(): void {
        echo '<div class="sfs-ds-section" id="ds-tables">';
        echo '<h2>' . esc_html__( 'Tables', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'WordPress widefat striped tables with responsive support.', 'sfs-hr' ) . '</p>';

        // Standard table
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Standard Data Table', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-hr-table-responsive">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'ID', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Employee', 'sfs-hr' ) . '</th>';
        echo '<th class="hide-mobile">' . esc_html__( 'Department', 'sfs-hr' ) . '</th>';
        echo '<th class="hide-mobile">' . esc_html__( 'Position', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '<th style="width:120px;">' . esc_html__( 'Actions', 'sfs-hr' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $rows = [
            [ '1', 'Ahmed Al-Rashid',  'Engineering',   'Senior Developer',   'active' ],
            [ '2', 'Sarah Johnson',     'HR',            'HR Manager',         'active' ],
            [ '3', 'Mohammed Ali',      'Finance',       'Accountant',         'on_leave' ],
            [ '4', 'Fatima Hassan',     'Marketing',     'Marketing Lead',     'active' ],
            [ '5', 'James Wilson',      'Engineering',   'QA Engineer',        'terminated' ],
        ];

        $status_map = [
            'active'     => [ 'green',  __( 'Active', 'sfs-hr' ) ],
            'on_leave'   => [ 'teal',   __( 'On Leave', 'sfs-hr' ) ],
            'terminated' => [ 'red',    __( 'Terminated', 'sfs-hr' ) ],
        ];

        foreach ( $rows as $r ) {
            $s = $status_map[ $r[4] ];
            echo '<tr>';
            echo '<td>' . esc_html( $r[0] ) . '</td>';
            echo '<td><a href="#" class="emp-name">' . esc_html( $r[1] ) . '</a></td>';
            echo '<td class="hide-mobile">' . esc_html( $r[2] ) . '</td>';
            echo '<td class="hide-mobile">' . esc_html( $r[3] ) . '</td>';
            echo '<td><span class="sfs-hr-status-chip sfs-hr-status-chip--' . esc_attr( $s[0] ) . '">' . esc_html( $s[1] ) . '</span></td>';
            echo '<td><div style="display:flex;gap:4px;"><button class="button button-small">' . esc_html__( 'View', 'sfs-hr' ) . '</button><button class="button button-small">' . esc_html__( 'Edit', 'sfs-hr' ) . '</button></div></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // table-responsive
        echo '<div class="sfs-ds-code"><code>.sfs-hr-table-responsive > table.widefat.striped</code></div>';
        echo '</div>';

        // Table with amounts
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Settlement / Financial Table', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-hr-table-responsive">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'ID', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Employee', 'sfs-hr' ) . '</th>';
        echo '<th class="hide-mobile">' . esc_html__( 'Last Working Day', 'sfs-hr' ) . '</th>';
        echo '<th class="hide-mobile">' . esc_html__( 'Service Years', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Amount', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $settlements = [
            [ '101', 'Omar Khan',    '2025-12-31', '5.25', '45,000.00', 'pending' ],
            [ '102', 'Layla Ahmed',  '2025-11-15', '3.00', '28,500.00', 'approved' ],
            [ '103', 'Ali Hassan',   '2025-10-01', '7.50', '67,200.00', 'paid' ],
            [ '104', 'Noor Malik',   '2025-09-20', '2.10', '15,800.00', 'rejected' ],
        ];

        $settle_map = [
            'pending'  => [ 'yellow', __( 'Pending', 'sfs-hr' ) ],
            'approved' => [ 'green',  __( 'Approved', 'sfs-hr' ) ],
            'paid'     => [ 'blue',   __( 'Paid', 'sfs-hr' ) ],
            'rejected' => [ 'red',    __( 'Rejected', 'sfs-hr' ) ],
        ];

        foreach ( $settlements as $s ) {
            $st = $settle_map[ $s[5] ];
            echo '<tr>';
            echo '<td>' . esc_html( $s[0] ) . '</td>';
            echo '<td><a href="#" class="emp-name">' . esc_html( $s[1] ) . '</a></td>';
            echo '<td class="hide-mobile">' . esc_html( $s[2] ) . '</td>';
            echo '<td class="hide-mobile">' . esc_html( $s[3] ) . ' ' . esc_html__( 'yrs', 'sfs-hr' ) . '</td>';
            echo '<td><strong>' . esc_html( $s[4] ) . '</strong></td>';
            echo '<td><span class="sfs-hr-status-chip sfs-hr-status-chip--' . esc_attr( $st[0] ) . '">' . esc_html( $st[1] ) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        // Empty state
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Empty Table State', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-hr-table-responsive">';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'ID', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Name', 'sfs-hr' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'sfs-hr' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        echo '<tr><td colspan="3">' . esc_html__( 'No records found.', 'sfs-hr' ) . '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 7. FILTERS & TOOLBARS
     * ====================================================================== */
    private static function render_filters_toolbar_section(): void {
        echo '<div class="sfs-ds-section" id="ds-filters">';
        echo '<h2>' . esc_html__( 'Filters & Toolbars', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'Search bars, filter dropdowns, and action toolbars.', 'sfs-hr' ) . '</p>';

        // Search toolbar
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Search Toolbar', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-toolbar-demo">';
        echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        echo '<input type="search" placeholder="' . esc_attr__( 'Search employee name or code...', 'sfs-hr' ) . '" style="min-width:250px;" />';
        echo '<button class="button">' . esc_html__( 'Search', 'sfs-hr' ) . '</button>';
        echo '<button class="button">' . esc_html__( 'Clear', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Filter bar
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Filter Bar with Dropdowns', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-toolbar-demo">';
        echo '<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">';

        // Status filter
        echo '<div style="display:flex;flex-direction:column;gap:4px;">';
        echo '<label style="font-size:12px;font-weight:500;">' . esc_html__( 'Status', 'sfs-hr' ) . '</label>';
        echo '<select>';
        echo '<option>' . esc_html__( 'All', 'sfs-hr' ) . '</option>';
        echo '<option>' . esc_html__( 'Active', 'sfs-hr' ) . '</option>';
        echo '<option>' . esc_html__( 'Pending', 'sfs-hr' ) . '</option>';
        echo '<option>' . esc_html__( 'Returned', 'sfs-hr' ) . '</option>';
        echo '</select>';
        echo '</div>';

        // Category filter
        echo '<div style="display:flex;flex-direction:column;gap:4px;">';
        echo '<label style="font-size:12px;font-weight:500;">' . esc_html__( 'Category', 'sfs-hr' ) . '</label>';
        echo '<select>';
        echo '<option>' . esc_html__( 'All', 'sfs-hr' ) . '</option>';
        echo '<option>' . esc_html__( 'Laptop', 'sfs-hr' ) . '</option>';
        echo '<option>' . esc_html__( 'Phone', 'sfs-hr' ) . '</option>';
        echo '<option>' . esc_html__( 'Vehicle', 'sfs-hr' ) . '</option>';
        echo '</select>';
        echo '</div>';

        // Department filter
        echo '<div style="display:flex;flex-direction:column;gap:4px;">';
        echo '<label style="font-size:12px;font-weight:500;">' . esc_html__( 'Department', 'sfs-hr' ) . '</label>';
        echo '<select>';
        echo '<option>' . esc_html__( 'All', 'sfs-hr' ) . '</option>';
        echo '<option>' . esc_html__( 'Engineering', 'sfs-hr' ) . '</option>';
        echo '<option>' . esc_html__( 'HR', 'sfs-hr' ) . '</option>';
        echo '<option>' . esc_html__( 'Finance', 'sfs-hr' ) . '</option>';
        echo '</select>';
        echo '</div>';

        echo '<button class="button button-primary">' . esc_html__( 'Filter', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Actions toolbar
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Actions Toolbar', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-toolbar-demo">';
        echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        echo '<button class="button button-primary"><span class="dashicons dashicons-plus-alt2" style="margin-top:3px;margin-right:2px;font-size:16px;width:16px;height:16px;"></span> ' . esc_html__( 'Add New Asset', 'sfs-hr' ) . '</button>';
        echo '<span style="width:1px;height:24px;background:#dcdcde;"></span>';
        echo '<button class="button">' . esc_html__( 'Export CSV', 'sfs-hr' ) . '</button>';
        echo '<button class="button">' . esc_html__( 'Import', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 8. TABS
     * ====================================================================== */
    private static function render_tabs_section(): void {
        echo '<div class="sfs-ds-section" id="ds-tabs">';
        echo '<h2>' . esc_html__( 'Tabs', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'WordPress nav-tab and custom tab styles.', 'sfs-hr' ) . '</p>';

        // WP nav tabs
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'WordPress Nav Tabs', 'sfs-hr' ) . '</h3>';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="#" class="nav-tab nav-tab-active">' . esc_html__( 'Employee List', 'sfs-hr' ) . '</a>';
        echo '<a href="#" class="nav-tab">' . esc_html__( 'Import', 'sfs-hr' ) . '</a>';
        echo '<a href="#" class="nav-tab">' . esc_html__( 'Export', 'sfs-hr' ) . '</a>';
        echo '</h2>';
        echo '<div class="sfs-ds-code"><code>.nav-tab-wrapper > .nav-tab + .nav-tab-active</code></div>';
        echo '</div>';

        // Status tabs with counts
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Status Tabs with Counts', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-status-tabs">';
        $tabs = [
            [ 'label' => __( 'Pending', 'sfs-hr' ),  'count' => 5, 'active' => true ],
            [ 'label' => __( 'Approved', 'sfs-hr' ), 'count' => 12, 'active' => false ],
            [ 'label' => __( 'Paid', 'sfs-hr' ),     'count' => 8,  'active' => false ],
            [ 'label' => __( 'Rejected', 'sfs-hr' ), 'count' => 2,  'active' => false ],
        ];
        foreach ( $tabs as $tab ) {
            $cls = 'sfs-tab' . ( $tab['active'] ? ' active' : '' );
            echo '<a href="#" class="' . esc_attr( $cls ) . '">';
            echo esc_html( $tab['label'] );
            echo ' <span class="count">' . esc_html( $tab['count'] ) . '</span>';
            echo '</a>';
        }
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.sfs-tab + .sfs-tab.active > .count</code></div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 9. FORMS
     * ====================================================================== */
    private static function render_forms_section(): void {
        echo '<div class="sfs-ds-section" id="ds-forms">';
        echo '<h2>' . esc_html__( 'Forms', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'WordPress form-table layout and custom form groups.', 'sfs-hr' ) . '</p>';

        // Standard form-table
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Standard Form Table', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-hr-emp-card" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;">';
        echo '<h2 style="margin:0 0 12px;font-size:14px;">' . esc_html__( 'Personal & Contact', 'sfs-hr' ) . '</h2>';
        echo '<table class="form-table">';

        echo '<tr><th><label>' . esc_html__( 'Employee Code', 'sfs-hr' ) . '</label></th>';
        echo '<td><input type="text" class="regular-text" value="EMP-001" /></td></tr>';

        echo '<tr><th><label>' . esc_html__( 'First Name', 'sfs-hr' ) . '</label></th>';
        echo '<td><input type="text" class="regular-text" value="Ahmed" /></td></tr>';

        echo '<tr><th><label>' . esc_html__( 'Last Name', 'sfs-hr' ) . '</label></th>';
        echo '<td><input type="text" class="regular-text" value="Al-Rashid" /></td></tr>';

        echo '<tr><th><label>' . esc_html__( 'Email', 'sfs-hr' ) . '</label></th>';
        echo '<td><input type="email" class="regular-text" value="ahmed@company.com" /></td></tr>';

        echo '<tr><th><label>' . esc_html__( 'Gender', 'sfs-hr' ) . '</label></th>';
        echo '<td><select>';
        echo '<option value="">' . esc_html__( '-- Select --', 'sfs-hr' ) . '</option>';
        echo '<option value="male" selected>' . esc_html__( 'Male', 'sfs-hr' ) . '</option>';
        echo '<option value="female">' . esc_html__( 'Female', 'sfs-hr' ) . '</option>';
        echo '</select></td></tr>';

        echo '<tr><th><label>' . esc_html__( 'Date of Birth', 'sfs-hr' ) . '</label></th>';
        echo '<td><input type="date" value="1990-05-15" /></td></tr>';

        echo '<tr><th><label>' . esc_html__( 'Notes', 'sfs-hr' ) . '</label></th>';
        echo '<td><textarea rows="3" class="large-text">' . esc_html__( 'Optional notes about the employee...', 'sfs-hr' ) . '</textarea></td></tr>';

        echo '</table>';
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>table.form-table > tr > th (label) + td (input)</code></div>';
        echo '</div>';

        // File upload
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'File Upload Field', 'sfs-hr' ) . '</h3>';
        echo '<div style="display:flex;align-items:center;gap:8px;">';
        echo '<input type="file" accept=".csv,.xlsx" style="max-width:250px;" />';
        echo '<button class="button">' . esc_html__( 'Upload', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';

        // Submit button
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Submit Button (wp_submit_button)', 'sfs-hr' ) . '</h3>';
        echo '<p class="submit"><input type="submit" class="button button-primary" value="' . esc_attr__( 'Save Changes', 'sfs-hr' ) . '" /></p>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 10. MODALS
     * ====================================================================== */
    private static function render_modals_section(): void {
        echo '<div class="sfs-ds-section" id="ds-modals">';
        echo '<h2>' . esc_html__( 'Modals', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'Modal dialogs used for asset details, approvals, and confirmations.', 'sfs-hr' ) . '</p>';

        // Modal trigger buttons
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Modal Demos', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row">';
        echo '<button class="button button-primary" onclick="document.getElementById(\'sfs-ds-modal-info\').classList.add(\'active\');">' . esc_html__( 'Open Info Modal', 'sfs-hr' ) . '</button>';
        echo '<button class="button" onclick="document.getElementById(\'sfs-ds-modal-form\').classList.add(\'active\');">' . esc_html__( 'Open Form Modal', 'sfs-hr' ) . '</button>';
        echo '<button class="button" onclick="document.getElementById(\'sfs-ds-modal-confirm\').classList.add(\'active\');">' . esc_html__( 'Open Confirm Modal', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';

        // Info modal
        echo '<div class="sfs-hr-ds-modal" id="sfs-ds-modal-info" onclick="if(event.target===this)this.classList.remove(\'active\');">';
        echo '<div class="sfs-hr-ds-modal-content">';
        echo '<div class="sfs-hr-ds-modal-header">';
        echo '<h3>' . esc_html__( 'Asset Details', 'sfs-hr' ) . '</h3>';
        echo '<button type="button" class="sfs-hr-ds-modal-close" onclick="this.closest(\'.sfs-hr-ds-modal\').classList.remove(\'active\');">&times;</button>';
        echo '</div>';
        echo '<div class="sfs-hr-ds-modal-body">';
        echo '<ul class="sfs-hr-ds-detail-list">';
        echo '<li><span class="sfs-hr-ds-label">' . esc_html__( 'Code', 'sfs-hr' ) . '</span><span>AST-001</span></li>';
        echo '<li><span class="sfs-hr-ds-label">' . esc_html__( 'Category', 'sfs-hr' ) . '</span><span>' . esc_html__( 'Laptop', 'sfs-hr' ) . '</span></li>';
        echo '<li><span class="sfs-hr-ds-label">' . esc_html__( 'Department', 'sfs-hr' ) . '</span><span>' . esc_html__( 'Engineering', 'sfs-hr' ) . '</span></li>';
        echo '<li><span class="sfs-hr-ds-label">' . esc_html__( 'Assignee', 'sfs-hr' ) . '</span><span>Ahmed Al-Rashid</span></li>';
        echo '<li><span class="sfs-hr-ds-label">' . esc_html__( 'Status', 'sfs-hr' ) . '</span><span class="sfs-hr-status-chip sfs-hr-status-chip--green">' . esc_html__( 'Active', 'sfs-hr' ) . '</span></li>';
        echo '</ul>';
        echo '</div>';
        echo '<div class="sfs-hr-ds-modal-footer">';
        echo '<button class="button button-primary" onclick="this.closest(\'.sfs-hr-ds-modal\').classList.remove(\'active\');"><span class="dashicons dashicons-edit" style="margin-top:3px;margin-right:2px;font-size:16px;width:16px;height:16px;"></span> ' . esc_html__( 'View / Edit Asset', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Form modal
        echo '<div class="sfs-hr-ds-modal" id="sfs-ds-modal-form" onclick="if(event.target===this)this.classList.remove(\'active\');">';
        echo '<div class="sfs-hr-ds-modal-content">';
        echo '<div class="sfs-hr-ds-modal-header">';
        echo '<h3>' . esc_html__( 'Approve Resignation', 'sfs-hr' ) . '</h3>';
        echo '<button type="button" class="sfs-hr-ds-modal-close" onclick="this.closest(\'.sfs-hr-ds-modal\').classList.remove(\'active\');">&times;</button>';
        echo '</div>';
        echo '<div class="sfs-hr-ds-modal-body">';
        echo '<p><label style="font-weight:500;">' . esc_html__( 'Note (optional):', 'sfs-hr' ) . '</label><br />';
        echo '<textarea rows="4" style="width:100%;border:1px solid #dcdcde;border-radius:4px;padding:8px;margin-top:6px;">' . '</textarea></p>';
        echo '</div>';
        echo '<div class="sfs-hr-ds-modal-footer">';
        echo '<button class="button button-primary" onclick="this.closest(\'.sfs-hr-ds-modal\').classList.remove(\'active\');">' . esc_html__( 'Approve', 'sfs-hr' ) . '</button>';
        echo '<button class="button" onclick="this.closest(\'.sfs-hr-ds-modal\').classList.remove(\'active\');">' . esc_html__( 'Cancel', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Confirm modal
        echo '<div class="sfs-hr-ds-modal" id="sfs-ds-modal-confirm" onclick="if(event.target===this)this.classList.remove(\'active\');">';
        echo '<div class="sfs-hr-ds-modal-content" style="max-width:420px;">';
        echo '<div class="sfs-hr-ds-modal-header">';
        echo '<h3>' . esc_html__( 'Confirm Delete', 'sfs-hr' ) . '</h3>';
        echo '<button type="button" class="sfs-hr-ds-modal-close" onclick="this.closest(\'.sfs-hr-ds-modal\').classList.remove(\'active\');">&times;</button>';
        echo '</div>';
        echo '<div class="sfs-hr-ds-modal-body">';
        echo '<p>' . esc_html__( 'Are you sure you want to delete this record? This action cannot be undone.', 'sfs-hr' ) . '</p>';
        echo '</div>';
        echo '<div class="sfs-hr-ds-modal-footer">';
        echo '<button class="button button-link-delete" onclick="this.closest(\'.sfs-hr-ds-modal\').classList.remove(\'active\');">' . esc_html__( 'Delete', 'sfs-hr' ) . '</button>';
        echo '<button class="button" onclick="this.closest(\'.sfs-hr-ds-modal\').classList.remove(\'active\');">' . esc_html__( 'Cancel', 'sfs-hr' ) . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="sfs-ds-code"><code>.sfs-hr-ds-modal > .sfs-hr-ds-modal-content > header + body + footer</code></div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 11. NAVIGATION
     * ====================================================================== */
    private static function render_navigation_section(): void {
        echo '<div class="sfs-ds-section" id="ds-nav">';
        echo '<h2>' . esc_html__( 'Navigation', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'Breadcrumb navigation and dashboard link button.', 'sfs-hr' ) . '</p>';

        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Breadcrumb Nav', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-nav-demo">';
        echo '<div class="sfs-hr-nav" style="position:static;">';
        echo '<a class="sfs-hr-nav-dashboard" href="#">';
        echo '<span class="dashicons dashicons-dashboard"></span>';
        echo esc_html__( 'Dashboard', 'sfs-hr' );
        echo '</a>';
        echo '<div class="sfs-hr-nav-breadcrumb">';
        echo '<span><a href="#">' . esc_html__( 'Employees', 'sfs-hr' ) . '</a></span>';
        echo '<span>' . esc_html__( 'Employee Profile', 'sfs-hr' ) . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="sfs-ds-code"><code>.sfs-hr-nav > .sfs-hr-nav-dashboard + .sfs-hr-nav-breadcrumb</code></div>';
        echo '</div>';

        // Dashicons reference
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Common Dashicons', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row" style="gap:16px;">';

        $icons = [
            'dashicons-groups'         => 'groups',
            'dashicons-id-alt'         => 'id-alt',
            'dashicons-networking'     => 'networking',
            'dashicons-calendar-alt'   => 'calendar-alt',
            'dashicons-clock'          => 'clock',
            'dashicons-chart-area'     => 'chart-area',
            'dashicons-money-alt'      => 'money-alt',
            'dashicons-laptop'         => 'laptop',
            'dashicons-businessperson' => 'businessperson',
            'dashicons-admin-users'    => 'admin-users',
            'dashicons-dashboard'      => 'dashboard',
            'dashicons-edit'           => 'edit',
            'dashicons-plus-alt2'      => 'plus-alt2',
            'dashicons-download'       => 'download',
            'dashicons-upload'         => 'upload',
            'dashicons-search'         => 'search',
            'dashicons-trash'          => 'trash',
            'dashicons-yes-alt'        => 'yes-alt',
            'dashicons-dismiss'        => 'dismiss',
            'dashicons-warning'        => 'warning',
        ];
        foreach ( $icons as $cls => $name ) {
            echo '<span style="display:inline-flex;flex-direction:column;align-items:center;gap:4px;min-width:60px;">';
            echo '<span class="dashicons ' . esc_attr( $cls ) . '" style="font-size:24px;width:24px;height:24px;color:#2271b1;"></span>';
            echo '<span style="font-size:10px;color:#646970;">' . esc_html( $name ) . '</span>';
            echo '</span>';
        }
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 12. PROGRESS BARS
     * ====================================================================== */
    private static function render_progress_bars_section(): void {
        echo '<div class="sfs-ds-section" id="ds-progress">';
        echo '<h2>' . esc_html__( 'Progress Bars', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'Profile completion and generic progress indicators.', 'sfs-hr' ) . '</p>';

        $levels = [
            [ '25%', '#dc2626' ],
            [ '50%', '#d97706' ],
            [ '75%', '#2271b1' ],
            [ '100%', '#059669' ],
        ];

        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Completion Bars', 'sfs-hr' ) . '</h3>';
        foreach ( $levels as $l ) {
            echo '<div style="margin-bottom:12px;">';
            echo '<div style="font-size:12px;font-weight:500;margin-bottom:4px;">' . esc_html( $l[0] ) . ' ' . esc_html__( 'Complete', 'sfs-hr' ) . '</div>';
            echo '<div class="sfs-hr-completion-bar">';
            echo '<div class="sfs-hr-completion-fill" style="width:' . esc_attr( $l[0] ) . ';background:' . esc_attr( $l[1] ) . ';"></div>';
            echo '</div>';
            echo '</div>';
        }
        echo '<div class="sfs-ds-code"><code>.sfs-hr-completion-bar > .sfs-hr-completion-fill</code></div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * 13. TYPOGRAPHY & SPACING
     * ====================================================================== */
    private static function render_typography_section(): void {
        echo '<div class="sfs-ds-section" id="ds-typography">';
        echo '<h2>' . esc_html__( 'Typography & Spacing', 'sfs-hr' ) . '</h2>';
        echo '<p class="sfs-ds-desc">' . esc_html__( 'Standard text sizes and spacing used across the plugin.', 'sfs-hr' ) . '</p>';

        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Headings', 'sfs-hr' ) . '</h3>';
        echo '<h1 style="margin:0 0 8px;">Heading 1 (h1)</h1>';
        echo '<h2 style="margin:0 0 8px;">Heading 2 (h2)</h2>';
        echo '<h3 style="margin:0 0 8px;">Heading 3 (h3)</h3>';
        echo '<h4 style="margin:0 0 8px;">Heading 4 (h4)</h4>';
        echo '</div>';

        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Text Styles', 'sfs-hr' ) . '</h3>';
        echo '<p style="font-size:14px;color:#1d2327;margin:0 0 8px;">' . esc_html__( 'Regular body text (14px, #1d2327)', 'sfs-hr' ) . '</p>';
        echo '<p style="font-size:13px;color:#1d2327;margin:0 0 8px;">' . esc_html__( 'Small text (13px, #1d2327)', 'sfs-hr' ) . '</p>';
        echo '<p style="font-size:12px;color:#646970;margin:0 0 8px;">' . esc_html__( 'Meta / muted text (12px, #646970)', 'sfs-hr' ) . '</p>';
        echo '<p style="font-size:11px;color:#646970;margin:0 0 8px;">' . esc_html__( 'Fine print (11px, #646970)', 'sfs-hr' ) . '</p>';
        echo '<p style="margin:0 0 8px;"><a href="#">' . esc_html__( 'Link text (#2271b1)', 'sfs-hr' ) . '</a></p>';
        echo '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'Bold / strong text', 'sfs-hr' ) . '</strong></p>';
        echo '<p style="margin:0 0 8px;"><code>Inline code (monospace)</code></p>';
        echo '</div>';

        // Color palette
        echo '<div class="sfs-ds-subsection">';
        echo '<h3>' . esc_html__( 'Color Palette', 'sfs-hr' ) . '</h3>';
        echo '<div class="sfs-ds-row" style="gap:8px;">';

        $colors = [
            [ '#2271b1', 'Primary',  '#fff' ],
            [ '#135e96', 'Hover',    '#fff' ],
            [ '#1d2327', 'Text',     '#fff' ],
            [ '#646970', 'Muted',    '#fff' ],
            [ '#dcdcde', 'Border',   '#1d2327' ],
            [ '#f0f0f1', 'BG Light', '#1d2327' ],
            [ '#059669', 'Success',  '#fff' ],
            [ '#d97706', 'Warning',  '#fff' ],
            [ '#dc2626', 'Danger',   '#fff' ],
            [ '#d63638', 'WP Red',   '#fff' ],
        ];
        foreach ( $colors as $c ) {
            echo '<div style="display:flex;flex-direction:column;align-items:center;gap:4px;">';
            echo '<div style="width:48px;height:48px;border-radius:8px;background:' . esc_attr( $c[0] ) . ';border:1px solid rgba(0,0,0,.1);"></div>';
            echo '<span style="font-size:10px;color:#646970;">' . esc_html( $c[1] ) . '</span>';
            echo '<span style="font-size:9px;color:#999;">' . esc_html( $c[0] ) . '</span>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .sfs-ds-section
    }

    /* =========================================================================
     * STYLES
     * ====================================================================== */
    private static function output_styles(): void {
        echo '<style>
            /* ===== Design Samples Page Styles ===== */

            /* Table of contents */
            .sfs-ds-toc {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 20px;
                margin: 16px 0 24px;
            }
            .sfs-ds-toc h2 {
                margin: 0 0 12px;
                font-size: 14px;
                font-weight: 600;
            }
            .sfs-ds-toc-grid {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .sfs-ds-toc-link {
                display: inline-block;
                padding: 6px 14px;
                background: #f0f0f1;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                text-decoration: none;
                color: #2271b1;
                font-size: 13px;
                font-weight: 500;
                transition: all 0.15s ease;
            }
            .sfs-ds-toc-link:hover {
                background: #2271b1;
                color: #fff;
                border-color: #2271b1;
            }

            /* Sections */
            .sfs-ds-section {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 24px;
                margin-bottom: 20px;
            }
            .sfs-ds-section > h2 {
                margin: 0 0 4px;
                font-size: 18px;
                font-weight: 600;
                padding-bottom: 12px;
                border-bottom: 1px solid #f0f0f1;
            }
            .sfs-ds-desc {
                color: #646970;
                font-size: 13px;
                margin: 8px 0 20px;
            }
            .sfs-ds-subsection {
                margin-bottom: 24px;
                padding-bottom: 24px;
                border-bottom: 1px dashed #e5e7eb;
            }
            .sfs-ds-subsection:last-child {
                margin-bottom: 0;
                padding-bottom: 0;
                border-bottom: none;
            }
            .sfs-ds-subsection h3 {
                font-size: 13px;
                font-weight: 600;
                color: #1d2327;
                margin: 0 0 12px;
            }

            /* Flex row for inline items */
            .sfs-ds-row {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                align-items: center;
            }

            /* Code hint */
            .sfs-ds-code {
                margin-top: 10px;
                padding: 6px 10px;
                background: #f6f7f7;
                border-radius: 4px;
                font-size: 12px;
            }
            .sfs-ds-code code {
                background: none;
                padding: 0;
                color: #50575e;
            }

            /* Demo toolbar area */
            .sfs-ds-toolbar-demo {
                background: #f6f7f7;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 16px;
            }

            /* Demo nav area */
            .sfs-ds-nav-demo {
                background: #f6f7f7;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 12px;
            }

            /* Notice demo override (prevent WP hiding) */
            .sfs-ds-notice-demo {
                display: block !important;
                position: relative;
                margin: 8px 0 !important;
            }

            /* Status tabs demo */
            .sfs-ds-status-tabs {
                display: flex;
                gap: 0;
                border-bottom: 2px solid #dcdcde;
            }
            .sfs-ds-status-tabs .sfs-tab {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 10px 16px;
                text-decoration: none;
                color: #646970;
                font-size: 13px;
                font-weight: 500;
                border-bottom: 2px solid transparent;
                margin-bottom: -2px;
                transition: all 0.15s ease;
            }
            .sfs-ds-status-tabs .sfs-tab:hover {
                color: #2271b1;
            }
            .sfs-ds-status-tabs .sfs-tab.active {
                color: #2271b1;
                border-bottom-color: #2271b1;
            }
            .sfs-ds-status-tabs .sfs-tab .count {
                background: #f0f0f1;
                color: #646970;
                padding: 1px 7px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 600;
            }
            .sfs-ds-status-tabs .sfs-tab.active .count {
                background: #2271b1;
                color: #fff;
            }

            /* Dashboard grid (for card demos) */
            .sfs-ds-section .sfs-hr-dashboard-grid {
                display: flex;
                flex-wrap: wrap;
                gap: 16px;
                margin-top: 0;
            }
            .sfs-ds-section .sfs-hr-card {
                flex: 1 1 180px;
                background: #fff;
                border-radius: 8px;
                border: 1px solid #dcdcde;
                box-shadow: 0 1px 3px rgba(0,0,0,.04);
                padding: 16px;
                text-decoration: none;
                color: #1d2327;
            }
            .sfs-ds-section .sfs-hr-card h2 {
                margin: 0 0 8px;
                font-size: 14px;
                font-weight: 600;
            }
            .sfs-ds-section .sfs-hr-card .sfs-hr-card-count {
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 4px;
            }
            .sfs-ds-section .sfs-hr-card .sfs-hr-card-meta {
                font-size: 12px;
                color: #646970;
            }
            .sfs-ds-section .sfs-hr-approval-card {
                border-left: 4px solid #d63638 !important;
                background: linear-gradient(135deg, #fff 0%, #fef8f8 100%);
            }
            .sfs-ds-section .sfs-hr-approval-card .sfs-hr-card-count {
                color: #d63638;
            }

            /* Quick access grid for nav cards */
            .sfs-ds-section .sfs-hr-quick-access-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 10px;
            }
            .sfs-ds-section .sfs-hr-nav-card {
                display: flex;
                align-items: center;
                gap: 10px;
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                padding: 12px 14px;
                cursor: default;
                color: #1d2327;
            }
            .sfs-ds-section .sfs-hr-nav-card .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
                color: #2271b1;
            }
            .sfs-ds-section .sfs-hr-nav-card span:not(.dashicons) {
                font-size: 13px;
                font-weight: 500;
            }

            /* Table link styling */
            .sfs-ds-section a.emp-name {
                color: #2271b1;
                text-decoration: none;
                font-weight: 500;
            }
            .sfs-ds-section a.emp-name:hover {
                text-decoration: underline;
            }

            /* Hide-mobile class demo (visible on desktop) */
            .sfs-ds-section .hide-mobile {
                /* visible on desktop */
            }

            /* Progress bars */
            .sfs-hr-completion-bar {
                width: 100%;
                height: 8px;
                background: #f0f0f1;
                border-radius: 4px;
                overflow: hidden;
            }
            .sfs-hr-completion-fill {
                height: 100%;
                border-radius: 4px;
                transition: width 0.3s ease;
            }

            /* Inline alert */
            .sfs-ds-section .sfs-hr-alert {
                padding: 12px 16px;
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                color: #646970;
                font-size: 13px;
            }

            /* Pill badges (Loans / Resignation) */
            .sfs-hr-pill {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 500;
                white-space: nowrap;
            }
            .sfs-hr-pill--pending-gm {
                background: #fff3cd; color: #856404;
            }
            .sfs-hr-pill--pending-finance {
                background: #cce5ff; color: #004085;
            }
            .sfs-hr-pill--active {
                background: #d4edda; color: #155724;
            }
            .sfs-hr-pill--completed {
                background: #d1ecf1; color: #0c5460;
            }
            .sfs-hr-pill--rejected {
                background: #f8d7da; color: #721c24;
            }
            .sfs-hr-pill--cancelled {
                background: #e2e3e5; color: #383d41;
            }
            .sfs-hr-pill--pending {
                background: #fff3cd; color: #856404;
            }
            .sfs-hr-pill--approved {
                background: #d4edda; color: #155724;
            }
            .sfs-hr-pill--planned {
                background: #e2e3e5; color: #383d41;
            }
            .sfs-hr-pill--paid {
                background: #d1ecf1; color: #0c5460;
            }
            .sfs-hr-pill--partial {
                background: #fff3cd; color: #856404;
            }
            .sfs-hr-pill--skipped {
                background: #f8d7da; color: #721c24;
            }

            /* ===== Modal Styles ===== */
            .sfs-hr-ds-modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,.5);
                z-index: 100100;
                align-items: center;
                justify-content: center;
            }
            .sfs-hr-ds-modal.active {
                display: flex;
            }
            .sfs-hr-ds-modal-content {
                background: #fff;
                border-radius: 8px;
                width: 90%;
                max-width: 520px;
                box-shadow: 0 8px 32px rgba(0,0,0,.2);
                animation: sfs-ds-modal-in 0.2s ease;
            }
            @keyframes sfs-ds-modal-in {
                from { opacity: 0; transform: translateY(20px); }
                to   { opacity: 1; transform: translateY(0); }
            }
            .sfs-hr-ds-modal-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                border-bottom: 1px solid #dcdcde;
            }
            .sfs-hr-ds-modal-header h3 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
            }
            .sfs-hr-ds-modal-close {
                background: none;
                border: none;
                font-size: 22px;
                color: #646970;
                cursor: pointer;
                padding: 0;
                line-height: 1;
            }
            .sfs-hr-ds-modal-close:hover {
                color: #d63638;
            }
            .sfs-hr-ds-modal-body {
                padding: 20px;
            }
            .sfs-hr-ds-modal-footer {
                display: flex;
                gap: 8px;
                padding: 16px 20px;
                border-top: 1px solid #dcdcde;
            }

            /* Modal detail list */
            .sfs-hr-ds-detail-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            .sfs-hr-ds-detail-list li {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f1;
                font-size: 13px;
            }
            .sfs-hr-ds-detail-list li:last-child {
                border-bottom: none;
            }
            .sfs-hr-ds-label {
                color: #646970;
                font-weight: 500;
            }

            /* Responsive */
            @media (max-width: 782px) {
                .sfs-ds-section {
                    padding: 16px;
                }
                .sfs-ds-toc-grid {
                    gap: 6px;
                }
                .sfs-ds-toc-link {
                    font-size: 12px;
                    padding: 4px 10px;
                }
                .sfs-ds-section .sfs-hr-quick-access-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                .sfs-ds-section .hide-mobile {
                    display: none;
                }
            }
        </style>';
    }
}
