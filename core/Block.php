<?php
/**
 * Block Structure
 * XYZChain Secure - PHP 7.4 Compatible
 */

class Block
{
    public $index;
    public $timestamp;
    public $previous_hash;
    public $hash;
    public $nonce;
    public $difficulty;
    public $merkle_root;
    public $miner_address;
    public $reward;
    public $cumulative_difficulty;
    public $transactions;
    public $block_signature;
    public $block_size;
    public $version;
    
    public function __construct($index, $previousHash, $transactions, $minerAddress, $difficulty = 4)
    {
        $this->index = $index;
        $this->timestamp = time();
        $this->previous_hash = $previousHash;
        $this->transactions = $transactions;
        $this->miner_address = $minerAddress;
        $this->difficulty = $difficulty;
        $this->nonce = 0;
        $this->version = 1;
        $this->reward = self::calculateReward($index);
        $this->cumulative_difficulty = 0;
        $this->merkle_root = self::calculateMerkleRootStatic($transactions);
        $this->hash = '';
        $this->block_signature = '';
        $this->block_size = 0;
    }
    
    /**
     * Calculate the block hash
     */
    public function calculateHash()
    {
        $data = $this->index . 
                $this->timestamp . 
                $this->previous_hash . 
                $this->merkle_root . 
                $this->difficulty . 
                $this->nonce . 
                $this->miner_address .
                $this->version;
        
        return hash('sha256', $data);
    }
    
    /**
     * Mine the block - Proof of Work
     */
    public function mine()
    {
        $target = str_repeat('0', $this->difficulty);
        $startTime = microtime(true);
        $stopFlag = NodeConfig::getStopMiningFlag();
        
        // Remove stop flag if exists
        if (file_exists($stopFlag)) {
            unlink($stopFlag);
        }
        
        Logger::info("Starting mining for block #{$this->index} with difficulty {$this->difficulty}");
        
        while (true) {
            $this->hash = $this->calculateHash();
            
            // Check if hash meets difficulty target
            if (substr($this->hash, 0, $this->difficulty) === $target) {
                $this->block_signature = $this->signBlock();
                $this->block_size = strlen(serialize($this->toArray()));
                
                $miningTime = round(microtime(true) - $startTime, 2);
                Logger::info("Block #{$this->index} mined successfully in {$miningTime}s");
                Logger::info("Hash: {$this->hash}");
                Logger::info("Nonce: {$this->nonce}");
                
                return true;
            }
            
            $this->nonce++;
            
            // Periodic checks
            if ($this->nonce % 100000 === 0) {
                // Check for stop signal
                if (file_exists($stopFlag)) {
                    Logger::warning("Mining interrupted by stop signal at nonce {$this->nonce}");
                    return false;
                }
                
                // Update mining status file
                $elapsed = microtime(true) - $startTime;
                $hashRate = $elapsed > 0 ? round($this->nonce / $elapsed) : 0;
                
                file_put_contents(NodeConfig::getMiningStatusFile(), json_encode([
                    'block_index' => $this->index,
                    'nonce' => $this->nonce,
                    'hashrate' => $hashRate,
                    'elapsed' => round($elapsed),
                    'difficulty' => $this->difficulty,
                    'timestamp' => time()
                ]));
                
                Logger::debug("Mining progress: nonce={$this->nonce}, hashrate={$hashRate} H/s");
            }
            
            // Prevent integer overflow
            if ($this->nonce >= PHP_INT_MAX - 1) {
                Logger::warning("Nonce overflow, adjusting timestamp");
                $this->timestamp = time();
                $this->nonce = 0;
            }
        }
    }
    
    /**
     * Verify the block
     */
    public function verify()
    {
        // Verify hash
        $calculatedHash = $this->calculateHash();
        if ($calculatedHash !== $this->hash) {
            Logger::error("Block #{$this->index}: Hash mismatch");
            Logger::error("Calculated: {$calculatedHash}");
            Logger::error("Stored: {$this->hash}");
            return false;
        }
        
        // Verify proof of work (hash starts with required zeros)
        $target = str_repeat('0', $this->difficulty);
        if (substr($this->hash, 0, $this->difficulty) !== $target) {
            Logger::error("Block #{$this->index}: Invalid proof of work");
            return false;
        }
        
        // Verify block reward
        $expectedReward = self::calculateReward($this->index);
        if (abs($this->reward - $expectedReward) > 0.00000001) {
            Logger::error("Block #{$this->index}: Invalid block reward");
            Logger::error("Expected: {$expectedReward}, Got: {$this->reward}");
            return false;
        }
        
        // Verify merkle root
        if (!empty($this->transactions)) {
            $calculatedRoot = self::calculateMerkleRootStatic($this->transactions);
            if ($calculatedRoot !== $this->merkle_root) {
                Logger::error("Block #{$this->index}: Merkle root mismatch");
                return false;
            }
        }
        
        // Verify timestamp is reasonable
        if ($this->timestamp > time() + 7200) {
            Logger::error("Block #{$this->index}: Timestamp too far in future");
            return false;
        }
        
        return true;
    }
    
    /**
     * Sign the block
     */
    private function signBlock()
    {
        $data = $this->hash . $this->merkle_root . $this->miner_address . $this->index;
        return hash('sha256', $data . 'XYZCHAIN_BLOCK_SIGNATURE');
    }
    
    /**
     * Calculate Merkle root from transactions
     */
    public static function calculateMerkleRootStatic($transactions)
    {
        if (empty($transactions)) {
            return hash('sha256', 'empty_block');
        }
        
        // Get transaction hashes
        $hashes = [];
        foreach ($transactions as $tx) {
            if (is_array($tx)) {
                $txid = isset($tx['txid']) ? $tx['txid'] : hash('sha256', json_encode($tx));
            } elseif (is_object($tx)) {
                $txid = isset($tx->txid) ? $tx->txid : hash('sha256', serialize($tx));
            } else {
                $txid = hash('sha256', (string)$tx);
            }
            $hashes[] = $txid;
        }
        
        // Sort hashes for consistency
        sort($hashes);
        
        // Build Merkle tree
        while (count($hashes) > 1) {
            $nextLevel = [];
            $count = count($hashes);
            
            for ($i = 0; $i < $count; $i += 2) {
                if ($i + 1 < $count) {
                    $nextLevel[] = hash('sha256', $hashes[$i] . $hashes[$i + 1]);
                } else {
                    // Duplicate last element if odd number
                    $nextLevel[] = hash('sha256', $hashes[$i] . $hashes[$i]);
                }
            }
            
            $hashes = $nextLevel;
        }
        
        return $hashes[0];
    }
    
    /**
     * Calculate block reward based on height
     */
    public static function calculateReward($blockIndex)
    {
        // Genesis block special case
        if ($blockIndex === 0) {
            return 400000000; // 400 million XYZ pre-mined
        }
        
        // Calculate halvings (every 210,000 blocks)
        $halvings = intdiv($blockIndex, 210000);
        
        // Initial reward is 50 XYZ
        $reward = 50 / pow(2, $halvings);
        
        // Ensure minimum reward
        return max(0.00000001, $reward);
    }
    
    /**
     * Convert block to array
     */
    public function toArray()
    {
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
            'cumulative_difficulty' => $this->cumulative_difficulty,
            'transactions' => $this->transactions,
            'block_signature' => $this->block_signature,
            'block_size' => $this->block_size,
            'version' => $this->version
        ];
    }
    
    /**
     * Create Block from array data
     */
    public static function fromArray($data)
    {
        $block = new self(
            isset($data['index']) ? (int)$data['index'] : 0,
            isset($data['previous_hash']) ? (string)$data['previous_hash'] : '',
            isset($data['transactions']) ? $data['transactions'] : [],
            isset($data['miner_address']) ? (string)$data['miner_address'] : '',
            isset($data['difficulty']) ? (int)$data['difficulty'] : 4
        );
        
        $block->timestamp = isset($data['timestamp']) ? (int)$data['timestamp'] : time();
        $block->hash = isset($data['hash']) ? (string)$data['hash'] : '';
        $block->nonce = isset($data['nonce']) ? (int)$data['nonce'] : 0;
        $block->merkle_root = isset($data['merkle_root']) ? (string)$data['merkle_root'] : '';
        $block->reward = isset($data['reward']) ? (float)$data['reward'] : 0;
        $block->cumulative_difficulty = isset($data['cumulative_difficulty']) ? (float)$data['cumulative_difficulty'] : 0;
        $block->block_signature = isset($data['block_signature']) ? (string)$data['block_signature'] : '';
        $block->block_size = isset($data['block_size']) ? (int)$data['block_size'] : 0;
        $block->version = isset($data['version']) ? (int)$data['version'] : 1;
        
        return $block;
    }
    
    /**
     * Get block summary
     */
    public function getSummary()
    {
        return [
            'index' => $this->index,
            'hash' => substr($this->hash, 0, 16) . '...',
            'timestamp' => date('Y-m-d H:i:s', $this->timestamp),
            'transactions_count' => count($this->transactions),
            'miner' => substr($this->miner_address, 0, 10) . '...',
            'reward' => $this->reward
        ];
    }
}