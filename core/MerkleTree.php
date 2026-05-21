<?php
class MerkleTree
{
    private $leaves;
    private $layers;
    
    public function __construct()
    {
        $this->leaves = [];
        $this->layers = [];
    }
    
    public function buildMerkleRoot($transactions)
    {
        if (empty($transactions)) {
            return hash('sha256', 'empty_block');
        }
        
        // Extract transaction hashes
        $this->leaves = [];
        foreach ($transactions as $tx) {
            $this->leaves[] = isset($tx['txid']) ? $tx['txid'] : hash('sha256', json_encode($tx));
        }
        
        // Sort leaves for consistency
        sort($this->leaves);
        
        // Build tree
        $this->layers = [$this->leaves];
        $currentLayer = $this->leaves;
        
        while (count($currentLayer) > 1) {
            $nextLayer = [];
            $length = count($currentLayer);
            
            for ($i = 0; $i < $length; $i += 2) {
                if ($i + 1 < $length) {
                    $hash = hash('sha256', $currentLayer[$i] . $currentLayer[$i + 1]);
                } else {
                    $hash = hash('sha256', $currentLayer[$i] . $currentLayer[$i]);
                }
                $nextLayer[] = $hash;
            }
            
            $this->layers[] = $nextLayer;
            $currentLayer = $nextLayer;
        }
        
        return $currentLayer[0];
    }
    
    public function getProof($txHash)
    {
        $index = array_search($txHash, $this->leaves);
        if ($index === false) {
            return [];
        }
        
        $proof = [];
        $currentIndex = $index;
        
        for ($layer = 0; $layer < count($this->layers) - 1; $layer++) {
            $pairIndex = ($currentIndex % 2 === 0) ? $currentIndex + 1 : $currentIndex - 1;
            
            if (isset($this->layers[$layer][$pairIndex])) {
                $proof[] = [
                    'hash' => $this->layers[$layer][$pairIndex],
                    'position' => ($currentIndex % 2 === 0) ? 'right' : 'left'
                ];
            }
            
            $currentIndex = intdiv($currentIndex, 2);
        }
        
        return $proof;
    }
    
    public static function verifyProof($txHash, $root, $proof)
    {
        $currentHash = $txHash;
        
        foreach ($proof as $step) {
            if ($step['position'] === 'right') {
                $currentHash = hash('sha256', $currentHash . $step['hash']);
            } else {
                $currentHash = hash('sha256', $step['hash'] . $currentHash);
            }
        }
        
        return $currentHash === $root;
    }
    
    public function getTreeStructure()
    {
        return [
            'leaves' => count($this->leaves),
            'height' => count($this->layers),
            'root' => isset($this->layers[count($this->layers) - 1][0]) ? 
                      $this->layers[count($this->layers) - 1][0] : null
        ];
    }
}