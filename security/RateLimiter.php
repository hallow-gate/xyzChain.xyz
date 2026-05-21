<?php
class RateLimiter
{
    private static $requests = [];
    private static $maxRequests = 100;
    private static $windowSize = 60;
    
    public static function isAllowed($ip)
    {
        self::cleanOldRequests();
        
        if (!isset(self::$requests[$ip])) {
            self::$requests[$ip] = [];
        }
        
        $currentTime = microtime(true);
        self::$requests[$ip][] = $currentTime;
        
        $windowStart = $currentTime - self::$windowSize;
        $recentRequests = array_filter(self::$requests[$ip], function($time) use ($windowStart) {
            return $time > $windowStart;
        });
        
        self::$requests[$ip] = array_values($recentRequests);
        
        if (count($recentRequests) > self::$maxRequests) {
            Logger::warning("Rate limit exceeded for IP: {$ip}");
            
            if (count($recentRequests) > self::$maxRequests * 10) {
                $peerManager = new PeerManager();
                $peerManager->banPeer($ip, "DDoS detected");
            }
            
            return false;
        }
        
        return true;
    }
    
    private static function cleanOldRequests()
    {
        $cutoff = microtime(true) - (self::$windowSize * 2);
        
        foreach (self::$requests as $ip => $times) {
            self::$requests[$ip] = array_values(array_filter($times, function($time) use ($cutoff) {
                return $time > $cutoff;
            }));
            
            if (empty(self::$requests[$ip])) {
                unset(self::$requests[$ip]);
            }
        }
    }
}