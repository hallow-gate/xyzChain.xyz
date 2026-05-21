<?php
class Validator
{
    public function validateBlock($block, $previousBlock)
    {
        // Check if previous block exists
        if ($previousBlock !== null) {
            // Validate index sequence
            if ($block->index !== $previousBlock->index + 1) {
                Logger::error("Invalid block index: {$block->index}");
                return false;
            }
            
            // Validate previous hash
            if ($block->previous_hash !== $previousBlock->hash) {
                Logger::error("Invalid previous hash in block #{$block->index}");
                return false;
            }
            
            // Validate timestamp
            if ($block->timestamp <= $previousBlock->timestamp) {
                Logger::error("Invalid timestamp in block #{$block->index}");
                return false;
            }
            
            // Validate timestamp not too far in future
            if ($block->timestamp > time() + 7200) {
                Logger::error("Block timestamp too far in future #{$block->index}");
                return false;
            }
        }
        
        // Validate block structure
        if (!$block->verify()) {
            Logger::error("Block verification failed for #{$block->index}");
            return false;
        }
        
        // Validate transactions
        if (!$this->validateTransactions($block->transactions)) {
            Logger::error("Transaction validation failed in block #{$block->index}");
            return false;
        }
        
        return true;
    }
    
    public function validateChain($chain)
    {
        if (empty($chain)) {
            return false;
        }
        
        $previousBlock = null;
        $blockHashes = [];
        
        foreach ($chain as $block) {
            // Check for duplicate hashes
            if (in_array($block->hash, $blockHashes)) {
                Logger::error("Duplicate hash found: {$block->hash}");
                return false;
            }
            $blockHashes[] = $block->hash;
            
            // Validate each block
            if (!$this->validateBlock($block, $previousBlock)) {
                return false;
            }
            
            $previousBlock = $block;
        }
        
        return true;
    }
    
    public function validateTransactions($transactions)
    {
        $txids = [];
        
        foreach ($transactions as $txData) {
            $transaction = Transaction::fromArray($txData);
            
            // Verify transaction
            if (!$transaction->verify()) {
                Logger::error("Invalid transaction: {$transaction->txid}");
                return false;
            }
            
            // Check duplicate transactions
            if (in_array($transaction->txid, $txids)) {
                Logger::error("Duplicate transaction: {$transaction->txid}");
                return false;
            }
            $txids[] = $transaction->txid;
        }
        
        return true;
    }
    
    public function validateTransaction($transaction)
    {
        if (!$transaction->verify()) {
            return false;
        }
        
        if ($transaction->amount <= 0 || $transaction->amount > 1000000000) {
            return false;
        }
        
        if ($transaction->fee < 0.00000001) {
            return false;
        }
        
        return true;
    }
}