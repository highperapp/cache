<?php

declare(strict_types=1);

namespace HighPerApp\Cache\FFI;

use FFI;
use HighPerApp\Cache\Contracts\ConfigurationInterface;
use HighPerApp\Cache\Exceptions\EngineException;
use Psr\Log\LoggerInterface;

/**
 * Rust FFI wrapper for high-performance cache operations
 */
class RustCacheFFI
{
    private ?FFI $ffi = null;
    private bool $initialized = false;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    
    public function __construct(
        ConfigurationInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Initialize FFI interface
     */
    public function initialize(): bool
    {
        if ($this->initialized) {
            return true;
        }
        
        if (!extension_loaded('ffi')) {
            $this->logger->warning('FFI extension not loaded');
            return false;
        }
        
        $libraryPath = $this->getLibraryPath();
        
        if (!file_exists($libraryPath)) {
            $this->logger->warning('Rust cache library not found', ['path' => $libraryPath]);
            return false;
        }
        
        try {
            $this->ffi = FFI::cdef($this->getFFIDefinition(), $libraryPath);
            $this->initialized = true;
            
            $version = $this->getVersion();
            $this->logger->info('Rust cache FFI initialized', [
                'version' => $version,
                'library_path' => $libraryPath
            ]);
            
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to initialize Rust cache FFI', [
                'error' => $e->getMessage(),
                'library_path' => $libraryPath
            ]);
            return false;
        }
    }
    
    /**
     * Check if FFI is available and initialized
     */
    public function isAvailable(): bool
    {
        return $this->initialized && $this->ffi !== null;
    }
    
    /**
     * Get library version
     */
    public function getVersion(): string
    {
        if (!$this->isAvailable()) {
            return 'unavailable';
        }
        
        try {
            $ptr = $this->ffi->highper_cache_version();
            $version = FFI::string($ptr);
            $this->ffi->highper_cache_free_string($ptr);
            return $version;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get Rust cache version', ['error' => $e->getMessage()]);
            return 'error';
        }
    }
    
    /**
     * Memory cache operations
     */
    public function memorySet(string $key, string $value, int $ttl = 0): bool
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            return $this->ffi->highper_cache_memory_set($key, $value, $ttl);
        } catch (\Throwable $e) {
            $this->logger->error('Rust memory cache set failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new EngineException('Memory cache set failed: ' . $e->getMessage());
        }
    }
    
    public function memoryGet(string $key): ?string
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            $ptr = $this->ffi->highper_cache_memory_get($key);
            
            if (FFI::isNull($ptr)) {
                return null;
            }
            
            $value = FFI::string($ptr);
            $this->ffi->highper_cache_free_string($ptr);
            
            return $value;
        } catch (\Throwable $e) {
            $this->logger->error('Rust memory cache get failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new EngineException('Memory cache get failed: ' . $e->getMessage());
        }
    }
    
    public function memoryDelete(string $key): bool
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            return $this->ffi->highper_cache_memory_delete($key);
        } catch (\Throwable $e) {
            $this->logger->error('Rust memory cache delete failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new EngineException('Memory cache delete failed: ' . $e->getMessage());
        }
    }
    
    public function memoryClear(): bool
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            return $this->ffi->highper_cache_memory_clear();
        } catch (\Throwable $e) {
            $this->logger->error('Rust memory cache clear failed', ['error' => $e->getMessage()]);
            throw new EngineException('Memory cache clear failed: ' . $e->getMessage());
        }
    }
    
    public function memoryExists(string $key): bool
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            return $this->ffi->highper_cache_memory_exists($key);
        } catch (\Throwable $e) {
            $this->logger->error('Rust memory cache exists failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    public function memoryCleanup(): int
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            return $this->ffi->highper_cache_memory_cleanup();
        } catch (\Throwable $e) {
            $this->logger->error('Rust memory cache cleanup failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }
    
    public function memoryCount(): int
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            return $this->ffi->highper_cache_memory_count();
        } catch (\Throwable $e) {
            $this->logger->error('Rust memory cache count failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }
    
    /**
     * Batch operations for improved performance
     */
    public function memorySetMultiple(array $data, int $ttl = 0): int
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        if (empty($data)) {
            return 0;
        }
        
        try {
            $count = count($data);
            $keys = FFI::new("char*[{$count}]");
            $values = FFI::new("char*[{$count}]");
            $ttls = FFI::new("uint64_t[{$count}]");
            
            $i = 0;
            foreach ($data as $key => $value) {
                $keys[$i] = $key;
                $values[$i] = $value;
                $ttls[$i] = $ttl;
                $i++;
            }
            
            return $this->ffi->highper_cache_memory_set_multiple(
                FFI::addr($keys[0]),
                FFI::addr($values[0]),
                FFI::addr($ttls[0]),
                $count
            );
        } catch (\Throwable $e) {
            $this->logger->error('Rust memory cache set multiple failed', [
                'count' => count($data),
                'error' => $e->getMessage()
            ]);
            throw new EngineException('Memory cache set multiple failed: ' . $e->getMessage());
        }
    }
    
    public function memoryGetMultiple(array $keys): array
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        if (empty($keys)) {
            return [];
        }
        
        try {
            $count = count($keys);
            $keyPtrs = FFI::new("char*[{$count}]");
            
            for ($i = 0; $i < $count; $i++) {
                $keyPtrs[$i] = $keys[$i];
            }
            
            $ptr = $this->ffi->highper_cache_memory_get_multiple(
                FFI::addr($keyPtrs[0]),
                $count
            );
            
            if (FFI::isNull($ptr)) {
                return [];
            }
            
            $json = FFI::string($ptr);
            $this->ffi->highper_cache_free_string($ptr);
            
            $result = json_decode($json, true);
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            $this->logger->error('Rust memory cache get multiple failed', [
                'count' => count($keys),
                'error' => $e->getMessage()
            ]);
            throw new EngineException('Memory cache get multiple failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Redis operations
     */
    public function redisPing(string $host, int $port): bool
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            return $this->ffi->highper_cache_redis_ping($host, $port);
        } catch (\Throwable $e) {
            $this->logger->error('Rust Redis ping failed', [
                'host' => $host,
                'port' => $port,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Compression utilities
     */
    public function compressLZ4(string $data): ?string
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            $compressedSize = FFI::new('size_t');
            $ptr = $this->ffi->highper_cache_compress_lz4($data, FFI::addr($compressedSize));
            
            if (FFI::isNull($ptr)) {
                return null;
            }
            
            $compressed = FFI::string($ptr);
            $this->ffi->highper_cache_free_string($ptr);
            
            return $compressed;
        } catch (\Throwable $e) {
            $this->logger->error('Rust LZ4 compression failed', [
                'data_size' => strlen($data),
                'error' => $e->getMessage()
            ]);
            throw new EngineException('LZ4 compression failed: ' . $e->getMessage());
        }
    }
    
    public function decompressLZ4(string $data): ?string
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            $ptr = $this->ffi->highper_cache_decompress_lz4($data);
            
            if (FFI::isNull($ptr)) {
                return null;
            }
            
            $decompressed = FFI::string($ptr);
            $this->ffi->highper_cache_free_string($ptr);
            
            return $decompressed;
        } catch (\Throwable $e) {
            $this->logger->error('Rust LZ4 decompression failed', [
                'data_size' => strlen($data),
                'error' => $e->getMessage()
            ]);
            throw new EngineException('LZ4 decompression failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Performance benchmarking
     */
    public function benchmarkMemory(int $operations = 10000): float
    {
        if (!$this->isAvailable()) {
            throw new EngineException('Rust FFI not available');
        }
        
        try {
            return $this->ffi->highper_cache_benchmark_memory($operations);
        } catch (\Throwable $e) {
            $this->logger->error('Rust benchmark failed', [
                'operations' => $operations,
                'error' => $e->getMessage()
            ]);
            throw new EngineException('Benchmark failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get library path based on configuration and platform
     */
    private function getLibraryPath(): string
    {
        $configPath = $this->config->get('ffi.library_path');
        
        if ($configPath && file_exists($configPath)) {
            return $configPath;
        }
        
        // Auto-detect library path
        $baseDir = dirname(__DIR__);
        $libraryName = 'libhighper_cache';
        
        $extensions = match (PHP_OS_FAMILY) {
            'Linux' => ['so'],
            'Darwin' => ['dylib'],
            'Windows' => ['dll'],
            default => ['so', 'dylib', 'dll'],
        };
        
        foreach ($extensions as $ext) {
            $path = "{$baseDir}/FFI/{$libraryName}.{$ext}";
            if (file_exists($path)) {
                return $path;
            }
        }
        
        return "{$baseDir}/FFI/{$libraryName}.so";
    }
    
    /**
     * Get FFI C header definitions
     */
    private function getFFIDefinition(): string
    {
        return '
            // Memory management
            void highper_cache_free_string(char* ptr);
            char* highper_cache_version(void);
            
            // Memory cache operations
            bool highper_cache_memory_set(const char* key, const char* value, uint64_t ttl);
            char* highper_cache_memory_get(const char* key);
            bool highper_cache_memory_delete(const char* key);
            bool highper_cache_memory_clear(void);
            bool highper_cache_memory_exists(const char* key);
            uint64_t highper_cache_memory_cleanup(void);
            uint64_t highper_cache_memory_count(void);
            
            // Batch operations
            uint64_t highper_cache_memory_set_multiple(
                const char** keys,
                const char** values, 
                const uint64_t* ttls,
                size_t count
            );
            char* highper_cache_memory_get_multiple(const char** keys, size_t count);
            
            // Redis operations
            bool highper_cache_redis_ping(const char* host, uint16_t port);
            
            // Compression
            char* highper_cache_compress_lz4(const char* data, size_t* compressed_size);
            char* highper_cache_decompress_lz4(const char* data);
            
            // Benchmarking
            double highper_cache_benchmark_memory(uint64_t operations);
        ';
    }
}