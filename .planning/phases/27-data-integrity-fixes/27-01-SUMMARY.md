---
phase: 27-data-integrity-fixes
plan: 01
subsystem: Settlement
tags: [settlement, eos, gratuity, saudi-labor-law, data-integrity]
dependency_graph:
  requires: []
  provides: [correct-eos-gratuity, trigger-type-support]
  affects: [Settlement_Service, Settlement_Form, Settlement_Handlers, Migrations]
tech_stack:
  added: []
  patterns: [server-side-recalculation, allowlist-validation, idempotent-migration]
key_files:
  created: []
  modified:
    - includes/Modules/Settlement/Services/class-settlement-service.php
    - includes/Modules/Settlement/Admin/Views/class-settlement-form.php
    - includes/Modules/Settlement/Handlers/class-settlement-handlers.php
    - includes/Install/Migrations.php
decisions:
  - Saudi Article 84 rates (15-day/30-day) replace UAE 21-day rate in both PHP and JS
  - trigger_type defaults to 'resignation' in DB column to reflect the most common settlement source
  - Server-side gratuity recalculation in handle_create() overrides any client-submitted value
  - calculate_gratuity() retained for backward compatibility; new calculate_gratuity_with_trigger() wraps it
metrics:
  duration: ~15 minutes
  completed: "2026-03-18T02:44:00Z"
  tasks_completed: 2
  tasks_total: 2
  files_modified: 4
  commits: 2
requirements: [DATA-01, DATA-06]
---

# Phase 27 Plan 01: Settlement EOS Formula and Trigger Type Summary

Settlement EOS gratuity corrected from UAE 21-day rate to Saudi Article 84 (15-day/30-day) with resignation/termination/contract_end trigger-type multipliers applied both client-side (JS) and server-side (PHP).

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Fix EOS formula and add trigger_type to Settlement_Service + migration | d92d1c8 | class-settlement-service.php, Migrations.php |
| 2 | Update settlement form JS formula and add trigger_type field + server-side validation | 5c76a1e | class-settlement-form.php, class-settlement-handlers.php |

## What Was Built

### Task 1 — Settlement_Service + Migrations

- **`calculate_gratuity()`**: Changed first-5-years factor from `21` (UAE) to `15` (Saudi Article 84 half-month). Updated docblock to reference Article 84. The second tier (30-day per year after 5 years) was already correct.
- **`calculate_gratuity_with_trigger()`**: New method wrapping `calculate_gratuity()` with resignation-payout reductions per Saudi law:
  - < 2 years of service → 0
  - 2–5 years → 1/3 of full gratuity
  - 5–10 years → 2/3 of full gratuity
  - 10+ years → full gratuity
  - termination / contract_end → always full gratuity
- **`get_trigger_types()`**: Returns the three valid trigger labels (localized).
- **`create_settlement()`**: Stores `trigger_type` from `$data['trigger_type'] ?? 'resignation'`.
- **Migrations**: `add_column_if_missing($settle, 'trigger_type', "VARCHAR(20) NOT NULL DEFAULT 'resignation'")` added after existing settlement column additions.

### Task 2 — Form + Handler

- **Settlement form**: Added `<select name="trigger_type">` dropdown with resignation/termination/contract_end options, placed before the gratuity row, calls `calculateGratuity()` on change.
- **JS `calculateGratuity()`**: Changed `* 21 *` to `* 15 *` in both the `<= 5 years` branch and the `first5Years` calculation. Added trigger-type block that applies the same reduction logic as the PHP method.
- **`handle_create()`**: Reads `trigger_type` from POST, validates against an allowlist (`['resignation','termination','contract_end']`), then calls `Settlement_Service::calculate_gratuity_with_trigger()` to overwrite the client-submitted gratuity value before calling `create_settlement()`.

## Deviations from Plan

None — plan executed exactly as written.

## Self-Check

- `includes/Modules/Settlement/Services/class-settlement-service.php` — modified (trigger_type methods, 15-day rate)
- `includes/Modules/Settlement/Admin/Views/class-settlement-form.php` — modified (dropdown, JS fix)
- `includes/Modules/Settlement/Handlers/class-settlement-handlers.php` — modified (trigger_type validation, server-side recalc)
- `includes/Install/Migrations.php` — modified (trigger_type column)
- Commits d92d1c8 and 5c76a1e exist in git log

## Self-Check: PASSED
