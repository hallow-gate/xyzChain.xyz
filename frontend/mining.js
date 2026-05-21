// Mining Management Functions

async function startMining() {
    const address = app.getWalletAddress();
    if (!address) {
        app.showNotification('Please create a wallet first', 'error');
        return;
    }
    
    const result = await app.apiRequest('/mine/start', 'POST', {
        address: address,
        threads: 1
    });
    
    if (result.success) {
        app.showNotification('Mining started!', 'success');
        document.getElementById('start-mining-btn').disabled = true;
        document.getElementById('stop-mining-btn').disabled = false;
        document.getElementById('mining-state').textContent = 'Running';
        updateMiningStatus();
    } else {
        app.showNotification('Failed to start mining: ' + result.error, 'error');
    }
}

async function stopMining() {
    const result = await app.apiRequest('/mine/stop', 'POST');
    
    if (result.success) {
        app.showNotification('Mining stopped', 'warning');
        document.getElementById('start-mining-btn').disabled = false;
        document.getElementById('stop-mining-btn').disabled = true;
        document.getElementById('mining-state').textContent = 'Stopped';
    }
}

async function updateMiningStatus() {
    const result = await app.apiRequest('/mine/status');
    
    if (result.success) {
        const status = result.data;
        document.getElementById('hash-rate').textContent = 
            status.hash_rate ? status.hash_rate.toLocaleString() + ' H/s' : '0 H/s';
        document.getElementById('current-difficulty').textContent = status.current_difficulty;
        document.getElementById('block-reward').textContent = 
            status.next_reward ? status.next_reward + ' XYZ' : '0 XYZ';
        
        if (status.is_running) {
            document.getElementById('start-mining-btn').disabled = true;
            document.getElementById('stop-mining-btn').disabled = false;
            document.getElementById('mining-state').textContent = 'Running';
        }
    }
}

// Update mining stats periodically
setInterval(() => {
    if (app.currentTab === 'mining') {
        updateMiningStatus();
    }
}, 5000);