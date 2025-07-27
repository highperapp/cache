<?php

declare(strict_types=1);

namespace HighPerApp\Cache;

use HighPerApp\Cache\Contracts\CacheInterface;
use HighPerApp\Cache\Contracts\EngineManagerInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Exceptions\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use DateInterval;
use DateTime;
use Throwable;

/**
 * High-performance cache manager with multi-engine support
 */
class CacheManager implements CacheInterface
{
    private EngineManagerInterface $engineManager;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private array $tags = [];
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
        'errors' => 0,
    ];
    
    public function __construct(
        EngineManagerInterface $engineManager,
        ConfigurationInterface $config,
        LoggerInterface $logger
    ) {
        $this->engineManager = $engineManager;
        $this->config = $config;
        $this->logger = $logger;
    }
    
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        
        try {
            $engine = $this->engineManager->getBestEngine();
            $value = $engine->get($key);
            
            if ($value !== null) {
                $this->stats['hits']++;
                
                $this->logger->debug('Cache hit', [
                    'key' => $key,
                    'engine' => $engine->getName()
                ]);
                
                return $value;
            }
            
            $this->stats['misses']++;
            
            $this->logger->debug('Cache miss', [
                'key' => $key,
                'engine' => $engine->getName()
            ]);
            
            return $default;
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache get failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            
            return $default;
        }
    }
    
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        
        try {
            $ttlSeconds = $this->normalizeTtl($ttl);
            $engine = $this->engineManager->getBestEngine();
            
            $result = $engine->set($key, $value, $ttlSeconds);
            
            if ($result) {
                $this->stats['sets']++;
                
                $this->logger->debug('Cache set', [
                    'key' => $key,
                    'ttl' => $ttlSeconds,
                    'engine' => $engine->getName()
                ]);
            } else {
                $this->stats['errors']++;
            }
            
            return $result;
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache set failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    public function delete(string $key): bool
    {
        $this->validateKey($key);
        
        try {
            $engine = $this->engineManager->getBestEngine();
            $result = $engine->delete($key);
            
            if ($result) {
                $this->stats['deletes']++;
                
                $this->logger->debug('Cache delete', [
                    'key' => $key,
                    'engine' => $engine->getName()
                ]);
            }
            
            return $result;
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache delete failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    public function clear(): bool
    {
        try {
            $engine = $this->engineManager->getBestEngine();
            $result = $engine->clear();
            
            if ($result) {
                $this->logger->info('Cache cleared', [
                    'engine' => $engine->getName()
                ]);
            }
            
            return $result;
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache clear failed', [
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keysArray = $this->iterableToArray($keys);
        $this->validateKeys($keysArray);
        
        try {
            $engine = $this->engineManager->getBestEngine();
            $results = $engine->getMultiple($keysArray);
            
            // Fill in defaults for missing keys
            foreach ($keysArray as $key) {
                if (!array_key_exists($key, $results) || $results[$key] === null) {
                    $results[$key] = $default;
                    $this->stats['misses']++;
                } else {
                    $this->stats['hits']++;
                }
            }
            
            $this->logger->debug('Cache get multiple', [
                'keys' => count($keysArray),
                'hits' => count(array_filter($results, fn($v) => $v !== $default)),
                'engine' => $engine->getName()
            ]);
            
            return $results;
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache get multiple failed', [
                'keys' => $keysArray,
                'error' => $e->getMessage()
            ]);
            
            // Return defaults for all keys
            return array_fill_keys($keysArray, $default);
        }
    }
    
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $valuesArray = $this->iterableToArray($values);
        $this->validateKeys(array_keys($valuesArray));
        
        try {
            $ttlSeconds = $this->normalizeTtl($ttl);
            $engine = $this->engineManager->getBestEngine();
            
            $result = $engine->setMultiple($valuesArray, $ttlSeconds);
            
            if ($result) {
                $this->stats['sets'] += count($valuesArray);
                
                $this->logger->debug('Cache set multiple', [
                    'keys' => count($valuesArray),
                    'ttl' => $ttlSeconds,
                    'engine' => $engine->getName()
                ]);
            } else {
                $this->stats['errors']++;
            }
            
            return $result;
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache set multiple failed', [
                'keys' => array_keys($valuesArray),
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    public function deleteMultiple(iterable $keys): bool
    {
        $keysArray = $this->iterableToArray($keys);
        $this->validateKeys($keysArray);
        
        try {
            $engine = $this->engineManager->getBestEngine();
            $result = $engine->deleteMultiple($keysArray);
            
            if ($result) {
                $this->stats['deletes'] += count($keysArray);
                
                $this->logger->debug('Cache delete multiple', [
                    'keys' => count($keysArray),
                    'engine' => $engine->getName()
                ]);
            }
            
            return $result;
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache delete multiple failed', [
                'keys' => $keysArray,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    public function has(string $key): bool
    {
        $this->validateKey($key);
        
        try {
            $engine = $this->engineManager->getBestEngine();
            return $engine->has($key);
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache has failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    public function getStats(): array
    {
        try {
            $engine = $this->engineManager->getBestEngine();
            $engineStats = $engine->getStats();
        } catch (Throwable) {
            $engineStats = [];
        }
        
        return array_merge($this->stats, $engineStats, [
            'available_engines' => count($this->engineManager->getAvailableEngines()),
            'preferred_engine' => $this->engineManager->getPreferredEngine(),
        ]);
    }
    
    public function getInfo(): array
    {
        return [
            'manager' => 'HighPerApp\\Cache\\CacheManager',
            'version' => '1.0.0',
            'engines' => $this->engineManager->getEngineNames(),
            'available_engines' => array_keys($this->engineManager->getAvailableEngines()),
            'preferred_engine' => $this->engineManager->getPreferredEngine(),
            'config' => [
                'async_threshold' => $this->config->get('async_threshold'),
                'batch_size' => $this->config->get('batch_size'),
                'default_ttl' => $this->config->get('ttl_default'),
            ],
        ];
    }
    
    public function increment(string $key, int $value = 1): int|bool
    {
        $this->validateKey($key);
        
        try {
            $engine = $this->engineManager->getBestEngine();
            return $engine->increment($key, $value);
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache increment failed', [
                'key' => $key,
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    public function decrement(string $key, int $value = 1): int|bool
    {
        $this->validateKey($key);
        
        try {
            $engine = $this->engineManager->getBestEngine();
            return $engine->decrement($key, $value);
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache decrement failed', [
                'key' => $key,
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    public function add(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }
        
        return $this->set($key, $value, $ttl);
    }
    
    public function replace(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        if (!$this->has($key)) {
            return false;
        }
        
        return $this->set($key, $value, $ttl);
    }
    
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);
        
        return $value;
    }
    
    public function setWithTags(string $key, mixed $value, array $tags, null|int|DateInterval $ttl = null): bool
    {
        $result = $this->set($key, $value, $ttl);
        
        if ($result) {
            foreach ($tags as $tag) {
                if (!isset($this->tags[$tag])) {
                    $this->tags[$tag] = [];
                }
                $this->tags[$tag][] = $key;
            }
        }
        
        return $result;
    }
    
    public function invalidateTags(array $tags): bool
    {
        $keysToDelete = [];
        
        foreach ($tags as $tag) {
            if (isset($this->tags[$tag])) {
                $keysToDelete = array_merge($keysToDelete, $this->tags[$tag]);
                unset($this->tags[$tag]);
            }
        }
        
        if (empty($keysToDelete)) {
            return true;
        }
        
        return $this->deleteMultiple(array_unique($keysToDelete));
    }
    
    public function remember(string $key, null|int|DateInterval $ttl, callable $callback): mixed
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }
    
    public function rememberAsync(string $key, null|int|DateInterval $ttl, callable $callback): mixed
    {
        // For now, use synchronous version
        // TODO: Implement proper async version with AMPHP
        return $this->remember($key, $ttl, $callback);
    }
    
    public function touch(string $key, null|int|DateInterval $ttl = null): bool
    {
        if (!$this->has($key)) {
            return false;
        }
        
        $value = $this->get($key);
        return $this->set($key, $value, $ttl);
    }
    
    public function getTtl(string $key): int|null
    {
        // Basic implementation - would need engine support for actual TTL
        return $this->has($key) ? -1 : null;
    }
    
    public function flush(): bool
    {
        return $this->clear();
    }
    
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be empty');
        }
        
        if (preg_match('/[{}()\/@:"]/', $key)) {
            throw new InvalidArgumentException('Cache key contains invalid characters');
        }
        
        if (strlen($key) > 250) {
            throw new InvalidArgumentException('Cache key is too long (max 250 characters)');
        }
    }
    
    private function validateKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache key must be a string');
            }
            $this->validateKey($key);
        }
    }
    
    private function iterableToArray(iterable $iterable): array
    {
        if (is_array($iterable)) {
            return $iterable;
        }
        
        return iterator_to_array($iterable);
    }
    
    private function normalizeTtl(null|int|DateInterval $ttl): int
    {
        if ($ttl === null) {
            return $this->config->get('ttl_default', 3600);
        }
        
        if ($ttl instanceof DateInterval) {
            $now = new DateTime();
            $future = $now->add($ttl);
            return $future->getTimestamp() - $now->getTimestamp();
        }
        
        return $ttl;
    }
}