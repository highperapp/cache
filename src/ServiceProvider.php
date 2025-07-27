<?php

declare(strict_types=1);

namespace HighPerApp\Cache;

use HighPerApp\Cache\Contracts\CacheInterface;
use HighPerApp\Cache\Contracts\EngineManagerInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Contracts\ConnectionPoolInterface;
use HighPerApp\Cache\Configuration\Configuration;
use HighPerApp\Cache\CacheManager;
use HighPerApp\Cache\Engines\EngineManager;
use HighPerApp\Cache\Engines\RustFFIEngine;
use HighPerApp\Cache\Engines\AMPHPEngine;
use HighPerApp\Cache\Engines\PurePHPEngine;
use HighPerApp\Cache\FFI\RustCacheFFI;
use HighPerApp\Cache\ConnectionPool\RedisConnectionPool;
use HighPerApp\Cache\Serializers\SerializerManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service provider for auto-discovery and framework integration
 */
class ServiceProvider
{
    private ContainerInterface $container;
    private array $bindings = [];
    private bool $registered = false;
    
    public function __construct(ContainerInterface $container = null)
    {
        $this->container = $container ?? new SimpleContainer();
    }
    
    /**
     * Register all cache services
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        
        $this->registerConfiguration();
        $this->registerLogger();
        $this->registerSerializers();
        $this->registerConnectionPools();
        $this->registerEngines();
        $this->registerEngineManager();
        $this->registerCacheManager();
        $this->registerSessionStore();
        $this->registerProviders();
        
        $this->registered = true;
    }
    
    /**
     * Boot the cache service (warm up connections, etc.)
     */
    public function boot(): void
    {
        if (!$this->registered) {
            $this->register();
        }
        
        // Warm up connection pools
        try {
            $connectionPool = $this->container->get(ConnectionPoolInterface::class);
            if (method_exists($connectionPool, 'warmUp')) {
                $connectionPool->warmUp();
            }
        } catch (\Throwable $e) {
            // Ignore warmup errors during boot
        }
        
        // Initialize engine manager
        try {
            $engineManager = $this->container->get(EngineManagerInterface::class);
            if (method_exists($engineManager, 'refreshAvailability')) {
                $engineManager->refreshAvailability();
            }
        } catch (\Throwable $e) {
            // Ignore initialization errors during boot
        }
    }
    
    /**
     * Get the cache manager instance
     */
    public function getCacheManager(): CacheInterface
    {
        if (!$this->registered) {
            $this->register();
        }
        
        return $this->container->get(CacheInterface::class);
    }
    
    /**
     * Get service by interface/class name
     */
    public function get(string $id): mixed
    {
        if (!$this->registered) {
            $this->register();
        }
        
        return $this->container->get($id);
    }
    
    /**
     * Check if service is registered
     */
    public function has(string $id): bool
    {
        return $this->container->has($id);
    }
    
    /**
     * Get all registered bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
    
    /**
     * Create a cache manager with custom configuration
     */
    public static function createWithConfig(array $config): CacheInterface
    {
        $provider = new self();
        $provider->container->set(ConfigurationInterface::class, new Configuration($config));
        $provider->register();
        
        return $provider->getCacheManager();
    }
    
    /**
     * Create a cache manager with environment-based configuration
     */
    public static function createFromEnv(): CacheInterface
    {
        $provider = new self();
        $provider->register();
        
        return $provider->getCacheManager();
    }
    
    private function registerConfiguration(): void
    {
        if (!$this->container->has(ConfigurationInterface::class)) {
            $this->container->set(ConfigurationInterface::class, function() {
                return Configuration::fromEnvironment();
            });
        }
        
        $this->bindings[ConfigurationInterface::class] = 'Environment-based configuration';
    }
    
    private function registerLogger(): void
    {
        if (!$this->container->has(LoggerInterface::class)) {
            $this->container->set(LoggerInterface::class, function() {
                // Try to use application logger if available
                if (class_exists('\\Monolog\\Logger')) {
                    $logger = new \Monolog\Logger('highper_cache');
                    $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stderr'));
                    return $logger;
                }
                
                return new NullLogger();
            });
        }
        
        $this->bindings[LoggerInterface::class] = 'Auto-detected logger';
    }
    
    private function registerSerializers(): void
    {
        $this->container->set(SerializerManager::class, function() {
            $config = $this->container->get(ConfigurationInterface::class);
            $defaultSerializer = $config->get('serializer_default', 'php');
            
            return new SerializerManager($defaultSerializer);
        });
        
        $this->bindings[SerializerManager::class] = 'Serializer manager with auto-detection';
    }
    
    private function registerConnectionPools(): void
    {
        $this->container->set(ConnectionPoolInterface::class, function() {
            $config = $this->container->get(ConfigurationInterface::class);
            $logger = $this->container->get(LoggerInterface::class);
            
            // For now, only Redis connection pool is implemented
            return new RedisConnectionPool($config, $logger);
        });
        
        $this->bindings[ConnectionPoolInterface::class] = 'Redis connection pool';
    }
    
    private function registerEngines(): void
    {
        // Rust FFI Engine
        $this->container->set(RustFFIEngine::class, function() {
            $config = $this->container->get(ConfigurationInterface::class);
            $logger = $this->container->get(LoggerInterface::class);
            $serializer = $this->container->get(SerializerManager::class);
            
            $ffi = new RustCacheFFI();
            
            return new RustFFIEngine($ffi, $serializer, $config, $logger);
        });
        
        // AMPHP Engine
        $this->container->set(AMPHPEngine::class, function() {
            $config = $this->container->get(ConfigurationInterface::class);
            $logger = $this->container->get(LoggerInterface::class);
            $serializer = $this->container->get(SerializerManager::class);
            $connectionPool = $this->container->get(ConnectionPoolInterface::class);
            
            return new AMPHPEngine($connectionPool, $serializer, $config, $logger);
        });
        
        // Pure PHP Engine
        $this->container->set(PurePHPEngine::class, function() {
            $config = $this->container->get(ConfigurationInterface::class);
            $logger = $this->container->get(LoggerInterface::class);
            $serializer = $this->container->get(SerializerManager::class);
            
            return new PurePHPEngine($config, $logger, $serializer);
        });
        
        $this->bindings['engines'] = 'Rust FFI, AMPHP, and Pure PHP engines';
    }
    
    private function registerEngineManager(): void
    {
        $this->container->set(EngineManagerInterface::class, function() {
            $config = $this->container->get(ConfigurationInterface::class);
            $logger = $this->container->get(LoggerInterface::class);
            
            $manager = new EngineManager($config, $logger);
            
            // Register all engines
            $manager->registerEngine('rust_ffi', $this->container->get(RustFFIEngine::class));
            $manager->registerEngine('amphp', $this->container->get(AMPHPEngine::class));
            $manager->registerEngine('pure_php', $this->container->get(PurePHPEngine::class));
            
            return $manager;
        });
        
        $this->bindings[EngineManagerInterface::class] = 'Engine manager with all engines';
    }
    
    private function registerCacheManager(): void
    {
        $this->container->set(CacheInterface::class, function() {
            $engineManager = $this->container->get(EngineManagerInterface::class);
            $config = $this->container->get(ConfigurationInterface::class);
            $logger = $this->container->get(LoggerInterface::class);
            
            return new CacheManager($engineManager, $config, $logger);
        });
        
        $this->container->set(CacheManager::class, function() {
            return $this->container->get(CacheInterface::class);
        });
        
        $this->bindings[CacheInterface::class] = 'Main cache manager';
        $this->bindings[CacheManager::class] = 'Alias for cache manager';
    }
    
    private function registerSessionStore(): void
    {
        $this->container->set('session.store', function() {
            $cache = $this->container->get(CacheInterface::class);
            $config = $this->container->get(ConfigurationInterface::class);
            $logger = $this->container->get(LoggerInterface::class);
            
            return new \HighPerApp\Cache\Session\SessionStore($cache, $config, $logger);
        });
        
        $this->container->set('session.handler', function() {
            $cache = $this->container->get(CacheInterface::class);
            $config = $this->container->get(ConfigurationInterface::class);
            $logger = $this->container->get(LoggerInterface::class);
            
            return new \HighPerApp\Cache\Session\SessionHandler($cache, $config, $logger);
        });
        
        $this->bindings['session.store'] = 'Session store with cache backend';
        $this->bindings['session.handler'] = 'Session handler with cache backend';
    }
    
    private function registerProviders(): void
    {
        // Register cache providers
        $this->container->set('provider.valkey', function() {
            return new \HighPerApp\Cache\Providers\ValkeyProvider();
        });
        
        $this->container->set('provider.dragonfly', function() {
            return new \HighPerApp\Cache\Providers\DragonflyProvider();
        });
        
        $this->container->set('provider.aws_elasticache', function() {
            return new \HighPerApp\Cache\Providers\AWSElastiCacheProvider();
        });
        
        $this->bindings['provider.valkey'] = 'Valkey cache provider';
        $this->bindings['provider.dragonfly'] = 'Dragonfly cache provider';
        $this->bindings['provider.aws_elasticache'] = 'AWS ElastiCache provider';
    }
}

/**
 * Simple container implementation for when no DI container is available
 */
class SimpleContainer implements ContainerInterface
{
    private array $services = [];
    private array $instances = [];
    
    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new \InvalidArgumentException("Service '{$id}' not found");
        }
        
        // Return cached instance if available
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        
        $service = $this->services[$id];
        
        if (is_callable($service)) {
            $instance = $service();
        } else {
            $instance = $service;
        }
        
        $this->instances[$id] = $instance;
        
        return $instance;
    }
    
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
    
    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
        
        // Clear cached instance
        unset($this->instances[$id]);
    }
    
    public function singleton(string $id, callable $factory): void
    {
        $this->set($id, $factory);
    }
    
    public function instance(string $id, mixed $instance): void
    {
        $this->services[$id] = $instance;
        $this->instances[$id] = $instance;
    }
}