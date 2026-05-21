<?php
/**
 * Peer Manager - Complete P2P Peer Management
 * PHP 7.4 Compatible
 */

class PeerManager
{
    private $peers;
    private $bannedPeers;
    private $peersFile;
    private $bansFile;
    
    public function __construct()
    {
        $peersDir = NodeConfig::getPeersDir();
        $this->peersFile = $peersDir . '/peers.dat';
        $this->bansFile = $peersDir . '/bans.dat';
        
        $this->peers = [];
        $this->bannedPeers = [];
        
        $this->loadPeers();
        $this->loadBans();
    }
    
    public function addPeer($ip, $port)
    {
        if ($this->isPeerBanned($ip)) {
            Logger::warning("Cannot add banned peer: {$ip}");
            return false;
        }
        
        // Don't add ourselves
        if ($ip === '127.0.0.1' || $ip === 'localhost' || $ip === '0.0.0.0') {
            // Allow localhost for testing
        }
        
        $peerKey = "{$ip}:{$port}";
        
        if (isset($this->peers[$peerKey])) {
            $this->peers[$peerKey]['last_seen'] = time();
            return true;
        }
        
        $this->peers[$peerKey] = [
            'ip' => $ip,
            'port' => $port,
            'first_seen' => time(),
            'last_seen' => time(),
            'reputation' => 100,
            'failures' => 0,
            'blocks_synced' => 0,
            'latency' => 0
        ];
        
        $this->savePeers();
        Logger::info("New peer added: {$peerKey}");
        return true;
    }
    
    public function removePeer($ip, $port)
    {
        $peerKey = "{$ip}:{$port}";
        if (isset($this->peers[$peerKey])) {
            unset($this->peers[$peerKey]);
            $this->savePeers();
            Logger::info("Peer removed: {$peerKey}");
        }
    }
    
    public function banPeer($ip, $reason = '')
    {
        $this->bannedPeers[$ip] = [
            'ip' => $ip,
            'banned_at' => time(),
            'reason' => $reason,
            'ban_expires' => time() + 86400 // 24 hours
        ];
        
        // Remove from peers list
        foreach ($this->peers as $key => $peer) {
            if ($peer['ip'] === $ip) {
                unset($this->peers[$key]);
            }
        }
        
        $this->saveBans();
        $this->savePeers();
        Logger::warning("Peer banned: {$ip} - {$reason}");
    }
    
    public function isPeerBanned($ip)
    {
        if (isset($this->bannedPeers[$ip])) {
            // Check if ban has expired
            if ($this->bannedPeers[$ip]['ban_expires'] < time()) {
                unset($this->bannedPeers[$ip]);
                $this->saveBans();
                return false;
            }
            return true;
        }
        return false;
    }
    
    public function updatePeerReputation($ip, $port, $success)
    {
        $peerKey = "{$ip}:{$port}";
        
        if (!isset($this->peers[$peerKey])) {
            return;
        }
        
        if ($success) {
            $this->peers[$peerKey]['reputation'] = min(100, $this->peers[$peerKey]['reputation'] + 1);
            $this->peers[$peerKey]['failures'] = 0;
            $this->peers[$peerKey]['last_seen'] = time();
        } else {
            $this->peers[$peerKey]['reputation'] -= 10;
            $this->peers[$peerKey]['failures']++;
            $this->peers[$peerKey]['last_seen'] = time();
            
            // Ban if reputation too low
            if ($this->peers[$peerKey]['reputation'] <= 0 || 
                $this->peers[$peerKey]['failures'] >= 5) {
                $this->banPeer($ip, "Low reputation or too many failures");
            }
        }
        
        $this->savePeers();
    }
    
    public function getPeers($limit = 50)
    {
        $peers = array_values($this->peers);
        
        // Sort by reputation (highest first), then by last seen (most recent first)
        usort($peers, function($a, $b) {
            if ($a['reputation'] !== $b['reputation']) {
                return $b['reputation'] - $a['reputation'];
            }
            return $b['last_seen'] - $a['last_seen'];
        });
        
        return array_slice($peers, 0, $limit);
    }
    
    public function getActivePeers()
    {
        $activeTimeout = time() - 300; // 5 minutes
        $active = [];
        
        foreach ($this->peers as $key => $peer) {
            if ($peer['last_seen'] > $activeTimeout && !$this->isPeerBanned($peer['ip'])) {
                $active[$key] = $peer;
            }
        }
        
        return $active;
    }
    
    public function getPeerCount()
    {
        return count($this->peers);
    }
    
    public function getActivePeerCount()
    {
        return count($this->getActivePeers());
    }
    
    /**
     * Check health of all peers by pinging them
     */
    public function checkPeerHealth()
    {
        Logger::info("Checking peer health...");
        
        foreach ($this->peers as $peerKey => $peer) {
            // Skip banned peers
            if ($this->isPeerBanned($peer['ip'])) {
                continue;
            }
            
            try {
                $response = $this->pingPeer($peer['ip'], $peer['port']);
                
                if ($response && isset($response['type']) && $response['type'] === 'pong') {
                    $this->updatePeerReputation($peer['ip'], $peer['port'], true);
                    $this->peers[$peerKey]['latency'] = isset($response['timestamp']) ? 
                        time() - $response['timestamp'] : 0;
                    
                    Logger::debug("Peer {$peer['ip']}:{$peer['port']} is healthy");
                } else {
                    $this->updatePeerReputation($peer['ip'], $peer['port'], false);
                    Logger::debug("Peer {$peer['ip']}:{$peer['port']} failed health check");
                }
            } catch (\Exception $e) {
                $this->updatePeerReputation($peer['ip'], $peer['port'], false);
                Logger::debug("Peer {$peer['ip']}:{$peer['port']} unreachable: " . $e->getMessage());
            }
        }
        
        // Remove dead peers (not seen for 1 hour)
        $deadline = time() - 3600;
        foreach ($this->peers as $key => $peer) {
            if ($peer['last_seen'] < $deadline && $peer['reputation'] < 10) {
                unset($this->peers[$key]);
            }
        }
        
        $this->savePeers();
    }
    
    /**
     * Ping a peer to check if it's alive
     */
    public function pingPeer($ip, $port)
    {
        return $this->sendMessage($ip, $port, [
            'type' => 'ping'
        ]);
    }
    
    /**
     * Send a message to a peer and get response
     */
    public function sendMessage($ip, $port, $message)
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        if (!$socket) {
            throw new \RuntimeException("Cannot create socket");
        }
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);
        
        if (!@socket_connect($socket, $ip, $port)) {
            socket_close($socket);
            throw new \RuntimeException("Connection failed to {$ip}:{$port}");
        }
        
        $data = json_encode($message);
        socket_write($socket, $data, strlen($data));
        
        $response = socket_read($socket, 65536);
        socket_close($socket);
        
        if ($response) {
            $decoded = json_decode($response, true);
            return $decoded !== null ? $decoded : null;
        }
        
        return null;
    }
    
    /**
     * Discover new peers from existing peers
     */
    public function discoverPeers()
    {
        Logger::info("Starting peer discovery...");
        
        $activePeers = $this->getActivePeers();
        
        foreach ($activePeers as $peer) {
            try {
                $response = $this->sendMessage($peer['ip'], $peer['port'], [
                    'type' => 'get_peers'
                ]);
                
                if ($response && isset($response['peers'])) {
                    foreach ($response['peers'] as $newPeer) {
                        $this->addPeer($newPeer['ip'], $newPeer['port']);
                    }
                }
            } catch (\Exception $e) {
                Logger::debug("Peer discovery failed from {$peer['ip']}: " . $e->getMessage());
            }
        }
        
        Logger::info("Peer discovery complete. Known peers: " . count($this->peers));
    }
    
    private function savePeers()
    {
        $fp = fopen($this->peersFile, 'w');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, serialize($this->peers));
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
    }
    
    private function loadPeers()
    {
        if (file_exists($this->peersFile)) {
            $data = file_get_contents($this->peersFile);
            if ($data !== false) {
                $peers = unserialize($data);
                if ($peers !== false) {
                    $this->peers = $peers;
                }
            }
        }
    }
    
    private function saveBans()
    {
        file_put_contents($this->bansFile, serialize($this->bannedPeers));
    }
    
    private function loadBans()
    {
        if (file_exists($this->bansFile)) {
            $data = file_get_contents($this->bansFile);
            if ($data !== false) {
                $bans = unserialize($data);
                if ($bans !== false) {
                    $this->bannedPeers = $bans;
                }
            }
        }
    }
    
    /**
     * Get peer statistics
     */
    public function getStats()
    {
        $activePeers = $this->getActivePeers();
        $totalReputation = 0;
        
        foreach ($activePeers as $peer) {
            $totalReputation += $peer['reputation'];
        }
        
        return [
            'total_known' => count($this->peers),
            'active' => count($activePeers),
            'banned' => count($this->bannedPeers),
            'average_reputation' => count($activePeers) > 0 ? 
                round($totalReputation / count($activePeers), 2) : 0
        ];
    }
}