<?php
class AntiTamper
{
    public function validateBlockIntegrity($block)
    {
        // Verify block structure
        if (!$this->verifyStructureIntegrity($block)) {
            return false;
        }
        
        // Verify hash integrity
        if (!$this->verifyHashIntegrity($block)) {
            return false;
        }
        
        return true;
    }
    
    private function verifyStructureIntegrity($block)
    {
        $requiredFields = ['index', 'timestamp', 'previous_hash', 'hash', 'nonce', 
                          'difficulty', 'merkle_root', 'miner_address', 'reward'];
        
        $blockArray = $block->toArray();
        foreach ($requiredFields as $field) {
            if (!isset($blockArray[$field])) {
                Logger::error("Missing required field: {$field}");
                return false;
            }
        }
        
        if ($block->index < 0 || $block->timestamp < 0 || $block->nonce < 0) {
            return false;
        }
        
        return true;
    }
    
    private function verifyHashIntegrity($block)
    {
        if (strlen($block->hash) !== 64) {
            return false;
        }
        
        if (!ctype_xdigit($block->hash)) {
            return false;
        }
        
        $calculatedHash = $block->calculateHash();
        if ($calculatedHash !== $block->hash) {
            return false;
        }
        
        return true;
    }
    
    public function detectChainTampering($chain)
    {
        $violations = [];
        
        for ($i = 1; $i < count($chain); $i++) {
            $currentBlock = $chain[$i];
            $previousBlock = $chain[$i - 1];
            
            if ($currentBlock->previous_hash !== $previousBlock->hash) {
                $violations[] = [
                    'type' => 'hash_chain_break',
                    'block_index' => $i
                ];
            }
            
            if ($currentBlock->index !== $previousBlock->index + 1) {
                $violations[] = [
                    'type' => 'index_sequence_break',
                    'block_index' => $i
                ];
            }
        }
        
        return $violations;
    }
}