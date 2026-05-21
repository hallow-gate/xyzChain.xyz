<?php
class AntiReplay
{
    private $seenTransactions;
    private $storageFile;
    
    public function __construct()
    {
        $this->storageFile = NodeConfig::getBasePath() . '/node/antireplay_cache.dat';
        $this->seenTransactions = [];
        $this->loadCache();
    }
    
    public function validate($transaction)
    {
        $tx = $transaction instanceof Transaction ? $transaction : Transaction::fromArray($transaction);
        $txid = $tx->txid;
        
        if (isset($this->seenTransactions[$txid])) {
            Logger::error("Replay attack detected: {$txid}");
            return false;
        }
        
        // Validate nonce uniqueness for sender
        $senderNonces = $this->getSenderNonces($tx->sender);
        if (in_array($tx->nonce, $senderNonces)) {
            Logger::error("Duplicate nonce detected: {$tx->nonce}");
            return false;
        }
        
        $this->seenTransactions[$txid] = [
            'timestamp' => time(),
            'sender' => $tx->sender,
            'nonce' => $tx->nonce
        ];
        
        if (count($this->seenTransactions) > 10000) {
            $this->cleanCache();
        }
        
        $this->saveCache();
        return true;
    }
    
    private function getSenderNonces($sender)
    {
        $nonces = [];
        foreach ($this->seenTransactions as $data) {
            if ($data['sender'] === $sender) {
                $nonces[] = $data['nonce'];
            }
        }
        return $nonces;
    }
    
    private function cleanCache()
    {
        $expiryTime = time() - 86400;
        foreach ($this->seenTransactions as $txid => $data) {
            if ($data['timestamp'] < $expiryTime) {
                unset($this->seenTransactions[$txid]);
            }
        }
    }
    
    private function saveCache()
    {
        file_put_contents($this->storageFile, serialize($this->seenTransactions));
    }
    
    private function loadCache()
    {
        if (file_exists($this->storageFile)) {
            $data = file_get_contents($this->storageFile);
            $cache = unserialize($data);
            if ($cache !== false) {
                $this->seenTransactions = $cache;
            }
        }
    }
}