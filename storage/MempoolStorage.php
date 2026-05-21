<?php
class MempoolStorage
{
    private $mempoolFile;
    private $transactions;
    
    public function __construct()
    {
        $mempoolDir = NodeConfig::getMempoolDir();
        $this->mempoolFile = $mempoolDir . '/mempool.dat';
        
        if (!is_dir($mempoolDir)) {
            mkdir($mempoolDir, 0755, true);
        }
        
        $this->transactions = [];
        $this->load();
    }
    
    public function addTransaction($transaction)
    {
        $txData = $transaction instanceof Transaction ? $transaction->toArray() : $transaction;
        $txid = isset($txData['txid']) ? $txData['txid'] : '';
        
        if (empty($txid)) {
            return false;
        }
        
        // Check for duplicate
        if (isset($this->transactions[$txid])) {
            Logger::warning("Duplicate transaction in mempool: {$txid}");
            return false;
        }
        
        $this->transactions[$txid] = $txData;
        $this->save();
        
        return true;
    }
    
    public function removeTransaction($txid)
    {
        unset($this->transactions[$txid]);
        $this->save();
    }
    
    public function removeTransactions($txids)
    {
        foreach ($txids as $txid) {
            unset($this->transactions[$txid]);
        }
        $this->save();
    }
    
    public function getTransaction($txid)
    {
        return isset($this->transactions[$txid]) ? $this->transactions[$txid] : null;
    }
    
    public function getPendingTransactions($limit = 100)
    {
        $txs = array_values($this->transactions);
        
        // Sort by fee (highest first) then by timestamp
        usort($txs, function($a, $b) {
            $feeA = isset($a['fee']) ? $a['fee'] : 0;
            $feeB = isset($b['fee']) ? $b['fee'] : 0;
            
            if ($feeB !== $feeA) {
                return $feeB - $feeA;
            }
            
            $timeA = isset($a['timestamp']) ? $a['timestamp'] : 0;
            $timeB = isset($b['timestamp']) ? $b['timestamp'] : 0;
            return $timeA - $timeB;
        });
        
        return array_slice($txs, 0, $limit);
    }
    
    public function getCount()
    {
        return count($this->transactions);
    }
    
    public function cleanExpired()
    {
        $expiryTime = time() - (24 * 3600); // 24 hours
        $removed = 0;
        
        foreach ($this->transactions as $txid => $tx) {
            if (isset($tx['timestamp']) && $tx['timestamp'] < $expiryTime) {
                unset($this->transactions[$txid]);
                $removed++;
            }
        }
        
        if ($removed > 0) {
            Logger::info("Cleaned {$removed} expired transactions from mempool");
            $this->save();
        }
    }
    
    private function save()
    {
        $data = serialize([
            'transactions' => $this->transactions,
            'last_updated' => time(),
            'count' => count($this->transactions)
        ]);
        
        $compressed = gzcompress($data, 9);
        
        $fp = fopen($this->mempoolFile, 'w');
        if ($fp && flock($fp, LOCK_EX)) {
            fwrite($fp, $compressed);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
    
    private function load()
    {
        if (!file_exists($this->mempoolFile)) {
            return;
        }
        
        $fp = fopen($this->mempoolFile, 'r');
        if ($fp && flock($fp, LOCK_SH)) {
            $compressed = fread($fp, filesize($this->mempoolFile));
            flock($fp, LOCK_UN);
            fclose($fp);
            
            $data = gzuncompress($compressed);
            if ($data !== false) {
                $unserialized = unserialize($data);
                if ($unserialized !== false) {
                    $this->transactions = isset($unserialized['transactions']) ? 
                        $unserialized['transactions'] : [];
                }
            }
        }
    }
}