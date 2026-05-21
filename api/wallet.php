<?php
/**
 * Wallet API Endpoint
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../crypto/Wallet.php';
require_once __DIR__ . '/../crypto/Mnemonic.php';
require_once __DIR__ . '/../crypto/AES.php';
require_once __DIR__ . '/../storage/WalletStorage.php';
require_once __DIR__ . '/../core/Blockchain.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch (true) {
        case ($path === '/wallet/create' || preg_match('#^/api/wallet/create$#', $path)):
            if ($method === 'POST') {
                $password = isset($input['password']) ? $input['password'] : '';
                
                if (strlen($password) < 8) {
                    throw new \Exception('Password must be at least 8 characters');
                }
                
                $walletData = Wallet::createEncrypted($password);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'address' => $walletData['address'],
                        'public_key' => $walletData['public_key'],
                        'created' => date('Y-m-d H:i:s', $walletData['creation_time'])
                    ]
                ]);
            }
            break;
            
        case ($path === '/wallet/import' || preg_match('#^/api/wallet/import$#', $path)):
            if ($method === 'POST') {
                $type = isset($input['type']) ? $input['type'] : '';
                $password = isset($input['password']) ? $input['password'] : '';
                
                if ($type === 'mnemonic') {
                    $mnemonic = isset($input['mnemonic']) ? $input['mnemonic'] : '';
                    if (!Mnemonic::validate($mnemonic)) {
                        throw new \Exception('Invalid mnemonic phrase');
                    }
                    $walletData = Wallet::importFromMnemonic($mnemonic);
                } elseif ($type === 'private_key') {
                    $privateKey = isset($input['private_key']) ? $input['private_key'] : '';
                    $walletData = Wallet::importFromPrivateKey($privateKey);
                } else {
                    throw new \Exception('Invalid import type');
                }
                
                $aes = new AES();
                $encryptedPrivateKey = $aes->encrypt($walletData['private_key'], $password);
                
                $walletFile = [
                    'address' => $walletData['address'],
                    'public_key' => $walletData['public_key'],
                    'encrypted_private_key' => $encryptedPrivateKey,
                    'creation_time' => time(),
                    'imported' => true
                ];
                
                WalletStorage::save($walletFile);
                
                echo json_encode([
                    'success' => true,
                    'data' => ['address' => $walletData['address']]
                ]);
            }
            break;
            
        case ($path === '/wallet/balance' || preg_match('#^/api/wallet/balance$#', $path)):
            $address = isset($_GET['address']) ? $_GET['address'] : '';
            if (empty($address)) {
                throw new \Exception('Address required');
            }
            
            $blockchain = new Blockchain();
            $balance = $blockchain->getBalance($address);
            $history = $blockchain->getTransactionHistory($address, 20);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'balance' => $balance,
                    'transactions' => $history
                ]
            ]);
            break;
            
        case ($path === '/wallet/unlock' || preg_match('#^/api/wallet/unlock$#', $path)):
            if ($method === 'POST') {
                $password = isset($input['password']) ? $input['password'] : '';
                $wallet = new Wallet();
                
                if ($wallet->unlock($password)) {
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'address' => $wallet->getAddress(),
                            'status' => 'unlocked'
                        ]
                    ]);
                } else {
                    throw new \Exception('Invalid password');
                }
            }
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Endpoint not found']);
    }
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}