---
title: "Add shortcodes reference page in admin settings"
labels: enhancement, ux, P2-medium
---

## Problem

There is no central place in the admin panel where administrators can see all available shortcodes, their parameters, and usage examples.

## Proposed Solution

- Create a "Shortcodes" tab or section within the main settings page
- List every available shortcode with its name, parameters, and description
- Add copy-to-clipboard button for each shortcode
- Add live preview or screenshot of each shortcode's output
- Keep the list auto-updated — pull from registered shortcodes dynamically

## Acceptance Criteria

- [ ] Shortcodes tab/section visible in main settings
- [ ] All shortcodes listed with name, parameters, description
- [ ] Copy-to-clipboard works for each shortcode
- [ ] List updates automatically when new shortcodes are registered

## Priority

P2 — Medium

## References

- ENHANCEMENTS.md Section 7.1
- Shortcodes: `includes/Frontend/Shortcodes.php`
