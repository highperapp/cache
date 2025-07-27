<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Engines;

use HighPerApp\Cache\Contracts\EngineManagerInterface;
use HighPerApp\Cache\Contracts\CacheEngineInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Exceptions\EngineNotAvailableException;
use Psr\Log\LoggerInterface;

/**
 * Engine manager with automatic fallback selection
 */
class EngineManager implements EngineManagerInterface
{
    private array $engines = [];
    private array $availableEngines = [];
    private string $preferredEngine;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private bool $initialized = false;
    
    public function __construct(
        ConfigurationInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->preferredEngine = $config->get('preferred_engine', 'rust_ffi');
    }
    
    public function registerEngine(string $name, CacheEngineInterface $engine): void
    {
        $this->engines[$name] = $engine;
        
        if ($engine->isAvailable()) {
            $this->availableEngines[$name] = $engine;
            
            $this->logger->debug('Cache engine registered and available', [
                'engine' => $name,
                'performance_level' => $engine->getPerformanceLevel()
            ]);
        } else {
            $this->logger->debug('Cache engine registered but not available', [
                'engine' => $name
            ]);
        }
    }
    
    public function getBestEngine(): CacheEngineInterface
    {
        $this->ensureInitialized();
        
        if (empty($this->availableEngines)) {
            throw new EngineNotAvailableException('No cache engines available');
        }
        
        // Try preferred engine first
        if (isset($this->availableEngines[$this->preferredEngine])) {
            return $this->availableEngines[$this->preferredEngine];
        }
        
        // Sort by performance level (highest first)
        $sortedEngines = $this->availableEngines;
        uasort($sortedEngines, fn($a, $b) => $b->getPerformanceLevel() <=> $a->getPerformanceLevel());
        
        $bestEngine = reset($sortedEngines);
        
        $this->logger->debug('Selected best cache engine', [
            'engine' => $bestEngine->getName(),
            'performance_level' => $bestEngine->getPerformanceLevel()
        ]);
        
        return $bestEngine;
    }
    
    public function getEngine(string $name): CacheEngineInterface
    {
        $this->ensureInitialized();
        
        if (!isset($this->engines[$name])) {
            throw new EngineNotAvailableException("Engine '{$name}' not registered");
        }
        
        if (!isset($this->availableEngines[$name])) {
            throw new EngineNotAvailableException("Engine '{$name}' not available");
        }
        
        return $this->availableEngines[$name];
    }
    
    public function getAvailableEngines(): array
    {
        $this->ensureInitialized();
        return $this->availableEngines;
    }
    
    public function getEngineNames(): array
    {
        return array_keys($this->engines);
    }
    
    public function getPreferredEngine(): string
    {
        return $this->preferredEngine;
    }
    
    public function setPreferredEngine(string $name): void
    {
        if (!isset($this->engines[$name])) {
            throw new EngineNotAvailableException("Engine '{$name}' not registered");
        }
        
        $this->preferredEngine = $name;
        
        $this->logger->info('Preferred cache engine changed', [
            'engine' => $name
        ]);
    }
    
    public function refreshAvailability(): void
    {
        $this->availableEngines = [];
        
        foreach ($this->engines as $name => $engine) {
            if ($engine->isAvailable()) {
                $this->availableEngines[$name] = $engine;
            }
        }
        
        $this->logger->debug('Cache engine availability refreshed', [
            'available' => array_keys($this->availableEngines),
            'total' => count($this->engines)
        ]);
    }
    
    public function getEngineStats(): array
    {
        $stats = [];
        
        foreach ($this->availableEngines as $name => $engine) {
            try {
                $stats[$name] = $engine->getStats();
            } catch (\Throwable $e) {
                $stats[$name] = ['error' => $e->getMessage()];
                $this->logger->warning('Failed to get engine stats', [
                    'engine' => $name,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $stats;
    }
    
    public function testEngines(): array
    {
        $results = [];
        
        foreach ($this->engines as $name => $engine) {
            $results[$name] = [
                'available' => $engine->isAvailable(),
                'performance_level' => $engine->getPerformanceLevel(),
                'ping' => false,
                'error' => null,
            ];
            
            if ($engine->isAvailable()) {
                try {
                    $results[$name]['ping'] = $engine->ping();
                } catch (\Throwable $e) {
                    $results[$name]['error'] = $e->getMessage();
                    $this->logger->warning('Engine ping failed', [
                        'engine' => $name,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        return $results;
    }
    
    public function benchmarkEngines(int $operations = 1000): array
    {
        $results = [];
        
        foreach ($this->availableEngines as $name => $engine) {
            try {
                $start = microtime(true);
                
                // Simple benchmark: set, get, delete operations
                for ($i = 0; $i < $operations; $i++) {
                    $key = "benchmark_{$i}";
                    $value = "value_{$i}";
                    
                    $engine->set($key, $value, 3600);
                    $engine->get($key);
                    $engine->delete($key);
                }
                
                $time = microtime(true) - $start;
                $opsPerSecond = $operations > 0 ? round($operations / $time) : 0;
                
                $results[$name] = [
                    'operations' => $operations,
                    'time_seconds' => round($time, 4),
                    'ops_per_second' => $opsPerSecond,
                    'performance_level' => $engine->getPerformanceLevel(),
                ];
                
                $this->logger->info('Engine benchmark completed', [
                    'engine' => $name,
                    'ops_per_second' => $opsPerSecond
                ]);
                
            } catch (\Throwable $e) {
                $results[$name] = [
                    'error' => $e->getMessage()
                ];
                
                $this->logger->error('Engine benchmark failed', [
                    'engine' => $name,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $results;
    }
    
    public function getEngineRecommendation(): array
    {
        $this->ensureInitialized();
        
        if (empty($this->availableEngines)) {
            return [
                'recommended' => null,
                'reason' => 'No engines available',
                'alternatives' => [],
            ];
        }
        
        $engines = $this->availableEngines;
        
        // Sort by performance level
        uasort($engines, fn($a, $b) => $b->getPerformanceLevel() <=> $a->getPerformanceLevel());
        
        $recommended = reset($engines);
        $alternatives = array_slice($engines, 1, 2, true);
        
        return [
            'recommended' => $recommended->getName(),
            'performance_level' => $recommended->getPerformanceLevel(),
            'reason' => 'Highest performance level available',
            'alternatives' => array_map(
                fn($e) => [
                    'name' => $e->getName(),
                    'performance_level' => $e->getPerformanceLevel()
                ],
                $alternatives
            ),
        ];
    }
    
    private function ensureInitialized(): void
    {
        if (!$this->initialized) {
            $this->refreshAvailability();
            $this->initialized = true;
            
            $this->logger->info('Engine manager initialized', [
                'total_engines' => count($this->engines),
                'available_engines' => count($this->availableEngines),
                'preferred_engine' => $this->preferredEngine
            ]);
        }
    }
}