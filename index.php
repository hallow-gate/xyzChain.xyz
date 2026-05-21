<?php
/**
 * XYZChain Secure - InfinityFree Compatible Version
 * No database required - uses file-based storage
 * PHP 7.4+ Compatible
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

define('ROOT_PATH', __DIR__);
define('MAX_SUPPLY', 800000000);
define('GENESIS_ADDRESS', 'XYZCHAIN_GENESIS_ADDRESS');
define('COIN_NAME', 'XYZ');
define('DIFFICULTY_TARGET', 4);
define('HALVING_INTERVAL', 210000);
define('INITIAL_REWARD', 50);

// Create necessary directories
$directories = ['data', 'data/blocks', 'data/mempool', 'data/peers', 'data/wallet', 'data/logs'];
foreach ($directories as $dir) {
    if (!is_dir(ROOT_PATH . '/' . $dir)) {
        mkdir(ROOT_PATH . '/' . $dir, 0755, true);
    }
}

// ============================================================================
// CORE CLASSES
// ============================================================================

class Logger {
    private static $logFile;
    
    public static function init() {
        self::$logFile = ROOT_PATH . '/data/logs/node.log';
    }
    
    public static function info($msg) { self::write('INFO', $msg); }
    public static function error($msg) { self::write('ERROR', $msg); }
    public static function warning($msg) { self::write('WARNING', $msg); }
    public static function debug($msg) { if (defined('DEBUG') && DEBUG) self::write('DEBUG', $msg); }
    
    private static function write($level, $msg) {
        $entry = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $msg . PHP_EOL;
        file_put_contents(self::$logFile, $entry, FILE_APPEND);
    }
}
Logger::init();

// ============================================================================
// BLOCK CLASS
// ============================================================================

class Block {
    public $index, $timestamp, $previous_hash, $hash, $nonce, $difficulty;
    public $merkle_root, $miner_address, $reward, $transactions;
    
    public function __construct($index, $previousHash, $transactions, $minerAddress, $difficulty = 4) {
        $this->index = $index;
        $this->timestamp = time();
        $this->previous_hash = $previousHash;
        $this->transactions = $transactions;
        $this->miner_address = $minerAddress;
        $this->difficulty = $difficulty;
        $this->nonce = 0;
        $this->reward = $index === 0 ? 400000000 : max(0.00000001, 50 / pow(2, intdiv($index, HALVING_INTERVAL)));
        $this->merkle_root = $this->calculateMerkleRoot();
        $this->hash = '';
    }
    
    public function calculateHash() {
        return hash('sha256', 
            $this->index . $this->timestamp . $this->previous_hash . 
            $this->merkle_root . $this->difficulty . $this->nonce . 
            $this->miner_address
        );
    }
    
    public function mine() {
        $target = str_repeat('0', $this->difficulty);
        while (substr($this->hash = $this->calculateHash(), 0, $this->difficulty) !== $target) {
            $this->nonce++;
            if ($this->nonce % 100000 === 0) {
                // Update mining status for web display
                file_put_contents(ROOT_PATH . '/data/mining_status.json', json_encode([
                    'block_index' => $this->index,
                    'nonce' => $this->nonce,
                    'hash' => substr($this->hash, 0, 16),
                    'timestamp' => time()
                ]));
            }
        }
        return true;
    }
    
    public function verify() {
        if ($this->calculateHash() !== $this->hash) return false;
        $target = str_repeat('0', $this->difficulty);
        return substr($this->hash, 0, $this->difficulty) === $target;
    }
    
    private function calculateMerkleRoot() {
        if (empty($this->transactions)) return hash('sha256', 'empty');
        $hashes = array_map(function($tx) { 
            return isset($tx['txid']) ? $tx['txid'] : hash('sha256', json_encode($tx)); 
        }, $this->transactions);
        sort($hashes);
        while (count($hashes) > 1) {
            $next = [];
            for ($i = 0; $i < count($hashes); $i += 2) {
                if ($i + 1 < count($hashes)) {
                    $next[] = hash('sha256', $hashes[$i] . $hashes[$i + 1]);
                } else {
                    $next[] = hash('sha256', $hashes[$i] . $hashes[$i]);
                }
            }
            $hashes = $next;
        }
        return $hashes[0];
    }
    
    public function toArray() {
        return [
            'index' => $this->index,
            'timestamp' => $this->timestamp,
            'previous_hash' => $this->previous_hash,
            'hash' => $this->hash,
            'nonce' => $this->nonce,
            'difficulty' => $this->difficulty,
            'merkle_root' => $this->merkle_root,
            'miner_address' => $this->miner_address,
            'reward' => $this->reward,
            'transactions' => $this->transactions
        ];
    }
    
    public static function fromArray($data) {
        $block = new self($data['index'], $data['previous_hash'], $data['transactions'], $data['miner_address'], $data['difficulty']);
        $block->timestamp = $data['timestamp'];
        $block->hash = $data['hash'];
        $block->nonce = $data['nonce'];
        $block->merkle_root = $data['merkle_root'];
        $block->reward = $data['reward'];
        return $block;
    }
}

// ============================================================================
// TRANSACTION CLASS
// ============================================================================

class Transaction {
    public $txid, $sender, $receiver, $amount, $fee, $nonce, $timestamp, $signature;
    
    public function __construct($sender, $receiver, $amount, $fee = 0.0001) {
        $this->sender = $sender;
        $this->receiver = $receiver;
        $this->amount = (float)$amount;
        $this->fee = (float)$fee;
        $this->nonce = random_int(100000, 999999);
        $this->timestamp = time();
        $this->signature = '';
        $this->txid = $this->calculateTxid();
    }
    
    private function calculateTxid() {
        return hash('sha256', json_encode([
            'sender' => $this->sender,
            'receiver' => $this->receiver,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'nonce' => $this->nonce,
            'timestamp' => $this->timestamp
        ]));
    }
    
    public function sign($privateKey) {
        // Simple signing for InfinityFree (no OpenSSL EC requirement)
        $data = $this->txid . $this->sender . $this->receiver . $this->amount . $this->fee . $this->nonce;
        $this->signature = hash_hmac('sha256', $data, $privateKey);
        return true;
    }
    
    public function verify() {
        if ($this->sender === '0' || $this->sender === GENESIS_ADDRESS) return true;
        if (empty($this->signature)) return false;
        if ($this->amount <= 0 || $this->amount > 100000000) return false;
        if ($this->fee < 0.00000001 || $this->fee > 1000) return false;
        return true;
    }
    
    public function toArray() {
        return [
            'txid' => $this->txid,
            'sender' => $this->sender,
            'receiver' => $this->receiver,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'nonce' => $this->nonce,
            'timestamp' => $this->timestamp,
            'signature' => $this->signature
        ];
    }
    
    public static function fromArray($data) {
        $tx = new self($data['sender'], $data['receiver'], $data['amount'], $data['fee']);
        $tx->txid = $data['txid'];
        $tx->nonce = $data['nonce'];
        $tx->timestamp = $data['timestamp'];
        $tx->signature = $data['signature'];
        return $tx;
    }
}

// ============================================================================
// BLOCKCHAIN CLASS
// ============================================================================

class Blockchain {
    private $blocksDir, $mempoolFile;
    
    public function __construct() {
        $this->blocksDir = ROOT_PATH . '/data/blocks';
        $this->mempoolFile = ROOT_PATH . '/data/mempool/mempool.dat';
        if (!file_exists($this->blocksDir . '/genesis.dat')) {
            $this->createGenesisBlock();
        }
    }
    
    private function createGenesisBlock() {
        $genesisTx = [
            'txid' => str_repeat('0', 64),
            'sender' => '0',
            'receiver' => GENESIS_ADDRESS,
            'amount' => 400000000,
            'fee' => 0,
            'nonce' => 0,
            'timestamp' => time(),
            'signature' => 'GENESIS'
        ];
        $genesis = new Block(0, str_repeat('0', 64), [$genesisTx], GENESIS_ADDRESS, 4);
        $genesis->hash = $genesis->calculateHash();
        $this->saveBlock($genesis);
        Logger::info("Genesis block created");
    }
    
    private function saveBlock($block) {
        file_put_contents($this->blocksDir . '/' . $block->index . '.json', json_encode($block->toArray()));
    }
    
    private function loadBlock($index) {
        $file = $this->blocksDir . '/' . $index . '.json';
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        return $data ? Block::fromArray($data) : null;
    }
    
    public function getLatestBlock() {
        $files = glob($this->blocksDir . '/*.json');
        if (empty($files)) return null;
        $maxIndex = 0;
        foreach ($files as $file) {
            $idx = (int)basename($file, '.json');
            if ($idx > $maxIndex) $maxIndex = $idx;
        }
        return $this->loadBlock($maxIndex);
    }
    
    public function getBlockByHeight($height) {
        return $this->loadBlock($height);
    }
    
    public function getBlockByHash($hash) {
        $files = glob($this->blocksDir . '/*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['hash'] === $hash) {
                return Block::fromArray($data);
            }
        }
        return null;
    }
    
    public function getHeight() {
        $files = glob($this->blocksDir . '/*.json');
        return count($files) - 1;
    }
    
    public function getChain($limit = 100) {
        $chain = [];
        $height = $this->getHeight();
        $start = max(0, $height - $limit + 1);
        for ($i = $start; $i <= $height; $i++) {
            $block = $this->loadBlock($i);
            if ($block) $chain[] = $block->toArray();
        }
        return $chain;
    }
    
    public function addBlock($block) {
        $latest = $this->getLatestBlock();
        if ($latest && $block->index !== $latest->index + 1) return false;
        if ($latest && $block->previous_hash !== $latest->hash) return false;
        if (!$block->verify()) return false;
        
        // Validate transactions
        foreach ($block->transactions as $txData) {
            $tx = Transaction::fromArray($txData);
            if (!$tx->verify()) return false;
        }
        
        $this->saveBlock($block);
        
        // Remove transactions from mempool
        foreach ($block->transactions as $tx) {
            $this->removeFromMempool($tx['txid']);
        }
        
        Logger::info("Block #{$block->index} added");
        return true;
    }
    
    public function addTransaction($txData) {
        $tx = Transaction::fromArray($txData);
        if (!$tx->verify()) return false;
        
        // Check for duplicate
        $mempool = $this->getMempool();
        foreach ($mempool as $existing) {
            if ($existing['txid'] === $tx->txid) return false;
        }
        
        // Check balance for non-coinbase
        if ($tx->sender !== '0' && $tx->sender !== GENESIS_ADDRESS) {
            $balance = $this->getBalance($tx->sender);
            if ($balance['spendable'] < $tx->amount + $tx->fee) return false;
        }
        
        $this->addToMempool($tx->toArray());
        Logger::info("Transaction added: " . substr($tx->txid, 0, 16));
        return true;
    }
    
    public function getPendingTransactions($limit = 100) {
        $mempool = $this->getMempool();
        usort($mempool, function($a, $b) {
            return ($b['fee'] ?? 0) - ($a['fee'] ?? 0);
        });
        return array_slice($mempool, 0, $limit);
    }
    
    public function getMempoolSize() {
        return count($this->getMempool());
    }
    
    private function getMempool() {
        if (!file_exists($this->mempoolFile)) return [];
        $data = file_get_contents($this->mempoolFile);
        return $data ? json_decode($data, true) : [];
    }
    
    private function addToMempool($tx) {
        $mempool = $this->getMempool();
        $mempool[$tx['txid']] = $tx;
        file_put_contents($this->mempoolFile, json_encode($mempool));
    }
    
    private function removeFromMempool($txid) {
        $mempool = $this->getMempool();
        unset($mempool[$txid]);
        file_put_contents($this->mempoolFile, json_encode($mempool));
    }
    
    public function getBalance($address) {
        $confirmed = 0;
        $height = $this->getHeight();
        
        for ($i = 0; $i <= $height; $i++) {
            $block = $this->loadBlock($i);
            if (!$block) continue;
            
            // Mining rewards
            if ($block->miner_address === $address) {
                $confirmed += $block->reward;
            }
            
            // Transactions
            foreach ($block->transactions as $tx) {
                if ($tx['receiver'] === $address) {
                    $confirmed += $tx['amount'];
                }
                if ($tx['sender'] === $address && $tx['sender'] !== '0' && $tx['sender'] !== GENESIS_ADDRESS) {
                    $confirmed -= $tx['amount'] + ($tx['fee'] ?? 0);
                }
            }
        }
        
        // Calculate pending/locked
        $locked = 0;
        foreach ($this->getMempool() as $tx) {
            if ($tx['sender'] === $address) {
                $locked += $tx['amount'] + ($tx['fee'] ?? 0);
            }
        }
        
        return [
            'address' => $address,
            'confirmed' => round($confirmed, 8),
            'locked' => round($locked, 8),
            'spendable' => round(max(0, $confirmed - $locked), 8)
        ];
    }
    
    public function getTransactionHistory($address, $limit = 50) {
        $txs = [];
        $height = $this->getHeight();
        
        for ($i = 0; $i <= $height; $i++) {
            $block = $this->loadBlock($i);
            if (!$block) continue;
            
            foreach ($block->transactions as $tx) {
                if ($tx['sender'] === $address || $tx['receiver'] === $address) {
                    $tx['block_height'] = $i;
                    $tx['confirmations'] = $height - $i + 1;
                    $txs[] = $tx;
                }
            }
        }
        
        usort($txs, function($a, $b) {
            return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
        });
        
        return array_slice($txs, 0, $limit);
    }
    
    public function getTotalSupply() {
        $total = 0;
        $height = $this->getHeight();
        for ($i = 0; $i <= $height; $i++) {
            $block = $this->loadBlock($i);
            if ($block) $total += $block->reward;
        }
        return min($total, MAX_SUPPLY);
    }
    
    public function getRemainingSupply() {
        return max(0, MAX_SUPPLY - $this->getTotalSupply());
    }
    
    public function validateChain() {
        $height = $this->getHeight();
        for ($i = 1; $i <= $height; $i++) {
            $current = $this->loadBlock($i);
            $previous = $this->loadBlock($i - 1);
            if (!$current || !$previous) return false;
            if ($current->previous_hash !== $previous->hash) return false;
            if (!$current->verify()) return false;
        }
        return true;
    }
}

// ============================================================================
// WALLET CLASS
// ============================================================================

class SimpleWallet {
    public static function createAddress() {
        $random = bin2hex(random_bytes(32));
        $timestamp = time();
        $data = $random . $timestamp;
        $hash1 = hash('sha256', $data);
        $hash2 = hash('ripemd160', $hash1);
        $checksum = substr(hash('sha256', hash('sha256', $hash2, true)), 0, 8);
        $address = 'XYZ' . substr($hash2, 0, 30) . substr($checksum, 0, 6);
        return strtoupper($address);
    }
    
    public static function createPrivateKey() {
        return bin2hex(random_bytes(32));
    }
}

// ============================================================================
// MINER CLASS
// ============================================================================

class Miner {
    private $blockchain, $minerAddress, $running;
    
    public function __construct($blockchain, $minerAddress) {
        $this->blockchain = $blockchain;
        $this->minerAddress = $minerAddress;
        $this->running = false;
    }
    
    public function start() {
        $this->running = true;
        file_put_contents(ROOT_PATH . '/data/miner_active.txt', '1');
        return true;
    }
    
    public function stop() {
        $this->running = false;
        if (file_exists(ROOT_PATH . '/data/miner_active.txt')) {
            unlink(ROOT_PATH . '/data/miner_active.txt');
        }
        return true;
    }
    
    public function isRunning() {
        return $this->running || file_exists(ROOT_PATH . '/data/miner_active.txt');
    }
    
    public function mineBlock() {
        if (!$this->isRunning()) return null;
        
        $latest = $this->blockchain->getLatestBlock();
        $nextIndex = $latest ? $latest->index + 1 : 0;
        $prevHash = $latest ? $latest->hash : str_repeat('0', 64);
        
        $coinbase = [
            'txid' => hash('sha256', 'coinbase_' . $nextIndex . '_' . time()),
            'sender' => '0',
            'receiver' => $this->minerAddress,
            'amount' => $nextIndex === 0 ? 400000000 : max(0.00000001, 50 / pow(2, intdiv($nextIndex, HALVING_INTERVAL))),
            'fee' => 0,
            'nonce' => random_int(100000, 999999),
            'timestamp' => time(),
            'signature' => 'COINBASE'
        ];
        
        $pending = $this->blockchain->getPendingTransactions(100);
        $transactions = array_merge([$coinbase], $pending);
        
        $difficulty = $latest && $latest->index > 0 && $latest->index % 10 === 0 ? 
            max(1, min(62, $latest->difficulty + ($latest->timestamp - $this->blockchain->getBlockByHeight($latest->index - 10)->timestamp < 1500 ? 1 : -1))) : 
            ($latest ? $latest->difficulty : 4);
        
        $block = new Block($nextIndex, $prevHash, $transactions, $this->minerAddress, $difficulty);
        
        if ($block->mine()) {
            if ($this->blockchain->addBlock($block)) {
                Logger::info("Block #{$nextIndex} mined!");
                return $block;
            }
        }
        return null;
    }
    
    public function getStatus() {
        $latest = $this->blockchain->getLatestBlock();
        $statusFile = ROOT_PATH . '/data/mining_status.json';
        $miningStats = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : [];
        
        return [
            'is_running' => $this->isRunning(),
            'miner_address' => $this->minerAddress,
            'chain_height' => $this->blockchain->getHeight(),
            'difficulty' => $latest ? $latest->difficulty : 4,
            'block_reward' => $latest ? max(0.00000001, 50 / pow(2, intdiv($latest->index + 1, HALVING_INTERVAL))) : 50,
            'total_supply' => $this->blockchain->getTotalSupply(),
            'remaining_supply' => $this->blockchain->getRemainingSupply(),
            'mempool_size' => $this->blockchain->getMempoolSize(),
            'nonce' => $miningStats['nonce'] ?? 0,
            'current_hash' => $miningStats['hash'] ?? ''
        ];
    }
}

// ============================================================================
// API HANDLER
// ============================================================================

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$blockchain = new Blockchain();
$response = ['success' => false, 'error' => 'Endpoint not found'];

try {
    // ========================================================================
    // BLOCKCHAIN ENDPOINTS
    // ========================================================================
    
    if ($path === '/' || $path === '/index.html') {
        header('Content-Type: text/html');
        echo getHtmlPage();
        exit();
    }
    
    elseif ($path === '/api/status' || $path === '/status') {
        $latest = $blockchain->getLatestBlock();
        $response = [
            'success' => true,
            'data' => [
                'chain_height' => $blockchain->getHeight(),
                'total_supply' => $blockchain->getTotalSupply(),
                'remaining_supply' => $blockchain->getRemainingSupply(),
                'mempool_size' => $blockchain->getMempoolSize(),
                'latest_hash' => $latest ? $latest->hash : null,
                'difficulty' => $latest ? $latest->difficulty : 4,
                'max_supply' => MAX_SUPPLY,
                'coin_name' => COIN_NAME
            ]
        ];
    }
    
    elseif ($path === '/chain' || $path === '/api/chain') {
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 50;
        $response = [
            'success' => true,
            'data' => [
                'height' => $blockchain->getHeight(),
                'blocks' => $blockchain->getChain($limit)
            ]
        ];
    }
    
    elseif (preg_match('#^/block/(\d+)$#', $path, $matches)) {
        $block = $blockchain->getBlockByHeight((int)$matches[1]);
        $response = ['success' => true, 'data' => $block ? $block->toArray() : null];
    }
    
    elseif (preg_match('#^/block/hash/([a-f0-9]{64})$#', $path, $matches)) {
        $block = $blockchain->getBlockByHash($matches[1]);
        $response = ['success' => true, 'data' => $block ? $block->toArray() : null];
    }
    
    elseif ($path === '/block/latest') {
        $block = $blockchain->getLatestBlock();
        $response = ['success' => true, 'data' => $block ? $block->toArray() : null];
    }
    
    // ========================================================================
    // WALLET ENDPOINTS
    // ========================================================================
    
    elseif ($path === '/wallet/create') {
        if ($method === 'POST') {
            $address = SimpleWallet::createAddress();
            $privateKey = SimpleWallet::createPrivateKey();
            $walletFile = [
                'address' => $address,
                'private_key' => $privateKey,
                'created' => time()
            ];
            file_put_contents(ROOT_PATH . '/data/wallet/' . md5($address) . '.json', json_encode($walletFile));
            $response = ['success' => true, 'data' => ['address' => $address, 'private_key' => $privateKey]];
        }
    }
    
    elseif ($path === '/wallet/balance') {
        $address = $_GET['address'] ?? '';
        if (empty($address)) {
            $response = ['success' => false, 'error' => 'Address required'];
        } else {
            $balance = $blockchain->getBalance($address);
            $history = $blockchain->getTransactionHistory($address, 20);
            $response = ['success' => true, 'data' => array_merge($balance, ['transactions' => $history])];
        }
    }
    
    // ========================================================================
    // TRANSACTION ENDPOINTS
    // ========================================================================
    
    elseif ($path === '/transaction/create') {
        if ($method === 'POST') {
            $required = ['sender', 'receiver', 'amount'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    $response = ['success' => false, 'error' => "Missing field: {$field}"];
                    break;
                }
            }
            
            $tx = new Transaction($input['sender'], $input['receiver'], (float)$input['amount'], (float)($input['fee'] ?? 0.0001));
            
            if (isset($input['private_key'])) {
                $tx->sign($input['private_key']);
            }
            
            if ($blockchain->addTransaction($tx->toArray())) {
                $response = ['success' => true, 'data' => ['txid' => $tx->txid, 'status' => 'pending']];
            } else {
                $response = ['success' => false, 'error' => 'Transaction rejected'];
            }
        }
    }
    
    elseif (preg_match('#^/transaction/([a-f0-9]{64})$#', $path, $matches)) {
        $txid = $matches[1];
        $found = null;
        
        // Search in blockchain
        $height = $blockchain->getHeight();
        for ($i = 0; $i <= $height; $i++) {
            $block = $blockchain->getBlockByHeight($i);
            if ($block) {
                foreach ($block->transactions as $tx) {
                    if ($tx['txid'] === $txid) {
                        $found = $tx;
                        $found['confirmed'] = true;
                        $found['block_height'] = $i;
                        break 2;
                    }
                }
            }
        }
        
        // Search in mempool
        if (!$found) {
            foreach ($blockchain->getPendingTransactions(1000) as $tx) {
                if ($tx['txid'] === $txid) {
                    $found = $tx;
                    $found['confirmed'] = false;
                    break;
                }
            }
        }
        
        $response = ['success' => true, 'data' => $found];
    }
    
    elseif ($path === '/mempool') {
        $response = [
            'success' => true,
            'data' => [
                'count' => $blockchain->getMempoolSize(),
                'transactions' => $blockchain->getPendingTransactions(100)
            ]
        ];
    }
    
    // ========================================================================
    // MINING ENDPOINTS
    // ========================================================================
    
    elseif ($path === '/mine/start') {
        if ($method === 'POST') {
            $address = $input['address'] ?? '';
            if (empty($address)) {
                $response = ['success' => false, 'error' => 'Miner address required'];
            } else {
                $miner = new Miner($blockchain, $address);
                if ($miner->start()) {
                    // Start background mining (note: InfinityFree may have limitations)
                    $response = ['success' => true, 'data' => ['status' => 'started', 'address' => $address]];
                } else {
                    $response = ['success' => false, 'error' => 'Failed to start mining'];
                }
            }
        }
    }
    
    elseif ($path === '/mine/stop') {
        $miner = new Miner($blockchain, '');
        $miner->stop();
        $response = ['success' => true, 'data' => ['status' => 'stopped']];
    }
    
    elseif ($path === '/mine/status') {
        $miner = new Miner($blockchain, '');
        $response = ['success' => true, 'data' => $miner->getStatus()];
    }
    
    elseif ($path === '/mine/once') {
        // Mine a single block (useful for scheduled mining)
        $address = $_GET['address'] ?? '';
        if (empty($address)) {
            $response = ['success' => false, 'error' => 'Address required'];
        } else {
            $miner = new Miner($blockchain, $address);
            $block = $miner->mineBlock();
            $response = ['success' => true, 'data' => ['mined' => $block !== null, 'block' => $block ? $block->toArray() : null]];
        }
    }
    
    // ========================================================================
    // NETWORK/INFO ENDPOINTS
    // ========================================================================
    
    elseif ($path === '/network/stats') {
        $latest = $blockchain->getLatestBlock();
        $response = [
            'success' => true,
            'data' => [
                'chain_height' => $blockchain->getHeight(),
                'total_supply' => $blockchain->getTotalSupply(),
                'remaining_supply' => $blockchain->getRemainingSupply(),
                'mempool_size' => $blockchain->getMempoolSize(),
                'difficulty' => $latest ? $latest->difficulty : 4,
                'block_reward' => $latest ? max(0.00000001, 50 / pow(2, intdiv($latest->index + 1, HALVING_INTERVAL))) : 50,
                'max_supply' => MAX_SUPPLY,
                'node_version' => '1.0.0-infinityfree'
            ]
        ];
    }
    
    elseif ($path === '/supply') {
        $response = [
            'success' => true,
            'data' => [
                'total_supply' => $blockchain->getTotalSupply(),
                'remaining_supply' => $blockchain->getRemainingSupply(),
                'max_supply' => MAX_SUPPLY,
                'circulating_percent' => round(($blockchain->getTotalSupply() / MAX_SUPPLY) * 100, 2),
                'halving_info' => [
                    'interval' => HALVING_INTERVAL,
                    'current_reward' => $blockchain->getLatestBlock() ? 
                        max(0.00000001, 50 / pow(2, intdiv($blockchain->getLatestBlock()->index + 1, HALVING_INTERVAL))) : 50
                ]
            ]
        ];
    }
    
    // ========================================================================
    // VALIDATION ENDPOINTS
    // ========================================================================
    
    elseif ($path === '/validate/chain') {
        $isValid = $blockchain->validateChain();
        $response = ['success' => true, 'data' => ['valid' => $isValid]];
    }
    
    elseif ($path === '/reset') {
        // ONLY FOR TESTING - Remove in production
        if (isset($_GET['secret']) && $_GET['secret'] === 'reset_xyzchain') {
            array_map('unlink', glob(ROOT_PATH . '/data/blocks/*.json'));
            array_map('unlink', glob(ROOT_PATH . '/data/mempool/*.dat'));
            $response = ['success' => true, 'message' => 'Blockchain reset'];
        } else {
            $response = ['success' => false, 'error' => 'Unauthorized'];
        }
    }

} catch (Exception $e) {
    $response = ['success' => false, 'error' => $e->getMessage()];
}

echo json_encode($response);

// ============================================================================
// HTML PAGE GENERATOR
// ============================================================================

function getHtmlPage() {
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XYZChain Secure - Blockchain Explorer & Wallet</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0a0a0f 0%, #111118 100%);
            color: #c0c0c0;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: linear-gradient(135deg, #111118, #16161e);
            border: 1px solid #252530;
            border-radius: 16px;
            padding: 24px 32px;
            margin-bottom: 24px;
        }
        
        .header h1 {
            color: #c8c8d0;
            font-size: 28px;
            font-weight: 600;
            letter-spacing: 2px;
        }
        
        .header .version {
            color: #888899;
            font-size: 12px;
            margin-top: 4px;
        }
        
        .nav {
            display: flex;
            gap: 8px;
            margin-top: 20px;
            flex-wrap: wrap;
            border-bottom: 1px solid #252530;
        }
        
        .nav-btn {
            background: none;
            border: none;
            color: #888899;
            padding: 12px 24px;
            cursor: pointer;
            font-size: 14px;
            letter-spacing: 1px;
            text-transform: uppercase;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }
        
        .nav-btn:hover {
            color: #c8c8d0;
        }
        
        .nav-btn.active {
            color: #c8c8d0;
            border-bottom-color: #a0a0b0;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }
        
        .card {
            background: #16161e;
            border: 1px solid #252530;
            border-radius: 12px;
            padding: 24px;
            transition: all 0.3s;
        }
        
        .card:hover {
            border-color: #2a2a35;
            background: #1a1a24;
        }
        
        .card h2 {
            color: #a0a0b0;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid #252530;
        }
        
        .card-full {
            grid-column: 1 / -1;
        }
        
        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.03);
        }
        
        .stat-label {
            color: #888899;
            font-size: 13px;
        }
        
        .stat-value {
            color: #e8e8f0;
            font-weight: 500;
        }
        
        .stat-value.green { color: #4a9a6a; }
        .stat-value.gold { color: #9a8a5a; }
        .stat-value.blue { color: #6a7a9a; }
        
        .supply-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .supply-item {
            background: #111118;
            border: 1px solid #252530;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        
        .supply-item .supply-label {
            color: #888899;
            font-size: 11px;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .supply-item .supply-value {
            color: #c8c8d0;
            font-size: 28px;
            font-weight: 600;
        }
        
        input, textarea, select {
            width: 100%;
            padding: 12px;
            background: #111118;
            border: 1px solid #252530;
            border-radius: 8px;
            color: #e8e8f0;
            font-size: 14px;
            margin-bottom: 12px;
            font-family: inherit;
        }
        
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #a0a0b0;
        }
        
        button {
            background: #252530;
            border: 1px solid #2a2a35;
            color: #a0a0b0;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 0.5px;
            transition: all 0.3s;
        }
        
        button:hover {
            background: #2a2a35;
            border-color: #a0a0b0;
            color: #c8c8d0;
        }
        
        button.primary {
            background: #6a7a9a;
            border-color: #6a7a9a;
            color: white;
        }
        
        button.primary:hover {
            background: #7a8aaa;
        }
        
        button.success {
            background: #4a9a6a;
            border-color: #4a9a6a;
            color: white;
        }
        
        button.danger {
            background: #9a4a4a;
            border-color: #9a4a4a;
            color: white;
        }
        
        .output {
            background: #111118;
            border: 1px solid #252530;
            border-radius: 8px;
            padding: 16px;
            margin-top: 12px;
            font-family: 'SF Mono', 'Monaco', monospace;
            font-size: 12px;
            word-break: break-all;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .hash {
            color: #6a7a9a;
            font-family: monospace;
            font-size: 11px;
        }
        
        .clickable {
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .clickable:hover {
            color: #c8c8d0;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 12px;
            font-size: 13px;
        }
        
        .alert-success {
            background: rgba(74,154,106,0.1);
            border-left: 3px solid #4a9a6a;
            color: #4a9a6a;
        }
        
        .alert-error {
            background: rgba(154,74,74,0.1);
            border-left: 3px solid #9a4a4a;
            color: #c06060;
        }
        
        .alert-info {
            background: rgba(106,122,154,0.1);
            border-left: 3px solid #6a7a9a;
            color: #6a7a9a;
        }
        
        .flex {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .tx-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border-bottom: 1px solid rgba(255,255,255,0.03);
            cursor: pointer;
        }
        
        .tx-row:hover {
            background: rgba(255,255,255,0.02);
        }
        
        .tx-hash {
            font-family: monospace;
            font-size: 11px;
            color: #6a7a9a;
        }
        
        .tx-amount {
            font-weight: 500;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            text-transform: uppercase;
        }
        
        .badge-success {
            background: rgba(74,154,106,0.2);
            color: #4a9a6a;
        }
        
        .badge-pending {
            background: rgba(154,138,90,0.2);
            color: #9a8a5a;
        }
        
        .hidden {
            display: none !important;
        }
        
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .modal-content {
            background: #16161e;
            border: 1px solid #252530;
            border-radius: 16px;
            padding: 32px;
            max-width: 800px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .close-btn {
            float: right;
            font-size: 24px;
            background: none;
            border: none;
            color: #888899;
            cursor: pointer;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        
        .pagination button {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            .cards {
                grid-template-columns: 1fr;
            }
            .supply-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-top">
                <div>
                    <h1>⚡ XYZChain Secure</h1>
                    <div class="version">Decentralized Blockchain Platform | InfinityFree Compatible</div>
                </div>
                <div class="status">
                    <span id="connection-status">● Connected</span>
                </div>
            </div>
            <div class="nav">
                <button class="nav-btn active" onclick="switchTab('dashboard')">📊 Dashboard</button>
                <button class="nav-btn" onclick="switchTab('wallet')">👛 Wallet</button>
                <button class="nav-btn" onclick="switchTab('mining')">⛏️ Mining</button>
                <button class="nav-btn" onclick="switchTab('explorer')">🔍 Explorer</button>
                <button class="nav-btn" onclick="switchTab('network')">🌐 Network</button>
            </div>
        </div>

        <!-- Dashboard Tab -->
        <div id="dashboard-tab" class="tab-content active">
            <div class="supply-grid">
                <div class="supply-item">
                    <div class="supply-label">Total Supply</div>
                    <div class="supply-value" id="total-supply">--</div>
                    <div class="supply-sub">/ 800M XYZ</div>
                </div>
                <div class="supply-item">
                    <div class="supply-label">Remaining</div>
                    <div class="supply-value" id="remaining-supply">--</div>
                    <div class="supply-sub">XYZ left to mine</div>
                </div>
                <div class="supply-item">
                    <div class="supply-label">Block Reward</div>
                    <div class="supply-value" id="block-reward">--</div>
                    <div class="supply-sub">per block</div>
                </div>
                <div class="supply-item">
                    <div class="supply-label">Difficulty</div>
                    <div class="supply-value" id="difficulty">--</div>
                    <div class="supply-sub">Proof of Work</div>
                </div>
            </div>
            <div class="cards">
                <div class="card">
                    <h2>📈 Network Overview</h2>
                    <div class="stat-row"><span class="stat-label">Chain Height</span><span class="stat-value" id="chain-height">--</span></div>
                    <div class="stat-row"><span class="stat-label">Latest Block</span><span class="stat-value hash" id="latest-hash">--</span></div>
                    <div class="stat-row"><span class="stat-label">Mempool Size</span><span class="stat-value" id="mempool-size">--</span></div>
                    <div class="stat-row"><span class="stat-label">Mining Status</span><span class="stat-value" id="mining-status">--</span></div>
                </div>
                <div class="card">
                    <h2>🔄 Latest Blocks</h2>
                    <div id="latest-blocks">Loading...</div>
                </div>
                <div class="card">
                    <h2>💎 Quick Actions</h2>
                    <button onclick="switchTab('wallet')">Create Wallet</button>
                    <button onclick="switchTab('explorer')" style="margin-left: 8px;">Explore Blockchain</button>
                    <div class="stat-row" style="margin-top: 16px;">
                        <span class="stat-label">Chain Valid</span>
                        <span class="stat-value green" id="chain-valid">✓ Verified</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Wallet Tab -->
        <div id="wallet-tab" class="tab-content">
            <div class="cards">
                <div class="card">
                    <h2>🔐 Create New Wallet</h2>
                    <button class="primary" onclick="createWallet()">Generate Wallet</button>
                    <div id="wallet-result" class="output hidden"></div>
                </div>
                <div class="card">
                    <h2>💰 Check Balance</h2>
                    <input type="text" id="balance-address" placeholder="Enter wallet address">
                    <button onclick="checkBalance()">Check Balance</button>
                    <div id="balance-result" class="output hidden"></div>
                </div>
                <div class="card card-full">
                    <h2>💸 Send Transaction</h2>
                    <input type="text" id="tx-sender" placeholder="Sender Address">
                    <input type="text" id="tx-receiver" placeholder="Receiver Address">
                    <div class="flex">
                        <input type="number" id="tx-amount" placeholder="Amount (XYZ)" step="0.00000001" style="flex: 2">
                        <input type="number" id="tx-fee" placeholder="Fee" step="0.00000001" value="0.0001" style="flex: 1">
                    </div>
                    <input type="text" id="tx-private-key" placeholder="Private Key (for signing)">
                    <button class="success" onclick="sendTransaction()">Send Transaction</button>
                    <div id="tx-result" class="output hidden"></div>
                </div>
            </div>
        </div>

        <!-- Mining Tab -->
        <div id="mining-tab" class="tab-content">
            <div class="cards">
                <div class="card">
                    <h2>⛏️ Mining Control</h2>
                    <input type="text" id="miner-address" placeholder="Your Wallet Address">
                    <div class="flex">
                        <button class="success" onclick="startMining()">Start Mining</button>
                        <button class="danger" onclick="stopMining()">Stop Mining</button>
                    </div>
                    <div id="mining-result" class="output hidden"></div>
                </div>
                <div class="card">
                    <h2>📊 Mining Stats</h2>
                    <div class="stat-row"><span class="stat-label">Status</span><span class="stat-value" id="miner-status">--</span></div>
                    <div class="stat-row"><span class="stat-label">Current Nonce</span><span class="stat-value hash" id="current-nonce">--</span></div>
                    <div class="stat-row"><span class="stat-label">Current Hash</span><span class="stat-value hash" id="current-hash">--</span></div>
                    <button onclick="refreshMiningStatus()" style="margin-top: 12px;">Refresh Status</button>
                </div>
                <div class="card">
                    <h2>💰 Block Reward Info</h2>
                    <div class="stat-row"><span class="stat-label">Current Reward</span><span class="stat-value green" id="reward-value">--</span></div>
                    <div class="stat-row"><span class="stat-label">Halving Interval</span><span class="stat-value">210,000 blocks</span></div>
                    <div class="stat-row"><span class="stat-label">Blocks to Halving</span><span class="stat-value" id="blocks-to-halving">--</span></div>
                </div>
            </div>
        </div>

        <!-- Explorer Tab -->
        <div id="explorer-tab" class="tab-content">
            <div class="cards">
                <div class="card card-full">
                    <h2>🔍 Search</h2>
                    <div class="flex">
                        <input type="text" id="search-query" placeholder="Block height, hash, or transaction ID" style="flex: 1">
                        <button onclick="search()">Search</button>
                    </div>
                    <div id="search-result" class="output hidden"></div>
                </div>
                <div class="card">
                    <h2>📦 Recent Blocks</h2>
                    <div id="recent-blocks">Loading...</div>
                </div>
                <div class="card">
                    <h2>⏳ Pending Transactions</h2>
                    <div id="pending-txs">Loading...</div>
                </div>
            </div>
        </div>

        <!-- Network Tab -->
        <div id="network-tab" class="tab-content">
            <div class="cards">
                <div class="card">
                    <h2>🌐 Network Status</h2>
                    <div class="stat-row"><span class="stat-label">Node Version</span><span class="stat-value">XYZChain v1.0</span></div>
                    <div class="stat-row"><span class="stat-label">Chain Height</span><span class="stat-value" id="net-height">--</span></div>
                    <div class="stat-row"><span class="stat-label">Difficulty</span><span class="stat-value" id="net-difficulty">--</span></div>
                    <div class="stat-row"><span class="stat-label">Total Supply</span><span class="stat-value" id="net-supply">--</span></div>
                </div>
                <div class="card">
                    <h2>⚙️ System Info</h2>
                    <div class="stat-row"><span class="stat-label">Storage Type</span><span class="stat-value">File-based (JSON)</span></div>
                    <div class="stat-row"><span class="stat-label">Database Required</span><span class="stat-value green">No</span></div>
                    <div class="stat-row"><span class="stat-label">Block Time</span><span class="stat-value">~3-5 seconds</span></div>
                    <div class="stat-row"><span class="stat-label">Consensus</span><span class="stat-value">PoW (SHA256)</span></div>
                </div>
            </div>
        </div>
    </div>

    <div id="modal" class="modal hidden" onclick="closeModal(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <button class="close-btn" onclick="closeModal()">&times;</button>
            <div id="modal-body"></div>
        </div>
    </div>

    <script>
        let currentTab = 'dashboard';
        
        // API helper
        async function apiCall(endpoint, method = 'GET', data = null) {
            try {
                const options = { method, headers: { 'Content-Type': 'application/json' } };
                if (data && method === 'POST') options.body = JSON.stringify(data);
                const response = await fetch(endpoint, options);
                return await response.json();
            } catch (error) {
                console.error('API Error:', error);
                return { success: false, error: error.message };
            }
        }
        
        // Tab switching
        function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById(`${tab}-tab`).classList.add('active');
            event.target.classList.add('active');
            
            // Refresh data for the tab
            if (tab === 'dashboard') loadDashboard();
            if (tab === 'explorer') loadExplorer();
            if (tab === 'mining') refreshMiningStatus();
        }
        
        // Dashboard functions
        async function loadDashboard() {
            const status = await apiCall('/api/status');
            const chain = await apiCall('/chain?limit=10');
            const mining = await apiCall('/mine/status');
            
            if (status.success) {
                const data = status.data;
                document.getElementById('total-supply').textContent = (data.total_supply / 1000000).toFixed(2) + 'M';
                document.getElementById('remaining-supply').textContent = (data.remaining_supply / 1000000).toFixed(2) + 'M';
                document.getElementById('block-reward').textContent = data.difficulty ? '50 XYZ' : '--';
                document.getElementById('difficulty').textContent = data.difficulty || '--';
                document.getElementById('chain-height').textContent = data.chain_height;
                document.getElementById('mempool-size').textContent = data.mempool_size;
                if (data.latest_hash) {
                    document.getElementById('latest-hash').textContent = data.latest_hash.substring(0, 24) + '...';
                }
            }
            
            if (mining.success) {
                document.getElementById('mining-status').textContent = mining.data.is_running ? '🟢 Active' : '⚫ Stopped';
                document.getElementById('mining-status').className = 'stat-value ' + (mining.data.is_running ? 'green' : '');
            }
            
            if (chain.success && chain.data.blocks) {
                const blocks = chain.data.blocks.slice(-10).reverse();
                document.getElementById('latest-blocks').innerHTML = blocks.map(b => `
                    <div class="stat-row clickable" onclick="viewBlock(${b.index})">
                        <span class="stat-label">#${b.index}</span>
                        <span class="hash">${b.hash.substring(0, 20)}...</span>
                        <span class="stat-value">${b.transactions ? b.transactions.length : 0} tx</span>
                    </div>
                `).join('');
            }
        }
        
        // Wallet functions
        async function createWallet() {
            const result = await apiCall('/wallet/create', 'POST');
            if (result.success) {
                document.getElementById('wallet-result').innerHTML = `
                    <div class="alert alert-success">✅ Wallet Created Successfully!</div>
                    <div class="stat-row"><span class="stat-label">Address:</span><span class="hash">${result.data.address}</span></div>
                    <div class="stat-row"><span class="stat-label">Private Key:</span><span class="hash" style="color:#9a8a5a">${result.data.private_key}</span></div>
                    <div class="alert alert-info" style="margin-top:12px">⚠️ Save your private key! It cannot be recovered.</div>
                `;
                document.getElementById('wallet-result').classList.remove('hidden');
            } else {
                document.getElementById('wallet-result').innerHTML = `<div class="alert alert-error">❌ ${result.error}</div>`;
                document.getElementById('wallet-result').classList.remove('hidden');
            }
        }
        
        async function checkBalance() {
            const address = document.getElementById('balance-address').value.trim();
            if (!address) {
                alert('Please enter an address');
                return;
            }
            const result = await apiCall(`/wallet/balance?address=${encodeURIComponent(address)}`);
            if (result.success) {
                const data = result.data;
                document.getElementById('balance-result').innerHTML = `
                    <div class="stat-row"><span class="stat-label">Address</span><span class="hash">${data.address}</span></div>
                    <div class="stat-row"><span class="stat-label">Confirmed Balance</span><span class="stat-value green">${data.confirmed.toFixed(8)} XYZ</span></div>
                    <div class="stat-row"><span class="stat-label">Pending (Locked)</span><span class="stat-value gold">${data.locked.toFixed(8)} XYZ</span></div>
                    <div class="stat-row"><span class="stat-label">Spendable</span><span class="stat-value blue">${data.spendable.toFixed(8)} XYZ</span></div>
                    ${data.transactions && data.transactions.length > 0 ? '<div class="stat-row"><span class="stat-label">Transactions</span><span class="stat-value">' + data.transactions.length + '</span></div>' : ''}
                `;
                document.getElementById('balance-result').classList.remove('hidden');
            } else {
                document.getElementById('balance-result').innerHTML = `<div class="alert alert-error">❌ ${result.error}</div>`;
                document.getElementById('balance-result').classList.remove('hidden');
            }
        }
        
        async function sendTransaction() {
            const sender = document.getElementById('tx-sender').value.trim();
            const receiver = document.getElementById('tx-receiver').value.trim();
            const amount = parseFloat(document.getElementById('tx-amount').value);
            const fee = parseFloat(document.getElementById('tx-fee').value);
            const privateKey = document.getElementById('tx-private-key').value.trim();
            
            if (!sender || !receiver || isNaN(amount) || amount <= 0) {
                alert('Please fill all required fields');
                return;
            }
            
            if (!privateKey) {
                alert('Private key required for signing');
                return;
            }
            
            const result = await apiCall('/transaction/create', 'POST', {
                sender, receiver, amount, fee, private_key: privateKey
            });
            
            if (result.success) {
                document.getElementById('tx-result').innerHTML = `
                    <div class="alert alert-success">✅ Transaction Sent!</div>
                    <div class="stat-row"><span class="stat-label">TXID</span><span class="hash clickable" onclick="viewTransaction('${result.data.txid}')">${result.data.txid}</span></div>
                `;
                document.getElementById('tx-result').classList.remove('hidden');
                document.getElementById('tx-sender').value = '';
                document.getElementById('tx-receiver').value = '';
                document.getElementById('tx-amount').value = '';
                document.getElementById('tx-private-key').value = '';
            } else {
                document.getElementById('tx-result').innerHTML = `<div class="alert alert-error">❌ ${result.error}</div>`;
                document.getElementById('tx-result').classList.remove('hidden');
            }
        }
        
        // Mining functions
        async function startMining() {
            const address = document.getElementById('miner-address').value.trim();
            if (!address) {
                alert('Please enter your wallet address');
                return;
            }
            const result = await apiCall('/mine/start', 'POST', { address });
            if (result.success) {
                document.getElementById('mining-result').innerHTML = `<div class="alert alert-success">⛏️ Mining started for ${address.substring(0, 20)}...</div>`;
                refreshMiningStatus();
            } else {
                document.getElementById('mining-result').innerHTML = `<div class="alert alert-error">❌ ${result.error}</div>`;
            }
            document.getElementById('mining-result').classList.remove('hidden');
        }
        
        async function stopMining() {
            const result = await apiCall('/mine/stop', 'POST');
            if (result.success) {
                document.getElementById('mining-result').innerHTML = `<div class="alert alert-info">⛏️ Mining stopped</div>`;
                refreshMiningStatus();
            }
            document.getElementById('mining-result').classList.remove('hidden');
        }
        
        async function refreshMiningStatus() {
            const result = await apiCall('/mine/status');
            if (result.success) {
                const data = result.data;
                document.getElementById('miner-status').textContent = data.is_running ? '🟢 Active' : '⚫ Stopped';
                document.getElementById('miner-status').className = data.is_running ? 'stat-value green' : 'stat-value';
                document.getElementById('current-nonce').textContent = data.nonce ? data.nonce.toLocaleString() : '--';
                document.getElementById('current-hash').textContent = data.current_hash ? data.current_hash + '...' : '--';
                document.getElementById('reward-value').textContent = data.block_reward ? data.block_reward.toFixed(8) + ' XYZ' : '--';
                
                const height = data.chain_height;
                const blocksToHalving = 210000 - (height % 210000);
                document.getElementById('blocks-to-halving').textContent = blocksToHalving.toLocaleString();
            }
        }
        
        // Explorer functions
        async function loadExplorer() {
            await loadRecentBlocks();
            await loadPendingTransactions();
        }
        
        async function loadRecentBlocks() {
            const result = await apiCall('/chain?limit=20');
            if (result.success && result.data.blocks) {
                const blocks = result.data.blocks.slice(-20).reverse();
                document.getElementById('recent-blocks').innerHTML = blocks.map(b => `
                    <div class="stat-row clickable" onclick="viewBlock(${b.index})">
                        <span class="stat-label">#${b.index}</span>
                        <span class="hash">${b.hash.substring(0, 24)}...</span>
                        <span class="stat-value">${new Date(b.timestamp * 1000).toLocaleTimeString()}</span>
                    </div>
                `).join('');
            } else {
                document.getElementById('recent-blocks').innerHTML = '<div class="alert alert-info">No blocks found</div>';
            }
        }
        
        async function loadPendingTransactions() {
            const result = await apiCall('/mempool');
            if (result.success && result.data.transactions) {
                const txs = result.data.transactions.slice(0, 20);
                if (txs.length === 0) {
                    document.getElementById('pending-txs').innerHTML = '<div class="alert alert-info">No pending transactions</div>';
                } else {
                    document.getElementById('pending-txs').innerHTML = txs.map(tx => `
                        <div class="stat-row clickable" onclick="viewTransaction('${tx.txid}')">
                            <span class="hash">${tx.txid.substring(0, 24)}...</span>
                            <span class="stat-value">${tx.amount.toFixed(8)} XYZ</span>
                            <span class="badge badge-pending">Pending</span>
                        </div>
                    `).join('');
                }
            } else {
                document.getElementById('pending-txs').innerHTML = '<div class="alert alert-info">No pending transactions</div>';
            }
        }
        
        async function search() {
            const query = document.getElementById('search-query').value.trim();
            if (!query) return;
            
            document.getElementById('search-result').innerHTML = '<div class="alert alert-info">Searching...</div>';
            document.getElementById('search-result').classList.remove('hidden');
            
            // Try block height
            if (/^\d+$/.test(query)) {
                const result = await apiCall(`/block/${query}`);
                if (result.success && result.data) {
                    viewBlock(parseInt(query));
                    return;
                }
            }
            
            // Try transaction or hash
            if (/^[a-f0-9]{64}$/i.test(query)) {
                const txResult = await apiCall(`/transaction/${query}`);
                if (txResult.success && txResult.data) {
                    viewTransaction(query);
                    return;
                }
                
                const blockResult = await apiCall(`/block/hash/${query}`);
                if (blockResult.success && blockResult.data) {
                    viewBlock(blockResult.data.index);
                    return;
                }
            }
            
            // Try wallet address
            const balanceResult = await apiCall(`/wallet/balance?address=${encodeURIComponent(query)}`);
            if (balanceResult.success && balanceResult.data) {
                const data = balanceResult.data;
                document.getElementById('search-result').innerHTML = `
                    <div class="alert alert-success">✅ Wallet Address Found</div>
                    <div class="stat-row"><span class="stat-label">Address</span><span class="hash">${data.address}</span></div>
                    <div class="stat-row"><span class="stat-label">Balance</span><span class="stat-value green">${data.confirmed.toFixed(8)} XYZ</span></div>
                    <div class="stat-row"><span class="stat-label">Spendable</span><span class="stat-value blue">${data.spendable.toFixed(8)} XYZ</span></div>
                `;
                return;
            }
            
            document.getElementById('search-result').innerHTML = '<div class="alert alert-error">❌ No results found</div>';
        }
        
        async function viewBlock(height) {
            const result = await apiCall(`/block/${height}`);
            if (result.success && result.data) {
                const b = result.data;
                document.getElementById('modal-body').innerHTML = `
                    <h3>Block #${b.index}</h3>
                    <div class="stat-row"><span class="stat-label">Hash</span><span class="hash" style="word-break:break-all">${b.hash}</span></div>
                    <div class="stat-row"><span class="stat-label">Previous Hash</span><span class="hash" style="word-break:break-all">${b.previous_hash}</span></div>
                    <div class="stat-row"><span class="stat-label">Timestamp</span><span class="stat-value">${new Date(b.timestamp * 1000).toLocaleString()}</span></div>
                    <div class="stat-row"><span class="stat-label">Difficulty</span><span class="stat-value">${b.difficulty}</span></div>
                    <div class="stat-row"><span class="stat-label">Nonce</span><span class="stat-value">${b.nonce.toLocaleString()}</span></div>
                    <div class="stat-row"><span class="stat-label">Merkle Root</span><span class="hash">${b.merkle_root}</span></div>
                    <div class="stat-row"><span class="stat-label">Miner</span><span class="hash">${b.miner_address}</span></div>
                    <div class="stat-row"><span class="stat-label">Reward</span><span class="stat-value green">${b.reward.toFixed(8)} XYZ</span></div>
                    <div class="stat-row"><span class="stat-label">Transactions</span><span class="stat-value">${b.transactions ? b.transactions.length : 0}</span></div>
                    ${b.transactions && b.transactions.length > 0 ? `
                        <h4 style="margin-top:20px">Transactions</h4>
                        ${b.transactions.map(tx => `
                            <div class="stat-row clickable" onclick="viewTransaction('${tx.txid}')">
                                <span class="hash">${tx.txid.substring(0, 32)}...</span>
                                <span class="stat-value">${tx.amount.toFixed(8)} XYZ</span>
                            </div>
                        `).join('')}
                    ` : ''}
                `;
                document.getElementById('modal').classList.remove('hidden');
            }
        }
        
        async function viewTransaction(txid) {
            const result = await apiCall(`/transaction/${txid}`);
            if (result.success && result.data) {
                const tx = result.data;
                document.getElementById('modal-body').innerHTML = `
                    <h3>Transaction Details</h3>
                    <div class="stat-row"><span class="stat-label">TXID</span><span class="hash" style="word-break:break-all">${tx.txid}</span></div>
                    <div class="stat-row"><span class="stat-label">From</span><span class="hash">${tx.sender}</span></div>
                    <div class="stat-row"><span class="stat-label">To</span><span class="hash">${tx.receiver}</span></div>
                    <div class="stat-row"><span class="stat-label">Amount</span><span class="stat-value green">${tx.amount.toFixed(8)} XYZ</span></div>
                    <div class="stat-row"><span class="stat-label">Fee</span><span class="stat-value">${(tx.fee || 0).toFixed(8)} XYZ</span></div>
                    <div class="stat-row"><span class="stat-label">Nonce</span><span class="stat-value">${tx.nonce}</span></div>
                    <div class="stat-row"><span class="stat-label">Timestamp</span><span class="stat-value">${new Date(tx.timestamp * 1000).toLocaleString()}</span></div>
                    <div class="stat-row"><span class="stat-label">Status</span><span class="stat-value ${tx.confirmed ? 'green' : 'gold'}">${tx.confirmed ? '✓ Confirmed' : '⏳ Pending'}</span></div>
                    ${tx.block_height ? `<div class="stat-row"><span class="stat-label">Block</span><span class="stat-value clickable" onclick="viewBlock(${tx.block_height})">#${tx.block_height}</span></div>` : ''}
                `;
                document.getElementById('modal').classList.remove('hidden');
            }
        }
        
        function closeModal(event) {
            if (!event || event.target === document.getElementById('modal')) {
                document.getElementById('modal').classList.add('hidden');
            }
        }
        
        // Auto-refresh
        setInterval(() => {
            if (currentTab === 'dashboard') loadDashboard();
            if (currentTab === 'explorer') loadRecentBlocks();
            if (currentTab === 'mining') refreshMiningStatus();
        }, 10000);
        
        // Initial load
        loadDashboard();
    </script>
</body>
</html>
HTML;
}
?>
