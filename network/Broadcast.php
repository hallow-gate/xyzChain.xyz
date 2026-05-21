<?php
class Broadcast
{
    public static function transaction($transaction)
    {
        $txData = $transaction instanceof Transaction ? $transaction->toArray() : $transaction;
        Logger::info("Broadcasting transaction: " . substr($txData['txid'], 0, 16));
        
        // In production, send to all connected peers
        $peerManager = new PeerManager();
        $activePeers = $peerManager->getActivePeers();
        
        foreach ($activePeers as $peer) {
            self::sendToPeer($peer, [
                'type' => 'new_transaction',
                'transaction' => $txData
            ]);
        }
    }
    
    public static function block($block)
    {
        $blockData = $block instanceof Block ? $block->toArray() : $block;
        Logger::info("Broadcasting block #" . $blockData['index']);
        
        $peerManager = new PeerManager();
        $activePeers = $peerManager->getActivePeers();
        
        foreach ($activePeers as $peer) {
            self::sendToPeer($peer, [
                'type' => 'new_block',
                'block' => $blockData
            ]);
        }
    }
    
    private static function sendToPeer($peer, $message)
    {
        try {
            $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$socket) return;
            
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 3, 'usec' => 0]);
            
            if (@socket_connect($socket, $peer['ip'], $peer['port'])) {
                $data = json_encode($message);
                socket_write($socket, $data, strlen($data));
            }
            
            socket_close($socket);
        } catch (\Exception $e) {
            // Silently fail - peer might be offline
        }
    }
}