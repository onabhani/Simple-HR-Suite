# PWA Module Audit Findings

**Audited:** 2026-03-17
**Files:** 5 files reviewed
- `includes/Modules/PWA/PWAModule.php` (414 lines — primary module)
- `assets/pwa/service-worker.js` (219 lines)
- `assets/pwa/pwa-app.js` (243 lines)
- `assets/pwa/pwa-app.min.js` (4.7 KB — minified; matches pwa-app.js)
- `assets/pwa/manifest.json` (static fallback; not served at runtime)
**Module Status:** Stub / incomplete (per CLAUDE.md) — push notification subscription management absent; offline employee roster feature is implemented in JS but depends on external Attendance REST endpoint

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 2 |
| High     | 4 |
| Medium   | 3 |
| Low      | 4 |

---

## Security Findings

### SEC-001 [High]: Service worker registered with site-wide scope `/`

- **File:** `assets/pwa/pwa-app.js:14` / `PWAModule.php:145-146`
- **Detail:** Service worker is registered with `{ scope: '/' }` and the SW itself sets `Service-Worker-Allowed: /` (PWAModule.php line 219). This means the service worker intercepts ALL requests on the entire WordPress site origin — not scoped to the HR portal. A bug in the fetch handler could interfere with WP admin or other plugins. The SW's fetch handler does exclude `/wp-admin/` and `admin-ajax.php` by path check (service-worker.js lines 49-52), but path-based exclusion is fragile and incomplete (e.g., multisite sub-paths, REST API `/wp-json/` is also excluded but only by pathname contains check).
- **Impact:** Unintended caching or interception of non-HR pages. SW bugs are extremely hard to debug and persist across reloads once installed.
- **Fix:** Scope the service worker to the HR portal path, e.g. `{ scope: '/employee-portal/' }` or the actual page path containing HR shortcodes. Remove `Service-Worker-Allowed: /` from the PHP response header unless broad scope is explicitly required. If broad scope is needed, add it to a comment in the code explaining why.

---

### SEC-002 [Critical]: Manifest and service worker endpoints accessible without authentication

- **File:** `PWAModule.php:33-35`
- **Detail:** Both AJAX actions (`sfs_hr_pwa_manifest` and `sfs_hr_pwa_sw`) are registered with both `wp_ajax_` (authenticated) AND `wp_ajax_nopriv_` (unauthenticated) hooks. The manifest endpoint reveals:
  - `admin_url('admin.php?page=sfs-hr-my-profile')` — internal admin path (manifest shortcut, line 205)
  - `home_url('/?pwa=1&action=punch')` and `home_url('/?pwa=1')` — parameter-based routing hints
  - Site name via `get_bloginfo('name')`
- **Impact:** Anonymous users can retrieve the HR admin portal URL. While admin paths are not strictly secret, exposing `admin.php?page=sfs-hr-my-profile` to unauthenticated requests aids reconnaissance. The service worker JS itself is also served anonymously.
- **Fix:** Remove `wp_ajax_nopriv_sfs_hr_pwa_manifest` — manifest should only be served to logged-in users who have already been exposed to it via the `<link rel="manifest">` tag (which itself is gated behind `is_user_logged_in()`). Similarly, anonymous service worker serving is unnecessary since only authenticated users trigger registration. Remove the admin URL shortcut from the manifest or serve separate manifest variants.

---

### SEC-003 [Critical]: Stored nonce in IndexedDB and offline punch replay using stale nonce

- **File:** `assets/pwa/service-worker.js:107` / `pwa-app.js:35`
- **Detail:** When background sync fires (`sfs-hr-punch-sync`), the SW first attempts to get a fresh nonce via `postMessage` to an open client (service-worker.js lines 91-101). If no client window is available, it falls back to `punch.nonce` — the nonce that was stored at punch-queue time (service-worker.js line 107: `const useNonce = freshNonce || punch.nonce`). WP REST nonces have a 12–24 hour TTL, but offline periods exceeding this window will result in stale nonces being replayed. A 401/403 response is handled by keeping the punch in the queue (service-worker.js lines 131-132) and retrying — but there is no maximum retry count and no expiry TTL on queued punches. A stale punch could sit in IndexedDB indefinitely.
- **Impact:** Punch records from hours-old offline sessions may never sync. More critically, if the nonce has rotated, the stored punch is orphaned with no user-visible error. There is no cleanup mechanism.
- **Fix:** Store a `queued_at` timestamp with each punch (already done: `pwa-app.js:99` stores `timestamp`). On sync failure with 401/403, check if `timestamp` is older than 24 hours and discard the punch with a user notification rather than retrying indefinitely. Add a max-retry-count or TTL-based expiry to the IndexedDB queue.

---

### SEC-004 [High]: Employee roster cached in IndexedDB without encryption — includes token_hash values

- **File:** `assets/pwa/pwa-app.js:83-87`, `service-worker.js:161-163`
- **Detail:** The offline kiosk roster stores employee records in IndexedDB including `token_hash` (SHA-256 of QR token), employee `id`, `name`, and `code`. IndexedDB is accessible to any JavaScript on the same origin. Since the service worker is scoped to `/` (see SEC-001), and the SW itself can read IndexedDB (service-worker.js lines 150-186), any injected script on any page of the site could access the employee roster.
- **Impact:** Employee codes and names (plus token hashes) stored on kiosk device local storage. On a shared kiosk device, if another user opens a browser on the same device, they can access the roster via DevTools console. Partial information disclosure of employee data.
- **Fix:** Scope the service worker narrowly (fixing SEC-001 also reduces this exposure). Add a TTL check before serving roster data (already partially implemented via `isRosterFresh()`). Document the security assumption that kiosk devices are trusted/controlled — if not true, the offline roster feature should be disabled or the data minimized (store only `token_hash`, not names/codes).

---

### SEC-005 [High]: `start_url` and shortcut URLs use query parameters without integrity protection

- **File:** `PWAModule.php:177, 200-201`
- **Detail:** The generated manifest sets `start_url: home_url('/?pwa=1')` and shortcuts include `/?pwa=1&action=punch`. The `pwa` and `action` query parameters are served to the frontend but there is no handling of these parameters visible in PWAModule.php. If these parameters reach template or PHP code, they must be sanitized — but the PWA module itself does not implement any handler for them.
- **Impact:** If another module or theme accidentally echoes `$_GET['action']` without escaping, the manifest's shortcut could be weaponized (e.g., construct a PWA shortcut URL that triggers an unintended handler). Low probability but architecture is unclear.
- **Fix:** Audit where `?pwa=1` and `?pwa=1&action=punch` are consumed. If no PHP handler exists, document that these parameters are client-side-only. Otherwise, ensure all handlers sanitize `$_GET['action']` with an allowlist before processing.

---

### SEC-006 [Medium]: `showNotification()` called without requiring notification permission grant

- **File:** `assets/pwa/service-worker.js:140-146`
- **Detail:** After background sync of offline punches, the SW calls `self.registration.showNotification(...)` directly without checking if notification permission was previously granted by the user. Modern browsers enforce permission at the OS level, so this will silently fail if permission was denied, but it may trigger unexpected behavior on some platforms.
- **Impact:** Minor UX issue; no security risk beyond unexpected notification display.
- **Fix:** Wrap the `showNotification` call in a permission check: `if (Notification.permission === 'granted')`. Alternatively, the SW can send a `postMessage` to the client window to handle notification display in JS context.

---

### SEC-007 [Medium]: Inline `console.log('[PWA] installed')` on install outcome

- **File:** `PWAModule.php:293`
- **Detail:** The admin install prompt JavaScript logs `console.log('PWA installed')` when a user accepts the install prompt. This is a trivial information disclosure (confirms user installed the app to any open browser console), but is inconsistent with production code practices.
- **Impact:** Low. Trivial.
- **Fix:** Remove or gate behind a debug flag.

---

## Performance Findings

### PERF-001 [Low]: Manifest endpoint sets `Cache-Control: public, max-age=86400` (24h) — stale after config change

- **File:** `PWAModule.php:171`
- **Detail:** The manifest is generated dynamically (includes `get_bloginfo('name')`, admin URLs, home URLs) but cached with `Cache-Control: public, max-age=86400`. If the site URL, blog name, or admin path changes, browsers will serve a stale manifest for up to 24 hours. Additionally, `public` cache-control allows CDN/proxy caching of a manifest that contains admin paths.
- **Impact:** Config changes take 24 hours to propagate to PWA-installed users. CDNs may cache authenticated admin URLs.
- **Fix:** Change to `Cache-Control: no-cache` or use `private, max-age=3600`. The manifest is dynamic and should not be publicly cached by proxies. Alternatively, add a version hash query param to the manifest link and bump it on config change.

---

### PERF-002 [Low]: `Service-Worker-Allowed: /` header set on every AJAX response — unnecessary for cached clients

- **File:** `PWAModule.php:219`
- **Detail:** The `Service-Worker-Allowed` header must only be present on the initial SW registration response. Once cached, the SW re-registers from the AJAX URL and the header is irrelevant on subsequent calls. However, since the SW itself has `Cache-Control: no-cache, no-store, must-revalidate` (PWAModule.php line 220), it is re-fetched on every page load, adding a small overhead.
- **Impact:** Marginal extra bandwidth per page load. Not a significant issue.
- **Fix:** This is acceptable for a stub module. Document that the no-cache strategy is intentional for SW update delivery.

---

### PERF-003 [Medium]: Service worker caches ALL non-admin GET responses (network-first, cache fallback)

- **File:** `assets/pwa/service-worker.js:54-71`
- **Detail:** The fetch handler intercepts all non-admin, non-AJAX GET requests and caches them after a successful network response. This includes leave request pages, employee profile pages, attendance history pages, and any page containing HR data. If a user visits the leave balance page while online, that cached response will be served offline — showing potentially stale leave balance data.
- **Impact:** Employees may see outdated attendance records, leave balances, or payroll data from the cache when offline. The stale data could cause confusion or incorrect actions (e.g., believing leave was approved when it was not).
- **Fix:** Exclude HR-specific frontend paths from the cache strategy, or use a "stale-while-revalidate" approach with a short max-age for dynamic pages. At minimum, add URL pattern exclusions for `?sfs_hr_tab=`, `?pwa=1`, and any known HR page slugs.

---

## Duplication Findings

### DUP-001 [Low]: Install prompt banner HTML/JS duplicated across two methods

- **File:** `PWAModule.php:246-305` (admin prompt) and `PWAModule.php:340-412` (frontend prompt)
- **Detail:** `output_pwa_install_prompt()` and `output_frontend_pwa_prompt()` are nearly identical: both render an install banner, both listen to `beforeinstallprompt`, both check `localStorage.getItem('sfs_hr_pwa_dismissed')`, both handle dismiss. The only differences are CSS styling and element IDs.
- **Impact:** Any bug fix or UI change to the install prompt must be applied in two places.
- **Fix:** Extract a `render_pwa_install_banner(string $context)` method that accepts a context parameter (`admin` or `frontend`) to vary the styles, and call it from both methods. This eliminates ~60 lines of duplication.

---

### DUP-002 [Low]: `page_has_hr_shortcode()` logic duplicated across `register_pwa_scripts()` and `output_frontend_pwa_prompt()`

- **File:** `PWAModule.php:82-92` (private method) and `PWAModule.php:329-333` (inline in `output_frontend_pwa_prompt`)
- **Detail:** `output_frontend_pwa_prompt()` inlines the same three `has_shortcode()` checks that the existing private helper `page_has_hr_shortcode()` already encapsulates — but also adds `isset($_GET['sfs_hr_tab']) && $_GET['sfs_hr_tab'] === 'attendance'`. The helper is not called; the checks are repeated inline.
- **Fix:** Call `$this->page_has_hr_shortcode($post)` in `output_frontend_pwa_prompt()` and extend the helper to include the `$_GET['sfs_hr_tab']` check. Note: `$_GET['sfs_hr_tab']` should be sanitized: `sanitize_key($_GET['sfs_hr_tab'] ?? '') === 'attendance'`.

---

## Logical Findings

### LOGIC-001 [High]: `$_GET['sfs_hr_tab']` used without sanitization in `output_frontend_pwa_prompt()`

- **File:** `PWAModule.php:333`
- **Detail:** The condition `isset($_GET['sfs_hr_tab']) && $_GET['sfs_hr_tab'] === 'attendance'` accesses `$_GET` directly without sanitization. While the value is used only for a boolean equality check (not echoed or used in queries), this is inconsistent with the project's defensive coding conventions and could become a risk if the condition is later modified.
- **Impact:** Low direct risk; Medium pattern risk.
- **Fix:** `sanitize_key( $_GET['sfs_hr_tab'] ?? '' ) === 'attendance'`

---

### LOGIC-002 [High]: Service worker scope is `'/'` but SW is served via `admin-ajax.php` — scope enforcement may fail

- **File:** `PWAModule.php:144-145`, `pwa-app.js:13-14`
- **Detail:** The service worker is served via `admin-ajax.php?action=sfs_hr_pwa_sw`. Service workers can only control pages within their scope, and the scope is relative to the SW script's path. Since the SW URL is `admin-ajax.php` (under `/wp-admin/` or the WP root), the `Service-Worker-Allowed: /` override header is used to permit a broader scope. However, browsers may be stricter about SW scope when the script is served from `admin-ajax.php` depending on the WP installation path. If WordPress is installed in a subdirectory (e.g., `/wp/`), the scope `/` may not cover the HR portal pages.
- **Impact:** Service worker silently fails to register or control HR pages on non-root WP installations. Offline functionality breaks without any error to the user.
- **Fix:** Serve the service worker as a static file (e.g., copy to WP uploads or use a rewrite rule) to control the serving path, or use a dedicated rewrite rule that maps `/hr-sw.js` to the AJAX handler. This is a known architectural limitation of serving SWs via AJAX.

---

### LOGIC-003 [Medium]: Push notification listener in service worker (`push` event) is implemented but no push subscription management exists

- **File:** `assets/pwa/service-worker.js:189-201`
- **Detail:** The service worker registers a `push` event listener and displays notifications from `event.data.json()`. However, there is no code anywhere in PWAModule.php or the JS files to:
  - Register a push subscription with a VAPID server
  - Send the subscription endpoint to a WordPress REST API or AJAX handler
  - Store push subscriptions server-side
  - Send push notifications from PHP
  VAPID keys are not defined anywhere in the module.
- **Impact:** The push notification listener is entirely dead code. Any `push` event that arrives will process it correctly, but pushes can never arrive because no subscription is registered. The feature is functionally absent.
- **Fix (stub assessment):** This is the primary indicator that PWA is a stub module. Options: (a) Remove the push event listener to eliminate dead code confusion, (b) Implement VAPID key generation, subscription endpoint, and PHP push sender in v1.2, or (c) Gate the feature behind a settings flag so intent is clear.

---

### LOGIC-004 [Low]: Static `manifest.json` in `assets/pwa/` is dead code — never served to users

- **File:** `assets/pwa/manifest.json`
- **Detail:** There is a `manifest.json` file in the `assets/pwa/` directory. However, `PWAModule.php` generates the manifest dynamically via `ajax_serve_manifest()` and the `<link rel="manifest">` tag points to the AJAX URL. The static file is never referenced. It also contains outdated icon paths (`icon-192.png`, `icon-512.png`, `icon-punch.png`) that do not exist in the assets directory (only `icon-192.svg` exists).
- **Impact:** Dead file with incorrect paths — could confuse developers. No runtime impact.
- **Fix:** Delete `assets/pwa/manifest.json` as it is superseded by the dynamic endpoint, or update it to serve as developer documentation of the manifest structure.

---

### LOGIC-005 [Low]: Sync notification icon path is hardcoded with plugin-specific path

- **File:** `assets/pwa/service-worker.js:144`
- **Detail:** `showNotification` uses a hardcoded icon path: `/wp-content/plugins/hr-suite/assets/pwa/icon-192.svg`. If the plugin is installed under a different folder name (e.g., `simple-hr-suite` or renamed), the icon will 404 silently.
- **Impact:** Notification shows without icon on non-standard plugin directory name installations.
- **Fix:** The plugin's URL constant `SFS_HR_URL` is PHP-only. Pass the icon URL to the service worker via `wp_localize_script` in `enqueue_pwa_script()` and store it in `sfsHrPwa.iconUrl`. Then use `self.ICON_URL` set via a `message` event on SW install, or use a relative path. A simpler fix: pass the URL through `sfsHrPwa` object and re-use it from the sync notification path.

---

## Stub/Incomplete Code Assessment

### 1. Push Notification Subscription System

**What exists:** Service worker `push` event listener (service-worker.js:189-201); `notificationclick` handler (service-worker.js:204-218).

**What's missing:**
- VAPID key generation/storage (no PHP, no DB table)
- Push subscription endpoint (`/wp-json/sfs-hr/v1/pwa/subscribe` does not exist)
- PHP code to store and manage push subscriptions per employee
- PHP code to send push notifications (no `web-push` library integration)
- Subscription cleanup on employee termination or logout

**Risk of incomplete state:** The service worker will receive any `push` event and display it correctly — but since no subscription is registered, no events will arrive. Functionally: zero impact. However, if a future developer adds VAPID keys and subscription logic without reviewing this code, they may assume the push listener is production-ready when it is not (no auth on subscription endpoint, no terminated-employee cleanup).

**Recommendation:** Either remove the `push` and `notificationclick` listeners or add a PHP-level feature flag `sfs_hr_pwa_push_enabled` that controls whether the JS event listeners are registered. Do not leave dead infrastructure that implies functionality.

---

### 2. Offline Kiosk Employee Roster

**What exists:** Full IndexedDB management for employee roster in `pwa-app.js` (replaceRoster, getEmployee, getAllEmployees, isRosterFresh). SHA-256 helper for token validation. `refreshRoster()` fetches from `/sfs-hr/v1/attendance/kiosk-roster`.

**What's missing:**
- Actual kiosk JS code that USES the roster for offline punch validation (not in PWAModule.php; expected in the Kiosk shortcode JS which is separate)
- Documentation of when `refreshRoster()` is called

**Risk:** The roster infrastructure is implemented but its consumer may not exist. If `refreshRoster()` is called but the kiosk JS doesn't use the IndexedDB roster for offline validation, employee data is being cached without any benefit — pure risk with no reward.

**Recommendation:** Verify that the kiosk shortcode JS calls `window.sfsHrPwa.db.getEmployee()` for offline validation. If not, either implement the consumer or disable roster caching.

---

### 3. `pwa-app.js` Duplicate Registration in IndexedDB

**What exists:** Both `service-worker.js` and `pwa-app.js` define `openDB()` independently with the same database name `sfs-hr-punches` and version `2`. Both define the same `onupgradeneeded` schema.

**What's missing:** Single source of truth for DB schema. If one is updated (e.g., version bumped) without the other, IndexedDB schema conflicts will cause hard-to-debug failures.

**Risk:** Medium. IndexedDB version conflicts throw errors that terminate all transactions.

**Recommendation:** Maintain the schema definition in `pwa-app.js` only (runs in window context). The service worker should rely on the schema being already created by the page-side code. The SW's `openDB()` should omit `onupgradeneeded` (or handle the case where it fires and the stores already exist, which it already does with `contains()` checks).

---

## Data Leakage Assessment

### What data is cacheable by the service worker?

The fetch handler in `service-worker.js` (lines 54-71) caches ALL successful GET responses that are not:
- Admin AJAX (`admin-ajax.php`)
- WP REST (`/wp-json/`)
- WP Admin paths (`/wp-admin/`)

This means the following HR pages ARE cacheable:
- Frontend HR portal pages containing leave balances, profile data, attendance history
- Any page with `[sfs_hr_my_profile]`, `[sfs_hr_kiosk]`, or `[sfs_hr_attendance_widget]` shortcodes

**Verdict:** Dynamic HR data pages are cached by the service worker. On a shared device, cached pages persist in the browser's Cache Storage after the user logs out (service workers do not clear cache on logout). A subsequent user on the same device could access cached HR pages if they know the URL.

**Fix:** Add a `cache-clear-on-logout` event listener: listen for `navigator.serviceWorker` message with type `sfs-hr-logout` and call `caches.delete(CACHE_NAME)`. Trigger this from the WP logout action.

---

### Are auth tokens stored in localStorage/sessionStorage accessible to the service worker?

The WP REST nonce is in `sfsHrPwa.nonce` (inline page variable, not localStorage) and `window.SFS_ATT_NONCE` (also an inline page variable). Neither is stored in localStorage.

The install prompt dismiss flag (`sfs_hr_pwa_dismissed`) is stored in localStorage — this is not sensitive.

**Verdict:** Nonces are not persisted to localStorage. The offline punch queue stores a nonce at punch time (in IndexedDB), but only as a fallback for offline sync — this is inherent to offline-first punch design. The risk is documented under SEC-003.

---

### Is the manifest exposing internal infrastructure?

Yes — partially. The dynamically generated manifest (PWAModule.php lines 173-208) exposes:
- `admin_url('admin.php?page=sfs-hr-my-profile')` as a shortcut URL (line 205)
- `home_url('/?pwa=1&action=punch')` as a shortcut URL

The admin URL shortcut is the most notable: any unauthenticated user who fetches the manifest (currently possible — see SEC-002) learns that the HR admin is at `wp-admin/admin.php?page=sfs-hr-my-profile`. This confirms WP is running and reveals a specific plugin page.

**Fix:** Remove the admin URL shortcut from the manifest, or restrict the manifest endpoint to authenticated users only (SEC-002 fix).

---

### Are API responses with employee PII in the cache?

REST API calls (`/wp-json/sfs-hr/v1/`) are excluded from the SW cache (service-worker.js line 49). Admin AJAX calls are also excluded (line 49). Therefore, direct API responses are NOT cached by the service worker.

However, HTML page responses that EMBED employee data (e.g., SSR profile pages) ARE cached (see "What data is cacheable" above). This is the primary concern.

---

## Cross-Module Patterns

### Recurring Antipattern Check

| Antipattern | Phases Found | PWAModule.php |
|-------------|-------------|---------------|
| Bare `ALTER TABLE` (no `add_column_if_missing`) | Phase 04, 08, 16 | **Not present** — PWAModule has no DB tables |
| `information_schema.tables` query | Phase 04, 08, 11, 12, 16, 18 | **Not present** — no database queries at all |
| Unprepared `SHOW TABLES` | Phase 04, 08 | **Not present** |
| `__return_true` permission callback | Phase 05 | **Not present** — no REST endpoints |
| Nonce-only guard without capability check | Phase 13 | **Partially present** — manifest/SW via `nopriv` AJAX (no nonce, no capability — see SEC-002) |

### $wpdb Query Catalogue

**Total $wpdb calls in PWAModule.php: 0**

PWAModule.php has no database queries whatsoever. All DB access (if any) is delegated to other modules. The module is purely presentational + asset delivery.

This is the cleanest bootstrap in the audit series from a SQL injection perspective.

---

### Comparison with Prior Phase Findings

- **No recurring SQL antipatterns** — unlike Loans (Phase 08), Core (Phase 04), Assets (Phase 11), Documents (Phase 16), Projects (Phase 18): all clear.
- **No `__return_true` REST endpoints** — unlike Phase 05 Attendance kiosk endpoints.
- **`nopriv_` AJAX pattern** is a new variant: it is not `__return_true` but achieves the same effect for manifest/SW delivery — unauthenticated access to endpoints that reveal internal URLs. Severity is lower (High vs Critical) because no employee data is exposed.
- **Service worker scope breadth** echoes the systemic "wrong capability gate / over-broad access" pattern seen in Assets (Phase 11), Workforce_Status (Phase 15), and Documents (Phase 16) — where access was granted more broadly than intended.
- **Push notification dead code** continues the stub pattern seen in Surveys (Phase 18) where features were partially scaffolded but never completed.
