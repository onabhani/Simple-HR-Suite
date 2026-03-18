/**
 * Simple HR Suite - Service Worker
 * Provides offline capability for punch in/out
 */

const CACHE_NAME = 'sfs-hr-pwa-v3';

// Assets to cache for offline use (only safe, always-available assets)
const PRECACHE_ASSETS = [
    '/wp-includes/css/dashicons.min.css',
];

// Install event - cache essential assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS).catch(err => {
                console.log('[SW] Pre-cache partial failure:', err);
            });
        })
    );
    self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// Fetch event - network first, cache fallback for GET requests
self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;

    const url = new URL(event.request.url);
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

    // Skip API / AJAX requests — always go to network
    if (url.pathname.includes('/wp-json/') || url.pathname.includes('admin-ajax.php')) return;

    // Skip WP admin pages — don't cache them
    if (url.pathname.includes('/wp-admin/')) return;

    // Only cache static assets (CSS, JS, images, fonts) — never cache HTML pages
    // which may contain authenticated content / PII.
    const ext = url.pathname.split('.').pop().toLowerCase();
    const cacheableExts = ['css','js','png','jpg','jpeg','gif','svg','ico','woff','woff2','ttf','eot'];
    const isCacheable = cacheableExts.indexOf(ext) !== -1;

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response.status === 200 && isCacheable) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                return caches.match(event.request).then((cached) => {
                    if (cached) return cached;
                    return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
                });
            })
    );
});

// Background sync for offline punch attempts
self.addEventListener('sync', (event) => {
    if (event.tag === 'sfs-hr-punch-sync') {
        event.waitUntil(syncPunches());
    }
});

// Sync offline punches when back online
async function syncPunches() {
    const db = await openDB();
    const punches = await getAllPendingPunches(db);

    if (!punches.length) return;

    // Get a fresh nonce from any open client window
    let freshNonce = null;
    try {
        const clients = await self.clients.matchAll({ type: 'window' });
        for (const client of clients) {
            // Ask the client for a fresh nonce via postMessage
            freshNonce = await new Promise((resolve) => {
                const channel = new MessageChannel();
                channel.port1.onmessage = (e) => resolve(e.data?.nonce || null);
                client.postMessage({ type: 'sfs-hr-get-nonce' }, [channel.port2]);
                setTimeout(() => resolve(null), 3000);
            });
            if (freshNonce) break;
        }
    } catch (_) {}

    let synced = 0;
    for (const punch of punches) {
        try {
            const useNonce = freshNonce || punch.nonce;

            // Build the body — include offline_origin fields if present
            const body = Object.assign({}, punch.data);
            if (punch.offline_origin) {
                body.offline_origin      = true;
                body.offline_employee_id = punch.offline_employee_id || 0;
                body.client_punch_time   = punch.client_punch_time || '';
            }

            const response = await fetch(punch.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': useNonce
                },
                body: JSON.stringify(body)
            });

            if (response.ok || response.status === 409) {
                // 409 = duplicate/invalid transition — remove from queue anyway
                await deletePunch(db, punch.id);
                synced++;
            } else if (response.status === 401 || response.status === 403) {
                // Nonce expired — keep for retry, but discard if too old or too many retries
                const retryCount = (punch.retryCount || 0) + 1;
                const ageMs = Date.now() - (punch.timestamp || 0);
                const MAX_RETRIES = 10;
                const MAX_AGE_MS = 48 * 60 * 60 * 1000; // 48 hours

                if (retryCount > MAX_RETRIES || ageMs > MAX_AGE_MS) {
                    console.log('[SW] Discarding stale punch:', punch.id, { retryCount, ageMs });
                    await deletePunch(db, punch.id);
                } else {
                    await updatePunch(db, punch.id, { retryCount });
                }
            }
        } catch (err) {
            console.log('[SW] Sync failed for punch:', punch.id, err);
            // Network still down — stop trying
            break;
        }
    }

    if (synced > 0) {
        self.registration.showNotification('HR Suite', {
            body: synced === 1
                ? 'Offline punch synced successfully!'
                : `${synced} offline punches synced successfully!`,
            icon: '/wp-content/plugins/hr-suite/assets/pwa/icon-192.svg'
        });
    }
}

// IndexedDB helpers for offline punch storage (v2: includes employees store)
function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('sfs-hr-punches', 2);
        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('punches')) {
                db.createObjectStore('punches', { keyPath: 'id', autoIncrement: true });
            }
            if (!db.objectStoreNames.contains('employees')) {
                const empStore = db.createObjectStore('employees', { keyPath: 'id' });
                empStore.createIndex('token_hash', 'token_hash', { unique: false });
            }
        };
    });
}

function getAllPendingPunches(db) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction('punches', 'readonly');
        const store = tx.objectStore('punches');
        const request = store.getAll();
        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);
    });
}

function deletePunch(db, id) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction('punches', 'readwrite');
        const store = tx.objectStore('punches');
        const request = store.delete(id);
        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve();
    });
}

function updatePunch(db, id, fields) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction('punches', 'readwrite');
        const store = tx.objectStore('punches');
        const getReq = store.get(id);
        getReq.onerror = () => reject(getReq.error);
        getReq.onsuccess = () => {
            const punch = getReq.result;
            if (!punch) { resolve(); return; }
            Object.assign(punch, fields);
            const putReq = store.put(punch);
            putReq.onerror = () => reject(putReq.error);
            putReq.onsuccess = () => resolve();
        };
    });
}

// Push notifications
self.addEventListener('push', (event) => {
    if (!event.data) return;
    let data;
    try {
        data = event.data.json();
    } catch (e) {
        console.warn('[SFS HR] Failed to parse push data', e);
        data = { title: 'HR Suite', body: 'You have a new notification.' };
    }
    event.waitUntil(
        self.registration.showNotification(data.title || 'HR Suite', {
            body: data.body || '',
            icon: data.icon || '/wp-content/plugins/hr-suite/assets/pwa/icon-192.svg',
            vibrate: [100, 50, 100],
            data: data.url || '/',
            actions: data.actions || []
        })
    );
});

// Handle notification click
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        self.clients.matchAll({ type: 'window' }).then((clientList) => {
            for (const client of clientList) {
                if (client.url === event.notification.data && 'focus' in client) {
                    return client.focus();
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(event.notification.data);
            }
        })
    );
});
