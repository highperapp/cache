<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Contracts;

/**
 * Engine manager interface for handling multiple cache engines
 */
interface EngineManagerInterface
{
    /**
     * Get all available engines
     */
    public function getAvailableEngines(): array;
    
    /**
     * Get the best available engine
     */
    public function getBestEngine(): CacheEngineInterface;
    
    /**
     * Get specific engine by name
     */
    public function getEngine(string $name): CacheEngineInterface;
    
    /**
     * Add an engine to the manager
     */
    public function addEngine(string $name, CacheEngineInterface $engine): void;
    
    /**
     * Remove an engine from the manager
     */
    public function removeEngine(string $name): bool;
    
    /**
     * Set preferred engine
     */
    public function setPreferredEngine(string $name): void;
    
    /**
     * Get preferred engine name
     */
    public function getPreferredEngine(): string;
    
    /**
     * Test all engines
     */
    public function testEngines(): array;
    
    /**
     * Get engine performance benchmark
     */
    public function benchmarkEngines(): array;
    
    /**
     * Check if engine exists
     */
    public function hasEngine(string $name): bool;
    
    /**
     * Get engine names
     */
    public function getEngineNames(): array;
}