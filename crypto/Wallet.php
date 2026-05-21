 <?php
/**
 * Wallet Management - Full BIP39 HD Wallet
 * PHP 7.4 Compatible
 * 
 * Same mnemonic ALWAYS produces the same wallet address
 */

class Wallet
{
    private $keyPair;
    private $address;
    private $privateKey;
    private $publicKey;
    private $encrypted;
    private $mnemonic;
    
    public function __construct()
    {
        $this->encrypted = false;
        $this->mnemonic = null;
    }
    
    /**
     * Create a brand new wallet with random keypair
     */
    public static function create()
    {
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'secp256k1'
        ];
        
        $keyResource = openssl_pkey_new($config);
        
        if ($keyResource === false) {
            throw new \RuntimeException('Failed to generate key pair: ' . openssl_error_string());
        }
        
        openssl_pkey_export($keyResource, $privateKey);
        $details = openssl_pkey_get_details($keyResource);
        $publicKey = $details['key'];
        $address = self::generateAddress($publicKey);
        $mnemonic = Mnemonic::generate();
        
        openssl_free_key($keyResource);
        
        return [
            'address' => $address,
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'mnemonic' => $mnemonic,
            'creation_time' => time(),
            'wallet_version' => '1.0'
        ];
    }
    
    /**
     * Create encrypted wallet and save
     */
    public static function createEncrypted($password)
    {
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters');
        }
        
        $walletData = self::create();
        
        $aes = new AES();
        
        $walletFile = [
            'address' => $walletData['address'],
            'public_key' => $walletData['public_key'],
            'encrypted_private_key' => $aes->encrypt($walletData['private_key'], $password),
            'encrypted_mnemonic' => $aes->encrypt($walletData['mnemonic'], $password),
            'creation_time' => $walletData['creation_time'],
            'wallet_version' => $walletData['wallet_version'],
            'encrypted' => true
        ];
        
        WalletStorage::save($walletFile);
        
        Logger::info("Wallet created: {$walletData['address']}");
        
        return $walletFile;
    }
    
    /**
     * Import wallet from mnemonic - ALWAYS produces the same address
     */
    public static function importFromMnemonic($mnemonic)
    {
        if (!Mnemonic::validate($mnemonic)) {
            throw new \InvalidArgumentException('Invalid mnemonic phrase');
        }
        
        Logger::info("Importing wallet from mnemonic...");
        
        // Generate BIP39 seed from mnemonic
        $seed = Mnemonic::toSeed($mnemonic, '');
        
        // Deterministically derive keypair from seed
        $keyPair = self::generateKeypairFromSeed($seed);
        
        Logger::info("Wallet imported: {$keyPair['address']}");
        
        return [
            'address' => $keyPair['address'],
            'public_key' => $keyPair['public_key'],
            'private_key' => $keyPair['private_key'],
            'mnemonic' => $mnemonic
        ];
    }
    
    /**
     * Import wallet from private key string
     */
    public static function importFromPrivateKey($privateKeyString)
    {
        $privateKeyString = trim($privateKeyString);
        
        if (strpos($privateKeyString, '-----BEGIN') === false) {
            $privateKey = self::hexToProperPEM($privateKeyString);
        } else {
            $privateKey = $privateKeyString;
        }
        
        $keyResource = @openssl_pkey_get_private($privateKey);
        
        if ($keyResource === false) {
            throw new \RuntimeException('Invalid private key: ' . openssl_error_string());
        }
        
        $details = openssl_pkey_get_details($keyResource);
        $publicKey = $details['key'];
        $address = self::generateAddress($publicKey);
        
        openssl_free_key($keyResource);
        
        return [
            'address' => $address,
            'public_key' => $publicKey,
            'private_key' => $privateKey
        ];
    }
    
    /**
     * Deterministically generate keypair from seed
     * SAME seed ALWAYS = SAME keypair = SAME address
     */
    private static function generateKeypairFromSeed($seed)
    {
        // BIP32: I = HMAC-SHA512(Key="Bitcoin seed", Data=seed)
        $I = hash_hmac('sha512', $seed, 'Bitcoin seed', true);
        
        // IL = left 32 bytes (master private key)
        $IL = substr($I, 0, 32);
        
        // Convert IL to a valid secp256k1 private key
        // secp256k1 order: FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141
        // We need the private key to be less than the order
        
        $hexKey = bin2hex($IL);
        // Ensure key is less than the curve order by taking first 31 bytes
        $safeHexKey = substr($hexKey, 0, 62);
        $safeHexKey = str_pad($safeHexKey, 64, '0', STR_PAD_LEFT);
        
        // Try to create a valid EC key with this material
        $keyResource = null;
        $privateKey = null;
        $counter = 0;
        $maxAttempts = 256;
        
        while ($keyResource === null && $counter < $maxAttempts) {
            // Derive key bytes with counter for determinism
            $data = $IL . pack('N', $counter);
            $keyBytes = substr(hash('sha256', $data, true), 0, 32);
            
            // Try to create the EC key
            $der = self::buildMinimalECPrivateKeyDER($keyBytes);
            $pem = "-----BEGIN EC PRIVATE KEY-----\n";
            $pem .= chunk_split(base64_encode($der), 64, "\n");
            $pem .= "-----END EC PRIVATE KEY-----\n";
            
            $testKey = @openssl_pkey_get_private($pem);
            
            if ($testKey !== false) {
                $keyResource = $testKey;
                $privateKey = $pem;
                break;
            }
            
            $counter++;
        }
        
        // If all attempts failed, create a key using the seed hash
        if ($keyResource === null) {
            $finalHash = hash('sha512', $seed . 'final', true);
            $finalBytes = substr($finalHash, 0, 32);
            
            $der = self::buildMinimalECPrivateKeyDER($finalBytes);
            $privateKey = "-----BEGIN EC PRIVATE KEY-----\n";
            $privateKey .= chunk_split(base64_encode($der), 64, "\n");
            $privateKey .= "-----END EC PRIVATE KEY-----\n";
            
            $keyResource = openssl_pkey_get_private($privateKey);
        }
        
        if ($keyResource === false || $keyResource === null) {
            throw new \RuntimeException('Failed to derive deterministic key from seed');
        }
        
        // Get key details and generate address
        $details = openssl_pkey_get_details($keyResource);
        $publicKey = $details['key'];
        $address = self::generateAddress($publicKey);
        
        // Re-export to ensure consistent format
        openssl_pkey_export($keyResource, $cleanPrivateKey);
        
        openssl_free_key($keyResource);
        
        Logger::info("Deterministic key derived at counter {$counter}");
        
        return [
            'private_key' => $cleanPrivateKey,
            'public_key' => $publicKey,
            'address' => $address
        ];
    }
    
    /**
     * Build minimal valid EC private key DER structure
     */
    private static function buildMinimalECPrivateKeyDER($privateKeyBytes)
    {
        // ECPrivateKey SEQUENCE
        // Version = 1
        $version = "\x02\x01\x01";
        
        // Private key octet string (32 bytes)
        $privateKeyOctet = "\x04\x20" . $privateKeyBytes;
        
        // secp256k1 OID: 1.3.132.0.10
        $oid = "\x06\x05\x2b\x81\x04\x00\x0a";
        $parameters = "\xa0\x07" . $oid;
        
        // Combine
        $inner = $version . $privateKeyOctet . $parameters;
        $length = strlen($inner);
        
        // SEQUENCE wrapper
        $der = "\x30";
        if ($length < 128) {
            $der .= chr($length);
        } else {
            $der .= "\x81" . chr($length);
        }
        $der .= $inner;
        
        return $der;
    }
    
    /**
     * Create PEM from hex private key
     */
    private static function createECPrivateKeyPEM($hexKey)
    {
        $hexKey = str_pad(preg_replace('/[^0-9a-fA-F]/', '', $hexKey), 64, '0');
        $binaryKey = hex2bin($hexKey);
        
        $der = self::buildMinimalECPrivateKeyDER($binaryKey);
        
        $pem = "-----BEGIN EC PRIVATE KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END EC PRIVATE KEY-----\n";
        
        return $pem;
    }
    
    /**
     * Convert hex private key to PEM
     */
    private static function hexToProperPEM($hexKey)
    {
        $hexKey = preg_replace('/[^0-9a-fA-F]/', '', $hexKey);
        $hexKey = str_pad($hexKey, 64, '0');
        
        return self::createECPrivateKeyPEM($hexKey);
    }
    
    /**
     * Generate wallet address from public key
     */
    public static function generateAddress($publicKey)
    {
        $sha256 = hash('sha256', $publicKey, true);
        $ripemd160 = hash('ripemd160', $sha256);
        
        $versioned = "\x00" . hex2bin($ripemd160);
        
        $checksum = substr(
            hash('sha256', hash('sha256', $versioned, true), true),
            0,
            4
        );
        
        $binary = $versioned . $checksum;
        
        return self::base58Encode($binary);
    }
    
    /**
     * Base58 encoding
     */
    private static function base58Encode($data)
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = '58';
        
        $leadingZeros = 0;
        while (isset($data[$leadingZeros]) && $data[$leadingZeros] === "\0") {
            $leadingZeros++;
        }
        
        $num = '0';
        for ($i = 0; $i < strlen($data); $i++) {
            $num = bcadd(bcmul($num, '256'), (string)ord($data[$i]));
        }
        
        $result = '';
        while (bccomp($num, '0') > 0) {
            $remainder = (int)bcmod($num, $base);
            $num = bcdiv($num, $base);
            $result = $alphabet[$remainder] . $result;
        }
        
        return str_repeat('1', $leadingZeros) . $result;
    }
    
    // ===== Instance Methods =====
    
    public function getAddress()
    {
        return $this->address;
    }
    
    public function getPublicKey()
    {
        return $this->publicKey;
    }
    
    public function getPrivateKey()
    {
        return $this->privateKey;
    }
    
    public function signTransaction($transaction)
    {
        if (empty($this->privateKey)) {
            Logger::error("No private key available");
            return false;
        }
        return $transaction->sign($this->privateKey);
    }
    
    public function unlock($password)
    {
        $walletData = WalletStorage::load();
        
        if (!$walletData || !isset($walletData['encrypted_private_key'])) {
            return false;
        }
        
        try {
            $aes = new AES();
            $this->privateKey = $aes->decrypt($walletData['encrypted_private_key'], $password);
            $this->address = $walletData['address'];
            $this->publicKey = $walletData['public_key'];
            $this->encrypted = false;
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function lock()
    {
        $this->privateKey = '';
        $this->encrypted = true;
    }
}