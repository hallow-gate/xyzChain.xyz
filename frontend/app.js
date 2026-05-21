// XYZChain Secure - Main Application
class XYZChainApp {
    constructor() {
        this.apiUrl = 'http://localhost:8080';
        this.wsUrl = 'ws://localhost:8081';
        this.currentTab = 'wallet';
        this.walletAddress = localStorage.getItem('walletAddress');
        this.ws = null;
        
        this.init();
    }
    
    async init() {
        await this.connectWebSocket();
        this.updateNetworkStats();
        this.updateWalletBalance();
        this.startAutoUpdate();
    }
    
    async connectWebSocket() {
        try {
            this.ws = new WebSocket(this.wsUrl);
            
            this.ws.onopen = () => {
                console.log('WebSocket connected');
                this.subscribeToChannels();
            };
            
            this.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                this.handleWebSocketMessage(data);
            };
            
            this.ws.onclose = () => {
                console.log('WebSocket disconnected, reconnecting...');
                setTimeout(() => this.connectWebSocket(), 5000);
            };
        } catch (error) {
            console.error('WebSocket connection failed:', error);
        }
    }
    
    subscribeToChannels() {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify({ type: 'subscribe', channel: 'blocks' }));
            this.ws.send(JSON.stringify({ type: 'subscribe', channel: 'transactions' }));
            if (this.currentTab === 'mining') {
                this.ws.send(JSON.stringify({ type: 'subscribe', channel: 'mining' }));
            }
        }
    }
    
    handleWebSocketMessage(data) {
        switch (data.type) {
            case 'block_update':
                this.onNewBlock(data.data);
                break;
            case 'transaction_update':
                this.onTransactionUpdate(data.data);
                break;
            case 'mining_update':
                this.onMiningUpdate(data.data);
                break;
        }
    }
    
    onNewBlock(block) {
        this.showNotification('New block mined: #' + block.index, 'success');
        this.updateNetworkStats();
        this.updateWalletBalance();
        this.updateLatestBlocks();
    }
    
    onTransactionUpdate(transactions) {
        if (this.currentTab === 'explorer') {
            this.updateLatestTransactions();
        }
    }
    
    onMiningUpdate(status) {
        if (this.currentTab === 'mining') {
            this.updateMiningStatus(status);
        }
    }
    
    async apiRequest(endpoint, method = 'GET', data = null) {
        try {
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json'
                }
            };
            
            if (data) {
                options.body = JSON.stringify(data);
            }
            
            const response = await fetch(`${this.apiUrl}${endpoint}`, options);
            return await response.json();
        } catch (error) {
            console.error('API request failed:', error);
            return { success: false, error: error.message };
        }
    }
    
    async updateNetworkStats() {
        const stats = await this.apiRequest('/network/stats');
        if (stats.success) {
            document.getElementById('active-peers').textContent = stats.data.active_peers;
            document.getElementById('total-peers').textContent = stats.data.total_known_peers;
            document.getElementById('chain-height').textContent = stats.data.chain_height;
            document.getElementById('mempool-size').textContent = stats.data.mempool_size;
        }
    }
    
    async updateWalletBalance() {
        const address = this.getWalletAddress();
        if (!address) return;
        
        const balance = await this.apiRequest(`/wallet/balance?address=${address}`);
        if (balance.success) {
            document.getElementById('confirmed-balance').textContent = 
                balance.data.confirmed.toFixed(8) + ' XYZ';
            document.getElementById('pending-balance').textContent = 
                balance.data.pending.toFixed(8) + ' XYZ';
            document.getElementById('locked-balance').textContent = 
                balance.data.locked.toFixed(8) + ' XYZ';
        }
    }
    
    getWalletAddress() {
        return localStorage.getItem('walletAddress') || '';
    }
    
    showNotification(message, type = 'info') {
        const notification = document.getElementById('notification');
        notification.textContent = message;
        notification.className = `notification ${type}`;
        
        setTimeout(() => {
            notification.classList.add('hidden');
        }, 5000);
    }
    
    showModal(content) {
        const modal = document.getElementById('modal');
        document.getElementById('modal-body').innerHTML = content;
        modal.classList.remove('hidden');
    }
    
    closeModal() {
        document.getElementById('modal').classList.add('hidden');
    }
    
    startAutoUpdate() {
        setInterval(() => {
            this.updateNetworkStats();
            if (this.getWalletAddress()) {
                this.updateWalletBalance();
            }
        }, 30000); // Update every 30 seconds
    }
}

// Initialize app
const app = new XYZChainApp();

function switchTab(tab) {
    // Update navigation
    document.querySelectorAll('.nav-link').forEach(link => {
        link.classList.remove('active');
    });
    document.querySelector(`[onclick="switchTab('${tab}')"]`).classList.add('active');
    
    // Update content
    document.querySelectorAll('.tab-content').forEach(section => {
        section.classList.remove('active');
    });
    document.getElementById(`${tab}-section`).classList.add('active');
    
    app.currentTab = tab;
    
    // Subscribe to mining updates if on mining tab
    if (tab === 'mining' && app.ws && app.ws.readyState === WebSocket.OPEN) {
        app.ws.send(JSON.stringify({ type: 'subscribe', channel: 'mining' }));
    }
}