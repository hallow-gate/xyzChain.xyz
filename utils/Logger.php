<?php
class Logger
{
    private static $logFile;
    private static $buffer = [];
    private static $minLevel = 0;
    private static $levels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];
    
    public static function init()
    {
        $logDir = NodeConfig::getLogsDir();
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        self::$logFile = $logDir . '/node.log';
    }
    
    public static function debug($message)
    {
        self::log('DEBUG', $message);
    }
    
    public static function info($message)
    {
        self::log('INFO', $message);
    }
    
    public static function warning($message)
    {
        self::log('WARNING', $message);
    }
    
    public static function error($message)
    {
        self::log('ERROR', $message);
    }
    
    public static function critical($message)
    {
        self::log('CRITICAL', $message);
    }
    
    private static function log($level, $message)
    {
        if (self::$levels[$level] < self::$minLevel) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        echo $entry;
        
        self::$buffer[] = $entry;
        
        if (count(self::$buffer) >= 10) {
            self::flush();
        }
    }
    
    public static function flush()
    {
        if (empty(self::$buffer) || self::$logFile === null) {
            return;
        }
        
        $fp = @fopen(self::$logFile, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                foreach (self::$buffer as $entry) {
                    fwrite($fp, $entry);
                }
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
        
        self::$buffer = [];
    }
}