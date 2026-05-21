<?php
declare(strict_types=1);

// API Server Entry Point
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/Constants.php';
require_once __DIR__ . '/../includes/Helpers.php';

NodeConfig::init(dirname(__DIR__));

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Parse request
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Rate limiting
$clientIp = $_SERVER['REMOTE_ADDR'];
if (!RateLimiter::isAllowed($clientIp)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too many requests'
    ]);
    exit();
}

// Route requests
try {
    if (strpos($path, '/chain') === 0 || strpos($path, '/block') === 0) {
        require_once __DIR__ . '/blockchain.php';
    } elseif (strpos($path, '/wallet') === 0) {
        require_once __DIR__ . '/wallet.php';
    } elseif (strpos($path, '/transaction') === 0 || strpos($path, '/mempool') === 0) {
        require_once __DIR__ . '/transaction.php';
    } elseif (strpos($path, '/mine') === 0) {
        require_once __DIR__ . '/mining.php';
    } elseif (strpos($path, '/peers') === 0 || strpos($path, '/network') === 0 || strpos($path, '/sync') === 0) {
        require_once __DIR__ . '/network.php';
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Endpoint not found'
        ]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}