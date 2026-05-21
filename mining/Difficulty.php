<?php
class Difficulty
{
    const TARGET_BLOCK_TIME = 300;
    const ADJUSTMENT_INTERVAL = 10;
    const MIN_DIFFICULTY = 1;
    const MAX_DIFFICULTY = 62;
    
    public static function calculate($blockchain)
    {
        $latestBlock = $blockchain->getLatestBlock();
        
        if ($latestBlock === null || $latestBlock->index === 0) {
            return 4;
        }
        
        if ($latestBlock->index % self::ADJUSTMENT_INTERVAL !== 0) {
            return $latestBlock->difficulty;
        }
        
        $adjustmentBlock = $blockchain->getBlockByHeight(
            $latestBlock->index - self::ADJUSTMENT_INTERVAL
        );
        
        if ($adjustmentBlock === null) {
            return $latestBlock->difficulty;
        }
        
        $timeTaken = $latestBlock->timestamp - $adjustmentBlock->timestamp;
        $expectedTime = self::TARGET_BLOCK_TIME * self::ADJUSTMENT_INTERVAL;
        
        if ($timeTaken < $expectedTime / 2) {
            $newDifficulty = $latestBlock->difficulty + 1;
        } elseif ($timeTaken > $expectedTime * 2) {
            $newDifficulty = max(self::MIN_DIFFICULTY, $latestBlock->difficulty - 1);
        } else {
            $newDifficulty = $latestBlock->difficulty;
        }
        
        $newDifficulty = max(self::MIN_DIFFICULTY, min(self::MAX_DIFFICULTY, $newDifficulty));
        
        if ($newDifficulty !== $latestBlock->difficulty) {
            Logger::info("Difficulty adjusted from {$latestBlock->difficulty} to {$newDifficulty}");
        }
        
        return $newDifficulty;
    }
    
    public static function getTarget($difficulty)
    {
        return str_repeat('0', $difficulty);
    }
    
    public static function validateDifficulty($difficulty, $hash)
    {
        $target = self::getTarget($difficulty);
        return substr($hash, 0, $difficulty) === $target;
    }
}