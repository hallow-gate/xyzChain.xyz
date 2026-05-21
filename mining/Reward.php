<?php
class Reward
{
    const INITIAL_REWARD = 50;
    const HALVING_INTERVAL = 210000;
    const MAX_SUPPLY = 800000000;
    const GENESIS_SUPPLY = 400000000;
    
    public static function calculateBlockReward($blockHeight)
    {
        if ($blockHeight === 0) {
            return self::GENESIS_SUPPLY;
        }
        
        $halvings = intdiv($blockHeight, self::HALVING_INTERVAL);
        $reward = self::INITIAL_REWARD / pow(2, $halvings);
        
        return max(0.00000001, $reward);
    }
    
    public static function getTotalMinedSupply($blockchain)
    {
        $totalReward = self::GENESIS_SUPPLY;
        
        for ($i = 1; $i <= $blockchain->getHeight(); $i++) {
            $block = $blockchain->getBlockByHeight($i);
            if ($block) {
                $totalReward += $block->reward;
            }
        }
        
        return $totalReward;
    }
    
    public static function getRemainingSupply($blockchain)
    {
        return self::MAX_SUPPLY - self::getTotalMinedSupply($blockchain);
    }
    
    public static function getNextHalving($blockchain)
    {
        $currentHeight = $blockchain->getHeight();
        $nextHalving = (intdiv($currentHeight, self::HALVING_INTERVAL) + 1) * self::HALVING_INTERVAL;
        $blocksRemaining = $nextHalving - $currentHeight;
        
        return [
            'current_height' => $currentHeight,
            'next_halving_height' => $nextHalving,
            'blocks_remaining' => $blocksRemaining,
            'current_reward' => self::calculateBlockReward($currentHeight),
            'next_reward' => self::calculateBlockReward($nextHalving)
        ];
    }
}