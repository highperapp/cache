<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Stores;

use HighPerApp\Cache\Contracts\CacheStoreInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Exceptions\ConnectionException;
use Psr\Log\LoggerInterface;

/**
 * File-based cache store for persistent caching
 */
class FileStore implements CacheStoreInterface
{
    private string $cacheDir;
    private int $permissions;
    private bool $connected = false;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private int $cleanupInterval;
    private float $lastCleanup;
    private string $keyPrefix;
    
    public function __construct(
        ConfigurationInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->cacheDir = $config->get('file.cache_dir', sys_get_temp_dir() . '/highper_cache');
        $this->permissions = (int)$config->get('file.permissions', 0755);
        $this->cleanupInterval = $config->get('file.cleanup_interval', 300);
        $this->keyPrefix = $config->get('file.key_prefix', 'cache_');
        $this->lastCleanup = microtime(true);
    }
    
    public function connect(): bool
    {
        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, $this->permissions, true)) {
                throw new ConnectionException("Failed to create cache directory: {$this->cacheDir}");
            }
        }
        
        if (!is_writable($this->cacheDir)) {
            throw new ConnectionException("Cache directory is not writable: {$this->cacheDir}");
        }
        
        $this->connected = true;
        $this->logger->info('File cache store connected', ['cache_dir' => $this->cacheDir]);
        
        return true;
    }
    
    public function disconnect(): bool
    {
        $this->connected = false;
        $this->logger->info('File cache store disconnected');
        return true;
    }
    
    public function isConnected(): bool
    {
        return $this->connected && is_dir($this->cacheDir) && is_writable($this->cacheDir);
    }
    
    public function getConnection(): mixed
    {
        return $this->cacheDir;
    }
    
    public function ping(): bool
    {
        return $this->isConnected();
    }
    
    public function get(string $key): mixed
    {
        $this->ensureConnected();
        $this->periodicCleanup();
        
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        try {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                return null;
            }
            
            $data = unserialize($contents);
            
            // Check TTL
            if (isset($data['expires_at']) && time() > $data['expires_at']) {
                $this->delete($key);
                return null;
            }
            
            // Update access time
            $data['accessed_at'] = time();
            $data['access_count'] = ($data['access_count'] ?? 0) + 1;
            file_put_contents($filePath, serialize($data), LOCK_EX);
            
            return $data['value'];
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read cache file', [
                'key' => $key,
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->ensureConnected();
        $this->periodicCleanup();
        
        $filePath = $this->getFilePath($key);
        $dir = dirname($filePath);
        
        if (!is_dir($dir)) {
            if (!mkdir($dir, $this->permissions, true)) {
                $this->logger->error('Failed to create cache subdirectory', ['dir' => $dir]);
                return false;
            }
        }
        
        $data = [
            'value' => $value,
            'created_at' => time(),
            'accessed_at' => time(),
            'access_count' => 0,
            'expires_at' => $ttl > 0 ? time() + $ttl : null,
        ];
        
        try {
            $result = file_put_contents($filePath, serialize($data), LOCK_EX);
            
            if ($result !== false) {
                chmod($filePath, $this->permissions & 0666); // Remove execute bit for files
                return true;
            }
            
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to write cache file', [
                'key' => $key,
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function delete(string $key): bool
    {
        $this->ensureConnected();
        
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        try {
            return unlink($filePath);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete cache file', [
                'key' => $key,
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function clear(): bool
    {
        $this->ensureConnected();
        
        try {
            $this->clearDirectory($this->cacheDir);
            $this->logger->info('File cache cleared');
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clear file cache', ['error' => $e->getMessage()]);
            return false;
        }
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
        
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        try {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                return false;
            }
            
            $data = unserialize($contents);
            
            // Check TTL
            if (isset($data['expires_at']) && time() > $data['expires_at']) {
                $this->delete($key);
                return false;
            }
            
            return true;
        } catch (\Throwable) {
            return false;
        }
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
        $fileCount = 0;
        $totalSize = 0;
        $expiredCount = 0;
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $fileCount++;
                    $totalSize += $file->getSize();
                    
                    // Check if expired
                    try {
                        $contents = file_get_contents($file->getPathname());
                        if ($contents !== false) {
                            $data = unserialize($contents);
                            if (isset($data['expires_at']) && time() > $data['expires_at']) {
                                $expiredCount++;
                            }
                        }
                    } catch (\Throwable) {
                        // Ignore errors reading individual files
                    }
                }
            }
        } catch (\Throwable) {
            // Directory might not exist or be accessible
        }
        
        return [
            'files' => $fileCount,
            'total_size_bytes' => $totalSize,
            'expired_files' => $expiredCount,
            'cache_dir' => $this->cacheDir,
            'disk_free_bytes' => disk_free_space($this->cacheDir),
            'last_cleanup' => $this->lastCleanup,
            'cleanup_interval' => $this->cleanupInterval,
        ];
    }
    
    public function getInfo(): array
    {
        return [
            'type' => 'file',
            'connected' => $this->connected,
            'cache_dir' => $this->cacheDir,
            'permissions' => decoct($this->permissions),
            'cleanup_interval' => $this->cleanupInterval,
            'key_prefix' => $this->keyPrefix,
            'writable' => is_writable($this->cacheDir),
        ];
    }
    
    public function flushAll(): bool
    {
        return $this->clear();
    }
    
    public function getTtl(string $key): int|null
    {
        $this->ensureConnected();
        
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        try {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                return null;
            }
            
            $data = unserialize($contents);
            
            if (!isset($data['expires_at'])) {
                return -1; // No expiration
            }
            
            $ttl = $data['expires_at'] - time();
            return $ttl > 0 ? $ttl : 0;
        } catch (\Throwable) {
            return null;
        }
    }
    
    public function touch(string $key, int $ttl): bool
    {
        $this->ensureConnected();
        
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        try {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                return false;
            }
            
            $data = unserialize($contents);
            $data['expires_at'] = $ttl > 0 ? time() + $ttl : null;
            
            return file_put_contents($filePath, serialize($data), LOCK_EX) !== false;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to touch cache file', [
                'key' => $key,
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    private function ensureConnected(): void
    {
        if (!$this->connected) {
            throw new ConnectionException('File store not connected');
        }
    }
    
    private function getFilePath(string $key): string
    {
        $safeKey = $this->keyPrefix . hash('sha256', $key);
        
        // Create subdirectories based on first few characters for better performance
        $subDir = substr($safeKey, 0, 2) . '/' . substr($safeKey, 2, 2);
        
        return $this->cacheDir . '/' . $subDir . '/' . $safeKey . '.cache';
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
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'cache') {
                    try {
                        $contents = file_get_contents($file->getPathname());
                        if ($contents !== false) {
                            $data = unserialize($contents);
                            if (isset($data['expires_at']) && time() > $data['expires_at']) {
                                unlink($file->getPathname());
                                $cleaned++;
                            }
                        }
                    } catch (\Throwable) {
                        // If we can't read the file, it's probably corrupted - delete it
                        try {
                            unlink($file->getPathname());
                            $cleaned++;
                        } catch (\Throwable) {
                            // Ignore deletion errors
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('File cache cleanup encountered error', ['error' => $e->getMessage()]);
        }
        
        if ($cleaned > 0) {
            $this->logger->debug('File cache cleanup completed', ['cleaned' => $cleaned]);
        }
        
        return $cleaned;
    }
    
    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
    }
}