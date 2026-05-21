<?php
class ECDSA
{
    private static $curveName = 'secp256k1';
    private static $keyPair = [];
    
    public static function generateKeyPair()
    {
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => self::$curveName
        ];
        
        $keyPair = openssl_pkey_new($config);
        
        if ($keyPair === false) {
            throw new \RuntimeException('Failed to generate key pair: ' . openssl_error_string());
        }
        
        openssl_pkey_export($keyPair, $privateKey);
        $details = openssl_pkey_get_details($keyPair);
        $publicKey = $details['key'];
        
        self::$keyPair = [
            'private_key' => $privateKey,
            'public_key' => $publicKey,
            'address' => self::generateAddress($publicKey)
        ];
        
        return self::$keyPair;
    }
    
    public static function generateAddress($publicKey)
    {
        $hash = hash('sha256', $publicKey);
        $ripemd = hash('ripemd160', $hash);
        $versioned = '00' . $ripemd;
        $checksum = substr(hash('sha256', hash('sha256', hex2bin($versioned))), 0, 8);
        $binary = hex2bin($versioned . $checksum);
        
        return self::base58Encode($binary);
    }
    
    public static function sign($data)
    {
        $privateKey = isset(self::$keyPair['private_key']) ? self::$keyPair['private_key'] : null;
        
        if ($privateKey === null) {
            throw new \RuntimeException('No key pair generated. Call generateKeyPair() first.');
        }
        
        return self::signWithPrivateKey($data, $privateKey);
    }
    
    public static function signWithPrivateKey($data, $privateKey)
    {
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        
        if ($privateKeyResource === false) {
            throw new \RuntimeException('Invalid private key');
        }
        
        $signature = '';
        $hash = hash('sha256', $data, true);
        
        if (!openssl_sign($hash, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signing failed: ' . openssl_error_string());
        }
        
        openssl_free_key($privateKeyResource);
        
        return $signature;
    }
    
    public static function verify($data, $signature, $publicKey)
    {
        $publicKeyResource = openssl_pkey_get_public($publicKey);
        
        if ($publicKeyResource === false) {
            return false;
        }
        
        $hash = hash('sha256', $data, true);
        $result = openssl_verify($hash, $signature, $publicKeyResource, OPENSSL_ALGO_SHA256);
        
        openssl_free_key($publicKeyResource);
        
        return $result === 1;
    }
    
    public static function getPublicKeyFromPrivate($privateKey)
    {
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        
        if ($privateKeyResource === false) {
            throw new \RuntimeException('Invalid private key');
        }
        
        $details = openssl_pkey_get_details($privateKeyResource);
        $publicKey = $details['key'];
        
        openssl_free_key($privateKeyResource);
        
        return [
            'public_key' => $publicKey,
            'address' => self::generateAddress($publicKey)
        ];
    }
    
    private static function base58Encode($data)
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = strlen($alphabet);
        $bytes = array_values(unpack('C*', $data));
        
        $leadingZeros = 0;
        foreach ($bytes as $byte) {
            if ($byte === 0) {
                $leadingZeros++;
            } else {
                break;
            }
        }
        
        $decimal = '0';
        foreach ($bytes as $byte) {
            $decimal = bcadd(bcmul($decimal, '256'), (string)$byte);
        }
        
        $result = '';
        while (bccomp($decimal, '0') > 0) {
            $remainder = bcmod($decimal, (string)$base);
            $decimal = bcdiv($decimal, (string)$base);
            $result = $alphabet[(int)$remainder] . $result;
        }
        
        return str_repeat('1', $leadingZeros) . $result;
    }
}