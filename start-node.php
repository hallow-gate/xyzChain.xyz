<?php
/**
 * XYZChain Secure - Complete Node Entry Point
 * PHP 7.4 Compatible with Full P2P Networking
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');

define('ROOT_PATH', __DIR__);

// ===== FIXED AUTOLOADER =====
spl_autoload_register(function ($className) {
    $dirs = ['core', 'crypto', 'network', 'mining', 'storage', 'security', 'config', 'utils'];
    
    foreach ($dirs as $dir) {
        $file = ROOT_PATH . '/' . $dir . '/' . $className . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    
    // Also check root directory
    $file = ROOT_PATH . '/' . $className . '.php';
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    
    return false;
});

// Create necessary directories
$requiredDirs = [
    'node',
    'node/blocks',
    'node/wallet',
    'node/mempool',
    'node/peers',
    'node/logs',
    'node/recovery'
];

foreach ($requiredDirs as $dir) {
    $path = ROOT_PATH . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        echo "Created directory: {$path}\n";
    }
}

// Initialize required data files
$dataFiles = [
    'node/blocks/index.dat' => serialize([]),
    'node/mempool/mempool.dat' => gzcompress(serialize([
        'transactions' => [],
        'last_updated' => time(),
        'count' => 0
    ]), 9),
    'node/peers/peers.dat' => serialize([]),
    'node/peers/bans.dat' => serialize([]),
    'node/chain_state.dat' => gzcompress(serialize([
        'balances' => [],
        'transaction_history' => [],
        'last_processed_block' => -1,
        'updated_at' => time()
    ]), 9)
];

foreach ($dataFiles as $file => $defaultContent) {
    $filepath = ROOT_PATH . '/' . $file;
    if (!file_exists($filepath)) {
        file_put_contents($filepath, $defaultContent);
    }
}

// ===== EXPLICITLY REQUIRE ALL CORE FILES =====
require_once ROOT_PATH . '/config/NodeConfig.php';
require_once ROOT_PATH . '/utils/Logger.php';
require_once ROOT_PATH . '/core/Block.php';
require_once ROOT_PATH . '/core/Blockchain.php';
require_once ROOT_PATH . '/core/Transaction.php';
require_once ROOT_PATH . '/core/MerkleTree.php';
require_once ROOT_PATH . '/core/Validator.php';
require_once ROOT_PATH . '/core/ChainState.php';
require_once ROOT_PATH . '/crypto/Hash.php';
require_once ROOT_PATH . '/crypto/ECDSA.php';
require_once ROOT_PATH . '/crypto/AES.php';
require_once ROOT_PATH . '/crypto/Wallet.php';
require_once ROOT_PATH . '/crypto/Mnemonic.php';
require_once ROOT_PATH . '/mining/Miner.php';
require_once ROOT_PATH . '/mining/Difficulty.php';
require_once ROOT_PATH . '/mining/Reward.php';
require_once ROOT_PATH . '/storage/BlockStorage.php';
require_once ROOT_PATH . '/storage/MempoolStorage.php';
require_once ROOT_PATH . '/storage/WalletStorage.php';
require_once ROOT_PATH . '/storage/IntegrityScanner.php';
require_once ROOT_PATH . '/network/PeerManager.php';
require_once ROOT_PATH . '/network/SyncManager.php';
require_once ROOT_PATH . '/network/Broadcast.php';
require_once ROOT_PATH . '/network/WebSocketServer.php';
require_once ROOT_PATH . '/security/AntiReplay.php';
require_once ROOT_PATH . '/security/AntiTamper.php';
require_once ROOT_PATH . '/security/RateLimiter.php';
require_once ROOT_PATH . '/security/PeerFirewall.php';
require_once ROOT_PATH . '/security/ChainRecovery.php';
require_once ROOT_PATH . '/network/NodeServer.php';

// Initialize configuration
NodeConfig::init(ROOT_PATH);
Logger::init();

// Parse command line arguments
$apiPort = 8080;
$p2pPort = null;

if (isset($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--port=') === 0) {
            $apiPort = (int)substr($arg, 7);
            $p2pPort = $apiPort + 1000;
        }
        if (strpos($arg, '--p2p-port=') === 0) {
            $p2pPort = (int)substr($arg, 11);
        }
    }
}

// Start the node server
try {
    $server = new NodeServer($apiPort, $p2pPort);
    $server->start();
} catch (\Exception $e) {
    echo "Error starting node: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}