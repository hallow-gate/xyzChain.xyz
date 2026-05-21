<?php
class Hash
{
    public static function sha256($data)
    {
        return hash('sha256', $data);
    }
    
    public static function doubleSha256($data)
    {
        return hash('sha256', hash('sha256', $data, true));
    }
    
    public static function ripemd160($data)
    {
        return hash('ripemd160', $data);
    }
    
    public static function hash160($data)
    {
        return self::ripemd160(self::sha256($data));
    }
    
    public static function sha512($data)
    {
        return hash('sha512', $data);
    }
    
    public static function generateNonce($length = 32)
    {
        return bin2hex(random_bytes($length));
    }
    
    public static function checksum($data)
    {
        return substr(self::doubleSha256($data), 0, 8);
    }
    
    public static function verifyChecksum($data, $checksum)
    {
        return self::checksum($data) === $checksum;
    }
}