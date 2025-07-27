<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Session;

use HighPerApp\Cache\Contracts\SessionStoreInterface;
use HighPerApp\Cache\Contracts\CacheInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use Psr\Log\LoggerInterface;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * High-performance session handler using cache backend
 */
class SessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private CacheInterface $cache;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private string $prefix;
    private int $maxLifetime;
    private int $lockTimeout;
    private array $locks = [];
    private array $stats = [
        'reads' => 0,
        'writes' => 0,
        'destroys' => 0,
        'gc_calls' => 0,
        'gc_collected' => 0,
        'locks' => 0,
        'lock_timeouts' => 0,
    ];
    
    public function __construct(
        CacheInterface $cache,
        ConfigurationInterface $config,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->config = $config;
        $this->logger = $logger;
        $this->prefix = $config->get('session.prefix', 'sess:');
        $this->maxLifetime = $config->get('session.lifetime', 3600);
        $this->lockTimeout = $config->get('session.lock_timeout', 30);
    }
    
    /**
     * Register this handler with PHP's session system
     */
    public function register(): bool
    {
        $result = session_set_save_handler($this, true);
        
        if ($result) {
            $this->logger->info('Session handler registered successfully');
        } else {
            $this->logger->error('Failed to register session handler');
        }
        
        return $result;
    }
    
    public function open(string $path, string $name): bool
    {
        $this->logger->debug('Session handler opened', [
            'path' => $path,
            'name' => $name
        ]);
        
        return true;
    }
    
    public function close(): bool
    {
        // Release all locks
        foreach ($this->locks as $sessionId => $lockTime) {
            $this->unlock($sessionId);
        }
        
        $this->logger->debug('Session handler closed');
        
        return true;
    }
    
    public function read(string $sessionId): string
    {
        $this->stats['reads']++;
        
        try {
            $key = $this->getKey($sessionId);
            
            // Try to acquire lock
            if (!$this->lock($sessionId)) {
                $this->logger->warning('Failed to acquire session lock', [
                    'session_id' => $sessionId
                ]);
                return '';
            }
            
            $data = $this->cache->get($key);
            
            if ($data === null) {
                $this->logger->debug('Session not found', [
                    'session_id' => $sessionId
                ]);
                return '';
            }
            
            // Check if session data is a structured array
            if (is_array($data) && isset($data['data'])) {
                return $data['data'];
            }
            
            return is_string($data) ? $data : '';
            
        } catch (\Throwable $e) {
            $this->logger->error('Session read failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }
    
    public function write(string $sessionId, string $data): bool
    {
        $this->stats['writes']++;
        
        try {
            $key = $this->getKey($sessionId);
            
            // Prepare session data with metadata
            $sessionData = [
                'data' => $data,
                'created_at' => time(),
                'updated_at' => time(),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ];
            
            // If we have an existing session, preserve creation time
            $existing = $this->cache->get($key);
            if (is_array($existing) && isset($existing['created_at'])) {
                $sessionData['created_at'] = $existing['created_at'];
            }
            
            $result = $this->cache->set($key, $sessionData, $this->maxLifetime);
            
            if ($result) {
                $this->logger->debug('Session written successfully', [
                    'session_id' => $sessionId,
                    'data_length' => strlen($data)
                ]);
            } else {
                $this->logger->error('Failed to write session', [
                    'session_id' => $sessionId
                ]);
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->logger->error('Session write failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function destroy(string $sessionId): bool
    {
        $this->stats['destroys']++;
        
        try {
            $key = $this->getKey($sessionId);
            
            // Remove session data
            $result = $this->cache->delete($key);
            
            // Remove lock if exists
            $this->unlock($sessionId);
            
            if ($result) {
                $this->logger->debug('Session destroyed successfully', [
                    'session_id' => $sessionId
                ]);
            } else {
                $this->logger->warning('Failed to destroy session', [
                    'session_id' => $sessionId
                ]);
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->logger->error('Session destroy failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function gc(int $maxLifetime): int
    {
        $this->stats['gc_calls']++;
        
        try {
            // For cache-based sessions, TTL handles expiration automatically
            // We just need to clean up any orphaned locks
            $cleaned = $this->cleanupOrphanedLocks();
            
            $this->stats['gc_collected'] += $cleaned;
            
            $this->logger->debug('Session garbage collection completed', [
                'max_lifetime' => $maxLifetime,
                'cleaned_locks' => $cleaned
            ]);
            
            return $cleaned;
            
        } catch (\Throwable $e) {
            $this->logger->error('Session garbage collection failed', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    public function updateTimestamp(string $sessionId, string $data): bool
    {
        try {
            $key = $this->getKey($sessionId);
            
            // Touch the cache key to update TTL
            $result = $this->cache->touch($key, $this->maxLifetime);
            
            if ($result) {
                $this->logger->debug('Session timestamp updated', [
                    'session_id' => $sessionId
                ]);
            }
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->logger->error('Session timestamp update failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function validateId(string $sessionId): bool
    {
        // Validate session ID format
        if (!preg_match('/^[a-zA-Z0-9,-]{22,256}$/', $sessionId)) {
            return false;
        }
        
        try {
            $key = $this->getKey($sessionId);
            return $this->cache->has($key);
            
        } catch (\Throwable $e) {
            $this->logger->error('Session validation failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function createSid(): string
    {
        // Generate cryptographically secure session ID
        $bytes = random_bytes(32);
        return base64_encode($bytes);
    }
    
    /**
     * Lock session for exclusive access
     */
    public function lock(string $sessionId, int $timeout = null): bool
    {
        $timeout = $timeout ?? $this->lockTimeout;
        $lockKey = $this->getLockKey($sessionId);
        
        try {
            $startTime = time();
            
            while (time() - $startTime < $timeout) {
                if ($this->cache->add($lockKey, time(), $timeout)) {
                    $this->locks[$sessionId] = time();
                    $this->stats['locks']++;
                    return true;
                }
                
                // Wait before retry
                usleep(100000); // 100ms
            }
            
            $this->stats['lock_timeouts']++;
            return false;
            
        } catch (\Throwable $e) {
            $this->logger->error('Session lock failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Unlock session
     */
    public function unlock(string $sessionId): bool
    {
        $lockKey = $this->getLockKey($sessionId);
        
        try {
            $result = $this->cache->delete($lockKey);
            unset($this->locks[$sessionId]);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->logger->error('Session unlock failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Check if session is locked
     */
    public function isLocked(string $sessionId): bool
    {
        $lockKey = $this->getLockKey($sessionId);
        
        try {
            return $this->cache->has($lockKey);
            
        } catch (\Throwable $e) {
            $this->logger->error('Session lock check failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get session metadata
     */
    public function getMetadata(string $sessionId): array
    {
        try {
            $key = $this->getKey($sessionId);
            $data = $this->cache->get($key);
            
            if (is_array($data)) {
                return array_intersect_key($data, array_flip([
                    'created_at', 'updated_at', 'ip_address', 'user_agent'
                ]));
            }
            
            return [];
            
        } catch (\Throwable $e) {
            $this->logger->error('Session metadata retrieval failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Get session statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'active_locks' => count($this->locks),
            'max_lifetime' => $this->maxLifetime,
            'lock_timeout' => $this->lockTimeout,
            'prefix' => $this->prefix,
        ]);
    }
    
    /**
     * Get active session count (estimate)
     */
    public function getActiveCount(): int
    {
        // This is an estimate since we can't easily count all sessions
        // In a real implementation, you might maintain a counter
        return 0;
    }
    
    /**
     * Touch session to prevent expiration
     */
    public function touch(string $sessionId): bool
    {
        return $this->updateTimestamp($sessionId, '');
    }
    
    /**
     * Get session TTL
     */
    public function getTtl(string $sessionId): int|null
    {
        try {
            $key = $this->getKey($sessionId);
            return $this->cache->getTtl($key);
            
        } catch (\Throwable $e) {
            $this->logger->error('Session TTL retrieval failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Set session TTL
     */
    public function setTtl(string $sessionId, int $ttl): bool
    {
        try {
            $key = $this->getKey($sessionId);
            return $this->cache->touch($key, $ttl);
            
        } catch (\Throwable $e) {
            $this->logger->error('Session TTL setting failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    private function getKey(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }
    
    private function getLockKey(string $sessionId): string
    {
        return $this->prefix . 'lock:' . $sessionId;
    }
    
    private function cleanupOrphanedLocks(): int
    {
        $cleaned = 0;
        
        foreach ($this->locks as $sessionId => $lockTime) {
            if (time() - $lockTime > $this->lockTimeout) {
                $this->unlock($sessionId);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
}