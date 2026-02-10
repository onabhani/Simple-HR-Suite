---
title: "Create full plugin documentation"
labels: documentation, P3-low
---

## Problem

The plugin lacks comprehensive documentation. The current README.md only covers v0.1.5 basics. Users, admins, and developers need thorough guides.

## Proposed Solution

Create a `docs/` directory with structured documentation:

- [ ] Installation & Setup guide
- [ ] Configuration guide (General Settings, each module's settings)
- [ ] User guide for admins (managing employees, leave, attendance, payroll, etc.)
- [ ] User guide for employees (frontend shortcode usage)
- [ ] Developer guide (architecture, hooks/filters, extending modules, REST API)
- [ ] Database schema documentation (all tables and relationships)
- [ ] Capabilities and roles reference
- [ ] Cron jobs and scheduled tasks reference
- [ ] FAQ / Troubleshooting section

## Acceptance Criteria

- [ ] `docs/` directory exists with organized markdown files
- [ ] All 17 modules are documented
- [ ] All shortcodes documented with examples
- [ ] Developer guide covers module extension pattern
- [ ] Database ERD or table relationship documentation included

## Priority

P3 — Low

## References

- ENHANCEMENTS.md Section 6.1
- Current README: `README.md`
