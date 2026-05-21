<?php
/**
 * Blockchain Engine - Complete Version
 * PHP 7.4 Compatible
 */

class Blockchain
{
    private $chain;
    private $pendingTransactions;
    private $invalidTransactions;
    private $miningAddress;
    private $isMining;
    private $storageDir;
    
    const MAX_SUPPLY = 800000000;
    const MAX_MEMPOOL_SIZE = 10000;
    
    public function __construct()
    {
        $this->chain = [];
        $this->pendingTransactions = [];
        $this->invalidTransactions = [];
        $this->isMining = false;
        $this->miningAddress = '';
        $this->storageDir = NodeConfig::getBlocksDir();
        
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
        
        $this->loadChain();
        $this->loadMempool();
        $this->loadInvalidTransactions();
    }
    
    private function loadChain()
    {
        $index = 0;
        
        while (true) {
            $blockFile = sprintf('%s/%06d.blk', $this->storageDir, $index);
            if (!file_exists($blockFile)) {
                break;
            }
            
            $data = @file_get_contents($blockFile);
            if ($data !== false) {
                $decompressed = @gzuncompress($data);
                if ($decompressed !== false) {
                    $blockData = @unserialize($decompressed);
                    if ($blockData !== false && isset($blockData['index'])) {
                        $this->chain[] = Block::fromArray($blockData);
                    }
                }
            }
            
            $index++;
        }
        
        if (empty($this->chain)) {
            $this->createGenesisBlock();
        }
        
        Logger::info("Blockchain loaded: " . count($this->chain) . " blocks");
    }
    
    private function loadMempool()
    {
        $mempoolDir = dirname($this->storageDir) . '/mempool';
        $mempoolFile = $mempoolDir . '/mempool.dat';
        
        if (file_exists($mempoolFile)) {
            $data = @file_get_contents($mempoolFile);
            if ($data !== false) {
                $decoded = @gzuncompress($data);
                if ($decoded !== false) {
                    $mempoolData = @unserialize($decoded);
                    if ($mempoolData !== false && isset($mempoolData['transactions'])) {
                        $this->pendingTransactions = $mempoolData['transactions'];
                    }
                }
            }
        }
    }
    
    private function loadInvalidTransactions()
    {
        $invalidFile = dirname($this->storageDir) . '/mempool/invalid.dat';
        if (file_exists($invalidFile)) {
            $data = @file_get_contents($invalidFile);
            if ($data !== false) {
                $decoded = @gzuncompress($data);
                if ($decoded !== false) {
                    $invalid = @unserialize($decoded);
                    if ($invalid !== false && is_array($invalid)) {
                        $this->invalidTransactions = $invalid;
                    }
                }
            }
        }
    }
    
    private function saveMempool()
    {
        $mempoolDir = dirname($this->storageDir) . '/mempool';
        if (!is_dir($mempoolDir)) {
            mkdir($mempoolDir, 0755, true);
        }
        
        $mempoolFile = $mempoolDir . '/mempool.dat';
        $data = serialize([
            'transactions' => $this->pendingTransactions,
            'timestamp' => time(),
            'count' => count($this->pendingTransactions)
        ]);
        $compressed = gzcompress($data, 9);
        file_put_contents($mempoolFile, $compressed);
        
        $invalidFile = $mempoolDir . '/invalid.dat';
        $invalidData = serialize($this->invalidTransactions);
        $invalidCompressed = gzcompress($invalidData, 9);
        file_put_contents($invalidFile, $invalidCompressed);
    }
    
    private function createGenesisBlock()
    {
        $genesisTx = [
            'txid' => str_repeat('0', 64),
            'sender' => '0',
            'receiver' => 'XYZCHAIN_GENESIS_ADDRESS',
            'amount' => 400000000,
            'fee' => 0,
            'nonce' => 0,
            'timestamp' => time(),
            'signature' => 'GENESIS',
            'public_key' => '',
            'transaction_hash' => ''
        ];
        
        $genesisBlock = new Block(0, str_repeat('0', 64), [$genesisTx], 'XYZCHAIN_GENESIS', 4);
        $genesisBlock->hash = $genesisBlock->calculateHash();
        $genesisBlock->block_signature = 'GENESIS_SIGNATURE';
        $genesisBlock->block_size = strlen(serialize($genesisBlock->toArray()));
        
        $this->chain[] = $genesisBlock;
        $this->saveBlock($genesisBlock);
        
        Logger::info("Genesis block created");
    }
    
    private function saveBlock($block)
    {
        $blockFile = sprintf('%s/%06d.blk', $this->storageDir, $block->index);
        $serialized = serialize($block->toArray());
        $compressed = gzcompress($serialized, 9);
        
        $fp = fopen($blockFile, 'w');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $compressed);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
    }
    
    public function addBlock($block)
    {
        $latestBlock = $this->getLatestBlock();
        
        if ($latestBlock) {
            if ($block->index !== $latestBlock->index + 1) {
                Logger::error("Invalid block index: {$block->index}");
                return false;
            }
            
            if ($block->previous_hash !== $latestBlock->hash) {
                Logger::error("Invalid previous hash");
                return false;
            }
            
            if ($block->timestamp <= $latestBlock->timestamp) {
                Logger::error("Invalid timestamp");
                return false;
            }
        }
        
        if (!$block->verify()) {
            Logger::error("Block #{$block->index} verification failed");
            return false;
        }
        
        $validationResult = $this->validateBlockTransactions($block);
        if (!$validationResult['valid']) {
            Logger::error("Block #{$block->index} contains invalid transactions: " . $validationResult['error']);
            
            if (isset($validationResult['invalid_txids']) && !empty($validationResult['invalid_txids'])) {
                foreach ($validationResult['invalid_txids'] as $invalidTxid) {
                    $this->markTransactionInvalid($invalidTxid, $validationResult['error']);
                    $this->removePendingTransaction($invalidTxid);
                }
            }
            
            return false;
        }
        
        $currentSupply = $this->getTotalSupply();
        if (($currentSupply + $block->reward) > self::MAX_SUPPLY) {
            Logger::error("Block would exceed maximum supply");
            return false;
        }
        
        $this->chain[] = $block;
        $this->saveBlock($block);
        
        foreach ($block->transactions as $tx) {
            $txid = isset($tx['txid']) ? $tx['txid'] : '';
            $this->removePendingTransaction($txid);
        }
        
        $this->saveMempool();
        
        Logger::info("Block #{$block->index} added to chain with " . count($block->transactions) . " transactions");
        return true;
    }
    
    private function validateBlockTransactions($block)
    {
        $txids = [];
        $invalidTxids = [];
        $hasCoinbase = false;
        
        foreach ($block->transactions as $index => $txData) {
            $txid = isset($txData['txid']) ? $txData['txid'] : '';
            
            if (empty($txid)) {
                return ['valid' => false, 'error' => "Transaction missing txid", 'invalid_txids' => []];
            }
            
            if (isset($this->invalidTransactions[$txid])) {
                $invalidTxids[] = $txid;
                continue;
            }
            
            if (in_array($txid, $txids)) {
                $invalidTxids[] = $txid;
                return ['valid' => false, 'error' => "Duplicate TXID", 'invalid_txids' => $invalidTxids];
            }
            $txids[] = $txid;
            
            $isCoinbase = ($txData['sender'] === '0' || $txData['sender'] === 'XYZCHAIN_GENESIS_ADDRESS');
            
            if ($index === 0 && !$isCoinbase) {
                return ['valid' => false, 'error' => "First transaction must be coinbase", 'invalid_txids' => $invalidTxids];
            }
            
            if ($index > 0 && $isCoinbase) {
                return ['valid' => false, 'error' => "Coinbase can only be first", 'invalid_txids' => $invalidTxids];
            }
            
            if ($isCoinbase) {
                $hasCoinbase = true;
                continue;
            }
            
            if (empty($txData['sender']) || empty($txData['receiver'])) {
                $invalidTxids[] = $txid;
                return ['valid' => false, 'error' => "Transaction missing sender or receiver", 'invalid_txids' => $invalidTxids];
            }
            
            if ($txData['amount'] <= 0) {
                $invalidTxids[] = $txid;
                return ['valid' => false, 'error' => "Invalid amount", 'invalid_txids' => $invalidTxids];
            }
        }
        
        if (!$hasCoinbase) {
            return ['valid' => false, 'error' => "Block missing coinbase", 'invalid_txids' => $invalidTxids];
        }
        
        return ['valid' => true];
    }
    
    private function markTransactionInvalid($txid, $reason)
    {
        $this->invalidTransactions[$txid] = [
            'txid' => $txid,
            'reason' => $reason,
            'timestamp' => time()
        ];
        
        $expiry = time() - 3600;
        foreach ($this->invalidTransactions as $id => $data) {
            if ($data['timestamp'] < $expiry) {
                unset($this->invalidTransactions[$id]);
            }
        }
    }
    
    public function addTransaction($txData)
    {
        if (isset($txData['txid']) && isset($this->invalidTransactions[$txData['txid']])) {
            Logger::error("Rejected previously invalid transaction");
            return false;
        }
        
        if (!isset($txData['sender']) || empty($txData['sender'])) {
            Logger::error("Transaction missing sender");
            return false;
        }
        
        if (!isset($txData['receiver']) || empty($txData['receiver'])) {
            Logger::error("Transaction missing receiver");
            return false;
        }
        
        if (!isset($txData['amount']) || $txData['amount'] <= 0) {
            Logger::error("Transaction invalid amount");
            return false;
        }
        
        if (!isset($txData['txid']) || empty($txData['txid'])) {
            $txData['txid'] = hash('sha256', json_encode($txData) . microtime() . random_int(0, 999999));
        }
        
        if (!isset($txData['timestamp'])) {
            $txData['timestamp'] = time();
        }
        
        if (!isset($txData['nonce'])) {
            $txData['nonce'] = random_int(100000, 999999);
        }
        
        if (!isset($txData['fee'])) {
            $txData['fee'] = 0.0001;
        }
        
        if (!isset($txData['signature'])) {
            $txData['signature'] = '';
        }
        
        if (!isset($txData['public_key'])) {
            $txData['public_key'] = '';
        }
        
        if (count($this->pendingTransactions) >= self::MAX_MEMPOOL_SIZE) {
            Logger::warning("Mempool full");
            return false;
        }
        
        foreach ($this->pendingTransactions as $tx) {
            if ($tx['txid'] === $txData['txid']) {
                Logger::warning("Duplicate transaction");
                return false;
            }
        }
        
        $this->pendingTransactions[] = $txData;
        $this->saveMempool();
        Logger::info("Transaction added to mempool: " . substr($txData['txid'], 0, 16));
        return true;
    }
    
    private function removePendingTransaction($txid)
    {
        if (empty($txid)) return;
        
        foreach ($this->pendingTransactions as $key => $tx) {
            if (isset($tx['txid']) && $tx['txid'] === $txid) {
                unset($this->pendingTransactions[$key]);
                break;
            }
        }
        $this->pendingTransactions = array_values($this->pendingTransactions);
    }
    
    public function getPendingTransactions($limit = 100)
    {
        $validTxs = [];
        foreach ($this->pendingTransactions as $tx) {
            $txid = isset($tx['txid']) ? $tx['txid'] : '';
            if (!isset($this->invalidTransactions[$txid])) {
                $validTxs[] = $tx;
            }
        }
        
        usort($validTxs, function($a, $b) {
            $feeA = isset($a['fee']) ? (float)$a['fee'] : 0;
            $feeB = isset($b['fee']) ? (float)$b['fee'] : 0;
            return $feeB <=> $feeA;
        });
        
        return array_slice($validTxs, 0, min($limit, self::MAX_MEMPOOL_SIZE));
    }
    
    public function getMempoolSize()
    {
        return count($this->getPendingTransactions(10000));
    }
    
    public function getMempool()
    {
        return $this->getPendingTransactions(10000);
    }
    
    public function getLatestBlock()
    {
        return end($this->chain) ?: null;
    }
    
    public function getBlockByHeight($height)
    {
        return isset($this->chain[$height]) ? $this->chain[$height] : null;
    }
    
    public function getBlockByHash($hash)
    {
        foreach ($this->chain as $block) {
            if ($block->hash === $hash) {
                return $block;
            }
        }
        return null;
    }
    
    public function getHeight()
    {
        return count($this->chain) - 1;
    }
    
    public function getChain()
    {
        $chainData = [];
        foreach ($this->chain as $block) {
            $chainData[] = $block->toArray();
        }
        return $chainData;
    }
    
    public function getRecentBlocks($limit = 10)
    {
        $limit = min($limit, count($this->chain));
        $blocks = array_slice($this->chain, -$limit);
        return array_map(function($block) {
            return $block->toArray();
        }, array_reverse($blocks));
    }
    
    /**
     * Get transaction history for an address - FIXED: Added this missing method
     */
    public function getTransactionHistory($address, $limit = 50)
    {
        $transactions = [];
        
        foreach ($this->chain as $block) {
            foreach ($block->transactions as $tx) {
                $sender = isset($tx['sender']) ? $tx['sender'] : '';
                $receiver = isset($tx['receiver']) ? $tx['receiver'] : '';
                
                if ($sender === $address || $receiver === $address) {
                    $tx['block_height'] = $block->index;
                    $tx['confirmations'] = $this->getHeight() - $block->index + 1;
                    $transactions[] = $tx;
                }
            }
        }
        
        usort($transactions, function($a, $b) {
            $timeA = isset($a['timestamp']) ? $a['timestamp'] : 0;
            $timeB = isset($b['timestamp']) ? $b['timestamp'] : 0;
            return $timeB - $timeA;
        });
        
        return array_slice($transactions, 0, $limit);
    }
    
    /**
     * Get balance for an address
     */
    public function getBalance($address)
    {
        $balance = 0.0;
        
        foreach ($this->chain as $block) {
            foreach ($block->transactions as $tx) {
                if (isset($tx['receiver']) && $tx['receiver'] === $address) {
                    $balance += isset($tx['amount']) ? (float)$tx['amount'] : 0;
                }
                if (isset($tx['sender']) && $tx['sender'] === $address && $tx['sender'] !== '0' && $tx['sender'] !== 'XYZCHAIN_GENESIS_ADDRESS') {
                    $total = (isset($tx['amount']) ? (float)$tx['amount'] : 0) + 
                             (isset($tx['fee']) ? (float)$tx['fee'] : 0);
                    $balance -= $total;
                }
            }
            
            if ($block->miner_address === $address) {
                $balance += $block->reward;
            }
        }
        
        if ($balance < 0) {
            $balance = 0.0;
        }
        
        $locked = 0.0;
        foreach ($this->pendingTransactions as $tx) {
            $txid = isset($tx['txid']) ? $tx['txid'] : '';
            if (isset($tx['sender']) && $tx['sender'] === $address && !isset($this->invalidTransactions[$txid])) {
                $locked += (isset($tx['amount']) ? (float)$tx['amount'] : 0) + 
                           (isset($tx['fee']) ? (float)$tx['fee'] : 0);
            }
        }
        
        $spendable = $balance - $locked;
        if ($spendable < 0) {
            $spendable = 0.0;
        }
        
        return [
            'address' => $address,
            'confirmed' => round($balance, 8),
            'locked' => round($locked, 8),
            'spendable' => round($spendable, 8)
        ];
    }
    
    public function getTotalSupply()
    {
        $total = 0.0;
        foreach ($this->chain as $block) {
            $total += $block->reward;
        }
        return min($total, self::MAX_SUPPLY);
    }
    
    public function getRemainingSupply()
    {
        return max(0, self::MAX_SUPPLY - $this->getTotalSupply());
    }
    
    public function startMining($address)
    {
        $this->miningAddress = $address;
        $this->isMining = true;
        
        $stopFlag = NodeConfig::getStopMiningFlag();
        if (file_exists($stopFlag)) {
            unlink($stopFlag);
        }
        
        Logger::info("Mining started for address: " . substr($address, 0, 16));
        return true;
    }
    
    public function stopMining()
    {
        $this->isMining = false;
        file_put_contents(NodeConfig::getStopMiningFlag(), 'stop');
        Logger::info("Mining stopped");
        return true;
    }
    
    public function isMining()
    {
        $stopFlag = NodeConfig::getStopMiningFlag();
        return $this->isMining && !file_exists($stopFlag);
    }
    
    public function getMiningStatus()
    {
        $latestBlock = $this->getLatestBlock();
        
        $miningStats = [];
        $statusFile = NodeConfig::getMiningStatusFile();
        if (file_exists($statusFile)) {
            $miningStats = json_decode(file_get_contents($statusFile), true) ?: [];
        }
        
        return [
            'is_running' => $this->isMining(),
            'miner_address' => $this->miningAddress,
            'chain_height' => $this->getHeight(),
            'difficulty' => $latestBlock ? $latestBlock->difficulty : 4,
            'block_reward' => Block::calculateReward($this->getHeight() + 1),
            'total_supply' => $this->getTotalSupply(),
            'remaining_supply' => $this->getRemainingSupply(),
            'mempool_size' => $this->getMempoolSize(),
            'hash_rate' => isset($miningStats['hashrate']) ? (int)$miningStats['hashrate'] : 0
        ];
    }
    
    public function getNetworkStats()
    {
        $latestBlock = $this->getLatestBlock();
        
        return [
            'chain_height' => $this->getHeight(),
            'total_supply' => $this->getTotalSupply(),
            'remaining_supply' => $this->getRemainingSupply(),
            'mempool_size' => $this->getMempoolSize(),
            'is_mining' => $this->isMining(),
            'difficulty' => $latestBlock ? $latestBlock->difficulty : 4
        ];
    }
    
    public function validateChain()
    {
        for ($i = 1; $i < count($this->chain); $i++) {
            $currentBlock = $this->chain[$i];
            $previousBlock = $this->chain[$i - 1];
            
            if ($currentBlock->previous_hash !== $previousBlock->hash) {
                Logger::error("Chain broken at block #{$i}");
                return false;
            }
            
            if (!$currentBlock->verify()) {
                Logger::error("Block #{$i} failed verification");
                return false;
            }
        }
        
        Logger::info("Chain validation passed");
        return true;
    }
}