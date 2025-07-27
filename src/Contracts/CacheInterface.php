<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Contracts;

use Psr\SimpleCache\CacheInterface as PsrCacheInterface;

/**
 * High-performance cache interface extending PSR-16
 */
interface CacheInterface extends PsrCacheInterface
{
    /**
     * Get cache stats
     */
    public function getStats(): array;
    
    /**
     * Get cache info
     */
    public function getInfo(): array;
    
    /**
     * Increment a numeric value
     */
    public function increment(string $key, int $value = 1): int|bool;
    
    /**
     * Decrement a numeric value
     */
    public function decrement(string $key, int $value = 1): int|bool;
    
    /**
     * Add a value only if key doesn't exist
     */
    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;
    
    /**
     * Replace a value only if key exists
     */
    public function replace(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;
    
    /**
     * Get and delete a value atomically
     */
    public function pull(string $key, mixed $default = null): mixed;
    
    /**
     * Set value with tags for group invalidation
     */
    public function setWithTags(string $key, mixed $value, array $tags, null|int|\DateInterval $ttl = null): bool;
    
    /**
     * Invalidate all keys with given tags
     */
    public function invalidateTags(array $tags): bool;
    
    /**
     * Get or set value using callback
     */
    public function remember(string $key, null|int|\DateInterval $ttl, callable $callback): mixed;
    
    /**
     * Get or set value using callback (async version)
     */
    public function rememberAsync(string $key, null|int|\DateInterval $ttl, callable $callback): mixed;
    
    /**
     * Touch a key to reset its TTL
     */
    public function touch(string $key, null|int|\DateInterval $ttl = null): bool;
    
    /**
     * Get remaining TTL for a key
     */
    public function getTtl(string $key): int|null;
    
    /**
     * Flush all cache data
     */
    public function flush(): bool;
}