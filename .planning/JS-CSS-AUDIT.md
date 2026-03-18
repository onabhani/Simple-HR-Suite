# JavaScript/CSS Audit Report

**Audited:** 2026-03-18
**Scope:** ~16,400 lines of inline JS/CSS + ~6,600 lines of standalone assets
**Files:** 11 files across Frontend, Admin, and PWA layers

## Summary

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High | 7 |
| Medium | 28 |
| Low | 41 |
| **Total** | **77** |

## Critical Findings

### C-01: innerHTML XSS in Widget_Shortcode punch history
- **File:** Widget_Shortcode.php:1161-1181
- **Issue:** `updatePunchHistory()` builds HTML from REST response data (`p.type`, `p.time`) and injects via `punchListEl.innerHTML`. Neither value is escaped. `punchTypeLabel()` falls back to raw `type` value. Stored/reflected XSS if REST data is compromised.
- **Fix:** Use `textContent` for text values, or create elements via DOM APIs. Add an `escapeHtml()` helper.

## High Findings

### H-01: innerHTML XSS in Kiosk employee name display
- **File:** Kiosk_Shortcode.php:2128-2165
- **Issue:** Employee name from REST response uses `.replace(/</g,'&lt;')` — incomplete escaping (no quotes, `>`, `&`). `getInitials()` returns raw unescaped characters inserted via `innerHTML`.
- **Fix:** Use `textContent` / `createElement` for all user-controlled data, or create a proper `escapeHtml()` function.

### H-02: Dead `parseEmployeeQR` uses innerHTML with QR payload
- **File:** Kiosk_Shortcode.php:2288-2295
- **Issue:** Arbitrary QR code data assigned to `textarea.innerHTML` for entity decoding. Function appears unused (dead code).
- **Fix:** Remove entirely if dead code. If needed, use `DOMParser` instead.

### H-03: innerHTML XSS in LeaveModule approve/reject forms
- **File:** LeaveModule.php:664-704
- **Issue:** Approve/reject forms and history rendering built via string concatenation + `.innerHTML`. Custom `sfsEsc()` does not escape single quotes.
- **Fix:** Use DOM construction. At minimum add single-quote escaping to `sfsEsc`.

### H-04: Global PII + CSRF nonce exposure in LeaveModule
- **File:** LeaveModule.php:583-738
- **Issue:** `sfsHrLeaveData` is a global variable containing employee names, leave reasons, dates, and valid CSRF nonces for all visible requests. Any script on the page can read it.
- **Fix:** Wrap in IIFE. Only emit nonces for requests the current user can approve.

### H-05: Service worker caches authenticated HTML pages
- **File:** service-worker.js:54-71
- **Issue:** Fetch handler caches ALL GET responses with status 200, including pages with PII and session tokens. Shared kiosk could serve cached authenticated pages to different users.
- **Fix:** Restrict caching to static assets only (CSS, JS, images, fonts). Never cache HTML with auth context.

### H-06: Incomplete HTML escaper in early leave review modal
- **File:** class-admin-pages.php:6726-6748
- **Issue:** `sfsElEsc()` used with jQuery `.html()` — only 4 entity replacements, no single-quote escaping.
- **Fix:** Use `.text()` for text values, DOM APIs for structured content.

### H-07: CSS `transition: all` on all PWA links/buttons
- **File:** pwa-styles.css:1088-1094
- **Issue:** `transition: all 0.15s ease` on all `a` and `button` inside `.sfs-hr-pwa-app`. Forces browser to check all animatable properties on every reflow.
- **Fix:** Replace with specific properties: `transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;`

## Medium Findings

### Security

| ID | File | Line(s) | Issue |
|----|------|---------|-------|
| M-01 | Widget_Shortcode.php | 198, 1000, 1049+ | `$inst` used in JS without `esc_js()` — inconsistent with other locations |
| M-02 | pwa-app.js | 34-39 | `message` event listener doesn't validate `event.origin` |
| M-03 | pwa-app.js | 234-243 | HMAC key handling — confirm key is not persisted client-side |
| M-04 | Shortcodes.php | 1211 | REST nonce in globally-scoped IIFE readable by any XSS |
| M-05 | class-admin-pages.php | 6823-6827 | REST URL built with unvalidated `id` — add `parseInt()` |
| M-06 | Admin.php | 2032-2035 | Chart data uses `echo $var` in script — use `wp_json_encode()` inline |

### Performance

| ID | File | Line(s) | Issue |
|----|------|---------|-------|
| M-07 | Widget_Shortcode.php | 1009-1022 | New `AudioContext` per punch — reuse single instance |
| M-08 | Widget_Shortcode.php | 1871 | `geolocation.watchPosition` never cleared — battery drain |
| M-09 | Kiosk_Shortcode.php | 1279, 2327+ | Multiple `setInterval` calls never cleared — memory leak |
| M-10 | Kiosk_Shortcode.php | 1774-1777 | jsQR lazy-load uses tight polling loop (100ms × 30) |
| M-11 | Shortcodes.php | 2353 | `translateFormElements` runs 3 expensive querySelectorAll passes |
| M-12 | class-admin-pages.php | 2272+, 4724+ | Duplicate Leaflet map init code (~90 lines × 2) |
| M-13 | class-admin-pages.php | 3949-3970 | Bulk assignment: O(days × employees) individual queries, no transaction |
| M-14 | service-worker.js | 16-22 | Precache only has 1 asset; `.catch()` silently swallows failures |
| M-15 | pwa-styles.css | 1354-1363 | Attribute substring selectors `div[style*="background:#fff"]` — slowest selector type |

### Logic

| ID | File | Line(s) | Issue |
|----|------|---------|-------|
| M-16 | Kiosk_Shortcode.php | 966-973 | JS references non-existent DOM IDs (`sfs-kiosk-status-`, `sfs-kiosk-capture-`, `sfs-kiosk-emp-`) |
| M-17 | Kiosk_Shortcode.php | 2221-2250 | Selfie preview references wrong canvas ID (`sfs-kiosk-canvas-` vs `sfs-kiosk-selfie-`) |
| M-18 | Widget_Shortcode.php | 1758-1759 | Redundant `stopSelfiePreview()` — already called in `doPunch()` |
| M-19 | LeaveModule.php | 664-677 | No double-submit prevention on approve/reject forms |
| M-20 | Shortcodes.php | 1121 | No double-submit prevention on asset photo modal |
| M-21 | service-worker.js | 82-137 | Race condition: concurrent sync events can double-submit punches |
| M-22 | service-worker.js | 126-131 | 401/403 punches kept in queue indefinitely — no expiry |
| M-23 | class-admin-pages.php | 6815-6848 | Early leave AJAX error resets button to "Submit" instead of original label |

### Quality

| ID | File | Line(s) | Issue |
|----|------|---------|-------|
| M-24 | Widget_Shortcode.php | 1639-1644 | Hardcoded English validation messages bypass i18n system |
| M-25 | Shortcodes.php | 1985-1987 | iOS detection misses iPadOS 13+ (reports as Mac) |
| M-26 | LeaveModule.php | 631-738 | All functions + data in global scope — pollutes `window` |
| M-27 | pwa-styles.css | entire | ~2,000 lines of duplicated dark mode CSS (system + manual) |
| M-28 | pwa-styles.css | 32-37+ | ~100+ `!important` declarations fighting theme styles |

### Accessibility

| ID | File | Line(s) | Issue |
|----|------|---------|-------|
| M-29 | pwa-styles.css | 2067-2073 | `outline: none` on focus — invisible in High Contrast Mode |
| M-30 | pwa-styles.css | 527 | Tab nav `font-size: 10px` — below 12px minimum for touch |

## Low Findings (41 total)

### Quick Wins

| ID | File | Line(s) | Issue | Fix |
|----|------|---------|-------|-----|
| L-01 | Shortcodes.php | 1517 | CSS typo: `border:1px solid:#fde68a;` (extra colon) | Remove the colon after `solid` |
| L-02 | Shortcodes.php | 2081 | Dead variable `lastScrollY` | Remove |
| L-03 | Shortcodes.php | 2139 | Dead variable `translationsLoaded` | Remove |
| L-04 | Kiosk_Shortcode.php | 1096 | Dead `DBG` constant (never referenced) | Remove |
| L-05 | Kiosk_Shortcode.php | 982 | Dead `qrStop` references (element removed) | Remove all references |
| L-06 | pwa-styles.css | 1096-1100 | Dead pull-to-refresh indicator CSS | Remove |
| L-07 | pwa-styles.css | 417-421 | Redundant nested media query (782px inside 768px) | Remove inner `@media` |
| L-08 | class-admin-pages.php | 3911 | Dead ternary fallback on `wp_json_encode` output | Simplify |

### Other Low Findings

| ID | File | Issue |
|----|------|-------|
| L-09 | Widget_Shortcode.php:1076 | `setInterval(tickClock)` never cleared |
| L-10 | Widget_Shortcode.php:82-83 | Leaflet infinite retry with no max attempts |
| L-11 | Widget_Shortcode.php:1860 | Hardcoded Riyadh coordinates as map default |
| L-12 | Widget_Shortcode.php:1805-1815 | `preloadGeo` triggers visible geofence error flash |
| L-13 | Widget_Shortcode.php:1002-1008 | `flash()` className from punch type not validated against allowlist |
| L-14 | Widget_Shortcode.php:1889 | Window `resize` handler without debounce |
| L-15 | Widget_Shortcode.php:1873-1909 | Duplicate user marker creation logic |
| L-16 | Widget_Shortcode.php | Multiple catch blocks silently swallow errors |
| L-17 | Kiosk_Shortcode.php:788 | REST nonce exposed as `window.SFS_ATT_NONCE` global |
| L-18 | Kiosk_Shortcode.php:978-980 | Duplicate variable assignments (`qrWrap`/`camwrap`, `qrVid`/`video`) |
| L-19 | Kiosk_Shortcode.php:1169-1178 | `labelFor()` shadows outer `t` variable |
| L-20 | Kiosk_Shortcode.php:781 | jsQR CDN with no fallback or error notification |
| L-21 | Kiosk_Shortcode.php:1252-1274 | Button list queried every 60s instead of cached |
| L-22 | Kiosk_Shortcode.php:2143-2157 | Log list lacks `aria-live` for screen readers |
| L-23 | Kiosk_Shortcode.php:1972 | Arrow param `t` shadows i18n `t` |
| L-24 | Kiosk_Shortcode.php:2418-2500 | Dead `punch()` function (~80 lines) |
| L-25 | Kiosk_Shortcode.php:2490-2491 | `data-type` vs `data-action` mismatch (in dead code) |
| L-26 | LeaveModule.php:582 | `sfsEsc` global name — could collide |
| L-27 | LeaveModule.php:711 | `body.style.overflow = 'hidden'` not restored on navigation |
| L-28 | LeaveModule.php:583-629 | History data eagerly loaded for all requests |
| L-29 | LeaveModule.php:688-704 | `for...in` on `h.meta` without `hasOwnProperty` guard |
| L-30 | LeaveModule.php:3288+, 7582+ | Duplicate confirm functions across pages |
| L-31 | Shortcodes.php:997, 1026-1028 | Empty catch blocks swallow camera errors |
| L-32 | Shortcodes.php:1944-2472 | Global event listeners never cleaned up |
| L-33 | Shortcodes.php:2446 | `translateFormElements` references `lang` inconsistently via closure |
| L-34 | pwa-app.js:94-125 | IndexedDB opened fresh per operation — should cache connection |
| L-35 | pwa-app.js:210-220 | `isRosterFresh()` reads `employees[0]` instead of explicit meta record |
| L-36 | pwa-app.js:259 | REST URL hardcoded — fails if WP in subdirectory |
| L-37 | pwa-app.js:69 | `window.sfsHrPwa.db` assignment could clobber existing |
| L-38 | service-worker.js:132-136 | `break` on network error stops all remaining punch syncs |
| L-39 | service-worker.js:189-201 | `event.data.json()` without try/catch |
| L-40 | admin-styles.css:300-322 | `outline: none` on focus in admin forms |
| L-41 | admin-styles.css:456-470, 971-987 | Duplicate `@media (max-width: 782px)` blocks |
| L-42 | admin-styles.css:485-490 | Toggle checkbox hidden with `opacity:0; width:0; height:0` — poor screen reader compat |
| L-43 | admin-styles.css:928-942 | WebKit scrollbar styles without Firefox equivalents |
| L-44 | pwa-styles.css:3226-3255 | Duplicate `.sfs-badge` definitions with different `display` |
| L-45 | pwa-styles.css:317-380 | `:has()` selector unsupported in Firefox < 121 |
| L-46 | Admin.php:440-442 | Blanket `display:none` on all non-plugin WP notices |
| L-47 | Admin.php:178+ | 14 scattered inline `<style>` blocks (not cacheable) |
| L-48 | Admin.php:6690+ | jQuery dependency without explicit enqueue |
| L-49 | Admin.php:2618 | Global functions (`sfsHrToggleKebab`, etc.) |
| L-50 | class-admin-pages.php:640+ | 8 scattered inline `<style>` blocks |
| L-51 | class-admin-pages.php:2281 | Leaflet polling with no max retry |
| L-52 | class-admin-pages.php:5877 | GET URL built with string concatenation for data-modifying action |

## Recurring Antipatterns

| Pattern | Occurrences | Files |
|---------|-------------|-------|
| `innerHTML` / jQuery `.html()` with REST/AJAX data | 6 | Widget, Kiosk, Leave, Admin Pages |
| Hand-rolled incomplete HTML escapers | 3 | Widget (`none`), Kiosk (`<` only), Leave/Admin (`sfsEsc`/`sfsElEsc`) |
| Global function/variable pollution | 5 | Leave, Shortcodes, Admin, Kiosk |
| `setInterval` / `watchPosition` never cleared | 4 | Widget, Kiosk |
| Empty catch blocks (error swallowing) | 6 | Widget, Shortcodes, Kiosk |
| No double-submit prevention | 3 | Leave, Shortcodes, Admin Pages |
| Inline `<style>` blocks (not cacheable) | 22 | Admin.php (14), Admin Pages (8) |

---
*Audit completed: 2026-03-18*
