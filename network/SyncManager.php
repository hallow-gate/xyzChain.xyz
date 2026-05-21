<?php
class SyncManager
{
    private $blockchain;
    private $isSyncing;
    
    public function __construct($blockchain)
    {
        $this->blockchain = $blockchain;
        $this->isSyncing = false;
    }
    
    public function syncWithPeers()
    {
        if ($this->isSyncing) {
            return;
        }
        
        $this->isSyncing = true;
        Logger::info("Starting peer synchronization...");
        
        try {
            // In a real implementation, this would connect to peers
            // and download missing blocks. For now, we just validate
            // our own chain.
            
            if ($this->blockchain->validateChain()) {
                Logger::info("Chain validation passed");
            } else {
                Logger::error("Chain validation failed - attempting repair");
                $this->repairChain();
            }
        } catch (\Exception $e) {
            Logger::error("Sync failed: " . $e->getMessage());
        }
        
        $this->isSyncing = false;
    }
    
    public function syncBlocks($fromHeight, $toHeight)
    {
        Logger::info("Syncing blocks {$fromHeight} to {$toHeight}");
        
        // In production, this would fetch blocks from peers
        // For now, we just log the attempt
        return 0;
    }
    
    private function repairChain()
    {
        // Remove invalid blocks from the end
        $chain = $this->blockchain->getChain();
        $validator = new Validator();
        
        $validChain = [];
        foreach ($chain as $block) {
            $blockObj = Block::fromArray($block);
            if ($validator->validateBlock($blockObj, end($validChain) ?: null)) {
                $validChain[] = $blockObj;
            } else {
                break;
            }
        }
        
        Logger::info("Chain repaired. Kept " . count($validChain) . " blocks");
    }
    
    public function quickSync()
    {
        Logger::info("Quick sync started");
        $this->syncWithPeers();
        return true;
    }
}