<?php
/**
 * WebSocket Server for real-time updates
 * PHP 7.4 Compatible
 */

class WebSocketServer
{
    private $socket;
    private $clients;
    private $port;
    private $running;
    
    public function __construct($port = 8081)
    {
        $this->port = $port;
        $this->clients = [];
        $this->running = false;
    }
    
    public function start()
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if (!$this->socket) {
            Logger::error("WebSocket: Failed to create socket");
            return false;
        }
        
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        if (!socket_bind($this->socket, '0.0.0.0', $this->port)) {
            Logger::error("WebSocket: Failed to bind to port {$this->port}");
            return false;
        }
        
        if (!socket_listen($this->socket)) {
            Logger::error("WebSocket: Failed to listen");
            return false;
        }
        
        socket_set_nonblock($this->socket);
        
        $this->running = true;
        Logger::info("WebSocket server started on port {$this->port}");
        
        while ($this->running) {
            $this->acceptNewConnections();
            $this->handleMessages();
            usleep(50000);
        }
    }
    
    private function acceptNewConnections()
    {
        $newSocket = @socket_accept($this->socket);
        
        if ($newSocket !== false) {
            socket_set_nonblock($newSocket);
            $this->clients[] = [
                'socket' => $newSocket,
                'handshake' => false,
                'ip' => ''
            ];
            socket_getpeername($newSocket, $ip);
            Logger::info("WebSocket: New connection from {$ip}");
        }
    }
    
    private function handleMessages()
    {
        foreach ($this->clients as $key => $client) {
            $data = @socket_read($client['socket'], 8192);
            
            if ($data === false || $data === '') {
                continue;
            }
            
            if (!$client['handshake']) {
                $this->performHandshake($key, $data);
            } else {
                $decoded = $this->decode($data);
                if ($decoded !== null && $decoded !== '') {
                    $this->processMessage($key, $decoded);
                }
            }
        }
        
        // Clean up disconnected clients
        $this->cleanupClients();
    }
    
    private function performHandshake($clientKey, $request)
    {
        if (preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $request, $matches)) {
            $key = trim($matches[1]);
            $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            
            $response = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";
            
            socket_write($this->clients[$clientKey]['socket'], $response);
            $this->clients[$clientKey]['handshake'] = true;
        }
    }
    
    private function decode($data)
    {
        if (strlen($data) < 2) {
            return null;
        }
        
        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);
        $opcode = $firstByte & 0x0F;
        
        // Only handle text frames
        if ($opcode === 8) {
            return 'close';
        }
        
        if ($opcode !== 1) {
            return null;
        }
        
        $masked = ($secondByte & 0x80) !== 0;
        $payloadLength = $secondByte & 0x7F;
        $offset = 2;
        
        if ($payloadLength === 126) {
            $payloadLength = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLength === 127) {
            $payloadLength = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }
        
        if ($masked) {
            $mask = substr($data, $offset, 4);
            $offset += 4;
            $payload = substr($data, $offset, $payloadLength);
            
            $decoded = '';
            for ($i = 0; $i < $payloadLength; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            
            return $decoded;
        }
        
        return substr($data, $offset, $payloadLength);
    }
    
    private function encode($data)
    {
        $length = strlen($data);
        $frame = chr(129); // FIN + text frame
        
        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length <= 65535) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }
        
        return $frame . $data;
    }
    
    private function processMessage($clientKey, $message)
    {
        $data = json_decode($message, true);
        if ($data === null) {
            return;
        }
        
        $type = isset($data['type']) ? $data['type'] : '';
        
        switch ($type) {
            case 'subscribe':
                $this->handleSubscribe($clientKey, $data);
                break;
            case 'ping':
                $this->send($clientKey, json_encode(['type' => 'pong']));
                break;
        }
    }
    
    private function handleSubscribe($clientKey, $data)
    {
        $channel = isset($data['channel']) ? $data['channel'] : '';
        
        switch ($channel) {
            case 'blocks':
                $blockchain = new Blockchain();
                $latest = $blockchain->getLatestBlock();
                if ($latest) {
                    $this->send($clientKey, json_encode([
                        'type' => 'block_update',
                        'data' => $latest->toArray()
                    ]));
                }
                break;
            case 'transactions':
                $blockchain = new Blockchain();
                $txs = $blockchain->getPendingTransactions(10);
                $this->send($clientKey, json_encode([
                    'type' => 'transaction_update',
                    'data' => $txs
                ]));
                break;
            case 'mining':
                $statusFile = NodeConfig::getMiningStatusFile();
                if (file_exists($statusFile)) {
                    $status = json_decode(file_get_contents($statusFile), true);
                    $this->send($clientKey, json_encode([
                        'type' => 'mining_update',
                        'data' => $status
                    ]));
                }
                break;
        }
    }
    
    private function send($clientKey, $data)
    {
        if (isset($this->clients[$clientKey])) {
            $frame = $this->encode($data);
            @socket_write($this->clients[$clientKey]['socket'], $frame);
        }
    }
    
    public function broadcast($data)
    {
        $frame = $this->encode($data);
        foreach ($this->clients as $client) {
            if ($client['handshake']) {
                @socket_write($client['socket'], $frame);
            }
        }
    }
    
    private function cleanupClients()
    {
        foreach ($this->clients as $key => $client) {
            $status = socket_get_status($client['socket']);
            if (!$status['eof']) {
                // Check by sending a ping
                $ping = chr(137) . chr(0);
                $result = @socket_write($client['socket'], $ping);
                if ($result === false) {
                    socket_close($client['socket']);
                    unset($this->clients[$key]);
                }
            } else {
                socket_close($client['socket']);
                unset($this->clients[$key]);
            }
        }
        $this->clients = array_values($this->clients);
    }
    
    public function stop()
    {
        $this->running = false;
        foreach ($this->clients as $client) {
            socket_close($client['socket']);
        }
        socket_close($this->socket);
    }
    
    public function getClientCount()
    {
        return count($this->clients);
    }
}