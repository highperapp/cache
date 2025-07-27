<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Contracts;

/**
 * Serializer interface for cache value serialization
 */
interface SerializerInterface
{
    /**
     * Serialize data for storage
     */
    public function serialize(mixed $data): string;
    
    /**
     * Unserialize data from storage
     */
    public function unserialize(string $data): mixed;
    
    /**
     * Check if serializer supports this data type
     */
    public function supports(mixed $data): bool;
    
    /**
     * Get serializer name
     */
    public function getName(): string;
    
    /**
     * Get serializer performance level
     */
    public function getPerformanceLevel(): int;
    
    /**
     * Check if serializer is available
     */
    public function isAvailable(): bool;
}