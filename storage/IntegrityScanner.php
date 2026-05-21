<?php
/**
 * Blockchain Integrity Scanner
 * PHP 7.4 Compatible
 */

class IntegrityScanner
{
    private $blocksDir;
    private $issues;
    
    public function __construct()
    {
        $this->blocksDir = NodeConfig::getBlocksDir();
        $this->issues = [];
    }
    
    public function scan()
    {
        $this->issues = [];
        
        Logger::info("Starting blockchain integrity scan...");
        
        // Check block files
        $this->scanBlockFiles();
        
        // Verify chain continuity
        $this->verifyChainContinuity();
        
        // Check for orphaned blocks
        $this->checkOrphanedBlocks();
        
        // Verify block sizes
        $this->verifyBlockSizes();
        
        $totalIssues = count($this->issues);
        
        if ($totalIssues === 0) {
            Logger::info("Integrity scan passed - no issues found");
        } else {
            Logger::warning("Integrity scan found {$totalIssues} issue(s)");
        }
        
        return [
            'success' => $totalIssues === 0,
            'issues_count' => $totalIssues,
            'issues' => $this->issues,
            'scan_time' => time()
        ];
    }
    
    private function scanBlockFiles()
    {
        $files = glob($this->blocksDir . '/*.blk');
        
        foreach ($files as $file) {
            $filename = basename($file);
            
            // Check file permissions
            if (!is_readable($file)) {
                $this->issues[] = [
                    'type' => 'permission',
                    'file' => $filename,
                    'message' => 'Block file not readable'
                ];
                continue;
            }
            
            // Check file size
            $size = filesize($file);
            if ($size < 100 || $size > 2000000) {
                $this->issues[] = [
                    'type' => 'size',
                    'file' => $filename,
                    'message' => "Suspicious block file size: {$size} bytes"
                ];
                continue;
            }
            
            // Verify block data integrity
            $data = file_get_contents($file);
            if ($data === false || strlen($data) < 8) {
                $this->issues[] = [
                    'type' => 'corrupted',
                    'file' => $filename,
                    'message' => 'Corrupted block file'
                ];
                continue;
            }
            
            // Check checksum
            $size = unpack('V', substr($data, 0, 4))[1];
            if ($size < 0 || $size > strlen($data)) {
                $this->issues[] = [
                    'type' => 'invalid_size',
                    'file' => $filename,
                    'message' => 'Invalid block size header'
                ];
                continue;
            }
        }
    }
    
    private function verifyChainContinuity()
    {
        $blockchain = new Blockchain();
        $chain = $blockchain->getChain();
        
        if (empty($chain)) {
            $this->issues[] = [
                'type' => 'empty_chain',
                'message' => 'Blockchain is empty'
            ];
            return;
        }
        
        for ($i = 1; $i < count($chain); $i++) {
            $currentBlock = $chain[$i];
            $previousBlock = $chain[$i - 1];
            
            if ($currentBlock['previous_hash'] !== $previousBlock['hash']) {
                $this->issues[] = [
                    'type' => 'chain_break',
                    'block_index' => $i,
                    'message' => "Chain broken at block #{$currentBlock['index']}"
                ];
            }
            
            if ($currentBlock['index'] !== $previousBlock['index'] + 1) {
                $this->issues[] = [
                    'type' => 'index_gap',
                    'block_index' => $i,
                    'message' => "Index gap at block #{$currentBlock['index']}"
                ];
            }
        }
    }
    
    private function checkOrphanedBlocks()
    {
        $files = glob($this->blocksDir . '/*.blk');
        $blockchain = new Blockchain();
        $existingHeights = [];
        
        foreach ($blockchain->getChain() as $block) {
            $existingHeights[] = $block['index'];
        }
        
        foreach ($files as $file) {
            $filename = basename($file, '.blk');
            $index = (int)$filename;
            
            if (!in_array($index, $existingHeights)) {
                $this->issues[] = [
                    'type' => 'orphaned',
                    'file' => basename($file),
                    'message' => "Orphaned block file for height {$index}"
                ];
            }
        }
    }
    
    private function verifyBlockSizes()
    {
        $blockchain = new Blockchain();
        $chain = $blockchain->getChain();
        
        foreach ($chain as $block) {
            if (isset($block['block_size']) && $block['block_size'] > 2000000) {
                $this->issues[] = [
                    'type' => 'oversized_block',
                    'block_index' => $block['index'],
                    'message' => "Block #{$block['index']} exceeds maximum size"
                ];
            }
        }
    }
    
    public function repair()
    {
        $scan = $this->scan();
        $repairs = 0;
        
        foreach ($this->issues as $issue) {
            if ($issue['type'] === 'orphaned') {
                $filepath = $this->blocksDir . '/' . $issue['file'];
                if (file_exists($filepath)) {
                    // Move to quarantine
                    $quarantineDir = $this->blocksDir . '/quarantine';
                    if (!is_dir($quarantineDir)) {
                        mkdir($quarantineDir, 0755, true);
                    }
                    rename($filepath, $quarantineDir . '/' . $issue['file'] . '.quarantined');
                    $repairs++;
                }
            }
        }
        
        Logger::info("Integrity repair completed: {$repairs} issues fixed");
        
        return [
            'success' => true,
            'repairs_made' => $repairs,
            'remaining_issues' => count($this->issues) - $repairs
        ];
    }
}