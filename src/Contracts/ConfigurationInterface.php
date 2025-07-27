<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Contracts;

/**
 * Configuration interface for cache settings
 */
interface ConfigurationInterface
{
    /**
     * Get configuration value
     */
    public function get(string $key, mixed $default = null): mixed;
    
    /**
     * Set configuration value
     */
    public function set(string $key, mixed $value): void;
    
    /**
     * Check if configuration key exists
     */
    public function has(string $key): bool;
    
    /**
     * Merge configuration array
     */
    public function merge(array $config): void;
    
    /**
     * Get all configuration as array
     */
    public function toArray(): array;
    
    /**
     * Validate configuration
     */
    public function validate(): array;
    
    /**
     * Get configuration schema
     */
    public function getSchema(): array;
    
    /**
     * Load configuration from environment
     */
    public function loadFromEnvironment(): void;
    
    /**
     * Load configuration from file
     */
    public function loadFromFile(string $path): void;
    
    /**
     * Save configuration to file
     */
    public function saveToFile(string $path): bool;
}