<?php
namespace SFS\HR\Modules\Settlement;

use SFS\HR\Core\Helpers;

if (!defined('ABSPATH')) { exit; }

class SettlementModule {

    public function hooks(): void {
        add_action('admin_menu', [$this, 'menu']);

        // Form handlers
        add_action('admin_post_sfs_hr_settlement_create',  [$this, 'handle_create']);
        add_action('admin_post_sfs_hr_settlement_update',  [$this, 'handle_update']);
        add_action('admin_post_sfs_hr_settlement_approve', [$this, 'handle_approve']);
        add_action('admin_post_sfs_hr_settlement_reject',  [$this, 'handle_reject']);
        add_action('admin_post_sfs_hr_settlement_payment', [$this, 'handle_payment']);
    }

    public function menu(): void {
        add_submenu_page(
            'sfs-hr',
            __('Settlements', 'sfs-hr'),
            __('Settlements', 'sfs-hr'),
            'sfs_hr.manage',
            'sfs-hr-settlements',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        global $wpdb;

        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        $settlement_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

        if ($action === 'create') {
            $this->render_create_form();
        } elseif ($action === 'edit' && $settlement_id) {
            $this->render_edit_form($settlement_id);
        } elseif ($action === 'view' && $settlement_id) {
            $this->render_view($settlement_id);
        } else {
            $this->render_list();
        }
    }

    /* ---------------------------------- List View ---------------------------------- */

    private function render_list(): void {
        global $wpdb;

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'pending';
        $page   = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $pp     = 20;
        $offset = ($page - 1) * $pp;

        $table = $wpdb->prefix . 'sfs_hr_settlements';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $where  = '1=1';
        $params = [];

        if (in_array($status, ['pending', 'approved', 'rejected', 'paid'], true)) {
            $where   .= " AND s.status = %s";
            $params[] = $status;
        }

        $sql_total = "SELECT COUNT(*)
                      FROM $table s
                      JOIN $emp_t e ON e.id = s.employee_id
                      WHERE $where";
        $total = $params ? (int)$wpdb->get_var($wpdb->prepare($sql_total, ...$params))
                         : (int)$wpdb->get_var($sql_total);

        $sql = "SELECT s.*, e.employee_code, e.first_name, e.last_name
                FROM $table s
                JOIN $emp_t e ON e.id = s.employee_id
                WHERE $where
                ORDER BY s.id DESC
                LIMIT %d OFFSET %d";

        $params_all = array_merge($params, [$pp, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_all), ARRAY_A);

        Helpers::render_admin_nav();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('End of Service Settlements', 'sfs-hr'); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&action=create')); ?>" class="page-title-action">
                <?php esc_html_e('Create Settlement', 'sfs-hr'); ?>
            </a>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&status=pending')); ?>"
                    class="<?php echo $status === 'pending' ? 'current' : ''; ?>">
                    <?php esc_html_e('Pending', 'sfs-hr'); ?></a> | </li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&status=approved')); ?>"
                    class="<?php echo $status === 'approved' ? 'current' : ''; ?>">
                    <?php esc_html_e('Approved', 'sfs-hr'); ?></a> | </li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&status=paid')); ?>"
                    class="<?php echo $status === 'paid' ? 'current' : ''; ?>">
                    <?php esc_html_e('Paid', 'sfs-hr'); ?></a> | </li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&status=rejected')); ?>"
                    class="<?php echo $status === 'rejected' ? 'current' : ''; ?>">
                    <?php esc_html_e('Rejected', 'sfs-hr'); ?></a></li>
            </ul>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Employee', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Last Working Day', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Service Years', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Total Settlement', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Status', 'sfs-hr'); ?></th>
                        <th><?php esc_html_e('Actions', 'sfs-hr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7"><?php esc_html_e('No settlements found.', 'sfs-hr'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['id']); ?></td>
                                <td><?php echo esc_html($row['first_name'] . ' ' . $row['last_name']); ?><br>
                                    <small><?php echo esc_html($row['employee_code']); ?></small></td>
                                <td><?php echo esc_html($row['last_working_day']); ?></td>
                                <td><?php echo esc_html(number_format($row['years_of_service'], 2)); ?> <?php esc_html_e('years', 'sfs-hr'); ?></td>
                                <td><strong><?php echo esc_html(number_format($row['total_settlement'], 2)); ?></strong></td>
                                <td><?php echo $this->status_badge($row['status']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&action=view&id=' . $row['id'])); ?>" class="button button-small">
                                        <?php esc_html_e('View', 'sfs-hr'); ?>
                                    </a>
                                    <?php if ($row['status'] === 'pending'): ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements&action=edit&id=' . $row['id'])); ?>" class="button button-small">
                                            <?php esc_html_e('Edit', 'sfs-hr'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total > $pp): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        $total_pages = ceil($total / $pp);
                        echo paginate_links([
                            'base'    => add_query_arg('paged', '%#%'),
                            'format'  => '',
                            'current' => $page,
                            'total'   => $total_pages,
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------- Create Form ---------------------------------- */

    private function render_create_form(): void {
        global $wpdb;

        $emp_table = $wpdb->prefix . 'sfs_hr_employees';
        $resign_table = $wpdb->prefix . 'sfs_hr_resignations';

        // Get approved resignations without settlements
        $sql = "SELECT r.*, e.employee_code, e.first_name, e.last_name, e.base_salary, e.hire_date, e.hired_at
                FROM $resign_table r
                JOIN $emp_table e ON e.id = r.employee_id
                WHERE r.status = 'approved'
                AND r.id NOT IN (SELECT resignation_id FROM {$wpdb->prefix}sfs_hr_settlements WHERE resignation_id IS NOT NULL)
                ORDER BY r.last_working_day DESC";

        $pending_resignations = $wpdb->get_results($sql, ARRAY_A);

        Helpers::render_admin_nav();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Create Settlement', 'sfs-hr'); ?></h1>

            <?php if (empty($pending_resignations)): ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e('No approved resignations pending settlement. All approved resignations have been processed.', 'sfs-hr'); ?></p>
                </div>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements')); ?>" class="button">&larr; <?php esc_html_e('Back to Settlements', 'sfs-hr'); ?></a></p>
            <?php else: ?>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="settlement-form">
                <?php wp_nonce_field('sfs_hr_settlement_create'); ?>
                <input type="hidden" name="action" value="sfs_hr_settlement_create">

                <table class="form-table">
                    <tr>
                        <th><label for="resignation_id"><?php esc_html_e('Select Resignation:', 'sfs-hr'); ?> <span style="color:red;">*</span></label></th>
                        <td>
                            <select name="resignation_id" id="resignation_id" required style="width:100%;max-width:500px;" onchange="loadResignationDetails(this.value)">
                                <option value=""><?php esc_html_e('-- Select Resignation --', 'sfs-hr'); ?></option>
                                <?php foreach ($pending_resignations as $r): ?>
                                    <option value="<?php echo esc_attr($r['id']); ?>"
                                            data-employee-id="<?php echo esc_attr($r['employee_id']); ?>"
                                            data-last-working-day="<?php echo esc_attr($r['last_working_day']); ?>"
                                            data-employee-name="<?php echo esc_attr($r['first_name'] . ' ' . $r['last_name']); ?>"
                                            data-base-salary="<?php echo esc_attr($r['base_salary'] ?? 0); ?>"
                                            data-hire-date="<?php echo esc_attr($r['hire_date'] ?? $r['hired_at'] ?? ''); ?>">
                                        <?php echo esc_html($r['first_name'] . ' ' . $r['last_name'] . ' - ' . $r['employee_code']); ?>
                                        (<?php esc_html_e('LWD:', 'sfs-hr'); ?> <?php echo esc_html($r['last_working_day']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Select an approved resignation to create a settlement.', 'sfs-hr'); ?></p>
                        </td>
                    </tr>
                </table>

                <div id="settlement-details" style="display:none;margin-top:20px;">
                    <h2><?php esc_html_e('Settlement Calculation', 'sfs-hr'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Employee Name:', 'sfs-hr'); ?></th>
                            <td><span id="display-employee-name"></span></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Last Working Day:', 'sfs-hr'); ?></th>
                            <td><span id="display-last-working-day"></span></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Years of Service:', 'sfs-hr'); ?></th>
                            <td><span id="display-years-of-service"></span></td>
                        </tr>
                        <tr>
                            <th><label for="basic_salary"><?php esc_html_e('Basic Salary:', 'sfs-hr'); ?> <span style="color:red;">*</span></label></th>
                            <td><input type="number" name="basic_salary" id="basic_salary" step="0.01" required style="width:200px;" onchange="calculateSettlement()"></td>
                        </tr>
                        <tr>
                            <th><label for="gratuity_amount"><?php esc_html_e('Gratuity Amount:', 'sfs-hr'); ?></label></th>
                            <td>
                                <input type="number" name="gratuity_amount" id="gratuity_amount" step="0.01" readonly style="width:200px;background:#f5f5f5;">
                                <button type="button" onclick="calculateGratuity()" class="button"><?php esc_html_e('Calculate', 'sfs-hr'); ?></button>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="unused_leave_days"><?php esc_html_e('Unused Leave Days:', 'sfs-hr'); ?></label></th>
                            <td><input type="number" name="unused_leave_days" id="unused_leave_days" value="0" min="0" style="width:200px;" onchange="calculateSettlement()"></td>
                        </tr>
                        <tr>
                            <th><label for="leave_encashment"><?php esc_html_e('Leave Encashment:', 'sfs-hr'); ?></label></th>
                            <td><input type="number" name="leave_encashment" id="leave_encashment" step="0.01" readonly style="width:200px;background:#f5f5f5;"></td>
                        </tr>
                        <tr>
                            <th><label for="final_salary"><?php esc_html_e('Final Salary:', 'sfs-hr'); ?></label></th>
                            <td><input type="number" name="final_salary" id="final_salary" step="0.01" value="0" style="width:200px;" onchange="calculateSettlement()"></td>
                        </tr>
                        <tr>
                            <th><label for="other_allowances"><?php esc_html_e('Other Allowances:', 'sfs-hr'); ?></label></th>
                            <td><input type="number" name="other_allowances" id="other_allowances" step="0.01" value="0" style="width:200px;" onchange="calculateSettlement()"></td>
                        </tr>
                        <tr>
                            <th><label for="deductions"><?php esc_html_e('Deductions:', 'sfs-hr'); ?></label></th>
                            <td><input type="number" name="deductions" id="deductions" step="0.01" value="0" style="width:200px;" onchange="calculateSettlement()"></td>
                        </tr>
                        <tr>
                            <th><label for="deduction_notes"><?php esc_html_e('Deduction Notes:', 'sfs-hr'); ?></label></th>
                            <td><textarea name="deduction_notes" id="deduction_notes" rows="3" style="width:100%;max-width:500px;"></textarea></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Total Settlement:', 'sfs-hr'); ?></th>
                            <td><strong style="font-size:18px;color:#0073aa;" id="display-total-settlement">0.00</strong></td>
                        </tr>
                    </table>

                    <input type="hidden" name="employee_id" id="employee_id">
                    <input type="hidden" name="last_working_day" id="last_working_day">
                    <input type="hidden" name="years_of_service" id="years_of_service">
                    <input type="hidden" name="total_settlement" id="total_settlement">

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Create Settlement', 'sfs-hr'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements')); ?>" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></a>
                    </p>
                </div>
            </form>

            <script>
            function loadResignationDetails(resignationId) {
                if (!resignationId) {
                    document.getElementById('settlement-details').style.display = 'none';
                    return;
                }

                const select = document.getElementById('resignation_id');
                const option = select.options[select.selectedIndex];

                const employeeId = option.getAttribute('data-employee-id');
                const lastWorkingDay = option.getAttribute('data-last-working-day');
                const employeeName = option.getAttribute('data-employee-name');
                const baseSalary = parseFloat(option.getAttribute('data-base-salary')) || 0;
                const hireDate = option.getAttribute('data-hire-date');

                // Calculate years of service
                const yearsOfService = calculateYearsOfService(hireDate, lastWorkingDay);

                // Populate fields
                document.getElementById('employee_id').value = employeeId;
                document.getElementById('last_working_day').value = lastWorkingDay;
                document.getElementById('years_of_service').value = yearsOfService;
                document.getElementById('basic_salary').value = baseSalary.toFixed(2);

                document.getElementById('display-employee-name').textContent = employeeName;
                document.getElementById('display-last-working-day').textContent = lastWorkingDay;
                document.getElementById('display-years-of-service').textContent = yearsOfService.toFixed(2) + ' years';

                document.getElementById('settlement-details').style.display = 'block';

                // Auto-calculate gratuity
                calculateGratuity();
            }

            function calculateYearsOfService(hireDate, lastWorkingDay) {
                if (!hireDate || !lastWorkingDay) return 0;

                const hire = new Date(hireDate);
                const lwd = new Date(lastWorkingDay);
                const diffTime = Math.abs(lwd - hire);
                const diffDays = diffTime / (1000 * 60 * 60 * 24);
                return diffDays / 365.25;
            }

            function calculateGratuity() {
                const yearsOfService = parseFloat(document.getElementById('years_of_service').value) || 0;
                const baseSalary = parseFloat(document.getElementById('basic_salary').value) || 0;

                // Gratuity calculation: 21 days salary for each year of service (for first 5 years)
                // 30 days salary for each year beyond 5 years
                let gratuity = 0;

                if (yearsOfService <= 5) {
                    gratuity = (baseSalary / 30) * 21 * yearsOfService;
                } else {
                    const first5Years = (baseSalary / 30) * 21 * 5;
                    const remainingYears = yearsOfService - 5;
                    const afterYears = (baseSalary / 30) * 30 * remainingYears;
                    gratuity = first5Years + afterYears;
                }

                document.getElementById('gratuity_amount').value = gratuity.toFixed(2);
                calculateSettlement();
            }

            function calculateSettlement() {
                const gratuity = parseFloat(document.getElementById('gratuity_amount').value) || 0;
                const unusedLeaveDays = parseFloat(document.getElementById('unused_leave_days').value) || 0;
                const baseSalary = parseFloat(document.getElementById('basic_salary').value) || 0;
                const finalSalary = parseFloat(document.getElementById('final_salary').value) || 0;
                const otherAllowances = parseFloat(document.getElementById('other_allowances').value) || 0;
                const deductions = parseFloat(document.getElementById('deductions').value) || 0;

                // Calculate leave encashment (daily rate * unused days)
                const dailyRate = baseSalary / 30;
                const leaveEncashment = dailyRate * unusedLeaveDays;
                document.getElementById('leave_encashment').value = leaveEncashment.toFixed(2);

                // Calculate total
                const total = gratuity + leaveEncashment + finalSalary + otherAllowances - deductions;

                document.getElementById('total_settlement').value = total.toFixed(2);
                document.getElementById('display-total-settlement').textContent = total.toFixed(2);
            }
            </script>

            <?php endif; ?>
        </div>
        <?php
    }

    /* ---------------------------------- View Settlement ---------------------------------- */

    private function render_view(int $settlement_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';
        $emp_t = $wpdb->prefix . 'sfs_hr_employees';

        $settlement = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, e.employee_code, e.first_name, e.last_name, e.position, e.dept_id
             FROM $table s
             JOIN $emp_t e ON e.id = s.employee_id
             WHERE s.id = %d",
            $settlement_id
        ), ARRAY_A);

        if (!$settlement) {
            wp_die(__('Settlement not found.', 'sfs-hr'));
        }

        Helpers::render_admin_nav();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Settlement Details', 'sfs-hr'); ?> #<?php echo esc_html($settlement['id']); ?></h1>

            <div style="background:#fff;padding:20px;border:1px solid #ddd;margin-top:20px;">
                <h2><?php esc_html_e('Employee Information', 'sfs-hr'); ?></h2>
                <table class="widefat">
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('Employee:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['first_name'] . ' ' . $settlement['last_name']); ?> (<?php echo esc_html($settlement['employee_code']); ?>)</td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Position:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['position'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Last Working Day:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['last_working_day']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Years of Service:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['years_of_service'], 2)); ?> <?php esc_html_e('years', 'sfs-hr'); ?></td>
                    </tr>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e('Settlement Breakdown', 'sfs-hr'); ?></h2>
                <table class="widefat">
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('Basic Salary:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['basic_salary'], 2)); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Gratuity Amount:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['gratuity_amount'], 2)); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Leave Encashment:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['leave_encashment'], 2)); ?>
                            (<?php echo esc_html($settlement['unused_leave_days']); ?> <?php esc_html_e('days', 'sfs-hr'); ?>)</td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Final Salary:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['final_salary'], 2)); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Other Allowances:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['other_allowances'], 2)); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Deductions:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html(number_format($settlement['deductions'], 2)); ?>
                            <?php if (!empty($settlement['deduction_notes'])): ?>
                                <br><small><?php echo esc_html($settlement['deduction_notes']); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr style="background:#f5f5f5;font-weight:bold;font-size:16px;">
                        <th><?php esc_html_e('Total Settlement:', 'sfs-hr'); ?></th>
                        <td style="color:#0073aa;"><?php echo esc_html(number_format($settlement['total_settlement'], 2)); ?></td>
                    </tr>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e('Clearance Status', 'sfs-hr'); ?></h2>
                <table class="widefat">
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('Asset Clearance:', 'sfs-hr'); ?></th>
                        <td><?php echo $this->status_badge($settlement['asset_clearance_status']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Document Clearance:', 'sfs-hr'); ?></th>
                        <td><?php echo $this->status_badge($settlement['document_clearance_status']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Finance Clearance:', 'sfs-hr'); ?></th>
                        <td><?php echo $this->status_badge($settlement['finance_clearance_status']); ?></td>
                    </tr>
                </table>

                <h2 style="margin-top:30px;"><?php esc_html_e('Approval & Payment', 'sfs-hr'); ?></h2>
                <table class="widefat">
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('Status:', 'sfs-hr'); ?></th>
                        <td><?php echo $this->status_badge($settlement['status']); ?></td>
                    </tr>
                    <?php if (!empty($settlement['approver_note'])): ?>
                    <tr>
                        <th><?php esc_html_e('Approver Note:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['approver_note']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($settlement['payment_date'])): ?>
                    <tr>
                        <th><?php esc_html_e('Payment Date:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['payment_date']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($settlement['payment_reference'])): ?>
                    <tr>
                        <th><?php esc_html_e('Payment Reference:', 'sfs-hr'); ?></th>
                        <td><?php echo esc_html($settlement['payment_reference']); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php if ($settlement['status'] === 'pending'): ?>
                <div style="margin-top:30px;">
                    <a href="#" onclick="return showApproveModal();" class="button button-primary">
                        <?php esc_html_e('Approve Settlement', 'sfs-hr'); ?>
                    </a>
                    <a href="#" onclick="return showRejectModal();" class="button">
                        <?php esc_html_e('Reject', 'sfs-hr'); ?>
                    </a>
                </div>
                <?php elseif ($settlement['status'] === 'approved'): ?>
                <div style="margin-top:30px;">
                    <a href="#" onclick="return showPaymentModal();" class="button button-primary">
                        <?php esc_html_e('Mark as Paid', 'sfs-hr'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <p style="margin-top:20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sfs-hr-settlements')); ?>" class="button">&larr; <?php esc_html_e('Back to Settlements', 'sfs-hr'); ?></a>
            </p>

            <!-- Approve Modal -->
            <div id="approve-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
                <div style="background:#fff; padding:20px; max-width:500px; margin:100px auto; border:1px solid #ccc;">
                    <h2><?php esc_html_e('Approve Settlement', 'sfs-hr'); ?></h2>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sfs_hr_settlement_approve'); ?>
                        <input type="hidden" name="action" value="sfs_hr_settlement_approve">
                        <input type="hidden" name="settlement_id" value="<?php echo esc_attr($settlement['id']); ?>">
                        <p>
                            <label><?php esc_html_e('Note (optional):', 'sfs-hr'); ?></label><br>
                            <textarea name="note" rows="4" style="width:100%;"></textarea>
                        </p>
                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Approve', 'sfs-hr'); ?></button>
                            <button type="button" onclick="hideApproveModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                        </p>
                    </form>
                </div>
            </div>

            <!-- Reject Modal -->
            <div id="reject-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
                <div style="background:#fff; padding:20px; max-width:500px; margin:100px auto; border:1px solid #ccc;">
                    <h2><?php esc_html_e('Reject Settlement', 'sfs-hr'); ?></h2>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sfs_hr_settlement_reject'); ?>
                        <input type="hidden" name="action" value="sfs_hr_settlement_reject">
                        <input type="hidden" name="settlement_id" value="<?php echo esc_attr($settlement['id']); ?>">
                        <p>
                            <label><?php esc_html_e('Reason for rejection:', 'sfs-hr'); ?></label><br>
                            <textarea name="note" rows="4" style="width:100%;" required></textarea>
                        </p>
                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Reject', 'sfs-hr'); ?></button>
                            <button type="button" onclick="hideRejectModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                        </p>
                    </form>
                </div>
            </div>

            <!-- Payment Modal -->
            <div id="payment-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;">
                <div style="background:#fff; padding:20px; max-width:500px; margin:100px auto; border:1px solid #ccc;">
                    <h2><?php esc_html_e('Record Payment', 'sfs-hr'); ?></h2>
                    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('sfs_hr_settlement_payment'); ?>
                        <input type="hidden" name="action" value="sfs_hr_settlement_payment">
                        <input type="hidden" name="settlement_id" value="<?php echo esc_attr($settlement['id']); ?>">
                        <p>
                            <label><?php esc_html_e('Payment Date:', 'sfs-hr'); ?></label><br>
                            <input type="date" name="payment_date" required style="width:100%;">
                        </p>
                        <p>
                            <label><?php esc_html_e('Payment Reference:', 'sfs-hr'); ?></label><br>
                            <input type="text" name="payment_reference" style="width:100%;">
                        </p>
                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e('Mark as Paid', 'sfs-hr'); ?></button>
                            <button type="button" onclick="hidePaymentModal();" class="button"><?php esc_html_e('Cancel', 'sfs-hr'); ?></button>
                        </p>
                    </form>
                </div>
            </div>

            <script>
            function showApproveModal() {
                document.getElementById('approve-modal').style.display = 'block';
                return false;
            }
            function hideApproveModal() {
                document.getElementById('approve-modal').style.display = 'none';
            }
            function showRejectModal() {
                document.getElementById('reject-modal').style.display = 'block';
                return false;
            }
            function hideRejectModal() {
                document.getElementById('reject-modal').style.display = 'none';
            }
            function showPaymentModal() {
                document.getElementById('payment-modal').style.display = 'block';
                return false;
            }
            function hidePaymentModal() {
                document.getElementById('payment-modal').style.display = 'none';
            }
            </script>
        </div>
        <?php
    }

    /* ---------------------------------- Form Handlers ---------------------------------- */

    public function handle_create(): void {
        check_admin_referer('sfs_hr_settlement_create');
        Helpers::require_cap('sfs_hr.manage');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';

        $employee_id = intval($_POST['employee_id'] ?? 0);
        $resignation_id = intval($_POST['resignation_id'] ?? 0);
        $last_working_day = sanitize_text_field($_POST['last_working_day'] ?? '');
        $years_of_service = floatval($_POST['years_of_service'] ?? 0);
        $basic_salary = floatval($_POST['basic_salary'] ?? 0);
        $gratuity_amount = floatval($_POST['gratuity_amount'] ?? 0);
        $unused_leave_days = intval($_POST['unused_leave_days'] ?? 0);
        $leave_encashment = floatval($_POST['leave_encashment'] ?? 0);
        $final_salary = floatval($_POST['final_salary'] ?? 0);
        $other_allowances = floatval($_POST['other_allowances'] ?? 0);
        $deductions = floatval($_POST['deductions'] ?? 0);
        $deduction_notes = sanitize_textarea_field($_POST['deduction_notes'] ?? '');
        $total_settlement = floatval($_POST['total_settlement'] ?? 0);

        $now = current_time('mysql');

        $wpdb->insert($table, [
            'employee_id' => $employee_id,
            'resignation_id' => $resignation_id,
            'settlement_date' => current_time('Y-m-d'),
            'last_working_day' => $last_working_day,
            'years_of_service' => $years_of_service,
            'basic_salary' => $basic_salary,
            'gratuity_amount' => $gratuity_amount,
            'leave_encashment' => $leave_encashment,
            'unused_leave_days' => $unused_leave_days,
            'final_salary' => $final_salary,
            'other_allowances' => $other_allowances,
            'deductions' => $deductions,
            'deduction_notes' => $deduction_notes,
            'total_settlement' => $total_settlement,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-settlements'),
            'success',
            __('Settlement created successfully.', 'sfs-hr')
        );
    }

    public function handle_approve(): void {
        check_admin_referer('sfs_hr_settlement_approve');
        Helpers::require_cap('sfs_hr.manage');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';

        $settlement_id = intval($_POST['settlement_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        $wpdb->update($table, [
            'status' => 'approved',
            'approver_id' => get_current_user_id(),
            'approver_note' => $note,
            'decided_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $settlement_id]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-settlements&action=view&id=' . $settlement_id),
            'success',
            __('Settlement approved successfully.', 'sfs-hr')
        );
    }

    public function handle_reject(): void {
        check_admin_referer('sfs_hr_settlement_reject');
        Helpers::require_cap('sfs_hr.manage');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';

        $settlement_id = intval($_POST['settlement_id'] ?? 0);
        $note = sanitize_textarea_field($_POST['note'] ?? '');

        $wpdb->update($table, [
            'status' => 'rejected',
            'approver_id' => get_current_user_id(),
            'approver_note' => $note,
            'decided_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $settlement_id]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-settlements'),
            'success',
            __('Settlement rejected.', 'sfs-hr')
        );
    }

    public function handle_payment(): void {
        check_admin_referer('sfs_hr_settlement_payment');
        Helpers::require_cap('sfs_hr.manage');

        global $wpdb;
        $table = $wpdb->prefix . 'sfs_hr_settlements';

        $settlement_id = intval($_POST['settlement_id'] ?? 0);
        $payment_date = sanitize_text_field($_POST['payment_date'] ?? '');
        $payment_reference = sanitize_text_field($_POST['payment_reference'] ?? '');

        $wpdb->update($table, [
            'status' => 'paid',
            'payment_date' => $payment_date,
            'payment_reference' => $payment_reference,
            'updated_at' => current_time('mysql'),
        ], ['id' => $settlement_id]);

        Helpers::redirect_with_notice(
            admin_url('admin.php?page=sfs-hr-settlements&action=view&id=' . $settlement_id),
            'success',
            __('Payment recorded successfully.', 'sfs-hr')
        );
    }

    /* ---------------------------------- Helper Methods ---------------------------------- */

    private function status_badge(string $status): string {
        $colors = [
            'pending'  => '#f0ad4e',
            'approved' => '#5cb85c',
            'rejected' => '#d9534f',
            'paid'     => '#0073aa',
            'cleared'  => '#5cb85c',
        ];

        $color = $colors[$status] ?? '#777';
        return sprintf(
            '<span style="background:%s;color:#fff;padding:4px 8px;border-radius:3px;font-size:11px;">%s</span>',
            esc_attr($color),
            esc_html(ucfirst($status))
        );
    }
}