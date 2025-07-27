<?php

declare(strict_types=1);

namespace HighPerApp\Cache\Serializers;

use HighPerApp\Cache\Contracts\SerializerInterface;
use HighPerApp\Cache\Exceptions\CacheException;

/**
 * Serializer manager with automatic format selection
 */
class SerializerManager
{
    private array $serializers = [];
    private string $defaultSerializer;
    
    public function __construct(string $defaultSerializer = 'php')
    {
        $this->defaultSerializer = $defaultSerializer;
        $this->initializeSerializers();
    }
    
    /**
     * Get the best serializer for the given data
     */
    public function getBestSerializer(mixed $data): SerializerInterface
    {
        // Try to use the default serializer first
        if (isset($this->serializers[$this->defaultSerializer])) {
            $serializer = $this->serializers[$this->defaultSerializer];
            if ($serializer->supports($data)) {
                return $serializer;
            }
        }
        
        // Find the best available serializer
        $availableSerializers = array_filter(
            $this->serializers,
            fn(SerializerInterface $s) => $s->isAvailable() && $s->supports($data)
        );
        
        if (empty($availableSerializers)) {
            throw new CacheException('No suitable serializer found for data type: ' . gettype($data));
        }
        
        // Sort by performance level (highest first)
        uasort($availableSerializers, fn($a, $b) => $b->getPerformanceLevel() <=> $a->getPerformanceLevel());
        
        return reset($availableSerializers);
    }
    
    /**
     * Get serializer by name
     */
    public function getSerializer(string $name): SerializerInterface
    {
        if (!isset($this->serializers[$name])) {
            throw new CacheException("Serializer '{$name}' not found");
        }
        
        return $this->serializers[$name];
    }
    
    /**
     * Add a custom serializer
     */
    public function addSerializer(string $name, SerializerInterface $serializer): void
    {
        $this->serializers[$name] = $serializer;
    }
    
    /**
     * Get all available serializers
     */
    public function getAvailableSerializers(): array
    {
        return array_filter(
            $this->serializers,
            fn(SerializerInterface $s) => $s->isAvailable()
        );
    }
    
    /**
     * Serialize data using the best available serializer
     */
    public function serialize(mixed $data): array
    {
        $serializer = $this->getBestSerializer($data);
        
        return [
            'data' => $serializer->serialize($data),
            'serializer' => $serializer->getName(),
        ];
    }
    
    /**
     * Unserialize data using the specified serializer
     */
    public function unserialize(string $data, string $serializerName): mixed
    {
        $serializer = $this->getSerializer($serializerName);
        return $serializer->unserialize($data);
    }
    
    /**
     * Initialize built-in serializers
     */
    private function initializeSerializers(): void
    {
        $this->serializers = [
            'php' => new PhpSerializer(),
            'json' => new JsonSerializer(),
        ];
        
        // Add msgpack serializer if available
        if (extension_loaded('msgpack')) {
            $this->serializers['msgpack'] = new class implements SerializerInterface {
                public function serialize(mixed $data): string
                {
                    return msgpack_pack($data);
                }
                
                public function unserialize(string $data): mixed
                {
                    return msgpack_unpack($data);
                }
                
                public function supports(mixed $data): bool
                {
                    return true;
                }
                
                public function getName(): string
                {
                    return 'msgpack';
                }
                
                public function getPerformanceLevel(): int
                {
                    return 3;
                }
                
                public function isAvailable(): bool
                {
                    return extension_loaded('msgpack');
                }
            };
        }
        
        // Add igbinary serializer if available
        if (extension_loaded('igbinary')) {
            $this->serializers['igbinary'] = new class implements SerializerInterface {
                public function serialize(mixed $data): string
                {
                    return igbinary_serialize($data);
                }
                
                public function unserialize(string $data): mixed
                {
                    return igbinary_unserialize($data);
                }
                
                public function supports(mixed $data): bool
                {
                    return true;
                }
                
                public function getName(): string
                {
                    return 'igbinary';
                }
                
                public function getPerformanceLevel(): int
                {
                    return 3;
                }
                
                public function isAvailable(): bool
                {
                    return extension_loaded('igbinary');
                }
            };
        }
    }
}