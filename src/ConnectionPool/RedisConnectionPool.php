<?php

declare(strict_types=1);

namespace HighPerApp\Cache\ConnectionPool;

use HighPerApp\Cache\Contracts\ConnectionPoolInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Exceptions\ConnectionException;
use Psr\Log\LoggerInterface;
use Amphp\Redis\RedisClient;
use Amphp\Redis\RedisConfig;

/**
 * Redis connection pool with AMPHP async support
 */
class RedisConnectionPool implements ConnectionPoolInterface
{
    private array $connections = [];
    private array $activeConnections = [];
    private int $minConnections;
    private int $maxConnections;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private array $stats = [
        'created' => 0,
        'destroyed' => 0,
        'hits' => 0,
        'misses' => 0,
        'timeouts' => 0,
    ];
    
    public function __construct(
        ConfigurationInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->minConnections = $config->get('redis.pool_min', 5);
        $this->maxConnections = $config->get('redis.pool_max', 20);
        
        $this->warmUp();
    }
    
    public function getConnection(): mixed
    {
        // Try to get an available connection from the pool
        if (!empty($this->connections)) {
            $connection = array_pop($this->connections);
            $this->activeConnections[spl_object_id($connection)] = $connection;
            $this->stats['hits']++;
            
            // Test connection health
            if ($this->testConnection($connection)) {
                return $connection;
            } else {
                // Connection is dead, remove it and try again
                unset($this->activeConnections[spl_object_id($connection)]);
                $this->stats['destroyed']++;
                return $this->getConnection();
            }
        }
        
        // Create new connection if under max limit
        if ($this->getTotalConnections() < $this->maxConnections) {
            $connection = $this->createConnection();
            $this->activeConnections[spl_object_id($connection)] = $connection;
            $this->stats['misses']++;
            $this->stats['created']++;
            return $connection;
        }
        
        // Wait for a connection to become available
        $this->stats['timeouts']++;
        throw new ConnectionException('Connection pool exhausted');
    }
    
    public function releaseConnection(mixed $connection): void
    {
        $connectionId = spl_object_id($connection);
        
        if (!isset($this->activeConnections[$connectionId])) {
            $this->logger->warning('Attempted to release unknown connection');
            return;
        }
        
        unset($this->activeConnections[$connectionId]);
        
        // Test connection health before returning to pool
        if ($this->testConnection($connection)) {
            $this->connections[] = $connection;
            
            // Trim pool to max size
            while (count($this->connections) > $this->maxConnections) {
                $excess = array_shift($this->connections);
                $this->destroyConnection($excess);
                $this->stats['destroyed']++;
            }
        } else {
            $this->destroyConnection($connection);
            $this->stats['destroyed']++;
        }
    }
    
    public function getPoolStats(): array
    {
        return array_merge($this->stats, [
            'total_connections' => $this->getTotalConnections(),
            'available_connections' => count($this->connections),
            'active_connections' => count($this->activeConnections),
            'min_connections' => $this->minConnections,
            'max_connections' => $this->maxConnections,
        ]);
    }
    
    public function resize(int $minConnections, int $maxConnections): void
    {
        $this->minConnections = $minConnections;
        $this->maxConnections = $maxConnections;
        
        // Trim excess connections
        while (count($this->connections) > $maxConnections) {
            $connection = array_pop($this->connections);
            $this->destroyConnection($connection);
            $this->stats['destroyed']++;
        }
        
        // Add connections if needed
        $this->warmUp();
    }
    
    public function getPoolSize(): int
    {
        return $this->getTotalConnections();
    }
    
    public function getActiveConnections(): int
    {
        return count($this->activeConnections);
    }
    
    public function getIdleConnections(): int
    {
        return count($this->connections);
    }
    
    public function isHealthy(): bool
    {
        // Pool is healthy if we have at least minimum connections
        // and less than 50% connection failures
        $totalAttempts = $this->stats['hits'] + $this->stats['misses'];
        $failureRate = $totalAttempts > 0 ? $this->stats['destroyed'] / $totalAttempts : 0;
        
        return $this->getTotalConnections() >= $this->minConnections && $failureRate < 0.5;
    }
    
    public function closeAll(): void
    {
        // Close all active connections
        foreach ($this->activeConnections as $connection) {
            $this->destroyConnection($connection);
        }
        $this->activeConnections = [];
        
        // Close all available connections
        foreach ($this->connections as $connection) {
            $this->destroyConnection($connection);
        }
        $this->connections = [];
        
        $this->logger->info('All Redis connections closed');
    }
    
    public function cleanup(): int
    {
        $cleanedUp = 0;
        
        // Test and remove dead connections from pool
        $this->connections = array_filter($this->connections, function ($connection) use (&$cleanedUp) {
            if ($this->testConnection($connection)) {
                return true;
            } else {
                $this->destroyConnection($connection);
                $cleanedUp++;
                return false;
            }
        });
        
        // Test active connections (mark for cleanup but don't forcibly close)
        foreach ($this->activeConnections as $id => $connection) {
            if (!$this->testConnection($connection)) {
                $this->logger->warning('Active Redis connection failed health check', ['id' => $id]);
            }
        }
        
        $this->stats['destroyed'] += $cleanedUp;
        
        if ($cleanedUp > 0) {
            $this->logger->info('Cleaned up dead Redis connections', ['count' => $cleanedUp]);
        }
        
        return $cleanedUp;
    }
    
    public function testConnections(): array
    {
        $results = [];
        
        // Test available connections
        foreach ($this->connections as $i => $connection) {
            $results["available_{$i}"] = $this->testConnection($connection);
        }
        
        // Test active connections
        foreach ($this->activeConnections as $id => $connection) {
            $results["active_{$id}"] = $this->testConnection($connection);
        }
        
        return $results;
    }
    
    public function warmUp(): bool
    {
        $created = 0;
        
        while (count($this->connections) < $this->minConnections) {
            try {
                $connection = $this->createConnection();
                $this->connections[] = $connection;
                $created++;
                $this->stats['created']++;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to warm up Redis connection pool', [
                    'error' => $e->getMessage(),
                    'created' => $created
                ]);
                break;
            }
        }
        
        if ($created > 0) {
            $this->logger->info('Redis connection pool warmed up', [
                'created' => $created,
                'total' => count($this->connections)
            ]);
        }
        
        return $created > 0;
    }
    
    private function createConnection(): RedisClient
    {
        $redisConfig = new RedisConfig(
            $this->config->get('redis.host', '127.0.0.1'),
            $this->config->get('redis.port', 6379),
            $this->config->get('redis.password'),
            $this->config->get('redis.database', 0)
        );
        
        try {
            $client = new RedisClient($redisConfig);
            
            $this->logger->debug('Redis connection created', [
                'host' => $this->config->get('redis.host'),
                'port' => $this->config->get('redis.port'),
                'database' => $this->config->get('redis.database', 0)
            ]);
            
            return $client;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create Redis connection', [
                'error' => $e->getMessage(),
                'host' => $this->config->get('redis.host'),
                'port' => $this->config->get('redis.port')
            ]);
            throw new ConnectionException('Failed to create Redis connection: ' . $e->getMessage());
        }
    }
    
    private function testConnection(mixed $connection): bool
    {
        try {
            // Simple ping test
            if ($connection instanceof RedisClient) {
                // For AMPHP Redis, we'd typically use async operations
                // This is a simplified synchronous test
                return true; // Assume healthy for now
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }
    
    private function destroyConnection(mixed $connection): void
    {
        try {
            if ($connection instanceof RedisClient) {
                $connection->quit();
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Error destroying Redis connection', ['error' => $e->getMessage()]);
        }
    }
    
    private function getTotalConnections(): int
    {
        return count($this->connections) + count($this->activeConnections);
    }
}