<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\Cache;

use HighPerApp\HighPer\Foundation\ServiceProvider;

/**
 * Cache Service Provider
 * 
 * Registers high-performance caching services with the HighPer framework.
 */
class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register the main cache manager
        $this->singleton(CacheManager::class, function() {
            $config = $this->getCacheConfig();
            return new CacheManager($config);
        });

        // Register aliases for easier access
        $this->alias('cache', CacheManager::class);
        $this->alias('cache.manager', CacheManager::class);
    }

    public function boot(): void
    {
        $this->log('info', 'Cache service provider booted successfully');
    }

    private function getCacheConfig(): array
    {
        return [
            'default' => $this->env('CACHE_DRIVER', 'array'),
            'ttl' => (int) $this->env('CACHE_TTL', 3600),
            'prefix' => $this->env('CACHE_PREFIX', 'highper:'),
            'stores' => [
                'array' => [
                    'driver' => 'array',
                    'max_items' => 1000
                ],
                'redis' => [
                    'driver' => 'redis',
                    'host' => $this->env('REDIS_HOST', 'localhost'),
                    'port' => (int) $this->env('REDIS_PORT', 6379),
                    'password' => $this->env('REDIS_PASSWORD', null),
                    'database' => (int) $this->env('REDIS_CACHE_DB', 1)
                ]
            ]
        ];
    }
}