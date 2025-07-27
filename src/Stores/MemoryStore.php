<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Stores;

use HighPerApp\Cache\Contracts\CacheStoreInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Exceptions\ConnectionException;
use Psr\Log\LoggerInterface;

/**
 * In-memory cache store for development and testing
 */
class MemoryStore implements CacheStoreInterface
{
    private array $data = [];
    private array $metadata = [];
    private bool $connected = false;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private int $maxSize;
    private int $cleanupInterval;
    private float $lastCleanup;
    
    public function __construct(
        ConfigurationInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->maxSize = $this->parseMemorySize($config->get('memory.max_size', '100M'));
        $this->cleanupInterval = $config->get('memory.cleanup_interval', 300);
        $this->lastCleanup = microtime(true);
    }
    
    public function connect(): bool
    {
        $this->connected = true;
        $this->logger->info('Memory cache store connected');
        return true;
    }
    
    public function disconnect(): bool
    {
        $this->data = [];
        $this->metadata = [];
        $this->connected = false;
        $this->logger->info('Memory cache store disconnected');
        return true;
    }
    
    public function isConnected(): bool
    {
        return $this->connected;
    }
    
    public function getConnection(): mixed
    {
        return $this; // Memory store doesn't have external connections
    }
    
    public function ping(): bool
    {
        return $this->connected;
    }
    
    public function get(string $key): mixed
    {
        $this->ensureConnected();
        $this->periodicCleanup();
        
        if (!isset($this->data[$key])) {
            return null;
        }
        
        $metadata = $this->metadata[$key] ?? [];
        
        // Check TTL
        if (isset($metadata['expires_at']) && time() > $metadata['expires_at']) {
            $this->delete($key);
            return null;
        }
        
        // Update access time
        $this->metadata[$key]['accessed_at'] = time();
        $this->metadata[$key]['access_count'] = ($metadata['access_count'] ?? 0) + 1;
        
        return $this->data[$key];
    }
    
    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->ensureConnected();
        $this->periodicCleanup();
        
        // Check memory limit before adding
        $estimatedSize = strlen($key) + strlen($value) + 1024; // Add overhead estimate
        if ($this->getCurrentMemoryUsage() + $estimatedSize > $this->maxSize) {
            $this->evictLeastRecentlyUsed();
        }
        
        $this->data[$key] = $value;
        $this->metadata[$key] = [
            'created_at' => time(),
            'accessed_at' => time(),
            'access_count' => 0,
            'size' => strlen($value),
            'expires_at' => $ttl > 0 ? time() + $ttl : null,
        ];
        
        return true;
    }
    
    public function delete(string $key): bool
    {
        $this->ensureConnected();
        
        $existed = isset($this->data[$key]);
        
        unset($this->data[$key]);
        unset($this->metadata[$key]);
        
        return $existed;
    }
    
    public function clear(): bool
    {
        $this->ensureConnected();
        
        $this->data = [];
        $this->metadata = [];
        
        $this->logger->info('Memory cache cleared');
        return true;
    }
    
    public function getMultiple(array $keys): array
    {
        $this->ensureConnected();
        
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        
        return $results;
    }
    
    public function setMultiple(array $values, int $ttl = 0): bool
    {
        $this->ensureConnected();
        
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    public function deleteMultiple(array $keys): bool
    {
        $this->ensureConnected();
        
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    public function exists(string $key): bool
    {
        $this->ensureConnected();
        
        if (!isset($this->data[$key])) {
            return false;
        }
        
        $metadata = $this->metadata[$key] ?? [];
        
        // Check TTL
        if (isset($metadata['expires_at']) && time() > $metadata['expires_at']) {
            $this->delete($key);
            return false;
        }
        
        return true;
    }
    
    public function increment(string $key, int $value = 1): int|bool
    {
        $this->ensureConnected();
        
        $current = $this->get($key);
        
        if ($current === null) {
            $current = 0;
        } elseif (!is_numeric($current)) {
            return false;
        }
        
        $newValue = (int)$current + $value;
        $this->set($key, (string)$newValue);
        
        return $newValue;
    }
    
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }
    
    public function getStats(): array
    {
        return [
            'keys' => count($this->data),
            'memory_usage_bytes' => $this->getCurrentMemoryUsage(),
            'max_memory_bytes' => $this->maxSize,
            'memory_usage_percent' => round(($this->getCurrentMemoryUsage() / $this->maxSize) * 100, 2),
            'total_accesses' => array_sum(array_column($this->metadata, 'access_count')),
            'last_cleanup' => $this->lastCleanup,
            'cleanup_interval' => $this->cleanupInterval,
        ];
    }
    
    public function getInfo(): array
    {
        return [
            'type' => 'memory',
            'connected' => $this->connected,
            'max_size' => $this->maxSize,
            'cleanup_interval' => $this->cleanupInterval,
            'keys' => count($this->data),
            'memory_usage' => $this->getCurrentMemoryUsage(),
        ];
    }
    
    public function flushAll(): bool
    {
        return $this->clear();
    }
    
    public function getTtl(string $key): int|null
    {
        $this->ensureConnected();
        
        if (!isset($this->metadata[$key])) {
            return null;
        }
        
        $metadata = $this->metadata[$key];
        
        if (!isset($metadata['expires_at'])) {
            return -1; // No expiration
        }
        
        $ttl = $metadata['expires_at'] - time();
        return $ttl > 0 ? $ttl : 0;
    }
    
    public function touch(string $key, int $ttl): bool
    {
        $this->ensureConnected();
        
        if (!isset($this->data[$key])) {
            return false;
        }
        
        $this->metadata[$key]['expires_at'] = $ttl > 0 ? time() + $ttl : null;
        return true;
    }
    
    private function ensureConnected(): void
    {
        if (!$this->connected) {
            throw new ConnectionException('Memory store not connected');
        }
    }
    
    private function periodicCleanup(): void
    {
        $now = microtime(true);
        
        if ($now - $this->lastCleanup > $this->cleanupInterval) {
            $this->cleanup();
            $this->lastCleanup = $now;
        }
    }
    
    private function cleanup(): int
    {
        $cleaned = 0;
        $now = time();
        
        foreach ($this->metadata as $key => $metadata) {
            if (isset($metadata['expires_at']) && $now > $metadata['expires_at']) {
                unset($this->data[$key]);
                unset($this->metadata[$key]);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            $this->logger->debug('Memory cache cleanup completed', ['cleaned' => $cleaned]);
        }
        
        return $cleaned;
    }
    
    private function evictLeastRecentlyUsed(): void
    {
        if (empty($this->metadata)) {
            return;
        }
        
        // Sort by access time (oldest first)
        $sortedKeys = array_keys($this->metadata);
        usort($sortedKeys, function ($a, $b) {
            return $this->metadata[$a]['accessed_at'] <=> $this->metadata[$b]['accessed_at'];
        });
        
        // Remove 10% of keys or at least 1
        $toRemove = max(1, (int)(count($sortedKeys) * 0.1));
        
        for ($i = 0; $i < $toRemove; $i++) {
            $key = $sortedKeys[$i];
            unset($this->data[$key]);
            unset($this->metadata[$key]);
        }
        
        $this->logger->info('Memory cache eviction completed', ['removed' => $toRemove]);
    }
    
    private function getCurrentMemoryUsage(): int
    {
        $usage = 0;
        
        foreach ($this->data as $key => $value) {
            $usage += strlen($key) + strlen($value);
        }
        
        // Add metadata overhead
        $usage += count($this->metadata) * 1024; // Estimate 1KB per metadata entry
        
        return $usage;
    }
    
    private function parseMemorySize(string $size): int
    {
        $size = trim($size);
        $unit = strtoupper(substr($size, -1));
        $value = (int)substr($size, 0, -1);
        
        return match ($unit) {
            'K' => $value * 1024,
            'M' => $value * 1024 * 1024,
            'G' => $value * 1024 * 1024 * 1024,
            default => (int)$size,
        };
    }
}