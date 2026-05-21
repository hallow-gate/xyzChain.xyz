<?php
class Miner
{
    private $blockchain;
    private $isRunning;
    private $hashRate;
    private $totalHashes;
    private $startTime;
    private $minerAddress;
    private $threads;
    
    public function __construct($blockchain, $minerAddress, $threads = 1)
    {
        $this->blockchain = $blockchain;
        $this->minerAddress = $minerAddress;
        $this->threads = $threads;
        $this->isRunning = false;
        $this->totalHashes = 0;
        $this->hashRate = 0;
        $this->startTime = 0;
    }
    
    public function start()
    {
        if ($this->isRunning) {
            Logger::warning("Miner is already running");
            return false;
        }
        
        $stopFlag = NodeConfig::getStopMiningFlag();
        if (file_exists($stopFlag)) {
            unlink($stopFlag);
        }
        
        $this->isRunning = true;
        $this->totalHashes = 0;
        $this->startTime = microtime(true);
        
        Logger::info("Mining started with {$this->threads} thread(s) for {$this->minerAddress}");
        
        return true;
    }
    
    public function stop()
    {
        if (!$this->isRunning) {
            return false;
        }
        
        $stopFlag = NodeConfig::getStopMiningFlag();
        file_put_contents($stopFlag, 'stop');
        
        $this->isRunning = false;
        $elapsed = microtime(true) - $this->startTime;
        $elapsed = max($elapsed, 0.001);
        
        Logger::info("Mining stopped. Total hashes: {$this->totalHashes}, Hash rate: " . 
                     round($this->totalHashes / $elapsed) . " H/s");
        
        return true;
    }
    
    public function isRunning()
    {
        $stopFlag = NodeConfig::getStopMiningFlag();
        return $this->isRunning && !file_exists($stopFlag);
    }
    
    public function mineBlock()
    {
        if (!$this->isRunning()) {
            return null;
        }
        
        $latestBlock = $this->blockchain->getLatestBlock();
        $nextIndex = $latestBlock ? $latestBlock->index + 1 : 0;
        $previousHash = $latestBlock ? $latestBlock->hash : str_repeat('0', 64);
        
        // Get valid pending transactions only
        $pendingTxs = $this->getValidPendingTransactions();
        
        // Create coinbase transaction with ALL required fields
        $coinbase = [
            'txid' => hash('sha256', 'coinbase_' . $nextIndex . '_' . uniqid() . '_' . microtime(true)),
            'sender' => '0',  // MUST be '0' for coinbase
            'receiver' => $this->minerAddress,
            'amount' => Block::calculateReward($nextIndex),
            'fee' => 0,
            'nonce' => random_int(100000, 999999),
            'timestamp' => time(),
            'signature' => 'COINBASE',
            'public_key' => '',
            'transaction_hash' => ''
        ];
        
        // Ensure coinbase is valid
        Logger::debug("Coinbase tx: sender={$coinbase['sender']}, receiver={$coinbase['receiver']}, amount={$coinbase['amount']}");
        
        // Filter out invalid transactions before adding to block
        $validTransactions = [];
        foreach ($pendingTxs as $tx) {
            if ($this->isValidTransaction($tx)) {
                $validTransactions[] = $tx;
            } else {
                Logger::warning("Skipping invalid transaction: " . (isset($tx['txid']) ? substr($tx['txid'], 0, 16) : 'unknown'));
            }
        }
        
        // Prepend coinbase transaction (must be first)
        $transactions = array_merge([$coinbase], $validTransactions);
        
        // If no valid transactions besides coinbase, that's fine
        if (count($transactions) === 0) {
            Logger::error("No transactions available to mine");
            return null;
        }
        
        // Calculate difficulty
        $difficulty = $this->calculateDifficulty();
        
        Logger::info("Mining block #{$nextIndex} with difficulty {$difficulty}, " . (count($transactions) - 1) . " user transactions");
        
        $block = new Block($nextIndex, $previousHash, $transactions, $this->minerAddress, $difficulty);
        
        $success = $block->mine();
        
        $this->totalHashes += $block->nonce;
        
        if ($success) {
            Logger::info("Block #{$nextIndex} mined successfully! Hash: " . substr($block->hash, 0, 16));
            return $block;
        }
        
        return null;
    }
    
    /**
     * Get valid pending transactions from mempool
     */
    private function getValidPendingTransactions()
    {
        $pendingTxs = $this->blockchain->getPendingTransactions(100);
        $validTxs = [];
        
        foreach ($pendingTxs as $tx) {
            if ($this->isValidTransaction($tx)) {
                $validTxs[] = $tx;
            }
        }
        
        return $validTxs;
    }
    
    /**
     * Validate a single transaction has all required fields
     */
    private function isValidTransaction($tx)
    {
        // Check required fields
        if (!isset($tx['sender']) || empty($tx['sender'])) {
            Logger::debug("Transaction missing sender: " . (isset($tx['txid']) ? $tx['txid'] : 'unknown'));
            return false;
        }
        
        if (!isset($tx['receiver']) || empty($tx['receiver'])) {
            Logger::debug("Transaction missing receiver: " . (isset($tx['txid']) ? $tx['txid'] : 'unknown'));
            return false;
        }
        
        if (!isset($tx['amount']) || $tx['amount'] <= 0) {
            Logger::debug("Transaction invalid amount: " . (isset($tx['amount']) ? $tx['amount'] : 'missing'));
            return false;
        }
        
        // Ensure txid exists
        if (!isset($tx['txid']) || empty($tx['txid'])) {
            $tx['txid'] = hash('sha256', json_encode($tx) . microtime());
        }
        
        // Ensure timestamp exists
        if (!isset($tx['timestamp'])) {
            $tx['timestamp'] = time();
        }
        
        // Ensure nonce exists for non-coinbase
        if ($tx['sender'] !== '0' && !isset($tx['nonce'])) {
            $tx['nonce'] = random_int(100000, 999999);
        }
        
        return true;
    }
    
    private function calculateDifficulty()
    {
        $latestBlock = $this->blockchain->getLatestBlock();
        
        if (!$latestBlock || $latestBlock->index === 0) {
            return 4;
        }
        
        if ($latestBlock->index % 10 !== 0) {
            return $latestBlock->difficulty;
        }
        
        $adjustBlock = $this->blockchain->getBlockByHeight($latestBlock->index - 10);
        if (!$adjustBlock) {
            return $latestBlock->difficulty;
        }
        
        $timeTaken = $latestBlock->timestamp - $adjustBlock->timestamp;
        $expectedTime = 10 * 300;
        
        if ($timeTaken < $expectedTime / 2) {
            return min(62, $latestBlock->difficulty + 1);
        } elseif ($timeTaken > $expectedTime * 2) {
            return max(1, $latestBlock->difficulty - 1);
        }
        
        return $latestBlock->difficulty;
    }
    
    public function getHashRate()
    {
        $elapsed = microtime(true) - $this->startTime;
        return $elapsed > 0 ? $this->totalHashes / $elapsed : 0;
    }
    
    public function getStatus()
    {
        return [
            'is_running' => $this->isRunning(),
            'hash_rate' => round($this->getHashRate()),
            'total_hashes' => $this->totalHashes,
            'elapsed_seconds' => round(microtime(true) - $this->startTime),
            'miner_address' => $this->minerAddress,
            'threads' => $this->threads
        ];
    }
}