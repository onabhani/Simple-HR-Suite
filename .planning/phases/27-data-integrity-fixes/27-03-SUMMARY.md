---
phase: 27-data-integrity-fixes
plan: "03"
subsystem: Loans / Payroll
tags: [bug-fix, loan-deductions, payroll, data-integrity]
dependency_graph:
  requires: []
  provides: [correct-loan-deductions-in-payroll, consistent-installment-amount-calculation]
  affects: [PayrollModule, Loans-Frontend]
tech_stack:
  added: []
  patterns: [wpdb-prepare, column-alias-correction]
key_files:
  created: []
  modified:
    - includes/Modules/Payroll/PayrollModule.php
    - includes/Modules/Loans/Frontend/class-my-profile-loans.php
decisions:
  - "installment_amount is the canonical column in sfs_hr_loans; no monthly_installment column exists"
  - "Frontend uses user-entered monthly_amount only to compute installments_count via floor/ceil; actual stored installment_amount is always round(principal/installments, 2)"
metrics:
  duration: "61s"
  completed_date: "2026-03-18"
  tasks_completed: 2
  files_modified: 2
requirements: [DATA-03, DATA-05]
---

# Phase 27 Plan 03: Payroll Loan Column and Frontend Installment Calculation Summary

Corrected PayrollModule SQL to read the actual `installment_amount` column (not the non-existent `monthly_installment`), and aligned the frontend loan submission formula with the admin approval path so both store `round(principal / installments_count, 2)`.

## Tasks Completed

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | Fix Payroll loan column name from monthly_installment to installment_amount | db739ad | PayrollModule.php |
| 2 | Align frontend installment calculation with admin path | 38fefb2 | class-my-profile-loans.php |

## What Was Done

### Task 1 — Payroll loan column fix

PayrollModule.php line 570 was SELECTing `l.monthly_installment` — a column that does not exist in `sfs_hr_loans`. The actual column is `installment_amount`. Because the column was missing, `$loan->monthly_installment` was always `null`, and `min(null, balance)` evaluated to zero, causing every payroll run to apply zero loan deductions.

Two lines fixed:
- Line 570: `l.monthly_installment` → `l.installment_amount` in the SQL SELECT
- Line 580: `$loan->monthly_installment` → `$loan->installment_amount` in the PHP property access

No other references to `monthly_installment` existed anywhere in the Payroll module.

### Task 2 — Frontend installment calculation alignment

The admin approval path in `class-admin-pages.php` always computes:
```php
$installment_amount = round( $principal / $installments, 2 );
```

The frontend `validate_and_insert_loan()` method was using the user-entered monthly amount directly:
```php
$installment_amt = round( $monthly_amount, 2 );  // old — user-entered value
```

This meant that for a 10,000 SAR loan with a 700 SAR monthly entry, the frontend stored `installment_amount=700.00` with `installments_count=15` (ceil(10000/700)), while the admin path would compute `round(10000/15, 2) = 666.67`. Payroll would see different deduction amounts depending on which submission path created the loan.

The fix: frontend now computes the same formula — `round($principal / $installments, 2)`. The user-entered `$monthly_amount` is still used (unchanged) to derive `$installments` via the existing floor/ceil logic above.

## Deviations from Plan

None — plan executed exactly as written.

## Verification Results

1. `grep -rn "monthly_installment" includes/Modules/Payroll/` — 0 matches (PASSED)
2. `grep -n "l.installment_amount" includes/Modules/Payroll/PayrollModule.php` — line 570 confirmed (PASSED)
3. `grep -n "round.*monthly_amount" includes/Modules/Loans/Frontend/class-my-profile-loans.php` — 0 matches (PASSED)
4. `grep -n "round.*principal.*installments" includes/Modules/Loans/Frontend/class-my-profile-loans.php` — line 706 confirmed (PASSED)

## Self-Check: PASSED

- `includes/Modules/Payroll/PayrollModule.php` — FOUND, modified
- `includes/Modules/Loans/Frontend/class-my-profile-loans.php` — FOUND, modified
- Commit `db739ad` — FOUND
- Commit `38fefb2` — FOUND
