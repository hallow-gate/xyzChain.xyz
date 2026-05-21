<?php
class ChainState
{
    private $balances;
    private $transactionHistory;
    private $stateFile;
    private $lastProcessedBlock;
    
    public function __construct()
    {
        $this->balances = [];
        $this->transactionHistory = [];
        $this->lastProcessedBlock = -1;
        $this->stateFile = NodeConfig::getBasePath() . '/node/chain_state.dat';
        $this->loadState();
    }
    
    public function updateChainState($chain)
    {
        for ($i = $this->lastProcessedBlock + 1; $i < count($chain); $i++) {
            $block = $chain[$i];
            $this->processBlock($block);
            $this->lastProcessedBlock = $i;
        }
        
        $this->saveState();
    }
    
    private function processBlock($block)
    {
        foreach ($block->transactions as $txData) {
            $this->processTransaction($txData);
        }
        
        // Add mining reward
        if (isset($block->miner_address)) {
            $this->addBalance($block->miner_address, $block->reward);
        }
    }
    
    private function processTransaction($txData)
    {
        $tx = Transaction::fromArray($txData);
        
        // Process outputs
        if ($tx->sender !== '0' && $tx->sender !== 'XYZCHAIN_GENESIS_ADDRESS') {
            $this->subtractBalance($tx->sender, $tx->amount + $tx->fee);
        }
        
        // Process inputs
        $this->addBalance($tx->receiver, $tx->amount);
        
        // Record transaction
        $this->recordTransaction($tx);
    }
    
    public function getAddressBalance($address)
    {
        $balance = isset($this->balances[$address]) ? $this->balances[$address] : 0;
        
        return [
            'address' => $address,
            'confirmed' => $balance,
            'locked' => 0,
            'spendable' => $balance
        ];
    }
    
    public function getAddressTransactions($address, $limit = 50)
    {
        if (!isset($this->transactionHistory[$address])) {
            return [];
        }
        
        return array_slice(array_reverse($this->transactionHistory[$address]), 0, $limit);
    }
    
    public function getTotalSupply()
    {
        return array_sum($this->balances);
    }
    
    public function getRichestAddresses($limit = 10)
    {
        arsort($this->balances);
        return array_slice($this->balances, 0, $limit, true);
    }
    
    private function addBalance($address, $amount)
    {
        if (!isset($this->balances[$address])) {
            $this->balances[$address] = 0;
        }
        $this->balances[$address] += $amount;
    }
    
    private function subtractBalance($address, $amount)
    {
        if (!isset($this->balances[$address])) {
            $this->balances[$address] = 0;
        }
        $this->balances[$address] -= $amount;
    }
    
    private function recordTransaction($tx)
    {
        $record = $tx->toArray();
        
        if ($tx->sender !== '0' && $tx->sender !== 'XYZCHAIN_GENESIS_ADDRESS') {
            if (!isset($this->transactionHistory[$tx->sender])) {
                $this->transactionHistory[$tx->sender] = [];
            }
            $this->transactionHistory[$tx->sender][] = $record;
        }
        
        if (!isset($this->transactionHistory[$tx->receiver])) {
            $this->transactionHistory[$tx->receiver] = [];
        }
        $this->transactionHistory[$tx->receiver][] = $record;
    }
    
    private function saveState()
    {
        $state = [
            'balances' => $this->balances,
            'transaction_history' => $this->transactionHistory,
            'last_processed_block' => $this->lastProcessedBlock,
            'updated_at' => time()
        ];
        
        $compressed = gzcompress(serialize($state), 9);
        file_put_contents($this->stateFile, $compressed);
    }
    
    private function loadState()
    {
        if (!file_exists($this->stateFile)) {
            return;
        }
        
        $compressed = file_get_contents($this->stateFile);
        if ($compressed !== false) {
            $data = gzuncompress($compressed);
            if ($data !== false) {
                $state = unserialize($data);
                if ($state !== false) {
                    $this->balances = isset($state['balances']) ? $state['balances'] : [];
                    $this->transactionHistory = isset($state['transaction_history']) ? $state['transaction_history'] : [];
                    $this->lastProcessedBlock = isset($state['last_processed_block']) ? $state['last_processed_block'] : -1;
                }
            }
        }
    }
}