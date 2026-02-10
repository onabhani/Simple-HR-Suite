---
title: "General bug fixes and performance audit"
labels: bug, performance, P0-critical
---

## Problem

The plugin needs a thorough review for bugs, security issues, and performance bottlenecks across all 17 modules.

## Tasks

### Bug Audit
- [ ] Full code review across all modules for common bugs
- [ ] Check PHP error logs and WordPress debug output for warnings/notices
- [ ] Fix any SQL injection, XSS, or CSRF vulnerabilities
- [ ] Test all AJAX endpoints for proper nonce verification and capability checks

### Performance Audit
- [ ] Profile database queries — identify slow queries (especially Attendance and Leave modules)
- [ ] Add missing database indexes (reference `sql/performance-indexes.sql`)
- [ ] Implement query caching for frequently accessed data
- [ ] Audit admin page load times and optimize asset loading
- [ ] Review cron job efficiency (Reminders, Workforce Status modules)

## Priority

P0 — Critical

## References

- ENHANCEMENTS.md Sections 2.1, 2.2
- Existing SQL optimizations: `sql/performance-indexes.sql`, `sql/performance-indexes-safe.sql`
