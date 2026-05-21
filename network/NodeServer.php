<?php
/**
 * Complete Node Server with P2P Support
 * SECURITY HARDENED - PHP 7.4 Compatible
 * - Enforced transaction signatures
 * - Rate-limited wallet unlock
 * - Anti-replay protection
 * - Input validation
 */

class NodeServer
{
    private $apiPort;
    private $p2pPort;
    private $apiSocket;
    private $p2pSocket;
    private $blockchain;
    private $miner;
    private $peerManager;
    private $running;
    private $unlockAttempts;  // Rate limiting for wallet unlock
    
    public function __construct($apiPort = 8080, $p2pPort = null)
    {
        $this->apiPort = $apiPort;
        $this->p2pPort = $p2pPort !== null ? $p2pPort : $apiPort + 1000;
        $this->blockchain = new Blockchain();
        $this->peerManager = new PeerManager();
        $this->miner = null;
        $this->running = false;
        $this->unlockAttempts = [];
    }
    
    public function start()
    {
        $this->running = true;
        
        if (!$this->startApiServer()) {
            Logger::error("Failed to start API server");
            return false;
        }
        
        if (!$this->startP2PServer()) {
            Logger::error("Failed to start P2P server");
            return false;
        }
        
        $this->displayBanner();
        
        $lastMine = 0;
        $lastSync = 0;
        $lastPeerCheck = 0;
        
        while ($this->running) {
            $apiClient = @socket_accept($this->apiSocket);
            if ($apiClient) {
                $this->handleApiRequest($apiClient);
            }
            
            $p2pClient = @socket_accept($this->p2pSocket);
            if ($p2pClient) {
                $this->handleP2PConnection($p2pClient);
            }
            
            if ($this->miner && $this->miner->isRunning()) {
                if ((time() - $lastMine) > 2) {
                    $block = $this->miner->mineBlock();
                    if ($block) {
                        if ($this->blockchain->addBlock($block)) {
                            Logger::info("Block #{$block->index} mined and added!");
                            $this->broadcastBlock($block);
                        }
                    }
                    $lastMine = time();
                }
            }
            
            if ((time() - $lastSync) > 60) {
                $this->syncWithNetwork();
                $lastSync = time();
            }
            
            if ((time() - $lastPeerCheck) > 120) {
                $this->peerManager->checkPeerHealth();
                $lastPeerCheck = time();
            }
            
            // Clean expired rate limit entries
            $this->cleanupRateLimits();
            
            usleep(50000);
        }
        
        return true;
    }
    
    // ===== RATE LIMITING FOR SENSITIVE ENDPOINTS =====
    
    private function checkUnlockRateLimit($ip)
    {
        $now = time();
        $window = 60;
        $maxAttempts = 5;
        
        if (!isset($this->unlockAttempts[$ip])) {
            $this->unlockAttempts[$ip] = [];
        }
        
        $this->unlockAttempts[$ip] = array_filter(
            $this->unlockAttempts[$ip],
            function($time) use ($now, $window) { return $time > ($now - $window); }
        );
        
        if (count($this->unlockAttempts[$ip]) >= $maxAttempts) {
            Logger::warning("Wallet unlock rate limit exceeded for IP: {$ip}");
            return false;
        }
        
        $this->unlockAttempts[$ip][] = $now;
        return true;
    }
    
    private function cleanupRateLimits()
    {
        $now = time();
        foreach ($this->unlockAttempts as $ip => $times) {
            $this->unlockAttempts[$ip] = array_filter(
                $times,
                function($time) use ($now) { return $time > ($now - 120); }
            );
            if (empty($this->unlockAttempts[$ip])) {
                unset($this->unlockAttempts[$ip]);
            }
        }
    }
    
    // ===== INPUT SANITIZATION =====
    
    private function sanitizeInput($data)
    {
        if (is_string($data)) {
            return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
        }
        if (is_array($data)) {
            return array_map([$this, 'sanitizeInput'], $data);
        }
        return $data;
    }
    
    // ===== TRANSACTION VALIDATION =====
    
    private function isCoinbaseOrGenesis($sender)
    {
        return $sender === '0' || $sender === 'XYZCHAIN_GENESIS_ADDRESS' || $sender === 'XYZCHAIN_GENESIS';
    }
    
    private function validateTransactionSecurity($txData, $data)
    {
        // MUST have either private_key OR (signature + public_key) for non-coinbase
        $hasPrivateKey = !empty($data['private_key']);
        $hasSignature = !empty($data['signature']) && !empty($data['public_key']);
        $isCoinbase = $this->isCoinbaseOrGenesis($txData['sender']);
        
        if (!$isCoinbase && !$hasPrivateKey && !$hasSignature) {
            return [
                'valid' => false,
                'error' => 'Transaction must be cryptographically signed',
                'detail' => 'Provide private_key for auto-signing, or (signature + public_key) for pre-signed transactions'
            ];
        }
        
        // Validate amount is reasonable
        if ($txData['amount'] <= 0) {
            return ['valid' => false, 'error' => 'Amount must be positive'];
        }
        
        if ($txData['amount'] > 100000000) {
            return ['valid' => false, 'error' => 'Amount exceeds maximum (100,000,000 XYZ)'];
        }
        
        // Validate fee
        if ($txData['fee'] < 0.00000001) {
            return ['valid' => false, 'error' => 'Fee too low (minimum 0.00000001 XYZ)'];
        }
        
        if ($txData['fee'] > 1000) {
            return ['valid' => false, 'error' => 'Fee too high (maximum 1,000 XYZ)'];
        }
        
        // Validate addresses look reasonable
        if (strlen($txData['sender']) < 10 || strlen($txData['receiver']) < 10) {
            return ['valid' => false, 'error' => 'Invalid address format'];
        }
        
        return ['valid' => true];
    }
    
    // ===== SERVER SETUP (unchanged) =====
    
    private function startApiServer()
    {
        $this->apiSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->apiSocket) return false;
        socket_set_option($this->apiSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (!socket_bind($this->apiSocket, '0.0.0.0', $this->apiPort)) {
            Logger::error("Cannot bind API to port {$this->apiPort}");
            return false;
        }
        if (!socket_listen($this->apiSocket)) return false;
        socket_set_nonblock($this->apiSocket);
        return true;
    }
    
    private function startP2PServer()
    {
        $this->p2pSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->p2pSocket) return false;
        socket_set_option($this->p2pSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (!socket_bind($this->p2pSocket, '0.0.0.0', $this->p2pPort)) {
            Logger::error("Cannot bind P2P to port {$this->p2pPort}");
            return false;
        }
        if (!socket_listen($this->p2pSocket)) return false;
        socket_set_nonblock($this->p2pSocket);
        return true;
    }
    
    private function displayBanner()
    {
        echo "\n";
        echo "==================================================\n";
        echo "       XYZChain Secure - Full Node\n";
        echo "       SECURITY HARDENED v1.0\n";
        echo "==================================================\n";
        echo "\n";
        echo "  API Server: http://localhost:{$this->apiPort}\n";
        echo "  P2P Server: tcp://0.0.0.0:{$this->p2pPort}\n";
        echo "  Chain Height: " . $this->blockchain->getHeight() . "\n";
        echo "  Signature Enforcement: STRICT\n";
        echo "  Unlock Rate Limit: 5/min per IP\n";
        echo "\n";
        echo "  Node is running (Press Ctrl+C to stop)\n";
        echo "\n";
    }
    
    // ===== API REQUEST HANDLING =====
    private function handleApiRequest($client)
    {
        socket_getpeername($client, $ip);
        
        $request = socket_read($client, 16384);
        if (!$request) { socket_close($client); return; }
        
        $lines = explode("\r\n", $request);
        $firstLine = explode(' ', $lines[0]);
        $method = isset($firstLine[0]) ? $firstLine[0] : 'GET';
        $path = isset($firstLine[1]) ? $firstLine[1] : '/';
        
        $body = '';
        if ($method === 'POST') {
            $body = end($lines);
        }
        
        $data = json_decode($body, true);
        if ($data === null) { $data = []; }
        
        // Sanitize all input data
        $data = $this->sanitizeInput($data);
        
        parse_str(parse_url($path, PHP_URL_QUERY), $queryParams);
        $queryParams = $this->sanitizeInput($queryParams);
        $path = parse_url($path, PHP_URL_PATH);
        
        $response = $this->routeApiRequest($method, $path, $queryParams, $data, $ip);
        
        $contentType = 'application/json';
        if ($path === '/' && strpos($response, '<!DOCTYPE') === 0) {
            $contentType = 'text/html';
        }
        
        $httpResponse = "HTTP/1.1 200 OK\r\n";
        $httpResponse .= "Content-Type: {$contentType}; charset=utf-8\r\n";
        $httpResponse .= "Access-Control-Allow-Origin: *\r\n";
        $httpResponse .= "Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n";
        $httpResponse .= "Access-Control-Allow-Headers: Content-Type, Authorization\r\n";
        $httpResponse .= "X-Content-Type-Options: nosniff\r\n";
        $httpResponse .= "X-Frame-Options: DENY\r\n";
        $httpResponse .= "X-XSS-Protection: 1; mode=block\r\n";
        $httpResponse .= "Connection: close\r\n";
        $httpResponse .= "Content-Length: " . strlen($response) . "\r\n";
        $httpResponse .= "\r\n";
        $httpResponse .= $response;
        
        socket_write($client, $httpResponse);
        socket_close($client);
    }
    
    // ===== P2P HANDLING (unchanged) =====
    private function handleP2PConnection($client)
    {
        socket_getpeername($client, $ip, $port);
        
        if (!PeerFirewall::isConnectionAllowed($ip)) {
            socket_close($client); return;
        }
        if (!RateLimiter::isAllowed($ip)) {
            socket_close($client); return;
        }
        
        $data = socket_read($client, 65536);
        if (!$data) { socket_close($client); return; }
        
        $message = json_decode($data, true);
        if (!$message) { socket_close($client); return; }
        
        if (!PeerFirewall::validatePeerData($message)) {
            socket_close($client); return;
        }
        
        $response = $this->processP2PMessage($message, $ip, $port);
        
        if ($response !== null) {
            $responseData = json_encode($response);
            socket_write($client, $responseData, strlen($responseData));
        }
        
        socket_close($client);
    }
    
    private function processP2PMessage($message, $ip, $port)
    {
        $type = isset($message['type']) ? $message['type'] : '';
        switch ($type) {
            case 'ping':
                $this->peerManager->addPeer($ip, $port);
                return ['type' => 'pong', 'timestamp' => time(), 'chain_height' => $this->blockchain->getHeight()];
            case 'get_peers':
                return ['type' => 'peers', 'peers' => $this->peerManager->getPeers(50)];
            case 'get_blocks':
                $from = isset($message['from_height']) ? (int)$message['from_height'] : 0;
                $to = isset($message['to_height']) ? (int)$message['to_height'] : $this->blockchain->getHeight();
                $blocks = [];
                for ($i = $from; $i <= $to; $i++) {
                    $block = $this->blockchain->getBlockByHeight($i);
                    if ($block) $blocks[] = $block->toArray();
                }
                return ['type' => 'blocks', 'blocks' => $blocks];
            case 'new_block':
                return $this->handleNewBlock($message, $ip);
            case 'new_transaction':
                return $this->handleNewTransaction($message, $ip);
            case 'get_chain_height':
                return ['type' => 'chain_height', 'height' => $this->blockchain->getHeight()];
            default:
                return ['type' => 'error', 'message' => 'Unknown message type'];
        }
    }
    
    private function handleNewBlock($message, $senderIp)
    {
        $blockData = isset($message['block']) ? $message['block'] : null;
        if (!$blockData) return ['type' => 'error', 'message' => 'No block data'];
        try {
            $block = Block::fromArray($blockData);
            if (!$block->verify()) {
                $this->peerManager->updatePeerReputation($senderIp, $this->p2pPort, false);
                return ['type' => 'block_rejected'];
            }
            $ourHeight = $this->blockchain->getHeight();
            if ($block->index === $ourHeight + 1) {
                if ($this->blockchain->addBlock($block)) {
                    $this->peerManager->updatePeerReputation($senderIp, $this->p2pPort, true);
                    $this->broadcastBlock($block);
                    return ['type' => 'block_accepted'];
                }
            } elseif ($block->index > $ourHeight + 1) {
                $this->syncWithPeer($senderIp, $this->p2pPort);
            }
            return ['type' => 'block_ignored'];
        } catch (\Exception $e) {
            return ['type' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    private function handleNewTransaction($message, $senderIp)
    {
        $txData = isset($message['transaction']) ? $message['transaction'] : null;
        if (!$txData) return ['type' => 'error', 'message' => 'No transaction data'];
        
        $transaction = Transaction::fromArray($txData);
        if (!$transaction->verify()) {
            $this->peerManager->updatePeerReputation($senderIp, $this->p2pPort, false);
            return ['type' => 'transaction_rejected', 'reason' => 'invalid_signature'];
        }
        
        if ($this->blockchain->addTransaction($transaction->toArray())) {
            $this->broadcastTransaction($transaction);
            $this->peerManager->updatePeerReputation($senderIp, $this->p2pPort, true);
            return ['type' => 'transaction_accepted'];
        }
        return ['type' => 'transaction_ignored'];
    }
    
    // ===== BROADCASTING =====
    public function broadcastBlock($block)
    {
        $message = ['type' => 'new_block', 'block' => $block instanceof Block ? $block->toArray() : $block];
        $this->broadcastToPeers($message);
    }
    
    public function broadcastTransaction($transaction)
    {
        $message = ['type' => 'new_transaction', 'transaction' => $transaction instanceof Transaction ? $transaction->toArray() : $transaction];
        $this->broadcastToPeers($message);
    }
    
    private function broadcastToPeers($message)
    {
        $peers = $this->peerManager->getActivePeers();
        foreach ($peers as $peer) {
            try { $this->sendToPeer($peer['ip'], $peer['port'], $message); } catch (\Exception $e) {}
        }
    }
    
    // ===== SYNC =====
    public function syncWithNetwork()
    {
        $peers = $this->peerManager->getActivePeers();
        if (empty($peers)) return;
        foreach ($peers as $peer) {
            try {
                $response = $this->sendToPeer($peer['ip'], $peer['port'], ['type' => 'get_chain_height']);
                if ($response && isset($response['height']) && (int)$response['height'] > $this->blockchain->getHeight()) {
                    $this->syncWithPeer($peer['ip'], $peer['port']);
                    return;
                }
            } catch (\Exception $e) {}
        }
    }
    
    public function syncWithPeer($ip, $port)
    {
        $fromHeight = $this->blockchain->getHeight() + 1;
        try {
            $response = $this->sendToPeer($ip, $port, ['type' => 'get_blocks', 'from_height' => $fromHeight, 'to_height' => $fromHeight + 500]);
            if ($response && isset($response['blocks'])) {
                $syncedCount = 0;
                foreach ($response['blocks'] as $blockData) {
                    $block = Block::fromArray($blockData);
                    if ($block->verify() && $this->blockchain->addBlock($block)) $syncedCount++;
                }
                if ($syncedCount > 0) {
                    Logger::info("Synced {$syncedCount} blocks from {$ip}:{$port}");
                    $this->peerManager->updatePeerReputation($ip, $port, true);
                }
                return $syncedCount;
            }
        } catch (\Exception $e) {
            $this->peerManager->updatePeerReputation($ip, $port, false);
        }
        return 0;
    }
    
    public function sendToPeer($ip, $port, $message)
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$socket) throw new \RuntimeException("Cannot create socket");
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);
        if (!@socket_connect($socket, $ip, $port)) { socket_close($socket); throw new \RuntimeException("Connection failed"); }
        socket_write($socket, json_encode($message));
        $response = socket_read($socket, 65536);
        socket_close($socket);
        return $response ? json_decode($response, true) : null;
    }
    
    // ===== API ROUTING (SECURITY HARDENED) =====
    private function routeApiRequest($method, $path, $queryParams, $data, $clientIp)
    {
        switch (true) {
            // === HOME / STATUS ===
            case ($path === '/'):
                $htmlFile = ROOT_PATH . '/frontend/index.html';
                if (file_exists($htmlFile)) return file_get_contents($htmlFile);
                return json_encode(['success' => true, 'name' => 'XYZChain Secure', 'chain_height' => $this->blockchain->getHeight()]);
            
            case ($path === '/api/status'):
                return json_encode(['success' => true, 'data' => [
                    'status' => 'running', 'version' => '1.0.0-security',
                    'php_version' => PHP_VERSION,
                    'chain_height' => $this->blockchain->getHeight(),
                    'mempool_size' => $this->blockchain->getMempoolSize(),
                    'mining' => $this->miner ? $this->miner->isRunning() : false,
                    'peers' => $this->peerManager->getActivePeerCount(),
                    'total_supply' => $this->blockchain->getTotalSupply(),
                    'p2p_port' => $this->p2pPort,
                    'api_port' => $this->apiPort,
                    'signature_enforcement' => 'STRICT'
                ]]);
            
            // === BLOCKCHAIN ===
            case ($path === '/chain' || $path === '/api/chain'):
                $limit = isset($queryParams['limit']) ? min((int)$queryParams['limit'], 1000) : 100;
                $chain = $this->blockchain->getChain();
                if ($limit > 0) $chain = array_slice($chain, -$limit);
                return json_encode(['success' => true, 'data' => ['height' => $this->blockchain->getHeight(), 'blocks' => $chain]]);
            
            case ($path === '/block/latest'):
                $block = $this->blockchain->getLatestBlock();
                return json_encode(['success' => true, 'data' => $block ? $block->toArray() : null]);
            
            case (preg_match('#^/block/(\d+)$#', $path, $m)):
                $block = $this->blockchain->getBlockByHeight((int)$m[1]);
                return json_encode(['success' => true, 'data' => $block ? $block->toArray() : null]);
            
            case (preg_match('#^/block/hash/([a-f0-9]{64})$#', $path, $m)):
                $block = $this->blockchain->getBlockByHash($m[1]);
                return json_encode(['success' => true, 'data' => $block ? $block->toArray() : null]);
            
            // === WALLET ===
            case ($path === '/wallet/create'):
                if ($method === 'POST') {
                    $password = isset($data['password']) ? $data['password'] : '';
                    if (strlen($password) < 8) return json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
                    if (strlen($password) > 128) return json_encode(['success' => false, 'error' => 'Password too long']);
                    try {
                        $walletData = Wallet::createEncrypted($password);
                        return json_encode(['success' => true, 'data' => [
                            'address' => $walletData['address'],
                            'created' => date('Y-m-d H:i:s', $walletData['creation_time'])
                        ]]);
                    } catch (\Exception $e) {
                        return json_encode(['success' => false, 'error' => 'Wallet creation failed']);
                    }
                }
                break;
            
            case ($path === '/wallet/import'):
                if ($method === 'POST') {
                    $type = isset($data['type']) ? $data['type'] : '';
                    $password = isset($data['password']) ? $data['password'] : '';
                    if (strlen($password) < 8) return json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
                    try {
                        $walletData = null;
                        if ($type === 'mnemonic') {
                            $mnemonic = isset($data['mnemonic']) ? $data['mnemonic'] : '';
                            if (empty($mnemonic)) return json_encode(['success' => false, 'error' => 'Mnemonic required']);
                            if (!Mnemonic::validate($mnemonic)) return json_encode(['success' => false, 'error' => 'Invalid mnemonic phrase']);
                            $walletData = Wallet::importFromMnemonic($mnemonic);
                        } elseif ($type === 'private_key') {
                            $privateKey = isset($data['private_key']) ? $data['private_key'] : '';
                            if (empty($privateKey)) return json_encode(['success' => false, 'error' => 'Private key required']);
                            $walletData = Wallet::importFromPrivateKey($privateKey);
                        } else {
                            return json_encode(['success' => false, 'error' => 'Type must be mnemonic or private_key']);
                        }
                        
                        $aes = new AES();
                        $walletFile = [
                            'address' => $walletData['address'],
                            'public_key' => $walletData['public_key'],
                            'encrypted_private_key' => $aes->encrypt($walletData['private_key'], $password),
                            'creation_time' => time(),
                            'imported' => true
                        ];
                        if (isset($walletData['mnemonic'])) {
                            $walletFile['encrypted_mnemonic'] = $aes->encrypt($walletData['mnemonic'], $password);
                        }
                        WalletStorage::save($walletFile);
                        
                        return json_encode(['success' => true, 'data' => ['address' => $walletData['address'], 'imported' => true]]);
                    } catch (\Exception $e) {
                        return json_encode(['success' => false, 'error' => 'Import failed']);
                    }
                }
                break;
            
            case ($path === '/wallet/unlock'):
                if ($method === 'POST') {
                    // RATE LIMIT CHECK
                    if (!$this->checkUnlockRateLimit($clientIp)) {
                        Logger::warning("Unlock rate limit exceeded for IP: {$clientIp}");
                        return json_encode(['success' => false, 'error' => 'Too many attempts. Please wait 60 seconds.']);
                    }
                    
                    $password = isset($data['password']) ? $data['password'] : '';
                    if (empty($password)) return json_encode(['success' => false, 'error' => 'Password required']);
                    
                    $wallet = new Wallet();
                    if ($wallet->unlock($password)) {
                        Logger::info("Wallet unlocked successfully for IP: {$clientIp}");
                        return json_encode(['success' => true, 'data' => [
                            'address' => $wallet->getAddress(),
                            'public_key' => $wallet->getPublicKey(),
                            'private_key' => $wallet->getPrivateKey(),
                            'status' => 'unlocked'
                        ]]);
                    }
                    Logger::warning("Failed unlock attempt from IP: {$clientIp}");
                    return json_encode(['success' => false, 'error' => 'Invalid password or no wallet file found']);
                }
                break;
            
            case ($path === '/wallet/lock'):
                if ($method === 'POST') {
                    $wallet = new Wallet();
                    $wallet->lock();
                    return json_encode(['success' => true, 'data' => ['status' => 'locked']]);
                }
                break;
            
            case ($path === '/wallet/balance'):
                $address = isset($queryParams['address']) ? $queryParams['address'] : '';
                if (empty($address)) return json_encode(['success' => false, 'error' => 'Address required']);
                $balance = $this->blockchain->getBalance($address);
                $history = $this->blockchain->getTransactionHistory($address, 20);
                return json_encode(['success' => true, 'data' => array_merge($balance, ['transactions' => $history])]);
            
            // === TRANSACTIONS (STRICT SIGNATURE ENFORCEMENT) ===
            case ($path === '/transaction/create'):
                if ($method === 'POST') {
                    $privateKey = isset($data['private_key']) ? $data['private_key'] : null;
                    $signature = isset($data['signature']) ? $data['signature'] : null;
                    $publicKey = isset($data['public_key']) ? $data['public_key'] : null;
                    
                    $txData = [
                        'txid' => hash('sha256', json_encode($data) . microtime()),
                        'sender' => isset($data['sender']) ? $data['sender'] : '',
                        'receiver' => isset($data['receiver']) ? $data['receiver'] : '',
                        'amount' => isset($data['amount']) ? (float)$data['amount'] : 0,
                        'fee' => isset($data['fee']) ? (float)$data['fee'] : 0.0001,
                        'nonce' => isset($data['nonce']) ? (int)$data['nonce'] : random_int(100000, 999999),
                        'timestamp' => time(),
                        'signature' => '',
                        'public_key' => ''
                    ];
                    
                    // Validate required fields
                    if (empty($txData['sender']) || empty($txData['receiver']) || $txData['amount'] <= 0) {
                        return json_encode(['success' => false, 'error' => 'Missing required fields (sender, receiver, amount)']);
                    }
                    
                    // Security validation
                    $secCheck = $this->validateTransactionSecurity($txData, $data);
                    if (!$secCheck['valid']) {
                        return json_encode(['success' => false, 'error' => $secCheck['error'], 'detail' => $secCheck['detail'] ?? '']);
                    }
                    
                    $isCoinbase = $this->isCoinbaseOrGenesis($txData['sender']);
                    
                    // FOR NON-COINBASE: STRICT SIGNATURE ENFORCEMENT
                    if (!$isCoinbase) {
                        if ($privateKey) {
                            // Auto-sign with provided private key
                            $transaction = new Transaction($txData['sender'], $txData['receiver'], $txData['amount'], $txData['fee'], $txData['nonce']);
                            $transaction->txid = $txData['txid'];
                            $transaction->timestamp = $txData['timestamp'];
                            if (!$transaction->sign($privateKey)) {
                                return json_encode(['success' => false, 'error' => 'Failed to sign transaction - invalid private key']);
                            }
                            $txData['signature'] = $transaction->signature;
                            $txData['public_key'] = $transaction->public_key;
                            $txData['transaction_hash'] = $transaction->transaction_hash;
                        } elseif ($signature && $publicKey) {
                            // Verify provided signature
                            $txData['signature'] = $signature;
                            $txData['public_key'] = $publicKey;
                            $transaction = Transaction::fromArray($txData);
                            if (!$transaction->verify()) {
                                return json_encode(['success' => false, 'error' => 'Invalid transaction signature - transaction rejected']);
                            }
                        } else {
                            // NO SIGNATURE - REJECTED
                            return json_encode([
                                'success' => false,
                                'error' => 'CRYPTOGRAPHIC SIGNATURE REQUIRED',
                                'detail' => 'All non-coinbase transactions must be signed. Provide private_key for auto-signing or (signature + public_key) for pre-signed transactions.'
                            ]);
                        }
                    }
                    
                    // Check balance (skip for coinbase)
                    if (!$isCoinbase) {
                        $balance = $this->blockchain->getBalance($txData['sender']);
                        if ($balance['spendable'] < ($txData['amount'] + $txData['fee'])) {
                            return json_encode([
                                'success' => false,
                                'error' => 'Insufficient balance',
                                'balance' => $balance['spendable'],
                                'required' => $txData['amount'] + $txData['fee']
                            ]);
                        }
                    }
                    
                    // Anti-replay check
                    $antiReplay = new AntiReplay();
                    if (!$antiReplay->validate(Transaction::fromArray($txData))) {
                        return json_encode(['success' => false, 'error' => 'Transaction rejected by anti-replay protection']);
                    }
                    
                    if ($this->blockchain->addTransaction($txData)) {
                        $this->broadcastTransaction(Transaction::fromArray($txData));
                        return json_encode(['success' => true, 'data' => $txData]);
                    }
                    return json_encode(['success' => false, 'error' => 'Transaction rejected by mempool']);
                }
                break;
            
            case ($path === '/mempool'):
                return json_encode(['success' => true, 'data' => [
                    'count' => $this->blockchain->getMempoolSize(),
                    'transactions' => $this->blockchain->getPendingTransactions(100)
                ]]);
            
            // === MINING ===
            case ($path === '/mine/start'):
                if ($method === 'POST') {
                    $address = isset($data['address']) ? $data['address'] : '';
                    if (empty($address)) return json_encode(['success' => false, 'error' => 'Miner address required']);
                    if ($this->miner && $this->miner->isRunning()) return json_encode(['success' => false, 'error' => 'Miner already running']);
                    $this->miner = new Miner($this->blockchain, $address, 1);
                    $this->miner->start();
                    return json_encode(['success' => true, 'data' => ['status' => 'started', 'address' => $address]]);
                }
                break;
            
            case ($path === '/mine/stop'):
                if ($this->miner) $this->miner->stop();
                return json_encode(['success' => true, 'data' => ['status' => 'stopped']]);
            
            case ($path === '/mine/status'):
                $status = $this->miner ? $this->miner->getStatus() : ['is_running' => false, 'hash_rate' => 0];
                $latestBlock = $this->blockchain->getLatestBlock();
                $status['chain_height'] = $this->blockchain->getHeight();
                $status['difficulty'] = $latestBlock ? $latestBlock->difficulty : 4;
                $status['block_reward'] = Reward::calculateBlockReward($latestBlock ? $latestBlock->index + 1 : 1);
                $status['total_supply'] = $this->blockchain->getTotalSupply();
                return json_encode(['success' => true, 'data' => $status]);
            
            // === NETWORK / PEERS ===
            case ($path === '/peers'):
                return json_encode(['success' => true, 'data' => [
                    'active' => $this->peerManager->getActivePeerCount(),
                    'total' => $this->peerManager->getPeerCount(),
                    'peers' => $this->peerManager->getPeers(50)
                ]]);
            
            case ($path === '/peers/register'):
                if ($method === 'POST') {
                    $ip = isset($data['ip']) ? $data['ip'] : '';
                    $port = isset($data['port']) ? (int)$data['port'] : 9080;
                    if ($ip && $this->peerManager->addPeer($ip, $port)) {
                        $this->syncWithPeer($ip, $port);
                        return json_encode(['success' => true, 'data' => ['peer' => "{$ip}:{$port}", 'syncing' => true]]);
                    }
                    return json_encode(['success' => false, 'error' => 'Invalid peer']);
                }
                break;
            
            case ($path === '/sync/start'):
                if ($method === 'POST') { $this->syncWithNetwork(); return json_encode(['success' => true]); }
                break;
            
            case ($path === '/network/stats'):
                return json_encode(['success' => true, 'data' => [
                    'chain_height' => $this->blockchain->getHeight(),
                    'mempool_size' => $this->blockchain->getMempoolSize(),
                    'active_peers' => $this->peerManager->getActivePeerCount(),
                    'total_peers' => $this->peerManager->getPeerCount(),
                    'api_port' => $this->apiPort,
                    'p2p_port' => $this->p2pPort,
                    'mining' => $this->miner ? $this->miner->isRunning() : false,
                    'total_supply' => $this->blockchain->getTotalSupply(),
                    'signature_enforcement' => 'STRICT'
                ]]);
        }
        
        return json_encode(['success' => false, 'error' => 'Endpoint not found']);
    }
    
    public function stop()
    {
        $this->running = false;
        if ($this->apiSocket) socket_close($this->apiSocket);
        if ($this->p2pSocket) socket_close($this->p2pSocket);
        if ($this->miner) $this->miner->stop();
        Logger::info("Node stopped");
    }
}