// Service Worker for Offline Support - Fixed for XYZChain Secure

const CACHE_NAME = 'xyzchain-secure-v1';
const STATIC_CACHE_NAME = 'xyzchain-static-v1';
const API_CACHE_NAME = 'xyzchain-api-v1';

// Assets to cache - updated to match your actual file structure
const STATIC_ASSETS = [
    '/',
    '/index.html'
];

// API endpoints that can be cached (GET only)
const CACHABLE_API_ENDPOINTS = [
    '/api/status',
    '/chain',
    '/mempool',
    '/network/stats'
];

// Install service worker
self.addEventListener('install', (event) => {
    console.log('Service Worker installing...');
    
    event.waitUntil(
        caches.open(STATIC_CACHE_NAME)
            .then((cache) => {
                console.log('Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => self.skipWaiting())
    );
});

// Activate service worker - clean up old caches
self.addEventListener('activate', (event) => {
    console.log('Service Worker activating...');
    
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (cacheName !== STATIC_CACHE_NAME && 
                        cacheName !== API_CACHE_NAME &&
                        cacheName !== CACHE_NAME) {
                        console.log('Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Determine if request is for API
function isApiRequest(url) {
    return url.includes('/api/') || 
           url.includes('/chain') || 
           url.includes('/block/') ||
           url.includes('/mempool') ||
           url.includes('/wallet/balance');
}

// Determine if request should be cached
function shouldCacheApiRequest(url, method) {
    if (method !== 'GET') return false;
    
    for (const endpoint of CACHABLE_API_ENDPOINTS) {
        if (url.includes(endpoint)) return true;
    }
    
    // Cache block requests but with limit
    if (url.includes('/block/') && !url.includes('/block/latest')) {
        return true;
    }
    
    return false;
}

// Fetch strategy: Network first for API, Cache first for static
self.addEventListener('fetch', (event) => {
    const url = event.request.url;
    const method = event.request.method;
    
    // Skip non-GET requests for caching
    if (method !== 'GET') {
        // For POST/PUT/DELETE, just pass through
        event.respondWith(fetch(event.request));
        return;
    }
    
    // Handle API requests
    if (isApiRequest(url)) {
        if (shouldCacheApiRequest(url, method)) {
            // API: Network first, fallback to cache
            event.respondWith(
                fetch(event.request)
                    .then((response) => {
                        // Cache successful responses
                        if (response && response.status === 200) {
                            const responseClone = response.clone();
                            caches.open(API_CACHE_NAME)
                                .then((cache) => {
                                    cache.put(event.request, responseClone);
                                });
                        }
                        return response;
                    })
                    .catch(() => {
                        // Offline fallback - return cached response
                        return caches.match(event.request)
                            .then((cachedResponse) => {
                                if (cachedResponse) {
                                    return cachedResponse;
                                }
                                // Return a generic API error response
                                return new Response(JSON.stringify({
                                    success: false,
                                    error: 'Offline - Please check your connection',
                                    offline: true
                                }), {
                                    headers: { 'Content-Type': 'application/json' }
                                });
                            });
                    })
            );
        } else {
            // Non-cachable API: Network only
            event.respondWith(
                fetch(event.request).catch(() => {
                    return new Response(JSON.stringify({
                        success: false,
                        error: 'Network error - Please try again'
                    }), {
                        headers: { 'Content-Type': 'application/json' }
                    });
                })
            );
        }
        return;
    }
    
    // Handle static assets: Cache first, fallback to network
    event.respondWith(
        caches.match(event.request)
            .then((cachedResponse) => {
                if (cachedResponse) {
                    // Return cached version
                    return cachedResponse;
                }
                
                // Not in cache, fetch from network
                return fetch(event.request)
                    .then((response) => {
                        // Cache the response for future
                        if (response && response.status === 200) {
                            const responseClone = response.clone();
                            caches.open(STATIC_CACHE_NAME)
                                .then((cache) => {
                                    cache.put(event.request, responseClone);
                                });
                        }
                        return response;
                    });
            })
            .catch(() => {
                // Offline fallback for HTML navigation
                if (event.request.mode === 'navigate') {
                    return caches.match('/index.html');
                }
                
                return new Response('Offline - Content not available', {
                    status: 503,
                    statusText: 'Service Unavailable'
                });
            })
    );
});

// Background sync for offline transactions
self.addEventListener('sync', (event) => {
    console.log('Background sync triggered:', event.tag);
    
    if (event.tag === 'sync-transactions') {
        event.waitUntil(syncPendingTransactions());
    } else if (event.tag === 'sync-blockchain') {
        event.waitUntil(syncBlockchainData());
    }
});

async function syncPendingTransactions() {
    console.log('Syncing pending transactions...');
    
    try {
        // Open IndexedDB to get pending transactions
        const request = indexedDB.open('XYZChainLightNode', 2);
        
        request.onsuccess = async (event) => {
            const db = event.target.result;
            
            // Get pending transactions
            const transaction = db.transaction(['pending_tx'], 'readonly');
            const store = transaction.objectStore('pending_tx');
            const pendingTxs = await new Promise((resolve) => {
                const getAllRequest = store.getAll();
                getAllRequest.onsuccess = () => resolve(getAllRequest.result || []);
                getAllRequest.onerror = () => resolve([]);
            });
            
            console.log(`Found ${pendingTxs.length} pending transactions to sync`);
            
            // Send each pending transaction to server
            for (const tx of pendingTxs) {
                try {
                    const response = await fetch('/transaction/create', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            sender: tx.sender,
                            receiver: tx.receiver,
                            amount: tx.amount,
                            fee: tx.fee || 0.0001,
                            private_key: tx.private_key
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Remove from pending store
                        const deleteTx = db.transaction(['pending_tx'], 'readwrite');
                        const deleteStore = deleteTx.objectStore('pending_tx');
                        deleteStore.delete(tx.id);
                        console.log(`Transaction ${tx.txid} synced successfully`);
                    } else if (tx.retryCount < 3) {
                        // Update retry count
                        const updateTx = db.transaction(['pending_tx'], 'readwrite');
                        const updateStore = updateTx.objectStore('pending_tx');
                        tx.retryCount = (tx.retryCount || 0) + 1;
                        updateStore.put(tx);
                    } else {
                        // Max retries exceeded, remove it
                        const deleteTx = db.transaction(['pending_tx'], 'readwrite');
                        const deleteStore = deleteTx.objectStore('pending_tx');
                        deleteStore.delete(tx.id);
                        console.log(`Transaction ${tx.txid} removed after ${tx.retryCount} retries`);
                    }
                } catch (error) {
                    console.error('Failed to sync transaction:', error);
                }
            }
        };
    } catch (error) {
        console.error('Background sync failed:', error);
    }
}

async function syncBlockchainData() {
    console.log('Syncing blockchain data in background...');
    
    try {
        // Fetch latest chain status
        const response = await fetch('/api/status');
        const data = await response.json();
        
        if (data.success && data.data) {
            // Store in cache for offline use
            const cache = await caches.open(API_CACHE_NAME);
            cache.put('/api/status', new Response(JSON.stringify(data)));
        }
    } catch (error) {
        console.error('Blockchain sync failed:', error);
    }
}

// Push notification handler
self.addEventListener('push', (event) => {
    console.log('Push notification received:', event);
    
    let title = 'XYZChain Secure';
    let body = 'New blockchain update available';
    let icon = '/icon.png';
    let data = {};
    
    if (event.data) {
        try {
            const payload = event.data.json();
            title = payload.title || title;
            body = payload.body || body;
            icon = payload.icon || icon;
            data = payload.data || {};
        } catch (e) {
            body = event.data.text();
        }
    }
    
    const options = {
        body: body,
        icon: icon,
        badge: '/badge.png',
        vibrate: [200, 100, 200],
        data: data,
        actions: [
            {
                action: 'open',
                title: 'Open XYZChain'
            },
            {
                action: 'dismiss',
                title: 'Dismiss'
            }
        ]
    };
    
    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// Notification click handler
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    
    if (event.action === 'open') {
        event.waitUntil(
            clients.openWindow('/')
        );
    }
});

// Message handler for client communication
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});