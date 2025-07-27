<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Serializers;

use HighPerApp\Cache\Contracts\SerializerInterface;
use HighPerApp\Cache\Exceptions\CacheException;

/**
 * PHP native serializer for cache values
 */
class PhpSerializer implements SerializerInterface
{
    public function serialize(mixed $data): string
    {
        $serialized = serialize($data);
        
        if ($serialized === false) {
            throw new CacheException('PHP serialization failed');
        }
        
        return $serialized;
    }
    
    public function unserialize(string $data): mixed
    {
        set_error_handler(function ($severity, $message) {
            throw new CacheException("PHP deserialization failed: {$message}");
        });
        
        try {
            $result = unserialize($data);
            
            if ($result === false && $data !== serialize(false)) {
                throw new CacheException('PHP deserialization failed');
            }
            
            return $result;
        } finally {
            restore_error_handler();
        }
    }
    
    public function supports(mixed $data): bool
    {
        return true; // PHP serializer supports all data types
    }
    
    public function getName(): string
    {
        return 'php';
    }
    
    public function getPerformanceLevel(): int
    {
        return 3; // Excellent performance for PHP objects
    }
    
    public function isAvailable(): bool
    {
        return function_exists('serialize') && function_exists('unserialize');
    }
}