<?php
namespace SFS\HR\Modules\Settlement\Admin\Views;

use SFS\HR\Core\Helpers;
use SFS\HR\Modules\Settlement\Services\Settlement_Service;

if (!defined('ABSPATH')) { exit; }

/**
 * Settlement Form View
 * Renders the create settlement form
 */
class Settlement_Form {

    /**
     * Render the create form
     */
    public static function render(): void {
        $pending_resignations = Settlement_Service::get_pending_resignations();

        ?>
        <div class="wrap sfs-hr-wrap">
            <h1><?php esc_html_e('Create Settlement', 'sfs-hr'); ?></h1>
            <?php Helpers::render_admin_nav(); ?>

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

            <?php self::render_script(); ?>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render calculation JavaScript
     */
    private static function render_script(): void {
        ?>
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

            // Gratuity calculation per Saudi Labor Law:
            // 21 days salary for each year of service (for first 5 years)
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
        <?php
    }
}
