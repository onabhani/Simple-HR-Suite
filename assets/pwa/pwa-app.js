/**
 * Simple HR Suite - PWA Application Script
 * Handles service worker registration and PWA functionality
 */
(function() {
    'use strict';

    if (!('serviceWorker' in navigator)) return;

    // Register service worker
    window.addEventListener('load', async () => {
        try {
            const registration = await navigator.serviceWorker.register(
                sfsHrPwa.serviceWorkerUrl,
                { scope: '/' }
            );

            // Check for updates
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        showUpdateNotification();
                    }
                });
            });
        } catch (error) {
            console.log('[PWA] SW registration failed:', error);
        }
    });

    // Respond to service worker nonce requests (for offline sync)
    navigator.serviceWorker.addEventListener('message', (event) => {
        if (event.data && event.data.type === 'sfs-hr-get-nonce') {
            const nonce = window.SFS_ATT_NONCE || '';
            if (event.ports && event.ports[0]) {
                event.ports[0].postMessage({ nonce: nonce });
            }
        }
    });

    function showUpdateNotification() {
        const el = document.createElement('div');
        el.id = 'sfs-hr-pwa-update';
        el.innerHTML = '<div style="position:fixed;bottom:20px;left:20px;right:20px;max-width:400px;margin:0 auto;background:#2271b1;color:#fff;border-radius:8px;padding:12px 16px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:99999;display:flex;align-items:center;justify-content:space-between;"><span>' + ((sfsHrPwa.i18n && sfsHrPwa.i18n.updateAvailable) || 'A new version is available!') + '</span><button onclick="location.reload()" style="background:#fff;color:#2271b1;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-weight:600;">' + ((sfsHrPwa.i18n && sfsHrPwa.i18n.refresh) || 'Refresh') + '</button></div>';
        document.body.appendChild(el);
    }

    // Handle offline/online status
    function updateConnectionStatus() {
        const isOnline = navigator.onLine;
        document.body.classList.toggle('sfs-hr-offline', !isOnline);

        if (isOnline && 'SyncManager' in window) {
            navigator.serviceWorker.ready.then(reg => {
                reg.sync.register('sfs-hr-punch-sync');
            }).catch(() => {});
        }
    }

    window.addEventListener('online', updateConnectionStatus);
    window.addEventListener('offline', updateConnectionStatus);
    updateConnectionStatus();

    // ---- IndexedDB: version 2 adds 'employees' store for offline kiosk roster ----
    var DB_NAME = 'sfs-hr-punches';
    var DB_VERSION = 2;

    window.sfsHrPwa = window.sfsHrPwa || {};

    window.sfsHrPwa.db = {
        open: function() {
            return new Promise(function(resolve, reject) {
                var request = indexedDB.open(DB_NAME, DB_VERSION);
                request.onerror = function() { reject(request.error); };
                request.onsuccess = function() { resolve(request.result); };
                request.onupgradeneeded = function(event) {
                    var db = event.target.result;
                    // v1: punches store
                    if (!db.objectStoreNames.contains('punches')) {
                        db.createObjectStore('punches', { keyPath: 'id', autoIncrement: true });
                    }
                    // v2: employees store for offline kiosk roster
                    if (!db.objectStoreNames.contains('employees')) {
                        var empStore = db.createObjectStore('employees', { keyPath: 'id' });
                        empStore.createIndex('token_hash', 'token_hash', { unique: false });
                    }
                };
            });
        },

        // ---- Punch operations ----

        storePunch: async function(punchData) {
            var db = await this.open();
            return new Promise(function(resolve, reject) {
                var tx = db.transaction('punches', 'readwrite');
                var store = tx.objectStore('punches');
                var request = store.add(Object.assign({}, punchData, { timestamp: Date.now() }));
                request.onerror = function() { reject(request.error); };
                request.onsuccess = function() { resolve(request.result); };
            });
        },

        getPendingPunches: async function() {
            var db = await this.open();
            return new Promise(function(resolve, reject) {
                var tx = db.transaction('punches', 'readonly');
                var store = tx.objectStore('punches');
                var request = store.getAll();
                request.onerror = function() { reject(request.error); };
                request.onsuccess = function() { resolve(request.result); };
            });
        },

        clearPunches: async function() {
            var db = await this.open();
            return new Promise(function(resolve, reject) {
                var tx = db.transaction('punches', 'readwrite');
                var store = tx.objectStore('punches');
                var request = store.clear();
                request.onerror = function() { reject(request.error); };
                request.onsuccess = function() { resolve(); };
            });
        },

        // ---- Employee roster operations (for offline kiosk validation) ----

        /**
         * Replace entire employee roster with fresh data from server.
         * @param {Array} employees - Array of { id, code, name, token_hmac }
         * @param {number} generatedAt - Server timestamp (seconds)
         * @param {number} ttl - Cache TTL in seconds
         * @param {string} rosterNonce - Per-roster HMAC nonce from server
         */
        replaceRoster: async function(employees, generatedAt, ttl, rosterNonce) {
            var db = await this.open();
            return new Promise(function(resolve, reject) {
                var tx = db.transaction('employees', 'readwrite');
                var store = tx.objectStore('employees');
                store.clear(); // wipe old roster
                // Store roster metadata (nonce, timestamps) as a reserved record
                store.put({
                    id: '__roster_meta__',
                    roster_nonce: rosterNonce || '',
                    _cached_at: generatedAt,
                    _ttl: ttl
                });
                for (var i = 0; i < employees.length; i++) {
                    store.put(Object.assign({}, employees[i], {
                        _cached_at: generatedAt,
                        _ttl: ttl
                    }));
                }
                tx.oncomplete = function() { resolve(); };
                tx.onerror = function() { reject(tx.error); };
            });
        },

        /**
         * Retrieve the roster nonce stored during the last replaceRoster call.
         * Returns the nonce string, or null if not found.
         */
        getRosterNonce: async function() {
            var db = await this.open();
            return new Promise(function(resolve, reject) {
                var tx = db.transaction('employees', 'readonly');
                var store = tx.objectStore('employees');
                var request = store.get('__roster_meta__');
                request.onerror = function() { reject(request.error); };
                request.onsuccess = function() {
                    var meta = request.result;
                    resolve((meta && meta.roster_nonce) ? meta.roster_nonce : null);
                };
            });
        },

        /**
         * Look up an employee by ID. Returns record or undefined.
         */
        getEmployee: async function(empId) {
            var db = await this.open();
            return new Promise(function(resolve, reject) {
                var tx = db.transaction('employees', 'readonly');
                var store = tx.objectStore('employees');
                var request = store.get(empId);
                request.onerror = function() { reject(request.error); };
                request.onsuccess = function() { resolve(request.result); };
            });
        },

        /**
         * Get all cached employees (for roster age check).
         */
        getAllEmployees: async function() {
            var db = await this.open();
            return new Promise(function(resolve, reject) {
                var tx = db.transaction('employees', 'readonly');
                var store = tx.objectStore('employees');
                var request = store.getAll();
                request.onerror = function() { reject(request.error); };
                request.onsuccess = function() { resolve(request.result); };
            });
        },

        /**
         * Check if the roster cache is still valid.
         * Returns true if at least one employee exists and _cached_at + _ttl > now.
         */
        isRosterFresh: async function() {
            try {
                var employees = await this.getAllEmployees();
                if (!employees || !employees.length) return false;
                var first = employees[0];
                var expiresAt = (first._cached_at || 0) + (first._ttl || 0);
                return (Date.now() / 1000) < expiresAt;
            } catch (_) {
                return false;
            }
        }
    };

    // ---- SHA-256 helper using Web Crypto API (for offline QR token validation) ----

    window.sfsHrPwa.sha256 = async function(message) {
        var msgBuffer = new TextEncoder().encode(message);
        var hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
        var hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(function(b) { return b.toString(16).padStart(2, '0'); }).join('');
    };

    // ---- HMAC-SHA-256 helper using Web Crypto API (for offline kiosk roster validation) ----

    window.sfsHrPwa.hmacSha256 = async function(message, key) {
        var enc = new TextEncoder();
        var keyData = await crypto.subtle.importKey(
            'raw', enc.encode(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
        );
        var sig = await crypto.subtle.sign('HMAC', keyData, enc.encode(message));
        return Array.from(new Uint8Array(sig)).map(function(b) {
            return b.toString(16).padStart(2, '0');
        }).join('');
    };

    // ---- Roster refresh logic ----

    /**
     * Fetch fresh roster from server and cache it in IndexedDB.
     * Called on kiosk page load (when online) and periodically.
     * @param {number} deviceId - The kiosk device ID
     * @param {string} nonce - WP REST nonce
     * @returns {boolean} true if roster was refreshed
     */
    window.sfsHrPwa.refreshRoster = async function(deviceId, nonce) {
        if (!navigator.onLine) return false;
        if (!deviceId) return false;

        try {
            var restBase = (window.sfsHrPwa && window.sfsHrPwa.restUrl) ? window.sfsHrPwa.restUrl : window.location.origin + '/wp-json/';
            var url = restBase + 'sfs-hr/v1/attendance/kiosk-roster?device=' + deviceId;
            var resp = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-WP-Nonce': nonce, 'Cache-Control': 'no-cache' }
            });
            if (!resp.ok) return false;

            var data = await resp.json();
            if (!data || !data.ok || !Array.isArray(data.employees)) return false;

            await window.sfsHrPwa.db.replaceRoster(
                data.employees,
                data.generated_at || Math.floor(Date.now() / 1000),
                data.ttl || 1800,
                data.roster_nonce || ''
            );
            console.log('[PWA] Roster cached:', data.employees.length, 'employees');
            return true;
        } catch (e) {
            console.log('[PWA] Roster refresh failed:', e);
            return false;
        }
    };
})();
