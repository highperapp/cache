<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Providers;

use HighPerApp\Cache\Contracts\CacheProviderInterface;
use Amphp\Redis\RedisClient;
use Amphp\Redis\RedisConfig;

/**
 * Dragonfly cache provider (Redis-compatible with optimizations)
 */
class DragonflyProvider implements CacheProviderInterface
{
    public function getName(): string
    {
        return 'dragonfly';
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
            'clustering' => false, // Dragonfly doesn't support Redis Cluster
            'replication' => true,
            'sentinel' => false,
            'streams' => true,
            'modules' => false,
            'acl' => true,
            'tls' => true,
            'compression' => true,
            'encryption' => false,
            'json' => true,
            'search' => false,
            'time_series' => false,
            'bloom_filter' => false,
            'graph' => false,
            'high_memory_efficiency' => true,
            'multi_threading' => true,
            'snapshot_consistency' => true,
        ];
    }
    
    public function getPerformanceLevel(): int
    {
        return 4; // Very high performance (optimized for modern hardware)
    }
    
    public function createConnection(array $config): mixed
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 6379;
        $database = $config['database'] ?? 0;
        $password = $config['password'] ?? null;
        $username = $config['username'] ?? null;
        
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
        
        // Dragonfly-specific optimizations
        $redisConfig = $redisConfig
            ->withConnectTimeout($config['connect_timeout'] ?? 2) // Faster connection
            ->withTimeout($config['read_timeout'] ?? 1); // Faster reads
        
        // Enable TLS if configured
        if ($config['tls'] ?? false) {
            $redisConfig = $redisConfig->withTlsContext($config['tls_context'] ?? []);
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
                'dragonfly_version' => $info['dragonfly_version'] ?? 'unknown',
                'os' => $info['os'] ?? 'unknown',
                'architecture' => $info['arch_bits'] ?? 'unknown',
                'mode' => 'standalone', // Dragonfly is always standalone
                'role' => $info['role'] ?? 'master',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'used_memory' => $info['used_memory'] ?? 0,
                'used_memory_human' => $info['used_memory_human'] ?? '0B',
                'uptime_in_seconds' => $info['uptime_in_seconds'] ?? 0,
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'evicted_keys' => $info['evicted_keys'] ?? 0,
                'expired_keys' => $info['expired_keys'] ?? 0,
                'threads' => $info['threads'] ?? 1,
                'fiber_switches' => $info['fiber_switches'] ?? 0,
                'memory_efficiency' => $info['memory_efficiency'] ?? 0,
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
                'description' => 'Dragonfly server hostname',
            ],
            'port' => [
                'type' => 'integer',
                'default' => 6379,
                'description' => 'Dragonfly server port',
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
            'username' => [
                'type' => 'string',
                'default' => null,
                'description' => 'Authentication username (ACL)',
            ],
            'connect_timeout' => [
                'type' => 'integer',
                'default' => 2,
                'description' => 'Connection timeout in seconds (optimized for Dragonfly)',
            ],
            'read_timeout' => [
                'type' => 'integer',
                'default' => 1,
                'description' => 'Read timeout in seconds (optimized for Dragonfly)',
            ],
            'tls' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Enable TLS encryption',
            ],
            'tls_context' => [
                'type' => 'array',
                'default' => [],
                'description' => 'TLS context options',
            ],
            'persistent' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Use persistent connections (recommended for Dragonfly)',
            ],
            'prefix' => [
                'type' => 'string',
                'default' => '',
                'description' => 'Key prefix',
            ],
            'serializer' => [
                'type' => 'string',
                'default' => 'php',
                'description' => 'Serialization method',
            ],
            'compression' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Enable compression (efficient in Dragonfly)',
            ],
            'pipeline_size' => [
                'type' => 'integer',
                'default' => 100,
                'description' => 'Pipeline batch size for bulk operations',
            ],
            'memory_optimization' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Enable memory optimizations',
            ],
        ];
    }
    
    public function validateConfiguration(array $config): array
    {
        $errors = [];
        $warnings = [];
        
        // Validate host
        if (empty($config['host'])) {
            $errors[] = 'Host is required';
        }
        
        // Validate port
        if (isset($config['port']) && ($config['port'] < 1 || $config['port'] > 65535)) {
            $errors[] = 'Port must be between 1 and 65535';
        }
        
        // Validate database
        if (isset($config['database']) && ($config['database'] < 0 || $config['database'] > 15)) {
            $warnings[] = 'Database should be between 0 and 15';
        }
        
        // Validate TLS configuration
        if ($config['tls'] ?? false) {
            if (empty($config['tls_context'])) {
                $warnings[] = 'TLS is enabled but no TLS context provided';
            }
        }
        
        // Validate ACL configuration
        if (!empty($config['username']) && empty($config['password'])) {
            $errors[] = 'Password is required when username is provided';
        }
        
        // Dragonfly-specific recommendations
        if (isset($config['persistent']) && !$config['persistent']) {
            $warnings[] = 'Persistent connections are recommended for Dragonfly';
        }
        
        if (isset($config['compression']) && !$config['compression']) {
            $warnings[] = 'Compression is highly efficient in Dragonfly and recommended';
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
                'memory_efficiency' => $info['memory_efficiency'] ?? 0,
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
                'dragonfly_version' => $info['dragonfly_version'] ?? 'unknown',
                'threads' => $info['threads'] ?? 1,
                'fiber_switches' => $info['fiber_switches'] ?? 0,
                'mode' => 'standalone',
                'role' => $info['role'] ?? 'master',
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
            'multi_threading' => 'Native multi-threading for high performance',
            'memory_efficiency' => 'Optimized memory usage with advanced data structures',
            'snapshot_consistency' => 'Consistent point-in-time snapshots',
            'high_throughput' => 'Optimized for high-throughput workloads',
            'low_latency' => 'Sub-millisecond latency for most operations',
            'replication' => 'Master-slave replication',
            'transactions' => 'ACID transactions with MULTI/EXEC',
            'lua_scripts' => 'Server-side Lua scripting',
            'pub_sub' => 'Publish/Subscribe messaging',
            'streams' => 'Redis Streams data structure',
            'acl' => 'Access Control Lists',
            'tls' => 'TLS encryption support',
            'json' => 'JSON data type support',
            'compression' => 'Built-in compression support',
            'pipelining' => 'Efficient command pipelining',
            'bulk_operations' => 'Optimized bulk operations',
        ];
    }
    
    public function supportsFeature(string $feature): bool
    {
        return array_key_exists($feature, $this->getFeatures());
    }
    
    public function getDocumentationUrl(): string
    {
        return 'https://dragonflydb.io/docs/';
    }
    
    public function getSupportInfo(): array
    {
        return [
            'provider' => $this->getName(),
            'maintainer' => 'DragonflyDB',
            'license' => 'BSL 1.1',
            'stability' => 'stable',
            'compatibility' => 'Redis Protocol Compatible',
            'documentation' => $this->getDocumentationUrl(),
            'community' => 'https://github.com/dragonflydb/dragonfly',
            'issues' => 'https://github.com/dragonflydb/dragonfly/issues',
            'releases' => 'https://github.com/dragonflydb/dragonfly/releases',
            'discord' => 'https://discord.gg/HsPjXGVH85',
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