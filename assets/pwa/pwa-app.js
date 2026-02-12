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

    // IndexedDB utilities for offline punch storage
    window.sfsHrPwa = window.sfsHrPwa || {};

    window.sfsHrPwa.db = {
        open: function() {
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
        },

        storePunch: async function(punchData) {
            const db = await this.open();
            return new Promise((resolve, reject) => {
                const tx = db.transaction('punches', 'readwrite');
                const store = tx.objectStore('punches');
                const request = store.add({ ...punchData, timestamp: Date.now() });
                request.onerror = () => reject(request.error);
                request.onsuccess = () => resolve(request.result);
            });
        },

        getPendingPunches: async function() {
            const db = await this.open();
            return new Promise((resolve, reject) => {
                const tx = db.transaction('punches', 'readonly');
                const store = tx.objectStore('punches');
                const request = store.getAll();
                request.onerror = () => reject(request.error);
                request.onsuccess = () => resolve(request.result);
            });
        },

        clearPunches: async function() {
            const db = await this.open();
            return new Promise((resolve, reject) => {
                const tx = db.transaction('punches', 'readwrite');
                const store = tx.objectStore('punches');
                const request = store.clear();
                request.onerror = () => reject(request.error);
                request.onsuccess = () => resolve();
            });
        }
    };
})();
