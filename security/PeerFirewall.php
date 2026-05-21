<?php
class PeerFirewall
{
    private static $blockedNetworks = [
        '0.0.0.0/8',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '192.168.0.0/16',
        '224.0.0.0/4'
    ];
    
    public static function isConnectionAllowed($ip)
    {
        foreach (self::$blockedNetworks as $network) {
            if (self::ipInRange($ip, $network)) {
                Logger::warning("Blocked network connection: {$ip}");
                return false;
            }
        }
        
        return true;
    }
    
    public static function validatePeerData($data)
    {
        $json = json_encode($data);
        
        if (strlen($json) > 1048576) {
            Logger::error("Oversized peer data");
            return false;
        }
        
        $sqlPatterns = ['/UNION/i', '/SELECT/i', '/DROP/i', '/DELETE/i', '/INSERT/i'];
        foreach ($sqlPatterns as $pattern) {
            if (preg_match($pattern, $json)) {
                Logger::error("SQL injection attempt detected");
                return false;
            }
        }
        
        return true;
    }
    
    private static function ipInRange($ip, $range)
    {
        list($subnet, $bits) = explode('/', $range);
        
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        
        return ($ip & $mask) == ($subnet & $mask);
    }
}