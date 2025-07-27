<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Contracts;

/**
 * Connection pool interface for managing cache connections
 */
interface ConnectionPoolInterface
{
    /**
     * Get a connection from the pool
     */
    public function getConnection(): mixed;
    
    /**
     * Release a connection back to the pool
     */
    public function releaseConnection(mixed $connection): void;
    
    /**
     * Get pool statistics
     */
    public function getPoolStats(): array;
    
    /**
     * Resize the pool
     */
    public function resize(int $minConnections, int $maxConnections): void;
    
    /**
     * Get current pool size
     */
    public function getPoolSize(): int;
    
    /**
     * Get active connections count
     */
    public function getActiveConnections(): int;
    
    /**
     * Get idle connections count
     */
    public function getIdleConnections(): int;
    
    /**
     * Check if pool is healthy
     */
    public function isHealthy(): bool;
    
    /**
     * Close all connections in the pool
     */
    public function closeAll(): void;
    
    /**
     * Cleanup dead connections
     */
    public function cleanup(): int;
    
    /**
     * Test all connections in the pool
     */
    public function testConnections(): array;
    
    /**
     * Warm up the pool with minimum connections
     */
    public function warmUp(): bool;
}