<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Engines;

use HighPerApp\Cache\Contracts\CacheEngineInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Contracts\ConnectionPoolInterface;
use HighPerApp\Cache\Serializers\SerializerManager;
use HighPerApp\Cache\Exceptions\EngineNotAvailableException;
use Psr\Log\LoggerInterface;
use Amphp\Redis\RedisClient;
use function Amphp\async;
use function Amphp\await;

/**
 * AMPHP-based async cache engine for mid-level performance
 */
class AMPHPEngine implements CacheEngineInterface
{
    private ConnectionPoolInterface $connectionPool;
    private SerializerManager $serializer;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private int $asyncThreshold;
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
        'errors' => 0,
        'async_operations' => 0,
    ];
    
    public function __construct(
        ConnectionPoolInterface $connectionPool,
        SerializerManager $serializer,
        ConfigurationInterface $config,
        LoggerInterface $logger
    ) {
        $this->connectionPool = $connectionPool;
        $this->serializer = $serializer;
        $this->config = $config;
        $this->logger = $logger;
        $this->asyncThreshold = $config->get('async_threshold', 10);
    }
    
    public function isAvailable(): bool
    {
        return $this->config->get('amphp_enabled', true) && 
               class_exists('Amphp\\Redis\\RedisClient') &&
               $this->connectionPool->isHealthy();
    }
    
    public function getPerformanceLevel(): int
    {
        return 2; // Mid-level performance
    }
    
    public function getName(): string
    {
        return 'amphp';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function get(string $key): mixed
    {
        $this->ensureAvailable();
        
        try {
            $connection = $this->connectionPool->getConnection();
            
            if (!($connection instanceof RedisClient)) {
                throw new \RuntimeException('Invalid connection type for AMPHP engine');
            }
            
            $serialized = await($connection->get($key));
            
            $this->connectionPool->releaseConnection($connection);
            
            if ($serialized === null) {
                $this->stats['misses']++;
                return null;
            }
            
            $this->stats['hits']++;
            
            // Unserialize the data
            $data = json_decode($serialized, true);
            if (isset($data['serializer']) && isset($data['data'])) {
                return $this->serializer->unserialize($data['data'], $data['serializer']);
            }
            
            return $serialized;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('AMPHP cache get failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->ensureAvailable();
        
        try {
            $connection = $this->connectionPool->getConnection();
            
            if (!($connection instanceof RedisClient)) {
                throw new \RuntimeException('Invalid connection type for AMPHP engine');
            }
            
            // Serialize the data
            $serialized = $this->serializer->serialize($value);
            $data = json_encode($serialized);
            
            if ($ttl > 0) {
                $result = await($connection->setex($key, $ttl, $data));
            } else {
                $result = await($connection->set($key, $data));
            }
            
            $this->connectionPool->releaseConnection($connection);
            
            if ($result) {
                $this->stats['sets']++;
            } else {
                $this->stats['errors']++;
            }
            
            return (bool)$result;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('AMPHP cache set failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function delete(string $key): bool
    {
        $this->ensureAvailable();
        
        try {
            $connection = $this->connectionPool->getConnection();
            
            if (!($connection instanceof RedisClient)) {
                throw new \RuntimeException('Invalid connection type for AMPHP engine');
            }
            
            $result = await($connection->del($key));
            
            $this->connectionPool->releaseConnection($connection);
            
            if ($result > 0) {
                $this->stats['deletes']++;
                return true;
            }
            
            return false;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('AMPHP cache delete failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function clear(): bool
    {
        $this->ensureAvailable();
        
        try {
            $connection = $this->connectionPool->getConnection();
            
            if (!($connection instanceof RedisClient)) {
                throw new \RuntimeException('Invalid connection type for AMPHP engine');
            }
            
            $result = await($connection->flushdb());
            
            $this->connectionPool->releaseConnection($connection);
            
            return (bool)$result;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('AMPHP cache clear failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    public function getMultiple(array $keys): array
    {
        $this->ensureAvailable();
        
        if (count($keys) >= $this->asyncThreshold) {
            return $this->getMultipleAsync($keys);
        }
        
        try {
            $connection = $this->connectionPool->getConnection();
            
            if (!($connection instanceof RedisClient)) {
                throw new \RuntimeException('Invalid connection type for AMPHP engine');
            }
            
            $serializedResults = await($connection->mget($keys));
            
            $this->connectionPool->releaseConnection($connection);
            
            $results = [];
            foreach ($keys as $i => $key) {
                $serialized = $serializedResults[$i] ?? null;
                
                if ($serialized !== null) {
                    $data = json_decode($serialized, true);
                    if (isset($data['serializer']) && isset($data['data'])) {
                        $results[$key] = $this->serializer->unserialize($data['data'], $data['serializer']);
                    } else {
                        $results[$key] = $serialized;
                    }
                    $this->stats['hits']++;
                } else {
                    $results[$key] = null;
                    $this->stats['misses']++;
                }
            }
            
            return $results;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('AMPHP cache get multiple failed', [
                'keys' => $keys,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to individual gets
            $results = [];
            foreach ($keys as $key) {
                $results[$key] = $this->get($key);
            }
            return $results;
        }
    }
    
    public function setMultiple(array $values, int $ttl = 0): bool
    {
        $this->ensureAvailable();
        
        if (count($values) >= $this->asyncThreshold) {
            return $this->setMultipleAsync($values, $ttl);
        }
        
        try {
            $connection = $this->connectionPool->getConnection();
            
            if (!($connection instanceof RedisClient)) {
                throw new \RuntimeException('Invalid connection type for AMPHP engine');
            }
            
            // Serialize all values
            $serializedValues = [];
            foreach ($values as $key => $value) {
                $serialized = $this->serializer->serialize($value);
                $serializedValues[$key] = json_encode($serialized);
            }
            
            if ($ttl > 0) {
                // Use pipeline for SETEX commands
                $pipeline = $connection->pipeline();
                foreach ($serializedValues as $key => $data) {
                    $pipeline->setex($key, $ttl, $data);
                }
                $results = await($pipeline->execute());
                $success = !in_array(false, $results, true);
            } else {
                $result = await($connection->mset($serializedValues));
                $success = (bool)$result;
            }
            
            $this->connectionPool->releaseConnection($connection);
            
            if ($success) {
                $this->stats['sets'] += count($values);
            } else {
                $this->stats['errors']++;
            }
            
            return $success;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('AMPHP cache set multiple failed', [
                'count' => count($values),
                'error' => $e->getMessage()
            ]);
            
            // Fallback to individual sets
            $success = true;
            foreach ($values as $key => $value) {
                if (!$this->set($key, $value, $ttl)) {
                    $success = false;
                }
            }
            return $success;
        }
    }
    
    public function deleteMultiple(array $keys): bool
    {
        $this->ensureAvailable();
        
        try {
            $connection = $this->connectionPool->getConnection();
            
            if (!($connection instanceof RedisClient)) {
                throw new \RuntimeException('Invalid connection type for AMPHP engine');
            }
            
            $result = await($connection->del(...$keys));
            
            $this->connectionPool->releaseConnection($connection);
            
            $deletedCount = (int)$result;
            $this->stats['deletes'] += $deletedCount;
            
            return $deletedCount === count($keys);
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('AMPHP cache delete multiple failed', [
                'keys' => $keys,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to individual deletes
            $success = true;
            foreach ($keys as $key) {
                if (!$this->delete($key)) {
                    $success = false;
                }
            }
            return $success;
        }
    }
    
    public function has(string $key): bool
    {
        $this->ensureAvailable();
        
        try {
            $connection = $this->connectionPool->getConnection();
            
            if (!($connection instanceof RedisClient)) {
                throw new \RuntimeException('Invalid connection type for AMPHP engine');
            }
            
            $result = await($connection->exists($key));
            
            $this->connectionPool->releaseConnection($connection);
            
            return (bool)$result;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('AMPHP cache has failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function increment(string $key, int $value = 1): int|bool
    {
        $this->ensureAvailable();
        
        try {
            $connection = $this->connectionPool->getConnection();
            
            if (!($connection instanceof RedisClient)) {
                throw new \RuntimeException('Invalid connection type for AMPHP engine');
            }
            
            $result = await($connection->incrby($key, $value));
            
            $this->connectionPool->releaseConnection($connection);
            
            return (int)$result;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('AMPHP cache increment failed', [
                'key' => $key,
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }
    
    public function getStats(): array
    {
        $poolStats = [];
        
        try {
            $poolStats = $this->connectionPool->getPoolStats();
        } catch (\Throwable) {
            // Ignore pool stats errors
        }
        
        return array_merge($this->stats, $poolStats, [
            'engine' => $this->getName(),
            'version' => $this->getVersion(),
            'available' => $this->isAvailable(),
            'performance_level' => $this->getPerformanceLevel(),
            'async_threshold' => $this->asyncThreshold,
        ]);
    }
    
    public function ping(): bool
    {
        try {
            $connection = $this->connectionPool->getConnection();
            
            if (!($connection instanceof RedisClient)) {
                return false;
            }
            
            $result = await($connection->ping());
            
            $this->connectionPool->releaseConnection($connection);
            
            return $result === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }
    
    public function getConnectionInfo(): array
    {
        return [
            'engine' => $this->getName(),
            'type' => 'amphp_redis',
            'version' => $this->getVersion(),
            'available' => $this->isAvailable(),
            'pool_stats' => $this->connectionPool->getPoolStats(),
            'async_threshold' => $this->asyncThreshold,
        ];
    }
    
    private function getMultipleAsync(array $keys): array
    {
        $this->stats['async_operations']++;
        
        try {
            // Process keys in batches
            $batchSize = $this->config->get('batch_size', 100);
            $batches = array_chunk($keys, $batchSize, true);
            
            $futures = [];
            foreach ($batches as $batch) {
                $futures[] = async(function() use ($batch) {
                    return $this->getMultiple($batch);
                });
            }
            
            $results = [];
            foreach ($futures as $future) {
                $batchResults = await($future);
                $results = array_merge($results, $batchResults);
            }
            
            return $results;
        } catch (\Throwable $e) {
            $this->logger->error('AMPHP async get multiple failed', [
                'keys_count' => count($keys),
                'error' => $e->getMessage()
            ]);
            
            // Fallback to synchronous version
            return $this->getMultiple($keys);
        }
    }
    
    private function setMultipleAsync(array $values, int $ttl = 0): bool
    {
        $this->stats['async_operations']++;
        
        try {
            // Process values in batches
            $batchSize = $this->config->get('batch_size', 100);
            $batches = array_chunk($values, $batchSize, true);
            
            $futures = [];
            foreach ($batches as $batch) {
                $futures[] = async(function() use ($batch, $ttl) {
                    return $this->setMultiple($batch, $ttl);
                });
            }
            
            $success = true;
            foreach ($futures as $future) {
                if (!await($future)) {
                    $success = false;
                }
            }
            
            return $success;
        } catch (\Throwable $e) {
            $this->logger->error('AMPHP async set multiple failed', [
                'values_count' => count($values),
                'error' => $e->getMessage()
            ]);
            
            // Fallback to synchronous version
            return $this->setMultiple($values, $ttl);
        }
    }
    
    private function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new EngineNotAvailableException('AMPHP engine not available');
        }
    }
}