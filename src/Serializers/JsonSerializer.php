<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Serializers;

use HighPerApp\Cache\Contracts\SerializerInterface;
use HighPerApp\Cache\Exceptions\CacheException;

/**
 * JSON serializer for cache values
 */
class JsonSerializer implements SerializerInterface
{
    public function serialize(mixed $data): string
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        
        if ($encoded === false) {
            throw new CacheException('JSON serialization failed');
        }
        
        return $encoded;
    }
    
    public function unserialize(string $data): mixed
    {
        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CacheException('JSON deserialization failed: ' . $e->getMessage());
        }
    }
    
    public function supports(mixed $data): bool
    {
        return is_array($data) || 
               is_object($data) || 
               is_string($data) || 
               is_numeric($data) || 
               is_bool($data) || 
               is_null($data);
    }
    
    public function getName(): string
    {
        return 'json';
    }
    
    public function getPerformanceLevel(): int
    {
        return 2; // Good performance
    }
    
    public function isAvailable(): bool
    {
        return function_exists('json_encode') && function_exists('json_decode');
    }
}