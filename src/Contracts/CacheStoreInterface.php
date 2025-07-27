<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Contracts;

/**
 * Cache store interface for different storage backends
 */
interface CacheStoreInterface
{
    /**
     * Connect to the cache store
     */
    public function connect(): bool;
    
    /**
     * Disconnect from the cache store
     */
    public function disconnect(): bool;
    
    /**
     * Check if connected
     */
    public function isConnected(): bool;
    
    /**
     * Get the underlying connection
     */
    public function getConnection(): mixed;
    
    /**
     * Ping the cache store
     */
    public function ping(): bool;
    
    /**
     * Get a value from the store
     */
    public function get(string $key): mixed;
    
    /**
     * Set a value in the store
     */
    public function set(string $key, string $value, int $ttl = 0): bool;
    
    /**
     * Delete a value from the store
     */
    public function delete(string $key): bool;
    
    /**
     * Clear all values from the store
     */
    public function clear(): bool;
    
    /**
     * Get multiple values from the store
     */
    public function getMultiple(array $keys): array;
    
    /**
     * Set multiple values in the store
     */
    public function setMultiple(array $values, int $ttl = 0): bool;
    
    /**
     * Delete multiple values from the store
     */
    public function deleteMultiple(array $keys): bool;
    
    /**
     * Check if key exists in the store
     */
    public function exists(string $key): bool;
    
    /**
     * Increment a numeric value
     */
    public function increment(string $key, int $value = 1): int|bool;
    
    /**
     * Decrement a numeric value
     */
    public function decrement(string $key, int $value = 1): int|bool;
    
    /**
     * Get store statistics
     */
    public function getStats(): array;
    
    /**
     * Get store information
     */
    public function getInfo(): array;
    
    /**
     * Flush all data from the store
     */
    public function flushAll(): bool;
    
    /**
     * Get the remaining TTL for a key
     */
    public function getTtl(string $key): int|null;
    
    /**
     * Touch a key to reset its TTL
     */
    public function touch(string $key, int $ttl): bool;
}