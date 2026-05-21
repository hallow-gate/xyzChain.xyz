<?php
class ChainRecovery
{
    private $backupDir;
    
    public function __construct()
    {
        $this->backupDir = NodeConfig::getRecoveryDir();
        
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }
    
    public function createBackup($chain)
    {
        $backupData = [
            'timestamp' => time(),
            'height' => count($chain) - 1,
            'chain_data' => array_map(function($block) {
                return $block->toArray();
            }, $chain),
            'checksum' => ''
        ];
        
        $backupData['checksum'] = hash('sha256', serialize($backupData['chain_data']));
        
        $filename = sprintf('chain_backup_%d_%d.dat', time(), $backupData['height']);
        $filepath = $this->backupDir . '/' . $filename;
        
        $compressed = gzcompress(serialize($backupData), 9);
        
        if (file_put_contents($filepath, $compressed)) {
            Logger::info("Chain backup created: {$filename}");
            $this->cleanOldBackups(5);
            return true;
        }
        
        return false;
    }
    
    public function restoreFromBackup($filename)
    {
        $filepath = $this->backupDir . '/' . $filename;
        
        if (!file_exists($filepath)) {
            Logger::error("Backup file not found: {$filename}");
            return false;
        }
        
        $compressed = file_get_contents($filepath);
        $backupData = unserialize(gzuncompress($compressed));
        
        if (!$backupData) {
            return false;
        }
        
        // Verify checksum
        $calculatedChecksum = hash('sha256', serialize($backupData['chain_data']));
        if ($calculatedChecksum !== $backupData['checksum']) {
            Logger::error("Backup checksum mismatch");
            return false;
        }
        
        $chain = [];
        foreach ($backupData['chain_data'] as $blockData) {
            $chain[] = Block::fromArray($blockData);
        }
        
        Logger::info("Chain restored from backup: {$filename}");
        return $chain;
    }
    
    private function cleanOldBackups($keep)
    {
        $backups = glob($this->backupDir . '/chain_backup_*.dat');
        
        if (count($backups) <= $keep) {
            return;
        }
        
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        $toDelete = array_slice($backups, 0, count($backups) - $keep);
        foreach ($toDelete as $file) {
            unlink($file);
        }
    }
}