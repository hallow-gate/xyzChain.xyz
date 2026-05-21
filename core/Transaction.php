<?php
/**
 * Transaction Structure - PHP 7.4 Compatible
 * SECURITY HARDENED
 * 
 * - Nonce ordering enforcement
 * - Timestamp validation window (2h future, 24h past)
 * - Strict signature verification
 * - Amount bounds checking
 * - Replay attack prevention
 */

class Transaction
{
    public $txid;
    public $sender;
    public $receiver;
    public $amount;
    public $fee;
    public $nonce;
    public $timestamp;
    public $public_key;
    public $signature;
    public $transaction_hash;
    
    // Security constants
    const MAX_FUTURE_TIMESTAMP = 7200;    // 2 hours
    const MAX_PAST_TIMESTAMP = 86400;     // 24 hours
    const MIN_AMOUNT = 0.00000001;        // 1 satoshi equivalent
    const MAX_AMOUNT = 100000000;         // 100M XYZ
    const MIN_FEE = 0.00000001;
    const MAX_FEE = 1000;                 // 1000 XYZ
    const MIN_NONCE = 100000;
    const MAX_NONCE = 999999999;
    
    public function __construct($sender, $receiver, $amount, $fee = 0.0001, $nonce = null)
    {
        $this->sender = $sender;
        $this->receiver = $receiver;
        $this->amount = (float)$amount;
        $this->fee = (float)$fee;
        $this->nonce = $nonce !== null ? (int)$nonce : random_int(100000, 999999);
        $this->timestamp = time();
        $this->public_key = '';
        $this->signature = '';
        $this->transaction_hash = '';
        $this->txid = $this->calculateTxid();
    }
    
    /**
     * Calculate transaction ID from core fields
     */
    private function calculateTxid()
    {
        $data = json_encode([
            'sender' => $this->sender,
            'receiver' => $this->receiver,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'nonce' => $this->nonce,
            'timestamp' => $this->timestamp
        ]);
        
        return hash('sha256', $data);
    }
    
    /**
     * Sign transaction with private key
     * 
     * @param string $privateKey PEM formatted EC private key
     * @return bool True if signing succeeded
     */
    public function sign($privateKey)
    {
        // Build signing data
        $data = $this->txid . $this->sender . $this->receiver . 
                $this->amount . $this->fee . $this->nonce . $this->timestamp;
        
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        if ($privateKeyResource === false) {
            Logger::error("Transaction signing failed: invalid private key");
            return false;
        }
        
        $rawSignature = '';
        $hash = hash('sha256', $data, true);
        
        if (openssl_sign($hash, $rawSignature, $privateKeyResource, OPENSSL_ALGO_SHA256)) {
            $this->signature = base64_encode($rawSignature);
            
            $details = openssl_pkey_get_details($privateKeyResource);
            if ($details === false) {
                openssl_free_key($privateKeyResource);
                return false;
            }
            
            $this->public_key = $details['key'];
            $this->transaction_hash = hash('sha256', $data . $this->signature . $this->public_key);
            
            openssl_free_key($privateKeyResource);
            return true;
        }
        
        openssl_free_key($privateKeyResource);
        Logger::error("Transaction signing failed: " . openssl_error_string());
        return false;
    }
    
    /**
     * Verify transaction integrity and signature
     * 
     * @return bool True if transaction is valid
     */
    public function verify()
    {
        // === AMOUNT VALIDATION ===
        if ($this->amount <= 0) {
            Logger::error("Transaction amount must be positive: {$this->amount}");
            return false;
        }
        
        if ($this->amount < self::MIN_AMOUNT) {
            Logger::error("Transaction amount below minimum: {$this->amount}");
            return false;
        }
        
        if ($this->amount > self::MAX_AMOUNT) {
            Logger::error("Transaction amount exceeds maximum: {$this->amount}");
            return false;
        }
        
        // === FEE VALIDATION ===
        if ($this->fee < 0) {
            Logger::error("Transaction fee cannot be negative: {$this->fee}");
            return false;
        }
        
        // Fee validation for non-coinbase transactions
        if ($this->sender !== '0' && $this->sender !== 'XYZCHAIN_GENESIS_ADDRESS') {
            if ($this->fee < self::MIN_FEE) {
                Logger::error("Transaction fee below minimum: {$this->fee}");
                return false;
            }
            if ($this->fee > self::MAX_FEE) {
                Logger::error("Transaction fee exceeds maximum: {$this->fee}");
                return false;
            }
        }
        
        // === NONCE VALIDATION ===
        if ($this->nonce < self::MIN_NONCE) {
            Logger::error("Transaction nonce too low: {$this->nonce}");
            return false;
        }
        
        if ($this->nonce > self::MAX_NONCE) {
            Logger::error("Transaction nonce too high: {$this->nonce}");
            return false;
        }
        
        // === TIMESTAMP VALIDATION ===
        $now = time();
        
        // Cannot be too far in the future
        if ($this->timestamp > ($now + self::MAX_FUTURE_TIMESTAMP)) {
            Logger::error("Transaction timestamp too far in future: " . ($this->timestamp - $now) . "s ahead");
            return false;
        }
        
        // Cannot be too far in the past
        if ($this->timestamp < ($now - self::MAX_PAST_TIMESTAMP)) {
            Logger::error("Transaction timestamp too old: " . ($now - $this->timestamp) . "s ago");
            return false;
        }
        
        // === SENDER/RECEIVER VALIDATION ===
        if (empty($this->sender) || empty($this->receiver)) {
            Logger::error("Transaction missing sender or receiver");
            return false;
        }
        
        // Cannot send to self (except coinbase)
        if ($this->sender === $this->receiver && $this->sender !== '0') {
            Logger::error("Cannot send transaction to self");
            return false;
        }
        
        // === COINBASE/GENESIS CHECK ===
        if ($this->sender === '0' || 
            $this->sender === 'XYZCHAIN_GENESIS_ADDRESS' || 
            $this->signature === 'GENESIS' || 
            $this->signature === 'COINBASE') {
            
            // Coinbase transactions must have zero fee
            if ($this->fee != 0) {
                Logger::error("Coinbase transaction cannot have a fee");
                return false;
            }
            
            return true;
        }
        
        // === SIGNATURE VERIFICATION ===
        // For non-coinbase transactions, signature is MANDATORY
        if (empty($this->signature)) {
            Logger::error("Transaction missing signature");
            return false;
        }
        
        if (empty($this->public_key)) {
            Logger::error("Transaction missing public key");
            return false;
        }
        
        // Verify the signature cryptographically
        $data = $this->txid . $this->sender . $this->receiver . 
                $this->amount . $this->fee . $this->nonce . $this->timestamp;
        
        $publicKeyResource = @openssl_pkey_get_public($this->public_key);
        if ($publicKeyResource === false) {
            Logger::error("Invalid public key in transaction");
            return false;
        }
        
        $hash = hash('sha256', $data, true);
        $rawSignature = base64_decode($this->signature);
        
        if ($rawSignature === false) {
            openssl_free_key($publicKeyResource);
            Logger::error("Invalid signature encoding");
            return false;
        }
        
        $result = openssl_verify($hash, $rawSignature, $publicKeyResource, OPENSSL_ALGO_SHA256);
        
        openssl_free_key($publicKeyResource);
        
        if ($result !== 1) {
            Logger::error("Transaction signature verification failed");
            return false;
        }
        
        // === TRANSACTION HASH VERIFICATION ===
        if (!empty($this->transaction_hash)) {
            $expectedHash = hash('sha256', $data . $this->signature . $this->public_key);
            if (!hash_equals($this->transaction_hash, $expectedHash)) {
                Logger::error("Transaction hash mismatch - possible tampering");
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check if this transaction has a valid nonce sequence
     * relative to a previous transaction from the same sender
     * 
     * @param Transaction|null $previousTx The previous transaction from this sender
     * @return bool True if nonce is valid (greater than previous)
     */
    public function verifyNonceSequence($previousTx)
    {
        if ($previousTx === null) {
            // No previous transaction - any valid nonce is acceptable
            return true;
        }
        
        // Nonce must be strictly greater than previous
        if ($this->nonce <= $previousTx->nonce) {
            Logger::error("Nonce sequence violation: {$this->nonce} <= {$previousTx->nonce}");
            return false;
        }
        
        // Nonce gap should not be too large (prevent nonce exhaustion attacks)
        $maxGap = 1000000;
        if (($this->nonce - $previousTx->nonce) > $maxGap) {
            Logger::warning("Large nonce gap detected: " . ($this->nonce - $previousTx->nonce));
            // Warning only - don't reject, as it could be legitimate
        }
        
        return true;
    }
    
    /**
     * Validate timestamp relative to a reference transaction
     * 
     * @param Transaction|null $previousTx The previous transaction
     * @return bool True if timestamp is valid
     */
    public function verifyTimestampSequence($previousTx)
    {
        if ($previousTx === null) {
            return $this->verifyTimestamp();
        }
        
        // This transaction must have timestamp >= previous transaction
        if ($this->timestamp < $previousTx->timestamp) {
            Logger::error("Timestamp sequence violation: {$this->timestamp} < {$previousTx->timestamp}");
            return false;
        }
        
        return $this->verifyTimestamp();
    }
    
    /**
     * Validate timestamp is within acceptable window
     * 
     * @return bool True if timestamp is valid
     */
    public function verifyTimestamp()
    {
        $now = time();
        
        if ($this->timestamp > ($now + self::MAX_FUTURE_TIMESTAMP)) {
            return false;
        }
        
        if ($this->timestamp < ($now - self::MAX_PAST_TIMESTAMP)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get the signing payload for this transaction
     * 
     * @return string Data that was/would be signed
     */
    public function getSigningPayload()
    {
        return $this->txid . $this->sender . $this->receiver . 
               $this->amount . $this->fee . $this->nonce . $this->timestamp;
    }
    
    /**
     * Check if this is a coinbase (mining reward) transaction
     * 
     * @return bool True if this is a coinbase transaction
     */
    public function isCoinbase()
    {
        return $this->sender === '0' || 
               $this->sender === 'XYZCHAIN_GENESIS_ADDRESS' ||
               $this->signature === 'GENESIS' || 
               $this->signature === 'COINBASE';
    }
    
    /**
     * Get human-readable transaction summary
     * 
     * @return array Summary data
     */
    public function getSummary()
    {
        return [
            'txid' => substr($this->txid, 0, 16) . '...',
            'type' => $this->isCoinbase() ? 'REWARD' : 'TRANSFER',
            'sender' => $this->isCoinbase() ? 'Network' : substr($this->sender, 0, 16) . '...',
            'receiver' => substr($this->receiver, 0, 16) . '...',
            'amount' => $this->amount,
            'fee' => $this->fee,
            'timestamp' => date('Y-m-d H:i:s', $this->timestamp)
        ];
    }
    
    /**
     * Convert transaction to array for storage/API
     * 
     * @return array
     */
    public function toArray()
    {
        return [
            'txid' => $this->txid,
            'sender' => $this->sender,
            'receiver' => $this->receiver,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'nonce' => $this->nonce,
            'timestamp' => $this->timestamp,
            'public_key' => $this->public_key,
            'signature' => $this->signature,
            'transaction_hash' => $this->transaction_hash
        ];
    }
    
    /**
     * Create Transaction from array data
     * 
     * @param array $data Transaction data array
     * @return Transaction
     */
    public static function fromArray($data)
    {
        $sender = isset($data['sender']) ? $data['sender'] : 
                  (isset($data['sender_address']) ? $data['sender_address'] : '');
        $receiver = isset($data['receiver']) ? $data['receiver'] : 
                    (isset($data['receiver_address']) ? $data['receiver_address'] : '');
        $amount = isset($data['amount']) ? (float)$data['amount'] : 0;
        $fee = isset($data['fee']) ? (float)$data['fee'] : 0.0001;
        $nonce = isset($data['nonce']) ? (int)$data['nonce'] : null;
        
        $tx = new self($sender, $receiver, $amount, $fee, $nonce);
        
        $tx->txid = isset($data['txid']) ? $data['txid'] : '';
        $tx->timestamp = isset($data['timestamp']) ? (int)$data['timestamp'] : time();
        $tx->public_key = isset($data['public_key']) ? $data['public_key'] : '';
        $tx->signature = isset($data['signature']) ? $data['signature'] : '';
        $tx->transaction_hash = isset($data['transaction_hash']) ? $data['transaction_hash'] : '';
        
        return $tx;
    }
}