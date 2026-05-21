<?php
/**
 * Import Wallet from Mnemonic
 */
require_once __DIR__ . '/config/NodeConfig.php';
require_once __DIR__ . '/crypto/Wallet.php';
require_once __DIR__ . '/crypto/Mnemonic.php';
require_once __DIR__ . '/crypto/AES.php';
require_once __DIR__ . '/storage/WalletStorage.php';

NodeConfig::init(__DIR__);

// Get mnemonic from command line or prompt
if (isset($argv[1])) {
    $mnemonic = $argv[1];
} else {
    echo "Enter your 24-word mnemonic phrase: ";
    $mnemonic = trim(fgets(STDIN));
}

// Validate mnemonic
if (!Mnemonic::validate($mnemonic)) {
    echo "❌ Invalid mnemonic phrase!\n";
    echo "Make sure it's exactly 24 words from the BIP39 wordlist.\n";
    exit(1);
}

echo "✅ Mnemonic is valid\n";

// Get password
if (isset($argv[2])) {
    $password = $argv[2];
} else {
    echo "Enter a password to encrypt this wallet: ";
    $password = trim(fgets(STDIN));
}

if (strlen($password) < 8) {
    echo "❌ Password must be at least 8 characters\n";
    exit(1);
}

// Import wallet
try {
    $walletData = Wallet::importFromMnemonic($mnemonic);
    
    echo "\n";
    echo "═══════════════════════════════════\n";
    echo "  Wallet Imported Successfully!\n";
    echo "═══════════════════════════════════\n";
    echo "\n";
    echo "  Address: {$walletData['address']}\n";
    echo "  Public Key: " . substr($walletData['public_key'], 0, 50) . "...\n";
    echo "\n";
    
    // Encrypt and save
    $aes = new AES();
    $encryptedPrivateKey = $aes->encrypt($walletData['private_key'], $password);
    
    $walletFile = [
        'address' => $walletData['address'],
        'public_key' => $walletData['public_key'],
        'encrypted_private_key' => $encryptedPrivateKey,
        'creation_time' => time(),
        'imported' => true,
        'imported_at' => date('Y-m-d H:i:s')
    ];
    
    if (WalletStorage::save($walletFile)) {
        echo "  ✅ Wallet saved to node/wallet/wallet.dat\n";
        echo "  ✅ Your wallet is now ready to use\n";
    }
    
    echo "\n";
    echo "  To check balance:\n";
    echo "  curl \"http://localhost:8080/wallet/balance?address={$walletData['address']}\"\n";
    echo "\n";
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}