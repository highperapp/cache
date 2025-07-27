<?php

declare(strict_types=1);

namespace HighPerApp\Cache\ConnectionPool;

use HighPerApp\Cache\Contracts\ConnectionPoolInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Contracts\ClusterConfigurationInterface;
use HighPerApp\Cache\Cluster\RedisClusterConfiguration;
use HighPerApp\Cache\Exceptions\ConnectionException;
use Psr\Log\LoggerInterface;
use Amphp\Redis\RedisClient;
use Amphp\Redis\RedisConfig;

/**
 * Cluster-aware Redis connection pool
 */
class ClusterAwareRedisConnectionPool implements ConnectionPoolInterface
{
    private array $connections = [];
    private array $nodeConnections = [];
    private ClusterConfigurationInterface $clusterConfig;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private int $minConnections;
    private int $maxConnections;
    private array $stats = [
        'total_connections' => 0,
        'active_connections' => 0,
        'failed_connections' => 0,
        'cluster_failures' => 0,
        'failovers' => 0,
        'node_discoveries' => 0,
    ];
    
    public function __construct(
        ConfigurationInterface $config,
        LoggerInterface $logger,
        ClusterConfigurationInterface $clusterConfig = null
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->clusterConfig = $clusterConfig ?? new RedisClusterConfiguration($config);
        $this->minConnections = $config->get('redis.pool_min', 5);
        $this->maxConnections = $config->get('redis.pool_max', 20);
        
        $this->initializeCluster();
    }
    
    public function getConnection(): mixed
    {
        // Get connection based on cluster configuration
        if ($this->clusterConfig->isEnabled()) {
            return $this->getClusterConnection();
        }
        
        // Fallback to single connection
        return $this->getSingleConnection();
    }
    
    public function getReadConnection(): mixed
    {
        $node = $this->clusterConfig->getReadNode();
        
        if ($node) {
            return $this->getConnectionForNode($node);
        }
        
        return $this->getConnection();
    }
    
    public function getWriteConnection(): mixed
    {
        $node = $this->clusterConfig->getWriteNode();
        
        if ($node) {
            return $this->getConnectionForNode($node);
        }
        
        return $this->getConnection();
    }
    
    public function releaseConnection(mixed $connection): void
    {
        $connectionId = spl_object_id($connection);
        
        // Test connection health before returning to pool
        if ($this->testConnection($connection)) {
            $this->connections[] = $connection;
            $this->stats['active_connections']--;
        } else {
            $this->destroyConnection($connection);
            $this->stats['failed_connections']++;
        }
    }
    
    public function getStats(): array
    {
        $clusterStats = $this->clusterConfig->getStatus();
        
        return array_merge($this->stats, [
            'pool_size' => count($this->connections),
            'node_pools' => count($this->nodeConnections),
            'cluster_enabled' => $this->clusterConfig->isEnabled(),
            'cluster_type' => $this->clusterConfig->getType(),
            'cluster_nodes' => count($this->clusterConfig->getNodes()),
            'cluster_status' => $clusterStats,
        ]);
    }
    
    public function warmUp(): void
    {
        if ($this->clusterConfig->isEnabled()) {
            $this->warmUpCluster();
        } else {
            $this->warmUpSingle();
        }
    }
    
    public function shutdown(): void
    {
        // Close all connections
        foreach ($this->connections as $connection) {
            $this->destroyConnection($connection);
        }
        
        foreach ($this->nodeConnections as $nodeKey => $nodePool) {
            foreach ($nodePool as $connection) {
                $this->destroyConnection($connection);
            }
        }
        
        $this->connections = [];
        $this->nodeConnections = [];
        
        $this->logger->info('Cluster-aware Redis connection pool shutdown');
    }
    
    public function healthCheck(): array
    {
        $results = [];
        
        foreach ($this->clusterConfig->getNodes() as $nodeKey => $node) {
            $healthy = $this->checkNodeHealth($node);
            
            $results[$nodeKey] = [
                'healthy' => $healthy,
                'host' => $node['host'],
                'port' => $node['port'],
                'role' => $node['role'],
                'last_check' => time(),
            ];
        }
        
        return $results;
    }
    
    private function getClusterConnection(): mixed
    {
        $type = $this->clusterConfig->getType();
        
        switch ($type) {
            case 'cluster':
                return $this->getRedisClusterConnection();
                
            case 'sentinel':
                return $this->getSentinelConnection();
                
            case 'replica':
                return $this->getReplicaConnection();
                
            default:
                throw new ConnectionException("Unsupported cluster type: {$type}");
        }
    }
    
    private function getRedisClusterConnection(): mixed
    {
        // For Redis Cluster, we need to handle slot routing
        $nodes = $this->clusterConfig->getNodes();
        
        // Try to get a healthy connection
        foreach ($nodes as $nodeKey => $node) {
            if ($node['status'] === 'active') {
                try {
                    $connection = $this->getConnectionForNode($node);
                    
                    if ($connection) {
                        return $connection;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to connect to cluster node', [
                        'node' => $nodeKey,
                        'error' => $e->getMessage(),
                    ]);
                    
                    // Mark node as unhealthy
                    $this->markNodeUnhealthy($nodeKey);
                }
            }
        }
        
        throw new ConnectionException('No healthy cluster nodes available');
    }
    
    private function getSentinelConnection(): mixed
    {
        $sentinels = $this->clusterConfig->getSentinelNodes();
        
        foreach ($sentinels as $sentinel) {
            try {
                $connection = $this->createConnection($sentinel);
                
                // Query sentinel for master information
                $masterInfo = $this->queryMasterFromSentinel($connection);
                
                if ($masterInfo) {
                    return $this->createConnection($masterInfo);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to connect to sentinel', [
                    'sentinel' => $sentinel,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        throw new ConnectionException('No healthy sentinel nodes available');
    }
    
    private function getReplicaConnection(): mixed
    {
        $master = $this->clusterConfig->getMasterNode();
        
        if ($master) {
            try {
                return $this->getConnectionForNode($master);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to connect to master, trying replicas', [
                    'error' => $e->getMessage(),
                ]);
                
                // Try replica nodes
                $replicas = $this->clusterConfig->getSlaveNodes();
                
                foreach ($replicas as $replica) {
                    try {
                        return $this->getConnectionForNode($replica);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to connect to replica', [
                            'replica' => $replica,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
        
        throw new ConnectionException('No healthy replica nodes available');
    }
    
    private function getConnectionForNode(array $node): mixed
    {
        $nodeKey = "{$node['host']}:{$node['port']}";
        
        // Check if we have a connection pool for this node
        if (!isset($this->nodeConnections[$nodeKey])) {
            $this->nodeConnections[$nodeKey] = [];
        }
        
        // Try to get an existing connection
        if (!empty($this->nodeConnections[$nodeKey])) {
            $connection = array_pop($this->nodeConnections[$nodeKey]);
            
            if ($this->testConnection($connection)) {
                $this->stats['active_connections']++;
                return $connection;
            } else {
                $this->destroyConnection($connection);
                $this->stats['failed_connections']++;
            }
        }
        
        // Create new connection
        $connection = $this->createConnection($node);
        $this->stats['total_connections']++;
        $this->stats['active_connections']++;
        
        return $connection;
    }
    
    private function createConnection(array $node): mixed
    {
        $config = RedisConfig::fromUri(
            "redis://{$node['host']}:{$node['port']}"
        );
        
        // Apply node-specific options
        if (isset($node['options']['password'])) {
            $config = $config->withPassword($node['options']['password']);
        }
        
        if (isset($node['options']['database'])) {
            $config = $config->withDatabase($node['options']['database']);
        }
        
        $config = $config->withConnectTimeout($this->clusterConfig->getConnectionTimeout());
        $config = $config->withTimeout($this->clusterConfig->getReadTimeout());
        
        return new RedisClient($config);
    }
    
    private function getSingleConnection(): mixed
    {
        if (!empty($this->connections)) {
            $connection = array_pop($this->connections);
            
            if ($this->testConnection($connection)) {
                $this->stats['active_connections']++;
                return $connection;
            } else {
                $this->destroyConnection($connection);
                $this->stats['failed_connections']++;
            }
        }
        
        // Create new connection
        $host = $this->config->get('redis.host', 'localhost');
        $port = $this->config->get('redis.port', 6379);
        
        $node = ['host' => $host, 'port' => $port, 'options' => []];
        $connection = $this->createConnection($node);
        
        $this->stats['total_connections']++;
        $this->stats['active_connections']++;
        
        return $connection;
    }
    
    private function testConnection(mixed $connection): bool
    {
        try {
            // Simple ping test
            $result = $connection->ping();
            return $result === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }
    
    private function destroyConnection(mixed $connection): void
    {
        try {
            if (method_exists($connection, 'close')) {
                $connection->close();
            }
        } catch (\Throwable) {
            // Ignore errors during connection cleanup
        }
    }
    
    private function initializeCluster(): void
    {
        if (!$this->clusterConfig->isEnabled()) {
            return;
        }
        
        // Validate cluster configuration
        $validation = $this->clusterConfig->validate();
        
        if (!$validation['valid']) {
            throw new ConnectionException(
                'Invalid cluster configuration: ' . implode(', ', $validation['errors'])
            );
        }
        
        // Log warnings
        foreach ($validation['warnings'] as $warning) {
            $this->logger->warning('Cluster configuration warning: ' . $warning);
        }
        
        // Auto-discover nodes if enabled
        if ($this->clusterConfig->isAutoDiscoveryEnabled()) {
            $this->clusterConfig->autoDiscover();
            $this->stats['node_discoveries']++;
        }
        
        $this->logger->info('Cluster-aware Redis connection pool initialized', [
            'type' => $this->clusterConfig->getType(),
            'nodes' => count($this->clusterConfig->getNodes()),
        ]);
    }
    
    private function warmUpCluster(): void
    {
        $nodes = $this->clusterConfig->getNodes();
        
        foreach ($nodes as $nodeKey => $node) {
            try {
                $connection = $this->createConnection($node);
                
                if ($this->testConnection($connection)) {
                    $this->nodeConnections[$nodeKey][] = $connection;
                    $this->logger->debug('Warmed up connection to node', ['node' => $nodeKey]);
                } else {
                    $this->destroyConnection($connection);
                    $this->markNodeUnhealthy($nodeKey);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to warm up connection to node', [
                    'node' => $nodeKey,
                    'error' => $e->getMessage(),
                ]);
                
                $this->markNodeUnhealthy($nodeKey);
            }
        }
    }
    
    private function warmUpSingle(): void
    {
        for ($i = 0; $i < $this->minConnections; $i++) {
            try {
                $connection = $this->getSingleConnection();
                $this->releaseConnection($connection);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to warm up single connection', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
    
    private function markNodeUnhealthy(string $nodeKey): void
    {
        $nodes = $this->clusterConfig->getNodes();
        
        if (isset($nodes[$nodeKey])) {
            $nodes[$nodeKey]['status'] = 'unhealthy';
            $nodes[$nodeKey]['last_check'] = time();
            
            $this->logger->warning('Marked node as unhealthy', ['node' => $nodeKey]);
        }
    }
    
    private function checkNodeHealth(array $node): bool
    {
        try {
            $connection = $this->createConnection($node);
            $healthy = $this->testConnection($connection);
            $this->destroyConnection($connection);
            
            return $healthy;
        } catch (\Throwable) {
            return false;
        }
    }
    
    private function queryMasterFromSentinel(mixed $connection): array|null
    {
        try {
            // This would query the sentinel for master information
            // Implementation depends on the specific sentinel protocol
            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}