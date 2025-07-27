<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Contracts;

/**
 * Cache provider interface for different cache backends
 */
interface CacheProviderInterface
{
    /**
     * Get provider name
     */
    public function getName(): string;
    
    /**
     * Get provider version
     */
    public function getVersion(): string;
    
    /**
     * Check if provider is available
     */
    public function isAvailable(): bool;
    
    /**
     * Get provider capabilities
     */
    public function getCapabilities(): array;
    
    /**
     * Get provider performance level
     */
    public function getPerformanceLevel(): int;
    
    /**
     * Create connection to cache backend
     */
    public function createConnection(array $config): mixed;
    
    /**
     * Test connection health
     */
    public function testConnection(mixed $connection): bool;
    
    /**
     * Get connection information
     */
    public function getConnectionInfo(mixed $connection): array;
    
    /**
     * Get provider-specific configuration options
     */
    public function getConfigurationOptions(): array;
    
    /**
     * Validate configuration
     */
    public function validateConfiguration(array $config): array;
    
    /**
     * Get provider statistics
     */
    public function getStats(mixed $connection): array;
    
    /**
     * Ping the cache backend
     */
    public function ping(mixed $connection): bool;
    
    /**
     * Get provider-specific features
     */
    public function getFeatures(): array;
    
    /**
     * Check if feature is supported
     */
    public function supportsFeature(string $feature): bool;
    
    /**
     * Get provider documentation URL
     */
    public function getDocumentationUrl(): string;
    
    /**
     * Get provider support information
     */
    public function getSupportInfo(): array;
}