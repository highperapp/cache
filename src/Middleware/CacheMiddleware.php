<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Middleware;

use HighPerApp\Cache\Contracts\CacheInterface;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP cache middleware for request/response caching
 */
class CacheMiddleware implements MiddlewareInterface
{
    private CacheInterface $cache;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private array $stats = [
        'requests' => 0,
        'hits' => 0,
        'misses' => 0,
        'stores' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];
    
    public function __construct(
        CacheInterface $cache,
        ConfigurationInterface $config,
        LoggerInterface $logger = null
    ) {
        $this->cache = $cache;
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->stats['requests']++;
        
        // Check if request should be cached
        if (!$this->shouldCacheRequest($request)) {
            $this->stats['skipped']++;
            return $handler->handle($request);
        }
        
        $cacheKey = $this->generateCacheKey($request);
        
        // Try to get cached response
        try {
            $cachedResponse = $this->cache->get($cacheKey);
            
            if ($cachedResponse !== null) {
                $this->stats['hits']++;
                $this->logger->debug('Cache hit for request', [
                    'method' => $request->getMethod(),
                    'uri' => (string)$request->getUri(),
                    'cache_key' => $cacheKey,
                ]);
                
                return $this->createResponseFromCache($cachedResponse);
            }
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->logger->error('Cache retrieval failed', [
                'cache_key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Cache miss - process request
        $this->stats['misses']++;
        $response = $handler->handle($request);
        
        // Cache the response if appropriate
        if ($this->shouldCacheResponse($request, $response)) {
            try {
                $ttl = $this->getTtl($request, $response);
                $cacheData = $this->serializeResponse($response);
                
                $this->cache->set($cacheKey, $cacheData, $ttl);
                $this->stats['stores']++;
                
                $this->logger->debug('Response cached', [
                    'method' => $request->getMethod(),
                    'uri' => (string)$request->getUri(),
                    'cache_key' => $cacheKey,
                    'ttl' => $ttl,
                ]);
            } catch (\Throwable $e) {
                $this->stats['errors']++;
                $this->logger->error('Cache storage failed', [
                    'cache_key' => $cacheKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $response;
    }
    
    /**
     * Check if request should be cached
     */
    private function shouldCacheRequest(ServerRequestInterface $request): bool
    {
        // Only cache GET and HEAD requests
        $method = $request->getMethod();
        if (!in_array($method, ['GET', 'HEAD'])) {
            return false;
        }
        
        // Check for cache control headers
        $cacheControl = $request->getHeaderLine('Cache-Control');
        if (str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store')) {
            return false;
        }
        
        // Check for authorization headers
        if ($request->hasHeader('Authorization')) {
            return $this->config->get('cache.allow_authenticated', false);
        }
        
        // Check URI patterns
        $uri = (string)$request->getUri();
        $excludePatterns = $this->config->get('cache.exclude_patterns', []);
        
        foreach ($excludePatterns as $pattern) {
            if (fnmatch($pattern, $uri)) {
                return false;
            }
        }
        
        // Check include patterns
        $includePatterns = $this->config->get('cache.include_patterns', []);
        if (!empty($includePatterns)) {
            foreach ($includePatterns as $pattern) {
                if (fnmatch($pattern, $uri)) {
                    return true;
                }
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if response should be cached
     */
    private function shouldCacheResponse(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        // Only cache successful responses
        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }
        
        // Check for cache control headers
        $cacheControl = $response->getHeaderLine('Cache-Control');
        if (str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store')) {
            return false;
        }
        
        // Check for private cache directive
        if (str_contains($cacheControl, 'private')) {
            return false;
        }
        
        // Check content type
        $contentType = $response->getHeaderLine('Content-Type');
        $allowedTypes = $this->config->get('cache.allowed_content_types', [
            'application/json',
            'application/xml',
            'text/html',
            'text/plain',
            'text/css',
            'text/javascript',
            'application/javascript',
        ]);
        
        if (!empty($allowedTypes)) {
            $typeMatches = false;
            foreach ($allowedTypes as $allowedType) {
                if (str_starts_with($contentType, $allowedType)) {
                    $typeMatches = true;
                    break;
                }
            }
            
            if (!$typeMatches) {
                return false;
            }
        }
        
        // Check response size
        $maxSize = $this->config->get('cache.max_response_size', 1048576); // 1MB
        $bodySize = $response->getBody()->getSize();
        
        if ($bodySize !== null && $bodySize > $maxSize) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Generate cache key for request
     */
    private function generateCacheKey(ServerRequestInterface $request): string
    {
        $method = $request->getMethod();
        $uri = (string)$request->getUri();
        $query = $request->getUri()->getQuery();
        
        // Include relevant headers in cache key
        $headers = [];
        $varyHeaders = $this->config->get('cache.vary_headers', ['Accept', 'Accept-Encoding']);
        
        foreach ($varyHeaders as $header) {
            if ($request->hasHeader($header)) {
                $headers[$header] = $request->getHeaderLine($header);
            }
        }
        
        // Create cache key
        $keyData = [
            'method' => $method,
            'uri' => $uri,
            'query' => $query,
            'headers' => $headers,
        ];
        
        $prefix = $this->config->get('cache.key_prefix', 'http:');
        $key = $prefix . md5(json_encode($keyData));
        
        return $key;
    }
    
    /**
     * Get TTL for cached response
     */
    private function getTtl(ServerRequestInterface $request, ResponseInterface $response): int
    {
        // Check Cache-Control max-age
        $cacheControl = $response->getHeaderLine('Cache-Control');
        if (preg_match('/max-age=(\d+)/', $cacheControl, $matches)) {
            return (int)$matches[1];
        }
        
        // Check Expires header
        $expires = $response->getHeaderLine('Expires');
        if ($expires) {
            $expiresTime = strtotime($expires);
            if ($expiresTime !== false) {
                return max(0, $expiresTime - time());
            }
        }
        
        // Use default TTL
        return $this->config->get('cache.default_ttl', 3600);
    }
    
    /**
     * Serialize response for caching
     */
    private function serializeResponse(ResponseInterface $response): array
    {
        $body = $response->getBody();
        $body->rewind();
        
        return [
            'status' => $response->getStatusCode(),
            'reason' => $response->getReasonPhrase(),
            'headers' => $response->getHeaders(),
            'body' => $body->getContents(),
            'cached_at' => time(),
        ];
    }
    
    /**
     * Create response from cached data
     */
    private function createResponseFromCache(array $cachedData): ResponseInterface
    {
        $response = new \GuzzleHttp\Psr7\Response(
            $cachedData['status'],
            $cachedData['headers'],
            $cachedData['body'],
            '1.1',
            $cachedData['reason']
        );
        
        // Add cache headers
        $response = $response
            ->withHeader('X-Cache', 'HIT')
            ->withHeader('X-Cache-Date', date('c', $cachedData['cached_at']))
            ->withHeader('Age', (string)(time() - $cachedData['cached_at']));
        
        return $response;
    }
    
    /**
     * Get middleware statistics
     */
    public function getStats(): array
    {
        $hitRate = $this->stats['requests'] > 0 
            ? round(($this->stats['hits'] / $this->stats['requests']) * 100, 2)
            : 0;
        
        return array_merge($this->stats, [
            'hit_rate' => $hitRate,
            'middleware' => 'CacheMiddleware',
            'version' => '1.0.0',
        ]);
    }
    
    /**
     * Clear cache for specific patterns
     */
    public function clearCache(array $patterns = []): int
    {
        $cleared = 0;
        
        if (empty($patterns)) {
            // Clear all HTTP cache
            $prefix = $this->config->get('cache.key_prefix', 'http:');
            $patterns = [$prefix . '*'];
        }
        
        foreach ($patterns as $pattern) {
            try {
                // This would need to be implemented based on cache backend
                // For now, we'll just flush all cache
                $this->cache->flush();
                $cleared++;
            } catch (\Throwable $e) {
                $this->logger->error('Cache clear failed', [
                    'pattern' => $pattern,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $cleared;
    }
    
    /**
     * Warm up cache for specific URLs
     */
    public function warmUp(array $urls): int
    {
        $warmed = 0;
        
        foreach ($urls as $url) {
            try {
                // This would make HTTP requests to warm up cache
                // Implementation depends on HTTP client
                $warmed++;
            } catch (\Throwable $e) {
                $this->logger->error('Cache warm up failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $warmed;
    }
}