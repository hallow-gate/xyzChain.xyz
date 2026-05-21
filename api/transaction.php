<?php
/**
 * Transaction API Endpoint
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../core/Transaction.php';
require_once __DIR__ . '/../core/Validator.php';
require_once __DIR__ . '/../core/Blockchain.php';
require_once __DIR__ . '/../network/Broadcast.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch (true) {
        case ($path === '/transaction/create' || preg_match('#^/api/transaction/create$#', $path)):
            if ($method === 'POST') {
                $transaction = new Transaction(
                    isset($input['sender']) ? $input['sender'] : '',
                    isset($input['receiver']) ? $input['receiver'] : '',
                    isset($input['amount']) ? (float)$input['amount'] : 0,
                    isset($input['fee']) ? (float)$input['fee'] : 0.0001
                );
                
                // Sign if private key provided
                if (isset($input['private_key'])) {
                    $transaction->sign($input['private_key']);
                }
                
                $validator = new Validator();
                if (!$validator->validateTransaction($transaction)) {
                    throw new \Exception('Invalid transaction');
                }
                
                $blockchain = new Blockchain();
                if ($blockchain->addTransaction($transaction->toArray())) {
                    Broadcast::transaction($transaction);
                    
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'txid' => $transaction->txid,
                            'status' => 'pending'
                        ]
                    ]);
                } else {
                    throw new \Exception('Transaction rejected by mempool');
                }
            }
            break;
            
        case ($path === '/transaction/receive' || preg_match('#^/api/transaction/receive$#', $path)):
            if ($method === 'POST') {
                $txData = isset($input['transaction']) ? $input['transaction'] : $input;
                $transaction = Transaction::fromArray($txData);
                
                $validator = new Validator();
                if (!$validator->validateTransaction($transaction)) {
                    throw new \Exception('Invalid transaction received');
                }
                
                $blockchain = new Blockchain();
                if ($blockchain->addTransaction($transaction->toArray())) {
                    Broadcast::transaction($transaction);
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => ['txid' => $transaction->txid]
                ]);
            }
            break;
            
        case (preg_match('#^/transaction/([a-f0-9]{64})$#', $path, $matches)):
            $txid = $matches[1];
            $blockchain = new Blockchain();
            // Search for transaction in chain and mempool
            $found = false;
            foreach ($blockchain->getChain() as $block) {
                foreach ($block['transactions'] as $tx) {
                    if ($tx['txid'] === $txid) {
                        echo json_encode([
                            'success' => true,
                            'data' => $tx
                        ]);
                        $found = true;
                        break 2;
                    }
                }
            }
            
            if (!$found) {
                foreach ($blockchain->getPendingTransactions() as $tx) {
                    if ($tx['txid'] === $txid) {
                        echo json_encode([
                            'success' => true,
                            'data' => array_merge($tx, ['status' => 'pending'])
                        ]);
                        $found = true;
                        break;
                    }
                }
            }
            
            if (!$found) {
                echo json_encode(['success' => false, 'error' => 'Transaction not found']);
            }
            break;
            
        case ($path === '/mempool' || preg_match('#^/api/mempool$#', $path)):
            $blockchain = new Blockchain();
            echo json_encode([
                'success' => true,
                'data' => [
                    'count' => $blockchain->getMempoolSize(),
                    'transactions' => $blockchain->getPendingTransactions(100)
                ]
            ]);
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Endpoint not found']);
    }
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}