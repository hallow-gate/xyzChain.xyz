<?php
/**
 * Platform Constants
 * PHP 7.4 Compatible
 */

// Blockchain Constants
define('COIN_NAME', 'XYZ');
define('COIN_SYMBOL', 'XYZ');
define('MAX_SUPPLY', 800000000);
define('INITIAL_REWARD', 50);
define('HALVING_INTERVAL', 210000);
define('TARGET_BLOCK_TIME', 300);
define('DIFFICULTY_ADJUSTMENT_INTERVAL', 10);
define('MIN_DIFFICULTY', 1);
define('MAX_DIFFICULTY', 62);
define('HASH_ALGORITHM', 'sha256');
define('GENESIS_HASH', '0000000000000000000000000000000000000000000000000000000000000000');

// Network Constants
define('DEFAULT_API_PORT', 8080);
define('DEFAULT_P2P_PORT', 9080);
define('DEFAULT_WS_PORT', 8081);
define('MAX_PEERS', 50);
define('PEER_TIMEOUT', 300);
define('SYNC_INTERVAL', 60);
define('MAX_BLOCK_SIZE', 2000000);
define('MAX_TRANSACTIONS_PER_BLOCK', 1000);

// Mempool Constants
define('MEMPOOL_EXPIRY', 86400);
define('MAX_MEMPOOL_TRANSACTIONS', 10000);
define('MIN_TRANSACTION_FEE', 0.00000001);

// Security Constants
define('RATE_LIMIT_MAX_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 60);
define('BAN_DURATION', 86400);
define('MAX_FAILED_PEER_ATTEMPTS', 5);
define('MIN_PEER_REPUTATION', 0);
define('MAX_PEER_REPUTATION', 100);

// Wallet Constants
define('WALLET_ENCRYPTION_METHOD', 'aes-256-cbc');
define('MNEMONIC_ENTROPY_BITS', 256);
define('MNEMONIC_WORD_COUNT', 24);
define('PBKDF2_ITERATIONS', 100000);

// Version
define('NODE_VERSION', '1.0.0');
define('PROTOCOL_VERSION', '1');