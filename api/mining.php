<?php
/**
 * Mining API Endpoint
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../mining/Miner.php';
require_once __DIR__ . '/../mining/Difficulty.php';
require_once __DIR__ . '/../mining/Reward.php';
require_once __DIR__ . '/../core/Blockchain.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// Global miner instance (in production, use proper state management)
$minerFile = NodeConfig::getBasePath() . '/node/miner_instance.dat';
$miner = null;
if (file_exists($minerFile)) {
    $miner = unserialize(file_get_contents($minerFile));
}

try {
    switch (true) {
        case ($path === '/mine/start' || preg_match('#^/api/mine/start$#', $path)):
            if ($method === 'POST') {
                $address = isset($input['address']) ? $input['address'] : '';
                
                if (empty($address)) {
                    throw new \Exception('Miner address required');
                }
                
                if ($miner && $miner->isRunning()) {
                    throw new \Exception('Miner already running');
                }
                
                $blockchain = new Blockchain();
                $miner = new Miner($blockchain, $address, isset($input['threads']) ? (int)$input['threads'] : 1);
                
                if ($miner->start()) {
                    file_put_contents($minerFile, serialize($miner));
                    
                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'status' => 'started',
                            'address' => $address,
                            'difficulty' => 4
                        ]
                    ]);
                }
            }
            break;
            
        case ($path === '/mine/stop' || preg_match('#^/api/mine/stop$#', $path)):
            if ($method === 'POST') {
                if (!$miner) {
                    throw new \Exception('Miner not running');
                }
                
                if ($miner->stop()) {
                    if (file_exists($minerFile)) {
                        unlink($minerFile);
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'data' => ['status' => 'stopped']
                    ]);
                }
            }
            break;
            
        case ($path === '/mine/status' || preg_match('#^/api/mine/status$#', $path)):
            $blockchain = new Blockchain();
            $status = $miner ? $miner->getStatus() : ['is_running' => false, 'hash_rate' => 0];
            
            $latestBlock = $blockchain->getLatestBlock();
            $status['chain_height'] = $blockchain->getHeight();
            $status['difficulty'] = $latestBlock ? $latestBlock->difficulty : 4;
            $status['block_reward'] = Reward::calculateBlockReward(
                $latestBlock ? $latestBlock->index + 1 : 1
            );
            $status['total_supply'] = Reward::getTotalMinedSupply($blockchain);
            $status['remaining_supply'] = Reward::getRemainingSupply($blockchain);
            
            echo json_encode([
                'success' => true,
                'data' => $status
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