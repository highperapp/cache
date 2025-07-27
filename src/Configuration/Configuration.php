<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Configuration;

use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Exceptions\ConfigurationException;
use InvalidArgumentException;

/**
 * Configuration manager with environment variable support
 */
class Configuration implements ConfigurationInterface
{
    private array $config = [];
    private array $defaults = [];
    private array $schema = [];
    
    public function __construct(array $config = [], array $defaults = [])
    {
        $this->defaults = $this->getDefaultConfiguration();
        $this->schema = $this->getConfigurationSchema();
        
        if (!empty($defaults)) {
            $this->defaults = array_merge($this->defaults, $defaults);
        }
        
        $this->loadFromEnvironment();
        
        if (!empty($config)) {
            $this->merge($config);
        }
    }
    
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default ?? $this->getDefault($key);
            }
            $value = $value[$segment];
        }
        
        return $value;
    }
    
    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;
        
        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                $config[$segment] = $value;
            } else {
                if (!isset($config[$segment]) || !is_array($config[$segment])) {
                    $config[$segment] = [];
                }
                $config = &$config[$segment];
            }
        }
    }
    
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }
        
        return true;
    }
    
    public function merge(array $config): void
    {
        $this->config = array_merge_recursive($this->config, $config);
    }
    
    public function toArray(): array
    {
        return $this->config;
    }
    
    public function validate(): array
    {
        $errors = [];
        
        foreach ($this->schema as $key => $rules) {
            $value = $this->get($key);
            
            foreach ($rules as $rule => $constraint) {
                switch ($rule) {
                    case 'required':
                        if ($constraint && $value === null) {
                            $errors[] = "Configuration key '{$key}' is required";
                        }
                        break;
                        
                    case 'type':
                        if ($value !== null && !$this->validateType($value, $constraint)) {
                            $errors[] = "Configuration key '{$key}' must be of type {$constraint}";
                        }
                        break;
                        
                    case 'in':
                        if ($value !== null && !in_array($value, $constraint, true)) {
                            $allowed = implode(', ', $constraint);
                            $errors[] = "Configuration key '{$key}' must be one of: {$allowed}";
                        }
                        break;
                        
                    case 'min':
                        if (is_numeric($value) && $value < $constraint) {
                            $errors[] = "Configuration key '{$key}' must be at least {$constraint}";
                        }
                        break;
                        
                    case 'max':
                        if (is_numeric($value) && $value > $constraint) {
                            $errors[] = "Configuration key '{$key}' must be at most {$constraint}";
                        }
                        break;
                }
            }
        }
        
        return $errors;
    }
    
    public function getSchema(): array
    {
        return $this->schema;
    }
    
    public function loadFromEnvironment(): void
    {
        $this->config = array_merge($this->defaults, [
            'engine' => $_ENV['CACHE_ENGINE'] ?? 'auto',
            'rust_ffi_enabled' => $this->parseBool($_ENV['CACHE_RUST_FFI_ENABLED'] ?? 'true'),
            'amphp_parallel_enabled' => $this->parseBool($_ENV['CACHE_AMPHP_PARALLEL_ENABLED'] ?? 'true'),
            'uv_enabled' => $this->parseBool($_ENV['CACHE_UV_ENABLED'] ?? 'true'),
            'default_store' => $_ENV['CACHE_DEFAULT_STORE'] ?? 'redis',
            'async_threshold' => (int)($_ENV['CACHE_ASYNC_THRESHOLD'] ?? 1000),
            'batch_size' => (int)($_ENV['CACHE_BATCH_SIZE'] ?? 100),
            'memory_limit' => $_ENV['CACHE_MEMORY_LIMIT'] ?? '256M',
            'ttl_default' => (int)($_ENV['CACHE_TTL_DEFAULT'] ?? 3600),
            'stats_enabled' => $this->parseBool($_ENV['CACHE_STATS_ENABLED'] ?? 'true'),
            'debug' => $this->parseBool($_ENV['CACHE_DEBUG'] ?? 'false'),
            'log_level' => $_ENV['CACHE_LOG_LEVEL'] ?? 'info',
            
            'redis' => [
                'host' => $_ENV['CACHE_REDIS_HOST'] ?? '127.0.0.1',
                'port' => (int)($_ENV['CACHE_REDIS_PORT'] ?? 6379),
                'password' => $_ENV['CACHE_REDIS_PASSWORD'] ?? null,
                'database' => (int)($_ENV['CACHE_REDIS_DATABASE'] ?? 0),
                'pool_min' => (int)($_ENV['CACHE_REDIS_POOL_MIN'] ?? 5),
                'pool_max' => (int)($_ENV['CACHE_REDIS_POOL_MAX'] ?? 20),
                'timeout' => (int)($_ENV['CACHE_REDIS_TIMEOUT'] ?? 30),
                'retry_delay' => (int)($_ENV['CACHE_REDIS_RETRY_DELAY'] ?? 100),
                
                // Cluster configuration
                'cluster' => [
                    'enabled' => $this->parseBool($_ENV['REDIS_CLUSTER_ENABLED'] ?? 'false'),
                    'type' => $_ENV['REDIS_CLUSTER_TYPE'] ?? 'cluster',
                    'auto_discovery' => $this->parseBool($_ENV['REDIS_CLUSTER_AUTO_DISCOVERY'] ?? 'true'),
                    'read_preference' => $_ENV['REDIS_CLUSTER_READ_PREFERENCE'] ?? 'primary',
                    'write_concern' => $_ENV['REDIS_CLUSTER_WRITE_CONCERN'] ?? 'majority',
                    'connection_timeout' => (int)($_ENV['REDIS_CLUSTER_CONNECTION_TIMEOUT'] ?? 5),
                    'read_timeout' => (int)($_ENV['REDIS_CLUSTER_READ_TIMEOUT'] ?? 3),
                    'retry_attempts' => (int)($_ENV['REDIS_CLUSTER_RETRY_ATTEMPTS'] ?? 3),
                    'retry_delay' => (int)($_ENV['REDIS_CLUSTER_RETRY_DELAY'] ?? 100),
                    'health_check_interval' => (int)($_ENV['REDIS_CLUSTER_HEALTH_CHECK_INTERVAL'] ?? 60),
                    'nodes' => $this->loadClusterNodesFromEnvironment(),
                ],
            ],
            
            'memcached' => [
                'servers' => explode(',', $_ENV['CACHE_MEMCACHED_SERVERS'] ?? '127.0.0.1:11211'),
                'pool_min' => (int)($_ENV['CACHE_MEMCACHED_POOL_MIN'] ?? 3),
                'pool_max' => (int)($_ENV['CACHE_MEMCACHED_POOL_MAX'] ?? 15),
                'timeout' => (int)($_ENV['CACHE_MEMCACHED_TIMEOUT'] ?? 30),
                'retry_delay' => (int)($_ENV['CACHE_MEMCACHED_RETRY_DELAY'] ?? 100),
            ],
            
            'file' => [
                'path' => $_ENV['CACHE_FILE_PATH'] ?? 'storage/cache',
                'permissions' => octdec($_ENV['CACHE_FILE_PERMISSIONS'] ?? '0755'),
            ],
            
            'memory' => [
                'max_size' => $_ENV['CACHE_MEMORY_MAX_SIZE'] ?? '100M',
                'cleanup_interval' => (int)($_ENV['CACHE_MEMORY_CLEANUP_INTERVAL'] ?? 300),
            ],
            
            'amphp' => [
                'workers' => $_ENV['CACHE_AMPHP_WORKERS'] ?? 'auto',
                'task_limit' => (int)($_ENV['CACHE_AMPHP_TASK_LIMIT'] ?? 1000),
                'worker_memory_limit' => $_ENV['CACHE_AMPHP_WORKER_MEMORY_LIMIT'] ?? '256M',
            ],
            
            'ffi' => [
                'library_path' => $_ENV['CACHE_FFI_LIBRARY_PATH'] ?? 'src/FFI/libhighper_cache.so',
                'enabled' => $this->parseBool($_ENV['CACHE_FFI_ENABLED'] ?? 'true'),
            ],
        ]);
    }
    
    public function loadFromFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new ConfigurationException("Configuration file not found: {$path}");
        }
        
        $config = require $path;
        
        if (!is_array($config)) {
            throw new ConfigurationException("Configuration file must return an array: {$path}");
        }
        
        $this->merge($config);
    }
    
    public function saveToFile(string $path): bool
    {
        $content = "<?php\n\nreturn " . var_export($this->config, true) . ";\n";
        
        return file_put_contents($path, $content) !== false;
    }
    
    private function getDefault(string $key): mixed
    {
        $keys = explode('.', $key);
        $value = $this->defaults;
        
        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        
        return $value;
    }
    
    private function getDefaultConfiguration(): array
    {
        return [
            'engine' => 'auto',
            'rust_ffi_enabled' => true,
            'amphp_parallel_enabled' => true,
            'uv_enabled' => true,
            'default_store' => 'redis',
            'async_threshold' => 1000,
            'batch_size' => 100,
            'memory_limit' => '256M',
            'ttl_default' => 3600,
            'stats_enabled' => true,
            'debug' => false,
            'log_level' => 'info',
        ];
    }
    
    private function getConfigurationSchema(): array
    {
        return [
            'engine' => [
                'type' => 'string',
                'in' => ['auto', 'rust_ffi', 'amphp_parallel', 'pure_php'],
            ],
            'rust_ffi_enabled' => [
                'type' => 'boolean',
            ],
            'amphp_parallel_enabled' => [
                'type' => 'boolean',
            ],
            'uv_enabled' => [
                'type' => 'boolean',
            ],
            'default_store' => [
                'type' => 'string',
                'in' => ['redis', 'memcached', 'memory', 'file'],
            ],
            'async_threshold' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 100000,
            ],
            'batch_size' => [
                'type' => 'integer',
                'min' => 1,
                'max' => 10000,
            ],
            'ttl_default' => [
                'type' => 'integer',
                'min' => 0,
            ],
        ];
    }
    
    private function validateType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'float' => is_float($value),
            'numeric' => is_numeric($value),
            default => false,
        };
    }
    
    private function parseBool(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
    
    /**
     * Load cluster nodes from environment variables
     */
    private function loadClusterNodesFromEnvironment(): array
    {
        $nodes = [];
        
        // Support comma-separated node list
        $nodesList = $_ENV['REDIS_CLUSTER_NODES'] ?? null;
        if ($nodesList) {
            $nodeStrings = explode(',', $nodesList);
            
            foreach ($nodeStrings as $nodeString) {
                $parts = explode(':', trim($nodeString));
                
                if (count($parts) >= 2) {
                    $nodes[] = [
                        'host' => $parts[0],
                        'port' => (int)$parts[1],
                        'options' => [
                            'role' => $parts[2] ?? 'unknown',
                            'priority' => isset($parts[3]) ? (int)$parts[3] : 0,
                            'weight' => isset($parts[4]) ? (int)$parts[4] : 1,
                        ],
                    ];
                }
            }
        }
        
        // Support individual node environment variables
        $nodeIndex = 0;
        while (true) {
            $host = $_ENV["REDIS_CLUSTER_NODE_{$nodeIndex}_HOST"] ?? null;
            $port = $_ENV["REDIS_CLUSTER_NODE_{$nodeIndex}_PORT"] ?? null;
            
            if (!$host || !$port) {
                break;
            }
            
            $nodes[] = [
                'host' => $host,
                'port' => (int)$port,
                'options' => [
                    'role' => $_ENV["REDIS_CLUSTER_NODE_{$nodeIndex}_ROLE"] ?? 'unknown',
                    'priority' => (int)($_ENV["REDIS_CLUSTER_NODE_{$nodeIndex}_PRIORITY"] ?? 0),
                    'weight' => (int)($_ENV["REDIS_CLUSTER_NODE_{$nodeIndex}_WEIGHT"] ?? 1),
                    'password' => $_ENV["REDIS_CLUSTER_NODE_{$nodeIndex}_PASSWORD"] ?? null,
                    'database' => (int)($_ENV["REDIS_CLUSTER_NODE_{$nodeIndex}_DATABASE"] ?? 0),
                ],
            ];
            
            $nodeIndex++;
        }
        
        // Support JSON format from environment
        $jsonNodes = $_ENV['REDIS_CLUSTER_NODES_JSON'] ?? null;
        if ($jsonNodes) {
            $decoded = json_decode($jsonNodes, true);
            if (is_array($decoded)) {
                $nodes = array_merge($nodes, $decoded);
            }
        }
        
        return $nodes;
    }
}