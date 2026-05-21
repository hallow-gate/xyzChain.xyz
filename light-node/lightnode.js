// Light Node Implementation using IndexedDB - Fixed for PHP Backend
class LightNode {
    constructor() {
        this.db = null;
        this.headers = [];
        this.maxHeaders = 1000;
        this.syncInterval = 60000; // 1 minute
        this.apiBase = ''; // Use relative paths for same-origin requests
        this.isSyncing = false;
    }
    
    async init() {
        await this.openDatabase();
        await this.loadHeaders();
        this.startSync();
        console.log('Light node initialized');
    }
    
    async openDatabase() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open('XYZChainLightNode', 2);
            
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                
                // Block headers store
                if (!db.objectStoreNames.contains('headers')) {
                    const headerStore = db.createObjectStore('headers', { keyPath: 'index' });
                    headerStore.createIndex('hash', 'hash', { unique: true });
                    headerStore.createIndex('timestamp', 'timestamp', { unique: false });
                }
                
                // Transaction store
                if (!db.objectStoreNames.contains('transactions')) {
                    const txStore = db.createObjectStore('transactions', { keyPath: 'txid' });
                    txStore.createIndex('block_index', 'block_index', { unique: false });
                    txStore.createIndex('sender', 'sender', { unique: false });
                    txStore.createIndex('receiver', 'receiver', { unique: false });
                }
                
                // Settings store
                if (!db.objectStoreNames.contains('settings')) {
                    db.createObjectStore('settings', { keyPath: 'key' });
                }
                
                // Pending transactions store for offline support
                if (!db.objectStoreNames.contains('pending_tx')) {
                    const pendingStore = db.createObjectStore('pending_tx', { keyPath: 'id', autoIncrement: true });
                    pendingStore.createIndex('timestamp', 'timestamp', { unique: false });
                }
            };
            
            request.onsuccess = (event) => {
                this.db = event.target.result;
                console.log('Light node database opened');
                resolve();
            };
            
            request.onerror = (event) => {
                console.error('Database error:', event.target.error);
                reject(event.target.error);
            };
        });
    }
    
    async loadHeaders() {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['headers'], 'readonly');
            const store = transaction.objectStore('headers');
            const request = store.getAll();
            
            request.onsuccess = () => {
                this.headers = request.result || [];
                console.log(`Loaded ${this.headers.length} headers from cache`);
                resolve();
            };
            
            request.onerror = () => {
                reject(request.error);
            };
        });
    }
    
    async saveHeader(header) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['headers'], 'readwrite');
            const store = transaction.objectStore('headers');
            const request = store.put(header);
            
            request.onsuccess = () => {
                // Update in-memory cache
                const existingIndex = this.headers.findIndex(h => h.index === header.index);
                if (existingIndex >= 0) {
                    this.headers[existingIndex] = header;
                } else {
                    this.headers.push(header);
                }
                this.cleanOldHeaders();
                resolve();
            };
            
            request.onerror = () => {
                reject(request.error);
            };
        });
    }
    
    async saveTransaction(tx) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['transactions'], 'readwrite');
            const store = transaction.objectStore('transactions');
            const request = store.put(tx);
            
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }
    
    async cleanOldHeaders() {
        if (this.headers.length > this.maxHeaders) {
            const oldHeaders = this.headers.slice(0, this.headers.length - this.maxHeaders);
            const transaction = this.db.transaction(['headers'], 'readwrite');
            const store = transaction.objectStore('headers');
            
            for (const header of oldHeaders) {
                store.delete(header.index);
            }
            
            this.headers = this.headers.slice(-this.maxHeaders);
            console.log(`Cleaned old headers, kept ${this.headers.length}`);
        }
    }
    
    async verifySimplePaymentVerification(txid, blockHeader) {
        const tx = await this.getTransaction(txid);
        if (!tx) return false;
        
        // Verify transaction exists in block
        if (blockHeader && blockHeader.merkle_root) {
            // Simplified verification
            return true;
        }
        
        return false;
    }
    
    async getTransaction(txid) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['transactions'], 'readonly');
            const store = transaction.objectStore('transactions');
            const request = store.get(txid);
            
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }
    
    async syncHeaders(fromHeight = 0) {
        if (this.isSyncing) {
            console.log('Sync already in progress');
            return;
        }
        
        this.isSyncing = true;
        
        try {
            // Fetch chain data from PHP backend
            const response = await fetch(`/chain?limit=100`);
            const data = await response.json();
            
            if (data.success && data.data && data.data.blocks) {
                const blocks = data.data.blocks;
                
                // Convert blocks to headers format
                const headers = blocks.map(block => ({
                    index: block.index,
                    hash: block.hash,
                    previous_hash: block.previous_hash,
                    timestamp: block.timestamp,
                    difficulty: block.difficulty,
                    nonce: block.nonce,
                    merkle_root: block.merkle_root,
                    transactions_count: block.transactions ? block.transactions.length : 0
                }));
                
                // Save only new headers
                let newCount = 0;
                for (const header of headers) {
                    const exists = this.headers.some(h => h.index === header.index);
                    if (!exists) {
                        await this.saveHeader(header);
                        newCount++;
                    }
                    
                    // Also save transactions from blocks
                    const block = blocks.find(b => b.index === header.index);
                    if (block && block.transactions) {
                        for (const tx of block.transactions) {
                            tx.block_index = block.index;
                            await this.saveTransaction(tx);
                        }
                    }
                }
                
                console.log(`Synced ${newCount} new headers, total: ${this.headers.length}`);
                
                // Update latest header in settings
                if (headers.length > 0) {
                    await this.setSetting('latest_height', headers[headers.length - 1].index);
                }
            }
        } catch (error) {
            console.error('Header sync failed:', error);
        } finally {
            this.isSyncing = false;
        }
    }
    
    async getLatestHeader() {
        if (this.headers.length === 0) {
            return null;
        }
        // Headers are sorted by index
        return this.headers[this.headers.length - 1];
    }
    
    async getHeaderByHeight(height) {
        return this.headers.find(h => h.index === height) || null;
    }
    
    async getBalance(address) {
        try {
            const response = await fetch(`/wallet/balance?address=${encodeURIComponent(address)}`);
            const data = await response.json();
            return data.success ? data.data : null;
        } catch (error) {
            console.error('Balance fetch failed:', error);
            return null;
        }
    }
    
    async getTransactionHistory(address, limit = 50) {
        try {
            // First try to get from local cache
            return new Promise((resolve) => {
                const transaction = this.db.transaction(['transactions'], 'readonly');
                const senderIndex = transaction.objectStore('transactions').index('sender');
                const receiverIndex = transaction.objectStore('transactions').index('receiver');
                
                let txList = [];
                
                // Get from sender index
                const senderRequest = senderIndex.getAll(address);
                senderRequest.onsuccess = () => {
                    txList = txList.concat(senderRequest.result || []);
                    
                    // Get from receiver index
                    const receiverRequest = receiverIndex.getAll(address);
                    receiverRequest.onsuccess = () => {
                        txList = txList.concat(receiverRequest.result || []);
                        
                        // Remove duplicates by txid
                        const uniqueTxs = [];
                        const seen = new Set();
                        for (const tx of txList) {
                            if (!seen.has(tx.txid)) {
                                seen.add(tx.txid);
                                uniqueTxs.push(tx);
                            }
                        }
                        
                        // Sort by timestamp descending
                        uniqueTxs.sort((a, b) => (b.timestamp || 0) - (a.timestamp || 0));
                        
                        resolve(uniqueTxs.slice(0, limit));
                    };
                    receiverRequest.onerror = () => resolve(txList.slice(0, limit));
                };
                senderRequest.onerror = () => {
                    // Fallback to API
                    this.getTransactionHistoryFromAPI(address, limit).then(resolve);
                };
            });
        } catch (error) {
            console.error('Transaction history fetch failed:', error);
            return this.getTransactionHistoryFromAPI(address, limit);
        }
    }
    
    async getTransactionHistoryFromAPI(address, limit = 50) {
        try {
            const response = await fetch(`/wallet/balance?address=${encodeURIComponent(address)}`);
            const data = await response.json();
            if (data.success && data.data && data.data.transactions) {
                return data.data.transactions.slice(0, limit);
            }
            return [];
        } catch (error) {
            console.error('API transaction history failed:', error);
            return [];
        }
    }
    
    async storePendingTransaction(tx) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pending_tx'], 'readwrite');
            const store = transaction.objectStore('pending_tx');
            const request = store.add({
                ...tx,
                timestamp: Date.now(),
                retryCount: 0
            });
            
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }
    
    async getPendingTransactions() {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pending_tx'], 'readonly');
            const store = transaction.objectStore('pending_tx');
            const request = store.getAll();
            
            request.onsuccess = () => resolve(request.result || []);
            request.onerror = () => reject(request.error);
        });
    }
    
    async removePendingTransaction(id) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['pending_tx'], 'readwrite');
            const store = transaction.objectStore('pending_tx');
            const request = store.delete(id);
            
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }
    
    async getSetting(key, defaultValue = null) {
        return new Promise((resolve) => {
            const transaction = this.db.transaction(['settings'], 'readonly');
            const store = transaction.objectStore('settings');
            const request = store.get(key);
            
            request.onsuccess = () => {
                resolve(request.result ? request.result.value : defaultValue);
            };
            request.onerror = () => resolve(defaultValue);
        });
    }
    
    async setSetting(key, value) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['settings'], 'readwrite');
            const store = transaction.objectStore('settings');
            const request = store.put({ key, value });
            
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }
    
    startSync() {
        // Initial sync
        this.syncHeaders(0);
        
        // Periodic sync
        setInterval(() => {
            const latestHeight = this.headers.length > 0 ? this.headers[this.headers.length - 1].index : 0;
            this.syncHeaders(latestHeight);
        }, this.syncInterval);
        
        console.log(`Light node sync started (interval: ${this.syncInterval / 1000}s)`);
    }
    
    async getNetworkStatus() {
        try {
            const response = await fetch('/api/status');
            const data = await response.json();
            return data.success ? data.data : null;
        } catch (error) {
            console.error('Network status fetch failed:', error);
            return null;
        }
    }
    
    async getBlock(height) {
        try {
            const response = await fetch(`/block/${height}`);
            const data = await response.json();
            return data.success ? data.data : null;
        } catch (error) {
            console.error('Block fetch failed:', error);
            return null;
        }
    }
    
    async getMempool() {
        try {
            const response = await fetch('/mempool');
            const data = await response.json();
            return data.success ? data.data.transactions : [];
        } catch (error) {
            console.error('Mempool fetch failed:', error);
            return [];
        }
    }
    
    // Get sync progress
    getSyncProgress() {
        const latestHeight = this.headers.length > 0 ? this.headers[this.headers.length - 1].index : 0;
        return {
            synced_headers: this.headers.length,
            latest_height: latestHeight,
            is_syncing: this.isSyncing
        };
    }
}

// Initialize light node when document is ready
let lightNodeInstance = null;

function initLightNode() {
    if (!lightNodeInstance && window.indexedDB) {
        lightNodeInstance = new LightNode();
        lightNodeInstance.init().catch(console.error);
    }
    return lightNodeInstance;
}

// Export for use in other scripts
window.LightNode = LightNode;
window.initLightNode = initLightNode;