<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Providers;

use HighPerApp\Cache\Contracts\CacheProviderInterface;
use Amphp\Redis\RedisClient;
use Amphp\Redis\RedisConfig;

/**
 * AWS ElastiCache provider (Redis-compatible with AWS optimizations)
 */
class AWSElastiCacheProvider implements CacheProviderInterface
{
    public function getName(): string
    {
        return 'aws_elasticache';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function isAvailable(): bool
    {
        return class_exists(RedisClient::class);
    }
    
    public function getCapabilities(): array
    {
        return [
            'persistent_connections' => true,
            'transactions' => true,
            'pub_sub' => true,
            'lua_scripts' => true,
            'clustering' => true,
            'replication' => true,
            'sentinel' => false,
            'streams' => true,
            'modules' => false,
            'acl' => true,
            'tls' => true,
            'compression' => false,
            'encryption' => true,
            'json' => false,
            'search' => false,
            'time_series' => false,
            'bloom_filter' => false,
            'graph' => false,
            'backup_restore' => true,
            'auto_failover' => true,
            'multi_az' => true,
            'monitoring' => true,
            'scaling' => true,
            'security_groups' => true,
            'vpc_support' => true,
        ];
    }
    
    public function getPerformanceLevel(): int
    {
        return 3; // High performance with AWS optimizations
    }
    
    public function createConnection(array $config): mixed
    {
        $host = $config['host'] ?? $config['endpoint'] ?? 'localhost';
        $port = $config['port'] ?? 6379;
        $database = $config['database'] ?? 0;
        $password = $config['password'] ?? $config['auth_token'] ?? null;
        $username = $config['username'] ?? null;
        
        // Handle ElastiCache configuration endpoint
        if (isset($config['configuration_endpoint'])) {
            $host = $config['configuration_endpoint'];
        }
        
        $uri = "redis://{$host}:{$port}";
        
        if ($username && $password) {
            $uri = "redis://{$username}:{$password}@{$host}:{$port}";
        } elseif ($password) {
            $uri = "redis://:{$password}@{$host}:{$port}";
        }
        
        $redisConfig = RedisConfig::fromUri($uri);
        
        if ($database > 0) {
            $redisConfig = $redisConfig->withDatabase($database);
        }
        
        // AWS ElastiCache optimizations
        $redisConfig = $redisConfig
            ->withConnectTimeout($config['connect_timeout'] ?? 5)
            ->withTimeout($config['read_timeout'] ?? 3);
        
        // Enable TLS for in-transit encryption
        if ($config['tls'] ?? $config['transit_encryption'] ?? false) {
            $tlsContext = $config['tls_context'] ?? [];
            
            // AWS-specific TLS settings
            $tlsContext = array_merge([
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => $config['ca_bundle'] ?? null,
            ], $tlsContext);
            
            $redisConfig = $redisConfig->withTlsContext($tlsContext);
        }
        
        return new RedisClient($redisConfig);
    }
    
    public function testConnection(mixed $connection): bool
    {
        try {
            $result = $connection->ping();
            return $result === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }
    
    public function getConnectionInfo(mixed $connection): array
    {
        try {
            $info = $connection->info();
            
            return [
                'provider' => $this->getName(),
                'server_version' => $info['redis_version'] ?? 'unknown',
                'os' => $info['os'] ?? 'unknown',
                'architecture' => $info['arch_bits'] ?? 'unknown',
                'mode' => $info['redis_mode'] ?? 'standalone',
                'role' => $info['role'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'used_memory' => $info['used_memory'] ?? 0,
                'used_memory_human' => $info['used_memory_human'] ?? '0B',
                'uptime_in_seconds' => $info['uptime_in_seconds'] ?? 0,
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'evicted_keys' => $info['evicted_keys'] ?? 0,
                'expired_keys' => $info['expired_keys'] ?? 0,
                'replication_offset' => $info['master_repl_offset'] ?? 0,
                'cluster_enabled' => $info['cluster_enabled'] ?? 0,
                'aws_region' => $info['aws_region'] ?? 'unknown',
                'aws_availability_zone' => $info['aws_availability_zone'] ?? 'unknown',
            ];
        } catch (\Throwable) {
            return [
                'provider' => $this->getName(),
                'error' => 'Failed to get connection info',
            ];
        }
    }
    
    public function getConfigurationOptions(): array
    {
        return [
            'host' => [
                'type' => 'string',
                'default' => 'localhost',
                'description' => 'ElastiCache endpoint hostname',
            ],
            'endpoint' => [
                'type' => 'string',
                'default' => null,
                'description' => 'ElastiCache endpoint (alias for host)',
            ],
            'configuration_endpoint' => [
                'type' => 'string',
                'default' => null,
                'description' => 'ElastiCache configuration endpoint for cluster mode',
            ],
            'port' => [
                'type' => 'integer',
                'default' => 6379,
                'description' => 'ElastiCache port',
            ],
            'database' => [
                'type' => 'integer',
                'default' => 0,
                'description' => 'Database number',
            ],
            'password' => [
                'type' => 'string',
                'default' => null,
                'description' => 'Authentication password',
            ],
            'auth_token' => [
                'type' => 'string',
                'default' => null,
                'description' => 'ElastiCache auth token (alias for password)',
            ],
            'username' => [
                'type' => 'string',
                'default' => null,
                'description' => 'Authentication username (RBAC)',
            ],
            'connect_timeout' => [
                'type' => 'integer',
                'default' => 5,
                'description' => 'Connection timeout in seconds',
            ],
            'read_timeout' => [
                'type' => 'integer',
                'default' => 3,
                'description' => 'Read timeout in seconds',
            ],
            'tls' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Enable TLS encryption',
            ],
            'transit_encryption' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Enable in-transit encryption (alias for tls)',
            ],
            'tls_context' => [
                'type' => 'array',
                'default' => [],
                'description' => 'TLS context options',
            ],
            'ca_bundle' => [
                'type' => 'string',
                'default' => null,
                'description' => 'Path to CA bundle file',
            ],
            'region' => [
                'type' => 'string',
                'default' => 'us-east-1',
                'description' => 'AWS region',
            ],
            'availability_zone' => [
                'type' => 'string',
                'default' => null,
                'description' => 'AWS availability zone',
            ],
            'cluster_mode' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Enable cluster mode',
            ],
            'auto_discovery' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Enable automatic node discovery',
            ],
            'failover' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Enable automatic failover',
            ],
            'multi_az' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Enable Multi-AZ deployment',
            ],
            'backup_retention' => [
                'type' => 'integer',
                'default' => 1,
                'description' => 'Backup retention period in days',
            ],
            'maintenance_window' => [
                'type' => 'string',
                'default' => null,
                'description' => 'Maintenance window (e.g., sun:05:00-sun:09:00)',
            ],
            'security_groups' => [
                'type' => 'array',
                'default' => [],
                'description' => 'Security group IDs',
            ],
            'parameter_group' => [
                'type' => 'string',
                'default' => null,
                'description' => 'ElastiCache parameter group',
            ],
            'subnet_group' => [
                'type' => 'string',
                'default' => null,
                'description' => 'ElastiCache subnet group',
            ],
            'tags' => [
                'type' => 'array',
                'default' => [],
                'description' => 'Resource tags',
            ],
        ];
    }
    
    public function validateConfiguration(array $config): array
    {
        $errors = [];
        $warnings = [];
        
        // Validate host or endpoint
        if (empty($config['host']) && empty($config['endpoint'])) {
            $errors[] = 'Host or endpoint is required';
        }
        
        // Validate port
        if (isset($config['port']) && ($config['port'] < 1 || $config['port'] > 65535)) {
            $errors[] = 'Port must be between 1 and 65535';
        }
        
        // Validate database
        if (isset($config['database']) && ($config['database'] < 0 || $config['database'] > 15)) {
            $warnings[] = 'Database should be between 0 and 15';
        }
        
        // Validate AWS region
        if (empty($config['region'])) {
            $warnings[] = 'AWS region should be specified';
        }
        
        // Validate TLS configuration
        if ($config['tls'] ?? $config['transit_encryption'] ?? false) {
            if (empty($config['tls_context']) && empty($config['ca_bundle'])) {
                $warnings[] = 'TLS is enabled but no TLS context or CA bundle provided';
            }
        }
        
        // Validate auth token
        if (!empty($config['username']) && empty($config['password']) && empty($config['auth_token'])) {
            $errors[] = 'Password or auth token is required when username is provided';
        }
        
        // Validate cluster mode
        if ($config['cluster_mode'] ?? false) {
            if (empty($config['configuration_endpoint'])) {
                $warnings[] = 'Configuration endpoint should be provided for cluster mode';
            }
        }
        
        // AWS-specific recommendations
        if (!($config['transit_encryption'] ?? $config['tls'] ?? false)) {
            $warnings[] = 'In-transit encryption is recommended for production ElastiCache';
        }
        
        if (!($config['multi_az'] ?? false)) {
            $warnings[] = 'Multi-AZ deployment is recommended for high availability';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
    
    public function getStats(mixed $connection): array
    {
        try {
            $info = $connection->info();
            
            return [
                'provider' => $this->getName(),
                'connections' => $info['connected_clients'] ?? 0,
                'memory_usage' => $info['used_memory'] ?? 0,
                'memory_usage_human' => $info['used_memory_human'] ?? '0B',
                'memory_peak' => $info['used_memory_peak'] ?? 0,
                'memory_peak_human' => $info['used_memory_peak_human'] ?? '0B',
                'keys' => $info['db0']['keys'] ?? 0,
                'expires' => $info['db0']['expires'] ?? 0,
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate($info),
                'evicted_keys' => $info['evicted_keys'] ?? 0,
                'expired_keys' => $info['expired_keys'] ?? 0,
                'commands_processed' => $info['total_commands_processed'] ?? 0,
                'uptime' => $info['uptime_in_seconds'] ?? 0,
                'version' => $info['redis_version'] ?? 'unknown',
                'mode' => $info['redis_mode'] ?? 'standalone',
                'role' => $info['role'] ?? 'unknown',
                'cluster_enabled' => $info['cluster_enabled'] ?? 0,
                'replication_offset' => $info['master_repl_offset'] ?? 0,
                'aws_region' => $info['aws_region'] ?? 'unknown',
                'aws_availability_zone' => $info['aws_availability_zone'] ?? 'unknown',
            ];
        } catch (\Throwable) {
            return [
                'provider' => $this->getName(),
                'error' => 'Failed to get stats',
            ];
        }
    }
    
    public function ping(mixed $connection): bool
    {
        return $this->testConnection($connection);
    }
    
    public function getFeatures(): array
    {
        return [
            'managed_service' => 'Fully managed Redis service',
            'auto_failover' => 'Automatic failover to read replicas',
            'multi_az' => 'Multi-Availability Zone deployment',
            'backup_restore' => 'Automated backup and restore',
            'scaling' => 'Horizontal and vertical scaling',
            'monitoring' => 'CloudWatch integration',
            'security_groups' => 'VPC security groups',
            'vpc_support' => 'Amazon VPC support',
            'encryption_at_rest' => 'Encryption at rest',
            'encryption_in_transit' => 'Encryption in transit',
            'compliance' => 'SOC, PCI DSS, HIPAA compliance',
            'clustering' => 'Redis Cluster support',
            'replication' => 'Master-slave replication',
            'transactions' => 'ACID transactions with MULTI/EXEC',
            'lua_scripts' => 'Server-side Lua scripting',
            'pub_sub' => 'Publish/Subscribe messaging',
            'streams' => 'Redis Streams data structure',
            'acl' => 'Access Control Lists',
            'maintenance_windows' => 'Scheduled maintenance windows',
            'parameter_groups' => 'Custom parameter groups',
            'subnet_groups' => 'Custom subnet groups',
        ];
    }
    
    public function supportsFeature(string $feature): bool
    {
        return array_key_exists($feature, $this->getFeatures());
    }
    
    public function getDocumentationUrl(): string
    {
        return 'https://docs.aws.amazon.com/elasticache/latest/red-ug/';
    }
    
    public function getSupportInfo(): array
    {
        return [
            'provider' => $this->getName(),
            'maintainer' => 'Amazon Web Services',
            'license' => 'Proprietary',
            'stability' => 'production',
            'compatibility' => 'Redis Protocol Compatible',
            'documentation' => $this->getDocumentationUrl(),
            'support' => 'AWS Support',
            'console' => 'https://console.aws.amazon.com/elasticache/',
            'pricing' => 'https://aws.amazon.com/elasticache/pricing/',
            'sla' => 'https://aws.amazon.com/elasticache/sla/',
        ];
    }
    
    private function calculateHitRate(array $info): float
    {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;
        
        if ($total === 0) {
            return 0.0;
        }
        
        return round(($hits / $total) * 100, 2);
    }
}