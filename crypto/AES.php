<?php
/**
 * AES-256-CBC Encryption with Authenticated Encryption
 * PHP 7.4 Compatible - SECURITY HARDENED
 * 
 * - Encrypt-then-MAC (EtM) scheme
 * - HMAC-SHA256 authentication
 * - Timing-safe comparison
 * - PBKDF2-SHA512 with 210,000 iterations
 * - Proper exception handling
 * - Memory cleanup
 */

class AES
{
    private $method = 'aes-256-cbc';
    private $keyLength = 32;
    private $ivLength = 16;
    private $hmacLength = 32;  // SHA-256 produces 32 bytes
    private $hmacAlgorithm = 'sha256';
    
    // Minimum data overhead: IV (16) + HMAC (32) = 48 bytes
    const MIN_ENCRYPTED_LENGTH = 48;
    
    /**
     * Encrypt data with AES-256-CBC + HMAC-SHA256 authentication
     * 
     * Format: base64( IV | CIPHERTEXT | HMAC )
     * 
     * @param string $data Plaintext to encrypt
     * @param string $password Password or derived key
     * @return string Base64-encoded encrypted payload
     * @throws AESException On encryption failure
     */
    public function encrypt($data, $password)
    {
        // Validate input
        if (empty($data)) {
            throw new AESException('Cannot encrypt empty data', AESException::ERR_EMPTY_DATA);
        }
        
        if (empty($password)) {
            throw new AESException('Encryption password cannot be empty', AESException::ERR_EMPTY_KEY);
        }
        
        // Derive encryption key
        $key = $this->deriveKey($password);
        
        // Generate cryptographically secure random IV
        try {
            $iv = random_bytes($this->ivLength);
        } catch (\Exception $e) {
            throw new AESException(
                'Failed to generate IV: ' . $e->getMessage(),
                AESException::ERR_IV_GENERATION
            );
        }
        
        // Encrypt the data
        $encrypted = openssl_encrypt(
            $data,
            $this->method,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($encrypted === false) {
            $this->clearSensitiveData($key);
            throw new AESException(
                'Encryption failed: ' . openssl_error_string(),
                AESException::ERR_ENCRYPTION_FAILED
            );
        }
        
        // Calculate HMAC over IV + ciphertext (Encrypt-then-MAC)
        $hmac = hash_hmac($this->hmacAlgorithm, $iv . $encrypted, $key, true);
        
        // Clear key from memory
        $this->clearSensitiveData($key);
        
        // Return base64 encoded: IV | Ciphertext | HMAC
        return base64_encode($iv . $encrypted . $hmac);
    }
    
    /**
     * Decrypt and verify data
     * 
     * @param string $encryptedData Base64-encoded encrypted payload
     * @param string $password Password or derived key
     * @return string Decrypted plaintext
     * @throws AESException On decryption or integrity failure
     */
    public function decrypt($encryptedData, $password)
    {
        // Validate input
        if (empty($encryptedData)) {
            throw new AESException('Cannot decrypt empty data', AESException::ERR_EMPTY_DATA);
        }
        
        if (empty($password)) {
            throw new AESException('Decryption password cannot be empty', AESException::ERR_EMPTY_KEY);
        }
        
        // Decode base64
        $data = base64_decode($encryptedData, true);
        
        if ($data === false) {
            throw new AESException(
                'Invalid base64 encoding',
                AESException::ERR_INVALID_FORMAT
            );
        }
        
        // Validate minimum length (IV + at least 1 byte ciphertext + HMAC)
        if (strlen($data) < self::MIN_ENCRYPTED_LENGTH) {
            throw new AESException(
                'Encrypted data too short: ' . strlen($data) . ' bytes (minimum ' . self::MIN_ENCRYPTED_LENGTH . ')',
                AESException::ERR_DATA_TOO_SHORT
            );
        }
        
        // Extract components
        $iv = substr($data, 0, $this->ivLength);
        $hmac = substr($data, -$this->hmacLength);
        $ciphertext = substr($data, $this->ivLength, -$this->hmacLength);
        
        // Validate ciphertext is not empty
        if (strlen($ciphertext) === 0) {
            throw new AESException(
                'Ciphertext is empty after extraction',
                AESException::ERR_DATA_TOO_SHORT
            );
        }
        
        // Derive key
        $key = $this->deriveKey($password);
        
        // Verify HMAC with timing-safe comparison
        $calculatedHmac = hash_hmac($this->hmacAlgorithm, $iv . $ciphertext, $key, true);
        
        if (!hash_equals($hmac, $calculatedHmac)) {
            $this->clearSensitiveData($key);
            throw new AESException(
                'Authentication failed: data has been tampered with or wrong password',
                AESException::ERR_INTEGRITY_CHECK_FAILED
            );
        }
        
        // Decrypt
        $decrypted = openssl_decrypt(
            $ciphertext,
            $this->method,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        // Clear key from memory
        $this->clearSensitiveData($key);
        
        if ($decrypted === false) {
            throw new AESException(
                'Decryption failed: ' . openssl_error_string(),
                AESException::ERR_DECRYPTION_FAILED
            );
        }
        
        return $decrypted;
    }
    
    /**
     * Derive encryption key from password using PBKDF2-SHA512
     * 210,000 iterations for brute-force resistance
     * 
     * @param string $password The password to derive from
     * @return string 32-byte derived key
     */
    private function deriveKey($password)
    {
        return hash_pbkdf2(
            'sha512',
            $password,
            'XYZCHAIN_SECURE_SALT_2024',
            210000,
            $this->keyLength,
            true
        );
    }
    
    /**
     * Clear sensitive data from memory
     * Overwrites with zeros before unsetting
     * 
     * @param string &$data Reference to sensitive data
     */
    private function clearSensitiveData(&$data)
    {
        if (is_string($data) && strlen($data) > 0) {
            // Overwrite with zeros
            $data = str_repeat("\0", strlen($data));
            $data = null;
        }
    }
    
    /**
     * Securely compare two strings in constant time
     * 
     * @param string $knownString The known string
     * @param string $userString The user-supplied string
     * @return bool True if strings are equal
     */
    public static function secureCompare($knownString, $userString)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($knownString, $userString);
        }
        
        // Fallback for PHP < 5.6 (shouldn't be needed for 7.4)
        $diff = strlen($knownString) ^ strlen($userString);
        $minLen = min(strlen($knownString), strlen($userString));
        
        for ($i = 0; $i < $minLen; $i++) {
            $diff |= ord($knownString[$i]) ^ ord($userString[$i]);
        }
        
        return $diff === 0;
    }
}

/**
 * AES Exception with error codes
 */
class AESException extends \RuntimeException
{
    const ERR_EMPTY_DATA = 1;
    const ERR_EMPTY_KEY = 2;
    const ERR_IV_GENERATION = 3;
    const ERR_ENCRYPTION_FAILED = 4;
    const ERR_INVALID_FORMAT = 5;
    const ERR_DATA_TOO_SHORT = 6;
    const ERR_INTEGRITY_CHECK_FAILED = 7;
    const ERR_DECRYPTION_FAILED = 8;
    
    private $errorCode;
    
    public function __construct($message, $errorCode = 0, $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
        $this->errorCode = $errorCode;
    }
    
    public function getErrorCode()
    {
        return $this->errorCode;
    }
    
    /**
     * Get user-friendly error message
     */
    public function getUserMessage()
    {
        switch ($this->errorCode) {
            case self::ERR_INTEGRITY_CHECK_FAILED:
                return 'Authentication failed. The data may have been tampered with or the password is incorrect.';
            case self::ERR_INVALID_FORMAT:
                return 'Invalid encrypted data format. The file may be corrupted.';
            case self::ERR_DATA_TOO_SHORT:
                return 'Encrypted data is incomplete or corrupted.';
            case self::ERR_DECRYPTION_FAILED:
                return 'Failed to decrypt data. The password may be incorrect.';
            default:
                return 'An encryption error occurred. Please try again.';
        }
    }
}