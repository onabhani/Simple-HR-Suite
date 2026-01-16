/**
 * Simple HR Suite - Service Worker
 * Provides offline capability for punch in/out
 */

const CACHE_NAME = 'sfs-hr-pwa-v1';
const OFFLINE_URL = '/offline.html';

// Assets to cache for offline use
const PRECACHE_ASSETS = [
    '/',
    '/offline.html',
    '/wp-includes/css/dashicons.min.css',
];

// Install event - cache essential assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('[SW] Pre-caching offline assets');
            return cache.addAll(PRECACHE_ASSETS).catch(err => {
                console.log('[SW] Pre-cache failed (some assets may not exist):', err);
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
                        console.log('[SW] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// Fetch event - network first, cache fallback
self.addEventListener('fetch', (event) => {
    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Only cache http/https requests (skip chrome-extension://, etc.)
    const url = new URL(event.request.url);
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return;
    }

    // Skip API requests - always go to network
    if (event.request.url.includes('/wp-json/') || event.request.url.includes('admin-ajax.php')) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Clone the response for caching
                const responseClone = response.clone();

                // Cache successful responses
                if (response.status === 200) {
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, responseClone);
                    });
                }

                return response;
            })
            .catch(() => {
                // Network failed, try cache
                return caches.match(event.request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }

                    // If it's a navigation request, show offline page
                    if (event.request.mode === 'navigate') {
                        return caches.match(OFFLINE_URL);
                    }

                    return new Response('Offline', {
                        status: 503,
                        statusText: 'Service Unavailable'
                    });
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

    for (const punch of punches) {
        try {
            const response = await fetch(punch.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': punch.nonce
                },
                body: JSON.stringify(punch.data)
            });

            if (response.ok) {
                await deletePunch(db, punch.id);
                self.registration.showNotification('HR Suite', {
                    body: 'Offline punch synced successfully!',
                    icon: '/wp-content/plugins/hr-suite/assets/pwa/icon-192.png'
                });
            }
        } catch (err) {
            console.log('[SW] Sync failed for punch:', punch.id);
        }
    }
}

// IndexedDB helpers for offline punch storage
function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('sfs-hr-punches', 1);

        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);

        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('punches')) {
                db.createObjectStore('punches', { keyPath: 'id', autoIncrement: true });
            }
        };
    });
}

function getAllPendingPunches(db) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction('punches', 'readonly');
        const store = transaction.objectStore('punches');
        const request = store.getAll();

        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);
    });
}

function deletePunch(db, id) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction('punches', 'readwrite');
        const store = transaction.objectStore('punches');
        const request = store.delete(id);

        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve();
    });
}

// Push notifications
self.addEventListener('push', (event) => {
    if (!event.data) return;

    const data = event.data.json();

    const options = {
        body: data.body || '',
        icon: data.icon || '/wp-content/plugins/hr-suite/assets/pwa/icon-192.png',
        badge: '/wp-content/plugins/hr-suite/assets/pwa/badge-72.png',
        vibrate: [100, 50, 100],
        data: data.url || '/',
        actions: data.actions || []
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'HR Suite', options)
    );
});

// Handle notification click
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        clients.matchAll({ type: 'window' }).then((clientList) => {
            // Focus existing window if available
            for (const client of clientList) {
                if (client.url === event.notification.data && 'focus' in client) {
                    return client.focus();
                }
            }
            // Otherwise open new window
            if (clients.openWindow) {
                return clients.openWindow(event.notification.data);
            }
        })
    );
});
