<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Session;

use HighPerApp\Cache\Contracts\SessionStoreInterface;
use HighPerApp\Cache\Contracts\CacheInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use Psr\Log\LoggerInterface;

/**
 * Session store implementation using cache backend
 */
class SessionStore implements SessionStoreInterface
{
    private CacheInterface $cache;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private SessionHandler $handler;
    private string $prefix;
    private int $maxLifetime;
    private array $stats = [
        'reads' => 0,
        'writes' => 0,
        'destroys' => 0,
        'gc_calls' => 0,
        'locks' => 0,
        'active_sessions' => 0,
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
        
        // Create session handler
        $this->handler = new SessionHandler($cache, $config, $logger);
    }
    
    public function read(string $sessionId): string
    {
        $this->stats['reads']++;
        return $this->handler->read($sessionId);
    }
    
    public function write(string $sessionId, string $data): bool
    {
        $this->stats['writes']++;
        return $this->handler->write($sessionId, $data);
    }
    
    public function destroy(string $sessionId): bool
    {
        $this->stats['destroys']++;
        return $this->handler->destroy($sessionId);
    }
    
    public function gc(int $maxLifetime): int
    {
        $this->stats['gc_calls']++;
        return $this->handler->gc($maxLifetime);
    }
    
    public function open(string $path, string $name): bool
    {
        return $this->handler->open($path, $name);
    }
    
    public function close(): bool
    {
        return $this->handler->close();
    }
    
    public function updateTimestamp(string $sessionId, string $data): bool
    {
        return $this->handler->updateTimestamp($sessionId, $data);
    }
    
    public function validateId(string $sessionId): bool
    {
        return $this->handler->validateId($sessionId);
    }
    
    public function createSid(): string
    {
        return $this->handler->createSid();
    }
    
    public function getStats(): array
    {
        $handlerStats = $this->handler->getStats();
        
        return array_merge($this->stats, $handlerStats, [
            'store_type' => 'cache_based',
            'backend' => get_class($this->cache),
            'prefix' => $this->prefix,
            'max_lifetime' => $this->maxLifetime,
        ]);
    }
    
    public function getActiveCount(): int
    {
        return $this->handler->getActiveCount();
    }
    
    public function lock(string $sessionId, int $timeout = 30): bool
    {
        $this->stats['locks']++;
        return $this->handler->lock($sessionId, $timeout);
    }
    
    public function unlock(string $sessionId): bool
    {
        return $this->handler->unlock($sessionId);
    }
    
    public function isLocked(string $sessionId): bool
    {
        return $this->handler->isLocked($sessionId);
    }
    
    public function getMetadata(string $sessionId): array
    {
        return $this->handler->getMetadata($sessionId);
    }
    
    public function setMetadata(string $sessionId, array $metadata): bool
    {
        try {
            $key = $this->getKey($sessionId);
            $data = $this->cache->get($key);
            
            if (is_array($data)) {
                $data = array_merge($data, $metadata);
                return $this->cache->set($key, $data, $this->maxLifetime);
            }
            
            return false;
            
        } catch (\Throwable $e) {
            $this->logger->error('Session metadata update failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function touch(string $sessionId): bool
    {
        return $this->handler->touch($sessionId);
    }
    
    public function getTtl(string $sessionId): int|null
    {
        return $this->handler->getTtl($sessionId);
    }
    
    public function setTtl(string $sessionId, int $ttl): bool
    {
        return $this->handler->setTtl($sessionId, $ttl);
    }
    
    /**
     * Get the session handler instance
     */
    public function getHandler(): SessionHandler
    {
        return $this->handler;
    }
    
    /**
     * Register session handler with PHP
     */
    public function register(): bool
    {
        return $this->handler->register();
    }
    
    /**
     * Start session with this store
     */
    public function start(): bool
    {
        if (!$this->register()) {
            return false;
        }
        
        // Configure session settings
        ini_set('session.gc_maxlifetime', (string)$this->maxLifetime);
        ini_set('session.cookie_lifetime', (string)$this->maxLifetime);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        
        if (session_status() === PHP_SESSION_NONE) {
            return session_start();
        }
        
        return true;
    }
    
    /**
     * Stop session
     */
    public function stop(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_write_close();
        }
        
        return true;
    }
    
    /**
     * Regenerate session ID
     */
    public function regenerate(bool $deleteOldSession = true): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_regenerate_id($deleteOldSession);
        }
        
        return false;
    }
    
    /**
     * Get current session ID
     */
    public function getId(): string
    {
        return session_id();
    }
    
    /**
     * Set session ID
     */
    public function setId(string $sessionId): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_id($sessionId);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get session name
     */
    public function getName(): string
    {
        return session_name();
    }
    
    /**
     * Set session name
     */
    public function setName(string $name): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name($name);
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if session is active
     */
    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }
    
    /**
     * Get session data
     */
    public function getData(): array
    {
        return $_SESSION ?? [];
    }
    
    /**
     * Set session data
     */
    public function setData(array $data): bool
    {
        if ($this->isActive()) {
            $_SESSION = $data;
            return true;
        }
        
        return false;
    }
    
    /**
     * Flash data for next request
     */
    public function flash(string $key, mixed $value): void
    {
        if ($this->isActive()) {
            $_SESSION['_flash'][$key] = $value;
        }
    }
    
    /**
     * Get flash data
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        if ($this->isActive() && isset($_SESSION['_flash'][$key])) {
            $value = $_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);
            return $value;
        }
        
        return $default;
    }
    
    /**
     * Check if has flash data
     */
    public function hasFlash(string $key): bool
    {
        return $this->isActive() && isset($_SESSION['_flash'][$key]);
    }
    
    private function getKey(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }
}