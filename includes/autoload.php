<?php
declare(strict_types=1);

spl_autoload_register(function (string $className) {
    // Map of class names to file paths
    $classMap = [
        // Core
        'Block' => '/core/Block.php',
        'Blockchain' => '/core/Blockchain.php',
        'Transaction' => '/core/Transaction.php',
        'MerkleTree' => '/core/MerkleTree.php',
        'Validator' => '/core/Validator.php',
        'ChainState' => '/core/ChainState.php',
        
        // Crypto
        'Hash' => '/crypto/Hash.php',
        'ECDSA' => '/crypto/ECDSA.php',
        'AES' => '/crypto/AES.php',
        'Wallet' => '/crypto/Wallet.php',
        'Mnemonic' => '/crypto/Mnemonic.php',
        
        // Network
        'NodeServer' => '/network/NodeServer.php',
        'PeerManager' => '/network/PeerManager.php',
        'SyncManager' => '/network/SyncManager.php',
        'Broadcast' => '/network/Broadcast.php',
        'WebSocketServer' => '/network/WebSocketServer.php',
        
        // Mining
        'Miner' => '/mining/Miner.php',
        'Difficulty' => '/mining/Difficulty.php',
        'Reward' => '/mining/Reward.php',
        
        // Storage
        'BlockStorage' => '/storage/BlockStorage.php',
        'MempoolStorage' => '/storage/MempoolStorage.php',
        'WalletStorage' => '/storage/WalletStorage.php',
        
        // Security
        'AntiReplay' => '/security/AntiReplay.php',
        'AntiTamper' => '/security/AntiTamper.php',
        'RateLimiter' => '/security/RateLimiter.php',
        'PeerFirewall' => '/security/PeerFirewall.php',
        'ChainRecovery' => '/security/ChainRecovery.php',
        
        // Config/Utils
        'NodeConfig' => '/config/NodeConfig.php',
        'Logger' => '/utils/Logger.php'
    ];
    
    if (isset($classMap[$className])) {
        $file = ROOT_PATH . $classMap[$className];
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
    
    // Search in directories
    $dirs = ['core', 'crypto', 'network', 'mining', 'storage', 'security', 'config', 'utils'];
    foreach ($dirs as $dir) {
        $file = ROOT_PATH . '/' . $dir . '/' . $className . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});