<?php
/**
 * Blockchain API Endpoint
 * Include this in the API server routing
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../core/Blockchain.php';
require_once __DIR__ . '/../core/Block.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$blockchain = new Blockchain();

try {
    switch (true) {
        case ($path === '/chain' || preg_match('#^/api/chain$#', $path)):
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $chain = $blockchain->getChain();
            if ($limit > 0) {
                $chain = array_slice($chain, -$limit);
            }
            echo json_encode([
                'success' => true,
                'data' => [
                    'height' => $blockchain->getHeight(),
                    'total_supply' => $blockchain->getTotalSupply(),
                    'blocks' => $chain
                ]
            ]);
            break;
            
        case ($path === '/block/latest' || preg_match('#^/api/block/latest$#', $path)):
            $block = $blockchain->getLatestBlock();
            echo json_encode([
                'success' => true,
                'data' => $block ? $block->toArray() : null
            ]);
            break;
            
        case (preg_match('#^/block/(\d+)$#', $path, $matches)):
            $height = (int)$matches[1];
            $block = $blockchain->getBlockByHeight($height);
            echo json_encode([
                'success' => true,
                'data' => $block ? $block->toArray() : null
            ]);
            break;
            
        case (preg_match('#^/block/hash/([a-f0-9]{64})$#', $path, $matches)):
            $hash = $matches[1];
            $block = $blockchain->getBlockByHash($hash);
            echo json_encode([
                'success' => true,
                'data' => $block ? $block->toArray() : null
            ]);
            break;
            
        case ($path === '/block/validate' || preg_match('#^/api/block/validate$#', $path)):
            if ($method === 'POST' && !empty($input)) {
                $block = Block::fromArray($input);
                $isValid = $block->verify();
                echo json_encode([
                    'success' => true,
                    'valid' => $isValid
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid request']);
            }
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Endpoint not found']);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}