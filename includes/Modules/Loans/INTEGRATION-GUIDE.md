# Loans Module Integration Guide

This guide explains how to integrate the Loans Module with other HR modules.

---

## Table of Contents
1. [Available Helper Methods](#available-helper-methods)
2. [Employee Profile Integration](#employee-profile-integration)
3. [Leave Module Integration](#leave-module-integration)
4. [Final Exit Integration](#final-exit-integration)
5. [Employee List Integration](#employee-list-integration)
6. [Dashboard Integration](#dashboard-integration)
7. [Hooks & Filters](#hooks--filters)

---

## Available Helper Methods

The Loans Module provides several helper methods in `LoansModule` class:

### 1. Check if Employee Has Active Loans

```php
$employee_id = 123;
$has_loans = \SFS\HR\Modules\Loans\LoansModule::has_active_loans( $employee_id );

if ( $has_loans ) {
    // Employee has one or more active loans
}
```

**Returns:** `bool` - `true` if employee has at least one active loan with remaining balance > 0

---

### 2. Get Employee's Total Outstanding Balance

```php
$employee_id = 123;
$outstanding = \SFS\HR\Modules\Loans\LoansModule::get_outstanding_balance( $employee_id );

echo "Outstanding: " . number_format( $outstanding, 2 ) . " SAR";
```

**Returns:** `float` - Total remaining balance across all active loans

---

### 3. Get Employee Loan Summary

```php
$employee_id = 123;
$summary = \SFS\HR\Modules\Loans\Admin\DashboardWidget::get_employee_loan_summary( $employee_id );

if ( $summary ) {
    echo "Loans: " . $summary['loan_count'];
    echo "Outstanding: " . $summary['total_outstanding'] . " SAR";
    echo "Next Due: " . $summary['next_due_date'];
}
```

**Returns:** `array|null` with keys:
- `has_loans` (bool)
- `loan_count` (int)
- `total_outstanding` (float)
- `next_due_date` (string|null)

---

### 4. Render Employee Loan Badge

```php
$employee_id = 123;
echo \SFS\HR\Modules\Loans\Admin\DashboardWidget::render_employee_loan_badge( $employee_id );
```

**Returns:** HTML badge showing loan count and outstanding amount (or "—" if no loans)

---

## Employee Profile Integration

The Loans Module automatically integrates with Employee Profile pages via WordPress hooks.

### How It Works

The Employee Profile page fires these hooks:
- `do_action( 'sfs_hr_employee_tabs', $employee )` - For adding tab links
- `do_action( 'sfs_hr_employee_tab_content', $employee, $active_tab )` - For rendering tab content

The Loans Module hooks into these to display the Loans tab.

### Already Integrated ✅

Employee Profile pages at:
- **Admin:** `?page=sfs-hr-employee-profile&employee_id=123`
- **Frontend:** Shortcode `[sfs_hr_my_profile]`

Both automatically show the Loans tab if enabled in settings.

---

## Leave Module Integration

### Option 1: Display Loan Info (Recommended)

Show loan information on leave request details:

```php
// In leave request detail page
$employee_id = $leave_request->employee_id;
$summary = \SFS\HR\Modules\Loans\Admin\DashboardWidget::get_employee_loan_summary( $employee_id );

if ( $summary ) {
    echo '<div class="notice notice-info">';
    echo '<p>';
    echo '<strong>' . esc_html__( 'Note:', 'sfs-hr' ) . '</strong> ';
    echo sprintf(
        esc_html__( 'This employee has %d active loan(s) with %s SAR outstanding.', 'sfs-hr' ),
        $summary['loan_count'],
        number_format( $summary['total_outstanding'], 2 )
    );
    echo '</p>';
    echo '</div>';
}
```

### Option 2: Add Finance to Approval Workflow

Require Finance approval for employees with active loans:

```php
// In leave approval workflow
$employee_id = $leave_request->employee_id;

if ( \SFS\HR\Modules\Loans\LoansModule::has_active_loans( $employee_id ) ) {
    // Add Finance to approval chain
    $approvers[] = [
        'role' => 'finance',
        'reason' => __( 'Employee has active loan(s)', 'sfs-hr' ),
    ];
}
```

---

## Final Exit Integration

**IMPORTANT:** Block final exit if employee has outstanding loans!

### Implementation

```php
// In Final Exit processing - BEFORE completing exit
$employee_id = $final_exit->employee_id;

// Check for active loans
$has_loans = \SFS\HR\Modules\Loans\LoansModule::has_active_loans( $employee_id );
$outstanding = \SFS\HR\Modules\Loans\LoansModule::get_outstanding_balance( $employee_id );

if ( $has_loans && $outstanding > 0 ) {
    // Block exit completion
    wp_die(
        sprintf(
            esc_html__( 'Cannot complete final exit. Employee has outstanding loan balance of %s SAR. Please settle with Finance department first.', 'sfs-hr' ),
            number_format( $outstanding, 2 )
        ),
        esc_html__( 'Outstanding Loans', 'sfs-hr' ),
        [
            'back_link' => true,
            'response' => 403,
        ]
    );
}

// Otherwise, proceed with exit
```

### With Override Option

```php
// Allow override for special cases (e.g., loan forgiveness)
$force_exit = isset( $_POST['force_exit_with_loans'] ) && current_user_can( 'sfs_hr.admin' );

if ( $has_loans && $outstanding > 0 && ! $force_exit ) {
    // Show warning with override checkbox
    echo '<div class="notice notice-error">';
    echo '<p><strong>' . esc_html__( 'Warning: Outstanding Loans', 'sfs-hr' ) . '</strong></p>';
    echo '<p>' . sprintf(
        esc_html__( 'Employee has %s SAR in outstanding loans.', 'sfs-hr' ),
        number_format( $outstanding, 2 )
    ) . '</p>';
    echo '<label>';
    echo '<input type="checkbox" name="force_exit_with_loans" value="1" /> ';
    echo esc_html__( 'Override and complete exit anyway (CEO/GM approval required)', 'sfs-hr' );
    echo '</label>';
    echo '</div>';
}
```

---

## Employee List Integration

Add loan indicator column to employee lists:

### In Employee List Table

```php
// Add column header
function my_employee_list_columns( $columns ) {
    $columns['loans'] = __( 'Loans', 'sfs-hr' );
    return $columns;
}
add_filter( 'sfs_hr_employee_list_columns', 'my_employee_list_columns' );

// Add column content
function my_employee_list_column_content( $column, $employee ) {
    if ( $column === 'loans' ) {
        echo \SFS\HR\Modules\Loans\Admin\DashboardWidget::render_employee_loan_badge( $employee->id );
    }
}
add_action( 'sfs_hr_employee_list_column', 'my_employee_list_column_content', 10, 2 );
```

---

## Dashboard Integration

The Loans Module provides a dashboard widget that shows:
- Active loans count
- Total outstanding amount
- Employees with loans
- Pending approvals
- Monthly statistics

### WordPress Dashboard

Automatically appears for users with `sfs_hr.manage` capability.

### Custom HR Dashboard

If you have a custom HR dashboard, use this hook:

```php
// In your HR dashboard page
do_action( 'sfs_hr_dashboard_widgets' );
```

The Loans Module will render its widget automatically.

---

## Hooks & Filters

### Actions

#### `sfs_hr_employee_tabs`
Fired when rendering employee profile tabs.

```php
do_action( 'sfs_hr_employee_tabs', $employee );
```

**Used by:** Loans Module to add Loans tab

---

#### `sfs_hr_employee_tab_content`
Fired when rendering employee profile tab content.

```php
do_action( 'sfs_hr_employee_tab_content', $employee, $active_tab );
```

**Used by:** Loans Module to render Loans tab content

---

#### `sfs_hr_dashboard_widgets`
Fired when rendering HR dashboard widgets.

```php
do_action( 'sfs_hr_dashboard_widgets' );
```

**Used by:** Loans Module to render statistics widget

---

### Filters

#### `sfs_hr_employee_has_clearance_issues`
Filter to add loan clearance check to employee exit process.

```php
add_filter( 'sfs_hr_employee_has_clearance_issues', function( $has_issues, $employee_id ) {
    if ( \SFS\HR\Modules\Loans\LoansModule::has_active_loans( $employee_id ) ) {
        return true; // Block clearance
    }
    return $has_issues;
}, 10, 2 );
```

---

## Example: Complete Leave Integration

```php
/**
 * Add Loans info to Leave Request page
 */
function add_loans_to_leave_request( $employee_id, $leave_request ) {
    $summary = \SFS\HR\Modules\Loans\Admin\DashboardWidget::get_employee_loan_summary( $employee_id );

    if ( ! $summary ) {
        return; // No loans
    }

    echo '<div class="leave-request-loans-info" style="margin:15px 0;padding:12px;background:#fff3cd;border-left:4px solid #ffc107;">';
    echo '<h4 style="margin:0 0 8px 0;">' . esc_html__( '💰 Active Loans', 'sfs-hr' ) . '</h4>';
    echo '<p style="margin:0;">';
    echo sprintf(
        esc_html__( 'This employee has %d active loan(s) with %s SAR outstanding. Next payment due: %s', 'sfs-hr' ),
        $summary['loan_count'],
        '<strong>' . number_format( $summary['total_outstanding'], 2 ) . '</strong>',
        $summary['next_due_date'] ? wp_date( 'F j, Y', strtotime( $summary['next_due_date'] ) ) : __( 'N/A', 'sfs-hr' )
    );
    echo '</p>';
    echo '<p style="margin:8px 0 0 0;"><a href="' . esc_url( admin_url( 'admin.php?page=sfs-hr-loans&employee_id=' . $employee_id ) ) . '">' . esc_html__( 'View Loan Details →', 'sfs-hr' ) . '</a></p>';
    echo '</div>';
}
```

---

## Best Practices

1. **Always check for active loans** before final exit
2. **Display loan info** on employee-related pages (don't hide it)
3. **Use helper methods** - don't query database directly
4. **Respect privacy** - only show loan info to authorized users
5. **Test thoroughly** - loan data affects financial calculations

---

## Need Help?

If you need additional integration points or helper methods, add them to:
- `LoansModule.php` - For core business logic
- `class-dashboard-widget.php` - For display helpers

---

## Future Integration Points

When these modules are built, integrate as described above:

- ✅ **Employee Profile** - Already integrated
- ⏳ **Leave Module** - See "Leave Module Integration"
- ⏳ **Final Exit** - See "Final Exit Integration"
- ⏳ **Resignation** - Similar to Final Exit
- ⏳ **Payroll** - Auto-deduct installments
- ⏳ **Employee Transfer** - Transfer loans between departments

---

**Last Updated:** December 2025
**Version:** 1.0
