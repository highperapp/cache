# HighPer Cache

High-Performance Cache Manager with Redis, Memcached, and in-memory support for ultra-fast data access with Rust FFI optimization and PHP 8.3+ support.

## Features

- 🚀 **Multi-Driver Support**: Redis, Memcached, Valkey, Dragonfly, AWS ElastiCache, and in-memory caching
- ⚡ **Async Operations**: Non-blocking cache operations with AMPHP v3 and revolt/eventloop
- 🦀 **Rust FFI Integration**: High-performance Rust FFI with transparent fallback to pure PHP
- 🔄 **Auto-Expiration**: TTL-based cache expiration management
- 📊 **Cache Statistics**: Performance metrics and hit/miss ratios
- 🧮 **Serialization**: Automatic data serialization/deserialization
- 🔧 **Configurable**: Environment-driven configuration
- 🎯 **Interface-Driven**: Clean architecture with no abstract classes
- 🌐 **Cluster Support**: Redis Cluster, Sentinel, and replica configurations
- 📦 **Session Store**: Full session handler with locking and metadata
- 🔀 **HTTP Middleware**: PSR-15 compatible cache middleware
- 📋 **PSR-16 Compatible**: Simple Cache Interface compliance

## Installation

```bash
composer require highperapp/cache
```

## Requirements

- **PHP 8.3+** (with 8.4 support)
- **AMPHP v3+** with revolt/eventloop
- **Optional**: Rust FFI for maximum performance
- **Redis server** (for Redis driver)
- **Memcached server** (for Memcached driver)

## Quick Start

```php
<?php
use HighPerApp\Cache\ServiceProvider;

// Auto-configure from environment
$cache = ServiceProvider::createFromEnv();

// Or configure manually
$cache = ServiceProvider::createWithConfig([
    'engine' => 'auto',
    'default_store' => 'redis',
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379
    ]
]);

// Store data
yield $cache->set('user:123', ['name' => 'John', 'email' => 'john@example.com'], 3600);

// Retrieve data
$user = yield $cache->get('user:123');

// Check if exists
$exists = yield $cache->has('user:123');

// Delete data
yield $cache->delete('user:123');
```

## Supported Cache Providers

### Redis Driver
```php
$config = [
    'default_store' => 'redis',
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
        'database' => 0,
        'pool_min' => 5,
        'pool_max' => 20
    ]
];
```

### Valkey Driver
```php
$config = [
    'default_store' => 'valkey',
    'valkey' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'memory_efficiency' => true,
        'multi_threading' => true
    ]
];
```

### Dragonfly Driver
```php
$config = [
    'default_store' => 'dragonfly',
    'dragonfly' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'snapshot_enabled' => true,
        'memory_defrag' => true
    ]
];
```

### AWS ElastiCache
```php
$config = [
    'default_store' => 'aws_elasticache',
    'aws_elasticache' => [
        'cluster_endpoint' => 'my-cluster.cache.amazonaws.com',
        'port' => 6379,
        'tls' => true,
        'multi_az' => true,
        'auto_failover' => true
    ]
];
```

## Cluster Configuration

### Redis Cluster
```php
$config = [
    'redis' => [
        'cluster' => [
            'enabled' => true,
            'type' => 'cluster',
            'nodes' => [
                ['host' => '127.0.0.1', 'port' => 7000],
                ['host' => '127.0.0.1', 'port' => 7001],
                ['host' => '127.0.0.1', 'port' => 7002]
            ],
            'read_preference' => 'secondary',
            'auto_discovery' => true
        ]
    ]
];
```

### Environment Variables
```bash
# Basic configuration
CACHE_ENGINE=auto
CACHE_DEFAULT_STORE=redis
CACHE_RUST_FFI_ENABLED=true
CACHE_AMPHP_PARALLEL_ENABLED=true

# Redis cluster nodes
REDIS_CLUSTER_ENABLED=true
REDIS_CLUSTER_NODES=127.0.0.1:7000:master,127.0.0.1:7001:slave,127.0.0.1:7002:slave

# Or individual node configuration
REDIS_CLUSTER_NODE_0_HOST=127.0.0.1
REDIS_CLUSTER_NODE_0_PORT=7000
REDIS_CLUSTER_NODE_0_ROLE=master
```

## Session Store

```php
use HighPerApp\Cache\Session\SessionStore;

$provider = new ServiceProvider();
$provider->register();

// Get session store
$sessionStore = $provider->get('session.store');

// Use as PHP session handler
$sessionHandler = $provider->get('session.handler');
session_set_save_handler($sessionHandler, true);
session_start();

// Direct session operations
$sessionStore->write('sess_123', 'session_data');
$data = $sessionStore->read('sess_123');
$sessionStore->destroy('sess_123');
```

## HTTP Middleware

```php
use HighPerApp\Cache\Middleware\CacheMiddleware;

$middleware = new CacheMiddleware($cache, $config);

// Configure cache patterns
$config = [
    'cache' => [
        'include_patterns' => ['/api/*', '/static/*'],
        'exclude_patterns' => ['/admin/*'],
        'default_ttl' => 3600,
        'vary_headers' => ['Accept', 'Accept-Encoding']
    ]
];

// Get middleware statistics
$stats = $middleware->getStats();
```

## Advanced Features

### Engine Management
```php
// Auto-select best available engine
$cache = ServiceProvider::createFromEnv();

// Force specific engine
$cache = ServiceProvider::createWithConfig([
    'engine' => 'rust_ffi'  // or 'amphp', 'pure_php'
]);
```

### Cache Statistics
```php
$stats = yield $cache->getStats();
/*
Array
(
    [hits] => 1250
    [misses] => 89
    [hit_ratio] => 0.933
    [memory_usage] => 45231616
    [keys_count] => 342
    [engine] => 'rust_ffi'
    [provider] => 'redis'
)
*/
```

### Bulk Operations
```php
// Get multiple keys
$data = yield $cache->getMultiple(['user:1', 'user:2', 'user:3']);

// Set multiple keys
yield $cache->setMultiple([
    'user:1' => $userData1,
    'user:2' => $userData2
], 3600);

// Delete multiple keys
yield $cache->deleteMultiple(['user:1', 'user:2']);
```

### Cache Tags
```php
// Store with tags
yield $cache->setWithTags('product:123', $product, ['products', 'catalog'], 3600);

// Invalidate by tag
yield $cache->invalidateByTag('products');
```

## Performance Optimization

### Rust FFI Engine
- **Maximum Performance**: Native Rust implementation
- **Automatic Fallback**: Falls back to pure PHP if FFI unavailable
- **Memory Efficient**: Optimized memory usage and garbage collection

### AMPHP Integration
- **Async Operations**: Non-blocking cache operations
- **Parallel Processing**: Concurrent request handling
- **Worker Pools**: Efficient resource management

### Environment Configuration
```bash
# Performance tuning
CACHE_ASYNC_THRESHOLD=1000
CACHE_BATCH_SIZE=100
CACHE_MEMORY_LIMIT=256M
CACHE_AMPHP_WORKERS=auto
CACHE_AMPHP_TASK_LIMIT=1000
```

## Testing

```bash
# Run all tests
composer test

# Run specific test suites
composer test:unit
composer test:integration
composer test:session

# Run with coverage
composer test:coverage
```

## Architecture

This library follows strict interface-driven architecture:

- **No Abstract Classes**: Pure interfaces for maximum flexibility
- **No Final Keywords**: All classes can be extended
- **PSR Standards**: PSR-16 Simple Cache Interface compliance
- **Service Provider Pattern**: Framework-agnostic integration
- **Dependency Injection**: Full IoC container support

## License

MIT