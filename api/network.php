<?php
/**
 * Network API Endpoint
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../network/PeerManager.php';
require_once __DIR__ . '/../network/SyncManager.php';
require_once __DIR__ . '/../core/Blockchain.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$input = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch (true) {
        case ($path === '/peers' || preg_match('#^/api/peers$#', $path)):
            $peerManager = new PeerManager();
            $peers = $peerManager->getPeers(50);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'total' => $peerManager->getPeerCount(),
                    'active' => $peerManager->getActivePeerCount(),
                    'peers' => $peers
                ]
            ]);
            break;
            
        case ($path === '/peers/register' || preg_match('#^/api/peers/register$#', $path)):
            if ($method === 'POST') {
                $ip = isset($input['ip']) ? $input['ip'] : $_SERVER['REMOTE_ADDR'];
                $port = isset($input['port']) ? (int)$input['port'] : 9080;
                
                $peerManager = new PeerManager();
                if ($peerManager->addPeer($ip, $port)) {
                    echo json_encode([
                        'success' => true,
                        'data' => ['peer' => "{$ip}:{$port}"]
                    ]);
                } else {
                    throw new \Exception('Peer rejected');
                }
            }
            break;
            
        case ($path === '/network/stats' || preg_match('#^/api/network/stats$#', $path)):
            $peerManager = new PeerManager();
            $blockchain = new Blockchain();
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'active_peers' => $peerManager->getActivePeerCount(),
                    'total_peers' => $peerManager->getPeerCount(),
                    'chain_height' => $blockchain->getHeight(),
                    'mempool_size' => $blockchain->getMempoolSize(),
                    'total_supply' => $blockchain->getTotalSupply(),
                    'version' => '1.0.0-php7.4',
                    'network_id' => 'xyzchain-mainnet'
                ]
            ]);
            break;
            
        case ($path === '/sync/start' || preg_match('#^/api/sync/start$#', $path)):
            if ($method === 'POST') {
                $blockchain = new Blockchain();
                $syncManager = new SyncManager($blockchain);
                $syncManager->syncWithPeers();
                
                echo json_encode([
                    'success' => true,
                    'data' => ['status' => 'sync_initiated']
                ]);
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