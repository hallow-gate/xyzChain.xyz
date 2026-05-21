<?php
class NodeConfig
{
    private static $basePath;
    
    public static function init($basePath)
    {
        self::$basePath = $basePath;
    }
    
    public static function getBasePath()
    {
        return isset(self::$basePath) ? self::$basePath : __DIR__ . '/..';
    }
    
    public static function getBlocksDir()
    {
        return self::getBasePath() . '/node/blocks';
    }
    
    public static function getMempoolDir()
    {
        return self::getBasePath() . '/node/mempool';
    }
    
    public static function getWalletDir()
    {
        return self::getBasePath() . '/node/wallet';
    }
    
    public static function getPeersDir()
    {
        return self::getBasePath() . '/node/peers';
    }
    
    public static function getLogsDir()
    {
        return self::getBasePath() . '/node/logs';
    }
    
    public static function getStopMiningFlag()
    {
        return self::getBasePath() . '/node/mining_stop.flag';
    }
    
    public static function getMiningStatusFile()
    {
        return self::getBasePath() . '/node/mining_status.json';
    }
    
    public static function getRecoveryDir()
    {
        return self::getBasePath() . '/node/recovery';
    }
}