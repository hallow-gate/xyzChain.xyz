<?php
declare(strict_types=1);

class Helpers
{
    public static function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    public static function formatHashRate(float $hashesPerSecond): string
    {
        $units = ['H/s', 'KH/s', 'MH/s', 'GH/s', 'TH/s'];
        $unitIndex = 0;
        
        while ($hashesPerSecond >= 1000 && $unitIndex < count($units) - 1) {
            $hashesPerSecond /= 1000;
            $unitIndex++;
        }
        
        return round($hashesPerSecond, 2) . ' ' . $units[$unitIndex];
    }
    
    public static function formatTimestamp(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    public static function base58Encode(string $data): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = strlen($alphabet);
        $bytes = array_values(unpack('C*', $data));
        
        $leadingZeros = 0;
        foreach ($bytes as $byte) {
            if ($byte === 0) {
                $leadingZeros++;
            } else {
                break;
            }
        }
        
        $decimal = '0';
        foreach ($bytes as $byte) {
            $decimal = bcadd(bcmul($decimal, '256'), (string)$byte);
        }
        
        $result = '';
        while (bccomp($decimal, '0') > 0) {
            $remainder = bcmod($decimal, (string)$base);
            $decimal = bcdiv($decimal, (string)$base);
            $result = $alphabet[(int)$remainder] . $result;
        }
        
        return str_repeat('1', $leadingZeros) . $result;
    }
    
    public static function validateAddress(string $address): bool
    {
        return preg_match('/^[1-9A-HJ-NP-Za-km-z]{26,35}$/', $address) === 1;
    }
    
    public static function validateHash(string $hash): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $hash) === 1;
    }
    
    public static function sanitizeString(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
    
    public static function getMemoryUsage(): string
    {
        return self::formatBytes(memory_get_usage(true));
    }
    
    public static function getDiskUsage(string $path): string
    {
        $total = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            $total += $file->getSize();
        }
        
        return self::formatBytes($total);
    }
}