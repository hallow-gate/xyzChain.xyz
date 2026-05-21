<?php
class BlockStorage
{
    private $blocksDir;
    private $indexFile;
    
    public function __construct()
    {
        $this->blocksDir = NodeConfig::getBlocksDir();
        $this->indexFile = $this->blocksDir . '/index.dat';
        
        if (!is_dir($this->blocksDir)) {
            mkdir($this->blocksDir, 0755, true);
        }
        
        if (!file_exists($this->indexFile)) {
            file_put_contents($this->indexFile, serialize([]));
        }
    }
    
    public function saveBlock($block)
    {
        $filename = sprintf('%s/%06d.blk', $this->blocksDir, $block->index);
        
        $data = $block->toArray();
        $serialized = serialize($data);
        $compressed = gzcompress($serialized, 9);
        $checksum = hash('crc32b', $compressed);
        
        $blockData = pack('V', strlen($compressed)) . $checksum . $compressed;
        
        $fp = fopen($filename, 'w');
        if ($fp === false) {
            Logger::error("Cannot open block file: $filename");
            return false;
        }
        
        if (!flock($fp, LOCK_EX)) {
            Logger::error("Cannot acquire lock on: $filename");
            fclose($fp);
            return false;
        }
        
        fwrite($fp, $blockData);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        $this->updateIndex($block->index, $block->hash);
        
        Logger::info("Block #{$block->index} saved: $filename");
        return true;
    }
    
    public function loadBlock($index)
    {
        $filename = sprintf('%s/%06d.blk', $this->blocksDir, $index);
        
        if (!file_exists($filename)) {
            return null;
        }
        
        $fp = fopen($filename, 'r');
        if ($fp === false) {
            return null;
        }
        
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return null;
        }
        
        $data = fread($fp, filesize($filename));
        flock($fp, LOCK_UN);
        fclose($fp);
        
        if (strlen($data) < 8) {
            Logger::error("Corrupted block file: $filename");
            return null;
        }
        
        $size = unpack('V', substr($data, 0, 4))[1];
        $storedChecksum = substr($data, 4, 8);
        $compressed = substr($data, 12, $size);
        
        $calculatedChecksum = hash('crc32b', $compressed);
        if ($storedChecksum !== $calculatedChecksum) {
            Logger::error("Checksum mismatch for block #$index");
            return null;
        }
        
        $serialized = gzuncompress($compressed);
        if ($serialized === false) {
            Logger::error("Failed to decompress block #$index");
            return null;
        }
        
        $data = unserialize($serialized);
        if ($data === false) {
            Logger::error("Failed to unserialize block #$index");
            return null;
        }
        
        return Block::fromArray($data);
    }
    
    public function loadAllBlocks()
    {
        $index = $this->loadIndex();
        $blocks = [];
        
        foreach ($index as $blockIndex => $blockHash) {
            $block = $this->loadBlock($blockIndex);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }
        
        return $blocks;
    }
    
    public function replaceChain($blocks)
    {
        $files = glob($this->blocksDir . '/*.blk');
        foreach ($files as $file) {
            unlink($file);
        }
        
        file_put_contents($this->indexFile, serialize([]));
        
        foreach ($blocks as $block) {
            $this->saveBlock($block);
        }
    }
    
    public function deleteBlock($index)
    {
        $filename = sprintf('%s/%06d.blk', $this->blocksDir, $index);
        if (file_exists($filename)) {
            unlink($filename);
        }
        
        $indexData = $this->loadIndex();
        unset($indexData[$index]);
        file_put_contents($this->indexFile, serialize($indexData));
    }
    
    private function updateIndex($index, $hash)
    {
        $indexData = $this->loadIndex();
        $indexData[$index] = $hash;
        ksort($indexData);
        file_put_contents($this->indexFile, serialize($indexData));
    }
    
    private function loadIndex()
    {
        $data = file_get_contents($this->indexFile);
        $index = unserialize($data);
        return $index !== false ? $index : [];
    }
}