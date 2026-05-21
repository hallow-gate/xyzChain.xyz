// Blockchain Explorer Functions

async function searchBlockchain() {
    const query = document.getElementById('search-input').value.trim();
    
    if (!query) {
        app.showNotification('Please enter a search query', 'error');
        return;
    }
    
    const results = document.getElementById('explorer-results');
    results.innerHTML = '<p>Searching...</p>';
    
    // Try to get block by height
    if (/^\d+$/.test(query)) {
        const block = await app.apiRequest(`/block/${query}`);
        if (block.success && block.data) {
            displayBlockInfo(block.data);
            return;
        }
    }
    
    // Try to get block by hash
    if (query.length === 64) {
        const block = await app.apiRequest(`/block/hash/${query}`);
        if (block.success && block.data) {
            displayBlockInfo(block.data);
            return;
        }
    }
    
    // Try to get transaction
    const transaction = await app.apiRequest(`/transaction/${query}`);
    if (transaction.success && transaction.data) {
        displayTransactionInfo(transaction.data);
        return;
    }
    
    results.innerHTML = '<p>No results found</p>';
}

function displayBlockInfo(block) {
    const results = document.getElementById('explorer-results');
    results.innerHTML = `
        <h3>Block #${block.index}</h3>
        <table class="info-table">
            <tr><td>Hash</td><td class="hash">${block.current_hash || block.hash}</td></tr>
            <tr><td>Previous Hash</td><td class="hash">${block.previous_hash}</td></tr>
            <tr><td>Timestamp</td><td>${new Date(block.timestamp * 1000).toLocaleString()}</td></tr>
            <tr><td>Difficulty</td><td>${block.difficulty}</td></tr>
            <tr><td>Nonce</td><td>${block.nonce}</td></tr>
            <tr><td>Merkle Root</td><td class="hash">${block.merkle_root}</td></tr>
            <tr><td>Miner</td><td>${block.miner_address}</td></tr>
            <tr><td>Reward</td><td>${block.reward} XYZ</td></tr>
            <tr><td>Transactions</td><td>${block.transactions ? block.transactions.length : 0}</td></tr>
            <tr><td>Size</td><td>${block.block_size} bytes</td></tr>
        </table>
        <h4>Transactions</h4>
        <div class="transaction-list">
            ${block.transactions ? block.transactions.map(tx => `
                <div class="tx-item">
                    <span class="txid">${tx.txid}</span>
                    <span>${tx.amount} XYZ</span>
                </div>
            `).join('') : '<p>No transactions</p>'}
        </div>
    `;
}

function displayTransactionInfo(transaction) {
    const results = document.getElementById('explorer-results');
    results.innerHTML = `
        <h3>Transaction</h3>
        <table class="info-table">
            <tr><td>TXID</td><td class="hash">${transaction.txid}</td></tr>
            <tr><td>From</td><td>${transaction.sender_address}</td></tr>
            <tr><td>To</td><td>${transaction.receiver_address}</td></tr>
            <tr><td>Amount</td><td>${transaction.amount} XYZ</td></tr>
            <tr><td>Fee</td><td>${transaction.fee} XYZ</td></tr>
            <tr><td>Nonce</td><td>${transaction.nonce}</td></tr>
            <tr><td>Timestamp</td><td>${new Date(transaction.timestamp * 1000).toLocaleString()}</td></tr>
        </table>
    `;
}

async function updateLatestBlocks() {
    const blocksDiv = document.getElementById('latest-blocks');
    
    try {
        const result = await app.apiRequest('/chain');
        if (result.success && result.data.blocks) {
            const blocks = result.data.blocks.slice(-10).reverse();
            blocksDiv.innerHTML = blocks.map(block => `
                <div class="block-item" onclick="searchBlockchain(${block.index})">
                    <span class="block-height">#${block.index}</span>
                    <span class="block-hash">${block.current_hash.substring(0, 16)}...</span>
                    <span class="block-time">${new Date(block.timestamp * 1000).toLocaleTimeString()}</span>
                </div>
            `).join('');
        }
    } catch (error) {
        console.error('Failed to load blocks:', error);
    }
}

async function updateLatestTransactions() {
    const txDiv = document.getElementById('latest-transactions');
    
    try {
        const result = await app.apiRequest('/mempool?limit=10');
        if (result.success && result.data.transactions) {
            txDiv.innerHTML = result.data.transactions.map(tx => `
                <div class="tx-item">
                    <span class="txid">${tx.txid.substring(0, 16)}...</span>
                    <span>${tx.amount} XYZ</span>
                    <span class="tx-status">Pending</span>
                </div>
            `).join('');
        }
    } catch (error) {
        console.error('Failed to load transactions:', error);
    }
}

// Update blocks and transactions periodically
setInterval(() => {
    if (app.currentTab === 'explorer') {
        updateLatestBlocks();
        updateLatestTransactions();
    }
}, 10000);