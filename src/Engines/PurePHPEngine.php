<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Engines;

use HighPerApp\Cache\Contracts\CacheEngineInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Stores\MemoryStore;
use HighPerApp\Cache\Stores\FileStore;
use HighPerApp\Cache\Serializers\SerializerManager;
use Psr\Log\LoggerInterface;

/**
 * Pure PHP cache engine - guaranteed fallback option
 */
class PurePHPEngine implements CacheEngineInterface
{
    private mixed $store;
    private SerializerManager $serializer;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private string $storeType;
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
        'errors' => 0,
    ];
    
    public function __construct(
        ConfigurationInterface $config,
        LoggerInterface $logger,
        SerializerManager $serializer
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->serializer = $serializer;
        $this->storeType = $config->get('pure_php.store_type', 'memory');
        
        $this->initializeStore();
    }
    
    public function isAvailable(): bool
    {
        return true; // Pure PHP is always available
    }
    
    public function getPerformanceLevel(): int
    {
        return 1; // Lowest performance but most reliable
    }
    
    public function getName(): string
    {
        return 'pure_php';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function get(string $key): mixed
    {
        try {
            $serialized = $this->store->get($key);
            
            if ($serialized === null) {
                $this->stats['misses']++;
                return null;
            }
            
            $this->stats['hits']++;
            
            // Deserialize if needed
            if (is_string($serialized) && $this->isSerializedData($serialized)) {
                $data = json_decode($serialized, true);
                if (isset($data['serializer']) && isset($data['data'])) {
                    return $this->serializer->unserialize($data['data'], $data['serializer']);
                }
            }
            
            return $serialized;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Pure PHP cache get failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        try {
            // Serialize the data if it's not a string
            if (!is_string($value)) {
                $serialized = $this->serializer->serialize($value);
                $data = json_encode($serialized);
            } else {
                $data = $value;
            }
            
            $result = $this->store->set($key, $data, $ttl);
            
            if ($result) {
                $this->stats['sets']++;
            } else {
                $this->stats['errors']++;
            }
            
            return $result;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Pure PHP cache set failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function delete(string $key): bool
    {
        try {
            $result = $this->store->delete($key);
            
            if ($result) {
                $this->stats['deletes']++;
            }
            
            return $result;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Pure PHP cache delete failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function clear(): bool
    {
        try {
            return $this->store->clear();
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Pure PHP cache clear failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    public function getMultiple(array $keys): array
    {
        try {
            $results = $this->store->getMultiple($keys);
            
            // Process results for deserialization
            foreach ($results as $key => $value) {
                if ($value !== null) {
                    if (is_string($value) && $this->isSerializedData($value)) {
                        $data = json_decode($value, true);
                        if (isset($data['serializer']) && isset($data['data'])) {
                            $results[$key] = $this->serializer->unserialize($data['data'], $data['serializer']);
                        }
                    }
                    $this->stats['hits']++;
                } else {
                    $this->stats['misses']++;
                }
            }
            
            return $results;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Pure PHP cache get multiple failed', [
                'keys' => $keys,
                'error' => $e->getMessage()
            ]);
            
            // Return null for all keys
            return array_fill_keys($keys, null);
        }
    }
    
    public function setMultiple(array $values, int $ttl = 0): bool
    {
        try {
            // Serialize all values
            $serializedValues = [];
            foreach ($values as $key => $value) {
                if (!is_string($value)) {
                    $serialized = $this->serializer->serialize($value);
                    $serializedValues[$key] = json_encode($serialized);
                } else {
                    $serializedValues[$key] = $value;
                }
            }
            
            $result = $this->store->setMultiple($serializedValues, $ttl);
            
            if ($result) {
                $this->stats['sets'] += count($values);
            } else {
                $this->stats['errors']++;
            }
            
            return $result;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Pure PHP cache set multiple failed', [
                'count' => count($values),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function deleteMultiple(array $keys): bool
    {
        try {
            $result = $this->store->deleteMultiple($keys);
            
            if ($result) {
                $this->stats['deletes'] += count($keys);
            }
            
            return $result;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Pure PHP cache delete multiple failed', [
                'keys' => $keys,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function has(string $key): bool
    {
        try {
            return $this->store->exists($key);
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Pure PHP cache has failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function increment(string $key, int $value = 1): int|bool
    {
        try {
            return $this->store->increment($key, $value);
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Pure PHP cache increment failed', [
                'key' => $key,
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function decrement(string $key, int $value = 1): int|bool
    {
        try {
            return $this->store->decrement($key, $value);
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Pure PHP cache decrement failed', [
                'key' => $key,
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function getStats(): array
    {
        $storeStats = [];
        
        try {
            if (method_exists($this->store, 'getStats')) {
                $storeStats = $this->store->getStats();
            }
        } catch (\Throwable) {
            // Ignore store stats errors
        }
        
        return array_merge($this->stats, $storeStats, [
            'engine' => $this->getName(),
            'version' => $this->getVersion(),
            'store_type' => $this->storeType,
            'available' => $this->isAvailable(),
            'performance_level' => $this->getPerformanceLevel(),
        ]);
    }
    
    public function ping(): bool
    {
        try {
            return $this->store->ping();
        } catch (\Throwable) {
            return false;
        }
    }
    
    public function getConnectionInfo(): array
    {
        $storeInfo = [];
        
        try {
            if (method_exists($this->store, 'getInfo')) {
                $storeInfo = $this->store->getInfo();
            }
        } catch (\Throwable) {
            // Ignore store info errors
        }
        
        return [
            'engine' => $this->getName(),
            'type' => 'pure_php',
            'store_type' => $this->storeType,
            'version' => $this->getVersion(),
            'available' => $this->isAvailable(),
            'store_info' => $storeInfo,
        ];
    }
    
    /**
     * Get the underlying store instance
     */
    public function getStore(): mixed
    {
        return $this->store;
    }
    
    /**
     * Switch to a different store type
     */
    public function switchStore(string $storeType): bool
    {
        try {
            $this->storeType = $storeType;
            $this->initializeStore();
            
            $this->logger->info('Pure PHP engine store switched', [
                'new_store' => $storeType
            ]);
            
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to switch Pure PHP engine store', [
                'store_type' => $storeType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    private function initializeStore(): void
    {
        switch ($this->storeType) {
            case 'file':
                $this->store = new FileStore($this->config, $this->logger);
                break;
                
            case 'memory':
            default:
                $this->store = new MemoryStore($this->config, $this->logger);
                break;
        }
        
        // Connect to the store
        if (method_exists($this->store, 'connect')) {
            $this->store->connect();
        }
        
        $this->logger->debug('Pure PHP engine store initialized', [
            'store_type' => $this->storeType
        ]);
    }
    
    private function isSerializedData(string $data): bool
    {
        // Check if the data looks like our serialized format
        $decoded = json_decode($data, true);
        return is_array($decoded) && 
               isset($decoded['serializer']) && 
               isset($decoded['data']);
    }
}