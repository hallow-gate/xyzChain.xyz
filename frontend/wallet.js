// Wallet Management Functions

async function showCreateWallet() {
    const content = `
        <h3>Create New Wallet</h3>
        <form onsubmit="createWallet(event)">
            <input type="password" id="wallet-password" placeholder="Enter password (min 8 characters)" required>
            <input type="password" id="wallet-password-confirm" placeholder="Confirm password" required>
            <button type="submit">Create Wallet</button>
        </form>
        <p class="warning">Save your mnemonic phrase securely! It cannot be recovered.</p>
    `;
    app.showModal(content);
}

async function showImportWallet() {
    const content = `
        <h3>Import Wallet</h3>
        <select id="import-type" onchange="toggleImportFields()">
            <option value="mnemonic">Mnemonic Phrase</option>
            <option value="private_key">Private Key</option>
        </select>
        <div id="import-mnemonic">
            <textarea id="mnemonic-input" placeholder="Enter your 24-word mnemonic phrase"></textarea>
        </div>
        <div id="import-private-key" class="hidden">
            <input type="text" id="private-key-input" placeholder="Enter private key">
        </div>
        <input type="password" id="import-password" placeholder="Set wallet password" required>
        <button onclick="importWallet()">Import Wallet</button>
    `;
    app.showModal(content);
}

function toggleImportFields() {
    const type = document.getElementById('import-type').value;
    document.getElementById('import-mnemonic').style.display = type === 'mnemonic' ? 'block' : 'none';
    document.getElementById('import-private-key').style.display = type === 'private_key' ? 'block' : 'none';
}

async function createWallet(event) {
    event.preventDefault();
    
    const password = document.getElementById('wallet-password').value;
    const confirmPassword = document.getElementById('wallet-password-confirm').value;
    
    if (password !== confirmPassword) {
        app.showNotification('Passwords do not match', 'error');
        return;
    }
    
    if (password.length < 8) {
        app.showNotification('Password must be at least 8 characters', 'error');
        return;
    }
    
    const result = await app.apiRequest('/wallet/create', 'POST', { password });
    
    if (result.success) {
        localStorage.setItem('walletAddress', result.data.address);
        app.showNotification('Wallet created successfully!', 'success');
        app.closeModal();
        await app.updateWalletBalance();
        await loadWalletInfo();
    } else {
        app.showNotification('Failed to create wallet: ' + result.error, 'error');
    }
}

async function importWallet() {
    const type = document.getElementById('import-type').value;
    const password = document.getElementById('import-password').value;
    
    let data = { type, password };
    
    if (type === 'mnemonic') {
        data.mnemonic = document.getElementById('mnemonic-input').value;
    } else {
        data.private_key = document.getElementById('private-key-input').value;
    }
    
    const result = await app.apiRequest('/wallet/import', 'POST', data);
    
    if (result.success) {
        localStorage.setItem('walletAddress', result.data.address);
        app.showNotification('Wallet imported successfully!', 'success');
        app.closeModal();
        await app.updateWalletBalance();
    } else {
        app.showNotification('Import failed: ' + result.error, 'error');
    }
}

async function sendTransaction(event) {
    event.preventDefault();
    
    const address = app.getWalletAddress();
    if (!address) {
        app.showNotification('Please create or import a wallet first', 'error');
        return;
    }
    
    const receiver = document.getElementById('receiver-address').value;
    const amount = parseFloat(document.getElementById('send-amount').value);
    const fee = parseFloat(document.getElementById('send-fee').value);
    
    if (!receiver || !amount) {
        app.showNotification('Please fill all required fields', 'error');
        return;
    }
    
    const transaction = {
        sender_address: address,
        receiver_address: receiver,
        amount: amount,
        fee: fee
    };
    
    const result = await app.apiRequest('/transaction/create', 'POST', transaction);
    
    if (result.success) {
        app.showNotification('Transaction sent! TXID: ' + result.data.txid, 'success');
        await app.updateWalletBalance();
        document.getElementById('send-form').reset();
    } else {
        app.showNotification('Transaction failed: ' + result.error, 'error');
    }
}

async function loadWalletInfo() {
    // Load wallet info if exists
    const address = app.getWalletAddress();
    if (address) {
        document.getElementById('wallet-info').innerHTML = `
            <div class="balance-display">
                <div class="balance-item">
                    <span class="label">Wallet Address</span>
                    <span class="value" style="font-size: 14px;">${address}</span>
                </div>
            </div>
            <button onclick="copyAddress()">Copy Address</button>
            <button onclick="showQRCode()">Show QR Code</button>
        `;
    }
}

function copyAddress() {
    const address = app.getWalletAddress();
    navigator.clipboard.writeText(address);
    app.showNotification('Address copied to clipboard', 'success');
}

function showQRCode() {
    // Simple QR code display (in production, use a QR library)
    const address = app.getWalletAddress();
    const content = `
        <h3>Wallet Address QR Code</h3>
        <p>${address}</p>
        <div class="qr-code" style="background: white; padding: 20px; text-align: center;">
            <!-- QR code would be generated here -->
            <p>QR Code: ${address}</p>
        </div>
    `;
    app.showModal(content);
}

// Load wallet info on page load
document.addEventListener('DOMContentLoaded', loadWalletInfo);