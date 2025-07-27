<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Contracts;

/**
 * Cluster configuration interface for distributed cache setups
 */
interface ClusterConfigurationInterface
{
    /**
     * Get cluster nodes
     */
    public function getNodes(): array;
    
    /**
     * Add cluster node
     */
    public function addNode(string $host, int $port, array $options = []): void;
    
    /**
     * Remove cluster node
     */
    public function removeNode(string $host, int $port): bool;
    
    /**
     * Get cluster type (cluster, sentinel, replica)
     */
    public function getType(): string;
    
    /**
     * Get cluster options
     */
    public function getOptions(): array;
    
    /**
     * Set cluster options
     */
    public function setOptions(array $options): void;
    
    /**
     * Check if cluster is enabled
     */
    public function isEnabled(): bool;
    
    /**
     * Get master node (for sentinel/replica setups)
     */
    public function getMasterNode(): array|null;
    
    /**
     * Get slave nodes (for replica setups)
     */
    public function getSlaveNodes(): array;
    
    /**
     * Get sentinel nodes (for sentinel setups)
     */
    public function getSentinelNodes(): array;
    
    /**
     * Get cluster hash slots (for Redis Cluster)
     */
    public function getHashSlots(): array;
    
    /**
     * Get consistency level
     */
    public function getConsistencyLevel(): string;
    
    /**
     * Get read preference (primary, secondary, any)
     */
    public function getReadPreference(): string;
    
    /**
     * Get write concern
     */
    public function getWriteConcern(): string;
    
    /**
     * Get connection timeout
     */
    public function getConnectionTimeout(): int;
    
    /**
     * Get read timeout
     */
    public function getReadTimeout(): int;
    
    /**
     * Get retry attempts
     */
    public function getRetryAttempts(): int;
    
    /**
     * Get retry delay
     */
    public function getRetryDelay(): int;
    
    /**
     * Get health check interval
     */
    public function getHealthCheckInterval(): int;
    
    /**
     * Check if auto-discovery is enabled
     */
    public function isAutoDiscoveryEnabled(): bool;
    
    /**
     * Validate cluster configuration
     */
    public function validate(): array;
    
    /**
     * Get cluster status
     */
    public function getStatus(): array;
}