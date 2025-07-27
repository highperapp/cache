<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Cluster;

use HighPerApp\Cache\Contracts\ClusterConfigurationInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Exceptions\ConfigurationException;

/**
 * Redis cluster configuration
 */
class RedisClusterConfiguration implements ClusterConfigurationInterface
{
    private array $nodes = [];
    private string $type;
    private array $options = [];
    private ConfigurationInterface $config;
    
    public function __construct(ConfigurationInterface $config)
    {
        $this->config = $config;
        $this->type = $config->get('redis.cluster.type', 'cluster');
        $this->options = $config->get('redis.cluster.options', []);
        
        $this->loadNodesFromConfig();
    }
    
    public function getNodes(): array
    {
        return $this->nodes;
    }
    
    public function addNode(string $host, int $port, array $options = []): void
    {
        $nodeKey = "{$host}:{$port}";
        
        $this->nodes[$nodeKey] = [
            'host' => $host,
            'port' => $port,
            'options' => $options,
            'role' => $options['role'] ?? 'unknown',
            'priority' => $options['priority'] ?? 0,
            'weight' => $options['weight'] ?? 1,
            'status' => $options['status'] ?? 'active',
            'last_check' => time(),
        ];
    }
    
    public function removeNode(string $host, int $port): bool
    {
        $nodeKey = "{$host}:{$port}";
        
        if (isset($this->nodes[$nodeKey])) {
            unset($this->nodes[$nodeKey]);
            return true;
        }
        
        return false;
    }
    
    public function getType(): string
    {
        return $this->type;
    }
    
    public function getOptions(): array
    {
        return $this->options;
    }
    
    public function setOptions(array $options): void
    {
        $this->options = array_merge($this->options, $options);
    }
    
    public function isEnabled(): bool
    {
        return $this->config->get('redis.cluster.enabled', false) && !empty($this->nodes);
    }
    
    public function getMasterNode(): array|null
    {
        foreach ($this->nodes as $node) {
            if ($node['role'] === 'master' || $node['role'] === 'primary') {
                return $node;
            }
        }
        
        return null;
    }
    
    public function getSlaveNodes(): array
    {
        $slaves = [];
        
        foreach ($this->nodes as $node) {
            if ($node['role'] === 'slave' || $node['role'] === 'replica') {
                $slaves[] = $node;
            }
        }
        
        return $slaves;
    }
    
    public function getSentinelNodes(): array
    {
        $sentinels = [];
        
        foreach ($this->nodes as $node) {
            if ($node['role'] === 'sentinel') {
                $sentinels[] = $node;
            }
        }
        
        return $sentinels;
    }
    
    public function getHashSlots(): array
    {
        return $this->options['hash_slots'] ?? [];
    }
    
    public function getConsistencyLevel(): string
    {
        return $this->options['consistency_level'] ?? 'eventual';
    }
    
    public function getReadPreference(): string
    {
        return $this->options['read_preference'] ?? 'primary';
    }
    
    public function getWriteConcern(): string
    {
        return $this->options['write_concern'] ?? 'majority';
    }
    
    public function getConnectionTimeout(): int
    {
        return $this->options['connection_timeout'] ?? 5;
    }
    
    public function getReadTimeout(): int
    {
        return $this->options['read_timeout'] ?? 3;
    }
    
    public function getRetryAttempts(): int
    {
        return $this->options['retry_attempts'] ?? 3;
    }
    
    public function getRetryDelay(): int
    {
        return $this->options['retry_delay'] ?? 100;
    }
    
    public function getHealthCheckInterval(): int
    {
        return $this->options['health_check_interval'] ?? 60;
    }
    
    public function isAutoDiscoveryEnabled(): bool
    {
        return $this->options['auto_discovery'] ?? true;
    }
    
    public function validate(): array
    {
        $errors = [];
        $warnings = [];
        
        // Check if we have nodes
        if (empty($this->nodes)) {
            $errors[] = 'No cluster nodes configured';
        }
        
        // Validate cluster type
        $validTypes = ['cluster', 'sentinel', 'replica'];
        if (!in_array($this->type, $validTypes)) {
            $errors[] = "Invalid cluster type: {$this->type}";
        }
        
        // Type-specific validation
        switch ($this->type) {
            case 'cluster':
                if (count($this->nodes) < 3) {
                    $warnings[] = 'Redis Cluster should have at least 3 nodes';
                }
                break;
                
            case 'sentinel':
                $sentinels = $this->getSentinelNodes();
                if (count($sentinels) < 3) {
                    $warnings[] = 'Redis Sentinel should have at least 3 sentinel nodes';
                }
                
                if ($this->getMasterNode() === null) {
                    $errors[] = 'Redis Sentinel requires a master node';
                }
                break;
                
            case 'replica':
                if ($this->getMasterNode() === null) {
                    $errors[] = 'Redis Replica requires a master node';
                }
                
                if (empty($this->getSlaveNodes())) {
                    $warnings[] = 'Redis Replica should have at least one slave node';
                }
                break;
        }
        
        // Validate node connectivity
        foreach ($this->nodes as $nodeKey => $node) {
            if (!$this->validateNodeConnectivity($node)) {
                $warnings[] = "Node {$nodeKey} may not be reachable";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
    
    public function getStatus(): array
    {
        $status = [
            'type' => $this->type,
            'enabled' => $this->isEnabled(),
            'nodes' => count($this->nodes),
            'master_nodes' => count($this->getMasterNode() ? [1] : []),
            'slave_nodes' => count($this->getSlaveNodes()),
            'sentinel_nodes' => count($this->getSentinelNodes()),
            'healthy_nodes' => 0,
            'unhealthy_nodes' => 0,
            'options' => $this->options,
        ];
        
        foreach ($this->nodes as $node) {
            if ($node['status'] === 'active') {
                $status['healthy_nodes']++;
            } else {
                $status['unhealthy_nodes']++;
            }
        }
        
        return $status;
    }
    
    /**
     * Load nodes from configuration
     */
    private function loadNodesFromConfig(): void
    {
        // Load from environment variables
        $this->loadNodesFromEnvironment();
        
        // Load from config file
        $configNodes = $this->config->get('redis.cluster.nodes', []);
        
        foreach ($configNodes as $node) {
            $this->addNode(
                $node['host'],
                $node['port'],
                $node['options'] ?? []
            );
        }
    }
    
    /**
     * Load nodes from environment variables
     */
    private function loadNodesFromEnvironment(): void
    {
        // Support various environment variable formats
        $envPatterns = [
            'REDIS_CLUSTER_NODES',
            'REDIS_SENTINEL_NODES',
            'REDIS_REPLICA_NODES',
        ];
        
        foreach ($envPatterns as $pattern) {
            $envValue = $_ENV[$pattern] ?? getenv($pattern);
            
            if ($envValue) {
                $this->parseNodesFromString($envValue);
            }
        }
        
        // Support individual node environment variables
        $nodeIndex = 0;
        
        while (true) {
            $hostEnv = $_ENV["REDIS_NODE_{$nodeIndex}_HOST"] ?? getenv("REDIS_NODE_{$nodeIndex}_HOST");
            $portEnv = $_ENV["REDIS_NODE_{$nodeIndex}_PORT"] ?? getenv("REDIS_NODE_{$nodeIndex}_PORT");
            
            if (!$hostEnv || !$portEnv) {
                break;
            }
            
            $roleEnv = $_ENV["REDIS_NODE_{$nodeIndex}_ROLE"] ?? getenv("REDIS_NODE_{$nodeIndex}_ROLE");
            $priorityEnv = $_ENV["REDIS_NODE_{$nodeIndex}_PRIORITY"] ?? getenv("REDIS_NODE_{$nodeIndex}_PRIORITY");
            
            $this->addNode($hostEnv, (int)$portEnv, [
                'role' => $roleEnv ?: 'unknown',
                'priority' => $priorityEnv ? (int)$priorityEnv : 0,
            ]);
            
            $nodeIndex++;
        }
    }
    
    /**
     * Parse nodes from string format
     */
    private function parseNodesFromString(string $nodesString): void
    {
        // Support formats:
        // - "host1:port1,host2:port2"
        // - "host1:port1:role1,host2:port2:role2"
        // - JSON array
        
        if (str_starts_with($nodesString, '[') || str_starts_with($nodesString, '{')) {
            // JSON format
            $nodes = json_decode($nodesString, true);
            
            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    $this->addNode(
                        $node['host'],
                        $node['port'],
                        $node['options'] ?? []
                    );
                }
            }
        } else {
            // String format
            $nodeStrings = explode(',', $nodesString);
            
            foreach ($nodeStrings as $nodeString) {
                $parts = explode(':', trim($nodeString));
                
                if (count($parts) >= 2) {
                    $host = $parts[0];
                    $port = (int)$parts[1];
                    $role = $parts[2] ?? 'unknown';
                    $priority = isset($parts[3]) ? (int)$parts[3] : 0;
                    
                    $this->addNode($host, $port, [
                        'role' => $role,
                        'priority' => $priority,
                    ]);
                }
            }
        }
    }
    
    /**
     * Validate node connectivity
     */
    private function validateNodeConnectivity(array $node): bool
    {
        $host = $node['host'];
        $port = $node['port'];
        $timeout = $this->getConnectionTimeout();
        
        // Simple TCP connection check
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        
        if ($socket) {
            fclose($socket);
            return true;
        }
        
        return false;
    }
    
    /**
     * Auto-discover cluster nodes
     */
    public function autoDiscover(): bool
    {
        if (!$this->isAutoDiscoveryEnabled()) {
            return false;
        }
        
        // This would implement cluster node discovery
        // For Redis Cluster, this would use CLUSTER NODES command
        // For Sentinel, this would use SENTINEL MASTERS/SLAVES commands
        
        return true;
    }
    
    /**
     * Get balanced node for read operations
     */
    public function getReadNode(): array|null
    {
        $preference = $this->getReadPreference();
        
        switch ($preference) {
            case 'primary':
                return $this->getMasterNode();
                
            case 'secondary':
                $slaves = $this->getSlaveNodes();
                return $this->selectNodeByWeight($slaves);
                
            case 'any':
            default:
                return $this->selectNodeByWeight($this->nodes);
        }
    }
    
    /**
     * Get node for write operations
     */
    public function getWriteNode(): array|null
    {
        // Always write to master/primary
        return $this->getMasterNode();
    }
    
    /**
     * Select node based on weight and health
     */
    private function selectNodeByWeight(array $nodes): array|null
    {
        if (empty($nodes)) {
            return null;
        }
        
        // Filter healthy nodes
        $healthyNodes = array_filter($nodes, function($node) {
            return $node['status'] === 'active';
        });
        
        if (empty($healthyNodes)) {
            return null;
        }
        
        // Simple weighted selection
        $totalWeight = array_sum(array_column($healthyNodes, 'weight'));
        $random = mt_rand(1, $totalWeight);
        $currentWeight = 0;
        
        foreach ($healthyNodes as $node) {
            $currentWeight += $node['weight'];
            
            if ($random <= $currentWeight) {
                return $node;
            }
        }
        
        return reset($healthyNodes);
    }
}