<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Engines;

use HighPerApp\Cache\Contracts\CacheEngineInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\FFI\RustCacheFFI;
use HighPerApp\Cache\Serializers\SerializerManager;
use HighPerApp\Cache\Exceptions\EngineNotAvailableException;
use Psr\Log\LoggerInterface;

/**
 * Rust FFI engine for maximum performance
 */
class RustFFIEngine implements CacheEngineInterface
{
    private RustCacheFFI $ffi;
    private SerializerManager $serializer;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
        'errors' => 0,
    ];
    
    public function __construct(
        RustCacheFFI $ffi,
        SerializerManager $serializer,
        ConfigurationInterface $config,
        LoggerInterface $logger
    ) {
        $this->ffi = $ffi;
        $this->serializer = $serializer;
        $this->config = $config;
        $this->logger = $logger;
    }
    
    public function isAvailable(): bool
    {
        return $this->config->get('rust_ffi_enabled', true) && 
               $this->ffi->isAvailable();
    }
    
    public function getPerformanceLevel(): int
    {
        return 3; // Highest performance
    }
    
    public function getName(): string
    {
        return 'rust_ffi';
    }
    
    public function getVersion(): string
    {
        try {
            return $this->ffi->getVersion();
        } catch (\Throwable) {
            return 'unknown';
        }
    }
    
    public function get(string $key): mixed
    {
        $this->ensureAvailable();
        
        try {
            $serialized = $this->ffi->memoryGet($key);
            
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
            $this->logger->error('Rust FFI cache get failed', [
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
            // Serialize the data
            $serialized = $this->serializer->serialize($value);
            $data = json_encode($serialized);
            
            $result = $this->ffi->memorySet($key, $data, $ttl);
            
            if ($result) {
                $this->stats['sets']++;
            } else {
                $this->stats['errors']++;
            }
            
            return $result;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Rust FFI cache set failed', [
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
            $result = $this->ffi->memoryDelete($key);
            
            if ($result) {
                $this->stats['deletes']++;
            }
            
            return $result;
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Rust FFI cache delete failed', [
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
            return $this->ffi->memoryClear();
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Rust FFI cache clear failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    public function getMultiple(array $keys): array
    {
        $this->ensureAvailable();
        
        try {
            $serializedResults = $this->ffi->memoryGetMultiple($keys);
            $results = [];
            
            foreach ($serializedResults as $key => $serialized) {
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
            $this->logger->error('Rust FFI cache get multiple failed', [
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
        
        try {
            // Serialize all values
            $serializedValues = [];
            foreach ($values as $key => $value) {
                $serialized = $this->serializer->serialize($value);
                $serializedValues[$key] = json_encode($serialized);
            }
            
            $successCount = $this->ffi->memorySetMultiple($serializedValues, $ttl);
            $this->stats['sets'] += $successCount;
            
            return $successCount === count($values);
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Rust FFI cache set multiple failed', [
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
        
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    public function has(string $key): bool
    {
        $this->ensureAvailable();
        
        try {
            return $this->ffi->memoryExists($key);
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Rust FFI cache has failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function increment(string $key, int $value = 1): int|bool
    {
        $this->ensureAvailable();
        
        $current = $this->get($key);
        
        if ($current === null) {
            $current = 0;
        } elseif (!is_numeric($current)) {
            return false;
        }
        
        $newValue = (int)$current + $value;
        
        if ($this->set($key, $newValue)) {
            return $newValue;
        }
        
        return false;
    }
    
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }
    
    public function getStats(): array
    {
        try {
            $rustStats = [
                'count' => $this->ffi->memoryCount(),
                'cleanup_count' => $this->ffi->memoryCleanup(),
            ];
        } catch (\Throwable) {
            $rustStats = [
                'count' => 0,
                'cleanup_count' => 0,
            ];
        }
        
        return array_merge($this->stats, $rustStats, [
            'engine' => $this->getName(),
            'version' => $this->getVersion(),
            'available' => $this->isAvailable(),
            'performance_level' => $this->getPerformanceLevel(),
        ]);
    }
    
    public function ping(): bool
    {
        try {
            return $this->isAvailable();
        } catch (\Throwable) {
            return false;
        }
    }
    
    public function getConnectionInfo(): array
    {
        return [
            'engine' => $this->getName(),
            'type' => 'rust_ffi',
            'version' => $this->getVersion(),
            'available' => $this->isAvailable(),
            'ffi_loaded' => extension_loaded('ffi'),
            'library_loaded' => $this->ffi->isAvailable(),
        ];
    }
    
    /**
     * Benchmark the Rust FFI engine
     */
    public function benchmark(int $operations = 10000): array
    {
        $this->ensureAvailable();
        
        try {
            $rustTime = $this->ffi->benchmarkMemory($operations);
            
            // PHP benchmark for comparison
            $phpStart = microtime(true);
            for ($i = 0; $i < $operations; $i++) {
                $this->set("bench_$i", "value_$i", 3600);
                $this->get("bench_$i");
                $this->delete("bench_$i");
            }
            $phpTime = microtime(true) - $phpStart;
            
            $speedup = $phpTime > 0 ? $phpTime / $rustTime : 0;
            
            return [
                'operations' => $operations,
                'rust_time' => $rustTime,
                'php_time' => $phpTime,
                'speedup' => round($speedup, 2),
                'rust_ops_per_sec' => round($operations / $rustTime),
                'php_ops_per_sec' => round($operations / $phpTime),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Rust FFI benchmark failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    private function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new EngineNotAvailableException('Rust FFI engine not available');
        }
    }
}