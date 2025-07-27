<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Contracts;

/**
 * Cache engine interface for different implementation strategies
 */
interface CacheEngineInterface
{
    /**
     * Check if engine is available
     */
    public function isAvailable(): bool;
    
    /**
     * Get performance level (1=basic, 2=good, 3=excellent)
     */
    public function getPerformanceLevel(): int;
    
    /**
     * Get engine name
     */
    public function getName(): string;
    
    /**
     * Get engine version
     */
    public function getVersion(): string;
    
    /**
     * Get a cached value
     */
    public function get(string $key): mixed;
    
    /**
     * Set a cached value
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool;
    
    /**
     * Delete a cached value
     */
    public function delete(string $key): bool;
    
    /**
     * Clear all cached values
     */
    public function clear(): bool;
    
    /**
     * Get multiple cached values
     */
    public function getMultiple(array $keys): array;
    
    /**
     * Set multiple cached values
     */
    public function setMultiple(array $values, int $ttl = 0): bool;
    
    /**
     * Delete multiple cached values
     */
    public function deleteMultiple(array $keys): bool;
    
    /**
     * Check if key exists
     */
    public function has(string $key): bool;
    
    /**
     * Increment a numeric value
     */
    public function increment(string $key, int $value = 1): int|bool;
    
    /**
     * Decrement a numeric value
     */
    public function decrement(string $key, int $value = 1): int|bool;
    
    /**
     * Get engine statistics
     */
    public function getStats(): array;
    
    /**
     * Ping the cache engine
     */
    public function ping(): bool;
    
    /**
     * Get connection information
     */
    public function getConnectionInfo(): array;
}