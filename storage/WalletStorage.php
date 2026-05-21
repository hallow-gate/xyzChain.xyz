<?php
class WalletStorage
{
    private static $walletFile;
    
    public static function init()
    {
        $walletDir = NodeConfig::getWalletDir();
        self::$walletFile = $walletDir . '/wallet.dat';
        
        if (!is_dir($walletDir)) {
            mkdir($walletDir, 0755, true);
        }
    }
    
    public static function save($walletData)
    {
        if (!self::$walletFile) {
            self::init();
        }
        
        $serialized = serialize($walletData);
        $encrypted = self::obfuscate($serialized);
        
        $fp = fopen(self::$walletFile, 'w');
        if ($fp === false) {
            Logger::error("Cannot open wallet file for writing");
            return false;
        }
        
        if (!flock($fp, LOCK_EX)) {
            Logger::error("Cannot acquire lock on wallet file");
            fclose($fp);
            return false;
        }
        
        chmod(self::$walletFile, 0600);
        fwrite($fp, $encrypted);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        Logger::info("Wallet saved successfully");
        return true;
    }
    
    public static function load()
    {
        if (!self::$walletFile) {
            self::init();
        }
        
        if (!file_exists(self::$walletFile)) {
            return null;
        }
        
        $fp = fopen(self::$walletFile, 'r');
        if ($fp === false) {
            return null;
        }
        
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return null;
        }
        
        $encrypted = fread($fp, filesize(self::$walletFile));
        flock($fp, LOCK_UN);
        fclose($fp);
        
        $serialized = self::deobfuscate($encrypted);
        $data = unserialize($serialized);
        
        return $data !== false ? $data : null;
    }
    
    private static function obfuscate($data)
    {
        $key = self::getObfuscationKey();
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    private static function deobfuscate($data)
    {
        $key = self::getObfuscationKey();
        $decoded = base64_decode($data);
        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }
    
    private static function getObfuscationKey()
    {
        $machineId = php_uname('n') . php_uname('m') . __DIR__;
        return hash('sha256', $machineId, true);
    }
}