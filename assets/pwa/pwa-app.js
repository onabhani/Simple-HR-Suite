/**
 * Simple HR Suite - PWA Application Script
 * Handles service worker registration and PWA functionality
 */

(function() {
    'use strict';

    // Register service worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', async () => {
            try {
                const registration = await navigator.serviceWorker.register(
                    sfsHrPwa.serviceWorkerUrl,
                    { scope: '/' }
                );

                console.log('[PWA] Service Worker registered:', registration.scope);

                // Check for updates
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            // New version available
                            showUpdateNotification();
                        }
                    });
                });
            } catch (error) {
                console.log('[PWA] Service Worker registration failed:', error);
            }
        });
    }

    // Show update notification
    function showUpdateNotification() {
        const notification = document.createElement('div');
        notification.id = 'sfs-hr-pwa-update';
        notification.innerHTML = `
            <div style="position:fixed; bottom:20px; left:20px; right:20px; max-width:400px; margin:0 auto; background:#2271b1; color:#fff; border-radius:8px; padding:12px 16px; box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:99999; display:flex; align-items:center; justify-content:space-between;">
                <span>${sfsHrPwa.i18n.updateAvailable || 'A new version is available!'}</span>
                <button onclick="location.reload()" style="background:#fff; color:#2271b1; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-weight:600;">
                    ${sfsHrPwa.i18n.refresh || 'Refresh'}
                </button>
            </div>
        `;
        document.body.appendChild(notification);
    }

    // Handle offline/online status
    function updateConnectionStatus() {
        const isOnline = navigator.onLine;
        document.body.classList.toggle('sfs-hr-offline', !isOnline);

        // Try to sync punches when back online
        if (isOnline && 'serviceWorker' in navigator && 'sync' in window.ServiceWorkerRegistration.prototype) {
            navigator.serviceWorker.ready.then(registration => {
                registration.sync.register('sfs-hr-punch-sync');
            });
        }
    }

    window.addEventListener('online', updateConnectionStatus);
    window.addEventListener('offline', updateConnectionStatus);
    updateConnectionStatus();

    // Request notification permission
    if ('Notification' in window && Notification.permission === 'default') {
        // Only ask after user interaction
        document.addEventListener('click', function askNotificationPermission() {
            Notification.requestPermission();
            document.removeEventListener('click', askNotificationPermission);
        }, { once: true });
    }

    // Expose PWA utilities
    window.sfsHrPwa = window.sfsHrPwa || {};

    window.sfsHrPwa.showNotification = function(title, body, options = {}) {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(title, {
                body: body,
                icon: options.icon || '/wp-content/plugins/hr-suite/assets/pwa/icon-192.png',
                badge: options.badge || '/wp-content/plugins/hr-suite/assets/pwa/badge-72.png',
                ...options
            });
        }
    };

    // IndexedDB utilities for offline punch storage
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
                const transaction = db.transaction('punches', 'readwrite');
                const store = transaction.objectStore('punches');
                const request = store.add({
                    ...punchData,
                    timestamp: Date.now()
                });

                request.onerror = () => reject(request.error);
                request.onsuccess = () => resolve(request.result);
            });
        },

        getPendingPunches: async function() {
            const db = await this.open();
            return new Promise((resolve, reject) => {
                const transaction = db.transaction('punches', 'readonly');
                const store = transaction.objectStore('punches');
                const request = store.getAll();

                request.onerror = () => reject(request.error);
                request.onsuccess = () => resolve(request.result);
            });
        },

        clearPunches: async function() {
            const db = await this.open();
            return new Promise((resolve, reject) => {
                const transaction = db.transaction('punches', 'readwrite');
                const store = transaction.objectStore('punches');
                const request = store.clear();

                request.onerror = () => reject(request.error);
                request.onsuccess = () => resolve();
            });
        }
    };

    console.log('[PWA] HR Suite PWA initialized');
})();
