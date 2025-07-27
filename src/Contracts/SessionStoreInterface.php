<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Contracts;

/**
 * Session store interface for high-performance session management
 */
interface SessionStoreInterface
{
    /**
     * Read session data
     */
    public function read(string $sessionId): string;
    
    /**
     * Write session data
     */
    public function write(string $sessionId, string $data): bool;
    
    /**
     * Destroy session
     */
    public function destroy(string $sessionId): bool;
    
    /**
     * Garbage collection
     */
    public function gc(int $maxLifetime): int;
    
    /**
     * Open session handler
     */
    public function open(string $path, string $name): bool;
    
    /**
     * Close session handler
     */
    public function close(): bool;
    
    /**
     * Update session timestamp
     */
    public function updateTimestamp(string $sessionId, string $data): bool;
    
    /**
     * Validate session ID
     */
    public function validateId(string $sessionId): bool;
    
    /**
     * Create new session ID
     */
    public function createSid(): string;
    
    /**
     * Get session statistics
     */
    public function getStats(): array;
    
    /**
     * Get active session count
     */
    public function getActiveCount(): int;
    
    /**
     * Lock session for writing
     */
    public function lock(string $sessionId, int $timeout = 30): bool;
    
    /**
     * Unlock session
     */
    public function unlock(string $sessionId): bool;
    
    /**
     * Check if session is locked
     */
    public function isLocked(string $sessionId): bool;
    
    /**
     * Get session metadata
     */
    public function getMetadata(string $sessionId): array;
    
    /**
     * Set session metadata
     */
    public function setMetadata(string $sessionId, array $metadata): bool;
    
    /**
     * Touch session to prevent expiration
     */
    public function touch(string $sessionId): bool;
    
    /**
     * Get session TTL
     */
    public function getTtl(string $sessionId): int|null;
    
    /**
     * Set session TTL
     */
    public function setTtl(string $sessionId, int $ttl): bool;
}